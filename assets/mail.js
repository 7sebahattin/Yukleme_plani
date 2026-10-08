/* assets/mail.js — Mail Merkezi (yalnız mail.php). JS yalnız KOLAYLIKTIR: kapalıyken sayfa tam çalışır
   ("Okundu yap" düğmesi her zaman var). GET hiçbir şeyi değiştirmez; okundu işareti POST + CSRF ile gider. */
(function () {
    'use strict';
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    // Açılan mesajı kısa bir bekleyişten sonra okundu işaretle (GET değil, POST).
    var oku = document.querySelector('[data-okundu-gonder]');
    if (oku && window.fetch) {
        var id = oku.getAttribute('data-okundu-gonder');
        setTimeout(function () {
            var body = new URLSearchParams({ csrf: csrf(), islem: 'oku', m: id });
            fetch('mail.php', { method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.ok) return;
                    var sec = document.querySelector('.mail-oge[aria-current="true"]');
                    if (sec) { sec.classList.remove('okunmamis'); var n = sec.querySelector('.mail-nokta'); if (n) n.remove(); }
                    oku.removeAttribute('data-okundu-gonder');
                    var btn = document.querySelector('.mail-okuyucu-arac button[value="oku"]');
                    if (btn) { btn.value = 'okunmadi'; btn.textContent = 'Okunmadı yap'; }
                })
                .catch(function () { /* sessiz: düğme ile elle işaretlenebilir */ });
        }, 1200);
    }

    // Çift tık / çift gönderim: gönderim formlarında ilk gönderimden sonra düğmeleri kilitle (SADECE kolaylık —
    // asıl koruma sunucudadır: idempotency_key + atomik sahiplenme). setTimeout: veri kümesi oluştuktan SONRA devre dışı bırak.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.hasAttribute || !f.hasAttribute('data-tek-gonderim')) return;
        if (f.getAttribute('data-gonderildi') === '1') { e.preventDefault(); return; }
        f.setAttribute('data-gonderildi', '1');
        setTimeout(function () {
            f.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) { b.disabled = true; });
        }, 0);
    });

    // Riskli ek türleri: indirmeden önce onay.
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[data-tehlikeli]') : null;
        if (a && !window.confirm('Bu dosya türü çalıştırılabilir ya da aktif içerik taşıyabilir. Göndericiye güveniyor musunuz? İndirmek istediğinize emin misiniz?')) {
            e.preventDefault();
        }
    });

    // Seçili liste öğesini görünür tut (masaüstü uzun liste).
    var sec = document.querySelector('.mail-oge.secili');
    if (sec && sec.scrollIntoView && window.matchMedia('(min-width: 768px)').matches) {
        sec.scrollIntoView({ block: 'nearest' });
    }
})();
