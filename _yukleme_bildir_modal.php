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
        <div class="beyan-hks-fields bsz-form">
          <label>Parti No <span class="muted">(isteğe bağlı · HKS'e gitmez)</span>
            <input type="text" id="bszParti" class="form-control" maxlength="60" autocomplete="off">
          </label>
          <label>HKS Firma <strong style="color:var(--danger)">*</strong>
            <select id="bszFirma" class="form-control"></select>
          </label>

          <fieldset class="bsz-yon" style="grid-column:1/-1">
            <legend>Alıcı</legend>
            <label class="bsz-radyo"><input type="radio" name="bszYon" value="yurt_disi" checked> Yurt dışı (ihracat) — ülke</label>
            <label class="bsz-radyo"><input type="radio" name="bszYon" value="yurt_ici"> Yurt içi — kişi / firma</label>
          </fieldset>

          <label id="bszUlkeKap" style="grid-column:1/-1">Ülke <strong style="color:var(--danger)">*</strong>
            <select id="bszUlke" class="form-control" data-aramali="Ülke yazın veya seçin"></select>
          </label>
          <label id="bszKisiKap" style="grid-column:1/-1" hidden>Kişi / Firma <strong style="color:var(--danger)">*</strong>
            <select id="bszKisi" class="form-control" data-aramali="Kişi / firma yazın veya seçin"></select>
          </label>

          <label style="grid-column:1/-1">Ürün <strong style="color:var(--danger)">*</strong>
            <select id="bszUrun" class="form-control" data-aramali="Ürün yazın veya seçin"></select>
          </label>
          <label>Net KG <strong style="color:var(--danger)">*</strong>
            <input type="text" id="bszKg" class="form-control" inputmode="decimal" autocomplete="off" placeholder="örn. 20000">
          </label>
          <label>Araç Plakası <strong style="color:var(--danger)">*</strong>
            <input type="text" id="bszPlaka" class="form-control" data-nospace data-uppercase="tr" autocomplete="off" maxlength="30">
          </label>
        </div>

        <div id="bszPlakaBand" class="bsz-plaka-band" hidden role="status">
          <span id="bszPlakaMetin"></span>
          <button type="button" class="btn btn-sm" id="bszYineDe">Yine de bildir</button>
        </div>

        <p class="beyan-hks-warn">
          Bu işlem yalnızca <strong>TASLAK</strong> oluşturur; HKS'e bildirim <strong>GÖNDERİLMEZ</strong>.
          Birim fiyat burada girilmez — <strong>Hal Kayıt'ta gönderimde</strong> girilir.
          <span id="bszIciNot" hidden>Yurt içi alıcı için taslak <strong>eksik</strong> açılır; Hal Kayıt'ta
          Düzenle ile tamamlanır.</span>
        </p>
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
    function yon() { var r = document.querySelector('input[name="bszYon"]:checked'); return r ? r.value : 'yurt_disi'; }
    function hataGoster(msg) {
        el('bszYukleniyor').hidden = true;
        el('bszHata').textContent = msg;
        if (MIGRATE && /kurulu değil|kurulmadı/i.test(msg)) {
            var a = document.createElement('a'); a.href = MIGRATE; a.textContent = ' Kurulum sayfası'; el('bszHata').appendChild(a);
        }
        el('bszHata').hidden = false;
    }
    function kontrol() {
        var ok = el('bszFirma').value && el('bszUrun').value && el('bszPlaka').value.trim() !== '' &&
                 String(el('bszKg').value).trim() !== '' &&
                 (yon() === 'yurt_ici' ? el('bszKisi').value : el('bszUlke').value);
        el('bszKaydet').disabled = !ok || gonderiyor;
    }
    function yonUygula() {
        var ici = yon() === 'yurt_ici';
        el('bszUlkeKap').hidden = ici;
        el('bszKisiKap').hidden = !ici;
        el('bszIciNot').hidden = !ici;
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
            doldur(el('bszFirma'), j.firmalar, '— seçiniz —');
            if (j.sonFirma) el('bszFirma').value = j.sonFirma;
            else if (j.firmalar.length === 1) el('bszFirma').value = j.firmalar[0].id;
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
                ulkeId: ici ? '' : el('bszUlke').value, kisiId: ici ? el('bszKisi').value : '',
                urunId: el('bszUrun').value, kg: el('bszKg').value, plaka: el('bszPlaka').value,
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
    ['bszKg', 'bszPlaka'].forEach(function (i) { el(i).addEventListener('input', kontrol); });
    el('bszPlaka').addEventListener('change', plakaKontrol);
    el('bszKaydet').addEventListener('click', function () { gonder(plakaOnayli); });
    el('bszYineDe').addEventListener('click', function () { plakaOnayli = true; el('bszYineDe').hidden = true; gonder(true); });
})();
</script>
