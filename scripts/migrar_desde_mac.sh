#!/usr/bin/env bash
set -euo pipefail

# Migracion desde Mac local: servidor viejo -> servidor nuevo
# Incluye todas las bases que contienen "_" (excluye esquemas del sistema)
#
# Uso:
#   ./scripts/migrar_desde_mac.sh
#
# Opcional (sin ejecutar, solo prueba conexiones):
#   DRY_RUN=1 ./scripts/migrar_desde_mac.sh

OLD_HOST="${OLD_HOST:-168.231.95.50}"
OLD_PORT="${OLD_PORT:-3306}"
OLD_USER="${OLD_USER:-sistemax}"

NEW_HOST="${NEW_HOST:-168.231.95.50}"
NEW_PORT="${NEW_PORT:-3306}"
NEW_USER="${NEW_USER:-sistemax}"

DRY_RUN="${DRY_RUN:-0}"

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
die() { log "ERROR: $*"; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Comando no encontrado: $1"
}

read -r -s -p "Password servidor viejo (${OLD_USER}@${OLD_HOST}): " OLD_PASS
echo
read -r -s -p "Password servidor nuevo (${NEW_USER}@${NEW_HOST}): " NEW_PASS
echo

[[ -n "${OLD_PASS}" ]] || die "Password del servidor viejo vacio"
[[ -n "${NEW_PASS}" ]] || die "Password del servidor nuevo vacio"

require_cmd mysql
require_cmd mysqldump
require_cmd pv

log "Validando conexion al servidor viejo..."
MYSQL_PWD="$OLD_PASS" mysql -h "$OLD_HOST" -P "$OLD_PORT" -u "$OLD_USER" -Nse "SELECT 1" >/dev/null \
  || die "No se pudo conectar al servidor viejo (${OLD_HOST}:${OLD_PORT})"

log "Validando conexion al servidor nuevo..."
MYSQL_PWD="$NEW_PASS" mysql -h "$NEW_HOST" -P "$NEW_PORT" -u "$NEW_USER" -Nse "SELECT 1" >/dev/null \
  || die "No se pudo conectar al servidor nuevo (${NEW_HOST}:${NEW_PORT})"

DBS="$(MYSQL_PWD="$OLD_PASS" mysql -h "$OLD_HOST" -P "$OLD_PORT" -u "$OLD_USER" -Nse "
SELECT SCHEMA_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME LIKE '%\\_%'
  AND SCHEMA_NAME NOT IN ('information_schema','performance_schema','mysql','sys')
")"

if [[ -z "${DBS}" ]]; then
  die "No se encontraron bases con '_' en el servidor viejo"
fi

log "Bases detectadas: $(wc -w <<< "$DBS" | tr -d ' ')"
log "Se migraran todas las bases con '_' (excepto sistema)"

if [[ "$DRY_RUN" == "1" ]]; then
  log "DRY_RUN=1 -> fin sin migrar."
  exit 0
fi

log "Iniciando migracion con barra de progreso..."
# shellcheck disable=SC2086
MYSQL_PWD="$OLD_PASS" mysqldump -h "$OLD_HOST" -P "$OLD_PORT" -u "$OLD_USER" \
  --single-transaction --routines --triggers --events \
  --set-gtid-purged=OFF --column-statistics=0 --default-character-set=utf8mb4 \
  --databases $DBS \
| pv \
| MYSQL_PWD="$NEW_PASS" mysql -h "$NEW_HOST" -P "$NEW_PORT" -u "$NEW_USER"

log "Migracion finalizada."
log "Verificando cantidad de empresas en serproc1.empresa del nuevo servidor..."
MYSQL_PWD="$NEW_PASS" mysql -h "$NEW_HOST" -P "$NEW_PORT" -u "$NEW_USER" -Nse "SELECT COUNT(*) FROM serproc1.empresa;" | sed 's/^/empresas: /'

log "OK"
