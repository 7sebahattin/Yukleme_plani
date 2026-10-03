<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_servis_smoke.php — Servis Ücreti (v299) davranış testi.
//
// Bellek içi SQLite + GERÇEK DDL çevirici (pdks_cavus_ucret_smoke.php
// harness'i) + gerçek fonksiyonlar. Canlı DB'ye dokunmaz.
// Kapsam: fiyat/tutar, fiyat yok (giriş reddi + hesapta eksik), kesin hakediş
// reddi, taslak needs_recalculation, iptal (soft), istek_id tekrarı, karışık
// para birimi, Çavuş Ücreti dedektörleri (A + B) servis satırına TAKILMAZ,
// cari yansıması, tablo yokken eski davranış, statik kapılar.
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function okcu(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function db(): PDO { global $db; return $db; }
$AKTIF = 'Depo A';
function active_depot(): ?string { global $AKTIF; return $AKTIF; }
$ADMIN = true;
function is_admin(): bool { global $ADMIN; return $ADMIN; }
function can(string $p): bool { return true; }
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid, 'new' => $n];
}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_faz8b_cavus_b.php';
require_once $root . '/config/pdks_faz8j.php';
require_once $root . '/config/pdks_servis.php';
require_once $root . '/config/pdks_cari.php';

// ── MySQL DDL → SQLite çevirici — pdks_faz9d_smoke.php İLE BİREBİR AYNI
//    (kasıtlı kopya): foreman_daily_rates'in INDEX/CONSTRAINT içeren GERÇEK
//    DDL'ini pdks_faz8b_cavus_ucret_migrate() üzerinden GERÇEKTEN çalıştırmak için.
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

// ── Şema — pdks_faz9c_smoke.php'nin Faz 8A temeliyle AYNI (finalize()'ın
//    çağırdığı pdks_gunluk_oturum_ozet() Faz 8A yoluna düşsün diye). ──
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın')");
$kadinId = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
// cari_bakiye()'nin okuduğu minimum foreman_payments — bu test ödeme akışını sınamıyor.
$db->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, currency TEXT, amount TEXT, payment_date TEXT, status TEXT DEFAULT 'valid')");
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, display_name TEXT, is_active INTEGER DEFAULT 1)");
$db->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test Yönetici')");

function ddlKur(PDO $db, array $tablolar): void {
    foreach ($tablolar as $sql) {
        [$create, $indeksler] = pdks_ddl_sqlite($sql);
        $db->exec($create);
        foreach ($indeksler as $ix) $db->exec($ix);
    }
}
function donemEkle(PDO $db, int $sid, int $cardId, int $tipId, string $day): void {
    $db->prepare("INSERT INTO worker_cards (id,card_no) VALUES (?,?)")->execute([$cardId, 'K' . $cardId]);
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,declared_attendance_class,status)
        VALUES (?,?,?,?,?,?,'tam','closed')")->execute([$sid, $cardId, $tipId, 'Kadın', "$day 08:00:00", "$day 17:00:00"]);
}
function mesaiEkle(PDO $db, int $sid, int $fid, string $day, string $depo, string $status = 'closed'): void {
    $db->prepare("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,?,?)")
        ->execute([$sid, $fid, 'Çavuş ' . $fid, 'C' . $fid, $day, $depo, $status]);
}
function istek(): string { return bin2hex(random_bytes(16)); }
function servisSatir(array $lines, string $kod): ?array {
    foreach ($lines as $l) if (($l['worker_type_code_snapshot'] ?? '') === $kod) return $l;
    return null;
}

$day = date('Y-m-d', strtotime('-3 days'));
ddlKur($db, pdks_faz8b_cavus_ucret_tablolar());

