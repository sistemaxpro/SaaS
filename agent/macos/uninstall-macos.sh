#!/usr/bin/env bash
set -euo pipefail

AGENT_HOME="$HOME/.sistemax-agent"
PLIST_PATH="$HOME/Library/LaunchAgents/com.sistemax.agent.plist"

launchctl bootout "gui/$(id -u)/com.sistemax.agent" >/dev/null 2>&1 || true
launchctl disable "gui/$(id -u)/com.sistemax.agent" >/dev/null 2>&1 || true

rm -f "$PLIST_PATH"
rm -rf "$AGENT_HOME"

echo "Sistemax Agent removido de macOS"
