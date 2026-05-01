<?php

/**
 * API REST de Usuarios
 * Endpoints: list, get, create, update, delete, activate, change-password
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Modules/Users/UsersController.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar autenticación
if (!Session::isLoggedIn()) {
    Response::error('No autorizado', 401);
    exit;
}

// Verificar permisos de admin para operaciones de escritura
$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = $_SESSION['usr_priv_admin'] === 'Y';

if (in_array($method, ['POST', 'PUT', 'DELETE']) && !$isAdmin) {
    Response::error('No tiene permisos para esta operación', 403);
    exit;
}

// Obtener acción desde GET o el path
$action = $_GET['action'] ?? '';

// Router simple
switch ($method) {
    case 'GET':
        handleGet($action);
        break;

    case 'POST':
        handlePost($action);
        break;

    case 'PUT':
        handlePut($action);
        break;

    case 'DELETE':
        handleDelete();
        break;

    default:
        Response::error('Método no permitido', 405);
}

/**
 * Manejar GET
 */
function handleGet(string $action): void
{
    switch ($action) {
        case 'list':
            $result = UsersController::list([
                'page' => (int)($_GET['page'] ?? 1),
                'per_page' => (int)($_GET['per_page'] ?? 20),
                'search' => $_GET['search'] ?? '',
                'status' => $_GET['status'] ?? '',
                'id_grupo' => (int)($_GET['id_grupo'] ?? 0),
                'id_empresa' => (int)($_GET['id_empresa'] ?? 0)
            ]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                Response::error('ID requerido', 400);
                return;
            }
            $result = UsersController::get($id);
            break;

        case 'groups':
            $result = UsersController::getGroups((int)($_GET['id_empresa'] ?? 0));
            break;

        case 'empresas':
            $result = UsersController::getEmpresas();
            break;

        default:
            Response::error('Acción no válida', 400);
            return;
    }

    if ($result['success']) {
        Response::success($result['data'] ?? [], $result['message'] ?? 'OK', $result['pagination'] ?? null);
    } else {
        Response::error($result['error'] ?? 'Error desconocido', 400);
    }
}

/**
 * Manejar POST (crear)
 */
function handlePost(string $action): void
{
    // Validar CSRF para formularios
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Security::validateCSRFToken($csrfToken)) {
        Response::error('Token CSRF inválido', 403);
        return;
    }

    switch ($action) {
        case 'create':
            $data = [
                'login' => trim($_POST['login'] ?? ''),
                'password' => $_POST['password'] ?? '',
                'name' => trim($_POST['name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'active' => $_POST['active'] ?? 'Y',
                'priv_admin' => $_POST['priv_admin'] ?? 'N',
                'id_empresa' => !empty($_POST['id_empresa']) ? (int)$_POST['id_empresa'] : null,
                'id_grupo' => !empty($_POST['id_grupo']) ? (int)$_POST['id_grupo'] : null
            ];

            $result = UsersController::create($data);
            break;

        case 'change-password':
            $id = (int)($_POST['id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';

            if ($id <= 0) {
                Response::error('ID requerido', 400);
                return;
            }

            $result = UsersController::changePassword($id, $newPassword);
            break;

        case 'activate':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                Response::error('ID requerido', 400);
                return;
            }
            $result = UsersController::activate($id);
            break;

        default:
            Response::error('Acción no válida', 400);
            return;
    }

    outputResult($result);
}

/**
 * Manejar PUT (actualizar)
 */
function handlePut(string $action): void
{
    // Leer body JSON
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // Validar CSRF
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Security::validateCSRFToken($csrfToken)) {
        Response::error('Token CSRF inválido', 403);
        return;
    }

    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        Response::error('ID requerido', 400);
        return;
    }

    $data = [];
    $allowedFields = ['login', 'password', 'name', 'email', 'active', 'priv_admin', 'id_empresa', 'id_grupo'];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $input)) {
            $data[$field] = $input[$field];
        }
    }

    $result = UsersController::update($id, $data);
    outputResult($result);
}

/**
 * Manejar DELETE
 */
function handleDelete(): void
{
    // Validar CSRF desde header
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Security::validateCSRFToken($csrfToken)) {
        Response::error('Token CSRF inválido', 403);
        return;
    }

    $id = (int)($_GET['id'] ?? 0);
    $hardDelete = ($_GET['hard'] ?? '') === '1';

    if ($id <= 0) {
        Response::error('ID requerido', 400);
        return;
    }

    $result = UsersController::delete($id, $hardDelete);
    outputResult($result);
}

/**
 * Enviar resultado
 */
function outputResult(array $result): void
{
    if ($result['success']) {
        Response::success(
            ['id' => $result['id'] ?? null],
            $result['message'] ?? 'Operación exitosa'
        );
    } else {
        $errors = $result['errors'] ?? null;
        $message = $result['error'] ?? 'Error en la operación';

        if ($errors) {
            Response::error($message, 400, ['validation_errors' => $errors]);
        } else {
            Response::error($message, 400);
        }
    }
}
