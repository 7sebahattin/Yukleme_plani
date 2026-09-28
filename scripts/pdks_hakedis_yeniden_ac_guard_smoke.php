<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_hakedis_yeniden_ac_guard_smoke.php — kesin hakedişi yeniden
// açma korumasının (pdks_hakedis_yeniden_ac) "geçerli ödemesi olan çavuş"
// kuralı, config/pdks_cari.php YÜKLENMEMİŞ bir çağıranda da çalışıyor mu?
//
// Tek çağıran cavus_hakedis_detay.php pdks_cari.php'yi yüklemiyor; eski
// function_exists() yumuşak kontrolü orada sessizce atlanıyordu. Her senaryo
// AYRI bir php alt sürecinde, YALNIZ config/pdks_hakedis.php yüklenerek
// koşulur (gerçek ekranın izole tekrarı). Ağ yok, canlı DB'ye dokunmaz.
//
//   php scripts/pdks_hakedis_yeniden_ac_guard_smoke.php   → çıkış 0 = geçti
// =========================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); die('Yalnız CLI.'); }

$root = dirname(__DIR__);
$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-96s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

/**
 * $odeme: null = foreman_payments tablosu YOK · 'valid' / 'cancelled' = o
 * durumda tek ödeme · 'baska_cavus' = geçerli ödeme ama BAŞKA çavuşa ait.
 */
function senaryo(string $root, ?string $odeme, bool $cariYukle = false): array {
    $kod = <<<'PHP'
<?php
declare(strict_types=1);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function is_admin(): bool { return true; }
require_once '__KOK__/config/pdks_hakedis.php';
if (__CARI__) { require_once '__KOK__/config/pdks_cari.php'; }
if (!__CARI__ && function_exists('pdks_cari_odeme_var_mi')) { fwrite(STDERR, "izolasyon bozuk\n"); exit(2); }
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, foreman_id INTEGER, status TEXT, notes TEXT, updated_at TEXT, finalized_at TEXT, finalized_by_user_id INTEGER)");
$db->exec("INSERT INTO foreman_daily_entitlements (id,foreman_id,status,finalized_at) VALUES (1,7,'final','2026-09-01 10:00:00')");
$odeme = __ODEME__;
if ($odeme !== null) {
    $db->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY, foreman_id INTEGER, status TEXT)");
    $fid = $odeme === 'baska_cavus' ? 8 : 7;
    $st  = $odeme === 'baska_cavus' ? 'valid' : $odeme;
    $db->prepare("INSERT INTO foreman_payments (id,foreman_id,status) VALUES (1,?,?)")->execute([$fid, $st]);
}
$r = pdks_hakedis_yeniden_ac(1, 'test gerekçesi', 1, $db);
$r['_durum'] = (string)$db->query("SELECT status FROM foreman_daily_entitlements WHERE id=1")->fetchColumn();
echo json_encode($r, JSON_UNESCAPED_UNICODE);
PHP;
    $kod = strtr($kod, [
        '__KOK__'   => $root,
        '__CARI__'  => $cariYukle ? 'true' : 'false',
        '__ODEME__' => $odeme === null ? 'null' : var_export($odeme, true),
    ]);
    $dosya = tempnam(sys_get_temp_dir(), 'reac_') . '.php';
    file_put_contents($dosya, $kod);
    $cikti = []; $rc = 0;
    exec('php ' . escapeshellarg($dosya) . ' 2>&1', $cikti, $rc);
    @unlink($dosya);
    $str = implode("\n", $cikti);
    $j = json_decode($str, true);
    return ['rc' => $rc, 'ham' => $str, 'r' => is_array($j) ? $j : null];
}

echo "\n=== A. pdks_cari.php YÜKLENMEDEN (cavus_hakedis_detay.php senaryosu) ===\n";
$s = senaryo($root, 'valid');
ok('A1) geçerli ödemesi olan çavuşun kesin hakedişi yeniden AÇILAMAZ (cari_hareketli_engel)',
    $s['rc'] === 0 && ($s['r']['ok'] ?? true) === false && ($s['r']['kod'] ?? '') === 'cari_hareketli_engel', $s['ham']);
ok('A2) reddedilince hakediş KESİN kalır (status=final)', ($s['r']['_durum'] ?? '') === 'final', $s['ham']);

$s = senaryo($root, 'cancelled');
ok('A3) yalnız İPTAL edilmiş ödeme varsa yeniden açılır (ok, status=draft)',
    $s['rc'] === 0 && ($s['r']['ok'] ?? false) === true && ($s['r']['_durum'] ?? '') === 'draft', $s['ham']);

$s = senaryo($root, 'baska_cavus');
ok('A4) BAŞKA çavuşun geçerli ödemesi bu çavuşu engellemez (ok, status=draft)',
    $s['rc'] === 0 && ($s['r']['ok'] ?? false) === true && ($s['r']['_durum'] ?? '') === 'draft', $s['ham']);

$s = senaryo($root, null);
ok('A5) Faz 5 tablosu (foreman_payments) YOKSA kontrol atlanır — Faz 4 tek başına çalışır (ok)',
    $s['rc'] === 0 && ($s['r']['ok'] ?? false) === true && ($s['r']['_durum'] ?? '') === 'draft', $s['ham']);

echo "\n=== B. pdks_cari.php YÜKLÜYKEN (mevcut yumuşak kontrol yolu) aynı karar ===\n";
$s = senaryo($root, 'valid', true);
ok('B1) geçerli ödeme → cari_hareketli_engel (function_exists yolu)',
    $s['rc'] === 0 && ($s['r']['ok'] ?? true) === false && ($s['r']['kod'] ?? '') === 'cari_hareketli_engel', $s['ham']);
$s = senaryo($root, 'cancelled', true);
ok('B2) iptal ödeme → yeniden açılır (function_exists yolu)',
    $s['rc'] === 0 && ($s['r']['ok'] ?? false) === true && ($s['r']['_durum'] ?? '') === 'draft', $s['ham']);

echo "\n=== C. KAYNAK SÖZLEŞMESİ ===\n";
$hk = (string)file_get_contents($root . '/config/pdks_hakedis.php');
$dt = (string)file_get_contents($root . '/cavus_hakedis_detay.php');
ok('C1) cavus_hakedis_detay.php pdks_hakedis_yeniden_ac() çağırıyor (bu testin kapsadığı ekran)', str_contains($dt, 'pdks_hakedis_yeniden_ac('));
ok('C2) config/pdks_hakedis.php pdks_cari.php\'yi require ETMİYOR (ters bağımlılık yok)', !preg_match('/require(_once)?\s+.*pdks_cari/', $hk));
ok('C3) yedek kontrol foreman_payments tablo varlığına bağlı (pdks_hakedis_tablo_var)',
    (bool)preg_match("/!function_exists\('pdks_cari_odeme_var_mi'\)\s*&&\s*pdks_hakedis_tablo_var\(\\\$pdo, 'foreman_payments'\)/", $hk));

echo "\nSONUÇ: $gecen test geçti, $fail hata.\n";
exit($fail > 0 ? 1 : 0);
