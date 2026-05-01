<?php
/**
 * Mock Bridge local para pruebas POS Tarjeta (Bancard/Terminal style).
 *
 * Uso recomendado (router script):
 *   php -S 127.0.0.1:8099 scripts/mock_bancard_bridge.php
 *
 * Endpoints soportados:
 *   POST /sale
 *   POST /status
 *   POST /reversal
 *
 * También acepta action desde JSON:
 *   {"action":"sale|status|reversal", ...}
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$path = strtolower($path);

if ($path === '/health') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'service' => 'mock_bancard_bridge',
        'status' => 'UP',
        'time' => date('c')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido']);
    exit;
}

$storeFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mock_bancard_bridge_store.json';

function loadStore(string $file): array
{
    if (!is_file($file)) return ['tx' => []];
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return ['tx' => []];
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['tx' => []];
    if (!isset($data['tx']) || !is_array($data['tx'])) $data['tx'] = [];
    return $data;
}

function saveStore(string $file, array $store): void
{
    @file_put_contents($file, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function randDigits(int $len): string
{
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= (string)random_int(0, 9);
    return $s;
}

function nowIso(): string
{
    return date('c');
}

$input = file_get_contents('php://input');
$data = json_decode($input ?: '', true);
if (!is_array($data)) $data = [];

$action = strtolower((string)($data['action'] ?? ''));
if ($action === '') {
    if ($path === '/sale') $action = 'sale';
    elseif ($path === '/status') $action = 'status';
    elseif ($path === '/reversal') $action = 'reversal';
}
if (!in_array($action, ['sale', 'status', 'reversal'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Action invalida']);
    exit;
}

$store = loadStore($storeFile);

if ($action === 'sale') {
    $amount = (float)($data['amount'] ?? 0);
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Monto invalido']);
        exit;
    }

    $processor = strtolower(trim((string)($data['processor'] ?? 'bancard')));
    $installments = (int)($data['installments'] ?? 1);
    if ($installments < 1) $installments = 1;
    if ($installments > 12) $installments = 12;
    $financingType = strtolower(trim((string)($data['financing_type'] ?? 'credito')));
    if (!in_array($financingType, ['credito', 'debito', 'avista', 'lojista', 'emissor'], true)) {
        $financingType = 'credito';
    }

    // Permite forzar rechazo para pruebas: send {"mock_decline":true}
    $mockDecline = (bool)($data['mock_decline'] ?? false);
    $approved = !$mockDecline;
    $status = $approved ? 'APPROVED' : 'DECLINED';

    $reference = 'REF' . randDigits(8);
    $transactionId = 'TX-' . randDigits(10);
    $authCode = $approved ? randDigits(6) : '';
    $nsu = randDigits(6);
    $rrn = randDigits(12);
    $batch = randDigits(4);
    $brand = (string)($data['mock_brand'] ?? 'VISA');
    $maskedPan = '4509********' . randDigits(4);

    $tx = [
        'action' => 'sale',
        'approved' => $approved,
        'status' => $status,
        'reference' => $reference,
        'auth_code' => $authCode,
        'masked_pan' => $maskedPan,
        'brand' => $brand,
        'transaction_id' => $transactionId,
        'nsu' => $nsu,
        'acquirer' => $processor,
        'rrn' => $rrn,
        'batch' => $batch,
        'installments' => $installments,
        'financing_type' => $financingType,
        'processor' => $processor,
        'amount' => $amount,
        'currency' => (string)($data['currency'] ?? 'PYG'),
        'created_at' => nowIso(),
        'reversed' => false,
    ];

    $store['tx'][$reference] = $tx;
    saveStore($storeFile, $store);

    echo json_encode([
        'success' => true,
        'message' => $approved ? 'Mock venta aprobada' : 'Mock venta rechazada',
        'data' => $tx
    ]);
    exit;
}

// status/reversal require reference/nsu/transaction_id
$ref = trim((string)($data['reference'] ?? ''));
$nsuReq = trim((string)($data['nsu'] ?? ''));
$txReq = trim((string)($data['transaction_id'] ?? ''));

$found = null;
foreach ($store['tx'] as $k => $row) {
    if (($ref !== '' && $k === $ref) ||
        ($nsuReq !== '' && (string)($row['nsu'] ?? '') === $nsuReq) ||
        ($txReq !== '' && (string)($row['transaction_id'] ?? '') === $txReq)) {
        $found = $row;
        $ref = $k;
        break;
    }
}

if (!$found) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Transaccion no encontrada']);
    exit;
}

if ($action === 'status') {
    $status = ($found['reversed'] ?? false) ? 'REVERSED' : (string)($found['status'] ?? 'APPROVED');
    $approved = $status === 'APPROVED';

    $found['action'] = 'status';
    $found['status'] = $status;
    $found['approved'] = $approved;
    $found['checked_at'] = nowIso();

    echo json_encode([
        'success' => true,
        'message' => 'Mock status obtenido',
        'data' => $found
    ]);
    exit;
}

// reversal
if (($found['reversed'] ?? false) === true) {
    echo json_encode([
        'success' => true,
        'message' => 'Mock transaccion ya estaba revertida',
        'data' => [
            'action' => 'reversal',
            'approved' => true,
            'status' => 'REVERSED',
            'reference' => $ref,
            'transaction_id' => $found['transaction_id'] ?? '',
            'nsu' => $found['nsu'] ?? '',
            'rrn' => $found['rrn'] ?? '',
            'batch' => $found['batch'] ?? '',
            'processor' => $found['processor'] ?? 'bancard',
            'reversed_at' => $found['reversed_at'] ?? nowIso(),
        ]
    ]);
    exit;
}

$found['reversed'] = true;
$found['reversed_at'] = nowIso();
$found['status'] = 'REVERSED';
$found['approved'] = true;
$store['tx'][$ref] = $found;
saveStore($storeFile, $store);

echo json_encode([
    'success' => true,
    'message' => 'Mock reversal aplicado',
    'data' => [
        'action' => 'reversal',
        'approved' => true,
        'status' => 'REVERSED',
        'reference' => $ref,
        'transaction_id' => $found['transaction_id'] ?? '',
        'nsu' => $found['nsu'] ?? '',
        'rrn' => $found['rrn'] ?? '',
        'batch' => $found['batch'] ?? '',
        'processor' => $found['processor'] ?? 'bancard',
        'reversed_at' => $found['reversed_at'],
    ]
]);
