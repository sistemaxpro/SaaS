#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
VERSION="${1:-0.1.2}"

mkdir -p "$DIST_DIR"

echo "[1/4] Build windows/amd64 agent binary"
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o "$DIST_DIR/sistemax-agent.exe" "$ROOT_DIR"
cp "$DIST_DIR/sistemax-agent.exe" "$DIST_DIR/sistemax-agent-windows-agent-$VERSION.exe"

echo "[2/4] Prepare source package folder"
PKG_DIR="$DIST_DIR/sistemax-agent-windows-$VERSION"
rm -rf "$PKG_DIR"
mkdir -p "$PKG_DIR"
cp "$DIST_DIR/sistemax-agent.exe" "$PKG_DIR/sistemax-agent.exe"
cp "$ROOT_DIR/windows/install-windows.ps1" "$PKG_DIR/install-windows.ps1"
cp "$ROOT_DIR/windows/uninstall-windows.ps1" "$PKG_DIR/uninstall-windows.ps1"
cp "$ROOT_DIR/windows/README.txt" "$PKG_DIR/README.txt"

echo "[3/4] Create tar.gz package"
tar -czf "$DIST_DIR/sistemax-agent-windows-$VERSION.tar.gz" -C "$DIST_DIR" "sistemax-agent-windows-$VERSION"
cp "$DIST_DIR/sistemax-agent-windows-$VERSION.tar.gz" "$DIST_DIR/sistemax-agent-windows-source-$VERSION.tar.gz"

echo "[4/4] Build setup bootstrap exe"
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o "$DIST_DIR/Sistemax-Agent-Setup-$VERSION.exe" "$ROOT_DIR/windows/setup"

echo "Done:"
echo " - Agent exe: $DIST_DIR/sistemax-agent-windows-agent-$VERSION.exe"
echo " - Source tar: $DIST_DIR/sistemax-agent-windows-$VERSION.tar.gz"
echo " - Source alias: $DIST_DIR/sistemax-agent-windows-source-$VERSION.tar.gz"
echo " - Setup exe: $DIST_DIR/Sistemax-Agent-Setup-$VERSION.exe"
