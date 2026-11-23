/* frugro_detay.js — Frugro sipariş detayı (frugro_view.php) için KÜÇÜK ek. Pencereler, ✎ alan düzenleme, mail/evrak onay kilidi,
 * Kopyala ve düzenlenebilir mail metni assets/siparis.js'tedir (data-sip-* sözleşmesi). Burada yalnız "⇄ Durumu değiştir"
 * penceresinin gerekçe kuralı: seçilen hedef data-frg-gerekce="1" ise gerekçe alanı görünür ve zorunlu olur (sunucu da aynı
 * kuralı frg_durum_degistir'de zorlar). Satır içi onclick YOK. */
(function () {
  'use strict';
  function guncelle(f) {
    var sec = f.querySelector('input[name=hedef]:checked');
    var gerek = !!(sec && sec.getAttribute('data-frg-gerekce') === '1');
    var kap = f.querySelector('[data-frg-gerekce-kap]'), ta = kap ? kap.querySelector('textarea') : null;
    if (kap) kap.hidden = !gerek;
    if (ta) { ta.required = gerek; ta.minLength = gerek ? 3 : 0; }
  }
  document.addEventListener('change', function (e) {
    var f = e.target.closest ? e.target.closest('[data-frg-durum-form]') : null;
    if (f) guncelle(f);
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-frg-durum-form]'), guncelle);
})();
