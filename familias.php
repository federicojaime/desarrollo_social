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

// Manejo de formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'agregar':
            case 'editar':
                $familia_id = $_POST['familia_id'] ?? null;
                $nombre_jefe = limpiarEntrada($_POST['nombre_jefe']);
                $apellido_jefe = limpiarEntrada($_POST['apellido_jefe']);
                $dni_jefe = limpiarEntrada($_POST['dni_jefe']);
                $telefono = limpiarEntrada($_POST['telefono']);
                $direccion = limpiarEntrada($_POST['direccion']);
                $barrio = limpiarEntrada($_POST['barrio']);
                $cantidad_integrantes = (int)$_POST['cantidad_integrantes'];

                if (empty($nombre_jefe) || empty($apellido_jefe) || empty($dni_jefe)) {
                    $_SESSION['error'] = "Nombre, apellido y DNI del jefe de familia son obligatorios.";
                } elseif (!preg_match('/^\d{7,8}$/', $dni_jefe)) {
                    $_SESSION['error'] = "El DNI debe tener entre 7 y 8 dígitos.";
                } else {
                    try {
                        // Verificar DNI duplicado
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM familias WHERE dni_jefe = ? AND id != ?");
                        $stmt_check->execute([$dni_jefe, $familia_id ?? 0]);
                        
                        if ($stmt_check->fetchColumn() > 0) {
                            $_SESSION['error'] = "Ya existe una familia registrada con ese DNI.";
                        } else {
                            if ($familia_id) {
                                // Editar
                                $stmt = $pdo->prepare("
                                    UPDATE familias SET 
                                        nombre_jefe = ?, apellido_jefe = ?, dni_jefe = ?, 
                                        telefono = ?, direccion = ?, barrio = ?, cantidad_integrantes = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([
                                    $nombre_jefe, $apellido_jefe, $dni_jefe, 
                                    $telefono, $direccion, $barrio, $cantidad_integrantes, $familia_id
                                ]);
                                
                                if (function_exists('registrarLog')) {
                                    registrarLog($pdo, 'familias', $familia_id, 'actualizar', 
                                        "Familia actualizada: $nombre_jefe $apellido_jefe", $_SESSION['user_id']);
                                }
                                
                                $_SESSION['mensaje'] = "Familia actualizada correctamente.";
                            } else {
                                // Agregar
                                $stmt = $pdo->prepare("
                                    INSERT INTO familias (nombre_jefe, apellido_jefe, dni_jefe, telefono, direccion, barrio, cantidad_integrantes) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $nombre_jefe, $apellido_jefe, $dni_jefe, 
                                    $telefono, $direccion, $barrio, $cantidad_integrantes
                                ]);
                                
                                $nuevo_id = $pdo->lastInsertId();
                                if (function_exists('registrarLog')) {
                                    registrarLog($pdo, 'familias', $nuevo_id, 'crear', 
                                        "Nueva familia registrada: $nombre_jefe $apellido_jefe", $_SESSION['user_id']);
                                }
                                
                                $_SESSION['mensaje'] = "Familia registrada correctamente.";
                            }
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
                    }
                }
                break;
                
            case 'eliminar':
                if ($user_role === 'admin') {
                    $familia_id = $_POST['familia_id'];
                    try {
                        // Verificar si tiene asignaciones
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM asignaciones WHERE familia_id = ?");
                        $stmt_check->execute([$familia_id]);
                        
                        if ($stmt_check->fetchColumn() > 0) {
                            $_SESSION['error'] = "No se puede eliminar la familia porque tiene asignaciones asociadas.";
                        } else {
                            $stmt = $pdo->prepare("UPDATE familias SET estado = 'inactiva' WHERE id = ?");
                            $stmt->execute([$familia_id]);
                            
                            if (function_exists('registrarLog')) {
                                registrarLog($pdo, 'familias', $familia_id, 'eliminar', 
                                    "Familia desactivada", $_SESSION['user_id']);
                            }
                            
                            $_SESSION['mensaje'] = "Familia desactivada correctamente.";
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error al eliminar familia: " . $e->getMessage();
                    }
                } else {
                    $_SESSION['error'] = "No tiene permisos para realizar esta acción.";
                }
                break;
        }
    }
    header("Location: familias.php");
    exit();
}

// Obtener familias con paginación y búsqueda
$page = (int)($_GET['page'] ?? 1);
$limit = 12;  // Mostrar 12 familias por página para mejor vista en cards
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$estado_filter = $_GET['estado'] ?? 'activa';
$barrio_filter = $_GET['barrio'] ?? '';

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

