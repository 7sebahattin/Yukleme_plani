<?php
// =========================================================
// _yukleme_bildir_modal.php — "Yükleme Bildir" penceresi (v498)
// Beyan OLMADAN yapılan SATIŞ yüklemesi için HKS TASLAĞI açar; HKS'e hiçbir şey
// göndermez. Fiyat burada YOK — Hal Kayıt'ta gönderimde girilir.
// Yalnız can_yukleme_bildir() olanlara include edilir (beyanlar.php,
// _beyansiz_sekme.php). Fonksiyon TANIMLAMAZ.
// =========================================================
if (!function_exists('can_yukleme_bildir') || !can_yukleme_bildir()) return;
?>
<div id="bszOverlay" class="beyan-hks-overlay bsz-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="bszTitle">
  <div class="beyan-hks-dialog" style="max-width:640px">
    <div class="beyan-hks-head">
      <strong id="bszTitle">🚚 Yükleme Bildir <span class="beyan-hks-rozet">beyansız · satış</span></strong>
      <button type="button" class="beyan-hks-x" id="bszKapat" aria-label="Kapat">✕</button>
    </div>
    <div class="beyan-hks-body">
      <div id="bszYukleniyor" class="muted" style="padding:20px;text-align:center">Hazırlanıyor…</div>
      <div id="bszHata" class="flash flash-error" hidden></div>
      <div id="bszIcerik" hidden>
        <form class="bf bsz-bf" id="bszForm" novalidate onsubmit="return false">
        <section class="beyan-section" data-bf="lojistik">
          <div class="beyan-form-grid bsz-grid">
            <div class="form-group" data-ic="diyez">
              <label class="form-label" for="bszParti">Parti No</label>
              <input type="text" id="bszParti" class="form-control" maxlength="60" autocomplete="off">
            </div>
            <div class="form-group" data-ic="bina">
              <label class="form-label" for="bszFirma">HKS Firma <strong class="bsz-zor">*</strong></label>
              <select id="bszFirma" class="form-control"></select>
            </div>

            <fieldset class="bsz-yon bsz-tam">
              <legend>Alıcı</legend>
              <label class="bsz-radyo"><input type="radio" name="bszYon" value="yurt_disi" checked> Yurt dışı (ihracat) — ülke</label>
              <label class="bsz-radyo"><input type="radio" name="bszYon" value="yurt_ici"> Yurt içi — firma</label>
            </fieldset>

            <div class="form-group bsz-tam" data-ic="kure" id="bszUlkeKap">
              <label class="form-label" for="bszUlke">Ülke <strong class="bsz-zor">*</strong></label>
              <select id="bszUlke" class="form-control" data-aramali="Ülke yazın veya seçin"></select>
            </div>
            <div class="form-group bsz-tam" data-ic="kisi" id="bszKisiKap" hidden>
              <label class="form-label" for="bszKisi">Firma <strong class="bsz-zor">*</strong></label>
              <select id="bszKisi" class="form-control" data-aramali="Firma yazın veya seçin"></select>
              <button type="button" class="bsz-yeni-bag" id="bszYeniAc">＋ Listede yok — yeni firma ekle</button>
            </div>
            <div class="form-group bsz-tam" data-ic="bina" id="bszYeniKap" hidden>
              <label class="form-label" for="bszYeniAd">Yeni firma adı <strong class="bsz-zor">*</strong></label>
              <input type="text" id="bszYeniAd" class="form-control" maxlength="150" autocomplete="off" data-uppercase="tr">
              <button type="button" class="bsz-yeni-bag" id="bszYeniIptal">↩ Listeden seç</button>
            </div>

            <div class="form-group bsz-tam" data-ic="elma">
              <label class="form-label" for="bszUrun">Ürün <strong class="bsz-zor">*</strong></label>
              <select id="bszUrun" class="form-control" data-aramali="Ürün yazın veya seçin"></select>
            </div>
            <div class="form-group bsz-vurgu bsz-vurgu-kg" data-ic="terazi">
              <label class="form-label" for="bszKg">Net KG <strong class="bsz-zor">*</strong></label>
              <input type="text" id="bszKg" class="form-control" inputmode="numeric" autocomplete="off" placeholder="örn. 20.000">
            </div>
            <div class="form-group bsz-vurgu bsz-vurgu-plaka" data-ic="arac">
              <label class="form-label" for="bszPlaka">Araç Plakası <strong class="bsz-zor">*</strong></label>
              <input type="text" id="bszPlaka" class="form-control" data-nospace data-uppercase="tr" autocomplete="off" maxlength="30">
            </div>
          </div>
        </section>
        </form>

        <div id="bszPlakaBand" class="bsz-plaka-band" hidden role="status">
          <span id="bszPlakaMetin"></span>
          <button type="button" class="btn btn-sm" id="bszYineDe">Yine de bildir</button>
        </div>

        <p class="beyan-hks-warn">Bu işlem yalnızca <strong>TASLAK</strong> oluşturur; HKS'e bildirim <strong>GÖNDERİLMEZ</strong>.</p>
      </div>
    </div>
    <div class="beyan-hks-foot">
      <button type="button" class="btn btn-ghost" id="bszVazgec">Vazgeç</button>
      <button type="button" class="btn btn-primary" id="bszKaydet" disabled>Taslağa Kaydet</button>
    </div>
  </div>
