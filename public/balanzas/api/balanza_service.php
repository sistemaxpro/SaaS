<?php

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../../../../config/bootstrap.php';
}

function smxEnsureBalanzasTable(PDO $pdo, string $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS {$db}.balanzas (
        id_balanza INT NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        nombre_modelo VARCHAR(100) NOT NULL,
        prefijo VARCHAR(10) NOT NULL,
        longitud_codigo INT NOT NULL DEFAULT 13,
        pos_inicio_producto INT NOT NULL DEFAULT 3,
        largo_producto INT NOT NULL DEFAULT 5,
        pos_inicio_valor INT NOT NULL DEFAULT 8,
        largo_valor INT NOT NULL DEFAULT 5,
        divisor_valor DECIMAL(12,4) NOT NULL DEFAULT 1000,
        modo VARCHAR(20) NOT NULL DEFAULT 'PESO',
        activo TINYINT(1) NOT NULL DEFAULT 1,
        observacion VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id_balanza),
        KEY idx_empresa_activo (id_empresa, activo),
        KEY idx_prefijo (prefijo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);

    // Compatibilidad con instalaciones antiguas: agregar columnas faltantes.
    $requiredColumns = [
        'id_empresa' => "ALTER TABLE {$db}.balanzas ADD COLUMN id_empresa INT NOT NULL DEFAULT 0 AFTER id_balanza",
        'nombre_modelo' => "ALTER TABLE {$db}.balanzas ADD COLUMN nombre_modelo VARCHAR(100) NOT NULL DEFAULT '' AFTER id_empresa",
        'prefijo' => "ALTER TABLE {$db}.balanzas ADD COLUMN prefijo VARCHAR(10) NOT NULL DEFAULT '' AFTER nombre_modelo",
        'longitud_codigo' => "ALTER TABLE {$db}.balanzas ADD COLUMN longitud_codigo INT NOT NULL DEFAULT 13 AFTER prefijo",
        'pos_inicio_producto' => "ALTER TABLE {$db}.balanzas ADD COLUMN pos_inicio_producto INT NOT NULL DEFAULT 3 AFTER longitud_codigo",
        'largo_producto' => "ALTER TABLE {$db}.balanzas ADD COLUMN largo_producto INT NOT NULL DEFAULT 5 AFTER pos_inicio_producto",
        'pos_inicio_valor' => "ALTER TABLE {$db}.balanzas ADD COLUMN pos_inicio_valor INT NOT NULL DEFAULT 8 AFTER largo_producto",
        'largo_valor' => "ALTER TABLE {$db}.balanzas ADD COLUMN largo_valor INT NOT NULL DEFAULT 5 AFTER pos_inicio_valor",
        'divisor_valor' => "ALTER TABLE {$db}.balanzas ADD COLUMN divisor_valor DECIMAL(12,4) NOT NULL DEFAULT 1000 AFTER largo_valor",
        'modo' => "ALTER TABLE {$db}.balanzas ADD COLUMN modo VARCHAR(20) NOT NULL DEFAULT 'PESO' AFTER divisor_valor",
        'activo' => "ALTER TABLE {$db}.balanzas ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER modo",
        'observacion' => "ALTER TABLE {$db}.balanzas ADD COLUMN observacion VARCHAR(255) NULL AFTER activo",
        'created_at' => "ALTER TABLE {$db}.balanzas ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER observacion",
        'updated_at' => "ALTER TABLE {$db}.balanzas ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($requiredColumns as $col => $alterSql) {
        $check = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME = 'balanzas'
              AND COLUMN_NAME = :col
            LIMIT 1
        ");
        $check->execute([':db' => $db, ':col' => $col]);
        if (!$check->fetchColumn()) {
            $pdo->exec($alterSql);
        }
    }
}

function smxNormalizeBalanzaMode($raw): string
{
    $m = strtoupper(trim((string)$raw));
    if ($m === 'PRECIO') return 'PRECIO';
    if ($m === 'CANTIDAD') return 'CANTIDAD';
    return 'PESO';
}

