<?php
require_once __DIR__ . '/env.php';

class Templates {

    private static function layout($titulo, $contenido) {
        return '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($titulo) . '</title>
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:32px 0">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05)">
                            <tr>
                                <td style="background:#c84c2a;padding:24px;text-align:center">
                                    <h1 style="color:#ffffff;margin:0;font-size:22px">Casa Monarca</h1>
                                    <p style="color:#ffffff;margin:4px 0 0 0;font-size:13px;opacity:0.9">Sistema de gestión institucional</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:32px 24px;color:#222;line-height:1.6;font-size:15px">
                                    ' . $contenido . '
                                </td>
                            </tr>
                            <tr>
                                <td style="background:#f9f9f9;padding:16px 24px;text-align:center;color:#888;font-size:12px;border-top:1px solid #eee">
                                    Este es un correo automático. Por favor no respondas a este mensaje.<br>
                                    Casa Monarca · ' . date('Y') . '
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';
    }

    public static function recuperacionAdmin($nombre, $token)
    {
        $appUrl = env('APP_URL', 'http://localhost:8080');
        $url = $appUrl . '/recuperar.html?token=' . urlencode($token);
        $contenido = '
        <h2 style="margin-top:0;color:#c84c2a">Recuperación de cuenta</h2>
        <p>Hola ' . htmlspecialchars($nombre) . ',</p>
        <p>Se solicitó una recuperación de tu cuenta de administrador desde tu cuenta espejo.</p>
        <p>Para completar el proceso, ingresa al siguiente enlace en los próximos <strong>30 minutos</strong>:</p>
        <p style="margin:24px 0">
            <a href="' . htmlspecialchars($url) . '" style="background:#c84c2a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block">Restablecer contraseña</a>
        </p>
        <p style="font-size:13px;color:#888">Si tú no solicitaste esto, ignora este correo y notifica al equipo técnico inmediatamente.</p>';
        return self::layout('Recuperación de cuenta', $contenido);
    }

    public static function recuperacionCompletada($nombre)
    {
        $contenido = '
        <h2 style="margin-top:0;color:#c84c2a">Contraseña restablecida</h2>
        <p>Hola ' . htmlspecialchars($nombre) . ',</p>
        <p>Tu contraseña de administrador fue restablecida exitosamente el <strong>' . date('Y-m-d H:i') . '</strong>.</p>
        <p>Si <strong>tú</strong> realizaste este cambio, puedes ignorar este mensaje.</p>
        <p style="background:#fff3cd;padding:12px 16px;border-left:4px solid #c84c2a;margin:16px 0">
            <strong>¿No fuiste tú?</strong> Contacta al equipo técnico inmediatamente y revoca la cuenta espejo asociada.
        </p>';
        return self::layout('Contraseña restablecida', $contenido);
    }

    public static function cuentaActivada($nombre, $email, $password_temporal) {
        $appUrl = env('APP_URL', 'http://localhost:8080');
        $contenido = '
            <h2 style="margin-top:0;color:#c84c2a">Bienvenido, ' . htmlspecialchars($nombre) . '</h2>
            <p>Tu cuenta en el sistema de Casa Monarca ha sido activada exitosamente.</p>
            <p><strong>Tus credenciales de acceso son:</strong></p>
            <table style="background:#f9f9f9;padding:16px;border-radius:6px;margin:16px 0">
                <tr><td style="padding:6px 12px"><strong>Correo:</strong></td><td style="padding:6px 12px;font-family:monospace">' . htmlspecialchars($email) . '</td></tr>
                <tr><td style="padding:6px 12px"><strong>Contraseña temporal:</strong></td><td style="padding:6px 12px;font-family:monospace">' . htmlspecialchars($password_temporal) . '</td></tr>
            </table>
            <p>Te recomendamos cambiar tu contraseña en cuanto inicies sesión.</p>
            <p style="margin-top:24px">
                <a href="' . htmlspecialchars($appUrl) . '/frontend.html" style="background:#c84c2a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block">Iniciar sesión</a>
            </p>';
        return self::layout('Cuenta activada', $contenido);
    }

