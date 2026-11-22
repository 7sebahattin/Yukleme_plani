<?php
// =========================================================
// scripts/puantaj_cizelgesi_smoke.php — Aylık Puantaj Çizelgesi (v552 + v553 elle gün işareti) testi
//
// SADECE CLI. Canlı DB'ye dokunmaz (bellek içi SQLite). Bölümler:
//   1) statik kapılar (yetki, GET-only, rota, nav, sw.js, hub kartı)
//   2) saf hesap (ay/gün, giriş-çıkış eşleme, pivot, özet, arama, dışa aktarım satırları, XLSX geri okuma)
//   3) gerçek sayfa çıktısı (scripts/_puantaj_cizelgesi_harness.php alt süreci): depo/pasif, sekmeler,
//      yetki matrisi, CSV/XLSX + audit, yazdırma, şema yokken nazik hata
//   4) v553 elle işaret: şema fonksiyonu, saf doğrulayıcılar, yazma ucu kapıları (CSRF/yetki/kod/tarih/depo),
//      upsert + temizle + audit, işaretin otomatik hücreyi ezmesi + özet sayıları, dışa aktarım, tablo yokken çizelge
//   php scripts/puantaj_cizelgesi_smoke.php   → çıkış kodu 0 = hepsi geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI'); }
$ROOT = dirname(__DIR__);

$GRANTS = []; $ADMIN = false;
function can(string $p): bool { global $GRANTS; return in_array($p, $GRANTS, true); }
function is_admin(): bool { global $ADMIN; return $ADMIN; }
function pdks_tablo_var(PDO $pdo, string $t): bool { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 0"); return true; } catch (PDOException $e) { return false; } }
require_once $ROOT . '/config/role_pdks_matrix.php';
require_once $ROOT . '/config/puantaj_cizelgesi.php';

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-100s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $f): string { global $ROOT; return (string)file_get_contents($ROOT . '/' . $f); }

