/* Frugro "Mailden al" (frugro_mail.php) — siparis.js mailAl() döngüsünün sadeleştirilmiş eşi.
   Tek tık = baslat → devam… → bitti. Sonuç HTML'i sunucuda h() ile kaçırılmış TEK render (innerHTML yalnız o).
   Ağ kopunca "Devam et" aynı tarama_id'yle sürer. Satır içi olay YOK; kullanıcı verisi yalnız textContent. */
(function () {
    'use strict';
    function q(s, k) { return (k || document).querySelector(s); }

    function kur(kap) {
        var url = kap.getAttribute('data-frgm-url') || 'frugro_mail.php';
        var csrf = kap.getAttribute('data-frgm-csrf') || '';
        var baslatDugme = q('[data-frgm-baslat]', kap);
        var ilerleme = q('[data-frgm-ilerleme]', kap), bar = ilerleme ? q('progress', ilerleme) : null;
        var metin = ilerleme ? q('[data-frgm-ilerleme-metin]', ilerleme) : null;
        var devamIl = ilerleme ? q('[data-frgm-devam-et]', ilerleme) : null, vazgecIl = ilerleme ? q('[data-frgm-vazgec]', ilerleme) : null;
        var hataKutu = q('[data-frgm-hata]', kap), sonucKap = q('[data-frgm-sonuc]', kap);
        var durum = { tid: '', calisiyor: false };
        if (!baslatDugme) return;

        function kilitle(k) { kap.setAttribute('aria-busy', k ? 'true' : 'false'); baslatDugme.disabled = !!k; }
        function hataYaz(m) { if (hataKutu) { hataKutu.textContent = m || ''; hataKutu.hidden = !m; } }
        function ilerlemeGoster(g) { if (ilerleme) ilerleme.hidden = !g; }
        function dugmeGoster(devam, vazgec) { if (devamIl) devamIl.hidden = !devam; if (vazgecIl) vazgecIl.hidden = !vazgec; }
        function seritKaldir() {
            var s = kap.querySelectorAll('[data-frgm-devam]');
            for (var i = 0; i < s.length; i++) if (s[i].parentNode) s[i].parentNode.removeChild(s[i]);
        }
        function ilerlemeYaz(y) {
            if (!ilerleme || !metin) return;
            var sy = y.sayac || {}, t = y.toplam || 0;
            if (bar) { bar.max = Math.max(1, t); bar.value = Math.min(t, y.i || 0); }
            var m = 'Mailler taranıyor… ' + (y.i || 0) + ' / ' + t + ' ek · ' + (sy.alindi || 0) + ' alındı · ' + (sy.zaten || 0) + ' zaten vardı';
            if (sy.fark) m += ' · ' + sy.fark + ' fark';
            if (sy.hata) m += ' · ' + sy.hata + ' hata';
            metin.textContent = m;
        }
        function bitir(y) {
            durum.calisiyor = false; durum.tid = '';
            ilerlemeGoster(false); dugmeGoster(false, false); seritKaldir(); hataYaz('');
            if (sonucKap && y.html) { sonucKap.innerHTML = y.html; try { sonucKap.scrollIntoView({ block: 'start', behavior: 'smooth' }); } catch (e) { /* yok say */ } }
            kilitle(false);
        }
        function sorun(m, devamEdilebilir) {
            durum.calisiyor = false;
            hataYaz(m);
            dugmeGoster(devamEdilebilir, !!durum.tid);
            if (devamEdilebilir && metin) metin.textContent = 'Mail sorgusu yarıda kaldı.'; else ilerlemeGoster(false);
            kilitle(false);
        }
        function post(govde) {
            var ac = typeof AbortController === 'function' ? new AbortController() : null;
            var zaman = ac ? setTimeout(function () { ac.abort(); }, 80000) : null;
            return fetch(url, {
                method: 'POST', credentials: 'same-origin', signal: ac ? ac.signal : undefined,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(Object.assign({ csrf: csrf }, govde))
            }).then(function (r) { if (zaman) clearTimeout(zaman); return r.text(); }).then(function (t) {
                try { return JSON.parse(t); } catch (e) { return { ok: false, mesaj: 'Sunucudan beklenmeyen yanıt geldi (oturum süresi dolmuş olabilir). Sayfayı yenileyin.' }; }
            }, function () { if (zaman) clearTimeout(zaman); throw new Error('ag'); });
        }
        function hatamsi(y) { return y.mesaj || y.error || 'İşlem yapılamadı.'; }
        function dongu() {
            durum.calisiyor = true; dugmeGoster(false, true); hataYaz('');
            post({ islem: 'devam', tarama_id: durum.tid }).then(function (y) {
                if (!y.ok) { if (y.kod === 'sona_erdi' || y.kod === 'depo') durum.tid = ''; sorun(hatamsi(y), false); return; }
                ilerlemeYaz(y);
                if (y.bitti) bitir(y); else dongu();
            }, function () { sorun('Bağlantı kesildi — kaldığı yerden devam edebilirsiniz.', true); });
        }
        function baslat() {
            hataYaz(''); kilitle(true); ilerlemeGoster(true); dugmeGoster(false, false); seritKaldir();
            if (bar) { bar.max = 1; bar.value = 0; }
            if (metin) metin.textContent = 'Frugro sorgusu başlatılıyor…';
            post({ islem: 'baslat' }).then(function (y) {
                if (!y.ok) { sorun(hatamsi(y), false); return; }
                durum.tid = y.tarama_id;
                ilerlemeYaz(y);
                if (y.bitti) bitir(y); else dongu();
            }, function () { sorun('Bağlantı kesildi — sorgu başlatılamadı. Yeniden deneyin.', false); });
        }
        kap.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) return;
            var d = t.closest('[data-frgm-baslat]');
            if (d && kap.contains(d)) { if (!d.disabled && !durum.calisiyor) baslat(); return; }
            var dv = t.closest('[data-frgm-devam-et]');
            if (dv) {
                var serit = dv.closest('[data-frgm-devam]');
                if (serit) durum.tid = serit.getAttribute('data-frgm-devam') || '';
                if (!durum.tid || durum.calisiyor) return;
                kilitle(true); ilerlemeGoster(true); hataYaz('');
                if (metin) metin.textContent = 'Frugro sorgusu sürdürülüyor…';
                dongu();
                return;
            }
            var vz = t.closest('[data-frgm-vazgec]');
            if (vz) {
                var s2 = vz.closest('[data-frgm-devam]');
                var tid = s2 ? (s2.getAttribute('data-frgm-devam') || '') : durum.tid;
                if (!tid) return;
                post({ islem: 'vazgec', tarama_id: tid }).then(function (y) {
                    if (y.ok) { durum.tid = tid; bitir(y); } else { seritKaldir(); durum.tid = ''; sorun(hatamsi(y), false); }
                }, function () { sorun('Bağlantı kesildi — vazgeçme isteği ulaşmadı.', true); });
            }
        });
    }

    function basla() { var k = document.querySelectorAll('[data-frgm-kap]'); for (var i = 0; i < k.length; i++) kur(k[i]); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', basla); else basla();
})();
