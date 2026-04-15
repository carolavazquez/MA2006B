<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('JWT_SECRET', 'casa_monarca_secret_key_2026_!xK9#mP2$nQ8@vL5&wR3^tY7*uI1_OSF_monarca');
define('JWT_ALGO', 'HS256');
define('JWT_EXPIRATION', 3600);

function generarJWT($usuario_id, $nivel, $area) {
    $payload = [
        'iss' => 'casa-monarca',
        'iat' => time(),
        'exp' => time() + JWT_EXPIRATION,
        'sub' => $usuario_id,
        'nivel' => $nivel,
        'area' => $area
    ];
    return JWT::encode($payload, JWT_SECRET, JWT_ALGO);
}

function validarJWT($token) {
    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, JWT_ALGO));
        return (array) $decoded;
    } catch (Exception $e) {
        return null;
    }
}