// ───────────────────────── 1. STATİK ─────────────────────────
echo "\n=== 1. Dosyalar, söz dizimi, kapılar ===\n";
foreach (['config/puantaj_cizelgesi.php', 'puantaj_cizelgesi.php', 'puantaj_cizelgesi_yazdir.php', 'assets/puantaj_cizelgesi.css', 'assets/puantaj_cizelgesi.js', 'assets/print_puantaj_cizelgesi.css'] as $f) {
    ok("$f var", is_file("$ROOT/$f"));
}
foreach (['config/puantaj_cizelgesi.php', 'puantaj_cizelgesi.php', 'puantaj_cizelgesi_yazdir.php'] as $f) {
    exec('php -l ' . escapeshellarg("$ROOT/$f") . ' 2>&1', $o, $rc); ok("php -l $f", $rc === 0, implode(' ', $o)); $o = [];
}
$sayfa = oku('puantaj_cizelgesi.php'); $yaz = oku('puantaj_cizelgesi_yazdir.php'); $lib = oku('config/puantaj_cizelgesi.php');
ok('sayfa: require_login + role_pdks_require_request()', str_contains($sayfa, 'require_login()') && str_contains($sayfa, 'role_pdks_require_request();'));
ok('sayfa: sekme yoksa forbidden (pc_sekmeler boş)', (bool)preg_match('/\$sekmeler = pc_sekmeler\(\);\s*if \(!\$sekmeler\) forbidden\(/', $sayfa));
ok("sayfa: csv/xlsx → require_perm('reports.export')", (bool)preg_match("/if \(\\\$csvIst \|\| \\\$xlsxIst\) require_perm\('reports\.export'\);/", $sayfa));
ok('sayfa: export_audit xlsx + csv (pdks / puantaj_cizelgesi)', substr_count($sayfa, "export_audit('pdks', 'puantaj_cizelgesi'") === 2 && str_contains($sayfa, "'xlsx'") && str_contains($sayfa, "'csv'"));
ok('sayfa: XLSX kolu CSV kolundan ÖNCE', strpos($sayfa, 'xlsx_indir(') !== false && strpos($sayfa, 'xlsx_indir(') < strpos($sayfa, 'text/csv'));
ok("sayfa: CSV BOM + fputcsv ';'", str_contains($sayfa, '\\xEF\\xBB\\xBF') && str_contains($sayfa, "';', '\"', '\\\\'"));
ok('sayfa: v553 — çizelge sayfaları $_POST / csrf_check / <form method=post> KULLANMAZ (yazma ucu ayrı dosya; sayfa yalnız csrf_token() basar)', !preg_match('/\$_POST|csrf_check|method="post"/i', $sayfa . $yaz . $lib) && !str_contains($yaz . $lib, 'csrf_token'));
ok('sayfa: kayıt yazan SQL YOK (INSERT/UPDATE/DELETE/ALTER/CREATE) — çizelge okuyucuları yalnız SELECT', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\b/', $lib . $sayfa . $yaz));
ok('sayfa: render_header + render_footer; yazdırma render_header ÇAĞIRMAZ',
    str_contains($sayfa, "render_header('Aylık Puantaj Çizelgesi')") && !preg_match('/render_header\s*\(/', $yaz) && str_contains($yaz, 'render_print_page_start('));
ok('yazdır: role_pdks_require_request + sekme kapısı', str_contains($yaz, 'role_pdks_require_request();') && str_contains($yaz, 'pc_sekmeler()') && str_contains($yaz, 'forbidden('));
ok('yazdır: yatay A4 landscape', str_contains($yaz, "'landscape'"));
ok('kütüphane: depo_sql_in(\'e.depo\') pozisyonel + status=aktif varsayılan', str_contains($lib, "depo_sql_in('e.depo')") && str_contains($lib, "e.status = 'aktif'"));
ok('kütüphane: günlük işçi şema kapıları (pdks_gunluk_sema_hazir + faz8a)', str_contains($lib, 'pdks_gunluk_sema_hazir($pdo)') && str_contains($lib, 'pdks_gunluk_faz8a_sema_hazir($pdo)'));
ok('kütüphane: toplu döküm finansal=false', str_contains($lib, 'pdks_rapor_cavus_toplu_dokum($ay, $depo !== \'\' ? $depo : null, null, false, $pdo)'));
ok('kütüphane: tablo yoksa pdks_tablo_var + nazik hata (500 yok)', str_contains($lib, "pdks_tablo_var(\$pdo, 'employees')") && str_contains($lib, 'catch (Throwable $e)'));

echo "\n=== 1b. Rota / yetki matrisi / nav / sw.js / hub kartı ===\n";
$pages = role_pdks_pages(); $routes = role_pdks_routes();
ok("matris: 'monthly_timesheet' satırı (v553: read + edit; eski roller yalnız read; legacy daily_reports+employees)", isset($pages['monthly_timesheet']) && $pages['monthly_timesheet']['actions'] === ['read', 'edit'] && ($pages['monthly_timesheet']['legacy_actions'] ?? null) === ['read'] && $pages['monthly_timesheet']['legacy'] === ['daily_reports', 'employees']);
ok('matris: üç rota kayıtlı (kapı UYGULANSIN; yazma ucu dahil)', ($routes['puantaj_cizelgesi.php'] ?? '') === 'monthly_timesheet' && ($routes['puantaj_cizelgesi_yazdir.php'] ?? '') === 'monthly_timesheet' && ($routes['puantaj_isaret_kaydet.php'] ?? '') === 'monthly_timesheet');
ok("matris: POST puantaj_isaret_kaydet.php = ['monthly_timesheet','edit']; çizelge sayfalarına POST hâlâ reddedilir", role_pdks_request_action('puantaj_isaret_kaydet.php', 'POST') === ['monthly_timesheet', 'edit'] && role_pdks_request_action('puantaj_cizelgesi_yazdir.php', 'POST') === null && role_pdks_request_action('puantaj_cizelgesi.php', 'POST') === null);
ok('matris: GET = read, POST = reddedilir', role_pdks_request_action('puantaj_cizelgesi.php', 'GET') === ['monthly_timesheet', 'read'] && role_pdks_request_action('puantaj_cizelgesi.php', 'POST', [], ['action' => 'x']) === null);
ok('matris: katalog anahtarları attendance.monthly_timesheet.read + .edit', isset(role_pdks_catalog()['attendance.monthly_timesheet.read'], role_pdks_catalog()['attendance.monthly_timesheet.edit']));
$e1 = role_pdks_expand_permissions(['attendance.daily_reports']);
$e2 = role_pdks_expand_permissions(['attendance.employees']);
$e3 = role_pdks_expand_permissions(['attendance.scan']);
ok('eski rol: daily_reports → sayfa Gör', in_array('attendance.monthly_timesheet.read', $e1, true));
ok('eski rol: employees → sayfa Gör', in_array('attendance.monthly_timesheet.read', $e2, true));
ok('eski rol: yalnız scan → sayfa YOK', !in_array('attendance.monthly_timesheet.read', $e3, true));
ok("eski roller 'edit' ALMAZ (daily_reports / employees / ikisi birden / tüm eski paketler)", !in_array('attendance.monthly_timesheet.edit', $e1, true) && !in_array('attendance.monthly_timesheet.edit', $e2, true)
    && !in_array('attendance.monthly_timesheet.edit', role_pdks_expand_permissions(['attendance.employees', 'attendance.daily_reports', 'attendance.entitlements', 'attendance.admin']), true));
$GRANTS = $e2;
ok("eski rol (employees): role_pdks_can('monthly_timesheet') AÇIK, 'edit' KAPALI (legacy yedeği de açmaz)", role_pdks_can('monthly_timesheet') && !role_pdks_can('monthly_timesheet', 'edit'));
$GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.monthly_timesheet.edit', 'attendance.employees.read']);
ok("ayrıntılı rol: açıkça seçilen 'edit' yazabilir; yalnız read seçen yazamaz; read olmadan edit işe yaramaz",
    role_pdks_can('monthly_timesheet', 'edit') && role_pdks_can('employees')
    && !(function () { global $GRANTS; $GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.read']); return role_pdks_can('monthly_timesheet', 'edit'); })()
    && !(function () { global $GRANTS; $GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.edit']); return role_pdks_can('monthly_timesheet', 'edit'); })());
$GRANTS = [];
$d = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.read']);
ok('yeni rol: yalnız sayfa Gör eski paketleri (daily_reports/employees) AÇMAZ', !in_array('attendance.daily_reports', $d, true) && !in_array('attendance.employees', $d, true));
$GRANTS = $d;
ok('yeni rol: hub açılır, ama sekme yok → pc_sekmeler boş', role_pdks_can('hub') && pc_sekmeler() === []);
$GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read']);
ok('yeni rol: + Personel kartları Gör → yalnız personel sekmesi', pc_sekmeler() === ['personel']);
$GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.daily_reports.read']);
ok('yeni rol: + Günlük puantaj Gör → yalnız günlük sekmesi', pc_sekmeler() === ['gunluk']);
$GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.employees.read', 'attendance.daily_reports.read']);
ok('yeni rol: sekme izni var ama sayfa Gör yok → sayfa kapalı', pc_sekmeler() === []);
$GRANTS = $e1;
ok('eski rol (daily_reports): yalnız günlük sekmesi', pc_sekmeler() === ['gunluk']);
$GRANTS = []; $ADMIN = true;
ok('yönetici: iki sekme (personel önce)', pc_sekmeler() === ['personel', 'gunluk']);
$ADMIN = false; $GRANTS = [];
ok('izinsiz: sekme yok', pc_sekmeler() === []);
ok('pc_sekme_sec: geçersiz istek → ilk izinli; boş liste → null', pc_sekme_sec('x', ['gunluk']) === 'gunluk' && pc_sekme_sec('gunluk', ['personel', 'gunluk']) === 'gunluk' && pc_sekme_sec('x', []) === null);

$helpers = str_replace("\r\n", "\n", oku('config/helpers.php'));
$a = strpos($helpers, 'function nav_ptak_sayfalari(): array {'); $b = strpos($helpers, "\n}\n", $a);
eval(substr($helpers, $a, $b - $a + 3));
ok('nav_ptak_sayfalari: puantaj_cizelgesi.php VAR, yazdır sayfası YOK', in_array('puantaj_cizelgesi.php', nav_ptak_sayfalari(), true) && !in_array('puantaj_cizelgesi_yazdir.php', nav_ptak_sayfalari(), true));

$sw = oku('sw.js');
ok('sw.js: puantaj_cizelgesi(+_yazdir) önbellek dışı (respondWith ÖNCESİ)', (bool)preg_match('#if \(/\\\\/puantaj_cizelgesi\(_yazdir\)\?\\\\\.php\(\\\\/\|\$\)/\.test\(u\.pathname\)\) return;#', $sw) && strpos($sw, 'puantaj_cizelgesi') < strpos($sw, 'e.respondWith('));
$rx = '#/puantaj_cizelgesi(_yazdir)?\.php(/|$)#';
ok('sw.js regex davranışı', preg_match($rx, '/puantaj_cizelgesi.php') && preg_match($rx, '/a/puantaj_cizelgesi_yazdir.php') && preg_match($rx, '/puantaj_cizelgesi.php/x') && !preg_match($rx, '/puantaj_cizelgesi.phps') && !preg_match($rx, '/x_puantaj_cizelgesi.html'));

$takip = oku('personel_takip.php');
ok("hub: 'Puantaj Çizelgesi' kartı düz home-card + pc_sekmeler() (sayfanın açılabileceği sekme var) ile sarılı",
    (bool)preg_match('/<\?php if \(!empty\(pc_sekmeler\(\)\)\): \?>\s*<a href="puantaj_cizelgesi\.php" class="home-card">.*?Puantaj Çizelgesi.*?Aylık puantaj · personel ve günlük işçi.*?<\?php endif; \?>/s', $takip));
ok('hub: $p_gunluk bayrağı monthly_timesheet\'i de sayar', (bool)preg_match('/\$p_gunluk\s*=.*role_pdks_can\(\'monthly_timesheet\'\)/', $takip));
ok('hub: toplam düz home-card = 12', substr_count($takip, 'class="home-card"') === 12);

preg_match("/define\('APP_SURUM', '(v\d+)'\)/", oku('config/helpers.php'), $m1);
preg_match("/CACHE_NAME\s*=\s*'yukleme-plani-(v\d+)'/", $sw, $m2);
preg_match('/\*\*SW Cache:\*\* `yukleme-plani-(v\d+)`/', oku('CLAUDE.md'), $m3);
ok('sürüm: APP_SURUM = sw.js CACHE_NAME = CLAUDE.md (' . ($m1[1] ?? '?') . ' / ' . ($m2[1] ?? '?') . ' / ' . ($m3[1] ?? '?') . ')', isset($m1[1], $m2[1], $m3[1]) && $m1[1] === $m2[1] && $m2[1] === $m3[1]);

// ───────────────────────── 2. SAF HESAP ─────────────────────────
echo "\n=== 2. Ay / gün hesabı ===\n";
ok('pc_ay_dogrula: geçerli/geçersiz', pc_ay_dogrula('2026-11') === '2026-11' && pc_ay_dogrula('2026-13', '2026-01') === '2026-01' && pc_ay_dogrula('abc', '2026-01') === '2026-01' && pc_ay_dogrula('1999-05', '2026-01') === '2026-01' && pc_ay_dogrula('', '2026-01') === '2026-01' && pc_ay_dogrula(' 2026-02 ') === '2026-02');
ok('gün sayısı: Şubat 2026=28, 2028=29 (artık), 2100=28, Nisan=30, Aralık=31', pc_ay_gun_sayisi('2026-02') === 28 && pc_ay_gun_sayisi('2028-02') === 29 && pc_ay_gun_sayisi('2100-02') === 28 && pc_ay_gun_sayisi('2026-04') === 30 && pc_ay_gun_sayisi('2026-12') === 31);
ok('pc_ay_gez: yıl dönüşü', pc_ay_gez('2026-12', 1) === '2027-01' && pc_ay_gez('2026-01', -1) === '2025-12' && pc_ay_gez('2026-03', 0) === '2026-03');
ok('pc_ay_basligi', pc_ay_basligi('2026-11') === 'Kasım 2026' && pc_ay_basligi('2026-02') === 'Şubat 2026');
$g = pc_gunler('2026-11', '2026-11-22');
ok('pc_gunler(2026-11): 30 gün, 1 Kasım = Pazar, bugün işaretli tek gün', count($g) === 30 && $g[0]['ad'] === 'Paz' && $g[0]['hg'] === 7 && count(array_filter($g, fn($x) => $x['bugun'])) === 1 && $g[21]['bugun'] === true);
ok('pc_gunler: hafta sonu = Cmt+Paz (2026-11: 4 Cmt + 5 Paz = 9)', count(array_filter($g, fn($x) => $x['hs'])) === 9 && $g[6]['ad'] === 'Cmt' && $g[6]['hs'] === true && $g[1]['hs'] === false);
ok('pc_gunler(2028-02): 29 gün; bugün başka ayda → işaretli gün yok', count(pc_gunler('2028-02', '2026-11-22')) === 29 && !array_filter(pc_gunler('2028-02', '2026-11-22'), fn($x) => $x['bugun']));
ok('pc_sayi_fmt: 8,5 · 8 · 1.234,5 · 0', pc_sayi_fmt(8.5) === '8,5' && pc_sayi_fmt(8.0) === '8' && pc_sayi_fmt(1234.54) === '1.234,5' && pc_sayi_fmt(0) === '0');
ok('pc_url: boş değerler atılır', pc_url('x.php', ['a' => '1', 'b' => '', 'c' => null]) === 'x.php?a=1' && pc_url('x.php', ['b' => '']) === 'x.php');

echo "\n=== 2b. Giriş-çıkış eşleme ===\n";
$ev = fn(array $l) => array_map(fn($x) => ['t' => $x[0], 'z' => $x[1]], $l);
$r = pc_olay_cozumle($ev([['GIRIS', '2026-11-03 08:00:00'], ['CIKIS', '2026-11-03 17:30:00']]));
ok('tek çift: 9,5 saat, X', abs($r['2026-11-03']['saat'] - 9.5) < 1e-9 && $r['2026-11-03']['tam'] === 1 && $r['2026-11-03']['acik'] === 0 && $r['2026-11-03']['ilk'] === '08:00' && $r['2026-11-03']['son'] === '17:30');
$r = pc_olay_cozumle($ev([['GIRIS', '2026-11-04 08:00:00'], ['CIKIS', '2026-11-04 12:00:00'], ['GIRIS', '2026-11-04 13:00:00'], ['CIKIS', '2026-11-04 17:00:00']]));
ok('çift giriş/çıkış: 4+4 = 8 saat, 2 tam çift', abs($r['2026-11-04']['saat'] - 8.0) < 1e-9 && $r['2026-11-04']['tam'] === 2 && $r['2026-11-04']['son'] === '17:00');
$r = pc_olay_cozumle($ev([['GIRIS', '2026-11-05 08:00:00']]));
ok('çıkışsız giriş: eksik', $r['2026-11-05']['acik'] === 1 && $r['2026-11-05']['tam'] === 0 && pc_hucre_personel($r['2026-11-05'])['k'] === '!');
$r = pc_olay_cozumle($ev([['GIRIS', '2026-11-12 22:00:00'], ['CIKIS', '2026-11-13 06:00:00']]));
ok('gece vardiyası: süre GİRİŞ gününe (12) yazılır, 13 yok', abs($r['2026-11-12']['saat'] - 8.0) < 1e-9 && !isset($r['2026-11-13']));
$r = pc_olay_cozumle($ev([['GIRIS', '2026-11-06 08:00:00'], ['GIRIS', '2026-11-06 09:00:00'], ['CIKIS', '2026-11-06 17:00:00']]));
ok('arka arkaya iki giriş: ilki eksik, ikincisi çıkışla eşlenir (8 saat)', $r['2026-11-06']['acik'] === 1 && $r['2026-11-06']['tam'] === 1 && abs($r['2026-11-06']['saat'] - 8.0) < 1e-9 && pc_hucre_personel($r['2026-11-06'])['k'] === '!');
$r = pc_olay_cozumle($ev([['CIKIS', '2026-11-07 06:00:00'], ['GIRIS', '2026-11-07 08:00:00'], ['CIKIS', '2026-11-07 16:00:00']]));
ok('girişsiz çıkış yok sayılır', abs($r['2026-11-07']['saat'] - 8.0) < 1e-9 && $r['2026-11-07']['olay'] === 2);
$r = pc_olay_cozumle($ev([['GIRIS', '2026-11-08 08:00:00'], ['CIKIS', '2026-11-09 20:00:00']]));
ok('16 saati aşan çift eşleşmez: giriş eksik, süre 0', $r['2026-11-08']['acik'] === 1 && $r['2026-11-08']['saat'] == 0 && !isset($r['2026-11-09']));
ok('boş / tanımsız olay: boş sonuç', pc_olay_cozumle([]) === [] && pc_olay_cozumle($ev([['XYZ', '2026-11-01 08:00:00'], ['GIRIS', 'bozuk']])) === []);
ok('küçük harf tip (giris/cikis) kabul', pc_olay_cozumle($ev([['giris', '2026-11-03 08:00:00'], ['cikis', '2026-11-03 10:00:00']]))['2026-11-03']['tam'] === 1);

echo "\n=== 2c. Personel pivotu, özet, arama ===\n";
$cal = [
    ['id' => 1, 'full_name' => 'Örnek Personel A', 'personnel_no' => 'P001', 'department' => 'Paketleme'],
    ['id' => 2, 'full_name' => 'İsmail Örnek', 'personnel_no' => 'P002', 'department' => ''],
    ['id' => 3, 'full_name' => 'Örnek Personel C', 'personnel_no' => '', 'department' => ''],
];
$olaylar = [   // karışık sırada verilir (kur() sıralar)
    ['employee_id' => 1, 'event_type' => 'CIKIS', 'server_event_time' => '2026-11-03 17:30:00'],
    ['employee_id' => 1, 'event_type' => 'GIRIS', 'server_event_time' => '2026-11-03 08:00:00'],
    ['employee_id' => 1, 'event_type' => 'GIRIS', 'server_event_time' => '2026-11-04 08:00:00'],
    ['employee_id' => 1, 'event_type' => 'CIKIS', 'server_event_time' => '2026-11-04 12:00:00'],
    ['employee_id' => 1, 'event_type' => 'GIRIS', 'server_event_time' => '2026-11-04 13:00:00'],
    ['employee_id' => 1, 'event_type' => 'CIKIS', 'server_event_time' => '2026-11-04 17:00:00'],
    ['employee_id' => 1, 'event_type' => 'GIRIS', 'server_event_time' => '2026-11-05 08:00:00'],
    ['employee_id' => 1, 'event_type' => 'GIRIS', 'server_event_time' => '2026-11-30 22:00:00'],   // ay sonu: çıkış sonraki ayda → eksik
    ['employee_id' => 2, 'event_type' => 'GIRIS', 'server_event_time' => '2026-11-03 09:00:00'],
    ['employee_id' => 2, 'event_type' => 'CIKIS', 'server_event_time' => '2026-11-03 17:00:00'],
    ['employee_id' => 2, 'event_type' => 'GIRIS', 'server_event_time' => '2026-10-31 09:00:00'],   // ay dışı: yok sayılır
];
$t = pc_tablo_personel_kur('2026-11', $cal, $olaylar);
$A = $t['satirlar'][0]; $I = $t['satirlar'][1]; $C = $t['satirlar'][2];
ok('tablo: 3 satır, 30 gün, sıra korunur', count($t['satirlar']) === 3 && count($t['gunler']) === 30 && $A['ad'] === 'Örnek Personel A');
ok('A: alt = sicil · departman', $A['alt'] === 'P001 · Paketleme' && $C['alt'] === '');
ok('A: 3 Kasım X 9,5; 4 Kasım X 8; 5 Kasım !; 30 Kasım ! (ay sonu)', $A['hucre']['2026-11-03']['k'] === 'X' && abs($A['hucre']['2026-11-03']['d'] - 9.5) < 1e-9 && abs($A['hucre']['2026-11-04']['d'] - 8) < 1e-9 && $A['hucre']['2026-11-05']['k'] === '!' && $A['hucre']['2026-11-30']['k'] === '!');
ok('A özet: çalışılan gün 2, toplam 17,5 saat, eksik 2', $A['ozet']['gun'] === 2 && abs($A['ozet']['toplam'] - 17.5) < 1e-9 && $A['ozet']['eksik'] === 2);
ok('İsmail: ay dışı olay yok sayıldı (yalnız 3 Kasım X 8)', count($I['hucre']) === 1 && $I['ozet']['gun'] === 1 && $I['ozet']['eksik'] === 0);
ok('C: kayıtsız satır özetleri 0 (v553: izin/rapor/devamsız dahil)', $C['hucre'] === [] && $C['ozet'] === ['gun' => 0, 'toplam' => 0.0, 'eksik' => 0, 'izin' => 0, 'rapor' => 0, 'devamsiz' => 0]);
ok('o gün çalışan sayısı: 3 Kasım=2, 4=1, 5=1 (eksikli de sayılır), 6=0', $t['gun_say']['2026-11-03'] === 2 && $t['gun_say']['2026-11-04'] === 1 && $t['gun_say']['2026-11-05'] === 1 && $t['gun_say']['2026-11-06'] === 0);
ok('genel: 3 kişi · 3 gün · 25,5 saat · 2 eksik', $t['genel']['kisi'] === 3 && $t['genel']['gun'] === 3 && abs($t['genel']['toplam'] - 25.5) < 1e-9 && $t['genel']['eksik'] === 2);
$tq = pc_tablo_personel_kur('2026-11', $cal, $olaylar, 'ISMAIL');
ok("arama: 'ISMAIL' → 'İsmail Örnek' (Türkçe duyarsız), toplamlar filtreyle yeniden hesaplanır", count($tq['satirlar']) === 1 && $tq['genel']['kisi'] === 1 && abs($tq['genel']['toplam'] - 8) < 1e-9);
ok("arama: sicil 'p001' ve departman 'paket' bulur; olmayan → boş", count(pc_tablo_personel_kur('2026-11', $cal, $olaylar, 'p001')['satirlar']) === 1 && count(pc_tablo_personel_kur('2026-11', $cal, $olaylar, 'paket')['satirlar']) === 1 && pc_tablo_personel_kur('2026-11', $cal, $olaylar, 'zzz')['satirlar'] === []);
$t2 = pc_tablo_personel_kur('2028-02', $cal, []);
ok('Şubat 2028 (artık yıl): 29 gün sütunu, boş veriyle hata yok', count($t2['gunler']) === 29 && $t2['genel']['toplam'] == 0);

echo "\n=== 2d. Günlük işçi pivotu (çavuş × gün) ===\n";
$rapor = [
    ['foreman_id' => 5, 'cavus_adi' => 'Örnek Çavuş B', 'cavus_kodu' => 'C002', 'tarih' => '2026-11-03', 'toplam_isci' => 10, 'eksik_cikis' => 0],
    ['foreman_id' => 5, 'cavus_adi' => 'Örnek Çavuş B', 'cavus_kodu' => 'C002', 'tarih' => '2026-11-03', 'toplam_isci' => 4, 'eksik_cikis' => 1],   // aynı gün ikinci oturum → toplanır
    ['foreman_id' => 5, 'cavus_adi' => 'Örnek Çavuş B', 'cavus_kodu' => 'C002', 'tarih' => '2026-11-04', 'toplam_isci' => 7, 'eksik_cikis' => 0],
    ['foreman_id' => 2, 'cavus_adi' => 'Çağlar Çavuş', 'cavus_kodu' => 'C001', 'tarih' => '2026-11-03', 'toplam_isci' => 6, 'eksik_cikis' => 0],
    ['foreman_id' => 2, 'cavus_adi' => 'Çağlar Çavuş', 'cavus_kodu' => 'C001', 'tarih' => '2026-11-10', 'toplam_isci' => 0, 'eksik_cikis' => 0],   // işçisiz boş oturum → hücre değil
    ['foreman_id' => 2, 'cavus_adi' => 'Çağlar Çavuş', 'cavus_kodu' => 'C001', 'tarih' => '2026-10-31', 'toplam_isci' => 9, 'eksik_cikis' => 0],  // ay dışı
];
$g = pc_tablo_gunluk_kur('2026-11', $rapor);
ok('2 çavuş; ad sırası (Ç < Ö, Türkçe duyarsız)', count($g['satirlar']) === 2 && $g['satirlar'][0]['id'] === 2 && $g['satirlar'][1]['id'] === 5);
$cb = $g['satirlar'][1];
ok('aynı gün iki oturum toplanır: 3 Kasım = 14 işçi, ! (1 eksik)', $cb['hucre']['2026-11-03']['d'] === 14 && $cb['hucre']['2026-11-03']['k'] === '!' && $cb['hucre']['2026-11-03']['acik'] === 1);
ok('çavuş B özet: 2 gün · 21 işçi-gün · 1 eksik', $cb['ozet'] === ['gun' => 2, 'toplam' => 21, 'eksik' => 1]);
ok('çavuş (Çağlar): boş oturum ve ay dışı hücre YOK → 1 hücre', count($g['satirlar'][0]['hucre']) === 1 && $g['satirlar'][0]['ozet']['toplam'] === 6);
ok('gün toplamı (işçi): 3 Kasım = 20, 4 Kasım = 7; genel 27 işçi-gün, 1 eksik', $g['gun_say']['2026-11-03'] === 20 && $g['gun_say']['2026-11-04'] === 7 && $g['genel']['toplam'] === 27 && $g['genel']['eksik'] === 1 && $g['genel']['kisi'] === 2);
ok("günlük arama: 'cagl' → Çağlar Çavuş", count(pc_tablo_gunluk_kur('2026-11', $rapor, 'cagl')['satirlar']) === 1);

echo "\n=== 2e. Hücre gösterimi (renk + HARF) ===\n";
[$c1, $i1, $t1] = pc_hucre_goster('personel', ['k' => 'X', 'd' => 8.5, 'ilk' => '08:00', 'son' => '17:00', 'acik' => 0]);
[$c2, $i2] = pc_hucre_goster('personel', ['k' => '!', 'd' => 0.0, 'ilk' => '08:00', 'son' => null, 'acik' => 1]);
[$c3, $i3] = pc_hucre_goster('personel', null);
[$c4, $i4] = pc_hucre_goster('gunluk', ['k' => '!', 'd' => 12, 'ilk' => null, 'son' => null, 'acik' => 2]);
[$c5, $i5] = pc_hucre_goster('gunluk', ['k' => 'X', 'd' => 7, 'ilk' => null, 'son' => null, 'acik' => 0]);
ok('X hücresi: sınıf pc-x, "X" + küçük saat 8,5, title saat aralığı', $c1 === 'pc-x' && $i1 === '<b>X</b><i>8,5</i>' && str_contains($t1, '08:00 – 17:00'));
ok('! hücresi: pc-e, harf "!"', $c2 === 'pc-e' && $i2 === '<b>!</b>');
ok('kayıt yok: pc-b ve "·"', $c3 === 'pc-b' && $i3 === '·');
ok('günlük: sayı + ! rozeti (pc-e) / yalnız sayı (pc-x)', $c4 === 'pc-e' && $i4 === '<b>12</b><i>!</i>' && $c5 === 'pc-x' && $i5 === '<b>7</b>');

echo "\n=== 2f. Dışa aktarım satırları + XLSX geri okuma ===\n";
$bas = pc_disa_baslik($t); $sat = pc_disa_satirlar($t);
ok('başlık: 2 + 30 gün + 6 özet = 38; "3 Sal" vb. (v553: İzin · Rapor · Devamsız)', count($bas) === 38 && $bas[2] === '1 Paz' && $bas[4] === '3 Sal' && $bas[32] === 'Çalışılan Gün' && $bas[33] === 'Toplam Saat' && $bas[34] === 'Eksik Çıkış' && array_slice($bas, 35) === ['İzin', 'Rapor', 'Devamsız']);
ok('satırlar: 3 kişi + "O gün çalışan" + GENEL TOPLAM; hepsi 38 sütun', count($sat) === 5 && array_reduce($sat, fn($c, $r) => $c && count($r) === 38, true) && $sat[3][0] === 'O gün çalışan' && str_starts_with($sat[4][0], 'GENEL TOPLAM (3 kişi)'));
ok('A satırı: 3 Kasım 9,5 · 5 Kasım "!" · boş gün null · özet 2 / 17,5 / 2 / 0 / 0 / 0', $sat[0][4] === 9.5 && $sat[0][6] === '!' && $sat[0][3] === null && array_slice($sat[0], 32) === [2, 17.5, 2, 0, 0, 0]);
ok('o gün çalışan satırı: 3 Kasım = 2', $sat[3][4] === 2);
$sh = pc_xlsx_sayfa($t, 'Açıklama');
ok('xlsx sayfa tanımı: 38 sütun, gün sütunları tip=sayi, toplam=false', count($sh['sutunlar']) === 38 && $sh['sutunlar'][5]['tip'] === 'sayi' && $sh['toplam'] === false && str_contains($sh['baslik'], 'Kasım 2026'));
if (is_file($ROOT . '/vendor/autoload.php')) {
    require_once $ROOT . '/config/xlsx_export.php';
    $kitap = xlsx_olustur([$sh]);
    $ws = $kitap->getActiveSheet();
    $bulundu = false; $satirNo = 0;
    foreach ($ws->getRowIterator() as $row) { if ((string)$ws->getCell('A' . $row->getRowIndex())->getValue() === 'Örnek Personel A') { $bulundu = true; $satirNo = $row->getRowIndex(); break; } }
    ok('XLSX geri okuma: A satırı bulundu', $bulundu);
    ok('XLSX: 3 Kasım hücresi SAYI 9,5; 5 Kasım hücresi metin "!"; özet 2 / 17,5 / 2',
        $bulundu && (float)$ws->getCell('E' . $satirNo)->getValue() === 9.5 && $ws->getCell('E' . $satirNo)->getDataType() === 'n'
        && (string)$ws->getCell('G' . $satirNo)->getValue() === '!' && $ws->getCell('G' . $satirNo)->getDataType() === 's'
        && (int)$ws->getCell('AG' . $satirNo)->getValue() === 2 && (float)$ws->getCell('AH' . $satirNo)->getValue() === 17.5 && (int)$ws->getCell('AI' . $satirNo)->getValue() === 2);
    $son = $ws->getHighestRow();
    ok('XLSX: son satır GENEL TOPLAM', str_starts_with((string)$ws->getCell('A' . $son)->getValue(), 'GENEL TOPLAM'));
    // Formül enjeksiyonu: ad "=HYPERLINK(...)" metin kalır
    $zehir = pc_tablo_personel_kur('2026-11', [['id' => 9, 'full_name' => '=HYPERLINK("http://x","t")', 'personnel_no' => '', 'department' => '']], []);
    $zws = xlsx_olustur([pc_xlsx_sayfa($zehir)])->getActiveSheet();
    $zr = 0; foreach ($zws->getRowIterator() as $row) { if (str_starts_with((string)$zws->getCell('A' . $row->getRowIndex())->getValue(), '=HYPERLINK')) $zr = $row->getRowIndex(); }
    ok('formül enjeksiyonu: "=HYPERLINK" adı METİN hücresi', $zr > 0 && $zws->getCell('A' . $zr)->getDataType() === 's');
} else {
    ok('vendor/autoload.php yok → XLSX geri okuma atlandı (UYARI)', true);
}
$limit = pc_tablo_personel_kur('2026-11', array_map(fn($i) => ['id' => $i, 'full_name' => "P$i", 'personnel_no' => '', 'department' => ''], range(1, 400)), []);
ok('hücre sayısı: 400 kişi × 38 sütun (15.200) XLSX_MAX_HUCRE (150.000) altında', (count(pc_disa_satirlar($limit)) + 2) * 38 < 150000);
$gun = pc_tablo_gunluk_kur('2026-11', $rapor);
ok('günlük dışa aktarım: başlık Çavuş / Toplam İşçi-Gün (35 sütun, izin/rapor/devamsız YOK); hücre = işçi sayısı (eksikte de sayı)', pc_disa_baslik($gun)[0] === 'Çavuş' && pc_disa_baslik($gun)[33] === 'Toplam İşçi-Gün' && count(pc_disa_baslik($gun)) === 35 && pc_disa_satirlar($gun)[1][4] === 14);

// ───────────────────────── 3. GERÇEK SAYFA (alt süreç) ─────────────────────────
echo "\n=== 3. Gerçek sayfa çıktısı (bellek içi SQLite) ===\n";
function sayfa_calistir(string $sayfa, array $get, array $opt): array
{
    global $ROOT;
    $cmd = ['php', $ROOT . '/scripts/_puantaj_cizelgesi_harness.php', $sayfa, json_encode($get), json_encode($opt)];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['rc' => proc_close($p), 'out' => $out, 'err' => $err];
}
function satir_adlari(string $html): array { preg_match_all('/<th class="pc-ad" scope="row"><span class="pc-ad-ad">(.*?)<\/span>/', $html, $m); return $m[1]; }
function izin(array $ham): array { return role_pdks_expand_permissions($ham); }
$bu = date('Y-m');
$yon = ['admin' => true, 'ay' => $bu, 'depo' => '', 'allowed' => ['Depo A']];

$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], $yon);
ok('yönetici/personel: sayfa çıktı verdi (rc 0), PHP uyarısı yok', $p['rc'] === 0 && !preg_match('/Warning:|Notice:|Fatal|Deprecated/', $p['out'] . $p['err']), $p['err'] . substr($p['out'], 0, 300));
$ad = satir_adlari($p['out']);
ok('depo kuralı: Depo A + atanmamış görünür; Depo B ve pasif GİZLİ', $ad === ['Örnek Personel A', 'Örnek Personel B', 'Örnek Personel C', 'İsmail Örnek'], json_encode($ad, JSON_UNESCAPED_UNICODE));
ok('ay = bu ay → ızgara başlığında gün sayısı kadar pc-g sütunu', preg_match_all('/class="pc-g[ "]/', $p['out']) === (int)date('t'));
ok('bugünün sütunu çerçeveli (data-bugun + pc-bugun)', str_contains($p['out'], 'data-bugun') && str_contains($p['out'], 'pc-bugun'));
ok('hafta sonu sütunları işaretli (pc-hs başlıkta)', preg_match_all('/class="pc-g pc-hs/', $p['out']) >= 8);
ok('A satırı: 3 hücre X + 1 hücre ! ve özet', preg_match('/Örnek Personel A.*?<\/tr>/s', $p['out'], $ma) && substr_count($ma[0], 'pc-x') === 3 && substr_count($ma[0], 'pc-e') === 1 && str_contains($ma[0], '25,5') && str_contains($ma[0], '! 1'));
ok('mobil liste + ızgara ikisi de basılı; kişi başına details + 7 sütunlu takvim', substr_count($p['out'], '<details class="pc-kisi" data-emp-kisi=') === 4 && substr_count($p['out'], 'class="pc-takvim"') === 4 && str_contains($p['out'], 'class="table-wrap pc-grid-wrap"'));
ok('lejant + sekme çubuğu (yönetici: 2 sekme)', str_contains($p['out'], 'pc-lejant') && preg_match_all('/class="pc-sekme[ "]/', $p['out']) === 2);
ok('GET-only: formda method=get, csrf/post yok', str_contains($p['out'], 'method="get"') && !preg_match('/method="post"|name="csrf"/i', $p['out']));
ok('Excel menüsü + Yazdır bağlantısı (yönetici)', str_contains($p['out'], 'dl-menu') && str_contains($p['out'], 'puantaj_cizelgesi_yazdir.php?'));
ok('mobil gösterge düğmesi (ⓘ) + ay giriş alanı type=month', str_contains($p['out'], 'pc-lejant-dugme') && str_contains($p['out'], 'type="month"'));

$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'pasif' => '1'], $yon);
ok("'Pasifleri de göster': pasif personel eklenir (Depo B yine gizli)", in_array('Örnek Personel E', satir_adlari($p['out']), true) && !in_array('Örnek Personel D', satir_adlari($p['out']), true));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['admin' => true, 'ay' => $bu, 'allowed' => ['Depo A', 'Depo B']]);
ok('kullanıcı iki depoya yetkili: Depo B personeli de görünür', in_array('Örnek Personel D', satir_adlari($p['out']), true));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'q' => 'ismail'], $yon);
ok("arama 'ismail' → yalnız 'İsmail Örnek'; arama kutusu değeri korunur", satir_adlari($p['out']) === ['İsmail Örnek'] && str_contains($p['out'], 'value="ismail"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => '2028-02'], ['admin' => true, 'ay' => '2028-02']);
ok('Şubat 2028: 29 gün sütunu', preg_match_all('/class="pc-g[ "]/', $p['out']) === 29 && $p['rc'] === 0);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => 'saçma'], $yon);
ok('geçersiz ay → bu aya düşer (500 yok)', $p['rc'] === 0 && preg_match_all('/class="pc-g[ "]/', $p['out']) === (int)date('t'));

