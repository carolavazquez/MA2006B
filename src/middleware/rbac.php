<?php
require_once __DIR__ . '/../auth/jwt.php';
require_once __DIR__ . '/../config/database.php';

function permisosEfectivos($usuario_id, $nivel) {
    $base = match((int)$nivel) {
        1 => ['r' => 1, 'w' => 1, 'x' => 1, 'e' => 1],
        2 => ['r' => 1, 'w' => 1, 'x' => 1, 'e' => 0],
        3 => ['r' => 1, 'w' => 1, 'x' => 0, 'e' => 0],
        4 => ['r' => 1, 'w' => 0, 'x' => 0, 'e' => 0],
        default => ['r' => 0, 'w' => 0, 'x' => 0, 'e' => 0],
    };

    $db = getDB();
    $stmt = $db->prepare("SELECT permiso, valor FROM permisos_overrides WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);

    foreach ($stmt->fetchAll() as $row) {
        if (isset($base[$row['permiso']])) {
            $base[$row['permiso']] = (int)$row['valor'];
        }
    }

    return $base;
}

function verificarAcceso($nivel_requerido, $area_requerida = null, $permiso_requerido = null) {
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

    /* Cuenta espejo — solo lectura */
    $db = getDB();
    $stmt = $db->prepare("SELECT es_espejo FROM usuarios WHERE id = ?");
    $stmt->execute([$payload['sub']]);
    $usuario = $stmt->fetch();

    if ($usuario && $usuario['es_espejo'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ruta_actual = $_SERVER['REQUEST_URI'] ?? '';
        if (!str_contains($ruta_actual, '/usuarios/recuperar-admin')) {
            http_response_code(403);
            die(json_encode([
                'error' => 'Cuenta de solo lectura. Esta cuenta espejo no puede ejecutar acciones.'
            ]));
        }
    }

    /* Verificación de permiso individual (overrides) */
    if ($permiso_requerido) {
        $efectivos = permisosEfectivos($payload['sub'], $payload['nivel']);
        if (empty($efectivos[$permiso_requerido])) {
            http_response_code(403);
            die(json_encode([
                'error' => 'Acceso denegado',
                'motivo' => "Tu cuenta no tiene el permiso '$permiso_requerido' habilitado.",
                'permiso_requerido' => $permiso_requerido
            ]));
        }
    }

    return $payload;
}