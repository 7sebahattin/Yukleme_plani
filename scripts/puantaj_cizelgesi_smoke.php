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
ok('sayfa: sekme yoksa forbidden (pc_sekmeler_hazir boş; v554: Puantaj Girişi tablo hazırlığı da hesaba katılır)', (bool)preg_match('/\[\$sekmeler, \$girisHazir\] = pc_sekmeler_hazir\(\$pdo\);\s*if \(!\$sekmeler\) forbidden\(/', $sayfa));
ok("sayfa: csv/xlsx → require_perm('reports.export')", (bool)preg_match("/if \(\\\$csvIst \|\| \\\$xlsxIst\) require_perm\('reports\.export'\);/", $sayfa));
ok('sayfa: export_audit xlsx + csv (pdks / puantaj_cizelgesi)', substr_count($sayfa, "export_audit('pdks', 'puantaj_cizelgesi'") === 2 && str_contains($sayfa, "'xlsx'") && str_contains($sayfa, "'csv'"));
ok('sayfa: XLSX kolu CSV kolundan ÖNCE', strpos($sayfa, 'xlsx_indir(') !== false && strpos($sayfa, 'xlsx_indir(') < strpos($sayfa, 'text/csv'));
ok("sayfa: CSV BOM + fputcsv ';'", str_contains($sayfa, '\\xEF\\xBB\\xBF') && str_contains($sayfa, "';', '\"', '\\\\'"));
ok('sayfa: v553 — çizelge sayfaları $_POST / csrf_check / <form method=post> KULLANMAZ (yazma ucu ayrı dosya; sayfa yalnız csrf_token() basar)', !preg_match('/\$_POST|csrf_check|method="post"/i', $sayfa . $yaz . $lib) && !str_contains($yaz . $lib, 'csrf_token'));
ok('sayfa: kayıt yazan SQL YOK (INSERT/UPDATE/DELETE/ALTER/CREATE) — çizelge okuyucuları yalnız SELECT', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\b/', $lib . $sayfa . $yaz));
ok('sayfa: render_header + render_footer; yazdırma render_header ÇAĞIRMAZ',
    str_contains($sayfa, "render_header('Aylık Puantaj Çizelgesi')") && !preg_match('/render_header\s*\(/', $yaz) && str_contains($yaz, 'render_print_page_start('));
ok('yazdır: role_pdks_require_request + sekme kapısı (pc_sekmeler_hazir)', str_contains($yaz, 'role_pdks_require_request();') && str_contains($yaz, 'pc_sekmeler_hazir($pdo)') && str_contains($yaz, 'forbidden('));
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
ok('yönetici: üç sekme, sıra Puantaj Girişi · Personel · Günlük İşçi (v555: giris İLK)', pc_sekmeler() === ['giris', 'personel', 'gunluk']);
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
ok("hub: 'Puantaj Çizelgesi' kartı düz home-card + pc_sekmeler_hazir() (sayfanın açılabileceği sekme var) ile sarılı",
    (bool)preg_match('/<\?php if \(!empty\(pc_sekmeler_hazir\(db\(\)\)\[0\]\)\): \?>\s*<a href="puantaj_cizelgesi\.php" class="home-card">.*?Puantaj Çizelgesi.*?Aylık puantaj · personel ve günlük işçi.*?<\?php endif; \?>/s', $takip));
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
    $errDosya = tempnam(sys_get_temp_dir(), 'pcze_');   // v555: stderr dosyaya (15500 satırlık dump boruyu doldurup kilitlemesin); bu testin kendi geçici dosyası — harness'in "yeni dosya" sayımı 'pcz' önekini dışlar
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errDosya, 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $rc = proc_close($p);
    $err = (string)file_get_contents($errDosya); @unlink($errDosya);
    return ['rc' => $rc, 'out' => $out, 'err' => $err];
}
function satir_adlari(string $html): array { preg_match_all('/<th class="pc-isim" scope="row"><span class="pc-isim-yazi">(.*?)<\/span>/', $html, $m); return $m[1]; }
function izin(array $ham): array { return role_pdks_expand_permissions($ham); }
$bu = date('Y-m');
$yon = ['admin' => true, 'ay' => $bu, 'depo' => '', 'allowed' => ['Depo A']];

$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], $yon);
ok('yönetici/personel: sayfa çıktı verdi (rc 0), PHP uyarısı yok', $p['rc'] === 0 && !preg_match('/Warning:|Notice:|Fatal|Deprecated/', $p['out'] . $p['err']), $p['err'] . substr($p['out'], 0, 300));
$ad = satir_adlari($p['out']);
ok('depo kuralı: Depo A + atanmamış görünür; Depo B ve pasif GİZLİ', $ad === ['Örnek Personel A', 'Örnek Personel B', 'Örnek Personel C', 'İsmail Örnek'], json_encode($ad, JSON_UNESCAPED_UNICODE));
ok('ay = bu ay → ızgara başlığında gün sayısı kadar pc-g sütunu', preg_match_all('/class="pc-g[ "]/', $p['out']) === (int)date('t'));
ok('bugünün sütunu çerçeveli (data-bugun + pc-bugun)', str_contains($p['out'], 'data-bugun') && str_contains($p['out'], 'pc-bugun'));
ok('hafta sonu sütunları işaretli (pc-hs başlıkta)', preg_match_all('/class="pc-g pc-hs/', $p['out']) >= 8);
ok('A satırı: 3 hücre X + 1 hücre ! ve özet', preg_match('/Örnek Personel A.*?<\/tr>/s', $p['out'], $ma) && substr_count($ma[0], 'pc-x') === 3 && substr_count($ma[0], 'pc-e') === 1 && str_contains($ma[0], '25,5') && str_contains($ma[0], '! 1'));
ok('mobil liste + ızgara ikisi de basılı; kişi başına details + 7 sütunlu takvim', substr_count($p['out'], '<details class="pc-kisi" data-emp-kisi=') === 4 && substr_count($p['out'], 'class="pc-takvim"') === 4 && str_contains($p['out'], 'class="table-wrap pc-grid-wrap"'));
ok('lejant + sekme çubuğu (yönetici: 3 sekme; tablo kurulu değil → varsayılan Personel)', str_contains($p['out'], 'pc-lejant') && preg_match_all('/class="pc-sekme[ "]/', $p['out']) === 3 && str_contains($p['out'], 'data-tur="personel"'));
ok('GET-only: formda method=get, csrf/post yok', str_contains($p['out'], 'method="get"') && !preg_match('/method="post"|name="csrf"/i', $p['out']));
ok('Excel menüsü + Yazdır bağlantısı (yönetici)', str_contains($p['out'], 'dl-menu') && str_contains($p['out'], 'puantaj_cizelgesi_yazdir.php?'));
ok('mobil gösterge düğmesi (ⓘ) + ay giriş alanı type=month', str_contains($p['out'], 'pc-lejant-dugme') && str_contains($p['out'], 'type="month"'));

$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'pasif' => '1'], $yon);
ok("'Pasifleri de göster': pasif personel eklenir (Depo B yine gizli)", in_array('Örnek Personel E', satir_adlari($p['out']), true) && !in_array('Örnek Personel D', satir_adlari($p['out']), true));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['admin' => true, 'ay' => $bu, 'allowed' => ['Depo A', 'Depo B']]);
ok('kullanıcı iki depoya yetkili: Depo B personeli de görünür', in_array('Örnek Personel D', satir_adlari($p['out']), true));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'q' => 'ismail'], $yon);
ok("arama 'ismail' → yalnız 'İsmail Örnek'; arama kutusu değeri korunur", satir_adlari($p['out']) === ['İsmail Örnek'] && str_contains($p['out'], 'value="ismail"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => '2028-02', 'sekme' => 'personel'], ['admin' => true, 'ay' => '2028-02']);
ok('Şubat 2028: 29 gün sütunu', preg_match_all('/class="pc-g[ "]/', $p['out']) === 29 && $p['rc'] === 0);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => 'saçma', 'sekme' => 'personel'], $yon);
ok('geçersiz ay → bu aya düşer (500 yok)', $p['rc'] === 0 && preg_match_all('/class="pc-g[ "]/', $p['out']) === (int)date('t'));

$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], $yon);
ok('günlük sekme: not "kart / çavuş bazında" + çavuş satırları', $p['rc'] === 0 && str_contains($p['out'], 'kart / çavuş bazında') && satir_adlari($p['out']) === ['Örnek Çavuş 1', 'Örnek Çavuş 2'], json_encode(satir_adlari($p['out']), JSON_UNESCAPED_UNICODE) . substr($p['out'], 0, 200));
ok('günlük: Çavuş 1 bugün 2 işçi + ! (eksik çıkış), Çavuş 2 bugün 2 işçi', preg_match('/Örnek Çavuş 1.*?<\/tr>/s', $p['out'], $mc1) && str_contains($mc1[0], '<b>2</b><i>!</i>') && preg_match('/Örnek Çavuş 2.*?<\/tr>/s', $p['out'], $mc2) && str_contains($mc2[0], '<b>2</b>') && !str_contains($mc2[0], '<i>!</i>'));
ok('günlük: alt toplam satırı "O gün toplam işçi" = 4, genel 4 işçi-gün', str_contains($p['out'], 'O gün toplam işçi') && preg_match('/pc-bugun">4<\/td>/', $p['out']) === 1);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk', 'q' => 'zzz'], $yon);
ok('günlük arama sonucu boş → bilgi kutusu', str_contains($p['out'], 'pc-bos') && !str_contains($p['out'], 'class="pc-grid"'));

echo "\n=== 3b. Yetki matrisi (gerçek role_pdks_require_request) ===\n";
$sadeceSayfa = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => $sadeceSayfa, 'ay' => $bu]);
ok('yalnız sayfa Gör (sekme izni yok) → forbidden', $p['rc'] === 3 && str_contains($p['out'], 'FORBIDDEN'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => [], 'ay' => $bu]);
ok('izinsiz kullanıcı → forbidden', $p['rc'] === 3);
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => [], 'ay' => $bu]);
ok('yazdırma: izinsiz → forbidden', $p['rc'] === 3);
$pers = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], ['perms' => $pers, 'ay' => $bu]);
ok('yalnız personel izni: ?sekme=gunluk istense de personel sekmesi, tek sekme → sekme çubuğu YOK', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="personel"') && !str_contains($p['out'], 'class="pc-sekmeler"') && !str_contains($p['out'], 'dl-menu'), substr($p['out'], 0, 200));
$gun = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.daily_reports.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => $gun, 'ay' => $bu, 'depo' => '']);
ok('yalnız günlük izni: varsayılan sekme günlük işçi', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="gunluk"'));
$ikisi = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read', 'attendance.daily_reports.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => $ikisi, 'ay' => $bu]);
ok('iki izin: iki sekme; reports.export yok → Excel menüsü basılmaz', $p['rc'] === 0 && preg_match_all('/class="pc-sekme[ "]/', $p['out']) === 2 && !str_contains($p['out'], 'dl-menu'));
$eski = izin(['attendance.daily_reports']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => $eski, 'ay' => $bu]);
ok('ESKİ rol (attendance.daily_reports): sayfa açılır, yalnız günlük sekmesi', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="gunluk"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'csv' => '1'], ['perms' => $ikisi, 'ay' => $bu]);
ok('reports.export yok → ?csv forbidden', $p['rc'] === 3 && str_contains($p['out'], 'reports.export'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'xlsx' => '1'], ['perms' => $ikisi, 'ay' => $bu]);
ok('reports.export yok → ?xlsx forbidden', $p['rc'] === 3 && str_contains($p['out'], 'reports.export'));

echo "\n=== 3c. CSV / XLSX / audit ===\n";
$exp = array_merge($ikisi, ['reports.export']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'csv' => '1'], ['perms' => $exp, 'ay' => $bu]);
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
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'personel'], $yon);
ok('yazdır: rc 0, pcy-tablo, sidebar/container YOK, @page landscape', $p['rc'] === 0 && str_contains($p['out'], 'pcy-tablo') && !str_contains($p['out'], '<main class="container">') && str_contains($p['out'], 'A4 landscape'), substr($p['out'], 0, 200) . $p['err']);
ok('yazdır: thead + tfoot, 4 kişi, lejant', str_contains($p['out'], '<thead>') && str_contains($p['out'], 'GENEL TOPLAM (4)') && str_contains($p['out'], 'pcy-lejant'));
ok('yazdır: X hücreleri pcy-x, eksik pcy-e (renk + harf)', substr_count($p['out'], 'class="pcy-x') >= 4 && str_contains($p['out'], 'class="pcy-e'));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'gunluk'], $yon);
ok('yazdır (günlük): çavuş satırları + kart bazlı notu', $p['rc'] === 0 && str_contains($p['out'], 'Örnek Çavuş 1') && str_contains($p['out'], 'kart / çavuş bazında'));

