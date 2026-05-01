-- MIGRACION: Esquema normalizado de tipos de negocio y relación con apps
-- Fecha: 2026-03-08

CREATE TABLE IF NOT EXISTS tipo_negocio (
    id INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(120) NOT NULL,
    orden INT NOT NULL DEFAULT 100,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_nombre (nombre),
    KEY idx_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tipo_negocio_app (
    id INT NOT NULL AUTO_INCREMENT,
    tipo_negocio_id INT NOT NULL,
    id_app INT NOT NULL,
    orden INT NOT NULL DEFAULT 100,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tipo_app (tipo_negocio_id, id_app),
    KEY idx_tipo (tipo_negocio_id),
    KEY idx_app (id_app),
    CONSTRAINT fk_tipo_negocio_app_tipo
        FOREIGN KEY (tipo_negocio_id) REFERENCES tipo_negocio(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipos base (idempotente)
INSERT IGNORE INTO tipo_negocio (nombre, orden, activo)
VALUES
    ('Comercial', 10, 1),
    ('Estación de Servicio', 20, 1),
    ('Sistema', 30, 1);

-- Hidratar tipos faltantes desde catálogo
INSERT IGNORE INTO tipo_negocio (nombre, orden, activo)
SELECT DISTINCT COALESCE(NULLIF(TRIM(c.negocio), ''), 'Comercial') AS nombre,
       100 AS orden,
       1 AS activo
FROM saas_apps_catalogo c;
