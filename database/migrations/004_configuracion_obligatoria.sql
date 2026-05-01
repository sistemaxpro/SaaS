-- MIGRACION: Configuracion como app obligatoria y ruta nativa
-- Fecha: 2026-02-13

UPDATE saas_apps_catalogo
SET
    ruta_app = 'public/empresa/index.php',
    obligatoria = 1,
    icono = 'fas fa-cog',
    activo = 1
WHERE codigo = 'configuracion';

