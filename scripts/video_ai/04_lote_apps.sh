#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "Uso: $0 <app1> [app2] [app3] ..."
  echo "Ejemplo: $0 pos misventas cajas productos"
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

GEN_SCRIPT="$ROOT_DIR/scripts/video_ai/01_generar_guion_openai.sh"
TTS_SCRIPT="$ROOT_DIR/scripts/video_ai/02_generar_audio_elevenlabs.sh"
RENDER_SCRIPT="$ROOT_DIR/scripts/video_ai/03_render_video_ffmpeg.sh"

for app in "$@"; do
  echo "=============================="
  echo "APP: $app"
  echo "=============================="

  SRC_DIR="$ROOT_DIR/video_src/$app"
  OUT_DIR="$ROOT_DIR/video_out/$app"

  GUIA_FILE="$SRC_DIR/guia.md"
  SLIDES_DIR="$SRC_DIR/slides"
  GUION_OUT="$OUT_DIR/guion.txt"
  AUDIO_OUT="$OUT_DIR/audio.mp3"
  VIDEO_OUT="$OUT_DIR/video.mp4"

  if [ ! -f "$GUIA_FILE" ]; then
    echo "SKIP $app: falta $GUIA_FILE"
    continue
  fi
  if [ ! -d "$SLIDES_DIR" ]; then
    echo "SKIP $app: falta $SLIDES_DIR"
    continue
  fi

  mkdir -p "$OUT_DIR"

  bash "$GEN_SCRIPT" "$GUIA_FILE" "$GUION_OUT"
  bash "$TTS_SCRIPT" "$GUION_OUT" "$AUDIO_OUT"
  bash "$RENDER_SCRIPT" "$SLIDES_DIR" "$AUDIO_OUT" "$VIDEO_OUT"

  echo "OK APP $app -> $VIDEO_OUT"
done
