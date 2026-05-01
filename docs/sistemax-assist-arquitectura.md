# SistemaX Assist

Arquitectura propuesta para un sistema de asistencia remota nativo de `sistemax.pro`, sin depender de APIs de terceros para la operación principal.

## Objetivo

Construir una plataforma propia de soporte remoto para:

- equipos `Windows`, `macOS` y `Linux`
- `tablets Android` y teléfonos Android en modo asistido
- administración multiempresa desde `SistemaX`
- trazabilidad completa de solicitudes, sesiones, accesos y auditoría

## Alcance realista

### Sí es viable

- inventario de equipos
- solicitud de soporte desde SistemaX
- panel de soporte integrado
- control remoto de escritorio
- transferencia de archivos
- chat de sesión
- grabación y auditoría
- acceso desatendido bajo política

### Parcialmente viable

- soporte a `Android`
- captura de pantalla
- control remoto según permisos y fabricante
- soporte asistido con aceptación local

### No debe prometerse como capacidad general

- control remoto completo de `iPhone/iPad`
- control silencioso universal de Android sin interacción del usuario

## Principios

- `multiempresa` desde diseño
- `zero trust` entre agente, consola y backend
- `audit-first`: todo acceso debe quedar registrado
- `consent-first` por defecto
- `self-hosted` para señalización, relay, auth y storage operativo
- `fallbacks` para redes corporativas y NAT complejos

## Componentes

### 1. SistemaX Assist Agent

Agente nativo instalado en el equipo cliente.

Responsabilidades:

- registrar el dispositivo
- autenticarse contra Sistemax
- reportar presencia y salud
- capturar pantalla
- recibir eventos de mouse/teclado
- abrir chat de sesión
- subir/descargar archivos
- exponer permisos y estado del sistema
- aplicar políticas locales

Plataformas:

- `Windows`
- `macOS`
- `Linux`
- `Android` en segunda etapa

Tecnología sugerida:

- `Rust` para el core
- wrappers específicos por SO cuando haga falta

Motivo:

- binario liviano
- buen rendimiento
- mejor control de memoria
- facilidad para cross-compile

### 2. Control Plane

Backend central de Sistemax para orquestación.

Responsabilidades:

- autenticación
- autorización
- emisión de tokens cortos
- inventario de equipos
- creación y cierre de sesiones
- auditoría
- reglas de acceso por empresa
- asignación de soporte
- cola de solicitudes

Tecnología sugerida:

- `Go` o `Node.js`
- `MySQL` para persistencia principal
- `Redis` para presencia, locks y sesiones efímeras

### 3. Signaling Server

Canal de negociación entre consola y agente.

Responsabilidades:

- intercambio de SDP/ICE si se usa WebRTC
- coordinación de apertura de sesión
- renovación de sesión
- heartbeats
- entrega de eventos de control

Tecnología sugerida:

- `WebSocket`
- opcional `gRPC` interno para servicios backend

### 4. Relay / NAT Traversal

Infra para cuando cliente y soporte no pueden conectarse directo.

Responsabilidades:

- `STUN/TURN` para WebRTC
- relay TCP/UDP propio
- fallback en redes restrictivas

Tecnología sugerida:

- `coturn` para TURN inicial
- relay propio en fases posteriores si se necesita optimización

### 5. Consola Web de Soporte

Panel integrado en `sistemax.pro`.

Responsabilidades:

- ver solicitudes
- ver equipos online/offline
- buscar por empresa, usuario o host
- iniciar sesión remota
- pedir consentimiento
- ver estado de permisos
- copiar ID/clave si se usa modo híbrido durante transición
- descargar evidencias y logs

### 6. Servicio de Grabación y Auditoría

Responsabilidades:

- guardar metadata de sesión
- grabar video o eventos
- guardar hash de integridad
- registrar:
  - quién inició
  - quién aceptó
  - empresa
  - duración
  - IP
  - archivos transferidos
  - motivo de conexión

## Modos operativos

### Modo asistido

Uso recomendado por defecto.

Flujo:

1. usuario solicita soporte
2. soporte toma la solicitud
3. agente muestra consentimiento local
4. usuario acepta
5. se abre la sesión
6. se registra auditoría

### Modo desatendido

Solo para equipos autorizados.

Casos:

- cajas
- servidores internos
- kioskos
- equipos de administración

Requiere:

- política por empresa
- whitelist de técnicos
- MFA del operador
- registro reforzado

## Flujo de sesión

### Alta de dispositivo

1. el agente se instala
2. genera `device_keypair`
3. pide `device_token` al backend
4. se vincula a:
   - empresa
   - usuario
   - host
   - SO
5. queda visible en inventario

### Solicitud de soporte

1. usuario presiona `Solicitar soporte`
2. backend crea `support_request`
3. soporte recibe notificación
4. técnico toma la solicitud
5. backend crea `remote_session`

### Inicio de sesión remota

