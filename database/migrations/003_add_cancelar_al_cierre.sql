-- ============================================================
-- MIGRACIÓN: Agregar campo cancelar_al_cierre
-- Para permitir marcar apps para cancelación diferida (al cierre de factura)
-- Ejecutar en Master DB: serproc1
-- ============================================================

-- Agregar campo a saas_suscripcion_apps (items de suscripción)
ALTER TABLE saas_suscripcion_apps 
ADD COLUMN IF NOT EXISTS cancelar_al_cierre TINYINT(1) DEFAULT 0 
    COMMENT '1=Marcada para cancelar al final del período de facturación',
ADD COLUMN IF NOT EXISTS fecha_solicitud_cancelacion DATETIME DEFAULT NULL 
    COMMENT 'Fecha en que se solicitó la cancelación';

-- Agregar índice para consultas de cancelaciones pendientes
ALTER TABLE saas_suscripcion_apps 
ADD INDEX IF NOT EXISTS idx_cancelar_al_cierre (cancelar_al_cierre);

-- También agregar a saas_suscripcion_apps si existe (modelo de factura mensual)
-- ALTER TABLE saas_suscripcion_apps 
-- ADD COLUMN IF NOT EXISTS cancelar_al_cierre TINYINT(1) DEFAULT 0 
--     COMMENT '1=Marcada para cancelar al final del período',
-- ADD COLUMN IF NOT EXISTS fecha_solicitud_cancelacion DATETIME DEFAULT NULL;

-- ============================================================
-- Script de verificación
-- ============================================================
-- SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT 
-- FROM information_schema.COLUMNS 
-- WHERE TABLE_NAME = 'saas_suscripciones' 
-- AND COLUMN_NAME IN ('cancelar_al_cierre', 'fecha_solicitud_cancelacion');
