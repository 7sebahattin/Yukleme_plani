<?php
// CLI: gerçek sidebar + dashboard sunum kodu, anonim yetki profilleri.
// Bootstrap, helpers migration IIFE, DB ve dashboard sayaç/yedek prefix'i çalışmaz.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Yalnız CLI.'); }
error_reporting(E_ALL);
ini_set('display_errors', '1');

$dashboardRoot = dirname(__DIR__);
require_once $dashboardRoot . '/config/role_permission_matrix.php';
$dashboardHelperSource = file_get_contents($dashboardRoot . '/config/helpers.php');
$dashboardIndexSource = file_get_contents($dashboardRoot . '/index.php');

/** Yalnız adlandırılmış gerçek fonksiyon gövdesi; üst seviye PHP yürütülmez. */
function dashboard_extract_function(string $source, string $name): string
{
    $tokens = token_get_all($source);
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
        $next = $i + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $next++;
        if (!isset($tokens[$next]) || !is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING || $tokens[$next][1] !== $name) continue;
        $body = ''; $depth = 0; $opened = false;
        for ($j = $i; isset($tokens[$j]); $j++) {
            $part = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            $body .= $part;
            if ($part === '{') { $opened = true; $depth++; }
            elseif ($part === '}') {
                $depth--;
                if ($opened && $depth === 0) return $body;
            }
        }
        throw new RuntimeException('Fonksiyon gövdesi kapanmadı: ' . $name);
    }
    throw new RuntimeException('Fonksiyon bulunamadı: ' . $name);
}

$dashboardProfile = ['name' => 'anonymous', 'admin' => false, 'permissions' => []];
function can(string $permission): bool { return in_array($permission, role_matrix_expand_permissions($GLOBALS['dashboardProfile']['permissions']), true); }
function is_admin(): bool { return $GLOBALS['dashboardProfile']['admin']; }
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function current_user(): ?array { return ['id' => 1, 'username' => 'anonymous', 'display_name' => 'ANONİM TEST']; }
function user_primary_role(): ?array { return ['label' => $GLOBALS['dashboardProfile']['name']]; }
function active_depot(): ?string { return 'ANONİM TEST DEPO'; }
function base_url(): string { return ''; }
function nav_aktif_anahtar(): ?string { return 'home'; }
function nav_mail_okunmamis(bool $reset = false): int { return 0; }
function nav_rozet_html(int $n, bool $readable = true, string $extra = '', bool $hidden = false): string { return ''; }
function render_flash(): void {}

if (!preg_match("/define\('APP_SURUM',\s*'(v\d+)'\)/", $dashboardHelperSource, $version)) throw new RuntimeException('Sürüm bulunamadı.');
define('APP_SURUM', $version[1]);
foreach (['can_mail', 'can_siparis', 'can_hizli_maliyet', 'can_yukleme_bildir', 'beyan_sayfa_erisimi', 'nav_ptak_gorunur', 'nav_muhasebe_gruplari', 'nav_eski_esleri', 'nav_alt_sayfalar', 'nav_alt_ikon_url', 'nav_menu_modeli', 'nav_grup_ikon_svg', 'render_desktop_sidebar'] as $functionName) {
    $functionSource = dashboard_extract_function($dashboardHelperSource, $functionName);
    eval(str_replace('__DIR__', var_export($dashboardRoot . '/config', true), $functionSource));
}
// Sunum sınırı DB/backup/oturum işlemlerinden SONRADIR; aşağıda DB stub'ı bile yok.
$dashboardStart = strpos($dashboardIndexSource, '// Bölüm görünürlükleri');
if ($dashboardStart === false) throw new RuntimeException('Dashboard sunum sınırı bulunamadı.');
$dashboardPresentation = substr($dashboardIndexSource, $dashboardStart);
foreach (token_get_all('<?php ' . $dashboardPresentation) as $token) {
    if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
        throw new RuntimeException('Dashboard sunum sınırına include eklenmiş; izolasyon yeniden incelenmeli.');
    }
}
if (str_contains($dashboardPresentation, 'db()') || str_contains($dashboardPresentation, 'create_daily_backup_if_needed')) throw new RuntimeException('DB/backup sunum sınırının dışında olmalı.');
$dashboardPresentation = str_replace('__DIR__', var_export($dashboardRoot, true), $dashboardPresentation);

