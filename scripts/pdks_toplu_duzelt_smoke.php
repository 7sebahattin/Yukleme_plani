<?php
// =========================================================
// scripts/pdks_toplu_duzelt_smoke.php — v302 KART HAREKETLERİ TOPLU DÜZENLE / TOPLU İPTAL
//
// config/pdks_faz8j.php: pdks_faz8j_toplu_duzelt(), pdks_faz8j_toplu_iptal(),
// pdks_faz8j_duzelt_satir() (tekil + toplu ortak çekirdek) ve
// _puantaj_toplu_duzelt_ajax.php (statik). Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_toplu_duzelt_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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

function hex(): string { return bin2hex(random_bytes(16)); }
function donem(int $pid): array { return db()->query('SELECT * FROM daily_worker_work_periods WHERE id = ' . $pid)->fetch() ?: []; }
/** Tüm dönem satırları + olay/audit sayısı + hakediş bayrakları — "hiçbir şey yazılmadı" karşılaştırması için. */
function anlik(): string {
    return j([db()->query('SELECT * FROM daily_worker_work_periods ORDER BY id')->fetchAll(), say('daily_worker_card_events'), say('audit_log'),
              db()->query('SELECT session_id, status, needs_recalculation FROM foreman_daily_entitlements ORDER BY id')->fetchAll()]);
}
function auditler(string $action): array {
    $st = db()->prepare('SELECT * FROM audit_log WHERE action = ? ORDER BY id'); $st->execute([$action]);
    return array_map(function ($r) { $r['nv'] = json_decode((string)$r['new_values'], true) ?: []; return $r; }, $st->fetchAll());
}

