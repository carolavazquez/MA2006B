<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/rbac.php';

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
        if (isset($_GET['activo'])){ $filtros[] = "activo = ?";$valores[] = $_GET['activo'];}

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

        $this->db->prepare("INSERT INTO usuarios (id, nombre, email, password_hash, nivel, area, activo) VALUES (?, ?, ?, ?, ?, ?, 0)")
            ->execute([$id, $nombre, $email, $password_hash, $nivel, $area]);

        $this->bitacora($admin['sub'], 'CREAR_USUARIO', 'usuarios', $id);

        return ['status' => 'ok', 'mensaje' => 'Colaborador creado. Acceso inactivo hasta ser activado.', 'credenciales' => ['email' => $email, 'password_temporal' => $password_temporal]];
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

        $this->db->prepare("UPDATE usuarios SET activo = 1 WHERE id = ?")->execute([$usuario_id]);
        $this->bitacora($admin['sub'], 'ACTIVAR_USUARIO', 'usuarios', $usuario_id);

        return ['status' => 'ok', 'mensaje' => 'Acceso activado.', 'usuario_afectado' => $usuario['email']];
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