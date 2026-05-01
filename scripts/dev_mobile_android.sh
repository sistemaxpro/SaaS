#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8000}"
LAN_IP="${LAN_IP:-$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || true)}"
START_URL_DEFAULT="http://${LAN_IP:-127.0.0.1}:${PORT}/public/login.php"
START_URL="${SISTEMAX_ANDROID_START_URL:-$START_URL_DEFAULT}"

export SISTEMAX_ENV="dev"
export SISTEMAX_ANDROID_START_URL="$START_URL"

printf '%s\n' "Servidor web listo para móvil."
printf '%s\n' "URL para abrir en Android: $START_URL"
printf '%s\n' "URL para compilar APK local: $START_URL"
printf '%s\n' "Usa esta terminal para dejar corriendo el servidor."

exec php -S 0.0.0.0:"$PORT" "$ROOT_DIR/router.dev.php"
