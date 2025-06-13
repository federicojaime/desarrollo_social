<?php

/**
 * API para exportar tipos de ayuda a Excel
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
    $estado_filter = $_GET['estado'] ?? 'activo';

    // Construir consulta con filtros
    $where_conditions = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where_conditions[] = "(nombre_ayuda LIKE ? OR descripcion LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param]);
    }

    if ($estado_filter === 'activo') {
        $where_conditions[] = "activo = 1";
    } elseif ($estado_filter === 'inactivo') {
        $where_conditions[] = "activo = 0";
    }

    $where_clause = "WHERE " . implode(" AND ", $where_conditions);

    // Obtener datos de ayudas con estadísticas de uso
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id), 0
            ) as total_asignaciones,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'pendiente'), 0
            ) as asignaciones_pendientes,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'autorizada'), 0
            ) as asignaciones_autorizadas,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'entregada'), 0
            ) as asignaciones_entregadas,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'cancelada'), 0
            ) as asignaciones_canceladas,
            COALESCE(
                (SELECT SUM(asg.cantidad) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'entregada'), 0
            ) as cantidad_total_entregada,
            COALESCE(
                (SELECT MAX(asg.fecha_asignacion) FROM asignaciones asg WHERE asg.id_ayuda = a.id), NULL
            ) as ultima_asignacion,
            COALESCE(
                (SELECT MIN(asg.fecha_asignacion) FROM asignaciones asg WHERE asg.id_ayuda = a.id), NULL
            ) as primera_asignacion
        FROM ayudas a
        $where_clause
        ORDER BY a.fecha_registro DESC
    ");

    $stmt->execute($params);
    $ayudas = $stmt->fetchAll();

    // Configurar headers para descarga de CSV
    $filename = 'tipos_ayuda_' . date('Y-m-d_H-i-s') . '.csv';

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
        'Nombre de la Ayuda',
        'Descripción',
        'Estado',
        'Total Asignaciones',
        'Asignaciones Pendientes',
        'Asignaciones Autorizadas',
        'Asignaciones Entregadas',
        'Asignaciones Canceladas',
        'Cantidad Total Entregada',
        'Primera Asignación',
        'Última Asignación',
        'Fecha de Registro'
    ];

    fputcsv($output, $headers, ';'); // Usar ; como separador para mejor compatibilidad con Excel

    // Agregar datos
    foreach ($ayudas as $ayuda) {
        $row = [
            $ayuda['id'],
            $ayuda['nombre_ayuda'],
            $ayuda['descripcion'] ?: 'Sin descripción',
            $ayuda['activo'] ? 'Activa' : 'Inactiva',
            $ayuda['total_asignaciones'],
            $ayuda['asignaciones_pendientes'],
            $ayuda['asignaciones_autorizadas'],
            $ayuda['asignaciones_entregadas'],
            $ayuda['asignaciones_canceladas'],
            number_format($ayuda['cantidad_total_entregada'], 2, ',', '.'),
            $ayuda['primera_asignacion'] ? formatearFecha($ayuda['primera_asignacion']) : 'Sin asignaciones',
            $ayuda['ultima_asignacion'] ? formatearFecha($ayuda['ultima_asignacion']) : 'Sin asignaciones',
            formatearFechaHora($ayuda['fecha_registro'])
        ];

        fputcsv($output, $row, ';');
    }

    // Calcular estadísticas generales
    $total_ayudas = count($ayudas);
    $ayudas_activas = 0;
    $ayudas_inactivas = 0;
    $total_asignaciones_sistema = 0;
    $ayudas_con_asignaciones = 0;
    $ayudas_sin_asignaciones = 0;

    foreach ($ayudas as $ayuda) {
        if ($ayuda['activo']) {
            $ayudas_activas++;
        } else {
            $ayudas_inactivas++;
        }

        $total_asignaciones_sistema += $ayuda['total_asignaciones'];

        if ($ayuda['total_asignaciones'] > 0) {
            $ayudas_con_asignaciones++;
        } else {
            $ayudas_sin_asignaciones++;
        }
    }

    // Top 5 ayudas más utilizadas
    $ayudas_ordenadas = $ayudas;
    usort($ayudas_ordenadas, function ($a, $b) {
        return $b['total_asignaciones'] <=> $a['total_asignaciones'];
    });
    $top_ayudas = array_slice($ayudas_ordenadas, 0, 5);

    // Agregar estadísticas al final
    fputcsv($output, [], ';'); // Línea vacía
    fputcsv($output, ['ESTADÍSTICAS DEL REPORTE'], ';');
    fputcsv($output, ['Total de tipos de ayuda exportados:', $total_ayudas], ';');
    fputcsv($output, ['Ayudas activas:', $ayudas_activas], ';');
    fputcsv($output, ['Ayudas inactivas:', $ayudas_inactivas], ';');
    fputcsv($output, ['Total asignaciones en el sistema:', $total_asignaciones_sistema], ';');
    fputcsv($output, ['Ayudas con asignaciones:', $ayudas_con_asignaciones], ';');
    fputcsv($output, ['Ayudas sin asignaciones:', $ayudas_sin_asignaciones], ';');

    if ($total_ayudas > 0) {
        fputcsv($output, ['Promedio asignaciones por ayuda:', round($total_asignaciones_sistema / $total_ayudas, 2)], ';');
        fputcsv($output, ['Porcentaje ayudas activas:', round(($ayudas_activas / $total_ayudas) * 100, 2) . '%'], ';');
        fputcsv($output, ['Porcentaje con asignaciones:', round(($ayudas_con_asignaciones / $total_ayudas) * 100, 2) . '%'], ';');
    }

    // Top 5 ayudas más utilizadas
    if (!empty($top_ayudas)) {
        fputcsv($output, [], ';'); // Línea vacía
        fputcsv($output, ['TOP 5 AYUDAS MÁS UTILIZADAS'], ';');
        fputcsv($output, ['Ranking', 'Nombre de la Ayuda', 'Total Asignaciones', 'Cantidad Entregada'], ';');

        foreach ($top_ayudas as $index => $ayuda) {
            if ($ayuda['total_asignaciones'] > 0) {
                fputcsv($output, [
                    ($index + 1),
                    $ayuda['nombre_ayuda'],
                    $ayuda['total_asignaciones'],
                    number_format($ayuda['cantidad_total_entregada'], 2, ',', '.')
                ], ';');
            }
        }
    }

    fputcsv($output, [], ';'); // Línea vacía
    fputcsv($output, ['INFORMACIÓN DEL REPORTE'], ';');
    fputcsv($output, ['Fecha de exportación:', date('d/m/Y H:i:s')], ';');
    fputcsv($output, ['Usuario:', $_SESSION['nombre'] . ' ' . $_SESSION['apellido']], ';');
    fputcsv($output, ['Filtros aplicados:'], ';');

    if (!empty($search)) {
        fputcsv($output, ['- Búsqueda:', $search], ';');
    }

    if ($estado_filter === 'activo') {
        fputcsv($output, ['- Estado:', 'Solo ayudas activas'], ';');
    } elseif ($estado_filter === 'inactivo') {
        fputcsv($output, ['- Estado:', 'Solo ayudas inactivas'], ';');
    } else {
        fputcsv($output, ['- Estado:', 'Todas las ayudas'], ';');
    }

    if (empty($search) && $estado_filter === 'activo') {
        fputcsv($output, ['- Filtro por defecto (ayudas activas)'], ';');
    }

    // Registrar la exportación en el log si la función existe
    if (function_exists('registrarLog')) {
        registrarLog(
            $pdo,
            'ayudas',
            0,
            'exportar',
            'Exportación de ' . count($ayudas) . ' tipos de ayuda a Excel',
            $_SESSION['user_id']
        );
    }

    fclose($output);
    exit;
} catch (PDOException $e) {
    error_log("Error en exportación de ayudas: " . $e->getMessage());

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
                            <p class="mb-3">Ha ocurrido un error al generar el archivo de exportación de tipos de ayuda.</p>
                            <p class="text-muted">Por favor, intente nuevamente o contacte al administrador del sistema.</p>
                            <div class="d-flex gap-2">
                                <a href="../ayudas.php" class="btn btn-primary">
                                    <i class="fas fa-arrow-left me-2"></i>Volver a Tipos de Ayuda
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
                            <p><strong>Archivo:</strong> api/export_ayudas.php</p>

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

// Función auxiliar para formato de fecha (por si no está disponible)
if (!function_exists('formatearFecha')) {
    function formatearFecha($fecha)
    {
        if (!$fecha || $fecha === '0000-00-00' || $fecha === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        return date('d/m/Y', strtotime($fecha));
    }
}

if (!function_exists('formatearFechaHora')) {
    function formatearFechaHora($fecha)
    {
        if (!$fecha || $fecha === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        return date('d/m/Y H:i', strtotime($fecha));
    }
}
?>