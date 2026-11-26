<?php
// =========================================================
// muh_mali_tablolar.php — Mizan, Muavin/Kebir, Gelir Tablosu, Bilanço & KDV Özeti. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/muhasebe_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('muh_mali_tablolar')) { forbidden(); }
muh_kapi('read');
muh_no_store();
$pdo = db();
muh_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'mizan');
if (!in_array($sekme, ['mizan', 'muavin', 'gelir', 'bilanco', 'kdv'], true)) {
    $sekme = 'mizan';
}
$bas = (string)($_GET['bas'] ?? date('Y-01-01'));
$bit = (string)($_GET['bit'] ?? date('Y-m-d'));
if (!muh_tarih_gecerli($bas)) $bas = date('Y-01-01');
if (!muh_tarih_gecerli($bit)) $bit = date('Y-m-d');
$hesap = trim((string)($_GET['hesap'] ?? '100'));

$veri = match ($sekme) {
    'muavin'  => muh_muavin($pdo, $hesap, $bas, $bit),
    'gelir'   => muh_gelir_tablosu($pdo, $bas, $bit),
    'bilanco' => muh_bilanco($pdo, $bit),
    'kdv'     => muh_kdv_ozeti($pdo, $bas, $bit),
    default   => muh_mizan($pdo, $bas, $bit),
};

$exportCan = is_admin() || can('reports.export');
if (($_GET['export'] ?? '') === 'csv' && in_array($sekme, ['mizan', 'muavin'], true)) {
    if (!$exportCan) { forbidden(); }
    if (function_exists('export_prepare_runtime')) export_prepare_runtime();
    if (function_exists('export_audit')) export_audit('genel_muhasebe_' . $sekme, ['bas' => $bas, 'bit' => $bit, 'hesap' => $hesap]);
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $sekme . '_' . $bas . '_' . $bit . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = static function ($v): string {
        $s = (string)$v;
        return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    };
    if ($sekme === 'mizan') {
        fputcsv($out, ['Kod', 'Hesap Adı', 'Borç', 'Alacak', 'Borç Bakiye', 'Alacak Bakiye'], ';');
        foreach (($veri['satirlar'] ?? []) as $r) {
            fputcsv($out, [$safe($r['kod']), $safe($r['ad']), $r['borc'], $r['alacak'], $r['borc_bakiye'], $r['alacak_bakiye']], ';');
        }
    } else {
        fputcsv($out, ['Tarih', 'Fiş No', 'Belge No', 'Açıklama', 'Borç', 'Alacak', 'Yürüyen Bakiye'], ';');
        foreach (($veri['hareketler'] ?? []) as $r) {
            fputcsv($out, [$r['tarih'], $safe($r['fis_no']), $safe($r['belge_no']), $safe($r['aciklama']), $r['borc'], $r['alacak'], $r['yuruyen_bakiye']], ';');
        }
    }
    fclose($out);
    exit;
}

$hareketHesaplari = muh_hesap_listesi($pdo, ['aktif' => 1]);
$filtre = ['bas' => $bas, 'bit' => $bit, 'hesap' => $hesap];

render_header('Mizan & Mali Tablolar');
muh_head_varlik();
render_flash();
echo muh_mali_tablolar_render($sekme, $veri, $hareketHesaplari, $filtre, $exportCan);
muh_scripts();
render_footer();
