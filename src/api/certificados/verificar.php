<?php
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Método no permitido']));
}

$body = json_decode(file_get_contents('php://input'), true);
$usuario_id = $body['usuario_id'] ?? '';
$hash = $body['hash'] ?? '';

if (!$usuario_id || !$hash) {
    http_response_code(400);
    die(json_encode(['error' => 'usuario_id y hash son requeridos']));
}

$db = getDB();

$stmt = $db->prepare("
    SELECT c.*, u.nombre, u.email, u.nivel, u.area
    FROM certificados c
    JOIN usuarios u ON c.usuario_id = u.id
    WHERE c.usuario_id = ?
    AND c.hash_certificado = ?
    AND c.estado = 'activo'
    AND c.fecha_expiracion > NOW()
");
$stmt->execute([$usuario_id, $hash]);
$cert = $stmt->fetch();

if (!$cert) {
    http_response_code(404);
    die(json_encode([
        'status' => 'invalido',
        'mensaje' => 'Certificado no válido, revocado o expirado'
    ]));
}

echo json_encode([
    'status' => 'valido',
    'mensaje' => 'Certificado válido y activo',
    'titular' => [
        'nombre'    => $cert['nombre'],
        'email'     => $cert['email'],
        'nivel'     => $cert['nivel'],
        'area'      => $cert['area']
    ],
    'certificado' => [
        'id'               => $cert['id'],
        'fecha_emision'    => $cert['fecha_emision'],
        'fecha_expiracion' => $cert['fecha_expiracion'],
        'estado'           => $cert['estado']
    ]
]);