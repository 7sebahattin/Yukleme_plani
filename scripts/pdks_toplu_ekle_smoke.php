<?php
// =========================================================
// scripts/pdks_toplu_ekle_smoke.php — KARTSIZ MESAİ + BUGÜN KURALLARI + TOPLU İŞLEM
//
// config/pdks_faz8j.php: pdks_faz8j_gecmis_ekle() (kartsız + bugün),
// pdks_faz8j_toplu_onizle/ekle/listele/geri_al(). Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_toplu_ekle_smoke.php   → çıkış kodu 0 = tüm testler geçti
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
function ekle(array $o = []): array {
    global $cavus, $kartlar, $kadin, $dun, $AKTIF_DEPO;
    return pdks_faz8j_gecmis_ekle($o + [
        'foreman_id' => $cavus['A'], 'work_date' => $dun, 'worker_card_id' => $kartlar['K001'], 'worker_type_id' => $kadin,
        'entry_date' => $dun, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00',
        'reason' => 'Test sebebi', 'note' => '', 'depo' => $AKTIF_DEPO,
    ], 1, db());
}

echo "\n=== 1. Kartsız tekil ekleme (sanal kart) ===\n";
$r0 = ekle();
ok('kartlı kayıt (K001) dün eklendi', !empty($r0['ok']), j($r0));
$sidA = (int)$r0['session_id'];
$r1 = ekle(['kartsiz' => 1, 'worker_card_id' => 0, 'entry_clock' => '08:30', 'exit_clock' => '16:30']);
$p1id = !empty($r1['ok']) ? (int)db()->query('SELECT worker_card_id FROM daily_worker_work_periods WHERE id = ' . (int)$r1['period_id'])->fetchColumn() : 0;
ok('kartsız kayıt eklendi (aynı mesai, kartsiz=true, kart no = KARTSIZ- + 6 haneli satır id)', !empty($r1['ok']) && (int)$r1['session_id'] === $sidA && $r1['kartsiz'] === true && $r1['card_no'] === sprintf('KARTSIZ-%06d', $p1id), j($r1));
$p1 = db()->query('SELECT * FROM daily_worker_work_periods WHERE id = ' . (int)$r1['period_id'])->fetch();
$vk = db()->query('SELECT * FROM worker_cards WHERE id = ' . (int)$p1['worker_card_id'])->fetch();
ok('sanal kart: enrolled_source=kartsiz, status=disabled, tip=Kadın', $vk['enrolled_source'] === 'kartsiz' && $vk['status'] === 'disabled' && (int)$vk['worker_type_id'] === $kadin, j($vk));
ok('sanal kart UID "KARTSIZ" + hex, onaltılık değil', str_starts_with($vk['canonical_uid'], 'KARTSIZ') && strlen($vk['canonical_uid']) <= 32 && !ctype_xdigit($vk['canonical_uid']), $vk['canonical_uid']);
ok('sanal kart UID hiçbir okuyucu normalizasyonundan ÜRETİLEMEZ', pdks_uid_hex_normalize($vk['canonical_uid']) === null && pdks_uid_from_decimal($vk['canonical_uid']) === null && pdks_uid_from_web_nfc($vk['canonical_uid']) === null);
ok('pdks_faz8j_kartsiz_mi(): sanal kart true, fiziksel kart false', pdks_faz8j_kartsiz_mi($vk) && !pdks_faz8j_kartsiz_mi(db()->query('SELECT * FROM worker_cards WHERE id = ' . $kartlar['K001'])->fetch()));
ok('kartsız dönem kapalı, kaynak manual, tip Kadın', $p1['status'] === 'closed' && $p1['source'] === 'manual' && $p1['worker_type_name_snapshot'] === 'Kadın' && $p1['exit_time'] === "$dun 16:30:00", j($p1));
$r2 = ekle(['kartsiz' => 1, 'worker_type_id' => $erkek, 'entry_clock' => '09:00', 'exit_clock' => '15:00']);
$p2id = !empty($r2['ok']) ? (int)db()->query('SELECT worker_card_id FROM daily_worker_work_periods WHERE id = ' . (int)$r2['period_id'])->fetchColumn() : 0;
ok('ikinci kartsız (Erkek) → ayrı sanal kart, numarası id\'den (sıralı, tekil)', !empty($r2['ok']) && $p2id > $p1id && $r2['card_no'] === sprintf('KARTSIZ-%06d', $p2id) && $r2['card_no'] !== $r1['card_no'], j($r2));
ok('geçici kart numarası (KARTSIZ-T…) kalmadı, kart no benzersiz', (int)db()->query("SELECT COUNT(*) FROM worker_cards WHERE card_no LIKE 'KARTSIZ-T%'")->fetchColumn() === 0
    && (int)db()->query('SELECT COUNT(*) FROM worker_cards')->fetchColumn() === (int)db()->query('SELECT COUNT(DISTINCT card_no) FROM worker_cards')->fetchColumn());
