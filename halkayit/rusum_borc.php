<?php
// =============================================================================
// HAL KAYIT — RÜSUM BORCU (v567 → v568)
// HKS web servislerinde borç sorgusu YOK (bkz. rusum_borc_lib.php başlığı). Bu sayfa
// bildirimlerdeki rüsum tutarlarını gün gün toplar, son ödeme tarihini hesaplar ve
// "ödendi" durumunu kullanıcının elle işaretlemesiyle tutar. RESMÎ BORÇ DEĞİLDİR.
// Sorgu SALT OKUNUR (HKS'e hiçbir şey göndermez). Yazma: ödendi işareti (tek ya da TOPLU;
// POST, CSRF, hks.edit, audit) → hks_kv; şema/migration YOK. Eksiksiz ve güncel bir sorgu,
// Hal Kayıt ana ekranındaki kart için hks_kv'ye özet olarak da bırakılır (rusum_ozet ucu).
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

// ── Ödendi işareti (POST; tek ya da toplu) — render_header'dan ÖNCE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $__ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    csrf_check($_POST['csrf'] ?? null);
    $__cik = function (array $j, int $kod = 200) use ($__ajax) {
        if ($__ajax) { http_response_code($kod); header('Content-Type: application/json; charset=utf-8'); echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; }
        if ($kod >= 400) { http_response_code($kod); exit((string)($j['hata'] ?? 'Hata')); }
    };
    if (!role_hks_can('edit')) $__cik(['hata' => 'Ödendi işaretlemek için düzenleme yetkiniz yok.'], 403);
    $__fid = (string)($_POST['firma'] ?? '');
    $__odendi = ($_POST['odendi'] ?? '') === '1';
    $__ham = $_POST['grup'] ?? [];
    if (!is_array($__ham)) $__ham = [$__ham];
    $__yalniz_metin = array_filter($__ham, 'is_string');
    $__gruplar = array_values(array_unique($__yalniz_metin));
    if (!isset($rb_firmalar[$__fid]) || !$__gruplar || count($__yalniz_metin) !== count($__ham)) {   // iç içe/dizi değer → reddet
        $__cik(['hata' => 'Geçersiz firma ya da seçim.'], 400);
    }
    $__kul = trim((string)($auth_user['display_name'] ?? $auth_user['username'] ?? ''));
    try {
        $__n = hks_rusum_odeme_toplu_yaz($__fid, $__gruplar, $__odendi, $__kul);
    } catch (Throwable $e) {
        error_log('[hks rusum_borc] odeme ' . get_class($e));
        $__cik(['hata' => 'İşaret kaydedilemedi.'], 500);
    }
    if ($__n < 0) $__cik(['hata' => 'İşaret kaydedilemedi (seçim geçersiz ya da çok büyük).'], 409);
    // Audit: VKN (grup kodunun başı) YAZILMAZ — yalnız firma id + adet + tahakkuk günü aralığı + yeni durum.
    $__gunler = array_map(fn($g) => substr($g, -17, 8), $__gruplar);
    sort($__gunler);
    audit_log_event('hks_rusum_odeme', 'hks_rusum', null, null, [
        'firma_id' => $__fid, 'adet' => $__n, 'tahakkuk_ilk' => $__gunler[0], 'tahakkuk_son' => $__gunler[count($__gunler) - 1],
        'odendi' => $__odendi,
    ]);
    $__cik(['tamam' => true, 'odendi' => $__odendi, 'adet' => $__n, 'odemeTarihi' => $__odendi ? date('Y-m-d') : '']);
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
if ($rb_bas === '') $rb_bas = date('Y-m-d', strtotime($rb_bugun . ' -6 months'));   // varsayılan: son 6 ay
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
        if ($__c['kismiHata'] > 0) {
            $rb_uyari = 'HKS ' . $__c['kismiHata'] . ' sorgu parçasını yanıtlamadı; liste eksik olabilir. Tekrar sorgulayın.';
        } elseif (hks_rusum_onbellege_uygun($rb_bas, $rb_bit, $rb_bugun)) {
            // Eksiksiz + tam aralıklı sorgu: ana ekrandaki "Rüsum Borcu" kartı bunu okur. Hata yutulur (kart yan ürün).
            try { hks_rusum_onbellek_yaz($rb_firma, $__g['gruplar'], $rb_bas, $rb_bit); }
            catch (Throwable $e) { error_log('[hks rusum_borc] onbellek ' . get_class($e)); }
        }
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
$rb_filtre_etiket = ['odenmedi' => 'Ödenmemiş', 'odendi' => 'Ödenmiş', 'hepsi' => 'Tümü'];