1. consola solicita conexión
2. backend valida permisos
3. signaling server notifica al agente
4. agente presenta consentimiento o aplica política
5. se negocia canal de medios/control
6. se abre sesión

### Cierre

1. operador o cliente termina sesión
2. backend marca fin
3. se guarda resumen
4. si hubo grabación, se indexa

## Protocolo lógico

### Agent -> Control Plane

- `agent.register`
- `agent.heartbeat`
- `agent.capabilities`
- `agent.permissions.status`
- `agent.session.accept`
- `agent.session.reject`
- `agent.session.end`

### Console -> Control Plane

- `support.request.list`
- `support.request.take`
- `support.session.create`
- `support.session.close`
- `support.device.list`
- `support.device.policy.update`

### Señalización

- `session.offer`
- `session.answer`
- `session.ice`
- `session.control`
- `session.file.transfer`
- `session.chat.message`

## Modelo de datos inicial

### `smx_assist_devices`

- `id`
- `id_empresa`
- `id_login`
- `device_uuid`
- `host_name`
- `platform`
- `platform_version`
- `agent_version`
- `device_public_key`
- `status`
- `last_seen_at`
- `created_at`
- `updated_at`

### `smx_assist_requests`

- `id`
- `id_empresa`
- `id_login`
- `request_type`
- `platform`
- `status`
- `notes`
- `taken_by_login`
- `resolved_by_login`
- `created_at`
- `updated_at`

### `smx_assist_sessions`

- `id`
- `request_id`
- `id_empresa`
- `support_login_id`
- `target_device_id`
- `mode`
- `status`
- `started_at`
- `ended_at`
- `duration_sec`
- `recording_path`
- `audit_hash`

### `smx_assist_session_events`

- `id`
- `session_id`
- `event_type`
- `event_payload`
- `created_at`

### `smx_assist_policies`

- `id_empresa`
- `allow_unattended`
- `require_consent`
- `allow_file_transfer`
- `allow_clipboard`
- `record_sessions`
- `allowed_support_roles`

## Seguridad

### Identidad

- cada agente con clave propia
- tokens de acceso cortos
- refresh controlado
- revocación inmediata por backend

### Transporte

- `TLS` en todos los canales
- `DTLS/SRTP` si se usa WebRTC
- cifrado extremo a extremo opcional para sesiones

### Acceso

- MFA para técnicos
- autorización por empresa
- autorización por rol
- política por dispositivo
- consentimiento explícito cuando aplique

### Auditoría

- log inmutable de sesión
- hash de eventos
- correlación con usuario y empresa
- exportación para incidencias

## Restricciones por plataforma

### Windows

Más viable para MVP.

Capacidades esperables:

- control completo
- archivos
- clipboard
- desatendido

### macOS

Viable, pero depende de permisos:

- `Screen Recording`
- `Accessibility`

### Linux

Viable en la mayoría de escritorios, con variaciones por `X11/Wayland`.

### Android

Viable en modo asistido.

Restricciones:

- confirmación local frecuente
- permisos de captura
- `Accessibility`
- diferencias por fabricante

### iOS/iPadOS

No planificar control completo.

## Roadmap sugerido

### Fase 1: MVP desktop

Duración estimada: `6 a 8 semanas`

Entregables:

- agente Windows
- inventario de equipos
- solicitud de soporte
- consola web
- sesión remota básica
- auditoría inicial

### Fase 2: hardening desktop

Duración estimada: `4 a 6 semanas`

Entregables:

- transferencia de archivos
- clipboard
- grabación
- MFA de soporte
- políticas por empresa
- macOS/Linux beta

### Fase 3: Android asistido

Duración estimada: `4 a 8 semanas`

Entregables:

- app Android agente
- permisos guiados
- solicitud desde app
- captura remota
- control donde el dispositivo lo permita

### Fase 4: operación avanzada

Duración estimada: `4 a 6 semanas`

Entregables:

- acceso desatendido administrado
- dashboards
- métricas
- alertas
- exportación de auditoría

## Recomendación de implementación

Orden sugerido:

1. `Windows desktop`
2. `Mesa de soporte y auditoría`
3. `macOS/Linux`
4. `Android asistido`

No conviene arrancar por Android.

## Estrategia de transición desde el estado actual

### Etapa A

Mantener el módulo actual de soporte como frontend operativo y reemplazar solo el branding/flujo por `RustDesk` mientras se construye `SistemaX Assist`.

### Etapa B

Agregar tabla y panel de `devices`, `requests` y `sessions` propios.

### Etapa C

Incorporar el `SistemaX Agent` para Windows y empezar operación híbrida.

### Etapa D

Eliminar dependencia operativa de herramientas externas para desktop.

## MVP exacto recomendado

Para empezar ahora:

- `agent Windows`
- `panel de soporte`
- `requests`
- `devices`
- `session create`
- `screen stream`
- `mouse/keyboard`
- `audit trail`

Eso ya valida el producto sin intentar resolver todo el universo de Android al mismo tiempo.

