<?php
/**
 * API para obtener detalle completo de una asignación
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
        'error' => 'ID de asignación requerido'
    ]);
    exit;
}

$asignacion_id = (int)$_GET['id'];

try {
    // Obtener datos completos de la asignación
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            f.telefono,
            f.direccion,
            f.barrio,
            f.cantidad_integrantes,
            ay.nombre_ayuda,
            ay.descripcion as ayuda_descripcion,
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
        WHERE a.id = ?
    ");

    $stmt->execute([$asignacion_id]);
    $asignacion = $stmt->fetch();

    if (!$asignacion) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Asignación no encontrada'
        ]);
        exit;
    }

    // Obtener integrantes de la familia
    $stmt_personas = $pdo->prepare("
        SELECT nombre, apellido, cedula 
        FROM personas 
        WHERE id_familia = ?
        ORDER BY nombre
    ");
    $stmt_personas->execute([$asignacion['familia_id']]);
    $integrantes = $stmt_personas->fetchAll();

    // Generar HTML para el modal
    ob_start();
?>

<div class="asignacion-detalle-content">
    <!-- Header con información básica -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-file-alt me-2"></i>
                        Expediente: <?php echo htmlspecialchars($asignacion['numero_expediente'] ?? 'N/A'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary">Información del Beneficiario</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Nombre Completo:</strong></td>
                                    <td><?php echo htmlspecialchars($asignacion['nombre_jefe'] . ' ' . $asignacion['apellido_jefe']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>DNI:</strong></td>
                                    <td><?php echo htmlspecialchars($asignacion['dni_jefe']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Teléfono:</strong></td>
                                    <td><?php echo htmlspecialchars($asignacion['telefono'] ?? 'No registrado'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Dirección:</strong></td>
                                    <td><?php echo htmlspecialchars($asignacion['direccion'] ?? 'No registrada'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Barrio:</strong></td>
                                    <td><?php echo htmlspecialchars($asignacion['barrio'] ?? 'No especificado'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Integrantes:</strong></td>
                                    <td><?php echo $asignacion['cantidad_integrantes']; ?> persona(s)</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Información de la Asignación</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Ayuda Asignada:</strong></td>
                                    <td><?php echo htmlspecialchars($asignacion['nombre_ayuda']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Cantidad:</strong></td>
                                    <td><?php echo number_format($asignacion['cantidad'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Estado:</strong></td>
                                    <td>
                                        <span class="badge bg-<?php echo obtenerColorEstado($asignacion['estado']); ?>">
                                            <?php echo ucfirst($asignacion['estado']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Prioridad:</strong></td>
                                    <td>
                                        <span class="badge bg-<?php echo obtenerColorPrioridad($asignacion['prioridad']); ?>">
                                            <?php echo ucfirst($asignacion['prioridad']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Fecha Asignación:</strong></td>
                                    <td><?php echo formatearFecha($asignacion['fecha_asignacion']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Fecha Creación:</strong></td>
                                    <td><?php echo formatearFechaHora($asignacion['fecha_creacion']); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-cogs me-2"></i>Estado del Trámite</h6>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <div class="timeline-item <?php echo $asignacion['estado'] === 'pendiente' ? 'active' : 'completed'; ?>">
                            <i class="fas fa-plus-circle"></i>
                            <div>
                                <strong>Creado</strong>
                                <br><small><?php echo formatearFechaHora($asignacion['fecha_creacion']); ?></small>
                                <?php if ($asignacion['asignador_nombre']): ?>
                                    <br><small>Por: <?php echo htmlspecialchars($asignacion['asignador_nombre'] . ' ' . $asignacion['asignador_apellido']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (in_array($asignacion['estado'], ['autorizada', 'entregada'])): ?>
                        <div class="timeline-item <?php echo $asignacion['estado'] === 'autorizada' ? 'active' : 'completed'; ?>">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Autorizado</strong>
                                <br><small><?php echo formatearFechaHora($asignacion['fecha_autorizacion']); ?></small>
                                <?php if ($asignacion['autorizador_nombre']): ?>
                                    <br><small>Por: <?php echo htmlspecialchars($asignacion['autorizador_nombre'] . ' ' . $asignacion['autorizador_apellido']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($asignacion['estado'] === 'entregada'): ?>
                        <div class="timeline-item active">
                            <i class="fas fa-hand-holding"></i>
                            <div>
                                <strong>Entregado</strong>
                                <br><small><?php echo formatearFechaHora($asignacion['fecha_entrega_real']); ?></small>
                                <?php if ($asignacion['entregador_nombre']): ?>
                                    <br><small>Por: <?php echo htmlspecialchars($asignacion['entregador_nombre'] . ' ' . $asignacion['entregador_apellido']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($asignacion['estado'] === 'cancelada'): ?>
                        <div class="timeline-item cancelled">
                            <i class="fas fa-times-circle"></i>
                            <div>
                                <strong>Cancelado</strong>
                                <br><small><?php echo formatearFechaHora($asignacion['fecha_actualizacion']); ?></small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Motivo y observaciones -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0"><i class="fas fa-comment-alt me-2"></i>Motivo de la Asignación</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0"><?php echo htmlspecialchars($asignacion['motivo'] ?? 'No especificado'); ?></p>
                </div>
            </div>
        </div>
        <?php if (!empty($asignacion['observaciones'])): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Observaciones</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0"><?php echo htmlspecialchars($asignacion['observaciones']); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Integrantes de la familia -->
    <?php if (!empty($integrantes)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="fas fa-users me-2"></i>Integrantes del Grupo Familiar</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nombre</th>
                                    <th>Apellido</th>
                                    <th>Cédula/DNI</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($integrantes as $index => $persona): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($persona['nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($persona['apellido'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($persona['cedula'] ?? 'No registrada'); ?></td>
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

    <!-- Sección de firmas para impresión -->
    <div class="firma-section d-print-flex" style="display: none;">
        <div class="row mt-5 pt-4" style="border-top: 2px solid #dee2e6;">
            <div class="col-md-6">
                <div class="text-center">
                    <div style="border-bottom: 2px solid #000; margin-bottom: 10px; height: 60px;"></div>
                    <strong>FIRMA DEL BENEFICIARIO</strong>
                    <br><small>Aclaración: <?php echo htmlspecialchars($asignacion['nombre_jefe'] . ' ' . $asignacion['apellido_jefe']); ?></small>
                    <br><small>DNI: <?php echo htmlspecialchars($asignacion['dni_jefe']); ?></small>
                    <br><small>Fecha: ________________</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="text-center">
                    <div style="border-bottom: 2px solid #000; margin-bottom: 10px; height: 60px;"></div>
                    <strong>FIRMA DEL FUNCIONARIO</strong>
                    <br><small>Aclaración: _________________________</small>
                    <br><small>Cargo: _____________________________</small>
                    <br><small>Fecha: ________________</small>
                </div>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <strong>IMPORTANTE:</strong> El beneficiario declara haber recibido la ayuda detallada en este documento en perfecto estado y en la cantidad especificada. Se compromete a hacer uso responsable de la misma.
                </div>
            </div>
        </div>
    </div>

    <!-- Información del sistema (pie de página para impresión) -->
    <div class="sistema-info d-print-block mt-4" style="display: none;">
        <hr>
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    <strong>Sistema de Desarrollo Social</strong><br>
                    Municipalidad de San Fernando<br>
                    Fecha de impresión: <?php echo date('d/m/Y H:i:s'); ?>
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small class="text-muted">
                    Expediente: <?php echo htmlspecialchars($asignacion['numero_expediente'] ?? 'N/A'); ?><br>
                    Usuario: <?php echo htmlspecialchars($_SESSION['nombre'] . ' ' . $_SESSION['apellido']); ?><br>
                    ID Asignación: <?php echo $asignacion['id']; ?>
                </small>
            </div>
        </div>
    </div>
</div>

<style>
/* Estilos para el detalle */
.asignacion-detalle-content .timeline {
    position: relative;
    padding-left: 30px;
}

