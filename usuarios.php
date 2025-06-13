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
        <div class="alert alert-