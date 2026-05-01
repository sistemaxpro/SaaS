<?php

if (!function_exists('sx_env_value')) {
    function sx_env_value(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== null && $v !== '') return (string)$v;
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];

        // Compatibilidad con variables legacy sin prefijo SISTEMAX_.
        $aliases = [
            'SISTEMAX_WHATSAPP_ENDPOINT' => 'WHATSAPP_ENDPOINT',
            'SISTEMAX_WHATSAPP_AUTH_BEARER' => 'WHATSAPP_AUTH_BEARER',
            'SISTEMAX_WHATSAPP_TOKEN' => 'WHATSAPP_TOKEN',
            'SISTEMAX_WHATSAPP_PHONE_NUMBER_ID' => 'WHATSAPP_PHONE_NUMBER_ID',
            'SISTEMAX_WHATSAPP_GRAPH_VERSION' => 'WHATSAPP_GRAPH_VERSION',
            'SISTEMAX_WHATSAPP_ENABLED' => 'WHATSAPP_ENABLED',
            'SISTEMAX_SUPPORT_WHATSAPP' => 'SUPPORT_WHATSAPP',
            'SISTEMAX_WHATSAPP_ADMIN_TO' => 'WHATSAPP_ADMIN_TO',
        ];
        $legacyKey = $aliases[$key] ?? '';
        if ($legacyKey !== '') {
            $legacyVal = getenv($legacyKey);
            if ($legacyVal !== false && $legacyVal !== null && $legacyVal !== '') return (string)$legacyVal;
            if (isset($_SERVER[$legacyKey]) && $_SERVER[$legacyKey] !== '') return (string)$_SERVER[$legacyKey];
            if (isset($_ENV[$legacyKey]) && $_ENV[$legacyKey] !== '') return (string)$_ENV[$legacyKey];
        }

        $fromfile = sx_env_file_value($key, '');
        if ($fromfile !== '') return $fromfile;

        return $default;
    }
}


if (!function_exists('sx_env_file_value')) {
    function sx_env_file_value(string $key, string $default = ''): string
    {
        static $cache = null;
        if (!is_array($cache)) {
            $cache = [];
            $path = '/etc/environment';
            if (is_readable($path)) {
                $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '' || $line[0] === '#') continue;
                    $eq = strpos($line, '=');
                    if ($eq === false) continue;
                    $k = trim(substr($line, 0, $eq));
                    $v = trim(substr($line, $eq + 1));
                    if ($k === '') continue;
                    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    $cache[$k] = $v;
                }
            }
        }
        return (string)($cache[$key] ?? $default);
    }
}


if (!function_exists('sx_normalize_whatsapp_to_e164')) {
    function sx_normalize_whatsapp_to_e164(string $raw, string $defaultCountry = '+595'): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $defaultDigits = preg_replace('/\D+/', '', $defaultCountry);
        if ($defaultDigits === '') $defaultDigits = '595';

        if (strpos($raw, '+') === 0) return preg_replace('/\D+/', '', $raw);

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') return '';
        if (strpos($digits, '00') === 0) return substr($digits, 2);
        if ($digits[0] === '0') return $defaultDigits . ltrim($digits, '0');
        if (strpos($digits, $defaultDigits) === 0) return $digits;
        if (strlen($digits) <= 10) return $defaultDigits . $digits;
        return $digits;
    }
}

if (!function_exists('sx_send_whatsapp_via_endpoint')) {
    function sx_send_whatsapp_via_endpoint(string $toE164, string $message): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => 'cURL no disponible en PHP'];
        }
        $endpoint = trim(sx_env_value('SISTEMAX_WHATSAPP_ENDPOINT', ''));
        if ($endpoint === '') {
            return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => 'SISTEMAX_WHATSAPP_ENDPOINT no configurado'];
        }
        $token = trim(sx_env_value('SISTEMAX_WHATSAPP_AUTH_BEARER', ''));
        $payload = json_encode(['to' => $toE164, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => 'No se pudo serializar payload'];
        }
        $headers = ['Content-Type: application/json'];
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 25,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => ('curl error: ' . $err)];
        }
        if ($http < 200 || $http >= 300) {
            return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => ('HTTP ' . $http . ': ' . substr((string)$resp, 0, 300))];
        }
        return ['success' => true, 'provider' => 'whatsapp-endpoint', 'error' => null];
    }
}

