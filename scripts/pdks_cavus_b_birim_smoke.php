<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_cavus_b_birim_smoke.php — v297: Yöntem B birimi ÇAVUŞ BAZINDA
// ("kaç kişi-gün = 1 hakediş", foreman_rate_method_log.unit_size; NULL = 25).
// Varsayılan, çavuş bazında birim, kapanışta donma, devrin yeni birime
// bölünmesi, zaman kuralı, doğrulama, eski tabloda idempotent migrasyon.
// pdks_cavus_b_smoke.php ile AYNI harness (bellek içi SQLite + gerçek DDL
// çevirici + gerçek fonksiyonlar). Canlı DB'ye HİÇ dokunmaz.
//
//   php scripts/pdks_cavus_b_birim_smoke.php   → çıkış kodu 0 = tüm testler geçti
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



function yontemBirimEkle(PDO $db, int $fid, string $method, string $effectiveAt, ?int $unit): int {
    $db->prepare("INSERT INTO foreman_rate_method_log (foreman_id, method, unit_size, effective_at, created_at) VALUES (?,?,?,?,?)")
       ->execute([$fid, $method, $unit, $effectiveAt, $effectiveAt]);
    return (int)$db->lastInsertId();
}
function kapanisSatiri(PDO $db, int $id): array {
    return $db->query("SELECT * FROM foreman_period_closures WHERE id = " . $id)->fetch();
}

ddlUygula($db, pdks_cari_tablolar());
ddlUygula($db, pdks_faz8b_cavus_ucret_tablolar());

// =========================================================
// § 1 — MİGRASYON: ESKİ TABLO (unit_size YOK) → kolon eklenir, idempotent
// =========================================================
echo "=== 1. MİGRASYON (eski tablo) ===\n";
$bDdl = pdks_faz8b_cavus_ucret_b_tablolar();
$eskiLog = preg_replace('/\n\s*`unit_size`\s+INT\s+NULL DEFAULT NULL,/', '', $bDdl['foreman_rate_method_log']);
okb('1) eski DDL (unit_size kolonsuz) üretildi', !str_contains($eskiLog, 'unit_size') && str_contains($bDdl['foreman_rate_method_log'], '`unit_size`          INT          NULL DEFAULT NULL'));
ddlUygula($db, ['foreman_rate_method_log' => $eskiLog, 'foreman_period_closures' => $bDdl['foreman_period_closures'],
    'foreman_period_closure_items' => $bDdl['foreman_period_closure_items']]);
okb('2) eski tabloda birim kolonu YOK', !pdks_faz8b_cavus_ucret_b_birim_kolonu_var($db));

$db->exec("INSERT INTO foremen (id,code,name) VALUES (90,'C90','Eski Çavuş')");
yontemEkle($db, 90, 'B', '2026-01-01 00:00:00');   // eski satır (kolonsuz)
okb('3) kolon yokken birim varsayılan 25', pdks_faz8b_cavus_ucret_birim(90, $db) === 25);
okb('4) kolon yokken geçmiş okunur (unit_size NULL)', array_key_exists('unit_size', pdks_faz8b_cavus_ucret_yontem_gecmisi(90, $db)[0]) && pdks_faz8b_cavus_ucret_yontem_gecmisi(90, $db)[0]['unit_size'] === null);
$rKolonsuz = pdks_faz8b_cavus_ucret_yontem_degistir(90, 'B', 1, $db, '30');
okb('5) kolon yokken 25 dışı birim reddedilir (fail-closed)', $rKolonsuz['ok'] === false && $rKolonsuz['kod'] === 'birim_kolonu_yok', json_encode($rKolonsuz));

$mig1 = pdks_faz8b_cavus_ucret_b_migrate($db);
$kolonSatiri = array_values(array_filter($mig1, fn($r) => $r['tablo'] === 'foreman_rate_method_log.unit_size'));
okb('6) migrasyon kolonu EKLEDİ', count($kolonSatiri) === 1 && $kolonSatiri[0]['durum'] === 'eklendi', json_encode($mig1));
okb('7) kolon artık var', pdks_faz8b_cavus_ucret_b_birim_kolonu_var($db));
$eskiSatir = $db->query("SELECT unit_size FROM foreman_rate_method_log WHERE foreman_id = 90")->fetch();
okb('8) eski satırın unit_size\'ı NULL (veri değişmedi) → birim 25', $eskiSatir['unit_size'] === null && pdks_faz8b_cavus_ucret_birim(90, $db) === 25);
$mig2 = pdks_faz8b_cavus_ucret_b_migrate($db);
okb('9) ikinci migrasyon idempotent (hepsi "var")', count(array_filter($mig2, fn($r) => $r['durum'] !== 'var')) === 0, json_encode($mig2));

