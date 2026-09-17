<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_faz9c_smoke.php — Faz 9C (v230) odaklı doğrulama.
//
// v227 sistem audit'inin H-02 bulgusunu (sabit 08:00-17:00 vardiya modeli /
// planlı bitişten ölçülen fazla mesai) TAMAMEN KAPATAN değişiklikleri
// hedefler: ÇAVUŞ bazlı anlaşmalı normal günlük çalışma süresi (dakika),
// oturum-seviyesi donmuş anlık görüntü (snapshot), süre-tabanlı Tam/FM
// hesabı ve muhasebenin hesaplanan FM adayının ALTINDA onaylayabildiği
// "Onaylanan FM Saati" modeli. Gerçek fonksiyon çağrıları + bellek içi
// SQLite ile "davranış doğru mu" kontrolüdür.
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok9c(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$ADMIN9C = true;
$DEPOT9C = 'Depo A';
function db(): PDO { global $db; return $db; }
function is_admin(): bool { global $ADMIN9C; return $ADMIN9C; }
function active_depot(): ?string { global $DEPOT9C; return $DEPOT9C; }
function can(string $p): bool { return true; }

require_once $root . '/config/pdks.php';
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_faz8e.php';
require_once $root . '/config/pdks_faz8j.php';

// ── Şema — Faz 9B'nin pdks_faz9b_smoke.php'siyle AYNI temel + Faz 9C'nin
//    YENİ kolonları (normal_work_minutes / normal_work_minutes_snapshot /
//    overtime_approved_hours). ──
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'),('ERKEK','Erkek')");
$kadinId = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)$db->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, phone TEXT, notes TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, canonical_uid TEXT, uid_bytes INTEGER DEFAULT 4, uid_decimal TEXT, enrolled_source TEXT DEFAULT 'usb_decimal', status TEXT DEFAULT 'available', notes TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT, opened_at TEXT, opened_by_user_id INTEGER, closed_at TEXT, closed_by_user_id INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_hours INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT DEFAULT 'scan', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER, created_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$db->exec("INSERT INTO foremen (code,name) VALUES ('C1','Ayşe Çavuş')");
$foremanId = (int)$db->lastInsertId();
$day = date('Y-m-d', strtotime('-1 day'));

function periodEkle9c(PDO $db, int $sessionId, int $cardId, int $tipId, string $tip, string $giris, string $cikis, string $day): int {
    $st = $db->prepare("INSERT INTO daily_worker_work_periods
        (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,
         entry_time,exit_time,declared_attendance_class,approved_attendance_class,work_date_snapshot,depo_snapshot,status)
        VALUES (?,?,?,?,?,?,'tam',NULL,?,?,'closed')");
    $st->execute([$sessionId, $cardId, $tipId, $tip, "{$day} {$giris}:00", "{$day} {$cikis}:00", $day, 'Depo A']);
    return (int)$db->lastInsertId();
}

// =========================================================
// § A — FOREMAN DURATION (madde 1-6)
// =========================================================
echo "=== A. ÇAVUŞ NORMAL GÜNLÜK ÇALIŞMA SÜRESİ ===\n";
ok9c('1) yeni/mevcut çavuş varsayılan olarak 540 dk (9 saat)', pdks_faz8b_cavus_normal_sure_dk($foremanId, $db) === 540);

$r8 = pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 480, 1, $db);
ok9c('2) 8 saatlik çavuş 480 dk saklar', $r8['ok'] === true && pdks_faz8b_cavus_normal_sure_dk($foremanId, $db) === 480, json_encode($r8, JSON_UNESCAPED_UNICODE));

$r9 = pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 540, 1, $db);
ok9c('3) 9 saatlik çavuş 540 dk saklar', $r9['ok'] === true && pdks_faz8b_cavus_normal_sure_dk($foremanId, $db) === 540);

$r10 = pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 600, 1, $db);
ok9c('4) 10 saatlik çavuş 600 dk saklar', $r10['ok'] === true && pdks_faz8b_cavus_normal_sure_dk($foremanId, $db) === 600);

