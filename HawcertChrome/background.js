// Background service worker para HawCert Auto-Fill

// Configuración por defecto
const DEFAULT_CONFIG = {
  apiUrl: 'https://hawcert.hawkins.es/api',
  certificate: null,
};

// Inicializar configuración
chrome.runtime.onInstalled.addListener(() => {
  chrome.storage.local.get(['config'], (result) => {
    if (!result.config) {
      chrome.storage.local.set({ config: DEFAULT_CONFIG });
    }
  });
});

// Mantener el service worker activo
chrome.runtime.onConnect.addListener((port) => {
  // Mantener conexión activa
});

// ——— Helpers para chrome.storage.session ———
// chrome.storage.session persiste aunque el service worker sea terminado por Chrome MV3.
// Esto soluciona la pérdida de credenciales en el flujo dos-pasos (user → password en otra URL).

function credKey(tabId)    { return `cred_${tabId}`; }
function twoStepKey(tabId) { return `twostep_${tabId}`; }

function sessionGet(key) {
  return new Promise(resolve => {
    chrome.storage.session.get([key], result => resolve(result[key] ?? null));
  });
}
function sessionSet(key, value) {
  return new Promise(resolve => {
    chrome.storage.session.set({ [key]: value }, resolve);
  });
}
function sessionRemove(keys) {
  return new Promise(resolve => {
    chrome.storage.session.remove(Array.isArray(keys) ? keys : [keys], resolve);
  });
}

// Tiempo máximo que las credenciales quedan en sesión (60 segundos)
const CACHE_TTL_MS = 60_000;

// Escuchar mensajes del content script
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  const tabId = sender.tab ? sender.tab.id : 'unknown';

  if (request.action === 'getCredentials') {
    getCredentials(request.url, request.manual === true)
      .then(credentials => sendResponse({ success: true, credentials }))
      .catch(error => sendResponse({ success: false, error: error.message }));
    return true;
  }

  // Pre-comprobar credenciales: guardarlas en session storage y enviar solo metadatos al content.js
  if (request.action === 'checkCredentials') {
    getCredentials(request.url, false)
      .then(async credentials => {
        if (credentials && credentials.mode === 'multiple') {
          await sessionSet(credKey(tabId), {
            mode: 'multiple',
            url: request.url,
            list: credentials.credentials,
            ts: Date.now(),
          });
          sendResponse({
            success: true,
            multipleAccounts: credentials.credentials,
            websiteName: credentials.credentials[0]?.website_name || 'esta web',
            certificateOnly: false,
            certificateFile: false,
          });
        } else {
          await sessionSet(credKey(tabId), { data: credentials, ts: Date.now() });
          sendResponse({
            success: true,
            websiteName: credentials.website_name,
            certificateOnly: credentials.certificate_only === true,
            certificateFile: credentials.certificate_file === true,
          });
        }
      })
      .catch(error => sendResponse({ success: false, error: error.message }));
    return true;
  }

  // Recupera las credenciales cacheadas (Just-In-Time)
  if (request.action === 'retrieveCachedCredentials') {
    (async () => {
      const cached = await sessionGet(credKey(tabId));

      if (!cached || (Date.now() - cached.ts > CACHE_TTL_MS)) {
        await sessionRemove(credKey(tabId));
        sendResponse({ success: false, error: 'Credenciales expiradas o no encontradas en caché segura' });
        return;
      }

      // Modo múltiple: obtener credencial específica de la API
      if (cached.mode === 'multiple') {
        const credentialId = request.credentialId;
        if (!credentialId) {
          sendResponse({ success: false, error: 'Se requiere seleccionar una cuenta' });
          return;
        }
        await sessionRemove(credKey(tabId));
        getCredentials(cached.url, false, credentialId)
          .then(credential => sendResponse({ success: true, credentials: credential }))
          .catch(err => sendResponse({ success: false, error: err.message }));
        return;
      }

      // Flujo dos pasos: si el flag está activo → segunda página (contraseña) → limpiar todo
      const twoStepFlag = await sessionGet(twoStepKey(tabId));
      if (twoStepFlag) {
        await sessionRemove([credKey(tabId), twoStepKey(tabId)]);
      }
      // En el flujo normal (sin dos pasos) o en la primera recuperación (antes de setTwoStepPending),
      // dejamos la caché intacta. Expirará por TTL o será sobreescrita en la próxima visita.

      sendResponse({ success: true, credentials: cached.data });
    })();
    return true;
  }

  // Marca la pestaña como en flujo dos pasos (persiste en session storage, cross-origin safe)
  if (request.action === 'setTwoStepPending') {
    (async () => {
      await sessionSet(twoStepKey(tabId), true);
      sendResponse({ ok: true });
    })();
    return true;
  }

  // Comprueba si hay flujo dos pasos pendiente (sin borrar el flag)
  if (request.action === 'checkAndClearTwoStepPending') {
    (async () => {
      const flag = await sessionGet(twoStepKey(tabId));
      sendResponse({ pending: flag === true });
    })();
    return true;
  }

  if (request.action === 'getConfig') {
    chrome.storage.local.get(['config'], (result) => {
      sendResponse({ config: result.config || DEFAULT_CONFIG });
    });
    return true;
  }

  if (request.action === 'showCertificateOnlyNotification') {
    showCertificateOnlyNotification(request.websiteName || 'Esta página');
    sendResponse({ ok: true });
    return true;
  }

  if (request.action === 'getCertificate') {
    chrome.storage.local.get(['config'], (result) => {
      const config = result.config || DEFAULT_CONFIG;
      if (config.certificate) {
        sendResponse({ success: true, certificate: config.certificate });
      } else {
        sendResponse({ success: false, error: 'No hay certificado configurado' });
      }
    });
    return true;
  }

  return false;
});

function showCertificateOnlyNotification(websiteName) {
  chrome.notifications.create({
    type: 'basic',
    title: 'HawCert – Solo certificado',
    message: `${websiteName} usa solo certificado. Si el navegador pide un certificado, elige tu certificado HawCert y marca "Recordar" para que se use automáticamente.`,
    priority: 1,
    requireInteraction: false,
  });
}

/**
 * Obtiene credenciales desde la API de HawCert.
 * @param {string} url - URL actual
 * @param {boolean} manual - true si el usuario pulsó "Rellenar ahora"
 * @param {string|null} credentialId - ID de credencial específica (para modo múltiple)
 */
async function getCredentials(url, manual = false, credentialId = null) {
  return new Promise((resolve, reject) => {
    chrome.storage.local.get(['config'], async (result) => {
      const config = result.config || DEFAULT_CONFIG;

      if (!config.certificate) {
        reject(new Error('No hay certificado configurado. Por favor, configura tu certificado en las opciones.'));
        return;
      }

      if (!config.apiUrl) {
        reject(new Error('No hay URL de API configurada. Por favor, configura la URL en las opciones.'));
        return;
      }

      try {
        const body = {
          certificate: config.certificate,
          url: url,
          manual: !!manual,
        };

        if (credentialId !== null) {
          body.credential_id = credentialId;
        }

        const response = await fetch(`${config.apiUrl}/get-credentials`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });

        const data = await response.json();

        if (!data.success) {
          reject(new Error(data.message || 'Error al obtener credenciales'));
          return;
        }

        if (data.mode === 'multiple') {
          resolve({ mode: 'multiple', credentials: data.credentials });
        } else {
          resolve(data.credential);
        }
      } catch (error) {
        reject(new Error(`Error de red: ${error.message}`));
      }
    });
  });
}
