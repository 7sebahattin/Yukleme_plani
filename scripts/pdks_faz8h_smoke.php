<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$db8h = new PDO('sqlite::memory:');
$db8h->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db8h->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$admin8h = true;
$depo8h = 'FINIKE';
function db(): PDO { global $db8h; return $db8h; }
function is_admin(): bool { global $admin8h; return $admin8h; }
function current_user(): ?array { return ['id' => 7]; }
function active_depot(): ?string { global $depo8h; return $depo8h; }
function audit_log_event(string $action, string $module, int $recordId, ?array $old = null, ?array $new = null): void {
    db()->prepare('INSERT INTO audit_log (user_id, action, module, record_id, old_values, new_values) VALUES (?,?,?,?,?,?)')
        ->execute([7, $action, $module, $recordId, json_encode($old), json_encode($new)]);
}
require_once $root . '/config/pdks.php';
require_once $root . '/config/pdks_faz8h.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_cari.php';
require_once $root . '/config/pdks_rapor.php';

foreach ([
    'CREATE TABLE worker_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER, sort_order INTEGER)',
    'CREATE TABLE foremen (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)',
    'CREATE TABLE worker_cards (id INTEGER PRIMARY KEY, card_no TEXT, canonical_uid TEXT, worker_type_id INTEGER NULL REFERENCES worker_types(id), status TEXT)',
    'CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, opened_at TEXT, opened_by_user_id INTEGER, closed_at TEXT, closed_by_user_id INTEGER, notes TEXT, updated_at TEXT, UNIQUE(foreman_id, work_date, depo))',
    'CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)',
    'CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT, approved_attendance_class TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT)',
    'CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, session_id INTEGER, foreman_id INTEGER, status TEXT, needs_recalculation INTEGER DEFAULT 0, notes TEXT, updated_at TEXT, finalized_at TEXT, finalized_by_user_id INTEGER, total_amount TEXT)',
    'CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY, foreman_id INTEGER, status TEXT)',
    'CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
] as $sql) $db8h->exec($sql);
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$db8h->exec("INSERT INTO worker_types VALUES (1,'KADIN','Kadın',1,1)");
$db8h->exec("INSERT INTO foremen VALUES (1,'C1','Ayşe Çavuş',1),(2,'C2','Başka Çavuş',1),(3,'C3','Üçüncü Çavuş',1),(4,'C4','Dördüncü Çavuş',1)");
$uid = pdks_uid_from_decimal('631799511');
$db8h->prepare("INSERT INTO worker_cards VALUES (1,'K001',?,NULL,'active')")->execute([$uid]);
$insS = $db8h->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,opened_at,opened_by_user_id,closed_at,closed_by_user_id,notes,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([
    [1,1,$today,'FINIKE','open'], [2,2,$today,'ANTALYA','closed'],
    [3,3,$yesterday,'FINIKE','closed'], [4,4,$today,'FINIKE','closed'],
] as [$id,$foreman,$day,$depo,$status]) {
    $insS->execute([$id,$foreman,'Çavuş '.$foreman,'C'.$foreman,$day,$depo,$status,"$day 08:00:00",7,$status === 'closed' ? "$day 17:00:00" : null,$status === 'closed' ? 7 : null,$status === 'closed' ? 'Eski kapanış notu' : null,"$day 17:00:00"]);
}
$db8h->prepare('INSERT INTO daily_worker_card_events (id,session_id,worker_card_id,event_type,source,canonical_uid_snapshot,worker_type_id_snapshot,worker_type_name_snapshot,work_date_snapshot,depo_snapshot,recorded_by_user_id,server_event_time) VALUES (1,1,1,?,?,?,?,?,?,?,?,?)')
    ->execute(['GIRIS','usb_decimal',$uid,1,'Kadın',$today,'FINIKE',7,"$today 08:00:00"]);
$db8h->prepare("INSERT INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_event_id,entry_time,declared_attendance_class,work_date_snapshot,depo_snapshot,status,source) VALUES (1,1,1,1,'Kadın',1,?,'auto',?,'FINIKE','open','scan')")
    ->execute(["$today 08:00:00",$today]);
$db8h->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,status,needs_recalculation,notes,total_amount) VALUES (1,1,1,'draft',0,'','1000.00'),(4,4,4,'final',0,'','2000.00')");

