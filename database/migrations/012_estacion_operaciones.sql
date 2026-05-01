-- Turnos de playero
CREATE TABLE estacion_turnos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  id_sucursal INT,
  fecha_turno DATE NOT NULL,
  hora_apertura DATETIME NOT NULL,
  hora_cierre DATETIME,
  estado ENUM('abierto', 'cerrado', 'conciliado') NOT NULL DEFAULT 'abierto',
  efectivo_apertura DECIMAL(15, 2) NOT NULL DEFAULT 0,
  efectivo_cierre DECIMAL(15, 2),
  observacion_apertura TEXT,
  observacion_cierre TEXT,
  cerrado_por VARCHAR(255),
  conciliado_por VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_usuario (id_usuario),
  KEY idx_fecha (fecha_turno),
  KEY idx_estado (estado),
  UNIQUE KEY unique_turno_usuario_fecha (id_usuario, fecha_turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Asignación de picos a cada turno
CREATE TABLE estacion_turno_picos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_turno INT NOT NULL,
  id_pico INT NOT NULL,
  totalizador_apertura DECIMAL(15, 3),
  totalizador_cierre DECIMAL(15, 3),
  litros_vendidos_sistema DECIMAL(10, 2) COMMENT 'Calculado por sistema en cierre',
  litros_vendidos_pico DECIMAL(10, 2) COMMENT 'Por diferencia de totalizador',
  diferencia DECIMAL(10, 2) COMMENT 'Variación entre sistema y pico',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_turno) REFERENCES estacion_turnos(id),
  FOREIGN KEY (id_pico) REFERENCES estacion_picos(id),
  KEY idx_turno (id_turno),
  KEY idx_pico (id_pico),
  UNIQUE KEY unique_turno_pico (id_turno, id_pico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Despachos (cada venta de combustible)
CREATE TABLE estacion_despachos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_turno INT NOT NULL,
  id_pico INT NOT NULL,
  id_usuario INT NOT NULL,
  fecha_hora DATETIME NOT NULL,
  litros DECIMAL(10, 3) NOT NULL,
  monto_total DECIMAL(15, 2) NOT NULL,
  precio_unitario DECIMAL(10, 2) NOT NULL,
  id_combustible INT NOT NULL,
  modo_registro ENUM('manual', 'automatico') NOT NULL DEFAULT 'manual',
  totalizador_inicio DECIMAL(15, 3),
  totalizador_fin DECIMAL(15, 3),
  id_factura INT COMMENT 'Referencia a factura SIFEN si aplica',
  nro_comprobante VARCHAR(50),
  id_cliente INT COMMENT 'Cliente si venta a persona específica',
  estado ENUM('registrado', 'facturado', 'anulado') NOT NULL DEFAULT 'registrado',
  observacion TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (id_turno) REFERENCES estacion_turnos(id),
  FOREIGN KEY (id_pico) REFERENCES estacion_picos(id),
  FOREIGN KEY (id_combustible) REFERENCES estacion_combustibles(id),
  KEY idx_turno (id_turno),
  KEY idx_pico (id_pico),
  KEY idx_fecha (fecha_hora),
  KEY idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cierre de playa (consolidación diaria)
CREATE TABLE estacion_cierre_playa (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  id_sucursal INT,
  id_usuario_supervisor INT,
  hora_cierre DATETIME,
  estado ENUM('abierto', 'cerrado') NOT NULL DEFAULT 'abierto',
  total_litros_vendidos DECIMAL(15, 3),
  total_importe DECIMAL(15, 2),
  diferencia_tanque TEXT COMMENT 'JSON con diferencias por combustible',
  observacion TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fecha (fecha),
  KEY idx_estado (estado),
  UNIQUE KEY unique_fecha_sucursal (fecha, id_sucursal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detalle del cierre por combustible
CREATE TABLE estacion_cierre_playa_detalle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_cierre_playa INT NOT NULL,
  id_combustible INT NOT NULL,
  litros_apertura_tanque DECIMAL(10, 2),
  litros_cierre_tanque DECIMAL(10, 2),
  litros_vendidos DECIMAL(10, 2),
  diferencia DECIMAL(10, 2),
  porcentaje_diferencia DECIMAL(5, 2),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_cierre_playa) REFERENCES estacion_cierre_playa(id),
  FOREIGN KEY (id_combustible) REFERENCES estacion_combustibles(id),
  KEY idx_cierre (id_cierre_playa),
  KEY idx_combustible (id_combustible)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