echo "\n=== 3e. Şema hazır değil → nazik mesaj, 500 yok ===\n";
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['admin' => true, 'ay' => $bu, 'sema' => 'yok']);
ok('personel tabloları yok: rc 0 + açıklayıcı mesaj + tablo yok', $p['rc'] === 0 && str_contains($p['out'], 'Personel tabloları henüz hazır değil') && !str_contains($p['out'], 'class="pc-grid"'), $p['err'] . substr($p['out'], 0, 300));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], ['admin' => true, 'ay' => $bu, 'sema' => 'yok']);
ok('günlük şema yok: rc 0 + açıklayıcı mesaj', $p['rc'] === 0 && str_contains($p['out'], 'Günlük işçi modülü tabloları henüz hazır değil'), $p['err'] . substr($p['out'], 0, 300));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], ['admin' => true, 'ay' => $bu, 'sema' => 'personel']);
ok('yalnız personel şeması hazır: günlük sekmesi nazik mesaj, personel sekmesi çalışır', $p['rc'] === 0 && str_contains($p['out'], 'tabloları henüz hazır değil'));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'personel'], ['admin' => true, 'ay' => $bu, 'sema' => 'yok']);
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
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
$say = substr_count($p['out'], ' data-pi data-emp=');
ok('yönetici + tablo hazır: hücreler düzenlenebilir (ızgara + mobil takvim = 2 × 4 kişi × gün), data-pi-url + csrf özniteliği', $p['rc'] === 0 && $say === 2 * 4 * (int)date('t') && str_contains($p['out'], 'data-pi-url="puantaj_isaret_kaydet.php"') && str_contains($p['out'], 'data-pi-csrf="testcsrf"'), (string)$say . $p['err']);
ok('menü: 5 kod düğmesi (D İ Ü R T) + Temizle; <form> YOK; hata alanı role=alert', $p['rc'] === 0 && preg_match_all('/data-pi-kod="[DİÜRT]"/u', $p['out']) === 5 && str_contains($p['out'], 'data-pi-kod=""') && str_contains($p['out'], 'id="piMenu"') && str_contains($p['out'], 'role="alert"') && !preg_match('/<form[^>]*method="post"/i', $p['out']));
ok('işaretli hücreler: İ/D/R/T/Ü sınıfları (renk + harf); kayıt çakışan 3 Kasım hücresinde nokta (pc-kn)', str_contains($p['out'], 'pc-k pc-k-iz') && str_contains($p['out'], 'pc-k pc-k-d') && str_contains($p['out'], 'pc-k pc-k-r') && str_contains($p['out'], 'pc-k pc-k-t') && str_contains($p['out'], 'pc-k pc-k-uc') && str_contains($p['out'], 'class="pc-kn"') && str_contains($p['out'], 'data-kod="İ"'));
ok('XSS: işaret açıklaması title içinde KAÇIRILIR (&lt;script&gt;), ham <script>x yok', str_contains($p['out'], '&lt;script&gt;x&lt;/script&gt;') && !str_contains($p['out'], '<script>x</script>'));
ok('özet sütunları: başlıkta İzin/Rapor/Devamsız; A satırı 3 Kasım X yerine İ → yalnız 4 ve 12\'si X (2 gün, 16 saat; işaretli gün sayılmaz), eksik 1', str_contains($p['out'], '>Devamsız</th>') && preg_match('/Örnek Personel A.*?<\/tr>/s', $p['out'], $ma) === 1 && substr_count($ma[0], 'pc-x') === 2 && str_contains($ma[0], '>16<') && str_contains($ma[0], '! 1'), $ma[0] ?? '');
ok('lejant: kod rozetleri + "tıklayarak işaretleyin" ipucu (düzenleyen için)', str_contains($p['out'], 'pc-kl-liste') && str_contains($p['out'], 'Güne tıklayarak'));
ok('mobil gösterge ⓘ arkasında (pc-lejant-ic içinde) ve özet rozetleri (izin/rapor/devamsız) basılı', str_contains($p['out'], 'pc-lejant-dugme') && preg_match('/pc-lejant-ic".*?pc-kl-liste/s', $p['out']) === 1 && str_contains($p['out'], 'devamsız</span>'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => $edit, 'ay' => $bu, 'isaretler' => $ISR]);
ok("ayrıntılı rol + açık 'edit' + Personel kartları Gör: düzenleme açık", $p['rc'] === 0 && str_contains($p['out'], ' data-pi data-emp=') && str_contains($p['out'], 'id="piMenu"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => $salt, 'ay' => $bu, 'isaretler' => $ISR]);
ok("'edit' YOK: işaretler GÖRÜNÜR ama hücre salt okunur (data-pi / data-pi-url / menü / pc-duz / ipucu YOK)", $p['rc'] === 0 && str_contains($p['out'], 'pc-k pc-k-iz') && !str_contains($p['out'], 'data-pi') && !str_contains($p['out'], 'piMenu') && !str_contains($p['out'], 'pc-duz') && !str_contains($p['out'], 'Güne tıklayarak'), substr($p['out'], 0, 100));
$eski = izin(['attendance.employees']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => $eski, 'ay' => $bu, 'isaretler' => $ISR]);
ok("ESKİ rol (attendance.employees): sayfa açılır, 'edit' otomatik AÇILMAZ → salt okunur", $p['rc'] === 0 && str_contains($p['out'], 'data-tur="personel"') && !str_contains($p['out'], 'data-pi'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
ok('GÜNLÜK İŞÇİ sekmesi AYNEN: işaret arayüzü/özet sütunları/menü YOK', $p['rc'] === 0 && !str_contains($p['out'], 'data-pi') && !str_contains($p['out'], 'piMenu') && !str_contains($p['out'], '>Devamsız</th>') && !str_contains($p['out'], 'pc-kl-liste'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['admin' => true, 'ay' => $bu, 'isaret_yok' => true]);
ok("tablo YOK (migrate edilmemiş): çizelge eskisi gibi çalışır (rc 0, A satırı X/!), işaretleme arayüzü gizli, yönetici için 'migrate.php#puantaj-isaret' notu", $p['rc'] === 0 && str_contains($p['out'], 'class="pc-grid"') && !str_contains($p['out'], 'data-pi') && !str_contains($p['out'], 'piMenu') && !str_contains($p['out'], 'pc-kl-liste')
    && str_contains($p['out'], 'migrate.php#puantaj-isaret') && str_contains($p['out'], 'Puantaj İşaretleri') && substr_count($p['out'], 'pc-x') >= 3, $p['err'] . substr($p['out'], 0, 200));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['perms' => $salt, 'ay' => $bu, 'isaret_yok' => true]);
ok('tablo yok + yönetici olmayan kullanıcı: not GÖRÜNMEZ', $p['rc'] === 0 && !str_contains($p['out'], 'puantaj-isaret') && str_contains($p['out'], 'class="pc-grid"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], ['admin' => true, 'ay' => $bu, 'sema' => 'yok']);
ok('personel tabloları da yok: nazik hata, işaret notu/menü yok (500 yok)', $p['rc'] === 0 && str_contains($p['out'], 'henüz hazır değil') && !str_contains($p['out'], 'puantaj-isaret') && !str_contains($p['out'], 'piMenu'));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'personel'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
ok('yazdırma: işaretli hücreler pcy-k pcy-k-iz/-d/-r/-t/-uc (harf), İzin/Rapor/Devamsız başlıkları, lejantta kodlar, XSS kaçırılmış (yazdırmada title yok)', $p['rc'] === 0 && str_contains($p['out'], 'pcy-k pcy-k-iz') && str_contains($p['out'], 'pcy-k pcy-k-d') && str_contains($p['out'], 'pcy-k pcy-k-t') && str_contains($p['out'], 'pcy-k pcy-k-uc') && str_contains($p['out'], '>Devamsız</th>') && str_contains($p['out'], 'D = Devamsız') && !str_contains($p['out'], '<script>x</script>') && str_contains($p['out'], 'colspan="6"'), $p['err'] . substr($p['out'], 0, 200));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'personel'], ['admin' => true, 'ay' => $bu, 'isaret_yok' => true]);
ok('yazdırma + tablo yok: lejantta kod yok, sayfa çalışır', $p['rc'] === 0 && !str_contains($p['out'], 'D = Devamsız') && str_contains($p['out'], 'pcy-tablo'));

echo "\n=== 4f. CSV / XLSX: işaret harfleri + yeni özet sütunları, audit (q metni YOK) ===\n";
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'csv' => '1'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
$satirlar = preg_split('/\r?\n/', trim(substr($p['out'], 0, 3) === "\xEF\xBB\xBF" ? substr($p['out'], 3) : $p['out']));
$ilk = str_getcsv($satirlar[0], ';', '"', '\\');
$a1 = null; foreach ($satirlar as $sr) { $c = str_getcsv($sr, ';', '"', '\\'); if ($c[0] === 'Örnek Personel A') $a1 = $c; }
ok('CSV: başlık son 3 sütun İzin;Rapor;Devamsız (38 sütun)', array_slice($ilk, -3) === ['İzin', 'Rapor', 'Devamsız'] && count($ilk) === 2 + (int)date('t') + 6, json_encode($ilk, JSON_UNESCAPED_UNICODE));
ok('CSV: A satırı — 3. gün "İ" (formül ön eki yok), 6. gün "D"; özet 5. ve 6. sütunlar izin=1 · rapor=0 · devamsız=1', $a1 !== null && $a1[4] === 'İ' && $a1[7] === 'D' && array_slice($a1, -3) === ['1', '0', '1'], json_encode($a1, JSON_UNESCAPED_UNICODE));
ok('CSV: audit q-metni kuralı — aranan metin audit\'e YAZILMAZ (yalnız arama:true)', str_contains($p['err'], '"report":"puantaj_cizelgesi"') && !str_contains($p['err'], 'gizli arama'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'csv' => '1', 'q' => 'gizli arama'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
ok('CSV + q: audit filtresinde "arama":true, metin YOK', str_contains($p['err'], '"arama":true') && !str_contains($p['err'], 'gizli arama'), $p['err']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'xlsx' => '1'], ['admin' => true, 'ay' => $bu, 'isaretler' => $ISR]);
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

// ───────────────────────── 5. v554 PUANTAJ GİRİŞİ (bağımsız, gayri resmi sekme) ─────────────────────────
echo "\n=== 5a. Statik: dosyalar, bağımsızlık, şema, kapılar ===\n";
foreach (['config/puantaj_giris.php', 'config/puantaj_giris_ui.php', 'puantaj_giris_kaydet.php', 'assets/puantaj_giris.js'] as $f) ok("$f var", is_file("$ROOT/$f"));
foreach (['config/puantaj_giris.php', 'config/puantaj_giris_ui.php', 'puantaj_giris_kaydet.php', 'config/puantaj_cizelgesi.php', 'puantaj_cizelgesi.php', 'puantaj_cizelgesi_yazdir.php', 'migrate.php', 'config/role_pdks_matrix.php'] as $f) {
    exec('php -l ' . escapeshellarg("$ROOT/$f") . ' 2>&1', $o, $rc); ok("php -l $f", $rc === 0, implode(' ', $o)); $o = [];
}
exec('node --check ' . escapeshellarg("$ROOT/assets/puantaj_giris.js") . ' 2>&1', $o, $rc); ok('node --check assets/puantaj_giris.js', $rc === 0, implode(' ', $o)); $o = [];
$kod_yorumsuz = static function (string $f): string {   // PHP yorumları atılmış kod (bağımsızlık denetimi yalnız çalışan koda bakar)
    $out = '';
    foreach (token_get_all(oku($f)) as $t) { if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $out .= $t[1]; } else $out .= $t; }
    return $out;
};
$pgk = $kod_yorumsuz('config/puantaj_giris.php') . $kod_yorumsuz('puantaj_giris_kaydet.php') . $kod_yorumsuz('config/puantaj_giris_ui.php');
ok('BAĞIMSIZ: giriş kodu employees / attendance_* / puantaj_isaretler / daily_* / worker_* / foreman / hakediş / cavus / pdks_* tablo-fonksiyonlarına HİÇ değinmez', !preg_match('/\b(employees|attendance_\w+|puantaj_isaretler|daily_\w+|worker_\w+|foreman\w*|hakedis\w*|entitlement\w*|cavus\w*|employee_cards)\b/i', $pgk) && !preg_match('/\bpdks_(?!aktif)\w+\s*\(/i', $pgk), (string)(preg_match('/\b(employees|attendance_\w+|puantaj_isaretler|daily_\w+|worker_\w+|foreman\w*|hakedis\w*|cavus\w*)\b/i', $pgk, $mm) ? $mm[0] : ''));
preg_match_all('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|FROM|JOIN)\s+`?([a-z_]+)`?/i', $kod_yorumsuz('config/puantaj_giris.php') . $kod_yorumsuz('puantaj_giris_kaydet.php'), $tm);
$tablolar = array_values(array_unique(array_map('strtolower', $tm[1])));
sort($tablolar);
ok('BAĞIMSIZ: SQL yalnız puantaj_giris_kisiler + puantaj_giris_gunler tablolarına', $tablolar === ['puantaj_giris_gunler', 'puantaj_giris_kisiler'], json_encode($tablolar));
$ddl = pg_tablolar();
ok('şema: iki tablo, IF NOT EXISTS, ALTER / FOREIGN KEY / REFERENCES YOK', array_keys($ddl) === ['puantaj_giris_kisiler', 'puantaj_giris_gunler'] && !preg_match('/ALTER|FOREIGN|REFERENCES/i', implode(' ', $ddl)) && substr_count(implode(' ', $ddl), 'CREATE TABLE IF NOT EXISTS') === 2);
ok('şema kisiler: id · ad VARCHAR(120) NOT NULL · gorev VARCHAR(80) NULL · depo NOT NULL DEFAULT \'\' · aktif TINYINT(1) DEFAULT 1 · sira INT DEFAULT 0 · created_by · created_at · updated_at',
    str_contains($ddl['puantaj_giris_kisiler'], '`id`         INT AUTO_INCREMENT PRIMARY KEY') && (bool)preg_match('/`ad`\s+VARCHAR\(120\)\s+NOT NULL/', $ddl['puantaj_giris_kisiler']) && (bool)preg_match('/`gorev`\s+VARCHAR\(80\)\s+NULL/', $ddl['puantaj_giris_kisiler'])
    && (bool)preg_match("/`depo`\s+VARCHAR\(120\)\s+NOT NULL DEFAULT ''/", $ddl['puantaj_giris_kisiler']) && (bool)preg_match('/`aktif`\s+TINYINT\(1\)\s+NOT NULL DEFAULT 1/', $ddl['puantaj_giris_kisiler']) && (bool)preg_match('/`sira`\s+INT\s+NOT NULL DEFAULT 0/', $ddl['puantaj_giris_kisiler'])
    && str_contains($ddl['puantaj_giris_kisiler'], '`created_by`') && str_contains($ddl['puantaj_giris_kisiler'], '`created_at`') && str_contains($ddl['puantaj_giris_kisiler'], '`updated_at`'));
ok('şema gunler: kisi_id · tarih DATE · kod VARCHAR(4) · user_id · created_at · updated_at; UNIQUE(kisi_id, tarih) + tarih indeksi', (bool)preg_match('/`kod`\s+VARCHAR\(4\)\s+NOT NULL/', $ddl['puantaj_giris_gunler']) && (bool)preg_match('/`tarih`\s+DATE\s+NOT NULL/', $ddl['puantaj_giris_gunler'])
    && str_contains($ddl['puantaj_giris_gunler'], 'UNIQUE KEY `uq_pg_gun` (`kisi_id`, `tarih`)') && str_contains($ddl['puantaj_giris_gunler'], 'KEY `idx_pg_gun_tarih` (`tarih`)') && str_contains($ddl['puantaj_giris_gunler'], '`user_id`'));
// pg_migrate: kanca ile (gerçek DB'ye dokunmaz); idempotent
$bos = new PDO('sqlite::memory:'); $bos->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_pg_ddl_calistir'] = function (PDO $pdo, string $ad, string $d) use (&$cagrilar): void { $cagrilar[] = [$ad, $d]; $pdo->exec('CREATE TABLE `' . $ad . '` (id INTEGER)'); };
$cagrilar = [];
$r1 = pg_migrate($bos); $r2 = pg_migrate($bos);
unset($GLOBALS['_pg_ddl_calistir']);
ok('pg_migrate: ilk çalıştırma iki tabloyu oluşturur, ikinci çalıştırma "var" der (idempotent); kanca yalnız CREATE TABLE IF NOT EXISTS aldı',
    array_column($r1, 'durum') === ['olusturuldu', 'olusturuldu'] && array_column($r2, 'durum') === ['var', 'var'] && count($cagrilar) === 2 && !preg_match('/ALTER|FOREIGN/i', implode(' ', array_column($cagrilar, 1))));
ok('pg_hazir: tablo yokken false (hata fırlatmaz)', pg_hazir(new PDO('sqlite::memory:')) === false);
ok("migrate.php: 'Puantaj Girişi' kartı mg_kart_ac('puantaj-giris'); ✓/✗; ne=puantaj_giris + csrf_check + pg_migrate + audit(migrate); pi kartından SONRA", str_contains($mig, "mg_kart_ac('puantaj-giris')") && str_contains($mig, '✓ Tablolar var') && str_contains($mig, '✗ Tablolar eksik')
    && (bool)preg_match("/'puantaj_giris'\)\s*\{\s*\n\s*csrf_check/", $mig) && str_contains($mig, 'pg_migrate($pdo)') && str_contains($mig, "audit_log_event('migrate', 'puantaj_giris'") && str_contains($mig, "require_once __DIR__ . '/config/puantaj_giris.php'") && strpos($mig, "mg_kart_ac('puantaj-giris')") > strpos($mig, "mg_kart_ac('puantaj-isaret')"));
$sayfa = oku('puantaj_cizelgesi.php'); $yaz = oku('puantaj_cizelgesi_yazdir.php'); $lib = oku('config/puantaj_cizelgesi.php');
$kaydet = oku('puantaj_giris_kaydet.php'); $pgjs = oku('assets/puantaj_giris.js'); $css = oku('assets/puantaj_cizelgesi.css'); $pgl = oku('config/puantaj_giris.php');
ok('şema YALNIZ migrate kartında: pg_migrate çağıranı yalnız migrate.php; sayfalar/yazma ucu/UI DDL ATMAZ', substr_count($mig, 'pg_migrate(') === 1 && !preg_match('/pg_migrate\(/', $sayfa . $yaz . $lib . $kaydet . oku('config/puantaj_giris_ui.php')) && !preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $sayfa . $yaz . $lib . $kaydet . oku('config/puantaj_giris_ui.php')));
ok("matris: puantaj_giris_kaydet.php rotası monthly_timesheet; POST = ['monthly_timesheet','edit']; GET = read; diğer rotalar değişmedi", (role_pdks_routes()['puantaj_giris_kaydet.php'] ?? '') === 'monthly_timesheet'
    && role_pdks_request_action('puantaj_giris_kaydet.php', 'POST', [], ['action' => 'x!', 'form' => 'x!']) === ['monthly_timesheet', 'edit'] && role_pdks_request_action('puantaj_giris_kaydet.php', 'GET') === ['monthly_timesheet', 'read']
    && role_pdks_request_action('puantaj_isaret_kaydet.php', 'POST') === ['monthly_timesheet', 'edit'] && role_pdks_request_action('puantaj_cizelgesi.php', 'POST') === null && role_pdks_request_action('personel.php', 'POST') === null);
// yazma ucu kapı sırası
$sira = ['require_login()', "'POST'", 'role_pdks_require_request();', "role_pdks_can('monthly_timesheet', 'edit')", 'csrf_check(', 'enforce_active_depot()', 'active_depot()', 'pg_hazir($pdo)'];
$konum = array_map(fn($n) => strpos($kaydet, $n), $sira);
ok('yazma ucu: kapı sırası = login → POST → rota kapısı → edit → CSRF → aktif depo → tablo hazır', !in_array(false, $konum, true) && $konum === array_values(array_unique($konum)) && $konum === (function ($k) { $s = $k; sort($s); return $s; })($konum), json_encode($konum));
ok('yazma ucu: action beyaz liste (v555: 11 eylem), employees izni GEREKMEZ (bağımsız), depot_visible_to_user, audit_log_event puantaj_giris, doğrudan SQL yok',
    str_contains($kaydet, "['kisi_ekle', 'kisi_duzenle', 'kisi_durum', 'gun_isaretle', 'gun_toplu', 'gun_sutun', 'kisi_sil', 'kisi_toplu', 'gun_toplu_sec', 'excel_onizle', 'excel_ekle']") && !str_contains($kaydet, "role_pdks_can('employees')") && str_contains($kaydet, 'depot_visible_to_user(') && substr_count($kaydet, "'puantaj_giris'") >= 3 && !preg_match('/\b(INSERT|UPDATE|DELETE|SELECT)\s/', $kaydet));
preg_match_all("/'((?:SELECT|INSERT|UPDATE|DELETE)[^']*)'/", $kod_yorumsuz('config/puantaj_giris.php'), $sqlm);
ok('SQL pozisyonel ?: tüm sorgu dizgelerinde adlandırılmış parametre (:ad) YOK; WHERE/VALUES içerenlerde ? var', count($sqlm[1]) >= 12 && !array_filter($sqlm[1], fn($q) => preg_match('/:[a-z_]+/', $q)) && !array_filter($sqlm[1], fn($q) => preg_match('/\b(WHERE|VALUES)\b/', $q) && !str_contains($q, '?') && !str_ends_with($q, 'WHERE ') && !str_ends_with($q, 'IN (')), (string)count($sqlm[1]));   // WHERE ile biten parça: ? koşulları depo_sql_in()'den pozisyonel eklenir; "IN (" ile biten parça: ? yer tutucuları implode(array_fill) ile pozisyonel eklenir
ok('JS: fetch POST same-origin + csrf, Esc, innerHTML YOK, <form> YOK, data-pg-url yoksa bağlanmaz; gün seçimi/⋯/kişi ekle/toplu atama (v555: gun_sutun artık UI\'dan çağrılmaz)', !preg_match('/\.innerHTML|insertAdjacentHTML|document\.write/', $pgjs) && !str_contains($pgjs, "createElement('form')") && str_contains($pgjs, "method: 'POST'") && str_contains($pgjs, "credentials: 'same-origin'") && str_contains($pgjs, "g.set('csrf', CSRF)") && str_contains($pgjs, "'Escape'")
    && str_contains($pgjs, ".pc-page[data-pg-url]") && str_contains($pgjs, 'data-pg-gun') && str_contains($pgjs, 'data-pg-kisi-ekle') && !str_contains($pgjs, "'gun_sutun'") && str_contains($pgjs, "'gun_toplu'") && str_contains($pgjs, "'kisi_durum'")
    && str_contains($pgjs, "'gun_toplu_sec'") && str_contains($pgjs, "'Content-Type': 'application/json'") && str_contains($pgjs, 'window.pgApi') && str_contains($pgjs, 'data-pg-sec-kod'));   // v555: gun_sutun UI'dan ÇIKTI (uç eylemi durur)
$ui = oku('config/puantaj_giris_ui.php');
ok('UI: <form> ELEMENTİ yok (.pm-dialog > form tuzağı), JS gövdesi yalnız fetch; CSS pencere z-index ≥ 600, mobil alt sayfa, 16px input, var(--surface YOK', !preg_match('/<form\b/i', $kod_yorumsuz('config/puantaj_giris_ui.php')) && (bool)preg_match('/\.pg-pen \{[^}]*z-index: 6\d\d/', $css) && (bool)preg_match('/@media \(max-width: 767px\) \{\s*\.pg-pen \{[^}]*bottom: 0/', $css) && (bool)preg_match('/\.pg-pen-alan input \{ font-size: 16px/', $css) && !preg_match('/var\(--surface/', preg_replace('#/\*.*?\*/#s', '', $css)));
ok('CLAUDE.md: v554 puantaj satırı (Puantaj Girişi, puantaj_giris_kisiler/gunler, migrate kartı, puantaj_giris_kaydet.php, gayri resmi / employees ilişkisiz)', str_contains(oku('CLAUDE.md'), 'puantaj_giris_kisiler') && str_contains(oku('CLAUDE.md'), 'puantaj_giris_kaydet.php') && str_contains(oku('CLAUDE.md'), 'gayri resmi'));

echo "\n=== 5b. Saf fonksiyonlar: kodlar, doğrulayıcılar, tablo kurucu, dışa aktarım ===\n";
ok('pg_kodlar: X=1 Y=0,5 D İ Ü R T=0; sıra X Y D İ Ü R T; mevcut işaret sözlüğüyle (pi_kodlar) uyumlu', array_keys(pg_kodlar()) === ['X', 'Y', 'D', 'İ', 'Ü', 'R', 'T'] && pg_kodlar()['X']['agirlik'] === 1.0 && pg_kodlar()['Y']['agirlik'] === 0.5 && pg_kodlar()['T']['agirlik'] === 0.0 && pg_kodlar()['D']['sinif'] === pi_kodlar()['D']['sinif'] && pg_kodlar()['Ü']['grup'] === 'izin');
ok('pg_kod_gecerli: 7 kod geçerli; küçük harf / iki harf / boş / dizi / null geçersiz', pg_kod_gecerli('X') && pg_kod_gecerli('Y') && pg_kod_gecerli('İ') && !pg_kod_gecerli('x') && !pg_kod_gecerli('XX') && !pg_kod_gecerli('') && !pg_kod_gecerli(['X']) && !pg_kod_gecerli(null) && !pg_kod_gecerli('i'));
ok('pg_ad_dogrula: trim + boşluk sadeleştirme + kontrol karakteri atılır', pg_ad_dogrula("  Örnek   Kişi A  ") === 'Örnek Kişi A' && pg_ad_dogrula("Ör\x00nek\tKişi\n") === 'Ör nek Kişi' && pg_ad_dogrula('=HYPERLINK("x")') === '=HYPERLINK("x")');
ok('pg_ad_dogrula: boş / yalnız boşluk / dizi / null / 121 karakter → null; 120 karakter (çok baytlı) geçerli', pg_ad_dogrula('') === null && pg_ad_dogrula("   \t ") === null && pg_ad_dogrula(['a']) === null && pg_ad_dogrula(null) === null && pg_ad_dogrula(str_repeat('ş', 121)) === null && pg_ad_dogrula(str_repeat('ş', 120)) === str_repeat('ş', 120));
ok('pg_gorev_dogrula: boş izinli (\'\'), 80 geçerli, 81 → null', pg_gorev_dogrula('') === '' && pg_gorev_dogrula(str_repeat('ü', 80)) === str_repeat('ü', 80) && pg_gorev_dogrula(str_repeat('ü', 81)) === null && pg_gorev_dogrula(null) === '');
ok('pi_tarih_dogrula (yeniden kullanılır): gerçek takvim günü ve ±2 yıl', pi_tarih_dogrula('2026-02-30', '2026-11-22') === null && pi_tarih_dogrula('2026-11-22', '2026-11-22') === '2026-11-22' && pi_tarih_dogrula('2029-01-01', '2026-11-22') === null && pi_tarih_dogrula('2024-11-23', '2026-11-22') === '2024-11-23' && pi_tarih_dogrula('2024-11-21', '2026-11-22') === null);
$kis = [
    ['id' => 1, 'ad' => 'Örnek Kişi A', 'gorev' => 'Paketçi', 'depo' => 'Depo A', 'aktif' => 1, 'sira' => 0],
    ['id' => 2, 'ad' => 'İsmail Örnek', 'gorev' => '', 'depo' => 'Depo A', 'aktif' => 1, 'sira' => 0],
    ['id' => 3, 'ad' => 'Pasif Kişi', 'gorev' => '', 'depo' => 'Depo A', 'aktif' => 0, 'sira' => 0],
];
$gn = [1 => ['2026-11-02' => 'X', '2026-11-03' => 'Y', '2026-11-04' => 'Y', '2026-11-05' => 'D', '2026-11-06' => 'İ', '2026-11-07' => 'Ü', '2026-11-09' => 'R', '2026-11-10' => 'T', '2026-10-31' => 'X', '2026-11-11' => 'Z'],
       2 => ['2026-11-02' => 'X', '2026-11-03' => 'X'], 3 => ['2026-11-02' => 'X']];
$tg = pg_tablo_kur('2026-11', $kis, $gn);
$ad_sirasi = array_column($tg['satirlar'], 'id');
ok('tablo: kişi sırası verilen sırayı korur (sıralama pg_kisiler_oku\'da); 3 satır, pasif bayrağı yalnız 3. kişide', $ad_sirasi === [1, 2, 3] && $tg['satirlar'][0]['alt'] === 'Paketçi' && $tg['satirlar'][2]['pasif'] === true && $tg['satirlar'][0]['pasif'] === false);
$A = $tg['satirlar'][0]; $B = $tg['satirlar'][1]; $C = $tg['satirlar'][2];
ok('özet A: çalışılan gün = X + 0,5·Y = 1 + 0,5 + 0,5 = 2 ; izin = İ+Ü = 2 ; rapor 1 ; devamsız 1 (T, geçersiz Z, ay dışı yok sayıldı)', abs($A['ozet']['gun'] - 2.0) < 1e-9 && $A['ozet']['izin'] === 2 && $A['ozet']['rapor'] === 1 && $A['ozet']['devamsiz'] === 1);
ok('geçersiz kod (Z) ve ay dışı (31 Ekim) hücre OLUŞTURMAZ; T hücresi var ama hiçbir özete girmez', !isset($A['hucre']['2026-11-11']) && !isset($A['hucre']['2026-10-31']) && $A['hucre']['2026-11-10']['k'] === 'T' && count($A['hucre']) === 8);
ok('özet B: 2 gün; C (pasif) 1 gün', $B['ozet']['gun'] === 2.0 && $C['ozet']['gun'] === 1.0);
ok('o gün çalışan: 2 Kasım = 3 (X,X,X) · 3 Kasım = 2 (Y,X) · 4 Kasım = 1 (Y) · 5 Kasım (D) = 0 · 10 Kasım (T) = 0', $tg['gun_say']['2026-11-02'] === 3 && $tg['gun_say']['2026-11-03'] === 2 && $tg['gun_say']['2026-11-04'] === 1 && $tg['gun_say']['2026-11-05'] === 0 && $tg['gun_say']['2026-11-10'] === 0);
ok('genel: 3 kişi · gün 2 + 2 + 1 = 5 · izin 2 · rapor 1 · devamsız 1', $tg['genel'] === ['kisi' => 3, 'gun' => 5.0, 'izin' => 2, 'rapor' => 1, 'devamsiz' => 1]);
$tq = pg_tablo_kur('2026-11', $kis, $gn, 'ISMAIL');
ok("arama: 'ISMAIL' → İsmail Örnek (Türkçe duyarsız); görev metninde de arar ('paket'); toplamlar süzgeçle yeniden hesaplanır", count($tq['satirlar']) === 1 && $tq['satirlar'][0]['id'] === 2 && $tq['genel']['kisi'] === 1 && $tq['genel']['gun'] === 2.0 && count(pg_tablo_kur('2026-11', $kis, $gn, 'paket')['satirlar']) === 1 && pg_tablo_kur('2026-11', $kis, $gn, 'zzz')['satirlar'] === []);
ok('boş veri: Şubat 2028 (29 gün), hata yok, genel 0', count(pg_tablo_kur('2028-02', $kis, [])['gunler']) === 29 && pg_tablo_kur('2028-02', $kis, [])['genel']['gun'] === 0.0);
ok('ondalık gösterim: 2,5 gün "2,5", 2 gün "2", 0 "0" (pg_gun_fmt)', pg_gun_fmt(2.5) === '2,5' && pg_gun_fmt(2.0) === '2' && pg_gun_fmt(0.0) === '0' && pg_gun_fmt(12.5) === '12,5');
[$c1, $i1, $t1] = pg_hucre_goster(['k' => 'X', 'd' => 1.0]); [$c2, $i2] = pg_hucre_goster(['k' => 'Y', 'd' => 0.5]); [$c3, $i3] = pg_hucre_goster(['k' => 'İ', 'd' => 0.0]); [$c4, $i4] = pg_hucre_goster(null);
ok('hücre gösterimi (renk + HARF): X → pc-k pc-k-cx; Y → pc-k-y + ½; İ → pc-k-iz; boş → pc-b ·', $c1 === 'pc-k pc-k-cx' && $i1 === '<b>X</b>' && $t1 === 'Çalıştı' && $c2 === 'pc-k pc-k-y' && $i2 === '<b>Y</b><i>½</i>' && $c3 === 'pc-k pc-k-iz' && $i3 === '<b>İ</b>' && $c4 === 'pc-b' && $i4 === '·'
    && pc_hucre_goster('giris', ['k' => 'R', 'd' => 0.0])[0] === 'pc-k pc-k-r');
$bg = pc_disa_baslik($tg); $sg = pc_disa_satirlar($tg);
ok('dışa aktarım başlık: Ad Soyad · Görev · 30 gün · Çalışılan Gün · İzin · Rapor · Devamsız (36 sütun)', count($bg) === 36 && $bg[0] === 'Ad Soyad' && $bg[1] === 'Görev' && $bg[2] === '1 Paz' && array_slice($bg, -4) === ['Çalışılan Gün', 'İzin', 'Rapor', 'Devamsız']);
ok('dışa aktarım satırları: A → harfler (X/Y/D/İ…), boş gün null; özet 2 / 2 / 1 / 1; "O gün çalışan" 2 Kasım = 3; GENEL TOPLAM (3 kişi) gün 5', $sg[0][3] === 'X' && $sg[0][4] === 'Y' && $sg[0][6] === 'D' && $sg[0][2] === null && array_slice($sg[0], -4) === [2.0, 2, 1, 1] && $sg[3][0] === 'O gün çalışan' && $sg[3][3] === 3 && str_starts_with($sg[4][0], 'GENEL TOPLAM (3 kişi)') && array_slice($sg[4], -4) === [5.0, 2, 1, 1] && array_reduce($sg, fn($c, $r) => $c && count($r) === 36, true));
$sh = pc_xlsx_sayfa($tg, 'Açık');
ok('xlsx sayfa tanımı: gün sütunları sayi, Çalışılan Gün ondalik, toplam=false, başlık "gayri resmi"', count($sh['sutunlar']) === 36 && $sh['sutunlar'][2]['tip'] === 'sayi' && $sh['sutunlar'][32]['tip'] === 'ondalik' && $sh['toplam'] === false && str_contains($sh['baslik'], 'gayri resmi') && str_contains($sh['baslik'], 'Kasım 2026'));
if (is_file($ROOT . '/vendor/autoload.php')) {
    $zehir = pg_tablo_kur('2026-11', [['id' => 9, 'ad' => '=HYPERLINK("http://x","t")', 'gorev' => '@SUM(1)', 'depo' => '', 'aktif' => 1, 'sira' => 0]], [9 => ['2026-11-03' => 'Y']]);
    $ws = xlsx_olustur([pc_xlsx_sayfa($zehir)])->getActiveSheet();
    $zr = 0; foreach ($ws->getRowIterator() as $row) { if (str_starts_with((string)$ws->getCell('A' . $row->getRowIndex())->getValue(), '=HYPERLINK')) $zr = $row->getRowIndex(); }
    ok('XLSX: ad / görev formül gibi başlasa da METİN hücresi; Y harfi metin; çalışılan gün SAYI 0,5', $zr > 0 && $ws->getCell('A' . $zr)->getDataType() === 's' && $ws->getCell('B' . $zr)->getDataType() === 's' && (string)$ws->getCell('E' . $zr)->getValue() === 'Y' && $ws->getCell('E' . $zr)->getDataType() === 's' && (float)$ws->getCell('AG' . $zr)->getValue() === 0.5 && $ws->getCell('AG' . $zr)->getDataType() === 'n');
}
$lejG = pc_lejant_metni('giris');
ok('lejant (giris): yedi kod + hafta sonu; personel lejantı değişmedi', str_contains($lejG, 'X = Çalıştı') && str_contains($lejG, 'Y = Yarım gün') && str_contains($lejG, 'T = Resmi tatil') && str_contains($lejG, 'gri sütun = hafta sonu') && str_starts_with(pc_lejant_metni('personel', false), 'X = geldi'));

echo "\n=== 5c. Sekme / varsayılan sekme / yetki kuralı ===\n";
$GRANTS = []; $ADMIN = false;
$GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.monthly_timesheet.edit']);
ok("yalnız sayfa read+edit (kaynak izni YOK): sekmeler = ['giris'] — bağımsız veri, employees/daily_reports izni gerekmez", pc_sekmeler() === ['giris']);
$GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read', 'attendance.monthly_timesheet.edit']);
ok("read+edit+Personel kartları: ['giris','personel'] (v555: giris İLK)", pc_sekmeler() === ['giris', 'personel']);
$GRANTS = role_pdks_expand_permissions(['attendance.employees', 'attendance.daily_reports']);
ok("eski roller (edit otomatik YOK) → giris sekmesi YOK: ['personel','gunluk']", pc_sekmeler() === ['personel', 'gunluk']);
$GRANTS = [];
ok("pc_sekme_sec (v555): parametre YOK → listenin İLKİ; giris görünüyorsa (listede ilk) varsayılan açılış 'giris'; görünmüyorsa eski davranış (ilk = personel)", pc_sekme_sec('', ['giris', 'personel', 'gunluk']) === 'giris' && pc_sekme_sec('', ['giris']) === 'giris' && pc_sekme_sec('', ['personel', 'gunluk']) === 'personel' && pc_sekme_sec('', ['gunluk']) === 'gunluk');
ok("pc_sekme_sec: açık sekme isteği saygı görür (personel / gunluk / giris); geçersiz istek → ilk izinli (giris DEĞİL); giris izinli değilse eski davranış", pc_sekme_sec('personel', ['personel', 'gunluk', 'giris']) === 'personel' && pc_sekme_sec('giris', ['personel', 'giris']) === 'giris' && pc_sekme_sec('x', ['personel', 'giris']) === 'personel' && pc_sekme_sec('', ['personel', 'gunluk']) === 'personel' && pc_sekme_sec('giris', ['personel']) === 'personel' && pc_sekme_sec('', []) === null);
ok('sekme adları: Personel · Günlük İşçi · Puantaj Girişi', pc_sekme_adlari() === ['personel' => 'Personel', 'gunluk' => 'Günlük İşçi', 'giris' => 'Puantaj Girişi']);
$pdoHazir = new PDO('sqlite::memory:'); $pdoHazir->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoHazir->exec('CREATE TABLE puantaj_giris_kisiler (id INTEGER)'); $pdoHazir->exec('CREATE TABLE puantaj_giris_gunler (id INTEGER)');
$pdoYok = new PDO('sqlite::memory:');
$GRANTS = role_pdks_expand_permissions(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read', 'attendance.monthly_timesheet.edit']);
ok("pc_sekmeler_hazir: tablo HAZIR → [giris, personel] + hazır=true; tablo YOK + yetkili ama yönetici değil → giris GİZLİ ([personel], hazır=false)", pc_sekmeler_hazir($pdoHazir) === [['giris', 'personel'], true] && pc_sekmeler_hazir($pdoYok) === [['personel'], false]);
$GRANTS = []; $ADMIN = true;
ok("pc_sekmeler_hazir: tablo YOK + yönetici → giris GÖRÜNÜR ve İLK (kurulum notu için), hazır=false", pc_sekmeler_hazir($pdoYok) === [['giris', 'personel', 'gunluk'], false] && pc_sekmeler_hazir($pdoHazir)[1] === true);
$ADMIN = false; $GRANTS = [];

echo "\n=== 5d. Gerçek sayfa: Puantaj Girişi sekmesi (bellek içi SQLite harness) ===\n";
$GB = ['admin' => true, 'ay' => $bu, 'giris' => true, 'depo' => 'Depo A', 'allowed' => ['Depo A'],
    'giris_kisiler' => [[1, 'Örnek Kişi A', 'Paketçi', 'Depo A', 1], [2, 'Örnek Kişi B', '', '', 1], [3, 'Örnek Kişi C', '', 'Depo B', 1], [4, 'Örnek Kişi D', '', 'Depo A', 0], [5, 'İsmail Örnek', 'Şoför', 'Depo A', 1]],
    'giris_gunler' => [[1, 2, 'X'], [1, 3, 'Y'], [1, 4, 'D'], [1, 5, 'İ'], [2, 3, 'R'], [3, 3, 'X'], [4, 3, 'X']]];
function giris_ad_listesi(string $html): array { preg_match_all('/<tr data-pg-satir="\d+"[^>]*>\s*<th class="pc-isim" scope="row"><span class="pg-isim-kap">(?:<label class="pg-secbox">.*?<\/label>|<span class="pg-secbox pg-secbox-bos"[^>]*><\/span>)<span class="pg-isim-metin"><span class="pc-isim-yazi">(.*?)(?: <em|<\/span>)/s', $html, $m); return $m[1]; }
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], $GB);
ok('yönetici + tablolar hazır + sekme parametresi YOK → VARSAYILAN sekme Puantaj Girişi (rc 0, PHP uyarısı yok)', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="giris"') && !preg_match('/Warning:|Notice:|Fatal|Deprecated/', $p['out'] . $p['err']), $p['err'] . substr($p['out'], 0, 200));
ok('sekme çubuğu (v555): Puantaj Girişi (ilk, aktif) · Personel · Günlük İşçi', preg_match_all('/class="pc-sekme[ "]/', $p['out']) === 3 && (bool)preg_match('/<a[^>]*is-aktif[^>]*>Puantaj Girişi<\/a>\s*<a[^>]*>Personel<\/a>\s*<a[^>]*>Günlük İşçi<\/a>/', $p['out']));
ok('depo kuralı: Depo A + atanmamış görünür; Depo B kişisi ve PASİF GİZLİ', giris_ad_listesi($p['out']) === ['İsmail Örnek', 'Örnek Kişi A', 'Örnek Kişi B'], json_encode(giris_ad_listesi($p['out']), JSON_UNESCAPED_UNICODE));
ok('lejant yedi kod + bilgi satırı "gayri resmi / göstermeliktir; PDKS, hakediş ve diğer modüllerle ilişkisi yoktur"', str_contains($p['out'], 'pg-bilgi') && str_contains($p['out'], 'Bu alan gayri resmi / göstermeliktir; PDKS, hakediş ve diğer modüllerle ilişkisi yoktur.') && preg_match_all('/class="pc-kl pc-k pc-k-(cx|y|d|iz|uc|r|t)"/', $p['out']) === 7);
ok('+ Kişi ekle düğmesi (data-pg-kisi-ekle); Excel menüsü + Yazdır (sekme=giris)', str_contains($p['out'], 'data-pg-kisi-ekle') && str_contains($p['out'], 'dl-menu') && str_contains($p['out'], 'puantaj_cizelgesi_yazdir.php?sekme=giris'));
ok('sayfa kökünde data-pg-url / csrf / ay / kod sözlüğü JSON (geçerli); puantaj_giris.js yüklenir', (bool)preg_match('/data-pg-url="puantaj_giris_kaydet\.php" data-pg-csrf="testcsrf" data-pg-ay="' . preg_quote($bu, '/') . '"/', $p['out']) && preg_match('/data-pg-kodlar="([^"]*)"/', $p['out'], $mk) === 1 && count(json_decode(html_entity_decode($mk[1]), true) ?? []) === 7 && str_contains($p['out'], 'assets/puantaj_giris.js'));
ok('POST formu YOK: <form method=post>, csrf alanı, innerHTML yok — yalnız GET formu + data-* ; pencerelerde <form> yok', !preg_match('/method="post"|name="csrf"/i', $p['out']) && substr_count($p['out'], '<form') === 1 && str_contains($p['out'], 'id="pgPen"'));
ok('Pasifleri göster onay kutusu bu sekmede de var; arama yer tutucusu "Ad veya görev ara…"', str_contains($p['out'], 'name="pasif"') && str_contains($p['out'], 'Ad veya görev ara'));
ok('satır A (X + Y = Gün 1,5 · İzin 1 · Rapor 0 · Devamsız 1): Y hücresi ½ ile; özet td\'leri data-oz', preg_match('/data-pg-satir="1".*?<\/tr>/s', $p['out'], $ma) === 1 && str_contains($ma[0], '<b>Y</b><i>½</i>') && str_contains($ma[0], 'data-oz="gun">1,5<') && str_contains($ma[0], 'data-oz="izin">1<') && str_contains($ma[0], 'data-oz="rapor">0<') && str_contains($ma[0], 'data-oz="devamsiz">1<'), $ma[0] ?? '');
ok('hücre sözleşmesi: td.pc-duz data-pg data-emp data-tarih data-kod (her gün); gün başlığında data-pg-gun düğmesi; "O gün çalışan" td data-pg-say; genel toplam data-gz-oz',
    substr_count($p['out'], 'data-pg-gun="') === (int)date('t') && preg_match_all('/<td class="pc-h [^"]*pc-duz[^"]*" [^>]*data-pg data-emp="1"/', $p['out']) === (int)date('t') && substr_count($p['out'], 'data-pg-say="') === (int)date('t') && str_contains($p['out'], 'data-gz-oz="gun"'));
ok('hafta sonu sütunları gri + işaretlenebilir (pc-hs ve data-pg birlikte)', preg_match('/<td class="pc-h [^"]*pc-hs[^"]*pc-duz[^"]*"[^>]*data-pg /', $p['out']) === 1 || preg_match('/<td class="pc-h [^"]*pc-hs[^"]*"[^>]*data-pg /', $p['out']) === 1);
ok('mobil: kişi başına details + 7 sütunlu takvim (3) + ⋯ düğmesi + rozet özeti data-rz; genel kart data-gz', substr_count($p['out'], '<details class="pc-kisi') === 3 && substr_count($p['out'], 'class="pc-takvim"') === 3 && substr_count($p['out'], 'data-pg-kisi="') === 6 && str_contains($p['out'], 'data-rz="gun"') && str_contains($p['out'], 'data-gz="gun"'));
ok('genel toplam: 3 kişi, gün 1,5 (yalnız A: X + Y/2; B yalnız rapor)', preg_match('/data-gz-oz="gun">1,5</', $p['out']) === 1 && preg_match('/<span data-gz="kisi">3<\/span> kişi/', $p['out']) === 1);

$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], $GB);
ok("açık ?sekme=personel istenirse Personel açılır (varsayılan kural yalnız sekme parametresi YOKKEN)", $p['rc'] === 0 && str_contains($p['out'], 'data-tur="personel"') && !str_contains($p['out'], 'data-pg-url'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], $GB);
ok('?sekme=gunluk → Günlük İşçi (etkilenmedi)', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="gunluk"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris', 'pasif' => '1'], $GB);
ok("'Pasifleri de göster': pasif kişi satırı (pg-pasif, '(pasif)'), hücreleri DÜZENLENEMEZ (data-pg yok), ⋯ menüsünde data-pasif=1; Depo B kişisi yine gizli", $p['rc'] === 0 && str_contains($p['out'], 'Örnek Kişi D') && !str_contains($p['out'], 'Örnek Kişi C') && preg_match('/<tr data-pg-satir="4" class="pg-pasif">.*?<\/tr>/s', $p['out'], $mp) === 1 && !str_contains($mp[0], 'data-pg ') && str_contains($mp[0], 'data-pasif="1"') && str_contains($mp[0], '(pasif)') && !str_contains($mp[0], 'pc-duz'), substr($mp[0] ?? '', 0, 300));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris', 'q' => 'ismail'], $GB);
ok("arama 'ismail' → İsmail Örnek; arama kutusu değeri korunur; data-pg-q (sütun işlemi aynı süzgeci kullanır)", giris_ad_listesi($p['out']) === ['İsmail Örnek'] && str_contains($p['out'], 'value="ismail"') && str_contains($p['out'], 'data-pg-q="ismail"'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris', 'q' => 'zzz'], $GB);
ok("arama sonucu boş → 'Aramanıza uyan kişi yok.'", str_contains($p['out'], 'Aramanıza uyan kişi yok.') && !str_contains($p['out'], 'class="pc-grid'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB, ['giris_kisiler' => [], 'giris_gunler' => []]));
ok("boş durum: 'Henüz kişi eklemediniz' + büyük '+ Kişi ekle' (pg-buyuk); ızgara yok; pencereler hazır (kişi ekle açılabilir)", str_contains($p['out'], 'Henüz kişi eklemediniz') && str_contains($p['out'], 'pg-buyuk') && !str_contains($p['out'], 'class="pc-grid') && str_contains($p['out'], 'id="pgPen"') && str_contains($p['out'], 'data-pg-url'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB, ['giris_kisiler' => [[4, 'Örnek Kişi D', '', 'Depo A', 0]], 'giris_gunler' => []]));
ok("yalnız pasif kişi varken: 'Henüz kişi eklemediniz' DEĞİL; 'Aktif kişi yok' + Pasifleri göster ipucu", !str_contains($p['out'], 'Henüz kişi eklemediniz') && str_contains($p['out'], 'Aktif kişi yok'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB, ['giris_kisiler' => [[1, '<script>alert(1)</script>', '"><img src=x onerror=alert(2)>', 'Depo A', 1]], 'giris_gunler' => []]));
ok('XSS: ad ve görev h() ile kaçırılır (script / img etiketi sayfada YOK; data-isim özniteliği kaçırılmış)', !str_contains($p['out'], '<script>alert(1)') && !str_contains($p['out'], '<img src=x') && str_contains($p['out'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($p['out'], 'data-gorev=""><img'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => '2028-02', 'sekme' => 'giris'], array_merge($GB, ['ay' => '2028-02']));
ok('Şubat 2028: 29 gün sütunu (artık yıl)', $p['rc'] === 0 && substr_count($p['out'], 'data-pg-gun="') === 29);

echo "--- tablo kurulu DEĞİL (migrate edilmemiş site) ---\n";
$YOK = array_merge($GB, ['giris' => false]);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], $YOK);
ok('yönetici: tablo yokken de VARSAYILAN sekme Puantaj Girişi (v555) — kurulum notu görünür, 500 yok; sekme çubuğunda 3 sekme', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="giris"') && str_contains($p['out'], 'migrate.php#puantaj-giris') && preg_match_all('/class="pc-sekme[ "]/', $p['out']) === 3 && !preg_match('/Warning:|Notice:|Fatal|Deprecated/', $p['out'] . $p['err']));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris'], $YOK);
ok("yönetici + ?sekme=giris + tablo yok: net mesaj 'Önce migrate.php › Puantaj Girişi kartını çalıştırın' + #puantaj-giris bağlantısı; sayfa çöker/500 vermez; Kişi ekle düğmesi YOK", $p['rc'] === 0 && str_contains($p['out'], 'Puantaj Girişi tabloları henüz kurulmamış') && str_contains($p['out'], 'migrate.php#puantaj-giris') && str_contains($p['out'], 'kartını çalıştırın') && !str_contains($p['out'], 'data-pg-kisi-ekle') && !str_contains($p['out'], 'data-pg-url') && !preg_match('/Warning:|Notice:|Fatal/', $p['out'] . $p['err']), substr($p['out'], 0, 200));
$edP = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.monthly_timesheet.edit', 'attendance.employees.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris'], array_merge($YOK, ['admin' => false, 'perms' => $edP]));
ok('yönetici OLMAYAN edit rolü + tablo yok: Puantaj Girişi sekmesi GİZLİ (tek sekme Personel; ?sekme=giris → Personel); sayfa çalışır', $p['rc'] === 0 && str_contains($p['out'], 'data-tur="personel"') && !str_contains($p['out'], 'Puantaj Girişi') && !str_contains($p['out'], 'migrate.php'));
$sadEd = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.monthly_timesheet.edit']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($YOK, ['admin' => false, 'perms' => $sadEd]));
ok('yalnız edit rolü + tablo yok: gösterilecek sekme yok → temiz 403 (500 değil)', $p['rc'] === 3 && str_contains($p['out'], 'FORBIDDEN'));

echo "--- yetki: edit rolü ---\n";
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB, ['admin' => false, 'perms' => $sadEd]));
ok("yalnız sayfa read+edit (employees / daily_reports İZNİ YOK): Puantaj Girişi AÇILIR ve VARSAYILAN; tek sekme → sekme çubuğu yok; düzenleme etkin", $p['rc'] === 0 && str_contains($p['out'], 'data-tur="giris"') && !str_contains($p['out'], 'class="pc-sekmeler"') && str_contains($p['out'], 'data-pg-url') && str_contains($p['out'], 'data-pg-kisi-ekle') && !str_contains($p['out'], 'dl-menu'), substr($p['out'], 0, 200));
$salt = izin(['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris'], array_merge($GB, ['admin' => false, 'perms' => $salt]));
ok("yalnız read (edit YOK): Puantaj Girişi sekmesi GÖRÜNMEZ; ?sekme=giris → Personel; hiçbir data-pg yok", $p['rc'] === 0 && str_contains($p['out'], 'data-tur="personel"') && !str_contains($p['out'], 'data-pg') && !str_contains($p['out'], 'Puantaj Girişi'));
$eskiR = izin(['attendance.employees', 'attendance.daily_reports']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB, ['admin' => false, 'perms' => $eskiR]));
ok("eski rol: giris sekmesi YOK, varsayılan davranış AYNEN (Personel)", $p['rc'] === 0 && str_contains($p['out'], 'data-tur="personel"') && !str_contains($p['out'], 'Puantaj Girişi'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB, ['admin' => false, 'perms' => []]));
ok('izinsiz → forbidden', $p['rc'] === 3);

echo "--- CSV / XLSX / yazdır / audit ---\n";
$exp = array_merge($edP, ['reports.export']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris', 'csv' => '1'], array_merge($GB, ['admin' => false, 'perms' => $edP]));
ok('reports.export YOK → ?csv forbidden (giris da aynı kapı)', $p['rc'] === 3 && str_contains($p['out'], 'reports.export'));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris', 'csv' => '1', 'q' => 'gizli arama'], array_merge($GB, ['giris_kisiler' => [[1, '=HYPERLINK("http://x","t")', '+görev', 'Depo A', 1], [2, 'Örnek Kişi B', '', 'Depo A', 1]]]));
$icerik = substr($p['out'], 0, 3) === "\xEF\xBB\xBF" ? substr($p['out'], 3) : $p['out'];
ok("CSV (giris): arama süzgeci uygulanır ('gizli arama' → 0 kişi, yalnız başlık + toplam satırları), audit: sekme=giris, arama:true, metin YOK", $p['rc'] === 0 && str_contains($p['err'], '"sekme":"giris"') && str_contains($p['err'], '"arama":true') && !str_contains($p['err'], 'gizli arama'), $p['err']);
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris', 'csv' => '1'], array_merge($GB, ['giris_kisiler' => [[1, '=HYPERLINK("http://x","t")', '+görev', 'Depo A', 1], [2, 'Örnek Kişi B', '', 'Depo A', 1]], 'giris_gunler' => [[2, 2, 'X'], [2, 3, 'Y'], [2, 4, 'İ']]]));
$satirlar = preg_split('/\r?\n/', trim(substr($p['out'], 0, 3) === "\xEF\xBB\xBF" ? substr($p['out'], 3) : $p['out']));
$ilk = str_getcsv($satirlar[0], ';', '"', '\\');
$sB = null; $sZ = null; foreach ($satirlar as $sr) { $c = str_getcsv($sr, ';', '"', '\\'); if ($c[0] === 'Örnek Kişi B') $sB = $c; if (str_contains($c[0], 'HYPERLINK')) $sZ = $c; }
ok('CSV (giris): BOM + ; ayraç, başlık Ad Soyad;Görev;1 …;Çalışılan Gün;İzin;Rapor;Devamsız', substr($p['out'], 0, 3) === "\xEF\xBB\xBF" && $ilk[0] === 'Ad Soyad' && $ilk[1] === 'Görev' && array_slice($ilk, -4) === ['Çalışılan Gün', 'İzin', 'Rapor', 'Devamsız'] && count($ilk) === 2 + (int)date('t') + 4, json_encode($ilk, JSON_UNESCAPED_UNICODE));
ok("CSV (giris): kişi satırı harflerle (X, Y, İ) ve çalışılan gün '1,5' (1 ondalık, virgül); ad '=HYPERLINK' → başına ' (formül enjeksiyonu), görev '+görev' → '+görev", $sB !== null && $sB[3] === 'X' && $sB[4] === 'Y' && $sB[5] === 'İ' && array_slice($sB, -4) === ['1,5', '1', '0', '0'] && $sZ !== null && str_starts_with($sZ[0], "'=HYPERLINK") && $sZ[1] === "'+görev", json_encode([$sB, $sZ], JSON_UNESCAPED_UNICODE));
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris', 'xlsx' => '1'], array_merge($GB, ['admin' => false, 'perms' => $exp]));
ok('XLSX (giris): zip başlığı PK + audit xlsx / giris; reports.export + edit yetkisiyle', substr($p['out'], 0, 2) === 'PK' && str_contains($p['err'], '"format":"xlsx"') && str_contains($p['err'], '"sekme":"giris"'), $p['err']);
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'giris'], $GB);
ok("yazdır (giris): rc 0, başlık 'PUANTAJ GİRİŞİ', bilgi satırı, lejant, özet başlıkları Gün/İzin/Rapor/Devamsız (Saat/Eksik YOK), işaretli hücreler pcy-k pcy-k-cx / pcy-k-y, 'o gün çalışan', render_header yok",
    $p['rc'] === 0 && str_contains($p['out'], 'PUANTAJ GİRİŞİ') && str_contains($p['out'], 'gayri resmi / göstermeliktir') && str_contains($p['out'], 'X = Çalıştı') && preg_match('/<th class="pcy-oz">Gün<\/th>\s*<th class="pcy-oz">İzin<\/th><th class="pcy-oz">Rapor<\/th><th class="pcy-oz pcy-dv">Devamsız<\/th>/', $p['out']) === 1 && !str_contains($p['out'], '>Saat<') && !str_contains($p['out'], '>Eksik<')
    && str_contains($p['out'], 'pcy-k pcy-k-cx') && str_contains($p['out'], 'pcy-k pcy-k-y') && str_contains($p['out'], 'O gün çalışan') && !str_contains($p['out'], 'class="sidebar'), substr($p['out'], 0, 300));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'giris'], array_merge($GB, ['admin' => false, 'perms' => $salt]));
