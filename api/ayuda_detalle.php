<?php
/**
 * API para obtener detalle completo de un tipo de ayuda
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
        'error' => 'ID de ayuda requerido'
    ]);
    exit;
}

$ayuda_id = (int)$_GET['id'];

// Obtener rol del usuario de la sesión
$user_role = $_SESSION['rol'] ?? 'empleado';

try {
    // Obtener datos completos de la ayuda
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id), 0
            ) as total_asignaciones,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'pendiente'), 0
            ) as asignaciones_pendientes,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'autorizada'), 0
            ) as asignaciones_autorizadas,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'entregada'), 0
            ) as asignaciones_entregadas,
            COALESCE(
                (SELECT COUNT(*) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'cancelada'), 0
            ) as asignaciones_canceladas,
            COALESCE(
                (SELECT SUM(asg.cantidad) FROM asignaciones asg WHERE asg.id_ayuda = a.id AND asg.estado = 'entregada'), 0
            ) as cantidad_total_entregada,
            COALESCE(
                (SELECT MAX(asg.fecha_asignacion) FROM asignaciones asg WHERE asg.id_ayuda = a.id), NULL
            ) as ultima_asignacion,
            COALESCE(
                (SELECT MIN(asg.fecha_asignacion) FROM asignaciones asg WHERE asg.id_ayuda = a.id), NULL
            ) as primera_asignacion
        FROM ayudas a
        WHERE a.id = ?
    ");

    $stmt->execute([$ayuda_id]);
    $ayuda = $stmt->fetch();

    if (!$ayuda) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Tipo de ayuda no encontrado'
        ]);
        exit;
    }

    // Obtener últimas 10 asignaciones de esta ayuda
    $stmt_asignaciones = $pdo->prepare("
        SELECT 
            a.id,
            a.numero_expediente,
            a.fecha_asignacion,
            a.estado,
            a.prioridad,
            a.cantidad,
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido
        FROM asignaciones a
        LEFT JOIN familias f ON a.familia_id = f.id
        LEFT JOIN usuarios u ON a.usuario_asignador = u.id
        WHERE a.id_ayuda = ?
        ORDER BY a.fecha_asignacion DESC
        LIMIT 10
    ");
    $stmt_asignaciones->execute([$ayuda_id]);
    $asignaciones_recientes = $stmt_asignaciones->fetchAll();

    // Obtener estadísticas por mes (últimos 12 meses)
    $stmt_estadisticas = $pdo->prepare("
        SELECT 
            DATE_FORMAT(fecha_asignacion, '%Y-%m') as mes,
            COUNT(*) as cantidad_asignaciones,
            SUM(cantidad) as cantidad_total
        FROM asignaciones 
        WHERE id_ayuda = ? 
        AND fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(fecha_asignacion, '%Y-%m')
        ORDER BY mes DESC
    ");
    $stmt_estadisticas->execute([$ayuda_id]);
    $estadisticas_mensuales = $stmt_estadisticas->fetchAll();

    // Obtener familias más beneficiadas
    $stmt_familias = $pdo->prepare("
        SELECT 
            f.nombre_jefe,
            f.apellido_jefe,
            f.dni_jefe,
            COUNT(a.id) as cantidad_asignaciones,
            SUM(a.cantidad) as cantidad_total,
            MAX(a.fecha_asignacion) as ultima_asignacion
        FROM asignaciones a
        JOIN familias f ON a.familia_id = f.id
        WHERE a.id_ayuda = ?
        GROUP BY f.id, f.nombre_jefe, f.apellido_jefe, f.dni_jefe
        ORDER BY cantidad_asignaciones DESC
        LIMIT 5
    ");
    $stmt_familias->execute([$ayuda_id]);
    $familias_beneficiadas = $stmt_familias->fetchAll();

    // Generar HTML para el modal
    ob_start();
?>

<div class="ayuda-detalle-content">
    <!-- Header con información básica -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-gift me-2"></i>
                        Información del Tipo de Ayuda
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Nombre:</strong></td>
                                    <td class="fs-5 fw-bold text-primary"><?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Descripción:</strong></td>
                                    <td>
                                        <?php if (!empty($ayuda['descripcion'])): ?>
                                            <?php echo htmlspecialchars($ayuda['descripcion']); ?>
                                        <?php else: ?>
                                            <em class="text-muted">Sin descripción disponible</em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Estado:</strong></td>
                                    <td>
                                        <span class="badge <?php echo $ayuda['activo'] ? 'bg-success' : 'bg-warning'; ?> fs-6">
                                            <?php echo $ayuda['activo'] ? 'Activa' : 'Inactiva'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Fecha de Registro:</strong></td>
                                    <td>
                                        <i class="fas fa-calendar me-2 text-info"></i>
                                        <?php echo formatearFechaHora($ayuda['fecha_registro']); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>ID Sistema:</strong></td>
                                    <td class="font-monospace text-muted"><?php echo $ayuda['id']; ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <div class="ayuda-icon-big mb-3">
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
                                <h4><?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?></h4>
                                <p class="text-muted">Tipo de Ayuda #<?php echo $ayuda['id']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Resumen de Uso</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-3">
                        <div class="col-6">
                            <div class="fw-bold fs-4 text-primary"><?php echo $ayuda['total_asignaciones']; ?></div>
                            <small class="text-muted">Total Asignaciones</small>
                        </div>
                        <div class="col-6">
                            <div class="fw-bold fs-4 text-success"><?php echo $ayuda['asignaciones_entregadas']; ?></div>
                            <small class="text-muted">Entregadas</small>
                        </div>
                    </div>
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="fw-bold fs-5 text-warning"><?php echo $ayuda['asignaciones_pendientes']; ?></div>
                            <small class="text-muted">Pendientes</small>
                        </div>
                        <div class="col-6">
                            <div class="fw-bold fs-5 text-info"><?php echo $ayuda['asignaciones_autorizadas']; ?></div>
                            <small class="text-muted">Autorizadas</small>
                        </div>
                    </div>
                    
                    <?php if ($ayuda['total_asignaciones'] > 0): ?>
                    <hr>
                    <div class="small">
                        <div class="mb-2">
                            <strong>Cantidad Total Entregada:</strong><br>
                            <span class="text-success fs-6"><?php echo number_format($ayuda['cantidad_total_entregada'], 2); ?></span>
                        </div>
                        <?php if ($ayuda['primera_asignacion']): ?>
                        <div class="mb-2">
                            <strong>Primera Asignación:</strong><br>
                            <span class="text-muted"><?php echo formatearFecha($ayuda['primera_asignacion']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($ayuda['ultima_asignacion']): ?>
                        <div>
                            <strong>Última Asignación:</strong><br>
                            <span class="text-muted"><?php echo formatearFecha($ayuda['ultima_asignacion']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <hr>
                    <div class="text-center text-muted">
                        <i class="fas fa-info-circle fa-2x mb-2"></i>
                        <p class="mb-0">Sin asignaciones registradas</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Estadísticas mensuales -->
    <?php if (!empty($estadisticas_mensuales)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Estadísticas Mensuales (Últimos 12 Meses)</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Mes</th>
                                    <th>Asignaciones</th>
                                    <th>Cantidad Total</th>
                                    <th>Promedio por Asignación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estadisticas_mensuales as $stat): ?>
                                <tr>
                                    <td><?php echo date('M Y', strtotime($stat['mes'] . '-01')); ?></td>
                                    <td><span class="badge bg-primary"><?php echo $stat['cantidad_asignaciones']; ?></span></td>
                                    <td><?php echo number_format($stat['cantidad_total'], 2); ?></td>
                                    <td><?php echo number_format($stat['cantidad_total'] / $stat['cantidad_asignaciones'], 2); ?></td>
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

    <!-- Familias más beneficiadas -->
    <?php if (!empty($familias_beneficiadas)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0"><i class="fas fa-users me-2"></i>Familias Más Beneficiadas</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Familia</th>
                                    <th>DNI</th>
                                    <th>Asignaciones</th>
                                    <th>Cantidad Total</th>
                                    <th>Última Asignación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($familias_beneficiadas as $familia): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe']); ?></strong>
                                    </td>
                                    <td class="font-monospace"><?php echo htmlspecialchars($familia['dni_jefe']); ?></td>
                                    <td><span class="badge bg-info"><?php echo $familia['cantidad_asignaciones']; ?></span></td>
                                    <td><?php echo number_format($familia['cantidad_total'], 2); ?></td>
                                    <td><?php echo formatearFecha($familia['ultima_asignacion']); ?></td>
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

    <!-- Asignaciones recientes -->
    <?php if (!empty($asignaciones_recientes)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-secondary">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="fas fa-history me-2"></i>Asignaciones Recientes</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Expediente</th>
                                    <th>Familia</th>
                                    <th>Cantidad</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                    <th>Usuario</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($asignaciones_recientes as $asignacion): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($asignacion['numero_expediente'] ?? 'N/A'); ?></code></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($asignacion['nombre_jefe'] . ' ' . $asignacion['apellido_jefe']); ?></strong>
                                        <br><small class="text-muted">DNI: <?php echo htmlspecialchars($asignacion['dni_jefe']); ?></small>
                                    </td>
                                    <td><?php echo number_format($asignacion['cantidad'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo obtenerColorEstado($asignacion['estado']); ?>">
                                            <?php echo ucfirst($asignacion['estado']); ?>
                                        </span>
                                        <?php if ($asignacion['prioridad'] === 'urgente'): ?>
                                            <span class="badge bg-danger ms-1">Urgente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatearFecha($asignacion['fecha_asignacion']); ?></td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($asignacion['usuario_nombre'] . ' ' . $asignacion['usuario_apellido']); ?>
                                        </small>
                                    </td>
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
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="fas fa-bolt me-2"></i>Acciones Rápidas</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2 d-md-flex">
                        <?php if ($ayuda['total_asignaciones'] > 0): ?>
                        <a href="asignaciones.php?search=<?php echo urlencode($ayuda['nombre_ayuda']); ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-list me-2"></i>Ver Todas las Asignaciones
                        </a>
                        <?php endif; ?>
                        <a href="asignaciones.php" class="btn btn-success btn-sm">
                            <i class="fas fa-plus me-2"></i>Nueva Asignación
                        </a>
                        <button class="btn btn-warning btn-sm" onclick="editarAyudaDesdeDetalle()">
                            <i class="fas fa-edit me-2"></i>Editar Ayuda
                        </button>
                        <?php if ($ayuda['activo'] && $user_role === 'admin'): ?>
                        <button class="btn btn-outline-secondary btn-sm" onclick="desactivarAyuda(<?php echo $ayuda['id']; ?>, '<?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?>')">
                            <i class="fas fa-pause me-2"></i>Desactivar
                        </button>
                        <?php elseif (!$ayuda['activo'] && $user_role === 'admin'): ?>
                        <button class="btn btn-outline-success btn-sm" onclick="activarAyuda(<?php echo $ayuda['id']; ?>, '<?php echo htmlspecialchars($ayuda['nombre_ayuda']); ?>')">
                            <i class="fas fa-play me-2"></i>Reactivar
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Estilos específicos para el detalle de ayuda */
.ayuda-detalle-content .ayuda-icon-big {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2.5rem;
    margin: 0 auto;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.ayuda-detalle-content .card {
    transition: all 0.3s ease;
}

.ayuda-detalle-content .card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.ayuda-detalle-content .table th {
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
</style>

<?php
    $html = ob_get_clean();

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'html' => $html,
        'ayuda' => $ayuda,
        'estadisticas' => [
            'total_asignaciones' => $ayuda['total_asignaciones'],
            'asignaciones_entregadas' => $ayuda['asignaciones_entregadas'],
            'cantidad_total_entregada' => $ayuda['cantidad_total_entregada'],
            'estadisticas_mensuales' => $estadisticas_mensuales,
            'familias_beneficiadas' => $familias_beneficiadas,
            'asignaciones_recientes' => $asignaciones_recientes
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Error en API ayuda_detalle: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
?>