#!/bin/bash

# ============================================================================
# Script: reset_mock_station.sh
# Propósito: Limpiar y volver a cargar el mock de estación en un solo paso
# Uso: ./scripts/reset_mock_station.sh
# ============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  RESET MOCK - Estación de Servicio                           ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"

cd "$ROOT_DIR"

if ! command -v php >/dev/null 2>&1; then
    echo -e "${RED}✗ PHP no está disponible en PATH${NC}"
    exit 1
fi

echo -e "${YELLOW}→ Limpiando base mock...${NC}"
php scripts/cleanup_mock_station.php --apply --force

echo -e "${YELLOW}→ Verificando esquema y tablas...${NC}"
php verify-mock-setup.php --create-schema

echo -e "${YELLOW}→ Cargando datos mock...${NC}"
php load-mock-data.php

echo ""
echo -e "${GREEN}✅ RESET MOCK COMPLETADO${NC}"
echo -e "${CYAN}→ La base mock quedó limpia y recargada${NC}"
