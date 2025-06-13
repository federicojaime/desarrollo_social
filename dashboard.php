<?php
session_start();
require 'includes/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['rol'] ?? 'empleado';
$user_name = $_SESSION['nombre'] ?? $_SESSION['username'] ?? 'Usuario';
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

try {
    // Estadísticas principales
    $stats = [];
    
    // Total de familias activas
    $stmt = $pdo->query("SELECT COUNT(*) FROM familias WHERE estado = 'activa' OR estado IS NULL");
    $stats['familias_activas'] = $stmt->fetchColumn();
    
    // Total de personas registradas
    $stmt = $pdo->query("SELECT COUNT(*) FROM personas");
    $stats['total_personas'] = $stmt->fetchColumn();
    
    // Asignaciones por estado
    $stmt = $pdo->query("SELECT estado, COUNT(*) as cantidad FROM asignaciones GROUP BY estado");
    $asignaciones_estado = [];
    while ($row = $stmt->fetch()) {
        $asignaciones_estado[$row['estado']] = $row['cantidad'];
    }
    
    $stats['asignaciones_pendientes'] = $asignaciones_estado['pendiente'] ?? 0;
    $stats['asignaciones_autorizadas'] = $asignaciones_estado['autorizada'] ?? 0;
    $stats['asignaciones_entregadas'] = $asignaciones_estado['entregada'] ?? 0;
    $stats['asignaciones_canceladas'] = $asignaciones_estado['cancelada'] ?? 0;
    $stats['total_asignaciones'] = array_sum($asignaciones_estado);
    
    // Asignaciones este mes
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM asignaciones 
        WHERE YEAR(fecha_asignacion) = YEAR(CURDATE()) 
        AND MONTH(fecha_asignacion) = MONTH(CURDATE())
    ");
    $stats['asignaciones_mes'] = $stmt->fetchColumn();
    
    // Total de tipos de ayuda
    $stmt = $pdo->query("SELECT COUNT(*) FROM ayudas WHERE activo = 1");
    $stats['tipos_ayuda'] = $stmt->fetchColumn();
    
    // Últimas 8 asignaciones
    $stmt = $pdo->query("
        SELECT 
            a.id,
            a.fecha_asignacion,
            a.fecha_creacion,
            COALESCE(a.estado, 'pendiente') as estado,
            COALESCE(a.prioridad, 'media') as prioridad,
            a.numero_expediente,
            a.cantidad,
            COALESCE(f.nombre_jefe, b.nombre, p.nombre, 'N/A') as beneficiario,
            COALESCE(f.apellido_jefe, b.apellido, p.apellido, '') as apellido_beneficiario,
            COALESCE(ay.nombre_ayuda, 'Ayuda no especificada') as nombre_ayuda,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido
        FROM asignaciones a
        LEFT JOIN familias f ON a.familia_id = f.id
        LEFT JOIN personas p ON a.id_persona = p.id
        LEFT JOIN beneficiarios b ON a.beneficiario_id = b.id
        LEFT JOIN ayudas ay ON a.id_ayuda = ay.id
        LEFT JOIN usuarios u ON a.usuario_asignador = u.id
        ORDER BY a.fecha_creacion DESC
        LIMIT 8
    ");
    $ultimas_asignaciones = $stmt->fetchAll();
    
    // Top 5 ayudas más solicitadas (últimos 30 días)
    $stmt = $pdo->query("
        SELECT 
            ay.nombre_ayuda,
            COUNT(*) as cantidad,
            SUM(a.cantidad) as total_cantidad
        FROM asignaciones a
        JOIN ayudas ay ON a.id_ayuda = ay.id
        WHERE a.fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY ay.id, ay.nombre_ayuda
        ORDER BY cantidad DESC
        LIMIT 5
    ");
    $top_ayudas = $stmt->fetchAll();
    
    // Asignaciones por prioridad
    $stmt = $pdo->query("
        SELECT 
            COALESCE(prioridad, 'media') as prioridad, 
            COUNT(*) as cantidad 
        FROM asignaciones 
        WHERE fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY COALESCE(prioridad, 'media')
    ");
    $asignaciones_prioridad = [];
    while ($row = $stmt->fetch()) {
        $asignaciones_prioridad[$row['prioridad']] = $row['cantidad'];
    }
    
    // Actividad reciente (últimos 10 registros de log si existe)
    $actividad_reciente = [];
    if (function_exists('tablaExiste') && tablaExiste($pdo, 'log_actividades')) {
        $stmt = $pdo->query("
            SELECT 
                l.accion,
                l.descripcion,
                l.fecha_actividad,
                l.tabla_afectada,
                u.nombre as usuario_nombre,
                u.apellido as usuario_apellido
            FROM log_actividades l
            LEFT JOIN usuarios u ON l.usuario_id = u.id
            ORDER BY l.fecha_actividad DESC
            LIMIT 10
        ");
        $actividad_reciente = $stmt->fetchAll();
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar estadísticas: " . $e->getMessage();
    // Valores por defecto en caso de error
    $stats = [
        'familias_activas' => 0,
        'total_personas' => 0,
        'asignaciones_pendientes' => 0,
        'asignaciones_autorizadas' => 0,
        'asignaciones_entregadas' => 0,
        'asignaciones_canceladas' => 0,
        'total_asignaciones' => 0,
        'asignaciones_mes' => 0,
        'tipos_ayuda' => 0
    ];
    $ultimas_asignaciones = [];
    $top_ayudas = [];
    $asignaciones_prioridad = [];
    $actividad_reciente = [];
}

// Manejo de agregar usuario (solo admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 'admin' && isset($_POST['agregar_usuario'])) {
    $nombre = limpiarEntrada($_POST['nombre']);
    $apellido = limpiarEntrada($_POST['apellido']);
    $email = limpiarEntrada($_POST['email']);
    $usuario = limpiarEntrada($_POST['usuario']);
    $contraseña = $_POST['contraseña'];
    $rol = $_POST['rol'];

    if (empty($nombre) || empty($apellido) || empty($usuario) || empty($contraseña)) {
        $_SESSION['error'] = "Todos los campos son obligatorios.";
    } else {
        try {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ? OR email = ?");
            $stmt_check->execute([$usuario, $email]);
            
            if ($stmt_check->fetchColumn() > 0) {
                $_SESSION['error'] = "El usuario o email ya existe.";
            } else {
                $contraseña_hash = password_hash($contraseña, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios (nombre, apellido, email, usuario, contraseña, rol) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $apellido, $email, $usuario, $contraseña_hash, $rol]);
                
                if (function_exists('registrarLog')) {
                    registrarLog($pdo, 'usuarios', $pdo->lastInsertId(), 'crear', 
                        "Usuario creado: $nombre $apellido", $_SESSION['user_id']);
                }
                
                $_SESSION['mensaje'] = "Usuario creado exitosamente.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error al crear usuario: " . $e->getMessage();
        }
    }
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Desarrollo Social</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--light-color);
            color: var(--dark-color);
            line-height: 1.6;
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

        /* Header */
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="2" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1.5" fill="white" opacity="0.08"/><circle cx="50" cy="10" r="1" fill="white" opacity="0.06"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>') repeat;
            opacity: 0.3;
        }

        .dashboard-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .dashboard-header p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 0;
        }

        .header-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1.5rem;
        }

        .header-stat {
            text-align: center;
        }

        .header-stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            display: block;
        }

        .header-stat-label {
            font-size: 0.875rem;
            opacity: 0.8;
        }

        /* Cards Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
            background: linear-gradient(90deg, var(--primary-color), var(--primary-dark));
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
            position: relative;
            overflow: hidden;
        }

        .stat-card-icon::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(45deg) translateX(-100%);
            transition: transform 0.6s ease;
        }

        .stat-card:hover .stat-card-icon::before {
            transform: rotate(45deg) translateX(100%);
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
            margin-bottom: 0.5rem;
        }

        .stat-card-change {
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-card-change.positive {
            color: var(--success-color);
        }

        .stat-card-change.negative {
            color: var(--danger-color);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Tables */
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .table-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: between;
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

        .table {
            margin-bottom: 0;
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

        /* Badges */
        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
        }

        .badge-pendiente {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge-autorizada {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-entregada {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge-cancelada {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .badge-urgente {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .badge-alta {
            background-color: #fed7aa;
            color: #9a3412;
        }

        .badge-media {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-baja {
            background-color: #f1f5f9;
            color: #475569;
        }

        /* Progress bars */
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

        /* Quick actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .quick-action {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            text-decoration: none;
            color: var(--dark-color);
            transition: all 0.3s ease;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .quick-action:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .quick-action i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .quick-action h4 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0;
        }

        .quick-action p {
            font-size: 0.85rem;
            color: var(--secondary-color);
            margin: 0;
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
            background-color: #f0fdf4;
            border-left-color: var(--success-color);
            color: #065f46;
        }

        .alert-danger {
            background-color: #fef2f2;
            border-left-color: var(--danger-color);
            color: #991b1b;
        }

        /* Empty states */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--secondary-color);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--dark-color);
        }

        .empty-state p {
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .fade-in-up:nth-child(2) { animation-delay: 0.1s; }
        .fade-in-up:nth-child(3) { animation-delay: 0.2s; }
        .fade-in-up:nth-child(4) { animation-delay: 0.3s; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <div class="dashboard-header fade-in-up">
            <h1>¡Bienvenido, <?php echo htmlspecialchars($user_name); ?>!</h1>
            <p>Panel de control del Sistema de Desarrollo Social</p>
            <div class="header-stats">
                <div class="header-stat">
                    <span class="header-stat-number"><?php echo number_format($stats['total_asignaciones']); ?></span>
                    <span class="header-stat-label">Total Asignaciones</span>
                </div>
                <div class="header-stat">
                    <span class="header-stat-number"><?php echo number_format($stats['familias_activas']); ?></span>
                    <span class="header-stat-label">Familias Activas</span>
                </div>
                <div class="header-stat">
                    <span class="header-stat-number"><?php echo number_format($stats['asignaciones_mes']); ?></span>
                    <span class="header-stat-label">Este Mes</span>
                </div>
            </div>
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

        <!-- Estadísticas principales -->
        <div class="stats-grid">
            <div class="stat-card fade-in-up">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-value"><?php echo number_format($stats['asignaciones_pendientes']); ?></div>
                        <div class="stat-card-label">Asignaciones Pendientes</div>
                        <?php if ($stats['asignaciones_pendientes'] > 0): ?>
                        <div class="stat-card-change positive">
                            <i class="fas fa-arrow-up"></i>
                            Requieren atención
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card fade-in-up">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-value"><?php echo number_format($stats['asignaciones_entregadas']); ?></div>
                        <div class="stat-card-label">Asignaciones Entregadas</div>
                        <div class="stat-card-change positive">
                            <i class="fas fa-check"></i>
                            Completadas
                        </div>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card fade-in-up">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-value"><?php echo number_format($stats['familias_activas']); ?></div>
                        <div class="stat-card-label">Familias Registradas</div>
                        <div class="stat-card-change positive">
                            <i class="fas fa-users"></i>
                            Activas
                        </div>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, #2563eb, #1d4ed8);">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card fade-in-up">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-value"><?php echo number_format($stats['tipos_ayuda']); ?></div>
                        <div class="stat-card-label">Tipos de Ayuda</div>
                        <div class="stat-card-change positive">
                            <i class="fas fa-gift"></i>
                            Disponibles
                        </div>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                        <i class="fas fa-gift"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Acciones rápidas -->
        <div class="quick-actions fade-in-up">
            <a href="asignaciones.php" class="quick-action">
                <i class="fas fa-plus-circle"></i>
                <h4>Nueva Asignación</h4>
                <p>Crear una nueva asignación de ayuda</p>
            </a>
            <a href="familias.php" class="quick-action">
                <i class="fas fa-user-plus"></i>
                <h4>Registrar Familia</h4>
                <p>Agregar nueva familia al sistema</p>
            </a>
            <a href="ayudas.php" class="quick-action">
                <i class="fas fa-boxes"></i>
                <h4>Gestionar Ayudas</h4>
                <p>Administrar tipos de ayuda</p>
            </a>
            <a href="reportes.php" class="quick-action">
                <i class="fas fa-chart-line"></i>
                <h4>Ver Reportes</h4>
                <p>Generar informes y estadísticas</p>
            </a>
        </div>

        <!-- Contenido principal -->
        <div class="content-grid">
            <!-- Últimas asignaciones -->
            <div class="table-container fade-in-up">
                <div class="table-header">
                    <h3><i class="fas fa-history me-2"></i>Últimas Asignaciones</h3>
                </div>
                <div class="table-responsive">
                    <?php if (!empty($ultimas_asignaciones)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Expediente</th>
                                <th>Beneficiario</th>
                                <th>Ayuda</th>
                                <th>Estado</th>
                                <th>Prioridad</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimas_asignaciones as $asignacion): ?>
                            <tr>
                                <td>
                                    <code class="text-muted"><?php echo htmlspecialchars($asignacion['numero_expediente'] ?? 'N/A'); ?></code>
                                </td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($asignacion['beneficiario']); ?></strong>
                                        <?php if (!empty($asignacion['apellido_beneficiario'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($asignacion['apellido_beneficiario']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <?php echo htmlspecialchars($asignacion['nombre_ayuda']); ?>
                                        <br><small class="text-muted">Cant: <?php echo number_format($asignacion['cantidad'], 2); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $asignacion['estado']; ?>">
                                        <?php echo ucfirst($asignacion['estado']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $asignacion['prioridad']; ?>">
                                        <?php echo ucfirst($asignacion['prioridad']); ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo formatearFecha($asignacion['fecha_asignacion']); ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>No hay asignaciones recientes</h4>
                        <p>Las asignaciones aparecerán aquí una vez que se registren</p>
                        <a href="asignaciones.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>
                            Crear Primera Asignación
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Panel lateral -->
            <div class="fade-in-up">
                <!-- Top ayudas más solicitadas -->
                <div class="table-container mb-4">
                    <div class="table-header">
                        <h3><i class="fas fa-trophy me-2"></i>Ayudas Más Solicitadas</h3>
                    </div>
                    <div class="p-3">
                        <?php if (!empty($top_ayudas)): ?>
                            <?php foreach ($top_ayudas as $index => $ayuda): ?>
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3">
                                    <span class="badge bg-primary rounded-pill"><?php echo $index + 1; ?></span>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?></div>
                                    <small class="text-muted"><?php echo $ayuda['cantidad']; ?> solicitudes</small>
                                    <div class="progress mt-1">
                                        <?php $percentage = ($ayuda['cantidad'] / $top_ayudas[0]['cantidad']) * 100; ?>
                                        <div class="progress-bar bg-primary" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-chart-bar fa-2x mb-2 opacity-50"></i>
                                <p class="mb-0">No hay datos suficientes</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Distribución por prioridad -->
                <div class="table-container mb-4">
                    <div class="table-header">
                        <h3><i class="fas fa-exclamation-triangle me-2"></i>Por Prioridad</h3>
                    </div>
                    <div class="p-3">
                        <?php 
                        $prioridades = ['urgente' => 'Urgente', 'alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];
                        $total_prioridad = array_sum($asignaciones_prioridad);
                        ?>
                        <?php foreach ($prioridades as $key => $label): ?>
                            <?php $cantidad = $asignaciones_prioridad[$key] ?? 0; ?>
                            <?php if ($cantidad > 0): ?>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="d-flex align-items-center">
                                    <span class="badge badge-<?php echo $key; ?> me-2"><?php echo $label; ?></span>
                                </div>
                                <div class="text-end">
                                    <strong><?php echo $cantidad; ?></strong>
                                    <small class="text-muted">
                                        (<?php echo $total_prioridad > 0 ? round(($cantidad / $total_prioridad) * 100, 1) : 0; ?>%)
                                    </small>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <?php if ($total_prioridad == 0): ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-info-circle fa-2x mb-2 opacity-50"></i>
                                <p class="mb-0">No hay asignaciones recientes</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Actividad reciente -->
                <?php if (!empty($actividad_reciente)): ?>
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-clock me-2"></i>Actividad Reciente</h3>
                    </div>
                    <div class="p-3">
                        <?php foreach (array_slice($actividad_reciente, 0, 5) as $actividad): ?>
                        <div class="d-flex align-items-start mb-3">
                            <div class="me-3 mt-1">
                                <?php
                                $iconos = [
                                    'crear' => 'fas fa-plus text-success',
                                    'actualizar' => 'fas fa-edit text-warning',
                                    'eliminar' => 'fas fa-trash text-danger',
                                    'login' => 'fas fa-sign-in-alt text-info',
                                    'autorizar' => 'fas fa-check text-primary',
                                    'entregar' => 'fas fa-hand-holding text-success'
                                ];
                                $icono = $iconos[$actividad['accion']] ?? 'fas fa-circle text-secondary';
                                ?>
                                <i class="<?php echo $icono; ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold mb-1">
                                    <?php echo htmlspecialchars($actividad['descripcion']); ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($actividad['usuario_nombre'] . ' ' . $actividad['usuario_apellido']); ?>
                                    • <?php echo formatearFechaHora($actividad['fecha_actividad']); ?>
                                </small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Resumen estadístico adicional -->
        <div class="row fade-in-up">
            <div class="col-md-6">
                <div class="stat-card">
                    <h4 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Resumen de Estados</h4>
                    <div class="row text-center">
                        <div class="col-3">
                            <div class="fw-bold text-warning"><?php echo $stats['asignaciones_pendientes']; ?></div>
                            <small class="text-muted">Pendientes</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-info"><?php echo $stats['asignaciones_autorizadas']; ?></div>
                            <small class="text-muted">Autorizadas</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-success"><?php echo $stats['asignaciones_entregadas']; ?></div>
                            <small class="text-muted">Entregadas</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-danger"><?php echo $stats['asignaciones_canceladas']; ?></div>
                            <small class="text-muted">Canceladas</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <h4 class="mb-3"><i class="fas fa-users me-2"></i>Cobertura del Sistema</h4>
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="fw-bold text-primary"><?php echo $stats['familias_activas']; ?></div>
                            <small class="text-muted">Familias Activas</small>
                        </div>
                        <div class="col-6">
                            <div class="fw-bold text-success"><?php echo $stats['total_personas']; ?></div>
                            <small class="text-muted">Personas Registradas</small>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            Promedio: <?php echo $stats['familias_activas'] > 0 ? round($stats['total_personas'] / $stats['familias_activas'], 1) : 0; ?> personas por familia
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para gestión de usuarios (solo admin) -->
    <?php if ($user_role === 'admin'): ?>
    <div class="modal fade" id="usuariosModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Crear Nuevo Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="dashboard.php">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="nombre" class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="apellido" class="form-label">Apellido *</label>
                                    <input type="text" class="form-control" id="apellido" name="apellido" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="usuario" class="form-label">Usuario *</label>
                                    <input type="text" class="form-control" id="usuario" name="usuario" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="contraseña" class="form-label">Contraseña *</label>
                                    <input type="password" class="form-control" id="contraseña" name="contraseña" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="rol" class="form-label">Rol *</label>
                                    <select class="form-select" id="rol" name="rol" required>
                                        <option value="empleado">Empleado</option>
                                        <option value="supervisor">Supervisor</option>
                                        <option value="admin">Administrador</option>
                                        <option value="consulta">Solo Consulta</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" name="agregar_usuario" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Crear Usuario
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Animaciones de números incrementales
        document.addEventListener('DOMContentLoaded', function() {
            const counters = document.querySelectorAll('.stat-card-value');
            const speed = 200;

            counters.forEach(counter => {
                const animate = () => {
                    const value = +counter.getAttribute('data-value') || +counter.innerText.replace(/,/g, '');
                    const data = +counter.innerText.replace(/,/g, '');

                    const time = value / speed;
                    if (data < value) {
                        counter.innerText = Math.ceil(data + time).toLocaleString();
                        setTimeout(animate, 1);
                    } else {
                        counter.innerText = value.toLocaleString();
                    }
                };
                
                // Iniciar animación cuando el elemento esté visible
                const observer = new IntersectionObserver(entries => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            animate();
                            observer.unobserve(entry.target);
                        }
                    });
                });
                
                observer.observe(counter);
            });

            // Auto-refresh cada 5 minutos para estadísticas en tiempo real
            setTimeout(() => {
                location.reload();
            }, 300000);
        });

        // Función para actualizar estadísticas sin recargar la página
        function actualizarEstadisticas() {
            fetch('api/estadisticas.php')
                .then(response => response.json())
                .then(data => {
                    // Actualizar valores en tiempo real
                    document.querySelectorAll('[data-stat]').forEach(element => {
                        const stat = element.getAttribute('data-stat');
                        if (data[stat] !== undefined) {
                            element.textContent = data[stat].toLocaleString();
                        }
                    });
                })
                .catch(error => console.log('Error actualizando estadísticas:', error));
        }

        // Actualizar cada 2 minutos
        setInterval(actualizarEstadisticas, 120000);
    </script>
</body>
</html>