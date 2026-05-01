-- DATOS MOCK PARA MÓDULO ESTACIÓN DE SERVICIO (DEV ONLY)
-- Cargar solo en BD de desarrollo (puerto 3307)
-- Contiene: 3 surtidores, 2 tanques, 6 picos, turnos, despachos

-- ====== TANQUES ======
INSERT INTO estacion_tanques (nombre, id_combustible, capacidad_litros, nivel_minimo_alerta, tipo_medicion, activo, id_sucursal) VALUES
('Tanque Nafta Común', 1, 5000.00, 200.00, 'sensor', 'Y', 1),
('Tanque Diésel', 4, 5000.00, 200.00, 'sensor', 'Y', 1)
ON DUPLICATE KEY UPDATE activo='Y';

-- ====== SURTIDORES ======
INSERT INTO estacion_surtidores (nombre, nro_surtidor, id_sucursal, tipo_control, activo) VALUES
('Surtidor 1', 1, 1, 'automatico', 'Y'),
('Surtidor 2', 2, 1, 'automatico', 'Y'),
('Surtidor 3', 3, 1, 'manual', 'Y')
ON DUPLICATE KEY UPDATE activo='Y';

-- ====== PICOS ======
INSERT INTO estacion_picos (id_surtidor, nro_pico, nombre, id_combustible, id_tanque, totalizador_actual, activo) VALUES
-- Surtidor 1: 2 picos (Nafta)
(1, 1, 'Pico 1A - Nafta Común', 1, 1, 1000.250, 'Y'),
(1, 2, 'Pico 1B - Nafta Super', 2, 1, 950.500, 'Y'),

-- Surtidor 2: 2 picos (Nafta + Diésel)
(2, 1, 'Pico 2A - Nafta Común', 1, 1, 1500.750, 'Y'),
(2, 2, 'Pico 2B - Diésel', 4, 2, 800.300, 'Y'),

-- Surtidor 3: 2 picos (Nafta + Diésel)
(3, 1, 'Pico 3A - Nafta Premium', 3, 1, 2000.100, 'Y'),
(3, 2, 'Pico 3B - Diésel', 4, 2, 1200.450, 'Y')
ON DUPLICATE KEY UPDATE totalizador_actual=VALUES(totalizador_actual), activo='Y';

-- ====== LECTURAS INICIALES DE TANQUES ======
INSERT INTO estacion_lecturas_tanque (id_tanque, fecha_hora, litros_medidos, cms_medidos, tipo, registrado_por, observacion) VALUES
(1, DATE_SUB(NOW(), INTERVAL 2 DAY), 4500.00, 180, 'apertura', 'admin', 'Lectura apertura turno'),
(1, NOW(), 4200.00, 168, 'manual', 'admin', 'Lectura manual actual'),
(2, DATE_SUB(NOW(), INTERVAL 2 DAY), 4800.00, 192, 'apertura', 'admin', 'Lectura apertura turno'),
(2, NOW(), 4400.00, 176, 'manual', 'admin', 'Lectura manual actual')
ON DUPLICATE KEY UPDATE observacion=VALUES(observacion);

-- ====== TURNOS ======
INSERT INTO estacion_turnos (id_usuario, id_sucursal, fecha_turno, hora_apertura, hora_cierre, estado, efectivo_apertura, efectivo_cierre, observacion_apertura, cerrado_por) VALUES
-- Turno cerrado (ayer)
(1, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 24 HOUR) + INTERVAL 6 HOUR, DATE_SUB(NOW(), INTERVAL 24 HOUR) + INTERVAL 14 HOUR, 'cerrado', 500.00, 1250.00, 'Turno sin novedad', 'admin'),

-- Turno abierto (hoy)
(1, 1, CURDATE(), NOW() - INTERVAL 2 HOUR, NULL, 'abierto', 500.00, NULL, 'Turno iniciado', NULL)
ON DUPLICATE KEY UPDATE estado=VALUES(estado);

-- ====== ASIGNACIÓN DE PICOS A TURNO (hoy) ======
INSERT INTO estacion_turno_picos (id_turno, id_pico, totalizador_apertura, totalizador_cierre, litros_vendidos_sistema) VALUES
-- Turno de hoy (asumiendo que el turno más reciente es el ID 2)
(2, 1, 1000.250, NULL, NULL),
(2, 2, 950.500, NULL, NULL),
(2, 3, 1500.750, NULL, NULL),
(2, 4, 800.300, NULL, NULL),
(2, 5, 2000.100, NULL, NULL),
(2, 6, 1200.450, NULL, NULL)
ON DUPLICATE KEY UPDATE totalizador_apertura=VALUES(totalizador_apertura);

