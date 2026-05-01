#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_SOURCE="$SCRIPT_DIR/sistemax-agent"

if [[ ! -f "$BIN_SOURCE" ]]; then
  ARCH="$(uname -m || true)"
  if [[ "$ARCH" == "arm64" && -f "$SCRIPT_DIR/sistemax-agent-darwin-arm64" ]]; then
    BIN_SOURCE="$SCRIPT_DIR/sistemax-agent-darwin-arm64"
  elif [[ -f "$SCRIPT_DIR/sistemax-agent-darwin-amd64" ]]; then
    BIN_SOURCE="$SCRIPT_DIR/sistemax-agent-darwin-amd64"
  fi
fi

if [[ ! -f "$BIN_SOURCE" ]]; then
  echo "No se encontro binario del Agent en este paquete."
  echo "Esperado: sistemax-agent o sistemax-agent-darwin-(amd64|arm64)"
  exit 1
fi

AGENT_HOME="$HOME/.sistemax-agent"
BIN_DEST="$AGENT_HOME/bin/sistemax-agent"
LOG_DIR="$AGENT_HOME/logs"
PLIST_DIR="$HOME/Library/LaunchAgents"
PLIST_PATH="$PLIST_DIR/com.sistemax.agent.plist"

mkdir -p "$AGENT_HOME/bin" "$LOG_DIR" "$PLIST_DIR"
cp "$BIN_SOURCE" "$BIN_DEST"
chmod +x "$BIN_DEST"
xattr -d com.apple.quarantine "$BIN_DEST" >/dev/null 2>&1 || true

TEMPLATE_PATH="$SCRIPT_DIR/com.sistemax.agent.plist.template"
if [[ ! -f "$TEMPLATE_PATH" ]]; then
  TEMPLATE_PATH="$SCRIPT_DIR/com.sistemax.agent.plist"
fi

if [[ ! -f "$TEMPLATE_PATH" ]]; then
  echo "No se encontro plantilla plist"
  exit 1
fi

sed \
  -e "s|__AGENT_BIN__|$BIN_DEST|g" \
  -e "s|__LOG_DIR__|$LOG_DIR|g" \
  "$TEMPLATE_PATH" > "$PLIST_PATH"

if command -v plutil >/dev/null 2>&1; then
  if ! plutil -lint "$PLIST_PATH"; then
    echo "El LaunchAgent generado no es valido:"
    echo "  $PLIST_PATH"
    exit 1
  fi
fi

xattr -d com.apple.quarantine "$PLIST_PATH" >/dev/null 2>&1 || true

launchctl bootout "gui/$(id -u)/com.sistemax.agent" >/dev/null 2>&1 || true
if ! launchctl bootstrap "gui/$(id -u)" "$PLIST_PATH"; then
  echo "bootstrap fallo; intentando load -w..."
  launchctl unload "$PLIST_PATH" >/dev/null 2>&1 || true
  launchctl load -w "$PLIST_PATH"
fi
launchctl enable "gui/$(id -u)/com.sistemax.agent" >/dev/null 2>&1 || true
launchctl kickstart -k "gui/$(id -u)/com.sistemax.agent" >/dev/null 2>&1 || true

sleep 1
if curl -fsS http://127.0.0.1:17890/health >/dev/null 2>&1; then
  echo "Sistemax Agent instalado y activo en macOS"
  echo "Health: http://127.0.0.1:17890/health"
else
  echo "Instalado, pero aun no responde /health. Revisa logs en: $LOG_DIR"
fi
