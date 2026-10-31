<?php
declare(strict_types=1);

/** Pure PDKS editor and route policy. Existing financial/admin rules still apply. */
function role_pdks_pages(): array {
    return [
        'hub'=>['label'=>'Personel Takibi','route'=>'personel_takip.php','legacy'=>['read','admin'],'actions'=>['read']],
        'employees'=>['label'=>'Personel kartları','route'=>'personel.php','legacy'=>['employees'],'actions'=>['read','create','edit','delete']],
        'cards'=>['label'=>'Personel kart zimmetleri','route'=>'personel_kartlar.php','legacy'=>['cards'],'actions'=>['read','create','edit']],
        'scan'=>['label'=>'Personel kart tarama','route'=>'giris_cikis.php','legacy'=>['scan'],'actions'=>['read','create']],
        'foremen'=>['label'=>'Çavuşlar','route'=>'cavuslar.php','legacy'=>['foremen'],'actions'=>['read','create','edit']],
        'worker_cards'=>['label'=>'İşçi kart havuzu','route'=>'isci_kartlari.php','legacy'=>['worker_cards'],'actions'=>['read','create','edit'],'note'=>'Kart silme yalnız sistem yöneticisidir. Tanım kaldırma Düzenle kapsamındadır.'],
        'worker_types'=>['label'=>'İşçi tipleri','route'=>'isci_tipleri.php','legacy'=>['worker_cards'],'actions'=>['read','edit'],'note'=>'Mevcut tiplerin aktifliği değiştirilebilir; yeni tip ekleme ve silme yoktur.'],
        'daily_scan'=>['label'=>'Günlük işçi giriş / çıkış','route'=>'gunluk_isci_giris_cikis.php','legacy'=>['daily_scan'],'actions'=>['read','create','edit'],'note'=>'Giriş ve mesai açma Ekle; çıkış ve kapatma Düzenle kapsamındadır.'],
        'daily_reports'=>['label'=>'Günlük puantaj raporları','route'=>'gunluk_isci_puantaj.php','legacy'=>['daily_reports'],'actions'=>['read'],'note'=>'Geçmiş kayıt ekleme, düzeltme ve iptal mevcut yalnız yönetici sınırındadır.'],
        'foreman_rates'=>['label'=>'Çavuş fiyatları','route'=>'cavus_fiyatlari.php','legacy'=>['foreman_rates'],'actions'=>['read','create','edit']],
        'entitlements'=>['label'=>'Hakediş','route'=>'cavus_hakedis.php','legacy'=>['entitlements'],'actions'=>['read','create','edit','delete'],'note'=>'Kesinleştirme ayrıca Çavuş fiyatları Gör iznini gerektirir. Sil, düzeltmeyi ters kayıtla iptal eder. Yöneticiye özel yeniden açma sınırı korunur.'],
        'foreman_accounts'=>['label'=>'Çavuş cari ve ekstre','route'=>'cavus_cari.php','legacy'=>['foreman_accounts'],'actions'=>['read']],
        'foreman_payments'=>['label'=>'Çavuş ödemeleri','route'=>'cavus_odeme.php','legacy'=>['foreman_payments'],'actions'=>['read','create','delete'],'note'=>'Sil, ödeme iptalidir; kesinleşmiş hareket geçmişi korunur.'],
        'management_reports'=>['label'=>'Personel yönetim raporları','route'=>'raporlar.php','legacy'=>['management_reports'],'actions'=>['read'],'note'=>'Finansal bölümler ayrıca cari hesap görüntüleme izni gerektirir.'],
        'mesai'=>['label'=>'Mesai değerlendirme','route'=>'mesai_degerlendirme.php','legacy'=>['entitlements'],'actions'=>['read','edit'],'note'=>'Çavuş fiyatları Gör ve mevcut hakediş erişimi de gerekir.'],
        'manual_exit'=>['label'=>'Manuel çıkış','route'=>'manuel_cikis.php','legacy'=>['entitlements'],'actions'=>['read','edit'],'note'=>'Çavuş fiyatları Gör ve mevcut hakediş erişimi de gerekir.'],
        'ters_pool'=>['label'=>'Giriş tanımlı kart havuzu','route'=>'ters_kart_havuz.php','legacy'=>['worker_cards'],'actions'=>['read','create','edit','delete'],'note'=>'Sil, havuz üyeliğini bitirir veya sıfırlar; kart geçmişini silmez.'],
        'ters_count'=>['label'=>'Sabah kart sayımı','route'=>'ters_kart_sayim.php','legacy'=>['daily_scan'],'actions'=>['read','create','edit','delete'],'note'=>'Sil, sayımı geri alır. Mevcut çıkış sonrası yönetici sınırı korunur.'],
        'ters_report'=>['label'=>'Giriş tanımlı mutabakat','route'=>'ters_kart_mutabakat.php','legacy'=>['daily_scan','daily_reports'],'actions'=>['read']],
    ];
}
function role_pdks_expand_permissions(array $permissions): array {
    $original = $permissions;
    $detailed = in_array('role.permissions.detailed', $original, true);
    foreach (role_pdks_pages() as $page=>$policy) {
        if (!$detailed) foreach ($policy['legacy'] as $legacy) {
            if (in_array('attendance.'.$legacy,$original,true)) {
                foreach ($policy['actions'] as $action) $permissions[]='attendance.'.$page.'.'.$action;
                break;
            }
        }
        // Bridges expose existing navigation and retain existing secondary guards.
        if (in_array('attendance.'.$page.'.read',$permissions,true)) {
            foreach ($policy['legacy'] as $legacy) if ($legacy!=='admin' && ($detailed || in_array('attendance.'.$legacy,$original,true))) $permissions[]='attendance.'.$legacy;
        }
    }
    if (!$detailed) foreach ($original as $permission) if (is_string($permission) && str_starts_with($permission,'attendance.') && substr_count($permission,'.')===1) { $permissions[]='attendance.hub.read'; break; }
    return array_values(array_unique($permissions));
}
function role_pdks_catalog(): array {
    $out=[];
    foreach (role_pdks_pages() as $page=>$policy) foreach ($policy['actions'] as $action) $out['attendance.'.$page.'.'.$action]=$policy['label'].' · '.$action;
    return $out;
}
function role_pdks_routes(): array {
    $out=[];
    foreach ([
        'hub'=>['personel_takip.php','ters_kart.php'],
        'employees'=>['personel.php','personel_form.php','personel_foto.php'],
        'cards'=>['personel_kartlar.php','pdks_nfc_test.php'],
        'scan'=>['giris_cikis.php'],
        'foremen'=>['cavuslar.php','cavus_form.php'],
        'worker_cards'=>['isci_kartlari.php'], 'worker_types'=>['isci_tipleri.php'],
        'daily_scan'=>['gunluk_isci_giris_cikis.php','ters_kart_cikis.php'],
        'daily_reports'=>['gunluk_isci_puantaj.php','gunluk_isci_puantaj_detay.php','gunluk_puantaj_yazdir.php','gunluk_puantaj_liste_yazdir.php'],
        'foreman_rates'=>['cavus_fiyatlari.php'],
        'entitlements'=>['cavus_hakedis.php','cavus_hakedis_detay.php','cavus_hakedis_yazdir.php','cavus_hakedis_liste_yazdir.php'],
        'foreman_accounts'=>['cavus_cari.php','cavus_ekstre.php','cavus_cari_yazdir.php','cavus_ekstre_yazdir.php'],
        'foreman_payments'=>['cavus_odeme.php','cavus_odeme_yazdir.php'],
        'management_reports'=>['raporlar.php','rapor_yazdir.php','cavus_donem_raporu.php','cavus_toplu_dokum.php','cavus_toplu_dokum_detay.php','cavus_toplu_dokum_yazdir.php','cavus_toplu_dokum_detay_yazdir.php'],
        'mesai'=>['mesai_degerlendirme.php','mesai_degerlendirme_yazdir.php'],
        'manual_exit'=>['manuel_cikis.php'], 'ters_pool'=>['ters_kart_havuz.php'],
        'ters_count'=>['ters_kart_sayim.php'], 'ters_report'=>['ters_kart_mutabakat.php'],
    ] as $page=>$routes) foreach ($routes as $route) $out[$route]=$page;
    return $out;
}
function role_pdks_scalar(array $values, string $key, string $default=''): string {
    return isset($values[$key]) && is_scalar($values[$key]) ? (string)$values[$key] : $default;
}
/** null = unknown or unsupported mutation. No database access. */
function role_pdks_request_action(string $route, string $method, array $query=[], array $post=[], array $json=[]): ?array {
    $page=role_pdks_routes()[$route] ?? null;
    if ($page===null) return null;
    if (in_array($method,['GET','HEAD'],true)) return [$page,'read'];
    if ($method!=='POST') return null;
    foreach (['action','form'] as $field) if (array_key_exists($field,$post) && !is_string($post[$field])) return null;
    if (array_key_exists('ajax',$query) && !is_string($query['ajax'])) return null;
    $action=role_pdks_scalar($post,'action'); $ajax=role_pdks_scalar($query,'ajax');
    $form=role_pdks_scalar($post,'form');
    if ($route==='personel_form.php') {
        if (in_array($action,['kart_ata','kart_durum','kart_degistir'],true)) return ['cards',$action==='kart_ata'?'create':'edit'];
        return match($action ?: 'save_employee') {'save_employee'=>['employees',(int)role_pdks_scalar($query,'id',role_pdks_scalar($post,'id'))>0?'edit':'create'], 'delete_employee'=>['employees','delete'], default=>null};
    }
    if ($route==='personel_kartlar.php') return match($action) {'kart_ata'=>['cards','create'],'kart_durum','kart_degistir'=>['cards','edit'],default=>null};
    if ($route==='cavus_form.php') return match($action ?: 'save') {'save'=>['foremen',(int)role_pdks_scalar($query,'id',role_pdks_scalar($post,'id'))>0?'edit':'create'],'sure_kaydet','aktiflik'=>['foremen','edit'],default=>null};
    if ($route==='isci_kartlari.php') {
        if ($ajax==='tanim_toplu_kaydet') return ['worker_cards','create'];
        if ($ajax==='tanim_kaldir_toplu') return ['worker_cards','edit'];
        return in_array($action,['kart_duzenle','kart_durum','kart_tanim','kart_tanim_bitir'],true)?['worker_cards','edit']:null;
    }
    if ($route==='isci_tipleri.php') return $action==='aktiflik'?['worker_types','edit']:null;
    if ($route==='giris_cikis.php') return $ajax==='kaydet'?['scan','create']:null;
    if ($route==='gunluk_isci_giris_cikis.php' && $ajax==='kaydet') return match(trim(role_pdks_scalar($json,'event_type'))) {'GIRIS'=>['daily_scan','create'],'CIKIS'=>['daily_scan','edit'],default=>null};
    if ($route==='gunluk_isci_giris_cikis.php' && $ajax==='oturum') return match(trim(role_pdks_scalar($json,'mode'))) {'GIRIS'=>['daily_scan','create'],'CIKIS'=>['daily_scan','read'],default=>null};
    if ($route==='gunluk_isci_giris_cikis.php') return match($ajax) {'tanimli_giris'=>['daily_scan','create'],'ortak_cikis','kapat'=>['daily_scan','edit'],'ortak_mesailer','kapanis_kontrol'=>['daily_scan','read'],default=>null};
    if ($route==='ters_kart_cikis.php') return $ajax==='cikis'?['daily_scan','edit']:null;
    if ($route==='cavus_fiyatlari.php') return match($form) {'oran','cavus_ucret','servis_ucret',''=>['foreman_rates','create'],'cavus_yontem','saat_duzelt','baslangic_geri'=>['foreman_rates','edit'],default=>null};
    if ($route==='cavus_hakedis.php') return $action==='hesapla'?['entitlements','create']:null;
    if ($route==='cavus_hakedis_detay.php') return match($action) {'duzeltme_ekle'=>['entitlements','create'],'hesapla','finalize','yeniden_ac'=>['entitlements','edit'],'duzeltme_ters_kayit'=>['entitlements','delete'],default=>null};
    if ($route==='cavus_odeme.php') return match($action) {'odeme_kaydet'=>['foreman_payments','create'],'iptal'=>['foreman_payments','delete'],default=>null};
    if ($route==='mesai_degerlendirme.php') return in_array($action,['tekli_kaydet','toplu_kaydet'],true)?['mesai','edit']:null;
    if ($route==='manuel_cikis.php') return ($action==='' && $form==='' && $ajax==='') ? ['manual_exit','edit'] : null;
    if ($route==='ters_kart_havuz.php') {
        if ($form==='saat' && $ajax==='') return ['ters_pool','edit'];
        if ($ajax==='ekle') return ['ters_pool','create'];
        if ($ajax==='sifirla') return ['ters_pool','delete'];
        if ($ajax==='islem') return match(role_pdks_scalar($json,'islem')) {'cikar'=>['ters_pool','delete'],'aktar','kayip','bozuk','kullanim_disi','kullanima_al'=>['ters_pool','edit'],default=>null};
        return null;
    }
    if ($route==='ters_kart_sayim.php') {
        if ($form==='baslat' && $ajax==='') return ['ters_count','create'];
        return match($ajax) {'okut','karar','onayla','manuel_onayla'=>['ters_count','edit'],'geri_al'=>['ters_count','delete'],'onizle','manuel_onizle'=>['ters_count','read'],default=>null};
    }
    return null;
}
function role_pdks_can(string $page, string $action='read'): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    if (!function_exists('can') || !isset(role_pdks_pages()[$page])) return false;
    if ($page==='hub' && $action==='read') { foreach (role_pdks_pages() as $child=>$policy) if ($child!=='hub' && role_pdks_can($child,'read')) return true; }
    if (can('attendance.'.$page.'.read') && ($action==='read' || can('attendance.'.$page.'.'.$action))) return true;
    // Isolated legacy callers may not invoke per-role expansion. Production expands before union.
    if (!can('role.permissions.detailed') && in_array($action,role_pdks_pages()[$page]['actions'],true)) {
        foreach (role_pdks_pages()[$page]['legacy'] as $legacy) if (can('attendance.'.$legacy)) return true;
    }
    return false;
}
/** Additional request boundary. Existing permission, depot and admin gates remain. */
function role_pdks_require_request(): void {
    if (function_exists('is_admin') && is_admin()) return;
    $route=basename((string)parse_url($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? $_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH));
    if (!isset(role_pdks_routes()[$route])) return;
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $json=[];
    $isJson=str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')),'application/json');
    if ($method==='POST' && ($isJson || ($route==='ters_kart_havuz.php' && ($_GET['ajax'] ?? '')==='islem'))) {
        try { $decoded=json_decode((string)file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { forbidden('Geçersiz işlem isteği.'); return; }
        if (!is_array($decoded) || array_is_list($decoded)) { forbidden('Geçersiz işlem isteği.'); return; }
        $json=$decoded;
    }
    $post=$_POST;
    foreach (['action','form','id'] as $field) if (!array_key_exists($field,$post) && array_key_exists($field,$json)) $post[$field]=$json[$field];
    $request=role_pdks_request_action($route,$method,$_GET,$post,$json);
    $page=role_pdks_routes()[$route];
    if (!$request || !role_pdks_can($page,'read') || !role_pdks_can($request[0],$request[1])) {
        forbidden('Bu sayfa veya işlem için yetkiniz yok.');
    }
}
