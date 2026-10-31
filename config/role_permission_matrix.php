<?php
declare(strict_types=1);
require_once __DIR__ . '/role_pdks_matrix.php';

/** Pure editor schema. Unsupported operations are absent, never implied. */
function role_permission_matrix(): array {
    $a = static fn(string $p, string $label = '') => ['permission' => $p, 'label' => $label];
    $row = static function(string $key, string $label, string $route, array $actions, array $children = [], string $note = '', bool $admin = false): array {
        return ['key'=>$key,'label'=>$label,'route'=>$route,'actions'=>$actions,'children'=>$children,'note'=>$note,'admin_only'=>$admin];
    };
    $crud = static fn(string $p) => ['read'=>$a("$p.read"),'create'=>$a("$p.create"),'edit'=>$a("$p.edit"),'delete'=>$a("$p.delete")];
    $reports = [];
    foreach (role_report_types() as $key => $label) $reports[] = $row('reports_'.$key, $label, 'reports.php?type='.$key, ['read'=>$a("reports.$key.read"),'export'=>$a("reports.$key.export",'Dışa aktar')]);
    $beyan = [];
    foreach (['contact'=>'İletişim ve adres','analysis'=>'Analiz ve numune','hks'=>'HKS eşleştirme','source'=>'Ham kaynak metni'] as $key=>$label) $beyan[]=$row('beyan_'.$key,$label,'beyanlar.php',['read'=>$a("beyan.$key.read"),'edit'=>$a("beyan.$key.write")]);
    $beyanActions = $crud('beyan');
    foreach (['status'=>'Durum değiştir','match'=>'Yüklemeyle eşleştir','notify'=>'Bildirim yönet'] as $key=>$label) $beyanActions[$key]=$a('beyan.'.$key,$label);
    $pdks=[];
    foreach (role_pdks_pages() as $key=>$policy) {
        if ($key === 'hub') continue;
        $actions=[];
        foreach ($policy['actions'] as $action) $actions[$action]=$a('attendance.'.$key.'.'.$action);
        $pdks[]=$row('attendance_'.$key,$policy['label'],$policy['route'],$actions,[],$policy['note'] ?? '');
    }
    $pdks[]=$row('attendance_fis','Fiş Excel Yükleme','gunluk_fis_yukle.php',[],[],'Yalnız sistem yöneticisi.',true);
    $groups = [
        'Operasyon'=>[
            $row('dashboard','Ana Sayfa','index.php',['read'=>$a('dashboard.read')]),
            $row('siparis','OGL Siparişler','siparisler.php',$crud('siparis.ogl')+['match'=>$a('siparis.match','Beyan eşleştir')]),
            $row('urun_siparis','Siparişler','urun_siparis.php',$crud('siparis.urun')),
            $row('beyan','Beyanlar','beyanlar.php',$beyanActions,$beyan),
            $row('hks','Hal Bildirimi','halkayit/index.php',['read'=>$a('hks.access','Bildirim işlemleri')],[],'Hal bildirimini açar ve mevcut bildirim işlemlerini yönetir.'),
            $row('mail','Mail Merkezi','mail.php',['read'=>$a('mail.read'),'reply'=>$a('mail.reply','Cevap taslağı'),'send'=>$a('mail.send','Gönder'),'admin'=>$a('mail.admin','Hesap yönetimi')]),
            $row('records','Yükleme Planları','records.php',$crud('records.loading')+['lock'=>$a('records.lock','Kilitle'),'unlock'=>$a('records.unlock','Kilit aç'),'templates'=>$a('records.templates','Malzeme şablonlarını oluştur, düzenle ve sil')]),
            $row('cikmalar','Paletleme','cikmalar.php',$crud('records.pallet')),
            $row('kantar','Mal Kabul','kantar.php',$crud('kantar')),
            $row('reports','Raporlar','reports.php',['read'=>$a('reports.read'),'export'=>$a('reports.export','Dışa aktar')],$reports),
            $row('quick_cost','Hızlı Maliyet','hizli_maliyet.php',['read'=>$a('hizli_maliyet.read'),'create'=>$a('hizli_maliyet.write','Kaydet / kopyala'),'delete'=>$a('hizli_maliyet.delete')],[],'Eski Maliyet izinleri bunu açmaz. Kayıt tablosunun kurulumu yalnız sistem yöneticisine açıktır.'),
            $row('stok','Malzeme Stok','malzeme_stok.php',['read'=>$a('stok.read'),'create'=>$a('stok.create')],[],'Hareket düzenleme, silme ve taşıma yalnız sistem yöneticisine açıktır.'),
            $row('hesap','Hesap','hesap.php',$crud('hesap')+['approve'=>$a('hesap.approve','Onayla'),'pay'=>$a('hesap.pay','Ödendi işaretle'),'admin'=>$a('hesap.admin','Tüm personelin hesaplarını yönet')],[],'Normal erişim yalnız kendi kayıtlarıdır. Tüm personel için hesap yönetimi gerekir.'),
            $row('attendance','Personel Takibi','personel_takip.php',['read'=>$a('attendance.hub.read')],$pdks,'Gör, genel ekranı açar. Alt sayfa izinleri ayrı seçilir; alt sayfayı gören genel ekrana da ulaşabilir.'),
        ],
        'Yönetim'=>[
            $row('defs','Tanımlar','definitions.php',['read'=>$a('defs.read'),'create'=>$a('defs.create'),'edit'=>$a('defs.edit'),'delete'=>$a('defs.admin')],[],'Silme ve gelişmiş yönetim aynı mevcut izin paketidir.'),
            $row('users','Kullanıcılar','users.php',['read'=>$a('users.admin','Kullanıcı ve rol yönetimi')],[],'Yönetim işlemleri mevcut tek güvenlik paketiyle verilir.'),
            $row('roles','Roller','roles.php',['admin'=>$a('users.admin','Kullanıcı ve rol yönetimi')]),
            $row('audit','İşlem Geçmişi','audit.php',[],[],'Yalnız sistem yöneticisi.',true),
            $row('backup','Veritabanı Yedekleri','admin_db_backups.php',[],[],'Yalnız sistem yöneticisi.',true),
        ],
        'Raporlar altındaki diğer sayfalar'=>[
            $row('maliyet','Detaylı Maliyet','maliyet.php',$crud('maliyet')+['unlock'=>$a('maliyet.unlock','Kilit aç'),'admin'=>$a('maliyet.admin','Alan ve şablon yönetimi')]),
        ],
    ];
    if (function_exists('nav_muhasebe_gruplari')) foreach (nav_muhasebe_gruplari() as $key=>[$label,$icon,$pages]) {
        $children=[];
        foreach ($pages as $route=>[$title,$emoji]) $children[]=$row(pathinfo($route,PATHINFO_FILENAME),$title,$route,[],[],'Hazırlanan sayfa; yalnız sistem yöneticisi. İşlem yetkisi bulunmuyor.',true);
        $groups[$label.' · Menü']=$children;
    }
    return $groups;
}

