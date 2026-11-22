<?php
// =========================================================
// scripts/bottomnav_render.php — Mobil alt gezinme çubuğu (bottomnav)
// tarayıcı testinin GİRDİ ÜRETİCİSİ.
//
// GERÇEK render_header() + render_footer() (config/helpers.php) çağrılır;
// yalnız auth/depo/DB stub'lanır (bellek içi SQLite, canlı DB'ye HİÇ
// dokunmaz). Her profil × sayfa için bir HTML dosyası ve beklentileri
// taşıyan manifest.json yazar; ölçümü scripts/bottomnav_smoke.js yapar.
//
//   BOTTOMNAV_OUT=/tmp/bottomnav-test php scripts/bottomnav_render.php
//   BOTTOMNAV_OUT=/tmp/bottomnav-test node scripts/bottomnav_smoke.js
//
// (Çıktı klasörü verilmezse sys_get_temp_dir()/bottomnav-test kullanılır;
//  --out=KLASÖR argümanı da kabul edilir.)
//
// ÜRETİM CSS'i: <link href="assets/style.css?v=..."> etiketi, assets/style.css
// dosyasının İÇERİĞİYLE <style> olarak değiştirilir (file:// altında
// cssRules okunabilsin diye — güvenli alan / kural taraması için gerekli).
// app.js ve görseller mutlak file:// yoluyla bağlanır (konsol hatası üretmesin).
//
// YETKİ KAPISI TABLOSU ($GATES): çubuktaki her bağlantının hedef sayfası,
// o sayfanın KENDİ kapısıyla değerlendirilir (first_allowed_page() ile aynı
// ilke). Her girdinin kaynak satırı ÇALIŞMA ANINDA aranır; kapı taşınır ya
// da değişirse bu betik HATA verir (tablo bayatlamasın).
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/role_permission_matrix.php';
$OUT  = getenv('BOTTOMNAV_OUT') ?: (sys_get_temp_dir() . '/bottomnav-test');
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--out=')) $OUT = substr($a, 6);
}
if (!is_dir($OUT) && !mkdir($OUT, 0777, true)) {
    fwrite(STDERR, "Çıktı klasörü açılamadı: $OUT\n");
    exit(1);
}
$OUT = realpath($OUT);

// Migration gürültüsünü (SQLite'ta SHOW COLUMNS çalışmaz) log'a yönlendir.
ini_set('log_errors', '1');
ini_set('error_log', $OUT . '/php_render.log');
ini_set('session.save_path', sys_get_temp_dir());
ini_set('session.use_cookies', '0');
session_start();   // csrf_token() çıktıdan ÖNCE oturum ister

// ── SQLite + auth/depo stub'ları (helpers.php'den ÖNCE) ──────────────────
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PROFIL = ['perms' => [], 'admin' => false, 'depo' => null, 'rol' => null];
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { global $PROFIL; return in_array($p, role_matrix_expand_permissions($PROFIL['perms']), true); }
function is_admin(): bool { global $PROFIL; return (bool)$PROFIL['admin']; }
function require_login(): array { return current_user(); }
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function active_depot(): ?string { global $PROFIL; return $PROFIL['depo']; }
function enforce_active_depot(): void {}
function depot_options(): array { global $PROFIL; return $PROFIL['depo'] ? [$PROFIL['depo']] : []; }
function depot_visible_to_user(?string $d): bool { return true; }
function user_allowed_depots(): array { global $PROFIL; return $PROFIL['depo'] ? [$PROFIL['depo']] : []; }
function depo_sql_records(string $a = 'r'): array { return ['', []]; }
function depo_sql_records_in(string $a = 'r'): array { return ['', []]; }
function depo_sql_column(string $c): array { return ['', []]; }
function depo_sql_in(string $c): array { return ['', []]; }
function user_primary_role(): ?array { global $PROFIL; return $PROFIL['rol']; }

// GERÇEK yetki kataloğu (auth.php can()/is_admin() tanımladığı için bütün
// dosya yüklenemez — roles_modal_render.php ile aynı çıkarma yöntemi).
$asrc = file_get_contents($ROOT . '/config/auth.php');
$a = strpos($asrc, 'function permission_catalog(): array {');
$b = strpos($asrc, "\n}\n", $a);
eval(substr($asrc, $a, $b - $a + 3));
$KATALOG = array_keys(array_merge(...array_values(permission_catalog())));

require_once $ROOT . '/config/helpers.php';   // GERÇEK render_header/footer, first_allowed_page, nav_ptak_*
// v552: muhasebe menüsü sayfa yetkileri (muhasebe.<sayfa>.read) auth.php permission_catalog()'unda DEĞİL, muhasebe_roller.php katalog eklerinde.
$KATALOG = array_values(array_unique(array_merge($KATALOG, array_keys(muhasebe_rol_katalog()))));

// Depo rengi: biri tanımdan (elle seçilmiş renk), biri isimden türetilen palet.
db()->exec("CREATE TABLE IF NOT EXISTS material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT, type TEXT, is_active INT DEFAULT 1, color TEXT)");
db()->exec("INSERT INTO material_definitions (name, type, color) VALUES ('MERKEZ DEPO', 'depo', '#16a34a')");

