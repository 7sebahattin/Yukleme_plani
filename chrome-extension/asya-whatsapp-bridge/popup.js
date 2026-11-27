// =========================================================
// popup.js — Asya WhatsApp Web Bridge (Manifest V3)
//
// Kullanıcı akışı:
//   1. Eşleştirme durumu kontrolü (service-worker üzerinden)
//   2. "BU GRUBU TARA" → aktif WhatsApp Web sekmesindeki görünür DOM mesajlarını okur
//   3. Mükerrer ön kontrolü + beyan adayı listesi
//   4. Seçilen mesajı mevcut Asya AI servisine gönderip gerçek Yeni Beyan alanlarıyla eşleştirir
//   5. İki bölümlü inceleme ekranı (A. Kaynak Mesaj / B. Beyan Bilgileri)
//   6. Kullanıcı onayı sonrası mevcut beyan altyapısında taslak oluşturur
// =========================================================
'use strict';

const state = {
  connected: false,
  baseUrl: 'https://asya.scai.tr',
  user: null,
  permissions: null,
  ai: null,
  schema: null,
  scanResult: null,
  duplicates: {},
  activeReview: null
};

const el = {
  connDot: document.getElementById('connDot'),
  connSummary: document.getElementById('connSummary'),
  btnDisconnect: document.getElementById('btnDisconnect'),
  globalAlert: document.getElementById('globalAlert'),
  viewPair: document.getElementById('viewPair'),
  pairCode: document.getElementById('pairCode'),
  pairDevice: document.getElementById('pairDevice'),
  btnPair: document.getElementById('btnPair'),
  viewScan: document.getElementById('viewScan'),
  scanLimit: document.getElementById('scanLimit'),
  btnScan: document.getElementById('btnScan'),
  chatMeta: document.getElementById('chatMeta'),
  listToolbar: document.getElementById('listToolbar'),
  candidateSummary: document.getElementById('candidateSummary'),
  chkShowAll: document.getElementById('chkShowAll'),
  messageList: document.getElementById('messageList'),
  viewReview: document.getElementById('viewReview'),
  reviewSub: document.getElementById('reviewSub'),
  linkOpenInAsya: document.getElementById('linkOpenInAsya'),
  btnBackToList: document.getElementById('btnBackToList'),
  reviewAlerts: document.getElementById('reviewAlerts'),
  reviewSourceMeta: document.getElementById('reviewSourceMeta'),
  reviewSourceText: document.getElementById('reviewSourceText'),
  reviewAiExplain: document.getElementById('reviewAiExplain'),
  reviewForm: document.getElementById('reviewForm'),
  reviewFieldsContainer: document.getElementById('reviewFieldsContainer'),
  btnApproveDraft: document.getElementById('btnApproveDraft')
};

function sendBg(payload) {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage(payload, (res) => {
      if (chrome.runtime.lastError) {
        resolve({ ok: false, error: chrome.runtime.lastError.message });
      } else {
        resolve(res || { ok: false, error: 'Boş yanıt.' });
      }
    });
  });
}

function showAlert(kind, text) {
  if (!text) {
    el.globalAlert.hidden = true;
    el.globalAlert.textContent = '';
    return;
  }
  el.globalAlert.className = `alert ${kind}`;
  el.globalAlert.textContent = text;
  el.globalAlert.hidden = false;
}

function escapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function setView(name) {
  el.viewPair.hidden = name !== 'pair';
  el.viewScan.hidden = name !== 'scan';
  el.viewReview.hidden = name !== 'review';
}

async function initAuth() {
  showAlert('', '');
  const res = await sendBg({ type: 'GET_AUTH_STATE' });
  state.baseUrl = res.baseUrl || 'https://asya.scai.tr';

  if (res.connected) {
    state.connected = true;
    state.user = res.user;
    state.permissions = res.permissions;
    state.ai = res.ai;
    el.connDot.classList.add('ok');
    const disp = (res.user && (res.user.display_name || res.user.username)) || 'Bağlı';
    const aiLabel = res.ai && res.ai.provider ? ` · AI: ${res.ai.provider}` : '';
    el.connSummary.textContent = `Bağlı: ${disp}${aiLabel}`;
    el.btnDisconnect.hidden = false;
    setView('scan');

    const schemaRes = await sendBg({ type: 'GET_SCHEMA' });
    if (schemaRes.ok && schemaRes.schema) {
      state.schema = schemaRes.schema;
    }

    // Sayfa içi "AI ile Ayrıştır" tıklamasıyla açıldıysa doğrudan onu ayrıştır
    const pendRes = await sendBg({ type: 'CONSUME_PENDING_PARSE' });
    if (pendRes.ok && pendRes.pending && pendRes.pending.item) {
      await handleParseFromPage(pendRes.pending);
    } else {
      // WhatsApp Web sekmesindeysek otomatik tara ve mesajların yanına butonları yerleştir
      const waTab = await getActiveWhatsAppTab();
      if (waTab) {
        await handleScan(true);
      }
    }
  } else {
    state.connected = false;
    el.connDot.classList.remove('ok');
    el.connSummary.textContent = 'Eşleştirilmedi';
    el.btnDisconnect.hidden = true;
    setView('pair');
    if (res.error) showAlert('warn', res.error);
  }
}

