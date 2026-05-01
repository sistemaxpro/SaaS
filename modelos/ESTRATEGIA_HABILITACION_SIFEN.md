# Estrategia de Habilitación SIFEN - Establecimientos y Puntos de Expedición

## Estructura de Datos

### Tabla Principal: `habilitacion_sifen`

Contiene los datos generales del contribuyente y timbrado:

- `id_empresa` → Relación con tabla `empresa`
- `numero_timbrado` → Número de timbrado habilitado
- `fecha_inicio_vigencia` → Fecha de inicio del timbrado
- `csc`, `id_csc`, `cert_pass`, `cert_path` → Credenciales SIFEN
- `ambiente` → TEST o PROD

### Tabla de Documentos: `habilitacion_sifen_documentos`

Define qué tipos de documento están habilitados y sus puntos de expedición:

| Campo                    | Descripción                         |
| ------------------------ | ----------------------------------- |
| `id_habilitacion`        | FK a `habilitacion_sifen.id`        |
| `codigo_establecimiento` | Código 3 dígitos (ej: "001", "002") |
| `punto_expedicion`       | Código 3 dígitos (ej: "001")        |
| `tipo_documento`         | Código SIFEN del documento          |
| `activo`                 | Estado de la habilitación           |

### Tipos de Documento SIFEN

| Código | Descripción                  | Constante PHP |
| ------ | ---------------------------- | ------------- |
| 1      | Factura Electrónica          | `TIPO_DOC_FE` |
| 4      | Autofactura Electrónica      | `TIPO_DOC_AF` |
| 5      | Nota de Crédito Electrónica  | `TIPO_DOC_NC` |
| 6      | Nota de Débito Electrónica   | `TIPO_DOC_ND` |
| 7      | Nota de Remisión Electrónica | `TIPO_DOC_NR` |

---

## Query de Obtención de Datos

```php
// Constante según tipo de documento
define('TIPO_DOC_NC', 5);  // Para NC
define('TIPO_DOC_ND', 6);  // Para ND
define('TIPO_DOC_NR', 7);  // Para NR
define('TIPO_DOC_FE', 1);  // Para Facturas

// Query para obtener timbrado y puntos de expedición
$sqlHabilitacion = "
SELECT
    h.numero_timbrado,
    h.fecha_inicio_vigencia,
    h.csc AS hab_csc,
    h.id_csc AS hab_id_csc,
    h.cert_pass AS hab_cert_pass,
    h.cert_path AS hab_cert_path,
    h.ambiente,
    d.codigo_establecimiento,
    d.punto_expedicion
FROM habilitacion_sifen h
JOIN habilitacion_sifen_documentos d ON d.id_habilitacion = h.id
WHERE h.id_empresa = :id_empresa
  AND d.tipo_documento = :tipo_documento
  AND h.activo = 1
  AND d.activo = 1
ORDER BY h.id DESC
LIMIT 1
";
```

---

## Ejemplo de Datos (Empresa 1040)

```
habilitacion_sifen:
├── id: 4
├── id_empresa: 1040
├── ruc: 80105122
├── numero_timbrado: 18245227
└── ambiente: PROD

habilitacion_sifen_documentos:
├── Factura (tipo=1):      001-001, 002-001
├── NC (tipo=5):           001-001, 002-001
├── ND (tipo=6):           001-001
└── NR (tipo=7):           001-001, 002-001
```

---

## Archivos Modificados

### nc_sifen.php / nd_sifen.php

- ✅ Consulta `habilitacion_sifen_documentos` según tipo de documento
- ✅ Obtiene `codigo_establecimiento` y `punto_expedicion` correctamente formateados
- ✅ Prioriza datos de `habilitacion_sifen` sobre `empresa`

### nc_api.php

- ✅ Fallback con `str_pad()` si llegan valores vacíos
- ✅ Debug logging para verificar valores

---

## Ventajas de Esta Estrategia

1. **Separación por tipo de documento**: Cada tipo puede tener diferente establecimiento/expedición
2. **Múltiples sucursales**: Soporta diferentes establecimientos (001, 002, etc.)
3. **Configuración centralizada**: Todo en `habilitacion_sifen` + `habilitacion_sifen_documentos`
4. **No depende de tabla `empresa`**: Los datos de timbrado vienen de la habilitación SIFEN
5. **Auditable**: Cada documento tiene su propia configuración activa/inactiva

---

## Pendientes

- [ ] Aplicar misma estrategia a `facturas_api.php` / `facturas.php`
- [ ] Aplicar a `nr_sifen.php` para Notas de Remisión
- [ ] Crear interfaz de administración de `habilitacion_sifen_documentos`
