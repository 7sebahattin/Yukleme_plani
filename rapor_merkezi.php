<?php
// =========================================================
// rapor_merkezi.php — ERP Faz 5 Yeni Raporlama & BI Merkezi (Konsolide KPI + Modül/BI Raporları + CSV). YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/bi_rapor_core.php';
require_once __DIR__ . '/config/bi_rapor_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('rapor_merkezi')) { forbidden(); }
bi_kapi('read');
bi_no_store();
$pdo = db();
bi_rapor_sayfa_kapisi($pdo);

$basT = trim((string)($_GET['bas_tarih'] ?? date('Y-m-01')));
$bitT = trim((string)($_GET['bit_tarih'] ?? date('Y-m-d')));
if (!bi_tarih_gecerli($basT)) $basT = date('Y-m-01');
if (!bi_tarih_gecerli($bitT)) $bitT = date('Y-m-d');
$ara       = trim((string)($_GET['ara'] ?? ''));
$secSablon = (int)($_GET['sablon_id'] ?? 0);
$secKaynak = trim((string)($_GET['kaynak'] ?? ''));

$sablonlar = bi_sablon_listesi($pdo);
$sonuc = null;
$ekFiltre = ['bas_tarih' => $basT, 'bit_tarih' => $bitT, 'ara' => $ara];

if ($secSablon > 0) {
    $st = $pdo->prepare('SELECT * FROM `bi_rapor_sablonlari` WHERE id = ?');
    $st->execute([$secSablon]);
    $sb = $st->fetch(PDO::FETCH_ASSOC);
    if ($sb) {
        $sonuc = bi_sablon_calistir($pdo, $sb, $ekFiltre);
    } else {
        $sonuc = ['ok' => false, 'hata' => 'Seçilen BI şablonu bulunamadı.'];
    }
} elseif ($secKaynak !== '') {
    $sonuc = bi_sablon_calistir($pdo, ['veri_kaynagi' => $secKaynak], $ekFiltre);
}

// CSV (Excel) İndirme — B-33 Formula Injection Korumalı
if (($_GET['csv'] ?? '') === '1' && $sonuc !== null && !empty($sonuc['ok'])) {
    if (function_exists('export_prepare_runtime')) export_prepare_runtime();
    $dosya = 'bi_rapor_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $dosya . '"');
    echo bi_csv_uret($sonuc['sutunlar'], $sonuc['satirlar']);
    exit;
}

$veri = [
    'kpis'       => bi_yonetici_ozeti($pdo, $basT, $bitT),
    'sablonlar'  => $sablonlar,
    'sec_kaynak' => $secKaynak,
    'sec_sablon' => $secSablon,
    'bas_tarih'  => $basT,
    'bit_tarih'  => $bitT,
    'ara'        => $ara,
    'sonuc'      => $sonuc,
];

render_header('Raporlama & BI Merkezi');
bi_head_varlik();
render_flash();
echo bi_rapor_merkezi_render($veri);
bi_scripts();
render_footer();
