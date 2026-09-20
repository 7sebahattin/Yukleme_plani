<?php
// =========================================================
// scripts/pdks_faz8b_toplu_degerlendirme_smoke.php — Mesai Değerlendirme
// "Toplu İşlem" (Sprint Toplu-Degerlendirme-01) testi.
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ — scripts/pdks_faz8b_finans_smoke.php
// İLE AYNI minimal SQLite şemasını REUSE eder (kasıtlı kopya, o dosyanın
// KENDİSİ değiştirilmedi).
//
//   php scripts/pdks_faz8b_toplu_degerlendirme_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);

// ⚠ pdks_faz8b_degerlendirme_kaydet() audit_log_event() ÇAĞIRIR AMA yalnız
// function_exists() ise — bu betikte tanımlanmazsa çağrı sessizce ATLANIR
// (gerçek config/helpers.php'nin İMZASININ AYNISI, yalnız audit_log
// tablosuna yazan minimal bir sürüm).
$AUDIT_DB = null;
function audit_log_event(string $action, string $module, ?int $record_id = null, ?array $old_values = null, ?array $new_values = null, ?int $explicit_user_id = null): void {
    global $AUDIT_DB;
    if (!$AUDIT_DB) return;
    $AUDIT_DB->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values) VALUES (?,?,?,?,?,?)")
        ->execute([$explicit_user_id, $action, $module, $record_id, json_encode($old_values), json_encode($new_values)]);
}

require_once __DIR__ . '/../config/pdks_faz8b.php';

$gecen = 0; $hata = 0;
function ok8bt(string $ad, bool $kosul, string $detay = ''): void {
    global $gecen, $hata;
    if ($kosul) { $gecen++; echo "OK  - {$ad}\n"; }
    else { $hata++; echo "HATA- {$ad}" . ($detay !== '' ? " :: {$detay}" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['AUDIT_DB'] = $db;

$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(20) NOT NULL, name VARCHAR(150) NOT NULL)");
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(30) NOT NULL, name VARCHAR(80) NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no VARCHAR(30) NOT NULL)");
$db->exec("CREATE TABLE daily_work_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INT NOT NULL,
    foreman_name_snapshot VARCHAR(150) NOT NULL DEFAULT '', foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '',
    normal_work_minutes_snapshot INT NOT NULL DEFAULT 540,
    work_date DATE NOT NULL, depo VARCHAR(150) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'closed'
)");
$db->exec("CREATE TABLE foreman_worker_rates (
    id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INT NOT NULL, worker_type_id INT NOT NULL,
    daily_rate DECIMAL(12,2) NOT NULL, currency VARCHAR(10) NOT NULL DEFAULT 'TRY',
    valid_from DATE NOT NULL, valid_to DATE NULL, is_active INT NOT NULL DEFAULT 1, created_by_user_id INT NULL
)");
$db->exec("CREATE TABLE daily_worker_work_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT NOT NULL, worker_card_id INT NOT NULL,
    worker_type_id_snapshot INT NULL, worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '',
    entry_time DATETIME NOT NULL, exit_time DATETIME NULL,
    declared_attendance_class VARCHAR(10) NOT NULL DEFAULT 'tam', approved_attendance_class VARCHAR(10) NULL,
    is_voided INT NOT NULL DEFAULT 0, voided_at DATETIME NULL, voided_by_user_id INT NULL, void_reason VARCHAR(500) NULL
)");
$db->exec("CREATE TABLE foreman_daily_entitlements (
    id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT NOT NULL UNIQUE, foreman_id INT NOT NULL,
    foreman_name_snapshot VARCHAR(150) NOT NULL DEFAULT '', foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '',
    work_date DATE NOT NULL, depo VARCHAR(150) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'draft',
    currency VARCHAR(10) NOT NULL DEFAULT 'TRY', total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    calculated_at DATETIME NULL, calculated_by_user_id INT NULL, finalized_at DATETIME NULL, finalized_by_user_id INT NULL,
    missing_exit_ack INT NOT NULL DEFAULT 0, notes TEXT NULL, updated_at DATETIME NULL
)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INT NOT NULL, worker_type_id INT NULL,
    worker_type_code_snapshot VARCHAR(30) NOT NULL DEFAULT '', worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '',
    worker_count INT NOT NULL, unit_rate DECIMAL(12,2) NOT NULL, line_total DECIMAL(14,2) NOT NULL
)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$mig = pdks_faz8b_migrate($db);
ok8bt('Faz 8B migrasyonu hatasız', count(array_filter($mig, fn($r) => $r['durum'] === 'hata')) === 0, json_encode($mig, JSON_UNESCAPED_UNICODE));

