# Android Agent - Reemplazo de RawBT

Fecha: 2026-02-24

## Objetivo

Reemplazar `rawbt:base64,...` por app nativa propia con esquema:

- `sistemaxagent://print?data=<BASE64_ESC_POS>`

## Contrato mínimo Android

1. Registrar `intent-filter` para esquema `sistemaxagent`.
2. Leer parámetro `data` (base64 ESC/POS).
3. Decodificar y enviar a impresora Bluetooth/USB.
4. Mostrar confirmación simple y registrar log local.

## Integración web móvil

El POS móvil enviará impresión con:

```js
window.location.href = `sistemaxagent://print?data=${encodeURIComponent(base64)}`;
```

## Fallback

- Si la app no está instalada: fallback a vista PDF/manual.

## Seguridad

- Limitar tamaño máximo payload.
- Validar base64 antes de imprimir.
- Registrar hash del job para auditoría.

## Implementación (MVP actual)

- Código Android nativo: `agent/android`
- Paquete app: `pro.sistemax.agent`
- Soporta:
  - `sistemaxagent://print?data=<BASE64_ESC_POS>`
  - compatibilidad `rawbt:base64,<BASE64_ESC_POS>`
- Envío Bluetooth clásico (SPP) a impresora emparejada seleccionada en la app.
