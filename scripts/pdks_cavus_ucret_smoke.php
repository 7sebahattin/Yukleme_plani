<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_cavus_ucret_smoke.php — Çavuş Ücreti (Faz 8B eki) davranış testi.
//
// Çavuşun kendi günlük çalışma ücreti: foreman_daily_rates tablosu +
// pdks_faz8b_hakedis_hesapla() içindeki otomatik satır ekleme. Bellek içi
// SQLite + gerçek fonksiyon çağrıları — pdks_faz8b_finans_smoke.php /
// pdks_faz9d_smoke.php'nin harness deseni REUSE edilir.
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
function active_depot(): ?string { return 'Depo A'; }
function can(string $p): bool { return true; }
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid, 'new' => $n];
}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_cari.php';
require_once $root . '/config/pdks_rapor.php';

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

$day = date('Y-m-d', strtotime('-3 days'));

function periodEklecu(PDO $db, int $sessionId, int $cardId, int $tipId, string $tip, string $giris, string $cikis, string $day): int {
    $st = $db->prepare("INSERT INTO daily_worker_work_periods
        (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,
         entry_time,exit_time,declared_attendance_class,approved_attendance_class,status)
        VALUES (?,?,?,?,?,?,'tam',NULL,'closed')");
    $st->execute([$sessionId, $cardId, $tipId, $tip, "{$day} {$giris}:00", "{$day} {$cikis}:00"]);
    return (int)$db->lastInsertId();
}

// =========================================================
// § A — TABLO YOKKEN GERİYE UYUMLULUK (madde 3, 10) + kardeş işaretleme no-op
// =========================================================
echo "=== A. TABLO YOKKEN NO-OP ===\n";
okcu('1) foreman_daily_rates HENÜZ yok', pdks_faz8b_cavus_ucret_sema_hazir($db) === false);

$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C1','Ayşe Çavuş')");
$foremanA = 1;
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (1,{$foremanA},'Ayşe Çavuş','C1','{$day}','Depo A','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (1,'K001')");
pdks_faz8b_oran_ekle($foremanA, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
periodEklecu($db, 1, 1, $kadinId, 'Kadın', '08:00', '17:00', $day);
$hNoTable = pdks_faz8b_hakedis_hesapla(1, 1, $db);
okcu('2) tablo YOKKEN hesaplama hatasız çalışır', $hNoTable['ok'] === true, json_encode($hNoTable, JSON_UNESCAPED_UNICODE));
okcu('3) tablo YOKKEN hiçbir çavuş ücreti satırı eklenmez (yalnız işçi satırı)', count($hNoTable['lines'] ?? []) === 1 && $hNoTable['total_amount'] === '1500.00', json_encode($hNoTable['lines'] ?? [], JSON_UNESCAPED_UNICODE));

// kardeş işaretleme de tablo yokken no-op (hata FIRLATMAZ).
try {
    pdks_faz8b_cavus_ucret_kardes_isaretle(1, $db);
    okcu('4) tablo YOKKEN kardeş işaretleme sessizce no-op', true);
} catch (Throwable $e) {
    okcu('4) tablo YOKKEN kardeş işaretleme sessizce no-op', false, $e->getMessage());
}

// =========================================================
// § B — MİGRASYON (gerçek DDL, idempotent)
// =========================================================
echo "\n=== B. MİGRASYON ===\n";
foreach (pdks_faz8b_cavus_ucret_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    $db->exec($create);
    foreach ($indeksler as $ix) $db->exec($ix);
}
okcu('5) şema hazır (gerçek DDL, SQLite\'a çevrilmiş)', pdks_faz8b_cavus_ucret_sema_hazir($db));
$mig = pdks_faz8b_cavus_ucret_migrate($db);
okcu('6) migrasyon idempotent — mevcut tabloyu "var" olarak tanır, hata VERMEZ',
    count($mig) > 0 && count(array_filter($mig, fn($r) => $r['durum'] === 'var')) === count($mig), json_encode($mig, JSON_UNESCAPED_UNICODE));

// =========================================================
// § C — ÜCRET GİRİŞİ FONKSİYONU: doğrulama + audit
// =========================================================
echo "\n=== C. ÜCRET GİRİŞİ — DOĞRULAMA + AUDIT ===\n";
$rGecersizTarih = pdks_faz8b_cavus_ucret_ekle($foremanA, '1000', '16-09-2026', 'TRY', 1, $db);
okcu('7) geçersiz tarih formatı REDDEDİLİR', $rGecersizTarih['ok'] === false, json_encode($rGecersizTarih, JSON_UNESCAPED_UNICODE));

$rSifir = pdks_faz8b_cavus_ucret_ekle($foremanA, '0', $day, 'TRY', 1, $db);
okcu('8) sıfır/negatif ücret REDDEDİLİR', $rSifir['ok'] === false, json_encode($rSifir, JSON_UNESCAPED_UNICODE));

$rYokCavus = pdks_faz8b_cavus_ucret_ekle(999, '1000', $day, 'TRY', 1, $db);
okcu('9) bulunmayan çavuş REDDEDİLİR', $rYokCavus['ok'] === false, json_encode($rYokCavus, JSON_UNESCAPED_UNICODE));

$AUDIT = [];
$rEkle1 = pdks_faz8b_cavus_ucret_ekle($foremanA, '1000', $day, 'TRY', 1, $db);
okcu('10) geçerli ücret KABUL edilir', $rEkle1['ok'] === true, json_encode($rEkle1, JSON_UNESCAPED_UNICODE));
okcu('11) audit_log_event çağrıldı (create/foreman_daily_rates)',
    count(array_filter($AUDIT, fn($a) => $a['action'] === 'create' && $a['module'] === 'foreman_daily_rates')) === 1, json_encode($AUDIT, JSON_UNESCAPED_UNICODE));

$rEkleGeriTarih = pdks_faz8b_cavus_ucret_ekle($foremanA, '1200', $day, 'TRY', 1, $db);
okcu('12) mevcut dönemden ÖNCE/EŞİT başlangıç REDDEDİLİR', $rEkleGeriTarih['ok'] === false, json_encode($rEkleGeriTarih, JSON_UNESCAPED_UNICODE));

$sonraki = date('Y-m-d', strtotime($day . ' +5 days'));
$rEkle2 = pdks_faz8b_cavus_ucret_ekle($foremanA, '1200', $sonraki, 'TRY', 1, $db);
okcu('13) sonraki dönem KABUL edilir', $rEkle2['ok'] === true, json_encode($rEkle2, JSON_UNESCAPED_UNICODE));
$eskiDonem = $db->query("SELECT valid_to FROM foreman_daily_rates WHERE id = {$rEkle1['id']}")->fetch();
$beklenenBitis = date('Y-m-d', strtotime($sonraki . ' -1 day'));
okcu('14) yeni dönem eklenince ÖNCEKİ dönem bir gün öncesinde KAPANIR', $eskiDonem['valid_to'] === $beklenenBitis, json_encode($eskiDonem));

// =========================================================
// § D — GEÇERLİLİK TARİHİ SINIRLARI (valid_from/valid_to)
// =========================================================
echo "\n=== D. GEÇERLİLİK SINIRLARI ===\n";
// ⚠ SQLite NUMERIC affinity ondalık sıfırları kırpar (CLAUDE.md'nin bilinen
// farklılığı, gerçek DDL'den DECIMAL(12,2) kolonu) — ham daily_rate '1000.00'
// DEĞİL 1000 (int) dönebilir, bu yüzden sayısal karşılaştırma yapılır.
okcu('15) test günü hâlâ İLK dönemin (1000 TRY) sınırları İÇİNDE', (float)pdks_faz8b_cavus_ucret_gecerli($foremanA, $day, $db)['daily_rate'] === 1000.0);
okcu('16) sonraki dönem günü İKİNCİ dönemin (1200 TRY) sınırları İÇİNDE', (float)pdks_faz8b_cavus_ucret_gecerli($foremanA, $sonraki, $db)['daily_rate'] === 1200.0);
$oncesi = date('Y-m-d', strtotime($day . ' -10 days'));
okcu('17) İLK dönemden ÖNCEKİ bir gün için ücret YOK (sınır dışı)', pdks_faz8b_cavus_ucret_gecerli($foremanA, $oncesi, $db) === null);

// =========================================================
// § E — ÜCRET TANIMLI/TANIMSIZ HESAPLAMA (madde 1-2)
// =========================================================
echo "\n=== E. ÜCRET TANIMLI/TANIMSIZ HESAPLAMA ===\n";
// foremanA için $day artık ücret İÇİNDE — session 1'i yeniden hesapla.
$h1 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
okcu('18) ücret tanımlıyken hesaplama BAŞARILI', $h1['ok'] === true, json_encode($h1, JSON_UNESCAPED_UNICODE));
$cavusSatir1 = array_values(array_filter($h1['lines'] ?? [], fn($x) => $x['worker_type_id'] === null && $x['work_period_id'] === null))[0] ?? null;
okcu('19) çavuş ücreti satırı VAR ve 1000 TL', $cavusSatir1 !== null && $cavusSatir1['line_total'] === '1000.00', json_encode($cavusSatir1, JSON_UNESCAPED_UNICODE));
okcu('20) toplam işçi(1500) + çavuş ücreti(1000) = 2500 TL', $h1['total_amount'] === '2500.00', json_encode($h1, JSON_UNESCAPED_UNICODE));

// foremanB: ücret HİÇ tanımlanmadı.
$db->exec("INSERT INTO foremen (id,code,name) VALUES (2,'C2','Veli Çavuş')");
$foremanB = 2;
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (2,{$foremanB},'Veli Çavuş','C2','{$day}','Depo A','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (2,'K002')");
pdks_faz8b_oran_ekle($foremanB, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
periodEklecu($db, 2, 2, $kadinId, 'Kadın', '08:00', '17:00', $day);
$h2 = pdks_faz8b_hakedis_hesapla(2, 1, $db);
okcu('21) ücret TANIMSIZKEN hesaplama BAŞARILI (zorunlu değil)', $h2['ok'] === true, json_encode($h2, JSON_UNESCAPED_UNICODE));
okcu('22) ücret TANIMSIZKEN çavuş satırı YOK', count(array_filter($h2['lines'] ?? [], fn($x) => $x['worker_type_id'] === null && $x['work_period_id'] === null)) === 0, json_encode($h2['lines'] ?? [], JSON_UNESCAPED_UNICODE));
okcu('23) ücret TANIMSIZKEN eksik SAYILMAZ (eksikler boş)', empty($h2['eksikler'] ?? []));
okcu('24) ücret TANIMSIZKEN toplam yalnız işçi ücreti (1500 TL)', $h2['total_amount'] === '1500.00');

// =========================================================
// § F — AYNI ÇAVUŞ, AYNI GÜN, İKİ DEPO/OTURUM — ANKOR (madde 3)
// =========================================================
echo "\n=== F. ANKOR — İKİ OTURUM, ÜCRET BİR KEZ ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (3,'C3','Fatma Çavuş')");
$foremanC = 3;
pdks_faz8b_cavus_ucret_ekle($foremanC, '800', '2020-01-01', 'TRY', 1, $db);
pdks_faz8b_oran_ekle($foremanC, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (10,{$foremanC},'Fatma Çavuş','C3','{$day}','Depo A','closed')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (11,{$foremanC},'Fatma Çavuş','C3','{$day}','Depo B','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (10,'K010'),(11,'K011')");
periodEklecu($db, 10, 10, $kadinId, 'Kadın', '08:00', '17:00', $day);
periodEklecu($db, 11, 11, $kadinId, 'Kadın', '08:00', '17:00', $day);

okcu('25) ankor oturum EN KÜÇÜK id (10)', pdks_faz8b_cavus_ucret_ankor_session_id($foremanC, $day, $db) === 10);
$hF10 = pdks_faz8b_hakedis_hesapla(10, 1, $db);
$hF11 = pdks_faz8b_hakedis_hesapla(11, 1, $db);
okcu('26) ANKOR oturum (10) çavuş ücretini ALIR', $hF10['total_amount'] === '2300.00', json_encode($hF10, JSON_UNESCAPED_UNICODE));
okcu('27) ANKOR OLMAYAN oturum (11) çavuş ücretini ALMAZ', $hF11['total_amount'] === '1500.00', json_encode($hF11, JSON_UNESCAPED_UNICODE));
okcu('28) toplamda ücret ÇİFT SAYILMAZ (2300 + 1500 = 3800, tek ücret dahil)',
    ((float)$hF10['total_amount'] + (float)$hF11['total_amount']) === 3800.0);

// =========================================================
// § G — FINAL KİLİT SIRASI (madde 3 kenar durumu)
// =========================================================
echo "\n=== G. FINAL KİLİT SIRASI — B ÖNCE FINAL, A SONRA DÖNEM ALIR ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (4,'C4','Mehmet Çavuş')");
$foremanD = 4;
pdks_faz8b_cavus_ucret_ekle($foremanD, '700', '2020-01-01', 'TRY', 1, $db);
pdks_faz8b_oran_ekle($foremanD, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
// Önce sadece B (id=21) dönem alır ve tek oturum olduğu için ANKOR olur.
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (21,{$foremanD},'Mehmet Çavuş','C4','{$day}','Depo B','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (21,'K021')");
periodEklecu($db, 21, 21, $kadinId, 'Kadın', '08:00', '17:00', $day);
$hB = pdks_faz8b_hakedis_hesapla(21, 1, $db);
okcu('29) tek oturum (B) çavuş ücretini ALIR', $hB['total_amount'] === '2200.00', json_encode($hB, JSON_UNESCAPED_UNICODE));
$finB = pdks_faz8b_hakedis_finalize(21, 1, false, $db);
okcu('30) B KESİNLEŞTİRİLİR', $finB['ok'] === true, json_encode($finB, JSON_UNESCAPED_UNICODE));

// Şimdi A (id=20, B'den KÜÇÜK) dönem alır — id'ye göre A ANKOR olurdu, ama
// B zaten final+ücretli olduğu için A ASLA ücreti almamalı.
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (20,{$foremanD},'Mehmet Çavuş','C4','{$day}','Depo A','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (20,'K020')");
periodEklecu($db, 20, 20, $kadinId, 'Kadın', '08:00', '17:00', $day);
okcu('31) id\'ye göre A (20) artık ANKOR olurdu', pdks_faz8b_cavus_ucret_ankor_session_id($foremanD, $day, $db) === 20);
okcu('32) ama B ZATEN FİNAL ücreti taşıdığı için A ücreti ALAMAZ (baska_final_var_mi)', pdks_faz8b_cavus_ucret_baska_final_var_mi($foremanD, $day, 20, $db) === true);
$hA = pdks_faz8b_hakedis_hesapla(20, 1, $db);
okcu('33) A hesaplanınca çavuş ücreti satırı YOK, yalnız işçi ücreti (1500)', $hA['total_amount'] === '1500.00', json_encode($hA, JSON_UNESCAPED_UNICODE));
$bToplamSonra = (string)$db->query("SELECT total_amount FROM foreman_daily_entitlements WHERE session_id=21")->fetchColumn();
okcu('34) B\'nin FİNAL kaydı DEĞİŞMEDİ (final asla yeniden hesaplanmaz)', $bToplamSonra === '2200.00');
$finalTekrar = pdks_faz8b_hakedis_hesapla(21, 1, $db);
okcu('35) KESİN (final) oturum yeniden hesaplama TALEBİ reddedilir (satır DONAR)', $finalTekrar['ok'] === false && ($finalTekrar['kod'] ?? '') === 'zaten_kesinlesmis');

// =========================================================
// § H — DÖNEM YOK → HAKEDİŞ YOK
// =========================================================
echo "\n=== H. ÇALIŞILAN DÖNEM YOK ===\n";
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (30,{$foremanD},'Mehmet Çavuş','C4','{$day}','Depo C','closed')");
$hBos = pdks_faz8b_hakedis_hesapla(30, 1, $db);
okcu('36) hiç işlenmiş dönemi olmayan oturum kart_yok ile REDDEDİLİR (çavuş ücreti dahi eklenmez)', $hBos['ok'] === false && ($hBos['kod'] ?? '') === 'kart_yok', json_encode($hBos, JSON_UNESCAPED_UNICODE));
okcu('37) boş oturum için hiç entitlement YAZILMADI', (int)$db->query("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id=30")->fetchColumn() === 0);

// =========================================================
// § I — PARA BİRİMİ UYUŞMAZLIĞI → karisik_para_birimi
// =========================================================
echo "\n=== I. PARA BİRİMİ UYUŞMAZLIĞI ===\n";
$db->exec("INSERT INTO foremen (id,code,name) VALUES (5,'C5','Zeynep Çavuş')");
$foremanE = 5;
pdks_faz8b_oran_ekle($foremanE, $kadinId, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);   // işçi TRY
pdks_faz8b_cavus_ucret_ekle($foremanE, '50', '2020-01-01', 'USD', 1, $db);                                  // çavuş USD
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (40,{$foremanE},'Zeynep Çavuş','C5','{$day}','Depo A','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (40,'K040')");
periodEklecu($db, 40, 40, $kadinId, 'Kadın', '08:00', '17:00', $day);
$hParaKarisik = pdks_faz8b_hakedis_hesapla(40, 1, $db);
okcu('38) çavuş(USD) ≠ işçi(TRY) => karisik_para_birimi ile BLOKE EDİLİR', $hParaKarisik['ok'] === false && ($hParaKarisik['kod'] ?? '') === 'karisik_para_birimi', json_encode($hParaKarisik, JSON_UNESCAPED_UNICODE));
okcu('39) para birimi uyuşmazlığında HİÇBİR satır yazılmadı (validate-first)', (int)$db->query("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id=40")->fetchColumn() === 0);

// =========================================================
// § J — CARİ BAKİYE + TOPLU DÖKÜM ÇAVUŞ ÜCRETİNİ İÇERİR (madde 8-9)
// =========================================================
echo "\n=== J. CARİ BAKİYE + TOPLU DÖKÜM ===\n";
$bakiyeD = pdks_cari_bakiye($foremanD, $db);
okcu('40) pdks_cari_bakiye() KESİN hakedişin (2200, çavuş ücreti dahil) TAMAMINI sayar', ($bakiyeD['TRY']['bakiye'] ?? null) === '2200.00', json_encode($bakiyeD['TRY'] ?? null));

$topluAy = date('Y-m', strtotime($day));
$topluD = pdks_rapor_cavus_toplu_dokum($topluAy, null, $foremanD, true, $db);
$topluBSatir = array_values(array_filter($topluD, fn($r) => $r['session_id'] === 21))[0] ?? null;
okcu('41) pdks_rapor_cavus_toplu_dokum() ilgili oturumun (21) toplamını çavuş ücreti DAHİL raporlar',
    $topluBSatir !== null && ($topluBSatir['hakedis']['total_amount'] ?? null) === '2200.00', json_encode($topluBSatir));

// =========================================================
// § K — KARDEŞ OTURUM needs_recalculation İŞARETLEME (madde 3 sonuç)
// =========================================================
echo "\n=== K. KARDEŞ OTURUM İŞARETLEME ===\n";
// F bölümünün foremanC'si: iki TASLAK entitlement (session 10 ve 11) zaten var.
$db->exec("UPDATE foreman_daily_entitlements SET needs_recalculation = 0 WHERE session_id IN (10,11)");
pdks_faz8b_cavus_ucret_kardes_isaretle(10, $db);
$flagKardes = (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=11")->fetchColumn();
$flagKendi  = (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=10")->fetchColumn();
okcu('42) session 10 değişince KARDEŞ oturum (11) needs_recalculation=1 alır', $flagKardes === 1);
okcu('43) çağıran oturumun (10) KENDİ bayrağı bu çağrıyla değişmez (hâlâ 0)', $flagKendi === 0);
// Zaten FİNAL olan (21) hiçbir zaman needs_recalculation almaz (final asla yeniden hesaplanmaz).
pdks_faz8b_cavus_ucret_kardes_isaretle(20, $db);
$flagFinal = (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=21")->fetchColumn();
okcu('44) FİNAL kardeş oturum (21) needs_recalculation ALMAZ (status=draft koşulu)', $flagFinal === 0);

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
