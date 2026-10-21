#!/bin/bash
# =============================================================================
# Blindaje del acceso SSH: solo entran ivan (clave), root (clave de Coolify),
# tunnel (túneles inversos) y hawuser2026 (certificados HawCert de un solo uso).
# hawuser2026 queda sin poder dejar persistencia. Ejecutar como root:
#
#   sudo bash harden.sh <externo|interno>
#   (interno real: sudo KEEP_USERS=claude KEEP_DOCKER_USERS=hera bash harden.sh interno)
#
# Qué hace (todo con copia .bak.<fecha> y validado antes de aplicar):
#   1. AllowUsers root ivan tunnel hawuser2026   (sshd, fichero 10-hawcert-allowusers.conf)
#   2. Match User hawuser2026: AuthorizedKeysFile none, sin agente, sin X11, sin rc,
#      (interno: sin reenvío TCP). Mantiene TrustedUserCAKeys + AuthorizedPrincipalsCommand.
#   3. hawuser2026 fuera de los grupos sudo y docker; sudoers restringido; docker por envoltorio.
#   4. cron/at denegados a hawuser2026; sin linger; reaper cada minuto.
#   5. Claves y usuarios de terceros: claves ajenas fuera de ivan y hawuser2026 (copia),
#      usuarios victor/claude/dani/hera bloqueados (usermod -L, fuera de sudo/docker,
#      authorized_keys renombrado). No se borra ningún home ni fichero.
# =============================================================================
set -euo pipefail
SLUG="${1:?externo|interno}"
# Usuarios de sistema que NO se bloquean ni se quitan de AllowUsers (p. ej. claude en el interno: clave
# restringida del backup de ENUSA; aun así se les quita sudo y docker).
KEEP_USERS="${KEEP_USERS:-}"
# Cuentas de servicio locales (sin login SSH) que necesitan seguir en el grupo docker
# (p. ej. hera en el interno: claude-bridge.service / hera-agent.service).
KEEP_DOCKER_USERS="${KEEP_DOCKER_USERS:-}"
HERE="$(cd "$(dirname "$0")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
[ "$(id -u)" -eq 0 ] || { echo "ejecutar como root" >&2; exit 1; }
bak() { [ -e "$1" ] && cp -a "$1" "$1.bak.$STAMP" && echo "backup: $1.bak.$STAMP" || true; }

echo "### 1-2. sshd"
bak /etc/ssh/sshd_config.d/10-hawcert-allowusers.conf
cat > /etc/ssh/sshd_config.d/10-hawcert-allowusers.conf <<EOF
# HawCert: solo estos usuarios pueden autenticarse por SSH.
AllowUsers root ivan tunnel hawuser2026 $KEEP_USERS
EOF
bak /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf
{
  echo "# HawCert: certificados SSH temporales de un solo uso para hawuser2026 (último fichero: Match hasta el final)."
  echo "Match User hawuser2026"
  echo "    TrustedUserCAKeys /etc/ssh/hawcert/ca.pub"
  echo "    AuthorizedPrincipalsCommand /usr/local/sbin/hawcert-principals %u %s %i %t %C"
  echo "    AuthorizedPrincipalsCommandUser hawcertauth"
  echo "    AuthorizedKeysFile none"
  echo "    AllowAgentForwarding no"
  echo "    X11Forwarding no"
  echo "    PermitUserRC no"
  echo "    PermitTunnel no"
  if [ "$SLUG" = "interno" ]; then echo "    AllowTcpForwarding no"; else echo "    AllowTcpForwarding yes"; echo "    PermitOpen 217.160.39.79:22"; fi
} > /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf
if ! sshd -t; then
  echo "sshd -t FALLA: restauro y salgo" >&2
  [ -e /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf.bak.$STAMP ] && cp -a /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf.bak.$STAMP /etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf
  rm -f /etc/ssh/sshd_config.d/10-hawcert-allowusers.conf
  exit 1
fi

echo "### 3. hawuser2026: grupos, sudoers, envoltorio docker"
gpasswd -d hawuser2026 sudo 2>/dev/null || true
gpasswd -d hawuser2026 docker 2>/dev/null || true
install -m 755 -o root -g root "$HERE/docker-wrapper.sh" /usr/local/sbin/docker
bak /etc/sudoers.d/hawuser2026-nopasswd
install -m 440 -o root -g root "$HERE/sudoers-hawuser2026" /etc/sudoers.d/hawuser2026-nopasswd
visudo -c -f /etc/sudoers.d/hawuser2026-nopasswd
# que ivan no se vea afectado: su sudo sigue siendo ALL; el envoltorio deja pasar a cualquiera que no sea hawuser2026
sudo -u ivan sudo -n /usr/local/sbin/docker version --format '{{.Server.Version}}' >/dev/null && echo "ivan: sudo docker OK via envoltorio"

