<?php
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function env_value(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== null && $v !== '') return (string)$v;
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    return $default;
}

function read_bearer_token(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return '';
}

function normalize_e164(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if (stripos($raw, 'whatsapp:') === 0) {
        $raw = substr($raw, 9);
    }
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return '';
    if (strlen($digits) < 10 || strlen($digits) > 15) return '';
    return '+' . $digits;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    $expectedBearer = trim(env_value('SISTEMAX_WHATSAPP_AUTH_BEARER', env_value('WHATSAPP_AUTH_BEARER', '')));
    if ($expectedBearer !== '') {
        $gotBearer = read_bearer_token();
        if ($gotBearer === '' || !hash_equals($expectedBearer, $gotBearer)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'unauthorized']);
            exit;
        }
    }

    $rawBody = file_get_contents('php://input');
    $json = json_decode((string)$rawBody, true);
    if (!is_array($json)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_json']);
        exit;
    }

    $to = normalize_e164((string)($json['to'] ?? ''));
    $message = trim((string)($json['message'] ?? ''));
    if ($to === '' || $message === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'to_or_message_required']);
        exit;
    }

    $accountSid = trim(env_value('TWILIO_ACCOUNT_SID', ''));
    $authToken = trim(env_value('TWILIO_AUTH_TOKEN', ''));
    $fromWhatsapp = trim(env_value('TWILIO_WHATSAPP_FROM', '+14155238886'));

    if ($accountSid === '' || $authToken === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'twilio_credentials_missing']);
        exit;
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json';
    $payload = [
        'To' => 'whatsapp:' . $to,
        'From' => (stripos($fromWhatsapp, 'whatsapp:') === 0 ? $fromWhatsapp : 'whatsapp:' . $fromWhatsapp),
        'Body' => $message,
    ];

    if (!function_exists('curl_init')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'curl_not_available']);
        exit;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 30,
    ]);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'twilio_curl_error', 'detail' => $err]);
        exit;
    }

    $data = json_decode((string)$resp, true);
    if ($http < 200 || $http >= 300) {
        $twilioMsg = is_array($data) ? ((string)($data['message'] ?? '')) : '';
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => 'twilio_http_error',
            'http' => $http,
            'detail' => ($twilioMsg !== '' ? $twilioMsg : substr((string)$resp, 0, 350)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'success' => true,
        'provider' => 'twilio-whatsapp',
        'sid' => is_array($data) ? ($data['sid'] ?? null) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_exception', 'detail' => $e->getMessage()]);
}

