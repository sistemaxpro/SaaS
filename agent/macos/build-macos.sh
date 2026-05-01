#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
VERSION="${1:-0.1.1}"

mkdir -p "$DIST_DIR"

echo "[1/4] Build darwin/amd64"
GOOS=darwin GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o "$DIST_DIR/sistemax-agent-darwin-amd64" "$ROOT_DIR"

echo "[2/4] Build darwin/arm64"
GOOS=darwin GOARCH=arm64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o "$DIST_DIR/sistemax-agent-darwin-arm64" "$ROOT_DIR"

echo "[3/4] Create universal binary (if supported)"
if command -v lipo >/dev/null 2>&1; then
  lipo -create \
    "$DIST_DIR/sistemax-agent-darwin-amd64" \
    "$DIST_DIR/sistemax-agent-darwin-arm64" \
    -output "$DIST_DIR/sistemax-agent-macos"
  chmod +x "$DIST_DIR/sistemax-agent-macos"
  echo " - universal binary creado con lipo"
else
  echo " - lipo no disponible, se empaquetan binarios por arquitectura"
fi

echo "[4/4] Create distributable archive"
ARCHIVE_DIR="$DIST_DIR/sistemax-agent-macos-$VERSION"
rm -rf "$ARCHIVE_DIR"
mkdir -p "$ARCHIVE_DIR"
if [[ -f "$DIST_DIR/sistemax-agent-macos" ]]; then
  cp "$DIST_DIR/sistemax-agent-macos" "$ARCHIVE_DIR/sistemax-agent"
fi
cp "$DIST_DIR/sistemax-agent-darwin-amd64" "$ARCHIVE_DIR/sistemax-agent-darwin-amd64"
cp "$DIST_DIR/sistemax-agent-darwin-arm64" "$ARCHIVE_DIR/sistemax-agent-darwin-arm64"
cp "$ROOT_DIR/macos/install-macos.sh" "$ARCHIVE_DIR/install-macos.sh"
cp "$ROOT_DIR/macos/uninstall-macos.sh" "$ARCHIVE_DIR/uninstall-macos.sh"
cp "$ROOT_DIR/macos/com.sistemax.agent.plist" "$ARCHIVE_DIR/com.sistemax.agent.plist.template"
chmod +x \
  "$ARCHIVE_DIR/install-macos.sh" \
  "$ARCHIVE_DIR/uninstall-macos.sh" \
  "$ARCHIVE_DIR/sistemax-agent-darwin-amd64" \
  "$ARCHIVE_DIR/sistemax-agent-darwin-arm64"
if [[ -f "$ARCHIVE_DIR/sistemax-agent" ]]; then
  chmod +x "$ARCHIVE_DIR/sistemax-agent"
fi

tar -czf "$DIST_DIR/sistemax-agent-macos-$VERSION.tar.gz" -C "$DIST_DIR" "sistemax-agent-macos-$VERSION"

echo "Done:"
echo " - Universal binary: $DIST_DIR/sistemax-agent-macos"
echo " - Tarball: $DIST_DIR/sistemax-agent-macos-$VERSION.tar.gz"
