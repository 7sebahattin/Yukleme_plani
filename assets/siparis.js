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
        if (modal.id === 'sipYapistirModal' && pklApi) pklApi.hazirla();
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
            if (g.getAttribute('data-sip-girdi') === 'metin') g.setAttribute('inputmode', v.tip === 'telefon' ? 'tel' : 'text');
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
        // Mail gönderildi + (v400) Evrak takımı pencereleri: tüm onay kutuları işaretlenmeden gönder düğmesi pasif
        var m = e.target.closest ? e.target.closest('[data-sip-mail-form],[data-sip-evrak-form]') : null;
        if (m) {
            var p = m.hasAttribute('data-sip-evrak-form') ? 'evrak' : 'mail';
            var hepsi = qa('[data-sip-' + p + '-onay]', m).every(function (c) { return c.checked; });
            var b = q('[data-sip-' + p + '-gonder]', m); if (b) b.disabled = !hepsi;
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

    /* ── PKL: Excel benzeri satır ızgarası (v372) ───────────
       Brüt/Net yapıştırma (hücreye ya da pencereye), Enter/↑/↓ ile sütunda gezinme, canlı toplamlar ve Palet ID
       numaralandırması + boşluk/çakışma uyarısı. Hiçbir şey kaydedilmez: yalnız girdiler dolar, `input` olayı yayılır. */
    var pklApi = null;
    function kgCoz(ham) {
        var s = String(ham == null ? '' : ham).replace(/[\s ]/g, '');
        if (s === '') return { bos: true };
        if (s.charAt(0) === '-') return { ok: false, neden: 'negatif olamaz' };
        if (!/^[\d.,]+$/.test(s)) return { ok: false, neden: 'sayı değil' };
        var t;
        if (s.indexOf(',') >= 0) {
            if (!/^(\d{1,3}(\.\d{3})+|\d+),\d+$/.test(s)) return { ok: false, neden: 'biçim anlaşılamadı (örn. 1.234,5)' };
            t = s.replace(/\./g, '').replace(',', '.');
        } else if (s.indexOf('.') >= 0) {
            // num() noktayı HEP binlik ayırıcı sayar: "812.5" 8125 olurdu. Sessiz 10x hata yerine reddet.
            if (!/^\d{1,3}(\.\d{3})+$/.test(s)) return { ok: false, neden: 'nokta belirsiz (ondalık için virgül kullanın: 812,5)' };
            t = s.replace(/\./g, '');
        } else t = s;
        var n = parseFloat(t);
        if (!isFinite(n) || n > 1e9) return { ok: false, neden: 'çok büyük' };
        n = Math.round(n * 1000) / 1000;
        return { ok: true, n: n, metin: Number.isInteger(n) ? String(n) : String(n).replace('.', ',') };
    }
    function yapistirCoz(metin) {
        var satirlar = String(metin == null ? '' : metin).replace(/\r\n?/g, '\n').split('\n');
        while (satirlar.length && satirlar[satirlar.length - 1].trim() === '') satirlar.pop();
        var hucre = satirlar.map(function (l) {
            var c = l.split('\t');
            while (c.length > 1 && c[c.length - 1].trim() === '') c.pop();
            return c;
        });
        var sut = hucre.reduce(function (m, c) { return Math.max(m, c.length); }, 0);
        var r = { satir: hucre.length, sut: sut, deger: [], hatalar: [], bos: 0 };
        if (!hucre.length) return r;
        if (sut > 2) { r.hatalar.push('En çok 2 sütun yapıştırılabilir (Brüt | Net); ' + sut + ' sütun algılandı. Başlık ve fazladan sütunları seçmeyin.'); return r; }
        hucre.forEach(function (c, i) {
            var satir = [];
            for (var k = 0; k < sut; k++) {
                var x = kgCoz(c[k] || '');
                if (x.bos) r.bos++;
                else if (!x.ok) r.hatalar.push('Satır ' + (i + 1) + ', ' + (k === 0 ? 'ilk' : 'ikinci') + ' sütun: “' + String(c[k]).trim().slice(0, 24) + '” — ' + x.neden);
                satir.push(x);
            }
            r.deger.push(satir);
        });
        return r;
    }
    function trSayi(n) { return Math.round(n).toLocaleString('tr-TR'); }
    /** PHP siparis_pkl_palet_uyarilari_hesapla aynası. */
    function paletUyarilari(son, diger, bas, adet) {
        var o = [];
        if (adet < 1 || bas < 1) return o;
        var bit = bas + adet - 1, cakisan = diger.filter(function (d) { return d.bas <= bit && d.bit >= bas; });
        if (cakisan.length) {
            var p = cakisan.slice(0, 3).map(function (d) { return (String(d.belge_no).trim() || 'başka bir PKL') + (String(d.order_ref).trim() ? ' (' + String(d.order_ref).trim() + ')' : '') + ' ' + d.bas + '–' + d.bit; });
            o.push({ kod: 'palet_cakisma', mesaj: 'Palet aralığı ' + bas + '–' + bit + ', ' + p.join(', ') + (cakisan.length > 3 ? ' ve ' + (cakisan.length - 3) + ' PKL daha' : '') + ' ile çakışıyor — kaydedebilirsiniz, ancak PKL oluşturulurken sistem bunu reddeder; başlangıç numarasını değiştirin.' });
        } else if (bas <= son) {
            o.push({ kod: 'palet_geride', mesaj: 'Başlangıç (' + bas + '), önceki siparişin son palet no\'sundan (' + son + ') küçük; daha önce boşta kalmış bir aralık kullanılıyor.' });
        }
        if (bas > son + 1) {
            var bos = bas - son - 1, aralik = bos === 1 ? (son + 1) + ' numarası' : (son + 1) + '–' + (bas - 1) + ' arası ' + bos + ' numara';
            o.push({ kod: 'palet_bosluk', mesaj: son < 1 ? 'Bu sezonda henüz palet numarası ayrılmadı; ' + aralik + ' boşta kalıyor.' : 'Önceki siparişin son palet no\'su ' + son + '; ' + aralik + ' boşta kalıyor.' });
        }
        return o;
    }
    function pklIzgara(form) {
        var tablo = q('[data-sip-izgara]', form);
        if (!tablo) return;
        var satirlar = qa('tbody tr', tablo), n = satirlar.length;
        var durum = q('[data-sip-yapistir-durum]', form), detay = q('.sip-satir-detay', form);
        var modal = document.getElementById('sipYapistirModal');
        function olay(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }
        function hucre(i, sut) { return satirlar[i] ? q('[data-sip-sutun="' + sut + '"]', satirlar[i]) : null; }
        function durumYaz(tip, parcalar) {
            if (!durum) return;
            durum.textContent = '';
            (Array.isArray(parcalar) ? parcalar : [parcalar]).forEach(function (m) { var d = document.createElement('div'); d.textContent = m; durum.appendChild(d); });
            durum.className = 'sip-yap-durum sip-yap-durum--' + tip;
            durum.hidden = false;
        }

        /* — etkin değer / canlı toplamlar / ortak değer ipuçları — */
        function ortak(sut, bi) {
            var b = form.elements['bloklar[' + bi + '][' + sut + ']'], c = form.elements['varsayilan[' + sut + ']'];
            var x = b && b.value.trim() !== '' ? kgCoz(b.value) : { bos: true };
            if (x.ok) return x;
            var y = c && c.value.trim() !== '' ? kgCoz(c.value) : { bos: true };
            return y.ok ? y : { bos: true };
        }
        function topla() {
            var tb = 0, tn = 0, dolu = 0;
            satirlar.forEach(function (tr) {
                var bi = tr.getAttribute('data-sip-blok'), v = {};
                ['M', 'N'].forEach(function (sut) {
                    var i = q('[data-sip-sutun="' + sut + '"]', tr), x = i && i.value.trim() !== '' ? kgCoz(i.value) : { bos: true };
                    if (!x.ok) x = ortak(sut, bi);
                    v[sut] = x;
                    if (i) { var ip = ortak(sut, bi); i.placeholder = ip.ok ? ip.metin : ''; i.classList.toggle('sip-hucre-bos', !x.ok); }
                });
                if (v.M.ok) tb += v.M.n;
                if (v.N.ok) tn += v.N.n;
                if (v.M.ok && v.N.ok) dolu++;
            });
            var set = function (a, t) { var e = q(a, form); if (e) e.textContent = t; };
            set('[data-sip-t-brut]', trSayi(tb)); set('[data-sip-t-net]', trSayi(tn)); set('[data-sip-t-dolu]', String(dolu));
        }

        /* — Palet ID numaralandırması + uyarılar — */
        var basInp = q('[data-sip-palet-bas]', form), uyariKap = q('[data-sip-palet-uyari]', form);
        var son = +form.getAttribute('data-sip-palet-son') || 0, ilk = +form.getAttribute('data-sip-palet-ilk') || 1, diger = [];
        try { diger = JSON.parse(form.getAttribute('data-sip-palet-diger') || '[]'); } catch (x) { diger = []; }
        function paletBas() {
            if (!basInp) return ilk;
            var v = parseInt(String(basInp.value).replace(/\D/g, ''), 10);
            return v >= 1 ? v : (+basInp.getAttribute('data-sip-palet-varsayilan') || ilk);
        }
        function paletGuncelle() {
            var bas = paletBas();
            satirlar.forEach(function (tr, i) {
                var no = String(bas + i), s = q('[data-sip-palet-no]', tr);
                if (s) s.textContent = no;
                qa('[data-sip-sutun]', tr).forEach(function (inp) { inp.setAttribute('aria-label', (inp.getAttribute('aria-label') || '').replace(/\d+$/, no)); });
            });
            qa('[data-sip-palet-aralik-yazi]').forEach(function (e) { e.textContent = bas + '–' + (bas + n - 1); });
            if (uyariKap) {
                uyariKap.textContent = '';
                paletUyarilari(son, diger, bas, n).forEach(function (u) {
                    var d = document.createElement('div');
                    d.className = 'sip-ok-uyari sip-palet-uyari-satir' + (u.kod === 'palet_cakisma' ? ' sip-palet-uyari-satir--guclu' : '');
                    d.setAttribute('data-kod', u.kod);
                    d.textContent = '⚠ ' + u.mesaj;
                    uyariKap.appendChild(d);
                });
            }
        }
        if (basInp) {
            basInp.addEventListener('input', paletGuncelle);
            basInp.addEventListener('change', function () { var v = parseInt(String(basInp.value).replace(/\D/g, ''), 10); basInp.value = v >= 1 ? String(v) : String(+basInp.getAttribute('data-sip-palet-varsayilan') || ilk); paletGuncelle(); });
        }

        /* — klavye: Enter/Shift+Enter/↑/↓ — */
        function git(inp, yon) {
            var tr = inp.closest('tr'), sut = inp.getAttribute('data-sip-sutun'), sk = tr;
            while ((sk = yon > 0 ? sk.nextElementSibling : sk.previousElementSibling)) {
                var h = q('[data-sip-sutun="' + sut + '"]', sk);
                if (h && !h.disabled) { h.focus(); try { h.select(); } catch (x) { /* yok say */ } return true; }
            }
            return false;
        }
        tablo.addEventListener('keydown', function (e) {
            if (e.isComposing || e.altKey || e.ctrlKey || e.metaKey) return;
            var inp = e.target;
            if (!inp.matches || !inp.matches('input.sip-hucre')) return;
            if (inp.hasAttribute('data-sip-palet-bas')) {
                if (e.key !== 'Enter') return;
                e.preventDefault(); inp.dispatchEvent(new Event('change', { bubbles: true }));
                var h0 = hucre(0, 'M'); if (h0) { h0.focus(); try { h0.select(); } catch (x) { /* yok say */ } }
                return;
            }
            if (e.key === 'Enter' || ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !e.shiftKey)) {
                e.preventDefault();                      // forma ASLA gönderme
                var yon = (e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey)) ? -1 : 1;
                if (!git(inp, yon) && yon > 0 && e.key === 'Enter') inp.blur();          // son satır: klavyeyi kapat
            }
        });

        /* — yapıştırma — */
        function uygula(basSatir, hedef, coz) {
            var uygun = n - basSatir, yaz = Math.min(coz.satir, uygun), say = 0, ilkEl = null;
            for (var i = 0; i < yaz; i++) {
                for (var k = 0; k < coz.sut; k++) {
                    var el = hucre(basSatir + i, k === 0 ? hedef : 'N'), x = coz.deger[i][k];
                    if (!el) continue;
                    el.value = x.ok ? x.metin : '';
                    el.classList.add('sip-hucre--yeni');
                    (function (e2) { setTimeout(function () { e2.classList.remove('sip-hucre--yeni'); }, 1600); })(el);
                    olay(el); say++;
                    if (!ilkEl) ilkEl = el;
                }
            }
            if (detay) detay.open = true;
            return { yazilan: say, satir: yaz, fazla: Math.max(0, coz.satir - yaz), kalan: Math.max(0, uygun - yaz), ilkEl: ilkEl };
        }
        function sonucMesaj(sonuc, coz, hedefAd) {
            var m = ['✓ ' + sonuc.satir + ' satır dolduruldu (' + hedefAd + '). Kaydetmek için «Taslağı kaydet» düğmesine basın.'], tip = 'ok';
            if (coz.bos) { m.push(coz.bos + ' boş hücre yapıştırıldı — ilgili hücreler temizlendi (ortak değeri kullanır).'); }
            if (sonuc.fazla > 0) { m.push('Uyarı: ' + sonuc.fazla + ' satır fazla geldi, yok sayıldı (tabloda yalnız ' + sonuc.satir + ' satır kalmıştı).'); tip = 'uyari'; }
            else if (sonuc.kalan > 0) { m.push('Uyarı: yapıştırılan satır sayısı (' + coz.satir + ') palet satırından az; kalan ' + sonuc.kalan + ' satıra dokunulmadı.'); tip = 'uyari'; }
            durumYaz(tip, m);
        }
        tablo.addEventListener('paste', function (e) {
            var inp = e.target;
            if (!inp.matches || !inp.matches('input[data-sip-sutun]')) return;
            var sut = inp.getAttribute('data-sip-sutun');
            if (sut !== 'M' && sut !== 'N') return;
            var metin = (e.clipboardData || window.clipboardData) ? (e.clipboardData || window.clipboardData).getData('text') : '';
            if (metin === '') return;
            e.preventDefault();
            var coz = yapistirCoz(metin), basSatir = satirlar.indexOf(inp.closest('tr'));
            if (!coz.satir) return;
            if (coz.hatalar.length) { durumYaz('hata', ['Yapıştırma uygulanmadı — hiçbir hücre değişmedi:'].concat(coz.hatalar.slice(0, 4)).concat(coz.hatalar.length > 4 ? ['… ve ' + (coz.hatalar.length - 4) + ' hata daha'] : [])); return; }
            if (coz.sut === 2 && sut !== 'M') { durumYaz('hata', ['İki sütun (Brüt | Net) yalnız Brüt hücresinden başlayarak yapıştırılabilir; hiçbir hücre değişmedi.']); return; }
            var sonuc = uygula(basSatir, sut, coz);
            sonucMesaj(sonuc, coz, coz.sut === 2 ? 'Brüt + Net' : (sut === 'M' ? 'Brüt' : 'Net'));
        });

        /* — pencere — */
        var ta = modal ? q('[data-sip-yap-metin]', modal) : null, sat = modal ? q('[data-sip-yap-satir]', modal) : null, hed = modal ? q('[data-sip-yap-hedef]', modal) : null;
        var onz = modal ? q('[data-sip-yap-onizleme]', modal) : null, uyg = modal ? q('[data-sip-yap-uygula]', modal) : null;
        function onizle() {
            if (!ta) return;
            onz.textContent = ''; uyg.disabled = true;
            var ekle = function (sinif, t) { var d = document.createElement('div'); d.className = sinif; d.textContent = t; onz.appendChild(d); };
            var coz = yapistirCoz(ta.value);
            if (!coz.satir) { ekle('sip-soluk', 'Henüz bir şey yapıştırılmadı.'); return; }
            if (coz.hatalar.length) {
                ekle('sip-yap-onz--hata', 'Uygulanamaz — ' + coz.hatalar.length + ' sorun:');
                coz.hatalar.slice(0, 4).forEach(function (h) { ekle('sip-yap-onz--hata', '• ' + h); });
                if (coz.hatalar.length > 4) ekle('sip-yap-onz--hata', '… ve ' + (coz.hatalar.length - 4) + ' sorun daha');
                return;
            }
            if (coz.sut === 2) hed.value = 'M';
            var ilkB = coz.deger[0][0], ilkN = coz.sut === 2 ? coz.deger[0][1] : null, fmt = function (x) { return x && x.ok ? x.metin : '—'; };
            ekle('', coz.satir + ' satır · ' + (coz.sut === 2 ? 'Brüt | Net' : (hed.value === 'M' ? 'Brüt' : 'Net')) + ' · ilk ' + (coz.sut === 2 ? 'Brüt ' + fmt(ilkB) + ', Net ' + fmt(ilkN) : (hed.value === 'M' ? 'Brüt ' : 'Net ') + fmt(ilkB)));
            var bas = parseInt(sat.value, 10) || 0, uygun = n - bas;
            if (coz.satir > uygun) ekle('sip-yap-onz--uyari', '⚠ ' + coz.satir + ' satır yapıştırıldı, seçilen satırdan itibaren yalnız ' + uygun + ' palet satırı var: fazla ' + (coz.satir - uygun) + ' satır yok sayılacak.');
            else if (coz.satir < uygun) ekle('sip-yap-onz--uyari', '⚠ ' + coz.satir + ' satır yapıştırıldı, palet satırı ' + uygun + ': kalan ' + (uygun - coz.satir) + ' satıra dokunulmayacak.');
            if (coz.bos) ekle('sip-yap-onz--uyari', '⚠ ' + coz.bos + ' boş hücre var: ilgili hücreler temizlenir.');
            uyg.disabled = false;
        }
        function hazirla() {
            if (!modal) return;
            var mevcut = sat.value;
            sat.textContent = '';
            satirlar.forEach(function (tr, i) {
                var o = document.createElement('option'), no = q('[data-sip-palet-no]', tr), pb = no ? no.textContent : String(paletBas() + i);
                o.value = String(i); o.textContent = (i + 1) + '. satır · Palet ' + pb + ' · kalibre ' + (tr.children[1] ? tr.children[1].textContent.trim() : '');
                sat.appendChild(o);
            });
            if (mevcut && sat.options[+mevcut]) sat.value = mevcut;
            ta.value = ''; onizle();
        }
        if (modal) {
            ta.addEventListener('input', onizle); sat.addEventListener('change', onizle); hed.addEventListener('change', onizle);
            uyg.addEventListener('click', function () {
                var coz = yapistirCoz(ta.value);
                if (!coz.satir || coz.hatalar.length) return;
                var hedef = coz.sut === 2 ? 'M' : hed.value;
                var sonuc = uygula(parseInt(sat.value, 10) || 0, hedef, coz);
                kapat(modal);
                sonucMesaj(sonuc, coz, coz.sut === 2 ? 'Brüt + Net' : (hedef === 'M' ? 'Brüt' : 'Net'));
                if (sonuc.ilkEl && sonuc.ilkEl.scrollIntoView) sonuc.ilkEl.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            });
        }

        /* — canlı toplam tetikleyicileri — */
        form.addEventListener('input', function (e) {
            var t = e.target, ad = t && t.name ? t.name : '';
            if (t.hasAttribute && (t.hasAttribute('data-sip-sutun') || /^varsayilan\[(M|N)\]$/.test(ad) || /^bloklar\[\d+\]\[(M|N)\]$/.test(ad))) topla();
        });
        paletGuncelle(); topla();
        pklApi = { hazirla: hazirla, topla: topla, paletGuncelle: paletGuncelle };
    }

    /* ── v378: Mailden al (Sipariş / Lojistik sorgula) ─────────────────────────
       Tek tık = tara + içe aktar. İş sunucuda parçalı yürür (siparis_mail.php: baslat → devam… → bitti); burada yalnız döngü + ilerleme.
       Sonuç HTML'i sunucunun h() ile kaçırdığı TEK render (innerHTML yalnız bu). Ağ kopunca "Devam et" aynı tarama_id'yle sürer. */
    function mailAl(kap) {
        var url = kap.getAttribute('data-sip-mail-url') || 'siparis_mail.php';
        var csrf = kap.getAttribute('data-sip-mail-csrf') || '';
        var dugmeler = qa('[data-sip-mail]', kap);
        var ilerleme = q('[data-sip-mail-ilerleme]', kap), metin = q('[data-sip-mail-ilerleme-metin]', kap), bar = ilerleme ? q('progress', ilerleme) : null;
        var hataKutu = q('[data-sip-mail-hata]', kap), sonucKap = q('[data-sip-mail-sonuc]', kap);
        var ilkPasif = dugmeler.map(function (b) { return b.disabled; });
        var devamIl = ilerleme ? q('[data-sip-mail-devam-et]', ilerleme) : null, vazgecIl = ilerleme ? q('[data-sip-mail-vazgec]', ilerleme) : null;
        var durum = { tid: '', tur: '', calisiyor: false };
        var ETIKET = { siparis: 'Sipariş', lojistik: 'Lojistik' };

        function kilitle(kilit) {
            kap.setAttribute('aria-busy', kilit ? 'true' : 'false');
            dugmeler.forEach(function (b, i) { b.disabled = kilit ? true : ilkPasif[i]; });
        }
        function hataYaz(m) { if (hataKutu) { hataKutu.textContent = m || ''; hataKutu.hidden = !m; } }
        function ilerlemeGoster(goster) { if (ilerleme) ilerleme.hidden = !goster; }
        function dugmeGoster(devam, vazgec) { if (devamIl) devamIl.hidden = !devam; if (vazgecIl) vazgecIl.hidden = !vazgec; }
        function seritKaldir() { qa('[data-sip-mail-devam]', kap).forEach(function (e) { e.parentNode.removeChild(e); }); }
        function ilerlemeYaz(y) {
            if (!ilerleme) return;
            var sy = y.sayac || {}, t = y.toplam || 0;
            if (bar) { bar.max = Math.max(1, t); bar.value = Math.min(t, y.i || 0); }
            var m = 'Mailler taranıyor… ' + (y.i || 0) + ' / ' + t + ' ek · ' + (sy.alindi || 0) + ' alındı · ' + (sy.zaten || 0) + ' zaten vardı';
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
            if (devamEdilebilir && metin) metin.textContent = 'Mail sorgusu yarıda kaldı.';
            else ilerlemeGoster(false);
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
                if (!y.ok) { durum.tid = (y.kod === 'sona_erdi' || y.kod === 'depo') ? '' : durum.tid; sorun(hatamsi(y), false); return; }
                ilerlemeYaz(y);
                if (y.bitti) bitir(y); else dongu();
            }, function () { sorun('Bağlantı kesildi — kaldığı yerden devam edebilirsiniz.', true); });
        }
        function baslat(tur) {
            durum.tur = tur; hataYaz(''); kilitle(true); ilerlemeGoster(true); dugmeGoster(false, false);
            seritKaldir();
            if (bar) { bar.max = 1; bar.value = 0; }
            metin.textContent = (ETIKET[tur] || '') + ' sorgusu başlatılıyor…';
            post({ islem: 'baslat', tur: tur }).then(function (y) {
                if (!y.ok) { sorun(hatamsi(y), false); return; }
                durum.tid = y.tarama_id;
                ilerlemeYaz(y);
                if (y.bitti) bitir(y); else dongu();
            }, function () { sorun('Bağlantı kesildi — sorgu başlatılamadı. Yeniden deneyin.', false); });
        }
        kap.addEventListener('click', function (e) {
            var d = e.target.closest('[data-sip-mail]');
            if (d && kap.contains(d)) { if (!d.disabled && !durum.calisiyor) baslat(d.getAttribute('data-sip-mail')); return; }
            var dv = e.target.closest('[data-sip-mail-devam-et]');
            if (dv) {
                var serit = dv.closest('[data-sip-mail-devam]');
                if (serit) { durum.tid = serit.getAttribute('data-sip-mail-devam') || ''; durum.tur = serit.getAttribute('data-sip-mail-tur') || ''; }
                if (!durum.tid || durum.calisiyor) return;
                kilitle(true); ilerlemeGoster(true); hataYaz('');
                if (metin) metin.textContent = (ETIKET[durum.tur] || 'Mail') + ' sorgusu sürdürülüyor…';
                dongu();
                return;
            }
            var vz = e.target.closest('[data-sip-mail-vazgec]');
            if (vz) {
                var s2 = vz.closest('[data-sip-mail-devam]');
                var tid = s2 ? (s2.getAttribute('data-sip-mail-devam') || '') : durum.tid;
                if (!tid) return;
                post({ islem: 'vazgec', tarama_id: tid }).then(function (y) {
                    if (y.ok) { durum.tid = tid; bitir(y); } else { seritKaldir(); durum.tid = ''; sorun(hatamsi(y), false); }
                }, function () { sorun('Bağlantı kesildi — vazgeçme isteği ulaşmadı.', true); });
                return;
            }
            var on = e.target.closest('[data-sip-mail-onceden]');
            if (on) {
                var kart = on.closest('[data-sip-mail-sonuc-kart]');
                if (!kart) return;
                var acik = kart.classList.toggle('sip-mail-onceden-acik');
                on.setAttribute('aria-pressed', acik ? 'true' : 'false');
                on.textContent = on.textContent.replace(acik ? 'göster' : 'gizle', acik ? 'gizle' : 'göster');
            }
        });
    }

    /* ── v382: "Belge yükle" kartı (native <details>) ─────────────────────────────
       Öncelik: sunucu `open` > URL hash (#yukle / #mail) > localStorage '1' > kapalı. localStorage YALNIZ kullanıcı summary'ye
       tıkladığında yazılır (sunucu açılışı / hash tercihi ezmez). Tarama sürerken (aria-busy) summary tıkı kartı KAPATMAZ.
       Seçici YALNIZ details[data-sip-yukle] > summary.sip-yukle-ozet (içte başka details/summary var). */
    function yukleKart(det) {
        var anahtar = 'asya_sip_yukle_' + (det.getAttribute('data-sip-yukle-uid') || '0');
        var ozet = q('summary.sip-yukle-ozet', det);
        if (!ozet || ozet.parentNode !== det) return;
        function oku() { try { return window.localStorage.getItem(anahtar); } catch (e) { return null; } }
        function yaz(v) { try { window.localStorage.setItem(anahtar, v); } catch (e) { /* erişilemez: varsayılan kapalı */ } }
        function hashUygun() { return location.hash === '#yukle' || location.hash === '#mail'; }
        function hashAc() {
            if (!hashUygun()) return;
            det.open = true;
            try { det.scrollIntoView({ block: 'start' }); } catch (e) { det.scrollIntoView(); }
        }
        if (!det.open) { if (hashUygun()) hashAc(); else if (oku() === '1') det.open = true; }
        window.addEventListener('hashchange', hashAc);
        qa('a[href="#yukle"], a[href="#mail"]').forEach(function (a) { a.addEventListener('click', function () { det.open = true; }); });
        var mesgulZaman = null;
        ozet.addEventListener('click', function (e) {
            if (det.open && q('[data-sip-mail-kap][aria-busy="true"]', det)) {
                e.preventDefault();
                var n = q('[data-sip-yukle-mesgul]', ozet);
                if (n) { n.hidden = false; clearTimeout(mesgulZaman); mesgulZaman = setTimeout(function () { n.hidden = true; }, 2000); }
                return;
            }
            setTimeout(function () { yaz(det.open ? '1' : '0'); }, 0);
        });
    }

    /* ── v401: Ödemeler sayfası (native <dialog>; öğe yoksa etkisiz) ── */
    function odAc(d) {
        if (!d) return;
        if (typeof d.showModal === 'function') { if (!d.open) d.showModal(); } else d.setAttribute('open', '');
        var i = q('input:not([type=hidden]), select', d);
        if (i && window.matchMedia('(min-width: 768px)').matches) { try { i.focus({ preventScroll: true }); } catch (x) { /* yok say */ } }
    }
    function odKapat(d) { if (!d) return; if (typeof d.close === 'function') d.close(); else d.removeAttribute('open'); }
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-od-ac],[data-od-kapat],[data-od-iptal],[data-od-yazdir]') : null;
        if (!t || !t.closest('.sip-mod')) return;
        e.preventDefault();
        if (t.hasAttribute('data-od-ac')) { odAc(document.getElementById(t.getAttribute('data-od-ac'))); return; }
        if (t.hasAttribute('data-od-kapat')) { odKapat(t.closest('dialog')); return; }
        if (t.hasAttribute('data-od-iptal')) {
            var d = document.getElementById('odIptalDlg'); if (!d) return;
            var f = q('form', d);
            f.elements['id'].value = t.getAttribute('data-od-iptal'); f.elements['neden'].value = '';
            var o = q('[data-od-ozet-yazi]', d); if (o) o.textContent = t.getAttribute('data-od-ozet') || '';
            odAc(d); return;
        }
        document.body.classList.add('od-yazdir-modu');
        var temizle = function () { document.body.classList.remove('od-yazdir-modu'); window.removeEventListener('afterprint', temizle); };
        window.addEventListener('afterprint', temizle);
        window.print();
    });

    // v402 — "Son bakışınızdan sonra değişti" çerçevesi. Görülme kaydı YALNIZ bu cihazda (localStorage; şema yok):
    // { t: taban zaman (ilk ziyaret, sunucu saati), s: { sevkiyat id: görülen değişme zamanı } }. Zamanlar "YYYY-AA-GG SS:DD:SS" → dizgi kıyaslanır.
    function gorulen() {
        var m = q('[data-sip-gor-uid]'); if (!m) return;
        var k = 'asya_sip_gorulen_' + m.getAttribute('data-sip-gor-uid'), now = m.getAttribute('data-sip-gor-now') || '', d = null;
        try { d = JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { d = null; }
        if (!d || typeof d !== 'object' || typeof d.t !== 'string' || !d.s || typeof d.s !== 'object') d = { t: now, s: {} };
        var yaz = function () {
            Object.keys(d.s).forEach(function (i) { if (d.s[i] <= d.t) delete d.s[i]; });   // tabanın altı zaten "eski" → kayıt gereksiz
            try { localStorage.setItem(k, JSON.stringify(d)); } catch (e) { /* depolama kapalı: işaret çıkmaz, sayfa bozulmaz */ }
        };
        var vid = m.getAttribute('data-sip-gor-id');
        if (vid) { d.s[vid] = m.getAttribute('data-sip-gor-upd') || now; yaz(); return; }   // sevkiyat sayfası açıldı = görüldü
        yaz();
        qa('[data-sip-gor-id]').forEach(function (e) {
            var id = e.getAttribute('data-sip-gor-id'), upd = e.getAttribute('data-sip-gor-upd') || '';
            if (!upd || upd <= d.t || upd <= (d.s[id] || '')) return;
            var tr = e.closest ? e.closest('tr') : null, kart = e.closest ? e.closest('.sip-card') : null;
            if (tr) {
                tr.classList.add('sip-degisti'); tr.title = 'Son bakışınızdan sonra değişti';
                var sn = tr.nextElementSibling;
                if (sn && sn.classList.contains('sip-urun-tr') && sn.getAttribute('data-href') === tr.getAttribute('data-href')) sn.classList.add('sip-degisti');
            } else if (kart) { kart.classList.add('sip-degisti'); kart.title = 'Son bakışınızdan sonra değişti'; }
        });
    }

    function basla() {
        gorulen();
        qa('details[data-sip-yukle]').forEach(yukleKart);
        qa('form[data-sip-upload]').forEach(yukleme);
        qa('[data-sip-mail-kap]').forEach(mailAl);
        var p = q('form[data-sip-pkl]'); if (p) { pkl(p); pklIzgara(p); }
        zipGuncelle();
        qa('[data-sip-durum-form]').forEach(gerekceGuncelle);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', basla); else basla();

    window.SiparisUI = { ac: function (id) { ac(document.getElementById(id), null); }, kapat: function (id) { kapat(document.getElementById(id)); } };
})();
