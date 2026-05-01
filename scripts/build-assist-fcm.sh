#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ANDROID_DIR="$ROOT_DIR/agent/android"
APK_SOURCE="$ANDROID_DIR/app/build/outputs/apk/debug/app-debug.apk"
APK_TARGET_DIR="$ROOT_DIR/public/soporte/downloads"
APK_TARGET="$APK_TARGET_DIR/sistemax-assist-android.apk"
VERSIONED_TARGET="$APK_TARGET_DIR/sistemax-assist-android-0.3.6.apk"

required_vars=(
  SISTEMAX_FIREBASE_APP_ID
  SISTEMAX_FIREBASE_API_KEY
  SISTEMAX_FIREBASE_PROJECT_ID
  SISTEMAX_FIREBASE_SENDER_ID
)

missing=()
for var_name in "${required_vars[@]}"; do
  if [[ -z "${!var_name:-}" ]]; then
    missing+=("$var_name")
  fi
done

if [[ ${#missing[@]} -gt 0 ]]; then
  printf 'Faltan variables Firebase requeridas:\n' >&2
  for item in "${missing[@]}"; do
    printf '  - %s\n' "$item" >&2
  done
  printf '\nEjemplo:\n' >&2
  cat <<'EOF' >&2
export SISTEMAX_FIREBASE_APP_ID="1:1234567890:android:abcd1234"
export SISTEMAX_FIREBASE_API_KEY="AIza..."
export SISTEMAX_FIREBASE_PROJECT_ID="tu-project-id"
export SISTEMAX_FIREBASE_SENDER_ID="1234567890"
export SISTEMAX_FIREBASE_STORAGE_BUCKET="tu-project-id.firebasestorage.app"
export SISTEMAX_FIREBASE_SERVICE_ACCOUNT_FILE="/var/www/html/desarrollo/config/firebase_service_account.json"
EOF
  exit 1
fi

if [[ -z "${SISTEMAX_FIREBASE_SERVICE_ACCOUNT_FILE:-}" && -z "${SISTEMAX_FIREBASE_SERVICE_ACCOUNT_JSON:-}" ]]; then
  printf 'Aviso: no definiste service account para envio server-to-server.\n' >&2
  printf 'La app compila, pero el backend no podra enviar FCM real hasta cargar una credencial.\n' >&2
fi

mkdir -p "$APK_TARGET_DIR"

pushd "$ANDROID_DIR" >/dev/null
gradle :app:assembleDebug
popd >/dev/null

if [[ ! -f "$APK_SOURCE" ]]; then
  printf 'No se encontro el APK compilado en %s\n' "$APK_SOURCE" >&2
  exit 1
fi

install -m 0644 "$APK_SOURCE" "$APK_TARGET"
install -m 0644 "$APK_SOURCE" "$VERSIONED_TARGET"

printf 'APK generado y publicado:\n'
printf '  %s\n' "$APK_TARGET"
printf '  %s\n' "$VERSIONED_TARGET"
printf '\nSHA256:\n'
sha256sum "$APK_TARGET" "$VERSIONED_TARGET"