echo "### 4. cron/at, linger, reaper"
for f in /etc/cron.deny /etc/at.deny; do touch "$f"; grep -qx hawuser2026 "$f" || echo hawuser2026 >> "$f"; done
loginctl disable-linger hawuser2026 2>/dev/null || true
install -m 755 -o root -g root "$HERE/hawuser2026-reaper.sh" /usr/local/sbin/hawuser2026-reaper
cat > /etc/systemd/system/hawuser2026-reaper.service <<'EOF'
[Unit]
Description=HawCert: mata procesos de hawuser2026 sin sesión SSH
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/hawuser2026-reaper
EOF
cat > /etc/systemd/system/hawuser2026-reaper.timer <<'EOF'
[Unit]
Description=HawCert: reaper de hawuser2026 cada minuto
[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
AccuracySec=10s
[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now hawuser2026-reaper.timer >/dev/null
# authorized_keys de hawuser2026: ya no se usa (AuthorizedKeysFile none); guardar copia y vaciar
if [ -s /home/hawuser2026/.ssh/authorized_keys ]; then
  bak /home/hawuser2026/.ssh/authorized_keys; : > /home/hawuser2026/.ssh/authorized_keys
fi

echo "### 5. claves y usuarios de terceros"
# claves ajenas en ivan: nos quedamos SOLO con ivan@hlmanagement y las claves ivan_servers (comentarios que empiecen por ivan)
AK=/home/ivan/.ssh/authorized_keys
if [ -f "$AK" ]; then
  bak "$AK"
  awk -v st="$STAMP" -v keep="${KEEP_FPR:-}" '
    $0 ~ /^(ssh-|ecdsa-|sk-)/ {
      c=$3; line=$0
      cmd="echo \"" $1 " " $2 "\" | ssh-keygen -lf - 2>/dev/null"; cmd | getline fp; close(cmd); split(fp, f, " ")
      if (c ~ /^ivan/ || (keep != "" && index(keep, f[2]) > 0)) print line; else print "# DESACTIVADA " st " (HawCert hardening): " line
      next
    }
    { print }' "$AK.bak.$STAMP" > "$AK"
  chown ivan:ivan "$AK"; chmod 600 "$AK"
  echo "claves activas en ivan:"; grep -E "^(ssh-|ecdsa-|sk-)" "$AK" | awk '{print "   ", $3}'
fi
for u in victor claude dani hera; do
  id "$u" >/dev/null 2>&1 || continue
  case " $KEEP_USERS " in *" $u "*)
    for g in sudo docker; do gpasswd -d "$u" "$g" 2>/dev/null || true; done
    [ -f "/etc/sudoers.d/$u" ] && mv "/etc/sudoers.d/$u" "/root/sudoers.d.$u.disabled.$STAMP"
    echo "usuario $u se mantiene (KEEP_USERS) sin sudo ni docker"; continue ;; esac
  usermod -L "$u"
  for g in sudo docker; do gpasswd -d "$u" "$g" 2>/dev/null || true; done
  h=$(getent passwd "$u" | cut -d: -f6)
  if [ -f "$h/.ssh/authorized_keys" ]; then mv "$h/.ssh/authorized_keys" "$h/.ssh/authorized_keys.disabled.$STAMP"; fi
  [ -f "/etc/sudoers.d/$u" ] && mv "/etc/sudoers.d/$u" "/root/sudoers.d.$u.disabled.$STAMP"
  case " $KEEP_DOCKER_USERS " in *" $u "*) gpasswd -a "$u" docker >/dev/null; echo "usuario $u bloqueado para SSH pero se mantiene en docker (KEEP_DOCKER_USERS)"; continue ;; esac
  echo "usuario $u bloqueado (home intacto)"
done
visudo -c >/dev/null && echo "sudoers global OK"

echo "### aplicar sshd"
systemctl reload ssh 2>/dev/null || systemctl reload sshd
sshd -T -C user=hawuser2026,host=localhost,addr=127.0.0.1 | grep -iE '^(allowusers|authorizedkeysfile|allowtcpforwarding|allowagentforwarding|permituserrc|trustedusercakeys) '
echo "OK: $SLUG blindado."
