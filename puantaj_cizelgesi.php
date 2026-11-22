<?php
// =========================================================
// puantaj_cizelgesi.php — Aylık Puantaj Çizelgesi (Personel Takibi, v552)
//
// Mevcut sayfalardan AYRI, SALT OKUNUR, yalnız GET. Veri mevcut tablolardan
// türetilir (employees + attendance_events / günlük işçi toplu döküm); hesap
// config/puantaj_cizelgesi.php içindedir. Yetki: role_pdks_can('monthly_timesheet')
// + sekme başına kaynağın Gör izni (employees / daily_reports); Excel reports.export.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/pdks_faz8b_cavus_b.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/xlsx_export.php';
require_once __DIR__ . '/config/puantaj_cizelgesi.php';

$auth_user = require_login();
role_pdks_require_request();
if (function_exists('enforce_active_depot')) enforce_active_depot();

$sekmeler = pc_sekmeler();
if (!$sekmeler) forbidden('Bu sayfaya erişim yetkiniz yok. (Aylık puantaj çizelgesi ile Personel kartları veya Günlük puantaj raporları Gör izni gerekir.)');
$csvIst = isset($_GET['csv']);
$xlsxIst = isset($_GET['xlsx']);
if ($csvIst || $xlsxIst) require_perm('reports.export');

$sekme = (string)pc_sekme_sec((string)($_GET['sekme'] ?? ''), $sekmeler);
$ay = pc_ay_dogrula((string)($_GET['ay'] ?? ''));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$pasif = $sekme === 'personel' && !empty($_GET['pasif']);
$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';
$pdo = db();

$tablo = $sekme === 'personel'
    ? pc_tablo_personel($pdo, $ay, $q, $pasif)
    : pc_tablo_gunluk($pdo, $ay, $depo, $q);
$hata = $tablo['hata'] ?? null;

$param = ['sekme' => $sekme, 'ay' => $ay, 'q' => $q, 'pasif' => $pasif ? '1' : ''];

