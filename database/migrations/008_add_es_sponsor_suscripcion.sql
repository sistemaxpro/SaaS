-- ============================================================
-- MIGRACION: Agregar campo es_sponsor a saas_suscripcion
-- Fecha: 2026-03-05
-- Ejecutar en Master DB: serproc1
-- ============================================================

ALTER TABLE saas_suscripcion
ADD COLUMN IF NOT EXISTS es_sponsor TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=Cuenta sponsor sin cobro';

ALTER TABLE saas_suscripcion
ADD INDEX IF NOT EXISTS idx_es_sponsor (es_sponsor);

-- Verificacion
-- SHOW COLUMNS FROM saas_suscripcion LIKE 'es_sponsor';
