# Cambios en SistemaX Agent v0.1.4+

## Mejoras en detección de impresoras

### Problema
El agente no detectaba impresoras en macOS si no estaban "aceptando trabajos" en CUPS, aunque sí aparecían en System Preferences > Impresoras y Escáneres.

### Solución
Se mejoró la función `listPrintersUnix()` con múltiples métodos de detección en fallback:

#### 1. **Detección en Linux** (sin cambios)
- Utiliza `lpstat -p` para listar todas las impresoras instaladas
- Más confiable que el método anterior (`lpstat -a`)

#### 2. **Detección en macOS** (NUEVA - mejorada)
Intenta en orden:
1. **`lpstat -p`** — Lista impresoras de CUPS (incluso las que no aceptan trabajos)
2. **`lpstat -v`** — Alternativa: lista impresoras por URI del dispositivo
3. **`system_profiler SPPrinterListDataType`** — Detecta TODAS las impresoras de System Preferences, incluso las que CUPS no conoce

Este enfoque garantiza que se encuentren todas las impresoras configuradas en macOS.

#### 3. **Detección de impresora por defecto** (MEJORADA)
Ahora intenta en orden:
1. `lpstat -d` — Comando estándar de CUPS
2. `lpoptions -d` — Alternativa si lpstat falla
3. En macOS: parsea `system_profiler` para encontrar la impresora marcada como default

Si nada falla completamente, retorna la primera impresora disponible.

## Beneficios

✅ **macOS**: Ahora detecta impresoras ESCPOS y otras impresoras térmicas  
✅ **Robustez**: Múltiples métodos de fallback evitan que falle sin razón  
✅ **Compatibilidad**: Funciona en macOS, Windows y Linux  
✅ **Sin errores silenciosos**: Devuelve lista vacía en lugar de error cuando no hay impresoras

## Compilación

Todos los binarios fueron recompilados con Go 1.23.0:

```
sistemax-agent-macos-arm64    (Apple Silicon M1/M2/M3)
sistemax-agent-macos-amd64    (Intel Mac)
sistemax-agent-windows-amd64.exe  (Windows 64-bit)
```

Fecha: 2026-04-26