    public static function certificadoEmitido($nombre, $area, $hash, $fecha_expiracion) {
        $contenido = '
            <h2 style="margin-top:0;color:#c84c2a">Certificado digital emitido</h2>
            <p>Hola ' . htmlspecialchars($nombre) . ',</p>
            <p>Se ha emitido un certificado digital a tu nombre dentro del sistema de Casa Monarca.</p>
            <table style="background:#f9f9f9;padding:16px;border-radius:6px;margin:16px 0;width:100%">
                <tr><td style="padding:6px 12px"><strong>Área:</strong></td><td style="padding:6px 12px">' . htmlspecialchars($area) . '</td></tr>
                <tr><td style="padding:6px 12px"><strong>Vigencia hasta:</strong></td><td style="padding:6px 12px">' . htmlspecialchars($fecha_expiracion) . '</td></tr>
                <tr><td style="padding:6px 12px;vertical-align:top"><strong>Hash SHA-256:</strong></td><td style="padding:6px 12px;font-family:monospace;font-size:11px;word-break:break-all">' . htmlspecialchars($hash) . '</td></tr>
            </table>
            <p><strong>Importante:</strong> contacta al administrador del sistema para coordinar la entrega segura de tu certificado y llave privada. No se enviará por correo electrónico.</p>';
        return self::layout('Certificado emitido', $contenido);
    }

    public static function certificadoRevocado($nombre, $hash, $motivo = null) {
        $bloqueMotivo = $motivo
            ? '<p><strong>Motivo:</strong> ' . htmlspecialchars($motivo) . '</p>'
            : '';
        $contenido = '
            <h2 style="margin-top:0;color:#c84c2a">Certificado revocado</h2>
            <p>Hola ' . htmlspecialchars($nombre) . ',</p>
            <p>Te informamos que tu certificado digital en el sistema de Casa Monarca ha sido revocado.</p>
            <table style="background:#f9f9f9;padding:16px;border-radius:6px;margin:16px 0;width:100%">
                <tr><td style="padding:6px 12px;vertical-align:top"><strong>Hash del certificado:</strong></td><td style="padding:6px 12px;font-family:monospace;font-size:11px;word-break:break-all">' . htmlspecialchars($hash) . '</td></tr>
                <tr><td style="padding:6px 12px"><strong>Fecha de revocación:</strong></td><td style="padding:6px 12px">' . date('Y-m-d H:i') . '</td></tr>
            </table>
            ' . $bloqueMotivo . '
            <p>A partir de este momento, tu certificado no podrá ser utilizado para firmar documentos ni acceder a recursos protegidos. Si crees que esto es un error, contacta al administrador del sistema.</p>';
        return self::layout('Certificado revocado', $contenido);
    }

    public static function certificadoPorExpirar($nombre, $area, $hash, $fecha_expiracion, $dias)
    {
        $urgencia = $dias <= 7 ? '#c00' : '#c84c2a';
        $contenido = '
        <h2 style="margin-top:0;color:' . $urgencia . '">Tu certificado expira en ' . $dias . ' días</h2>
        <p>Hola ' . htmlspecialchars($nombre) . ',</p>
        <p>Te informamos que tu certificado digital del área de <strong>' . htmlspecialchars($area) . '</strong> está próximo a expirar.</p>
        <table style="background:#f9f9f9;padding:16px;border-radius:6px;margin:16px 0;width:100%">
            <tr><td style="padding:6px 12px"><strong>Días restantes:</strong></td><td style="padding:6px 12px;color:' . $urgencia . ';font-weight:600">' . $dias . ' días</td></tr>
            <tr><td style="padding:6px 12px"><strong>Fecha de expiración:</strong></td><td style="padding:6px 12px">' . htmlspecialchars($fecha_expiracion) . '</td></tr>
            <tr><td style="padding:6px 12px;vertical-align:top"><strong>Hash:</strong></td><td style="padding:6px 12px;font-family:monospace;font-size:11px;word-break:break-all">' . htmlspecialchars($hash) . '</td></tr>
        </table>
        <p>Para evitar interrupciones en tu acceso, contacta al administrador del sistema y solicita la renovación de tu certificado antes de la fecha de expiración.</p>
        <p style="background:#fff3cd;padding:12px 16px;border-left:4px solid ' . $urgencia . ';margin:16px 0;font-size:14px">
            Una vez expirado, no podrás firmar documentos ni acceder a recursos protegidos hasta que se emita uno nuevo.
        </p>';
        return self::layout('Certificado por expirar', $contenido);
    }


