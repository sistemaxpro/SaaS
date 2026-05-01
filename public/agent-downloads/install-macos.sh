#!/bin/bash
# Script de instalación del SistemaX Agent para macOS

set -e

# Detectar arquitectura
ARCH=$(uname -m)
case "$ARCH" in
    arm64)
        BINARY="sistemax-agent-macos-arm64"
        ;;
    x86_64)
        BINARY="sistemax-agent-macos-amd64"
        ;;
    *)
        echo "❌ Arquitectura no soportada: $ARCH"
        echo "   Soportadas: arm64 (Apple Silicon), x86_64 (Intel)"
        exit 1
        ;;
esac

INSTALL_DIR="$HOME/.sistemax"
BINARY_PATH="$INSTALL_DIR/$BINARY"

echo "📦 SistemaX Agent Installer para macOS"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Arquitectura detectada: $ARCH"
echo "Binario: $BINARY"
echo "Destino: $BINARY_PATH"
echo ""

# Crear directorio de instalación
mkdir -p "$INSTALL_DIR"

# Descargar binario
if [ ! -f "$BINARY" ]; then
    echo "❌ Archivo $BINARY no encontrado"
    echo "   Asegúrate de ejecutar este script desde la carpeta donde descargaste los binarios"
    exit 1
fi

# Copiar y hacer ejecutable
cp "$BINARY" "$BINARY_PATH"
chmod +x "$BINARY_PATH"
echo "✅ Binario instalado en: $BINARY_PATH"

# Crear alias en ~/.zprofile para fácil acceso
if [ -f "$HOME/.zprofile" ]; then
    if ! grep -q "alias sistemax-agent" "$HOME/.zprofile"; then
        echo "alias sistemax-agent='$BINARY_PATH'" >> "$HOME/.zprofile"
        echo "✅ Alias 'sistemax-agent' añadido a ~/.zprofile"
    fi
fi

echo ""
echo "🚀 Para iniciar el agente, ejecuta:"
echo "   sistemax-agent"
echo ""
echo "   O directamente:"
echo "   $BINARY_PATH"
echo ""
echo "El agente escuchará en: http://127.0.0.1:17890"
