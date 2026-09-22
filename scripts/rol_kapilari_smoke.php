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

$PERMS = [];
$IS_ADMIN = false;
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
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

$IS_ADMIN = true; $PERMS = [];
ok('admin (yetkisi boşalmış olsa da) → beyanlar.php',
    first_allowed_page() === 'beyanlar.php', 'admin hiçbir yere gidemiyor');
$IS_ADMIN = false;

// first_allowed_page'in döndürdüğü her sayfa gerçekten var mı?
foreach (['records.php','beyanlar.php','kantar.php','halkayit/index.php','reports.php',
          'malzeme_stok.php','hesap.php','maliyet.php','personel_takip.php',
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

echo "\n" . ($fail === 0 ? "TÜMÜ GEÇTİ" : "$fail TEST BAŞARISIZ") . "\n";
exit($fail === 0 ? 0 : 1);
