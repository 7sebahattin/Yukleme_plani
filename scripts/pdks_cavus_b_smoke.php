<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_cavus_b_smoke.php — Çavuş Ücreti Yöntem B (25 kişi-gün =
// 1 hakediş, dönem kapanışı) davranış testi.
//
// pdks_cavus_ucret_smoke.php'nin harness deseni (bellek içi SQLite +
// gerçek MySQL DDL çeviricisi + gerçek fonksiyon çağrıları) REUSE edilir.
// Canlı DB'ye HİÇ dokunmaz.
//
//   php scripts/pdks_cavus_b_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function okb(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function active_depot(): ?string { return 'Depo A'; }
function can(string $p): bool { return true; }
function is_admin(): bool { return true; }
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid, 'old' => $o, 'new' => $n];
}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_cari.php';
require_once $root . '/config/pdks_rapor.php';
require_once $root . '/config/pdks_faz8b_cavus_b.php';

// ── MySQL DDL → SQLite çevirici — pdks_cavus_ucret_smoke.php İLE BİREBİR AYNI. ──
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1];
    $govde = $m[2];
    $parcalar = []; $buf = ''; $derinlik = 0;
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
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")";
            continue;
        }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")";
            continue;
        }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    $create = "CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)";
    return [$create, $indeksler];
}
function pdks_kolon_listesi(string $ham): string
{
    $ham = preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $ham);
    return preg_replace('/\s+/', ' ', trim($ham));
}
function ddlUygula(PDO $db, array $tablolar): void {
    foreach ($tablolar as $sql) {
        [$create, $indeksler] = pdks_ddl_sqlite($sql);
        $db->exec($create);
        foreach ($indeksler as $ix) $db->exec($ix);
    }
}