function render_header(string $title): void
{
    $path = str_replace('\\', '/', $GLOBALS['dashboardRoot']) . '/';
    $uri = preg_match('/^[A-Za-z]:\//', $path) ? 'file:///' . $path : 'file://' . $path;
    echo '<!doctype html><html lang="tr" data-theme="light"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="' . h($uri) . '"><link rel="stylesheet" href="assets/style.css"><title>' . h($title) . '</title></head><body class="bn-yok">';
    render_desktop_sidebar('');
    echo '<main class="container">';
}
function render_footer(): void { echo '</main></body></html>'; }

function dashboard_profile(string $name, bool $admin, array $permissions): array
{
    return ['name' => $name, 'admin' => $admin, 'permissions' => array_values(array_unique(array_merge(['dashboard.read'], $permissions)))];
}
$dashboardAllPermissions = ['records.read', 'records.write', 'beyan.read', 'kantar.read', 'reports.read', 'stok.read', 'hesap.read', 'defs.read', 'users.admin', 'siparis.read', 'mail.read', 'attendance.daily_scan'];
$dashboardProfiles = [
    'admin' => dashboard_profile('admin', true, $dashboardAllPermissions),
    'admin_minimal' => dashboard_profile('admin_minimal', true, []),
    'operator' => dashboard_profile('operator', false, ['records.read', 'records.write', 'beyan.read', 'kantar.read', 'reports.read', 'stok.read', 'hesap.read', 'defs.read', 'siparis.read', 'attendance.daily_scan']),
    'viewer' => dashboard_profile('viewer', false, ['records.read', 'beyan.read', 'kantar.read', 'reports.read', 'stok.read', 'hesap.read', 'defs.read', 'siparis.read']),
    'accountant' => dashboard_profile('accountant', false, ['records.read', 'reports.read', 'stok.read', 'hesap.read', 'siparis.read', 'attendance.foreman_accounts']),
    'dashboard_only' => dashboard_profile('dashboard_only', false, []),
];
$dashboardProfiles['loading_only']=dashboard_profile('loading_only',false,['role.permissions.detailed','records.loading.read']);
$dashboardProfiles['pallet_only']=dashboard_profile('pallet_only',false,['role.permissions.detailed','records.pallet.read']);
$dashboardProfiles['product_orders_only']=dashboard_profile('product_orders_only',false,['role.permissions.detailed','siparis.urun.read']);
$dashboardProfiles['ogl_only']=dashboard_profile('ogl_only',false,['role.permissions.detailed','siparis.ogl.read']);
// Frugro Siparişler KENDİ sayfa izni: güncel sürümde kaydedilmiş rol (Frugro kapalı / yalnız Frugro). İşaretleyicisiz ogl_only eski roldür → Frugro'yu OGL'den alır.
$dashboardProfiles['ogl_only_v3']=dashboard_profile('ogl_only_v3',false,['role.permissions.detailed','role.catalog.'.ROLE_CATALOG_VERSION,'siparis.ogl.read']);
$dashboardProfiles['frugro_only']=dashboard_profile('frugro_only',false,['role.permissions.detailed','role.catalog.'.ROLE_CATALOG_VERSION,'siparis.frugro.read']);
// Eski maliyet.* izinleriyle operator/muhasebe benzeri profil: Hızlı Maliyet'i görmemeli.
$dashboardProfiles['legacy_maliyet'] = dashboard_profile('legacy_maliyet', false, ['records.read', 'maliyet.read', 'maliyet.write', 'maliyet.delete']);
$dashboardProfiles['hizli_rw'] = dashboard_profile('hizli_rw', false, ['hizli_maliyet.read', 'hizli_maliyet.write']);
// v555: eski Yükleme Planı/Paletleme/Mal Kabul girişleri YALNIZ yeni karşılığı (muhasebe fiş sayfası) kullanıcıya görünüyorsa menüden kalkar.
// yeni_fis_hepsi = eski izinleri + 3 yeni fiş sayfası → eski giriş YOK, yeni var; yeni_fis_yalniz_yukleme = yalnız Araç Yükleme Fişleri;
// eski_yalniz = eski izinleri var, yeni karşılığı YOK (operatör benzeri) → eski giriş KORUNUR.
$dashboardProfiles['yeni_fis_hepsi'] = dashboard_profile('yeni_fis_hepsi', false, ['records.read', 'kantar.read', 'muhasebe.arac_yukleme_fisleri.read', 'muhasebe.palet_fisleri.read', 'muhasebe.mal_kabul_fisleri.read']);
$dashboardProfiles['yeni_fis_yalniz_yukleme'] = dashboard_profile('yeni_fis_yalniz_yukleme', false, ['records.read', 'kantar.read', 'muhasebe.arac_yukleme_fisleri.read']);
$dashboardProfiles['eski_yalniz'] = dashboard_profile('eski_yalniz', false, ['records.read', 'kantar.read']);
$dashboardProfiles['yeni_fis_izinsiz_eski'] = dashboard_profile('yeni_fis_izinsiz_eski', false, ['muhasebe.arac_yukleme_fisleri.read', 'muhasebe.palet_fisleri.read', 'muhasebe.mal_kabul_fisleri.read']);   // eski izni yok: yalnız yeni giriş
$dashboardSinglePermissions = array_merge($dashboardAllPermissions, ['mail.admin', 'maliyet.read', 'maliyet.write', 'hizli_maliyet.read', 'hizli_maliyet.write', 'hizli_maliyet.delete', 'attendance.foremen', 'attendance.worker_cards', 'attendance.daily_reports', 'attendance.foreman_rates', 'attendance.entitlements', 'attendance.foreman_accounts', 'attendance.foreman_payments', 'attendance.management_reports', 'attendance.employees', 'attendance.cards', 'attendance.scan']);
foreach ($dashboardSinglePermissions as $permission) {
    $key = 'only_' . str_replace('.', '_', $permission);
    $dashboardProfiles[$key] = dashboard_profile($key, false, [$permission]);
}

