// Rellenar credenciales demo al hacer clic
            document.querySelectorAll('.credential-item').forEach(item => {
                item.addEventListener('click', function() {
                    const label = this.querySelector('.credential-label').textContent.toLowerCase();
                    const value = this.querySelector('.credential-value').textContent;
                    
                    if (label.includes('usuario')) {
                        usuarioInput.value = value;
                        usuarioInput.focus();
                        usuarioInput.classList.add('success');
                    } else if (label.includes('contraseña')) {
                        passwordInput.value = value;
                        passwordInput.focus();
                        passwordInput.classList.add('success');
                    }
                });
            });<?php
session_start();
ob_start(); // Iniciar output buffering para evitar problemas de headers
require 'includes/conexion.php';

// Redirigir si ya está logueado
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$mensaje = '';

// Verificar si hay mensajes de sesión
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    unset($_SESSION['mensaje']);
}

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $usuario = trim($_POST['usuario']);
    $contraseña = $_POST['contraseña'];

    // Validaciones básicas
    if (empty($usuario) || empty($contraseña)) {
        $error = "Por favor, complete todos los campos.";
    } else {
        try {
            // Buscar usuario (sin verificar si está activo primero, para mejores mensajes de error)
            $stmt = $pdo->prepare("
                SELECT id, usuario, nombre, apellido, contraseña, rol, activo, ultimo_acceso 
                FROM usuarios 
                WHERE usuario = ?
            ");
            $stmt->execute([$usuario]);
            $user = $stmt->fetch();

            if (!$user) {
                // Usuario no existe
                $error = "Usuario o contraseña incorrectos.";
            } elseif ($user['activo'] != 1) {
                // Usuario inactivo
                $error = "La cuenta de usuario está desactivada. Contacte al administrador.";
            } elseif (!password_verify($contraseña, $user['contraseña'])) {
                // Contraseña incorrecta
                $error = "Usuario o contraseña incorrectos.";
                
                // Registrar intento fallido
                try {
                    $stmt_intento = $pdo->prepare("
                        UPDATE usuarios 
                        SET intentos_fallidos = COALESCE(intentos_fallidos, 0) + 1 
                        WHERE id = ?
                    ");
                    $stmt_intento->execute([$user['id']]);
                } catch (Exception $e) {
                    error_log("Error registrando intento fallido: " . $e->getMessage());
                }
            } else {
                // Login exitoso - redirección inmediata y robusta
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['usuario'] = $user['usuario'];
                $_SESSION['nombre'] = $user['nombre'] ?: 'Usuario';
                $_SESSION['apellido'] = $user['apellido'] ?: '';
                $_SESSION['username'] = trim(($user['nombre'] ?: 'Usuario') . ' ' . ($user['apellido'] ?: ''));
                $_SESSION['rol'] = $user['rol'] ?: 'empleado';
                $_SESSION['login_time'] = time();

                // Actualizar último acceso
                try {
                    $stmt_update = $pdo->prepare("
                        UPDATE usuarios 
                        SET ultimo_acceso = NOW(), 
                            intentos_fallidos = 0 
                        WHERE id = ?
                    ");
                    $stmt_update->execute([$user['id']]);
                } catch (Exception $e) {
                    error_log("Error actualizando último acceso: " . $e->getMessage());
                }

                // Registrar login en log (si la función existe)
                if (function_exists('registrarLog')) {
                    try {
                        registrarLog($pdo, 'usuarios', $user['id'], 'login', 
                            "Inicio de sesión exitoso", $user['id']);
                    } catch (Exception $e) {
                        error_log("Error registrando log: " . $e->getMessage());
                    }
                }

                // Redirección robusta
                ob_end_clean(); // Limpiar cualquier output buffer
                header("Cache-Control: no-cache, no-store, must-revalidate");
                header("Pragma: no-cache");
                header("Expires: 0");
                header("Location: dashboard.php");
                exit();
            }
        } catch (PDOException $e) {
            error_log("Error en login PDO: " . $e->getMessage());
            $error = "Error del sistema. Por favor, inténtelo nuevamente.";
            
            // Debug temporal (remover en producción)
            if (defined('DEBUG') && DEBUG === true) {
                $error .= " (Debug: " . $e->getMessage() . ")";
            }
        } catch (Exception $e) {
            error_log("Error general en login: " . $e->getMessage());
            $error = "Error inesperado. Por favor, inténtelo nuevamente.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Desarrollo Social - Acceso</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #3b82f6;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --error-color: #ef4444;
            --warning-color: #f59e0b;
            --background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --surface: rgba(255, 255, 255, 0.95);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--background);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Elementos decorativos de fondo */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 2px,
                rgba(255, 255, 255, 0.03) 2px,
                rgba(255, 255, 255, 0.03) 4px
            );
            animation: movePattern 20s linear infinite;
        }

        @keyframes movePattern {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        .login-container {
            background: var(--surface);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
            position: relative;
            z-index: 1;
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 2.5rem 2rem 2rem;
            text-align: center;
            position: relative;
        }

        .login-header::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 20px;
            background: linear-gradient(to bottom right, transparent 49%, white 50%);
        }

        .logo {
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 10px;
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .system-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .system-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 400;
            margin-bottom: 0;
        }

        .login-form {
            padding: 2.5rem 2rem 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .input-group {
            position: relative;
        }

        .form-input {
            width: 100%;
            height: 52px;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 1rem;
            color: var(--text-primary);
            background: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            transform: translateY(-1px);
        }

        .form-input::placeholder {
            color: #9ca3af;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 1.125rem;
            transition: color 0.3s ease;
            z-index: 2;
        }

        .form-input:focus + .input-icon {
            color: var(--primary-color);
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            cursor: pointer;
            font-size: 1.125rem;
            transition: color 0.3s ease;
            z-index: 2;
        }

        .password-toggle:hover {
            color: var(--text-primary);
        }

        .login-btn {
            width: 100%;
            height: 52px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 0.5rem;
            position: relative;
            overflow: hidden;
            font-family: inherit;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .btn-loading {
            position: relative;
            color: transparent;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .demo-info {
            background: linear-gradient(135deg, #eff6ff 0%, #f0f9ff 100%);
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 1.5rem;
            text-align: center;
        }

        .demo-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .demo-credentials {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            font-size: 0.8rem;
        }

        .credential-item {
            background: white;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #e0e7ff;
        }

        .credential-label {
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .credential-value {
            font-family: 'Monaco', 'Menlo', monospace;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
        }

        .footer {
            background: #f8fafc;
            padding: 1.5rem 2rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
        }

        .footer-text {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin: 0;
        }

        .version-info {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-top: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 480px) {
            body {
                padding: 1rem;
            }
            
            .login-container {
                max-width: 100%;
                border-radius: 16px;
            }
            
            .login-header,
            .login-form {
                padding-left: 1.5rem;
                padding-right: 1.5rem;
            }
            
            .system-title {
                font-size: 1.5rem;
            }
            
            .demo-credentials {
                grid-template-columns: 1fr;
            }
        }

        /* Estados de validación */
        .form-input.error {
            border-color: var(--error-color);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .form-input.success {
            border-color: var(--success-color);
        }

        /* Animaciones adicionales */
        .shake {
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <img src="assets/img/logonegro.png" alt="Logo Municipalidad" onerror="this.style.display='none'; this.parentNode.innerHTML='<i class=\'fas fa-heart\' style=\'font-size: 2rem; color: #2563eb;\'></i>';">
            </div>
            <h1 class="system-title">Desarrollo Social</h1>
            <p class="system-subtitle">Sistema de Gestión Municipal</p>
        </div>

        <div class="login-form">
            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <?php if ($mensaje): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="loginForm" novalidate>
                <div class="form-group">
                    <label class="form-label" for="usuario">Usuario</label>
                    <div class="input-group">
                        <input type="text" 
                               id="usuario"
                               name="usuario" 
                               class="form-input" 
                               placeholder="Ingrese su nombre de usuario"
                               value="<?php echo htmlspecialchars($_POST['usuario'] ?? ''); ?>"
                               autocomplete="username"
                               required>
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="contraseña">Contraseña</label>
                    <div class="input-group">
                        <input type="password" 
                               id="contraseña"
                               name="contraseña" 
                               class="form-input" 
                               placeholder="Ingrese su contraseña"
                               autocomplete="current-password"
                               required>
                        <i class="fas fa-lock input-icon"></i>
                        <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                    </div>
                </div>

                <button type="submit" name="login" class="login-btn" id="loginBtn">
                    <span id="btnText">Iniciar Sesión</span>
                </button>
            </form>


        </div>

        <div class="footer">
            <p class="footer-text">
                © <?php echo date('Y'); ?> Municipalidad - Sistema de Desarrollo Social
            </p>
            <p class="version-info">
                Versión 2.0 | Desarrollado con <i class="fas fa-heart" style="color: #ef4444;"></i>
            </p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Referencias a elementos
            const loginForm = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('contraseña');
            const usuarioInput = document.getElementById('usuario');

            // Toggle mostrar/ocultar contraseña
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });

            // Validación en tiempo real
            function validateField(field) {
                const value = field.value.trim();
                if (value === '') {
                    field.classList.add('error');
                    field.classList.remove('success');
                    return false;
                } else {
                    field.classList.remove('error');
                    field.classList.add('success');
                    return true;
                }
            }

            usuarioInput.addEventListener('blur', function() {
                validateField(this);
            });

            passwordInput.addEventListener('blur', function() {
                validateField(this);
            });

            // Limpiar estados de error al escribir
            usuarioInput.addEventListener('input', function() {
                this.classList.remove('error');
            });

            passwordInput.addEventListener('input', function() {
                this.classList.remove('error');
            });

            // Manejo del formulario
            loginForm.addEventListener('submit', function(e) {
                const usuario = usuarioInput.value.trim();
                const contraseña = passwordInput.value;
                
                // Validaciones
                if (!usuario || !contraseña) {
                    e.preventDefault();
                    
                    if (!usuario) validateField(usuarioInput);
                    if (!contraseña) validateField(passwordInput);
                    
                    // Efecto shake
                    loginForm.classList.add('shake');
                    setTimeout(() => loginForm.classList.remove('shake'), 500);
                    
                    return false;
                }
                
                // Estado de carga
                loginBtn.disabled = true;
                loginBtn.classList.add('btn-loading');
                btnText.textContent = 'Verificando credenciales...';
                
                // Permitir que el formulario se envíe
                return true;
            });

            // Auto-focus en el campo usuario
            usuarioInput.focus();

            // Atajos de teclado
            document.addEventListener('keydown', function(e) {
                // Enter para enviar formulario
                if (e.key === 'Enter' && (usuarioInput === document.activeElement || passwordInput === document.activeElement)) {
                    loginForm.dispatchEvent(new Event('submit'));
                }
            });

            // Rellenar credenciales demo al hacer clic
            document.querySelectorAll('.credential-item').forEach(item => {
                item.addEventListener('click', function() {
                    const label = this.querySelector('.credential-label').textContent.toLowerCase();
                    const value = this.querySelector('.credential-value').textContent;
                    
                    if (label.includes('usuario')) {
                        usuarioInput.value = value;
                        usuarioInput.focus();
                        usuarioInput.classList.add('success');
                    } else if (label.includes('contraseña')) {
                        passwordInput.value = value;
                        passwordInput.focus();
                        passwordInput.classList.add('success');
                    }
                });
            });

            // Efecto de ripple en el botón
            loginBtn.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Prevenir envío múltiple
        let formSubmitted = false;
        document.getElementById('loginForm').addEventListener('submit', function() {
            if (formSubmitted) {
                return false;
            }
            formSubmitted = true;
        });
    </script>

    <style>
        /* Efecto ripple */
        .login-btn {
            position: relative;
            overflow: hidden;
        }

        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }

        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
    </style>
</body>
</html>