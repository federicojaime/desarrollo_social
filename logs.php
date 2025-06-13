<?php
/* =========================================================================
   logs.php – Registro de Actividad (estético + paginación sólida)
   ========================================================================= */
session_start();
require 'includes/conexion.php';

/*─── Acceso ─────────────────────────────────────────────────────────────*/
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$rol = $_SESSION['rol'] ?? 'empleado';
if (!in_array($rol, ['admin', 'supervisor'])) {
    die('<h2 style="padding:2rem;font-family:sans-serif;color:#c00">Sin permisos</h2>');
}

/*─── Paginación sin vueltas ────────────────────────────────────────────*/
$limit = 40;                                    // filas por página
$page  = max(1, (int)($_GET['page'] ?? 1));     // página actual
$offset = ($page - 1) * $limit;                 // desplazamiento

$total = (int)$pdo->query("SELECT COUNT(*) FROM log_actividades")
                  ->fetchColumn();              // total seguro

$total_pages = max(1, ceil($total / $limit));   // nº de páginas

/*─── Traemos el bloque correspondiente ────────────────────────────────*/
$query = "
    SELECT l.*, COALESCE(CONCAT(u.nombre,' ',u.apellido),'–') AS usuario
    FROM   log_actividades l
    LEFT   JOIN usuarios u ON u.id = l.usuario_id
    ORDER  BY l.fecha_actividad DESC
    LIMIT  $limit OFFSET $offset
";
$logs = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

/*─── Helper simple de fecha ───────────────────────────────────────────*/
function ffecha($t){ return date('d/m/Y H:i', strtotime($t)); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registro de Actividad – Desarrollo Social</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

<style>
:root{
    --sidebar-width:280px;           --primary:#2563eb;
    --primary-dark:#1d4ed8;          --light:#f8fafc;
    --shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06);
}
body{margin:0;display:flex;background:var(--light);font-family:system-ui,sans-serif;}
.main{flex:1;padding:2rem;margin-left:var(--sidebar-width);}
h1{font-size:1.6rem;font-weight:600;margin-bottom:1.5rem;}
.box{background:#fff;border-radius:12px;box-shadow:var(--shadow);overflow:hidden;}
.table thead{background:var(--primary);color:#fff;font-weight:600;}
.table th,.table td{white-space:nowrap;vertical-align:middle;font-size:.9rem;}
.badge-accion{background:#64748b;}
.pagination .page-link{border:none;border-radius:8px;margin:0 2px;}
.pagination .active>.page-link{background:var(--primary-dark);}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="main">
    <h1><i class="fas fa-history me-3"></i>Registro de Actividad</h1>

    <div class="box mb-3">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>Fecha</th><th>Acción</th><th>Tabla</th><th>ID</th>
                        <th>Descripción</th><th>Usuario</th><th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if($logs): foreach($logs as $l): ?>
                    <tr>
                        <td><?= ffecha($l['fecha_actividad']); ?></td>
                        <td><span class="badge text-bg-secondary"><?= $l['accion']; ?></span></td>
                        <td><?= $l['tabla_afectada']; ?></td>
                        <td><?= $l['registro_id']; ?></td>
                        <td><?= htmlspecialchars($l['descripcion']); ?></td>
                        <td><?= htmlspecialchars($l['usuario']); ?></td>
                        <td><?= $l['ip_address']; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x mb-2"></i><br>Sin registros todavía
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Paginación bonita -->
    <?php if($total_pages > 1): ?>
    <nav aria-label="Paginación">
        <ul class="pagination justify-content-end">
            <?php for($i=1;$i<=$total_pages;$i++): ?>
                <li class="page-item <?= $i==$page?'active':''; ?>">
                    <a class="page-link" href="?page=<?= $i; ?>"><?= $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

</body>
</html>
