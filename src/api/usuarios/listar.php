<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';

header('Content-Type: application/json');

$admin = verificarAcceso(1);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die(json_encode(['error' => 'Método no permitido']));
}

$db = getDB();

$filtros = [];
$valores = [];

if (isset($_GET['nivel'])) {
    $filtros[] = "nivel = ?";
    $valores[] = $_GET['nivel'];
}

if (isset($_GET['area'])) {
    $filtros[] = "area = ?";
    $valores[] = $_GET['area'];
}

if (isset($_GET['activo'])) {
    $filtros[] = "activo = ?";
    $valores[] = $_GET['activo'];
}

$where = count($filtros) > 0 ? "WHERE " . implode(" AND ", $filtros) : "";

$stmt = $db->prepare("
    SELECT id, nombre, email, nivel, area, activo, creado_en
    FROM usuarios
    $where
    ORDER BY nivel ASC, nombre ASC
");
$stmt->execute($valores);
$usuarios = $stmt->fetchAll();

echo json_encode([
    'status' => 'ok',
    'total' => count($usuarios),
    'usuarios' => $usuarios
]);