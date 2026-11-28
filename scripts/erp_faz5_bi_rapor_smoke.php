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
require_once $ROOT . '/config/edonusum_core.php';
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
edonusum_migrate($pdo);
bi_rapor_migrate($pdo);
$rBi2 = bi_rapor_migrate($pdo);

ok('BI Raporlama tabloları hazır ve 2. tur idempotent',
    bi_rapor_hazir($pdo) && !in_array('olusturuldu', array_column($rBi2, 'durum'), true)
);
ok('2 aktif ERP modülü BI veri kaynakları kataloğunda tanımlı (v609)', count(bi_veri_kaynaklari()) === 2);

// ── 2) Örnek ERP Verileri & Konsolide Yönetici Özeti ────────────────────────
echo "\n=== 2. Konsolide Yönetici Özeti (KPI) ===\n";
$pdo->exec("INSERT INTO muh_fisler (fis_no, tarih, tur, aciklama, durum, toplam_borc, toplam_alacak) VALUES ('YEV-BI-01', '2026-05-15', 'mahsup', 'EuroFresh İhracat Tahakkuk', 'onayli', 250000.00, 250000.00)");
$pdo->exec("INSERT INTO edonusum_zarflar (ettn, belge_no, belge_turu, tarih, alici_unvan, para_birimi, ara_toplam, kdv_toplam, genel_toplam, durum) VALUES ('11111111-1111-4111-8111-111111111111', 'EF-BI-01', 'e_fatura', '2026-05-16', '=HYPERLINK(\"http://evil\")', 'TRY', 100000.00, 20000.00, 120000.00, 'gonderildi')");

$kpis = bi_yonetici_ozeti($pdo, '2026-05-01', '2026-05-31');
ok('Yönetici özeti Genel Muhasebe (250.000) ve e-Dönüşüm (120.000) hesapladı',
    isset($kpis['muhasebe'], $kpis['edonusum'])
    && str_contains($kpis['muhasebe']['deger'], '250.000')
    && str_contains($kpis['edonusum']['deger'], '120.000'),
    json_encode($kpis, JSON_UNESCAPED_UNICODE)
);

// ── 3) Dinamik BI Şablon CRUD + Katı Allowlist + CSV Formula Koruması ───────
echo "\n=== 3. Dinamik BI Tasarımcısı, Sorgu Motoru & CSV Güvenliği ===\n";
$sb = bi_sablon_kaydet($pdo, [
    'kod'            => 'RPR-MUH-01',
    'ad'             => 'Aylık Yevmiye Raporu',
    'veri_kaynagi'   => 'genel_muhasebe',
    'sutunlar'       => ['fis_no', 'tarih', 'aciklama', 'toplam_borc', 'DROP TABLE users;--'], // geçersiz sütun reddedilmeli
    'siralama_sutun' => 'toplam_borc; DROP TABLE users', // geçersiz sıralama id'ye düşmeli
    'siralama_yon'   => 'DESC',
    'limit_satir'    => 100,
], 1);
ok('BI şablonu kaydedildi ve geçersiz sütun/sıralama allowlist ile temizlendi',
    !empty($sb['ok']) && (int)$sb['id'] > 0, json_encode($sb)
);

$sbRow = $pdo->query('SELECT * FROM bi_rapor_sablonlari WHERE id = ' . (int)$sb['id'])->fetch();
$cal = bi_sablon_calistir($pdo, $sbRow, ['bas_tarih' => '2026-05-01', 'bit_tarih' => '2026-05-31', 'ara' => 'EuroFresh']);
ok('Dinamik BI sorgu motoru doğru satırı ve sayısal toplamı (250000.00) üretti',
    !empty($cal['ok']) && $cal['kayit_sayisi'] === 1
    && !isset($cal['sutunlar']['DROP TABLE users;--'])
    && abs((float)($cal['toplamlar']['toplam_borc'] ?? 0) - 250000.0) < 0.01,
    json_encode($cal)
);

// B-33 CSV Formula Injection Koruması (=HYPERLINK başına tek tırnak koymalı)
$calEd = bi_sablon_calistir($pdo, ['veri_kaynagi' => 'edonusum', 'sutunlar' => ['alici_unvan', 'genel_toplam']]);
$csv = bi_csv_uret($calEd['sutunlar'], $calEd['satirlar']);
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
    'sec_kaynak' => 'genel_muhasebe',
    'sec_sablon' => (int)$sb['id'],
    'bas_tarih'  => '2026-05-01',
    'bit_tarih'  => '2026-05-31',
    'ara'        => '',
    'sonuc'      => $cal,
]);
$hTanim = bi_rapor_tanimlari_render([
    'sec_kaynak'   => 'genel_muhasebe',
    'sablonlar'    => bi_sablon_listesi($pdo),
    'zamanlamalar' => bi_zamanlama_listesi($pdo),
], true);

ok('Raporlama Merkezi & Dinamik BI Tasarımcısı sıfır PHP uyarısı ile çizildi',
    $warnings === [] && str_contains($hMerkez, 'Aylık Yevmiye Raporu') && str_contains($hTanim, 'yonetim@asya.scai.tr'),
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
