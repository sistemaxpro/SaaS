-- MIGRACION: Agregar app Autorrepuestos Desktop Dropdown al catalogo
-- Fecha: 2026-04-01

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, icono_svg, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo)
VALUES
    (
        'pos_autorrepuestos_desktop',
        'Autorrepuestos',
        'POS desktop para autorrepuestos con dropdown de mercaderias y preview de detalle en mostrador',
        'public/pos/index_desktop_dropdown.php?rubro=autorrepuestos',
        'fas fa-car-side',
        'assets/images/icons_v2/pos.svg',
        'emerald',
        150000.00,
        0,
        NULL,
        'venta_pos',
        36,
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

INSERT IGNORE INTO tipo_negocio (nombre, orden, activo)
VALUES ('Autorrepuestos', 40, 1);

DELETE tna
FROM tipo_negocio_app tna
INNER JOIN tipo_negocio tn ON tn.id = tna.tipo_negocio_id
INNER JOIN saas_apps_catalogo a ON a.id_app = tna.id_app
WHERE LOWER(TRIM(tn.nombre)) = 'autorrepuestos'
  AND a.codigo IN ('venta_pos', 'pos_autorrepuestos');

INSERT IGNORE INTO tipo_negocio_app (tipo_negocio_id, id_app, orden, activo)
SELECT tn.id, a.id_app, 10, 1
FROM tipo_negocio tn
INNER JOIN saas_apps_catalogo a ON a.codigo = 'pos_autorrepuestos_desktop'
WHERE LOWER(TRIM(tn.nombre)) = 'autorrepuestos';
