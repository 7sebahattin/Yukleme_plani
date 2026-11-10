<?php
// scripts/pdks_toplu_hizli_onizle_smoke.php — Toplu İşlem hızlı kutular (kişi sayısı + çoklu FM) → GERÇEK önizleme/ekleme
//   php scripts/pdks_toplu_hizli_onizle_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');

/** execute() parametrelerini kaydeder (kart kilit sırası testi). */
class LogStmt extends PDOStatement {
    public static bool $acik = false; public static array $log = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (self::$acik) self::$log[] = [$this->queryString, $params];
        return parent::execute($params);
    }
}

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LogStmt::class]);
$AKTIF_DEPO = 'Depo A';
$ADMIN = true;
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
function current_user(): ?array { return ['id' => 1]; }
function can(string $p): bool { return true; }
function is_admin(): bool { global $ADMIN; return $ADMIN; }
$AUDIT = [];
function audit_log_event(string $a, string $m, int $id, ?array $o = null, ?array $n = null): void { global $AUDIT; $AUDIT[] = [$a, $m, $id]; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_faz8j.php';

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

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` TEXT, `display_name` TEXT, `is_active` INTEGER DEFAULT 1)");
db()->exec("INSERT INTO users (id, username) VALUES (1, 'test')");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
foreach (pdks_gunluk_tablolar() as $sql) {
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
pdks_gunluk_migrate(db());
[$c, $ix] = pdks_ddl_sqlite(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
db()->exec($c); foreach ($ix as $x) db()->exec($x);
pdks_gunluk_faz8a_migrate(db());
pdks_faz8j_migrate(db());
// Faz 8B onay kolonları (iptal/düzeltme bunları sıfırlar)
foreach (['approved_by_user_id INTEGER', 'approved_at TEXT', 'overtime_approved INTEGER', 'overtime_approved_hours INTEGER', 'overtime_approved_by_user_id INTEGER', 'overtime_approved_at TEXT'] as $k) {
    db()->exec("ALTER TABLE daily_worker_work_periods ADD COLUMN $k");
}

db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
db()->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, status TEXT, needs_recalculation INTEGER DEFAULT 0, notes TEXT, updated_at TEXT, finalized_at TEXT, finalized_by_user_id INTEGER, total_amount TEXT)");


require_once $ROOT . '/config/pdks_rapor.php';

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-92s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function say(string $t): int { return (int)db()->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
function sayimlar(): array {
    global $AUDIT;
    return ['kart' => say('worker_cards'), 'mesai' => say('daily_work_sessions'), 'olay' => say('daily_worker_card_events'),
            'donem' => say('daily_worker_work_periods'), 'audit' => say('audit_log'), 'audit_ev' => count($AUDIT),
            'hak' => say('foreman_daily_entitlements'), 'iptal' => (int)db()->query('SELECT COUNT(*) FROM daily_worker_work_periods WHERE is_voided=1')->fetchColumn()];
}

$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$cavus = [];
foreach (['A','B','C','D','E','F','G','H'] as $i => $h) {
    $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C00' . ($i + 1), 'name' => 'Çavuş ' . $h], 1, db())['id'];
}
$kartlar = [];
for ($i = 1; $i <= 12; $i++) {
    $no = sprintf('K%03d', $i);
    pdks_gunluk_kart_olustur(['card_no' => $no, 'ham_uid' => (string)(100000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
    $kartlar[$no] = (int)db()->query("SELECT id FROM worker_cards WHERE card_no='$no'")->fetchColumn();
}
$dun = date('Y-m-d', strtotime('-1 day'));
$dun2 = date('Y-m-d', strtotime('-2 day'));
$dun3 = date('Y-m-d', strtotime('-3 day'));

$bugun = date('Y-m-d');
$simdiHm = date('H:i');

// ---------------------------------------------------------------------------------------------
// v504 — Regresyon: hızlı kutular + mesai tarihi, Gelişmiş BOŞ, mesai yok → yeni mesai açılır.
// KÖK NEDEN (önizleme "Giriş/çıkış zamanı mesai tarihi, 24 saat ve gelecek kurallarına uymuyor"):
// Tam = sabit 08:00–17:00. Mesai tarihi BUGÜN ise ve şimdi 17:00'den önceyse çıkış GELECEKTE kalır;
// pdks_faz8j_zaman_kurali() "çıkış > şimdi" diye reddeder → her satır hatalı. Gelişmiş boş olması ilgisiz
// (boş gelişmiş grup JS'te hiç gönderilmez). Geçmiş günde aynı girdi sorunsuz geçer.
// ---------------------------------------------------------------------------------------------
require_once $ROOT . '/config/pdks_toplu_hizli.php';
$tipKodlari = [$kadin => 'KADIN', $erkek => 'ERKEK'];
$kodFn = static fn(int $id): ?string => $tipKodlari[$id] ?? null;
function hizli_onizle(array $hizli, string $gun, int $foreman): array {
    global $kodFn, $AKTIF_DEPO;
    $hz = pdks_toplu_hizli_isle($hizli, $gun, [], $kodFn);   // Gelişmiş: istemci grubu YOK (boş)
    if ($hz['hata'] !== null) return ['ok' => false, 'hatalar' => [$hz['hata']], 'satirlar' => [], 'ozet' => [], 'yeni_mesai' => false];
    $v = $hz['ek'] + ['foreman_id' => $foreman, 'work_date' => $gun, 'depo' => $AKTIF_DEPO, 'reason' => 'Test', 'note' => '', 'gruplar' => $hz['gruplar'], 'istek_id' => ''];
    return pdks_faz8j_toplu_onizle($v, 1, db());
}
$hz = ['tam' => [$kadin => 45, $erkek => 21], 'yarim' => [$kadin => 1],
       'fm' => [['kisi' => 40, 'saat' => '1'], ['kisi' => 4, 'saat' => '3'], ['kisi' => 1, 'saat' => '2']]];

echo "\n=== A. Geçmiş gün: hızlı kutular + çoklu FM, Gelişmiş boş, mesai yok ===\n";
$r = hizli_onizle($hz, $dun, $cavus['A']);
ok('A1 önizleme hatasız (67 kişi, çıkışlar geçmişte)', !empty($r['ok']) && $r['hatalar'] === [] && count($r['satirlar']) === 67, j($r['hatalar']));
ok('A2 çavuşun o gün mesaisi yok → yeni mesai açılacak', ($r['yeni_mesai'] ?? false) === true && ($r['session_id'] ?? null) === null);
ok('A3 tüm satırlar durum=ok', count(array_filter($r['satirlar'], fn($s) => $s['durum'] === 'ok')) === 67);
$onceki = sayimlar();
ok('A4 önizleme yan etkisiz', sayimlar() === $onceki);
$hzE = pdks_toplu_hizli_isle($hz, $dun, [], $kodFn);
$tE = pdks_faz8j_toplu_ekle($hzE['ek'] + ['foreman_id' => $cavus['A'], 'work_date' => $dun, 'depo' => $AKTIF_DEPO, 'reason' => 'Test', 'note' => '', 'gruplar' => $hzE['gruplar'], 'istek_id' => bin2hex(random_bytes(16))], 1, db());
ok('A5 ekleme: 67 dönem yazıldı, yeni mesai açıldı', !empty($tE['ok']) && (int)$tE['eklenen'] === 67 && $tE['yeni_mesai'] === true, j($tE));
$cik = db()->query("SELECT substr(exit_time,12,5) c, COUNT(*) n FROM daily_worker_work_periods WHERE session_id=" . (int)$tE['session_id'] . " AND is_voided=0 GROUP BY c ORDER BY c")->fetchAll(PDO::FETCH_KEY_PAIR);
$fmAlan = 0; foreach ($cik as $c => $n) if (!in_array($c, ['17:00', '12:30'], true)) $fmAlan += (int)$n;
ok('A6 FM saatli çıkış alan toplam 45 kişi (40+4+1)', $fmAlan === 45, j($cik));
// v509: Yarım kutusundan eklenen dönem kalıcı olarak declared='yarim'; diğerleri 'auto' (eski davranış)
$dc = db()->query("SELECT declared_attendance_class d, COUNT(*) n FROM daily_worker_work_periods WHERE session_id=" . (int)$tE['session_id'] . " AND is_voided=0 GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
ok('A7 v509: Yarım kutusundan 1 dönem declared=yarim, kalan 66 auto', (int)($dc['yarim'] ?? 0) === 1 && (int)($dc['auto'] ?? 0) === 66 && count($dc) === 2, j($dc));

echo "\n=== B. Sahip örneği: Kadın tam 45, Kadın yarım 1, Erkek tam 21 — 40 kişi 1 saat FM ===\n";
$g = pdks_toplu_hizli_gruplar(['tam' => [$kadin => 45, $erkek => 21], 'yarim' => [$kadin => 1], 'fm' => ['kisi' => 40, 'saat' => '1']], $tipKodlari, $dun);
$d = $g['dagilim'];
echo "   tek satır dağılımı: ", j($d), "\n";
ok('B1 hata yok, toplam FM = 40', $g['hata'] === null && array_sum($d) === 40, j($g));
// Elle: havuz KT45 ET21 KY1 (67). 40*45/67 = 26 (kalan 58), 40*21/67 = 12 (kalan 36), 40*1/67 = 0 (kalan 40) → taban 38,
// artan 2 → en büyük kalanlar KT(58) ve KY(40) → Kadın Tam 27 · Erkek Tam 12 · Kadın Yarım 1 (yarım 1 kişi de havuzda → FM alır)
ok('B2 dağılım: Kadın Tam 27 · Erkek Tam 12 · Kadın Yarım 1 (toplam 40)', ($d['KADIN_TAM'] ?? -1) === 27 && ($d['ERKEK_TAM'] ?? -1) === 12 && ($d['KADIN_YARIM'] ?? -1) === 1, j($d));
ok('B3 hiçbir kategori mevcudunu aşmaz', $d['KADIN_TAM'] <= 45 && $d['ERKEK_TAM'] <= 21 && $d['KADIN_YARIM'] <= 1);
ok('B4 grup toplamı = 67 kişi', array_sum(array_column($g['gruplar'], 'kartsiz_adet')) === 67);
$c = pdks_toplu_hizli_gruplar(['tam' => [$kadin => 45, $erkek => 21], 'yarim' => [$kadin => 1], 'fm' => $hz['fm']], $tipKodlari, $dun);
ok('B5 çoklu FM: hata yok, toplam 45', $c['hata'] === null && array_sum($c['dagilim']) === 45, j($c['hata']));
$satirTop = []; foreach ($c['fm_satirlar'] as $fs) foreach ($fs['dagilim'] as $k => $n) $satirTop[$k] = ($satirTop[$k] ?? 0) + $n;
ok('B6 satır toplamları = genel dağılım; kategori mevcudunu aşmaz', $satirTop === $c['dagilim'] && $c['dagilim']['KADIN_TAM'] <= 45 && $c['dagilim']['ERKEK_TAM'] <= 21 && $c['dagilim']['KADIN_YARIM'] <= 1, j($c['dagilim']));
ok('B7 satır 1 (40 kişi) = tek satırlı B2 ile aynı (ilk satır tam havuzdan)', $c['fm_satirlar'][0]['dagilim']['KADIN_TAM'] === 27 && $c['fm_satirlar'][0]['dagilim']['ERKEK_TAM'] === 12);
ok('B8 deterministik', $c === pdks_toplu_hizli_gruplar(['tam' => [$kadin => 45, $erkek => 21], 'yarim' => [$kadin => 1], 'fm' => $hz['fm']], $tipKodlari, $dun));
echo "   çoklu satır dağılımları: ", j(array_map(fn($x) => $x['dagilim'], $c['fm_satirlar'])), "\n";
$asim = pdks_toplu_hizli_gruplar(['tam' => [$kadin => 10], 'fm' => [['kisi' => 6, 'saat' => '1'], ['kisi' => 5, 'saat' => '2']]], $tipKodlari, $dun);
ok('B9 satırlar toplamı havuzu aşarsa net hata', $asim['hata'] !== null && str_contains($asim['hata'], 'havuzunu') && $asim['gruplar'] === [], (string)$asim['hata']);
$cift = pdks_toplu_hizli_gruplar(['tam' => [$kadin => 5], 'cift' => [$kadin => 3], 'fm' => [['kisi' => 6, 'saat' => '1']]], $tipKodlari, $dun);
ok('B10 çift yevmiyeliler havuza girmez (5 havuz, 6 FM → hata)', $cift['hata'] !== null);
ok('B11 boş FM satırı (kişi 0) yok sayılır', pdks_toplu_hizli_gruplar(['tam' => [$kadin => 5], 'fm' => [['kisi' => 0, 'saat' => ''], ['kisi' => 2, 'saat' => '1']]], $tipKodlari, $dun)['hata'] === null);
ok('B12 saat eksik satır → hata', pdks_toplu_hizli_gruplar(['tam' => [$kadin => 5], 'fm' => [['kisi' => 2, 'saat' => '']]], $tipKodlari, $dun)['hata'] !== null);
$cok = []; for ($i = 0; $i < PDKS_TOPLU_FM_SATIR_UST + 1; $i++) $cok[] = ['kisi' => 1, 'saat' => '1'];
ok('B13 satır sayısı üst sınırı', pdks_toplu_hizli_gruplar(['tam' => [$kadin => 50], 'fm' => $cok], $tipKodlari, $dun)['hata'] !== null);
ok('B14 eski tek satır biçimi hâlâ kabul', pdks_toplu_hizli_gruplar(['tam' => [$kadin => 5], 'fm' => ['kisi' => 2, 'saat' => '1']], $tipKodlari, $dun)['hata'] === null);

echo "\n=== C. BUGÜN (09.11 senaryosu): mesai yok → yeni mesai; çıkış gelecekteyse NET hata ===\n";
$hzB = ['tam' => [$kadin => 30, $erkek => 36]];
$r = hizli_onizle($hzB, $bugun, $cavus['B']);
if ($simdiHm >= '17:00') {
    ok('C1 şimdi ≥ 17:00: bugün hızlı Tam hatasız + yeni mesai açılır', !empty($r['ok']) && $r['yeni_mesai'] === true && count($r['satirlar']) === 66, j($r['hatalar']));
} elseif ($simdiHm >= '08:00') {
    $hm = implode(' ', array_column($r['satirlar'], 'hata'));
    ok('C1 şimdi 17:00 öncesi: 17:00 çıkışı gelecekte → TÜM 66 satır reddedilir (kök neden)', empty($r['ok']) && count(array_filter($r['satirlar'], fn($s) => $s['durum'] === 'hata')) === 66, j($r['hatalar']));
    ok('C2 hata metni "gelecek" olduğunu açıkça söyler (çıkış saati + şu an)', str_contains($hm, 'henüz gelmedi') && str_contains($hm, '17:00'), $hm);
    ok('C3 aynı girdi dünün tarihiyle geçer (sorun Gelişmiş değil, çıkışın geleceği)', !empty(hizli_onizle($hzB, $dun, $cavus['B'])['ok']));
} else {
    ok('C1 şimdi < 08:00: bugün giriş de gelecekte → hata', empty($r['ok']));
}
ok('C4 mesai yok → yeni_mesai=true (bugün için de)', ($r['yeni_mesai'] ?? false) === true);

echo "\nSONUÇ: $gecen geçti, $fail başarısız\n";
exit($fail ? 1 : 0);
