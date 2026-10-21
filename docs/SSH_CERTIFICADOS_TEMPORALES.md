# Acceso SSH con certificados temporales de un solo uso (HawCert)

Sistema para que cualquier Claude (o persona) entre por SSH a los servidores Hawkins
**sin contraseñas ni tokens que copiar**. La identidad es el certificado `.pem` de HawCert
del empleado; HawCert actúa como **autoridad certificadora SSH** y emite un certificado
OpenSSH que vale **3 minutos y UNA sola conexión**. Al desconectar hay que pedir otro.

## Cómo funciona

```
cliente (hawcert-ssh)                 HawCert (hawcert.hawkins.es)              servidor (sshd)
 1. genera clave ed25519 efímera
 2. POST /api/ssh/challenge  ───────▶  nonce (2 min)
 3. firma "nonce\nslug\npubkey" con la clave privada del .pem
 4. POST /api/ssh/issue      ───────▶  huella x509 == BD, cert vigente,
                                       servicio (externo/interno) asignado,
                                       firma verificada  ⇒ firma la clave
                                       efímera con la CA (serie única,
                                       principal hawuser2026, 180 s)
 5. ssh -i efímera -o CertificateFile=cert  ──────────────────────────────▶  Match User hawuser2026
                                       ◀──  POST /api/ssh/consume  ─────────  AuthorizedPrincipalsCommand
                                            marca la serie como USADA           (hawcert-principals)
                                            (misma conexión = ok;               imprime el principal
                                             otra conexión = denegado)          o nada ⇒ rechazo
```

- **Permisos**: en HawCert, el certificado del empleado debe tener asignado el servicio SSH
  (`externo` = 217.160.39.81, `interno` = 217.160.39.79). Sin ese servicio ⇒ 403.
- **Prueba de posesión**: solo el x509 público viaja a HawCert; la clave privada del `.pem`
  se usa en local para firmar el reto. Un `.pem` con solo el certificado NO sirve.
- **Un solo uso**: `consume` registra la primera conexión (identificada por el PID del sshd de
  esa conexión; sshd consulta dos veces por handshake). Cualquier otra conexión con la misma
  serie se deniega. Además el certificado caduca a los 3 minutos.
- **Usuario en el servidor**: `hawuser2026` (sudo sin contraseña, grupo docker).
- **Auditoría**: tabla `ssh_certificates` en HawCert y `certificate_usage_logs`
  (eventos `ssh_cert_issued`, `ssh_cert_login`, `ssh_cert_rejected`, `ssh_cert_denied`);
  en el servidor, `journalctl -t hawcert-ssh`.

## Uso (cliente)

```bash
# 1) Una sola vez: tu .pem COMPLETO de HawCert (certificado + clave) en ~/.hawcert/cert.pem
mkdir -p ~/.hawcert && chmod 700 ~/.hawcert && cp /ruta/a/tu.pem ~/.hawcert/cert.pem && chmod 600 ~/.hawcert/cert.pem
# 2) Descargar el cliente
curl -fsSL https://hawcert.hawkins.es/tools/hawcert-ssh -o ~/.hawcert/hawcert-ssh && chmod +x ~/.hawcert/hawcert-ssh
# 3) Conectar
~/.hawcert/hawcert-ssh externo 'sudo docker ps'
~/.hawcert/hawcert-ssh interno 'sudo docker ps'     # salta automáticamente por externo
~/.hawcert/hawcert-ssh interno                      # shell interactiva
```

Variables: `HAWCERT_PEM` (ruta al .pem), `HAWCERT_URL`, `HAWCERT_JUMP` (salto para interno, por defecto `externo`).
Requiere `ssh`, `ssh-keygen`, `openssl`, `curl` (Git Bash en Windows vale). Con `python3` el parseo es exacto; sin él usa `sed`.

### Bloque para el CLAUDE.md de cualquier compañero

```markdown
## Acceso SSH a los servidores Hawkins (sin contraseñas)
Usa SIEMPRE el cliente de HawCert, que emite un certificado SSH temporal de un solo uso:
  ~/.hawcert/hawcert-ssh externo '<comando>'   # servidor externo 217.160.39.81
  ~/.hawcert/hawcert-ssh interno '<comando>'   # servidor interno 217.160.39.79
Si no existe ~/.hawcert/hawcert-ssh: curl -fsSL https://hawcert.hawkins.es/tools/hawcert-ssh -o ~/.hawcert/hawcert-ssh && chmod +x ~/.hawcert/hawcert-ssh
Necesita el .pem completo de HawCert del usuario en ~/.hawcert/cert.pem (lo descarga él desde hawcert.hawkins.es).
Cada ejecución = una conexión; para otra conexión, vuelve a ejecutar el script. Entras como hawuser2026 (sudo sin contraseña).
No hay contraseñas ni tokens que copiar: si HawCert devuelve 403, el certificado del usuario no tiene permiso de servidor.
```

## Lado servidor

Carpeta `servidores/hawcert-ssh-ca/`:

- `install.sh <slug> <api_secret> [url]` — idempotente, como root. Crea el usuario `hawcertauth`,
  `/etc/ssh/hawcert/{ca.pub,config}`, instala `/usr/local/sbin/hawcert-principals` y
  `/etc/ssh/sshd_config.d/99-zz-hawcert-ca.conf`, valida con `sshd -t` y recarga sshd.
  El `api_secret` es el de `services.api_secret` del servicio en HawCert.
