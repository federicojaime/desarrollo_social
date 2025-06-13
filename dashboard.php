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

try {
    // Estadísticas generales
    $stats = [];
    
    // Total de familias activas
    $stmt = $pdo->query("SELECT COUNT(*) FROM familias WHERE estado = 'activa' OR estado IS NULL");
    $stats['familias_activas'] = $stmt->fetchColumn();
    
    // Total de asignaciones este mes
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM asignaciones 
        WHERE YEAR(fecha_asignacion) = YEAR(CURDATE()) 
        AND MONTH(fecha_asignacion) = MONTH(CURDATE())
    ");
    $stats['asignaciones_mes'] = $stmt->fetchColumn();
    
    // Asignaciones pendientes
    $stmt = $pdo->query("SELECT COUNT(*) FROM asignaciones WHERE estado = 'pendiente' OR estado IS NULL");
    $stats['asignaciones_pendientes'] = $stmt->fetchColumn();
    
    // Total de tipos de ayuda
    $stmt = $pdo->query("SELECT COUNT(*) FROM ayudas");
    $stats['tipos_ayuda'] = $stmt->fetchColumn();
    
    // Últimas 5 asignaciones
    $stmt = $pdo->query("
        SELECT 
            a.id,
            a.fecha_asignacion,
            COALESCE(a.estado, 'pendiente') as estado,
            COALESCE(a.prioridad, 'media') as prioridad,
            a.numero_expediente,
            COALESCE(f.nombre_jefe, b.nombre, p.nombre, 'N/A') as beneficiario,
            COALESCE(f.apellido_jefe, b.apellido, '', '') as apellido_beneficiario,
            COALESCE(ay.nombre_ayuda, 'Ayuda no especificada') as nombre_ayuda,
            u.nombre as usuario_nombre
        FROM asignaciones a
        LEFT JOIN familias f ON a.familia_id = f.id
        LEFT JOIN personas p ON a.id_persona = p.id
        LEFT JOIN beneficiarios b ON a.beneficiario_id = b.id
        LEFT JOIN ayudas ay ON a.id_ayuda = ay.id
        LEFT JOIN usuarios u ON a.usuario_asignador = u.id
        ORDER BY a.fecha_creacion DESC
        LIMIT 5
    ");
    $ultimas_asignaciones = $stmt->fetchAll();
    
    // Asignaciones por estado (para gráfico)
    $stmt = $pdo->query("
        SELECT 
            COALESCE(estado, 'pendiente') as estado, 
            COUNT(*) as cantidad 
        FROM asignaciones 
        WHERE fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY COALESCE(estado, 'pendiente')
    ");
    $asignaciones_por_estado = $stmt->fetchAll();
    
    // Top 5 ayudas más solicitadas
    $stmt = $pdo->query("
        SELECT 
            ay.nombre_ayuda,
            COUNT(*) as cantidad
        FROM asignaciones a
        JOIN ayudas ay ON a.id_ayuda = ay.id
        WHERE a.fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY ay.id, ay.nombre_ayuda
        ORDER BY cantidad DESC
        LIMIT 5
    ");
    $top_ayudas = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error al cargar estadísticas: " . $e->getMessage();
}

// Manejo de agregar usuario (solo admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 'admin' && isset($_POST['agregar_usuario'])) {
    $nombre = limpiarEntrada($_POST['nombre']);
    $apellido = limpiarEntrada($_POST['apellido']);
    $email = limpiarEntrada($_POST['email']);
    $usuario = limpiarEntrada($_POST['usuario']);
    $contraseña = $_POST['contraseña'];
    $rol = $_POST['rol'];

    if (empty($nombre) || empty($apellido) || empty($usuario) || empty($contraseña)) {
        $_SESSION['error'] = "Todos los campos son obligatorios.";
    } else {
        try {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ? OR email = ?");
            $stmt_check->execute([$usuario, $email]);
            
            if ($stmt_check->fetchColumn() > 0) {
                $_SESSION['error'] = "El usuario o email ya existe.";
            } else {
                $contraseña_hash = password_hash($contraseña, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios (nombre, apellido, email, usuario, contraseña, rol) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $apellido, $email, $usuario, $contraseña_hash, $rol]);
                
                if (function_exists('registrarLog')) {
                    registrarLog($pdo, 'usuarios', $pdo->lastInsertId(), 'crear', 
                        "Usuario creado: $nombre $apellido", $_SESSION['user_id']);
                }
                
                $_SESSION['mensaje'] = "Usuario creado exitosamente.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error al crear usuario: " . $e->getMessage();
        }
    }
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Desarrollo Social</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #64748b;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --info-color: #0891b2;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
            --sidebar-width: 280px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--light-color);
            overflow-x: hidden;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color) 0%, #1d4ed8 100%);
            color: white;
            z-index: 1000;
            transition: transform 0.3s ease;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .sidebar-header h4 {
            margin: 0;
            font-weight: 600;
        }

        .sidebar-menu {
            padding: 1rem 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: #60a5fa;
        }

        .sidebar-menu a i {
            width: 20px;
            margin-right: 0.75rem;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 2rem;
        }

        .header-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--secondary-color);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .table-header {
            background: #f8fafc;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        .btn-logout {
            position: absolute;
            bottom: 1rem;
            left: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-logout:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h4><i class="fas fa-heart me-2"></i>Desarrollo Social</h4>
            <small class="text-light opacity-75">Sistema de Gestión</small>
        </div>
        
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="familias.php">
                <i class="fas fa-users"></i>
                <span>Familias</span>
            </a>
            <a href="asignaciones.php">
                <i class="fas fa-hand-holding-heart"></i>
                <span>Asignaciones</span>
            </a>
            <a href="ayudas.php">
                <i class="fas fa-gift"></i>
                <span>Tipos de Ayuda</span>
            </a>
            <?php if ($user_role === 'admin'): ?>
            <a href="#" data-bs-toggle="modal" data-bs-target="#usuariosModal">
                <i class="fas fa-user-cog"></i>
                <span>Usuarios</span>
            </a>
            <?php endif; ?>
        </nav>
        
        <a href="logout.php" class="btn btn-logout">
            <i class="fas fa-sign-out-alt me-2"></i>
            Cerrar Sesión
        </a>
    </div>

    <div class="main-content">
        <div class="header-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="mb-1">Bienvenido, <?php echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['nombre'] ?? 'Usuario'); ?>!</h1>
                    <p class="text-muted mb-0">Panel de control del Sistema de Desarrollo Social</p>
                </div>
                <div class="text-end">
                    <small class="text-muted">Rol: <strong><?php echo ucfirst($user_role); ?></strong></small><br>
                    <small class="text-muted"><?php echo date('d/m/Y H:i'); ?></small>
                </div>
            </div>
        </div>

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

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background-color: var(--primary-color); color: white;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['familias_activas']); ?></div>
                <div class="stat-label">Familias Activas</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background-color: var(--success-color); color: white;">
                    <i class="fas fa-hand-holding-heart"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['asignaciones_mes']); ?></div>
                <div class="stat-label">Asignaciones este Mes</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background-color: var(--warning-color); color: white;">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['asignaciones_pendientes']); ?></div>
                <div class="stat-label">Pendientes de Autorización</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background-color: var(--info-color); color: white;">
                    <i class="fas fa-gift"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['tipos_ayuda']); ?></div>
                <div class="stat-label">Tipos de Ayuda Disponibles</div>
            </div>
        </div>

        <!-- Últimas asignaciones -->
        <div class="table-container">
            <div class="table-header">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Últimas Asignaciones</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Expediente</th>
                            <th>Beneficiario</th>
                            <th>Ayuda</th>
                            <th>Estado</th>
                            <th>Prioridad</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($ultimas_asignaciones)): ?>
                            <?php foreach ($ultimas_asignaciones as $asignacion): ?>
                            <tr>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($asignacion['numero_expediente'] ?? 'N/A'); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($asignacion['beneficiario']); ?></strong>
                                    <?php if (!empty($asignacion['apellido_beneficiario'])): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($asignacion['apellido_beneficiario']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($asignacion['nombre_ayuda']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo obtenerColorEstado($asignacion['estado']); ?>">
                                        <?php echo ucfirst($asignacion['estado']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo obtenerColorPrioridad($asignacion['prioridad']); ?>">
                                        <?php echo ucfirst($asignacion['prioridad']); ?>
                                    </span>
                                </td>
                                <td>
                                    <small><?php echo formatearFecha($asignacion['fecha_asignacion']); ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                                    No hay asignaciones registradas
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal para gestión de usuarios (solo admin) -->
    <?php if ($user_role === 'admin'): ?>
    <div class="modal fade" id="usuariosModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Gestión de Usuarios</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="dashboard.php">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="nombre" class="form-label">Nombre</label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="apellido" class="form-label">Apellido</label>
                                    <input type="text" class="form-control" id="apellido" name="apellido" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="usuario" class="form-label">Usuario</label>
                                    <input type="text" class="form-control" id="usuario" name="usuario" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="contraseña" class="form-label">Contraseña</label>
                                    <input type="password" class="form-control" id="contraseña" name="contraseña" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="rol" class="form-label">Rol</label>
                                    <select class="form-select" id="rol" name="rol" required>
                                        <option value="empleado">Empleado</option>
                                        <option value="admin">Administrador</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" name="agregar_usuario" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Crear Usuario
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>