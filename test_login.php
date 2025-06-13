<?php
// test_login.php - Script para probar el login y verificar usuarios
require 'includes/conexion.php';

echo "<h2>Test de Sistema de Login</h2>";

// 1. Verificar conexión a la base de datos
echo "<h3>1. Verificación de conexión</h3>";
try {
    $stmt = $pdo->query("SELECT 1");
    echo "✅ Conexión a base de datos exitosa<br>";
} catch (Exception $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "<br>";
    exit;
}

// 2. Verificar tabla de usuarios
echo "<h3>2. Verificación de tabla usuarios</h3>";
try {
    $stmt = $pdo->query("DESCRIBE usuarios");
    echo "✅ Tabla usuarios existe<br>";
    
    // Mostrar estructura
    echo "<table border='1' style='margin: 10px 0;'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th></tr>";
    while ($row = $stmt->fetch()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "❌ Error con tabla usuarios: " . $e->getMessage() . "<br>";
}

// 3. Verificar usuarios existentes
echo "<h3>3. Usuarios en la base de datos</h3>";
try {
    $stmt = $pdo->query("SELECT id, usuario, nombre, apellido, rol, activo, fecha_registro FROM usuarios");
    $usuarios = $stmt->fetchAll();
    
    if (count($usuarios) > 0) {
        echo "<table border='1' style='margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Usuario</th><th>Nombre</th><th>Apellido</th><th>Rol</th><th>Activo</th><th>Fecha</th></tr>";
        foreach ($usuarios as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['usuario']}</td>";
            echo "<td>{$user['nombre']}</td>";
            echo "<td>{$user['apellido']}</td>";
            echo "<td>{$user['rol']}</td>";
            echo "<td>" . ($user['activo'] ? 'Sí' : 'No') . "</td>";
            echo "<td>{$user['fecha_registro']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "⚠️ No hay usuarios en la base de datos<br>";
    }
} catch (Exception $e) {
    echo "❌ Error consultando usuarios: " . $e->getMessage() . "<br>";
}

// 4. Crear usuarios de prueba si no existen
echo "<h3>4. Creación de usuarios de prueba</h3>";
try {
    $usuarios_prueba = [
        ['admin', 'Administrador', 'Sistema', 'password', 'admin'],
        ['supervisor', 'Juan Carlos', 'Supervisor', 'password', 'supervisor'],
        ['empleado', 'María Elena', 'Empleada', 'password', 'empleado']
    ];
    
    foreach ($usuarios_prueba as $user_data) {
        $stmt_check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmt_check->execute([$user_data[0]]);
        
        if (!$stmt_check->fetch()) {
            $password_hash = password_hash($user_data[3], PASSWORD_DEFAULT);
            $stmt_create = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, apellido, contraseña, rol) VALUES (?, ?, ?, ?, ?)");
            $stmt_create->execute([$user_data[0], $user_data[1], $user_data[2], $password_hash, $user_data[4]]);
            echo "✅ Usuario '{$user_data[0]}' creado<br>";
        } else {
            echo "ℹ️ Usuario '{$user_data[0]}' ya existe<br>";
        }
    }
} catch (Exception $e) {
    echo "❌ Error creando usuarios: " . $e->getMessage() . "<br>";
}

// 5. Probar verificación de contraseñas
echo "<h3>5. Prueba de verificación de contraseñas</h3>";
try {
    $stmt = $pdo->prepare("SELECT usuario, contraseña FROM usuarios WHERE usuario IN ('admin', 'supervisor', 'empleado')");
    $stmt->execute();
    $usuarios = $stmt->fetchAll();
    
    foreach ($usuarios as $user) {
        $password_ok = password_verify('password', $user['contraseña']);
        echo "Usuario '{$user['usuario']}': " . ($password_ok ? '✅ Contraseña correcta' : '❌ Contraseña incorrecta') . "<br>";
        
        if (!$password_ok) {
            // Mostrar hash para debug
            echo "  Hash almacenado: " . substr($user['contraseña'], 0, 20) . "...<br>";
            echo "  Hash de 'password': " . substr(password_hash('password', PASSWORD_DEFAULT), 0, 20) . "...<br>";
        }
    }
} catch (Exception $e) {
    echo "❌ Error verificando contraseñas: " . $e->getMessage() . "<br>";
}

// 6. Simular proceso de login
echo "<h3>6. Simulación de login</h3>";
$test_credentials = [
    ['admin', 'password'],
    ['supervisor', 'password'],
    ['empleado', 'password'],
    ['admin', 'wrong_password']
];

foreach ($test_credentials as $cred) {
    echo "<strong>Probando: {$cred[0]} / {$cred[1]}</strong><br>";
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND activo = 1");
        $stmt->execute([$cred[0]]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo "  - Usuario encontrado: ✅<br>";
            $password_ok = password_verify($cred[1], $user['contraseña']);
            echo "  - Contraseña válida: " . ($password_ok ? '✅' : '❌') . "<br>";
            
            if ($password_ok) {
                echo "  - 🎉 LOGIN EXITOSO<br>";
            } else {
                echo "  - ❌ Login fallido - contraseña incorrecta<br>";
            }
        } else {
            echo "  - ❌ Usuario no encontrado o inactivo<br>";
        }
    } catch (Exception $e) {
        echo "  - ❌ Error: " . $e->getMessage() . "<br>";
    }
    echo "<br>";
}

echo "<hr>";
echo "<h3>Información adicional</h3>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "PDO MySQL disponible: " . (extension_loaded('pdo_mysql') ? 'Sí' : 'No') . "<br>";
echo "Timezone: " . date_default_timezone_get() . "<br>";
echo "Fecha actual: " . date('Y-m-d H:i:s') . "<br>";
?>