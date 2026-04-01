#!/usr/bin/env python3
"""
HawCert SSH OTP — Configuración automática completa
====================================================
1. Registra el servidor en HawCert (genera slug y api_secret automáticamente)
2. Configura PAM en el servidor SSH para validar OTPs contra la API
Todo sin tocar el panel web manualmente.

Requisitos:
    pip install paramiko requests

Uso:
    python setup_ssh_otp.py
"""

import sys
import time
import getpass
import textwrap

try:
    import paramiko
except ImportError:
    print("\n[ERROR] Falta 'paramiko'. Instala con:  pip install paramiko requests\n")
    sys.exit(1)

try:
    import requests
except ImportError:
    print("\n[ERROR] Falta 'requests'. Instala con:  pip install paramiko requests\n")
    sys.exit(1)


# ─── Configuración por defecto ────────────────────────────────────────────────

DEFAULT_SSH_HOST  = "217.160.39.79"
DEFAULT_SSH_PORT  = 22
DEFAULT_SSH_USER  = "root"
DEFAULT_SSH_LINUX_USER = "root"          # usuario Linux compartido en el servidor
HAWCERT_BASE_URL  = "https://hawcert.hawkins.es"

PAM_SCRIPT_PATH   = "/etc/pam_hawcert.sh"
PAM_SSHD_PATH     = "/etc/pam.d/sshd"
SSHD_CONFIG_PATH  = "/etc/ssh/sshd_config"

# ─── Colores ──────────────────────────────────────────────────────────────────

G = "\033[92m"; Y = "\033[93m"; R = "\033[91m"; B = "\033[94m"
BOLD = "\033[1m"; RESET = "\033[0m"

def ok(m):   print(f"  {G}✓{RESET} {m}")
def warn(m): print(f"  {Y}⚠{RESET}  {m}")
def err(m):  print(f"  {R}✗{RESET} {m}")
def info(m): print(f"  {B}→{RESET} {m}")
def step(n, total, m): print(f"\n{BOLD}[{n}/{total}] {m}{RESET}")


# ─── Paso 1: Registrar servidor en HawCert ────────────────────────────────────

def register_in_hawcert(server_name: str, ssh_host: str, ssh_port: int,
                         ssh_linux_user: str, admin_key: str) -> dict:
    """
    Llama a POST /api/admin/register-ssh-server y devuelve { slug, api_secret }.
    """
    url = f"{HAWCERT_BASE_URL}/api/admin/register-ssh-server"
    headers = {
        "Content-Type": "application/json",
        "X-Admin-Key": admin_key,
    }
    payload = {
        "name":     server_name,
        "ssh_host": ssh_host,
        "ssh_port": ssh_port,
        "ssh_user": ssh_linux_user,
    }

    try:
        resp = requests.post(url, json=payload, headers=headers, timeout=15)
    except requests.exceptions.ConnectionError:
        raise RuntimeError(f"No se pudo conectar a {HAWCERT_BASE_URL} — ¿está desplegado?")
    except requests.exceptions.Timeout:
        raise RuntimeError("Tiempo de espera agotado al conectar con HawCert")

    if resp.status_code == 401:
        raise RuntimeError("HAWCERT_ADMIN_API_KEY incorrecta o no configurada en el servidor")
    if resp.status_code == 422:
        errors = resp.json().get('errors', resp.text)
        raise RuntimeError(f"Datos inválidos: {errors}")
    if not resp.ok:
        raise RuntimeError(f"Error HTTP {resp.status_code}: {resp.text}")

    data = resp.json()
    if not data.get('success'):
        raise RuntimeError(data.get('message', 'Respuesta inesperada de HawCert'))

    return data  # { service_id, slug, api_secret, message }


# ─── Helpers SSH ──────────────────────────────────────────────────────────────

def run(ssh: paramiko.SSHClient, cmd: str, check=True):
    _, stdout, stderr = ssh.exec_command(cmd)
    exit_code = stdout.channel.recv_exit_status()
    out     = stdout.read().decode().strip()
    err_out = stderr.read().decode().strip()
    if check and exit_code != 0:
        raise RuntimeError(f"Falló (exit {exit_code}):\n  CMD: {cmd}\n  ERR: {err_out}")
    return out, err_out, exit_code


def upload_text(ssh: paramiko.SSHClient, content: str, remote_path: str):
    sftp = ssh.open_sftp()
    with sftp.file(remote_path, 'w') as f:
        f.write(content)
    sftp.close()


