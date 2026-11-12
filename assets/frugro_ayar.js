/* frugro_ayar.js — Frugro ayarları · GGN listesi: "+ Satır ekle" (yalnız istemci).
   Yeni satır <template id="frga-satir"> klonudur; indeks = mevcut satır sayısı (sunucudaki indeksler 0..n-1 bitişik).
   Sunucu sınırı FRGP_GGN_MAX: sınıra gelince düğme pasif + not gösterilir. Satır içi olay öznitelikleri YOK (data-frga-*). */
(function () {
  'use strict';
  function basla(btn) {
    var form = btn.closest('form');
    var tbody = form ? form.querySelector('table tbody') : null;
    var tpl = form ? form.querySelector('template#frga-satir') : null;
    if (!tbody || !tpl) return;
    var max = parseInt(btn.getAttribute('data-frga-max') || '100', 10) || 100;
    var not = form.querySelector('[data-frga-not]');
    function durum() {
      var dolu = tbody.rows.length >= max;
      btn.disabled = dolu;
      if (not) not.hidden = !dolu;
    }
    btn.addEventListener('click', function () {
      if (tbody.rows.length >= max) { durum(); return; }
      var i = tbody.rows.length;
      var frag = tpl.content.cloneNode(true);
      var tr = frag.querySelector('tr');
      var ad = tr.querySelector('[data-frga-ad]');
      var no = tr.querySelector('[data-frga-no-giris]');
      tr.querySelector('[data-frga-no]').textContent = String(i + 1);
      ad.name = 'ggn[' + i + '][ad]';
      ad.setAttribute('aria-label', 'GGN adı ' + (i + 1));
      no.name = 'ggn[' + i + '][no]';
      no.setAttribute('aria-label', 'GGN no ' + (i + 1));
      tbody.appendChild(frag);
      durum();
      ad.focus();
    });
    durum();
  }
  function basla_hepsi() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-frga-ekle]'), basla);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', basla_hepsi);
  else basla_hepsi();
})();
