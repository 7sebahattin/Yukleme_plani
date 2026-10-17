/* assets/mail.js — Mail Merkezi (yalnız mail.php). JS yalnız KOLAYLIKTIR: kapalıyken sayfa tam çalışır
   ("Okundu yap" düğmesi her zaman var). GET hiçbir şeyi değiştirmez; okundu işareti POST + CSRF ile gider. */
(function () {
    'use strict';
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    // Rozet sayısını bir azalt; 0'a inerse rozeti kaldır (okundu işaretlenince çip sayaçları tazelensin).
    function sayacAzalt(el) {
        if (!el) return;
        var n = parseInt(el.textContent, 10);
        if (isNaN(n)) return;
        if (n <= 1) el.remove(); else el.textContent = String(n - 1);
    }

    // Açılan mesajı kısa bir bekleyişten sonra okundu işaretle (GET değil, POST).
    var oku = document.querySelector('[data-okundu-gonder]');
    if (oku && window.fetch) {
        var id = oku.getAttribute('data-okundu-gonder');
        var hesap = oku.getAttribute('data-okundu-hesap');
        setTimeout(function () {
            var body = new URLSearchParams({ csrf: csrf(), islem: 'oku', m: id });
            fetch('mail.php', { method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.ok) return;
                    var sec = document.querySelector('.mail-oge[aria-current="true"]');
                    if (sec) {
                        sec.classList.remove('okunmamis');
                        var n = sec.querySelector('.mail-nokta'); if (n) n.remove();
                        var g = sec.querySelector('.mail-gizli'); if (g) g.remove();
                    }
                    oku.removeAttribute('data-okundu-gonder');
                    var btn = document.querySelector('.mail-okuyucu-arac button[value="oku"]');
                    if (btn) { btn.value = 'okunmadi'; btn.textContent = 'Okunmadı yap'; }
                    // Okunmamış sayaçları: "Tümü" çipi, mesajın hesap çipi ve "Okunmamış" klasör çipi.
                    sayacAzalt(document.querySelector('.mail-chip[data-hesap="0"] .mail-rozet'));
                    if (hesap) sayacAzalt(document.querySelector('.mail-chip[data-hesap="' + hesap + '"] .mail-rozet'));
                    sayacAzalt(document.querySelector('.mail-chip[data-filtre="okunmamis"] .mail-say'));
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

    // Geri alınamaz / sonuç doğuran düğmeler: data-onay="soru" → onaylanmazsa gönderim iptal (inline onclick YOK; JS kapalıysa doğrudan gider).
    // data-gerek-secim: toplu işlemde hiçbir kutu seçili değilse önce uyarır.
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-onay]') : null;
        if (!b) return;
        if (b.hasAttribute('data-gerek-secim') && !document.querySelector('input[name="ids[]"]:checked')) {
            e.preventDefault();
            window.alert('Önce işaretlenecek mailleri seçin.');
            return;
        }
        if (!window.confirm(b.getAttribute('data-onay'))) e.preventDefault();
    });

    // Cevap Bekleyen: "Tümünü seç" ↔ satır kutuları.
    var tumu = document.querySelector('input[data-toplu-tumu]');
    if (tumu) {
        var kutular = function () { return Array.prototype.slice.call(document.querySelectorAll('input[name="ids[]"]')); };
        tumu.addEventListener('change', function () { kutular().forEach(function (k) { k.checked = tumu.checked; }); });
        document.addEventListener('change', function (e) {
            if (!e.target || e.target.name !== 'ids[]') return;
            var ks = kutular(), n = ks.filter(function (k) { return k.checked; }).length;
            tumu.checked = n === ks.length && n > 0;
            tumu.indeterminate = n > 0 && n < ks.length;
        });
    }

    // Cevap taslağı yedeği: CSRF süresi dolup gönderim 403 olursa (ya da sekme kapanırsa) yazılan metin kaybolmasın.
    // YALNIZ bu tarayıcıda (localStorage), mesaj kimliği başına; sunucuya gitmez. Cevap onay paneli açılınca (taslak sunucuda
    // kayıtlıdır) silinir; 7 günden eskiler temizlenir. Depolama yoksa/engelliyse sessizce atlanır.
    var TASLAK_ONEK = 'mail_taslak_', TASLAK_OMUR = 7 * 24 * 3600 * 1000;
    function depo() { try { return window.localStorage || null; } catch (e) { return null; } }
    function taslakOku(anahtar) {
        try {
            var s = depo(); if (!s) return null;
            var j = JSON.parse(s.getItem(TASLAK_ONEK + anahtar) || 'null');
            return j && typeof j.v === 'string' && j.v !== '' ? j : null;
        } catch (e) { return null; }
    }
    function taslakSil(anahtar) { try { var s = depo(); if (s) s.removeItem(TASLAK_ONEK + anahtar); } catch (e) { /* yoksay */ } }
    try {
        var st = depo();
        if (st) {
            for (var i = st.length - 1; i >= 0; i--) {
                var k = st.key(i);
                if (!k || k.indexOf(TASLAK_ONEK) !== 0) continue;
                var j0 = null; try { j0 = JSON.parse(st.getItem(k) || 'null'); } catch (e) { /* bozuk kayıt → sil */ }
                if (!j0 || !j0.t || Date.now() - j0.t > TASLAK_OMUR) st.removeItem(k);
            }
        }
    } catch (e) { /* yoksay */ }

    var temizle = document.querySelector('[data-taslak-temizle]');
    if (temizle) taslakSil(temizle.getAttribute('data-taslak-temizle'));

    var yazForm = document.querySelector('form[data-taslak-anahtar]');
    if (yazForm) {
        var anahtar = yazForm.getAttribute('data-taslak-anahtar');
        var ta = yazForm.querySelector('textarea[name="body_tr"]');
        var dil = yazForm.querySelector('select[name="target_lang"]');
        if (ta) {
            var kayit = taslakOku(anahtar);
            if (kayit && ta.value === '') {
                ta.value = kayit.v;
                if (dil && kayit.d) dil.value = kayit.d;
                var not = document.createElement('div');
                not.className = 'mail-bilgi';
                not.setAttribute('role', 'status');
                not.appendChild(document.createTextNode('Önceki, gönderilmemiş taslağınız geri yüklendi. '));
                var sil = document.createElement('button');
                sil.type = 'button'; sil.className = 'btn'; sil.textContent = 'Taslağı sil';
                sil.addEventListener('click', function () { ta.value = ''; taslakSil(anahtar); not.remove(); ta.focus(); });
                not.appendChild(sil);
                yazForm.parentNode.insertBefore(not, yazForm);
            }
            var zamanlayici = null;
            var kaydet = function () {
                try {
                    var s = depo(); if (!s) return;
                    if (ta.value.trim() === '') { s.removeItem(TASLAK_ONEK + anahtar); return; }
                    s.setItem(TASLAK_ONEK + anahtar, JSON.stringify({ t: Date.now(), v: ta.value.slice(0, 20000), d: dil ? dil.value : '' }));
                } catch (e) { /* kota/erişim hatası: yedek yok, form yine çalışır */ }
            };
            ta.addEventListener('input', function () { clearTimeout(zamanlayici); zamanlayici = setTimeout(kaydet, 400); });
            if (dil) dil.addEventListener('change', kaydet);
            window.addEventListener('pagehide', kaydet);
        }
    }

    // Seçili liste öğesini görünür tut (masaüstü uzun liste).
    var sec = document.querySelector('.mail-oge.secili');
    if (sec && sec.scrollIntoView && window.matchMedia('(min-width: 768px)').matches) {
        sec.scrollIntoView({ block: 'nearest' });
    }
})();
