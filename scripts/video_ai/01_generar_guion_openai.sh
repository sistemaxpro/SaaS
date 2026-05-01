#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "" ] || [ "${2:-}" = "" ]; then
  echo "Uso: $0 <guia_input.md> <guion_output.txt>"
  exit 1
fi

if [ -z "${OPENAI_API_KEY:-}" ]; then
  echo "Error: falta OPENAI_API_KEY"
  exit 1
fi

INPUT_FILE="$1"
OUTPUT_FILE="$2"
MODEL="${OPENAI_MODEL:-gpt-4.1}"

if [ ! -f "$INPUT_FILE" ]; then
  echo "Error: no existe $INPUT_FILE"
  exit 1
fi

mkdir -p "$(dirname "$OUTPUT_FILE")"

PROMPT_SYS="Sos un guionista tecnico para tutoriales de software en espanol.
Escribi un guion de narracion claro para usuario principiante.
Reglas:
- tono didactico y directo
- sin relleno
- pasos numerados
- mencionar errores comunes y como evitarlos
- cerrar con resumen practico
- salida en texto plano"

PROMPT_USER="$(cat "$INPUT_FILE")"

TMP_JSON="$(mktemp)"
trap 'rm -f "$TMP_JSON"' EXIT

jq -n \
  --arg model "$MODEL" \
  --arg sys "$PROMPT_SYS" \
  --arg usr "$PROMPT_USER" \
  '{
    model: $model,
    temperature: 0.4,
    messages: [
      {role: "system", content: $sys},
      {role: "user", content: $usr}
    ]
  }' > "$TMP_JSON"

RESP="$(curl -sS https://api.openai.com/v1/chat/completions \
  -H "Authorization: Bearer ${OPENAI_API_KEY}" \
  -H "Content-Type: application/json" \
  --data @"$TMP_JSON")"

if echo "$RESP" | jq -e '.error' >/dev/null 2>&1; then
  echo "Error OpenAI:"
  echo "$RESP" | jq -r '.error.message // .error'
  exit 1
fi

CONTENT="$(echo "$RESP" | jq -r '.choices[0].message.content // empty')"

if [ -z "$CONTENT" ]; then
  echo "Error: OpenAI no devolvio contenido"
  echo "$RESP" | jq .
  exit 1
fi

printf "%s\n" "$CONTENT" > "$OUTPUT_FILE"
echo "OK guion: $OUTPUT_FILE"
