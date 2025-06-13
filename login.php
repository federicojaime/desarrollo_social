<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema - Desarrollo Social</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --surface: rgba(255, 255, 255, 0.95);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        html {
            height: 100%;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--background);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Patrón de fondo animado */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: repeating-linear-gradient(45deg,
                    transparent,
                    transparent 2px,
                    rgba(255, 255, 255, 0.03) 2px,
                    rgba(255, 255, 255, 0.03) 4px);
            animation: movePattern 20s linear infinite;
            z-index: -1;
        }

        @keyframes movePattern {
            0% {
                transform: translate(0, 0);
            }

            100% {
                transform: translate(50px, 50px);
            }
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
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 2.5rem 2rem 2rem;
            text-align: center;
            position: relative;
        }

        .login-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 20px;
            background: linear-gradient(to bottom right, transparent 49%, white 50%);
        }

        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo img {
            width: 280px;
            height: 280px;
            object-fit: contain;
            display: block;
        }

        .logo i {
            font-size: 2.5rem;
            color: white;
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
        }

        .login-form {
            padding: 2.5rem 2rem;
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
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 1rem;
            color: var(--text-primary);
            background: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            transform: translateY(-1px);
        }

        .form-input.error {
            border-color: var(--error);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .form-input.success {
            border-color: var(--success);
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

        .form-input:focus+.input-icon {
            color: var(--primary);
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

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-container input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }

        .login-btn {
            width: 100%;
            height: 52px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            font-family: inherit;
        }

        .login-btn:hover:not(:disabled) {
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

        .demo-credentials {
            background: linear-gradient(135deg, #eff6ff 0%, #f0f9ff 100%);
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1.5rem;
            text-align: center;
        }

        .demo-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .demo-item {
            margin: 0.25rem 0;
            font-family: monospace;
            padding: 0.5rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .demo-item:hover {
            background-color: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .system-stats {
            background: #f8fafc;
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--border);
        }

        .footer-text {
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin: 0;
        }

        .shake {
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-5px);
            }

            75% {
                transform: translateX(5px);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
                height: 100vh;
                overflow-y: auto;
            }

            .login-container {
                max-width: 100%;
                border-radius: 16px;
                margin: auto;
            }

            .login-header,
            .login-form {
                padding-left: 1.5rem;
                padding-right: 1.5rem;
            }

            .system-title {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 480px) {

            .form-input,
            .login-btn {
                height: 56px;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <img src="assets/img/logo_munisf.png" alt="Logo Municipalidad"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-building" style="display: none;"></i>
            </div>
            <h1 class="system-title">Desarrollo Social</h1>
            <p class="system-subtitle">Sistema de Gestión Municipal</p>
        </div>

        <div class="login-form">
            <div class="alert alert-error" id="errorAlert" style="display: none;">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="errorMessage"></span>
            </div>

            <div class="alert alert-success" id="successAlert" style="display: none;">
                <i class="fas fa-check-circle"></i>
                <span id="successMessage"></span>
            </div>

            <form method="POST" id="loginForm" novalidate>
                <div class="form-group">
                    <label class="form-label" for="usuario">Usuario</label>
                    <div class="input-group">
                        <input type="text"
                            id="usuario"
                            name="usuario"
                            class="form-input"
                            placeholder="Ingrese su nombre de usuario"
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

                <div class="form-options">
                    <div class="checkbox-container">
                        <input type="checkbox" id="recordar" name="recordar">
                        <label for="recordar">Recordarme</label>
                    </div>
                </div>

                <button type="submit" name="login" class="login-btn" id="loginBtn">
                    <span id="btnText">Iniciar Sesión</span>
                </button>
            </form>

            <div class="demo-credentials" id="demoCredentials">
                <div class="demo-title">
                    <i class="fas fa-key"></i> Credenciales de Prueba
                </div>
                <div class="demo-list">
                    <div class="demo-item" onclick="llenarCredenciales('admin', 'password')">
                        👤 admin / password (Administrador)
                    </div>
                    <div class="demo-item" onclick="llenarCredenciales('supervisor', 'password')">
                        👤 supervisor / password (Supervisor)
                    </div>
                    <div class="demo-item" onclick="llenarCredenciales('empleado', 'password')">
                        👤 empleado / password (Empleado)
                    </div>
                </div>
            </div>
        </div>

        <div class="system-stats">
            <p class="footer-text">
                © 2025 Municipalidad - Sistema de Desarrollo Social
            </p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('contraseña');
            const usuarioInput = document.getElementById('usuario');

            // Toggle mostrar/ocultar contraseña
            togglePassword?.addEventListener('click', function() {
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

            usuarioInput?.addEventListener('blur', function() {
                validateField(this);
            });
            passwordInput?.addEventListener('blur', function() {
                validateField(this);
            });

            // Limpiar estados de error al escribir
            usuarioInput?.addEventListener('input', function() {
                this.classList.remove('error');
            });
            passwordInput?.addEventListener('input', function() {
                this.classList.remove('error');
            });

            // Manejo del formulario
            loginForm?.addEventListener('submit', function(e) {
                const usuario = usuarioInput.value.trim();
                const contraseña = passwordInput.value;

                if (!usuario || !contraseña) {
                    e.preventDefault();
                    if (!usuario) validateField(usuarioInput);
                    if (!contraseña) validateField(passwordInput);

                    loginForm.classList.add('shake');
                    setTimeout(() => loginForm.classList.remove('shake'), 500);
                    return false;
                }

                // Estado de carga
                loginBtn.disabled = true;
                btnText.textContent = 'Verificando credenciales...';

                return true;
            });

            // Auto-focus
            usuarioInput?.focus();
        });

        function llenarCredenciales(usuario, password) {
            document.getElementById('usuario').value = usuario;
            document.getElementById('contraseña').value = password;
            document.getElementById('usuario').classList.add('success');
            document.getElementById('contraseña').classList.add('success');
            document.getElementById('usuario').focus();
        }

        // Prevenir envío múltiple
        let formSubmitted = false;
        document.getElementById('loginForm')?.addEventListener('submit', function() {
            if (formSubmitted) {
                return false;
            }
            formSubmitted = true;
        });
    </script>
</body>

</html>