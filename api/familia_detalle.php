<?php

/**
 * API para obtener detalle completo de una familia
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
        'error' => 'ID de familia requerido'
    ]);
    exit;
}

$familia_id = (int)$_GET['id'];

try {
    // Obtener datos completos de la familia
    $stmt = $pdo->prepare("
        SELECT 
            f.*,
            u.nombre as usuario_registro_nombre,
            u.apellido as usuario_registro_apellido
        FROM familias f
        LEFT JOIN usuarios u ON f.id = u.id
        WHERE f.id = ?
    ");

    $stmt->execute([$familia_id]);
    $familia = $stmt->fetch();

    if (!$familia) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Familia no encontrada'
        ]);
        exit;
    }

    // Obtener asignaciones de la familia
    $stmt_asignaciones = $pdo->prepare("
        SELECT 
            a.*,
            ay.nombre_ayuda,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido
        FROM asignaciones a
        LEFT JOIN ayudas ay ON a.id_ayuda = ay.id
        LEFT JOIN usuarios u ON a.usuario_asignador = u.id
        WHERE a.familia_id = ?
        ORDER BY a.fecha_asignacion DESC
        LIMIT 10
    ");

    $stmt_asignaciones->execute([$familia_id]);
    $asignaciones = $stmt_asignaciones->fetchAll();

    // Obtener estadísticas de asignaciones
    $stmt_stats = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN estado = 'autorizada' THEN 1 ELSE 0 END) as autorizadas,
            SUM(CASE WHEN estado = 'entregada' THEN 1 ELSE 0 END) as entregadas,
            SUM(CASE WHEN estado = 'cancelada' THEN 1 ELSE 0 END) as canceladas,
            MAX(fecha_asignacion) as ultima_asignacion,
            MIN(fecha_asignacion) as primera_asignacion
        FROM asignaciones 
        WHERE familia_id = ?
    ");

    $stmt_stats->execute([$familia_id]);
    $stats_asignaciones = $stmt_stats->fetch();

    // Obtener personas de la familia si existe la tabla
    $personas = [];
    try {
        $stmt_personas = $pdo->prepare("
            SELECT nombre, apellido, cedula 
            FROM personas 
            WHERE id_familia = ?
            ORDER BY nombre
        ");
        $stmt_personas->execute([$familia_id]);
        $personas = $stmt_personas->fetchAll();
    } catch (Exception $e) {
        // La tabla personas puede no existir
        $personas = [];
    }

    // Generar HTML para el modal
    ob_start();
?>

    <div class="row">
        <div class="col-md-8">
            <!-- Información básica -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-user me-2"></i>Información Personal</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Nombre Completo</label>
                                <div class="fw-bold fs-5">
                                    <?php echo htmlspecialchars($familia['nombre_jefe'] . ' ' . $familia['apellido_jefe']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">DNI</label>
                                <div class="fw-bold fs-5 font-monospace">
                                    <?php echo htmlspecialchars($familia['dni_jefe']); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Teléfono</label>
                                <div class="fw-bold">
                                    <?php if (!empty($familia['telefono'])): ?>
                                        <i class="fas fa-phone text-success me-2"></i>
                                        <?php echo htmlspecialchars($familia['telefono']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Sin teléfono registrado</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Estado</label>
                                <div>
                                    <span class="badge <?php echo $familia['estado'] === 'activa' ? 'bg-success' : 'bg-secondary'; ?> fs-6">
                                        <?php echo ucfirst($familia['estado']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label text-muted">Dirección</label>
                                <div class="fw-bold">
                                    <?php if (!empty($familia['direccion'])): ?>
                                        <i class="fas fa-map-marker-alt text-danger me-2"></i>
                                        <?php echo htmlspecialchars($familia['direccion']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Sin dirección registrada</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Barrio</label>
                                <div class="fw-bold">
                                    <?php if (!empty($familia['barrio'])): ?>
                                        <i class="fas fa-home text-info me-2"></i>
                                        <?php echo htmlspecialchars($familia['barrio']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Sin barrio especificado</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label text-muted">Integrantes</label>
                                <div class="fw-bold fs-5 text-primary">
                                    <i class="fas fa-users me-2"></i>
                                    <?php echo $familia['cantidad_integrantes']; ?>
                                    <?php echo $familia['cantidad_integrantes'] == 1 ? 'persona' : 'personas'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-0">
                                <label class="form-label text-muted">Fecha de Registro</label>
                                <div class="text-muted">
                                    <i class="fas fa-calendar me-2"></i>
                                    <?php echo formatearFechaHora($familia['fecha_registro']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Integrantes de la familia -->
            <?php if (!empty($personas)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-user-friends me-2"></i>Integrantes de la Familia</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($personas as $persona): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center p-2 bg-light rounded">
                                        <div class="me-3">
                                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                                style="width: 40px; height: 40px; font-size: 0.9rem;">
                                                <?php echo strtoupper(substr($persona['nombre'], 0, 1)); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($persona['nombre'] . ' ' . ($persona['apellido'] ?? '')); ?></div>
                                            <?php if (!empty($persona['cedula'])): ?>
                                                <small class="text-muted">CI: <?php echo htmlspecialchars($persona['cedula']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-md-4">
            <!-- Estadísticas de asignaciones -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Resumen de Asignaciones</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="fw-bold fs-4 text-primary"><?php echo $stats_asignaciones['total']; ?></div>
                            <small class="text-muted">Total</small>
                        </div>
                        <div class="col-6">
                            <div class="fw-bold fs-4 text-warning"><?php echo $stats_asignaciones['pendientes']; ?></div>
                            <small class="text-muted">Pendientes</small>
                        </div>
                    </div>
                    <hr>
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="fw-bold fs-5 text-success"><?php echo $stats_asignaciones['entregadas']; ?></div>
                            <small class="text-muted">Entregadas</small>
                        </div>
                        <div class="col-6">
                            <div class="fw-bold fs-5 text-danger"><?php echo $stats_asignaciones['canceladas']; ?></div>
                            <small class="text-muted">Canceladas</small>
                        </div>
                    </div>

                    <?php if ($stats_asignaciones['primera_asignacion']): ?>
                        <hr>
                        <div class="small">
                            <div class="mb-1">
                                <strong>Primera asignación:</strong><br>
                                <span class="text-muted"><?php echo formatearFecha($stats_asignaciones['primera_asignacion']); ?></span>
                            </div>
                            <?php if ($stats_asignaciones['ultima_asignacion']): ?>
                                <div>
                                    <strong>Última asignación:</strong><br>
                                    <span class="text-muted"><?php echo formatearFecha($stats_asignaciones['ultima_asignacion']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Acciones rápidas -->
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Acciones Rápidas</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="asignaciones.php?familia=<?php echo $familia['id']; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-gift me-2"></i>Ver Todas las Asignaciones
                        </a>
                        <a href="asignaciones.php?nueva=1&familia=<?php echo $familia['id']; ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-plus me-2"></i>Nueva Asignación
                        </a>
                        <?php if (!empty($personas)): ?>
                            <a href="personas.php?familia=<?php echo $familia['id']; ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-user-friends me-2"></i>Gestionar Integrantes
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($asignaciones)): ?>
        <!-- Últimas asignaciones -->
        <div class="card mt-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Últimas Asignaciones</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Ayuda</th>
                                <th>Cantidad</th>
                                <th>Estado</th>
                                <th>Usuario</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($asignaciones as $asignacion): ?>
                                <tr>
                                    <td><?php echo formatearFecha($asignacion['fecha_asignacion']); ?></td>
                                    <td><?php echo htmlspecialchars($asignacion['nombre_ayuda'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($asignacion['cantidad'], 2); ?></td>
                                    <td>
                                        <span class="badge <?php
                                                            echo match ($asignacion['estado']) {
                                                                'pendiente' => 'bg-warning',
                                                                'autorizada' => 'bg-info',
                                                                'entregada' => 'bg-success',
                                                                'cancelada' => 'bg-danger',
                                                                default => 'bg-secondary'
                                                            };
                                                            ?>">
                                            <?php echo ucfirst($asignacion['estado']); ?>
                                        </span>
                                    </td>
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
    <?php endif; ?>

<?php
    $html = ob_get_clean();

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'html' => $html,
        'familia' => $familia,
        'stats' => $stats_asignaciones
    ]);
} catch (PDOException $e) {
    error_log("Error en API familia_detalle: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
?>