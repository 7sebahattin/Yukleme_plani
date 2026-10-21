/* siparis_yazdir.js — Siparişler › bölüm başlıkları: Yazdır + PDF Gönder (v382) + WP Resim.
 *
 * Yalnız siparisler.php'de yüklenir (beyan_yazdir.js emsali: app.js / siparis.js'e dokunmaz). Çalışma biçimi
 * beyan_yazdir.js ile AYNIDIR; fark: veri sayfaya gömülü DEĞİL, her tıkta siparis_yazdir.php'den TAZE çekilir
 * (şoför telefonu HTML listeye girmez; sayfalamasız tüm küme; ekrandaki filtre/arama aynen). Düğmeler birden çok bölüm
 * başlığında durur (İşlem Bekleyen / Evrak Takımı Bekleyen / Kapanan / tek-durum "Sonuçlar"); her biri kendi
 * data-sip-yazdir-url'ünü (…&bolum=acik|evrak|kapali) taşır, isteğe amac=yazdir|pdf|resim eklenir.
 *   • Yazdır     → yeni pencerede rapor (A4 YATAY) + yazdırma penceresi. Pencere fetch'ten ÖNCE, eşzamanlı açılır (popup engelleyici).
 *   • PDF Gönder → html2canvas + jsPDF (halkayit/ dosyaları) YALNIZ ilk basışta yüklenir; telefonda Web Share ile dosya
 *                  paylaşılır (WhatsApp), masaüstünde PDF iner.
 *   • WP Resim   → aynı rapor (başlık + meta + tablo + toplam + notlar) ekran dışı BEYAZ tutucuda html2canvas ile tek PNG;
 *                  telefonda Web Share, masaüstünde panoya PNG ("WhatsApp'ta yapıştırın"), pano yoksa / reddedilirse PNG iner.
 *                  Davranış siparis_wp.js ile aynı desendir (iOS NotAllowedError → "Resim hazır — Paylaş", AbortError hata değil).
 * Sütunlar SUNUCUDAN gelir (kolonlar[] — kalibre sütunlarının sayısı değişkendir); burada indeks/sütun sayısı varsayılmaz.
 * Hücreler sunucudan HAM METİN gelir; kaçışlama burada yapılır (esc → "\n" → <br>).
 * "Hazır" (izin reddi sonrası bekleyen dosya) DÜĞME BAŞINA ve tür başına ayrı tutulur (WeakMap).
 * UYARI: pdfUret() dilimleme algoritması beyan_yazdir.js ile aynıdır — birinde hata düzeltirsen ötekinde de düzelt.
 */
