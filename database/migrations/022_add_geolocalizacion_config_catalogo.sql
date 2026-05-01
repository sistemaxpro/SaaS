-- MIGRACION: Agregar app Configuracion Geolocalizacion al catalogo SaaS

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
SELECT
    'geolocalizacion_config',
    'Configuración Geolocalización',
    'Configura dispositivos, tracking y tokens del módulo de geolocalización móvil',
    'public/geolocalizacion-config/index.php',
    'map-pin',
    'emerald',
    0,
    0,
    NULL,
    'geolocalizacion_config',
    24,
    1,
    0,
    'Soporte',
    'General'
WHERE NOT EXISTS (
    SELECT 1
    FROM saas_apps_catalogo
    WHERE codigo = 'geolocalizacion_config'
);

UPDATE saas_apps_catalogo
SET
    nombre = 'Configuración Geolocalización',
    descripcion = 'Configura dispositivos, tracking y tokens del módulo de geolocalización móvil',
    ruta_app = 'public/geolocalizacion-config/index.php',
    icono = 'map-pin',
    color = 'emerald',
    precio_mensual = 0,
    obligatoria = 0,
    permiso_base = 'geolocalizacion_config',
    orden = 24,
    activo = 1,
    en_desarrollo = 0,
    modulo = 'Soporte',
    negocio = 'General'
WHERE codigo = 'geolocalizacion_config';
