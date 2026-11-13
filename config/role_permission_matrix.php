<?php
declare(strict_types=1);
require_once __DIR__ . '/role_pdks_matrix.php';
require_once __DIR__ . '/role_content.php';   // v507: sayfa içi kart/düğme (içerik) yetkileri

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
    // v504 — kart (bölüm) yetkileri. Kartta işlem için sayfanın Gör izni de gerekir; Ekle sayfa düzeyindedir.
    $beyanKartlar = [
        $row('beyan_bek','İşlem Bekleyen Beyanlar','beyanlar.php',['read'=>$a('beyan.bek.read'),'edit'=>$a('beyan.bek.edit'),'delete'=>$a('beyan.bek.delete')],[],'Ekle: sayfa satırındaki “+ Yeni Beyan”. Düzenle durum değiştirmeyi de kapsar.'),
        $row('beyan_kap','Kapanan Beyanlar','beyanlar.php',['read'=>$a('beyan.kap.read'),'edit'=>$a('beyan.kap.edit'),'delete'=>$a('beyan.kap.delete')],[],'Yüklendi, iptal ve red durumundaki beyanlar.'),
        $row('beyan_bsz','Beyansız Yüklemeler','beyanlar.php?sekme=beyansiz',['read'=>$a('beyan.bsz.read'),'create'=>$a('beyan.yukleme_bildir','Yükleme Bildir')],[
            $row('beyan_bsz_bek','Bildirim Bekleyenler','beyanlar.php?sekme=beyansiz',['read'=>$a('beyan.bsz_bek.read'),'delete'=>$a('beyan.bsz_bek.delete')],[],'Ekle: Beyansız Yüklemeler satırındaki “Yükleme Bildir”. Gönderilmiş kayıt silinemez.'),
            $row('beyan_bsz_yap','Bildirim Yapılanlar','beyanlar.php?sekme=beyansiz',['read'=>$a('beyan.bsz_yap.read'),'delete'=>$a('beyan.bsz_yap.delete')],[],'Yalnız taslağı silinmiş kayıt silinebilir; gönderilmiş ve durumu belirsiz kayıt silinemez.'),
        ],'Sekmeyi açar. Ekle = Yükleme Bildir düğmesi.'),
    ];
    $beyanActions = ['read'=>$a('beyan.read'),'create'=>$a('beyan.create','Yeni Beyan')];
    foreach (['status'=>'Durum değiştir','match'=>'Yüklemeyle eşleştir','notify'=>'Bildirim yönet'] as $key=>$label) $beyanActions[$key]=$a('beyan.'.$key,$label);
    $beyan = array_merge($beyanKartlar, $beyan);
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
            $row('siparis','OGL Siparişler','siparisler.php',['read'=>$a('siparis.ogl.read'),'match'=>$a('siparis.match','Beyan eşleştir')],[
                $row('siparis_acik','İşlem Bekleyen Sevkiyatlar','siparisler.php?kart=acik',$crud('siparis.ogl_acik'),[],'Ekle: yeni sevkiyat, belge, kalem ve mailden alma.'),
                $row('siparis_evrak','Evrak Takımı Bekleyen Sevkiyatlar','siparisler.php?kart=evrak',$crud('siparis.ogl_evrak')),
                $row('siparis_kapali','Kapanan Sevkiyatlar','siparisler.php?kart=kapali',$crud('siparis.ogl_kapali')),
            ],'Sayfayı açar. İşlem için ilgili kartta da izin verilmelidir; kart izni sevkiyatın bulunduğu bölüme göre uygulanır.'),
            $row('frugro','Frugro Siparişler','frugro_siparis.php',$crud('siparis.frugro'),[],'OGL’den bağımsız sayfa. Mailden alma için Mail Merkezi okuma, ayarlar için Sil yetkisi de gerekir; beyan bağı OGL satırındaki “Beyan eşleştir” iznini kullanır.'),
            $row('urun_siparis','Siparişler','urun_siparis.php',$crud('siparis.urun')),
            $row('beyan','Beyanlar','beyanlar.php',$beyanActions,$beyan),
            $row('hks','Hal Bildirimi','halkayit/index.php',$crud('hks'),[],'Ekle: taslak hazırlama, kopyalama ve HKS’ye bildirim gönderme (geri alınamaz). Düzenle: taslak ve kişi düzenleme. Sil: taslak ve kişi silme. Firma tanımları yalnız sistem yöneticisidir.'),
            $row('mail','Mail Merkezi','mail.php',['read'=>$a('mail.read'),'reply'=>$a('mail.reply','Cevap taslağı'),'send'=>$a('mail.send','Gönder'),'admin'=>$a('mail.admin','Hesap yönetimi')]),
            $row('records','Yükleme Planları','records.php',$crud('records.loading')+['lock'=>$a('records.lock','Kilitle'),'unlock'=>$a('records.unlock','Kilit aç'),'templates'=>$a('records.templates','Malzeme şablonlarını oluştur, düzenle ve sil')]),
            $row('cikmalar','Paletleme','cikmalar.php',$crud('records.pallet')),
            $row('kantar','Mal Kabul','kantar.php',$crud('kantar')),
            $row('reports','Raporlar','reports.php',['read'=>$a('reports.read'),'export'=>$a('reports.export','Dışa aktar')],$reports),
            $row('quick_cost','Hızlı Maliyet','hizli_maliyet.php',['read'=>$a('hizli_maliyet.read'),'create'=>$a('hizli_maliyet.write','Kaydet / kopyala'),'delete'=>$a('hizli_maliyet.delete')],[],'Eski Maliyet izinleri bunu açmaz. Kayıt tablosunun kurulumu yalnız sistem yöneticisine açıktır.'),
            $row('stok','Malzeme Stok','malzeme_stok.php',['read'=>$a('stok.read'),'create'=>$a('stok.create')],[],'Hareket düzenleme, silme ve taşıma yalnız sistem yöneticisine açıktır.'),
            $row('hesap','Hesap','hesap.php',$crud('hesap')+['approve'=>$a('hesap.approve','Onayla'),'pay'=>$a('hesap.pay','Ödendi işaretle'),'admin'=>$a('hesap.admin','Tüm personelin hesaplarını yönet')],[],'Normal erişim yalnız kendi kayıtlarıdır. Tüm personel için hesap yönetimi gerekir.'),
            $row('uretim','Günlük Üretim','gunluk_uretim.php',['read'=>$a('uretim.read'),'create'=>$a('uretim.write','Veri gir (Excel / toplu / elle)'),'delete'=>$a('uretim.delete')],[],'İşlenen mal ve işçi başı verim raporu. Sayfaya Personel Takibi sayfasından ulaşılır; Personel Takibi için de Gör izni verin. Yönetici her zaman erişir.'),
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
        $aciklama=$key==='cari'
            ? 'Çalışan modül; yalnız sistem yöneticisi (rol bazlı cari yetkileri sonraki faz).'
            : 'Hazırlanan sayfa; yalnız sistem yöneticisi. İşlem yetkisi bulunmuyor.';
        foreach ($pages as $route=>[$title,$emoji]) $children[]=$row(pathinfo($route,PATHINFO_FILENAME),$title,$route,[],[],$aciklama,true);
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
    $permissions = role_sections_derive($permissions, $original);
    $permissions = role_frugro_derive($permissions, $original);
    foreach (['records'=>['loading','pallet'],'siparis'=>role_siparis_pages()] as $module=>$pages) {
        foreach (['read','create','edit','delete'] as $action) {
            if (!$detailed && (in_array("$module.$action",$original,true) || (in_array($action,['create','edit'],true) && in_array("$module.write",$original,true)))) foreach ($pages as $page) $permissions[]="$module.$page.$action";
            foreach ($pages as $page) if (($detailed || in_array("$module.$action",$original,true) || in_array("$module.write",$original,true)) && in_array("$module.$page.$action",$permissions,true)) {
                $permissions[]="$module.$action";
                if (in_array($action,['create','edit'],true)) $permissions[]="$module.write";
            }
        }
    }
    $permissions = role_sections_fanout($permissions);
    $permissions = role_content_expand($permissions);   // v507 — SON adım: içerik öğeleri ön koşul izinlerine bakar
    return array_values(array_unique($permissions));
}

