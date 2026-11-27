// =========================================================
// service-worker.js — Asya WhatsApp Web Bridge (Manifest V3)
//
// Tüm Asya API isteklerini arka plan service worker üzerinden yönetir.
// Eklenti ikonu tıklandığında Chrome sağ yan panelini (Side Panel) açar.
// Content script hiçbir zaman doğrudan harici ağ isteği yapmaz.
// =========================================================
'use strict';

const DEFAULT_API_BASE = 'https://asya.scai.tr';
const ALLOWED_BASE_HOSTS = new Set(['asya.scai.tr']);
const STORAGE_KEYS = {
  TOKEN: 'asya_bridge_token',
  USER: 'asya_bridge_user',
  DEVICE_LABEL: 'asya_bridge_device_label',
  SCHEMA_CACHE: 'asya_bridge_schema_cache',
  PENDING_PARSE: 'asya_bridge_pending_parse'
};

// Eklenti ikonuna tıklayınca sağ tarafta sabit Side Panel açılsın
if (chrome.sidePanel && chrome.sidePanel.setPanelBehavior) {
  chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
}

chrome.runtime.onInstalled.addListener(() => {
  if (chrome.sidePanel && chrome.sidePanel.setPanelBehavior) {
    chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
  }
});

function getApiUrl(action) {
  const base = DEFAULT_API_BASE.replace(/\/+$/, '');
  const parsed = new URL(base);
  if (parsed.protocol !== 'https:' || !ALLOWED_BASE_HOSTS.has(parsed.hostname)) {
    throw new Error('İzin verilmeyen sunucu adresi.');
  }
  return `${base}/api_chrome_whatsapp.php?action=${encodeURIComponent(action)}`;
}

async function getStoredAuth() {
  const data = await chrome.storage.local.get([
    STORAGE_KEYS.TOKEN,
    STORAGE_KEYS.USER,
    STORAGE_KEYS.DEVICE_LABEL
  ]);
  return {
    token: data[STORAGE_KEYS.TOKEN] || '',
    user: data[STORAGE_KEYS.USER] || null,
    deviceLabel: data[STORAGE_KEYS.DEVICE_LABEL] || ''
  };
}

async function clearStoredAuth() {
  await chrome.storage.local.remove([
    STORAGE_KEYS.TOKEN,
    STORAGE_KEYS.USER,
    STORAGE_KEYS.DEVICE_LABEL,
    STORAGE_KEYS.SCHEMA_CACHE
  ]);
}

async function apiRequest(action, method = 'GET', payload = null, requireAuth = true) {
  const url = getApiUrl(action);
  const headers = { Accept: 'application/json' };

  if (requireAuth) {
    const auth = await getStoredAuth();
    if (!auth.token) {
      return { ok: false, status: 401, error: 'Eklenti henüz Asya hesabıyla eşleştirilmemiş.' };
    }
    headers.Authorization = `Bearer ${auth.token}`;
  }

  const init = { method, headers, cache: 'no-store' };
  if (method === 'POST') {
    headers['Content-Type'] = 'application/json; charset=utf-8';
    init.body = JSON.stringify(payload || {});
  }

  let response;
  try {
    response = await fetch(url, init);
  } catch (err) {
    return {
      ok: false,
      status: 0,
      error: 'Asya sunucusuna ulaşılamadı. İnternet bağlantınızı kontrol edin.'
    };
  }

  let json = null;
  try {
    json = await response.json();
  } catch (_) {
    json = { ok: false, error: `Sunucudan geçersiz yanıt alındı (HTTP ${response.status}).` };
  }

  if (response.status === 401 && requireAuth) {
    await clearStoredAuth();
  }

  return Object.assign({ status: response.status }, json);
}

