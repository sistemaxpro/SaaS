-- ============================================================
-- SISTEMA DE SUSCRIPCIONES SaaS - SistemaX v1
-- Modelo tipo factura: Catálogo de Apps + Suscripción mensual + Items
-- Ejecutar en Master DB: serproc1
-- ============================================================

-- ============================================================
-- TABLA 1: CATÁLOGO DE APLICACIONES
-- Similar a una tabla de productos
-- ============================================================
CREATE TABLE IF NOT EXISTS saas_apps_catalogo (
    id_app INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE COMMENT 'Código único de la app (ej: venta_pos, app_grid_ventas)',
    nombre VARCHAR(100) NOT NULL COMMENT 'Nombre visible en el menú',
    descripcion TEXT COMMENT 'Descripción de la funcionalidad',
    ruta_app VARCHAR(255) NOT NULL COMMENT 'Ruta relativa de la aplicación',
    icono VARCHAR(100) DEFAULT 'fas fa-cube' COMMENT 'Clase de FontAwesome',
    icono_svg VARCHAR(255) COMMENT 'Ruta al icono SVG (opcional)',
    color VARCHAR(30) DEFAULT 'blue' COMMENT 'Color del icono 3D: blue, green, orange, purple, red, cyan, pink',
    precio_mensual DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Precio mensual en guaraníes',
    obligatoria TINYINT(1) DEFAULT 0 COMMENT '1=Siempre aparece en menú (ej: Salir)',
    requiere_modulo INT DEFAULT NULL COMMENT 'Solo visible si empresa tiene este módulo (ej: 7=estación servicio)',
    permiso_base VARCHAR(100) COMMENT 'Nombre del permiso en sec_groups_apps para compatibilidad',
    orden INT DEFAULT 100 COMMENT 'Orden de aparición en el menú',
    activo TINYINT(1) DEFAULT 1 COMMENT '1=Disponible para contratar',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_codigo (codigo),
    INDEX idx_activo (activo),
    INDEX idx_obligatoria (obligatoria),
    INDEX idx_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Catálogo de aplicaciones disponibles para contratar en el SaaS';


-- ============================================================
-- TABLA 2: SUSCRIPCIÓN MENSUAL (CABECERA)
-- Similar a factura_ventas - Una por empresa por mes
-- ============================================================
CREATE TABLE IF NOT EXISTS saas_suscripcion (
    id_suscripcion INT AUTO_INCREMENT PRIMARY KEY,
    id_empresa INT NOT NULL COMMENT 'FK a empresa.id_empresa',
    
    -- Período de facturación
    periodo_inicio DATE NOT NULL COMMENT 'Primer día del período (ej: 2026-02-01)',
    periodo_fin DATE NOT NULL COMMENT 'Último día del período (ej: 2026-02-28)',
    
    -- Facturación
    nro_factura VARCHAR(30) COMMENT 'Número de factura SaaS (ej: SAAS-2026-02-0001)',
    fecha_emision DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_vencimiento DATE COMMENT 'Fecha límite de pago (periodo_fin + dias_gracia)',
    
    -- Montos
    subtotal DECIMAL(12,2) DEFAULT 0.00,
    descuento DECIMAL(12,2) DEFAULT 0.00,
    total DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Total a pagar',
    moneda VARCHAR(3) DEFAULT 'PYG',
    
    -- Estados
    estado ENUM('activa', 'gracia', 'vencida', 'cancelada') DEFAULT 'activa' 
        COMMENT 'activa=vigente, gracia=período de gracia, vencida=bloqueada, cancelada=baja',
    estado_pago ENUM('pendiente', 'pagado', 'parcial', 'atrasado') DEFAULT 'pendiente',
    
    -- Período de gracia
    dias_gracia INT DEFAULT 5 COMMENT 'Días de gracia después de vencimiento',
    
    -- Pago
    fecha_pago DATETIME COMMENT 'Fecha en que se registró el pago',
    metodo_pago VARCHAR(50) COMMENT 'ueno, transferencia, efectivo, etc.',
    ueno_payment_id VARCHAR(100) COMMENT 'ID de pago Ueno para webhook',
    comprobante_pago VARCHAR(255) COMMENT 'Número de comprobante o referencia',
    
    -- Notificaciones
    notificado_nueva TINYINT(1) DEFAULT 0 COMMENT '1=Se envió email de nueva factura',
    notificado_vencimiento TINYINT(1) DEFAULT 0 COMMENT '1=Se envió advertencia de vencimiento',
    notificado_bloqueo TINYINT(1) DEFAULT 0 COMMENT '1=Se envió aviso de bloqueo',
    
    -- Auditoría
    obs TEXT COMMENT 'Observaciones',
    created_by INT COMMENT 'Usuario que creó (para renovación manual)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_empresa (id_empresa),
    INDEX idx_periodo (periodo_inicio, periodo_fin),
    INDEX idx_estado (estado),
    INDEX idx_estado_pago (estado_pago),
    INDEX idx_fecha_vencimiento (fecha_vencimiento),
    INDEX idx_ueno_payment (ueno_payment_id),
    
    UNIQUE KEY uk_empresa_periodo (id_empresa, periodo_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Cabecera de suscripción mensual - Una factura por empresa por mes';


-- ============================================================
-- TABLA 3: ITEMS DE SUSCRIPCIÓN (DETALLE)
-- Similar a extracto_productos - Apps contratadas en la factura
-- ============================================================
CREATE TABLE IF NOT EXISTS saas_suscripcion_apps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_suscripcion INT NOT NULL COMMENT 'FK a saas_suscripcion',
    id_app INT NOT NULL COMMENT 'FK a saas_apps_catalogo',
    
    -- Datos desnormalizados al momento de facturar
    codigo_app VARCHAR(50) NOT NULL COMMENT 'Código de la app al facturar',
    nombre_app VARCHAR(100) NOT NULL COMMENT 'Nombre de la app al facturar',
    
    -- Precio
    cantidad INT DEFAULT 1 COMMENT 'Por si hay licencias múltiples',
    precio_unitario DECIMAL(12,2) NOT NULL COMMENT 'Precio al momento de facturar',
    descuento DECIMAL(12,2) DEFAULT 0.00,
    subtotal DECIMAL(12,2) NOT NULL COMMENT '(cantidad * precio_unitario) - descuento',
    
    -- Estado del item
    activo TINYINT(1) DEFAULT 1 COMMENT '1=App habilitada para la empresa',
    
    -- Fechas de activación
    fecha_activacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_desactivacion DATETIME COMMENT 'Si se desactiva antes de fin de período',
    
    -- Auditoría
    obs TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_suscripcion (id_suscripcion),
    INDEX idx_app (id_app),
    INDEX idx_activo (activo),
    
    UNIQUE KEY uk_suscripcion_app (id_suscripcion, id_app),
    
    CONSTRAINT fk_suscripcion_apps_suscripcion 
        FOREIGN KEY (id_suscripcion) REFERENCES saas_suscripcion(id_suscripcion) 
        ON DELETE CASCADE,
    CONSTRAINT fk_suscripcion_apps_catalogo 
        FOREIGN KEY (id_app) REFERENCES saas_apps_catalogo(id_app) 
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Detalle de suscripción - Apps contratadas por factura mensual';


-- ============================================================
-- TABLA 4: HISTORIAL DE PAGOS (Opcional - Para auditoría)
-- ============================================================
CREATE TABLE IF NOT EXISTS saas_pagos_historial (
    id_pago INT AUTO_INCREMENT PRIMARY KEY,
    id_suscripcion INT NOT NULL,
    
    fecha_pago DATETIME DEFAULT CURRENT_TIMESTAMP,
    monto DECIMAL(12,2) NOT NULL,
    metodo_pago VARCHAR(50) NOT NULL,
    referencia VARCHAR(100) COMMENT 'ID de transacción, nro cheque, etc.',
    
    -- Datos de pasarela
    gateway VARCHAR(30) COMMENT 'ueno, bancard, etc.',
    gateway_response TEXT COMMENT 'Respuesta JSON de la pasarela',
    
    obs TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_suscripcion (id_suscripcion),
    
    CONSTRAINT fk_pagos_suscripcion 
        FOREIGN KEY (id_suscripcion) REFERENCES saas_suscripcion(id_suscripcion) 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Historial de pagos de suscripciones';


-- ============================================================
-- VISTA: Suscripción actual por empresa
-- Para consulta rápida desde menu.php
-- ============================================================
CREATE OR REPLACE VIEW v_suscripcion_activa AS
SELECT 
    s.id_suscripcion,
    s.id_empresa,
    s.periodo_inicio,
    s.periodo_fin,
    s.total,
    s.estado,
    s.estado_pago,
    s.dias_gracia,
    s.fecha_vencimiento,
    DATEDIFF(s.fecha_vencimiento, CURDATE()) as dias_restantes,
    CASE 
        WHEN s.estado = 'activa' AND s.estado_pago = 'pagado' THEN 'ok'
        WHEN s.estado = 'activa' AND s.estado_pago != 'pagado' THEN 'pendiente_pago'
        WHEN s.estado = 'gracia' THEN 'gracia'
        WHEN s.estado = 'vencida' THEN 'bloqueado'
        ELSE 'sin_suscripcion'
    END as estado_acceso
FROM saas_suscripcion s
WHERE CURDATE() BETWEEN s.periodo_inicio AND DATE_ADD(s.periodo_fin, INTERVAL s.dias_gracia DAY)
  AND s.estado NOT IN ('cancelada');


-- ============================================================
-- VISTA: Apps activas por empresa (para el menú)
-- ============================================================
CREATE OR REPLACE VIEW v_empresa_apps AS
SELECT 
    s.id_empresa,
    a.id_app,
    a.codigo,
    a.nombre,
    a.ruta_app,
    a.icono,
    a.icono_svg,
    a.color,
    a.permiso_base,
    a.requiere_modulo,
    a.orden,
    a.obligatoria,
    sa.activo as item_activo
FROM saas_suscripcion s
INNER JOIN saas_suscripcion_apps sa ON sa.id_suscripcion = s.id_suscripcion
INNER JOIN saas_apps_catalogo a ON a.id_app = sa.id_app
WHERE s.estado IN ('activa', 'gracia')
  AND CURDATE() BETWEEN s.periodo_inicio AND DATE_ADD(s.periodo_fin, INTERVAL s.dias_gracia DAY)
  AND sa.activo = 1
  AND a.activo = 1
UNION
-- Apps obligatorias siempre aparecen
SELECT 
    0 as id_empresa, -- Se filtra con COALESCE en la consulta
    a.id_app,
    a.codigo,
    a.nombre,
    a.ruta_app,
    a.icono,
    a.icono_svg,
    a.color,
    a.permiso_base,
    a.requiere_modulo,
    a.orden,
    a.obligatoria,
    1 as item_activo
FROM saas_apps_catalogo a
WHERE a.obligatoria = 1 AND a.activo = 1;