(function () {
  'use strict';

  var dugmeler = Array.prototype.slice.call(document.querySelectorAll('[data-sip-yazdir]'));
  if (!dugmeler.length) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function hucre(s) { return esc(s).replace(/\n/g, '<br>'); }

  // Telefon / tablet mi? (beyan_yazdir.js ile aynı ölçüt — Windows/Edge canShare=true döndürüp WhatsApp'a dosya veremediği için
  // paylaşım yalnız gerçek mobil cihazda denenir.)
  function mobilMi(w) {
    var n = (w || window).navigator;
    var ua = n.userAgent || '';
    return /Android|iPhone|iPod|iPad/i.test(ua) || (/Macintosh/.test(ua) && (n.maxTouchPoints || 0) > 1);
  }
  var mobil = mobilMi();
  var ETIKET_PDF = mobil ? '📤 PDF Gönder' : '⬇️ PDF İndir';
  var ETIKET_RESIM = '🖼️ WP Resim';
  var HAZIR_PDF = '📤 PDF hazır — Paylaş', HAZIR_RESIM = '🖼️ Resim hazır — Paylaş';
  dugmeler.forEach(function (b) { if (b.getAttribute('data-sip-yazdir') === 'pdf') b.textContent = ETIKET_PDF; });

  var CSS =
    '.syz{font-family:Arial,Helvetica,sans-serif;color:#111;background:#fff;font-size:10.5px;line-height:1.3}' +
    '.syz h2{font-size:16px;margin:0 0 4px}.syz-meta,.syz-anahtar,.syz-not{font-size:10.5px;color:#333;margin:0 0 6px}' +
    '.syz table{width:100%;border-collapse:collapse;table-layout:fixed}' +
    '.syz th,.syz td{border:1px solid #999;padding:3px 4px;text-align:left;vertical-align:top;overflow-wrap:anywhere}' +
    '.syz thead th{background:#eee;font-size:9px;padding:3px 3px;text-align:center;vertical-align:middle;white-space:normal;overflow-wrap:normal;word-break:normal;hyphens:none}.syz thead{display:table-header-group}' +
    '.syz tr{page-break-inside:avoid;break-inside:avoid}' +
    '.syz .syz-s{text-align:right}.syz .syz-nw{white-space:nowrap;overflow-wrap:normal}' +
    '.syz tr.syz-gecmis>td{background:#fff3b0}.syz tr.syz-bugun>td:first-child{font-weight:700}' +
    '.syz tr.syz-gun>td{border-top:2px solid #333}.syz tr.syz-toplam>td{font-weight:700;background:#f2f2f2}' +
    '.syz-kutu{display:inline-block;width:12px;height:10px;background:#fff3b0;border:1px solid #999;vertical-align:-1px;margin-right:4px}' +
    '.syz td,.syz th,.syz-kutu{-webkit-print-color-adjust:exact;print-color-adjust:exact}';
  var NOWRAP = { tarih: 1, siparis: 1, tel: 1 };   // plaka KIRILIR ("07BEV611-07BCD361" nowrap iken yan sütuna taşıp kesiliyordu)

  // Rapor gövdesi (başlık + meta + anahtar + tablo + notlar) — yazdırma penceresi, PDF ve WP Resim yakalaması kullanır.
  // Sütunlar v.kolonlar'dan (değişken sayıda kalibre sütunu); toplam satırı her 'sayi' sütunu için v.toplam[k.k].
  function raporHtml(v) {
    var kol = v.kolonlar || [];
    var top = v.toplam || {};
    var dolu = function (x) { return x != null && x !== ''; };
    var meta = 'Basım: <b>' + esc(v.basim) + '</b>';
    if (v.depo) meta += ' &nbsp;|&nbsp; Depo: <b>' + esc(v.depo) + '</b>';
    meta += ' &nbsp;|&nbsp; <b>' + esc(v.adet) + '</b> sevkiyat';
    if (dolu(top.palet)) meta += ' &nbsp;|&nbsp; <b>' + esc(top.palet) + '</b> palet';
    if (dolu(top.kasa)) meta += ' &nbsp;|&nbsp; <b>' + esc(top.kasa) + '</b> kasa';
    // "Geçmiş" yalnız açık iş listesinde anlamlı (kapanan/evrak bölümünde hepsi geçmiştir).
    if (!v.kapsam || v.kapsam === 'acik' || v.kapsam === 'tek') meta += ' &nbsp;|&nbsp; Geçmiş: <b>' + esc(v.gecmis_say) + '</b>';
    if (v.filtre) meta += ' &nbsp;|&nbsp; Filtre: <b>' + esc(v.filtre) + '</b>';
    var sinifi = function (k) { return k.t === 'sayi' ? 'syz-s' : (NOWRAP[k.k] ? 'syz-nw' : ''); };
    var cg = kol.map(function (k) { var w = Number(k.w); return w > 0 ? '<col style="width:' + w + '%">' : '<col>'; }).join('');
    var th = kol.map(function (k) { return '<th>' + esc(k.l) + '</th>'; }).join('');
    var onceki = null;
    var tr = (v.satirlar || []).map(function (s, ri) {
      var sinif = [];
      if (s.gecmis) sinif.push('syz-gecmis');
      if (s.bugun) sinif.push('syz-bugun');
      if (ri > 0 && s.gun !== onceki) sinif.push('syz-gun');
      onceki = s.gun;
      return '<tr class="' + sinif.join(' ') + '">' + kol.map(function (k, i) {
        var h = s.h ? s.h[i] : '';
        return '<td class="' + sinifi(k) + '">' + hucre(h) + '</td>';
      }).join('') + '</tr>';
    }).join('');
    // Toplam satırı tbody'nin SONUNDA (tfoot tarayıcı baskısında her sayfada tekrar eder).
    var toplamTr = '<tr class="syz-toplam">' + kol.map(function (k, i) {
      var t = i === 0 ? 'TOPLAM' : (k.t === 'sayi' && dolu(top[k.k]) ? top[k.k] : '');
      return '<td class="' + sinifi(k) + '">' + esc(t) + '</td>';
    }).join('') + '</tr>';
    var notlar = (v.notlar || []).map(function (n) { return '<div class="syz-not">' + esc(n) + '</div>'; }).join('');
    var anahtar = Number(v.gecmis_say) > 0 ? '<div class="syz-anahtar"><span class="syz-kutu"></span>Sarı zemin ve ✓ = çıkış tarihi geçmiş</div>' : '';
    return '<div class="syz" id="pdfIcerik">' +
      '<h2>' + esc(v.baslik) + '</h2>' +
      '<div class="syz-meta">' + meta + '</div>' + anahtar +
      '<table><colgroup>' + cg + '</colgroup><thead><tr>' + th + '</tr></thead><tbody>' + tr + toplamTr + '</tbody></table>' +
      notlar + '</div>';
  }

  function dosyaAdiOlustur(v) {
    return String(v.dosya || 'Sevkiyatlar').replace(/[\\/:*?"<>|]/g, ' ').replace(/\s+/g, ' ').trim();
  }

  // Taze veri: her tıkta sunucudan (istemcide önbellek YOK).
  function getir(btn, amac) {
    var url = btn.getAttribute('data-sip-yazdir-url') || 'siparis_yazdir.php';
    url += (url.indexOf('?') < 0 ? '?' : '&') + 'amac=' + encodeURIComponent(amac);
    return fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }).then(function (res) {
      var ct = res.headers.get('Content-Type') || '';
      if (ct.indexOf('json') < 0) {
        // 200/yönlendirme + HTML = giriş sayfası (oturum düştü); başka kod = sunucu hatası — ikisini karıştırma
        if (res.ok || res.redirected) throw new Error('Oturum sona ermiş olabilir — sayfayı yenileyin.');
        throw new Error('Sunucu beklenmeyen bir yanıt verdi (HTTP ' + res.status + ') — sayfayı yenileyip tekrar deneyin.');
      }
      return res.json().then(function (j) {
        if (!res.ok || !j || !j.ok) throw new Error((j && j.hata) || 'Liste okunamadı.');
        if (!j.satirlar || !j.satirlar.length) throw new Error('Yazdırılacak sevkiyat yok.');
        return j;
      });
    });
  }

  // ── Yazdır ─────────────────────────────────────────────────────────────────
  function yazdir(btn) {
    // Pencere fetch'ten ÖNCE ve eşzamanlı açılır; aksi halde popup engelleyici kullanıcı hareketini sayamaz.
    var w = window.open('', '_blank');
    if (!w) { alert('Yazdırma penceresi engellendi. Açılır pencere izni verin.'); return; }
    try { w.document.write('<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Hazırlanıyor…</title></head><body style="font-family:Arial,sans-serif;padding:16px">Hazırlanıyor…</body></html>'); } catch (e) { /* yok say */ }
    var etiket = btn.textContent;
    btn.disabled = true;
    getir(btn, 'yazdir').then(function (v) {
      var ad = dosyaAdiOlustur(v);
      w.document.open();
      w.document.write(
        '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<title>' + esc(ad) + '</title><style>' +
        'body{margin:0;padding:16px;background:#fff}' + CSS +
        '.syz-btn{display:flex;gap:8px;justify-content:flex-end;margin-bottom:14px}' +
        '.syz-b{padding:9px 18px;cursor:pointer;font-size:13px;font-weight:700;border:1px solid #1c4a6e;background:#1c4a6e;color:#fff;border-radius:8px}' +
        '@page{size:A4 landscape;margin:8mm}' +
        '@media print{body{padding:0}.syz-btn{display:none}}' +
        '</style></head><body>' +
        '<div class="syz-btn"><button class="syz-b" onclick="window.print()">🖨️ Yazdır</button></div>' +
        raporHtml(v) + '</body></html>');
      w.document.close();
      w.focus();
      // İçerik yerleştikten sonra yazdırma penceresini kendiliğinden aç.
      setTimeout(function () { try { w.print(); } catch (e) { /* düğmeden yeniden denenebilir */ } }, 350);
    }).catch(function (e) {
      var m = e && e.message ? e.message : String(e);
      try { w.document.open(); w.document.write('<!doctype html><meta charset="utf-8"><title>Hata</title><body style="font-family:Arial,sans-serif;padding:16px"><p>Yazdırma verisi alınamadı: ' + esc(m) + '</p></body>'); w.document.close(); } catch (e2) { /* pencere kapatılmış */ }
      alert('Yazdırma verisi alınamadı: ' + m);
    }).then(function () { btn.disabled = false; btn.textContent = etiket; });
  }

  // ── PDF Gönder ─────────────────────────────────────────────────────────────
  function betikYukle(src) {
    return new Promise(function (ok, hata) {
      var s = document.createElement('script');
      s.src = src; s.onload = ok;
      s.onerror = function () { hata(new Error(src + ' yüklenemedi')); };
      document.head.appendChild(s);
    });
  }

  // Sayfa kesimi SATIR SINIRINDA yapılır (satır ortadan bölünmez), ikinci sayfadan itibaren tablo başlığı yeniden çizilir; yatay A4.
  // Dilimleme algoritması beyan_yazdir.js ile aynıdır. Sabit 1200px tutucu (A4'te ≈ 7pt yazı; 1400px'te 6pt'a düşer) (yüzdeli sütunlar için; max-content DEĞİL).
  async function pdfUret(v) {
    if (!window.html2canvas) await betikYukle('halkayit/html2canvas.min.js');
    if (!window.jspdf) await betikYukle('halkayit/jspdf.umd.min.js');

    var tutucu = document.createElement('div');
    tutucu.setAttribute('aria-hidden', 'true');
    tutucu.style.cssText = 'position:fixed;left:-100000px;top:0;width:1200px;background:#fff;z-index:-1';
    tutucu.innerHTML = '<style>' + CSS + '</style>' + raporHtml(v);
    document.body.appendChild(tutucu);
    try {
      var el = tutucu.querySelector('#pdfIcerik');
      var olcek = 1.25;
      var kanvas = await window.html2canvas(el, { scale: olcek, backgroundColor: '#ffffff' });

      var jsPDF = window.jspdf.jsPDF;
      var pdf = new jsPDF({ orientation: 'l', unit: 'mm', format: 'a4' });
      var kenar = 8;
      var sayfaG = pdf.internal.pageSize.getWidth();
      var sayfaY = pdf.internal.pageSize.getHeight();
      var icerikG = sayfaG - kenar * 2;
      var icerikY = sayfaY - kenar * 2;
      var pxPerMm = kanvas.width / icerikG;
      var sayfaMaxPx = Math.floor(icerikY * pxPerMm);

      var kokUst = el.getBoundingClientRect().top;
      var thead = el.querySelector('thead');
      var basAlt = Math.round((thead.getBoundingClientRect().bottom - kokUst) * olcek);   // başlık satırının alt sınırı
      var basUst = Math.round((thead.getBoundingClientRect().top - kokUst) * olcek);
      var basYuk = basAlt - basUst;
      var sinirlar = [];
      el.querySelectorAll('tbody tr').forEach(function (tr) {
        sinirlar.push(Math.round((tr.getBoundingClientRect().bottom - kokUst) * olcek));
      });
      // Tablodan sonraki notlar da son dilime girsin.
      if (!sinirlar.length || sinirlar[sinirlar.length - 1] < kanvas.height) sinirlar.push(kanvas.height);

      var bas = 0, ilk = true;
      while (bas < kanvas.height) {
        var kullan = ilk ? sayfaMaxPx : sayfaMaxPx - basYuk;   // ikinci sayfadan itibaren başlık yeri ayrılır
        var son = 0;
        for (var i = 0; i < sinirlar.length; i++) { if (sinirlar[i] > bas && sinirlar[i] - bas <= kullan) son = sinirlar[i]; }
        if (son <= bas) son = Math.min(bas + kullan, kanvas.height);   // tek satır sayfadan uzunsa zorunlu kesim

        var govdeY = son - bas;
        var dilim = document.createElement('canvas');
        dilim.width = kanvas.width;
        dilim.height = govdeY + (ilk ? 0 : basYuk);
        var cx = dilim.getContext('2d');
        cx.fillStyle = '#fff'; cx.fillRect(0, 0, dilim.width, dilim.height);
        if (!ilk) cx.drawImage(kanvas, 0, basUst, kanvas.width, basYuk, 0, 0, kanvas.width, basYuk);
        cx.drawImage(kanvas, 0, bas, kanvas.width, govdeY, 0, ilk ? 0 : basYuk, kanvas.width, govdeY);

        if (!ilk) pdf.addPage();
        pdf.addImage(dilim.toDataURL('image/jpeg', 0.9), 'JPEG', kenar, kenar, icerikG, dilim.height / pxPerMm);
        ilk = false;
        bas = son;
      }
      // Sayfa numarası: dilimlemeden sonra "Sayfa i / n" (sağ alt).
      var n = pdf.getNumberOfPages();
      pdf.setFontSize(8);
      for (var p = 1; p <= n; p++) {
        pdf.setPage(p);
        pdf.text('Sayfa ' + p + ' / ' + n, sayfaG - kenar, sayfaY - 3, { align: 'right' });
      }
      return pdf.output('blob');
    } finally {
      tutucu.remove();
    }
  }

  function indir(blob, ad) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = ad + '.pdf';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  // İzin reddi (iOS) sonrası hazır dosya: DÜĞME BAŞINA (birden çok bölümde düğme var; PDF ve resim düğmeleri ayrı öğedir).
  var hazirlar = new WeakMap();   // btn → { dosya, ad, metin }
  function paylas(h) { return navigator.share(h.metin ? { files: [h.dosya], title: h.ad, text: h.metin } : { files: [h.dosya], title: h.ad }); }

  async function pdfGonder(btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    try {
      if (hazirlar.has(btn)) {   // önceki denemede hazırlanıp paylaşılamayan PDF: yeni dokunuşla paylaş
        var h0 = hazirlar.get(btn);
        await paylas(h0);
        hazirlar.delete(btn); btn.textContent = ETIKET_PDF;
        return;
      }
      btn.textContent = '⏳ PDF hazırlanıyor...';
      var v = await getir(btn, 'pdf');
      var ad = dosyaAdiOlustur(v);
      var blob = await pdfUret(v);
      var dosya = new File([blob], ad + '.pdf', { type: 'application/pdf' });
      var metin = v.baslik + ' · ' + v.adet + ' sevkiyat\n' + v.basim;
      if (mobil && navigator.canShare && navigator.canShare({ files: [dosya] })) {
        try {
          await paylas({ dosya: dosya, ad: ad, metin: metin });
          btn.textContent = ETIKET_PDF;
        } catch (e) {
          if (e && e.name === 'NotAllowedError') {
            // Hazırlık sırasında dokunma izni zaman aşımına uğradı (özellikle iOS) — PDF hazır, bir dokunuş yeter.
            hazirlar.set(btn, { dosya: dosya, ad: ad, metin: metin });
            btn.textContent = HAZIR_PDF;
          } else { throw e; }
        }
      } else {
        indir(blob, ad);
        btn.textContent = ETIKET_PDF;
      }
    } catch (e) {
      btn.textContent = hazirlar.has(btn) ? HAZIR_PDF : ETIKET_PDF;
      // Kullanıcı paylaşım sayfasını kapatırsa (AbortError) hata değildir.
      if (!e || e.name !== 'AbortError') alert('PDF hazırlanamadı: ' + (e && e.message ? e.message : e));
    } finally {
      btn.disabled = false;
    }
  }

  // ── WP Resim ───────────────────────────────────────────────────────────────
  var RESIM_W = 1400, RESIM_OLCEK = 2, RESIM_MAX_Y = 16000;
  // WP resmi telefonda küçülerek açılır: yazı PDF/yazdırmadan belirgin büyük ve kalın (ince yazı okunmuyordu).
  var RESIM_YAZI =
    '.syz-resim-tutucu .syz h2{font-size:22px}.syz-resim-tutucu .syz-meta,.syz-resim-tutucu .syz-anahtar,.syz-resim-tutucu .syz-not{font-size:14px;color:#000}' +
    '.syz-resim-tutucu .syz th,.syz-resim-tutucu .syz td{font-size:14px;font-weight:600;padding:5px 4px;border-color:#555}.syz-resim-tutucu .syz .syz-nw{white-space:normal;overflow-wrap:anywhere}' +
    '.syz-resim-tutucu .syz thead th{font-size:12.5px;font-weight:800;padding:5px 3px}.syz-resim-tutucu .syz tr.syz-toplam>td{font-weight:800}';
  async function resimUret(v) {
    if (!window.html2canvas) await betikYukle('halkayit/html2canvas.min.js');
    var tutucu = document.createElement('div');
    tutucu.className = 'syz-resim-tutucu';
    tutucu.setAttribute('aria-hidden', 'true');
    // Temadan bağımsız beyaz zemin; iç boşluk resmin kenarında nefes payı bırakır.
    tutucu.style.cssText = 'position:fixed;left:-100000px;top:0;width:' + RESIM_W + 'px;background:#fff;z-index:-1;pointer-events:none';
    tutucu.innerHTML = '<style>' + CSS + '.syz-resim-tutucu .syz{padding:16px;box-sizing:border-box;width:' + RESIM_W + 'px;font-size:14px;line-height:1.35;color:#000}' + RESIM_YAZI + '</style>' + raporHtml(v);
    document.body.appendChild(tutucu);
    try {
      if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (e) { /* yok say */ } }
      var el = tutucu.querySelector('#pdfIcerik');
      var yuk = el.getBoundingClientRect().height || 1;
      // Uzun listede kanvas yüksekliği sınırı aşmasın: ölçek düşer (en az 1).
      var olcek = Math.max(1, Math.min(RESIM_OLCEK, RESIM_MAX_Y / yuk));
      var kanvas = await window.html2canvas(el, { scale: olcek, backgroundColor: '#ffffff', logging: false });
      return await new Promise(function (ok, hata) {
        kanvas.toBlob(function (b) { if (b) ok(b); else hata(new Error('Görsel oluşturulamadı.')); }, 'image/png');
      });
    } finally { tutucu.remove(); }
  }

  // Bildirim (siparis_wp.js deseninin küçük hâli; sınıf AYRI — WP kartının #sipWpDurum'una karışmaz). z-index 650.
  var bildirim = null, bildirimMetin = null, bildirimZaman = null;
  function bildir(metin, tur) {
    if (!bildirim) {
      bildirim = document.createElement('div');
      bildirim.className = 'sip-yz-durum no-print';
      bildirim.setAttribute('role', 'status');
      bildirim.setAttribute('aria-live', 'polite');
      bildirim.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);z-index:650;' +
        'bottom:calc(var(--bn-h, 0px) + 16px + env(safe-area-inset-bottom, 0px));display:flex;align-items:center;gap:10px;' +
        'box-sizing:border-box;width:max-content;max-width:calc(100vw - 32px);padding:10px 8px 10px 14px;border-radius:12px;' +
        'background:var(--card,#fff);color:var(--text,#111);border:1px solid var(--border,#ddd);border-left:4px solid var(--primary,#1c4a6e);' +
        'box-shadow:0 8px 24px rgba(0,0,0,.25);font-size:.92rem;line-height:1.35';
      bildirimMetin = document.createElement('span');
      bildirimMetin.style.cssText = 'flex:1 1 auto;min-width:0;overflow-wrap:anywhere';
      var kapat = document.createElement('button');
      kapat.type = 'button';
      kapat.setAttribute('aria-label', 'Kapat');
      kapat.textContent = '✕';
      kapat.style.cssText = 'min-width:44px;min-height:44px;background:none;border:0;color:inherit;font-size:1.1rem;cursor:pointer;border-radius:8px';
      kapat.addEventListener('click', bildirimGizle);
      bildirim.appendChild(bildirimMetin);
      bildirim.appendChild(kapat);
      document.body.appendChild(bildirim);
    }
    if (bildirimZaman) clearTimeout(bildirimZaman);
    bildirim.style.borderLeftColor = tur === 'hata' ? 'var(--danger,#dc2626)' : (tur === 'ok' ? '#16a34a' : 'var(--primary,#1c4a6e)');
    bildirim.setAttribute('data-tur', tur || 'bilgi');
    bildirimMetin.textContent = metin;
    bildirim.hidden = false;
    bildirim.style.display = 'flex';
    bildirimZaman = setTimeout(bildirimGizle, tur === 'hata' ? 7000 : 4500);
  }
  function bildirimGizle() {
    if (bildirimZaman) { clearTimeout(bildirimZaman); bildirimZaman = null; }
    if (bildirim) { bildirim.hidden = true; bildirim.style.display = 'none'; }
  }

  function pngIndir(blob, ad) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = ad + '.png';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }
  function panoVar() { return !!(window.isSecureContext && navigator.clipboard && navigator.clipboard.write && window.ClipboardItem); }
  function panoyaYaz(deger) { return navigator.clipboard.write([new window.ClipboardItem({ 'image/png': deger })]); }
  var PANO_OK = "✅ Resim panoya kopyalandı — WhatsApp'ta yapıştırın (Ctrl+V)";
  var PNG_INDI = "⬇️ Resim (PNG) indirildi — WhatsApp'a ekleyin.";

  async function resimGonder(btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    try {
      if (hazirlar.has(btn)) {   // izin reddinden kalan hazır resim: bu dokunuşla paylaş
        await paylas(hazirlar.get(btn));
        hazirlar.delete(btn); btn.textContent = ETIKET_RESIM;
        return;
      }
      btn.textContent = '⏳ Resim hazırlanıyor...';
      var uretim = getir(btn, 'resim').then(function (v) { return resimUret(v).then(function (blob) { return { v: v, blob: blob, ad: dosyaAdiOlustur(v) }; }); });
      uretim.catch(function () { /* aşağıda işlenir */ });
      if (!mobil && panoVar()) {
        // Pano yazımı kullanıcı hareketinin İÇİNDE, eşzamanlı başlatılır (Safari şartı); değer Promise<Blob>.
        var panoSoz = null;
        try { panoSoz = panoyaYaz(uretim.then(function (r) { return r.blob; })); panoSoz.catch(function () {}); }
        catch (e) { panoSoz = null; }      // Promise değeri kabul etmeyen motor → blob hazır olunca yeniden denenir
        var r = await uretim;
        try {
          if (panoSoz) await panoSoz; else await panoyaYaz(r.blob);
          bildir(PANO_OK, 'ok');
        } catch (e) {
          pngIndir(r.blob, r.ad);   // pano reddedildi → PNG iner
          bildir(PNG_INDI, 'ok');
        }
        btn.textContent = ETIKET_RESIM;
        return;
      }
      var r2 = await uretim;
      var h = { dosya: new File([r2.blob], r2.ad + '.png', { type: 'image/png' }), ad: r2.ad };
      if (mobil && navigator.share && navigator.canShare && navigator.canShare({ files: [h.dosya] })) {
        try {
          await paylas(h);
          btn.textContent = ETIKET_RESIM;
        } catch (e) {
          if (e && e.name === 'NotAllowedError') {
            // Hazırlık sırasında dokunma izni zaman aşımına uğradı (iOS) — resim hazır, bir dokunuş yeter.
            hazirlar.set(btn, h);
            btn.textContent = HAZIR_RESIM;
          } else { throw e; }
        }
        return;
      }
      pngIndir(r2.blob, r2.ad);
      bildir(PNG_INDI, 'ok');
      btn.textContent = ETIKET_RESIM;
    } catch (e) {
      btn.textContent = hazirlar.has(btn) ? HAZIR_RESIM : ETIKET_RESIM;
      // Kullanıcı paylaşım sayfasını kapatırsa (AbortError) hata değildir.
      if (!e || e.name !== 'AbortError') bildir('Resim hazırlanamadı: ' + (e && e.message ? e.message : e), 'hata');
    } finally {
      btn.disabled = false;
    }
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-sip-yazdir]') : null;
    if (!b || b.disabled) return;
    var tur = b.getAttribute('data-sip-yazdir');
    if (tur === 'pdf') pdfGonder(b); else if (tur === 'resim') resimGonder(b); else yazdir(b);
  });
})();
