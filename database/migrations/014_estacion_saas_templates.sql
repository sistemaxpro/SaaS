-- Vinculación de apps de estación de servicio con el tipo de negocio
-- Ejecutar en Master DB: serproc1

-- Asegurar que exista la tabla saas_negocio_templates
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

-- Vincular las 6 apps de estación con el tipo de negocio "Estación de Servicio"
INSERT IGNORE INTO saas_negocio_templates (negocio, id_app, orden, activo)
SELECT
    'Estación de Servicio',
    sac.id_app,
    sac.orden,
    sac.activo
FROM saas_apps_catalogo sac
WHERE sac.codigo IN (
    'estacion',
    'estacion_surtidores',
    'estacion_tanques',
    'estacion_turnos',
    'estacion_despachos',
    'estacion_cierres'
);
