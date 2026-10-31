<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/config/role_pdks_matrix.php';
$grants=[]; $checks=0;
function can(string $p):bool{return in_array($p,$GLOBALS['grants'],true);}
function is_admin():bool{return false;}
function pdks_faz8b_sema_hazir(PDO $pdo):bool{return true;}
function pdks_cavus_sure_revizyon(int $id, PDO $pdo):int{return 0;}
function forbidden(string $message=''):void{throw new RuntimeException('denied');}
function current_user():?array{return ['id'=>1];}
function enforce_active_depot():void{}
function assert_gate(bool $pass,string $message):void{ $GLOBALS['checks']++;if(!$pass)throw new RuntimeException($message); }
$root=dirname(__DIR__);
function load_gate_function(string $path,string $name):void{
    $source=str_replace("\r\n","\n",file_get_contents($path));
    $a=strpos($source,'function '.$name.'(');
    if($a===false)throw new RuntimeException('Missing function '.$name);
    $b=strpos($source,"\n}\n",$a);
    if($b===false)throw new RuntimeException('Unclosed function');
    eval(substr($source,$a,$b-$a+3));
}
load_gate_function($root.'/config/pdks_hakedis.php','pdks_hakedis_hesapla');
load_gate_function($root.'/config/pdks_faz8b.php','pdks_faz8b_hakedis_hesapla');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE daily_work_sessions(id INTEGER,foreman_id INTEGER); CREATE TABLE foreman_daily_entitlements(session_id INTEGER,status TEXT); INSERT INTO daily_work_sessions VALUES(1,1),(2,1); INSERT INTO foreman_daily_entitlements VALUES(1,'draft')");
foreach(['pdks_hakedis_hesapla','pdks_faz8b_hakedis_hesapla'] as $engine){
    foreach([['read',1],['create',1],['edit',2]] as [$action,$id]){
        $grants=['role.permissions.detailed','attendance.entitlements.read','attendance.entitlements.'.$action];
        $before=$pdo->query('SELECT * FROM foreman_daily_entitlements')->fetchAll();
        $result=$engine($id,1,$pdo);
        assert_gate(!$result['ok']&&$result['kod']==='yetki_yok',$engine.' wrong action not denied');
        assert_gate($pdo->query('SELECT * FROM foreman_daily_entitlements')->fetchAll()===$before&&!$pdo->inTransaction(),$engine.' denial changed data');
    }
}
// Execute the actual shared request hook from require_pdks with anonymous stubs.
define('PDKS_AKTIF',true);
load_gate_function($root.'/config/pdks.php','pdks_can');
load_gate_function($root.'/config/pdks.php','require_pdks');
$grants=role_pdks_expand_permissions(['role.permissions.detailed','attendance.employees.read']);
$_SERVER['SCRIPT_NAME']='/personel_form.php';$_SERVER['REQUEST_METHOD']='POST';$_GET=['id'=>1];$_POST=['action'=>'save_employee'];
$denied=false;try{require_pdks('employees');}catch(RuntimeException $e){$denied=$e->getMessage()==='denied';}
assert_gate($denied,'Actual require_pdks allowed read-only POST');
$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
require_pdks('employees');assert_gate(true,'Actual require_pdks denied read-only GET');
function pdks_gunluk_kart_tanim_sema_hazir(PDO $pdo):bool{return true;}
function pdks_gunluk_desteklenen_tip_coz(int $id,PDO $pdo):array{return ['id'=>$id];}
function pdks_gunluk_faz8a_kart_kilitle(PDO $pdo,int $id):void{}
function pdks_gunluk_ters_havuz_engeli(PDO $pdo,int $id,int $foreman,string $depo):?array{return null;}
function pdks_gunluk_kart_tanim_aktif(PDO $pdo,int $id):?array{$q=$pdo->prepare('SELECT * FROM worker_card_assignments WHERE aktif_kart_id=?');$q->execute([$id]);return $q->fetch()?:null;}
function pdks_gunluk_depo_fold(string $depo):string{return strtolower($depo);}
function pdks_gunluk_faz8a_sema_hazir(PDO $pdo):bool{return false;}
load_gate_function($root.'/config/pdks_gunluk.php','pdks_gunluk_kart_tanim_kaydet');
$pdo->exec("CREATE TABLE worker_cards(id INTEGER,card_no TEXT,enrolled_source TEXT); CREATE TABLE foremen(id INTEGER,name TEXT,is_active INTEGER); CREATE TABLE worker_card_assignments(id INTEGER PRIMARY KEY AUTOINCREMENT,worker_card_id INTEGER,foreman_id INTEGER,worker_type_id INTEGER,depo TEXT,aktif_kart_id INTEGER,valid_from TEXT,valid_to TEXT,created_by_user_id INTEGER,ended_by_user_id INTEGER,end_reason TEXT); INSERT INTO worker_cards VALUES(1,'TEST1','usb'),(2,'TEST2','usb'),(3,'TEST3','usb'); INSERT INTO foremen VALUES(1,'Test',1); INSERT INTO worker_card_assignments(worker_card_id,foreman_id,worker_type_id,depo,aktif_kart_id) VALUES(1,1,1,'A',1)");
$grants=['role.permissions.detailed','attendance.worker_cards.read','attendance.worker_cards.create'];
$before=$pdo->query('SELECT * FROM worker_card_assignments')->fetchAll();
$result=pdks_gunluk_kart_tanim_kaydet(1,1,2,'A',1,$pdo);
assert_gate(!$result['ok']&&$result['kod']==='yetki_yok','Create-only batch changed existing assignment');
assert_gate($before===$pdo->query('SELECT * FROM worker_card_assignments')->fetchAll()&&!$pdo->inTransaction(),'Denied assignment left writes or transaction');
$result=pdks_gunluk_kart_tanim_kaydet(1,1,1,'A',1,$pdo);
assert_gate($result['ok']&&!empty($result['degisiklik_yok']),'Unchanged existing assignment lost no-op');
$result=pdks_gunluk_kart_tanim_kaydet(2,1,1,'A',1,$pdo);
assert_gate($result['ok'],'Create-only new assignment denied');
$grants=['role.permissions.detailed','attendance.worker_cards.read','attendance.worker_cards.edit'];
$result=pdks_gunluk_kart_tanim_kaydet(3,1,1,'A',1,$pdo);
assert_gate(!$result['ok']&&$result['kod']==='yetki_yok','Edit-only created new assignment');
echo "PDKS engine/request gates: $checks checks passed.\n";
