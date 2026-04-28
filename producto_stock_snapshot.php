<?php

if (!function_exists('sxSnapshotTableExists')) {
    function sxSnapshotTableExists(PDO $pdo, string $db, string $table): bool
    {
        static $cache = [];
        $key = $db . '.' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = :db
                  AND table_name = :table
                LIMIT 1
            ");
            $stmt->execute([
                ':db' => $db,
                ':table' => $table,
            ]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

if (!function_exists('sxSnapshotColumnExists')) {
    function sxSnapshotColumnExists(PDO $pdo, string $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $db . '.' . $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = :db
                  AND table_name = :table
                  AND column_name = :column
                LIMIT 1
            ");
            $stmt->execute([
                ':db' => $db,
                ':table' => $table,
                ':column' => $column,
            ]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

if (!function_exists('sxProductoStockSnapshotReady')) {
    function sxProductoStockSnapshotTable(PDO $pdo, string $db): ?string
    {
        static $cache = [];
        if (array_key_exists($db, $cache)) {
            return $cache[$db];
        }

        foreach (['producto_stock', 'productos_stock'] as $candidate) {
            if (sxSnapshotTableExists($pdo, $db, $candidate)) {
                $cache[$db] = $candidate;
                return $candidate;
            }
        }

        $cache[$db] = null;
        return null;
    }
}

if (!function_exists('sxProductoStockSnapshotReady')) {
    function sxProductoStockSnapshotReady(PDO $pdo, string $db): bool
    {
        static $cache = [];
        if (array_key_exists($db, $cache)) {
            return $cache[$db];
        }

        $table = sxProductoStockSnapshotTable($pdo, $db);
        $cache[$db] = $table !== null
            && sxSnapshotColumnExists($pdo, $db, $table, 'idproducto')
            && sxSnapshotColumnExists($pdo, $db, $table, 'id_sucursal')
            && (
                sxSnapshotColumnExists($pdo, $db, $table, 'stock_disponible')
                || sxSnapshotColumnExists($pdo, $db, $table, 'stock_actual')
            );

        return $cache[$db];
    }
}

if (!function_exists('sxProductoStockSnapshotAdjust')) {
    function sxProductoStockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
    {
        if ($idProducto <= 0 || $idSucursal <= 0 || abs($delta) < 0.000001 || !sxProductoStockSnapshotReady($pdo, $db)) {
            return;
        }

        $table = sxProductoStockSnapshotTable($pdo, $db);
        if ($table === null) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$db}.{$table} (idproducto, id_sucursal, stock_actual, stock_reservado, stock_disponible)
            VALUES (:idproducto, :id_sucursal, :delta_actual, 0, :delta_disponible)
            ON DUPLICATE KEY UPDATE
                stock_actual = COALESCE(stock_actual, 0) + VALUES(stock_actual),
                stock_disponible = COALESCE(stock_disponible, 0) + VALUES(stock_disponible),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':idproducto' => $idProducto,
            ':id_sucursal' => $idSucursal,
            ':delta_actual' => $delta,
            ':delta_disponible' => $delta,
        ]);
    }
}
