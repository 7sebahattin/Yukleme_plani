<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$faz8jDb = new PDO('sqlite::memory:');
$faz8jDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$faz8jDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$faz8jAdmin = true; $faz8jDepot = 'Depo A';
function db(): PDO { global $faz8jDb; return $faz8jDb; }
function is_admin(): bool { global $faz8jAdmin; return $faz8jAdmin; }
function active_depot(): ?string { global $faz8jDepot; return $faz8jDepot; }
function can(string $permission): bool { return true; }
require_once $root . '/config/pdks_faz8j.php';

$db = $faz8jDb;
$db->exec('CREATE TABLE worker_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER, sort_order INTEGER)');
$db->exec("INSERT INTO worker_types VALUES (1,'KADIN','Kadın',1,1),(2,'ERKEK','Erkek',1,2),(3,'DIGER','Diğer',1,3),(4,'KADIN','Pasif Kadın',0,4)");
$db->exec('CREATE TABLE worker_cards (id INTEGER PRIMARY KEY, card_no TEXT, canonical_uid TEXT)');
$db->exec("INSERT INTO worker_cards VALUES (1,'K001','UID-1'),(2,'K002','UID-2'),(3,'K003','UID-3')");
$db->exec('CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY, work_date TEXT, depo TEXT, foreman_id INTEGER)');
$day = date('Y-m-d', strtotime('-2 days'));
$db->prepare('INSERT INTO daily_work_sessions VALUES (?,?,?,1)')->execute([1,$day,'Depo A']);
$db->prepare('INSERT INTO daily_work_sessions VALUES (?,?,?,1)')->execute([2,$day,'Depo B']);
$db->exec('CREATE TABLE foremen (id INTEGER PRIMARY KEY, name TEXT)'); $db->exec("INSERT INTO foremen VALUES (1,'Çavuş')");
$db->exec('CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)');
// Faz 9C / H-02: overtime_approved_hours EKLENDİ — eksikken pdks_faz8j_void()/
// pdks_faz8j_duzelt()'in UPDATE'i (bu kolonu da eski overtime_approved
// bayrağıyla BİRLİKTE NULL'a sıfırlar, bkz. config/pdks_faz8j.php)
// "no such column" ile fatal veriyordu.
$db->exec('CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT, declared_attendance_class TEXT, approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_hours INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)');
$db->exec('CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, session_id INTEGER, status TEXT, needs_recalculation INTEGER DEFAULT 0)');
$db->exec("INSERT INTO foreman_daily_entitlements VALUES (1,1,'draft',0),(2,2,'final',0)");
$db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

