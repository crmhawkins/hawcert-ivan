#!/bin/bash
# HawCert — Habilitar OTP de HawCert para sudo
# =============================================
# Requiere que setup_ssh_otp.py ya haya configurado el servidor SSH
# (el script PAM /etc/pam_hawcert.sh debe existir).
#
# Ejecutar como root desde dentro del servidor:
#   bash setup_sudo_otp.sh

set -euo pipefail

PAM_SCRIPT_PATH="/etc/pam_hawcert.sh"
PAM_SUDO_PATH="/etc/pam.d/sudo"
HAWCERT_LINE="auth sufficient pam_exec.so expose_authtok $PAM_SCRIPT_PATH"

G="\033[92m"; Y="\033[93m"; R="\033[91m"; B="\033[94m"
BOLD="\033[1m"; RESET="\033[0m"

ok()   { echo -e "  ${G}✓${RESET} $*"; }
warn() { echo -e "  ${Y}⚠${RESET}  $*"; }
err()  { echo -e "  ${R}✗${RESET} $*"; }
info() { echo -e "  ${B}→${RESET} $*"; }

echo -e "\n${BOLD}══════════════════════════════════════════════════════════════${RESET}"
echo -e "${BOLD}  HawCert — Habilitar OTP para sudo${RESET}"
echo -e "${BOLD}══════════════════════════════════════════════════════════════${RESET}\n"

# ── 1. Comprobar que se ejecuta como root ─────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    err "Este script debe ejecutarse como root (usa: sudo bash $0)"
    exit 1
fi
ok "Ejecutando como root"

# ── 2. Verificar que el script PAM existe ─────────────────────────────────────
if [[ ! -x "$PAM_SCRIPT_PATH" ]]; then
    err "No se encontró $PAM_SCRIPT_PATH"
    echo ""
    echo -e "  ${Y}Primero ejecuta setup_ssh_otp.py desde tu máquina para registrar"
    echo -e "  este servidor en HawCert y crear el script PAM.${RESET}"
    exit 1
fi
ok "Script PAM encontrado: $PAM_SCRIPT_PATH"

# ── 3. Verificar que pam_exec.so existe ──────────────────────────────────────
PAM_EXEC=$(find /lib* /usr/lib* -name "pam_exec.so" 2>/dev/null | head -1 || true)
if [[ -z "$PAM_EXEC" ]]; then
    warn "pam_exec.so no encontrado — puede que PAM no esté instalado"
    warn "Instala con: apt-get install libpam-runtime  o  yum install pam"
else
    ok "pam_exec.so: $PAM_EXEC"
fi

# ── 4. Verificar que /etc/pam.d/sudo existe ───────────────────────────────────
if [[ ! -f "$PAM_SUDO_PATH" ]]; then
    err "$PAM_SUDO_PATH no encontrado — ¿está sudo instalado?"
    exit 1
fi
ok "$PAM_SUDO_PATH encontrado"

# ── 5. Comprobar si ya está configurado ───────────────────────────────────────
if grep -q "pam_hawcert" "$PAM_SUDO_PATH" 2>/dev/null; then
    existing=$(grep "pam_hawcert" "$PAM_SUDO_PATH")
    if echo "$existing" | grep -q "required"; then
        info "Entrada HawCert encontrada con 'required' — actualizando a 'sufficient'..."
        sed -i 's|auth required pam_exec.so expose_authtok.*pam_hawcert.*|'"$HAWCERT_LINE"'|' "$PAM_SUDO_PATH"
        ok "Actualizado: required → sufficient"
    else
        ok "HawCert ya está configurado en sudo (sufficient) — nada que hacer"
    fi
    echo ""
    echo -e "${G}${BOLD}  ✓ sudo ya estaba configurado${RESET}"
    echo ""
    exit 0
fi

# ── 6. Hacer backup ───────────────────────────────────────────────────────────
cp "$PAM_SUDO_PATH" "${PAM_SUDO_PATH}.hawcert.bak"
ok "Backup creado: ${PAM_SUDO_PATH}.hawcert.bak"

# ── 7. Insertar línea HawCert al inicio de pam.d/sudo ────────────────────────
# 'sufficient': si el OTP valida, sudo se concede sin pedir contraseña del sistema.
sed -i "1s|^|${HAWCERT_LINE}\n|" "$PAM_SUDO_PATH"
ok "Línea HawCert añadida al inicio de $PAM_SUDO_PATH (sufficient)"

# ── 8. Verificar resultado ────────────────────────────────────────────────────
echo ""
info "Verificando configuración resultante..."
echo ""
echo -e "  ${BOLD}$PAM_SUDO_PATH:${RESET}"
head -5 "$PAM_SUDO_PATH" | sed 's/^/    /'
echo ""

if grep -q "pam_hawcert" "$PAM_SUDO_PATH"; then
    ok "Configuración verificada"
else
    err "Algo falló — la línea no aparece en $PAM_SUDO_PATH"
    echo "  Para restaurar: cp ${PAM_SUDO_PATH}.hawcert.bak $PAM_SUDO_PATH"
    exit 1
fi

# ── Resumen ───────────────────────────────────────────────────────────────────
echo -e "${G}══════════════════════════════════════════════════════════════${RESET}"
echo -e "${G}${BOLD}  ✓ sudo configurado correctamente con HawCert OTP${RESET}"
echo -e "${G}══════════════════════════════════════════════════════════════${RESET}"
echo ""
echo -e "  ${BOLD}Flujo de uso:${RESET}"
echo "    1. Genera una clave en HawCert (panel → Servidores)"
echo "    2. Dentro del servidor, ejecuta:  sudo <comando>"
echo "    3. Cuando pida contraseña, pega la clave OTP de HawCert"
echo ""
echo -e "  ${Y}Nota:${RESET} El token SSH se consume al hacer login."
echo "  Para sudo necesitas generar un token nuevo."
echo ""
echo -e "  Para deshacer: cp ${PAM_SUDO_PATH}.hawcert.bak $PAM_SUDO_PATH"
echo ""
