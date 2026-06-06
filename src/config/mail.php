<?php
require_once __DIR__ . '/env.php';

class Mailer {

    private static $certPath = '/var/certificados/sistema/sistema.crt';
    private static $keyPath  = '/var/certificados/sistema/sistema.key';

    public static function send($to, $subject, $body, $isHtml = true) {
        $host = env('MAIL_HOST');
        $port = (int) env('MAIL_PORT', 2525);
        $user = env('MAIL_USERNAME');
        $pass = env('MAIL_PASSWORD');
        $from_email = env('MAIL_FROM_EMAIL');
        $from_name = env('MAIL_FROM_NAME', 'Casa Monarca');

        $signedMessage = self::buildSignedMessage($from_name, $from_email, $to, $subject, $body, $isHtml);

        if (!$signedMessage) {
            error_log('Mailer: no se pudo firmar el correo, enviando sin firma');
            return self::sendRaw($host, $port, $user, $pass, $from_email, $to, $subject,
                self::buildPlainMessage($from_name, $from_email, $to, $subject, $body, $isHtml));
        }

        return self::sendRaw($host, $port, $user, $pass, $from_email, $to, $subject, $signedMessage);
    }

    public static function sendConAdjuntos($to, $subject, $body, $adjuntos = []) {
        if (empty($adjuntos)) {
            return self::send($to, $subject, $body, true);
        }

        $host = env('MAIL_HOST');
        $port = (int) env('MAIL_PORT', 2525);
        $user = env('MAIL_USERNAME');
        $pass = env('MAIL_PASSWORD');
        $from_email = env('MAIL_FROM_EMAIL');
        $from_name = env('MAIL_FROM_NAME', 'Casa Monarca');

        $boundary = 'mixed_' . md5(uniqid('', true));

        $contenidoMime = "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n";
        $contenidoMime .= "--$boundary\r\n";
        $contenidoMime .= "Content-Type: text/html; charset=UTF-8\r\n";
        $contenidoMime .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $contenidoMime .= $body . "\r\n\r\n";

        foreach ($adjuntos as $a) {
            $contenidoMime .= "--$boundary\r\n";
            $contenidoMime .= "Content-Type: " . $a['tipo'] . "; name=\"" . $a['nombre'] . "\"\r\n";
            $contenidoMime .= "Content-Transfer-Encoding: base64\r\n";
            $contenidoMime .= "Content-Disposition: attachment; filename=\"" . $a['nombre'] . "\"\r\n\r\n";
            $contenidoMime .= chunk_split($a['contenido']) . "\r\n";
        }

        $contenidoMime .= "--$boundary--\r\n";

        $tmpIn  = tempnam(sys_get_temp_dir(), 'mail_adj_in_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'mail_adj_out_');
        file_put_contents($tmpIn, $contenidoMime);

        $signed = openssl_pkcs7_sign(
            $tmpIn,
            $tmpOut,
            'file:///var/certificados/sistema/sistema.crt',
            'file:///var/certificados/sistema/sistema.key',
            [
                'From'    => "$from_name <$from_email>",
                'To'      => $to,
                'Subject' => $subject
            ],
            PKCS7_DETACHED
        );

        if (!$signed) {
            error_log('Mailer: falló firma S/MIME del correo con adjuntos');
            unlink($tmpIn);
            unlink($tmpOut);
            return false;
        }

        $message = "Date: " . date('r') . "\r\n" . file_get_contents($tmpOut);
        unlink($tmpIn);
        unlink($tmpOut);

        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) {
            error_log("SMTP connection failed: $errstr ($errno)");
            return false;
        }

        $read = function() use ($socket) { return fgets($socket, 515); };
        $write = function($cmd) use ($socket) { fputs($socket, $cmd . "\r\n"); };

        $read();
        $write("EHLO localhost");
        while (substr($read(), 3, 1) === '-') {}
        $write("AUTH LOGIN"); $read();
        $write(base64_encode($user)); $read();
        $write(base64_encode($pass));
        $resp = $read();
        if (substr($resp, 0, 3) !== '235') {
            error_log("SMTP auth failed: $resp");
            fclose($socket);
            return false;
        }

        $write("MAIL FROM:<$from_email>"); $read();
        $write("RCPT TO:<$to>"); $read();
        $write("DATA"); $read();
        $write($message . "\r\n.");
        $read();
        $write("QUIT");
        fclose($socket);

        return true;
    }

    private static function buildSignedMessage($from_name, $from_email, $to, $subject, $body, $isHtml) {
        if (!file_exists(self::$certPath) || !file_exists(self::$keyPath)) {
            return null;
        }

        $tmpIn  = tempnam(sys_get_temp_dir(), 'mail_in_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'mail_out_');

        $contentType = $isHtml ? 'text/html' : 'text/plain';
        $rawContent = "Content-Type: $contentType; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: 7bit\r\n\r\n"
                    . $body;

        file_put_contents($tmpIn, $rawContent);

        $signed = openssl_pkcs7_sign(
            $tmpIn,
            $tmpOut,
            'file://' . self::$certPath,
            'file://' . self::$keyPath,
            [
                'From'    => "$from_name <$from_email>",
                'To'      => $to,
                'Subject' => $subject
            ],
            PKCS7_DETACHED
        );

        if (!$signed) {
            unlink($tmpIn);
            unlink($tmpOut);
            return null;
        }

        $message = file_get_contents($tmpOut);
        unlink($tmpIn);
        unlink($tmpOut);

        $message = "Date: " . date('r') . "\r\n" . $message;

        return $message;
    }

    private static function buildPlainMessage($from_name, $from_email, $to, $subject, $body, $isHtml) {
        $headers = [
            "From: $from_name <$from_email>",
            "To: <$to>",
            "Subject: $subject",
            "MIME-Version: 1.0",
            "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8",
            "Date: " . date('r')
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }


    private static function sendRaw($host, $port, $user, $pass, $from_email, $to, $subject, $message) {
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) {
            error_log("SMTP connection failed: $errstr ($errno)");
            return false;
        }

        $read = function() use ($socket) { return fgets($socket, 515); };
        $write = function($cmd) use ($socket) { fputs($socket, $cmd . "\r\n"); };

        $read();
        $write("EHLO localhost");
        while (substr($read(), 3, 1) === '-') {}
        $write("AUTH LOGIN"); $read();
        $write(base64_encode($user)); $read();
        $write(base64_encode($pass));
        $resp = $read();
        if (substr($resp, 0, 3) !== '235') {
            error_log("SMTP auth failed: $resp");
            fclose($socket);
            return false;
        }

        $write("MAIL FROM:<$from_email>"); $read();
        $write("RCPT TO:<$to>"); $read();
        $write("DATA"); $read();

        $write($message . "\r\n.");
        $read();
        $write("QUIT");
        fclose($socket);

        return true;
    }
}