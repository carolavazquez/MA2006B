<?php
require_once __DIR__ . '/../auth/jwt.php';

function verificarAcceso($nivel_requerido, $area_requerida = null) {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$auth || !str_starts_with($auth, 'Bearer ')) {
        http_response_code(401);
        die(json_encode(['error' => 'Token requerido']));
    }

    $token = substr($auth, 7);
    $payload = validarJWT($token);

    if (!$payload) {
        http_response_code(401);
        die(json_encode(['error' => 'Token inválido o expirado']));
    }

    if ($payload['nivel'] > $nivel_requerido) {
        http_response_code(403);
        die(json_encode([
            'error' => 'Acceso denegado',
            'tu_nivel' => $payload['nivel'],
            'nivel_requerido' => $nivel_requerido
        ]));
    }

    if ($area_requerida && $payload['nivel'] > 1) {
        if ($payload['area'] !== $area_requerida) {
            http_response_code(403);
            die(json_encode([
                'error' => 'Acceso denegado',
                'motivo' => 'No tienes acceso a esta área'
            ]));
        }
    }

    // Verificar si es cuenta espejo — solo lectura
    if ($payload) {
        $db = getDB();
        $stmt = $db->prepare("SELECT es_espejo FROM usuarios WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $usuario = $stmt->fetch();

        if ($usuario && $usuario['es_espejo'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
            http_response_code(403);
            die(json_encode([
                'error' => 'Cuenta de solo lectura. Esta cuenta espejo no puede ejecutar acciones.'
            ]));
        }
    }

    return $payload;
}