$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], $yon);
ok('günlük sekme: not "kart / çavuş bazında" + çavuş satırları', $p['rc'] === 0 && str_contains($p['out'], 'kart / çavuş bazında') && satir_adlari($p['out']) === ['Örnek Çavuş 1', 'Örnek Çavuş 2'], json_encode(satir_adlari($p['out']), JSON_UNESCAPED_UNICODE) . substr($p['out'], 0, 200));
ok('günlük: Çavuş 1 bugün 2 işçi + ! (eksik çıkış), Çavuş 2 bugün 2 işçi', preg_match('/Örnek Çavuş 1.*?<\/tr>/s', $p['out'], $mc1) && str_contains($mc1[0], '<b>2</b><i>!</i>') && preg_match('/Örnek Çavuş 2.*?<\/tr>/s', $p['out'], $mc2) && str_contains($mc2[0], '<b>2</b>') && !str_contains($mc2[0], '<i>!</i>'));
ok('günlük: alt toplam satırı "O gün toplam işçi" = 4, genel 4 işçi-gün', str_contains($p['out'], 'O gün toplam işçi') && preg_match('/pc-bugun">4<\/td>/', $p['out']) === 1);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk', 'q' => 'zzz'], $yon);
ok('günlük arama sonucu boş → bilgi kutusu', str_contains($p['out'], 'pc-bos') && !str_contains($p['out'], 'class="pc-grid"'));

