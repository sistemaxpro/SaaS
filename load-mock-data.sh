#!/bin/bash

# Script para cargar datos mock - Estación de Servicio
# SOLO PARA DESARROLLO

set -e

# Cargar configuración desde .env.mock si existe
if [ -f ".env.mock" ]; then
    export $(cat .env.mock | grep -v '^#' | xargs)
fi

HOST="${MOCK_DB_HOST:-localhost}"
PORT="${MOCK_DB_PORT:-3307}"
USER="${MOCK_DB_USER:-desarrollo}"
PASSWORD="${MOCK_DB_PASS:-desarrollo123}"
DATABASE="${MOCK_DB_NAME:-desarrollo}"
ALLOWED_PORT="${MOCK_ALLOWED_PORT:-3307}"
SQL_FILE="database/seeds/estacion_datos_mock.sql"

echo "=================================="
echo "Cargando datos mock - Estación"
echo "=================================="
echo "BD: $DATABASE@$HOST:$PORT"
echo ""

# Protección: verificar que es el puerto correcto
if [ "$PORT" != "3307" ]; then
    echo "❌ ERROR: Este script solo funciona en desarrollo (puerto 3307)"
    exit 1
fi

# Verificar que el archivo SQL existe
if [ ! -f "$SQL_FILE" ]; then
    echo "❌ Archivo no encontrado: $SQL_FILE"
    exit 1
fi

# Ejecutar SQL
echo "📋 Ejecutando SQL..."
mysql -h "$HOST" -P "$PORT" -u "$USER" -p"$PASSWORD" "$DATABASE" < "$SQL_FILE"

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Datos cargados exitosamente"
    echo ""
    echo "📊 Datos importados:"
    echo "   • 2 tanques"
    echo "   • 3 surtidores"
    echo "   • 6 picos (2 por surtidor)"
    echo "   • 2 turnos"
    echo "   • 8 despachos/ventas"
    echo "   • Histórico de lecturas y precios"
else
    echo "❌ Error al ejecutar el SQL"
    exit 1
fi