async function handleMessage(msg, sender) {
  if (!msg || typeof msg.type !== 'string') {
    return { ok: false, error: 'Geçersiz istek.' };
  }

  switch (msg.type) {
    case 'GET_AUTH_STATE': {
      const auth = await getStoredAuth();
      if (!auth.token) {
        return { ok: true, connected: false, baseUrl: DEFAULT_API_BASE };
      }
      const res = await apiRequest('status', 'GET', null, true);
      if (!res.ok) {
        return { ok: true, connected: false, baseUrl: DEFAULT_API_BASE, error: res.error || '' };
      }
      return {
        ok: true,
        connected: true,
        baseUrl: DEFAULT_API_BASE,
        user: res.user,
        permissions: res.permissions,
        ai: res.ai,
        schema_version: res.schema_version,
        device_label: res.device_label
      };
    }

    case 'PAIR': {
      const code = String(msg.code || '').trim();
      const deviceLabel = String(msg.device_label || 'Chrome Eklentisi').trim();
      if (!code) {
        return { ok: false, error: 'Eşleştirme kodu boş olamaz.' };
      }
      const res = await apiRequest('pair', 'POST', { code, device_label: deviceLabel }, false);
      if (!res.ok || !res.token) {
        return { ok: false, error: res.error || 'Eşleştirme başarısız.' };
      }
      await chrome.storage.local.set({
        [STORAGE_KEYS.TOKEN]: res.token,
        [STORAGE_KEYS.USER]: res.user || null,
        [STORAGE_KEYS.DEVICE_LABEL]: res.device_label || deviceLabel
      });
      return { ok: true, connected: true, user: res.user, device_label: res.device_label };
    }

    case 'DISCONNECT': {
      await apiRequest('disconnect', 'POST', {}, true);
      await clearStoredAuth();
      return { ok: true, connected: false };
    }

    case 'GET_SCHEMA': {
      const res = await apiRequest('schema', 'GET', null, true);
      if (res.ok && res.schema) {
        await chrome.storage.local.set({ [STORAGE_KEYS.SCHEMA_CACHE]: res.schema });
      }
      return res;
    }

    case 'CHECK_DUPLICATES': {
      return await apiRequest('check_duplicates', 'POST', {
        chat_name: msg.chat_name || '',
        messages: Array.isArray(msg.messages) ? msg.messages : []
      }, true);
    }

    case 'PARSE_MESSAGE': {
      return await apiRequest('parse', 'POST', {
        chat_name: msg.chat_name || '',
        chat_type: msg.chat_type || 'unknown',
        source_message_id: msg.source_message_id || '',
        sender_name: msg.sender_name || '',
        message_timestamp: msg.message_timestamp || '',
        raw_text: msg.raw_text || '',
        force_reparse: Boolean(msg.force_reparse)
      }, true);
    }

    case 'CREATE_DRAFT': {
      return await apiRequest('create_draft', 'POST', {
        bridge_message_id: msg.bridge_message_id || null,
        fields: msg.fields || {},
        confirm: msg.confirm === true
      }, true);
    }

    case 'GET_RECENT': {
      return await apiRequest('recent', 'GET', null, true);
    }

    case 'OPEN_SIDE_PANEL_AND_PARSE': {
      const pending = {
        ts: Date.now(),
        chat_name: msg.chat_name || '',
        chat_type: msg.chat_type || 'unknown',
        item: msg.item || null
      };
      await chrome.storage.local.set({ [STORAGE_KEYS.PENDING_PARSE]: pending });
      if (chrome.sidePanel && chrome.sidePanel.open && sender && sender.tab && sender.tab.id) {
        try {
          await chrome.sidePanel.open({ tabId: sender.tab.id });
        } catch (_) {
          // Panel zaten açıksa veya kullanıcı jesti süresi geçtiyse aşağıda runtime mesajı yine iletir
        }
      }
      chrome.runtime.sendMessage({ type: 'TRIGGER_PARSE_FROM_PAGE', pending }).catch(() => {});
      return { ok: true };
    }

    case 'CONSUME_PENDING_PARSE': {
      const data = await chrome.storage.local.get([STORAGE_KEYS.PENDING_PARSE]);
      const pending = data[STORAGE_KEYS.PENDING_PARSE] || null;
      await chrome.storage.local.remove([STORAGE_KEYS.PENDING_PARSE]);
      // 60 saniyeden eski bekleyen istekleri yok say
      if (!pending || (Date.now() - (pending.ts || 0)) > 60000) {
        return { ok: true, pending: null };
      }
      return { ok: true, pending };
    }

    default:
      return { ok: false, error: `Bilinmeyen işlem: ${msg.type}` };
  }
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  handleMessage(msg, sender)
    .then((res) => sendResponse(res))
    .catch((err) => sendResponse({ ok: false, error: err && err.message ? err.message : 'Beklenmeyen hata.' }));
  return true;
});
