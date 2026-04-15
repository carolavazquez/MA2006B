<?php
require_once 'config/database.php';

function seedAdmin() {
    $db = getDB();
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM usuarios WHERE nivel = 1");
    $result = $stmt->fetch();
    
    if ($result['total'] > 0) {
        die(json_encode([
            'status' => 'skip',
            'mensaje' => 'Ya existe un administrador. El seed no se puede correr dos veces.'
        ]));
    }
    
    $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    $password_temporal = bin2hex(random_bytes(8));
    $password_hash = password_hash($password_temporal, PASSWORD_BCRYPT);
    
    $stmt = $db->prepare("
        INSERT INTO usuarios (id, nombre, email, password_hash, nivel, area, activo)
        VALUES (?, ?, ?, ?, 1, 'TI', 1)
    ");
    
    $stmt->execute([
        $id,
        'Administrador Casa Monarca',
        'admin@casamonarca.org',
        $password_hash
    ]);
    
    echo json_encode([
        'status' => 'ok',
        'mensaje' => 'Administrador creado exitosamente.',
        'credenciales' => [
            'email' => 'admin@casamonarca.org',
            'password_temporal' => $password_temporal,
            'nivel' => 1,
            'area' => 'TI'
        ],
        'aviso' => 'Guarda esta contraseña ahora. No se puede recuperar después.'
    ]);
}

seedAdmin();