async function handlePair() {
  const code = el.pairCode.value.trim();
  const deviceLabel = el.pairDevice.value.trim() || 'Chrome Eklentisi';
  if (!code) {
    showAlert('err', 'Lütfen Asya ekranından aldığınız eşleştirme kodunu girin.');
    return;
  }
  el.btnPair.disabled = true;
  el.btnPair.textContent = 'Bağlanıyor…';
  showAlert('', '');
  const res = await sendBg({ type: 'PAIR', code, device_label: deviceLabel });
  el.btnPair.disabled = false;
  el.btnPair.textContent = 'Bağlan';

  if (!res.ok) {
    showAlert('err', res.error || 'Eşleştirme başarısız.');
    return;
  }
  el.pairCode.value = '';
  showAlert('ok', 'Asya hesabıyla eşleştirme başarılı.');
  await initAuth();
}

async function handleDisconnect() {
  el.btnDisconnect.disabled = true;
  await sendBg({ type: 'DISCONNECT' });
  el.btnDisconnect.disabled = false;
  state.scanResult = null;
  state.activeReview = null;
  await initAuth();
  showAlert('ok', 'Eklenti bağlantısı kesildi.');
}

async function getActiveWhatsAppTab() {
  const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
  const tab = tabs && tabs[0];
  if (!tab || !tab.id || !tab.url || !tab.url.startsWith('https://web.whatsapp.com/')) {
    return null;
  }
  return tab;
}

async function syncInlineButtonsOnTab() {
  if (!state.scanResult) return;
  const tab = await getActiveWhatsAppTab();
  if (!tab) return;
  try {
    await chrome.tabs.sendMessage(tab.id, {
      type: 'RENDER_INLINE_BUTTONS',
      candidates: state.scanResult.candidates || [],
      duplicates: state.duplicates || {},
      baseUrl: state.baseUrl
    });
  } catch (_) {}
}

async function handleScan(silentOnNoChat = false) {
  showAlert('', '');
  const tab = await getActiveWhatsAppTab();
  if (!tab) {
    if (!silentOnNoChat) {
      showAlert('err', 'Lütfen önce Chrome\'da https://web.whatsapp.com sekmesini ve ilgili grubu açın.');
    }
    return;
  }

  const limit = Number(el.scanLimit.value) || 50;
  el.btnScan.disabled = true;
  el.btnScan.textContent = 'Taranıyor…';

  let scanRes;
  try {
    scanRes = await chrome.tabs.sendMessage(tab.id, {
      type: 'SCAN_CHAT',
      limit,
      baseUrl: state.baseUrl
    });
  } catch (err) {
    el.btnScan.disabled = false;
    el.btnScan.textContent = 'BU GRUBU TARA';
    if (!silentOnNoChat) {
      showAlert('err', 'WhatsApp Web sayfasına bağlanılamadı. WhatsApp Web sekmesini yenileyip tekrar deneyin.');
    }
    return;
  }

  el.btnScan.disabled = false;
  el.btnScan.textContent = 'BU GRUBU TARA';

  if (!scanRes || !scanRes.ok) {
    if (!silentOnNoChat) {
      showAlert('err', (scanRes && scanRes.error) || 'Sohbet okunamadı.');
    }
    return;
  }

  state.scanResult = scanRes;
  state.duplicates = {};

  // Mükerrer ön kontrolü (AI çağrısı yapmadan)
  const checkList = (scanRes.candidates && scanRes.candidates.length ? scanRes.candidates : scanRes.all_messages || []).slice(0, 60);
  if (checkList.length) {
    const dupRes = await sendBg({
      type: 'CHECK_DUPLICATES',
      chat_name: scanRes.chat_name,
      messages: checkList
    });
    if (dupRes.ok && dupRes.duplicates) {
      state.duplicates = dupRes.duplicates;
    }
  }

  // WhatsApp Web mesajlarının yanındaki butonları güncel mükerrer bilgisiyle çiz
  await syncInlineButtonsOnTab();
  renderScanResults();
}

