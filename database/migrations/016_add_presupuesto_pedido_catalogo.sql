-- MIGRACION: Agregar apps Presupuesto a Clientes y Pedidos a Proveedores al catalogo
-- Fecha: 2026-04-03

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, icono_svg, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo)
VALUES
    (
        'presupuesto_clientes',
        'Presupuesto a Clientes',
        'POS desktop para emitir presupuestos y cotizaciones a clientes',
        'public/pos/presupuestos_list.php',
        'fas fa-file-signature',
        'assets/images/icons_v2/pos.svg',
        'sky',
        90000.00,
        0,
        NULL,
        'venta_pos',
        35,
        1
    ),
    (
        'pedido_proveedores',
        'Pedidos a Proveedores',
        'POS desktop para generar pedidos y solicitudes a proveedores',
        'public/pos/pedidos_proveedor_list.php',
        'fas fa-dolly',
        'assets/images/icons_v2/truck.svg',
        'amber',
        90000.00,
        0,
        NULL,
        'app_grid_factura_compras',
        45,
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

INSERT IGNORE INTO tipo_negocio_app (tipo_negocio_id, id_app, orden, activo)
SELECT tna.tipo_negocio_id, destino.id_app, COALESCE(tna.orden, 1), COALESCE(tna.activo, 1)
FROM tipo_negocio_app tna
INNER JOIN saas_apps_catalogo origen ON origen.id_app = tna.id_app
INNER JOIN saas_apps_catalogo destino ON destino.codigo = 'presupuesto_clientes'
WHERE origen.codigo = 'venta_pos';

INSERT IGNORE INTO tipo_negocio_app (tipo_negocio_id, id_app, orden, activo)
SELECT tna.tipo_negocio_id, destino.id_app, COALESCE(tna.orden, 3), COALESCE(tna.activo, 1)
FROM tipo_negocio_app tna
INNER JOIN saas_apps_catalogo origen ON origen.id_app = tna.id_app
INNER JOIN saas_apps_catalogo destino ON destino.codigo = 'pedido_proveedores'
WHERE origen.codigo = 'compras';
