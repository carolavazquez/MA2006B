<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';

class DocumentosController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function listar() {
        $payload = verificarAcceso(4);
        
        $filtros = ["d.area = ?"];
        $valores = [$payload['area']];

        if ($payload['nivel'] == 1) {
            $filtros = [];
            $valores = [];
        }

        if (isset($_GET['estado'])) {
            $filtros[] = "d.estado = ?";
            $valores[] = $_GET['estado'];
        }

        if (isset($_GET['area']) && $payload['nivel'] == 1) {
            $filtros[] = "d.area = ?";
            $valores[] = $_GET['area'];
        }

        if (isset($_GET['carpeta_id'])) {
            if ($_GET['carpeta_id'] === 'sin-clasificar') {
                $filtros[] = "d.carpeta_id IS NULL";
            } else {
                $filtros[] = "d.carpeta_id = ?";
                $valores[] = $_GET['carpeta_id'];
            }
        }

        $where = count($filtros) > 0 ? "WHERE " . implode(" AND ", $filtros) : "";

        $stmt = $this->db->prepare("
            SELECT d.id, d.nombre, d.area, d.estado, d.version,
                   d.archivo_nombre, d.archivo_tipo, d.creado_en,
                   d.firma_digital,
                   u.nombre as autor_nombre, u.email as autor_email
            FROM documentos d
            JOIN usuarios u ON d.autor_id = u.id
            $where
            ORDER BY d.creado_en DESC
        ");
        $stmt->execute($valores);

        return ['status' => 'ok', 'documentos' => $stmt->fetchAll()];
    }

    public function ver() {
        $payload = verificarAcceso(4);
        $id = $_GET['id'] ?? '';

        if (!$id) return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("
            SELECT d.*, u.nombre as autor_nombre, u.email as autor_email
            FROM documentos d
            JOIN usuarios u ON d.autor_id = u.id
            WHERE d.id = ?
        ");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();

        if (!$doc) return $this->error(404, 'Documento no encontrado');
        if ($payload['nivel'] > 1 && $doc['area'] !== $payload['area']) {
            return $this->error(403, 'No tienes acceso a este documento');
        }

        return ['status' => 'ok', 'documento' => $doc];
    }

    public function crear() {
        $payload = verificarAcceso(3);

        $nombre = $_POST['nombre'] ?? '';
        $area = $_POST['area'] ?? $payload['area'];
        $contenido_texto = $_POST['contenido_texto'] ?? '';
        $carpeta_id = $_POST['carpeta_id'] ?? null;

        if (!$nombre) return $this->error(400, 'nombre es requerido');
        if ($payload['nivel'] > 1 && $area !== $payload['area']) {
            return $this->error(403, 'No puedes crear documentos en otra área');
        }

        $archivo_nombre = null;
        $archivo_tipo   = null;
        $contenido_cifrado = '';

        if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $archivo_nombre = $_FILES['archivo']['name'];
            $archivo_tipo   = $_FILES['archivo']['type'];
            $contenido_cifrado = base64_encode(file_get_contents($_FILES['archivo']['tmp_name']));
        }

        if (!$contenido_texto && !$archivo_nombre) {
            return $this->error(400, 'Debe incluir contenido de texto o un archivo');
        }

        $id = $this->uuid();

        $this->db->prepare("
        INSERT INTO documentos 
        (id, area, autor_id, nombre, contenido_cifrado, contenido_texto, 
        archivo_nombre, archivo_tipo, estado, version, carpeta_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo', 1, ?)
    ")->execute([
        $id, $area, $payload['sub'], $nombre,
        $contenido_cifrado, $contenido_texto,
        $archivo_nombre, $archivo_tipo, $carpeta_id
    ]);

        $this->bitacora($payload['sub'], 'CREAR_DOCUMENTO', 'documentos', $id);

        return ['status' => 'ok', 'mensaje' => 'Documento creado. Pendiente de firma digital.', 'id' => $id];
    }

