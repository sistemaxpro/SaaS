<?php
require_once __DIR__ . '/../config/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function smtpReadResponse($socket): array
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    $code = (int)substr(trim($response), 0, 3);
    return [$code, trim($response)];
}

function smtpCommand($socket, string $command, array $expectedCodes): array
{
    fwrite($socket, $command . "\r\n");
    [$code, $response] = smtpReadResponse($socket);
    return [in_array($code, $expectedCodes, true), $code, $response];
}

function smtpSendMailHostingerDebug(string $to, string $subject, string $html, string $fromEmail, string $replyTo): array
{
    $host = trim((string)(getenv('SISTEMAX_SMTP_HOST') ?: 'smtp.hostinger.com'));
    $port = (int)(getenv('SISTEMAX_SMTP_PORT') ?: 465);
    $username = trim((string)(getenv('SISTEMAX_SMTP_USER') ?: $fromEmail));
    $password = trim((string)(getenv('SISTEMAX_SMTP_PASS') ?: ''));
    $timeout = 20;
    $log = [];

    if ($password === '') {
        return ['success' => false, 'log' => ['Falta SISTEMAX_SMTP_PASS']];
    }

    $socket = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        return ['success' => false, 'log' => ['Connect error: ' . $errstr . ' (' . $errno . ')']];
    }
    stream_set_timeout($socket, $timeout);

    [$code, $greeting] = smtpReadResponse($socket);
    $log[] = 'S: ' . $greeting;
    if ($code !== 220) {
        fclose($socket);
        return ['success' => false, 'log' => $log];
    }

    fwrite($socket, "EHLO sistemax.pro\r\n");
    [, $respEhlo] = smtpReadResponse($socket);
    $log[] = 'S: ' . $respEhlo;

    [$okAuth, $codeAuth, $respAuth] = smtpCommand($socket, 'AUTH LOGIN', [334]);
    $log[] = 'C: AUTH LOGIN';
    $log[] = 'S: [' . $codeAuth . '] ' . $respAuth;
    if (!$okAuth) {
        fclose($socket);
        return ['success' => false, 'log' => $log];
    }

    [$okUser, $codeUser, $respUser] = smtpCommand($socket, base64_encode($username), [334]);
    $log[] = 'C: USER(base64)';
    $log[] = 'S: [' . $codeUser . '] ' . $respUser;
    if (!$okUser) {
        fclose($socket);
        return ['success' => false, 'log' => $log];
    }

    [$okPass, $codePass, $respPass] = smtpCommand($socket, base64_encode($password), [235]);
    $log[] = 'C: PASS(base64)';
    $log[] = 'S: [' . $codePass . '] ' . $respPass;
    if (!$okPass) {
        fclose($socket);
        return ['success' => false, 'log' => $log];
    }

    [$okFrom, $codeFrom, $respFrom] = smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    $log[] = 'C: MAIL FROM';
    $log[] = 'S: [' . $codeFrom . '] ' . $respFrom;
    if (!$okFrom) {
        fclose($socket);
        return ['success' => false, 'log' => $log];
    }

    [$okRcpt, $codeRcpt, $respRcpt] = smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    $log[] = 'C: RCPT TO';
    $log[] = 'S: [' . $codeRcpt . '] ' . $respRcpt;
    if (!$okRcpt) {
        fclose($socket);
        return ['success' => false, 'log' => $log];
    }

    [$okData, $codeData, $respData] = smtpCommand($socket, 'DATA', [354]);
    $log[] = 'C: DATA';
    $log[] = 'S: [' . $codeData . '] ' . $respData;
    if (!$okData) {
        fclose($socket);
        return ['success' => false, 'log' => $log];
    }

    $headers = [
        'Date: ' . date('r'),
        'From: Sistemax <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Reply-To: ' . $replyTo,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n.";
    fwrite($socket, $data . "\r\n");

    [$codeEnd, $respEnd] = smtpReadResponse($socket);
    $log[] = 'S: [' . $codeEnd . '] ' . $respEnd;
    smtpCommand($socket, 'QUIT', [221, 250]);
    fclose($socket);

    return ['success' => $codeEnd === 250, 'log' => $log];
}

$result = null;
$to = trim((string)($_POST['to'] ?? ''));
$fromEmail = trim((string)(getenv('SISTEMAX_NOREPLY_EMAIL') ?: 'no-responder@sistemax.pro'));
$replyTo = trim((string)(getenv('SISTEMAX_SUPPORT_EMAIL') ?: $fromEmail));
$host = trim((string)(getenv('SISTEMAX_SMTP_HOST') ?: 'smtp.hostinger.com'));
$port = (int)(getenv('SISTEMAX_SMTP_PORT') ?: 465);
$user = trim((string)(getenv('SISTEMAX_SMTP_USER') ?: $fromEmail));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = ['success' => false, 'message' => 'Email destino inválido', 'log' => []];
    } else {
        $subject = 'Prueba SMTP Sistemax';
        $html = '<h2>Prueba SMTP OK</h2><p>Fecha: ' . date('Y-m-d H:i:s') . '</p>';
        $smtp = smtpSendMailHostingerDebug($to, $subject, $html, $fromEmail, $replyTo);

        if ($smtp['success']) {
            $result = ['success' => true, 'message' => 'Email enviado por SMTP', 'log' => $smtp['log']];
        } else {
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: Sistemax <' . $fromEmail . '>',
                'Reply-To: ' . $replyTo,
                'X-Mailer: PHP/' . phpversion(),
            ];
            $fallback = @mail($to, $subject, $html, implode("\r\n", $headers));
            $result = [
                'success' => $fallback,
                'message' => $fallback ? 'SMTP falló, pero mail() sí envió.' : 'Falló SMTP y también mail().',
                'log' => $smtp['log'],
            ];
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test SMTP - Sistemax</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
<main class="max-w-3xl mx-auto p-6">
    <div class="bg-white border border-slate-200 rounded-2xl p-6">
        <h1 class="text-2xl font-bold mb-2">Test SMTP (Hostinger)</h1>
        <p class="text-sm text-slate-600 mb-4">Envía un correo de prueba y muestra diagnóstico SMTP.</p>

        <div class="mb-4 rounded-lg bg-slate-50 border border-slate-200 p-3 text-sm">
            <div><strong>Host:</strong> <?= h($host) ?>:<?= h((string)$port) ?></div>
            <div><strong>User:</strong> <?= h($user) ?></div>
            <div><strong>From:</strong> <?= h($fromEmail) ?></div>
            <div><strong>Reply-To:</strong> <?= h($replyTo) ?></div>
        </div>

        <form method="post" class="flex gap-2">
            <input type="email" name="to" value="<?= h($to) ?>" required placeholder="destino@correo.com" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg">
            <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700">Enviar prueba</button>
        </form>

        <?php if (is_array($result)): ?>
            <div class="mt-4 rounded-lg border px-4 py-3 text-sm <?= $result['success'] ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-rose-300 bg-rose-50 text-rose-700' ?>">
                <?= h($result['message']) ?>
            </div>
            <?php if (!empty($result['log'])): ?>
                <pre class="mt-3 rounded-lg border border-slate-200 bg-slate-900 text-slate-100 p-3 text-xs overflow-auto"><?= h(implode("\n", $result['log'])) ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

