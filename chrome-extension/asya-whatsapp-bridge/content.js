// =========================================================
// content.js — Asya WhatsApp Web Bridge (DOM-Only Reader)
//
// Yalnızca açık olan WhatsApp Web sayfasında kullanıcıya görüntülenen
// ve DOM üzerinden erişilebilen metin mesajlarını okur.
//
// KESİN SINIRLAR:
//   • WhatsApp gizli API'lerine, React/webpack iç yapılarına, IndexedDB'ye,
//     localStorage'a, çerezlere veya WebSocket'e ASLA dokunmaz.
//   • Otomatik mesaj göndermez, silmez veya kullanıcı adına işlem yapmaz.
//   • Yalnızca kullanıcı popup üzerinden "BU GRUBU TARA" dediğinde çalışır.
//   • Ekranda yüklenmemiş eski mesajları okuyabildiğini iddia etmez.
// =========================================================
'use strict';

(function (globalScope) {
  const SYSTEM_PATTERNS = [
    /uçtan uca şifrelidir/i,
    /end-to-end encrypted/i,
    /bu mesaj silindi/i,
    /this message was deleted/i,
    /gruba eklendi|gruptan ayrıldı|grubu oluşturdu|yönetici yaptı|konuyu değiştirdi/i,
    /added you|left the group|created group|changed the subject/i,
    /güvenlik kodunuz değişti|security code changed/i
  ];

  const DATE_DIVIDER_PATTERN = /^(bugün|dün|today|yesterday|pazartesi|salı|çarşamba|perşembe|cuma|cumartesi|pazar|\d{1,2}[./-]\d{1,2}[./-]\d{2,4})$/i;

  function normalizeTr(str) {
    return String(str || '')
      .replace(/\u00a0|\u200b|\ufeff/g, ' ')
      .replace(/İ/g, 'i')
      .replace(/I/g, 'ı')
      .toLowerCase()
      .replace(/\s+/g, ' ')
      .trim();
  }

  function isSystemOrDateText(text) {
    const clean = normalizeTr(text);
    if (!clean) return true;
    if (DATE_DIVIDER_PATTERN.test(clean)) return true;
    return SYSTEM_PATTERNS.some((rx) => rx.test(clean));
  }

  /**
   * Modüler beyan adayı tespiti (Aşama 1: beyan_v1).
   * Sabit tek kalıba bağlı kalmadan, "yeni beyan" başlangıç sinyallerini önceliklendirir
   * ve alan yoğunluğu ile sıradan sohbet mesajlarını eler.
   */
  function scoreBeyanCandidate(rawText) {
    const text = String(rawText || '').trim();
    if (!text || isSystemOrDateText(text)) {
      return { isCandidate: false, score: 0, reasons: [] };
    }

    const norm = normalizeTr(text);
    const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    let score = 0;
    const reasons = [];

    // 1. Öncelikli güçlü sinyaller ("yeni beyan", "beyan açılacak", vb.)
    if (/\byeni\s+beyan\b/i.test(norm)) {
      score += 60;
      reasons.push('"Yeni beyan" başlangıç ifadesi');
    } else if (/\bbeyan(?:name)?\b/i.test(norm) && lines.length >= 2) {
      score += 35;
      reasons.push('Beyan ifadesi');
    }

    // 2. Gerçek Yeni Beyan alan sinyalleri
    const domainSignals = [
      { rx: /\bparti(?:\s*no)?\b/i, pts: 18, label: 'Parti No' },
      { rx: /\b(?:br[uü]t|b[uü]r[uü]t|gross)\b/i, pts: 18, label: 'Brüt KG' },
      { rx: /\bnet(?:\s*kg)?\b/i, pts: 16, label: 'Net KG' },
      { rx: /\bpalet\b/i, pts: 15, label: 'Palet' },
      { rx: /\b(?:kasa|sand[ıi]k|plastik|karton)\b/i, pts: 14, label: 'Kasa/Ambalaj' },
      { rx: /\b(?:al[ıi]c[ıi]|buyer)\b/i, pts: 14, label: 'Alıcı' },
      { rx: /\b(?:[çc][ıi]k[ıi][şs]|depo)\b/i, pts: 12, label: 'Çıkış Depo' },
      { rx: /\b(?:g[öo]nderici|ihracat[çc][ıi])\b/i, pts: 14, label: 'Gönderici' },
      { rx: /\b(?:denizyolu|karayolu|havayolu|ro-ro|konteyner|dap|cif|fob)\b/i, pts: 12, label: 'Nakliye/Hat' },
      { rx: /\b(?:g[üu]mr[üu]k|m[üu][şs]avir|ilgili)\b/i, pts: 10, label: 'Gümrük/İlgili' },
      { rx: /\b(?:[üu]r[üu]n|mahsul|marka|ambalaj|[çc]e[şs]it)\b/i, pts: 10, label: 'Ürün/Marka' },
      { rx: /\b(?:plaka|dorse|[çc]ekici|[şs]of[öo]r)\b/i, pts: 10, label: 'Araç/Şoför' },
      { rx: /\b(?:sipari[şs]|numune)\b/i, pts: 8, label: 'Sipariş/Numune' }
    ];

    let matchedDomainCount = 0;
    for (const sig of domainSignals) {
      if (sig.rx.test(norm)) {
        score += sig.pts;
        matchedDomainCount++;
        reasons.push(sig.label);
      }
    }

    // Çok satırlı yapılandırılmış bilgi ve sayısal değer bonusu
    const hasNumbers = /\d{2,}/.test(norm);
    if (lines.length >= 3 && hasNumbers && matchedDomainCount >= 2) {
      score += 12;
    }

    // Tek satırlık kısa sohbet / soru elemesi
    if (lines.length === 1 && norm.length < 35 && !/\byeni\s+beyan\b/i.test(norm)) {
      return { isCandidate: false, score: 0, reasons: [] };
    }

    // Eşik: ya açık "yeni beyan" + en az 1 alan/sayı, ya da en az 3 farklı beyan alan sinyali (>=45 puan)
    const isCandidate =
      (/\byeni\s+beyan\b/i.test(norm) && (matchedDomainCount >= 1 || hasNumbers || lines.length >= 2)) ||
      (matchedDomainCount >= 3 && score >= 45);

    return {
      isCandidate,
      score: Math.min(100, score),
      reasons: reasons.slice(0, 6)
    };
  }

  /**
   * Aynı göndericiden hemen ardından gelen ve açık devam sinyali taşıyan tekil devam mesajlarını
   * (ör. ek palet/kasa/sipariş satırı) yalnızca yeterli bağlam varsa ilişkilendirir.
   * Birbiriyle ilgisiz mesajları ASLA birleştirmez.
   */
  function isLikelyContinuation(prevMsg, nextMsg) {
    if (!prevMsg || !nextMsg) return false;
    if (!prevMsg.sender_name || !nextMsg.sender_name) return false;
    if (normalizeTr(prevMsg.sender_name) !== normalizeTr(nextMsg.sender_name)) return false;

    const nextText = String(nextMsg.raw_text || '').trim();
    const normNext = normalizeTr(nextText);
    if (!normNext || normNext.length > 400) return false;

    // Yeni bir beyan başlıyorsa kesinlikle birleştirme!
    if (/\byeni\s+beyan\b/i.test(normNext)) return false;

    // Devam mesajı başka bir parti numarası taşıyorsa ayrı beyandır; birleştirme!
    if (/\bparti(?:\s*no)?\s*[:\-]?\s*\d/i.test(normNext)) return false;

    // Açık devam/ekleme sinyali (ek palet/kasa/sipariş/plaka/düzeltme)
    const contSignal = /\b(ek|devam|ilave|sipari[şs]|plaka|dorse|[çc]ekici|[şs]of[öo]r|numune|palet|br[üu]t|b[uü]r[uü]t|net|kasa)\b/i;
    if (!contSignal.test(normNext)) return false;

    // Sayısal veri içermeyen sıradan yorumları birleştirme
    if (!/\d/.test(normNext)) return false;

    return true;
  }

  function detectBeyanCandidates(messages) {
    const candidates = [];
    let idx = 0;

    while (idx < messages.length) {
      const msg = messages[idx];
      const evalRes = scoreBeyanCandidate(msg.raw_text);
      if (!evalRes.isCandidate) {
        idx++;
        continue;
      }

      let combinedText = msg.raw_text;
      let mergedCount = 1;
      const mergedIds = msg.source_message_id ? [msg.source_message_id] : [];

      // Yalnızca hemen sonraki 1-2 mesaj açık devam niteliğindeyse ilişkilendir
      while (idx + mergedCount < messages.length && mergedCount < 3) {
        const nextMsg = messages[idx + mergedCount];
        const nextEval = scoreBeyanCandidate(nextMsg.raw_text);
        if (nextEval.isCandidate) break;
        if (!isLikelyContinuation(msg, nextMsg)) break;
        combinedText += '\n' + nextMsg.raw_text;
        if (nextMsg.source_message_id) mergedIds.push(nextMsg.source_message_id);
        mergedCount++;
      }

      candidates.push({
        client_id: msg.client_id,
        source_message_id: mergedIds.join('|') || msg.source_message_id || '',
        sender_name: msg.sender_name,
        message_timestamp: msg.message_timestamp,
        raw_text: combinedText,
        score: evalRes.score,
        reasons: evalRes.reasons,
        merged_count: mergedCount
      });

      idx += mergedCount;
    }

    return candidates;
  }

  // ── DOM Okuma (Yalnızca Görünür WhatsApp Web DOM'u) ───────

  function parsePrePlainText(attr) {
    // Örn: "[14:32, 26.11.2026] Ahmet Yılmaz: " veya "[2:32 PM, 11/26/2026] +90 555 ...: "
    const s = String(attr || '').trim();
    const m = s.match(/^\[([^\]]+)\]\s*(.+?):\s*$/);
    if (!m) return { timestamp: '', sender: '' };
    return {
      timestamp: m[1].trim(),
      sender: m[2].trim()
    };
  }

  function extractActiveChatInfo(doc) {
    const main = doc.querySelector('#main');
    if (!main) {
      return { open: false, chat_name: '', chat_type: 'unknown' };
    }
    const header = main.querySelector('header');
    let chatName = '';
    let chatType = 'unknown';

    if (header) {
      const titleEl =
        header.querySelector('span[dir="auto"][title]') ||
        header.querySelector('[data-testid="conversation-info-header-chat-title"]') ||
        header.querySelector('span[dir="auto"]');
      if (titleEl) {
        chatName = (titleEl.getAttribute('title') || titleEl.textContent || '').trim();
      }
      // Grup tespiti: başlık altında virgüllü katılımcı listesi veya "kişi bilgisi için tıklayın" ipucu
      const headerSpans = Array.from(header.querySelectorAll('span[dir="auto"], span[title]'));
      for (const sp of headerSpans) {
        const t = (sp.getAttribute('title') || sp.textContent || '').trim();
        if (!t || t === chatName) continue;
        if (t.includes(',') || /grup|group|katılımcı|member/i.test(t)) {
          chatType = 'group';
          break;
        }
        if (/son görülme|last seen|çevrimiçi|online|yazıyor|typing/i.test(t)) {
          chatType = 'direct';
          break;
        }
      }
    }

    return { open: true, chat_name: chatName, chat_type: chatType };
  }

  function extractVisibleMessages(doc, limit) {
    const maxCount = Math.max(10, Math.min(300, Number(limit) || 50));
    const main = doc.querySelector('#main');
    if (!main) return { messages: [], detectedGroupFromSenders: false };

    // WhatsApp Web mesaj satırları: data-id taşıyan veya message-in/message-out sınıflı düğümler
    let nodes = Array.from(main.querySelectorAll('div[data-id]'));
    if (!nodes.length) {
      nodes = Array.from(main.querySelectorAll('.message-in, .message-out'));
    }

    const parsed = [];
    const seenSenders = new Set();

    for (let i = 0; i < nodes.length; i++) {
      const node = nodes[i];
      // Metin kopyalanabilir alanı
      const copyable = node.querySelector('[data-pre-plain-text]');
      const textEls = Array.from(node.querySelectorAll('span.selectable-text, div.selectable-text'));
      if (!textEls.length) continue;

      // Alıntılanan (quoted) metni ayıklamak için asıl mesaj bloğundaki selectable-text'i al
      let rawText = '';
      if (copyable) {
        const innerTexts = Array.from(copyable.querySelectorAll('span.selectable-text'));
        rawText = innerTexts.map((el) => el.innerText || el.textContent || '').join('\n').trim();
      }
      if (!rawText) {
        rawText = textEls
          .map((el) => el.innerText || el.textContent || '')
          .join('\n')
          .trim();
      }
      if (!rawText || isSystemOrDateText(rawText)) continue;

      const preMeta = copyable ? parsePrePlainText(copyable.getAttribute('data-pre-plain-text')) : { timestamp: '', sender: '' };

      // Gönderen adı (grup mesajlarında ek olarak üstte görünür)
      let senderName = preMeta.sender;
      if (!senderName) {
        const authorEl = node.querySelector('span[aria-label][dir="auto"], span._ahxt');
        if (authorEl) senderName = (authorEl.textContent || '').trim();
      }
      if (senderName) seenSenders.add(normalizeTr(senderName));

      // Zaman damgası
      let msgTs = preMeta.timestamp;
      if (!msgTs) {
        const timeEl = node.querySelector('[data-testid="msg-meta"] span, span[dir="auto"]:last-child');
        const candidateTs = timeEl ? (timeEl.textContent || '').trim() : '';
        if (/^\d{1,2}:\d{2}/.test(candidateTs)) msgTs = candidateTs;
      }

      const sourceMsgId = (node.getAttribute('data-id') || '').trim();
      const clientId = sourceMsgId || `dom_${i}_${rawText.length}`;

      parsed.push({
        client_id: clientId,
        source_message_id: sourceMsgId,
        sender_name: senderName || 'Bilinmeyen',
        message_timestamp: msgTs,
        raw_text: rawText
      });
    }

    const sliced = parsed.slice(-maxCount);
    return {
      messages: sliced,
      total_loaded: parsed.length,
      detectedGroupFromSenders: seenSenders.size >= 2
    };
  }

  function scanActiveChat(doc, limit) {
    const chatInfo = extractActiveChatInfo(doc);
    if (!chatInfo.open) {
      return {
        ok: false,
        error: 'Açık bir WhatsApp sohbeti bulunamadı. Lütfen önce bir WhatsApp grubunu açın.'
      };
    }

    const extracted = extractVisibleMessages(doc, limit);
    let chatType = chatInfo.chat_type;
    if (chatType === 'unknown' && extracted.detectedGroupFromSenders) {
      chatType = 'group';
    }

    const candidates = detectBeyanCandidates(extracted.messages);

    return {
      ok: true,
      chat_name: chatInfo.chat_name || 'Adsız Sohbet',
      chat_type: chatType,
      limit: Number(limit) || 50,
      scanned_count: extracted.messages.length,
      total_loaded_in_dom: extracted.total_loaded,
      candidates,
      all_messages: extracted.messages
    };
  }

  // Chrome runtime mesaj dinleyici (yalnız tarayıcı eklenti ortamında)
  if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.onMessage) {
    chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
      if (!msg || msg.type !== 'SCAN_CHAT') return false;
      try {
        const result = scanActiveChat(document, msg.limit || 50);
        sendResponse(result);
      } catch (err) {
        sendResponse({
          ok: false,
          error: 'Sohbet taranırken hata oluştu: ' + (err && err.message ? err.message : String(err))
        });
      }
      return true;
    });
  }

  // Node.js / Smoke testleri için salt fonksiyon dışa aktarımı
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
      normalizeTr,
      isSystemOrDateText,
      scoreBeyanCandidate,
      isLikelyContinuation,
      detectBeyanCandidates,
      parsePrePlainText,
      extractActiveChatInfo,
      extractVisibleMessages,
      scanActiveChat
    };
  } else if (globalScope) {
    globalScope.__AsyaBridgeContent = {
      scoreBeyanCandidate,
      detectBeyanCandidates,
      scanActiveChat
    };
  }
})(typeof globalThis !== 'undefined' ? globalThis : window);
