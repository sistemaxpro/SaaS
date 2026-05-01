-- Ejecutar sobre la base empresa deseada.
-- Ejemplo:
   USE empresa_118;
   SOURCE database/migrations/019_migrate_tblproductos_to_productos_equivalencias.sql;

CREATE TABLE IF NOT EXISTS `productos_equivalencias` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `idproducto` INT NOT NULL,
    `marca_cod_conversion` VARCHAR(120) NULL DEFAULT NULL,
    `conversion` VARCHAR(120) NULL DEFAULT NULL,
    `orden` INT NOT NULL DEFAULT 1,
    `id_login` INT NULL DEFAULT NULL,
    `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_producto_orden` (`idproducto`, `orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preview de filas candidatas.
SELECT
    p.idproducto,
    TRIM(COALESCE(p.cve_producto, '')) AS cve_producto,
    TRIM(COALESCE(p.referencia, '')) AS referencia,
    TRIM(COALESCE(m.marca, '')) AS marca_cod_conversion
FROM tblproductos p
LEFT JOIN mercaderia_marca m ON m.id = p.marca
LEFT JOIN productos_equivalencias e
    ON e.idproducto = p.idproducto
   AND TRIM(COALESCE(e.conversion, '')) = TRIM(COALESCE(p.referencia, ''))
   AND TRIM(COALESCE(e.marca_cod_conversion, '')) = TRIM(COALESCE(m.marca, ''))
WHERE NULLIF(TRIM(COALESCE(p.referencia, '')), '') IS NOT NULL
  AND e.id IS NULL
ORDER BY p.idproducto;

-- Aplicación de migración.
INSERT INTO productos_equivalencias
    (idproducto, marca_cod_conversion, conversion, orden, id_login)
SELECT
    p.idproducto,
    NULLIF(TRIM(COALESCE(m.marca, '')), '') AS marca_cod_conversion,
    TRIM(COALESCE(p.referencia, '')) AS conversion,
    1 AS orden,
    NULL AS id_login
FROM tblproductos p
LEFT JOIN mercaderia_marca m ON m.id = p.marca
LEFT JOIN productos_equivalencias e
    ON e.idproducto = p.idproducto
   AND TRIM(COALESCE(e.conversion, '')) = TRIM(COALESCE(p.referencia, ''))
   AND TRIM(COALESCE(e.marca_cod_conversion, '')) = TRIM(COALESCE(m.marca, ''))
WHERE NULLIF(TRIM(COALESCE(p.referencia, '')), '') IS NOT NULL
  AND e.id IS NULL;
