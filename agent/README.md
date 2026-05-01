# Sistemax Agent (MVP)

Servicio local tipo QZ Tray para impresion RAW ESC/POS.

## Endpoints

- `GET /health`
- `GET /printers`
- `POST /print/raw`
- `POST /apps/open`
- `GET /permissions/status`
- `POST /permissions/open-settings`
- `GET /assist/status`
- `POST /assist/register`
- `POST /assist/heartbeat`
- `POST /assist/sync`

## Ejecutar

```bash
cd agent
go run .
```

Escucha en `127.0.0.1:17890`.

## Request de impresion

```json
{
  "printer": "NOMBRE_IMPRESORA",
  "data": "BASE64_ESC_POS",
  "copies": 1
}
```

`GET /permissions/status`

Devuelve una guía simple para saber si todavía hace falta revisar permisos del sistema.

## Assist MVP

`GET /assist/status`

Devuelve el estado local del enrolamiento Assist. El agente guarda su estado en:

- `~/.sistemax-agent/assist.json`

`POST /assist/register`

Registra el agente con el token efímero emitido por `assist.php?action=client_issue_agent_bootstrap`.

```json
{
  "bootstrap_token": "TOKEN_TEMPORAL",
  "assist_api_url": "https://sistemax.pro/public/api/assist.php",
  "device_name": "Caja Mostrador"
}
```

`POST /assist/heartbeat`

Envía heartbeat con el `agent_token` ya persistido localmente.

```json
{
  "assist_api_url": "https://sistemax.pro/public/api/assist.php",
  "status": "online"
}
```

`POST /assist/sync`

Si el agente no está registrado, hace el alta inicial y luego envía heartbeat en una sola llamada.

```json
{
  "bootstrap_token": "TOKEN_TEMPORAL",
  "assist_api_url": "https://sistemax.pro/public/api/assist.php",
  "device_name": "Caja Mostrador",
  "status": "online"
}
```

## Estado por plataforma

- Linux/macOS: listado de impresoras y envio RAW via `lp`/`lpr`.
- Windows: listado de impresoras + envio RAW nativo via Winspool (`OpenPrinterW`/`WritePrinter`).

## Build Windows + instalador

Desde PowerShell en Windows:

```powershell
cd agent
go mod tidy
powershell -ExecutionPolicy Bypass -File .\windows\build-windows.ps1 -Version 0.1.1
```

Esto genera:

- `agent/dist/sistemax-agent.exe`
- instalador Inno Setup `agent/windows/sistemax-agent-setup-0.1.1.exe` (si `iscc` esta instalado)

## Build macOS + autoarranque

Desde Terminal (macOS):

```bash
cd agent
go mod tidy
./macos/build-macos.sh 0.1.1
```

Esto genera:

- `agent/dist/sistemax-agent-macos` (binario universal: Intel + Apple Silicon)
- `agent/dist/sistemax-agent-macos-0.1.1.tar.gz` (paquete para distribuir)

Instalacion en la Mac destino:

```bash
tar -xzf sistemax-agent-macos-0.1.1.tar.gz
cd sistemax-agent-macos-0.1.1
./install-macos.sh
```

El instalador crea un `LaunchAgent` de usuario:

- `~/Library/LaunchAgents/com.sistemax.agent.plist`

Logs:

- `~/.sistemax-agent/logs/agent.out.log`
- `~/.sistemax-agent/logs/agent.err.log`

Desinstalar:

```bash
./uninstall-macos.sh
```

## Build Linux + servicio (systemd user)

Desde Linux:

```bash
cd agent
go mod tidy
./linux/build-linux.sh 0.1.1
```

Esto genera:

- `agent/dist/sistemax-agent-linux-0.1.1.tar.gz`

Instalacion en Linux destino:

```bash
tar -xzf sistemax-agent-linux-0.1.1.tar.gz
cd sistemax-agent-linux-0.1.1
./install-linux.sh
```

Servicio user:

- `~/.config/systemd/user/com.sistemax.agent.service`

Desinstalar:

```bash
./uninstall-linux.sh
```

## Seguridad MVP

- Escucha solo localhost.
- CORS abierto para facilitar pruebas locales.
- En siguiente fase: allowlist de origen + token firmado + pairing.