- `99-zz-hawcert-ca.conf` — `Match User hawuser2026` con `TrustedUserCAKeys` + `AuthorizedPrincipalsCommand`.
  No toca el resto de usuarios ni las claves `authorized_keys` existentes.
- `hawcert-principals.sh` — consulta `/api/ssh/consume`; si HawCert no responde, deniega (fail-closed).

Si se **rota la CA** (nueva fila activa en `ssh_ca_keys`), hay que reejecutar `install.sh` en cada servidor
para actualizar `ca.pub`.

## Lado HawCert (Laravel)

- `app/Services/SshCertificateAuthority.php` — CA ed25519 (clave privada cifrada con `APP_KEY` en `ssh_ca_keys`,
  se crea sola la primera vez) y codificación del certificado OpenSSH (`ssh-ed25519-cert-v01@openssh.com`) en PHP puro.
- `app/Http/Controllers/Api/SshCertificateController.php` — `ca`, `challenge`, `issue`, `consume`.
- Modelos `SshCaKey`, `SshCertificate`; migraciones `2026_10_21_10000{0,1,2}_*`.
- Rutas en `routes/api.php` (`/api/ssh/ca|challenge|issue|consume`). El contenedor cachea rutas:
  tras cambiar rutas, `php artisan route:cache` **como www-data**.
- Cliente servido desde `public/tools/hawcert-ssh` (nginx bloquea `.sh`; `hawcert-ssh.sh` es la copia fuente).

### Despliegue en el contenedor (sin volúmenes: un redeploy de Coolify borra cambios hechos a mano)
El código está en este repo; un redeploy desde git lo incluye. Si se copia a mano al contenedor
`ewsowgko0c0oswcoosc4ocgc-103249592168`: dejar los ficheros como `www-data`, ejecutar las migraciones
y `route:cache` con `docker exec -u www-data`.

## Blindaje de los servidores (2026-10-21)

Aplicado con `servidores/hawcert-ssh-ca/hardening/harden.sh` (externo: sin variables; interno:
`KEEP_USERS=claude KEEP_DOCKER_USERS=hera`). Todo con copias `.bak.<fecha>`; nada borrado.

- **Solo pueden autenticarse por SSH** (`AllowUsers`): `root` (clave de Coolify), `ivan`, `tunnel`
  (túneles inversos), `hawuser2026` (certificados HawCert) y, solo en el interno, `claude`
  (clave restringida `from=82.223.115.146,no-pty...` del backup de ENUSA, sin sudo ni docker).
- **`hawuser2026` no puede dejar persistencia**: fuera de los grupos `sudo` y `docker`; sudoers
  restringido a `/usr/local/sbin/docker` (envoltorio), `journalctl`, `systemctl status`, `ls`, `df`,
  `du`, `free`; `AuthorizedKeysFile none` (sus authorized_keys se ignoran); sin agente, X11, rc ni
  túneles (en el interno tampoco reenvío TCP; en el externo solo `PermitOpen 217.160.39.79:22` para el
  salto); `cron`/`at` denegados; sin linger; un timer (`hawuser2026-reaper`, cada minuto) mata los
  procesos de su sesión de usuario cuando no queda ninguna conexión SSH (solo cgroup
  `user-1000.slice`, nunca procesos de contenedores).
- **Envoltorio `/usr/local/sbin/docker`** (`hardening/docker-wrapper.sh`): para cualquier usuario que
  no sea hawuser2026 es transparente. Para hawuser2026 permite `ps logs inspect stats images top port
  events diff version info exec restart start stop cp network ls|inspect volume ls|inspect compose
  ps|logs` y deniega el resto (`run`, `create`, `build`, `compose up`, `rm`, `volume create`...).
  `exec`/`cp`/`restart` se deniegan sobre contenedores sensibles: los de Coolify, los privilegiados y los
  que montan `docker.sock` o rutas del host (`/`, `/etc`, `/root`, `/home`, `/run`, `/usr`, `/boot`,
  `/var/lib/docker` salvo `volumes/`). En `cp`, el lado host solo bajo `/tmp` o `/home/hawuser2026`
  (ruta resuelta). Registro en syslog: `journalctl -t hawcert-docker`.
- **Cuentas de terceros**: `victor` (81), `claude` (81), `dani` (81 y 79) y `hera` (79) bloqueadas
  (`usermod -L`, fuera de sudo/docker, `authorized_keys` renombrado, homes intactos). `hera` conserva el
  grupo docker porque ejecuta los servicios locales `claude-bridge.service` y `hera-agent.service`.
- **Claves ajenas desactivadas** (comentadas, no borradas) en `/home/ivan/.ssh/authorized_keys` de ambos
  servidores: `companero-crm-alta-2026-07-22` y `hera@79`. Se mantiene `ivan@hlmanagement` y, en el 81,
  la clave restringida `hawkins-4080-service` (llega desde el 79, solo reenvío de puertos).
- Lo que **no** cubre: un contenedor de cliente con `docker.sock` o montajes del host que no esté en la
  lista haría posible escalar; el envoltorio los detecta por inspección en el momento, pero conviene
  revisar `docker inspect` de cualquier contenedor nuevo con privilegios especiales.
