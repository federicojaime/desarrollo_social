<?php
session_start();
require 'includes/conexion.php';

echo "<h2>Debug del Login</h2>";

// Mostrar información del POST
echo "<h3>1. Información del formulario:</h3>";
echo "Método: " . $_SERVER['REQUEST_METHOD'] . "<br>";
echo "Datos POST recibidos:<br>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

// Mostrar información de sesión
echo "<h3>2. Información de sesión:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

$error = '';
$mensaje = '';

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['user_id'])) {
    echo "<h3>✅ Usuario ya logueado, redirigiendo...</h3>";
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    echo "<h3>3. Procesando login...</h3>";
    
    $usuario = trim($_POST['usuario']);
    $contraseña = $_POST['contraseña'];
    
    echo "Usuario ingresado: '$usuario'<br>";
    echo "Contraseña ingresada: '" . str_repeat('*', strlen($contraseña)) . "'<br>";
    
    if (empty($usuario) || empty($contraseña)) {
        $error = "Por favor ingrese usuario y contraseña.";
        echo "❌ Campos vacíos<br>";
    } else {
        try {
            echo "<h4>Buscando usuario en BD...</h4>";
            // Buscar usuario en la base de datos
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND activo = 1");
            $stmt->execute([$usuario]);
            $user = $stmt->fetch();
            
            if ($user) {
                echo "✅ Usuario encontrado en BD<br>";
                echo "ID: {$user['id']}<br>";
                echo "Nombre: {$user['nombre']} {$user['apellido']}<br>";
                echo "Rol: {$user['rol']}<br>";
                echo "Activo: {$user['activo']}<br>";
                
                echo "<h4>Verificando contraseña...</h4>";
                $password_verified = password_verify($contraseña, $user['contraseña']);
                echo "Contraseña válida: " . ($password_verified ? '✅ SÍ' : '❌ NO') . "<br>";
                
                if ($password_verified) {
                    echo "<h4>🎉 Login exitoso! Configurando sesión...</h4>";
                    
                    // Login exitoso
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['usuario'] = $user['usuario'];
                    $_SESSION['username'] = $user['usuario'];
                    $_SESSION['nombre'] = $user['nombre'];
                    $_SESSION['apellido'] = $user['apellido'];
                    $_SESSION['rol'] = $user['rol'];
                    
                    echo "Sesión configurada:<br>";
                    echo "<pre>";
                    print_r($_SESSION);
                    echo "</pre>";
                    
                    // Actualizar último acceso
                    $stmt_update = $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW(), intentos_fallidos = 0 WHERE id = ?");
                    $stmt_update->execute([$user['id']]);
                    echo "✅ Último acceso actualizado<br>";
                    
                    // Registrar login en el log si la función existe
                    if (function_exists('registrarLog')) {
                        registrarLog($pdo, 'usuarios', $user['id'], 'login', 'Inicio de sesión exitoso', $user['id']);
                        echo "✅ Log registrado<br>";
                    }
                    
                    echo "<h4>Redirigiendo al dashboard...</h4>";
                    echo '<a href="dashboard.php">Ir al Dashboard manualmente</a><br>';
                    echo '<script>setTimeout(function(){ window.location.href = "dashboard.php"; }, 3000);</script>';
                    
                    // Comentamos el header redirect para ver el debug
                    // header("Location: dashboard.php");
                    // exit;
                } else {
                    echo "❌ Contraseña incorrecta<br>";
                    echo "Hash en BD: " . substr($user['contraseña'], 0, 30) . "...<br>";
                    echo "Verificando 'password': " . (password_verify('password', $user['contraseña']) ? 'SÍ' : 'NO') . "<br>";
                    $error = "Usuario o contraseña incorrectos.";
                }
            } else {
                echo "❌ Usuario no encontrado o inactivo<br>";
                
                // Verificar si existe pero está inactivo
                $stmt_check = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
                $stmt_check->execute([$usuario]);
                $user_inactive = $stmt_check->fetch();
                
                if ($user_inactive) {
                    echo "Usuario existe pero está inactivo: {$user_inactive['activo']}<br>";
                } else {
                    echo "Usuario no existe en la base de datos<br>";
                }
                
                $error = "Usuario o contraseña incorrectos.";
            }
        } catch (PDOException $e) {
            $error = "Error de conexión. Intente nuevamente.";
            echo "❌ Error de BD: " . $e->getMessage() . "<br>";
            error_log("Error de login: " . $e->getMessage());
        }
    }
}

// Mostrar errores si los hay
if ($error) {
    echo "<h3>❌ Error:</h3>";
    echo "<div style='color: red; font-weight: bold;'>$error</div>";
}

if ($mensaje) {
    echo "<h3>✅ Mensaje:</h3>";
    echo "<div style='color: green; font-weight: bold;'>$mensaje</div>";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Login</title>
</head>
<body>
    <hr>
    <h3>Formulario de prueba:</h3>
    <form method="POST">
        <div>
            <label>Usuario:</label>
            <input type="text" name="usuario" value="admin" required>
        </div>
        <div>
            <label>Contraseña:</label>
            <input type="password" name="contraseña" value="password" required>
        </div>
        <button type="submit" name="login">Iniciar Sesión</button>
    </form>
    
    <hr>
    <h3>Enlaces de prueba:</h3>
    <a href="login.php">Volver al login normal</a><br>
    <a href="dashboard.php">Ir al dashboard</a><br>
    <a href="logout.php">Cerrar sesión</a>
</body>
</html>