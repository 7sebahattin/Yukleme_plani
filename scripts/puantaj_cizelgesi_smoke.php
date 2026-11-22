<?php
// =========================================================
// scripts/puantaj_cizelgesi_smoke.php — Aylık Puantaj Çizelgesi (v552) testi
//
// SADECE CLI. Canlı DB'ye dokunmaz (bellek içi SQLite). Bölümler:
//   1) statik kapılar (yetki, GET-only, rota, nav, sw.js, hub kartı)
//   2) saf hesap (ay/gün, giriş-çıkış eşleme, pivot, özet, arama, dışa aktarım satırları, XLSX geri okuma)
//   3) gerçek sayfa çıktısı (scripts/_puantaj_cizelgesi_harness.php alt süreci): depo/pasif, sekmeler,
//      yetki matrisi, CSV/XLSX + audit, yazdırma, şema yokken nazik hata
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
ok('sayfa: GET-only — POST/CSRF/<form method=post> YOK', !preg_match('/\$_POST|csrf_(check|token)|method="post"/i', $sayfa . $yaz . $lib));
ok('sayfa: kayıt yazan SQL YOK (INSERT/UPDATE/DELETE/ALTER/CREATE)', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\b/', $lib . $sayfa . $yaz));
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
ok("matris: 'monthly_timesheet' satırı (read-only, legacy daily_reports+employees)", isset($pages['monthly_timesheet']) && $pages['monthly_timesheet']['actions'] === ['read'] && $pages['monthly_timesheet']['legacy'] === ['daily_reports', 'employees']);
ok('matris: iki rota kayıtlı (kapı UYGULANSIN)', ($routes['puantaj_cizelgesi.php'] ?? '') === 'monthly_timesheet' && ($routes['puantaj_cizelgesi_yazdir.php'] ?? '') === 'monthly_timesheet');
ok('matris: GET = read, POST = reddedilir', role_pdks_request_action('puantaj_cizelgesi.php', 'GET') === ['monthly_timesheet', 'read'] && role_pdks_request_action('puantaj_cizelgesi.php', 'POST', [], ['action' => 'x']) === null);
ok('matris: katalog anahtarı attendance.monthly_timesheet.read', isset(role_pdks_catalog()['attendance.monthly_timesheet.read']));
$e1 = role_pdks_expand_permissions(['attendance.daily_reports']);
$e2 = role_pdks_expand_permissions(['attendance.employees']);
$e3 = role_pdks_expand_permissions(['attendance.scan']);
ok('eski rol: daily_reports → sayfa Gör', in_array('attendance.monthly_timesheet.read', $e1, true));
ok('eski rol: employees → sayfa Gör', in_array('attendance.monthly_timesheet.read', $e2, true));
ok('eski rol: yalnız scan → sayfa YOK', !in_array('attendance.monthly_timesheet.read', $e3, true));
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
ok('C: kayıtsız satır özetleri 0', $C['hucre'] === [] && $C['ozet'] === ['gun' => 0, 'toplam' => 0.0, 'eksik' => 0]);
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
ok('başlık: 2 + 30 gün + 3 özet = 35; "3 Sal" vb.', count($bas) === 35 && $bas[2] === '1 Paz' && $bas[4] === '3 Sal' && $bas[32] === 'Çalışılan Gün' && $bas[33] === 'Toplam Saat' && $bas[34] === 'Eksik Çıkış');
ok('satırlar: 3 kişi + "O gün çalışan" + GENEL TOPLAM; hepsi 35 sütun', count($sat) === 5 && array_reduce($sat, fn($c, $r) => $c && count($r) === 35, true) && $sat[3][0] === 'O gün çalışan' && str_starts_with($sat[4][0], 'GENEL TOPLAM (3 kişi)'));
ok('A satırı: 3 Kasım 9,5 · 5 Kasım "!" · boş gün null · özet 2 / 17,5 / 2', $sat[0][4] === 9.5 && $sat[0][6] === '!' && $sat[0][3] === null && array_slice($sat[0], 32) === [2, 17.5, 2]);
ok('o gün çalışan satırı: 3 Kasım = 2', $sat[3][4] === 2);
$sh = pc_xlsx_sayfa($t, 'Açıklama');
ok('xlsx sayfa tanımı: 35 sütun, gün sütunları tip=sayi, toplam=false', count($sh['sutunlar']) === 35 && $sh['sutunlar'][5]['tip'] === 'sayi' && $sh['toplam'] === false && str_contains($sh['baslik'], 'Kasım 2026'));
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
ok('hücre sayısı: 400 kişi × 35 sütun (14.000) XLSX_MAX_HUCRE (150.000) altında', (count(pc_disa_satirlar($limit)) + 2) * 35 < 150000);
$gun = pc_tablo_gunluk_kur('2026-11', $rapor);
ok('günlük dışa aktarım: başlık Çavuş / Toplam İşçi-Gün; hücre = işçi sayısı (eksikte de sayı)', pc_disa_baslik($gun)[0] === 'Çavuş' && pc_disa_baslik($gun)[33] === 'Toplam İşçi-Gün' && pc_disa_satirlar($gun)[1][4] === 14);

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
ok('mobil liste + ızgara ikisi de basılı; kişi başına details + 7 sütunlu takvim', substr_count($p['out'], '<details class="pc-kisi">') === 4 && substr_count($p['out'], 'class="pc-takvim"') === 4 && str_contains($p['out'], 'class="table-wrap pc-grid-wrap"'));
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
ok('CSV: BOM + ";" ayraç + başlık (Ad Soyad;Sicil / Departman;1 …)', substr($p['out'], 0, 3) === "\xEF\xBB\xBF" && $ilk[0] === 'Ad Soyad' && $ilk[1] === 'Sicil / Departman' && count($ilk) === 2 + (int)date('t') + 3, json_encode($ilk, JSON_UNESCAPED_UNICODE));
$a1 = null; foreach ($satirlar as $s) { $c = str_getcsv($s, ';', '"', '\\'); if ($c[0] === 'Örnek Personel A') $a1 = $c; }
ok('CSV: A satırı — 3. gün "9,50", 5. gün "!", özet 3 gün · 25,50 · 1 eksik', $a1 !== null && $a1[4] === '9,50' && $a1[6] === '!' && array_slice($a1, -3) === ['3', '25,50', '1'], json_encode($a1, JSON_UNESCAPED_UNICODE));
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

echo "\n----------------------------------------------\n";
echo "Geçen: $gecen   Kalan: $fail\n";
exit($fail > 0 ? 1 : 0);
