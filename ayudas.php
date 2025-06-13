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

// Recuperar mensajes de sesión
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    unset($_SESSION['mensaje']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Manejo de formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'agregar':
            case 'editar':
                $ayuda_id = $_POST['ayuda_id'] ?? null;
                $nombre_ayuda = limpiarEntrada($_POST['nombre_ayuda']);
                $descripcion = limpiarEntrada($_POST['descripcion']);

                if (empty($nombre_ayuda)) {
                    $_SESSION['error'] = "El nombre de la ayuda es obligatorio.";
                } else {
                    try {
                        // Verificar si el nombre ya existe
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM ayudas WHERE nombre_ayuda = ? AND id != ?");
                        $stmt_check->execute([$nombre_ayuda, $ayuda_id ?? 0]);
                        
                        if ($stmt_check->fetchColumn() > 0) {
                            $_SESSION['error'] = "Ya existe una ayuda con ese nombre.";
                        } else {
                            if ($ayuda_id) {
                                // Editar ayuda
                                $stmt = $pdo->prepare("
                                    UPDATE ayudas SET 
                                        nombre_ayuda = ?, descripcion = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$nombre_ayuda, $descripcion, $ayuda_id]);
                                
                                if (function_exists('registrarLog')) {
                                    registrarLog($pdo, 'ayudas', $ayuda_id, 'actualizar', 
                                        "Ayuda actualizada: $nombre_ayuda", $_SESSION['user_id']);
                                }
                                
                                $_SESSION['mensaje'] = "Ayuda actualizada correctamente.";
                            } else {
                                // Agregar nueva ayuda
                                $stmt = $pdo->prepare("
                                    INSERT INTO ayudas (nombre_ayuda, descripcion) 
                                    VALUES (?, ?)
                                ");
                                $stmt->execute([$nombre_ayuda, $descripcion]);
                                
                                $nuevo_id = $pdo->lastInsertId();
                                if (function_exists('registrarLog')) {
                                    registrarLog($pdo, 'ayudas', $nuevo_id, 'crear', 
                                        "Nueva ayuda creada: $nombre_ayuda", $_SESSION['user_id']);
                                }
                                
                                $_SESSION['mensaje'] = "Ayuda registrada correctamente.";
                            }
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
                    }
                }
                break;
                
            case 'eliminar':
                if ($user_role === 'admin') {
                    $ayuda_id = $_POST['ayuda_id'];
                    try {
                        // Verificar si hay asignaciones asociadas
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM asignaciones WHERE id_ayuda = ?");
                        $stmt_check->execute([$ayuda_id]);
                        
                        if ($stmt_check->fetchColumn() > 0) {
                            $_SESSION['error'] = "No se puede eliminar esta ayuda porque tiene asignaciones asociadas.";
                        } else {
                            // Marcar como inactiva en lugar de eliminar
                            $stmt = $pdo->prepare("UPDATE ayudas SET activo = 0 WHERE id = ?");
                            $stmt->execute([$ayuda_id]);
                            
                            if (function_exists('registrarLog')) {
                                registrarLog($pdo, 'ayudas', $ayuda_id, 'eliminar', 
                                    "Ayuda desactivada", $_SESSION['user_id']);
                            }
                            
                            $_SESSION['mensaje'] = "Ayuda desactivada correctamente.";
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error al eliminar ayuda: " . $e->getMessage();
                    }
                } else {
                    $_SESSION['error'] = "No tiene permisos para realizar esta acción.";
                }
                break;
                
            case 'activar':
                if ($user_role === 'admin') {
                    $ayuda_id = $_POST['ayuda_id'];
                    try {
                        $stmt = $pdo->prepare("UPDATE ayudas SET activo = 1 WHERE id = ?");
                        $stmt->execute([$ayuda_id]);
                        
                        if (function_exists('registrarLog')) {
                            registrarLog($pdo, 'ayudas', $ayuda_id, 'actualizar', 
                                "Ayuda reactivada", $_SESSION['user_id']);
                        }
                        
                        $_SESSION['mensaje'] = "Ayuda reactivada correctamente.";
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error al reactivar ayuda: " . $e->getMessage();
                    }
                }
                break;
        }
    }
    header("Location: ayudas.php");
    exit();
}

// Obtener ayudas con paginación y búsqueda
$page = (int)($_GET['page'] ?? 1);
$limit = 12;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$estado_filter = $_GET['estado'] ?? 'activo';

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

