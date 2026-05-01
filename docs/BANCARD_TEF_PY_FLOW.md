# Flujo Tecnico Bancard TEF (Paraguay) - POS

## Objetivo
Integrar cobro presencial con tarjeta desde el POS usando terminal/pinpad y un bridge local, con trazabilidad operativa y soporte de reverso.

## Arquitectura
1. `public/pos/index.php` (frontend POS) inicia captura de tarjeta.
2. `public/pos/api/card_terminal.php` (backend POS) normaliza request/response.
3. `Bridge local` (servicio del proveedor en la PC/caja) se comunica con terminal Bancard TEF.
4. Terminal responde autorizacion y metadatos (NSU/RRN/Lote/Autorizacion).

## Operaciones soportadas
1. `sale`: venta con tarjeta.
2. `status`: consulta estado de una transaccion.
3. `reversal`: reverso/anulacion de una transaccion.

## Variables de entorno
1. `CARD_TERMINAL_BRIDGE_URL` (requerido): URL principal de venta.
2. `CARD_TERMINAL_BRIDGE_STATUS_URL` (opcional): URL de consulta.
3. `CARD_TERMINAL_BRIDGE_REVERSAL_URL` (opcional): URL de reverso.
4. `CARD_TERMINAL_TIMEOUT` (opcional, default `45` segundos).

## Request estandar POS -> card_terminal.php
```json
{
  "action": "sale",
  "amount": 12345,
  "country": "PY",
  "integration": "pos_py",
  "processor": "bancard",
  "financing_type": "credito",
  "installments": 3,
  "id_empresa": 1,
  "id_caja": 1,
  "id_usuario": 10
}
```

## Response estandar card_terminal.php -> POS
```json
{
  "success": true,
  "message": "Tarjeta capturada correctamente",
  "data": {
    "action": "sale",
    "approved": true,
    "status": "APPROVED",
    "reference": "123456",
    "auth_code": "A1B2C3",
    "nsu": "998877",
    "rrn": "123456789012",
    "batch": "0042",
    "processor": "bancard",
    "brand": "VISA",
    "masked_pan": "4509********1234",
    "transaction_id": "abc-123",
    "installments": 3,
    "financing_type": "credito"
  }
}
```

## Reglas de negocio en POS
1. No confirmar venta tarjeta sin `reference` o `auth_code` o `nsu`.
2. Si `approved=false`, bloquear confirmacion y mostrar rechazo.
3. Guardar en venta:
   - `card_terminal_reference`
   - `card_auth_code`
   - `card_nsu`
   - `card_rrn`
   - `card_batch`
   - `card_processor`
   - `card_brand`
   - `card_masked_pan`
   - `card_installments`
   - `card_financing_type`
4. Si hay timeout de terminal:
   - ejecutar `status` por `reference/nsu`.
   - si estado incierto, habilitar `reversal` controlado.

## Flujo operativo recomendado
1. Cajero selecciona `Tarjeta`.
2. POS envia `sale` a bridge.
3. Cliente inserta/apoya tarjeta y PIN en terminal.
4. Bridge devuelve resultado.
5. POS muestra datos (Autorizacion/NSU/RRN/Lote).
6. POS confirma e imprime.

## Flujo de contingencia
1. Si `sale` timeout:
   - llamar `status` con referencia temporal o `nsu`.
2. Si `status` no concluye:
   - marcar pago `PENDIENTE_CONCILIACION`.
   - no duplicar cobro.
3. Si comercio decide anular:
   - ejecutar `reversal`.
   - registrar motivo y usuario.

## Seguridad y cumplimiento
1. No almacenar PAN completo.
2. Guardar solo `masked_pan`.
3. Loggear request/response sin datos sensibles.
4. Usar canal local seguro para bridge (localhost o red interna confiable).

## Checklist de puesta en produccion
1. Validar homologacion con bridge Bancard.
2. Probar `sale` credito/debito y cuotas.
3. Probar timeout + `status`.
4. Probar `reversal`.
5. Validar impresion y conciliacion de caja con NSU/RRN.

## Mock local para desarrollo (sin equipo fisico)
1. Levantar mock:
```bash
php -S 127.0.0.1:8099 scripts/mock_bancard_bridge.php
```
2. Configurar variables en el entorno del servidor PHP del POS:
```bash
export CARD_TERMINAL_BRIDGE_URL="http://127.0.0.1:8099/sale"
export CARD_TERMINAL_BRIDGE_STATUS_URL="http://127.0.0.1:8099/status"
export CARD_TERMINAL_BRIDGE_REVERSAL_URL="http://127.0.0.1:8099/reversal"
export CARD_TERMINAL_TIMEOUT="45"
```
3. Probar venta en POS (metodo Tarjeta + Capturar Tarjeta).
4. Probar rechazo forzado (solo mock):
```json
{"action":"sale","amount":10000,"mock_decline":true}
```
5. Probar status:
```json
{"action":"status","reference":"REF12345678"}
```
6. Probar reversal:
```json
{"action":"reversal","reference":"REF12345678","reason":"Prueba QA"}
```
