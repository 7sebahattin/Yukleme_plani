/* siparis_wp.js — Siparişler › ⋮ menüsü › "💬 WP Paylaş" (v394). Yalnız siparisler.php'de yüklenir; app.js / siparis.js /
 * siparis_yazdir.js'e dokunmaz. Tıkta siparis_paylas.php'den TAZE veri (şoför bilgisi liste HTML'inde yok) → ekran dışı,
 * temadan bağımsız BEYAZ kart → html2canvas (halkayit/, yalnız ilk basışta) → PNG.
 *   • Telefon: Web Share (PNG dosyası → WhatsApp). Dokunma izni zaman aşımı (iOS NotAllowedError) → "📤 Paylaş" bildirimi.
 *   • Masaüstü: panoya PNG (ClipboardItem) → "Panoya kopyalandı — WhatsApp'ta yapıştırın". Pano yoksa / reddedilirse PNG indir.
 * esc / mobilMi / betikYukle siparis_yazdir.js'teki küçük kopyalardır (bilinçli; beyan_yazdir.js emsali).
 */
(function () {
  'use strict';

  var KART_W = 440, OLCEK = 2;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function mobilMi(w) {
    var n = (w || window).navigator;
    var ua = n.userAgent || '';
    return /Android|iPhone|iPod|iPad/i.test(ua) || (/Macintosh/.test(ua) && (n.maxTouchPoints || 0) > 1);
  }
  var mobil = mobilMi();
  function betikYukle(src) {
    return new Promise(function (ok, hata) {
      var s = document.createElement('script');
      s.src = src; s.onload = ok;
      s.onerror = function () { hata(new Error(src + ' yüklenemedi')); };
      document.head.appendChild(s);
    });
  }

  // Kart stili: sayfa temasından BAĞIMSIZ, her renk açık değer (koyu temada global th/td kuralları sızmasın).
  // Başlık = th (700), veri = td (300). gap / inset / grid YOK (html2canvas).
  var CSS =
    '.swp-kart{box-sizing:border-box;width:440px;margin:0;padding:18px 20px 16px;background:#ffffff;color:#111827;' +
    'font-family:-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:16px;line-height:1.35;' +
    'border-top:6px solid #b45309;text-align:left;-webkit-font-smoothing:antialiased}' +
    '.swp-kart table{width:100%;border-collapse:collapse;table-layout:fixed;margin:0;background:#ffffff}' +
    '.swp-kart th,.swp-kart td{box-sizing:border-box;padding:6px 0;border:0;border-bottom:1px solid #e5e7eb;background:#ffffff;' +
    'text-align:left;vertical-align:top;text-transform:none;letter-spacing:normal;overflow-wrap:anywhere;word-break:break-word;white-space:normal}' +
    '.swp-kart th{font-weight:700;color:#111827;font-size:15px}' +
    '.swp-kart td{font-weight:300;color:#374151;font-size:16px}' +
    '.swp-alan th{width:150px;padding-right:12px}' +
    '.swp-kalem{margin-top:14px!important}' +
    '.swp-kalem th,.swp-kalem td{padding-right:8px}' +
    '.swp-kalem thead th{border-bottom:2px solid #111827}' +
    '.swp-kalem .swp-k2{width:70px}' +
    '.swp-kalem .swp-k3{width:104px;text-align:right;padding-right:0;white-space:nowrap}' +
    '.swp-kalem tr.swp-toplam th,.swp-kalem tr.swp-toplam td{border-top:2px solid #111827;border-bottom:0}';

  // Her değer esc() ile HTML'e girer (sunucu ham metin döner).
  function kartHtml(v) {
    var o = '<div class="swp-kart"><table class="swp-alan"><tbody>';
    v.alanlar.forEach(function (f) {
      o += '<tr data-k="' + esc(f.k) + '"><th>' + esc(f.etiket) + '</th><td>' + esc(f.deger) + '</td></tr>';
    });
    o += '</tbody></table><table class="swp-kalem"><thead><tr><th>' + esc(v.kalem_basliklar[0]) + '</th><th class="swp-k2">' +
      esc(v.kalem_basliklar[1]) + '</th><th class="swp-k3">' + esc(v.kalem_basliklar[2]) + '</th></tr></thead><tbody>';
    v.kalemler.forEach(function (k) {
      o += '<tr><td>' + esc(k.urun) + '</td><td class="swp-k2">' + esc(k.kalibre) + '</td><td class="swp-k3">' + esc(k.palet_koli) + '</td></tr>';
    });
    o += '<tr class="swp-toplam"><th>' + esc(v.toplam.etiket) + '</th><td class="swp-k2"></td><td class="swp-k3">' + esc(v.toplam.palet_koli) + '</td></tr>';
    o += '</tbody></table></div>';
    return o;
  }

  // Taze veri (önbellek YOK). Hata metinleri siparis_yazdir.js getir() ile aynı ayrım.
  function getir(id) {
    return fetch('siparis_paylas.php?id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (res) {
        var ct = res.headers.get('Content-Type') || '';
        if (ct.indexOf('json') < 0) {
          if (res.ok || res.redirected) throw new Error('Oturum sona ermiş olabilir — sayfayı yenileyin.');
          throw new Error('Sunucu beklenmeyen bir yanıt verdi (HTTP ' + res.status + ') — sayfayı yenileyip tekrar deneyin.');
        }
        return res.json().then(function (j) {
          if (!res.ok || !j || !j.ok || !Array.isArray(j.alanlar)) throw new Error((j && j.hata) || 'Sevkiyat bilgisi okunamadı.');
          return j;
        });
      });
  }

  async function pngUret(v) {
    if (!window.html2canvas) await betikYukle('halkayit/html2canvas.min.js');
    var tutucu = document.createElement('div');
    tutucu.className = 'swp-tutucu';
    tutucu.setAttribute('aria-hidden', 'true');
    tutucu.style.cssText = 'position:fixed;left:-100000px;top:0;width:' + KART_W + 'px;background:#fff;z-index:-1;pointer-events:none';
    tutucu.innerHTML = '<style>' + CSS + '</style>' + kartHtml(v);
    document.body.appendChild(tutucu);
    try {
      if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (e) { /* yok say */ } }
      var kanvas = await window.html2canvas(tutucu.querySelector('.swp-kart'), { scale: OLCEK, backgroundColor: '#ffffff', logging: false });
      return await new Promise(function (ok, hata) {
        kanvas.toBlob(function (b) { if (b) ok(b); else hata(new Error('Görsel oluşturulamadı.')); }, 'image/png');
      });
    } finally { tutucu.remove(); }
  }

  // ── bildirim (#sipWpDurum): menü tıkta kapandığı için durum/eylem burada gösterilir ──
  // durum(metin, tur, eylemler): tur = 'bilgi' | 'ok' | 'uyari' | 'hata'; eylemler = [{ etiket, fn }]
  //   Eylemsiz ok → 4 sn, eylemsiz hata → 7 sn sonra gizlenir; eylemli bildirim kullanıcı kapatana / eylem bitene kadar kalır.
  var kutu = null, kutuMetin = null, kutuEylem = null, zamanlayici = null;
  function kutuHazirla() {
    if (kutu) return;
    kutu = document.createElement('div');
    kutu.id = 'sipWpDurum';
    kutu.className = 'sip-wp-durum';
    kutu.setAttribute('role', 'status');
    kutu.setAttribute('aria-live', 'polite');
    kutu.hidden = true;
    kutuMetin = document.createElement('span');
    kutuMetin.className = 'sip-wp-durum-metin';
    kutuEylem = document.createElement('span');
    kutuEylem.className = 'sip-wp-durum-eylem';
    var kapat = document.createElement('button');
    kapat.type = 'button';
    kapat.className = 'sip-wp-durum-kapat';
    kapat.setAttribute('aria-label', 'Kapat');
    kapat.textContent = '✕';
    kapat.addEventListener('click', durumGizle);
    kutu.appendChild(kutuMetin);
    kutu.appendChild(kutuEylem);
    kutu.appendChild(kapat);
    (document.querySelector('.sip-mod') || document.body).appendChild(kutu);
  }
  function durum(metin, tur, eylemler) {
    kutuHazirla();
    if (zamanlayici) { clearTimeout(zamanlayici); zamanlayici = null; }
    tur = tur || 'bilgi';
    kutu.className = 'sip-wp-durum' + (tur === 'bilgi' ? '' : ' sip-wp-durum--' + tur);
    kutuMetin.textContent = metin;
    kutuEylem.textContent = '';
    (eylemler || []).forEach(function (e, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn' + (i === 0 ? ' btn-primary' : '');
      b.textContent = e.etiket;
      b.addEventListener('click', function () {
        Array.prototype.forEach.call(kutuEylem.querySelectorAll('button'), function (x) { x.disabled = true; });
        var s;
        try { s = e.fn(); } catch (x) { s = null; }
        Promise.resolve(s).catch(function () { /* fn kendi bildirimini yazar */ });
      });
      kutuEylem.appendChild(b);
    });
    kutu.hidden = false;
    if (!eylemler || !eylemler.length) {
      if (tur === 'ok') zamanlayici = setTimeout(durumGizle, 4000);
      else if (tur === 'hata') zamanlayici = setTimeout(durumGizle, 7000);
    }
  }
  function durumGizle() {
    if (zamanlayici) { clearTimeout(zamanlayici); zamanlayici = null; }
    if (kutu) kutu.hidden = true;
  }

  function indir(blob, ad) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = ad + '.png';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }
  function panoVar() { return !!(window.isSecureContext && navigator.clipboard && navigator.clipboard.write && window.ClipboardItem); }
  function panoyaYaz(deger) { return navigator.clipboard.write([new window.ClipboardItem({ 'image/png': deger })]); }
  function paylas(h) { return navigator.share({ files: [h.dosya], title: h.ad }); }

  var PANO_OK = "✅ Panoya kopyalandı — WhatsApp'ta yapıştırın (Ctrl+V)";
  var INDI = "⬇️ PNG indirildi — WhatsApp'a ekleyin.";
  var mesgul = false;   // çift tıklama koruması (aynı anda tek kart)

  async function wpPaylas(id) {
    if (mesgul) return;
    mesgul = true;
    durum('⏳ Sevkiyat kartı hazırlanıyor…', 'bilgi');
    var uretim = getir(id).then(function (v) { return pngUret(v).then(function (blob) { return { v: v, blob: blob }; }); });
    uretim.catch(function () { /* aşağıda işlenir */ });
    try {
      if (!mobil && panoVar()) {
        // Pano yazımı kullanıcı hareketinin İÇİNDE, eşzamanlı başlatılır (Safari şartı); değer Promise<Blob>.
        var panoSoz = null;
        try { panoSoz = panoyaYaz(uretim.then(function (r) { return r.blob; })); panoSoz.catch(function () {}); }
        catch (e) { panoSoz = null; }      // Promise değeri kabul etmeyen motor → blob hazır olunca yeniden denenir
        var r = await uretim;
        try {
          if (panoSoz) await panoSoz; else await panoyaYaz(r.blob);
          durum(PANO_OK, 'ok');
        } catch (e) {
          durum('Görsel hazır — panoya kopyalanamadı.', 'uyari', [
            { etiket: '📋 Kopyala', fn: function () {
              return panoyaYaz(r.blob).then(function () { durum(PANO_OK, 'ok'); }, function () { indir(r.blob, r.v.dosya); durum(INDI, 'ok'); });
            } },
            { etiket: '⬇️ PNG İndir', fn: function () { indir(r.blob, r.v.dosya); durum(INDI, 'ok'); } }
          ]);
        }
        return;
      }
      var r2 = await uretim;
      var h = { dosya: new File([r2.blob], r2.v.dosya + '.png', { type: 'image/png' }), ad: r2.v.dosya };
      if (mobil && navigator.share && navigator.canShare && navigator.canShare({ files: [h.dosya] })) {
        try { await paylas(h); durumGizle(); }
        catch (e) {
          if (e && e.name === 'NotAllowedError') {
            // Hazırlık sırasında dokunma izni zaman aşımına uğradı (iOS) — görsel hazır, bir dokunuş yeter.
            durum('Görsel hazır.', 'uyari', [{ etiket: '📤 Paylaş', fn: function () {
              return paylas(h).then(durumGizle, function (e2) {
                if (e2 && e2.name === 'AbortError') durumGizle(); else durum('Paylaşılamadı: ' + (e2 && e2.message ? e2.message : e2), 'hata');
              });
            } }]);
          } else if (e && e.name === 'AbortError') { durumGizle(); }
          else { throw e; }
        }
        return;
      }
      indir(r2.blob, r2.v.dosya);
      durum(INDI, 'ok');
    } catch (e) {
      if (e && e.name === 'AbortError') { durumGizle(); return; }
      durum('Paylaşılamadı: ' + (e && e.message ? e.message : e), 'hata');
    } finally {
      mesgul = false;
    }
  }

  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-sip-wp]') : null;
    if (!b) return;
    e.preventDefault();
    var id = parseInt(b.getAttribute('data-sip-wp'), 10);
    if (id > 0) wpPaylas(id);
  });
})();
