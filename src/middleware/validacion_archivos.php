<?php

class ValidacionArchivos {

    private static $tiposPermitidos = [
        'pdf'  => ['mime' => ['application/pdf'],                                                    'magic' => ['25504446']],
        'jpg'  => ['mime' => ['image/jpeg', 'image/jpg'],                                            'magic' => ['ffd8ff']],
        'jpeg' => ['mime' => ['image/jpeg', 'image/jpg'],                                            'magic' => ['ffd8ff']],
        'png'  => ['mime' => ['image/png'],                                                          'magic' => ['89504e470d0a1a0a']],
        'docx' => ['mime' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'magic' => ['504b0304', '504b0506', '504b0708']],
        'xlsx' => ['mime' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],  'magic' => ['504b0304', '504b0506', '504b0708']],
        'pptx' => ['mime' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'], 'magic' => ['504b0304', '504b0506', '504b0708']],
        'txt'  => ['mime' => ['text/plain'],                                                         'magic' => null],
    ];

    private static $maxSize = 10485760;

    private static function esTextoValido($ruta) {
        $handle = fopen($ruta, 'rb');
        $muestra = fread($handle, 4096);
        fclose($handle);

        if ($muestra === '' || $muestra === false) return true;

        $bytes = strlen($muestra);
        $imprimibles = 0;

        for ($i = 0; $i < $bytes; $i++) {
            $byte = ord($muestra[$i]);
            if (($byte >= 32 && $byte <= 126) || $byte === 9 || $byte === 10 || $byte === 13 || $byte >= 128) {
                $imprimibles++;
            }
        }

        $porcentaje = $imprimibles / $bytes;
        return $porcentaje >= 0.95;
    }

    public static function validar($archivo) {
        if (!is_array($archivo) || !isset($archivo['tmp_name'])) {
            return ['ok' => false, 'error' => 'Archivo no recibido correctamente'];
        }

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Error al cargar el archivo (código ' . $archivo['error'] . ')'];
        }

        if ($archivo['size'] > self::$maxSize) {
            $mb = round(self::$maxSize / 1048576, 1);
            return ['ok' => false, 'error' => "El archivo excede el tamaño máximo de {$mb} MB"];
        }

        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!isset(self::$tiposPermitidos[$extension])) {
            return ['ok' => false, 'error' => "Tipo de archivo no permitido. Solo se aceptan: " . implode(', ', array_keys(self::$tiposPermitidos))];
        }

        $config = self::$tiposPermitidos[$extension];

        $mimeDetectado = mime_content_type($archivo['tmp_name']);
        if (!in_array($mimeDetectado, $config['mime'])) {
            return ['ok' => false, 'error' => "El tipo MIME del archivo ({$mimeDetectado}) no coincide con la extensión .{$extension}"];
        }

        if ($config['magic']) {
            $handle = fopen($archivo['tmp_name'], 'rb');
            $bytes = fread($handle, 16);
            fclose($handle);
            $hex = strtolower(bin2hex($bytes));

            $coincide = false;
            foreach ($config['magic'] as $magic) {
                if (str_starts_with($hex, strtolower($magic))) {
                    $coincide = true;
                    break;
                }
            }
            if (!$coincide) {
                return ['ok' => false, 'error' => 'El contenido del archivo no corresponde a su extensión declarada'];
            }
        } elseif ($extension === 'txt') {
            if (!self::esTextoValido($archivo['tmp_name'])) {
                return ['ok' => false, 'error' => 'El archivo declarado como texto plano contiene datos binarios no permitidos'];
            }
        }

        if (in_array($extension, ['docx', 'xlsx', 'pptx'])) {
            if (self::contieneMacrosOffice($archivo['tmp_name'])) {
                return ['ok' => false, 'error' => 'El archivo de Office contiene macros u objetos embebidos. Por seguridad, este tipo de archivos no se acepta. Guarda el documento sin macros y vuelve a intentarlo.'];
            }
        }

        $hash = hash_file('sha256', $archivo['tmp_name']);

        return [
            'ok'        => true,
            'extension' => $extension,
            'mime'      => $mimeDetectado,
            'tamaño'    => $archivo['size'],
            'hash'      => $hash
        ];
    }

    public static function registrarRechazo($externo_id, $archivo, $motivo) {
        require_once __DIR__ . '/../config/database.php';
        $db = getDB();

        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));

        $nombre = $archivo['name'] ?? 'desconocido';
        $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) ?: null;
        $tamano = $archivo['size'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        $db->prepare("
            INSERT INTO archivos_rechazados (id, externo_id, nombre_archivo, extension, tamano_bytes, motivo_rechazo, ip_origen, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$id, $externo_id, $nombre, $extension, $tamano, $motivo, $ip, $ua]);
    }

    private static function contieneMacrosOffice($ruta) {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            return false;
        }

        $archivos_sospechosos = [
            'word/vbaProject.bin',
            'xl/vbaProject.bin',
            'ppt/vbaProject.bin',
            'word/vbaData.xml',
            'xl/vbaData.xml',
        ];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (in_array($nombre, $archivos_sospechosos)) {
                $zip->close();
                return true;
            }
            if (str_starts_with($nombre, 'word/embeddings/') 
                || str_starts_with($nombre, 'xl/embeddings/') 
                || str_starts_with($nombre, 'ppt/embeddings/')) {
                $zip->close();
                return true;
            }
        }

        $zip->close();
        return false;
    }
}