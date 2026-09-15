<?php
// =========================================================
// scripts/pdks_takip_ui_smoke.php — Personel Takip Merkezi + Yazdırma (Faz 7)
// arayüz (render) testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla personel_takip.php + 5 yazdırma
// sayfasını GERÇEKTEN render eder (pdks_rapor_ui_smoke.php İLE AYNI desen).
//
//   php scripts/pdks_takip_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$ROOT = dirname(__DIR__);

$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['attendance.employees', 'attendance.cards', 'attendance.scan', 'attendance.foremen', 'attendance.worker_cards',
          'attendance.daily_scan', 'attendance.daily_reports', 'attendance.foreman_rates', 'attendance.entitlements',
          'attendance.foreman_accounts', 'attendance.foreman_payments', 'attendance.management_reports'];
$IS_ADMIN = false;
$AKTIF_DEPO = 'Depo A';
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
function audit_log_event(...$a): void {}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function set_flash($a, $b): void {}
function get_flash(): ?array { return null; }
function render_flash(): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function base_url(): string { return '/'; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header(string $t, bool $p = false): void { echo "<!doctype html><html><head><title>" . h($t) . "</title></head><body><main class=\"container\">"; }
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_cari.php';
require_once $ROOT . '/config/pdks_rapor.php';
require_once $ROOT . '/config/print_helpers.php';

function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1]; $govde = $m[2]; $parcalar = []; $buf = ''; $derinlik = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $derinlik++;
        if ($c === ')') $derinlik--;
        if ($c === ',' && $derinlik === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = []; $indeksler = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $mm[2]) . ")"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $mm[2]) . ")"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $indeksler];
}

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `display_name` VARCHAR(150) NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test Kullanıcı')");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i);
}
foreach (pdks_gunluk_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
foreach (pdks_hakedis_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
foreach (pdks_cari_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
pdks_gunluk_migrate(db());

// ── Test verisi: Ayşe (TRY, tam çıkış + eksik çıkış kartı) + Mehmet (EUR) ──
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
pdks_gunluk_tip_olustur('PAKETLEME', 'Paketleme', db());
$paketlemeId = (int)db()->query("SELECT id FROM worker_types WHERE code='PAKETLEME'")->fetchColumn();

$ayseId = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db())['id'];
$mehmetId = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Mehmet Çavuş'], 1, db())['id'];

$oAyse = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
$sidAyse = (int)$oAyse['session']['id'];
pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $sidAyse, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $sidAyse, 'CIKIS', 1, db());
pdks_gunluk_kart_olustur(['card_no' => 'K002', 'worker_type_id' => $paketlemeId, 'ham_uid' => '631799512', 'kaynak' => 'usb_decimal'], 1, db());
pdks_gunluk_oturum_kaydet('631799512', 'usb_decimal', $sidAyse, 'GIRIS', 1, db());   // ÇIKIŞ YOK — eksik çıkış senaryosu
pdks_gunluk_oturum_kapat($sidAyse, 'Test kapanışı — eksik çıkış onaylandı.', 1, db());
pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2020-01-01', 'TRY', 1, db());
pdks_hakedis_oran_ekle($ayseId, $paketlemeId, '900', '2020-01-01', 'TRY', 1, db());
$finalAyse = pdks_hakedis_finalize($sidAyse, 1, true, db());   // eksik çıkış onayıyla kesinleştir
$ayseEntId = (int)$finalAyse['entitlement_id'];
pdks_cari_odeme_ekle($ayseId, date('Y-m-d'), '500', 'TRY', 'BANK', 'HAVALE-001', 'kısmi ödeme', 1, db());
$odemeIptal = pdks_cari_odeme_ekle($ayseId, date('Y-m-d'), '200', 'TRY', 'CASH', null, 'yanlış girildi', 1, db());
pdks_cari_odeme_iptal((int)$odemeIptal['id'], 'mükerrer kayıt', 1, db());

$oMehmet = pdks_gunluk_oturum_ac_veya_getir($mehmetId, 1, db());
$sidMehmet = (int)$oMehmet['session']['id'];
pdks_gunluk_kart_olustur(['card_no' => 'K010', 'worker_type_id' => $erkekId, 'ham_uid' => '631799520', 'kaynak' => 'usb_decimal'], 1, db());
pdks_gunluk_oturum_kaydet('631799520', 'usb_decimal', $sidMehmet, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('631799520', 'usb_decimal', $sidMehmet, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($sidMehmet, null, 1, db());
pdks_hakedis_oran_ekle($mehmetId, $erkekId, '40', '2020-01-01', 'EUR', 1, db());
pdks_hakedis_finalize($sidMehmet, 1, false, db());
pdks_cari_odeme_ekle($mehmetId, date('Y-m-d'), '15', 'EUR', 'BANK', null, null, 1, db());

function renderPage(string $file, array $get = [], array $post = []): string {
    global $ROOT;
    $_GET = $get; $_POST = $post; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/pdks_hakedis|config\/pdks_cari|config\/pdks_rapor|config\/print_helpers|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdkstakipui_' . md5($file . serialize($get) . serialize($post)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-84s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

echo "\n=== 3/4/8. personel_takip.php — admin: TÜM kartlar görünür ===\n";
$IS_ADMIN = true;
$s0 = renderPage('personel_takip.php');
ok('hata sızmadı', !str_starts_with($s0, '__ERROR__'), $s0);
ok('PHP Warning/Notice yok', !str_contains($s0, 'Warning:') && !str_contains($s0, 'Notice:'));
foreach (['Personeller', 'Personel Kartları', 'Personel Giriş / Çıkış', 'Çavuşlar', 'İşçi Kartları', 'İşçi Tipleri',
          'Günlük İşçi Giriş / Çıkış', 'Günlük Puantaj', 'Çavuş Fiyatları', 'Hakedişler', 'Çavuş Cari Hesapları',
          'Ekstre', 'Çavuş Ödemeleri', 'Personel / Günlük İşçi Raporları'] as $kartAdi) {
    ok("admin: '$kartAdi' kartı görünüyor", str_contains($s0, $kartAdi));
}
$IS_ADMIN = false;

echo "\n=== 5. personel_takip.php — operator (yalnız attendance.daily_scan): tarama VAR, finansal YOK ===\n";
$PERMS = ['attendance.daily_scan'];
$s1 = renderPage('personel_takip.php');
ok('hata sızmadı', !str_starts_with($s1, '__ERROR__'), $s1);
ok('operator: "Günlük İşçi Giriş / Çıkış" kartı GÖRÜNÜYOR', str_contains($s1, 'Günlük İşçi Giriş / Çıkış'));
foreach (['Çavuş Fiyatları', 'Hakedişler', 'Çavuş Cari Hesapları', 'Ekstre', 'Çavuş Ödemeleri',
          'Personel / Günlük İşçi Raporları', 'Çavuşlar', 'İşçi Kartları'] as $kartAdi) {
    ok("operator: '$kartAdi' kartı GÖRÜNMÜYOR (finansal/yönetim yetkisi yok)", !str_contains($s1, $kartAdi));
}

echo "\n=== 6. personel_takip.php — muhasebe (rates+entitlements+accounts+payments+reports): finansal kartlar VAR ===\n";
$PERMS = ['attendance.foreman_rates', 'attendance.entitlements', 'attendance.foreman_accounts', 'attendance.foreman_payments', 'attendance.management_reports'];
$s2 = renderPage('personel_takip.php');
ok('hata sızmadı', !str_starts_with($s2, '__ERROR__'), $s2);
foreach (['Çavuş Fiyatları', 'Hakedişler', 'Çavuş Cari Hesapları', 'Ekstre', 'Çavuş Ödemeleri', 'Personel / Günlük İşçi Raporları'] as $kartAdi) {
    ok("muhasebe: '$kartAdi' kartı görünüyor", str_contains($s2, $kartAdi));
}
ok('muhasebe: kalıcı personel kartları (attendance.employees yok) GÖRÜNMÜYOR', !str_contains($s2, 'Personeller'));

echo "\n=== 7. personel_takip.php — 'ik' (foremen+worker_cards+daily_scan+daily_reports+entitlements+management_reports, foreman_accounts/rates/payments YOK) ===\n";
$PERMS = ['attendance.foremen', 'attendance.worker_cards', 'attendance.daily_scan', 'attendance.daily_reports', 'attendance.entitlements', 'attendance.management_reports'];
$s3 = renderPage('personel_takip.php');
ok('hata sızmadı', !str_starts_with($s3, '__ERROR__'), $s3);
foreach (['Çavuşlar', 'İşçi Kartları', 'İşçi Tipleri', 'Günlük İşçi Giriş / Çıkış', 'Günlük Puantaj', 'Hakedişler', 'Personel / Günlük İşçi Raporları'] as $kartAdi) {
    ok("ik: '$kartAdi' kartı görünüyor", str_contains($s3, $kartAdi));
}
foreach (['Çavuş Fiyatları', 'Çavuş Cari Hesapları', 'Ekstre', 'Çavuş Ödemeleri'] as $kartAdi) {
    ok("ik: '$kartAdi' kartı GÖRÜNMÜYOR (foreman_rates/accounts/payments yok — mevcut izin matrisiyle TUTARLI)", !str_contains($s3, $kartAdi));
}

echo "\n=== hiçbir personel/attendance izni OLMAYAN kullanıcı REDDEDİLİYOR ===\n";
$PERMS = ['dashboard.read'];
$s4 = renderPage('personel_takip.php');
ok('yetkisiz kullanıcı forbidden() ile REDDEDİLDİ', str_starts_with($s4, '__ERROR__: forbidden'), $s4);
$PERMS = ['attendance.employees', 'attendance.cards', 'attendance.scan', 'attendance.foremen', 'attendance.worker_cards',
          'attendance.daily_scan', 'attendance.daily_reports', 'attendance.foreman_rates', 'attendance.entitlements',
          'attendance.foreman_accounts', 'attendance.foreman_payments', 'attendance.management_reports'];   // sıfırla

echo "\n=== 10. HEDEF SAYFALAR KENDİ YETKİ KONTROLÜNÜ KORUYOR (personel_takip.php bypass DEĞİL) ===\n";
$PERMS = ['attendance.daily_scan'];   // yalnız tarama — çavuş YÖNETİMİ yok
$rCavuslar = renderPage('cavuslar.php');
ok('cavuslar.php: yalnız daily_scan izniyle HÂLÂ REDDEDİLİYOR (attendance.foremen gerekir)', str_starts_with($rCavuslar, '__ERROR__: forbidden'), $rCavuslar);
$PERMS = ['attendance.employees', 'attendance.cards', 'attendance.scan', 'attendance.foremen', 'attendance.worker_cards',
          'attendance.daily_scan', 'attendance.daily_reports', 'attendance.foreman_rates', 'attendance.entitlements',
          'attendance.foreman_accounts', 'attendance.foreman_payments', 'attendance.management_reports'];   // sıfırla

// ═══════════════════════════════════════════════════════════
echo "\n=== 23/24/25/26/27. gunluk_puantaj_yazdir.php — Çavuş Gün Sonu Fişi ===\n";
$sPuantaj = renderPage('gunluk_puantaj_yazdir.php', ['id' => (string)$sidAyse]);
ok('hata sızmadı', !str_starts_with($sPuantaj, '__ERROR__'), $sPuantaj);
ok('PHP Warning/Notice yok', !str_contains($sPuantaj, 'Warning:') && !str_contains($sPuantaj, 'Notice:'));
ok('23. başlık "GÜNLÜK İŞÇİ PUANTAJ FİŞİ"', str_contains($sPuantaj, 'GÜNLÜK İŞÇİ PUANTAJ FİŞİ'));
ok('23. çavuş adı/kodu ve depo görünüyor', str_contains($sPuantaj, 'Ayşe Çavuş') && str_contains($sPuantaj, 'C001') && str_contains($sPuantaj, 'Depo A'));
ok('24. dinamik işçi tipi (Paketleme) sütunu görünüyor — Kadın/Erkek HARDCODE edilmedi', str_contains($sPuantaj, 'Paketleme'));
ok('25. eksik çıkışlı kart (K002) "⚠️ Eksik Çıkış" ile İŞARETLİ', (bool)preg_match('/K002.*?⚠️ Eksik Çıkış/s', $sPuantaj), $sPuantaj);
ok('25. tam çıkışlı kart (K001) "Tam" gösteriyor', (bool)preg_match('/K001.*?Tam(?!am)/s', $sPuantaj), $sPuantaj);
ok('26. UYDURULMUŞ bir çıkış saati YOK — K002 satırında çıkış hücresi BOŞ (saat basılmadı)', !preg_match('/K002<\/td>\s*<td>Paketleme<\/td>\s*<td>\d{2}:\d{2}<\/td>\s*<td>\d{2}:\d{2}<\/td>/', $sPuantaj));
ok('27. kanonik NFC UID (631799511/631799512) HİÇBİR YERDE BASILMADI', !str_contains($sPuantaj, '631799511') && !str_contains($sPuantaj, '631799512'));
ok('35. imza alanları var (Çavuş + Kontrol Eden)', str_contains($sPuantaj, 'Çavuş') && str_contains($sPuantaj, 'Kontrol Eden'));
ok('36. "Yazdır" butonu no-print İÇİNDE (ekranda görünür ama kağıda BASILMAZ)', (bool)preg_match('/class="[^"]*no-print[^"]*"[^>]*>\s*<button[^>]*onclick="window\.print\(\)"/s', $sPuantaj));

echo "\n=== 28/29. cavus_hakedis_yazdir.php — Hakediş Dökümü ===\n";
$sHakedis = renderPage('cavus_hakedis_yazdir.php', ['id' => (string)$ayseEntId]);
ok('hata sızmadı', !str_starts_with($sHakedis, '__ERROR__'), $sHakedis);
ok('PHP Warning/Notice yok', !str_contains($sHakedis, 'Warning:') && !str_contains($sHakedis, 'Notice:'));
ok('başlık "ÇAVUŞ HAKEDİŞ DÖKÜMÜ"', str_contains($sHakedis, 'ÇAVUŞ HAKEDİŞ DÖKÜMÜ'));
ok('28. Kadın 1×1.200,00 = 1.200,00 satırı var', str_contains($sHakedis, 'Kadın') && str_contains($sHakedis, '1.200,00'));
ok('28. Paketleme 1×900,00 = 900,00 satırı var', str_contains($sHakedis, 'Paketleme') && str_contains($sHakedis, '900,00'));
ok('28. GENEL TOPLAM 2.100,00 TRY görünüyor', (bool)preg_match('/GENEL TOPLAM.*?2\.100,00 TRY/s', $sHakedis), $sHakedis);
ok('29. KESİNLEŞMİŞ HAKEDİŞ durumu doğru gösteriliyor', str_contains($sHakedis, 'KESİNLEŞMİŞ HAKEDİŞ'));
ok('29. eksik çıkış onayı NOTU görünüyor (bu hakediş eksik-çıkışlı bir mesaiden AÇIKÇA onaylanarak kesinleşti)', str_contains($sHakedis, 'eksik çıkışla kapatılmıştır') && str_contains($sHakedis, 'AÇIKÇA onaylanarak'));
ok('imza alanları (Hazırlayan/Çavuş/Onay) var', str_contains($sHakedis, 'Hazırlayan') && str_contains($sHakedis, 'Onay'));

echo "\n=== 30/34. cavus_ekstre_yazdir.php — Cari Ekstre (koşan bakiye + çoklu para birimi) ===\n";
$sEkstre = renderPage('cavus_ekstre_yazdir.php', ['foreman_id' => (string)$ayseId]);
ok('hata sızmadı', !str_starts_with($sEkstre, '__ERROR__'), $sEkstre);
ok('PHP Warning/Notice yok', !str_contains($sEkstre, 'Warning:') && !str_contains($sEkstre, 'Notice:'));
ok('başlık "ÇAVUŞ CARİ HESAP EKSTRESİ"', str_contains($sEkstre, 'ÇAVUŞ CARİ HESAP EKSTRESİ'));
// Ayşe'nin TEK hakedişi Kadın(1200)+Paketleme(900)=2100.00 TOPLAMDIR (ekstre
// entitlement başına TEK satır gösterir, satır kalemi başına DEĞİL) —
// +2100 hakediş, ardından -500 geçerli ödeme (200'lük iptal HARİÇ) → KALAN 1.600,00.
ok('30. koşan BAKİYE sütunu dolduruluyor (2.100,00 hakediş satırından sonra, 1.600,00 nihai KALAN BAKİYE)', str_contains($sEkstre, '2.100,00') && str_contains($sEkstre, '1.600,00'));
ok('30. KALAN BAKİYE satırı "Çavuşa Borcumuz" etiketiyle görünüyor', str_contains($sEkstre, 'KALAN BAKİYE') && str_contains($sEkstre, 'Çavuşa Borcumuz'));
ok('iptal edilen ödeme (200) ekstrede GÖRÜNMÜYOR (yalnız GEÇERLİ hareketler)', !str_contains($sEkstre, '200,00'));

$sEkstreMehmet = renderPage('cavus_ekstre_yazdir.php', ['foreman_id' => (string)$mehmetId]);
ok('34. Mehmet EUR ekstresinde TRY hiç YOK — para birimleri KARIŞTIRILMADI', str_contains($sEkstreMehmet, 'EUR Hareketleri') && !str_contains($sEkstreMehmet, 'TRY Hareketleri'));

echo "\n=== 31/32. cavus_odeme_yazdir.php — Ödeme Dökümü (iptal HARİÇ tutuluyor) ===\n";
$sOdeme = renderPage('cavus_odeme_yazdir.php', ['cavus' => (string)$ayseId]);
ok('hata sızmadı', !str_starts_with($sOdeme, '__ERROR__'), $sOdeme);
ok('PHP Warning/Notice yok', !str_contains($sOdeme, 'Warning:') && !str_contains($sOdeme, 'Notice:'));
ok('başlık "ÇAVUŞ ÖDEME DÖKÜMÜ"', str_contains($sOdeme, 'ÇAVUŞ ÖDEME DÖKÜMÜ'));
ok('31. Geçerli ödeme toplamı 500,00 (200\'lük İPTAL satırı toplama DAHİL EDİLMEDİ)', (bool)preg_match('/Toplam Geçerli Ödeme.*?TRY.*?500,00/s', $sOdeme), $sOdeme);
ok('32. iptal edilen ödeme (200,00) YİNE DE geçmişte GÖRÜNÜYOR — "İptal" etiketiyle', str_contains($sOdeme, '200,00') && str_contains($sOdeme, 'İptal'));
ok('32. iptal gerekçesi görünüyor', str_contains($sOdeme, 'mükerrer kayıt'));
ok('geçerli ödeme "Geçerli" etiketiyle', str_contains($sOdeme, 'Geçerli'));
ok('ödeme yöntemi Türkçe ("Banka / Havale") — ham "BANK" DEĞİL', str_contains($sOdeme, 'Banka / Havale') && !preg_match('/<td>BANK<\/td>/', $sOdeme));

echo "\n=== 33. rapor_yazdir.php — finansal izin FARKINDALIĞI ===\n";
$sRaporFin = renderPage('rapor_yazdir.php', ['donem' => 'bu_ay']);
ok('hata sızmadı (finansal izinle)', !str_starts_with($sRaporFin, '__ERROR__'), $sRaporFin);
ok('finansal bölüm görünüyor (attendance.foreman_accounts VAR)', str_contains($sRaporFin, 'Finansal Özet'));

$PERMS = ['attendance.management_reports'];
$sRaporIk = renderPage('rapor_yazdir.php', ['donem' => 'bu_ay']);
ok('hata sızmadı (yalnız management_reports ile)', !str_starts_with($sRaporIk, '__ERROR__'), $sRaporIk);
ok('33. finansal bölüm YOK (attendance.foreman_accounts YOK — ?print=1 tarzı bir URL değişikliği yetkiyi ATLAYAMADI)', !str_contains($sRaporIk, 'Finansal Özet'));
ok('operasyonel bölüm HÂLÂ görünüyor', str_contains($sRaporIk, 'Operasyonel Özet'));
$PERMS = ['attendance.employees', 'attendance.cards', 'attendance.scan', 'attendance.foremen', 'attendance.worker_cards',
          'attendance.daily_scan', 'attendance.daily_reports', 'attendance.foreman_rates', 'attendance.entitlements',
          'attendance.foreman_accounts', 'attendance.foreman_payments', 'attendance.management_reports'];   // sıfırla

$PERMS = ['attendance.daily_scan'];
$rRaporYazdirYok = renderPage('rapor_yazdir.php', ['donem' => 'bu_ay']);
ok('operator izniyle rapor_yazdir.php SAYFANIN TAMAMI reddediliyor (attendance.management_reports gerekir)', str_starts_with($rRaporYazdirYok, '__ERROR__: forbidden'), $rRaporYazdirYok);
$PERMS = ['attendance.employees', 'attendance.cards', 'attendance.scan', 'attendance.foremen', 'attendance.worker_cards',
          'attendance.daily_scan', 'attendance.daily_reports', 'attendance.foreman_rates', 'attendance.entitlements',
          'attendance.foreman_accounts', 'attendance.foreman_payments', 'attendance.management_reports'];   // sıfırla

echo "\n=== 19/20/21. YAZDIRMA SAYFALARI UYGULAMA CHROME'UNU HİÇ BASMIYOR ===\n";
foreach (['gunluk_puantaj_yazdir.php' => ['id' => (string)$sidAyse], 'cavus_hakedis_yazdir.php' => ['id' => (string)$ayseEntId],
          'cavus_ekstre_yazdir.php' => ['foreman_id' => (string)$ayseId], 'cavus_odeme_yazdir.php' => ['cavus' => (string)$ayseId],
          'rapor_yazdir.php' => ['donem' => 'bu_ay']] as $dosya => $params) {
    $r = renderPage($dosya, $params);
    ok("$dosya: render_header()/sidebar/bottomnav HİÇ ÇAĞRILMADI (kendi minimal HTML iskeleti, chrome YOK)",
        !str_contains($r, 'class="container"') && !str_contains($r, 'desktop-sidebar') && !str_contains($r, 'bottomnav'));
    ok("$dosya: print_base.css YÜKLENİYOR", str_contains($r, 'print_base.css'));
}

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
