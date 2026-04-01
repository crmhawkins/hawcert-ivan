# HawCert SSH OTP - Configuracion automatica (PowerShell 5+)
# Uso: .\setup_ssh_otp.ps1

$ErrorActionPreference = "Stop"

$DEFAULT_SSH_HOST   = "217.160.39.79"
$DEFAULT_SSH_PORT   = 22
$DEFAULT_SSH_USER   = "root"
$DEFAULT_LINUX_USER = "root"
$HAWCERT_BASE_URL   = "https://hawcert.hawkins.es"
$PAM_SCRIPT_PATH    = "/etc/pam_hawcert.sh"
$PAM_SSHD_PATH      = "/etc/pam.d/sshd"
$SSHD_CONFIG_PATH   = "/etc/ssh/sshd_config"
$TOTAL_STEPS        = 8

function ok($m)   { Write-Host "  [OK] $m" -ForegroundColor Green }
function warn($m) { Write-Host "  [!!] $m" -ForegroundColor Yellow }
function err($m)  { Write-Host "  [XX] $m" -ForegroundColor Red }
function info($m) { Write-Host "  --> $m" -ForegroundColor Cyan }
function hdr($n, $m) { Write-Host "`n[$n/$TOTAL_STEPS] $m" -ForegroundColor White }

# Ejecuta un comando SSH y devuelve la salida
function Invoke-Remote($session, [string]$cmd) {
    $r = Invoke-SSHCommand -SessionId $session.SessionId -Command $cmd
    return $r
}

# ─── Cabecera ─────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "============================================================" -ForegroundColor White
Write-Host "  HawCert SSH OTP - Configuracion automatica completa"       -ForegroundColor White
Write-Host "============================================================" -ForegroundColor White
Write-Host ""

# ─── Recoger datos del servidor SSH ───────────────────────────────────────────
hdr 1 "Datos del servidor SSH"
Write-Host ""

$sshHost = Read-Host "  IP / Hostname [$DEFAULT_SSH_HOST]"
if ([string]::IsNullOrWhiteSpace($sshHost)) { $sshHost = $DEFAULT_SSH_HOST }

$sshPortInput = Read-Host "  Puerto SSH [$DEFAULT_SSH_PORT]"
$sshPort = if ([string]::IsNullOrWhiteSpace($sshPortInput)) { $DEFAULT_SSH_PORT } else { [int]$sshPortInput }

$sshUser = Read-Host "  Usuario SSH [$DEFAULT_SSH_USER]"
if ([string]::IsNullOrWhiteSpace($sshUser)) { $sshUser = $DEFAULT_SSH_USER }

$sshPassSecure = Read-Host "  Contrasena SSH para ${sshUser}@${sshHost}" -AsSecureString
$sshPass = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
               [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sshPassSecure))

$linuxUser = Read-Host "  Usuario Linux compartido en el servidor [$DEFAULT_LINUX_USER]"
if ([string]::IsNullOrWhiteSpace($linuxUser)) { $linuxUser = $DEFAULT_LINUX_USER }

$serverName = Read-Host "  Nombre del servidor en HawCert (ej: Servidor IONOS Interno)"
if ([string]::IsNullOrWhiteSpace($serverName)) { $serverName = "Servidor $sshHost" }

# ─── Recoger clave admin HawCert ─────────────────────────────────────────────
hdr 2 "Clave admin de HawCert"
Write-Host ""
Write-Host "  Necesitas HAWCERT_ADMIN_API_KEY del .env del servidor HawCert." -ForegroundColor Gray

$adminKeySecure = Read-Host "  HAWCERT_ADMIN_API_KEY" -AsSecureString
$adminKey = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
                [Runtime.InteropServices.Marshal]::SecureStringToBSTR($adminKeySecure))

if ([string]::IsNullOrWhiteSpace($adminKey)) {
    err "La clave admin es obligatoria."
    exit 1
}

# ─── Registrar servidor en HawCert ───────────────────────────────────────────
hdr 3 "Registrando servidor en HawCert"
Write-Host ""
info "Llamando a la API de HawCert..."

$registerUrl = "$HAWCERT_BASE_URL/api/admin/register-ssh-server"
$bodyObj = @{ name = $serverName; ssh_host = $sshHost; ssh_port = $sshPort; ssh_user = $linuxUser }
$bodyJson = $bodyObj | ConvertTo-Json -Compress

