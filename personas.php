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
                $persona_id = $_POST['persona_id'] ?? null;
                $nombre = limpiarEntrada($_POST['nombre']);
                $apellido = limpiarEntrada($_POST['apellido']);
                $cedula = limpiarEntrada($_POST['cedula']);
                $direccion = limpiarEntrada($_POST['direccion']);
                $telefono = limpiarEntrada($_POST['telefono']);
                $id_familia = $_POST['id_familia'] !== '' ? (int)$_POST['id_familia'] : null;

                if (empty($nombre) || empty($cedula)) {
                    $_SESSION['error'] = "Nombre y Cédula son obligatorios.";
                } elseif (!ctype_digit($cedula)) {
                    $_SESSION['error'] = "La Cédula debe contener solo números.";
                } else {
                    try {
                        // Verificar si la cédula ya existe
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM personas WHERE cedula = ? AND id != ?");
                        $stmt_check->execute([$cedula, $persona_id ?? 0]);
                        
                        if ($stmt_check->fetchColumn() > 0) {
                            $_SESSION['error'] = "Ya existe una persona con la cédula ingresada.";
                        } else {
                            if ($persona_id) {
                                // Editar persona
                                $stmt = $pdo->prepare("
                                    UPDATE personas SET 
                                        nombre = ?, apellido = ?, cedula = ?, direccion = ?, 
                                        telefono = ?, id_familia = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([
                                    $nombre, $apellido, $cedula, $direccion, 
                                    $telefono, $id_familia, $persona_id
                                ]);
                                
                                if (function_exists('registrarLog')) {
                                    registrarLog($pdo, 'personas', $persona_id, 'actualizar', 
                                        "Persona actualizada: $nombre $apellido", $_SESSION['user_id']);
                                }
                                
                                $_SESSION['mensaje'] = "Persona actualizada correctamente.";
                            } else {
                                // Agregar nueva persona
                                $stmt = $pdo->prepare("
                                    INSERT INTO personas (nombre, apellido, cedula, direccion, telefono, id_familia) 
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $nombre, $apellido, $cedula, $direccion, $telefono, $id_familia
                                ]);
                                
                                $nuevo_id = $pdo->lastInsertId();
                                if (function_exists('registrarLog')) {
                                    registrarLog($pdo, 'personas', $nuevo_id, 'crear', 
                                        "Nueva persona registrada: $nombre $apellido", $_SESSION['user_id']);
                                }
                                
                                $_SESSION['mensaje'] = "Persona registrada correctamente.";
                            }
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
                    }
                }
                break;
                
            case 'eliminar':
                if ($user_role === 'admin') {
                    $persona_id = $_POST['persona_id'];
                    try {
                        $stmt = $pdo->prepare("DELETE FROM personas WHERE id = ?");
                        $stmt->execute([$persona_id]);
                        
                        if (function_exists('registrarLog')) {
                            registrarLog($pdo, 'personas', $persona_id, 'eliminar', 
                                "Persona eliminada", $_SESSION['user_id']);
                        }
                        
                        $_SESSION['mensaje'] = "Persona eliminada correctamente.";
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error al eliminar persona: " . $e->getMessage();
                    }
                } else {
                    $_SESSION['error'] = "No tiene permisos para realizar esta acción.";
                }
                break;
        }
    }
    header("Location: personas.php");
    exit();
}

// Obtener personas con paginación y búsqueda
$page = (int)($_GET['page'] ?? 1);
$limit = 12;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$familia_filter = $_GET['familia'] ?? '';

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

