<?php
// =========================================================
// _puantaj_toplu.php — "Toplu İşlem" penceresi (v294, YALNIZ yönetici)
//
// gunluk_isci_puantaj_detay.php (sabit çavuş) ve gunluk_isci_puantaj.php (çavuş
// seçilir) include eder. Partial fonksiyon TANIMLAMAZ, DB'ye dokunmaz. Yazma işi
// pdks_faz8j_toplu_ekle()'dedir (kurallar orada; UI yalnız sunar): önce ÖNİZLEME
// (?ajax=toplu_onizle, yan etkisiz), temiz önizleme olmadan Kaydet açılmaz,
// Kaydet ?ajax=toplu_ekle (hep-ya-hiç). Uçlar _puantaj_toplu_ajax.php'dedir.
//
// Beklenen değişkenler:
//   $topluWorkDate   — 'Y-m-d' mesai günü
//   $topluKartlar    — [['id'=>,'card_no'=>,'worker_type_id'=>], …] o gün BOŞ kartlar
//   $topluTipler     — [['id'=>,'name'=>], …] KADIN/ERKEK (tek politika)
//   $topluSabitCavus — ['id'=>,'name'=>] (detay) YA DA null
//   $topluCavuslar   — [['id'=>,'name'=>,'is_active'=>], …] ($topluSabitCavus null iken)
//   $topluUrlOnizle / $topluUrlEkle — ajax uç adresleri
// =========================================================
if (!isset($topluWorkDate, $topluKartlar, $topluTipler, $topluUrlOnizle, $topluUrlEkle)) { http_response_code(404); exit; }
$topluSabitCavus = $topluSabitCavus ?? null;
$topluCavuslar   = $topluCavuslar ?? [];
$topluTrTarih    = date('d.m.Y', strtotime($topluWorkDate));
$topluBugun      = ($topluWorkDate === date('Y-m-d'));
$topluLimit      = defined('PDKS_FAZ8J_TOPLU_LIMIT') ? (int)PDKS_FAZ8J_TOPLU_LIMIT : 250;
?>
<dialog id="toplu" class="pm-dialog isk-card-modal isk-toplu"
        data-csrf="<?= h(csrf_token()) ?>" data-work-date="<?= h($topluWorkDate) ?>" data-bugun="<?= $topluBugun ? '1' : '0' ?>"
        data-limit="<?= (int)$topluLimit ?>" data-url-onizle="<?= h($topluUrlOnizle) ?>" data-url-ekle="<?= h($topluUrlEkle) ?>"
        data-cavus-sabit="<?= $topluSabitCavus ? (int)$topluSabitCavus['id'] : 0 ?>">