try {
    // Contar total de registros
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM familias $where_clause");
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    
    // Obtener familias
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
            ) as asignaciones_pendientes
        FROM familias f 
        $where_clause
        ORDER BY f.fecha_registro DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $familias = $stmt->fetchAll();
    
    // Obtener barrios para el filtro
    $stmt_barrios = $pdo->query("
        SELECT DISTINCT barrio 
        FROM familias 
        WHERE barrio IS NOT NULL AND barrio != '' AND estado = 'activa'
        ORDER BY barrio
    ");
    $barrios = $stmt_barrios->fetchAll();
    
    // Estadísticas rápidas
    $stats = [];
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado = 'activa' THEN 1 ELSE 0 END) as activas,
            SUM(CASE WHEN estado = 'inactiva' THEN 1 ELSE 0 END) as inactivas,
            SUM(cantidad_integrantes) as total_integrantes,
            AVG(cantidad_integrantes) as promedio_integrantes
        FROM familias
    ");
    $stats = $stmt_stats->fetch();
    
} catch (PDOException $e) {
    $error = "Error al cargar familias: " . $e->getMessage();
    $familias = [];
    $barrios = [];
    $stats = ['total' => 0, 'activas' => 0, 'inactivas' => 0, 'total_integrantes' => 0, 'promedio_integrantes' => 0];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Familias - Sistema de Desarrollo Social</title>
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

        /* Search and Filters */
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

        /* Family Cards */
        .families-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .family-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
        }

        .family-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .family-card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        .family-avatar {
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

        .family-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .family-dni {
            font-size: 0.9rem;
            color: var(--secondary-color);
            font-family: 'Monaco', 'Menlo', monospace;
        }

        .family-card-body {
            padding: 1.5rem;
        }

        .family-info-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
        }

        .family-info-item i {
            width: 20px;
            color: var(--secondary-color);
            margin-right: 0.75rem;
        }

        .family-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .family-stat {
            text-align: center;
        }

        .family-stat-number {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-color);
            display: block;
        }

        .family-stat-label {
            font-size: 0.8rem;
            color: var(--secondary-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .family-card-actions {
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

        /* Badges */
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

        .priority-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-high {
            background: #fee2e2;
            color: #991b1b;
        }

        .priority-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .priority-low {
            background: #f0f9ff;
            color: #0369a1;
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
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <h1><i class="fas fa-users me-3"></i>Gestión de Familias</h1>
            <p>Administración integral de familias beneficiarias del sistema</p>
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
                        <p>Total Familias</p>
                    </div>
                    <div class="stat-card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['activas']); ?></h3>
                        <p>Familias Activas</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--success-color), #047857);">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['total_integrantes']); ?></h3>
                        <p>Total Integrantes</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--info-color), #0e7490);">
                        <i class="fas fa-user-friends"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['promedio_integrantes'], 1); ?></h3>
                        <p>Promedio por Familia</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--warning-color), #c2410c);">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section fade-in-up">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <div class="search-box">
                        <input type="text" 
                               class="form-control" 
                               name="search" 
                               placeholder="Buscar por nombre, DNI o dirección..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="estado">
                        <option value="activa" <?php echo $estado_filter === 'activa' ? 'selected' : ''; ?>>Activas</option>
                        <option value="inactiva" <?php echo $estado_filter === 'inactiva' ? 'selected' : ''; ?>>Inactivas</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="barrio">
                        <option value="">Todos los barrios</option>
                        <?php foreach ($barrios as $barrio): ?>
                            <option value="<?php echo htmlspecialchars($barrio['barrio']); ?>" 
                                    <?php echo $barrio_filter === $barrio['barrio'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($barrio['barrio']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-search me-2"></i>Buscar
                        </button>
                        <a href="familias.php" class="btn btn-outline-secondary">
                            <i class="fas fa-refresh"></i>
                        </a>
                        <button type="button" class="btn btn-outline-success" onclick="exportarFamilias()">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Families Grid -->
        <?php if (!empty($familias)): ?>
        <div class="families-grid">
            <?php foreach ($familias as $familia): ?>
            <div class="family-card fade-in-up">
                <div class="family-card-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="family-avatar">
                                <?php echo strtoupper(substr($familia['nombre_jefe'], 0, 1) . substr($familia['apellido_jefe'], 0, 1)); ?>
                            </div>
                            <div class="family-name">
                                <?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe']); ?>
                            </div>
                            <div class="family-dni">
                                DNI: <?php echo htmlspecialchars($familia['dni_jefe']); ?>
                            </div>
                        </div>
                        <div>
                            <span class="status-badge <?php echo $familia['estado'] === 'activa' ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo ucfirst($familia['estado']); ?>
                            </span>
                            <?php if ($familia['asignaciones_pendientes'] > 0): ?>
                                <div class="mt-2">
                                    <span class="priority-badge priority-high">
                                        <?php echo $familia['asignaciones_pendientes']; ?> pendiente<?php echo $familia['asignaciones_pendientes'] > 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="family-card-body">
                    <div class="family-info-item">
                        <i class="fas fa-phone"></i>
                        <span><?php echo !empty($familia['telefono']) ? htmlspecialchars($familia['telefono']) : 'Sin teléfono'; ?></span>
                    </div>
                    
                    <div class="family-info-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo !empty($familia['direccion']) ? htmlspecialchars($familia['direccion']) : 'Sin dirección'; ?></span>
                    </div>
                    
                    <div class="family-info-item">
                        <i class="fas fa-home"></i>
                        <span><?php echo !empty($familia['barrio']) ? htmlspecialchars($familia['barrio']) : 'Sin barrio'; ?></span>
                    </div>
                    
                    <div class="family-info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Registrado: <?php echo formatearFecha($familia['fecha_registro']); ?></span>
                    </div>

                    <div class="family-stats">
                        <div class="family-stat">
                            <span class="family-stat-number"><?php echo $familia['cantidad_integrantes']; ?></span>
                            <span class="family-stat-label">Integrantes</span>
                        </div>
                        <div class="family-stat">
                            <span class="family-stat-number"><?php echo $familia['total_asignaciones']; ?></span>
                            <span class="family-stat-label">Asignaciones</span>
                        </div>
                    </div>

                    <?php if ($familia['ultima_asignacion']): ?>
                    <div class="mt-2 pt-2 border-top">
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i>
                            Última asignación: <?php echo formatearFecha($familia['ultima_asignacion']); ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="family-card-actions">
                    <button class="btn-action btn-view" 
                            onclick="verDetalleFamilia(<?php echo $familia['id']; ?>)"
                            title="Ver detalle">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn-action btn-edit" 
                            onclick="editarFamilia(<?php echo $familia['id']; ?>)"
                            title="Editar familia">
                        <i class="fas fa-edit"></i>
                    </button>
                    <a href="asignaciones.php?familia=<?php echo $familia['id']; ?>" 
                       class="btn-action btn-view" 
                       title="Ver asignaciones">
                        <i class="fas fa-gift"></i>
                    </a>
                    <?php if ($user_role === 'admin'): ?>
                    <button class="btn-action btn-delete" 
                            onclick="eliminarFamilia(<?php echo $familia['id']; ?>, '<?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe']); ?>')"
                            title="Eliminar familia">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state fade-in-up">
            <i class="fas fa-users"></i>
            <h3>No se encontraron familias</h3>
            <p>No hay familias registradas que coincidan con los criterios de búsqueda</p>
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#familiaModal">
                <i class="fas fa-plus me-2"></i>Registrar Primera Familia
            </button>
        </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container fade-in-up">
            <div class="d-flex justify-content-between align-items-center w-100">
                <div class="text-muted">
                    Mostrando <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> 
                    de <?php echo number_format($total_records); ?> familias
                </div>
                
                <nav>
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>&barrio=<?php echo urlencode($barrio_filter); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>&barrio=<?php echo urlencode($barrio_filter); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>&barrio=<?php echo urlencode($barrio_filter); ?>">
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

    <!-- Floating Action Button -->
    <button class="fab" data-bs-toggle="modal" data-bs-target="#familiaModal" title="Agregar nueva familia">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Modal para agregar/editar familia -->
    <div class="modal fade" id="familiaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-users me-2"></i>
                        <span id="modalTitle">Nueva Familia</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="familiaForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="agregar">
                        <input type="hidden" name="familia_id" id="familia_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="nombre_jefe" class="form-label">Nombre del Jefe de Familia *</label>
                                    <input type="text" class="form-control" id="nombre_jefe" name="nombre_jefe" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="apellido_jefe" class="form-label">Apellido del Jefe de Familia *</label>
                                    <input type="text" class="form-control" id="apellido_jefe" name="apellido_jefe" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="dni_jefe" class="form-label">DNI *</label>
                                    <input type="text" class="form-control" id="dni_jefe" name="dni_jefe" 
                                           pattern="[0-9]{7,8}" maxlength="8" required>
                                    <div class="form-text">Ingrese DNI sin puntos ni espacios</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="telefono" class="form-label">Teléfono</label>
                                    <input type="tel" class="form-control" id="telefono" name="telefono">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="direccion" class="form-label">Dirección</label>
                                    <input type="text" class="form-control" id="direccion" name="direccion">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="barrio" class="form-label">Barrio</label>
                                    <input type="text" class="form-control" id="barrio" name="barrio">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="cantidad_integrantes" class="form-label">Cantidad de Integrantes *</label>
                                    <input type="number" class="form-control" id="cantidad_integrantes" 
                                           name="cantidad_integrantes" min="1" max="20" value="1" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <span id="btnSubmitText">Guardar Familia</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de detalle de familia -->
    <div class="modal fade" id="detalleFamiliaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle me-2"></i>
                        Detalle de Familia
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleFamiliaContent">
                    <!-- Contenido cargado dinámicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="editarFamiliaDesdeDetalle()">
                        <i class="fas fa-edit me-2"></i>Editar Familia
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para eliminar -->
    <div class="modal fade" id="eliminarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirmar Eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea desactivar la familia <strong id="familiaEliminar"></strong>?</p>
                    <p class="text-muted small">Esta acción cambiará el estado de la familia a "inactiva" pero conservará todos los registros históricos.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="familia_id" id="familiaIdEliminar">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Desactivar Familia
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let familiaIdActual = null;

        // Auto-capitalizar nombres
        document.addEventListener('DOMContentLoaded', function() {
            const campos = ['nombre_jefe', 'apellido_jefe', 'direccion', 'barrio'];
            campos.forEach(campo => {
                const input = document.getElementById(campo);
                if (input) {
                    input.addEventListener('input', function() {
                        this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
                    });
                }
            });
            
            // Solo números para DNI
            const dniInput = document.getElementById('dni_jefe');
            if (dniInput) {
                dniInput.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '');
                });
            }
        });

        function editarFamilia(id) {
            familiaIdActual = id;
            
            // Hacer petición AJAX para obtener datos de la familia
            fetch(`api/familia.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const familia = data.familia;
                        
                        document.getElementById('modalTitle').textContent = 'Editar Familia';
                        document.getElementById('btnSubmitText').textContent = 'Actualizar Familia';
                        document.getElementById('accion').value = 'editar';
                        document.getElementById('familia_id').value = familia.id;
                        
                        document.getElementById('nombre_jefe').value = familia.nombre_jefe || '';
                        document.getElementById('apellido_jefe').value = familia.apellido_jefe || '';
                        document.getElementById('dni_jefe').value = familia.dni_jefe || '';
                        document.getElementById('telefono').value = familia.telefono || '';
                        document.getElementById('direccion').value = familia.direccion || '';
                        document.getElementById('barrio').value = familia.barrio || '';
                        document.getElementById('cantidad_integrantes').value = familia.cantidad_integrantes || 1;
                        
                        new bootstrap.Modal(document.getElementById('familiaModal')).show();
                    } else {
                        alert('Error al cargar los datos de la familia');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar los datos');
                });
        }

        function verDetalleFamilia(id) {
            // Cargar detalle de familia
            fetch(`api/familia_detalle.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('detalleFamiliaContent').innerHTML = data.html;
                        familiaIdActual = id;
                        new bootstrap.Modal(document.getElementById('detalleFamiliaModal')).show();
                    } else {
                        alert('Error al cargar el detalle de la familia');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar el detalle');
                });
        }

        function editarFamiliaDesdeDetalle() {
            if (familiaIdActual) {
                bootstrap.Modal.getInstance(document.getElementById('detalleFamiliaModal')).hide();
                setTimeout(() => {
                    editarFamilia(familiaIdActual);
                }, 300);
            }
        }

        function eliminarFamilia(id, nombre) {
            document.getElementById('familiaEliminar').textContent = nombre;
            document.getElementById('familiaIdEliminar').value = id;
            new bootstrap.Modal(document.getElementById('eliminarModal')).show();
        }

        function exportarFamilias() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.open(`api/export_familias.php?${params.toString()}`, '_blank');
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('familiaModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('familiaForm').reset();
            document.getElementById('modalTitle').textContent = 'Nueva Familia';
            document.getElementById('btnSubmitText').textContent = 'Guardar Familia';
            document.getElementById('accion').value = 'agregar';
            document.getElementById('familia_id').value = '';
            familiaIdActual = null;
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
            const counters = document.querySelectorAll('.stat-card-info h3');
            counters.forEach(counter => {
                const target = parseInt(counter.textContent.replace(/,/g, ''));
                let current = 0;
                const increment = target / 100;
                const timer = setInterval(function() {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    counter.textContent = Math.floor(current).toLocaleString();
                }, 20);
            });
        });
    </script>
</body>
</html>