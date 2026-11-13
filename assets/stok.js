/* =========================================================
   assets/stok.js — Stok (muhasebe) modülü istemcisi (stok_kartlari / stok_tanimlari; Parça B: fiş sayfaları)
   Yalnız #stok kökü olan sayfada çalışır. Yazmalar YALNIZ stok_kaydet.php'ye (fetch + X-Requested-With: fetch;
   JS kapalıyken aynı formlar klasik POST + 303 ile çalışır). app.js / cari.js'e DOKUNMAZ.
   Kancalar: form[data-st-ajax] · [data-st-modal="kart"] (+data-duzenle) · [data-st-kapat] · #stArama · [data-filtre] ·
             #stListe a.st-satir (≥1024 master-detail: ?parca=detay) · [data-tanim-duzen]/[data-tanim-vazgec] · input[role=switch] ·
             .st-tanim-nav-oge (sekme) · #stKartVeri (düzenleme verisi).
   ========================================================= */
(function () {
    'use strict';
    var KOK = document.getElementById('stok');
    if (!KOK) return;

    var S = {
        kaydet: 'stok_kaydet.php',
        kartlar: 'stok_kartlari.php',
        toastAnahtar: 'stok_toast',
        yonRe: /^(stok_[a-z_]+|transfer_fisleri)\.php(\?[^\r\n]*)?$/,
        baslik: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        m: { ag: 'Bağlantı hatası; tekrar deneyin.', kaydedildi: 'Kaydedildi', zorunlu: 'Zorunlu alan.' }
    };
    function $(s, k) { return (k || document).querySelector(s); }
    function $$(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }
    function trKucuk(s) { return String(s || '').replace(/İ/g, 'i').replace(/I/g, 'ı').toLocaleLowerCase('tr-TR'); }
    function depoOku(k) { try { return window.sessionStorage.getItem(k); } catch (x) { return null; } }
    function depoYaz(k, v) { try { if (v == null) window.sessionStorage.removeItem(k); else window.sessionStorage.setItem(k, v); } catch (x) { /* yok: sorun değil */ } }
    function guvenliYol(u) { return typeof u === 'string' && S.yonRe.test(u); }
    function masaustu() { return window.matchMedia('(min-width: 1024px)').matches; }

    /* ═════════ Toast (+ yenilemeye taşınan) ═════════ */
    var toastZ = null;
    function toast(msg, hata) {
        var t = $('.st-toast');
        if (!t) { t = document.createElement('div'); t.className = 'st-toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
        t.textContent = msg;
        t.classList.toggle('st-toast--hata', !!hata);
        t.hidden = false;
        clearTimeout(toastZ);
        toastZ = setTimeout(function () { t.hidden = true; }, hata ? 4500 : 2600);
    }
    function yenileToastla(msg, url) {
        if (msg) depoYaz(S.toastAnahtar, msg);
        if (url && guvenliYol(url) && url !== location.pathname.replace(/^.*\//, '') + location.search) location.assign(url); else location.reload();
    }
    (function () { var m = depoOku(S.toastAnahtar); if (m) { depoYaz(S.toastAnahtar, null); toast(m); } })();

    /* ═════════ Form hataları ═════════ */
    function hataTemizle(f) {
        $$('.st-hata', f).forEach(function (e) { e.remove(); });
        $$('[aria-invalid="true"]', f).forEach(function (e) { e.removeAttribute('aria-invalid'); });
        var g = $('.st-form-hata', f); if (g) { g.hidden = true; g.textContent = ''; }
    }
    function alanHata(f, ad, msg) {
        var el = $$('[name="' + ad + '"]', f).filter(function (x) { return x.type !== 'hidden'; })[0];
        if (!el) return null;
        el.setAttribute('aria-invalid', 'true');
        var s = document.createElement('span'); s.className = 'st-hata'; s.textContent = msg;
        var id = 'stH' + Math.random().toString(36).slice(2, 8); s.id = id; el.setAttribute('aria-describedby', id);
        (el.closest('.pm-label') || el.parentNode).appendChild(s);
        return el;
    }
    function genelHata(f, msg) {
        var g = $('.st-form-hata', f);
        if (g) { g.textContent = msg; g.hidden = false; } else toast(msg, true);
    }
    function yanitCoz(r) {
        return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) { j = null; } return { r: r, j: j }; });
    }

    /* ═════════ AJAX form gönderimi ═════════ */
    function gonder(f) {
        if (f.dataset.kilit === '1') return;
        if (f.getAttribute('data-onay') && !window.confirm(f.getAttribute('data-onay'))) return;
        hataTemizle(f);
        var eksik = $$('[required]', f).filter(function (e) { return !e.disabled && !String(e.value || '').trim(); });
        if (eksik.length) { eksik.forEach(function (e) { alanHata(f, e.name, S.m.zorunlu); }); eksik[0].focus(); return; }
        var btn = $$('button[type=submit], button:not([type])', f);
        f.dataset.kilit = '1'; btn.forEach(function (b) { b.disabled = true; });
        var fd = new FormData(f);
        fetch(f.getAttribute('action') || S.kaydet, { method: 'POST', body: fd, headers: S.baslik, credentials: 'same-origin' })
            .then(yanitCoz)
            .then(function (s) {
                f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; });
                if (s.j && s.j.ok) {
                    var msg = f.getAttribute('data-ok') || S.m.kaydedildi;
                    if (fd.get('islem') === 'ice_aktar' && s.j.eklenen != null) msg = 'İçe aktarıldı: ' + s.j.eklenen + ' kart eklendi, ' + (s.j.atlanan || 0) + ' atlandı';
                    if (s.j.uyari) msg += ' — ' + s.j.uyari;
                    yenileToastla(msg, s.j.yonlendir);
                    return;
                }
                if (s.j && s.j.alanlar) {
                    var ilk = null;
                    Object.keys(s.j.alanlar).forEach(function (ad) { var el = alanHata(f, ad, s.j.alanlar[ad]); if (el && !ilk) ilk = el; });
                    if (ilk && ilk.focus) ilk.focus();
                }
                genelHata(f, (s.j && s.j.hata) || ('İşlem yapılamadı (HTTP ' + s.r.status + ').'));
            })
            .catch(function () { f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; }); genelHata(f, S.m.ag); });
    }
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.matches || !f.matches('form[data-st-ajax]')) return;
        e.preventDefault();
        gonder(f);
    });
    // Aktif anahtarı: değişince formu gönder
    document.addEventListener('change', function (e) {
        var c = e.target;
        if (c.matches && c.matches('input[role="switch"]') && c.form && c.form.matches('form[data-st-ajax]')) gonder(c.form);
    });

    /* ═════════ Modal ═════════ */
    var acik = null, acan = null;
    function modalAc(id, tetik) {
        var m = document.getElementById(id); if (!m) return null;
        acik = m; acan = tetik || document.activeElement;
        m.hidden = false; document.body.style.overflow = 'hidden';
        setTimeout(function () { var o = $('[data-ilk-odak]', m) || $('input:not([type=hidden]), select, textarea', m); if (o) o.focus(); }, 30);
        return m;
    }
    function modalKapat() {
        if (!acik) return;
        acik.hidden = true; document.body.style.overflow = '';
        var a = acan; acik = null; acan = null;
        if (a && document.contains(a) && a.focus) a.focus();
    }
    function kartVeri() {
        var s = $('#stDetay #stKartVeri');
        if (!s) return null;
        try { return JSON.parse(s.textContent); } catch (x) { return null; }
    }
    function kartModalAc(duzenle, tetik) {
        var m = document.getElementById('stModalKart'); if (!m) return;
        var f = $('form', m);
        f.reset(); hataTemizle(f);
        var v = duzenle ? kartVeri() : null;
        var kilit = $('[data-birim-kilit]', m);
        if (v) {
            $('#stKartBaslik', m).textContent = 'Kartı düzenle';
            ['kod', 'ad', 'tip', 'ana_birim', 'cevrim_birim', 'cevrim_katsayi', 'kdv_orani', 'barkod', 'kritik_seviye', 'notlar'].forEach(function (k) {
                var el = f.elements[k]; if (el) el.value = v[k] == null ? '' : String(v[k]);
            });
            f.elements.grup_id.value = v.grup_id == null ? '' : String(v.grup_id);
            f.elements.aktif.checked = v.aktif === 1;
            f.elements.id.value = String(v.id);
            f.elements.geri.value = S.kartlar + '?id=' + v.id;
            var hareketli = !!v.hareketli;
            f.elements.ana_birim.disabled = false;
            if (kilit) kilit.hidden = !hareketli;
        } else {
            $('#stKartBaslik', m).textContent = 'Yeni stok kartı';
            f.elements.id.value = '';
            f.elements.kod.value = f.elements.kod.getAttribute('data-oneri') || '';
            f.elements.geri.value = S.kartlar;
            if (kilit) kilit.hidden = true;
        }
        modalAc('stModalKart', tetik);
    }
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-st-modal], [data-st-kapat]') : null;
        if (t && t.hasAttribute('data-st-kapat')) { e.preventDefault(); modalKapat(); return; }
        if (t && t.getAttribute('data-st-modal') === 'kart') { e.preventDefault(); kartModalAc(t.getAttribute('data-duzenle') === '1', t); return; }
        var ov = e.target;
        if (ov && ov.classList && ov.classList.contains('pm-overlay') && ov === acik) modalKapat();
    });
    document.addEventListener('keydown', function (e) {
        if (acik) {
            if (e.key === 'Escape') { e.preventDefault(); modalKapat(); return; }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { var f = $('form', acik); if (f) { e.preventDefault(); gonder(f); } return; }
            if (e.key === 'Tab') {   // odak tuzağı
                var od = $$('button, input:not([type=hidden]), select, textarea, a[href]', acik).filter(function (x) { return !x.disabled && x.getClientRects().length; });
                if (!od.length) return;
                var ilk = od[0], son = od[od.length - 1];
                if (e.shiftKey && document.activeElement === ilk) { e.preventDefault(); son.focus(); }
                else if (!e.shiftKey && document.activeElement === son) { e.preventDefault(); ilk.focus(); }
            }
            return;
        }
        var ar = document.getElementById('stArama');
        var yazim = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
        if (ar && e.key === '/' && !yazim) { e.preventDefault(); ar.focus(); ar.select(); return; }
        if (ar && document.activeElement === ar && e.key === 'Escape') { ar.value = ''; listeSuz(); return; }
        // ↑/↓ liste satırları arası (gerçek odak)
        var a = document.activeElement;
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && (a === ar || (a && a.classList && a.classList.contains('st-satir')))) {
            var gor = $$('#stListe li:not([hidden]) a.st-satir');
            if (!gor.length) return;
            var i = gor.indexOf(a);
            var j = e.key === 'ArrowDown' ? (i < 0 ? 0 : Math.min(gor.length - 1, i + 1)) : (i <= 0 ? 0 : i - 1);
            e.preventDefault(); gor[j].focus();
        }
    });

    /* ═════════ Kart listesi süzgeci ═════════ */
    var liste = document.getElementById('stListe');
    function durumSecili() { var b = $('.st-cip[aria-pressed="true"]', KOK); return b ? b.getAttribute('data-deger') : 'aktif'; }
    function listeSuz() {
        if (!liste) return;
        var q = trKucuk(($('#stArama') || {}).value || '').trim();
        var d = durumSecili();
        var tip = ($('select[data-filtre="tip"]', KOK) || {}).value || '';
        var grup = ($('select[data-filtre="grup"]', KOK) || {}).value || '';
        var n = 0;
        $$('li', liste).forEach(function (li) {
            var a = $('a.st-satir', li); if (!a) return;
            var pasif = a.getAttribute('data-pasif') === '1', kritik = a.getAttribute('data-kritik') === '1';
            var es = (d === 'tumu') || (d === 'aktif' && !pasif) || (d === 'pasif' && pasif) || (d === 'kritik' && kritik);
            if (es && tip && a.getAttribute('data-tip') !== tip) es = false;
            if (es && grup && a.getAttribute('data-grup') !== grup) es = false;
            if (es && q && (a.getAttribute('data-ara') || '').indexOf(q) < 0) es = false;
            li.hidden = !es; if (es) n++;
        });
        var sy = document.getElementById('stSayi'); if (sy) sy.textContent = n + ' kart';
        var yok = document.getElementById('stSonucYok'); if (yok) yok.hidden = n > 0 || !$$('li', liste).length;
        try {
            var u = new URL(location.href);
            [['ara', q], ['durum', d === 'aktif' ? '' : d], ['tip', tip], ['grup', grup]].forEach(function (p) { if (p[1]) u.searchParams.set(p[0], p[1]); else u.searchParams.delete(p[0]); });
            history.replaceState(history.state, '', u.pathname.replace(/^.*\//, '') + u.search);
        } catch (x) { /* kritik değil */ }
    }
    if (liste) {
        var ar = document.getElementById('stArama');
        if (ar) ar.addEventListener('input', listeSuz);
        $$('.st-cip[data-filtre="durum"]', KOK).forEach(function (b) {
            b.addEventListener('click', function () {
                $$('.st-cip[data-filtre="durum"]', KOK).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
                listeSuz();
            });
        });
        $$('select[data-filtre]', KOK).forEach(function (s) { s.addEventListener('change', listeSuz); });
    }

    /* ═════════ Master-detail (≥1024: sayfa yenilemeden detay) ═════════ */
    function detayYukle(id, href, push) {
        var d = document.getElementById('stDetay');
        if (d) d.classList.add('st-yukleniyor');
        return fetch(S.kartlar + '?id=' + encodeURIComponent(id) + '&parca=detay', { headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (h) {
                var t = document.createElement('div'); t.innerHTML = h;
                var yeni = $('#stDetay', t);
                if (!yeni) throw new Error('detay yok');
                var eski = document.getElementById('stDetay');
                eski.parentNode.replaceChild(yeni, eski);
                var ana = $('.st-ana', KOK); if (ana) ana.classList.add('st-ana--detay');
                $$('#stListe a.st-satir').forEach(function (a) { if (a.getAttribute('data-id') === String(id)) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
                var mf = $('#stModalKart form'); if (mf) mf.elements.geri.value = S.kartlar + '?id=' + id;
                if (push) { try { history.pushState({ stId: id }, '', href); } catch (x) { /* kritik değil */ } }
            })
            .catch(function () { location.assign(href); });
    }
    if (liste) {
        liste.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a.st-satir') : null;
            if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0 || !masaustu()) return;
            e.preventDefault();
            detayYukle(a.getAttribute('data-id'), a.getAttribute('href'), true);
        });
        window.addEventListener('popstate', function () {
            var id = new URLSearchParams(location.search).get('id');
            if (id && masaustu()) detayYukle(id, location.href, false); else location.reload();
        });
    }

    /* ═════════ Tanımlar: sekme + satır içi düzenleme ═════════ */
    var nav = $$('.st-tanim-nav-oge', KOK);
    nav.forEach(function (t) {
        t.addEventListener('click', function (e) {
            if (e.ctrlKey || e.metaKey) return;
            e.preventDefault();
            nav.forEach(function (x) {
                var on = x === t;
                x.setAttribute('aria-selected', on ? 'true' : 'false');
                var p = document.getElementById(x.getAttribute('aria-controls')); if (p) p.hidden = !on;
            });
            try { history.replaceState(history.state, '', t.getAttribute('href')); } catch (x) { /* kritik değil */ }
        });
    });
    KOK.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-tanim-duzen], [data-tanim-vazgec]') : null;
        if (!d) return;
        var li = d.closest('li');
        if (d.hasAttribute('data-tanim-duzen')) {
            var ed = li.nextElementSibling;
            if (ed && ed.classList.contains('st-tanim-satir--duzen')) { li.hidden = true; ed.hidden = false; var i = $('input[name="ad"]:not([readonly]), input[name="sira"]', ed); if (i) i.focus(); }
        } else {
            var on = li.previousElementSibling;
            li.hidden = true; if (on) { on.hidden = false; var b = $('[data-tanim-duzen]', on); if (b) b.focus(); }
        }
    });

    /* ═════════ Açılış ═════════ */
    if (KOK.getAttribute('data-yeni') === 'kart') {
        kartModalAc(false, null);
        try { var q = new URL(location.href); q.searchParams.delete('yeni'); history.replaceState(history.state, '', q.pathname.replace(/^.*\//, '') + q.search); } catch (x) { /* kritik değil */ }
    }
    if (liste) {
        var sec = $('#stListe a.st-satir[aria-current="true"]');
        if (sec && masaustu()) { try { sec.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ } }
        var id0 = new URLSearchParams(location.search).get('id');
        if (id0) { try { history.replaceState({ stId: id0 }, '', location.href); } catch (x) { /* kritik değil */ } }
    }
})();