-- ====== DESPACHOS (VENTAS) ======
INSERT INTO estacion_despachos (id_turno, id_pico, id_usuario, fecha_hora, litros, monto_total, precio_unitario, id_combustible, modo_registro, totalizador_inicio, totalizador_fin, estado, nro_comprobante) VALUES
-- Despachos del turno de ayer
(1, 1, 1, DATE_SUB(NOW(), INTERVAL 24 HOUR) + INTERVAL 7 HOUR, 40.50, 780.60, 19.28, 1, 'automatico', 1000.250, 1040.750, 'registrado', 'DESH-001'),
(1, 1, 1, DATE_SUB(NOW(), INTERVAL 24 HOUR) + INTERVAL 8 HOUR, 35.00, 674.80, 19.28, 1, 'automatico', 1040.750, 1075.750, 'registrado', 'DESH-002'),
(1, 2, 1, DATE_SUB(NOW(), INTERVAL 24 HOUR) + INTERVAL 9 HOUR, 50.00, 975.00, 19.50, 2, 'automatico', 950.500, 1000.500, 'registrado', 'DESH-003'),
(1, 3, 1, DATE_SUB(NOW(), INTERVAL 24 HOUR) + INTERVAL 10 HOUR, 45.25, 872.10, 19.28, 1, 'automatico', 1500.750, 1546.000, 'registrado', 'DESH-004'),
(1, 4, 1, DATE_SUB(NOW(), INTERVAL 24 HOUR) + INTERVAL 11 HOUR, 30.00, 570.00, 19.00, 4, 'automatico', 800.300, 830.300, 'registrado', 'DESH-005'),

-- Despachos del turno de hoy
(2, 1, 1, NOW() - INTERVAL 90 MINUTE, 25.00, 482.00, 19.28, 1, 'automatico', 1000.250, 1025.250, 'registrado', 'DESH-006'),
(2, 2, 1, NOW() - INTERVAL 60 MINUTE, 30.00, 585.00, 19.50, 2, 'automatico', 950.500, 980.500, 'registrado', 'DESH-007'),
(2, 3, 1, NOW() - INTERVAL 45 MINUTE, 35.50, 684.44, 19.28, 1, 'automatico', 1500.750, 1536.250, 'registrado', 'DESH-008')
ON DUPLICATE KEY UPDATE estado=VALUES(estado);

-- ====== LECTURAS AUTOMÁTICAS DEL SURTIDOR ======
INSERT INTO estacion_lecturas_surtidor (id_pico, fecha_hora, totalizador_litros, totalizador_importe, fuente) VALUES
-- Pico 1
(1, NOW() - INTERVAL 2 HOUR, 1000.250, 19285.00, 'evento'),
(1, NOW() - INTERVAL 90 MINUTE, 1025.250, 19767.00, 'evento'),

-- Pico 2
(2, NOW() - INTERVAL 2 HOUR, 950.500, 18537.75, 'evento'),
(2, NOW() - INTERVAL 60 MINUTE, 980.500, 19120.75, 'evento'),

-- Pico 3
(3, NOW() - INTERVAL 2 HOUR, 1500.750, 28950.00, 'evento'),
(3, NOW() - INTERVAL 45 MINUTE, 1536.250, 29640.00, 'evento')
ON DUPLICATE KEY UPDATE totalizador_litros=VALUES(totalizador_litros);

-- ====== CIERRE DE PLAYA (AYER) ======
INSERT INTO estacion_cierre_playa (fecha, id_sucursal, id_usuario_supervisor, hora_cierre, estado, total_litros_vendidos, total_importe, observacion) VALUES
(DATE_SUB(CURDATE(), INTERVAL 1 DAY), 1, 1, DATE_SUB(NOW(), INTERVAL 24 HOUR) + INTERVAL 14 HOUR, 'cerrado', 200.75, 3852.50, 'Cierre sin novedad')
ON DUPLICATE KEY UPDATE estado=VALUES(estado);

-- ====== DETALLE CIERRE PLAYA (AYER) ======
INSERT INTO estacion_cierre_playa_detalle (id_cierre_playa, id_combustible, litros_apertura_tanque, litros_cierre_tanque, litros_vendidos, diferencia, porcentaje_diferencia) VALUES
(1, 1, 4500.00, 4200.00, 300.00, 0.00, 0.00),
(1, 4, 4800.00, 4400.00, 400.00, 0.00, 0.00)
ON DUPLICATE KEY UPDATE litros_vendidos=VALUES(litros_vendidos);

-- ====== HISTÓRICO DE PRECIOS ======
INSERT INTO estacion_precios_historial (id_combustible, precio_anterior, precio_nuevo, fecha_cambio, id_usuario, motivo) VALUES
(1, 18.50, 19.28, DATE_SUB(NOW(), INTERVAL 3 DAY), 1, 'Ajuste diario'),
(1, 19.28, 19.28, DATE_SUB(NOW(), INTERVAL 1 DAY), 1, 'Precio sin cambios'),
(2, 19.00, 19.50, DATE_SUB(NOW(), INTERVAL 5 DAY), 1, 'Ajuste diario'),
(4, 18.50, 19.00, DATE_SUB(NOW(), INTERVAL 4 DAY), 1, 'Ajuste diario')
ON DUPLICATE KEY UPDATE fecha_cambio=VALUES(fecha_cambio);