$pass = 0; $fail = 0; $behavioral = 0; $static = 0;
function check8j(string $name, bool $value, bool $isBehavioral = true): void {
    global $pass,$fail,$behavioral,$static;
    $isBehavioral ? $behavioral++ : $static++;
    if ($value) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
function putPeriod8j(PDO $db, int $id, int $session, int $card, int $type, string $entry, ?string $exit = null, string $status = 'legacy_unresolved', ?int $exitEvent = null): void {
    $name = $type === 2 ? 'Erkek' : 'Kadın'; $depo = $session === 2 ? 'Depo B' : 'Depo A';
    $db->prepare('INSERT OR REPLACE INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_event_id,exit_event_id,entry_time,exit_time,work_date_snapshot,depo_snapshot,status,source,declared_attendance_class,approved_attendance_class,approved_by_user_id,approved_at,overtime_approved,overtime_approved_by_user_id,overtime_approved_at,is_voided) VALUES (?,?,?,?,?,101,?,?,?,?,?,?,\'scan\',\'auto\',\'tam\',9,\'x\',1,9,\'x\',0)')->execute([$id,$session,$card,$type,$name,$exitEvent,$entry,$exit,substr($entry,0,10),$depo,$status]);
}
function payload8j(int $id, int $session, int $card, int $type, string $day, ?string $exit = '17:00'): array {
    return ['period_id'=>$id,'session_id'=>$session,'depo'=>$session===2?'Depo B':'Depo A','worker_card_id'=>$card,'worker_type_id'=>$type,'entry_date'=>$day,'entry_clock'=>'08:00','exit_date'=>$exit===null?'':$day,'exit_clock'=>$exit??'','reason'=>'Düzeltme nedeni','note'=>'Not'];
}

putPeriod8j($db,1,1,1,1,"$day 08:00:00");
$db->prepare("INSERT INTO daily_worker_card_events (session_id,worker_card_id,event_type,source,canonical_uid_snapshot,worker_type_id_snapshot,worker_type_name_snapshot,work_date_snapshot,depo_snapshot,recorded_by_user_id,server_event_time) VALUES (1,1,'GIRIS','scan','UID-1',1,'Kadın',?,?,7,?)")->execute([$day,'Depo A',"$day 08:00:00"]);
$entryRaw = $db->query('SELECT * FROM daily_worker_card_events WHERE event_type="GIRIS"')->fetch();

$faz8jAdmin = false;
check8j('non-admin edit is rejected', !pdks_faz8j_duzelt(payload8j(1,1,1,1,$day),7,$db)['ok']);
check8j('non-admin void is rejected', !pdks_faz8j_void(1,1,'Depo A','Neden',7,$db)['ok']);
$faz8jAdmin = true;
$faz8jDepot = 'Depo B';
check8j('backend rejects edit for wrong active depot', !pdks_faz8j_duzelt(payload8j(1,1,1,1,$day),7,$db)['ok']);
check8j('backend rejects void for wrong active depot', !pdks_faz8j_void(1,1,'Depo A','Neden',7,$db)['ok']);
$faz8jDepot = '';
check8j('backend rejects correction when active depot is empty', !pdks_faz8j_duzelt(payload8j(1,1,1,1,$day),7,$db)['ok']);
$faz8jDepot = 'Depo A';
check8j('wrong session-period ownership is rejected', !pdks_faz8j_duzelt(payload8j(1,2,1,1,$day),7,$db)['ok']);

foreach ([
    ['partial exit date rejected', $day, ''], ['partial exit clock rejected', '', '17:00'],
    ['malformed exit rejected', $day, '25:70'], ['impossible exit date rejected', '2025-02-30', '17:00'],
] as [$label,$exitDate,$exitClock]) { $p=payload8j(1,1,1,1,$day); $p['exit_date']=$exitDate; $p['exit_clock']=$exitClock; check8j($label,!pdks_faz8j_duzelt($p,7,$db)['ok']); }
$p=payload8j(1,1,1,1,$day); $p['exit_clock']='07:59'; check8j('exit before entry is rejected',!pdks_faz8j_duzelt($p,7,$db)['ok']);
$p=payload8j(1,1,1,1,$day); $p['exit_date']=date('Y-m-d',strtotime($day.' +2 days')); check8j('exit beyond 24 hours is rejected',!pdks_faz8j_duzelt($p,7,$db)['ok']);
$p=payload8j(1,1,1,1,$day); $p['entry_date']=date('Y-m-d',strtotime('+1 day')); check8j('future entry is rejected',!pdks_faz8j_duzelt($p,7,$db)['ok']);
$p=payload8j(1,1,1,1,$day); $p['entry_clock']='99:00'; check8j('malformed entry is rejected',!pdks_faz8j_duzelt($p,7,$db)['ok']);
$p=payload8j(1,1,1,1,$day); $p['worker_type_id']=3; check8j('unsupported worker type is rejected',!pdks_faz8j_duzelt($p,7,$db)['ok']);

$result=pdks_faz8j_duzelt(payload8j(1,1,1,2,$day),7,$db); $period=$db->query('SELECT * FROM daily_worker_work_periods WHERE id=1')->fetch();
check8j('valid historical KADIN to ERKEK correction succeeds',$result['ok']);
check8j('valid date and valid clock are persisted',$period['entry_time']==="$day 08:00:00" && $period['exit_time']==="$day 17:00:00");
check8j('both corrected type snapshots are ERKEK',(int)$period['worker_type_id_snapshot']===2 && $period['worker_type_name_snapshot']==='Erkek');
check8j('worker type master data remains unchanged',(int)$db->query('SELECT COUNT(*) FROM worker_types WHERE id=1 AND code="KADIN"')->fetchColumn()===1);
check8j('missing exit creates exactly one manual exit event',(int)$db->query("SELECT COUNT(*) FROM daily_worker_card_events WHERE source='manual' AND event_type='CIKIS'")->fetchColumn()===1);
$manual=$db->query("SELECT * FROM daily_worker_card_events WHERE source='manual'")->fetch();
check8j('new manual event uses corrected ERKEK snapshot',(int)$manual['worker_type_id_snapshot']===2 && $manual['worker_type_name_snapshot']==='Erkek' && $manual['worker_card_id']==1);
check8j('new manual event preserves corrected session depot and time',(int)$manual['session_id']===1 && $manual['depo_snapshot']==='Depo A' && $manual['server_event_time']==="$day 17:00:00");
check8j('new manual event retains target card canonical UID',$manual['canonical_uid_snapshot']==='UID-1');
check8j('raw GIRIS event remains immutable',$db->query('SELECT worker_type_id_snapshot FROM daily_worker_card_events WHERE id='.(int)$entryRaw['id'])->fetchColumn()==1);
pdks_faz8j_duzelt(payload8j(1,1,1,2,$day),7,$db);
check8j('ordinary repeated save does not duplicate manual exit',(int)$db->query("SELECT COUNT(*) FROM daily_worker_card_events WHERE source='manual' AND event_type='CIKIS'")->fetchColumn()===1);
check8j('draft entitlement is marked for recalculation',(int)$db->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=1')->fetchColumn()===1);
check8j('correction clears attendance and FM approvals',$db->query('SELECT approved_attendance_class FROM daily_worker_work_periods WHERE id=1')->fetchColumn()===null && $db->query('SELECT overtime_approved FROM daily_worker_work_periods WHERE id=1')->fetchColumn()===null);

putPeriod8j($db,3,1,2,1,"$day 18:00:00","$day 19:00:00",'closed');
putPeriod8j($db,4,1,3,1,"$day 10:00:00","$day 11:00:00",'closed');
$seq=payload8j(4,1,2,2,$day,'17:00'); check8j('sequential card reassignment is allowed',pdks_faz8j_duzelt($seq,7,$db)['ok']);
check8j('sequential reassignment updates the operational card link',(int)$db->query('SELECT worker_card_id FROM daily_worker_work_periods WHERE id=4')->fetchColumn()===2);
putPeriod8j($db,5,1,3,1,"$day 09:00:00","$day 12:00:00",'closed');
$overlap=payload8j(5,1,2,2,$day,'17:00'); $overlap['entry_clock']='18:30'; $overlap['exit_clock']='18:45'; check8j('overlapping target card is rejected',!pdks_faz8j_duzelt($overlap,7,$db)['ok']);

putPeriod8j($db,6,1,3,1,"$day 13:00:00","$day 17:00:00",'closed',99);
$db->prepare("INSERT INTO daily_worker_card_events (id,session_id,worker_card_id,event_type,source,canonical_uid_snapshot,worker_type_id_snapshot,worker_type_name_snapshot,work_date_snapshot,depo_snapshot,recorded_by_user_id,server_event_time) VALUES (99,1,3,'CIKIS','scan','UID-3',1,'Kadın',?,?,7,?)")->execute([$day,'Depo A',"$day 17:00:00"]);
$edit=payload8j(6,1,3,1,$day,'16:30'); $edit['entry_clock']='13:00'; check8j('operational exit correction succeeds',pdks_faz8j_duzelt($edit,7,$db)['ok']);
check8j('existing raw exit time is unchanged',$db->query('SELECT server_event_time FROM daily_worker_card_events WHERE id=99')->fetchColumn()==="$day 17:00:00");
$clear=payload8j(6,1,3,1,$day,null); $clear['entry_clock']='13:00'; check8j('intentional empty date and clock clear exit',pdks_faz8j_duzelt($clear,7,$db)['ok']);
check8j('clearing operational exit retains raw exit event',(int)$db->query('SELECT COUNT(*) FROM daily_worker_card_events WHERE id=99')->fetchColumn()===1 && $db->query('SELECT exit_event_id FROM daily_worker_work_periods WHERE id=6')->fetchColumn()===null);
check8j('cleared historical exit becomes unresolved not live-open',$db->query('SELECT status FROM daily_worker_work_periods WHERE id=6')->fetchColumn()==='legacy_unresolved');

putPeriod8j($db,7,2,1,1,"$day 08:00:00",null,'open');
$faz8jDepot='Depo B'; $db->exec("UPDATE foreman_daily_entitlements SET status='final' WHERE session_id=2");
check8j('final entitlement blocks edit',!pdks_faz8j_duzelt(payload8j(7,2,1,1,$day),7,$db)['ok']);
check8j('final entitlement blocks void',!pdks_faz8j_void(7,2,'Depo B','Neden',7,$db)['ok']);
check8j('final entitlement remains unchanged',(int)$db->query('SELECT is_voided FROM daily_worker_work_periods WHERE id=7')->fetchColumn()===0 && (int)$db->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=2')->fetchColumn()===0);
$db->exec("UPDATE foreman_daily_entitlements SET status='draft' WHERE session_id=2");
check8j('void reason is required',!pdks_faz8j_void(7,2,'Depo B','',7,$db)['ok']);
check8j('admin void succeeds',pdks_faz8j_void(7,2,'Depo B','Geçersiz kayıt',7,$db)['ok']);
check8j('void marks row but does not delete it',(int)$db->query('SELECT is_voided FROM daily_worker_work_periods WHERE id=7')->fetchColumn()===1 && (int)$db->query('SELECT COUNT(*) FROM daily_worker_work_periods WHERE id=7')->fetchColumn()===1);
check8j('void metadata records actor reason and time',$db->query('SELECT voided_by_user_id FROM daily_worker_work_periods WHERE id=7')->fetchColumn()==7 && $db->query('SELECT void_reason FROM daily_worker_work_periods WHERE id=7')->fetchColumn()==='Geçersiz kayıt' && $db->query('SELECT voided_at FROM daily_worker_work_periods WHERE id=7')->fetchColumn()!==null);
check8j('void leaves raw event history intact',(int)$db->query('SELECT COUNT(*) FROM daily_worker_card_events')->fetchColumn()>=2);
check8j('voided open period no longer blocks card',pdks_gunluk_faz8a_kart_acik_donemi($db,1)===null);
$summary=pdks_gunluk_faz8a_oturum_ozet(2,$db);
check8j('voided period is excluded from session entry totals',(int)$summary['giris_toplam']===0);
check8j('voided period is excluded from missing and inside totals',(int)$summary['eksik_toplam']===0 && (int)$summary['icerde_toplam']===0);
$faz8jDepot='Depo A';
check8j('edit audit is written',(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='puantaj_duzeltme'")->fetchColumn()>=1);
check8j('void audit is written',(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='puantaj_iptal'")->fetchColumn()>=1);

putPeriod8j($db,8,1,1,1,"$day 12:00:00",null,'legacy_unresolved'); $before=$db->query('SELECT worker_type_id_snapshot FROM daily_worker_work_periods WHERE id=8')->fetchColumn();
$db->exec("CREATE TRIGGER fail_edit_audit BEFORE INSERT ON audit_log WHEN NEW.action='puantaj_duzeltme' BEGIN SELECT RAISE(FAIL,'audit'); END");
check8j('audit failure rolls edit back',!pdks_faz8j_duzelt(payload8j(8,1,1,2,$day),7,$db)['ok'] && (int)$db->query('SELECT worker_type_id_snapshot FROM daily_worker_work_periods WHERE id=8')->fetchColumn()===(int)$before);
$db->exec('DROP TRIGGER fail_edit_audit');
$db->exec("CREATE TRIGGER fail_void_audit BEFORE INSERT ON audit_log WHEN NEW.action='puantaj_iptal' BEGIN SELECT RAISE(FAIL,'audit'); END");
check8j('audit failure rolls void back',!pdks_faz8j_void(8,1,'Depo A','Neden',7,$db)['ok'] && (int)$db->query('SELECT is_voided FROM daily_worker_work_periods WHERE id=8')->fetchColumn()===0);
$db->exec('DROP TRIGGER fail_void_audit');

// Faz 9C geçişi: yeni kolon varken onay sıfırlanır, migrasyon öncesinde
// aynı düzeltme/iptal yolları eski sütunlarla çalışmayı sürdürür.
$db->exec("INSERT INTO worker_cards (id,card_no,canonical_uid) VALUES (4,'K004','UID-4'),(5,'K005','UID-5'),(6,'K006','UID-6'),(7,'K007','UID-7')");
foreach ([9 => 4, 10 => 5] as $id => $card) putPeriod8j($db,$id,1,$card,1,"$day 08:00:00","$day 17:00:00",'closed');
$db->exec('UPDATE daily_worker_work_periods SET overtime_approved_hours=2 WHERE id IN (9,10)');
check8j('migrated correction succeeds', pdks_faz8j_duzelt(payload8j(9,1,4,2,$day),7,$db)['ok']);
check8j('migrated correction clears approved overtime hours', $db->query('SELECT overtime_approved_hours FROM daily_worker_work_periods WHERE id=9')->fetchColumn()===null);
check8j('migrated void succeeds', pdks_faz8j_void(10,1,'Depo A','Geçersiz kayıt',7,$db)['ok']);
check8j('migrated void clears approved overtime hours', $db->query('SELECT overtime_approved_hours FROM daily_worker_work_periods WHERE id=10')->fetchColumn()===null);

$db->exec('ALTER TABLE daily_worker_work_periods DROP COLUMN overtime_approved_hours');
pdks_gunluk_kolon_onbellek_temizle($db, 'daily_worker_work_periods');
foreach ([11 => 6, 12 => 7] as $id => $card) putPeriod8j($db,$id,1,$card,1,"$day 08:00:00","$day 17:00:00",'closed');
$legacyEdit = pdks_faz8j_duzelt(payload8j(11,1,6,2,$day),7,$db);
check8j('pre-9C correction succeeds without missing-column error', $legacyEdit['ok'] === true);
check8j('pre-9C correction still clears old overtime approval', $db->query('SELECT overtime_approved FROM daily_worker_work_periods WHERE id=11')->fetchColumn()===null);
$legacyVoid = pdks_faz8j_void(12,1,'Depo A','Geçersiz kayıt',7,$db);
check8j('pre-9C void succeeds without missing-column error', $legacyVoid['ok'] === true);
check8j('pre-9C void still marks row and clears old approval', (int)$db->query('SELECT is_voided FROM daily_worker_work_periods WHERE id=12')->fetchColumn()===1 && $db->query('SELECT overtime_approved FROM daily_worker_work_periods WHERE id=12')->fetchColumn()===null);

$source=file_get_contents($root.'/config/pdks_faz8j.php');
check8j('static raw event safety guard',!preg_match('/(?:UPDATE|DELETE)\s+daily_worker_card_events/i',$source),false);
check8j('static central void predicate remains used',str_contains(file_get_contents($root.'/config/pdks_gunluk.php'),'pdks_gunluk_faz8j_etkin_kosul'),false);
echo "SONUÇ: $pass geçti, $fail hata; gerçek DB doğrulaması: $behavioral, statik doğrulama: $static\n";
exit($fail===0?0:1);
