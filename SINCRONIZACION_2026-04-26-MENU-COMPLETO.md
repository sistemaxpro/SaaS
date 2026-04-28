# Sincronización Dev → Producción (2026-04-26 - Correcciones de Menu)

## Problema Identificado

El archivo `/public/menu/menu.php` estaba agregando Vcorta y otras apps obligatorias **siempre**, sin considerar si la empresa tenía aplicaciones asignadas. Esto causaba que empresas sin apps vieran el menú con Vcorta y Salir, cuando debería estar completamente vacío.

## Causa Raíz

En `/public/menu/menu.php` (líneas 1712-1762):
- Se creaba el array $appsObligatorias sin verificar si $appsMenuData estaba vacío
- Se agregaba Vcorta incondicional al menú
- Se reposicionaba Vcorta al inicio del array (líneas 1839-1851)

## Solución Implementada

### Archivo: `/public/menu/menu.php`

**Cambio 1 - Condicionar apps obligatorias (líneas 1712-1762):**
```php
$appsObligatorias = [];

// Solo agregar apps obligatorias si la empresa tiene al menos una app asignada
if (!empty($appsMenuData)) {
    $appsObligatorias = [
        // Vcorta, Salir, Centro Notificaciones, etc
    ];
}
```

**Cambio 2 - Remover reposicionamiento de Vcorta (líneas 1839-1851):**
- Removidas líneas que buscaban Vcorta y la posicionaban al inicio del array

### Archivo: `/public/menu.php`

**Cambio - Agregar debug logging (línea 597):**
```php
error_log('[menu.php][DEBUG] getAppsEmpresa retornó ' . count($appsMenuData) . ' apps: ...');
```

## Comportamiento Post-Sincronización

```
Empresa SIN apps asignadas:
  ✓ Menú completamente vacío
  ✓ No muestra Vcorta
  ✓ No muestra Salir
  ✓ No muestra ninguna app obligatoria

Empresa CON apps asignadas:
  ✓ Muestra apps asignadas
  ✓ Muestra apps obligatorias (Vcorta, Salir, Centro Notificaciones, etc)
```

## Sincronización

| Ambiente | Estado |
|----------|--------|
| Dev (Puerto 3307) | ✅ Cambios aplicados en master |
| Prod (Puerto 3306) | ✅ Cambios disponibles (código compartido) |

**Commit:** `6cafbac` - Corregir aparición de apps obligatorias en menu.php

## Verificación

Para verificar que los cambios están funcionando:

### Empresa sin apps (ID: 29 - TRANSPORTADORA ACARAY SA)
- Acceder a la aplicación con esa empresa
- Verificar que el menú está completamente vacío

### Empresa con apps (ID: 118 - SEBASTIAN AUTO REPUESTOS)
- Acceder a la aplicación con esa empresa
- Verificar que aparecen Alquileres y otras apps
- Verificar que aparece Vcorta al final del menú

## Notas

- No requiere cambios en estructura de base de datos
- Los cambios son solo de lógica PHP
- Compatible con ambos puertos MySQL (3307 dev, 3306 prod)
- El código fuente es compartido entre dev y prod

## Estado de Sincronización

- ✅ Cambios implementados en rama master
- ✅ Commit realizado: `6cafbac`
- ✅ Código disponible en ambos ambientes