// ── Şema (Faz 8A temeli) ──
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın')");
$kadinId = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$gid = 1;
function periodEkleb(PDO $db, int $sessionId, int $cardId, int $tipId, string $tip, string $giris, string $cikis, string $day): int {
    $st = $db->prepare("INSERT INTO daily_worker_work_periods
        (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,work_date_snapshot,depo_snapshot,
         entry_time,exit_time,declared_attendance_class,approved_attendance_class,status)
        VALUES (?,?,?,?,?,'Depo A',?,?,'tam',NULL,'closed')");
    $st->execute([$sessionId, $cardId, $tipId, $tip, $day, "{$day} {$giris}:00", "{$day} {$cikis}:00"]);
    return (int)$db->lastInsertId();
}

/** Doğrudan KESİN (final) hakediş fikstürü — session/hesapla akışını atlar. */
function kesinGunEkle(PDO $db, int $fid, string $workDate, int $kisiGun, ?string $finalizedAt, bool $cavusSatiri = false, string $status = 'final'): int {
    global $kadinId;
    $ins = $db->prepare(
        "INSERT INTO foreman_daily_entitlements
            (session_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status,
             currency, total_amount, needs_recalculation, calculated_at, finalized_at)
         VALUES (NULL,?,?,?,?,'Depo A',?, 'TRY','0.00',0,?,?)"
    );
    $simdi = $finalizedAt ?? date('Y-m-d H:i:s');
    $ins->execute([$fid, 'Test Çavuş', 'CX', $workDate, $status, $simdi, $status === 'final' ? $finalizedAt : null]);
    $entId = (int)$db->lastInsertId();
    if ($kisiGun > 0) {
        $db->prepare(
            "INSERT INTO foreman_daily_entitlement_lines
                (entitlement_id, work_period_id, worker_type_id, worker_type_code_snapshot, worker_type_name_snapshot,
                 attendance_class_snapshot, worker_count, unit_rate, overtime_hours, overtime_unit_rate, overtime_total, line_total)
             VALUES (?,NULL,?,?,?, 'tam', ?, '0.00', 0, '0.00', '0.00', '0.00')"
        )->execute([$entId, $kadinId, 'KADIN', 'Kadın', $kisiGun]);
    }
    if ($cavusSatiri) {
        $db->prepare(
            "INSERT INTO foreman_daily_entitlement_lines
                (entitlement_id, work_period_id, worker_type_id, worker_type_code_snapshot, worker_type_name_snapshot,
                 attendance_class_snapshot, worker_count, unit_rate, overtime_hours, overtime_unit_rate, overtime_total, line_total)
             VALUES (?,NULL,NULL,'','Çavuş Ücreti', 'tam', 1, '0.00', 0, '0.00', '0.00', '0.00')"
        )->execute([$entId]);
    }
    return $entId;
}

function yontemEkle(PDO $db, int $fid, string $method, string $effectiveAt): int {
    $db->prepare("INSERT INTO foreman_rate_method_log (foreman_id, method, effective_at, created_at) VALUES (?,?,?,?)")
       ->execute([$fid, $method, $effectiveAt, $effectiveAt]);
    return (int)$db->lastInsertId();
}

$day = date('Y-m-d', strtotime('-10 days'));

// =========================================================
// § A — SAF HESAP
// =========================================================
echo "=== A. SAF HESAP (pdks_faz8b_cavus_ucret_b_hesap) ===\n";
$hA1 = pdks_faz8b_cavus_ucret_b_hesap(0, 105);
okb('1) (0,105) -> adet 4, devir 5', $hA1['adet'] === 4 && $hA1['devir_cikan'] === 5, json_encode($hA1));
$hA2 = pdks_faz8b_cavus_ucret_b_hesap(0, 24);
okb('2) (0,24) -> adet 0, devir 24', $hA2['adet'] === 0 && $hA2['devir_cikan'] === 24);
$hA3 = pdks_faz8b_cavus_ucret_b_hesap(0, 25);
okb('3) (0,25) -> adet 1, devir 0', $hA3['adet'] === 1 && $hA3['devir_cikan'] === 0);
$hA4 = pdks_faz8b_cavus_ucret_b_hesap(5, 20);
okb('4) (5,20) -> adet 1, devir 0', $hA4['adet'] === 1 && $hA4['devir_cikan'] === 0);
$hA5 = pdks_faz8b_cavus_ucret_b_hesap(24, 1);
okb('5) (24,1) -> adet 1, devir 0', $hA5['adet'] === 1 && $hA5['devir_cikan'] === 0);
try { pdks_faz8b_cavus_ucret_b_hesap(-1, 5); okb('6) negatif devir InvalidArgumentException fırlatır', false); }
catch (InvalidArgumentException $e) { okb('6) negatif devir InvalidArgumentException fırlatır', true); }
try { pdks_faz8b_cavus_ucret_b_hesap(0, -5); okb('7) negatif dönem InvalidArgumentException fırlatır', false); }
catch (InvalidArgumentException $e) { okb('7) negatif dönem InvalidArgumentException fırlatır', true); }
okb('8) 4 hakediş × 100000 kuruş = 400000 kuruş', $hA1['adet'] * 100000 === 400000);

// =========================================================
// § B — YÖNTEM ANDA (SAF)
// =========================================================
echo "\n=== B. YONTEM_ANDA ===\n";
okb('9) boş geçmiş -> A', pdks_faz8b_cavus_ucret_yontem_anda([], '2026-01-01 00:00:00') === 'A');
$gB1 = [['method' => 'B', 'effective_at' => '2026-01-10 00:00:00', 'id' => 1]];
okb('10) [B@01-10] öncesi (01-05) A', pdks_faz8b_cavus_ucret_yontem_anda($gB1, '2026-01-05 00:00:00') === 'A');
okb('11) [B@01-10] sonrası (01-15) B', pdks_faz8b_cavus_ucret_yontem_anda($gB1, '2026-01-15 00:00:00') === 'B');
$gB2 = [
    ['method' => 'B', 'effective_at' => '2026-01-01 00:00:00', 'id' => 1],
    ['method' => 'A', 'effective_at' => '2026-02-01 00:00:00', 'id' => 2],
    ['method' => 'B', 'effective_at' => '2026-03-01 00:00:00', 'id' => 3],
];
okb('12) [B,A,B] 02-15 -> A (A dönemi)', pdks_faz8b_cavus_ucret_yontem_anda($gB2, '2026-02-15 00:00:00') === 'A');
okb('13) [B,A,B] 03-05 -> B (3. dönem)', pdks_faz8b_cavus_ucret_yontem_anda($gB2, '2026-03-05 00:00:00') === 'B');
$gB3 = [$gB2[2], $gB2[0], $gB2[1]];   // sırasız
okb('14) sıra karışsa da SONUÇ AYNI (03-05 -> B)', pdks_faz8b_cavus_ucret_yontem_anda($gB3, '2026-03-05 00:00:00') === 'B');

// =========================================================
// § C — HAVUZ_SEC
// =========================================================
echo "\n=== C. HAVUZ_SEC ===\n";
$gecmisC = [['method' => 'B', 'effective_at' => '2026-01-01 00:00:00', 'id' => 1]];
$adaylarC = [
    ['entitlement_id' => 1, 'work_date' => '2026-01-05', 'depo' => 'Depo A', 'finalized_at' => '2026-01-05 10:00:00', 'kisi_gun' => 5, 'cavus_satiri' => 0],
    ['entitlement_id' => 2, 'work_date' => '2026-01-06', 'depo' => 'Depo A', 'finalized_at' => '2026-01-06 10:00:00', 'kisi_gun' => 5, 'cavus_satiri' => 1],   // çavuş satırlı -> elenir
    ['entitlement_id' => 3, 'work_date' => '2025-12-20', 'depo' => 'Depo A', 'finalized_at' => '2025-12-20 10:00:00', 'kisi_gun' => 5, 'cavus_satiri' => 0],   // A anında kesinleşti -> elenir
    ['entitlement_id' => 4, 'work_date' => '2026-01-07', 'depo' => 'Depo A', 'finalized_at' => '', 'kisi_gun' => 5, 'cavus_satiri' => 0],                      // finalized_at boş -> elenir
    ['entitlement_id' => 5, 'work_date' => '2026-01-08', 'depo' => 'Depo A', 'finalized_at' => '2026-01-08 10:00:00', 'kisi_gun' => 0, 'cavus_satiri' => 0],   // kişi-gün 0 -> elenir
];
$secC = pdks_faz8b_cavus_ucret_b_havuz_sec($adaylarC, $gecmisC);
okb('15) havuz_sec yalnız geçerli tek adayı (1) döner', count($secC) === 1 && $secC[0]['entitlement_id'] === 1, json_encode($secC));

// =========================================================
// § D — MİGRASYON
// =========================================================
echo "\n=== D. MİGRASYON ===\n";
$migD1 = pdks_faz8b_cavus_ucret_b_migrate($db);
okb('16) önkoşul tablo (foremen dışında foreman_daily_entitlements/foreman_payments) eksikken hepsi atlandı', count(array_filter($migD1, fn($r) => $r['durum'] === 'atlandi')) === count($migD1), json_encode($migD1));
okb('17) hiçbir B tablosu oluşmadı', !pdks_faz8b_cavus_ucret_b_tablo_var($db, 'foreman_period_closures'));

// Önkoşulları kur: foreman_payments (Faz 5)
ddlUygula($db, pdks_cari_tablolar());
// foreman_daily_rates (Çavuş Ücreti A) — gerçek DDL üzerinden.
ddlUygula($db, pdks_faz8b_cavus_ucret_tablolar());
// Şimdi önkoşullar tam: gerçek migrasyon fonksiyonu artık ATLAMAZ (önkoşul
// kontrolünü geçer) — ama SQLite'ta HAM MySQL DDL'i çalıştıramaz (INDEX/
// UNIQUE KEY sözdizimi farklı), diğer *_smoke.php dosyalarıyla AYNI bilinen
// sınırlama. Üretim davranışını (önkoşul kontrolü + exec denemesi) DOĞRULAMAK
// için burada YALNIZ "artık atlanmıyor" doğrulanır; GERÇEK şema, testin geri
// kalanının çalışabilmesi için pdks_ddl_sqlite() çeviricisiyle KURULUR.
$migD2 = pdks_faz8b_cavus_ucret_b_migrate($db);
okb('18) önkoşullar tamken migrasyon artık "atlandi" DEMİYOR (exec dener)', count(array_filter($migD2, fn($r) => $r['durum'] === 'atlandi')) === 0, json_encode($migD2));

ddlUygula($db, pdks_faz8b_cavus_ucret_b_tablolar());
okb('19) şema hazır (gerçek DDL, SQLite\'a çevrilmiş)', pdks_faz8b_cavus_ucret_b_sema_hazir($db));
$migD3 = pdks_faz8b_cavus_ucret_b_migrate($db);
okb('20) migrasyon idempotent (tablolar zaten varken -> hepsi "var")', count(array_filter($migD3, fn($r) => $r['durum'] === 'var')) === count($migD3), json_encode($migD3));

$faz8bKaynak = file_get_contents($root . '/config/pdks_faz8b.php');
preg_match('/function pdks_faz8b_sema_hazir.*?\n\}\n/s', $faz8bKaynak, $mSema);
okb('21) pdks_faz8b_sema_hazir() kaynağı B tablolarından HİÇBİRİNİ içermez', !preg_match('/foreman_rate_method_log|foreman_period_closures|foreman_period_closure_items/', $mSema[0] ?? ''));

// =========================================================
// § E — YÖNTEM DEĞİŞTİRME
// =========================================================
echo "\n=== E. YÖNTEM DEĞİŞTİRME ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C1','Çavuş B1')");
$fB1 = 1;
okb('22) varsayılan yöntem A', pdks_faz8b_cavus_ucret_yontem($fB1, $db) === 'A');
$db->exec("INSERT INTO foreman_daily_entitlements (id,foreman_id,work_date,status,currency,total_amount,needs_recalculation) VALUES (900,{$fB1},'2026-01-01','draft','TRY','0.00',0)");
$AUDIT = [];
$rE1 = pdks_faz8b_cavus_ucret_yontem_degistir($fB1, 'b', 1, $db);
okb('23) A -> B: degisti true', $rE1['ok'] === true && $rE1['degisti'] === true, json_encode($rE1));
okb('24) audit update/foreman_rate_method_log çağrıldı', count(array_filter($AUDIT, fn($a) => $a['action'] === 'update' && $a['module'] === 'foreman_rate_method_log')) === 1);
$flagE = (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=900")->fetchColumn();
okb('25) draft hakediş needs_recalculation=1 aldı', $flagE === 1);
okb('26) yöntem şimdi B', pdks_faz8b_cavus_ucret_yontem($fB1, $db) === 'B');
$rE2 = pdks_faz8b_cavus_ucret_yontem_degistir($fB1, 'B', 1, $db);
okb('27) tekrar B -> degisti false', $rE2['ok'] === true && $rE2['degisti'] === false);
$rE3 = pdks_faz8b_cavus_ucret_yontem_degistir($fB1, 'C', 1, $db);
okb('28) geçersiz yöntem C -> gecersiz_yontem', $rE3['ok'] === false && $rE3['kod'] === 'gecersiz_yontem');

// =========================================================
// § F — HESAPLA REGRESYONU (gerçek session akışı)
// =========================================================
echo "\n=== F. HESAPLA REGRESYONU ===\n";
pdks_faz8b_oran_ekle($fB1, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
pdks_faz8b_cavus_ucret_ekle($fB1, '1000', '2020-01-01', 'TRY', 1, $db);
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (100,{$fB1},'Çavuş B1','C1','{$day}','Depo A','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (100,'K100')");
periodEkleb($db, 100, 100, $kadinId, 'Kadın', '08:00', '17:00', $day);
$hF1 = pdks_faz8b_hakedis_hesapla(100, 1, $db);
okb('29) B çavuşunda "Çavuş Ücreti" satırı YOK', count(array_filter($hF1['lines'] ?? [], fn($x) => $x['worker_type_id'] === null)) === 0, json_encode($hF1));

$db->exec("INSERT INTO foremen (id,code,name) VALUES (2,'C2','Çavuş A1')");
$fA1 = 2;
pdks_faz8b_oran_ekle($fA1, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
pdks_faz8b_cavus_ucret_ekle($fA1, '1000', '2020-01-01', 'TRY', 1, $db);
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (101,{$fA1},'Çavuş A1','C2','{$day}','Depo A','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (101,'K101')");
periodEkleb($db, 101, 101, $kadinId, 'Kadın', '08:00', '17:00', $day);
$hF2 = pdks_faz8b_hakedis_hesapla(101, 1, $db);
$cavusSatirF2 = array_values(array_filter($hF2['lines'] ?? [], fn($x) => $x['worker_type_id'] === null))[0] ?? null;
okb('30) A çavuşunda "Çavuş Ücreti" satırı VAR (1000)', $cavusSatirF2 !== null && $cavusSatirF2['line_total'] === '1000.00', json_encode($hF2));

// =========================================================
// § G — 105 SENARYOSU (uçtan uca kapanış)
// =========================================================
echo "\n=== G. 105 KİŞİ-GÜN SENARYOSU ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (10,'C10','Çavuş B2')");
$fG = 10;
pdks_faz8b_cavus_ucret_ekle($fG, '1000', '2020-01-01', 'TRY', 1, $db);
yontemEkle($db, $fG, 'B', '2026-01-01 00:00:00');
for ($i = 0; $i < 5; $i++) {
    kesinGunEkle($db, $fG, '2026-01-0' . ($i + 1), 21, '2026-01-0' . ($i + 1) . ' 18:00:00');
}
$odemeG = pdks_faz8b_cavus_ucret_odeme_kaydet($fG, '2026-02-01', '100', 'TRY', 'BANK', null, null, 1, $db);
okb('31) ödeme+kapanış ok', $odemeG['ok'] === true, json_encode($odemeG));
$kapG = $odemeG['kapanis'];
okb('32) kapanış yazıldı (kapanis_id dolu)', $kapG !== null && !empty($kapG['kapanis_id']), json_encode($kapG));
$satirG = $db->query("SELECT * FROM foreman_period_closures WHERE id = " . (int)$kapG['kapanis_id'])->fetch();
okb('33) earned_units=4, carry_out=5, amount=4000.00', (int)$satirG['earned_units'] === 4 && (int)$satirG['carry_out'] === 5 && (float)$satirG['amount'] === 4000.0, json_encode($satirG));
$kalemSayisiG = (int)$db->query("SELECT COUNT(*) FROM foreman_period_closure_items WHERE closure_id = " . (int)$kapG['kapanis_id'])->fetchColumn();
okb('34) 5 kalem yazıldı', $kalemSayisiG === 5);
$bakiyeG = pdks_cari_bakiye($fG, $db);
okb('35) cari bakiye hakedis_kurus 400000 içerir', ($bakiyeG['TRY']['hakedis_kurus'] ?? 0) === 400000, json_encode($bakiyeG['TRY'] ?? null));
okb('36) cari bakiye cavus_hakedis_kurus 400000', ($bakiyeG['TRY']['cavus_hakedis_kurus'] ?? 0) === 400000);
$ekstreG = pdks_cari_ekstre($fG, null, null, $db);
$satirEkstreG = null; $idxCavus = null; $idxOdeme = null;
foreach (($ekstreG['TRY'] ?? []) as $i => $s) {
    if ($s['tip'] === 'CAVUS_HAKEDIS') { $satirEkstreG = $s; $idxCavus = $i; }
    if ($s['tip'] === 'ODEME') $idxOdeme = $i;
}
okb('37) ekstre CAVUS_HAKEDIS açıklaması birebir', $satirEkstreG !== null
    && $satirEkstreG['aciklama'] === 'Çavuş Hakedişi — 105 kişi-gün (+0 devir) → 4 hakediş × 1.000,00 TRY, devir 5', json_encode($satirEkstreG));
okb('38) aynı tarihli kapanış satırı ödeme satırından ÖNCE', $idxCavus !== null && $idxOdeme !== null && $idxCavus < $idxOdeme);
okb('39) audit create/foreman_period_closures çağrıldı', count(array_filter($AUDIT, fn($a) => $a['action'] === 'create' && $a['module'] === 'foreman_period_closures')) >= 1);

// =========================================================
// § H — DEVİR
// =========================================================
echo "\n=== H. DEVİR ===\n";
kesinGunEkle($db, $fG, '2026-02-10', 20, '2026-02-10 18:00:00');
$odemeH = pdks_faz8b_cavus_ucret_odeme_kaydet($fG, '2026-03-01', '100', 'TRY', 'BANK', null, null, 1, $db);
$kapH = $odemeH['kapanis'];
okb('40) devir senaryosu: carry_in=5, period=20, adet=1, devir=0', $kapH !== null && $kapH['onizleme']['devir_giren'] === 5 && $kapH['onizleme']['donem_kisi_gun'] === 20
    && $kapH['onizleme']['adet'] === 1 && $kapH['onizleme']['devir_cikan'] === 0, json_encode($kapH));
$satirH = $db->query("SELECT * FROM foreman_period_closures WHERE id = " . (int)$kapH['kapanis_id'])->fetch();
okb('41) prev_closure_id doğru (G kapanışına işaret eder)', (int)$satirH['prev_closure_id'] === (int)$kapG['kapanis_id']);

// =========================================================
// § I — GEÇ KESİNLEŞEN GÜNLER
// =========================================================
echo "\n=== I. GEÇ KESİNLEŞEN GÜN ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (11,'C11','Çavuş B3')");
$fI = 11;
pdks_faz8b_cavus_ucret_ekle($fI, '1000', '2020-01-01', 'TRY', 1, $db);
yontemEkle($db, $fI, 'B', '2026-01-01 00:00:00');
$entTaslakI = kesinGunEkle($db, $fI, '2026-01-05', 7, '2026-01-05 18:00:00', false, 'draft');
$odemeI1 = pdks_faz8b_cavus_ucret_odeme_kaydet($fI, '2026-02-01', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('42) taslak gün ödeme 1de SAYILMAZ (kapanış yok, bos)', $odemeI1['ok'] === true && ($odemeI1['kapanis']['onizleme']['durum'] ?? '') === 'bos', json_encode($odemeI1['kapanis'] ?? null));
$db->prepare("UPDATE foreman_daily_entitlements SET status='final', finalized_at=? WHERE id=?")->execute(['2026-02-05 18:00:00', $entTaslakI]);
$odemeI2 = pdks_faz8b_cavus_ucret_odeme_kaydet($fI, '2026-03-01', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('43) sonradan final olan gün sonraki kapanışta SAYILIR', $odemeI2['ok'] === true && ($odemeI2['kapanis']['onizleme']['donem_kisi_gun'] ?? 0) === 7, json_encode($odemeI2['kapanis'] ?? null));

// =========================================================
// § J — B→A→B GEÇİŞİ
// =========================================================
echo "\n=== J. B->A->B GEÇİŞİ ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (12,'C12','Çavuş J1')");
$fJ = 12;
pdks_faz8b_cavus_ucret_ekle($fJ, '1000', '2020-01-01', 'TRY', 1, $db);
yontemEkle($db, $fJ, 'B', '2026-01-01 00:00:00');
yontemEkle($db, $fJ, 'A', '2026-02-01 00:00:00');
kesinGunEkle($db, $fJ, '2025-12-15', 30, '2025-12-15 18:00:00');   // B öncesi -> ASLA sayılmaz
kesinGunEkle($db, $fJ, '2026-01-10', 30, '2026-01-10 18:00:00');   // B döneminde final
kesinGunEkle($db, $fJ, '2026-02-10', 10, '2026-02-10 18:00:00', true);   // A döneminde, çavuş satırlı (A ile zaten ödendi)
kesinGunEkle($db, $fJ, '2026-02-11', 7, '2026-02-11 18:00:00');   // A döneminde, çavuş satırsız (yine de A anında final -> sayılmaz)
okb('44) A dönemindeyken yöntem A', pdks_faz8b_cavus_ucret_yontem($fJ, $db) === 'A');
$odemeJ1 = pdks_faz8b_cavus_ucret_odeme_kaydet($fJ, '2026-02-20', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('45) A dayken ödeme -> kapanış hiç DENENMEDİ (kapanis null)', $odemeJ1['ok'] === true && $odemeJ1['kapanis'] === null, json_encode($odemeJ1['kapanis'] ?? null));
$onizJ1 = pdks_faz8b_cavus_ucret_b_onizle($fJ, '2026-02-20', $db);
okb('45b) önizleme A dayken durum=yontem_a mesajıyla bekleyen kişi-günü bildirir', $onizJ1['durum'] === 'yontem_a' && str_contains($onizJ1['mesaj'], "Yöntem A'da"), json_encode($onizJ1));
yontemEkle($db, $fJ, 'B', '2026-03-01 00:00:00');
kesinGunEkle($db, $fJ, '2026-03-05', 20, '2026-03-05 18:00:00');
$odemeJ2 = pdks_faz8b_cavus_ucret_odeme_kaydet($fJ, '2026-04-01', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('46) B\'ye dönünce yalnız 01-10 ve 03-05 sayılır (toplam 50, adet 2, devir 0)',
    $odemeJ2['ok'] === true && ($odemeJ2['kapanis']['onizleme']['toplam'] ?? null) === 50
    && ($odemeJ2['kapanis']['onizleme']['adet'] ?? null) === 2 && ($odemeJ2['kapanis']['onizleme']['devir_cikan'] ?? null) === 0,
    json_encode($odemeJ2['kapanis'] ?? null));
$kalemlerJ = pdks_faz8b_cavus_ucret_b_kapanis_kalemleri((int)$odemeJ2['kapanis']['kapanis_id'], $db);
$tarihlerJ = array_map(fn($k) => $k['work_date'], $kalemlerJ);
sort($tarihlerJ);
okb('47) kapanış kalemleri yalnız 01-10 ve 03-05', $tarihlerJ === ['2026-01-10', '2026-03-05'], json_encode($tarihlerJ));

// devirli çavuşta B->A->B sonrası carry_in = önceki carry_out
$db->exec("INSERT INTO foremen (id,code,name) VALUES (13,'C13','Çavuş J2')");
$fJ2 = 13;
pdks_faz8b_cavus_ucret_ekle($fJ2, '1000', '2020-01-01', 'TRY', 1, $db);
yontemEkle($db, $fJ2, 'B', '2026-01-01 00:00:00');
kesinGunEkle($db, $fJ2, '2026-01-05', 10, '2026-01-05 18:00:00');
$odemeJ2a = pdks_faz8b_cavus_ucret_odeme_kaydet($fJ2, '2026-01-10', '10', 'TRY', 'BANK', null, null, 1, $db);
$carryOutJ2a = (int)($odemeJ2a['kapanis']['onizleme']['devir_cikan'] ?? -1);
yontemEkle($db, $fJ2, 'A', '2026-01-15 00:00:00');
yontemEkle($db, $fJ2, 'B', '2026-01-20 00:00:00');
kesinGunEkle($db, $fJ2, '2026-01-25', 5, '2026-01-25 18:00:00');
$onizJ2b = pdks_faz8b_cavus_ucret_b_onizle($fJ2, '2026-02-01', $db);
okb('48) B->A->B sonrası carry_in = önceki carry_out (' . $carryOutJ2a . ')', (int)$onizJ2b['devir_giren'] === $carryOutJ2a, json_encode($onizJ2b));

// =========================================================
// § K — ÜCRET TANIMSIZ
// =========================================================
echo "\n=== K. ÜCRET TANIMSIZ ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (14,'C14','Çavuş K1')");
$fK = 14;
yontemEkle($db, $fK, 'B', '2026-01-01 00:00:00');
kesinGunEkle($db, $fK, '2026-01-05', 10, '2026-01-05 18:00:00');
$AUDIT = [];
$odemeK1 = pdks_faz8b_cavus_ucret_odeme_kaydet($fK, '2026-02-01', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('49) ücret yokken ödeme ok', $odemeK1['ok'] === true, json_encode($odemeK1));
okb('50) kapanış yapılmadı (kapanis_id null), durum ucret_yok', array_key_exists('kapanis_id', $odemeK1['kapanis']) && $odemeK1['kapanis']['kapanis_id'] === null && ($odemeK1['kapanis']['onizleme']['durum'] ?? '') === 'ucret_yok', json_encode($odemeK1['kapanis'] ?? null));
okb('51) audit closure_skipped çağrıldı', count(array_filter($AUDIT, fn($a) => $a['action'] === 'closure_skipped')) === 1);
$bakiyeK = pdks_cari_bakiye($fK, $db);
okb('52) bakiyede kapanış tutarı YOK (yalnız ödeme düşümü)', ($bakiyeK['TRY']['cavus_hakedis_kurus'] ?? 0) === 0);
pdks_faz8b_cavus_ucret_ekle($fK, '500', '2026-01-01', 'TRY', 1, $db);
$odemeK2 = pdks_faz8b_cavus_ucret_odeme_kaydet($fK, '2026-03-01', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('53) ücret eklenince sonraki ödemede açıktaki 10 kişi-gün kapanır', ($odemeK2['kapanis']['onizleme']['donem_kisi_gun'] ?? 0) === 10 && !empty($odemeK2['kapanis']['kapanis_id']), json_encode($odemeK2['kapanis'] ?? null));

// =========================================================
// § L — PARA BİRİMİ
// =========================================================
echo "\n=== L. PARA BİRİMİ ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (15,'C15','Çavuş L1')");
$fL = 15;
pdks_faz8b_cavus_ucret_ekle($fL, '10', '2020-01-01', 'EUR', 1, $db);
yontemEkle($db, $fL, 'B', '2026-01-01 00:00:00');
kesinGunEkle($db, $fL, '2026-01-05', 25, '2026-01-05 18:00:00');
$odemeL = pdks_faz8b_cavus_ucret_odeme_kaydet($fL, '2026-02-01', '100', 'TRY', 'BANK', null, null, 1, $db);
okb('54) kapanış EUR olarak yazıldı', ($odemeL['kapanis']['onizleme']['currency'] ?? '') === 'EUR', json_encode($odemeL['kapanis'] ?? null));
$bakiyeL = pdks_cari_bakiye($fL, $db);
okb('55) bakiye EUR hakediş + TRY ödeme AYRI anahtarlar', ($bakiyeL['EUR']['cavus_hakedis_kurus'] ?? 0) === 1000 && ($bakiyeL['TRY']['odeme_kurus'] ?? 0) === 10000, json_encode($bakiyeL));
// Taze bir "kapanacak" dönem kur (önceki 25 kişi-gün zaten kapandı) — önizlemenin
// EUR kapanış tutarını mevcut bakiyeye EKLEYİP eklemediğini sınamak için.
kesinGunEkle($db, $fL, '2026-02-05', 25, '2026-02-05 18:00:00');
$onizL_eur = pdks_faz8b_cavus_ucret_odeme_onizleme($fL, 5000, 'EUR', '2026-03-01', $db);
$onizL_try = pdks_faz8b_cavus_ucret_odeme_onizleme($fL, 500000, 'TRY', '2026-03-01', $db);
okb('56) EUR ödeme önizlemesinde asim false (kapanış aynı para birimine eklenir: 1000+1000=2000 > 5000? hayır -> asim true beklenir)', $onizL_eur['asim'] === true, json_encode($onizL_eur));
okb('56b) EUR önizlemesi kapanış tutarını mevcut bakiyeye EKLEDİ (mevcut_bakiye_kurus 2000)', ($onizL_eur['mevcut_bakiye_kurus'] ?? null) === 2000, json_encode($onizL_eur));
okb('57) TRY ödeme önizlemesinde farklı para birimi kapanışı bakiyeye EKLENMEZ (yalnız mevcut TRY bakiyesi -10000)', ($onizL_try['mevcut_bakiye_kurus'] ?? null) === -10000, json_encode($onizL_try));

// =========================================================
// § M — İPTAL / GERİ ALMA
// =========================================================
echo "\n=== M. İPTAL / GERİ ALMA ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (16,'C16','Çavuş M1')");
$fM = 16;
pdks_faz8b_cavus_ucret_ekle($fM, '1000', '2020-01-01', 'TRY', 1, $db);
yontemEkle($db, $fM, 'B', '2026-01-01 00:00:00');
kesinGunEkle($db, $fM, '2026-01-05', 30, '2026-01-05 18:00:00');
$odemeM1 = pdks_faz8b_cavus_ucret_odeme_kaydet($fM, '2026-02-01', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('M1 sanity: earned=1, carry_out=5', ($odemeM1['kapanis']['onizleme']['adet'] ?? null) === 1 && ($odemeM1['kapanis']['onizleme']['devir_cikan'] ?? null) === 5, json_encode($odemeM1['kapanis'] ?? null));
kesinGunEkle($db, $fM, '2026-02-10', 20, '2026-02-10 18:00:00');
$odemeM2 = pdks_faz8b_cavus_ucret_odeme_kaydet($fM, '2026-03-01', '10', 'TRY', 'BANK', null, null, 1, $db);

$iptalM1erken = pdks_faz8b_cavus_ucret_odeme_iptal((int)$odemeM1['id'], 'test', 1, $db);
okb('58) EN ESKİ ödemenin iptali kapanis_en_son_degil ile reddedilir', $iptalM1erken['ok'] === false && $iptalM1erken['kod'] === 'kapanis_en_son_degil', json_encode($iptalM1erken));

$iptalDogrudan = pdks_cari_odeme_iptal((int)$odemeM2['id'], 'test', 1, $db);
okb('59) pdks_cari_odeme_iptal() DOĞRUDAN çağrılırsa kapanis_bagli ile reddedilir', $iptalDogrudan['ok'] === false && $iptalDogrudan['kod'] === 'kapanis_bagli', json_encode($iptalDogrudan));

$AUDIT = [];
$iptalM2 = pdks_faz8b_cavus_ucret_odeme_iptal((int)$odemeM2['id'], 'Mükerrer', 1, $db);
okb('60) en son ödemenin iptali orkestratörle BAŞARILI', $iptalM2['ok'] === true && !empty($iptalM2['kapanis_iptal']), json_encode($iptalM2));
$kapM2Durum = $db->query("SELECT status, chain_key FROM foreman_period_closures WHERE id = " . (int)$iptalM2['kapanis_iptal'])->fetch();
okb('61) kapanış cancelled + chain_key NULL', $kapM2Durum['status'] === 'cancelled' && $kapM2Durum['chain_key'] === null, json_encode($kapM2Durum));
okb('62) audit cancel/foreman_period_closures çağrıldı', count(array_filter($AUDIT, fn($a) => $a['action'] === 'cancel' && $a['module'] === 'foreman_period_closures')) === 1);
$bakiyeM = pdks_cari_bakiye($fM, $db);
okb('63) iptalden sonra bakiyede yalnız M1 kapanışının 1000 TRY tutarı kalır', ($bakiyeM['TRY']['cavus_hakedis_kurus'] ?? -1) === 100000, json_encode($bakiyeM['TRY'] ?? null));

$odemeM3 = pdks_faz8b_cavus_ucret_odeme_kaydet($fM, '2026-04-01', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('64) iptalden AÇIĞA DÜŞEN 20 kişi-gün + M1 devri (5) yeniden sayılır -> toplam 25, adet 1', ($odemeM3['kapanis']['onizleme']['toplam'] ?? null) === 25 && ($odemeM3['kapanis']['onizleme']['adet'] ?? null) === 1, json_encode($odemeM3['kapanis'] ?? null));

// =========================================================
// § N — EŞZAMANLILIK
// =========================================================
echo "\n=== N. EŞZAMANLILIK ===\n";
okb('65) zincir_anahtari(3,null) === F3:P0', pdks_faz8b_cavus_ucret_b_zincir_anahtari(3, null) === 'F3:P0');
okb('66) zincir_anahtari(3,7) === F3:P7', pdks_faz8b_cavus_ucret_b_zincir_anahtari(3, 7) === 'F3:P7');

$db->exec("INSERT INTO foremen (id,code,name) VALUES (17,'C17','Çavuş N1')");
$fN = 17;
$dupPay1 = $db->query("SELECT id FROM foreman_payments LIMIT 1")->fetchColumn();
$db->prepare("INSERT INTO foreman_payments (foreman_id,foreman_name_snapshot,foreman_code_snapshot,payment_date,amount,currency,payment_method,status) VALUES (?,?,?,?,?,?,?,?)")
   ->execute([$fN, 'Çavuş N1', 'C17', '2026-01-01', '1.00', 'TRY', 'OTHER', 'valid']);
$payN1 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO foreman_payments (foreman_id,foreman_name_snapshot,foreman_code_snapshot,payment_date,amount,currency,payment_method,status) VALUES (?,?,?,?,?,?,?,?)")
   ->execute([$fN, 'Çavuş N1', 'C17', '2026-01-02', '1.00', 'TRY', 'OTHER', 'valid']);
$payN2 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO foreman_period_closures (foreman_id,foreman_name_snapshot,payment_id,closure_date,chain_key,unit_size,carry_in,period_person_days,total_person_days,earned_units,carry_out,unit_rate,currency,amount,status) VALUES (?,?,?,?,?,25,0,0,0,0,0,'1.00','TRY','1.00','valid')")
   ->execute([$fN, 'Çavuş N1', $payN1, '2026-01-01', 'F' . $fN . ':P0']);
try {
    $db->prepare("INSERT INTO foreman_period_closures (foreman_id,foreman_name_snapshot,payment_id,closure_date,chain_key,unit_size,carry_in,period_person_days,total_person_days,earned_units,carry_out,unit_rate,currency,amount,status) VALUES (?,?,?,?,?,25,0,0,0,0,0,'1.00','TRY','1.00','valid')")
       ->execute([$fN, 'Çavuş N1', $payN2, '2026-01-02', 'F' . $fN . ':P0']);
    okb('67) AYNI chain_key ikinci INSERT reddedilir (PDOException 23000)', false);
} catch (PDOException $e) {
    okb('67) AYNI chain_key ikinci INSERT reddedilir (PDOException 23000)', $e->getCode() === '23000', $e->getMessage());
}
$db->exec("UPDATE foreman_period_closures SET chain_key = NULL WHERE payment_id = {$payN1}");
$db->prepare("INSERT INTO foreman_period_closures (foreman_id,foreman_name_snapshot,payment_id,closure_date,chain_key,unit_size,carry_in,period_person_days,total_person_days,earned_units,carry_out,unit_rate,currency,amount,status) VALUES (?,?,?,?,NULL,25,0,0,0,0,0,'1.00','TRY','1.00','valid')")
   ->execute([$fN, 'Çavuş N1', $payN2, '2026-01-02']);
okb('68) chain_key NULL yapınca tekrar eklenebilir, iki NULL birlikte durur', (int)$db->query("SELECT COUNT(*) FROM foreman_period_closures WHERE payment_id IN ({$payN1},{$payN2})")->fetchColumn() === 2);

try {
    $r = pdks_faz8b_cavus_ucret_b_kapat($fN, $payN1, '2026-01-01', 1, $db);
    okb('69) b_kapat tx DIŞINDA çağrılırsa transaction_yok', $r['ok'] === false && $r['kod'] === 'transaction_yok', json_encode($r));
} catch (Throwable $e) { okb('69) b_kapat tx DIŞINDA çağrılırsa transaction_yok', false, $e->getMessage()); }

$cavusBKaynak = file_get_contents($root . '/config/pdks_faz8b_cavus_b.php');
preg_match('/function pdks_faz8b_cavus_ucret_b_kilit.*?\n\}\n/s', $cavusBKaynak, $mKilit);
okb('70) b_kilit gövdesinde FOR UPDATE ve mysql kontrolü var', (bool)preg_match('/FOR UPDATE/', $mKilit[0] ?? '') && (bool)preg_match("/'mysql'/", $mKilit[0] ?? ''));
preg_match('/function pdks_faz8b_cavus_ucret_odeme_kaydet.*?\n\}\n/s', $cavusBKaynak, $mOK);
okb('71) odeme_kaydet: b_kilit( çağrısı pdks_cari_odeme_ekle(\'den ÖNCE', (bool)preg_match('/pdks_faz8b_cavus_ucret_b_kilit\(.*?pdks_cari_odeme_ekle\(/s', $mOK[0] ?? ''));
preg_match('/function pdks_faz8b_cavus_ucret_odeme_iptal.*?\n\}\n/s', $cavusBKaynak, $mOI);
okb('72) odeme_iptal: b_kilit( çağrısı var', (bool)preg_match('/pdks_faz8b_cavus_ucret_b_kilit\(/', $mOI[0] ?? ''));

// =========================================================
// § O — YENİDEN AÇMA KORUMASI
// =========================================================
echo "\n=== O. YENİDEN AÇMA KORUMASI ===\n";
$entO = (int)$db->query("SELECT entitlement_id FROM foreman_period_closure_items WHERE closure_id = " . (int)$odemeG['kapanis']['kapanis_id'] . " LIMIT 1")->fetchColumn();
$rO = pdks_hakedis_yeniden_ac($entO, 'test', 1, $db);
okb('73) geçerli B kapanışındaki hakediş yeniden AÇILAMAZ (b_kapanisina_dahil)', $rO['ok'] === false && $rO['kod'] === 'b_kapanisina_dahil', json_encode($rO));

// Kaynak doğrulaması: guard function_exists('pdks_faz8b_...')'YE BAĞLI DEĞİL —
// cavus_hakedis_detay.php gibi config/pdks_faz8b.php'yi YÜKLEMEYEN bir ekranda
// da (bkz. §O2 aşağıda) çalışmalı.
$hakedisKaynakO = file_get_contents($root . '/config/pdks_hakedis.php');
preg_match('/function pdks_hakedis_yeniden_ac.*?\n\}\n/s', $hakedisKaynakO, $mReac);
okb('73b) yeniden_ac() B guard\'ı function_exists(\'pdks_faz8b...\') KULLANMIYOR', !preg_match("/function_exists\('pdks_faz8b/", $mReac[0] ?? ''), $mReac[0] ?? '');
okb('73c) yeniden_ac() B guard\'ı bu dosyanın KENDİ pdks_hakedis_tablo_var() yardımcısını kullanıyor', (bool)preg_match("/pdks_hakedis_tablo_var\(\\\$pdo, 'foreman_period_closures'\)/", $mReac[0] ?? ''));

// § O2 — İZOLE ALT SÜREÇ: config/pdks_faz8b.php / pdks_faz8b_cavus_b.php HİÇ
// YÜKLENMEDEN (yalnız config/pdks_hakedis.php) guard'ın YİNE DE reddettiğini
// KANITLAR — cavus_hakedis_detay.php senaryosunun izole tekrarı.
$altSurecKod = <<<'PHP'
<?php
declare(strict_types=1);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function is_admin(): bool { return true; }
require_once '__PDKS_HAKEDIS_YOLU__';
if (function_exists('pdks_faz8b_cavus_ucret_b_sema_hazir') || function_exists('pdks_faz8b_cavus_ucret_yontem')) {
    fwrite(STDERR, "BEKLENMEYEN: pdks_faz8b.php fonksiyonları tanımlı (izolasyon bozuk)\n");
    exit(2);
}
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, foreman_id INTEGER, status TEXT, notes TEXT, updated_at TEXT, finalized_at TEXT, finalized_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_period_closures (id INTEGER PRIMARY KEY, status TEXT)");
$db->exec("CREATE TABLE foreman_period_closure_items (id INTEGER PRIMARY KEY, closure_id INTEGER, entitlement_id INTEGER)");
$db->exec("INSERT INTO foreman_daily_entitlements (id,foreman_id,status) VALUES (1,1,'final')");
$db->exec("INSERT INTO foreman_period_closures (id,status) VALUES (1,'valid')");
$db->exec("INSERT INTO foreman_period_closure_items (id,closure_id,entitlement_id) VALUES (1,1,1)");
$r = pdks_hakedis_yeniden_ac(1, 'test', 1, $db);
echo json_encode($r);
PHP;
$altSurecKod = str_replace('__PDKS_HAKEDIS_YOLU__', $root . '/config/pdks_hakedis.php', $altSurecKod);
$altSurecDosya = sys_get_temp_dir() . '/pdks_cavus_b_altsurec_izole.php';
file_put_contents($altSurecDosya, $altSurecKod);
$cikti = []; $rc = 0;
exec('php ' . escapeshellarg($altSurecDosya) . ' 2>&1', $cikti, $rc);
$ciktiStr = implode("\n", $cikti);
$rIzole = json_decode($ciktiStr, true);
okb('73d) İZOLE alt süreçte (pdks_faz8b.php YÜKLENMEDEN) guard YİNE DE reddeder (b_kapanisina_dahil)',
    $rc === 0 && is_array($rIzole) && ($rIzole['ok'] ?? true) === false && ($rIzole['kod'] ?? '') === 'b_kapanisina_dahil', $ciktiStr);
@unlink($altSurecDosya);

// =========================================================
// § P — RAPOR TUTARLILIĞI
// =========================================================
echo "\n=== P. RAPOR TUTARLILIĞI ===\n";
$cariG = pdks_cari_bakiye($fG, $db);
$toplu = pdks_rapor_cavus_bakiye_toplu([$fG], $db);
okb('74) pdks_rapor_cavus_bakiye_toplu == pdks_cari_bakiye (G, TRY)', ($toplu[$fG]['TRY']['bakiye_kurus'] ?? null) === ($cariG['TRY']['bakiye_kurus'] ?? null), json_encode([$toplu[$fG]['TRY'] ?? null, $cariG['TRY'] ?? null]));
$bakiyeToplu = pdks_rapor_bakiye_toplu(null, $fG, $db);
okb('75) pdks_rapor_bakiye_toplu == pdks_cari_bakiye (G, TRY)', ($bakiyeToplu['TRY']['bakiye_kurus'] ?? null) === ($cariG['TRY']['bakiye_kurus'] ?? null));

$cariL = pdks_cari_bakiye($fL, $db);
$topluL = pdks_rapor_cavus_bakiye_toplu([$fL], $db);
okb('76) pdks_rapor_cavus_bakiye_toplu == pdks_cari_bakiye (L, EUR)', ($topluL[$fL]['EUR']['bakiye_kurus'] ?? null) === ($cariL['EUR']['bakiye_kurus'] ?? null));

$kpiG = pdks_rapor_finansal_kpi('2026-02-01', '2026-02-28', null, $fG, $db);
okb('77) finansal_kpi dönem aralığındaki B kapanışını İÇERİR', ($kpiG['TRY']['hakedis_kurus'] ?? 0) >= 400000, json_encode($kpiG['TRY'] ?? null));
$kpiGDisi = pdks_rapor_finansal_kpi('2026-05-01', '2026-05-31', null, $fG, $db);
okb('78) finansal_kpi aralık DIŞINDAKİ kapanışı içermez', ($kpiGDisi['TRY']['hakedis_kurus'] ?? 0) === 0, json_encode($kpiGDisi));

$trendG = pdks_rapor_gunluk_trend('2026-02-01', '2026-02-01', null, $fG, null, $db);
$gunTrendG = $trendG[0] ?? null;
okb('79) gunluk_trend closure_date gününde hakediş içerir', $gunTrendG !== null && ($gunTrendG['hakedis']['TRY'] ?? '0.00') !== '0.00', json_encode($gunTrendG));

// Çavuş özeti OPERASYONEL özet: yalnız dönemde mesaisi olan çavuşları listeler
// (dönem ödemeleri için de aynı). G'nin Şubat'ta mesaisi yok → önce listede
// OLMADIĞINI, sonra bir mesai eklenince B kapanışının dönem hakedişine
// GİRDİĞİNİ doğrula (eskiden bu test koşulsuz `true` idi).
$ozetGBos = pdks_rapor_cavus_ozeti('2026-02-01', '2026-02-28', null, $fG, null, $db);
okb('80) cavus_ozeti: dönemde mesaisi olmayan çavuş listelenmez (mevcut operasyonel kural)', $ozetGBos === [], json_encode($ozetGBos));
$db->exec("INSERT INTO daily_work_sessions (foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES ({$fG},'Çavuş G','CG','2026-02-10','Depo A','closed')");
$ozetG = pdks_rapor_cavus_ozeti('2026-02-01', '2026-02-28', null, $fG, null, $db);
okb('80b) cavus_ozeti dönem hakedişine B kapanışı (closure_date) DAHİL: 4000.00 TRY',
    count($ozetG) === 1 && ($ozetG[0]['donem_hakedis']['TRY'] ?? null) === '4000.00', json_encode($ozetG[0]['donem_hakedis'] ?? $ozetG));
$ozetGDisi = pdks_rapor_cavus_ozeti('2026-02-02', '2026-02-28', null, $fG, null, $db);
okb('80c) cavus_ozeti: kapanış tarihi (01.02) aralık DIŞINDAYSA dönem hakedişine GİRMEZ',
    count($ozetGDisi) === 1 && ($ozetGDisi[0]['donem_hakedis']['TRY'] ?? '0.00') === '0.00', json_encode($ozetGDisi[0]['donem_hakedis'] ?? $ozetGDisi));

// İptal edilen kapanış sayılmaz.
$kpiMSonra = pdks_rapor_finansal_kpi('2026-01-01', '2026-12-31', null, $fM, $db);
$cariMSonra = pdks_cari_bakiye($fM, $db);
okb('81) iptal edilmiş kapanış finansal_kpi\'de sayılmaz (yalnız GEÇERLİ kapanışlar)', ($kpiMSonra['TRY']['hakedis_kurus'] ?? 0) === ($cariMSonra['TRY']['hakedis_kurus'] ?? -1), json_encode([$kpiMSonra['TRY'] ?? null, $cariMSonra['TRY'] ?? null]));

// =========================================================
// § R — LİSTELER
// =========================================================
echo "\n=== R. LİSTELER ===\n";
$listeAy = pdks_faz8b_cavus_ucret_b_kapanis_listesi($fG, '2026-02-01', '2026-02-28', 'valid', $db);
okb('82) ay filtresi yalnız o ay içindeki kapanışı döner', count($listeAy) === 1, json_encode($listeAy));
$listeIptal = pdks_faz8b_cavus_ucret_b_kapanis_listesi($fM, null, null, 'cancelled', $db);
okb('83) cancelled filtresi yalnız iptal edilenleri döner', count($listeIptal) === 1 && $listeIptal[0]['status'] === 'cancelled', json_encode($listeIptal));
$haritaM = pdks_faz8b_cavus_ucret_b_odeme_kapanis_haritasi($fM, $db);
okb('84) odeme_kapanis_haritasi payment_id anahtarlı, 3 ödeme kaydı taşır', count($haritaM) === 3, json_encode(array_keys($haritaM)));

// =========================================================
// § S — ÖNİZLEME == KAPANIŞ
// =========================================================
echo "\n=== S. ÖNİZLEME == KAPANIŞ ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (18,'C18','Çavuş S1')");
$fS = 18;
pdks_faz8b_cavus_ucret_ekle($fS, '1000', '2020-01-01', 'TRY', 1, $db);
yontemEkle($db, $fS, 'B', '2026-01-01 00:00:00');
kesinGunEkle($db, $fS, '2026-01-05', 30, '2026-01-05 18:00:00');
$onizS = pdks_faz8b_cavus_ucret_b_onizle($fS, '2026-02-01', $db);
$odemeS = pdks_faz8b_cavus_ucret_odeme_kaydet($fS, '2026-02-01', '10', 'TRY', 'BANK', null, null, 1, $db);
okb('85) önizleme adet/devir/tutar kapanışla BİREBİR AYNI', $onizS['adet'] === $odemeS['kapanis']['onizleme']['adet']
    && $onizS['devir_cikan'] === $odemeS['kapanis']['onizleme']['devir_cikan']
    && $onizS['tutar_kurus'] === $odemeS['kapanis']['onizleme']['tutar_kurus'], json_encode([$onizS, $odemeS['kapanis']['onizleme']]));

// =========================================================
// § T — STATİK KAYNAK DOĞRULAMALARI
// =========================================================
echo "\n=== T. STATİK ===\n";
$odemeKaynak = file_get_contents($root . '/cavus_odeme.php');
okb('86) cavus_odeme.php orkestratör çağrılarını kullanıyor', str_contains($odemeKaynak, 'pdks_faz8b_cavus_ucret_odeme_kaydet(') && str_contains($odemeKaynak, 'pdks_faz8b_cavus_ucret_odeme_iptal('));
okb('87) cavus_odeme.php csrf_check() çağırıyor', str_contains($odemeKaynak, 'csrf_check('));
okb('88) cavus_odeme.php require_pdks_cari(\'payments\') çağırıyor', str_contains($odemeKaynak, "require_pdks_cari('payments')"));
okb('89) cavus_odeme.php asim_onay akışı korunuyor', str_contains($odemeKaynak, 'asim_onay'));

$fiyatKaynak = file_get_contents($root . '/cavus_fiyatlari.php');
okb("90) cavus_fiyatlari.php form=cavus_yontem dalı var", str_contains($fiyatKaynak, "'cavus_yontem'"));
okb("91) cavus_fiyatlari.php require_pdks_hakedis('rates') çağırıyor", str_contains($fiyatKaynak, "require_pdks_hakedis('rates')"));
okb('92) cavus_fiyatlari.php kaynağında "CREATE TABLE" YOK', !str_contains($fiyatKaynak, 'CREATE TABLE'));

$donemRaporuKaynak = file_get_contents($root . '/cavus_donem_raporu.php');
okb('93) cavus_donem_raporu.php require_pdks_rapor() çağırıyor', str_contains($donemRaporuKaynak, 'require_pdks_rapor()'));
okb("94) cavus_donem_raporu.php pdks_rapor_can('financial') kapılı", str_contains($donemRaporuKaynak, "pdks_rapor_can('financial')"));
okb("95) cavus_donem_raporu.php require_perm('reports.export') çağırıyor", str_contains($donemRaporuKaynak, "require_perm('reports.export')"));
okb('96) cavus_donem_raporu.php iki export_audit( çağrısı var', substr_count($donemRaporuKaynak, 'export_audit(') === 2);
okb("97) cavus_donem_raporu.php fputcsv ';' '\"' '\\\\' ile çağrılıyor", (bool)preg_match('/fputcsv\([^)]*\'\;\'\s*,\s*\'"\'\s*,\s*\'\\\\\\\\\'\)/', $donemRaporuKaynak));

$helpersKaynak = file_get_contents($root . '/config/helpers.php');
okb("98) nav_ptak_sayfalari() içinde 'cavus_donem_raporu.php' var", (bool)preg_match("/function nav_ptak_sayfalari.*?cavus_donem_raporu\\.php.*?\\n\\}/s", $helpersKaynak));

$phDosyalar = [
    'config/pdks_faz8b.php', 'config/pdks_faz8b_cavus_b.php', 'config/pdks_cari.php', 'config/pdks_hakedis.php',
    'config/pdks_rapor.php', 'cavus_fiyatlari.php', 'cavus_odeme.php', 'cavus_ekstre.php', 'cavus_cari.php',
    'cavus_donem_raporu.php', 'cavus_toplu_dokum.php', 'cavus_toplu_dokum_yazdir.php', 'raporlar.php', 'migrate.php',
];
$phTemiz = true; $phMesaj = '';
foreach ($phDosyalar as $pf) {
    exec('php -l ' . escapeshellarg($root . '/' . $pf) . ' 2>&1', $cikti, $rc);
    if ($rc !== 0) { $phTemiz = false; $phMesaj .= $pf . ': ' . implode("\n", $cikti) . "\n"; }
    $cikti = [];
}
okb('99) tüm yeni/değişen PHP dosyaları php -l temiz', $phTemiz, $phMesaj);

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