/** Sipariş modülünün sayfa anahtarları (Roller satırları: siparis.<sayfa>.read|create|edit|delete). */
function role_siparis_pages(): array { return ['ogl','urun','frugro']; }

/** Frugro Siparişler satırının kataloğa girdiği sürüm (role.catalog.N). */
const ROLE_FRUGRO_SINCE = 3;

/**
 * Frugro sayfası geri uyumluluğu (DB'ye yazmaz): Frugro satırından ÖNCE kaydedilmiş rol
 * (katalog sürümü < ROLE_FRUGRO_SINCE) Frugro izinlerini OGL sayfa izinlerinden alır —
 * eskiden Frugro OGL kapısını kullanıyordu, kimse erişim kaybetmez. Roller ekranında bir kez
 * kaydedilen rol güncel sürüm işaretleyicisini taşır ve yalnız açıkça seçilen siparis.frugro.* geçerli olur.
 * Detaylı (role.permissions.detailed) ve kart işaretleyicili roller dahil; işaretleyicisiz düz roller
 * ayrıca modül izninden (siparis.read/write/delete) sayfa döngüsüyle genişler.
 */
function role_frugro_derive(array $p, array $original): array {
    if (role_catalog_version_of($original) >= ROLE_FRUGRO_SINCE) return $p;
    foreach (['read','create','edit','delete'] as $a) if (in_array("siparis.ogl.$a", $p, true)) $p[] = "siparis.frugro.$a";
    return $p;
}

