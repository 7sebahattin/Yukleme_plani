<?php
// =========================================================
// scripts/rol_kapilari_smoke.php — Rol/yetki KAPILARI testi (Sprint Rol-02)
//
// SADECE CLI, ağsız, canlı DB'ye dokunmaz.
//   php scripts/rol_kapilari_smoke.php   → çıkış kodu 0 = geçti
//
// Roller ekranı geldikten sonra ortaya çıkan "yetki mimarisi" hatalarını
// sabitler. Hepsi gerçekten yaşanmış sorunlardır:
//   1) Seed her istekte çalışıp roles.php'den kaldırılan yetkiyi geri yazıyordu.
//   2) dashboard.read'i olmayan rol girişte 403'e düşüp sistemi hiç kullanamıyordu.
//   3) hesap_can() reports.read/records.write üzerinden Hesap modülüne sessiz
//      köprü kuruyordu — yetki kutuları gerçeği söylemiyordu.
//   4) users.php'nin kilitlenme koruması yalnız 'admin' SLUG'ına bakıyordu.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/role_permission_matrix.php';

$PERMS = [];
$IS_ADMIN = false;
function can(string $p): bool { global $PERMS; return in_array($p, role_matrix_expand_permissions($PERMS), true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function db(): PDO { throw new RuntimeException('bu test DB kullanmaz'); }
function base_url(): string { return '/'; }

require_once $ROOT . '/config/hesap_calc.php';   // GERÇEK hesap_can()

// first_allowed_page() gövdesini helpers.php'den çıkarıp yükle — helpers.php'nin
// tamamı migrasyon IIFE'si yüzünden DB ister, bu yüzden yalnız bu fonksiyon alınır.
//
// ⚠ Çıkarma nav_ptak_sayfalari()'ndan BAŞLAR: first_allowed_page() artık
// 'personel_takip.php' kapısı için nav_ptak_gorunur()'u çağırıyor (izin listesi
// sidebar/bottomnav ile TEK kaynakta tutuluyor). Yalnız first_allowed_page()
// alınırsa o çağrı tanımsız fonksiyon hatası verir.
$hsrc = file_get_contents($ROOT . '/config/helpers.php');
$a = strpos($hsrc, 'function nav_ptak_sayfalari(): array {');
$b = strpos($hsrc, 'function render_desktop_sidebar(');
if ($a === false || $b === false || $b < $a) { fwrite(STDERR, "first_allowed_page() bulunamadı\n"); exit(1); }
eval(substr($hsrc, $a, $b - $a));

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-72s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → $ipucu");
}

// ── 1) first_allowed_page(): giriş 403 tuzağı ───────────────────────────
echo "\n=== 1. first_allowed_page() — giriş yönlendirmesi ===\n";

$PERMS = ['attendance.daily_scan'];
ok('yalnız günlük tarama yetkisi → personel_takip.php',
    first_allowed_page() === 'personel_takip.php', 'dönen: ' . var_export(first_allowed_page(), true));

$PERMS = ['records.read', 'attendance.daily_scan'];
ok('records.read varsa sıra records.php',
    first_allowed_page() === 'records.php');

$PERMS = ['hesap.read'];
ok('yalnız hesap.read → hesap.php', first_allowed_page() === 'hesap.php');

$PERMS = ['users.admin'];
ok('yalnız users.admin → users.php', first_allowed_page() === 'users.php');

$PERMS = ['attendance.management_reports'];
ok('yalnız yönetim raporu → personel_takip.php',
    first_allowed_page() === 'personel_takip.php');

$PERMS = [];
ok('hiç yetki yok → null (403 mesajı gösterilir)', first_allowed_page() === null);

$PERMS = ['dashboard.read'];
ok('yalnız dashboard.read → null (index.php zaten açılır)', first_allowed_page() === null);

$PERMS = ['siparis.read'];
ok('legacy siparis.read → yeni sırada Ürün Siparişler', first_allowed_page() === 'urun_siparis.php',
    'dönen: ' . var_export(first_allowed_page(), true));

$PERMS = ['role.permissions.detailed','siparis.ogl.read'];
ok('yalnız OGL sayfa izni → siparisler.php', first_allowed_page() === 'siparisler.php');
$PERMS = ['role.permissions.detailed','siparis.urun.read'];
ok('yalnız Ürün Siparişler izni → urun_siparis.php', first_allowed_page() === 'urun_siparis.php');
$PERMS = ['role.permissions.detailed','records.pallet.read'];
ok('yalnız Paletleme izni → cikmalar.php', first_allowed_page() === 'cikmalar.php');
$PERMS = ['siparis.read', 'records.read'];
ok('records.read varsa sıra yine records.php (siparisler ondan SONRA)', first_allowed_page() === 'records.php');

$PERMS = ['siparis.write', 'siparis.delete', 'siparis.match'];
ok('siparis.read olmadan write/delete/match → siparisler.php YOK',
    first_allowed_page() !== 'siparisler.php' && !can_siparis('read') && !can_siparis('write'));

$PERMS = ['records.read', 'records.write', 'beyan.read', 'beyan.write', 'dashboard.read'];
ok('sessiz köprü YOK: records.*/beyan.* siparis kapısını AÇMIYOR',
    !can_siparis('read') && !can_siparis('write') && !can_siparis('delete') && !can_siparis('match'));

$PERMS = ['siparis.read'];
ok('can_siparis matrisi: read açık, write/delete/match kapalı',
    can_siparis('read') && !can_siparis('write') && !can_siparis('delete') && !can_siparis('match'));
$PERMS = ['siparis.read', 'siparis.write', 'siparis.match'];
ok('can_siparis: operator seti → read/write/match açık, delete kapalı',
    can_siparis('read') && can_siparis('write') && can_siparis('match') && !can_siparis('delete'));
ok('can_siparis: bilinmeyen izin adı → false', !can_siparis('admin') && !can_siparis(''));

$IS_ADMIN = true; $PERMS = [];
ok('can_siparis: admin dört yetkiyi açar, bilinmeyeni açmaz',
    can_siparis('read') && can_siparis('write') && can_siparis('delete') && can_siparis('match') && !can_siparis('x'));

// ── Hızlı Maliyet (hizli_maliyet.*) — maliyet.* ile KARIŞMAZ ───────────────
$IS_ADMIN = false;
$PERMS = ['maliyet.read', 'maliyet.write', 'maliyet.delete', 'maliyet.admin'];
ok('eski maliyet.* izinleri Hızlı Maliyet\'i AÇMAZ (operator/muhasebe benzeri)',
    !can_hizli_maliyet('read') && !can_hizli_maliyet('write') && !can_hizli_maliyet('delete')
    && first_allowed_page() === 'maliyet.php');
$PERMS = ['hizli_maliyet.read'];
ok('can_hizli_maliyet: yalnız read → read açık, write/delete kapalı',
    can_hizli_maliyet('read') && !can_hizli_maliyet('write') && !can_hizli_maliyet('delete'));
ok('yalnız hizli_maliyet.read → hizli_maliyet.php', first_allowed_page() === 'hizli_maliyet.php');
$PERMS = ['hizli_maliyet.read', 'hizli_maliyet.write'];
ok('can_hizli_maliyet: read+write → silme kapalı', can_hizli_maliyet('write') && !can_hizli_maliyet('delete'));
$PERMS = ['hizli_maliyet.read', 'hizli_maliyet.write', 'hizli_maliyet.delete'];
ok('can_hizli_maliyet: üçü birden açık', can_hizli_maliyet('read') && can_hizli_maliyet('write') && can_hizli_maliyet('delete'));
$PERMS = ['hizli_maliyet.write', 'hizli_maliyet.delete'];
ok('hizli_maliyet.read olmadan write/delete hiçbir şey açmaz',
    !can_hizli_maliyet('read') && !can_hizli_maliyet('write') && !can_hizli_maliyet('delete') && first_allowed_page() === null);
$PERMS = ['hizli_maliyet.read'];
ok('can_hizli_maliyet: bilinmeyen izin adı → false', !can_hizli_maliyet('admin') && !can_hizli_maliyet(''));
$PERMS = []; $IS_ADMIN = true;
ok('can_hizli_maliyet: yönetici satırsız da üç yetkiyi açar, bilinmeyeni açmaz',
    can_hizli_maliyet('read') && can_hizli_maliyet('write') && can_hizli_maliyet('delete') && !can_hizli_maliyet('x'));
$IS_ADMIN = false; $PERMS = [];
// Katalog ↔ seed eşitliği: üç hizli_maliyet.* yetkisi katalogda ve admin seed'inde birlikte
$asrc = file_get_contents($ROOT . '/config/auth.php');
$ca = strpos($asrc, 'function permission_catalog(): array {');
$cb = strpos($asrc, 'function protected_role_slugs()', $ca);
if (!function_exists('permission_catalog')) eval(substr($asrc, $ca, $cb - $ca));
ok('katalogda Hızlı Maliyet grubu 3 yetki', array_keys(permission_catalog()['Hızlı Maliyet'] ?? []) === ['hizli_maliyet.read', 'hizli_maliyet.write', 'hizli_maliyet.delete']);
ok('admin seed ($all_p) üç hizli_maliyet.* yetkisini içerir; operator/muhasebe/viewer seed\'i içermez',
    str_contains($hsrc, "'hizli_maliyet.read','hizli_maliyet.write','hizli_maliyet.delete'], \$pdks_p)")
    && !preg_match("/'(operator|viewer|muhasebe|ik)'\s*=>\s*\[[^\]]*hizli_maliyet/", $hsrc));
$IS_ADMIN = false;

$IS_ADMIN = true; $PERMS = [];
ok('admin (yetkisi boşalmış olsa da) → beyanlar.php',
    first_allowed_page() === 'beyanlar.php', 'admin hiçbir yere gidemiyor');
$IS_ADMIN = false;

// first_allowed_page'in döndürdüğü her sayfa gerçekten var mı?
foreach (['records.php','beyanlar.php','kantar.php','halkayit/index.php','reports.php',
          'malzeme_stok.php','hesap.php','mail.php','siparisler.php','maliyet.php','personel_takip.php',
          'definitions.php','users.php'] as $sayfa) {
    ok("hedef dosya mevcut: $sayfa", file_exists($ROOT . '/' . $sayfa));
}

// ── 2) hesap_can(): sessiz köprüler kapandı ─────────────────────────────
echo "\n=== 2. hesap_can() — yetki kutuları gerçeği söylüyor mu ===\n";

$PERMS = ['reports.read'];
ok('reports.read TEK BAŞINA Hesap açmıyor', !hesap_can('read'),
    'Roller ekranında Hesap kutuları boşken modül açılıyor');

$PERMS = ['records.write'];
ok('records.write Hesap kaydı YAZDIRMIYOR', !hesap_can('write'),
    'yükleme yetkisi masraf kaydı yazdırıyor');

$PERMS = ['records.delete'];
ok('records.delete Hesap kaydı SİLDİRMİYOR', !hesap_can('delete'));

$PERMS = ['hesap.read'];
ok('hesap.read → okuma açık', hesap_can('read'));
ok('hesap.read yazma AÇMIYOR', !hesap_can('write'));

$PERMS = ['hesap.read','hesap.write'];
ok('hesap.write → yazma açık', hesap_can('write'));
ok('hesap.write onay AÇMIYOR', !hesap_can('approve'));

$PERMS = ['hesap.approve'];
ok('hesap.approve → onay açık', hesap_can('approve'));

$PERMS = ['hesap.admin'];
ok('hesap.admin üst yetki: onay/ödeme açık', hesap_can('approve') && hesap_can('pay'));

$IS_ADMIN = true; $PERMS = [];
ok('admin her şeyi açar', hesap_can('read') && hesap_can('write') && hesap_can('pay'));
$IS_ADMIN = false;

// ── 3) Kaynak kodu değişmezleri (statik) ────────────────────────────────
echo "\n=== 3. Kaynak kodu değişmezleri ===\n";

$idx = file_get_contents($ROOT . '/index.php');
ok('index.php artık dashboard.read için 403 BASMIYOR',
    !preg_match("/require_perm\('dashboard\.read'\)/", $idx),
    'giriş akışı yine 403 tuzağına düşer');
ok('index.php first_allowed_page() ile yönlendiriyor',
    str_contains($idx, 'first_allowed_page()'));
ok('index.php Hesap kartı hesap.read ile kapılı (reports.read DEĞİL)',
    (bool)preg_match("/if \(can\('hesap\.read'\) \|\| is_admin\(\)\): \?>\s*\n\s*<a href=\"hesap\.php\"/", $idx),
    'kart kapısı hesap_can() ile ayrışıyor');

ok('sidebar \$p_hes artık reports.read\'e düşmüyor',
    !preg_match("/\\\$p_hes\s*=[^\n]*reports\.read/", $hsrc),
    'menüde Hesap görünüp tıklayınca 403 verir');

// Seed koruması — roles.php düzenlemelerini geri yazmamalı
$seedBlok = substr($hsrc, strpos($hsrc, '$ins_p'), 700);
ok('seed yalnız YETKİSİZ role uygulanıyor (admin düzenlemesi korunur)',
    str_contains($seedBlok, 'role_permissions` WHERE role_id') && str_contains($seedBlok, 'continue;'),
    'koşulsuz INSERT IGNORE — kaldırılan yetki her istekte geri gelir');

$usr = file_get_contents($ROOT . '/users.php');
ok('users.php kilitlenme kilidi yetki tabanlı (update_user)',
    str_contains($usr, "any_active_user_has_permission('users.admin')"),
    'yalnız admin SLUG\'ına bakan koruma özel rolleri görmez');
ok('users.php pasife almada users.admin kontrolü var',
    str_contains($usr, "rp.permission = 'users.admin'"));
ok('users.php update_user işlem (transaction) içinde',
    str_contains($usr, '$pdo->beginTransaction();') && str_contains($usr, '$pdo->commit();'));

$rol = file_get_contents($ROOT . '/roles.php');
ok('roles.php PDOException\'ı RuntimeException\'dan ÖNCE yakalıyor',
    strpos($rol, 'catch (PDOException $e)') < strpos($rol, 'catch (RuntimeException $e)'),
    'PDOException RuntimeException alt sınıfı — DB hatası "kilitlenme" mesajı verir');
ok('roles.php POST yetkilerini is_string ile süzüyor',
    str_contains($rol, 'is_string($p)'),
    'iç içe dizi PHP uyarısı sızdırır');
ok('roles.php silme işlemi atomik',
    (bool)preg_match('/beginTransaction\(\);\s*\n\s*try \{\s*\n\s*\$pdo->prepare\("DELETE FROM role_permissions/', $rol));
ok('yeni rol varsayılanında dashboard.read işaretli',
    str_contains($rol, ": ['dashboard.read'];"));

// ── Kaldırılan web sayfaları = 410 tombstone (v281) ──────────────────────
// Deploy repodan silinen dosyayı sunucudan SİLMEZ; kaldırılan sayfa içeriği
// boşaltılmış 410 yanıtıdır. DB/oturum/girdi erişimi YOK, kısa kalır ve
// uygulamanın hiçbir yerinden bağlantı almaz.
echo "\n── Tombstone değişmezleri ──\n";
$TOMBSTONES = ['admin_db_backup_download.php', 'hesap_sahipsiz.php', 'depo_tasima.php', 'firma_eslestirme.php',
               'fix_brand.php', 'repair_xz_tables.php', 'faz8b_migrate.php', 'record_new.php'];
$kaynaklar = array_merge(glob($ROOT . '/*.php'), glob($ROOT . '/config/*.php'), glob($ROOT . '/assets/*.js'));
foreach ($TOMBSTONES as $t) {
    $src = (string)@file_get_contents($ROOT . '/' . $t);
    ok("$t: 410 döner", str_contains($src, 'http_response_code(410)'));
    ok("$t: DB/oturum/girdi erişimi YOK", !preg_match('/require|include|db\(|session_|\$_POST|\$_GET/', $src));
    ok("$t: ≤15 satır", $src !== '' && substr_count($src, "\n") <= 15);
    $linkler = [];
    foreach ($kaynaklar as $k) {
        if (basename($k) === $t) continue;
        $ks = (string)file_get_contents($k);
        if (str_contains($ks, 'href="' . $t) || str_contains($ks, "'" . $t . "'")) $linkler[] = basename($k);
    }
    ok("$t: hiçbir sayfadan bağlantı YOK", $linkler === [], implode(', ', $linkler));
}

echo "\n" . ($fail === 0 ? "TÜMÜ GEÇTİ" : "$fail TEST BAŞARISIZ") . "\n";
exit($fail === 0 ? 0 : 1);
