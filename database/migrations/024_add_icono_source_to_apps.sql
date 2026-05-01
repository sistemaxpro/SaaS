-- Agregar columna icono_source para distinguir entre Heroicons, Tabler Icons y Huge Icons
ALTER TABLE saas_apps_catalogo
ADD COLUMN icono_source VARCHAR(20) DEFAULT 'heroicon'
COMMENT 'Fuente del icono: heroicon, tabler, huge'
AFTER icono;

-- Crear índice para búsquedas rápidas
CREATE INDEX idx_icono_source ON saas_apps_catalogo(icono_source);
