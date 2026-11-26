/* assets/whatsapp.js — WhatsApp Yükleme Planı yönetim sayfası (vanilla JS)
   Tüm istekler aynı sayfaya POST + CSRF (form-urlencoded, X-Requested-With). Metinler textContent ile yazılır (XSS yok);
   günlük tablosu sunucuda h() ile üretilen HTML'dir. */
(function () {
    'use strict';
    var kok = document.getElementById('waSayfa');
    if (!kok) return;
    var csrf = kok.getAttribute('data-csrf') || '';
    var $ = function (id) { return document.getElementById(id); };

    function post(alanlar) {
        var govde = new URLSearchParams();
        govde.set('csrf', csrf);
        Object.keys(alanlar).forEach(function (k) { govde.set(k, alanlar[k]); });
        return fetch('whatsapp_yonetim.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: govde.toString()
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, hata: 'Sunucudan geçersiz yanıt (HTTP ' + r.status + ').' }; });
        }).catch(function () { return { ok: false, hata: 'Sunucuya ulaşılamadı.' }; });
    }

    /* ── 1) Bağlantı: 5 sn'de bir durum; QR yalnız gerektiğinde ── */
    var rozet = $('waDurumRozet'), not = $('waDurumNot'), qrKutu = $('waQrKutu'), qrImg = $('waQrImg');
    var qrZaman = 0, mesgul = false, sonDurum = '';
    var ETIKET = { open: 'Bağlı', connecting: 'Bağlanıyor…', close: 'Bağlantı yok' };

    function durumYaz(d) {
        sonDurum = d;
        rozet.className = 'wa-rozet wa-' + (ETIKET[d] ? d : 'pasif');
        rozet.textContent = ETIKET[d] || d;
        if (d === 'open') { qrKutu.hidden = true; qrImg.removeAttribute('src'); qrZaman = 0; }
    }
    function qrYaz(qr) {
        if (qr) { qrImg.src = qr; qrKutu.hidden = false; qrZaman = Date.now(); }
    }
    function durumSor(qrIste, kur) {
        if (mesgul) return Promise.resolve();
        mesgul = true;
        return post({ ajax: kur ? 'kur' : 'durum', qr: qrIste ? '1' : '' }).then(function (r) {
            mesgul = false;
            if (!r.ok) {
                rozet.className = 'wa-rozet wa-hata'; rozet.textContent = 'Ulaşılamıyor';
                not.textContent = r.hata || 'Durum alınamadı.';
                return;
            }
            not.textContent = '';
            durumYaz(r.durum);
            qrYaz(r.qr);
        });
    }
    function dongu() {
        // QR yoksa ya da 45 sn'den eskiyse (WhatsApp QR'ı kısa sürede yeniler) ve bağlı değilse yeni QR iste
        var qrGerek = sonDurum !== 'open' && sonDurum !== '' && (Date.now() - qrZaman > 45000) && !qrKutu.hidden;
        durumSor(qrGerek, false);
    }
    $('waQrIste').addEventListener('click', function () { durumSor(true, true); });
    $('waDurumYenile').addEventListener('click', function () { durumSor(false, false); });
    durumSor(false, false);
    setInterval(function () { if (!document.hidden) dongu(); }, 5000);

    /* ── 2) Gruplar ── */
    var liste = $('waGrupListe'), grupNot = $('waGrupNot');
    function secili() {
        var s = {};
        liste.querySelectorAll('input[type=checkbox]').forEach(function (c) { s[c.value] = c.checked; });
        return s;
    }
    $('waGrupGetir').addEventListener('click', function () {
        var btn = this; btn.disabled = true; grupNot.textContent = 'Gruplar getiriliyor…';
        post({ ajax: 'gruplar' }).then(function (r) {
            btn.disabled = false;
            if (!r.ok) { grupNot.textContent = r.hata || 'Gruplar alınamadı.'; return; }
            var onceki = secili();
            liste.textContent = '';
            r.gruplar.forEach(function (g) {
                var li = document.createElement('li');
                var lab = document.createElement('label'); lab.className = 'wa-onay';
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'gruplar[]'; cb.value = g.jid; cb.checked = !!onceki[g.jid];
                var sp = document.createElement('span'); sp.textContent = g.ad + (g.uye ? ' · ' + g.uye + ' üye' : '');
                var sm = document.createElement('small'); sm.textContent = g.jid;
                lab.appendChild(cb); lab.appendChild(sp); lab.appendChild(sm);
                var gz = document.createElement('input'); gz.type = 'hidden'; gz.name = 'grup_ad[' + g.jid + ']'; gz.value = g.ad;
                li.appendChild(lab); li.appendChild(gz); liste.appendChild(li);
            });
            // Önceden seçili ama artık listede olmayan gruplar da korunur (hat gruptan çıkmış olabilir)
            Object.keys(onceki).forEach(function (jid) {
                if (!onceki[jid] || r.gruplar.some(function (g) { return g.jid === jid; })) return;
                var li = document.createElement('li');
                var lab = document.createElement('label'); lab.className = 'wa-onay';
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'gruplar[]'; cb.value = jid; cb.checked = true;
                var sp = document.createElement('span'); sp.textContent = jid + ' (listede yok)';
                lab.appendChild(cb); lab.appendChild(sp); li.appendChild(lab); liste.appendChild(li);
            });
            grupNot.textContent = r.gruplar.length + ' grup bulundu. Dinlemek istediklerinizi işaretleyip "Ayarları kaydet"e basın. Hiçbiri işaretli değilse TÜM gruplar dinlenir.';
        });
    });

    /* ── 3) AI test ── */
    var testDurum = $('waTestDurum');
    $('waTestGonder').addEventListener('click', function () {
        var metin = $('waTestMetin').value.trim();
        if (!metin) { testDurum.textContent = 'Önce bir mesaj yazın.'; return; }
        var btn = this; btn.disabled = true; testDurum.textContent = 'Ayrıştırılıyor…';
        post({ ajax: 'test', metin: metin }).then(function (r) {
            btn.disabled = false;
            if (!r.ok) { testDurum.textContent = r.hata || 'Hata.'; return; }
            testDurum.textContent = '';
            var ozet = $('waTestOzet'); ozet.textContent = '';
            var ROZET = { hazir: ['wa-hazir', 'Karar: Hazır (otomatik taslak olurdu)'], eksik_bilgi: ['wa-eksik', 'Karar: Eksik bilgi'], alakasiz: ['wa-pasif', 'Karar: Alakasız'] };
            var k = ROZET[r.karar] || ['wa-pasif', r.karar];
            [[k[0], k[1]], ['wa-pasif', r.ai + ' · ' + r.model], ['wa-pasif', 'Güven: ' + r.sonuc.guven_skoru + ' (eşik ' + r.esik + ')']]
                .concat(r.gurultu ? [['wa-eksik', 'Gürültü süzgeci AI\'a göndermezdi']] : [])
                .forEach(function (x) { var s = document.createElement('span'); s.className = 'wa-rozet ' + x[0]; s.textContent = x[1]; ozet.appendChild(s); });
            $('waTestJson').textContent = JSON.stringify(r.sonuc, null, 2);
            $('waTestSonuc').hidden = false;
        });
    });

    /* ── 4) Günlük ── */
    var logKutu = $('waLogKutu');
    function loglariYenile() {
        return post({ ajax: 'loglar' }).then(function (r) { if (r.ok) logKutu.innerHTML = r.html; });
    }
    $('waLogYenile').addEventListener('click', loglariYenile);
    setInterval(function () { if (!document.hidden) loglariYenile(); }, 20000);

    logKutu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-wa-eylem]');
        if (!b) return;
        var eylem = b.getAttribute('data-wa-eylem');
        var onay = { aktar: 'Bu mesaj yükleme planına TASLAK olarak aktarılsın mı?', onayla: 'Taslak onaylansın mı? Plan normal kayıt olur.' }[eylem];
        if (onay && !window.confirm(onay)) return;
        b.disabled = true;
        post({ ajax: eylem, id: b.getAttribute('data-id') }).then(function (r) {
            window.alert(r.ok ? (r.mesaj || 'Tamam.') : (r.hata || 'Hata oluştu.'));
            return loglariYenile();
        });
    });
})();
