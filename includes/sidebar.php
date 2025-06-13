<?php
// Obtener la página actual para marcar el menú activo
$current_page = basename($_SERVER['PHP_SELF']);

// Función para determinar si un enlace está activo
function isActive($page)
{
    global $current_page;
    return $current_page === $page ? 'active' : '';
}

// Obtener información del usuario actual
$user_name = $_SESSION['nombre'] ?? $_SESSION['username'] ?? 'Usuario';
$user_role = $_SESSION['rol'] ?? 'empleado';
$user_avatar = strtoupper(substr($user_name, 0, 1));

// Función para obtener el color del avatar según el rol
function getAvatarColor($role)
{
    $colors = [
        'admin' => '#dc3545',      // Rojo para admin
        'supervisor' => '#fd7e14',  // Naranja para supervisor
        'empleado' => '#198754',    // Verde para empleado
        'consulta' => '#6c757d'     // Gris para consulta
    ];
    return $colors[$role] ?? '#6c757d';
}

// Función para obtener el badge del rol
function getRoleBadge($role)
{
    $badges = [
        'admin' => '<span class="role-badge admin"><i class="fas fa-crown"></i> Admin</span>',
        'supervisor' => '<span class="role-badge supervisor"><i class="fas fa-user-tie"></i> Supervisor</span>',
        'empleado' => '<span class="role-badge empleado"><i class="fas fa-user"></i> Empleado</span>',
        'consulta' => '<span class="role-badge consulta"><i class="fas fa-eye"></i> Consulta</span>'
    ];
    return $badges[$role] ?? '<span class="role-badge"><i class="fas fa-user"></i> Usuario</span>';
}
?>