echo "\n=== 3b. Yetki matrisi (gerçek role_pdks_require_request) ===\n";
$sadeceSayfa = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => $sadeceSayfa, 'ay' => $bu]);
ok('yalnız sayfa Gör (sekme izni yok) → forbidden', $p['rc'] === 3 && str_contains($p['out'], 'FORBIDDEN'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => [], 'ay' => $bu]);
ok('izinsiz kullanıcı → forbidden', $p['rc'] === 3);
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu], ['perms' => [], 'ay' => $bu]);
ok('yazdırma: izinsiz → forbidden', $p['rc'] === 3);
$pers = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], ['perms' => $pers, 'ay' => $bu]);
ok('yalnız personel izni: ?sekme=gunluk istense de personel sekmesi, tek sekme → sekme çubuğu YOK', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="personel"') && !str_contains($p['out'], 'class="pc-sekmeler"') && !str_contains($p['out'], 'dl-menu'), substr($p['out'], 0, 200));
$gun = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.daily_reports.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => $gun, 'ay' => $bu, 'depo' => '']);
ok('yalnız günlük izni: varsayılan sekme günlük işçi', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="gunluk"'));
$ikisi = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read', 'attendance.daily_reports.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => $ikisi, 'ay' => $bu]);
ok('iki izin: iki sekme; reports.export yok → Excel menüsü basılmaz', $p['rc'] === 0 && preg_match_all('/class="pc-sekme[ "]/', $p['out']) === 2 && !str_contains($p['out'], 'dl-menu'));
$eski = izin(['attendance.daily_reports']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => $eski, 'ay' => $bu]);
ok('ESKİ rol (attendance.daily_reports): sayfa açılır, yalnız günlük sekmesi', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="gunluk"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'csv' => '1'], ['perms' => $ikisi, 'ay' => $bu]);
ok('reports.export yok → ?csv forbidden', $p['rc'] === 3 && str_contains($p['out'], 'reports.export'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'xlsx' => '1'], ['perms' => $ikisi, 'ay' => $bu]);
ok('reports.export yok → ?xlsx forbidden', $p['rc'] === 3 && str_contains($p['out'], 'reports.export'));

echo "\n=== 3c. CSV / XLSX / audit ===\n";
$exp = array_merge($ikisi, ['reports.export']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'csv' => '1'], ['perms' => $exp, 'ay' => $bu]);
$satirlar = preg_split('/\r?\n/', trim(substr($p['out'], 0, 3) === "\xEF\xBB\xBF" ? substr($p['out'], 3) : $p['out']));
$ilk = str_getcsv($satirlar[0], ';', '"', '\\');
ok('CSV: BOM + ";" ayraç + başlık (Ad Soyad;Sicil / Departman;1 …)', substr($p['out'], 0, 3) === "\xEF\xBB\xBF" && $ilk[0] === 'Ad Soyad' && $ilk[1] === 'Sicil / Departman' && count($ilk) === 2 + (int)date('t') + 6, json_encode($ilk, JSON_UNESCAPED_UNICODE));
$a1 = null; foreach ($satirlar as $s) { $c = str_getcsv($s, ';', '"', '\\'); if ($c[0] === 'Örnek Personel A') $a1 = $c; }
ok('CSV: A satırı — 3. gün "9,50", 5. gün "!", özet 3 gün · 25,50 · 1 eksik · 0 izin/rapor/devamsız', $a1 !== null && $a1[4] === '9,50' && $a1[6] === '!' && array_slice($a1, -6) === ['3', '25,50', '1', '0', '0', '0'], json_encode($a1, JSON_UNESCAPED_UNICODE));
ok('CSV: audit (pdks / puantaj_cizelgesi / csv / satır sayısı 4)', str_contains($p['err'], 'AUDIT: ["export","pdks",null,null,{"report":"puantaj_cizelgesi","format":"csv","row_count":4'), $p['err']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'xlsx' => '1', 'sekme' => 'gunluk'], ['perms' => $exp, 'ay' => $bu, 'depo' => '']);
ok('XLSX (günlük): zip başlığı PK + audit xlsx', substr($p['out'], 0, 2) === 'PK' && str_contains($p['err'], '"format":"xlsx"') && str_contains($p['err'], '"sekme":"gunluk"'), substr($p['out'], 0, 200) . $p['err']);
if (is_file($ROOT . '/vendor/autoload.php') && substr($p['out'], 0, 2) === 'PK') {
    $tmp = tempnam(sys_get_temp_dir(), 'pcx') . '.xlsx'; file_put_contents($tmp, $p['out']);
    $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet(); @unlink($tmp);
    $v = []; foreach ($ws->getRowIterator() as $row) $v[$ws->getCell('A' . $row->getRowIndex())->getValue()] = $row->getRowIndex();
    ok('XLSX (günlük) geri okuma: iki çavuş + toplam satırı; Çavuş 1 bugün = 2', isset($v['Örnek Çavuş 1'], $v['Örnek Çavuş 2']) && (int)$ws->getCell([2 + (int)date('j'), $v['Örnek Çavuş 1']])->getValue() === 2);
}
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'csv' => '1', 'sekme' => 'gunluk'], ['perms' => $exp, 'ay' => $bu, 'depo' => '', 'sema' => 'personel']);
ok('şema hazır değilken dışa aktarım: dosya yerine hata + yönlendirme (500 yok)', $p['rc'] === 0 && !str_contains($p['out'], 'Ad Soyad'));

echo "\n=== 3d. Yazdırma sayfası ===\n";
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu], $yon);
ok('yazdır: rc 0, pcy-tablo, sidebar/container YOK, @page landscape', $p['rc'] === 0 && str_contains($p['out'], 'pcy-tablo') && !str_contains($p['out'], '<main class="container">') && str_contains($p['out'], 'A4 landscape'), substr($p['out'], 0, 200) . $p['err']);
ok('yazdır: thead + tfoot, 4 kişi, lejant', str_contains($p['out'], '<thead>') && str_contains($p['out'], 'GENEL TOPLAM (4)') && str_contains($p['out'], 'pcy-lejant'));
ok('yazdır: X hücreleri pcy-x, eksik pcy-e (renk + harf)', substr_count($p['out'], 'class="pcy-x') >= 4 && str_contains($p['out'], 'class="pcy-e'));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'gunluk'], $yon);
ok('yazdır (günlük): çavuş satırları + kart bazlı notu', $p['rc'] === 0 && str_contains($p['out'], 'Örnek Çavuş 1') && str_contains($p['out'], 'kart / çavuş bazında'));

echo "\n=== 3e. Şema hazır değil → nazik mesaj, 500 yok ===\n";
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['admin' => true, 'ay' => $bu, 'sema' => 'yok']);
ok('personel tabloları yok: rc 0 + açıklayıcı mesaj + tablo yok', $p['rc'] === 0 && str_contains($p['out'], 'Personel tabloları henüz hazır değil') && !str_contains($p['out'], 'class="pc-grid"'), $p['err'] . substr($p['out'], 0, 300));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], ['admin' => true, 'ay' => $bu, 'sema' => 'yok']);
ok('günlük şema yok: rc 0 + açıklayıcı mesaj', $p['rc'] === 0 && str_contains($p['out'], 'Günlük işçi modülü tabloları henüz hazır değil'), $p['err'] . substr($p['out'], 0, 300));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], ['admin' => true, 'ay' => $bu, 'sema' => 'personel']);
ok('yalnız personel şeması hazır: günlük sekmesi nazik mesaj, personel sekmesi çalışır', $p['rc'] === 0 && str_contains($p['out'], 'tabloları henüz hazır değil'));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu], ['admin' => true, 'ay' => $bu, 'sema' => 'yok']);
ok('yazdır + şema yok: rc 0 + mesaj', $p['rc'] === 0 && str_contains($p['out'], 'henüz hazır değil'));