function renderScanResults() {
  const sr = state.scanResult;
  if (!sr) return;

  const typeLabel = sr.chat_type === 'group' ? 'Grup' : (sr.chat_type === 'direct' ? 'Bireysel Sohbet' : 'Sohbet');
  el.chatMeta.innerHTML =
    `<strong>${escapeHtml(sr.chat_name)}</strong> (${escapeHtml(typeLabel)}) — ` +
    `Yüklenmiş son <strong>${sr.scanned_count}</strong> metin mesajı tarandı, ` +
    `<strong>${sr.candidates.length}</strong> beyan adayı bulundu.`;
  el.chatMeta.hidden = false;

  el.listToolbar.hidden = false;
  el.candidateSummary.textContent = el.chkShowAll.checked
    ? `Tüm metin mesajları (${sr.all_messages.length})`
    : `Beyan adayları (${sr.candidates.length})`;

  const items = el.chkShowAll.checked ? sr.all_messages : sr.candidates;
  el.messageList.innerHTML = '';

  if (!items.length) {
    el.messageList.innerHTML =
      '<div class="card muted">Bu taramada beyan niteliğinde mesaj bulunamadı. Eski mesajlar için WhatsApp\'ta yukarı kaydırıp tekrar tarayın veya "Tüm metin mesajlarını göster" seçeneğini açın.</div>';
    return;
  }

  // En yeni mesaj üstte görünsün
  const ordered = items.slice().reverse();
  for (const item of ordered) {
    const dup = state.duplicates[item.client_id] || null;
    const isCand = typeof item.score === 'number';
    const card = document.createElement('div');
    card.className = 'msg-card' + (dup ? ' is-duplicate' : (isCand ? ' is-candidate' : ''));

    const badges = [];
    if (isCand) badges.push(`<span class="badge badge-green">Beyan Adayı · %${item.score}</span>`);
    if (item.merged_count && item.merged_count > 1) badges.push(`<span class="badge badge-amber">${item.merged_count} mesaj ilişkili</span>`);
    if (dup) {
      const dTxt = dup.declaration_id ? `Aktarıldı (#${dup.declaration_id})` : 'Daha Önce Aktarıldı';
      badges.push(`<span class="badge badge-gray">${escapeHtml(dTxt)}</span>`);
    }

    card.innerHTML = `
      <div class="msg-head">
        <div>
          <span class="msg-sender">${escapeHtml(item.sender_name || 'Bilinmeyen')}</span>
          <span class="muted"> · ${escapeHtml(item.message_timestamp || '')}</span>
        </div>
        <div class="msg-badges">${badges.join('')}</div>
      </div>
      <div class="msg-body">${escapeHtml(item.raw_text)}</div>
      <div class="msg-foot">
        <span class="muted small">${isCand && item.reasons ? escapeHtml(item.reasons.join(', ')) : 'Manuel seçim'}</span>
        <div>
          ${dup && dup.declaration_id
            ? `<a href="${escapeHtml(state.baseUrl)}/beyan_view.php?id=${Number(dup.declaration_id)}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-xs">Beyanı Aç</a>`
            : `<button type="button" class="btn btn-secondary btn-xs" data-parse-id="${escapeHtml(item.client_id)}">${dup ? 'İncelemeyi Aç' : 'AI ile Ayrıştır'}</button>`
          }
        </div>
      </div>
    `;

    const btn = card.querySelector('[data-parse-id]');
    if (btn) {
      btn.addEventListener('click', () => handleParseItem(item, btn));
    }
    el.messageList.appendChild(card);
  }
}

