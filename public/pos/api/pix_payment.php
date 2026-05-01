<?php
/**
 * PIX payment helper (create/status/webhook) for POS.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-PIX-TOKEN');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../../config/bootstrap.php';

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'status')));

try {
    if ($action !== 'webhook' && !Session::isLoggedIn()) {
        throw new Exception('Sesión no válida');
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) $data = [];

    $idEmpresa = (int)($data['id_empresa'] ?? Session::getIdEmpresa());
    $idCaja = (int)($data['id_caja'] ?? 0);
    $idUsuario = (int)($data['id_usuario'] ?? Session::getIdLogin());

    $pdo = Database::getMasterConnection();
    $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id LIMIT 1");
    $stmt->execute([':id' => $idEmpresa]);
    $dbName = (string)$stmt->fetchColumn();
    if ($dbName === '') throw new Exception('Empresa no encontrada');

    ensurePixTable($pdo, $dbName);

    if ($action === 'create') {
        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) throw new Exception('Monto inválido para PIX');

        $txid = 'PIX-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $expiresAt = date('Y-m-d H:i:s', time() + 600);
        $pixPayload = buildPixPayload($txid, $amount, $idEmpresa);
        $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . rawurlencode($pixPayload);

        $ins = $pdo->prepare("INSERT INTO {$dbName}.pagos_pix
            (txid, amount, status, payload_qr, qr_image_url, id_empresa, id_caja, id_usuario, expires_at, created_at, updated_at)
            VALUES (:txid, :amount, 'PENDING', :payload, :img, :emp, :caja, :usr, :exp, NOW(), NOW())");
        $ins->execute([
            ':txid' => $txid,
            ':amount' => $amount,
            ':payload' => $pixPayload,
            ':img' => $qrImageUrl,
            ':emp' => $idEmpresa,
            ':caja' => $idCaja,
            ':usr' => $idUsuario,
            ':exp' => $expiresAt
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Cobro PIX generado',
            'data' => [
                'reference' => $txid,
                'txid' => $txid,
                'amount' => $amount,
                'status' => 'PENDING',
                'expires_at' => $expiresAt,
                'payload_qr' => $pixPayload,
                'qr_image_url' => $qrImageUrl
            ]
        ]);
        exit;
    }

    if ($action === 'status') {
        $reference = trim((string)($data['reference'] ?? $_GET['reference'] ?? ''));
        if ($reference === '') throw new Exception('Referencia PIX requerida');

        $sel = $pdo->prepare("SELECT txid, amount, status, payload_qr, qr_image_url, expires_at, paid_at
            FROM {$dbName}.pagos_pix
            WHERE txid = :txid
            ORDER BY id DESC
            LIMIT 1");
        $sel->execute([':txid' => $reference]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Cobro PIX no encontrado');

        echo json_encode([
            'success' => true,
            'data' => [
                'reference' => (string)$row['txid'],
                'txid' => (string)$row['txid'],
                'amount' => (float)$row['amount'],
                'status' => (string)$row['status'],
                'paid' => strtoupper((string)$row['status']) === 'PAID',
                'expires_at' => (string)$row['expires_at'],
                'paid_at' => (string)($row['paid_at'] ?? ''),
                'payload_qr' => (string)($row['payload_qr'] ?? ''),
                'qr_image_url' => (string)($row['qr_image_url'] ?? '')
            ]
        ]);
        exit;
    }

    if ($action === 'mark_paid') {
        if (!Session::isLoggedIn()) throw new Exception('Sesión no válida');
        $reference = trim((string)($data['reference'] ?? ''));
        if ($reference === '') throw new Exception('Referencia PIX requerida');

        $upd = $pdo->prepare("UPDATE {$dbName}.pagos_pix
            SET status = 'PAID', paid_at = NOW(), updated_at = NOW()
            WHERE txid = :txid
            ORDER BY id DESC
            LIMIT 1");
        $upd->execute([':txid' => $reference]);
        if ($upd->rowCount() <= 0) throw new Exception('No se pudo confirmar PIX');

        echo json_encode([
            'success' => true,
            'message' => 'PIX confirmado'
        ]);
        exit;
    }

    if ($action === 'webhook') {
        // Optional lightweight token for provider callback.
        $tokenExpected = trim((string)getenv('PIX_WEBHOOK_TOKEN'));
        if ($tokenExpected !== '') {
            $tokenIn = trim((string)($_SERVER['HTTP_X_PIX_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ''));
            if ($tokenIn !== $tokenExpected) {
                throw new Exception('Webhook no autorizado');
            }
        }

        $reference = trim((string)($data['reference'] ?? $data['txid'] ?? ''));
        $status = strtoupper(trim((string)($data['status'] ?? '')));
        if ($reference === '') throw new Exception('Referencia requerida');
        if ($status === '') $status = 'PAID';

        $paid = in_array($status, ['PAID', 'APPROVED', 'CONFIRMED', 'SUCCEEDED'], true);
        $newStatus = $paid ? 'PAID' : 'PENDING';

        $upd = $pdo->prepare("UPDATE {$dbName}.pagos_pix
            SET status = :st,
                paid_at = CASE WHEN :paid = 1 THEN NOW() ELSE paid_at END,
                provider_payload = :payload,
                updated_at = NOW()
            WHERE txid = :txid
            ORDER BY id DESC
            LIMIT 1");
        $upd->execute([
            ':st' => $newStatus,
            ':paid' => $paid ? 1 : 0,
            ':payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':txid' => $reference
        ]);

        echo json_encode(['success' => true, 'message' => 'Webhook PIX procesado']);
        exit;
    }

    throw new Exception('Acción no soportada');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function ensurePixTable(PDO $pdo, string $dbName): void
{
    $sql = "CREATE TABLE IF NOT EXISTS {$dbName}.pagos_pix (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        txid VARCHAR(80) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        payload_qr TEXT NULL,
        qr_image_url TEXT NULL,
        provider_payload LONGTEXT NULL,
        id_empresa INT NOT NULL,
        id_caja INT NOT NULL DEFAULT 0,
        id_usuario INT NOT NULL DEFAULT 0,
        id_factura INT NULL,
        expires_at DATETIME NULL,
        paid_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY ux_txid (txid),
        KEY idx_status_created (status, created_at),
        KEY idx_factura (id_factura)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
}

function buildPixPayload(string $txid, float $amount, int $idEmpresa): string
{
    $amountStr = number_format(max(0, $amount), 2, '.', '');
    return 'PIX|EMP:' . $idEmpresa . '|TXID:' . $txid . '|AMT:' . $amountStr . '|CCY:PYG';
}