// ───────────────────────── 4. v553 — ELLE GÜN İŞARETİ ─────────────────────────
echo "\n=== 4a. Şema (pi_tablolar / pi_migrate / pi_hazir) + saf doğrulayıcılar ===\n";
function sqlite_ddl_pi(string $ddl): array
{
    $d = preg_replace('/\)\s*ENGINE=.*$/s', ')', $ddl);
    $d = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $d);
    $ix = [];
    $d = preg_replace_callback('/^\s*(UNIQUE )?KEY `(\w+)` \(([^)]*)\),?\s*$/m', function ($m) use (&$ix) {
        $ix[] = 'CREATE ' . $m[1] . 'INDEX `' . $m[2] . '` ON `puantaj_isaretler` (' . $m[3] . ')'; return '';
    }, $d);
    $d = preg_replace('/,\s*\)\s*$/', "\n)", $d);
    return [$d, $ix];
}
$pi = new PDO('sqlite::memory:'); $pi->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pi->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ok('pi_tablolar: tek tablo puantaj_isaretler, IF NOT EXISTS, UNIQUE(employee_id, tarih) + tarih indeksi, alan seti', array_keys(pi_tablolar()) === ['puantaj_isaretler']
    && str_contains(pi_tablolar()['puantaj_isaretler'], 'CREATE TABLE IF NOT EXISTS `puantaj_isaretler`') && str_contains(pi_tablolar()['puantaj_isaretler'], 'UNIQUE KEY `uq_puantaj_isaret` (`employee_id`, `tarih`)')
    && str_contains(pi_tablolar()['puantaj_isaretler'], 'KEY `idx_puantaj_isaret_tarih` (`tarih`)') && str_contains(pi_tablolar()['puantaj_isaretler'], '`kod`         VARCHAR(4)')
    && str_contains(pi_tablolar()['puantaj_isaretler'], '`aciklama`    VARCHAR(200)  NULL') && !preg_match('/\bALTER\b|FOREIGN KEY|DROP/i', pi_tablolar()['puantaj_isaretler']));