function role_report_types(): array {
    return ['yukleme'=>'Yükleme Raporları','cikma'=>'Paletleme Raporları','depo'=>'Depo Raporları','urun'=>'Ürün Raporları','firma'=>'Firma Raporları','malzeme'=>'Malzeme Raporları','kantar'=>'Mal Kabul Raporları','gunluk'=>'Günlük Rapor'];
}

/** Must run per role before roles are unioned. One detailed role cannot narrow another. */
function role_matrix_expand_permissions(array $permissions): array {
    $original = $permissions;
    $permissions = role_pdks_expand_permissions($permissions);
    $detailed=in_array('role.permissions.detailed',$permissions,true);
    if (!$detailed && in_array('records.write',$original,true)) $permissions[]='hks.access';
    if (!$detailed && in_array('records.write',$permissions,true)) $permissions[]='records.templates';
    foreach (['records','kantar','stok','maliyet','hesap','defs','siparis'] as $module) {
        if (!$detailed && in_array("$module.write",$original,true)) array_push($permissions,"$module.create","$module.edit");
        if (($detailed || in_array("$module.write",$original,true)) && (in_array("$module.create",$permissions,true) || in_array("$module.edit",$permissions,true))) $permissions[]="$module.write";
    }
    if (!$detailed) foreach (array_keys(role_report_types()) as $type) {
        if (in_array('reports.read',$original,true)) $permissions[]="reports.$type.read";
        if (in_array('reports.export',$original,true)) $permissions[]="reports.$type.export";
    }
    foreach (['records'=>['loading','pallet'],'siparis'=>['ogl','urun']] as $module=>$pages) {
        foreach (['read','create','edit','delete'] as $action) {
            if (!$detailed && (in_array("$module.$action",$original,true) || (in_array($action,['create','edit'],true) && in_array("$module.write",$original,true)))) foreach ($pages as $page) $permissions[]="$module.$page.$action";
            foreach ($pages as $page) if (($detailed || in_array("$module.$action",$original,true) || in_array("$module.write",$original,true)) && in_array("$module.$page.$action",$permissions,true)) {
                $permissions[]="$module.$action";
                if (in_array($action,['create','edit'],true)) $permissions[]="$module.write";
            }
        }
    }
    return array_values(array_unique($permissions));
}

function role_matrix_catalog_additions(): array {
    $out=['role.permissions.detailed'=>'Sayfa ve işlem matrisi ile ayrıntılı yetkilendirme','hks.access'=>'Hal Bildirimi erişimi ve işlemleri','records.templates'=>'Malzeme şablonlarını yönet'];
    foreach (['records','kantar','stok','maliyet','hesap','defs','siparis'] as $module) {
        $out["$module.create"]='Yeni kayıt ekle'; $out["$module.edit"]='Mevcut kaydı düzenle';
    }
    foreach (['records'=>['loading','pallet'],'siparis'=>['ogl','urun']] as $module=>$pages) foreach ($pages as $page) foreach (['read','create','edit','delete'] as $action) $out["$module.$page.$action"] = "$page $action";
    foreach (role_report_types() as $type=>$label) { $out["reports.$type.read"]=$label.' görüntüle'; $out["reports.$type.export"]=$label.' dışa aktar'; }
    return $out + role_pdks_catalog();
}

/** Additional CRUD check; caller must retain its existing module/read gate. */
function role_matrix_can_action(string $module, string $action): bool {
    if (in_array($module, ['siparis','hesap','maliyet'], true) && function_exists('is_admin') && is_admin()) return true;
    return function_exists('can') && can($module.'.'.$action);
}

function role_matrix_page_can(string $module, string $page, string $action = 'read'): bool {
    if (!function_exists('can')) return false;
    if ($module === 'siparis' && function_exists('is_admin') && is_admin()) return true;
    return can("$module.$page.$action") || (!can('role.permissions.detailed') && can("$module.$action"));
}
