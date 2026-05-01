-- MIGRACION: Agregar modulo Gestion de Gastos al catalogo SaaS
-- Fecha: 2026-03-13

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, icono_svg, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo)
VALUES
    (
        'gastos',
        'Gestión de Gastos',
        'Registro y control de gastos de la empresa, con carga manual y por voz en móvil',
        'public/gastos/index.php',
        'fas fa-file-invoice-dollar',
        'assets/images/icons_v2/banknotes.svg',
        'emerald',
        50000.00,
        0,
        NULL,
        'app_grid_caja',
        46,
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
    obligatoria = VALUES(obligatoria),
    requiere_modulo = VALUES(requiere_modulo),
    permiso_base = VALUES(permiso_base),
    orden = VALUES(orden),
    activo = VALUES(activo);
