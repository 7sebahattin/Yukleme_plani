/* Yükleme Planları listesi (records.php) — Palet Fişleri + Mal Kabul Fişleri görünümlü etkileşimli tablo.
 * Satıra tıklayınca plan açılır · ▸ üçgeni detay satırını açar/kapar · başlığa tık: sırala ·
 * başlık kenarından sürükle: genişlik · kenara çift tık: tüm sütunları sığdır ·
 * başlığı tutup sürükle: sütun sırası · başlığa çift tık: sütunları göster/gizle ·
 * süz satırı: sütunda geçen metne göre filtre · F2: yeni kayıt.
 * Genişlik/sıra/gizli tercihi KULLANICI BAZINDA sunucuda saklanır (records.php tercih_kaydet;
 * tablo yoksa tarayıcı localStorage'a düşer). */
(function () {
    'use strict';

    var cfg = window.RECORDS_LISTE || {};
    var page = document.getElementById('recPage');
    var table = document.getElementById('recTable');
    var wrap = document.getElementById('recWrap');
    var body = document.getElementById('recBody');
    var head = document.getElementById('recHead');
    if (!page || !table || !wrap || !body || !head) return;

    var filtreSatir = document.getElementById('recFiltreSatiri');
    var bosEl = document.getElementById('recEmpty');
    var temizleBtn = document.getElementById('recFiltreTemizle');
    var ozetEl = document.getElementById('recOzet');
    var countEl = document.getElementById('recCount');
    var durum = document.getElementById('recStatus');
    var dlg = document.getElementById('recSutunDialog');
    var sutunListe = document.getElementById('recSutunListe');

    var YEREL = 'asya.records.tablo.v1';
    var DETAY_COL = '_detay';
    var DETAY_GENISLIK = 40;
    var VARSAYILAN = {
        tarih: 118,
        urun_sahibi: 130,
        firma: 155,
        parti_no: 135,
        alici: 130,
        bolge: 105,
        urun: 130,
        casus_no: 110,
        palet: 68,
        kasa: 76,
        brut: 88,
        dara: 78,
        net: 92
    };
    var SAYISAL_KOLONLAR = ['tarih', 'palet', 'kasa', 'brut', 'dara', 'net'];

    var tr = function (s) { return String(s == null ? '' : s).toLocaleLowerCase('tr-TR'); };
    var fmt0 = function (n) { return Math.round(Number(n) || 0).toLocaleString('tr-TR'); };

    function veriBasliklari() {
        return Array.prototype.filter.call(head.children, function (th) {
            return th.dataset.col && th.dataset.col !== DETAY_COL;
        });
    }
    function veriSutunlari() {
        return veriBasliklari().map(function (th) { return th.dataset.col; });
    }
    function baslikMetni(th) {
        if (!th) return '';
        var metin = '';
        Array.prototype.forEach.call(th.childNodes, function (n) {
            if (n.nodeType === 3) metin += n.textContent;
        });
        metin = metin.trim();
        return metin || (th.dataset.col || '');
    }
    function durumYaz(t) { if (durum) durum.textContent = t || ''; }

    var anaSatirlar = Array.prototype.slice.call(body.querySelectorAll('tr.rec-main-row'));
    var detayHarita = Object.create(null);
    Array.prototype.forEach.call(body.querySelectorAll('tr.rec-detail-row'), function (dr) {
        if (dr.dataset.detailFor) detayHarita[dr.dataset.detailFor] = dr;
    });
    function detaySatiri(row) {
        if (!row) return null;
        var id = row.dataset.recordId;
        if (id && detayHarita[id]) return detayHarita[id];
        var next = row.nextElementSibling;
        return next && next.classList.contains('rec-detail-row') ? next : null;
    }

    // ── Tercihler (genişlik + sütun sırası + gizli sütunlar) ──
    var tercih = { genislik: {}, sira: [], gizli: [] };
    (function tercihYukle() {
        var gecerli = veriSutunlari();
        var ham = cfg.tercihSunucu ? cfg.tercih : null;
        if (!cfg.tercihSunucu) {
            try { ham = JSON.parse(localStorage.getItem(YEREL) || 'null'); } catch (e) { ham = null; }
        }
        if (ham && typeof ham === 'object') {
            var gen = {};
            if (ham.genislik && typeof ham.genislik === 'object') {
                Object.keys(ham.genislik).forEach(function (k) {
                    if (gecerli.indexOf(k) < 0) return;
                    var v = Number(ham.genislik[k]);
                    if (Number.isFinite(v) && v >= 44 && v <= 1200) gen[k] = Math.round(v);
                });
            }
            tercih = {
                genislik: gen,
                sira: Array.isArray(ham.sira) ? ham.sira.filter(function (c) { return gecerli.indexOf(c) >= 0; }) : [],
                gizli: Array.isArray(ham.gizli) ? ham.gizli.filter(function (c) { return gecerli.indexOf(c) >= 0; }) : []
            };
        }
    })();

    var gizli = new Set(tercih.gizli);
    if (gizli.size >= veriSutunlari().length) gizli.clear();

    var kayitZamanlayici = 0;
    function tercihKaydet() {
        clearTimeout(kayitZamanlayici);
        kayitZamanlayici = setTimeout(function () {
            tercih.gizli = Array.from(gizli);
            tercih.sira = veriSutunlari();
            if (!cfg.tercihSunucu) {
                try { localStorage.setItem(YEREL, JSON.stringify(tercih)); } catch (e) { /* depolama kapalı */ }
                return;
            }
            var fd = new FormData();
            fd.append('action', 'tercih_kaydet');
            fd.append('csrf', page.dataset.csrf || '');
            fd.append('anahtar', cfg.anahtar || 'records.tablo');
            fd.append('deger', JSON.stringify(tercih));
            fetch('records.php', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (r) { if (!r || !r.ok) durumYaz('Sütun ayarı kaydedilemedi.'); })
                .catch(function () { durumYaz('Sütun ayarı kaydedilemedi.'); });
        }, 500);
    }

    // ── Sütun sırası + genişliği + gizleme ──
    function moveColumn(fromIdx, toIdx) {
        var veriSayisi = veriBasliklari().length;
        if (fromIdx === toIdx || fromIdx < 0 || toIdx < 0 || fromIdx >= veriSayisi || toIdx >= veriSayisi) return;
        var satirlar = [head];
        if (filtreSatir) satirlar.push(filtreSatir);
        for (var i = 0; i < anaSatirlar.length; i++) satirlar.push(anaSatirlar[i]);
        satirlar.forEach(function (row) {
            var items = Array.prototype.slice.call(row.children);
            var item = items[fromIdx];
            var ref = items[toIdx];
            if (item && ref && item.dataset.col !== DETAY_COL && ref.dataset.col !== DETAY_COL) {
                row.insertBefore(item, fromIdx < toIdx ? ref.nextSibling : ref);
            }
        });
    }
    function siraKaydet() {
        tercih.sira = veriSutunlari();
        tercihKaydet();
    }
    function siraUygula() {
        var mevcut = veriSutunlari();
        if (!tercih.sira.length) return;
        var hedef = tercih.sira.filter(function (c) { return mevcut.indexOf(c) >= 0; })
            .concat(mevcut.filter(function (c) { return tercih.sira.indexOf(c) < 0; }));
        hedef.forEach(function (col, i) {
            var simdi = veriSutunlari().indexOf(col);
            if (simdi >= 0 && simdi !== i) moveColumn(simdi, i);
        });
    }

    var gizliStil = document.head.appendChild(document.createElement('style'));
    function genislikUygula() {
        var toplam = 0;
        Array.prototype.forEach.call(head.children, function (th) {
            var c = th.dataset.col;
            if (!c) return;
            var w;
            if (c === DETAY_COL) {
                w = DETAY_GENISLIK;
            } else {
                if (gizli.has(c)) return;
                w = tercih.genislik[c] || VARSAYILAN[c] || 120;
            }
            var fTh = filtreSatir ? filtreSatir.querySelector('th[data-col="' + c + '"]') : null;
            [th, fTh].forEach(function (el) {
                if (el) { el.style.width = el.style.minWidth = el.style.maxWidth = w + 'px'; }
            });
            toplam += w;
        });
        table.style.width = toplam + 'px';
    }
    function gizliUygula() {
        gizliStil.textContent = Array.from(gizli).map(function (c) {
            return '#recTable [data-col="' + c + '"]{display:none}';
        }).join('');
        var gorVeri = veriSutunlari().filter(function (c) { return !gizli.has(c); }).length;
        var detayVar = !!head.querySelector('th[data-col="' + DETAY_COL + '"]');
        var colspan = gorVeri + (detayVar ? 1 : 0);
        Array.prototype.forEach.call(body.querySelectorAll('tr.rec-detail-row > td'), function (td) {
            td.colSpan = colspan;
        });
        genislikUygula();
    }
    function varsayilanGenislikler() {
        var gor = veriSutunlari().filter(function (c) { return !gizli.has(c); });
        if (!gor.length) return;
        var detayVar = !!head.querySelector('th[data-col="' + DETAY_COL + '"]');
        var detayW = detayVar ? DETAY_GENISLIK : 0;
        var top = gor.reduce(function (s, c) { return s + (VARSAYILAN[c] || 120); }, 0);
        var oran = top > 0 ? Math.max(1, (wrap.clientWidth - 24 - detayW) / top) : 1;
        gor.forEach(function (c) {
            if (!tercih.genislik[c]) tercih.genislik[c] = Math.round((VARSAYILAN[c] || 120) * oran);
        });
    }
    function filtreSatiriKonum() {
        if (!filtreSatir) return;
        var h = head.getBoundingClientRect().height;
        Array.prototype.forEach.call(filtreSatir.children, function (th) {
            th.style.top = h + 'px';
        });
    }

    // ── İçeriğe göre daralt (çift tık .pf-rs) ──
    var olcum = document.createElement('canvas').getContext('2d');
    function icerikGenisligi(col) {
        var th = head.querySelector('th[data-col="' + col + '"]');
        if (!th || !olcum) return VARSAYILAN[col] || 120;
        var ornek = body.querySelector('tr.rec-main-row td[data-col="' + col + '"]');
        var stilT = getComputedStyle(th);
        var stilH = ornek ? getComputedStyle(ornek) : stilT;
        olcum.font = stilT.fontWeight + ' ' + stilT.fontSize + ' ' + stilT.fontFamily;
        var en = olcum.measureText(baslikMetni(th)).width + 30;
        olcum.font = stilH.fontWeight + ' ' + stilH.fontSize + ' ' + stilH.fontFamily;
        Array.prototype.forEach.call(body.querySelectorAll('tr.rec-main-row td[data-col="' + col + '"]'), function (td) {
            var metin = String(td.textContent || '').replace(/\s+/g, ' ').trim();
            en = Math.max(en, olcum.measureText(metin).width + 22);
        });
        return Math.max(44, Math.min(1200, Math.ceil(en)));
    }
    function hepsiniDaralt() {
        veriBasliklari().forEach(function (th) {
            var c = th.dataset.col;
            tercih.genislik[c] = icerikGenisligi(c);
        });
        genislikUygula();
        tercihKaydet();
        durumYaz('Sütunlar içeriğe göre daraltıldı.');
    }

    // ── Sütun kenarından sürükle: genişlik + çift tık: tüm sütunları sığdır ──
    var siralaZaman = 0;
    veriBasliklari().forEach(function (th) {
        var tutac = document.createElement('i');
        tutac.className = 'pf-rs';
        tutac.title = 'Sürükleyin: genişlik · Çift tık: tüm sütunları sığdır';
        th.appendChild(tutac);
        tutac.addEventListener('click', function (e) { e.stopPropagation(); });
        tutac.addEventListener('dblclick', function (e) {
            e.stopPropagation();
            e.preventDefault();
            clearTimeout(siralaZaman);
            hepsiniDaralt();
        });
        tutac.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) return;
            e.preventDefault();
            e.stopPropagation();
            var x0 = e.clientX;
            var w0 = th.getBoundingClientRect().width;
            var c = th.dataset.col;
            tutac.setPointerCapture(e.pointerId);
            document.body.classList.add('pf-boyutlaniyor');
            var hareket = function (ev) {
                tercih.genislik[c] = Math.max(44, Math.min(1200, Math.round(w0 + ev.clientX - x0)));
                genislikUygula();
            };
            var bitti = function () {
                tutac.removeEventListener('pointermove', hareket);
                tutac.removeEventListener('pointerup', bitti);
                tutac.removeEventListener('pointercancel', bitti);
                document.body.classList.remove('pf-boyutlaniyor');
                tercihKaydet();
            };
            tutac.addEventListener('pointermove', hareket);
            tutac.addEventListener('pointerup', bitti);
            tutac.addEventListener('pointercancel', bitti);
        });
    });

    // ── Sütun sürükle-bırak sıralama (Palet Fişleri ile birebir) ──
    var surukle = null;
    var holdTimer = 0;
    var justDragged = false;
    var justDraggedTimer = 0;

    function suruklemeBaslat(th, event) {
        clearTimeout(siralaZaman);
        var r = th.getBoundingClientRect();
        var ghost = document.createElement('div');
        ghost.className = 'pf-hayalet';
        ghost.textContent = baslikMetni(th);
        ghost.style.width = r.width + 'px';
        ghost.style.height = r.height + 'px';
        var cizgi = document.createElement('div');
        cizgi.className = 'pf-birak';
        document.body.appendChild(ghost);
        document.body.appendChild(cizgi);
        document.body.classList.add('pf-suruklen');
        var col = th.dataset.col;
        th.classList.add('pf-drag-armed');
        Array.prototype.forEach.call(body.querySelectorAll('tr.rec-main-row td[data-col="' + col + '"]'), function (td) {
            td.classList.add('pf-kol-soluk');
        });
        surukle = {
            th: th,
            from: veriBasliklari().indexOf(th),
            ghost: ghost,
            cizgi: cizgi,
            sinir: 0,
            dx: event.clientX - r.left,
            x: event.clientX,
            raf: 0
        };
        durumYaz('Sütun taşınıyor — bırakılacak yeri mavi çizgi gösterir, Esc: vazgeç.');
        suruklemeKonum(event.clientX);
        surukleKaydir();
    }
    function suruklemeKonum(x) {
        var d = surukle;
        if (!d) return;
        d.x = x;
        d.ghost.style.left = (x - d.dx) + 'px';
        d.ghost.style.top = head.getBoundingClientRect().top + 'px';
        var tum = veriBasliklari();
        var gor = tum.filter(function (t) { return !gizli.has(t.dataset.col); }).map(function (t) {
            return { i: tum.indexOf(t), r: t.getBoundingClientRect() };
        });
        if (!gor.length) return;
        var b = gor[gor.length - 1].i + 1;
        var cx = gor[gor.length - 1].r.right;
        for (var i = 0; i < gor.length; i++) {
            var g = gor[i];
            if (x < g.r.left + g.r.width / 2) {
                b = g.i;
                cx = g.r.left;
                break;
            }
        }
        d.sinir = b;
        var w = wrap.getBoundingClientRect();
        d.cizgi.style.left = (Math.max(w.left, Math.min(w.right - 3, cx)) - 1) + 'px';
        d.cizgi.style.top = w.top + 'px';
        d.cizgi.style.height = wrap.clientHeight + 'px';
    }
    function surukleKaydir() {
        var d = surukle;
        if (!d) return;
        var w = wrap.getBoundingClientRect();
        if (d.x > w.right - 50) wrap.scrollLeft += 14;
        else if (d.x < w.left + 50) wrap.scrollLeft -= 14;
        suruklemeKonum(d.x);
        d.raf = requestAnimationFrame(surukleKaydir);
    }
    function suruklemeBitir(uygula) {
        var d = surukle;
        if (!d) return;
        cancelAnimationFrame(d.raf);
        surukle = null;
        clearTimeout(siralaZaman);
        justDragged = true;
        clearTimeout(justDraggedTimer);
        justDraggedTimer = setTimeout(function () { justDragged = false; }, 120);
        d.ghost.remove();
        d.cizgi.remove();
        document.body.classList.remove('pf-suruklen');
        d.th.classList.remove('pf-drag-armed');
        Array.prototype.forEach.call(body.querySelectorAll('.pf-kol-soluk'), function (td) {
            td.classList.remove('pf-kol-soluk');
        });
        var veriSayisi = veriBasliklari().length;
        var hedef = d.sinir > d.from ? d.sinir - 1 : d.sinir;
        hedef = Math.max(0, Math.min(veriSayisi - 1, hedef));
        if (uygula && hedef !== d.from) {
            moveColumn(d.from, hedef);
            siraKaydet();
            durumYaz('Sütun sırası kaydedildi.');
        } else {
            durumYaz(uygula ? '' : 'Sütun taşıma vazgeçildi.');
        }
    }

    head.addEventListener('pointerdown', function (event) {
        if (event.button !== 0 || event.target.closest('.pf-rs')) return;
        var th = event.target.closest('th[data-col]');
        if (!th || th.dataset.col === DETAY_COL) return;
        var x0 = event.clientX;
        var y0 = event.clientY;
        var dokunmatik = event.pointerType !== 'mouse';
        var basladi = false;
        var temizle = function () {
            clearTimeout(holdTimer);
            window.removeEventListener('pointermove', hareket);
            window.removeEventListener('pointerup', bitti);
            window.removeEventListener('pointercancel', iptal);
            window.removeEventListener('keydown', tus, true);
        };
        var hareket = function (e) {
            if (!basladi) {
                if (dokunmatik) {
                    if (Math.abs(e.clientX - x0) > 10 || Math.abs(e.clientY - y0) > 10) temizle();
                    return;
                }
                if (Math.abs(e.clientX - x0) < 6) return;
                basladi = true;
                suruklemeBaslat(th, e);
                return;
            }
            e.preventDefault();
            suruklemeKonum(e.clientX);
        };
        var bitti = function () { temizle(); suruklemeBitir(true); };
        var iptal = function () { temizle(); suruklemeBitir(false); };
        var tus = function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                iptal();
            }
        };
        if (dokunmatik) {
            holdTimer = window.setTimeout(function () {
                basladi = true;
                suruklemeBaslat(th, { clientX: x0, clientY: y0 });
            }, 380);
        }
        window.addEventListener('pointermove', hareket);
        window.addEventListener('pointerup', bitti);
        window.addEventListener('pointercancel', iptal);
        window.addEventListener('keydown', tus, true);
    });
    head.addEventListener('contextmenu', function (event) {
        if (surukle) event.preventDefault();
    });

    // ── Sütun görünürlüğü penceresi (#recSutunDialog) ──
    function sutunListeCiz() {
        if (!sutunListe) return;
        sutunListe.textContent = '';
        var basliklar = veriBasliklari();
        var toplam = basliklar.length;
        basliklar.forEach(function (th) {
            var c = th.dataset.col;
            var satir = document.createElement('label');
            satir.className = 'pf-sutun-satir';
            var kutu = document.createElement('input');
            kutu.type = 'checkbox';
            kutu.checked = !gizli.has(c);
            kutu.disabled = kutu.checked && toplam - gizli.size <= 1;
            kutu.addEventListener('change', function () {
                if (kutu.checked) gizli.delete(c); else gizli.add(c);
                tercih.gizli = Array.from(gizli);
                gizliUygula();
                tercihKaydet();
                sutunListeCiz();
            });
            var ad = document.createElement('span');
            ad.textContent = baslikMetni(th);
            satir.appendChild(kutu);
            satir.appendChild(ad);
            sutunListe.appendChild(satir);
        });
    }
    function sutunPenceresiAc() {
        if (!dlg || dlg.open) return;
        sutunListeCiz();
        dlg.showModal();
        var ilk = sutunListe ? sutunListe.querySelector('input:not(:disabled)') : null;
        if (ilk) ilk.focus();
    }
    head.addEventListener('dblclick', function (e) {
        var th = e.target.closest('th[data-col]');
        if (!th || th.dataset.col === DETAY_COL || e.target.closest('.pf-rs')) return;
        e.preventDefault();
        clearTimeout(siralaZaman);
        sutunPenceresiAc();
    });
    var sutunTamam = document.getElementById('recSutunTamam');
    var sutunKapat = document.getElementById('recSutunKapat');
    var sutunHepsi = document.getElementById('recSutunHepsi');
    if (sutunTamam && dlg) sutunTamam.addEventListener('click', function () { dlg.close(); });
    if (sutunKapat && dlg) sutunKapat.addEventListener('click', function () { dlg.close(); });
    if (sutunHepsi) {
        sutunHepsi.addEventListener('click', function () {
            gizli.clear();
            tercih.gizli = [];
            gizliUygula();
            tercihKaydet();
            sutunListeCiz();
        });
    }

    // ── Tek tıkla sıralama (detay satırları ana satırla eşli kalır) ──
    var sirala = { col: null, yon: 1 };
    function hucreDegeri(row, c) {
        var td = row.querySelector('td[data-col="' + c + '"]');
        if (!td) return '';
        if (td.dataset.sort != null) return td.dataset.sort;
        if (td.dataset.value != null) return td.dataset.value;
        return td.textContent;
    }
    function siralaUygula() {
        if (!sirala.col) return;
        var sayisal = SAYISAL_KOLONLAR.indexOf(sirala.col) >= 0;
        var dizi = anaSatirlar.slice().sort(function (a, b) {
            var x = hucreDegeri(a, sirala.col);
            var y = hucreDegeri(b, sirala.col);
            var s = sayisal
                ? (parseFloat(x) || 0) - (parseFloat(y) || 0)
                : String(x).localeCompare(String(y), 'tr', { numeric: true });
            return s * sirala.yon;
        });
        var frag = document.createDocumentFragment();
        dizi.forEach(function (r) {
            frag.appendChild(r);
            var det = detaySatiri(r);
            if (det) frag.appendChild(det);
        });
        body.appendChild(frag);
        anaSatirlar = dizi;
        Array.prototype.forEach.call(head.children, function (th) {
            th.classList.remove('kf-sirali-art', 'kf-sirali-azal');
        });
        var aktifTh = head.querySelector('th[data-col="' + sirala.col + '"]');
        if (aktifTh) aktifTh.classList.add(sirala.yon > 0 ? 'kf-sirali-art' : 'kf-sirali-azal');
    }
    head.addEventListener('click', function (e) {
        var th = e.target.closest('th[data-col]');
        if (!th || th.dataset.col === DETAY_COL || e.target.closest('.pf-rs') || justDragged) return;
        clearTimeout(siralaZaman);
        siralaZaman = setTimeout(function () {
            if (justDragged || surukle) return;
            var c = th.dataset.col;
            sirala = sirala.col === c ? { col: c, yon: -sirala.yon } : { col: c, yon: 1 };
            siralaUygula();
            durumYaz('');
        }, 230);
    });

    // ── Detay satırı aç/kapa ──
    function detayAcKapa(row, acikOlsun) {
        if (!row) return;
        var det = detaySatiri(row);
        var btn = row.querySelector('.rec-exp-btn');
        if (!det) return;
        var ac = typeof acikOlsun === 'boolean' ? acikOlsun : det.hidden;
        det.hidden = !ac;
        row.classList.toggle('rec-row-expanded', ac);
        if (btn) {
            btn.classList.toggle('is-open', ac);
            btn.setAttribute('aria-expanded', ac ? 'true' : 'false');
        }
    }

    // ── Satır içi süzgeçler ──
    var filtreler = {};
    function sonucuGuncelle() {
        var gorunen = 0;
        var top = { palet: 0, kasa: 0, brut: 0, dara: 0, net: 0 };
        var filtreAnahtarlari = Object.keys(filtreler);
        anaSatirlar.forEach(function (r) {
            var ok = true;
            for (var i = 0; i < filtreAnahtarlari.length; i++) {
                var c = filtreAnahtarlari[i];
                var q = filtreler[c];
                if (!q) continue;
                var td = r.querySelector('td[data-col="' + c + '"]');
                var metin = td ? (td.dataset.value != null ? td.dataset.value : td.textContent) : '';
                if (!td || tr(metin).indexOf(q) < 0) {
                    ok = false;
                    break;
                }
            }
            r.hidden = !ok;
            if (!ok) {
                detayAcKapa(r, false);
                return;
            }
            gorunen++;
            Object.keys(top).forEach(function (k) {
                var td = r.querySelector('td[data-col="' + k + '"]');
                top[k] += td ? (parseFloat(td.dataset.sort) || 0) : 0;
            });
        });
        if (countEl) countEl.textContent = String(gorunen);
        if (ozetEl) {
            var planEl = ozetEl.querySelector('[data-sum="plan"]');
            if (planEl) planEl.textContent = fmt0(gorunen);
            Object.keys(top).forEach(function (k) {
                var el = ozetEl.querySelector('[data-sum="' + k + '"]');
                if (el) el.textContent = fmt0(top[k]);
            });
        }
        if (bosEl) bosEl.hidden = gorunen > 0;
        if (temizleBtn) {
            var aktif = filtreAnahtarlari.some(function (c) { return !!filtreler[c]; });
            temizleBtn.hidden = !aktif;
        }
    }
    if (filtreSatir) {
        filtreSatir.addEventListener('input', function (e) {
            var inp = e.target.closest('input[data-f]');
            if (!inp) return;
            filtreler[inp.dataset.f] = tr(inp.value.trim());
            sonucuGuncelle();
        });
    }
    if (temizleBtn) {
        temizleBtn.addEventListener('click', function () {
            filtreler = {};
            if (filtreSatir) {
                Array.prototype.forEach.call(filtreSatir.querySelectorAll('input[data-f]'), function (inp) {
                    inp.value = '';
                });
            }
            sonucuGuncelle();
        });
    }

    // ── Detay üçgeni vs satıra tıklayarak plana gitme + klavye ──
    function planaGit(row) {
        if (row && row.dataset.href) window.location.href = row.dataset.href;
    }
    body.addEventListener('click', function (e) {
        var expHedef = e.target.closest('.rec-exp-btn, .rec-exp-td');
        if (expHedef) {
            e.stopPropagation();
            detayAcKapa(expHedef.closest('tr.rec-main-row'));
            return;
        }
        if (window.getSelection && String(window.getSelection()).length > 0) return;
        if (e.target.closest('a, button, input, select, textarea, label')) return;
        var row = e.target.closest('tr.rec-main-row');
        if (row) planaGit(row);
    });
    body.addEventListener('keydown', function (e) {
        var row = e.target.closest('tr.rec-main-row');
        if (!row) return;
        if (e.target.closest('input, textarea, select')) return;
        if (e.key === 'Enter') {
            if (e.target.closest('a, button')) return;
            e.preventDefault();
            planaGit(row);
        } else if (e.key === ' ' || e.key === 'Spacebar') {
            if (e.target.closest('a, button')) return;
            e.preventDefault();
            detayAcKapa(row);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var gor = anaSatirlar.filter(function (x) { return !x.hidden; });
            var idx = gor.indexOf(row);
            var hedef = gor[idx + (e.key === 'ArrowDown' ? 1 : -1)];
            if (hedef) hedef.focus();
        }
    });

    // ── F2 kısayolu: Yeni kayıt ──
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'F2' || e.ctrlKey || e.altKey || e.metaKey) return;
        if (!cfg.canCreate) return;
        if ((dlg && dlg.open) || document.querySelector('dialog[open]')) return;
        var active = document.activeElement;
        if (active && (active.matches('input, textarea, select') || active.isContentEditable)) return;
        e.preventDefault();
        window.location.href = 'record_create.php';
    });

    // ── Sayfa pencere yüksekliğine ölçeklenir (PC + mobil) ──
    function fitPage() {
        var ust = page.getBoundingClientRect().top + window.scrollY;
        if (window.innerWidth < 768) {
            var probe = document.createElement('div');
            probe.style.cssText = 'position:fixed;left:-9999px;bottom:0;height:0;padding-bottom:calc(var(--bn-h, 0px) + env(safe-area-inset-bottom, 0px))';
            document.body.appendChild(probe);
            var altBosluk = parseFloat(getComputedStyle(probe).paddingBottom) || 0;
            probe.remove();
            page.style.height = Math.max(240, window.innerHeight - ust - altBosluk - 8) + 'px';
        } else {
            page.style.height = Math.max(320, window.innerHeight - ust - 12) + 'px';
        }
        filtreSatiriKonum();
    }

    // ── İlk kurulum ──
    siraUygula();
    gizliUygula();
    varsayilanGenislikler();
    genislikUygula();
    filtreSatiriKonum();
    sonucuGuncelle();
    fitPage();
    window.addEventListener('resize', fitPage);
    window.addEventListener('load', fitPage);
})();