// ── Profiller ─────────────────────────────────────────────────────────────
// Yetki adları permission_catalog()'dan DOĞRULANIR (aşağıda). Varsayılan
// rollerin listeleri helpers.php seed'inin ($rp_map) birebir kopyasıdır —
// ama canlıda roller roles.php'den değiştirilebilir, bu yüzden "benzeri".
$PROFILLER = [
    'admin' => [
        'ad' => 'Sistem Yöneticisi (admin)', 'admin' => true, 'depo' => 'MERKEZ DEPO',
        'rol' => ['slug' => 'admin', 'label' => 'Sistem Yöneticisi'],
        'perms' => $KATALOG,
    ],
    'operator' => [
        'ad' => 'Operatör benzeri', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'operator', 'label' => 'Operatör'],
        'perms' => ['dashboard.read','records.read','records.write','records.lock','kantar.read','kantar.write','stok.read','stok.write','defs.read','reports.read','reports.export','beyan.read','beyan.write','maliyet.read','maliyet.write','hesap.read','hesap.write','attendance.daily_scan'],
    ],
    'muhasebe' => [
        'ad' => 'Muhasebe benzeri', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'muhasebe', 'label' => 'Muhasebe'],
        'perms' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay','siparis.read','attendance.daily_reports','attendance.foreman_rates','attendance.entitlements','attendance.foreman_accounts','attendance.foreman_payments','attendance.management_reports'],
    ],
    'viewer' => [
        // Depo YOK: aktif depo seçilmemiş hâl (render_header değişkeni basmaz).
        'ad' => 'İzleyici benzeri (aktif depo yok)', 'admin' => false, 'depo' => null,
        'rol' => ['slug' => 'viewer', 'label' => 'İzleyici'],
        'perms' => ['dashboard.read','records.read','kantar.read','stok.read','defs.read','reports.read','beyan.read','hesap.read'],
    ],
    'hizli_okuyucu' => [
        // Özel rol: yalnız records.read + hizli_maliyet.read (eski maliyet.* YOK) → Hızlı Maliyet kapısı hizli_maliyet.read'den açılır.
        'ad' => 'Özel rol: Hızlı Maliyet okuyucu', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'hizli_okuyucu', 'label' => 'Hızlı Maliyet Okuyucu'],
        'perms' => ['dashboard.read','records.read','hizli_maliyet.read'],
    ],
    'ozel_gunluk' => [
        // Özel rol: YALNIZ attendance.* (Günlük İşçi) — dashboard.read YOK.
        'ad' => 'Özel rol: yalnız Günlük İşçi (attendance.*)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'gunluk_sorumlu', 'label' => 'Günlük İşçi Sorumlusu'],
        'perms' => ['attendance.foremen','attendance.worker_cards','attendance.daily_scan','attendance.daily_reports'],
    ],
    'dort_sayfa' => [
        // Tam 4 aday sayfa (records+cikma, kantar, reports): 390px ve üstünde
        // 4 slot → "Diğer" GEREKSİZ; 390 altında 4. slot gizlenir → "Diğer"
        // (yalnız dar ekranda) o sayfayı taşır.
        'ad' => 'Özel rol: tam 4 aday sayfa', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'dort', 'label' => 'Dört Sayfa'],
        'perms' => ['dashboard.read','records.read','kantar.read','reports.read'],
    ],
    'ozel_pdks' => [
        // Özel rol: YALNIZ kalıcı personel PDKS izinleri (attendance.*) —
        // personel.php'yi açabilir (require_pdks('employees')) ama Personel
        // Takibi'nin 9 izninden hiçbiri yok. Uç durum.
        'ad' => 'Özel rol: yalnız kalıcı PDKS (attendance.*)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'pdks_kalici', 'label' => 'PDKS Kartoteks'],
        'perms' => ['attendance.read','attendance.employees','attendance.cards','attendance.scan'],
    ],
    // Sprint Alt-Menü-01 düzeltme turu (BN-MALIYET-AKTIF-YOK): dashboard.read
    // YOK ve maliyet.read TEK yetki — Ana Sayfa first_allowed_page()'e
    // (maliyet.php) gider. maliyet.php nav_alt_izinler()'de HİÇ aday değildir
    // (bilerek, bkz. CLAUDE.md) → adaylar boş, çubukta yalnız Ana Sayfa
    // görünür ve maliyet.php'de AKTİF işaretlenmelidir (nav_aktif_anahtar()
    // orada 'rapor' döner — maliyet'in kendi sidebar girişi yok).
    'maliyet_tek' => [
        'ad' => 'Özel rol: yalnız maliyet.read (dashboard.read YOK)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'maliyet_tek', 'label' => 'Maliyet'],
        'perms' => ['maliyet.read'],
    ],
    // Sprint Alt-Menü-01 düzeltme turu (mükerrer slot): dashboard.read YOK
    // ve hesap.read TEK yetki — Ana Sayfa first_allowed_page()'e (hesap.php)
    // gider AMA hesap.read AYNI ZAMANDA nav_alt_izinler()'de 'hesap' adayını
    // da açar (href'i de hesap.php) — Ana Sayfa'yla AYNI sayfaya giden ikinci
    // bir slot ÇİZİLMEMELİDİR.
    'hesap_tek' => [
        'ad' => 'Özel rol: yalnız hesap.read (dashboard.read YOK)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'hesap_tek', 'label' => 'Hesap'],
        'perms' => ['hesap.read'],
    ],
    // v552: "Diğer" sayfası sidebar ile AYNI modelden çizilir. Yalnız 3 aday sayfa (eskiden "Diğer" ÇIKMAZDI) + anahtarsız
    // (pin'siz) muhasebe sayfaları → o sayfalara mobilde ulaşmak için "Diğer" düğmesi/sheet ŞART.
    'muhasebe_sayfa' => [
        'ad' => 'Özel rol: 3 aday + muhasebe sayfaları (cari kartları, palet fişleri)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'muhasebe_sayfa', 'label' => 'Muhasebe Sayfa'],
        'perms' => ['dashboard.read','records.read','kantar.read','muhasebe.cari_kartlari.read','muhasebe.palet_fisleri.read'],
    ],
    // dashboard.read YOK + yalnız muhasebe sayfaları: Ana Sayfa ilk izinli muhasebe sayfasına (cari_kartlari.php) gider →
    // o sayfa sheet'te İKİNCİ kez çizilmez; kalan (cari_dekontlar) için "Diğer" şart.
    'muhasebe_tek' => [
        'ad' => 'Özel rol: yalnız muhasebe sayfaları (dashboard.read YOK)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
        'rol' => ['slug' => 'muhasebe_tek', 'label' => 'Muhasebe Tek'],
        'perms' => ['muhasebe.cari_kartlari.read','muhasebe.cari_dekontlar.read'],
    ],
];
// v555: eski Yükleme Planı/Paletleme/Mal Kabul girişleri (records/cikma/kantar adayları) YALNIZ yeni karşılığı (muhasebe fiş sayfası:
// arac_yukleme_fisleri / palet_fisleri / mal_kabul_fisleri, muhasebe_sayfa_can) kullanıcıya görünüyorsa menüden (sidebar + mobil Diğer
// + alt çubuk adayları) kalkar. Karşılığını göremeyen rol (operator, viewer, dort_sayfa…) eski girişi KORUR.
//  · yeni_fis_hepsi      : eski izinleri + 3 yeni fiş sayfası → eski 3 aday YOK, yeni 3 muhasebe sayfası sheet'te
//  · yeni_fis_kismi      : eski izinleri + YALNIZ Mal Kabul Fişleri → yalnız kantar adayı gizli (karşılık BAŞINA karar)
//  · yalniz_yeni_fis     : eski izni YOK, yalnız 3 yeni fiş sayfası (dashboard.read yok → Ana Sayfa ilk izinli muhasebe sayfasına gider)
$PROFILLER['yeni_fis_hepsi'] = ['ad' => 'Özel rol: eski izinler + 3 yeni fiş sayfası (eski girişler gizli)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
    'rol' => ['slug' => 'yeni_fis_hepsi', 'label' => 'Yeni Fiş Hepsi'],
    'perms' => ['dashboard.read','records.read','kantar.read','reports.read','muhasebe.arac_yukleme_fisleri.read','muhasebe.palet_fisleri.read','muhasebe.mal_kabul_fisleri.read']];
