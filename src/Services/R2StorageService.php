<?php

class R2StorageService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require __DIR__ . '/../../config/r2.php';

        if (!$this->isEnabled()) {
            throw new RuntimeException('R2 no está configurado');
        }
    }

    public static function isConfigured(): bool
    {
        $cfg = @include __DIR__ . '/../../config/r2.php';
        if (!is_array($cfg)) {
            return false;
        }

        return !empty($cfg['enabled'])
            && !empty($cfg['access_key_id'])
            && !empty($cfg['secret_access_key'])
            && !empty($cfg['bucket'])
            && !empty($cfg['endpoint'])
            && !empty($cfg['public_base_url']);
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    public function publicUrl(string $key): string
    {
        $base = rtrim((string)$this->config['public_base_url'], '/');
        return $base . '/' . ltrim($key, '/');
    }

    public function putObject(string $key, string $body, string $contentType = 'application/octet-stream', array $extraHeaders = []): array
    {
        $key = ltrim($key, '/');
        if ($key === '') {
            throw new InvalidArgumentException('Key inválido');
        }

        $sha256 = hash('sha256', $body);
        $headers = array_merge([
            'content-type' => $contentType,
            'x-amz-content-sha256' => $sha256,
        ], $this->normalizeHeaders($extraHeaders));

        $result = $this->request('PUT', $key, '', $headers, $body);

        return [
            'key' => $key,
            'url' => $this->publicUrl($key),
            'etag' => $result['headers']['etag'] ?? '',
            'size' => strlen($body),
            'name' => basename($key),
            'id' => 'r2:' . $key,
        ];
    }

    public function deleteObject(string $key): void
    {
        $key = ltrim($key, '/');
        if ($key === '') {
            return;
        }

        $this->request('DELETE', $key, '', [
            'x-amz-content-sha256' => hash('sha256', ''),
        ], '');
    }

    public function objectExists(string $key): bool
    {
        $key = ltrim($key, '/');
        if ($key === '') {
            return false;
        }
        try {
            $this->request('HEAD', $key, '', [
                'x-amz-content-sha256' => hash('sha256', ''),
            ], '');
            return true;
        } catch (Throwable $e) {
            $msg = (string)$e->getMessage();
            if (stripos($msg, 'HTTP 404') !== false) {
                return false;
            }
            throw $e;
        }
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $k => $v) {
            $key = strtolower(trim((string)$k));
            if ($key === '') {
                continue;
            }
            $normalized[$key] = trim((string)$v);
        }
        return $normalized;
    }

    private function request(string $method, string $key, string $query = '', array $headers = [], string $body = ''): array
    {
        $endpoint = rtrim((string)$this->config['endpoint'], '/');
        $bucket = trim((string)$this->config['bucket']);
        $accessKey = trim((string)$this->config['access_key_id']);
        $secretKey = trim((string)$this->config['secret_access_key']);

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('R2 config incompleta');
        }

        $url = $endpoint . '/' . rawurlencode($bucket) . '/' . $this->encodePath($key);
        if ($query !== '') {
            $url .= '?' . $query;
        }

        $parsed = parse_url($endpoint);
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            throw new RuntimeException('Endpoint R2 inválido');
        }

        $now = gmdate('Ymd\\THis\\Z');
        $date = gmdate('Ymd');
        $region = 'auto';
        $service = 's3';

        $amzHeaders = [
            'host' => $host,
            'x-amz-date' => $now,
        ];
        foreach ($headers as $hk => $hv) {
            $amzHeaders[strtolower($hk)] = trim((string)$hv);
        }
        if (!isset($amzHeaders['x-amz-content-sha256'])) {
            $amzHeaders['x-amz-content-sha256'] = hash('sha256', $body);
        }

        ksort($amzHeaders);

        $canonicalHeaders = '';
        $signedHeaderKeys = [];
        foreach ($amzHeaders as $hk => $hv) {
            $canonicalHeaders .= $hk . ':' . preg_replace('/\s+/', ' ', $hv) . "\n";
            $signedHeaderKeys[] = $hk;
        }
        $signedHeaders = implode(';', $signedHeaderKeys);

        $canonicalUri = '/' . rawurlencode($bucket) . '/' . $this->encodePath($key);
        $canonicalQuery = $this->canonicalizeQuery($query);

        $payloadHash = $amzHeaders['x-amz-content-sha256'];

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = implode("\n", [
            $algorithm,
            $now,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($secretKey, $date, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = $algorithm
            . ' Credential=' . $accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $curlHeaders = ['Authorization: ' . $authHeader];
        foreach ($amzHeaders as $hk => $hv) {
            $curlHeaders[] = $this->formatHeaderName($hk) . ': ' . $hv;
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$responseHeaders) {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }
                return $len;
            },
        ]);

        if ($method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('R2 cURL error: ' . $curlErr);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('R2 HTTP ' . $httpCode . ' - ' . $this->truncate((string)$responseBody, 240));
        }

        return [
            'status' => $httpCode,
            'body' => (string)$responseBody,
            'headers' => $responseHeaders,
        ];
    }

    private function canonicalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $item) {
            if ($item === '') {
                continue;
            }
            $kv = explode('=', $item, 2);
            $k = rawurlencode(rawurldecode($kv[0]));
            $v = rawurlencode(rawurldecode($kv[1] ?? ''));
            $pairs[] = $k . '=' . $v;
        }
        sort($pairs, SORT_STRING);
        return implode('&', $pairs);
    }

    private function encodePath(string $path): string
    {
        $parts = array_map(static fn($p) => rawurlencode($p), explode('/', ltrim($path, '/')));
        return implode('/', $parts);
    }

    private function getSigningKey(string $secretKey, string $date, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function formatHeaderName(string $lowerName): string
    {
        return implode('-', array_map('ucfirst', explode('-', $lowerName)));
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim($text);
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max) . '...';
    }
}