ok('tablo yokken pi_hazir=false; pi_oku boş dizi (çökmez)', pi_hazir($pi) === false && pi_oku($pi, '2026-11') === []);
$GLOBALS['_pi_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = sqlite_ddl_pi($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$m1 = pi_migrate($pi);
ok("pi_migrate: 'olusturuldu'; ikinci çalıştırma 'var' (idempotent); pi_hazir=true", $m1 === [['tablo' => 'puantaj_isaretler', 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']] && pi_migrate($pi)[0]['durum'] === 'var' && pi_hazir($pi));
unset($GLOBALS['_pi_ddl_calistir']);
$pi->exec("INSERT INTO puantaj_isaretler (employee_id, tarih, kod, created_at) VALUES (1, '2026-11-03', 'D', '2026-11-01 00:00:00')");
$cift = false; try { $pi->exec("INSERT INTO puantaj_isaretler (employee_id, tarih, kod, created_at) VALUES (1, '2026-11-03', 'R', '2026-11-01 00:00:00')"); } catch (PDOException $e) { $cift = true; }
ok('UNIQUE(employee_id, tarih): aynı gün ikinci satır reddedilir; başka çalışan/gün serbest', $cift && $pi->exec("INSERT INTO puantaj_isaretler (employee_id, tarih, kod, created_at) VALUES (2, '2026-11-03', 'R', '2026-11-01 00:00:00')") === 1);
$pi->exec('DELETE FROM puantaj_isaretler');
$k = pi_kodlar();
ok("kodlar: D İ Ü R T (5, ≤ 9), her birinde ad + sınıf + grup; harf+renk birlikte", array_keys($k) === ['D', 'İ', 'Ü', 'R', 'T'] && count($k) <= 9 && $k['D']['ad'] === 'Devamsız' && $k['İ']['grup'] === 'izin' && $k['Ü']['grup'] === 'izin' && $k['R']['grup'] === 'rapor' && $k['T']['grup'] === 'tatil' && array_reduce($k, fn($c, $x) => $c && $x['sinif'] !== '' && $x['ad'] !== '', true));
ok('pi_kod_gecerli: beyaz liste (küçük harf, çoklu, boş, null, sayı REDDEDİLİR)', pi_kod_gecerli('D') && pi_kod_gecerli('İ') && pi_kod_gecerli('Ü') && pi_kod_gecerli('R') && pi_kod_gecerli('T')
    && !pi_kod_gecerli('d') && !pi_kod_gecerli('X') && !pi_kod_gecerli('') && !pi_kod_gecerli('DD') && !pi_kod_gecerli(null) && !pi_kod_gecerli(1) && !pi_kod_gecerli('I') && !pi_kod_gecerli('ü'));
$bg = '2026-11-22';
ok('pi_tarih_dogrula: geçerli takvim günü', pi_tarih_dogrula('2026-11-22', $bg) === '2026-11-22' && pi_tarih_dogrula(' 2026-11-03 ', $bg) === '2026-11-03' && pi_tarih_dogrula('2028-02-29', '2028-01-01') === '2028-02-29');
ok('pi_tarih_dogrula: takvimde olmayan / biçimsiz tarih reddedilir', pi_tarih_dogrula('2026-02-30', $bg) === null && pi_tarih_dogrula('2026-13-01', $bg) === null && pi_tarih_dogrula('2026-1-1', $bg) === null && pi_tarih_dogrula('abc', $bg) === null && pi_tarih_dogrula('', $bg) === null && pi_tarih_dogrula('2026-11-22 10:00', $bg) === null && pi_tarih_dogrula('2027-02-29', $bg) === null);
ok('pi_tarih_dogrula: ±2 yıl sınırı (sınır dahil; bir gün ötesi reddedilir)', pi_tarih_dogrula('2024-11-22', $bg) === '2024-11-22' && pi_tarih_dogrula('2024-11-21', $bg) === null && pi_tarih_dogrula('2028-11-22', $bg) === '2028-11-22' && pi_tarih_dogrula('2028-11-23', $bg) === null && pi_tarih_dogrula('2030-01-01', $bg) === null && pi_tarih_dogrula('1999-01-01', $bg) === null);
ok('pi_aciklama_temizle: boş/boşluk/string-olmayan → null; kontrol karakteri sadeleşir; 200 karakterde kesilir; HTML metin olarak kalır', pi_aciklama_temizle('') === null && pi_aciklama_temizle('   ') === null && pi_aciklama_temizle(null) === null && pi_aciklama_temizle(5) === null
    && pi_aciklama_temizle("  a\n\tb\x00c  ") === 'a b c' && mb_strlen((string)pi_aciklama_temizle(str_repeat('ş', 300))) === 200 && pi_aciklama_temizle('<b>x</b>') === '<b>x</b>');
ok('pi_girdi_coz: JSON gövde / form alanları / bozuk JSON (boş dizi) / liste olan JSON (boş dizi)', pi_girdi_coz('{"kod":"D"}', 'application/json; charset=utf-8', ['kod' => 'R']) === ['kod' => 'D'] && pi_girdi_coz('', 'application/x-www-form-urlencoded', ['kod' => 'R']) === ['kod' => 'R']
    && pi_girdi_coz('{bozuk', 'application/json', ['kod' => 'R']) === [] && pi_girdi_coz('[1,2]', 'application/json', []) === []);

echo "\n=== 4b. pi_uygula: upsert + temizle + audit verisi ===\n";
$u = fn(int $e, string $t, string $k, ?string $a = null) => pi_uygula($pi, $e, $t, $k, $a, 7, '2026-11-22');
$r = $u(1, '2026-11-05', 'D');
ok("yeni işaret: islem=create, audit ['create', id, null, {employee_id,tarih,kod,aciklama}]", $r['ok'] && $r['islem'] === 'create' && $r['audit'][0] === 'create' && $r['audit'][2] === null && $r['audit'][3] === ['employee_id' => 1, 'tarih' => '2026-11-05', 'kod' => 'D', 'aciklama' => null] && $r['audit'][1] > 0);
$id1 = $r['audit'][1];
$st = $pi->query("SELECT * FROM puantaj_isaretler WHERE id = $id1")->fetch();
ok('satır: user_id=7, created_at/updated_at dolu', (int)$st['user_id'] === 7 && $st['created_at'] !== '' && $st['updated_at'] !== null);
$r = $u(1, '2026-11-05', 'D');
ok('aynı değer: islem=degismedi, audit YOK, satır sayısı 1', $r['ok'] && $r['islem'] === 'degismedi' && $r['audit'] === null && (int)$pi->query('SELECT COUNT(*) FROM puantaj_isaretler')->fetchColumn() === 1);
$r = $u(1, '2026-11-05', 'R');
ok("kod değişimi: islem=update, audit ['update', aynı id, eski D, yeni R]; satır tek", $r['islem'] === 'update' && $r['audit'][0] === 'update' && $r['audit'][1] === $id1 && $r['audit'][2]['kod'] === 'D' && $r['audit'][3]['kod'] === 'R' && (int)$pi->query('SELECT COUNT(*) FROM puantaj_isaretler')->fetchColumn() === 1);
$r = $u(1, '2026-11-05', 'R', 'Doktor raporu');
ok('açıklama eklenir (update)', $r['islem'] === 'update' && $r['audit'][3]['aciklama'] === 'Doktor raporu');
$r = $u(1, '2026-11-05', 'İ');
ok('açıklama verilmezse (null) mevcut açıklama KORUNUR', $r['islem'] === 'update' && $r['audit'][3]['aciklama'] === 'Doktor raporu' && $r['audit'][3]['kod'] === 'İ');
$r = $u(1, '2026-11-05', 'İ', '');
ok("açıklama '' → silinir (update)", $r['islem'] === 'update' && $r['audit'][3]['aciklama'] === null);
$r = $u(2, '2026-11-05', 'T');
ok('başka çalışan aynı gün → ayrı satır (create)', $r['islem'] === 'create' && (int)$pi->query('SELECT COUNT(*) FROM puantaj_isaretler')->fetchColumn() === 2);
$r = $u(1, '2026-11-05', '');
ok("temizle: islem=delete, audit ['delete', id, eski {kod İ}, null]; satır gitti, diğer çalışan durur", $r['islem'] === 'delete' && $r['audit'][0] === 'delete' && $r['audit'][1] === $id1 && $r['audit'][2]['kod'] === 'İ' && $r['audit'][3] === null
    && (int)$pi->query('SELECT COUNT(*) FROM puantaj_isaretler WHERE employee_id = 1')->fetchColumn() === 0 && (int)$pi->query('SELECT COUNT(*) FROM puantaj_isaretler WHERE employee_id = 2')->fetchColumn() === 1);
$r = $u(1, '2026-11-05', '');
ok('olmayan işareti temizle: ok, islem=yok, audit YOK', $r['ok'] && $r['islem'] === 'yok' && $r['audit'] === null);
$sayi0 = (int)$pi->query('SELECT COUNT(*) FROM puantaj_isaretler')->fetchColumn();
$bad = [$u(1, '2026-11-05', 'X'), $u(1, '2026-11-05', 'd'), $u(1, '2026-02-30', 'D'), $u(1, '2030-01-01', 'D'), $u(1, 'saçma', 'D')];
ok('geçersiz kod/tarih: ok=false + hata mesajı; DB değişmez', array_reduce($bad, fn($c, $x) => $c && $x['ok'] === false && $x['hata'] !== '' && $x['audit'] === null, true) && (int)$pi->query('SELECT COUNT(*) FROM puantaj_isaretler')->fetchColumn() === $sayi0);
ok('gelecek tarih serbest (1 yıl sonrası)', $u(1, '2027-11-01', 'T')['islem'] === 'create');
$pi->exec('DELETE FROM puantaj_isaretler');
$pi->exec('DROP TABLE puantaj_isaretler');
$eskiLog = ini_set('error_log', '/dev/null');   // beklenen hata günlük satırı çıktıya karışmasın
$r = pi_uygula($pi, 1, '2026-11-05', 'D', null, 7, '2026-11-22');
ini_set('error_log', (string)$eskiLog);
ok('tablo yokken pi_uygula çökmez: ok=false', $r['ok'] === false && $r['hata'] !== '');

echo "\n=== 4c. Tabloya işleme: işaret otomatik X/! hücresinin ÜSTÜNE geçer, hesaplardan düşer ===\n";
$isr = [
    1 => ['2026-11-03' => ['kod' => 'İ', 'aciklama' => ''], '2026-11-05' => ['kod' => 'R', 'aciklama' => ''], '2026-11-06' => ['kod' => 'D', 'aciklama' => ''],
          '2026-11-07' => ['kod' => 'T', 'aciklama' => ''], '2026-11-08' => ['kod' => 'Ü', 'aciklama' => ''], '2026-11-09' => ['kod' => 'İ', 'aciklama' => 'Düğün'],
          '2026-11-10' => ['kod' => 'Z', 'aciklama' => ''], '2026-12-01' => ['kod' => 'D', 'aciklama' => '']],   // geçersiz kod + ay dışı → yok sayılır
    99 => ['2026-11-03' => ['kod' => 'D', 'aciklama' => '']],                                                       // listede olmayan çalışan → yok sayılır
];
$ti = pc_tablo_personel_kur('2026-11', $cal, $olaylar, '', $isr);
$A = $ti['satirlar'][0]; $I = $ti['satirlar'][1];
ok('3 Kasım: X (9,5 saat kayıt) yerine İ; hücrede kayıt işareti (kayit=true, 9,5 saat, ilk/son saat korunur)', $A['hucre']['2026-11-03']['k'] === 'İ' && !empty($A['hucre']['2026-11-03']['isaret']) && $A['hucre']['2026-11-03']['kayit'] === true && abs($A['hucre']['2026-11-03']['kayit_saat'] - 9.5) < 1e-9 && $A['hucre']['2026-11-03']['ilk'] === '08:00' && $A['hucre']['2026-11-03']['son'] === '17:30' && $A['hucre']['2026-11-03']['d'] === 0.0);
ok('5 Kasım: ! (eksik çıkış) yerine R; eksik sayılmaz (acik=0, kayit_acik=1)', $A['hucre']['2026-11-05']['k'] === 'R' && $A['hucre']['2026-11-05']['acik'] === 0 && $A['hucre']['2026-11-05']['kayit_acik'] === 1);
ok('6 Kasım (kayıtsız gün) D: kayit=false; hafta sonu 7 Kasım T + 8 Kasım Ü işaretlenebilir', $A['hucre']['2026-11-06']['k'] === 'D' && $A['hucre']['2026-11-06']['kayit'] === false && $A['hucre']['2026-11-07']['k'] === 'T' && $A['hucre']['2026-11-08']['k'] === 'Ü' && $A['hucre']['2026-11-09']['aciklama'] === 'Düğün');
ok('4 Kasım (işaretsiz) X olarak KALIR; geçersiz kod (10 Kasım), ay dışı (1 Aralık) ve olmayan çalışan YOK sayıldı', $A['hucre']['2026-11-04']['k'] === 'X' && !isset($A['hucre']['2026-11-10']) && !isset($A['hucre']['2026-12-01']) && count($ti['satirlar']) === 3);
ok('A özet: gün 1 (yalnız 4 Kasım) · 8 saat · eksik 1 (yalnız 30 Kasım) · izin 3 (İ+Ü+İ) · rapor 1 · devamsız 1; Tatil (T) hiçbir sütuna girmez', $A['ozet'] === ['gun' => 1, 'toplam' => 8.0, 'eksik' => 1, 'izin' => 3, 'rapor' => 1, 'devamsiz' => 1]);
ok('İsmail (işaretsiz) özeti değişmez; kayıtsız satır özetleri 0', $I['ozet']['gun'] === 1 && $I['ozet']['izin'] === 0 && $ti['satirlar'][2]['ozet']['izin'] === 0);
ok('"o gün çalışan": 3 Kasım = 1 (A işaretli → sayılmaz, yalnız İsmail); 4 = 1; 5 = 0; 6–9 = 0', $ti['gun_say']['2026-11-03'] === 1 && $ti['gun_say']['2026-11-04'] === 1 && $ti['gun_say']['2026-11-05'] === 0 && $ti['gun_say']['2026-11-06'] === 0 && $ti['gun_say']['2026-11-07'] === 0);
ok('genel: 3 kişi · 2 gün · 16 saat · 1 eksik · 3 izin · 1 rapor · 1 devamsız', $ti['genel'] === ['kisi' => 3, 'gun' => 2, 'toplam' => 16.0, 'eksik' => 1, 'izin' => 3, 'rapor' => 1, 'devamsiz' => 1]);
$tq = pc_tablo_personel_kur('2026-11', $cal, $olaylar, 'personel a', $isr);
ok('arama + işaret: toplamlar süzülmüş satırlardan', count($tq['satirlar']) === 1 && $tq['genel']['izin'] === 3 && $tq['genel']['gun'] === 1);
$t0 = pc_tablo_personel_kur('2026-11', $cal, $olaylar);
ok('işaret verilmezse (eski çağrı) sonuç değişmez: A özet 2 gün · 17,5 saat · 2 eksik, izin/rapor/devamsız 0', $t0['satirlar'][0]['ozet'] === ['gun' => 2, 'toplam' => 17.5, 'eksik' => 2, 'izin' => 0, 'rapor' => 0, 'devamsiz' => 0]);
$tg = pc_tablo_gunluk_kur('2026-11', $rapor);
ok('günlük sekme DEĞİŞMEZ: özet yalnız gun/toplam/eksik (izin/rapor/devamsız anahtarı YOK)', array_keys($tg['satirlar'][0]['ozet']) === ['gun', 'toplam', 'eksik'] && array_keys($tg['genel']) === ['kisi', 'gun', 'toplam', 'eksik']);

echo "\n=== 4d. Hücre gösterimi + dışa aktarım ===\n";
[$kc, $ki, $kt] = pc_hucre_goster('personel', $A['hucre']['2026-11-03']);
[$kc2, $ki2, $kt2] = pc_hucre_goster('personel', $A['hucre']['2026-11-06']);
[$kc3, $ki3] = pc_hucre_goster('personel', $A['hucre']['2026-11-07']);
[$kc4, $ki4, $kt4] = pc_hucre_goster('personel', ['k' => 'D', 'd' => 0.0, 'ilk' => null, 'son' => null, 'acik' => 0, 'isaret' => true, 'kayit' => false, 'kayit_saat' => 0.0, 'kayit_acik' => 0, 'aciklama' => '<script>x</script>']);
ok('kayıt çakışan işaret: pc-k pc-k-iz, harf İ + küçük nokta, title "bu gün giriş/çıkış kaydı da var (08:00 – 17:30, 9,5 saat)"', $kc === 'pc-k pc-k-iz' && str_starts_with($ki, '<b>İ</b>') && str_contains($ki, 'pc-kn') && str_contains($kt, 'Yıllık izin') && str_contains($kt, 'bu gün giriş/çıkış kaydı da var') && str_contains($kt, '08:00 – 17:30') && str_contains($kt, '9,5 saat'));
ok('kayıtsız işaret: nokta YOK; her kodun sınıfı (D=d, T=t)', $kc2 === 'pc-k pc-k-d' && $ki2 === '<b>D</b>' && $kc3 === 'pc-k pc-k-t' && $ki3 === '<b>T</b>');
ok('açıklama title metnine girer (kaçırma çağıran h() ile — sayfada denetlenir)', str_contains($kt4, 'Devamsız · <script>x</script>') && $ki4 === '<b>D</b>');
ok('lejant: personel metni kodları sayar; $kodlar=false sayamaz; günlük değişmez', str_contains(pc_lejant_metni('personel'), 'D = Devamsız') && str_contains(pc_lejant_metni('personel'), 'İ = Yıllık izin') && str_contains(pc_lejant_metni('personel'), 'Ü = Ücretsiz izin') && str_contains(pc_lejant_metni('personel'), 'R = Raporlu') && str_contains(pc_lejant_metni('personel'), 'T = Resmi tatil')
    && !str_contains(pc_lejant_metni('personel', false), 'Devamsız') && !str_contains(pc_lejant_metni('gunluk'), 'Devamsız'));
$bas = pc_disa_baslik($ti); $sat = pc_disa_satirlar($ti);
ok('dışa aktarım hücreleri: 3 Kasım "İ" · 5 Kasım "R" · 6 "D" · 7 "T" · 8 "Ü" · 4 Kasım 8 (saat); özet 1 / 8 / 1 / 3 / 1 / 1', $sat[0][4] === 'İ' && $sat[0][6] === 'R' && $sat[0][7] === 'D' && $sat[0][8] === 'T' && $sat[0][9] === 'Ü' && $sat[0][5] === 8.0 && array_slice($sat[0], 32) === [1, 8.0, 1, 3, 1, 1]);
ok('"o gün çalışan" satırı işaretli günü saymaz (3 Kasım = 1); genel satır özet sütunları 2 / 16 / 1 / 3 / 1 / 1', $sat[3][4] === 1 && array_slice($sat[4], 32) === [2, 16.0, 1, 3, 1, 1] && count($sat[3]) === 38);
if (is_file($ROOT . '/vendor/autoload.php')) {
    $ws = xlsx_olustur([pc_xlsx_sayfa($ti, '')])->getActiveSheet();
    $satirNo = 0; foreach ($ws->getRowIterator() as $row) { if ((string)$ws->getCell('A' . $row->getRowIndex())->getValue() === 'Örnek Personel A') { $satirNo = $row->getRowIndex(); break; } }
    ok('XLSX: 3 Kasım hücresi METİN "İ"; 4 Kasım SAYI 8; özet AG=1, AH=8, AI=1, AJ(izin)=3, AK(rapor)=1, AL(devamsız)=1; başlıklar İzin/Rapor/Devamsız',
        $satirNo > 0 && (string)$ws->getCell('E' . $satirNo)->getValue() === 'İ' && $ws->getCell('E' . $satirNo)->getDataType() === 's' && (float)$ws->getCell('F' . $satirNo)->getValue() === 8.0 && $ws->getCell('F' . $satirNo)->getDataType() === 'n'
        && (int)$ws->getCell('AG' . $satirNo)->getValue() === 1 && (int)$ws->getCell('AJ' . $satirNo)->getValue() === 3 && (int)$ws->getCell('AK' . $satirNo)->getValue() === 1 && (int)$ws->getCell('AL' . $satirNo)->getValue() === 1);
    $bsr = 0; foreach ($ws->getRowIterator() as $row) { if ((string)$ws->getCell('AJ' . $row->getRowIndex())->getValue() === 'İzin') $bsr = $row->getRowIndex(); }
    ok('XLSX: başlık satırında İzin / Rapor / Devamsız', $bsr > 0 && (string)$ws->getCell('AK' . $bsr)->getValue() === 'Rapor' && (string)$ws->getCell('AL' . $bsr)->getValue() === 'Devamsız');
}

echo "\n=== 4e. Gerçek sayfa: arayüz yetkiye ve şemaya bağlı ===\n";
$ISR = [[1, 3, 'İ'], [1, 6, 'D', '<script>x</script>'], [2, 10, 'R'], [2, 11, 'T'], [3, 12, 'Ü']];
$edit = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.monthly_timesheet.edit', 'attendance.employees.read']);
$salt = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
$say = substr_count($p['out'], ' data-pi data-emp=');
ok('yönetici + tablo hazır: hücreler düzenlenebilir (ızgara + mobil takvim = 2 × 4 kişi × gün), data-pi-url + csrf özniteliği', $p['rc'] === 0 && $say === 2 * 4 * (int)date('t') && str_contains($p['out'], 'data-pi-url="puantaj_isaret_kaydet.php"') && str_contains($p['out'], 'data-pi-csrf="testcsrf"'), (string)$say . $p['err']);
ok('menü: 5 kod düğmesi (D İ Ü R T) + Temizle; <form> YOK; hata alanı role=alert', $p['rc'] === 0 && preg_match_all('/data-pi-kod="[DİÜRT]"/u', $p['out']) === 5 && str_contains($p['out'], 'data-pi-kod=""') && str_contains($p['out'], 'id="piMenu"') && str_contains($p['out'], 'role="alert"') && !preg_match('/<form[^>]*method="post"/i', $p['out']));
ok('işaretli hücreler: İ/D/R/T/Ü sınıfları (renk + harf); kayıt çakışan 3 Kasım hücresinde nokta (pc-kn)', str_contains($p['out'], 'pc-k pc-k-iz') && str_contains($p['out'], 'pc-k pc-k-d') && str_contains($p['out'], 'pc-k pc-k-r') && str_contains($p['out'], 'pc-k pc-k-t') && str_contains($p['out'], 'pc-k pc-k-uc') && str_contains($p['out'], 'class="pc-kn"') && str_contains($p['out'], 'data-kod="İ"'));
ok('XSS: işaret açıklaması title içinde KAÇIRILIR (&lt;script&gt;), ham <script>x yok', str_contains($p['out'], '&lt;script&gt;x&lt;/script&gt;') && !str_contains($p['out'], '<script>x</script>'));
ok('özet sütunları: başlıkta İzin/Rapor/Devamsız; A satırı 3 Kasım X yerine İ → yalnız 4 ve 12\'si X (2 gün, 16 saat; işaretli gün sayılmaz), eksik 1', str_contains($p['out'], '>Devamsız</th>') && preg_match('/Örnek Personel A.*?<\/tr>/s', $p['out'], $ma) === 1 && substr_count($ma[0], 'pc-x') === 2 && str_contains($ma[0], '>16<') && str_contains($ma[0], '! 1'), $ma[0] ?? '');
ok('lejant: kod rozetleri + "tıklayarak işaretleyin" ipucu (düzenleyen için)', str_contains($p['out'], 'pc-kl-liste') && str_contains($p['out'], 'Güne tıklayarak'));
ok('mobil gösterge ⓘ arkasında (pc-lejant-ic içinde) ve özet rozetleri (izin/rapor/devamsız) basılı', str_contains($p['out'], 'pc-lejant-dugme') && preg_match('/pc-lejant-ic".*?pc-kl-liste/s', $p['out']) === 1 && str_contains($p['out'], 'devamsız</span>'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => $edit, 'ay' => $bu, 'isaretler' => $ISR]);
ok("ayrıntılı rol + açık 'edit' + Personel kartları Gör: düzenleme açık", $p['rc'] === 0 && str_contains($p['out'], ' data-pi data-emp=') && str_contains($p['out'], 'id="piMenu"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => $salt, 'ay' => $bu, 'isaretler' => $ISR]);
ok("'edit' YOK: işaretler GÖRÜNÜR ama hücre salt okunur (data-pi / data-pi-url / menü / pc-duz / ipucu YOK)", $p['rc'] === 0 && str_contains($p['out'], 'pc-k pc-k-iz') && !str_contains($p['out'], 'data-pi') && !str_contains($p['out'], 'piMenu') && !str_contains($p['out'], 'pc-duz') && !str_contains($p['out'], 'Güne tıklayarak'), substr($p['out'], 0, 100));
$eski = izin(['attendance.employees']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => $eski, 'ay' => $bu, 'isaretler' => $ISR]);
ok("ESKİ rol (attendance.employees): sayfa açılır, 'edit' otomatik AÇILMAZ → salt okunur", $p['rc'] === 0 && str_contains($p['out'], 'data-tur="personel"') && !str_contains($p['out'], 'data-pi'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
ok('GÜNLÜK İŞÇİ sekmesi AYNEN: işaret arayüzü/özet sütunları/menü YOK', $p['rc'] === 0 && !str_contains($p['out'], 'data-pi') && !str_contains($p['out'], 'piMenu') && !str_contains($p['out'], '>Devamsız</th>') && !str_contains($p['out'], 'pc-kl-liste'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['admin' => true, 'ay' => $bu, 'isaret_yok' => true]);
ok("tablo YOK (migrate edilmemiş): çizelge eskisi gibi çalışır (rc 0, A satırı X/!), işaretleme arayüzü gizli, yönetici için 'migrate.php#puantaj-isaret' notu", $p['rc'] === 0 && str_contains($p['out'], 'class="pc-grid"') && !str_contains($p['out'], 'data-pi') && !str_contains($p['out'], 'piMenu') && !str_contains($p['out'], 'pc-kl-liste')
    && str_contains($p['out'], 'migrate.php#puantaj-isaret') && str_contains($p['out'], 'Puantaj İşaretleri') && substr_count($p['out'], 'pc-x') >= 3, $p['err'] . substr($p['out'], 0, 200));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['perms' => $salt, 'ay' => $bu, 'isaret_yok' => true]);
ok('tablo yok + yönetici olmayan kullanıcı: not GÖRÜNMEZ', $p['rc'] === 0 && !str_contains($p['out'], 'puantaj-isaret') && str_contains($p['out'], 'class="pc-grid"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], ['admin' => true, 'ay' => $bu, 'sema' => 'yok']);
ok('personel tabloları da yok: nazik hata, işaret notu/menü yok (500 yok)', $p['rc'] === 0 && str_contains($p['out'], 'henüz hazır değil') && !str_contains($p['out'], 'puantaj-isaret') && !str_contains($p['out'], 'piMenu'));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
ok('yazdırma: işaretli hücreler pcy-k pcy-k-iz/-d/-r/-t/-uc (harf), İzin/Rapor/Devamsız başlıkları, lejantta kodlar, XSS kaçırılmış (yazdırmada title yok)', $p['rc'] === 0 && str_contains($p['out'], 'pcy-k pcy-k-iz') && str_contains($p['out'], 'pcy-k pcy-k-d') && str_contains($p['out'], 'pcy-k pcy-k-t') && str_contains($p['out'], 'pcy-k pcy-k-uc') && str_contains($p['out'], '>Devamsız</th>') && str_contains($p['out'], 'D = Devamsız') && !str_contains($p['out'], '<script>x</script>') && str_contains($p['out'], 'colspan="6"'), $p['err'] . substr($p['out'], 0, 200));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu], ['admin' => true, 'ay' => $bu, 'isaret_yok' => true]);
ok('yazdırma + tablo yok: lejantta kod yok, sayfa çalışır', $p['rc'] === 0 && !str_contains($p['out'], 'D = Devamsız') && str_contains($p['out'], 'pcy-tablo'));

echo "\n=== 4f. CSV / XLSX: işaret harfleri + yeni özet sütunları, audit (q metni YOK) ===\n";
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'csv' => '1'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
$satirlar = preg_split('/\r?\n/', trim(substr($p['out'], 0, 3) === "\xEF\xBB\xBF" ? substr($p['out'], 3) : $p['out']));
$ilk = str_getcsv($satirlar[0], ';', '"', '\\');
$a1 = null; foreach ($satirlar as $sr) { $c = str_getcsv($sr, ';', '"', '\\'); if ($c[0] === 'Örnek Personel A') $a1 = $c; }
ok('CSV: başlık son 3 sütun İzin;Rapor;Devamsız (38 sütun)', array_slice($ilk, -3) === ['İzin', 'Rapor', 'Devamsız'] && count($ilk) === 2 + (int)date('t') + 6, json_encode($ilk, JSON_UNESCAPED_UNICODE));
ok('CSV: A satırı — 3. gün "İ" (formül ön eki yok), 6. gün "D"; özet 5. ve 6. sütunlar izin=1 · rapor=0 · devamsız=1', $a1 !== null && $a1[4] === 'İ' && $a1[7] === 'D' && array_slice($a1, -3) === ['1', '0', '1'], json_encode($a1, JSON_UNESCAPED_UNICODE));
ok('CSV: audit q-metni kuralı — aranan metin audit\'e YAZILMAZ (yalnız arama:true)', str_contains($p['err'], '"report":"puantaj_cizelgesi"') && !str_contains($p['err'], 'gizli arama'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'csv' => '1', 'q' => 'gizli arama'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
ok('CSV + q: audit filtresinde "arama":true, metin YOK', str_contains($p['err'], '"arama":true') && !str_contains($p['err'], 'gizli arama'), $p['err']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'xlsx' => '1'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
ok('XLSX (personel + işaretler): zip başlığı PK, audit xlsx', substr($p['out'], 0, 2) === 'PK' && str_contains($p['err'], '"format":"xlsx"'));
if (is_file($ROOT . '/vendor/autoload.php') && substr($p['out'], 0, 2) === 'PK') {
    $tmp = tempnam(sys_get_temp_dir(), 'pcx') . '.xlsx'; file_put_contents($tmp, $p['out']);
    $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet(); @unlink($tmp);
    $rA = 0; foreach ($ws->getRowIterator() as $row) { if ((string)$ws->getCell('A' . $row->getRowIndex())->getValue() === 'Örnek Personel A') $rA = $row->getRowIndex(); }
    ok('XLSX sayfa çıktısı: A satırı 3. gün "İ", 6. gün "D"; İzin sütunu AJ = 1', $rA > 0 && (string)$ws->getCell('E' . $rA)->getValue() === 'İ' && (string)$ws->getCell('H' . $rA)->getValue() === 'D' && (int)$ws->getCell('AJ' . $rA)->getValue() === 1);
}

echo "\n=== 4g. Yazma ucu puantaj_isaret_kaydet.php (gerçek role_pdks_require_request + kapılar) ===\n";
function post_calistir(array $post, array $opt = []): array
{
    global $bu;
    $r = sayfa_calistir('puantaj_isaret_kaydet.php', [], array_merge(['ay' => $bu, 'dump' => true, 'post' => $post], $opt));
    $r['json'] = json_decode(preg_replace('/ISARETLER:.*$/s', '', $r['out']), true);
    preg_match('/ISARETLER: (.*)/', $r['err'] . "\n" . $r['out'], $m);
    $r['dump'] = isset($m[1]) ? json_decode($m[1], true) : null;
    return $r;
}
$g5 = "$bu-05"; $g6 = "$bu-06";
$temel = ['csrf' => 'testcsrf', 'employee_id' => '1', 'tarih' => $g5, 'kod' => 'D'];
$pa = post_calistir($temel, ['admin' => true]);
ok('yönetici: ok + islem=create; DB satırı yazıldı (user_id=1, açıklama boş)', $pa['rc'] === 0 && ($pa['json']['ok'] ?? false) === true && $pa['json']['islem'] === 'create' && $pa['dump'] === [['employee_id' => 1, 'tarih' => $g5, 'kod' => 'D', 'aciklama' => null, 'user_id' => 1]], $pa['out'] . $pa['err']);
ok("audit: create / puantaj_isaret / kayıt id / eski null / yeni {employee_id,tarih,kod,aciklama}", str_contains($pa['err'], 'AUDIT: ["create","puantaj_isaret",1,null,{"employee_id":1,"tarih":"' . $g5 . '","kod":"D","aciklama":null}]'), $pa['err']);
ok('yanıt JSON alanları: ok · islem · kod · tarih · employee_id', $pa['json'] === ['ok' => true, 'islem' => 'create', 'kod' => 'D', 'tarih' => $g5, 'employee_id' => 1]);
$pb = post_calistir(['csrf' => 'testcsrf', 'employee_id' => '1', 'tarih' => $g5, 'kod' => 'R'], ['admin' => true, 'isaretler' => [[1, 5, 'D']]]);
ok('güncelle: islem=update; audit eski D → yeni R (aynı kayıt id)', ($pb['json']['islem'] ?? '') === 'update' && str_contains($pb['err'], 'AUDIT: ["update","puantaj_isaret",1,{"employee_id":1,"tarih":"' . $g5 . '","kod":"D","aciklama":null},{"employee_id":1,"tarih":"' . $g5 . '","kod":"R","aciklama":null}]') && count($pb['dump']) === 1 && $pb['dump'][0]['kod'] === 'R', $pb['err']);
$pc = post_calistir(['csrf' => 'testcsrf', 'employee_id' => '1', 'tarih' => $g5, 'kod' => ''], ['admin' => true, 'isaretler' => [[1, 5, 'D']]]);
ok("temizle (kod=''): islem=delete; audit delete eski {kod D} yeni null; satır silindi", ($pc['json']['islem'] ?? '') === 'delete' && str_contains($pc['err'], 'AUDIT: ["delete","puantaj_isaret",1,{"employee_id":1,"tarih":"' . $g5 . '","kod":"D","aciklama":null},null]') && $pc['dump'] === [], $pc['err']);
$pd = post_calistir(['csrf' => 'testcsrf', 'employee_id' => '1', 'tarih' => $g6, 'kod' => ''], ['admin' => true]);
ok('olmayan işareti temizle: ok, islem=yok, audit YOK', ($pd['json']['islem'] ?? '') === 'yok' && !str_contains($pd['err'], 'AUDIT:'));
$pe = post_calistir($temel, ['admin' => true, 'isaretler' => [[1, 5, 'D']]]);
ok('aynı değeri tekrar gönder: islem=degismedi, audit YOK, tek satır', ($pe['json']['islem'] ?? '') === 'degismedi' && !str_contains($pe['err'], 'AUDIT:') && count($pe['dump']) === 1);
$pf = post_calistir($temel + ['aciklama' => str_repeat('ş', 300)], ['admin' => true]);
ok('açıklama 200 karaktere kısaltılır', mb_strlen((string)($pf['dump'][0]['aciklama'] ?? '')) === 200, json_encode($pf['dump'], JSON_UNESCAPED_UNICODE));
$pg = post_calistir(['csrf' => 'testcsrf', 'employee_id' => '1', 'tarih' => $g5, 'kod' => 'İ'], ['admin' => true, 'isaretler' => [[1, 5, 'D', 'Not']]]);
ok('"İ" kodu kabul; açıklama verilmedi → "Not" korunur', ($pg['json']['ok'] ?? false) === true && ($pg['dump'][0]['kod'] ?? '') === 'İ' && ($pg['dump'][0]['aciklama'] ?? '') === 'Not');

echo "--- kapılar ---\n";
$pk = post_calistir(array_diff_key($temel, ['csrf' => 1]), ['admin' => true]);
ok('CSRF YOK → 403 + JSON, DB yazılmadı', $pk['rc'] === 5 && ($pk['json']['ok'] ?? null) === false && ($pk['json']['code'] ?? 0) === 403 && $pk['dump'] === [] && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
$pk = post_calistir(['csrf' => 'yanlis'] + $temel, ['admin' => true]);
ok('CSRF YANLIŞ → 403 + JSON, DB yazılmadı', $pk['rc'] === 5 && ($pk['json']['code'] ?? 0) === 403 && $pk['dump'] === []);
$pk = post_calistir($temel, ['perms' => [], 'ay' => $bu]);
ok('izinsiz kullanıcı → 403 (rota kapısı), DB yazılmadı', $pk['rc'] === 3 && ($pk['json']['ok'] ?? null) === false && ($pk['dump'] ?? []) === []);
$pk = post_calistir($temel, ['perms' => $salt]);
ok("yalnız 'read' (edit yok) → 403, DB yazılmadı", $pk['rc'] === 3 && ($pk['json']['ok'] ?? null) === false && ($pk['dump'] ?? []) === [], $pk['out']);
$pk = post_calistir($temel, ['perms' => $eski]);
ok("ESKİ rol (attendance.employees, edit otomatik YOK) → 403", $pk['rc'] === 3 && ($pk['dump'] ?? []) === []);
$pk = post_calistir($temel, ['perms' => izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.monthly_timesheet.edit'])]);
ok("'edit' var ama kaynak (Personel kartları Gör) YOK → 403 JSON, DB yazılmadı", ($pk['json']['ok'] ?? null) === false && ($pk['json']['code'] ?? 0) === 403 && ($pk['dump'] ?? []) === [], $pk['out']);
$pk = post_calistir($temel, ['perms' => izin(['role.permissions.detailed', 'attendance.monthly_timesheet.edit', 'attendance.employees.read'])]);
ok("'edit' var ama sayfa 'read' YOK → 403", $pk['rc'] === 3 && ($pk['dump'] ?? []) === []);
$pk = post_calistir($temel, ['perms' => $edit]);
ok("ayrıntılı rol (read+edit+Personel kartları Gör) → yazar", ($pk['json']['ok'] ?? false) === true && count($pk['dump']) === 1);
$pk = sayfa_calistir('puantaj_isaret_kaydet.php', [], ['admin' => true, 'ay' => $bu]);
ok('GET → 405 JSON (yazma ucu yalnız POST)', str_contains($pk['out'], '"ok":false') && str_contains($pk['out'], 'Geçersiz istek'));

echo "--- girdi doğrulama ---\n";
foreach ([['kod' => 'X'], ['kod' => 'd'], ['kod' => 'DD'], ['kod' => ['D']], ['tarih' => '2026-02-30'], ['tarih' => '2031-01-01'], ['tarih' => ''], ['tarih' => 'saçma'], ['tarih' => "$bu-05 10:00"], ['employee_id' => 'abc'], ['employee_id' => '0'], ['employee_id' => '-1'], ['employee_id' => '1.5'], ['employee_id' => '']] as $kotu) {
    $pk = post_calistir($kotu + $temel, ['admin' => true]);
    ok('geçersiz girdi reddedilir (400): ' . json_encode($kotu, JSON_UNESCAPED_UNICODE), ($pk['json']['ok'] ?? null) === false && $pk['dump'] === [] && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
}
$pk = post_calistir(array_diff_key($temel, ['kod' => 1]), ['admin' => true]);
ok('kod alanı hiç yok → reddedilir (sessizce temizleme YAPMAZ)', ($pk['json']['ok'] ?? null) === false && $pk['dump'] === []);
$pk = post_calistir(['employee_id' => '999'] + $temel, ['admin' => true]);
ok('olmayan çalışan → 404 JSON', ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), 'bulunamadı') && $pk['dump'] === []);

echo "--- depo ---\n";
$pk = post_calistir(['employee_id' => '4'] + $temel, ['admin' => true, 'allowed' => ['Depo A']]);
ok('başka depo (Depo B) çalışanı → 403 JSON, yazılmadı', ($pk['json']['ok'] ?? null) === false && ($pk['json']['code'] ?? 0) === 403 && $pk['dump'] === [] && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
$pk = post_calistir(['employee_id' => '4'] + $temel, ['admin' => true, 'allowed' => ['Depo A', 'Depo B']]);
ok('iki depoya yetkili kullanıcı Depo B çalışanını işaretleyebilir', ($pk['json']['ok'] ?? false) === true);
$pk = post_calistir(['employee_id' => '4'] + $temel, ['admin' => true, 'allowed' => ['Depo A', 'Depo B'], 'depo' => 'Depo A']);
ok('aktif depo Depo A seçiliyken Depo B çalışanı 403', ($pk['json']['code'] ?? 0) === 403 && $pk['dump'] === []);
$pk = post_calistir(['employee_id' => '3'] + $temel, ['admin' => true, 'allowed' => ['Depo A']]);
ok('atanmamış (boş) depo her depoda geçer', ($pk['json']['ok'] ?? false) === true);
$pk = post_calistir(['employee_id' => '5'] + $temel, ['admin' => true]);
ok('pasif çalışan da işaretlenebilir (Pasifleri göster ile görünür)', ($pk['json']['ok'] ?? false) === true);

echo "--- tarih sınırı ---\n";
$gel = date('Y-m-d', strtotime('+11 months'));
$pk = post_calistir(['tarih' => $gel] + $temel, ['admin' => true]);
ok('gelecek tarihe (11 ay sonra) işaret serbest', ($pk['json']['ok'] ?? false) === true && ($pk['dump'][0]['tarih'] ?? '') === $gel);
$pk = post_calistir(['tarih' => date('Y-m-d', strtotime('+2 years +2 days'))] + $temel, ['admin' => true]);
ok('2 yıldan uzak tarih reddedilir', ($pk['json']['ok'] ?? null) === false && $pk['dump'] === []);

echo "--- tablo yok ---\n";
$pk = post_calistir($temel, ['admin' => true, 'isaret_yok' => true]);
ok('tablo kurulmamış → 503 JSON + migrate.php yönlendirmesi; ucu DDL ATMAZ (tablo hâlâ yok)', ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), 'migrate.php') && ($pk['dump'] ?? null) === null, $pk['out'] . $pk['err']);

echo "\n=== 4h. Statik: migrate kartı, yazma ucu, JS, CSS, sw ===\n";
$mig = oku('migrate.php'); $uc = oku('puantaj_isaret_kaydet.php'); $js = oku('assets/puantaj_cizelgesi.js'); $css = oku('assets/puantaj_cizelgesi.css'); $pcss = oku('assets/print_puantaj_cizelgesi.css'); $pil = oku('config/puantaj_isaret.php');
foreach (['migrate.php', 'puantaj_isaret_kaydet.php', 'config/puantaj_isaret.php'] as $f) { exec('php -l ' . escapeshellarg("$ROOT/$f") . ' 2>&1', $o, $rc); ok("php -l $f", $rc === 0, implode(' ', $o)); $o = []; }
ok("migrate.php: 'Puantaj İşaretleri' kartı mg_kart_ac('puantaj-isaret') ... mg_kart_kapat; ✓/✗ durumu; POST dalı ne=puantaj_isaret + csrf_check + pi_migrate + audit(migrate)", str_contains($mig, "mg_kart_ac('puantaj-isaret')") && str_contains($mig, '✓ Tablo var') && str_contains($mig, '✗ Tablo eksik')
    && (bool)preg_match("/'puantaj_isaret'\)\s*\{\s*\n\s*csrf_check/", $mig) && str_contains($mig, 'pi_migrate($pdo)') && str_contains($mig, "audit_log_event('migrate', 'puantaj_isaret'") && str_contains($mig, "require_once __DIR__ . '/config/puantaj_isaret.php'"));
ok('şema YALNIZ migrate kartında: pi_migrate çağıranı migrate.php; sayfalar/yazma ucu DDL ATMAZ (CREATE/ALTER yok)', substr_count($mig, 'pi_migrate(') === 1 && !str_contains($sayfa . $yaz . $lib . $uc, 'pi_migrate') && !preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $sayfa . $yaz . $lib . $uc));
ok('yazma ucu: yalnız POST (405), require_login, role_pdks_require_request, can kapıları (edit + employees), csrf_check, depot_visible_to_user, pi_hazir, audit_log_event', str_contains($uc, "'POST'") && str_contains($uc, '405') && str_contains($uc, 'require_login()') && str_contains($uc, 'role_pdks_require_request();')
    && str_contains($uc, "role_pdks_can('monthly_timesheet', 'edit')") && str_contains($uc, "role_pdks_can('employees')") && str_contains($uc, 'csrf_check(') && str_contains($uc, 'depot_visible_to_user(') && str_contains($uc, 'pi_hazir($pdo)') && str_contains($uc, 'audit_log_event($aksiyon'));
ok("yazma ucu: sorgular pozisyonel ?; SQL yalnız config/puantaj_isaret.php'de (uçta doğrudan SQL yok)", !preg_match('/\b(INSERT|UPDATE|DELETE|SELECT)\b/', $uc) && !preg_match('/:[a-z_]+\b.*=>|bindParam\(\s*[\'"]:/', $pil));
ok('JS: delegasyon data-pi, Esc kapatır, dışarı tık (ovl) kapatır, fetch POST same-origin + csrf, başarıda yenile, hata mesajı; <form> KULLANMAZ', str_contains($js, '[data-pi]') && str_contains($js, "'Escape'") && str_contains($js, "ovl.addEventListener('click', kapat)") && str_contains($js, "method: 'POST'") && str_contains($js, "credentials: 'same-origin'") && str_contains($js, "govde.set('csrf', CSRF)") && str_contains($js, 'window.location.reload()') && str_contains($js, 'Kaydedilemedi') && !str_contains($js, 'createElement(\'form\')') && !str_contains($js, '.submit()  //'));
ok('JS: yetkisiz/tablosuz sayfada (data-pi-url / piMenu yok) hiçbir işaret etkileşimi bağlanmaz', str_contains($js, "document.querySelector('.pc-page[data-pi-url]')") && str_contains($js, 'if (!sayfa || !menu || !ovl) return;'));
ok('CSS: menü z-index ≥ 600; mobil alt sayfa (bottom:0, safe-area); yazdırmada gizli; 5 kod rengi koyu temada da; var(--surface YOK; --pc-* belirteçleri', (bool)preg_match('/\.pi-menu \{[^}]*z-index: 6\d\d/', $css) && str_contains($css, 'env(safe-area-inset-bottom') && (bool)preg_match('/@media print \{ \.pi-menu, \.pi-ovl \{ display: none/', $css) && !preg_match('/var\(--surface/', preg_replace('#/\*.*?\*/#s', '', $css))
    && preg_match_all('/--pc-k(d|iz|uc|r|t)-bg/', $css) >= 10 && str_contains($css, 'html[data-theme="dark"] .pc-page') && (bool)preg_match('/\.pi-dugme \{[^}]*min-height: 36px/', $css) && (bool)preg_match('/\.pi-dugme \{ min-height: 48px/', $css));
ok('CSS: işaretli hücre hafta sonu gri (!important) zeminini ezer; yazdırma css: pcy-k harf + çerçeve + siyah-beyazda okunur', str_contains($css, '.pc-grid td.pc-k, .pc-t.pc-k { background: var(--k-bg) !important') && str_contains($pcss, 'td.pcy-k { position: relative; border: 1.2px solid') && str_contains($pcss, 'pcy-k-t') && str_contains($pcss, 'print-color-adjust: exact'));
ok("sw.js: yazma ucu POST → 'method !== GET' erken çıkışı (önbelleğe girmez)", (bool)preg_match("/if \(e\.request\.method !== 'GET'\) return;/", $sw));
ok("CLAUDE.md: puantaj satırında puantaj_isaretler + yazma ucu + 'edit' + migrate kartı notu", str_contains(oku('CLAUDE.md'), 'puantaj_isaretler') && str_contains(oku('CLAUDE.md'), 'puantaj_isaret_kaydet'));

echo "\n----------------------------------------------\n";
echo "Geçen: $gecen   Kalan: $fail\n";
exit($fail > 0 ? 1 : 0);
