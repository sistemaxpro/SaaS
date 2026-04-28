#!/bin/bash

# Script para iniciar servidor PHP local
# Uso: ./iniciar_servidor_local.sh

cd "$(dirname "$0")/public"

echo "🚀 Iniciando servidor PHP en http://localhost:8080"
echo "📁 Directorio: $(pwd)"
echo ""
echo "Prueba estos URLs:"
echo "  - http://localhost:8080/test.php"
echo "  - http://localhost:8080/index.php"
echo ""
echo "Presiona Ctrl+C para detener el servidor"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

php -S localhost:8080
