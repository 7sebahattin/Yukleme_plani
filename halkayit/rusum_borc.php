<?php
// =============================================================================
// HAL KAYIT — RÜSUM BORCU (v566)
// HKS web servislerinde borç sorgusu YOK (bkz. rusum_borc_lib.php başlığı). Bu sayfa
// bildirimlerdeki rüsum tutarlarını gün gün toplar, son ödeme tarihini hesaplar ve
// "ödendi" durumunu kullanıcının elle işaretlemesiyle tutar. RESMÎ BORÇ DEĞİLDİR.
// Sorgu SALT OKUNUR (HKS'e hiçbir şey göndermez). Tek yazma: ödendi işareti (POST,
// CSRF, hks.edit, audit) → hks_kv; şema/migration YOK.
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';        // ../config/db.php → helpers.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/taslak_lib.php';    // hks_firma_bul
require_once __DIR__ . '/rusum_borc_lib.php';

$auth_user = require_login();
require_perm('hks.access');   // Hal Kayıt paneliyle (index.php) aynı kapı
if (!role_hks_can('read')) forbidden('Hal Bildirimi için görüntüleme yetkiniz yok.');
icerik_kapi('hks.rusum_borc');

header('Cache-Control: no-store, private');

$rb_firmalar = [];
try {
    try { hks_tablolari_hazirla(); } catch (Throwable $e) { /* tablo kurulamadıysa okuma hatası aşağıda */ }
    $__st = hks_db()->query('SELECT id, ad FROM ' . hks_tablo('firmalar') . ' ORDER BY ad');
    foreach ($__st->fetchAll(PDO::FETCH_ASSOC) as $__r) $rb_firmalar[(string)$__r['id']] = (string)$__r['ad'];
} catch (Throwable $e) {
    error_log('[hks rusum_borc] firmalar ' . get_class($e));
}

// ── Ödendi işareti (POST) — render_header'dan ÖNCE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $__ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    csrf_check($_POST['csrf'] ?? null);
    $__cik = function (array $j, int $kod = 200) use ($__ajax) {
        if ($__ajax) { http_response_code($kod); header('Content-Type: application/json; charset=utf-8'); echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; }
        if ($kod >= 400) { http_response_code($kod); exit((string)($j['hata'] ?? 'Hata')); }
    };
    if (!role_hks_can('edit')) $__cik(['hata' => 'Ödendi işaretlemek için düzenleme yetkiniz yok.'], 403);
    $__fid = (string)($_POST['firma'] ?? '');
    $__grup = (string)($_POST['grup'] ?? '');
    $__odendi = ($_POST['odendi'] ?? '') === '1';
    if (!isset($rb_firmalar[$__fid]) || !hks_rusum_grup_gecerli($__grup)) $__cik(['hata' => 'Geçersiz firma ya da grup.'], 400);
    $__kul = trim((string)($auth_user['display_name'] ?? $auth_user['username'] ?? ''));
    try {
        $__yazildi = hks_rusum_odeme_yaz($__fid, $__grup, $__odendi, $__kul);
    } catch (Throwable $e) {
        error_log('[hks rusum_borc] odeme ' . get_class($e));
        $__cik(['hata' => 'İşaret kaydedilemedi.'], 500);
    }
    if (!$__yazildi) $__cik(['hata' => 'İşaret kaydedilemedi (liste dolu olabilir).'], 409);
    // Audit: VKN (grup kodunun başı) YAZILMAZ — yalnız firma id + tahakkuk günü + yeni durum.
    audit_log_event('hks_rusum_odeme', 'hks_rusum', null, null, [
        'firma_id' => $__fid, 'tahakkuk' => substr($__grup, -17, 8), 'odendi' => $__odendi,
    ]);
    $__cik(['tamam' => true, 'odendi' => $__odendi, 'odemeTarihi' => $__odendi ? date('Y-m-d') : '']);
    // Düz form gönderimi (JS kapalı): aynı sorguya geri dön.
    header('Location: rusum_borc.php?' . http_build_query([
        'firma' => $__fid, 'bas' => (string)($_POST['bas'] ?? ''), 'bit' => (string)($_POST['bit'] ?? ''),
        'durum' => (string)($_POST['durum'] ?? ''), 'sorgula' => '1',
    ]));
    exit;
}