ok('yazdır: edit yetkisi yoksa giris yazdırılamaz (Personel\'e düşer)', $p['rc'] === 0 && str_contains($p['out'], 'PERSONEL') && !str_contains($p['out'], 'PUANTAJ GİRİŞİ'));
$p = sayfa_calistir('puantaj_cizelgesi_yazdir.php', ['ay' => $bu, 'sekme' => 'giris'], $YOK);
ok('yazdır: tablo yok → hata mesajı, 500 yok', $p['rc'] === 0 && str_contains($p['out'], 'Puantaj Girişi tabloları henüz kurulmamış'));

echo "\n=== 5e. Yazma ucu puantaj_giris_kaydet.php (gerçek role_pdks_require_request + kapılar) ===\n";
function giris_post(array $post, array $opt = []): array
{
    global $GB;
    $r = sayfa_calistir('puantaj_giris_kaydet.php', [], array_merge($GB, ['dump_giris' => true, 'tum_tablolar' => true, 'post' => $post], $opt));
    $r['json'] = json_decode($r['out'], true);
    $r['kisiler'] = preg_match('/GIRIS_KISILER: (.*)/', $r['err'], $m) ? json_decode($m[1], true) : null;
    $r['gunler'] = preg_match('/GIRIS_GUNLER: (.*)/', $r['err'], $m) ? json_decode($m[1], true) : null;
    $r['sayilar'] = preg_match('/SAYILAR: (.*)/', $r['err'], $m) ? json_decode($m[1], true) : null;
    return $r;
}
function aud(string $err): string { return implode("\n", preg_grep('/^AUDIT:/', explode("\n", $err))); }   // yalnız audit satırları (dump satırları kişi adı içerir)
function gun_say_kisi(?array $gunler, int $kisi): int { return count(array_filter($gunler ?? [], fn($g) => (int)$g['kisi_id'] === $kisi)); }
$T = ['csrf' => 'testcsrf'];
$g2 = "$bu-02"; $g6 = "$bu-06"; $g7 = "$bu-07";

