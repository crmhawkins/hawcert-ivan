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

// Caché temporal de credenciales seguras (indexadas por tab.id)
const credentialCache = new Map();

// Flag de flujo dos pasos por pestaña (persiste cross-origin a diferencia de sessionStorage)
const twoStepPending = new Map();

// Escuchar mensajes del content script
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  // Manejar mensajes de forma asíncrona
  if (request.action === 'getCredentials') {
    getCredentials(request.url, request.manual === true)
      .then(credentials => {
        sendResponse({ success: true, credentials });
      })
      .catch(error => {
        sendResponse({ success: false, error: error.message });
      });
    return true; // Mantener el canal abierto para respuesta asíncrona
  }

  // Pre-comprobar credenciales guardándolas en el background y enviando solo metadatos al content.js
  if (request.action === 'checkCredentials') {
    getCredentials(request.url, false)
      .then(credentials => {
        const tabId = sender.tab ? sender.tab.id : 'unknown';

        if (credentials && credentials.mode === 'multiple') {
          // Modo múltiple: guardar lista + url en cache
          credentialCache.set(tabId, {
            mode: 'multiple',
            url: request.url,
            list: credentials.credentials,
          });
          sendResponse({
            success: true,
            multipleAccounts: credentials.credentials,
            websiteName: credentials.credentials[0]?.website_name || 'esta web',
            certificateOnly: false,
            certificateFile: false,
          });
        } else {
          // Modo single (comportamiento actual)
          credentialCache.set(tabId, credentials);
          sendResponse({
            success: true,
            websiteName: credentials.website_name,
            certificateOnly: credentials.certificate_only === true,
            certificateFile: credentials.certificate_file === true,
          });
        }
      })
      .catch(error => {
        sendResponse({ success: false, error: error.message });
      });
    return true;
  }

  // Recupera las verdaderas credenciales cacheadas (Just-In-Time)
  if (request.action === 'retrieveCachedCredentials') {
    const tabId = sender.tab ? sender.tab.id : 'unknown';
    const cached = credentialCache.get(tabId);

    if (!cached) {
      sendResponse({ success: false, error: 'Credenciales expiradas o no encontradas en caché segura' });
      return true;
    }

    if (cached.mode === 'multiple') {
      const credentialId = request.credentialId;
      if (!credentialId) {
        sendResponse({ success: false, error: 'Se requiere seleccionar una cuenta' });
        return true;
      }
      credentialCache.delete(tabId);
      getCredentials(cached.url, false, credentialId)
        .then(credential => sendResponse({ success: true, credentials: credential }))
        .catch(err => sendResponse({ success: false, error: err.message }));
      return true;
    }

    // Si hay un flujo dos pasos pendiente, NO borrar el caché todavía:
    // la página de contraseña necesitará recuperarlo de nuevo.
    // Se borra en el segundo retrieve o tras 60 segundos.
    if (twoStepPending.get(tabId)) {
      // Segunda recuperación: borrar caché y flag
      twoStepPending.delete(tabId);
      credentialCache.delete(tabId);
    } else {
      // Primera recuperación: programar auto-borrado por seguridad (60s)
      setTimeout(() => credentialCache.delete(tabId), 60000);
    }
    sendResponse({ success: true, credentials: cached });
    return true;
  }

  // Marca la pestaña como en flujo dos pasos (cross-origin safe)
  if (request.action === 'setTwoStepPending') {
    const tabId = sender.tab ? sender.tab.id : 'unknown';
    twoStepPending.set(tabId, true);
    // Auto-limpiar tras 60 segundos por seguridad
    setTimeout(() => twoStepPending.delete(tabId), 60000);
    sendResponse({ ok: true });
    return true;
  }

  // Comprueba y limpia el flag de flujo dos pasos
  if (request.action === 'checkAndClearTwoStepPending') {
    const tabId = sender.tab ? sender.tab.id : 'unknown';
    const pending = twoStepPending.get(tabId) === true;
    sendResponse({ pending });
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
 * @param {boolean} manual - true si el usuario pulsó "Rellenar ahora" (solo entonces el servidor registra el uso en logs)
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
          headers: {
            'Content-Type': 'application/json',
          },
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