if (!function_exists('sx_send_whatsapp_via_meta')) {
    function sx_send_whatsapp_via_meta(string $toE164, string $message): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => 'cURL no disponible en PHP'];
        }
        $token = trim(sx_env_value('SISTEMAX_WHATSAPP_TOKEN', ''));
        $phoneNumberId = trim(sx_env_value('SISTEMAX_WHATSAPP_PHONE_NUMBER_ID', ''));
        $version = trim(sx_env_value('SISTEMAX_WHATSAPP_GRAPH_VERSION', 'v21.0'));
        if ($token === '' || $phoneNumberId === '') {
            return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => 'Faltan SISTEMAX_WHATSAPP_TOKEN o SISTEMAX_WHATSAPP_PHONE_NUMBER_ID'];
        }

        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toE164,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => $message],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => 'No se pudo serializar payload'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 25,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => ('curl error: ' . $err)];
        }
        $data = json_decode((string)$resp, true);
        if ($http < 200 || $http >= 300) {
            $metaErr = is_array($data) ? ($data['error']['message'] ?? '') : '';
            $msg = $metaErr !== '' ? $metaErr : substr((string)$resp, 0, 300);
            return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => ('HTTP ' . $http . ': ' . $msg)];
        }
        return ['success' => true, 'provider' => 'meta-whatsapp', 'error' => null];
    }
}

if (!function_exists('sx_send_whatsapp_message')) {
    function sx_send_whatsapp_message(string $toRaw, string $message, string $defaultCountry = '+595'): array
    {
        $enabled = strtolower(trim(sx_env_value('SISTEMAX_WHATSAPP_ENABLED', '1')));
        if (in_array($enabled, ['0', 'false', 'off', 'no'], true)) {
            return ['success' => false, 'provider' => 'whatsapp', 'error' => 'WhatsApp deshabilitado por configuración'];
        }
        $toE164 = sx_normalize_whatsapp_to_e164($toRaw, $defaultCountry);
        if ($toE164 === '' || strlen($toE164) < 10) {
            return ['success' => false, 'provider' => 'whatsapp', 'error' => 'Número destino inválido'];
        }
        if (trim(sx_env_value('SISTEMAX_WHATSAPP_ENDPOINT', '')) !== '') {
            return sx_send_whatsapp_via_endpoint($toE164, $message);
        }
        return sx_send_whatsapp_via_meta($toE164, $message);
    }
}