$kadin   = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek   = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$rampaci = (int)(pdks_gunluk_rampaci_tip_garanti(db())['id'] ?? 0);
$karisik = (int)(pdks_gunluk_karisik_tip_garanti(db())['id'] ?? 0);
$cavus = [];
foreach (['A', 'B', 'C', 'D'] as $i => $h) $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C00' . ($i + 1), 'name' => 'Çavuş ' . $h], 1, db())['id'];
$kartlar = [];
for ($i = 1; $i <= 12; $i++) {
    $no = sprintf('K%03d', $i);
    pdks_gunluk_kart_olustur(['card_no' => $no, 'ham_uid' => (string)(300000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
    $kartlar[$no] = (int)db()->query("SELECT id FROM worker_cards WHERE card_no='$no'")->fetchColumn();
}
$dun2 = date('Y-m-d', strtotime('-2 day'));
$dun3 = date('Y-m-d', strtotime('-3 day'));
$dun4 = date('Y-m-d', strtotime('-4 day'));
$ertesi = fn(string $d) => date('Y-m-d', strtotime($d . ' +1 day'));

/** Geçmiş güne kayıt ekler (mesaiyi de oluşturur); dönem id döner. */
function ekle(string $cav, string $gun, ?string $kart, int $tip, string $g = '08:00', string $c = '17:00'): int {
    global $cavus, $kartlar;
    $r = pdks_faz8j_gecmis_ekle(['foreman_id' => $cavus[$cav], 'work_date' => $gun, 'kartsiz' => $kart === null ? 1 : 0,
        'worker_card_id' => $kart === null ? 0 : $kartlar[$kart], 'worker_type_id' => $tip,
        'entry_date' => $gun, 'entry_clock' => $g, 'exit_date' => $gun, 'exit_clock' => $c, 'reason' => 'Kurulum', 'note' => '', 'depo' => 'Depo A'], 1, db());
    if (empty($r['ok'])) { fwrite(STDERR, 'Kurulum hatası: ' . j($r) . "\n"); exit(2); }
    return (int)$r['period_id'];
}
function sid(int $pid): int { return (int)donem($pid)['session_id']; }
function satir(int $pid, array $o = []): array {
    $p = donem($pid);
    return $o + ['period_id' => $pid, 'worker_type_id' => (int)$p['worker_type_id_snapshot'], 'entry_clock' => substr((string)$p['entry_time'], 11, 5),
        'exit_date' => $p['exit_time'] ? substr((string)$p['exit_time'], 0, 10) : '', 'exit_clock' => $p['exit_time'] ? substr((string)$p['exit_time'], 11, 5) : ''];
}
function onayla(array $pids): void {
    db()->exec("UPDATE daily_worker_work_periods SET approved_attendance_class='tam', approved_by_user_id=1, approved_at='2020-01-01 00:00:00', overtime_approved=1, overtime_approved_hours=2, overtime_approved_by_user_id=1, overtime_approved_at='2020-01-01 00:00:00' WHERE id IN (" . implode(',', $pids) . ')');
}

// --- Kurulum: Çavuş A / dün-2 mesaisi (5 kartlı + 1 kartsız), Çavuş B / dün-2 (başka mesai) ---
$pA1 = ekle('A', $dun2, 'K001', $kadin);
$pA2 = ekle('A', $dun2, 'K002', $kadin);
$pA3 = ekle('A', $dun2, 'K003', $erkek);
$pA4 = ekle('A', $dun2, 'K004', $erkek);
$pA5 = ekle('A', $dun2, 'K005', $kadin);
$pAk = ekle('A', $dun2, null, $kadin, '09:00', '16:00');
$sidA = sid($pA1);
$pB1 = ekle('B', $dun2, 'K006', $kadin);
$sidB = sid($pB1);
onayla([$pA1, $pA2, $pA3, $pA4, $pA5, $pAk]);
// Kiosk saniyesi: yalnız tip değişen satırda saniye korunmalı
db()->exec("UPDATE daily_worker_work_periods SET entry_time='$dun2 08:00:27', exit_time='$dun2 17:00:41' WHERE id=$pA2");
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, status, needs_recalculation) VALUES (?,?,'draft',0)")->execute([$sidA, $cavus['A']]);
ok('kurulum: A mesaisi 6 dönem, B ayrı mesai', $sidA > 0 && $sidB > 0 && $sidA !== $sidB && (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id=$sidA")->fetchColumn() === 6);

echo "\n=== 1. Başarılı toplu düzenleme ===\n";
$ist1 = hex();
$auditOnce = say('audit_log');
$r = pdks_faz8j_toplu_duzelt($sidA, [
    satir($pA1, ['worker_type_id' => $erkek]),                                        // tip
    satir($pA2, ['worker_type_id' => $rampaci]),                                      // yalnız tip (saniyeler korunmalı)
    satir($pA3, ['entry_clock' => '07:30', 'exit_clock' => '18:15']),                 // saat
    satir($pA4),                                                                      // değişmez → atlanır
], 'Toplu düzeltme testi', 'not', $ist1, 1, db());
ok('ok=true, guncellenen=3, atlanan=1, toplu_id TD…', !empty($r['ok']) && $r['guncellenen'] === 3 && $r['atlanan'] === 1 && preg_match('/^TD\d{8}[0-9a-f]{8}$/', (string)$r['toplu_id']) === 1, j($r));
$d1 = donem($pA1); $d2 = donem($pA2); $d3 = donem($pA3); $d4 = donem($pA4);
ok('A1 tipi Erkek, kart değişmedi', (int)$d1['worker_type_id_snapshot'] === $erkek && $d1['worker_type_name_snapshot'] === 'Erkek' && (int)$d1['worker_card_id'] === $kartlar['K001'], j($d1));
ok('A2 tipi Rampacı, giriş/çıkış SANİYESİ korundu', (int)$d2['worker_type_id_snapshot'] === $rampaci && $d2['entry_time'] === "$dun2 08:00:27" && $d2['exit_time'] === "$dun2 17:00:41", j($d2));
ok('A3 saatleri değişti (07:30–18:15), kapalı', $d3['entry_time'] === "$dun2 07:30:00" && $d3['exit_time'] === "$dun2 18:15:00" && $d3['status'] === 'closed', j($d3));
ok('değişen satırların Tam/Yarım + FM onayları sıfırlandı', $d1['approved_attendance_class'] === null && $d1['overtime_approved'] === null && $d1['overtime_approved_hours'] === null && $d3['approved_by_user_id'] === null);
ok('DEĞİŞMEYEN satır (A4) yazılmadı, onayları KORUNDU', $d4['approved_attendance_class'] === 'tam' && (int)$d4['overtime_approved_hours'] === 2 && (int)$d4['approved_by_user_id'] === 1, j($d4));
ok('taslak hakedişe needs_recalculation=1', (int)db()->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=$sidA")->fetchColumn() === 1);
$sat = array_values(array_filter(auditler('puantaj_duzeltme'), fn($a) => ($a['nv']['toplu_id'] ?? '') === $r['toplu_id']));
ok('satır audit puantaj_duzeltme × 3, toplu_id + reason + note taşır', count($sat) === 3 && $sat[0]['module'] === 'daily_worker_work_periods'
    && $sat[0]['nv']['reason'] === 'Toplu düzeltme testi' && $sat[0]['nv']['note'] === 'not' && array_key_exists('card_no', $sat[0]['nv']), j($sat[0]['nv'] ?? null));
ok('satır audit record_id = period id, eski değerler old_values\'ta', in_array($pA1, array_map(fn($a) => (int)$a['record_id'], $sat), true)
    && (json_decode((string)$sat[0]['old_values'], true)['worker_type_name_snapshot'] ?? '') !== '');
$oz = auditler('puantaj_toplu_duzeltme');
ok('özet audit puantaj_toplu_duzeltme: modül daily_work_sessions, record_id = mesai, istek_id/toplu_id/period_ids/reason',
    count($oz) === 1 && $oz[0]['module'] === 'daily_work_sessions' && (int)$oz[0]['record_id'] === $sidA && $oz[0]['nv']['istek_id'] === $ist1
    && $oz[0]['nv']['toplu_id'] === $r['toplu_id'] && $oz[0]['nv']['period_ids'] === [$pA1, $pA2, $pA3] && $oz[0]['nv']['reason'] === 'Toplu düzeltme testi', j($oz[0]['nv'] ?? null));
ok('toplam 4 audit satırı (3 satır + 1 özet)', say('audit_log') === $auditOnce + 4);

echo "\n=== 2. Tekrar gönderim (aynı istek_id) ===\n";
$on = anlik();
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], 'Tekrar', '', $ist1, 1, db());
ok('aynı istek_id → ret, tekrar=true, hiçbir şey yazılmadı', empty($r['ok']) && !empty($r['tekrar']) && str_contains((string)$r['hata'], 'tekrar') && anlik() === $on, j($r));
$r = pdks_faz8j_toplu_iptal($sidA, [$pA5], 'Tekrar', $ist1, 1, db());
ok('düzenlemenin istek_id\'si toplu iptalde de tekrar sayılır', empty($r['ok']) && !empty($r['tekrar']) && anlik() === $on, j($r));

echo "\n=== 3. Hatalı satır → HEP-YA-HİÇ ===\n";
$on = anlik();
$r = pdks_faz8j_toplu_duzelt($sidA, [
    satir($pA5, ['worker_type_id' => $erkek]),                                        // geçerli
    satir($pA4, ['entry_clock' => '10:00', 'exit_clock' => '09:00']),                 // çıkış < giriş
    satir($pA1, ['entry_clock' => '25:00']),                                          // bozuk saat
], 'Hatalı', '', hex(), 1, db());
$hp = array_column($r['hatalar'] ?? [], 'period_id');
ok('ok=false, özet hata + satır satır 2 hata', empty($r['ok']) && str_contains((string)$r['hata'], '2 satırda hata') && count($r['hatalar']) === 2 && in_array($pA4, $hp, true) && in_array($pA1, $hp, true), j($r));
$h4 = array_values(array_filter($r['hatalar'], fn($h) => $h['period_id'] === $pA4))[0] ?? [];
ok('satır hatası card_no + mesaj taşır (zaman kuralı)', ($h4['card_no'] ?? '') === 'K004' && str_contains((string)$h4['hata'], '24 saat'), j($h4));
ok('geçerli satır (A5) DAHİL hiçbir şey yazılmadı', anlik() === $on && (int)donem($pA5)['worker_type_id_snapshot'] === $kadin);
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek]), satir($pB1, ['worker_type_id' => $erkek])], 'Başka mesai', '', hex(), 1, db());
ok('başka mesainin period_id\'si → satır hatası, hiçbir şey yazılmadı', empty($r['ok']) && ($r['hatalar'][0]['period_id'] ?? 0) === $pB1 && str_contains((string)$r['hatalar'][0]['hata'], 'bulunamadı') && anlik() === $on, j($r));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $karisik])], 'Karışık hedef', '', hex(), 1, db());
ok('hedef tip Karışık → satır hatası (desteklenmiyor)', empty($r['ok']) && str_contains((string)($r['hatalar'][0]['hata'] ?? ''), 'desteklenmiyor') && anlik() === $on, j($r));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => 0])], 'Tip yok', '', hex(), 1, db());
ok('tip boş → satır hatası', empty($r['ok']) && count($r['hatalar']) === 1 && anlik() === $on, j($r));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['exit_date' => '', 'exit_clock' => '18:00'])], 'Yarım çıkış', '', hex(), 1, db());
ok('çıkış tarihi/saati yarım → satır hatası', empty($r['ok']) && str_contains((string)($r['hatalar'][0]['hata'] ?? ''), 'birlikte') && anlik() === $on, j($r));

