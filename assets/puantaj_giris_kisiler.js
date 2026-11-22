/* puantaj_giris_kisiler.js — Aylık Puantaj › Puantaj Girişi › Kişiler görünümü (v555).
   Liste: Düzenle (ad + görev penceresi) · Pasife al / Aktif yap · SİL (kalıcı; onaylı, "tüm puantaj kayıtları silinecek"),
   çoklu seçim → toplu Pasife al / Aktif yap / Sil, "Excel'den ekle" (şablon → dosya seç → ÖNİZLEME (kaydetmez) → Ekle onayı → sonuç).
   Hepsi fetch → puantaj_giris_kaydet.php (CSRF; <form> YOK; dosya yükleme FormData + fetch); innerHTML YOK (textContent / createElement).
   puantaj_giris.js'in window.pgApi'sini (post, postJson, pencereAc, kisiPenceresi, yenile) kullanır; ondan SONRA yüklenir. */
(function () {
    'use strict';
    var api = window.pgApi;
    var sayfa = document.querySelector('.pc-page[data-pg-url][data-pg-gorunum="kisiler"]');
    if (!api || !sayfa) return;
    var ovl = document.getElementById('pgOvl');
    var cubuk = document.getElementById('pkCubuk');
    var cubukOzet = document.getElementById('pkCubukOzet');
    var sec = {};          // kişi id → true
    var mesgul = false;

    function idler() { return Object.keys(sec).filter(function (k) { return sec[k]; }); }
    function satir(id) { return sayfa.querySelector('tr[data-pk-satir="' + String(id).replace(/[^0-9]/g, '') + '"]'); }
    function hata(m) { var e = document.getElementById('pkHata'); if (e) { e.textContent = m || ''; e.hidden = !m; } }
    function cubukHata(m) { api.hataYaz('pkCubukHata', m); }
    function gunToplam(ids) {
        var t = 0;
        ids.forEach(function (id) { var tr = satir(id); if (tr) t += parseInt(tr.getAttribute('data-gun') || '0', 10) || 0; });
        return t;
    }

    function secimGoster() {
        var ids = idler();
        sayfa.querySelectorAll('input[data-pk-sec]').forEach(function (c) { c.checked = !!sec[c.getAttribute('data-pk-sec')]; });
        sayfa.querySelectorAll('tr[data-pk-satir]').forEach(function (tr) { tr.classList.toggle('pk-sec', !!sec[tr.getAttribute('data-pk-satir')]); });
        var hepsi = sayfa.querySelector('[data-pk-hepsi]');
        var tum = sayfa.querySelectorAll('tr[data-pk-satir]').length;
        if (hepsi) { hepsi.checked = tum > 0 && ids.length === tum; hepsi.indeterminate = ids.length > 0 && ids.length < tum; }
        if (cubuk) {
            cubuk.hidden = ids.length === 0;
            sayfa.classList.toggle('pg-cubuk-acik', ids.length > 0);
            if (cubukOzet) cubukOzet.textContent = ids.length + ' kişi seçili';
            if (ids.length === 0) cubukHata('');
        }
    }

    function listeYenile() { api.yenile(); }

    // ── Satır işlemleri ──
    function durumDegistir(tr) {
        if (mesgul) return;
        mesgul = true; hata('');
        var aktif = tr.getAttribute('data-aktif') === '1';
        api.post({ action: 'kisi_durum', kisi_id: tr.getAttribute('data-pk-satir'), aktif: aktif ? '0' : '1' }).then(function (j) {
            if (j && j.ok) { listeYenile(); return; }
            mesgul = false; hata('İşlem yapılamadı: ' + ((j && j.error) || 'bilinmeyen hata'));
        });
    }
    function silOnayi(tr) {
        var id = tr.getAttribute('data-pk-satir'), ad = tr.getAttribute('data-isim') || '', gun = parseInt(tr.getAttribute('data-gun') || '0', 10) || 0;
        api.pencereAc({
            baslik: 'Kişiyi sil', tamam: 'Kalıcı olarak sil', tehlike: true,
            mesaj: ad + ' ve tüm puantaj kayıtları (' + gun + ' gün kaydı) kalıcı olarak silinecek. Bu işlem geri alınamaz. Onaylıyor musunuz?',
            is: function () {
                return api.post({ action: 'kisi_sil', kisi_id: id }).then(function (j) {
                    if (j && j.ok) { listeYenile(); return true; }
                    api.hataYaz('pgPenHata', 'Silinemedi: ' + ((j && j.error) || 'bilinmeyen hata'));
                    return false;
                });
            }
        });
    }
    function topluIslem(islem) {
        var ids = idler();
        if (!ids.length || mesgul) return;
        var yap = function () {
            return api.postJson({ action: 'kisi_toplu', kisi_ids: ids, islem: islem }).then(function (j) {
                if (j && j.ok) { listeYenile(); return true; }
                var m = 'İşlem yapılamadı: ' + ((j && j.error) || 'bilinmeyen hata');
                cubukHata(m); api.hataYaz('pgPenHata', m);
                return false;
            });
        };
        if (islem === 'sil') {
            api.pencereAc({
                baslik: 'Seçili kişileri sil', tamam: 'Kalıcı olarak sil', tehlike: true,
                mesaj: ids.length + ' kişi ve tüm puantaj kayıtları (' + gunToplam(ids) + ' gün kaydı) kalıcı olarak silinecek. Bu işlem geri alınamaz. Onaylıyor musunuz?',
                is: yap
            });
            return;
        }
        mesgul = true; cubukHata('');
        yap().then(function (tamam) { if (!tamam) mesgul = false; });
    }

    sayfa.addEventListener('change', function (e) {
        var c = e.target;
        if (!c || !c.getAttribute) return;
        if (c.hasAttribute('data-pk-sec')) {
            var id = c.getAttribute('data-pk-sec');
            if (c.checked) sec[id] = true; else delete sec[id];
            secimGoster(); return;
        }
        if (c.hasAttribute('data-pk-hepsi')) {
            sec = {};
            if (c.checked) sayfa.querySelectorAll('tr[data-pk-satir]').forEach(function (tr) { sec[tr.getAttribute('data-pk-satir')] = true; });
            secimGoster();
        }
    });
    sayfa.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('button') : null;
        if (!b || !sayfa.contains(b)) return;
        if (b.hasAttribute('data-pk-excel-ac')) { excelAc(); return; }
        if (b.hasAttribute('data-pk-sec-temizle')) { sec = {}; secimGoster(); return; }
        if (b.hasAttribute('data-pk-toplu')) { topluIslem(b.getAttribute('data-pk-toplu')); return; }
        var is = b.getAttribute('data-pk-islem');
        if (!is) return;
        var tr = b.closest('tr[data-pk-satir]');
        if (!tr) return;
        if (is === 'duzenle') api.kisiPenceresi({ id: tr.getAttribute('data-pk-satir'), ad: tr.getAttribute('data-isim') || '', gorev: tr.getAttribute('data-gorev') || '' });
        else if (is === 'durum') durumDegistir(tr);
        else if (is === 'sil') silOnayi(tr);
    });

    // ── Excel'den toplu ekleme ──
    var pen = document.getElementById('pkExcel');
    var dosya = document.getElementById('pkDosya');
    var onizleme = document.getElementById('pkExcelOnizleme');
    var ozetKap = document.getElementById('pkExcelOzet');
    var govde = document.getElementById('pkExcelGovde');
    var not = document.getElementById('pkExcelNot');
    var ekleBtn = document.getElementById('pkExcelEkle');
    var vazgecBtn = document.getElementById('pkExcelVazgec');
    var adim1 = document.getElementById('pkExcelAdim1');
    var eklendi = false;
    var DURUM = {
        gecerli: ['Eklenecek', 'pk-d-gecerli'], bos: ['Ad boş', 'pk-d-sorun'], uzun: ['Çok uzun', 'pk-d-sorun'],
        mukerrer_mevcut: ['Zaten var', 'pk-d-mukerrer'], mukerrer_dosya: ['Dosyada tekrar', 'pk-d-mukerrer']
    };

    function excelHata(m) { api.hataYaz('pkExcelHata', m); }
    function excelSonuc(m) { var e = document.getElementById('pkExcelSonuc'); if (e) { e.textContent = m || ''; e.hidden = !m; } }
    function excelSifirla() {
        eklendi = false;
        if (dosya) dosya.value = '';
        if (onizleme) onizleme.hidden = true;
        if (adim1) adim1.hidden = false;
        if (govde) while (govde.firstChild) govde.removeChild(govde.firstChild);
        if (ozetKap) while (ozetKap.firstChild) ozetKap.removeChild(ozetKap.firstChild);
        if (ekleBtn) { ekleBtn.disabled = true; ekleBtn.textContent = 'Ekle'; ekleBtn.hidden = false; }
        if (vazgecBtn) vazgecBtn.textContent = 'Vazgeç';
        excelHata(''); excelSonuc('');
    }
    function excelAc() {
        if (!pen) return;
        excelSifirla();
        if (ovl) { ovl.hidden = false; ovl.classList.add('pg-karart'); }
        pen.hidden = false;
        try { if (dosya) dosya.focus({ preventScroll: true }); } catch (e) { /* */ }
    }
    function excelKapat() {
        if (!pen || pen.hidden || mesgul) return;
        pen.hidden = true;
        if (ovl) { ovl.hidden = true; ovl.classList.remove('pg-karart'); }
        if (eklendi) listeYenile();
    }
    function chip(kap, metin, sayi, sinif) {
        var s = document.createElement('span');
        s.className = 'pk-chip' + (sinif ? ' ' + sinif : '');
        s.appendChild(document.createTextNode(metin + ' '));
        var b = document.createElement('b'); b.textContent = String(sayi); s.appendChild(b);
        kap.appendChild(s);
    }
    function gonder(eylem, f) {
        var fd = new FormData();
        fd.append('csrf', api.csrf);
        fd.append('action', eylem);
        fd.append('dosya', f, f.name);
        return fetch(api.url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Sunucudan beklenmeyen yanıt geldi.' }; }); })
            .catch(function () { return { ok: false, error: 'bağlantı kurulamadı.' }; });
    }
    function onizlemeCiz(j) {
        var o = j.ozet || {};
        while (ozetKap.firstChild) ozetKap.removeChild(ozetKap.firstChild);
        chip(ozetKap, 'Toplam', o.toplam || 0, '');
        chip(ozetKap, 'Geçerli', o.gecerli || 0, 'pk-chip-aktif');
        chip(ozetKap, 'Boş', o.bos || 0, '');
        chip(ozetKap, 'Çok uzun', o.uzun || 0, '');
        chip(ozetKap, 'Mükerrer', o.mukerrer || 0, 'pk-chip-pasif');
        while (govde.firstChild) govde.removeChild(govde.firstChild);
        (j.ilk || []).forEach(function (r) {
            var tr = document.createElement('tr');
            [String(r.satir), r.ad, r.gorev].forEach(function (m) { var td = document.createElement('td'); td.textContent = m; tr.appendChild(td); });
            var d = DURUM[r.durum] || [String(r.durum), ''];
            var td = document.createElement('td'); td.textContent = d[0]; td.className = d[1]; tr.appendChild(td);
            govde.appendChild(tr);
        });
        not.textContent = (o.toplam > (j.gosterilen || 0) ? 'İlk ' + j.gosterilen + ' satır gösteriliyor (toplam ' + o.toplam + '). ' : '') + (j.baslik_bulundu ? '' : 'Başlık satırı bulunamadı: ilk sütun ad, ikinci sütun görev kabul edildi. ') + 'Yalnız "Eklenecek" satırlar eklenir; diğerleri atlanır.';
        onizleme.hidden = false;
        ekleBtn.disabled = !(o.gecerli > 0);
        ekleBtn.textContent = o.gecerli > 0 ? 'Ekle (' + o.gecerli + ' kişi)' : 'Ekle';
    }
    if (dosya) dosya.addEventListener('change', function () {
        var f = dosya.files && dosya.files[0];
        excelHata(''); excelSonuc('');
        if (onizleme) onizleme.hidden = true;
        if (ekleBtn) { ekleBtn.disabled = true; ekleBtn.textContent = 'Ekle'; }
        if (!f) return;
        if (!/\.(xlsx|csv)$/i.test(f.name)) { excelHata('Yalnız .xlsx ya da .csv dosyası seçin.'); return; }
        if (f.size > 2 * 1024 * 1024) { excelHata('Dosya çok büyük (en çok 2 MB).'); return; }
        mesgul = true; pen.classList.add('pg-mesgul');
        gonder('excel_onizle', f).then(function (j) {
            mesgul = false; pen.classList.remove('pg-mesgul');
            if (j && j.ok) { onizlemeCiz(j); return; }
            excelHata((j && j.error) || 'Dosya okunamadı.');
        });
    });
    if (ekleBtn) ekleBtn.addEventListener('click', function () {
        var f = dosya && dosya.files && dosya.files[0];
        if (!f || mesgul || ekleBtn.disabled) return;
        mesgul = true; pen.classList.add('pg-mesgul'); excelHata('');
        gonder('excel_ekle', f).then(function (j) {
            mesgul = false; pen.classList.remove('pg-mesgul');
            if (j && j.ok) {
                eklendi = true;
                onizleme.hidden = true; adim1.hidden = true; ekleBtn.disabled = true; ekleBtn.hidden = true;
                vazgecBtn.textContent = 'Kapat';
                excelSonuc(j.eklenen + ' kişi eklendi' + (j.atlanan ? ', ' + j.atlanan + ' satır atlandı (boş / çok uzun / mükerrer)' : '') + '.');
                return;
            }
            excelHata('Eklenemedi: ' + ((j && j.error) || 'bilinmeyen hata'));
        });
    });
    if (pen) pen.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('button') : null;
        if (b && b.hasAttribute('data-pk-excel-kapat')) excelKapat();
    });
    if (ovl) ovl.addEventListener('click', function () { if (pen && !pen.hidden) excelKapat(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pen && !pen.hidden) { e.preventDefault(); excelKapat(); } });
    secimGoster();
})();
