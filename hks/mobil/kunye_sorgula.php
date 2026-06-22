<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (!can('hks.read') && !is_admin()) {
    http_response_code(403); die('Erişim yetkiniz yok (hks.read).');
}

$op_repo         = new HksRepository(db());
$op_settings     = $op_repo->getSettings();
$op_company_name = $op_settings['firma_adi'] ?? 'AGRONATURAL';
$op_env          = $op_settings['environment'] ?? 'test';

// Validate mode
$mode = trim($_GET['mode'] ?? '');
if ($mode !== 'satis' && $mode !== 'sevk') {
    http_response_code(400); die('Geçersiz mod.');
}

$mode_label   = $mode === 'satis' ? 'Satış' : 'Sevk Etme';
$back_tab     = $mode === 'satis' ? 'satis' : 'sevk-etme';

// Filters from GET
$musteri_tc  = trim($_GET['musteri'] ?? '');
$plaka_filter = strtoupper(trim($_GET['plaka'] ?? ''));
$baslangic   = trim($_GET['baslangic'] ?? date('Y-m-d', strtotime('-14 days')));
$bitis       = trim($_GET['bitis'] ?? date('Y-m-d'));

// Validate dates
$bas_obj = DateTime::createFromFormat('Y-m-d', $baslangic);
$bit_obj = DateTime::createFromFormat('Y-m-d', $bitis);
$dates_valid = $bas_obj && $bit_obj
    && $bas_obj->format('Y-m-d') === $baslangic
    && $bit_obj->format('Y-m-d') === $bitis;

// Run query
$hks_result   = null;
$records      = [];
$raw_data     = null;
$query_error  = null;

if ($dates_valid) {
    $company_id = isset($_SESSION['hks_company_id']) ? (int)$_SESSION['hks_company_id'] : 0;
    if ($company_id === 0) {
        $all_cos    = $op_repo->getAllCompanies();
        $company_id = !empty($all_cos) ? (int)$all_cos[0]['id'] : 0;
    }

    $client = new HksClient($op_settings);

    $hks_params = [
        'BaslangicTarihi' => $bas_obj->format('Y-m-d\T00:00:00'),
        'BitisTarihi'     => $bit_obj->format('Y-m-d\T00:00:00'),
    ];

    if ($mode === 'satis') {
        $hks_result = hks_query_bildirim_liste(
            fn(array $p) => $client->getYaptigimBildirimler($p),
            $hks_params,
            ''
        );
    } else {
        $hks_result = hks_query_bildirim_liste(
            fn(array $p) => $client->getBanaYapilanBildirimler($p),
            $hks_params,
            ''
        );
    }

    $raw_data = $hks_result['data'] ?? null;

    if (!empty($hks_result['ok'])) {
        $records = hks_collect_dto_records($raw_data ?? []);

        // PHP-side filtering by plaka
        if ($plaka_filter !== '') {
            $records = array_values(array_filter($records, function($r) use ($plaka_filter) {
                $p = strtoupper(trim((string)(
                    $r['AracPlakaNo'] ?? $r['AracPlaka'] ?? $r['Plaka'] ?? ''
                )));
                return $p === $plaka_filter || str_contains($p, $plaka_filter);
            }));
        }

        // PHP-side filtering by customer tc_vkn
        if ($musteri_tc !== '') {
            if ($mode === 'satis') {
                // Filter by Alici/MalinSahibi TC/VKN
                $records = array_values(array_filter($records, function($r) use ($musteri_tc) {
                    $tc = (string)(
                        $r['AliciTcKimlikVergiNo'] ??
                        $r['MalinSahibiTcKimlikVergiNo'] ??
                        $r['MalinSahibiTcVergiNo'] ??
                        $r['MalinSahibi'] ?? ''
                    );
                    return $tc !== '' && str_starts_with($tc, substr($musteri_tc, 0, 4));
                }));
            } else {
                // Sevk: filter by gönderen (Satici/Bildirici)
                $records = array_values(array_filter($records, function($r) use ($musteri_tc) {
                    $tc = (string)(
                        $r['SaticiTcKimlikVergiNo'] ??
                        $r['BildiricininTcKimlikVergiNo'] ??
                        $r['BildiricininTcVergiNo'] ?? ''
                    );
                    return $tc !== '' && str_starts_with($tc, substr($musteri_tc, 0, 4));
                }));
            }
        }
    } else {
        $norm = hks_normalize_response($raw_data ?? []);
        $query_error = $norm['user_message'] ?? ($hks_result['message'] ?? 'HKS servisine ulaşılamadı.');
    }
} else {
    $query_error = 'Geçersiz tarih aralığı.';
}