// ── Filtre (GET) ──
$rb_bugun = date('Y-m-d');
$rb_firma = (string)($_GET['firma'] ?? '');
if (!isset($rb_firmalar[$rb_firma])) $rb_firma = '';
$rb_bas = (string)($_GET['bas'] ?? '');
$rb_bit = (string)($_GET['bit'] ?? '');
if ($rb_bit === '') $rb_bit = $rb_bugun;
if ($rb_bas === '') $rb_bas = date('Y-m-d', strtotime($rb_bugun . ' -29 days'));
$rb_durum = in_array((string)($_GET['durum'] ?? ''), ['odenmedi', 'odendi', 'hepsi'], true) ? (string)$_GET['durum'] : 'odenmedi';
$rb_sorgula = ($_GET['sorgula'] ?? '') === '1' && $rb_firma !== '';

$rb_hata = '';
$rb_uyari = '';
$rb_sonuc = null;      // ['gruplar','toplam','bildirim','rusumlu']
$rb_firma_vkn = '';
if ($rb_sorgula) {
    try {
        [$__b, $__e] = hks_gelen_aralik_dogrula($rb_bas, $rb_bit);
        $rb_bas = $__b->format('Y-m-d'); $rb_bit = $__e->format('Y-m-d');
        $__cfg = hks_firma_bul($rb_firma);
        if (!$__cfg) throw new InvalidArgumentException('Firma bulunamadı.');
        $rb_firma_vkn = (string)$__cfg['vergiNo'];
        @set_time_limit(180);
        $__c = hks_rusum_tahakkuk_cek($__cfg, $__b, $__e);
        $__g = hks_rusum_grupla($__c['satirlar'], $rb_firma_vkn);
        $__d = hks_rusum_durumlari_isle($__g['gruplar'], hks_rusum_odeme_oku($rb_firma), $rb_bugun);
        $rb_sonuc = ['gruplar' => $__d['gruplar'], 'toplam' => $__d['toplam'], 'bildirim' => $__g['bildirim'], 'rusumlu' => $__g['rusumlu']];
        if ($__c['kismiHata'] > 0) $rb_uyari = 'HKS ' . $__c['kismiHata'] . ' sorgu parçasını yanıtlamadı; liste eksik olabilir. Tekrar sorgulayın.';
    } catch (InvalidArgumentException $e) {
        $rb_hata = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[hks rusum_borc] sorgu ' . get_class($e));
        $rb_hata = 'HKS sorgusu yapılamadı: ' . $e->getMessage();
    }
}
$rb_firma_ad = $rb_firmalar[$rb_firma] ?? '';
$rb_duzenle = role_hks_can('edit');
$rb_durum_etiket = ['odendi' => 'Ödendi', 'odenmedi' => 'Ödenmedi', 'gecikmis' => 'Vadesi geçti'];