// ── Dışa aktarım (ekranla AYNI veri; XLSX kolu CSV'den önce) ──
if ($csvIst || $xlsxIst) {
    if ($hata !== null) {
        set_flash('error', $hata);
        header('Location: ' . pc_url('puantaj_cizelgesi.php', $param));
        exit;
    }
    $filtre = ['sekme' => $sekme, 'ay' => $ay, 'arama' => $q !== '', 'depo' => $depo, 'pasif' => $pasif ? '1' : ''];
    $dosya = 'puantaj_cizelgesi_' . $sekme . '_' . $ay;
    $acik = array_filter([$depo !== '' ? 'Depo: ' . $depo : 'Tüm depolar', $q !== '' ? 'Arama: ' . $q : '', pc_lejant_metni($tablo['tur'])]);
    if ($xlsxIst) {
        export_audit('pdks', 'puantaj_cizelgesi', 'xlsx', count($tablo['satirlar']), $filtre);
        xlsx_indir($dosya . '.xlsx', [pc_xlsx_sayfa($tablo, implode(' · ', $acik))],
            pc_url('puantaj_cizelgesi.php', $param + ['csv' => '1']));
    }
    export_audit('pdks', 'puantaj_cizelgesi', 'csv', count($tablo['satirlar']), $filtre);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $dosya . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, pc_disa_baslik($tablo), ';', '"', '\\');
    foreach (pc_disa_satirlar($tablo) as $r) {
        foreach ($r as $i => $v) {
            if (is_float($v)) $r[$i] = number_format($v, 2, ',', '');   // TR Excel için ondalık virgül
            elseif (is_string($v) && $v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $r[$i] = "'" . $v;   // CSV formül enjeksiyonu koruması
        }
        fputcsv($out, $r, ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// ── Ekran ──
render_header('Aylık Puantaj Çizelgesi');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/puantaj_cizelgesi.css?v=' . @filemtime(__DIR__ . '/assets/puantaj_cizelgesi.css') . '">';
render_flash();

$tur = $sekme === 'gunluk' ? 'gunluk' : 'personel';
$gunluk = $tur === 'gunluk';
$onceki = pc_ay_gez($ay, -1);
$sonraki = pc_ay_gez($ay, 1);
$adlar = pc_sekme_adlari();
$say = $hata === null ? count($tablo['satirlar']) : 0;
$kisiEtiket = $gunluk ? 'Çavuş' : 'Personel';
$ayUzun = pc_ay_adlari();
?>
<div class="pc-page" data-tur="<?= h($tur) ?>">
<div class="page-head">
    <div>
        <h1>📅 Aylık Puantaj Çizelgesi</h1>
        <p class="muted" style="margin:4px 0 0"><?= h(pc_ay_basligi($ay)) ?> · <?= h($depo !== '' ? $depo : 'Tüm depolar') ?></p>
    </div>
    <div class="page-head-actions">
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
    </div>
</div>

<form method="get" action="puantaj_cizelgesi.php" class="pc-bar" id="pcForm">
    <input type="hidden" name="sekme" value="<?= h($sekme) ?>">
    <div class="pc-ay">
        <a class="btn pc-ok" href="<?= h(pc_url('puantaj_cizelgesi.php', ['ay' => $onceki] + $param)) ?>" aria-label="Önceki ay" title="Önceki ay">‹</a>
        <input type="month" name="ay" value="<?= h($ay) ?>" class="pc-ay-giris" pattern="\d{4}-\d{2}" placeholder="YYYY-AA" aria-label="Ay" data-oto>
        <a class="btn pc-ok" href="<?= h(pc_url('puantaj_cizelgesi.php', ['ay' => $sonraki] + $param)) ?>" aria-label="Sonraki ay" title="Sonraki ay">›</a>
    </div>
    <input type="search" name="q" value="<?= h($q) ?>" class="pc-ara" placeholder="Ad veya sicil ara…" aria-label="Ad veya sicil ara" maxlength="60" enterkeyhint="search">
    <?php if ($sekme === 'personel'): ?>
    <label class="pc-pasif"><input type="checkbox" name="pasif" value="1" <?= $pasif ? 'checked' : '' ?> data-oto> Pasifleri de göster</label>
    <?php endif; ?>
    <noscript><button type="submit" class="btn">Göster</button></noscript>
    <?php if (count($sekmeler) > 1): ?>
    <nav class="pc-sekmeler" aria-label="Puantaj türü">
        <?php foreach ($sekmeler as $sk): ?>
        <a href="<?= h(pc_url('puantaj_cizelgesi.php', ['sekme' => $sk] + $param)) ?>" class="pc-sekme<?= $sk === $sekme ? ' is-aktif' : '' ?>"<?= $sk === $sekme ? ' aria-current="page"' : '' ?>><?= h($adlar[$sk]) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="pc-aksiyon">
        <?= $hata === null ? export_menu(pc_url('puantaj_cizelgesi.php', $param + ['csv' => '1']), pc_url('puantaj_cizelgesi.php', $param + ['xlsx' => '1']), 'Excel') : '' ?>
        <?php if ($hata === null): ?><a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="<?= h(pc_url('puantaj_cizelgesi_yazdir.php', $param)) ?>">🖨️ Yazdır</a><?php endif; ?>
    </div>
</form>

<div class="pc-lejant" id="pcLejant">
    <button type="button" class="pc-lejant-dugme" aria-expanded="false" aria-controls="pcLejantIc" title="Gösterge">ⓘ <span>Gösterge</span></button>
    <div class="pc-lejant-ic" id="pcLejantIc"><?= h(pc_lejant_metni($tur)) ?></div>
</div>
<?php if ($gunluk): ?>
<p class="pc-not">Günlük işçide kişi değil <strong>kart / çavuş bazında</strong> sayılır: satır = çavuş, hücre = o gün çavuşun mesaisinde giriş yapan kart sayısı.</p>
<?php endif; ?>

<?php if ($hata !== null): ?>
<div class="pc-bos" role="status"><span aria-hidden="true">⚠️</span> <?= h($hata) ?></div>
<?php elseif ($say === 0): ?>
<div class="pc-bos" role="status"><span aria-hidden="true">📭</span> <?= $q !== '' ? 'Aramanıza uyan ' . h(mb_strtolower($kisiEtiket, 'UTF-8')) . ' yok.' : h(pc_ay_basligi($ay)) . ' için ' . ($gunluk ? 'çavuş kaydı' : 'personel') . ' bulunamadı.' ?></div>
<?php else:
    $gunler = $tablo['gunler'];
    $genel = $tablo['genel'];
    $ozBaslik = $gunluk ? 'İşçi-gün' : 'Saat';
?>

<!-- ≥768px: çizelge ızgarası -->
<div class="table-wrap pc-grid-wrap" id="pcGridWrap">
<table class="pc-grid">
    <thead>
    <tr>
        <th class="pc-ad pc-kose" scope="col"><?= h($kisiEtiket) ?> <span class="pc-adet">(<?= $say ?>)</span></th>
        <?php foreach ($gunler as $g): ?>
        <th class="pc-g<?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?>" scope="col" title="<?= h($g['n'] . ' ' . $ayUzun[(int)substr($ay, 5, 2)] . ' ' . pc_gun_adlari_uzun()[$g['hg']]) ?>"<?= $g['bugun'] ? ' data-bugun' : '' ?>><span class="pc-gn"><?= $g['n'] ?></span><span class="pc-gk"><?= h($g['ad']) ?></span></th>
        <?php endforeach; ?>
        <th class="pc-oz" scope="col" title="Çalışılan gün">Gün</th>
        <th class="pc-oz" scope="col" title="<?= $gunluk ? 'Toplam işçi-gün' : 'Toplam saat' ?>"><?= h($ozBaslik) ?></th>
        <th class="pc-oz" scope="col" title="Eksik çıkış">Eksik</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($tablo['satirlar'] as $s): ?>
    <tr>
        <th class="pc-ad" scope="row"><span class="pc-ad-ad"><?= h($s['ad']) ?></span><?php if ($s['alt'] !== ''): ?><small><?= h($s['alt']) ?></small><?php endif; ?></th>
        <?php foreach ($gunler as $g):
            [$cls, $ic, $tt] = pc_hucre_goster($tur, $s['hucre'][$g['tarih']] ?? null);
            if ($gunluk && $ic !== '·') {
                $tt = $g['n'] . ' ' . $ayUzun[(int)substr($ay, 5, 2)] . ' · ' . $tt;
            } ?>
        <td class="pc-h <?= $cls ?><?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?>"<?= $tt !== '' ? ' title="' . h($tt) . '"' : '' ?>><?= $ic ?></td>
        <?php endforeach; ?>
        <td class="pc-oz"><?= (int)$s['ozet']['gun'] ?></td>
        <td class="pc-oz"><?= $gunluk ? (int)$s['ozet']['toplam'] : h(pc_sayi_fmt($s['ozet']['toplam'])) ?></td>
        <td class="pc-oz<?= $s['ozet']['eksik'] >= 1 ? ' pc-uyari' : '' ?>"><?= (int)$s['ozet']['eksik'] >= 1 ? '! ' . (int)$s['ozet']['eksik'] : '0' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
    <tr class="pc-say">
        <th class="pc-ad" scope="row"><?= $gunluk ? 'O gün toplam işçi' : 'O gün çalışan' ?></th>
        <?php foreach ($gunler as $g): $n = (int)($tablo['gun_say'][$g['tarih']] ?? 0); ?>
        <td class="pc-h<?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?>"><?= $n > 0 ? $n : '·' ?></td>
        <?php endforeach; ?>
        <td class="pc-oz" colspan="3"></td>
    </tr>
    <tr class="pc-genel">
        <th class="pc-ad" scope="row">Genel toplam</th>
        <td class="pc-genel-bos" colspan="<?= count($gunler) ?>"><?= (int)$genel['kisi'] ?> <?= h(mb_strtolower($kisiEtiket, 'UTF-8')) ?></td>
        <td class="pc-oz"><?= (int)$genel['gun'] ?></td>
        <td class="pc-oz"><?= $gunluk ? (int)$genel['toplam'] : h(pc_sayi_fmt($genel['toplam'])) ?></td>
        <td class="pc-oz<?= $genel['eksik'] >= 1 ? ' pc-uyari' : '' ?>"><?= (int)$genel['eksik'] >= 1 ? '! ' . (int)$genel['eksik'] : '0' ?></td>
    </tr>
    </tfoot>
</table>
</div>

<!-- <768px: kişi listesi; dokununca o kişinin ay takvimi -->
<div class="pc-liste" id="pcListe">
    <div class="pc-genel-kart">
        <strong><?= (int)$genel['kisi'] ?> <?= h(mb_strtolower($kisiEtiket, 'UTF-8')) ?></strong>
        <span class="pc-rz" title="Toplam çalışılan gün"><?= (int)$genel['gun'] ?> gün</span>
        <span class="pc-rz" title="<?= $gunluk ? 'Toplam işçi-gün' : 'Toplam saat' ?>"><?= $gunluk ? (int)$genel['toplam'] . ' işçi-gün' : h(pc_sayi_fmt($genel['toplam'])) . ' sa' ?></span>
        <span class="pc-rz<?= $genel['eksik'] >= 1 ? ' pc-rz-uyari' : '' ?>" title="Eksik çıkış"><?= (int)$genel['eksik'] ?> eksik</span>
    </div>
    <?php
    $ilkHg = $gunler[0]['hg'];   // ayın ilk gününün haftadaki yeri (1=Pzt)
    foreach ($tablo['satirlar'] as $s): $oz = $s['ozet']; ?>
    <details class="pc-kisi">
        <summary>
            <span class="pc-k-ad"><span class="pc-ad-ad"><?= h($s['ad']) ?></span><?php if ($s['alt'] !== ''): ?><small><?= h($s['alt']) ?></small><?php endif; ?></span>
            <span class="pc-rozetler">
                <span class="pc-rz" title="Çalışılan gün"><?= (int)$oz['gun'] ?> gün</span>
                <span class="pc-rz" title="<?= $gunluk ? 'Toplam işçi-gün' : 'Toplam saat' ?>"><?= $gunluk ? (int)$oz['toplam'] . ' işçi' : h(pc_sayi_fmt($oz['toplam'])) . ' sa' ?></span>
                <?php if ($oz['eksik'] >= 1): ?><span class="pc-rz pc-rz-uyari" title="Eksik çıkış">! <?= (int)$oz['eksik'] ?></span><?php endif; ?>
            </span>
        </summary>
        <div class="pc-takvim" role="grid" aria-label="<?= h($s['ad'] . ' — ' . pc_ay_basligi($ay)) ?>">
            <?php foreach (pc_gun_adlari() as $hn => $hk): ?><span class="pc-t-bas<?= $hn >= 6 ? ' pc-hs' : '' ?>"><?= h($hk) ?></span><?php endforeach; ?>
            <?php for ($i = 1; $i < $ilkHg; $i++): ?><span class="pc-t pc-t-bos" aria-hidden="true"></span><?php endfor; ?>
            <?php foreach ($gunler as $g):
                [$cls, $ic, $tt] = pc_hucre_goster($tur, $s['hucre'][$g['tarih']] ?? null); ?>
            <span class="pc-t <?= $cls ?><?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?>"<?= $tt !== '' ? ' title="' . h($tt) . '"' : '' ?>><span class="pc-t-n"><?= $g['n'] ?></span><span class="pc-t-ic"><?= $ic ?></span></span>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<script src="<?= h($base) ?>assets/puantaj_cizelgesi.js?v=<?= (int)@filemtime(__DIR__ . '/assets/puantaj_cizelgesi.js') ?>" defer></script>
<?php render_footer(); ?>
