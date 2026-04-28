# GUÍA RÁPIDA: Cargar Datos Mock de Estación

## 🚀 Inicio Rápido (3 pasos)

### 1. Crear archivo de configuración

```bash
cp .env.mock.example .env.mock
```

### 2. Editar `.env.mock` con tus credenciales

Abre el archivo y ajusta:

```bash
# Si tu BD dev está en puerto 3307
MOCK_DB_HOST=localhost
MOCK_DB_PORT=3307
MOCK_DB_USER=desarrollo
MOCK_DB_PASS=desarrollo123
MOCK_DB_NAME=desarrollo
MOCK_ALLOWED_PORT=3307
```

### 3. Ejecutar el script de carga

```bash
php load-mock-data.php
```

**✅ Listo!** Verás algo como:

```
✓ Conectado a BD: desarrollo@localhost:3307
====================================================
✓ Insertados datos en: estacion_tanques
✓ Insertados datos en: estacion_surtidores
✓ Insertados datos en: estacion_picos
✓ Insertados datos en: estacion_turnos
✓ Insertados datos en: estacion_despachos
✓ Insertados datos en: estacion_cierre_playa
...
✅ Carga completada: 20 statements ejecutados
```

---

## 🔍 Verificar Antes de Ejecutar

Si algo está mal, usa esto para diagnosticar:

```bash
php verify-mock-setup.php
```

Te dirá exactamente qué falta.

---

## 📊 ¿Qué se carga?

El script automáticamente inserta:

| Elemento | Cantidad |
|----------|----------|
| Tanques | 2 |
| Surtidores | 3 |
| Picos | 6 |
| Turnos | 2 |
| Despachos | 8 |
| Lecturas | 12+ |
| Cierres | 1 |

Con datos realistas para empezar a hacer pruebas inmediato.

---

## ❌ Limpiar Datos (Empezar de Nuevo)

Si necesitas eliminar todos los datos mock y cargar nuevamente:

```bash
php load-mock-data.php
```

El script usa `INSERT ... ON DUPLICATE KEY UPDATE`, así que es seguro ejecutar múltiples veces.

O si quieres limpiar completamente:

```bash
mysql -h localhost -P 3307 -u desarrollo -p desarrollo -e "
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
"
```

---

## 🔐 Seguridad

Los scripts están protegidos:
- ✅ Solo funcionan si el puerto es 3307 (o el que especifiques)
- ✅ Requieren credenciales correctas
- ✅ Protegidos contra ejecución accidental en producción
- ✅ Se pueden ejecutar múltiples veces sin riesgo

---

## 📚 Alternativas

### Script bash
```bash
bash load-mock-data.sh
```

### SQL directo
```bash
mysql -h localhost -P 3307 -u desarrollo -p < database/seeds/estacion_datos_mock.sql
```

---

## ❓ Troubleshooting

**"Access denied for user"**
- Verifica usuario/contraseña en `.env.mock`
- Comprueba que MySQL está corriendo en ese puerto

**"Table doesn't exist"**
- Las migraciones no han sido ejecutadas
- Ejecuta las migraciones primero

**"Connection refused"**
- MySQL no está corriendo en ese puerto
- Verifica: `mysql -h localhost -P 3307 -e "SELECT 1;"`

**Script no hace nada**
- Verifica que el archivo `.env.mock` existe
- Ejecuta `php verify-mock-setup.php` para diagnosticar

