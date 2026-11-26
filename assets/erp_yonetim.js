/* =========================================================
 * assets/erp_yonetim.js — ERP Faz 3 İstemci Doğrulamaları
 * Kurallar: IIFE · istemci depolaması yok · güvenli DOM erişimi
 * ========================================================= */
(function () {
  'use strict';

  document.querySelectorAll('form[data-onay-red]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var inp = f.querySelector('input[name="not"]');
      if (inp && !String(inp.value || '').trim()) {
        e.preventDefault();
        window.alert('Onay talebini reddetmek için gerekçe yazmalısınız.');
        inp.focus();
      }
    });
  });
})();
