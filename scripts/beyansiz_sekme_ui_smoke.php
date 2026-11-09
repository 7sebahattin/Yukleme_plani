<?php
// =========================================================
// scripts/beyansiz_sekme_ui_smoke.php — Beyanlar sekmeleri + Beyansız Yüklemeler (v498)
//
// SADECE CLI, bellek içi SQLite. beyanlar.php GERÇEKTEN render edilir.
//   php scripts/beyansiz_sekme_ui_smoke.php   → çıkış 0 = hepsi geçti
//
// Doğrular: yalnız-yukleme_bildir kullanıcı beyan sorguları ÇALIŞMADAN Beyansız
// sekmesini görür (customs_declarations tablosu yokken bile) ve "Yükleme Bildir"
// düğmesi/modalı vardır; yalnız beyan.read (hks.read yok) sekme/düğme görmez;
// karşı kişi adı gizleme; hks.access notu; durum rozetleri; arama ve kaçışlar.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/role_permission_matrix.php';
// Migration gurultusunu (SQLite'ta SHOW COLUMNS calismaz) log dosyasina yonlendir.
$LOG = sys_get_temp_dir() . '/beyansiz_sekme_ui_smoke.log';
ini_set('log_errors', '1');
ini_set('error_log', $LOG);

// ── SQLite + auth stub'ları (helpers.php'den ÖNCE tanımlanmalı) ───────────
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['beyan.read', 'beyan.write', 'beyan.delete', 'records.write', 'maliyet.read'];
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test']; }
function can(string $p): bool { global $PERMS; return in_array($p, role_matrix_expand_permissions(beyan_expand_role_permissions($PERMS)), true); }
function is_admin(): bool { return false; }
function require_login(): array { return current_user(); }
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function active_depot(): ?string { return 'DEPO'; }
function enforce_active_depot(): void {}
function depot_options(): array { return ['DEPO']; }
function depot_visible_to_user(?string $d): bool { return true; }
function user_allowed_depots(): array { return ['DEPO']; }
function depo_sql_records(string $a = 'r'): array { return ['', []]; }
function depo_sql_records_in(string $a = 'r'): array { return ['', []]; }
function depo_sql_column(string $c): array { return ['', []]; }
function depo_sql_in(string $c): array { return ['', []]; }
function user_primary_role(): ?array { return ['slug' => 'operator', 'label' => 'Operatör']; }

require_once $ROOT . '/config/helpers.php';   // GERÇEK bb_* / beyan_hks_*

// ── Şema ──────────────────────────────────────────────────────────────────
db()->exec("CREATE TABLE customs_declarations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, raw_text TEXT, unmatched_text TEXT,
  declaration_title TEXT, company_name TEXT, company_address TEXT,
  transport_type TEXT, vehicle_plate TEXT, line_type TEXT, party_no TEXT,
  pallet_count INT, product_name TEXT, product_variety TEXT,
  gross_kg REAL, net_kg REAL, crate_count INT, crate_type TEXT,
  exit_depot TEXT, contact_person TEXT, buyer_name TEXT, brand TEXT,
  status TEXT DEFAULT 'beyan_acildi', analysis_note TEXT,
  sample_taken_at TEXT, analysis_result_at TEXT, loading_record_id INT,
  hks_durum TEXT, hks_firma_id TEXT, hks_urun_id TEXT, hks_urun_ad TEXT,
  hks_ulke_id TEXT, hks_ulke_ad TEXT, note TEXT, sample_gross_kg REAL, sample_net_kg REAL, sample_crate_type TEXT, sample_crate_count INTEGER, tractor_plate TEXT, sender_company_name TEXT, driver_name TEXT, driver_phone TEXT,
  created_by INT, updated_by INT, created_at TEXT DEFAULT '2026-07-01 10:00',
  updated_at TEXT, deleted_at TEXT)");
db()->exec("CREATE TABLE beyan_hks_bildirim (
  id INTEGER PRIMARY KEY AUTOINCREMENT, beyan_id INT, hks_firma_id TEXT,
  hks_firma_ad TEXT, taslak_id TEXT, gonderim_id TEXT, durum TEXT,
  urun_id TEXT, urun_ad TEXT, ulke_id TEXT, ulke_ad TEXT, plaka TEXT,
  kg REAL, fiyat REAL, hata_metni TEXT, created_by INT,
  created_at TEXT DEFAULT '2026-07-02 10:00', updated_at TEXT)");
