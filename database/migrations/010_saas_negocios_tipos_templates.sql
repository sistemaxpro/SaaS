-- MIGRACION: Tipos de negocio + plantillas de apps por negocio
-- Fecha: 2026-03-08

CREATE TABLE IF NOT EXISTS saas_negocios_tipos (
    id INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(120) NOT NULL,
    orden INT NOT NULL DEFAULT 100,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_nombre (nombre),
    KEY idx_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_negocio_templates (
    id INT NOT NULL AUTO_INCREMENT,
    negocio VARCHAR(120) NOT NULL,
    id_app INT NOT NULL,
    orden INT NOT NULL DEFAULT 100,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_negocio_app (negocio, id_app),
    KEY idx_negocio (negocio),
    KEY idx_app (id_app)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipos base (idempotente)
INSERT IGNORE INTO saas_negocios_tipos (nombre, orden, activo)
VALUES
    ('Comercial', 10, 1),
    ('Estación de Servicio', 20, 1),
    ('Sistema', 30, 1);

-- Hidratar tipos de negocio existentes desde catálogo
INSERT IGNORE INTO saas_negocios_tipos (nombre, orden, activo)
SELECT DISTINCT COALESCE(NULLIF(TRIM(negocio), ''), 'Comercial') AS nombre,
       100 AS orden,
       1 AS activo
FROM saas_apps_catalogo;

