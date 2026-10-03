<?php
// =========================================================
// scripts/pdks_puantaj_dialog_render.php — gunluk_isci_puantaj_detay.php'yi
// YÖNETİCİ olarak, GERÇEK CSS ile tek bir HTML dosyasına basar. Tek amacı
// tarayıcı testine (pdks_puantaj_dialog_smoke.js) girdi üretmektir; canlı
// DB'ye dokunmaz (bellek içi SQLite — pdks_gunluk_faz3_ui_smoke.php deseni).
//
//   php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

if (PHP_SAPI !== 'cli') { http_response_code(403); die('Yalnız CLI.'); }

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { return true; }
function is_admin(): bool { return true; }
function active_depot(): ?string { return 'Depo A'; }
function audit_log_event(...$a): void {}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function set_flash($a, $b): void {}
function get_flash(): ?array { return null; }
function render_flash(): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function base_url(): string { return ''; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header(string $t, bool $p = false): void {
    echo "<!doctype html><html lang=\"tr\"><head><meta charset=\"utf-8\">"
       . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<title>" . h($t) . "</title>"
       . "<link rel=\"stylesheet\" href=\"assets/style.css\">"
       . "</head><body><main class=\"container\">";
}
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_faz8h.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_hakedis.php';

// MySQL DDL → SQLite (pdks_gunluk_faz3_ui_smoke.php ile aynı çevirici)
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    [$tablo, $govde] = [$m[1], $m[2]];
    $parcalar = []; $buf = ''; $d = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $d++;
        if ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kol = []; $ix = [];
    $liste = fn(string $s) => preg_replace('/\s+/', ' ', trim(preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $s)));
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ix[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `$tablo` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ix[] = "CREATE INDEX `{$mm[1]}` ON `$tablo` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kol[] = $p;
    }
    return ["CREATE TABLE `$tablo` (\n  " . implode(",\n  ", $kol) . "\n)", $ix];
}

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `display_name` VARCHAR(150) NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test Kullanıcı')");
db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
foreach (pdks_gunluk_tablolar() as $sql) {
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
pdks_gunluk_migrate(db());
// Faz 8A tablosu migrate()'ten ÖNCE DDL çeviriciyle kurulur (ham MySQL
// CREATE SQLite'ta çalışmaz — pdks_gunluk_faz8a_ui_smoke.php ile aynı not).
[$c, $ix] = pdks_ddl_sqlite(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
db()->exec($c); foreach ($ix as $x) db()->exec($x);
pdks_gunluk_faz8a_migrate(db());
pdks_faz8j_migrate(db());

// Test verisi: tek mesai, birkaç kart (biri çıkışsız) — her satır için
// Düzenle + İptal <dialog>'u çizilir.
$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$cavus = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Test Çavuş'], 1, db())['id'];
$o = pdks_gunluk_oturum_ac_veya_getir($cavus, 1, db());
$sid = (int)$o['session']['id'];
foreach (['631799511', '111222333', '444555666', '777888999', '123123123', '456456456'] as $i => $uid) {
    pdks_gunluk_kart_olustur(['card_no' => 'K00' . ($i + 1), 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
    $r = pdks_gunluk_faz8a_giris_kaydet($uid, 'usb_decimal', $sid, $kadin, 'auto', 1, db());
    if (!($r['ok'] ?? false)) { fwrite(STDERR, 'giriş: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
}
pdks_gunluk_faz8a_cikis_kaydet('631799511', 'usb_decimal', $sid, 1, db());

// v291: ekle penceresinde seçilebilecek, o gün KULLANILMAMIŞ iki kart.
foreach (['321321321', '654654654'] as $i => $uid) {
    pdks_gunluk_kart_olustur(['card_no' => 'B00' . ($i + 1), 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
}
// v291: bir dönemi "elle eklendi" (source=manual) say — rozet testi için.
db()->exec("UPDATE daily_worker_work_periods SET source = 'manual' WHERE id = 3");

// v294: Toplu İşlem penceresi için o gün BOŞ 40 kart (yarısı KADIN, yarısı ERKEK — kaydırma gerekir).
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
for ($i = 1; $i <= 40; $i++) {
    $r = pdks_gunluk_kart_olustur(['card_no' => sprintf('F%03d', $i), 'ham_uid' => (string)(900000000 + $i), 'kaynak' => 'usb_decimal',
                                   'worker_type_id' => $i <= 20 ? $kadin : $erkek], 1, db());
    if (!($r['ok'] ?? false)) { fwrite(STDERR, 'kart F' . $i . ': ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
}
db()->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, status TEXT, needs_recalculation INTEGER DEFAULT 0, notes TEXT, updated_at TEXT, finalized_at TEXT, finalized_by_user_id INTEGER, total_amount TEXT)");
// v294: GERÇEK toplu işlem (kartlı + kartsız) — kartsız rozet, kilitli düzenleme ve "Toplu İşlemler"
// bölümü bu veriyle sınanır. Yazma yolu üretimdekiyle aynı (pdks_faz8j_toplu_ekle).
$b1 = (int)db()->query("SELECT id FROM worker_cards WHERE card_no='B001'")->fetchColumn();
$b2 = (int)db()->query("SELECT id FROM worker_cards WHERE card_no='B002'")->fetchColumn();
$tp = pdks_faz8j_toplu_ekle(['foreman_id' => $cavus, 'work_date' => date('Y-m-d'), 'depo' => 'Depo A', 'reason' => 'Test toplu', 'note' => 'Render kurulumu', 'istek_id' => bin2hex(random_bytes(16)),
    'gruplar' => [['worker_type_id' => $kadin, 'entry_clock' => '00:00', 'exit_date' => date('Y-m-d'), 'exit_clock' => '00:01', 'kart_ids' => [$b1, $b2], 'kartsiz_adet' => 2]]], 1, db());
if (!($tp['ok'] ?? false)) { fwrite(STDERR, 'toplu: ' . json_encode($tp, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }

// v295: KARIŞIK girişli 5 dönem (kiosk yolu; biri çıkışlı) — "🎲 Otomatik Ata" uyarı
// kartı + penceresi ve Karışık rozeti bu veriyle sınanır (pdks_karisik_smoke.js).
$karisik = (int)(pdks_gunluk_karisik_tip_garanti(db())['id'] ?? 0);
foreach (['880000001', '880000002', '880000003', '880000004', '880000005'] as $i => $uid) {
    pdks_gunluk_kart_olustur(['card_no' => 'Z00' . ($i + 1), 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
    $r = pdks_gunluk_faz8a_giris_kaydet($uid, 'usb_decimal', $sid, $karisik, 'auto', 1, db());
    if (!($r['ok'] ?? false)) { fwrite(STDERR, 'karışık giriş: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
}
pdks_gunluk_faz8a_cikis_kaydet('880000001', 'usb_decimal', $sid, 1, db());

// v299: PUANTAJ_FAZ8B=1 → Faz 8B + fiyat dönemi saatleri kurulur, KADIN fiyatı Tam 9 s /
// Çift 12 s (2000) / FM saatlik 150; K001 dönemi 06:00–19:00 (13 s) ve FM 4 saat onaylı →
// "Mesai Tanımı" = "Çift · FM 1 s". Varsayılan (env yok) davranış DEĞİŞMEZ.
if (getenv('PUANTAJ_FAZ8B') === '1') {
    require_once $ROOT . '/config/pdks_faz8b.php';
    foreach (['foreman_worker_rates', 'foreman_daily_entitlement_lines'] as $ht) {
        [$c, $ix] = pdks_ddl_sqlite(pdks_hakedis_tablolar()[$ht]); db()->exec($c); foreach ($ix as $x) db()->exec($x);
    }
    pdks_faz8b_migrate(db());
    pdks_faz8b_saat_kolonlari_migrate(db());
    $rr = pdks_faz8b_oran_ekle($cavus, $kadin, '1000', '600', 'hourly', '150', date('Y-m-d', strtotime('-30 days')), 'TRY', 1, db(),
        ['full_day' => '9', 'double_day' => '12', 'double_day_rate' => '2000']);
    if (!($rr['ok'] ?? false)) { fwrite(STDERR, 'oran: ' . json_encode($rr, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
    $gun = date('Y-m-d');
    $stK = db()->prepare("UPDATE daily_worker_work_periods SET entry_time = ?, exit_time = ?, overtime_approved_hours = 4
                           WHERE session_id = ? AND worker_card_id = (SELECT id FROM worker_cards WHERE card_no = 'K001')");
    $stK->execute([$gun . ' 06:00:00', $gun . ' 19:00:00', $sid]);
    db()->prepare("UPDATE daily_worker_card_events SET server_event_time = ? WHERE session_id = ? AND event_type = 'GIRIS' AND worker_card_id = (SELECT id FROM worker_cards WHERE card_no = 'K001')")->execute([$gun . ' 06:00:00', $sid]);
    db()->prepare("UPDATE daily_worker_card_events SET server_event_time = ? WHERE session_id = ? AND event_type = 'CIKIS' AND worker_card_id = (SELECT id FROM worker_cards WHERE card_no = 'K001')")->execute([$gun . ' 19:00:00', $sid]);
}

// v299: PUANTAJ_SERVIS=1 → Servis Ücreti tabloları + çavuş fiyatı (Büyük 1500 / Küçük 800) +
// 2 servis kaydı (biri iptal) — "🚌 Servis Ücreti" penceresi ve Servisler listesi
// (pdks_servis_dialog_smoke.js). Varsayılan (env yok) davranış DEĞİŞMEZ (tablo yok → gizli).
if (getenv('PUANTAJ_SERVIS') === '1') {
    require_once $ROOT . '/config/pdks_servis.php';
    foreach (pdks_servis_tablolar() as $svSql) { [$c, $ix] = pdks_ddl_sqlite($svSql); db()->exec($c); foreach ($ix as $x) db()->exec($x); }
    $sv = pdks_servis_ucret_ekle($cavus, '1500', '800', date('Y-m-d', strtotime('-30 days')), 'TRY', 1, db());
    $s1 = pdks_servis_ekle($sid, 2, 1, 'Sabah servisi', bin2hex(random_bytes(16)), 1, db());
    $s2 = pdks_servis_ekle($sid, 1, 0, '', bin2hex(random_bytes(16)), 1, db());
    $svIptalId = (int)db()->query("SELECT id FROM daily_session_services WHERE batch_id = " . db()->quote((string)($s2['batch_id'] ?? '')))->fetchColumn();
    $s3 = pdks_servis_iptal($svIptalId, $sid, 'Yanlış girildi', 1, db());
    if (!($sv['ok'] ?? false) || !($s1['ok'] ?? false) || !($s3['ok'] ?? false)) {
        fwrite(STDERR, 'servis: ' . json_encode([$sv, $s1, $s2, $s3], JSON_UNESCAPED_UNICODE) . "
"); exit(1);
    }
}

// Sayfa seçimi: varsayılan = mesai detayı. PUANTAJ_SAYFA=liste → Günlük Puantaj listesi
// (PUANTAJ_TARIH=bugun|dun). Liste sayfası geçmiş gün + yönetici iken "ekle" penceresini basar.
// v296: PUANTAJ_SAYFA=toplu → Çavuş Toplu Döküm (bu ay) — pdks_oto_filtre_smoke.js girdisi.
$sayfa = match (getenv('PUANTAJ_SAYFA')) {
    'liste' => 'gunluk_isci_puantaj.php',
    'toplu' => 'cavus_toplu_dokum.php',
    default => 'gunluk_isci_puantaj_detay.php',
};
$_GET = match ($sayfa) {
    'gunluk_isci_puantaj.php' => ['tarih' => getenv('PUANTAJ_TARIH') === 'bugun' ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'))],
    'cavus_toplu_dokum.php'   => ['ay' => date('Y-m')],
    default                   => ['id' => (string)$sid],
};
$_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/' . $sayfa;
$src = file_get_contents($ROOT . '/' . $sayfa);
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/(db|pdks_gunluk|pdks_faz8h|pdks_faz8j|pdks_hakedis|auth)\.php\';.*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
$src = str_replace('__DIR__', var_export($ROOT, true), $src);
$tmp = sys_get_temp_dir() . '/pdks_puantaj_dialog_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n" . $src);
ob_start();
try { include $tmp; } catch (Throwable $e) { ob_end_clean(); @unlink($tmp); fwrite(STDERR, 'HATA: ' . $e->getMessage() . "\n"); exit(1); }
$html = ob_get_clean();
@unlink($tmp);
// Göreli assets/ yolları repo kökünden çözülsün diye çıktı kökte durur.
echo $html;
