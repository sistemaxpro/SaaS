-- ============================================================
-- DATOS INICIALES: CATÁLOGO DE APPS
-- Migración de las 17 apps del array $itemsMenu en menu.php
-- Ejecutar en Master DB: serproc1
-- ============================================================

-- Limpiar catálogo (solo en primera instalación)
-- TRUNCATE TABLE saas_apps_catalogo;

INSERT INTO saas_apps_catalogo 
    (codigo, nombre, descripcion, ruta_app, icono, icono_svg, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo)
VALUES
    -- Apps principales de venta
    ('venta_pos', 'POS', 'Punto de Venta moderno con interfaz táctil', 'pos/index.php', 'fas fa-cart-plus', 'assets/images/icons_v2/pos.svg', 'green', 150000.00, 0, NULL, 'venta_pos', 10, 1),
    
    ('venta_pos_clasico', 'POS Clásico', 'Punto de Venta clásico compatible con todos los dispositivos', 'venta_pos', 'fas fa-shopping-cart', 'assets/images/icons_v2/cart.svg', 'cyan', 100000.00, 0, NULL, 'venta_pos', 15, 1),
    
    -- Apps de estación de servicio
    ('estacion', 'Dashboard Estación', 'Panel de control principal de la estación de servicio', 'public/estacion/index.php', 'fas fa-gas-pump', 'assets/images/icons_v2/gas-pump.svg', 'orange', 150000.00, 0, 'Estación de Servicio', 'app_grid_estacion', 18, 1),

    ('estacion_surtidores', 'Surtidores', 'Gestión de surtidores, picos y dispensadores de combustible', 'public/estacion-surtidores/index.php', 'fas fa-tint', 'assets/images/icons_v2/drop.svg', 'blue', 100000.00, 0, 'Estación de Servicio', 'app_grid_estacion', 19, 1),

    ('estacion_tanques', 'Tanques', 'Control de tanques de almacenamiento y lecturas de nivel', 'public/estacion-tanques/index.php', 'fas fa-oil-can', 'assets/images/icons_v2/barrel.svg', 'amber', 100000.00, 0, 'Estación de Servicio', 'app_grid_estacion', 20, 1),

    ('estacion_turnos', 'Turnos', 'Gestión de turnos de playeros y cajas de playa', 'public/estacion-turnos/index.php', 'fas fa-user-clock', 'assets/images/icons_v2/clock.svg', 'purple', 80000.00, 0, 'Estación de Servicio', 'app_playero_estacion', 21, 1),

    ('estacion_despachos', 'Despachos', 'Registro de despachos de combustible y facturación', 'public/estacion-despachos/index.php', 'fas fa-receipt', 'assets/images/icons_v2/receipt.svg', 'green', 120000.00, 0, 'Estación de Servicio', 'app_playero_estacion', 22, 1),

    ('estacion_cierres', 'Cierre Playa', 'Cierre de playa y control cruzado de ventas vs tanques', 'public/estacion-cierres/index.php', 'fas fa-check-circle', 'assets/images/icons_v2/check.svg', 'indigo', 100000.00, 0, 'Estación de Servicio', 'app_grid_estacion', 23, 1),
    
    -- Apps de gestión comercial
    ('ventas', 'Ventas', 'Consulta y gestión de facturas de venta', 'app_grid_factura_venta_global', 'fas fa-chart-pie', 'assets/images/icons_v2/chart.svg', 'purple', 80000.00, 0, NULL, 'app_grid_factura_venta_global', 30, 1),

    ('presupuesto_clientes', 'Presupuesto a Clientes', 'POS desktop para emitir presupuestos y cotizaciones a clientes', 'public/pos/presupuestos_list.php', 'fas fa-file-signature', 'assets/images/icons_v2/pos.svg', 'sky', 90000.00, 0, NULL, 'venta_pos', 35, 1),
    
    ('compras', 'Compras', 'Gestión de facturas de compra', 'app_grid_factura_compras', 'fas fa-truck-loading', 'assets/images/icons_v2/truck.svg', 'orange', 80000.00, 0, NULL, 'app_grid_factura_compras', 40, 1),

    ('pedido_proveedores', 'Pedidos a Proveedores', 'POS desktop para generar pedidos y solicitudes a proveedores', 'public/pos/pedidos_proveedor_list.php', 'fas fa-dolly', 'assets/images/icons_v2/truck.svg', 'amber', 90000.00, 0, NULL, 'app_grid_factura_compras', 45, 1),
    
    ('productos', 'Productos', 'Catálogo de productos y mercaderías', 'public/productos/legacy_index.php', 'fas fa-boxes', 'assets/images/icons_v2/boxes.svg', 'cyan', 50000.00, 0, NULL, 'app_grid_mercaderias', 50, 1),
    
    ('cajas', 'Cajas', 'Gestión de cajas y movimientos de efectivo', 'app_grid_caja', 'fas fa-cash-register', 'assets/images/icons_v2/cash-register.svg', 'green', 60000.00, 0, NULL, 'app_grid_caja', 60, 1),
    
    ('cuentas', 'Cuentas', 'Gestión de cuentas contables', 'app_grid_cuentas', 'fas fa-folder-open', 'assets/images/icons_v2/folder.svg', 'orange', 70000.00, 0, NULL, 'app_grid_cuentas', 80, 1),
    
    -- Apps de terceros
    ('clientes', 'Clientes', 'Gestión de clientes', 'app_grid_clientes', 'fas fa-user-tie', 'assets/images/icons_v2/user-tie.svg', 'pink', 40000.00, 0, NULL, 'app_grid_clientes', 90, 1),
    
    ('proveedores', 'Proveedores', 'Gestión de proveedores', 'app_grid_proveedores', 'fas fa-building', 'assets/images/icons_v2/building.svg', 'purple', 40000.00, 0, NULL, 'app_grid_proveedores', 110, 1),
    
    ('empleados', 'Empleados', 'Gestión de empleados', 'app_grid_empleados', 'fas fa-user-tag', 'assets/images/icons_v2/user-tag.svg', 'cyan', 40000.00, 0, NULL, 'app_grid_empleados', 120, 1),
    
    -- Apps de administración
    ('panel', 'Panel', 'Dashboard con indicadores y gráficos', 'panel', 'fas fa-tachometer-alt', 'assets/images/icons_v2/dashboard.svg', 'blue', 100000.00, 0, NULL, 'panel', 130, 1),
    
    ('habilitacion_sifen', 'Habilitación SIFEN', 'Configuración de facturación electrónica', 'admin_empresa/editar_habilitacion_sifen.php', 'fas fa-certificate', 'assets/images/icons_v2/certificate.svg', 'green', 0.00, 0, NULL, 'editar_habilitacion_sifen', 140, 1),
    
    ('configuracion', 'Configuración', 'Configuración general de la empresa', 'admin_empresa/editar_empresa_local.php', 'fas fa-cog', 'assets/images/icons_v2/settings.svg', 'purple', 0.00, 0, NULL, 'editar_empresa_local', 150, 1),
    
    ('empresas', 'Empresas', 'Gestión de empresas del sistema (Super Admin)', 'admin_empresa/empresa.php', 'fas fa-industry', 'assets/images/icons_v2/factory.svg', 'blue', 0.00, 0, NULL, 'empresa', 160, 1),
    
    -- App de suscripción (solo admin, empresas != 169)
    ('mi_suscripcion', 'Mi Suscripción', 'Panel para ver y gestionar la suscripción de la empresa', 'public/mi_suscripcion.php', 'fas fa-credit-card', 'assets/images/icons_v2/credit-card.svg', 'emerald', 0.00, 0, NULL, NULL, 170, 1),
    
    -- App de admin suscripciones (solo empresa 169)
    ('suscripciones', 'Suscripciones', 'Administración de suscripciones de todas las empresas', 'public/suscripciones.php', 'fas fa-credit-card', 'assets/images/icons_v2/credit-card.svg', 'green', 0.00, 0, NULL, NULL, 165, 1),
    
    -- App obligatoria (siempre aparece)
    ('salir', 'Salir', 'Cerrar sesión', '__logout__', 'fas fa-sign-out-alt', 'assets/images/icons_v2/logout.svg', 'red', 0.00, 1, NULL, NULL, 999, 1);


