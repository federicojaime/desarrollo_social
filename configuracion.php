<?php
session_start();
require 'includes/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['rol'] ?? 'empleado';
if (!in_array($user_role, ['admin', 'supervisor'])) {
    $_SESSION['error'] = "No tiene permisos para acceder a Configuración.";
    header("Location: dashboard.php");
    exit;
}

$mensaje = $_SESSION['mensaje'] ?? '';
$error   = $_SESSION['error']   ?? '';
unset($_SESSION['mensaje'], $_SESSION['error']);

/* ──────────────── CRUD ──────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion        = $_POST['accion'];
    $config_id     = $_POST['config_id'] ?? null;
    $clave         = limpiarEntrada($_POST['clave'] ?? '');
    $valor         = trim($_POST['valor'] ?? '');
    $descripcion   = limpiarEntrada($_POST['descripcion'] ?? '');
    $tipo          = $_POST['tipo'] ?? 'texto';
    $categoria     = limpiarEntrada($_POST['categoria'] ?? 'general');

    try {
        switch ($accion) {

            case 'agregar':
                if ($clave === '') throw new Exception("La clave es obligatoria.");
                $stmt = $pdo->prepare("
                    INSERT INTO configuraciones (clave, valor, descripcion, tipo, categoria)
                    VALUES (?,?,?,?,?)
                ");
                $stmt->execute([$clave, $valor, $descripcion, $tipo, $categoria]);

                registrarLog($pdo, 'configuraciones', $pdo->lastInsertId(),
                    'crear', "Configuración creada: $clave", $_SESSION['user_id']);
                $_SESSION['mensaje'] = "Configuración agregada correctamente.";
                break;

            case 'editar':
                $stmt = $pdo->prepare("
                    UPDATE configuraciones
                    SET valor = ?, descripcion = ?, tipo = ?, categoria = ?
                    WHERE id = ?
                ");
                $stmt->execute([$valor, $descripcion, $tipo, $categoria, $config_id]);

                registrarLog($pdo, 'configuraciones', $config_id,
                    'actualizar', "Configuración actualizada: $clave", $_SESSION['user_id']);
                $_SESSION['mensaje'] = "Configuración actualizada.";
                break;

            case 'eliminar':
                if ($user_role !== 'admin') throw new Exception("Solo un administrador puede eliminar configuraciones.");
                $stmt = $pdo->prepare("DELETE FROM configuraciones WHERE id = ?");
                $stmt->execute([$config_id]);

                registrarLog($pdo, 'configuraciones', $config_id,
                    'eliminar', "Configuración eliminada: $clave", $_SESSION['user_id']);
                $_SESSION['mensaje'] = "Configuración eliminada.";
                break;
        }

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header("Location: configuracion.php");
    exit;
}

/* ──────────────── LISTADO ──────────────── */
$search = $_GET['search'] ?? '';
$where  = "1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (clave LIKE ? OR descripcion LIKE ? OR categoria LIKE ?)";
    $params = array_fill(0, 3, "%$search%");
}

$stmt_total = $pdo->prepare("SELECT COUNT(*) FROM configuraciones WHERE $where");
$stmt_total->execute($params);
$total = (int)$stmt_total->fetchColumn();

