<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';

header('Content-Type: application/json');

$admin = verificarAcceso(1);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die(json_encode(['error' => 'Método no permitido']));
}

$usuario_id = $_GET['id'] ?? '';

if (!$usuario_id) {
    http_response_code(400);
    die(json_encode(['error' => 'id es requerido']));
}

$db = getDB();

$stmt = $db->prepare("
    SELECT id, nombre, email, nivel, area, activo, creado_en
    FROM usuarios
    WHERE id = ?
");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    http_response_code(404);
    die(json_encode(['error' => 'Usuario no encontrado']));
}

$stmt = $db->prepare("
    SELECT accion, tabla_afectada, creado_en
    FROM bitacora
    WHERE registro_id = ?
    ORDER BY creado_en DESC
    LIMIT 20
");
$stmt->execute([$usuario_id]);
$historial = $stmt->fetchAll();

echo json_encode([
    'status' => 'ok',
    'usuario' => $usuario,
    'historial' => $historial
]);