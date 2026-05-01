# Template de arranque para apps nuevas

Estructura base sugerida:

- `index.php`: entra por `config/bootstrap.php` y renderiza la app.
- `config/db_config.php`: resuelve la empresa y aplica el guard de esquema.
- `_module.php`: helpers de UI y funciones compartidas del modulo.

Uso:

1. Copiar esta carpeta como base de una nueva app.
2. Cambiar `APP_MODULES` por las tablas/modulos que corresponden.
3. Si la app usa tablas propias, agregar su bloque en `public/shared/schema_module_compat.php`.
4. Mantener `config/bootstrap.php` como primer include en las paginas.