// =========================================================
// § 2 — DOĞRULAMA
// =========================================================
echo "\n=== 2. DOĞRULAMA ===\n";
foreach (['0', '-5', 'abc', '1001', '12.5', '1e2', ' 3 0', '99999999'] as $kotu) {
    okb("10) birim '{$kotu}' reddedilir", pdks_faz8b_cavus_ucret_b_birim_dogrula($kotu) === null);
}
okb('11) boş / null → varsayılan 25', pdks_faz8b_cavus_ucret_b_birim_dogrula('') === 25 && pdks_faz8b_cavus_ucret_b_birim_dogrula(null) === 25);
okb('12) 1, 30, 1000 kabul', pdks_faz8b_cavus_ucret_b_birim_dogrula('1') === 1 && pdks_faz8b_cavus_ucret_b_birim_dogrula(' 30 ') === 30 && pdks_faz8b_cavus_ucret_b_birim_dogrula('1000') === 1000);
$db->exec("INSERT INTO foremen (id,code,name) VALUES (91,'C91','Doğrulama Çavuş')");
$oncekiSayi = (int)$db->query("SELECT COUNT(*) FROM foreman_rate_method_log")->fetchColumn();
foreach (['0', '-1', 'x', '1001'] as $kotu) {
    $r = pdks_faz8b_cavus_ucret_yontem_degistir(91, 'B', 1, $db, $kotu);
    okb("13) yontem_degistir B + '{$kotu}' → gecersiz_birim", $r['ok'] === false && $r['kod'] === 'gecersiz_birim', json_encode($r));
}
okb('14) geçersiz birimlerle HİÇBİR geçmiş satırı yazılmadı', (int)$db->query("SELECT COUNT(*) FROM foreman_rate_method_log")->fetchColumn() === $oncekiSayi);
$rA = pdks_faz8b_cavus_ucret_yontem_degistir(91, 'A', 1, $db, 'x');
okb('15) Yöntem A seçilince birim yok sayılır (geçersiz olsa da)', $rA['ok'] === true, json_encode($rA));

// =========================================================
// § 3 — YÖNTEM / BİRİM GEÇMİŞİ (yalnız birim değişimi de yeni satır)
// =========================================================
echo "\n=== 3. BİRİM GEÇMİŞİ ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (92,'C92','Geçmiş Çavuş')");
$AUDIT = [];
$r1 = pdks_faz8b_cavus_ucret_yontem_degistir(92, 'B', 1, $db, '30');
okb('16) A→B (30) kaydedildi', $r1['ok'] && $r1['degisti'] && $r1['birim'] === 30, json_encode($r1));
okb('17) audit unit_size taşıyor', ($AUDIT[0]['new']['unit_size'] ?? null) === 30 && ($AUDIT[0]['module'] ?? '') === 'foreman_rate_method_log');
$r2 = pdks_faz8b_cavus_ucret_yontem_degistir(92, 'B', 1, $db, '30');
okb('18) aynı yöntem + aynı birim → değişiklik yok', $r2['ok'] && !$r2['degisti']);
$r3 = pdks_faz8b_cavus_ucret_yontem_degistir(92, 'B', 1, $db, '35');
okb('19) B→B yalnız birim değişimi YENİ satır yazar', $r3['ok'] && $r3['degisti'] && $r3['eski'] === 'B' && $r3['eski_birim'] === 30 && $r3['birim'] === 35, json_encode($r3));
$satirlar92 = $db->query("SELECT method, unit_size FROM foreman_rate_method_log WHERE foreman_id = 92 ORDER BY id")->fetchAll();
okb('20) geçmişte 2 satır: B/30, B/35 (eski satır değişmedi)', count($satirlar92) === 2 && (int)$satirlar92[0]['unit_size'] === 30 && (int)$satirlar92[1]['unit_size'] === 35, json_encode($satirlar92));
okb('21) şu anki birim 35', pdks_faz8b_cavus_ucret_birim(92, $db) === 35);
$r4 = pdks_faz8b_cavus_ucret_yontem_degistir(92, 'A', 1, $db);
$son92 = $db->query("SELECT method, unit_size FROM foreman_rate_method_log WHERE foreman_id = 92 ORDER BY id DESC LIMIT 1")->fetch();
okb('22) B→A satırında unit_size NULL', $r4['ok'] && $son92['method'] === 'A' && $son92['unit_size'] === null);
okb('23) etiket: B/30 ve varsayılan', pdks_faz8b_cavus_ucret_yontem_etiketi('B', 30) === 'Yöntem B — 30 kişi-gün = 1 hakediş'
    && pdks_faz8b_cavus_ucret_yontem_etiketi('B') === 'Yöntem B — 25 kişi-gün = 1 hakediş'
    && pdks_faz8b_cavus_ucret_yontem_etiketi('A', 30) === 'Yöntem A — Günlük sabit ücret');

