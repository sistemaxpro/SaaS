-- MIGRACION: Agregar Dashboard Estado FE al catalogo SaaS
-- Fecha: 2026-02-19

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, icono_svg, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo)
VALUES
    (
        'estado_fe',
        'Estado FE',
        'Dashboard de estado de facturas electronicas, cola y reprocesamiento',
        'public/empresa/fe_dashboard.php',
        'fas fa-chart-line',
        'assets/images/icons_v2/dashboard.svg',
        'blue',
        50000.00,
        0,
        NULL,
        NULL,
        141,
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
