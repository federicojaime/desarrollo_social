<?php

/**
 * API para exportar personas a Excel
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
    $familia_filter = $_GET['familia'] ?? '';

    // Construir consulta con filtros
    $where_conditions = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where_conditions[] = "(p.nombre LIKE ? OR p.apellido LIKE ? OR p.cedula LIKE ? OR p.telefono LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }

    if (!empty($familia_filter)) {
        $where_conditions[] = "p.id_familia = ?";
        $params[] = $familia_filter;
    }

    $where_clause = "WHERE " . implode(" AND ", $where_conditions);

    // Obtener datos de personas con información familiar
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.nombre,
            p.apellido,
            p.cedula,
            p.telefono,
            p.direccion,
            p.fecha_registro,
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            f.barrio,
            f.telefono as telefono_familia,
            f.direccion as direccion_familia,
            f.cantidad_integrantes,
            f.estado as estado_familia
        FROM personas p
        LEFT JOIN familias f ON p.id_familia = f.id
        $where_clause
        ORDER BY p.nombre, p.apellido
    ");

    $stmt->execute($params);
    $personas = $stmt->fetchAll();

    // Configurar headers para descarga de CSV
    $filename = 'personas_' . date('Y-m-d_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');

    // Crear output stream
    $output = fopen('php://output', 'w');

    // BOM para UTF-8 (para que Excel lo reconozca correctamente)
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Headers del CSV
    $headers = [
        'ID',
        'Nombre',
        'Apellido',
        'Cédula/DNI',
        'Teléfono Personal',
        'Dirección Personal',
        'Fecha de Registro',
        'Tiene Familia Asignada',
        'Jefe de Familia',
        'DNI Jefe de Familia',
        'Teléfono Familiar',
        'Dirección Familiar',
        'Barrio',
        'Total Integrantes Familia',
        'Estado Familia'
    ];

    fputcsv($output, $headers, ';'); // Usar ; como separador para mejor compatibilidad con Excel

    // Agregar datos
    foreach ($personas as $persona) {
        $row = [
            $persona['id'],
            $persona['nombre'],
            $persona['apellido'] ?: 'Sin apellido',
            $persona['cedula'],
            $persona['telefono'] ?: 'Sin teléfono',
            $persona['direccion'] ?: 'Sin dirección',
            formatearFechaHora($persona['fecha_registro']),
            $persona['nombre_jefe'] ? 'SÍ' : 'NO',
            $persona['nombre_jefe'] ? $persona['nombre_jefe'] . ' ' . $persona['apellido_jefe'] : 'N/A',
            $persona['dni_jefe'] ?: 'N/A',
            $persona['telefono_familia'] ?: 'N/A',
            $persona['direccion_familia'] ?: 'N/A',
            $persona['barrio'] ?: 'N/A',
            $persona['cantidad_integrantes'] ?: 'N/A',
            $persona['estado_familia'] ? ucfirst($persona['estado_familia']) : 'N/A'
        ];

        fputcsv($output, $row, ';');
    }

    // Agregar estadísticas al final
    fputcsv($output, [], ';'); // Línea vacía
    fputcsv($output, ['ESTADÍSTICAS DEL REPORTE'], ';');
    fputcsv($output, ['Total de personas exportadas:', count($personas)], ';');

    // Calcular estadísticas
    $con_familia = 0;
    $sin_familia = 0;
    $con_telefono = 0;
    $con_direccion = 0;

    foreach ($personas as $persona) {
        if ($persona['nombre_jefe']) {
            $con_familia++;
        } else {
            $sin_familia++;
        }

        if (!empty($persona['telefono'])) {
            $con_telefono++;
        }

        if (!empty($persona['direccion'])) {
            $con_direccion++;
        }
    }

    fputcsv($output, ['Personas con familia asignada:', $con_familia], ';');
    fputcsv($output, ['Personas sin familia asignada:', $sin_familia], ';');
    fputcsv($output, ['Personas con teléfono:', $con_telefono], ';');
    fputcsv($output, ['Personas con dirección:', $con_direccion], ';');
    fputcsv($output, ['Porcentaje con familia:', $con_familia > 0 ? round(($con_familia / count($personas)) * 100, 2) . '%' : '0%'], ';');
    fputcsv($output, ['Porcentaje con teléfono:', $con_telefono > 0 ? round(($con_telefono / count($personas)) * 100, 2) . '%' : '0%'], ';');

    fputcsv($output, [], ';'); // Línea vacía
    fputcsv($output, ['INFORMACIÓN DEL REPORTE'], ';');
    fputcsv($output, ['Fecha de exportación:', date('d/m/Y H:i:s')], ';');
    fputcsv($output, ['Usuario:', $_SESSION['nombre'] . ' ' . $_SESSION['apellido']], ';');
    fputcsv($output, ['Filtros aplicados:'], ';');

    if (!empty($search)) {
        fputcsv($output, ['- Búsqueda:', $search], ';');
    }

    if (!empty($familia_filter)) {
        if ($familia_filter === '0') {
            fputcsv($output, ['- Familia:', 'Sin familia asignada'], ';');
        } else {
            // Obtener nombre de la familia
            $stmt_familia = $pdo->prepare("SELECT nombre_jefe, apellido_jefe FROM familias WHERE id = ?");
            $stmt_familia->execute([$familia_filter]);
            $familia_info = $stmt_familia->fetch();
            if ($familia_info) {
                fputcsv($output, ['- Familia:', $familia_info['nombre_jefe'] . ' ' . $familia_info['apellido_jefe']], ';');
            }
        }
    }

    if (empty($search) && empty($familia_filter)) {
        fputcsv($output, ['- Sin filtros (todas las personas)'], ';');
    }

    // Registrar la exportación en el log si la función existe
    if (function_exists('registrarLog')) {
        registrarLog(
            $pdo,
            'personas',
            0,
            'exportar',
            'Exportación de ' . count($personas) . ' personas a Excel',
            $_SESSION['user_id']
        );
    }

    fclose($output);
    exit;
} catch (PDOException $e) {
    error_log("Error en exportación de personas: " . $e->getMessage());

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
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
                            <p class="mb-3">Ha ocurrido un error al generar el archivo de exportación de personas.</p>
                            <p class="text-muted">Por favor, intente nuevamente o contacte al administrador del sistema.</p>
                            <div class="d-flex gap-2">
                                <a href="../personas.php" class="btn btn-primary">
                                    <i class="fas fa-arrow-left me-2"></i>Volver a Personas
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

        <!-- Información técnica para el administrador -->
        <div class="container mt-4">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Información Técnica</h6>
                        </div>
                        <div class="card-body">
                            <p><strong>Error:</strong> <?php echo htmlspecialchars($e->getMessage()); ?></p>
                            <p><strong>Fecha:</strong> <?php echo date('d/m/Y H:i:s'); ?></p>
                            <p><strong>Usuario:</strong> <?php echo htmlspecialchars($_SESSION['nombre'] . ' ' . $_SESSION['apellido']); ?></p>
                            <p><strong>Archivo:</strong> api/export_personas.php</p>

                            <div class="alert alert-info mt-3">
                                <h6><i class="fas fa-lightbulb me-2"></i>Posibles Soluciones:</h6>
                                <ul class="mb-0">
                                    <li>Verificar la conexión a la base de datos</li>
                                    <li>Comprobar permisos de escritura del servidor</li>
                                    <li>Reducir el número de registros a exportar aplicando filtros</li>
                                    <li>Contactar al administrador del sistema si el problema persiste</li>
                                </ul>
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
?>