/** v504 — kart (bölüm) yetkileri. Yeni matristen kaydedilen rol `role.sections.detailed` taşır. */
function role_ogl_sections(): array { return ['ogl_acik','ogl_evrak','ogl_kapali']; }

/** Marker'lı rol: sayfa düzeyi uyumluluk anahtarları kartlardan TÜRETİLİR (kapı fonksiyonları eski anahtarları okur). */
function role_sections_derive(array $p, array $original): array {
    if (!in_array('role.sections.detailed', $original, true)) return $p;
    $any = static function(array $keys) use ($p): bool { foreach ($keys as $k) if (in_array($k, $p, true)) return true; return false; };
    foreach (['create','edit','delete'] as $a) if ($any(array_map(static fn($s) => "siparis.$s.$a", role_ogl_sections()))) $p[] = "siparis.ogl.$a";
    if ($any(['beyan.bek.edit','beyan.kap.edit'])) $p[] = 'beyan.edit';
    if ($any(['beyan.bek.delete','beyan.kap.delete'])) $p[] = 'beyan.delete';
    if ($any(['beyan.bsz_bek.delete','beyan.bsz_yap.delete'])) $p[] = 'beyan.yukleme_sil';
    if (in_array('hks.read', $p, true)) $p[] = 'hks.access';
    return $p;
}

/** Marker'sız (eski) rol: sayfa düzeyi anahtarlar kartlara/eylemlere genişler — davranış değişmez. */
function role_sections_fanout(array $p): array {
    if (in_array('role.sections.detailed', $p, true)) return $p;
    $has = static fn(string $k): bool => in_array($k, $p, true);
    foreach (['read','create','edit','delete'] as $a) if ($has("siparis.ogl.$a")) foreach (role_ogl_sections() as $s) $p[] = "siparis.$s.$a";
    foreach (['read','edit','delete'] as $a) if ($has("beyan.$a") || ($a === 'edit' && ($has('beyan.status') || $has('beyan.notify') || $has('beyan.match')))) { $p[] = "beyan.bek.$a"; $p[] = "beyan.kap.$a"; }
    if ($has('beyan.yukleme_bildir') || ($has('beyan.read') && $has('beyan.hks.read'))) array_push($p, 'beyan.bsz.read', 'beyan.bsz_bek.read', 'beyan.bsz_yap.read');
    if ($has('beyan.yukleme_sil')) array_push($p, 'beyan.bsz_bek.delete', 'beyan.bsz_yap.delete');
    if ($has('hks.access')) array_push($p, 'hks.read', 'hks.create', 'hks.edit', 'hks.delete');
    return $p;
}

