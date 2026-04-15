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

$nombre = $body['nombre'] ?? '';
$email  = $body['email'] ?? '';
$nivel  = $body['nivel'] ?? '';
$area   = $body['area'] ?? '';

if (!$nombre || !$email || !$nivel || !$area) {
    http_response_code(400);
    die(json_encode(['error' => 'nombre, email, nivel y area son requeridos']));
}

if (!in_array((int)$nivel, [2, 3, 4])) {
    http_response_code(400);
    die(json_encode(['error' => 'Nivel inválido. Solo se permiten niveles 2, 3 y 4. El administrador únicamente puede crearse mediante el seed inicial.']));
}

$areas_validas = ['Humanitaria', 'PsicoSocial', 'Legal', 'Comunicacion', 'Almacen', 'TI'];
if (!in_array($area, $areas_validas)) {
    http_response_code(400);
    die(json_encode(['error' => 'Área inválida', 'areas_validas' => $areas_validas]));
}

$db = getDB();

$stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(409);
    die(json_encode(['error' => 'Ya existe un usuario con ese email']));
}

$id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$password_temporal = bin2hex(random_bytes(8));
$password_hash = password_hash($password_temporal, PASSWORD_BCRYPT);

$stmt = $db->prepare("
    INSERT INTO usuarios (id, nombre, email, password_hash, nivel, area, activo)
    VALUES (?, ?, ?, ?, ?, ?, 0)
");
$stmt->execute([$id, $nombre, $email, $password_hash, $nivel, $area]);

$db->prepare("
    INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
    VALUES (UUID(), ?, 'CREAR_USUARIO', 'usuarios', ?)
")->execute([$admin['sub'], $id]);

echo json_encode([
    'status' => 'ok',
    'mensaje' => 'Colaborador creado. Acceso inactivo hasta ser activado por el admin.',
    'credenciales' => [
        'email' => $email,
        'password_temporal' => $password_temporal
    ]
]);