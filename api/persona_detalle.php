<?php
/**
 * API para obtener detalle completo de una persona
 * Usado para el modal de detalle con información extendida
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

session_start();
require '../includes/conexion.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'No autorizado'
    ]);
    exit;
}

// Verificar que se envió el ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'ID de persona requerido'
    ]);
    exit;
}

$persona_id = (int)$_GET['id'];

try {
    // Obtener datos completos de la persona
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            f.telefono as telefono_familia,
            f.direccion as direccion_familia,
            f.barrio,
            f.cantidad_integrantes,
            f.estado as estado_familia,
            f.fecha_registro as fecha_registro_familia
        FROM personas p
        LEFT JOIN familias f ON p.id_familia = f.id
        WHERE p.id = ?
    ");

    $stmt->execute([$persona_id]);
    $persona = $stmt->fetch();

    if (!$persona) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Persona no encontrada'
        ]);
        exit;
    }

    // Obtener otros integrantes de la familia (si pertenece a una)
    $otros_integrantes = [];
    if ($persona['id_familia']) {
        $stmt_integrantes = $pdo->prepare("
            SELECT id, nombre, apellido, cedula 
            FROM personas 
            WHERE id_familia = ? AND id != ?
            ORDER BY nombre
        ");
        $stmt_integrantes->execute([$persona['id_familia'], $persona_id]);
        $otros_integrantes = $stmt_integrantes->fetchAll();
    }

    // Obtener asignaciones relacionadas (si hay alguna vinculación por familia)
    $asignaciones_relacionadas = [];
    if ($persona['id_familia']) {
        $stmt_asignaciones = $pdo->prepare("
            SELECT 
                a.id,
                a.numero_expediente,
                a.fecha_asignacion,
                a.estado,
                a.prioridad,
                ay.nombre_ayuda,
                a.cantidad
            FROM asignaciones a
            LEFT JOIN ayudas ay ON a.id_ayuda = ay.id
            WHERE a.familia_id = ?
            ORDER BY a.fecha_asignacion DESC
            LIMIT 5
        ");
        $stmt_asignaciones->execute([$persona['id_familia']]);
        $asignaciones_relacionadas = $stmt_asignaciones->fetchAll();
    }

    // Generar HTML para el modal
    ob_start();
?>

<div class="persona-detalle-content">
    <!-- Header con información básica -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-user me-2"></i>
                        Información Personal
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Nombre Completo:</strong></td>
                                    <td><?php echo htmlspecialchars($persona['nombre'] . ' ' . ($persona['apellido'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Cédula/DNI:</strong></td>
                                    <td class="font-monospace"><?php echo htmlspecialchars($persona['cedula']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Teléfono:</strong></td>
                                    <td>
                                        <?php if (!empty($persona['telefono'])): ?>
                                            <i class="fas fa-phone text-success me-2"></i>
                                            <?php echo htmlspecialchars($persona['telefono']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">No registrado</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Dirección:</strong></td>
                                    <td>
                                        <?php if (!empty($persona['direccion'])): ?>
                                            <i class="fas fa-map-marker-alt text-danger me-2"></i>
                                            <?php echo htmlspecialchars($persona['direccion']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">No registrada</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Fecha de Registro:</strong></td>
                                    <td>
                                        <i class="fas fa-calendar me-2 text-info"></i>
                                        <?php echo formatearFechaHora($persona['fecha_registro']); ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <div class="text-center">
                                <div class="persona-avatar-big mb-3">
                                    <?php echo strtoupper(substr($persona['nombre'], 0, 1) . substr($persona['apellido'] ?? '', 0, 1)); ?>
                                </div>
                                <h4><?php echo htmlspecialchars($persona['nombre'] . ' ' . ($persona['apellido'] ?? '')); ?></h4>
                                <p class="text-muted font-monospace">ID: <?php echo $persona['id']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Estado</h6>
                </div>
                <div class="card-body text-center">
                    <?php if ($persona['id_familia']): ?>
                        <div class="mb-3">
                            <i class="fas fa-home fa-3x text-success mb-2"></i>
                            <h6 class="text-success">Con Familia Asignada</h6>
                            <small class="text-muted">Pertenece a un grupo familiar</small>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <i class="fas fa-user fa-3x text-warning mb-2"></i>
                            <h6 class="text-warning">Sin Familia Asignada</h6>
                            <small class="text-muted">Registro individual</small>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <?php if (!empty($persona['telefono'])): ?>
                            <span class="badge bg-success me-1">Con Teléfono</span>
                        <?php endif; ?>
                        <?php if (!empty($persona['direccion'])): ?>
                            <span class="badge bg-info me-1">Con Dirección</span>
                        <?php endif; ?>
                        <?php if (empty($persona['telefono']) && empty($persona['direccion'])): ?>
                            <span class="badge bg-warning">Datos Incompletos</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Información de la familia -->
    <?php if ($persona['id_familia']): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="fas fa-home me-2"></i>Información del Grupo Familiar</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-success">Datos de la Familia</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Jefe de Familia:</strong></td>
                                    <td><?php echo htmlspecialchars($persona['nombre_jefe'] . ' ' . $persona['apellido_jefe']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>DNI Jefe:</strong></td>
                                    <td class="font-monospace"><?php echo htmlspecialchars($persona['dni_jefe']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Teléfono Familiar:</strong></td>
                                    <td><?php echo htmlspecialchars($persona['telefono_familia'] ?? 'No registrado'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Dirección Familiar:</strong></td>
                                    <td><?php echo htmlspecialchars($persona['direccion_familia'] ?? 'No registrada'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Barrio:</strong></td>
                                    <td><?php echo htmlspecialchars($persona['barrio'] ?? 'No especificado'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Total Integrantes:</strong></td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $persona['cantidad_integrantes']; ?> personas</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Estado Familia:</strong></td>
                                    <td>
                                        <span class="badge <?php echo $persona['estado_familia'] === 'activa' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($persona['estado_familia']); ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <?php if (!empty($otros_integrantes)): ?>
                            <h6 class="text-success">Otros Integrantes de la Familia</h6>
                            <div class="list-group list-group-flush">
                                <?php foreach ($otros_integrantes as $integrante): ?>
                                <div class="list-group-item border-0 px-0">
                                    <div class="d-flex align-items-center">
                                        <div class="persona-avatar-small me-3">
                                            <?php echo strtoupper(substr($integrante['nombre'], 0, 1) . substr($integrante['apellido'] ?? '', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($integrante['nombre'] . ' ' . ($integrante['apellido'] ?? '')); ?></div>
                                            <?php if (!empty($integrante['cedula'])): ?>
                                                <small class="text-muted font-monospace">Cédula: <?php echo htmlspecialchars($integrante['cedula']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-user-friends fa-2x mb-2 opacity-50"></i>
                                <p class="mb-0">Es el único integrante registrado</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Asignaciones relacionadas -->
    <?php if (!empty($asignaciones_relacionadas)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0"><i class="fas fa-gift me-2"></i>Asignaciones Familiares Recientes</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Expediente</th>
                                    <th>Ayuda</th>
                                    <th>Cantidad</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($asignaciones_relacionadas as $asignacion): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($asignacion['numero_expediente'] ?? 'N/A'); ?></code></td>
                                    <td><?php echo htmlspecialchars($asignacion['nombre_ayuda'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($asignacion['cantidad'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo obtenerColorEstado($asignacion['estado']); ?>">
                                            <?php echo ucfirst($asignacion['estado']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatearFecha($asignacion['fecha_asignacion']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Acciones rápidas -->
    <div class="row">
        <div class="col-12">
            <div class="card border-secondary">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="fas fa-bolt me-2"></i>Acciones Rápidas</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2 d-md-flex">
                        <?php if ($persona['id_familia']): ?>
                        <a href="familias.php?search=<?php echo urlencode($persona['dni_jefe']); ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-home me-2"></i>Ver Familia Completa
                        </a>
                        <a href="asignaciones.php?familia=<?php echo $persona['id_familia']; ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-gift me-2"></i>Ver Asignaciones Familiares
                        </a>
                        <?php else: ?>
                        <a href="familias.php" class="btn btn-info btn-sm">
                            <i class="fas fa-plus me-2"></i>Asignar a Familia
                        </a>
                        <?php endif; ?>
                        <button class="btn btn-warning btn-sm" onclick="editarPersonaDesdeDetalle()">
                            <i class="fas fa-edit me-2"></i>Editar Datos
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Estilos específicos para el detalle de persona */
.persona-detalle-content .persona-avatar-big {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2rem;
    font-weight: 700;
    margin: 0 auto;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.persona-detalle-content .persona-avatar-small {
    width: 35px;
    height: 35px;
    background: linear-gradient(135deg, #059669, #047857);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.8rem;
    font-weight: 600;
}

.persona-detalle-content .card {
    transition: all 0.3s ease;
}

.persona-detalle-content .card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
</style>

<?php
    $html = ob_get_clean();

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'html' => $html,
        'persona' => $persona,
        'otros_integrantes' => $otros_integrantes,
        'asignaciones_relacionadas' => $asignaciones_relacionadas
    ]);
    
} catch (PDOException $e) {
    error_log("Error en API persona_detalle: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
?>