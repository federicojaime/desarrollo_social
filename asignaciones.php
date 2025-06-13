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
                $asignacion_id = $_POST['asignacion_id'] ?? null;
                $familia_id = (int)$_POST['familia_id'];
                $tipo_ayuda_id = (int)$_POST['tipo_ayuda_id'];
                $cantidad = (float)$_POST['cantidad'];
                $motivo = limpiarEntrada($_POST['motivo']);
                $observaciones = limpiarEntrada($_POST['observaciones']);
                $fecha_asignacion = $_POST['fecha_asignacion'];
                $prioridad = $_POST['prioridad'];

                if (empty($familia_id) || empty($tipo_ayuda_id) || empty($cantidad) || empty($motivo) || empty($fecha_asignacion)) {
                    $_SESSION['error'] = "Todos los campos marcados con * son obligatorios.";
                } elseif ($cantidad <= 0) {
                    $_SESSION['error'] = "La cantidad debe ser mayor a cero.";
                } else {
                    try {
                        if ($asignacion_id) {
                            // Editar asignación
                            $stmt = $pdo->prepare("
                                UPDATE asignaciones SET 
                                    familia_id = ?, id_ayuda = ?, cantidad = ?, motivo = ?, 
                                    observaciones = ?, fecha_asignacion = ?, prioridad = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([
                                $familia_id, $tipo_ayuda_id, $cantidad, $motivo, 
                                $observaciones, $fecha_asignacion, $prioridad, $asignacion_id
                            ]);
                            
                            if (function_exists('registrarLog')) {
                                registrarLog($pdo, 'asignaciones', $asignacion_id, 'actualizar', 
                                    "Asignación actualizada", $_SESSION['user_id']);
                            }
                            
                            $_SESSION['mensaje'] = "Asignación actualizada correctamente.";
                        } else {
                            // Nueva asignación
                            $numero_expediente = generarNumeroExpediente($pdo);
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO asignaciones (
                                    familia_id, id_ayuda, cantidad, motivo, observaciones, 
                                    fecha_asignacion, prioridad, numero_expediente, usuario_asignador, 
                                    estado
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
                            ");
                            $stmt->execute([
                                $familia_id, $tipo_ayuda_id, $cantidad, $motivo, $observaciones, 
                                $fecha_asignacion, $prioridad, $numero_expediente, $_SESSION['user_id']
                            ]);
                            
                            $nuevo_id = $pdo->lastInsertId();
                            if (function_exists('registrarLog')) {
                                registrarLog($pdo, 'asignaciones', $nuevo_id, 'crear', 
                                    "Nueva asignación creada: $numero_expediente", $_SESSION['user_id']);
                            }
                            
                            $_SESSION['mensaje'] = "Asignación creada correctamente con número de expediente: $numero_expediente";
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
                    }
                }
                break;
                
            case 'cambiar_estado':
                $asignacion_id = $_POST['asignacion_id'];
                $nuevo_estado = $_POST['nuevo_estado'];
                
                try {
                    $campos_adicionales = '';
                    $params = [$nuevo_estado];
                    
                    if ($nuevo_estado === 'autorizada') {
                        $campos_adicionales = ', usuario_autorizador = ?, fecha_autorizacion = NOW()';
                        $params[] = $_SESSION['user_id'];
                    } elseif ($nuevo_estado === 'entregada') {
                        $campos_adicionales = ', usuario_entregador = ?, fecha_entrega_real = NOW()';
                        $params[] = $_SESSION['user_id'];
                    }
                    
                    $params[] = $asignacion_id;
                    
                    $stmt = $pdo->prepare("UPDATE asignaciones SET estado = ? $campos_adicionales WHERE id = ?");
                    $stmt->execute($params);
                    
                    if (function_exists('registrarLog')) {
                        registrarLog($pdo, 'asignaciones', $asignacion_id, $nuevo_estado, 
                            "Estado cambiado a: $nuevo_estado", $_SESSION['user_id']);
                    }
                    
                    $_SESSION['mensaje'] = "Estado de la asignación actualizado correctamente.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error al cambiar estado: " . $e->getMessage();
                }
                break;
                
            case 'eliminar':
                if ($user_role === 'admin') {
                    $asignacion_id = $_POST['asignacion_id'];
                    try {
                        $stmt = $pdo->prepare("UPDATE asignaciones SET estado = 'cancelada' WHERE id = ?");
                        $stmt->execute([$asignacion_id]);
                        
                        if (function_exists('registrarLog')) {
                            registrarLog($pdo, 'asignaciones', $asignacion_id, 'eliminar', 
                                "Asignación cancelada", $_SESSION['user_id']);
                        }
                        
                        $_SESSION['mensaje'] = "Asignación cancelada correctamente.";
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error al cancelar asignación: " . $e->getMessage();
                    }
                } else {
                    $_SESSION['error'] = "No tiene permisos para realizar esta acción.";
                }
                break;
        }
    }
    header("Location: asignaciones.php");
    exit();
}