$PROFILLER['yeni_fis_kismi'] = ['ad' => 'Özel rol: eski izinler + yalnız Mal Kabul Fişleri (yalnız eski Mal Kabul gizli)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
    'rol' => ['slug' => 'yeni_fis_kismi', 'label' => 'Yeni Fiş Kısmi'],
    'perms' => ['dashboard.read','records.read','kantar.read','reports.read','muhasebe.mal_kabul_fisleri.read']];
$PROFILLER['yalniz_yeni_fis'] = ['ad' => 'Özel rol: yalnız 3 yeni fiş sayfası (eski izin YOK, dashboard.read YOK)', 'admin' => false, 'depo' => 'KARAMAN CİHAT',
    'rol' => ['slug' => 'yalniz_yeni_fis', 'label' => 'Yalnız Yeni Fiş'],
    'perms' => ['muhasebe.arac_yukleme_fisleri.read','muhasebe.palet_fisleri.read','muhasebe.mal_kabul_fisleri.read']];
foreach (['loading_only'=>'records.loading.read','pallet_only'=>'records.pallet.read','ogl_only'=>'siparis.ogl.read','product_orders_only'=>'siparis.urun.read','frugro_only'=>'siparis.frugro.read'] as $key=>$permission) {
    $PROFILLER[$key]=['ad'=>'Ayrıntılı sayfa '.$key,'admin'=>false,'depo'=>'ANONIM TEST DEPO','rol'=>['slug'=>$key,'label'=>$key],'perms'=>['dashboard.read','role.permissions.detailed',$permission]];
}
foreach ($PROFILLER as $pk => $pr) {
    $bilinmeyen = array_diff($pr['perms'], $KATALOG);
    if ($bilinmeyen) {
        fwrite(STDERR, "Profil $pk kataloğda OLMAYAN yetki içeriyor: " . implode(', ', $bilinmeyen) . "\n");
        exit(1);
    }
}

// ── Sayfa → kapı tablosu (hedef sayfanın KENDİ kapısı) ───────────────────
function satir_bul(string $dosya, string $igne): int {
    global $ROOT;
    $satirlar = @file($ROOT . '/' . $dosya);
    if ($satirlar === false) return 0;
    foreach ($satirlar as $i => $s) {
        if (str_contains($s, $igne)) return $i + 1;
    }
    return 0;
}
$PTAK_9 = ['attendance.foremen','attendance.worker_cards','attendance.daily_scan','attendance.daily_reports',
           'attendance.foreman_rates','attendance.entitlements','attendance.foreman_accounts',
           'attendance.foreman_payments','attendance.management_reports'];
