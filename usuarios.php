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

// Solo admin puede acceder a esta página
if ($user_role !== 'admin') {
    $_SESSION['error'] = "No tiene permisos para acceder a esta sección.";
    header("Location: dashboard.php");
    exit;
}

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
                $usuario_id = $_POST['usuario_id'] ?? null;
                $nombre = limpiarEntrada($_POST['nombre']);
                $apellido = limpiarEntrada($_POST['apellido']);
                $email = limpiarEntrada($_POST['email']);
                $usuario = limpiarEntrada($_POST['usuario']);
                $contraseña = $_POST['contraseña'] ?? '';
                $rol = $_POST['rol'];

                if (empty($nombre) || empty($apellido) || empty($usuario)) {
                    $_SESSION['error'] = "Nombre, apellido y usuario son obligatorios.";
                } elseif (!$usuario_id && empty($contraseña)) {
                    $_SESSION['error'] = "La contraseña es obligatoria para usuarios nuevos.";
                } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['error'] = "El formato del email no es válido.";
                } else {
                    try {
                        // Verificar si el usuario ya existe
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE (usuario = ? OR email = ?) AND id != ?");
                        $stmt_check->execute([$usuario, $email, $usuario_id ?? 0]);
                        
                        if ($stmt_check->fetchColumn() > 0) {
                            $_SESSION['error'] = "El usuario o email ya existe.";
                        } else {
                            if ($usuario_id) {
                                // Editar usuario
                                if (!empty($contraseña)) {
                                    $contraseña_hash = password_hash($contraseña, PASSWORD_DEFAULT);
                                    $stmt = $pdo->prepare("
                                        UPDATE usuarios SET 
                                            nombre = ?, apellido = ?, email = ?, usuario = ?, 
                                            contraseña = ?, rol = ?
                                        WHERE id = ?
                                    ");
                                    $stmt->execute([
                                        $nombre, $apellido, $email, $usuario, 
                                        $contraseña_hash, $rol, $usuario_id
                                    ]);
                                } else {
                                    $stmt = $pdo->prepare("
                                        UPDATE usuarios SET 
                                            nombre = ?, apellido = ?, email = ?, usuario = ?, rol = ?
                                        WHERE id = ?
                                    ");
                                    $stmt->execute([
                                        $nombre, $apellido, $email, $usuario, $rol, $usuario_id
                                    ]);
                                }
                                
                                if (function_exists('registrarLog')) {
                                    registrarLog($pdo, 'usuarios', $usuario_id, 'actualizar', 
                                        "Usuario actualizado: $nombre $apellido", $_SESSION['user_id']);
                                }
                                
                                $_SESSION['mensaje'] = "Usuario actualizado correctamente.";
                            } else {
                                // Agregar nuevo usuario
                                $contraseña_hash = password_hash($contraseña, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("
                                    INSERT INTO usuarios (nombre, apellido, email, usuario, contraseña, rol) 
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $nombre, $apellido, $email, $usuario, $contraseña_hash, $rol
                                ]);
                                
                                $nuevo_id = $pdo->lastInsertId();
                                if (function_exists('registrarLog')) {
                                    registrarLog($pdo, 'usuarios', $nuevo_id, 'crear', 
                                        "Nuevo usuario registrado: $nombre $apellido", $_SESSION['user_id']);
                                }
                                
                                $_SESSION['mensaje'] = "Usuario registrado correctamente.";
                            }
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
                    }
                }
                break;
                
            case 'cambiar_estado':
                $usuario_id = $_POST['usuario_id'];
                $nuevo_estado = $_POST['nuevo_estado'] === '1' ? 1 : 0;
                
                // No permitir desactivar el propio usuario
                if ($usuario_id == $_SESSION['user_id']) {
                    $_SESSION['error'] = "No puede desactivar su propio usuario.";
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
                        $stmt->execute([$nuevo_estado, $usuario_id]);
                        
                        if (function_exists('registrarLog')) {
                            $accion = $nuevo_estado ? 'activar' : 'desactivar';
                            registrarLog($pdo, 'usuarios', $usuario_id, 'actualizar', 
                                "Usuario $accion", $_SESSION['user_id']);
                        }
                        
                        $mensaje_estado = $nuevo_estado ? 'activado' : 'desactivado';
                        $_SESSION['mensaje'] = "Usuario $mensaje_estado correctamente.";
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error al cambiar estado: " . $e->getMessage();
                    }
                }
                break;
                
            case 'resetear_password':
                $usuario_id = $_POST['usuario_id'];
                $nueva_contraseña = 'password123'; // Contraseña temporal
                
                try {
                    $contraseña_hash = password_hash($nueva_contraseña, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE usuarios SET contraseña = ?, intentos_fallidos = 0 WHERE id = ?");
                    $stmt->execute([$contraseña_hash, $usuario_id]);
                    
                    if (function_exists('registrarLog')) {
                        registrarLog($pdo, 'usuarios', $usuario_id, 'actualizar', 
                            "Contraseña reseteada", $_SESSION['user_id']);
                    }
                    
                    $_SESSION['mensaje'] = "Contraseña reseteada correctamente. Nueva contraseña: $nueva_contraseña";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error al resetear contraseña: " . $e->getMessage();
                }
                break;
                
            case 'eliminar':
                $usuario_id = $_POST['usuario_id'];
                
                // No permitir eliminar el propio usuario
                if ($usuario_id == $_SESSION['user_id']) {
                    $_SESSION['error'] = "No puede eliminar su propio usuario.";
                } else {
                    try {
                        // Verificar si el usuario tiene asignaciones
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM asignaciones WHERE usuario_asignador = ? OR usuario_autorizador = ? OR usuario_entregador = ?");
                        $stmt_check->execute([$usuario_id, $usuario_id, $usuario_id]);
                        
                        if ($stmt_check->fetchColumn() > 0) {
                            // Solo desactivar si tiene asignaciones
                            $stmt = $pdo->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?");
                            $stmt->execute([$usuario_id]);
                            $_SESSION['mensaje'] = "Usuario desactivado (tiene asignaciones asociadas).";
                        } else {
                            // Eliminar completamente si no tiene asignaciones
                            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
                            $stmt->execute([$usuario_id]);
                            $_SESSION['mensaje'] = "Usuario eliminado correctamente.";
                        }
                        
                        if (function_exists('registrarLog')) {
                            registrarLog($pdo, 'usuarios', $usuario_id, 'eliminar', 
                                "Usuario eliminado/desactivado", $_SESSION['user_id']);
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error al eliminar usuario: " . $e->getMessage();
                    }
                }
                break;
        }
    }
    header("Location: usuarios.php");
    exit();
}

// Obtener usuarios con paginación y búsqueda
$page = (int)($_GET['page'] ?? 1);
$limit = 12;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$rol_filter = $_GET['rol'] ?? '';
$estado_filter = $_GET['estado'] ?? 'todos';

$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(nombre LIKE ? OR apellido LIKE ? OR email LIKE ? OR usuario LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if (!empty($rol_filter)) {
    $where_conditions[] = "rol = ?";
    $params[] = $rol_filter;
}

if ($estado_filter === 'activos') {
    $where_conditions[] = "activo = 1";
} elseif ($estado_filter === 'inactivos') {
    $where_conditions[] = "activo = 0";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

try {
    // Contar total de registros
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM usuarios $where_clause");
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    
    // Obtener usuarios con estadísticas
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones a WHERE a.usuario_asignador = u.id), 0
            ) as asignaciones_creadas,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones a WHERE a.usuario_autorizador = u.id), 0
            ) as asignaciones_autorizadas,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones a WHERE a.usuario_entregador = u.id), 0
            ) as asignaciones_entregadas,
            COALESCE(
                (SELECT MAX(a.fecha_asignacion) FROM asignaciones a WHERE a.usuario_asignador = u.id), NULL
            ) as ultima_actividad
        FROM usuarios u
        $where_clause
        ORDER BY u.fecha_registro DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();
    
    // Estadísticas rápidas
    $stats = [];
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as inactivos,
            SUM(CASE WHEN rol = 'admin' THEN 1 ELSE 0 END) as administradores,
            SUM(CASE WHEN rol = 'supervisor' THEN 1 ELSE 0 END) as supervisores,
            SUM(CASE WHEN rol = 'empleado' THEN 1 ELSE 0 END) as empleados,
            SUM(CASE WHEN rol = 'consulta' THEN 1 ELSE 0 END) as consulta
        FROM usuarios
    ");
    $stats = $stmt_stats->fetch();
    
} catch (PDOException $e) {
    $error = "Error al cargar usuarios: " . $e->getMessage();
    $usuarios = [];
    $stats = ['total' => 0, 'activos' => 0, 'inactivos' => 0, 'administradores' => 0, 'supervisores' => 0, 'empleados' => 0, 'consulta' => 0];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Sistema de Desarrollo Social</title>
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

        /* Usuarios Grid */
        .usuarios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .usuario-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
        }

        .usuario-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .usuario-card.inactive {
            opacity: 0.7;
            border-left: 4px solid var(--warning-color);
        }

        .usuario-card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        .usuario-avatar {
            width: 60px;
            height: 60px;
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

        .usuario-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .usuario-username {
            font-size: 0.9rem;
            color: var(--secondary-color);
            font-family: 'Monaco', 'Menlo', monospace;
        }

        .usuario-status {
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

        .usuario-card-body {
            padding: 1.5rem;
        }

        .usuario-info-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
        }

        .usuario-info-item i {
            width: 20px;
            color: var(--secondary-color);
            margin-right: 0.75rem;
        }

        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin {
            background: #fee2e2;
            color: #991b1b;
        }

        .role-supervisor {
            background: #fed7aa;
            color: #9a3412;
        }

        .role-empleado {
            background: #d1fae5;
            color: #065f46;
        }

        .role-consulta {
            background: #f1f5f9;
            color: #475569;
        }

        .usuario-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .usuario-stat {
            text-align: center;
        }

        .usuario-stat-number {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            display: block;
        }

        .usuario-stat-label {
            font-size: 0.7rem;
            color: var(--secondary-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .usuario-card-actions {
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

        .btn-toggle {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-toggle:hover {
            background: #bfdbfe;
            transform: scale(1.1);
        }

        .btn-reset {
            background: #fef3c7;
            color: #92400e;
        }

        .btn-reset:hover {
            background: #fde68a;
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
            <h1><i class="fas fa-users-cog me-3"></i>Gestión de Usuarios</h1>
            <p>Administración de usuarios y permisos del sistema</p>
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
                        <p>Total Usuarios</p>
                    </div>
                    <div class="stat-card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['activos']); ?></h3>
                        <p>Usuarios Activos</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--success-color), #047857);">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['administradores']); ?></h3>
                        <p>Administradores</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--danger-color), #b91c1c);">
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['empleados']); ?></h3>
                        <p>Empleados</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--info-color), #0e7490);">
                        <i class="fas fa-user-tie"></i>
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
                               placeholder="Buscar por nombre, apellido, email o usuario..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="rol">
                        <option value="">Todos los roles</option>
                        <option value="admin" <?php echo $rol_filter === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                        <option value="supervisor" <?php echo $rol_filter === 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                        <option value="empleado" <?php echo $rol_filter === 'empleado' ? 'selected' : ''; ?>>Empleado</option>
                        <option value="consulta" <?php echo $rol_filter === 'consulta' ? 'selected' : ''; ?>>Consulta</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="estado">
                        <option value="todos" <?php echo $estado_filter === 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="activos" <?php echo $estado_filter === 'activos' ? 'selected' : ''; ?>>Activos</option>
                        <option value="inactivos" <?php echo $estado_filter === 'inactivos' ? 'selected' : ''; ?>>Inactivos</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-search me-2"></i>Buscar
                        </button>
                        <a href="usuarios.php" class="btn btn-outline-secondary">
                            <i class="fas fa-refresh"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-success w-100" onclick="exportarUsuarios()">
                        <i class="fas fa-download me-2"></i>Exportar
                    </button>
                </div>
            </form>
        </div>

        <!-- Usuarios Grid -->
        <?php if (!empty($usuarios)): ?>
        <div class="usuarios-grid">
            <?php foreach ($usuarios as $usuario): ?>
            <div class="usuario-card <?php echo $usuario['activo'] ? '' : 'inactive'; ?> fade-in-up">
                <div class="usuario-card-header">
                    <div class="usuario-status">
                        <span class="status-badge <?php echo $usuario['activo'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                        </span>
                    </div>
                    <div class="usuario-avatar" style="background: <?php 
                        echo match($usuario['rol']) {
                            'admin' => 'linear-gradient(135deg, #dc2626, #b91c1c)',
                            'supervisor' => 'linear-gradient(135deg, #d97706, #c2410c)',
                            'empleado' => 'linear-gradient(135deg, #059669, #047857)',
                            'consulta' => 'linear-gradient(135deg, #64748b, #475569)',
                            default => 'linear-gradient(135deg, var(--primary-color), var(--primary-dark))'
                        };
                    ?>;">
                        <?php echo strtoupper(substr($usuario['nombre'], 0, 1) . substr($usuario['apellido'], 0, 1)); ?>
                    </div>
                    <div class="usuario-name">
                        <?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']); ?>
                    </div>
                    <div class="usuario-username">
                        @<?php echo htmlspecialchars($usuario['usuario']); ?>
                    </div>
                </div>

                <div class="usuario-card-body">
                    <?php if (!empty($usuario['email'])): ?>
                    <div class="usuario-info-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($usuario['email']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="usuario-info-item">
                        <i class="fas fa-user-tag"></i>
                        <span class="role-badge role-<?php echo $usuario['rol']; ?>">
                            <?php echo ucfirst($usuario['rol']); ?>
                        </span>
                    </div>
                    
                    <div class="usuario-info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Registrado: <?php echo formatearFecha($usuario['fecha_registro']); ?></span>
                    </div>

                    <?php if ($usuario['ultimo_acceso']): ?>
                    <div class="usuario-info-item">
                        <i class="fas fa-clock"></i>
                        <span>Último acceso: <?php echo formatearFechaHora($usuario['ultimo_acceso']); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($usuario['ultima_actividad']): ?>
                    <div class="usuario-info-item">
                        <i class="fas fa-bolt"></i>
                        <span>Última actividad: <?php echo formatearFecha($usuario['ultima_actividad']); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="usuario-stats">
                        <div class="usuario-stat">
                            <span class="usuario-stat-number"><?php echo $usuario['asignaciones_creadas']; ?></span>
                            <span class="usuario-stat-label">Creadas</span>
                        </div>
                        <div class="usuario-stat">
                            <span class="usuario-stat-number"><?php echo $usuario['asignaciones_autorizadas']; ?></span>
                            <span class="usuario-stat-label">Autorizadas</span>
                        </div>
                        <div class="usuario-stat">
                            <span class="usuario-stat-number"><?php echo $usuario['asignaciones_entregadas']; ?></span>
                            <span class="usuario-stat-label">Entregadas</span>
                        </div>
                    </div>
                </div>

                <div class="usuario-card-actions">
                    <button class="btn-action btn-edit" 
                            onclick="editarUsuario(<?php echo $usuario['id']; ?>)"
                            title="Editar usuario">
                        <i class="fas fa-edit"></i>
                    </button>
                    
                    <?php if ($usuario['id'] != $_SESSION['user_id']): ?>
                    <button class="btn-action btn-toggle" 
                            onclick="cambiarEstadoUsuario(<?php echo $usuario['id']; ?>, <?php echo $usuario['activo'] ? '0' : '1'; ?>)"
                            title="<?php echo $usuario['activo'] ? 'Desactivar' : 'Activar'; ?> usuario">
                        <i class="fas fa-<?php echo $usuario['activo'] ? 'pause' : 'play'; ?>"></i>
                    </button>
                    
                    <button class="btn-action btn-reset" 
                            onclick="resetearPassword(<?php echo $usuario['id']; ?>, '<?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']); ?>')"
                            title="Resetear contraseña">
                        <i class="fas fa-key"></i>
                    </button>
                    
                    <button class="btn-action btn-delete" 
                            onclick="eliminarUsuario(<?php echo $usuario['id']; ?>, '<?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']); ?>')"
                            title="Eliminar usuario">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state fade-in-up">
            <i class="fas fa-users-cog"></i>
            <h3>No se encontraron usuarios</h3>
            <p>No hay usuarios registrados que coincidan con los criterios de búsqueda</p>
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#usuarioModal">
                <i class="fas fa-plus me-2"></i>Crear Primer Usuario
            </button>
        </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container fade-in-up">
            <div class="d-flex justify-content-between align-items-center w-100">
                <div class="text-muted">
                    Mostrando <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> 
                    de <?php echo number_format($total_records); ?> usuarios
                </div>
                
                <nav>
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&rol=<?php echo $rol_filter; ?>&estado=<?php echo $estado_filter; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&rol=<?php echo $rol_filter; ?>&estado=<?php echo $estado_filter; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&rol=<?php echo $rol_filter; ?>&estado=<?php echo $estado_filter; ?>">
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
    <button class="fab" data-bs-toggle="modal" data-bs-target="#usuarioModal" title="Agregar nuevo usuario">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Modal para agregar/editar usuario -->
    <div class="modal fade" id="usuarioModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>
                        <span id="modalTitle">Nuevo Usuario</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="usuarioForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="agregar">
                        <input type="hidden" name="usuario_id" id="usuario_id">
                        
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
                                    <label for="usuario" class="form-label">Usuario *</label>
                                    <input type="text" class="form-control" id="usuario" name="usuario" required>
                                    <div class="form-text">Nombre de usuario único para iniciar sesión</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="contraseña" class="form-label">Contraseña <span id="contraseñaRequerida">*</span></label>
                                    <input type="password" class="form-control" id="contraseña" name="contraseña">
                                    <div class="form-text">Mínimo 6 caracteres</div>
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
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Roles del sistema:</strong>
                            <ul class="mb-0 mt-2">
                                <li><strong>Administrador:</strong> Acceso completo al sistema</li>
                                <li><strong>Supervisor:</strong> Puede autorizar asignaciones</li>
                                <li><strong>Empleado:</strong> Puede crear y gestionar asignaciones</li>
                                <li><strong>Solo Consulta:</strong> Solo puede ver información</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <span id="btnSubmitText">Crear Usuario</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para cambiar estado -->
    <div class="modal fade" id="estadoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Cambiar Estado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="estadoMensaje"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="cambiar_estado">
                        <input type="hidden" name="usuario_id" id="estadoUsuarioId">
                        <input type="hidden" name="nuevo_estado" id="nuevoEstado">
                        <button type="submit" class="btn btn-warning" id="btnCambiarEstado">
                            <i class="fas fa-exchange-alt me-2"></i>Confirmar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para resetear contraseña -->
    <div class="modal fade" id="resetModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-key me-2"></i>Resetear Contraseña</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea resetear la contraseña de <strong id="usuarioReset"></strong>?</p>
                    <p class="text-muted small">Se establecerá la contraseña temporal: <code>password123</code></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="resetear_password">
                        <input type="hidden" name="usuario_id" id="resetUsuarioId">
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-key me-2"></i>Resetear Contraseña
                        </button>
                    </form>
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
                    <p>¿Está seguro que desea eliminar al usuario <strong id="usuarioEliminar"></strong>?</p>
                    <p class="text-muted small">Si el usuario tiene asignaciones asociadas será desactivado en lugar de eliminado.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="usuario_id" id="eliminarUsuarioId">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Eliminar Usuario
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let usuarioIdActual = null;

        // Auto-capitalizar nombres
        document.addEventListener('DOMContentLoaded', function() {
            const campos = ['nombre', 'apellido'];
            campos.forEach(campo => {
                const input = document.getElementById(campo);
                if (input) {
                    input.addEventListener('input', function() {
                        this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
                    });
                }
            });
        });

        function editarUsuario(id) {
            usuarioIdActual = id;
            
            // Obtener datos del usuario desde la vista actual
            const usuarioCard = document.querySelector(`[onclick*="${id}"]`).closest('.usuario-card');
            const nombre = usuarioCard.querySelector('.usuario-name').textContent.split(' ')[0];
            const apellido = usuarioCard.querySelector('.usuario-name').textContent.split(' ').slice(1).join(' ');
            const usuario = usuarioCard.querySelector('.usuario-username').textContent.replace('@', '');
            const rol = usuarioCard.querySelector('.role-badge').textContent.toLowerCase();
            
            document.getElementById('modalTitle').textContent = 'Editar Usuario';
            document.getElementById('btnSubmitText').textContent = 'Actualizar Usuario';
            document.getElementById('accion').value = 'editar';
            document.getElementById('usuario_id').value = id;
            document.getElementById('contraseñaRequerida').style.display = 'none';
            document.getElementById('contraseña').removeAttribute('required');
            
            document.getElementById('nombre').value = nombre;
            document.getElementById('apellido').value = apellido;
            document.getElementById('usuario').value = usuario;
            document.getElementById('rol').value = rol;
            
            new bootstrap.Modal(document.getElementById('usuarioModal')).show();
        }

        function cambiarEstadoUsuario(id, nuevoEstado) {
            const accion = nuevoEstado === 1 ? 'activar' : 'desactivar';
            const mensaje = `¿Está seguro que desea ${accion} este usuario?`;
            
            document.getElementById('estadoMensaje').textContent = mensaje;
            document.getElementById('estadoUsuarioId').value = id;
            document.getElementById('nuevoEstado').value = nuevoEstado;
            document.getElementById('btnCambiarEstado').innerHTML = `<i class="fas fa-exchange-alt me-2"></i>${accion.charAt(0).toUpperCase() + accion.slice(1)}`;
            
            new bootstrap.Modal(document.getElementById('estadoModal')).show();
        }

        function resetearPassword(id, nombre) {
            document.getElementById('usuarioReset').textContent = nombre;
            document.getElementById('resetUsuarioId').value = id;
            new bootstrap.Modal(document.getElementById('resetModal')).show();
        }

        function eliminarUsuario(id, nombre) {
            document.getElementById('usuarioEliminar').textContent = nombre;
            document.getElementById('eliminarUsuarioId').value = id;
            new bootstrap.Modal(document.getElementById('eliminarModal')).show();
        }

        function exportarUsuarios() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.open(`api/export_usuarios.php?${params.toString()}`, '_blank');
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('usuarioModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('usuarioForm').reset();
            document.getElementById('modalTitle').textContent = 'Nuevo Usuario';
            document.getElementById('btnSubmitText').textContent = 'Crear Usuario';
            document.getElementById('accion').value = 'agregar';
            document.getElementById('usuario_id').value = '';
            document.getElementById('contraseñaRequerida').style.display = 'inline';
            document.getElementById('contraseña').setAttribute('required', 'required');
            usuarioIdActual = null;
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
            const counters = document.querySelectorAll('.stat-card-info h3, .usuario-stat-number');
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

        // Generar usuario automáticamente basado en nombre y apellido
        document.getElementById('nombre').addEventListener('input', generarUsuario);
        document.getElementById('apellido').addEventListener('input', generarUsuario);

        function generarUsuario() {
            const nombre = document.getElementById('nombre').value.toLowerCase();
            const apellido = document.getElementById('apellido').value.toLowerCase();
            const usuarioInput = document.getElementById('usuario');
            
            if (nombre && apellido && !usuarioIdActual) {
                const sugerencia = nombre.charAt(0) + apellido.replace(/\s+/g, '');
                usuarioInput.value = sugerencia;
            }
        }
    </script>
</body>
</html>