<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/jwt.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Método no permitido']));
}

$body = json_decode(file_get_contents('php://input'), true);
$email = $body['email'] ?? '';
$password = $body['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    die(json_encode(['error' => 'Email y contraseña requeridos']));
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1");
$stmt->execute([$email]);
$usuario = $stmt->fetch();

if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Credenciales inválidas']));
}

if ($usuario['totp_secret']) {
    die(json_encode([
        'requiere_totp' => true,
        'usuario_id' => $usuario['id']
    ]));
}

$token = generarJWT($usuario['id'], $usuario['nivel'], $usuario['area']);
echo json_encode(['token' => $token]);