$herhangi = fn(array $l): bool => (bool)array_filter($l, fn($p) => can($p));
$GATES = [
    // hedef                => [dosya, kaynakta aranan satır, koşul (profil için), açıklama]
    'index.php'             => ['index.php', "if (!can('dashboard.read')) {",
                                fn() => can('dashboard.read') || first_allowed_page() !== null,
                                "dashboard.read; yoksa first_allowed_page()'e YÖNLENDİRİR, o da null ise 403"],
    'records.php'           => ['records.php', "require_perm('records.loading.read');", fn() => role_matrix_page_can('records','loading'), 'records.loading.read'],
    'record_view.php'       => ['record_view.php', "require_perm('records.read');", fn() => can('records.read'), 'records.read'],
    'cikmalar.php'          => ['cikmalar.php', "require_perm('records.pallet.read');", fn() => role_matrix_page_can('records','pallet'), 'records.pallet.read'],
    'halkayit/index.php'    => ['halkayit/index.php', "require_perm('hks.access');", fn() => can('hks.access'), 'hks.access'],
    'personel_takip.php'    => ['personel_takip.php', "if (!role_pdks_can('hub')) {",
                                fn() => role_pdks_can('hub'),
                                'role_pdks_can(hub): izinli alt sayfa veya genel ekran Gör'],
    'cavus_cari.php'        => ['cavus_cari.php', "require_pdks_cari('accounts');",
                                fn() => is_admin() || can('attendance.foreman_accounts'), 'is_admin() || attendance.foreman_accounts (pdks_cari_can)'],
    'reports.php'           => ['reports.php', "else { require_perm('reports.read'); }", fn() => can('reports.read'), 'reports.read'],
    'hizli_maliyet.php'     => ['hizli_maliyet.php', "require_hizli_maliyet('read');", fn() => can('hizli_maliyet.read') || is_admin(), 'can_hizli_maliyet(read) = hizli_maliyet.read || is_admin(); maliyet.* does NOT open'],
    'hizli_maliyet_form.php' => ['hizli_maliyet_form.php', "require_hizli_maliyet('read');", fn() => can('hizli_maliyet.read') || is_admin(), 'can_hizli_maliyet(read) = hizli_maliyet.read || is_admin(); maliyet.* does NOT open'],
    'beyanlar.php'          => ['beyanlar.php', "if (!beyan_sayfa_erisimi()) forbidden();", fn() => can('beyan.read') || can('beyan.yukleme_bildir') || is_admin(), 'beyan_sayfa_erisimi() = beyan.read || beyan.yukleme_bildir || is_admin() (v498)'],
    'kantar.php'            => ['kantar.php', "require_perm('kantar.read');", fn() => can('kantar.read'), 'kantar.read'],
    'malzeme_stok.php'      => ['malzeme_stok.php', "require_perm('stok.read');", fn() => can('stok.read'), 'stok.read'],
    'stok.php'              => ['stok.php', "require_perm('stok.read');", fn() => can('stok.read'), 'stok.read'],
    'hesap.php'             => ['hesap.php', "require_hesap('read');", fn() => can('hesap.read') || is_admin(), 'hesap_can(read) = is_admin() || hesap.read'],
    'mail.php'              => ['mail.php', "require_mail('read');", fn() => can('mail.read') || can('mail.admin') || is_admin(), 'can_mail(read) = mail.read || mail.admin || is_admin()'],
    'siparisler.php'        => ['siparisler.php', "require_siparis('read');", fn() => role_matrix_page_can('siparis','ogl'), 'siparis.ogl.read or admin'],
    'frugro_siparis.php'    => ['frugro_siparis.php', "require_siparis('read', 'frugro');", fn() => role_matrix_page_can('siparis','frugro'), 'siparis.frugro.read or admin (Frugro satırı öncesi kaydedilmiş rolde OGL\'den türer)'],
    'urun_siparis.php'      => ['urun_siparis.php', "require_siparis('read', 'urun');", fn() => role_matrix_page_can('siparis','urun'), 'siparis.urun.read or admin'],
    'maliyet.php'           => ['maliyet.php', "require_maliyet('read');", fn() => can('maliyet.read') || is_admin(), 'can_maliyet(read) = maliyet.read || is_admin()'],
    'definitions.php'       => ['definitions.php', "require_perm('defs.read');", fn() => can('defs.read'), 'defs.read'],
    'users.php'             => ['users.php', "require_perm('users.admin');", fn() => can('users.admin'), 'users.admin'],
    'roles.php'             => ['roles.php', "require_perm('users.admin');", fn() => can('users.admin'), 'users.admin'],
    'audit.php'             => ['audit.php', "if (!is_admin())", fn() => is_admin(), 'is_admin()'],
    'admin_db_backups.php'  => ['admin_db_backups.php', 'is_admin()', fn() => is_admin(), 'is_admin()'],
    'personel.php'          => ['personel.php', "require_pdks('employees');", fn() => is_admin() || can('attendance.employees'), 'pdks_can(employees) = is_admin() || attendance.employees'],
    'depo_sec.php'          => ['depo_sec.php', 'require_login();', fn() => true, 'yalnız giriş'],
    'logout.php'            => ['logout.php', '<?php', fn() => true, 'kapı yok'],
];
// Alt çubuk aday anahtarı → hedef sayfa (onaylı tasarımın sayfa listesi).
// Uygulamanın nav_alt_sayfalar() kaydıyla BAĞIMSIZ yazılır ve aşağıda onunla
// karşılaştırılır: kayıt değişirse bu test fark eder.
$ANAHTAR_SAYFA = [
    'records' => 'records.php', 'cikma' => 'cikmalar.php', 'beyan' => 'beyanlar.php',
    'kantar' => 'kantar.php', 'hks' => 'halkayit/index.php', 'rapor' => 'reports.php', 'qcost' => 'hizli_maliyet.php',
    'mstok' => 'malzeme_stok.php', 'hesap' => 'hesap.php', 'mail' => 'mail.php', 'siparis' => 'siparisler.php', 'frugro' => 'frugro_siparis.php', 'usip' => 'urun_siparis.php', 'ptak' => 'personel_takip.php',
    'defs' => 'definitions.php', 'users' => 'users.php', 'roles' => 'roles.php',
    'audit' => 'audit.php', 'backup' => 'admin_db_backups.php',
];
$ESKI_YENI_UYG = array_map(fn($x) => $x . '.php', nav_eski_esleri());
// Soğuk başlangıç önceliği: Yüklemeler, Bildirim, Personel, Raporlar, sonra sidebar sırası
$SOGUK = ['records', 'hks', 'ptak', 'rapor', 'siparis', 'frugro', 'usip', 'beyan', 'mail', 'cikma', 'kantar', 'qcost', 'mstok', 'hesap', 'defs', 'users', 'roles', 'audit', 'backup'];
foreach ($ANAHTAR_SAYFA as $k => $hedef) {
    if (!isset($GATES[$hedef])) { fwrite(STDERR, "Aday '$k' → $hedef kapı tablosunda YOK\n"); exit(1); }
}
$gate_meta = [];
$eksik = [];
foreach ($GATES as $hedef => [$dosya, $igne, $kosul, $aciklama]) {
    $satir = satir_bul($dosya, $igne);
    if ($satir === 0) $eksik[] = "$dosya: \"$igne\"";
    $gate_meta[$hedef] = ['ref' => "$dosya:$satir", 'kosul' => $aciklama];
}
if ($eksik) {
    fwrite(STDERR, "Kapı tablosu BAYAT — şu kapı satırları kaynakta bulunamadı:\n  " . implode("\n  ", $eksik) . "\n");
    exit(1);
}

