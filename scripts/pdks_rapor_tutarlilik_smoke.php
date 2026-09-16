<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$testDb = new PDO('sqlite::memory:');
$testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $testDb; return $testDb; }
function can(string $permission): bool { return in_array($permission, ['attendance.management_reports', 'attendance.entitlements', 'attendance.foreman_rates'], true); }
function is_admin(): bool { return false; }
function active_depot(): ?string { return 'FİNİKE'; }
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_faz8e.php';
require_once $root . '/config/pdks_rapor.php';

$testDb->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT, sort_order INTEGER)");
$testDb->exec("INSERT INTO worker_types VALUES (1,'KADIN','Kadın',1),(2,'ERKEK','Erkek',2)");
$testDb->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)");
$testDb->exec("INSERT INTO foremen VALUES (1,'F1','Ayşe',1),(2,'F2','Mehmet',1)");
$testDb->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY, card_no TEXT, canonical_uid TEXT, uid_decimal TEXT, worker_type_id INTEGER NULL REFERENCES worker_types(id))");
for ($i = 1; $i <= 8; $i++) $testDb->prepare('INSERT INTO worker_cards VALUES (?,?,?,?,NULL)')->execute([$i,'K'.$i,'AABB'.sprintf('%04X',$i),(string)(1000+$i)]);
$testDb->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, notes TEXT)");
$testDb->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)");
$testDb->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT, approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT)");
$testDb->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, session_id INTEGER, foreman_id INTEGER, work_date TEXT, depo TEXT, status TEXT, needs_recalculation INTEGER, currency TEXT, total_amount TEXT)");
$testDb->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY, foreman_id INTEGER, payment_date TEXT, status TEXT, currency TEXT, amount TEXT)");
$testDb->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$day = date('Y-m-d', strtotime('yesterday'));
$prev = date('Y-m-d', strtotime('2 days ago'));
$phantomDay = date('Y-m-d', strtotime('3 days ago'));
$legacyDay = date('Y-m-d', strtotime('4 days ago'));
$insS = $testDb->prepare('INSERT INTO daily_work_sessions VALUES (?,?,?,?,?,?,?,NULL)');
foreach ([[1,1,$day,'FİNİKE','open'],[2,1,$day,'FİNİKE','open'],[3,2,$day,'FİNİKE','closed'],[4,1,$day,'MERKEZ','closed'],[5,2,$prev,'FİNİKE','open'],[6,1,$day,'FİNİKE','closed'],[7,1,$phantomDay,'FİNİKE','open'],[8,1,$legacyDay,'FİNİKE','open']] as [$id,$fid,$date,$depot,$status]) {
    $insS->execute([$id,$fid,$fid===1?'Ayşe':'Mehmet','F'.$fid,$date,$depot,$status]);
}
$insP = $testDb->prepare('INSERT INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_event_id,entry_time,exit_time,work_date_snapshot,depo_snapshot,status,source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([
    [1,1,1,1,$day,'FİNİKE','closed',"$day 17:00:00"], // üretimdeki çelişki: açık oturum, içeride işçi yok
    [2,2,1,1,$day,'FİNİKE','open',null],      // aynı fiziksel kartın yeni meşru dönemi
    [3,2,2,2,$day,'FİNİKE','closed',"$day 17:00:00"],
    [4,2,1,1,$day,'FİNİKE','closed',"$day 12:00:00"], // aynı kart, aynı gün, ayrı dönem
    [5,3,3,2,$day,'FİNİKE','open',null],
    [6,3,4,1,$day,'FİNİKE','legacy_unresolved',null],
    [7,4,5,1,$day,'MERKEZ','open',null],
    [8,5,6,2,$prev,'FİNİKE','open',null],
    [9,6,7,2,$day,'FİNİKE','closed',"$day 17:00:00"],
    [10,7,8,1,$phantomDay,'FİNİKE','closed',"$phantomDay 17:00:00"],
    [11,8,8,1,$legacyDay,'FİNİKE','legacy_unresolved',null],
] as [$id,$sid,$card,$type,$date,$depot,$status,$exit]) {
    $insP->execute([$id,$sid,$card,$type,$type===1?'Kadın':'Erkek',$id,"$date 08:00:00",$exit,$date,$depot,$status,'scan']);
}
$testDb->prepare("INSERT INTO foreman_daily_entitlements VALUES (1,6,1,?,'FİNİKE','final',0,'TRY','100.00')")->execute([$day]);
$testDb->prepare("INSERT INTO foreman_daily_entitlements VALUES (2,3,2,?,'FİNİKE','final',0,'EUR','20.00')")->execute([$day]);
$testDb->prepare("INSERT INTO foreman_daily_entitlements VALUES (3,2,1,?,'FİNİKE','draft',0,'TRY','0.00')")->execute([$day]);
$testDb->prepare("INSERT INTO foreman_payments VALUES (1,1,?,'valid','TRY','5.00')")->execute([$day]);

