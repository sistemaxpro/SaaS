-- MIGRACION: Agregar app Central de Apps al catalogo SaaS

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
SELECT
    'central_apps',
    'Central de Apps',
    'Panel central para administrar catálogo de apps y suscripciones',
    'public/suscripciones.php',
    'squares-plus',
    'indigo',
    0,
    0,
    NULL,
    'suscripciones',
    23,
    1,
    0,
    'Soporte',
    'General'
WHERE NOT EXISTS (
    SELECT 1
    FROM saas_apps_catalogo
    WHERE codigo = 'central_apps'
);

UPDATE saas_apps_catalogo
SET
    nombre = 'Central de Apps',
    descripcion = 'Panel central para administrar catálogo de apps y suscripciones',
    ruta_app = 'public/suscripciones.php',
    icono = 'squares-plus',
    color = 'indigo',
    precio_mensual = 0,
    obligatoria = 0,
    permiso_base = 'suscripciones',
    orden = 23,
    activo = 1,
    en_desarrollo = 0,
    modulo = 'Soporte',
    negocio = 'General'
WHERE codigo = 'central_apps';
