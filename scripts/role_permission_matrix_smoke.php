<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config/role_permission_matrix.php';
$checks=0;
function matrix_ok(bool $ok,string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
$legacy=role_matrix_expand_permissions(['records.read','records.write','records.delete','reports.read','reports.export','siparis.read','siparis.write']);
foreach (['records.loading.read','records.loading.create','records.pallet.edit','hks.access','siparis.ogl.create','siparis.urun.edit'] as $p) matrix_ok(in_array($p,$legacy,true),'Legacy grant lost: '.$p);
foreach (array_keys(role_report_types()) as $type) foreach (['read','export'] as $action) matrix_ok(in_array("reports.$type.$action",$legacy,true),'Legacy report lost');
$limited=role_matrix_expand_permissions(['role.permissions.detailed','records.loading.read','records.loading.create','siparis.urun.read','siparis.urun.edit','reports.read','reports.yukleme.read']);
foreach (['records.read','records.create','records.write','siparis.read','siparis.edit','siparis.write'] as $p) matrix_ok(in_array($p,$limited,true),'Module bridge absent: '.$p);
foreach (['records.loading.edit','records.pallet.read','records.pallet.create','hks.access','siparis.ogl.read','siparis.urun.create','reports.cikma.read','reports.yukleme.export'] as $p) matrix_ok(!in_array($p,$limited,true),'Unassigned grant: '.$p);
$union=array_unique(array_merge($legacy,$limited));
matrix_ok(in_array('records.pallet.edit',$union,true),'Detailed role narrowed legacy role');
$hks=role_matrix_expand_permissions(['role.permissions.detailed','hks.access']);
$explicit=role_matrix_expand_permissions(['records.loading.create']);
matrix_ok(role_matrix_expand_permissions($explicit)===$explicit,'New alias expansion must be idempotent');
matrix_ok(!in_array('records.pallet.create',$explicit,true),'Alias-only grant expanded sibling');
matrix_ok(!in_array('records.write',$hks,true),'HKS gained loading compatibility writes');
matrix_ok(!in_array('records.templates',$hks,true),'HKS gained material template administration');
matrix_ok(in_array('records.templates',$legacy,true),'Legacy material templates grant lost');
matrix_ok(!in_array('records.loading.create',$hks,true),'HKS gained loading creation');
$perms=$limited;
function can(string $p): bool { global $perms; return in_array($p,$perms,true); }
function is_admin(): bool { return false; }
matrix_ok(role_matrix_page_can('records','loading'),'Loading read denied');
matrix_ok(!role_matrix_page_can('records','pallet'),'Pallet read escalated');
matrix_ok(role_matrix_page_can('siparis','urun','edit'),'Order edit denied');
matrix_ok(!role_matrix_page_can('siparis','ogl'),'OGL read escalated');
$source=file_get_contents(dirname(__DIR__).'/config/helpers.php');
$start=strpos($source,'function nav_muhasebe_gruplari(): array {');
$end=strpos($source,"\n}\n",$start)+2;
eval(substr($source,$start,$end-$start));
$schema=role_permission_matrix(); $rows=[];
$visit=function(array $row) use (&$visit,&$rows): void { $rows[]=$row; foreach ($row['children'] as $child) $visit($child); };
foreach ($schema as $group) foreach ($group as $row) $visit($row);
foreach ($rows as $row) matrix_ok(is_file(dirname(__DIR__).'/'.explode('?',$row['route'])[0]),'Missing row route '.$row['route']);
$adminRows=array_filter($rows,fn($r)=>$r['admin_only']);
// v550/v566: yalnız Şube/Depo Tanımları (2 sayfa; Admin Konsolu grubu kalktı) + Fiş Excel Yükleme + İşlem Geçmişi + Yedekler admin-only kalır; menü sayfaları Roller'den verilebilir (varsayılan kapalı).
matrix_ok(count($adminRows)===5,'Admin-only inventory changed: '.count($adminRows));
foreach ($adminRows as $row) matrix_ok($row['actions']===[],'Admin-only row invented permissions');
$catalogRoutes=[]; $adminKonsolu=['depo_tanimlari.php','whatsapp_yonetim.php']; foreach (nav_muhasebe_gruplari() as $gk=>[,,$pages]) foreach ($pages as $route=>$_) $catalogRoutes[]=$route;
matrix_ok(!isset(nav_muhasebe_gruplari()['admin_konsolu']),'Admin Konsolu group reappeared (v566)');
foreach ($adminKonsolu as $route) matrix_ok(!in_array($route,$catalogRoutes,true),'Yönetim-only page leaked into muhasebe catalog: '.$route);
matrix_ok(count($catalogRoutes)===45,'Menu catalog size changed (v593: Raporlama & BI Merkezi +1 sayfa → 45)');
foreach (['ihracat_faturasi_maliyeti.php','beyanname_siparis_proforma.php','dovizli_cari_fis.php','ihracat_tanimlari.php','stok_donusum_fisleri.php','kantar_fisleri.php'] as $goneRoute) matrix_ok(!in_array($goneRoute,$catalogRoutes,true),'Removed menu page reappeared: '.$goneRoute);
$adminRoutes=array_column($adminRows,'route');
foreach ($adminKonsolu as $route) matrix_ok(in_array($route,$adminRoutes,true),'Şube/Depo Tanımları must stay admin-only: '.$route);
$byKey=[]; foreach ($rows as $r) $byKey[$r['key']]=$r;
foreach ($catalogRoutes as $route) if (!in_array($route,$adminKonsolu,true)) {
    $slug=pathinfo($route,PATHINFO_FILENAME);
    if (in_array($slug,muhasebe_herkese_acik_sayfalar(),true)) { matrix_ok(!isset($byKey[$slug]),'v575: herkese açık sayfa Roller satırı OLMAMALI: '.$slug); continue; }   // oturumlu herkes; verilecek yetki yok
    matrix_ok(isset($byKey[$slug]) && !$byKey[$slug]['admin_only'] && ($byKey[$slug]['actions']['read']['permission'] ?? '')==="muhasebe.$slug.read",'Menu page lacks grantable read row: '.$slug);
}
foreach (['genel_uygulamalar','ihracat'] as $gone) matrix_ok(!isset(nav_muhasebe_gruplari()[$gone]),'Removed menu group reappeared: '.$gone);
foreach (['operasyon_fis','kasa','kullanici_konsolu'] as $slug) matrix_ok(isset(nav_muhasebe_gruplari()[$slug]),'Menu group missing: '.$slug);
$codes=[]; foreach ($rows as $row) foreach ($row['actions'] as $action) $codes[]=$action['permission'];
matrix_ok(in_array('records.pallet.create',$codes,true),'Pallet create editor control absent');
matrix_ok(in_array('reports.yukleme.read',$codes,true),'Report child editor control absent');
matrix_ok(in_array('beyan.yukleme_bildir',$codes,true),'Yükleme Bildir editor control absent');
matrix_ok(!in_array('beyan.yukleme_bildir',role_matrix_expand_permissions(['beyan.read','beyan.write']),true),'Legacy beyan.write expanded to yukleme_bildir');
matrix_ok(!in_array('beyan.yukleme_bildir',role_matrix_expand_permissions(['role.permissions.detailed','beyan.read']),true),'Detailed beyan.read expanded to yukleme_bildir');
echo "Role matrix: $checks checks passed.\n";
