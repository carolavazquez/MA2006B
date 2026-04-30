<?php
require_once __DIR__ . '/env.php';

class Mailer {

    public static function send($to, $subject, $body, $isHtml = true) {
        $host = env('MAIL_HOST');
        $port = (int) env('MAIL_PORT', 2525);
        $user = env('MAIL_USERNAME');
        $pass = env('MAIL_PASSWORD');
        $from_email = env('MAIL_FROM_EMAIL');
        $from_name = env('MAIL_FROM_NAME', 'Casa Monarca');

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

        $headers = [];
        $headers[] = "From: $from_name <$from_email>";
        $headers[] = "To: <$to>";
        $headers[] = "Subject: $subject";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8";
        $headers[] = "Date: " . date('r');

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        $write($message); $read();
        $write("QUIT");
        fclose($socket);

        return true;
    }
}