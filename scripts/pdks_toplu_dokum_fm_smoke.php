<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_toplu_dokum_fm_smoke.php — v407 Çavuş Toplu Döküm Fazla Mesai (FM) testi.
//
// pdks_faz8b_ay_fm_dagilimi() / pdks_faz8b_fm_dagilim_metni() / pdks_rapor_cavus_toplu_dokum()
// satırlarındaki fm_* alanları. FM hesabı TEK yerde (sınıflandırıcı); bu test oturum başına
// pdks_faz8b_gun_mesai_ozeti() ile birebir eşitliği, ek sorgu sayısının oturum sayısından
// bağımsızlığını ve kaynak taramasını doğrular. Bellek içi SQLite; canlı DB'ye dokunmaz.
//
//   php scripts/pdks_toplu_dokum_fm_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function okfm(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

class SayPDO extends PDO {
    public int $say = 0;
    #[\ReturnTypeWillChange]
    public function prepare(string $q, array $o = []) { $this->say++; return parent::prepare($q, $o); }
    #[\ReturnTypeWillChange]
    public function query(string $q, ?int $f = null, ...$a) { $this->say++; return $f === null ? parent::query($q) : parent::query($q, $f, ...$a); }
}
$db = new SayPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function active_depot(): ?string { return 'Depo A'; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_servis.php';
require_once $root . '/config/pdks_cari.php';
require_once $root . '/config/pdks_rapor.php';

$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('ERKEK','Erkek'), ('KARISIK','Karışık')");
$kadin = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)$db->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$karisik = (int)$db->query("SELECT id FROM worker_types WHERE code='KARISIK'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C1','Çavuş 1'), (2,'C2','Çavuş 2')");

function fmSession(string $tarih, int $foreman = 1, string $depo = 'Depo A', int $snap = 540): int {
    global $db;
    $db->prepare("INSERT INTO daily_work_sessions (foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,?,'closed')")
       ->execute([$foreman, 'Çavuş ' . $foreman, 'C' . $foreman, $snap, $tarih, $depo]);
    return (int)$db->lastInsertId();
}
/** $dk null → çıkışsız; $fm onaylı FM saati; $tip: id; $ad snapshot adı. */
function fmDonem(int $sid, string $tarih, int $tip, string $ad, ?int $dk, ?int $fm = null, ?string $sinifOnay = null): void {
    global $db;
    static $n = 0; $n++;
    $db->prepare("INSERT INTO worker_cards (card_no) VALUES (?)")->execute(['F' . $n]);
    $kart = (int)$db->lastInsertId();
    $g = strtotime("{$tarih} 06:00:00");
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status,overtime_approved_hours,approved_attendance_class,is_voided) VALUES (?,?,?,?,?,?,?,?,?,0)")
       ->execute([$sid, $kart, $tip, $ad, date('Y-m-d H:i:s', $g), $dk === null ? null : date('Y-m-d H:i:s', $g + $dk * 60),
                  $dk === null ? 'open' : 'closed', $fm, $sinifOnay]);
}

$ay = '2026-03'; $d1 = '2026-03-10'; $d2 = '2026-03-31'; $onceki = '2026-02-28'; $sonraki = '2026-04-01';
$sA = fmSession($d1);                       // Çavuş 1
fmDonem($sA, $d1, $kadin, 'Kadın', 540);                 // FM yok
fmDonem($sA, $d1, $kadin, 'Kadın', 600, 1);              // onaylı 1 sa
fmDonem($sA, $d1, $kadin, 'Kadın', 600, 1);              // onaylı 1 sa
fmDonem($sA, $d1, $erkek, 'Erkek', 660, 2);              // onaylı 2 sa
fmDonem($sA, $d1, $erkek, 'Erkek', 660, 0);              // reddedilen → sayılmaz
fmDonem($sA, $d1, $kadin, 'Kadın', 660);                 // onaysız bekliyor → aday saat gösterilir (özetle aynı)
fmDonem($sA, $d1, $karisik, 'Karışık', 700, 2);          // Karışık hariç
fmDonem($sA, $d1, $kadin, 'Kadın', null);                // çıkışsız hariç
$sB = fmSession($d1, 2);                    // Çavuş 2: FM yok
fmDonem($sB, $d1, $kadin, 'Kadın', 540);
$sC = fmSession($d2, 1);                    // ay sonu
fmDonem($sC, $d2, $erkek, 'Erkek', 600, 1);
$sO = fmSession($onceki, 1); fmDonem($sO, $onceki, $kadin, 'Kadın', 660, 2);     // önceki ay
$sS = fmSession($sonraki, 1); fmDonem($sS, $sonraki, $kadin, 'Kadın', 660, 2);   // sonraki ay
$sD = fmSession($d1, 1, 'Depo B'); fmDonem($sD, $d1, $kadin, 'Kadın', 660, 2);   // başka depo