echo "\n=== 4. Kapılar ===\n";
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA4), satir($pA5)], 'Değişiklik yok', '', hex(), 1, db());
ok('hiçbir satır değişmediyse → "değişiklik yok", audit yazılmaz', empty($r['ok']) && str_contains((string)$r['hata'], 'değişiklik yok') && anlik() === $on, j($r));
$ADMIN = false;
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], 'Yetkisiz', '', hex(), 1, db());
$r2 = pdks_faz8j_toplu_iptal($sidA, [$pA5], 'Yetkisiz', hex(), 1, db());
ok('admin olmayan → ret (düzenle + iptal)', empty($r['ok']) && empty($r2['ok']) && str_contains((string)$r['hata'], 'yönetici') && anlik() === $on, j([$r, $r2]));
$ADMIN = true;
$AKTIF_DEPO = 'Depo B';
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], 'Depo', '', hex(), 1, db());
$r2 = pdks_faz8j_toplu_iptal($sidA, [$pA5], 'Depo', hex(), 1, db());
ok('yanlış aktif depo → ret (düzenle + iptal)', empty($r['ok']) && empty($r2['ok']) && str_contains((string)$r['hata'], 'aktif depo') && anlik() === $on, j([$r, $r2]));
$AKTIF_DEPO = 'Depo A';
$cok = []; for ($i = 1; $i <= PDKS_FAZ8J_TOPLU_LIMIT + 1; $i++) $cok[] = ['period_id' => 100000 + $i, 'worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_date' => '', 'exit_clock' => ''];
$r = pdks_faz8j_toplu_duzelt($sidA, $cok, 'Limit', '', hex(), 1, db());
$r2 = pdks_faz8j_toplu_iptal($sidA, array_column($cok, 'period_id'), 'Limit', hex(), 1, db());
ok('limit (' . PDKS_FAZ8J_TOPLU_LIMIT . ') üstü → ret (düzenle + iptal)', empty($r['ok']) && empty($r2['ok']) && str_contains((string)$r['hata'], 'en fazla') && str_contains((string)$r2['hata'], 'en fazla'), j([$r['hata'] ?? '', $r2['hata'] ?? '']));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek]), satir($pA5)], 'Yinelenen', '', hex(), 1, db());
$r2 = pdks_faz8j_toplu_iptal($sidA, [$pA5, $pA5], 'Yinelenen', hex(), 1, db());
ok('yinelenen period_id → ret', empty($r['ok']) && empty($r2['ok']) && str_contains((string)$r['hata'], 'birden fazla'), j([$r, $r2]));
$r = pdks_faz8j_toplu_duzelt($sidA, [], 'Boş', '', hex(), 1, db());
$r2 = pdks_faz8j_toplu_iptal($sidA, [], 'Boş', hex(), 1, db());
ok('boş liste → ret', empty($r['ok']) && empty($r2['ok']) && str_contains((string)$r['hata'], 'En az bir'), j([$r, $r2]));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], '  ', '', hex(), 1, db());
$r2 = pdks_faz8j_toplu_iptal($sidA, [$pA5], '', hex(), 1, db());
ok('neden zorunlu (düzenle + iptal mesajları)', empty($r['ok']) && str_contains((string)$r['hata'], 'Düzeltme nedeni') && empty($r2['ok']) && str_contains((string)$r2['hata'], 'İptal nedeni'), j([$r, $r2]));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], str_repeat('a', 501), '', hex(), 1, db());
ok('neden > 500 karakter → ret', empty($r['ok']), j($r));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], 'Uzun not', str_repeat('n', 1001), hex(), 1, db());
ok('açıklama > 1000 karakter → ret', empty($r['ok']) && str_contains((string)$r['hata'], '1000'), j($r));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], 'İstek', '', 'XYZ', 1, db());
$r2 = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], 'İstek', '', '', 1, db());
ok('geçersiz / eksik istek_id → ret', empty($r['ok']) && empty($r2['ok']) && str_contains((string)$r['hata'], 'istek anahtarı'), j([$r, $r2]));
$r = pdks_faz8j_toplu_duzelt(999999, [satir($pA5, ['worker_type_id' => $erkek])], 'Mesai yok', '', hex(), 1, db());
ok('olmayan mesai → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'Mesai bulunamadı'), j($r));
$r = pdks_faz8j_toplu_duzelt($sidA, [['period_id' => [1], 'worker_type_id' => ['x'], 'entry_clock' => ['a'], 'exit_date' => null, 'exit_clock' => new stdClass()]], 'Skaler', '', hex(), 1, db());
ok('skaler olmayan alanlar uyarısız 0/\'\' sayılır → ret', empty($r['ok']) && anlik() === $on, j($r));
ok('kapı retlerinde hiçbir şey yazılmadı', anlik() === $on);

echo "\n=== 5. Kesin hakediş ===\n";
db()->exec("UPDATE foreman_daily_entitlements SET status='final' WHERE session_id=$sidA");
$on = anlik();
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pA5, ['worker_type_id' => $erkek])], 'Kesin', '', hex(), 1, db());
$r2 = pdks_faz8j_toplu_iptal($sidA, [$pA5], 'Kesin', hex(), 1, db());
ok('kesinleşmiş hakediş → ret (düzenle + iptal), hiçbir şey yazılmadı', empty($r['ok']) && empty($r2['ok']) && str_contains((string)$r['hata'], 'kesinleşmiş') && str_contains((string)$r2['hata'], 'kesinleşmiş') && anlik() === $on, j([$r, $r2]));
db()->exec("UPDATE foreman_daily_entitlements SET status='draft', needs_recalculation=0 WHERE session_id=$sidA");

