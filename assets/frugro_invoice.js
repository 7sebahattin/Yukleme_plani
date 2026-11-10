/* frugro_invoice.js — Frugro Invoice sayfası: yalnız ÖNİZLEME (sunucu tutarı yeniden hesaplar), PDF önizleme, çift gönderim koruması.
   Satır içi olay öznitelikleri YOK; her şey data-frgi-* ile bağlanır. Tutar: kutu × fiyat (on-binde tamsayı) → kuruş, yarım yukarı. */
(function () {
  'use strict';
  function q(s, r) { return (r || document).querySelector(s); }
  function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function tamSayi(v) {
    var s = String(v == null ? '' : v).replace(/[\s.]/g, '');
    return /^\d+$/.test(s) ? parseInt(s, 10) : null;
  }
  function fiyat1e4(v) {            // TEK ayraç HER ZAMAN ondalık ("1.370" = 1,37)
    var s = String(v == null ? '' : v).replace(/[\s €£]/g, '');
    if (!/^[0-9.,]+$/.test(s) || !/\d/.test(s)) return null;
    var nd = (s.match(/\./g) || []).length, nc = (s.match(/,/g) || []).length;
    if (nd > 0 && nc > 0) {                              // son ayraç ondalık, öteki binlik
      var dec = s.lastIndexOf('.') > s.lastIndexOf(',') ? '.' : ',';
      var bin = dec === '.' ? /,/g : /\./g;
      if ((s.split(dec).length - 1) !== 1) return null;
      s = s.replace(bin, '');
      s = s.replace(',', '.');
    } else if (nd + nc > 1) { return null; }
    else { s = s.replace(',', '.'); }
    var f = parseFloat(s);
    if (!isFinite(f) || f <= 0 || f >= 1000) return null;
    return Math.round(f * 10000);
  }
  function para(kurus) {
    var tl = Math.floor(kurus / 100), kr = kurus % 100;
    return String(tl).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + (kr < 10 ? '0' : '') + kr;
  }
  function form(f) {
    var kutu0 = parseInt(f.getAttribute('data-frgi-kutu') || '0', 10) || 0;
    var net0 = parseInt(f.getAttribute('data-frgi-net') || '0', 10) || 0;
    var brut0 = parseInt(f.getAttribute('data-frgi-brut') || '0', 10) || 0;
    var gK = q('[data-frgi-giris="kutu"]', f), gF = q('[data-frgi-giris="fiyat"]', f), gP = q('[data-frgi-giris="pb"]', f);
    var gN = q('[data-frgi-giris="net"]', f), gB = q('[data-frgi-giris="brut"]', f);
    var top = q('[data-frgi-toplam]', f), sem = q('[data-frgi-sembol-goster]', f), fark = q('[data-frgi-fark]', f);
    function hesapla() {
      var k = gK && gK.value.trim() !== '' ? tamSayi(gK.value) : kutu0;
      var fp = gF ? fiyat1e4(gF.value) : null;
      if (sem && gP) {
        var o = gP.options[gP.selectedIndex];
        sem.textContent = o ? (o.getAttribute('data-frgi-sembol') || '') : '';
      }
      if (top) top.textContent = (k !== null && k > 0 && fp !== null) ? para(Math.floor((k * fp + 50) / 100)) : '—';
      if (fark) {
        var n = gN && gN.value.trim() !== '' ? tamSayi(gN.value) : net0;
        var b = gB && gB.value.trim() !== '' ? tamSayi(gB.value) : brut0;
        fark.hidden = !((net0 > 0 && n !== net0) || (brut0 > 0 && b !== brut0));
      }
    }
    qa('input,select', f).forEach(function (el) { el.addEventListener('input', hesapla); el.addEventListener('change', hesapla); });
    f.addEventListener('submit', function (e) {          // çift tıklama: aynı gönderim iki kez gitmesin
      if (f.getAttribute('data-frgi-gonderildi') === '1') { e.preventDefault(); return; }
      f.setAttribute('data-frgi-gonderildi', '1');
      window.setTimeout(function () { f.removeAttribute('data-frgi-gonderildi'); }, 8000);
    });
    hesapla();
  }
  function onizle(d) {
    var ifr = q('iframe', d);
    d.addEventListener('toggle', function () {
      if (d.open && ifr && !ifr.getAttribute('src')) ifr.setAttribute('src', d.getAttribute('data-src') || '');
    });
  }
  function basla() { qa('form[data-frgi]').forEach(form); qa('details[data-frgi-onizle]').forEach(onizle); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', basla); else basla();
})();