render_header('Rüsum Borcu');
?>
<style>
@page { size: A4 portrait; margin: 10mm; }
.rb-sayfa { max-width: 1100px; }
.rb-bas { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; margin: 4px 0 12px; }
.rb-bas h1 { margin: 0; font-size: 22px; flex: 1 1 auto; min-width: 0; }
.rb-not { background: var(--warn-soft); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 9px 11px; margin-bottom: 12px; font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; }
.rb-hata { background: var(--danger-soft); }
.rb-filtre { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; margin-bottom: 14px; }
.rb-izgara { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px 12px; }
.rb-filtre label { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; font-weight: 600; color: var(--muted); min-width: 0; }
.rb-filtre select, .rb-filtre input { width: 100%; min-width: 0; box-sizing: border-box; }
.rb-alt { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center; }
.rb-kartlar { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.rb-kart { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; box-shadow: var(--shadow); min-width: 0; }
.rb-kart-e { font-size: 12px; color: var(--muted); font-weight: 600; }
.rb-kart-d { font-size: 20px; font-weight: 800; margin-top: 2px; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
.rb-kart-alt { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.rb-kart-gec .rb-kart-d { color: var(--danger); }
.rb-seg { display: inline-flex; border: 1px solid var(--border); border-radius: 999px; overflow: hidden; margin-bottom: 10px; max-width: 100%; }
.rb-seg button { border: 0; background: var(--card); color: var(--text); padding: 7px 14px; font-size: 13px; font-weight: 600; cursor: pointer; min-height: 36px; }
.rb-seg button + button { border-left: 1px solid var(--border); }
.rb-seg button[aria-pressed="true"] { background: var(--primary); color: #fff; }
.rb-tablo { width: 100%; border-collapse: collapse; font-size: 14px; }
.rb-tablo th, .rb-tablo td { padding: 8px 9px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
.rb-tablo th { background: var(--surface-2); font-size: 12.5px; color: var(--muted); white-space: nowrap; }
.rb-tablo .rb-s { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 700; }
.rb-tablo .rb-nw { white-space: nowrap; }
.rb-grup { display: block; font-size: 11px; color: var(--muted); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.rb-tablo tr.rb-gizli { display: none; }
.rb-tablo tr[data-durum="gecikmis"] td:first-child { box-shadow: inset 3px 0 0 var(--danger); }
.rb-tablo tr[data-durum="odendi"] { color: var(--muted); }
.rb-rozet { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--surface-2); border: 1px solid var(--border); white-space: nowrap; }
.rb-rozet[data-r="gecikmis"] { background: var(--danger-soft); color: var(--danger); }
.rb-rozet[data-r="odendi"] { background: var(--success-soft, var(--primary-soft)); color: var(--success, var(--text)); }
.rb-isaret { margin: 0; }
.rb-isaret button { min-height: 36px; padding: 4px 10px; font-size: 13px; }
.rb-bos { color: var(--muted); padding: 14px 0; }
.rb-toplam td { font-weight: 800; background: var(--primary-soft); border-top: 2px solid var(--border-strong); }
@media (max-width: 767px) {
    .rb-kartlar { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .rb-kart-d { font-size: 17px; }
    .rb-izgara { grid-template-columns: 1fr 1fr; }
    .rb-izgara .rb-genis { grid-column: 1 / -1; }
    .rb-bas h1 { font-size: 19px; }
    /* Satırlar kart olur: 5 sütunlu tablo 390px'e sığmaz (Rüsum/Durum/düğme dışarıda kalıyordu). */
    .rb-tablo thead { display: none; }
    .rb-tablo, .rb-tablo tbody { display: block; }
    .rb-tablo tr { display: grid; grid-template-columns: 1fr 1fr; grid-template-areas: "tah tah" "vade tutar" "durum isl";
        gap: 4px 10px; padding: 10px 12px; border-bottom: 1px solid var(--border); align-items: center; }
    .rb-tablo tr.rb-gizli { display: none; }
    .rb-tablo td { display: block; padding: 0; border: 0; min-width: 0; }
    .rb-tablo td.rb-c-tah { grid-area: tah; font-weight: 700; font-size: 15px; }
    .rb-tablo td.rb-c-vade { grid-area: vade; }
    .rb-tablo td.rb-c-tutar { grid-area: tutar; text-align: right; font-size: 16px; }
    .rb-tablo td.rb-c-durum { grid-area: durum; }
    .rb-tablo td.rb-c-isl { grid-area: isl; text-align: right; }
    .rb-tablo td[data-etiket]::before { content: attr(data-etiket); display: block; font-size: 11px; font-weight: 600; color: var(--muted); }
    .rb-tablo td.rb-c-adet { display: none; }
    .rb-tablo tr[data-durum="gecikmis"] { box-shadow: inset 3px 0 0 var(--danger); }
    .rb-tablo tr[data-durum="gecikmis"] td:first-child { box-shadow: none; }
}
@media (max-width: 389px) { .rb-izgara { grid-template-columns: 1fr; } }
@media print {
    .desktop-sidebar, .topbar, .topnav, .bottomnav, .rb-filtre, .rb-ekran, .rb-seg, .rb-isaret, .rb-col-isl { display: none !important; }
    .container { margin: 0 !important; padding: 0 !important; max-width: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .rb-kart { box-shadow: none; }
    .rb-kart, .rb-tablo th, .rb-tablo td { border-color: #999 !important; color: #000 !important; background: #fff !important; }
    .rb-tablo { font-size: 10pt; }
    .rb-tablo tr.rb-gizli { display: none !important; }
}
</style>

<div class="rb-sayfa">
<div class="rb-bas">
    <a class="btn btn-geri rb-ekran" href="index.php">← Hal Kayıt</a>
    <h1>💳 Rüsum Borcu</h1>
    <?php if ($rb_sonuc !== null): ?><button type="button" class="btn rb-ekran" data-rb-yazdir>🖨 Yazdır</button><?php endif; ?>
</div>

<div class="rb-not">
    <b>Resmî borç sorgusu değildir.</b> HKS web servisinde borç/ödeme sorgusu yok; bu sayfa bildirimlerdeki
    <b>rüsum tutarlarını</b> bildirim gününe göre toplar, son ödeme tarihini <b>tahakkuktan 5 iş günü sonra</b> olarak hesaplar.
    <b>Ödendi</b> bilgisi servisten gelmez, ödemeyi yapınca siz işaretlersiniz. Kesin borç ve ödeme durumu için
    <a href="https://hks.hal.gov.tr" target="_blank" rel="noopener noreferrer">HKS portalındaki Borç Sorgulama</a>'ya bakın;
    tutarlar farklıysa portal doğrudur.
</div>

<form class="rb-filtre rb-ekran" method="get" action="rusum_borc.php">
    <input type="hidden" name="sorgula" value="1">
    <div class="rb-izgara">
        <label class="rb-genis">Firma
            <select name="firma" required>
                <option value="">Firma seçin…</option>
                <?php foreach ($rb_firmalar as $__id => $__ad): ?>
                <option value="<?= h($__id) ?>"<?= (string)$__id === $rb_firma ? ' selected' : '' ?>><?= h($__ad) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Başlangıç <input type="date" name="bas" value="<?= h($rb_bas) ?>" max="<?= h($rb_bugun) ?>"></label>
        <label>Bitiş <input type="date" name="bit" value="<?= h($rb_bit) ?>" max="<?= h($rb_bugun) ?>"></label>
    </div>
    <input type="hidden" name="durum" value="<?= h($rb_durum) ?>">
    <div class="rb-alt">
        <button type="submit" class="btn btn-primary">Sorgula</button>
        <span class="rb-kart-alt">En çok <?= (int)HKS_GELEN_MAX_GUN ?> gün; geniş aralıkta sorgu bir dakika sürebilir.</span>
    </div>
</form>

<?php if ($rb_hata !== ''): ?><div class="rb-not rb-hata"><?= h($rb_hata) ?></div><?php endif; ?>
<?php if ($rb_uyari !== ''): ?><div class="rb-not"><?= h($rb_uyari) ?></div><?php endif; ?>

<?php if ($rb_sonuc === null && $rb_hata === ''): ?>
<p class="rb-bos">Firma ve tarih aralığı seçip <b>Sorgula</b>'ya basın. Hal Kayıt ekranından gelindiğinde firma hazır seçili gelir.</p>
<?php elseif ($rb_sonuc !== null): $rb_t = $rb_sonuc['toplam']; ?>
<p class="rb-kart-alt" style="margin:0 0 10px"><?= h($rb_firma_ad) ?> · <?= h(hks_rusum_tarih($rb_bas)) ?> – <?= h(hks_rusum_tarih($rb_bit)) ?> ·
    <?= (int)$rb_sonuc['bildirim'] ?> bildirim, <?= (int)$rb_sonuc['rusumlu'] ?> tanesinde rüsum var</p>

<div class="rb-kartlar" id="rbKartlar">
    <div class="rb-kart"><div class="rb-kart-e">Toplam rüsum (TL)</div><div class="rb-kart-d" data-rb-top="tutar"><?= h(hks_rusum_tl($rb_t['tutar'])) ?></div><div class="rb-kart-alt"><?= (int)$rb_t['adet'] ?> günlük tahakkuk</div></div>
    <div class="rb-kart"><div class="rb-kart-e">Ödenmemiş (TL)</div><div class="rb-kart-d" data-rb-top="odenmedi"><?= h(hks_rusum_tl($rb_t['odenmedi'])) ?></div></div>
    <div class="rb-kart rb-kart-gec"><div class="rb-kart-e">Vadesi geçmiş (TL)</div><div class="rb-kart-d" data-rb-top="gecikmis"><?= h(hks_rusum_tl($rb_t['gecikmis'])) ?></div></div>
    <div class="rb-kart"><div class="rb-kart-e">Ödenmiş (TL)</div><div class="rb-kart-d" data-rb-top="odendi"><?= h(hks_rusum_tl($rb_t['odendi'])) ?></div></div>
</div>

<?php if (!$rb_sonuc['gruplar']): ?>
    <p class="rb-bos">
        <?php if ($rb_sonuc['bildirim'] === 0): ?>Bu aralıkta HKS'te yaptığınız bildirim bulunamadı.
        <?php else: ?>Bu aralıkta <?= (int)$rb_sonuc['bildirim'] ?> bildirim var ama hiçbirinde rüsum tutarı yok.<?php endif; ?>
    </p>
<?php else: ?>
<div class="rb-seg rb-ekran" role="group" aria-label="Ödeme durumu">
    <button type="button" data-rb-filtre="odenmedi" aria-pressed="<?= $rb_durum === 'odenmedi' ? 'true' : 'false' ?>">Ödenmemiş</button>
    <button type="button" data-rb-filtre="odendi" aria-pressed="<?= $rb_durum === 'odendi' ? 'true' : 'false' ?>">Ödenmiş</button>
    <button type="button" data-rb-filtre="hepsi" aria-pressed="<?= $rb_durum === 'hepsi' ? 'true' : 'false' ?>">Hepsi</button>
</div>
<div class="table-wrap">
<table class="rb-tablo" id="rbTablo" data-filtre="<?= h($rb_durum) ?>" data-firma="<?= h($rb_firma) ?>">
    <thead><tr>
        <th>Tahakkuk</th><th>Son ödeme</th><th class="rb-s">Rüsum (TL)</th><th class="rb-col-adet rb-s">Bildirim</th><th>Durum</th>
        <?php if ($rb_duzenle): ?><th class="rb-col-isl"></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rb_sonuc['gruplar'] as $__g): ?>
    <tr data-durum="<?= h($__g['durum']) ?>" data-tutar="<?= h((string)$__g['tutar']) ?>" data-grup="<?= h($__g['grup']) ?>">
        <td class="rb-nw rb-c-tah"><?= h(hks_rusum_tarih($__g['tahakkuk'])) ?><span class="rb-grup"><?= h($__g['grup']) ?></span></td>
        <td class="rb-nw rb-c-vade" data-etiket="Son ödeme"><?= h(hks_rusum_tarih($__g['sonOdeme'])) ?></td>
        <td class="rb-s rb-c-tutar" data-etiket="Rüsum (TL)"><?= h(hks_rusum_tl($__g['tutar'])) ?></td>
        <td class="rb-col-adet rb-s rb-c-adet" data-etiket="Bildirim"><?= (int)$__g['adet'] ?></td>
        <td class="rb-c-durum"><span class="rb-rozet" data-r="<?= h($__g['durum']) ?>" data-rb-rozet><?= h($rb_durum_etiket[$__g['durum']]) ?></span>
            <?php if ($__g['odemeTarihi'] !== ''): ?><span class="rb-grup" data-rb-odeme>işaret: <?= h(hks_rusum_tarih($__g['odemeTarihi'])) ?></span><?php else: ?><span class="rb-grup" data-rb-odeme></span><?php endif; ?></td>
        <?php if ($rb_duzenle): ?>
        <td class="rb-col-isl rb-c-isl">
            <form class="rb-isaret" method="post" action="rusum_borc.php" data-rb-form>
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="firma" value="<?= h($rb_firma) ?>">
                <input type="hidden" name="grup" value="<?= h($__g['grup']) ?>">
                <input type="hidden" name="bas" value="<?= h($rb_bas) ?>">
                <input type="hidden" name="bit" value="<?= h($rb_bit) ?>">
                <input type="hidden" name="durum" value="<?= h($rb_durum) ?>">
                <input type="hidden" name="odendi" value="<?= $__g['durum'] === 'odendi' ? '0' : '1' ?>" data-rb-yeni>
                <button type="submit" class="btn" data-rb-dugme><?= $__g['durum'] === 'odendi' ? 'Geri al' : 'Ödendi işaretle' ?></button>
            </form>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="rb-bos rb-ekran" data-rb-bos hidden>Bu görünümde satır yok.</p>
<?php endif; ?>
<?php endif; ?>
</div>

<script>
(function () {
  var tablo = document.getElementById('rbTablo');
  var yz = document.querySelector('[data-rb-yazdir]');
  if (yz) yz.addEventListener('click', function () { window.print(); });
  if (!tablo) return;
  var ETIKET = { odendi: 'Ödendi', odenmedi: 'Ödenmedi', gecikmis: 'Vadesi geçti' };
  var satirlar = Array.prototype.slice.call(tablo.querySelectorAll('tbody tr'));
  var bos = document.querySelector('[data-rb-bos]');
  function tl(v) { return v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function uygula() {
    var f = tablo.getAttribute('data-filtre'), top = { tutar: 0, odendi: 0, odenmedi: 0, gecikmis: 0 }, gorunen = 0;
    satirlar.forEach(function (tr) {
      var d = tr.getAttribute('data-durum'), t = parseFloat(tr.getAttribute('data-tutar')) || 0;
      top.tutar += t;
      if (d === 'odendi') top.odendi += t; else { top.odenmedi += t; if (d === 'gecikmis') top.gecikmis += t; }
      var goster = f === 'hepsi' || (f === 'odendi' ? d === 'odendi' : d !== 'odendi');
      tr.classList.toggle('rb-gizli', !goster);
      if (goster) gorunen++;
    });
    Object.keys(top).forEach(function (k) {
      var el = document.querySelector('[data-rb-top="' + k + '"]');
      if (el) el.textContent = tl(Math.round(top[k] * 100) / 100);
    });
    if (bos) bos.hidden = gorunen > 0;
    document.querySelectorAll('[data-rb-filtre]').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-rb-filtre') === f ? 'true' : 'false');
    });
    // Düz form gönderiminde dönülecek filtre
    tablo.querySelectorAll('input[name="durum"]').forEach(function (i) { i.value = f; });
  }
  document.querySelectorAll('[data-rb-filtre]').forEach(function (b) {
    b.addEventListener('click', function () { tablo.setAttribute('data-filtre', b.getAttribute('data-rb-filtre')); uygula(); });
  });
  var bugun = new Date().toISOString().slice(0, 10);
  tablo.addEventListener('submit', function (ev) {
    var form = ev.target.closest('[data-rb-form]');
    if (!form || !window.fetch) return;   // fetch yoksa düz gönderim
    ev.preventDefault();
    var tr = form.closest('tr'), dugme = form.querySelector('[data-rb-dugme]'), yeni = form.querySelector('[data-rb-yeni]');
    dugme.disabled = true;
    fetch('rusum_borc.php', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (s) {
        if (!s.ok || !s.j.tamam) throw new Error((s.j && s.j.hata) || 'Kaydedilemedi');
        var odendi = !!s.j.odendi, vade = tr.querySelectorAll('td')[1].textContent.trim().split('.').reverse().join('-');
        var durum = odendi ? 'odendi' : (vade && vade < bugun ? 'gecikmis' : 'odenmedi');
        tr.setAttribute('data-durum', durum);
        var r = tr.querySelector('[data-rb-rozet]'); r.setAttribute('data-r', durum); r.textContent = ETIKET[durum];
        var o = tr.querySelector('[data-rb-odeme]'); if (o) o.textContent = odendi ? 'işaret: ' + s.j.odemeTarihi.split('-').reverse().join('.') : '';
        yeni.value = odendi ? '0' : '1'; dugme.textContent = odendi ? 'Geri al' : 'Ödendi işaretle';
        uygula();
      })
      .catch(function (e) { alert(e.message || 'Kaydedilemedi'); })
      .then(function () { dugme.disabled = false; });
  });
  uygula();
})();
</script>
<?php
render_footer();
