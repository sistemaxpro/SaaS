#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
VERSION="${1:-0.1.1}"

mkdir -p "$DIST_DIR"

echo "[1/3] Build linux/amd64"
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o "$DIST_DIR/sistemax-agent-linux-amd64" "$ROOT_DIR"

echo "[2/3] Build linux/arm64"
GOOS=linux GOARCH=arm64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o "$DIST_DIR/sistemax-agent-linux-arm64" "$ROOT_DIR"

echo "[3/3] Create distributable source package"
ARCHIVE_DIR="$DIST_DIR/sistemax-agent-linux-$VERSION"
rm -rf "$ARCHIVE_DIR"
mkdir -p "$ARCHIVE_DIR"
cp "$DIST_DIR/sistemax-agent-linux-amd64" "$ARCHIVE_DIR/"
cp "$DIST_DIR/sistemax-agent-linux-arm64" "$ARCHIVE_DIR/"
cp "$ROOT_DIR/linux/install-linux.sh" "$ARCHIVE_DIR/install-linux.sh"
cp "$ROOT_DIR/linux/uninstall-linux.sh" "$ARCHIVE_DIR/uninstall-linux.sh"
cp "$ROOT_DIR/linux/com.sistemax.agent.service" "$ARCHIVE_DIR/com.sistemax.agent.service.template"
chmod +x "$ARCHIVE_DIR/install-linux.sh" "$ARCHIVE_DIR/uninstall-linux.sh"

tar -czf "$DIST_DIR/sistemax-agent-linux-$VERSION.tar.gz" -C "$DIST_DIR" "sistemax-agent-linux-$VERSION"

echo "Done: $DIST_DIR/sistemax-agent-linux-$VERSION.tar.gz"
