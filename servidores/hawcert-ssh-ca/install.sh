#!/bin/bash
# =============================================================================
# Instalador (idempotente) del lado servidor de los certificados SSH temporales
# de HawCert. Ejecutar como root en cada servidor:
#
#   sudo bash install.sh <slug> <api_secret> [url_hawcert]
#     slug        = slug del servicio SSH en HawCert (externo | interno)
#     api_secret  = services.api_secret de ese servicio en HawCert
#     url_hawcert = https://hawcert.hawkins.es (defecto)
#
# Qué hace (todo aditivo, con copias .bak.<fecha> de lo que sobreescribe):
#   1. Usuario de sistema hawcertauth (sin shell) para ejecutar el comando de principals.
#   2. /etc/ssh/hawcert/ca.pub  <- clave pública de la CA (GET $URL/api/ssh/ca)
#      /etc/ssh/hawcert/config  <- URL/SLUG/SECRET (0640 root:hawcertauth)
#   3. /usr/local/sbin/hawcert-principals (0755)
#   4. /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf  (Match User hawuser2026)
#   5. sshd -t  y, solo si valida, systemctl reload ssh (las sesiones abiertas no se tocan).
# =============================================================================
set -euo pipefail
SLUG="${1:?slug}"; SECRET="${2:?api_secret}"; URL="${3:-https://hawcert.hawkins.es}"
HERE="$(cd "$(dirname "$0")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
[ "$(id -u)" -eq 0 ] || { echo "ejecutar como root" >&2; exit 1; }
id hawuser2026 >/dev/null 2>&1 || { echo "no existe el usuario hawuser2026 en este servidor" >&2; exit 1; }

bak() { [ -e "$1" ] && cp -a "$1" "$1.bak.$STAMP" && echo "backup: $1.bak.$STAMP" || true; }

# 1. usuario de sistema
if ! id hawcertauth >/dev/null 2>&1; then
  useradd --system --shell /usr/sbin/nologin --home /nonexistent --no-create-home hawcertauth
  echo "creado usuario hawcertauth"
fi

# 2. CA pública + config
install -d -m 755 /etc/ssh/hawcert
CA="$(curl -fsS --max-time 20 "$URL/api/ssh/ca")"
case "$CA" in ssh-ed25519\ *) ;; *) echo "respuesta inesperada de $URL/api/ssh/ca: $CA" >&2; exit 1 ;; esac
bak /etc/ssh/hawcert/ca.pub
printf '%s\n' "$CA" > /etc/ssh/hawcert/ca.pub; chmod 644 /etc/ssh/hawcert/ca.pub
bak /etc/ssh/hawcert/config
umask 027
printf 'URL=%s\nSLUG=%s\nSECRET=%s\n' "$URL" "$SLUG" "$SECRET" > /etc/ssh/hawcert/config
chown root:hawcertauth /etc/ssh/hawcert/config; chmod 640 /etc/ssh/hawcert/config
umask 022

# 3. comando de principals
bak /usr/local/sbin/hawcert-principals
install -m 755 -o root -g root "$HERE/hawcert-principals.sh" /usr/local/sbin/hawcert-principals

# 4. sshd config (aditivo, último fichero del include)
bak /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf
install -m 644 -o root -g root "$HERE/99-zz-hawcert-ca.conf" /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf

# 5. validar y recargar
if sshd -t; then
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
  echo "sshd recargado. Config efectiva para hawuser2026:"
  sshd -T -C user=hawuser2026,host=localhost,addr=127.0.0.1 | grep -i -E '^(trustedusercakeys|authorizedprincipalscommand|authorizedprincipalscommanduser) '
else
  echo "sshd -t ha FALLADO: retiro el fichero de configuración y NO recargo" >&2
  rm -f /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf
  exit 1
fi
echo "OK: $SLUG listo para certificados HawCert de un solo uso."
