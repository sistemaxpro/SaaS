# CAMBIOS REALIZADOS AL ENDPOINT DE ELIMINACIÓN DE EMPRESA

## Problemas Identificados y Solucionados

### 1. **Manejo Incorrecto de Contraseña en mysqldump**

- **Problema**: La contraseña con caracteres especiales (`@Armagedon#1023840!`) se estaba interpolando directamente en el comando shell.
- **Solución**: Usar `putenv("MYSQL_PWD=...")` para pasar la contraseña de forma segura a mysqldump, evitando problemas de escape.

### 2. **Búsqueda Incorrecta de CREATE TABLE**

- **Problema**: Se usaba `SHOW CREATE TABLE` desde una conexión PDO a `masterDb` para bases de datos distintas.
- **Solución**: Usar `information_schema.COLUMNS` y construir CREATE TABLE dinamicamente desde la metadata.

### 3. **Falta de Limpieza de Output Buffer en Casos de Error**

- **Problema**: Cuando se enviaba `echo json_encode()` durante errores, el output buffer estaba activo, causando salida corrupta.
- **Solución**: Llamar a `ob_end_clean()` antes de cualquier `echo` en el flujo de error.

### 4. **Sintaxis SQL Inconsistente**

- **Problema**: Algunos `DROP DATABASE` y `DELETE FROM` usaban sintaxis inconsistente con/sin backticks.
- **Solución**: Usar backticks para todos los nombres de BD/tabla: `` `dbname` `` y `` `table` ``.

### 5. **Headers No Siempre Establecidos**

- **Problema**: El header `Content-Type: application/json` no se enviaba en todos los caminos de respuesta.
- **Solución**: Verificar con `!headers_sent()` e incluir header en cada echo JSON.

### 6. **Logging Insuficiente**

- **Problema**: Falta de información sobre qué comando exactamente falla.
- **Solución**: Agregar `error_log()` detallado en cada operación, incluyendo:
  - Ejecución de mysqldump (returnCode, salida)
  - Consultas a information_schema
  - Escritura de archivos de backup
  - Comandos DROP DATABASE
  - Comandos DELETE FROM

## Cambios Específicos en el Código

### Línea ~630: Mysqldump Mejorado

```php
putenv("MYSQL_PWD=" . $dbPass);
$cmd = sprintf(
    'mysqldump -h %s -u %s --skip-extended-insert %s > %s 2>&1',
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    escapeshellarg($dbName),
    escapeshellarg($backupFile)
);
// ... ejecutar ...
putenv("MYSQL_PWD=");  // Limpiar
```

### Línea ~670: Backup PHP Mejorado

- Usar `information_schema` en lugar de `SHOW CREATE TABLE`
- Agregar logging de cada paso
- Mejor manejo de excepciones

### Línea ~730: Drop Database Mejorado

- Syntax correcta: `` DROP DATABASE IF EXISTS `dbname` ``
- Separated exception handlers para PDOException vs Exception
- Logging del return code

### Línea ~755: Delete Record Mejorado

- Syntax correcta: ``DELETE FROM `db`.`table` WHERE ...``
- Logging de filas afectadas
- Mejor manejo de errores

## Archivos de Debug

El script crea log en: `/home/fabio/web/sistemax.com.py/public_html/log_delete_error.txt`

Este log contendrá:

- Inicio/fin de request
- Método HTTP y Content-Type
- Datos de entrada
- Cada paso de la operación
- Retorno de mysqldump (returnCode y output)
- Excepciones completas con stack traces

## Cómo Verificar

1. **Revisar el log_delete_error.txt después de intentar una eliminación**:

   ```bash
   tail -100 /home/fabio/web/sistemax.com.py/public_html/log_delete_error.txt
   ```

2. **Verificar que el comando mysqldump funciona**:

   ```bash
   MYSQL_PWD=@Armagedon#1023840! mysqldump -h 168.231.95.50 -u sistemax empresa_1042 > /tmp/test.sql
   echo $?  # Debería ser 0
   ```

3. **Probar el endpoint con curl**:
   ```bash
   curl -X POST http://localhost/admin_empresa/editar_empresa.php?action=delete \
     -H "Content-Type: application/json" \
     -d '{"id_empresa":1042,"confirmacion":"80102866-3"}'
   ```

## Posibles Errores Restantes

1. **Permiso de mysqldump**: Usuario `sistemax` podría no tener acceso
   - Solución: Usar cuenta admin o configurar acceso

2. **Directorio de backup no existe**: `/home/fabio/web/sistemax.com.py/public_html/_lib/file/backupDB`
   - Solución: Crear con: `mkdir -p _lib/file/backupDB && chmod 755 _lib/file/backupDB`

3. **Permisos de DROP DATABASE**: Usuario `sistemax` podría no tener privilegio
   - Solución: GRANT DROP en MySQL

## Próximos Pasos

1. Revisar el log después de intentar eliminar
2. Identificar cuál es el paso exacto que falla
3. Aplicar la solución específica según el error
