-- ============================================================
-- MIGRACIÓN: Deprecar rutas ScriptCase en saas_apps_catalogo
-- Reemplazar app_grid_* y app_form_* por rutas nativas /public/
-- Ejecutar en Master DB: serproc1
-- Fecha: 2026-02-10
-- ============================================================

-- Apps con reemplazo nativo existente
UPDATE saas_apps_catalogo SET ruta_app = 'public/ventas/index.php' WHERE codigo = 'ventas' AND ruta_app = 'app_grid_factura_venta_global';
UPDATE saas_apps_catalogo SET ruta_app = 'public/compras/index.php' WHERE codigo = 'compras' AND ruta_app = 'app_grid_factura_compras';

-- Apps sin reemplazo nativo aún - apuntar a rutas placeholder nativas
-- Se crearán los módulos progresivamente
UPDATE saas_apps_catalogo SET ruta_app = 'public/productos/legacy_index.php' WHERE codigo = 'productos' AND ruta_app = 'app_grid_mercaderias';
UPDATE saas_apps_catalogo SET ruta_app = 'public/cajas/index.php' WHERE codigo = 'cajas' AND ruta_app = 'app_grid_caja';
UPDATE saas_apps_catalogo SET ruta_app = 'public/cuentas/index.php' WHERE codigo = 'cuentas' AND ruta_app = 'app_grid_cuentas';
UPDATE saas_apps_catalogo SET ruta_app = 'public/clientes/index.php' WHERE codigo = 'clientes' AND ruta_app = 'app_grid_clientes';
UPDATE saas_apps_catalogo SET ruta_app = 'public/proveedores/index.php' WHERE codigo = 'proveedores' AND ruta_app = 'app_grid_proveedores';
UPDATE saas_apps_catalogo SET ruta_app = 'public/empleados/index.php' WHERE codigo = 'empleados' AND ruta_app = 'app_grid_empleados';
UPDATE saas_apps_catalogo SET ruta_app = 'public/picos/index.php' WHERE codigo = 'picos' AND ruta_app = 'app_grid_maquinas';
UPDATE saas_apps_catalogo SET ruta_app = 'public/cierres/index.php' WHERE codigo = 'cierres_turno' AND ruta_app = 'app_grid_cierre_turno_playero';
UPDATE saas_apps_catalogo SET ruta_app = 'public/panel/index.php' WHERE codigo = 'panel' AND ruta_app = 'panel';
UPDATE saas_apps_catalogo SET ruta_app = 'public/cierre-playa/index.php' WHERE codigo = 'cierre_playa' AND ruta_app = 'cierre_playa_buscador';

-- Verificar cambios
SELECT codigo, nombre, ruta_app FROM saas_apps_catalogo ORDER BY orden;
