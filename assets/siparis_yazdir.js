/* siparis_yazdir.js — Siparişler › "Açık Sevkiyatlar" bölümü: Yazdır + PDF Gönder (v382).
 *
 * Yalnız siparisler.php'de yüklenir (beyan_yazdir.js emsali: app.js / siparis.js'e dokunmaz). Çalışma biçimi
 * beyan_yazdir.js ile AYNIDIR; fark: veri sayfaya gömülü DEĞİL, her tıkta siparis_yazdir.php'den TAZE çekilir
 * (şoför adı/telefonu HTML listeye girmez; sayfalamasız tüm küme; ekrandaki filtre/arama aynen).
 *   • Yazdır     → yeni pencerede rapor (A4 YATAY) + yazdırma penceresi. Pencere fetch'ten ÖNCE, eşzamanlı açılır (popup engelleyici).
 *   • PDF Gönder → html2canvas + jsPDF (halkayit/ dosyaları) YALNIZ ilk basışta yüklenir; telefonda Web Share ile dosya
 *                  paylaşılır (WhatsApp), masaüstünde PDF iner.
 * Hücreler sunucudan HAM METİN gelir; kaçışlama burada yapılır (esc → "\n" → <br>).
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
  dugmeler.forEach(function (b) { if (b.getAttribute('data-sip-yazdir') === 'pdf') b.textContent = ETIKET_PDF; });

  var CSS =
    '.syz{font-family:Arial,Helvetica,sans-serif;color:#111;background:#fff;font-size:10.5px;line-height:1.3}' +
    '.syz h2{font-size:16px;margin:0 0 4px}.syz-meta,.syz-anahtar,.syz-not{font-size:10.5px;color:#333;margin:0 0 6px}' +
    '.syz table{width:100%;border-collapse:collapse;table-layout:fixed}' +
    '.syz th,.syz td{border:1px solid #999;padding:3px 4px;text-align:left;vertical-align:top;overflow-wrap:anywhere}' +
    '.syz thead th{background:#eee;font-size:9px;padding:3px 3px;overflow-wrap:normal;word-break:normal}.syz thead{display:table-header-group}' +
    '.syz tr{page-break-inside:avoid;break-inside:avoid}' +
    '.syz .syz-s{text-align:right}.syz .syz-nw{white-space:nowrap;overflow-wrap:normal}' +
    '.syz tr.syz-gecmis>td{background:#fff3b0}.syz tr.syz-bugun>td:first-child{font-weight:700}' +
    '.syz tr.syz-gun>td{border-top:2px solid #333}.syz tr.syz-toplam>td{font-weight:700;background:#f2f2f2}' +
    '.syz-kutu{display:inline-block;width:12px;height:10px;background:#fff3b0;border:1px solid #999;vertical-align:-1px;margin-right:4px}' +
    '.syz td,.syz th,.syz-kutu{-webkit-print-color-adjust:exact;print-color-adjust:exact}';
  var NOWRAP = { tarih: 1, navlun: 1, siparis: 1, tel: 1 };

  // Rapor gövdesi (başlık + meta + anahtar + tablo + notlar) — hem yazdırma penceresi hem PDF yakalama kullanır.
  function raporHtml(v) {
    var kol = v.kolonlar;
    var meta = 'Basım: <b>' + esc(v.basim) + '</b>';
    if (v.depo) meta += ' &nbsp;|&nbsp; Depo: <b>' + esc(v.depo) + '</b>';
    meta += ' &nbsp;|&nbsp; <b>' + esc(v.adet) + '</b> sevkiyat';
    if (v.toplam && v.toplam.palet !== '') meta += ' &nbsp;|&nbsp; <b>' + esc(v.toplam.palet) + '</b> palet';
    if (v.toplam && v.toplam.kasa !== '') meta += ' &nbsp;|&nbsp; <b>' + esc(v.toplam.kasa) + '</b> kasa';
    meta += ' &nbsp;|&nbsp; Geçmiş: <b>' + esc(v.gecmis_say) + '</b>';
    if (v.filtre) meta += ' &nbsp;|&nbsp; Filtre: <b>' + esc(v.filtre) + '</b>';
    var cg = kol.map(function (k) { return '<col style="width:' + Number(k.w) + '%">'; }).join('');
    var th = kol.map(function (k) { return '<th class="' + (k.t === 'sayi' ? 'syz-s' : '') + '">' + esc(k.l) + '</th>'; }).join('');
    var onceki = null;
    var tr = v.satirlar.map(function (s, ri) {
      var sinif = [];
      if (s.gecmis) sinif.push('syz-gecmis');
      if (s.bugun) sinif.push('syz-bugun');
      if (ri > 0 && s.gun !== onceki) sinif.push('syz-gun');
      onceki = s.gun;
      return '<tr class="' + sinif.join(' ') + '">' + s.h.map(function (h, i) {
        var k = kol[i];
        var cls = k.t === 'sayi' ? 'syz-s' : (NOWRAP[k.k] ? 'syz-nw' : '');
        return '<td class="' + cls + '">' + hucre(h) + '</td>';
      }).join('') + '</tr>';
    }).join('');
    // Toplam satırı tbody'nin SONUNDA (tfoot tarayıcı baskısında her sayfada tekrar eder).
    var top = '<tr class="syz-toplam">' + kol.map(function (k, i) {
      var t = i === 0 ? 'TOPLAM' : (k.k === 'palet' ? v.toplam.palet : (k.k === 'kasa' ? v.toplam.kasa : ''));
      return '<td class="' + (k.t === 'sayi' ? 'syz-s' : '') + '">' + esc(t) + '</td>';
    }).join('') + '</tr>';
    var notlar = (v.notlar || []).map(function (n) { return '<div class="syz-not">' + esc(n) + '</div>'; }).join('');
    return '<div class="syz" id="pdfIcerik">' +
      '<h2>' + esc(v.baslik) + '</h2>' +
      '<div class="syz-meta">' + meta + '</div>' +
      '<div class="syz-anahtar"><span class="syz-kutu"></span>Sarı zemin ve ✓ = çıkış tarihi geçmiş</div>' +
      '<table><colgroup>' + cg + '</colgroup><thead><tr>' + th + '</tr></thead><tbody>' + tr + top + '</tbody></table>' +
      notlar + '</div>';
  }

  function dosyaAdiOlustur(v) {
    return String(v.dosya || 'Acik Sevkiyatlar').replace(/[\\/:*?"<>|]/g, ' ').replace(/\s+/g, ' ').trim();
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

  var hazir = null;   // { dosya, ad, metin } — paylaşım hareketi reddedilirse (iOS) hazır PDF bir sonraki dokunuşta paylaşılır
  function paylas(h) { return navigator.share({ files: [h.dosya], title: h.ad, text: h.metin }); }

  async function pdfGonder(btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    try {
      if (hazir) {   // önceki denemede hazırlanıp paylaşılamayan PDF: yeni dokunuşla paylaş
        var h0 = hazir;
        await paylas(h0);
        hazir = null; btn.textContent = ETIKET_PDF;
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
            hazir = { dosya: dosya, ad: ad, metin: metin };
            btn.textContent = '📤 PDF hazır — Paylaş';
          } else { throw e; }
        }
      } else {
        indir(blob, ad);
        btn.textContent = ETIKET_PDF;
      }
    } catch (e) {
      btn.textContent = hazir ? '📤 PDF hazır — Paylaş' : ETIKET_PDF;
      // Kullanıcı paylaşım sayfasını kapatırsa (AbortError) hata değildir.
      if (!e || e.name !== 'AbortError') alert('PDF hazırlanamadı: ' + (e && e.message ? e.message : e));
    } finally {
      btn.disabled = false;
    }
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-sip-yazdir]') : null;
    if (!b || b.disabled) return;
    if (b.getAttribute('data-sip-yazdir') === 'pdf') pdfGonder(b); else yazdir(b);
  });
})();
