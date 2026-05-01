<?php
/**
 * Integración base con terminal de tarjetas (patrón comercio PY: POS/PinPad).
 * No procesa PAN completo ni datos sensibles; solo metadatos de autorización.
 *
 * Configuración requerida:
 *   export CARD_TERMINAL_BRIDGE_URL="http://127.0.0.1:8099/sale"
 * Opcional por operación:
 *   export CARD_TERMINAL_BRIDGE_STATUS_URL="http://127.0.0.1:8099/status"
 *   export CARD_TERMINAL_BRIDGE_REVERSAL_URL="http://127.0.0.1:8099/reversal"
 * Opcional:
 *   export CARD_TERMINAL_TIMEOUT="45"
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/bootstrap.php';

    $envGet = static function (string $key, string $default = ''): string {
        $v = getenv($key);
        if ($v !== false && $v !== null && $v !== '') return (string)$v;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return $default;
    };

    if (!Session::isLoggedIn()) {
        throw new Exception('Sesión no válida');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];

    $action = strtolower(trim((string)($data['action'] ?? 'sale')));
    if (!in_array($action, ['sale', 'status', 'reversal'], true)) {
        throw new Exception('Acción no soportada');
    }

    $amount = (float)($data['amount'] ?? 0);
    if ($action === 'sale' && $amount <= 0) {
        throw new Exception('Monto inválido para venta');
    }

    $idEmpresaReq = (int)($data['id_empresa'] ?? Session::getIdEmpresa());
    $idCajaReq = (int)($data['id_caja'] ?? 0);
    $idUsuarioReq = (int)($data['id_usuario'] ?? Session::getIdLogin());

    $installments = (int)($data['installments'] ?? 1);
    if ($installments < 1) $installments = 1;
    if ($installments > 12) $installments = 12;

    $financingType = strtolower(trim((string)($data['financing_type'] ?? 'credito')));
    if (!in_array($financingType, ['credito', 'debito', 'avista', 'lojista', 'emissor'], true)) {
        $financingType = 'credito';
    }
    $processor = strtolower(trim((string)($data['processor'] ?? 'bancard')));

    $bridgeUrl = trim($envGet('CARD_TERMINAL_BRIDGE_URL', 'http://127.0.0.1:8099/sale'));
    $bridgeStatusUrl = trim($envGet('CARD_TERMINAL_BRIDGE_STATUS_URL', 'http://127.0.0.1:8099/status'));
    $bridgeReversalUrl = trim($envGet('CARD_TERMINAL_BRIDGE_REVERSAL_URL', 'http://127.0.0.1:8099/reversal'));
    $timeout = (int)$envGet('CARD_TERMINAL_TIMEOUT', '45');
    if ($timeout <= 0) $timeout = 45;
    $extraHeaders = [];

    // Cargar configuracion por empresa/caja si existe.
    try {
        $masterCfg = Database::getMasterConnection();
        $cfg = loadCardProviderConfig($masterCfg, $idEmpresaReq, $idCajaReq, $processor);
        if ($cfg) {
            if (!empty($cfg['bridge_sale_url'])) $bridgeUrl = trim((string)$cfg['bridge_sale_url']);
            if (!empty($cfg['bridge_status_url'])) $bridgeStatusUrl = trim((string)$cfg['bridge_status_url']);
            if (!empty($cfg['bridge_reversal_url'])) $bridgeReversalUrl = trim((string)$cfg['bridge_reversal_url']);
            if (!empty($cfg['timeout_sec'])) $timeout = max(10, (int)$cfg['timeout_sec']);
            if (!empty($cfg['processor'])) $processor = strtolower(trim((string)$cfg['processor']));
            if (!empty($cfg['bridge_headers_json'])) {
                $hdr = json_decode((string)$cfg['bridge_headers_json'], true);
                if (is_array($hdr)) {
                    foreach ($hdr as $k => $v) {
                        if ($k === '' || $v === null) continue;
                        $extraHeaders[] = $k . ': ' . $v;
                    }
                }
            }
        }
    } catch (Throwable $eCfg) {
        // Fallback a entorno si no hay tabla/config.
    }

    if ($bridgeUrl === '') {
        throw new Exception('Terminal no configurada. Defina CARD_TERMINAL_BRIDGE_URL');
    }

    $payload = [
        'action' => 'sale',
        'amount' => $amount,
        'amount_cents' => (int)round($amount * 100),
        'currency' => 'PYG',
        'country' => strtoupper(trim((string)($data['country'] ?? 'PY'))),
        'integration' => strtolower(trim((string)($data['integration'] ?? 'pos_py'))),
        'installments' => $installments,
        'financing_type' => $financingType,
        'processor' => $processor,
        'id_empresa' => $idEmpresaReq,
        'id_caja' => $idCajaReq,
        'id_usuario' => $idUsuarioReq,
        'trace_id' => 'POS-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
    ];

    if ($action === 'status') {
        $ref = trim((string)($data['reference'] ?? $data['nsu'] ?? $data['transaction_id'] ?? ''));
        if ($ref === '') {
            throw new Exception('Referencia requerida para status');
        }
        $payload['action'] = 'status';
        $payload['reference'] = $ref;
        $payload['amount'] = $amount;
        $payload['amount_cents'] = (int)round($amount * 100);
        $bridgeUrl = $bridgeStatusUrl !== '' ? $bridgeStatusUrl : $bridgeUrl;
    } elseif ($action === 'reversal') {
        $ref = trim((string)($data['reference'] ?? $data['nsu'] ?? $data['transaction_id'] ?? ''));
        if ($ref === '') {
            throw new Exception('Referencia requerida para reversal');
        }
        $payload['action'] = 'reversal';
        $payload['reference'] = $ref;
        $payload['reason'] = trim((string)($data['reason'] ?? 'Operación revertida desde POS'));
        $payload['amount'] = $amount;
        $payload['amount_cents'] = (int)round($amount * 100);
        $bridgeUrl = $bridgeReversalUrl !== '' ? $bridgeReversalUrl : $bridgeUrl;
    }

    $bridgeCheck = ensureCardBridgeAvailable($bridgeUrl, $envGet);
    if (!$bridgeCheck['ok']) {
        throw new Exception($bridgeCheck['message']);
    }

    $httpHeaders = array_merge(['Content-Type: application/json'], $extraHeaders);
    $ch = curl_init($bridgeUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new Exception('No se pudo conectar con la terminal: ' . $err);
    }

    $resp = json_decode($raw, true);
    if (!is_array($resp)) {
        throw new Exception('Respuesta inválida de la terminal');
    }

    if (($http >= 400) || (isset($resp['success']) && !$resp['success'])) {
        $msg = (string)($resp['message'] ?? 'La terminal rechazó la operación');
        throw new Exception($msg);
    }

    $tx = (isset($resp['data']) && is_array($resp['data'])) ? $resp['data'] : $resp;

    $reference = trim((string)(
        $tx['reference']
        ?? $tx['voucher_number']
        ?? $tx['numero_comprobante']
        ?? $tx['comprobante']
        ?? $tx['ticket_number']
        ?? $tx['ticket']
        ?? $tx['codigo_operacion']
        ?? $tx['operation_code']
        ?? $tx['operation_id']
        ?? $tx['nsu']
        ?? $tx['transaction_id']
        ?? $tx['id']
        ?? ''
    ));
    $authCode = trim((string)(
        $tx['auth_code']
        ?? $tx['authorization_code']
        ?? $tx['codigo_autorizacion']
        ?? $tx['autorizacion']
        ?? ''
    ));
    $maskedPan = trim((string)($tx['masked_pan'] ?? $tx['pan_masked'] ?? $tx['tarjeta_enmascarada'] ?? ''));
    $brand = trim((string)($tx['brand'] ?? $tx['card_brand'] ?? $tx['bandeira'] ?? $tx['marca'] ?? ''));
    $transactionId = trim((string)($tx['transaction_id'] ?? $tx['id'] ?? $tx['operation_id'] ?? ''));
    $nsu = trim((string)($tx['nsu'] ?? $tx['host_nsu'] ?? $tx['codigo_nsu'] ?? ''));
    $acquirer = trim((string)($tx['acquirer'] ?? $tx['rede'] ?? $tx['processor'] ?? $tx['adquirente'] ?? ''));
    $rrn = trim((string)($tx['rrn'] ?? $tx['retrieval_reference_number'] ?? $tx['arn'] ?? ''));
    $batch = trim((string)($tx['batch'] ?? $tx['lote'] ?? $tx['batch_number'] ?? $tx['numero_lote'] ?? ''));
    $respInstallments = (int)($tx['installments'] ?? $installments);
    if ($respInstallments < 1) $respInstallments = $installments;
    $respFinancingType = strtolower(trim((string)($tx['financing_type'] ?? $financingType)));
    $respProcessor = strtolower(trim((string)($tx['processor'] ?? $processor)));
    $approved = isset($tx['approved']) ? (bool)$tx['approved'] : true;
    $status = strtoupper(trim((string)($tx['status'] ?? ($approved ? 'APPROVED' : 'DECLINED'))));

    if ($reference === '' && $authCode === '' && $transactionId === '' && $nsu === '' && $rrn === '') {
        $keys = implode(', ', array_keys($tx));
        throw new Exception('La terminal no devolvió referencia de autorización. Claves recibidas: ' . ($keys !== '' ? $keys : '(sin claves)'));
    }

    echo json_encode([
        'success' => true,
        'message' => $action === 'sale' ? 'Tarjeta capturada correctamente' : 'Operación consultada correctamente',
        'data' => [
            'action' => $action,
            'approved' => $approved,
            'status' => $status,
            'reference' => $reference,
            'auth_code' => $authCode,
            'masked_pan' => $maskedPan,
            'brand' => $brand,
            'transaction_id' => $transactionId,
            'nsu' => $nsu,
            'acquirer' => $acquirer,
            'rrn' => $rrn,
            'batch' => $batch,
            'installments' => $respInstallments,
            'financing_type' => $respFinancingType,
            'processor' => $respProcessor,
        ]
    ]);
} catch (Throwable $e) {
    error_log('POS Card Terminal Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}

function loadCardProviderConfig(PDO $pdo, int $idEmpresa, int $idCaja, string $processor = 'bancard'): ?array
{
    try {
        $sql = "SELECT *
                FROM pos_card_provider_config
                WHERE id_empresa = :id_empresa
                  AND enabled = 1
                  AND (:proc = '' OR processor = :proc OR provider = :proc)
                ORDER BY
                  CASE WHEN id_caja = :id_caja THEN 0
                       WHEN id_caja IS NULL OR id_caja = 0 THEN 1
                       ELSE 2 END,
                  id DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_empresa' => $idEmpresa,
            ':id_caja' => $idCaja,
            ':proc' => trim($processor)
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function ensureCardBridgeAvailable(string $bridgeUrl, callable $envGet): array
{
    if (isBridgeReachable($bridgeUrl)) {
        return ['ok' => true, 'message' => ''];
    }

    // Deprecated: auto start del mock; mantener solo compatibilidad temporal.
    $autoStartRaw = strtolower(trim((string)$envGet('CARD_TERMINAL_AUTO_START_MOCK', '0')));
    $autoStartEnabled = !in_array($autoStartRaw, ['0', 'false', 'no', 'off'], true);

    if (!$autoStartEnabled) {
        return [
            'ok' => false,
            'message' => 'No se pudo conectar con la terminal. Inicie el bridge manualmente y verifique URL/puerto.'
        ];
    }

    error_log('POS Card Terminal Warning: CARD_TERMINAL_AUTO_START_MOCK está deprecado y será removido en una próxima versión.');
    $started = autoStartLocalMockBridge($bridgeUrl);
    if ($started && isBridgeReachable($bridgeUrl, 900)) {
        return ['ok' => true, 'message' => ''];
    }

    return [
        'ok' => false,
        'message' => 'No se pudo conectar con la terminal. Verifique el bridge o inícielo manualmente en ' . $bridgeUrl
    ];
}

function isBridgeReachable(string $url, int $timeoutMs = 600): bool
{
    $target = parseBridgeTarget($url);
    if (!$target) return false;

    $errno = 0;
    $errstr = '';
    $timeoutSec = max(0.2, $timeoutMs / 1000);
    $fp = @fsockopen($target['host'], $target['port'], $errno, $errstr, $timeoutSec);
    if (is_resource($fp)) {
        fclose($fp);
        return true;
    }
    return false;
}

function parseBridgeTarget(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts)) return null;
    $host = (string)($parts['host'] ?? '');
    if ($host === '') return null;
    $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port <= 0 || $port > 65535) return null;
    return ['host' => $host, 'port' => $port];
}

function autoStartLocalMockBridge(string $bridgeUrl): bool
{
    $target = parseBridgeTarget($bridgeUrl);
    if (!$target) return false;

    $host = strtolower($target['host']);
    if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
        return false;
    }

    $scriptPath = realpath(__DIR__ . '/../../../scripts/mock_bancard_bridge.php');
    if (!$scriptPath || !is_file($scriptPath)) {
        return false;
    }

    if (isBridgeReachable($bridgeUrl)) {
        return true;
    }

    $logFile = '/tmp/mock_bancard_bridge_' . $target['port'] . '.log';
    $cmd = 'nohup php -S '
        . escapeshellarg($target['host'] . ':' . $target['port'])
        . ' ' . escapeshellarg($scriptPath)
        . ' > ' . escapeshellarg($logFile)
        . ' 2>&1 &';

    $out = @shell_exec($cmd);
    if ($out === null) {
        return false;
    }

    // Espera corta para que el proceso abra el puerto.
    for ($i = 0; $i < 6; $i++) {
        usleep(250000);
        if (isBridgeReachable($bridgeUrl, 700)) {
            return true;
        }
    }
    return false;
}
