UPDATE saas_apps_catalogo
SET
    nombre = 'Instalar Geolocalizador',
    descripcion = 'Instalacion guiada del agente geolocalizador para telefono o tablet Android',
    ruta_app = 'public/apps-moviles.php?external=android',
    icono = 'device-phone-mobile',
    color = 'emerald',
    precio_mensual = 0,
    obligatoria = 0,
    activo = 1,
    en_desarrollo = 0,
    modulo = 'POS',
    negocio = 'General',
    permiso_base = 'app_grid_instalar_android',
    orden = 16
WHERE codigo = 'instalar_android';
