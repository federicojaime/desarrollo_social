<?php
session_start();
require 'includes/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['rol'] ?? 'empleado';
$mensaje = '';
$error = '';

// Recuperar mensajes
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    unset($_SESSION['mensaje']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Obtener parámetros de fecha
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01'); // Primer día del mes actual
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-t');  // Último día del mes actual
$tipo_reporte = $_GET['tipo_reporte'] ?? 'general';

try {
    // Estadísticas generales del sistema
    $stats_generales = [];

    // Total de familias
    $stmt = $pdo->query("SELECT COUNT(*) as total, COUNT(CASE WHEN estado = 'activa' THEN 1 END) as activas FROM familias");
    $stats_generales['familias'] = $stmt->fetch();

    // Total de personas
    $stmt = $pdo->query("SELECT COUNT(*) as total, COUNT(CASE WHEN id_familia IS NOT NULL THEN 1 END) as con_familia FROM personas");
    $stats_generales['personas'] = $stmt->fetch();

    // Total de ayudas
    $stmt = $pdo->query("SELECT COUNT(*) as total, COUNT(CASE WHEN activo = 1 THEN 1 END) as activas FROM ayudas");
    $stats_generales['ayudas'] = $stmt->fetch();

    // Asignaciones por estado
    $stmt = $pdo->query("SELECT estado, COUNT(*) as cantidad FROM asignaciones GROUP BY estado");
    $asignaciones_estado = [];
    while ($row = $stmt->fetch()) {
        $asignaciones_estado[$row['estado']] = $row['cantidad'];
    }
    $stats_generales['asignaciones'] = $asignaciones_estado;

    // Estadísticas del período seleccionado
    $stats_periodo = [];

    // Asignaciones en el período
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes,
            COUNT(CASE WHEN estado = 'autorizada' THEN 1 END) as autorizadas,
            COUNT(CASE WHEN estado = 'entregada' THEN 1 END) as entregadas,
            COUNT(CASE WHEN estado = 'cancelada' THEN 1 END) as canceladas,
            COUNT(CASE WHEN prioridad = 'urgente' THEN 1 END) as urgentes,
            SUM(CASE WHEN estado = 'entregada' THEN cantidad ELSE 0 END) as cantidad_entregada
        FROM asignaciones 
        WHERE fecha_asignacion BETWEEN ? AND ?
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $stats_periodo['asignaciones'] = $stmt->fetch();

    // Top 10 ayudas más solicitadas en el período
    $stmt = $pdo->prepare("
        SELECT 
            ay.nombre_ayuda,
            COUNT(a.id) as cantidad_asignaciones,
            SUM(a.cantidad) as cantidad_total,
            COUNT(CASE WHEN a.estado = 'entregada' THEN 1 END) as entregadas
        FROM asignaciones a
        JOIN ayudas ay ON a.id_ayuda = ay.id
        WHERE a.fecha_asignacion BETWEEN ? AND ?
        GROUP BY ay.id, ay.nombre_ayuda
        ORDER BY cantidad_asignaciones DESC
        LIMIT 10
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $top_ayudas = $stmt->fetchAll();

    // Asignaciones por mes (últimos 12 meses)
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(fecha_asignacion, '%Y-%m') as mes,
            COUNT(*) as cantidad,
            COUNT(CASE WHEN estado = 'entregada' THEN 1 END) as entregadas
        FROM asignaciones 
        WHERE fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(fecha_asignacion, '%Y-%m')
        ORDER BY mes ASC
    ");
    $asignaciones_mensuales = $stmt->fetchAll();

    // Familias más beneficiadas en el período
    $stmt = $pdo->prepare("
        SELECT 
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            f.barrio,
            COUNT(a.id) as cantidad_asignaciones,
            SUM(a.cantidad) as cantidad_total
        FROM asignaciones a
        JOIN familias f ON a.familia_id = f.id
        WHERE a.fecha_asignacion BETWEEN ? AND ?
        GROUP BY f.id
        ORDER BY cantidad_asignaciones DESC
        LIMIT 10
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $familias_beneficiadas = $stmt->fetchAll();

    // Estadísticas por barrio
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(f.barrio, 'Sin especificar') as barrio,
            COUNT(DISTINCT f.id) as familias,
            COUNT(a.id) as asignaciones,
            SUM(a.cantidad) as cantidad_total
        FROM asignaciones a
        JOIN familias f ON a.familia_id = f.id
        WHERE a.fecha_asignacion BETWEEN ? AND ?
        GROUP BY f.barrio
        ORDER BY asignaciones DESC
        LIMIT 10
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $estadisticas_barrios = $stmt->fetchAll();

    // Usuarios más activos
    $stmt = $pdo->prepare("
        SELECT 
            u.nombre,
            u.apellido,
            u.rol,
            COUNT(a.id) as asignaciones_creadas
        FROM asignaciones a
        JOIN usuarios u ON a.usuario_asignador = u.id
        WHERE a.fecha_asignacion BETWEEN ? AND ?
        GROUP BY u.id
        ORDER BY asignaciones_creadas DESC
        LIMIT 5
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $usuarios_activos = $stmt->fetchAll();

    // Distribución por prioridad en el período
    $stmt = $pdo->prepare("
        SELECT 
            prioridad,
            COUNT(*) as cantidad,
            COUNT(CASE WHEN estado = 'entregada' THEN 1 END) as entregadas
        FROM asignaciones 
        WHERE fecha_asignacion BETWEEN ? AND ?
        GROUP BY prioridad
        ORDER BY 
            CASE prioridad 
                WHEN 'urgente' THEN 1 
                WHEN 'alta' THEN 2 
                WHEN 'media' THEN 3 
                WHEN 'baja' THEN 4 
            END
    ");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $distribucion_prioridad = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error al cargar estadísticas: " . $e->getMessage();
    $stats_generales = [];
    $stats_periodo = [];
    $top_ayudas = [];
    $asignaciones_mensuales = [];
    $familias_beneficiadas = [];
    $estadisticas_barrios = [];
    $usuarios_activos = [];
    $distribucion_prioridad = [];
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes y Estadísticas - Sistema de Desarrollo Social</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --sidebar-width: 280px;
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color: #64748b;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --info-color: #0891b2;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
            --border-color: #e2e8f0;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--light-color);
            color: var(--dark-color);
        }

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 2rem;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }

        /* Header Section */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: scale(1.5);
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 0;
            position: relative;
            z-index: 1;
        }

        /* Filters Section */
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--info-color));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card:hover::before {
            transform: scaleX(1);
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .stat-card-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-card-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
            line-height: 1;
        }

        .stat-card-label {
            color: var(--secondary-color);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }

        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .chart-header h3 {
            margin: 0;
            color: var(--dark-color);
            font-size: 1.2rem;
            font-weight: 600;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }

        .table-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .table-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark-color);
        }

        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }

        .table th {
            background: #f8fafc;
            border-bottom: 2px solid var(--border-color);
            color: var(--secondary-color);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 0.75rem;
        }

        .table td {
            padding: 1rem 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background-color: #f8fafc;
        }

        /* Progress Bars */
        .progress {
            height: 8px;
            border-radius: 4px;
            background-color: #f1f5f9;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-export {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        /* Alerts */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #f0fdf4;
            border-left-color: var(--success-color);
            color: #065f46;
        }

        .alert-danger {
            background: #fef2f2;
            border-left-color: var(--danger-color);
            color: #991b1b;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .fade-in-up:nth-child(odd) {
            animation-delay: 0.1s;
        }

        .fade-in-up:nth-child(even) {
            animation-delay: 0.2s;
        }

        /* Responsive Charts */
        .chart-canvas {
            max-height: 400px;
        }

        /* Period Summary */
        .period-summary {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .period-summary h4 {
            color: var(--primary-dark);
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .period-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .period-stat {
            text-align: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
        }

        .period-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            display: block;
        }

        .period-stat-label {
            font-size: 0.8rem;
            color: var(--secondary-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <h1><i class="fas fa-chart-line me-3"></i>Reportes y Estadísticas</h1>
            <p>Análisis detallado del sistema de desarrollo social</p>
        </div>

        <!-- Alertas -->
        <?php if ($mensaje): ?>
            <div class="alert alert-success alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($mensaje); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filters Section -->
        <div class="filters-section fade-in-up">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="fecha_desde" class="form-label">Fecha Desde</label>
                    <input type="date" class="form-control" id="fecha_desde" name="fecha_desde"
                        value="<?php echo htmlspecialchars($fecha_desde); ?>">
                </div>
                <div class="col-md-3">
                    <label for="fecha_hasta" class="form-label">Fecha Hasta</label>
                    <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta"
                        value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                </div>
                <div class="col-md-3">
                    <label for="tipo_reporte" class="form-label">Tipo de Reporte</label>
                    <select class="form-select" id="tipo_reporte" name="tipo_reporte">
                        <option value="general" <?php echo $tipo_reporte === 'general' ? 'selected' : ''; ?>>General</option>
                        <option value="asignaciones" <?php echo $tipo_reporte === 'asignaciones' ? 'selected' : ''; ?>>Asignaciones</option>
                        <option value="familias" <?php echo $tipo_reporte === 'familias' ? 'selected' : ''; ?>>Familias</option>
                        <option value="ayudas" <?php echo $tipo_reporte === 'ayudas' ? 'selected' : ''; ?>>Tipos de Ayuda</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-chart-bar me-2"></i>Generar
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-download"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="exportarReporte('pdf')"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                                <li><a class="dropdown-item" href="#" onclick="exportarReporte('excel')"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Estadísticas Generales del Sistema -->
        <div class="stats-grid fade-in-up">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-value"><?php echo number_format($stats_generales['familias']['total'] ?? 0); ?></div>
                        <div class="stat-card-label">Total Familias</div>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <small class="text-muted">
                    <?php echo number_format($stats_generales['familias']['activas'] ?? 0); ?> activas
                </small>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-value"><?php echo number_format($stats_generales['personas']['total'] ?? 0); ?></div>
                        <div class="stat-card-label">Total Personas</div>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--success-color), #047857);">
                        <i class="fas fa-user-friends"></i>
                    </div>
                </div>
                <small class="text-muted">
                    <?php echo number_format($stats_generales['personas']['con_familia'] ?? 0); ?> con familia
                </small>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-value"><?php echo number_format(array_sum($stats_generales['asignaciones'] ?? [])); ?></div>
                        <div class="stat-card-label">Total Asignaciones</div>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--info-color), #0e7490);">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                </div>
                <small class="text-muted">
                    <?php echo number_format($stats_generales['asignaciones']['entregada'] ?? 0); ?> entregadas
                </small>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-value"><?php echo number_format($stats_generales['ayudas']['total'] ?? 0); ?></div>
                        <div class="stat-card-label">Tipos de Ayuda</div>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--warning-color), #c2410c);">
                        <i class="fas fa-gift"></i>
                    </div>
                </div>
                <small class="text-muted">
                    <?php echo number_format($stats_generales['ayudas']['activas'] ?? 0); ?> activas
                </small>
            </div>
        </div>

        <!-- Resumen del Período -->
        <?php if (!empty($stats_periodo['asignaciones'])): ?>
            <div class="period-summary fade-in-up">
                <h4><i class="fas fa-calendar-alt me-2"></i>Período: <?php echo formatearFecha($fecha_desde); ?> - <?php echo formatearFecha($fecha_hasta); ?></h4>
                <div class="period-stats">
                    <div class="period-stat">
                        <span class="period-stat-value"><?php echo number_format($stats_periodo['asignaciones']['total']); ?></span>
                        <span class="period-stat-label">Asignaciones</span>
                    </div>
                    <div class="period-stat">
                        <span class="period-stat-value"><?php echo number_format($stats_periodo['asignaciones']['entregadas']); ?></span>
                        <span class="period-stat-label">Entregadas</span>
                    </div>
                    <div class="period-stat">
                        <span class="period-stat-value"><?php echo number_format($stats_periodo['asignaciones']['urgentes']); ?></span>
                        <span class="period-stat-label">Urgentes</span>
                    </div>
                    <div class="period-stat">
                        <span class="period-stat-value"><?php echo number_format($stats_periodo['asignaciones']['cantidad_entregada'], 0); ?></span>
                        <span class="period-stat-label">Cant. Entregada</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Charts Row -->
        <div class="row fade-in-up">
            <!-- Gráfico de Asignaciones Mensuales -->
            <div class="col-md-8">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-line me-2"></i>Asignaciones por Mes (Últimos 12 Meses)</h3>
                    </div>
                    <canvas id="asignacionesMensualesChart" class="chart-canvas"></canvas>
                </div>
            </div>

            <!-- Gráfico de Estados -->
            <div class="col-md-4">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-pie me-2"></i>Estados de Asignaciones</h3>
                    </div>
                    <canvas id="estadosChart" class="chart-canvas"></canvas>
                </div>
            </div>
        </div>

        <!-- Top Ayudas -->
        <?php if (!empty($top_ayudas)): ?>
            <div class="table-container fade-in-up">
                <div class="table-header">
                    <h3><i class="fas fa-trophy me-2"></i>Top 10 Ayudas Más Solicitadas en el Período</h3>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tipo de Ayuda</th>
                                <th>Asignaciones</th>
                                <th>Cantidad Total</th>
                                <th>Entregadas</th>
                                <th>% Efectividad</th>
                                <th>Progreso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_ayudas as $index => $ayuda): ?>
                                <tr>
                                    <td><span class="badge bg-primary"><?php echo $index + 1; ?></span></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?></strong>
                                    </td>
                                    <td><?php echo number_format($ayuda['cantidad_asignaciones']); ?></td>
                                    <td><?php echo number_format($ayuda['cantidad_total'], 2); ?></td>
                                    <td><?php echo number_format($ayuda['entregadas']); ?></td>
                                    <td>
                                        <?php
                                        $efectividad = $ayuda['cantidad_asignaciones'] > 0 ?
                                            round(($ayuda['entregadas'] / $ayuda['cantidad_asignaciones']) * 100, 1) : 0;
                                        ?>
                                        <span class="badge <?php echo $efectividad >= 80 ? 'bg-success' : ($efectividad >= 60 ? 'bg-warning' : 'bg-danger'); ?>">
                                            <?php echo $efectividad; ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress">
                                            <div class="progress-bar <?php echo $efectividad >= 80 ? 'bg-success' : ($efectividad >= 60 ? 'bg-warning' : 'bg-danger'); ?>"
                                                style="width: <?php echo $efectividad; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Familias y Barrios Row -->
        <div class="row fade-in-up">
            <!-- Familias Más Beneficiadas -->
            <?php if (!empty($familias_beneficiadas)): ?>
                <div class="col-md-6">
                    <div class="table-container">
                        <div class="table-header">
                            <h3><i class="fas fa-users me-2"></i>Familias Más Beneficiadas</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Familia</th>
                                        <th>Barrio</th>
                                        <th>Asignaciones</th>
                                        <th>Cantidad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($familias_beneficiadas as $familia): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe']); ?></strong>
                                                <br><small class="text-muted">DNI: <?php echo htmlspecialchars($familia['dni_jefe']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($familia['barrio'] ?? 'N/A'); ?></td>
                                            <td><span class="badge bg-info"><?php echo $familia['cantidad_asignaciones']; ?></span></td>
                                            <td><?php echo number_format($familia['cantidad_total'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Estadísticas por Barrio -->
            <?php if (!empty($estadisticas_barrios)): ?>
                <div class="col-md-6">
                    <div class="table-container">
                        <div class="table-header">
                            <h3><i class="fas fa-map-marker-alt me-2"></i>Estadísticas por Barrio</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Barrio</th>
                                        <th>Familias</th>
                                        <th>Asignaciones</th>
                                        <th>Cantidad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estadisticas_barrios as $barrio): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($barrio['barrio']); ?></strong></td>
                                            <td><?php echo number_format($barrio['familias']); ?></td>
                                            <td><span class="badge bg-primary"><?php echo $barrio['asignaciones']; ?></span></td>
                                            <td><?php echo number_format($barrio['cantidad_total'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Usuarios y Prioridades Row -->
        <div class="row fade-in-up">
            <!-- Usuarios Más Activos -->
            <?php if (!empty($usuarios_activos)): ?>
                <div class="col-md-6">
                    <div class="table-container">
                        <div class="table-header">
                            <h3><i class="fas fa-user-check me-2"></i>Usuarios Más Activos</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Rol</th>
                                        <th>Asignaciones Creadas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usuarios_activos as $usuario): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge <?php
                                                                    echo match ($usuario['rol']) {
                                                                        'admin' => 'bg-danger',
                                                                        'supervisor' => 'bg-warning',
                                                                        'empleado' => 'bg-success',
                                                                        default => 'bg-secondary'
                                                                    };
                                                                    ?>">
                                                    <?php echo ucfirst($usuario['rol']); ?>
                                                </span>
                                            </td>
                                            <td><span class="badge bg-info"><?php echo $usuario['asignaciones_creadas']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Distribución por Prioridad -->
            <?php if (!empty($distribucion_prioridad)): ?>
                <div class="col-md-6">
                    <div class="table-container">
                        <div class="table-header">
                            <h3><i class="fas fa-exclamation-triangle me-2"></i>Distribución por Prioridad</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Prioridad</th>
                                        <th>Total</th>
                                        <th>Entregadas</th>
                                        <th>% Efectividad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($distribucion_prioridad as $prioridad): ?>
                                        <tr>
                                            <td>
                                                <span class="badge <?php
                                                                    echo match ($prioridad['prioridad']) {
                                                                        'urgente' => 'bg-danger',
                                                                        'alta' => 'bg-warning',
                                                                        'media' => 'bg-info',
                                                                        'baja' => 'bg-secondary',
                                                                        default => 'bg-secondary'
                                                                    };
                                                                    ?>">
                                                    <?php echo ucfirst($prioridad['prioridad']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($prioridad['cantidad']); ?></td>
                                            <td><?php echo number_format($prioridad['entregadas']); ?></td>
                                            <td>
                                                <?php
                                                $efectividad_prioridad = $prioridad['cantidad'] > 0 ?
                                                    round(($prioridad['entregadas'] / $prioridad['cantidad']) * 100, 1) : 0;
                                                ?>
                                                <?php echo $efectividad_prioridad; ?>%
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Gráfico de Prioridades -->
        <?php if (!empty($distribucion_prioridad)): ?>
            <div class="chart-container fade-in-up">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-bar me-2"></i>Distribución de Asignaciones por Prioridad</h3>
                </div>
                <canvas id="prioridadChart" class="chart-canvas"></canvas>
            </div>
        <?php endif; ?>

        <!-- Botones de Exportación -->
        <div class="d-flex justify-content-end fade-in-up">
            <div class="export-buttons">
                <button class="btn btn-outline-primary btn-export" onclick="imprimirReporte()">
                    <i class="fas fa-print"></i>
                    <span>Imprimir</span>
                </button>
                <button class="btn btn-outline-success btn-export" onclick="exportarReporte('excel')">
                    <i class="fas fa-file-excel"></i>
                    <span>Exportar Excel</span>
                </button>
                <button class="btn btn-outline-danger btn-export" onclick="exportarReporte('pdf')">
                    <i class="fas fa-file-pdf"></i>
                    <span>Exportar PDF</span>
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Datos para los gráficos
        const asignacionesMensuales = <?php echo json_encode($asignaciones_mensuales); ?>;
        const estadosAsignaciones = <?php echo json_encode($stats_generales['asignaciones'] ?? []); ?>;
        const distribucionPrioridad = <?php echo json_encode($distribucion_prioridad); ?>;

        // Configuración de colores
        const colors = {
            primary: '#2563eb',
            success: '#059669',
            warning: '#d97706',
            danger: '#dc2626',
            info: '#0891b2',
            secondary: '#64748b'
        };

        // Gráfico de Asignaciones Mensuales
        if (asignacionesMensuales.length > 0) {
            const ctx1 = document.getElementById('asignacionesMensualesChart').getContext('2d');
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: asignacionesMensuales.map(item => {
                        const fecha = new Date(item.mes + '-01');
                        return fecha.toLocaleDateString('es-ES', {
                            month: 'short',
                            year: 'numeric'
                        });
                    }),
                    datasets: [{
                        label: 'Total Asignaciones',
                        data: asignacionesMensuales.map(item => item.cantidad),
                        borderColor: colors.primary,
                        backgroundColor: colors.primary + '20',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Entregadas',
                        data: asignacionesMensuales.map(item => item.entregadas),
                        borderColor: colors.success,
                        backgroundColor: colors.success + '20',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        // Gráfico de Estados
        if (Object.keys(estadosAsignaciones).length > 0) {
            const ctx2 = document.getElementById('estadosChart').getContext('2d');
            const estadosData = Object.entries(estadosAsignaciones);

            new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: estadosData.map(([estado]) => estado.charAt(0).toUpperCase() + estado.slice(1)),
                    datasets: [{
                        data: estadosData.map(([, cantidad]) => cantidad),
                        backgroundColor: [
                            colors.warning, // pendiente
                            colors.info, // autorizada
                            colors.success, // entregada
                            colors.danger // cancelada
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        // Gráfico de Prioridad
        if (distribucionPrioridad.length > 0) {
            const ctx3 = document.getElementById('prioridadChart').getContext('2d');
            new Chart(ctx3, {
                type: 'bar',
                data: {
                    labels: distribucionPrioridad.map(item =>
                        item.prioridad.charAt(0).toUpperCase() + item.prioridad.slice(1)
                    ),
                    datasets: [{
                        label: 'Total Asignaciones',
                        data: distribucionPrioridad.map(item => item.cantidad),
                        backgroundColor: [
                            colors.danger, // urgente
                            colors.warning, // alta
                            colors.info, // media
                            colors.secondary // baja
                        ]
                    }, {
                        label: 'Entregadas',
                        data: distribucionPrioridad.map(item => item.entregadas),
                        backgroundColor: colors.success,
                        type: 'line',
                        borderColor: colors.success,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            position: 'left'
                        }
                    }
                }
            });
        }

        // Funciones de exportación
        function exportarReporte(formato) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', formato);
            window.open(`api/export_reportes.php?${params.toString()}`, '_blank');
        }

        function imprimirReporte() {
            // Ocultar elementos no imprimibles
            const elementosOcultar = document.querySelectorAll('.export-buttons, .filters-section, .btn');
            elementosOcultar.forEach(el => el.style.display = 'none');

            // Imprimir
            window.print();

            // Restaurar elementos
            setTimeout(() => {
                elementosOcultar.forEach(el => el.style.display = '');
            }, 1000);
        }

        // Auto-actualizar contadores
        document.addEventListener('DOMContentLoaded', function() {
            const counters = document.querySelectorAll('.stat-card-value, .period-stat-value');
            counters.forEach(counter => {
                const target = parseInt(counter.textContent.replace(/,/g, ''));
                let current = 0;
                const increment = target / 50;
                const timer = setInterval(function() {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    counter.textContent = Math.floor(current).toLocaleString();
                }, 30);
            });
        });

        // Validación de fechas
        document.getElementById('fecha_desde').addEventListener('change', function() {
            const fechaHasta = document.getElementById('fecha_hasta');
            fechaHasta.min = this.value;
        });

        document.getElementById('fecha_hasta').addEventListener('change', function() {
            const fechaDesde = document.getElementById('fecha_desde');
            fechaDesde.max = this.value;
        });
    </script>

    <style media="print">
        .main-content {
            margin-left: 0 !important;
        }

        .export-buttons,
        .filters-section,
        .btn {
            display: none !important;
        }

        .chart-container,
        .table-container {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .stat-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        body {
            font-size: 12px;
        }

        .page-header {
            color: black !important;
        }

        .stat-card-value {
            color: black !important;
        }
    </style>
</body>

</html>