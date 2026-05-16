<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/templates.php';


class CertificadosController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function generar() {
        $admin = verificarAcceso(1, null, 'x');
        $body = json_decode(file_get_contents('php://input'), true);
        $usuario_id = $body['usuario_id'] ?? '';

        if (!$usuario_id) return $this->error(400, 'usuario_id es requerido');

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();
        if (!$usuario) return $this->error(404, 'Usuario no encontrado o inactivo');

        $stmt = $this->db->prepare("SELECT id FROM certificados WHERE usuario_id = ? AND estado = 'activo'");
        $stmt->execute([$usuario_id]);
        if ($stmt->fetch()) return $this->error(409, 'El usuario ya tiene un certificado activo');

        $stmt = $this->db->prepare(" SELECT c.id 
        FROM certificados c 
        WHERE c.usuario_id = ? AND c.estado = 'activo' AND c.fecha_expiracion > NOW() ");
        $stmt->execute([$admin['sub']]);
        $cert_admin = $stmt->fetch();

        if (!$cert_admin) {
            return $this->error(403, 'El administrador no tiene un certificado vigente para emitir.');
        }

        $ruta_admin = "/var/certificados/{$cert_admin['id']}";
        $cert_admin_pem = @file_get_contents("$ruta_admin/certificado.crt");
        $key_admin_pem = @file_get_contents("$ruta_admin/clave_privada.key");

        if (!$cert_admin_pem || !$key_admin_pem) {
            return $this->error(500, 'No se pudo leer el certificado del administrador.');
        }

        $config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $par_claves = openssl_pkey_new($config);

        $dn = [
            'commonName' => $usuario['nombre'],
            'organizationName' => 'Casa Monarca',
            'organizationalUnitName' => $usuario['area'],
            'countryName' => 'MX'
        ];
        $csr = openssl_csr_new($dn, $par_claves);

        $certificado = openssl_csr_sign($csr, $cert_admin_pem, $key_admin_pem, 1461);

        if (!$certificado) {
            return $this->error(500, 'Error al firmar el certificado: ' . openssl_error_string());
        }

        openssl_x509_export($certificado, $crt_string);
        openssl_pkey_export($par_claves, $key_string);

        $clave_publica = openssl_pkey_get_details($par_claves)['key'];
        $hash_certificado = hash('sha256', $crt_string);
        $cert_id = $this->uuid();
        $token_descarga = bin2hex(random_bytes(16));

        $carpeta = "/var/certificados/$cert_id";
        mkdir($carpeta, 0700, true);
        file_put_contents("$carpeta/certificado.crt", $crt_string);
        file_put_contents("$carpeta/clave_privada.key", $key_string);

        $fecha_expiracion = date('Y-m-d H:i:s', strtotime('+4 years'));
        $link_expiracion  = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $this->db->prepare("INSERT INTO certificados (id, usuario_id, clave_publica, hash_certificado, fecha_expiracion, link_descarga, link_expiracion) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$cert_id, $usuario_id, $clave_publica, $hash_certificado, $fecha_expiracion, $token_descarga, $link_expiracion]);

        $this->bitacora($admin['sub'], 'GENERAR_CERTIFICADO', 'certificados', $cert_id);

        Mailer::send(
            $usuario['email'],
            'Tu certificado digital ha sido emitido',
            Templates::certificadoEmitido($usuario['nombre'], $usuario['area'], $hash_certificado, $fecha_expiracion)
        );

        return ['status' => 'ok', 'mensaje' => 'Certificado generado exitosamente.', 'cert_id' => $cert_id, 'hash' => $hash_certificado, 'fecha_expiracion' => $fecha_expiracion, 'link_descarga' => "http://localhost:8080/api/certificados/descargar?token=$token_descarga", 'link_expira_en' => $link_expiracion];
    }

    public function revocar() {
        $admin = verificarAcceso(1, null, 'x');
        $body = json_decode(file_get_contents('php://input'), true);
        $usuario_id = $body['usuario_id'] ?? '';

        if (!$usuario_id) return $this->error(400, 'usuario_id es requerido');

        $stmt = $this->db->prepare("SELECT c.*, u.nombre, u.email 
            FROM certificados c JOIN usuarios u ON c.usuario_id = u.id 
            WHERE c.usuario_id = ? AND c.estado = 'activo'
        ");
        $stmt->execute([$usuario_id]);
        $cert = $stmt->fetch();
        if (!$cert) return $this->error(404, 'No se encontró un certificado activo para este usuario');

        $this->db->prepare("UPDATE certificados SET estado = 'revocado' WHERE id = ?")->execute([$cert['id']]);

        $carpeta = "/var/certificados/{$cert['id']}";
        if (file_exists("$carpeta/certificado.crt"))  unlink("$carpeta/certificado.crt");
        if (file_exists("$carpeta/clave_privada.key")) unlink("$carpeta/clave_privada.key");
        if (is_dir($carpeta)) rmdir($carpeta);

        $this->bitacora($admin['sub'], 'REVOCAR_CERTIFICADO', 'certificados', $cert['id']);

        Mailer::send(
            $cert['email'],
            'Tu certificado digital ha sido revocado',
            Templates::certificadoRevocado($cert['nombre'], $cert['hash_certificado'])
        );

        return ['status' => 'ok', 'mensaje' => 'Certificado revocado.', 'cert_id' => $cert['id']];
    }

    public function verificar() {
    $body = json_decode(file_get_contents('php://input'), true);
    $usuario_id = $body['usuario_id'] ?? '';
    $hash       = $body['hash']       ?? '';

    if (!$usuario_id) {
        return $this->error(400, 'usuario_id es requerido');
    }

    if ($hash) {
        $stmt = $this->db->prepare("SELECT c.*, u.nombre, u.email, u.nivel, u.area 
            FROM certificados c JOIN usuarios u ON c.usuario_id = u.id 
            WHERE c.usuario_id = ? AND c.hash_certificado = ? 
            AND c.estado = 'activo' AND c.fecha_expiracion > NOW()
        ");
        $stmt->execute([$usuario_id, $hash]);
    } else {
        $stmt = $this->db->prepare("SELECT c.*, u.nombre, u.email, u.nivel, u.area 
            FROM certificados c JOIN usuarios u ON c.usuario_id = u.id 
            WHERE c.usuario_id = ? 
            AND c.estado = 'activo' AND c.fecha_expiracion > NOW()
        ");
        $stmt->execute([$usuario_id]);
    }

    $cert = $stmt->fetch();

    if (!$cert) return ['status' => 'invalido', 'mensaje' => 'Certificado no válido, revocado o expirado'];

    return [
        'status'  => 'valido',
        'mensaje' => 'Certificado válido y activo',
        'titular' => [
            'nombre' => $cert['nombre'],
            'email'  => $cert['email'],
            'nivel'  => $cert['nivel'],
            'area'   => $cert['area']
        ],
        'certificado' => [
            'id'               => $cert['id'],
            'hash'             => $cert['hash_certificado'],
            'fecha_emision'    => $cert['fecha_emision'],
            'fecha_expiracion' => $cert['fecha_expiracion'],
            'estado'           => $cert['estado']
        ]
    ];
}


    public function renovarMio()
    {
        $admin = verificarAcceso(1, null, 'w');
        $body = json_decode(file_get_contents('php://input'), true);
        $firma = $body['firma'] ?? '';
        $challenge = $body['challenge'] ?? '';

        if (!$firma || !$challenge) {
            return $this->error(400, 'firma y challenge son requeridos');
        }

        $partes = explode('|', $challenge);
        if (count($partes) !== 2 || (time() - intval($partes[1])) > 300) {
            return $this->error(401, 'Challenge expirado. Solicita uno nuevo.');
        }

        if ($admin['es_espejo'] ?? false) {
            return $this->error(403, 'La cuenta espejo no puede renovar certificados');
        }

        $stmt = $this->db->prepare("
        SELECT * FROM certificados 
        WHERE usuario_id = ? AND estado = 'activo' AND fecha_expiracion > NOW()
    ");
        $stmt->execute([$admin['sub']]);
        $cert_actual = $stmt->fetch();

        if (!$cert_actual) {
            return $this->error(403, 'No tienes un certificado vigente. Usa el flujo de recuperación con cuenta espejo.');
        }

        $clave_publica = openssl_pkey_get_public($cert_actual['clave_publica']);
        if (!$clave_publica) {
            return $this->error(500, 'No se pudo cargar la llave pública del certificado actual');
        }

        $firma_binaria = base64_decode($firma);
        $resultado = openssl_verify($challenge, $firma_binaria, $clave_publica, OPENSSL_ALGO_SHA256);

        if ($resultado !== 1) {
            $this->bitacora($admin['sub'], 'INTENTO_RENOVACION_FIRMA_INVALIDA', 'certificados', $cert_actual['id']);
            return $this->error(401, 'Firma inválida. La solicitud no fue autorizada con tu certificado vigente.');
        }

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$admin['sub']]);
        $usuario = $stmt->fetch();

        $config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $par_claves = openssl_pkey_new($config);

        $dn = [
            'commonName' => $usuario['nombre'],
            'organizationName' => 'Casa Monarca',
            'organizationalUnitName' => $usuario['area'],
            'countryName' => 'MX'
        ];
        $csr = openssl_csr_new($dn, $par_claves);
        $certificado = openssl_csr_sign($csr, null, $par_claves, 1461);

        openssl_x509_export($certificado, $crt_string);
        openssl_pkey_export($par_claves, $key_string);

        $clave_publica_nueva = openssl_pkey_get_details($par_claves)['key'];
        $hash_certificado = hash('sha256', $crt_string);
        $cert_id_nuevo = $this->uuid();
        $token_descarga = bin2hex(random_bytes(16));

        $carpeta = "/var/certificados/$cert_id_nuevo";
        mkdir($carpeta, 0700, true);
        file_put_contents("$carpeta/certificado.crt", $crt_string);
        file_put_contents("$carpeta/clave_privada.key", $key_string);

        $fecha_expiracion = date('Y-m-d H:i:s', strtotime('+4 years'));
        $link_expiracion = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE certificados SET estado = 'revocado' WHERE id = ?")
                ->execute([$cert_actual['id']]);

            $carpeta_vieja = "/var/certificados/{$cert_actual['id']}";
            if (file_exists("$carpeta_vieja/certificado.crt"))
                unlink("$carpeta_vieja/certificado.crt");
            if (file_exists("$carpeta_vieja/clave_privada.key"))
                unlink("$carpeta_vieja/clave_privada.key");
            if (is_dir($carpeta_vieja))
                rmdir($carpeta_vieja);

            $this->db->prepare("
            INSERT INTO certificados (id, usuario_id, clave_publica, hash_certificado, fecha_expiracion, link_descarga, link_expiracion)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$cert_id_nuevo, $admin['sub'], $clave_publica_nueva, $hash_certificado, $fecha_expiracion, $token_descarga, $link_expiracion]);

            $this->bitacora($admin['sub'], 'RENOVAR_CERTIFICADO_PROPIO', 'certificados', $cert_id_nuevo);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            return $this->error(500, 'Error al renovar el certificado: ' . $e->getMessage());
        }

        return [
            'status' => 'ok',
            'mensaje' => 'Certificado renovado exitosamente. El anterior fue revocado.',
            'cert_id' => $cert_id_nuevo,
            'hash' => $hash_certificado,
            'fecha_expiracion' => $fecha_expiracion,
            'link_descarga' => "http://localhost:8080/api/certificados/descargar?token=$token_descarga",
            'link_expira_en' => $link_expiracion
        ];
    }

    public function challengeRenovacion()
    {
        $admin = verificarAcceso(1, null, 'r');
        $challenge = bin2hex(random_bytes(32)) . '|' . time();
        return [
            'status' => 'ok',
            'challenge' => $challenge,
            'aviso' => 'Firma este challenge con tu llave privada y envíalo al endpoint de renovación dentro de 5 minutos.'
        ];
    }

    public function reemitirMio()
    {
        $admin = verificarAcceso(1, null, 'w');

        if ($admin['es_espejo'] ?? false) {
            return $this->error(403, 'La cuenta espejo no puede reemitir certificados');
        }

        $stmt = $this->db->prepare("
        SELECT * FROM recuperaciones_admin
        WHERE admin_id = ? AND usado = 1
          AND creado_en > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY creado_en DESC
        LIMIT 1
    ");
        $stmt->execute([$admin['sub']]);
        $recuperacion = $stmt->fetch();

        if (!$recuperacion) {
            return $this->error(403, 'Solo puedes reemitir tu certificado tras completar un proceso de recuperación reciente (últimas 24 horas).');
        }

        $stmt = $this->db->prepare("SELECT id FROM certificados WHERE recuperacion_id = ?");
        $stmt->execute([$recuperacion['id']]);
        if ($stmt->fetch()) {
            return $this->error(409, 'Ya se reemitió un certificado con esta recuperación. Si lo perdiste, inicia un nuevo proceso de recuperación.');
        }

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$admin['sub']]);
        $usuario = $stmt->fetch();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT id FROM certificados WHERE usuario_id = ? AND estado = 'activo'");
            $stmt->execute([$admin['sub']]);
            foreach ($stmt->fetchAll() as $cert_viejo) {
                $this->db->prepare("UPDATE certificados SET estado = 'revocado' WHERE id = ?")
                    ->execute([$cert_viejo['id']]);

                $carpeta_vieja = "/var/certificados/{$cert_viejo['id']}";
                if (file_exists("$carpeta_vieja/certificado.crt"))
                    unlink("$carpeta_vieja/certificado.crt");
                if (file_exists("$carpeta_vieja/clave_privada.key"))
                    unlink("$carpeta_vieja/clave_privada.key");
                if (is_dir($carpeta_vieja))
                    rmdir($carpeta_vieja);
            }

            $config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $par_claves = openssl_pkey_new($config);

            $dn = [
                'commonName' => $usuario['nombre'],
                'organizationName' => 'Casa Monarca',
                'organizationalUnitName' => $usuario['area'],
                'countryName' => 'MX'
            ];
            $csr = openssl_csr_new($dn, $par_claves);
            $certificado = openssl_csr_sign($csr, null, $par_claves, 1461);

            openssl_x509_export($certificado, $crt_string);
            openssl_pkey_export($par_claves, $key_string);

            $clave_publica = openssl_pkey_get_details($par_claves)['key'];
            $hash_certificado = hash('sha256', $crt_string);
            $cert_id_nuevo = $this->uuid();
            $token_descarga = bin2hex(random_bytes(16));

            $carpeta = "/var/certificados/$cert_id_nuevo";
            mkdir($carpeta, 0700, true);
            file_put_contents("$carpeta/certificado.crt", $crt_string);
            file_put_contents("$carpeta/clave_privada.key", $key_string);

            $fecha_expiracion = date('Y-m-d H:i:s', strtotime('+4 years'));
            $link_expiracion = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $this->db->prepare("
            INSERT INTO certificados 
            (id, usuario_id, clave_publica, hash_certificado, fecha_expiracion, link_descarga, link_expiracion, recuperacion_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
                        $cert_id_nuevo,
                        $admin['sub'],
                        $clave_publica,
                        $hash_certificado,
                        $fecha_expiracion,
                        $token_descarga,
                        $link_expiracion,
                        $recuperacion['id']
                    ]);

            $this->bitacora($admin['sub'], 'REEMITIR_CERTIFICADO_ADMIN', 'certificados', $cert_id_nuevo);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            return $this->error(500, 'Error al reemitir certificado: ' . $e->getMessage());
        }

        return [
            'status' => 'ok',
            'mensaje' => 'Certificado reemitido exitosamente. Tus certificados anteriores fueron revocados.',
            'cert_id' => $cert_id_nuevo,
            'hash' => $hash_certificado,
            'fecha_expiracion' => $fecha_expiracion,
            'link_descarga' => "http://localhost:8080/api/certificados/descargar?token=$token_descarga",
            'link_expira_en' => $link_expiracion
        ];
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

    private function error($code, $mensaje, $extra = []) {
        http_response_code($code);
        return array_merge(['error' => $mensaje], $extra);
    }
}