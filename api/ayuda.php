<?php

/**
 * API para obtener datos de un tipo de ayuda específico
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
        'error' => 'ID de ayuda requerido'
    ]);
    exit;
}

$ayuda_id = (int)$_GET['id'];

try {
    // Obtener datos básicos de la ayuda
    $stmt = $pdo->prepare("
        SELECT 
            id,
            nombre_ayuda,
            descripcion,
            activo,
            fecha_registro
        FROM ayudas 
        WHERE id = ?
    ");

    $stmt->execute([$ayuda_id]);
    $ayuda = $stmt->fetch();

    if (!$ayuda) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Tipo de ayuda no encontrado'
        ]);
        exit;
    }

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'ayuda' => $ayuda
    ]);
} catch (PDOException $e) {
    error_log("Error en API ayuda: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
