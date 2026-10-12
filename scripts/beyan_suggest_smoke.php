<?php
// SQLite gerçek helper ve endpoint kontrol akışı; canlı DB/HKS yüklenmez.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BEYAN_ONERI_BASLANGIC', '2026-01-01 00:00:00');   // test sabiti (gerçek saatten önce olmalı; helpers.php'deki değerle ayrı)
$tokens = token_get_all((string)file_get_contents(dirname(__DIR__) . '/config/helpers.php'));
for ($i=0,$n=count($tokens);$i<$n;$i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $j=$i+1; while (is_array($tokens[$j] ?? null) && $tokens[$j][0]===T_WHITESPACE) $j++;
    $name=$tokens[$j][1] ?? '';
    if ($name !== 'tr_upper' && !str_starts_with($name, 'beyan_suggest_')) continue;
    $code='';$depth=0;$started=false;
    for (;$i<$n;$i++) { $t=$tokens[$i];$code.=is_array($t)?$t[1]:$t;
        if ($t==='{') { $depth++;$started=true; }
        if ($t==='}' && --$depth===0 && $started) break;
    }
    eval($code);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO { global $pdo;return $pdo; }
$catalog=['urunler'=>[['id'=>'1','ad'=>'Şeftali'],['id'=>'1','ad'=>'SEFTALI'],['id'=>'2','ad'=>'ELMA'],['id'=>'3','ad'=>'elma']]];
function bb_katalog(): array { global $catalog; return $catalog; }
$pdo->exec('CREATE TABLE hks_kv (anahtar TEXT PRIMARY KEY, deger TEXT)');
$pdo->exec('CREATE TABLE material_definitions (type TEXT,name TEXT,is_active INTEGER)');
$cols=implode(' TEXT, ',array_keys(beyan_suggest_fields())) . ' TEXT';
$pdo->exec("CREATE TABLE customs_declarations (id INTEGER PRIMARY KEY, deleted_at TEXT, created_at TEXT, $cols)");
$pdo->exec("INSERT INTO material_definitions VALUES ('urun','seftali',1), ('alici','ÖRNEK ALICI',1)");
$pdo->exec("INSERT INTO customs_declarations (created_at,company_name,company_address,buyer_name) VALUES ('2026-03-13 10:00:00','GÜMRÜK A','ADRES A','Geçmiş Alıcı')");
// Temiz başlangıçtan ÖNCEki beyan — öneride görünmemeli.
$pdo->exec("INSERT INTO customs_declarations (created_at,company_name,company_address,buyer_name,brand) VALUES ('2025-10-01 10:00:00','ESKİ GÜMRÜK','ESKİ ADRES','ESKİ ALICI','ESKİ MARKA')");
if (($argv[1] ?? '') === '--endpoint') {
    function require_login(): array { return ['id'=>1]; }
    function can_beyan(string $perm): bool { return getenv('BEYAN_TEST_DENY') !== '1'; }
    function csrf_check($token): void {
        if ($token !== 'test-csrf') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }
    }
    function audit_log_event(...$args): void {}
    $_SERVER['REQUEST_METHOD']=getenv('BEYAN_TEST_METHOD') ?: 'POST';
    $code=(string)file_get_contents(dirname(__DIR__) . '/api_beyan_tanim_ekle.php');
    $code=preg_replace("~^require_once __DIR__ \\. '/config/(?:db|auth)\\.php';\\R~m", '', $code);
    $code=str_replace("'php://input'", "'php://stdin'", $code);
    eval(substr($code,5));exit;
}
$count=0;$fails=0;
function ck(string $label,bool $ok): void { global $count,$fails;$count++;if (!$ok) { $fails++;echo "FAIL $label\n"; } }
function rejects(callable $f): bool { try {$f();} catch (InvalidArgumentException $e) {return true;}return false; }
ck('TR normalize',beyan_suggest_normalize('buyer_name',"  işi   şeftali ")==='İŞİ ŞEFTALİ');
ck('accent duplicate',beyan_suggest_key('ÇİĞ ŞEFTALİ')===beyan_suggest_key('cig seftali'));
$d=beyan_suggest_form_data();
ck('catalog duplicate merge',count($d['values']['product_name'])===2);
ck('single id safe',$d['products']['ŞEFTALİ']['urun_id']==='1');
ck('ambiguous no automatic HKS',!isset($d['products']['ELMA']));
ck('history suggestions',in_array('GEÇMİŞ ALICI',$d['values']['buyer_name'],true));
ck('history address',$d['companies']['GÜMRÜK A']==='ADRES A');
ck('temiz başlangıç: eski beyan alıcısı YOK',!in_array('ESKİ ALICI',$d['values']['buyer_name'],true));
ck('temiz başlangıç: eski beyan markası YOK',!in_array('ESKİ MARKA',$d['values']['brand'],true));
ck('temiz başlangıç: eski gümrük/adres YOK',!isset($d['companies']['ESKİ GÜMRÜK']));
ck('temiz başlangıç: tanım havuzu (material_definitions) kullanılmıyor',!in_array('ÖRNEK ALICI',$d['values']['buyer_name'],true));
// Zaman damgasız (eski) KV tanımı görünmez; +ekle ile yeniden eklenince görünür.
$pdo->exec("INSERT INTO hks_kv (anahtar,deger) VALUES ('beyan_defs_brand','{\"ESKI TANIM\":{\"name\":\"ESKİ TANIM\"}}')");
ck('temiz başlangıç: damgasız eski KV tanımı YOK',!in_array('ESKİ TANIM',beyan_suggest_form_data()['values']['brand'],true));
beyan_suggest_save($pdo,'brand','eski tanım');
ck('+ekle sonrası tanım görünür (added_at damgalı)',in_array('ESKİ TANIM',beyan_suggest_form_data()['values']['brand'],true)
   && !empty(beyan_suggest_read($pdo,'brand')['ESKI TANIM']['added_at']));
