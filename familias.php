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
                                
                                registrarLog($pdo, 'familias', $familia_id, 'actualizar', 
                                    "Familia actualizada: $nombre_jefe $apellido_jefe", $_SESSION['user_id']);
                                
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
                                registrarLog($pdo, 'familias', $nuevo_id, 'crear', 
                                    "Nueva familia registrada: $nombre_jefe $apellido_jefe", $_SESSION['user_id']);
                                
                                $_SESSION['mensaje'] = "Familia registrada correctamente.";
                            }
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
                    }
                }
                break;
                
            case 'eliminar':
                $familia_id = $_POST['familia_id'];
                try {
                    // Verificar si tiene asignaciones
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM asignaciones WHERE familia_id = ? OR id_persona IN (SELECT id FROM personas WHERE id_familia = ?)");
                    $stmt_check->execute([$familia_id, $familia_id]);
                    
                    if ($stmt_check->fetchColumn() > 0) {
                        $_SESSION['error'] = "No se puede eliminar la familia porque tiene asignaciones asociadas.";
                    } else {
                        $stmt = $pdo->prepare("UPDATE familias SET estado = 'inactiva' WHERE id = ?");
                        $stmt->execute([$familia_id]);
                        
                        registrarLog($pdo, 'familias', $familia_id, 'eliminar', 
                            "Familia desactivada", $_SESSION['user_id']);
                        
                        $_SESSION['mensaje'] = "Familia desactivada correctamente.";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error al eliminar familia: " . $e->getMessage();
                }
                break;
        }
    }
    header("Location: familias.php");
    exit();
}

// Obtener familias con paginación y búsqueda
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$estado_filter = $_GET['estado'] ?? 'activa';

$where_conditions = ["estado = ?"];
$params = [$estado_filter];

if (!empty($search)) {
    $where_conditions[] = "(nombre_jefe LIKE ? OR apellido_jefe LIKE ? OR dni_jefe LIKE ? OR direccion LIKE ? OR barrio LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
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
            (SELECT COUNT(*) FROM asignaciones a 
             JOIN personas p ON a.id_persona = p.id 
             WHERE p.id_familia = f.id) as total_asignaciones,
            (SELECT MAX(a.fecha_asignacion) FROM asignaciones a 
             JOIN personas p ON a.id_persona = p.id 
             WHERE p.id_familia = f.id) as ultima_asignacion
        FROM familias f 
        $where_clause
        ORDER BY f.fecha_registro DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $familias = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error al cargar familias: " . $e->getMessage();
    $familias = [];
}

