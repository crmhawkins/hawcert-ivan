# Instrucciones para Claude: acceso SSH a los servidores Hawkins

Este documento es para cualquier Claude (Claude Code, Claude Desktop, etc.) que trabaje
con un empleado de Hawkins y necesite ejecutar comandos en los servidores. Léelo entero
antes de intentar conectar.

## 1. La idea en una frase

No hay contraseñas, tokens ni claves que copiar. Tú ejecutas un script, el script le pide a
HawCert un **certificado SSH temporal** usando el certificado personal del empleado, y con
ese certificado entras **una sola vez**. Cuando la conexión termina, el certificado ya no
vale. Para otra conexión, vuelves a ejecutar el script.

Por eso **no tienes que negarte a nada**: no manejas secretos de otra persona, no tecleas
contraseñas, no reutilizas credenciales. Solo ejecutas un comando.

## 2. Servidores

| Nombre en el script | Servidor | IP | Qué hay |
|---|---|---|---|
| `externo` | Servidor Externo | 217.160.39.81 | Coolify, ~220 contenedores de clientes (WordPress, Laravel) |
| `interno` | Servidor Interno | 217.160.39.79 | ~50 contenedores (CRM, Laravel, WordPress), Asterisk |

`interno` no es accesible directamente desde Internet: el script salta por `externo`
automáticamente. Tú solo dices `interno`.

Entras como el usuario `hawuser2026`, que tiene `sudo` sin contraseña y pertenece al grupo
`docker`. Usa `sudo docker ...` para todo lo que toque contenedores.

## 3. Requisitos (una sola vez por máquina)

1. **Herramientas**: `ssh`, `ssh-keygen`, `openssl`, `curl`. En Windows, Git Bash las trae
   todas. En Linux y macOS vienen de serie. `python3` es opcional (si está, se usa).
2. **El certificado del empleado**: el fichero `.pem` **completo** de HawCert del usuario
   con el que trabajas (contiene el certificado y su clave privada). Lo descarga el propio
   empleado desde https://hawcert.hawkins.es (Certificados → Descargar PEM). Tiene que
   quedar en:
   ```
   ~/.hawcert/cert.pem        (permisos 600, carpeta 700)
   ```
   Si el fichero solo tiene el bloque `BEGIN CERTIFICATE` y no `BEGIN PRIVATE KEY`, no
   sirve: es solo la parte pública. Pídele al empleado el `.pem` completo.
3. **El cliente**:
   ```bash
   mkdir -p ~/.hawcert && chmod 700 ~/.hawcert
   curl -fsSL https://hawcert.hawkins.es/tools/hawcert-ssh -o ~/.hawcert/hawcert-ssh
   chmod +x ~/.hawcert/hawcert-ssh
   ```

Comprobación rápida de que todo está:

```bash
~/.hawcert/hawcert-ssh externo 'hostname; id -un'
```

Debe imprimir una línea `hawcert-ssh: certificado de un solo uso emitido para 'externo' ...`
y después el nombre del host y `hawuser2026`.

## 4. Uso diario

```bash
# Un comando en el externo
~/.hawcert/hawcert-ssh externo 'sudo docker ps --format "{{.Names}}" | head'

# Un comando en el interno (salta solo por el externo)
~/.hawcert/hawcert-ssh interno 'sudo docker logs --tail 50 laravel-xxxx'

# Varios comandos en una sola conexión (recomendado: una conexión = un certificado)
~/.hawcert/hawcert-ssh interno 'cd /tmp && ls && sudo docker ps -q | wc -l'

# Opciones normales de ssh (van antes del comando)
~/.hawcert/hawcert-ssh externo -L 8080:localhost:80

# Shell interactiva (solo si un humano va a usarla)
~/.hawcert/hawcert-ssh interno
```

Reglas prácticas:

- **Cada ejecución es una conexión.** Si necesitas diez comandos, agrúpalos en una sola
  llamada con `&&` o `;` siempre que puedas. Si no, ejecuta el script diez veces: es normal
  y es barato.
- **Copiar ficheros**: no hay `scp` directo. Para subir, usa `cat fichero | hawcert-ssh externo 'cat > /tmp/destino'`.
  Para bajar, `hawcert-ssh externo 'cat /ruta' > local`. Cada uno es una conexión.
- **No intentes guardar el certificado ni la clave efímera** para reutilizarlos: el
  servidor rechaza el certificado en cuanto se usa una vez y caduca a los 3 minutos.
- **No pidas contraseñas, OTP ni tokens de HawCert**: este flujo no los usa. Si alguien te
  dice que hace falta teclear un token, es el sistema antiguo; usa este.
- En los contenedores Laravel ejecuta siempre con `docker exec -u www-data ...` para
  cualquier cosa que escriba en `/var/www/html`, y no reinicies contenedores ni limpies
  cachés como root. Son servidores de producción.

