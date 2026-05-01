# SistemaX Assist MVP

Esquema SQL inicial y contrato API propuesto para la primera versión operativa.

## Objetivo del MVP

Cubrir soporte remoto inicial para `Windows desktop` con:

- inventario de dispositivos
- solicitudes de soporte
- creación de sesiones
- auditoría base
- presencia del agente
- permisos y capacidades

## Convenciones

- Base de datos: `MASTER_DB`
- Prefijo de tablas: `smx_assist_`
- Respuesta API:
  - `ok: true|false`
  - `error: string|null`
  - `data: object|array|null`

## SQL inicial

```sql
CREATE TABLE IF NOT EXISTS smx_assist_devices (
    id BIGINT NOT NULL AUTO_INCREMENT,
    id_empresa INT NOT NULL,
    id_login INT NOT NULL,
    login_name VARCHAR(120) DEFAULT NULL,
    user_name VARCHAR(180) DEFAULT NULL,
    company_name VARCHAR(190) DEFAULT NULL,
    device_uuid CHAR(36) NOT NULL,
    device_name VARCHAR(190) NOT NULL,
    host_name VARCHAR(190) DEFAULT NULL,
    platform VARCHAR(40) NOT NULL,
    platform_version VARCHAR(80) DEFAULT NULL,
    architecture VARCHAR(40) DEFAULT NULL,
    agent_version VARCHAR(50) DEFAULT NULL,
    agent_channel VARCHAR(20) DEFAULT NULL,
    device_public_key TEXT DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'offline',
    ip_public VARCHAR(64) DEFAULT NULL,
    ip_local VARCHAR(64) DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_device_uuid (device_uuid),
    KEY idx_empresa_status (id_empresa, status, last_seen_at),
    KEY idx_login (id_login),
    KEY idx_platform (platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smx_assist_device_capabilities (
    id BIGINT NOT NULL AUTO_INCREMENT,
    device_id BIGINT NOT NULL,
    can_screen_capture TINYINT(1) NOT NULL DEFAULT 0,
    can_input_control TINYINT(1) NOT NULL DEFAULT 0,
    can_file_transfer TINYINT(1) NOT NULL DEFAULT 0,
    can_clipboard_sync TINYINT(1) NOT NULL DEFAULT 0,
    can_audio_stream TINYINT(1) NOT NULL DEFAULT 0,
    can_unattended TINYINT(1) NOT NULL DEFAULT 0,
    requires_local_consent TINYINT(1) NOT NULL DEFAULT 1,
    permissions_screen TINYINT(1) NOT NULL DEFAULT 0,
    permissions_accessibility TINYINT(1) NOT NULL DEFAULT 0,
    permissions_input_monitoring TINYINT(1) NOT NULL DEFAULT 0,
    raw_payload JSON DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_device_caps (device_id),
    CONSTRAINT fk_assist_caps_device FOREIGN KEY (device_id) REFERENCES smx_assist_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smx_assist_requests (
    id BIGINT NOT NULL AUTO_INCREMENT,
    id_empresa INT NOT NULL,
    id_login INT NOT NULL,
    login_name VARCHAR(120) DEFAULT NULL,
    user_name VARCHAR(180) DEFAULT NULL,
    source_device_id BIGINT DEFAULT NULL,
    request_type VARCHAR(30) NOT NULL DEFAULT 'remote_support',
    platform VARCHAR(40) DEFAULT NULL,
    app_version VARCHAR(50) DEFAULT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    taken_by_login INT DEFAULT NULL,
    taken_by_name VARCHAR(180) DEFAULT NULL,
    resolved_by_login INT DEFAULT NULL,
    resolved_by_name VARCHAR(180) DEFAULT NULL,
    resolution_notes TEXT DEFAULT NULL,
    taken_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_empresa_status (id_empresa, status, created_at),
    KEY idx_login_status (id_login, status, created_at),
    KEY idx_taken (taken_by_login, status),
    CONSTRAINT fk_assist_request_device FOREIGN KEY (source_device_id) REFERENCES smx_assist_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smx_assist_sessions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    request_id BIGINT DEFAULT NULL,
    id_empresa INT NOT NULL,
    support_login_id INT NOT NULL,
    support_login_name VARCHAR(180) DEFAULT NULL,
    target_device_id BIGINT NOT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'assisted',
    status VARCHAR(20) NOT NULL DEFAULT 'created',
    started_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    duration_sec INT DEFAULT NULL,
    consent_required TINYINT(1) NOT NULL DEFAULT 1,
    consent_granted_at DATETIME DEFAULT NULL,
    recording_enabled TINYINT(1) NOT NULL DEFAULT 0,
    recording_path VARCHAR(255) DEFAULT NULL,
    transport_type VARCHAR(20) DEFAULT NULL,
    relay_used TINYINT(1) NOT NULL DEFAULT 0,
    close_reason VARCHAR(40) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_empresa_status (id_empresa, status, created_at),
    KEY idx_support (support_login_id, status),
    KEY idx_device (target_device_id, status),
    CONSTRAINT fk_assist_session_request FOREIGN KEY (request_id) REFERENCES smx_assist_requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_assist_session_device FOREIGN KEY (target_device_id) REFERENCES smx_assist_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smx_assist_session_events (
    id BIGINT NOT NULL AUTO_INCREMENT,
    session_id BIGINT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_level VARCHAR(20) NOT NULL DEFAULT 'info',
    actor_type VARCHAR(20) DEFAULT NULL,
    actor_login_id INT DEFAULT NULL,
    payload JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_session_time (session_id, created_at),
    KEY idx_event_type (event_type, created_at),
    CONSTRAINT fk_assist_event_session FOREIGN KEY (session_id) REFERENCES smx_assist_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smx_assist_policies (
    id BIGINT NOT NULL AUTO_INCREMENT,
    id_empresa INT NOT NULL,
    allow_unattended TINYINT(1) NOT NULL DEFAULT 0,
    require_consent TINYINT(1) NOT NULL DEFAULT 1,
    allow_file_transfer TINYINT(1) NOT NULL DEFAULT 1,
    allow_clipboard_sync TINYINT(1) NOT NULL DEFAULT 1,
    record_sessions TINYINT(1) NOT NULL DEFAULT 1,
    allowed_support_roles JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_empresa_policy (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smx_assist_agent_tokens (
    id BIGINT NOT NULL AUTO_INCREMENT,
    device_id BIGINT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_scope VARCHAR(50) NOT NULL DEFAULT 'agent',
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token_hash (token_hash),
    KEY idx_device_expires (device_id, expires_at),
    CONSTRAINT fk_assist_token_device FOREIGN KEY (device_id) REFERENCES smx_assist_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Estados recomendados

### Requests

- `pending`
- `taken`
- `resolved`
- `rejected`

### Devices

- `online`
- `offline`
- `disabled`

### Sessions

- `created`
- `waiting_consent`
- `active`
- `ended`
- `failed`
- `cancelled`

## API HTTP inicial

Base sugerida:

- `/public/api/assist.php`

Autenticación:

- usuario web: sesión SistemaX
- agente: bearer token emitido por backend

## Endpoints web de soporte

### `GET /public/api/assist.php?action=admin_devices`

Lista de dispositivos visibles para soporte.

Query params:

- `q`
- `id_empresa`
- `status`
- `platform`

Response:

```json
{
  "ok": true,
  "data": [
    {
      "id": 10,
      "id_empresa": 169,
      "device_name": "PC-CAJA-01",
      "platform": "windows",
      "status": "online",
      "last_seen_at": "2026-03-25 15:00:00"
    }
  ]
}
```

### `GET /public/api/assist.php?action=admin_requests`

Lista de solicitudes.

Query params:

- `status`
- `q`
- `id_empresa`

### `POST /public/api/assist.php`

Acción: `admin_take_request`

Body:

```json
{
  "action": "admin_take_request",
  "request_id": 25
}
```

### `POST /public/api/assist.php`

Acción: `admin_resolve_request`

Body:

```json
{
  "action": "admin_resolve_request",
  "request_id": 25,
  "status": "resolved",
  "resolution_notes": "Incidencia atendida"
}
```

### `POST /public/api/assist.php`

Acción: `admin_create_session`

Body:

```json
{
  "action": "admin_create_session",
  "request_id": 25,
  "target_device_id": 10,
  "mode": "assisted"
}
```

Response:

```json
{
  "ok": true,
  "data": {
    "session_id": 80,
    "status": "waiting_consent"
  }
}
```

### `POST /public/api/assist.php`

Acción: `admin_end_session`

Body:

```json
{
  "action": "admin_end_session",
  "session_id": 80,
  "close_reason": "support_finished"
}
```

### `GET /public/api/assist.php?action=admin_session_events&session_id=80`

Lista de eventos de auditoría.

## Endpoints cliente web

### `POST /public/api/assist.php`

Acción: `client_request_support`

Body:

```json
{
  "action": "client_request_support",
  "platform": "windows",
  "source_device_id": 10,
  "notes": "No abre POS"
}
```

### `GET /public/api/assist.php?action=client_my_requests`

Solicitudes del usuario actual.

### `POST /public/api/assist.php`

Acción: `client_cancel_request`

Body:

```json
{
  "action": "client_cancel_request",
  "request_id": 25
}
```

## Endpoints del agente

### `POST /public/api/assist.php`

Acción: `agent_register`

Body:

```json
{
  "action": "agent_register",
  "device_uuid": "4ca1d87d-b3ef-4d5f-8ad1-6d0f1c9d2c11",
  "device_name": "PC-CAJA-01",
  "host_name": "pc-caja-01",
  "platform": "windows",
  "platform_version": "Windows 11 Pro",
  "architecture": "x64",
  "agent_version": "0.1.0",
  "public_key": "base64..."
}
```

Response:

```json
{
  "ok": true,
  "data": {
    "device_id": 10,
    "agent_token": "jwt-or-random-token",
    "expires_at": "2026-03-25T18:00:00Z"
  }
}
```

### `POST /public/api/assist.php`

Acción: `agent_heartbeat`

Body:

```json
{
  "action": "agent_heartbeat",
  "device_id": 10,
  "status": "online",
  "ip_public": "190.10.10.10",
  "ip_local": "192.168.1.50",
  "capabilities": {
    "can_screen_capture": true,
    "can_input_control": true,
    "can_file_transfer": true,
    "can_clipboard_sync": true,
    "can_unattended": false,
    "requires_local_consent": true,
    "permissions_screen": true,
    "permissions_accessibility": true
  }
}
```

### `GET /public/api/assist.php?action=agent_pending_sessions&device_id=10`

Devuelve sesiones pendientes para ese agente.

### `POST /public/api/assist.php`

Acción: `agent_session_consent`

Body:

```json
{
  "action": "agent_session_consent",
  "session_id": 80,
  "decision": "accept"
}
```

Valores válidos:

- `accept`
- `reject`

### `POST /public/api/assist.php`

Acción: `agent_session_state`

Body:

```json
{
  "action": "agent_session_state",
  "session_id": 80,
  "status": "active",
  "transport_type": "webrtc",
  "relay_used": false
}
```

### `POST /public/api/assist.php`

Acción: `agent_push_event`

Body:

```json
{
  "action": "agent_push_event",
  "session_id": 80,
  "event_type": "clipboard_sync_enabled",
  "event_level": "info",
  "payload": {
    "enabled": true
  }
}
```

## Señalización sugerida

Canal aparte:

- `/ws/assist`

Mensajes:

- `session.offer`
- `session.answer`
- `session.ice`
- `session.control.mouse`
- `session.control.keyboard`
- `session.chat.message`
- `session.file.offer`
- `session.file.progress`
- `session.close`

## Reglas de autorización

### Soporte

Puede:

- ver todas las requests de empresas asignadas
- crear sesión
- tomar o resolver solicitudes
- ver dispositivos

### Usuario cliente

Puede:

- ver solo sus requests
- crear requests de su empresa
- aceptar o rechazar consentimiento local

### Agente

Puede:

- operar solo sobre su `device_id`
- reportar heartbeat
- aceptar sesiones asignadas
- enviar eventos

## Eventos mínimos de auditoría

Registrar en `smx_assist_session_events`:

- `session_created`
- `session_consent_requested`
- `session_consent_accepted`
- `session_consent_rejected`
- `session_started`
- `session_ended`
- `file_upload_started`
- `file_upload_finished`
- `clipboard_enabled`
- `clipboard_disabled`
- `input_control_enabled`
- `input_control_disabled`

## Índices y performance

Necesarios para MVP:

- búsquedas por `id_empresa + status`
- búsquedas por `device_uuid`
- búsquedas por `support_login_id`
- timeline de `session_events` por `session_id`

## Orden recomendado de implementación

1. migraciones SQL
2. `agent_register`
3. `agent_heartbeat`
4. `client_request_support`
5. `admin_requests`
6. `admin_take_request`
7. `admin_create_session`
8. `agent_pending_sessions`
9. `agent_session_consent`
10. `agent_session_state`
11. websocket de señalización

## MVP fuera de alcance

No meter en primera iteración:

- Android control total
- iOS
- grabación de video completa
- transferencia de archivos pesada
- proxy inverso multimedia complejo
- múltiples monitores avanzados

## Decisiones pragmáticas

- usar `MySQL` ya existente
- meter API en `public/api/assist.php` al inicio
- dejar `WebSocket` en servicio aparte
- guardar auditoría textual primero, multimedia después
- arrancar con `Windows`

