<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';

class AuditController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function listar() {
        verificarAcceso(1, null, 'r');

        $filtros = [];
        $valores = [];

        if (!empty($_GET['accion'])) {
            $filtros[] = "b.accion LIKE ?";
            $valores[] = '%' . $_GET['accion'] . '%';
        }

        if (!empty($_GET['tabla'])) {
            $filtros[] = "b.tabla_afectada = ?";
            $valores[] = $_GET['tabla'];
        }

        if (!empty($_GET['usuario_id'])) {
            $filtros[] = "b.usuario_id = ?";
            $valores[] = $_GET['usuario_id'];
        }

        if (!empty($_GET['desde'])) {
            $filtros[] = "b.creado_en >= ?";
            $valores[] = $_GET['desde'];
        }

        if (!empty($_GET['hasta'])) {
            $filtros[] = "b.creado_en <= ?";
            $valores[] = $_GET['hasta'];
        }

        $where  = count($filtros) > 0 ? "WHERE " . implode(" AND ", $filtros) : "";
        $limit  = min((int)($_GET['limit'] ?? 100), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM bitacora b $where");
        $stmt->execute($valores);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT b.id, b.usuario_id, b.accion, b.tabla_afectada, b.registro_id, b.creado_en,
                   u.nombre AS usuario_nombre, u.email AS usuario_email, u.nivel AS usuario_nivel
            FROM bitacora b
            LEFT JOIN usuarios u ON b.usuario_id = u.id
            $where
            ORDER BY b.creado_en DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($valores);

        return [
            'status'    => 'ok',
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
            'registros' => $stmt->fetchAll()
        ];
    }
}