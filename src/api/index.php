<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$route = $_GET['route'] ?? '';
$parts = explode('/', trim($route, '/'));

$resource = $parts[0] ?? '';
$action   = $parts[1] ?? '';

error_log("DEBUG route: " . ($_GET['route'] ?? 'VACIO') . " | resource: $resource | action: $action");

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
            case 'crear-espejo': echo json_encode($controller->crearEspejo()); break;
            case 'recuperar-admin':       echo json_encode($controller->recuperarAdmin()); break;
            case 'completar-recuperacion': echo json_encode($controller->completarRecuperacion()); break;
            case 'solicitar-reset':  echo json_encode($controller->solicitarReset()); break;
            case 'completar-reset':  echo json_encode($controller->completarReset()); break;
            case 'actualizar-permisos': echo json_encode($controller->actualizarPermisos()); break;
            case 'obtener-permisos':    echo json_encode($controller->obtenerPermisos()); break;
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
            case 'challenge-renovacion': echo json_encode($controller->challengeRenovacion()); break;
            case 'renovar-mio':          echo json_encode($controller->renovarMio()); break;
            case 'descargar':
                require_once __DIR__ . '/../api/certificados/descargar.php';
                break;
            
            default: http_response_code(404); echo json_encode(['error' => 'Acción no encontrada']); break;
        }
        break;

    case 'auth':
        require_once __DIR__ . '/../controllers/AuthController.php';
        $controller = new AuthController();
        switch ($action) {
            case 'me':
                echo json_encode($controller->me());
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Acción no encontrada']);
                break;

            case 'verificar-certificado':
                echo json_encode($controller->verificarCertificado());
                break;

            case 'login':
                require_once __DIR__ . '/../auth/login.php';
                break;

            case 'registro':
                echo json_encode($controller->registro());
                break;
        }
        break;

    case 'documentos':
    require_once __DIR__ . '/../controllers/DocumentosController.php';
    $controller = new DocumentosController();
    switch ($action) {
        case 'listar':  echo json_encode($controller->listar());  break;
        case 'ver':     echo json_encode($controller->ver());     break;
        case 'crear':   echo json_encode($controller->crear());   break;
        case 'editar':  echo json_encode($controller->editar());  break;
        case 'firmar':  echo json_encode($controller->firmar());  break;
        case 'autorizar':         echo json_encode($controller->autorizar());         break;
        case 'listar-autorizados': echo json_encode($controller->listarAutorizados()); break;
        case 'mover': echo json_encode($controller->mover()); break;
        default: http_response_code(404); echo json_encode(['error' => 'Acción no encontrada']); break;
    }

    break;

    case 'carpetas':
    require_once __DIR__ . '/../controllers/CarpetasController.php';
    $controller = new CarpetasController();
    switch ($action) {
        case 'listar':   echo json_encode($controller->listar());   break;
        case 'crear':    echo json_encode($controller->crear());    break;
        case 'eliminar': echo json_encode($controller->eliminar()); break;
        default: http_response_code(404); echo json_encode(['error' => 'Acción no encontrada']); break;
    }
    break;

    case 'audit':
        require_once __DIR__ . '/../controllers/AuditController.php';;
        $controller = new AuditController();
        switch ($action) {
            case 'listar':
                echo json_encode($controller->listar());
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Acción no encontrada']);
        }
        break;

    case 'reemitir-mio': echo json_encode($controller->reemitirMio()); break;

    case 'anuncios':
        require_once __DIR__ . '/../controllers/AnunciosController.php';
        $controller = new AnunciosController();
        switch ($action) {
            case 'crear':
                echo json_encode($controller->crear());
                break;
            case 'listar':
                echo json_encode($controller->listar());
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Acción no encontrada']);
        }
        break;

    case 'mensajes':
        require_once __DIR__ . '/../controllers/MensajesController.php';
        $controller = new MensajesController();
        switch ($action) {
            case 'iniciar':
                echo json_encode($controller->iniciarHilo());
                break;
            case 'responder':
                echo json_encode($controller->responder());
                break;
            case 'hilos':
                echo json_encode($controller->listarHilos());
                break;
            case 'ver':
                echo json_encode($controller->verHilo());
                break;
            case 'contactos':
                echo json_encode($controller->contactos());
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Acción no encontrada']);
        }
        break;

    case 'verificacion':
        require_once __DIR__ . '/../controllers/VerificacionPublicaController.php';
        $controller = new VerificacionPublicaController();
        switch ($action) {
            case 'smime':
                echo json_encode($controller->verificarSmime());
                break;
            case 'contenido':
                echo json_encode($controller->verificarContenido());
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Acción no encontrada']);
        }
        break;

    case 'externos':
        require_once __DIR__ . '/../controllers/ComunicacionesExternasController.php';
        $controller = new ComunicacionesExternasController();
        switch ($action) {
            case 'enviar':
                echo json_encode($controller->enviar());
                break;
            case 'info':
                echo json_encode($controller->infoExterno());
                break;
            case 'listar':
                echo json_encode($controller->listar());
                break;
            case 'ver':
                echo json_encode($controller->ver());
                break;
            case 'actualizar-estado':
                echo json_encode($controller->actualizarEstado());
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Acción no encontrada']);
        }
        break;

    case 'correos':
        require_once __DIR__ . '/../controllers/CorreosController.php';
        $controller = new CorreosController();
        switch ($action) {
            case 'destinatarios': echo json_encode($controller->destinatarios()); break;
            case 'enviar':        echo json_encode($controller->enviar()); break;
            case 'listar':        echo json_encode($controller->listar()); break;
            case 'ver':           echo json_encode($controller->ver()); break;
            case 'adjuntos':            echo json_encode($controller->adjuntos()); break;
            case 'descargar-adjunto':   echo json_encode($controller->descargarAdjunto()); break;
            default: http_response_code(404); echo json_encode(['error' => 'Acción no encontrada']);
        }
        break;


    default:
        http_response_code(404);
        echo json_encode(['error' => 'Recurso no encontrado']);
        break;
}