echo "=== 1. Oturum başına FM = gun_mesai_ozeti ===\n";
$dag = pdks_faz8b_ay_fm_dagilimi('2026-03-01', '2026-03-31', null, null, $db);
$hepsi = true; $detay = '';
foreach ([$sA, $sB, $sC, $sD] as $sid) {
    $oz = pdks_faz8b_gun_mesai_ozeti([$sid], $db);
    $beklenen = (int)$oz['fm_saat'];
    $gelen = (int)($dag[$sid]['saat'] ?? 0);
    if ($beklenen !== $gelen) { $hepsi = false; $detay .= " sid=$sid beklenen=$beklenen gelen=$gelen"; }
    $kisiBek = array_sum(array_map(fn($t) => array_sum($t['fm']), $oz['tanim']));
    if ($kisiBek !== (int)($dag[$sid]['kisi'] ?? 0)) { $hepsi = false; $detay .= " kisi sid=$sid"; }
}
okfm('1a) fm_saat/kisi her oturumda gun_mesai_ozeti ile aynı', $hepsi, $detay);
okfm('1b) A oturumu dağılım: 1 sa:2 · 2 sa:2 (onaysız 11 sa aday 2 sa dahil)', ($dag[$sA]['dagilim'] ?? null) === [1 => 2, 2 => 2], json_encode($dag[$sA] ?? null));
okfm('1c) reddedilen / Karışık / çıkışsız sayılmaz (A: kişi 4, saat 6)', ($dag[$sA]['kisi'] ?? 0) === 4 && ($dag[$sA]['saat'] ?? 0) === 6, json_encode($dag[$sA] ?? null));
okfm('1d) FM yok → oturum anahtarı yok', !isset($dag[$sB]));
okfm('1e) ay sınırı: 31 Mart dahil, 28 Şubat/1 Nisan hariç', isset($dag[$sC]) && !isset($dag[$sO]) && !isset($dag[$sS]));
$dagDepo = pdks_faz8b_ay_fm_dagilimi('2026-03-01', '2026-03-31', 'Depo A', null, $db);
okfm('1f) depo filtresi', isset($dagDepo[$sA]) && !isset($dagDepo[$sD]));
$dagCav = pdks_faz8b_ay_fm_dagilimi('2026-03-01', '2026-03-31', null, 2, $db);
okfm('1g) çavuş filtresi', !isset($dagCav[$sA]) && !isset($dagCav[$sC]) && $dagCav === []);
okfm('1h) metin biçimi', pdks_faz8b_fm_dagilim_metni([2 => 3, 1 => 15]) === '1 sa: 15 kişi · 2 sa: 3 kişi' && pdks_faz8b_fm_dagilim_metni([]) === '—');

echo "\n=== 2. Çift gün ===\n";
$sX = fmSession('2026-03-15', 1);
// 14 sa çalışma, çift eşiği yokken sıradan; çift fiyat dönemi tanımsız → gun_mesai_ozeti ile eşitlik yeter
fmDonem($sX, '2026-03-15', $erkek, 'Erkek', 840, 3);
$dag2 = pdks_faz8b_ay_fm_dagilimi('2026-03-01', '2026-03-31', null, null, $db);
$oz2 = pdks_faz8b_gun_mesai_ozeti([$sX], $db);
okfm('2a) uzun mesai: toplu = özet', (int)($dag2[$sX]['saat'] ?? 0) === (int)$oz2['fm_saat'], json_encode([$dag2[$sX] ?? null, $oz2['fm_saat']]));
$f = ['fazla_mesai_saat' => 4, 'fazla_mesai_durum' => 'onayli', 'etkin_sinif' => 'cift', 'odenecek_fm_saat' => 2];
okfm('2b) fm_goster: çift günde odenecek_fm_saat', pdks_faz8b_fm_goster($f) === 2);
okfm('2c) fm_goster: reddedilen 0, normal aday', pdks_faz8b_fm_goster(['fazla_mesai_saat' => 3, 'fazla_mesai_durum' => 'reddedildi', 'etkin_sinif' => 'tam']) === 0
    && pdks_faz8b_fm_goster(['fazla_mesai_saat' => 3, 'fazla_mesai_durum' => 'onayli', 'etkin_sinif' => 'tam']) === 3);