    public static function resetPassword($nombre, $token)
    {
        $appUrl = env('APP_URL', 'http://localhost:8080');
        $url = $appUrl . '/reset-password.html?token=' . urlencode($token);
        $contenido = '
        <h2 style="margin-top:0;color:#c84c2a">Restablecer contraseña</h2>
        <p>Hola ' . htmlspecialchars($nombre) . ',</p>
        <p>Recibimos una solicitud para restablecer tu contraseña en el sistema de Casa Monarca.</p>
        <p>Para crear una nueva contraseña, ingresa al siguiente enlace en los próximos <strong>30 minutos</strong>:</p>
        <p style="margin:24px 0">
            <a href="' . htmlspecialchars($url) . '" style="background:#c84c2a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block">Restablecer contraseña</a>
        </p>
        <p style="font-size:13px;color:#888">Si tú no solicitaste este cambio, ignora este correo. Tu contraseña actual seguirá funcionando.</p>';
        return self::layout('Restablecer contraseña', $contenido);
    }


    public static function nuevoAnuncio($destinatario_nombre, $autor_nombre, $titulo, $contenido)
    {
        $appUrl = env('APP_URL', 'http://localhost:8080');
        $cuerpo = '
        <h2 style="margin-top:0;color:#c84c2a">' . htmlspecialchars($titulo) . '</h2>
        <p>Hola ' . htmlspecialchars($destinatario_nombre) . ',</p>
        <p><strong>' . htmlspecialchars($autor_nombre) . '</strong> publicó un anuncio:</p>
        <div style="background:#f9f9f9;padding:16px;border-left:4px solid #c84c2a;margin:16px 0;white-space:pre-wrap">' . htmlspecialchars($contenido) . '</div>
        <p style="margin-top:24px">
            <a href="' . htmlspecialchars($appUrl) . '/frontend.html" style="background:#c84c2a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block">Ver en el sistema</a>
        </p>';
        return self::layout('Nuevo anuncio', $cuerpo);
    }

    public static function nuevoMensaje($destinatario_nombre, $autor_nombre, $asunto, $contenido)
    {
        $appUrl = env('APP_URL', 'http://localhost:8080');
        $cuerpo = '
        <h2 style="margin-top:0;color:#c84c2a">' . htmlspecialchars($asunto) . '</h2>
        <p>Hola ' . htmlspecialchars($destinatario_nombre) . ',</p>
        <p><strong>' . htmlspecialchars($autor_nombre) . '</strong> te envió un mensaje:</p>
        <div style="background:#f9f9f9;padding:16px;border-left:4px solid #c84c2a;margin:16px 0;white-space:pre-wrap">' . htmlspecialchars($contenido) . '</div>
        <p style="margin-top:24px">
            <a href="' . htmlspecialchars($appUrl) . '/frontend.html" style="background:#c84c2a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block">Responder en el sistema</a>
        </p>';
        return self::layout('Nuevo mensaje', $cuerpo);
    }


    public static function nuevaComExterna($admin_nombre, $externo_nombre, $externo_email, $asunto)
    {
        $appUrl = env('APP_URL', 'http://localhost:8080');
        $cuerpo = '
        <h2 style="margin-top:0;color:#c84c2a">Comunicación externa firmada</h2>
        <p>Hola ' . htmlspecialchars($admin_nombre) . ',</p>
        <p>Se recibió una nueva comunicación de un colaborador externo con firma digital verificada:</p>
        <table style="background:#f9f9f9;padding:16px;border-radius:6px;margin:16px 0;width:100%">
            <tr><td style="padding:6px 12px"><strong>Remitente:</strong></td><td style="padding:6px 12px">' . htmlspecialchars($externo_nombre) . '</td></tr>
            <tr><td style="padding:6px 12px"><strong>Email:</strong></td><td style="padding:6px 12px">' . htmlspecialchars($externo_email) . '</td></tr>
            <tr><td style="padding:6px 12px"><strong>Asunto:</strong></td><td style="padding:6px 12px">' . htmlspecialchars($asunto) . '</td></tr>
            <tr><td style="padding:6px 12px"><strong>Firma:</strong></td><td style="padding:6px 12px;color:#060">✓ Verificada criptográficamente</td></tr>
        </table>
        <p style="margin-top:24px">
            <a href="' . htmlspecialchars($appUrl) . '/frontend.html" style="background:#c84c2a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block">Revisar en el sistema</a>
        </p>';
        return self::layout('Nueva comunicación externa', $cuerpo);
    }

