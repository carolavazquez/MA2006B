<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';

header('Content-Type: application/json');

$admin = verificarAcceso(1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Método no permitido']));
}

$body = json_decode(file_get_contents('php://input'), true);
$usuario_id = $body['usuario_id'] ?? '';

if (!$usuario_id) {
    http_response_code(400);
    die(json_encode(['error' => 'usuario_id es requerido']));
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    http_response_code(404);
    die(json_encode(['error' => 'Usuario no encontrado']));
}

if ($usuario['activo']) {
    http_response_code(409);
    die(json_encode(['error' => 'El usuario ya está activo']));
}

$db->prepare("UPDATE usuarios SET activo = 1 WHERE id = ?")->execute([$usuario_id]);

$db->prepare("
    INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
    VALUES (UUID(), ?, 'ACTIVAR_USUARIO', 'usuarios', ?)
")->execute([$admin['sub'], $usuario_id]);

echo json_encode([
    'status' => 'ok',
    'mensaje' => 'Acceso activado. El colaborador ya puede iniciar sesión.',
    'usuario_afectado' => $usuario['email']
]);