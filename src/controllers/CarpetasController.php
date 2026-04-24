<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';

class CarpetasController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function listar() {
        $payload = verificarAcceso(4);

        if ($payload['nivel'] == 1) {
            $stmt = $this->db->prepare("
                SELECT c.*, u.nombre as creador_nombre,
                       COUNT(d.id) as total_documentos
                FROM carpetas c
                LEFT JOIN usuarios u ON c.creado_por = u.id
                LEFT JOIN documentos d ON d.carpeta_id = c.id
                GROUP BY c.id
                ORDER BY c.area ASC, c.nombre ASC
            ");
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("
                SELECT c.*, u.nombre as creador_nombre,
                       COUNT(d.id) as total_documentos
                FROM carpetas c
                LEFT JOIN usuarios u ON c.creado_por = u.id
                LEFT JOIN documentos d ON d.carpeta_id = c.id
                WHERE c.area = ?
                GROUP BY c.id
                ORDER BY c.nombre ASC
            ");
            $stmt->execute([$payload['area']]);
        }

        // Agregar carpeta virtual "Sin clasificar" si hay documentos sin carpeta
        if ($payload['nivel'] == 1) {
            $stmt2 = $this->db->prepare("
        SELECT COUNT(*) as total FROM documentos WHERE carpeta_id IS NULL
    ");
        } else {
            $stmt2 = $this->db->prepare("
        SELECT COUNT(*) as total FROM documentos WHERE carpeta_id IS NULL AND area = ?
    ");
            $stmt2->execute([$payload['area']]);
        }

        if ($payload['nivel'] == 1)
            $stmt2->execute();
        $sin_carpeta = $stmt2->fetch();

        $carpetas = $stmt->fetchAll();

        if ($sin_carpeta['total'] > 0) {
            $carpetas[] = [
                'id' => 'sin-clasificar',
                'nombre' => 'Sin clasificar',
                'area' => 'General',
                'creado_por' => null,
                'creado_en' => null,
                'creador_nombre' => null,
                'total_documentos' => $sin_carpeta['total']
            ];
        }

        return ['status' => 'ok', 'carpetas' => $carpetas];
        return ['status' => 'ok', 'carpetas' => $stmt->fetchAll()];
    }

    public function crear() {
        $payload = verificarAcceso(2);
        $body = json_decode(file_get_contents('php://input'), true);

        $nombre = $body['nombre'] ?? '';
        $area   = $body['area']   ?? $payload['area'];

        if (!$nombre) return $this->error(400, 'nombre es requerido');

        if ($payload['nivel'] > 1 && $area !== $payload['area']) {
            return $this->error(403, 'No puedes crear carpetas en otra área');
        }

        $stmt = $this->db->prepare("SELECT id FROM carpetas WHERE nombre = ? AND area = ?");
        $stmt->execute([$nombre, $area]);
        if ($stmt->fetch()) return $this->error(409, 'Ya existe una carpeta con ese nombre en esta área');

        $id = $this->uuid();
        $this->db->prepare("
            INSERT INTO carpetas (id, nombre, area, creado_por)
            VALUES (?, ?, ?, ?)
        ")->execute([$id, $nombre, $area, $payload['sub']]);

        $this->bitacora($payload['sub'], 'CREAR_CARPETA', 'carpetas', $id);

        return ['status' => 'ok', 'mensaje' => 'Carpeta creada correctamente.', 'id' => $id];
    }

    public function eliminar() {
        $payload = verificarAcceso(1);
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';

        if (!$id) return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("SELECT * FROM carpetas WHERE id = ?");
        $stmt->execute([$id]);
        $carpeta = $stmt->fetch();
        if (!$carpeta) return $this->error(404, 'Carpeta no encontrada');

        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM documentos WHERE carpeta_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        if ($result['total'] > 0) {
            return $this->error(409, 'No se puede eliminar una carpeta con documentos. Mueve o elimina los documentos primero.');
        }

        $this->db->prepare("DELETE FROM carpetas WHERE id = ?")->execute([$id]);
        $this->bitacora($payload['sub'], 'ELIMINAR_CARPETA', 'carpetas', $id);

        return ['status' => 'ok', 'mensaje' => 'Carpeta eliminada correctamente.'];
    }

    private function bitacora($usuario_id, $accion, $tabla, $registro_id) {
        $this->db->prepare("
            INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
            VALUES (UUID(), ?, ?, ?, ?)
        ")->execute([$usuario_id, $accion, $tabla, $registro_id]);
    }

    private function uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000,
            mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    }

    private function error($code, $mensaje) {
        http_response_code($code);
        return ['error' => $mensaje];
    }
}