async function handleParseItem(item, buttonEl = null, chatOverride = null) {
  showAlert('', '');
  if (!state.schema) {
    const sRes = await sendBg({ type: 'GET_SCHEMA' });
    if (sRes.ok && sRes.schema) state.schema = sRes.schema;
  }

  let oldLabel = '';
  if (buttonEl) {
    buttonEl.disabled = true;
    oldLabel = buttonEl.textContent;
    buttonEl.textContent = 'AI ayrıştırıyor…';
  } else {
    showAlert('ok', 'Seçilen WhatsApp mesajı yapay zekâ ile ayrıştırılıyor…');
  }

  const chatName = (chatOverride && chatOverride.chat_name) || (state.scanResult ? state.scanResult.chat_name : '');
  const chatType = (chatOverride && chatOverride.chat_type) || (state.scanResult ? state.scanResult.chat_type : 'unknown');

  const res = await sendBg({
    type: 'PARSE_MESSAGE',
    chat_name: chatName,
    chat_type: chatType,
    source_message_id: item.source_message_id || '',
    sender_name: item.sender_name || '',
    message_timestamp: item.message_timestamp || '',
    raw_text: item.raw_text || ''
  });

  if (buttonEl) {
    buttonEl.disabled = false;
    buttonEl.textContent = oldLabel;
  }

  if (!res.ok) {
    showAlert('err', res.error || 'Yapay zekâ ayrıştırması başarısız oldu.');
    return;
  }

  showAlert('', '');

  if (res.duplicate && res.declaration_id) {
    showAlert('warn', res.message || `Bu mesaj için zaten #${res.declaration_id} numaralı beyan oluşturulmuş.`);
    return;
  }

  state.activeReview = {
    client_id: item.client_id || '',
    bridge_message_id: res.bridge_message_id,
    duplicate: Boolean(res.duplicate),
    duplicate_message: res.message || '',
    ai_provider: res.ai_provider || '',
    ai_model: res.ai_model || '',
    ai_result: res.ai_result || {},
    duplicate_reference_warnings: res.duplicate_reference_warnings || [],
    source: res.source || {
      chat_name: chatName,
      chat_type: chatType,
      sender_name: item.sender_name || '',
      message_timestamp: item.message_timestamp || '',
      raw_text: item.raw_text || ''
    }
  };

  resetApproveButton();
  renderReviewScreen();
  setView('review');
}

async function handleParseFromPage(pending) {
  if (!pending || !pending.item) return;
  if (!state.connected) {
    showAlert('warn', 'Önce eklentiyi Asya hesabınızla eşleştirin.');
    setView('pair');
    return;
  }
  await handleParseItem(pending.item, null, {
    chat_name: pending.chat_name || '',
    chat_type: pending.chat_type || 'unknown'
  });
}