echo "--- kapılar ---\n";
$pk = giris_post(['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z'], ['admin' => true]);
ok('CSRF YOK → 403 + JSON (rc 5), DB yazılmadı, audit YOK', $pk['rc'] === 5 && ($pk['json']['code'] ?? 0) === 403 && count($pk['kisiler']) === 5 && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
$pk = giris_post(['csrf' => 'yanlis', 'action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z']);
ok('CSRF YANLIŞ → 403 + JSON, DB yazılmadı', $pk['rc'] === 5 && count($pk['kisiler']) === 5);
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z'], ['admin' => false, 'perms' => []]);
ok('izinsiz kullanıcı → 403 (rota kapısı), DB yazılmadı', $pk['rc'] === 3 && ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5);
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z'], ['admin' => false, 'perms' => $salt]);
ok("yalnız 'read' (edit yok) → 403 JSON, DB yazılmadı", $pk['rc'] === 3 && ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5, $pk['out']);
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z'], ['admin' => false, 'perms' => $eskiR]);
ok("ESKİ rol (attendance.employees + daily_reports; edit otomatik YOK) → 403", $pk['rc'] === 3 && count($pk['kisiler']) === 5);
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z'], ['admin' => false, 'perms' => izin(['role.permissions.detailed', 'attendance.monthly_timesheet.edit', 'attendance.employees.read'])]);
ok("'edit' var ama sayfa 'read' YOK → 403", $pk['rc'] === 3 && count($pk['kisiler']) === 5);
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z'], ['admin' => false, 'perms' => $sadEd]);
ok("BAĞIMSIZ: yalnız sayfa read+edit (Personel kartları / günlük rapor izni YOK) → YAZAR; kişi depo damgalı", ($pk['json']['ok'] ?? false) === true && count($pk['kisiler']) === 6 && end($pk['kisiler'])['depo'] === 'Depo A', $pk['out'] . $pk['err']);
$pk = sayfa_calistir('puantaj_giris_kaydet.php', [], array_merge($GB, ['admin' => true]));
ok('GET → 405 JSON (yalnız POST)', str_contains($pk['out'], '"ok":false') && str_contains($pk['out'], 'Geçersiz istek'));
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z'], ['depo' => '']);
ok("aktif depo YOK → 400 'Önce bir depo seçmelisiniz', yazılmadı", ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), 'depo') && count($pk['kisiler']) === 5);
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z'], ['giris' => false]);
ok("tablolar kurulmamış → 503 JSON + migrate.php yönlendirmesi; uç DDL ATMAZ (tablo hâlâ yok)", ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), 'migrate.php') && $pk['kisiler'] === null, $pk['out']);
foreach ([[], ['action' => ''], ['action' => 'sil'], ['action' => 'KISI_EKLE'], ['action' => ['kisi_ekle']], ['action' => 'kisi_ekle; DROP TABLE x']] as $kotu) {
    $pk = giris_post($T + $kotu + ['ad' => 'Örnek Kişi Z']);
    ok('geçersiz / eksik action reddedilir (400): ' . json_encode($kotu, JSON_UNESCAPED_UNICODE), ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5 && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
}

echo "--- kisi_ekle / kisi_duzenle / kisi_durum ---\n";
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => '  Örnek   Kişi Z ', 'gorev' => ' Depocu ']);
$yeniZ = end($pk['kisiler']);
ok('kisi_ekle: ok + kisi_id; satır: ad sadeleşmiş, görev, depo = aktif depo, aktif=1, sira=0', ($pk['json']['ok'] ?? false) === true && ($pk['json']['kisi_id'] ?? 0) === 6 && $yeniZ === ['id' => 6, 'ad' => 'Örnek Kişi Z', 'gorev' => 'Depocu', 'depo' => 'Depo A', 'aktif' => 1, 'sira' => 0], json_encode($yeniZ, JSON_UNESCAPED_UNICODE) . $pk['out']);
ok("kisi_ekle audit: create / puantaj_giris / id / eski null / yeni {aktif, depo, ad_uzunluk, gorev_uzunluk}; kişi ADI audit'te YOK", str_contains($pk['err'], 'AUDIT: ["create","puantaj_giris",6,null,{"aktif":1,"depo":"Depo A","ad_uzunluk":12,"gorev_uzunluk":6}]') && !str_contains(aud($pk['err']), 'Örnek Kişi Z') && !str_contains(aud($pk['err']), 'Depocu'), $pk['err']);
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Y'], ['depo' => 'Depo B', 'allowed' => ['Depo B']]);
ok('yeni kişi AKTİF depo ile damgalanır (Depo B seçiliyken Depo B)', ($pk['json']['ok'] ?? false) === true && end($pk['kisiler'])['depo'] === 'Depo B' && end($pk['kisiler'])['gorev'] === null);
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => "Ör\x00nek\x07 Kişi\n"]);
ok('ad kontrol karakterleri atılır (NUL / BEL / satır sonu → boşluk, kırpılır)', ($pk['json']['ok'] ?? false) === true && end($pk['kisiler'])['ad'] === 'Ör nek Kişi');
foreach ([['ad' => ''], ['ad' => '   '], ['ad' => str_repeat('ş', 121)], ['ad' => ['a']], ['gorev' => str_repeat('ü', 81)], ['gorev' => ['x']]] as $kotu) {
    $pk = giris_post($T + $kotu + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Z']);
    ok('kisi_ekle geçersiz girdi reddedilir (400): ' . mb_substr(json_encode($kotu, JSON_UNESCAPED_UNICODE), 0, 60), ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5 && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
}
$pk = giris_post($T + ['action' => 'kisi_ekle', 'ad' => str_repeat('ş', 120), 'gorev' => str_repeat('ü', 80)]);
ok('sınır değerler: ad 120 + görev 80 karakter KABUL', ($pk['json']['ok'] ?? false) === true);
$pk = giris_post($T + ['action' => 'kisi_ekle']);
ok('ad alanı hiç yok → reddedilir', ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5);
$pk = giris_post($T + ['action' => 'kisi_duzenle', 'kisi_id' => '1', 'ad' => 'Örnek Kişi A2', 'gorev' => '']);
ok('kisi_duzenle: ad değişir, görev silinir (NULL); audit update, eski/yeni YALNIZ uzunluk + ad_degisti, ad metni YOK', ($pk['json']['ok'] ?? false) === true && $pk['json']['islem'] === 'update' && $pk['kisiler'][0]['ad'] === 'Örnek Kişi A2' && $pk['kisiler'][0]['gorev'] === null
    && str_contains($pk['err'], 'AUDIT: ["update","puantaj_giris",1,{"ad_uzunluk":12,"gorev_uzunluk":7},{"ad_uzunluk":13,"gorev_uzunluk":0,"ad_degisti":true}]') && !str_contains(aud($pk['err']), 'Örnek Kişi A'), $pk['err']);
$pk = giris_post($T + ['action' => 'kisi_duzenle', 'kisi_id' => '1', 'ad' => 'Örnek Kişi A', 'gorev' => 'Paketçi']);
ok('kisi_duzenle aynı değerler → islem=degismedi, audit YOK', ($pk['json']['islem'] ?? '') === 'degismedi' && !str_contains($pk['err'], 'AUDIT:'));
foreach ([['kisi_id' => '1', 'ad' => ''], ['kisi_id' => '1', 'ad' => str_repeat('a', 121)], ['kisi_id' => '1', 'ad' => 'x', 'gorev' => str_repeat('g', 81)]] as $kotu) {
    $pk = giris_post($T + $kotu + ['action' => 'kisi_duzenle', 'gorev' => '']);
    ok('kisi_duzenle geçersiz ad/görev reddedilir', ($pk['json']['ok'] ?? null) === false && $pk['kisiler'][0]['ad'] === 'Örnek Kişi A' && !str_contains($pk['err'], 'AUDIT:'));
}
foreach (['abc', '0', '-1', '1.5', '', '999'] as $kid) {
    $pk = giris_post($T + ['action' => 'kisi_duzenle', 'kisi_id' => $kid, 'ad' => 'Örnek Kişi A']);
    ok("kisi_duzenle geçersiz / olmayan kisi_id '$kid' → 400/404 JSON", ($pk['json']['ok'] ?? null) === false && !str_contains($pk['err'], 'AUDIT:'));
}
$pk = giris_post($T + ['action' => 'kisi_duzenle', 'kisi_id' => '3', 'ad' => 'Hacklendi'], ['allowed' => ['Depo A']]);
ok('BAŞKA DEPO kişisi (Depo B) düzenlenemez → 403 JSON, değişmedi', ($pk['json']['code'] ?? 0) === 403 && $pk['kisiler'][2]['ad'] === 'Örnek Kişi C' && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
$pk = giris_post($T + ['action' => 'kisi_duzenle', 'kisi_id' => '2', 'ad' => 'Örnek Kişi B2']);
ok('atanmamış (boş) depolu kişi her depoda düzenlenebilir', ($pk['json']['ok'] ?? false) === true && $pk['kisiler'][1]['ad'] === 'Örnek Kişi B2');
$pk = giris_post($T + ['action' => 'kisi_durum', 'kisi_id' => '1', 'aktif' => '0']);
ok('kisi_durum aktif=0 (kaldır): pasife alınır; GÜN KAYITLARI SİLİNMEZ (4 gün); audit update eski {aktif:1} yeni {aktif:0}', ($pk['json']['ok'] ?? false) === true && $pk['kisiler'][0]['aktif'] === 0 && gun_say_kisi($pk['gunler'], 1) === 4 && str_contains($pk['err'], 'AUDIT: ["update","puantaj_giris",1,{"aktif":1},{"aktif":0}]'), $pk['err']);
$pk = giris_post($T + ['action' => 'kisi_durum', 'kisi_id' => '4', 'aktif' => '1']);
ok('kisi_durum aktif=1 (geri al) pasif kişiyi aktifleştirir; audit {aktif:0}→{aktif:1}', ($pk['json']['ok'] ?? false) === true && $pk['kisiler'][3]['aktif'] === 1 && str_contains($pk['err'], '{"aktif":0},{"aktif":1}]'));
$pk = giris_post($T + ['action' => 'kisi_durum', 'kisi_id' => '1', 'aktif' => '1']);
ok('kisi_durum aynı durum → degismedi, audit YOK', ($pk['json']['islem'] ?? '') === 'degismedi' && !str_contains($pk['err'], 'AUDIT:'));
foreach (['2', 'x', '', 'true', '-1'] as $av) {
    $pk = giris_post($T + ['action' => 'kisi_durum', 'kisi_id' => '1', 'aktif' => $av]);
    ok("kisi_durum geçersiz aktif '$av' reddedilir", ($pk['json']['ok'] ?? null) === false && $pk['kisiler'][0]['aktif'] === 1);
}
$pk = giris_post($T + ['action' => 'kisi_durum', 'kisi_id' => '3', 'aktif' => '0'], ['allowed' => ['Depo A']]);
ok('kisi_durum: başka depo kişisi → 403, değişmedi', ($pk['json']['code'] ?? 0) === 403 && $pk['kisiler'][2]['aktif'] === 1);

echo "--- gun_isaretle ---\n";
$kisiN = count($GB['giris_kisiler']);
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '2', 'tarih' => $g6, 'kod' => 'X']);
ok('gun_isaretle (yeni): islem=create; satır yazıldı (user_id=1); yanıt degisim [{k,t,c}]', ($pk['json']['ok'] ?? false) === true && $pk['json']['islem'] === 'create' && in_array(['kisi_id' => 2, 'tarih' => $g6, 'kod' => 'X', 'user_id' => 1], $pk['gunler'], true) && $pk['json']['degisim'] === [['k' => 2, 't' => $g6, 'c' => 'X']], $pk['out'] . $pk['err']);
ok('audit: create / puantaj_giris / gün satırı id / eski null / yeni {kisi_id,tarih,kod}', str_contains($pk['err'], 'AUDIT: ["create","puantaj_giris",8,null,{"kisi_id":2,"tarih":"' . $g6 . '","kod":"X"}]'), $pk['err']);
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '1', 'tarih' => "$bu-03", 'kod' => 'D']);
ok('gun_isaretle (var olanı değiştir Y→D): islem=update; audit eski Y → yeni D; tekil kayıt korunur (UNIQUE)', ($pk['json']['islem'] ?? '') === 'update' && str_contains($pk['err'], '"kod":"Y"},{"kisi_id":1,"tarih":"' . "$bu-03" . '","kod":"D"}') && gun_say_kisi($pk['gunler'], 1) === 4);
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '1', 'tarih' => $g2, 'kod' => '']);
ok("gun_isaretle kod='' (temizle): islem=delete; satır silindi; audit delete eski {kod X} yeni null; degisim c=''", ($pk['json']['islem'] ?? '') === 'delete' && gun_say_kisi($pk['gunler'], 1) === 3 && str_contains($pk['err'], 'AUDIT: ["delete","puantaj_giris",') && str_contains($pk['err'], ',null]') && $pk['json']['degisim'] === [['k' => 1, 't' => $g2, 'c' => '']], $pk['err']);
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '2', 'tarih' => $g6, 'kod' => '']);
ok('olmayan günü temizle: ok, islem=yok, audit YOK', ($pk['json']['islem'] ?? '') === 'yok' && !str_contains($pk['err'], 'AUDIT:'));
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '1', 'tarih' => "$bu-02", 'kod' => 'X'], ['giris_gunler' => [[1, 2, 'X']]]);
ok('aynı değeri tekrar gönder (X zaten var): islem=degismedi, audit YOK, tek kayıt', ($pk['json']['islem'] ?? '') === 'degismedi' && !str_contains($pk['err'], 'AUDIT:') && gun_say_kisi($pk['gunler'], 1) === 1);
foreach (['Y', 'D', 'İ', 'Ü', 'R', 'T'] as $kd) {
    $pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '2', 'tarih' => $g7, 'kod' => $kd]);
    ok("kod '$kd' kabul (hafta sonu günü dahil)", ($pk['json']['ok'] ?? false) === true && ($pk['json']['kod'] ?? '') === $kd);
}
foreach ([['kod' => 'x'], ['kod' => 'Q'], ['kod' => 'XY'], ['kod' => 'i'], ['kod' => ['X']], ['tarih' => '2026-02-30'], ['tarih' => '2031-01-01'], ['tarih' => '1999-01-01'], ['tarih' => ''], ['tarih' => 'saçma'], ['tarih' => "$bu-05 10:00"], ['tarih' => ['x']], ['kisi_id' => 'abc'], ['kisi_id' => '0'], ['kisi_id' => '-1'], ['kisi_id' => '1.5'], ['kisi_id' => '']] as $kotu) {
    $pk = giris_post($T + $kotu + ['action' => 'gun_isaretle', 'kisi_id' => '2', 'tarih' => $g6, 'kod' => 'X']);
    ok('gun_isaretle geçersiz girdi reddedilir (400): ' . json_encode($kotu, JSON_UNESCAPED_UNICODE), ($pk['json']['ok'] ?? null) === false && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
}
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '2', 'tarih' => $g6]);
ok('kod alanı hiç yok → reddedilir (sessizce temizleme YAPMAZ)', ($pk['json']['ok'] ?? null) === false && count($pk['gunler']) === count($GB['giris_gunler']));
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '999', 'tarih' => $g6, 'kod' => 'X']);
ok('olmayan kişi → 404 JSON', ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), 'bulunamadı'));
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '3', 'tarih' => $g6, 'kod' => 'X'], ['allowed' => ['Depo A']]);
ok('BAŞKA DEPO kişisine işaret → 403 JSON, yazılmadı, audit YOK', ($pk['json']['code'] ?? 0) === 403 && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'));
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '3', 'tarih' => $g6, 'kod' => 'X'], ['allowed' => ['Depo A', 'Depo B'], 'depo' => 'Depo A']);
ok('aktif depo Depo A seçiliyken Depo B kişisi → 403 (aktif depo dar kapı)', ($pk['json']['code'] ?? 0) === 403);
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '3', 'tarih' => $g6, 'kod' => 'X'], ['allowed' => ['Depo B'], 'depo' => 'Depo B']);
ok('Depo B seçiliyken Depo B kişisi işaretlenebilir', ($pk['json']['ok'] ?? false) === true);
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '4', 'tarih' => $g6, 'kod' => 'X']);
ok('PASİF kişiye işaret girilemez → 400 (önce geri alınmalı)', ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), 'Pasif'));
$gel = date('Y-m-d', strtotime('+11 months'));
$pk = giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '2', 'tarih' => $gel, 'kod' => 'T']);
ok('gelecek tarih (11 ay) serbest; 2 yıl + 2 gün reddedilir', ($pk['json']['ok'] ?? false) === true && giris_post($T + ['action' => 'gun_isaretle', 'kisi_id' => '2', 'tarih' => date('Y-m-d', strtotime('+2 years +2 days')), 'kod' => 'T'])['json']['ok'] === false);

echo "--- gun_toplu (hafta içi doldur / ayı temizle) ---\n";
$hi = array_values(array_filter(pc_gunler($bu), fn($g) => !$g['hs']));
$hs = array_values(array_filter(pc_gunler($bu), fn($g) => $g['hs']));
$varHi = 0; foreach ($GB['giris_gunler'] as $r) { if ((int)$r[0] === 1) { $d = sprintf('%s-%02d', $bu, $r[1]); foreach ($hi as $g) if ($g['tarih'] === $d) $varHi++; } }
$pk = giris_post($T + ['action' => 'gun_toplu', 'kisi_id' => '1', 'ay' => $bu, 'mod' => 'haftaici_x']);
$k1 = array_values(array_filter($pk['gunler'], fn($g) => (int)$g['kisi_id'] === 1));
$k1map = array_column($k1, 'kod', 'tarih');
$bekAdet = count($hi) - $varHi;
ok('haftaici_x: yalnız BOŞ hafta içi günler X olur (adet = ' . $bekAdet . '); kişinin mevcut D/İ/Y/X işaretleri KORUNUR', ($pk['json']['ok'] ?? false) === true && ($pk['json']['adet'] ?? -1) === $bekAdet && count($pk['json']['degisim']) === $bekAdet && ($k1map["$bu-03"] ?? '') === 'Y' && ($k1map["$bu-04"] ?? '') === 'D' && ($k1map["$bu-05"] ?? '') === 'İ' && ($k1map["$bu-02"] ?? '') === 'X', $pk['out'] . $pk['err']);
ok('haftaici_x: HAFTA SONU günlerine hiç yazılmaz; tüm hafta içi günlerde işaret var', count(array_filter($hs, fn($g) => isset($k1map[$g['tarih']]) && !in_array($g['n'], [2, 3, 4, 5], true))) === 0 && count(array_filter($hi, fn($g) => isset($k1map[$g['tarih']]))) === count($hi));
ok("haftaici_x audit: TEK özet kayıt {kisi_id, ay, mod, adet} (gün başına audit YOK)", substr_count($pk['err'], 'AUDIT:') === 1 && str_contains($pk['err'], 'AUDIT: ["update","puantaj_giris",1,null,{"kisi_id":1,"ay":"' . $bu . '","mod":"haftaici_x","adet":' . $bekAdet . '}]'), $pk['err']);
$pk = giris_post($T + ['action' => 'gun_toplu', 'kisi_id' => '2', 'ay' => $bu, 'mod' => 'haftaici_x']);
ok('haftaici_x ikinci kişide (kişi 2: 3. gün R): başka kişinin kayıtlarına DOKUNMAZ; kişi 1 sayısı sabit', ($pk['json']['ok'] ?? false) === true && gun_say_kisi($pk['gunler'], 1) === 4 && gun_say_kisi($pk['gunler'], 3) === 1);
$pk = giris_post($T + ['action' => 'gun_toplu', 'kisi_id' => '1', 'ay' => $bu, 'mod' => 'ay_temizle'], ['giris_gunler' => array_merge($GB['giris_gunler'], [[1, date('Y-m-d', strtotime('first day of last month')), 'X'], [1, date('Y-m-d', strtotime('first day of next month')), 'R']])]);
ok('ay_temizle: yalnız O KİŞİNİN O AYININ işaretleri silinir; önceki/sonraki ay ve diğer kişiler KALIR; audit tek özet', ($pk['json']['ok'] ?? false) === true && $pk['json']['adet'] === 4 && gun_say_kisi($pk['gunler'], 1) === 2 && gun_say_kisi($pk['gunler'], 2) === 1 && gun_say_kisi($pk['gunler'], 3) === 1 && count($pk['json']['degisim']) === 4 && $pk['json']['degisim'][0]['c'] === ''
    && substr_count($pk['err'], 'AUDIT:') === 1 && str_contains($pk['err'], '"mod":"ay_temizle","adet":4}'), $pk['out'] . $pk['err']);