function smxResolveBalanzaProduct(PDO $pdo, string $db, string $rawProd, string $trimmedProd): array
{
    $idProducto = $trimmedProd !== '' ? $trimmedProd : '0';
    $codigoProducto = $rawProd !== '' ? $rawProd : $idProducto;
    $barcodeEncontrado = '';
    $producto = null;
    $precio = null;
    $resueltoPorCodigoBarra = false;

    // 1) Buscar por codigo_barra (exacto y normalizado sin ceros a la izquierda).
    try {
        $bn = ltrim($trimmedProd, '0') === '' ? '0' : ltrim($trimmedProd, '0');
        $bnum = (int)($trimmedProd === '' ? 0 : $trimmedProd);
        $stmtCb = $pdo->prepare("
            SELECT id_producto, codigo_barra
            FROM {$db}.codigo_barra
            WHERE codigo_barra = ?
               OR codigo_barra = ?
               OR TRIM(LEADING '0' FROM codigo_barra) = ?
               OR (codigo_barra REGEXP '^[0-9]+$' AND CAST(codigo_barra AS UNSIGNED) = ?)
            ORDER BY
                CASE
                    WHEN codigo_barra = ? THEN 0
                    WHEN codigo_barra = ? THEN 1
                    WHEN TRIM(LEADING '0' FROM codigo_barra) = ? THEN 2
                    WHEN (codigo_barra REGEXP '^[0-9]+$' AND CAST(codigo_barra AS UNSIGNED) = ?) THEN 3
                    ELSE 4
                END
            LIMIT 1
        ");
        $stmtCb->execute([
            $rawProd, $trimmedProd, $bn, $bnum,
            $rawProd, $trimmedProd, $bn, $bnum
        ]);
        $cb = $stmtCb->fetch(PDO::FETCH_ASSOC);
        if ($cb) {
            if (!empty($cb['id_producto'])) {
                $idProducto = (string)$cb['id_producto'];
                $resueltoPorCodigoBarra = true;
            }
            $barcodeEncontrado = (string)($cb['codigo_barra'] ?? '');
        }
    } catch (Exception $e) {
        // Compatibilidad: algunas instalaciones usan columna idproducto en codigo_barra.
        try {
            $bn = ltrim($trimmedProd, '0') === '' ? '0' : ltrim($trimmedProd, '0');
            $bnum = (int)($trimmedProd === '' ? 0 : $trimmedProd);
            $stmtCb2 = $pdo->prepare("
                SELECT idproducto AS id_producto, codigo_barra
                FROM {$db}.codigo_barra
                WHERE codigo_barra = ?
                   OR codigo_barra = ?
                   OR TRIM(LEADING '0' FROM codigo_barra) = ?
                   OR (codigo_barra REGEXP '^[0-9]+$' AND CAST(codigo_barra AS UNSIGNED) = ?)
                ORDER BY
                    CASE
                        WHEN codigo_barra = ? THEN 0
                        WHEN codigo_barra = ? THEN 1
                        WHEN TRIM(LEADING '0' FROM codigo_barra) = ? THEN 2
                        WHEN (codigo_barra REGEXP '^[0-9]+$' AND CAST(codigo_barra AS UNSIGNED) = ?) THEN 3
                        ELSE 4
                    END
                LIMIT 1
            ");
            $stmtCb2->execute([
                $rawProd, $trimmedProd, $bn, $bnum,
                $rawProd, $trimmedProd, $bn, $bnum
            ]);
            $cb = $stmtCb2->fetch(PDO::FETCH_ASSOC);
            if ($cb) {
                if (!empty($cb['id_producto'])) {
                    $idProducto = (string)$cb['id_producto'];
                    $resueltoPorCodigoBarra = true;
                }
                $barcodeEncontrado = (string)($cb['codigo_barra'] ?? '');
            }
        } catch (Exception $e2) {
            // ignore
        }
    }

    // Compatibilidad: algunas empresas guardan código de barra directamente en tblproductos.
    if (!$resueltoPorCodigoBarra) {
        try {
            $bn = ltrim($trimmedProd, '0') === '' ? '0' : ltrim($trimmedProd, '0');
            $stmtDirect = $pdo->prepare("
                SELECT *
                FROM {$db}.tblproductos
                WHERE codigo_barra = ?
                   OR codigo_barra = ?
                   OR TRIM(LEADING '0' FROM codigo_barra) = ?
                ORDER BY
                    CASE
                        WHEN codigo_barra = ? THEN 0
                        WHEN codigo_barra = ? THEN 1
                        WHEN TRIM(LEADING '0' FROM codigo_barra) = ? THEN 2
                        ELSE 3
                    END
                LIMIT 1
            ");
            $stmtDirect->execute([$rawProd, $trimmedProd, $bn, $rawProd, $trimmedProd, $bn]);
            $prodDirect = $stmtDirect->fetch(PDO::FETCH_ASSOC);
            if ($prodDirect) {
                $producto = $prodDirect;
                $idProducto = (string)($prodDirect['idproducto'] ?? $idProducto);
                $codigoProducto = (string)($prodDirect['cve_producto'] ?? $codigoProducto);
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    // 2) Si hubo match en codigo_barra, obtener producto por idproducto.
    if ($resueltoPorCodigoBarra) {
        try {
            $stmtP = $pdo->prepare("
                SELECT *
                FROM {$db}.tblproductos
                WHERE idproducto = :id
                LIMIT 1
            ");
            $stmtP->execute([':id' => (int)$idProducto]);
            $producto = $stmtP->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($producto) {
                $idProducto = (string)($producto['idproducto'] ?? $idProducto);
                $codigoProducto = (string)($producto['cve_producto'] ?? $codigoProducto);
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    // 3) Fallback: buscar por id/código interno si no se resolvió por codigo_barra.
    if (!$producto) {
        try {
            $stmtP = $pdo->prepare("
                SELECT *
                FROM {$db}.tblproductos
                WHERE (idproducto = ? OR cve_producto = ? OR cve_producto = ?)
                ORDER BY
                    CASE
                        WHEN cve_producto = ? THEN 0
                        WHEN cve_producto = ? THEN 1
                        WHEN idproducto = ? THEN 2
                        ELSE 3
                    END
                LIMIT 1
            ");
            $stmtP->execute([
                (int)$idProducto, $trimmedProd, $rawProd,
                $rawProd, $trimmedProd, (int)$idProducto
            ]);
            $producto = $stmtP->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($producto) {
                $idProducto = (string)($producto['idproducto'] ?? $idProducto);
                $codigoProducto = (string)($producto['cve_producto'] ?? $codigoProducto);
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    // 4) Precio desde mercaderia_precio (compatibilidad con diferentes esquemas).
    $precioKeys = array_values(array_unique(array_filter([
        $idProducto,
        $codigoProducto,
        $trimmedProd,
        $rawProd
    ], fn($v) => (string)$v !== '')));

    if (!empty($precioKeys)) {
        $ph = [];
        $bind = [':t' => 1];
        foreach ($precioKeys as $i => $k) {
            $key = ':k' . $i;
            $ph[] = $key;
            $bind[$key] = $k;
        }

        try {
            $stmtPrecio = $pdo->prepare("
                SELECT codigo, tipo, precio
                FROM {$db}.mercaderia_precio
                WHERE tipo = :t
                  AND codigo IN (" . implode(', ', $ph) . ")
                ORDER BY
                    CASE
                        WHEN codigo = :k0 THEN 0
                        ELSE 1
                    END
                LIMIT 1
            ");
            $stmtPrecio->execute($bind);
            $r = $stmtPrecio->fetch(PDO::FETCH_ASSOC);
            if ($r && isset($r['precio'])) {
                $precio = (float)$r['precio'];
            }
        } catch (Exception $e) {
            try {
                $stmtPrecio = $pdo->prepare("
                    SELECT codigo, id_tipo_precio, precio
                    FROM {$db}.mercaderia_precio
                    WHERE id_tipo_precio = :t
                      AND codigo IN (" . implode(', ', $ph) . ")
                    ORDER BY
                        CASE
                            WHEN codigo = :k0 THEN 0
                            ELSE 1
                        END
                    LIMIT 1
                ");
                $stmtPrecio->execute($bind);
                $r = $stmtPrecio->fetch(PDO::FETCH_ASSOC);
                if ($r && isset($r['precio'])) {
                    $precio = (float)$r['precio'];
                }
            } catch (Exception $e2) {
                // ignore
            }
        }
    }

    return [
        'idproducto' => (int)$idProducto,
        'codigo_producto' => (string)$codigoProducto,
        'descripcion' => (string)($producto['desproducto'] ?? ''),
        'codigo_barra' => $barcodeEncontrado !== '' ? $barcodeEncontrado : $rawProd,
        'precio' => $precio,
        'producto' => is_array($producto) ? $producto : null,
        'resuelto_por_codigo_barra' => $resueltoPorCodigoBarra,
    ];
}

function smxDecodeBalanzaFromConfig(PDO $pdo, string $db, array $cfg, string $codigo): array
{
    $codigoLimpio = preg_replace('/\D+/', '', (string)$codigo);
    if ($codigoLimpio === '') {
        return ['es_balanza' => false, 'error' => 'Código vacío'];
    }

    $longitud = (int)($cfg['longitud_codigo'] ?? 0);
    $prefijoCfg = (string)($cfg['prefijo'] ?? '');

    // Tolerancia para lectores que envían 1 dígito extra (p.ej. GTIN-14 con cero inicial)
    if ($longitud > 0 && strlen($codigoLimpio) !== $longitud) {
        $lenActual = strlen($codigoLimpio);

        if ($lenActual === $longitud + 1 && substr($codigoLimpio, 0, 1) === '0') {
            $codigoLimpio = substr($codigoLimpio, 1);
        } elseif ($lenActual > $longitud && $prefijoCfg !== '' && str_starts_with($codigoLimpio, $prefijoCfg)) {
            $codigoLimpio = substr($codigoLimpio, 0, $longitud);
        } elseif ($lenActual > $longitud) {
            // Último fallback: quedarse con los últimos N dígitos
            $codigoLimpio = substr($codigoLimpio, -$longitud);
        }
    }

    if ($longitud > 0 && strlen($codigoLimpio) !== $longitud) {
        return [
            'es_balanza' => false,
            'error' => "Longitud inválida. Esperado {$longitud}",
            'codigo' => $codigoLimpio
        ];
    }

    $startProd = max(0, ((int)($cfg['pos_inicio_producto'] ?? 3)) - 1);
    $lenProd = max(1, (int)($cfg['largo_producto'] ?? 5));
    $startVal = max(0, ((int)($cfg['pos_inicio_valor'] ?? 8)) - 1);
    $lenVal = max(1, (int)($cfg['largo_valor'] ?? 5));

    $rawProd = substr($codigoLimpio, $startProd, $lenProd);
    $rawVal = substr($codigoLimpio, $startVal, $lenVal);
    if ($rawProd === '' || $rawVal === '') {
        return ['es_balanza' => false, 'error' => 'No se pudo extraer producto/valor', 'codigo' => $codigoLimpio];
    }

    $idProducto = ltrim($rawProd, '0');
    if ($idProducto === '') $idProducto = '0';
    $productoResuelto = smxResolveBalanzaProduct($pdo, $db, $rawProd, $idProducto);

    $divisor = (float)($cfg['divisor_valor'] ?? 1);
    if ($divisor <= 0) $divisor = 1;
    $valorNum = (float)preg_replace('/\D+/', '', $rawVal);
    $valor = $valorNum / $divisor;
    $modo = smxNormalizeBalanzaMode($cfg['modo'] ?? 'PESO');

    return [
        'es_balanza' => true,
        'id_balanza' => (int)($cfg['id_balanza'] ?? 0),
        'balanza' => (string)($cfg['nombre_modelo'] ?? 'Balanza'),
        'prefijo' => (string)($cfg['prefijo'] ?? ''),
        'codigo' => $codigoLimpio,
        'idproducto' => (int)($productoResuelto['idproducto'] ?? $idProducto),
        'codigo_producto' => (string)($productoResuelto['codigo_producto'] ?? $rawProd),
        'descripcion' => (string)($productoResuelto['descripcion'] ?? ''),
        'codigo_barra' => (string)($productoResuelto['codigo_barra'] ?? $rawProd),
        'precio_referencia' => $productoResuelto['precio'] ?? null,
        'producto' => $productoResuelto['producto'] ?? null,
        'resuelto_por_codigo_barra' => !empty($productoResuelto['resuelto_por_codigo_barra']),
        'modo' => $modo,
        'valor' => (float)$valor,
        'raw_producto' => $rawProd,
        'raw_valor' => $rawVal,
    ];
}

function smxDecodeBalanza(PDO $pdo, string $db, int $idEmpresa, string $codigo): array
{
    $codigoLimpio = preg_replace('/\D+/', '', (string)$codigo);
    if ($codigoLimpio === '') {
        return ['es_balanza' => false, 'error' => 'Código vacío'];
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM {$db}.balanzas
        WHERE (id_empresa = :id_empresa OR id_empresa = 0)
          AND activo = 1
          AND prefijo IS NOT NULL
          AND prefijo <> ''
          AND :codigo LIKE CONCAT(prefijo, '%')
        ORDER BY LENGTH(prefijo) DESC
        LIMIT 1
    ");
    $stmt->execute([':id_empresa' => $idEmpresa, ':codigo' => $codigoLimpio]);
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cfg) {
        return ['es_balanza' => false, 'codigo' => $codigoLimpio];
    }

    return smxDecodeBalanzaFromConfig($pdo, $db, $cfg, $codigoLimpio);
}