try {
    $response = Invoke-RestMethod `
        -Uri $registerUrl `
        -Method POST `
        -ContentType "application/json" `
        -Headers @{ "X-Admin-Key" = $adminKey } `
        -Body $bodyJson
} catch {
    $code = $_.Exception.Response.StatusCode.value__
    if ($code -eq 401) { err "HAWCERT_ADMIN_API_KEY incorrecta o no configurada en el .env del servidor" }
    elseif ($code -eq 422) { err "Datos invalidos: $($_.ErrorDetails.Message)" }
    else { err "Error HTTP ${code}: $($_.Exception.Message)" }
    exit 1
}

$slug      = $response.slug
$apiSecret = $response.api_secret
$serviceId = $response.service_id

ok "Servicio creado en HawCert - ID: $serviceId | slug: '$slug'"

# ─── Instalar Posh-SSH si no esta ────────────────────────────────────────────
hdr 4 "Preparando modulo SSH"
Write-Host ""

if (-not (Get-Module -ListAvailable -Name Posh-SSH)) {
    info "Instalando modulo Posh-SSH (solo la primera vez)..."
    Install-Module -Name Posh-SSH -Force -Scope CurrentUser -AllowClobber
    ok "Posh-SSH instalado"
} else {
    ok "Posh-SSH ya disponible"
}
Import-Module Posh-SSH -Force

# ─── Conectar al servidor ────────────────────────────────────────────────────
hdr 5 "Conectando a ${sshUser}@${sshHost}:${sshPort}"
Write-Host ""

$secPass = ConvertTo-SecureString $sshPass -AsPlainText -Force
$cred    = New-Object System.Management.Automation.PSCredential($sshUser, $secPass)

try {
    $session = New-SSHSession -ComputerName $sshHost -Port $sshPort `
                              -Credential $cred -AcceptKey -Force
} catch {
    err "No se pudo conectar: $($_.Exception.Message)"
    exit 1
}
ok "Conectado a $sshHost"

# ─── Comprobar curl ───────────────────────────────────────────────────────────
hdr 6 "Configurando el servidor"
Write-Host ""

$r = Invoke-Remote $session "which curl 2>/dev/null && echo found || echo missing"
if ($r.Output -notmatch "found") {
    info "Instalando curl..."
    Invoke-Remote $session "apt-get install -y curl 2>/dev/null" | Out-Null
}
ok "curl disponible"

# ─── Crear usuario Linux compartido si no existe ──────────────────────────────
$checkUser = Invoke-Remote $session "id $linuxUser 2>/dev/null && echo exists || echo missing"
if ($checkUser.Output -match "missing") {
    info "Creando usuario Linux compartido '$linuxUser'..."
    Invoke-Remote $session "useradd -m -s /bin/bash $linuxUser 2>/dev/null; echo done" | Out-Null
    Invoke-Remote $session "passwd -l $linuxUser 2>/dev/null; echo done" | Out-Null
    ok "Usuario '$linuxUser' creado con contrasena bloqueada (solo acceso OTP)"
} else {
    Invoke-Remote $session "passwd -l $linuxUser 2>/dev/null; echo done" | Out-Null
    ok "Usuario '$linuxUser' ya existe"
}

# ─── Crear script PAM ────────────────────────────────────────────────────────
$validateUrl = "$HAWCERT_BASE_URL/api/ssh/validate"

# Construir el contenido del script PAM con here-string de PowerShell.
# Las variables de bash (PAM_USER, TOKEN, RESULT) se escapan con backtick (`$)
# para que PowerShell NO las expanda. $slug, $apiSecret y $validateUrl SI se expanden.
$pamScript = @"
#!/bin/bash
# HawCert SSH OTP Validator - generado automaticamente
TOKEN=`$(cat -)
RESULT=`$(curl -sf --max-time 10 -X POST "$validateUrl" \
  -H "Content-Type: application/json" \
  -d "{\"server_slug\":\"$slug\",\"api_secret\":\"$apiSecret\",\"username\":\"`$PAM_USER\",\"token\":\"`$TOKEN\"}")
if echo "`$RESULT" | grep -q '"'"'success'"'"':true'; then
    logger -t hawcert "OTP OK: `$PAM_USER"
    exit 0
fi
logger -t hawcert "OTP DENEGADO: `$PAM_USER"
exit 1
"@

# Subir via base64 para evitar cualquier problema de escaping de shell
$pamBytes  = [System.Text.Encoding]::UTF8.GetBytes($pamScript)
$pamBase64 = [Convert]::ToBase64String($pamBytes)
Invoke-Remote $session "echo '$pamBase64' | base64 -d > $PAM_SCRIPT_PATH" | Out-Null
Invoke-Remote $session "chmod 700 $PAM_SCRIPT_PATH && chown root:root $PAM_SCRIPT_PATH" | Out-Null
ok "Script PAM creado en $PAM_SCRIPT_PATH"

# ─── Configurar pam.d/sshd ───────────────────────────────────────────────────
Invoke-Remote $session "cp $PAM_SSHD_PATH ${PAM_SSHD_PATH}.hawcert.bak" | Out-Null
ok "Backup de PAM creado"

$checkPam = Invoke-Remote $session "grep -c pam_hawcert $PAM_SSHD_PATH 2>/dev/null || echo 0"
if ([int]($checkPam.Output.Trim()) -eq 0) {
    # sufficient: si el OTP valida, acceso concedido inmediatamente sin consultar pam_unix.so
    $pamLine = "auth sufficient pam_exec.so expose_authtok $PAM_SCRIPT_PATH"
    $insertCmd = "sed -i '1s|^|" + $pamLine + "\n|' $PAM_SSHD_PATH"
    Invoke-Remote $session $insertCmd | Out-Null
    ok "Linea HawCert anadida a pam.d/sshd (sufficient)"
} else {
    # Si existe con 'required', corregir a 'sufficient'
    $existingPam = Invoke-Remote $session "grep pam_hawcert $PAM_SSHD_PATH"
    if ($existingPam.Output -match "required") {
        Invoke-Remote $session "sed -i 's|auth required pam_exec.so expose_authtok|auth sufficient pam_exec.so expose_authtok|' $PAM_SSHD_PATH" | Out-Null
        ok "Entrada HawCert actualizada: required -> sufficient"
    } else {
        ok "Entrada HawCert ya configurada correctamente (sufficient)"
    }
}