<div class="pm-header"><h2 class="pm-title">Toplu İşlem</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div>
<form class="isk-card-modal-body" onsubmit="return false" autocomplete="off">
    <p class="muted" style="margin:0 0 12px;font-size:.88rem">
        Mesai günü: <strong><?= h($topluTrTarih) ?></strong>. Kadın ve erkek için tek seferde kart seçerek ve/veya <strong>kartsız</strong> kişi sayısı girerek
        kayıt ekleyin. Önce <strong>Önizle</strong>; hata yoksa <strong>Kaydet</strong> açılır. Tek satır bile hatalıysa hiçbir kayıt yazılmaz.
        <?php if ($topluBugun): ?>Bugün için çıkış boş bırakılırsa kartlı kişiler içeride yazılır (kartsızda çıkış zorunludur).<?php endif; ?>
    </p>
    <div class="pdks-form-grid">
        <?php if ($topluSabitCavus): ?>
        <div class="span-2"><span class="form-label">Çavuş</span><div><strong><?= h($topluSabitCavus['name']) ?></strong></div></div>
        <?php else: ?>
        <label class="span-2"><span class="form-label">Çavuş *</span>
            <select id="tpCavus">
                <option value="">— Çavuş seçin —</option>
                <?php foreach ($topluCavuslar as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?= empty($c['is_active']) ? ' (pasif)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label class="span-2"><span class="form-label">Ekleme nedeni *</span><textarea id="tpReason" maxlength="500" rows="2" placeholder="Örn. Kart okutma cihazı arızalıydı"></textarea></label>
        <label class="span-2"><span class="form-label">Açıklama</span><textarea id="tpNote" maxlength="1000" rows="2"></textarea></label>
    </div>

    <?php foreach ($topluTipler as $t):
        $tid = (int)$t['id'];
        $esles = []; $digerleri = [];
        foreach ($topluKartlar as $k) { if ((int)($k['worker_type_id'] ?? 0) === $tid) $esles[] = $k; else $digerleri[] = $k; }
        $sirali = array_merge($esles, $digerleri);
    ?>
    <section class="tp-grup" data-tip="<?= $tid ?>" data-ad="<?= h($t['name']) ?>">
        <h3 class="tp-grup-baslik"><span><?= h($t['name']) ?></span> <span class="tp-sayac">0 kartlı + 0 kartsız = 0 kişi</span></h3>
        <div class="pdks-form-grid">
            <label><span class="form-label">Giriş saati</span><input type="time" data-f="entry_clock"></label>
            <label><span class="form-label">Kartsız kişi sayısı</span><input type="number" min="0" max="<?= (int)$topluLimit ?>" step="1" inputmode="numeric" value="0" data-f="kartsiz_adet"></label>
            <label><span class="form-label">Çıkış günü</span><input type="date" value="<?= h($topluWorkDate) ?>" data-f="exit_date"></label>
            <label><span class="form-label">Çıkış saati</span><input type="time" data-f="exit_clock"></label>
        </div>
        <div class="tp-kart-arac">
            <label class="tp-ilkn">İlk <input type="number" min="1" max="<?= (int)$topluLimit ?>" value="10" inputmode="numeric" data-f="ilkn"> boş kartı seç</label>
            <button type="button" class="btn btn-sm" data-tp="sec">Seç</button>
            <button type="button" class="btn btn-sm btn-ghost" data-tp="temizle">Temizle</button>
        </div>
        <?php if ($sirali): ?>
        <div class="tp-kartlar" role="group" aria-label="<?= h($t['name']) ?> için boş kartlar">
            <?php foreach ($sirali as $k): ?>
            <label class="tp-kart<?= (int)($k['worker_type_id'] ?? 0) === $tid ? ' tp-kart-esles' : '' ?>"><input type="checkbox" value="<?= (int)$k['id'] ?>"> <span><?= h($k['card_no']) ?></span></label>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="muted tp-bos">Bu gün için boş kart yok; yalnız kartsız kişi ekleyebilirsiniz.</p>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <div class="tp-toplam" aria-live="polite"><strong>Toplam:</strong> <span id="tpToplam">0</span> kişi <span class="tp-asim" id="tpAsim" hidden>— en fazla <?= (int)$topluLimit ?> kayıt eklenebilir</span></div>
    <div class="tp-mesaj" id="tpMesaj" role="alert" hidden></div>
    <div class="tp-onizle" id="tpOnizle" hidden></div>

    <div class="isk-card-form-actions tp-aksiyon">
        <button type="button" class="btn" id="tpOnizleBtn">Önizle</button>
        <button type="button" class="btn btn-primary" id="tpKaydetBtn" disabled>Kaydet</button>
        <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Vazgeç</button>
    </div>
</form>
<script>
// v294 — Toplu İşlem penceresi. Veri sunucudadır: burada yalnız toplama, önizleme
// çağrısı ve "temiz önizleme olmadan Kaydet yok" kapısı vardır.
(function () {
    var dlg = document.getElementById('toplu');
    var form = dlg.querySelector('form');
    var limit = parseInt(dlg.getAttribute('data-limit'), 10) || 250;
    var bugun = dlg.getAttribute('data-bugun') === '1';
    var gun = dlg.getAttribute('data-work-date');
    var sabitCavus = parseInt(dlg.getAttribute('data-cavus-sabit'), 10) || 0;
    var cavusSel = document.getElementById('tpCavus');
    var gruplar = [].slice.call(dlg.querySelectorAll('.tp-grup'));
    var btnOn = document.getElementById('tpOnizleBtn'), btnKay = document.getElementById('tpKaydetBtn');
    var elMesaj = document.getElementById('tpMesaj'), elOn = document.getElementById('tpOnizle');
    var elTop = document.getElementById('tpToplam'), elAsim = document.getElementById('tpAsim');
    var onizlemeImza = null, kaydediyor = false, tumunuGoster = false, sonYanit = null;

    function alan(g, f) { return g.querySelector('[data-f="' + f + '"]'); }
    function kutular(g) { return [].slice.call(g.querySelectorAll('.tp-kartlar input[type=checkbox]')); }
    function adet(g) { var n = parseInt(alan(g, 'kartsiz_adet').value, 10); return isFinite(n) && n > 0 ? n : 0; }
    function secili(g) { return kutular(g).filter(function (c) { return c.checked; }); }
    function toplamKisi() { return gruplar.reduce(function (t, g) { return t + secili(g).length + adet(g); }, 0); }

    // Bir kart yalnız TEK grupta seçilebilir: başka grupta işaretliyse bu grupta pasif.
    function kartKilitleri() {
        var alinan = {};
        gruplar.forEach(function (g, i) { secili(g).forEach(function (c) { alinan[c.value] = i; }); });
        gruplar.forEach(function (g, i) {
            kutular(g).forEach(function (c) {
                var baska = alinan.hasOwnProperty(c.value) && alinan[c.value] !== i;
                c.disabled = baska;
                c.parentNode.classList.toggle('tp-kart-pasif', baska);
            });
        });
    }
    function sayaclar() {
        gruplar.forEach(function (g) {
            var k = secili(g).length, m = adet(g);
            g.querySelector('.tp-sayac').textContent = k + ' kartlı + ' + m + ' kartsız = ' + (k + m) + ' kişi';
        });
        var t = toplamKisi();
        elTop.textContent = t;
        elAsim.hidden = t <= limit;
        btnOn.disabled = t > limit;
    }
    function mesaj(satirlar) {
        elMesaj.textContent = '';
        if (!satirlar || !satirlar.length) { elMesaj.hidden = true; return; }
        var ul = document.createElement('ul');
        satirlar.forEach(function (s) { var li = document.createElement('li'); li.textContent = s; ul.appendChild(li); });
        elMesaj.appendChild(ul); elMesaj.hidden = false;
    }
    function veri() {
        var gr = [];
        gruplar.forEach(function (g) {
            var ids = secili(g).map(function (c) { return parseInt(c.value, 10); });
            var m = adet(g);
            if (!ids.length && !m) return;
            gr.push({ worker_type_id: parseInt(g.getAttribute('data-tip'), 10), entry_clock: alan(g, 'entry_clock').value,
                      exit_date: alan(g, 'exit_date').value, exit_clock: alan(g, 'exit_clock').value, kart_ids: ids, kartsiz_adet: m });
        });
        return { csrf: dlg.getAttribute('data-csrf'), foreman_id: cavusSel ? (parseInt(cavusSel.value, 10) || 0) : sabitCavus,
                 work_date: gun, reason: document.getElementById('tpReason').value.trim(), note: document.getElementById('tpNote').value.trim(), gruplar: gr };
    }
    function istemciHatalari(v) {
        var h = [];
        if (v.foreman_id < 1) h.push('Çavuş seçin.');
        if (!v.reason) h.push('Ekleme nedeni zorunludur.');
        var t = toplamKisi();
        if (t < 1) h.push('En az bir kart seçin ya da kartsız kişi sayısı girin.');
        if (t > limit) h.push('Bir toplu işlemde en fazla ' + limit + ' kayıt eklenebilir (seçilen: ' + t + ').');
        v.gruplar.forEach(function (g) {
            var ad = (dlg.querySelector('.tp-grup[data-tip="' + g.worker_type_id + '"]') || {getAttribute: function () { return ''; }}).getAttribute('data-ad');
            if (!g.entry_clock) h.push(ad + ': giriş saati zorunludur.');
            var cikisVar = g.exit_clock !== '' || g.exit_date !== '';
            if (cikisVar && (g.exit_clock === '' || g.exit_date === '')) h.push(ad + ': çıkış tarihi ve saati birlikte girilmelidir.');
            if (!cikisVar) {
                if (!bugun) h.push(ad + ': geçmiş günde çıkış tarihi ve saati zorunludur.');
                else if (g.kartsiz_adet > 0) h.push(ad + ': kartsız kişide çıkış zorunludur.');
            }
        });
        return h;
    }
    // Çıkış saati boşsa (yalnız bugün için geçerli) çıkış gününü de boş gönder.
    function temizVeri() {
        var v = veri();
        v.gruplar.forEach(function (g) { if (g.exit_clock === '') g.exit_date = ''; });
        return v;
    }
    function imza() { return JSON.stringify(temizVeri()); }
    function gecersiz() {
        if (!elOn.hidden) elOn.classList.add('tp-eski');
        onizlemeImza = null; btnKay.disabled = true;
    }

    function saat(s) {
        if (!s) return null;
        var d = s.slice(0, 10), h = s.slice(11, 16);
        return d === gun ? h : d.slice(8, 10) + '.' + d.slice(5, 7) + ' ' + h;
    }
    function td(tr, metin, sinif) { var c = document.createElement('td'); c.textContent = metin; if (sinif) c.className = sinif; tr.appendChild(c); return c; }
    function onizleCiz(y) {
        elOn.textContent = ''; elOn.classList.remove('tp-eski'); elOn.hidden = false;
        var satirlar = y.satirlar || [], hatalar = y.hatalar || [];
        if (hatalar.length) {
            var kutu = document.createElement('div'); kutu.className = 'tp-genel-hata';
            var ul = document.createElement('ul');
            hatalar.forEach(function (s) { var li = document.createElement('li'); li.textContent = s; ul.appendChild(li); });
            kutu.appendChild(ul); elOn.appendChild(kutu);
        }
        if (y.yeni_mesai && satirlar.length) {
            var p = document.createElement('p'); p.className = 'muted tp-yeni'; p.textContent = 'Bu çavuş için bu gün mesai yok: kayıtla birlikte yeni mesai açılacak.'; elOn.appendChild(p);
        }
        (y.ozet || []).forEach(function (o) {
            var d = document.createElement('div'); d.className = 'tp-ozet';
            d.textContent = o.tip + ': ' + o.kartli + ' kartlı + ' + o.kartsiz + ' kartsız = ' + o.toplam + ' kişi'; elOn.appendChild(d);
        });
        if (!satirlar.length) return;
        var hataSayisi = satirlar.filter(function (s) { return s.durum !== 'ok'; }).length;
        var uzun = satirlar.length > 30;
        var goster = satirlar.filter(function (s) { return !uzun || tumunuGoster || s.durum !== 'ok'; });
        if (uzun) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm tp-hepsi';
            b.textContent = tumunuGoster ? 'Yalnız hatalıları göster' : 'Tümünü göster (' + satirlar.length + ' satır)';
            b.addEventListener('click', function () { tumunuGoster = !tumunuGoster; onizleCiz(y); });
            var n = document.createElement('p'); n.className = 'muted tp-yeni';
            n.textContent = satirlar.length + ' satır, ' + hataSayisi + ' hatalı' + (tumunuGoster || hataSayisi ? '' : ' — ayrıntı için tümünü gösterin') + '.';
            elOn.appendChild(n); elOn.appendChild(b);
        }
        if (!goster.length) return;
        var w = document.createElement('div'); w.className = 'tp-tablo-kap';
        var t = document.createElement('table'); t.className = 'data-table tp-tablo';
        var hd = document.createElement('thead'), hr = document.createElement('tr');
        ['Tip', 'Kart', 'Giriş', 'Çıkış', 'Durum'].forEach(function (s) { var th = document.createElement('th'); th.textContent = s; hr.appendChild(th); });
        hd.appendChild(hr); t.appendChild(hd);
        var tb = document.createElement('tbody');
        goster.forEach(function (s) {
            var tr = document.createElement('tr'); if (s.durum !== 'ok') tr.className = 'tp-satir-hata';
            td(tr, s.tip || '—'); td(tr, s.kartsiz ? 'Kartsız' : (s.kart_no || '—'));
            td(tr, saat(s.giris) || '—'); td(tr, saat(s.cikis) || 'içeride');
            td(tr, s.durum === 'ok' ? '✓ Uygun' : (s.hata || 'Hata'), s.durum === 'ok' ? 'tp-ok' : 'tp-hata-metin');
            tb.appendChild(tr);
        });
        t.appendChild(tb); w.appendChild(t); elOn.appendChild(w);
    }
    function temizMi(y) {
        return !!y && y.ok === true && !(y.hatalar || []).length && (y.satirlar || []).length > 0
            && (y.satirlar || []).every(function (s) { return s.durum === 'ok'; });
    }
    function istek(url, v) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(v) })
            .then(function (r) { return r.json().catch(function () { return { ok: false, hatalar: ['Sunucu yanıtı okunamadı.'] }; }); })
            .catch(function () { return { ok: false, hatalar: ['Sunucuya ulaşılamadı. Bağlantınızı kontrol edin.'] }; });
    }

    btnOn.addEventListener('click', function () {
        if (kaydediyor) return;
        var v = temizVeri(), h = istemciHatalari(v);
        mesaj(h); gecersiz(); elOn.hidden = true;
        if (h.length) return;
        btnOn.disabled = true; btnOn.textContent = 'Önizleniyor…';
        istek(dlg.getAttribute('data-url-onizle'), v).then(function (y) {
            btnOn.textContent = 'Önizle'; sayaclar();
            if (y.error) { mesaj([y.error]); return; }
            sonYanit = y; onizleCiz(y);
            if (temizMi(y)) { onizlemeImza = JSON.stringify(v); btnKay.disabled = false; mesaj([]); }
            else if (!(y.satirlar || []).length && (y.hatalar || []).length) { elOn.hidden = false; }
            elOn.scrollIntoView({ block: 'nearest' });
        });
    });
    btnKay.addEventListener('click', function () {
        if (kaydediyor || btnKay.disabled) return;
        var v = temizVeri();
        if (onizlemeImza === null || JSON.stringify(v) !== onizlemeImza) { gecersiz(); mesaj(['Girdiler değişti — önce yeniden önizleyin.']); return; }
        kaydediyor = true; btnKay.disabled = true; btnOn.disabled = true; btnKay.textContent = 'Kaydediliyor…';
        istek(dlg.getAttribute('data-url-ekle'), v).then(function (y) {
            if (y && y.ok === true && y.redirect) { location.href = y.redirect; return; }
            kaydediyor = false; btnKay.textContent = 'Kaydet'; gecersiz(); sayaclar();
            if (y && y.error) mesaj([y.error]); else { sonYanit = y || {}; if ((y.satirlar || []).length || (y.hatalar || []).length) onizleCiz(y); else mesaj(['Kayıt yapılamadı.']); }
        });
    });

    form.addEventListener('input', function (e) { if (e.target.getAttribute('data-f') === 'ilkn') return; kartKilitleri(); sayaclar(); gecersiz(); });
    form.addEventListener('change', function (e) { if (e.target.getAttribute('data-f') === 'ilkn') return; kartKilitleri(); sayaclar(); gecersiz(); });
    gruplar.forEach(function (g) {
        g.querySelector('[data-tp="sec"]').addEventListener('click', function () {
            var n = parseInt(alan(g, 'ilkn').value, 10) || 0;
            kutular(g).forEach(function (c) { if (!c.disabled) c.checked = false; });
            kartKilitleri();
            kutular(g).filter(function (c) { return !c.disabled; }).slice(0, n).forEach(function (c) { c.checked = true; });
            kartKilitleri(); sayaclar(); gecersiz();
        });
        g.querySelector('[data-tp="temizle"]').addEventListener('click', function () {
            kutular(g).forEach(function (c) { c.checked = false; });
            kartKilitleri(); sayaclar(); gecersiz();
        });
    });
    kartKilitleri(); sayaclar();
})();
</script>
</dialog>
