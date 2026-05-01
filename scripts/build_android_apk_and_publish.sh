#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ANDROID_DIR="$ROOT_DIR/agent/android"
PUBLISH_DIR="$ROOT_DIR/public/pos/downloads"
TARGET_APK="$PUBLISH_DIR/sistemax-agent-android-0.1.0.apk"

echo "[1/5] Verificando herramientas..."
if ! command -v java >/dev/null 2>&1; then
  echo "ERROR: Java no encontrado. Instalá JDK 17+."
  exit 1
fi

if [ -x "$ANDROID_DIR/gradlew" ]; then
  GRADLE_CMD="$ANDROID_DIR/gradlew"
elif command -v gradle >/dev/null 2>&1; then
  GRADLE_CMD="gradle"
else
  echo "ERROR: No se encontró gradle/gradlew."
  echo "Sugerencia: abrí agent/android en Android Studio y ejecutá Build > Build APK(s)."
  exit 1
fi

echo "[2/5] Compilando APK debug (firmada automaticamente)..."
cd "$ANDROID_DIR"
"$GRADLE_CMD" :app:assembleDebug

echo "[3/5] Localizando APK generado..."
APK_SRC="$(find "$ANDROID_DIR/app/build/outputs/apk" -type f -name '*debug*.apk' | head -n 1 || true)"
if [ -z "${APK_SRC:-}" ] || [ ! -f "$APK_SRC" ]; then
  echo "ERROR: No se encontró APK debug generado."
  exit 1
fi
echo "APK encontrado: $APK_SRC"

echo "[4/5] Publicando APK en downloads..."
mkdir -p "$PUBLISH_DIR"
cp -f "$APK_SRC" "$TARGET_APK"
chmod 644 "$TARGET_APK"

echo "[5/5] Listo."
echo "Publicado: $TARGET_APK"
ls -lh "$TARGET_APK"
echo
echo "Ahora en SistemaX se habilita automáticamente 'Instalar ahora (Android)'."
