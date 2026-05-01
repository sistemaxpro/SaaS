#!/usr/bin/env bash
set -euo pipefail

AGENT_HOME="$HOME/.sistemax-agent"
SERVICE_PATH="$HOME/.config/systemd/user/com.sistemax.agent.service"

systemctl --user disable --now com.sistemax.agent.service >/dev/null 2>&1 || true
systemctl --user daemon-reload >/dev/null 2>&1 || true

rm -f "$SERVICE_PATH"
rm -rf "$AGENT_HOME"

echo "Sistemax Agent removido de Linux"