foreach ([['ay' => '2026-13'], ['ay' => 'abc'], ['ay' => ''], ['ay' => '2026-1'], ['ay' => "$bu-01"], ['mod' => 'sil'], ['mod' => ''], ['mod' => 'HAFTAICI_X'], ['mod' => ['x']], ['ay' => ['x']], ['kisi_id' => '999']] as $kotu) {
    $pk = giris_post($T + $kotu + ['action' => 'gun_toplu', 'kisi_id' => '1', 'ay' => $bu, 'mod' => 'haftaici_x']);
    ok('gun_toplu geçersiz girdi reddedilir: ' . json_encode($kotu, JSON_UNESCAPED_UNICODE), ($pk['json']['ok'] ?? null) === false && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
}
$pk = giris_post($T + ['action' => 'gun_toplu', 'kisi_id' => '3', 'ay' => $bu, 'mod' => 'haftaici_x'], ['allowed' => ['Depo A']]);
ok('gun_toplu: başka depo kişisi → 403, yazılmadı', ($pk['json']['code'] ?? 0) === 403 && count($pk['gunler']) === count($GB['giris_gunler']));
$pk = giris_post($T + ['action' => 'gun_toplu', 'kisi_id' => '4', 'ay' => $bu, 'mod' => 'haftaici_x']);
ok('gun_toplu: pasif kişi → 400', ($pk['json']['ok'] ?? null) === false && count($pk['gunler']) === count($GB['giris_gunler']));

echo "--- gun_sutun (güne tüm görünen kişiler) ---\n";
$pk = giris_post($T + ['action' => 'gun_sutun', 'tarih' => $g6, 'kod' => 'T']);
$g6map = []; foreach ($pk['gunler'] as $g) if ($g['tarih'] === $g6) $g6map[(int)$g['kisi_id']] = $g['kod'];
ok('gun_sutun: görünen AKTİF kişilerin (1, 2, 5) hepsine T; PASİF (4) ve BAŞKA DEPO (3) kişisine DOKUNULMAZ; adet 3, kisi_sayisi 3', ($pk['json']['ok'] ?? false) === true && $pk['json']['adet'] === 3 && $pk['json']['kisi_sayisi'] === 3 && ($g6map[1] ?? '') === 'T' && ($g6map[2] ?? '') === 'T' && ($g6map[5] ?? '') === 'T' && !isset($g6map[3]) && !isset($g6map[4]) && count($pk['json']['degisim']) === 3, $pk['out'] . json_encode($g6map));
ok('gun_sutun audit: TEK özet {mod, tarih, kod, adet}, kayıt id yok', substr_count($pk['err'], 'AUDIT:') === 1 && str_contains($pk['err'], 'AUDIT: ["update","puantaj_giris",null,null,{"mod":"gun_sutun","tarih":"' . $g6 . '","kod":"T","adet":3}]'), $pk['err']);
$pk = giris_post($T + ['action' => 'gun_sutun', 'tarih' => $g6, 'kod' => 'X', 'q' => 'ismail']);
$g6map = []; foreach ($pk['gunler'] as $g) if ($g['tarih'] === $g6) $g6map[(int)$g['kisi_id']] = $g['kod'];
ok("gun_sutun + q: ekrandaki arama süzgeciyle AYNI küme ('ismail' → yalnız İsmail Örnek = kişi 5); audit'te arama metni YOK", $pk['json']['adet'] === 1 && $pk['json']['kisi_sayisi'] === 1 && ($g6map[5] ?? '') === 'X' && !isset($g6map[1]) && !str_contains(aud($pk['err']), 'ismail'), $pk['out']);
$pk = giris_post($T + ['action' => 'gun_sutun', 'tarih' => "$bu-03", 'kod' => ''], []);
$g3map = []; foreach ($pk['gunler'] as $g) if ($g['tarih'] === "$bu-03") $g3map[(int)$g['kisi_id']] = $g['kod'];
ok("gun_sutun kod='' (temizle): görünenlerin o günü silinir (1: Y, 2: R); 3 (başka depo) ve 4 (pasif) KALIR", $pk['json']['adet'] === 2 && !isset($g3map[1]) && !isset($g3map[2]) && isset($g3map[3]) && isset($g3map[4]), json_encode($g3map));
$pk = giris_post($T + ['action' => 'gun_sutun', 'tarih' => $g6, 'kod' => 'X'], ['giris_gunler' => [[1, 6, 'X'], [2, 6, 'X'], [5, 6, 'X']]]);
ok('gun_sutun: zaten aynı kodlu kişiler "değişen" sayılmaz → adet 0, audit YOK', $pk['json']['adet'] === 0 && !str_contains($pk['err'], 'AUDIT:'));
foreach ([['tarih' => '2026-02-30'], ['tarih' => ''], ['tarih' => '2031-01-01'], ['kod' => 'Q'], ['kod' => 'x'], ['kod' => ['X']]] as $kotu) {
    $pk = giris_post($T + $kotu + ['action' => 'gun_sutun', 'tarih' => $g6, 'kod' => 'X']);
    ok('gun_sutun geçersiz girdi reddedilir: ' . json_encode($kotu, JSON_UNESCAPED_UNICODE), ($pk['json']['ok'] ?? null) === false && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'));
}
$pk = giris_post($T + ['action' => 'gun_sutun', 'tarih' => $g6]);
ok('gun_sutun: kod alanı yoksa reddedilir', ($pk['json']['ok'] ?? null) === false);

echo "--- bağımsızlık: employees / PDKS tablolarına dokunulmadı ---\n";
$base = giris_post($T + ['action' => 'sil']);   // hiçbir şey yazmayan referans çalıştırma
$yaz1 = giris_post($T + ['action' => 'gun_toplu', 'kisi_id' => '1', 'ay' => $bu, 'mod' => 'haftaici_x']);
$yaz2 = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Örnek Kişi Q']);
$yaz3 = giris_post($T + ['action' => 'gun_sutun', 'tarih' => $g6, 'kod' => 'R']);
ok('employees / attendance_events / puantaj_isaretler satır sayıları, yazma işlemlerinden ÖNCE ve SONRA AYNI (6 personel; PDKS olayları; elle işaret tablosu boş)', $base['sayilar'] !== null && $base['sayilar']['employees'] === 6 && $yaz1['sayilar'] === $base['sayilar'] && $yaz2['sayilar'] === $base['sayilar'] && $yaz3['sayilar'] === $base['sayilar'] && $base['sayilar']['attendance_events'] > 0, json_encode([$base['sayilar'], $yaz1['sayilar']]));

echo "--- audit: kişi adı HİÇBİR audit kaydında yok ---\n";
$tumErr = aud($yaz1['err']) . aud($yaz2['err']) . aud($yaz3['err']);
$adli = giris_post($T + ['action' => 'kisi_ekle', 'ad' => 'Çok Gizli Ad Soyad', 'gorev' => 'Çok Gizli Görev']);
$adli2 = giris_post($T + ['action' => 'kisi_duzenle', 'kisi_id' => '1', 'ad' => 'Başka Gizli Ad', 'gorev' => 'Başka Gizli Görev']);
ok('kisi_ekle + kisi_duzenle audit çıktısında ad / görev metni YOK (yalnız id, aktif, depo, uzunluklar)', str_contains($adli['err'], 'AUDIT:') && str_contains($adli2['err'], 'AUDIT:') && !preg_match('/Gizli|Örnek Kişi/u', aud($adli['err']) . aud($adli2['err']) . $tumErr), $adli['err'] . $adli2['err']);


// ───────────────────────── 6. v555 — Puantaj Girişi: ilk sekme · Kişiler görünümü · Excel'den ekle · toplu kişi × gün ─────────────────────────
echo "\n=== 6a. Statik: dosyalar, güvenlik, bağımsızlık, kapılar ===\n";
foreach (['config/puantaj_giris_excel.php', 'config/puantaj_giris_kisiler_ui.php', 'assets/puantaj_giris_kisiler.js'] as $f) ok("$f var", is_file("$ROOT/$f"));
foreach (['config/puantaj_giris_excel.php', 'config/puantaj_giris_kisiler_ui.php'] as $f) { exec('php -l ' . escapeshellarg("$ROOT/$f") . ' 2>&1', $o, $rc); ok("php -l $f", $rc === 0, implode(' ', $o)); $o = []; }
exec('node --check ' . escapeshellarg("$ROOT/assets/puantaj_giris_kisiler.js") . ' 2>&1', $o, $rc); ok('node --check assets/puantaj_giris_kisiler.js', $rc === 0, implode(' ', $o)); $o = [];
$pgx = $kod_yorumsuz('config/puantaj_giris_excel.php'); $pgkui = $kod_yorumsuz('config/puantaj_giris_kisiler_ui.php');
$kisJs = oku('assets/puantaj_giris_kisiler.js'); $pgjs = oku('assets/puantaj_giris.js'); $css = oku('assets/puantaj_cizelgesi.css'); $kaydet = oku('puantaj_giris_kaydet.php');
$sayfa = oku('puantaj_cizelgesi.php'); $pgl = $kod_yorumsuz('config/puantaj_giris.php');
$v555 = $pgx . $pgkui . $kod_yorumsuz('puantaj_giris_kaydet.php') . $pgl . $kod_yorumsuz('config/puantaj_giris_ui.php');
ok('BAĞIMSIZ (v555 dosyaları dahil): employees / attendance_* / puantaj_isaretler / daily_* / worker_* / foreman / hakediş / cavus / pdks_* çağrısına HİÇ değinmez', !preg_match('/\b(employees|attendance_\w+|puantaj_isaretler|daily_\w+|worker_\w+|foreman\w*|hakedis\w*|entitlement\w*|cavus\w*|employee_cards)\b/i', $v555) && !preg_match('/\bpdks_(?!aktif)\w+\s*\(/i', $v555));
preg_match_all('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|FROM|JOIN)\s+`?([a-z_]+)`?/i', $pgx . $pgl . $kod_yorumsuz('puantaj_giris_kaydet.php'), $tm2);
$tablolar2 = array_values(array_unique(array_map('strtolower', $tm2[1]))); sort($tablolar2);
ok('BAĞIMSIZ: SQL (Excel modülü + toplu işlemler dahil) yalnız puantaj_giris_kisiler + puantaj_giris_gunler', $tablolar2 === ['puantaj_giris_gunler', 'puantaj_giris_kisiler'], json_encode($tablolar2));
ok('Excel güvenliği: yüklenen dosya DİSKE KAYDEDİLMEZ (move_uploaded_file / file_put_contents / copy / rename / tempnam / fopen yazma YOK); yalnız php://memory', !preg_match('/\b(move_uploaded_file|file_put_contents|tempnam|tmpfile|copy|rename|touch|unlink)\s*\(/i', $pgx . $kod_yorumsuz('puantaj_giris_kaydet.php')) && !preg_match('/fopen\s*\(\s*[^)]*[\'"][wax]\+?b?[\'"]/i', $pgx) && str_contains($pgx, "fopen('php://memory'"));
ok('Excel güvenliği: ReadDataOnly + yalnız ilk sayfa + okuma süzgeci; getCalculatedValue / calculate() YOK; formül hücresi yalnız önbellek', str_contains($pgx, 'setReadDataOnly(true)') && str_contains($pgx, 'setLoadSheetsOnly(') && str_contains($pgx, 'IReadFilter') && !preg_match('/getCalculatedValue|->calculate\(|setCalculate/i', $pgx) && str_contains($pgx, 'getOldCalculatedValue'));
ok('Excel güvenliği: boyut ≤ 2 MB · ≤ 500 satır · .xlsx/.csv · PK + zip denetimi (makro/dış bağlantı/gömülü/zip bombası) · is_uploaded_file varsayılanı', str_contains($pgx, 'const PGE_MAX_BAYT = 2097152') && str_contains($pgx, 'const PGE_MAX_SATIR = 500') && str_contains($pgx, "'xl/vbaproject.bin'") && str_contains($pgx, 'externallinks/') && str_contains($pgx, 'embeddings/') && str_contains($pgx, 'zip bombası') && str_contains($pgx, "'is_uploaded_file'"));
ok('yazma ucu: dosya yükleme kancası YALNIZ test globali ($GLOBALS[_pg_yukleme_denetimi]); CSRF dosya eyleminden ÖNCE (kapı sırası değişmedi)', str_contains($kaydet, "_pg_yukleme_denetimi") && strpos($kaydet, 'csrf_check(') < strpos($kaydet, "case 'excel_onizle'") && strpos($kaydet, 'role_pdks_can(\'monthly_timesheet\', \'edit\')') < strpos($kaydet, "case 'kisi_sil'"));
ok('yazma ucu: yeni eylemler tek-transaction yardımcılarını çağırır (pg_kisi_sil / pg_kisi_toplu / pg_gun_toplu_sec / pge_kisileri_ekle), doğrudan SQL yok, audit_log_event puantaj_giris', str_contains($kaydet, 'pg_kisi_sil($pdo') && str_contains($kaydet, 'pg_kisi_toplu($pdo') && str_contains($kaydet, 'pg_gun_toplu_sec($pdo') && str_contains($kaydet, 'pge_kisileri_ekle($pdo') && substr_count($kaydet, "'puantaj_giris'") >= 6 && !preg_match('/\b(INSERT|UPDATE|DELETE|SELECT)\s/', $kaydet));
preg_match_all('/audit_log_event\([^;]*;/', $kod_yorumsuz('puantaj_giris_kaydet.php'), $auSeg);
ok("yazma ucu: audit_log_event çağrılarının HİÇBİRİ ad / görev / kişi satırı taşımaz (yalnız id, sayı, mod, kod, tarih)", count($auSeg[0]) >= 6 && !array_filter($auSeg[0], fn($x) => preg_match("/'(ad|gorev)'/", $x)), json_encode($auSeg[0], JSON_UNESCAPED_UNICODE));
ok("yazma ucu: toplu eylemlerde depo kuralı HER kişide (depot_visible_to_user döngüsü), tümü-ya-hiçbiri; kisi_ids ≤ PG_TOPLU_ID_MAX, tarihler ≤ PG_SEC_GUN_MAX", str_contains($kaydet, 'foreach ($kis as $k)') && str_contains($kaydet, 'depot_visible_to_user($k[\'depo\'])') && str_contains($kaydet, 'PG_TOPLU_ID_MAX') && str_contains($kaydet, 'PG_SEC_GUN_MAX'));
ok('sınırlar: kişi 500 × gün 31 = 15500 (PG_SEC_HUCRE_MAX); Excel 500 satır', PG_TOPLU_ID_MAX === 500 && PG_SEC_GUN_MAX === 31 && PG_SEC_HUCRE_MAX === 15500 && PG_TOPLU_ID_MAX * PG_SEC_GUN_MAX === PG_SEC_HUCRE_MAX && PGE_MAX_SATIR === 500 && PGE_MAX_BAYT === 2 * 1024 * 1024);
ok('JS (kişiler): innerHTML / insertAdjacentHTML / document.write / confirm( / alert( / <form> YOK; FormData + fetch; kisi_sil / kisi_toplu / excel_onizle / excel_ekle; window.pgApi', !preg_match('/\.innerHTML|insertAdjacentHTML|document\.write|\bconfirm\s*\(|\balert\s*\(|createElement\(.form.\)/', $kisJs . $pgjs) && str_contains($kisJs, 'new FormData()') && str_contains($kisJs, "'excel_onizle'") && str_contains($kisJs, "'excel_ekle'") && str_contains($kisJs, "'kisi_toplu'") && str_contains($kisJs, "'kisi_sil'") && str_contains($kisJs, 'window.pgApi') && str_contains($kisJs, 'textContent'));
ok('UI (kişiler + puantaj): <form> ELEMENTİ yok (modal-form tuzağı); tarayıcı confirm/alert yok; sayfa kaynağında innerHTML yok', !preg_match('/<form\b/i', $pgkui . $kod_yorumsuz('config/puantaj_giris_ui.php')) && !preg_match('/\bconfirm\(|\balert\(/', $pgkui));
ok('CSS: yeni pencereler z-index ≥ 600 (.pk-excel 601; mobil işlem çubuğu 610; perde 600); mobil alt sayfa; 16px input; koyu tema --pc-sec; var(--surface YOK', (bool)preg_match('/\.pk-excel \{[^}]*z-index: 601/', $css) && (bool)preg_match('/\.pg-cubuk \{ position: fixed; left: 0; right: 0; bottom: 0; z-index: 610/', $css) && str_contains($css, '.pk-dosya { font-size: 16px; }') && str_contains($css, 'html[data-theme="dark"] .pc-page { --pc-sec-bg') && !preg_match('/var\(--surface/', preg_replace('#/\*.*?\*/#s', '', $css)));
ok('CSS: AD SÜTUNU sertleştirildi — .pc-grid .pc-isim AÇIK width/min-width (içerik ölçüsüne bağlı değil), sticky left:0 korunur, z-index < 100', (bool)preg_match('/\.pc-grid \.pc-isim \{[^}]*width: 210px; min-width: 210px/', $css) && (bool)preg_match('/\.pc-grid \.pc-isim \{[^}]*position: sticky; left: 0; z-index: 2/', $css));
ok('sayfa: sablon indirme yalnız edit yetkisiyle (forbidden) + xlsx_gonder(pge_sablon_uret()); gorunum=kisiler yalnız giris sekmesi; dışa aktarım her zaman çizelge', str_contains($sayfa, "role_pdks_can('monthly_timesheet', 'edit')) forbidden(") && str_contains($sayfa, 'xlsx_gonder(pge_sablon_uret()') && str_contains($sayfa, "\$gorunum = (\$sekme === 'giris' &&") && str_contains($sayfa, '!$csvIst && !$xlsxIst'));
ok('CLAUDE.md: v555 satırı (ilk/varsayılan sekme, Kişiler görünümü, Excel\'den toplu ekle, gun_toplu_sec, ad sütunu)', str_contains(oku('CLAUDE.md'), 'v555') && str_contains(oku('CLAUDE.md'), 'gun_toplu_sec') && str_contains(oku('CLAUDE.md'), 'Kişiler'));
ok('sürüm: APP_SURUM = sw.js CACHE_NAME = CLAUDE.md SW Cache (v555)', preg_match("/define\('APP_SURUM', 'v(\d+)'\)/", oku('config/helpers.php'), $m1) && preg_match("/CACHE_NAME = 'yukleme-plani-v(\d+)'/", oku('sw.js'), $m2) && preg_match('/\*\*SW Cache:\*\* `yukleme-plani-v(\d+)`/', oku('CLAUDE.md'), $m3) && $m1[1] === $m2[1] && $m2[1] === $m3[1] && (int)$m1[1] >= 555, json_encode([$m1[1] ?? null, $m2[1] ?? null, $m3[1] ?? null]));

echo "\n=== 6b. Saf: Excel ayrıştırıcı (başlık · sınıflama · CSV · TR) ===\n";
$bb = pge_baslik_bul([['Ad Soyad', 'Görev'], ['x', 'y']]);
ok("başlık: 'Ad Soyad / Görev' → başlıklı, ad=0 görev=1, veri 1. dizinden", $bb === ['baslik' => true, 'ad' => 0, 'gorev' => 1, 'bas' => 1]);
$bb = pge_baslik_bul([['Rapor başlığı'], ['İsim', '', 'Ünvan'], ['x']]);
ok("başlık: 3. sütunda 'Ünvan', 2. satırda 'İsim' (üstte başlık satırı) → ad=0 görev=2 bas=2; TR duyarsız (İ/Ü)", $bb === ['baslik' => true, 'ad' => 0, 'gorev' => 2, 'bas' => 2]);
$bb = pge_baslik_bul([['GÖREV', 'AD-SOYAD'], ['x', 'y']]);
ok("başlık: sıra bağımsız ('GÖREV' önce, 'AD-SOYAD' sonra), noktalama/BÜYÜK harf → ad=1 görev=0", $bb['baslik'] === true && $bb['ad'] === 1 && $bb['gorev'] === 0);
$bb = pge_baslik_bul([['Örnek Kişi A', 'Paketçi'], ['Örnek Kişi B', '']]);
ok('başlık YOK → ilk sütun ad, ikinci sütun görev, ilk satırdan itibaren veri', $bb === ['baslik' => false, 'ad' => 0, 'gorev' => 1, 'bas' => 0]);
$bb = pge_baslik_bul([['Ad'], ['Örnek Kişi A']]);
ok("başlık: yalnız 'Ad' sütunu → görev yok (gorev=-1)", $bb['baslik'] === true && $bb['gorev'] === -1);
$mevcut = [pc_fold('Örnek Kişi A'), pc_fold('Örnek Kişi D')];
$ham = [['Ad Soyad', 'Görev'], ['Örnek Kişi Ş', 'Paketçi'], ['ÖRNEK KİŞİ Ş', ''], ['örnek kişi a', 'x'], ['', 'görevi var adı yok'], [str_repeat('ş', 121), ''], ['Örnek Kişi Ç', str_repeat('g', 81)], ['=HYPERLINK("http://x","t")', '+görev'], ['Örnek Kişi Ö', '  '], ['  Örnek   Kişi   Ü  ', "Gör\x07ev"]];
$c = pge_satirlari_coz($ham, $mevcut);
$dur = array_column($c['satirlar'], 'durum');
ok('sınıflama: geçerli · dosyada tekrar (TR duyarsız) · mevcutla mükerrer · boş ad · çok uzun ad · çok uzun görev · formül metni GEÇERLİ · geçerli · sadeleşmiş geçerli',
    $dur === ['gecerli', 'mukerrer_dosya', 'mukerrer_mevcut', 'bos', 'uzun', 'uzun', 'gecerli', 'gecerli', 'gecerli'], json_encode($dur));
ok('özet: toplam 9 · geçerli 4 · boş 1 · uzun 2 · mükerrer 2 (mevcut 1 + dosya 1)', $c['ozet'] === ['toplam' => 9, 'gecerli' => 4, 'bos' => 1, 'uzun' => 2, 'mukerrer' => 2, 'mukerrer_mevcut' => 1, 'mukerrer_dosya' => 1], json_encode($c['ozet']));
ok("formül gibi başlayan hücre DÜZ METİN kalır (başına ' EKLENMEZ — DB'ye olduğu gibi; ekranda h(), dışa aktarımda metin hücresi/CSV öneki)", $c['satirlar'][6]['ad'] === '=HYPERLINK("http://x","t")' && $c['satirlar'][6]['gorev'] === '+görev');
ok('metin temizliği: denetim karakteri (BEL) atılır, ardışık boşluk tek, kırpılır; satır sayısı = başlık hariç', $c['satirlar'][8]['ad'] === 'Örnek Kişi Ü' && $c['satirlar'][8]['gorev'] === 'Gör ev' && $c['baslik'] === true);
$on = pge_onizleme_yaniti($c);
ok('önizleme yanıtı: özet + ilk 20 satır (burada 9) + baslik_bulundu; uzun metin 150 karaktere kırpılır', $on['gosterilen'] === 9 && count($on['ilk']) === 9 && $on['baslik_bulundu'] === true && mb_strlen($on['ilk'][4]['ad'], 'UTF-8') === 121);
$ham500 = [['Ad Soyad']]; for ($i = 1; $i <= 500; $i++) $ham500[] = ['Örnek Kişi N' . $i];
$c500 = pge_satirlari_coz($ham500, []);
ok('500 satır KABUL (500 geçerli); önizleme yalnız ilk 20 satırı taşır', ($c500['ozet']['gecerli'] ?? 0) === 500 && count(pge_onizleme_yaniti($c500)['ilk']) === 20 && pge_onizleme_yaniti($c500)['gosterilen'] === 20);
$ham501 = $ham500; $ham501[] = ['Örnek Kişi N501'];
ok('501 satır REDDEDİLİR (hata: en çok 500 satır)', isset(pge_satirlari_coz($ham501, [])['hata']) && str_contains(pge_satirlari_coz($ham501, [])['hata'], '500'));
ok('Türkçe karakterler korunur: Ş Ç Ğ İ Ö Ü ı; mükerrer katlaması TR duyarsız (ı/İ/i aynı)', (function () {
    $r = pge_satirlari_coz([['Örnek Kişi Ş'], ['Örnek Kişi Ç'], ['Örnek Kişi Ğ'], ['Örnek Kişi İ'], ['Örnek Kişi ı'], ['Örnek Kişi Ö'], ['Örnek Kişi Ü']], []);
    return array_column($r['satirlar'], 'ad') === ['Örnek Kişi Ş', 'Örnek Kişi Ç', 'Örnek Kişi Ğ', 'Örnek Kişi İ', 'Örnek Kişi ı', 'Örnek Kişi Ö', 'Örnek Kişi Ü'] && $r['ozet']['mukerrer_dosya'] === 1 && $r['ozet']['gecerli'] === 6;
})());
$cb = pge_satirlari_coz([['Ad', 'Görev'], ['', ''], ['x', 'g'], ['', 'g']], []);
ok('tamamen boş satır SAYILMAZ; ad boş + görev dolu satır "bos" sayılır (toplam 2: 1 geçerli + 1 boş)', $cb['ozet']['toplam'] === 2 && $cb['ozet']['gecerli'] === 1 && $cb['ozet']['bos'] === 1);
$csv1 = "\xEF\xBB\xBFAd Soyad;Görev\r\nÖrnek Kişi Ş;Paketçi\r\n\"Örnek; Kişi\";\"iki\nsatır\"\r\n\r\n";
$r1 = pge_csv_coz($csv1);
ok("CSV: UTF-8 + BOM, ayraç ';', tırnaklı alan (içinde ';' ve satır sonu), boş satır atlanır", $r1 === [['Ad Soyad', 'Görev'], ['Örnek Kişi Ş', 'Paketçi'], ['Örnek; Kişi', 'iki satır']], json_encode($r1, JSON_UNESCAPED_UNICODE));
$r2 = pge_csv_coz("Ad,Görev\nÖrnek Kişi Ç,Depocu\n");
ok("CSV: BOM'suz UTF-8, ayraç ','", $r2 === [['Ad', 'Görev'], ['Örnek Kişi Ç', 'Depocu']]);
$r3 = pge_csv_coz("Ad\tGörev\nÖrnek Kişi Ğ\tDepocu\n");
ok('CSV: sekme ayraçlı', $r3 === [['Ad', 'Görev'], ['Örnek Kişi Ğ', 'Depocu']]);
$tr1254 = mb_convert_encoding("Ad Soyad;Görev\nÖrnek Kişi İ;Şoför\n", 'Windows-1254', 'UTF-8');
ok('CSV: Windows-1254 (Türkçe Excel varsayılanı) → UTF-8, TR karakterler doğru', pge_csv_coz($tr1254) === [['Ad Soyad', 'Görev'], ['Örnek Kişi İ', 'Şoför']], json_encode(pge_csv_coz($tr1254), JSON_UNESCAPED_UNICODE));
ok("CSV: hücreler pge_hucre_metin'den geçer (denetim karakteri atılır); 10 sütundan fazlası kesilir", pge_csv_coz("a\x07b;c;" . implode(';', range(1, 15)) . "\n")[0][0] === 'a b' && count(pge_csv_coz("a;b;" . implode(';', range(1, 15)) . "\n")[0]) === 10);
ok('pge_hucre_metin: bool/dizi/null → boş; float 3.0 → "3"; 2.5 → "2.5"; başlangıç = + - @ olduğu gibi', pge_hucre_metin(true) === '' && pge_hucre_metin([1]) === '' && pge_hucre_metin(null) === '' && pge_hucre_metin(3.0) === '3' && pge_hucre_metin(2.5) === '2.5' && pge_hucre_metin('=A1') === '=A1' && pge_hucre_metin('@x') === '@x');

echo "\n=== 6c. DB: kişi yönetimi + toplu işlemler (bellek içi SQLite; transaction / rollback) ===\n";
function pg_test_db(array $kisiler = [], array $gunler = []): PDO
{
    $d = new PDO('sqlite::memory:'); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $d->exec('CREATE TABLE puantaj_giris_kisiler (id INTEGER PRIMARY KEY AUTOINCREMENT, ad VARCHAR(120) NOT NULL, gorev VARCHAR(80) NULL, depo VARCHAR(120) NOT NULL DEFAULT "", aktif INTEGER NOT NULL DEFAULT 1, sira INTEGER NOT NULL DEFAULT 0, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)');
    $d->exec('CREATE TABLE puantaj_giris_gunler (id INTEGER PRIMARY KEY AUTOINCREMENT, kisi_id INTEGER NOT NULL, tarih TEXT NOT NULL, kod VARCHAR(4) NOT NULL, user_id INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)');
    $d->exec('CREATE UNIQUE INDEX uq_pg_gun ON puantaj_giris_gunler (kisi_id, tarih)');
    foreach ($kisiler as $k) $d->prepare('INSERT INTO puantaj_giris_kisiler (id, ad, gorev, depo, aktif, sira) VALUES (?,?,?,?,?,?)')->execute([$k[0], $k[1], $k[2], $k[3] ?? '', $k[4] ?? 1, $k[5] ?? 0]);
    foreach ($gunler as $g) $d->prepare('INSERT INTO puantaj_giris_gunler (kisi_id, tarih, kod) VALUES (?,?,?)')->execute($g);
    return $d;
}
$dk = [[1, 'Örnek Kişi B', 'Paketçi', 'Depo A', 1, 5], [2, 'Örnek Kişi A', '', 'Depo A', 1, 0], [3, 'İsmail Örnek', 'Şoför', '', 0, 0], [4, 'Çok Pasif', '', 'Depo A', 0, 0]];
$dg = [[1, '2026-11-02', 'X'], [1, '2026-11-03', 'Y'], [1, '2026-10-30', 'R'], [2, '2026-11-02', 'X'], [3, '2026-11-02', 'D']];
$tdb = pg_test_db($dk, $dg);
$yon = pg_kisiler_yonetim($tdb);
ok('pg_kisiler_yonetim: pasifler DAHİL, ad A-Z (sira yok sayılır: B kişisinin sira=5 sıralamayı etkilemez), TR duyarsız; gün kaydı sayısı (tüm aylar)', array_column($yon, 'ad') === ['Çok Pasif', 'İsmail Örnek', 'Örnek Kişi A', 'Örnek Kişi B'] && array_column($yon, 'gun', 'id') === [4 => 0, 3 => 1, 2 => 1, 1 => 3], json_encode(array_column($yon, 'gun', 'id')));
ok('pg_kisi_sayaclari: toplam 4 · aktif 2 · pasif 2', pg_kisi_sayaclari($yon) === ['toplam' => 4, 'aktif' => 2, 'pasif' => 2]);
ok("pg_kisiler_filtrele: durum aktif/pasif/hepsi; arama TR duyarsız ('ISMAIL', 'paket' görevde); sıra korunur", array_column(pg_kisiler_filtrele($yon, '', 'aktif'), 'id') === [2, 1] && array_column(pg_kisiler_filtrele($yon, '', 'pasif'), 'id') === [4, 3] && array_column(pg_kisiler_filtrele($yon, 'ISMAIL', 'hepsi'), 'id') === [3] && array_column(pg_kisiler_filtrele($yon, 'paket', 'hepsi'), 'id') === [1] && pg_kisiler_filtrele($yon, 'zzz', 'hepsi') === [] && count(pg_kisiler_filtrele($yon, '', 'hepsi')) === 4);
ok('pg_kisiler_idlerle: var olanları döndürür, olmayan id sonuçta YOK (çağıran sayıyla karşılaştırır); 450 id parçalanır', array_keys(pg_kisiler_idlerle($tdb, [1, 3, 999])) === [1, 3] && pg_kisiler_idlerle($tdb, []) === [] && count(pg_kisiler_idlerle($tdb, range(1, 450))) === 4);

$sil = pg_kisi_sil($tdb, pg_kisi_bul($tdb, 1));
$kalan = $tdb->query('SELECT kisi_id, tarih FROM puantaj_giris_gunler ORDER BY id')->fetchAll();
ok("pg_kisi_sil: kişinin TÜM gün kayıtları (3, farklı aylar dahil) + kişi silinir; diğer kişilerin kayıtları KALIR; audit {aktif, depo, gun_kaydi}, ad YOK", $sil['ok'] === true && $sil['gun_silinen'] === 3 && pg_kisi_bul($tdb, 1) === null && count($kalan) === 2 && array_map('intval', array_column($kalan, 'kisi_id')) === [2, 3] && $sil['audit'][0] === 'delete' && $sil['audit'][3] === null && $sil['audit'][2] === ['aktif' => 1, 'depo' => 'Depo A', 'gun_kaydi' => 3] && !str_contains(json_encode($sil['audit'], JSON_UNESCAPED_UNICODE), 'Örnek'));
$tdb = pg_test_db($dk, $dg);
$tdb->exec("CREATE TRIGGER pg_sil_engel BEFORE DELETE ON puantaj_giris_kisiler WHEN OLD.id = 1 BEGIN SELECT RAISE(ABORT, 'engel'); END");
$s2 = pg_kisi_sil($tdb, pg_kisi_bul($tdb, 1));
ok('pg_kisi_sil TEK transaction: kişi silinemezse gün kayıtları da GERİ ALINIR (yarım silme yok)', $s2['ok'] === false && (int)$tdb->query('SELECT COUNT(*) FROM puantaj_giris_gunler WHERE kisi_id = 1')->fetchColumn() === 3 && pg_kisi_bul($tdb, 1) !== null);

$tdb = pg_test_db($dk, $dg);
$ids = pg_kisiler_idlerle($tdb, [1, 2, 3, 4]);
$t1 = pg_kisi_toplu($tdb, $ids, 'pasif');
ok("pg_kisi_toplu pasif: aktif olanlar (1, 2) pasife alınır → etkilenen 2, zaten pasif olanlar (3, 4) atlanan 2; gün kayıtları DOKUNULMAZ (5)", $t1['ok'] && $t1['etkilenen'] === 2 && $t1['atlanan'] === 2 && (int)$tdb->query('SELECT COUNT(*) FROM puantaj_giris_kisiler WHERE aktif = 0')->fetchColumn() === 4 && (int)$tdb->query('SELECT COUNT(*) FROM puantaj_giris_gunler')->fetchColumn() === 5);
$t2 = pg_kisi_toplu($tdb, pg_kisiler_idlerle($tdb, [1, 2, 3, 4]), 'aktif');
ok('pg_kisi_toplu aktif: hepsi aktif (etkilenen 4)', $t2['etkilenen'] === 4 && $t2['atlanan'] === 0 && (int)$tdb->query('SELECT COUNT(*) FROM puantaj_giris_kisiler WHERE aktif = 1')->fetchColumn() === 4);
$t3 = pg_kisi_toplu($tdb, pg_kisiler_idlerle($tdb, [1, 3]), 'sil');
ok('pg_kisi_toplu sil: seçilen 2 kişi + TÜM gün kayıtları (1: 3, 3: 1 = 4) silinir; diğerleri kalır', $t3['ok'] && $t3['etkilenen'] === 2 && $t3['gun_silinen'] === 4 && array_column($tdb->query('SELECT id FROM puantaj_giris_kisiler ORDER BY id')->fetchAll(), 'id') === [2, 4] && (int)$tdb->query('SELECT COUNT(*) FROM puantaj_giris_gunler')->fetchColumn() === 1);
ok('pg_kisi_toplu geçersiz işlem reddedilir', pg_kisi_toplu($tdb, [], 'yok')['ok'] === false);
$tdb = pg_test_db($dk, $dg);
$tdb->exec("CREATE TRIGGER pg_toplu_engel BEFORE DELETE ON puantaj_giris_kisiler WHEN OLD.id = 3 BEGIN SELECT RAISE(ABORT, 'engel'); END");
$t4 = pg_kisi_toplu($tdb, pg_kisiler_idlerle($tdb, [1, 2, 3]), 'sil');
ok('pg_kisi_toplu sil TEK transaction: 3. kişide hata → 1 ve 2 de GERİ ALINIR (hepsi ya da hiçbiri), gün kayıtları da', $t4['ok'] === false && (int)$tdb->query('SELECT COUNT(*) FROM puantaj_giris_kisiler')->fetchColumn() === 4 && (int)$tdb->query('SELECT COUNT(*) FROM puantaj_giris_gunler')->fetchColumn() === 5);

$tdb = pg_test_db([[1, 'Örnek Kişi A', '', 'Depo A', 1], [2, 'Örnek Kişi B', '', 'Depo A', 1], [3, 'Örnek Kişi C', '', 'Depo A', 1], [4, 'Pasif', '', 'Depo A', 0]],
    [[1, '2026-11-03', 'Y'], [2, '2026-11-03', 'R'], [4, '2026-11-03', 'X']]);
$kisAll = pg_kisiler_idlerle($tdb, [1, 2, 3]);
$d3 = ['2026-11-02', '2026-11-03', '2026-11-04'];
$ymap = fn() => array_map(fn($r) => $r['kisi_id'] . ':' . $r['tarih'] . ':' . $r['kod'], $tdb->query('SELECT kisi_id, tarih, kod FROM puantaj_giris_gunler ORDER BY kisi_id, tarih')->fetchAll());
$r = pg_gun_toplu_sec($tdb, '2026-11', $kisAll, $d3, 'X', false, 7, '2026-11-22');
ok("gun_toplu_sec üstüne yaz KAPALI: yalnız BOŞLAR dolar (kişi×gün 9 − 2 dolu = 7 yazılan), dolu 2 hücre (Y, R) ATLANIR (atlanan 2); dolu değerler korunur", $r['ok'] && $r['kisi_sayisi'] === 3 && $r['gun_sayisi'] === 3 && $r['yazilan'] === 7 && $r['atlanan'] === 2 && $r['degismedi'] === 0 && in_array('1:2026-11-03:Y', $ymap(), true) && in_array('2:2026-11-03:R', $ymap(), true) && count($r['degisim']) === 7 && count($ymap()) === 10, json_encode($r));
$r = pg_gun_toplu_sec($tdb, '2026-11', $kisAll, $d3, 'X', true, 7, '2026-11-22');
ok('gun_toplu_sec üstüne yaz AÇIK: dolu 2 hücre X olur (yazılan 2), zaten X olanlar "degismedi" 7; atlanan 0', $r['ok'] && $r['yazilan'] === 2 && $r['atlanan'] === 0 && $r['degismedi'] === 7 && in_array('1:2026-11-03:X', $ymap(), true) && in_array('2:2026-11-03:X', $ymap(), true));
$r = pg_gun_toplu_sec($tdb, '2026-11', pg_kisiler_idlerle($tdb, [1, 2]), ['2026-11-03', '2026-11-04'], '', false, 7, '2026-11-22');
ok("gun_toplu_sec Temizle: üstüne yaz KAPALI olsa da seçili HÜCRELER silinir (4 hücre: 1,2 × 3,4 Kasım); seçili olmayan kişi (3) ve gün (2 Kasım) KALIR; pasif kişinin kaydı kalır", $r['ok'] && $r['yazilan'] === 4 && $r['degisim'][0]['c'] === '' && in_array('3:2026-11-03:X', $ymap(), true) && in_array('1:2026-11-02:X', $ymap(), true) && in_array('4:2026-11-03:X', $ymap(), true) && !in_array('1:2026-11-03:X', $ymap(), true), json_encode($ymap()));
$r = pg_gun_toplu_sec($tdb, '2026-11', pg_kisiler_idlerle($tdb, [1, 4]), ['2026-11-10'], 'D', false, 7, '2026-11-22');
ok('gun_toplu_sec: PASİF kişi atlanır (pasif_atlanan 1), aktif olana yazılır; pasif kişiye YAZILMAZ', $r['ok'] && $r['kisi_sayisi'] === 1 && $r['pasif_atlanan'] === 1 && $r['yazilan'] === 1 && !in_array('4:2026-11-10:D', $ymap(), true) && in_array('1:2026-11-10:D', $ymap(), true));
ok('gun_toplu_sec: hepsi pasif → reddedilir', pg_gun_toplu_sec($tdb, '2026-11', pg_kisiler_idlerle($tdb, [4]), ['2026-11-10'], 'D', false, 7, '2026-11-22')['ok'] === false);
$say0 = count($ymap());
foreach ([['ay' => '2026-13'], ['ay' => '2026-11-01'], ['tar' => ['2026-12-01']], ['tar' => ['2026-02-30']], ['tar' => []], ['tar' => ['2026-11-1']], ['tar' => [5]], ['tar' => ['2031-11-03']], ['kod' => 'x'], ['kod' => 'Q'], ['kod' => 'XX']] as $kotu) {
    $r = pg_gun_toplu_sec($tdb, $kotu['ay'] ?? '2026-11', $kisAll, $kotu['tar'] ?? ['2026-11-12'], $kotu['kod'] ?? 'X', false, 7, '2026-11-22');
    ok('gun_toplu_sec geçersiz girdi reddedilir, hiçbir şey yazılmaz: ' . json_encode($kotu, JSON_UNESCAPED_UNICODE), $r['ok'] === false && count($ymap()) === $say0, json_encode($r));
}
$r = pg_gun_toplu_sec($tdb, '2026-11', $kisAll, array_map(fn($n) => sprintf('2026-11-%02d', $n), range(1, 30)), 'X', false, 7, '2026-11-22');
ok('gun_toplu_sec: 30 günlük ayın TÜM günleri (kısa ay) kabul', $r['ok'] && $r['gun_sayisi'] === 30);
$kisler501 = []; for ($i = 1; $i <= 501; $i++) $kisler501[$i] = ['id' => $i, 'ad' => 'x', 'gorev' => '', 'depo' => '', 'aktif' => 1, 'sira' => 0];
ok('gun_toplu_sec: 501 kişi (> 500) ve 32 gün (> 31) reddedilir', pg_gun_toplu_sec($tdb, '2026-11', $kisler501, ['2026-11-02'], 'X', false, 7, '2026-11-22')['ok'] === false && pg_gun_toplu_sec($tdb, '2026-11', $kisAll, array_merge(array_map(fn($n) => sprintf('2026-11-%02d', $n), range(1, 30)), ['2026-11-31']), 'X', false, 7, '2026-11-22')['ok'] === false);
$tdb2 = pg_test_db([[1, 'A', '', 'Depo A', 1], [2, 'B', '', 'Depo A', 1], [3, 'C', '', 'Depo A', 1]]);
$tdb2->exec("CREATE TRIGGER pg_gun_engel BEFORE INSERT ON puantaj_giris_gunler WHEN NEW.kisi_id = 3 BEGIN SELECT RAISE(ABORT, 'engel'); END");
$r = pg_gun_toplu_sec($tdb2, '2026-11', pg_kisiler_idlerle($tdb2, [1, 2, 3]), ['2026-11-02', '2026-11-03'], 'X', false, 7, '2026-11-22');
ok('gun_toplu_sec TEK transaction: 3. kişide hata → 1. ve 2. kişinin yazdıkları da GERİ ALINIR', $r['ok'] === false && (int)$tdb2->query('SELECT COUNT(*) FROM puantaj_giris_gunler')->fetchColumn() === 0);
$kisBuyuk = []; $dBuyuk = pg_test_db(); $ins = $dBuyuk->prepare('INSERT INTO puantaj_giris_kisiler (id, ad, gorev, depo, aktif) VALUES (?,?,?,?,1)');
for ($i = 1; $i <= 500; $i++) { $ins->execute([$i, 'Örnek Kişi ' . $i, '', 'Depo A']); }
$t0 = microtime(true);
$rb = pg_gun_toplu_sec($dBuyuk, '2026-12', pg_kisiler_idlerle($dBuyuk, range(1, 500)), array_map(fn($n) => sprintf('2026-12-%02d', $n), range(1, 31)), 'X', false, 7, '2026-11-22');
ok('gun_toplu_sec ÜST SINIR: 500 kişi × 31 gün = 15500 hücre tek transaction\'da yazılır (' . round(microtime(true) - $t0, 2) . ' sn)', $rb['ok'] && $rb['yazilan'] === 15500 && (int)$dBuyuk->query('SELECT COUNT(*) FROM puantaj_giris_gunler')->fetchColumn() === 15500 && count($rb['degisim']) === 15500);

echo "\n=== 6d. Sayfa: sekme sırası · varsayılan açılış · Kişiler görünümü · seçim arayüzü ===\n";
$GB2 = $GB;   // 1 A (Depo A) · 2 B (atanmamış) · 3 C (Depo B → görünmez) · 4 D (pasif) · 5 İsmail
$p = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], $GB2);
ok('yönetici + tablo hazır + sekme parametresi YOK → Puantaj Girişi AÇILIR; sekme sırası Puantaj Girişi · Personel · Günlük İşçi', str_contains($p['out'], 'data-tur="giris"') && (bool)preg_match('/<a[^>]*is-aktif[^>]*>Puantaj Girişi<\/a>\s*<a[^>]*>Personel<\/a>\s*<a[^>]*>Günlük İşçi<\/a>/', $p['out']));
$p0 = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB2, ['giris' => false]));
ok('yönetici + TABLO YOK + sekme parametresi YOK → yine Puantaj Girişi (kurulum notuyla); Personel/Günlük sekmesi hâlâ açılabilir', $p0['rc'] === 0 && str_contains($p0['out'], 'data-tur="giris"') && str_contains($p0['out'], 'migrate.php#puantaj-giris') && !str_contains($p0['out'], 'data-pg-url'));
$pEd = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB2, ['admin' => false, 'perms' => $edP]));
ok('edit rolü (+Personel kartları Gör) → varsayılan Puantaj Girişi, sekmeler [Puantaj Girişi, Personel]', $pEd['rc'] === 0 && str_contains($pEd['out'], 'data-tur="giris"') && (bool)preg_match('/is-aktif[^>]*>Puantaj Girişi<\/a>\s*<a[^>]*>Personel<\/a>/', $pEd['out']));
$pSalt = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB2, ['admin' => false, 'perms' => $salt]));
ok('edit YOK → eski davranış AYNEN: varsayılan Personel, Puantaj Girişi sekmesi yok', $pSalt['rc'] === 0 && str_contains($pSalt['out'], 'data-tur="personel"') && !str_contains($pSalt['out'], 'Puantaj Girişi'));
$pEski = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB2, ['admin' => false, 'perms' => $eskiR]));
ok('eski rol (employees + daily_reports) → varsayılan Personel (değişmedi)', $pEski['rc'] === 0 && str_contains($pEski['out'], 'data-tur="personel"'));
ok('iç görünüm anahtarı: [📅 Puantaj] (aktif) + [👥 Kişiler]; Kişiler bağlantısı gorunum=kisiler; sekme çubuğu bağlantıları gorunum taşımaz', str_contains($p['out'], 'class="pg-gorunum"') && (bool)preg_match('/pg-gorunum-bag is-aktif"[^>]*aria-current="page">📅 Puantaj<\/a>\s*<a href="puantaj_cizelgesi\.php\?sekme=giris&amp;gorunum=kisiler"/', $p['out']));
$ps = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel'], $GB2);
ok("Personel sekmesinde iç görünüm anahtarı YOK; ?sekme=personel&gorunum=kisiler → yine Personel (gorunum yalnız giris'te)", !str_contains($ps['out'], 'pg-gorunum') && str_contains(sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'personel', 'gorunum' => 'kisiler'], $GB2)['out'], 'data-tur="personel"'));

