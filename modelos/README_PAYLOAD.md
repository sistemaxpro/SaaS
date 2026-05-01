# FacturaTest con Payload JSON

El archivo `facturatest.php` ha sido modificado para recibir datos via payload JSON en lugar de tener los datos hardcodeados.

## Características

- ✅ Recibe datos via POST en formato JSON
- ✅ Validación de datos requeridos
- ✅ Manejo de errores robusto
- ✅ Respuestas en formato JSON
- ✅ Mantiene funcionalidad de anulación (?anular=CDC)
- ✅ Mantiene funcionalidad de consulta de lote (?lote=ID)
- ✅ Compatibilidad con datos por defecto para pruebas

## Estructura del Payload JSON

### Campos Requeridos

#### Emisor
```json
"emisor": {
  "ruc": "80062286",
  "dv": "3",
  "razon_social": "NOMBRE DE LA EMPRESA"
}
```

#### Receptor
```json
"receptor": {
  "documento": "5311558",
  "tipo_doc": "ci",
  "razon_social": "NOMBRE DEL CLIENTE"
}
```

#### Conceptos
```json
"conceptos": [
  {
    "descripcion": "Producto o servicio",
    "precio": 10000,
    "cantidad": 1
  }
]
```

### Campos Opcionales

#### Emisor (campos adicionales)
- `tipo_contribuyente`: Tipo de contribuyente
- `ciudad`: Código de ciudad
- `direccion`: Dirección del emisor
- `telefono`: Teléfono de contacto
- `email`: Email de contacto
- `act_eco`: Código de actividad económica
- `act_eco_desc`: Descripción de actividad económica

#### Receptor (campos adicionales)
- `direccion`: Dirección del receptor
- `telefono`: Teléfono del receptor
- `email`: Email del receptor
- `ciudad`: Código de ciudad

#### Conceptos (campos adicionales)
- `codigo`: Código del producto/servicio
- `tasa_iva`: Tasa de IVA (5, 10)
- `descuento`: Monto de descuento
- `proporcion_iva`: Proporción de IVA
- `unidad_medida`: Código de unidad de medida

#### Configuración de Factura
```json
"factura": {
  "ndoc": "1001",
  "condicion": "contado",
  "timbrado": "17993028",
  "fec_timbrado": "2025-04-28",
  "cod_establecimiento": "001",
  "cod_expedicion": "001",
  "moneda": "PYG",
  "cambio": 1,
  "plazo": {
    "condicion": 1,
    "valor": 12
  }
}
```

#### Formas de Pago
```json
"formas_pago": [
  {
    "tipo_pago": 1,
    "monto": 10000,
    "moneda": "PYG",
    "cambio": 1
  }
]
```

#### Configuración SIFEN
```json
"sifen_config": {
  "certificado": "80062286.p12",
  "password": "12345678",
  "ambiente": "prod",
  "idc": "1",
  "csc": "f5157Ae805c58f2eeB8eA1eB7649f864"
}
```

## Uso

### 1. Generar Factura (POST)

```bash
curl -X POST http://localhost:8000/facturatest.php \
  -H "Content-Type: application/json" \
  -d @ejemplo_payload.json
```

### 2. Anular Factura (GET)

```bash
curl "http://localhost:8000/facturatest.php?anular=CDC_DE_LA_FACTURA"
```

### 3. Consultar Lote (GET)

```bash
curl "http://localhost:8000/facturatest.php?lote=ID_DEL_LOTE"
```

### 4. Prueba sin Payload (GET)

```bash
curl "http://localhost:8000/facturatest.php"
```

## Respuestas

### Respuesta Exitosa
```json
{
  "success": true,
  "mensaje": "Factura generada exitosamente",
  "datos": {
    "id": "1108418391654346064",
    "fec_proc": "2025-01-18T10:30:00",
    "est_res": "Aprobado",
    "prot_aut": "123456789",
    "cod_res": "0260",
    "msg_res": "Procesado correctamente",
    "prot_cons_lote": "987654321",
    "tpo_proces": "1",
    "url_cons_lote": "https://sifen.set.gov.py/...",
    "qr_url": "https://sifen.set.gov.py/...",
    "qr_image": "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=..."
  }
}
```

### Respuesta de Error
```json
{
  "error": "Errores en datos del emisor",
  "detalles": [
    "Campo requerido faltante: ruc",
    "Campo requerido faltante: razon_social"
  ]
}
```

## Validaciones

- **JSON válido**: El payload debe ser un JSON válido
- **Campos requeridos**: Se validan los campos mínimos necesarios
- **Tipos de datos**: Se verifican los tipos de datos esperados
- **Estructura**: Se valida la estructura del payload

## Compatibilidad

- ✅ Compatible con el sistema SIFEN existente
- ✅ Mantiene todas las funcionalidades originales
- ✅ Funciona con datos por defecto si no se envía payload
- ✅ Respuestas consistentes en formato JSON

## Ejemplo Completo

Ver el archivo `ejemplo_payload.json` para un ejemplo completo del payload esperado.