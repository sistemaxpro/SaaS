-- Tanques de almacenamiento
CREATE TABLE estacion_tanques (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  id_combustible INT NOT NULL,
  capacidad_litros DECIMAL(10, 2) NOT NULL,
  nivel_minimo_alerta DECIMAL(10, 2) DEFAULT 100,
  tipo_medicion ENUM('manual', 'sensor') NOT NULL DEFAULT 'manual',
  sensor_ip VARCHAR(15),
  sensor_puerto INT,
  sensor_protocolo VARCHAR(50) COMMENT 'Ej: Modbus, Veeder-Root',
  activo ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
  id_sucursal INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (id_combustible) REFERENCES estacion_combustibles(id),
  KEY idx_combustible (id_combustible),
  KEY idx_sucursal (id_sucursal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lecturas de nivel de tanques (varillado o sensor)
CREATE TABLE estacion_lecturas_tanque (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_tanque INT NOT NULL,
  fecha_hora DATETIME NOT NULL,
  litros_medidos DECIMAL(10, 2) NOT NULL,
  cms_medidos INT COMMENT 'Centímetros de altura en el varillado',
  tipo ENUM('apertura', 'cierre', 'automatica', 'manual') NOT NULL,
  registrado_por VARCHAR(255),
  observacion TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_tanque) REFERENCES estacion_tanques(id),
  KEY idx_tanque (id_tanque),
  KEY idx_fecha (fecha_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Surtidores (máquinas expendedoras/dispensadores)
CREATE TABLE estacion_surtidores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  nro_surtidor INT NOT NULL,
  id_sucursal INT,
  tipo_control ENUM('manual', 'automatico') NOT NULL DEFAULT 'manual',
  controladora_ip VARCHAR(15),
  controladora_puerto INT,
  controladora_protocolo VARCHAR(50),
  activo ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sucursal (id_sucursal),
  UNIQUE KEY unique_surtidor_sucursal (nro_surtidor, id_sucursal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Picos/mangueras de cada surtidor
CREATE TABLE estacion_picos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_surtidor INT NOT NULL,
  nro_pico INT NOT NULL COMMENT 'Número del pico en el surtidor (1, 2, 3, etc)',
  nombre VARCHAR(255),
  id_combustible INT NOT NULL,
  id_tanque INT NOT NULL,
  totalizador_actual DECIMAL(15, 3) DEFAULT 0 COMMENT 'Lectura acumulada del surtidor',
  activo ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (id_surtidor) REFERENCES estacion_surtidores(id),
  FOREIGN KEY (id_combustible) REFERENCES estacion_combustibles(id),
  FOREIGN KEY (id_tanque) REFERENCES estacion_tanques(id),
  KEY idx_surtidor (id_surtidor),
  KEY idx_combustible (id_combustible),
  KEY idx_tanque (id_tanque),
  UNIQUE KEY unique_pico_surtidor (id_surtidor, nro_pico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de lecturas automáticas del surtidor
CREATE TABLE estacion_lecturas_surtidor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_pico INT NOT NULL,
  fecha_hora DATETIME NOT NULL,
  totalizador_litros DECIMAL(15, 3),
  totalizador_importe DECIMAL(15, 2),
  fuente ENUM('poll', 'evento') NOT NULL DEFAULT 'poll',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_pico) REFERENCES estacion_picos(id),
  KEY idx_pico (id_pico),
  KEY idx_fecha (fecha_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de cambios de precio
CREATE TABLE estacion_precios_historial (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_combustible INT NOT NULL,
  precio_anterior DECIMAL(10, 2) NOT NULL,
  precio_nuevo DECIMAL(10, 2) NOT NULL,
  fecha_cambio DATETIME NOT NULL,
  id_usuario INT,
  motivo VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_combustible) REFERENCES estacion_combustibles(id),
  KEY idx_combustible (id_combustible),
  KEY idx_fecha (fecha_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
