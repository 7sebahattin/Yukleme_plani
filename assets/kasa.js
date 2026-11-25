/* =========================================================
   assets/kasa.js — Kasa modülü istemcisi (kasa_fisleri / kasa_tanimlari)
   Yalnız #kasa kökü olan sayfada çalışır. Yazmalar YALNIZ kasa_kaydet.php'ye (fetch + X-Requested-With: fetch;
   JS kapalıyken data-ks-ajax formları klasik POST + 303 ile çalışır). app.js / stok.js'e DOKUNMAZ.
   Kancalar: form[data-ks-ajax] · [data-ks-modal="kart"] (+data-duzenle, data-kart) · [data-ks-kapat] · [data-ks-form="<tür>"] (hareket formu) ·
             #ksForm[data-ks-form] (kendi fetch'i; 409 yetersiz_bakiye → #ksEksik + "Yine de kaydet") · a[data-ks-detay] / tablo satırı (?parca=detay → #ksModalDetay) ·
             [data-ks-iptal-ac] · #ksModalIptal · [data-oto-gonder] · [data-ks-donem] · [data-ks-daha] · #ksVeri (kasalar / cariler) · .ks-tanim-nav-oge · [data-tanim-duzen].
   Klavye: "/" aramaya odak · Ctrl+Enter kaydet · Esc kapat.
   Tutar çözümü yalnız ÖNİZLEME içindir (tam sayı kuruş); esas doğrulama sunucudadır.
   ========================================================= */
