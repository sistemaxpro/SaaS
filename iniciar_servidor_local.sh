#!/bin/bash

# Script para iniciar servidor PHP local (sin Docker)
# MySQL local en puerto 3306 con base de datos serproc1_local

export SISTEMAX_ENV=dev
export SISTEMAX_MASTER_DB_HOST=127.0.0.1
export SISTEMAX_MASTER_DB_PORT=3306
export SISTEMAX_MASTER_DB_NAME=serproc1
export SISTEMAX_MASTER_DB_USER=sistemax
export SISTEMAX_MASTER_DB_PASS=Armagedon123

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "Iniciando servidor PHP en http://localhost:8080"
echo "Directorio raíz: $(pwd)"
echo ""
echo "Entorno: DEV  |  DB: serproc1  |  Puerto MySQL: 3306"
echo ""
echo "URLs de prueba:"
echo "  http://localhost:8080/public/test_conexion.php"
echo "  http://localhost:8080/public/login.php"
echo "  http://localhost:8080/public/menu/menu.php"
echo ""
echo "Presiona Ctrl+C para detener"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

php -S localhost:8080 "$SCRIPT_DIR/router.dev.php"