// CSRF token for Seç form
$csrf_token = csrf_token();

// Cache filtered records in session for secure Seç POST flow (single-use token)
$session_token = '';
if (!empty($records)) {
    $session_token = bin2hex(random_bytes(16));
    if (!isset($_SESSION['hks_mobile_kunye_results'])) {
        $_SESSION['hks_mobile_kunye_results'] = [];
    }
    // Prune expired tokens; keep max 5 live entries
    $now = time();
    $_SESSION['hks_mobile_kunye_results'] = array_filter(
        $_SESSION['hks_mobile_kunye_results'],
        fn($e) => ($e['expires'] ?? 0) > $now
    );
    if (count($_SESSION['hks_mobile_kunye_results']) >= 5) {
        array_shift($_SESSION['hks_mobile_kunye_results']);
    }
    $_SESSION['hks_mobile_kunye_results'][$session_token] = [
        'expires'    => $now + 600,  // 10 minutes
        'mode'       => $mode,
        'musteri_tc' => $musteri_tc,
        'records'    => $records,
    ];
}

include __DIR__ . '/_layout.php';
hks_mob_start($mode_label . ' Künye Sorgula', 'anasayfa');

// Helper to safely get a field from a DTO (case-insensitive, multiple aliases)
function ks_gf(mixed $o, array $names): string {
    if (!is_array($o) && !is_object($o)) return '';
    $arr = is_object($o) ? (array)$o : $o;
    foreach ($names as $name) {
        foreach ($arr as $k => $v) {
            if (strtolower($k) === strtolower($name) && $v !== null && $v !== '') {
                return (string)$v;
            }
        }
    }
    return '';
}
?>

<div class="ks-header">
    <a href="index.php?tab=<?= hks_mob_h($back_tab) ?>" class="ks-back-btn">&#8592; Geri</a>
    <span class="ks-title"><?= hks_mob_h($mode_label) ?> Künye</span>
    <span class="ks-mode-badge"><?= $op_env === 'live' ? 'CANLI' : 'TEST' ?></span>
</div>

<!-- Applied filters summary -->
<div class="ks-filter-bar">
    <span class="ks-filter-item">
        <?= hks_mob_h(date('d.m.Y', strtotime($baslangic))) ?> –
        <?= hks_mob_h(date('d.m.Y', strtotime($bitis))) ?>
    </span>
    <?php if ($musteri_tc !== ''): ?>
    <span class="ks-filter-item">TC/VKN: <?= hks_mob_h($musteri_tc) ?></span>
    <?php endif; ?>
    <?php if ($plaka_filter !== ''): ?>
    <span class="ks-filter-item">Plaka: <?= hks_mob_h($plaka_filter) ?></span>
    <?php endif; ?>
</div>

<?php if ($query_error !== null): ?>
<!-- HKS error -->
<div class="ks-error-box">
    <strong>HKS Hatası</strong><br>
    <?= hks_mob_h($query_error) ?>
    <?php if ($raw_data !== null): ?>
    <details style="margin-top:8px;">
        <summary class="ks-details-toggle">Teknik Detay (ham JSON)</summary>
        <pre class="ks-raw-json"><?= hks_mob_h(json_encode(
            hks_mask_sensitive(is_array($raw_data) ? $raw_data : (array)$raw_data),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )) ?></pre>
    </details>
    <?php endif; ?>
</div>

<?php elseif (empty($records)): ?>
<!-- Zero results -->
<div class="ks-empty">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="width:40px;height:40px;color:#9eaabf;">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <p>Bu kriterlere uygun künye bulunamadı.</p>
    <?php if ($raw_data !== null): ?>
    <details style="margin-top:8px;text-align:left;">
        <summary class="ks-details-toggle">Teknik Detay (ham JSON)</summary>
        <pre class="ks-raw-json"><?= hks_mob_h(json_encode(
            hks_mask_sensitive(is_array($raw_data) ? $raw_data : (array)$raw_data),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )) ?></pre>
    </details>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Results -->
<p class="ks-result-count"><?= count($records) ?> kayıt bulundu</p>

