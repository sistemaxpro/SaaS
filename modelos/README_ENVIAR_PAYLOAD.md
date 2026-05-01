# 📋 Enviar Payload SIFEN - Guía de Uso

## 🎯 Descripción

El archivo `enviar_payload.php` es un script completo para crear y enviar payloads JSON al sistema SIFEN a través de `facturatest.php`. Incluye ejemplos prácticos y manejo completo de errores.

## 🚀 Características

- ✅ **Payload Completo**: Construye un JSON con todos los datos necesarios para SIFEN
- ✅ **Envío via cURL**: Comunicación HTTP robusta con manejo de errores
- ✅ **Múltiples Ejemplos**: Payload completo, mínimo y consultas de lote
- ✅ **Respuestas Formateadas**: Salida legible con emojis y colores
- ✅ **Validación**: Verificación de respuestas HTTP y JSON
- ✅ **Documentación**: Comentarios explicativos en español

## 📦 Estructura del Payload

### 1. Datos del Emisor
```json
{
  "emisor": {
    "ruc": "80062286",
    "dv": "3",
    "razon_social": "TRANSPARAGUAY LOGISTICS SOCIEDAD ANONIMA",
    "tipo_contribuyente": "2",
    "ciudad": 1,
    "direccion": "Ayolas 123, Centro",
    "telefono": "0981123456",
    "email": "facturacion@transparaguay.com.py",
    "act_eco": "49231",
    "act_eco_desc": "TRANSPORTE TERRESTRE LOCAL DE CARGA"
  }
}
```

### 2. Datos del Receptor
```json
{
  "receptor": {
    "documento": "5311558",
    "tipo_doc": "ci",
    "razon_social": "YENNY BEATRIZ PAREDES GONZALEZ",
    "direccion": "Barrio San Pablo, Calle Principal 456",
    "telefono": "0961268274",
    "email": "yenny.paredes@email.com",
    "ciudad": 1
  }
}
```

### 3. Conceptos/Productos
```json
{
  "conceptos": [
    {
      "codigo": "SERV001",
      "descripcion": "Servicio de Courier Aéreo Nacional",
      "precio": 15000,
      "cantidad": 1,
      "tasa_iva": 10,
      "descuento": 0,
      "proporcion_iva": 25,
      "unidad_medida": "77"
    }
  ]
}
```

### 4. Configuración de Factura
```json
{
  "factura": {
    "ndoc": "1005",
    "condicion": "contado",
    "timbrado": "17993028",
    "fec_timbrado": "2025-04-28",
    "cod_establecimiento": "001",
    "cod_expedicion": "001",
    "moneda": "PYG",
    "cambio": 1,
    "plazo": {
      "condicion": 1,
      "valor": 30
    }
  }
}
```

### 5. Formas de Pago
```json
{
  "formas_pago": [
    {
      "tipo_pago": 1,
      "monto": 25500,
      "moneda": "PYG",
      "cambio": 1
    }
  ]
}
```

### 6. Configuración SIFEN
```json
{
  "sifen_config": {
    "certificado": "certificados/80062286.p12",
    "password": "12345678",
    "ambiente": "prod",
    "idc": "1",
    "csc": "f5157Ae805c58f2eeB8eA1eB7649f864"
  }
}
```

## 🔧 Uso del Script

### Ejecución Básica
```bash
php enviar_payload.php
```

### Requisitos Previos
1. **Servidor PHP**: Debe estar ejecutándose `facturatest.php`
   ```bash
   php -S localhost:8000
   ```

2. **Certificados**: Verificar que existan los archivos de certificado
   ```bash
   ls -la certificados/
   ```

3. **Permisos**: Asegurar permisos de lectura en archivos

## 📊 Tipos de Respuesta

### ✅ Respuesta Exitosa
```json
{
  "success": true,
  "mensaje": "Factura generada exitosamente",
  "datos": {
    "id": "1108418391654810732",
    "fec_proc": "2025-08-18T09:35:09-03:00",
    "est_res": null,
    "cod_res": "0300",
    "msg_res": "Lote recibido con éxito",
    "qr_url": "https://..."
  }
}
```

### ❌ Respuesta de Error
```json
{
  "success": false,
  "error": "Descripción del error",
  "detalles": "Información adicional del error"
}
```

## 🧪 Ejemplos Incluidos

### 1. Payload Completo
- Incluye todos los campos requeridos y opcionales
- Múltiples conceptos con diferentes tasas de IVA
- Configuración completa de SIFEN

### 2. Payload Mínimo
- Solo campos esenciales
- Ideal para pruebas rápidas
- Usa valores por defecto

### 3. Consulta de Lote
- Verifica el estado de un lote procesado
- Usa parámetros GET
- Ejemplo de consulta de estado

## 🔍 Códigos de Respuesta SIFEN

| Código | Descripción |
|--------|-------------|
| 0300   | Lote recibido con éxito |
| 0301   | Lote procesado con éxito |
| 0302   | Lote procesado con errores |
| 0303   | Lote rechazado |

## 🛠️ Personalización

### Modificar Endpoint
```php
$url_endpoint = 'http://tu-servidor.com/facturatest.php';
```

### Cambiar Datos del Emisor
```php
$datos_emisor = [
    'ruc' => 'TU_RUC',
    'razon_social' => 'TU EMPRESA',
    // ... otros campos
];
```

### Agregar Más Conceptos
```php
$conceptos[] = [
    'codigo' => 'NUEVO001',
    'descripcion' => 'Nuevo Producto',
    'precio' => 50000,
    'cantidad' => 2
];
```

## 🚨 Solución de Problemas

### Error de Certificado
```
file_get_contents(80062286.p12): Failed to open stream
```
**Solución**: Verificar ruta del certificado en `sifen_config`

### Error de Conexión
```
cURL error: Connection refused
```
**Solución**: Verificar que el servidor PHP esté ejecutándose

### Error de JSON
```
JSON decode error
```
**Solución**: Verificar formato del payload

## 📝 Notas Importantes

- ⚠️ **Ambiente**: Cambiar `ambiente` de "test" a "prod" para producción
- 🔐 **Certificados**: Mantener seguros los archivos .p12
- 💰 **Montos**: Todos los montos deben estar en guaraníes
- 📅 **Fechas**: Usar formato ISO 8601 (YYYY-MM-DD)
- 🏢 **RUC**: Verificar que el RUC esté activo en SIFEN

## 📞 Soporte

Para más información sobre la integración con SIFEN:
- 📖 Documentación oficial de SIFEN
- 🌐 Portal del contribuyente
- 📧 Soporte técnico de SIFEN

---

**Versión**: 1.0  
**Fecha**: Agosto 2025  
**Autor**: Sistema SIFEN PHP