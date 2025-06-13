<?php
// Obtener la página actual para marcar el menú activo
$current_page = basename($_SERVER['PHP_SELF']);

// Función para determinar si un enlace está activo
function isActive($page) {
    global $current_page;
    return $current_page === $page ? 'active' : '';
}
?>

<div class="sidebar">
    <div class="sidebar-header">
        <h4><i class="fas fa-heart me-2"></i>Desarrollo Social</h4>
        <small class="text-light opacity-75">Sistema de Gestión v2.0</small>
    </div>
    
    <nav class="sidebar-menu">
        <a href="dashboard.php" class="<?php echo isActive('dashboard.php'); ?>">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
        
        <a href="familias.php" class="<?php echo isActive('familias.php'); ?>">
            <i class="fas fa-users"></i>
            <span>Familias</span>
        </a>
        
        <a href="asignaciones.php" class="<?php echo isActive('asignaciones.php'); ?>">
            <i class="fas fa-hand-holding-heart"></i>
            <span>Asignaciones</span>
        </a>
        
        <a href="ayudas.php" class="<?php echo isActive('ayudas.php'); ?>">
            <i class="fas fa-gift"></i>
            <span>Tipos de Ayuda</span>
        </a>
        
        <a href="reportes.php" class="<?php echo isActive('reportes.php'); ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Reportes</span>
        </a>
        
        <a href="actividad.php" class="<?php echo isActive('actividad.php'); ?>">
            <i class="fas fa-history"></i>
            <span>Registro de Actividad</span>
        </a>
        
        <?php if ($user_role === 'admin'): ?>
        <div class="mt-3 mb-2">
            <small class="text-light opacity-50 px-3 text-uppercase" style="font-size: 0.7rem;">Administración</small>
        </div>
        
        <a href="usuarios.php" class="<?php echo isActive('usuarios.php'); ?>">
            <i class="fas fa-user-cog"></i>
            <span>Usuarios</span>
        </a>
        
        <a href="configuracion.php" class="<?php echo isActive('configuracion.php'); ?>">
            <i class="fas fa-cogs"></i>
            <span>Configuración</span>
        </a>
        
        <a href="respaldo.php" class="<?php echo isActive('respaldo.php'); ?>">
            <i class="fas fa-database"></i>
            <span>Respaldo de Datos</span>
        </a>
        <?php endif; ?>
    </nav>
    
    <!-- Información del usuario -->
    <div class="mt-auto p-3 border-top border-light border-opacity-25">
        <div class="d-flex align-items-center mb-2">
            <div class="avatar-sm bg-light text-primary rounded-circle d-flex align-items-center justify-content-center me-2">
                <?php 
                $nombre = $_SESSION['nombre'] ?? $_SESSION['username'] ?? 'U';
                echo strtoupper(substr($nombre, 0, 1)); 
                ?>
            </div>
            <div class="flex-grow-1">
                <small class="d-block text-white fw-medium">
                    <?php echo htmlspecialchars($_SESSION['nombre'] ?? $_SESSION['username'] ?? 'Usuario'); ?>
                </small>
                <small class="text-light opacity-75">
                    <?php echo ucfirst($user_role); ?>
                </small>
            </div>
        </div>
        
        <!-- Botón de cerrar sesión -->
        <a href="logout.php" class="btn btn-outline-light btn-sm w-100" 
           onclick="return confirm('¿Está seguro que desea cerrar sesión?')">
            <i class="fas fa-sign-out-alt me-2"></i>
            Cerrar Sesión
        </a>
    </div>
</div>

<!-- Botón para mostrar/ocultar sidebar en móviles -->
<button class="btn btn-primary d-md-none position-fixed" 
        style="top: 1rem; left: 1rem; z-index: 1050;" 
        type="button" 
        onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

<!-- Overlay para cerrar sidebar en móviles -->
<div class="sidebar-overlay d-md-none" onclick="closeSidebar()" style="display: none;"></div>

<style>
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
}

@media (max-width: 767.98px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
}
</style>

<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    if (sidebar.classList.contains('show')) {
        closeSidebar();
    } else {
        sidebar.classList.add('show');
        overlay.style.display = 'block';
    }
}

function closeSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    sidebar.classList.remove('show');
    overlay.style.display = 'none';
}

// Cerrar sidebar al hacer clic en un enlace (en móviles)
document.addEventListener('DOMContentLoaded', function() {
    const sidebarLinks = document.querySelectorAll('.sidebar-menu a');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 768) {
                closeSidebar();
            }
        });
    });
});
</script>