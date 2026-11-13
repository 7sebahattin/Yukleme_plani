/* =========================================================
   assets/stok.js — Stok (muhasebe) modülü istemcisi (stok_kartlari / stok_tanimlari / 4 fiş sayfası)
   Yalnız #stok kökü olan sayfada çalışır. Yazmalar YALNIZ stok_kaydet.php'ye (fetch + X-Requested-With: fetch;
   JS kapalıyken aynı formlar klasik POST + 303 ile çalışır). app.js / cari.js'e DOKUNMAZ.
   Kancalar: form[data-st-ajax] · [data-st-modal="kart"] (+data-duzenle) · [data-st-kapat] · #stArama · [data-filtre] ·
             #stListe a.st-satir (≥1024 master-detail: ?parca=detay) · [data-tanim-duzen]/[data-tanim-vazgec] · input[role=switch] ·
             .st-tanim-nav-oge (sekme) · #stKartVeri (düzenleme verisi).
   Fiş sayfaları (#stok[data-sayfa="fis"]): #stFisForm[data-st-fis] (fetch JSON kaydet; 409 yetersiz_stok → #stEksik + "Yine de kaydet") ·
             #stFisVeri (kartlar/bakiye/cariler) · #stSatirSablon · [data-satir-ekle] · [data-satir-sil] · [data-lot-ac] · [data-depo-takas] ·
             [data-st-modal="iptal"|"kantar"] · form[data-st-kantar-form] · [data-oto-gonder] · [data-yazdir].
             Klavye: Enter sonraki hücre (son hücrede yeni satır) · Ctrl+S / Ctrl+Enter kaydet · Ctrl+↓ yeni satır · Ctrl+Del satır sil · Esc.
   ========================================================= */
