<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=desarrollo_social;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error en la conexión: " . $e->getMessage());
}
?>

<?php
$host = "localhost";
$usuario = "root";
$contrasena = "";
$base_datos = "desarrollo_social";

$conn = new mysqli($host, $usuario, $contrasena, $base_datos);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}
?>
