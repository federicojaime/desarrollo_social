<?php
session_start();
require 'includes/conexion.php';

// Manejar el login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $usuario = $_POST['usuario'];
    $contraseña = $_POST['contraseña'];

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
    $stmt->execute([$usuario]);
    $user = $stmt->fetch();

    if ($user && password_verify($contraseña, $user['contraseña'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['usuario'] = $user['usuario'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['username'] = $user['nombre'];

        $_SESSION['rol'] = $user['rol'];
        header("Location: dashboard.php");
        exit;
    } else {
        $error_login = "Credenciales incorrectas.";
    }
}

// Manejar el registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $usuario = trim($_POST['nuevo_usuario']);
    $contraseña = $_POST['nueva_contraseña'];
    $confirmar_contraseña = $_POST['confirmar_contraseña'];
    $rol = $_POST['rol'] ?? 'usuario'; // Por defecto 'usuario'

    // Validaciones
    if (empty($usuario) || empty($contraseña)) {
        $error_register = "Usuario y contraseña son obligatorios.";
    } elseif ($contraseña !== $confirmar_contraseña) {
        $error_register = "Las contraseñas no coinciden.";
    } elseif (strlen($contraseña) < 6) {
        $error_register = "La contraseña debe tener al menos 6 caracteres.";
    } else {
        // Verificar si el usuario ya existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmt->execute([$usuario]);
        
        if ($stmt->fetch()) {
            $error_register = "El usuario ya existe.";
        } else {
            // Crear el nuevo usuario
            $contraseña_hash = password_hash($contraseña, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, contraseña, rol) VALUES (?, ?, ?)");
            
            if ($stmt->execute([$usuario, $contraseña_hash, $rol])) {
                $success_register = "Usuario creado exitosamente. Ya puedes iniciar sesión.";
            } else {
                $error_register = "Error al crear el usuario. Intenta nuevamente.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Desarrollo Social</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --success-color: #10b981;
            --error-color: #ef4444;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #d1d5db;
            --bg-light: #f9fafb;
            --white: #ffffff;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg,rgba(24, 67, 185, 0.96) 0%,rgb(21, 51, 187) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-container {
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
        }

        .auth-tabs {
            display: flex;
            background: var(--bg-light);
        }

        .tab-button {
            flex: 1;
            padding: 15px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.3s ease;
            border-bottom: 2px solid transparent;
        }

        .tab-button.active {
            color: var(--primary-color);
            background: var(--white);
            border-bottom-color: var(--primary-color);
        }

        .tab-content {
            padding: 30px;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .auth-header h2 {
            color: var(--text-primary);
            margin-bottom: 8px;
            font-size: 24px;
        }

        .auth-header p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .error {
            background: #fef2f2;
            color: var(--error-color);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #fecaca;
        }

        .success {
            background: #f0fdf4;
            color: var(--success-color);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #bbf7d0;
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        @media (max-width: 480px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-tabs">
            <button class="tab-button active" onclick="switchTab('login')">Iniciar Sesión</button>
        </div>

        <div class="tab-content">
            <!-- Panel de Login -->
            <div id="login-panel" class="tab-panel active">
                <div class="auth-header">
                    <h2>Desarrollo Social</h2>
                    <p>Inicia sesión para continuar</p>
                </div>

                <?php if (!empty($error_login)): ?>
                    <div class="error"><?= htmlspecialchars($error_login) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="usuario">usuario</label>
                        <input type="text" id="usuario" name="usuario" required>
                    </div>

                    <div class="form-group">
                        <label for="contraseña">Contraseña</label>
                        <input type="password" id="contraseña" name="contraseña" required>
                    </div>

                    <button type="submit" name="login" class="btn btn-primary">Iniciar Sesión</button>
                </form>
            </div>


    <script>
        function switchTab(tabName) {
            // Ocultar todos los paneles
            document.querySelectorAll('.tab-panel').forEach(panel => {
                panel.classList.remove('active');
            });
            
            // Desactivar todos los botones
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Activar el panel y botón seleccionado
            document.getElementById(tabName + '-panel').classList.add('active');
            event.target.classList.add('active');
            
            // Limpiar mensajes de error/éxito al cambiar de tab
            document.querySelectorAll('.error, .success').forEach(msg => {
                msg.style.display = 'none';
            });
        }

        // Mostrar automáticamente el tab de registro si hay un error de registro
        <?php if (!empty($error_register) || !empty($success_register)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                switchTab('register');
                document.querySelector('.tab-button[onclick="switchTab(\'register\')"]').classList.add('active');
            });
        <?php endif; ?>
    </script>
</body>
</html>