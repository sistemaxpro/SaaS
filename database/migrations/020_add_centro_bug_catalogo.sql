-- MIGRACION: Agregar app Centro de Bug al catalogo SaaS

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
SELECT
    'centro_bug',
    'Centro de Bug',
    'Central de reportes y seguimiento de bugs enviados por usuarios y soporte',
    'public/devbugs/index.php',
    'shield-exclamation',
    'red',
    0,
    0,
    NULL,
    'centro_bug',
    22,
    1,
    0,
    'Soporte',
    'General'
WHERE NOT EXISTS (
    SELECT 1
    FROM saas_apps_catalogo
    WHERE codigo = 'centro_bug'
);

UPDATE saas_apps_catalogo
SET
    nombre = 'Centro de Bug',
    descripcion = 'Central de reportes y seguimiento de bugs enviados por usuarios y soporte',
    ruta_app = 'public/devbugs/index.php',
    icono = 'shield-exclamation',
    color = 'red',
    precio_mensual = 0,
    obligatoria = 0,
    permiso_base = 'centro_bug',
    orden = 22,
    activo = 1,
    en_desarrollo = 0,
    modulo = 'Soporte',
    negocio = 'General'
WHERE codigo = 'centro_bug';