$pass = 0; $fail = 0;
function ok8h(string $name, bool $value): void { global $pass,$fail; if ($value) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
function reopen8h(int $id, string $reason = 'Yanlışlıkla kapatıldı'): array { return pdks_gunluk_oturum_yeniden_ac($id,$reason,7,db()); }
function report8h(string $day): array {
    $k = pdks_rapor_operasyonel_kpi($day,$day,'FINIKE',1,null,db());
    return [$k['acik_mesai'],$k['eksik_cikis_mesai'],count(pdks_rapor_acik_mesailer_araligi($day,$day,'FINIKE',1,db())),count(pdks_rapor_eksik_cikislar_araligi($day,$day,'FINIKE',1,db()))];
}
ok8h('Faz 8A şeması etkin', pdks_gunluk_faz8a_sema_hazir($db8h));
$page = file_get_contents($root.'/gunluk_isci_giris_cikis.php');
$mainClick = preg_match("/getElementById\('giKapatBtn'\)\.addEventListener\('click', function \(\) \{(.*?)\n    \}\);/s",$page,$m) ? $m[1] : '';
ok8h('İlk tık yalnız onay ekranını açar', str_contains($mainClick,'ekranGoster(closeConfirmSec)') && !str_contains($mainClick,'kapat('));
ok8h('Vazgeç kapatma istemi göndermez', (bool)preg_match("/getElementById\('giCloseCancelBtn'\).*?ekranGoster\(scanSec\).*?\}\);/s",$page,$m) && !str_contains($m[0],'kapat('));
ok8h('Açık onay mevcut kapatma fonksiyonunu çağırır', str_contains($page,"giCloseConfirmBtn').addEventListener('click', function () { kapat(''); }"));
ok8h('Onay çavuş, tarih ve dört sayaç gösterir', !array_filter(['giCloseCavus','giCloseTarih','giCloseGiris','giCloseCikis','giCloseIceride','giCloseEksik'],fn($id)=>!str_contains($page,$id)));
ok8h('Eksik çıkış mutabakatı ve zorunlu neden korunur', str_contains($page,'giReconKapatBtn') && str_contains($page,'giReconNot') && str_contains($page,'eksik_cikis_var'));
$detail = file_get_contents($root.'/gunluk_isci_puantaj_detay.php');
ok8h('Admin detay eylemi, CSRF, bugün ve aktif depo ile sınırlı', str_contains($detail,'is_admin()') && str_contains($detail,"date('Y-m-d')") && str_contains($detail,'$oturum[\'depo\'] === $aktifDepo') && str_contains($detail,'csrf_check('));
ok8h('Yeniden açılan mesainin detayından taramaya dönüş bağlantısı var', str_contains($detail,'Giriş / Çıkışa Dön') && str_contains($detail,'gunluk_isci_giris_cikis.php'));

$noReasonClose = pdks_gunluk_oturum_kapat(1,'',7,$db8h);
ok8h('Eksik çıkış gerekçesiz kapatılmaz', !$noReasonClose['ok'] && $noReasonClose['kod']==='eksik_cikis_var');
$firstClose = pdks_gunluk_oturum_kapat(1,'Kart çıkışları tamamlanmadan kapatıldı',7,$db8h);
ok8h('Gerekçeli normal kapatma çalışır', $firstClose['ok'] && $db8h->query('SELECT status FROM daily_work_sessions WHERE id=1')->fetchColumn()==='closed');
ok8h('İlk kapanış denetim kaydı saklandı', (int)$db8h->query("SELECT COUNT(*) FROM audit_log WHERE action='close' AND record_id=1")->fetchColumn()===1);
ok8h('Kapalı oturumun işçisi eksik çıkış raporunda', report8h($today)===[0,1,0,1]);
$admin8h=false;
ok8h('Operatör yeniden açamaz', reopen8h(1)['kod']==='yetkisiz');
$admin8h=true;
ok8h('Gerekçe zorunludur', reopen8h(1,'  ')['kod']==='gerekce_gecersiz');
ok8h('Yanlış depo engellenir', reopen8h(2)['kod']==='yanlis_depo');
ok8h('Geçmiş gün engellenir', reopen8h(3)['kod']==='tarih_gecmis');
ok8h('Kesin hakediş yeniden açmayı engeller', reopen8h(4)['kod']==='kesin_hakedis');
ok8h('Kesin hakediş ve oturum değişmez', $db8h->query('SELECT status FROM foreman_daily_entitlements WHERE id=4')->fetchColumn()==='final' && $db8h->query('SELECT status FROM daily_work_sessions WHERE id=4')->fetchColumn()==='closed');
$sessionCount=(int)$db8h->query('SELECT COUNT(*) FROM daily_work_sessions')->fetchColumn();
$reopen=reopen8h(1,'Operasyon devam ediyor');
$session=$db8h->query('SELECT * FROM daily_work_sessions WHERE id=1')->fetch();
ok8h('Admin bugünkü oturumu aynı kimlikle açar', $reopen['ok'] && (int)$reopen['session_id']===1 && $session['status']==='open');
ok8h('Kapalı alanlar açık durumla tutarlı', $session['closed_at']===null && $session['closed_by_user_id']===null && $session['notes']===null);
ok8h('İkinci oturum oluşturulmaz', (int)$db8h->query('SELECT COUNT(*) FROM daily_work_sessions')->fetchColumn()===$sessionCount);
ok8h('Zaten açık oturum yeniden açılamaz', reopen8h(1)['kod']==='zaten_acik');
ok8h('Taslak hakediş bayatlatılır, tutar değişmez', (int)$db8h->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=1')->fetchColumn()===1 && $db8h->query('SELECT total_amount FROM foreman_daily_entitlements WHERE id=1')->fetchColumn()==='1000.00');
$audit=$db8h->query("SELECT * FROM audit_log WHERE action='daily_session_reopen' AND record_id=1")->fetch();
$old=json_decode($audit['old_values']??'{}',true); $new=json_decode($audit['new_values']??'{}',true);
ok8h('Yeniden açma audit aktör, gerekçe ve eski kapatma alanlarını korur', (int)$audit['user_id']===7 && $old['status']==='closed' && $old['closed_at']!==null && (int)$old['closed_by_user_id']===7 && $old['notes']==='Kart çıkışları tamamlanmadan kapatıldı' && $new['reason']==='Operasyon devam ediyor' && !empty($new['reopened_at']));
ok8h('Önceki kapanış audit kaydı durur', (int)$db8h->query("SELECT COUNT(*) FROM audit_log WHERE action='close' AND record_id=1")->fetchColumn()===1);
ok8h('Faz 8F raporu yeniden içeride gösterir', report8h($today)===[1,0,1,0]);
$found=pdks_gunluk_oturum_bul_acik(1,$db8h);
ok8h('Tarama aynı açık oturumu bulur', $found['ok'] && (int)$found['session']['id']===1);
$duplicate=pdks_gunluk_faz8a_giris_kaydet('631799511','usb_decimal',1,1,'auto',7,$db8h);
ok8h('Kart-gün/açık dönem mükerrer giriş koruması sürer', !$duplicate['ok'] && $duplicate['kod']==='mukerrer_giris');
$exit=pdks_gunluk_faz8a_cikis_kaydet('631799511','usb_decimal',1,7,$db8h);
ok8h('Yeniden açınca normal çıkış taraması çalışır', $exit['ok'] && $db8h->query('SELECT status FROM daily_worker_work_periods WHERE id=1')->fetchColumn()==='closed');
ok8h('Çıkıştan sonra açık/eksik raporu doğal olarak sıfırlanır', report8h($today)===[0,0,0,0]);
ok8h('İşçi dönemi silinmez veya kopyalanmaz', (int)$db8h->query('SELECT COUNT(*) FROM daily_worker_work_periods')->fetchColumn()===1);
$secondClose=pdks_gunluk_oturum_kapat(1,null,7,$db8h);
ok8h('Aynı oturum ikinci kez normal kapanır', $secondClose['ok'] && $db8h->query('SELECT status FROM daily_work_sessions WHERE id=1')->fetchColumn()==='closed');
ok8h('İki kapanış ve yeniden açma ayrı audit eylemleri', (int)$db8h->query("SELECT COUNT(*) FROM audit_log WHERE action='close' AND record_id=1")->fetchColumn()===2 && (int)$db8h->query("SELECT COUNT(*) FROM audit_log WHERE action='daily_session_reopen' AND record_id=1")->fetchColumn()===1);
$db8h->exec("INSERT INTO foreman_payments VALUES (1,4,'valid')");
ok8h('Mevcut cari koruması kesin hakedişi yeniden açtırmaz', pdks_hakedis_yeniden_ac(4,'Düzeltme',7,$db8h)['kod']==='cari_hareketli_engel');
$db8h->exec("DELETE FROM foreman_payments WHERE id=1");
ok8h('Hakedişi yeniden açma ayrı mevcut akış olarak kalır', pdks_hakedis_yeniden_ac(4,'Düzeltme',7,$db8h)['ok'] && $db8h->query('SELECT status FROM daily_work_sessions WHERE id=4')->fetchColumn()==='closed');
$db8h->exec("INSERT INTO foremen VALUES (5,'C5','Beşinci Çavuş',1),(6,'C6','Altıncı Çavuş',1)");
$insS->execute([5,5,'Beşinci Çavuş','C5',$today,'FINIKE','closed',"$today 08:00:00",7,"$today 12:00:00",7,'Yanlış kapanış',"$today 12:00:00"]);
ok8h('Hakediş yoksa aynı gün yeniden açılabilir', reopen8h(5)['ok'] && $db8h->query('SELECT status FROM daily_work_sessions WHERE id=5')->fetchColumn()==='open');
$insS->execute([6,6,'Altıncı Çavuş','C6',$today,'FINIKE','closed',"$today 08:00:00",7,"$today 12:00:00",7,'Yanlış kapanış',"$today 12:00:00"]);
$db8h->exec('DROP TABLE audit_log');
ok8h('Denetim kaydı yazılamazsa yeniden açma geri alınır', !reopen8h(6)['ok'] && $db8h->query('SELECT status FROM daily_work_sessions WHERE id=6')->fetchColumn()==='closed');
ok8h('Faz 8E manuel çıkış backend fonksiyonu korunur', function_exists('pdks_faz8e_manuel_cikis_kaydet') || is_file($root.'/config/pdks_faz8e.php'));
echo "SONUÇ: $pass geçti, $fail hata\n";
exit($fail===0 ? 0 : 1);
