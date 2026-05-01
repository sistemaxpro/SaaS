-- ============================================================
-- MIGRACION: Apuntar la app Productos al frontend legacy
-- Ejecutar en Master DB: serproc1
-- ============================================================

UPDATE saas_apps_catalogo
SET
    nombre = 'Productos',
    descripcion = 'Catálogo de productos y mercaderías',
    ruta_app = 'public/productos/legacy_index.php',
    icono = 'fas fa-boxes',
    icono_svg = 'assets/images/icons_v2/boxes.svg',
    color = 'cyan',
    precio_mensual = 50000.00,
    obligatoria = 0,
    requiere_modulo = NULL,
    permiso_base = 'app_grid_mercaderias',
    orden = 50,
    activo = 1
WHERE codigo = 'productos';

SELECT codigo, nombre, ruta_app
FROM saas_apps_catalogo
WHERE codigo = 'productos';
