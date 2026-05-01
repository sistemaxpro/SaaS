# Template de arranque para APIs nuevas

Estructura base sugerida:

- `config/db_config.php`: conexion a empresa y guard de esquema.
- `api.php`: endpoint inicial con respuesta JSON.

Flujo recomendado:

1. Copiar esta carpeta como base de la nueva API.
2. Ajustar el arreglo de modulos requeridos en `config/db_config.php`.
3. Reutilizar `getEmpresaConnection()` como punto unico de conexion.
4. Mantener el guard antes de ejecutar la logica de negocio.

