<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);
require_once $root.'/config/role_pdks_matrix.php';
$permissions=[];$expand=true;$checks=0;$finalize=true;
function can(string $permission):bool{global $permissions,$expand;return in_array($permission,$expand?role_pdks_expand_permissions($permissions):$permissions,true);}
function is_admin():bool{return false;}
function pdks_hakedis_can(string $action):bool{global $finalize;return $finalize;}
function h(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function link_ok(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$tersSource=file_get_contents($root.'/config/pdks_ters.php');
foreach(['pdks_ters_yetkiler','pdks_ters_hub_hedef','pdks_ters_sekmeler']as$name){$start=strpos($tersSource,'function '.$name.'(');$end=strpos($tersSource,"\n}\n",$start)+2;if($start===false||$end<2)throw new RuntimeException($name);eval(substr($tersSource,$start,$end-$start));}
$hubSource=file_get_contents($root.'/ters_kart.php');$start=strpos($hubSource,'<div class="tk-hub-grid">');$end=strpos($hubSource,'</div>',$start)+6;$hubMarkup=substr($hubSource,$start,$end-$start);
$profiles=[
 ['count',['role.permissions.detailed','attendance.ters_count.read'],true,false,'ters_kart_sayim.php?cavus=7'],
 ['exit',['role.permissions.detailed','attendance.daily_scan.read'],false,true,'gunluk_isci_giris_cikis.php?mod=ortak'],
 ['pool',['role.permissions.detailed','attendance.ters_pool.read'],false,false,'ters_kart_havuz.php?cavus=7'],
 ['report',['role.permissions.detailed','attendance.ters_report.read'],false,false,'ters_kart_mutabakat.php'],
 ['legacy',['attendance.daily_scan'],true,true,'ters_kart_sayim.php?cavus=7'],
];
foreach([false,true]as$expand)foreach($profiles as[$name,$permissions,$count,$exit,$target]){
 $y=pdks_ters_yetkiler();link_ok($y['sayim']===$count&&$y['cikis']===$exit,'Independent target flags '.$name);
 link_ok($y['tarama']===($count||$exit),'Legacy endpoint OR gate changed '.$name);
 link_ok(pdks_ters_hub_hedef($y,7)===$target,'Foreman card target denied '.$name);
 ob_start();pdks_ters_sekmeler('hub',$y);$tabs=ob_get_clean();
 ob_start();eval('?>'.$hubMarkup);$hub=ob_get_clean();
 foreach(['tabs'=>$tabs,'hub'=>$hub]as$surface=>$markup){
  link_ok(str_contains($markup,'href="ters_kart_sayim.php"')===$count,'Count visibility '.$surface.' '.$name);
  link_ok(str_contains($markup,'href="gunluk_isci_giris_cikis.php?mod=ortak"')===$exit,'Exit visibility '.$surface.' '.$name);
 }
}
link_ok(str_contains($hubSource,"pdks_ters_hub_hedef(\$y, (int)\$c['foreman_id'])"),'Hub foreman markup bypasses target helper');
$expand=true;$faz8bHazir=true;$sess=['id'=>1];$hakedis=['session_id'=>1];
foreach(['cavus_hakedis.php','cavus_hakedis_detay.php']as$file){
 $source=file_get_contents($root.'/'.$file);$lines=array_filter(explode("\n",$source),fn($line)=>str_contains($line,'<a')&&str_contains($line,'href="mesai_degerlendirme.php'));
 link_ok(count($lines)>0,'Mesai link fixture absent '.$file);
 foreach([false,true]as$allowed){$permissions=['role.permissions.detailed','attendance.entitlements.read','attendance.entitlements.edit','attendance.foreman_rates.read'];if($allowed)$permissions[]='attendance.mesai.read';foreach($lines as$line){ob_start();eval('?>'.$line);$markup=ob_get_clean();link_ok(str_contains($markup,'href="mesai_degerlendirme.php')===$allowed,'Mesai detailed target visibility '.$file);}}
 $permissions=['attendance.entitlements','attendance.foreman_rates'];foreach($lines as$line){ob_start();eval('?>'.$line);$markup=ob_get_clean();link_ok(str_contains($markup,'href="mesai_degerlendirme.php'),'Legacy mesai link lost '.$file);}
}
foreach(['gunluk_isci_puantaj.php','gunluk_isci_puantaj_detay.php']as$file){
 $source=file_get_contents($root.'/'.$file);if(!preg_match('/\$manuelCikisYetkisi\s*=[^;]+;/',$source,$match))throw new RuntimeException($file);
 foreach([false,true]as$allowed){$permissions=['role.permissions.detailed','attendance.entitlements.read','attendance.foreman_rates.read'];if($allowed)$permissions[]='attendance.manual_exit.read';eval($match[0]);link_ok($manuelCikisYetkisi===$allowed,'Manual exit target visibility '.$file);}
 $permissions=['attendance.entitlements','attendance.foreman_rates'];eval($match[0]);link_ok($manuelCikisYetkisi,'Legacy manual exit link lost '.$file);
}
echo "PDKS contextual links: $checks checks passed.\n";
