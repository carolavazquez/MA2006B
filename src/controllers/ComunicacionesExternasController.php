<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/templates.php';

class ComunicacionesExternasController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function enviar() {
        $externo_id = $_POST['externo_id'] ?? '';
        $asunto = trim($_POST['asunto'] ?? '');
        $contenido = trim($_POST['contenido'] ?? '');
        $firma = $_POST['firma'] ?? '';

        if (!$externo_id || !$asunto || !$contenido || !$firma) {
            return $this->error(400, 'externo_id, asunto, contenido y firma son requeridos');
        }

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ? AND nivel = 4 AND activo = 1");
        $stmt->execute([$externo_id]);
        $externo = $stmt->fetch();
        if (!$externo) return $this->error(404, 'Externo no encontrado o no autorizado');

        $stmt = $this->db->prepare("
            SELECT clave_publica FROM certificados 
            WHERE usuario_id = ? AND estado = 'activo' AND fecha_expiracion > NOW()
        ");
        $stmt->execute([$externo_id]);
        $cert = $stmt->fetch();
        if (!$cert) return $this->error(403, 'El externo no tiene certificado activo');

        $adjunto_nombre = null;
        $adjunto_tipo = null;
        $adjunto_contenido = null;
        $firmable_adjunto = '';

        if (isset($_FILES['adjunto']) && $_FILES['adjunto']['error'] === UPLOAD_ERR_OK) {
            $adjunto_nombre = $_FILES['adjunto']['name'];
            $adjunto_tipo   = $_FILES['adjunto']['type'];
            $adjunto_contenido = base64_encode(file_get_contents($_FILES['adjunto']['tmp_name']));
            $firmable_adjunto = $adjunto_contenido;
        }

        $contenido_firmable = $asunto . '|' . $contenido . '|' . $firmable_adjunto;
        $firma_bin = base64_decode($firma);
        $clave_publica = openssl_get_publickey($cert['clave_publica']);
        $verificado = openssl_verify($contenido_firmable, $firma_bin, $clave_publica, OPENSSL_ALGO_SHA256);

        if ($verificado !== 1) {
            return $this->error(401, 'La firma digital no es válida. El mensaje no fue aceptado.');
        }

        $id = $this->uuid();
        $this->db->prepare("
            INSERT INTO comunicaciones_externas 
            (id, externo_id, asunto, contenido, firma_digital, adjunto_nombre, adjunto_tipo, adjunto_contenido, firma_verificada)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ")->execute([$id, $externo_id, $asunto, $contenido, $firma, $adjunto_nombre, $adjunto_tipo, $adjunto_contenido]);

        $stmt = $this->db->query("SELECT email, nombre FROM usuarios WHERE nivel = 1 AND activo = 1 AND es_espejo = 0");
        foreach ($stmt->fetchAll() as $admin) {
            Mailer::send(
                $admin['email'],
                "Nueva comunicación externa firmada: $asunto",
                Templates::nuevaComExterna($admin['nombre'], $externo['nombre'], $externo['email'], $asunto)
            );
        }

        $this->db->prepare("INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id) VALUES (UUID(), ?, ?, ?, ?)")
            ->execute([$externo_id, 'COMUNICACION_EXTERNA_RECIBIDA', 'comunicaciones_externas', $id]);

        return [
            'status' => 'ok',
            'mensaje' => 'Comunicación recibida y firma verificada correctamente. Casa Monarca revisará tu mensaje pronto.',
            'id' => $id
        ];
    }

    public function listar() {
        $payload = verificarAcceso(1, null, 'r');

        $stmt = $this->db->prepare("
            SELECT c.*, u.nombre AS externo_nombre, u.email AS externo_email, u.area AS externo_area,
                   r.nombre AS revisor_nombre
            FROM comunicaciones_externas c
            JOIN usuarios u ON c.externo_id = u.id
            LEFT JOIN usuarios r ON c.revisada_por = r.id
            ORDER BY c.creado_en DESC
        ");
        $stmt->execute();
        return ['status' => 'ok', 'comunicaciones' => $stmt->fetchAll()];
    }

    public function ver() {
        $payload = verificarAcceso(1, null, 'r');
        $id = $_GET['id'] ?? '';
        if (!$id) return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("
            SELECT c.*, u.nombre AS externo_nombre, u.email AS externo_email, u.area AS externo_area
            FROM comunicaciones_externas c
            JOIN usuarios u ON c.externo_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $com = $stmt->fetch();
        if (!$com) return $this->error(404, 'Comunicación no encontrada');

        return ['status' => 'ok', 'comunicacion' => $com];
    }

    public function actualizarEstado() {
        $payload = verificarAcceso(1, null, 'w');
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        $estado = $body['estado'] ?? '';

        if (!$id || !in_array($estado, ['pendiente', 'revisada', 'archivada'])) {
            return $this->error(400, 'id y estado válido son requeridos');
        }

        $this->db->prepare("
            UPDATE comunicaciones_externas 
            SET estado = ?, revisada_por = ?, revisada_en = NOW() 
            WHERE id = ?
        ")->execute([$estado, $payload['sub'], $id]);

        $this->db->prepare("INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id) VALUES (UUID(), ?, ?, ?, ?)")
            ->execute([$payload['sub'], 'ACTUALIZAR_COMUNICACION_EXTERNA', 'comunicaciones_externas', $id]);

        return ['status' => 'ok', 'mensaje' => 'Estado actualizado'];
    }

    public function infoExterno() {
        $externo_id = $_GET['id'] ?? '';
        if (!$externo_id) return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("SELECT nombre, email, area FROM usuarios WHERE id = ? AND nivel = 4 AND activo = 1");
        $stmt->execute([$externo_id]);
        $externo = $stmt->fetch();
        if (!$externo) return $this->error(404, 'Externo no encontrado');

        return ['status' => 'ok', 'externo' => $externo];
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