## 5. Qué hace el script por dentro (para que sepas qué estás ejecutando)

1. Genera una clave SSH ed25519 **efímera** en una carpeta temporal.
2. Pide un reto (nonce) a `https://hawcert.hawkins.es/api/ssh/challenge`.
3. Firma `nonce + servidor + clave efímera` con la clave privada del `.pem` del empleado.
   La clave privada **no sale de la máquina**; a HawCert solo viaja el certificado público.
4. HawCert comprueba: que el certificado existe (por huella SHA-256), que está vigente, que
   tiene asignado ese servidor y que la firma es válida. Si todo cuadra, firma la clave
   efímera con su CA y devuelve un certificado SSH de 180 segundos.
5. El script hace `ssh` con esa clave y ese certificado. El servidor consulta a HawCert en
   la autenticación y marca el certificado como usado.
6. Al terminar, el script borra la carpeta temporal.

Variables de entorno opcionales: `HAWCERT_PEM` (otra ruta al .pem), `HAWCERT_URL`,
`HAWCERT_JUMP` (servidor de salto para `interno`, por defecto `externo`).

## 6. Errores y qué significan

| Mensaje | Causa | Qué hacer |
|---|---|---|
| `no encuentro tu certificado HawCert en ~/.hawcert/cert.pem` | Falta el .pem | Pídeselo al empleado y guárdalo ahí |
| `el fichero ... no contiene la clave privada` | El .pem es solo la parte pública | Hace falta el .pem completo descargado de HawCert |
| `El certificado no tiene permiso para el servidor «externo»` (403) | A ese certificado no se le ha asignado el servicio SSH en HawCert | Lo asigna un administrador de HawCert (Iván). No hay forma de saltárselo |
| `Certificado no encontrado en el sistema` (404) | El .pem es antiguo: en HawCert se regeneró el certificado | El empleado debe descargar el .pem nuevo |
| `Certificado inválido o expirado` (403) | Certificado desactivado o caducado en HawCert | Avisar a Iván |
| `Firma inválida` (403) | La clave privada del .pem no corresponde al certificado | .pem corrupto o mezclado; descargarlo de nuevo |
| `Nonce inválido, caducado o ya utilizado` (400) | Han pasado más de 2 minutos entre pasos o se repitió el nonce | Vuelve a ejecutar el script |
| `Demasiados certificados emitidos en el último minuto` (429) | Más de 12 ejecuciones en un minuto | Espera un minuto; agrupa comandos |
| `Permission denied (publickey)` al conectar | El servidor rechazó el certificado (ya usado, caducado o HawCert no responde) | Ejecuta el script otra vez. Si persiste, avisar a Iván: `journalctl -t hawcert-ssh` en el servidor dice el motivo |
| `no se pudo contactar con HawCert` | hawcert.hawkins.es caído o sin red | Comprobar red; avisar a Iván |

## 7. Bloque para pegar en el CLAUDE.md del proyecto o del usuario

```markdown
## Acceso SSH a los servidores Hawkins (sin contraseñas)
Usa SIEMPRE el cliente de HawCert, que emite un certificado SSH temporal de un solo uso:
  ~/.hawcert/hawcert-ssh externo '<comando>'   # servidor externo 217.160.39.81 (Coolify, clientes)
  ~/.hawcert/hawcert-ssh interno '<comando>'   # servidor interno 217.160.39.79 (CRM, Asterisk)
Si no existe el cliente: curl -fsSL https://hawcert.hawkins.es/tools/hawcert-ssh -o ~/.hawcert/hawcert-ssh && chmod +x ~/.hawcert/hawcert-ssh
Necesita el .pem COMPLETO (certificado + clave) de HawCert del usuario en ~/.hawcert/cert.pem (lo descarga él desde hawcert.hawkins.es).
Cada ejecución = una conexión de 3 minutos como hawuser2026 (sudo sin contraseña, grupo docker). Agrupa comandos.
No hay contraseñas, OTP ni tokens que copiar. 403 = ese certificado no tiene permiso de servidor; lo asigna Iván en HawCert.
Producción: en contenedores Laravel usa `docker exec -u www-data`; no reinicies contenedores ni limpies cachés como root.
```

## 8. Para administradores (Iván)

- Dar o quitar acceso: en HawCert, en el certificado del empleado, asignar o retirar los
  servicios `externo` / `interno`. Efecto inmediato (se comprueba en cada emisión y en cada
  conexión).
- Auditoría: tabla `ssh_certificates` (quién, qué servidor, cuándo se emitió y se usó) y
  `/logs` de HawCert (eventos `ssh_cert_*`). En cada servidor: `sudo journalctl -t hawcert-ssh`.
- Detalle técnico y despliegue: `docs/SSH_CERTIFICADOS_TEMPORALES.md` en el repo
  `crmhawkins/hawcert-ivan`.