// =========================================================
echo "=== A. TABLO YOKKEN ESKİ DAVRANIŞ ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C1','Çavuş 1')");
pdks_faz8b_oran_ekle(1, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
mesaiEkle($db, 1, 1, $day, 'Depo A');
donemEkle($db, 1, 1, $kadinId, $day);
okcu('A1) şema hazır DEĞİL', pdks_servis_sema_hazir($db) === false);
$h = pdks_faz8b_hakedis_hesapla(1, 1, $db);
okcu('A2) tablo yokken hesap eskisi gibi (yalnız işçi satırı, 1500)', $h['ok'] && count($h['lines']) === 1 && $h['total_amount'] === '1500.00', json_encode($h, JSON_UNESCAPED_UNICODE));
okcu('A3) tablo yokken toplamlar sıfır, liste boş', array_sum(pdks_servis_toplamlar(1, $db)) === 0 && pdks_servis_listele(1, $db) === []);
$r = pdks_servis_ekle(1, 1, 0, '', istek(), 1, $db);
okcu('A4) tablo yokken ekleme reddedilir', $r['ok'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));

// =========================================================
echo "\n=== B. MİGRASYON ===\n";
ddlKur($db, pdks_servis_tablolar());
okcu('B1) şema hazır (gerçek DDL çevrildi)', pdks_servis_sema_hazir($db));
$mig = pdks_servis_migrate($db);
okcu('B2) migrasyon idempotent ("var")', count(array_filter($mig, fn($x) => $x['durum'] === 'var')) === 2, json_encode($mig, JSON_UNESCAPED_UNICODE));
$k8b = file_get_contents($root . '/config/pdks_faz8b.php');
preg_match('/function pdks_faz8b_sema_hazir.*?\n\}\n/s', $k8b, $mS);
okcu('B3) pdks_faz8b_sema_hazir() servis tablolarını İÇERMEZ', ($mS[0] ?? '') !== '' && !preg_match('/daily_session_services|foreman_service_rates/', $mS[0]));

// =========================================================
echo "\n=== C. FİYAT TANIMI ===\n";
okcu('C1) boş iki fiyat reddedilir', pdks_servis_ucret_ekle(1, '', '', '2020-01-01', 'TRY', 1, $db)['ok'] === false);
okcu('C2) geçersiz fiyat reddedilir', pdks_servis_ucret_ekle(1, 'abc', '', '2020-01-01', 'TRY', 1, $db)['ok'] === false);
okcu('C3) geçersiz tarih reddedilir', pdks_servis_ucret_ekle(1, '500', '', '01-01-2020', 'TRY', 1, $db)['ok'] === false);
okcu('C4) bilinmeyen çavuş reddedilir', pdks_servis_ucret_ekle(999, '500', '', '2020-01-01', 'TRY', 1, $db)['ok'] === false);
$r = pdks_servis_ekle(1, 1, 0, '', istek(), 1, $db);
okcu('C5) v300: fiyat tanımsızken de giriş KABUL edilir, fiyatsız tür bilgi olarak döner', $r['ok'] === true && ($r['fiyatsiz'] ?? null) === ['Büyük'], json_encode($r, JSON_UNESCAPED_UNICODE));
// Sonraki senaryoların sayımı bozulmasın: test kaydını doğrudan temizle (üretimde silme yok).
if (!empty($r['ok'])) {
    $db->exec("DELETE FROM daily_session_services WHERE batch_id = " . $db->quote((string)$r['batch_id']));
    $db->exec("DELETE FROM audit_log WHERE action = 'servis_ekle'");
}
$AUDIT = [];
$f1 = pdks_servis_ucret_ekle(1, '400', '', '2020-01-01', 'TRY', 1, $db);
okcu('C6) yalnız Büyük fiyatlı dönem kabul', $f1['ok'] === true, json_encode($f1, JSON_UNESCAPED_UNICODE));
okcu('C7) audit create/foreman_service_rates', count(array_filter($AUDIT, fn($a) => $a['action'] === 'create' && $a['module'] === 'foreman_service_rates')) === 1);
$r = pdks_servis_ekle(1, 0, 2, '', istek(), 1, $db);
okcu('C8) v300: Küçük fiyatı yokken Küçük girişi KABUL edilir, fiyatsiz=[Küçük]', $r['ok'] === true && ($r['fiyatsiz'] ?? null) === ['Küçük'], json_encode($r, JSON_UNESCAPED_UNICODE));
if (!empty($r['ok'])) {
    $db->exec("DELETE FROM daily_session_services WHERE batch_id = " . $db->quote((string)$r['batch_id']));
    $db->exec("DELETE FROM audit_log WHERE action = 'servis_ekle'");
}
okcu('C9) aynı/önceki başlangıç reddedilir', pdks_servis_ucret_ekle(1, '450', '250', '2020-01-01', 'TRY', 1, $db)['ok'] === false);
$f2 = pdks_servis_ucret_ekle(1, '500', '250,50', '2021-01-01', 'TRY', 1, $db);
okcu('C10) sonraki dönem kabul', $f2['ok'] === true);
$eski = $db->query("SELECT valid_to FROM foreman_service_rates WHERE id = {$f1['id']}")->fetchColumn();
okcu('C11) önceki dönem bir gün önce kapanır', $eski === '2020-12-31', (string)$eski);
$g = pdks_servis_ucret_gecerli(1, $day, $db);
okcu('C12) geçerli fiyat = 500 / 250,50', $g && (float)$g['big_rate'] === 500.0 && (float)$g['small_rate'] === 250.5, json_encode($g));
okcu('C13) 2020 içi geçerli fiyat = eski dönem (Küçük yok)', pdks_servis_birim_kurus(pdks_servis_ucret_gecerli(1, '2020-06-01', $db), 'KUCUK') === null
    && pdks_servis_birim_kurus(pdks_servis_ucret_gecerli(1, '2020-06-01', $db), 'BUYUK') === 40000);
okcu('C14) geçmiş 2 satır', count(pdks_servis_ucret_gecmisi(1, $db)) === 2);

// =========================================================
echo "\n=== D. EKLEME KAPILARI ===\n";
$ADMIN = false;
okcu('D1) admin değilse reddedilir', pdks_servis_ekle(1, 1, 0, '', istek(), 1, $db)['ok'] === false);
$ADMIN = true;
$AKTIF = 'Depo B';
okcu('D2) aktif depo ≠ mesai deposu reddedilir', pdks_servis_ekle(1, 1, 0, '', istek(), 1, $db)['ok'] === false);
$AKTIF = 'Depo A';
okcu('D3) istek_id yoksa reddedilir', pdks_servis_ekle(1, 1, 0, '', '', 1, $db)['ok'] === false);
okcu('D4) istek_id biçimsizse reddedilir', pdks_servis_ekle(1, 1, 0, '', 'xyz!', 1, $db)['ok'] === false);
okcu('D5) 0 + 0 reddedilir', pdks_servis_ekle(1, 0, 0, '', istek(), 1, $db)['ok'] === false);
okcu('D6) negatif reddedilir', pdks_servis_ekle(1, -1, 2, '', istek(), 1, $db)['ok'] === false);
okcu('D7) üst sınır aşımı reddedilir', pdks_servis_ekle(1, PDKS_SERVIS_MAX_ADET + 1, 0, '', istek(), 1, $db)['ok'] === false);
okcu('D8) bulunmayan mesai reddedilir', pdks_servis_ekle(9999, 1, 0, '', istek(), 1, $db)['ok'] === false);
mesaiEkle($db, 2, 1, date('Y-m-d', strtotime('+2 days')), 'Depo A', 'open');
okcu('D9) gelecek tarihli mesai reddedilir', pdks_servis_ekle(2, 1, 0, '', istek(), 1, $db)['ok'] === false);
okcu('D10) reddedilen denemeler satır YAZMADI', (int)$db->query("SELECT COUNT(*) FROM daily_session_services")->fetchColumn() === 0);

// =========================================================
echo "\n=== E. EKLE + HESAP (çoklu kayıt toplanır) ===\n";
pdks_faz8b_hakedis_hesapla(1, 1, $db);   // taslak oluşsun
$db->exec("UPDATE foreman_daily_entitlements SET needs_recalculation = 0 WHERE session_id = 1");
$ist1 = istek();
$e1 = pdks_servis_ekle(1, 2, 1, 'Sabah servisi', $ist1, 1, $db);
okcu('E1) Büyük 2 + Küçük 1 eklenir', $e1['ok'] === true, json_encode($e1, JSON_UNESCAPED_UNICODE));
$rows = $db->query("SELECT * FROM daily_session_services ORDER BY id")->fetchAll();
okcu('E2) tür başına 1 satır, ortak batch_id', count($rows) === 2 && $rows[0]['batch_id'] === $rows[1]['batch_id'] && $rows[0]['batch_id'] === ($e1['batch_id'] ?? null));
okcu('E3) istek_id yalnız ilk satırda', ($rows[0]['istek_id'] ?? null) === $ist1 && count($rows) === 2 && $rows[1]['istek_id'] === null);
okcu('E4) depo/çavuş/tarih mesaiden', ($rows[0]['depo'] ?? '') === 'Depo A' && (int)($rows[0]['foreman_id'] ?? 0) === 1 && ($rows[0]['work_date'] ?? '') === $day);
okcu('E5) taslak hakediş needs_recalculation=1', (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=1")->fetchColumn() === 1);
$au = $db->query("SELECT * FROM audit_log WHERE action='servis_ekle'")->fetchAll();
okcu('E6) audit servis_ekle (modül daily_work_sessions, record_id = mesai)', count($au) === 1 && $au[0]['module'] === 'daily_work_sessions' && (int)$au[0]['record_id'] === 1);
$tekrar = pdks_servis_ekle(1, 2, 1, 'Sabah servisi', $ist1, 1, $db);
okcu('E7) aynı istek_id tekrar → reddedilir (tekrar)', $tekrar['ok'] === false && !empty($tekrar['tekrar']), json_encode($tekrar, JSON_UNESCAPED_UNICODE));
okcu('E8) tekrar satır YAZMADI', (int)$db->query("SELECT COUNT(*) FROM daily_session_services")->fetchColumn() === 2);
try {
    $db->prepare("INSERT INTO daily_session_services (session_id,foreman_id,work_date,depo,service_type,quantity,istek_id) VALUES (1,1,?,'Depo A','BUYUK',1,?)")->execute([$day, $ist1]);
    okcu('E9) istek_id UNIQUE (DB ayrıca korur)', false);
} catch (PDOException $x) { okcu('E9) istek_id UNIQUE (DB ayrıca korur)', (string)$x->getCode() === '23000'); }
$e2 = pdks_servis_ekle(1, 1, 0, '', istek(), 1, $db);
okcu('E10) aynı mesaiye ikinci kayıt eklenebilir', $e2['ok'] === true);
okcu('E11) toplamlar: Büyük 3, Küçük 1', pdks_servis_toplamlar(1, $db) === ['BUYUK' => 3, 'KUCUK' => 1], json_encode(pdks_servis_toplamlar(1, $db)));
$h = pdks_faz8b_hakedis_hesapla(1, 1, $db);
$sb = servisSatir($h['lines'] ?? [], 'SERVIS_BUYUK');
$sk = servisSatir($h['lines'] ?? [], 'SERVIS_KUCUK');
okcu('E12) Büyük satırı: 3 × 500 = 1500 (NULL/NULL, ad)', $sb && $sb['worker_count'] === 3 && $sb['unit_rate'] === '500.00' && $sb['line_total'] === '1500.00' && $sb['worker_type_id'] === null && $sb['work_period_id'] === null && $sb['worker_type_name_snapshot'] === 'Servis — Büyük', json_encode($sb, JSON_UNESCAPED_UNICODE));
okcu('E13) Küçük satırı: 1 × 250,50 = 250,50', $sk && $sk['worker_count'] === 1 && $sk['line_total'] === '250.50', json_encode($sk, JSON_UNESCAPED_UNICODE));
okcu('E14) toplam 1500 + 1500 + 250,50 = 3250,50 (kuruş)', ($h['total_amount'] ?? '') === '3250.50', json_encode($h, JSON_UNESCAPED_UNICODE));
$dbL = $db->query("SELECT l.* FROM foreman_daily_entitlement_lines l JOIN foreman_daily_entitlements e ON e.id = l.entitlement_id WHERE e.session_id = 1 AND l.worker_type_code_snapshot LIKE 'SERVIS%'")->fetchAll();
okcu('E15) servis satırları DB\'ye yazıldı (kod SERVIS_*)', count($dbL) === 2);
okcu('E16) pdks_servis_satiri_mi() satırı tanır', pdks_servis_satiri_mi($dbL[0] ?? []) && !pdks_servis_satiri_mi(['worker_type_code_snapshot' => '']));

// =========================================================
echo "\n=== F. İPTAL ===\n";
$sid2 = (int)$db->query("SELECT id FROM daily_session_services WHERE batch_id = " . $db->quote((string)($e2['batch_id'] ?? '')))->fetchColumn();
okcu('F1) gerekçesiz iptal reddedilir', pdks_servis_iptal($sid2, 1, '  ', 1, $db)['ok'] === false);
okcu('F2) başka mesai id ile iptal reddedilir', pdks_servis_iptal($sid2, 2, 'yanlış', 1, $db)['ok'] === false);
$ADMIN = false;
okcu('F3) admin değilse iptal reddedilir', pdks_servis_iptal($sid2, 1, 'yanlış', 1, $db)['ok'] === false);
$ADMIN = true;
$db->exec("UPDATE foreman_daily_entitlements SET needs_recalculation = 0 WHERE session_id = 1");
$ip = pdks_servis_iptal($sid2, 1, 'Yanlış girildi', 1, $db);
okcu('F4) iptal başarılı', $ip['ok'] === true, json_encode($ip, JSON_UNESCAPED_UNICODE));
$v = $db->query("SELECT * FROM daily_session_services WHERE id = $sid2")->fetch();
okcu('F5) soft: satır duruyor, is_voided=1 + gerekçe + kim', $v && (int)$v['is_voided'] === 1 && $v['void_reason'] === 'Yanlış girildi' && (int)$v['voided_by_user_id'] === 1 && $v['voided_at'] !== null);
okcu('F6) iki kez iptal reddedilir', pdks_servis_iptal($sid2, 1, 'tekrar', 1, $db)['ok'] === false);
okcu('F7) iptal sonrası toplam Büyük 2', pdks_servis_toplamlar(1, $db)['BUYUK'] === 2);
okcu('F8) iptal taslağa needs_recalculation koyar', (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=1")->fetchColumn() === 1);
okcu('F9) audit servis_iptal', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='servis_iptal' AND module='daily_work_sessions' AND record_id=1")->fetchColumn() === 1);
$liste = pdks_servis_listele(1, $db);
okcu('F10) liste iptalleri de gösterir (3 satır, ekleyen adı)', count($liste) === 3 && ($liste[0]['ekleyen'] ?? '') === 'Test Yönetici', json_encode($liste, JSON_UNESCAPED_UNICODE));
$h = pdks_faz8b_hakedis_hesapla(1, 1, $db);
okcu('F11) iptal sonrası hesap 1500 + 1000 + 250,50 = 2750,50', ($h['total_amount'] ?? '') === '2750.50', json_encode($h, JSON_UNESCAPED_UNICODE));
$etk = array_column(pdks_gunluk_puantaj_denetim_gecmisi([], $db, 20, 1), 'islem_etiket');
okcu('F12) mesai işlem geçmişi servis_ekle/servis_iptal etiketli', in_array('🚌 Servis eklendi', $etk, true) && in_array('🚌 Servis iptal edildi', $etk, true), json_encode($etk, JSON_UNESCAPED_UNICODE));

// =========================================================
echo "\n=== G. KESİN HAKEDİŞ + CARİ ===\n";
$fin = pdks_faz8b_hakedis_finalize(1, 1, false, $db);
okcu('G1) servisli hakediş kesinleşir', $fin['ok'] === true && ($fin['total_amount'] ?? '') === '2750.50', json_encode($fin, JSON_UNESCAPED_UNICODE));
$r = pdks_servis_ekle(1, 1, 0, '', istek(), 1, $db);
okcu('G2) kesin hakedişte ekleme REDDEDİLİR (önce yeniden açın)', $r['ok'] === false && str_contains($r['hata'], 'yeniden aç'), json_encode($r, JSON_UNESCAPED_UNICODE));
$sid1 = (int)$db->query("SELECT id FROM daily_session_services WHERE session_id=1 AND is_voided=0 ORDER BY id LIMIT 1")->fetchColumn();
okcu('G3) kesin hakedişte iptal REDDEDİLİR', pdks_servis_iptal($sid1, 1, 'deneme', 1, $db)['ok'] === false);
$bak = pdks_cari_bakiye(1, $db);
okcu('G4) cari bakiye servis DAHİL 2750,50', ($bak['TRY']['bakiye'] ?? null) === '2750.50', json_encode($bak));

// =========================================================
echo "\n=== H. YÖNTEM A — ÇAVUŞ ÜCRETİ DEDEKTÖRÜ SERVİSE TAKILMAZ ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (3,'C3','Çavuş 3')");
pdks_faz8b_oran_ekle(3, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
pdks_faz8b_cavus_ucret_ekle(3, '700', '2020-01-01', 'TRY', 1, $db);
pdks_servis_ucret_ekle(3, '300', '150', '2020-01-01', 'TRY', 1, $db);
mesaiEkle($db, 30, 3, $day, 'Depo A');
mesaiEkle($db, 31, 3, $day, 'Depo B');
donemEkle($db, 30, 30, $kadinId, $day);
donemEkle($db, 31, 31, $kadinId, $day);
$AKTIF = 'Depo B';
okcu('H1) ankor olmayan mesaiye (31) servis eklenir', pdks_servis_ekle(31, 1, 1, '', istek(), 1, $db)['ok'] === true);
$AKTIF = 'Depo A';
$h31 = pdks_faz8b_hakedis_hesapla(31, 1, $db);
okcu('H2) 31: işçi + servis, çavuş ücreti YOK (ankor değil) = 1500 + 300 + 150', $h31['ok'] && $h31['total_amount'] === '1950.00', json_encode($h31, JSON_UNESCAPED_UNICODE));
okcu('H3) 31 kesinleşir', pdks_faz8b_hakedis_finalize(31, 1, false, $db)['ok'] === true);
okcu('H4) baska_final_var_mi: servis satırı Çavuş Ücreti SAYILMAZ', pdks_faz8b_cavus_ucret_baska_final_var_mi(3, $day, 30, $db) === false);
$h30 = pdks_faz8b_hakedis_hesapla(30, 1, $db);
$cav = array_values(array_filter($h30['lines'] ?? [], fn($x) => $x['worker_type_id'] === null && $x['work_period_id'] === null && $x['worker_type_code_snapshot'] === ''));
okcu('H5) ankor (30) Yöntem A günlük çavuş ücretini HÂLÂ alır (1500 + 700)', $h30['ok'] && count($cav) === 1 && $h30['total_amount'] === '2200.00', json_encode($h30, JSON_UNESCAPED_UNICODE));
$fin30 = pdks_faz8b_hakedis_finalize(30, 1, false, $db);
okcu('H6) gerçek Çavuş Ücreti satırı hâlâ algılanır (31 için baska_final=true)', $fin30['ok'] && pdks_faz8b_cavus_ucret_baska_final_var_mi(3, $day, 31, $db) === true);

// =========================================================
echo "\n=== I. YÖNTEM B — HAVUZ / KİŞİ-GÜN DEĞİŞMEZ ===\n";
ddlKur($db, pdks_faz8b_cavus_ucret_b_tablolar());
okcu('I0) B şeması hazır', pdks_faz8b_cavus_ucret_b_sema_hazir($db));
$db->exec("INSERT INTO foremen (id,code,name) VALUES (4,'C4','Çavuş 4')");
pdks_faz8b_oran_ekle(4, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
pdks_faz8b_cavus_ucret_ekle(4, '2000', '2020-01-01', 'TRY', 1, $db);
pdks_servis_ucret_ekle(4, '300', '150', '2020-01-01', 'TRY', 1, $db);
$yB = pdks_faz8b_cavus_ucret_yontem_degistir(4, 'B', 1, $db, '25');
okcu('I1) çavuş 4 Yöntem B', $yB['ok'] === true && pdks_faz8b_cavus_ucret_yontem(4, $db) === 'B', json_encode($yB, JSON_UNESCAPED_UNICODE));
mesaiEkle($db, 40, 4, $day, 'Depo A');
donemEkle($db, 40, 40, $kadinId, $day);
donemEkle($db, 40, 41, $kadinId, $day);
donemEkle($db, 40, 42, $kadinId, $day);
okcu('I2) B çavuşuna servis eklenir', pdks_servis_ekle(40, 2, 1, '', istek(), 1, $db)['ok'] === true);
$h40 = pdks_faz8b_hakedis_hesapla(40, 1, $db);
okcu('I3) B: servis ödenir, günlük çavuş ücreti YOK (3×1500 + 2×300 + 150 = 5250)', $h40['ok'] && $h40['total_amount'] === '5250.00', json_encode($h40, JSON_UNESCAPED_UNICODE));
sleep(1);   // finalized_at > yöntem effective_at
okcu('I4) B hakedişi kesinleşir', pdks_faz8b_hakedis_finalize(40, 1, false, $db)['ok'] === true);
$adaylar = pdks_faz8b_cavus_ucret_b_aday_kalemler(4, pdks_faz8b_cavus_ucret_yontem_gecmisi(4, $db), $db);
okcu('I5) aday kalem: kişi-gün 3 (servis adedi SAYILMAZ), cavus_satiri 0', count($adaylar) === 1 && $adaylar[0]['kisi_gun'] === 3 && $adaylar[0]['cavus_satiri'] === 0, json_encode($adaylar));
$on = pdks_faz8b_cavus_ucret_b_onizle(4, date('Y-m-d'), $db);
okcu('I6) B önizleme: dönem kişi-günü 3 (gün havuzdan DÜŞMEDİ)', (int)($on['donem_kisi_gun'] ?? -1) === 3 && count($on['kalemler'] ?? []) === 1, json_encode($on['donem_kisi_gun'] ?? null));

// =========================================================
echo "\n=== J. FİYAT SONRADAN KALKARSA HESAPTA EKSİK + KARIŞIK PARA ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (5,'C5','Çavuş 5')");
pdks_faz8b_oran_ekle(5, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
pdks_servis_ucret_ekle(5, '300', '', '2020-01-01', 'TRY', 1, $db);
mesaiEkle($db, 50, 5, $day, 'Depo A');
donemEkle($db, 50, 50, $kadinId, $day);
okcu('J1) servis eklenir', pdks_servis_ekle(50, 1, 0, '', istek(), 1, $db)['ok'] === true);
$db->exec("UPDATE foreman_service_rates SET is_active = 0 WHERE foreman_id = 5");
$hJ = pdks_faz8b_hakedis_hesapla(50, 1, $db);
okcu('J2) fiyat yoksa hesap DURUR (eksik: Servis — Büyük — geçerli fiyat yok)', $hJ['ok'] === false && in_array('Servis — Büyük — geçerli fiyat yok', $hJ['eksikler'] ?? [], true), json_encode($hJ, JSON_UNESCAPED_UNICODE));
okcu('J3) eksikte hiçbir hakediş yazılmadı', (int)$db->query("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id=50")->fetchColumn() === 0);
pdks_servis_ucret_ekle(5, '20', '', date('Y-m-d', strtotime('-30 days')), 'USD', 1, $db);
$db->exec("UPDATE foreman_service_rates SET valid_from = '2020-01-01' WHERE foreman_id = 5 AND currency = 'USD'");
$hK = pdks_faz8b_hakedis_hesapla(50, 1, $db);
okcu('J4) servis USD ≠ işçi TRY → karisik_para_birimi', $hK['ok'] === false && ($hK['kod'] ?? '') === 'karisik_para_birimi', json_encode($hK, JSON_UNESCAPED_UNICODE));

// =========================================================
echo "\n=== L. v300: FİYATSIZ GİRİŞ → FİYAT SONRADAN → OTOMATİK HESAP ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (6,'C6','Çavuş 6')");
pdks_faz8b_oran_ekle(6, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
mesaiEkle($db, 60, 6, $day, 'Depo A');
donemEkle($db, 60, 60, $kadinId, $day);
okcu('L1) fiyatsız çavuşta fiyatsız-tarih yok (henüz servis yok)', pdks_servis_fiyatsiz_en_eski_tarih(6, $db) === null);
$rL = pdks_servis_ekle(60, 2, 1, 'fiyat yokken', istek(), 1, $db);
okcu('L2) fiyat YOKKEN servis girişi kabul (Büyük+Küçük fiyatsız)', $rL['ok'] === true && ($rL['fiyatsiz'] ?? null) === ['Büyük', 'Küçük'], json_encode($rL, JSON_UNESCAPED_UNICODE));
okcu('L3) kayıtlar yazıldı (2 satır)', (int)$db->query("SELECT COUNT(*) FROM daily_session_services WHERE session_id=60 AND is_voided=0")->fetchColumn() === 2);
okcu('L4) fiyatsız en eski tarih = mesai günü', pdks_servis_fiyatsiz_en_eski_tarih(6, $db) === $day, (string)pdks_servis_fiyatsiz_en_eski_tarih(6, $db));
$hL = pdks_faz8b_hakedis_hesapla(60, 1, $db);
okcu('L5) fiyat yokken hakediş DURUR (fail-closed, Büyük+Küçük eksik)', $hL['ok'] === false
    && in_array('Servis — Büyük — geçerli fiyat yok', $hL['eksikler'] ?? [], true)
    && in_array('Servis — Küçük — geçerli fiyat yok', $hL['eksikler'] ?? [], true), json_encode($hL, JSON_UNESCAPED_UNICODE));
okcu('L6) durdu: hiçbir hakediş satırı yazılmadı', (int)$db->query("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id=60")->fetchColumn() === 0);
// Bir önceki (fiyatsız) hesaplama başarısız → taslak yok. Önce işçi-yalnız bir taslak oluşturup
// fiyat eklenince "yeniden hesaplanmalı" işaretini sına: taslağı elle kur.
$db->exec("INSERT INTO foreman_daily_entitlements (session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,needs_recalculation) VALUES (60,6,'Çavuş 6','C6','{$day}','Depo A','draft','TRY','1500.00',0)");
$fL = pdks_servis_ucret_ekle(6, '400', '250', $day, 'TRY', 1, $db);
okcu('L7) fiyat sonradan tanımlanır (geçerlilik = mesai günü)', $fL['ok'] === true, json_encode($fL, JSON_UNESCAPED_UNICODE));
okcu('L8) servisi olan TASLAK hakediş yeniden hesaplamaya işaretlendi', ($fL['isaretlenen'] ?? 0) === 1
    && (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=60")->fetchColumn() === 1, json_encode($fL, JSON_UNESCAPED_UNICODE));
okcu('L9) fiyat gelince fiyatsız-tarih kalmadı', pdks_servis_fiyatsiz_en_eski_tarih(6, $db) === null);
$db->exec("DELETE FROM foreman_daily_entitlements WHERE session_id=60");
$hL2 = pdks_faz8b_hakedis_hesapla(60, 1, $db);
okcu('L10) hesap otomatik geçer: 1500 işçi + 2×400 + 1×250 = 2550', $hL2['ok'] === true && $hL2['total_amount'] === '2550.00', json_encode($hL2, JSON_UNESCAPED_UNICODE));
$satirKodlari = $db->query("SELECT worker_type_code_snapshot FROM foreman_daily_entitlement_lines l JOIN foreman_daily_entitlements e ON e.id=l.entitlement_id WHERE e.session_id=60 AND l.worker_type_code_snapshot LIKE 'SERVIS\_%' ESCAPE '\\' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
okcu('L11) servis satırları SERVIS_BUYUK + SERVIS_KUCUK kodlu', $satirKodlari === ['SERVIS_BUYUK', 'SERVIS_KUCUK'], json_encode($satirKodlari));
// kesin hakedişe fiyat eklemek onu işaretlemez/değiştirmez
$db->exec("UPDATE foreman_daily_entitlements SET status='final', needs_recalculation=0 WHERE session_id=60");
pdks_servis_ucret_ekle(6, '450', '260', date('Y-m-d', strtotime($day . ' +1 day')), 'TRY', 1, $db);
okcu('L12) KESİN hakediş fiyat eklemesinden etkilenmez', (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=60")->fetchColumn() === 0
    && $db->query("SELECT total_amount FROM foreman_daily_entitlements WHERE session_id=60")->fetchColumn() === '2550.00');

$m1 = pdks_servis_ekle_mesaji(['buyuk' => 2, 'kucuk' => 1, 'fiyatsiz' => ['Büyük', 'Küçük']]);
okcu('L13) fiyatsız kayıt mesajı: uyarı + "hakedişte hesaplanır"', str_contains($m1, '2 Büyük 1 Küçük') && str_contains($m1, 'Büyük ve Küçük servis fiyatı tanımlı değil') && str_contains($m1, 'hesaplanır'), $m1);
$m2 = pdks_servis_ekle_mesaji(['buyuk' => 1, 'kucuk' => 0, 'fiyatsiz' => []]);
okcu('L14) fiyatlı kayıt mesajı: yeniden hesaplanmalı, uyarı yok', str_contains($m2, '1 Büyük') && str_contains($m2, 'yeniden hesaplanmalıdır') && !str_contains($m2, '⚠'), $m2);

// =========================================================
echo "\n=== K. STATİK ===\n";
$ks = file_get_contents($root . '/config/pdks_servis.php');
preg_match('/function pdks_servis_ekle\(.*?\n\}\n/s', $ks, $mE);
$govde = $mE[0] ?? '';
okcu('K1) ekle gövdesi: yetki + aktif depo + mesai kilidi + kesin hakediş + yeniden hesap + audit',
    str_contains($govde, 'pdks_faz8j_yetki()') && str_contains($govde, 'pdks_faz8j_aktif_depo_kontrol(') && str_contains($govde, 'pdks_faz8j_mesai_kilitle(')
    && str_contains($govde, "=== 'final'") && str_contains($govde, 'pdks_faz8j_yeniden_hesap_isaretle(') && str_contains($govde, "'servis_ekle'"));
okcu('K2) mesai kilidi transaction\'ın İLK sorgusu', (bool)preg_match('/beginTransaction\(\);\s*pdks_faz8j_mesai_kilitle\(/', $govde));
preg_match('/function pdks_servis_iptal\(.*?\n\}\n/s', $ks, $mI);
okcu('K3) iptal gövdesi: yetki + depo + kilit + gerekçe + soft UPDATE (DELETE yok)', str_contains($mI[0] ?? '', 'pdks_faz8j_yetki()') && str_contains($mI[0] ?? '', 'pdks_faz8j_aktif_depo_kontrol(')
    && str_contains($mI[0] ?? '', 'is_voided = 1') && !preg_match('/DELETE\s+FROM/i', $mI[0] ?? ''));
$dt = file_get_contents($root . '/gunluk_isci_puantaj_detay.php');
okcu('K4) detay: servis POST dalı CSRF + işlevler', (bool)preg_match("/servis_ekle', 'servis_iptal'\], true\)\) \{\s*csrf_check/", $dt) && str_contains($dt, 'pdks_servis_ekle(') && str_contains($dt, 'pdks_servis_iptal('));
okcu('K5) detay: düğme $servisGoster kapısında, $ekleGoster\'e bağlı', (bool)preg_match('/\$servisGoster = \$ekleGoster && /', $dt));
okcu('K6) detay: sayfada migrate ÇAĞRILMAZ', !str_contains($dt, 'pdks_servis_migrate('));
$cf = file_get_contents($root . '/cavus_fiyatlari.php');
okcu('K7) fiyat sayfası: form=servis_ucret dalı CSRF + rates kapısı', (bool)preg_match("/=== 'servis_ucret'\) \{\s*csrf_check\(\\\$_POST\['csrf'\] \?\? null\);\s*require_pdks_hakedis\('rates'\);/", $cf) && !str_contains($cf, 'pdks_servis_migrate('));
$k8bc = file_get_contents($root . '/config/pdks_faz8b_cavus_b.php');
okcu('K8) dedektörler kod = \'\' filtresi taşır (A + B)', str_contains($k8b, "AND l.worker_type_code_snapshot = ''") && str_contains($k8bc, "l2.worker_type_code_snapshot = ''"));
$dp = is_file($root . '/_puantaj_servis.php') ? (string)file_get_contents($root . '/_puantaj_servis.php') : '';
okcu('K9) partial fonksiyon TANIMLAMAZ, native dialog id=servis', $dp !== '' && !preg_match('/\bfunction\s+\w+\s*\(/', preg_replace('#<script\b.*?</script>#s', '', $dp)) && str_contains($dp, '<dialog id="servis" class="pm-dialog'));

okcu('K10) v300: servis_ekle gövdesinde fiyat_yok ENGELİ yok', !str_contains($ks, "'kod' => 'fiyat_yok'"));
okcu('K11) v300: pencere sayaçları fiyat yokken de açık (svAcik = !kesin)', str_contains($dp, '$svAcik = !$servisKesin;'));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);