// Aralık dışı değerler reddedilir (madde 4 — 1-24 saat).
$rDusuk = pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 30, 1, $db);
ok9c('aralık dışı (30 dk / yarım saat) reddedilir', $rDusuk['ok'] === false);
$rYuksek = pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 1500, 1, $db);
ok9c('aralık dışı (25 saat) reddedilir', $rYuksek['ok'] === false);

// Testin geri kalanı için 9 saate (540 dk) geri dön.
pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 540, 1, $db);

// 5) yeni oturum çavuşun O ANKİ süresini donduruyor.
pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 480, 1, $db);   // çavuşu 8h yap
$acilis = pdks_gunluk_oturum_ac_veya_getir($foremanId, 1, $db);
ok9c('5) yeni oturum çavuşun O ANKİ (8h=480dk) süresini snapshot alıyor',
    $acilis['ok'] === true && (int)$acilis['session']['normal_work_minutes_snapshot'] === 480,
    json_encode($acilis, JSON_UNESCAPED_UNICODE));
$eskiOturumId = (int)$acilis['session']['id'];

// 6) çavuşu SONRADAN değiştirmek eski oturumun snapshot'ını DEĞİŞTİRMEZ.
pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 600, 1, $db);   // çavuşu 10h yap
$eskiOturum = $db->query("SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id={$eskiOturumId}")->fetch();
ok9c('6) çavuş SONRADAN değişse bile ESKİ oturumun snapshot\'ı (480) AYNI kalır',
    (int)$eskiOturum['normal_work_minutes_snapshot'] === 480, json_encode($eskiOturum, JSON_UNESCAPED_UNICODE));

// Bu oturumu kapat, geri kalan testler İÇİN çavuşu 9h'a döndür ve TEMİZ bir oturum aç.
$db->exec("UPDATE daily_work_sessions SET status='closed' WHERE id={$eskiOturumId}");
pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 540, 1, $db);

// =========================================================
// § B — TAM (madde 7-12) — saf süre-tabanlı karar
// =========================================================
echo "\n=== B. TAM — SÜRE-TABANLI (saat KİLİDİ YOK) ===\n";
function karar9c(string $g, string $c, int $normalDk, string $day): array {
    return pdks_faz8b_sure_karari("{$day} {$g}:00", "{$day} {$c}:00", $normalDk);
}
$k = karar9c('08:00', '17:00', 540, $day);
ok9c('7) 08:00-17:00 @9h => TAM', $k['otomatik_sinif'] === 'tam');
$k = karar9c('09:00', '18:00', 540, $day);
ok9c('8) 09:00-18:00 @9h => TAM', $k['otomatik_sinif'] === 'tam');
$k = karar9c('10:00', '19:00', 540, $day);
ok9c('9) 10:00-19:00 @9h => TAM', $k['otomatik_sinif'] === 'tam');
$k = karar9c('08:00', '16:59', 540, $day);
ok9c('10) 08:00-16:59 @9h => karar gerekli', $k['otomatik_sinif'] === null && $k['sinif_onayi_gerekli'] === true);
$k = karar9c('09:00', '17:00', 480, $day);
ok9c('11) 09:00-17:00 @8h => TAM', $k['otomatik_sinif'] === 'tam');
$k = karar9c('09:00', '19:00', 600, $day);
ok9c('12) 09:00-19:00 @10h => TAM', $k['otomatik_sinif'] === 'tam');

// =========================================================
// § C — OVERTIME @9H (madde 13-19)
// =========================================================
echo "\n=== C. FAZLA MESAİ @9 SAAT — 15 DK TOLERANS, SONRASI SAAT SAAT ===\n";
$k = karar9c('08:00', '17:00', 540, $day);
ok9c('13) 9h00 => 0 FM', $k['fazla_mesai_saat'] === 0);
$k = karar9c('08:00', '17:15', 540, $day);
ok9c('14) 9h15 => 0 FM', $k['fazla_mesai_saat'] === 0);
$k = karar9c('08:00', '17:16', 540, $day);
ok9c('15) 9h16 => 1 FM', $k['fazla_mesai_saat'] === 1);
$k = karar9c('08:00', '18:15', 540, $day);
ok9c('16) 10h15 => 1 FM', $k['fazla_mesai_saat'] === 1);
$k = karar9c('08:00', '18:16', 540, $day);
ok9c('17) 10h16 => 2 FM', $k['fazla_mesai_saat'] === 2);
$k = karar9c('08:00', '19:15', 540, $day);
ok9c('18) 11h15 => 2 FM', $k['fazla_mesai_saat'] === 2);
$k = karar9c('08:00', '19:16', 540, $day);
ok9c('19) 11h16 => 3 FM', $k['fazla_mesai_saat'] === 3);

