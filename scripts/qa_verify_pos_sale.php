#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

if (!defined('SISTEMAX_V1')) {
    define('SISTEMAX_V1', true);
}
require_once __DIR__ . '/../config/database.php';

$options = getopt('', ['empresa::', 'dbase::', 'factura::', 'limit::', 'method::', 'show-empresas::']);

$idEmpresa = isset($options['empresa']) ? (int)$options['empresa'] : 0;
$dbaseOverride = trim((string)($options['dbase'] ?? ''));
$idFactura = isset($options['factura']) ? (int)$options['factura'] : 0;
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 5;
$method = strtolower(trim((string)($options['method'] ?? '')));

$formaPagoMap = [
    1 => 'efectivo',
    2 => 'tarjeta',
    3 => 'transferencia',
    4 => 'pix',
    5 => 'credito',
];

try {
    $master = Database::getMasterConnection();

    if (array_key_exists('show-empresas', $options)) {
        $stmtList = $master->query('SELECT id_empresa, empresa, dbase, activo FROM empresa ORDER BY id_empresa DESC LIMIT 100');
        $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            echo $r['id_empresa'] . ' | ' . $r['empresa'] . ' | ' . $r['dbase'] . ' | activo=' . $r['activo'] . PHP_EOL;
        }
        exit(0);
    }

    if ($idEmpresa <= 0 && $dbaseOverride === '') {
        fwrite(STDERR, "Uso: php scripts/qa_verify_pos_sale.php --empresa=ID [--dbase=empresa_xxx] [--factura=ID] [--method=tarjeta] [--limit=5] [--show-empresas]\n");
        exit(1);
    }

    $dbase = $dbaseOverride;
    if ($dbase === '') {
        $stmtDb = $master->prepare('SELECT dbase FROM empresa WHERE id_empresa = :id LIMIT 1');
        $stmtDb->execute([':id' => $idEmpresa]);
        $dbase = (string)($stmtDb->fetchColumn() ?: '');
    }

    if ($dbase === '') {
        throw new RuntimeException("No se encontró dbase para empresa {$idEmpresa}");
    }

    if ($idEmpresa > 0) {
        $pdo = Database::getEmpresaConnection($idEmpresa);
    } else {
        $pdo = new PDO(
            "mysql:host=168.231.95.50;port=3306;dbname={$dbase};charset=utf8mb4",
            'sistemax',
            'Armagedon123',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    $hasPagosTable = false;
    $stmtTbl = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :tbl');
    $stmtTbl->execute([':db' => $dbase, ':tbl' => 'factura_ventas_pagos']);
    $hasPagosTable = ((int)$stmtTbl->fetchColumn()) > 0;

    $where = [];
    $params = [];

    if ($idFactura > 0) {
        $where[] = 'id_factura = :id_factura';
        $params[':id_factura'] = $idFactura;
    }

    if ($method !== '') {
        $forma = array_search($method, $formaPagoMap, true);
        if ($forma !== false) {
            $where[] = 'forma_pago = :forma_pago';
            $params[':forma_pago'] = (int)$forma;
        }
    }

    $sql = "SELECT id_factura, nro_factura, fecha, total, forma_pago, estado
            FROM {$dbase}.factura_ventas";
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id_factura DESC LIMIT ' . (int)$limit;

    $stmtFact = $pdo->prepare($sql);
    $stmtFact->execute($params);
    $facturas = $stmtFact->fetchAll(PDO::FETCH_ASSOC);

    if (empty($facturas)) {
        echo "FAIL | No se encontraron facturas con ese filtro\n";
        exit(2);
    }

    echo "Empresa: {$idEmpresa} | DB: {$dbase}\n";
    echo "Facturas encontradas: " . count($facturas) . "\n";
    echo str_repeat('-', 90) . "\n";

    $failCount = 0;

    foreach ($facturas as $f) {
        $fid = (int)$f['id_factura'];
        $fpCode = (int)($f['forma_pago'] ?? 0);
        $fpText = $formaPagoMap[$fpCode] ?? ('codigo_' . $fpCode);

        echo "Factura #{$fid} ({$f['nro_factura']}) | Fecha: {$f['fecha']} | Total: {$f['total']} | Forma: {$fpText}\n";

        if (!$hasPagosTable) {
            echo "  WARN: tabla {$dbase}.factura_ventas_pagos no existe\n";
            continue;
        }

        $stmtPay = $pdo->prepare("SELECT id, metodo, monto, voucher_number, transfer_reference, qr_transaction_code,
                                         card_terminal_reference, card_auth_code, card_nsu, card_rrn, card_batch,
                                         card_brand, card_processor, created_at
                                  FROM {$dbase}.factura_ventas_pagos
                                  WHERE id_factura = :id
                                  ORDER BY id DESC");
        $stmtPay->execute([':id' => $fid]);
        $pagos = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pagos)) {
            echo "  FAIL: sin registros en factura_ventas_pagos\n";
            $failCount++;
            continue;
        }

        foreach ($pagos as $p) {
            $metodo = strtolower(trim((string)$p['metodo']));
            $isOk = true;
            $issues = [];

            if ($metodo === 'tarjeta') {
                $hasRef = trim((string)$p['card_terminal_reference']) !== '' || trim((string)$p['voucher_number']) !== '';
                $hasAuthData = trim((string)$p['card_auth_code']) !== '' || trim((string)$p['card_nsu']) !== '' || trim((string)$p['card_rrn']) !== '';

                if (!$hasRef) {
                    $isOk = false;
                    $issues[] = 'sin referencia de terminal/voucher';
                }
                if (!$hasAuthData) {
                    $isOk = false;
                    $issues[] = 'sin auth_code/nsu/rrn';
                }
            }

            if ($metodo === 'transferencia' && trim((string)$p['transfer_reference']) === '') {
                $isOk = false;
                $issues[] = 'sin transfer_reference';
            }

            if ($metodo === 'pix' && trim((string)$p['qr_transaction_code']) === '') {
                $isOk = false;
                $issues[] = 'sin qr_transaction_code';
            }

            $status = $isOk ? 'OK' : 'FAIL';
            $info = "metodo={$metodo}, monto={$p['monto']}, ref=" . ($p['card_terminal_reference'] ?: $p['voucher_number'] ?: '-') . ", auth=" . ($p['card_auth_code'] ?: '-') . ", nsu=" . ($p['card_nsu'] ?: '-') . ", rrn=" . ($p['card_rrn'] ?: '-') . ", proc=" . ($p['card_processor'] ?: '-');
            echo "  {$status}: {$info}\n";

            if (!$isOk) {
                echo '    - ' . implode('; ', $issues) . "\n";
                $failCount++;
            }
        }
    }

    echo str_repeat('-', 90) . "\n";
    if ($failCount === 0) {
        echo "RESULTADO FINAL: OK (sin inconsistencias detectadas)\n";
        exit(0);
    }

    echo "RESULTADO FINAL: FAIL ({$failCount} inconsistencia/s)\n";
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
