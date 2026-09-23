<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/xlsx_export.php';
$auth_user = require_login();
require_hesap('read');
require_perm('reports.export');
hesap_migrate();

$q       = trim($_GET['q'] ?? '');
$type_f  = trim($_GET['type'] ?? '');
$tarih_b = trim($_GET['tarih_bas'] ?? '');
$tarih_s = trim($_GET['tarih_son'] ?? '');
$muh_f   = trim($_GET['muh'] ?? '');
$durum_f = trim($_GET['durum'] ?? '');
// Biçim: xlsx (varsayılan — eski yer imleri de gerçek Excel alır) · csv
// NOT: Bu uç nokta eskiden HTML tablosunu .xls uzantısıyla gönderiyordu;
// Excel her açılışta "biçim ile uzantı eşleşmiyor" uyarısı veriyor, tutarlar
// metin olarak geliyordu. Artık gerçek XLSX üretilir.
$bicim   = ($_GET['bicim'] ?? '') === 'csv' ? 'csv' : 'xlsx';

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(category LIKE ? OR person_company LIKE ? OR description LIKE ?)";
    $params   = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
}
if ($type_f) { $where[] = "type=?"; $params[] = $type_f; }
if ($tarih_b) { $where[] = "transaction_date>=?"; $params[] = $tarih_b; }
if ($tarih_s) { $where[] = "transaction_date<=?"; $params[] = $tarih_s; }
if ($muh_f === '1') { $where[] = "is_given_to_accountant=1"; }
if ($muh_f === '0') { $where[] = "is_given_to_accountant=0"; }
if ($durum_f === 'bekleyen') {
    $ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));
    $where[] = "status IN ($ph)";
    $params  = array_merge($params, hesap_pending_statuses());
} elseif ($durum_f !== '' && hesap_status_valid($durum_f)) {
    $where[]  = "status=?";
    $params[] = $durum_f;
}
[$osql, $oparams] = hesap_owner_sql();
if ($osql !== '') { $where[] = $osql; $params = array_merge($params, $oparams); }
[$dsql, $dparams] = depo_sql_in('depo');
if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }
$wstr = implode(' AND ', $where);

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr ORDER BY transaction_date ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll();

// Audit — dışa aktarma (içerik loglanmaz, sadece filtre ve kayıt sayısı)
audit_log_event('export', 'hesap', null, null, [
    'format'    => $bicim,
    'row_count' => count($rows),
    'filters'   => array_filter([
        'q'         => $q,
        'type'      => $type_f,
        'tarih_bas' => $tarih_b,
        'tarih_son' => $tarih_s,
        'muh'       => $muh_f,
        'durum'     => $durum_f,
    ], fn($v) => $v !== ''),
]);

// Satırlar — CSV ve XLSX AYNI diziden
$satirlar = [];
$totals   = [];   // B2: toplamlar para birimi bazında — farklı kurlar birbirine EKLENMEZ
foreach ($rows as $i => $r) {
    $cur = $r['currency'] ?: 'TRY';
    if (!isset($totals[$cur])) $totals[$cur] = ['gelir' => 0.0, 'gider' => 0.0, 'adet' => 0];
    if ($r['type'] === 'gelir') $totals[$cur]['gelir'] += (float)$r['amount'];
    else                        $totals[$cur]['gider'] += (float)$r['amount'];
    $totals[$cur]['adet']++;
    $satirlar[] = [
        $i + 1, $r['transaction_date'], hesap_type_label($r['type']), $r['category'], $r['person_company'],
        $r['description'], $r['document_no'], (float)$r['amount'], $cur, hesap_payment_label((string)$r['payment_method']),
        $r['has_invoice'] ? 'Evet' : 'Hayır', $r['is_for_company'] ? 'Evet' : 'Hayır',
        hesap_status_label((string)($r['status'] ?? '')), $r['notes'],
    ];
}
$basliklar = ['#', 'Tarih', 'Tür', 'Kategori', 'Kişi/Firma', 'Açıklama', 'Belge No', 'Tutar', 'Döviz', 'Ödeme', 'Fatura', 'Şirket İçin', 'Durum', 'Not'];

if ($bicim === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="hesap_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $basliklar, ';', '"', '\\');
    foreach ($satirlar as $s) {
        $s[1] = $s[1] ? date('d.m.Y', strtotime((string)$s[1])) : '';
        $s[7] = number_format((float)$s[7], 2, ',', '');   // binlik ayraçsız — aktarımda güvenli
        fputcsv($out, $s, ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$aciklama = 'Tarih aralığı: ' . ($tarih_b ? fmt_date($tarih_b) . ' — ' . fmt_date($tarih_s ?: date('Y-m-d')) : 'Tümü')
          . ' · Toplam ' . count($rows) . ' kayıt';
$tip = ['#' => 'tamsayi', 'Tarih' => 'tarih', 'Tutar' => 'tutar'];
$ozet = [];
foreach ($totals as $cur => $t) {
    $ozet[] = [$cur, $t['adet'], $t['gelir'], $t['gider'], $t['gelir'] - $t['gider']];
}
xlsx_indir('hesap_' . date('Y-m-d') . '.xlsx', [
    // Tutar sütununda toplam YOK: gelir/gider ve farklı para birimleri aynı sütunda.
    // Toplamlar para birimi başına "Özet" sayfasında.
    ['ad' => 'Hesap Kayıtları', 'baslik' => 'Asya Fresh — Hesap Kayıtları', 'aciklama' => $aciklama,
     'sutunlar' => array_map(fn($b) => ['baslik' => $b, 'tip' => $tip[$b] ?? 'metin'], $basliklar),
     'satirlar' => $satirlar],
    ['ad' => 'Para Birimi Özeti', 'baslik' => 'Para Birimi Bazında Özet', 'aciklama' => $aciklama . ' · Kurlar birbirine eklenmez',
     'sutunlar' => [['baslik' => 'Döviz'], ['baslik' => 'Kayıt', 'tip' => 'tamsayi'], ['baslik' => 'Toplam Gelir', 'tip' => 'tutar'],
                    ['baslik' => 'Toplam Gider', 'tip' => 'tutar'], ['baslik' => 'Net Bakiye', 'tip' => 'tutar']],
     'satirlar' => $ozet],
], 'hesap_export.php?' . http_build_query(array_merge($_GET, ['bicim' => 'csv'])));