db()->exec("CREATE TABLE hks_eslesme (id INTEGER PRIMARY KEY AUTOINCREMENT,
  tip TEXT, kaynak_norm TEXT, hks_id TEXT, hks_ad TEXT, kullanim INT, updated_at TEXT)");
db()->exec("CREATE TABLE hks_kv (anahtar TEXT PRIMARY KEY, deger TEXT)");
db()->exec("CREATE TABLE hks_firmalar (id TEXT PRIMARY KEY, ad TEXT, vergi_no TEXT)");
db()->exec("CREATE TABLE hks_gonderilenler (id TEXT PRIMARY KEY, zaman TEXT,
  firma_id TEXT, urun_ad TEXT, fiyat REAL)");
db()->exec("CREATE TABLE loading_records (id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT DEFAULT 'yukleme', tarih TEXT, firma TEXT, parti_no TEXT, alici TEXT,
  ulasim TEXT, gumruk TEXT, urun TEXT, durum TEXT, locked_at TEXT,
  urun_sahibi_id INT, on_plaka TEXT, arka_plaka TEXT, gidecek_ulke TEXT)");
db()->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT, type TEXT, is_active INT DEFAULT 1, color TEXT)");
db()->exec("CREATE TABLE cost_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT,
  record_id INT, sheet_no TEXT, sale_unit_price REAL, currency_code TEXT,
  currency_rate REAL, deleted_at TEXT)");
db()->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
  action TEXT, entity TEXT, entity_id INT, old_values TEXT, new_values TEXT,
  user_id INT, created_at TEXT)");

// Katalog önbelleği — gerçek yapısıyla
db()->prepare("INSERT INTO hks_kv (anahtar, deger) VALUES ('listeler_cache', ?)")
   ->execute([json_encode([
        'zaman'   => '2026-07-01T10:00:00+03:00',
        'urunler' => [['id' => '10', 'ad' => 'Kayısı'], ['id' => '11', 'ad' => 'Kiraz']],
        'ulkeler' => [['id' => '20', 'ad' => 'Rusya']],
        'sifatlar' => [['id' => '7', 'ad' => 'İhracat']],
        'bildirimTurleri' => [['id' => '5', 'ad' => 'Satış'], ['id' => '6', 'ad' => 'Satın Alım']],
        'isletmeTurleri' => [['id' => '3', 'ad' => 'Yurt Dışı']],
   ], JSON_UNESCAPED_UNICODE)]);
db()->exec("INSERT INTO hks_firmalar (id,ad,vergi_no) VALUES ('f1','TEST FİRMA','1234567890')");


// Çıktı başlamadan oturumu aç (render_header session_start ister; CLI'da başlık uyarısı çıkmasın).
ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', '');
if (session_status() === PHP_SESSION_NONE) @session_start();
require_once $ROOT . '/config/beyansiz_yukleme.php';
beyansiz_yukleme_migrate(db());
db()->exec("CREATE TABLE hks_taslaklar (id VARCHAR(40) PRIMARY KEY, zaman TEXT, firma_id TEXT, firma_ad TEXT, veri TEXT)");
db()->exec("INSERT INTO hks_taslaklar (id) VALUES ('tA'), ('tB')");

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-74s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : "\n    → " . substr($ipucu, 0, 300));
}
function etiket_dengesi(string $html): array {
    $acik = $kapali = [];
    preg_match_all('/<(\/?)(div|table|tbody|thead|tr|td|th|form|dl|section)\b/i', $html, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) { $t = strtolower($m[2]); if ($m[1] === '/') $kapali[$t] = ($kapali[$t] ?? 0) + 1; else $acik[$t] = ($acik[$t] ?? 0) + 1; }
    $bozuk = [];
    foreach ($acik as $t => $n) if (($kapali[$t] ?? 0) !== $n) $bozuk[] = "$t: $n açık, " . ($kapali[$t] ?? 0) . " kapalı";
    return $bozuk;
}