// ── Sayfalar ──────────────────────────────────────────────────────────────
// bolum = sidebar'ın aktif-sayfa mantığındaki karşılık (helpers.php
// render_desktop_sidebar $a_* değişkenleri): çubukta bu hedefe giden bir
// bağlantı VARSA aktif olan O olmalıdır.
$a_ref = satir_bul('config/helpers.php', '$a_home  = ');
$SAYFALAR = [
    'home'        => ['self' => '/index.php',          'kapi' => 'index.php',          'bolum' => 'index.php',          'baslik' => 'Ana Sayfa'],
    'records'     => ['self' => '/records.php',        'kapi' => 'records.php',        'bolum' => 'records.php',        'baslik' => 'Yüklemeler'],
    'record_view' => ['self' => '/record_view.php',    'kapi' => 'record_view.php',    'bolum' => 'records.php',        'baslik' => 'Yükleme Detayı'],
    'cikma_view'  => ['self' => '/record_view.php',    'kapi' => 'record_view.php',    'bolum' => 'cikmalar.php',       'baslik' => 'Çıkma Detayı', 'cikma' => true],
    'cikma_gec'   => ['self' => '/record_view.php',    'kapi' => 'record_view.php',    'bolum' => 'cikmalar.php',       'baslik' => 'Çıkma Detayı (geç ipucu)', 'cikma_gec' => true],
    'cikmalar'    => ['self' => '/cikmalar.php',       'kapi' => 'cikmalar.php',       'bolum' => 'cikmalar.php',       'baslik' => 'Çıkmalar'],
    'beyanlar'    => ['self' => '/beyanlar.php',       'kapi' => 'beyanlar.php',       'bolum' => 'beyanlar.php',       'baslik' => 'Beyanlar'],
    'kantar'      => ['self' => '/kantar.php',         'kapi' => 'kantar.php',         'bolum' => 'kantar.php',         'baslik' => 'Kantar'],
    'hks'         => ['self' => '/halkayit/index.php', 'kapi' => 'halkayit/index.php', 'bolum' => 'halkayit/index.php', 'baslik' => 'Hal Kayıt', 'hks' => true],
    'ptak'        => ['self' => '/personel_takip.php', 'kapi' => 'personel_takip.php', 'bolum' => 'personel_takip.php', 'baslik' => 'Personel Takibi'],
    'ptak_alt'    => ['self' => '/cavus_cari.php',     'kapi' => 'cavus_cari.php',     'bolum' => 'personel_takip.php', 'baslik' => 'Çavuş Cari'],
    'personel'    => ['self' => '/personel.php',       'kapi' => 'personel.php',       'bolum' => 'personel_takip.php', 'baslik' => 'Personel'],
    'reports'     => ['self' => '/reports.php',       'kapi' => 'reports.php',        'bolum' => 'reports.php',        'baslik' => 'Raporlar'],
    'maliyet'     => ['self' => '/maliyet.php',        'kapi' => 'maliyet.php',        'bolum' => 'reports.php',        'baslik' => 'Maliyet'],
    'quick_list'  => ['self' => '/hizli_maliyet.php', 'kapi' => 'hizli_maliyet.php', 'bolum' => 'hizli_maliyet.php', 'baslik' => 'Hızlı Maliyet'],
    'quick_form'  => ['self' => '/hizli_maliyet_form.php', 'kapi' => 'hizli_maliyet_form.php', 'bolum' => 'hizli_maliyet.php', 'baslik' => 'Yeni maliyet hesapla'],
    'hesap'       => ['self' => '/hesap.php',          'kapi' => 'hesap.php',          'bolum' => 'hesap.php',          'baslik' => 'Hesap'],
    'siparisler'  => ['self' => '/siparisler.php',     'kapi' => 'siparisler.php',     'bolum' => 'siparisler.php',     'baslik' => 'Siparişler'],
    'definitions' => ['self' => '/definitions.php',    'kapi' => 'definitions.php',    'bolum' => 'definitions.php',    'baslik' => 'Tanımlar'],
    // v552: anahtarsız (muhasebe) sayfa — sheet'te `.bn-tile-link`; nav_aktif_anahtar() dosya slug'ı döndürür
    'cari_kartlari' => ['self' => '/cari_kartlari.php', 'kapi' => 'cari_kartlari.php', 'bolum' => 'cari_kartlari.php', 'baslik' => 'Cari Kayıt Kartları'],
    // v555: yeni fiş sayfaları (eski records/cikmalar/kantar'ın yeni karşılığı) — anahtarsız muhasebe sayfası; nav_aktif_anahtar() slug döndürür
    'arac_fis'    => ['self' => '/arac_yukleme_fisleri.php', 'kapi' => 'arac_yukleme_fisleri.php', 'bolum' => 'arac_yukleme_fisleri.php', 'baslik' => 'Araç Yükleme Fişleri'],
    'mal_kabul_fis' => ['self' => '/mal_kabul_fisleri.php', 'kapi' => 'mal_kabul_fisleri.php', 'bolum' => 'mal_kabul_fisleri.php', 'baslik' => 'Mal Kabul Fişleri'],
];

