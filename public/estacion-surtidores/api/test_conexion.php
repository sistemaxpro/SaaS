<?php
/**
 * API: Probar conexión con controladora de surtidor automático
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');

    $pdo = Database::getSessionEmpresaConnection();

    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);

    if ($id <= 0) {
        throw new Exception('ID de surtidor inválido');
    }

    // Obtener datos del surtidor
    $stmt = $pdo->prepare("SELECT id, controladora_ip, controladora_puerto, controladora_protocolo, tipo_control
    FROM estacion_surtidores WHERE id = ?");
    $stmt->execute([$id]);
    $surtidor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$surtidor) {
        throw new Exception('Surtidor no encontrado');
    }

    if ($surtidor['tipo_control'] !== 'automatico') {
        throw new Exception('Este surtidor no es automático');
    }

    if (empty($surtidor['controladora_ip']) || empty($surtidor['controladora_puerto'])) {
        throw new Exception('Configuración de controladora incompleta (IP o puerto faltante)');
    }

    // Intentar conexión TCP
    $ip = $surtidor['controladora_ip'];
    $puerto = (int)$surtidor['controladora_puerto'];
    $timeout = 2;

    $socket = @fsockopen($ip, $puerto, $errno, $errstr, $timeout);

    if (!$socket) {
        throw new Exception("No se puede conectar a $ip:$puerto - " . ($errstr ?: 'Timeout'));
    }

    // Cerrar socket
    fclose($socket);

    echo json_encode([
        'ok' => true,
        'message' => "Conexión exitosa a $ip:$puerto"
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
