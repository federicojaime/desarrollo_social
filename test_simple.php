<?php
session_start();
require 'includes/conexion.php';

echo "<h2>Prueba Manual de Login</h2>";

// Simular un login manual
$usuario = 'admin';
$contraseña = 'password';

echo "<h3>Intentando login con: $usuario / $contraseña</h3>";

try {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND activo = 1");
    $stmt->execute([$usuario]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($contraseña, $user['contraseña'])) {
        echo "✅ Credenciales correctas<br>";
        
        // Configurar sesión
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['usuario'] = $user['usuario'];
        $_SESSION['username'] = $user['usuario'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['apellido'] = $user['apellido'];
        $_SESSION['rol'] = $user['rol'];
        
        echo "✅ Sesión configurada<br>";
        echo "<pre>";
        print_r($_SESSION);
        echo "</pre>";
        
        echo "<h3>✅ Login exitoso!</h3>";
        echo '<a href="dashboard.php" style="font-size: 18px; color: green;">🎉 IR AL DASHBOARD</a><br><br>';
        echo '<a href="logout.php">Cerrar sesión</a>';
        
    } else {
        echo "❌ Credenciales incorrectas<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>