function dashboard_render(array $profile, bool $withBadges = false): string
{
    $GLOBALS['dashboardProfile'] = $profile;
    $db_backup_result = null; $db_backup_eski = null;
    $stats = ['toplam_kayit' => 0, 'toplam_cikma' => 0, 'tanim_sayisi' => 0];
    $hks_taslak = 0; $beyan_bildirim_bekleyen = 0; $personel_eksik_cikis = 0;
    $hesap_bugun = 0.0; $hesap_bekleyen = 0; $hesap_onay_bekleyen = 0;
    $hesap_yonetici = is_admin() || can('hesap.admin');
    $mail_okunmamis = 0; $mail_cevapsiz24 = 0; $kullanici_aktif = 0; $audit_son24h = 0;
    if ($withBadges) {
        $stats = ['toplam_kayit' => 11, 'toplam_cikma' => 12, 'tanim_sayisi' => 13];
        $hks_taslak = 15; $beyan_bildirim_bekleyen = 14; $personel_eksik_cikis = 20;
        $hesap_bugun = 17.25; $hesap_bekleyen = 18; $hesap_onay_bekleyen = 19;
        $mail_okunmamis = 16; $mail_cevapsiz24 = 1; $kullanici_aktif = 21; $audit_son24h = 22;
    }
    ob_start();
    try { eval($GLOBALS['dashboardPresentation']); return ob_get_clean(); }
    catch (Throwable $e) { ob_end_clean(); throw $e; }
}

