-- En la BD Master (serproc1): registrar tipo de negocio "Estación de Servicio"
-- NOTA: Esta migración se ejecuta en la BD Master, no en empresa_[id]

-- 1. Registrar tipo de negocio
INSERT INTO saas_negocios_tipos (nombre, orden, activo) VALUES
('Estación de Servicio', 100, 1)
ON DUPLICATE KEY UPDATE activo=1;

-- 2. Registrar apps nuevas en el catálogo
INSERT INTO saas_apps_catalogo (codigo, nombre, descripcion, ruta_app, precio_mensual, modulo, negocio, orden, activo)
VALUES
('estacion', 'Dashboard Estación', 'Panel principal de control de estación de servicio', '/public/estacion/', 150000, 'Estación', 'Estación de Servicio', 1, 1),
('estacion_surtidores', 'Gestión de Surtidores', 'Gestión de surtidores y picos', '/public/estacion-surtidores/', 100000, 'Estación', 'Estación de Servicio', 2, 1),
('estacion_tanques', 'Control de Tanques', 'Gestión de tanques y lecturas', '/public/estacion-tanques/', 100000, 'Estación', 'Estación de Servicio', 3, 1),
('estacion_turnos', 'Turnos de Playero', 'Gestión de turnos de playeros', '/public/estacion-turnos/', 80000, 'Estación', 'Estación de Servicio', 4, 1),
('estacion_despachos', 'Registro de Despachos', 'Registro de despachos de combustible', '/public/estacion-despachos/', 120000, 'Estación', 'Estación de Servicio', 5, 1),
('estacion_cierres', 'Cierre de Playa', 'Cierre consolidado de la playa', '/public/estacion-cierres/', 100000, 'Estación', 'Estación de Servicio', 6, 1)
ON DUPLICATE KEY UPDATE precio_mensual=VALUES(precio_mensual);

-- 3. Vincular apps al tipo de negocio (template)
INSERT INTO saas_negocio_templates (negocio, id_app, orden, activo)
SELECT 'Estación de Servicio', sc.id_app, sc.orden, sc.activo
FROM saas_apps_catalogo sc
WHERE sc.codigo IN (
  'estacion',
  'estacion_surtidores',
  'estacion_tanques',
  'estacion_turnos',
  'estacion_despachos',
  'estacion_cierres'
)
AND NOT EXISTS (
  SELECT 1 FROM saas_negocio_templates snt
  WHERE snt.negocio = 'Estación de Servicio' AND snt.id_app = sc.id_app
);
