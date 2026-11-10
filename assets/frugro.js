/* frugro.js — Frugro Siparişler (frugro_siparis.php liste + frugro_view.php detay).
 * Liste: satıra tıkla → detay, Order Log xlsx yükleme (frugro_yukle.php) + sonuç özeti.
 * Detay: Düzenle penceresi (native <dialog>), durum (kapat / iptal / yeniden aç), iki adımlı silme (frugro_kaydet.php).
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

  /* ---- detay ---- */
  var ID = parseInt(kok.getAttribute('data-id') || '0', 10);
  if (!ID) return;
  var genelHata = $('frgHata');
  function gonder(yuk) {
    yuk.csrf = CSRF; yuk.id = ID;
    return fetch('frugro_kaydet.php', { method: 'POST', body: JSON.stringify(yuk), credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, hata: 'Sunucu yanıtı okunamadı.' }; }); })
      .catch(function () { return { ok: false, hata: 'Bağlantı hatası.' }; });
  }
  function hata(kutu, m) { kutu.textContent = m; kutu.hidden = false; }

  Array.prototype.forEach.call(document.querySelectorAll('[data-frg-kapat]'), function (b) {
    b.addEventListener('click', function () { var d = b.closest('dialog'); if (d) d.close(); });
  });

  var dlg = $('frgDialog'), duzenleAc = $('frgDuzenleAc'), form = $('frgForm');
  if (duzenleAc && dlg && form) {
    duzenleAc.addEventListener('click', function () { $('frgFormHata').hidden = true; dlg.showModal(); });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var alanlar = {};
      Array.prototype.forEach.call(form.elements, function (f) { if (f.name) alanlar[f.name] = f.value; });
      var k = $('frgKaydet'); k.disabled = true;
      gonder({ islem: 'duzenle', alanlar: alanlar }).then(function (j) {
        k.disabled = false;
        if (j && j.ok) { window.location.reload(); return; }
        hata($('frgFormHata'), (j && (j.hata || j.error)) || 'Kaydedilemedi.');
      });
    });
  }

  function durumGonder(hedef, gerekce, kutu) {
    return gonder({ islem: 'durum', durum: hedef, gerekce: gerekce || '' }).then(function (j) {
      if (j && j.ok) { window.location.reload(); return; }
      hata(kutu || genelHata, (j && (j.hata || j.error)) || 'İşlem başarısız.');
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-frg-durum]'), function (b) {
    b.addEventListener('click', function () {
      var hedef = b.getAttribute('data-frg-durum');
      if (hedef === 'kapali' && !window.confirm('Sipariş kapatılsın mı?')) return;
      durumGonder(hedef, '', genelHata);
    });
  });

  var ipDlg = $('frgIptalDialog'), ipAc = $('frgIptalAc'), ipForm = $('frgIptalForm');
  if (ipDlg && ipAc && ipForm) {
    ipAc.addEventListener('click', function () { $('frgIptalHata').hidden = true; $('frgGerekce').value = ''; ipDlg.showModal(); });
    ipForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var g = $('frgGerekce').value.trim();
      if (g.length < 3) { hata($('frgIptalHata'), 'İptal için gerekçe zorunlu.'); return; }
      durumGonder('iptal', g, $('frgIptalHata'));
    });
  }

  var silIlk = $('frgSilIlk'), silOnay = $('frgSilOnay');
  if (silIlk && silOnay) {
    silIlk.addEventListener('click', function () { silIlk.hidden = true; silOnay.hidden = false; });
    $('frgSilHayir').addEventListener('click', function () { silOnay.hidden = true; silIlk.hidden = false; });
    $('frgSilEvet').addEventListener('click', function () {
      gonder({ islem: 'sil', onay: true }).then(function (j) {
        if (j && j.ok) { window.location.href = 'frugro_siparis.php'; return; }
        hata(genelHata, (j && (j.hata || j.error)) || 'Silinemedi.');
        silOnay.hidden = true; silIlk.hidden = false;
      });
    });
  }
})();