// İlk çizimde görünen satırların toplamı (JS süzgeç değişince yeniden hesaplar).
$rb_gor_top = 0.0; $rb_gor_n = 0;
if ($rb_sonuc !== null) {
    foreach ($rb_sonuc['gruplar'] as $__g) {
        if ($rb_durum === 'hepsi' || ($rb_durum === 'odendi' ? $__g['durum'] === 'odendi' : $__g['durum'] !== 'odendi')) { $rb_gor_top += $__g['tutar']; $rb_gor_n++; }
    }
}

render_header('Rüsum Borcu');
?>
<style>
@page { size: A4 portrait; margin: 12mm; }
.rb-sayfa { max-width: 1100px; }
.rb-bas { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; margin: 4px 0 8px; }
.rb-bas h1 { margin: 0; font-size: 22px; flex: 1 1 auto; min-width: 0; }
.rb-alt-not { margin: 0 0 12px; font-size: 13px; color: var(--muted); }
.rb-not { background: var(--warn-soft); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 9px 11px; margin-bottom: 12px; font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; }
.rb-hata { background: var(--danger-soft); }
.rb-filtre { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; margin-bottom: 14px; }
.rb-izgara { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 12px; }
.rb-filtre label { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; font-weight: 600; color: var(--muted); min-width: 0; }
.rb-filtre select, .rb-filtre input { width: 100%; min-width: 0; box-sizing: border-box; }
.rb-f-firma { flex: 1 1 150px; max-width: 360px; }
.rb-f-tarih { flex: 0 0 150px; }
.rb-f-btn { flex: 0 0 auto; }
.rb-f-btn .btn { white-space: nowrap; }
.rb-kart-alt { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.rb-kartlar { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.rb-kart { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; box-shadow: var(--shadow); min-width: 0; }
.rb-kart-e { font-size: 12px; color: var(--muted); font-weight: 600; }
.rb-kart-d { font-size: 20px; font-weight: 800; margin-top: 2px; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
.rb-kart-gec .rb-kart-d { color: var(--danger); }
.rb-arac { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-bottom: 10px; }
.rb-seg { display: inline-flex; border: 1px solid var(--border); border-radius: 999px; overflow: hidden; max-width: 100%; }
.rb-seg button { border: 0; background: var(--card); color: var(--text); padding: 7px 14px; font-size: 13px; font-weight: 600; cursor: pointer; min-height: 36px; }
.rb-seg button + button { border-left: 1px solid var(--border); }
.rb-seg button[aria-pressed="true"] { background: var(--primary); color: #fff; }
.rb-toplu { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; background: var(--primary-soft); border: 1px solid var(--border); border-radius: 999px; padding: 4px 6px 4px 14px; font-size: 13px; font-weight: 600; }
.rb-toplu[hidden] { display: none; }
.rb-hepsi-mobil { display: none; }
.rb-toplu .btn { min-height: 34px; padding: 3px 12px; font-size: 13px; }
.rb-tablo { width: 100%; border-collapse: collapse; font-size: 14px; }
.rb-tablo th, .rb-tablo td { padding: 8px 9px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
.rb-tablo th { background: var(--surface-2); font-size: 12.5px; color: var(--muted); white-space: nowrap; }
.rb-tablo .rb-s { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 700; }
.rb-tablo .rb-nw { white-space: nowrap; }
.rb-col-sec { width: 44px; padding-left: 4px !important; padding-right: 0 !important; }
.rb-secl { display: flex; align-items: center; justify-content: center; min-width: 40px; min-height: 40px; cursor: pointer; }
.rb-secl input { width: 18px; height: 18px; margin: 0; cursor: pointer; }
.rb-grup { display: block; font-size: 11px; color: var(--muted); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; font-weight: 400; }
.rb-tablo tr.rb-gizli { display: none; }
.rb-tablo tbody tr[data-durum="gecikmis"] td:first-child { box-shadow: inset 3px 0 0 var(--danger); }
.rb-tablo tbody tr[data-durum="odendi"] { color: var(--muted); }
.rb-tablo tbody tr.rb-secili { background: var(--primary-soft); }
.rb-rozet { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--surface-2); border: 1px solid var(--border); white-space: nowrap; }
.rb-rozet[data-r="gecikmis"] { background: var(--danger-soft); color: var(--danger); }
.rb-rozet[data-r="odendi"] { background: var(--success-soft, var(--primary-soft)); color: var(--success, var(--text)); }
.rb-isaret { margin: 0; }
.rb-isaret button { min-height: 36px; padding: 4px 10px; font-size: 13px; }
.rb-bos { color: var(--muted); padding: 14px 0; }
.rb-tablo tfoot td { font-weight: 800; background: var(--primary-soft); border-top: 2px solid var(--border-strong); border-bottom: 0; }
.rb-yz { display: none; }
@media (max-width: 767px) {
    .rb-kartlar { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .rb-kart-d { font-size: 17px; }
    .rb-bas h1 { font-size: 19px; }
    /* Filtre: üst satırda Firma + Sorgula, altında iki tarih */
    .rb-hepsi-mobil { display: inline-flex; align-items: center; gap: 8px; min-height: 40px; font-size: 13px; font-weight: 600; cursor: pointer; }
    .rb-hepsi-mobil input { width: 18px; height: 18px; margin: 0; }
    .rb-f-btn { order: 1; }
    .rb-f-tarih { order: 2; flex: 1 1 130px; }
    /* Satırlar kart olur: 6 sütunlu tablo 390px'e sığmaz (Rüsum/Durum/düğme dışarıda kalıyordu). */
    .rb-tablo thead { display: none; }
    .rb-tablo, .rb-tablo tbody, .rb-tablo tfoot { display: block; }
    .rb-tablo tbody tr { display: grid; grid-template-columns: 40px 1fr 1fr; grid-template-areas: "sec tah tah" "sec vade tutar" "sec durum isl";
        gap: 4px 10px; padding: 10px 12px 10px 4px; border-bottom: 1px solid var(--border); align-items: center; }
    .rb-tablo tbody tr.rb-gizli { display: none; }
    .rb-tablo tbody td { display: block; padding: 0; border: 0; min-width: 0; }
    .rb-tablo td.rb-c-sec { grid-area: sec; }
    .rb-tablo td.rb-c-tah { grid-area: tah; font-weight: 700; font-size: 15px; }
    .rb-tablo td.rb-c-vade { grid-area: vade; }
    .rb-tablo td.rb-c-tutar { grid-area: tutar; text-align: right; font-size: 16px; }
    .rb-tablo td.rb-c-durum { grid-area: durum; }
    .rb-tablo td.rb-c-isl { grid-area: isl; text-align: right; }
    .rb-tablo td[data-etiket]::before { content: attr(data-etiket); display: block; font-size: 11px; font-weight: 600; color: var(--muted); }
    .rb-tablo td.rb-c-adet { display: none; }
    .rb-tablo tbody tr[data-durum="gecikmis"] { box-shadow: inset 3px 0 0 var(--danger); }
    .rb-tablo tbody tr[data-durum="gecikmis"] td:first-child { box-shadow: none; }
    .rb-tablo tfoot tr { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; padding: 10px 12px; background: var(--primary-soft); }
    .rb-tablo tfoot td { display: block; padding: 0; background: none; border: 0; }
    .rb-tablo tfoot td.rb-c-bos { display: none; }
}

/* ── YAZDIR: sade tek satırlık liste ──────────────────────────────────────────
   Mobil kart düzeni yazdırmaya SIZMASIN: kâğıt genişliği (A4 − kenar boşluğu ≈ 700px) 768px'in altında
   kaldığı için yukarıdaki max-width:767px kuralları yazdırmada da geçerli olurdu; tablo düzeni açıkça geri kurulur. */
@media print {
    .desktop-sidebar, .topbar, .topnav, .bottomnav, .rb-ekran, .rb-filtre, .rb-bas, .rb-alt-not, .rb-kartlar, .rb-arac, .rb-not,
    .rb-col-sec, .rb-c-sec, .rb-c-adet, .rb-col-isl, .rb-c-isl, .rb-grup, .rb-isaret, .rb-bos { display: none !important; }
    .container { margin: 0 !important; padding: 0 !important; max-width: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .table-wrap { overflow: visible !important; border: 0 !important; background: none !important; box-shadow: none !important; }
    .rb-yz { display: block; margin: 0 0 6px; }
    .rb-yz-baslik { font-size: 13pt; font-weight: 700; margin: 0; }
    .rb-yz-alt { font-size: 9pt; color: #333; margin: 1px 0 0; }
    .rb-tablo { display: table !important; width: 100%; font-size: 9.5pt; }
    .rb-tablo thead { display: table-header-group !important; }
    .rb-tablo tbody { display: table-row-group !important; }
    .rb-tablo tfoot { display: table-footer-group !important; }
    .rb-tablo tr, .rb-tablo tbody tr, .rb-tablo tfoot tr { display: table-row !important; background: #fff !important; box-shadow: none !important; padding: 0 !important; break-inside: avoid; }
    .rb-tablo tr.rb-gizli, .rb-tablo tbody tr.rb-gizli { display: none !important; }
    .rb-tablo th, .rb-tablo td, .rb-tablo tbody td, .rb-tablo tfoot td { display: table-cell !important; padding: 2px 6px !important; border: 0 !important; border-bottom: 1px solid #bbb !important;
        color: #000 !important; background: #fff !important; box-shadow: none !important; white-space: nowrap; font-weight: 400; font-size: 9.5pt !important; vertical-align: middle; }
    .rb-tablo th { border-bottom: 1.5px solid #000 !important; font-weight: 700; font-size: 9pt !important; }
    .rb-tablo td[data-etiket]::before { content: none !important; }
    .rb-tablo td.rb-s { text-align: right !important; font-weight: 700; }
    .rb-tablo tfoot td { font-weight: 700 !important; border-top: 1.5px solid #000 !important; border-bottom: 0 !important; }
    /* Gizlenecek hücreler, yukarıdaki "table-cell !important" kuralından SONRA ve daha özgül yazılır (yoksa ezilir:
       seçim kutusu sütunu satırı 45px yapıyor, Bildirim sütunu görünüyordu). */
    .rb-tablo .rb-col-sec, .rb-tablo .rb-c-sec, .rb-tablo .rb-col-adet, .rb-tablo .rb-c-adet, .rb-tablo .rb-col-isl, .rb-tablo .rb-c-isl,
    .rb-tablo tfoot td.rb-c-bos { display: none !important; }
    .rb-rozet { border: 0; background: none !important; padding: 0; color: #000 !important; font-size: 9.5pt; font-weight: 400; }
    .rb-tablo tbody tr[data-durum="gecikmis"] .rb-rozet { font-weight: 700; }
}
</style>

<div class="rb-sayfa">
<div class="rb-bas rb-ekran">
    <a class="btn btn-geri" href="index.php">← Hal Kayıt</a>
    <h1>💳 Rüsum Borcu</h1>
    <?php if ($rb_sonuc !== null && $rb_sonuc['gruplar']): ?><button type="button" class="btn" data-rb-yazdir>🖨 Yazdır</button><?php endif; ?>
</div>
<p class="rb-alt-not rb-ekran">Ödemeleri manuel işaretleyiniz.</p>

<form class="rb-filtre rb-ekran" method="get" action="rusum_borc.php">
    <input type="hidden" name="sorgula" value="1">
    <input type="hidden" name="durum" value="<?= h($rb_durum) ?>">
    <div class="rb-izgara">
        <label class="rb-f-firma">Firma
            <select name="firma" required>
                <option value="">Firma seçin…</option>
                <?php foreach ($rb_firmalar as $__id => $__ad): ?>
                <option value="<?= h($__id) ?>"<?= (string)$__id === $rb_firma ? ' selected' : '' ?>><?= h($__ad) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="rb-f-tarih">Başlangıç <input type="date" name="bas" value="<?= h($rb_bas) ?>" max="<?= h($rb_bugun) ?>"></label>
        <label class="rb-f-tarih">Bitiş <input type="date" name="bit" value="<?= h($rb_bit) ?>" max="<?= h($rb_bugun) ?>"></label>
        <div class="rb-f-btn"><button type="submit" class="btn btn-primary" title="En çok <?= (int)HKS_GELEN_MAX_GUN ?> gün; geniş aralıkta sorgu bir dakika sürebilir.">Sorgula</button></div>
    </div>
</form>

<?php if ($rb_hata !== ''): ?><div class="rb-not rb-hata rb-ekran"><?= h($rb_hata) ?></div><?php endif; ?>
<?php if ($rb_uyari !== ''): ?><div class="rb-not rb-ekran"><?= h($rb_uyari) ?></div><?php endif; ?>

<?php if ($rb_sonuc === null && $rb_hata === ''): ?>
<p class="rb-bos rb-ekran">Firma ve tarih aralığı seçip <b>Sorgula</b>'ya basın. Hal Kayıt ekranından gelindiğinde firma hazır seçili gelir.</p>
<?php elseif ($rb_sonuc !== null): $rb_t = $rb_sonuc['toplam']; ?>
<p class="rb-kart-alt rb-ekran" style="margin:0 0 10px"><?= h($rb_firma_ad) ?> · <?= h(hks_rusum_tarih($rb_bas)) ?> – <?= h(hks_rusum_tarih($rb_bit)) ?> ·
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
<div class="rb-yz">
    <p class="rb-yz-baslik">Rüsum Borcu — <?= h($rb_firma_ad) ?></p>
    <p class="rb-yz-alt"><?= h(hks_rusum_tarih($rb_bas)) ?> – <?= h(hks_rusum_tarih($rb_bit)) ?> · <span data-rb-yz-filtre><?= h($rb_filtre_etiket[$rb_durum]) ?></span> · yazdırma: <?= h(hks_rusum_tarih($rb_bugun)) ?></p>
</div>
<div class="rb-arac rb-ekran">
    <div class="rb-seg" role="group" aria-label="Ödeme durumu">
        <button type="button" data-rb-filtre="odenmedi" aria-pressed="<?= $rb_durum === 'odenmedi' ? 'true' : 'false' ?>">Ödenmemiş</button>
        <button type="button" data-rb-filtre="odendi" aria-pressed="<?= $rb_durum === 'odendi' ? 'true' : 'false' ?>">Ödenmiş</button>
        <button type="button" data-rb-filtre="hepsi" aria-pressed="<?= $rb_durum === 'hepsi' ? 'true' : 'false' ?>">Hepsi</button>
    </div>
    <?php if ($rb_duzenle): ?>
    <label class="rb-hepsi-mobil"><input type="checkbox" data-rb-hepsi aria-label="Görünen satırların tümünü seç"> Görünenlerin tümünü seç</label>
    <div class="rb-toplu" data-rb-toplu hidden>
        <span><b data-rb-secili>0</b> seçili</span>
        <button type="button" class="btn btn-primary" data-rb-toplu-odendi>Ödendi işaretle</button>
        <button type="button" class="btn" data-rb-toplu-geri>Geri al</button>
        <button type="button" class="btn" data-rb-toplu-temizle>Seçimi kaldır</button>
    </div>
    <?php endif; ?>
</div>
<div class="table-wrap">
<table class="rb-tablo" id="rbTablo" data-filtre="<?= h($rb_durum) ?>" data-firma="<?= h($rb_firma) ?>" data-bugun="<?= h($rb_bugun) ?>" data-csrf="<?= h(csrf_token()) ?>">
    <thead><tr>
        <?php if ($rb_duzenle): ?><th class="rb-col-sec"><label class="rb-secl" title="Görünen satırların tümünü seç"><input type="checkbox" data-rb-hepsi aria-label="Görünen satırların tümünü seç"></label></th><?php endif; ?>
        <th>Tahakkuk</th><th>Son ödeme</th><th class="rb-s">Rüsum (TL)</th><th class="rb-col-adet rb-s">Bildirim</th><th>Durum</th>
        <?php if ($rb_duzenle): ?><th class="rb-col-isl"></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rb_sonuc['gruplar'] as $__g): ?>
    <tr data-durum="<?= h($__g['durum']) ?>" data-tutar="<?= h((string)$__g['tutar']) ?>" data-grup="<?= h($__g['grup']) ?>" data-vade="<?= h($__g['sonOdeme']) ?>">
        <?php if ($rb_duzenle): ?><td class="rb-c-sec rb-col-sec"><label class="rb-secl"><input type="checkbox" data-rb-sec aria-label="<?= h(hks_rusum_tarih($__g['tahakkuk'])) ?> satırını seç"></label></td><?php endif; ?>
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
    <tfoot><tr>
        <?php if ($rb_duzenle): ?><td class="rb-col-sec rb-c-bos"></td><?php endif; ?>
        <td colspan="2" class="rb-c-tah-top">Toplam (<span data-rb-n><?= (int)$rb_gor_n ?></span> gün)</td>
        <td class="rb-s" data-rb-toplam><?= h(hks_rusum_tl($rb_gor_top)) ?></td>
        <td class="rb-c-bos" colspan="<?= $rb_duzenle ? 3 : 2 ?>"></td>
    </tr></tfoot>
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
  var FILTRE = { odenmedi: 'Ödenmemiş', odendi: 'Ödenmiş', hepsi: 'Tümü' };
  var bugun = tablo.getAttribute('data-bugun');
  var satirlar = Array.prototype.slice.call(tablo.querySelectorAll('tbody tr'));
  var bos = document.querySelector('[data-rb-bos]');
  var toplu = document.querySelector('[data-rb-toplu]');
  var hepsiler = Array.prototype.slice.call(document.querySelectorAll('[data-rb-hepsi]'));   // tablo başlığındaki + mobildeki (başlık gizli)
  function tl(v) { return v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function kutu(tr) { return tr.querySelector('[data-rb-sec]'); }
  function gorunur(tr) { return !tr.classList.contains('rb-gizli'); }
  function seciliSatirlar() { return satirlar.filter(function (tr) { var k = kutu(tr); return k && k.checked && gorunur(tr); }); }
  function uygula() {
    var f = tablo.getAttribute('data-filtre'), top = { tutar: 0, odendi: 0, odenmedi: 0, gecikmis: 0 }, gorunen = 0, gorTop = 0;
    satirlar.forEach(function (tr) {
      var d = tr.getAttribute('data-durum'), t = parseFloat(tr.getAttribute('data-tutar')) || 0;
      top.tutar += t;
      if (d === 'odendi') top.odendi += t; else { top.odenmedi += t; if (d === 'gecikmis') top.gecikmis += t; }
      var goster = f === 'hepsi' || (f === 'odendi' ? d === 'odendi' : d !== 'odendi');
      tr.classList.toggle('rb-gizli', !goster);
      if (goster) { gorunen++; gorTop += t; } else { var k = kutu(tr); if (k) k.checked = false; }   // gizlenen satır seçili kalmasın
      tr.classList.toggle('rb-secili', !!(kutu(tr) && kutu(tr).checked));
    });
    Object.keys(top).forEach(function (k) {
      var el = document.querySelector('[data-rb-top="' + k + '"]');
      if (el) el.textContent = tl(Math.round(top[k] * 100) / 100);
    });
    var tt = tablo.querySelector('[data-rb-toplam]'); if (tt) tt.textContent = tl(Math.round(gorTop * 100) / 100);
    var nn = tablo.querySelector('[data-rb-n]'); if (nn) nn.textContent = String(gorunen);
    if (bos) bos.hidden = gorunen > 0;
    document.querySelectorAll('[data-rb-filtre]').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-rb-filtre') === f ? 'true' : 'false');
    });
    var yf = document.querySelector('[data-rb-yz-filtre]'); if (yf) yf.textContent = FILTRE[f] || '';
    tablo.querySelectorAll('input[name="durum"]').forEach(function (i) { i.value = f; });   // düz form gönderiminde dönülecek filtre
    var sec = seciliSatirlar().length;
    if (toplu) { toplu.hidden = sec === 0; var sn = toplu.querySelector('[data-rb-secili]'); if (sn) sn.textContent = String(sec); }
    hepsiler.forEach(function (h) { h.checked = gorunen > 0 && sec === gorunen; h.indeterminate = sec > 0 && sec < gorunen; });
  }
  document.querySelectorAll('[data-rb-filtre]').forEach(function (b) {
    b.addEventListener('click', function () { tablo.setAttribute('data-filtre', b.getAttribute('data-rb-filtre')); uygula(); });
  });
  // Satır kutuları + "görünenlerin tümü"
  hepsiler.forEach(function (h) {
    h.addEventListener('change', function () {
      satirlar.forEach(function (tr) { var k = kutu(tr); if (k && gorunur(tr)) k.checked = h.checked; });
      uygula();
    });
  });
  tablo.addEventListener('change', function (ev) {
    if (ev.target.hasAttribute && ev.target.hasAttribute('data-rb-sec')) uygula();
  });
  function satirDurum(tr, odendi, tarih) {
    var vade = tr.getAttribute('data-vade') || '';
    var durum = odendi ? 'odendi' : (vade && vade < bugun ? 'gecikmis' : 'odenmedi');
    tr.setAttribute('data-durum', durum);
    var r = tr.querySelector('[data-rb-rozet]'); r.setAttribute('data-r', durum); r.textContent = ETIKET[durum];
    var o = tr.querySelector('[data-rb-odeme]'); if (o) o.textContent = odendi && tarih ? 'işaret: ' + tarih.split('-').reverse().join('.') : '';
    var yeni = tr.querySelector('[data-rb-yeni]'), dugme = tr.querySelector('[data-rb-dugme]');
    if (yeni) yeni.value = odendi ? '0' : '1';
    if (dugme) dugme.textContent = odendi ? 'Geri al' : 'Ödendi işaretle';
  }
  // Tek ya da toplu işaretleme: aynı uç (grup[] listesi).
  function isle(trler, odendi, dugmeler) {
    if (!trler.length) return Promise.resolve();
    var fd = new FormData();
    fd.append('csrf', tablo.getAttribute('data-csrf'));
    fd.append('firma', tablo.getAttribute('data-firma'));
    fd.append('odendi', odendi ? '1' : '0');
    trler.forEach(function (tr) { fd.append('grup[]', tr.getAttribute('data-grup')); });
    dugmeler.forEach(function (d) { d.disabled = true; });
    return fetch('rusum_borc.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (s) {
        if (!s.ok || !s.j.tamam) throw new Error((s.j && s.j.hata) || 'Kaydedilemedi');
        trler.forEach(function (tr) { satirDurum(tr, !!s.j.odendi, s.j.odemeTarihi); var k = kutu(tr); if (k) k.checked = false; });
        uygula();
      })
      .catch(function (e) { alert(e.message || 'Kaydedilemedi'); })
      .then(function () { dugmeler.forEach(function (d) { d.disabled = false; }); });
  }
  tablo.addEventListener('submit', function (ev) {
    var form = ev.target.closest('[data-rb-form]');
    if (!form || !window.fetch) return;   // fetch yoksa düz gönderim
    ev.preventDefault();
    isle([form.closest('tr')], form.querySelector('[data-rb-yeni]').value === '1', [form.querySelector('[data-rb-dugme]')]);
  });
  if (toplu) {
    var bOdendi = toplu.querySelector('[data-rb-toplu-odendi]'), bGeri = toplu.querySelector('[data-rb-toplu-geri]'), bTemiz = toplu.querySelector('[data-rb-toplu-temizle]');
    bOdendi.addEventListener('click', function () { isle(seciliSatirlar(), true, [bOdendi, bGeri]); });
    bGeri.addEventListener('click', function () { isle(seciliSatirlar(), false, [bOdendi, bGeri]); });
    bTemiz.addEventListener('click', function () { satirlar.forEach(function (tr) { var k = kutu(tr); if (k) k.checked = false; }); uygula(); });
  }
  uygula();
})();
</script>
<?php
render_footer();
