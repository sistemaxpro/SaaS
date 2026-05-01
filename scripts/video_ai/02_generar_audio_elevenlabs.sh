#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "" ] || [ "${2:-}" = "" ]; then
  echo "Uso: $0 <guion_input.txt> <audio_output.mp3>"
  exit 1
fi

if [ -z "${ELEVENLABS_API_KEY:-}" ]; then
  echo "Error: falta ELEVENLABS_API_KEY"
  exit 1
fi

VOICE_ID="${ELEVENLABS_VOICE_ID:-dlGxemPxFMTY7iXagmOj}"
INPUT_FILE="$1"
OUTPUT_FILE="$2"

if [ ! -f "$INPUT_FILE" ]; then
  echo "Error: no existe $INPUT_FILE"
  exit 1
fi

mkdir -p "$(dirname "$OUTPUT_FILE")"

RAW_TEXT="$(cat "$INPUT_FILE")"

# Limpiar markdown para evitar que la voz lea símbolos como "##", "###", etc.
TEXT_CONTENT="$(
  printf '%s\n' "$RAW_TEXT" \
    | sed -E 's/^[[:space:]]*#{1,6}[[:space:]]*//g' \
    | sed -E 's/^[-*][[:space:]]+//g' \
    | sed -E 's/^[0-9]+[.)][[:space:]]+//g' \
    | sed -E 's/`([^`]*)`/\1/g' \
    | sed -E 's/\*\*([^*]+)\*\*/\1/g' \
    | sed -E 's/__([^_]+)__/\1/g' \
    | sed -E 's/#//g' \
    | sed -E 's/^---+$//g' \
    | sed -E '/^[[:space:]]*$/N;/^\n$/D'
)"

TMP_JSON="$(mktemp)"
trap 'rm -f "$TMP_JSON"' EXIT

jq -n \
  --arg text "$TEXT_CONTENT" \
  '{
    text: $text,
    model_id: "eleven_multilingual_v2",
    voice_settings: {
      stability: 0.4,
      similarity_boost: 0.75,
      style: 0.25,
      use_speaker_boost: true
    }
  }' > "$TMP_JSON"

curl -sS "https://api.elevenlabs.io/v1/text-to-speech/${VOICE_ID}" \
  -H "xi-api-key: ${ELEVENLABS_API_KEY}" \
  -H "Content-Type: application/json" \
  --data @"$TMP_JSON" \
  --output "$OUTPUT_FILE"

if [ ! -s "$OUTPUT_FILE" ]; then
  echo "Error: no se genero audio"
  exit 1
fi

echo "OK audio: $OUTPUT_FILE"
