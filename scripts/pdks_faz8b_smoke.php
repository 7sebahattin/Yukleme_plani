<?php
// Faz 8B smoke: saf süre politikası + SQLite additive migrasyon.
declare(strict_types=1);

require_once __DIR__ . '/../config/pdks_faz8b.php';

$gecen = 0;
$hata = 0;
function ok8b(string $ad, bool $kosul, string $detay = ''): void {
    global $gecen, $hata;
    if ($kosul) { $gecen++; echo "OK  - {$ad}\n"; }
    else { $hata++; echo "HATA- {$ad}" . ($detay !== '' ? " :: {$detay}" : '') . "\n"; }
}

function karar8b(string $g, string $c): array {
    return pdks_faz8b_sure_karari("2026-09-16 {$g}:00", "2026-09-16 {$c}:00");
}

echo "=== Faz 8B süre/tolerans ===\n";
$k = karar8b('08:00', '17:00');
ok8b('08:00-17:00 otomatik Tam', $k['otomatik_sinif'] === 'tam');
ok8b('08:00-17:00 FM yok', $k['fazla_mesai_saat'] === 0);

$k = karar8b('08:15', '17:00');
ok8b('08:15 giriş 15 dk toleransla Tam', $k['otomatik_sinif'] === 'tam');

$k = karar8b('08:15', '16:45');
ok8b('iki uçta 15 dk tolerans (08:15-16:45) Tam', $k['otomatik_sinif'] === 'tam');

$k = karar8b('08:16', '16:44');
ok8b('tolerans dışı kısa gün muhasebe kararı bekler', $k['otomatik_sinif'] === null && $k['sinif_onayi_gerekli'] === true);

$k = karar8b('08:00', '17:15');
ok8b('17:15 çıkış FM toleransında, FM yok', $k['fazla_mesai_saat'] === 0);

$k = karar8b('08:00', '17:16');
ok8b('17:16 çıkış 1 saat FM adayı', $k['fazla_mesai_saat'] === 1);

$k = karar8b('08:00', '18:15');
ok8b('1s15dk plan sonrası = 1 saat FM', $k['fazla_mesai_saat'] === 1);

$k = karar8b('08:00', '18:16');
ok8b('1s16dk plan sonrası = 2 saat FM', $k['fazla_mesai_saat'] === 2);

$k = karar8b('08:15', '17:30');
ok8b('08:15-17:30: Tam + 1 saat FM adayı', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 1);

$k = pdks_faz8b_sure_karari('2026-09-16 08:00:00', null);
ok8b('çıkış yoksa otomatik ücret sınıfı verilmez', $k['otomatik_sinif'] === null && $k['sinif_onayi_gerekli'] === true);

echo "\n=== Faz 8B SQLite migrasyon ===\n";
try {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, daily_rate DECIMAL(12,2) NOT NULL)");
    $db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, approved_attendance_class VARCHAR(10) NULL)");
    $db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, total_amount DECIMAL(14,2) NOT NULL DEFAULT 0)");
    $db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INT NOT NULL, worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '', unit_rate DECIMAL(12,2) NOT NULL)");

    $r1 = pdks_faz8b_migrate($db);
    ok8b('ilk migrasyon hata üretmedi', count(array_filter($r1, fn($r) => $r['durum'] === 'hata')) === 0, json_encode($r1, JSON_UNESCAPED_UNICODE));
    ok8b('ilk migrasyon sonrası şema hazır', pdks_faz8b_sema_hazir($db));

    $r2 = pdks_faz8b_migrate($db);
    ok8b('ikinci migrasyon idempotent', count(array_filter($r2, fn($r) => $r['durum'] !== 'var')) === 0, json_encode($r2, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    ok8b('SQLite migrasyon exception yok', false, $e->getMessage());
}

echo "\n=== Faz 8B statik entegrasyon ===\n";
$kok = dirname(__DIR__);
$fiyat = (string)@file_get_contents($kok . '/cavus_fiyatlari.php');
$liste = (string)@file_get_contents($kok . '/cavus_hakedis.php');
$detay = (string)@file_get_contents($kok . '/cavus_hakedis_detay.php');
$deger = (string)@file_get_contents($kok . '/mesai_degerlendirme.php');

ok8b('fiyat ekranı Tam/Yarım/FM alanlarını içeriyor',
    str_contains($fiyat, 'half_day_rate') && str_contains($fiyat, 'overtime_mode') && str_contains($fiyat, 'overtime_rate'));
ok8b('hakediş listesi Faz 8B hesap motoruna bağlı', str_contains($liste, 'pdks_faz8b_hakedis_hesapla'));
ok8b('hakediş detay Faz 8B finalize motoruna bağlı', str_contains($detay, 'pdks_faz8b_hakedis_finalize'));
ok8b('muhasebe değerlendirme sayfası finalize yetkisi istiyor', str_contains($deger, "require_pdks_hakedis('entitlements_finalize')"));
ok8b('muhasebe ekranında FM onay/red seçimi var', str_contains($deger, 'onayla') && str_contains($deger, 'reddet'));

echo "\nSONUÇ: {$gecen} geçti, {$hata} hata\n";
exit($hata === 0 ? 0 : 1);
