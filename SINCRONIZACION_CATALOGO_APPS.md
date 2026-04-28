# Sistema de Sincronización Automática del Catálogo de Apps

## Descripción
Sistema que verifica automáticamente todas las apps generadas en el proyecto y las agrega al catálogo (`saas_apps_catalogo`) cuando se abre el menú o el catálogo de aplicaciones.

## Componentes Implementados

### 1. Archivo de Configuración de Apps
**Ruta:** `src/Config/apps_catalog_config.php`

Define todas las apps disponibles en el sistema de forma centralizada:
- Balanza Electrónica
- Migrador DB
- Agente de Impresión
- Instalador Android
- Impresora Bluetooth
- Tracking Móvil
- Diagnóstico Push
- Soporte Desktop (SistemaX Assist)
- Inventario Móvil
- Centro de Bug
- Central de Apps
- Configuración Geolocalización

### 2. Método de Sincronización
**Clase:** `SuscripcionController`
**Método:** `ensureAllCatalogApps()`

Ejecuta automáticamente:
- Verifica todas las apps definidas en la configuración
- Crea las apps que no existen en la BD
- Llama a los métodos `ensure*App()` del controlador
- Actualiza todas las apps para estar activas

### 3. Puntos de Activación Automática

#### a) En el Menú Principal
**Archivo:** `public/menu.php` (línea 458)
```php
SuscripcionController::ensureAllCatalogApps();
```
Se ejecuta cada vez que se carga el menú principal.

#### b) En el API de Catálogo
**Archivo:** `public/api/v1/suscripciones.php` (línea 65)
```php
SuscripcionController::ensureAllCatalogApps();
```
Se ejecuta al cargar el catálogo de apps desde el frontend.

### 4. Endpoint Manual de Sincronización
**URL:** `GET /public/api/v1/suscripciones.php?action=sync_catalog`
**Requerimiento:** Solo administradores super (empresa ID 169)

Permite forzar una sincronización manual. Útil para:
- Debugueo
- Sincronizar después de crear nuevas apps
- Validar estado del catálogo

**Ejemplo de respuesta:**
```json
{
  "success": true,
  "message": "Catálogo sincronizado",
  "sync_results": {
    "success": true,
    "synced": [
      {
        "method": "ensureBalanzaApp",
        "created": false
      },
      {
        "codigo": "print_agent",
        "nombre": "Agente de Impresion",
        "status": "exists"
      }
    ],
    "errors": [],
    "total": 14
  }
}
```

## Flujo de Sincronización

```
1. Usuario abre el menú o catálogo
     ↓
2. Se ejecuta SuscripcionController::ensureAllCatalogApps()
     ↓
3. Verifica apps del controlador (ensureBalanzaApp, ensureDbMigradorApp)
     ↓
4. Carga configuración desde apps_catalog_config.php
     ↓
5. Para cada app en la configuración:
     - Si existe en BD: marca como sincronizada
     - Si no existe: la crea automáticamente
     ↓
6. Activa todas las apps (UPDATE activo=1)
     ↓
7. Retorna resumen de sincronización
```

## Estadísticas del Catálogo

Después de la sincronización completa:
- **Total de apps activas:** 69
- **Apps organizadas por módulo:**
  - Administración: 3 apps
  - Administracion: 3 apps
  - Comercial: 2 apps
  - Estación: 6 apps
  - Estación de Servicio: 3 apps
  - Finanzas: 2 apps
  - General: 14 apps
  - Inmobiliaria: 6 apps
  - Inventario: 4 apps
  - POS: 4 apps
  - Soporte: 4 apps
  - Taller: 3 apps
  - Ventas: 5 apps

## Agregar Nuevas Apps

### Opción 1: Archivo de Configuración (Recomendado)
1. Editar `src/Config/apps_catalog_config.php`
2. Agregar nueva entrada en el array:
```php
'mi_app' => [
    'codigo' => 'mi_app',
    'nombre' => 'Mi Aplicación',
    'descripcion' => 'Descripción de mi aplicación',
    'ruta_app' => 'public/mi_app/index.php',
    'icono' => 'app-icon-name',
    'color' => 'blue',
    'precio_mensual' => 0,
    'obligatoria' => 0,
    'modulo' => 'Mi Módulo',
    'negocio' => 'Comercial',
    'orden' => 100
]
```
3. La app se sincronizará automáticamente al abrir el menú

### Opción 2: Método en SuscripcionController
Crear un nuevo método `public static function ensureMiApp()` siguiendo el patrón de `ensureBalanzaApp()`.

## Testing

Script de prueba disponible:
`public/api/v1/test_sync_catalogo.php`

Muestra:
- Resumen de sincronización
- Total de apps activas
- Listado de apps por módulo
- Lista completa de apps

## Mantenimiento

- Revisar regularmente `apps_catalog_config.php` para apps obsoletas
- Mantener la propiedad `orden` para controlar el orden de visualización
- Usar el mismo patrón de `codigo` para consistency
- Los módulos deben coincidir con los definidos en el sistema

## Beneficios

✅ Sincronización automática: No requiere ejecución manual
✅ Centralizado: Todas las apps en un archivo fácil de mantener
✅ Idempotente: No duplica apps existentes
✅ Visible: Estadísticas claras de qué se sincronizó
✅ Seguro: Solo super admin puede forzar sincronización manual
✅ Flexible: Soporta apps del controlador y del archivo de config

## Troubleshooting

**Problema:** Apps no aparecen en el catálogo
- **Solución:** Verificar que la app está marcada como `activo=1`
- **Solución:** Revisar que el módulo existe en el sistema
- **Solución:** Acceder a `?action=sync_catalog` para forzar sincronización

**Problema:** Error al sincronizar
- **Verificar:** El archivo de configuración tiene sintaxis PHP correcta
- **Verificar:** El path en `ruta_app` existe
- **Revisar logs:** `error_log` para mensajes de error detallados
