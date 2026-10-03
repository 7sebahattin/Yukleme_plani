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
<?php
// v297-B: satır içi SVG simgeleri (stroke currentColor, harici varlık YOK). Fonksiyon TANIMLAMAZ — kapanış
// (sayfa partial'ı iki kez include edebilir). Ayrı değişken: _puantaj_ekle.php'nin $ekleIk'ine bağımlı değil.
$topluIk = function (string $ad): string {
    static $y = [
        'takvim-arti' => '<rect x="3" y="4.5" width="18" height="16" rx="3"/><path d="M8 2.5v4M16 2.5v4M3 9.5h18M12 12.5v5M9.5 15h5"/>',
        'takvim'      => '<rect x="3" y="4.5" width="18" height="16" rx="3"/><path d="M8 2.5v4M16 2.5v4M3 9.5h18"/>',
        'saat'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
        'kart'        => '<rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19M6.5 15h4"/>',
        'kisiler'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 5.2a3.2 3.2 0 0 1 0 5.6M18.5 14.4c1.6.9 2.5 2.7 2.5 5.6"/>',
        'belge'       => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'balon'       => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4h0A2.5 2.5 0 0 1 4 13.5z"/>',
        'bilgi'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6v.2"/>',
        'kapat'       => '<path d="M6 6l12 12M18 6L6 18"/>',
        'kadin'       => '<circle cx="12" cy="5.5" r="3"/><path d="M12 9c-2.4 0-4 1.6-4.4 4l-1.1 5.5h3.1V22h4.8v-3.5h3.1L16.4 13C16 10.6 14.4 9 12 9z"/>',
        'erkek'       => '<circle cx="12" cy="5.5" r="3"/><path d="M7 21v-8c0-2.2 1.3-4 3.3-4h3.4c2 0 3.3 1.8 3.3 4v8M12 14v7"/>',
        'rampaci'     => '<path d="M5 9.5a7 7 0 0 1 14 0M3.5 9.5h17M12 2.5v4"/><circle cx="12" cy="14.2" r="2.6"/><path d="M6.5 21.5c0-3 2.4-5 5.5-5s5.5 2 5.5 5"/>',
        'ara'         => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        'kaydet'      => '<path d="M5 3h11l4 4v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M8 3v5h7V3M8 21v-7h8v7"/>',
        'cop'         => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    ];
    return '<svg class="tv-ik" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($y[$ad] ?? '') . '</svg>';
};
?>
<dialog id="toplu" class="pm-dialog isk-card-modal isk-toplu toplu-v2" aria-labelledby="topluBaslik"
        data-csrf="<?= h(csrf_token()) ?>" data-work-date="<?= h($topluWorkDate) ?>" data-bugun="<?= $topluBugun ? '1' : '0' ?>"
        data-limit="<?= (int)$topluLimit ?>" data-url-onizle="<?= h($topluUrlOnizle) ?>" data-url-ekle="<?= h($topluUrlEkle) ?>"
        data-cavus-sabit="<?= $topluSabitCavus ? (int)$topluSabitCavus['id'] : 0 ?>"
        data-istek-id="<?= h(bin2hex(random_bytes(16))) ?>">
<div class="pm-header tv-head">
    <span class="tv-rozet"><?= $topluIk('takvim-arti') ?></span>
    <h2 class="pm-title" id="topluBaslik">Toplu İşlem</h2>
    <button type="button" class="pm-close tv-kapat" aria-label="Kapat" onclick="this.closest('dialog').close()"><?= $topluIk('kapat') ?></button>
