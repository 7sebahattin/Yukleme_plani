/* puantaj_giris.js — Aylık Puantaj › Puantaj Girişi sekmesi (v554 → v555). Bağımsız, gayri resmi puantaj.
   Etkileşimler (hepsi fetch → puantaj_giris_kaydet.php, CSRF; <form> YOK, innerHTML YOK):
   · hücreye tıkla / dokun → kod menüsü (X Y D İ Ü R T + Temizle); başarıda hücre ve özetler YERİNDE güncellenir
     (sunucu yanıtı gerçek kaynak: hata olursa hiçbir şey değişmez)
   · v555 TOPLU ATAMA: kişi kutuları (başlıkta "tümünü seç") + gün seçimi (gün numarasına tıkla = seç/kaldır; "Tüm günler /
     Hafta içleri / Hafta sonları / Temizle") → sabit işlem çubuğu: "N kişi × M gün", kod düğmeleri, "Dolu günlerin üzerine yaz"
     (varsayılan KAPALI), Uygula → sayfa içi onay → gun_toplu_sec; sonuç yazılan/atlanan ve ızgara yerinde güncellenir.
     Mobil (<768px): "Toplu seç" modu (kişi kutuları + takvimde gün seçimi; takvimde uzun basış modu açar) + alt sayfa çubuğu.
   · ⋯ menüsü: Düzenle · Hafta içlerini Çalıştı yap · Ayı temizle (onaylı) · Kaldır (onaylı) / Geri al
   · + Kişi ekle penceresi
   Kişi ekle/düzenle/kaldır sonrası sayfa aynı ay/sekmeyle yenilenir (kaydırma korunur).
   window.pgApi: Kişiler görünümü (assets/puantaj_giris_kisiler.js) ortak yardımcıları kullanır. */