// =========================================================
// § 4 — ZAMAN KURALI (SAF birim_anda)
// =========================================================
echo "\n=== 4. ZAMAN KURALI ===\n";
$g = [
    ['id' => 1, 'method' => 'B', 'effective_at' => '2026-01-01 00:00:00', 'unit_size' => 30],
    ['id' => 2, 'method' => 'B', 'effective_at' => '2026-02-01 00:00:00', 'unit_size' => 40],
    ['id' => 3, 'method' => 'B', 'effective_at' => '2026-03-01 00:00:00', 'unit_size' => null],
];
okb('24) hiçbir satır geçerli değilken → 25', pdks_faz8b_cavus_ucret_birim_anda($g, '2025-12-31 00:00:00') === 25);
okb('25) 01-15 → 30', pdks_faz8b_cavus_ucret_birim_anda($g, '2026-01-15 00:00:00') === 30);
okb('26) 02-15 → 40', pdks_faz8b_cavus_ucret_birim_anda($g, '2026-02-15 00:00:00') === 40);
okb('27) 03-15 (NULL satır) → 25', pdks_faz8b_cavus_ucret_birim_anda($g, '2026-03-15 00:00:00') === 25);
okb('28) sırasız geçmişte de aynı sonuç', pdks_faz8b_cavus_ucret_birim_anda([$g[2], $g[0], $g[1]], '2026-02-15 00:00:00') === 40);
okb('29) boş geçmiş → 25', pdks_faz8b_cavus_ucret_birim_anda([], '2026-02-15 00:00:00') === 25);

// =========================================================
// § 5 — KAPANIŞ: varsayılan 25 (NULL)
// =========================================================
echo "\n=== 5. KAPANIŞ — VARSAYILAN 25 ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (93,'C93','Varsayılan Çavuş')");
pdks_faz8b_cavus_ucret_ekle(93, '1000', '2020-01-01', 'TRY', 1, $db);
yontemBirimEkle($db, 93, 'B', '2026-01-01 00:00:00', null);
kesinGunEkle($db, 93, '2026-01-05', 30, '2026-01-05 18:00:00');
kesinGunEkle($db, 93, '2026-01-06', 24, '2026-01-06 18:00:00');
$o93 = pdks_faz8b_cavus_ucret_odeme_kaydet(93, '2026-02-01', '10', 'TRY', 'BANK', null, null, 1, $db);
$k93 = kapanisSatiri($db, (int)$o93['kapanis']['kapanis_id']);
okb('30) NULL birim → kapanış unit_size 25, 54 → 2 hakediş devir 4', (int)$k93['unit_size'] === 25 && (int)$k93['earned_units'] === 2 && (int)$k93['carry_out'] === 4, json_encode($k93));
okb('31) 25\'lik kapanışın ekstre açıklaması bayt bayt eski biçim', pdks_cari_cavus_hakedis_aciklama($k93) === 'Çavuş Hakedişi — 54 kişi-gün (+0 devir) → 2 hakediş × 1.000,00 TRY, devir 4');

// =========================================================
// § 6 — KAPANIŞ: çavuş bazında 30 → 59 = 1 hakediş devir 29
// =========================================================
echo "\n=== 6. KAPANIŞ — BİRİM 30 ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (94,'C94','Otuzluk Çavuş')");
pdks_faz8b_cavus_ucret_ekle(94, '1000', '2020-01-01', 'TRY', 1, $db);
yontemBirimEkle($db, 94, 'B', '2026-01-01 00:00:00', 30);
kesinGunEkle($db, 94, '2026-01-05', 30, '2026-01-05 18:00:00');
kesinGunEkle($db, 94, '2026-01-06', 29, '2026-01-06 18:00:00');
$on94 = pdks_faz8b_cavus_ucret_b_onizle(94, '2026-02-01', $db);
okb('32) önizleme birim 30, metin birimi yazar', $on94['birim'] === 30 && str_contains($on94['mesaj'], '(30 kişi-gün = 1 hakediş)'), $on94['mesaj']);
$o94 = pdks_faz8b_cavus_ucret_odeme_kaydet(94, '2026-02-01', '10', 'TRY', 'BANK', null, null, 1, $db);
$k94id = (int)$o94['kapanis']['kapanis_id'];
$k94 = kapanisSatiri($db, $k94id);
okb('33) 59 kişi-gün → 1 hakediş, devir 29, unit_size 30, tutar 1000', (int)$k94['unit_size'] === 30 && (int)$k94['earned_units'] === 1
    && (int)$k94['carry_out'] === 29 && (float)$k94['amount'] === 1000.0, json_encode($k94));
