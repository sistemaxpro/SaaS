# Template de arranque para mobile nuevas

Estructura base sugerida:

- `config/db_config.php`: conexion por empresa y guard de esquema.
- `mobile.php`: vista Alpine/Tailwind para mobile.
- `api/`: endpoints locales del modulo.

Flujo recomendado:

1. Copiar esta carpeta como base de la app mobile.
2. Ajustar el arreglo de modulos requeridos en `config/db_config.php`.
3. Cargar `config/bootstrap.php` antes de renderizar.
4. Llamar a `getEmpresaConnection()` al inicio del flujo.

