# Plan Completo: Estrategia POS Rápido en Producción (SIFEN Paraguay)

## 1. Objetivo de negocio y técnico

- Cobro POS rápido: p95 < 2 segundos.
- Emisión FE robusta y no bloqueante.
- Evitar cancelaciones de venta por latencia o asincronía de SIFEN.
- Trazabilidad total por factura: crear, firmar, enviar, consultar lote, resolver.

## 2. Principios del flujo final

1. La venta se guarda primero (transacción local).
2. La FE se emite en segundo plano (cola/worker).
3. La impresión FE solo ocurre cuando estado SIFEN = `Aprobado`.
4. Si está pendiente, se imprime ticket operativo (nota/ticket de control) y se continúa caja.
5. Reimpresión FE desde “Mis Ventas” solo para FE aprobadas.

## 3. Modelo de estados unificado

### 3.1 Estado en `factura_ventas.estado_sifen`
- `Guardado Local`
- `Enviando`
- `Pendiente`
- `Aprobado`
- `Rechazado`
- `Error Envío`

### 3.2 Estado en `fe.estado_transmision`
- `LOCAL`
- `ENVIANDO`
- `PENDIENTE`
- `ENVIADO`
- `RECHAZADO`
- `ERROR`

### 3.3 Reglas de consistencia
- `Aprobado` => `fe.estado_transmision = ENVIADO`
- `Rechazado` => `fe.estado_transmision = RECHAZADO`
- `Pendiente` => `fe.estado_transmision = PENDIENTE`
- `Error Envío` => `fe.estado_transmision = ERROR`

## 4. Arquitectura por componentes

## 4.1 Frontend POS
- Archivo: `public/pos/index.php`
- Responsabilidades:
  - cerrar venta rápido.
  - no bloquear caja esperando aprobación final.
  - mostrar estado FE en “Mis Ventas”.
  - permitir acciones: `Reintentar`, `Consultar estado`, `Reimprimir`.

## 4.2 API SIFEN
- Archivo: `public/pos/api/sifen_lib.php`
- Responsabilidades:
  - construir payload.
  - firmar y enviar.
  - recuperar protocolo de lote.
  - consultar lote/CDC y resolver estado final.

## 4.3 Reintento/consulta
- Archivo: `public/pos/api/sifen_retry.php`
- Responsabilidades:
  - reproceso de FE pendiente.
  - consulta por protocolo (`consult_only=1`).
  - sincronizar `factura_ventas` y `fe`.

## 4.4 Worker de cola (nuevo)
- Archivo sugerido: `cron/worker_sifen_queue.php`
- Cron recomendado: cada 1 minuto.
- Responsabilidades:
  - tomar pendientes de cola.
  - emitir/reconsultar con backoff.
  - actualizar estados.

## 5. Cambios de base de datos (Fase estructural)

Agregar cola transversal por empresa.

```sql
CREATE TABLE IF NOT EXISTS serproc1.fe_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NOT NULL,
  db_name VARCHAR(64) NOT NULL,
  id_factura INT NOT NULL,
  accion ENUM('emitir','consultar') NOT NULL DEFAULT 'emitir',
  estado ENUM('pendiente','procesando','resuelto','fallido') NOT NULL DEFAULT 'pendiente',
  prioridad TINYINT NOT NULL DEFAULT 5,
  intentos INT NOT NULL DEFAULT 0,
  max_intentos INT NOT NULL DEFAULT 8,
  next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(255) NULL,
  last_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_empresa_factura_accion (id_empresa, id_factura, accion, estado),
  KEY idx_pick (estado, next_retry_at, prioridad, id),
  KEY idx_empresa_factura (id_empresa, id_factura)
);
```

Opcional (si no existe):

```sql
ALTER TABLE empresa_XXX.factura_ventas
  ADD COLUMN IF NOT EXISTS fecha_ult_consulta_sifen DATETIME NULL;
```

## 6. Flujo operativo final (paso a paso)

## 6.1 Cobro en POS (rápido)
1. `api/venta.php` guarda venta.
2. Si tipo documento = FE:
   - marca `estado_sifen='Guardado Local'`.
   - encola en `serproc1.fe_queue` acción `emitir`.
3. POS imprime ticket operativo y vuelve a caja.

