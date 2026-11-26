// =========================================================
// assets/bi_rapor.js — ERP Faz 5 BI Raporlama istemci yardımcıları
// Güvenli IIFE: istemci depolaması veya ham HTML ekleme kullanılmaz.
// =========================================================
(function () {
  'use strict';

  document.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('[data-bi-yazdir]') : null;
    if (btn) {
      window.print();
    }
  });
})();