$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C001','Ayşe Çavuş'),(2,'C002','Mehmet Çavuş')");
$db->exec("INSERT INTO worker_types (id,code,name) VALUES (1,'KADIN','Kadın'),(2,'ERKEK','Erkek')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status)
    VALUES (1,1,'Ayşe Çavuş','C001',540,'2026-09-20','Depo A','open'),
           (2,2,'Mehmet Çavuş','C002',540,'2026-09-20','Depo A','open')");
for ($i = 1; $i <= 10; $i++) {
    $db->prepare("INSERT INTO worker_cards (id,card_no) VALUES (?,?)")->execute([$i, sprintf('K%03d', $i)]);
}
pdks_faz8b_oran_ekle(1, 1, '1500', '900', 'hourly', '200', '2026-09-01', 'TRY', 1, $db);
pdks_faz8b_oran_ekle(2, 1, '1500', '900', 'hourly', '200', '2026-09-01', 'TRY', 1, $db);

function period8bt(PDO $db, int $session, int $cardId, string $giris, string $cikis): int {
    $db->prepare("INSERT INTO daily_worker_work_periods
        (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,declared_attendance_class)
        VALUES (?,?,1,'Kadın',?,?,'tam')")->execute([$session, $cardId, "2026-09-20 {$giris}:00", "2026-09-20 {$cikis}:00"]);
    return (int)$db->lastInsertId();
}

// Session 1: 2 kısa dönem (karar bekliyor) + 1 otomatik-tam + 1 FM adaylı UZUN dönem.
$pKisa1 = period8bt($db, 1, 1, '08:00', '11:00');     // 3 saat — karar bekliyor
$pKisa2 = period8bt($db, 1, 2, '08:00', '12:00');     // 4 saat — karar bekliyor
$pOtoTam = period8bt($db, 1, 3, '08:00', '17:00');    // 9 saat — otomatik Tam
$pFmli = period8bt($db, 1, 4, '08:00', '17:16');      // 9h16 — otomatik Tam + FM adayı VAR (1 saat)
// Session 2: aynı çavuş/gün değil ama BAŞKA oturum — IDOR testi için.
$pBaskaOturum = period8bt($db, 2, 5, '08:00', '11:00');

echo "\n=== 1. TEMEL TOPLU KAYIT — birden çok karar-bekleyen dönem AYNI kararı alır ===\n";
$r1 = pdks_faz8b_toplu_degerlendirme_kaydet([$pKisa1, $pKisa2], 'yarim', 1, 7, $db);
ok8bt('ok=true', $r1['ok'] === true, json_encode($r1));
ok8bt('2 dönem başarılı', $r1['basarili'] === 2);
ok8bt('0 atlandı', $r1['atlandi'] === 0);
$s1 = $db->query("SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=$pKisa1")->fetchColumn();
$s2 = $db->query("SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=$pKisa2")->fetchColumn();
ok8bt('Dönem 1 approved_attendance_class=yarim', $s1 === 'yarim');
ok8bt('Dönem 2 approved_attendance_class=yarim', $s2 === 'yarim');

echo "\n=== 2. OTOMATİK TAM DÖNEM LİSTEYE KARIŞSA BİLE ATLANIR (üzerine YAZILMAZ) ===\n";
$oncekiApprovedOtoTam = $db->query("SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=$pOtoTam")->fetchColumn();
$r2 = pdks_faz8b_toplu_degerlendirme_kaydet([$pOtoTam], 'yarim', 1, 7, $db);
ok8bt('basarili=0 (işlenecek bir şey yoktu)', $r2['basarili'] === 0);
ok8bt('atlandi=1', $r2['atlandi'] === 1);
ok8bt('otomatik-tam dönemin approved_attendance_class DEĞİŞMEDİ', $db->query("SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=$pOtoTam")->fetchColumn() === $oncekiApprovedOtoTam);

echo "\n=== 3. BAŞKA OTURUMUN DÖNEMİ LİSTEYE KARIŞSA BİLE ATLANIR (IDOR) ===\n";
$oncekiBaskaOturum = $db->query("SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=$pBaskaOturum")->fetchColumn();
$r3 = pdks_faz8b_toplu_degerlendirme_kaydet([$pBaskaOturum], 'tam', 1, 7, $db);   // session_id=1 gönderiliyor, dönem session=2'de
ok8bt('basarili=0', $r3['basarili'] === 0);
ok8bt('atlandi=1', $r3['atlandi'] === 1);
ok8bt('başka oturumun dönemi DEĞİŞMEDİ', $db->query("SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=$pBaskaOturum")->fetchColumn() === $oncekiBaskaOturum);

echo "\n=== 4. YAPISAL KURAL: 'karar bekleyen' bir dönemde FM adayı ASLA olamaz ===\n";
// pdks_faz8b_sure_karari(): fazla_mesai_saat yalnız toplam süre normali AŞTIĞINDA
// hesaplanır — bu ise otomatik_sinif='tam' (sinif_onayi_gerekli=false) demektir.
// Yani checkbox (yalnız sinif_onayi_gerekli satırlarda basılır) hiçbir zaman FM
// adaylı bir satırda görünmez; pdks_faz8b_toplu_degerlendirme_kaydet()'teki
// "FM'i kendi tavanına otomatik onayla" dalı bu YÜZDEN pratikte tetiklenmez —
// yine de kod DOĞRU davranır: fazla_mesai_saat=0 olduğu için overtime alanlarına
// HİÇ dokunmaz (NULL kalır). $pFmli (9h16, FM adaylı) BİLEREK otomatik-tam bir
// dönem — test 2'nin "otomatik dönem atlanır" kuralına AYNI şekilde tabidir.
$kisaKontrol = pdks_faz8b_sure_karari('2026-09-20 08:00:00', '2026-09-20 11:00:00', 540);
ok8bt('kısa (karar bekleyen) dönemde fazla_mesai_saat=0', $kisaKontrol['sinif_onayi_gerekli'] === true && $kisaKontrol['fazla_mesai_saat'] === 0, json_encode($kisaKontrol));
$fmKontrol = pdks_faz8b_sure_karari('2026-09-20 08:00:00', '2026-09-20 17:16:00', 540);
ok8bt('FM adaylı dönem her zaman otomatik-tam (sinif_onayi_gerekli=false)', $fmKontrol['sinif_onayi_gerekli'] === false && $fmKontrol['fazla_mesai_saat'] > 0, json_encode($fmKontrol));
$r4 = pdks_faz8b_toplu_degerlendirme_kaydet([$pFmli], 'tam', 1, 7, $db);
ok8bt('otomatik-tam+FM-adaylı dönem de bulk\'ta ATLANIR (0 başarılı, 1 atlandı)', $r4['basarili'] === 0 && $r4['atlandi'] === 1, json_encode($r4));
ok8bt('overtime_approved_hours HİÇ dokunulmadı (NULL)', $db->query("SELECT overtime_approved_hours FROM daily_worker_work_periods WHERE id=$pFmli")->fetchColumn() === null);

echo "\n=== 5. GEÇERSİZ KARAR DEĞERİ REDDEDİLİR, HİÇBİR ŞEY YAZILMAZ ===\n";
$pTaze = period8bt($db, 1, 6, '08:00', '11:00');
$r5 = pdks_faz8b_toplu_degerlendirme_kaydet([$pTaze], 'gecersiz_deger', 1, 7, $db);
ok8bt('ok=false', $r5['ok'] === false);
ok8bt('hiçbir dönem işlenmedi', $db->query("SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=$pTaze")->fetchColumn() === null);

echo "\n=== 6. MÜKERRER id'LER TEK SEFER İŞLENİR ===\n";
$pTaze2 = period8bt($db, 1, 7, '08:00', '11:00');
$oncekiToplamKayit = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE record_id=$pTaze2")->fetchColumn();
$r6 = pdks_faz8b_toplu_degerlendirme_kaydet([$pTaze2, $pTaze2, $pTaze2], 'tam', 1, 7, $db);
ok8bt('basarili=1 (3 kez gönderilse de TEK işlendi)', $r6['basarili'] === 1, json_encode($r6));
ok8bt('audit_log da TEK satır eklendi', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE record_id=$pTaze2")->fetchColumn() === $oncekiToplamKayit + 1);

echo "\n=== 7. HAKEDİŞ KESİNSE (final) TÜM SATIRLAR HATA OLARAK DÖNER, İKİNCİ BİR YOL AÇILMADIĞI DOĞRULANIR ===\n";
$pKesin = period8bt($db, 1, 8, '08:00', '11:00');
$db->exec("INSERT INTO foreman_daily_entitlements (session_id,foreman_id,work_date,depo,status) VALUES (1,1,'2026-09-20','Depo A','final')");
$r7 = pdks_faz8b_toplu_degerlendirme_kaydet([$pKesin], 'tam', 1, 7, $db);
ok8bt('ok=false', $r7['ok'] === false);
ok8bt('basarili=0', $r7['basarili'] === 0);
ok8bt('1 hata mesajı var (aynı pdks_faz8b_degerlendirme_kaydet\'in KESİN kilidinden geçti)', count($r7['hatalar']) === 1, json_encode($r7));
ok8bt('kesin dönem approved_attendance_class DEĞİŞMEDİ', $db->query("SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=$pKesin")->fetchColumn() === null);

echo "\n=== SONUÇ: $gecen geçti, $hata hata ===\n";
exit($hata === 0 ? 0 : 1);