// Obtener familia para editar
$familia_editar = null;
if (isset($_GET['editar'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM familias WHERE id = ?");
        $stmt->execute([$_GET['editar']]);
        $familia_editar = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Error al cargar familia para edición.";
    }
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
    <link href="assets/css/sistema.css" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <div class="header-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="mb-1"><i class="fas fa-users me-2 text-primary"></i>Gestión de Familias</h1>
                    <p class="text-muted mb-0">Registro y administración de familias beneficiarias</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#familiaModal">
                    <i class="fas fa-plus me-2"></i>Nueva Familia
                </button>
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
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Buscar por nombre, DNI, dirección o barrio..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="estado">
                            <option value="activa" <?php echo $estado_filter === 'activa' ? 'selected' : ''; ?>>Familias Activas</option>
                            <option value="inactiva" <?php echo $estado_filter === 'inactiva' ? 'selected' : ''; ?>>Familias Inactivas</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-outline-primary flex-fill">
                                <i class="fas fa-search me-1"></i>Buscar
                            </button>
                            <a href="familias.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla de familias -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Listado de Familias 
                    <span class="badge bg-primary ms-2"><?php echo number_format($total_records); ?></span>
                </h5>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-success" onclick="exportarDatos('excel')">
                        <i class="fas fa-file-excel me-1"></i>Excel
                    </button>
                    <button class="btn btn-outline-danger" onclick="exportarDatos('pdf')">
                        <i class="fas fa-file-pdf me-1"></i>PDF
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Jefe de Familia</th>
                                <th>DNI</th>
                                <th>Contacto</th>
                                <th>Ubicación</th>
                                <th>Integrantes</th>
                                <th>Asignaciones</th>
                                <th>Estado</th>
                                <th>Última Actividad</th>
                                <th width="120">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($familias)): ?>
                                <?php foreach ($familias as $familia): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2">
                                                <?php echo strtoupper(substr($familia['nombre_jefe'], 0, 1) . substr($familia['apellido_jefe'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe']); ?></strong>
                                                <br><small class="text-muted">Reg: <?php echo formatearFecha($familia['fecha_registro']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="font-monospace"><?php echo htmlspecialchars($familia['dni_jefe']); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($familia['telefono'])): ?>
                                            <i class="fas fa-phone text-success me-1"></i>
                                            <?php echo htmlspecialchars($familia['telefono']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Sin teléfono</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <?php if (!empty($familia['direccion'])): ?>
                                                <small class="d-block"><?php echo htmlspecialchars($familia['direccion']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($familia['barrio'])): ?>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($familia['barrio']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $familia['cantidad_integrantes']; ?> persona<?php echo $familia['cantidad_integrantes'] != 1 ? 's' : ''; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($familia['total_asignaciones'] > 0): ?>
                                            <span class="badge bg-success"><?php echo $familia['total_asignaciones']; ?></span>
                                            <?php if ($familia['ultima_asignacion']): ?>
                                                <br><small class="text-muted">Última: <?php echo formatearFecha($familia['ultima_asignacion']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Sin asignaciones</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $familia['estado'] === 'activa' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($familia['estado']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo formatearFecha($familia['fecha_registro']); ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary btn-sm" 
                                                    onclick="editarFamilia(<?php echo $familia['id']; ?>)"
                                                    title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="asignaciones.php?familia=<?php echo $familia['id']; ?>" 
                                               class="btn btn-outline-success btn-sm" title="Ver Asignaciones">
                                                <i class="fas fa-gift"></i>
                                            </a>
                                            <?php if ($user_role === 'admin'): ?>
                                            <button class="btn btn-outline-danger btn-sm" 
                                                    onclick="eliminarFamilia(<?php echo $familia['id']; ?>, '<?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe']); ?>')"
                                                    title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No se encontraron familias</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#familiaModal">
                                            <i class="fas fa-plus me-2"></i>Registrar Primera Familia
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
            <div class="card-footer">
                <nav aria-label="Paginación de familias">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
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
                <div class="text-center mt-2">
                    <small class="text-muted">
                        Mostrando <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> 
                        de <?php echo number_format($total_records); ?> familias
                    </small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

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
                        <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                        
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
                        <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
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
            document.getElementById('dni_jefe').addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '');
            });
        });

        function editarFamilia(id) {
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

        function eliminarFamilia(id, nombre) {
            document.getElementById('familiaEliminar').textContent = nombre;
            document.getElementById('familiaIdEliminar').value = id;
            new bootstrap.Modal(document.getElementById('eliminarModal')).show();
        }

        function exportarDatos(formato) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', formato);
            window.open(`api/export_familias.php?${params.toString()}`, '_blank');
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('familiaModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('familiaForm').reset();
            document.getElementById('modalTitle').textContent = 'Nueva Familia';
            document.getElementById('btnSubmitText').textContent = 'Guardar Familia';
            document.getElementById('accion').value = 'agregar';
            document.getElementById('familia_id').value = '';
        });

        <?php if ($familia_editar): ?>
        // Si hay una familia para editar, abrir el modal automáticamente
        document.addEventListener('DOMContentLoaded', function() {
            editarFamilia(<?php echo $familia_editar['id']; ?>);
        });
        <?php endif; ?>
    </script>
</body>
</html>