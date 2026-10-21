#!/bin/bash
# =============================================================================
# /usr/local/sbin/hawcert-principals  —  AuthorizedPrincipalsCommand de sshd
#
# sshd lo invoca (como el usuario hawcertauth) en cada intento de autenticación
# con certificado del usuario hawuser2026, pasando: %u %s %i %t %C
#   $1 usuario  $2 serie del certificado  $3 key id  $4 tipo de clave  $5 id de conexión
# Pregunta a HawCert si esa serie está autorizada y la marca como USADA
# (un solo uso). Si HawCert dice que sí, imprime el principal permitido;
# si no imprime nada, sshd rechaza el certificado.
# Config: /etc/ssh/hawcert/config  (URL=..., SLUG=..., SECRET=...)
# =============================================================================
USER_="$1"; SERIAL="$2"; KEYID="$3"; TYPE="$4"; CONN="${5:-}"
CONF=/etc/ssh/hawcert/config

[ -r "$CONF" ] || exit 0
# shellcheck disable=SC1090
. "$CONF"
[ -n "${URL:-}" ] && [ -n "${SLUG:-}" ] && [ -n "${SECRET:-}" ] || exit 0

case "$TYPE" in *-cert-v01@openssh.com) ;; *) exit 0 ;; esac
[[ "$SERIAL" =~ ^[0-9]+$ ]] || exit 0
# Identificador de conexión: %C de sshd; si llega vacío, el PID del proceso sshd de esta conexión (es el mismo
# en las dos consultas de un mismo handshake y distinto en cada conexión nueva).
[[ "$CONN" =~ ^[A-Za-z0-9]{1,128}$ ]] || CONN="pid$PPID"

RESP=$(curl -sS --max-time 6 -X POST "$URL/api/ssh/consume" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"server_slug\":\"$SLUG\",\"api_secret\":\"$SECRET\",\"serial\":$SERIAL,\"username\":\"$USER_\",\"connection_id\":\"$CONN\"}" 2>/dev/null) \
  || { logger -t hawcert-ssh "ERROR consultando HawCert (serial=$SERIAL)"; exit 0; }

if printf '%s' "$RESP" | grep -q '"allowed":true'; then
  PRINCIPAL=$(printf '%s' "$RESP" | sed -n 's/.*"principal":"\([^"]*\)".*/\1/p')
  logger -t hawcert-ssh "ACEPTADO user=$USER_ serial=$SERIAL conn=$CONN keyid=$KEYID"
  echo "${PRINCIPAL:-$USER_}"
else
  logger -t hawcert-ssh "DENEGADO user=$USER_ serial=$SERIAL conn=$CONN keyid=$KEYID resp=$(printf '%s' "$RESP" | tr -d '\n' | cut -c1-200)"
fi
exit 0