// =========================================================
// § D — SHIFTED TIMES (madde 20-22)
// =========================================================
echo "\n=== D. KAYMIŞ SAATLER / GÜN ÖTESİ ===\n";
$k = karar9c('12:00', '21:00', 540, $day);
ok9c('20) 12:00-21:00 @9h => TAM, 0 FM', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 0);
$k = karar9c('12:00', '22:16', 540, $day);
ok9c('21) 12:00-22:16 @9h => TAM, 2 FM', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 2);
$k = pdks_faz8b_sure_karari("{$day} 23:00:00", date('Y-m-d', strtotime($day . ' +1 day')) . ' 08:00:00', 540);
ok9c('22) gün ötesi 23:00-08:00 @9h => TAM, 0 FM', $k['otomatik_sinif'] === 'tam' && $k['fazla_mesai_saat'] === 0);

// =========================================================
// § E — SECOND PERIOD (madde 23-24) — gerçek DB satırlarıyla
// =========================================================
echo "\n=== E. İKİNCİ/KISA DÖNEM — SAAT GEÇ DİYE FM SAYILMAZ ===\n";
$db->prepare("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (2,?,?,?,540,?,?,?)")
    ->execute([$foremanId, 'Ayşe Çavuş', 'C1', $day, 'Depo A', 'closed']);
$db->exec("INSERT INTO worker_cards (id,card_no,status) VALUES (1,'K001','available'),(2,'K002','available')");
$p1 = periodEkle9c($db, 2, 1, $kadinId, 'Kadın', '08:00', '17:00', $day);
$p2 = periodEkle9c($db, 2, 2, $kadinId, 'Kadın', '18:00', '20:00', $day);
$donemler = pdks_faz8b_oturum_donemleri(2, $db);
$d1 = array_values(array_filter($donemler, fn($x) => (int)$x['id'] === $p1))[0] ?? null;
$d2 = array_values(array_filter($donemler, fn($x) => (int)$x['id'] === $p2))[0] ?? null;
ok9c('23) Dönem 1 (08:00-17:00 @9h) TAM, 0 FM', $d1 && $d1['faz8b']['otomatik_sinif'] === 'tam' && $d1['faz8b']['fazla_mesai_saat'] === 0);
ok9c('23b) Dönem 2 (18:00-20:00, 2 saat) KISA — karar bekliyor, 0 FM', $d2 && $d2['faz8b']['otomatik_sinif'] === null && $d2['faz8b']['fazla_mesai_saat'] === 0);
ok9c('24) geç saatli KISA ikinci dönem TEK BAŞINA asla FM ÜRETMEZ (yalnız SÜRESİNE bakılır)', $d2 && $d2['faz8b']['fazla_mesai_onayi_gerekli'] === false);

// =========================================================
// § F — APPROVAL (madde 25-28) — muhasebe HESAPLANAN adayın ALTINDA onay
// =========================================================
echo "\n=== F. ONAYLANAN FM SAATİ — HESAPLANANIN ALTINDA/EŞİT, ASLA ÜSTÜNDE DEĞİL ===\n";
$db->prepare("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (3,?,?,?,540,?,?,?)")
    ->execute([$foremanId, 'Ayşe Çavuş', 'C1', $day, 'Depo A', 'closed']);
$db->exec("INSERT INTO worker_cards (id,card_no,status) VALUES (3,'K003','available'),(4,'K004','available'),(5,'K005','available'),(6,'K006','available')");
$rOran = pdks_faz8b_oran_ekle($foremanId, $kadinId, '1500', '900', 'hourly', '200', '2026-09-01', 'TRY', 1, $db);
ok9c('fiyat tanımı ön koşulu geçti', $rOran['ok'] === true, json_encode($rOran, JSON_UNESCAPED_UNICODE));

