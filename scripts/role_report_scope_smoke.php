<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$root=dirname(__DIR__);
function report_test_function(string $file,string $name): void {
    $source=file_get_contents($file); $start=strpos($source,'function '.$name.'('); $end=strpos($source,"\n}\n",$start)+2;
    if ($start===false||$end<2) throw new RuntimeException('Missing function '.$name);
    eval(substr($source,$start,$end-$start));
}
$allowed=['A']; $restricted=true;
function user_allowed_depots(): ?array { global $allowed; return $allowed; }
function role_current_depot_scope_restricted(): bool { global $restricted; return $restricted; }
foreach (['depo_sql_records','depo_sql_column','depo_sql_in','depo_sql_records_in'] as $name) report_test_function($root.'/config/auth.php',$name);
$testDb=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO { global $testDb; return $testDb; }
$testDb->exec("CREATE TABLE loading_records(id INTEGER PRIMARY KEY,type TEXT,tarih TEXT,firma TEXT,urun TEXT,bolge TEXT);
CREATE TABLE loading_pallets(id INTEGER PRIMARY KEY,loading_record_id INTEGER,depo TEXT,kasa_adeti INTEGER,brut_kg REAL,dara_kg REAL,net_kg REAL);
CREATE TABLE material_definitions(id INTEGER PRIMARY KEY,type TEXT,name TEXT,unit_dara_kg REAL,is_active INTEGER);
CREATE TABLE pallet_materials(id INTEGER PRIMARY KEY,loading_pallet_id INTEGER,material_id INTEGER,quantity REAL,total_dara_kg REAL);
CREATE TABLE material_stock_movements(material_id INTEGER,movement_type TEXT,quantity REAL,depo TEXT);
INSERT INTO loading_records VALUES(1,'yukleme','2026-01-01','A FIRM','A PRODUCT','A REGION'),(2,'yukleme','2026-01-01','B FIRM','B PRODUCT','B REGION'),(3,'yukleme','2026-01-01','MIXED FIRM','MIXED PRODUCT','MIXED REGION'),(4,'yukleme','2026-01-01','EMPTY FIRM','EMPTY PRODUCT','EMPTY REGION');
INSERT INTO loading_pallets VALUES(1,1,'A',10,10,0,10),(2,2,'B',1000,1000,0,1000),(3,3,'A',100,100,0,100),(4,3,'B',200,200,0,200),(5,4,'',500,500,0,500);
INSERT INTO material_definitions VALUES(1,'kasa','TEST MATERIAL',1,1);
INSERT INTO pallet_materials VALUES(1,1,1,10,10),(2,2,1,1000,1000),(3,3,1,100,100),(4,5,1,500,500);
INSERT INTO material_stock_movements VALUES(1,'giris',20,'A'),(1,'giris',2000,'B'),(1,'giris',500,''),(1,'sevk',3,'A'),(1,'sevk',300,'B');");
$checks=0;
function report_ok(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$source=file_get_contents($root.'/reports.php');
report_test_function($root.'/reports.php','rpt_date_filter');
report_test_function($root.'/reports.php','rpt_merge_rows');
function rpt_norm(string $v):string{return strtolower($v);}
foreach(['rpt_scoped_record_options','rpt_scoped_depot_options'] as $name)report_test_function($root.'/reports.php',$name);
report_ok(rpt_scoped_record_options('firma')===['A FIRM'],'Filter leaked outside or mixed record');
report_ok(rpt_scoped_depot_options()===['A'],'Depot choices leaked');
$start=strpos($source,"} elseif (\$type === 'depo') {")+strlen("} elseif (\$type === 'depo') {");
$end=strpos($source,"} elseif (\$type === 'urun')",$start);
$f_depo=$f_firma=$f_from=$f_to=''; $rows=$totals=[];
eval(substr($source,$start,$end-$start));
report_ok(count($rows)===1&&$rows[0]['depo']==='A'&&(int)$rows[0]['toplam_kasa']===10,'Depot aggregate leaked another depot or mixed record');
$start=strpos($source,"} elseif (\$type === 'malzeme') {")+strlen("} elseif (\$type === 'malzeme') {");
$end=strpos($source,"} elseif (\$type === 'kantar')",$start);$f_mtype=$f_q='';$rows=$totals=[];
eval(substr($source,$start,$end-$start));
report_ok(count($rows)===1&&(int)$rows[0]['toplam_adet']===10,'Material usage aggregate leaked');
report_ok((int)$rows[0]['stok_giris']===20&&(int)$rows[0]['stok_sevk']===3&&(int)$rows[0]['stok_mevcut']===17,'Material stock aggregate leaked');
$allowed=[];$rows=$totals=[];eval(substr($source,$start,$end-$start));
report_ok((int)$rows[0]['toplam_adet']===0&&(int)$rows[0]['stok_giris']===0,'Empty selected scope did not fail closed');
$allowed=['A'];
report_test_function($root.'/rapor_malzeme.php','rm_filters');
[$where,$params]=rm_filters(['tarih_bas'=>'','tarih_bit'=>'','firma'=>'','urun'=>'','depo'=>'','urun_sahibi'=>''],false);
$st=$testDb->prepare('SELECT p.id FROM loading_pallets p JOIN loading_records r ON r.id=p.loading_record_id WHERE '.$where);$st->execute($params);
report_ok($st->fetchAll(PDO::FETCH_COLUMN)===[1],'Pivot material filter leaked mixed record');
function require_login():array{return ['id'=>1];}
$grants=[];
function require_perm(string $permission):void{global $grants;if(!in_array($permission,$grants,true))throw new RuntimeException('denied:'.$permission);}
$headerStart=strpos($source,'$auth_user = require_login();');$headerEnd=strpos($source,'$report_meta =',$headerStart);$header=substr($source,$headerStart,$headerEnd-$headerStart);
$_GET=['type'=>'yukleme','export'=>'csv'];
foreach ([['reports.export','reports.yukleme.export'],['reports.read','reports.export','reports.yukleme.export']] as $grants) {
    $denied=false;try{eval($header);}catch(RuntimeException $e){$denied=true;}report_ok($denied,'Export bypassed parent/child read gate');
}
$grants=['reports.read','reports.export','reports.yukleme.read','reports.yukleme.export'];eval($header);report_ok(true,'Authorized export denied');
echo "Report scope: $checks checks passed.\n";
