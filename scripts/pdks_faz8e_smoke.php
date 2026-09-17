<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$db8e = new PDO('sqlite::memory:');
$db8e->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db8e->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$allowed8e = true;
function db(): PDO { global $db8e; return $db8e; }
function can(string $p): bool { global $allowed8e; return $p === 'attendance.management_reports' || ($allowed8e && in_array($p, ['attendance.entitlements', 'attendance.foreman_rates'], true)); }
function is_admin(): bool { return false; }

require_once $root . '/config/pdks_faz8e.php';
require_once $root . '/config/pdks_rapor.php';

$db8e->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT)");
$db8e->exec("INSERT INTO worker_types VALUES (1,'KADIN','Kadın'),(2,'ERKEK','Erkek')");
$db8e->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, notes TEXT)");
$db8e->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY, card_no TEXT, canonical_uid TEXT, uid_decimal TEXT)");
$db8e->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)");
// Faz 9A / B1: is_voided/voided_at/voided_by_user_id/void_reason EKLENDİ —
// eskiden bu kolonlar YOKTU ve pdks_gunluk_faz8j_etkin_kosul() '1=1'e
// düşüyordu; pdks_faz8e_manuel_cikis_kaydet()'in KENDİ dönem sorgusu bu
// predicate'i KULLANIYOR (bkz. config/pdks_faz8e.php) ama testte hiç
// egzersiz edilmiyordu.
// Faz 9C / H-02: overtime_approved_hours EKLENDİ — eksikken
// pdks_faz8e_manuel_cikis_kaydet()'in UPDATE'i (bu kolonu da eski
// overtime_approved bayrağıyla BİRLİKTE NULL'a sıfırlar, bkz. config/
// pdks_faz8e.php) "no such column" ile fatal veriyordu.
$db8e->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT, approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_hours INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT, is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db8e->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, session_id INTEGER, status TEXT, needs_recalculation INTEGER DEFAULT 0, total_amount TEXT, currency TEXT)");
$db8e->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db8e->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT)");
$day = date('Y-m-d', strtotime('yesterday'));
$st = $db8e->prepare("INSERT INTO daily_work_sessions VALUES (?,1,'Ayşe Çavuş','C1',?,?,'closed',NULL)");
foreach ([[1,'Depo A'],[2,'Depo B'],[3,'Depo A']] as [$id,$depo]) $st->execute([$id,$day,$depo]);
$db8e->exec("INSERT INTO worker_cards VALUES (1,'K001','AABBCCDD','12345678'),(2,'K002','BBCCDDEE','23456789'),(3,'K003','CCDDEEFF','34567890'),(4,'K004','DDEEFFAA','45678901')");
$st = $db8e->prepare("INSERT INTO daily_worker_work_periods
    (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_event_id,entry_time,exit_time,declared_attendance_class,approved_attendance_class,work_date_snapshot,depo_snapshot,status,source)
    VALUES (?,?,?,?,?,? ,?,?, 'auto', ?, ?, ?, ?, 'scan')");
$st->execute([1,1,1,1,'Kadın',11,"$day 08:00:00",null,'yarim',$day,'Depo A','open']);
$st->execute([2,2,2,1,'Kadın',12,"$day 08:00:00",null,null,$day,'Depo B','open']);
$st->execute([3,3,3,2,'Erkek',13,"$day 08:00:00",null,null,$day,'Depo A','open']);
$st->execute([4,3,4,2,'Erkek',14,"$day 08:00:00","$day 17:00:00",null,$day,'Depo A','closed']);
$db8e->exec("INSERT INTO foreman_daily_entitlements VALUES (1,1,'draft',0,'1000.00','TRY'),(2,3,'final',0,'2000.00','TRY')");
$db8e->exec("INSERT INTO users VALUES (7,'muhasebe','Muhasebe Yetkilisi')");

$pass = 0; $fail = 0;
function ok8e(string $name, bool $value): void { global $pass,$fail; if ($value) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
function close8e(int $id, string $day, string $time = '17:00', string $reason = 'Kart bozuk', string $note = ''): array {
    return pdks_faz8e_manuel_cikis_kaydet($id, 'Depo A', $day, $time, $reason, $note, 7, db());
}

$before = pdks_rapor_cavus_toplu_dokum(substr($day,0,7), 'Depo A', null, false, $db8e);
ok8e('Önce eksik çıkış sayısı 1', (int)$before[0]['eksik_cikis'] === 1);
$result = close8e(1,$day);
ok8e('Yetkili kullanıcı dönemi kapatır', $result['ok'] === true);
$row = $db8e->query('SELECT * FROM daily_worker_work_periods WHERE id=1')->fetch();
ok8e('Çıkış zamanı doğru ve dönem kapalı', $row['exit_time'] === "$day 17:00:00" && $row['status'] === 'closed');
$event = $db8e->query('SELECT * FROM daily_worker_card_events WHERE id=' . (int)$row['exit_event_id'])->fetch();
ok8e('Bağlı çıkış olayı manual kaynağı taşır', $event['source'] === 'manual' && $event['event_type'] === 'CIKIS');
ok8e('Çıkış olayında aktör ve gerçek çıkış zamanı korunur', (int)$event['recorded_by_user_id'] === 7 && $event['server_event_time'] === "$day 17:00:00");
$audit = $db8e->query("SELECT * FROM audit_log WHERE record_id=1 AND action='manuel_cikis'")->fetch();
$payload = json_decode($audit['new_values'] ?? '{}', true);
ok8e('İşlem Geçmişi gerekçe, açıklama, kaynak ve aktörü saklar', $payload['reason'] === 'Kart bozuk' && $payload['note'] === '' && $payload['source'] === 'manual' && (int)$audit['user_id'] === 7 && !empty($audit['created_at']));
ok8e('Girişten önceki çıkış reddedilir', !close8e(3,$day,'07:59')['ok']);
ok8e('İmkânsız tarih reddedilir', !close8e(3,'2026-02-30')['ok']);
ok8e('Mesai günü dışındaki çıkış reddedilir', !close8e(3,date('Y-m-d', strtotime($day . ' +2 days')))['ok']);
ok8e('Aynı dönem ikinci kez kapatılamaz', !close8e(1,$day)['ok']);
ok8e('Başka depo dönemi kapatılamaz', !close8e(2,$day)['ok']);
$allowed8e = false;
ok8e('Yetkisiz kullanıcı reddedilir', !close8e(3,$day)['ok']);
$allowed8e = true;
ok8e('Diğer açıklama gerektirir', !close8e(3,$day,'17:00','Diğer')['ok']);
ok8e('Kesin hakediş değiştirilmez', !close8e(3,$day)['ok'] && $db8e->query("SELECT status FROM foreman_daily_entitlements WHERE id=2")->fetchColumn() === 'final');
$after = pdks_rapor_cavus_toplu_dokum(substr($day,0,7), 'Depo A', null, false, $db8e);
ok8e('Rapor eksik çıkış sayısı doğal olarak azalır', (int)$after[0]['eksik_cikis'] === 0);
$detail = pdks_rapor_cavus_kart_dokumu(1,'Depo A',$db8e);
ok8e('Detay Manuel göstergesi için çıkış kaynağını döndürür', $detail['cards'][0]['exit_source'] === 'manual' && !$detail['cards'][0]['eksik_cikis']);
ok8e('İkinci work period oluşmaz', (int)$db8e->query('SELECT COUNT(*) FROM daily_worker_work_periods')->fetchColumn() === 4);
$finance = pdks_faz8b_donem_finans_durumu($row);
ok8e('Faz 8B değerlendirmesi yeniden kullanılır', $finance['otomatik_sinif'] === 'tam' && $finance['sinif_kaynak'] === 'otomatik');
ok8e('Eski muhasebe kararı silinir ve taslak bayatlar', $row['approved_attendance_class'] === null && (int)$db8e->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=1')->fetchColumn() === 1);
ok8e('Normal tarama çıkış fonksiyonu yerinde', function_exists('pdks_gunluk_faz8a_cikis_kaydet'));
$src = file_get_contents($root . '/cavus_toplu_dokum_detay.php');
ok8e('Detayda Manuel rozeti ve yalnız eksik satır eylemi var', str_contains($src, 'Tamamlandı · Manuel') && str_contains($src, "\$c['eksik_cikis'] && \$c['exit_time'] === null"));
ok8e('Faz 8D rapor/detay bağlantıları korunur', str_contains(file_get_contents($root . '/cavus_toplu_dokum.php'), 'cavus_toplu_dokum_detay.php'));
$formSource = file_get_contents($root . '/manuel_cikis.php');
ok8e('Form yalnız çıkış zamanı, neden ve açıklama ister', str_contains($formSource, 'name="cikis_tarihi"')
    && str_contains($formSource, 'name="cikis_saati"') && str_contains($formSource, 'name="neden"')
    && !str_contains($formSource, 'name="attendance_decision"'));
ok8e('Form CSRF, onay ve kesin hakediş engeli içerir', str_contains($formSource, 'csrf_check(')
    && str_contains($formSource, 'window.confirm(') && str_contains($formSource, 'elseif ($finalEntitlement)'));

$db8e->exec("INSERT INTO worker_cards VALUES (5,'K005','EEFFAABB','56789012')");
$st->execute([5,1,5,1,'Kadın',15,"$day 09:00:00",null,null,$day,'Depo A','legacy_unresolved']);
$db8e->exec("UPDATE daily_worker_work_periods SET source='legacy_backfill' WHERE id=5");
$legacy = close8e(5,$day,'16:00','Diğer','Okuyucu arızası');
$legacyRow = $db8e->query('SELECT * FROM daily_worker_work_periods WHERE id=5')->fetch();
$legacyEvent = $db8e->query('SELECT source FROM daily_worker_card_events WHERE id=' . (int)$legacyRow['exit_event_id'])->fetchColumn();
$legacyAudit = json_decode((string)$db8e->query("SELECT new_values FROM audit_log WHERE record_id=5 AND action='manuel_cikis'")->fetchColumn(), true);
ok8e('Tarihsel çözülmemiş dönem tek satırda kapanır', $legacy['ok'] && $legacyRow['status'] === 'closed' && $legacyRow['source'] === 'legacy_backfill');
ok8e('Diğer açıklaması ve manuel olay kalıcıdır', $legacyEvent === 'manual' && $legacyAudit['note'] === 'Okuyucu arızası');

// Faz 9A / B1: İPTAL EDİLMİŞ bir açık dönem — manuel çıkış onu (ve ham
// olay geçmişini) HİÇ görmemeli. pdks_faz8e_manuel_cikis_kaydet() kendi
// dönem sorgusunda pdks_gunluk_faz8j_etkin_kosul() KULLANIYOR (bkz.
// config/pdks_faz8e.php) — bu, kolon eklenmeden önce testte HİÇ egzersiz
// edilmiyordu.
$db8e->exec("INSERT INTO worker_cards VALUES (7,'K007','AABBCCEE','78901234')");
$st->execute([7,1,7,1,'Kadın',17,"$day 08:00:00",null,null,$day,'Depo A','open']);
$db8e->prepare('UPDATE daily_worker_work_periods SET is_voided=1, voided_at=?, voided_by_user_id=9, void_reason=? WHERE id=7')
    ->execute(["$day 09:00:00", 'test iptal']);
$voidedClose = close8e(7, $day);
ok8e('İPTAL edilmiş açık dönem için manuel çıkış REDDEDİLİR', $voidedClose['ok'] === false);
ok8e('İPTAL edilmiş dönem manuel çıkış SONRASI hâlâ açık/çıkışsız kalır (hiçbir olay yazılmadı)',
    $db8e->query('SELECT exit_time, exit_event_id FROM daily_worker_work_periods WHERE id=7')->fetch() === ['exit_time' => null, 'exit_event_id' => null]
    && (int)$db8e->query('SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id=7')->fetchColumn() === 0);

// Gerçek detay sayfasının HTML çıktısı: PHP dosyasındaki bağlam kurulur,
// yalnız uygulama bootstrap/şema kapısı bu bellek içi testte atlanır.
function current_user(): ?array { return ['id' => 7, 'username' => 'muhasebe']; }
function require_login(): array { return current_user(); }
function active_depot(): ?string { return 'Depo A'; }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function base_url(): string { return '/'; }
function render_header(string $title, bool $print = false): void { echo '<html><body>'; }
function render_footer(bool $print = false): void { echo '</body></html>'; }
function render_flash(): void {}
$page = file_get_contents($root . '/cavus_toplu_dokum_detay.php');
$page = preg_replace('/^require_once __DIR__ .*$/m', '', $page);
$page = str_replace('pdks_gunluk_sayfa_kapisi($pdo);', '', $page);
$page = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $page);
$_GET = ['session_id' => '1', 'ay' => substr($day, 0, 7)];
ob_start();
try { eval('?>' . $page); $html = ob_get_clean(); }
catch (Throwable $e) { ob_end_clean(); $html = 'ERROR: ' . $e->getMessage(); }
ok8e('Detay sayfası Manuel durumunu gerçekten gösterir', !str_contains($html, 'ERROR:') && str_contains($html, 'Tamamlandı · Manuel'));
ok8e('Detay sayfası gerekçe, açıklama ve aktörü gösterir', str_contains($html, 'Okuyucu arızası') && str_contains($html, 'Muhasebe Yetkilisi'));
ok8e('Tamamlanan dönem için manuel çıkış düğmesi gizlenir', !str_contains($html, 'Manuel Çıkış Yap'));
$db8e->exec("INSERT INTO worker_cards VALUES (6,'K006','FFAABBCC','67890123')");
$st->execute([6,1,6,1,'Kadın',16,"$day 08:00:00",null,null,$day,'Depo A','open']);
ob_start();
try { eval('?>' . $page); $openHtml = ob_get_clean(); }
catch (Throwable $e) { ob_end_clean(); $openHtml = 'ERROR: ' . $e->getMessage(); }
ok8e('Eksik çıkışta Manuel Çıkış Yap düğmesi görünür', str_contains($openHtml, 'Manuel Çıkış Yap'));
$allowed8e = false;
ob_start();
try { eval('?>' . $page); $limitedHtml = ob_get_clean(); }
catch (Throwable $e) { ob_end_clean(); $limitedHtml = 'ERROR: ' . $e->getMessage(); }
ok8e('Rapor yetkili ama muhasebe yetkisiz kullanıcı düğmeyi görmez', !str_contains($limitedHtml, 'Manuel Çıkış Yap'));
$allowed8e = true;
$db8e->exec('DROP TABLE audit_log');
$failedAudit = close8e(6, $day);
ok8e('Denetim kaydı yazılamazsa çıkış geri alınır', !$failedAudit['ok']
    && $db8e->query('SELECT exit_time FROM daily_worker_work_periods WHERE id=6')->fetchColumn() === null
    && (int)$db8e->query('SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id=6')->fetchColumn() === 0);
echo "SONUÇ: $pass geçti, $fail hata\n";
exit($fail === 0 ? 0 : 1);