(function () {
    'use strict';
    var KOK = document.getElementById('kasa');
    if (!KOK) return;

    var S = {
        kaydet: 'kasa_kaydet.php',
        sayfa: (location.pathname.replace(/^.*\//, '') || 'kasa_fisleri.php'),
        toastAnahtar: 'kasa_toast',
        yonRe: /^kasa_(fisleri|tanimlari)\.php(\?[^\r\n]*)?$/,
        baslik: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        m: { ag: 'Bağlantı hatası; tekrar deneyin.', kaydedildi: 'Kaydedildi', zorunlu: 'Zorunlu alan.' }
    };
    var VERI = (function () { try { var s = document.getElementById('ksVeri'); return s ? JSON.parse(s.textContent) : null; } catch (x) { return null; } })();

    function $(s, k) { return (k || document).querySelector(s); }
    function $$(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }
    function oku(k) { try { return window.sessionStorage.getItem(k); } catch (x) { return null; } }
    function yaz(k, v) { try { if (v == null) window.sessionStorage.removeItem(k); else window.sessionStorage.setItem(k, v); } catch (x) { /* yok: sorun değil */ } }
    function guvenliYol(u) { return typeof u === 'string' && S.yonRe.test(u); }
    function trKucuk(s) { return String(s || '').replace(/İ/g, 'i').replace(/I/g, 'ı').toLocaleLowerCase('tr-TR'); }

    /* ═════════ Para (yalnız önizleme) ═════════ */
    /* Sunucudaki kasa_tutar_coz (cari_tutar_coz → hesap_parse_amount) ile BİREBİR aynı: {k: kuruş|null, belirsiz, mesaj} */
    function tutarCoz(ham) {
        var s = String(ham == null ? '' : ham).trim();
        ['₺', '$', '€', 'TRY', 'TL', 'USD', 'EUR', 'AED'].forEach(function (t) { s = s.replace(new RegExp(t.replace('$', '\\$'), 'gi'), ''); });
        s = s.replace(/[\s  ]+/g, '');
        var bos = { k: null, belirsiz: false, mesaj: '' };
        var bm = /^([1-9]\d{0,2}),(\d{3})$/.exec(s);
        if (bm) { var a = bm[1] + '.' + bm[2]; return { k: null, belirsiz: true, mesaj: 'Tutar belirsiz: ' + a + ' için nokta kullanın (' + a + '), ' + (a === '1.500' ? 'bir buçuk için 1,50 yazın.' : 'ondalık için ' + bm[1] + ',50 yazın.') }; }
        if (s === '') return bos;
        var neg = false;
        if (s.charAt(0) === '-') { neg = true; s = s.slice(1); }
        if (s === '' || !/^[0-9.,]+$/.test(s) || !/[0-9]/.test(s)) return bos;
        function gruplarGecerli(g) {
            if (!/^[0-9]{1,3}$/.test(g[0])) return false;
            for (var i = 1; i < g.length; i++) if (!/^[0-9]{3}$/.test(g[i])) return false;
            return true;
        }
        var nd = s.split('.').length - 1, nc = s.split(',').length - 1, val;
        if (nd === 0 && nc === 0) val = Number(s);
        else if (nd > 0 && nc > 0) {
            var dp = Math.max(s.lastIndexOf('.'), s.lastIndexOf(',')), ds = s.charAt(dp), bs = ds === '.' ? ',' : '.';
            var ip = s.slice(0, dp), tail = s.slice(dp + 1);
            if (ip.indexOf(ds) >= 0 || !/^[0-9]+$/.test(tail === '' ? '0' : tail)) return bos;
            if (!gruplarGecerli(ip.split(bs))) return bos;
            val = Number(ip.split(bs).join('') + '.' + (tail === '' ? '0' : tail));
        } else {
            var sep = nd > 0 ? '.' : ',', p = s.split(sep);
            if (p.length > 2) { if (!gruplarGecerli(p)) return bos; val = Number(p.join('')); }
            else if (p[1].length === 3 && /^[1-9][0-9]{0,2}$/.test(p[0])) val = Number(p[0] + p[1]);
            else val = Number((p[0] === '' ? '0' : p[0]) + '.' + (p[1] === '' ? '0' : p[1]));
        }
        if (neg) val = -val;
        if (!isFinite(val) || val <= 0) return bos;
        var k = Math.round(val * 100);
        if (k <= 0 || k > 99999999999999 || Math.abs(val * 100 - k) > 1e-6) return bos;
        return { k: k, belirsiz: false, mesaj: '' };
    }
    function kurusCoz(ham) { return tutarCoz(ham).k; }
    function paraYaz(kurus, pb) {
        var m = Math.abs(kurus), t = Math.floor(m / 100), k = String(m % 100);
        var sym = { TRY: '₺', USD: '$', EUR: '€', GBP: '£', CHF: 'CHF', RUB: '₽', JPY: '¥', SAR: 'SAR', AED: 'AED', AUD: 'A$', CAD: 'C$', CNY: '¥', AZN: '₼' }[pb] || pb || '';
        return (kurus < 0 ? '-' : '') + String(t).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + (k.length < 2 ? '0' + k : k) + (sym ? ' ' + sym : '');
    }

    /* ═════════ Toast ═════════ */
    var toastZ = null;
    function toast(msg, hata) {
        var t = $('.ks-toast');
        if (!t) { t = document.createElement('div'); t.className = 'ks-toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
        t.textContent = msg;
        t.classList.toggle('ks-toast--hata', !!hata);
        /* Mobilde görünür alt eylem çubuğunun ÜSTÜNE otur (çubuğu / Kaydet düğmesini örtmesin) */
        var ek = 0;
        if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
            $$('.ks-eylem-bar').forEach(function (b) { if (!b.hidden && b.getClientRects().length) ek = Math.max(ek, b.offsetHeight + 8); });
        }
        t.style.setProperty('--ks-toast-ek', ek + 'px');
        t.hidden = false;
        clearTimeout(toastZ);
        toastZ = setTimeout(function () { t.hidden = true; }, hata ? 6500 : (msg.length > 70 ? 9000 : 3200));
    }
    function yenileToastla(msg, url) {
        if (msg) yaz(S.toastAnahtar, msg);
        var simdiki = location.pathname.replace(/^.*\//, '') + location.search;
        if (url && guvenliYol(url) && url !== simdiki) location.assign(url); else location.reload();
    }
    (function () { var m = oku(S.toastAnahtar); if (m) { yaz(S.toastAnahtar, null); toast(m); } })();

    /* ═════════ Form hataları ═════════ */
    function hataTemizle(f) {
        $$('.ks-hata', f).forEach(function (e) { e.remove(); });
        $$('[aria-invalid="true"]', f).forEach(function (e) { e.removeAttribute('aria-invalid'); });
        var g = $('.ks-form-hata', f); if (g) { g.hidden = true; g.textContent = ''; }
    }
    function alanHata(f, ad, msg) {
        if (ad === 'cari_id') ad = 'cari_ara';   // gizli alan → görünen arama kutusu
        var el = $$('[name="' + ad + '"]', f).filter(function (x) { return x.type !== 'hidden' && !x.disabled; })[0];
        if (!el) return null;
        el.setAttribute('aria-invalid', 'true');
        if (ad === 'tutar' && el.closest('label') && $('.ks-tutar-onizleme--hata', el.closest('label'))) return el;   // önizleme aynı belirsizlik metnini zaten gösterir
        var s = document.createElement('span'); s.className = 'ks-hata'; s.textContent = msg;
        var id = 'ksH' + Math.random().toString(36).slice(2, 8); s.id = id; el.setAttribute('aria-describedby', id);
        (el.closest('.pm-label') || el.parentNode).appendChild(s);
        return el;
    }
    function genelHata(f, msg) {
        var g = $('.ks-form-hata', f);
        if (g) { g.textContent = msg; g.hidden = false; try { g.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ } } else toast(msg, true);
    }
    /** Sunucu kısmi yanıtını (kendi kasa_fisleri.php ?parca=detay çıktımız) DOM'a çevirir — modüldeki TEK HTML ayrıştırma noktası. */
    function htmlParca(h) { var t = document.createElement('div'); t.innerHTML = h; return t; }
    function yanitCoz(r) {
        return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) { j = null; } return { r: r, j: j }; });
    }

    /* ═════════ AJAX form gönderimi (basit formlar) ═════════ */
    function gonder(f, sw) {
        if (f.dataset.kilit === '1') return;
        if (f.getAttribute('data-onay') && !window.confirm(f.getAttribute('data-onay'))) return;
        hataTemizle(f);
        var eksik = $$('[required]', f).filter(function (e) { return !e.disabled && !String(e.value || '').trim(); });
        if (eksik.length) { eksik.forEach(function (e) { alanHata(f, e.name, S.m.zorunlu); }); eksik[0].focus(); return; }
        var btn = $$('button[type=submit], button:not([type])', f);
        f.dataset.kilit = '1'; btn.forEach(function (b) { b.disabled = true; });
        var fd = new FormData(f);   // anahtar kutusu devre dışı bırakılmadan ÖNCE okunur (disabled alan gönderilmez)
        if (sw) sw.disabled = true;
        // Hata dönerse anahtar eski hâline: kutu devre dışı kalmaz, işaret geri alınır
        function anahtarGeri() { if (sw) { sw.checked = !sw.checked; sw.disabled = false; } }
        fetch(f.getAttribute('action') || S.kaydet, { method: 'POST', body: fd, headers: S.baslik, credentials: 'same-origin' })
            .then(yanitCoz)
            .then(function (s) {
                f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; });
                if (s.j && s.j.ok) {
                    var msg = f.getAttribute('data-ok') || S.m.kaydedildi;
                    if (s.j.uyari) msg += ' — ' + s.j.uyari;
                    yenileToastla(msg, s.j.yonlendir);
                    return;
                }
                anahtarGeri();
                if (s.j && s.j.alanlar) {
                    var ilk = null;
                    Object.keys(s.j.alanlar).forEach(function (ad) { var el = alanHata(f, ad, s.j.alanlar[ad]); if (el && !ilk) ilk = el; });
                    if (ilk && ilk.focus) ilk.focus();
                }
                genelHata(f, (s.j && s.j.hata) || ('İşlem yapılamadı (HTTP ' + s.r.status + ').'));
            })
            .catch(function () { f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; }); anahtarGeri(); genelHata(f, S.m.ag); });
    }
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.matches) return;
        if (f.matches('form[data-ks-ajax]')) { e.preventDefault(); gonder(f); return; }
        if (f.matches('form#ksForm')) { e.preventDefault(); formGonder(false); }
    });
    document.addEventListener('change', function (e) {
        var c = e.target;
        if (!c.matches) return;
        if (c.matches('input[role="switch"]') && c.form && c.form.matches('form[data-ks-ajax]')) { gonder(c.form, c); return; }
        if (c.matches('select[data-oto-gonder]') && c.form) {
            if (c.matches('[data-ks-donem]')) {
                var oz = $('.ks-ozel-tarih', c.form);
                if (oz) { oz.hidden = c.value !== 'ozel'; if (c.value === 'ozel') { var b = $('input[name="bas"]', oz); if (b) b.focus(); return; } }
            }
            c.form.submit();
        }
    });

    /* ═════════ Modal ═════════ */
    var acik = null, acan = null;
    function modalAc(id, tetik) {
        var m = document.getElementById(id); if (!m) return null;
        acik = m; acan = tetik || document.activeElement;
        m.hidden = false; document.body.style.overflow = 'hidden';
        setTimeout(function () { var o = $('[data-ilk-odak]', m) || $('input:not([type=hidden]), select, textarea', m); if (o && o.focus) o.focus(); }, 30);
        return m;
    }
    function modalKapat() {
        if (!acik) return;
        acik.hidden = true; document.body.style.overflow = '';
        var a = acan; acik = null; acan = null;
        if (a && document.contains(a) && a.focus) a.focus();
        if (location.search.indexOf('id=') >= 0) { try { var u = new URL(location.href); u.searchParams.delete('id'); history.replaceState(history.state, '', u.pathname.replace(/^.*\//, '') + u.search); } catch (x) { /* kritik değil */ } }
    }
    // Sayfa açılışında sunucunun açık bıraktığı detay modalı da "açık" sayılır
    (function () { var d = document.getElementById('ksModalDetay'); if (d && !d.hidden) { acik = d; acan = null; document.body.style.overflow = 'hidden'; } })();

    document.addEventListener('click', function (e) {
        var hedef = e.target.closest ? e.target : null; if (!hedef) return;
        var t = hedef.closest('[data-ks-modal], [data-ks-kapat], [data-ks-form], [data-ks-iptal-ac], [data-ks-daha], a[data-ks-detay], [data-eksik-duzelt], [data-eksik-kaydet]');
        if (t) {
            if (t.hasAttribute('data-ks-kapat')) { e.preventDefault(); modalKapat(); return; }
            if (t.getAttribute('data-ks-modal') === 'kart') { e.preventDefault(); kartModalAc(t.getAttribute('data-duzenle') === '1', t); return; }
            if (t.hasAttribute('data-ks-form') && t.tagName === 'BUTTON') { e.preventDefault(); if (!t.disabled) hareketFormAc(t.getAttribute('data-ks-form'), t); return; }
            if (t.hasAttribute('data-ks-iptal-ac')) { e.preventDefault(); iptalModalAc(t); return; }
            if (t.hasAttribute('data-ks-daha')) { e.preventDefault(); dahaDegistir(t); return; }
            if (t.hasAttribute('data-eksik-duzelt')) { e.preventDefault(); eksikGizle(); return; }
            if (t.hasAttribute('data-eksik-kaydet')) { e.preventDefault(); var ez = $('#ksForm [name="eksiye_izin"]'); if (ez) ez.value = '1'; formGonder(true); return; }
            if (t.matches('a[data-ks-detay]')) {
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
                e.preventDefault(); detayAc(t.getAttribute('data-id'), t); return;
            }
        }
        var tr = hedef.closest('.ks-tablo tbody tr[data-id]');
        if (tr && !hedef.closest('a, button')) { detayAc(tr.getAttribute('data-id'), tr); return; }
        if (hedef.classList && hedef.classList.contains('pm-overlay') && hedef === acik) modalKapat();
        var da = $('#ksDaha');
        if (da && !da.hidden && !hedef.closest('#ksDaha, [data-ks-daha]')) dahaDegistir(null, true);
    });
    document.addEventListener('keydown', function (e) {
        if (acik) {
            if (e.key === 'Escape') { e.preventDefault(); modalKapat(); return; }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                var f = $('form', acik);
                if (f) { e.preventDefault(); if (f.matches('form#ksForm')) formGonder(false); else if (f.matches('form[data-ks-ajax]')) gonder(f); }
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
        var ar = document.getElementById('ksArama');
        var yazim = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
        if (ar && e.key === '/' && !yazim) { e.preventDefault(); ar.focus(); ar.select(); return; }
        if (ar && document.activeElement === ar && e.key === 'Escape' && ar.value) { ar.value = ''; if (ar.form) ar.form.submit(); }
    });

    function dahaDegistir(btn, kapat) {
        var p = document.getElementById('ksDaha'), b = btn || $('[data-ks-daha]'); if (!p) return;
        var ac = kapat ? false : p.hidden;
        p.hidden = !ac;
        if (b) b.setAttribute('aria-expanded', ac ? 'true' : 'false');
    }

    /* ═════════ Detay (hareket) ═════════ */
    function detayAc(id, tetik) {
        var m = document.getElementById('ksModalDetay'); if (!m || !id) return;
        var kap = document.getElementById('ksDetayKap');
        fetch(S.sayfa.replace(/\?.*$/, '') + '?id=' + encodeURIComponent(id) + '&parca=detay', { headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (h) {
                var d = $('#ksDetay', htmlParca(h));
                if (!d) throw new Error('detay yok');
                while (kap.firstChild) kap.removeChild(kap.firstChild);
                kap.appendChild(d);
                var ib = $('[data-ks-iptal-ac]', m); if (ib) ib.hidden = d.getAttribute('data-iptal') !== '1';
                modalAc('ksModalDetay', tetik);
            })
            .catch(function () { toast('Hareket ayrıntısı yüklenemedi.', true); });
    }
    function iptalModalAc(t) {
        var d = $('#ksDetay'); var m = document.getElementById('ksModalIptal'); if (!d || !m) return;
        var f = $('form', m);
        f.reset(); hataTemizle(f);
        f.elements.id.value = d.getAttribute('data-id') || '';
        f.elements.geri.value = (location.pathname.replace(/^.*\//, '') + location.search).replace(/([?&])id=\d+&?/, '$1').replace(/[?&]$/, '');
        if (!guvenliYol(f.elements.geri.value)) f.elements.geri.value = 'kasa_fisleri.php';
        var no = $('[data-iptal-no]', m); if (no) no.textContent = d.getAttribute('data-belge') || '';
        iptalSayac(f);
        // detay modalı kapanır, iptal modalı açılır
        var dm = document.getElementById('ksModalDetay'); if (dm) dm.hidden = true;
        acik = null;
        modalAc('ksModalIptal', t);
    }
    function iptalSayac(f) {
        var ta = $('[data-iptal-neden]', f), n = ta ? ta.value.trim().length : 0;
        var b = $('[data-iptal-onay]', f); if (b) b.disabled = n < 3;
        var sy = $('[data-sayac]', f); if (sy) sy.textContent = n < 3 ? 'En az 3 karakter' : n + ' / 500';
    }
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('[data-iptal-neden]')) iptalSayac(t.form);
        if (t && t.matches && t.matches('#ksForm [name="tutar"], #ksForm [name="cari_ara"]')) { if (t.name === 'cari_ara') cariCoz(); formOzet(); }
    });

    /* ═════════ Hareket formu ═════════ */
    var BASLIK = { cari_tahsilat: 'Cari Tahsilat', cari_odeme: 'Cari Ödeme (Tediye)', gelir: 'Gelir Fişi', gider: 'Gider Fişi', acilis: 'Açılış / Devir', virman: 'Kasalar Arası Virman' };
    var CIKIS = { cari_odeme: 1, gider: 1, virman: 1 };
    function rastgele() {
        var a = new Uint8Array(12), s = '';
        try { window.crypto.getRandomValues(a); } catch (x) { for (var i = 0; i < 12; i++) a[i] = Math.floor(Math.random() * 256); }
        a.forEach(function (b) { s += (b % 36).toString(36); });
        return s + Date.now().toString(36);
    }
    function kasaBul(id) { var r = null; ((VERI && VERI.kasalar) || []).forEach(function (k) { if (String(k.id) === String(id)) r = k; }); return r; }
    function turForm() { var f = document.getElementById('ksForm'); return f ? f.elements.tur.value : ''; }
    function turGoster(tur) {
        var f = document.getElementById('ksForm');
        $$('[data-turler]', f).forEach(function (el) {
            var ac = el.getAttribute('data-turler').split(' ').indexOf(tur) >= 0;
            el.hidden = !ac;
            $$('input, select, textarea', el).forEach(function (i) { i.disabled = !ac; });
        });
        f.elements.islem.value = tur === 'virman' ? 'virman_ekle' : 'hareket_ekle';
        f.elements.tur.value = tur;
    }
    var ksKurOto = null;
    function kurOtoAl() {
        var f = document.getElementById('ksForm');
        if (!f || !window.DovizOto || !f.elements.kur) return null;
        if (!ksKurOto) {
            ksKurOto = window.DovizOto.bagla({
                form: f,
                tarihEl: f.elements.tarih,
                kurEl: f.elements.kur,
                pbFn: function () {
                    if (turForm() === 'virman') return 'TRY';
                    var kasa = kasaBul(f.elements[kaynakKasaAd()].value);
                    return kasa ? kasa.pb : 'TRY';
                }
            });
        }
        return ksKurOto;
    }
    function hareketFormAc(tur, tetik) {
        var m = document.getElementById('ksModalForm'); if (!m || !VERI) return;
        var f = document.getElementById('ksForm');
        f.reset(); hataTemizle(f); eksikGizle();
        if (f.elements.kur) f.elements.kur.dataset.kurOto = '';
        var ko = kurOtoAl(); if (ko) ko.temizle();
        f.elements.istek_anahtari.value = rastgele();
        f.elements.eksiye_izin.value = '0';
        f.elements.cari_id.value = '';
        $('#ksFormBaslik', m).textContent = BASLIK[tur] || 'Yeni hareket';
        turGoster(tur);
        var sec = VERI.secili && kasaBul(VERI.secili) ? String(VERI.secili) : (VERI.kasalar[0] ? String(VERI.kasalar[0].id) : '');
        if (tur === 'virman') {
            f.elements.kaynak_kasa_id.value = sec;
            hedefSuz();
        } else {
            f.elements.kasa_id.value = sec;
        }
        // Son kullanılan geri adresi: kasa süzgeci korunur
        f.elements.geri.value = guvenliYol(S.sayfa + location.search) ? S.sayfa + location.search : 'kasa_fisleri.php';
        formOzet();
        modalAc('ksModalForm', tetik);
    }
    function kaynakKasaAd() { var f = document.getElementById('ksForm'); return turForm() === 'virman' ? 'kaynak_kasa_id' : 'kasa_id'; }
    function hedefSuz() {
        var f = document.getElementById('ksForm'), k = kasaBul(f.elements.kaynak_kasa_id.value), h = f.elements.hedef_kasa_id;
        Array.prototype.forEach.call(h.options, function (o) {
            if (!o.value) return;
            var ok = !k || (o.getAttribute('data-pb') === k.pb && o.value !== String(k.id));
            o.disabled = !ok; o.hidden = !ok;
        });
        if (h.value && h.options[h.selectedIndex] && h.options[h.selectedIndex].disabled) h.value = '';
        if (!h.value && k) { var ilk = Array.prototype.filter.call(h.options, function (o) { return o.value && !o.disabled; })[0]; if (ilk) h.value = ilk.value; }
    }
    function cariCoz() {
        var f = document.getElementById('ksForm'), v = f.elements.cari_ara.value.trim(), ip = $('[data-cari-ipucu]', f);
        var bul = null;
        ((VERI && VERI.cariler) || []).forEach(function (c) { if (c.kod + ' — ' + c.unvan === v) bul = c; });
        if (!bul && v) {
            var q = trKucuk(v), es = ((VERI && VERI.cariler) || []).filter(function (c) { return trKucuk(c.kod) === q || trKucuk(c.unvan) === q; });
            if (es.length === 1) bul = es[0];
        }
        f.elements.cari_id.value = bul ? String(bul.id) : '';
        if (ip) ip.textContent = bul ? 'Seçildi: ' + bul.unvan : (v ? 'Listeden bir cari seçin' : '');
    }
    function formOzet() {
        var f = document.getElementById('ksForm'); if (!f) return;
        var tur = turForm(), kasa = kasaBul(f.elements[kaynakKasaAd()].value);
        var tc = tutarCoz(f.elements.tutar.value), kurus = tc.k;
        var onz = $('[data-tutar-onizleme]', f);
        if (onz) {
            onz.textContent = tc.belirsiz ? tc.mesaj : (kurus !== null ? '= ' + paraYaz(kurus, kasa ? kasa.pb : 'TRY') : '');
            onz.classList.toggle('ks-tutar-onizleme--hata', tc.belirsiz);
            if (tc.belirsiz) f.elements.tutar.setAttribute('aria-invalid', 'true');
            else if (!$('.ks-hata', f.elements.tutar.closest('label'))) f.elements.tutar.removeAttribute('aria-invalid');
        }
        $$('[data-pb-etiket]', f).forEach(function (e) { e.textContent = kasa ? kasa.pb : ''; });
        var kur = $('[data-kur]', f);
        if (kur) { var goster = tur !== 'virman' && !!kasa && kasa.pb !== 'TRY'; kur.hidden = !goster; $$('input', kur).forEach(function (i) { i.disabled = !goster; if (!goster) { i.value = ''; i.dataset.kurOto = ''; } }); }
        var ko = kurOtoAl(); if (ko) ko.guncelle(false);
        $$('[data-bakiye-ipucu]', f).forEach(function (ip) {
            if (ip.closest('[data-turler]').hidden) { ip.textContent = ''; return; }
            if (!kasa) { ip.textContent = ''; return; }
            var t = 'Bakiye: ' + paraYaz(kasa.bakiye, kasa.pb);
            if (kurus !== null) { var yeni = kasa.bakiye + (CIKIS[tur] ? -kurus : kurus); t += ' → ' + paraYaz(yeni, kasa.pb); ip.classList.toggle('ks-eksi', yeni < 0); } else ip.classList.remove('ks-eksi');
            ip.textContent = t;
        });
        var tv = $('[data-ks-tevsik]', f);
        if (tv) tv.hidden = !((tur === 'cari_tahsilat' || tur === 'cari_odeme') && kasa && kasa.tip === 'nakit' && kasa.pb === 'TRY' && kurus !== null && kurus >= VERI.tevsik);
    }
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.matches || !t.closest('#ksForm')) return;
        if (t.name === 'kaynak_kasa_id') hedefSuz();
        if (t.name === 'kasa_id' || t.name === 'kaynak_kasa_id' || t.name === 'tutar') formOzet();
        if (t.name === 'tarih') { var ko = kurOtoAl(); if (ko) ko.guncelle(false); }
        if (t.name === 'cari_ara') cariCoz();
    });
    function eksikGizle() { var e = $('#ksEksik'); if (e) e.hidden = true; var ez = $('#ksForm [name="eksiye_izin"]'); if (ez) ez.value = '0'; }
    function formGonder(yineDe) {
        var f = document.getElementById('ksForm'); if (!f || f.dataset.kilit === '1') return;
        hataTemizle(f);
        if (!yineDe) eksikGizle();
        if (turForm() === 'cari_tahsilat' || turForm() === 'cari_odeme') cariCoz();
        var eksik = $$('[required]', f).filter(function (e) { return !e.disabled && !String(e.value || '').trim(); });
        if (eksik.length) { eksik.forEach(function (e) { alanHata(f, e.name, S.m.zorunlu); }); eksik[0].focus(); return; }
        var btn = $$('button[type=submit]', f).concat($$('[data-eksik-kaydet]', f));
        f.dataset.kilit = '1'; btn.forEach(function (b) { b.disabled = true; });
        var fd = new FormData(f);
        fetch(S.kaydet, { method: 'POST', body: fd, headers: S.baslik, credentials: 'same-origin' })
            .then(yanitCoz)
            .then(function (s) {
                f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; });
                if (s.j && s.j.ok) {
                    var msg = (s.j.tekrar ? 'Hareket zaten kaydedilmişti' : (BASLIK[turForm()] || 'Hareket') + ' kaydedildi') + (s.j.belge_no ? ' · ' + s.j.belge_no : '');
                    if (s.j.uyari) msg += ' — ' + s.j.uyari;
                    yenileToastla(msg, s.j.yonlendir);
                    return;
                }
                if (s.j && s.j.kod === 'yetersiz_bakiye') {
                    var ek = $('#ksEksik', f), kasa = kasaBul(f.elements[kaynakKasaAd()].value), pb = kasa ? kasa.pb : 'TRY';
                    $('[data-eksik="bakiye"]', ek).textContent = paraYaz(s.j.bakiye, pb);
                    $('[data-eksik="eksik"]', ek).textContent = paraYaz(s.j.eksik, pb);
                    $('[data-eksik="istenen"]', ek).textContent = paraYaz(s.j.bakiye + s.j.eksik, pb);
                    ek.hidden = false;
                    try { ek.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ }
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

    /* ═════════ Kasa kartı modalı (Tanımlar) ═════════ */
    function bankaGoster(f) {
        var b = $('[data-banka]', f), banka = f.elements.tip.value === 'banka';
        if (b) { b.hidden = !banka; $$('input', b).forEach(function (i) { i.disabled = !banka; }); }
    }
    function kartModalAc(duzenle, tetik) {
        var m = document.getElementById('ksModalKart'); if (!m) return;
        var f = $('form', m);
        f.reset(); hataTemizle(f);
        var hidd = $('input[type=hidden][name="para_birimi"]', f); if (hidd) hidd.remove();
        f.elements.para_birimi.disabled = false;
        var kilit = $('[data-pb-kilit]', m), v = null;
        if (duzenle && tetik) { try { v = JSON.parse(tetik.getAttribute('data-kart')); } catch (x) { v = null; } }
        if (v) {
            $('#ksKartBaslik', m).textContent = 'Kasayı düzenle';
            ['kod', 'ad', 'tip', 'para_birimi', 'banka_adi', 'sube', 'iban', 'aciklama'].forEach(function (k) { var el = f.elements[k]; if (el) el.value = v[k] == null ? '' : String(v[k]); });
            var d = f.elements.depo;
            if (v.depo && !Array.prototype.some.call(d.options, function (o) { return o.value === v.depo; })) { var o = document.createElement('option'); o.value = v.depo; o.textContent = v.depo; d.appendChild(o); }
            d.value = v.depo || '';
            f.elements.eksi_izin.checked = v.eksi_izin === 1;
            f.elements.aktif.checked = v.aktif === 1;
            f.elements.id.value = String(v.id);
            if (v.hareketli) {   // para birimi kilitli: görünür seçim devre dışı, değer gizli alanla taşınır
                f.elements.para_birimi.disabled = true;
                var g = document.createElement('input'); g.type = 'hidden'; g.name = 'para_birimi'; g.value = v.para_birimi; f.appendChild(g);
            }
            if (kilit) kilit.hidden = !v.hareketli;
        } else {
            $('#ksKartBaslik', m).textContent = 'Yeni kasa';
            f.elements.id.value = '';
            f.elements.kod.value = f.elements.kod.getAttribute('data-oneri') || '';
            if (kilit) kilit.hidden = true;
        }
        bankaGoster(f);
        modalAc('ksModalKart', tetik);
    }
    document.addEventListener('change', function (e) { var t = e.target; if (t && t.matches && t.matches('[data-ks-tip]')) bankaGoster(t.form); });

    /* ═════════ Tanımlar: sekme + satır içi düzenleme ═════════ */
    var nav = $$('.ks-tanim-nav-oge', KOK);
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
            if (ed && ed.classList.contains('ks-tanim-satir--duzen')) { li.hidden = true; ed.hidden = false; var i = $('input[name="ad"]', ed); if (i) i.focus(); }
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
})();
