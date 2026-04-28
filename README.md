# SistemaX v1 - Arquitectura Multi-Empresa

## Estructura del Proyecto

```
__v1/
├── config/              # Configuración centralizada
│   ├── database.php     # Conexiones Master + Empresa
│   ├── session.php      # Gestión de sesiones
│   └── bootstrap.php    # Inicializador del sistema
│
├── src/                 # Código fuente
│   ├── Core/            # Clases fundamentales
│   │   ├── Auth.php         # Autenticación
│   │   ├── Permission.php   # Control de acceso
│   │   ├── MultiTenant.php  # Multi-empresa/moneda/sucursal
│   │   └── Response.php     # Respuestas HTTP
│   │
│   ├── Modules/         # Módulos de negocio
│   │   ├── Users/
│   │   ├── Empresas/
│   │   ├── Products/
│   │   └── Sales/
│   │
│   ├── Layouts/         # Plantillas HTML
│   │   ├── base.php
│   │   └── components/
│   │       ├── navbar.php
│   │       └── footer.php
│   │
│   └── Helpers/         # Funciones auxiliares
│
├── public/              # Punto de entrada público
│   ├── index.php        # Dashboard principal
│   ├── assets/
│   │   ├── css/
│   │   └── js/
│   └── api/v1/
│       ├── auth.php
│       └── middleware/
│
├── docs/                # Documentación
└── logs/                # Logs del sistema
```

## Arquitectura de Base de Datos

### Master DB: `serproc1`

- `empresa`: Registro de empresas
- `sec_users`: Usuarios del sistema
- `sec_groups`: Grupos de permisos
- `sec_groups_apps`: Permisos por aplicación
- `habilitacion_sifen`: Configuración SIFEN

### Empresas DB: Variable

- Cada empresa tiene su propia base de datos
- Nombre definido en `serproc1.empresa.dbase`
- Ejemplos: `smx_169`, `tienda_169`, etc.

## Características

✅ **Multi-Empresa**: 1 base de datos por empresa  
✅ **Multi-Moneda**: Gestión de múltiples monedas  
✅ **Multi-Sucursal**: Múltiples sucursales por empresa  
✅ **Control de Acceso**: Sistema de permisos basado en grupos  
✅ **Arquitectura Modular**: Código organizado y mantenible  
✅ **Alpine.js + Tailwind CSS**: Stack frontend moderno  
✅ **APIs REST**: Endpoints estandarizados

## Uso

### Acceso al Sistema

1. Iniciar sesión en el sistema actual (`/admin_sec_login/`)
2. Acceder a `/__v1/public/index.php`
3. La sesión se reutiliza automáticamente

### Verificar Sesión

```bash
curl http://tu-dominio.com/__v1/public/api/v1/auth.php?action=check
```

### Estructura de Respuesta API

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "user": {...},
    "empresa": {...}
  }
}
```

## Desarrollo

### Crear Nuevo Módulo

1. Crear directorio en `src/Modules/NombreModulo/`
2. Crear archivos:
   - `index.php` (Vista principal)
   - `api.php` (Endpoints REST)
   - `Model.php` (Lógica de negocio)

### Usar Clases Core

```php
<?php
require_once __DIR__ . '/config/bootstrap.php';

// Verificar login
Session::requireLogin();

// Obtener empresa actual
$empresa = MultiTenant::getEmpresaActual();

// Verificar permisos
Permission::requireAccess('nombre_app');

// Conexión a DB de empresa
$db = Database::getSessionEmpresaConnection();
```

## Migración desde Sistema Actual

Ver [MIGRATION.md](MIGRATION.md) para guía detallada.

## Soporte

- Documentación: `/__v1/docs/`
- Logs: `/__v1/logs/php_errors.log`
- API Docs: `/__v1/docs/API.md`