function bsz_ekle(array $a): void {
    $v = array_merge(['parti_no' => null, 'hks_firma_id' => 'f1', 'hks_firma_ad' => 'TEST FİRMA', 'yon' => 'yurt_disi', 'ulke_ad' => 'Rusya',
        'karsi_kisi_ad' => null, 'urun_id' => '10', 'urun_ad' => 'Kayısı', 'kg' => 19800, 'plaka' => '34ABC123', 'taslak_id' => null,
        'durum' => 'taslak', 'created_at' => '2026-11-01 10:00:00'], $a);
    db()->prepare("INSERT INTO beyansiz_yukleme (parti_no,hks_firma_id,hks_firma_ad,yon,ulke_ad,karsi_kisi_ad,urun_id,urun_ad,kg,plaka,taslak_id,durum,created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$v['parti_no'], $v['hks_firma_id'], $v['hks_firma_ad'], $v['yon'], $v['ulke_ad'], $v['karsi_kisi_ad'], $v['urun_id'], $v['urun_ad'], $v['kg'], $v['plaka'], $v['taslak_id'], $v['durum'], $v['created_at']]);
}
bsz_ekle(['taslak_id' => 'tA', 'plaka' => '34BEKLE1', 'parti_no' => 'P-77']);
bsz_ekle(['taslak_id' => 'tB', 'yon' => 'yurt_ici', 'ulke_ad' => null, 'karsi_kisi_ad' => 'GİZLİ KİŞİ AD', 'plaka' => '06ICI222']);
bsz_ekle(['taslak_id' => 'tG', 'durum' => 'gonderildi', 'plaka' => '16GONDER3']);
bsz_ekle(['taslak_id' => 'tS', 'durum' => 'silindi', 'plaka' => '07SILIN4']);
bsz_ekle(['taslak_id' => 'tOlu', 'plaka' => '35OLU555']);
bsz_ekle(['taslak_id' => 'tXss', 'durum' => 'gonderildi', 'urun_ad' => '<script>alert(1)</script>', 'plaka' => '01XSS999', 'hks_firma_ad' => 'A"B&C']);

// beyanlar.php'yi render eder (render_liste ile aynı yöntem; her çağrıda taze kaynak).
function sayfa(array $get): string {
    global $ROOT;
    static $tmp = null;
    if ($tmp === null) {
        $src = (string)file_get_contents($ROOT . '/beyanlar.php');
        $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
        $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
        $src = str_replace('const BEYAN_PER_PAGE = 50;', "defined('BEYAN_PER_PAGE') || define('BEYAN_PER_PAGE', 50);", $src);
        $src = str_replace("__DIR__ . '/", var_export($ROOT, true) . " . '/", $src);
        $a = strpos($src, 'function valid_date_beyan');
        $b = strpos($src, "defined('BEYAN_PER_PAGE')");
        $src = substr($src, 0, $a) . "if (!function_exists('beyan_url')) {\n" . substr($src, $a, $b - $a) . "\n}\n" . substr($src, $b);
        $tmp = sys_get_temp_dir() . '/beyanlar_sekme_smoke.php';
        file_put_contents($tmp, $src);
    }
    $_GET = $get;
    ob_start();
    try { include $tmp; }
    catch (Throwable $e) { ob_end_clean(); return '__HATA__' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); }
    return (string)ob_get_clean();
}
function temiz(string $h): bool {
    return stripos($h, 'Warning:') === false && stripos($h, 'Notice:') === false && stripos($h, 'Deprecated:') === false && stripos($h, 'Fatal error') === false;
}