    public function editar() {
        $payload = verificarAcceso(3);
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';

        if (!$id) return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("SELECT * FROM documentos WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();

        if (!$doc) return $this->error(404, 'Documento no encontrado');
        if ($payload['nivel'] > 1 && $doc['area'] !== $payload['area']) {
            return $this->error(403, 'No tienes acceso a este documento');
        }
        if ($doc['estado'] === 'cerrado' || $doc['estado'] === 'archivado') {
            return $this->error(409, 'No se puede editar un documento cerrado o archivado');
        }

        $campos = [];
        $valores = [];

        if (isset($body['nombre']))          { $campos[] = "nombre = ?";          $valores[] = $body['nombre']; }
        if (isset($body['contenido_texto'])) { $campos[] = "contenido_texto = ?"; $valores[] = $body['contenido_texto']; }
        if (isset($body['estado'])) {
            $estados = ['activo', 'cerrado', 'archivado'];
            if (!in_array($body['estado'], $estados)) return $this->error(400, 'Estado inválido');
            $campos[] = "estado = ?";
            $valores[] = $body['estado'];
        }

        if (empty($campos)) return $this->error(400, 'No se enviaron campos a actualizar');

        $campos[] = "version = version + 1";
        $valores[] = $id;

        $this->db->prepare("UPDATE documentos SET " . implode(', ', $campos) . " WHERE id = ?")->execute($valores);
        $this->bitacora($payload['sub'], 'EDITAR_DOCUMENTO', 'documentos', $id);

        return ['status' => 'ok', 'mensaje' => 'Documento actualizado correctamente.'];
    }

    public function firmar() {
        $payload = verificarAcceso(3);

        $id          = $_POST['id']          ?? '';
        $firma_b64   = $_POST['firma']       ?? '';

        if (!$id || !$firma_b64) return $this->error(400, 'id y firma son requeridos');

        $stmt = $this->db->prepare("SELECT * FROM documentos WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();

        if (!$doc) return $this->error(404, 'Documento no encontrado');
        if ($payload['nivel'] > 1 && $doc['area'] !== $payload['area']) {
            return $this->error(403, 'No tienes acceso a este documento');
        }
        if ($doc['firma_digital']) return $this->error(409, 'El documento ya está firmado');

        // Verificar que el colaborador tiene certificado activo
        $stmt = $this->db->prepare("
            SELECT clave_publica FROM certificados 
            WHERE usuario_id = ? AND estado = 'activo' AND fecha_expiracion > NOW()
        ");
        $stmt->execute([$payload['sub']]);
        $cert = $stmt->fetch();

        if (!$cert) return $this->error(403, 'Necesitas un certificado activo para firmar documentos');

        // Verificar la firma con la clave pública
        $contenido = $doc['contenido_texto'] ?: $doc['contenido_cifrado'];
        $firma = base64_decode($firma_b64);
        $clave_publica = openssl_get_publickey($cert['clave_publica']);
        $verificado = openssl_verify($contenido, $firma, $clave_publica, OPENSSL_ALGO_SHA256);

        if ($verificado !== 1) return $this->error(401, 'La firma digital no es válida');

        $this->db->prepare("UPDATE documentos SET firma_digital = ? WHERE id = ?")->execute([$firma_b64, $id]);
        $this->bitacora($payload['sub'], 'FIRMAR_DOCUMENTO', 'documentos', $id);

        return ['status' => 'ok', 'mensaje' => 'Documento firmado correctamente.'];
    }

    public function autorizar()
    {
        $payload = verificarAcceso(1);
        $body = json_decode(file_get_contents('php://input'), true);

        $documento_id = $body['documento_id'] ?? '';
        $usuario_id = $body['usuario_id'] ?? '';

        if (!$documento_id || !$usuario_id) {
            return $this->error(400, 'documento_id y usuario_id son requeridos');
        }

        // Verificar que el documento existe
        $stmt = $this->db->prepare("SELECT id FROM documentos WHERE id = ?");
        $stmt->execute([$documento_id]);
        if (!$stmt->fetch())
            return $this->error(404, 'Documento no encontrado');

        // Verificar que el usuario es nivel 4
        $stmt = $this->db->prepare("SELECT nivel FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();
        if (!$usuario)
            return $this->error(404, 'Usuario no encontrado');
        if ((int) $usuario['nivel'] !== 4)
            return $this->error(400, 'Solo se pueden autorizar documentos para usuarios nivel 4');

        // Verificar que no existe ya la autorización
        $stmt = $this->db->prepare("SELECT id FROM autorizaciones_documentos WHERE documento_id = ? AND usuario_id = ?");
        $stmt->execute([$documento_id, $usuario_id]);
        if ($stmt->fetch())
            return $this->error(409, 'El documento ya está autorizado para este usuario');

        $id = $this->uuid();
        $this->db->prepare("
        INSERT INTO autorizaciones_documentos (id, documento_id, usuario_id, autorizado_por)
        VALUES (?, ?, ?, ?)
    ")->execute([$id, $documento_id, $usuario_id, $payload['sub']]);

        $this->bitacora($payload['sub'], 'AUTORIZAR_DOCUMENTO', 'autorizaciones_documentos', $id);

        return ['status' => 'ok', 'mensaje' => 'Documento autorizado correctamente para el usuario.'];
    }

    public function listarAutorizados()
    {
        $payload = verificarAcceso(4);

        if ((int) $payload['nivel'] !== 4) {
            return $this->error(403, 'Este endpoint es solo para usuarios nivel 4');
        }

        $stmt = $this->db->prepare("
        SELECT d.id, d.nombre, d.area, d.estado, d.version,
               d.archivo_nombre, d.archivo_tipo, d.creado_en,
               d.firma_digital, d.contenido_texto,
               u.nombre as autor_nombre, u.email as autor_email,
               a.creado_en as autorizado_en
        FROM autorizaciones_documentos a
        JOIN documentos d ON a.documento_id = d.id
        JOIN usuarios u ON d.autor_id = u.id
        WHERE a.usuario_id = ?
        ORDER BY a.creado_en DESC
    ");
        $stmt->execute([$payload['sub']]);

        return ['status' => 'ok', 'documentos' => $stmt->fetchAll()];
    }

    public function mover()
    {
        $payload = verificarAcceso(2);
        $body = json_decode(file_get_contents('php://input'), true);

        $id = $body['id'] ?? '';
        $carpeta_id = $body['carpeta_id'] ?? null;

        if (!$id)
            return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("SELECT * FROM documentos WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();

        if (!$doc)
            return $this->error(404, 'Documento no encontrado');
        if ($payload['nivel'] > 1 && $doc['area'] !== $payload['area']) {
            return $this->error(403, 'No tienes acceso a este documento');
        }

        if ($carpeta_id) {
            $stmt = $this->db->prepare("SELECT id, area FROM carpetas WHERE id = ?");
            $stmt->execute([$carpeta_id]);
            $carpeta = $stmt->fetch();
            if (!$carpeta)
                return $this->error(404, 'Carpeta no encontrada');
            if ($payload['nivel'] > 1 && $carpeta['area'] !== $payload['area']) {
                return $this->error(403, 'No puedes mover documentos a otra área');
            }
        }

        $this->db->prepare("UPDATE documentos SET carpeta_id = ? WHERE id = ?")->execute([$carpeta_id, $id]);
        $this->bitacora($payload['sub'], 'MOVER_DOCUMENTO', 'documentos', $id);

        return ['status' => 'ok', 'mensaje' => 'Documento movido correctamente.'];
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