.asignacion-detalle-content .timeline-item {
    position: relative;
    padding-bottom: 20px;
    border-left: 2px solid #e9ecef;
}

.asignacion-detalle-content .timeline-item:last-child {
    border-left: none;
}

.asignacion-detalle-content .timeline-item i {
    position: absolute;
    left: -25px;
    top: 0;
    background: white;
    padding: 5px;
    border-radius: 50%;
    border: 2px solid #e9ecef;
    color: #6c757d;
}

.asignacion-detalle-content .timeline-item.completed i {
    color: #198754;
    border-color: #198754;
}

.asignacion-detalle-content .timeline-item.active i {
    color: #0d6efd;
    border-color: #0d6efd;
}

.asignacion-detalle-content .timeline-item.cancelled i {
    color: #dc3545;
    border-color: #dc3545;
}

/* Estilos para impresión */
@media print {
    .asignacion-detalle-content .d-print-none {
        display: none !important;
    }
    
    .asignacion-detalle-content .d-print-block,
    .asignacion-detalle-content .d-print-flex {
        display: block !important;
    }
    
    .asignacion-detalle-content .card {
        border: 1px solid #000 !important;
        break-inside: avoid;
    }
    
    .asignacion-detalle-content .card-header {
        background: #f8f9fa !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact;
    }
    
    .asignacion-detalle-content .firma-section {
        page-break-before: auto;
        margin-top: 2cm;
    }
    
    .asignacion-detalle-content body {
        font-size: 12px;
    }
}
</style>

<?php
    $html = ob_get_clean();

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'html' => $html,
        'asignacion' => $asignacion,
        'integrantes' => $integrantes
    ]);
    
} catch (PDOException $e) {
    error_log("Error en API asignacion_detalle: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
?>