echo "\n=== 6. Kartsız dönem ===\n";
$on = anlik();
$kartsizKart = (int)donem($pAk)['worker_card_id'];
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pAk, ['exit_date' => '', 'exit_clock' => ''])], 'Kartsız çıkış sil', '', hex(), 1, db());
ok('kartsız dönemde çıkışı silmek → satır hatası (çıkış zorunlu)', empty($r['ok']) && str_contains((string)($r['hatalar'][0]['hata'] ?? ''), 'Kartsız') && ($r['hatalar'][0]['card_no'] ?? '') === db()->query('SELECT card_no FROM worker_cards WHERE id = ' . $kartsizKart)->fetchColumn() && anlik() === $on, j($r));
$r = pdks_faz8j_toplu_duzelt($sidA, [satir($pAk, ['worker_type_id' => $erkek, 'exit_clock' => '16:30'])], 'Kartsız tip', '', hex(), 1, db());
$dk = donem($pAk);
ok('kartsız dönemde tip + çıkış değişir, sanal kart AYNI kalır', !empty($r['ok']) && (int)$dk['worker_card_id'] === $kartsizKart && (int)$dk['worker_type_id_snapshot'] === $erkek && $dk['exit_time'] === "$dun2 16:30:00", j([$r, $dk]));

echo "\n=== 7. +1 gün çıkış, 24 saat kuralı, çıkışsız dönem ===\n";
$pC1 = ekle('C', $dun4, 'K007', $kadin);
$pC2 = ekle('C', $dun4, 'K008', $kadin);
$sidC = sid($pC1);
$r = pdks_faz8j_toplu_duzelt($sidC, [satir($pC1, ['entry_clock' => '20:00', 'exit_date' => $ertesi($dun4), 'exit_clock' => '04:00'])], 'Gece', '', hex(), 1, db());
$dc = donem($pC1);
ok('+1 gün çıkış kabul (20:00 → ertesi 04:00), giriş günü mesai günü', !empty($r['ok']) && $dc['entry_time'] === "$dun4 20:00:00" && $dc['exit_time'] === $ertesi($dun4) . ' 04:00:00', j([$r, $dc]));
$on = anlik();
$r = pdks_faz8j_toplu_duzelt($sidC, [satir($pC2, ['entry_clock' => '08:00', 'exit_date' => $ertesi($dun4), 'exit_clock' => '09:00'])], '24 saat', '', hex(), 1, db());
ok('24 saati aşan çıkış → satır hatası, hiçbir şey yazılmadı', empty($r['ok']) && str_contains((string)($r['hatalar'][0]['hata'] ?? ''), '24 saat') && anlik() === $on, j($r));
// Çıkışı olmayan (legacy) dönem: çıkış eklenince manuel ÇIKIŞ olayı yazılır (tekil çekirdekle aynı)
db()->exec("UPDATE daily_worker_work_periods SET exit_time=NULL, exit_event_id=NULL, status='legacy_unresolved' WHERE id=$pC2");
$olayOnce = say('daily_worker_card_events');
$r = pdks_faz8j_toplu_duzelt($sidC, [satir($pC2, ['exit_date' => $dun4, 'exit_clock' => '16:00'])], 'Çıkış ekle', '', hex(), 1, db());
$dc2 = donem($pC2);
ok('çıkışsız dönemde çıkış eklenir: manuel olay + kapalı', !empty($r['ok']) && say('daily_worker_card_events') === $olayOnce + 1 && $dc2['status'] === 'closed' && (int)$dc2['exit_event_id'] > 0, j([$r, $dc2]));

