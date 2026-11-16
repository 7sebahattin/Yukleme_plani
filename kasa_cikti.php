<?php
// =========================================================
// kasa_cikti.php — Kasa SALT OKUNUR çıktı ucu (YALNIZ GET; yazma YOK).
//   ?kasa=ID [&bas=&bit=] [&otomatik=1]          → A4 dikey kasa defteri (devir + yürüyen bakiye + kapanış)
//   ?kasa=ID [&bas=&bit=] &bicim=xlsx|csv        → defter dışa aktarımı (reports.export + export_audit('kasa','defter',…))
// Kapı: require_login → is_admin → kasa_kapi('read') → kasa_no_store → kasa_sayfa_kapisi; kasa aktif depoda görünür olmalı (kasa_defter).
// CSV `;` + BOM, binlik ayraçsız. Para birimleri toplanmaz (defter tek kasa = tek para birimi).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/kasa_core.php';
require_once __DIR__ . '/config/kasa_ui.php';
require_once __DIR__ . '/config/xlsx_export.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
kasa_kapi('read');
kasa_no_store();
$pdo = db();
kasa_sayfa_kapisi($pdo);

$bicim = in_array((string)($_GET['bicim'] ?? ''), ['xlsx', 'csv'], true) ? (string)$_GET['bicim'] : 'yazdir';
$bas = kasa_tarih_coz($_GET['bas'] ?? '') ?? '';
$bit = kasa_tarih_coz($_GET['bit'] ?? '') ?? '';
$d = kasa_defter($pdo, max(0, (int)($_GET['kasa'] ?? 0)), $bas, $bit);
if ($d === null) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Kasa bulunamadı ya da aktif deponuzda görünmüyor.'; exit; }

if ($bicim !== 'yazdir') {
    require_perm('reports.export');
    export_audit('kasa', 'defter', $bicim, count($d['satirlar']), ['kasa' => (int)$d['kasa']['id'], 'bas' => $bas, 'bit' => $bit]);
    $pb = $d['pb'];
    $dosya = 'kasa_defteri_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$d['kasa']['kod']) . '_' . date('Y-m-d');
    $baslik = ['Tarih', 'Belge No', 'Tür', 'Cari / Açıklama', 'Evrak No', 'Para Birimi', 'Giriş', 'Çıkış', 'Bakiye'];
    $dizi = static function (array $r) use ($pb): array {
        [$ana, $alt] = kasa_satir_aciklama($r);
        return [(string)$r['tarih'], (string)$r['belge_no'], kasa_tur_bilgi((string)$r['tur'], true)['etiket'] ?? (string)$r['tur'], trim($ana . ($alt !== '' ? ' · ' . $alt : '')), (string)($r['evrak_no'] ?? ''), $pb,
            $r['yon'] === 'G' ? (int)$r['tutar_kurus'] / 100 : '', $r['yon'] === 'C' ? (int)$r['tutar_kurus'] / 100 : '', (int)$r['yurume'] / 100];
    };
    if ($bicim === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $dosya . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        $para = static fn(int $k): string => ($k < 0 ? '-' : '') . intdiv(abs($k), 100) . ',' . str_pad((string)(abs($k) % 100), 2, '0', STR_PAD_LEFT);
        fputcsv($o, $baslik, ';', '"', '\\');
        fputcsv($o, ['', 'DEVİR', '', '', '', $pb, '', '', $para($d['devir'])], ';', '"', '\\');
        foreach ($d['satirlar'] as $r) {
            $s = $dizi($r);
            $s[0] = kasa_tarih_tr($s[0]);
            foreach ([1, 2, 3, 4, 5] as $mi) $s[$mi] = kasa_csv_metin((string)$s[$mi]);   // metin hücreleri: formül enjeksiyonu koruması
            $s[6] = $r['yon'] === 'G' ? $para((int)$r['tutar_kurus']) : ''; $s[7] = $r['yon'] === 'C' ? $para((int)$r['tutar_kurus']) : ''; $s[8] = $para((int)$r['yurume']);
            fputcsv($o, $s, ';', '"', '\\');
        }
        fputcsv($o, ['', 'TOPLAM ' . $pb, '', '', '', $pb, $para($d['donem_giris']), $para($d['donem_cikis']), $para($d['kapanis'])], ';', '"', '\\');
        fclose($o);
        exit;
    }
    $tip = ['Tarih' => 'tarih', 'Giriş' => 'tutar', 'Çıkış' => 'tutar', 'Bakiye' => 'tutar'];
    $aciklama = $d['kasa']['ad'] . ' · ' . count($d['satirlar']) . ' hareket · İptal edilen hareketler dahil değildir';
    $csvUrl = 'kasa_cikti.php?' . http_build_query(array_merge($_GET, ['bicim' => 'csv']));
    xlsx_indir($dosya . '.xlsx', [
        ['ad' => 'Kasa Defteri', 'baslik' => 'Asya Fresh — Kasa Defteri: ' . $d['kasa']['ad'], 'aciklama' => $aciklama,
         'sutunlar' => array_map(static fn(string $b): array => ['baslik' => $b, 'tip' => $tip[$b] ?? 'metin'], $baslik), 'satirlar' => array_map($dizi, $d['satirlar'])],
        ['ad' => 'Özet', 'baslik' => 'Dönem özeti (' . $pb . ')', 'aciklama' => $aciklama,
         'sutunlar' => [['baslik' => 'Para Birimi'], ['baslik' => 'Devir', 'tip' => 'tutar'], ['baslik' => 'Dönem Girişi', 'tip' => 'tutar'], ['baslik' => 'Dönem Çıkışı', 'tip' => 'tutar'], ['baslik' => 'Kapanış', 'tip' => 'tutar']],
         'satirlar' => [[$pb, $d['devir'] / 100, $d['donem_giris'] / 100, $d['donem_cikis'] / 100, $d['kapanis'] / 100]]],
    ], $csvUrl);
}

// ═════════════════ Yazdırma görünümü ═════════════════
require_once __DIR__ . '/config/siparis_invoice.php';   // siparis_inv_ihracatci(): firma bilgisinin TEK kaynağı
render_print_page_start('Kasa Defteri ' . $d['kasa']['ad'], 'kasa', 'detail', 'portrait', ['kasa.css']);
echo '<div class="no-print kasa-yz-arac"><button type="button" onclick="window.print()">Yazdır</button> <button type="button" onclick="window.close()">Kapat</button></div>' . "\n";
echo kasa_defter_yazdir_html($d, siparis_inv_ihracatci());
render_print_page_end(($_GET['otomatik'] ?? '') === '1');