(function () {
    'use strict';
    var KOK = document.getElementById('stok');
    if (!KOK) return;
    var FIS = KOK.getAttribute('data-sayfa') === 'fis';

    var S = {
        kaydet: 'stok_kaydet.php',
        kartlar: 'stok_kartlari.php',
        sayfa: (location.pathname.replace(/^.*\//, '') || 'stok_kartlari.php'),
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
    /** Sunucu kısmi yanıtını (kendi stok_*.php sayfamızın ?parca= çıktısı) DOM'a çevirir — modüldeki TEK HTML ayrıştırma noktası. */
    function htmlParca(h) { var t = document.createElement('div'); t.innerHTML = h; return t; }
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
        if (t && t.getAttribute('data-st-modal') === 'iptal') { e.preventDefault(); iptalModalAc(t); return; }
        if (t && t.getAttribute('data-st-modal') === 'kantar') { e.preventDefault(); modalAc('stModalKantar', t); return; }
        var yz = e.target.closest ? e.target.closest('[data-yazdir]') : null;
        if (yz) { e.preventDefault(); window.print(); return; }
        var ov = e.target;
        if (ov && ov.classList && ov.classList.contains('pm-overlay') && ov === acik) modalKapat();
    });
    document.addEventListener('keydown', function (e) {
        if (acik) {
            if (e.key === 'Escape') { e.preventDefault(); modalKapat(); return; }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                var f = $('form', acik);
                if (f) { e.preventDefault(); if (f.matches('form[data-st-ajax]')) gonder(f); else if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
                return;
            }
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
        if (ar && document.activeElement === ar && e.key === 'Escape') { ar.value = ''; if (!FIS) listeSuz(); return; }
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
    var kartListe = FIS ? null : liste;   // istemci süzgeci yalnız kart sayfasında (fiş listesi sunucuda süzülür: GET formu)
    function durumSecili() { var b = $('.st-cip[aria-pressed="true"]', KOK); return b ? b.getAttribute('data-deger') : 'aktif'; }
    function listeSuz() {
        if (!kartListe) return;
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
    if (kartListe) {
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
        return fetch(S.sayfa + '?id=' + encodeURIComponent(id) + '&parca=detay', { headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (h) {
                var t = htmlParca(h);
                var yeni = $('#stDetay', t);
                if (!yeni) throw new Error('detay yok');
                var eski = document.getElementById('stDetay');
                eski.parentNode.replaceChild(yeni, eski);
                var ana = $('.st-ana', KOK); if (ana) { ana.classList.add('st-ana--detay'); ana.classList.remove('st-ana--form'); }
                $$('#stListe a.st-satir').forEach(function (a) { if (a.getAttribute('data-id') === String(id)) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
                var mf = $('#stModalKart form'); if (mf) mf.elements.geri.value = S.kartlar + '?id=' + id;
                if (FIS) { var dk = $('.st-fis-icerik', yeni) || yeni; try { dk.scrollTop = 0; } catch (x) { /* önemsiz */ } }
                if (push) { try { history.pushState({ stId: id }, '', href); } catch (x) { /* kritik değil */ } }
            })
            .catch(function () { location.assign(href); });
    }
    if (liste) {
        liste.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a.st-satir') : null;
            if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0 || !masaustu()) return;
            if (FIS && document.getElementById('stFisForm') && fisDegisti() && !window.confirm('Kaydedilmemiş fiş formu kapanacak. Devam edilsin mi?')) { e.preventDefault(); return; }
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

    /* ═════════ Fiş sayfaları (Parça B) ═════════ */
    // İptal modalı: tetikleyicinin data-id / data-fis-no / data-geri değerleriyle (master-detail ile yüklenen detay için de)
    function iptalModalAc(t) {
        var m = document.getElementById('stModalIptal'); if (!m) return;
        var f = $('form', m);
        f.reset(); hataTemizle(f);
        f.elements.id.value = t.getAttribute('data-id') || '';
        f.elements.geri.value = t.getAttribute('data-geri') || S.sayfa;
        var no = $('[data-iptal-no]', m); if (no) no.textContent = t.getAttribute('data-fis-no') || '';
        iptalSayac(f);
        modalAc('stModalIptal', t);
    }
    function iptalSayac(f) {
        var ta = $('[data-iptal-neden]', f), n = ta ? ta.value.trim().length : 0;
        var b = $('[data-iptal-onay]', f); if (b) b.disabled = n < 3;
        var sy = $('[data-sayac]', f); if (sy) sy.textContent = n < 3 ? 'En az 3 karakter' : n + ' / 500';
    }
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('[data-iptal-neden]')) iptalSayac(t.form);
        if (t && t.id === 'stKantarAra') {
            var q = trKucuk(t.value).trim();
            $$('#stModalKantar .st-kaynak-satir').forEach(function (l) { l.parentNode.hidden = !!q && (l.getAttribute('data-ara') || '').indexOf(q) < 0; });
        }
    });
    // Süzgeç seçimleri: değişince GET formunu gönder
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('select[data-oto-gonder]') && t.form) t.form.submit();
    });

    var fisDegistiBayrak = false;
    function fisDegisti() { return fisDegistiBayrak; }

    var FF = document.getElementById('stFisForm');
    if (FF) fisFormKur(FF);

    function fisFormKur(f) {
        var V = {};
        try { V = JSON.parse((document.getElementById('stFisVeri') || {}).textContent || '{}'); } catch (x) { V = {}; }
        var tur = f.getAttribute('data-tur');
        var fiyatli = tur === 'giris' || tur === 'cikis';
        var kartlar = V.kartlar || [], cariler = V.cariler || [], bakiye = V.bakiye || {};
        var kartId = {}; kartlar.forEach(function (k) { kartId[String(k.id)] = k; });
        var liste = S.sayfa.replace(/\?.*$/, '');

        function katla(s) { return String(s || '').replace(/[İIı]/g, 'i').toLowerCase().trim(); }
        function sayiOku(v) {
            var s = String(v || '').replace(/[\s ₺$€]/g, '');
            if (!s) return NaN;
            if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
            else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
            return /^\d+(\.\d+)?$/.test(s) ? parseFloat(s) : NaN;
        }
        function tam(b) { return b !== 'lt' && b !== 'm'; }
        function miktarBicim(v, b) { return Number(v).toLocaleString('tr-TR', { maximumFractionDigits: tam(b) ? 0 : 3 }); }
        function para(kurus) { return (kurus / 100).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function kartBul(metin) {
            metin = String(metin || '').trim(); if (!metin) return null;
            var kod = katla(metin.split(' — ')[0]), i;
            for (i = 0; i < kartlar.length; i++) if (katla(kartlar[i].kod) === kod) return kartlar[i];
            for (i = 0; i < kartlar.length; i++) if (kartlar[i].barkod && String(kartlar[i].barkod) === metin) return kartlar[i];
            var ad = kartlar.filter(function (k) { return katla(k.ad) === katla(metin); });
            return ad.length === 1 ? ad[0] : null;
        }
        function cariBul(metin) {
            metin = String(metin || '').trim(); if (!metin) return null;
            var kod = katla(metin.split(' — ')[0]);
            for (var i = 0; i < cariler.length; i++) if (katla(cariler[i].kod) === kod || katla(cariler[i].unvan) === katla(metin)) return cariler[i];
            return null;
        }
        function el(tr, ad) { return $('[name="' + ad + '[]"]', tr); }
        function satirlar() { return $$('.st-fsatir', f); }
        function satirKart(tr) { return kartId[el(tr, 'kart_id').value] || null; }
        function satirDolu(tr) { return !!(el(tr, 'kart_id').value || el(tr, 'kart_ara').value.trim() || el(tr, 'miktar').value.trim()); }
        function depo() { return f.elements.depo ? f.elements.depo.value : ''; }

        function birimKur(tr, k, secili) {
            var s = el(tr, 'birim'); while (s.firstChild) s.removeChild(s.firstChild);
            var o = document.createElement('option'); o.value = ''; o.textContent = k ? k.birim : '—'; s.appendChild(o);
            if (k && k.cevrim_birim && k.cevrim_katsayi) {
                o = document.createElement('option'); o.value = k.cevrim_birim;
                o.textContent = k.cevrim_birim + ' (×' + miktarBicim(k.cevrim_katsayi, 'lt') + ' ' + k.birim + ')';
                if (secili === k.cevrim_birim) o.selected = true;
                s.appendChild(o);
            }
            el(tr, 'miktar').setAttribute('inputmode', k && !tam(k.birim) ? 'decimal' : 'numeric');
        }
        function kartSec(tr, k, yaz) {
            el(tr, 'kart_id').value = k ? String(k.id) : '';
            birimKur(tr, k, '');
            if (k && yaz !== false) el(tr, 'kart_ara').value = k.kod + ' — ' + k.ad;
        }
        /** Satırın ana birim miktarı (sayı) ya da NaN */
        function anaMiktar(tr) {
            var k = satirKart(tr), m = sayiOku(el(tr, 'miktar').value);
            if (!k || isNaN(m)) return NaN;
            var b = el(tr, 'birim').value;
            return b && b === k.cevrim_birim ? m * Number(k.cevrim_katsayi || 0) : m;
        }
        function satirGuncelle(tr) {
            var k = satirKart(tr), ana = anaMiktar(tr), ip = $('.st-fs-ipucu', tr), cv = $('.st-cevrim', tr);
            var yon = tr.getAttribute('data-yon');
            var dusen = tur === 'cikis' || tur === 'transfer' || (tur === 'donusum' && yon === 'C');
            tr.classList.remove('st-fsatir--eksik');
            if (cv) cv.textContent = k && el(tr, 'birim').value && !isNaN(ana) ? '= ' + miktarBicim(ana, k.birim) + ' ' + k.birim : '';
            if (ip) {
                if (!k) ip.textContent = el(tr, 'kart_ara').value.trim() ? 'Kart bulunamadı — listeden seçin' : '';
                else {
                    var bk = ((bakiye[k.id] || {})[depo()] || 0) / 1000;
                    var t = 'Mevcut: ' + miktarBicim(bk, k.birim) + ' ' + k.birim + (k.ort ? ' · ort ' + para(Math.round(k.ort)) + ' ₺/' + k.birim : '');
                    if (dusen && !isNaN(ana) && ana > bk + 1e-9) { tr.classList.add('st-fsatir--eksik'); t += ' — yetersiz'; }
                    ip.textContent = t;
                }
            }
            var out = $('.st-fs-tutar-deger', tr);
            if (out) {
                var fy = sayiOku(el(tr, 'birim_fiyat') ? el(tr, 'birim_fiyat').value : '');
                out.textContent = !isNaN(ana) && !isNaN(fy) ? para(Math.round(ana * fy * 100)) : '—';
            }
        }
        function toplamlar() {
            var mik = 0, tut = 0, kdv = 0, g = 0, c = 0, cDeger = 0;
            satirlar().forEach(function (tr) {
                var k = satirKart(tr), ana = anaMiktar(tr); if (!k || isNaN(ana)) return;
                var yon = tr.getAttribute('data-yon');
                if (tur === 'donusum') { if (yon === 'C') { c += ana; cDeger += ana * (k.ort || 0); } else g += ana; return; }
                mik += ana;
                var fy = sayiOku(el(tr, 'birim_fiyat') ? el(tr, 'birim_fiyat').value : '');
                if (!isNaN(fy)) {
                    var t = Math.round(ana * fy * 100); tut += t;
                    var kv = el(tr, 'kdv_orani') ? el(tr, 'kdv_orani').value : '';
                    kdv += Math.round(t * (kv === '' ? (k.kdv || 0) : Number(kv)) / 100);
                }
            });
            var set = function (ad, v) { var b = $('[data-top="' + ad + '"]', f.closest('.st-detay') || f); if (b) b.textContent = v; };
            if (tur === 'donusum') {
                set('miktar', miktarBicim(c, 'lt') + ' → ' + miktarBicim(g, 'lt'));
                set('fark', miktarBicim(c - g, 'lt') + (c > 0 ? ' (%' + ((c - g) / c * 100).toLocaleString('tr-TR', { maximumFractionDigits: 1 }) + ')' : ''));
                set('cikti_ort', g > 0 && cDeger > 0 ? para(Math.round(cDeger / g)) + ' ₺/birim' : '—');
            } else set('miktar', miktarBicim(mik, 'lt'));
            set('tutar', para(tut)); set('kdv', para(kdv));
        }
        function hepsi() { satirlar().forEach(satirGuncelle); toplamlar(); }
        function numarala() { $$('.st-satirlar', f).forEach(function (g) { $$('.st-fsatir', g).forEach(function (tr, i) { var n = $('.st-fs-no', tr); if (n) n.textContent = String(i + 1); }); }); }
        function satirEkle(grup, odak) {
            var sb = document.getElementById('stSatirSablon');
            var tr = sb.content.firstElementChild.cloneNode(true), yon = grup.getAttribute('data-yon');
            tr.setAttribute('data-yon', yon); el(tr, 'yon').value = yon;
            $('.st-fsatirlar', grup).appendChild(tr);
            numarala(); fisDegistiBayrak = true;
            if (odak) $('.st-kart-ara', tr).focus();
            return tr;
        }
        function satirSil(tr) {
            var grup = tr.closest('.st-satirlar'), kardes = $$('.st-fsatir', grup), i = kardes.indexOf(tr);
            if (kardes.length > 1) { tr.parentNode.removeChild(tr); var y = $$('.st-fsatir', grup); var o = y[Math.min(i, y.length - 1)]; if (o) $('.st-kart-ara', o).focus(); }
            else { $$('input:not([type=hidden])', tr).forEach(function (x) { x.value = ''; }); kartSec(tr, null); el(tr, 'kart_ara').value = ''; $('.st-kart-ara', tr).focus(); }
            numarala(); hepsi(); fisDegistiBayrak = true;
        }
        /** yaz=true: çözülen kartın "KOD — Ad" metni alana yazılır (change / Enter / odak çıkışı); yazarken (input) metne dokunulmaz. */
        function kartGirdi(inp, yaz) {
            var tr = inp.closest('.st-fsatir'), k = kartBul(inp.value);
            if (k !== satirKart(tr)) kartSec(tr, k, false);
            if (k && yaz) inp.value = k.kod + ' — ' + k.ad;
            satirGuncelle(tr); toplamlar();
        }
        function cariGirdi() {
            var inp = f.elements.cari_ara; if (!inp) return;
            var c = cariBul(inp.value);
            f.elements.cari_id.value = c ? String(c.id) : '';
            if (tur === 'cikis') f.classList.toggle('st-form--fiyatsiz', !c);
            if (c && f.elements.para_birimi && c.pb && f.elements.para_birimi.value !== c.pb && !fisDegistiPb) { f.elements.para_birimi.value = c.pb; pbGuncelle(); }
        }
        var fisDegistiPb = false;
        function pbGuncelle() {
            var pb = f.elements.para_birimi, kur = f.elements.kur; if (!pb || !kur) return;
            kur.disabled = pb.value === 'TRY'; if (kur.disabled) kur.value = '';
        }

        // — olaylar —
        f.addEventListener('input', function (e) {
            var t = e.target; fisDegistiBayrak = true;
            if (t.classList.contains('st-kart-ara')) { kartGirdi(t, false); return; }
            if (t.name === 'cari_ara') { cariGirdi(); return; }
            var tr = t.closest('.st-fsatir'); if (tr) satirGuncelle(tr);
            toplamlar();
        });
        f.addEventListener('change', function (e) {
            var t = e.target; fisDegistiBayrak = true;
            if (t.classList.contains('st-kart-ara')) { kartGirdi(t, true); return; }
            if (t.name === 'cari_ara') { cariGirdi(); return; }
            if (t.name === 'para_birimi') { fisDegistiPb = true; pbGuncelle(); }
            if (t.name === 'depo') { hepsi(); return; }
            var tr = t.closest('.st-fsatir'); if (tr) satirGuncelle(tr);
            toplamlar();
        });
        f.addEventListener('focusout', function (e) {
            var t = e.target;
            if (t.classList && t.classList.contains('st-kart-ara')) { var k = satirKart(t.closest('.st-fsatir')); if (k) t.value = k.kod + ' — ' + k.ad; }
        });
        f.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-satir-ekle], [data-satir-sil], [data-lot-ac], [data-depo-takas], [data-eksik-duzelt], [data-eksik-kaydet]') : null;
            if (!b) return;
            e.preventDefault();
            if (b.hasAttribute('data-satir-ekle')) satirEkle(b.closest('.st-satirlar'), true);
            else if (b.hasAttribute('data-satir-sil')) satirSil(b.closest('.st-fsatir'));
            else if (b.hasAttribute('data-lot-ac')) { var td = b.closest('td'), l = $('.st-fs-lot', td); l.classList.remove('st-fs-lot--kapali'); b.hidden = true; $('input', l).focus(); }
            else if (b.hasAttribute('data-depo-takas')) {
                var a = f.elements.depo, h = f.elements.hedef_depo, av = a.value, hv = h.value;
                if ($$('option', a).some(function (o) { return o.value === hv; })) { a.value = hv; h.value = av; hepsi(); }
                else toast('Hedef depo aktif deponuz değil; takas için önce depo değiştirin.', true);
            }
            else if (b.hasAttribute('data-eksik-duzelt')) { eksikGizle(); var ek = $('.st-fsatir--eksik [name="miktar[]"]', f) || $('[name="miktar[]"]', f); if (ek) ek.focus(); }
            else if (b.hasAttribute('data-eksik-kaydet')) kaydet(true);
        });
        function odaklanabilir(kok) {
            return $$('input:not([type=hidden]), select', kok).filter(function (x) { return !x.disabled && x.getClientRects().length && !x.closest('[hidden]'); });
        }
        function sonraki(t) {
            var tr = t.closest('.st-fsatir');
            if (tr) {
                var h = odaklanabilir(tr), i = h.indexOf(t);
                if (i >= 0 && i < h.length - 1) { h[i + 1].focus(); if (h[i + 1].select && h[i + 1].tagName === 'INPUT') h[i + 1].select(); return; }
                var grup = tr.closest('.st-satirlar'), ss = $$('.st-fsatir', grup);
                if (tr === ss[ss.length - 1]) { if (satirDolu(tr)) satirEkle(grup, true); return; }
                var n = odaklanabilir(tr.nextElementSibling)[0]; if (n) n.focus();
                return;
            }
            var tum = odaklanabilir($('.st-form-govde', f)), j = tum.indexOf(t);
            if (j >= 0 && j < tum.length - 1) tum[j + 1].focus();
        }
        f.addEventListener('keydown', function (e) {
            var t = e.target;
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S' || e.key === 'Enter')) { e.preventDefault(); kaydet(false); return; }
            var tr = t.closest ? t.closest('.st-fsatir') : null;
            if (e.ctrlKey && e.key === 'ArrowDown') { e.preventDefault(); var g = (tr || t).closest('.st-satirlar') || $('.st-satirlar', f); satirEkle(g, true); return; }
            if (e.ctrlKey && e.key === 'Delete' && tr) { e.preventDefault(); satirSil(tr); return; }
            if (e.key === 'Escape') {
                if (!$('#stEksik').hidden) { e.preventDefault(); eksikGizle(); return; }
                if (fisDegistiBayrak && window.confirm('Kaydedilmemiş değişiklikler var. Fiş formundan çıkılsın mı?')) { fisDegistiBayrak = false; location.assign(liste); }
                return;
            }
            if (e.key === 'Enter' && !e.shiftKey && !e.altKey && !e.ctrlKey && !e.metaKey && t.matches && t.matches('input:not([type=submit]):not([type=checkbox]), select')) {
                e.preventDefault();
                if (t.classList.contains('st-kart-ara')) kartGirdi(t, true);
                sonraki(t);
            }
        });
        f.addEventListener('submit', function (e) { e.preventDefault(); kaydet(false); });
        window.addEventListener('beforeunload', function (e) { if (fisDegistiBayrak && f.dataset.kilit !== '1' && !f.dataset.bitti) { e.preventDefault(); e.returnValue = ''; } });

        // — yetersiz stok —
        function eksikGizle() { $('#stEksik').hidden = true; }
        function eksikGoster(list) {
            var kutu = $('#stEksik'), tb = $('tbody', kutu);
            while (tb.firstChild) tb.removeChild(tb.firstChild);
            var idler = {};
            (list || []).forEach(function (x) {
                idler[String(x.kart_id)] = true;
                var r = document.createElement('tr');
                [x.kod + ' — ' + x.ad, x.depo || '—', miktarBicim(x.istenen_milli / 1000, x.birim) + ' ' + x.birim, miktarBicim(x.mevcut_milli / 1000, x.birim) + ' ' + x.birim, miktarBicim(x.eksik_milli / 1000, x.birim) + ' ' + x.birim]
                    .forEach(function (v, i) { var td = document.createElement('td'); td.textContent = v; if (i > 1) td.className = 'st-sayi'; r.appendChild(td); });
                tb.appendChild(r);
            });
            satirlar().forEach(function (tr) { if (idler[el(tr, 'kart_id').value] && tr.getAttribute('data-yon') !== 'G') tr.classList.add('st-fsatir--eksik'); });
            kutu.hidden = false;
            try { kutu.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ }
            var d = $('[data-eksik-duzelt]', kutu); if (d) d.focus();
        }
        // — kaydet (fetch JSON; klasik form da çalışır) —
        function satirHata(i, alan, msg) {
            var tr = satirlar()[Number(i)]; if (!tr) return null;
            tr.classList.add('st-fsatir--hata');
            var x = alan === 'kart_id' ? el(tr, 'kart_ara') : el(tr, alan === 'aciklama' ? 'satir_aciklama' : alan);
            if (!x || x.type === 'hidden') x = el(tr, 'kart_ara');
            x.setAttribute('aria-invalid', 'true');
            var s = document.createElement('span'); s.className = 'st-hata'; s.textContent = msg;
            var id = 'stH' + Math.random().toString(36).slice(2, 8); s.id = id; x.setAttribute('aria-describedby', id);
            x.closest('td').appendChild(s);
            return x;
        }
        function kaydet(eksiye) {
            if (f.dataset.kilit === '1') return;
            hataTemizle(f);
            satirlar().forEach(function (tr) { tr.classList.remove('st-fsatir--hata'); });
            if (!eksiye) eksikGizle();
            var eksik = $$('[required]', f).filter(function (x) { return !x.disabled && !String(x.value || '').trim(); });
            if (eksik.length) { eksik.forEach(function (x) { alanHata(f, x.name, S.m.zorunlu); }); eksik[0].focus(); return; }
            if (!satirlar().some(satirDolu)) { genelHata(f, 'En az bir satır girin.'); $('.st-kart-ara', f).focus(); return; }
            f.elements.eksiye_izin.value = eksiye ? '1' : '0';
            var btn = $('[data-kaydet]', f), etiket = btn.textContent;
            f.dataset.kilit = '1'; btn.disabled = true; btn.textContent = 'Kaydediliyor…';
            var bitir = function () { f.dataset.kilit = ''; btn.disabled = false; btn.textContent = etiket; };
            fetch(S.kaydet, { method: 'POST', body: new FormData(f), headers: S.baslik, credentials: 'same-origin' })
                .then(yanitCoz)
                .then(function (s) {
                    var j = s.j;
                    if (j && j.ok) {
                        f.dataset.bitti = '1'; fisDegistiBayrak = false;
                        var msg = (j.tekrar ? 'Fiş zaten kaydedilmişti' : 'Fiş kaydedildi') + (j.fis_no ? ' · ' + j.fis_no : '') + (j.uyari ? ' — ' + j.uyari : '');
                        yenileToastla(msg, j.yonlendir);
                        return;
                    }
                    bitir();
                    if (j && j.kod === 'yetersiz_stok') { eksikGoster(j.eksikler); return; }
                    var ilk = null;
                    if (j && j.alanlar) Object.keys(j.alanlar).forEach(function (ad) { var x = alanHata(f, ad, j.alanlar[ad]); if (x && !ilk) ilk = x; });
                    if (j && j.satir_hatalari) Object.keys(j.satir_hatalari).forEach(function (i) {
                        Object.keys(j.satir_hatalari[i]).forEach(function (a) { var x = satirHata(i, a, j.satir_hatalari[i][a]); if (x && !ilk) ilk = x; });
                    });
                    genelHata(f, (j && j.hata) || ('Kaydedilemedi (HTTP ' + s.r.status + ').'));
                    if (ilk && ilk.focus) ilk.focus();
                })
                .catch(function () { bitir(); genelHata(f, S.m.ag); });
        }

        // — Kantardan doldur (giriş) —
        var kf = document.querySelector('form[data-st-kantar-form]');
        if (kf) kf.addEventListener('submit', function (e) {
            var r = $('input[name="kantar_id"]:checked', kf);
            if (!r) { e.preventDefault(); toast('Bir kantar fişi seçin.', true); return; }
            var o = null; try { o = JSON.parse(r.getAttribute('data-oneri') || 'null'); } catch (x) { o = null; }
            if (!o) return;   // JS verisi yoksa klasik GET (?yeni=1&kantar_id=) sunucu doldurur
            e.preventDefault();
            kantarDoldur(o);
            modalKapat();
        });
        function kantarDoldur(o) {
            if (o.tarih && f.elements.tarih) f.elements.tarih.value = o.tarih;
            if (o.depo && $$('option', f.elements.depo).some(function (x) { return x.value === o.depo; })) f.elements.depo.value = o.depo;
            f.elements.kaynak_modul.value = 'kantar'; f.elements.kaynak_id.value = String(o.kantar_id);
            var ss = satirlar(), tr = ss.filter(function (x) { return !satirDolu(x); })[0] || ss[0];
            var k = o.kart_id ? kartId[String(o.kart_id)] : null;
            kartSec(tr, k);
            if (!k) el(tr, 'kart_ara').value = o.urun || '';
            el(tr, 'miktar').value = miktarBicim(o.miktar_milli / 1000, 'kg');
            if (o.cari_id && f.elements.cari_ara) {
                var c = cariler.filter(function (x) { return x.id === o.cari_id; })[0];
                if (c) { f.elements.cari_ara.value = c.kod + ' — ' + c.unvan; cariGirdi(); }
            }
            var bil = $('[data-kaynak-bilgi]', f);
            if (bil) { bil.textContent = 'Kantar #' + o.kantar_no + '’ten dolduruldu · ' + miktarBicim(o.miktar_milli / 1000, 'kg') + ' kg net' + (!k && o.urun ? ' · ürün: ' + o.urun + ' (kartı seçin)' : ''); bil.hidden = false; }
            fisDegistiBayrak = true;
            hepsi();
            var od = k ? (el(tr, 'birim_fiyat') || el(tr, 'miktar')) : el(tr, 'kart_ara');
            setTimeout(function () { od.focus(); }, 40);
        }

        // — açılış —
        satirlar().forEach(function (tr) {
            var k = satirKart(tr), sec = el(tr, 'birim').value;
            if (k) birimKur(tr, k, sec);
            else if (el(tr, 'kart_ara').value.trim()) { var b = kartBul(el(tr, 'kart_ara').value); if (b) kartSec(tr, b); }
        });
        pbGuncelle();
        if (tur === 'cikis' && f.elements.cari_id) f.classList.toggle('st-form--fiyatsiz', !f.elements.cari_id.value);
        hepsi();
        if (masaustu() || /[?&](yeni|kopya)=/.test(location.search)) {
            var ilkBos = satirlar().filter(function (x) { return !satirDolu(x); })[0];
            setTimeout(function () { var o = ilkBos ? $('.st-kart-ara', ilkBos) : $('[name="tarih"]', f); if (o && masaustu()) o.focus(); }, 30);
        }
    }

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
