<?php
/**
 * API para exportar reportes y estadísticas
 * Genera archivos CSV/Excel con datos completos del sistema
 */

session_start();
require '../includes/conexion.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Obtener parámetros
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-t');
$tipo_reporte = $_GET['tipo_reporte'] ?? 'general';
$export_format = $_GET['export'] ?? 'excel';

try {
    // Obtener todas las estadísticas necesarias
    $data = [];
    
    // 1. Estadísticas generales del sistema
    $stmt = $pdo->query("
        SELECT 
            'Familias Totales' as concepto,
            COUNT(*) as valor,
            'unidades' as unidad
        FROM familias
        UNION ALL
        SELECT 
            'Familias Activas' as concepto,
            COUNT(*) as valor,
            'unidades' as unidad
        FROM familias WHERE estado = 'activa'
        UNION ALL
        SELECT 
            'Personas Totales' as concepto,
            COUNT(*) as valor,
            'unidades' as unidad
        FROM personas
        UNION ALL
        SELECT 
            'Personas con Familia' as concepto,
            COUNT(*) as valor,
            'unidades' as unidad
        FROM personas WHERE id_familia IS NOT NULL
        UNION ALL
        SELECT 
            'Tipos de Ayuda Activos' as concepto,
            COUNT(*) as valor,
            'unidades' as unidad
        FROM ayudas WHERE activo = 1
        UNION ALL
        SELECT 
            'Asignaciones Totales' as concepto,
            COUNT(*) as valor,
            'unidades' as unidad
        FROM asignaciones
        UNION ALL
        SELECT 
            'Asignaciones Entregadas' as concepto,
            COUNT(*) as valor,
            'unidades' as unidad
        FROM asignaciones WHERE estado = 'entregada'
    ");
    $estadisticas_generales = $stmt->fetchAll();
    
    // 2. Asignaciones en el período
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.numero_expediente,
            a.fecha_asignacion,
            a.fecha_creacion,
            a.estado,
            a.prioridad,
            a.cantidad,
            a.motivo,
            a.observaciones,
            CONCAT(f.nombre_jefe, ' ', f.apellido_jefe) as beneficiario,
            f.dni_jefe,
            f.telefono,
            f.direccion,
            f.barrio,
            ay.nombre_ayuda,
            ay.descripcion as ayuda_descripcion,
            CONCAT(ua.nombre, ' ', ua.apellido) as usuario_asignador,
            CONCAT(uu.nombre, ' ', uu.apellido) as usuario_autorizador,
            CONCAT(ue.nombre, ' ', ue.apellido) as usuario_entregador,
            a.fecha_autorizacion,
            a.fecha_entrega_real
        FROM asignaciones a
        LEFT JOIN familias f ON a.familia_id = f.id
        LEFT JOIN ayudas ay ON a.id_ayuda = ay.id
        LEFT JOIN usuarios ua ON a.usuario_asignador = ua.id
        LEFT JOIN usuarios uu ON a.usuario_autorizador = uu.id
        LEFT JOIN usuarios ue ON a.usuario_entregador = ue.id
        WHERE a.fecha_asignacion BETWEEN ? AND ?
        ORDER BY a.fecha_asignacion DESC
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $asignaciones_periodo = $stmt->fetchAll();
    
    // 3. Top ayudas más solicitadas
    $stmt = $pdo->prepare("
        SELECT 
            ay.nombre_ayuda,
            ay.descripcion,
            COUNT(a.id) as cantidad_asignaciones,
            SUM(a.cantidad) as cantidad_total,
            COUNT(CASE WHEN a.estado = 'entregada' THEN 1 END) as entregadas,
            COUNT(CASE WHEN a.estado = 'pendiente' THEN 1 END) as pendientes,
            COUNT(CASE WHEN a.estado = 'autorizada' THEN 1 END) as autorizadas,
            COUNT(CASE WHEN a.estado = 'cancelada' THEN 1 END) as canceladas,
            MIN(a.fecha_asignacion) as primera_asignacion,
            MAX(a.fecha_asignacion) as ultima_asignacion
        FROM asignaciones a
        JOIN ayudas ay ON a.id_ayuda = ay.id
        WHERE a.fecha_asignacion BETWEEN ? AND ?
        GROUP BY ay.id, ay.nombre_ayuda, ay.descripcion
        ORDER BY cantidad_asignaciones DESC
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $top_ayudas = $stmt->fetchAll();
    
    // 4. Familias más beneficiadas
    $stmt = $pdo->prepare("
        SELECT 
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            f.telefono,
            f.direccion,
            f.barrio,
            f.cantidad_integrantes,
            f.fecha_registro,
            COUNT(a.id) as cantidad_asignaciones,
            SUM(a.cantidad) as cantidad_total,
            COUNT(CASE WHEN a.estado = 'entregada' THEN 1 END) as asignaciones_entregadas,
            MIN(a.fecha_asignacion) as primera_asignacion,
            MAX(a.fecha_asignacion) as ultima_asignacion
        FROM asignaciones a
        JOIN familias f ON a.familia_id = f.id
        WHERE a.fecha_asignacion BETWEEN ? AND ?
        GROUP BY f.id
        ORDER BY cantidad_asignaciones DESC
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $familias_beneficiadas = $stmt->fetchAll();
    
    // 5. Estadísticas por barrio
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(f.barrio, 'Sin especificar') as barrio,
            COUNT(DISTINCT f.id) as familias_atendidas,
            COUNT(a.id) as total_asignaciones,
            SUM(a.cantidad) as cantidad_total,
            COUNT(CASE WHEN a.estado = 'entregada' THEN 1 END) as asignaciones_entregadas,
            COUNT(CASE WHEN a.prioridad = 'urgente' THEN 1 END) as asignaciones_urgentes,
            AVG(a.cantidad) as promedio_cantidad
        FROM asignaciones a
        JOIN familias f ON a.familia_id = f.id
        WHERE a.fecha_asignacion BETWEEN ? AND ?
        GROUP BY f.barrio
        ORDER BY total_asignaciones DESC
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $estadisticas_barrios = $stmt->fetchAll();
    
    // 6. Usuarios más activos
    $stmt = $pdo->prepare("
        SELECT 
            u.nombre,
            u.apellido,
            u.usuario,
            u.rol,
            u.fecha_registro,
            COUNT(a.id) as asignaciones_creadas,
            COUNT(aa.id) as asignaciones_autorizadas,
            COUNT(ae.id) as asignaciones_entregadas,
            MIN(a.fecha_asignacion) as primera_asignacion,
            MAX(a.fecha_asignacion) as ultima_asignacion
        FROM usuarios u
        LEFT JOIN asignaciones a ON u.id = a.usuario_asignador 
            AND a.fecha_asignacion BETWEEN ? AND ?
        LEFT JOIN asignaciones aa ON u.id = aa.usuario_autorizador 
            AND aa.fecha_asignacion BETWEEN ? AND ?
        LEFT JOIN asignaciones ae ON u.id = ae.usuario_entregador 
            AND ae.fecha_asignacion BETWEEN ? AND ?
        WHERE u.activo = 1
        GROUP BY u.id
        HAVING asignaciones_creadas > 0 OR asignaciones_autorizadas > 0 OR asignaciones_entregadas > 0
        ORDER BY asignaciones_creadas DESC
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta, $fecha_desde, $fecha_hasta, $fecha_desde, $fecha_hasta]);
    $usuarios_activos = $stmt->fetchAll();
    
    // 7. Distribución mensual (últimos 12 meses)
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(fecha_asignacion, '%Y-%m') as mes,
            DATE_FORMAT(fecha_asignacion, '%M %Y') as mes_nombre,
            COUNT(*) as total_asignaciones,
            COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes,
            COUNT(CASE WHEN estado = 'autorizada' THEN 1 END) as autorizadas,
            COUNT(CASE WHEN estado = 'entregada' THEN 1 END) as entregadas,
            COUNT(CASE WHEN estado = 'cancelada' THEN 1 END) as canceladas,
            COUNT(CASE WHEN prioridad = 'urgente' THEN 1 END) as urgentes,
            SUM(cantidad) as cantidad_total,
            AVG(cantidad) as promedio_cantidad
        FROM asignaciones 
        WHERE fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(fecha_asignacion, '%Y-%m')
        ORDER BY mes ASC
    ");
    $distribucion_mensual = $stmt->fetchAll();
    
    // Configurar headers para descarga
    $fecha_reporte = date('Y-m-d_H-i-s');
    $filename = "reporte_{$tipo_reporte}_{$fecha_reporte}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');
    
    // Crear output stream
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Escribir encabezado del reporte
    fputcsv($output, ['REPORTE DEL SISTEMA DE DESARROLLO SOCIAL'], ';');
    fputcsv($output, ['Municipalidad de San Fernando'], ';');
    fputcsv($output, [], ';');
    fputcsv($output, ['Período del reporte:', formatearFecha($fecha_desde) . ' al ' . formatearFecha($fecha_hasta)], ';');
    fputcsv($output, ['Tipo de reporte:', ucfirst($tipo_reporte)], ';');
    fputcsv($output, ['Fecha de generación:', date('d/m/Y H:i:s')], ';');
    fputcsv($output, ['Usuario:', $_SESSION['nombre'] . ' ' . $_SESSION['apellido']], ';');
    fputcsv($output, [], ';');
    
    // SECCIÓN 1: ESTADÍSTICAS GENERALES
    fputcsv($output, ['=== ESTADÍSTICAS GENERALES DEL SISTEMA ==='], ';');
    fputcsv($output, ['Concepto', 'Valor', 'Unidad'], ';');
    foreach ($estadisticas_generales as $stat) {
        fputcsv($output, [
            $stat['concepto'],
            number_format($stat['valor']),
            $stat['unidad']
        ], ';');
    }
    fputcsv($output, [], ';');
    
    // SECCIÓN 2: ASIGNACIONES DEL PERÍODO
    if (!empty($asignaciones_periodo)) {
        fputcsv($output, ['=== ASIGNACIONES EN EL PERÍODO ==='], ';');
        fputcsv($output, [
            'ID',
            'Expediente',
            'Fecha Asignación',
            'Beneficiario',
            'DNI',
            'Teléfono',
            'Dirección',
            'Barrio',
            'Tipo de Ayuda',
            'Cantidad',
            'Estado',
            'Prioridad',
            'Motivo',
            'Observaciones',
            'Usuario Asignador',
            'Usuario Autorizador',
            'Usuario Entregador',
            'Fecha Autorización',
            'Fecha Entrega',
            'Fecha Creación'
        ], ';');
        
        foreach ($asignaciones_periodo as $asignacion) {
            fputcsv($output, [
                $asignacion['id'],
                $asignacion['numero_expediente'] ?: 'N/A',
                formatearFecha($asignacion['fecha_asignacion']),
                $asignacion['beneficiario'],
                $asignacion['dni_jefe'],
                $asignacion['telefono'] ?: 'N/A',
                $asignacion['direccion'] ?: 'N/A',
                $asignacion['barrio'] ?: 'N/A',
                $asignacion['nombre_ayuda'],
                number_format($asignacion['cantidad'], 2),
                ucfirst($asignacion['estado']),
                ucfirst($asignacion['prioridad']),
                $asignacion['motivo'] ?: 'N/A',
                $asignacion['observaciones'] ?: 'N/A',
                $asignacion['usuario_asignador'] ?: 'N/A',
                $asignacion['usuario_autorizador'] ?: 'N/A',
                $asignacion['usuario_entregador'] ?: 'N/A',
                $asignacion['fecha_autorizacion'] ? formatearFechaHora($asignacion['fecha_autorizacion']) : 'N/A',
                $asignacion['fecha_entrega_real'] ? formatearFechaHora($asignacion['fecha_entrega_real']) : 'N/A',
                formatearFechaHora($asignacion['fecha_creacion'])
            ], ';');
        }
        fputcsv($output, [], ';');
    }
    
    // SECCIÓN 3: TOP AYUDAS MÁS SOLICITADAS
    if (!empty($top_ayudas)) {
        fputcsv($output, ['=== AYUDAS MÁS SOLICITADAS EN EL PERÍODO ==='], ';');
        fputcsv($output, [
            'Ranking',
            'Tipo de Ayuda',
            'Descripción',
            'Total Asignaciones',
            'Cantidad Total',
            'Entregadas',
            'Pendientes',
            'Autorizadas',
            'Canceladas',
            '% Efectividad',
            'Primera Asignación',
            'Última Asignación'
        ], ';');
        
        foreach ($top_ayudas as $index => $ayuda) {
            $efectividad = $ayuda['cantidad_asignaciones'] > 0 ? 
                round(($ayuda['entregadas'] / $ayuda['cantidad_asignaciones']) * 100, 2) : 0;
                
            fputcsv($output, [
                $index + 1,
                $ayuda['nombre_ayuda'],
                $ayuda['descripcion'] ?: 'Sin descripción',
                $ayuda['cantidad_asignaciones'],
                number_format($ayuda['cantidad_total'], 2),
                $ayuda['entregadas'],
                $ayuda['pendientes'],
                $ayuda['autorizadas'],
                $ayuda['canceladas'],
                $efectividad . '%',
                formatearFecha($ayuda['primera_asignacion']),
                formatearFecha($ayuda['ultima_asignacion'])
            ], ';');
        }
        fputcsv($output, [], ';');
    }
    
    // SECCIÓN 4: FAMILIAS MÁS BENEFICIADAS
    if (!empty($familias_beneficiadas)) {
        fputcsv($output, ['=== FAMILIAS MÁS BENEFICIADAS EN EL PERÍODO ==='], ';');
        fputcsv($output, [
            'Ranking',
            'Jefe de Familia',
            'DNI',
            'Teléfono',
            'Dirección',
            'Barrio',
            'Cantidad Integrantes',
            'Fecha Registro',
            'Total Asignaciones',
            'Cantidad Total',
            'Asignaciones Entregadas',
            '% Efectividad',
            'Primera Asignación',
            'Última Asignación'
        ], ';');
        
        foreach ($familias_beneficiadas as $index => $familia) {
            $efectividad_familia = $familia['cantidad_asignaciones'] > 0 ? 
                round(($familia['asignaciones_entregadas'] / $familia['cantidad_asignaciones']) * 100, 2) : 0;
                
            fputcsv($output, [
                $index + 1,
                $familia['nombre_jefe'] . ' ' . $familia['apellido_jefe'],
                $familia['dni_jefe'],
                $familia['telefono'] ?: 'N/A',
                $familia['direccion'] ?: 'N/A',
                $familia['barrio'] ?: 'N/A',
                $familia['cantidad_integrantes'],
                formatearFecha($familia['fecha_registro']),
                $familia['cantidad_asignaciones'],
                number_format($familia['cantidad_total'], 2),
                $familia['asignaciones_entregadas'],
                $efectividad_familia . '%',
                formatearFecha($familia['primera_asignacion']),
                formatearFecha($familia['ultima_asignacion'])
            ], ';');
        }
        fputcsv($output, [], ';');
    }
    
    // SECCIÓN 5: ESTADÍSTICAS POR BARRIO
    if (!empty($estadisticas_barrios)) {
        fputcsv($output, ['=== ESTADÍSTICAS POR BARRIO ==='], ';');
        fputcsv($output, [
            'Barrio',
            'Familias Atendidas',
            'Total Asignaciones',
            'Cantidad Total',
            'Asignaciones Entregadas',
            'Asignaciones Urgentes',
            'Promedio Cantidad',
            '% Efectividad'
        ], ';');
        
        foreach ($estadisticas_barrios as $barrio) {
            $efectividad_barrio = $barrio['total_asignaciones'] > 0 ? 
                round(($barrio['asignaciones_entregadas'] / $barrio['total_asignaciones']) * 100, 2) : 0;
                
            fputcsv($output, [
                $barrio['barrio'],
                $barrio['familias_atendidas'],
                $barrio['total_asignaciones'],
                number_format($barrio['cantidad_total'], 2),
                $barrio['asignaciones_entregadas'],
                $barrio['asignaciones_urgentes'],
                number_format($barrio['promedio_cantidad'], 2),
                $efectividad_barrio . '%'
            ], ';');
        }
        fputcsv($output, [], ';');
    }
    
    // SECCIÓN 6: USUARIOS MÁS ACTIVOS
    if (!empty($usuarios_activos)) {
        fputcsv($output, ['=== USUARIOS MÁS ACTIVOS EN EL PERÍODO ==='], ';');
        fputcsv($output, [
            'Usuario',
            'Nombre Completo',
            'Rol',
            'Fecha Registro',
            'Asignaciones Creadas',
            'Asignaciones Autorizadas',
            'Asignaciones Entregadas',
            'Total Actividad',
            'Primera Asignación',
            'Última Asignación'
        ], ';');
        
        foreach ($usuarios_activos as $usuario) {
            $total_actividad = $usuario['asignaciones_creadas'] + 
                             $usuario['asignaciones_autorizadas'] + 
                             $usuario['asignaciones_entregadas'];
                             
            fputcsv($output, [
                $usuario['usuario'],
                $usuario['nombre'] . ' ' . $usuario['apellido'],
                ucfirst($usuario['rol']),
                formatearFecha($usuario['fecha_registro']),
                $usuario['asignaciones_creadas'],
                $usuario['asignaciones_autorizadas'],
                $usuario['asignaciones_entregadas'],
                $total_actividad,
                $usuario['primera_asignacion'] ? formatearFecha($usuario['primera_asignacion']) : 'N/A',
                $usuario['ultima_asignacion'] ? formatearFecha($usuario['ultima_asignacion']) : 'N/A'
            ], ';');
        }
        fputcsv($output, [], ';');
    }
    
    // SECCIÓN 7: DISTRIBUCIÓN MENSUAL
    if (!empty($distribucion_mensual)) {
        fputcsv($output, ['=== DISTRIBUCIÓN MENSUAL (ÚLTIMOS 12 MESES) ==='], ';');
        fputcsv($output, [
            'Mes',
            'Total Asignaciones',
            'Pendientes',
            'Autorizadas',
            'Entregadas',
            'Canceladas',
            'Urgentes',
            'Cantidad Total',
            'Promedio Cantidad',
            '% Efectividad'
        ], ';');
        
        foreach ($distribucion_mensual as $mes) {
            $efectividad_mes = $mes['total_asignaciones'] > 0 ? 
                round(($mes['entregadas'] / $mes['total_asignaciones']) * 100, 2) : 0;
                
            fputcsv($output, [
                $mes['mes_nombre'],
                $mes['total_asignaciones'],
                $mes['pendientes'],
                $mes['autorizadas'],
                $mes['entregadas'],
                $mes['canceladas'],
                $mes['urgentes'],
                number_format($mes['cantidad_total'], 2),
                number_format($mes['promedio_cantidad'], 2),
                $efectividad_mes . '%'
            ], ';');
        }
        fputcsv($output, [], ';');
    }
    
    // RESUMEN EJECUTIVO
    $total_asignaciones_periodo = count($asignaciones_periodo);
    $total_entregadas_periodo = 0;
    $total_cantidad_periodo = 0;
    
    foreach ($asignaciones_periodo as $asignacion) {
        if ($asignacion['estado'] === 'entregada') {
            $total_entregadas_periodo++;
        }
        $total_cantidad_periodo += $asignacion['cantidad'];
    }
    
    $efectividad_general = $total_asignaciones_periodo > 0 ? 
        round(($total_entregadas_periodo / $total_asignaciones_periodo) * 100, 2) : 0;
    
    fputcsv($output, ['=== RESUMEN EJECUTIVO ==='], ';');
    fputcsv($output, ['Concepto', 'Valor'], ';');
    fputcsv($output, ['Período analizado', formatearFecha($fecha_desde) . ' al ' . formatearFecha($fecha_hasta)], ';');
    fputcsv($output, ['Total asignaciones en período', number_format($total_asignaciones_periodo)], ';');
    fputcsv($output, ['Total asignaciones entregadas', number_format($total_entregadas_periodo)], ';');
    fputcsv($output, ['Efectividad general', $efectividad_general . '%'], ';');
    fputcsv($output, ['Cantidad total distribuida', number_format($total_cantidad_periodo, 2)], ';');
    fputcsv($output, ['Familias beneficiadas', number_format(count($familias_beneficiadas))], ';');
    fputcsv($output, ['Tipos de ayuda utilizados', number_format(count($top_ayudas))], ';');
    fputcsv($output, ['Barrios atendidos', number_format(count($estadisticas_barrios))], ';');
    fputcsv($output, ['Usuarios activos', number_format(count($usuarios_activos))], ';');
    
    // Información del reporte
    fputcsv($output, [], ';');
    fputcsv($output, ['=== INFORMACIÓN DEL REPORTE ==='], ';');
    fputcsv($output, ['Sistema', 'Desarrollo Social - Municipalidad de San Fernando'], ';');
    fputcsv($output, ['Versión', '2.0'], ';');
    fputcsv($output, ['Generado por', $_SESSION['nombre'] . ' ' . $_SESSION['apellido']], ';');
    fputcsv($output, ['Rol del usuario', ucfirst($_SESSION['rol'])], ';');
    fputcsv($output, ['Fecha de generación', date('d/m/Y H:i:s')], ';');
    fputcsv($output, ['Nombre del archivo', $filename], ';');
    
    // Registrar la exportación en el log
    if (function_exists('registrarLog')) {
        registrarLog(
            $pdo,
            'reportes',
            0,
            'exportar',
            "Exportación de reporte {$tipo_reporte} del período {$fecha_desde} al {$fecha_hasta}",
            $_SESSION['user_id']
        );
    }
    
    fclose($output);
    exit;
    
} catch (PDOException $e) {
    error_log("Error en exportación de reportes: " . $e->getMessage());
    
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
                <div class="col-md-8">
                    <div class="card border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Error en Exportación de Reportes</h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Ha ocurrido un error al generar el reporte de estadísticas.</p>
                            <p class="text-muted">Este error puede deberse a:</p>
                            <ul class="text-muted">
                                <li>Problemas de conexión con la base de datos</li>
                                <li>Período de fechas muy extenso</li>
                                <li>Falta de datos en el sistema</li>
                                <li>Permisos insuficientes</li>
                            </ul>
                            <div class="d-flex gap-2">
                                <a href="../reportes.php" class="btn btn-primary">
                                    <i class="fas fa-arrow-left me-2"></i>Volver a Reportes
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
                <div class="col-md-10">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Información Técnica del Error</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Error:</strong> <?php echo htmlspecialchars($e->getMessage()); ?></p>
                                    <p><strong>Fecha:</strong> <?php echo date('d/m/Y H:i:s'); ?></p>
                                    <p><strong>Usuario:</strong> <?php echo htmlspecialchars($_SESSION['nombre'] . ' ' . $_SESSION['apellido']); ?></p>
                                    <p><strong>Archivo:</strong> api/export_reportes.php</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Período solicitado:</strong> <?php echo htmlspecialchars($fecha_desde . ' al ' . $fecha_hasta); ?></p>
                                    <p><strong>Tipo de reporte:</strong> <?php echo htmlspecialchars($tipo_reporte); ?></p>
                                    <p><strong>Formato:</strong> <?php echo htmlspecialchars($export_format); ?></p>
                                    <p><strong>Código de error:</strong> <?php echo $e->getCode(); ?></p>
                                </div>
                            </div>

                            <div class="alert alert-info mt-3">
                                <h6><i class="fas fa-lightbulb me-2"></i>Posibles Soluciones:</h6>
                                <ul class="mb-0">
                                    <li>Reducir el rango de fechas del reporte</li>
                                    <li>Verificar que existen datos en el período seleccionado</li>
                                    <li>Comprobar la conexión a la base de datos</li>
                                    <li>Verificar permisos de escritura del servidor</li>
                                    <li>Intentar con un tipo de reporte diferente</li>
                                    <li>Contactar al administrador del sistema si el problema persiste</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning mt-3">
                                <h6><i class="fas fa-exclamation-triangle me-2"></i>Para Administradores:</h6>
                                <p class="mb-0">Este error ha sido registrado en el log del sistema. Revise los logs de PHP y MySQL para obtener más información detallada sobre la causa del problema.</p>
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