ok('kart no önerisi (K…) kartsız sıradan etkilenmez', pdks_gunluk_sonraki_kart_no($kadin, db()) === 'K013', pdks_gunluk_sonraki_kart_no($kadin, db()));
$r = ekle(['kartsiz' => 1, 'exit_date' => '', 'exit_clock' => '']);
ok('kartsız + çıkışsız → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'Kartsız'), j($r));
$au = db()->query("SELECT new_values FROM audit_log WHERE action='puantaj_ekle' AND record_id = " . (int)$r1['period_id'])->fetchColumn();
ok('audit puantaj_ekle yükü kartsiz=true taşır', (json_decode((string)$au, true)['kartsiz'] ?? null) === true, (string)$au);
$r = ekle(['worker_card_id' => (int)$vk['id'], 'entry_clock' => '17:30', 'exit_clock' => '18:00']);
ok('sanal kart id ile kartlı ekleme → reddedilir (başka kayda bağlanamaz)', empty($r['ok']) && str_contains((string)$r['hata'], 'sanal kart'), j($r));

echo "\n=== 2. Kartsız korumaları ===\n";
ok('boş kart listesinde sanal kart YOK', !array_filter(pdks_faz8j_bos_kartlar($dun3, db()), fn($k) => str_starts_with($k['card_no'], 'KARTSIZ')));
$src = (string)file_get_contents($ROOT . '/isci_kartlari.php');
ok('isci_kartlari.php havuz listesi enrolled_source=kartsiz kartları gizler', str_contains($src, "\$where = [\"w.enrolled_source <> 'kartsiz'\"]"));
$havuz = db()->query("SELECT card_no FROM worker_cards w WHERE w.enrolled_source <> 'kartsiz'")->fetchAll(PDO::FETCH_COLUMN);
ok('havuz sorgusu: 12 fiziksel kart, sanal kart yok', count($havuz) === 12, j($havuz));
$r = pdks_gunluk_kart_durum_degistir((int)$vk['id'], 'available', 1, db());
ok('sanal kart durum değişikliği → reddedilir', empty($r['ok']) && db()->query('SELECT status FROM worker_cards WHERE id=' . (int)$vk['id'])->fetchColumn() === 'disabled', j($r));
$r = pdks_gunluk_kart_duzenle((int)$vk['id'], ['card_no' => 'X1'], 1, db());
ok('sanal kart düzenleme → reddedilir', empty($r['ok']), j($r));
ok('fiziksel kartın durum değişikliği hâlâ çalışır', !empty(pdks_gunluk_kart_durum_degistir($kartlar['K012'], 'lost', 1, db())['ok']));
$kpi = pdks_rapor_faz8a_operasyonel_kpi($dun, $dun, 'Depo A', null, null, db());
ok('rapor: katılım 3 (1 kartlı + 2 kartsız), fiziksel kart kullanımı 1', $kpi['toplam_calisan'] === 3 && $kpi['fiziksel_kart_kullanimi'] === 1, j($kpi));
$oz = pdks_gunluk_oturum_ozet($sidA, db());
ok('oturum özeti: Kadın 2 (kartlı+kartsız), Erkek 1, toplam 3', ($oz['giris']['Kadın'] ?? 0) === 2 && ($oz['giris']['Erkek'] ?? 0) === 1 && $oz['giris_toplam'] === 3, j($oz));
$dn = pdks_faz8b_oturum_donemleri($sidA, db());
ok('hakediş dönem listesi (faz8b) kartsız dönemleri Kadın/Erkek olarak içerir', count($dn) === 3 && count(array_filter($dn, fn($d) => str_starts_with($d['card_no'], 'KARTSIZ'))) === 2, j(array_column($dn, 'card_no')));
$base = ['session_id' => $sidA, 'depo' => 'Depo A', 'worker_type_id' => $kadin, 'entry_date' => $dun, 'entry_clock' => '08:30', 'exit_date' => $dun, 'exit_clock' => '16:00', 'reason' => 'düzeltme'];
$r = pdks_faz8j_duzelt(['period_id' => (int)$r1['period_id'], 'worker_card_id' => $kartlar['K002']] + $base, 1, db());
ok('düzelt: kartsız dönem başka karta taşınamaz', empty($r['ok']) && str_contains((string)$r['hata'], 'taşınamaz'), j($r));
$r = pdks_faz8j_duzelt(['period_id' => (int)$r0['period_id'], 'worker_card_id' => (int)$vk['id']] + $base, 1, db());
ok('düzelt: sanal kart başka döneme verilemez', empty($r['ok']) && str_contains((string)$r['hata'], 'sanal kart'), j($r));
$r = pdks_faz8j_duzelt(['period_id' => (int)$r1['period_id'], 'worker_card_id' => (int)$vk['id'], 'exit_date' => '', 'exit_clock' => ''] + $base, 1, db());
ok('düzelt: kartsız dönemin çıkışı silinemez', empty($r['ok']) && str_contains((string)$r['hata'], 'zorunlu'), j($r));
$r = pdks_faz8j_duzelt(['period_id' => (int)$r1['period_id'], 'worker_card_id' => (int)$vk['id']] + $base, 1, db());
ok('düzelt: kartsız dönemin saati (aynı sanal kartla) düzeltilebilir', !empty($r['ok']), j($r));
$dg = pdks_gunluk_puantaj_denetim_gecmisi([(int)$r1['period_id']], db());
ok('işlem geçmişi etiketi "(kartsız)"', (bool)array_filter($dg, fn($d) => str_contains($d['islem_etiket'], 'kartsız')), j(array_column($dg, 'islem_etiket')));

echo "\n=== 3. Bugün kuralları (tekil) ===\n";
if ($simdiHm <= '00:01') {
    echo "  (gece yarısı — bugün senaryoları atlandı)\n";
} else {
    $bg = fn(array $o) => ekle($o + ['work_date' => $bugun, 'entry_date' => $bugun, 'entry_clock' => '00:00', 'exit_date' => '', 'exit_clock' => '']);
    // B: dünden açık mesai → kiosk yolu reddeder
    db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$cavus['B'], 'Çavuş B', 'C002', $dun, 'Depo A', 'open', "$dun 07:00:00", 1]);
    $m0 = say('daily_work_sessions');
    $r = $bg(['foreman_id' => $cavus['B'], 'worker_card_id' => $kartlar['K003']]);
    ok('önceki günden açık mesai → bugün eklenemez (kiosk kuralı mesajı)', empty($r['ok']) && str_contains((string)$r['hata'], 'kapatılmamış') && say('daily_work_sessions') === $m0, j($r));
    db()->exec("UPDATE daily_work_sessions SET status='closed' WHERE foreman_id = {$cavus['B']} AND work_date = '$dun'");
    $r = $bg(['foreman_id' => $cavus['B'], 'worker_card_id' => $kartlar['K003']]);
    $sB = !empty($r['ok']) ? db()->query('SELECT * FROM daily_work_sessions WHERE id=' . (int)$r['session_id'])->fetch() : [];
    $pB = !empty($r['ok']) ? db()->query('SELECT * FROM daily_worker_work_periods WHERE id=' . (int)$r['period_id'])->fetch() : [];
    ok('mesai yok → kiosk yoluyla AÇIK mesai açılır (yeni_mesai=true)', !empty($r['ok']) && $r['yeni_mesai'] === true && ($sB['status'] ?? '') === 'open' && $sB['work_date'] === $bugun, j($r));
    ok('çıkışsız kartlı satır → AÇIK dönem (exit NULL, exit_event NULL), tek GİRİŞ olayı', ($pB['status'] ?? '') === 'open' && $pB['exit_time'] === null && $pB['exit_event_id'] === null && $r['acik'] === true
        && (int)db()->query('SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id=' . $kartlar['K003'])->fetchColumn() === 1, j($pB));
    $ozB = pdks_gunluk_oturum_ozet((int)$r['session_id'], db());
    ok('özet: açık dönem İÇERİDE sayılır', $ozB['icerde_toplam'] === 1, j($ozB));
    $r2 = $bg(['foreman_id' => $cavus['B'], 'worker_card_id' => $kartlar['K004'], 'exit_date' => $bugun, 'exit_clock' => $simdiHm]);
    ok('aynı gün ikinci ekleme → mevcut AÇIK mesai kullanılır (yeni değil)', !empty($r2['ok']) && (int)$r2['session_id'] === (int)$r['session_id'] && $r2['yeni_mesai'] === false && $r2['acik'] === false, j($r2));
    $r = $bg(['foreman_id' => $cavus['B'], 'worker_card_id' => $kartlar['K003']]);
    ok('kiosk kuralı: aynı kart bu mesaide zaten içeride → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'içeride'), j($r));
    $r = $bg(['foreman_id' => $cavus['C'], 'worker_card_id' => $kartlar['K003']]);
    ok('kiosk kuralı: kart başka çavuşun açık mesaisinde → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'Çavuş B mesaisinde açık'), j($r));
    // E: bugün açık dönem, sonra mesai eksik çıkışla kapatılır → aynı gün başka mesaiye giremez
    $rE = $bg(['foreman_id' => $cavus['E'], 'worker_card_id' => $kartlar['K008']]);
    db()->exec('UPDATE daily_work_sessions SET status=\'closed\' WHERE id=' . (int)$rE['session_id']);
    $r = $bg(['foreman_id' => $cavus['C'], 'worker_card_id' => $kartlar['K008']]);
    ok('kiosk kuralı: bugün eksik çıkışla kapanan mesaideki kart → reddedilir (bugun_eksik_cikis)', !empty($rE['ok']) && empty($r['ok']) && str_contains((string)$r['hata'], 'Aynı gün'), j($r));
    $r = $bg(['foreman_id' => $cavus['E'], 'worker_card_id' => $kartlar['K009'], 'exit_date' => $bugun, 'exit_clock' => $simdiHm]);
    ok('bugünkü mesai KAPATILMIŞ → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'kapatılmış'), j($r));
    $r = $bg(['foreman_id' => $cavus['B'], 'worker_card_id' => $kartlar['K012']]);
    ok('kiosk kuralı: KAYIP kart çıkışsız girilemez', empty($r['ok']) && str_contains((string)$r['hata'], 'KAYIP'), j($r));
    $r = $bg(['foreman_id' => $cavus['B'], 'kartsiz' => 1]);
    ok('bugün kartsız + çıkışsız → reddedilir (kartsız her zaman kapalı)', empty($r['ok']) && str_contains((string)$r['hata'], 'Kartsız'), j($r));
    $r = $bg(['foreman_id' => $cavus['B'], 'kartsiz' => 1, 'exit_date' => $bugun, 'exit_clock' => $simdiHm]);
    ok('bugün kartsız + çıkışlı → kapalı dönem eklenir', !empty($r['ok']) && $r['acik'] === false, j($r));
    if ($simdiHm < '23:58') {
        $r = $bg(['foreman_id' => $cavus['B'], 'worker_card_id' => $kartlar['K010'], 'entry_clock' => date('H:i', time() + 120)]);
        ok('giriş gelecekte → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'kurallarına'), j($r));
    }
    $r = ekle(['foreman_id' => $cavus['B'], 'worker_card_id' => $kartlar['K010'], 'exit_date' => '', 'exit_clock' => '']);
    ok('geçmiş gün çıkışsız → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'zorunlu'), j($r));
}

echo "\n=== 4. Toplu önizleme — yan etkisiz ===\n";
function toplu(array $o = []): array {
    global $cavus, $kartlar, $kadin, $erkek, $dun2, $AKTIF_DEPO;
    $t = $o['work_date'] ?? $dun2;
    return $o + [
        'foreman_id' => $cavus['D'], 'work_date' => $t, 'depo' => $AKTIF_DEPO, 'reason' => 'Toplu giriş unutuldu', 'note' => 'not',
        'istek_id' => bin2hex(random_bytes(16)),
        'gruplar' => [
            ['worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_date' => $t, 'exit_clock' => '17:00', 'kart_ids' => [$kartlar['K002'], $kartlar['K001']], 'kartsiz_adet' => 2],
            ['worker_type_id' => $erkek, 'entry_clock' => '07:30', 'exit_date' => $t, 'exit_clock' => '16:30', 'kart_ids' => [$kartlar['K005']], 'kartsiz_adet' => 1],
        ],
    ];
}
$once = sayimlar();
$on = pdks_faz8j_toplu_onizle(toplu(), 1, db());
ok('önizleme ok, 6 satır hepsi ok, yeni_mesai=true', !empty($on['ok']) && count($on['satirlar']) === 6 && !array_filter($on['satirlar'], fn($s) => $s['durum'] !== 'ok') && $on['yeni_mesai'] === true, j($on));
$ozK = array_column($on['ozet'], null, 'tip');
ok('özet: Kadın 2 kartlı + 2 kartsız, Erkek 1 + 1', ($ozK['Kadın']['kartli'] ?? 0) === 2 && $ozK['Kadın']['kartsiz'] === 2 && ($ozK['Erkek']['kartli'] ?? 0) === 1 && $ozK['Erkek']['kartsiz'] === 1, j($on['ozet']));
ok('satır biçimi: kart_no / KARTSIZ, giriş-çıkış zamanları', $on['satirlar'][0]['kart_no'] === 'K002' && $on['satirlar'][2]['kart_no'] === 'KARTSIZ' && $on['satirlar'][0]['giris'] === "$dun2 08:00:00" && $on['satirlar'][0]['cikis'] === "$dun2 17:00:00", j($on['satirlar'][0]));
ok('önizleme HİÇBİR satır yazmadı (kart/mesai/olay/dönem/audit sayıları aynı)', sayimlar() === $once, j([$once, sayimlar()]));
if ($simdiHm > '00:01') {
    db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$cavus['G'], 'Çavuş G', 'C007', $dun, 'Depo A', 'open', "$dun 07:00:00", 1]);
    $once = sayimlar();
    $on2 = pdks_faz8j_toplu_onizle(toplu(['foreman_id' => $cavus['G'], 'work_date' => $bugun, 'gruplar' => [['worker_type_id' => $kadin, 'entry_clock' => '00:00', 'kart_ids' => [$kartlar['K011']]]]]), 1, db());
    ok('bugün önizleme: önceki gün açık mesai → hata, mesai AÇILMADI', empty($on2['ok']) && str_contains(implode(' ', $on2['hatalar']), 'kapatılmamış') && sayimlar() === $once, j($on2));
}

echo "\n=== 5. Toplu ekleme — hep-ya-hiç ===\n";
$c5 = pdks_faz8j_gecmis_ekle(['foreman_id' => $cavus['C'], 'work_date' => $dun2, 'worker_card_id' => $kartlar['K005'], 'worker_type_id' => $erkek,
    'entry_date' => $dun2, 'entry_clock' => '08:00', 'exit_date' => $dun2, 'exit_clock' => '17:00', 'reason' => 'x', 'depo' => 'Depo A'], 1, db());
ok('hazırlık: K005 aynı gün başka çavuşta 08:00-17:00', !empty($c5['ok']), j($c5));
$once = sayimlar();
$on = pdks_faz8j_toplu_onizle(toplu(), 1, db());
$k5 = array_values(array_filter($on['satirlar'], fn($s) => $s['kart_no'] === 'K005'))[0] ?? [];
ok('önizleme: K005 satırı hata (çakışan), diğerleri ok, ok=false', empty($on['ok']) && ($k5['durum'] ?? '') === 'hata' && str_contains((string)$k5['hata'], 'çakışan')
    && count(array_filter($on['satirlar'], fn($s) => $s['durum'] === 'ok')) === 5, j($on));
$r = pdks_faz8j_toplu_ekle(toplu(), 1, db());
ok('ekleme: tek hatalı kart → ok=false, HİÇBİR ŞEY yazılmadı', empty($r['ok']) && sayimlar() === $once && count($r['satirlar']) === 6, j([$r['hatalar'] ?? null, $once, sayimlar()]));
$g = toplu(); $g['gruplar'][1]['kart_ids'] = [$kartlar['K006']];
$g['gruplar'][0]['kart_ids'] = [$kartlar['K007'], $kartlar['K002'], $kartlar['K001']];
LogStmt::$log = []; LogStmt::$acik = true;
$tp = pdks_faz8j_toplu_ekle($g, 1, db());
LogStmt::$acik = false;
ok('toplu ekleme başarılı: 7 kayıt (4 kartlı + 3 kartsız), yeni mesai', !empty($tp['ok']) && $tp['eklenen'] === 7 && count($tp['period_ids']) === 7 && $tp['yeni_mesai'] === true, j($tp));
ok('toplu_id biçimi TP + Ymd + 8 hex', (bool)preg_match('/^TP' . date('Ymd') . '[0-9a-f]{8}$/', (string)($tp['toplu_id'] ?? '')), (string)($tp['toplu_id'] ?? ''));
$sD = db()->query('SELECT * FROM daily_work_sessions WHERE id=' . (int)$tp['session_id'])->fetch();
ok('geçmiş gün mesaisi KAPALI oluşturuldu, açılış 07:30 kapanış 17:00', $sD['status'] === 'closed' && $sD['opened_at'] === "$dun2 07:30:00" && $sD['closed_at'] === "$dun2 17:00:00", j($sD));
$kilitler = array_map(fn($l) => (int)$l[1][0], array_filter(LogStmt::$log, fn($l) => $l[0] === 'SELECT id FROM worker_cards WHERE id = ?'));
$beklenen = [$kartlar['K001'], $kartlar['K002'], $kartlar['K006'], $kartlar['K007']];
ok('kartlar ARTAN id sırasıyla kilitlendi', array_slice(array_values($kilitler), 0, 4) === $beklenen, j([$kilitler, $beklenen]));
$pa = db()->query("SELECT new_values FROM audit_log WHERE action='puantaj_ekle' AND module='daily_worker_work_periods'")->fetchAll(PDO::FETCH_COLUMN);
$paT = array_filter($pa, fn($x) => (json_decode($x, true)['toplu_id'] ?? '') === $tp['toplu_id']);
ok('satır başına audit puantaj_ekle + toplu_id (7)', count($paT) === 7, (string)count($paT));
$ozA = db()->query("SELECT * FROM audit_log WHERE action='puantaj_toplu_ekle'")->fetchAll();
$ozV = json_decode((string)($ozA[0]['new_values'] ?? ''), true) ?: [];
ok('TEK özet audit puantaj_toplu_ekle (mesai modülü, period_ids, sayılar, sebep)', count($ozA) === 1 && $ozA[0]['module'] === 'daily_work_sessions' && (int)$ozA[0]['record_id'] === (int)$tp['session_id']
    && $ozV['period_ids'] === $tp['period_ids'] && $ozV['kartli'] === 4 && $ozV['kartsiz'] === 3 && $ozV['reason'] === 'Toplu giriş unutuldu', j($ozA));
$ozD = pdks_gunluk_oturum_ozet((int)$tp['session_id'], db());
ok('mesai özeti: Kadın 5, Erkek 2, çıkış 7', ($ozD['giris']['Kadın'] ?? 0) === 5 && ($ozD['giris']['Erkek'] ?? 0) === 2 && $ozD['cikis_toplam'] === 7, j($ozD));
$dgT = pdks_gunluk_puantaj_denetim_gecmisi([], db(), 20, (int)$tp['session_id']);
ok('işlem geçmişi (session id ile) "Toplu çalışma eklendi" + "7 kayıt"', count($dgT) === 1 && str_contains($dgT[0]['islem_etiket'], 'Toplu') && str_contains((string)$dgT[0]['detay'], '7 kayıt'), j($dgT));
$r = pdks_faz8j_toplu_ekle($g, 1, db());
ok('aynı toplu tekrar → kartlar çakışır, reddedilir', empty($r['ok']), j($r['hatalar'] ?? null));

echo "\n=== 6. Toplu doğrulama kuralları ===\n";
$hata = function (string $ad, array $v, string $parca) {
    $once = sayimlar();
    $a = pdks_faz8j_toplu_onizle($v, 1, db()); $b = pdks_faz8j_toplu_ekle($v, 1, db());
    $metin = implode(' | ', array_merge($a['hatalar'], array_filter(array_column($a['satirlar'], 'hata'))));
    ok($ad, empty($a['ok']) && empty($b['ok']) && str_contains($metin, $parca) && sayimlar() === $once, $metin);
};
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3]);
$v['gruplar'] = [['worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_date' => $dun3, 'exit_clock' => '17:00', 'kart_ids' => [], 'kartsiz_adet' => 251]];
$hata('251 kayıt → limit (250) hatası', $v, '250');
$v['gruplar'][0]['kartsiz_adet'] = 250;
$a = pdks_faz8j_toplu_onizle($v, 1, db());
ok('tam 250 kayıt → önizleme ok', !empty($a['ok']) && count($a['satirlar']) === 250, j($a['hatalar']));
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3]);
$v['gruplar'][1]['kart_ids'] = [$kartlar['K001']]; $v['gruplar'][0]['kart_ids'] = [$kartlar['K001']];
$hata('aynı kart iki grupta → hata', $v, 'birden fazla grupta');
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3]); $v['gruplar'][1]['worker_type_id'] = $kadin;
$hata('aynı tip iki grup → hata', $v, 'Aynı işçi tipi');
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3]); $v['gruplar'][0]['worker_type_id'] = 99999;
$hata('desteklenmeyen tip → hata', $v, 'tipi');
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3]); $v['gruplar'][0]['exit_date'] = ''; $v['gruplar'][0]['exit_clock'] = '';
$hata('geçmiş gün çıkışsız grup → hata', $v, 'zorunlu');
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3, 'reason' => '']);
$hata('sebep boş → hata', $v, 'Sebep');
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3]); $v['gruplar'][0]['kart_ids'] = [99999];
$hata('olmayan kart → satır hatası', $v, 'bulunamadı');
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3, 'gruplar' => []]);
$hata('grup yok → hata', $v, 'grubu');
if ($simdiHm > '00:01') {
    $v = toplu(['foreman_id' => $cavus['H'], 'work_date' => $bugun]);
    $v['gruplar'] = [['worker_type_id' => $kadin, 'entry_clock' => '00:00', 'kart_ids' => [$kartlar['K010']], 'kartsiz_adet' => 1]];
    $hata('bugün çıkışsız grupta kartsız → hata', $v, 'Kartsız');
    $v['gruplar'][0]['kartsiz_adet'] = 0;
    $once = sayimlar();
    $tb = pdks_faz8j_toplu_ekle($v, 1, db());
    $pT = !empty($tb['ok']) ? db()->query('SELECT status, exit_time FROM daily_worker_work_periods WHERE id=' . (int)$tb['period_ids'][0])->fetch() : [];
    $sT = !empty($tb['ok']) ? db()->query('SELECT status FROM daily_work_sessions WHERE id=' . (int)$tb['session_id'])->fetchColumn() : '';
    ok('bugün toplu: mesai kiosk yoluyla açıldı, çıkışsız kart AÇIK dönem', !empty($tb['ok']) && $tb['yeni_mesai'] === true && $sT === 'open' && ($pT['status'] ?? '') === 'open' && $pT['exit_time'] === null, j($tb));
}
$ADMIN = false;
$a = pdks_faz8j_toplu_onizle(toplu(['work_date' => $dun3]), 1, db()); $b = pdks_faz8j_toplu_ekle(toplu(['work_date' => $dun3]), 1, db());
ok('yönetici değil → önizleme ve ekleme reddedilir', empty($a['ok']) && empty($b['ok']) && str_contains($a['hatalar'][0] ?? '', 'yönetici'), j($a));
$ADMIN = true;
$a = pdks_faz8j_toplu_ekle(toplu(['work_date' => $dun3, 'depo' => 'Depo B']), 1, db());
ok('depo aktif depoyla uyuşmuyor → reddedilir', empty($a['ok']) && str_contains($a['hatalar'][0] ?? '', 'aktif depo'), j($a));
$fin = pdks_faz8j_gecmis_ekle(['foreman_id' => $cavus['F'], 'work_date' => $dun3, 'worker_card_id' => $kartlar['K011'], 'worker_type_id' => $kadin,
    'entry_date' => $dun3, 'entry_clock' => '06:00', 'exit_date' => $dun3, 'exit_clock' => '07:00', 'reason' => 'x', 'depo' => 'Depo A'], 1, db());