# ─── Pasos de configuración SSH ───────────────────────────────────────────────

def check_prerequisites(ssh, linux_user: str, total):
    step(3, total, "Comprobando prerequisitos del servidor")

    out, _, _ = run(ssh, "curl --version 2>/dev/null | head -1", check=False)
    if "curl" not in out.lower():
        info("curl no instalado — instalando...")
        run(ssh, "apt-get install -y curl 2>/dev/null || yum install -y curl 2>/dev/null")
    ok("curl disponible")

    out, _, _ = run(ssh, "find /lib* /usr/lib* -name 'pam_exec.so' 2>/dev/null | head -1", check=False)
    if out:
        ok(f"pam_exec.so: {out}")
    else:
        warn("pam_exec.so no encontrado en rutas estándar — puede estar en otra ruta")

    # Crear el usuario Linux compartido si no existe
    out, _, rc = run(ssh, f"id {linux_user} 2>/dev/null && echo exists || echo missing", check=False)
    if "missing" in out:
        info(f"Creando usuario Linux compartido '{linux_user}'...")
        run(ssh, f"useradd -m -s /bin/bash {linux_user} 2>/dev/null || useradd -m {linux_user}")
        # Bloquear contraseña del sistema: el acceso será SOLO via OTP
        run(ssh, f"passwd -l {linux_user}", check=False)
        ok(f"Usuario '{linux_user}' creado con contraseña bloqueada (solo acceso OTP)")
    else:
        # Asegurar que la contraseña del sistema esté bloqueada para forzar uso de OTP
        run(ssh, f"passwd -l {linux_user} 2>/dev/null", check=False)
        ok(f"Usuario '{linux_user}' ya existe")


def create_pam_script(ssh, slug: str, api_secret: str, total):
    step(4, total, f"Creando script PAM en {PAM_SCRIPT_PATH}")

    validate_url = f"{HAWCERT_BASE_URL}/api/ssh/validate"

    script = textwrap.dedent(f"""\
        #!/bin/bash
        # HawCert SSH OTP Validator — generado automáticamente
        TOKEN=$(cat -)
        RESULT=$(curl -sf --max-time 10 -X POST "{validate_url}" \\
          -H "Content-Type: application/json" \\
          -d "{{\\\"server_slug\\\":\\\"{slug}\\\",\\\"api_secret\\\":\\\"{api_secret}\\\",\\\"username\\\":\\\"$PAM_USER\\\",\\\"token\\\":\\\"$TOKEN\\\"}}")
        if echo "$RESULT" | grep -q '"success":true'; then
            logger -t hawcert "OTP OK: $PAM_USER"
            exit 0
        fi
        logger -t hawcert "OTP DENEGADO: $PAM_USER"
        exit 1
        """)

    upload_text(ssh, script, PAM_SCRIPT_PATH)
    run(ssh, f"chmod 700 {PAM_SCRIPT_PATH} && chown root:root {PAM_SCRIPT_PATH}")
    ok(f"Script creado y protegido")


def configure_pam(ssh, total):
    step(5, total, f"Configurando {PAM_SSHD_PATH}")

    run(ssh, f"cp {PAM_SSHD_PATH} {PAM_SSHD_PATH}.hawcert.bak")
    ok("Backup creado")

    out, _, _ = run(ssh, f"grep -c 'pam_hawcert' {PAM_SSHD_PATH}", check=False)
    if int(out.strip() or "0") > 0:
        # Si existe con 'required', actualizar a 'sufficient' para que el OTP sea suficiente
        existing, _, _ = run(ssh, f"grep 'pam_hawcert' {PAM_SSHD_PATH}", check=False)
        if "required" in existing:
            run(ssh, f"sed -i 's|auth required pam_exec.so expose_authtok|auth sufficient pam_exec.so expose_authtok|' {PAM_SSHD_PATH}")
            ok("Entrada HawCert actualizada: required → sufficient")
        else:
            ok("Entrada HawCert ya configurada correctamente (sufficient)")
        return

    # 'sufficient': si el OTP valida, la autenticación es exitosa inmediatamente.
    # No se consulta pam_unix.so (contraseña del sistema), lo que permite
    # usar la cuenta con contraseña de sistema bloqueada.
    hawcert_line = f"auth sufficient pam_exec.so expose_authtok {PAM_SCRIPT_PATH}"
    run(ssh, f"sed -i '1s|^|{hawcert_line}\\n|' {PAM_SSHD_PATH}")
    ok("Línea HawCert añadida al inicio de pam.d/sshd (sufficient)")


