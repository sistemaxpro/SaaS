-- MIGRACION: Agregar apps POS Autorrepuestos y Taller Mecanico
-- Fecha: 2026-03-08

INSERT INTO saas_apps_catalogo
    (codigo, nombre, descripcion, ruta_app, icono, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo)
VALUES
    (
        'pos_autorrepuestos',
        'POS Autorrepuestos',
        'Puesto de venta para autorrepuestos con foco en mostrador y facturacion rapida',
        'public/pos/index.php?rubro=autorrepuestos',
        'fas fa-car-side',
        'emerald',
        100000.00,
        0,
        NULL,
        'venta_pos',
        36,
        1
    ),
    (
        'taller_mantenimiento',
        'Taller Mecanico',
        'Modulo completo de mantenimiento: clientes, vehiculos, servicios y ordenes de trabajo',
        'public/taller/index.php',
        'fas fa-screwdriver-wrench',
        'orange',
        100000.00,
        0,
        NULL,
        'app_grid_taller_mantenimiento',
        37,
        1
    )
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    ruta_app = VALUES(ruta_app),
    icono = VALUES(icono),
    color = VALUES(color),
    precio_mensual = VALUES(precio_mensual),
    permiso_base = VALUES(permiso_base),
    activo = VALUES(activo);

