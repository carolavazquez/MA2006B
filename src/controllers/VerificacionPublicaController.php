<?php
require_once __DIR__ . '/../config/database.php';

class VerificacionPublicaController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function verificarSmime() {
        if (!isset($_FILES['eml']) || $_FILES['eml']['error'] !== UPLOAD_ERR_OK) {
            return $this->error(400, 'Sube un archivo .eml válido');
        }

        $tmpEml = $_FILES['eml']['tmp_name'];
        $tmpOut = tempnam(sys_get_temp_dir(), 'verif_out_');

        $caPath = '/var/certificados/sistema/sistema.crt';

        if (!file_exists($caPath)) {
            return $this->error(500, 'Certificado del sistema no encontrado');
        }

        $resultado = openssl_pkcs7_verify(
            $tmpEml,
            PKCS7_NOVERIFY,
            $tmpOut,
            [$caPath]
        );

        $headers_raw = file_get_contents($tmpEml);
        $remitente = '';
        $asunto = '';
        $fecha = '';
        if (preg_match('/^From:\s*(.+)$/mi', $headers_raw, $m)) $remitente = trim($m[1]);
        if (preg_match('/^Subject:\s*(.+)$/mi', $headers_raw, $m)) $asunto = trim($m[1]);
        if (preg_match('/^Date:\s*(.+)$/mi', $headers_raw, $m)) $fecha = trim($m[1]);

        if (is_file($tmpOut)) unlink($tmpOut);

        if ($resultado === true) {
            return [
                'status' => 'valido',
                'mensaje' => 'Este correo fue firmado por el sistema de Casa Monarca y no ha sido modificado.',
                'detalles' => [
                    'remitente' => $remitente,
                    'asunto'    => $asunto,
                    'fecha'     => $fecha,
                    'autoridad' => 'Casa Monarca — Sistema institucional'
                ]
            ];
        }

        return [
            'status' => 'invalido',
            'mensaje' => 'La firma no es válida o el correo no fue firmado por Casa Monarca.',
            'detalles' => [
                'remitente' => $remitente,
                'asunto'    => $asunto,
                'fecha'     => $fecha
            ]
        ];
    }

    public function verificarContenido() {
        $body = json_decode(file_get_contents('php://input'), true);
        $tipo = $body['tipo'] ?? '';
        $id = $body['id'] ?? '';

        if (!$tipo || !$id) return $this->error(400, 'tipo e id son requeridos');
        if (!in_array($tipo, ['anuncio', 'mensaje'])) {
            return $this->error(400, 'tipo debe ser "anuncio" o "mensaje"');
        }

        if ($tipo === 'anuncio') {
            $stmt = $this->db->prepare("
                SELECT a.titulo, a.contenido, a.firma_digital, a.creado_en,
                       u.nombre AS autor_nombre, u.area AS autor_area
                FROM anuncios a
                JOIN usuarios u ON a.autor_id = u.id
                WHERE a.id = ?
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT m.contenido, m.firma_digital, m.creado_en,
                       u.nombre AS autor_nombre, u.area AS autor_area
                FROM mensajes m
                JOIN usuarios u ON m.autor_id = u.id
                WHERE m.id = ?
            ");
        }

        $stmt->execute([$id]);
        $registro = $stmt->fetch();

        if (!$registro) return $this->error(404, 'Registro no encontrado');
        if (!$registro['firma_digital']) {
            return [
                'status' => 'sin_firma',
                'mensaje' => 'Este contenido no fue firmado digitalmente.',
                'detalles' => [
                    'autor'     => $registro['autor_nombre'],
                    'area'      => $registro['autor_area'],
                    'fecha'     => $registro['creado_en']
                ]
            ];
        }

        $stmt = $this->db->prepare("
            SELECT c.clave_publica, u.nombre, u.area
            FROM certificados c
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE u.nombre = ? AND c.fecha_expiracion > ?
            ORDER BY c.fecha_emision DESC
            LIMIT 1
        ");
        $stmt->execute([$registro['autor_nombre'], $registro['creado_en']]);
        $cert = $stmt->fetch();

        if (!$cert) {
            return [
                'status' => 'invalido',
                'mensaje' => 'No se encontró un certificado vigente del autor en el momento de la firma.'
            ];
        }

        $contenido_firmable = $tipo === 'anuncio'
            ? $registro['titulo'] . '|' . $registro['contenido']
            : $registro['contenido'];

        $firma_bin = base64_decode($registro['firma_digital']);
        $clave_publica = openssl_get_publickey($cert['clave_publica']);
        $verificado = openssl_verify($contenido_firmable, $firma_bin, $clave_publica, OPENSSL_ALGO_SHA256);

        if ($verificado === 1) {
            return [
                'status' => 'valido',
                'mensaje' => 'La firma digital es válida. El contenido fue emitido por ' . $registro['autor_nombre'] . ' y no ha sido modificado.',
                'detalles' => [
                    'autor'     => $registro['autor_nombre'],
                    'area'      => $registro['autor_area'],
                    'fecha'     => $registro['creado_en'],
                    'tipo'      => ucfirst($tipo)
                ]
            ];
        }

        return [
            'status' => 'invalido',
            'mensaje' => 'La firma no coincide con el contenido. El registro pudo haber sido alterado.',
        ];
    }

    private function error($code, $mensaje) {
        http_response_code($code);
        return ['error' => $mensaje];
    }
}