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

$stmt = $db->prepare("
    SELECT * FROM certificados 
    WHERE usuario_id = ? AND estado = 'activo'
");
$stmt->execute([$usuario_id]);
$cert = $stmt->fetch();

if (!$cert) {
    http_response_code(404);
    die(json_encode(['error' => 'No se encontró un certificado activo para este usuario']));
}

$db->prepare("
    UPDATE certificados SET estado = 'revocado' WHERE id = ?
")->execute([$cert['id']]);

$carpeta = "/var/certificados/{$cert['id']}";
if (file_exists("$carpeta/certificado.crt")) unlink("$carpeta/certificado.crt");
if (file_exists("$carpeta/clave_privada.key")) unlink("$carpeta/clave_privada.key");
if (is_dir($carpeta)) rmdir($carpeta);

$db->prepare("
    INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
    VALUES (UUID(), ?, 'REVOCAR_CERTIFICADO', 'certificados', ?)
")->execute([$admin['sub'], $cert['id']]);

echo json_encode([
    'status' => 'ok',
    'mensaje' => 'Certificado revocado. Los archivos han sido eliminados del servidor.',
    'cert_id' => $cert['id']
]);
