#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html/sistemaxpro}"
NODE_MODULES_STAMP="${NODE_MODULES_STAMP:-.deploy-node-modules.stamp}"

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
die() { log "ERROR: $*"; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Comando no encontrado: $1"
}

[[ -d "$APP_DIR" ]] || die "No existe APP_DIR=$APP_DIR"

cd "$APP_DIR"

if [[ -f package.json ]]; then
  require_cmd npm
  need_npm_install=0

  if [[ ! -d node_modules ]]; then
    need_npm_install=1
  elif [[ ! -f "$NODE_MODULES_STAMP" ]]; then
    need_npm_install=1
  elif [[ package.json -nt "$NODE_MODULES_STAMP" || package-lock.json -nt "$NODE_MODULES_STAMP" ]]; then
    need_npm_install=1
  fi

  if [[ "$need_npm_install" == "1" ]]; then
    log "Instalando dependencias npm..."
    npm install --no-audit --no-fund
    touch "$NODE_MODULES_STAMP"
  else
    log "Dependencias npm sin cambios."
  fi

  if npm run | grep -q 'build:heroicons'; then
    log "Generando mapa de heroicons..."
    npm run build:heroicons
  fi

  if npm run | grep -q 'build:css'; then
    log "Compilando CSS..."
    npm run build:css
  fi
fi

if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet php8.1-fpm; then
  log "Recargando php8.1-fpm..."
  systemctl reload php8.1-fpm || systemctl restart php8.1-fpm
else
  log "php8.1-fpm no esta activo o no existe, se omite recarga."
fi

log "Post-deploy completado."
