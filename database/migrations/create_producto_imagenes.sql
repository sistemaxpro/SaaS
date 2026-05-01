-- ═══════════════════════════════════════════════════════════════════════
-- Migración: Crear tabla producto_imagenes (Google Drive)
-- Se ejecuta automáticamente vía schema_module_compat.php
-- Este archivo es solo referencia/documentación.
-- ═══════════════════════════════════════════════════════════════════════

-- 1. Agregar columna foto_url a tblproductos (si no existe)
ALTER TABLE tblproductos ADD COLUMN IF NOT EXISTS foto_url VARCHAR(500) NULL;

-- 2. Crear tabla de imágenes de productos
CREATE TABLE IF NOT EXISTS producto_imagenes (
    id INT NOT NULL AUTO_INCREMENT,
    idproducto INT NOT NULL,
    drive_file_id VARCHAR(100) NOT NULL COMMENT 'ID del archivo en Google Drive',
    url VARCHAR(500) NOT NULL COMMENT 'URL pública de la imagen',
    filename VARCHAR(255) NULL COMMENT 'Nombre del archivo en Drive (ej: 123_1.webp)',
    orden INT NOT NULL DEFAULT 1 COMMENT 'Orden de la imagen para el producto',
    principal TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=imagen principal del producto',
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_producto (idproducto),
    INDEX idx_drive_file (drive_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTA: Esta tabla se crea en la base de datos de CADA empresa
-- (multi-tenant), no en la base master.
--
-- Estructura de carpetas en Google Drive:
--   {carpeta_raíz}/
--     └── {nombre_bd_empresa}/
--          └── productos/
--               ├── {idproducto}_1.webp   ← imagen principal
--               ├── {idproducto}_2.webp   ← imagen secundaria
--               └── ...