try {
    // Contar total de registros
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ayudas $where_clause");
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    
    // Obtener ayudas con estadísticas de uso
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id), 0
            ) as total_asignaciones,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'entregada'), 0
            ) as asignaciones_entregadas,
            COALESCE(
                (SELECT MAX(asg.fecha_asignacion) FROM asignaciones asg WHERE asg.id_ayuda = a.id), NULL
            ) as ultima_asignacion
        FROM ayudas a
        $where_clause
        ORDER BY a.fecha_registro DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $ayudas = $stmt->fetchAll();
    
    // Estadísticas rápidas
    $stats = [];
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activas,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as inactivas,
            (SELECT COUNT(*) FROM asignaciones) as total_asignaciones_sistema
        FROM ayudas
    ");
    $stats = $stmt_stats->fetch();
    
    // Top 5 ayudas más utilizadas
    $stmt_top = $pdo->query("
        SELECT 
            a.nombre_ayuda,
            COUNT(asg.id) as cantidad_asignaciones
        FROM ayudas a
        LEFT JOIN asignaciones asg ON a.id = asg.id_ayuda
        WHERE a.activo = 1
        GROUP BY a.id, a.nombre_ayuda
        HAVING cantidad_asignaciones > 0
        ORDER BY cantidad_asignaciones DESC
        LIMIT 5
    ");
    $top_ayudas = $stmt_top->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error al cargar ayudas: " . $e->getMessage();
    $ayudas = [];
    $stats = ['total' => 0, 'activas' => 0, 'inactivas' => 0, 'total_asignaciones_sistema' => 0];
    $top_ayudas = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Tipos de Ayuda - Sistema de Desarrollo Social</title>
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

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
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
            height: 3px;
            background: linear-gradient(90deg, var(--primary-color), var(--info-color));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card:hover::before {
            transform: scaleX(1);
        }

        .stat-card-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-card-info h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .stat-card-info p {
            color: var(--secondary-color);
            margin: 0;
            font-size: 0.9rem;
        }

        .stat-card-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }

        /* Content Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1200px) {
            .content-layout {
                grid-template-columns: 1fr;
            }
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

        .search-box {
            position: relative;
        }

        .search-box .form-control {
            padding-left: 2.5rem;
            border-radius: 10px;
            border: 2px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .search-box .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .search-box .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-color);
            z-index: 2;
        }

        /* Ayudas Grid */
        .ayudas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .ayuda-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
        }

        .ayuda-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .ayuda-card.inactive {
            opacity: 0.7;
            border-left: 4px solid var(--warning-color);
        }

        .ayuda-card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        .ayuda-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .ayuda-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }

        .ayuda-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .ayuda-card-body {
            padding: 1.5rem;
        }

        .ayuda-description {
            color: var(--secondary-color);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .ayuda-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .ayuda-stat {
            text-align: center;
        }

        .ayuda-stat-number {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-color);
            display: block;
        }

        .ayuda-stat-label {
            font-size: 0.8rem;
            color: var(--secondary-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .ayuda-card-actions {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-edit {
            background: #fef3c7;
            color: #92400e;
        }

        .btn-edit:hover {
            background: #fde68a;
            transform: scale(1.1);
        }

        .btn-view {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-view:hover {
            background: #bfdbfe;
            transform: scale(1.1);
        }

        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-delete:hover {
            background: #fecaca;
            transform: scale(1.1);
        }

        .btn-activate {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-activate:hover {
            background: #a7f3d0;
            transform: scale(1.1);
        }

        /* Sidebar Panel */
        .sidebar-panel {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            height: fit-content;
        }

        .sidebar-panel-header {
            background: var(--primary-color);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }

        .sidebar-panel-body {
            padding: 1.5rem;
        }

        .top-ayudas-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .top-ayuda-item {
            display: flex;
            align-items: center;
            justify-content: between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .top-ayuda-item:last-child {
            border-bottom: none;
        }

        .top-ayuda-rank {
            width: 30px;
            height: 30px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            margin-right: 1rem;
        }

        .top-ayuda-info {
            flex-grow: 1;
        }

        .top-ayuda-name {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .top-ayuda-count {
            font-size: 0.8rem;
            color: var(--secondary-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--secondary-color);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--dark-color);
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 2rem;
        }

        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-top: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        .pagination {
            margin: 0;
        }

        .page-link {
            border: none;
            color: var(--secondary-color);
            padding: 0.5rem 0.75rem;
            margin: 0 0.125rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-1px);
        }

        .page-item.active .page-link {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 1.5rem;
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .fab:hover {
            transform: scale(1.1) translateY(-2px);
            box-shadow: 0 12px 30px rgba(37, 99, 235, 0.5);
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

        .fade-in-up:nth-child(odd) { animation-delay: 0.1s; }
        .fade-in-up:nth-child(even) { animation-delay: 0.2s; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <h1><i class="fas fa-gift me-3"></i>Gestión de Tipos de Ayuda</h1>
            <p>Administración del catálogo de ayudas disponibles en el sistema</p>
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

        <!-- Statistics Row -->
        <div class="stats-row fade-in-up">
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['total']); ?></h3>
                        <p>Total Tipos de Ayuda</p>
                    </div>
                    <div class="stat-card-icon">
                        <i class="fas fa-gift"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['activas']); ?></h3>
                        <p>Ayudas Activas</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--success-color), #047857);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['inactivas']); ?></h3>
                        <p>Ayudas Inactivas</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--warning-color), #c2410c);">
                        <i class="fas fa-pause-circle"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['total_asignaciones_sistema']); ?></h3>
                        <p>Asignaciones Totales</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--info-color), #0e7490);">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section fade-in-up">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <div class="search-box">
                        <input type="text" 
                               class="form-control" 
                               name="search" 
                               placeholder="Buscar por nombre o descripción..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="estado">
                        <option value="activo" <?php echo $estado_filter === 'activo' ? 'selected' : ''; ?>>Ayudas Activas</option>
                        <option value="inactivo" <?php echo $estado_filter === 'inactivo' ? 'selected' : ''; ?>>Ayudas Inactivas</option>
                        <option value="todos" <?php echo $estado_filter === 'todos' ? 'selected' : ''; ?>>Todas las Ayudas</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-search me-2"></i>Buscar
                        </button>
                        <a href="ayudas.php" class="btn btn-outline-secondary">
                            <i class="fas fa-refresh"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-success w-100" onclick="exportarAyudas()">
                        <i class="fas fa-download me-2"></i>Exportar
                    </button>
                </div>
            </form>
        </div>

        <!-- Content Layout -->
        <div class="content-layout fade-in-up">
            <!-- Main Content - Ayudas Grid -->
            <div>
                <?php if (!empty($ayudas)): ?>
                <div class="ayudas-grid">
                    <?php foreach ($ayudas as $ayuda): ?>
                    <div class="ayuda-card <?php echo $ayuda['activo'] ? '' : 'inactive'; ?> fade-in-up">
                        <div class="ayuda-card-header">
                            <div class="ayuda-status">
                                <span class="status-badge <?php echo $ayuda['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $ayuda['activo'] ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </div>
                            <div class="ayuda-icon">
                                <?php
                                // Íconos basados en el tipo de ayuda
                                $iconos = [
                                    'chapa' => 'fa-home',
                                    'cemento' => 'fa-cube',
                                    'ladrillo' => 'fa-th-large',
                                    'caño' => 'fa-tools',
                                    'alimentario' => 'fa-apple-alt',
                                    'leche' => 'fa-baby',
                                    'calzado' => 'fa-shoe-prints',
                                    'ropa' => 'fa-tshirt',
                                    'medicamento' => 'fa-pills',
                                    'escolar' => 'fa-graduation-cap'
                                ];
                                
                                $icono = 'fa-gift'; // Icono por defecto
                                foreach ($iconos as $palabra => $icon) {
                                    if (stripos($ayuda['nombre_ayuda'], $palabra) !== false) {
                                        $icono = $icon;
                                        break;
                                    }
                                }
                                ?>
                                <i class="fas <?php echo $icono; ?>"></i>
                            </div>
                            <div class="ayuda-name">
                                <?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?>
                            </div>
                        </div>

                        <div class="ayuda-card-body">
                            <div class="ayuda-description">
                                <?php if (!empty($ayuda['descripcion'])): ?>
                                    <?php echo htmlspecialchars($ayuda['descripcion']); ?>
                                <?php else: ?>
                                    <em class="text-muted">Sin descripción disponible</em>
                                <?php endif; ?>
                            </div>

                            <div class="ayuda-stats">
                                <div class="ayuda-stat">
                                    <span class="ayuda-stat-number"><?php echo $ayuda['total_asignaciones']; ?></span>
                                    <span class="ayuda-stat-label">Asignaciones</span>
                                </div>
                                <div class="ayuda-stat">
                                    <span class="ayuda-stat-number"><?php echo $ayuda['asignaciones_entregadas']; ?></span>
                                    <span class="ayuda-stat-label">Entregadas</span>
                                </div>
                            </div>

                            <?php if ($ayuda['ultima_asignacion']): ?>
                            <div class="mt-2 pt-2 border-top">
                                <small class="text-muted">
                                    <i class="fas fa-clock me-1"></i>
                                    Última asignación: <?php echo formatearFecha($ayuda['ultima_asignacion']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="ayuda-card-actions">
                            <button class="btn-action btn-view" 
                                    onclick="verDetalleAyuda(<?php echo $ayuda['id']; ?>)"
                                    title="Ver detalle">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-action btn-edit" 
                                    onclick="editarAyuda(<?php echo $ayuda['id']; ?>)"
                                    title="Editar ayuda">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($ayuda['total_asignaciones'] > 0): ?>
                            <a href="asignaciones.php?search=<?php echo urlencode($ayuda['nombre_ayuda']); ?>" 
                               class="btn-action btn-view" 
                               title="Ver asignaciones">
                                <i class="fas fa-list"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($user_role === 'admin'): ?>
                                <?php if ($ayuda['activo']): ?>
                                <button class="btn-action btn-delete" 
                                        onclick="desactivarAyuda(<?php echo $ayuda['id']; ?>, '<?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?>')"
                                        title="Desactivar ayuda">
                                    <i class="fas fa-pause"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn-action btn-activate" 
                                        onclick="activarAyuda(<?php echo $ayuda['id']; ?>, '<?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?>')"
                                        title="Reactivar ayuda">
                                    <i class="fas fa-play"></i>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-gift"></i>
                    <h3>No se encontraron tipos de ayuda</h3>
                    <p>No hay tipos de ayuda registrados que coincidan con los criterios de búsqueda</p>
                    <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#ayudaModal">
                        <i class="fas fa-plus me-2"></i>Crear Primer Tipo de Ayuda
                    </button>
                </div>
                <?php endif; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="d-flex justify-content-between align-items-center w-100">
                        <div class="text-muted">
                            Mostrando <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> 
                            de <?php echo number_format($total_records); ?> tipos de ayuda
                        </div>
                        
                        <nav>
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar Panel -->
            <div class="sidebar-panel">
                <div class="sidebar-panel-header">
                    <i class="fas fa-trophy me-2"></i>
                    Ayudas Más Utilizadas
                </div>
                <div class="sidebar-panel-body">
                    <?php if (!empty($top_ayudas)): ?>
                        <ul class="top-ayudas-list">
                            <?php foreach ($top_ayudas as $index => $top_ayuda): ?>
                            <li class="top-ayuda-item">
                                <div class="top-ayuda-rank">
                                    <?php echo $index + 1; ?>
                                </div>
                                <div class="top-ayuda-info">
                                    <div class="top-ayuda-name">
                                        <?php echo htmlspecialchars($top_ayuda['nombre_ayuda']); ?>
                                    </div>
                                    <div class="top-ayuda-count">
                                        <?php echo $top_ayuda['cantidad_asignaciones']; ?> asignaciones
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-chart-bar fa-3x mb-3 opacity-50"></i>
                            <p class="mb-0">No hay estadísticas de uso disponibles</p>
                            <small>Las estadísticas aparecerán cuando se realicen asignaciones</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button class="fab" data-bs-toggle="modal" data-bs-target="#ayudaModal" title="Agregar nuevo tipo de ayuda">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Modal para agregar/editar ayuda -->
    <div class="modal fade" id="ayudaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-gift me-2"></i>
                        <span id="modalTitle">Nuevo Tipo de Ayuda</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="ayudaForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="agregar">
                        <input type="hidden" name="ayuda_id" id="ayuda_id">
                        
                        <div class="mb-3">
                            <label for="nombre_ayuda" class="form-label">Nombre del Tipo de Ayuda *</label>
                            <input type="text" class="form-control" id="nombre_ayuda" name="nombre_ayuda" required
                                   placeholder="Ej: Chapas de Zinc, Bolsón Alimentario, etc.">
                            <div class="form-text">Ingrese un nombre descriptivo y único para este tipo de ayuda</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="4"
                                      placeholder="Describa las características, especificaciones o detalles importantes de esta ayuda..."></textarea>
                            <div class="form-text">Información adicional que ayude a identificar y caracterizar esta ayuda</div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Importante:</strong> Una vez creado, este tipo de ayuda estará disponible para ser asignado a familias desde el módulo de asignaciones.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <span id="btnSubmitText">Guardar Tipo de Ayuda</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de detalle de ayuda -->
    <div class="modal fade" id="detalleAyudaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>
                        Detalle del Tipo de Ayuda
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleAyudaContent">
                    <!-- Contenido cargado dinámicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="editarAyudaDesdeDetalle()">
                        <i class="fas fa-edit me-2"></i>Editar Ayuda
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para desactivar -->
    <div class="modal fade" id="desactivarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirmar Desactivación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea desactivar el tipo de ayuda <strong id="ayudaDesactivar"></strong>?</p>
                    <p class="text-muted small">La ayuda se mantendrá en el sistema pero no estará disponible para nuevas asignaciones. Las asignaciones existentes no se verán afectadas.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="ayuda_id" id="ayudaIdDesactivar">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-pause me-2"></i>Desactivar Ayuda
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para activar -->
    <div class="modal fade" id="activarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Confirmar Reactivación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea reactivar el tipo de ayuda <strong id="ayudaActivar"></strong>?</p>
                    <p class="text-muted small">La ayuda volverá a estar disponible para nuevas asignaciones.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="activar">
                        <input type="hidden" name="ayuda_id" id="ayudaIdActivar">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-play me-2"></i>Reactivar Ayuda
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let ayudaIdActual = null;

        // Auto-capitalizar nombres
        document.addEventListener('DOMContentLoaded', function() {
            const nombreInput = document.getElementById('nombre_ayuda');
            if (nombreInput) {
                nombreInput.addEventListener('input', function() {
                    this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
                });
            }
        });

        function editarAyuda(id) {
            ayudaIdActual = id;
            
            // Hacer petición AJAX para obtener datos de la ayuda
            fetch(`api/ayuda.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const ayuda = data.ayuda;
                        
                        document.getElementById('modalTitle').textContent = 'Editar Tipo de Ayuda';
                        document.getElementById('btnSubmitText').textContent = 'Actualizar Ayuda';
                        document.getElementById('accion').value = 'editar';
                        document.getElementById('ayuda_id').value = ayuda.id;
                        
                        document.getElementById('nombre_ayuda').value = ayuda.nombre_ayuda || '';
                        document.getElementById('descripcion').value = ayuda.descripcion || '';
                        
                        new bootstrap.Modal(document.getElementById('ayudaModal')).show();
                    } else {
                        alert('Error al cargar los datos de la ayuda');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar los datos');
                });
        }

        function verDetalleAyuda(id) {
            // Cargar detalle de ayuda
            document.getElementById('detalleAyudaContent').innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br><br>Cargando detalle...</div>';
            new bootstrap.Modal(document.getElementById('detalleAyudaModal')).show();
            
            fetch(`api/ayuda_detalle.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('detalleAyudaContent').innerHTML = data.html;
                        ayudaIdActual = id;
                    } else {
                        document.getElementById('detalleAyudaContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error al cargar el detalle: ${data.error}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('detalleAyudaContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error de conexión al cargar el detalle.
                        </div>
                    `;
                });
        }

        function editarAyudaDesdeDetalle() {
            if (ayudaIdActual) {
                bootstrap.Modal.getInstance(document.getElementById('detalleAyudaModal')).hide();
                setTimeout(() => {
                    editarAyuda(ayudaIdActual);
                }, 300);
            }
        }

        function desactivarAyuda(id, nombre) {
            document.getElementById('ayudaDesactivar').textContent = nombre;
            document.getElementById('ayudaIdDesactivar').value = id;
            new bootstrap.Modal(document.getElementById('desactivarModal')).show();
        }

        function activarAyuda(id, nombre) {
            document.getElementById('ayudaActivar').textContent = nombre;
            document.getElementById('ayudaIdActivar').value = id;
            new bootstrap.Modal(document.getElementById('activarModal')).show();
        }

        function exportarAyudas() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.open(`api/export_ayudas.php?${params.toString()}`, '_blank');
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('ayudaModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('ayudaForm').reset();
            document.getElementById('modalTitle').textContent = 'Nuevo Tipo de Ayuda';
            document.getElementById('btnSubmitText').textContent = 'Guardar Tipo de Ayuda';
            document.getElementById('accion').value = 'agregar';
            document.getElementById('ayuda_id').value = '';
            ayudaIdActual = null;
        });

        // Búsqueda en tiempo real
        let searchTimeout;
        document.querySelector('input[name="search"]').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });

        // Animaciones de contadores
        document.addEventListener('DOMContentLoaded', function() {
            const counters = document.querySelectorAll('.stat-card-info h3, .ayuda-stat-number');
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
    </script>
</body>
</html>