function role_matrix_catalog_additions(): array {
    $out=['role.permissions.detailed'=>'Sayfa ve işlem matrisi ile ayrıntılı yetkilendirme','hks.access'=>'Hal Bildirimi erişimi (Gör izninden türetilir)','records.templates'=>'Malzeme şablonlarını yönet','role.sections.detailed'=>'Kart / bölüm bazlı yetkilendirme (OGL, Beyan, Hal Bildirimi)',
        'hks.read'=>'Hal Bildirimi görüntüle','hks.create'=>'Hal Bildirimi taslak oluştur ve bildirim gönder','hks.edit'=>'Hal Bildirimi taslak / kişi düzenle','hks.delete'=>'Hal Bildirimi taslak / kişi sil',
        'beyan.bek.read'=>'İşlem Bekleyen Beyanlar görüntüle','beyan.bek.edit'=>'İşlem Bekleyen Beyanlar düzenle','beyan.bek.delete'=>'İşlem Bekleyen Beyanlar sil',
        'beyan.kap.read'=>'Kapanan Beyanlar görüntüle','beyan.kap.edit'=>'Kapanan Beyanlar düzenle','beyan.kap.delete'=>'Kapanan Beyanlar sil',
        'beyan.bsz.read'=>'Beyansız Yüklemeler sekmesini görüntüle','beyan.bsz_bek.read'=>'Bildirim Bekleyenler görüntüle','beyan.bsz_bek.delete'=>'Bildirim Bekleyenler sil','beyan.bsz_yap.read'=>'Bildirim Yapılanlar görüntüle','beyan.bsz_yap.delete'=>'Bildirim Yapılanlar sil'];
    foreach (['ogl_acik'=>'İşlem Bekleyen Sevkiyatlar','ogl_evrak'=>'Evrak Takımı Bekleyen Sevkiyatlar','ogl_kapali'=>'Kapanan Sevkiyatlar'] as $sec=>$secLabel)
        foreach (['read'=>'görüntüle','create'=>'ekle','edit'=>'düzenle','delete'=>'sil'] as $act=>$actLabel) $out["siparis.$sec.$act"] = "$secLabel $actLabel";
    foreach (['records','kantar','stok','maliyet','hesap','defs','siparis'] as $module) {
        $out["$module.create"]='Yeni kayıt ekle'; $out["$module.edit"]='Mevcut kaydı düzenle';
    }
    foreach (['records'=>['loading','pallet'],'siparis'=>role_siparis_pages()] as $module=>$pages) foreach ($pages as $page) foreach (['read','create','edit','delete'] as $action) $out["$module.$page.$action"] = "$page $action";
    foreach (role_report_types() as $type=>$label) { $out["reports.$type.read"]=$label.' görüntüle'; $out["reports.$type.export"]=$label.' dışa aktar'; }
    return $out + role_pdks_catalog() + role_content_catalog_labels();
}

/**
 * v507 — Özet şeması: yönetici-dışı her sayfa için içerdiği TÜM izinler (sayfa + kartlar + ek işlemler + içerik).
 * Roller listesi ve Kullanıcılar ekranındaki "ne görür / ne yapar" özeti bu TEK şemadan üretilir.
 * t: r=Gör c=Ekle e=Düzenle d=Sil x=ek işlem i=içerik · s: salt görüntüleme türü mü.
 */
function role_ozet_sema(): array {
    static $sema = null;
    if ($sema !== null) return $sema;
    $sema = [];
    $kisa = ['read' => 'r', 'create' => 'c', 'edit' => 'e', 'delete' => 'd'];
    $icerik = role_content_catalog();
    foreach (role_permission_matrix() as $grup => $rows) {
        foreach ($rows as $row) {
            if (!empty($row['admin_only'])) continue;
            $perms = [];
            $topla = static function (array $r) use (&$topla, &$perms, $kisa): void {
                if (empty($r['admin_only'])) foreach ((array)$r['actions'] as $tur => $a) {
                    $perms[] = ['p' => (string)$a['permission'], 't' => $kisa[$tur] ?? 'x', 's' => $tur === 'read' || $tur === 'export'];
                }
                foreach ((array)$r['children'] as $c) $topla($c);
            };
            $topla($row);
            foreach ((array)($icerik[$row['key']] ?? []) as $ogeler) foreach ($ogeler as $k => $o) $perms[] = ['p' => (string)$k, 't' => 'i', 's' => !empty($o['salt'])];
            $sema[] = ['key' => (string)$row['key'], 'label' => (string)$row['label'], 'grup' => (string)$grup,
                'okuma' => (string)($row['actions']['read']['permission'] ?? ''), 'perms' => $perms];
        }
    }
    return $sema;
}