function renderReviewScreen() {
  const rev = state.activeReview;
  if (!rev) return;
  const ai = rev.ai_result || {};
  const formValues = ai.form_fields || {};
  const uncertainSet = new Set(Array.isArray(ai.uncertain_fields) ? ai.uncertain_fields : []);
  const conflicts = Array.isArray(ai.conflicts) ? ai.conflicts : [];
  const missingReq = Array.isArray(ai.missing_required) ? ai.missing_required : [];
  const refWarnings = Array.isArray(rev.duplicate_reference_warnings) ? rev.duplicate_reference_warnings : [];

  el.reviewSub.textContent = `AI Güven: %${ai.confidence ?? 0}` + (rev.ai_provider ? ` · ${rev.ai_provider}` : '');
  if (rev.bridge_message_id) {
    el.linkOpenInAsya.href = `${state.baseUrl}/whatsapp_beyan_aktar.php?incele=${Number(rev.bridge_message_id)}`;
    el.linkOpenInAsya.hidden = false;
  } else {
    el.linkOpenInAsya.hidden = true;
  }

  const alertsHtml = [];
  if (rev.duplicate && rev.duplicate_message) {
    alertsHtml.push(`<div class="alert warn">${escapeHtml(rev.duplicate_message)}</div>`);
  }
  if (ai.is_beyan === false) {
    alertsHtml.push('<div class="alert warn"><strong>Uyarı:</strong> Yapay zekâ bu mesajı beyan bildirimi olarak değerlendirmedi. Yine de kontrol edip manuel düzenleyebilirsiniz.</div>');
  }
  if (refWarnings.length) {
    const items = refWarnings.map((w) => `#${w.id} (Parti: ${escapeHtml(w.party_no)}, ${escapeHtml(w.status)})`).join(', ');
    alertsHtml.push(`<div class="alert warn"><strong>Aynı Parti / Referans Uyarısı:</strong> Sistemde aynı parti numarasıyla kayıt bulunuyor: ${items}. Mevcut kayıt otomatik değiştirilmez.</div>`);
  }
  if (conflicts.length) {
    alertsHtml.push(`<div class="alert warn"><strong>Çelişki Tespiti:</strong> ${conflicts.map(escapeHtml).join(' | ')}</div>`);
  }
  if (missingReq.length) {
    alertsHtml.push(`<div class="alert warn"><strong>Eksik Zorunlu Alanlar:</strong> ${missingReq.map(escapeHtml).join(', ')}</div>`);
  }
  el.reviewAlerts.innerHTML = alertsHtml.join('');

  el.reviewSourceMeta.innerHTML = `
    <div><strong>Grup:</strong> ${escapeHtml(rev.source.chat_name || '—')}</div>
    <div><strong>Gönderen:</strong> ${escapeHtml(rev.source.sender_name || '—')}</div>
    <div><strong>Tarih/Saat:</strong> ${escapeHtml(rev.source.message_timestamp || '—')}</div>
  `;
  el.reviewSourceText.textContent = rev.source.raw_text || '';

  if (ai.explanation) {
    el.reviewAiExplain.innerHTML = `<strong>AI Özeti:</strong> ${escapeHtml(ai.explanation)}`;
    el.reviewAiExplain.hidden = false;
  } else {
    el.reviewAiExplain.hidden = true;
  }

  // Gerçek Yeni Beyan şemasından form alanlarını üret
  const schema = state.schema;
  el.reviewFieldsContainer.innerHTML = '';
  if (!schema || !Array.isArray(schema.fields)) {
    el.reviewFieldsContainer.innerHTML = '<div class="alert err">Yeni Beyan form şeması yüklenemedi.</div>';
    return;
  }

  const perms = schema.permissions || {};

  // HKS firmaları datalist
  const dl = document.createElement('datalist');
  dl.id = 'extHksFirmalar';
  (schema.hks_firmalar || []).forEach((f) => {
    const opt = document.createElement('option');
    opt.value = f.ad;
    dl.appendChild(opt);
  });
  el.reviewFieldsContainer.appendChild(dl);

  const sections = schema.sections || {};
  for (const [secKey, secTitle] of Object.entries(sections)) {
    const secFields = schema.fields.filter((f) => f.section === secKey);
    if (!secFields.length) continue;

    const visibleFields = secFields.filter((f) => {
      if (!f.permission_group) return true;
      return perms[`${f.permission_group}_read`] !== false;
    });
    if (!visibleFields.length) continue;

    const heading = document.createElement('div');
    heading.className = 'sec-title';
    heading.textContent = secTitle;
    el.reviewFieldsContainer.appendChild(heading);

    const grid = document.createElement('div');
    grid.className = 'fields-grid';

    for (const fld of visibleFields) {
      const name = fld.name;
      const isUnc = uncertainSet.has(name);
      const val = formValues[name] != null ? String(formValues[name]) : (fld.default || '');
      const disabled =
        (fld.permission_group && perms[`${fld.permission_group}_write`] === false) ||
        (name === 'status' && perms.status === false);

      const wrap = document.createElement('div');
      wrap.className = 'field-item' + (fld.type === 'textarea' ? ' full' : '') + (isUnc ? ' is-uncertain' : '');

      const lbl = document.createElement('label');
      lbl.htmlFor = `rf_${name}`;
      lbl.innerHTML = `${escapeHtml(fld.label)}${fld.required ? ' *' : ''}${isUnc ? ' <span class="badge badge-amber">Kontrol</span>' : ''}`;
      wrap.appendChild(lbl);

      let input;
      if (name === 'status') {
        input = document.createElement('select');
        (fld.options || []).forEach((o) => {
          const opt = document.createElement('option');
          opt.value = o.value;
          opt.textContent = o.label;
          if (val === o.value) opt.selected = true;
          input.appendChild(opt);
        });
      } else if (name === 'hks_ulke_id') {
        input = document.createElement('select');
        input.innerHTML = '<option value="">— seçilmedi —</option>';
        (schema.hks_ulkeler || []).forEach((u) => {
          const opt = document.createElement('option');
          opt.value = u.id;
          opt.textContent = u.ad;
          if (val === String(u.id)) opt.selected = true;
          input.appendChild(opt);
        });
      } else if (name === 'hks_urun_id') {
        input = document.createElement('select');
        input.innerHTML = '<option value="">— otomatik / seçilmedi —</option>';
        (schema.hks_urunler || []).forEach((u) => {
          const opt = document.createElement('option');
          opt.value = u.id;
          opt.textContent = u.ad;
          if (val === String(u.id)) opt.selected = true;
          input.appendChild(opt);
        });
      } else if (fld.type === 'textarea') {
        input = document.createElement('textarea');
        input.rows = name === 'raw_text' ? 3 : 2;
        input.value = val;
      } else {
        input = document.createElement('input');
        input.type = fld.type === 'tel' ? 'tel' : (fld.type === 'datetime' ? 'datetime-local' : 'text');
        input.value = fld.type === 'datetime' ? val.replace(' ', 'T').slice(0, 16) : val;
        if (name === 'sender_company_name') {
          input.setAttribute('list', 'extHksFirmalar');
          input.required = true;
        }
      }

      input.id = `rf_${name}`;
      input.name = name;
      if (disabled) input.disabled = true;
      wrap.appendChild(input);
      grid.appendChild(wrap);
    }

    el.reviewFieldsContainer.appendChild(grid);
  }
}

