<?php
require_once 'config/mail.php';

$ok = Mailer::send(
    'test@example.com',
    'Prueba de envío - Casa Monarca',
    '<h1>Funciona!</h1><p>Si ves esto en Mailtrap, todo está configurado correctamente.</p>'
);

echo json_encode(['enviado' => $ok]);