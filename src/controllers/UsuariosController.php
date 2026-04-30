<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/templates.php';

class UsuariosController {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function listar() {
        verificarAcceso(1);
        $filtros = [];
        $valores = [];

        if (isset($_GET['nivel'])) { $filtros[] = "nivel = ?"; $valores[] = $_GET['nivel']; }
        if (isset($_GET['area']))  { $filtros[] = "area = ?";  $valores[] = $_GET['area'];  }
        if (isset($_GET['activo'])) { $filtros[] = "activo = ?"; $valores[] = $_GET['activo']; }
        if (isset($_GET['estado'])) {
            if ($_GET['estado'] === 'pendiente') { $filtros[] = "activo = ?"; $valores[] = 0; }
            if ($_GET['estado'] === 'activo')    { $filtros[] = "activo = ?"; $valores[] = 1; }
        }
        $where = count($filtros) > 0 ? "WHERE " . implode(" AND ", $filtros) : "";
        $stmt = $this->db->prepare("SELECT id, nombre, email, nivel, area, activo, creado_en FROM usuarios $where ORDER BY nivel ASC, nombre ASC");
        $stmt->execute($valores);

        return ['status' => 'ok', 'total' => $stmt->rowCount(), 'usuarios' => $stmt->fetchAll()];
    }

    public function ver() {
        verificarAcceso(1);
        $id = $_GET['id'] ?? '';
        if (!$id) return $this->error(400, 'id es requerido');

        $stmt = $this->db->prepare("SELECT id, nombre, email, nivel, area, activo, creado_en FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $usuario = $stmt->fetch();
        if (!$usuario) return $this->error(404, 'Usuario no encontrado');

        $stmt = $this->db->prepare("SELECT accion, tabla_afectada, creado_en FROM bitacora WHERE registro_id = ? ORDER BY creado_en DESC LIMIT 20");
        $stmt->execute([$id]);

        return ['status' => 'ok', 'usuario' => $usuario, 'historial' => $stmt->fetchAll()];
    }

    public function crear() {
        $admin = verificarAcceso(1);
        $body = json_decode(file_get_contents('php://input'), true);

        $nombre = $body['nombre'] ?? '';
        $email  = $body['email']  ?? '';
        $nivel  = $body['nivel']  ?? '';
        $area   = $body['area']   ?? '';

        if (!$nombre || !$email || !$nivel || !$area) return $this->error(400, 'nombre, email, nivel y area son requeridos');
        if (!in_array((int)$nivel, [2, 3, 4])) return $this->error(400, 'Nivel inválido. Solo se permiten niveles 2, 3 y 4.');

        $areas_validas = ['Humanitaria', 'PsicoSocial', 'Legal', 'Comunicacion', 'Almacen', 'TI'];
        if (!in_array($area, $areas_validas)) return $this->error(400, 'Área inválida', ['areas_validas' => $areas_validas]);

        $stmt = $this->db->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) return $this->error(409, 'Ya existe un usuario con ese email');

        $id = $this->uuid();
        $password_temporal = bin2hex(random_bytes(8));
        $password_hash = password_hash($password_temporal, PASSWORD_BCRYPT);

        $this->db->prepare("INSERT INTO usuarios (id, nombre, email, password_hash, password_temporal, nivel, area, activo) VALUES (?, ?, ?, ?, ?, ?, ?, 0)")
            ->execute([$id, $nombre, $email, $password_hash, $password_temporal, $nivel, $area]);

        $this->bitacora($admin['sub'], 'CREAR_USUARIO', 'usuarios', $id);

        return [
            'status' => 'ok',
            'mensaje' => 'Colaborador creado. Acceso inactivo hasta ser activado. Las credenciales se enviarán por correo al activar.'
        ];
    }

    public function activar() {
        $admin = verificarAcceso(1);
        $body = json_decode(file_get_contents('php://input'), true);
        $usuario_id = $body['usuario_id'] ?? '';

        if (!$usuario_id) return $this->error(400, 'usuario_id es requerido');

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();

        if (!$usuario) return $this->error(404, 'Usuario no encontrado');
        if ($usuario['activo']) return $this->error(409, 'El usuario ya está activo');

        $this->db->prepare("UPDATE usuarios SET activo = 1, password_temporal = NULL WHERE id = ?")
            ->execute([$usuario_id]);

        $this->bitacora($admin['sub'], 'ACTIVAR_USUARIO', 'usuarios', $usuario_id);

        if ($usuario['password_temporal']) {
            Mailer::send(
                $usuario['email'],
                'Tu cuenta en Casa Monarca está activa',
                Templates::cuentaActivada($usuario['nombre'], $usuario['email'], $usuario['password_temporal'])
            );
        }

        return ['status' => 'ok', 'mensaje' => 'Acceso activado y credenciales enviadas al correo del colaborador.', 'usuario_afectado' => $usuario['email']];
    }

