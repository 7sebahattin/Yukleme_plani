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

// ⚠ Faz 9C / H-02: sabit 08:00-17:00 vardiya + saat-kilidi toleransı
// TAMAMEN kaldırıldı — pdks_faz8b_sure_karari() artık 3. parametre olarak
// bir normal süre (dk) alır ve YALNIZ geçen süreyi bununla karşılaştırır,
// hiçbir SAATE anchor olmaz. Bu bölüm o yüzden BAŞTAN, süre-tabanlı
// modele göre yazıldı — eski clock-tolerans senaryoları (08:15/16:45 vb.)
// artık iş kuralının PARÇASI DEĞİL.
function karar8b(string $g, string $c, int $normalDk = 540): array {
    return pdks_faz8b_sure_karari("2026-09-16 {$g}:00", "2026-09-16 {$c}:00", $normalDk);
}

echo "=== Faz 8B süre-tabanlı Tam/FM (Faz 9C) ===\n";
$k = karar8b('08:00', '17:00');
ok8b('08:00-17:00 @9h otomatik Tam', $k['otomatik_sinif'] === 'tam');
ok8b('08:00-17:00 @9h FM yok', $k['fazla_mesai_saat'] === 0);

$k = karar8b('09:00', '18:00');
ok8b('09:00-18:00 @9h (kaymış saat, AYNI süre) otomatik Tam', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 0);

$k = karar8b('10:00', '19:00');
ok8b('10:00-19:00 @9h (kaymış saat, AYNI süre) otomatik Tam', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 0);

$k = karar8b('08:00', '16:59');
ok8b('08:00-16:59 @9h (1 dk eksik) muhasebe kararı bekler', $k['otomatik_sinif'] === null && $k['sinif_onayi_gerekli'] === true);

$k = karar8b('09:00', '17:00', 480);
ok8b('09:00-17:00 @8h otomatik Tam', $k['otomatik_sinif'] === 'tam');

$k = karar8b('09:00', '19:00', 600);
ok8b('09:00-19:00 @10h otomatik Tam', $k['otomatik_sinif'] === 'tam');

$k = karar8b('08:00', '17:15');
ok8b('9h00 (aday tam süre) FM yok', $k['fazla_mesai_saat'] === 0 && $k['otomatik_sinif'] === 'tam');
$k = karar8b('08:00', '17:15');
ok8b('9h15 (15 dk tolerans sınırında) FM yok', $k['fazla_mesai_saat'] === 0);

$k = karar8b('08:00', '17:16');
ok8b('9h16 = 1 saat FM adayı', $k['fazla_mesai_saat'] === 1);

$k = karar8b('08:00', '18:15');
ok8b('10h15 = 1 saat FM', $k['fazla_mesai_saat'] === 1);

$k = karar8b('08:00', '18:16');
ok8b('10h16 = 2 saat FM', $k['fazla_mesai_saat'] === 2);

$k = karar8b('08:00', '19:15');
ok8b('11h15 = 2 saat FM', $k['fazla_mesai_saat'] === 2);

$k = karar8b('08:00', '19:16');
ok8b('11h16 = 3 saat FM', $k['fazla_mesai_saat'] === 3);

$k = karar8b('12:00', '21:00');
ok8b('12:00-21:00 @9h (kaymış saat) Tam + 0 FM', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 0);

$k = karar8b('12:00', '22:16');
ok8b('12:00-22:16 @9h Tam + 2 FM', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 2);

$k = pdks_faz8b_sure_karari('2026-09-16 23:00:00', '2026-09-17 08:00:00', 540);
ok8b('gün ötesi 23:00-08:00 @9h Tam + 0 FM (özel gün-ötesi kural YOK, yalnız fark)', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 0);

$k = karar8b('18:00', '20:00');
ok8b('ikinci/kısa dönem 18:00-20:00 @9h: yalnız SÜRE kısa olduğu için karar bekler, SAAT geç diye FM DEĞİL', $k['otomatik_sinif'] === null && $k['fazla_mesai_saat'] === 0);

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
    // Faz 9C / H-02: foremen.normal_work_minutes + daily_work_sessions.
    // normal_work_minutes_snapshot da BU migrasyonun (pdks_faz8b_migrate)
    // ALTER hedefleri — fixture'a eklendi.
    $db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(20) NOT NULL, name VARCHAR(150) NOT NULL, notes TEXT NULL)");
    $db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INT NOT NULL, foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '')");

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
// Faz 9C / UX-03: ikili onayla/reddet SEÇİMİ yerine, hesaplanan adayın
// altına inebilen sayısal "Onaylanan FM Saati" girdisi geldi.
ok8b('muhasebe ekranında Onaylanan FM Saati sayısal girdisi var', str_contains($deger, 'name="overtime_approved_hours"'));
ok8b('muhasebe ekranı eski onayla/reddet ikili seçimini İÇERMİYOR', !str_contains($deger, 'name="overtime_decision"'));

echo "\nSONUÇ: {$gecen} geçti, {$hata} hata\n";
exit($hata === 0 ? 0 : 1);
