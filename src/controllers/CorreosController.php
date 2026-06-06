<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/templates.php';

class CorreosController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function destinatarios() {
        $payload = verificarAcceso(2, null, 'r');

        $stmt = $this->db->prepare("
            SELECT id, nombre, email, area, matricula
            FROM usuarios 
            WHERE nivel = 4 AND activo = 1 AND solo_comunicacion = 1
            ORDER BY nombre ASC
        ");
        $stmt->execute();

        return ['status' => 'ok', 'destinatarios' => $stmt->fetchAll()];
    }

    public function enviar() {
    $payload = verificarAcceso(2, null, 'w');

    $destinatario_id = $_POST['destinatario_id'] ?? '';
    $asunto = trim($_POST['asunto'] ?? '');
    $contenido = trim($_POST['contenido'] ?? '');

    if (!$destinatario_id || !$asunto || !$contenido) {
        return $this->error(400, 'destinatario_id, asunto y contenido son requeridos');
    }

    $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$payload['sub']]);
    $emisor = $stmt->fetch();

    $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ? AND nivel = 4 AND activo = 1");
    $stmt->execute([$destinatario_id]);
    $destinatario = $stmt->fetch();

    if (!$destinatario) {
        return $this->error(404, 'Destinatario no encontrado o no es un externo activo');
    }

    $adjuntos_validados = [];
    if (isset($_FILES['adjuntos'])) {
        require_once __DIR__ . '/../middleware/validacion_archivos.php';

        $archivos = $this->normalizarFiles($_FILES['adjuntos']);
        foreach ($archivos as $i => $archivo) {
            if ($archivo['error'] !== UPLOAD_ERR_OK) continue;

            $validacion = ValidacionArchivos::validar($archivo);
            if (!$validacion['ok']) {
                ValidacionArchivos::registrarRechazo($emisor['id'], $archivo, $validacion['error']);
                return $this->error(400, "Adjunto #" . ($i + 1) . " (\"{$archivo['name']}\"): " . $validacion['error']);
            }

            $adjuntos_validados[] = [
                'nombre'    => $archivo['name'],
                'tipo'      => $validacion['mime'],
                'contenido' => base64_encode(file_get_contents($archivo['tmp_name'])),
                'hash'      => $validacion['hash'],
                'tamano'    => $validacion['tamaño']
            ];
        }
    }

    $cuerpo_html = Templates::correoInstitucional(
        $destinatario['nombre'], 
        $emisor['nombre'], 
        $asunto, 
        $contenido,
        count($adjuntos_validados)
    );

    $enviado = Mailer::sendConAdjuntos(
        $destinatario['email'],
        $asunto,
        $cuerpo_html,
        $adjuntos_validados
    );

    if (!$enviado) {
        return $this->error(500, 'Error al enviar el correo. Intenta de nuevo.');
    }

    $id = $this->uuid();
    $this->db->beginTransaction();
    try {
        $this->db->prepare("
            INSERT INTO correos_enviados (id, emisor_id, destinatario_id, asunto, contenido)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$id, $emisor['id'], $destinatario_id, $asunto, $contenido]);

        foreach ($adjuntos_validados as $a) {
            $adj_id = $this->uuid();
            $this->db->prepare("
                INSERT INTO correos_adjuntos (id, correo_id, nombre, tipo, contenido, hash, tamano_bytes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$adj_id, $id, $a['nombre'], $a['tipo'], $a['contenido'], $a['hash'], $a['tamano']]);
        }

        $this->db->prepare("
            INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
            VALUES (UUID(), ?, 'ENVIAR_CORREO_INSTITUCIONAL', 'correos_enviados', ?)
        ")->execute([$emisor['id'], $id]);

        $this->db->commit();
    } catch (Exception $e) {
        $this->db->rollBack();
        return $this->error(500, 'Error al guardar correo: ' . $e->getMessage());
    }

    return [
        'status' => 'ok',
        'mensaje' => 'Correo enviado y firmado con S/MIME institucional' . (count($adjuntos_validados) > 0 ? ' con ' . count($adjuntos_validados) . ' adjunto(s)' : '') . '.',
        'id' => $id,
        'adjuntos_count' => count($adjuntos_validados)
    ];
}


