<?php
// =========================================================
// hesap_yazdir.php — Hesap dönem raporu
//
// Varsayılan çıktı PDF'tir (dompdf). Hızlı tarayıcı yazdırması gerekirse
// ?goruntule=html ile aynı rapor HTML olarak açılır — dompdf kurulu değilse
// veya PDF üretimi hata verirse otomatik olarak bu görünüme düşülür.
//
// Filtreler: tarih_bas · tarih_son · type · durum
// Görünürlük (sahiplik + depo) hesap_report_data() içinde uygulanır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/hesap_pdf.php';
$auth_user = require_login();
require_hesap('read');
hesap_migrate();

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

$filters = [
    'tarih_bas' => trim($_GET['tarih_bas'] ?? date('Y-m-01')),
    'tarih_son' => trim($_GET['tarih_son'] ?? date('Y-m-t')),
    'type'      => trim($_GET['type'] ?? ''),
    'durum'     => trim($_GET['durum'] ?? ''),
];
// Tarihleri doğrula — geçersizse ay başı/sonu
foreach (['tarih_bas', 'tarih_son'] as $k) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$k])) {
        $filters[$k] = $k === 'tarih_bas' ? date('Y-m-01') : date('Y-m-t');
    }
}

$rapor = hesap_report_data($filters);

// Dışa aktarma audit'i — içerik loglanmaz, yalnız filtre ve kayıt sayısı
audit_log_event('export', 'hesap', null, null, [
    'format'    => ($_GET['goruntule'] ?? '') === 'html' ? 'html' : 'pdf',
    'row_count' => $rapor['meta']['adet'],
    'fis_count' => count($rapor['fisler']),
    'filters'   => array_filter($filters, fn($v) => $v !== ''),
]);

// ── HTML görünümü istendi ──
if (($_GET['goruntule'] ?? '') === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo hesap_report_html($rapor, false);
    exit;
}

// ── PDF ──
$pdf = null;
$hata = '';
try {
    $pdf = hesap_report_pdf($rapor);
} catch (Throwable $e) {
    error_log('[hesap_yazdir pdf] ' . $e->getMessage());
    $hata = $e->getMessage();
}

if ($pdf === null || $pdf === '') {
    // dompdf yok ya da üretim başarısız — rapor kaybolmasın, HTML'e düş
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="font:13px/1.5 system-ui;background:#fef2f2;border:1px solid #fca5a5;'
       . 'color:#991b1b;padding:10px 14px;margin:12px;border-radius:8px">'
       . '<strong>PDF üretilemedi — rapor HTML olarak gösteriliyor.</strong>'
       . ($hata !== '' ? '<br><span style="font-size:11px">' . h($hata) . '</span>' : '')
       . ' <button onclick="window.print()" style="margin-left:8px">Yazdır</button></div>';
    echo hesap_report_html($rapor, false);
    exit;
}

$dosya = hesap_report_filename($rapor['meta']);
// inline: tarayıcıda açılır; ?indir=1 ile dosya olarak iner
$disposition = isset($_GET['indir']) ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $dosya . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $pdf;
