<?php
/**
 * Configuracion de proveedor de tarjeta por empresa/caja.
 * Guarda datos de afiliacion y endpoints de bridge.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/bootstrap.php';

    if (!Session::isLoggedIn()) {
        throw new Exception('Sesion no valida');
    }

    $master = Database::getMasterConnection();
    $idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? Session::getIdEmpresa());
    $idUsuario = (int)Session::getIdLogin();
    if ($idEmpresa <= 0) {
        throw new Exception('Empresa invalida');
    }

    ensureConfigTable($master);

    $action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'bootstrap')));

    if ($action === 'bootstrap') {
        $configs = getConfigs($master, $idEmpresa);
        $cajas = getCajasEmpresa($idEmpresa);
        echo json_encode([
            'success' => true,
            'data' => [
                'configs' => $configs,
                'cajas' => $cajas
            ]
        ]);
        exit;
    }

    if ($action === 'save') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $id = (int)($input['id'] ?? 0);
        $idCaja = isset($input['id_caja']) && $input['id_caja'] !== '' ? (int)$input['id_caja'] : null;
        $provider = substr(trim((string)($input['provider'] ?? 'bancard')), 0, 50);
        $processor = substr(trim((string)($input['processor'] ?? 'bancard')), 0, 50);
        $terminalType = substr(trim((string)($input['terminal_type'] ?? 'pinpad')), 0, 50);
        $bridgeSale = trim((string)($input['bridge_sale_url'] ?? ''));
        $bridgeStatus = trim((string)($input['bridge_status_url'] ?? ''));
        $bridgeReversal = trim((string)($input['bridge_reversal_url'] ?? ''));
        $merchantId = substr(trim((string)($input['merchant_id'] ?? '')), 0, 120);
        $terminalCode = substr(trim((string)($input['terminal_code'] ?? '')), 0, 120);
        $environment = strtolower(trim((string)($input['environment'] ?? 'sandbox')));
        if (!in_array($environment, ['sandbox', 'production'], true)) $environment = 'sandbox';
        $allowedCardTypes = substr(trim((string)($input['allowed_card_types'] ?? 'credito,debito')), 0, 80);
        $installmentsMax = (int)($input['installments_max'] ?? 12);
        if ($installmentsMax < 1) $installmentsMax = 1;
        if ($installmentsMax > 36) $installmentsMax = 36;
        $timeoutSec = (int)($input['timeout_sec'] ?? 45);
        if ($timeoutSec < 10) $timeoutSec = 10;
        if ($timeoutSec > 120) $timeoutSec = 120;
        $enabled = (int)($input['enabled'] ?? 1) ? 1 : 0;
        $bridgeHeadersJson = trim((string)($input['bridge_headers_json'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));

        if ($bridgeSale === '') {
            throw new Exception('Bridge sale URL es obligatorio');
        }

        if ($id > 0) {
            $sql = "UPDATE pos_card_provider_config SET
                        id_caja = :id_caja,
                        provider = :provider,
                        processor = :processor,
                        terminal_type = :terminal_type,
                        bridge_sale_url = :bridge_sale_url,
                        bridge_status_url = :bridge_status_url,
                        bridge_reversal_url = :bridge_reversal_url,
                        merchant_id = :merchant_id,
                        terminal_code = :terminal_code,
                        environment = :environment,
                        allowed_card_types = :allowed_card_types,
                        installments_max = :installments_max,
                        timeout_sec = :timeout_sec,
                        enabled = :enabled,
                        bridge_headers_json = :bridge_headers_json,
                        notes = :notes,
                        updated_by = :updated_by,
                        updated_at = NOW()
                    WHERE id = :id AND id_empresa = :id_empresa";
            $stmt = $master->prepare($sql);
            $stmt->execute([
                ':id_caja' => $idCaja,
                ':provider' => $provider,
                ':processor' => $processor,
                ':terminal_type' => $terminalType,
                ':bridge_sale_url' => $bridgeSale,
                ':bridge_status_url' => $bridgeStatus,
                ':bridge_reversal_url' => $bridgeReversal,
                ':merchant_id' => $merchantId,
                ':terminal_code' => $terminalCode,
                ':environment' => $environment,
                ':allowed_card_types' => $allowedCardTypes,
                ':installments_max' => $installmentsMax,
                ':timeout_sec' => $timeoutSec,
                ':enabled' => $enabled,
                ':bridge_headers_json' => $bridgeHeadersJson,
                ':notes' => $notes,
                ':updated_by' => $idUsuario,
                ':id' => $id,
                ':id_empresa' => $idEmpresa
            ]);
        } else {
            $sql = "INSERT INTO pos_card_provider_config
                    (id_empresa, id_caja, provider, processor, terminal_type,
                     bridge_sale_url, bridge_status_url, bridge_reversal_url,
                     merchant_id, terminal_code, environment, allowed_card_types,
                     installments_max, timeout_sec, enabled, bridge_headers_json, notes,
                     created_by, updated_by, created_at, updated_at)
                    VALUES
                    (:id_empresa, :id_caja, :provider, :processor, :terminal_type,
                     :bridge_sale_url, :bridge_status_url, :bridge_reversal_url,
                     :merchant_id, :terminal_code, :environment, :allowed_card_types,
                     :installments_max, :timeout_sec, :enabled, :bridge_headers_json, :notes,
                     :created_by, :updated_by, NOW(), NOW())";
            $stmt = $master->prepare($sql);
            $stmt->execute([
                ':id_empresa' => $idEmpresa,
                ':id_caja' => $idCaja,
                ':provider' => $provider,
                ':processor' => $processor,
                ':terminal_type' => $terminalType,
                ':bridge_sale_url' => $bridgeSale,
                ':bridge_status_url' => $bridgeStatus,
                ':bridge_reversal_url' => $bridgeReversal,
                ':merchant_id' => $merchantId,
                ':terminal_code' => $terminalCode,
                ':environment' => $environment,
                ':allowed_card_types' => $allowedCardTypes,
                ':installments_max' => $installmentsMax,
                ':timeout_sec' => $timeoutSec,
                ':enabled' => $enabled,
                ':bridge_headers_json' => $bridgeHeadersJson,
                ':notes' => $notes,
                ':created_by' => $idUsuario,
                ':updated_by' => $idUsuario
            ]);
            $id = (int)$master->lastInsertId();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Configuracion guardada',
            'data' => ['id' => $id]
        ]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('ID invalido');
        $stmt = $master->prepare("DELETE FROM pos_card_provider_config WHERE id = :id AND id_empresa = :id_empresa");
        $stmt->execute([':id' => $id, ':id_empresa' => $idEmpresa]);
        echo json_encode(['success' => true, 'message' => 'Configuracion eliminada']);
        exit;
    }

    if ($action === 'test') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $saleUrl = trim((string)($input['bridge_sale_url'] ?? ''));
        $statusUrl = trim((string)($input['bridge_status_url'] ?? ''));
        $reversalUrl = trim((string)($input['bridge_reversal_url'] ?? ''));
        $timeout = (int)($input['timeout_sec'] ?? 20);
        if ($timeout < 5) $timeout = 5;
        if ($timeout > 60) $timeout = 60;

        if ($saleUrl === '') throw new Exception('Bridge sale URL es obligatorio para prueba');
        if ($statusUrl === '') $statusUrl = $saleUrl;
        if ($reversalUrl === '') $reversalUrl = $saleUrl;

        $resultSale = testHealthEndpoint($saleUrl, $timeout);
        $resultStatus = testHealthEndpoint($statusUrl, $timeout);
        $resultRev = testHealthEndpoint($reversalUrl, $timeout);

        echo json_encode([
            'success' => true,
            'message' => 'Prueba ejecutada',
            'data' => [
                'sale' => $resultSale,
                'status' => $resultStatus,
                'reversal' => $resultRev
            ]
        ]);
        exit;
    }

    throw new Exception('Accion no soportada');
} catch (Throwable $e) {
    error_log('Card Provider Config API Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function ensureConfigTable(PDO $pdo): void
{
    $sql = "CREATE TABLE IF NOT EXISTS pos_card_provider_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_empresa INT NOT NULL,
                id_caja INT NULL,
                provider VARCHAR(50) NOT NULL DEFAULT 'bancard',
                processor VARCHAR(50) NOT NULL DEFAULT 'bancard',
                terminal_type VARCHAR(50) NOT NULL DEFAULT 'pinpad',
                bridge_sale_url VARCHAR(255) NOT NULL,
                bridge_status_url VARCHAR(255) NULL,
                bridge_reversal_url VARCHAR(255) NULL,
                merchant_id VARCHAR(120) NULL,
                terminal_code VARCHAR(120) NULL,
                environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
                allowed_card_types VARCHAR(80) NOT NULL DEFAULT 'credito,debito',
                installments_max TINYINT NOT NULL DEFAULT 12,
                timeout_sec INT NOT NULL DEFAULT 45,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                bridge_headers_json TEXT NULL,
                notes TEXT NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_empresa (id_empresa),
                KEY idx_empresa_caja (id_empresa, id_caja),
                KEY idx_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
}

function getConfigs(PDO $pdo, int $idEmpresa): array
{
    $stmt = $pdo->prepare("SELECT * FROM pos_card_provider_config WHERE id_empresa = :id ORDER BY id_caja IS NULL DESC, id_caja ASC, id DESC");
    $stmt->execute([':id' => $idEmpresa]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getCajasEmpresa(int $idEmpresa): array
{
    try {
        $pdoEmpresa = Database::getEmpresaConnection($idEmpresa);
        $cols = [];
        $desc = $pdoEmpresa->query("DESCRIBE cajas");
        while ($row = $desc->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = strtolower((string)$row['Field']);
        }
        if (!in_array('id_caja', $cols, true)) return [];

        $labelCol = null;
        foreach (['descripcion', 'caja', 'nombre'] as $c) {
            if (in_array($c, $cols, true)) {
                $labelCol = $c;
                break;
            }
        }
        $labelExpr = $labelCol ? $labelCol : "CONCAT('Caja #', id_caja)";
        $sql = "SELECT id_caja, $labelExpr AS nombre FROM cajas ORDER BY id_caja";
        $stmt = $pdoEmpresa->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function testEndpoint(string $url, array $payload, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'http' => $http, 'error' => $err];
    }
    $json = json_decode($raw, true);
    return [
        'ok' => $http > 0 && $http < 500,
        'http' => $http,
        'response' => $json ?? substr($raw, 0, 500)
    ];
}

function testHealthEndpoint(string $url, int $timeout): array
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return ['ok' => false, 'http' => 0, 'error' => 'URL invalida'];
    }

    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $healthUrl = $parts['scheme'] . '://' . $parts['host'] . $port . '/health';

    $ch = curl_init($healthUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'http' => $http, 'error' => $err];
    }
    $json = json_decode($raw, true);
    return [
        'ok' => $http >= 200 && $http < 300,
        'http' => $http,
        'url' => $healthUrl,
        'response' => $json ?? substr($raw, 0, 500)
    ];
}
