<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';
require_once __DIR__ . '/../middleware/mensajeria.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/templates.php';

class MensajesController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function iniciarHilo() {
        $payload = verificarAcceso(4, null, 'w');

        $body = json_decode(file_get_contents('php://input'), true);
        $destinatario_id = $body['destinatario_id'] ?? '';
        $asunto = trim($body['asunto'] ?? '');
        $contenido = trim($body['contenido'] ?? '');
        $firma = $body['firma'] ?? null;

        if (!$destinatario_id || !$asunto || !$contenido) {
            return $this->error(400, 'destinatario_id, asunto y contenido son requeridos');
        }

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $emisor = $stmt->fetch();

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$destinatario_id]);
        $receptor = $stmt->fetch();

        if (!$receptor) return $this->error(404, 'Destinatario no encontrado');
        if (!puedeMandarMensajeA($emisor, $receptor)) {
            return $this->error(403, 'No tienes permitido escribirle a este usuario.');
        }

        $hilo_id = $this->uuid();
        $mensaje_id = $this->uuid();

        $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO mensajes_hilos (id, iniciado_por, asunto) VALUES (?, ?, ?)")
                ->execute([$hilo_id, $emisor['id'], $asunto]);

            $this->db->prepare("INSERT INTO mensajes_participantes (hilo_id, usuario_id) VALUES (?, ?), (?, ?)")
                ->execute([$hilo_id, $emisor['id'], $hilo_id, $receptor['id']]);

            $this->db->prepare("
                INSERT INTO mensajes (id, hilo_id, autor_id, contenido, firma_digital)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$mensaje_id, $hilo_id, $emisor['id'], $contenido, $firma]);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            return $this->error(500, 'Error al crear el hilo: ' . $e->getMessage());
        }

        Mailer::send(
            $receptor['email'],
            "Nuevo mensaje: $asunto",
            Templates::nuevoMensaje($receptor['nombre'], $emisor['nombre'], $asunto, $contenido)
        );

        $this->bitacora($emisor['id'], 'INICIAR_HILO_MENSAJES', 'mensajes_hilos', $hilo_id);

        return ['status' => 'ok', 'hilo_id' => $hilo_id, 'mensaje_id' => $mensaje_id];
    }

    public function responder() {
        $payload = verificarAcceso(4, null, 'w');

        $body = json_decode(file_get_contents('php://input'), true);
        $hilo_id = $body['hilo_id'] ?? '';
        $contenido = trim($body['contenido'] ?? '');
        $firma = $body['firma'] ?? null;

        if (!$hilo_id || !$contenido) return $this->error(400, 'hilo_id y contenido son requeridos');

        $stmt = $this->db->prepare("
            SELECT 1 FROM mensajes_participantes WHERE hilo_id = ? AND usuario_id = ?
        ");
        $stmt->execute([$hilo_id, $payload['sub']]);
        if (!$stmt->fetch()) return $this->error(403, 'No eres participante de este hilo');

        $mensaje_id = $this->uuid();
        $this->db->prepare("
            INSERT INTO mensajes (id, hilo_id, autor_id, contenido, firma_digital)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$mensaje_id, $hilo_id, $payload['sub'], $contenido, $firma]);

        $this->db->prepare("UPDATE mensajes_hilos SET actualizado_en = NOW() WHERE id = ?")
            ->execute([$hilo_id]);

        $stmt = $this->db->prepare("
            SELECT u.email, u.nombre, h.asunto, autor.nombre AS autor_nombre
            FROM mensajes_participantes p
            JOIN usuarios u ON p.usuario_id = u.id
            JOIN mensajes_hilos h ON p.hilo_id = h.id
            JOIN usuarios autor ON autor.id = ?
            WHERE p.hilo_id = ? AND p.usuario_id != ?
        ");
        $stmt->execute([$payload['sub'], $hilo_id, $payload['sub']]);

        foreach ($stmt->fetchAll() as $d) {
            Mailer::send(
                $d['email'],
                "Nueva respuesta en: " . $d['asunto'],
                Templates::nuevoMensaje($d['nombre'], $d['autor_nombre'], $d['asunto'], $contenido)
            );
        }

        $this->bitacora($payload['sub'], 'RESPONDER_MENSAJE', 'mensajes', $mensaje_id);

        return ['status' => 'ok', 'mensaje_id' => $mensaje_id];
    }

    public function listarHilos()
    {
        $payload = verificarAcceso(4, null, 'r');

        $stmt = $this->db->prepare("
        SELECT h.id, h.asunto, h.iniciado_por, h.creado_en, h.actualizado_en,
               u.nombre AS iniciador_nombre,
               (SELECT contenido FROM mensajes m WHERE m.hilo_id = h.id ORDER BY m.creado_en DESC LIMIT 1) AS ultimo_mensaje,
               (SELECT COUNT(*) FROM mensajes m WHERE m.hilo_id = h.id) AS total_mensajes
        FROM mensajes_hilos h
        JOIN mensajes_participantes p ON p.hilo_id = h.id
        JOIN usuarios u ON h.iniciado_por = u.id
        WHERE p.usuario_id = ?
        ORDER BY h.actualizado_en DESC
    ");
        $stmt->execute([$payload['sub']]);
        $hilos = $stmt->fetchAll();

        if ($hilos) {
            $hilo_ids = array_column($hilos, 'id');
            $placeholders = implode(',', array_fill(0, count($hilo_ids), '?'));
            $stmt = $this->db->prepare("
            SELECT p.hilo_id, u.id, u.nombre, u.area, u.nivel
            FROM mensajes_participantes p
            JOIN usuarios u ON p.usuario_id = u.id
            WHERE p.hilo_id IN ($placeholders)
        ");
            $stmt->execute($hilo_ids);
            $participantes_raw = $stmt->fetchAll();

            $por_hilo = [];
            foreach ($participantes_raw as $p) {
                $por_hilo[$p['hilo_id']][] = [
                    'id' => $p['id'],
                    'nombre' => $p['nombre'],
                    'area' => $p['area'],
                    'nivel' => $p['nivel']
                ];
            }

            foreach ($hilos as &$h) {
                $h['participantes'] = $por_hilo[$h['id']] ?? [];
            }
        }

        return ['status' => 'ok', 'hilos' => $hilos];
    }

    public function verHilo() {
        $payload = verificarAcceso(4, null, 'r');

        $hilo_id = $_GET['id'] ?? '';
        if (!$hilo_id) return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("SELECT 1 FROM mensajes_participantes WHERE hilo_id = ? AND usuario_id = ?");
        $stmt->execute([$hilo_id, $payload['sub']]);
        if (!$stmt->fetch()) return $this->error(403, 'No eres participante de este hilo');

        $stmt = $this->db->prepare("SELECT id, asunto, creado_en FROM mensajes_hilos WHERE id = ?");
        $stmt->execute([$hilo_id]);
        $hilo = $stmt->fetch();

        $stmt = $this->db->prepare("
            SELECT m.id, m.contenido, m.firma_digital, m.creado_en,
                   u.id AS autor_id, u.nombre AS autor_nombre, u.email AS autor_email, u.nivel AS autor_nivel
            FROM mensajes m
            JOIN usuarios u ON m.autor_id = u.id
            WHERE m.hilo_id = ?
            ORDER BY m.creado_en ASC
        ");
        $stmt->execute([$hilo_id]);
        $mensajes = $stmt->fetchAll();

        $stmt = $this->db->prepare("
            SELECT u.id, u.nombre, u.email, u.nivel, u.area
            FROM mensajes_participantes p
            JOIN usuarios u ON p.usuario_id = u.id
            WHERE p.hilo_id = ?
        ");
        $stmt->execute([$hilo_id]);
        $participantes = $stmt->fetchAll();

        $this->db->prepare("UPDATE mensajes_participantes SET leido_hasta = NOW() WHERE hilo_id = ? AND usuario_id = ?")
            ->execute([$hilo_id, $payload['sub']]);

        return ['status' => 'ok', 'hilo' => $hilo, 'mensajes' => $mensajes, 'participantes' => $participantes];
    }

    public function contactos() {
        $payload = verificarAcceso(4, null, 'r');

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $yo = $stmt->fetch();

        $stmt = $this->db->prepare("SELECT id, nombre, email, nivel, area FROM usuarios WHERE activo = 1 AND id != ?");
        $stmt->execute([$payload['sub']]);

        $todos = $stmt->fetchAll();
        $contactables = array_values(array_filter($todos, fn($u) => puedeMandarMensajeA($yo, $u)));

        return ['status' => 'ok', 'contactos' => $contactables];
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