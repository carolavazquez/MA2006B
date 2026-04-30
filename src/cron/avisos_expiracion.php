<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/templates.php';

function uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

function procesarAvisos($tipo, $dias) {
    $db = getDB();

    $stmt = $db->prepare("SELECT c.id, c.hash_certificado, c.fecha_expiracion, u.nombre, u.email, u.area
        FROM certificados c
        JOIN usuarios u ON c.usuario_id = u.id
        WHERE c.estado = 'activo'
          AND c.fecha_expiracion BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)
          AND NOT EXISTS (
              SELECT 1 FROM avisos_certificado a
              WHERE a.certificado_id = c.id AND a.tipo = ?
          )
    ");
    $stmt->execute([$dias, $tipo]);
    $certs = $stmt->fetchAll();

    $enviados = 0;
    foreach ($certs as $cert) {
        $diasRestantes = (int) ((strtotime($cert['fecha_expiracion']) - time()) / 86400);

        if ($tipo === '7_dias' && $diasRestantes > 7) continue;
        if ($tipo === '30_dias' && $diasRestantes <= 7) continue;

        $ok = Mailer::send(
            $cert['email'],
            "Tu certificado digital expira en $diasRestantes días",
            Templates::certificadoPorExpirar(
                $cert['nombre'],
                $cert['area'],
                $cert['hash_certificado'],
                $cert['fecha_expiracion'],
                $diasRestantes
            )
        );

        if ($ok) {
            $db->prepare("INSERT INTO avisos_certificado (id, certificado_id, tipo)
                VALUES (?, ?, ?)
            ")->execute([uuid(), $cert['id'], $tipo]);

            $db->prepare("INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
                VALUES (UUID(), NULL, ?, 'certificados', ?)
            ")->execute(['AVISO_EXPIRACION_' . strtoupper($tipo), $cert['id']]);

            $enviados++;
        }
    }
    return $enviados;
}

$enviados30 = procesarAvisos('30_dias', 30);
$enviados7  = procesarAvisos('7_dias', 7);

$total = $enviados30 + $enviados7;
$log = sprintf("[%s] Avisos enviados — 30d: %d | 7d: %d | total: %d\n",
    date('Y-m-d H:i:s'), $enviados30, $enviados7, $total);

file_put_contents(__DIR__ . '/avisos.log', $log, FILE_APPEND);
echo $log;