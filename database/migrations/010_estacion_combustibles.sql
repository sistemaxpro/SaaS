-- Catálogo de combustibles y productos de estación de servicio
CREATE TABLE estacion_combustibles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  unidad_medida ENUM('L', 'm3', 'kg') NOT NULL DEFAULT 'L',
  precio_venta DECIMAL(10, 2) NOT NULL DEFAULT 0,
  precio_costo DECIMAL(10, 2) NOT NULL DEFAULT 0,
  color_hex VARCHAR(7),
  activo ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos iniciales de combustibles
INSERT INTO estacion_combustibles (nombre, unidad_medida, color_hex, activo) VALUES
('Nafta Común', 'L', '#FFD700', 'Y'),
('Nafta Super', 'L', '#FF6347', 'Y'),
('Nafta Premium', 'L', '#1E90FF', 'Y'),
('Diésel/Gas Oil', 'L', '#8B4513', 'Y'),
('GNV (Gas Natural Vehicular)', 'm3', '#32CD32', 'Y'),
('GLP (Gas Licuado de Petróleo)', 'kg', '#FF4500', 'Y')
ON DUPLICATE KEY UPDATE activo='Y';