<?php foreach ($records as $i => $rec): ?>
<?php
    $kunye_no      = ks_gf($rec, ['KunyeNo','MalinKunyeNo','ReferansKunyeNo']);
    $tarih         = ks_gf($rec, ['BildirimTarihi','KayitTarihi','Tarih']);
    $urun          = ks_gf($rec, ['MalinAdi','UrunAdi','Urun']);
    $cins          = ks_gf($rec, ['MalinCinsi','UrunCinsi','Cins']);
    $miktar_val    = ks_gf($rec, ['MalinMiktari','MalinMiktar','Miktar']);
    $miktar_birim  = ks_gf($rec, ['MiktarBirimiAd','MiktarBirimAd','BirimAdi','Birim']);
    $kalan         = ks_gf($rec, ['KalanMiktar','Kalan']);
    $plaka_val     = ks_gf($rec, ['AracPlakaNo','AracPlaka','Plaka']);
    $mal_sahibi    = ks_gf($rec, ['MalinSahibiTcKimlikVergiNo','MalinSahibAdi','MalinSahibiTcVergiNo','MalinSahibi']);
    $uretici       = ks_gf($rec, ['UreticiTcKimlikVergiNo','UreticisininAdUnvani']);
    $sifat         = ks_gf($rec, ['Sifat','SifatAdi']);
    $bildirim_turu = ks_gf($rec, ['BildirimTuru','BildirimTuruAdi']);
    $unique_id     = ks_gf($rec, ['UniqueId']);
    $tarih_fmt     = '';
    if ($tarih !== '') {
        $td = DateTime::createFromFormat('Y-m-d\TH:i:s', $tarih)
           ?: DateTime::createFromFormat('Y-m-d H:i:s', $tarih)
           ?: DateTime::createFromFormat('Y-m-d', substr($tarih, 0, 10));
        if ($td) $tarih_fmt = $td->format('d.m.Y');
    }
    $miktar_display = $miktar_val !== '' ? number_format((float)str_replace(',', '.', $miktar_val), 2, ',', '.') . ($miktar_birim !== '' ? ' ' . $miktar_birim : '') : '';
    $kalan_display  = $kalan !== '' ? number_format((float)str_replace(',', '.', $kalan), 2, ',', '.') : '';
?>
<div class="ks-card">
    <div class="ks-card-head">
        <span class="ks-kunye-no"><?= $kunye_no !== '' ? hks_mob_h($kunye_no) : '—' ?></span>
        <?php if ($tarih_fmt !== ''): ?>
        <span class="ks-card-date"><?= hks_mob_h($tarih_fmt) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($urun !== '' || $cins !== ''): ?>
    <div class="ks-card-urun">
        <?= hks_mob_h($urun) ?><?= ($urun !== '' && $cins !== '') ? ' / ' : '' ?><?= hks_mob_h($cins) ?>
    </div>
    <?php endif; ?>

    <div class="ks-card-rows">
        <?php if ($miktar_display !== ''): ?>
        <div class="ks-card-row"><span class="ks-label">Miktar</span><span class="ks-val"><?= hks_mob_h($miktar_display) ?></span></div>
        <?php endif; ?>
        <?php if ($kalan_display !== ''): ?>
        <div class="ks-card-row"><span class="ks-label">Kalan</span><span class="ks-val"><?= hks_mob_h($kalan_display) ?></span></div>
        <?php endif; ?>
        <?php if ($plaka_val !== ''): ?>
        <div class="ks-card-row"><span class="ks-label">Plaka</span><span class="ks-val"><?= hks_mob_h($plaka_val) ?></span></div>
        <?php endif; ?>
        <?php if ($mal_sahibi !== ''): ?>
        <div class="ks-card-row"><span class="ks-label">Mal Sahibi</span><span class="ks-val"><?= hks_mob_h($mal_sahibi) ?></span></div>
        <?php endif; ?>
        <?php if ($uretici !== ''): ?>
        <div class="ks-card-row"><span class="ks-label">Üretici</span><span class="ks-val"><?= hks_mob_h($uretici) ?></span></div>
        <?php endif; ?>
        <?php if ($sifat !== ''): ?>
        <div class="ks-card-row"><span class="ks-label">Sıfat</span><span class="ks-val"><?= hks_mob_h($sifat) ?></span></div>
        <?php endif; ?>
        <?php if ($bildirim_turu !== ''): ?>
        <div class="ks-card-row"><span class="ks-label">Bildirim Türü</span><span class="ks-val"><?= hks_mob_h($bildirim_turu) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="ks-card-footer">
        <?php if ($session_token !== ''): ?>
        <form method="post" action="taslak_olustur.php" style="margin:0;flex-shrink:0;">
            <input type="hidden" name="csrf"  value="<?= hks_mob_h($csrf_token) ?>">
            <input type="hidden" name="token" value="<?= hks_mob_h($session_token) ?>">
            <input type="hidden" name="idx"   value="<?= $i ?>">
            <input type="hidden" name="mode"  value="<?= hks_mob_h($mode) ?>">
            <button type="submit" class="mob-btn mob-btn-solid ks-sec-btn">Seç</button>
        </form>
        <?php else: ?>
        <button class="mob-btn mob-btn-outline disabled ks-sec-btn" disabled>Seç</button>
        <?php endif; ?>
        <details style="flex:1;">
            <summary class="ks-details-toggle">Teknik Detay</summary>
            <pre class="ks-raw-json"><?= hks_mob_h(json_encode(
                hks_mask_sensitive(is_array($rec) ? $rec : (array)$rec),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            )) ?></pre>
        </details>
    </div>