async function handleApproveDraft(evt) {
  evt.preventDefault();
  if (!state.activeReview) return;

  const fields = {};
  const formData = new FormData(el.reviewForm);
  for (const [k, v] of formData.entries()) {
    fields[k] = String(v);
  }

  el.btnApproveDraft.disabled = true;
  el.btnApproveDraft.textContent = 'Kaydediliyor…';
  showAlert('', '');

  const res = await sendBg({
    type: 'CREATE_DRAFT',
    bridge_message_id: state.activeReview.bridge_message_id,
    fields,
    confirm: true
  });

  el.btnApproveDraft.disabled = false;
  el.btnApproveDraft.textContent = 'Onayla ve Taslak Oluştur';

  if (!res.ok) {
    const errs = Array.isArray(res.errors) ? res.errors.join(' · ') : (res.error || 'Taslak kaydedilemedi.');
    showAlert('err', errs);
    return;
  }

  const declId = Number(res.declaration_id);
  const viewUrl = `${state.baseUrl}/${res.view_url || ('beyan_view.php?id=' + declId)}`;
  el.reviewAlerts.innerHTML = `
    <div class="alert ok" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
      <strong>Beyan taslağı #${declId} oluşturuldu!</strong>
      <button type="button" class="btn btn-primary btn-xs" data-open-asya="${escapeHtml(viewUrl)}">📄 Beyana Git (#${declId})</button>
    </div>
  `;
  const openBtnTop = el.reviewAlerts.querySelector('[data-open-asya]');
  if (openBtnTop) {
    openBtnTop.addEventListener('click', () => sendBg({ type: 'OPEN_IN_ASYA_APP', url: viewUrl }));
  }

  // Alt kısımdaki onay butonunu doğrudan "Beyana Git" butonuna dönüştür (yukarı kaydırmaya gerek kalmasın)
  el.btnApproveDraft.disabled = false;
  el.btnApproveDraft.type = 'button';
  el.btnApproveDraft.textContent = `📄 Beyana Git (#${declId})`;
  el.btnApproveDraft.dataset.openAsyaUrl = viewUrl;
  el.btnApproveDraft.onclick = () => sendBg({ type: 'OPEN_IN_ASYA_APP', url: viewUrl });

  if (state.activeReview.client_id) {
    state.duplicates[state.activeReview.client_id] = {
      duplicate: true,
      bridge_message_id: state.activeReview.bridge_message_id,
      status: 'draft_created',
      declaration_id: declId
    };
    await syncInlineButtonsOnTab();
    renderScanResults();
  }
}

function resetApproveButton() {
  el.btnApproveDraft.disabled = false;
  el.btnApproveDraft.type = 'submit';
  el.btnApproveDraft.textContent = 'Onayla ve Taslak Oluştur';
  delete el.btnApproveDraft.dataset.openAsyaUrl;
  el.btnApproveDraft.onclick = null;
}

el.btnPair.addEventListener('click', handlePair);
el.btnDisconnect.addEventListener('click', handleDisconnect);
el.btnScan.addEventListener('click', () => handleScan(false));
el.chkShowAll.addEventListener('change', renderScanResults);
el.btnBackToList.addEventListener('click', () => {
  showAlert('', '');
  resetApproveButton();
  setView('scan');
});
el.reviewForm.addEventListener('submit', handleApproveDraft);

// WhatsApp Web içindeki "AI ile Ayrıştır" butonuna basıldığında canlı yakala
chrome.runtime.onMessage.addListener((msg) => {
  if (msg && msg.type === 'TRIGGER_PARSE_FROM_PAGE' && msg.pending) {
    sendBg({ type: 'CONSUME_PENDING_PARSE' }).catch(() => {});
    handleParseFromPage(msg.pending);
  }
});

initAuth();
