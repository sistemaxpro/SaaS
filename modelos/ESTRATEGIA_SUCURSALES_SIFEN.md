# Estrategia de Establecimientos y Puntos de Expedición por Sucursal

## Descripción General

El sistema ahora permite configurar múltiples sucursales, cada una con su propio **establecimiento** y **punto de expedición** para SIFEN.

```
Empresa
  └─ Habilitación SIFEN (configuración general)
      ├─ Sucursal 1 → Establecimiento 001
      │   ├─ Caja 1 → Punto 001
      │   ├─ Caja 2 → Punto 002
      │   └─ Caja 3 → Punto 003
      ├─ Sucursal 2 → Establecimiento 002
      │   ├─ Caja 1 → Punto 001
      │   └─ Caja 2 → Punto 002
      └─ Sucursal 3 → Establecimiento 003
          └─ Caja 1 → Punto 001
```

## Tablas de Base de Datos

### `serproc1.habilitacion_sifen_sucursales`

Relaciona sucursales con establecimientos SIFEN:

```sql
CREATE TABLE habilitacion_sifen_sucursales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_habilitacion INT NOT NULL,          -- FK a habilitacion_sifen
    id_sucursal INT NOT NULL,              -- Sucursal de la empresa
    codigo_establecimiento VARCHAR(3),     -- Código SIFEN (001-999)
    punto_expedicion_defecto VARCHAR(3),   -- Punto por defecto para esa sucursal
    descripcion VARCHAR(255),
    activo TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (id_habilitacion, codigo_establecimiento)
);
```

### `serproc1.habilitacion_sifen_puntos_expedicion`

Múltiples puntos de expedición (cajas/POS) por sucursal:

```sql
CREATE TABLE habilitacion_sifen_puntos_expedicion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_habilitacion_sucursal INT NOT NULL,  -- FK a habilitacion_sifen_sucursales
    codigo_punto VARCHAR(3),                 -- Código SIFEN (001-999)
    descripcion VARCHAR(255),                -- Ej: "Caja 1", "TPV Mostrador"
    activo TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (id_habilitacion_sucursal, codigo_punto)
);
```

## Flujo de Procesamiento

### 1. Creación de Tabla (Automática)

Cuando se accede a `habilitacion_sifen.php`, las tablas se crean automáticamente si no existen.

```php
// habilitacion_sifen.php - línea 44
crearTablasHabilitacionSifen($pdo, $masterDb);
```

### 2. Lectura de Configuración

En `nr_sifen_api.php`, la función `getEstablecimientoByRemision()` obtiene el establecimiento/punto:

```php
function getEstablecimientoByRemision($pdo, $masterDb, $habilitacion, $nr)
{
    // Si la NR tiene id_sucursal, obtiene de habilitacion_sifen_sucursales
    // Si no, usa valores por defecto (001-001)
}
```

### 3. Envío a SIFEN

El número de documento se forma con:

```
001-001-0000001
 ↑   ↑   ↑
 |   |   └─ Número secuencial de la remisión
 |   └───── Punto de expedición de esa sucursal
 └───────── Establecimiento de esa sucursal
```

## API para Gestionar Sucursales

Archivo: `api_habilitacion_sucursales.php`

### Endpoints

#### Listar Sucursales

```
GET /api_habilitacion_sucursales.php?action=listSucursales&id_empresa=169
```

Retorna todas las sucursales configuradas para una empresa.

#### Guardar Sucursal

```
POST /api_habilitacion_sucursales.php?action=saveSucursal&id_empresa=169
Content-Type: application/json

{
    "id_sucursal": 1,
    "codigo_establecimiento": "001",
    "punto_expedicion_defecto": "001",
    "descripcion": "Sucursal Centro"
}
```

#### Eliminar Sucursal

```
DELETE /api_habilitacion_sucursales.php?action=deleteSucursal&id_empresa=169&id_sucursal=1
```

#### Listar Puntos de Expedición

```
GET /api_habilitacion_sucursales.php?action=listPuntos&id_habilitacion_sucursal=5
```

#### Guardar Punto

```
POST /api_habilitacion_sucursales.php?action=savePunto
Content-Type: application/json

{
    "id_habilitacion_sucursal": 5,
    "codigo_punto": "002",
    "descripcion": "Caja 2"
}
```

#### Eliminar Punto

```
DELETE /api_habilitacion_sucursales.php?action=deletePunto&id=10
```

## Estructura de nota_remision

Debe tener el campo `id_sucursal`:

```sql
ALTER TABLE nota_remision ADD COLUMN id_sucursal INT(11) NULL;
```

Cuando se envía una NR a SIFEN:

1. Se lee `id_sucursal` de la remisión
2. Se obtiene `codigo_establecimiento` y `punto_expedicion_defecto` de `habilitacion_sifen_sucursales`
3. Se usa para formar el número de documento

Si `id_sucursal` es NULL, se usan los valores por defecto (001-001).

## Ejemplo de Uso Completo

### 1. Configurar sucursal en BD

```php
$pdo->exec("INSERT INTO serproc1.habilitacion_sifen_sucursales
    (id_habilitacion, id_sucursal, codigo_establecimiento, punto_expedicion_defecto, descripcion)
    VALUES (1, 1, '001', '001', 'Sucursal Centro')");
```

### 2. Crear nota de remisión con sucursal

```sql
INSERT INTO smx_169.nota_remision
    (id_empresa, nro_documento, id_sucursal, fecha, ...)
    VALUES (169, 1, 1, '2026-01-28', ...);
```

### 3. Enviar a SIFEN

```php
// El sistema obtiene automáticamente:
// - Establecimiento: 001 (de habilitacion_sifen_sucursales)
// - Punto: 001 (de habilitacion_sifen_sucursales)
// - Forma número: 001-001-0000001

POST /nr_sifen_api.php?action=enviar&id=123&id_empresa=169
```

## Cambios en nr_sifen_api.php

### Nueva función: `getEstablecimientoByRemision()`

- Obtiene establecimiento/punto por sucursal
- Ubicación: línea 155-180
- Usa fallback a 001-001 si no encuentra configuración

### Actualizado: `enviarSifen()`

- Usa `getEstablecimientoByRemision()` en lugar de valores fijos
- Línea 364: `$estabInfo = getEstablecimientoByRemision(...)`

### Actualizado: `generarXML()`

- También usa `getEstablecimientoByRemision()`
- Línea 714

## Ventajas

✅ **Escalabilidad**: Múltiples sucursales con diferentes códigos  
✅ **Flexibilidad**: Cada sucursal puede tener varios puntos  
✅ **Auditoría**: Historial de cambios en establecimientos  
✅ **Validación**: Evita números de documento duplicados  
✅ **Sincronización**: Fácil de actualizar sin código

## Próximos Pasos

1. Agregar interfaz gráfica en `habilitacion_sifen.php` para gestionar sucursales
2. Agregar campo `id_sucursal` a `nota_remision` si no existe
3. Importar sucursales existentes a `habilitacion_sifen_sucursales`
4. Configurar establecimiento/punto para cada sucursal
