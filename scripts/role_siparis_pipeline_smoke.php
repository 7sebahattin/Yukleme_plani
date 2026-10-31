<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
require_once dirname(__DIR__).'/config/role_permission_matrix.php';
$grants=[];$checks=0;
define('SIPARIS_VARSAYILAN_TEDARIKCI','TEST');
function active_depot():?string{return 'A';}
function siparis_audit(...$args):void{}
function siparis_tx(PDO $pdo,callable $work):mixed { $pdo->beginTransaction();try{$result=$work();$pdo->commit();return $result;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;} }
$raceLookupHide=false;
class PipelineRaceStatement extends PDOStatement {
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed {
        global $raceLookupHide;
        if($raceLookupHide && str_starts_with($this->queryString,'SELECT id, deleted_at FROM siparis_sevkiyatlar')) { $raceLookupHide=false; parent::fetch($mode,$orientation,$offset); return false; }
        return parent::fetch($mode,$orientation,$offset);
    }
}
function can(string $p):bool{global $grants;return in_array($p,role_matrix_expand_permissions($grants),true);}
function is_admin():bool{return false;}
function siparis_gorunur(array $shipment):bool{return $shipment['depo']==='A';}
function siparis_simdi():string{return '2026-01-01 00:00:00';}
function siparis_kirp(string $value,int $limit=500):string{return substr($value,0,$limit);}
function siparis_tekrar_mi(PDOException $e):bool{return (string)$e->getCode()==='23000';}
function siparis_kaynak_etiket(string $source):string{return $source;}
function siparis_olay(PDO $pdo,int $id,...$args):void{$pdo->prepare('INSERT INTO event_test(shipment_id) VALUES (?)')->execute([$id]);}
function siparis_sevkiyat_yenile(PDO $pdo,int $id):void{$pdo->prepare('INSERT INTO refresh_test(shipment_id) VALUES (?)')->execute([$id]);}
function pipeline_ok(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$source=file_get_contents(dirname(__DIR__).'/config/siparis_eslestir.php');
foreach(['siparis_sevkiyat_bul_olustur','siparis_pipeline_authorize','siparis_sevkiyat_elle_ac'] as $name){$start=strpos($source,'function '.$name.'(');$end=strpos($source,"\n}\n",$start)+2;eval(substr($source,$start,$end-$start));}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PipelineRaceStatement::class]);
$pdo->exec("CREATE TABLE siparis_sevkiyatlar(id INTEGER PRIMARY KEY AUTOINCREMENT,tedarikci_no TEXT,order_ref TEXT,tur_no TEXT,durum TEXT,durum_at TEXT,depo TEXT,created_by INTEGER,created_at TEXT,updated_at TEXT,deleted_at TEXT,UNIQUE(tedarikci_no,order_ref));
CREATE TABLE event_test(shipment_id INTEGER); CREATE TABLE refresh_test(shipment_id INTEGER);
INSERT INTO siparis_sevkiyatlar(tedarikci_no,order_ref,depo,deleted_at,tur_no) VALUES('TEST','EXISTING','A',NULL,'SAME'),('TEST','DELETED','A','2025-01-01',NULL),('TEST','OTHER','B',NULL,NULL);");
$authorize=fn(string $action,?int $id)=>siparis_pipeline_authorize($pdo,$action,$id);
$try=function(string $ref,?string $tour=null) use ($pdo,$authorize):bool{
    $pdo->beginTransaction();
    try {siparis_sevkiyat_bul_olustur($pdo,'TEST',$ref,$tour,1,'A',1,'po',$authorize);$pdo->commit();return true;}
    catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();return false;}
};
$grants=['role.permissions.detailed','siparis.ogl.read','siparis.ogl.create'];
pipeline_ok(!$try('EXISTING'),'Create-only automatic matching updated existing');
pipeline_ok(!$try('DELETED'),'Create-only resurrected shipment');
pipeline_ok($try('NEW'),'Create-only could not create new');
pipeline_ok((int)$pdo->query("SELECT COUNT(*) FROM siparis_sevkiyatlar WHERE order_ref='NEW'")->fetchColumn()===1,'New row absent');
pipeline_ok(!$try('DUPTOUR','SAME'),'Create-only changed existing tour sibling');
pipeline_ok((int)$pdo->query("SELECT COUNT(*) FROM siparis_sevkiyatlar WHERE order_ref='DUPTOUR'")->fetchColumn()===0,'Denied tour matching left partial shipment');
pipeline_ok((int)$pdo->query('SELECT COUNT(*) FROM refresh_test')->fetchColumn()===0,'Denied tour matching refreshed existing shipment');
$grants=['role.permissions.detailed','siparis.ogl.read','siparis.ogl.edit'];
pipeline_ok(!$try('EDITONLYNEW'),'Edit-only reprocessing created shipment');
pipeline_ok($try('EXISTING'),'Edit-only existing match denied');
pipeline_ok($try('DELETED'),'Edit-only authorized restore denied');
pipeline_ok($pdo->query("SELECT deleted_at FROM siparis_sevkiyatlar WHERE order_ref='DELETED'")->fetchColumn()===null,'Restore did not apply');
pipeline_ok(!$try('OTHER'),'Edit grant escaped depot scope');
$grants=['role.permissions.detailed','siparis.urun.read','siparis.urun.create','siparis.urun.edit'];
pipeline_ok(!$try('EXISTING')&&!$try('PRODUCTONLYNEW'),'Product order grants leaked into OGL pipeline');
$pdo->exec("INSERT INTO siparis_sevkiyatlar(tedarikci_no,order_ref,depo,deleted_at) VALUES('TEST','TC100001.10','A','2025-01-01'),('TEST','TC100002.10','B','2025-01-01'),('TEST','TC100003.10','A','2025-01-01')");
$grants=['role.permissions.detailed','siparis.ogl.read','siparis.ogl.create'];
$manual=siparis_sevkiyat_elle_ac($pdo,'TC100001.10',null,null,1);
pipeline_ok(!$manual['ok'],'Manual create-only restored deleted shipment');
$manual=siparis_sevkiyat_elle_ac($pdo,'TC100002.10',null,null,1);
pipeline_ok(!$manual['ok'],'Manual create-only restored cross-depot shipment');
$raceLookupHide=true;
$manual=siparis_sevkiyat_elle_ac($pdo,'TC100003.10',null,null,1);
pipeline_ok(!$manual['ok']&&!$raceLookupHide,'Manual duplicate-key race bypassed edit callback');
pipeline_ok((int)$pdo->query("SELECT COUNT(*) FROM siparis_sevkiyatlar WHERE order_ref IN ('TC100001.10','TC100002.10','TC100003.10') AND deleted_at IS NULL")->fetchColumn()===0,'Denied manual restore changed row');
$manual=siparis_sevkiyat_elle_ac($pdo,'TC100004.10',null,null,1);
pipeline_ok($manual['ok'],'Manual create-only new shipment denied');
echo "Order pipeline: $checks checks passed.\n";
