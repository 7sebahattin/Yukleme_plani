/* =========================================================
   assets/siparis.js — Siparişler modülü arayüzü (yalnız siparis*.php sayfalarında yüklenir)
   window.SiparisUI (IIFE). app.js'e dokunmaz. Satır içi onclick YOK: her şey data-sip-* öznitelikleriyle.
   Kullanıcı verisi yalnız textContent / value ile yazılır (innerHTML yalnız sunucunun kaçırdığı önizleme parçası).
   JS kapalıyken sayfalar çalışır: düzenleme ?duzenle= formuna, yükleme düz dosya girdisine düşer.
   ========================================================= */
(function () {
    'use strict';

    var sonOdak = null;
    var q = function (s, k) { return (k || document).querySelector(s); };
    var qa = function (s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); };

    function boyut(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

    /* ── pencereler ─────────────────────────────────────── */
    function doldur(modal, veri) {
        Object.keys(veri || {}).forEach(function (k) {
            var v = veri[k];
            if (k.indexOf('_yazi:') === 0) {
                qa('[data-sip-yazi="' + k.slice(6) + '"]', modal).forEach(function (e) { e.textContent = v == null ? '' : String(v); });
                return;
            }
            var e = qa('[name="' + k.replace(/"/g, '\\"') + '"]', modal)[0];
            if (!e) return;
            if (e.type === 'checkbox') e.checked = !!v; else e.value = v == null ? '' : String(v);
        });
    }
    function ac(modal, tetik) {
        if (!modal) return;
        sonOdak = tetik || document.activeElement;
        if (tetik && tetik.getAttribute('data-sip-doldur')) {
            try { doldur(modal, JSON.parse(tetik.getAttribute('data-sip-doldur'))); } catch (e) { /* bozuk JSON: boş aç */ }
        }
        if (modal.id === 'sipProfilModal') profilKopyala(modal);
        if (modal.id === 'sipDurumModal') gerekceGuncelle(q('form', modal));
        modal.hidden = false;
        var ilk = q('input:not([type=hidden]):not([disabled]):not([hidden]), textarea:not([disabled]), select', modal) || q('button', modal);
        if (ilk && window.matchMedia('(min-width: 768px)').matches) { try { ilk.focus({ preventScroll: true }); } catch (e) { /* yok say */ } }
    }
    function kapat(modal) {
        if (!modal) return;
        modal.hidden = true;
        if (sonOdak && document.contains(sonOdak)) { try { sonOdak.focus({ preventScroll: true }); } catch (e) { /* yok say */ } }
        if (modal.id === 'sipOnizleModal') { var g = q('[data-sip-onizle-govde]', modal); if (g) g.textContent = ''; }
    }
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-sip-ac]');
        if (t) { e.preventDefault(); ac(document.getElementById(t.getAttribute('data-sip-ac')), t); return; }
        var k = e.target.closest('[data-sip-kapat]');
        if (k) { kapat(k.closest('.pm-overlay')); return; }
        if (e.target.classList && e.target.classList.contains('pm-overlay') && e.target.closest('.sip-mod')) kapat(e.target);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var acik = qa('.sip-mod .pm-overlay').filter(function (m) { return !m.hidden; }).pop();
        if (acik) kapat(acik);
    });

    /* ── genel alan düzenleme penceresi ─────────────────── */
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-sip-alan]');
        if (!a) return;
        var modal = document.getElementById('sipAlanModal');
        if (!modal) return;
        e.preventDefault();
        var v;
        try { v = JSON.parse(a.getAttribute('data-sip-alan')); } catch (x) { return; }
        var f = q('form', modal);
        f.elements['varlik'].value = v.varlik; f.elements['id'].value = v.id; f.elements['alan'].value = v.alan;
        qa('[data-sip-yazi="etiket"]', modal).forEach(function (el) { el.textContent = v.etiket; });
        var tur = v.tip === 'tarih' ? 'tarih' : (v.tip === 'notlar' ? 'notlar' : 'metin');
        qa('[data-sip-girdi]', modal).forEach(function (g) {
            var bu = g.getAttribute('data-sip-girdi') === tur;
            g.hidden = !bu; g.disabled = !bu;
            if (bu) g.value = v.deger || '';
            if (g.getAttribute('data-sip-girdi') === 'metin') g.setAttribute('autocapitalize', v.tip === 'plaka' ? 'characters' : 'none');
        });
        var ip = q('[data-sip-yazi="ipucu"]', modal); if (ip) { ip.textContent = v.ipucu || ''; ip.hidden = !v.ipucu; }
        var kn = q('[data-sip-kilit-not]', modal), kb = q('[data-sip-kilit-btn]', modal);
        if (kn) kn.hidden = !v.kilitli; if (kb) kb.hidden = !v.kilitli;
        ac(modal, a);
    });

    /* ── yükleme formu: dosya listesi + ön denetim ──────── */
    function yukleme(form) {
        var input = q('input[type=file]', form), liste = q('[data-sip-dosya-liste]', form), hata = q('[data-sip-yukle-hata]', form), drop = q('[data-sip-drop]', form);
        if (!input) return;
        var maxDosya = +form.getAttribute('data-sip-max-dosya') || 15728640, maxAdet = +form.getAttribute('data-sip-max-adet') || 10, maxToplam = +form.getAttribute('data-sip-max-toplam') || 0;
        function denetle() {
            var dosyalar = Array.prototype.slice.call(input.files || []);
            if (liste) {
                liste.textContent = '';
                dosyalar.forEach(function (d) {
                    var li = document.createElement('li'), ad = document.createElement('span'), bo = document.createElement('span');
                    ad.className = 'sip-dosya-ad'; ad.textContent = d.name; bo.className = 'sip-soluk'; bo.textContent = boyut(d.size);
                    if (d.size > maxDosya) { li.className = 'sip-dosya-hatali'; bo.textContent += ' · çok büyük'; }
                    li.appendChild(ad); li.appendChild(bo); liste.appendChild(li);
                });
            }
            var toplam = dosyalar.reduce(function (t, d) { return t + d.size; }, 0), mesaj = '';
            if (dosyalar.length > maxAdet) mesaj = 'En çok ' + maxAdet + ' dosya seçebilirsiniz.';
            else if (dosyalar.some(function (d) { return d.size > maxDosya; })) mesaj = 'Bir dosya en çok ' + Math.round(maxDosya / 1048576) + ' MB olabilir.';
            else if (maxToplam && toplam > maxToplam) mesaj = 'Seçilen dosyalar çok büyük (toplam en çok ' + Math.floor(maxToplam / 1048576) + ' MB). Daha az dosya seçin.';
            if (hata) { hata.textContent = mesaj; hata.hidden = !mesaj; }
            return mesaj === '';
        }
        input.addEventListener('change', denetle);
        if (drop) {
            ['dragenter', 'dragover'].forEach(function (n) { drop.addEventListener(n, function () { drop.classList.add('sip-drop--uzerinde'); }); });
            ['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function () { drop.classList.remove('sip-drop--uzerinde'); }); });
        }
        form.addEventListener('submit', function (e) {
            if (!denetle()) { e.preventDefault(); if (hata) hata.focus(); return; }
            var b = q('[data-sip-gonder]', form);
            if (b) setTimeout(function () { b.disabled = true; b.textContent = 'Yükleniyor ve işleniyor…'; }, 0);
        });
    }

    /* ── çift gönderim + onay ───────────────────────────── */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.closest || !f.closest('.sip-mod')) return;
        var onay = f.getAttribute('data-sip-onay');
        if (onay && !window.confirm(onay)) { e.preventDefault(); return; }
        if (f.hasAttribute('data-sip-tek') && !e.defaultPrevented) {
            if (f.getAttribute('data-sip-gonderildi') === '1') { e.preventDefault(); return; }
            f.setAttribute('data-sip-gonderildi', '1');
            setTimeout(function () { qa('button[type=submit]', f).forEach(function (b) { b.disabled = true; }); }, 0);
        }
    }, true);
    window.addEventListener('pageshow', function (e) {          // geri tuşuyla dönüşte formlar yeniden kullanılabilsin
        if (!e.persisted) return;
        qa('form[data-sip-gonderildi]').forEach(function (f) { f.removeAttribute('data-sip-gonderildi'); qa('button[type=submit]', f).forEach(function (b) { b.disabled = false; }); });
    });

    /* ── filtre paneli ──────────────────────────────────── */
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-sip-filtre-toggle]');
        if (!t) return;
        var p = document.getElementById(t.getAttribute('aria-controls'));
        if (!p) return;
        var acik = !p.classList.contains('sip-acik');
        p.classList.toggle('sip-acik', acik);
        t.setAttribute('aria-expanded', acik ? 'true' : 'false');
    });

    /* ── durum penceresi: gerekçe ───────────────────────── */
    function gerekceGuncelle(f) {
        if (!f || !f.hasAttribute('data-sip-durum-form')) return;
        var secili = q('input[name=hedef]:checked', f), kap = q('[data-sip-gerekce-kap]', f), ta = q('[data-sip-gerekce]', f);
        var gerek = f.getAttribute('data-sip-kapali') === '1' || (secili && secili.value === 'iptal');
        if (kap) kap.hidden = !gerek;
        if (ta) { ta.required = !!gerek; ta.minLength = gerek ? 3 : 0; }
    }
    document.addEventListener('change', function (e) {
        var f = e.target.closest ? e.target.closest('[data-sip-durum-form]') : null;
        if (f) gerekceGuncelle(f);
        var m = e.target.closest ? e.target.closest('[data-sip-mail-form]') : null;
        if (m) {
            var hepsi = qa('[data-sip-mail-onay]', m).every(function (c) { return c.checked; });
            var b = q('[data-sip-mail-gonder]', m); if (b) b.disabled = !hepsi;
        }
    });

    /* ── ZIP seçimi ─────────────────────────────────────── */
    function zipGuncelle() {
        var sec = qa('input[data-sip-zip]:checked'), eski = q('[data-sip-eski]'), eskiDahil = !!(eski && eski.checked);
        var btn = q('[data-sip-zip-secili]'), hepsi = q('[data-sip-zip-hepsi]');
        if (btn) {
            btn.disabled = sec.length === 0;
            btn.title = sec.length ? sec.length + ' belge seçili' : 'Önce listeden belge seçin';
            btn.setAttribute('data-href', 'siparis_zip.php?id=' + btn.getAttribute('data-id') + sec.map(function (c) { return '&belge[]=' + encodeURIComponent(c.value); }).join('') + (eskiDahil ? '&eski=1' : ''));
        }
        if (hepsi) {
            var id = (btn && btn.getAttribute('data-id')) || '';
            hepsi.setAttribute('href', 'siparis_zip.php?id=' + id + (eskiDahil ? '&eski=1' : ''));
        }
    }
    document.addEventListener('change', function (e) { if (e.target.matches && (e.target.matches('input[data-sip-zip]') || e.target.matches('[data-sip-eski]'))) zipGuncelle(); });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-sip-zip-secili]');
        if (b && !b.disabled) window.location.href = b.getAttribute('data-href');
    });

    /* ── belge önizleme penceresi (masaüstü ≥1024) ──────── */
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-sip-onizle]');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        var modal = document.getElementById('sipOnizleModal');
        if (!modal || !window.matchMedia('(min-width: 1024px)').matches) return;   // dar ekranda belge sayfasına gider
        e.preventDefault();
        var id = a.getAttribute('data-id'), govde = q('[data-sip-onizle-govde]', modal);
        q('#sipOnizleBaslik', modal).textContent = a.getAttribute('data-ad') || 'Belge önizleme';
        q('[data-sip-onizle-sayfa]', modal).setAttribute('href', a.getAttribute('href'));
        q('[data-sip-onizle-indir]', modal).setAttribute('href', 'siparis_dosya.php?id=' + encodeURIComponent(id) + '&dl=1');
        govde.textContent = 'Yükleniyor…';
        ac(modal, a);
        fetch('siparis_belge.php?id=' + encodeURIComponent(id) + '&parca=1', { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function (h) { govde.innerHTML = h; })       // sunucunun kaçırdığı parça (h()); kullanıcı verisi içinde ham HTML yok
            .catch(function () { govde.textContent = 'Önizleme yüklenemedi. Belge sayfasını açmayı deneyin.'; });
    });

    /* ── kopyala ────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-sip-kopyala]');
        if (!b) return;
        var m = b.getAttribute('data-sip-kopyala'), eski = b.textContent;
        var bitti = function () { b.textContent = 'Kopyalandı ✓'; setTimeout(function () { b.textContent = eski; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(m).then(bitti, function () { /* izin yok */ });
        else { var t = document.createElement('textarea'); t.value = m; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); bitti(); } catch (x) { /* yok say */ } document.body.removeChild(t); }
    });

    /* ── PKL düzenleyici ────────────────────────────────── */
    function profilKopyala(modal) {
        var ana = document.getElementById('sipPklForm');
        if (!ana) return;
        qa('[data-sip-profil-kopya]', modal).forEach(function (h) {
            var k = ana.elements['varsayilan[' + h.getAttribute('data-sip-profil-kopya') + ']'];
            h.value = k ? k.value : '';
        });
    }
    function pkl(form) {
        var m = document.getElementById('sipM'), n = document.getElementById('sipN'), dara = +form.getAttribute('data-sip-dara') || 90;
        var nOtomatik = n && n.value === '';
        if (m && n) {
            n.addEventListener('input', function () { nOtomatik = false; });
            m.addEventListener('input', function () {
                var v = parseFloat(String(m.value).replace(/\./g, '').replace(',', '.'));
                if (!isNaN(v) && (n.value === '' || nOtomatik)) { n.value = String(Math.max(0, Math.round(v - dara))); nOtomatik = true; }
            });
        }
        var eta = q('[data-sip-eta]', form);
        if (eta) eta.addEventListener('click', function () { var i = document.getElementById('sipEta'); if (i) i.value = eta.getAttribute('data-sip-eta'); });
        var pr = q('[data-sip-profil]', form);
        if (pr) pr.addEventListener('change', function () {
            var o = pr.options[pr.selectedIndex], veri = {};
            try { veri = JSON.parse(o.getAttribute('data-v') || '{}'); } catch (x) { /* boş */ }
            if (!o.value) return;
            Object.keys(veri).forEach(function (k) { var el = form.elements['varsayilan[' + k + ']']; if (el) el.value = veri[k] || ''; });
        });
        var say = q('[data-sip-sayac]', form), hedef = +form.getAttribute('data-sip-etkin-palet') || 0;
        function topla() {
            var t = qa('[data-sip-blok-palet]', form).reduce(function (s, i) { return s + (parseInt(i.value, 10) || 0); }, 0);
            if (!say) return;
            q('[data-sip-sayac-toplam]', say).textContent = String(t);
            say.classList.toggle('sip-sayac--hata', hedef > 0 && t !== hedef);
            say.title = hedef > 0 && t !== hedef ? 'Bloklardaki palet toplamı sevkiyatın etkin paletinden farklı' : '';
        }
        form.addEventListener('input', function (e) { if (e.target.hasAttribute && e.target.hasAttribute('data-sip-blok-palet')) topla(); });
        topla();
    }

    function basla() {
        qa('form[data-sip-upload]').forEach(yukleme);
        var p = q('form[data-sip-pkl]'); if (p) pkl(p);
        zipGuncelle();
        qa('[data-sip-durum-form]').forEach(gerekceGuncelle);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', basla); else basla();

    window.SiparisUI = { ac: function (id) { ac(document.getElementById(id), null); }, kapat: function (id) { kapat(document.getElementById(id)); } };
})();
