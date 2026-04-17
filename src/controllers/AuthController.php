<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';

class AuthController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function me() {
        $payload = verificarAcceso(4); // cualquier nivel puede acceder

        $stmt = $this->db->prepare("
            SELECT id, nombre, email, nivel, area, activo, creado_en
            FROM usuarios WHERE id = ?
        ");
        $stmt->execute([$payload['sub']]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            http_response_code(404);
            return ['error' => 'Usuario no encontrado'];
        }

        return ['status' => 'ok', 'usuario' => $usuario];
    }
}