# Sincronización Dev → Producción (2026-04-26)

## Cambios Sincronizados

### 1. Corrección de Asignación de Apps por Defecto
**Problema:** Cuando una empresa no tiene ninguna app asignada, se mostraban automáticamente las apps obligatorias (vcorta, alquileres, etc).

**Solución Implementada:**
- Remover UNION de apps obligatorias en `getAppsEmpresa()` 
- Agregar validación en menu.php para no mostrar apps obligatorias si empresa no tiene apps

### 2. Archivos Modificados

#### `src/Modules/Empresas/SuscripcionController.php`
- **Método:** `getAppsEmpresa()`
- **Cambio:** Remover UNION que agregaba apps obligatorias
- **Líneas:** 1341-1483
- **Resultado:** Si empresa no tiene apps → retorna array vacío

#### `public/menu.php`
- **Sección:** Agregación de apps obligatorias
- **Cambio:** Envolver en condición `if (!empty($appsMenuData))`
- **Líneas:** 903-936
- **Resultado:** Solo muestra apps obligatorias si hay al menos una app asignada

### 3. Commits

| Hash | Mensaje |
|------|---------|
| `edbec79` | Corregir asignación de apps por defecto cuando empresa no tiene ninguna app |
| `f3300f9` | No mostrar apps obligatorias si empresa no tiene ninguna app asignada |
| `6dbf568` | Merge: Sincronizar correcciones de apps por defecto de dev a producción |

## Comportamiento Post-Sincronización

```
Empresa CON apps asignadas:
  ✓ Muestra apps asignadas
  ✓ Muestra apps obligatorias (Vcorta, Centro Notificaciones, etc)

Empresa SIN apps asignadas:
  ✓ Menú completamente vacío
  ✓ No muestra ninguna aplicación
```

## Estado de Sincronización

### Desarrollo (Puerto 3307)
- ✅ Cambios aplicados en rama `dev`
- ✅ Tests realizados correctamente

### Producción (Puerto 3306)
- ✅ Cambios mergeados a rama `master`
- ⏳ Pendiente: `git pull origin master` en servidor de producción

## Verificación

Para verificar que los cambios están en producción:

```bash
# En servidor de producción
cd /var/www/html/desarrollo
git checkout master
git pull origin master

# Verificar archivos actualizados
git log --oneline -5
```

## Notas

- No requiere cambios en estructura de base de datos
- Los cambios son solo de lógica PHP
- Compatible con MySQL en ambos puertos (3307 dev, 3306 prod)
