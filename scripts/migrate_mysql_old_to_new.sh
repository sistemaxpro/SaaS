#!/usr/bin/env bash
set -euo pipefail

# Migracion MySQL: servidor viejo -> servidor nuevo
# Uso:
#   OLD_HOST=168.231.95.50 OLD_USER=sistemax OLD_PASS='xxx' \
#   NEW_HOST=168.231.95.50 NEW_USER=sistemax NEW_PASS='yyy' \
#   ./scripts/migrate_mysql_old_to_new.sh
#
# Opcionales:
#   OLD_PORT=3306 NEW_PORT=3306
#   OUT_DIR=/tmp
#   DBS="serproc1 empresa_169 empresa_170"    # si no se define, usa --all-databases
#   DRY_RUN=1                                 # solo valida conectividad/comandos

OLD_HOST="${OLD_HOST:-}"
OLD_PORT="${OLD_PORT:-3306}"
OLD_USER="${OLD_USER:-}"
OLD_PASS="${OLD_PASS:-}"

NEW_HOST="${NEW_HOST:-}"
NEW_PORT="${NEW_PORT:-3306}"
NEW_USER="${NEW_USER:-}"
NEW_PASS="${NEW_PASS:-}"

OUT_DIR="${OUT_DIR:-/tmp}"
DBS="${DBS:-}"
DRY_RUN="${DRY_RUN:-0}"

ts="$(date +%Y%m%d_%H%M%S)"
dump_file="${OUT_DIR}/mysql_migracion_${ts}.sql.gz"

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
die() { log "ERROR: $*"; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "No existe comando requerido: $1"
}

check_required_vars() {
  [[ -n "$OLD_HOST" ]] || die "Falta OLD_HOST"
  [[ -n "$OLD_USER" ]] || die "Falta OLD_USER"
  [[ -n "$OLD_PASS" ]] || die "Falta OLD_PASS"
  [[ -n "$NEW_HOST" ]] || die "Falta NEW_HOST"
  [[ -n "$NEW_USER" ]] || die "Falta NEW_USER"
  [[ -n "$NEW_PASS" ]] || die "Falta NEW_PASS"
}

mysql_ping() {
  local host="$1" port="$2" user="$3" pass="$4" label="$5"
  log "Probando conexion a ${label} (${host}:${port})..."
  MYSQL_PWD="$pass" mysql -h "$host" -P "$port" -u "$user" -Nse "SELECT 1" >/dev/null \
    || die "No se pudo conectar a ${label} (${host}:${port})"
}

build_dump_command() {
  local cmd=(mysqldump
    -h "$OLD_HOST" -P "$OLD_PORT" -u "$OLD_USER"
    --single-transaction
    --routines --triggers --events
    --set-gtid-purged=OFF
    --default-character-set=utf8mb4
  )

  if [[ -n "$DBS" ]]; then
    # shellcheck disable=SC2206
    local db_list=( $DBS )
    cmd+=(--databases "${db_list[@]}")
  else
    cmd+=(--all-databases)
  fi

  printf '%q ' "${cmd[@]}"
}

main() {
  require_cmd mysql
  require_cmd mysqldump
  require_cmd gzip
  require_cmd gunzip
  check_required_vars

  mkdir -p "$OUT_DIR"

  mysql_ping "$OLD_HOST" "$OLD_PORT" "$OLD_USER" "$OLD_PASS" "servidor viejo"
  mysql_ping "$NEW_HOST" "$NEW_PORT" "$NEW_USER" "$NEW_PASS" "servidor nuevo"

  local dump_cmd
  dump_cmd="$(build_dump_command)"

  log "Resumen:"
  log "  Viejo: ${OLD_HOST}:${OLD_PORT} (${OLD_USER})"
  log "  Nuevo: ${NEW_HOST}:${NEW_PORT} (${NEW_USER})"
  if [[ -n "$DBS" ]]; then
    log "  DBs:   ${DBS}"
  else
    log "  DBs:   ALL"
  fi
  log "  Backup: ${dump_file}"

  if [[ "$DRY_RUN" == "1" ]]; then
    log "DRY_RUN=1, no se ejecuta dump/import."
    exit 0
  fi

  log "Generando backup comprimido..."
  # shellcheck disable=SC2086
  MYSQL_PWD="$OLD_PASS" eval "$dump_cmd" | gzip > "$dump_file"
  log "Backup OK: ${dump_file}"

  log "Importando al servidor nuevo..."
  gunzip -c "$dump_file" | MYSQL_PWD="$NEW_PASS" mysql -h "$NEW_HOST" -P "$NEW_PORT" -u "$NEW_USER"
  log "Importacion finalizada."

  log "Chequeo rapido de bases en servidor nuevo:"
  MYSQL_PWD="$NEW_PASS" mysql -h "$NEW_HOST" -P "$NEW_PORT" -u "$NEW_USER" -Nse "SHOW DATABASES;" | sed 's/^/  - /'

  log "Proceso completado."
}

main "$@"