    public function editar() {
        $admin = verificarAcceso(1);
        $body = json_decode(file_get_contents('php://input'), true);
        $usuario_id = $body['usuario_id'] ?? '';

        if (!$usuario_id) return $this->error(400, 'usuario_id es requerido');

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        if (!$stmt->fetch()) return $this->error(404, 'Usuario no encontrado');

        $campos_permitidos = ['nombre', 'nivel', 'area', 'activo'];
        $cambios = []; $valores = [];

        foreach ($campos_permitidos as $campo) {
            if (isset($body[$campo])) {
                if ($campo === 'nivel' && !in_array((int)$body[$campo], [1,2,3,4])) return $this->error(400, 'Nivel inválido');
                $areas_validas = ['Humanitaria','PsicoSocial','Legal','Comunicacion','Almacen','TI'];
                if ($campo === 'area' && !in_array($body[$campo], $areas_validas)) return $this->error(400, 'Área inválida');
                $cambios[] = "$campo = ?";
                $valores[] = $body[$campo];
            }
        }

        if (empty($cambios)) return $this->error(400, 'No se enviaron campos a actualizar');

        $valores[] = $usuario_id;
        $this->db->prepare("UPDATE usuarios SET " . implode(', ', $cambios) . " WHERE id = ?")->execute($valores);
        $this->bitacora($admin['sub'], 'EDITAR_USUARIO', 'usuarios', $usuario_id);

        return ['status' => 'ok', 'mensaje' => 'Usuario actualizado correctamente.'];
    }

    public function revocar() {
        $admin = verificarAcceso(1);
        $body = json_decode(file_get_contents('php://input'), true);
        $usuario_id = $body['usuario_id'] ?? '';

        if (!$usuario_id) return $this->error(400, 'usuario_id es requerido');
        if ($usuario_id === $admin['sub']) return $this->error(403, 'No puedes revocarte a ti mismo');

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();

        if (!$usuario) return $this->error(404, 'Usuario no encontrado');
        if (!$usuario['activo']) return $this->error(409, 'El usuario ya está inactivo');

        $this->db->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?")->execute([$usuario_id]);
        $this->bitacora($admin['sub'], 'REVOCAR_USUARIO', 'usuarios', $usuario_id);

        return ['status' => 'ok', 'mensaje' => 'Acceso revocado.', 'usuario_afectado' => $usuario['email']];
    }

    public function crearEspejo() {
        $admin = verificarAcceso(1);

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE espejo_de = ? AND es_espejo = 1");
        $stmt->execute([$admin['sub']]);
        if ($stmt->fetch()) {
            return $this->error(409, 'Ya existe una cuenta espejo para este administrador');
        }

        $id = $this->uuid();
        $password_temporal = bin2hex(random_bytes(8));
        $password_hash = password_hash($password_temporal, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$admin['sub']]);
        $admin_data = $stmt->fetch();