// Hal Kayıt sayfasının KENDİ düzen kuralları (iframe'i bottomnav'ın üstünde
// bitirmeye çalışan padding) — kaynaktan birebir alınır, kopyalanmaz.
$hks_src = file_get_contents($ROOT . '/halkayit/index.php');
preg_match('#<style>.*?</style>#s', $hks_src, $m_hks);
$HKS_STYLE = $m_hks[0] ?? '';
if ($HKS_STYLE === '' || !str_contains($HKS_STYLE, 'body.hk-page')) {
    fwrite(STDERR, "halkayit/index.php içindeki <style> bloğu bulunamadı.\n");
    exit(1);
}

$css_inline = file_get_contents($ROOT . '/assets/style.css');
$file_root  = 'file://' . $ROOT . '/';

function sayfa_render(array $s, string $base_css_inline, string $file_root, string $hks_style): string {
    $_SERVER['PHP_SELF']    = $s['self'];
    $_SERVER['SCRIPT_NAME'] = $s['self'];
    $_SERVER['REQUEST_URI'] = $s['self'];
    unset($GLOBALS['_nav_cikma_hint']);
    if (!empty($s['cikma'])) $GLOBALS['_nav_cikma_hint'] = true;   // record_view.php: render_header()'dan ÖNCE

    ob_start();
    render_header($s['baslik']);
    // İpucu sayfa gövdesinde GEÇ kurulursa da alt çubuk doğru bölümü göstermeli
    // (render_footer aktif bölümü çizim anında yeniden okur).
    if (!empty($s['cikma_gec'])) $GLOBALS['_nav_cikma_hint'] = true;
    if (!empty($s['hks'])) {
        echo "<script>document.body.classList.add('hk-page');</script>\n";
        echo '<iframe class="hk-frame" id="son-oge" title="Hal Kayıt Paneli" srcdoc="&lt;p&gt;HKS iframe yer tutucu&lt;/p&gt;"></iframe>' . "\n";
        echo $hks_style . "\n";
    } else {
        echo '<div class="page-head"><h1>' . h($s['baslik']) . "</h1></div>\n";
        for ($i = 1; $i <= 14; $i++) {
            echo '<div class="card" style="padding:16px;margin-bottom:12px">Test kartı ' . $i
               . ' — uzun içerik; sayfa kaydırılabilir olmalı.</div>' . "\n";
        }
        echo '<div class="card" id="son-oge" style="padding:16px">SON ÖĞE — alt çubuğun ÜSTÜNDE görünmeli</div>' . "\n";
    }
    render_footer();
    $html = ob_get_clean();

    $base = base_url();
    // Üretim CSS'ini satır içi yap (tek style.css bağlantısı).
    $html = preg_replace_callback(
        '#<link rel="stylesheet" href="' . preg_quote($base, '#') . 'assets/style\.css\?v=\d+">#',
        fn() => "<style data-kaynak=\"assets/style.css\">\n" . $base_css_inline . "\n</style>",
        $html, 1, $n);
    if ($n !== 1) throw new RuntimeException('style.css bağlantısı bulunamadı (render_header değişti mi?)');
    // Diğer yerel kaynaklar: mutlak file:// yolu (404 → konsol hatası olmasın).
    $html = str_replace(['"' . $base . 'assets/', '"' . $base . 'manifest.json'],
                        ['"' . $file_root . 'assets/', '"' . $file_root . 'manifest.json'], $html);
    return $html;
}