if (!function_exists('sx_ensure_whatsapp_outbox_table')) {
    function sx_ensure_whatsapp_outbox_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS saas_notificaciones_outbox (
                id_outbox INT NOT NULL AUTO_INCREMENT,
                canal VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
                tipo VARCHAR(60) NULL,
                id_solicitud INT NULL,
                destino VARCHAR(40) NOT NULL,
                mensaje TEXT NOT NULL,
                payload_json LONGTEXT NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                intentos INT NOT NULL DEFAULT 0,
                max_intentos INT NOT NULL DEFAULT 6,
                ultimo_error VARCHAR(500) NULL,
                proveedor VARCHAR(40) NULL,
                hash_dedupe CHAR(40) NULL,
                next_retry_at DATETIME NULL,
                lock_token VARCHAR(64) NULL,
                lock_until DATETIME NULL,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id_outbox),
                KEY idx_estado_retry (estado, next_retry_at),
                KEY idx_canal (canal),
                KEY idx_hash (hash_dedupe)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('sx_log_whatsapp_attempt')) {
    function sx_log_whatsapp_attempt(PDO $pdo, ?int $idSolicitud, string $tipo, string $destino, string $asunto, array $result): void
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO saas_solicitudes_mails
                (id_solicitud, tipo, destino, asunto, provider, estado, error_msg)
                VALUES
                (:id_solicitud, :tipo, :destino, :asunto, :provider, :estado, :error_msg)
            ");
            $stmt->execute([
                ':id_solicitud' => $idSolicitud,
                ':tipo' => substr($tipo, 0, 30),
                ':destino' => substr($destino, 0, 190),
                ':asunto' => substr($asunto, 0, 255),
                ':provider' => substr((string)($result['provider'] ?? 'whatsapp'), 0, 30),
                ':estado' => (!empty($result['success']) ? 'enviado' : 'error'),
                ':error_msg' => !empty($result['error']) ? substr((string)$result['error'], 0, 500) : null,
            ]);
        } catch (Exception $e) {
            error_log('WhatsApp queue log error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sx_enqueue_whatsapp')) {
    function sx_enqueue_whatsapp(PDO $pdo, array $data): array
    {
        sx_ensure_whatsapp_outbox_table($pdo);
        $destino = trim((string)($data['destino'] ?? ''));
        $mensaje = trim((string)($data['mensaje'] ?? ''));
        $tipo = trim((string)($data['tipo'] ?? 'whatsapp'));
        $idSolicitud = isset($data['id_solicitud']) ? (int)$data['id_solicitud'] : null;
        $payload = isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        if ($destino === '' || $mensaje === '') {
            return ['success' => false, 'error' => 'Destino o mensaje vacío'];
        }

        $hash = sha1($tipo . '|' . $idSolicitud . '|' . $destino . '|' . $mensaje);
        $stmtCheck = $pdo->prepare("
            SELECT id_outbox
            FROM saas_notificaciones_outbox
            WHERE hash_dedupe = :hash
              AND estado IN ('pendiente','enviando','enviado','error')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            LIMIT 1
        ");
        $stmtCheck->execute([':hash' => $hash]);
        $exists = (int)($stmtCheck->fetchColumn() ?: 0);
        if ($exists > 0) {
            return ['success' => true, 'id_outbox' => $exists, 'dedupe' => true];
        }

        $stmt = $pdo->prepare("
            INSERT INTO saas_notificaciones_outbox
            (canal, tipo, id_solicitud, destino, mensaje, payload_json, estado, intentos, max_intentos, hash_dedupe, next_retry_at)
            VALUES
            ('whatsapp', :tipo, :id_solicitud, :destino, :mensaje, :payload_json, 'pendiente', 0, :max_intentos, :hash, NOW())
        ");
        $stmt->execute([
            ':tipo' => $tipo,
            ':id_solicitud' => $idSolicitud,
            ':destino' => $destino,
            ':mensaje' => $mensaje,
            ':payload_json' => $payload,
            ':max_intentos' => (int)($data['max_intentos'] ?? 6),
            ':hash' => $hash,
        ]);
        return ['success' => true, 'id_outbox' => (int)$pdo->lastInsertId(), 'dedupe' => false];
    }
}

if (!function_exists('sx_process_whatsapp_outbox')) {
    function sx_process_whatsapp_outbox(PDO $pdo, int $limit = 20): array
    {
        sx_ensure_whatsapp_outbox_table($pdo);
        $limit = max(1, min(200, $limit));
        $stats = ['picked' => 0, 'sent' => 0, 'failed' => 0];

        $stmt = $pdo->prepare("
            SELECT id_outbox, tipo, id_solicitud, destino, mensaje, intentos, max_intentos
            FROM saas_notificaciones_outbox
            WHERE canal = 'whatsapp'
              AND estado IN ('pendiente','error')
              AND intentos < max_intentos
              AND (next_retry_at IS NULL OR next_retry_at <= NOW())
              AND (lock_until IS NULL OR lock_until < NOW())
            ORDER BY id_outbox ASC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stats['picked'] = count($rows);

        foreach ($rows as $row) {
            $id = (int)$row['id_outbox'];
            $token = bin2hex(random_bytes(12));
            $lock = $pdo->prepare("
                UPDATE saas_notificaciones_outbox
                SET estado = 'enviando',
                    lock_token = :tok,
                    lock_until = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
                WHERE id_outbox = :id
                  AND (lock_until IS NULL OR lock_until < NOW())
            ");
            $lock->execute([':tok' => $token, ':id' => $id]);
            if ($lock->rowCount() <= 0) {
                continue;
            }

            $res = sx_send_whatsapp_message((string)$row['destino'], (string)$row['mensaje']);
            $intentos = (int)$row['intentos'] + 1;

            if (!empty($res['success'])) {
                $upd = $pdo->prepare("
                    UPDATE saas_notificaciones_outbox
                    SET estado = 'enviado',
                        intentos = :intentos,
                        proveedor = :prov,
                        ultimo_error = NULL,
                        sent_at = NOW(),
                        next_retry_at = NULL,
                        lock_token = NULL,
                        lock_until = NULL
                    WHERE id_outbox = :id
                ");
                $upd->execute([
                    ':intentos' => $intentos,
                    ':prov' => substr((string)($res['provider'] ?? 'whatsapp'), 0, 40),
                    ':id' => $id,
                ]);
                sx_log_whatsapp_attempt(
                    $pdo,
                    isset($row['id_solicitud']) ? (int)$row['id_solicitud'] : null,
                    (string)($row['tipo'] ?? 'whatsapp'),
                    (string)$row['destino'],
                    'WhatsApp outbox enviado',
                    $res
                );
                $stats['sent']++;
                continue;
            }

            $backoff = min(60, max(1, (int)pow(2, min($intentos, 6))));
            $upd = $pdo->prepare("
                UPDATE saas_notificaciones_outbox
                SET estado = 'error',
                    intentos = :intentos,
                    proveedor = :prov,
                    ultimo_error = :err,
                    next_retry_at = DATE_ADD(NOW(), INTERVAL {$backoff} MINUTE),
                    lock_token = NULL,
                    lock_until = NULL
                WHERE id_outbox = :id
            ");
            $upd->execute([
                ':intentos' => $intentos,
                ':prov' => substr((string)($res['provider'] ?? 'whatsapp'), 0, 40),
                ':err' => substr((string)($res['error'] ?? 'Error desconocido'), 0, 500),
                ':id' => $id,
            ]);
            sx_log_whatsapp_attempt(
                $pdo,
                isset($row['id_solicitud']) ? (int)$row['id_solicitud'] : null,
                (string)($row['tipo'] ?? 'whatsapp'),
                (string)$row['destino'],
                'WhatsApp outbox error',
                $res
            );
            $stats['failed']++;
        }

        return $stats;
    }
}
