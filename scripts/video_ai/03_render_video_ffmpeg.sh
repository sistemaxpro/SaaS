#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "" ] || [ "${2:-}" = "" ] || [ "${3:-}" = "" ]; then
  echo "Uso: $0 <slides_dir> <audio.mp3> <video_output.mp4>"
  exit 1
fi

if ! command -v ffmpeg >/dev/null 2>&1; then
  echo "Error: ffmpeg no esta instalado"
  exit 1
fi

SLIDES_DIR="$1"
AUDIO_FILE="$2"
OUTPUT_VIDEO="$3"

SLIDES_DIR="$(realpath "$SLIDES_DIR")"
AUDIO_FILE="$(realpath "$AUDIO_FILE")"
mkdir -p "$(dirname "$OUTPUT_VIDEO")"
OUTPUT_VIDEO="$(realpath "$(dirname "$OUTPUT_VIDEO")")/$(basename "$OUTPUT_VIDEO")"

if [ ! -d "$SLIDES_DIR" ]; then
  echo "Error: no existe directorio de slides $SLIDES_DIR"
  exit 1
fi

if [ ! -f "$AUDIO_FILE" ]; then
  echo "Error: no existe audio $AUDIO_FILE"
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
LIST_FILE="$TMP_DIR/slides.txt"

SLIDES=()
while IFS= read -r f; do SLIDES+=("$f"); done < <(
  find "$SLIDES_DIR" -maxdepth 1 -type f \( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' \) | sort
)

COUNT="${#SLIDES[@]}"
if [ "$COUNT" -eq 0 ]; then
  echo "Error: no hay imagenes en $SLIDES_DIR"
  exit 1
fi

AUDIO_SECONDS="$(ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 "$AUDIO_FILE" | awk '{printf "%.3f", $1}')"
if [ -z "$AUDIO_SECONDS" ]; then
  echo "Error: no se pudo leer duracion del audio"
  exit 1
fi

DUR_PER_SLIDE="$(awk -v a="$AUDIO_SECONDS" -v c="$COUNT" 'BEGIN { printf "%.3f", (a/c) }')"

{
  for s in "${SLIDES[@]}"; do
    echo "file '$s'"
    echo "duration $DUR_PER_SLIDE"
  done
  echo "file '${SLIDES[$((COUNT-1))]}'"
} > "$LIST_FILE"

ffmpeg -y \
  -f concat -safe 0 -i "$LIST_FILE" \
  -i "$AUDIO_FILE" \
  -vf "scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2,format=yuv420p" \
  -c:v libx264 -preset veryfast -crf 22 \
  -c:a aac -b:a 192k \
  -shortest \
  "$OUTPUT_VIDEO"

echo "OK video: $OUTPUT_VIDEO"