<style>
    :root {
        --sidebar-width: 280px;
        --sidebar-bg: linear-gradient(180deg, #1e3a8a 0%, #1d4ed8 50%, #2563eb 100%);
        --sidebar-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        --sidebar-text: #ffffff;
        --sidebar-text-muted: rgba(255, 255, 255, 0.8);
        --sidebar-active-bg: rgba(255, 255, 255, 0.15);
        --sidebar-active-border: #60a5fa;
        --sidebar-hover-bg: rgba(255, 255, 255, 0.1);
        --sidebar-divider: rgba(255, 255, 255, 0.1);
    }

    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: var(--sidebar-width);
        background: var(--sidebar-bg);
        color: var(--sidebar-text);
        z-index: 1000;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow-y: auto;
        overflow-x: hidden;
        box-shadow: var(--sidebar-shadow);
        backdrop-filter: blur(10px);
    }

    /* Scrollbar personalizado */
    .sidebar::-webkit-scrollbar {
        width: 6px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 3px;
    }

    .sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.5);
    }

    /* Header del sidebar */
    .sidebar-header {
        padding: 2rem 1.5rem 1.5rem;
        text-align: center;
        border-bottom: 1px solid var(--sidebar-divider);
        position: relative;
    }

    .sidebar-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 80%;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    }

    .sidebar-logo {
        width: 70px;
        height: 70px;
        margin: 0 auto;

    }


    .sidebar-logo img {
        width: 70px;
        height: 70px;
        object-fit: contain;
        z-index: 1;
        position: relative;
    }

    .sidebar-logo i {
        font-size: 1.8rem;
        color: var(--sidebar-text);
        z-index: 1;
        position: relative;
    }

    .sidebar-title {
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        letter-spacing: -0.5px;
    }

    .sidebar-subtitle {
        font-size: 0.75rem;
        color: var(--sidebar-text-muted);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* Navegación */
    .sidebar-nav {
        padding: 1.5rem 0;
        flex-grow: 1;
    }

    .nav-section {
        margin-bottom: 2rem;
    }

    .nav-section-title {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--sidebar-text-muted);
        text-transform: uppercase;
        letter-spacing: 1.5px;
        padding: 0 1.5rem 0.75rem;
        margin-bottom: 0.5rem;
        position: relative;
    }

    .nav-section-title::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 1.5rem;
        right: 1.5rem;
        height: 1px;
        background: linear-gradient(90deg, var(--sidebar-divider), transparent);
    }

    .nav-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .nav-item {
        margin-bottom: 0.25rem;
    }

    .nav-link {
        display: flex;
        align-items: center;
        padding: 0.875rem 1.5rem;
        color: var(--sidebar-text);
        text-decoration: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        border-left: 3px solid transparent;
        font-weight: 500;
    }

    .nav-link::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 0;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
        transition: width 0.3s ease;
    }

    .nav-link:hover::before {
        width: 100%;
    }

    .nav-link:hover {
        background: var(--sidebar-hover-bg);
        transform: translateX(5px);
    }

    .nav-link.active {
        background: var(--sidebar-active-bg);
        border-left-color: var(--sidebar-active-border);
        transform: translateX(5px);
        box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.1);
    }

    .nav-link.active::before {
        width: 100%;
    }

    .nav-icon {
        width: 20px;
        height: 20px;
        margin-right: 0.875rem;
        font-size: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.3s ease;
    }

    .nav-link:hover .nav-icon,
    .nav-link.active .nav-icon {
        transform: scale(1.1);
    }

    .nav-text {
        flex-grow: 1;
        font-size: 0.9rem;
    }

    .nav-badge {
        background: rgba(255, 255, 255, 0.2);
        color: var(--sidebar-text);
        padding: 0.125rem 0.5rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        margin-left: auto;
    }

    /* Información del usuario */
    .sidebar-user {
        padding: 1.5rem;
        border-top: 1px solid var(--sidebar-divider);
        position: relative;
    }

    .sidebar-user::before {
        content: '';
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 80%;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    }

    .user-info {
        display: flex;
        align-items: center;
        margin-bottom: 1rem;
        padding: 0.75rem;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        backdrop-filter: blur(5px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }

    .user-info:hover {
        background: rgba(255, 255, 255, 0.15);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }

    .user-avatar {
        width: 45px;
        height: 45px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
        margin-right: 0.875rem;
        color: white;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        position: relative;
        overflow: hidden;
    }

    .user-avatar::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transform: rotate(45deg) translateX(-100%);
        transition: transform 0.6s ease;
    }

    .user-info:hover .user-avatar::before {
        transform: rotate(45deg) translateX(100%);
    }

    .user-details {
        flex-grow: 1;
        min-width: 0;
    }

    .user-name {
        font-weight: 600;
        font-size: 0.9rem;
        margin-bottom: 0.25rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .role-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0.125rem 0.5rem;
        border-radius: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .role-badge.admin {
        background: rgba(220, 53, 69, 0.2);
        color: #ff6b7a;
        border: 1px solid rgba(220, 53, 69, 0.3);
    }

    .role-badge.supervisor {
        background: rgba(253, 126, 20, 0.2);
        color: #ffb366;
        border: 1px solid rgba(253, 126, 20, 0.3);
    }

    .role-badge.empleado {
        background: rgba(25, 135, 84, 0.2);
        color: #66d9a3;
        border: 1px solid rgba(25, 135, 84, 0.3);
    }

    .role-badge.consulta {
        background: rgba(108, 117, 125, 0.2);
        color: #adb5bd;
        border: 1px solid rgba(108, 117, 125, 0.3);
    }

    /* Botón de cerrar sesión */
    .logout-btn {
        width: 100%;
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        color: #ff6b7a;
        padding: 0.75rem 1rem;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        position: relative;
        overflow: hidden;
    }

    .logout-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transition: left 0.5s ease;
    }

    .logout-btn:hover::before {
        left: 100%;
    }

    .logout-btn:hover {
        background: rgba(220, 53, 69, 0.2);
        border-color: rgba(220, 53, 69, 0.5);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        color: #ffffff;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.show {
            transform: translateX(0);
        }

        .main-content {
            margin-left: 0;
        }
    }

    /* Overlay para móviles */
    .sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .sidebar-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    /* Botón toggle para móviles */
    .sidebar-toggle {
        display: none;
        position: fixed;
        top: 1rem;
        left: 1rem;
        z-index: 1051;
        background: var(--sidebar-bg);
        color: white;
        border: none;
        width: 45px;
        height: 45px;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        transition: all 0.3s ease;
    }

    .sidebar-toggle:hover {
        transform: scale(1.05);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
    }

    @media (max-width: 768px) {
        .sidebar-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }
</style>

<!-- Botón toggle para móviles -->
<button class="sidebar-toggle" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

