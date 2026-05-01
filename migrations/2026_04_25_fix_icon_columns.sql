-- Migración: Corregir estructura de columnas de iconos en saas_apps_catalogo
-- Fecha: 2026-04-25
-- Descripción: Aumentar tamaño de icono_svg y agregar columna icono_source
--
-- Problema: El campo icono_svg estaba limitado a 255 caracteres (varchar),
-- lo que truncaba los SVG completos de las nuevas librerías (Material Symbols, Unicons).
-- Esto causaba que las actualizaciones fallaran silenciosamente con:
-- "Error actualizando app"
--
-- Solución:
-- 1. Cambiar icono_svg de VARCHAR(255) a LONGTEXT para aceptar SVG completos
-- 2. Agregar columna icono_source para rastrear la fuente del icono (heroicon, tabler, huge, material-symbols, unicons)
-- 3. Limpiar datos truncados e repoblar con valores correctos

-- Cambiar icono_svg de varchar(255) a LONGTEXT
ALTER TABLE saas_apps_catalogo
MODIFY COLUMN icono_svg LONGTEXT NULL;

-- Agregar columna icono_source si no existe
ALTER TABLE saas_apps_catalogo
ADD COLUMN icono_source VARCHAR(50) NULL DEFAULT 'heroicon' AFTER icono_svg;

-- Crear índice para búsquedas rápidas
ALTER TABLE saas_apps_catalogo
ADD INDEX idx_icono_source (icono_source);

-- Actualizar datos truncados (255 chars) a NULL para repoblar desde backfill script
UPDATE saas_apps_catalogo
SET icono_svg = NULL
WHERE LENGTH(COALESCE(icono_svg, '')) = 255;

-- Ejecutar después: php scripts/backfill_catalog_icon_svg.php