</div>
<form class="isk-card-modal-body tv-form" onsubmit="return false" autocomplete="off">
    <div class="tv-bilgi">
        <?= $topluIk('bilgi') ?>
        <p>
            Mesai günü: <strong><?= h($topluTrTarih) ?></strong>. Kadın ve erkek için tek seferde kart seçerek ve/veya <strong>kartsız</strong> kişi sayısı girerek
            kayıt ekleyin. Önce <strong>Önizle</strong>; hata yoksa <strong>Kaydet</strong> açılır. Tek satır bile hatalıysa hiçbir kayıt yazılmaz.
            <?php if ($topluBugun): ?>Bugün için çıkış boş bırakılırsa kartlı kişiler içeride yazılır (kartsızda çıkış zorunludur).<?php endif; ?>
        </p>
    </div>
    <div class="pdks-form-grid tv-ust">
        <?php if ($topluSabitCavus): ?>
        <div class="span-2 tv-cavus"><span class="tv-avatar"><?= $topluIk('kisiler') ?></span><div><span class="tv-cavus-et">Çavuş</span><strong><?= h($topluSabitCavus['name']) ?></strong></div></div>
        <?php else: ?>
        <label class="span-2 tv-cavus"><span class="tv-avatar"><?= $topluIk('kisiler') ?></span>
            <span class="tv-cavus-kap"><span class="tv-cavus-et">Çavuş <span class="tv-gerekli">*</span></span>
            <select id="tpCavus">
                <option value="">— Çavuş seçin —</option>
                <?php foreach ($topluCavuslar as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?= empty($c['is_active']) ? ' (pasif)' : '' ?></option>
                <?php endforeach; ?>
            </select></span>
        </label>
        <?php endif; ?>
        <label><span class="form-label"><?= $topluIk('belge') ?>Ekleme nedeni <span class="tv-gerekli">*</span></span><textarea id="tpReason" maxlength="500" rows="2" placeholder="Örn. Kart okutma cihazı arızalıydı"></textarea></label>
        <label><span class="form-label"><?= $topluIk('balon') ?>Açıklama</span><textarea id="tpNote" maxlength="1000" rows="2" placeholder="Varsa eklemek istediğiniz açıklama..."></textarea></label>
    </div>

    <?php foreach ($topluTipler as $t):
        $tid = (int)$t['id'];
        $esles = []; $digerleri = [];
        foreach ($topluKartlar as $k) { if ((int)($k['worker_type_id'] ?? 0) === $tid) $esles[] = $k; else $digerleri[] = $k; }
        $sirali = array_merge($esles, $digerleri);
        // Renk/simge işçi tipinden — tip kayıt defteri (pdks_gunluk_tip_kayit; ad/kod TR-duyarsız); tanınmazsa nötr.
        $tKod = pdks_gunluk_tip_kod_adindan((string)$t['name']) ?? pdks_gunluk_tip_kod_adindan((string)($t['code'] ?? ''));
        $tRenk = $tKod !== null ? pdks_gunluk_tip_renk($tKod) : 'diger';
        $tBaslik = $tKod !== null ? (pdks_gunluk_tip_kayit_ad($tKod) ?? (string)$t['name']) : (string)$t['name'];
    ?>
    <section class="tp-grup tv-grup tv-grup-<?= $tRenk ?>" data-tip="<?= $tid ?>" data-ad="<?= h($t['name']) ?>">
        <h3 class="tp-grup-baslik tv-grup-bas"><span class="tv-grup-ikon"><?= $topluIk(in_array($tRenk, ['erkek', 'rampaci'], true) ? $tRenk : 'kadin') ?></span><span class="tv-grup-ad"><?= h($tBaslik) ?></span> <span class="tp-sayac">0 kartlı + 0 kartsız = 0 kişi</span></h3>
        <div class="tv-grup-govde">
        <div class="pdks-form-grid tv-alanlar">
            <div><span class="form-label">Giriş günü</span><div class="tv-ro"><?= $topluIk('takvim') ?><span><?= h($topluTrTarih) ?></span></div></div>
            <label><span class="form-label">Çıkış günü</span><span class="tv-in"><?= $topluIk('takvim') ?><input type="date" value="<?= h($topluWorkDate) ?>" data-f="exit_date"></span></label>
            <label><span class="form-label">Giriş saati</span><span class="tv-in"><?= $topluIk('saat') ?><input type="time" data-f="entry_clock"></span></label>
            <label><span class="form-label">Çıkış saati</span><span class="tv-in"><?= $topluIk('saat') ?><input type="time" data-f="exit_clock"></span></label>
            <label class="span-2"><span class="form-label">Kartsız kişi sayısı</span><span class="tv-in"><?= $topluIk('kisiler') ?><input type="number" min="0" max="<?= (int)$topluLimit ?>" step="1" inputmode="numeric" value="0" data-f="kartsiz_adet"></span></label>
        </div>
        <label class="tv-toggle"><input type="checkbox" data-tp="kartli"><span class="tv-toggle-kutu"><?= $topluIk('kart') ?><span>Kartlı giriş ekle</span></span></label>
        <div class="tp-kartli" hidden>
            <div class="tp-kart-arac">
                <label class="tp-ilkn">İlk <input type="number" min="1" max="<?= (int)$topluLimit ?>" value="10" inputmode="numeric" data-f="ilkn"> boş kartı seç</label>
                <button type="button" class="btn btn-sm tv-sec" data-tp="sec">Seç</button>
                <button type="button" class="btn btn-sm btn-ghost tv-temizle" data-tp="temizle"><?= $topluIk('cop') ?>Temizle</button>
            </div>
            <?php if ($sirali): ?>
            <div class="tp-kartlar" role="group" aria-label="<?= h($t['name']) ?> için boş kartlar">
                <?php foreach ($sirali as $k): ?>
                <label class="tp-kart<?= (int)($k['worker_type_id'] ?? 0) === $tid ? ' tp-kart-esles' : '' ?>"><input type="checkbox" value="<?= (int)$k['id'] ?>"><?= $topluIk('kart') ?><span><?= h($k['card_no']) ?></span></label>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="muted tp-bos">Bu gün için boş kart yok; yalnız kartsız kişi ekleyebilirsiniz.</p>
            <?php endif; ?>
        </div>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="tp-toplam" aria-live="polite"><strong>Toplam:</strong> <span id="tpToplam">0</span> kişi <span class="tp-asim" id="tpAsim" hidden>— en fazla <?= (int)$topluLimit ?> kayıt eklenebilir</span></div>
    <div class="tp-mesaj" id="tpMesaj" role="alert" hidden></div>
    <div class="tp-onizle" id="tpOnizle" hidden></div>

    <div class="isk-card-form-actions tp-aksiyon tv-foot">
        <button type="button" class="btn btn-primary tv-btn" id="tpOnizleBtn"><?= $topluIk('ara') ?><span class="tv-bt">Önizle</span></button>
        <button type="button" class="btn tv-btn tv-kaydet" id="tpKaydetBtn" disabled><?= $topluIk('kaydet') ?><span class="tv-bt">Kaydet</span></button>
        <button type="button" class="btn btn-ghost tv-btn tv-vazgec" onclick="this.closest('dialog').close()"><?= $topluIk('kapat') ?><span class="tv-bt">Vazgeç</span></button>
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

    function btnYaz(b, m) { var sp = b.querySelector('.tv-bt'); (sp || b).textContent = m; }
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
        // istek_id: sayfa/pencere çizilirken sunucuda üretilir; Kaydet tekrarlanırsa AYNI değer gider (tekrar gönderim koruması).
        return { csrf: dlg.getAttribute('data-csrf'), istek_id: dlg.getAttribute('data-istek-id'), foreman_id: cavusSel ? (parseInt(cavusSel.value, 10) || 0) : sabitCavus,
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
        // Engellemeyen uyarılar (ör. aynı saatlerde kartsız kayıt zaten var) — sarı kutu, Kaydet'i kapatmaz.
        if ((y.uyarilar || []).length) {
            var uk = document.createElement('div'); uk.className = 'tp-uyari'; uk.setAttribute('role', 'status');
            var uu = document.createElement('ul');
            y.uyarilar.forEach(function (s) { var li = document.createElement('li'); li.textContent = s; uu.appendChild(li); });
            uk.appendChild(uu); elOn.appendChild(uk);
        }
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
        btnOn.disabled = true; btnYaz(btnOn, 'Önizleniyor…');
        istek(dlg.getAttribute('data-url-onizle'), v).then(function (y) {
            btnYaz(btnOn, 'Önizle'); sayaclar();
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
        kaydediyor = true; btnKay.disabled = true; btnOn.disabled = true; btnYaz(btnKay, 'Kaydediliyor…');
        istek(dlg.getAttribute('data-url-ekle'), v).then(function (y) {
            if (y && y.ok === true && y.redirect) { location.href = y.redirect; return; }
            kaydediyor = false; btnYaz(btnKay, 'Kaydet'); gecersiz(); sayaclar();
            if (y && y.error) mesaj([y.error]); else { sonYanit = y || {}; if ((y.satirlar || []).length || (y.hatalar || []).length) onizleCiz(y); else mesaj(['Kayıt yapılamadı.']); }
        });
    });

    form.addEventListener('input', function (e) { if (e.target.getAttribute('data-f') === 'ilkn') return; kartKilitleri(); sayaclar(); gecersiz(); });
    form.addEventListener('change', function (e) { if (e.target.getAttribute('data-f') === 'ilkn') return; kartKilitleri(); sayaclar(); gecersiz(); });
    gruplar.forEach(function (g) {
        // v297: kart listesi varsayılan GİZLİ. Kapatınca bu grubun seçili kartları temizlenir
        // (change olayı form dinleyicisine de ulaşır: kilitler/sayaç/önizleme geçersizliği güncellenir).
        var anahtar = g.querySelector('[data-tp="kartli"]'), kartli = g.querySelector('.tp-kartli');
        anahtar.addEventListener('change', function () {
            kartli.hidden = !anahtar.checked;
            g.classList.toggle('tv-kartli-acik', anahtar.checked);
            if (!anahtar.checked) kutular(g).forEach(function (c) { c.checked = false; });
        });
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