<!-- Overlay para cerrar sidebar en móviles -->
<div class="sidebar-overlay" onclick="closeSidebar()"></div>

<div class="sidebar">
    <!-- Header del sidebar -->
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="assets/img/logo_sidebar.png" alt="Logo Municipalidad"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <i class="fas fa-building" style="display: none;"></i>
        </div>
        <div class="sidebar-title">Desarrollo Social</div>
        <div class="sidebar-subtitle">Sistema Municipal</div>
    </div>

    <!-- Navegación principal -->
    <nav class="sidebar-nav">
        <!-- Sección principal -->
        <div class="nav-section">
            <div class="nav-section-title">Principal</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?php echo isActive('dashboard.php'); ?>">
                        <i class="fas fa-home nav-icon"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="familias.php" class="nav-link <?php echo isActive('familias.php'); ?>">
                        <i class="fas fa-users nav-icon"></i>
                        <span class="nav-text">Familias</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="personas.php" class="nav-link <?php echo isActive('personas.php'); ?>">
                        <i class="fas fa-user-friends nav-icon"></i>
                        <span class="nav-text">Personas</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Sección de gestión -->
        <div class="nav-section">
            <div class="nav-section-title">Gestión</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="asignaciones.php" class="nav-link <?php echo isActive('asignaciones.php'); ?>">
                        <i class="fas fa-hand-holding-heart nav-icon"></i>
                        <span class="nav-text">Asignaciones</span>
                        <?php
                        // Mostrar cantidad de pendientes si existe la función
                        if (function_exists('obtenerAsignacionesPendientes')) {
                            $pendientes = obtenerAsignacionesPendientes($pdo);
                            if ($pendientes > 0) {
                                echo '<span class="nav-badge">' . $pendientes . '</span>';
                            }
                        }
                        ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="ayudas.php" class="nav-link <?php echo isActive('ayudas.php'); ?>">
                        <i class="fas fa-gift nav-icon"></i>
                        <span class="nav-text">Tipos de Ayuda</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reportes.php" class="nav-link <?php echo isActive('reportes.php'); ?>">
                        <i class="fas fa-chart-bar nav-icon"></i>
                        <span class="nav-text">Reportes</span>
                    </a>
                </li>
            </ul>
        </div>

        <?php if ($user_role === 'admin'): ?>
            <!-- Sección de administración -->
            <div class="nav-section">
                <div class="nav-section-title">Administración</div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="usuarios.php" class="nav-link <?php echo isActive('usuarios.php'); ?>">
                            <i class="fas fa-user-cog nav-icon"></i>
                            <span class="nav-text">Usuarios</span>
                        </a>
                    </li>
                    <!--<li class="nav-item">
                    <a href="configuracion.php" class="nav-link <?php echo isActive('configuracion.php'); ?>">
                        <i class="fas fa-cogs nav-icon"></i>
                        <span class="nav-text">Configuración</span>
                    </a>
                </li>-->
                    <li class="nav-item">
                        <a href="logs.php" class="nav-link <?php echo isActive('logs.php'); ?>">
                            <i class="fas fa-history nav-icon"></i>
                            <span class="nav-text">Registro de Actividad</span>
                        </a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </nav>

    <!-- Información del usuario -->
    <div class="sidebar-user">
        <div class="user-info">
            <div class="user-avatar" style="background-color: <?php echo getAvatarColor($user_role); ?>">
                <?php echo $user_avatar; ?>
            </div>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <?php echo getRoleBadge($user_role); ?>
            </div>
        </div>

        <a href="logout.php" class="logout-btn" onclick="return confirm('¿Está seguro que desea cerrar sesión?')">
            <i class="fas fa-sign-out-alt"></i>
            <span>Cerrar Sesión</span>
        </a>
    </div>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.querySelector('.sidebar-overlay');

        if (sidebar.classList.contains('show')) {
            closeSidebar();
        } else {
            sidebar.classList.add('show');
            overlay.classList.add('show');
        }
    }

    function closeSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.querySelector('.sidebar-overlay');

        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    }

    // Cerrar sidebar al hacer clic en un enlace (en móviles)
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarLinks = document.querySelectorAll('.nav-link');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth < 768) {
                    closeSidebar();
                }
            });
        });

        // Cerrar con tecla Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });
    });
</script>