$pdo->exec("DELETE FROM hks_kv");
$r=beyan_suggest_save($pdo,'product_name','seftali');
ck('catalog canonical id',$r['name']==='ŞEFTALİ' && $r['urun_id']==='1');
ck('catalog no manual duplicate',(int)$pdo->query("SELECT count(*) FROM hks_kv")->fetchColumn()===0);
$r=beyan_suggest_save($pdo,'buyer_name','yeni alıcı');
ck('new upper',$r['name']==='YENİ ALICI');
$r=beyan_suggest_save($pdo,'buyer_name','YENI ALICI');
ck('duplicate canonical',$r['name']==='YENİ ALICI' && count(beyan_suggest_read($pdo,'buyer_name'))===1);
ck('forbidden field',rejects(fn()=>beyan_suggest_save($pdo,'status','X')));
ck('meaningless',rejects(fn()=>beyan_suggest_save($pdo,'brand','---')));
ck('long reject',rejects(fn()=>beyan_suggest_save($pdo,'brand',str_repeat('A',51))));
ck('address needs name',rejects(fn()=>beyan_suggest_save($pdo,'company_address','adres')));
beyan_suggest_save($pdo,'company_name','YENİ GÜMRÜK');
beyan_suggest_save($pdo,'company_address','açık adres','yeni gumruk');
$d=beyan_suggest_form_data();
ck('address linked canonical',$d['companies']['YENİ GÜMRÜK']==='AÇIK ADRES');
beyan_suggest_save($pdo,'company_name','Yeni Gümrük');
ck('empty address does not erase',beyan_suggest_form_data()['companies']['YENİ GÜMRÜK']==='AÇIK ADRES');
beyan_suggest_save($pdo,'company_name','Diğer Gümrük','','adres2');
ck('name and address add',beyan_suggest_form_data()['companies']['DİĞER GÜMRÜK']==='ADRES2');
$catalog=[];
ck('catalog missing manual fallback',beyan_suggest_save($pdo,'product_name','yeni ürün')['name']==='YENİ ÜRÜN');
$pdo->exec('DROP TABLE hks_kv');
ck('KV missing form works',in_array('GEÇMİŞ ALICI',beyan_suggest_form_data()['values']['buyer_name'],true));
try { beyan_suggest_save($pdo,'brand','ASYATEST');$failed=false; } catch (PDOException $e) {$failed=true;}
ck('KV missing honest error and rollback',$failed && !$pdo->inTransaction());
function endpoint(array $payload,array $env=[]): array {
    $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--endpoint'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,array_merge(getenv(),$env));
    fwrite($pipes[0],json_encode($payload));fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $status=proc_close($proc);if($status!==0) echo $err;
    return json_decode($out,true) ?: [];
}
$payload=['csrf'=>'test-csrf','field'=>'buyer_name','name'=>'deneme alıcı'];
ck('endpoint saves',(endpoint($payload)['name'] ?? '')==='DENEME ALICI');
ck('endpoint permission denied',(endpoint($payload,['BEYAN_TEST_DENY'=>'1'])['ok'] ?? true)===false);
ck('endpoint CSRF denied',(endpoint(array_merge($payload,['csrf'=>'bad']))['error'] ?? '')==='CSRF');
ck('endpoint GET denied',(endpoint($payload,['BEYAN_TEST_METHOD'=>'GET'])['ok'] ?? true)===false);
ck('endpoint unknown rejected',(endpoint(array_merge($payload,['field'=>'status']))['ok'] ?? true)===false);
ck('endpoint array rejected',(endpoint(array_merge($payload,['name'=>['x']]))['ok'] ?? true)===false);
echo "Beyan öğrenme: $count kontrol, $fails hata.\n";exit($fails ? 1 : 0);