okb('34) ekstre açıklaması birimi yazar', pdks_cari_cavus_hakedis_aciklama($k94) === 'Çavuş Hakedişi — 59 kişi-gün (+0 devir) → 1 hakediş × 1.000,00 TRY, devir 29 (30 kişi-gün = 1 hakediş)', pdks_cari_cavus_hakedis_aciklama($k94));

// =========================================================
// § 7 — BİRİM DEĞİŞİMİ: eski kapanış DONMUŞ, devir yeni birime bölünür
// =========================================================
echo "\n=== 7. BİRİM DEĞİŞİMİ ===\n";
$onceK94 = $k94;
yontemBirimEkle($db, 94, 'B', '2026-02-15 00:00:00', 20);
okb('35) birim değişince eski kapanış satırı AYNEN (unit 30, adet 1, devir 29)', kapanisSatiri($db, $k94id) == $onceK94);
kesinGunEkle($db, 94, '2026-03-10', 11, '2026-03-10 18:00:00');
$o94b = pdks_faz8b_cavus_ucret_odeme_kaydet(94, '2026-04-01', '10', 'TRY', 'BANK', null, null, 1, $db);
$k94b = kapanisSatiri($db, (int)$o94b['kapanis']['kapanis_id']);
okb('36) devir 29 + 11 = 40 → yeni birim 20 ile 2 hakediş, devir 0', (int)$k94b['unit_size'] === 20 && (int)$k94b['carry_in'] === 29
    && (int)$k94b['period_person_days'] === 11 && (int)$k94b['earned_units'] === 2 && (int)$k94b['carry_out'] === 0, json_encode($k94b));
okb('37) ilk kapanış yine değişmedi', kapanisSatiri($db, $k94id) == $onceK94);

// Zaman kuralı uçtan uca: gelecekte geçerli olacak birim bugünkü kapanışa UYGULANMAZ.
yontemBirimEkle($db, 94, 'B', '2099-01-01 00:00:00', 50);
kesinGunEkle($db, 94, '2026-04-10', 45, '2026-04-10 18:00:00');
$on94c = pdks_faz8b_cavus_ucret_b_onizle(94, date('Y-m-d'), $db);
okb('38) gelecek tarihli birim (50) bugünkü kapanışa uygulanmaz → 20', $on94c['birim'] === 20 && $on94c['adet'] === 2 && $on94c['devir_cikan'] === 5, json_encode(['birim' => $on94c['birim'], 'adet' => $on94c['adet'], 'devir' => $on94c['devir_cikan']]));

// İptal sonrası yeniden kapanış o anki birimi kullanır, iptal edilen satır silinmez.
$iptal = pdks_faz8b_cavus_ucret_odeme_iptal((int)$o94b['id'], 'test', 1, $db);
okb('39) son kapanış iptal edildi (satır durur, cancelled)', $iptal['ok'] && kapanisSatiri($db, (int)$o94b['kapanis']['kapanis_id'])['status'] === 'cancelled', json_encode($iptal));

// =========================================================
// § 8 — STATİK
// =========================================================
echo "\n=== 8. STATİK ===\n";
$fiyatSrc = (string)file_get_contents($root . '/cavus_fiyatlari.php');
okb('40) cavus_fiyatlari.php birim alanı (cavus_birim, min 1 max 1000) + CSRF\'li yöntem formu', str_contains($fiyatSrc, 'name="cavus_birim"')
    && str_contains($fiyatSrc, 'PDKS_FAZ8B_CAVUS_B_BIRIM_MAX') && str_contains($fiyatSrc, 'value="cavus_yontem"') && str_contains($fiyatSrc, "csrf_check(\$_POST['csrf'] ?? null)"));
foreach (['cavus_fiyatlari.php', 'cavus_odeme.php', 'cavus_donem_raporu.php', 'cavus_toplu_dokum.php', 'cavus_toplu_dokum_yazdir.php', 'config/pdks_faz8b.php', 'config/pdks_faz8b_cavus_b.php'] as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    okb("41) {$f}: sabit '25 kişi-gün' metni yok", !preg_match("/['\"(>]\s*25 kişi-gün|Yöntem B — 25|her 25/u", $src));
}
okb('42) kapanış INSERT\'ü unit_size yazmaya devam ediyor', (bool)preg_match('/INSERT INTO foreman_period_closures.*?unit_size/s', (string)file_get_contents($root . '/config/pdks_faz8b_cavus_b.php')));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
