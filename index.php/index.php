<?php
session_start();
require 'includes/conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_POST['usuario'];
    $contrasena = $_POST['contrasena'];

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
    $stmt->execute([$usuario]);
    $user = $stmt->fetch();

    if ($user && password_verify($contrasena, $user['contrasena'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['usuario'] = $user['usuario'];
        $_SESSION['rol'] = $user['rol'];
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Credenciales incorrectas.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión - Desarrollo Social</title>
    <link rel="stylesheet" href="index.php/styles.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h2>Desarrollo Social</h2>
                <p style="color: var(--text-secondary);">Inicia sesión para continuar</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="usuario">Usuario</label>
                    <input type="text" id="usuario" name="usuario" required>
                </div>

                <div class="form-group">
                    <label for="contrasena">Contraseña</label>
                    <input type="password" id="contrasena" name="contrasena" required>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Iniciar Sesión</button>
                <p style="text-align:center; margin-top: 15px;">
                    <a href="crear_usuario.php">¿No tienes cuenta? Crear usuario</a>
                </p>
            </form>
        </div>
    </div>
</body>
</html>