// ── 1) Yalnız beyan.yukleme_bildir ───────────────────────────────────────
echo "\n=== 1. yalnız beyan.yukleme_bildir (beyan.read YOK) ===\n";
$PERMS = ['beyan.yukleme_bildir'];
db()->exec("ALTER TABLE customs_declarations RENAME TO cd_gizli");   // beyan sorgusu çalışırsa PDOException → __HATA__
foreach (['' => [], 'açık sekme' => ['sekme' => 'beyansiz'], 'sekme=beyanlar zorlama' => ['sekme' => 'beyanlar']] as $ad => $get) {
    $h = sayfa($get);
    ok("[$ad] render edildi, beyan sorgusu ÇALIŞMADI", strncmp($h, '__HATA__', 8) !== 0 && strlen($h) > 500, substr($h, 0, 300));
    ok("[$ad] Beyansız Yüklemeler sekmesi gösterilir", str_contains($h, 'id="bszBekleyen"') && str_contains($h, 'id="bszYapilan"'));
    ok("[$ad] beyan listesi YOK", !str_contains($h, 'İşlem Bekleyen Beyanlar') && !str_contains($h, 'Kapanan Beyanlar'));
    ok("[$ad] Yükleme Bildir düğmesi + modal VAR", str_contains($h, 'id="bszAc"') && str_contains($h, 'id="bszOverlay"'));
    ok("[$ad] sekme çubuğunda Beyanlar bağlantısı YOK (beyan.read yok)", str_contains($h, 'bsz-sekmeler') && !preg_match('~<a class="bsz-sekme[^"]*" href="beyanlar\.php"~', $h));
    ok("[$ad] PHP uyarısı yok + etiket dengesi", temiz($h) && !etiket_dengesi($h), implode(' | ', etiket_dengesi($h)));
}
$h = sayfa(['sekme' => 'beyansiz']);
ok('bekleyen: 2 satır (canlı taslak) + sayaç', preg_match('~Bildirim Bekleyenler\s*<span class="beyan-blok-sayi">2</span>~u', $h) === 1);
ok('yapılan: gönderildi + taslak silindi + durum belirsiz rozetleri',
   str_contains($h, 'bsz-r-gonderildi') && str_contains($h, 'Taslak silindi') && str_contains($h, 'Durum belirsiz'));
ok('bekliyor + yurt içi satırı "Tamamlanacak" rozeti', str_contains($h, 'Hal Kayıt\'ta bekliyor') && str_contains($h, 'bsz-r-tamamla'));
ok('karşı kişi adı yukleme_bildir yetkisiyle görünür', str_contains($h, 'GİZLİ KİŞİ AD'));
ok('hks.access yok → "yetkili kişiye iletin" notu', str_contains($h, 'yetkili kişiye iletin'));
ok('kullanıcı verisi kaçışlı (XSS yok)', !str_contains($h, '<script>alert(1)</script>') && str_contains($h, '&lt;script&gt;'));
ok('toplu seçim/yazdır YOK', !str_contains($h, 'bb-sec') && !str_contains($h, 'bekleyenYazdirBtn'));
$ara = sayfa(['sekme' => 'beyansiz', 'bs_q' => 'BEKLE1']);
ok('bekleyen arama: yalnız eşleşen satır', str_contains($ara, '34BEKLE1') && !str_contains($ara, '06ICI222') && str_contains($ara, '16GONDER3'));
$ara2 = sayfa(['sekme' => 'beyansiz', 'yp_q' => 'GONDER3']);
ok('yapılan arama bağımsız; bekleyenler etkilenmez', str_contains($ara2, '16GONDER3') && !str_contains($ara2, '07SILIN4') && str_contains($ara2, '34BEKLE1'));
$PERMS = ['beyan.yukleme_bildir', 'hks.access'];
ok('hks.access varsa not YOK', !str_contains(sayfa(['sekme' => 'beyansiz']), 'yetkili kişiye iletin'));
db()->exec("ALTER TABLE cd_gizli RENAME TO customs_declarations");

// ── 2) Yalnız beyan.read (ayrıntılı, hks.read yok) ───────────────────────
echo "\n=== 2. yalnız beyan.read, hks.read yok ===\n";
$PERMS = ['beyan.read', 'beyan.permissions.detailed'];
$h = sayfa(['sekme' => 'beyansiz']);
ok('sekme=beyansiz istenirse Beyanlar sekmesine düşer', str_contains($h, 'İşlem Bekleyen Beyanlar') && !str_contains($h, 'id="bszBekleyen"'));
ok('sekme çubuğu ve Yükleme Bildir YOK', !str_contains($h, 'bsz-sekmeler') && !str_contains($h, 'id="bszAc"') && !str_contains($h, 'bszOverlay'));
ok('PHP uyarısı yok', temiz($h) && !etiket_dengesi($h), implode(' | ', etiket_dengesi($h)));

