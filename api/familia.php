<?php

/**
 * API para obtener datos de una familia específica
 * Usado para el modal de edición
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

session_start();
require '../includes/conexion.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'No autorizado'
    ]);
    exit;
}

// Verificar que se envió el ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'ID de familia requerido'
    ]);
    exit;
}

$familia_id = (int)$_GET['id'];

try {
    // Obtener datos de la familia
    $stmt = $pdo->prepare("
        SELECT 
            id,
            nombre_jefe,
            apellido_jefe,
            dni_jefe,
            telefono,
            direccion,
            barrio,
            cantidad_integrantes,
            estado,
            fecha_registro
        FROM familias 
        WHERE id = ?
    ");

    $stmt->execute([$familia_id]);
    $familia = $stmt->fetch();

    if (!$familia) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Familia no encontrada'
        ]);
        exit;
    }

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'familia' => $familia
    ]);
} catch (PDOException $e) {
    error_log("Error en API familia: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