echo "\n=== 3. Rapor satırları ===\n";
$sat = pdks_rapor_cavus_toplu_dokum($ay, null, null, false, $db);
$byId = []; foreach ($sat as $r) $byId[$r['session_id']] = $r;
okfm('3a) satırlarda fm_saat/fm_kisi/fm_dagilim var', isset($byId[$sA]['fm_saat'], $byId[$sA]['fm_kisi'], $byId[$sA]['fm_dagilim']));
okfm('3b) A: fm_saat 6 / kişi 4 / dağılım', $byId[$sA]['fm_saat'] === 6 && $byId[$sA]['fm_kisi'] === 4 && $byId[$sA]['fm_dagilim'] === [1 => 2, 2 => 2], json_encode($byId[$sA]));
okfm('3c) FM yok satırı 0 / []', $byId[$sB]['fm_saat'] === 0 && $byId[$sB]['fm_kisi'] === 0 && $byId[$sB]['fm_dagilim'] === []);
okfm('3d) finansal kapı FM\'i etkilemez', array_column(pdks_rapor_cavus_toplu_dokum($ay, null, null, true, $db), 'fm_saat', 'session_id') == array_column($sat, 'fm_saat', 'session_id'));
$oncekiAy = pdks_rapor_cavus_toplu_dokum('2026-02', null, null, false, $db);
okfm('3e) önceki ay satırı kendi ayında FM taşır', ($oncekiAy[0]['fm_saat'] ?? 0) === 2);

echo "\n=== 4. Sorgu sayısı oturum sayısından bağımsız ===\n";
pdks_faz8b_ay_fm_dagilimi('2026-03-01', '2026-03-31', null, null, $db);   // ısınma (şema yoklamaları)
$db->say = 0; pdks_faz8b_ay_fm_dagilimi('2026-03-01', '2026-03-31', null, 2, $db); $az = $db->say;
$db->say = 0; pdks_faz8b_ay_fm_dagilimi('2026-03-01', '2026-03-31', null, null, $db); $cok = $db->say;
for ($i = 0; $i < 12; $i++) { $s = fmSession('2026-03-20', 1); fmDonem($s, '2026-03-20', $kadin, 'Kadın', 660, 1); }
$db->say = 0; pdks_faz8b_ay_fm_dagilimi('2026-03-01', '2026-03-31', null, null, $db); $daha = $db->say;
okfm("4a) sorgu sayısı sabit (az=$az, çok=$cok, daha çok=$daha)", $cok === $daha && $cok <= $az + 1);

echo "\n=== 5. Kaynak taraması ===\n";
$bak = function (string $f) use ($root): string { return (string)file_get_contents($root . '/' . $f); };
foreach (['cavus_toplu_dokum.php', 'cavus_toplu_dokum_yazdir.php', 'config/pdks_rapor.php'] as $f) {
    $k = $bak($f);
    okfm("5) $f: FM hesabı yok (sınıflandırıcı/özet çağrısı, fazla_mesai_saat, odenecek_fm)", !preg_match('/fazla_mesai_saat|odenecek_fm|pdks_faz8b_gun_mesai_ozeti|pdks_faz8b_donem_siniflandir|pdks_faz8b_sure_karari/', $k));
}
okfm('5) ay_fm_dagilimi döngüde gun_mesai_ozeti çağırmaz', !preg_match('/function pdks_faz8b_ay_fm_dagilimi.*?gun_mesai_ozeti/s', preg_replace('/\n\/\*\* v407: \[saat.*/s', '', $bak('config/pdks_faz8b.php'))));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail ? 1 : 0);
