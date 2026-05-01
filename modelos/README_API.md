# Factura SIFEN - API Documentation

Este archivo `facturas_sifen.php` ha sido modificado para soportar múltiples métodos de envío de datos JSON vía curl, manteniendo la compatibilidad con la interfaz web original.

## Métodos de Uso

### 1. Interfaz Web (Navegador)
```
http://localhost/facturas_sifen.php
```
Acceso directo desde el navegador para usar la interfaz HTML.

### 2. API con JSON en el Body
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d @payload.json \
  http://localhost/facturas_sifen.php
```

### 3. API con Archivo JSON (Multipart)
```bash
curl -X POST \
  -F "payload=@payload.json" \
  http://localhost/facturas_sifen.php
```

### 4. API con JSON como String
```bash
curl -X POST \
  -d "payload=$(cat payload.json)" \
  http://localhost/facturas_sifen.php
```

### 5. API con JSON Inline
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"emisor":{...},"receptor":{...}}' \
  http://localhost/facturas_sifen.php
```

## Formato de Respuesta

### Respuesta API (JSON)
```json
{
  "success": true,
  "data": {
    "id": "01234567890123456789",
    "fec_proc": "2024-01-15T10:30:00",
    "est_res": "Aprobado",
    "prot_aut": "12345678901234567890",
    "cod_res": "0260",
    "msg_res": "Aprobado",
    "prot_cons_lote": "87654321098765432109",
    "tpo_proces": "1"
  },
  "error": null
}
```

### Respuesta Error (JSON)
```json
{
  "success": false,
  "data": null,
  "error": "Descripción del error",
  "raw_response": { ... }
}
```

## Archivo de Ejemplo

Se incluye un archivo `payload.json` con un ejemplo completo del formato esperado.

## Características

- ✅ Soporte para múltiples métodos de envío
- ✅ Detección automática de llamadas API vs navegador
- ✅ Respuestas JSON estructuradas para APIs
- ✅ Interfaz HTML para navegadores
- ✅ Manejo de errores mejorado
- ✅ Headers CORS para APIs
- ✅ Validación de JSON robusta
- ✅ Logging de debug para APIs

## Detección de Llamadas API

El sistema detecta automáticamente si es una llamada API basándose en:
- Content-Type: application/json
- User-Agent contiene "curl"
- Presencia del parámetro "payload" en $_FILES o $_POST

## Testing

```bash
# Probar con archivo de ejemplo
curl -X POST -F "payload=@payload.json" http://localhost/facturas_sifen.php

# Probar con JSON directo
curl -X POST -H "Content-Type: application/json" -d @payload.json http://localhost/facturas_sifen.php

# Probar error de validación
curl -X POST -d "payload=invalid-json" http://localhost/facturas_sifen.php
```