<?php
/**
 * API para exportar familias a Excel
 * Genera un archivo CSV compatible con Excel
 */

session_start();
require '../includes/conexion.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

try {
    // Obtener filtros de la URL (los mismos que se usan en la página)
    $search = $_GET['search'] ?? '';
    $estado_filter = $_GET['estado'] ?? 'activa';
    $barrio_filter = $_GET['barrio'] ?? '';
    
    // Construir consulta con filtros
    $where_conditions = ["estado = ?"];
    $params = [$estado_filter];
    
    if (!empty($search)) {
        $where_conditions[] = "(nombre_jefe LIKE ? OR apellido_jefe LIKE ? OR dni_jefe LIKE ? OR direccion LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    if (!empty($barrio_filter)) {
        $where_conditions[] = "barrio LIKE ?";
        $params[] = "%$barrio_filter%";
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Obtener datos de familias con estadísticas
    $stmt = $pdo->prepare("
        SELECT 
            f.*,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones a WHERE a.familia_id = f.id), 0
            ) as total_asignaciones,
            COALESCE(
                (SELECT MAX(a.fecha_asignacion) FROM asignaciones a WHERE a.familia_id = f.id), NULL
            ) as ultima_asignacion,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones a WHERE a.familia_id = f.id AND a.estado = 'pendiente'), 0
            ) as asignaciones_pendientes,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones a WHERE a.familia_id = f.id AND a.estado = 'entregada'), 0
            ) as asignaciones_entregadas
        FROM familias f 
        $where_clause
        ORDER BY f.nombre_jefe, f.apellido_jefe
    ");
    
    $stmt->execute($params);
    $familias = $stmt->fetchAll();
    
    // Configurar headers para descarga de CSV
    $filename = 'familias_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');
    
    // Crear output stream
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8 (para que Excel lo reconozca correctamente)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers del CSV
    $headers = [
        'ID',
        'Nombre del Jefe',
        'Apellido del Jefe', 
        'DNI',
        'Teléfono',
        'Dirección',
        'Barrio',
        'Cantidad de Integrantes',
        'Estado',
        'Total Asignaciones',
        'Asignaciones Pendientes',
        'Asignaciones Entregadas',
        'Última Asignación',
        'Fecha de Registro'
    ];
    
    fputcsv($output, $headers, ';'); // Usar ; como separador para mejor compatibilidad con Excel
    
    // Agregar datos
    foreach ($familias as $familia) {
        $row = [
            $familia['id'],
            $familia['nombre_jefe'],
            $familia['apellido_jefe'],
            $familia['dni_jefe'],
            $familia['telefono'] ?: 'Sin teléfono',
            $familia['direccion'] ?: 'Sin dirección',
            $familia['barrio'] ?: 'Sin barrio',
            $familia['cantidad_integrantes'],
            ucfirst($familia['estado']),
            $familia['total_asignaciones'],
            $familia['asignaciones_pendientes'],
            $familia['asignaciones_entregadas'],
            $familia['ultima_asignacion'] ? formatearFecha($familia['ultima_asignacion']) : 'Sin asignaciones',
            formatearFechaHora($familia['fecha_registro'])
        ];
        
        fputcsv($output, $row, ';');
    }
    
    // Agregar resumen al final
    fputcsv($output, [], ';'); // Línea vacía
    fputcsv($output, ['RESUMEN'], ';');
    fputcsv($output, ['Total de familias exportadas:', count($familias)], ';');
    fputcsv($output, ['Fecha de exportación:', date('d/m/Y H:i:s')], ';');
    fputcsv($output, ['Usuario:', $_SESSION['nombre'] . ' ' . $_SESSION['apellido']], ';');
    
    // Registrar la exportación en el log si la función existe
    if (function_exists('registrarLog')) {
        registrarLog($pdo, 'familias', 0, 'exportar', 
            'Exportación de ' . count($familias) . ' familias a Excel', $_SESSION['user_id']);
    }
    
    fclose($output);
    exit;
    
} catch (PDOException $e) {
    error_log("Error en exportación de familias: " . $e->getMessage());
    
    // En caso de error, mostrar página de error
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error en Exportación</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Error en Exportación</h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Ha ocurrido un error al generar el archivo de exportación.</p>
                            <p class="text-muted">Por favor, intente nuevamente o contacte al administrador del sistema.</p>
                            <div class="d-flex gap-2">
                                <a href="../familias.php" class="btn btn-primary">
                                    <i class="fas fa-arrow-left me-2"></i>Volver a Familias
                                </a>
                                <button onclick="window.close()" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>Cerrar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Función auxiliar para formato de fecha (por si no está disponible)
if (!function_exists('formatearFecha')) {
    function formatearFecha($fecha) {
        if (!$fecha || $fecha === '0000-00-00' || $fecha === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        return date('d/m/Y', strtotime($fecha));
    }
}

if (!function_exists('formatearFechaHora')) {
    function formatearFechaHora($fecha) {
        if (!$fecha || $fecha === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        return date('d/m/Y H:i', strtotime($fecha));
    }
}
?>