## 6.2 Emisión asíncrona
1. Worker toma cola (`pendiente` + `next_retry_at <= NOW()`).
2. Ejecuta emisión en `sifen_lib.php`.
3. Resultado:
   - Aprobado: cierra cola (`resuelto`).
   - Pendiente: reprograma (`next_retry_at`).
   - Rechazado: `resuelto`.
   - Error temporal: incrementa intentos y backoff.

## 6.3 Consulta por protocolo/CDC
- Si hay `prot_cons_lote_sifen`: consulta lote.
- Si lote no resuelve: consulta por CDC (`siConsDE`).
- Si CDC resuelve, prevalece sobre lote.

## 7. Política de reintentos (backoff)

Intentos y espera:
- 1: +10s
- 2: +30s
- 3: +60s
- 4: +5m
- 5: +15m
- 6+: +30m

Regla:
- si `intentos >= max_intentos` -> `fallido` y alerta operativa.

## 8. Reglas de impresión

- Impresión automática FE:
  - solo si `estado_sifen='Aprobado'`.
- Reimpresión desde lista:
  - FE aprobada: imprimir FE.
  - FE pendiente/rechazada: bloquear con mensaje claro.
- Ticket común:
  - siempre disponible.

## 9. Manejo de casos especiales

## 9.1 “Lote recibido con éxito”
- No es error.
- Queda en `Pendiente`.
- No cancelar ni revertir venta.

## 9.2 “Documento electrónico duplicado”
- No reemitir.
- Consultar por CDC.
- Resolver a `Aprobado`/`Rechazado`/`Pendiente`.

## 9.3 Timeout de SIFEN
- Mantener venta.
- Pasar FE a cola con reintento.
- UI: “Venta registrada. FE en proceso”.

## 10. Plan de implementación por fases

## Fase A (rápida, 1-2 días)
- Ajustar UX de POS para no bloquear caja.
- Unificar mensajes.
- Garantizar reimpresión FE solo aprobada.

Archivos:
- `public/pos/index.php`

## Fase B (2-3 días)
- Crear `fe_queue`.
- Implementar worker `cron/worker_sifen_queue.php`.
- Endpoint interno para procesar un item de cola.

Archivos:
- `database/migrations/007_fe_queue.sql`
- `cron/worker_sifen_queue.php`
- `public/pos/api/sifen_retry.php`
- `public/pos/api/sifen_lib.php`

## Fase C (2 días)
- Panel operativo de FE (pendientes/rechazadas).
- Métricas y alertas.

Archivos:
- `public/panel/index.php`
- `public/pos/api/` (endpoint de métricas)

## 11. Cron recomendado

Ejemplo:

```bash
* * * * * /usr/bin/php /var/www/html/sistemaxpro/cron/worker_sifen_queue.php >> /var/www/html/sistemaxpro/logs/worker_sifen_queue.log 2>&1
```

Si hay mucho volumen:
- 2 workers en paralelo con límite por lote.
- lock para evitar doble procesamiento del mismo registro.

## 12. Checklist de despliegue en producción

1. Backup DB (`serproc1` + `empresa_*`).
2. Ejecutar migración `fe_queue`.
3. Deploy backend (`sifen_lib.php`, `sifen_retry.php`).
4. Deploy frontend (`pos/index.php`).
5. Activar cron worker.
6. Verificar 10 ventas FE reales:
   - 3 aprobadas rápido
   - 3 pendientes y luego aprobadas
   - 2 duplicadas
   - 2 rechazadas
7. Medir KPIs primeras 24h.

## 13. KPIs de aceptación

- p95 tiempo cobro POS < 2s.
- 95% FE aprobadas < 60s.
- pendientes > 15 min < 2%.
- cancelaciones por FE = 0.
- reimpresiones fallidas FE aprobada = 0.

## 14. Decisiones operativas

- No revertir venta si SIFEN ya recibió lote.
- Priorizar continuidad de caja sobre sincronía inmediata de FE.
- Resolver asincronía con cola + consultas por protocolo/CDC.

## 15. Próximo entregable técnico

Implementar Fase B completa:
- `database/migrations/007_fe_queue.sql`
- `cron/worker_sifen_queue.php`
- endpoints de enqueue/proceso
- pruebas de carga de 100 ventas FE consecutivas.

