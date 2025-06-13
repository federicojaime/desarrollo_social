<?php
session_start();

// Si ya está logueado, ir al dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Si no está logueado, ir al login
header("Location: login.php");
exit;
?>