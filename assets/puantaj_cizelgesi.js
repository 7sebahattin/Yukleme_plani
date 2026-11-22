/* puantaj_cizelgesi.js — Aylık Puantaj Çizelgesi (v552). Yalnız küçük ergonomi:
   ay / "pasifleri göster" değişince formu gönder, mobilde gösterge aç-kapa,
   bu aysa bugünün sütununu ızgarada görünür yap. Hesap/filtre sunucudadır. */
(function () {
    'use strict';
    var form = document.getElementById('pcForm');
    if (form) {
        form.querySelectorAll('[data-oto]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (el.type === 'month' && !/^\d{4}-(0[1-9]|1[0-2])$/.test(el.value)) return;   // yarım yazılmış ay gönderilmez
                form.submit();
            });
        });
    }
    var lej = document.getElementById('pcLejant');
    if (lej) {
        var dugme = lej.querySelector('.pc-lejant-dugme');
        if (dugme) dugme.addEventListener('click', function () {
            var acik = lej.classList.toggle('acik');
            dugme.setAttribute('aria-expanded', acik ? 'true' : 'false');
        });
    }
    var wrap = document.getElementById('pcGridWrap');
    var bugun = wrap && wrap.querySelector('th[data-bugun]');
    if (wrap && bugun && wrap.offsetParent !== null) {
        wrap.scrollLeft = Math.max(0, bugun.offsetLeft - wrap.clientWidth / 2);
    }
})();