$pass=0; $fail=0;
function ok8f(string $name, bool $yes): void { global $pass,$fail; $yes ? $pass++ : $fail++; echo ($yes?'PASS ':'FAIL ').$name."\n"; }
function report8f(string $start, string $end, ?string $depot='FİNİKE', ?int $foreman=null, ?int $type=null): array {
    $pdo=db();
    return [
        pdks_rapor_operasyonel_kpi($start,$end,$depot,$foreman,$type,$pdo),
        pdks_rapor_acik_mesailer_araligi($start,$end,$depot,$foreman,$pdo,$type),
        pdks_rapor_eksik_cikislar_araligi($start,$end,$depot,$foreman,$pdo,$type),
        pdks_rapor_gunluk_trend($start,$end,$depot,$foreman,$type,$pdo),
        pdks_rapor_cavus_ozeti($start,$end,$depot,$foreman,$type,$pdo),
    ];
}
function consistent8f(array $r): bool { return $r[0]['acik_mesai']===count($r[1]) && $r[0]['eksik_cikis_mesai']===count($r[2]); }
ok8f('Faz 8A şeması etkin', pdks_gunluk_faz8a_sema_hazir($testDb));
[$k,$open,$missing,$trend,$summary] = report8f($day,$day);
$phantomOnly=report8f($phantomDay,$phantomDay);
ok8f('Açık oturumda tüm çıkışlar tamam ise KPI=0, liste boş', $phantomOnly[0]['toplam_calisan']===1 && $phantomOnly[0]['acik_mesai']===0 && $phantomOnly[1]===[]);
$legacyOnly=report8f($legacyDay,$legacyDay);
ok8f('Açık oturumda tarihsel legacy_unresolved içeride değil eksik çıkıştır', consistent8f($legacyOnly) && $legacyOnly[0]['acik_mesai']===0 && $legacyOnly[0]['eksik_cikis_mesai']===1 && $legacyOnly[1]===[] && count($legacyOnly[2])===1 && $legacyOnly[3][0]['eksik_cikis']===1 && $legacyOnly[4][0]['eksik_cikis']===1);
$phantom = report8f($day,$day,'FİNİKE',1,2);
ok8f('Açık oturumda açık erkek dönemi yoksa KPI/liste sıfır', $phantom[0]['acik_mesai']===0 && $phantom[1]===[]);
ok8f('Açık işçi dönemi KPI ve listede aynı kart', $k['acik_mesai']===1 && count($open)===1 && $open[0]['card_no']==='K1');
ok8f('Kapalı oturumun iki eksik dönemi iki satır sayılır', $k['eksik_cikis_mesai']===2 && count($missing)===2);
ok8f('Kapalı eksikler içeride listesine girmez', !in_array('K3',array_column($open,'card_no'),true) && !in_array('K4',array_column($open,'card_no'),true));
ok8f('Tamamlanan dönem iki listede de yok', !in_array('K2',array_column($open,'card_no'),true) && !in_array('K2',array_column($missing,'card_no'),true));
ok8f('KPI ve detay sayıları aynı', consistent8f([$k,$open,$missing]));
ok8f('Aynı kartın meşru dönemleri ayrı katılım', $k['toplam_calisan']===7 && $k['fiziksel_kart_kullanimi']===5);
ok8f('Günlük trend katılım/eksik/çavuş sayısı aynı veri kümesi', $trend[0]['toplam_calisan']===7 && $trend[0]['eksik_cikis']===2 && $trend[0]['aktif_cavus']===2);
$female=report8f($day,$day,'FİNİKE',null,1);
$male=report8f($day,$day,'FİNİKE',null,2);
ok8f('Kadın filtresi KPI, açık, eksik, trend ve özetle tutarlı', consistent8f($female) && $female[0]['toplam_calisan']===4 && $female[0]['acik_mesai']===1 && $female[0]['eksik_cikis_mesai']===1 && $female[3][0]['toplam_calisan']===4 && $female[3][0]['eksik_cikis']===1 && array_sum(array_column($female[4],'toplam_isci'))===4 && array_sum(array_column($female[4],'eksik_cikis'))===1);
ok8f('Erkek filtresi KPI, açık, eksik, trend ve özetle tutarlı', consistent8f($male) && $male[0]['toplam_calisan']===3 && $male[0]['acik_mesai']===0 && $male[0]['eksik_cikis_mesai']===1 && $male[3][0]['toplam_calisan']===3 && $male[3][0]['eksik_cikis']===1 && array_sum(array_column($male[4],'toplam_isci'))===3);
ok8f('İşçi tipi dağılımı seçili tipe daralır', count(pdks_rapor_isci_tipi_dagilimi($day,$day,'FİNİKE',null,$testDb,1))===1 && pdks_rapor_isci_tipi_dagilimi($day,$day,'FİNİKE',null,$testDb,1)[0]['ad']==='Kadın');
$ayse=report8f($day,$day,'FİNİKE',1);
$mehmet=report8f($day,$day,'FİNİKE',2);
ok8f('Çavuş filtresi tüm operasyonel bölümlerde aynı', consistent8f($ayse) && consistent8f($mehmet) && $ayse[0]['toplam_calisan']===5 && $mehmet[0]['toplam_calisan']===2 && count($ayse[4])===1 && count($mehmet[4])===1);
$other=report8f($day,$day,'MERKEZ');
ok8f('Depo filtresi tüm bölümlerde aynı', consistent8f($other) && $other[0]['toplam_calisan']===1 && $other[0]['eksik_cikis_mesai']===1 && $other[3][0]['eksik_cikis']===1);
$range=report8f($prev,$day);
ok8f('Tarih aralığı aynı: önceki gün açık işçi ayrıca sayılır', consistent8f($range) && $range[0]['acik_mesai']===2 && count($range[3])===2);
$financeBefore=pdks_rapor_finansal_kpi($day,$day,'FİNİKE',null,$testDb);
ok8f('TRY/EUR finans ayrı kalır', isset($financeBefore['TRY'],$financeBefore['EUR']) && $financeBefore['TRY']['hakedis']==='100.00' && $financeBefore['EUR']['hakedis']==='20.00');
$manual=pdks_faz8e_manuel_cikis_kaydet(2,'FİNİKE',$day,'17:00','Kart bozuk','',7,$testDb);
$after=report8f($day,$day);
ok8f('Faz 8E manuel çıkış doğal olarak açık listeden düşer', $manual['ok'] && consistent8f($after) && $after[0]['acik_mesai']===0 && $after[1]===[]);
ok8f('Manuel çıkış eksik listeye de eklenmez', $after[0]['eksik_cikis_mesai']===2 && count($after[2])===2);
ok8f('Finansal tutarlar çıkış düzeltmesinden etkilenmez', pdks_rapor_finansal_kpi($day,$day,'FİNİKE',null,$testDb)===$financeBefore);
$monthly=pdks_rapor_cavus_toplu_dokum(substr($day,0,7),'FİNİKE',1,false,$testDb);
$session2=array_values(array_filter($monthly,fn($r)=>(int)$r['session_id']===2))[0]??null;
ok8f('Faz 8D aylık satırda işçi/tip sayıları korunur, çıkış ve eksik doğal güncellenir', $session2!==null && (int)$session2['toplam_isci']===3 && (int)$session2['kadin']===2 && (int)$session2['erkek']===1 && (int)$session2['eksik_cikis']===0 && $session2['son_cikis']==="$day 17:00:00" && $session2['ilk_giris']==="$day 08:00:00");
$testDb->exec("UPDATE daily_worker_work_periods SET status='open' WHERE id=9");
$monthlyOdd=pdks_rapor_cavus_toplu_dokum(substr($day,0,7),'FİNİKE',1,false,$testDb);
$odd=array_values(array_filter($monthlyOdd,fn($r)=>(int)$r['session_id']===6))[0]??null;
$oddDetail=pdks_rapor_cavus_kart_dokumu(6,'FİNİKE',$testDb);
ok8f('Faz 8D çıkış zamanı dolu kaydı eksik saymaz', $odd!==null && (int)$odd['eksik_cikis']===0 && $oddDetail['cards'][0]['eksik_cikis']===false);
$web=file_get_contents($root.'/raporlar.php'); $print=file_get_contents($root.'/rapor_yazdir.php');
ok8f('Web ve yazdırma aynı filtreli KPI/açık/eksik kaynaklarını çağırır', str_contains($web,'$pdo, $tipId)') && str_contains($print,'$pdo, $tipId)') && str_contains($print,"(int)\$kpi['acik_mesai']") && str_contains($print,'count($acikMesai)'));
ok8f('Günlük ve Çavuş CSV aynı trend/özet dizilerinden yazılır', str_contains($web,"foreach (\$trend as \$g)") && str_contains($web,"foreach (\$cavusOzeti as \$c)"));
echo "SONUÇ: $pass geçti, $fail hata\n";
exit($fail===0?0:1);
