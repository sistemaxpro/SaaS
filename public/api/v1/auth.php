<?php
require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $usuario = $_POST['usuario'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($usuario) || empty($password)) {
            Response::error('Usuario y contraseña son requeridos');
        }

        $result = Auth::login($usuario, $password);

        if ($result['success']) {
            Response::success([
                'user' => $result['user'],
                'empresa' => $result['empresa']
            ], 'Login exitoso');
        } else {
            Response::error($result['error'], 401);
        }
        break;

    case 'logout':
        Auth::logout();
        header('Location: /public/login.php');
        exit;

    case 'check':
        if (Session::isLoggedIn()) {
            Response::success([
                'logged_in' => true,
                'user' => Auth::user(),
                'empresa' => MultiTenant::getEmpresaActual()
            ]);
        } else {
            Response::error('No hay sesión activa', 401);
        }
        break;

    default:
        Response::error('Acción no válida', 400);
}
