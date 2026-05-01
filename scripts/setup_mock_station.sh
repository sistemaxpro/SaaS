#!/bin/bash

# ============================================================================
# Script: setup_mock_station.sh
# Propósito: Verificar, crear esquema y cargar datos mock de estación
# Uso: ./scripts/setup_mock_station.sh
# ============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  SETUP MOCK - Estación de Servicio                           ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"

cd "$ROOT_DIR"

if ! command -v php >/dev/null 2>&1; then
    echo -e "${RED}✗ PHP no está disponible en PATH${NC}"
    exit 1
fi

if [ "${1:-}" = "--clean" ]; then
    echo -e "${YELLOW}→ Limpiando base mock antes de cargar...${NC}"
    php scripts/cleanup_mock_station.php --apply --force
fi

echo -e "${YELLOW}→ Verificando esquema y tablas...${NC}"
php verify-mock-setup.php --create-schema

echo -e "${YELLOW}→ Cargando datos mock...${NC}"
php load-mock-data.php

echo ""
echo -e "${GREEN}✅ SETUP MOCK COMPLETADO${NC}"
echo -e "${CYAN}→ Puedes re-ejecutar este comando cuando necesites resetear el demo${NC}"
