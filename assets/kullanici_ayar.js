/* assets/kullanici_ayar.js — Kullanıcı Ayarları grubu (v574): Şifre Değiştirme · Ajanda · Kullanıcı Tercihleri.
   Yalnız arayüz kolaylığı; TÜM doğrulama sunucudadır (config/kullanici_ayar.php, config/ajanda_core.php). */
(function () {
    'use strict';

    /* ---- Şifre göster/gizle ---- */
    document.querySelectorAll('[data-ka-goz]').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = document.getElementById(b.getAttribute('data-ka-goz'));
            if (!i) return;
            var gizli = i.type === 'password';
            i.type = gizli ? 'text' : 'password';
            b.setAttribute('aria-pressed', gizli ? 'true' : 'false');
            b.textContent = gizli ? '🙈' : '👁️';
        });
    });

    /* ---- Şifre gücü + eşleşme (yalnız ipucu) ---- */
    var sf = document.querySelector('form[data-ka-sifre]');
    if (sf) {
        var yeni = sf.querySelector('[name="yeni"]');
        var yeni2 = sf.querySelector('[name="yeni2"]');
        var guc = sf.querySelector('.ka-guc');
        var gucYazi = sf.querySelector('[data-ka-guc-yazi]');
        var eslesme = sf.querySelector('[data-ka-eslesme]');
        var ETIKET = ['', 'Zayıf', 'Orta', 'İyi', 'Güçlü'];
        var skor = function (s) {
            if (s.length < 8) return s.length ? 1 : 0;
            var p = 1;
            if (s.length >= 12) p++;
            if (/[a-zçğıöşü]/.test(s) && /[A-ZÇĞİÖŞÜ]/.test(s) && /\d/.test(s)) p++;
            if (/[^A-Za-z0-9çğıöşüÇĞİÖŞÜ]/.test(s)) p++;
            return Math.min(4, p);
        };
        var guncelle = function () {
            if (yeni && guc) {
                var n = skor(yeni.value);
                guc.setAttribute('data-seviye', String(n));
                if (gucYazi) gucYazi.textContent = n ? 'Şifre gücü: ' + ETIKET[n] : '';
            }
            if (yeni && yeni2 && eslesme) {
                if (!yeni2.value) { eslesme.textContent = ''; eslesme.className = 'ka-ipucu'; }
                else if (yeni.value === yeni2.value) { eslesme.textContent = '✓ Şifreler eşleşiyor'; eslesme.className = 'ka-ipucu iyi'; }
                else { eslesme.textContent = 'Şifreler henüz eşleşmiyor'; eslesme.className = 'ka-ipucu hata'; }
            }
        };
        if (yeni) yeni.addEventListener('input', guncelle);
        if (yeni2) yeni2.addEventListener('input', guncelle);
        sf.addEventListener('submit', function (e) {
            if (yeni && yeni2 && yeni.value !== yeni2.value) { e.preventDefault(); guncelle(); yeni2.focus(); }
        });
        guncelle();
    }

    /* ---- Onay isteyen formlar (silme vb.) ---- */
    document.querySelectorAll('form[data-ka-onay]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!window.confirm(f.getAttribute('data-ka-onay'))) e.preventDefault();
        });
    });

    /* ---- Tablo düzeni sıfırlama: sunucu kaydıyla birlikte bu tarayıcıdaki yedeği de sil ---- */
    document.querySelectorAll('form[data-ka-yerel]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;   // onay penceresi iptal edildiyse yedek silinmez
            try { localStorage.removeItem(f.getAttribute('data-ka-yerel')); } catch (e) { /* depolama kapalı */ }
        });
    });

    /* ---- Ekran kilidi PIN'i: mevcut sidebar penceresini aç (yalnız masaüstünde var) ---- */
    document.querySelectorAll('[data-ka-pin]').forEach(function (b) {
        b.addEventListener('click', function () {
            var hedef = document.getElementById('btnPinDegistir');
            if (hedef) { hedef.click(); return; }
            var n = document.querySelector('[data-ka-pin-not]');
            if (n) n.hidden = false;
        });
    });

    /* ---- Tema seçici: asyaTemaSec() (render_header) hem kaydeder hem [data-tema] düğmelerini işaretler ---- */
    document.querySelectorAll('[data-tema]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (typeof window.asyaTemaSec === 'function') window.asyaTemaSec(b.getAttribute('data-tema'));
        });
    });
})();
