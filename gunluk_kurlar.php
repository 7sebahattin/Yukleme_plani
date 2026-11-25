<?php
// =========================================================
// gunluk_kurlar.php — Günlük Kurlar & TCMB Tarihçe (YALNIZ GET; tüm yazmalar doviz_kaydet.php'de)
//   ?gorunum=gunluk|gecmis · ?tarih=YYYY-MM-DD · ?tumu=0|1 · ?kod=USD&bas=...&bit=...
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doviz_core.php';
require_once __DIR__ . '/config/doviz_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('gunluk_kurlar')) { forbidden(); }
doviz_no_store();

$pdo = db();
doviz_sayfa_kapisi($pdo);

$bugun   = date('Y-m-d');
$gorunum = (string)($_GET['gorunum'] ?? 'gunluk');
if (!in_array($gorunum, ['gunluk', 'gecmis'], true)) {
    $gorunum = 'gunluk';
}

$istenenTarih = doviz_tarih_coz($_GET['tarih'] ?? '');
$sonTarih     = doviz_son_tarih($pdo);

// İş günleri 15:30 sonrası bugünün bülteni henüz DB'de yoksa 5 dk soğuma ile otomatik TCMB kontrolü
if (
    $gorunum === 'gunluk'
    && $sonTarih !== $bugun
    && (int)date('N') <= 5
    && date('H:i') >= '15:30'
) {
    $otoSon = (int)($_SESSION['_dvz_gunluk_oto'][$bugun] ?? 0);
    if ((time() - $otoSon) >= 300) {
        $_SESSION['_dvz_gunluk_oto'][$bugun] = time();
        $otoUid = isset($auth_user['id']) ? (int)$auth_user['id'] : null;
        $otoRes = doviz_tcmb_senkron($pdo, $bugun, $otoUid, false, false);
        if (!empty($otoRes['ok']) && !empty($otoRes['tarih'])) {
            $sonTarih = doviz_son_tarih($pdo) ?? (string)$otoRes['tarih'];
        }
    }
}

$tarih        = $istenenTarih ?? ($sonTarih ?? $bugun);

$ts         = strtotime($tarih . ' 12:00:00');
$oncekiGun  = date('Y-m-d', $ts - 86400);
$sonrakiGun = date('Y-m-d', $ts + 86400);

$tumu = ((string)($_GET['tumu'] ?? '0')) === '1';

$tanimlar       = doviz_tanim_listesi($pdo, false);
$kurTipleri     = doviz_kur_tipleri();
$mevcutTarihler = doviz_mevcut_tarihler($pdo, 60);

$kurlar    = [];
$kpiKurlar = [];
$bultenNo  = '';
$gecmis    = [];
$gecmisKod = '';
$gecmisBas = '';
$gecmisBit = '';

if ($gorunum === 'gecmis') {
    $gecmisKod = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if (!preg_match('/^[A-Z]{3,10}$/', $gecmisKod)) {
        $gecmisKod = '';
    }
    $gecmisBas = doviz_tarih_coz($_GET['bas'] ?? '') ?? '';
    $gecmisBit = doviz_tarih_coz($_GET['bit'] ?? '') ?? '';
    $gecmis    = doviz_gecmis_liste($pdo, $gecmisKod, $gecmisBas, $gecmisBit, 150);
} else {
    $kurlar = doviz_gunluk_liste($pdo, $tarih, !$tumu);
    foreach ($kurlar as $r) {
        if ($bultenNo === '' && !empty($r['bulten_no'])) {
            $bultenNo = (string)$r['bulten_no'];
            break;
        }
    }
    $aktifKurlar = $tumu ? doviz_gunluk_liste($pdo, $tarih, true) : $kurlar;
    $kpiKaynak   = $aktifKurlar ?: $kurlar;
    $kpiKurlar   = array_slice($kpiKaynak, 0, 4);
}

$geriUrl = 'gunluk_kurlar.php?gorunum=' . rawurlencode($gorunum)
    . ($gorunum === 'gunluk' ? '&tarih=' . rawurlencode($tarih) . ($tumu ? '&tumu=1' : '') : '');

$ctx = [
    'gorunum'         => $gorunum,
    'tarih'           => $tarih,
    'bugun'           => $bugun,
    'son_tarih'       => $sonTarih,
    'onceki_gun'      => $oncekiGun,
    'sonraki_gun'     => $sonrakiGun,
    'tumu'            => $tumu,
    'bulten_no'       => $bultenNo,
    'kurlar'          => $kurlar,
    'kpi_kurlar'      => $kpiKurlar,
    'mevcut_tarihler' => $mevcutTarihler,
    'tanimlar'        => $tanimlar,
    'kur_tipleri'     => $kurTipleri,
    'gecmis'          => $gecmis,
    'gecmis_kod'      => $gecmisKod,
    'gecmis_bas'      => $gecmisBas,
    'gecmis_bit'      => $gecmisBit,
    'yazabilir'       => doviz_can('write'),
    'silebilir'       => doviz_can('delete'),
    'tanim_izni'      => muhasebe_sayfa_can('doviz_tanimlari'),
    'csrf'            => csrf_token(),
    'geri_url'        => $geriUrl,
];

render_header('Günlük Kurlar');
doviz_head_varlik();
render_flash();
echo doviz_gunluk_sayfa_render($ctx);
doviz_scripts();
render_footer();
