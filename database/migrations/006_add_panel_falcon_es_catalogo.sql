-- MIGRACION: Agregar Panel Falcon (ES) al catalogo de apps SaaS
-- Fecha: 2026-02-15

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, icono_svg, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo)
VALUES
    (
        'panel_falcon_es',
        'Panel Comercial',
        'Dashboard comercial en espanol con KPIs, graficos y tabla de compras',
        'public/panel/index.php',
        'fas fa-chart-line',
        'assets/images/icons_v2/dashboard.svg',
        'blue',
        0.00,
        0,
        NULL,
        NULL,
        131,
        1
    )
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    ruta_app = VALUES(ruta_app),
    icono = VALUES(icono),
    icono_svg = VALUES(icono_svg),
    color = VALUES(color),
    precio_mensual = VALUES(precio_mensual),
    orden = VALUES(orden),
    activo = 1;
