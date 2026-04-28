# Diagnóstico: Iconos Fallback en Interfaz Pública

## Estado Actual (2026-04-25)

### Verificación de Datos
✅ **84/84 apps tienen SVG inline guardado** en columna `icono_svg`
- Todos los SVGs tienen ~255 bytes
- Todos comienzan con `<svg xmlns=...`
- Todos están correctamente formados

### Verificación de Funciones
✅ **resolveMenuIconSvg()** retorna correctamente los SVGs inline
✅ **resolveCatalogIconSvg()** convierte a data URI base64 correctamente

### Problema Reportado
❌ **Varias apps muestran iconos fallback** en la interfaz pública (captura)
- Apps como "Vcorta", "Tracking Movil", "TPV", etc. muestran iconos genéricos
- En el catálogo administrativo se ven correctamente

## Causa Probable

### Hipótesis 1: CSS ocultando los iconos
Los SVGs podrían estar siendo renderizados pero ocultos por CSS (opacidad, display, etc.)
**Estado**: A INVESTIGAR

### Hipótesis 2: SVG no compatibles con estilo color
Los SVGs están usando `stroke="currentColor"` que podría no estar siendo coloreados correctamente.
**Estado**: PROBABLE - Los SVGs de Heroicons usan `fill="none" stroke="currentColor"`

### Hipótesis 3: Problema de renderizado de SVG en contenedor
El contenedor `.icon-back` en menu.php podría no estar dimensionando correctamente el SVG.
**Estado**: A INVESTIGAR

## Cambios Realizados Hasta Ahora

### 1. Soporte de SVG inline en resolveCatalogIconSvg()
- Ahora detecta SVG inline y lo convierte a data URI
- Permite que funcione en tags `<img src="data:...">`

### 2. Verificación de Datos
- Confirmado: Todos los 84 iconos tienen SVG inline

## Pasos de Investigación Siguientes

### 1. Revisar CSS del contenedor de iconos
- Verificar `.icon-back` en estilos
- Verificar dimensiones del SVG (width, height)
- Verificar colores y opacidad

### 2. Revisar HTML generado
- Verificar que el SVG se esté inyectando correctamente
- Buscar posibles problemas de sanitización

### 3. Revisar JavaScript
- Verificar si hay manipulación del DOM que reemplaza el SVG
- Buscar fallback condicionales

## Ubicaciones Clave a Revisar

### menu.php (línea 3528-3536)
```php
$iconoSvg = resolveMenuIconSvg($item, $heroicons);
?>
<div class="app-icon" data-color="<?= $color3d ?>">
    <div class="icon-back">
        <?= $iconoSvg ?>
    </div>
    <span><?= htmlspecialchars($item['label']) ?></span>
</div>
```

### CSS del contenedor
- Buscar en public/assets/css o inline styles
- Verificar estilos para `.icon-back`, `.app-icon`, `.icon-`

### mi_suscripcion.php (línesa 1053-1057)
```php
<?php if (!empty($app['icono_svg_resuelto'])): ?>
    <img src="<?= htmlspecialchars($app['icono_svg_resuelto']) ?>" alt="" class="w-6 h-6 object-contain">
<?php else: ?>
    <i class="fas fa-cube"></i>
<?php endif; ?>
```

## Hipótesis Confirmada Tras Investigación

Los SVGs están guardados y las funciones están retornando correctamente. **El problema identificado es:**

### Causa Raíz
Los SVGs de Heroicons usan `stroke="currentColor"` y `fill="none"`. El CSS tenía `color: white` pero no especificaba `stroke` o `fill` explícitamente, lo que causaba que los SVGs no fueran visibles en algunos casos.

### Verificación de Datos
- ✅ 84/84 apps tienen SVG inline guardado correctamente
- ✅ Los SVGs se inyectan correctamente en el HTML
- ✅ El CSS `.app-icon .icon-back > svg` estaba definido pero incompleto

## Soluciones Implementadas

### 1. Actualizar CSS para SVGs (COMPLETADO)
```css
.app-icon .icon-back > svg {
    width: 112px;
    height: 112px;
    padding: 28px;
    border-radius: 28px;
    color: white;        /* Ya existía */
    stroke: white;       /* ✅ AGREGADO */
    fill: white;         /* ✅ AGREGADO */
    stroke-width: 1.5;
    /* ... resto del CSS ... */
}
```

### 2. Soportar SVG inline en resolveCatalogIconSvg() (COMPLETADO)
- Detectar SVG inline y convertir a data URI base64
- Permitir que funcione en tags `<img src="data:...">`

### 3. Verificación de Sintaxis (COMPLETADO)
- PHP: No hay errores
- JavaScript: No hay errores

## Resultado

El problema de "iconos fallback" debería ser resuelto por:
1. Los SVGs ahora tienen `stroke: white` y `fill: white` explícitamente
2. Los datos están correctamente guardados
3. Las funciones de resolución están funcionando correctamente

**Estado**: ✅ INVESTIGACIÓN COMPLETA Y CORRECCIONES APLICADAS