def configure_sshd(ssh, total):
    step(6, total, "Verificando configuración sshd")

    out, _, _ = run(ssh, f"grep -i 'KbdInteractiveAuthentication\\|ChallengeResponseAuthentication' {SSHD_CONFIG_PATH}", check=False)
    if "yes" in out.lower():
        ok("KbdInteractiveAuthentication ya activo")
    else:
        _, _, rc = run(ssh, f"grep -qi 'KbdInteractiveAuthentication' {SSHD_CONFIG_PATH}", check=False)
        if rc == 0:
            run(ssh, f"sed -i 's/.*KbdInteractiveAuthentication.*/KbdInteractiveAuthentication yes/' {SSHD_CONFIG_PATH}")
        else:
            run(ssh, f"echo 'KbdInteractiveAuthentication yes' >> {SSHD_CONFIG_PATH}")
        ok("KbdInteractiveAuthentication habilitado")

    out, _, _ = run(ssh, f"grep -i '^PasswordAuthentication' {SSHD_CONFIG_PATH}", check=False)
    if "no" in out.lower():
        warn("PasswordAuthentication está en 'no'")
        warn("Habilitando para que PAM pueda recibir la contraseña...")
        run(ssh, f"sed -i 's/^PasswordAuthentication.*/PasswordAuthentication yes/' {SSHD_CONFIG_PATH}")
        ok("PasswordAuthentication habilitado")


def restart_ssh(ssh, total):
    step(7, total, "Reiniciando SSH")

    out, err_out, rc = run(ssh, "sshd -t 2>&1", check=False)
    if rc != 0:
        raise RuntimeError(f"Configuración SSH inválida — NO se reinicia:\n{err_out}")
    ok("Configuración SSH válida")

    run(ssh, "systemctl restart sshd 2>/dev/null || service ssh restart 2>/dev/null", check=False)
    time.sleep(2)
    ok("SSH reiniciado")


def verify_setup(ssh, slug: str, total):
    step(8, total, "Verificando instalación")

    out, _, rc = run(ssh, f"test -x {PAM_SCRIPT_PATH} && echo ok", check=False)
    ok("Script PAM ejecutable") if "ok" in out else err("Script no encontrado")

    out, _, rc = run(ssh, f"grep -c 'pam_hawcert' {PAM_SSHD_PATH}", check=False)
    ok("PAM configurado") if int(out.strip() or "0") > 0 else err("PAM no configurado")

    info("Probando conectividad con API de HawCert desde el servidor...")
    validate_url = f"{HAWCERT_BASE_URL}/api/ssh/validate"
    out, _, _ = run(ssh,
        f'curl -sf --max-time 5 -o /dev/null -w "%{{http_code}}" -X POST "{validate_url}" '
        f'-H "Content-Type: application/json" '
        f'-d \'{{"server_slug":"{slug}","api_secret":"test","username":"test","token":"test"}}\'',
        check=False)
    if out in ("401", "403", "404"):
        ok(f"API accesible desde el servidor (HTTP {out} — esperado para token de prueba)")
    else:
        warn(f"API devolvió HTTP {out} — verifica que el servidor tiene acceso a internet")


# ─── Main ─────────────────────────────────────────────────────────────────────

