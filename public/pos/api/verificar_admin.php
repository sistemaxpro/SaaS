<?php

/**
 * POS API - Verificar Contraseña Admin
 * Verifica si la contraseña proporcionada pertenece a un usuario administrador
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';
$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Contraseña requerida']);
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

try {
    // Obtener conexión maestra para verificar usuario admin
    $pdo = getMasterConnection();
    $masterDb = 'serproc1';

    // Obtener DB de la empresa
    $conn = getEmpresaConnection($id_empresa);
    $dbName = $conn['dbName'];

    // Verificar contraseña contra usuarios administradores
    // Buscar en la tabla sec_users donde el password coincida y tenga nivel admin
    $stmt = $pdo->prepare("
        SELECT u.login AS usuario, u.name AS nombre
        FROM {$masterDb}.sec_users u
        WHERE u.id_empresa = :id_empresa
          AND u.pswd = :password
          AND u.priv_admin = 'Y'
          AND u.active = 'Y'
        LIMIT 1
    ");

    // Intentar con MD5 del password primero
    $stmt->execute([
        ':id_empresa' => $id_empresa,
        ':password' => md5($password)
    ]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    // Si no encuentra, intentar con password plano
    if (!$admin) {
        $stmt->execute([
            ':id_empresa' => $id_empresa,
            ':password' => $password
        ]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($admin) {
        echo json_encode([
            'success' => true,
            'message' => 'Autorizado',
            'admin' => $admin['nombre']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Contraseña incorrecta o usuario no autorizado'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
