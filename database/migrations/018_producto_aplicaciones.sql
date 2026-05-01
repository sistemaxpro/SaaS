-- MIGRACION: aplicaciones multiples por producto para autorrepuestos
-- Fecha: 2026-04-14

CREATE TABLE IF NOT EXISTS producto_aplicaciones (
    id INT NOT NULL AUTO_INCREMENT,
    idproducto INT NOT NULL,
    conversion VARCHAR(120) NULL,
    vehiculo_marca VARCHAR(120) NULL,
    vehiculo_modelo VARCHAR(120) NULL,
    anio VARCHAR(40) NULL,
    motor VARCHAR(120) NULL,
    codigo_motor VARCHAR(120) NULL,
    orden INT NOT NULL DEFAULT 1,
    id_login INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_producto_orden (idproducto, orden),
    KEY idx_producto_marca_modelo (idproducto, vehiculo_marca, vehiculo_modelo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
