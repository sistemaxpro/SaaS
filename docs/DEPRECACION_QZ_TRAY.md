# Deprecación de QZ Tray

Fecha: 2026-02-24

QZ Tray quedó deprecado en Sistemax POS.

## Estado actual

- Impresión directa migrada a `Sistemax Agent` (servicio local en `127.0.0.1:17890`).
- UI del POS principal y Caja usan `smx-printer.js` en modo `agent-only`.
- `qz-sign.php` responde `410 Gone`.
- `qz-test.php` responde `410 Gone` con página informativa.

## Rutas de instaladores (POS)

- `/public/pos/downloads/sistemax-agent-setup-0.1.1.exe`
- `/public/pos/downloads/sistemax-agent-macos-0.1.1.tar.gz`
- `/public/pos/downloads/sistemax-agent-windows-source-0.1.1.tar.gz`
- `/public/pos/downloads/sistemax-agent-linux-0.1.1.tar.gz`
- `/public/pos/downloads/sistemax-agent-android-spec-0.1.1.tar.gz`

## Estado de limpieza

- Eliminados artefactos legacy: `qz-public.crt`, `qz-public.crt.bak`, `qz.csr`, `config/qz/*`.

## Pendiente de migración

- `public/cajas/index.php` todavía usa integración QZ.
- `public/misventas/index.php` todavía usa integración QZ.

Ambos módulos deben migrarse a `Sistemax Agent` para cierre total de QZ en todo el proyecto.
