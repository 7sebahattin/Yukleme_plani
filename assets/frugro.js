/* frugro.js — Frugro Siparişler LİSTESİ (frugro_siparis.php).
 * Liste: satıra tıkla → detay, Order Log xlsx yükleme (frugro_yukle.php) + sonuç özeti.
 * Detay bu dosyayı KULLANMAZ (assets/siparis.js + assets/frugro_detay.js).
 * Satır içi onclick YOK; tüm metin textContent ile basılır (XSS yok). Sunucu tek otoritedir; buradaki doğrulama yalnız kolaylık. */
(function () {
  'use strict';
  var kok = document.getElementById('frg');
  if (!kok) return;
  var CSRF = kok.getAttribute('data-csrf') || '';
  var $ = function (id) { return document.getElementById(id); };
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  /* ---- liste: satıra tıkla → detay ---- */
  Array.prototype.forEach.call(document.querySelectorAll('tr.frg-satir[data-href]'), function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;
      var sec = window.getSelection && String(window.getSelection());
      if (sec) return;   // metin seçiliyorsa gezinme
      window.location.href = tr.getAttribute('data-href');
    });
    tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') window.location.href = tr.getAttribute('data-href'); });
  });

  /* ---- liste: Excel yükle ---- */
  var yDugme = $('frgYukleDugme'), yDosya = $('frgDosya'), sonuc = $('frgSonuc');
  var ETIKET = { olustu: 'yeni', zaten: 'zaten var', fark: 'fark (alanlar korundu)', guncellendi: 'güncellendi', silinmis: 'silinmiş PO', hata: 'hata' };
  function sonucYaz(veri) {
    sonuc.textContent = '';
    var kapat = el('button', 'btn btn-sm frg-sonuc-kapat', 'Kapat'); kapat.type = 'button';
    kapat.addEventListener('click', function () { sonuc.hidden = true; });
    sonuc.appendChild(kapat);
    sonuc.appendChild(el('h2', null, 'Yükleme sonucu'));
    var o = veri.ozet || {};
    var oz = el('div', 'frg-ozet');
    oz.appendChild(el('span', null, (o.yeni || 0) + ' yeni'));
    oz.appendChild(el('span', null, (o.zaten || 0) + ' zaten'));
    oz.appendChild(el('span', null, (o.fark || 0) + ' fark'));
    if (o.hata) oz.appendChild(el('span', 'frg-sonuc-hata', o.hata + ' hata'));
    sonuc.appendChild(oz);
    (veri.dosyalar || []).forEach(function (d) {
      var ul = el('ul');
      var bas = el('li'); bas.appendChild(el('b', null, d.ad || 'dosya'));
      if (!d.ok) { bas.appendChild(el('span', 'frg-sonuc-hata', ' — ' + (d.hata || 'okunamadı'))); }
      ul.appendChild(bas);
      (d.sonuclar || []).forEach(function (r) {
        var li = el('li', null, r.po_no + ' — ' + (ETIKET[r.durum] || r.durum) + (r.mesaj ? ': ' + r.mesaj : ''));
        ul.appendChild(li);
        (r.farklar || []).forEach(function (f) { ul.appendChild(el('li', 'frg-soluk', '   ' + f.alan + ': ' + (f.eski || '—') + ' → ' + (f.yeni || '—'))); });
      });
      (d.uyarilar || []).forEach(function (u) { ul.appendChild(el('li', 'frg-uyari', '⚠ ' + u)); });
      sonuc.appendChild(ul);
    });
    if ((o.yeni || 0) + (o.fark || 0) > 0) {
      var yen = el('button', 'btn btn-primary', 'Listeyi yenile'); yen.type = 'button';
      yen.addEventListener('click', function () { window.location.reload(); });
      sonuc.appendChild(yen);
    }
    sonuc.hidden = false;
    sonuc.scrollIntoView({ block: 'nearest' });
  }
  function hataYaz(m) { sonucYaz({ ozet: { hata: 1 }, dosyalar: [{ ad: '', ok: false, hata: m, sonuclar: [], uyarilar: [] }] }); }
  if (yDugme && yDosya && sonuc) {
    yDugme.addEventListener('click', function () { yDosya.click(); });
    yDosya.addEventListener('change', function () {
      if (!yDosya.files.length) return;
      var fd = new FormData();
      fd.append('csrf', CSRF);
      Array.prototype.forEach.call(yDosya.files, function (f) { fd.append('dosya[]', f, f.name); });
      yDugme.disabled = true; yDugme.textContent = '⏳ Yükleniyor…';
      fetch('frugro_yukle.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return { ok: false, hata: 'Sunucu yanıtı okunamadı.' }; }); })
        .then(function (j) { if (j && j.ok) sonucYaz(j); else hataYaz((j && (j.hata || j.error)) || 'Yükleme başarısız.'); })
        .catch(function () { hataYaz('Bağlantı hatası.'); })
        .then(function () { yDugme.disabled = false; yDugme.textContent = '📥 Excel yükle'; yDosya.value = ''; });
    });
  }

  /* Detay (frugro_view.php) artık OGL sevkiyat sayfasının eşi: pencereler / ✎ / durum formları assets/siparis.js
     (data-sip-*) + assets/frugro_detay.js ile çalışır; bu dosya yalnız LİSTE içindir. */
})();
