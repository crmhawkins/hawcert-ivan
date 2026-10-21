#!/usr/bin/env bash
# =============================================================================
# hawcert-ssh — conexión SSH a los servidores Hawkins con un certificado SSH
# temporal de UN SOLO USO emitido por HawCert. Sin contraseñas ni tokens que
# copiar: la identidad es tu certificado .pem de HawCert (demuestras que tienes
# su clave privada firmando un reto) y el servidor acepta el certificado una
# sola vez. Si te desconectas, vuelves a ejecutar el script (emite otro).
#
#   Uso:   hawcert-ssh <servidor> [opciones de ssh] [comando]
#          servidor = externo (217.160.39.81) | interno (217.160.39.79, salta por externo)
#   Ej.:   hawcert-ssh externo 'sudo docker ps'
#          hawcert-ssh interno                      # shell interactiva
#          hawcert-ssh interno -L 8080:localhost:80 # opciones de ssh normales
#
#   Config (variables de entorno):
#     HAWCERT_PEM   ruta a tu .pem de HawCert (cert + clave).  Defecto: ~/.hawcert/cert.pem
#     HAWCERT_URL   URL de HawCert.                            Defecto: https://hawcert.hawkins.es
#     HAWCERT_JUMP  servidor de salto para "interno".          Defecto: externo
#
#   Requiere: ssh, ssh-keygen, openssl, curl (Git Bash en Windows, Linux o macOS).
#   Descarga: curl -fsSL https://hawcert.hawkins.es/tools/hawcert-ssh -o ~/.hawcert/hawcert-ssh && chmod +x ~/.hawcert/hawcert-ssh
# =============================================================================
set -euo pipefail

HAWCERT_URL="${HAWCERT_URL:-https://hawcert.hawkins.es}"
HAWCERT_PEM="${HAWCERT_PEM:-$HOME/.hawcert/cert.pem}"
HAWCERT_JUMP="${HAWCERT_JUMP:-externo}"

die() { echo "hawcert-ssh: $*" >&2; exit 1; }

[ $# -ge 1 ] || die "uso: hawcert-ssh <externo|interno> [opciones ssh] [comando]"
TARGET="$1"; shift
for bin in ssh ssh-keygen openssl curl; do command -v "$bin" >/dev/null 2>&1 || die "falta '$bin' en el PATH"; done
[ -r "$HAWCERT_PEM" ] || die "no encuentro tu certificado HawCert en $HAWCERT_PEM (exporta HAWCERT_PEM o cópialo ahí)"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
chmod 700 "$TMP"
# En Git Bash (Windows) curl es nativo y no entiende rutas /tmp/...: le pasamos la ruta Windows.
if command -v cygpath >/dev/null 2>&1; then TMP_NATIVE="$(cygpath -m "$TMP")"; else TMP_NATIVE="$TMP"; fi

# Separamos el .pem: solo el certificado público viaja a HawCert; la clave privada se queda aquí.
openssl x509 -in "$HAWCERT_PEM" -out "$TMP/cert.pem" 2>/dev/null || die "el fichero $HAWCERT_PEM no contiene un certificado X.509"
openssl pkey -in "$HAWCERT_PEM" -out "$TMP/key.pem" 2>/dev/null || die "el fichero $HAWCERT_PEM no contiene la clave privada (descarga el .pem completo desde HawCert)"
chmod 600 "$TMP/key.pem"

# Extrae un campo string de un JSON plano. Con python3 (si existe) es exacto; si no, sed + des-escape de \/ .
json_str() {
  if command -v python3 >/dev/null 2>&1; then
    printf '%s' "$1" | python3 -c 'import json,sys; d=json.load(sys.stdin); v=d.get(sys.argv[1],""); print(v if isinstance(v,str) else "")' "$2" 2>/dev/null && return 0
  fi
  printf '%s' "$1" | sed -n 's/.*"'"$2"'":"\([^"]*\)".*/\1/p' | head -1 | tr -d '\\'
}
json_num() { printf '%s' "$1" | sed -n 's/.*"'"$2"'":\([0-9]*\).*/\1/p' | head -1; }

# issue <slug> <ruta-clave>  → crea <ruta-clave>, <ruta-clave>.pub, <ruta-clave>-cert.pub
#                              y exporta ISSUED_USER / ISSUED_HOST / ISSUED_PORT
issue() {
  local slug="$1" key="$2"
  ssh-keygen -q -t ed25519 -N "" -C "hawcert-ephemeral" -f "$key"
  local pub; pub="$(cut -d' ' -f1,2 < "$key.pub")"

  local ch; ch="$(curl -sS --max-time 20 -X POST "$HAWCERT_URL/api/ssh/challenge" -H 'Accept: application/json')" \
    || die "no se pudo contactar con HawCert ($HAWCERT_URL)"
  local nonce; nonce="$(json_str "$ch" nonce)"
  [ -n "$nonce" ] || die "HawCert no devolvió nonce: $ch"

  local sig; sig="$(printf '%s\n%s\n%s' "$nonce" "$slug" "$pub" | openssl dgst -sha256 -sign "$TMP/key.pem" -binary | openssl base64 -A)"

  local resp; resp="$(curl -sS --max-time 30 -X POST "$HAWCERT_URL/api/ssh/issue" -H 'Accept: application/json' \
    --data-urlencode "certificate@$TMP_NATIVE/cert.pem" \
    --data-urlencode "server_slug=$slug" \
    --data-urlencode "public_key=$pub" \
    --data-urlencode "nonce=$nonce" \
    --data-urlencode "signature=$sig")" || die "error llamando a /api/ssh/issue"

  local cert; cert="$(json_str "$resp" ssh_certificate)"
  if [ -z "$cert" ]; then
    die "HawCert ha rechazado la emisión para '$slug': $(json_str "$resp" message)"
  fi
  printf '%s\n' "$cert" > "$key-cert.pub"
  ISSUED_USER="$(json_str "$resp" user)"
  ISSUED_HOST="$(json_str "$resp" host)"
  ISSUED_PORT="$(json_num "$resp" port)"; ISSUED_PORT="${ISSUED_PORT:-22}"
  echo "hawcert-ssh: certificado de un solo uso emitido para '$slug' (serie $(json_num "$resp" serial), válido $(json_num "$resp" valid_seconds)s)" >&2
}

SSH_COMMON=(-o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o PasswordAuthentication=no -o KbdInteractiveAuthentication=no)

case "$TARGET" in
  interno)
    issue "$HAWCERT_JUMP" "$TMP/jump"
    JUMP_USER="$ISSUED_USER"; JUMP_HOST="$ISSUED_HOST"; JUMP_PORT="$ISSUED_PORT"
    issue "interno" "$TMP/target"
    PROXY="ssh ${SSH_COMMON[*]} -i $TMP/jump -o CertificateFile=$TMP/jump-cert.pub -p $JUMP_PORT -W %h:%p $JUMP_USER@$JUMP_HOST"
    exec ssh "${SSH_COMMON[@]}" -i "$TMP/target" -o "CertificateFile=$TMP/target-cert.pub" -o "ProxyCommand=$PROXY" \
      -p "$ISSUED_PORT" "$ISSUED_USER@$ISSUED_HOST" "$@"
    ;;
  *)
    issue "$TARGET" "$TMP/target"
    exec ssh "${SSH_COMMON[@]}" -i "$TMP/target" -o "CertificateFile=$TMP/target-cert.pub" \
      -p "$ISSUED_PORT" "$ISSUED_USER@$ISSUED_HOST" "$@"
    ;;
esac