if (($argv[1] ?? '') === '--render') {
    $profileName = $argv[2] ?? 'admin';
    if (!isset($dashboardProfiles[$profileName])) throw new InvalidArgumentException('Bilinmeyen anonim profil.');
    echo dashboard_render($dashboardProfiles[$profileName]);
    exit;
}

$dashboardChecks = 0;
function dashboard_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $GLOBALS['dashboardChecks']++;
}
function dashboard_dom(string $html): DOMXPath
{
    $document = new DOMDocument();
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($document);
}
function dashboard_links(DOMXPath $xpath, string $query, string $labelClass): array
{
    $result = [];
    foreach ($xpath->query($query) as $node) {
        $label = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," ' . $labelClass . ' ")]', $node)->item(0);
        $result[] = ['href' => $node->getAttribute('href'), 'label' => $label ? trim($label->textContent) : ''];
    }
    return $result;
}
$dashboardHomeQuery = '//a[contains(concat(" ",normalize-space(@class)," ")," home-card ")]';
$dashboardSidebarQuery = '//nav[contains(concat(" ",normalize-space(@class)," ")," sidebar-nav ")]//a[contains(concat(" ",normalize-space(@class)," ")," sidebar-link ")]';
$dashboardCatalog = [];
foreach (nav_muhasebe_gruplari() as [$heading, , $pages]) {
    foreach ($pages as $href => [$label]) $dashboardCatalog[$href] = $label;
}
dashboard_check(count(nav_muhasebe_gruplari()) === 9 && count($dashboardCatalog) === 33, 'paylaşılan katalog 9 grup/33 hedef (v555: İhracat Programı grubu + Stok Dönüşüm/Kantar Fişleri kalktı)');
// v555 (a): kaldırılan grup/sayfalar katalogda YOK (sayfa dosyaları durur ama menü/kart üretilmez).
foreach (['ihracat'] as $removedGroup) dashboard_check(!isset(nav_muhasebe_gruplari()[$removedGroup]), 'İhracat Programı grubu katalogda yok');
foreach (['ihracat_faturasi_maliyeti.php', 'beyanname_siparis_proforma.php', 'dovizli_cari_fis.php', 'ihracat_tanimlari.php', 'stok_donusum_fisleri.php', 'kantar_fisleri.php'] as $removedPage) {
    dashboard_check(!isset($dashboardCatalog[$removedPage]), 'katalogda yok: ' . $removedPage);
}
dashboard_check(array_keys(nav_eski_esleri()) === ['records', 'cikma', 'kantar'] && array_values(nav_eski_esleri()) === ['arac_yukleme_fisleri', 'palet_fisleri', 'mal_kabul_fisleri'], 'eski → yeni karşılık eşlemesi (records/cikma/kantar)');
foreach (nav_eski_esleri() as $__yeniSlug) dashboard_check(isset($dashboardCatalog[$__yeniSlug . '.php']), 'yeni karşılık katalogda: ' . $__yeniSlug);
dashboard_check(!function_exists('db'), 'DB fonksiyonu/bağlantısı olmadan sunum testi');
foreach ($dashboardProfiles as $name => $profile) {
    $html = dashboard_render($profile);
    $xpath = dashboard_dom($html);
    $home = dashboard_links($xpath, $dashboardHomeQuery, 'home-card-title');
    $sidebar = dashboard_links($xpath, $dashboardSidebarQuery, 'sidebar-link-label');
    $homeHrefs = array_column($home, 'href');
    $homeLabels = array_column($home, 'label', 'href');
    dashboard_check(count($homeHrefs) === count(array_unique($homeHrefs)), $name . ': her kart hedefi bir kez');
    foreach ($home as $card) dashboard_check($card['label'] !== '', $name . ': kart etiketi boş değil ' . $card['href']);
    foreach ($sidebar as $link) {
        if ($link['href'] === 'index.php') continue;
        dashboard_check(in_array($link['href'], $homeHrefs, true), $name . ': sidebar hedefi ana sayfada ' . $link['href']);
        dashboard_check($homeLabels[$link['href']] === $link['label'], $name . ': sidebar/kart etiketleri aynı ' . $link['href']);
    }
    // v548 yerleşim değişmezleri: sidebar bağlantısı hangi <details data-sb-grup> içinde (yoksa '').
    $sbGroupOf = function (string $href) use ($xpath, $dashboardSidebarQuery): ?string {
        $node = $xpath->query($dashboardSidebarQuery . '[@href="' . $href . '"]')->item(0);
        if ($node === null) return null;
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof DOMElement && $p->tagName === 'details') return $p->getAttribute('data-sb-grup');
        }
        return '';
    };
    // Ana Sayfa: tek bağlantı, HİÇBİR grubun (Operasyon dahil) içinde değil ve ilk grubun üstünde.
    dashboard_check($sbGroupOf('index.php') === '', $name . ': Ana Sayfa hiçbir sidebar grubunun içinde değil');
    $homeLink = $xpath->query($dashboardSidebarQuery . '[@href="index.php"]')->item(0);
    dashboard_check($homeLink !== null && $xpath->query('preceding::details', $homeLink)->length === 0, $name . ': Ana Sayfa ilk grubun (Sipariş) üstünde');
    // Sürüm: yalnız .sidebar-user içinde, çıkış butonuyla aynı kapta, tam bir kez.
    $surumAll = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," sidebar-surum ")]');
    $surumIn = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," sidebar-user ")]//*[contains(concat(" ",normalize-space(@class)," ")," sidebar-surum ")]');
    dashboard_check($surumAll->length === 1 && $surumIn->length === 1 && trim($surumIn->item(0)->textContent) === APP_SURUM, $name . ': sürüm tek kez ve .sidebar-user içinde');
    dashboard_check($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," sidebar-user ")]//a[contains(@class,"sidebar-user-logout")]')->length === 1, $name . ': sürümün yanında çıkış bağlantısı');
    // Taşınan bağlantılar: yetkiye bağlı (admin olmasa da), yeni grubunda.
    foreach ([['malzeme_stok.php', 'stok', can('stok.read')], ['reports.php', 'raporlar_grup', can('reports.read')], ['hesap.php', 'kullanici_konsolu', can('hesap.read') || is_admin()]] as [$movedHref, $movedGroup, $movedAllowed]) {
        dashboard_check($sbGroupOf($movedHref) === ($movedAllowed ? $movedGroup : null), $name . ': ' . $movedHref . ' yalnız yetkiliyse ve ' . $movedGroup . ' grubunda');
    }
    // Eski* adlar yalnız sidebar/kart etiketi; Operasyon grubunda.
    foreach (['records.php' => 'Eski Yükleme Planı', 'cikmalar.php' => 'Eski Paletleme', 'kantar.php' => 'Eski Mal Kabul', 'reports.php' => 'Eski Raporlar'] as $oldHref => $oldLabel) {
        foreach ($sidebar as $link) if ($link['href'] === $oldHref) dashboard_check($link['label'] === $oldLabel, $name . ': sidebar etiketi ' . $oldLabel);
        if (isset($homeLabels[$oldHref])) dashboard_check($homeLabels[$oldHref] === $oldLabel, $name . ': kart etiketi ' . $oldLabel);
        if ($oldHref !== 'reports.php' && ($g = $sbGroupOf($oldHref)) !== null) dashboard_check($g === 'operasyon', $name . ': ' . $oldHref . ' Operasyon grubunda');
    }
    // v558: eski Yükleme Planı / Paletleme / Mal Kabul HİÇBİR rolde menüde ve ana sayfa kartlarında YOK (önceki v555 istisnası kalktı);
    // yeni karşılığı sidebar'da yalnız kendi kapısıyla (muhasebe_sayfa_can). "Eski Raporlar" (reports.php) bu kuraldan MUAF.
    $sbHrefsAll = array_column($sidebar, 'href');
    foreach (['records.php' => 'records', 'cikmalar.php' => 'cikma', 'kantar.php' => 'kantar'] as $oldHref => $oldKey) {
        $newSlug = nav_eski_esleri()[$oldKey];
        dashboard_check(!in_array($oldHref, $sbHrefsAll, true), $name . ': ' . $oldHref . ' sidebar\'da YOK (v558)');
        dashboard_check(!in_array($oldHref, $homeHrefs, true), $name . ': ' . $oldHref . ' ana sayfa kartı YOK (v558)');
        dashboard_check(in_array($newSlug . '.php', $sbHrefsAll, true) === muhasebe_sayfa_can($newSlug), $name . ': ' . $newSlug . '.php sidebar\'da = muhasebe_sayfa_can');
    }
    if ($profile['admin']) {
        // Admin: katalogdaki her sayfa sidebar'da; Operasyon Fişleri Operasyon'un İÇİNDE (ayrı grup değil).
        $catalogGroups = [];
        foreach (nav_muhasebe_gruplari() as $slug => [, , $pages]) foreach ($pages as $href => $_) $catalogGroups[$href] = $slug;
        foreach ($catalogGroups as $href => $slug) {
            $expected = $slug === 'operasyon_fis' ? 'operasyon' : $slug;
            dashboard_check($sbGroupOf($href) === $expected, $name . ': ' . $href . ' sidebar grubu ' . $expected);
        }
        dashboard_check($xpath->query('//details[@data-sb-grup="operasyon_fis"]')->length === 0, $name . ': operasyon_fis ayrı sidebar grubu çizilmez');
        // v555 (a): yönetici sidebar'ında İhracat Programı grubu, Stok Dönüşüm/Kantar Fişleri ve eski Yükleme Planı/Paletleme/Mal Kabul YOK; Raporlar etkilenmez.
        dashboard_check($xpath->query('//details[@data-sb-grup="ihracat"]')->length === 0 && !str_contains($html, 'İhracat Programı'), $name . ': İhracat Programı grubu sidebar\'da yok');
        foreach (['stok_donusum_fisleri.php', 'kantar_fisleri.php', 'ihracat_faturasi_maliyeti.php', 'beyanname_siparis_proforma.php', 'dovizli_cari_fis.php', 'ihracat_tanimlari.php', 'records.php', 'cikmalar.php', 'kantar.php'] as $__gone) {
            dashboard_check($sbGroupOf($__gone) === null, $name . ': yönetici sidebar\'ında yok ' . $__gone);
        }
        dashboard_check($sbGroupOf('reports.php') === (can('reports.read') ? 'raporlar_grup' : null), $name . ': Eski Raporlar (reports.read ile) Raporlar grubunda kalır');
        // v555 (b): Beyanlar Sipariş grubunun İLK öğesi (Operasyon'da değil).
        $__sipHrefs = array_column(dashboard_links($xpath, $dashboardSidebarQuery . '[ancestor::details[@data-sb-grup="siparis"]]', 'sidebar-link-label'), 'href');
        dashboard_check(($__sipHrefs[0] ?? '') === 'beyanlar.php' && $sbGroupOf('beyanlar.php') === 'siparis', $name . ': Beyanlar Sipariş grubunun ilk öğesi');
        dashboard_check(($catalogGroups['gunluk_kurlar.php'] ?? '') === 'kasa' && ($catalogGroups['doviz_tanimlari.php'] ?? '') === 'kasa' && !isset(nav_muhasebe_gruplari()['genel_uygulamalar']), $name . ': Günlük Kurlar + Döviz Tanımları Kasa grubunda, Genel Uygulamalar yok');
    } else {
        // Admin olmayan: yalnız yetkili taşınan bağlantı çizilir; grubun yer tutucu sayfaları ve Operasyon Fişleri çizilmez.
        $sbHrefs = array_column($sidebar, 'href');
        foreach ($dashboardCatalog as $href => $label) dashboard_check(in_array($href, $sbHrefs, true) === muhasebe_sayfa_can(substr($href, 0, -4)), $name . ': muhasebe sayfası sidebar\'da yalnız kendi kapısı açıksa ' . $href);
    }
    foreach (['index.php', 'migrate.php', 'depo_tasima.php', 'firma_eslestirme.php', 'logout.php'] as $excluded) {
        dashboard_check(!in_array($excluded, $homeHrefs, true), $name . ': modül olmayan/eski hedef kart değil ' . $excluded);
    }
    if ($profile['admin']) {
        foreach ($dashboardCatalog as $href => $label) dashboard_check(($homeLabels[$href] ?? null) === $label, $name . ': ortak alt kart ' . $href);
        foreach (nav_muhasebe_gruplari() as [$heading]) {
            $matches = 0;
            foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," home-section-title ")]') as $section) if (trim($section->textContent) === $heading) $matches++;
            dashboard_check($matches === 1, $name . ': katalog grup başlığı bir kez ' . $heading);
        }
    } else {
        foreach (array_merge(array_keys($dashboardCatalog), ['audit.php', 'admin_db_backups.php']) as $adminOnly) {
            dashboard_check(in_array($adminOnly, $homeHrefs, true) === (isset($dashboardCatalog[$adminOnly]) && muhasebe_sayfa_can(substr($adminOnly, 0, -4))), $name . ': muhasebe/admin kartı yalnız kapısı açıksa ' . $adminOnly);
        }
    }
    if ($name === 'admin') {
        dashboard_check(count($home) === 49, 'admin: Ana Sayfa hariç 49 benzersiz kart (33 katalog + 16 modül kartı; v558: eski Yükleme Planı/Paletleme/Mal Kabul kartları yok)');
        $icon = $xpath->query('//a[@href="hizli_maliyet.php" and contains(@class,"home-card")]//img')->item(0);
        dashboard_check($icon !== null && str_starts_with($icon->getAttribute('src'), 'assets/nav-icons/qcost.svg?v='), 'admin: ortak sürümlü Hızlı Maliyet SVG');
    }
    // Hızlı Maliyet kartı/sidebar bağlantısı YALNIZ yönetici ya da hizli_maliyet.read ile (maliyet.* AÇMAZ; write/delete okumasız AÇMAZ).
    $quickExpected = $profile['admin'] || in_array('hizli_maliyet.read', $profile['permissions'], true);
    dashboard_check(in_array('hizli_maliyet.php', $homeHrefs, true) === $quickExpected, $name . ': Hızlı Maliyet kartı yalnız hizli_maliyet.read/yönetici');
    dashboard_check(in_array('hizli_maliyet.php', array_column($sidebar, 'href'), true) === $quickExpected, $name . ': Hızlı Maliyet sidebar bağlantısı kartla aynı kapı');
    if ($name === 'only_hizli_maliyet_read') dashboard_check(isset($homeLabels['hizli_maliyet.php']) && count($home) === 1, 'yalnız hizli_maliyet.read tek kart + bölüm görünür');
    foreach (['loading_only'=>['records.php','cikmalar.php'],'pallet_only'=>['cikmalar.php','records.php'],'product_orders_only'=>['urun_siparis.php','siparisler.php'],'ogl_only'=>['siparisler.php','urun_siparis.php'],'ogl_only_v3'=>['siparisler.php','frugro_siparis.php'],'frugro_only'=>['frugro_siparis.php','siparisler.php']] as $profileName=>[$yes,$no]) if ($name===$profileName) {
        if (in_array($yes, ['records.php', 'cikmalar.php', 'kantar.php'], true)) {   // v558: eski sayfaların menü/kart girişi HİÇ yok
            dashboard_check(!array_intersect([$yes, $no], $homeHrefs) && !array_intersect([$yes, $no], array_column($sidebar, 'href')), 'v558 eski sayfa kartı/sidebar girişi yok '.$profileName);
            continue;
        }
        dashboard_check(in_array($yes,$homeHrefs,true),'Detailed page visible '.$yes);
        dashboard_check(!in_array($no,$homeHrefs,true),'Sibling page denied '.$no);
        dashboard_check(in_array($yes,array_column($sidebar,'href'),true) && !in_array($no,array_column($sidebar,'href'),true),'Sidebar matches card gate '.$profileName);
    }
    // v558 açık örnekler: eski izni olan rolde bile eski giriş YOK; yeni karşılık yalnız kendi kapısıyla.
    if ($name === 'eski_yalniz') dashboard_check(!array_intersect(['records.php', 'cikmalar.php', 'kantar.php', 'arac_yukleme_fisleri.php'], array_column($sidebar, 'href')), 'yalnız eski izni olan rolde de eski giriş çizilmez (v558), yeni yok');
    if ($name === 'yeni_fis_hepsi') dashboard_check(!array_intersect(['records.php', 'cikmalar.php', 'kantar.php'], array_column($sidebar, 'href')) && count(array_intersect(['arac_yukleme_fisleri.php', 'palet_fisleri.php', 'mal_kabul_fisleri.php'], array_column($sidebar, 'href'))) === 3, 'yeni karşılıklar görünür rolde eski girişler çizilmez');
    if ($name === 'yeni_fis_yalniz_yukleme') dashboard_check(!in_array('records.php', array_column($sidebar, 'href'), true) && !in_array('kantar.php', array_column($sidebar, 'href'), true) && in_array('arac_yukleme_fisleri.php', array_column($sidebar, 'href'), true), 'yalnız Araç Yükleme Fişleri görünen rolde de eski girişler yok (v558)');
    if ($name === 'ogl_only') dashboard_check(in_array('frugro_siparis.php',$homeHrefs,true),'Eski (Frugro satırı öncesi) OGL rolü Frugro kartını korur');
    if ($name === 'only_defs_read') dashboard_check(isset($homeLabels['definitions.php']), 'admin olmayan tanım yetkili kartı korunur');
    if ($name === 'only_users_admin') dashboard_check(isset($homeLabels['users.php']), 'admin olmayan kullanıcı yönetimi kartı korunur');
    if ($name === 'dashboard_only') dashboard_check(count($home) === 0, 'yalnız dashboard yetkisinde ilgisiz kart yok');
    foreach (['attendance.employees', 'attendance.cards', 'attendance.scan'] as $legacy) {
        if ($name === 'only_' . str_replace('.', '_', $legacy)) dashboard_check(isset($homeLabels['personel_takip.php']), 'yetkili personel alt sayfasının merkez girişi görünür: ' . $legacy);
    }
}
$badgeXpath = dashboard_dom(dashboard_render($dashboardProfiles['admin'], true));
// v558: records.php / cikmalar.php kartları (rozet 11/12) kaldırıldı.
foreach (['definitions.php' => '13', 'beyanlar.php' => '14', 'halkayit/index.php' => '15', 'mail.php' => '16', 'hesap.php' => '18', 'personel_takip.php' => '20', 'users.php' => '21', 'audit.php' => '22'] as $href => $badge) {
    $node = $badgeXpath->query('//a[@href="' . $href . '" and contains(@class,"home-card")]//*[contains(concat(" ",normalize-space(@class)," ")," home-card-badge ")]')->item(0);
    dashboard_check($node !== null && trim($node->textContent) === $badge, 'mevcut anonim kart rozeti korunur ' . $href);
}
dashboard_check(!function_exists('db'), 'test sonunda DB/bootstrap hâlâ yüklenmedi');
echo 'PASS ' . $dashboardChecks . ' dashboard/sidebar checks across ' . count($dashboardProfiles) . " anonymous profiles (no bootstrap/DB).\n";
