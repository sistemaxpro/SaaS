# Carga de Datos Mock - Estación de Servicio

Este documento explica cómo cargar datos de prueba en el módulo Estación de Servicio para desarrollo.

## ⚠️ IMPORTANTE

**Estos scripts SOLO funcionan en BD de desarrollo (puerto 3307)**. Están protegidos para no ejecutarse en producción (3306).

## 1️⃣ Configuración Inicial (Primer uso)

### Paso 1: Copiar archivo de ejemplo

```bash
cp .env.mock.example .env.mock
```

### Paso 2: Editar credenciales en `.env.mock`

Abre el archivo `.env.mock` y ajusta las credenciales según tu setup:

```bash
# Si tu BD de desarrollo está en puerto 3307 con usuario "desarrollo"
MOCK_DB_HOST=localhost
MOCK_DB_PORT=3307
MOCK_DB_USER=desarrollo
MOCK_DB_PASS=desarrollo123
MOCK_DB_NAME=desarrollo
MOCK_ALLOWED_PORT=3307
```

**Opciones comunes:**

Si usas la Master DB del proyecto:
```bash
MOCK_DB_HOST=localhost
MOCK_DB_PORT=3306
MOCK_DB_USER=sistemax
MOCK_DB_PASS=Armagedon123
MOCK_DB_NAME=serproc1
MOCK_ALLOWED_PORT=3306
```

### Paso 3: Verificar setup (opcional)

```bash
php verify-mock-setup.php
```

Esto verificará que:
- El archivo SQL existe
- Puedes conectarte a la BD
- Las tablas existen
- Hay permisos de escritura

## Opción 1: Script PHP (Recomendado)

```bash
cd /var/www/html/desarrollo
php load-mock-data.php
```

**Ventajas:**
- Compatible con todos los sistemas
- Mejor manejo de errores
- Logging detallado
- Ignora statements con errores no críticos

## Opción 2: Script Bash

```bash
cd /var/www/html/desarrollo
bash load-mock-data.sh
```

## Opción 3: SQL directo desde MySQL

```bash
mysql -h localhost -P 3307 -u desarrollo -p desarrollo < database/seeds/estacion_datos_mock.sql
```

## Datos Que Se Cargan

### Infraestructura
- **2 Tanques**
  - Tanque Nafta Común (5000 L)
  - Tanque Diésel (5000 L)

- **3 Surtidores**
  - Surtidor 1, 2, 3 (con control automático/manual)

- **6 Picos** (2 por surtidor)
  - Surtidor 1: Nafta Común + Nafta Super
  - Surtidor 2: Nafta Común + Diésel
  - Surtidor 3: Nafta Premium + Diésel

### Operaciones
- **2 Turnos**
  - 1 turno cerrado (ayer)
  - 1 turno abierto (hoy)

- **8 Despachos/Ventas**
  - 5 del turno de ayer
  - 3 del turno actual

- **Histórico**
  - Lecturas de tanques (apertura/cierre)
  - Lecturas automáticas de surtidores
  - Cierre de playa (ayer)
  - Histórico de precios

## Verificar Datos Cargados

### Ver tanques
```sql
SELECT * FROM estacion_tanques;
```

### Ver surtidores
```sql
SELECT * FROM estacion_surtidores;
```

### Ver picos
```sql
SELECT id, id_surtidor, nro_pico, nombre, totalizador_actual 
FROM estacion_picos 
ORDER BY id_surtidor, nro_pico;
```

### Ver turnos abiertos
```sql
SELECT id, fecha_turno, estado, hora_apertura 
FROM estacion_turnos 
WHERE estado = 'abierto';
```

### Ver últimos despachos
```sql
SELECT d.id, d.fecha_hora, d.litros, d.monto_total, 
       c.nombre as combustible, p.nombre as pico
FROM estacion_despachos d
JOIN estacion_combustibles c ON d.id_combustible = c.id
JOIN estacion_picos p ON d.id_pico = p.id
ORDER BY d.fecha_hora DESC
LIMIT 10;
```

## Limpiar Datos Mock (si necesitas empezar de nuevo)

```sql
-- ⚠️ PELIGROSO - Solo ejecutar en desarrollo
DELETE FROM estacion_precios_historial;
DELETE FROM estacion_cierre_playa_detalle;
DELETE FROM estacion_cierre_playa;
DELETE FROM estacion_lecturas_surtidor;
DELETE FROM estacion_despachos;
DELETE FROM estacion_turno_picos;
DELETE FROM estacion_turnos;
DELETE FROM estacion_lecturas_tanque;
DELETE FROM estacion_picos;
DELETE FROM estacion_surtidores;
DELETE FROM estacion_tanques;
```

## Solución de Problemas

### "Puerto incorrecto"
- Verifica que MySQL de desarrollo está corriendo en puerto 3307
- Comprueba: `mysql -P 3307 -u desarrollo -p -e "SELECT 1;"`

### "Usuario/Contraseña incorrecto"
- Ajusta las credenciales en el script (líneas 8-11 en load-mock-data.php)
- Asegúrate que el usuario tiene permisos en la BD

### "Archivo SQL no encontrado"
- Verifica que estás en `/var/www/html/desarrollo`
- Comprueba: `ls -la database/seeds/estacion_datos_mock.sql`

### Tabla no existe
- Ejecuta las migraciones primero: ver archivo de migraciones del proyecto

## Próximos Pasos

1. ✅ Cargar datos mock
2. 🧪 Hacer login en las apps de Estación
3. 📱 Probar funcionalidades:
   - Apertura/cierre de turnos
   - Registro de despachos
   - Lecturas de tanques
   - Cierre de playa