-- ============================================================
-- EJEMPLO: Crear suscripción para empresa de prueba
-- ============================================================
/*
-- 1. Crear cabecera de suscripción para febrero 2026
INSERT INTO saas_suscripcion 
    (id_empresa, periodo_inicio, periodo_fin, nro_factura, fecha_vencimiento, estado, estado_pago, dias_gracia)
VALUES 
    (169, '2026-02-01', '2026-02-28', 'SAAS-2026-02-0001', '2026-03-05', 'activa', 'pagado', 5);

-- Obtener ID de suscripción recién creada
SET @id_suscripcion = LAST_INSERT_ID();

-- 2. Agregar items (apps contratadas)
INSERT INTO saas_suscripcion_apps 
    (id_suscripcion, id_app, codigo_app, nombre_app, precio_unitario, subtotal)
SELECT 
    @id_suscripcion,
    id_app,
    codigo,
    nombre,
    precio_mensual,
    precio_mensual
FROM saas_apps_catalogo
WHERE codigo IN ('venta_pos', 'ventas', 'compras', 'productos', 'clientes', 'panel');

-- 3. Actualizar total de la suscripción
UPDATE saas_suscripcion 
SET subtotal = (SELECT SUM(subtotal) FROM saas_suscripcion_apps WHERE id_suscripcion = @id_suscripcion),
    total = (SELECT SUM(subtotal) FROM saas_suscripcion_apps WHERE id_suscripcion = @id_suscripcion)
WHERE id_suscripcion = @id_suscripcion;
*/
