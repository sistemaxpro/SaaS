<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function build_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function fetch_pdf_bytes(string $url): string
{
    $cookieHeader = session_name() . '=' . session_id();
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Cookie: {$cookieHeader}\r\nAccept: application/pdf,*/*\r\n",
            'ignore_errors' => true,
            'timeout' => 25,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $bytes = @file_get_contents($url, false, $context);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('No se pudo generar el documento adjunto');
    }

    return $bytes;
}

function pdf_escape_text(string $text): string
{
    $text = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
    return preg_replace('/[^\P{C}\n\r\t]/u', '', $text) ?? $text;
}

function build_simple_pdf_from_text(string $title, string $text): string
{
    $rawLines = preg_split("/\r\n|\r|\n/", $text) ?: [];
    $lines = [];
    foreach ($rawLines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            $lines[] = '';
            continue;
        }
        while (mb_strlen($line, 'UTF-8') > 95) {
            $chunk = mb_substr($line, 0, 95, 'UTF-8');
            $break = mb_strrpos($chunk, ' ', 0, 'UTF-8');
            if ($break !== false && $break > 35) {
                $lines[] = trim(mb_substr($line, 0, $break, 'UTF-8'));
                $line = trim(mb_substr($line, $break + 1, null, 'UTF-8'));
            } else {
                $lines[] = $chunk;
                $line = trim(mb_substr($line, 95, null, 'UTF-8'));
            }
        }
        $lines[] = $line;
    }

    $linesPerPage = 48;
    $pages = array_chunk($lines, $linesPerPage) ?: [[]];
    $objects = [];

    $fontObjectId = 1;
    $pagesObjectId = 2;
    $nextObjectId = 3;
    $pageObjectIds = [];

    foreach ($pages as $pageLines) {
        $content = "BT\n/F1 11 Tf\n40 800 Td\n";
        $content .= '(' . pdf_escape_text($title) . ") Tj\nT*\n";
        $content .= '/F1 9 Tf' . "\n";
        $content .= '(' . pdf_escape_text(str_repeat('=', min(90, max(10, strlen($title))))) . ") Tj\n";
        foreach ($pageLines as $line) {
            $content .= "T*\n(" . pdf_escape_text($line) . ") Tj\n";
        }
        $content .= "ET";

        $contentObjectId = $nextObjectId++;
        $pageObjectId = $nextObjectId++;
        $objects[$contentObjectId] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";
        $objects[$pageObjectId] = "<< /Type /Page /Parent {$pagesObjectId} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontObjectId} 0 R >> >> /Contents {$contentObjectId} 0 R >>";
        $pageObjectIds[] = $pageObjectId;
    }

    $objects[$fontObjectId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $kids = implode(' ', array_map(static fn($id) => $id . ' 0 R', $pageObjectIds));
    $objects[$pagesObjectId] = "<< /Type /Pages /Kids [ {$kids} ] /Count " . count($pageObjectIds) . " >>";
    $catalogObjectId = $nextObjectId++;
    $objects[$catalogObjectId] = "<< /Type /Catalog /Pages {$pagesObjectId} 0 R >>";

    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($catalogObjectId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $catalogObjectId; $i++) {
        $pdf .= str_pad((string)($offsets[$i] ?? 0), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . ($catalogObjectId + 1) . " /Root {$catalogObjectId} 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function send_mail_with_attachment(string $to, string $subject, string $html, string $attachmentName, string $attachmentBytes, string $replyTo): bool
{
    $boundary = 'smx_' . bin2hex(random_bytes(12));
    $headers = [
        'MIME-Version: 1.0',
        'From: SistemaX <noreply@sistemax.pro>',
        'Reply-To: ' . $replyTo,
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'X-Mailer: PHP/' . phpversion(),
    ];

    $body = [];
    $body[] = '--' . $boundary;
    $body[] = 'Content-Type: text/html; charset=UTF-8';
    $body[] = 'Content-Transfer-Encoding: 8bit';
    $body[] = '';
    $body[] = $html;
    $body[] = '';
    $body[] = '--' . $boundary;
    $body[] = 'Content-Type: application/pdf; name="' . $attachmentName . '"';
    $body[] = 'Content-Transfer-Encoding: base64';
    $body[] = 'Content-Disposition: attachment; filename="' . $attachmentName . '"';
    $body[] = '';
    $body[] = chunk_split(base64_encode($attachmentBytes));
    $body[] = '--' . $boundary . '--';
    $body[] = '';

    return mail($to, $subject, implode("\r\n", $body), implode("\r\n", $headers));
}

$input = json_decode(file_get_contents('php://input'), true);

$idFactura = (int)($input['id_factura'] ?? 0);
$idEmpresa = (int)(Session::get('id_empresa') ?? 0);
$email = trim((string)($input['email'] ?? ''));
$printPath = trim((string)($input['print_path'] ?? ''));
$documentLabel = trim((string)($input['document_label'] ?? 'Documento'));
$documentNumber = trim((string)($input['document_number'] ?? ''));
$cliente = trim((string)($input['cliente'] ?? ''));
$fecha = trim((string)($input['fecha'] ?? ''));
$total = trim((string)($input['total'] ?? ''));

if ($idFactura <= 0 || $idEmpresa <= 0 || $printPath === '') {
    json_out(['success' => false, 'error' => 'Datos incompletos'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['success' => false, 'error' => 'Email invalido'], 422);
}

$allowedPrintPaths = [
    '/public/pos/ticket_presupuesto.php',
    '/public/pos/ticket_pedido.php',
];
if (!in_array($printPath, $allowedPrintPaths, true)) {
    json_out(['success' => false, 'error' => 'Ruta de impresion no permitida'], 422);
}

try {
    $pdfUrl = build_base_url() . $printPath . '?id=' . rawurlencode((string)$idFactura) . '&id_empresa=' . rawurlencode((string)$idEmpresa) . '&pdf=1';
    $documentBytes = fetch_pdf_bytes($pdfUrl);
    if (stripos(substr($documentBytes, 0, 12), '%PDF') !== false) {
        $pdfBytes = $documentBytes;
    } else {
        $html = (string)$documentBytes;
        $plain = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</tr>'], ["\n", "\n", "\n", "\n", "\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace("/\n{3,}/", "\n\n", $plain) ?: $plain;
        $plain = trim($plain);
        if ($plain === '') {
            $plain = "{$documentLabel}\nNumero: {$documentNumber}\nCliente/Proveedor: {$cliente}\nFecha: {$fecha}\nTotal: {$total}\nPDF origen: {$pdfUrl}";
        }
        $pdfBytes = build_simple_pdf_from_text($documentLabel . ' ' . ($documentNumber !== '' ? $documentNumber : ('#' . $idFactura)), $plain);
    }

    $safeLabel = preg_replace('/[^a-z0-9]+/i', '_', $documentLabel) ?: 'documento';
    $safeNumber = preg_replace('/[^a-z0-9_-]+/i', '_', $documentNumber) ?: (string)$idFactura;
    $attachmentName = strtolower($safeLabel . '_' . $safeNumber . '.pdf');

    $subject = trim($documentLabel . ' ' . ($documentNumber !== '' ? $documentNumber . ' - ' : '') . 'SistemaX');
    $replyTo = 'info@sistemax.pro';

    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1f2937;">'
        . '<h2 style="margin:0 0 12px;">' . htmlspecialchars($documentLabel, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p>Adjuntamos el PDF del documento solicitado.</p>'
        . '<table style="border-collapse:collapse;margin:16px 0;">'
        . '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;">Numero</td><td style="padding:6px 0;font-weight:700;">' . htmlspecialchars($documentNumber !== '' ? $documentNumber : ('#' . $idFactura), ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;">Cliente/Proveedor</td><td style="padding:6px 0;font-weight:700;">' . htmlspecialchars($cliente !== '' ? $cliente : '-', ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;">Fecha</td><td style="padding:6px 0;font-weight:700;">' . htmlspecialchars($fecha !== '' ? $fecha : '-', ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;">Total</td><td style="padding:6px 0;font-weight:700;">' . htmlspecialchars($total !== '' ? $total : '-', ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<p>Tambien puede consultar el PDF aqui:<br><a href="' . htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p style="margin-top:24px;color:#6b7280;">Enviado desde SistemaX</p>'
        . '</body></html>';

    if (!send_mail_with_attachment($email, $subject, $html, $attachmentName, $pdfBytes, $replyTo)) {
        throw new RuntimeException('No se pudo enviar el email');
    }

    json_out(['success' => true, 'message' => 'Email enviado correctamente']);
} catch (Throwable $e) {
    error_log('[SMX_DOC_EMAIL] ' . $e->getMessage());
    json_out(['success' => false, 'error' => $e->getMessage()], 200);
}