/** Genişletilmiş izin listesinden sayfa özeti: [key,label,grup,gor,c,e,d,acik,toplam,duzey(kapali|salt|kismi|tam)]. */
function role_ozet(array $expanded): array {
    $var = array_flip(array_filter($expanded, 'is_string'));
    $out = [];
    foreach (role_ozet_sema() as $sayfa) {
        $acik = 0; $toplam = 0; $saltDisi = false; $tur = ['c' => false, 'e' => false, 'd' => false]; $gorulen = [];
        foreach ($sayfa['perms'] as $x) {
            if (isset($gorulen[$x['p']])) continue;
            $gorulen[$x['p']] = true; $toplam++;
            if (!isset($var[$x['p']])) continue;
            $acik++;
            if (!$x['s']) $saltDisi = true;
            if (isset($tur[$x['t']])) $tur[$x['t']] = true;
        }
        $gor = $sayfa['okuma'] !== '' ? isset($var[$sayfa['okuma']]) : $acik > 0;
        $duzey = (!$gor || $acik === 0) ? 'kapali' : ($acik === $toplam ? 'tam' : ($saltDisi ? 'kismi' : 'salt'));
        $out[] = ['key' => $sayfa['key'], 'label' => $sayfa['label'], 'grup' => $sayfa['grup'], 'gor' => $gor,
            'c' => $tur['c'], 'e' => $tur['e'], 'd' => $tur['d'], 'acik' => $acik, 'toplam' => $toplam, 'duzey' => $duzey];
    }
    return $out;
}

/** Additional CRUD check; caller must retain its existing module/read gate. */
function role_matrix_can_action(string $module, string $action): bool {
    if (in_array($module, ['siparis','hesap','maliyet'], true) && function_exists('is_admin') && is_admin()) return true;
    return function_exists('can') && can($module.'.'.$action);
}

function role_matrix_page_can(string $module, string $page, string $action = 'read'): bool {
    if (!function_exists('can')) return false;
    if ($module === 'siparis' && function_exists('is_admin') && is_admin()) return true;
    // Rol başına genişletme (role_matrix_expand_permissions) eski rolleri zaten sayfa anahtarlarına çevirir;
    // birleşim düzeyinde "detailed yoksa modül izni" yedeği KALDIRILDI (başka rolün izni sayfayı açıyordu).
    return can("$module.$page.$action");
}

/** OGL sevkiyat durumu → kart anahtarı. Kapalı/evrak listeleri siparis_core.php'den (sabit yazılmaz). */
function role_ogl_section_of(?string $durum): string {
    $durum = (string)$durum;
    if ($durum === '') return 'ogl_acik';
    if (function_exists('siparis_kapali_durumlar') && in_array($durum, siparis_kapali_durumlar(), true)) return 'ogl_kapali';
    if (function_exists('siparis_evrak_bekleyen_durumlar') && in_array($durum, siparis_evrak_bekleyen_durumlar(), true)) return 'ogl_evrak';
    return 'ogl_acik';
}

/** Sevkiyatın durumuna göre: sayfa Gör + kartın ilgili eylemi. $durum boş = yeni kayıt (İşlem Bekleyen kartı). */
function role_ogl_can(?string $durum, string $action): bool {
    if (!function_exists('can')) return false;
    if (function_exists('is_admin') && is_admin()) return true;
    return can('siparis.ogl.read') && can('siparis.' . role_ogl_section_of($durum) . '.' . $action);
}

/** Üç kartın herhangi birinde eylem var mı (sayfa düzeyi genel kapılar için). */
function role_ogl_any(string $action): bool {
    if (!function_exists('can')) return false;
    if (function_exists('is_admin') && is_admin()) return true;
    if (!can('siparis.ogl.read')) return false;
    foreach (role_ogl_sections() as $s) if (can("siparis.$s.$action")) return true;
    return false;
}

/** Hal Bildirimi eylem kapısı (read|create|edit|delete). hks.access (türetilmiş) sayfa girişidir. */
function role_hks_can(string $action): bool {
    if (!function_exists('can')) return false;
    if (function_exists('is_admin') && is_admin()) return true;
    return can('hks.access') && can("hks.$action");
}