        $this->db->prepare("
            INSERT INTO usuarios (id, nombre, email, password_hash, nivel, area, activo, es_espejo, espejo_de)
            VALUES (?, ?, ?, ?, 1, ?, 1, 1, ?)
        ")->execute([
            $id,
            '[ESPEJO] ' . $admin_data['nombre'],
            'espejo.' . $admin_data['email'],
            $password_hash,
            $admin_data['area'],
            $admin['sub']
        ]);

        $this->bitacora($admin['sub'], 'CREAR_ESPEJO', 'usuarios', $id);

        return [
            'status' => 'ok',
            'mensaje' => 'Cuenta espejo creada correctamente.',
            'credenciales' => [
                'email' => 'espejo.' . $admin_data['email'],
                'password_temporal' => $password_temporal
            ],
            'aviso' => 'La cuenta espejo tiene acceso de solo lectura. Guarda estas credenciales en un lugar seguro.'
        ];
    }

    public function recuperarAdmin() {
        $espejo = verificarAcceso(1);

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ? AND es_espejo = 1");
        $stmt->execute([$espejo['sub']]);
        $cuenta_espejo = $stmt->fetch();

        if (!$cuenta_espejo) return $this->error(403, 'Solo cuentas espejo pueden iniciar recuperación');

        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$cuenta_espejo['espejo_de']]);
        $admin = $stmt->fetch();

        if (!$admin) return $this->error(404, 'Administrador asociado no encontrado o inactivo');

        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $id = $this->uuid();
        $expira = date('Y-m-d H:i:s', time() + 1800);

        $this->db->prepare("
            INSERT INTO recuperaciones_admin (id, admin_id, espejo_id, token_hash, expira_en)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$id, $admin['id'], $cuenta_espejo['id'], $token_hash, $expira]);

        Mailer::send(
            $admin['email'],
            'Recuperación de cuenta - Casa Monarca',
            Templates::recuperacionAdmin($admin['nombre'], $token)
        );

        $this->bitacora($cuenta_espejo['id'], 'SOLICITAR_RECUPERACION_ADMIN', 'usuarios', $admin['id']);

        return [
            'status'  => 'ok',
            'mensaje' => 'Solicitud de recuperación enviada al correo del administrador. El enlace expira en 30 minutos.'
        ];
    }

    public function completarRecuperacion() {
        $body = json_decode(file_get_contents('php://input'), true);
        $token = $body['token'] ?? '';
        $nueva_password = $body['nueva_password'] ?? '';

        if (!$token || !$nueva_password) return $this->error(400, 'Token y nueva contraseña son requeridos');
        if (strlen($nueva_password) < 8) return $this->error(400, 'La contraseña debe tener al menos 8 caracteres');

        $token_hash = hash('sha256', $token);

        $stmt = $this->db->prepare("
            SELECT * FROM recuperaciones_admin
            WHERE token_hash = ? AND usado = 0 AND expira_en > NOW()
        ");
        $stmt->execute([$token_hash]);
        $recuperacion = $stmt->fetch();

        if (!$recuperacion) return $this->error(401, 'Token inválido, expirado o ya utilizado');

        $password_hash = password_hash($nueva_password, PASSWORD_BCRYPT);

        $this->db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
            ->execute([$password_hash, $recuperacion['admin_id']]);

        $this->db->prepare("UPDATE recuperaciones_admin SET usado = 1 WHERE id = ?")
            ->execute([$recuperacion['id']]);

        $stmt = $this->db->prepare("SELECT email, nombre FROM usuarios WHERE id = ?");
        $stmt->execute([$recuperacion['admin_id']]);
        $admin = $stmt->fetch();

        Mailer::send(
            $admin['email'],
            'Tu contraseña fue restablecida - Casa Monarca',
            Templates::recuperacionCompletada($admin['nombre'])
        );

        $this->bitacora($recuperacion['admin_id'], 'RECUPERACION_COMPLETADA', 'usuarios', $recuperacion['admin_id']);

        return ['status' => 'ok', 'mensaje' => 'Contraseña restablecida correctamente.'];
    }

    public function solicitarReset()
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $email = $body['email'] ?? '';

        if (!$email)
            return $this->error(400, 'Email es requerido');

        $stmt = $this->db->prepare("SELECT id, nombre, email, activo FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario && $usuario['activo']) {
            $token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $id = $this->uuid();
            $expira = date('Y-m-d H:i:s', time() + 1800);

            $this->db->prepare("
            INSERT INTO recuperaciones_password (id, usuario_id, token_hash, expira_en)
            VALUES (?, ?, ?, ?)
        ")->execute([$id, $usuario['id'], $token_hash, $expira]);

            Mailer::send(
                $usuario['email'],
                'Restablecer contraseña - Casa Monarca',
                Templates::resetPassword($usuario['nombre'], $token)
            );

            $this->bitacora($usuario['id'], 'SOLICITAR_RESET_PASSWORD', 'usuarios', $usuario['id']);
        }

        return [
            'status' => 'ok',
            'mensaje' => 'Si el correo está registrado, recibirás un enlace de recuperación en los próximos minutos.'
        ];
    }

    public function completarReset()
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $token = $body['token'] ?? '';
        $nueva_password = $body['nueva_password'] ?? '';

        if (!$token || !$nueva_password)
            return $this->error(400, 'Token y nueva contraseña son requeridos');
        if (strlen($nueva_password) < 8)
            return $this->error(400, 'La contraseña debe tener al menos 8 caracteres');

        $token_hash = hash('sha256', $token);

        $stmt = $this->db->prepare("
        SELECT * FROM recuperaciones_password
        WHERE token_hash = ? AND usado = 0 AND expira_en > NOW()
    ");
        $stmt->execute([$token_hash]);
        $recuperacion = $stmt->fetch();

        if (!$recuperacion)
            return $this->error(401, 'Token inválido, expirado o ya utilizado');

        $password_hash = password_hash($nueva_password, PASSWORD_BCRYPT);

        $this->db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
            ->execute([$password_hash, $recuperacion['usuario_id']]);

        $this->db->prepare("UPDATE recuperaciones_password SET usado = 1 WHERE id = ?")
            ->execute([$recuperacion['id']]);

        $this->bitacora($recuperacion['usuario_id'], 'RESET_PASSWORD_COMPLETADO', 'usuarios', $recuperacion['usuario_id']);

        return ['status' => 'ok', 'mensaje' => 'Contraseña restablecida correctamente.'];
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