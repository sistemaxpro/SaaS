<?php
define('TALLER_SKIP_PERMISSION_CHECK', true);
require_once __DIR__ . '/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$idEmpresa = (int)(($method === 'GET' ? ($_GET['id_empresa'] ?? 0) : null) ?? 0);

try {
    if ($idEmpresa <= 0) {
        throw new Exception('id_empresa requerido');
    }
    $conn = tallerConn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    if ($method === 'GET') {
        $action = trim((string)($_GET['action'] ?? 'status'));
        $chapa = trim((string)($_GET['chapa'] ?? ''));
        $vehiculo = tallerFindVehiculoByChapa($pdo, $db, $chapa);
        if (!$vehiculo) {
            throw new Exception('Vehículo no encontrado');
        }

        if ($action === 'status') {
            $stmt = $pdo->prepare("
                SELECT o.*, COUNT(i.id_item) AS items_count
                FROM `{$db}`.`taller_ordenes` o
                LEFT JOIN `{$db}`.`taller_orden_items` i ON i.id_orden = o.id_orden
                WHERE o.id_vehiculo = :id_vehiculo
                GROUP BY o.id_orden
                ORDER BY o.id_orden DESC
                LIMIT 1
            ");
            $stmt->execute([':id_vehiculo' => (int)$vehiculo['id_vehiculo']]);
            $orden = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $eventStmt = $pdo->prepare("
                SELECT id_evento, tipo, titulo, mensaje, estado, created_at
                FROM `{$db}`.`taller_ot_eventos`
                WHERE id_vehiculo = :id_vehiculo
                ORDER BY id_evento DESC
                LIMIT 20
            ");
            $eventStmt->execute([':id_vehiculo' => (int)$vehiculo['id_vehiculo']]);
            echo json_encode([
                'success' => true,
                'data' => [
                    'vehiculo' => [
                        'id_vehiculo' => (int)$vehiculo['id_vehiculo'],
                        'chapa' => $vehiculo['chapa'],
                        'marca' => $vehiculo['marca'],
                        'modelo' => $vehiculo['modelo'],
                        'anio' => $vehiculo['anio'],
                        'color' => $vehiculo['color'],
                        'cliente' => $vehiculo['cliente'],
                        'telefono_cliente' => $vehiculo['telefono_cliente'],
                    ],
                    'ot_actual' => $orden ? [
                        'id_orden' => (int)$orden['id_orden'],
                        'nro_ot' => $orden['nro_ot'],
                        'fecha' => $orden['fecha'],
                        'hora_ingreso' => $orden['hora_ingreso'] ?? null,
                        'estado' => $orden['estado'],
                        'prioridad' => $orden['prioridad'],
                        'problema' => $orden['problema'],
                        'diagnostico' => $orden['diagnostico'],
                        'total' => (float)$orden['total'],
                        'fecha_entrega_estimada' => $orden['fecha_entrega_estimada'] ?? null,
                        'share_url' => (!empty($orden['is_public']) && !empty($orden['public_token'])) ? tallerBuildPublicOtUrl($idEmpresa, (string)$orden['public_token']) : '',
                    ] : null,
                    'eventos' => $eventStmt->fetchAll(PDO::FETCH_ASSOC),
                ],
            ]);
            exit;
        }

        if ($action === 'events') {
            $sinceId = (int)($_GET['since_id'] ?? 0);
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $stmt = $pdo->prepare("
                SELECT id_evento, id_orden, tipo, titulo, mensaje, estado, created_at
                FROM `{$db}`.`taller_ot_eventos`
                WHERE id_vehiculo = :id_vehiculo
                  AND id_evento > :since_id
                ORDER BY id_evento ASC
                LIMIT {$limit}
            ");
            $stmt->execute([
                ':id_vehiculo' => (int)$vehiculo['id_vehiculo'],
                ':since_id' => $sinceId,
            ]);
            echo json_encode([
                'success' => true,
                'data' => [
                    'vehiculo' => [
                        'id_vehiculo' => (int)$vehiculo['id_vehiculo'],
                        'chapa' => $vehiculo['chapa'],
                    ],
                    'eventos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                ],
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
        exit;
    }

    $input = tallerJsonInput();
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'register_device') {
        $chapa = trim((string)($input['chapa'] ?? ''));
        $deviceUuid = trim((string)($input['device_uuid'] ?? ''));
        if ($chapa === '' || $deviceUuid === '') {
            throw new Exception('chapa y device_uuid son requeridos');
        }
        $vehiculo = tallerFindVehiculoByChapa($pdo, $db, $chapa);
        if (!$vehiculo) {
            throw new Exception('Vehículo no encontrado');
        }
        $stmt = $pdo->prepare("
            INSERT INTO `{$db}`.`taller_app_devices`
                (chapa, device_uuid, platform, device_name, push_token, last_seen_at)
            VALUES
                (:chapa, :device_uuid, :platform, :device_name, :push_token, NOW())
            ON DUPLICATE KEY UPDATE
                platform = VALUES(platform),
                device_name = VALUES(device_name),
                push_token = VALUES(push_token),
                last_seen_at = NOW()
        ");
        $stmt->execute([
            ':chapa' => $vehiculo['chapa'],
            ':device_uuid' => $deviceUuid,
            ':platform' => trim((string)($input['platform'] ?? 'android')),
            ':device_name' => trim((string)($input['device_name'] ?? '')),
            ':push_token' => trim((string)($input['push_token'] ?? '')),
        ]);
        echo json_encode([
            'success' => true,
            'message' => 'Dispositivo registrado',
            'data' => [
                'chapa' => $vehiculo['chapa'],
                'id_vehiculo' => (int)$vehiculo['id_vehiculo'],
            ],
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Acción no válida']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
