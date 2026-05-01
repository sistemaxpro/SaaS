# SistemaX Agent - Detector de Impresoras Local

Este agente permite que la aplicación web detecte las impresoras instaladas en tu computadora.

## 📥 Descargar

Descarga el binario compatible con tu sistema operativo:

### macOS

**Si tienes Apple Silicon (M1, M2, M3, etc.):**
```bash
curl -O https://dev.sistemax.pro/public/agent-downloads/sistemax-agent-macos-arm64
chmod +x sistemax-agent-macos-arm64
./sistemax-agent-macos-arm64
```

**Si tienes Mac Intel (antiguo):**
```bash
curl -O https://dev.sistemax.pro/public/agent-downloads/sistemax-agent-macos-amd64
chmod +x sistemax-agent-macos-amd64
./sistemax-agent-macos-amd64
```

### Windows

```powershell
# En PowerShell como Administrador:
curl -O https://dev.sistemax.pro/public/agent-downloads/sistemax-agent-windows-amd64.exe
.\sistemax-agent-windows-amd64.exe
```

## 🚀 Instalación rápida en macOS

Descarga el script de instalación:
```bash
curl -O https://dev.sistemax.pro/public/agent-downloads/install-macos.sh
chmod +x install-macos.sh
./install-macos.sh
```

Luego ejecuta el agente con:
```bash
sistemax-agent
```

## ⚙️ Configuración

El agente escucha en `http://127.0.0.1:17890`

Para usar desde la app:
1. Ve a: https://dev.sistemax.pro/public/apps-moviles.php?external=1
2. Haz clic en "Probar agente"
3. El agente debe responder con `{"ok": true, "version": "0.1.4", ...}`
4. Haz clic en "Ver impresoras" para listar tus impresoras instaladas

## 📋 Requisitos

- macOS 10.14+ (Intel o Apple Silicon)
- Windows 10+ (64-bit)
- El agente se ejecuta en segundo plano en tu máquina local

## 🔍 Solución de problemas

### "Error: Failed to fetch"
- Asegúrate de que el agente está ejecutándose
- Verifica que no hay otro programa usando el puerto 17890
- En macOS, intenta permitir el agente en Seguridad > Permitir apps descargadas

### No se detectan impresoras
- En macOS: verifica que la impresora aparece en Preferencias > Impresoras y Escáneres
- En Windows: verifica que la impresora aparece en Configuración > Dispositivos > Impresoras

## 📞 Soporte

Si tienes problemas, contacta con el equipo de SistemaX.
