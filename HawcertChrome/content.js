// Content script para inyectar credenciales automáticamente

(function() {
  'use strict';

  const DEBUG = true; // Cambiar a false para reducir logs en consola (F12 > Console)

  function log(...args) {
    if (DEBUG) {
      console.log('[HawCert]', ...args);
    }
  }

  function logError(...args) {
    console.error('[HawCert]', ...args);
  }

  let isFilling = false;
  let currentUrl = window.location.href;
  let filledFields = new Set(); // Para evitar rellenar múltiples veces

  // Patrones comunes para detectar campos de usuario/email
  const USERNAME_PATTERNS = [
    // Por atributo name
    'input[name*="user" i]',
    'input[name*="email" i]',
    'input[name*="mail" i]',
    'input[name*="login" i]',
    'input[name*="account" i]',
    // Por atributo id
    'input[id*="user" i]',
    'input[id*="email" i]',
    'input[id*="mail" i]',
    'input[id*="login" i]',
    'input[id*="account" i]',
    // Por tipo
    'input[type="email"]',
    'input[type="text"][placeholder*="email" i]',
    'input[type="text"][placeholder*="user" i]',
    'input[type="text"][placeholder*="mail" i]',
    // Por clase
    'input.email',
    'input.username',
    'input.user',
  ];

  // Patrones comunes para detectar campos de contraseña
  const PASSWORD_PATTERNS = [
    'input[type="password"]',
    'input[name*="pass" i]',
    'input[id*="pass" i]',
    'input.password',
    'input.passwd',
  ];

  // Patrones de texto para botones de envío / login
  const SUBMIT_TEXT_PATTERNS = [
    'Iniciar', 'Login', 'Entrar', 'Sign in', 'Log in', 'Iniciar sesión', 'Acceder'
  ];

  // Patrones para botón "Siguiente" (cuando usuario y contraseña están en páginas separadas)
  const NEXT_BUTTON_PATTERNS = [
    'Siguiente', 'Next', 'Continuar', 'Continue', 'Weiter', 'Suivant', 'Avanti',
    'Siguiente paso', 'Next step', 'Continuar con', 'Continue with'
  ];

  // Detectar cuando la página cambia (SPA)
  let lastUrl = location.href;
  new MutationObserver(() => {
    const url = location.href;
    if (url !== lastUrl) {
      lastUrl = url;
      currentUrl = url;
      filledFields.clear(); // Limpiar campos rellenados al cambiar de página
      // Esperar un poco para que la página cargue
      setTimeout(checkAndFill, 1500);
    }
  }).observe(document, { subtree: true, childList: true });

  // Mostrar overlay de protección si venimos de una navegación con credenciales
  if (sessionStorage.getItem('hawcert-navigating')) {
    showSecureOverlay();
    window.addEventListener('load', function() {
      setTimeout(removeSecureOverlay, 800);
    });
  }

  // Verificar y rellenar cuando la página carga
  // Dar tiempo al service worker para activarse
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      setTimeout(checkAndFill, 2000);
    });
  } else {
    setTimeout(checkAndFill, 2000);
  }

  // Observar cuando aparecen nuevos formularios
  const formObserver = new MutationObserver(() => {
    if (!isFilling) {
      setTimeout(checkAndFill, 500);
    }
  });

  formObserver.observe(document.body, {
    childList: true,
    subtree: true,
  });

  /**
   * Verifica si hay credenciales para esta URL y las rellena (o pregunta).
   * @param {boolean} manual - true si el usuario pulsó "Rellenar ahora" (salta la confirmación)
   */
  async function checkAndFill(manual = false) {
    if (isFilling) return false;

    log('checkAndFill iniciado', { url: currentUrl, manual });

    try {
      if (!chrome.runtime || !chrome.runtime.sendMessage) {
        log('Runtime no disponible');
        return false;
      }

      if (manual) {
        // Flujo Manual: Obtenemos contraseñas directamente sin confirmación extra
        const response = await new Promise((resolve, reject) => {
          chrome.runtime.sendMessage(
            { action: 'getCredentials', url: currentUrl, manual: true },
            (response) => {
              if (chrome.runtime.lastError) resolve(null);
              else resolve(response);
            }
          );
        });

        if (response && response.success && response.credentials) {
          log('Credenciales manuales recibidas para', response.credentials.website_name);
          return await fillCredentials(response.credentials);
        }
        return false;
      }

      // Flujo Automático: Just-In-Time (Comprobar -> Confirmar -> Obtener -> Inyectar)
      const checkResponse = await new Promise((resolve, reject) => {
        chrome.runtime.sendMessage(
          { action: 'checkCredentials', url: currentUrl },
          (response) => {
            if (chrome.runtime.lastError) resolve(null);
            else resolve(response);
          }
        );
      });

      if (!checkResponse || !checkResponse.success) {
        return false;
      }

      if (checkResponse.certificateOnly) {
        log('Página con autenticación solo por certificado');
        chrome.runtime.sendMessage({
          action: 'showCertificateOnlyNotification',
          websiteName: checkResponse.websiteName || 'Esta página',
        });
        return true;
      }

      if (checkResponse.multipleAccounts && checkResponse.multipleAccounts.length > 1) {
        showAccountPickerUI(checkResponse.multipleAccounts);
        return true;
      }

      showConfirmationUI(checkResponse.websiteName, checkResponse.certificateFile === true);
      return true;

    } catch (error) {
      logError('Error en checkAndFill', error);
      return false;
    }
  }

  function showConfirmationUI(websiteName, isCertificateFile = false) {
    if (document.getElementById('hawcert-shadow-host')) return;

    const host = document.createElement('div');
    host.id = 'hawcert-shadow-host';
    host.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 2147483647; font-family: system-ui, -apple-system, sans-serif;';
    document.body.appendChild(host);

    const shadow = host.attachShadow({ mode: 'closed' });
    
    const container = document.createElement('div');
    container.style.cssText = 'background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); padding: 16px; width: 320px; color: #1f2937; animation: hawcertSlideIn 0.3s ease-out;';
    
    const title = document.createElement('div');
    title.style.cssText = 'font-weight: 600; margin-bottom: 8px; font-size: 14px; display: flex; align-items: center; gap: 8px;';
    title.innerHTML = '<span style="background: #2563eb; color: white; border-radius: 4px; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; font-weight: bold;">H</span> HawCert Auto-Fill';
    
    const msg = document.createElement('div');
    msg.style.cssText = 'font-size: 13px; margin-bottom: 16px; line-height: 1.5; color: #4b5563;';
    msg.textContent = isCertificateFile
      ? `¿Adjuntar tu certificado a ${websiteName || 'esta web'}?`
      : `¿Acceder automáticamente a ${websiteName || 'esta web'}?`;

    const btnContainer = document.createElement('div');
    btnContainer.style.cssText = 'display: flex; gap: 8px; justify-content: flex-end;';

    const btnNo = document.createElement('button');
    btnNo.textContent = 'No';
    btnNo.style.cssText = 'padding: 6px 14px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: background 0.2s;';
    btnNo.onmouseover = () => btnNo.style.background = '#f3f4f6';
    btnNo.onmouseout = () => btnNo.style.background = '#fff';
    
    const btnYes = document.createElement('button');
    btnYes.textContent = 'Sí';
    btnYes.style.cssText = 'padding: 6px 14px; border: none; background: #2563eb; color: white; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); transition: background 0.2s;';
    btnYes.onmouseover = () => btnYes.style.background = '#1d4ed8';
    btnYes.onmouseout = () => btnYes.style.background = '#2563eb';

    const style = document.createElement('style');
    style.textContent = `
      @keyframes hawcertSlideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
      button:disabled { opacity: 0.7; cursor: not-allowed !important; }
    `;

    btnNo.onclick = () => host.remove();
    
    btnYes.onclick = async () => {
      btnYes.textContent = 'Inyectando...';
      btnYes.disabled = true;
      btnNo.disabled = true;
      showSecureOverlay();

      try {
        const response = await new Promise(resolve => {
            chrome.runtime.sendMessage({ action: 'retrieveCachedCredentials' }, resolve);
        });

        if (response && response.success && response.credentials) {
           host.remove();
           await fillCredentials(response.credentials);
        } else {
           msg.textContent = 'Error: ' + (response?.error || 'No se pudieron recuperar las credenciales seguras');
           msg.style.color = '#ef4444';
           btnNo.textContent = 'Cerrar';
           btnNo.disabled = false;
           removeSecureOverlay();
        }
      } catch (e) {
         msg.textContent = 'Error de conexión interna.';
         btnNo.disabled = false;
         removeSecureOverlay();
      }
    };

    btnContainer.appendChild(btnNo);
    btnContainer.appendChild(btnYes);
    
    container.appendChild(title);
    container.appendChild(msg);
    container.appendChild(btnContainer);
    
    shadow.appendChild(style);
    shadow.appendChild(container);
  }

  function showAccountPickerUI(accounts) {
    if (document.getElementById('hawcert-shadow-host')) return;

    const host = document.createElement('div');
    host.id = 'hawcert-shadow-host';
    host.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 2147483647; font-family: system-ui, -apple-system, sans-serif;';
    document.body.appendChild(host);

    const shadow = host.attachShadow({ mode: 'closed' });

    const style = document.createElement('style');
    style.textContent = `
      @keyframes hawcertSlideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
      button:disabled { opacity: 0.7; cursor: not-allowed !important; }
      .account-btn { width: 100%; text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; color: #1f2937; transition: background 0.15s; margin-bottom: 6px; }
      .account-btn:hover { background: #f0f4ff; border-color: #2563eb; }
      .account-name { font-weight: 600; display: block; }
      .account-hint { color: #6b7280; font-size: 12px; }
      .account-notes { color: #9ca3af; font-size: 11px; }
    `;

    const container = document.createElement('div');
    container.style.cssText = 'background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); padding: 16px; width: 320px; color: #1f2937; animation: hawcertSlideIn 0.3s ease-out;';

    const title = document.createElement('div');
    title.style.cssText = 'font-weight: 600; margin-bottom: 8px; font-size: 14px; display: flex; align-items: center; gap: 8px;';
    title.innerHTML = '<span style="background: #2563eb; color: white; border-radius: 4px; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; font-weight: bold;">H</span> HawCert — Selecciona cuenta';

    const msg = document.createElement('div');
    msg.style.cssText = 'font-size: 13px; margin-bottom: 12px; color: #4b5563;';
    msg.textContent = '¿Con qué cuenta quieres acceder?';

    const accountsList = document.createElement('div');

    accounts.forEach((account) => {
      const btn = document.createElement('button');
      btn.className = 'account-btn';
      btn.innerHTML = `
        <span class="account-name">${account.website_name || 'Cuenta'}</span>
        ${account.username_hint ? `<span class="account-hint">${account.username_hint}</span>` : ''}
        ${account.notes ? `<span class="account-notes">${account.notes}</span>` : ''}
      `;

      btn.onclick = async () => {
        // Deshabilitar todos los botones
        accountsList.querySelectorAll('button').forEach(b => b.disabled = true);
        msg.textContent = 'Iniciando sesión...';
        showSecureOverlay();

        try {
          const response = await new Promise(resolve => {
            chrome.runtime.sendMessage(
              { action: 'retrieveCachedCredentials', credentialId: account.id },
              resolve
            );
          });

          if (response && response.success && response.credentials) {
            host.remove();
            await fillCredentials(response.credentials);
          } else {
            msg.textContent = 'Error: ' + (response?.error || 'No se pudieron recuperar las credenciales');
            msg.style.color = '#ef4444';
            removeSecureOverlay();
          }
        } catch (e) {
          msg.textContent = 'Error de conexión interna.';
          removeSecureOverlay();
        }
      };

      accountsList.appendChild(btn);
    });

    const btnCancel = document.createElement('button');
    btnCancel.textContent = 'Cancelar';
    btnCancel.style.cssText = 'width: 100%; padding: 6px 14px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; margin-top: 4px;';
    btnCancel.onclick = () => host.remove();

    container.appendChild(title);
    container.appendChild(msg);
    container.appendChild(accountsList);
    container.appendChild(btnCancel);

    shadow.appendChild(style);
    shadow.appendChild(container);
  }

  function showSecureOverlay() {
    if (document.getElementById('hawcert-secure-overlay')) return;
    const overlay = document.createElement('div');
    overlay.id = 'hawcert-secure-overlay';
    // Se usa un z-index altísimo
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(255, 255, 255, 0.95); z-index: 2147483646; display: flex; flex-direction: column; align-items: center; justify-content: center; font-family: system-ui, -apple-system, sans-serif; backdrop-filter: blur(8px);';
    overlay.innerHTML = `
      <div style="width: 48px; height: 48px; border: 4px solid #e5e7eb; border-top: 4px solid #2563eb; border-radius: 50%; animation: hawcertSpin 1s linear infinite;"></div>
      <div style="margin-top: 24px; font-size: 20px; color: #111827; font-weight: 600;">HawCert</div>
      <div style="margin-top: 8px; font-size: 15px; color: #4b5563;">Iniciando sesión de forma segura...</div>
      <style>@keyframes hawcertSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
    `;
    document.body.appendChild(overlay);
    sessionStorage.setItem('hawcert-navigating', '1');
  }

  function removeSecureOverlay() {
    sessionStorage.removeItem('hawcert-navigating');
    const overlay = document.getElementById('hawcert-secure-overlay');
    if (overlay) {
       overlay.style.transition = 'opacity 0.3s';
       overlay.style.opacity = '0';
       setTimeout(() => overlay.remove(), 300);
    }
  }

  /**
   * Busca un campo usando múltiples patrones
   */
  function findField(patterns, excludeFields = []) {
    for (const pattern of patterns) {
      try {
        const fields = Array.from(document.querySelectorAll(pattern));
        for (const field of fields) {
          // Verificar que el campo sea visible y no esté deshabilitado
          if (field.offsetParent !== null &&
              !field.disabled &&
              !field.readOnly &&
              !excludeFields.includes(field) &&
              !filledFields.has(field)) {
            return field;
          }
        }
      } catch (e) {
        // Ignorar selectores inválidos
        continue;
      }
    }
    return null;
  }

  /**
   * Busca el botón de envío / login del formulario
   */
  function findSubmitButton(form) {
    const cssSelectors = [
      'button[type="submit"]',
      'input[type="submit"]',
      '[role="button"][aria-label*="login" i]',
      '[role="button"][aria-label*="sign in" i]',
      '[role="button"][aria-label*="iniciar" i]',
      '[role="button"][aria-label*="entrar" i]',
    ];

    for (const pattern of cssSelectors) {
      try {
        const button = form.querySelector(pattern);
        if (button && button.offsetParent !== null && !button.disabled) {
          return button;
        }
      } catch (e) {
        continue;
      }
    }

    const buttons = form.querySelectorAll('button, input[type="button"], input[type="submit"], [role="button"]');
    for (const button of buttons) {
      if (button.offsetParent === null || button.disabled) continue;
      const text = (button.textContent || button.value || button.getAttribute('aria-label') || '').toLowerCase();
      for (const pattern of SUBMIT_TEXT_PATTERNS) {
        if (text.includes(pattern.toLowerCase())) {
          return button;
        }
      }
    }
    return null;
  }

  /**
   * Busca el botón "Siguiente" / "Next" (para flujo usuario → siguiente → página de contraseña)
   */
  function findNextButton(form) {
    const buttons = form.querySelectorAll('button, input[type="button"], input[type="submit"], [role="button"], a[role="button"], a.btn');
    for (const button of buttons) {
      if (button.offsetParent === null || button.disabled) continue;
      const text = (button.textContent || button.value || button.getAttribute('aria-label') || '').trim().toLowerCase();
      for (const pattern of NEXT_BUTTON_PATTERNS) {
        if (text.includes(pattern.toLowerCase())) {
          return button;
        }
      }
    }
    return null;
  }

  /**
   * Rellena un campo y dispara eventos para que el sitio detecte el cambio
   */
  function fillFieldAndTrigger(field, value, label) {
    if (!field || value == null) return;
    field.focus();
    field.value = value;
    filledFields.add(field);
    const events = ['input', 'change', 'keyup', 'keydown', 'focus', 'blur'];
    events.forEach(eventType => {
      field.dispatchEvent(new Event(eventType, { bubbles: true, cancelable: true }));
    });
    field.dispatchEvent(new InputEvent('input', { bubbles: true, cancelable: true }));
    log(label, 'rellenado, valor length=', value.length);
  }

  /**
   * Rellena los campos con las credenciales. Soporta:
   * - Solo certificado (sin formulario) → mostrar notificación para elegir certificado cuando el navegador lo pida
   * - Usuario + contraseña en la misma página → rellenar ambos y enviar
   * - Solo usuario (página de "siguiente") → rellenar usuario y pulsar Siguiente
   * - Solo contraseña (segunda página) → rellenar contraseña y enviar
   * @returns {Promise<boolean>} true si se rellenó al menos un campo o se mostró aviso de solo certificado
   */
  async function fillCredentials(credential) {
    if (isFilling) return false;
    isFilling = true;

    try {
      if (credential.certificate_only) {
        log('Página con autenticación solo por certificado:', credential.website_name);
        if (chrome.runtime?.sendMessage) {
          chrome.runtime.sendMessage({
            action: 'showCertificateOnlyNotification',
            websiteName: credential.website_name || 'Esta página',
          });
        }
        return true;
      }

      if (credential.certificate_file) {
        log('Página con adjunto de certificado:', credential.website_name);
        return await attachCertificateFile(credential);
      }

      let usernameField = null;
      let passwordField = null;

      if (credential.username_field_selector) {
        usernameField = document.querySelector(credential.username_field_selector);
        if (usernameField) log('Campo usuario encontrado por selector', credential.username_field_selector);
      }
      if (!usernameField) {
        usernameField = findField(USERNAME_PATTERNS);
        if (usernameField) log('Campo usuario encontrado por patrones automáticos');
      }

      if (credential.password_field_selector) {
        passwordField = document.querySelector(credential.password_field_selector);
        if (passwordField) log('Campo contraseña encontrado por selector', credential.password_field_selector);
      }
      if (!passwordField) {
        passwordField = findField(PASSWORD_PATTERNS, usernameField ? [usernameField] : []);
        if (passwordField) log('Campo contraseña encontrado por patrones automáticos');
      }

      const hasUser = !!usernameField;
      const hasPass = !!passwordField;

      if (!hasUser && !hasPass) {
        log('No se encontró ni campo usuario ni contraseña en esta página');
        return false;
      }

      const form = (usernameField || passwordField).closest('form') || document.querySelector('form');

      // ——— Flujo: solo usuario (página pide usuario y luego "Siguiente") ———
      if (hasUser && !hasPass) {
        log('Solo campo usuario: rellenando usuario y buscando botón Siguiente');
        fillFieldAndTrigger(usernameField, credential.username, 'Usuario');
        const nextBtn = form ? findNextButton(form) : findNextButton(document.body);
        if (nextBtn) {
          log('Pulsando botón Siguiente:', nextBtn.textContent?.trim() || nextBtn.value);
          nextBtn.focus();
          nextBtn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
          nextBtn.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true }));
          nextBtn.click();
        } else {
          log('No se encontró botón Siguiente/Next; intentando submit del formulario');
          const submitBtn = form ? findSubmitButton(form) : findSubmitButton(document.body);
          if (submitBtn) {
            submitBtn.focus();
            submitBtn.click();
          } else if (form) {
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
          }
        }
        return true;
      }

      // ——— Flujo: solo contraseña (segunda página tras haber puesto usuario) ———
      if (!hasUser && hasPass) {
        log('Solo campo contraseña: rellenando contraseña y enviando');
        fillFieldAndTrigger(passwordField, credential.password, 'Contraseña');
        clickSubmitButton(form, credential, passwordField);
        return true;
      }

      // ——— Flujo: usuario y contraseña en la misma página ———
      log('Usuario y contraseña en la misma página: rellenando ambos');
      fillFieldAndTrigger(usernameField, credential.username, 'Usuario');
      fillFieldAndTrigger(passwordField, credential.password, 'Contraseña');

      clickSubmitButton(form, credential, passwordField);
      return true;
    } catch (error) {
      logError('Error al rellenar credenciales', error);
      return false;
    } finally {
      isFilling = false;
      
      // WIPE MEMORY: Destrucción de datos sensibles de la memoria RAM JIT
      if (credential) {
         credential.username = '***WIPED***';
         credential.password = '***WIPED***';
      }
      
      // Retirada del overlay si falla la navegación web (fallback tras 5 segundos)
      setTimeout(() => {
         removeSecureOverlay();
      }, 5000);
    }
  }

  async function attachCertificateFile(credential) {
    // 1. Obtener el PEM del certificado desde el background
    const certResponse = await new Promise(resolve => {
      chrome.runtime.sendMessage({ action: 'getCertificate' }, resolve);
    });

    if (!certResponse || !certResponse.success || !certResponse.certificate) {
      logError('No se pudo obtener el certificado para adjuntar');
      removeSecureOverlay();
      return false;
    }

    const pem = certResponse.certificate;

    // 2. Buscar el input[type="file"] dentro del área de subida
    let fileInput = null;
    const uploadArea = document.querySelector('#certificate-upload-area, .certificate-upload-area');
    if (uploadArea) {
      fileInput = uploadArea.querySelector('input[type="file"]');
    }
    if (!fileInput) {
      fileInput = document.querySelector('input[type="file"]');
    }

    if (!fileInput) {
      logError('No se encontró input[type="file"] en la página');
      removeSecureOverlay();
      return false;
    }

    // 3. Crear el File desde el PEM y asignarlo con DataTransfer
    const blob = new Blob([pem], { type: 'application/x-pem-file' });
    const file = new File([blob], 'certificado.pem', { type: 'application/x-pem-file' });
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    fileInput.files = dataTransfer.files;

    // 4. Disparar eventos para que la página detecte el cambio
    ['change', 'input'].forEach(eventType => {
      fileInput.dispatchEvent(new Event(eventType, { bubbles: true, cancelable: true }));
    });

    log('Certificado adjuntado al input file');

    // 5. Dar tiempo a la página para procesar el archivo y luego hacer click en el botón de submit
    await new Promise(resolve => setTimeout(resolve, 600));

    const submitBtn = document.querySelector('button.btn.btn-dark.w-100') ||
                      document.querySelector('button[type="submit"]');

    if (submitBtn && !submitBtn.disabled) {
      log('Pulsando botón de envío:', submitBtn.textContent?.trim());
      submitBtn.focus();
      submitBtn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
      submitBtn.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true }));
      submitBtn.click();
    } else {
      log('Botón de envío no encontrado o deshabilitado tras adjuntar certificado');
    }

    return true;
  }

  /**
   * Busca y pulsa el botón de envío, o envía el formulario por Enter/submit()
   */
  function clickSubmitButton(form, credential, passwordField) {
    const doClick = (btn, desc) => {
      if (!btn) return false;
      log('Pulsando botón de envío:', desc, btn.textContent?.trim() || btn.value);
      btn.focus();
      btn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
      btn.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true }));
      btn.click();
      return true;
    };

    setTimeout(() => {
      let submitButton = null;
      const root = form || document;

      if (credential.submit_button_selector) {
        submitButton = root.querySelector(credential.submit_button_selector);
        if (submitButton) {
          doClick(submitButton, 'selector configurado');
          return;
        }
      }

      submitButton = form ? findSubmitButton(form) : findSubmitButton(document);
      if (doClick(submitButton, 'detección automática')) return;

      if (form) {
        const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
        const submitted = form.dispatchEvent(submitEvent);
        if (submitted) {
          try {
            form.submit();
            log('Formulario enviado con form.submit()');
          } catch (e) {
            log('form.submit() falló, simulando Enter en contraseña');
            if (passwordField) {
              ['keydown', 'keyup', 'keypress'].forEach(type => {
                passwordField.dispatchEvent(new KeyboardEvent(type, {
                  key: 'Enter', code: 'Enter', keyCode: 13, bubbles: true, cancelable: true
                }));
              });
            }
          }
        } else {
          log('El formulario canceló el evento submit');
        }
      } else {
        const anySubmit = findSubmitButton(document);
        if (anySubmit) doClick(anySubmit, 'en documento');
        else log('No se encontró formulario ni botón de envío');
      }
    }, 300);
  }


  // Escuchar mensajes del popup para rellenar manualmente (clic en "Rellenar ahora")
  chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === 'fillNow') {
      checkAndFill(true)
        .then((filled) => {
          sendResponse({ success: filled === true });
        })
        .catch((err) => {
          logError('fillNow error', err);
          sendResponse({ success: false });
        });
      return true; // Mantener el canal abierto para respuesta asíncrona
    }
  });
})();
