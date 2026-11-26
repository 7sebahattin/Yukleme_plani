// =========================================================
// assets/erp_planlama.js — ERP Faz 4 istemci yardımcıları
// Güvenli IIFE: istemci depolaması veya ham HTML ekleme kullanılmaz.
// =========================================================
(function () {
  'use strict';

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.matches) return;
    var btn = form.querySelector('button[type="submit"]');
    if (btn && !btn.disabled) {
      btn.setAttribute('aria-busy', 'true');
    }
  });
})();