// ── Üret ──────────────────────────────────────────────────────────────────
$muhSayfalar = [];   // v552: muhasebe menüsü sayfa dosyaları (sheet'te anahtarsız bağlantı)
foreach (nav_muhasebe_gruplari() as [, , $__sayfalar]) foreach (array_keys($__sayfalar) as $__dosya) $muhSayfalar[] = (string)$__dosya;
$manifest = [
    'uretim'   => date('c'),
    'anahtar_sayfa' => $ANAHTAR_SAYFA,
    'muhasebe_sayfalar' => $muhSayfalar,
    'eski_yeni' => ['records' => 'arac_yukleme_fisleri.php', 'cikma' => 'palet_fisleri.php', 'kantar' => 'mal_kabul_fisleri.php'],
    'kok'      => $ROOT,
    'app_surum'=> APP_SURUM,
    'gates'    => $gate_meta,
    'aktif_ref'=> "config/helpers.php:$a_ref (render_desktop_sidebar \$a_*)",
    'profiller'=> [],
    'sayfalar' => [],
];
$uyari = [];
if ($ESKI_YENI_UYG !== ['records' => 'arac_yukleme_fisleri.php', 'cikma' => 'palet_fisleri.php', 'kantar' => 'mal_kabul_fisleri.php']) { fwrite(STDERR, "nav_eski_esleri() eşlemesi değişti: " . json_encode($ESKI_YENI_UYG) . "\n"); exit(1); }
foreach ($PROFILLER as $pk => $pr) {
    $PROFIL = ['perms' => $pr['perms'], 'admin' => $pr['admin'], 'depo' => $pr['depo'], 'rol' => $pr['rol']];

    $izinli = [];
    foreach ($GATES as $hedef => [, , $kosul]) $izinli[$hedef] = (bool)$kosul();
    // v552: muhasebe menüsü sayfaları (sheet'te anahtarsız bağlantı) — kapı: yönetici ya da muhasebe.<slug>.read;
    // admin_konsolu grubu yalnız yönetici (muhasebe_roller.php KAPSAM DIŞI). muhasebe_sayfa_can()'dan BAĞIMSIZ yazıldı.
    foreach (nav_muhasebe_gruplari() as $__grup => [, , $__sayfalar]) {
        foreach ($__sayfalar as $__dosya => $_) {
            $izinli[$__dosya] = is_admin() || ($__grup !== 'admin_konsolu' && can('muhasebe.' . substr((string)$__dosya, 0, -4) . '.read'));
        }
    }

    // Çapraz kontroller: tablo, uygulamanın KENDİ fonksiyonlarıyla ayrışmasın.
    $fap = first_allowed_page();
    if ($fap !== null && empty($izinli[$fap])) $uyari[] = "$pk: first_allowed_page()=$fap ama kapı tablosu izin vermiyor";
    if (nav_ptak_gorunur() !== $izinli['personel_takip.php']) $uyari[] = "$pk: nav_ptak_gorunur() ≠ personel_takip.php kapısı";

    // Alt çubuk beklentileri — HEDEF SAYFA KAPILARINDAN türetilir (uygulamanın
    // nav_alt_izinler()'inden DEĞİL), sonra uygulamayla çapraz kontrol edilir.
    // v555: eski girişi (records/cikma/kantar) kendi kapısı geçse de YENİ karşılığı (muhasebe fiş sayfası) görünüyorsa aday DEĞİLDİR.
    // Eşleme uygulamanın nav_eski_esleri()'nden BAĞIMSIZ yazıldı ve aşağıda onunla karşılaştırılır.
    $ESKI_YENI = ['records' => 'arac_yukleme_fisleri.php', 'cikma' => 'palet_fisleri.php', 'kantar' => 'mal_kabul_fisleri.php'];
    $adaylarHam = array_values(array_filter(array_keys($ANAHTAR_SAYFA), fn($k) => $izinli[$ANAHTAR_SAYFA[$k]] && !(isset($ESKI_YENI[$k]) && !empty($izinli[$ESKI_YENI[$k]]))));
    $uygAday = array_keys(array_filter(nav_alt_izinler()));
    if ($uygAday !== $adaylarHam) $uyari[] = "$pk: nav_alt_izinler() [" . implode(',', $uygAday) . "] ≠ kapı tablosu [" . implode(',', $adaylarHam) . "]";
    $home = can('dashboard.read') ? 'index.php' : $fap;
    // nav_alt_model()'in AYNI kuralı (Sprint Alt-Menü-01 düzeltme turu): Ana
    // Sayfa'nın hedeflediği sayfayla aynı href'e sahip aday ikinci bir slot
    // olarak ÇİZİLMEZ (ör. yalnız hesap.read'i olan role Ana Sayfa zaten
    // hesap.php'ye gider) — $adaylar (ham değil) bu yüzden aşağıdaki tüm
    // beklentilerde (slot sayısı, "Diğer", karo listesi) kullanılır.
    $adaylar = array_values(array_filter($adaylarHam, fn($k) => $home === null || $ANAHTAR_SAYFA[$k] !== $home));
    $soguk = array_slice(array_values(array_filter($SOGUK, fn($k) => in_array($k, $adaylar, true))), 0, 4);
    // v552: dokta yeri olmayan (anahtarsız) izinli muhasebe sayfası var mı? (Ana Sayfa'nın hedefi hariç — dokta zaten oraya gider.)
    // "Diğer" düğmesi/sheet bu durumda aday sayısından BAĞIMSIZ olarak çıkmalıdır.
    $sheetEk = (bool)array_filter($muhSayfalar, fn($d) => !empty($izinli[$d]) && $d !== $home);

    $manifest['profiller'][$pk] = [
        'ad' => $pr['ad'], 'admin' => $pr['admin'], 'depo' => $pr['depo'],
        'perms' => $pr['perms'], 'izinli' => $izinli,
        'first_allowed_page' => $fap,
        // Ana Sayfa: dashboard.read → index.php, yoksa first_allowed_page(), o da yoksa HİÇ
        'home_hedef' => $home,
        'adaylar' => $adaylar,
        'sheet_ek' => $sheetEk,
        'soguk_slotlar' => $soguk,
        'cubuk_beklenen' => $home !== null || $adaylar !== [],
    ];

    foreach ($SAYFALAR as $sk => $s) {
        if (empty($izinli[$s['kapi']])) continue;   // profil bu sayfayı açamaz → render yok
        // index.php dashboard.read olmadan YÖNLENDİRİR — içerik basmaz.
        if ($s['kapi'] === 'index.php' && !can('dashboard.read')) continue;
        $html = sayfa_render($s, $css_inline, $file_root, $HKS_STYLE);
        $dosya = "{$pk}__{$sk}.html";
        file_put_contents($OUT . '/' . $dosya, $html);
        $manifest['sayfalar'][] = [
            'profil' => $pk, 'sayfa' => $sk, 'dosya' => $dosya,
            'yol' => ltrim($s['self'], '/'), 'bolum' => $s['bolum'],
            'anahtar' => $s['bolum'] === 'index.php' ? 'home' : (array_search($s['bolum'], $ANAHTAR_SAYFA, true) ?: (in_array($s['bolum'], $muhSayfalar, true) ? substr($s['bolum'], 0, -4) : null)),   // muhasebe sayfası: slug (nav_aktif_anahtar)
            'depo_rengi' => $pr['depo'] !== null ? depot_color($pr['depo']) : null,
        ];
    }

    // ── Çerez senaryoları ('asya_nav' — sunucu tarafı okuma) ──────────────
    // Yalnız operatör: sahte (yasak/bilinmeyen anahtar), geçerli (sabit +
    // sıra) ve BAŞKA kullanıcının çerezi. Beklenen slotlar elle yazılır.
    if ($pk === 'admin') {
        foreach (['quick_list', 'quick_form'] as $quickPage) {
            $s = $SAYFALAR[$quickPage];
            // v555: admin'de eski 'records' adayı artık YOK (yeni karşılığı Araç Yükleme Fişleri görünür) → eski çerezdeki 'records' sessizce atılır, slotları 'rapor' doldurur.
            $_COOKIE['asya_nav'] = 'u:1;s:records,hks,ptak;p:qcost';
            $html = sayfa_render($s, $css_inline, $file_root, $HKS_STYLE);
            unset($_COOKIE['asya_nav']);
            $dosya = "admin__{$quickPage}__pinned.html";
            file_put_contents($OUT . '/' . $dosya, $html);
            $manifest['sayfalar'][] = [
                'profil' => $pk, 'sayfa' => $quickPage . '+pinned', 'dosya' => $dosya,
                'yol' => ltrim($s['self'], '/'), 'bolum' => 'hizli_maliyet.php', 'anahtar' => 'qcost',
                'depo_rengi' => depot_color($pr['depo']),
                'senaryo' => ['ad' => 'qcost_pinned', 'cerez' => 'u:1;s:records,hks,ptak;p:qcost', 'slotlar' => ['qcost', 'hks', 'ptak', 'rapor'], 'sabit' => ['qcost']],
            ];
        }
    }
    if ($pk === 'operator') {
        $senaryolar = [
            'cerez_sahte'  => ['u:1;s:users,qcost,xyz,kantar,audit;p:qcost,roles,backup,home,more,../index', ['kantar', 'records', 'hks', 'ptak'], []],
            'cerez_gecerli'=> ['u:1;s:beyan,kantar,records,mstok;p:hesap', ['hesap', 'beyan', 'kantar', 'records'], ['hesap']],
            'cerez_baska'  => ['u:2;s:beyan,kantar,records,mstok;p:hesap', $soguk, []],
            'cerez_bozuk'  => [str_repeat('s:records,', 60), $soguk, []],
        ];
        foreach ($senaryolar as $sen => [$cerez, $bekSlot, $bekSabit]) {
            $s = $SAYFALAR['kantar'];
            $_COOKIE['asya_nav'] = $cerez;
            $html = sayfa_render($s, $css_inline, $file_root, $HKS_STYLE);
            unset($_COOKIE['asya_nav']);
            $dosya = "{$pk}__kantar__{$sen}.html";
            file_put_contents($OUT . '/' . $dosya, $html);
            $manifest['sayfalar'][] = [
                'profil' => $pk, 'sayfa' => "kantar+$sen", 'dosya' => $dosya,
                'yol' => 'kantar.php', 'bolum' => 'kantar.php', 'anahtar' => 'kantar',
                'depo_rengi' => depot_color($pr['depo']),
                'senaryo' => ['ad' => $sen, 'cerez' => $cerez, 'slotlar' => $bekSlot, 'sabit' => $bekSabit],
            ];
        }
    }
}
if ($uyari) {
    fwrite(STDERR, "Kapı tablosu uygulamayla AYRIŞIYOR:\n  " . implode("\n  ", $uyari) . "\n");
    exit(1);
}
file_put_contents($OUT . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo count($manifest['sayfalar']) . " sayfa yazıldı → $OUT\n";