</div>
<?php endforeach; ?>

<?php if ($raw_data !== null): ?>
<details style="margin:12px 0;">
    <summary class="ks-details-toggle">Tüm Ham Yanıt (JSON)</summary>
    <pre class="ks-raw-json"><?= hks_mob_h(json_encode(
        hks_mask_sensitive(is_array($raw_data) ? $raw_data : (array)$raw_data),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    )) ?></pre>
</details>
<?php endif; ?>

<?php endif; ?>

<p class="mob-sprint-note" style="margin-top:16px;">Seçilen künye e-Bildirim taslağına dönüştürülür. Gönderim sonraki sprintte etkinleşecek.</p>

<style>
.ks-header {
    display:flex; align-items:center; gap:10px; margin-bottom:14px; padding-bottom:10px;
    border-bottom:1px solid #e8edf5;
}
.ks-back-btn {
    font-size:15px; color:#1565c0; text-decoration:none; padding:6px 4px; flex-shrink:0;
}
.ks-title { font-size:16px; font-weight:700; color:#1a2236; flex:1; }
.ks-mode-badge {
    font-size:10px; font-weight:700; padding:2px 7px; border-radius:6px;
    background:#e8f4fd; color:#1565c0;
}
.ks-filter-bar {
    display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px;
}
.ks-filter-item {
    font-size:12px; background:#f0f5fd; color:#1565c0; padding:3px 10px;
    border-radius:12px; font-weight:500;
}
.ks-error-box {
    background:#fee2e2; color:#991b1b; border-radius:12px; padding:14px 16px;
    font-size:14px; margin-bottom:12px;
}
.ks-empty {
    text-align:center; padding:40px 20px; color:#7b8fa8;
}
.ks-empty p { margin-top:10px; font-size:14px; }
.ks-result-count {
    font-size:13px; color:#7b8fa8; margin-bottom:10px;
}
.ks-card {
    background:#fff; border-radius:12px; padding:14px 16px; margin-bottom:10px;
    box-shadow:0 1px 3px rgba(21,101,192,.07);
}
.ks-card-head {
    display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;
}
.ks-kunye-no { font-size:15px; font-weight:700; color:#1565c0; }
.ks-card-date { font-size:12px; color:#9eaabf; }
.ks-card-urun {
    font-size:14px; font-weight:600; color:#1a2236; margin-bottom:8px;
}
.ks-card-rows { margin-bottom:10px; }
.ks-card-row {
    display:flex; justify-content:space-between; align-items:baseline;
    font-size:13px; padding:2px 0; border-bottom:1px solid #f3f5f9;
}
.ks-card-row:last-child { border-bottom:none; }
.ks-label { color:#7b8fa8; white-space:nowrap; margin-right:8px; }
.ks-val { color:#1a2236; font-weight:500; text-align:right; word-break:break-all; }
.ks-card-footer {
    display:flex; align-items:center; gap:10px; margin-top:10px; padding-top:8px;
    border-top:1px solid #f3f5f9;
}
.ks-sec-btn { flex:0 0 70px; min-width:0; font-size:13px; padding:6px 10px; }
.ks-details-toggle {
    font-size:12px; color:#7b8fa8; cursor:pointer; list-style:none;
}
.ks-details-toggle::-webkit-details-marker { display:none; }
.ks-raw-json {
    white-space:pre-wrap; font-size:11px; color:#374151; background:#f8fafc;
    border-radius:6px; padding:8px; margin-top:6px; max-height:220px; overflow:auto;
    font-family:monospace; word-break:break-all;
}
</style>

<?php hks_mob_end(); ?>
