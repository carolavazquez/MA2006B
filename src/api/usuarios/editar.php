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

$campos_permitidos = ['nombre', 'nivel', 'area', 'activo'];
$cambios = [];
$valores = [];

foreach ($campos_permitidos as $campo) {
    if (isset($body[$campo])) {
        if ($campo === 'nivel' && !in_array((int)$body[$campo], [1, 2, 3, 4])) {
            http_response_code(400);
            die(json_encode(['error' => 'Nivel inválido. Debe ser 1, 2, 3 o 4']));
        }
        $areas_validas = ['Humanitaria', 'PsicoSocial', 'Legal', 'Comunicacion', 'Almacen', 'TI'];
        if ($campo === 'area' && !in_array($body[$campo], $areas_validas)) {
            http_response_code(400);
            die(json_encode(['error' => 'Área inválida', 'areas_validas' => $areas_validas]));
        }
        $cambios[] = "$campo = ?";
        $valores[] = $body[$campo];
    }
}

if (empty($cambios)) {
    http_response_code(400);
    die(json_encode(['error' => 'No se enviaron campos a actualizar']));
}

$valores[] = $usuario_id;
$db->prepare("UPDATE usuarios SET " . implode(', ', $cambios) . " WHERE id = ?")->execute($valores);

$db->prepare("
    INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
    VALUES (UUID(), ?, 'EDITAR_USUARIO', 'usuarios', ?)
")->execute([$admin['sub'], $usuario_id]);

echo json_encode([
    'status' => 'ok',
    'mensaje' => 'Usuario actualizado correctamente.',
    'campos_actualizados' => array_keys(array_filter($body, fn($k) => in_array($k, $campos_permitidos), ARRAY_FILTER_USE_KEY))
]);