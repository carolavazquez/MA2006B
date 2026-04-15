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

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ? AND activo = 1");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    http_response_code(404);
    die(json_encode(['error' => 'Usuario no encontrado o inactivo']));
}

$stmt = $db->prepare("SELECT id FROM certificados WHERE usuario_id = ? AND estado = 'activo'");
$stmt->execute([$usuario_id]);
if ($stmt->fetch()) {
    http_response_code(409);
    die(json_encode(['error' => 'El usuario ya tiene un certificado activo']));
}

$config = [
    'digest_alg'       => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$par_claves = openssl_pkey_new($config);

$dn = [
    'commonName'         => $usuario['nombre'],
    'organizationName'   => 'Casa Monarca',
    'organizationalUnitName' => $usuario['area'],
    'countryName'        => 'MX',
];

$csr = openssl_csr_new($dn, $par_claves);
$certificado = openssl_csr_sign($csr, null, $par_claves, 1461); // 4 años = 1461 días

openssl_x509_export($certificado, $crt_string);
openssl_pkey_export($par_claves, $key_string);

$clave_publica = openssl_pkey_get_details($par_claves)['key'];
$hash_certificado = hash('sha256', $crt_string);

$cert_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$token_descarga = bin2hex(random_bytes(16));
$carpeta = "/var/certificados/$cert_id";
mkdir($carpeta, 0700, true);

file_put_contents("$carpeta/certificado.crt", $crt_string);
file_put_contents("$carpeta/clave_privada.key", $key_string);

$fecha_expiracion = date('Y-m-d H:i:s', strtotime('+4 years'));
$link_expiracion = date('Y-m-d H:i:s', strtotime('+24 hours'));

$stmt = $db->prepare("
    INSERT INTO certificados (id, usuario_id, clave_publica, hash_certificado, fecha_expiracion, link_descarga, link_expiracion)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $cert_id,
    $usuario_id,
    $clave_publica,
    $hash_certificado,
    $fecha_expiracion,
    $token_descarga,
    $link_expiracion
]);

$db->prepare("
    INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
    VALUES (UUID(), ?, 'GENERAR_CERTIFICADO', 'certificados', ?)
")->execute([$admin['sub'], $cert_id]);

echo json_encode([
    'status' => 'ok',
    'mensaje' => 'Certificado generado exitosamente.',
    'cert_id' => $cert_id,
    'hash' => $hash_certificado,
    'fecha_expiracion' => $fecha_expiracion,
    'link_descarga' => "http://localhost:8080/api/certificados/descargar.php?token=$token_descarga",
    'link_expira_en' => $link_expiracion
]);