-- Cola de procesamiento FE asíncrona (SIFEN)
-- Ejecutar en la base maestra serproc1

CREATE TABLE IF NOT EXISTS serproc1.fe_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NOT NULL,
  db_name VARCHAR(64) NOT NULL,
  id_factura INT NOT NULL,
  accion ENUM('emitir','consultar') NOT NULL DEFAULT 'emitir',
  estado ENUM('pendiente','procesando','resuelto','fallido') NOT NULL DEFAULT 'pendiente',
  prioridad TINYINT NOT NULL DEFAULT 5,
  intentos INT NOT NULL DEFAULT 0,
  max_intentos INT NOT NULL DEFAULT 8,
  next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(255) NULL,
  last_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pick (estado, next_retry_at, prioridad, id),
  KEY idx_empresa_factura (id_empresa, id_factura),
  KEY idx_estado_accion (estado, accion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

