<?php
// =========================================================
// puantaj_cizelgesi.php — Aylık Puantaj Çizelgesi (Personel Takibi, v552)
//
// Mevcut sayfalardan AYRI, yalnız GET (sayfa hiçbir şey yazmaz). Veri mevcut tablolardan
// türetilir (employees + attendance_events / günlük işçi toplu döküm); hesap
// config/puantaj_cizelgesi.php içindedir. Yetki: role_pdks_can('monthly_timesheet')
// + sekme başına kaynağın Gör izni (employees / daily_reports); Excel reports.export.
// v553: Personel sekmesinde elle gün işareti (D/İ/Ü/R/T) — yalnız 'monthly_timesheet' Düzenle
// izni + tablo hazırsa (migrate.php › Puantaj İşaretleri) hücreler tıklanır; yazma ucu
// puantaj_isaret_kaydet.php (fetch POST, CSRF). Tablo yoksa çizelge eskisi gibi, arayüz gizli.
// v554: üçüncü sekme 'Puantaj Girişi' (sekme=giris) — BAĞIMSIZ, gayri resmi: kullanıcının kendi tanımladığı kişiler
// (puantaj_giris_kisiler/gunler; employees/PDKS ile ilişkisiz). Kapı: monthly_timesheet.edit; yazma ucu
// puantaj_giris_kaydet.php. v555: Puantaj Girişi İLK sekme + varsayılan açılış (sekme parametresi yoksa; kullanıcı görebiliyorsa);
// içinde iç görünüm anahtarı [Puantaj] [Kişiler] (gorunum=kisiler: liste, düzenle/pasif/sil, toplu işlem, Excel'den ekle) ve
// çoklu kişi × gün toplu işaretleme (assets/puantaj_giris.js). ?sablon=1 = Excel'den ekleme şablonu (xlsx) indirir (edit yetkisi).
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

$pdo = db();
[$sekmeler, $girisHazir] = pc_sekmeler_hazir($pdo);
if (!$sekmeler) forbidden('Bu sayfaya erişim yetkiniz yok. (Aylık puantaj çizelgesi ile Personel kartları veya Günlük puantaj raporları Gör izni gerekir.)');
$csvIst = isset($_GET['csv']);
$xlsxIst = isset($_GET['xlsx']);
if ($csvIst || $xlsxIst) require_perm('reports.export');

$sekme = (string)pc_sekme_sec((string)($_GET['sekme'] ?? ''), $sekmeler);
$ay = pc_ay_dogrula((string)($_GET['ay'] ?? ''));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$pasif = in_array($sekme, ['personel', 'giris'], true) && !empty($_GET['pasif']);
$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

// v555: Puantaj Girişi şablonu (Excel'den kişi ekleme) — yalnız Düzenle yetkisi; dosya üretir, hiçbir şey yazmaz
if ($sekme === 'giris' && isset($_GET['sablon'])) {
    if (!role_pdks_can('monthly_timesheet', 'edit')) forbidden('Bu işlem için yetkiniz yok.');
    xlsx_gonder(pge_sablon_uret(), 'puantaj_kisi_sablonu.xlsx');
}

// v555: Puantaj Girişi iç görünümü: 'puantaj' (çizelge) | 'kisiler' (kişi yönetimi). Dışa aktarım her zaman çizelgedir.
$gorunum = ($sekme === 'giris' && ($_GET['gorunum'] ?? '') === 'kisiler' && !$csvIst && !$xlsxIst) ? 'kisiler' : 'puantaj';
$durum = in_array((string)($_GET['durum'] ?? ''), ['aktif', 'pasif'], true) ? (string)$_GET['durum'] : 'hepsi';
$kisiVeri = null;
if ($gorunum === 'kisiler') {
    if (!pg_hazir($pdo)) {
        $tablo = ['hata' => 'Puantaj Girişi tabloları henüz kurulmamış.', 'sema' => true];
    } else {
        try {
            $hepsi = pg_kisiler_yonetim($pdo);
            $kisiVeri = ['kisiler' => pg_kisiler_filtrele($hepsi, $q, $durum), 'sayac' => pg_kisi_sayaclari($hepsi)];
            $tablo = ['tur' => 'giris', 'satirlar' => $kisiVeri['kisiler']];
        } catch (Throwable $e) {
            error_log('[puantaj_giris] kisiler: ' . $e->getMessage());
            $tablo = ['hata' => 'Kişi listesi şu anda okunamadı.'];
        }
    }
} else {
    $tablo = $sekme === 'personel'
        ? pc_tablo_personel($pdo, $ay, $q, $pasif)
        : ($sekme === 'giris' ? pg_tablo($pdo, $ay, $q, $pasif) : pc_tablo_gunluk($pdo, $ay, $depo, $q));
}
$hata = $tablo['hata'] ?? null;

