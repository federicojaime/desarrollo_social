<?php
// config.php
$servername = "localhost";
$username = "root";       // o tu usuario MySQL
$password = "";           // o tu contraseña MySQL
$dbname = "desarrollo_social";  // Cambia al nombre real de tu BD

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Revisar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
?>
