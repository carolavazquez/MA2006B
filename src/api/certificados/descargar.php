<?php
require_once __DIR__ . '/../../config/database.php';

$token = $_GET['token'] ?? '';

if (!$token) {
    http_response_code(400);
    die(json_encode(['error' => 'Token de descarga requerido']));
}

$db = getDB();

$stmt = $db->prepare("
    SELECT c.*, u.nombre, u.email
    FROM certificados c
    JOIN usuarios u ON c.usuario_id = u.id
    WHERE c.link_descarga = ?
    AND c.link_expiracion > NOW()
    AND c.estado = 'activo'
");
$stmt->execute([$token]);
$cert = $stmt->fetch();

if (!$cert) {
    http_response_code(404);
    die(json_encode(['error' => 'Link inválido o expirado']));
}

$carpeta = "/var/certificados/{$cert['id']}";
$archivo_crt = "$carpeta/certificado.crt";
$archivo_key = "$carpeta/clave_privada.key";

if (!file_exists($archivo_crt) || !file_exists($archivo_key)) {
    http_response_code(404);
    die(json_encode(['error' => 'Archivos de certificado no encontrados']));
}

$zip_path = "$carpeta/certificado_{$cert['usuario_id']}.zip";
$zip = new ZipArchive();
$zip->open($zip_path, ZipArchive::CREATE);
$zip->addFile($archivo_crt, 'certificado.crt');
$zip->addFile($archivo_key, 'clave_privada.key');
$zip->addFromString('LEEME.txt', 
    "Certificado digital de {$cert['nombre']}\n" .
    "Email: {$cert['email']}\n" .
    "Emitido: {$cert['fecha_emision']}\n" .
    "Expira: {$cert['fecha_expiracion']}\n\n" .
    "IMPORTANTE:\n" .
    "- Guarda clave_privada.key en un lugar seguro\n" .
    "- Nunca compartas tu clave privada con nadie\n" .
    "- Si pierdes tu clave privada contacta al administrador\n"
);
$zip->close();

$db->prepare("
    UPDATE certificados SET link_descarga = NULL, link_expiracion = NULL WHERE id = ?
")->execute([$cert['id']]);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="certificado_casamonarca.zip"');
header('Content-Length: ' . filesize($zip_path));
readfile($zip_path);

unlink($zip_path);