$param = ['sekme' => $sekme, 'ay' => $ay, 'q' => $q, 'pasif' => $pasif ? '1' : ''];
$kisilerGorunum = $gorunum === 'kisiler';
if ($kisilerGorunum) $param = ['sekme' => 'giris', 'gorunum' => 'kisiler', 'q' => $q, 'durum' => $durum === 'hepsi' ? '' : $durum];

// v553: elle işaret arayüzü — yalnız Personel sekmesi, tablo hazır, 'edit' + kaynak (Personel kartları Gör) izni
$piHazir = $sekme === 'personel' && $hata === null && pi_hazir($pdo);
$duzenle = $piHazir && role_pdks_can('monthly_timesheet', 'edit') && role_pdks_can('employees');
$piSemaNotu = $sekme === 'personel' && $hata === null && !$piHazir && is_admin();

// ── Dışa aktarım (ekranla AYNI veri; XLSX kolu CSV'den önce) ──
if ($csvIst || $xlsxIst) {
    if ($hata !== null) {
        set_flash('error', $hata);
        header('Location: ' . pc_url('puantaj_cizelgesi.php', $param));
        exit;
    }
    $filtre = ['sekme' => $sekme, 'ay' => $ay, 'arama' => $q !== '', 'depo' => $depo, 'pasif' => $pasif ? '1' : ''];
    $dosya = 'puantaj_cizelgesi_' . $sekme . '_' . $ay;
    $acik = array_filter([$depo !== '' ? 'Depo: ' . $depo : 'Tüm depolar', $q !== '' ? 'Arama: ' . $q : '', pc_lejant_metni($tablo['tur'], $piHazir), $sekme === 'giris' ? PG_BILGI_METNI : '']);
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
            if (is_float($v)) $r[$i] = number_format($v, $sekme === 'giris' ? 1 : 2, ',', '');   // TR Excel için ondalık virgül
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

$tur = $sekme === 'gunluk' ? 'gunluk' : ($sekme === 'giris' ? 'giris' : 'personel');
$gunluk = $tur === 'gunluk';
$giris = $tur === 'giris';
$onceki = pc_ay_gez($ay, -1);
$sonraki = pc_ay_gez($ay, 1);
$adlar = pc_sekme_adlari();
$say = $hata === null ? count($tablo['satirlar']) : 0;
$kisiEtiket = $gunluk ? 'Çavuş' : ($giris ? 'Kişi' : 'Personel');
$ayUzun = pc_ay_adlari();
?>
<div class="pc-page" data-tur="<?= h($tur) ?>"<?= $duzenle ? ' data-pi-url="puantaj_isaret_kaydet.php" data-pi-csrf="' . h(csrf_token()) . '"' : '' ?><?= $giris && $hata === null ? ' data-pg-url="puantaj_giris_kaydet.php" data-pg-csrf="' . h(csrf_token()) . '" data-pg-ay="' . h($ay) . '" data-pg-q="' . h($q) . '" data-pg-gorunum="' . ($kisilerGorunum ? 'kisiler' : 'puantaj') . '" data-pg-kodlar="' . h(pg_kodlar_json()) . '"' : '' ?>>
<div class="page-head">
    <div>
        <h1>📅 Aylık Puantaj Çizelgesi</h1>
        <p class="muted" style="margin:4px 0 0"><?= h($kisilerGorunum ? 'Puantaj Girişi · Kişiler' : pc_ay_basligi($ay)) ?> · <?= h($depo !== '' ? $depo : 'Tüm depolar') ?></p>
    </div>
    <div class="page-head-actions">
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
    </div>
</div>

<form method="get" action="puantaj_cizelgesi.php" class="pc-bar" id="pcForm">
    <input type="hidden" name="sekme" value="<?= h($sekme) ?>">
    <?php if ($kisilerGorunum): ?><input type="hidden" name="gorunum" value="kisiler"><?php endif; ?>
    <?php if (!$kisilerGorunum): ?>
    <div class="pc-ay">
        <a class="btn pc-ok" href="<?= h(pc_url('puantaj_cizelgesi.php', ['ay' => $onceki] + $param)) ?>" aria-label="Önceki ay" title="Önceki ay">‹</a>
        <input type="month" name="ay" value="<?= h($ay) ?>" class="pc-ay-giris" pattern="\d{4}-\d{2}" placeholder="YYYY-AA" aria-label="Ay" data-oto>
        <a class="btn pc-ok" href="<?= h(pc_url('puantaj_cizelgesi.php', ['ay' => $sonraki] + $param)) ?>" aria-label="Sonraki ay" title="Sonraki ay">›</a>
    </div>
    <?php endif; ?>
    <input type="search" name="q" value="<?= h($q) ?>" class="pc-ara" placeholder="<?= $giris ? 'Ad veya görev ara…' : 'Ad veya sicil ara…' ?>" aria-label="<?= $giris ? 'Ad veya görev ara' : 'Ad veya sicil ara' ?>" maxlength="60" enterkeyhint="search">
    <?php if ($kisilerGorunum): ?>
    <select name="durum" class="pc-ay-giris pk-durum-sec" aria-label="Durum süzgeci" data-oto>
        <option value="hepsi"<?= $durum === 'hepsi' ? ' selected' : '' ?>>Tümü</option>
        <option value="aktif"<?= $durum === 'aktif' ? ' selected' : '' ?>>Aktif</option>
        <option value="pasif"<?= $durum === 'pasif' ? ' selected' : '' ?>>Pasif</option>
    </select>
    <?php elseif ($sekme === 'personel' || $giris): ?>
    <label class="pc-pasif"><input type="checkbox" name="pasif" value="1" <?= $pasif ? 'checked' : '' ?> data-oto> Pasifleri de göster</label>
    <?php endif; ?>
    <noscript><button type="submit" class="btn">Göster</button></noscript>
    <?php if (count($sekmeler) > 1): ?>
    <nav class="pc-sekmeler" aria-label="Puantaj türü">
        <?php foreach ($sekmeler as $sk): ?>
        <a href="<?= h(pc_url('puantaj_cizelgesi.php', ['sekme' => $sk, 'ay' => $ay, 'q' => $q, 'pasif' => ($pasif && $sk !== 'gunluk') ? '1' : ''])) ?>" class="pc-sekme<?= $sk === $sekme ? ' is-aktif' : '' ?>"<?= $sk === $sekme ? ' aria-current="page"' : '' ?>><?= h($adlar[$sk]) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="pc-aksiyon">
        <?php if ($giris && $hata === null): ?><button type="button" class="btn btn-sm btn-primary pg-ekle" data-pg-kisi-ekle>+ Kişi ekle</button><?php endif; ?>
        <?php if ($kisilerGorunum && $hata === null): ?><button type="button" class="btn btn-sm pk-excel-ac" data-pk-excel-ac>Excel'den ekle</button><?php endif; ?>
        <?php if (!$kisilerGorunum): ?>
        <?= $hata === null ? export_menu(pc_url('puantaj_cizelgesi.php', $param + ['csv' => '1']), pc_url('puantaj_cizelgesi.php', $param + ['xlsx' => '1']), 'Excel') : '' ?>
        <?php if ($hata === null): ?><a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="<?= h(pc_url('puantaj_cizelgesi_yazdir.php', $param)) ?>">🖨️ Yazdır</a><?php endif; ?>
        <?php endif; ?>
    </div>
</form>
<?php if ($giris && $hata === null): ?>
<nav class="pg-gorunum" aria-label="Puantaj Girişi görünümü">
    <a href="<?= h(pc_url('puantaj_cizelgesi.php', ['sekme' => 'giris', 'ay' => $ay, 'q' => $kisilerGorunum ? '' : $q, 'pasif' => $pasif ? '1' : ''])) ?>" class="pg-gorunum-bag<?= $kisilerGorunum ? '' : ' is-aktif' ?>"<?= $kisilerGorunum ? '' : ' aria-current="page"' ?>>📅 Puantaj</a>
    <a href="<?= h(pc_url('puantaj_cizelgesi.php', ['sekme' => 'giris', 'gorunum' => 'kisiler', 'q' => $kisilerGorunum ? $q : ''])) ?>" class="pg-gorunum-bag<?= $kisilerGorunum ? ' is-aktif' : '' ?>"<?= $kisilerGorunum ? ' aria-current="page"' : '' ?>>👥 Kişiler</a>
</nav>
<?php endif; ?>

<?php if (!$kisilerGorunum): ?>
<div class="pc-lejant" id="pcLejant">
    <button type="button" class="pc-lejant-dugme" aria-expanded="false" aria-controls="pcLejantIc" title="Gösterge">ⓘ <span>Gösterge</span></button>
    <div class="pc-lejant-ic" id="pcLejantIc"><?= h(pc_lejant_metni($tur, false)) ?>
        <?php if ($giris): ?><span class="pc-kl-liste"><?php foreach (pg_kodlar() as $k => $kd): ?><span class="pc-kl pc-k pc-k-<?= h($kd['sinif']) ?>"><b><?= h($k) ?></b> <?= h($kd['ad']) ?></span><?php endforeach; ?></span>
        <?php if ($hata === null): ?><span class="pc-kl-ipucu">Hücreye tıklayarak (mobilde dokunarak) tek gün işaretleyin. Çok kişiye birden: kişi kutularını ve gün numaralarını seçin (ya da "Gün seç" kısayolları), alttaki çubuktan kodu seçip Uygula'ya basın. Kişi adının yanındaki ⋯ menüsünden düzenleyebilirsiniz.</span><?php endif; ?>
        <?php elseif ($piHazir): ?><span class="pc-kl-liste"><?php foreach (pi_kodlar() as $k => $kd): ?><span class="pc-kl pc-k pc-k-<?= h($kd['sinif']) ?>"><b><?= h($k) ?></b> <?= h($kd['ad']) ?></span><?php endforeach; ?></span><?php endif; ?>
        <?php if ($duzenle): ?><span class="pc-kl-ipucu">Güne tıklayarak (mobilde dokunarak) işaretleyin; işaret, o günün otomatik X / ! değerinin yerine geçer.</span><?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php if ($piSemaNotu): ?>
<p class="pc-not">Şema kurulumu: <a href="migrate.php#puantaj-isaret">migrate.php › Puantaj İşaretleri</a> (elle gün işaretleme için tablo henüz kurulmamış; çizelge bu olmadan da çalışır).</p>
<?php endif; ?>
<?php if ($giris): ?>
<p class="pc-not pg-bilgi"><?= h(PG_BILGI_METNI) ?></p>
<?php endif; ?>
<?php if ($gunluk): ?>
<p class="pc-not">Günlük işçide kişi değil <strong>kart / çavuş bazında</strong> sayılır: satır = çavuş, hücre = o gün çavuşun mesaisinde giriş yapan kart sayısı.</p>
<?php endif; ?>

<?php if ($hata !== null): ?>
<div class="pc-bos" role="status"><span aria-hidden="true">⚠️</span> <?= h($hata) ?><?php if (!empty($tablo['sema'])): ?><br>Önce <a href="migrate.php#puantaj-giris">migrate.php › Puantaj Girişi</a> kartını çalıştırın.<?php endif; ?></div>
<?php elseif ($kisilerGorunum): pg_kisiler_ekran_ciz($kisiVeri, ['q' => $q, 'durum' => $durum]); ?>
<?php elseif ($giris): pg_ekran_ciz($tablo, ['ay' => $ay, 'q' => $q, 'pasif' => $pasif]); ?>
<?php elseif ($say === 0): ?>
<div class="pc-bos" role="status"><span aria-hidden="true">📭</span> <?= $q !== '' ? 'Aramanıza uyan ' . h(mb_strtolower($kisiEtiket, 'UTF-8')) . ' yok.' : h(pc_ay_basligi($ay)) . ' için ' . ($gunluk ? 'çavuş kaydı' : 'personel') . ' bulunamadı.' ?></div>
<?php else:
    $gunler = $tablo['gunler'];
    $genel = $tablo['genel'];
    $ozBaslik = $gunluk ? 'İşçi-gün' : 'Saat';
    $ozKol = $gunluk ? 3 : 6;   // özet sütun sayısı (personel: Gün · Saat · Eksik · İzin · Rapor · Devamsız)
    // Düzenlenebilir hücrenin veri öznitelikleri (yalnız $duzenle): boşken salt okunur
    $piOz = static function (int $emp, string $tarih, ?array $hh) use ($duzenle): string {
        if (!$duzenle) return '';
        return ' data-pi data-emp="' . $emp . '" data-tarih="' . h($tarih) . '" data-kod="' . h($hh && !empty($hh['isaret']) ? (string)$hh['k'] : '') . '"';
    };
?>

<!-- ≥768px: çizelge ızgarası -->
<div class="table-wrap pc-grid-wrap" id="pcGridWrap">
<table class="pc-grid">
    <colgroup><col class="pc-col-isim"></colgroup>
    <thead>
    <tr>
        <th class="pc-isim pc-kose" scope="col"><?= h($kisiEtiket) ?> <span class="pc-isim-sayi">(<?= $say ?>)</span></th>
        <?php foreach ($gunler as $g): ?>
        <th class="pc-g<?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?>" scope="col" title="<?= h($g['n'] . ' ' . $ayUzun[(int)substr($ay, 5, 2)] . ' ' . pc_gun_adlari_uzun()[$g['hg']]) ?>"<?= $g['bugun'] ? ' data-bugun' : '' ?>><span class="pc-gn"><?= $g['n'] ?></span><span class="pc-gk"><?= h($g['ad']) ?></span></th>
        <?php endforeach; ?>
        <th class="pc-oz" scope="col" title="Çalışılan gün">Gün</th>
        <th class="pc-oz" scope="col" title="<?= $gunluk ? 'Toplam işçi-gün' : 'Toplam saat' ?>"><?= h($ozBaslik) ?></th>
        <th class="pc-oz" scope="col" title="Eksik çıkış">Eksik</th>
        <?php if (!$gunluk): ?>
        <th class="pc-oz" scope="col" title="İzin günü (Yıllık izin + Ücretsiz izin)">İzin</th>
        <th class="pc-oz" scope="col" title="Raporlu gün">Rapor</th>
        <th class="pc-oz" scope="col" title="Devamsız gün">Devamsız</th>
        <?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($tablo['satirlar'] as $s): ?>
    <tr>
        <th class="pc-isim" scope="row"><span class="pc-isim-yazi"><?= h($s['ad']) ?></span><?php if ($s['alt'] !== ''): ?><small><?= h($s['alt']) ?></small><?php endif; ?></th>
        <?php foreach ($gunler as $g):
            $hh = $s['hucre'][$g['tarih']] ?? null;
            [$cls, $ic, $tt] = pc_hucre_goster($tur, $hh);
            if ($gunluk && $ic !== '·') {
                $tt = $g['n'] . ' ' . $ayUzun[(int)substr($ay, 5, 2)] . ' · ' . $tt;
            } ?>
        <td class="pc-h <?= $cls ?><?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?><?= $duzenle ? ' pc-duz' : '' ?>"<?= $tt !== '' ? ' title="' . h($tt) . '"' : '' ?><?= $piOz((int)$s['id'], $g['tarih'], $hh) ?>><?= $ic ?></td>
        <?php endforeach; ?>
        <td class="pc-oz"><?= (int)$s['ozet']['gun'] ?></td>
        <td class="pc-oz"><?= $gunluk ? (int)$s['ozet']['toplam'] : h(pc_sayi_fmt($s['ozet']['toplam'])) ?></td>
        <td class="pc-oz<?= $s['ozet']['eksik'] >= 1 ? ' pc-uyari' : '' ?>"><?= (int)$s['ozet']['eksik'] >= 1 ? '! ' . (int)$s['ozet']['eksik'] : '0' ?></td>
        <?php if (!$gunluk): ?>
        <td class="pc-oz"><?= (int)$s['ozet']['izin'] ?></td>
        <td class="pc-oz"><?= (int)$s['ozet']['rapor'] ?></td>
        <td class="pc-oz"><?= (int)$s['ozet']['devamsiz'] ?></td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
    <tr class="pc-say">
        <th class="pc-isim" scope="row"><?= $gunluk ? 'O gün toplam işçi' : 'O gün çalışan' ?></th>
        <?php foreach ($gunler as $g): $n = (int)($tablo['gun_say'][$g['tarih']] ?? 0); ?>
        <td class="pc-h<?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?>"><?= $n > 0 ? $n : '·' ?></td>
        <?php endforeach; ?>
        <td class="pc-oz" colspan="<?= $ozKol ?>"></td>
    </tr>
    <tr class="pc-genel">
        <th class="pc-isim" scope="row">Genel toplam</th>
        <td class="pc-genel-bos" colspan="<?= count($gunler) ?>"><?= (int)$genel['kisi'] ?> <?= h(mb_strtolower($kisiEtiket, 'UTF-8')) ?></td>
        <td class="pc-oz"><?= (int)$genel['gun'] ?></td>
        <td class="pc-oz"><?= $gunluk ? (int)$genel['toplam'] : h(pc_sayi_fmt($genel['toplam'])) ?></td>
        <td class="pc-oz<?= $genel['eksik'] >= 1 ? ' pc-uyari' : '' ?>"><?= (int)$genel['eksik'] >= 1 ? '! ' . (int)$genel['eksik'] : '0' ?></td>
        <?php if (!$gunluk): ?>
        <td class="pc-oz"><?= (int)$genel['izin'] ?></td>
        <td class="pc-oz"><?= (int)$genel['rapor'] ?></td>
        <td class="pc-oz"><?= (int)$genel['devamsiz'] ?></td>
        <?php endif; ?>
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
        <?php if (!$gunluk && ($genel['izin'] || $genel['rapor'] || $genel['devamsiz'])): ?>
        <span class="pc-rz" title="İzin günü"><?= (int)$genel['izin'] ?> izin</span>
        <span class="pc-rz" title="Raporlu gün"><?= (int)$genel['rapor'] ?> rapor</span>
        <span class="pc-rz" title="Devamsız gün"><?= (int)$genel['devamsiz'] ?> devamsız</span>
        <?php endif; ?>
    </div>
    <?php
    $ilkHg = $gunler[0]['hg'];   // ayın ilk gününün haftadaki yeri (1=Pzt)
    foreach ($tablo['satirlar'] as $s): $oz = $s['ozet']; ?>
    <details class="pc-kisi" data-emp-kisi="<?= (int)$s['id'] ?>">
        <summary>
            <span class="pc-k-isim"><span class="pc-isim-yazi"><?= h($s['ad']) ?></span><?php if ($s['alt'] !== ''): ?><small><?= h($s['alt']) ?></small><?php endif; ?></span>
            <span class="pc-rozetler">
                <span class="pc-rz" title="Çalışılan gün"><?= (int)$oz['gun'] ?> gün</span>
                <span class="pc-rz" title="<?= $gunluk ? 'Toplam işçi-gün' : 'Toplam saat' ?>"><?= $gunluk ? (int)$oz['toplam'] . ' işçi' : h(pc_sayi_fmt($oz['toplam'])) . ' sa' ?></span>
                <?php if ($oz['eksik'] >= 1): ?><span class="pc-rz pc-rz-uyari" title="Eksik çıkış">! <?= (int)$oz['eksik'] ?></span><?php endif; ?>
                <?php if (!$gunluk && $oz['izin'] >= 1): ?><span class="pc-rz" title="İzin günü"><?= (int)$oz['izin'] ?> izin</span><?php endif; ?>
                <?php if (!$gunluk && $oz['rapor'] >= 1): ?><span class="pc-rz" title="Raporlu gün"><?= (int)$oz['rapor'] ?> rapor</span><?php endif; ?>
                <?php if (!$gunluk && $oz['devamsiz'] >= 1): ?><span class="pc-rz" title="Devamsız gün"><?= (int)$oz['devamsiz'] ?> devamsız</span><?php endif; ?>
            </span>
        </summary>
        <div class="pc-takvim" role="grid" aria-label="<?= h($s['ad'] . ' — ' . pc_ay_basligi($ay)) ?>">
            <?php foreach (pc_gun_adlari() as $hn => $hk): ?><span class="pc-t-bas<?= $hn >= 6 ? ' pc-hs' : '' ?>"><?= h($hk) ?></span><?php endforeach; ?>
            <?php for ($i = 1; $i < $ilkHg; $i++): ?><span class="pc-t pc-t-bos" aria-hidden="true"></span><?php endfor; ?>
            <?php foreach ($gunler as $g):
                $hh = $s['hucre'][$g['tarih']] ?? null;
                [$cls, $ic, $tt] = pc_hucre_goster($tur, $hh); ?>
            <span class="pc-t <?= $cls ?><?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?><?= $duzenle ? ' pc-duz' : '' ?>"<?= $tt !== '' ? ' title="' . h($tt) . '"' : '' ?><?= $piOz((int)$s['id'], $g['tarih'], $hh) ?>><span class="pc-t-n"><?= $g['n'] ?></span><span class="pc-t-ic"><?= $ic ?></span></span>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($duzenle): ?>
<!-- Elle işaret menüsü (v553): masaüstünde hücrenin yanında küçük menü, <768px'te alt sayfa (z-index ≥ 600). <form> YOK — fetch POST. -->
<div class="pi-ovl" id="piOvl" hidden></div>
<div class="pi-menu" id="piMenu" role="dialog" aria-labelledby="piBaslik" hidden>
    <div class="pi-bas"><span class="pi-baslik" id="piBaslik"></span><button type="button" class="pi-kapat" data-pi-kapat aria-label="Kapat">×</button></div>
    <div class="pi-liste">
        <?php foreach (pi_kodlar() as $k => $kd): ?>
        <button type="button" class="pi-dugme pc-k pc-k-<?= h($kd['sinif']) ?>" data-pi-kod="<?= h($k) ?>"><b><?= h($k) ?></b><span><?= h($kd['ad']) ?></span></button>
        <?php endforeach; ?>
        <button type="button" class="pi-dugme pi-temizle" data-pi-kod="">Temizle</button>
    </div>
    <p class="pi-hata" id="piHata" role="alert" hidden></p>
</div>
<?php endif; ?>
</div>

<script src="<?= h($base) ?>assets/puantaj_cizelgesi.js?v=<?= (int)@filemtime(__DIR__ . '/assets/puantaj_cizelgesi.js') ?>" defer></script>
<?php if ($giris && $hata === null): ?><script src="<?= h($base) ?>assets/puantaj_giris.js?v=<?= (int)@filemtime(__DIR__ . '/assets/puantaj_giris.js') ?>" defer></script><?php endif; ?>
<?php if ($kisilerGorunum && $hata === null): ?><script src="<?= h($base) ?>assets/puantaj_giris_kisiler.js?v=<?= (int)@filemtime(__DIR__ . '/assets/puantaj_giris_kisiler.js') ?>" defer></script><?php endif; ?>
<?php render_footer(); ?>