def main():
    TOTAL_STEPS = 8

    print(f"\n{BOLD}{'═'*62}{RESET}")
    print(f"{BOLD}  HawCert SSH OTP — Configuración automática completa{RESET}")
    print(f"{BOLD}{'═'*62}{RESET}\n")
    print("Este script registra el servidor en HawCert y lo configura")
    print("para aceptar claves de un solo uso. No necesitas tocar el panel.\n")

    # ── Datos del servidor SSH ────────────────────────────────────────────────
    step(1, TOTAL_STEPS, "Datos del servidor SSH")
    print()
    ssh_host  = input(f"  IP / Hostname [{DEFAULT_SSH_HOST}]: ").strip() or DEFAULT_SSH_HOST
    port_str  = input(f"  Puerto SSH [{DEFAULT_SSH_PORT}]: ").strip()
    ssh_port  = int(port_str) if port_str.isdigit() else DEFAULT_SSH_PORT
    ssh_user  = input(f"  Usuario SSH (para conectar ahora) [{DEFAULT_SSH_USER}]: ").strip() or DEFAULT_SSH_USER
    ssh_pass  = getpass.getpass(f"  Contraseña SSH para {ssh_user}@{ssh_host}: ")

    linux_user = input(f"  Usuario Linux compartido en el servidor [{DEFAULT_SSH_LINUX_USER}]: ").strip() or DEFAULT_SSH_LINUX_USER
    server_name = input(f"  Nombre del servidor en HawCert (ej: Servidor IONOS Interno): ").strip()
    if not server_name:
        server_name = f"Servidor {ssh_host}"

    # ── Datos de HawCert ──────────────────────────────────────────────────────
    step(2, TOTAL_STEPS, "Datos de HawCert")
    print()
    print(f"  URL de HawCert: {HAWCERT_BASE_URL}")
    print(f"  Necesitas el HAWCERT_ADMIN_API_KEY configurado en el .env del servidor.")
    print(f"  Si no lo tienes, genera uno con:  openssl rand -hex 32\n")
    admin_key = getpass.getpass("  HAWCERT_ADMIN_API_KEY: ")

    if not admin_key:
        err("La clave admin es obligatoria.")
        sys.exit(1)

    # ── Registrar en HawCert ─────────────────────────────────────────────────
    print()
    info(f"Registrando '{server_name}' en HawCert...")
    try:
        hawcert_data = register_in_hawcert(server_name, ssh_host, ssh_port, linux_user, admin_key)
    except RuntimeError as e:
        err(str(e))
        sys.exit(1)

    slug       = hawcert_data['slug']
    api_secret = hawcert_data['api_secret']
    service_id = hawcert_data['service_id']

    ok(f"Servicio creado en HawCert — ID: {service_id}, slug: '{slug}'")

    # ── Conectar al servidor ──────────────────────────────────────────────────
    step(3, TOTAL_STEPS, f"Conectando a {ssh_user}@{ssh_host}:{ssh_port}")
    print()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        ssh.connect(ssh_host, port=ssh_port, username=ssh_user, password=ssh_pass, timeout=15)
    except paramiko.AuthenticationException:
        err("Autenticación SSH fallida")
        sys.exit(1)
    except Exception as e:
        err(f"No se pudo conectar: {e}")
        sys.exit(1)
    ok(f"Conectado a {ssh_host}")

    # ── Configurar el servidor ────────────────────────────────────────────────
    try:
        check_prerequisites(ssh, linux_user, TOTAL_STEPS)
        create_pam_script(ssh, slug, api_secret, TOTAL_STEPS)
        configure_pam(ssh, TOTAL_STEPS)
        configure_sshd(ssh, TOTAL_STEPS)
        restart_ssh(ssh, TOTAL_STEPS)
        verify_setup(ssh, slug, TOTAL_STEPS)
    except RuntimeError as e:
        print(f"\n{R}{'─'*62}{RESET}")
        err(str(e))
        print(f"\n{Y}Para restaurar PAM:{RESET}  cp {PAM_SSHD_PATH}.hawcert.bak {PAM_SSHD_PATH}")
        ssh.close()
        sys.exit(1)
    finally:
        ssh.close()

    # ── Resumen ───────────────────────────────────────────────────────────────
    print(f"\n{G}{'═'*62}{RESET}")
    print(f"{G}{BOLD}  ✓ Todo configurado correctamente{RESET}")
    print(f"{G}{'═'*62}{RESET}\n")
    print(f"  Servidor:   {BOLD}{server_name}{RESET}  ({ssh_host}:{ssh_port})")
    print(f"  HawCert ID: {service_id}  |  slug: {slug}")
    print()
    print(f"  {BOLD}Próximo paso:{RESET} Asigna este servicio a los certificados de las")
    print(f"  personas que necesitan acceso:")
    print(f"  → Panel HawCert → Certificados → Editar → Servicios → ✓ {server_name}")
    print()
    print(f"  {BOLD}Flujo de acceso:{RESET}")
    print(f"    1. Usuario entra en {HAWCERT_BASE_URL}/servidores")
    print(f"    2. Genera su clave de un solo uso (válida 10 min)")
    print(f"    3. ssh {linux_user}@{ssh_host}  y pega la clave")
    print()
    print(f"  Para deshacer:  cp {PAM_SSHD_PATH}.hawcert.bak {PAM_SSHD_PATH} && systemctl restart sshd")
    print()


if __name__ == "__main__":
    main()