public function adjuntos() {
    $payload = verificarAcceso(2, null, 'r');
    $correo_id = $_GET['correo_id'] ?? '';
    if (!$correo_id) return $this->error(400, 'correo_id es requerido');

    $stmt = $this->db->prepare("SELECT emisor_id FROM correos_enviados WHERE id = ?");
    $stmt->execute([$correo_id]);
    $correo = $stmt->fetch();
    if (!$correo) return $this->error(404, 'Correo no encontrado');

    if ((int)$payload['nivel'] !== 1 && $correo['emisor_id'] !== $payload['sub']) {
        return $this->error(403, 'No tienes acceso a este correo');
    }

    $stmt = $this->db->prepare("
        SELECT id, nombre, tipo, hash, tamano_bytes
        FROM correos_adjuntos
        WHERE correo_id = ?
        ORDER BY creado_en ASC
    ");
    $stmt->execute([$correo_id]);

    return ['status' => 'ok', 'adjuntos' => $stmt->fetchAll()];
}

public function descargarAdjunto() {
    $payload = verificarAcceso(2, null, 'r');
    $id = $_GET['id'] ?? '';
    if (!$id) return $this->error(400, 'id es requerido');

    $stmt = $this->db->prepare("
        SELECT a.*, c.emisor_id
        FROM correos_adjuntos a
        JOIN correos_enviados c ON a.correo_id = c.id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $adj = $stmt->fetch();
    if (!$adj) return $this->error(404, 'Adjunto no encontrado');

    if ((int)$payload['nivel'] !== 1 && $adj['emisor_id'] !== $payload['sub']) {
        return $this->error(403, 'No tienes acceso a este adjunto');
    }

    return [
        'status' => 'ok',
        'adjunto' => [
            'nombre'    => $adj['nombre'],
            'tipo'      => $adj['tipo'],
            'contenido' => $adj['contenido']
        ]
    ];
}
private function normalizarFiles($files) {
    $resultado = [];
    if (!is_array($files['name'])) {
        return [$files];
    }
    $total = count($files['name']);
    for ($i = 0; $i < $total; $i++) {
        $resultado[] = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
    }
    return $resultado;
}

   public function listar() {
    $payload = verificarAcceso(2, null, 'r');

    if ((int)$payload['nivel'] === 1) {
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   e.nombre AS emisor_nombre, e.email AS emisor_email,
                   d.nombre AS destinatario_nombre, d.email AS destinatario_email,
                   (SELECT COUNT(*) FROM correos_adjuntos WHERE correo_id = c.id) AS tiene_adjuntos
            FROM correos_enviados c
            JOIN usuarios e ON c.emisor_id = e.id
            JOIN usuarios d ON c.destinatario_id = d.id
            ORDER BY c.enviado_en DESC
        ");
        $stmt->execute();
    } else {
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   e.nombre AS emisor_nombre, e.email AS emisor_email,
                   d.nombre AS destinatario_nombre, d.email AS destinatario_email,
                   (SELECT COUNT(*) FROM correos_adjuntos WHERE correo_id = c.id) AS tiene_adjuntos
            FROM correos_enviados c
            JOIN usuarios e ON c.emisor_id = e.id
            JOIN usuarios d ON c.destinatario_id = d.id
            WHERE c.emisor_id = ?
            ORDER BY c.enviado_en DESC
        ");
        $stmt->execute([$payload['sub']]);
    }

    return ['status' => 'ok', 'correos' => $stmt->fetchAll()];
}

    public function ver() {
        $payload = verificarAcceso(2, null, 'r');
        $id = $_GET['id'] ?? '';
        if (!$id) return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("
            SELECT c.*, 
                   e.nombre AS emisor_nombre, e.email AS emisor_email,
                   d.nombre AS destinatario_nombre, d.email AS destinatario_email
            FROM correos_enviados c
            JOIN usuarios e ON c.emisor_id = e.id
            JOIN usuarios d ON c.destinatario_id = d.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $correo = $stmt->fetch();

        if (!$correo) return $this->error(404, 'Correo no encontrado');

        if ((int)$payload['nivel'] !== 1 && $correo['emisor_id'] !== $payload['sub']) {
            return $this->error(403, 'No tienes acceso a este correo');
        }

        return ['status' => 'ok', 'correo' => $correo];
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