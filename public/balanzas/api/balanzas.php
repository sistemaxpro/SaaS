<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/balanza_service.php';

Permission::requireAccess('app_grid_balanza');

if (!function_exists('smxResolveEmpresaIdFromSession')) {
    function smxResolveEmpresaIdFromSession(int $fallback = 169): int
    {
        $idLogin = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);
        if ($idLogin > 0) {
            try {
                $master = Database::getMasterConnection();
                $stmt = $master->prepare("SELECT id_empresa FROM sec_users WHERE id_login = :id LIMIT 1");
                $stmt->execute([':id' => $idLogin]);
                $idEmp = (int)($stmt->fetchColumn() ?: 0);
                if ($idEmp > 0) return $idEmp;
            } catch (Exception $e) {
                // ignore
            }
        }
        $idSesion = (int)($_SESSION['id_empresa'] ?? 0);
        if ($idSesion > 0) return $idSesion;
        return $fallback;
    }
}

$action = $_GET['action'] ?? '';
$idEmpresaParam = (int)($_GET['id_empresa'] ?? 0);
$idEmpresa = $idEmpresaParam > 0
    ? $idEmpresaParam
    : smxResolveEmpresaIdFromSession(169);
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    smxEnsureBalanzasTable($pdo, $db);

    switch ($action) {
        case 'list':
            $includeInactive = (int)($_GET['include_inactive'] ?? 0) === 1;
            $sql = "SELECT *
                FROM {$db}.balanzas
                WHERE id_empresa IN (:emp, 0)";
            if (!$includeInactive) {
                $sql .= " AND activo = 1";
            }
            $sql .= " ORDER BY activo DESC, nombre_modelo ASC, id_balanza DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':emp' => $idEmpresa]);
            echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save':
            Permission::requirePermission('app_grid_balanza', isset($input['id_balanza']) ? 'priv_update' : 'priv_insert');
            $idBalanza = (int)($input['id_balanza'] ?? 0);
            $row = [
                'nombre_modelo' => trim((string)($input['nombre_modelo'] ?? '')),
                'prefijo' => trim((string)($input['prefijo'] ?? '')),
                'longitud_codigo' => (int)($input['longitud_codigo'] ?? 13),
                'pos_inicio_producto' => (int)($input['pos_inicio_producto'] ?? 3),
                'largo_producto' => (int)($input['largo_producto'] ?? 5),
                'pos_inicio_valor' => (int)($input['pos_inicio_valor'] ?? 8),
                'largo_valor' => (int)($input['largo_valor'] ?? 5),
                'divisor_valor' => (float)($input['divisor_valor'] ?? 1000),
                'modo' => smxNormalizeBalanzaMode($input['modo'] ?? 'PESO'),
                'activo' => (int)(!empty($input['activo']) ? 1 : 0),
                'observacion' => trim((string)($input['observacion'] ?? '')),
            ];

            if ($row['nombre_modelo'] === '' || $row['prefijo'] === '') {
                echo json_encode(['ok' => false, 'error' => 'Nombre y prefijo son obligatorios']);
                break;
            }
            if ($row['divisor_valor'] <= 0) $row['divisor_valor'] = 1;

            if ($idBalanza > 0) {
                $sql = "UPDATE {$db}.balanzas SET
                    nombre_modelo=:nombre_modelo,
                    prefijo=:prefijo,
                    longitud_codigo=:longitud_codigo,
                    pos_inicio_producto=:pos_inicio_producto,
                    largo_producto=:largo_producto,
                    pos_inicio_valor=:pos_inicio_valor,
                    largo_valor=:largo_valor,
                    divisor_valor=:divisor_valor,
                    modo=:modo,
                    activo=:activo,
                    observacion=:observacion
                    WHERE id_balanza=:id_balanza AND id_empresa IN (:id_empresa, 0)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre_modelo' => $row['nombre_modelo'],
                    ':prefijo' => $row['prefijo'],
                    ':longitud_codigo' => $row['longitud_codigo'],
                    ':pos_inicio_producto' => $row['pos_inicio_producto'],
                    ':largo_producto' => $row['largo_producto'],
                    ':pos_inicio_valor' => $row['pos_inicio_valor'],
                    ':largo_valor' => $row['largo_valor'],
                    ':divisor_valor' => $row['divisor_valor'],
                    ':modo' => $row['modo'],
                    ':activo' => $row['activo'],
                    ':observacion' => $row['observacion'],
                    ':id_balanza' => $idBalanza,
                    ':id_empresa' => $idEmpresa
                ]);
            } else {
                $sql = "INSERT INTO {$db}.balanzas
                    (id_empresa, nombre_modelo, prefijo, longitud_codigo, pos_inicio_producto, largo_producto, pos_inicio_valor, largo_valor, divisor_valor, modo, activo, observacion)
                    VALUES
                    (:id_empresa, :nombre_modelo, :prefijo, :longitud_codigo, :pos_inicio_producto, :largo_producto, :pos_inicio_valor, :largo_valor, :divisor_valor, :modo, :activo, :observacion)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':id_empresa' => $idEmpresa,
                    ':nombre_modelo' => $row['nombre_modelo'],
                    ':prefijo' => $row['prefijo'],
                    ':longitud_codigo' => $row['longitud_codigo'],
                    ':pos_inicio_producto' => $row['pos_inicio_producto'],
                    ':largo_producto' => $row['largo_producto'],
                    ':pos_inicio_valor' => $row['pos_inicio_valor'],
                    ':largo_valor' => $row['largo_valor'],
                    ':divisor_valor' => $row['divisor_valor'],
                    ':modo' => $row['modo'],
                    ':activo' => $row['activo'],
                    ':observacion' => $row['observacion']
                ]);
                $idBalanza = (int)$pdo->lastInsertId();
            }

            echo json_encode(['ok' => true, 'id_balanza' => $idBalanza]);
            break;

        case 'delete':
            Permission::requirePermission('app_grid_balanza', 'priv_delete');
            $idBalanza = (int)($input['id_balanza'] ?? 0);
            if ($idBalanza <= 0) {
                echo json_encode(['ok' => false, 'error' => 'ID inválido']);
                break;
            }
            $stmt = $pdo->prepare("DELETE FROM {$db}.balanzas WHERE id_balanza = :id AND id_empresa IN (:emp, 0)");
            $stmt->execute([':id' => $idBalanza, ':emp' => $idEmpresa]);
            echo json_encode(['ok' => true]);
            break;

        case 'test':
            $codigo = trim((string)($_GET['codigo'] ?? $input['codigo'] ?? ''));
            if ($codigo === '') {
                echo json_encode(['ok' => false, 'error' => 'Código requerido']);
                break;
            }

            $idBalanza = (int)($_GET['id_balanza'] ?? $input['id_balanza'] ?? 0);
            if ($idBalanza > 0) {
                $stmt = $pdo->prepare("SELECT * FROM {$db}.balanzas WHERE id_balanza = :id AND id_empresa IN (:emp, 0) LIMIT 1");
                $stmt->execute([':id' => $idBalanza, ':emp' => $idEmpresa]);
                $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$cfg) {
                    echo json_encode(['ok' => false, 'error' => 'Balanza no encontrada']);
                    break;
                }
                $res = smxDecodeBalanzaFromConfig($pdo, $db, $cfg, $codigo);
            } else {
                $res = smxDecodeBalanza($pdo, $db, $idEmpresa, $codigo);
            }
            if (is_array($res)) {
                $res['_ctx_id_empresa'] = $idEmpresa;
                $res['_ctx_db'] = $db;
            }
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
