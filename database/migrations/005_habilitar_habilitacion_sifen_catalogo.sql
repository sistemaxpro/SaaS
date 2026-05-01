-- MIGRACION: Habilitar app Habilitacion SIFEN en catalogo con precio mensual
-- Fecha: 2026-02-13

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, icono_svg, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo)
VALUES
    (
        'habilitacion_sifen',
        'Habilitación SIFEN',
        'Configuración de facturación electrónica',
        'empresas/habilitacion_sifen_local.php',
        'fas fa-certificate',
        'assets/images/icons_v2/certificate.svg',
        'green',
        149000.00,
        0,
        NULL,
        'editar_habilitacion_sifen',
        140,
        1
    )
ON DUPLICATE KEY UPDATE
    ruta_app = VALUES(ruta_app),
    precio_mensual = VALUES(precio_mensual),
    icono = VALUES(icono),
    icono_svg = VALUES(icono_svg),
    color = VALUES(color),
    permiso_base = VALUES(permiso_base),
    activo = 1;
