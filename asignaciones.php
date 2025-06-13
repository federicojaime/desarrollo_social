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
                                    familia_id = ?, tipo_ayuda_id = ?, cantidad = ?, motivo = ?, 
                                    observaciones = ?, fecha_asignacion = ?, prioridad = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([
                                $familia_id, $tipo_ayuda_id, $cantidad, $motivo, 
                                $observaciones, $fecha_asignacion, $prioridad, $asignacion_id
                            ]);
                            
                            registrarLog($pdo, 'asignaciones', $asignacion_id, 'actualizar', 
                                "Asignación actualizada", $_SESSION['user_id']);
                            
                            $_SESSION['mensaje'] = "Asignación actualizada correctamente.";
                        } else {
                            // Nueva asignación
                            $numero_expediente = generarNumeroExpediente($pdo);
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO asignaciones (
                                    familia_id, tipo_ayuda_id, cantidad, motivo, observaciones, 
                                    fecha_asignacion, prioridad, numero_expediente, usuario_asignador, 
                                    estado
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
                            ");
                            $stmt->execute([
                                $familia_id, $tipo_ayuda_id, $cantidad, $motivo, $observaciones, 
                                $fecha_asignacion, $prioridad, $numero_expediente, $_SESSION['user_id']
                            ]);
                            
                            $nuevo_id = $pdo->lastInsertId();
                            registrarLog($pdo, 'asignaciones', $nuevo_id, 'crear', 
                                "Nueva asignación creada: $numero_expediente", $_SESSION['user_id']);
                            
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
                    
                    registrarLog($pdo, 'asignaciones', $asignacion_id, $nuevo_estado, 
                        "Estado cambiado a: $nuevo_estado", $_SESSION['user_id']);
                    
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
                        
                        registrarLog($pdo, 'asignaciones', $asignacion_id, 'eliminar', 
                            "Asignación cancelada", $_SESSION['user_id']);
                        
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
$limit = 20;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$estado_filter = $_GET['estado'] ?? '';
$prioridad_filter = $_GET['prioridad'] ?? '';
$familia_filter = $_GET['familia'] ?? '';

// Construir consulta con filtros
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(f.nombre_jefe LIKE ? OR f.apellido_jefe LIKE ? OR f.dni_jefe LIKE ? OR a.numero_expediente LIKE ? OR ta.nombre LIKE ?)";
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
        JOIN familias f ON a.familia_id = f.id
        LEFT JOIN tipos_ayuda ta ON a.tipo_ayuda_id = ta.id
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
            COALESCE(ta.nombre, ay.nombre_ayuda, 'Ayuda no especificada') as tipo_ayuda_nombre,
            ca.nombre as categoria_nombre,
            ca.color as categoria_color,
            ca.icono as categoria_icono,
            ua.nombre as asignador_nombre,
            ua.apellido as asignador_apellido,
            uu.nombre as autorizador_nombre,
            uu.apellido as autorizador_apellido,
            ue.nombre as entregador_nombre,
            ue.apellido as entregador_apellido
        FROM asignaciones a
        JOIN familias f ON a.familia_id = f.id
        LEFT JOIN tipos_ayuda ta ON a.tipo_ayuda_id = ta.id
        LEFT JOIN ayudas ay ON a.id_ayuda = ay.id
        LEFT JOIN categorias_ayuda ca ON ta.categoria_id = ca.id
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
        SELECT ta.*, ca.nombre as categoria_nombre 
        FROM tipos_ayuda ta
        JOIN categorias_ayuda ca ON ta.categoria_id = ca.id
        WHERE ta.activo = 1
        ORDER BY ca.nombre, ta.nombre
    ");
    $tipos_ayuda = $stmt_ayudas->fetchAll();
    
    // Agrupar tipos de ayuda por categoría
    $ayudas_por_categoria = [];
    foreach ($tipos_ayuda as $ayuda) {
        $ayudas_por_categoria[$ayuda['categoria_nombre']][] = $ayuda;
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar asignaciones: " . $e->getMessage();
    $asignaciones = [];
    $familias = [];
    $ayudas_por_categoria = [];
}

// Obtener estadísticas rápidas
try {
    $stats = [];
    $stmt = $pdo->query("SELECT estado, COUNT(*) as cantidad FROM asignaciones GROUP BY estado");
    while ($row = $stmt->fetch()) {
        $stats[$row['estado']] = $row['cantidad'];
    }
} catch (PDOException $e) {
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
    <link href="assets/css/sistema.css" rel="stylesheet">
    <style>
        .priority-urgente { border-left: 4px solid #dc3545; }
        .priority-alta { border-left: 4px solid #fd7e14; }
        .priority-media { border-left: 4px solid #0d6efd; }
        .priority-baja { border-left: 4px solid #6c757d; }
        
        .estado-timeline {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
        }
        
        .estado-step {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            background: #e9ecef;
            color: #6c757d;
        }
        
        .estado-step.active {
            background: #0d6efd;
            color: white;
        }
        
        .estado-step.completed {
            background: #198754;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <div class="header-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="mb-1"><i class="fas fa-hand-holding-heart me-2 text-primary"></i>Gestión de Asignaciones</h1>
                    <p class="text-muted mb-0">Administración de ayudas asignadas a familias</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#asignacionModal">
                    <i class="fas fa-plus me-2"></i>Nueva Asignación
                </button>
            </div>
        </div>

        <!-- Estadísticas rápidas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h3 class="text-warning"><?php echo $stats['pendiente'] ?? 0; ?></h3>
                        <small class="text-muted">Pendientes</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h3 class="text-info"><?php echo $stats['autorizada'] ?? 0; ?></h3>
                        <small class="text-muted">Autorizadas</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h3 class="text-success"><?php echo $stats['entregada'] ?? 0; ?></h3>
                        <small class="text-muted">Entregadas</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h3 class="text-danger"><?php echo $stats['cancelada'] ?? 0; ?></h3>
                        <small class="text-muted">Canceladas</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alertas -->
        <?php if ($mensaje): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filtros y búsqueda -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Buscar por familia, DNI o expediente..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="estado">
                            <option value="">Todos los estados</option>
                            <?php foreach (obtenerEstadosAsignacion() as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $estado_filter === $key ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="prioridad">
                            <option value="">Todas las prioridades</option>
                            <?php foreach (obtenerPrioridades() as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $prioridad_filter === $key ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-outline-primary flex-fill">
                                <i class="fas fa-search me-1"></i>Buscar
                            </button>
                            <a href="asignaciones.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="btn-group w-100">
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="exportarDatos('excel')">
                                <i class="fas fa-file-excel me-1"></i>Excel
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="exportarDatos('pdf')">
                                <i class="fas fa-file-pdf me-1"></i>PDF
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de asignaciones -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Asignaciones 
                    <span class="badge bg-primary ms-2"><?php echo number_format($total_records); ?></span>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($asignaciones)): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($asignaciones as $asignacion): ?>
                        <div class="list-group-item priority-<?php echo $asignacion['prioridad']; ?>">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3">
                                            <?php echo strtoupper(substr($asignacion['nombre_jefe'], 0, 1) . substr($asignacion['apellido_jefe'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($asignacion['nombre_jefe'] . ' ' . $asignacion['apellido_jefe']); ?></h6>
                                            <small class="text-muted">DNI: <?php echo htmlspecialchars($asignacion['dni_jefe']); ?></small>
                                            <br><small class="text-muted font-monospace"><?php echo htmlspecialchars($asignacion['numero_expediente']); ?></small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div>
                                        <strong><?php echo htmlspecialchars($asignacion['tipo_ayuda_nombre']); ?></strong>
                                        <br><span class="text-muted">Cantidad: <?php echo number_format($asignacion['cantidad'], 2); ?></span>
                                        <?php if (!empty($asignacion['categoria_nombre'])): ?>
                                            <br><small class="badge" style="background-color: <?php echo $asignacion['categoria_color']; ?>">
                                                <i class="<?php echo $asignacion['categoria_icono']; ?> me-1"></i>
                                                <?php echo htmlspecialchars($asignacion['categoria_nombre']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <div class="text-center">
                                        <span class="badge bg-<?php echo obtenerColorEstado($asignacion['estado']); ?> d-block mb-1">
                                            <?php echo ucfirst($asignacion['estado']); ?>
                                        </span>
                                        <span class="badge bg-<?php echo obtenerColorPrioridad($asignacion['prioridad']); ?>">
                                            <?php echo ucfirst($asignacion['prioridad']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <small class="text-muted d-block">Asignado: <?php echo formatearFecha($asignacion['fecha_asignacion']); ?></small>
                                    <?php if ($asignacion['fecha_autorizacion']): ?>
                                        <small class="text-muted d-block">Autorizado: <?php echo formatearFecha($asignacion['fecha_autorizacion']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($asignacion['fecha_entrega_real']): ?>
                                        <small class="text-muted d-block">Entregado: <?php echo formatearFecha($asignacion['fecha_entrega_real']); ?></small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-2">
                                    <div class="btn-group btn-group-sm w-100">
                                        <button class="btn btn-outline-primary" onclick="verDetalle(<?php echo $asignacion['id']; ?>)" title="Ver detalle">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <?php if ($asignacion['estado'] === 'pendiente'): ?>
                                            <button class="btn btn-outline-warning" onclick="editarAsignacion(<?php echo $asignacion['id']; ?>)" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($user_role === 'admin'): ?>
                                            <button class="btn btn-outline-success" onclick="cambiarEstado(<?php echo $asignacion['id']; ?>, 'autorizada')" title="Autorizar">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($asignacion['estado'] === 'autorizada'): ?>
                                            <button class="btn btn-outline-success" onclick="cambiarEstado(<?php echo $asignacion['id']; ?>, 'entregada')" title="Marcar como entregada">
                                                <i class="fas fa-hand-holding"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($user_role === 'admin' && in_array($asignacion['estado'], ['pendiente', 'autorizada'])): ?>
                                            <button class="btn btn-outline-danger" onclick="cancelarAsignacion(<?php echo $asignacion['id']; ?>)" title="Cancelar">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($asignacion['motivo']) || !empty($asignacion['observaciones'])): ?>
                            <div class="row mt-2">
                                <div class="col-12">
                                    <?php if (!empty($asignacion['motivo'])): ?>
                                        <small class="text-muted"><strong>Motivo:</strong> <?php echo htmlspecialchars($asignacion['motivo']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($asignacion['observaciones'])): ?>
                                        <br><small class="text-muted"><strong>Observaciones:</strong> <?php echo htmlspecialchars($asignacion['observaciones']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-hand-holding-heart fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No se encontraron asignaciones</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#asignacionModal">
                            <i class="fas fa-plus me-2"></i>Crear Primera Asignación
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm justify-content-center mb-0">
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
                <div class="text-center mt-2">
                    <small class="text-muted">
                        Mostrando <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> 
                        de <?php echo number_format($total_records); ?> asignaciones
                    </small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

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
                        <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="familia_id" class="form-label">Familia Beneficiaria *</label>
                                    <select class="form-select" id="familia_id" name="familia_id" required>
                                        <option value="">Seleccionar familia...</option>
                                        <?php foreach ($familias as $familia): ?>
                                            <option value="<?php echo $familia['id']; ?>">
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
                                        <?php foreach ($ayudas_por_categoria as $categoria => $ayudas): ?>
                                            <optgroup label="<?php echo htmlspecialchars($categoria); ?>">
                                                <?php foreach ($ayudas as $ayuda): ?>
                                                    <option value="<?php echo $ayuda['id']; ?>">
                                                        <?php echo htmlspecialchars($ayuda['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
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
                                        <?php foreach (obtenerPrioridades() as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $key === 'media' ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
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
                        <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                        <button type="submit" class="btn btn-success" id="btnCambiarEstado">
                            <i class="fas fa-check me-2"></i>Confirmar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
                    <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function exportarDatos(formato) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', formato);
            window.open(`api/export_asignaciones.php?${params.toString()}`, '_blank');
        }

        // Pre-seleccionar familia si viene del parámetro URL
        <?php if (!empty($familia_filter)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const familiaSelect = document.getElementById('familia_id');
            if (familiaSelect) {
                familiaSelect.value = '<?php echo $familia_filter; ?>';
            }
        });
        <?php endif; ?>

        // Limpiar formulario al cerrar modal
        document.getElementById('asignacionModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('asignacionForm').reset();
            document.getElementById('modalTitle').textContent = 'Nueva Asignación';
            document.getElementById('btnSubmitText').textContent = 'Crear Asignación';
            document.getElementById('accion').value = 'agregar';
            document.getElementById('asignacion_id').value = '';
            document.getElementById('fecha_asignacion').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('prioridad').value = 'media';
        });
    </script>
</body>
</html>