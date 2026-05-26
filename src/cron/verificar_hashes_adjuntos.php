<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/templates.php';

$db = getDB();

$stmt = $db->query("
    SELECT id, externo_id, adjunto_nombre, adjunto_contenido, adjunto_hash, creado_en
    FROM comunicaciones_externas
    WHERE adjunto_hash IS NOT NULL AND adjunto_contenido IS NOT NULL
");
$adjuntos = $stmt->fetchAll();

$discrepancias = [];

foreach ($adjuntos as $a) {
    $contenido_binario = base64_decode($a['adjunto_contenido']);
    $hash_actual = hash('sha256', $contenido_binario);

    if ($hash_actual !== $a['adjunto_hash']) {
        $discrepancias[] = [
            'comunicacion_id' => $a['id'],
            'archivo'         => $a['adjunto_nombre'],
            'hash_esperado'   => $a['adjunto_hash'],
            'hash_actual'     => $hash_actual,
            'recibido_en'    => $a['creado_en']
        ];

        $db->prepare("
            INSERT INTO bitacora (id, usuario_id, accion, tabla_afectada, registro_id)
            VALUES (UUID(), NULL, 'INTEGRIDAD_ADJUNTO_COMPROMETIDA', 'comunicaciones_externas', ?)
        ")->execute([$a['id']]);
    }
}

$revisados = count($adjuntos);
$alertas = count($discrepancias);
$log = sprintf("[%s] Verificación de hashes — revisados: %d | discrepancias: %d\n",
    date('Y-m-d H:i:s'), $revisados, $alertas);

if ($alertas > 0) {
    $detalle = '';
    foreach ($discrepancias as $d) {
        $detalle .= "  ID {$d['comunicacion_id']} ({$d['archivo']}) — recibido {$d['recibido_en']}\n";
    }
    $log .= "DISCREPANCIAS DETECTADAS:\n$detalle";

    $stmt = $db->query("SELECT email, nombre FROM usuarios WHERE nivel = 1 AND activo = 1 AND es_espejo = 0");
    foreach ($stmt->fetchAll() as $admin) {
        Mailer::send(
            $admin['email'],
            "⚠ Alerta de integridad — adjuntos comprometidos detectados",
            Templates::alertaIntegridadAdjuntos($admin['nombre'], $discrepancias)
        );
    }
}

file_put_contents(__DIR__ . '/integridad_adjuntos.log', $log, FILE_APPEND);
echo $log;