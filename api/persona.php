<?php
/**
 * API para obtener datos de una persona específica
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
        'error' => 'ID de persona requerido'
    ]);
    exit;
}

$persona_id = (int)$_GET['id'];

try {
    // Obtener datos de la persona
    $stmt = $pdo->prepare("
        SELECT 
            id,
            nombre,
            apellido,
            cedula,
            direccion,
            telefono,
            id_familia,
            fecha_registro
        FROM personas 
        WHERE id = ?
    ");

    $stmt->execute([$persona_id]);
    $persona = $stmt->fetch();

    if (!$persona) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Persona no encontrada'
        ]);
        exit;
    }

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'persona' => $persona
    ]);
} catch (PDOException $e) {
    error_log("Error en API persona: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
?>