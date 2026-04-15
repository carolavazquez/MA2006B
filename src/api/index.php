<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$route = $_GET['route'] ?? '';
$parts = explode('/', trim($route, '/'));

$resource = $parts[0] ?? '';
$action   = $parts[1] ?? '';

switch ($resource) {
    case 'usuarios':
        require_once __DIR__ . '/../controllers/UsuariosController.php';
        $controller = new UsuariosController();
        switch ($action) {
            case 'listar':   echo json_encode($controller->listar());  break;
            case 'ver':      echo json_encode($controller->ver());     break;
            case 'crear':    echo json_encode($controller->crear());   break;
            case 'activar':  echo json_encode($controller->activar()); break;
            case 'editar':   echo json_encode($controller->editar());  break;
            case 'revocar':  echo json_encode($controller->revocar()); break;
            default: http_response_code(404); echo json_encode(['error' => 'Acción no encontrada']); break;
        }
        break;

    case 'certificados':
        require_once __DIR__ . '/../controllers/CertificadosController.php';
        $controller = new CertificadosController();
        switch ($action) {
            case 'generar':   echo json_encode($controller->generar());   break;
            case 'revocar':   echo json_encode($controller->revocar());   break;
            case 'verificar': echo json_encode($controller->verificar()); break;
            case 'descargar':
                require_once __DIR__ . '/../api/certificados/descargar.php';
                break;
            default: http_response_code(404); echo json_encode(['error' => 'Acción no encontrada']); break;
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Recurso no encontrado']);
        break;
}