echo "--- Puantaj görünümü: ad sütunu + seçim kutuları + gün seçimi + işlem çubuğu ---\n";
ok('AD SÜTUNU: her satırda th.pc-isim (scope=row) içinde AD metni; başlıkta köşe th.pc-kose "Kişi (3)"; th sayısı = td sayısı (kolon kayması yok)', (function () use ($p) {
    $tr = []; preg_match_all('/<tr data-pg-satir="\d+".*?<\/tr>/s', $p['out'], $tr);
    if (count($tr[0]) !== 3) return false;
    foreach ($tr[0] as $row) { if (!preg_match('/<th class="pc-isim" scope="row">.*?<span class="pc-isim-yazi">[^<]+/s', $row)) return false; }
    preg_match('/<thead>.*?<\/thead>/s', $p['out'], $th); preg_match_all('/<th\b/', $th[0], $hc); preg_match_all('/<td\b/', $tr[0][0], $dc); preg_match_all('/<th\b/', $tr[0][0], $rc);
    return str_contains($th[0], 'pc-kose') && str_contains($th[0], 'Kişi <span class="pc-isim-sayi">(3)</span>') && count($hc[0]) === (int)date('t') + 1 + 4 && count($rc[0]) + count($dc[0]) === count($hc[0]);
})());
$pgn = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'gunluk'], $GB2);
ok('AD SÜTUNU sertleştirme: üç sekmenin ızgarasında da <colgroup><col class="pc-col-isim"> (ilk sütun genişliği <col> ile de verilir; hücre min/max-width\'ini yok sayan tarayıcılar için); CSS col.pc-col-isim 210px', substr_count($p['out'], '<colgroup><col class="pc-col-isim"></colgroup>') === 1 && substr_count($ps['out'], '<colgroup><col class="pc-col-isim"></colgroup>') === 1 && substr_count($pgn['out'], '<colgroup><col class="pc-col-isim"></colgroup>') === 1 && (bool)preg_match('/\.pc-grid col\.pc-col-isim \{ width: 210px; min-width: 210px; \}/', oku('assets/puantaj_cizelgesi.css')));
ok('kişi seçim kutusu: yalnız AKTİF kişilerde (data-pg-sec 3 masaüstü + 3 mobil), pasif satırda kutu YOK (boş yer tutucu); başlıkta "tümünü seç" data-pg-sec-hepsi', substr_count($p['out'], 'data-pg-sec="') === 6 && !str_contains($p['out'], 'data-pg-sec="4"') && substr_count($p['out'], 'data-pg-sec-hepsi') === 1);
$ppa = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu, 'sekme' => 'giris', 'pasif' => '1'], $GB2);
ok('pasifler görünürken: pasif satırda onay kutusu YOK (pg-secbox-bos) ve ad sütunu yine dolu; "tümünü seç" yalnız aktifleri seçer (JS: tr:not(.pg-pasif))', preg_match('/<tr data-pg-satir="4" class="pg-pasif">\s*<th class="pc-isim" scope="row"><span class="pg-isim-kap"><span class="pg-secbox pg-secbox-bos"[^>]*><\/span>.*?Örnek Kişi D/s', $ppa['out']) === 1 && str_contains(oku('assets/puantaj_giris.js'), 'tr[data-pg-satir]:not(.pg-pasif)'));
ok('gün başlığı: data-pg-gun + data-hs (hafta sonu 1/0) + aria-pressed; hızlı seçim: Tüm günler / Hafta içleri / Hafta sonları / Temizle', substr_count($p['out'], 'data-pg-gun="') === (int)date('t') && substr_count($p['out'], 'data-hs="1"') === count(array_filter(pc_gunler($bu), fn($g) => $g['hs'])) && substr_count($p['out'], 'aria-pressed="false" title=') >= (int)date('t') && str_contains($p['out'], 'data-pg-gunsec="tum"') && str_contains($p['out'], 'data-pg-gunsec="haftaici"') && str_contains($p['out'], 'data-pg-gunsec="haftasonu"') && str_contains($p['out'], 'data-pg-gunsec="temizle"'));
ok('işlem çubuğu #pgCubuk (hidden): "N kişi × M gün", 7 kod düğmesi (X Y D İ Ü R T) + Temizle, "Dolu günlerin üzerine yaz" (VARSAYILAN KAPALI), Uygula (disabled), Seçimi kaldır, sayfa içi onay alanı', str_contains($p['out'], 'id="pgCubuk"') && (bool)preg_match('/id="pgCubuk"[^>]*hidden/', $p['out']) && substr_count($p['out'], 'data-pg-sec-kod="') === 8 && (bool)preg_match('/<input type="checkbox" id="pgUstune">/', $p['out']) && !preg_match('/id="pgUstune"[^>]*checked/', $p['out']) && (bool)preg_match('/id="pgUygula"[^>]*disabled/', $p['out']) && str_contains($p['out'], 'data-pg-sec-temizle') && str_contains($p['out'], 'id="pgCubukOnay"') && str_contains($p['out'], 'Dolu günlerin üzerine yaz'));
ok('mobil: "Toplu seç" düğmesi (data-pg-secmod) + özet; kişi kutusu details summary içinde (pg-msec); tarayıcı confirm YOK', str_contains($p['out'], 'data-pg-secmod') && str_contains($p['out'], 'class="pg-msec"') && !preg_match('/confirm\(|alert\(/', $p['out']));
ok('eski gün-sütunu davranışı UI\'dan ÇIKTI: sayfada "tüm görünen kişilere tek işaret" başlığı/gun_sutun YOK', !str_contains($p['out'], 'tüm görünen kişilere tek işaret') && !str_contains($p['out'], 'gun_sutun') && !str_contains(oku('assets/puantaj_giris.js'), 'gun_sutun'));
ok('satır menüsü (⋯) KORUNDU: Düzenle · Hafta içlerini Çalıştı yap · Ayı temizle · Kaldır · Geri al; hücre menüsü (tek hücre) korundu', str_contains($p['out'], 'data-pg-islem="haftaici"') && str_contains($p['out'], 'data-pg-islem="temizle"') && str_contains($p['out'], 'data-pg-islem="kaldir"') && str_contains($p['out'], 'id="pgMenu"'));
$pb = sayfa_calistir('puantaj_cizelgesi.php', ['ay' => $bu], array_merge($GB2, ['giris_kisiler' => [], 'giris_gunler' => []]));
ok('boş durum: işlem çubuğu/araç çubuğu YOK, pencereler hazır (JS null-güvenli)', !str_contains($pb['out'], 'id="pgCubuk"') && !str_contains($pb['out'], 'pgSecArac') && str_contains($pb['out'], 'id="pgPen"'));

