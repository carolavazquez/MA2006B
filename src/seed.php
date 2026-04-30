<?php
require_once 'config/database.php';

function uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

function seedAdmin() {
    $db = getDB();

    $stmt = $db->query("SELECT COUNT(*) as total FROM usuarios WHERE nivel = 1 AND es_espejo = 0");
    $result = $stmt->fetch();

    if ($result['total'] > 0) {
        die(json_encode([
            'status'  => 'skip',
            'mensaje' => 'Ya existe un administrador. El seed no se puede correr dos veces.'
        ]));
    }

    $admin_id = uuid();
    $password_temporal = bin2hex(random_bytes(8));
    $password_hash = password_hash($password_temporal, PASSWORD_BCRYPT);

    $db->prepare("
        INSERT INTO usuarios (id, nombre, email, password_hash, nivel, area, activo)
        VALUES (?, ?, ?, ?, 1, 'TI', 1)
    ")->execute([
        $admin_id,
        'Administrador Casa Monarca',
        'admin@casamonarca.org',
        $password_hash
    ]);

    $config = [
        'digest_alg'       => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA
    ];
    $par_claves = openssl_pkey_new($config);

    $dn = [
        'commonName'             => 'Administrador Casa Monarca',
        'organizationName'       => 'Casa Monarca',
        'organizationalUnitName' => 'TI',
        'countryName'            => 'MX'
    ];
    $csr = openssl_csr_new($dn, $par_claves);
    $certificado = openssl_csr_sign($csr, null, $par_claves, 1461);

    openssl_x509_export($certificado, $crt_string);
    openssl_pkey_export($par_claves, $key_string);

    $clave_publica = openssl_pkey_get_details($par_claves)['key'];
    $hash_certificado = hash('sha256', $crt_string);
    $cert_id = uuid();
    $token_descarga = bin2hex(random_bytes(16));

    $carpeta = "/var/certificados/$cert_id";
    if (!is_dir($carpeta)) mkdir($carpeta, 0700, true);
    file_put_contents("$carpeta/certificado.crt", $crt_string);
    file_put_contents("$carpeta/clave_privada.key", $key_string);

    $fecha_expiracion = date('Y-m-d H:i:s', strtotime('+4 years'));
    $link_expiracion  = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $db->prepare("
        INSERT INTO certificados (id, usuario_id, clave_publica, hash_certificado, fecha_expiracion, link_descarga, link_expiracion)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$cert_id, $admin_id, $clave_publica, $hash_certificado, $fecha_expiracion, $token_descarga, $link_expiracion]);

    $db->prepare("
        INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
        VALUES (UUID(), ?, 'BOOTSTRAP_CERTIFICADO_ADMIN', 'certificados', ?)
    ")->execute([$admin_id, $cert_id]);

    echo json_encode([
        'status'  => 'ok',
        'mensaje' => 'Administrador y certificado bootstrap creados exitosamente.',
        'credenciales' => [
            'email'             => 'admin@casamonarca.org',
            'password_temporal' => $password_temporal,
            'nivel'             => 1,
            'area'              => 'TI'
        ],
        'certificado' => [
            'cert_id'          => $cert_id,
            'hash'             => $hash_certificado,
            'fecha_expiracion' => $fecha_expiracion,
            'link_descarga'    => "http://localhost:8080/api/certificados/descargar?token=$token_descarga",
            'link_expira_en'   => $link_expiracion
        ],
        'aviso' => 'Guarda esta contraseña y descarga el certificado ahora. Ninguno se puede recuperar después.'
    ], JSON_PRETTY_PRINT);
}

seedAdmin();