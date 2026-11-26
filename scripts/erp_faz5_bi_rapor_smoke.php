<?php
// =========================================================
// scripts/erp_faz5_bi_rapor_smoke.php — ERP Faz 5 (Yeni Raporlama & Dinamik BI Merkezi)
// Çalıştırma: php scripts/erp_faz5_bi_rapor_smoke.php
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/db_tx_helpers.php';
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('csrf_token')) {
    function csrf_token(): string { return 'test-csrf-token'; }
}
require_once $ROOT . '/config/muhasebe_core.php';
require_once $ROOT . '/config/wms_stok_core.php';
require_once $ROOT . '/config/yukleme_v2_core.php';
require_once $ROOT . '/config/onay_core.php';
require_once $ROOT . '/config/banka_core.php';
require_once $ROOT . '/config/ik_bordro_core.php';
require_once $ROOT . '/config/crm_core.php';
require_once $ROOT . '/config/sabit_kiymet_core.php';
require_once $ROOT . '/config/finans_plan_core.php';
require_once $ROOT . '/config/bi_rapor_core.php';
require_once $ROOT . '/config/bi_rapor_ui.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$warnings = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$warnings): bool {
    $warnings[] = "[$no] $str ($file:$line)";
    return false;
});

$pass = 0; $fail = 0;
function ok(string $ad, bool $kosul, string $ipucu = ''): void {
    global $pass, $fail;
    if ($kosul) $pass++; else $fail++;
    echo sprintf("%-74s %s%s\n", $ad, $kosul ? 'OK' : '*** FAIL', $kosul ? '' : '  ' . $ipucu);
}

// ── 1) 2× İdempotent Migrasyon ──────────────────────────────────────────────
echo "=== 1. Migrasyonlar (2× İdempotent) ===\n";
muhasebe_migrate($pdo);
yukleme_v2_migrate($pdo);
banka_migrate($pdo);
crm_migrate($pdo);
sabit_kiymet_migrate($pdo);
bi_rapor_migrate($pdo);
$rBi2 = bi_rapor_migrate($pdo);

ok('BI Raporlama tabloları hazır ve 2. tur idempotent',
    bi_rapor_hazir($pdo) && !in_array('olusturuldu', array_column($rBi2, 'durum'), true)
);
ok('12 ERP modülü BI veri kaynakları kataloğunda tanımlı', count(bi_veri_kaynaklari()) === 12);

// ── 2) Örnek ERP Verileri & Konsolide Yönetici Özeti ────────────────────────
echo "\n=== 2. Konsolide Yönetici Özeti (KPI) ===\n";
yukleme_v2_fis_kaydet($pdo, [
    'tarih'        => '2026-05-15',
    'depo'         => 'MERKEZ',
    'plaka'        => '33ABC123',
    'firma_unvan'  => 'EuroFresh GmbH',
    'gidecek_ulke' => 'Almanya',
    'paletler'     => [
        ['palet_no' => 'P1', 'urun_ad' => 'Kiraz', 'kasa_adeti' => 100, 'kasa_dara_kg' => 1.0, 'palet_dara_kg' => 22, 'brut_kg' => 1122],
    ],
], 1);
banka_hesap_kaydet($pdo, ['kod' => 'BNK-BI', 'banka_ad' => 'İş Bankası', 'acilis_bakiye' => 250000], 1);
crm_firsat_kaydet($pdo, ['musteri_ad' => '=HYPERLINK("http://evil")', 'baslik' => 'Test Fırsat', 'asama' => 'teklif', 'tahmini_tutar' => 100000, 'olasilik_yuzde' => 50], 1);
sk_kart_kaydet($pdo, ['kod' => 'SK-BI-1', 'ad' => 'Soğuk Hava Kompresörü', 'alis_tarih' => '2026-05-01', 'alis_bedeli' => 150000, 'faydali_omur_yil' => 5], 1);

$kpis = bi_yonetici_ozeti($pdo, '2026-05-01', '2026-05-31');
ok('Yönetici özeti Yükleme v2 (1000 kg), Banka (250.000), CRM (50.000), Sabit Kıymet (150.000) hesapladı',
    isset($kpis['yukleme_v2'], $kpis['banka'], $kpis['crm'], $kpis['sabit_kiymet'])
    && str_contains($kpis['yukleme_v2']['deger'], '1.000')
    && str_contains($kpis['banka']['deger'], '250.000')
    && str_contains($kpis['crm']['deger'], '50.000')
    && str_contains($kpis['sabit_kiymet']['deger'], '150.000'),
    json_encode($kpis, JSON_UNESCAPED_UNICODE)
);

// ── 3) Dinamik BI Şablon CRUD + Katı Allowlist + CSV Formula Koruması ───────
echo "\n=== 3. Dinamik BI Tasarımcısı, Sorgu Motoru & CSV Güvenliği ===\n";
$sb = bi_sablon_kaydet($pdo, [
    'kod'            => 'RPR-SEVK-01',
    'ad'             => 'İhracat Yükleme Raporu',
    'veri_kaynagi'   => 'yukleme_v2',
    'sutunlar'       => ['fis_no', 'tarih', 'firma_unvan', 'toplam_net_kg', 'DROP TABLE users;--'], // geçersiz sütun reddedilmeli
    'siralama_sutun' => 'toplam_net_kg; DROP TABLE users', // geçersiz sıralama id'ye düşmeli
    'siralama_yon'   => 'DESC',
    'limit_satir'    => 100,
], 1);
ok('BI şablonu kaydedildi ve geçersiz sütun/sıralama allowlist ile temizlendi',
    !empty($sb['ok']) && (int)$sb['id'] > 0, json_encode($sb)
);

