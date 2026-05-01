CREATE TABLE IF NOT EXISTS inventario_traslados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referencia VARCHAR(64) NOT NULL,
    id_producto BIGINT NOT NULL,
    id_sucursal_origen INT NOT NULL,
    id_sucursal_destino INT NOT NULL,
    cantidad DECIMAL(18,4) NOT NULL DEFAULT 0,
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    obs VARCHAR(255) NOT NULL DEFAULT '',
    fecha_salida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_recepcion DATETIME NULL DEFAULT NULL,
    id_login_salida BIGINT NOT NULL DEFAULT 0,
    id_login_recepcion BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_inventario_traslado_ref (referencia),
    KEY idx_inventario_traslado_estado_destino (estado, id_sucursal_destino),
    KEY idx_inventario_traslado_estado_origen (estado, id_sucursal_origen),
    KEY idx_inventario_traslado_producto (id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