// Obtener filtros
$page = (int)($_GET['page'] ?? 1);
$limit = 12;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$estado_filter = $_GET['estado'] ?? '';
$prioridad_filter = $_GET['prioridad'] ?? '';
$familia_filter = $_GET['familia'] ?? '';

// Construir consulta con filtros
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(f.nombre_jefe LIKE ? OR f.apellido_jefe LIKE ? OR f.dni_jefe LIKE ? OR a.numero_expediente LIKE ? OR ay.nombre_ayuda LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
}

if (!empty($estado_filter)) {
    $where_conditions[] = "a.estado = ?";
    $params[] = $estado_filter;
}

if (!empty($prioridad_filter)) {
    $where_conditions[] = "a.prioridad = ?";
    $params[] = $prioridad_filter;
}

if (!empty($familia_filter)) {
    $where_conditions[] = "a.familia_id = ?";
    $params[] = $familia_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

try {
    // Contar total de registros
    $stmt_count = $pdo->prepare("
        SELECT COUNT(*) 
        FROM asignaciones a
        LEFT JOIN familias f ON a.familia_id = f.id
        LEFT JOIN ayudas ay ON a.id_ayuda = ay.id
        $where_clause
    ");
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    
    // Obtener asignaciones
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            f.telefono,
            f.direccion,
            f.barrio,
            COALESCE(ay.nombre_ayuda, 'Ayuda no especificada') as tipo_ayuda_nombre,
            ua.nombre as asignador_nombre,
            ua.apellido as asignador_apellido,
            uu.nombre as autorizador_nombre,
            uu.apellido as autorizador_apellido,
            ue.nombre as entregador_nombre,
            ue.apellido as entregador_apellido
        FROM asignaciones a
        LEFT JOIN familias f ON a.familia_id = f.id
        LEFT JOIN ayudas ay ON a.id_ayuda = ay.id
        LEFT JOIN usuarios ua ON a.usuario_asignador = ua.id
        LEFT JOIN usuarios uu ON a.usuario_autorizador = uu.id
        LEFT JOIN usuarios ue ON a.usuario_entregador = ue.id
        $where_clause
        ORDER BY a.fecha_creacion DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $asignaciones = $stmt->fetchAll();
    
    // Obtener familias para el select
    $stmt_familias = $pdo->query("
        SELECT id, nombre_jefe, apellido_jefe, dni_jefe 
        FROM familias 
        WHERE estado = 'activa' 
        ORDER BY nombre_jefe, apellido_jefe
    ");
    $familias = $stmt_familias->fetchAll();
    
    // Obtener tipos de ayuda
    $stmt_ayudas = $pdo->query("
        SELECT * FROM ayudas WHERE activo = 1 ORDER BY nombre_ayuda
    ");
    $tipos_ayuda = $stmt_ayudas->fetchAll();
    
    // Estadísticas rápidas
    $stats = [];
    $stmt_stats = $pdo->query("
        SELECT 
            estado, 
            COUNT(*) as cantidad,
            SUM(CASE WHEN prioridad = 'urgente' THEN 1 ELSE 0 END) as urgentes
        FROM asignaciones 
        GROUP BY estado
    ");
    while ($row = $stmt_stats->fetch()) {
        $stats[$row['estado']] = $row;
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar asignaciones: " . $e->getMessage();
    $asignaciones = [];
    $familias = [];
    $tipos_ayuda = [];
    $stats = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Asignaciones - Sistema de Desarrollo Social</title>
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

        /* Asignaciones Grid */
        .asignaciones-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .asignacion-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
        }

        .asignacion-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .asignacion-card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        .expediente-number {
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 0.8rem;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .beneficiario-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .beneficiario-dni {
            font-size: 0.85rem;
            color: var(--secondary-color);
            font-family: 'Monaco', 'Menlo', monospace;
        }

        .asignacion-card-body {
            padding: 1.5rem;
        }

        .ayuda-info {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--info-color);
        }

        .ayuda-name {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .ayuda-cantidad {
            font-size: 0.9rem;
            color: var(--secondary-color);
        }

        .info-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }

        .info-item i {
            width: 16px;
            color: var(--secondary-color);
            margin-right: 0.5rem;
        }

        .status-priority-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pendiente {
            background: #fef3c7;
            color: #92400e;
        }

        .status-autorizada {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-entregada {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelada {
            background: #fee2e2;
            color: #991b1b;
        }

        .priority-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-urgente {
            background: #fee2e2;
            color: #991b1b;
        }

        .priority-alta {
            background: #fed7aa;
            color: #9a3412;
        }

        .priority-media {
            background: #dbeafe;
            color: #1e40af;
        }

        .priority-baja {
            background: #f1f5f9;
            color: #475569;
        }

        .asignacion-card-actions {
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

        .btn-success {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-success:hover {
            background: #a7f3d0;
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

        /* Motivo y observaciones */
        .motivo-text {
            background: #f8fafc;
            border-radius: 6px;
            padding: 0.5rem;
            font-size: 0.8rem;
            color: var(--secondary-color);
            margin-top: 0.5rem;
            border-left: 3px solid var(--info-color);
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <h1><i class="fas fa-hand-holding-heart me-3"></i>Gestión de Asignaciones</h1>
            <p>Administración de ayudas asignadas a familias registradas</p>
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
                        <h3><?php echo number_format($stats['pendiente']['cantidad'] ?? 0); ?></h3>
                        <p>Asignaciones Pendientes</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['autorizada']['cantidad'] ?? 0); ?></h3>
                        <p>Asignaciones Autorizadas</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--info-color), #0e7490);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['entregada']['cantidad'] ?? 0); ?></h3>
                        <p>Asignaciones Entregadas</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--success-color), #047857);">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-content">
                    <div class="stat-card-info">
                        <h3><?php echo number_format($stats['cancelada']['cantidad'] ?? 0); ?></h3>
                        <p>Asignaciones Canceladas</p>
                    </div>
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, var(--danger-color), #b91c1c);">
                        <i class="fas fa-times-circle"></i>
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
                               placeholder="Buscar por familia, DNI o expediente..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="estado">
                        <option value="">Todos los estados</option>
                        <option value="pendiente" <?php echo $estado_filter === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                        <option value="autorizada" <?php echo $estado_filter === 'autorizada' ? 'selected' : ''; ?>>Autorizada</option>
                        <option value="entregada" <?php echo $estado_filter === 'entregada' ? 'selected' : ''; ?>>Entregada</option>
                        <option value="cancelada" <?php echo $estado_filter === 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="prioridad">
                        <option value="">Todas las prioridades</option>
                        <option value="baja" <?php echo $prioridad_filter === 'baja' ? 'selected' : ''; ?>>Baja</option>
                        <option value="media" <?php echo $prioridad_filter === 'media' ? 'selected' : ''; ?>>Media</option>
                        <option value="alta" <?php echo $prioridad_filter === 'alta' ? 'selected' : ''; ?>>Alta</option>
                        <option value="urgente" <?php echo $prioridad_filter === 'urgente' ? 'selected' : ''; ?>>Urgente</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-search me-2"></i>Buscar
                        </button>
                        <a href="asignaciones.php" class="btn btn-outline-secondary">
                            <i class="fas fa-refresh"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-success w-100" onclick="exportarAsignaciones()">
                        <i class="fas fa-download me-2"></i>Exportar
                    </button>
                </div>
            </form>
        </div>

        <!-- Asignaciones Grid -->
        <?php if (!empty($asignaciones)): ?>
        <div class="asignaciones-grid">
            <?php foreach ($asignaciones as $asignacion): ?>
            <div class="asignacion-card fade-in-up">
                <div class="asignacion-card-header">
                    <div class="expediente-number">
                        <i class="fas fa-file-alt me-1"></i>
                        <?php echo htmlspecialchars($asignacion['numero_expediente'] ?? 'N/A'); ?>
                    </div>
                    <div class="beneficiario-name">
                        <?php echo htmlspecialchars($asignacion['nombre_jefe'] . ' ' . $asignacion['apellido_jefe']); ?>
                    </div>
                    <div class="beneficiario-dni">
                        DNI: <?php echo htmlspecialchars($asignacion['dni_jefe']); ?>
                    </div>
                </div>

                <div class="asignacion-card-body">
                    <div class="ayuda-info">
                        <div class="ayuda-name">
                            <?php echo htmlspecialchars($asignacion['tipo_ayuda_nombre']); ?>
                        </div>
                        <div class="ayuda-cantidad">
                            Cantidad: <?php echo number_format($asignacion['cantidad'], 2); ?>
                        </div>
                    </div>

                    <?php if (!empty($asignacion['telefono'])): ?>
                    <div class="info-item">
                        <i class="fas fa-phone"></i>
                        <span><?php echo htmlspecialchars($asignacion['telefono']); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($asignacion['direccion'])): ?>
                    <div class="info-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($asignacion['direccion']); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="info-item">
                        <i class="fas fa-calendar"></i>
                        <span>Asignado: <?php echo formatearFecha($asignacion['fecha_asignacion']); ?></span>
                    </div>

                    <?php if ($asignacion['fecha_autorizacion']): ?>
                    <div class="info-item">
                        <i class="fas fa-check"></i>
                        <span>Autorizado: <?php echo formatearFecha($asignacion['fecha_autorizacion']); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($asignacion['fecha_entrega_real']): ?>
                    <div class="info-item">
                        <i class="fas fa-hand-holding"></i>
                        <span>Entregado: <?php echo formatearFecha($asignacion['fecha_entrega_real']); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($asignacion['motivo'])): ?>
                    <div class="motivo-text">
                        <strong>Motivo:</strong> <?php echo htmlspecialchars($asignacion['motivo']); ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($asignacion['observaciones'])): ?>
                    <div class="motivo-text">
                        <strong>Observaciones:</strong> <?php echo htmlspecialchars($asignacion['observaciones']); ?>
                    </div>
                    <?php endif; ?>

                    <div class="status-priority-row">
                        <span class="status-badge status-<?php echo $asignacion['estado']; ?>">
                            <?php echo ucfirst($asignacion['estado']); ?>
                        </span>
                        <span class="priority-badge priority-<?php echo $asignacion['prioridad'] ?? 'media'; ?>">
                            <?php echo ucfirst($asignacion['prioridad'] ?? 'media'); ?>
                        </span>
                    </div>
                </div>

                <div class="asignacion-card-actions">
                    <button class="btn-action btn-view" 
                            onclick="verDetalleAsignacion(<?php echo $asignacion['id']; ?>)"
                            title="Ver detalle">
                        <i class="fas fa-eye"></i>
                    </button>
                    
                    <?php if ($asignacion['estado'] === 'pendiente'): ?>
                        <button class="btn-action btn-edit" 
                                onclick="editarAsignacion(<?php echo $asignacion['id']; ?>)"
                                title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if ($user_role === 'admin' || $user_role === 'supervisor'): ?>
                        <button class="btn-action btn-success" 
                                onclick="cambiarEstado(<?php echo $asignacion['id']; ?>, 'autorizada')"
                                title="Autorizar">
                            <i class="fas fa-check"></i>
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($asignacion['estado'] === 'autorizada'): ?>
                        <button class="btn-action btn-success" 
                                onclick="cambiarEstado(<?php echo $asignacion['id']; ?>, 'entregada')"
                                title="Marcar como entregada">
                            <i class="fas fa-hand-holding"></i>
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($user_role === 'admin' && in_array($asignacion['estado'], ['pendiente', 'autorizada'])): ?>
                        <button class="btn-action btn-delete" 
                                onclick="cancelarAsignacion(<?php echo $asignacion['id']; ?>)"
                                title="Cancelar">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state fade-in-up">
            <i class="fas fa-hand-holding-heart"></i>
            <h3>No se encontraron asignaciones</h3>
            <p>No hay asignaciones registradas que coincidan con los criterios de búsqueda</p>
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#asignacionModal">
                <i class="fas fa-plus me-2"></i>Crear Primera Asignación
            </button>
        </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container fade-in-up">
            <div class="d-flex justify-content-between align-items-center w-100">
                <div class="text-muted">
                    Mostrando <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> 
                    de <?php echo number_format($total_records); ?> asignaciones
                </div>
                
                <nav>
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>&prioridad=<?php echo $prioridad_filter; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>&prioridad=<?php echo $prioridad_filter; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo $estado_filter; ?>&prioridad=<?php echo $prioridad_filter; ?>">
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
    <button class="fab" data-bs-toggle="modal" data-bs-target="#asignacionModal" title="Nueva asignación">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Modal para agregar/editar asignación -->
    <div class="modal fade" id="asignacionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-hand-holding-heart me-2"></i>
                        <span id="modalTitle">Nueva Asignación</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="asignacionForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="agregar">
                        <input type="hidden" name="asignacion_id" id="asignacion_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="familia_id" class="form-label">Familia Beneficiaria *</label>
                                    <select class="form-select" id="familia_id" name="familia_id" required>
                                        <option value="">Seleccionar familia...</option>
                                        <?php foreach ($familias as $familia): ?>
                                            <option value="<?php echo $familia['id']; ?>" <?php echo $familia_filter == $familia['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe'] . ' - DNI: ' . $familia['dni_jefe']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="tipo_ayuda_id" class="form-label">Tipo de Ayuda *</label>
                                    <select class="form-select" id="tipo_ayuda_id" name="tipo_ayuda_id" required>
                                        <option value="">Seleccionar tipo de ayuda...</option>
                                        <?php foreach ($tipos_ayuda as $ayuda): ?>
                                            <option value="<?php echo $ayuda['id']; ?>">
                                                <?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="cantidad" class="form-label">Cantidad *</label>
                                    <input type="number" class="form-control" id="cantidad" name="cantidad" 
                                           step="0.01" min="0.01" value="1" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="fecha_asignacion" class="form-label">Fecha de Asignación *</label>
                                    <input type="date" class="form-control" id="fecha_asignacion" name="fecha_asignacion" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="prioridad" class="form-label">Prioridad *</label>
                                    <select class="form-select" id="prioridad" name="prioridad" required>
                                        <option value="baja">Baja</option>
                                        <option value="media" selected>Media</option>
                                        <option value="alta">Alta</option>
                                        <option value="urgente">Urgente</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="motivo" class="form-label">Motivo de la Asignación *</label>
                            <textarea class="form-control" id="motivo" name="motivo" rows="3" 
                                      placeholder="Describa el motivo por el cual se asigna esta ayuda..." required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="observaciones" class="form-label">Observaciones</label>
                            <textarea class="form-control" id="observaciones" name="observaciones" rows="2" 
                                      placeholder="Observaciones adicionales (opcional)"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <span id="btnSubmitText">Crear Asignación</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para cambiar estado -->
    <div class="modal fade" id="estadoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Cambiar Estado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Confirma el cambio de estado de la asignación?</p>
                    <div id="estadoInfo"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="cambiar_estado">
                        <input type="hidden" name="asignacion_id" id="estadoAsignacionId">
                        <input type="hidden" name="nuevo_estado" id="nuevoEstado">
                        <button type="submit" class="btn btn-success" id="btnCambiarEstado">
                            <i class="fas fa-check me-2"></i>Confirmar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de detalle de asignación -->
    <div class="modal fade" id="detalleAsignacionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-file-alt me-2"></i>
                        Detalle de Asignación
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleAsignacionContent">
                    <!-- Contenido cargado dinámicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let asignacionIdActual = null;

        function cambiarEstado(id, estado) {
            document.getElementById('estadoAsignacionId').value = id;
            document.getElementById('nuevoEstado').value = estado;
            
            let mensaje = '';
            switch(estado) {
                case 'autorizada':
                    mensaje = 'La asignación será <strong>autorizada</strong> y podrá proceder a la entrega.';
                    break;
                case 'entregada':
                    mensaje = 'La asignación será marcada como <strong>entregada</strong> y se registrará la fecha de entrega.';
                    break;
            }
            
            document.getElementById('estadoInfo').innerHTML = mensaje;
            new bootstrap.Modal(document.getElementById('estadoModal')).show();
        }

        function cancelarAsignacion(id) {
            if (confirm('¿Está seguro que desea cancelar esta asignación?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="asignacion_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function editarAsignacion(id) {
            // Aquí puedes implementar la carga de datos para editar
            // Por ahora simplemente abre el modal
            document.getElementById('modalTitle').textContent = 'Editar Asignación';
            document.getElementById('btnSubmitText').textContent = 'Actualizar Asignación';
            document.getElementById('accion').value = 'editar';
            document.getElementById('asignacion_id').value = id;
            
            new bootstrap.Modal(document.getElementById('asignacionModal')).show();
        }

        function verDetalleAsignacion(id) {
            // Cargar detalle de asignación
            document.getElementById('detalleAsignacionContent').innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br><br>Cargando detalle...</div>';
            new bootstrap.Modal(document.getElementById('detalleAsignacionModal')).show();
            
            // Llamada AJAX para cargar los detalles
            fetch(`api/asignacion_detalle.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('detalleAsignacionContent').innerHTML = data.html;
                        
                        // Agregar botón de imprimir en el footer del modal
                        const modalFooter = document.querySelector('#detalleAsignacionModal .modal-footer');
                        modalFooter.innerHTML = `
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Cerrar
                            </button>
                            <button type="button" class="btn btn-primary" onclick="imprimirDetalle()">
                                <i class="fas fa-print me-2"></i>Imprimir PDF
                            </button>
                        `;
                    } else {
                        document.getElementById('detalleAsignacionContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error al cargar el detalle: ${data.error}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('detalleAsignacionContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error de conexión al cargar el detalle.
                        </div>
                    `;
                });
        }

        function imprimirDetalle() {
            // Mostrar elementos de impresión
            const firmaSection = document.querySelector('.firma-section');
            const sistemaInfo = document.querySelector('.sistema-info');
            
            if (firmaSection) firmaSection.style.display = 'block';
            if (sistemaInfo) sistemaInfo.style.display = 'block';
            
            // Crear una nueva ventana para impresión
            const printWindow = window.open('', '_blank');
            const modalContent = document.getElementById('detalleAsignacionContent').innerHTML;
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html lang="es">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Detalle de Asignación - Expediente</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        @media print {
                            body { 
                                font-size: 12px; 
                                line-height: 1.4;
                            }
                            .card { 
                                border: 1px solid #000 !important; 
                                break-inside: avoid;
                                margin-bottom: 1rem;
                            }
                            .card-header { 
                                background: #f8f9fa !important; 
                                color: #000 !important; 
                                -webkit-print-color-adjust: exact;
                                border-bottom: 1px solid #000 !important;
                            }
                            .badge {
                                border: 1px solid #000 !important;
                                -webkit-print-color-adjust: exact;
                            }
                            .timeline-item i {
                                -webkit-print-color-adjust: exact;
                            }
                            .alert {
                                border: 1px solid #000 !important;
                                -webkit-print-color-adjust: exact;
                            }
                            .firma-section {
                                page-break-inside: avoid;
                                margin-top: 2cm;
                            }
                            @page {
                                margin: 2cm;
                                size: A4;
                            }
                        }
                        
                        .timeline {
                            position: relative;
                            padding-left: 30px;
                        }
                        .timeline-item {
                            position: relative;
                            padding-bottom: 20px;
                            border-left: 2px solid #e9ecef;
                        }
                        .timeline-item:last-child {
                            border-left: none;
                        }
                        .timeline-item i {
                            position: absolute;
                            left: -25px;
                            top: 0;
                            background: white;
                            padding: 5px;
                            border-radius: 50%;
                            border: 2px solid #e9ecef;
                            color: #6c757d;
                        }
                        .timeline-item.completed i {
                            color: #198754;
                            border-color: #198754;
                        }
                        .timeline-item.active i {
                            color: #0d6efd;
                            border-color: #0d6efd;
                        }
                        .timeline-item.cancelled i {
                            color: #dc3545;
                            border-color: #dc3545;
                        }
                    </style>
                </head>
                <body>
                    <div class="container-fluid">
                        <div class="text-center mb-4">
                            <h2>SISTEMA DE DESARROLLO SOCIAL</h2>
                            <h3>MUNICIPALIDAD DE SAN FERNANDO</h3>
                            <h4>COMPROBANTE DE ASIGNACIÓN DE AYUDA</h4>
                        </div>
                        ${modalContent}
                    </div>
                </body>
                </html>
            `);
            
            printWindow.document.close();
            
            // Esperar a que cargue y luego imprimir
            printWindow.onload = function() {
                printWindow.focus();
                printWindow.print();
                printWindow.close();
            };
            
            // Ocultar elementos de impresión nuevamente
            setTimeout(() => {
                if (firmaSection) firmaSection.style.display = 'none';
                if (sistemaInfo) sistemaInfo.style.display = 'none';
            }, 500);
        }

        function exportarAsignaciones() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.open(`api/export_asignaciones.php?${params.toString()}`, '_blank');
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('asignacionModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('asignacionForm').reset();
            document.getElementById('modalTitle').textContent = 'Nueva Asignación';
            document.getElementById('btnSubmitText').textContent = 'Crear Asignación';
            document.getElementById('accion').value = 'agregar';
            document.getElementById('asignacion_id').value = '';
            document.getElementById('fecha_asignacion').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('prioridad').value = 'media';
            asignacionIdActual = null;
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