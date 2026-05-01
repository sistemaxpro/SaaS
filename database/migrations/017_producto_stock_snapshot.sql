CREATE TABLE IF NOT EXISTS producto_stock (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idproducto INT NOT NULL,
    id_sucursal INT NOT NULL,
    stock_actual DECIMAL(18,4) NOT NULL DEFAULT 0,
    stock_reservado DECIMAL(18,4) NOT NULL DEFAULT 0,
    stock_disponible DECIMAL(18,4) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_producto_stock_producto_sucursal (idproducto, id_sucursal),
    KEY idx_producto_stock_sucursal_producto (id_sucursal, idproducto),
    KEY idx_producto_stock_producto (idproducto)
);

SET @default_sucursal_producto_stock := (
    SELECT COALESCE(MIN(s.id_sucursal), 1)
    FROM sucursales s
);

INSERT INTO producto_stock (idproducto, id_sucursal, stock_actual, stock_reservado, stock_disponible)
SELECT
    ep.idproducto,
    CASE
        WHEN COALESCE(ep.id_sucursal, 0) > 0 THEN ep.id_sucursal
        ELSE @default_sucursal_producto_stock
    END AS id_sucursal,
    SUM(COALESCE(ep.entrada, 0) - COALESCE(ep.salida, 0)) AS stock_actual,
    0 AS stock_reservado,
    SUM(COALESCE(ep.entrada, 0) - COALESCE(ep.salida, 0)) AS stock_disponible
FROM extracto_productos ep
WHERE ep.estado = 1
GROUP BY ep.idproducto,
    CASE
        WHEN COALESCE(ep.id_sucursal, 0) > 0 THEN ep.id_sucursal
        ELSE @default_sucursal_producto_stock
    END
ON DUPLICATE KEY UPDATE
    stock_actual = VALUES(stock_actual),
    stock_disponible = VALUES(stock_disponible),
    updated_at = CURRENT_TIMESTAMP;

UPDATE producto_stock
SET id_sucursal = @default_sucursal_producto_stock
WHERE COALESCE(id_sucursal, 0) <= 0;