# ─── Configurar sshd_config ──────────────────────────────────────────────────
$checkKbd = Invoke-Remote $session "grep -i KbdInteractiveAuthentication $SSHD_CONFIG_PATH 2>/dev/null || echo notfound"
if ($checkKbd.Output -match "notfound") {
    Invoke-Remote $session "echo 'KbdInteractiveAuthentication yes' >> $SSHD_CONFIG_PATH" | Out-Null
    ok "KbdInteractiveAuthentication habilitado"
} elseif ($checkKbd.Output -notmatch "yes") {
    Invoke-Remote $session "sed -i 's/.*KbdInteractiveAuthentication.*/KbdInteractiveAuthentication yes/' $SSHD_CONFIG_PATH" | Out-Null
    ok "KbdInteractiveAuthentication activado"
} else {
    ok "KbdInteractiveAuthentication ya estaba activo"
}

$checkPass = Invoke-Remote $session "grep -i '^PasswordAuthentication' $SSHD_CONFIG_PATH 2>/dev/null || echo notfound"
if ($checkPass.Output -match " no") {
    Invoke-Remote $session "sed -i 's/^PasswordAuthentication.*/PasswordAuthentication yes/' $SSHD_CONFIG_PATH" | Out-Null
    ok "PasswordAuthentication habilitado"
}

# ─── Validar config y reiniciar SSH ─────────────────────────────────────────
hdr 7 "Reiniciando SSH"
Write-Host ""

$sshCheck = Invoke-Remote $session "sshd -t 2>&1 && echo VALID || echo INVALID"
if ($sshCheck.Output -notmatch "VALID") {
    err "Configuracion SSH invalida - restaurando backup"
    Invoke-Remote $session "cp ${PAM_SSHD_PATH}.hawcert.bak $PAM_SSHD_PATH" | Out-Null
    Remove-SSHSession -SessionId $session.SessionId | Out-Null
    exit 1
}
ok "Configuracion SSH valida"

Invoke-Remote $session "systemctl restart sshd 2>/dev/null; echo done" | Out-Null
Start-Sleep -Seconds 2
ok "SSH reiniciado"

# ─── Verificar ──────────────────────────────────────────────────────────────
hdr 8 "Verificando instalacion"
Write-Host ""

$testScript = Invoke-Remote $session "test -x $PAM_SCRIPT_PATH && echo ok || echo fail"
if ($testScript.Output -match "ok") { ok "Script PAM ejecutable" } else { warn "Script no encontrado" }

$testPam = Invoke-Remote $session "grep -c pam_hawcert $PAM_SSHD_PATH 2>/dev/null || echo 0"
if ([int]($testPam.Output.Trim()) -gt 0) { ok "PAM configurado correctamente" } else { warn "PAM no configurado" }

info "Probando conectividad con API de HawCert desde el servidor..."
$curlTest = Invoke-Remote $session "curl -sf --max-time 5 -o /dev/null -w '%{http_code}' -X POST '$validateUrl' -H 'Content-Type: application/json' -d '{\"server_slug\":\"test\",\"api_secret\":\"test\",\"username\":\"test\",\"token\":\"test\"}'"
$httpCode = $curlTest.Output.Trim()
if ($httpCode -eq "401" -or $httpCode -eq "403" -or $httpCode -eq "404") {
    ok "API accesible desde el servidor (HTTP $httpCode - esperado para datos de prueba)"
} else {
    warn "API devolvio HTTP $httpCode - verifica que el servidor tiene acceso a internet"
}

Remove-SSHSession -SessionId $session.SessionId | Out-Null

# ─── Resumen final ────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "============================================================" -ForegroundColor Green
Write-Host "  Configuracion completada correctamente"                     -ForegroundColor Green
Write-Host "============================================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Servidor : $serverName ($sshHost)" -ForegroundColor White
Write-Host "  ID       : $serviceId  |  slug: $slug"   -ForegroundColor White
Write-Host ""
Write-Host "  PROXIMO PASO:" -ForegroundColor Yellow
Write-Host "  Panel HawCert -> Certificados -> Editar -> Servicios" -ForegroundColor White
Write-Host "  Marca '$serverName' para cada persona que necesite acceso" -ForegroundColor White
Write-Host ""
Write-Host "  Para deshacer:" -ForegroundColor Gray
Write-Host "  cp ${PAM_SSHD_PATH}.hawcert.bak $PAM_SSHD_PATH && systemctl restart sshd" -ForegroundColor Gray
Write-Host ""