echo "\n=== 8. Toplu iptal ===\n";
$pD1 = ekle('D', $dun3, 'K009', $kadin);
$pD2 = ekle('D', $dun3, 'K010', $kadin);
$pD3 = ekle('D', $dun3, 'K011', $erkek);
$sidD = sid($pD1);
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, status, needs_recalculation) VALUES (?,?,'draft',0)")->execute([$sidD, $cavus['D']]);
onayla([$pD1, $pD2]);
$r = pdks_faz8j_void($pD3, $sidD, 'Depo A', 'Önceden iptal', 1, db());
ok('kurulum: D3 tekil iptal edildi', !empty($r['ok']), j($r));
db()->exec("UPDATE foreman_daily_entitlements SET needs_recalculation=0 WHERE session_id=$sidD");
$on = anlik();
$r = pdks_faz8j_toplu_iptal($sidD, [$pD1, $pB1], 'Başka mesai', hex(), 1, db());
ok('başka mesainin dönemi → satır hatası, hiçbir şey iptal edilmedi', empty($r['ok']) && ($r['hatalar'][0]['period_id'] ?? 0) === $pB1 && anlik() === $on, j($r));
$istI = hex();
$auditOnce = say('audit_log');
$r = pdks_faz8j_toplu_iptal($sidD, [$pD2, $pD1, $pD3], 'Toplu iptal testi', $istI, 1, db());
ok('ok=true, iptal_edilen=2, atlanan=1 (zaten iptal)', !empty($r['ok']) && $r['iptal_edilen'] === 2 && $r['atlanan'] === 1 && preg_match('/^TI\d{8}[0-9a-f]{8}$/', (string)$r['toplu_id']) === 1, j($r));
$e1 = donem($pD1);
ok('iptal edilen dönem: is_voided=1, neden, onaylar sıfır', (int)$e1['is_voided'] === 1 && $e1['void_reason'] === 'Toplu iptal testi' && $e1['approved_attendance_class'] === null && $e1['overtime_approved_hours'] === null && (int)donem($pD2)['is_voided'] === 1, j($e1));
ok('taslak hakedişe needs_recalculation=1', (int)db()->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=$sidD")->fetchColumn() === 1);
$ia = array_values(array_filter(auditler('puantaj_iptal'), fn($a) => ($a['nv']['toplu_id'] ?? '') === $r['toplu_id']));
$io = auditler('puantaj_toplu_iptal');
ok('satır audit puantaj_iptal × 2 (toplu_id) + özet puantaj_toplu_iptal (istek_id, record_id = mesai)', count($ia) === 2 && count($io) === 1
    && $io[0]['module'] === 'daily_work_sessions' && (int)$io[0]['record_id'] === $sidD && $io[0]['nv']['istek_id'] === $istI
    && $io[0]['nv']['period_ids'] === [$pD1, $pD2] && $io[0]['nv']['atlanan'] === 1 && say('audit_log') === $auditOnce + 3, j($io[0]['nv'] ?? null));
$on = anlik();
$r = pdks_faz8j_toplu_iptal($sidD, [$pD1], 'Tekrar', $istI, 1, db());
ok('aynı istek_id → tekrar ret', empty($r['ok']) && !empty($r['tekrar']) && anlik() === $on, j($r));
$r = pdks_faz8j_toplu_iptal($sidD, [$pD1, $pD3], 'Hepsi iptal', hex(), 1, db());
ok('hepsi zaten iptal → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'zaten iptal') && anlik() === $on, j($r));
$r = pdks_faz8j_toplu_duzelt($sidD, [satir($pD1, ['worker_type_id' => $erkek])], 'İptal edilmiş düzenle', '', hex(), 1, db());
ok('iptal edilmiş dönem düzenlenemez → satır hatası', empty($r['ok']) && str_contains((string)($r['hatalar'][0]['hata'] ?? ''), 'iptal') && anlik() === $on, j($r));

echo "\n=== 9. Tekil düzelt (ortak çekirdek) davranışı değişmedi ===\n";
$r = pdks_faz8j_duzelt(['period_id' => $pA5, 'session_id' => $sidA, 'depo' => 'Depo A', 'worker_card_id' => $kartlar['K012'], 'worker_type_id' => $erkek,
    'entry_date' => $dun2, 'entry_clock' => '08:00', 'exit_date' => $dun2, 'exit_clock' => '17:00', 'reason' => 'Tekil', 'note' => ''], 1, db());
$d5 = donem($pA5); $tekilAudit = auditler('puantaj_duzeltme'); $tekilAudit = end($tekilAudit);
ok('tekil düzelt kartı taşır (K012), audit toplu_id TAŞIMAZ', !empty($r['ok']) && (int)$d5['worker_card_id'] === $kartlar['K012'] && !array_key_exists('toplu_id', $tekilAudit['nv']), j($r));
$r = pdks_faz8j_duzelt(['period_id' => $pAk, 'session_id' => $sidA, 'depo' => 'Depo A', 'worker_card_id' => $kartsizKart, 'worker_type_id' => $erkek,
    'entry_date' => $dun2, 'entry_clock' => '09:00', 'exit_date' => '', 'exit_clock' => '', 'reason' => 'Tekil', 'note' => ''], 1, db());
ok('tekil: kartsız çıkış silme → aynı mesaj', $r === ['ok' => false, 'hata' => 'Kartsız mesai kaydında çıkış zamanı zorunludur.'], j($r));
$r = pdks_faz8j_duzelt(['period_id' => $pB1, 'session_id' => $sidA, 'depo' => 'Depo A', 'worker_card_id' => $kartlar['K006'], 'worker_type_id' => $erkek,
    'entry_date' => $dun2, 'entry_clock' => '08:00', 'exit_date' => $dun2, 'exit_clock' => '17:00', 'reason' => 'Tekil', 'note' => ''], 1, db());
ok('tekil: başka mesainin dönemi → aynı mesaj', $r === ['ok' => false, 'hata' => 'Mesai dönemi bulunamadı veya iptal edilmiş.'], j($r));

echo "\n=== 10. Kaynak kod kuralları (statik) ===\n";
$src = (string)file_get_contents($ROOT . '/config/pdks_faz8j.php');
$govde = function (string $fn) use ($src): string { $i = strpos($src, "function $fn("); if ($i === false) return ''; $j = strpos($src, "\n}\n", $i); return substr($src, $i, $j - $i); };
$td = $govde('pdks_faz8j_toplu_duzelt'); $ti = $govde('pdks_faz8j_toplu_iptal'); $tk = $govde('pdks_faz8j_duzelt');
ok('toplu_duzelt gövdesinde "UPDATE daily_worker_work_periods" YOK, duzelt_satir çağrılır', $td !== '' && !str_contains($td, 'UPDATE daily_worker_work_periods') && str_contains($td, 'pdks_faz8j_duzelt_satir('));
ok('toplu_iptal gövdesinde UPDATE YOK, void_uygula çağrılır', $ti !== '' && !str_contains($ti, 'UPDATE daily_worker_work_periods') && str_contains($ti, 'pdks_faz8j_void_uygula('));
ok('tekil duzelt UPDATE yazmaz, duzelt_satir çağrılır', $tk !== '' && !str_contains($tk, 'UPDATE daily_worker_work_periods') && str_contains($tk, 'pdks_faz8j_duzelt_satir('));
ok('düzeltme UPDATE\'i dosyada TEK yerde (duzelt_satir)', substr_count($src, 'UPDATE daily_worker_work_periods SET worker_card_id=') === 1 && str_contains($govde('pdks_faz8j_duzelt_satir'), 'UPDATE daily_worker_work_periods SET worker_card_id='));
ok('toplu düzenle/iptal: transaction\'ın İLK sorgusu mesai kilidi', (bool)preg_match('/beginTransaction\(\);\s*\$oturum = pdks_faz8j_mesai_kilitle\(/', $td) && (bool)preg_match('/beginTransaction\(\);\s*if \(!pdks_faz8j_mesai_kilitle\(/', $ti));
ok('toplu düzenle kartı istemciden ALMAZ (dönemin worker_card_id\'si)', str_contains($td, "(int)\$p['worker_card_id']") && !str_contains($td, "'worker_card_id' =>"));
$ax = (string)file_get_contents($ROOT . '/_puantaj_toplu_duzelt_ajax.php');
ok('ajax partial: isset kapısı + 404, fonksiyon TANIMLAMAZ', str_contains($ax, 'if (!isset($pdo, $auth_user, $id, $tdAjaxKapi)) { http_response_code(404); exit; }') && !preg_match('/^\s*function\s/m', $ax));
ok('ajax partial: CSRF + yönetici + kapı 403 + is_scalar', str_contains($ax, 'csrf_check(') && str_contains($ax, 'pdks_faz8j_yetki()') && str_contains($ax, 'empty($tdAjaxKapi)') && str_contains($ax, '403') && str_contains($ax, 'is_scalar($x)'));
ok('ajax partial: uçlar toplu_duzelt / toplu_iptal, mesai id sunucudan ($id), istemciden session_id yok', str_contains($ax, "['toplu_duzelt', 'toplu_iptal']") && str_contains($ax, '$__tdSid = (int)$id;') && !str_contains($ax, "['session_id']"));
ok('ajax partial: başarıda set_flash + redirect', str_contains($ax, 'set_flash(') && str_contains($ax, "'redirect'] = 'gunluk_isci_puantaj_detay.php?id='"));

echo "\n" . ($fail ? "$fail HATA, $gecen geçti\n" : "Tümü geçti ($gecen)\n");
exit($fail ? 1 : 0);
