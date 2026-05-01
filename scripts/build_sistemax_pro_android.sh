#!/bin/bash

# ============================================================================
# Script: build_sistemax_pro_android.sh
# Propósito: Compilar SistemaX Pro Android v0.2.0+
# Permisos: Cámara, Micrófono, Bluetooth, GPS, Notificaciones
# Ubicación: dentro del árbol del proyecto
# ============================================================================

set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="$ROOT_DIR/apps/sistemaxpro-android"
OUTPUT_DIR="$ROOT_DIR/public/pos/downloads"
BUILD_TYPE="${1:-debug}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'

echo -e "${GREEN}╔═══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  BUILD: SistemaX Pro Android - Full Permissions              ║${NC}"
echo -e "${GREEN}╚═══════════════════════════════════════════════════════════════╝${NC}"

[ ! -d "$APP_DIR" ] && echo -e "${RED}✗ Directorio no encontrado: $APP_DIR${NC}" && exit 1
cd "$APP_DIR"

if [ -f gradlew ]; then GRADLE_CMD="./gradlew"; chmod +x gradlew
elif command -v gradle &>/dev/null; then GRADLE_CMD="gradle"
else echo -e "${RED}✗ Gradle no encontrado${NC}"; exit 1; fi

VERSION="0.2.0"
[ -f app/build.gradle.kts ] && V=$(grep 'versionName' app/build.gradle.kts | head -1 | sed 's/.*"\(.*\)".*/\1/') && [ -n "$V" ] && VERSION="$V"
echo -e "${CYAN}→ Versión: $VERSION | Build: $BUILD_TYPE${NC}"
if [ -n "${SISTEMAX_ANDROID_START_URL:-}" ]; then
    echo -e "${CYAN}→ START_URL: ${SISTEMAX_ANDROID_START_URL}${NC}"
fi

echo -e "${YELLOW}→ Limpiando...${NC}"
$GRADLE_CMD clean

if [ "$BUILD_TYPE" = "release" ]; then
    $GRADLE_CMD assembleRelease; APK_SOURCE="app/build/outputs/apk/release/app-release.apk"
else
    $GRADLE_CMD assembleDebug; APK_SOURCE="app/build/outputs/apk/debug/app-debug.apk"
fi

[ ! -f "$APK_SOURCE" ] && echo -e "${RED}✗ APK no generado${NC}" && exit 1

mkdir -p "$OUTPUT_DIR"
APK_FILENAME="sistemax-pro-android-${VERSION}.apk"
cp "$APK_SOURCE" "$OUTPUT_DIR/$APK_FILENAME"
APK_SIZE=$(du -h "$OUTPUT_DIR/$APK_FILENAME" | cut -f1)

echo ""
echo -e "${GREEN}✅ BUILD EXITOSO${NC}"
echo -e "  📦 $APK_FILENAME ($APK_SIZE)"
echo -e "  📁 $OUTPUT_DIR/$APK_FILENAME"
echo -e "  🔑 Permisos: Cámara, Mic, BT, GPS, Notif, FileChooser"
echo -e "  🌐 https://sistemax.pro/public/apps-moviles.php"
echo -e "${RED}  ⚠️  Usuario debe DESINSTALAR versión anterior antes de instalar${NC}"
echo ""
