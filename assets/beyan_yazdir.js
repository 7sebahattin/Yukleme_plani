/* beyan_yazdir.js — Beyanlar › "İşlem Bekleyen Beyanlar" kartı: Yazdır + PDF Gönder (v357).
 *
 * Sayfa yalnız beyanlar.php'de yüklenir (maliyet.js / hesap.js emsali: app.js'e dokunmaz).
 * Çalışma biçimi Hal Kayıt › "Bildirim Çoklu Künye Basım" ile aynıdır (halkayit/app.html
 * topluYazdirPenceresiAc / topluPdfPaylas):
 *   • Yazdır  → yeni pencerede rapor + yazdırma penceresi.
 *   • PDF Gönder → jsPDF + html2canvas SADECE ilk basışta yüklenir; telefonda Web Share API
 *     ile dosya paylaşılır (WhatsApp paylaşım listesinde açılır), masaüstünde PDF iner.
 * Veri sunucudan hazır gelir (#bekleyenYazdirVeri, beyan_bekleyen_yazdir_veri()): ekranda
 * görünen sütunlar + Şoför Adı / Telefonu. Hücreler ham metindir; kaçışlama burada yapılır.
 */
(function () {
  'use strict';

  var kap = document.getElementById('bekleyenYazdirVeri');
  var btnYazdir = document.getElementById('bekleyenYazdirBtn');
  var btnPdf = document.getElementById('bekleyenPdfBtn');
  if (!kap || !btnYazdir || !btnPdf) return;

  var veri;
  try { veri = JSON.parse(kap.textContent); } catch (e) { return; }
  if (!veri || !veri.satirlar || !veri.satirlar.length) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Telefon / tablet mi? (HKS ekranıyla aynı ölçüt — Windows/Edge canShare=true döndürüp
  // WhatsApp'a dosya veremediği için paylaşım yalnız gerçek mobil cihazda denenir.)
  function mobilMi(w) {
    var n = (w || window).navigator;
    var ua = n.userAgent || '';
    return /Android|iPhone|iPod|iPad/i.test(ua) || (/Macintosh/.test(ua) && (n.maxTouchPoints || 0) > 1);
  }

  var CSS =
    '.byz{font-family:Arial,Helvetica,sans-serif;color:#111;background:#fff;font-size:11px;line-height:1.3}' +
    '.byz h2{font-size:16px;margin:0 0 4px}' +
    '.byz .byz-meta{font-size:11px;margin-bottom:10px;color:#333}' +
    '.byz table{width:100%;border-collapse:collapse;table-layout:auto}' +
    '.byz th,.byz td{border:1px solid #999;padding:4px 6px;text-align:left;vertical-align:top}' +
    '.byz thead th{background:#eee;font-size:10.5px;white-space:nowrap}' +
    '.byz td.byz-s,.byz th.byz-s{text-align:right;white-space:nowrap}' +
    '.byz td.byz-nw{white-space:nowrap}' +
    '.byz tfoot td{font-weight:700;background:#f6f6f6}' +
    '.byz tr{page-break-inside:avoid}.byz thead{display:table-header-group}';

  // Rapor gövdesi (başlık + meta + tablo) — hem yazdırma penceresi hem PDF yakalama kullanır.
  function raporHtml() {
    var kol = veri.kolonlar;
    var meta = 'Tarih: <b>' + esc(veri.tarih) + '</b>';
    if (veri.depo) meta += ' &nbsp;|&nbsp; Depo: <b>' + esc(veri.depo) + '</b>';
    meta += ' &nbsp;|&nbsp; Beyan: <b>' + esc(veri.adet) + '</b>';
    if (veri.aranan) meta += ' &nbsp;|&nbsp; Arama: <b>' + esc(veri.aranan) + '</b>';
    var th = kol.map(function (k) {
      return '<th class="' + (k.t === 'sayi' ? 'byz-s' : '') + '">' + esc(k.l) + '</th>';
    }).join('');
    var nowrapKey = { tarih: 1, parti: 1, telefon: 1, cekici: 1, dorse: 1 };
    var tr = veri.satirlar.map(function (s) {
      return '<tr>' + s.map(function (h, i) {
        var k = kol[i];
        var cls = k.t === 'sayi' ? 'byz-s' : (nowrapKey[k.k] ? 'byz-nw' : '');
        return '<td class="' + cls + '">' + esc(h === '' ? '—' : h) + '</td>';
      }).join('') + '</tr>';
    }).join('');
    var ft = veri.toplam.map(function (h, i) {
      var k = kol[i];
      var ilkMetin = i === 0;
      return '<td class="' + (k.t === 'sayi' ? 'byz-s' : '') + '">' + (ilkMetin ? 'TOPLAM' : esc(h)) + '</td>';
    }).join('');
    return '<div class="byz" id="pdfIcerik">' +
      '<h2>' + esc(veri.baslik) + '</h2>' +
      '<div class="byz-meta">' + meta + '</div>' +
      '<table id="byzTablo"><thead><tr>' + th + '</tr></thead><tbody>' + tr + '</tbody>' +
      '<tfoot><tr>' + ft + '</tr></tfoot></table></div>';
  }

  // Dosya adında kullanılamayan karakterleri ayıkla (pencere <title>'ı da bu olur).
  var dosyaAdi = String(veri.dosya || 'Bekleyen Beyanlar').replace(/[\\/:*?"<>|]/g, ' ').replace(/\s+/g, ' ').trim();
  var paylasMetni = veri.baslik + ' · ' + veri.adet + ' beyan\n' + veri.tarih;

  // ── Yazdır ─────────────────────────────────────────────────────────────────
  btnYazdir.addEventListener('click', function () {
    var w = window.open('', '_blank');
    if (!w) { alert('Yazdırma penceresi engellendi. Açılır pencere izni verin.'); return; }
    w.document.write(
      '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<title>' + esc(dosyaAdi) + '</title><style>' +
      'body{margin:0;padding:16px;background:#fff}' + CSS +
      '.byz-btn{display:flex;gap:8px;justify-content:flex-end;margin-bottom:14px}' +
      '.byz-b{padding:9px 18px;cursor:pointer;font-size:13px;font-weight:700;border:1px solid #1c4a6e;background:#1c4a6e;color:#fff;border-radius:8px}' +
      '@page{size:A4 landscape;margin:8mm}' +
      '@media print{body{padding:0}.byz-btn{display:none}}' +
      '</style></head><body>' +
      '<div class="byz-btn"><button class="byz-b" onclick="window.print()">🖨️ Yazdır</button></div>' +
      raporHtml() + '</body></html>');
    w.document.close();
    // İçerik yerleştikten sonra yazdırma penceresini kendiliğinden aç.
    w.focus();
    setTimeout(function () { try { w.print(); } catch (e) { /* düğmeden yeniden denenebilir */ } }, 350);
  });

  // ── PDF Gönder ─────────────────────────────────────────────────────────────
  var mobil = mobilMi();
  var etiketNormal = mobil ? '📤 PDF Gönder' : '⬇️ PDF İndir';
  btnPdf.textContent = etiketNormal;
  var hazirDosya = null;   // paylaşım hareketi reddedilirse (iOS) hazır PDF bir sonraki dokunuşta paylaşılır

  function betikYukle(src) {
    return new Promise(function (ok, hata) {
      var s = document.createElement('script');
      s.src = src; s.onload = ok;
      s.onerror = function () { hata(new Error(src + ' yüklenemedi')); };
      document.head.appendChild(s);
    });
  }

  // Sayfa kesimi SATIR SINIRINDA yapılır (satır ortadan bölünmez) ve ikinci sayfadan itibaren
  // tablo başlığı yeniden çizilir. Yatay A4.
  async function pdfUret() {
    if (!window.html2canvas) await betikYukle('halkayit/html2canvas.min.js');
    if (!window.jspdf) await betikYukle('halkayit/jspdf.umd.min.js');

    var tutucu = document.createElement('div');
    tutucu.setAttribute('aria-hidden', 'true');
    tutucu.style.cssText = 'position:fixed;left:-100000px;top:0;width:max-content;min-width:1100px;background:#fff;z-index:-1';
    tutucu.innerHTML = '<style>' + CSS + '</style>' + raporHtml();
    document.body.appendChild(tutucu);
    try {
      var el = tutucu.querySelector('#pdfIcerik');
      var olcek = 1.25;
      var kanvas = await window.html2canvas(el, { scale: olcek, backgroundColor: '#ffffff' });

      var jsPDF = window.jspdf.jsPDF;
      var pdf = new jsPDF({ orientation: 'l', unit: 'mm', format: 'a4' });
      var kenar = 8;
      var icerikG = pdf.internal.pageSize.getWidth() - kenar * 2;
      var icerikY = pdf.internal.pageSize.getHeight() - kenar * 2;
      var pxPerMm = kanvas.width / icerikG;
      var sayfaMaxPx = Math.floor(icerikY * pxPerMm);

      var kokUst = el.getBoundingClientRect().top;
      var thead = el.querySelector('thead');
      var basAlt = Math.round((thead.getBoundingClientRect().bottom - kokUst) * olcek);   // başlık satırının alt sınırı
      var basUst = Math.round((thead.getBoundingClientRect().top - kokUst) * olcek);
      var basYuk = basAlt - basUst;
      var sinirlar = [];
      el.querySelectorAll('tbody tr, tfoot tr').forEach(function (tr) {
        sinirlar.push(Math.round((tr.getBoundingClientRect().bottom - kokUst) * olcek));
      });
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
      return pdf.output('blob');
    } finally {
      tutucu.remove();
    }
  }

  function indir(blob) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = dosyaAdi + '.pdf';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  async function paylas(dosya) {
    await navigator.share({ files: [dosya], title: dosyaAdi, text: paylasMetni });
  }

  btnPdf.addEventListener('click', async function () {
    if (btnPdf.disabled) return;
    btnPdf.disabled = true;
    try {
      // Önceki denemede hazırlanıp paylaşılamayan PDF: yeni bir dokunuşla paylaş.
      if (hazirDosya) {
        var d = hazirDosya;
        await paylas(d);
        hazirDosya = null; btnPdf.textContent = etiketNormal;
        return;
      }
      btnPdf.textContent = '⏳ PDF hazırlanıyor...';
      var blob = await pdfUret();
      var dosya = new File([blob], dosyaAdi + '.pdf', { type: 'application/pdf' });
      if (mobil && navigator.canShare && navigator.canShare({ files: [dosya] })) {
        try {
          await paylas(dosya);
          btnPdf.textContent = etiketNormal;
        } catch (e) {
          if (e && e.name === 'NotAllowedError') {
            // Hazırlık sırasında dokunma izni zaman aşımına uğradı (özellikle iOS) — PDF hazır, bir dokunuş yeter.
            hazirDosya = dosya;
            btnPdf.textContent = '📤 PDF hazır — Paylaş';
          } else { throw e; }
        }
      } else {
        indir(blob);
        btnPdf.textContent = etiketNormal;
      }
    } catch (e) {
      btnPdf.textContent = hazirDosya ? '📤 PDF hazır — Paylaş' : etiketNormal;
      // Kullanıcı paylaşım sayfasını kapatırsa (AbortError) hata değildir.
      if (!e || e.name !== 'AbortError') alert('PDF hazırlanamadı: ' + (e && e.message ? e.message : e));
    } finally {
      btnPdf.disabled = false;
    }
  });
})();