</div>

<script>
(function () {
    var CSRF = <?= json_encode(csrf_token()) ?>;
    var API  = 'api_beyansiz_yukleme.php';
    var ov   = document.getElementById('bszOverlay');
    if (!ov) return;
    var el   = function (i) { return document.getElementById(i); };
    var veri = null, yukleniyor = false, istekKodu = '', plakaOnayli = false, gonderiyor = false, plakaKontrolNo = 0;
    var MIGRATE = <?= json_encode(is_admin() ? 'migrate.php#beyansiz-yukleme' : '') ?>;

    function uret() {
        try { if (window.crypto && crypto.randomUUID) return 'b' + crypto.randomUUID().replace(/-/g, '').slice(0, 30); } catch (_) {}
        return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }
    function doldur(sel, liste, bos) {
        sel.innerHTML = '<option value="">' + bos + '</option>';
        liste.forEach(function (x) {
            var o = document.createElement('option');
            o.value = x.id; o.textContent = x.ad;          // kullanıcı verisi: textContent
            sel.appendChild(o);
        });
        if (sel._araTazele) sel._araTazele();
    }
    // Net KG: yazarken binlik nokta (20000 → 20.000); KG tam sayı gösterilir. İmleç korunur.
    function kgSayi() { return parseInt(String(el('bszKg').value).replace(/\D/g, ''), 10) || 0; }
    function kgBicimle() {
        var k = el('bszKg'), v = k.value, pos = k.selectionStart || 0;
        var solRakam = v.slice(0, pos).replace(/\D/g, '').length;
        var d = v.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        var yeni = d.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        if (yeni === v) return;
        k.value = yeni;
        var n = 0, i = 0;
        while (i < yeni.length && n < solRakam) { if (/\d/.test(yeni.charAt(i))) n++; i++; }
        try { k.setSelectionRange(i, i); } catch (_) {}
    }
    function yon() { var r = document.querySelector('input[name="bszYon"]:checked'); return r ? r.value : 'yurt_disi'; }
    function hataGoster(msg) {
        el('bszYukleniyor').hidden = true;
        el('bszHata').textContent = msg;
        if (MIGRATE && /kurulu değil|kurulmadı/i.test(msg)) {
            var a = document.createElement('a'); a.href = MIGRATE; a.textContent = ' Kurulum sayfası'; el('bszHata').appendChild(a);
        }
        el('bszHata').hidden = false;
    }
    var yeniModu = false;
    function yeniAd() { return el('bszYeniAd').value.replace(/\s+/g, ' ').trim(); }
    function yeniUygula(v) {
        yeniModu = v;
        if (v) el('bszKisi').value = '';
        if (el('bszKisi')._araTazele) el('bszKisi')._araTazele();
        el('bszYeniKap').hidden = !v || yon() !== 'yurt_ici';
        el('bszKisiKap').hidden = v || yon() !== 'yurt_ici';
        kontrol();
    }
    function kontrol() {
        var ok = el('bszFirma').value && el('bszUrun').value && el('bszPlaka').value.trim() !== '' &&
                 kgSayi() > 0 &&
                 (yon() === 'yurt_ici' ? (yeniModu ? yeniAd().length >= 3 : el('bszKisi').value) : el('bszUlke').value);
        el('bszKaydet').disabled = !ok || gonderiyor;
    }
    function yonUygula() {
        var ici = yon() === 'yurt_ici';
        el('bszUlkeKap').hidden = ici;
        el('bszKisiKap').hidden = !ici || yeniModu;
        el('bszYeniKap').hidden = !ici || !yeniModu;
        kontrol();
    }
    function bandKapat() { el('bszPlakaBand').hidden = true; plakaOnayli = false; }
    function bandAc(liste) {
        var m = 'Bugün bu plakaya ' + liste.length + ' kayıt var: ' + liste.map(function (x) {
            return x.saat + ' ' + x.urun + ' ' + Number(x.kg).toLocaleString('tr-TR') + ' kg';
        }).join(' · ');
        el('bszPlakaMetin').textContent = m;
        el('bszYineDe').hidden = false;
        el('bszPlakaBand').hidden = false;
    }
    var sonPlaka = null;
    function plakaKontrol() {
        var p = el('bszPlaka').value.trim();
        if (p === sonPlaka) return;      // değişmediyse bandı/onayı bozma
        sonPlaka = p;
        bandKapat();
        if (!p) return;
        var no = ++plakaKontrolNo;
        fetch(API + '?action=plaka_kontrol', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ plaka: p })
        }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
            if (no !== plakaKontrolNo || !j || !j.liste || !j.liste.length) return;
            bandAc(j.liste);
        }).catch(function () {});
    }

    function ac() {
        ov.hidden = false;
        document.body.style.overflow = 'hidden';
        istekKodu = uret();
        sonPlaka = null;
        bandKapat();
        if (veri || yukleniyor) { if (veri) { kontrol(); } return; }
        yukleniyor = true;
        fetch(API + '?action=hazirla', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            yukleniyor = false;
            if (!res.ok) { hataGoster(res.j.hata || 'Bilgiler alınamadı.'); return; }
            var j = res.j;
            if (!j.tabloVar) { hataGoster('Beyansız yükleme tablosu kurulu değil — yönetici kurulumu yapmalı.'); return; }
            if (j.katalogYok) { hataGoster('HKS katalog listeleri henüz indirilmemiş. Hal Kayıt panelinde "Listeleri Güncelle"ye basın.'); return; }
            veri = j;
            doldur(el('bszFirma'), j.firmalar, 'Firma seçin');        // hiçbiri önceden seçili gelmez
            doldur(el('bszUlke'), j.ulkeler, '— seçilmedi —');
            doldur(el('bszKisi'), j.kisiler, '— seçilmedi —');
            doldur(el('bszUrun'), j.urunler, '— seçilmedi —');
            el('bszYukleniyor').hidden = true;
            el('bszIcerik').hidden = false;
            yonUygula();
        })
        .catch(function (e) { yukleniyor = false; hataGoster('Bağlantı hatası: ' + e.message); });
    }
    function kapat() { ov.hidden = true; document.body.style.overflow = ''; }

    function gonder(onayli) {
        if (gonderiyor) return;
        gonderiyor = true;
        el('bszKaydet').disabled = true;
        el('bszKaydet').textContent = 'Kaydediliyor…';
        var ici = yon() === 'yurt_ici';
        fetch(API + '?action=olustur', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf: CSRF, istek_kodu: istekKodu, yon: yon(),
                partiNo: el('bszParti').value, firmaId: el('bszFirma').value,
                ulkeId: ici ? '' : el('bszUlke').value, kisiId: ici && !yeniModu ? el('bszKisi').value : '',
                yeniFirmaAd: ici && yeniModu ? yeniAd() : '',
                urunId: el('bszUrun').value, kg: kgSayi(), plaka: el('bszPlaka').value,
                plakaOnay: !!onayli
            })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, st: r.status, j: j }; }); })
        .then(function (res) {
            gonderiyor = false;
            el('bszKaydet').textContent = 'Taslağa Kaydet';
            if (res.ok && res.j.tamam) {
                alert('✅ ' + res.j.mesaj);
                location.href = 'beyanlar.php?sekme=beyansiz';
                return;
            }
            if (res.st === 409 && res.j.kod === 'ayni_plaka') { bandAc(res.j.liste || []); kontrol(); return; }
            alert('HATA: ' + (res.j.hata || 'Taslak oluşturulamadı.'));
            kontrol();
        })
        .catch(function (e) {
            gonderiyor = false;
            el('bszKaydet').textContent = 'Taslağa Kaydet';
            alert('Bağlantı hatası: ' + e.message);
            kontrol();
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-bsz-ac]'), function (b) { b.addEventListener('click', ac); });
    el('bszKapat').addEventListener('click', kapat);
    el('bszVazgec').addEventListener('click', kapat);
    ov.addEventListener('click', function (e) { if (e.target === ov) kapat(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !ov.hidden) kapat(); });
    Array.prototype.forEach.call(document.querySelectorAll('input[name="bszYon"]'), function (r) { r.addEventListener('change', yonUygula); });
    ['bszFirma', 'bszUlke', 'bszKisi', 'bszUrun'].forEach(function (i) { el(i).addEventListener('change', kontrol); });
    el('bszYeniAc').addEventListener('click', function () { yeniUygula(true); el('bszYeniAd').focus(); });
    el('bszYeniIptal').addEventListener('click', function () { el('bszYeniAd').value = ''; yeniUygula(false); });
    el('bszYeniAd').addEventListener('input', kontrol);
    el('bszKg').addEventListener('input', function () { kgBicimle(); kontrol(); });
    el('bszPlaka').addEventListener('input', kontrol);
    el('bszPlaka').addEventListener('change', plakaKontrol);
    el('bszKaydet').addEventListener('click', function () { gonder(plakaOnayli); });
    el('bszYineDe').addEventListener('click', function () { plakaOnayli = true; el('bszYineDe').hidden = true; gonder(true); });
})();
</script>
