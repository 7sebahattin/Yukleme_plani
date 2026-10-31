<?php
declare(strict_types=1);
// Anonymous pure policy checks; no bootstrap, database, process or service.
require_once __DIR__ . '/../config/role_pdks_matrix.php';
$grants=[]; $admin=false; $checks=0; $failed=0;
function can(string $permission): bool { global $grants; return in_array($permission,$grants,true); }
function is_admin(): bool { global $admin; return $admin; }
function ok(string $label,bool $pass): void { global $checks,$failed; $checks++; if(!$pass)$failed++; echo ($pass?'OK ':'FAIL ').$label."\n"; }
foreach (role_pdks_routes() as $route=>$page) {
    ok($route.' GET classified as read',role_pdks_request_action($route,'GET')===[$page,'read']);
    $grants=['role.permissions.detailed','attendance.'.$page.'.read'];
    ok($page.' read opens page',role_pdks_can($page));
    foreach (['create','edit','delete'] as $action) ok($page.' read does not grant '.$action,!role_pdks_can($page,$action));
    ok($route.' unknown POST rejected',role_pdks_request_action($route,'POST',[],['action'=>'unknown_!','form'=>'unknown_!'])===null);
}
$cases=[
 ['personel_form.php',[],['action'=>'save_employee'],[],['employees','create']],
 ['personel_form.php',['id'=>'7'],['action'=>'save_employee'],[],['employees','edit']],
 ['personel_form.php',['id'=>'7'],['action'=>'delete_employee'],[],['employees','delete']],
 ['personel_form.php',['id'=>'7'],['action'=>'kart_ata'],[],['cards','create']],
 ['personel_kartlar.php',[],['action'=>'kart_degistir'],[],['cards','edit']],
 ['cavus_form.php',[],['action'=>'save'],[],['foremen','create']],
 ['cavus_form.php',['id'=>'7'],['action'=>'save'],[],['foremen','edit']],
 ['cavus_form.php',['id'=>'7'],['action'=>'sure_kaydet'],[],['foremen','edit']],
 ['isci_kartlari.php',['ajax'=>'tanim_toplu_kaydet'],[],[],['worker_cards','create']],
 ['isci_kartlari.php',['ajax'=>'tanim_kaldir_toplu'],[],[],['worker_cards','edit']],
 ['isci_kartlari.php',[],['action'=>'kart_durum'],[],['worker_cards','edit']],
 ['isci_kartlari.php',[],['action'=>'tanimsiz_sil'],[],null],
 ['isci_tipleri.php',[],['action'=>'aktiflik'],[],['worker_types','edit']],
 ['giris_cikis.php',['ajax'=>'kaydet'],[],[],['scan','create']],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'kaydet'],[],['event_type'=>'GIRIS'],['daily_scan','create']],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'kaydet'],[],['event_type'=>'CIKIS'],['daily_scan','edit']],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'kaydet'],[],['event_type'=>[]],null],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'kaydet'],[],[],null],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'ortak_cikis'],[],[],['daily_scan','edit']],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'oturum'],[],['mode'=>'GIRIS'],['daily_scan','create']],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'oturum'],[],['mode'=>'CIKIS'],['daily_scan','read']],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'oturum'],[],['mode'=>[]],null],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'kapanis_kontrol'],[],[],['daily_scan','read']],
 ['gunluk_isci_giris_cikis.php',['ajax'=>'ortak_mesailer'],[],[],['daily_scan','read']],
 ['ters_kart_cikis.php',['ajax'=>'cikis'],[],[],['daily_scan','edit']],
 ['cavus_fiyatlari.php',[],['form'=>'cavus_ucret'],[],['foreman_rates','create']],
 ['cavus_fiyatlari.php',[],['form'=>'oran'],[],['foreman_rates','create']],
 ['cavus_fiyatlari.php',[],['form'=>'saat_duzelt'],[],['foreman_rates','edit']],
 ['cavus_hakedis.php',[],['action'=>'hesapla'],[],['entitlements','create']],
 ['cavus_hakedis_detay.php',[],['action'=>'hesapla'],[],['entitlements','edit']],
 ['cavus_hakedis_detay.php',[],['action'=>'duzeltme_ters_kayit'],[],['entitlements','delete']],
 ['cavus_odeme.php',[],['action'=>'odeme_kaydet'],[],['foreman_payments','create']],
 ['cavus_odeme.php',[],['action'=>'iptal'],[],['foreman_payments','delete']],
 ['mesai_degerlendirme.php',[],['action'=>'toplu_kaydet'],[],['mesai','edit']],
 ['manuel_cikis.php',[],[],[],['manual_exit','edit']],
 ['ters_kart_havuz.php',['ajax'=>'islem'],[],['islem'=>'cikar'],['ters_pool','delete']],
 ['ters_kart_havuz.php',['ajax'=>'islem'],[],['islem'=>'aktar'],['ters_pool','edit']],
 ['ters_kart_havuz.php',['ajax'=>'islem'],[],['islem'=>[]],null],
 ['ters_kart_sayim.php',[],['form'=>'baslat'],[],['ters_count','create']],
 ['ters_kart_sayim.php',['ajax'=>'onayla'],[],[],['ters_count','edit']],
 ['ters_kart_sayim.php',['ajax'=>'geri_al'],[],[],['ters_count','delete']],
 ['ters_kart_sayim.php',['ajax'=>'onizle'],[],[],['ters_count','read']],
 ['ters_kart_sayim.php',['ajax'=>'manuel_onizle'],[],[],['ters_count','read']],
 ['gunluk_isci_puantaj.php',[],['action'=>'puantaj_ekle'],[],null],
 ['gunluk_isci_puantaj_detay.php',['ajax'=>'toplu_duzelt'],[],[],null],
 ['personel_form.php',[],['action'=>[]],[],null],
 ['cavus_form.php',[],['action'=>[]],[],null],
];
foreach($cases as [$route,$query,$post,$json,$expected]) ok('explicit '.$route.' '.json_encode([$query,$post,$json]),role_pdks_request_action($route,'POST',$query,$post,$json)===$expected);
foreach(role_pdks_pages() as $page=>$policy) {
    foreach($policy['legacy'] as $legacy) {
        $old=role_pdks_expand_permissions(['attendance.'.$legacy]);
        foreach($policy['actions'] as $action) ok('legacy '.$legacy.' expands '.$page.'.'.$action,in_array('attendance.'.$page.'.'.$action,$old,true));
    }
    $readOnly=role_pdks_expand_permissions(['role.permissions.detailed','attendance.'.$page.'.read']);
    foreach(['create','edit','delete'] as $action) ok('detailed '.$page.' does not expand '.$action,!in_array('attendance.'.$page.'.'.$action,$readOnly,true));
}
$grants=role_pdks_expand_permissions(['role.permissions.detailed','attendance.foremen.read']);
ok('child read opens generic hub',role_pdks_can('hub'));
ok('foremen read cannot edit',!role_pdks_can('foremen','edit'));
$grants=role_pdks_expand_permissions(['role.permissions.detailed','attendance.hub.read']);
ok('hub only opens hub',role_pdks_can('hub'));
ok('hub does not grant employee access',!role_pdks_can('employees'));
ok('hub does not synthesize legacy admin',!in_array('attendance.admin',$grants,true));
$grants=role_pdks_expand_permissions(['role.permissions.detailed','attendance.foremen.create']);
ok('create still needs page read',!role_pdks_can('foremen','create'));
$grants=['role.permissions.detailed','attendance.foremen'];
ok('raw old package cannot bypass detailed read',!role_pdks_can('foremen'));
ok('unknown route unsupported',role_pdks_request_action('not-a-route.php','POST')===null);
$explicit=role_pdks_expand_permissions(['attendance.worker_types.read']);
ok('alias-only expansion idempotent',role_pdks_expand_permissions($explicit)===$explicit);
ok('alias-only cannot grant sibling pool edit',!in_array('attendance.ters_pool.edit',$explicit,true));
$admin=true;
ok('system admin exception retained',role_pdks_can('foremen','edit'));
echo json_encode(['checks'=>$checks,'failed'=>$failed])."\n";
exit($failed?1:0);