try {
    // Contar total de registros
    $stmt_count = $pdo->prepare("
        SELECT COUNT(*) 
        FROM personas p
        LEFT JOIN familias f ON p.id_familia = f.id
        $where_clause
    ");
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    
    // Obtener personas
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            f.barrio
        FROM personas p
        LEFT JOIN familias f ON p.id_familia = f.id
        $where_clause
        ORDER BY p.fecha_registro DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $personas = $stmt->fetchAll();
    
    // Obtener familias para el select
    $stmt_familias = $pdo->query("
        SELECT id, nombre_jefe, apellido_jefe, dni_jefe 
        FROM familias 
        WHERE estado = 'activa' 
        ORDER BY nombre_jefe, apellido_jefe
    ");
    $familias = $stmt_familias->fetchAll();
    
    // Estadísticas rápidas
    $stats = [];
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN id_familia IS NOT NULL THEN 1 END) as con_familia,
            COUNT(CASE WHEN id_familia IS NULL THEN 1 END) as sin_familia,
            COUNT(CASE WHEN telefono IS NOT NULL AND telefono != '' THEN 1 END) as con_telefono
        FROM personas
    ");
    $stats = $stmt_stats->fetch();
    
} catch (PDOException $e) {
    $error = "Error al cargar personas: " . $e->getMessage();
    $personas = [];
    $familias = [];
    $stats = ['total' => 0, 'con_familia' => 0, 'sin_familia' => 0, 'con_telefono' => 0];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Personas - Sistema de Desarrollo Social</title>
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

        /* Personas Grid */
        .personas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .persona-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
        }

        .persona-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .persona-card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        .persona-avatar {
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

        .persona-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .persona-cedula {
            font-size: 0.9rem;
            color: var(--secondary-color);
            font-family: 'Monaco', 'Menlo', monospace;
        }

        .persona-card-body {
            padding: 1.5rem;
        }

        .persona-info-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
        }

        .persona-info-item i {
            width: 20px;
            color: var(--secondary-color);
            margin-right: 0.75rem;
        }

        .familia-info {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            border-left: 4px solid var(--info-color);
        }

        .familia-name {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .familia-details {
            font-size: 0.85rem;
            color: var(--secondary-color);
        }

        .persona-card-actions {
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
            <h1><i class="fas fa-user-friends me-3"></i>Gestión de Personas</h1>
            <p>Administración del registro individual de personas del sistema</p>
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
                        <p>Total Personas</p>
                    </div>
                    <div class="stat-card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['con_familia']); ?></h3>
                        <p>Con Familia Asignada</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--success-color), #047857);">
                        <i class="fas fa-home"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['sin_familia']); ?></h3>
                        <p>Sin Familia Asignada</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--warning-color), #c2410c);">
                        <i class="fas fa-user"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['con_telefono']); ?></h3>
                        <p>Con Teléfono</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--info-color), #0e7490);">
                        <i class="fas fa-phone"></i>
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
                               placeholder="Buscar por nombre, apellido, cédula o teléfono..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="familia">
                        <option value="">Todas las familias</option>
                        <option value="0" <?php echo $familia_filter === '0' ? 'selected' : ''; ?>>Sin familia asignada</option>
                        <?php foreach ($familias as $familia): ?>
                            <option value="<?php echo $familia['id']; ?>" <?php echo $familia_filter == $familia['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-search me-2"></i>Buscar
                        </button>
                        <a href="personas.php" class="btn btn-outline-secondary">
                            <i class="fas fa-refresh"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-success w-100" onclick="exportarPersonas()">
                        <i class="fas fa-download me-2"></i>Exportar
                    </button>
                </div>
            </form>
        </div>

        <!-- Personas Grid -->
        <?php if (!empty($personas)): ?>
        <div class="personas-grid">
            <?php foreach ($personas as $persona): ?>
            <div class="persona-card fade-in-up">
                <div class="persona-card-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="persona-avatar">
                                <?php echo strtoupper(substr($persona['nombre'], 0, 1) . substr($persona['apellido'] ?? '', 0, 1)); ?>
                            </div>
                            <div class="persona-name">
                                <?php echo htmlspecialchars($persona['nombre'] . ' ' . ($persona['apellido'] ?? '')); ?>
                            </div>
                            <div class="persona-cedula">
                                Cédula: <?php echo htmlspecialchars($persona['cedula']); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="persona-card-body">
                    <?php if (!empty($persona['telefono'])): ?>
                    <div class="persona-info-item">
                        <i class="fas fa-phone"></i>
                        <span><?php echo htmlspecialchars($persona['telefono']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($persona['direccion'])): ?>
                    <div class="persona-info-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($persona['direccion']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="persona-info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Registrado: <?php echo formatearFecha($persona['fecha_registro']); ?></span>
                    </div>

                    <?php if ($persona['nombre_jefe']): ?>
                    <div class="familia-info">
                        <div class="familia-name">
                            <i class="fas fa-home me-2"></i>
                            Familia: <?php echo htmlspecialchars($persona['nombre_jefe'] . ' ' . $persona['apellido_jefe']); ?>
                        </div>
                        <div class="familia-details">
                            DNI Jefe: <?php echo htmlspecialchars($persona['dni_jefe']); ?>
                            <?php if (!empty($persona['barrio'])): ?>
                                • Barrio: <?php echo htmlspecialchars($persona['barrio']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="familia-info" style="background: linear-gradient(135deg, #fef3c7 0%, #fed7aa 100%); border-left-color: var(--warning-color);">
                        <div class="familia-name">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Sin familia asignada
                        </div>
                        <div class="familia-details">
                            Esta persona no está asociada a ningún grupo familiar
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="persona-card-actions">
                    <button class="btn-action btn-view" 
                            onclick="verDetallePersona(<?php echo $persona['id']; ?>)"
                            title="Ver detalle">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn-action btn-edit" 
                            onclick="editarPersona(<?php echo $persona['id']; ?>)"
                            title="Editar persona">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php if ($user_role === 'admin'): ?>
                    <button class="btn-action btn-delete" 
                            onclick="eliminarPersona(<?php echo $persona['id']; ?>, '<?php echo htmlspecialchars($persona['nombre'] . ' ' . ($persona['apellido'] ?? '')); ?>')"
                            title="Eliminar persona">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state fade-in-up">
            <i class="fas fa-user-friends"></i>
            <h3>No se encontraron personas</h3>
            <p>No hay personas registradas que coincidan con los criterios de búsqueda</p>
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#personaModal">
                <i class="fas fa-plus me-2"></i>Registrar Primera Persona
            </button>
        </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container fade-in-up">
            <div class="d-flex justify-content-between align-items-center w-100">
                <div class="text-muted">
                    Mostrando <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> 
                    de <?php echo number_format($total_records); ?> personas
                </div>
                
                <nav>
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&familia=<?php echo $familia_filter; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&familia=<?php echo $familia_filter; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&familia=<?php echo $familia_filter; ?>">
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
    <button class="fab" data-bs-toggle="modal" data-bs-target="#personaModal" title="Agregar nueva persona">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Modal para agregar/editar persona -->
    <div class="modal fade" id="personaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>
                        <span id="modalTitle">Nueva Persona</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="personaForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="agregar">
                        <input type="hidden" name="persona_id" id="persona_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="nombre" class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="apellido" class="form-label">Apellido</label>
                                    <input type="text" class="form-control" id="apellido" name="apellido">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="cedula" class="form-label">Cédula/DNI *</label>
                                    <input type="text" class="form-control" id="cedula" name="cedula" 
                                           pattern="[0-9]+" maxlength="8" required>
                                    <div class="form-text">Ingrese solo números, sin puntos ni espacios</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="telefono" class="form-label">Teléfono</label>
                                    <input type="tel" class="form-control" id="telefono" name="telefono">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="direccion" class="form-label">Dirección</label>
                            <input type="text" class="form-control" id="direccion" name="direccion">
                        </div>
                        
                        <div class="mb-3">
                            <label for="id_familia" class="form-label">Familia (Opcional)</label>
                            <select class="form-select" id="id_familia" name="id_familia">
                                <option value="">-- Sin familia asignada --</option>
                                <?php foreach ($familias as $familia): ?>
                                    <option value="<?php echo $familia['id']; ?>">
                                        <?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe'] . ' - DNI: ' . $familia['dni_jefe']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Seleccione la familia a la que pertenece esta persona</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <span id="btnSubmitText">Guardar Persona</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de detalle de persona -->
    <div class="modal fade" id="detallePersonaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle me-2"></i>
                        Detalle de Persona
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detallePersonaContent">
                    <!-- Contenido cargado dinámicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="editarPersonaDesdeDetalle()">
                        <i class="fas fa-edit me-2"></i>Editar Persona
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
                    <p>¿Está seguro que desea eliminar a <strong id="personaEliminar"></strong>?</p>
                    <p class="text-muted small">Esta acción no se puede deshacer. Se eliminarán todos los registros asociados a esta persona.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="persona_id" id="personaIdEliminar">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Eliminar Persona
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let personaIdActual = null;

        // Auto-capitalizar nombres
        document.addEventListener('DOMContentLoaded', function() {
            const campos = ['nombre', 'apellido', 'direccion'];
            campos.forEach(campo => {
                const input = document.getElementById(campo);
                if (input) {
                    input.addEventListener('input', function() {
                        this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
                    });
                }
            });
            
            // Solo números para cédula
            const cedulaInput = document.getElementById('cedula');
            if (cedulaInput) {
                cedulaInput.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '');
                });
            }
        });

        function editarPersona(id) {
            personaIdActual = id;
            
            // Hacer petición AJAX para obtener datos de la persona
            fetch(`api/persona.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const persona = data.persona;
                        
                        document.getElementById('modalTitle').textContent = 'Editar Persona';
                        document.getElementById('btnSubmitText').textContent = 'Actualizar Persona';
                        document.getElementById('accion').value = 'editar';
                        document.getElementById('persona_id').value = persona.id;
                        
                        document.getElementById('nombre').value = persona.nombre || '';
                        document.getElementById('apellido').value = persona.apellido || '';
                        document.getElementById('cedula').value = persona.cedula || '';
                        document.getElementById('telefono').value = persona.telefono || '';
                        document.getElementById('direccion').value = persona.direccion || '';
                        document.getElementById('id_familia').value = persona.id_familia || '';
                        
                        new bootstrap.Modal(document.getElementById('personaModal')).show();
                    } else {
                        alert('Error al cargar los datos de la persona');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar los datos');
                });
        }

        function verDetallePersona(id) {
            // Cargar detalle de persona
            document.getElementById('detallePersonaContent').innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br><br>Cargando detalle...</div>';
            new bootstrap.Modal(document.getElementById('detallePersonaModal')).show();
            
            fetch(`api/persona_detalle.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('detallePersonaContent').innerHTML = data.html;
                        personaIdActual = id;
                    } else {
                        document.getElementById('detallePersonaContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error al cargar el detalle: ${data.error}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('detallePersonaContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error de conexión al cargar el detalle.
                        </div>
                    `;
                });
        }

        function editarPersonaDesdeDetalle() {
            if (personaIdActual) {
                bootstrap.Modal.getInstance(document.getElementById('detallePersonaModal')).hide();
                setTimeout(() => {
                    editarPersona(personaIdActual);
                }, 300);
            }
        }

        function eliminarPersona(id, nombre) {
            document.getElementById('personaEliminar').textContent = nombre;
            document.getElementById('personaIdEliminar').value = id;
            new bootstrap.Modal(document.getElementById('eliminarModal')).show();
        }

        function exportarPersonas() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.open(`api/export_personas.php?${params.toString()}`, '_blank');
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('personaModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('personaForm').reset();
            document.getElementById('modalTitle').textContent = 'Nueva Persona';
            document.getElementById('btnSubmitText').textContent = 'Guardar Persona';
            document.getElementById('accion').value = 'agregar';
            document.getElementById('persona_id').value = '';
            personaIdActual = null;
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