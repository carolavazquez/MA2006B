<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';
require_once __DIR__ . '/../middleware/mensajeria.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/templates.php';

class AnunciosController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function crear() {
        $payload = verificarAcceso(2, null, 'w');

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $autor = $stmt->fetch();

        if (!puedeMandarAnuncio($autor)) {
            return $this->error(403, 'Tu nivel no puede emitir anuncios.');
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $titulo = trim($body['titulo'] ?? '');
        $contenido = trim($body['contenido'] ?? '');
        $alcance = $body['alcance'] ?? '';
        $area = $body['area'] ?? null;
        $nivel = isset($body['nivel']) ? (int)$body['nivel'] : null;
        $firma = $body['firma'] ?? null;

        if (!$titulo || !$contenido) return $this->error(400, 'titulo y contenido son requeridos');
        if (!in_array($alcance, ['todos', 'area', 'nivel'])) {
            return $this->error(400, 'Alcance inválido');
        }

        if ((int)$autor['nivel'] === 2) {
            $alcance = 'area';
            $area = $autor['area'];
        }

        if ($alcance === 'area' && !$area) return $this->error(400, 'Debes especificar el área');
        if ($alcance === 'nivel' && !$nivel) return $this->error(400, 'Debes especificar el nivel');

        $where = "activo = 1 AND id != ?";
        $params = [$autor['id']];

        if ($alcance === 'area') { $where .= " AND area = ?"; $params[] = $area; }
        if ($alcance === 'nivel') { $where .= " AND nivel = ?"; $params[] = $nivel; }

        $stmt = $this->db->prepare("SELECT id, email, nombre FROM usuarios WHERE $where");
        $stmt->execute($params);
        $destinatarios = $stmt->fetchAll();

        $id = $this->uuid();
        $this->db->prepare("
            INSERT INTO anuncios (id, autor_id, titulo, contenido, alcance, area, nivel, firma_digital)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$id, $autor['id'], $titulo, $contenido, $alcance, $area, $nivel, $firma]);

        foreach ($destinatarios as $d) {
            Mailer::send(
                $d['email'],
                "Nuevo anuncio: $titulo",
                Templates::nuevoAnuncio($d['nombre'], $autor['nombre'], $titulo, $contenido)
            );
        }

        $this->bitacora($autor['id'], 'CREAR_ANUNCIO', 'anuncios', $id);

        return [
            'status' => 'ok',
            'mensaje' => 'Anuncio publicado. Notificación enviada a ' . count($destinatarios) . ' destinatarios.',
            'id' => $id
        ];
    }

    public function listar() {
        $payload = verificarAcceso(4, null, 'r');

        $stmt = $this->db->prepare("
            SELECT a.*, u.nombre AS autor_nombre, u.nivel AS autor_nivel
            FROM anuncios a
            JOIN usuarios u ON a.autor_id = u.id
            WHERE 
                a.alcance = 'todos'
                OR (a.alcance = 'area' AND a.area = ?)
                OR (a.alcance = 'nivel' AND a.nivel = ?)
                OR a.autor_id = ?
            ORDER BY a.creado_en DESC
            LIMIT 100
        ");
        $stmt->execute([$payload['area'], $payload['nivel'], $payload['sub']]);

        return ['status' => 'ok', 'anuncios' => $stmt->fetchAll()];
    }

    private function bitacora($usuario_id, $accion, $tabla, $registro_id) {
        $this->db->prepare("INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id) VALUES (UUID(), ?, ?, ?, ?)")
            ->execute([$usuario_id, $accion, $tabla, $registro_id]);
    }

    private function uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    }

    private function error($code, $mensaje) {
        http_response_code($code);
        return ['error' => $mensaje];
    }
}