db()->exec("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, status) VALUES (" . (int)$fin['session_id'] . ", {$cavus['F']}, 'final')");
$hata('kesinleşmiş hakediş → hata', toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3]), 'kesinleşmiş');
db()->exec("UPDATE foreman_daily_entitlements SET status='draft', needs_recalculation=0 WHERE session_id=" . (int)$fin['session_id']);
$v = toplu(['foreman_id' => $cavus['F'], 'work_date' => $dun3]); $v['gruplar'][0]['kart_ids'] = [$kartlar['K008']]; $v['gruplar'][1]['kart_ids'] = [];
$tf = pdks_faz8j_toplu_ekle($v, 1, db());
ok('taslak hakediş → eklenir, needs_recalculation=1, mevcut mesai (yeni değil)', !empty($tf['ok']) && $tf['yeni_mesai'] === false
    && (int)db()->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=' . (int)$fin['session_id'])->fetchColumn() === 1, j($tf));

echo "\n=== 7. Listele + geri al ===\n";
$ls = pdks_faz8j_toplu_listele((int)$tp['session_id'], db());
ok('listele: 1 toplu, 7 aktif, 0 iptal, kullanıcı adı', count($ls) === 1 && $ls[0]['toplu_id'] === $tp['toplu_id'] && $ls[0]['aktif'] === 7 && $ls[0]['iptal'] === 0 && $ls[0]['kartsiz'] === 3 && $ls[0]['kullanici'] === 'test' && !$ls[0]['geri_alindi'], j($ls));
$ilk = (int)$tp['period_ids'][0];
ok('tekil iptal (hazırlık)', !empty(pdks_faz8j_void($ilk, (int)$tp['session_id'], 'Depo A', 'tek iptal', 1, db())['ok']));
$ADMIN = false;
$r = pdks_faz8j_toplu_geri_al($tp['toplu_id'], 'geri al', 1, db());
ok('geri al: yönetici değil → reddedilir', empty($r['ok']), j($r));
$ADMIN = true;
$r = pdks_faz8j_toplu_geri_al($tp['toplu_id'], '', 1, db());
ok('geri al: sebep boş → reddedilir', empty($r['ok']), j($r));
$r = pdks_faz8j_toplu_geri_al('TP20990101deadbeef', 'x', 1, db());
ok('geri al: olmayan toplu id → bulunamadı', empty($r['ok']) && str_contains((string)$r['hata'], 'bulunamadı'), j($r));
$AKTIF_DEPO = 'Depo B';
$r = pdks_faz8j_toplu_geri_al($tp['toplu_id'], 'x', 1, db());
ok('geri al: başka aktif depo → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'aktif depo'), j($r));
$AKTIF_DEPO = 'Depo A';
db()->exec("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, status) VALUES (" . (int)$tp['session_id'] . ", {$cavus['D']}, 'final')");
$once = sayimlar();
$r = pdks_faz8j_toplu_geri_al($tp['toplu_id'], 'x', 1, db());
ok('geri al: kesinleşmiş hakediş → reddedilir, hiçbir dönem iptal edilmedi', empty($r['ok']) && str_contains((string)$r['hata'], 'kesinleşmiş') && sayimlar() === $once, j($r));
db()->exec("UPDATE foreman_daily_entitlements SET status='draft' WHERE session_id=" . (int)$tp['session_id']);
$r = pdks_faz8j_toplu_geri_al($tp['toplu_id'], 'Yanlış çavuşa girildi', 1, db());
ok('geri al: 6 iptal, 1 atlandı (zaten iptal)', !empty($r['ok']) && $r['iptal_edilen'] === 6 && $r['atlanan'] === 1, j($r));
$aktif = (int)db()->query('SELECT COUNT(*) FROM daily_worker_work_periods WHERE is_voided=0 AND id IN (' . implode(',', $tp['period_ids']) . ')')->fetchColumn();
ok('toplu işlemin aktif dönemi kalmadı', $aktif === 0, (string)$aktif);
$iptalA = db()->query("SELECT new_values FROM audit_log WHERE action='puantaj_iptal'")->fetchAll(PDO::FETCH_COLUMN);
ok('her iptal satırı audit puantaj_iptal + toplu_id', count(array_filter($iptalA, fn($x) => (json_decode($x, true)['toplu_id'] ?? '') === $tp['toplu_id'])) === 6);
ok('özet audit puantaj_toplu_geri_al (mesai modülü)', (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='puantaj_toplu_geri_al' AND module='daily_work_sessions' AND record_id=" . (int)$tp['session_id'])->fetchColumn() === 1);
$ls = pdks_faz8j_toplu_listele((int)$tp['session_id'], db());
ok('listele: 0 aktif, 7 iptal, geri_alindi=true', $ls[0]['aktif'] === 0 && $ls[0]['iptal'] === 7 && $ls[0]['geri_alindi'] === true, j($ls));
$r = pdks_faz8j_toplu_geri_al($tp['toplu_id'], 'tekrar', 1, db());
ok('ikinci geri al → hepsi zaten iptal, reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'zaten'), j($r));
$dgT = pdks_gunluk_puantaj_denetim_gecmisi([], db(), 20, (int)$tp['session_id']);
ok('işlem geçmişi: "Toplu işlem geri alındı" etiketi', (bool)array_filter($dgT, fn($d) => str_contains($d['islem_etiket'], 'geri alındı')), j(array_column($dgT, 'islem_etiket')));


echo "\n=== 7b. Tekrar gönderim (istek_id), uyarı, girdi sağlamlığı, pasif çavuş, kayıp kart ===\n";
$dun4 = date('Y-m-d', strtotime('-4 day'));
$ist = bin2hex(random_bytes(16));
$tek = fn() => ekle(['foreman_id' => $cavus['H'], 'work_date' => $dun4, 'entry_date' => $dun4, 'exit_date' => $dun4, 'kartsiz' => 1, 'istek_id' => $ist]);
$a1 = $tek(); $once = sayimlar(); $a2 = $tek();
ok('tekil kartsız: aynı istek_id ikinci kez → "zaten kaydedildi", hiçbir şey yazılmadı', !empty($a1['ok']) && empty($a2['ok']) && !empty($a2['tekrar']) && str_contains((string)$a2['hata'], 'zaten kaydedildi') && sayimlar() === $once, j([$a1, $a2]));
$r = ekle(['foreman_id' => $cavus['H'], 'work_date' => $dun4, 'entry_date' => $dun4, 'exit_date' => $dun4, 'kartsiz' => 1, 'istek_id' => 'XYZ']);
ok('tekil: geçersiz istek_id biçimi → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'istek'), j($r));
$v = toplu(['foreman_id' => $cavus['H'], 'work_date' => $dun4]);
$v['gruplar'] = [['worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_date' => $dun4, 'exit_clock' => '17:00', 'kart_ids' => [], 'kartsiz_adet' => 3]];
$on = pdks_faz8j_toplu_onizle($v, 1, db());
ok('önizleme: aynı saatlerde kartsız KADIN zaten var → ENGELLEMEYEN uyarı', !empty($on['ok']) && count($on['uyarilar']) === 1 && str_contains($on['uyarilar'][0], '1 kartsız KADIN'), j($on['uyarilar'] ?? null));
$v2 = $v; $v2['gruplar'][0]['entry_clock'] = '09:00';
ok('farklı saat → uyarı yok', pdks_faz8j_toplu_onizle($v2, 1, db())['uyarilar'] === []);
$b1 = pdks_faz8j_toplu_ekle($v, 1, db()); $once = sayimlar(); $b2 = pdks_faz8j_toplu_ekle($v, 1, db());
ok('toplu (tamamı kartsız): aynı istek_id tekrar Kaydet → reddedilir, satır sayıları aynı', !empty($b1['ok']) && $b1['eklenen'] === 3 && empty($b2['ok']) && !empty($b2['tekrar']) && in_array('Bu işlem zaten kaydedildi (tekrar gönderim).', $b2['hatalar'], true) && sayimlar() === $once, j([$b1['ok'] ?? null, $b2['hatalar'] ?? null]));
$v3 = $v; unset($v3['istek_id']);
$r = pdks_faz8j_toplu_ekle($v3, 1, db());
ok('toplu: istek_id yok → reddedilir (zorunlu)', empty($r['ok']) && str_contains(implode(' ', $r['hatalar']), 'istek'), j($r['hatalar']));
ok('istek_id audit new_values içinde saklanır', (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='puantaj_toplu_ekle' AND new_values LIKE '%\"istek_id\":\"" . $v['istek_id'] . "\"%'")->fetchColumn() === 1);
// Bozuk (dizi) girdi — PHP uyarısı ÜRETMEMELİ
$uyari = [];
set_error_handler(function ($no, $str) use (&$uyari) { $uyari[] = $str; return true; });
$vb = toplu(['foreman_id' => $cavus['H'], 'work_date' => $dun4, 'reason' => ['x'], 'note' => ['y'], 'istek_id' => ['z']]);
$vb['gruplar'][0]['entry_clock'] = ['a']; $vb['gruplar'][0]['exit_date'] = ['b']; $vb['gruplar'][0]['exit_clock'] = ['c']; $vb['gruplar'][0]['kart_ids'] = [['d']]; $vb['gruplar'][0]['kartsiz_adet'] = ['e'];
$rb1 = pdks_faz8j_toplu_onizle($vb, 1, db()); $rb2 = pdks_faz8j_toplu_ekle($vb, 1, db());
$rb3 = ekle(['entry_clock' => ['x'], 'exit_clock' => ['y'], 'reason' => ['z'], 'worker_card_id' => ['w'], 'istek_id' => ['q'], 'kartsiz' => ['k']]);
restore_error_handler();
ok('dizi biçimli bozuk girdi: önizleme/ekleme/tekil reddedilir ve PHP uyarısı YOK', empty($rb1['ok']) && empty($rb2['ok']) && empty($rb3['ok']) && $uyari === [], j($uyari));
$src = (string)file_get_contents($ROOT . '/_puantaj_toplu_ajax.php');
ok('_puantaj_toplu_ajax.php skaler olmayan alanları güvenle çevirir (is_scalar) ve istek_id iletir', str_contains($src, 'is_scalar($x)') && str_contains($src, "'istek_id' =>"));
// Pasif çavuş — geçmiş gün yeni mesai
db()->exec("UPDATE foremen SET is_active = 0 WHERE id = {$cavus['G']}");
$once = sayimlar();
$r = ekle(['foreman_id' => $cavus['G'], 'work_date' => $dun4, 'entry_date' => $dun4, 'exit_date' => $dun4, 'worker_card_id' => $kartlar['K009']]);
$vt = toplu(['foreman_id' => $cavus['G'], 'work_date' => $dun4]);
$t1 = pdks_faz8j_toplu_onizle($vt, 1, db());
ok('pasif çavuş, geçmiş gün (yeni mesai) → tekil ve toplu reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'pasif') && empty($t1['ok']) && str_contains(implode(' ', $t1['hatalar']), 'pasif') && sayimlar() === $once, j([$r, $t1['hatalar']]));
db()->exec("UPDATE foremen SET is_active = 1 WHERE id = {$cavus['G']}");
// Kayıp kart — çıkışlı (kapalı) satırda da reddedilir
$r = ekle(['foreman_id' => $cavus['H'], 'work_date' => $dun4, 'entry_date' => $dun4, 'exit_date' => $dun4, 'worker_card_id' => $kartlar['K012']]);
ok('KAYIP fiziksel kart, çıkışlı geçmiş gün satırı → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'KAYIP'), j($r));
db()->exec("UPDATE worker_cards SET status='disabled' WHERE id = " . $kartlar['K011']);
$vt = toplu(['foreman_id' => $cavus['H'], 'work_date' => $dun4]); $vt['gruplar'][0]['kart_ids'] = [$kartlar['K011']]; $vt['gruplar'][0]['kartsiz_adet'] = 0; $vt['gruplar'][1]['kart_ids'] = []; $vt['gruplar'][1]['kartsiz_adet'] = 1;
$t2 = pdks_faz8j_toplu_onizle($vt, 1, db());
ok('DEVRE DIŞI fiziksel kart toplu satırda → satır hatası', empty($t2['ok']) && str_contains(implode(' ', array_filter(array_column($t2['satirlar'], 'hata'))), 'DEVRE DIŞI'), j($t2['satirlar']));
db()->exec("UPDATE worker_cards SET status='available' WHERE id = " . $kartlar['K011']);

echo "\n=== 8. Kaynak kod kuralları (statik) ===\n";
$src = (string)file_get_contents($ROOT . '/config/pdks_faz8j.php');
ok('dönem INSERT tek yerde (pdks_faz8j_satir_yaz)', substr_count($src, 'INSERT INTO daily_worker_work_periods') === 1);
ok('olay INSERT tek yerde', substr_count($src, 'INSERT INTO daily_worker_card_events') === 1);
ok('kart okutma yazma fonksiyonları çağrılmaz', !preg_match('/faz8a_giris_kaydet|faz8a_cikis_kaydet|oturum_kaydet\(/', $src));
ok('bugün mesaisi kiosk yoluyla açılır', str_contains($src, 'pdks_gunluk_oturum_ac_veya_getir($foremanId, $user, $pdo)'));
$on = substr($src, strpos($src, 'function pdks_faz8j_toplu_onizle('), 900);
ok('yazma transaction\'ının İLK sorgusu mesai kilidi (tekil + toplu)', substr_count($src, "beginTransaction();\n        pdks_faz8j_oturum_kilitle(") === 2);
ok('kartsız kart no satır id\'sinden (yeniden deneme döngüsü yok)', str_contains($src, "str_pad((string)\$id, 6, '0', STR_PAD_LEFT)") && !str_contains($src, '$deneme'));
ok('önizleme transaction/yazma çağırmaz', !preg_match('/beginTransaction|_ekle\(|satir_yaz|kartsiz_kart_olustur/', substr($on, 0, strpos($on, "\n}") ?: 900)));

echo "\n" . ($fail ? "$fail HATA, $gecen geçti\n" : "Tümü geçti ($gecen)\n");
exit($fail ? 1 : 0);