// 08:00-19:16 @9h => 11h16 geçen süre => 3 saat FM adayı.
$p25 = periodEkle9c($db, 3, 3, $kadinId, 'Kadın', '08:00', '19:16', $day);
$d25 = pdks_faz8b_degerlendirme_kaydet($p25, null, 3, 1, $db);
ok9c('25) computed=3, approved=3 => KABUL edilir', $d25['ok'] === true, json_encode($d25, JSON_UNESCAPED_UNICODE));
$h25 = pdks_faz8b_hakedis_hesapla(3, 1, $db);
$l25 = array_values(array_filter($h25['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === $p25))[0] ?? null;
ok9c('25b) computed=3, approved=3 => hakediş 3 saat FM ÖDER', $h25['ok'] === true && (int)($l25['overtime_hours'] ?? -1) === 3, json_encode($l25, JSON_UNESCAPED_UNICODE));

$p26 = periodEkle9c($db, 3, 4, $kadinId, 'Kadın', '08:00', '19:16', $day);
$d26 = pdks_faz8b_degerlendirme_kaydet($p26, null, 2, 1, $db);
ok9c('26) computed=3, approved=2 => KABUL edilir (kısmi onay)', $d26['ok'] === true, json_encode($d26, JSON_UNESCAPED_UNICODE));
$h26 = pdks_faz8b_hakedis_hesapla(3, 1, $db);
$l26 = array_values(array_filter($h26['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === $p26))[0] ?? null;
ok9c('26b) computed=3, approved=2 => hakediş YALNIZ 2 saat FM ÖDER', $h26['ok'] === true && (int)($l26['overtime_hours'] ?? -1) === 2, json_encode($l26, JSON_UNESCAPED_UNICODE));

$p27 = periodEkle9c($db, 3, 5, $kadinId, 'Kadın', '08:00', '19:16', $day);
$d27 = pdks_faz8b_degerlendirme_kaydet($p27, null, 0, 1, $db);
ok9c('27) computed=3, approved=0/reddet => KABUL edilir', $d27['ok'] === true, json_encode($d27, JSON_UNESCAPED_UNICODE));
$h27 = pdks_faz8b_hakedis_hesapla(3, 1, $db);
$l27 = array_values(array_filter($h27['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === $p27))[0] ?? null;
ok9c('27b) computed=3, approved=0 => hakediş 0 saat FM ÖDER (yalnız Tam Mesai)', $h27['ok'] === true && (int)($l27['overtime_hours'] ?? -1) === 0 && ($l27['overtime_total'] ?? '') === '0.00', json_encode($l27, JSON_UNESCAPED_UNICODE));

$p28 = periodEkle9c($db, 3, 6, $kadinId, 'Kadın', '08:00', '19:16', $day);
$d28 = pdks_faz8b_degerlendirme_kaydet($p28, null, 4, 1, $db);
ok9c('28) computed=3, approved=4 (adayın ÜSTÜ) => REDDEDİLİR', $d28['ok'] === false, json_encode($d28, JSON_UNESCAPED_UNICODE));
$satirAfter28 = $db->query("SELECT overtime_approved_hours FROM daily_worker_work_periods WHERE id={$p28}")->fetch();
ok9c('28b) reddedilen aşırı onay satırı DEĞİŞTİRMEDİ (overtime_approved_hours hâlâ NULL)', $satirAfter28['overtime_approved_hours'] === null, json_encode($satirAfter28, JSON_UNESCAPED_UNICODE));
// p28'e (madde 28'in reddedilen denemesi) GEÇERLİ bir karar ver — yoksa
// bu satır "muhasebe değerlendirmesi bekliyor" olarak kalır ve §G'nin
// finalize adımını BLOKE eder (bu test kartının kendi ayrı senaryosu
// zaten yukarıda kanıtlandı, burada yalnız oturumu temizliyoruz).
pdks_faz8b_degerlendirme_kaydet($p28, null, 0, 1, $db);

// =========================================================
// § F2 — PENDING (NULL) vs EXPLICIT ZERO — İKİSİ AYNI ŞEY DEĞİLDİR
// =========================================================
echo "\n=== F2. FM DURUM BÜTÜNLÜĞÜ — NULL (bekliyor) ile 0 (reddedildi) KARIŞTIRILMAZ ===\n";
$db->exec("INSERT INTO worker_cards (id,card_no,status) VALUES (7,'K007','available')");
// 08:00-19:16 @9h => yine 3 saatlik FM adayı, hiç değerlendirme YAPILMADI.
$pPending = periodEkle9c($db, 3, 7, $kadinId, 'Kadın', '08:00', '19:16', $day);
$fPendingOnce = pdks_faz8b_donem_finans_durumu(
    $db->query("SELECT p.*, s.normal_work_minutes_snapshot FROM daily_worker_work_periods p JOIN daily_work_sessions s ON s.id=p.session_id WHERE p.id={$pPending}")->fetch()
);
ok9c('1) HİÇ değerlendirilmemiş FM adayı: fazla_mesai_onay_saat NULL kalır (0 DEĞİL)', $fPendingOnce['fazla_mesai_onay_saat'] === null, json_encode($fPendingOnce, JSON_UNESCAPED_UNICODE));
ok9c('1b) HİÇ değerlendirilmemiş FM adayı: finans_hazir=false (BEKLİYOR, 0 SAYILMADI)', $fPendingOnce['finans_hazir'] === false);
$hPendingBlok = pdks_faz8b_hakedis_hesapla(3, 1, $db);
ok9c('1c) NULL onay hakediş hesabını BLOKE EDER — SESSİZCE 0 saat FM olarak YORUMLANMAZ',
    $hPendingBlok['ok'] === false && ($hPendingBlok['kod'] ?? '') === 'faz8b_degerlendirme_gerekli', json_encode($hPendingBlok, JSON_UNESCAPED_UNICODE));

// Şimdi AÇIK bir muhasebe kararıyla (0) ÇÖZÜLSÜN — NULL'dan farklı, RESOLVED bir durum.
$dPendingCoz = pdks_faz8b_degerlendirme_kaydet($pPending, null, 0, 1, $db);
ok9c('3) açık 0 (reddet) kararı KABUL edilir ve ÇÖZÜLMÜŞ sayılır', $dPendingCoz['ok'] === true);
$fPendingSonra = pdks_faz8b_donem_finans_durumu(
    $db->query("SELECT p.*, s.normal_work_minutes_snapshot FROM daily_worker_work_periods p JOIN daily_work_sessions s ON s.id=p.session_id WHERE p.id={$pPending}")->fetch()
);
ok9c('3b) açık 0 kararından SONRA fazla_mesai_onay_saat artık 0 (NULL DEĞİL) — durum reddedildi', $fPendingSonra['fazla_mesai_onay_saat'] === 0 && $fPendingSonra['fazla_mesai_durum'] === 'reddedildi', json_encode($fPendingSonra, JSON_UNESCAPED_UNICODE));
ok9c('3c) açık 0 kararından SONRA finans_hazir=true (artık BEKLEMİYOR, ÇÖZÜLDÜ)', $fPendingSonra['finans_hazir'] === true);
$hPendingCoz = pdks_faz8b_hakedis_hesapla(3, 1, $db);
ok9c('3d) çözülmüş (0 onaylı) satır artık hesabı BLOKE ETMEZ, 0 saat FM ÖDER', $hPendingCoz['ok'] === true);

// =========================================================
// § F3 — FM ONAYI STALE KALMAZ: mevcut geçersiz kılma (invalidation)
//        yollarının HEPSİ overtime_approved_hours'ı da NULL'a döndürür
// =========================================================
echo "\n=== F3. GEÇERSİZ KILMA YOLLARI — overtime_approved_hours ESKİ OVERTIME_APPROVED İLE BİRLİKTE SIFIRLANIR ===\n";

// --- FAZ8J DÜZELTME sonrası onaylı FM saati SIFIRLANIR (madde 5) ---
$db->prepare("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (5,?,?,?,540,?,?,?)")
    ->execute([$foremanId, 'Ayşe Çavuş', 'C1', $day, 'Depo A', 'closed']);
$db->exec("INSERT INTO worker_cards (id,card_no,status) VALUES (8,'K008','available'),(9,'K009','available')");
$pDuzeltme = periodEkle9c($db, 5, 8, $kadinId, 'Kadın', '08:00', '19:16', $day);
$db->exec("UPDATE daily_worker_work_periods SET depo_snapshot='Depo A' WHERE id={$pDuzeltme}");
$dDuzeltmeOnce = pdks_faz8b_degerlendirme_kaydet($pDuzeltme, null, 2, 1, $db);
ok9c('düzeltme-öncesi onay ön koşulu geçti (approved=2)', $dDuzeltmeOnce['ok'] === true, json_encode($dDuzeltmeOnce, JSON_UNESCAPED_UNICODE));
$satirOnceDuzeltme = $db->query("SELECT overtime_approved_hours FROM daily_worker_work_periods WHERE id={$pDuzeltme}")->fetch();
ok9c('düzeltmeden ÖNCE overtime_approved_hours=2 kaydedildi', (int)$satirOnceDuzeltme['overtime_approved_hours'] === 2);
$duzeltmePayload = ['period_id' => $pDuzeltme, 'session_id' => 5, 'depo' => 'Depo A', 'worker_card_id' => 8,
    'worker_type_id' => $kadinId, 'entry_date' => $day, 'entry_clock' => '08:00', 'exit_date' => $day, 'exit_clock' => '18:00',
    'reason' => 'Faz 9C durum bütünlüğü testi', 'note' => ''];
$dDuzeltmeSonuc = pdks_faz8j_duzelt($duzeltmePayload, 1, $db);
ok9c('düzeltme (giriş/çıkış değişikliği) KABUL edilir', $dDuzeltmeSonuc['ok'] === true, json_encode($dDuzeltmeSonuc, JSON_UNESCAPED_UNICODE));
$satirSonraDuzeltme = $db->query("SELECT overtime_approved_hours, overtime_approved, approved_attendance_class FROM daily_worker_work_periods WHERE id={$pDuzeltme}")->fetch();
ok9c('5) FAZ8J düzeltmesi SONRASI overtime_approved_hours NULL\'a SIFIRLANDI (eski onaylı saat YENİ süreye TAŞINMADI)',
    $satirSonraDuzeltme['overtime_approved_hours'] === null && $satirSonraDuzeltme['overtime_approved'] === null && $satirSonraDuzeltme['approved_attendance_class'] === null,
    json_encode($satirSonraDuzeltme, JSON_UNESCAPED_UNICODE));

// --- FAZ8J VOID sonrası onaylı FM saati SIFIRLANIR (madde 7) ---
$pVoid = periodEkle9c($db, 5, 9, $kadinId, 'Kadın', '08:00', '19:16', $day);
$db->exec("UPDATE daily_worker_work_periods SET depo_snapshot='Depo A' WHERE id={$pVoid}");
$dVoidOnce = pdks_faz8b_degerlendirme_kaydet($pVoid, null, 3, 1, $db);
ok9c('void-öncesi onay ön koşulu geçti (approved=3)', $dVoidOnce['ok'] === true, json_encode($dVoidOnce, JSON_UNESCAPED_UNICODE));
$rVoid = pdks_faz8j_void($pVoid, 5, 'Depo A', 'Faz 9C durum bütünlüğü testi — iptal', 1, $db);
ok9c('void KABUL edilir', $rVoid['ok'] === true, json_encode($rVoid, JSON_UNESCAPED_UNICODE));
$satirSonraVoid = $db->query("SELECT overtime_approved_hours, overtime_approved, is_voided FROM daily_worker_work_periods WHERE id={$pVoid}")->fetch();
ok9c('7) FAZ8J VOID sonrası overtime_approved_hours NULL\'a SIFIRLANDI, is_voided=1 (değiştirilmiş dikkate alınan veriye STALE onay ASILI KALMAZ)',
    $satirSonraVoid['overtime_approved_hours'] === null && $satirSonraVoid['overtime_approved'] === null && (int)$satirSonraVoid['is_voided'] === 1,
    json_encode($satirSonraVoid, JSON_UNESCAPED_UNICODE));

// --- FAZ8E MANUEL ÇIKIŞ sonrası (henüz hiç değerlendirilmemiş/açık dönem
//     için) overtime_approved_hours ASLA STALE KALMAZ (madde 6) — savunma
//     derinliği: UPDATE'in KENDİSİ bu kolonu da AÇIKÇA sıfırlar, kolonun
//     normalde zaten NULL olduğu (henüz FM hiç hesaplanmamış açık dönem)
//     durumda bile.
$db->prepare("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (6,?,?,?,540,?,?,?)")
    ->execute([$foremanId, 'Ayşe Çavuş', 'C1', $day, 'Depo A', 'open']);
$db->exec("INSERT INTO worker_cards (id,card_no,status) VALUES (10,'K010','available')");
$stAcik = $db->prepare("INSERT INTO daily_worker_work_periods
    (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,
     declared_attendance_class,work_date_snapshot,depo_snapshot,status,overtime_approved_hours)
    VALUES (6,10,?,?,?,NULL,'tam',?,?,'open',5)");
$stAcik->execute([$kadinId, 'Kadın', "{$day} 08:00:00", $day, 'Depo A']);
$pManuel = (int)$db->lastInsertId();
$oncekiAcik = $db->query("SELECT overtime_approved_hours FROM daily_worker_work_periods WHERE id={$pManuel}")->fetch();
ok9c('(kurulum) manuel çıkış öncesi satırda BİLEREK asılı/stale bir değer var (5)', (int)$oncekiAcik['overtime_approved_hours'] === 5);
$rManuel = pdks_faz8e_manuel_cikis_kaydet($pManuel, 'Depo A', $day, '17:00', 'Kart bozuk', '', 1, $db);
ok9c('manuel çıkış KABUL edilir', $rManuel['ok'] === true, json_encode($rManuel, JSON_UNESCAPED_UNICODE));
$satirSonraManuel = $db->query("SELECT overtime_approved_hours FROM daily_worker_work_periods WHERE id={$pManuel}")->fetch();
ok9c('6) FAZ8E MANUEL ÇIKIŞ sonrası overtime_approved_hours NULL\'a SIFIRLANDI (asılı/stale değer SÜRDÜRÜLMEDİ)',
    $satirSonraManuel['overtime_approved_hours'] === null, json_encode($satirSonraManuel, JSON_UNESCAPED_UNICODE));

// =========================================================
// § G — HISTORY (madde 29-31) — KRİTİK tarihsel güvenlik
// =========================================================
echo "\n=== G. TARİHSEL GÜVENLİK — CANLI ÇAVUŞ AYARI GEÇMİŞİ ETKİLEMEZ ===\n";
// #25 hâlâ draft; kesinleştir (finalize) ki #30 gerçek bir "kesinleşmiş
// hakediş" üzerinden test edilsin.
$finalize3 = pdks_faz8b_hakedis_finalize(3, 1, false, $db);
ok9c('kesinleştirme ön koşulu geçti', $finalize3['ok'] === true, json_encode($finalize3, JSON_UNESCAPED_UNICODE));
$toplamOnce = (string)$db->query("SELECT total_amount FROM foreman_daily_entitlements WHERE session_id=3")->fetchColumn();

// Çavuşun CANLI süresini büyük ölçüde değiştir (9h -> 12h) — session 3
// zaten AÇILMIŞTI (id=3, normal_work_minutes_snapshot=540 sabit INSERT
// edildi), bu değişiklik onun HESABINI ETKİLEMEMELİ.
pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 720, 1, $db);

$donem25Sonra = pdks_faz8b_donem_finans_durumu(
    $db->query("SELECT p.*, s.normal_work_minutes_snapshot FROM daily_worker_work_periods p JOIN daily_work_sessions s ON s.id=p.session_id WHERE p.id={$p25}")->fetch()
);
ok9c('29) canlı çavuş süresi SONRADAN değişse bile ESKİ dönemin hesabı (TAM/FM) AYNI kalır',
    $donem25Sonra['otomatik_sinif'] === 'tam' && $donem25Sonra['fazla_mesai_saat'] === 3, json_encode($donem25Sonra, JSON_UNESCAPED_UNICODE));

$toplamSonra = (string)$db->query("SELECT total_amount FROM foreman_daily_entitlements WHERE session_id=3")->fetchColumn();
ok9c('30) KESİNLEŞMİŞ hakediş toplamı canlı çavuş ayarı değişikliğinden ETKİLENMEDİ', $toplamOnce === $toplamSonra, "önce={$toplamOnce} sonra={$toplamSonra}");

$oranSonra = $db->query("SELECT daily_rate, overtime_rate FROM foreman_worker_rates WHERE foreman_id={$foremanId} AND worker_type_id={$kadinId}")->fetch();
ok9c('31) tarihsel oran satırı (daily_rate/overtime_rate) canlı çavuş süresi değişikliğinden ETKİLENMEDİ',
    $oranSonra['daily_rate'] === '1500.00' && $oranSonra['overtime_rate'] === '200.00', json_encode($oranSonra, JSON_UNESCAPED_UNICODE));

// Testin geri kalanı için çavuşu 9h'a döndür (temizlik).
pdks_faz8b_cavus_normal_sure_guncelle($foremanId, 540, 1, $db);

// =========================================================
// § H — MİGRASYON (idempotent, additive) + STATİK ENTEGRASYON
// =========================================================
echo "\n=== H. MİGRASYON + STATİK ENTEGRASYON ===\n";
$mig1 = pdks_faz8b_migrate($db);
ok9c('migrasyon hatasız (kolonlar zaten var — idempotent)', count(array_filter($mig1, fn($r) => $r['durum'] === 'hata')) === 0, json_encode($mig1, JSON_UNESCAPED_UNICODE));
ok9c('şema hazır', pdks_faz8b_sema_hazir($db));

$faz8bSrc = (string)file_get_contents($root . '/config/pdks_faz8b.php');
ok9c('eski sabit vardiya sabitleri (VARDIYA_BASLANGIC/BITIS) KOD OLARAK YOK', !str_contains($faz8bSrc, 'PDKS_FAZ8B_VARDIYA'));
ok9c('planlı 17:00 bitiş REFERANSI hesap fonksiyonunda YOK (yorum hariç gerçek KOD)',
    !preg_match('/strtotime\([^)]*17:00/', $faz8bSrc));

$cavusFormSrc = (string)file_get_contents($root . '/cavus_form.php');
ok9c('cavus_form.php normal süre alanını (name="saat") içeriyor', str_contains($cavusFormSrc, 'name="saat"'));
ok9c('cavus_form.php sabit başlangıç/bitiş SAATİ zorunlu KILMIYOR (name="baslangic_saati" YOK)', !str_contains($cavusFormSrc, 'name="baslangic_saati"'));
// Faz 9C tamamlama turu: "saat" alanı OLUŞTURMA (id=0) modunda da
// görünmeli — $id>0'a bağlı bir bloğun İÇİNDE OLMAMALI. Kanıt: alanın
// pozisyonu, yalnız DÜZENLEME'de görünen "Durum" kartının (aktiflik
// action'ı) pozisyonundan ÖNCE gelir — aynı, koşulsuz forma aittir.
$saatPos = strpos($cavusFormSrc, 'name="saat"');
$durumKartiPos = strpos($cavusFormSrc, 'value="aktiflik"');
ok9c('cavus_form.php: "saat" alanı OLUŞTURMA/DÜZENLEME ORTAK formunda (yalnız-düzenleme "Durum" kartından ÖNCE, $id>0 koşuluna bağlı DEĞİL)',
    $saatPos !== false && $durumKartiPos !== false && $saatPos < $durumKartiPos);
ok9c('cavus_form.php: eski AYRI "sure_guncelle" yazma yolu KALDIRILDI (tek yazma yolu — action=save)', !str_contains($cavusFormSrc, "'sure_guncelle'"));

$degerSrc = (string)file_get_contents($root . '/mesai_degerlendirme.php');
ok9c('mesai_degerlendirme.php normal_work_minutes_snapshot\'ı OKUYOR (donmuş süreyi gösteriyor)', str_contains($degerSrc, "normal_work_minutes_snapshot"));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