// ── 3) beyan.read + hks.read (eski rol) ──────────────────────────────────
echo "\n=== 3. beyan.read + hks.read ===\n";
$PERMS = ['beyan.read', 'records.write'];
$h = sayfa(['sekme' => 'beyansiz']);
ok('Beyansız sekmesi görünür; Yükleme Bildir düğmesi YOK', str_contains($h, 'id="bszBekleyen"') && !str_contains($h, 'id="bszAc"'));
ok('karşı kişi adı contact.read ile görünür (eski rolde var)', str_contains($h, 'GİZLİ KİŞİ AD'));
$PERMS = ['beyan.read', 'beyan.permissions.detailed', 'beyan.hks.read'];
$h = sayfa(['sekme' => 'beyansiz']);
ok('contact.read YOK → karşı kişi adı GİZLİ', str_contains($h, 'id="bszBekleyen"') && !str_contains($h, 'GİZLİ KİŞİ AD'));
$h = sayfa([]);
ok('Beyanlar sekmesi çubuğu iki sekme; beyan çıktısı bozulmadı', str_contains($h, 'bsz-sekmeler') && str_contains($h, 'İşlem Bekleyen Beyanlar') && str_contains($h, 'Kapanan Beyanlar'));

// ── v501: Sil düğmesi — yalnız yukleme_sil + YALNIZ Bildirim Bekleyenler ──
echo "\n=== 3b. Sil düğmesi (v501) ===\n";
$PERMS = ['beyan.yukleme_bildir'];
ok('yukleme_sil yok → Sil düğmesi YOK', !str_contains(sayfa(['sekme' => 'beyansiz']), 'data-bsz-sil'));
$PERMS = ['beyan.yukleme_bildir', 'beyan.yukleme_sil'];
$h = sayfa(['sekme' => 'beyansiz']);
$bekB = substr($h, strpos($h, 'id="bszBekleyen"'), strpos($h, 'id="bszYapilan"') - strpos($h, 'id="bszBekleyen"'));
$yapA = strpos($h, 'id="bszYapilan"');
$yapB = substr($h, $yapA, strpos($h, '</section>', $yapA) - $yapA);   // yalnız bölüm (betik dışarıda)
ok('yukleme_sil → Bekleyenler satırlarında Sil düğmesi var (masaüstü+mobil)', substr_count($bekB, 'data-bsz-sil=') >= 4, (string)substr_count($bekB, 'data-bsz-sil='));
ok('Yapılanlarda Sil YALNIZ taslağı silinen (07SILIN4) satırında; Gönderildi/belirsiz/XSS satırında YOK', substr_count($yapB, 'data-bsz-sil=') === 2 && str_contains($yapB, 'data-bsz-ad="07SILIN4"') && !str_contains($yapB, 'data-bsz-ad="16GONDER3"') && !str_contains($yapB, 'data-bsz-ad="35OLU555"') && !str_contains($yapB, 'data-bsz-ad="01XSS999"'), (string)substr_count($yapB, 'data-bsz-sil='));
ok('Sil betiği sayfada (api sil + confirm), satır içi onclick yok', str_contains($h, "api_beyansiz_yukleme.php?action=sil") && !preg_match('/\sonclick=/i', $bekB));
ok('PHP uyarısı yok (v501)', temiz($h) && !etiket_dengesi($h), implode(' | ', etiket_dengesi($h)));

// ── 4) Tablo yok ─────────────────────────────────────────────────────────
echo "\n=== 4. tablo kurulu değil ===\n";
db()->exec("DROP TABLE beyansiz_yukleme");
$PERMS = ['beyan.yukleme_bildir'];
// beyansiz_tablo_var() süreç boyunca önbellekli (doğru) → bu süreçte true kalır; fail-soft liste sorguları boş döner.
$h = sayfa(['sekme' => 'beyansiz']);
ok('tablo düşünce sayfa çökmez', strncmp($h, '__HATA__', 8) !== 0 && temiz($h), substr($h, 0, 200));

echo "\n" . ($fail === 0 ? "TUM TESTLER GECTI\n" : "$fail TEST BASARISIZ\n");
exit($fail === 0 ? 0 : 1);
