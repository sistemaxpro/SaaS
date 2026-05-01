#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

ARCH="$(uname -m)"
BIN_SOURCE=""
case "$ARCH" in
  x86_64|amd64) BIN_SOURCE="$SCRIPT_DIR/sistemax-agent-linux-amd64" ;;
  aarch64|arm64) BIN_SOURCE="$SCRIPT_DIR/sistemax-agent-linux-arm64" ;;
  *) echo "Arquitectura no soportada: $ARCH"; exit 1 ;;
esac

if [[ ! -f "$BIN_SOURCE" ]]; then
  echo "No se encontró binario: $BIN_SOURCE"
  exit 1
fi

AGENT_HOME="$HOME/.sistemax-agent"
BIN_DEST="$AGENT_HOME/bin/sistemax-agent"
LOG_DIR="$AGENT_HOME/logs"
SERVICE_DIR="$HOME/.config/systemd/user"
SERVICE_PATH="$SERVICE_DIR/com.sistemax.agent.service"

mkdir -p "$AGENT_HOME/bin" "$LOG_DIR" "$SERVICE_DIR"
cp "$BIN_SOURCE" "$BIN_DEST"
chmod +x "$BIN_DEST"

TEMPLATE_PATH="$SCRIPT_DIR/com.sistemax.agent.service.template"
if [[ ! -f "$TEMPLATE_PATH" ]]; then
  TEMPLATE_PATH="$SCRIPT_DIR/com.sistemax.agent.service"
fi
if [[ ! -f "$TEMPLATE_PATH" ]]; then
  echo "No se encontró plantilla systemd"
  exit 1
fi

sed \
  -e "s|__AGENT_BIN__|$BIN_DEST|g" \
  -e "s|__AGENT_HOME__|$AGENT_HOME|g" \
  -e "s|__LOG_DIR__|$LOG_DIR|g" \
  "$TEMPLATE_PATH" > "$SERVICE_PATH"

systemctl --user daemon-reload
systemctl --user enable --now com.sistemax.agent.service

sleep 1
if curl -fsS http://127.0.0.1:17890/health >/dev/null 2>&1; then
  echo "Sistemax Agent instalado y activo en Linux"
else
  echo "Instalado, pero aún no responde /health. Revisar logs en $LOG_DIR"
fi
