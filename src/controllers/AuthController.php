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

    public function verificarCertificado()
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->error(405, 'Método no permitido');
        }

        $usuario_id = $_POST['usuario_id'] ?? '';

        if (!$usuario_id) {
            return $this->error(400, 'usuario_id es requerido');
        }

        if (!isset($_FILES['certificado']) || $_FILES['certificado']['error'] !== UPLOAD_ERR_OK) {
            return $this->error(400, 'Archivo de certificado requerido');
        }

        $crt_contenido = file_get_contents($_FILES['certificado']['tmp_name']);
        $cert_data = openssl_x509_parse($crt_contenido);

        if (!$cert_data) {
            return $this->error(400, 'El archivo no es un certificado válido');
        }

        $clave_publica_subida = openssl_pkey_get_details(
            openssl_get_publickey($crt_contenido)
        )['key'];

        $stmt = $this->db->prepare("
        SELECT c.*, u.nombre, u.email, u.nivel, u.area
        FROM certificados c JOIN usuarios u ON c.usuario_id = u.id
        WHERE c.usuario_id = ?
        AND c.estado = 'activo'
        AND c.fecha_expiracion > NOW()
    ");
        $stmt->execute([$usuario_id]);
        $cert = $stmt->fetch();

        if (!$cert) {
            return $this->error(401, 'No existe un certificado activo para este usuario');
        }

        if (trim($clave_publica_subida) !== trim($cert['clave_publica'])) {
            return $this->error(401, 'El certificado no corresponde a este usuario');
        }

        return [
            'status' => 'ok',
            'mensaje' => 'Certificado verificado correctamente',
            'usuario' => [
                'nombre' => $cert['nombre'],
                'email' => $cert['email'],
                'nivel' => $cert['nivel'],
                'area' => $cert['area']
            ]
        ];
    }

    private function error($code, $mensaje) {
    http_response_code($code);
    return ['error' => $mensaje];
}
}

