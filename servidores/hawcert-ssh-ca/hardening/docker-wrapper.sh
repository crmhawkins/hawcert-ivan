#!/bin/bash
# =============================================================================
# /usr/local/sbin/docker  —  envoltorio de docker para el usuario hawuser2026
#
# Se instala con prioridad sobre /usr/bin/docker en el secure_path de sudo, así
# `sudo docker ...` sigue funcionando para todos, pero:
#   - si el que invoca NO es hawuser2026 (p. ej. ivan), se ejecuta docker tal cual;
#   - si es hawuser2026 (acceso por certificado HawCert), se aplica una política
#     que permite operar contenedores de clientes pero NO obtener root en el host
#     ni dejar persistencia:
#       * subcomandos permitidos: ps logs inspect stats images top port events diff
#         version info exec restart start stop cp network(ls|inspect)
#         volume(ls|inspect) compose(ps|logs)
#       * prohibido todo lo demás (run, create, build, compose up, rm, volume
#         create, system, commit, save/load, context, plugin, swarm...)
#       * exec/cp prohibidos sobre contenedores "sensibles": los de Coolify
#         (coolify*), los privilegiados y los que montan el socket de docker o
#         rutas del host como /, /etc, /root, /home, /var/run.
#       * cp: el lado host solo puede estar bajo /tmp o /home/hawuser2026
#         (rutas resueltas con realpath; nada de /tmp/../etc).
#       * exec: no se admite --privileged ni -u root hacia contenedores sensibles.
# Todo se registra en syslog (tag hawcert-docker).
# =============================================================================
REAL=/usr/bin/docker
if [ "${SUDO_USER:-}" != "hawuser2026" ]; then
  exec "$REAL" "$@"
fi

log() { logger -t hawcert-docker "user=$SUDO_USER $*"; }
deny() { log "DENEGADO: $* :: args=$(printf '%q ' "$@")"; echo "hawcert-docker: operación no permitida para hawuser2026: $*" >&2; exit 126; }

[ $# -ge 1 ] || deny "sin subcomando"
SUB="$1"; shift

# ¿El contenedor es sensible? (Coolify, privilegiado, docker.sock o rutas críticas del host)
sensitive_container() {
  local c="$1"
  case "$c" in coolify*|*coolify*) return 0 ;; esac
  local info
  info=$("$REAL" inspect --format '{{.HostConfig.Privileged}} {{range .Mounts}}{{.Source}} {{end}}' "$c" 2>/dev/null) || return 1
  case "$info" in true\ *) return 0 ;; esac
  local m
  for m in $info; do
    case "$m" in /var/lib/docker/volumes/*) continue ;; esac   # volúmenes con nombre: normales
    case "$m" in
      /var/run/docker.sock|/run/docker.sock|/|/etc|/etc/*|/root|/root/*|/home|/home/*|/var/run|/run|/usr|/usr/*|/boot|/boot/*|/var/lib/docker|/var/lib/docker/*|/proc|/sys|/dev) return 0 ;;
    esac
  done
  return 1
}

host_path_ok() {
  local p; p=$(realpath -m -- "$1") || return 1
  case "$p" in /tmp/*|/home/hawuser2026/*) return 0 ;; esac
  return 1
}

case "$SUB" in
  ps|logs|inspect|stats|images|top|port|events|diff|version|info)
    ;;
  network|volume)
    case "${1:-}" in ls|inspect) ;; *) deny "$SUB ${1:-}" ;; esac
    ;;
  compose)
    # compose <opciones> (ps|logs) — buscamos el verbo entre los argumentos
    ok=0; for a in "$@"; do case "$a" in ps|logs) ok=1 ;; up|down|run|exec|create|build|pull|push|rm|restart|start|stop|kill|cp|config) deny "compose $a" ;; esac; done
    [ $ok -eq 1 ] || deny "compose (solo ps/logs)"
    ;;
  restart|start|stop)
    for a in "$@"; do case "$a" in -*) ;; *) sensitive_container "$a" && deny "$SUB sobre contenedor sensible $a" ;; esac; done
    ;;
  exec)
    # localizar el contenedor: primer argumento que no es opción ni valor de opción
    cont=""; skip=0
    for a in "$@"; do
      if [ $skip -eq 1 ]; then skip=0; continue; fi
      case "$a" in
        --privileged*) deny "exec --privileged" ;;
        -u|--user|-w|--workdir|-e|--env|--env-file|--detach-keys) skip=1 ;;
        -*) ;;
        *) cont="$a"; break ;;
      esac
    done
    [ -n "$cont" ] || deny "exec sin contenedor"
    sensitive_container "$cont" && deny "exec en contenedor sensible $cont"
    ;;
  cp)
    # docker cp [opts] SRC DST  (uno de los dos lleva "contenedor:ruta")
    args=(); for a in "$@"; do case "$a" in -*) ;; *) args+=("$a") ;; esac; done
    [ ${#args[@]} -eq 2 ] || deny "cp con argumentos inesperados"
    for a in "${args[@]}"; do
      case "$a" in
        *:*) c="${a%%:*}"; sensitive_container "$c" && deny "cp con contenedor sensible $c" ;;
        -)   deny "cp con stdin/stdout" ;;
        *)   host_path_ok "$a" || deny "cp: la ruta del host debe estar bajo /tmp o /home/hawuser2026 ($a)" ;;
      esac
    done
    ;;
  *)
    deny "subcomando $SUB"
    ;;
esac

log "OK: $SUB $(printf '%q ' "$@" | cut -c1-300)"
exec "$REAL" "$SUB" "$@"
