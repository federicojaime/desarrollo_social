<?php
require 'includes/conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario']);
    $contrasena = $_POST['contrasena'];
    $contrasena_confirm = $_POST['contrasena_confirm'];
    $rol = $_POST['rol'] ?? 'usuario';

    if (empty($usuario) || empty($contrasena)) {
        $error = "Usuario y contraseña son obligatorios.";
    } elseif ($contrasena !== $contrasena_confirm) {
        $error = "Las contraseñas no coinciden.";
    } elseif (strlen($contrasena) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } else {
        // Verificar si el usuario ya existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmt->execute([$usuario]);
        
        if ($stmt->fetch()) {
            $error = "El usuario ya existe.";
        } else {
            $hash = password_hash($contrasena, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, contrasena, rol) VALUES (?, ?, ?)");
            $stmt->execute([$usuario, $hash, $rol]);
            
            $mensaje = "Usuario registrado correctamente.";
            $usuario = ''; // Limpiar el campo
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Usuario - Desarrollo Social</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h2>Registrar Usuario</h2>
                <p style="color: var(--text-secondary);">Crear nueva cuenta de usuario</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($mensaje)): ?>
                <div class="mensaje"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>

            <img src="assets/img/muni_sf.png" alt="Logo Municipalidad" class="logo-muni" style="display:block;margin:0 auto 20px auto;max-width:120px;">

            <form method="POST">
                <div class="form-group">
                    <label for="usuario">Usuario</label>
                    <input type="text" id="usuario" name="usuario" required 
                           value="<?= isset($usuario) ? htmlspecialchars($usuario) : '' ?>"
                           placeholder="Nombre de usuario">
                </div>

                <div class="form-group">
                    <label for="contrasena">Contraseña</label>
                    <input type="password" id="contrasena" name="contrasena" required 
                           placeholder="Mínimo 6 caracteres">
                </div>

                <div class="form-group">
                    <label for="contrasena_confirm">Confirmar contraseña</label>
                    <input type="password" id="contrasena_confirm" name="contrasena_confirm" required 
                           placeholder="Repite la contraseña">
                </div>

                <div class="form-group">
                    <label for="rol">Rol</label>
                    <select id="rol" name="rol">
                        <option value="usuario">Usuario</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Registrar Usuario</button>
            </form>

            <div class="text-center mt-4">
                <a href="login.php">← Volver al login</a>
            </div>
        </div>
    </div>
</body>
</html>