echo "--- Kişiler görünümü ---\n";
$K = ['ay' => $bu, 'sekme' => 'giris', 'gorunum' => 'kisiler'];
$pk = sayfa_calistir('puantaj_cizelgesi.php', $K, $GB2);
ok('Kişiler: rc 0, uyarı yok; data-pg-gorunum="kisiler"; puantaj_giris_kisiler.js yüklenir (puantaj_giris.js ÖNCE)', $pk['rc'] === 0 && !preg_match('/Warning:|Notice:|Fatal|Deprecated/', $pk['out'] . $pk['err']) && str_contains($pk['out'], 'data-pg-gorunum="kisiler"') && strpos($pk['out'], 'assets/puantaj_giris.js') < strpos($pk['out'], 'assets/puantaj_giris_kisiler.js'), $pk['err']);
preg_match_all('/<tr data-pk-satir="(\d+)"/', $pk['out'], $mk);
ok('Kişiler: depo kuralı (Depo A + atanmamış; Depo B GİZLİ), pasifler DAHİL; ad A-Z: İsmail · Kişi A · Kişi B · Kişi D', $mk[1] === ['5', '1', '2', '4'], json_encode($mk[1]));
ok('sayaçlar: Toplam 4 · Aktif 3 · Pasif 1; filtre yokken "Gösterilen" çipi YOK', (bool)preg_match('/Toplam <b data-pk-say="toplam">4<\/b>.*?Aktif <b data-pk-say="aktif">3<\/b>.*?Pasif <b data-pk-say="pasif">1<\/b>/s', $pk['out']) && !str_contains($pk['out'], 'Gösterilen'));
ok('satır: onay kutusu (data-pk-sec), ad, görev, durum rozeti, gün kaydı sayısı, İşlemler: Düzenle · Pasife al / Aktif yap · Sil; "Sil" kırmızı (pk-sil)', preg_match('/<tr data-pk-satir="1" data-isim="Örnek Kişi A" data-gorev="Paketçi" data-aktif="1" data-gun="4">.*?data-pk-sec="1".*?<td class="pk-isim" data-label="Ad Soyad">Örnek Kişi A<\/td>.*?Paketçi.*?pk-rozet-aktif">Aktif.*?<td class="pk-sayi" data-label="Gün kaydı">4<\/td>.*?data-pk-islem="duzenle".*?Pasife al.*?data-pk-islem="sil"/s', $pk['out']) === 1 && preg_match('/data-pk-satir="4".*?pk-rozet-pasif">Pasif.*?Aktif yap/s', $pk['out']) === 1 && str_contains($pk['out'], 'class="btn btn-sm pk-sil"'), substr($pk['out'], strpos($pk['out'], 'data-pk-satir="1"') - 30, 600));
ok('toplu işlem çubuğu #pkCubuk (hidden): Pasife al · Aktif yap · Sil · Seçimi kaldır; başlıkta tümünü seç (data-pk-hepsi)', (bool)preg_match('/id="pkCubuk"[^>]*hidden/', $pk['out']) && str_contains($pk['out'], 'data-pk-toplu="pasif"') && str_contains($pk['out'], 'data-pk-toplu="aktif"') && str_contains($pk['out'], 'data-pk-toplu="sil"') && str_contains($pk['out'], 'data-pk-hepsi'));
ok("Excel'den ekle: üst çubukta düğme (data-pk-excel-ac) + pencere #pkExcel (aria-modal, hidden): şablon bağlantısı sablon=1, dosya seçici .xlsx/.csv, önizleme tablosu, Ekle (disabled)", str_contains($pk['out'], 'data-pk-excel-ac') && (bool)preg_match('/id="pkExcel" role="dialog" aria-modal="true"[^>]*hidden/', $pk['out']) && str_contains($pk['out'], 'puantaj_cizelgesi.php?sekme=giris&amp;sablon=1') && str_contains($pk['out'], 'accept=".xlsx,.csv') && str_contains($pk['out'], 'id="pkExcelGovde"') && (bool)preg_match('/id="pkExcelEkle"[^>]*disabled/', $pk['out']));
ok("Kişiler üst çubuk: ay oku/Excel menüsü/Yazdır YOK (çizelgeye ait); arama + durum süzgeci + sekmeler + '+ Kişi ekle' var; form GET (post yok)", !str_contains($pk['out'], 'class="pc-ay"') && !str_contains($pk['out'], 'dl-menu') && !str_contains($pk['out'], 'puantaj_cizelgesi_yazdir.php') && str_contains($pk['out'], 'name="durum"') && str_contains($pk['out'], 'name="gorunum" value="kisiler"') && str_contains($pk['out'], 'data-pg-kisi-ekle') && !preg_match('/method="post"|name="csrf"/i', $pk['out']) && substr_count($pk['out'], '<form') === 1);
ok('Kişiler: çizelgeye ait öğeler YOK (gün başlığı, lejant, işlem çubuğu #pgCubuk, pc-grid)', !str_contains($pk['out'], 'data-pg-gun') && !str_contains($pk['out'], 'id="pcLejant"') && !str_contains($pk['out'], 'id="pgCubuk"') && !str_contains($pk['out'], 'class="pc-grid'));
$pkd = sayfa_calistir('puantaj_cizelgesi.php', $K + ['durum' => 'pasif'], $GB2);
preg_match_all('/<tr data-pk-satir="(\d+)"/', $pkd['out'], $m1);
ok("durum süzgeci 'pasif' → yalnız D; 'Gösterilen 1' çipi; sayaçlar süzgeçten ETKİLENMEZ (Toplam 4)", $m1[1] === ['4'] && str_contains($pkd['out'], 'Gösterilen <b>1</b>') && str_contains($pkd['out'], 'data-pk-say="toplam">4<') && str_contains($pkd['out'], '<option value="pasif" selected>'));
preg_match_all('/<tr data-pk-satir="(\d+)"/', sayfa_calistir('puantaj_cizelgesi.php', $K + ['durum' => 'aktif'], $GB2)['out'], $m2);
ok("durum süzgeci 'aktif' → İsmail · A · B (pasif yok)", $m2[1] === ['5', '1', '2']);
preg_match_all('/<tr data-pk-satir="(\d+)"/', sayfa_calistir('puantaj_cizelgesi.php', $K + ['q' => 'ISMAIL'], $GB2)['out'], $m3);
ok("arama 'ISMAIL' (TR duyarsız) → yalnız İsmail; arama kutusu değeri korunur; geçersiz durum değeri → hepsi", $m3[1] === ['5'] && str_contains(sayfa_calistir('puantaj_cizelgesi.php', $K + ['q' => 'ISMAIL'], $GB2)['out'], 'value="ISMAIL"') && preg_match_all('/<tr data-pk-satir=/', sayfa_calistir('puantaj_cizelgesi.php', $K + ['durum' => 'saçma'], $GB2)['out']) === 4);
$pkz = sayfa_calistir('puantaj_cizelgesi.php', $K + ['q' => 'zzz'], $GB2);
ok('arama sonucu boş → "uyan kişi yok" mesajı, tablo yok, sayaçlar durur', str_contains($pkz['out'], 'uyan kişi yok') && !str_contains($pkz['out'], 'id="pkTablo"') && str_contains($pkz['out'], 'data-pk-say="toplam">4<'));
$pkb = sayfa_calistir('puantaj_cizelgesi.php', $K, array_merge($GB2, ['giris_kisiler' => [], 'giris_gunler' => []]));
ok("hiç kişi yok → 'Henüz kişi eklemediniz' + '+ Kişi ekle' + 'Excel'den ekle' büyük düğmeleri; pencereler (Excel + kişi) hazır", str_contains($pkb['out'], 'Henüz kişi eklemediniz') && str_contains($pkb['out'], 'pg-buyuk') && substr_count($pkb['out'], 'data-pk-excel-ac') >= 2 && str_contains($pkb['out'], 'id="pkExcel"') && str_contains($pkb['out'], 'id="pgPen"'));
$pkx = sayfa_calistir('puantaj_cizelgesi.php', $K, array_merge($GB2, ['giris_kisiler' => [[1, '<script>alert(1)</script>', '"><img src=x onerror=alert(2)>', 'Depo A', 1]], 'giris_gunler' => []]));
ok('XSS: ad/görev h() ile kaçırılır (hücre, data-isim, data-gorev, aria-label); ham <script>/<img yok', !str_contains($pkx['out'], '<script>alert(1)') && !str_contains($pkx['out'], '<img src=x') && str_contains($pkx['out'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($pkx['out'], 'data-gorev=""><img'));
$pkt = sayfa_calistir('puantaj_cizelgesi.php', $K, array_merge($GB2, ['giris' => false]));
ok('Kişiler + tablo YOK: nazik hata + migrate bağlantısı, 500 yok, işlem düğmeleri yok', $pkt['rc'] === 0 && str_contains($pkt['out'], 'migrate.php#puantaj-giris') && !str_contains($pkt['out'], 'data-pk-excel-ac') && !str_contains($pkt['out'], 'data-pg-url'));
$pkp = sayfa_calistir('puantaj_cizelgesi.php', $K, array_merge($GB2, ['admin' => false, 'perms' => $salt]));
ok('Kişiler: edit yetkisi YOK → Puantaj Girişi yok → Personel sekmesine düşer (gorunum etkisiz)', $pkp['rc'] === 0 && str_contains($pkp['out'], 'data-tur="personel"') && !str_contains($pkp['out'], 'data-pk-satir'));
$pke = sayfa_calistir('puantaj_cizelgesi.php', $K, array_merge($GB2, ['admin' => false, 'perms' => $sadEd]));
ok('Kişiler: yalnız sayfa read+edit rolü AÇAR (kaynak izni gerekmez)', $pke['rc'] === 0 && str_contains($pke['out'], 'data-pk-satir="1"'));
$pkc = sayfa_calistir('puantaj_cizelgesi.php', $K + ['csv' => '1'], array_merge($GB2, ['admin' => false, 'perms' => $exp]));
ok('gorunum=kisiler iken ?csv → ÇİZELGE CSV (kişiler görünümü dışa aktarılmaz); rc 0', $pkc['rc'] === 0 && str_contains($pkc['out'], '"Ad Soyad";Görev;"1 '), substr($pkc['out'], 0, 300) . $pkc['err']);

echo "--- Excel şablonu (?sablon=1) ---\n";
$sb = sayfa_calistir('puantaj_cizelgesi.php', ['sekme' => 'giris', 'sablon' => '1'], $GB2);
$sbDosya = tempnam(sys_get_temp_dir(), 'pgsb_'); file_put_contents($sbDosya, $sb['out']);
ok('şablon: yönetici → xlsx (PK), rc 0', $sb['rc'] === 0 && substr($sb['out'], 0, 2) === 'PK', substr($sb['out'], 0, 80));
if (substr($sb['out'], 0, 2) === 'PK') {
    $ssb = \PhpOffice\PhpSpreadsheet\IOFactory::load($sbDosya);
    $names = $ssb->getSheetNames(); $w0 = $ssb->getSheet(0);
    ok("şablon içeriği: sayfalar [Kişiler, Açıklama]; A1 'Ad Soyad', B1 'Görev'; iki SENTETİK örnek satır ('Örnek Kişi A', 'Örnek Kişi B'); gerçek kişi adı yok", $names === ['Kişiler', 'Açıklama'] && $w0->getCell('A1')->getValue() === 'Ad Soyad' && $w0->getCell('B1')->getValue() === 'Görev' && $w0->getCell('A2')->getValue() === 'Örnek Kişi A' && $w0->getCell('A3')->getValue() === 'Örnek Kişi B' && $w0->getCell('A4')->getValue() === null);
    $dz = pge_dosya_dogrula(['name' => 'sablon.xlsx', 'tmp_name' => $sbDosya, 'error' => 0], fn($t) => true);
    $hm = $dz['ok'] ? pge_dosya_oku($dz) : [];
    $cz = pge_satirlari_coz($hm, []);
    ok("şablon geri okunur (kendi ayrıştırıcımızdan geçer): başlık bulunur, 2 geçerli satır; 'Açıklama' sayfası OKUNMAZ (yalnız ilk sayfa)", $dz['ok'] && $cz['baslik'] === true && $cz['ozet']['toplam'] === 2 && $cz['ozet']['gecerli'] === 2 && $cz['satirlar'][0]['ad'] === 'Örnek Kişi A' && $cz['satirlar'][0]['gorev'] === 'Paketçi');
}
@unlink($sbDosya);
$sb2 = sayfa_calistir('puantaj_cizelgesi.php', ['sekme' => 'giris', 'sablon' => '1'], array_merge($GB2, ['admin' => false, 'perms' => $salt]));
ok('şablon: edit YOK → Puantaj Girişi yok → Personel sayfası (şablon DÖNMEZ)', $sb2['rc'] === 0 && substr($sb2['out'], 0, 2) !== 'PK');
$sb3 = sayfa_calistir('puantaj_cizelgesi.php', ['sekme' => 'giris', 'sablon' => '1'], array_merge($GB2, ['admin' => false, 'perms' => $sadEd]));
ok('şablon: yalnız sayfa read+edit rolü indirebilir', $sb3['rc'] === 0 && substr($sb3['out'], 0, 2) === 'PK');

echo "\n=== 6e. Yazma ucu: kisi_sil · kisi_toplu ===\n";
$ids_all = fn(array $kisiler) => array_column($kisiler, 'id');
echo "--- kapılar (yeni eylemler) ---\n";
foreach ([['kisi_sil', ['kisi_id' => '1']], ['kisi_toplu', ['kisi_ids' => ['1', '2'], 'islem' => 'sil']], ['gun_toplu_sec', ['ay' => $bu, 'kisi_ids' => ['1'], 'tarihler' => ["$bu-06"], 'kod' => 'X']]] as [$eylem, $alan]) {
    $pk = giris_post(['action' => $eylem] + $alan, ['admin' => true]);
    ok("$eylem: CSRF YOK → 403 + JSON (rc 5), DB yazılmadı, audit YOK", $pk['rc'] === 5 && ($pk['json']['code'] ?? 0) === 403 && count($pk['kisiler']) === 5 && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
    $pk = giris_post($T + ['action' => $eylem] + $alan, ['admin' => false, 'perms' => $salt]);
    ok("$eylem: yalnız 'read' → 403 (rota kapısı), DB yazılmadı", $pk['rc'] === 3 && ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5 && count($pk['gunler']) === count($GB['giris_gunler']));
    $pk = giris_post($T + ['action' => $eylem] + $alan, ['admin' => false, 'perms' => $eskiR]);
    ok("$eylem: ESKİ rol (edit otomatik yok) → 403", $pk['rc'] === 3 && count($pk['kisiler']) === 5);
    $pk = giris_post($T + ['action' => $eylem] + $alan, ['depo' => '']);
    ok("$eylem: aktif depo yok → 400", ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5);
    $pk = giris_post($T + ['action' => $eylem] + $alan, ['giris' => false]);
    ok("$eylem: tablolar kurulmamış → 503 + migrate yönlendirmesi, DDL ATMAZ", ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), 'migrate.php') && $pk['kisiler'] === null);
    $pk = giris_post($T + ['action' => $eylem] + $alan, ['admin' => false, 'perms' => $sadEd]);
    ok("$eylem: yalnız sayfa read+edit rolü (kaynak izni YOK) çalıştırabilir (bağımsız veri)", ($pk['json']['ok'] ?? false) === true, $pk['out']);
}

echo "--- kisi_sil ---\n";
$pk = giris_post($T + ['action' => 'kisi_sil', 'kisi_id' => '1']);
ok('kisi_sil: kişi + TÜM gün kayıtları (4) silinir; diğer kişilerin 3 kaydı kalır; yanıt gun_silinen 4', ($pk['json']['ok'] ?? false) === true && $pk['json']['gun_silinen'] === 4 && !in_array(1, $ids_all($pk['kisiler']), true) && gun_say_kisi($pk['gunler'], 1) === 0 && count($pk['gunler']) === count($GB['giris_gunler']) - 4 && count($pk['kisiler']) === 4, $pk['out'] . $pk['err']);
ok('kisi_sil audit: delete / puantaj_giris / id / eski {aktif, depo, gun_kaydi} / yeni null; AD YOK', str_contains($pk['err'], 'AUDIT: ["delete","puantaj_giris",1,{"aktif":1,"depo":"Depo A","gun_kaydi":4},null]') && !str_contains(aud($pk['err']), 'Örnek Kişi'), $pk['err']);
$pk = giris_post($T + ['action' => 'kisi_sil', 'kisi_id' => '4']);
ok('kisi_sil: PASİF kişi de silinebilir (gün kaydı 1 de silinir)', ($pk['json']['ok'] ?? false) === true && $pk['json']['gun_silinen'] === 1 && !in_array(4, $ids_all($pk['kisiler']), true));
$pk = giris_post($T + ['action' => 'kisi_sil', 'kisi_id' => '3'], ['allowed' => ['Depo A']]);
ok('kisi_sil: BAŞKA DEPO kişisi → 403 JSON, silinmedi, audit YOK', ($pk['json']['code'] ?? 0) === 403 && count($pk['kisiler']) === 5 && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'));
$pk = giris_post($T + ['action' => 'kisi_sil', 'kisi_id' => '2']);
ok('kisi_sil: atanmamış (boş) depolu kişi her depoda silinebilir', ($pk['json']['ok'] ?? false) === true);
foreach (['999', 'abc', '0', '-1', '1.5', ''] as $kid) {
    $pk = giris_post($T + ['action' => 'kisi_sil', 'kisi_id' => $kid]);
    ok("kisi_sil geçersiz / olmayan kisi_id '$kid' → 400/404, hiçbir şey silinmedi", ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5 && !str_contains($pk['err'], 'AUDIT:'));
}
$pk = giris_post($T + ['action' => 'kisi_sil', 'kisi_id' => ['1']]);
ok('kisi_sil: kisi_id dizi → reddedilir', ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5);
$pk = giris_post($T + ['action' => 'kisi_sil', 'kisi_id' => '5']);
ok('kisi_sil: employees / PDKS tablolarına dokunmaz (satır sayıları değişmez)', ($pk['json']['ok'] ?? false) === true && $pk['sayilar'] === giris_post($T + ['action' => 'sil'])['sayilar']);

echo "--- kisi_toplu ---\n";
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => ['1', '5'], 'islem' => 'pasif']);
ok('kisi_toplu pasif: 1 ve 5 pasif olur (etkilenen 2); gün kayıtları DOKUNULMAZ; audit TEK özet (kisi_sayisi/etkilenen/atlanan), kişi adı YOK', ($pk['json']['ok'] ?? false) === true && $pk['json']['etkilenen'] === 2 && $pk['json']['atlanan'] === 0 && array_column(array_filter($pk['kisiler'], fn($k) => in_array($k['id'], [1, 5], true)), 'aktif') === [0, 0] && count($pk['gunler']) === count($GB['giris_gunler'])
    && substr_count($pk['err'], 'AUDIT:') === 1 && str_contains($pk['err'], 'AUDIT: ["update","puantaj_giris",null,null,{"mod":"kisi_toplu","islem":"pasif","kisi_sayisi":2,"etkilenen":2,"atlanan":0,"gun_silinen":0}]') && !str_contains(aud($pk['err']), 'Örnek'), $pk['out'] . $pk['err']);
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => ['4', '1'], 'islem' => 'aktif']);
ok('kisi_toplu aktif: pasif 4 aktif olur, zaten aktif 1 "atlanan" (1 etkilenen, 1 atlanan)', ($pk['json']['ok'] ?? false) === true && $pk['json']['etkilenen'] === 1 && $pk['json']['atlanan'] === 1 && $pk['kisiler'][3]['aktif'] === 1);
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => ['1', '2', '4'], 'islem' => 'sil']);
ok('kisi_toplu sil: 3 kişi + TÜM gün kayıtları (4 + 1 + 1 = 6) silinir; kalan: 3 (Depo B) ve 5; audit delete TEK özet (gun_silinen 6)', ($pk['json']['ok'] ?? false) === true && $pk['json']['etkilenen'] === 3 && $pk['json']['gun_silinen'] === 6 && $ids_all($pk['kisiler']) === [3, 5] && gun_say_kisi($pk['gunler'], 1) + gun_say_kisi($pk['gunler'], 2) + gun_say_kisi($pk['gunler'], 4) === 0 && gun_say_kisi($pk['gunler'], 3) === 1
    && substr_count($pk['err'], 'AUDIT:') === 1 && str_contains($pk['err'], 'AUDIT: ["delete","puantaj_giris",null,null,{"mod":"kisi_toplu","islem":"sil","kisi_sayisi":3,"etkilenen":3,"atlanan":0,"gun_silinen":6}]'), $pk['out'] . $pk['err']);
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => ['1', '1', '1'], 'islem' => 'pasif']);
ok('kisi_toplu: yinelenen kimlikler teke iner (kisi_sayisi 1)', ($pk['json']['ok'] ?? false) === true && $pk['json']['kisi_sayisi'] === 1);
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => ['1', '3'], 'islem' => 'sil'], ['allowed' => ['Depo A']]);
ok('kisi_toplu: listede BAŞKA DEPO kişisi (3) varsa 403 ve HİÇBİRİ silinmez (hepsi ya da hiçbiri; depo kuralı HER kişide)', ($pk['json']['code'] ?? 0) === 403 && count($pk['kisiler']) === 5 && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'));
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => ['1', '999'], 'islem' => 'sil']);
ok('kisi_toplu: olmayan kimlik (999) → 404 ve HİÇBİRİ silinmez', ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5 && !str_contains($pk['err'], 'AUDIT:'));
foreach ([['kisi_ids' => []], ['kisi_ids' => 'abc'], ['kisi_ids' => ['x']], ['kisi_ids' => ['1.5']], ['kisi_ids' => ['0']], ['kisi_ids' => ['-1']], ['kisi_ids' => [['1']]], ['kisi_ids' => ['1' => '1']], ['islem' => 'yok'], ['islem' => 'SIL'], ['islem' => ['sil']], ['islem' => '']] as $kotu) {
    $pk = giris_post($T + $kotu + ['action' => 'kisi_toplu', 'kisi_ids' => ['1', '2'], 'islem' => 'sil']);
    ok('kisi_toplu geçersiz girdi reddedilir (400), DB değişmedi: ' . json_encode($kotu, JSON_UNESCAPED_UNICODE), ($pk['json']['ok'] ?? null) === false && count($pk['kisiler']) === 5 && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
}
$buyukK = []; for ($i = 1; $i <= 501; $i++) $buyukK[] = [$i, 'Örnek Kişi ' . $i, '', 'Depo A', 1];
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => array_map('strval', range(1, 501)), 'islem' => 'pasif'], ['giris_kisiler' => $buyukK, 'giris_gunler' => []]);
ok('kisi_toplu: 501 kimlik → 400 (üst sınır 500), hiçbiri değişmedi', ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), '500') && count(array_filter($pk['kisiler'], fn($k) => $k['aktif'] === 0)) === 0, $pk['out']);
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => array_map('strval', range(1, 500)), 'islem' => 'pasif'], ['giris_kisiler' => $buyukK, 'giris_gunler' => []]);
ok('kisi_toplu: tam 500 kimlik KABUL (500 pasif)', ($pk['json']['ok'] ?? false) === true && $pk['json']['etkilenen'] === 500 && count(array_filter($pk['kisiler'], fn($k) => $k['aktif'] === 0)) === 500);
$pk = giris_post($T + ['action' => 'kisi_toplu', 'kisi_ids' => ['1'], 'islem' => 'sil', 'x' => 'y'], ['admin' => false, 'perms' => $sadEd, 'depo' => 'Depo A']);
ok('kisi_toplu: ayrı JSON gövdesi ve form gövdesi aynı kapıdan geçer (dizi alanı $_POST dizisi olarak da kabul)', ($pk['json']['ok'] ?? false) === true);

echo "\n=== 6f. Yazma ucu: gun_toplu_sec (çok kişi × çok gün) ===\n";
$GM = ['kisi_ids' => ['1', '2', '5'], 'tarihler' => [$g2, "$bu-03", "$bu-04"], 'ay' => $bu];
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'X'] + $GM);
// A: 2 X(aynı), 3 Y, 4 D · B: 3 R · İsmail: boş → yazılan: B 2,4 + İsmail 3 = 5; atlanan: A3, A4, B3 = 3; degismedi: A2 = 1
ok('gun_toplu_sec (üstüne yaz KAPALI, varsayılan): yalnız BOŞLAR dolar — yazılan 5, atlanan 3 (dolu), degismedi 1; dolu değerler (Y, D, R) korunur', ($pk['json']['ok'] ?? false) === true && $pk['json']['yazilan'] === 5 && $pk['json']['atlanan'] === 3 && $pk['json']['degismedi'] === 1 && $pk['json']['kisi_sayisi'] === 3 && $pk['json']['gun_sayisi'] === 3 && $pk['json']['pasif_atlanan'] === 0 && count($pk['json']['degisim']) === 5
    && in_array(['kisi_id' => 1, 'tarih' => "$bu-03", 'kod' => 'Y', 'user_id' => 1], $pk['gunler'], true) && in_array(['kisi_id' => 2, 'tarih' => "$bu-03", 'kod' => 'R', 'user_id' => 1], $pk['gunler'], true), $pk['out'] . $pk['err']);
ok("audit: TEK özet {mod, ay, kisi_sayisi, gun_sayisi, kod, ustune_yaz, yazilan, atlanan, pasif_atlanan}; gün/kişi başına audit YOK; ad YOK", substr_count($pk['err'], 'AUDIT:') === 1 && str_contains($pk['err'], 'AUDIT: ["update","puantaj_giris",null,null,{"mod":"gun_toplu_sec","ay":"' . $bu . '","kisi_sayisi":3,"gun_sayisi":3,"kod":"X","ustune_yaz":0,"yazilan":5,"atlanan":3,"pasif_atlanan":0}]') && !str_contains(aud($pk['err']), 'Örnek') && !str_contains(aud($pk['err']), 'İsmail'), $pk['err']);
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'X', 'ustune_yaz' => '1'] + $GM);
ok("gun_toplu_sec üstüne yaz AÇIK ('1'): A3, A4, B3 de X olur → yazılan 3 + 5 = 8; atlanan 0; audit ustune_yaz 1", ($pk['json']['ok'] ?? false) === true && $pk['json']['yazilan'] === 8 && $pk['json']['atlanan'] === 0 && str_contains($pk['err'], '"ustune_yaz":1'), $pk['out']);
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'X', 'ustune_yaz' => true] + $GM);
ok('gun_toplu_sec: ustune_yaz JSON/boolean true da kabul', ($pk['json']['ok'] ?? false) === true && $pk['json']['yazilan'] === 8);
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => ''] + $GM);
ok("gun_toplu_sec Temizle (kod ''): ustune_yaz KAPALI olsa da seçili HÜCRELER silinir (dolu 4: A2 A3 A4 B3); boş hücreler dokunulmaz", ($pk['json']['ok'] ?? false) === true && $pk['json']['yazilan'] === 4 && $pk['json']['atlanan'] === 0 && $pk['json']['degismedi'] === 5 && count(array_filter($pk['json']['degisim'], fn($d) => $d['c'] === '')) === 4 && gun_say_kisi($pk['gunler'], 1) === 1 && gun_say_kisi($pk['gunler'], 2) === 0, $pk['out']);
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'Y', 'kisi_ids' => ['1', '4'], 'tarihler' => ["$bu-10"], 'ay' => $bu]);
ok('gun_toplu_sec: PASİF kişi (4) ATLANIR (pasif_atlanan 1) ve rapora yazılır; aktif kişiye yazılır', ($pk['json']['ok'] ?? false) === true && $pk['json']['pasif_atlanan'] === 1 && $pk['json']['kisi_sayisi'] === 1 && $pk['json']['yazilan'] === 1 && !in_array(['kisi_id' => 4, 'tarih' => "$bu-10", 'kod' => 'Y', 'user_id' => 1], $pk['gunler'], true), $pk['out']);
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'Y', 'kisi_ids' => ['4'], 'tarihler' => ["$bu-10"], 'ay' => $bu]);
ok('gun_toplu_sec: seçilenlerin HEPSİ pasifse → 400', ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), 'pasif'));
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'X', 'kisi_ids' => ['1', '3'], 'tarihler' => ["$bu-10"], 'ay' => $bu], ['allowed' => ['Depo A']]);
ok('gun_toplu_sec: BAŞKA DEPO kişisi (3) listedeyse 403 ve HİÇBİR kişiye yazılmaz (depo kuralı her kişide)', ($pk['json']['code'] ?? 0) === 403 && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'));
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'X', 'kisi_ids' => ['1', '999'], 'tarihler' => ["$bu-10"], 'ay' => $bu]);
ok('gun_toplu_sec: olmayan kişi → 404, yazılmadı', ($pk['json']['ok'] ?? null) === false && count($pk['gunler']) === count($GB['giris_gunler']));
$ayDisi = date('Y-m-d', strtotime('first day of next month'));
$otuzIki = array_merge(array_map(fn($n) => sprintf('%s-%02d', $bu, $n), range(1, 28)), ["$bu-01", "$bu-02", "$bu-03", "$bu-04"]);
foreach ([['ay' => '2026-13'], ['ay' => 'abc'], ['ay' => ''], ['ay' => "$bu-01"], ['ay' => ['x']], ['tarihler' => [$ayDisi]], ['tarihler' => ['2026-02-30']], ['tarihler' => ["$bu-1"]], ['tarihler' => []], ['tarihler' => 'x'], ['tarihler' => [5]], ['tarihler' => [['a']]], ['tarihler' => $otuzIki], ['tarihler' => ["$bu-02", $ayDisi]], ['kod' => 'x'], ['kod' => 'Q'], ['kod' => 'XY'], ['kod' => ['X']], ['ustune_yaz' => 'abc'], ['ustune_yaz' => ['1']], ['kisi_ids' => []], ['kisi_ids' => 'x'], ['kisi_ids' => ['1', 'x']]] as $kotu) {
    $pk = giris_post($T + $kotu + ['action' => 'gun_toplu_sec', 'kod' => 'X'] + $GM);
    ok('gun_toplu_sec geçersiz girdi reddedilir (400), hiçbir şey yazılmadı, audit YOK: ' . mb_substr(json_encode($kotu, JSON_UNESCAPED_UNICODE), 0, 70), ($pk['json']['ok'] ?? null) === false && count($pk['gunler']) === count($GB['giris_gunler']) && !str_contains($pk['err'], 'AUDIT:'), $pk['out']);
}
$pk = giris_post($T + ['action' => 'gun_toplu_sec'] + $GM);
ok('gun_toplu_sec: kod alanı hiç yok → reddedilir (sessizce temizleme YAPMAZ)', ($pk['json']['ok'] ?? null) === false && count($pk['gunler']) === count($GB['giris_gunler']));
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'X', 'kisi_ids' => array_map('strval', range(1, 501)), 'tarihler' => ["$bu-02"], 'ay' => $bu], ['giris_kisiler' => $buyukK, 'giris_gunler' => []]);
ok('gun_toplu_sec: 501 kişi → 400 (üst sınır 500)', ($pk['json']['ok'] ?? null) === false && str_contains((string)($pk['json']['error'] ?? ''), '500') && $pk['gunler'] === []);
$ay31 = date('Y-m', strtotime('first day of december this year'));
$gunler31 = array_map(fn($n) => sprintf('%s-%02d', $ay31, $n), range(1, 31));
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'X', 'kisi_ids' => array_map('strval', range(1, 500)), 'tarihler' => $gunler31, 'ay' => $ay31], ['giris_kisiler' => $buyukK, 'giris_gunler' => []]);
ok('gun_toplu_sec ÜST SINIR (uçtan uca): 500 kişi × 31 gün = 15500 hücre tek istekte yazılır; audit tek özet; yanıt 15500 degisim', ($pk['json']['ok'] ?? false) === true && $pk['json']['yazilan'] === 15500 && count($pk['gunler']) === 15500 && count($pk['json']['degisim']) === 15500 && substr_count($pk['err'], 'AUDIT:') === 1, substr($pk['out'], 0, 200) . $pk['err']);
$ayBu31 = array_map(fn($n) => sprintf('%s-%02d', $bu, $n), range(1, (int)date('t')));
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'T', 'kisi_ids' => ['1'], 'tarihler' => $ayBu31, 'ay' => $bu]);
ok('gun_toplu_sec: ayın TÜM günleri (bu ay ' . date('t') . ' gün) tek kişiye', ($pk['json']['ok'] ?? false) === true && $pk['json']['gun_sayisi'] === (int)date('t'));
ok('gun_toplu_sec: employees / PDKS tablolarına dokunmaz', $pk['sayilar'] === giris_post($T + ['action' => 'sil'])['sayilar']);
$pk = giris_post($T + ['action' => 'gun_toplu_sec', 'kod' => 'X', 'kisi_ids' => ['1'], 'tarihler' => ["$bu-02"], 'ay' => $bu, 'kisi_ids2' => 'ignored'], ['admin' => false, 'perms' => $sadEd]);
ok('gun_toplu_sec: yalnız sayfa read+edit rolü yazabilir', ($pk['json']['ok'] ?? false) === true);
ok('gun_sutun uç eylemi KORUNDU (UI çağırmaz ama uç duruyor): aynı istek hâlâ çalışır', (function () use ($T, $g6) { $r = giris_post($T + ['action' => 'gun_sutun', 'tarih' => $g6, 'kod' => 'T']); return ($r['json']['ok'] ?? false) === true && $r['json']['adet'] === 3; })());

