#!/usr/bin/env bash
set -euo pipefail

NGINX_SITE="/etc/nginx/sites-available/sistemax.pro"
ENV_FILE="/etc/environment"
ENDPOINT_DEFAULT="https://sistemax.pro/public/api/whatsapp_twilio_endpoint.php"
FROM_DEFAULT="+14155238886"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Este script debe ejecutarse como root (sudo)." >&2
  exit 1
fi

ask() {
  local var_name="$1"
  local prompt="$2"
  local default="${3:-}"
  local value
  if [[ -n "${default}" ]]; then
    read -r -p "${prompt} [${default}]: " value
    value="${value:-$default}"
  else
    read -r -p "${prompt}: " value
  fi
  printf -v "${var_name}" '%s' "${value}"
}

echo "=== Rotacion de secretos WhatsApp/Twilio ==="
ask ENDPOINT "SISTEMAX_WHATSAPP_ENDPOINT" "${ENDPOINT_DEFAULT}"
ask BEARER "SISTEMAX_WHATSAPP_AUTH_BEARER (clave interna larga)"
ask SID "TWILIO_ACCOUNT_SID (AC...)" ""
ask TOKEN "TWILIO_AUTH_TOKEN" ""
ask FROM "TWILIO_WHATSAPP_FROM" "${FROM_DEFAULT}"

if [[ -z "${BEARER}" || -z "${SID}" || -z "${TOKEN}" ]]; then
  echo "Faltan valores obligatorios (BEARER, SID o TOKEN)." >&2
  exit 1
fi

tmp_nginx="$(mktemp)"
cp "${NGINX_SITE}" "${tmp_nginx}"

replace_or_add_fastcgi_param() {
  local key="$1"
  local value="$2"
  if grep -q "fastcgi_param ${key} " "${tmp_nginx}"; then
    sed -i "s|^[[:space:]]*fastcgi_param ${key} .*|        fastcgi_param ${key} \"${value}\";|g" "${tmp_nginx}"
  else
    # Insertar antes de include fastcgi_params dentro del bloque php.
    sed -i "/fastcgi_param SCRIPT_FILENAME/a\\        fastcgi_param ${key} \"${value}\";" "${tmp_nginx}"
  fi
}

replace_or_add_fastcgi_param "SISTEMAX_WHATSAPP_ENDPOINT" "${ENDPOINT}"
replace_or_add_fastcgi_param "SISTEMAX_WHATSAPP_AUTH_BEARER" "${BEARER}"
replace_or_add_fastcgi_param "TWILIO_ACCOUNT_SID" "${SID}"
replace_or_add_fastcgi_param "TWILIO_AUTH_TOKEN" "${TOKEN}"
replace_or_add_fastcgi_param "TWILIO_WHATSAPP_FROM" "${FROM}"

cp "${tmp_nginx}" "${NGINX_SITE}"
rm -f "${tmp_nginx}"

tmp_env="$(mktemp)"
cp "${ENV_FILE}" "${tmp_env}"

upsert_env() {
  local key="$1"
  local value="$2"
  if grep -q "^${key}=" "${tmp_env}"; then
    sed -i "s|^${key}=.*|${key}=\"${value}\"|g" "${tmp_env}"
  else
    printf '%s="%s"\n' "${key}" "${value}" >> "${tmp_env}"
  fi
}

upsert_env "SISTEMAX_WHATSAPP_ENDPOINT" "${ENDPOINT}"
upsert_env "SISTEMAX_WHATSAPP_AUTH_BEARER" "${BEARER}"
upsert_env "TWILIO_ACCOUNT_SID" "${SID}"
upsert_env "TWILIO_AUTH_TOKEN" "${TOKEN}"
upsert_env "TWILIO_WHATSAPP_FROM" "${FROM}"

cp "${tmp_env}" "${ENV_FILE}"
rm -f "${tmp_env}"

echo "Validando Nginx..."
nginx -t
systemctl reload nginx
echo "Nginx recargado."

echo "Probando endpoint interno..."
set +e
test_resp="$(curl -sS -X POST "${ENDPOINT}" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${BEARER}" \
  --data "{\"to\":\"${FROM}\",\"message\":\"Test tecnico Sistemax $(date '+%Y-%m-%d %H:%M:%S')\"}")"
test_code=$?
set -e

echo "Respuesta endpoint:"
echo "${test_resp}"
if [[ ${test_code} -ne 0 ]]; then
  echo "Fallo curl de prueba. Revisar conectividad/credenciales." >&2
  exit 1
fi

echo "Listo. Secretos rotados y configuracion aplicada."