(function () {
    'use strict';
    var sayfa = document.querySelector('.pc-page[data-pg-url]');
    if (!sayfa) return;
    var URL_ = sayfa.getAttribute('data-pg-url');
    var CSRF = sayfa.getAttribute('data-pg-csrf') || '';
    var AY = sayfa.getAttribute('data-pg-ay') || '';
    var Q = sayfa.getAttribute('data-pg-q') || '';
    var KODLAR = {};
    try { KODLAR = JSON.parse(sayfa.getAttribute('data-pg-kodlar') || '{}'); } catch (e) { KODLAR = {}; }
    var wrap = document.getElementById('pcGridWrap');
    var ovl = document.getElementById('pgOvl');
    var menu = document.getElementById('pgMenu');
    var islem = document.getElementById('pgIslem');
    var pen = document.getElementById('pgPen');
    if (!ovl || !menu || !islem || !pen) return;
    var AYLAR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    var HAFIZA = 'asya_pg_durum';

    var bag = null;        // açık küçük menünün bağlamı: {tur:'hucre'|'sutun'|'kisi', el, ...}
    var penIs = null;      // pencerenin Tamam işlevi → Promise<bool> (true: kapat)
    var mesgul = false;

    function mobil() { return window.matchMedia('(max-width: 767px)').matches; }
    function depoyaz(v) { try { sessionStorage.setItem(HAFIZA, JSON.stringify(v)); } catch (e) { /* özel pencere: sorun değil */ } }
    function depooku() { try { var v = sessionStorage.getItem(HAFIZA); sessionStorage.removeItem(HAFIZA); return v ? JSON.parse(v) : null; } catch (e) { return null; } }
    function yenile() {
        var ac = sayfa.querySelector('details.pc-kisi[open]');
        depoyaz({ emp: ac ? ac.getAttribute('data-emp-kisi') : '', sl: wrap ? wrap.scrollLeft : 0, st: wrap ? wrap.scrollTop : 0, y: window.pageYOffset || 0 });
        window.location.reload();
    }
    function fmt(v) { var s = (Math.round(v * 10) / 10).toFixed(1).replace('.', ','); return s.slice(-2) === ',0' ? s.slice(0, -2) : s; }
    function gunMetni(tarih, uzun) {
        var p = tarih.split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
        try { return d.toLocaleDateString('tr-TR', uzun ? { day: 'numeric', month: 'long', weekday: 'long' } : { day: 'numeric', month: 'long' }); } catch (e) { return tarih; }
    }
    function ayMetni() { var p = AY.split('-'); return (AYLAR[Number(p[1]) - 1] || AY) + ' ' + p[0]; }
    function hataYaz(id, m) { var e = document.getElementById(id); if (!e) return; e.textContent = m || ''; e.hidden = !m; }

    function post(alanlar) {
        var g = new URLSearchParams();
        g.set('csrf', CSRF);
        Object.keys(alanlar).forEach(function (k) { g.set(k, alanlar[k]); });
        return fetch(URL_, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: g.toString()
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Sunucudan beklenmeyen yanıt geldi.' }; });
        }).catch(function () { return { ok: false, error: 'bağlantı kurulamadı.' }; });
    }

    /* JSON gövdeli POST (dizi alanları için: kisi_ids[], tarihler[]) */
    function postJson(alanlar) {
        var govde = {};
        Object.keys(alanlar).forEach(function (k) { govde[k] = alanlar[k]; });
        govde.csrf = CSRF;
        return fetch(URL_, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
            body: JSON.stringify(govde)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Sunucudan beklenmeyen yanıt geldi.' }; });
        }).catch(function () { return { ok: false, error: 'bağlantı kurulamadı.' }; });
    }

    /* ── Hücre + özet güncelleme (sunucu yanıtından sonra) ── */
    function hucreYaz(el, kod) {
        var mob = el.classList.contains('pc-t');
        el.setAttribute('data-kod', kod);
        Array.prototype.slice.call(el.classList).forEach(function (c) { if (c === 'pc-b' || c === 'pc-k' || c.indexOf('pc-k-') === 0) el.classList.remove(c); });
        var hedef = mob ? el.querySelector('.pc-t-ic') : el;
        if (!hedef) return;
        while (hedef.firstChild) hedef.removeChild(hedef.firstChild);
        var kd = kod ? KODLAR[kod] : null;
        if (!kd) { el.classList.add('pc-b'); hedef.textContent = '·'; el.removeAttribute('title'); return; }
        el.classList.add('pc-k', 'pc-k-' + kd.sinif);
        var b = document.createElement('b'); b.textContent = kod; hedef.appendChild(b);
        if (kd.agirlik > 0 && kd.agirlik < 1) { var i = document.createElement('i'); i.textContent = '½'; hedef.appendChild(i); }
        el.setAttribute('title', kd.ad);
    }
    function ozetleriHesapla() {
        var gunSay = {}, genel = { gun: 0, izin: 0, rapor: 0, devamsiz: 0 };
        sayfa.querySelectorAll('tr[data-pg-satir]').forEach(function (tr) {
            var oz = { gun: 0, izin: 0, rapor: 0, devamsiz: 0 };
            tr.querySelectorAll('td[data-tarih]').forEach(function (td) {
                var kd = KODLAR[td.getAttribute('data-kod') || ''];
                if (!kd) return;
                if (kd.agirlik > 0) { oz.gun += kd.agirlik; var t = td.getAttribute('data-tarih'); gunSay[t] = (gunSay[t] || 0) + 1; }
                else if (kd.grup === 'izin') oz.izin++;
                else if (kd.grup === 'rapor') oz.rapor++;
                else if (kd.grup === 'devamsiz') oz.devamsiz++;
            });
            ['gun', 'izin', 'rapor', 'devamsiz'].forEach(function (k) {
                genel[k] += oz[k];
                var h = tr.querySelector('td[data-oz="' + k + '"]'); if (h) h.textContent = k === 'gun' ? fmt(oz[k]) : String(oz[k]);
            });
            var det = sayfa.querySelector('details.pc-kisi[data-emp-kisi="' + tr.getAttribute('data-pg-satir') + '"]');
            if (det) rozetYaz(det, '[data-rz]', 'data-rz', oz);
        });
        sayfa.querySelectorAll('td[data-pg-say]').forEach(function (td) { var n = gunSay[td.getAttribute('data-pg-say')] || 0; td.textContent = n > 0 ? String(n) : '·'; });
        ['gun', 'izin', 'rapor', 'devamsiz'].forEach(function (k) {
            var h = sayfa.querySelector('td[data-gz-oz="' + k + '"]'); if (h) h.textContent = k === 'gun' ? fmt(genel[k]) : String(genel[k]);
        });
        var kart = sayfa.querySelector('.pc-genel-kart');
        if (kart) rozetYaz(kart, '[data-gz]', 'data-gz', genel);
    }
    function rozetYaz(kap, secici, nitelik, oz) {
        var son = { gun: ' gün', izin: ' izin', rapor: ' rapor', devamsiz: ' devamsız' };
        kap.querySelectorAll(secici).forEach(function (el) {
            var k = el.getAttribute(nitelik);
            if (!(k in son)) return;
            el.textContent = (k === 'gun' ? fmt(oz[k]) : String(oz[k])) + son[k];
            if (k !== 'gun') el.hidden = oz[k] === 0;
        });
    }
    function degisimUygula(liste) {
        var indeks = {};   // "kisi|tarih" → hücreler (masaüstü td + mobil takvim kutusu); büyük listelerde sorgu başına tarama YOK
        sayfa.querySelectorAll('[data-pg][data-emp][data-tarih]').forEach(function (el) {
            var k = el.getAttribute('data-emp') + '|' + el.getAttribute('data-tarih');
            (indeks[k] = indeks[k] || []).push(el);
        });
        (liste || []).forEach(function (d) {
            (indeks[String(d.k).replace(/[^0-9]/g, '') + '|' + String(d.t).replace(/[^0-9-]/g, '')] || []).forEach(function (el) { hucreYaz(el, d.c || ''); });
        });
        ozetleriHesapla();
    }

    /* ── Açılır pencere yönetimi ── */
    function hepsiniGizle() {
        [menu, islem, pen].forEach(function (e) { e.hidden = true; e.classList.remove('pg-mesgul'); });
        ovl.hidden = true; ovl.classList.remove('pg-karart');
    }
    function kapat() {
        if (menu.hidden && islem.hidden && pen.hidden) return;
        var b = bag;
        hepsiniGizle();
        hataYaz('pgMenuHata', ''); hataYaz('pgIslemHata', ''); hataYaz('pgPenHata', '');
        if (b && b.el) { b.el.classList.remove('pi-sec'); try { b.el.focus && b.el.focus({ preventScroll: true }); } catch (e) { /* */ } }
        bag = null; penIs = null; mesgul = false;
    }
    function konumla(kutu, el) {
        if (mobil()) { kutu.style.left = kutu.style.top = kutu.style.right = ''; return; }
        var r = el.getBoundingClientRect();
        var w = kutu.offsetWidth, h = kutu.offsetHeight;
        var x = r.right + 6;
        if (x + w > window.innerWidth - 8) x = Math.max(8, r.left - w - 6);
        var y = Math.min(Math.max(8, r.top), Math.max(8, window.innerHeight - h - 8));
        kutu.style.left = x + 'px'; kutu.style.top = y + 'px';
    }
    function menuAc(kutu, el, baslik) {
        hepsiniGizle();
        el.classList.add('pi-sec');
        ovl.hidden = false; kutu.hidden = false;
        konumla(kutu, el);
        return baslik;
    }
    function kisiAdi(el) {
        var det = el.closest('details.pc-kisi');
        var a = det ? det.querySelector('.pc-isim-yazi') : null;
        if (!a) { var tr = el.closest('tr'); a = tr ? tr.querySelector('th .pc-isim-yazi') : null; }
        if (!a) return '';
        var t = a.firstChild ? a.firstChild.textContent : a.textContent;   // "(pasif)" etiketi ayrı <em>
        return String(t || '').trim();
    }

    /* Hücre kod menüsü (tek hücre) */
    function hucreMenusu(hucre) {
        var kod = hucre.getAttribute('data-kod') || '';
        bag = { tur: 'hucre', el: hucre };
        document.getElementById('pgMenuBaslik').textContent = kisiAdi(hucre) + ' · ' + gunMetni(hucre.getAttribute('data-tarih') || '', true);
        menu.querySelectorAll('[data-pg-kod]').forEach(function (b) {
            var k = b.getAttribute('data-pg-kod');
            if (k === '') b.disabled = kod === '';
            else b.setAttribute('aria-pressed', k === kod ? 'true' : 'false');
        });
        menuAc(menu, hucre);
        hataYaz('pgMenuHata', '');
        var ilk = menu.querySelector('[data-pg-kod]:not(:disabled)');
        if (ilk && !mobil()) ilk.focus({ preventScroll: true });
    }
    function kodSecildi(kod) {
        if (!bag || mesgul) return;
        if (bag.tur === 'hucre') {
            var hedef = bag.el;
            mesgul = true; hataYaz('pgMenuHata', ''); menu.classList.add('pg-mesgul');
            post({ action: 'gun_isaretle', kisi_id: hedef.getAttribute('data-emp'), tarih: hedef.getAttribute('data-tarih'), kod: kod }).then(function (j) {
                if (j && j.ok) { degisimUygula(j.degisim); kapat(); return; }
                mesgul = false; menu.classList.remove('pg-mesgul');
                hataYaz('pgMenuHata', 'Kaydedilemedi: ' + ((j && j.error) || 'bilinmeyen hata') + ' Lütfen tekrar deneyin.');
            });
            return;
        }
    }

    /* Kişi işlem menüsü (⋯) */
    function kisiMenusu(dugme) {
        var pasif = dugme.getAttribute('data-pasif') === '1';
        bag = { tur: 'kisi', el: dugme, id: dugme.getAttribute('data-pg-kisi'), ad: dugme.getAttribute('data-isim') || '', gorev: dugme.getAttribute('data-gorev') || '', pasif: pasif };
        document.getElementById('pgIslemBaslik').textContent = bag.ad;
        islem.querySelectorAll('[data-pg-islem]').forEach(function (b) {
            var k = b.getAttribute('data-pg-islem');
            b.hidden = pasif ? (k !== 'duzenle' && k !== 'geri') : (k === 'geri');
        });
        menuAc(islem, dugme);
        hataYaz('pgIslemHata', '');
        var ilk = islem.querySelector('[data-pg-islem]:not([hidden])');
        if (ilk && !mobil()) ilk.focus({ preventScroll: true });
    }
    function kisiIslemi(k) {
        if (!bag || bag.tur !== 'kisi' || mesgul) return;
        var b = bag;
        if (k === 'duzenle') { hepsiniGizle(); kisiPenceresi(b); return; }
        if (k === 'haftaici') {
            mesgul = true; hataYaz('pgIslemHata', ''); islem.classList.add('pg-mesgul');
            post({ action: 'gun_toplu', kisi_id: b.id, ay: AY, mod: 'haftaici_x' }).then(function (j) {
                if (j && j.ok) { degisimUygula(j.degisim); kapat(); return; }
                mesgul = false; islem.classList.remove('pg-mesgul');
                hataYaz('pgIslemHata', 'Kaydedilemedi: ' + ((j && j.error) || 'bilinmeyen hata'));
            });
            return;
        }
        if (k === 'temizle') {
            hepsiniGizle();
            pencereAc({
                baslik: 'Ayı temizle', tamam: 'Temizle', tehlike: true,
                mesaj: b.ad + ' için ' + ayMetni() + ' ayının tüm işaretleri silinecek. Onaylıyor musunuz?',
                is: function () {
                    return post({ action: 'gun_toplu', kisi_id: b.id, ay: AY, mod: 'ay_temizle' }).then(function (j) {
                        if (j && j.ok) { degisimUygula(j.degisim); return true; }
                        hataYaz('pgPenHata', 'Kaydedilemedi: ' + ((j && j.error) || 'bilinmeyen hata'));
                        return false;
                    });
                }
            });
            return;
        }
        if (k === 'kaldir') {
            hepsiniGizle();
            pencereAc({
                baslik: 'Kişiyi kaldır', tamam: 'Kaldır', tehlike: true,
                mesaj: b.ad + ' pasife alınacak (listeden kalkar). Kayıtlı işaretleri SİLİNMEZ; "Pasifleri de göster" ile görüp geri alabilirsiniz. Onaylıyor musunuz?',
                is: function () { return durumYaz(b.id, 0); }
            });
            return;
        }
        if (k === 'geri') {
            mesgul = true; islem.classList.add('pg-mesgul');
            durumYaz(b.id, 1).then(function (tamam) {
                if (!tamam) { mesgul = false; islem.classList.remove('pg-mesgul'); }
            });
        }
    }
    function durumYaz(id, aktif) {
        return post({ action: 'kisi_durum', kisi_id: id, aktif: String(aktif) }).then(function (j) {
            if (j && j.ok) { yenile(); return true; }
            var m = 'İşlem yapılamadı: ' + ((j && j.error) || 'bilinmeyen hata');
            hataYaz('pgPenHata', m); hataYaz('pgIslemHata', m);
            return false;
        });
    }

    /* Ad / onay penceresi */
    function pencereAc(o) {
        hepsiniGizle();
        document.getElementById('pgPenBaslik').textContent = o.baslik || '';
        var alan = document.getElementById('pgPenAlan');
        var mesaj = document.getElementById('pgPenMesaj');
        var tamam = document.getElementById('pgPenTamam');
        alan.hidden = !o.alanlar;
        mesaj.hidden = !o.mesaj; mesaj.textContent = o.mesaj || '';
        tamam.textContent = o.tamam || 'Kaydet';
        tamam.classList.toggle('pg-tehlike-dugme', !!o.tehlike);
        hataYaz('pgPenHata', '');
        penIs = o.is;
        ovl.hidden = false; ovl.classList.add('pg-karart'); pen.hidden = false; pen.classList.remove('pg-mesgul');
        if (o.alanlar) { var ad = document.getElementById('pgAd'); try { ad.focus(); ad.select(); } catch (e) { /* */ } }
        else { try { tamam.focus({ preventScroll: true }); } catch (e) { /* */ } }
        mesgul = false;
    }
    function kisiPenceresi(b) {
        var ad = document.getElementById('pgAd'), gorev = document.getElementById('pgGorev');
        var duz = b && b.id;
        ad.value = duz ? b.ad : ''; gorev.value = duz ? b.gorev : '';
        pencereAc({
            baslik: duz ? 'Kişiyi düzenle' : 'Kişi ekle', tamam: 'Kaydet', alanlar: true,
            is: function () {
                var adDeger = ad.value.replace(/\s+/g, ' ').trim();
                if (adDeger === '') { hataYaz('pgPenHata', 'Ad zorunludur.'); try { ad.focus(); } catch (e) { /* */ } return Promise.resolve(false); }
                var alanlar = duz ? { action: 'kisi_duzenle', kisi_id: b.id, ad: adDeger, gorev: gorev.value } : { action: 'kisi_ekle', ad: adDeger, gorev: gorev.value };
                return post(alanlar).then(function (j) {
                    if (j && j.ok) { yenile(); return true; }
                    hataYaz('pgPenHata', 'Kaydedilemedi: ' + ((j && j.error) || 'bilinmeyen hata'));
                    return false;
                });
            }
        });
    }
    function pencereTamam() {
        if (mesgul || !penIs) return;
        mesgul = true; pen.classList.add('pg-mesgul');
        var is = penIs;
        is().then(function (kapatilsin) {
            if (kapatilsin === true) { kapat(); return; }
            mesgul = false; pen.classList.remove('pg-mesgul');
        });
    }

    /* ── v555 Toplu seçim: kişi × gün ── */
    var arac = document.getElementById('pgSecArac');
    var cubuk = document.getElementById('pgCubuk');
    var cubukOzet = document.getElementById('pgCubukOzet');
    var secOzet = document.getElementById('pgSecOzet');
    var uygula = document.getElementById('pgUygula');
    var ustune = document.getElementById('pgUstune');
    var onay = document.getElementById('pgCubukOnay');
    var onayMetin = document.getElementById('pgCubukOnayMetin');
    var secKisi = {};      // kisi id → true (yalnız AKTİF kişiler seçilebilir)
    var secGun = {};       // tarih → true
    var secKod = null;     // null: kod seçilmedi · '' : Temizle · 'X'…
    var secMod = false;    // mobil "Toplu seç" modu
    var lpZaman = null, lpAtildi = false;

    function sayiAnahtar(o) { return Object.keys(o).filter(function (k) { return o[k]; }); }
    function gunDugmeleri() { return Array.prototype.slice.call(sayfa.querySelectorAll('.pg-gbtn[data-pg-gun]')); }
    function aktifKisiIdleri() {
        return Array.prototype.slice.call(sayfa.querySelectorAll('tr[data-pg-satir]:not(.pg-pasif)')).map(function (tr) { return tr.getAttribute('data-pg-satir'); });
    }
    function secimGoster() {
        var kisiler = sayiAnahtar(secKisi), gunler = sayiAnahtar(secGun);
        // kişi kutuları (masaüstü + mobil) + satır/kart vurgusu
        sayfa.querySelectorAll('input[data-pg-sec]').forEach(function (c) { c.checked = !!secKisi[c.getAttribute('data-pg-sec')]; });
        sayfa.querySelectorAll('tr[data-pg-satir]').forEach(function (tr) { tr.classList.toggle('pg-ksec', !!secKisi[tr.getAttribute('data-pg-satir')]); });
        sayfa.querySelectorAll('details.pc-kisi[data-emp-kisi]').forEach(function (d) { d.classList.toggle('pg-ksec', !!secKisi[d.getAttribute('data-emp-kisi')]); });
        var hepsi = sayfa.querySelector('[data-pg-sec-hepsi]');
        if (hepsi) {
            var tum = aktifKisiIdleri();
            hepsi.checked = tum.length > 0 && kisiler.length === tum.length;
            hepsi.indeterminate = kisiler.length > 0 && kisiler.length < tum.length;
        }
        // gün başlıkları + sütun/takvim hücreleri
        gunDugmeleri().forEach(function (b) {
            var acik = !!secGun[b.getAttribute('data-pg-gun')];
            b.setAttribute('aria-pressed', acik ? 'true' : 'false');
            if (b.parentNode) b.parentNode.classList.toggle('pg-gsec', acik);
        });
        sayfa.querySelectorAll('[data-pg][data-tarih]').forEach(function (el) { el.classList.toggle('pg-gsec', !!secGun[el.getAttribute('data-tarih')]); });
        // çubuk
        var n = kisiler.length, m = gunler.length;
        var ozet = n + ' kişi × ' + m + ' gün';
        if (cubukOzet) cubukOzet.textContent = ozet;
        if (secOzet) secOzet.textContent = (n || m) ? ozet + ' seçili' : '';
        if (cubuk) {
            var gorunur = n > 0 || m > 0;
            cubuk.hidden = !gorunur;
            sayfa.classList.toggle('pg-cubuk-acik', gorunur);
            if (!gorunur) { onayKapat(); sonucYaz(''); cubukHata(''); }
        }
        if (uygula) uygula.disabled = !(n > 0 && m > 0 && secKod !== null);
        sayfa.querySelectorAll('[data-pg-sec-kod]').forEach(function (b) { b.setAttribute('aria-pressed', secKod !== null && b.getAttribute('data-pg-sec-kod') === secKod ? 'true' : 'false'); });
    }
    function kisiToggle(id, acik) {
        id = String(id).replace(/[^0-9]/g, '');
        if (!id) return;
        if (acik === undefined) acik = !secKisi[id];
        if (acik) secKisi[id] = true; else delete secKisi[id];
    }
    function gunToggle(tarih, acik) {
        if (!tarih) return;
        if (acik === undefined) acik = !secGun[tarih];
        if (acik) secGun[tarih] = true; else delete secGun[tarih];
    }
    function secimTemizle() { secKisi = {}; secGun = {}; onayKapat(); secimGoster(); }
    function modAc(ac) {
        secMod = !!ac;
        sayfa.classList.toggle('pg-secmod', secMod);
        sayfa.querySelectorAll('[data-pg-secmod]').forEach(function (b) { b.setAttribute('aria-pressed', secMod ? 'true' : 'false'); });
        if (!secMod) secimTemizle();
    }
    function hizliGun(tur) {
        if (tur === 'temizle') { secGun = {}; secimGoster(); return; }
        var yeni = {};
        gunDugmeleri().forEach(function (b) {
            var hs = b.getAttribute('data-hs') === '1';
            if (tur === 'tum' || (tur === 'haftaici' && !hs) || (tur === 'haftasonu' && hs)) yeni[b.getAttribute('data-pg-gun')] = true;
        });
        secGun = yeni; secimGoster();
    }
    function cubukHata(m) { hataYaz('pgCubukHata', m); }
    function sonucYaz(m) { var e = document.getElementById('pgCubukSonuc'); if (e) { e.textContent = m || ''; e.hidden = !m; } }
    function onayKapat() { if (onay) onay.hidden = true; if (onayMetin) onayMetin.textContent = ''; }
    function kodAdi(kod) { return kod === '' ? 'işareti sil (Temizle)' : '"' + kod + ' — ' + ((KODLAR[kod] || {}).ad || kod) + '"'; }
    function onayIste() {
        if (secKod === null) return;
        var n = sayiAnahtar(secKisi).length, m = sayiAnahtar(secGun).length;
        if (!n || !m) return;
        var u = !!(ustune && ustune.checked);
        var metin = n + ' kişi için ' + m + ' gün ' + (secKod === '' ? 'işareti silinecek (dolu hücreler dahil; boşlar değişmez).' : kodAdi(secKod) + ' olarak işaretlenecek. ' + (u ? 'Dolu günlerin üzerine YAZILACAK.' : 'Yalnız boş günler doldurulur; dolu günlere dokunulmaz.')) + ' Onaylıyor musunuz?';
        if (onayMetin) onayMetin.textContent = metin;
        if (onay) onay.hidden = false;
        sonucYaz(''); cubukHata('');
    }
    function toplu() {
        if (mesgul || secKod === null) return;
        var kisiler = sayiAnahtar(secKisi), gunler = sayiAnahtar(secGun).sort();
        mesgul = true; cubuk.classList.add('pg-mesgul'); cubukHata('');
        postJson({ action: 'gun_toplu_sec', ay: AY, kisi_ids: kisiler, tarihler: gunler, kod: secKod, ustune_yaz: !!(ustune && ustune.checked) }).then(function (j) {
            mesgul = false; cubuk.classList.remove('pg-mesgul');
            if (j && j.ok) {
                degisimUygula(j.degisim);
                onayKapat();
                var m = j.yazilan + ' hücre ' + (j.kod === '' ? 'silindi' : 'işaretlendi') + (j.atlanan ? ', ' + j.atlanan + ' dolu gün atlandı (üzerine yazmak için kutuyu işaretleyin)' : '') + (j.degismedi ? ', ' + j.degismedi + ' hücre zaten aynıydı' : '') + (j.pasif_atlanan ? ', ' + j.pasif_atlanan + ' pasif kişi atlandı' : '') + '.';
                sonucYaz(m);
                return;
            }
            cubukHata('Kaydedilemedi: ' + ((j && j.error) || 'bilinmeyen hata'));
        });
    }

    /* ── Olaylar ── */
    sayfa.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;
        var ekle = t.closest('[data-pg-kisi-ekle]');
        if (ekle) { e.preventDefault(); bag = { tur: 'ekle', el: ekle }; kisiPenceresi(null); return; }
        var uc = t.closest('[data-pg-kisi]');
        if (uc) {
            e.preventDefault(); e.stopPropagation();
            if (bag && bag.el === uc && !islem.hidden) { kapat(); return; }
            kisiMenusu(uc); return;
        }
        if (t.closest('.pg-secbox, .pg-msec')) return;   // seçim kutuları kendi change olayını kullanır
        var gun = t.closest('[data-pg-gun]');
        if (gun) { gunToggle(gun.getAttribute('data-pg-gun')); secimGoster(); return; }
        var h = t.closest('[data-pg]');
        if (h && sayfa.contains(h)) {
            if (lpAtildi) { lpAtildi = false; e.preventDefault(); e.stopPropagation(); return; }
            if (mobil() && secMod && h.classList.contains('pc-t')) { gunToggle(h.getAttribute('data-tarih')); secimGoster(); return; }   // Toplu seç modunda dokunma = gün seçimi
            if (bag && bag.el === h && !menu.hidden) { kapat(); return; }
            hucreMenusu(h);
        }
    }, true);

    sayfa.addEventListener('change', function (e) {
        var c = e.target;
        if (!c || !c.getAttribute) return;
        if (c.hasAttribute('data-pg-sec')) { kisiToggle(c.getAttribute('data-pg-sec'), c.checked); secimGoster(); return; }
        if (c.hasAttribute('data-pg-sec-hepsi')) {
            secKisi = {};
            if (c.checked) aktifKisiIdleri().forEach(function (id) { secKisi[id] = true; });
            secimGoster();
        }
    });
    sayfa.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('button') : null;
        if (!b || !sayfa.contains(b)) return;
        if (b.hasAttribute('data-pg-gunsec')) { hizliGun(b.getAttribute('data-pg-gunsec')); return; }
        if (b.hasAttribute('data-pg-kisisec')) {
            var tum = aktifKisiIdleri(), hepsiSecili = tum.length > 0 && sayiAnahtar(secKisi).length === tum.length;
            secKisi = {};
            if (!hepsiSecili) tum.forEach(function (id) { secKisi[id] = true; });
            secimGoster(); return;
        }
        if (b.hasAttribute('data-pg-secmod')) { modAc(!secMod); return; }
        if (b.hasAttribute('data-pg-sec-kod')) { secKod = b.getAttribute('data-pg-sec-kod') || ''; onayKapat(); sonucYaz(''); secimGoster(); return; }
        if (b.hasAttribute('data-pg-sec-temizle')) { secimTemizle(); return; }
        if (b.hasAttribute('data-pg-uygula')) { onayIste(); return; }
        if (b.hasAttribute('data-pg-onay-iptal')) { onayKapat(); return; }
        if (b.hasAttribute('data-pg-onayla')) { toplu(); }
    });
    // mobil: takvim kutusunda UZUN BASIŞ "Toplu seç" modunu açar ve o günü seçer
    sayfa.addEventListener('pointerdown', function (e) {
        if (!mobil() || !e.target || !e.target.closest) return;
        var c = e.target.closest('.pc-t[data-pg]');
        if (!c) return;
        lpAtildi = false;
        clearTimeout(lpZaman);
        lpZaman = setTimeout(function () {
            lpAtildi = true;
            if (!secMod) modAc(true);
            gunToggle(c.getAttribute('data-tarih')); secimGoster();
        }, 520);
    });
    ['pointerup', 'pointercancel', 'pointerleave', 'scroll'].forEach(function (ad) { sayfa.addEventListener(ad, function () { clearTimeout(lpZaman); }, true); });
    secimGoster();
    ovl.addEventListener('click', kapat);
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('button') : null;
        if (!b) return;
        if (b.hasAttribute('data-pg-kapat') || b.hasAttribute('data-pg-pen-iptal')) { kapat(); return; }
        if (b.hasAttribute('data-pg-pen-tamam')) { pencereTamam(); return; }
        if (b.hasAttribute('data-pg-kod') && !b.disabled && menu.contains(b)) { kodSecildi(b.getAttribute('data-pg-kod') || ''); return; }
        if (b.hasAttribute('data-pg-islem') && islem.contains(b)) { kisiIslemi(b.getAttribute('data-pg-islem')); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && (!menu.hidden || !islem.hidden || !pen.hidden)) { e.preventDefault(); kapat(); return; }
        if (e.key === 'Enter' && !pen.hidden && e.target && (e.target.id === 'pgAd' || e.target.id === 'pgGorev')) { e.preventDefault(); pencereTamam(); }
    });
    window.addEventListener('resize', function () {
        if (bag && bag.el) { if (!menu.hidden) konumla(menu, bag.el); if (!islem.hidden) konumla(islem, bag.el); }
    });
    function izle() {
        if ((menu.hidden && islem.hidden) || !bag || !bag.el || mobil()) return;
        var r = bag.el.getBoundingClientRect();
        var w = wrap ? wrap.getBoundingClientRect() : { left: 0, right: window.innerWidth, top: 0, bottom: window.innerHeight };
        if (r.right < w.left || r.left > w.right || r.bottom < w.top || r.top > w.bottom) { kapat(); return; }
        konumla(menu.hidden ? islem : menu, bag.el);
    }
    if (wrap) wrap.addEventListener('scroll', izle, { passive: true });
    window.addEventListener('scroll', izle, { passive: true });

    window.pgApi = { post: post, postJson: postJson, pencereAc: pencereAc, kisiPenceresi: kisiPenceresi, yenile: yenile, hataYaz: hataYaz, mobil: mobil, csrf: CSRF, url: URL_, ay: AY };

    // Yenilemeden sonra: kaydırma konumunu ve (mobilde) açık kişiyi geri getir
    var d = depooku();
    if (d) {
        if (wrap) { wrap.scrollLeft = d.sl || 0; wrap.scrollTop = d.st || 0; }
        if (d.y) window.scrollTo(0, d.y);
        if (d.emp) {
            var kd = document.querySelector('details.pc-kisi[data-emp-kisi="' + String(d.emp).replace(/[^0-9]/g, '') + '"]');
            if (kd) kd.open = true;
        }
    }
})();
