#!/bin/bash
# /usr/local/sbin/hawuser2026-reaper — lo lanza un timer de systemd cada minuto.
# Si hawuser2026 no tiene ninguna sesión SSH abierta, mata los procesos que haya
# dejado en su sesión de usuario (nohup, screen, tmux, reverse shells...).
#
# IMPORTANTE: solo toca procesos cuyo cgroup cuelga de /user.slice/user-<uid>.slice/
# (sesiones de login). Los procesos de contenedores docker que corren con el mismo
# UID numérico viven en /system.slice/docker-*.scope y NO se tocan.
U=hawuser2026
UID_=$(id -u "$U" 2>/dev/null) || exit 0
# sesiones vivas = procesos sshd de este usuario ("sshd: hawuser2026 [priv]" / "sshd: hawuser2026@pts/0")
if pgrep -f "^sshd: $U" >/dev/null 2>&1; then exit 0; fi
n=0
for p in $(pgrep -u "$U" 2>/dev/null); do
  cg=$(head -1 "/proc/$p/cgroup" 2>/dev/null) || continue
  case "$cg" in
    *"/user.slice/user-$UID_.slice/"*) kill -KILL "$p" 2>/dev/null && n=$((n+1)) ;;
  esac
done
[ "$n" -gt 0 ] && logger -t hawcert-reaper "sin sesión SSH de $U: matados $n procesos de su sesión de usuario"
exit 0
