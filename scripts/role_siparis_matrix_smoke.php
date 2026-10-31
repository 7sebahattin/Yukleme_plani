<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require_once dirname(__DIR__).'/config/role_permission_matrix.php';
$grants=[];$administrator=false;$checks=0;
function can(string $p):bool{global $grants;return in_array($p,role_matrix_expand_permissions($grants),true);}
function is_admin():bool{global $administrator;return $administrator;}
function current_user():?array{return ['id'=>1];}
function enforce_active_depot():void{}
function forbidden(string $message=''):void{throw new RuntimeException('denied');}
function order_check(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function order_test_extract(string $path,string $name):void{$s=file_get_contents($path);$start=strpos($s,'function '.$name.'(');$end=strpos($s,"\n}\n",$start)+2;eval(substr($s,$start,$end-$start));}
$root=dirname(__DIR__);
order_test_extract($root.'/config/helpers.php','can_siparis');
order_test_extract($root.'/config/siparis_core.php','require_siparis');
foreach (['ogl','urun'] as $page) {
    $grants=['role.permissions.detailed',"siparis.$page.read","siparis.$page.create"];
    require_siparis('read',$page);order_check(true,'Own page allowed');
    $denied=false;try{require_siparis('read',$page==='ogl'?'urun':'ogl');}catch(RuntimeException $e){$denied=true;}order_check($denied,'Sibling page read bypass');
    order_check(role_matrix_page_can('siparis',$page,'create'),'Create grant absent');
    order_check(!role_matrix_page_can('siparis',$page,'edit'),'Create grant opened edit');
    $denied=false;try{require_siparis('delete',$page);}catch(RuntimeException $e){$denied=true;}order_check($denied,'Create grant opened delete');
}
foreach (['siparisler.php','siparis_view.php','siparis_pkl.php','siparis_invoice.php','siparis_ayarlar.php','siparis_belge.php'] as $file) {
    $src=file_get_contents($root.'/'.$file);
    if(!preg_match('/\$yaz\s*=\s*[^;]+;/',$src,$m))throw new RuntimeException('UI permission absent '.$file);
    $grants=['role.permissions.detailed','siparis.urun.read','siparis.urun.edit'];eval($m[0]);order_check(!$yaz,'Product-only grant opened OGL UI '.$file);
    $grants=['role.permissions.detailed','siparis.ogl.read','siparis.ogl.create'];eval($m[0]);order_check($yaz===($file==='siparisler.php'),'Create-only UI action mismatch '.$file);
}
$grants=['siparis.read','siparis.write'];require_siparis('read','ogl');require_siparis('read','urun');order_check(role_matrix_page_can('siparis','ogl','edit')&&role_matrix_page_can('siparis','urun','create'),'Legacy behavior changed');
$administrator=true;$grants=[];require_siparis('read','ogl');require_siparis('read','urun');order_check(true,'Admin compatibility failed');
echo "Order matrix: $checks checks passed.\n";
