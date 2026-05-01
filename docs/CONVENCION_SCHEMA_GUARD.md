# Convencion de Schema Guard

Regla del proyecto:

- Toda app nueva debe cargar `config/bootstrap.php` al inicio.
- Si la app usa tablas propias o depende de tablas de empresa, debe pasar por `public/shared/schema_module_compat.php`.
- La verificacion debe ejecutarse antes de renderizar la pagina o atender la API.

Flujo recomendado para nuevas apps:

1. Definir el modulo en `public/shared/schema_module_compat.php`.
2. Asociar sus tablas requeridas a una fuente plantilla valida.
3. Llamar a `sxEnsureModuleSchemaCompat($pdo, $dbName, [...], $sourceDb)`.
4. Si la app tiene un `config/db_config.php`, usar ese wrapper como punto unico de conexion.
5. Partir del starter en `templates/app_startup/`.
6. Para endpoints JSON, partir de `templates/api_startup/`.
7. Para UIs mobile, partir de `templates/mobile_startup/`.
8. Para limpieza de tablas, usar `scripts/purgar_tablas.php` primero en `dry-run`.
9. El modo seguro por defecto del purgador es `--mode=prefix`.
10. Para mock de estación, usar `scripts/setup_mock_station.sh`.
11. Para vaciar la BD mock antes de cargar datos, usar `scripts/cleanup_mock_station.php`.
12. Para reiniciar el mock completo en un solo paso, usar `scripts/reset_mock_station.sh`.
13. Para crear o sincronizar la BD de una empresa, usar `scripts/provision_empresa_db.php`.
14. Para normalizar todas las empresas a `empresa_<id_empresa>`, usar `scripts/normalizar_dbs_empresas.php`.

Ejemplos de purga:

```bash
php scripts/purgar_tablas.php
php scripts/purgar_tablas.php --db=empresa_1055
php scripts/purgar_tablas.php --db=empresa_1055 --mode=prefix
php scripts/purgar_tablas.php --db=empresa_1055 --apply
php scripts/purgar_tablas.php --db=empresa_1055 --apply --force
php scripts/purgar_tablas.php --db=empresa_1055 --mode=all --apply --force
php scripts/purgar_tablas.php --db=empresa_1055 --mode=prefix --prefix=tmp_,bak_
./scripts/setup_mock_station.sh
./scripts/setup_mock_station.sh --clean
./scripts/reset_mock_station.sh
php scripts/provision_empresa_db.php --id_empresa=169 --apply
php scripts/normalizar_dbs_empresas.php
php scripts/normalizar_dbs_empresas.php --apply
php scripts/cleanup_mock_station.php --apply --force
```

Limpieza mock:

- `php scripts/cleanup_mock_station.php` solo reporta.
- `php scripts/cleanup_mock_station.php --apply` borra las tablas mock y pide confirmacion.
- `php scripts/cleanup_mock_station.php --apply --force` borra sin pedir confirmacion.
- `./scripts/setup_mock_station.sh --clean` limpia y luego recarga datos.
- `./scripts/reset_mock_station.sh` limpia y recarga en un solo comando.
- `php scripts/provision_empresa_db.php --id_empresa=169 --apply` crea `empresa_169` y sincroniza `dbase`.
- `php scripts/normalizar_dbs_empresas.php --apply` alinea todas las empresas a `empresa_<id_empresa>` y copia datos legacy cuando existan.

Reglas del purgador:

- `--mode=prefix` solo considera tablas huérfanas que parezcan temporales o derivadas por nombre.
- `--mode=all` considera cualquier tabla fuera del allowlist y solo debe usarse con `--force`.
- `--prefix` permite acotar aún más el borrado en modo `prefix`.

Notas:

- El bootstrap global ya ejecuta `sxEnsureBootstrapModuleSchemasCompat()` para rutas conocidas.
- Si aparece un modulo nuevo, agregar su prefijo al mapa de deteccion del helper central.
- La creacion de tablas debe ser idempotente y best-effort: primero verificar, luego clonar desde plantilla, y solo despues continuar con la pagina.