    public static function externoSoloComunicacion($nombre, $externo_id)
    {
        $appUrl = env('APP_URL', 'http://localhost:8080');
        $url = $appUrl . '/externo.html?id=' . urlencode($externo_id);
        $cuerpo = '
        <h2 style="margin-top:0;color:#c84c2a">Bienvenido como colaborador externo</h2>
        <p>Hola ' . htmlspecialchars($nombre) . ',</p>
        <p>Casa Monarca te ha habilitado como colaborador externo para enviar comunicaciones firmadas al sistema. No requieres iniciar sesión en la plataforma.</p>
        <p>Cuando necesites enviar una comunicación o documento firmado a Casa Monarca, ingresa al siguiente enlace personal:</p>
        <p style="margin:24px 0">
            <a href="' . htmlspecialchars($url) . '" style="background:#c84c2a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block">Canal de comunicación</a>
        </p>
        <p style="font-size:13px;color:#888">
            <strong>Importante:</strong> próximamente recibirás otro correo con tu certificado digital y llave privada. Necesitarás tu llave privada (.key) para firmar cada comunicación que envíes. Guárdala en un lugar seguro.
        </p>
        <p style="font-size:13px;color:#888">
            Cada comunicación que envíes quedará firmada criptográficamente con tu certificado, garantizando autenticidad. Casa Monarca verifica la firma antes de procesar tu mensaje.
        </p>';
        return self::layout('Canal de comunicación', $cuerpo);
    }

    public static function alertaIntegridadAdjuntos($nombre, $discrepancias) {
        $appUrl = env('APP_URL', 'http://localhost:8080');
        $detalle = '';
        foreach ($discrepancias as $d) {
            $detalle .= '<tr>'
                . '<td style="padding:6px 12px;font-size:12px">' . htmlspecialchars($d['archivo']) . '</td>'
                . '<td style="padding:6px 12px;font-size:11px;color:#666">' . htmlspecialchars($d['comunicacion_id']) . '</td>'
                . '<td style="padding:6px 12px;font-size:12px">' . htmlspecialchars($d['recibido_en']) . '</td>'
                . '</tr>';
        }

        $cuerpo = '
            <h2 style="margin-top:0;color:#c00">⚠ Alerta de integridad de adjuntos</h2>
            <p>Hola ' . htmlspecialchars($nombre) . ',</p>
            <p>La verificación diaria de integridad detectó que <strong>' . count($discrepancias) . '</strong> adjunto(s) almacenado(s) en el sistema no coinciden con su hash original. Esto puede indicar:</p>
            <ul>
                <li>Modificación no autorizada de la base de datos</li>
                <li>Corrupción del almacenamiento</li>
                <li>Falla en el proceso de persistencia</li>
            </ul>
            <table style="background:#f9f9f9;padding:12px;border-radius:6px;margin:16px 0;width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:#eee">
                        <th style="padding:8px 12px;text-align:left;font-size:12px">Archivo</th>
                        <th style="padding:8px 12px;text-align:left;font-size:12px">ID</th>
                        <th style="padding:8px 12px;text-align:left;font-size:12px">Recibido</th>
                    </tr>
                </thead>
                <tbody>' . $detalle . '</tbody>
            </table>
            <p>Cada discrepancia quedó registrada en la bitácora con la acción <code>INTEGRIDAD_ADJUNTO_COMPROMETIDA</code>.</p>
            <p style="margin-top:24px">
                <a href="' . htmlspecialchars($appUrl) . '/frontend.html" style="background:#c84c2a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block">Revisar en el sistema</a>
            </p>';
        return self::layout('Alerta de integridad', $cuerpo);
    }
        
}