echo "\n=== 6g. Excel'den ekle: dosya doğrulama + okuma (gerçek xlsx / csv dosyaları) ===\n";
$XDIR = sys_get_temp_dir() . '/pgxl_' . getmypid();
@mkdir($XDIR, 0700, true);
register_shutdown_function(function () use ($XDIR) { foreach (glob($XDIR . '/*') ?: [] as $f) @unlink($f); @rmdir($XDIR); });
function pg_xlsx_yaz(string $yol, array $satirlar, ?callable $ek = null): string
{
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $ws = $ss->getActiveSheet();
    foreach ($satirlar as $i => $r) foreach ($r as $j => $v) {
        if ($v === null || $v === '') continue;
        if (is_string($v) && !str_starts_with($v, '=F:')) $ws->setCellValueExplicit([$j + 1, $i + 1], $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        elseif (is_string($v)) $ws->setCellValue([$j + 1, $i + 1], substr($v, 3));   // '=F:=1+1' → gerçek formül
        else $ws->setCellValue([$j + 1, $i + 1], $v);
    }
    if ($ek) $ek($ss);
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx')->save($yol);
    $ss->disconnectWorksheets();
    return $yol;
}
function pg_dosya_yaz(string $yol, string $icerik): string { file_put_contents($yol, $icerik); return $yol; }
function ham_oku(string $yol, string $ad): array { $d = pge_dosya_dogrula(['name' => $ad, 'tmp_name' => $yol, 'error' => 0], fn($t) => true); return $d['ok'] ? pge_dosya_oku($d) : ['hata' => $d['hata']]; }

$xs = pg_xlsx_yaz("$XDIR/a.xlsx", [['Ad Soyad', 'Görev'], ['Örnek Kişi Ş', 'Paketçi'], ['Örnek Kişi Ç', null], [null, null], ['Örnek Kişi Ğ', 'Şoför']]);
$h1 = ham_oku($xs, 'a.xlsx');
ok('xlsx okuma: başlık + 3 veri satırı (tamamen boş satır atlanır); TR karakterler doğru; her satır 10 sütun', $h1 === [array_pad(['Ad Soyad', 'Görev'], 10, ''), array_pad(['Örnek Kişi Ş', 'Paketçi'], 10, ''), array_pad(['Örnek Kişi Ç', ''], 10, ''), array_pad(['Örnek Kişi Ğ', 'Şoför'], 10, '')], json_encode($h1, JSON_UNESCAPED_UNICODE));
$xf = pg_xlsx_yaz("$XDIR/f.xlsx", [['Ad Soyad', 'Görev'], ['=F:=1+1', 'x'], ['=HYPERLINK("http://x","t")', '+görev'], ['Örnek Kişi A', '@x']]);
$zf = new ZipArchive(); $zf->open($xf); $sx = $zf->getFromName('xl/worksheets/sheet1.xml'); $zf->addFromString('xl/worksheets/sheet1.xml', str_replace('<f>1+1</f><v>2</v>', '<f>1+1</f><v>42</v>', $sx)); $zf->close();   // dosyadaki ÖNBELLEK değerini 42 yap: biz hesaplasaydık 2 çıkardı
$hf = ham_oku($xf, 'f.xlsx');
ok("formül ASLA hesaplanmaz: gerçek formül hücresi ('=1+1', dosyadaki önbellek 42) '42' (önbellek) olarak okunur — hesaplansaydı '2' olurdu; METİN hücresi '=HYPERLINK(...)' olduğu gibi okunur, '+görev' / '@x' düz metin", is_array($hf) && !isset($hf['hata']) && ($hf[1][0] ?? 'x') === '42' && $hf[2][0] === '=HYPERLINK("http://x","t")' && $hf[2][1] === '+görev' && $hf[3][1] === '@x', json_encode($hf, JSON_UNESCAPED_UNICODE));
$xf2 = pg_xlsx_yaz("$XDIR/f2.xlsx", [['Ad Soyad'], ['=F:=SUM(2,3)']]);
$zf = new ZipArchive(); $zf->open($xf2); $sx = $zf->getFromName('xl/worksheets/sheet1.xml'); $zf->addFromString('xl/worksheets/sheet1.xml', preg_replace('#<v>5</v>#', '', $sx)); $zf->close();   // önbellek YOK
ok("formül önbelleği yoksa hücre BOŞ sayılır (HESAPLANMAZ): yalnız formül içeren satır tamamen boş satır gibi atlanır → veri satırı 0", count(ham_oku($xf2, 'f2.xlsx')) === 1 && pge_satirlari_coz(ham_oku($xf2, 'f2.xlsx'), [])['ozet']['toplam'] === 0);
$xi = pg_xlsx_yaz("$XDIR/i.xlsx", [['Rapor Başlığı', null], ['Çalışan Adı Soyadı', 'Ünvan'], ['Örnek Kişi A', 'Depocu']], function ($ss) { $ss->createSheet()->setTitle('Diğer'); $ss->getSheet(1)->setCellValue('A1', 'Başka Sayfa Kişisi'); });
$hi = ham_oku($xi, 'i.xlsx'); $ci = pge_satirlari_coz($hi, []);
ok("yalnız İLK sayfa okunur (ikinci sayfadaki satır gelmez); üstte başlık/rapor satırı olsa da 'Çalışan Adı Soyadı' ≠ tanınan başlık → başlıksız: ilk sütun ad (uyarı)", count(array_filter($hi, fn($r) => str_contains(implode('', $r), 'Başka Sayfa'))) === 0 && $ci['baslik'] === false && $ci['ozet']['toplam'] === 3);
$xl = pg_dosya_yaz("$XDIR/l.xlsx", "bu bir excel dosyası değil");
ok('.xlsx uzantılı ama PK imzası yok → "Geçerli bir Excel" hatası', str_contains(ham_oku($xl, 'l.xlsx')['hata'] ?? '', 'Geçerli bir Excel'));
$zm = new ZipArchive(); $zmYol = "$XDIR/makro.xlsx"; $zm->open($zmYol, ZipArchive::CREATE); $zm->addFromString('[Content_Types].xml', '<x/>'); $zm->addFromString('xl/workbook.xml', '<x/>'); $zm->addFromString('xl/vbaProject.bin', 'MZ'); $zm->close();
ok('makro (xl/vbaProject.bin) içeren zip REDDEDİLİR', str_contains(ham_oku($zmYol, 'makro.xlsx')['hata'] ?? '', 'Makro'));
$zd = new ZipArchive(); $zdYol = "$XDIR/dis.xlsx"; $zd->open($zdYol, ZipArchive::CREATE); $zd->addFromString('[Content_Types].xml', '<x/>'); $zd->addFromString('xl/workbook.xml', '<x/>'); $zd->addFromString('xl/externalLinks/externalLink1.xml', '<x/>'); $zd->close();
ok('dış bağlantı (xl/externalLinks/) içeren zip REDDEDİLİR', str_contains(ham_oku($zdYol, 'dis.xlsx')['hata'] ?? '', 'dış bağlantı'));
$ze = new ZipArchive(); $zeYol = "$XDIR/gom.xlsx"; $ze->open($zeYol, ZipArchive::CREATE); $ze->addFromString('[Content_Types].xml', '<x/>'); $ze->addFromString('xl/workbook.xml', '<x/>'); $ze->addFromString('xl/embeddings/oleObject1.bin', 'x'); $ze->close();
ok('gömülü nesne (xl/embeddings/) içeren zip REDDEDİLİR', str_contains(ham_oku($zeYol, 'gom.xlsx')['hata'] ?? '', 'gömülü'));
$zb = new ZipArchive(); $zbYol = "$XDIR/bomba.xlsx"; $zb->open($zbYol, ZipArchive::CREATE); $zb->addFromString('[Content_Types].xml', '<x/>'); $zb->addFromString('xl/workbook.xml', '<x/>'); $zb->addFromString('xl/worksheets/sheet1.xml', str_repeat('A', 21 * 1048576)); $zb->close();
ok('zip bombası / dev parça (21 MB açılmış, küçük sıkıştırılmış) REDDEDİLİR', (bool)(ham_oku($zbYol, 'bomba.xlsx')['hata'] ?? '') && (filesize($zbYol) < 200000), (string)filesize($zbYol));
$zt = new ZipArchive(); $ztYol = "$XDIR/yol.xlsx"; $zt->open($ztYol, ZipArchive::CREATE); $zt->addFromString('[Content_Types].xml', '<x/>'); $zt->addFromString('xl/workbook.xml', '<x/>'); $zt->addFromString('xl/../../evil.txt', 'x'); $zt->close();
ok("zip yol atlatma ('..') REDDEDİLİR", (bool)(ham_oku($ztYol, 'yol.xlsx')['hata'] ?? ''));
ok('uzantı: .xls / .xlsm / .txt / .php / uzantısız REDDEDİLİR; .csv ve .xlsx kabul', (function () use ($xs, $XDIR) {
    foreach (['a.xls', 'a.xlsm', 'a.txt', 'a.php', 'a', 'a.xlsx.php'] as $ad) if (!str_contains(ham_oku($xs, $ad)['hata'] ?? '', 'Yalnız .xlsx ya da .csv')) return false;
    return !isset(ham_oku($xs, 'A.XLSX')['hata']);
})());
ok('boş dosya REDDEDİLİR; yükleme hatası kodları (INI_SIZE → "çok büyük", NO_FILE → "seçilmedi") Türkçe mesaj', str_contains(ham_oku(pg_dosya_yaz("$XDIR/bos.csv", ''), 'bos.csv')['hata'] ?? '', 'boş') && str_contains(pge_dosya_dogrula(['error' => UPLOAD_ERR_INI_SIZE])['hata'], 'çok büyük') && str_contains(pge_dosya_dogrula(['error' => UPLOAD_ERR_NO_FILE])['hata'], 'seçilmedi'));
ok("gerçek HTTP yüklemesi değilse (is_uploaded_file) REDDEDİLİR — kanca yokken tmp dosya kabul edilmez", str_contains(pge_dosya_dogrula(['name' => 'a.csv', 'tmp_name' => $xs, 'error' => 0])['hata'] ?? '', 'yükleme isteğiyle'));
$buyukCsv = pg_dosya_yaz("$XDIR/buyuk.csv", "Ad\n" . str_repeat("Örnek Kişi X\n", 160000));
ok('2 MB üstü dosya REDDEDİLİR (CSV 2,0+ MB)', filesize($buyukCsv) > 2 * 1048576 && str_contains(ham_oku($buyukCsv, 'buyuk.csv')['hata'] ?? '', 'çok büyük'), (string)filesize($buyukCsv));
$ikili = pg_dosya_yaz("$XDIR/ikili.csv", "Ad\0Soyad\n");
ok('.csv uzantılı ikili dosya (NUL) ve zip (PK) REDDEDİLİR', str_contains(ham_oku($ikili, 'ikili.csv')['hata'] ?? '', 'CSV değil') && str_contains(ham_oku($xs, 'sahte.csv')['hata'] ?? '', 'CSV değil'));
$sat501 = [['Ad Soyad']]; for ($i = 1; $i <= 501; $i++) $sat501[] = ['Örnek Kişi N' . $i];
$x501 = pg_xlsx_yaz("$XDIR/501.xlsx", $sat501);
$h501 = ham_oku($x501, '501.xlsx');
ok('xlsx 501 veri satırı → ayrıştırıcı "en çok 500 satır" der', isset(pge_satirlari_coz($h501, [])['hata']) && str_contains(pge_satirlari_coz($h501, [])['hata'], '500'));
$x500 = pg_xlsx_yaz("$XDIR/500.xlsx", array_slice($sat501, 0, 501));
ok('xlsx 500 veri satırı + başlık KABUL (500 geçerli)', (pge_satirlari_coz(ham_oku($x500, '500.xlsx'), [])['ozet']['gecerli'] ?? 0) === 500);
ok('xlsx okuma: yalnız ilk 10 sütun (11. sütundaki değer gelmez)', (function () use ($XDIR) { $y = pg_xlsx_yaz("$XDIR/genis.xlsx", [['Ad Soyad', 'Görev', null, null, null, null, null, null, null, null, 'ON BİRİNCİ']]); $h = ham_oku($y, 'genis.xlsx'); return count($h[0]) === 10 && !in_array('ON BİRİNCİ', $h[0], true); })());

echo "\n=== 6h. Excel'den ekle: yazma ucu (excel_onizle / excel_ekle; uçtan uca) ===\n";
function giris_dosya_post(string $eylem, ?string $yol, string $ad, array $opt = [], array $post = []): array
{
    $opt = array_merge($opt, ['tmp_say' => true]);
    if ($yol !== null) $opt['files'] = ['dosya' => ['name' => $ad, 'tmp_name' => $yol]];
    $r = giris_post(['csrf' => 'testcsrf', 'action' => $eylem] + $post, $opt);
    $r['tmp_yeni'] = preg_match('/TMP_YENI: (.*)/', $r['err'], $m) ? json_decode($m[1], true) : null;
    return $r;
}
$satirlarE = [
    ['Ad Soyad', 'Görev'],
    ['Örnek Kişi Ş', 'Paketçi'],           // geçerli
    ['ÖRNEK KİŞİ Ş', 'tekrar'],            // dosyada tekrar (TR duyarsız)
    ['örnek kişi a', 'x'],                 // mevcut (1: Depo A) → mükerrer
    ['Örnek Kişi D', ''],                  // mevcut PASİF → mükerrer
    ['Örnek Kişi C', 'Başka depoda'],      // Depo B kişisi GÖRÜNMEZ → bu depo için GEÇERLİ
    [null, 'adı yok'],                     // boş
    [str_repeat('ş', 121), ''],            // çok uzun ad
    ['Örnek Kişi Ç', str_repeat('g', 81)], // çok uzun görev
    ['=HYPERLINK("http://x","t")', '+görev'], // formül gibi METİN → geçerli
    ['  Örnek   Kişi   Ü  ', 'Şoför'],      // sadeleşir → geçerli
];
$xe = pg_xlsx_yaz("$XDIR/e.xlsx", $satirlarE);
$on = giris_dosya_post('excel_onizle', $xe, 'e.xlsx');
$jo = $on['json'] ?? [];
ok("excel_onizle: ok + tur xlsx; özet toplam 10 · geçerli 4 (Ş, Kişi C [başka depo], formül metni, Ü) · boş 1 · uzun 2 · mükerrer 3 (mevcut 2 + dosya 1); başlık bulundu", ($jo['ok'] ?? false) === true && $jo['tur'] === 'xlsx' && $jo['ozet'] === ['toplam' => 10, 'gecerli' => 4, 'bos' => 1, 'uzun' => 2, 'mukerrer' => 3, 'mukerrer_mevcut' => 2, 'mukerrer_dosya' => 1] && $jo['baslik_bulundu'] === true, $on['out']);
ok('excel_onizle: ilk 20 satır (burada 10) durumlarıyla; ham metin (formül metni, "+görev") olduğu gibi JSON\'da — HTML kaçışı ekranda (h()/textContent)', count($jo['ilk']) === 10 && $jo['ilk'][0]['durum'] === 'gecerli' && $jo['ilk'][1]['durum'] === 'mukerrer_dosya' && $jo['ilk'][2]['durum'] === 'mukerrer_mevcut' && $jo['ilk'][3]['durum'] === 'mukerrer_mevcut' && $jo['ilk'][4]['durum'] === 'gecerli' && $jo['ilk'][5]['durum'] === 'bos' && $jo['ilk'][6]['durum'] === 'uzun' && $jo['ilk'][7]['durum'] === 'uzun' && $jo['ilk'][8]['ad'] === '=HYPERLINK("http://x","t")' && $jo['ilk'][9]['ad'] === 'Örnek Kişi Ü');
ok('excel_onizle KAYDETMEZ: kişi sayısı 5 (değişmedi), gün kayıtları değişmedi, AUDIT YOK', count($on['kisiler']) === 5 && count($on['gunler']) === count($GB['giris_gunler']) && !str_contains($on['err'], 'AUDIT:'));
ok('DİSKE KAYDETMEZ: istek boyunca sistem geçici klasöründe YENİ dosya oluşmadı (excel_onizle)', $on['tmp_yeni'] === [], json_encode($on['tmp_yeni']));
$ek = giris_dosya_post('excel_ekle', $xe, 'e.xlsx');
$je = $ek['json'] ?? [];
$yeniAdlar = array_slice(array_column($ek['kisiler'], 'ad'), 5);
ok('excel_ekle: yalnız GEÇERLİ 4 satır eklenir (eklenen 4, atlanan 6); yeni kişiler aktif depo "Depo A" ile damgalı, aktif=1; ad sadeleştirilmiş; formül metni DÜZ METİN olarak saklanır', ($je['ok'] ?? false) === true && $je['eklenen'] === 4 && $je['atlanan'] === 6 && $yeniAdlar === ['Örnek Kişi Ş', 'Örnek Kişi C', '=HYPERLINK("http://x","t")', 'Örnek Kişi Ü'] && array_unique(array_column(array_slice($ek['kisiler'], 5), 'depo')) === ['Depo A'] && array_unique(array_column(array_slice($ek['kisiler'], 5), 'aktif')) === [1] && array_column($ek['kisiler'], 'gorev')[5] === 'Paketçi' && array_column($ek['kisiler'], 'gorev')[7] === '+görev', $ek['out'] . json_encode(array_slice($ek['kisiler'], 5), JSON_UNESCAPED_UNICODE));
ok("excel_ekle audit: TEK özet {mod, tur, satir, eklenen, atlanan, depo}; kişi adı / görev YOK", substr_count($ek['err'], 'AUDIT:') === 1 && str_contains($ek['err'], 'AUDIT: ["create","puantaj_giris",null,null,{"mod":"excel_ekle","tur":"xlsx","satir":10,"eklenen":4,"atlanan":6,"depo":"Depo A"}]') && !preg_match('/Örnek|HYPERLINK|Paketçi/u', aud($ek['err'])), $ek['err']);
ok('DİSKE KAYDETMEZ: excel_ekle sonrası da geçici klasörde yeni dosya YOK', $ek['tmp_yeni'] === [], json_encode($ek['tmp_yeni']));
ok('excel_ekle employees / PDKS tablolarına dokunmaz', $ek['sayilar'] === giris_post(['csrf' => 'testcsrf', 'action' => 'sil'])['sayilar']);
$ekb = giris_dosya_post('excel_ekle', $xe, 'e.xlsx', ['giris_kisiler' => [[1, 'Örnek Kişi Ş', '', 'Depo A', 1]], 'giris_gunler' => []]);
ok("excel_ekle: dosyadaki bir ad zaten varsa (Örnek Kişi Ş mevcut) o satırlar EKLENMEZ (2 mükerrer); kalan 5 geçerli eklenir", ($ekb['json']['ok'] ?? false) === true && $ekb['json']['eklenen'] === 5 && $ekb['json']['atlanan'] === 5 && count($ekb['kisiler']) === 6, $ekb['out']);
$xgecersiz = pg_xlsx_yaz("$XDIR/g.xlsx", [['Ad Soyad'], ['Örnek Kişi A'], [null], ['Örnek Kişi B']]);
$eg = giris_dosya_post('excel_ekle', $xgecersiz, 'g.xlsx');
ok('excel_ekle: geçerli satır yoksa (hepsi mevcutla mükerrer) → 400 "Eklenecek geçerli satır yok", yazılmadı, audit YOK', ($eg['json']['ok'] ?? null) === false && str_contains((string)($eg['json']['error'] ?? ''), 'geçerli satır yok') && count($eg['kisiler']) === 5 && !str_contains($eg['err'], 'AUDIT:'), $eg['out']);

$csvP = pg_dosya_yaz("$XDIR/k.csv", "\xEF\xBB\xBFAd Soyad;Görev\r\nÖrnek Kişi Ş;Paketçi\r\n\"Örnek; Kişi Ç\";\"iki\nsatır\"\r\nörnek kişi a;x\r\n");
$cs = giris_dosya_post('excel_onizle', $csvP, 'k.csv');
ok('CSV önizleme: tur csv; BOM + ; ayraç + tırnaklı alan; toplam 3 · geçerli 2 · mükerrer 1 (mevcut)', ($cs['json']['ok'] ?? false) === true && $cs['json']['tur'] === 'csv' && $cs['json']['ozet']['toplam'] === 3 && $cs['json']['ozet']['gecerli'] === 2 && $cs['json']['ozet']['mukerrer_mevcut'] === 1 && $cs['json']['ilk'][1]['ad'] === 'Örnek; Kişi Ç' && $cs['json']['ilk'][1]['gorev'] === 'iki satır' && $cs['tmp_yeni'] === [], $cs['out']);
$csk = giris_dosya_post('excel_ekle', pg_dosya_yaz("$XDIR/k2.csv", mb_convert_encoding("Ad,Görev\nÖrnek Kişi İ,Şoför\nÖrnek Kişi Ö,\n", 'Windows-1254', 'UTF-8')), 'k2.csv');
ok('CSV ekleme: Windows-1254 + virgül ayraç; TR karakterler doğru kaydedilir (İ, Ö, Şoför)', ($csk['json']['ok'] ?? false) === true && $csk['json']['eklenen'] === 2 && array_slice(array_column($csk['kisiler'], 'ad'), 5) === ['Örnek Kişi İ', 'Örnek Kişi Ö'] && array_column($csk['kisiler'], 'gorev')[5] === 'Şoför', $csk['out']);
$csb = giris_dosya_post('excel_ekle', pg_dosya_yaz("$XDIR/k3.csv", "Örnek Kişi Ğ;Depocu\nÖrnek Kişi Ü\n"), 'k3.csv');
ok('CSV: başlık satırı YOK → ilk sütun ad, ikinci sütun görev; 2 kişi eklenir', ($csb['json']['ok'] ?? false) === true && $csb['json']['eklenen'] === 2 && array_slice(array_column($csb['kisiler'], 'ad'), 5) === ['Örnek Kişi Ğ', 'Örnek Kişi Ü']);
$sat500 = []; for ($i = 1; $i <= 500; $i++) $sat500[] = 'Örnek Kişi N' . $i;
$c500 = giris_dosya_post('excel_ekle', pg_dosya_yaz("$XDIR/500.csv", "Ad\n" . implode("\n", $sat500) . "\n"), '500.csv');
ok('CSV 500 satır: tamamı eklenir (500 kişi, tek transaction; audit tek özet)', ($c500['json']['ok'] ?? false) === true && $c500['json']['eklenen'] === 500 && count($c500['kisiler']) === 505 && substr_count($c500['err'], 'AUDIT:') === 1, substr($c500['out'], 0, 200));
$c501 = giris_dosya_post('excel_ekle', pg_dosya_yaz("$XDIR/501.csv", "Ad\n" . implode("\n", array_merge($sat500, ['Örnek Kişi N501'])) . "\n"), '501.csv');
ok('CSV 501 satır → REDDEDİLİR (400, hiçbiri eklenmez, audit yok)', ($c501['json']['ok'] ?? null) === false && str_contains((string)($c501['json']['error'] ?? ''), '500') && count($c501['kisiler']) === 5 && !str_contains($c501['err'], 'AUDIT:'));
$cb = giris_dosya_post('excel_onizle', $buyukCsv, 'buyuk.csv');
ok('2 MB üstü dosya uçta REDDEDİLİR (400)', ($cb['json']['ok'] ?? null) === false && str_contains((string)($cb['json']['error'] ?? ''), 'çok büyük'));
ok('makro/ikili/uzantı hataları uçta 400 JSON (yazılmadı)', ($tmp1 = giris_dosya_post('excel_ekle', $zmYol, 'makro.xlsx'))['json']['ok'] === false && count($tmp1['kisiler']) === 5 && (giris_dosya_post('excel_ekle', $xs, 'a.xls')['json']['ok'] ?? null) === false && (giris_dosya_post('excel_ekle', $ikili, 'ikili.csv')['json']['ok'] ?? null) === false);
$bosDosya = pg_xlsx_yaz("$XDIR/bosx.xlsx", [['Ad Soyad', 'Görev']]);
ok("başlıktan başka satır yok → 400 'Dosyada kişi satırı bulunamadı'", str_contains((string)(giris_dosya_post('excel_onizle', $bosDosya, 'bosx.xlsx')['json']['error'] ?? ''), 'kişi satırı bulunamadı'));
$nf = giris_dosya_post('excel_onizle', null, '');
ok('dosya alanı YOK → 400 "Dosya seçilmedi"', ($nf['json']['ok'] ?? null) === false && str_contains((string)($nf['json']['error'] ?? ''), 'Dosya seçilmedi'));
foreach (['excel_onizle', 'excel_ekle'] as $eylem) {
    $r0 = giris_post(['action' => $eylem], ['admin' => true, 'files' => ['dosya' => ['name' => 'e.xlsx', 'tmp_name' => $xe]]]);
    ok("$eylem: CSRF YOK → 403 + JSON (rc 5), DB yazılmadı, audit YOK", $r0['rc'] === 5 && ($r0['json']['code'] ?? 0) === 403 && count($r0['kisiler']) === 5 && !str_contains($r0['err'], 'AUDIT:'));
    ok("$eylem: yalnız 'read' / ESKİ rol → 403 (rota kapısı)", giris_dosya_post($eylem, $xe, 'e.xlsx', ['admin' => false, 'perms' => $salt])['rc'] === 3 && giris_dosya_post($eylem, $xe, 'e.xlsx', ['admin' => false, 'perms' => $eskiR])['rc'] === 3);
    ok("$eylem: aktif depo yok → 400; tablolar kurulmamış → 503", (giris_dosya_post($eylem, $xe, 'e.xlsx', ['depo' => ''])['json']['ok'] ?? null) === false && str_contains((string)(giris_dosya_post($eylem, $xe, 'e.xlsx', ['giris' => false])['json']['error'] ?? ''), 'migrate.php'));
    ok("$eylem: yalnız sayfa read+edit rolü çalıştırabilir", (giris_dosya_post($eylem, $xe, 'e.xlsx', ['admin' => false, 'perms' => $sadEd])['json']['ok'] ?? false) === true);
}
$pkB = giris_dosya_post('excel_ekle', $xe, 'e.xlsx', ['depo' => 'Depo B', 'allowed' => ['Depo B'], 'giris_kisiler' => [[3, 'Örnek Kişi C', '', 'Depo B', 1]], 'giris_gunler' => []]);
ok("excel_ekle: AKTİF DEPO Depo B iken yeni kişiler Depo B ile damgalanır; Depo B'deki mevcut 'Örnek Kişi C' mükerrer sayılır (eklenen 5)", ($pkB['json']['ok'] ?? false) === true && $pkB['json']['eklenen'] === 5 && array_unique(array_column(array_slice($pkB['kisiler'], 1), 'depo')) === ['Depo B'], $pkB['out']);
ok("giriş kapısı: yazma ucundaki Excel eylemleri GET ile çağrılamaz (405 JSON)", str_contains(sayfa_calistir('puantaj_giris_kaydet.php', [], array_merge($GB, ['admin' => true]))['out'], 'Geçersiz istek'));

echo "\n----------------------------------------------\n";
echo "Geçen: $gecen   Kalan: $fail\n";
exit($fail > 0 ? 1 : 0);