$sbRow = $pdo->query('SELECT * FROM bi_rapor_sablonlari WHERE id = ' . (int)$sb['id'])->fetch();
$cal = bi_sablon_calistir($pdo, $sbRow, ['bas_tarih' => '2026-05-01', 'bit_tarih' => '2026-05-31', 'ara' => 'EuroFresh']);
ok('Dinamik BI sorgu motoru doğru satırı ve sayısal toplamı (1000.00 kg) üretti',
    !empty($cal['ok']) && $cal['kayit_sayisi'] === 1
    && !isset($cal['sutunlar']['DROP TABLE users;--'])
    && abs((float)($cal['toplamlar']['toplam_net_kg'] ?? 0) - 1000.0) < 0.01,
    json_encode($cal)
);

// B-33 CSV Formula Injection Koruması (=HYPERLINK başına tek tırnak koymalı)
$calCrm = bi_sablon_calistir($pdo, ['veri_kaynagi' => 'crm_firsatlar', 'sutunlar' => ['musteri_ad', 'tahmini_tutar']]);
$csv = bi_csv_uret($calCrm['sutunlar'], $calCrm['satirlar']);
ok('CSV dışa aktarma UTF-8 BOM taşır ve =HYPERLINK hücrelerini tek tırnakla etkisizleştirir (B-33)',
    str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "\"'=HYPERLINK"),
    $csv
);

// Zamanlanmış rapor dağıtımı + e-posta doğrulama
$zBad = bi_zamanlama_kaydet($pdo, ['sablon_id' => (int)$sb['id'], 'alici_eposta' => 'gecersiz-posta'], 1);
$zOk  = bi_zamanlama_kaydet($pdo, ['sablon_id' => (int)$sb['id'], 'periyot' => 'haftalik', 'alici_eposta' => 'yonetim@asya.scai.tr', 'format' => 'csv'], 1);
ok('Zamanlanmış rapor geçersiz e-postayı 422 reddeder, geçerli kaydı kaydeder',
    empty($zBad['ok']) && (int)($zBad['kod'] ?? 0) === 422 && !empty($zOk['ok'])
);

// ── 4) UI Render & Statik Güvenlik ──────────────────────────────────────────
echo "\n=== 4. UI Render & Statik Güvenlik ===\n";
$hMerkez = bi_rapor_merkezi_render([
    'kpis'       => $kpis,
    'sablonlar'  => bi_sablon_listesi($pdo),
    'sec_kaynak' => 'yukleme_v2',
    'sec_sablon' => (int)$sb['id'],
    'bas_tarih'  => '2026-05-01',
    'bit_tarih'  => '2026-05-31',
    'ara'        => '',
    'sonuc'      => $cal,
]);
$hTanim = bi_rapor_tanimlari_render([
    'sec_kaynak'   => 'yukleme_v2',
    'sablonlar'    => bi_sablon_listesi($pdo),
    'zamanlamalar' => bi_zamanlama_listesi($pdo),
], true);

ok('Raporlama Merkezi & Dinamik BI Tasarımcısı sıfır PHP uyarısı ile çizildi',
    $warnings === [] && str_contains($hMerkez, 'İhracat Yükleme Raporu') && str_contains($hTanim, 'yonetim@asya.scai.tr'),
    implode('; ', $warnings)
);

// Şablon silme (bağlı zamanlamayla birlikte)
$sil = bi_sablon_sil($pdo, (int)$sb['id'], 1);
ok('BI şablonu ve bağlı zamanlamaları temiz silinir',
    !empty($sil['ok']) && count(bi_sablon_listesi($pdo)) === 0 && count(bi_zamanlama_listesi($pdo)) === 0
);

$uiSrc = (string)file_get_contents($ROOT . '/config/bi_rapor_ui.php');
ok('config/bi_rapor_ui.php SIFIR SQL ve SCRIPT_FILENAME guard taşır',
    str_contains($uiSrc, 'SCRIPT_FILENAME') && !preg_match('/->(prepare|query|exec)\s*\(/', $uiSrc)
);
$jsSrc = (string)file_get_contents($ROOT . '/assets/bi_rapor.js');
ok('assets/bi_rapor.js localStorage ve innerHTML içermez',
    !str_contains($jsSrc, 'localStorage') && !str_contains($jsSrc, 'innerHTML')
);
$cssSrc = (string)file_get_contents($ROOT . '/assets/bi_rapor.css');
ok('assets/bi_rapor.css dark-mode, mobil <768px 16px ve print kuralları içerir',
    str_contains($cssSrc, 'html[data-theme="dark"]') && str_contains($cssSrc, 'font-size: 16px') && str_contains($cssSrc, '@media print')
);
$postSrc = (string)file_get_contents($ROOT . '/bi_rapor_kaydet.php');
ok('bi_rapor_kaydet.php POST-only (405), require_login, csrf_check ve bi_kapi kullanır',
    str_contains($postSrc, 'http_response_code(405)') && str_contains($postSrc, 'require_login()') && str_contains($postSrc, 'csrf_check(') && str_contains($postSrc, 'bi_kapi(')
);
$swSrc = (string)file_get_contents($ROOT . '/sw.js');
$swVer = preg_match("/CACHE_NAME\s*=\s*'yukleme-plani-v(\d+)'/", $swSrc, $swm) ? (int)$swm[1] : 0;
ok('sw.js ERP Faz 5 BI uçlarını önbellek dışı tutar ve v593+',
    $swVer >= 593 && str_contains($swSrc, 'rapor_merkezi|bi_rapor_')
);

echo "\nÖZET: $pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