$limit  = 20;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT * FROM configuraciones
    WHERE $where
    ORDER BY categoria, clave
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$configs      = $stmt->fetchAll();
$total_pages  = ceil($total / $limit);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Configuración – Sistema de Desarrollo Social</title>

    <!-- Framework & fuentes -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tema global (mismo que familias/personas) -->
    <style>
        /* Variables & helpers idénticos a los de familias y personas */ 
        :root { /* colores y sombras */                                  /* :contentReference[oaicite:0]{index=0} */
            --sidebar-width: 280px;
            --primary-color:#2563eb; --primary-dark:#1d4ed8;
            --secondary-color:#64748b; --success-color:#059669;
            --warning-color:#d97706; --danger-color:#dc2626;
            --info-color:#0891b2; --light-color:#f8fafc; --dark-color:#1e293b;
            --border-color:#e2e8f0;
            --shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06);
        }
        body{font-family:'Inter',sans-serif;background:var(--light-color);margin:0;display:flex;}
        .main-content{margin-left:var(--sidebar-width);padding:2rem;flex:1;}

        .page-header h1{font-size:1.6rem;font-weight:600}
        .fade-in-up{animation:fadeInUp .6s ease-out;}

        @keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

        /* ---------- tabla ---------- */
        .table-container{background:#fff;border-radius:12px;box-shadow:var(--shadow);overflow:hidden;}
        .table-container .table{margin:0;}
        .table thead{background:var(--primary-color);color:#fff;}
        .table th, .table td{vertical-align:middle;}
        .table td{white-space:nowrap;}
        .btn-action{border:none;background:transparent;color:var(--primary-color);padding:4px 6px;font-size:1rem;}
        .btn-action:hover{color:var(--primary-dark);}
        .badge-type{font-size:.75rem;border-radius:4px;padding:.25rem .5rem;}

        /* ---------- alerts ---------- */
        .alert{border:none;border-radius:12px;padding:1rem 1.5rem;margin-bottom:1.5rem;border-left:4px solid;}
        .alert-success{background:#f0fdf4;border-left-color:var(--success-color);color:#065f46;}
        .alert-danger{background:#fef2f2;border-left-color:var(--danger-color);color:#991b1b;}
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <div class="page-header fade-in-up">
            <h1><i class="fas fa-cogs me-3"></i>Configuración</h1>
            <p>Parámetros generales del sistema</p>
        </div>

        <!-- Alertas -->
        <?php if ($mensaje): ?>
            <div class="alert alert-success fade-in-up">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger fade-in-up">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Filtros -->
        <form class="row g-3 mb-4 fade-in-up" method="get">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search"
                       placeholder="Buscar clave, descripción o categoría"
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary"><i class="fas fa-search me-1"></i>Buscar</button>
            </div>
            <div class="col-md-2 d-grid">
                <a href="configuracion.php" class="btn btn-outline-secondary"><i class="fas fa-refresh"></i></a>
            </div>
            <div class="col-md-4 text-end">
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#configModal">
                    <i class="fas fa-plus me-2"></i>Nueva Configuración
                </button>
            </div>
        </form>

        <!-- Tabla -->
        <div class="table-container fade-in-up">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="min-width:140px">Categoría</th>
                            <th style="min-width:140px">Clave</th>
                            <th>Valor</th>
                            <th style="min-width:220px">Descripción</th>
                            <th style="min-width:90px;text-align:center">Tipo</th>
                            <th style="width:80px;text-align:center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($configs): foreach ($configs as $cfg): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cfg['categoria']); ?></td>
                            <td><?php echo htmlspecialchars($cfg['clave']); ?></td>
                            <td><?php echo htmlspecialchars($cfg['valor']); ?></td>
                            <td><?php echo htmlspecialchars($cfg['descripcion']); ?></td>
                            <td class="text-center">
                                <span class="badge-type bg-secondary text-white"><?php echo $cfg['tipo']; ?></span>
                            </td>
                            <td class="text-center">
                                <button class="btn-action" title="Editar"
                                    onclick="openModal('editar',<?php echo $cfg['id']; ?>,
                                        '<?php echo htmlspecialchars($cfg['clave'],ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($cfg['valor'],ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($cfg['descripcion'],ENT_QUOTES); ?>',
                                        '<?php echo $cfg['tipo']; ?>',
                                        '<?php echo htmlspecialchars($cfg['categoria'],ENT_QUOTES); ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if($user_role==='admin'): ?>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('¿Eliminar esta configuración?')">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="config_id" value="<?php echo $cfg['id']; ?>">
                                    <input type="hidden" name="clave" value="<?php echo htmlspecialchars($cfg['clave']); ?>">
                                    <button class="btn-action" title="Eliminar"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">
                            <i class="fas fa-inbox fa-2x mb-2"></i><br>No hay configuraciones registradas
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Paginación -->
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-end mt-3 fade-in-up">
            <ul class="pagination mb-0">
                <?php for ($i=1;$i<=$total_pages;$i++): ?>
                    <li class="page-item <?php echo $i===$page?'active':''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Alta / Edición -->
    <div class="modal fade" id="configModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="post" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-cog me-2"></i><span id="modalTitle">Nueva Configuración</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <input type="hidden" name="accion" id="accionInput" value="agregar">
                    <input type="hidden" name="config_id" id="configIdInput">
                    <div class="col-md-6">
                        <label class="form-label">Clave *</label>
                        <input type="text" name="clave" id="claveInput" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Categoría</label>
                        <input type="text" name="categoria" id="categoriaInput" class="form-control">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Valor *</label>
                        <textarea name="valor" id="valorInput" rows="2" class="form-control" required></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tipo</label>
                        <select name="tipo" id="tipoInput" class="form-select">
                            <option value="texto">Texto</option>
                            <option value="numero">Número</option>
                            <option value="booleano">Booleano</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" id="descripcionInput" rows="2" class="form-control"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary"><i class="fas fa-save me-2"></i>Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        /* Rellena el modal en modo edición */
        function openModal(mode,id,clave,valor,descripcion,tipo,categoria){
            document.getElementById('modalTitle').textContent = mode==='editar'?'Editar Configuración':'Nueva Configuración';
            document.getElementById('accionInput').value = mode;
            document.getElementById('configIdInput').value = id||'';
            document.getElementById('claveInput').value = clave||'';
            document.getElementById('valorInput').value = valor||'';
            document.getElementById('descripcionInput').value = descripcion||'';
            document.getElementById('tipoInput').value = tipo||'texto';
            document.getElementById('categoriaInput').value = categoria||'';
            new bootstrap.Modal(document.getElementById('configModal')).show();
        }
    </script>
</body>
</html>
