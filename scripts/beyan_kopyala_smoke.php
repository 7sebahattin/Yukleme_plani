<?php
// =========================================================
// scripts/beyan_kopyala_smoke.php — Beyan kopyalama + HKS otomatik eşleştirme (v330)
//
// SADECE CLI. Canlı DB'ye dokunmaz: GEÇİCİ DOSYA SQLite + stub'lanmış auth.
//   php scripts/beyan_kopyala_smoke.php   → çıkış kodu 0 = hepsi geçti
//
// Katmanlar:
//  1. Fonksiyonel (alt süreç): beyan_kopyala.php ve api_beyan_bildirim.php?action=eslestir
//     GERÇEKTEN çalıştırılır. header()/exit/http_response_code kaynak kopyasında
//     yakalanır; sonuç JSON olarak ana sürece döner. (Dosya DB ortak.)
//  2. Fonksiyonel (aynı süreç): beyan_hks_otomatik_eslestir, beyan_hks_firma_adlari.
//  3. Render: beyan_view.php, beyanlar.php (+ _beyan_liste.php).
//  4. Statik: JS yönlendirmesi vb.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/beyan_kopyala_smoke.log');

$DB_DOSYA = getenv('KOP_DB') ?: (sys_get_temp_dir() . '/beyan_kopyala_smoke_' . getmypid() . '.sqlite');

// ── Ortak stub'lar (ana süreç + alt süreç) ────────────────────────────────
$PDO_TEST = new PDO('sqlite:' . $DB_DOSYA);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['beyan.read', 'beyan.write', 'beyan.delete', 'records.write', 'maliyet.read'];
$KULLANICI = ['id' => 7, 'username' => 'test', 'display_name' => 'Test'];
function current_user(): ?array { global $KULLANICI; return $KULLANICI; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
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

require_once $ROOT . '/config/helpers.php';

// ══════════════════════════════════════════════════════════════════════════
// ALT SÜREÇ MODU
// ══════════════════════════════════════════════════════════════════════════
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string)file_get_contents($argv[2]), true);
    $PERMS = $cfg['perms'];
    if (array_key_exists('user', $cfg) && $cfg['user'] === false) $KULLANICI = null;
    ob_start();
    session_save_path(sys_get_temp_dir());
    session_start();
    $_SESSION['csrf'] = 'TOKEN-OK';
    $_SERVER['REQUEST_METHOD'] = $cfg['method'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_POST = $cfg['post'] ?? [];
    $_GET  = $cfg['get'] ?? [];
    $GLOBALS['__INPUT'] = $cfg['input'] ?? '';
    $GLOBALS['__HDR'] = [];
    $GLOBALS['__RC'] = null;
    function __hdr(string $h, bool $replace = true, int $code = 0): void { $GLOBALS['__HDR'][] = $h; }
    function __rc(int $c = 0) { if ($c) $GLOBALS['__RC'] = $c; return $c ?: true; }
    register_shutdown_function(function () {
        $out = ob_get_contents();
        @ob_end_clean();
        $son = error_get_last();
        echo "\n@@SONUC@@" . json_encode([
            'headers' => $GLOBALS['__HDR'], 'rc' => $GLOBALS['__RC'],
            'flash' => $_SESSION['flash'] ?? null, 'out' => $out,
            'fatal' => ($son && in_array($son['type'], [E_ERROR, E_PARSE, E_CORE_ERROR], true)) ? $son['message'] : null,
        ], JSON_UNESCAPED_UNICODE);
    });
    $src = (string)file_get_contents($ROOT . '/' . $cfg['script']);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/halkayit\/taslak_lib\.php';.*$/m", '', $src);
    $src = preg_replace('/\bheader\(/', '__hdr(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', '__rc(', $src);
    $src = str_replace("file_get_contents('php://input')", '$GLOBALS[\'__INPUT\']', $src);
    $tmp = sys_get_temp_dir() . '/kop_child_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    try { include $tmp; }
    catch (Throwable $e) { echo '__HATA__' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getLine(); }
    @unlink($tmp);
    exit(0);
}

// ══════════════════════════════════════════════════════════════════════════
// ANA SÜREÇ
// ══════════════════════════════════════════════════════════════════════════
register_shutdown_function(function () use ($DB_DOSYA) { @unlink($DB_DOSYA); });
session_save_path(sys_get_temp_dir());
@session_start();   // çıktıdan ÖNCE: csrf_token() render sırasında oturum açmaya çalışmasın

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
  hks_ulke_id TEXT, hks_ulke_ad TEXT,
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
  user_id INT, action TEXT, module TEXT, record_id INT, old_values TEXT,
  new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

db()->prepare("INSERT INTO hks_kv (anahtar, deger) VALUES ('listeler_cache', ?)")
   ->execute([json_encode([
        'zaman'   => '2026-07-01T10:00:00+03:00',
        'urunler' => [['id' => '10', 'ad' => 'Kayısı'], ['id' => '11', 'ad' => 'Kiraz']],
        'ulkeler' => [['id' => '20', 'ad' => 'Rusya'], ['id' => '21', 'ad' => 'Almanya']],
        'sifatlar' => [['id' => '7', 'ad' => 'İhracat']],
        'bildirimTurleri' => [['id' => '5', 'ad' => 'Satış'], ['id' => '6', 'ad' => 'Satın Alım']],
        'isletmeTurleri' => [['id' => '3', 'ad' => 'Yurt Dışı']],
   ], JSON_UNESCAPED_UNICODE)]);
db()->exec("INSERT INTO hks_firmalar (id,ad,vergi_no) VALUES ('f1','TEST FİRMA','1234567890')");

$fail = 0; $toplam = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $toplam;
    $toplam++;
    if (!$c) $fail++;
    printf("%-70s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : "\n    → " . $ipucu);
}

function beyan_ekle(array $a = []): int {
    $v = array_merge([
        'party_no' => '13-41', 'product_name' => 'KAYISI', 'product_variety' => 'PRICIA',
        'net_kg' => 19800, 'gross_kg' => 21000, 'pallet_count' => 26, 'crate_count' => 1300,
        'crate_type' => 'PLASTİK', 'status' => 'beyan_acildi', 'vehicle_plate' => '34ABC123',
        'buyer_name' => 'ALICI A.Ş.', 'brand' => 'ASYA', 'exit_depot' => 'DEPO',
        'transport_type' => 'KARAYOLU', 'line_type' => 'HAT-1',
        'company_name' => 'LLC ZAPADNYE', 'company_address' => 'RUSSIA, 108811, MOSKVA',
        'contact_person' => 'KİŞİ', 'raw_text' => 'HAM METİN', 'unmatched_text' => 'EŞLEŞMEYEN',
        'analysis_note' => 'NOT', 'sample_taken_at' => '2026-07-01', 'analysis_result_at' => '2026-07-03',
        'loading_record_id' => 99, 'hks_durum' => 'gonderildi',
        'hks_firma_id' => null, 'hks_urun_id' => null, 'hks_urun_ad' => null,
        'hks_ulke_id' => null, 'hks_ulke_ad' => null, 'deleted_at' => null,
    ], $a);
    $kol = array_keys($v);
    $st = db()->prepare('INSERT INTO customs_declarations (' . implode(',', $kol) . ') VALUES ('
        . implode(',', array_fill(0, count($kol), '?')) . ')');
    $st->execute(array_values($v));
    return (int)db()->lastInsertId();
}
function satir(int $id): array {
    $st = db()->prepare('SELECT * FROM customs_declarations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: [];
}
function say(): int { return (int)db()->query('SELECT COUNT(*) FROM customs_declarations')->fetchColumn(); }
function audit_say(string $a): int {
    $st = db()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?');
    $st->execute([$a]);
    return (int)$st->fetchColumn();
}

// Alt süreçte betik çalıştır.
function calistir(array $cfg): array {
    global $ROOT, $DB_DOSYA, $PERMS;
    $cfg += ['perms' => $PERMS, 'method' => 'POST', 'post' => [], 'get' => []];
    $f = sys_get_temp_dir() . '/kop_cfg_' . getmypid() . '.json';
    file_put_contents($f, json_encode($cfg, JSON_UNESCAPED_UNICODE));
    putenv('KOP_DB=' . $DB_DOSYA);
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($f) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    @unlink($f);
    $p = strrpos($out, '@@SONUC@@');
    if ($p === false) return ['headers' => [], 'flash' => null, 'out' => $out, 'fatal' => 'sonuç yok: ' . substr($out, 0, 400), 'rc' => null];
    $j = json_decode(substr($out, $p + 9), true);
    return is_array($j) ? $j : ['headers' => [], 'flash' => null, 'out' => $out, 'fatal' => 'json çözülemedi', 'rc' => null];
}
function kopyala(array $post, array $o = []): array {
    return calistir($o + ['script' => 'beyan_kopyala.php', 'post' => $post]);
}
function konum(array $r): string {
    foreach ($r['headers'] as $h) if (stripos($h, 'Location:') === 0) return trim(substr($h, 9));
    return '';
}
function hatasiz(array $r): bool {
    return empty($r['fatal']) && strpos((string)$r['out'], '__HATA__') === false
        && stripos((string)$r['out'], 'Warning:') === false && stripos((string)$r['out'], 'Notice:') === false
        && stripos((string)$r['out'], 'Deprecated:') === false && stripos((string)$r['out'], 'Fatal error') === false;
}

// ══ A) beyan_kopyala.php ══════════════════════════════════════════════════
echo "── A) beyan_kopyala.php ──\n";
$TAM = ['hks_firma_id' => 'f1', 'hks_urun_id' => '10', 'hks_urun_ad' => 'Kayısı',
        'hks_ulke_id' => '20', 'hks_ulke_ad' => 'Rusya'];
$KAYNAK = beyan_ekle($TAM + ['status' => 'yuklendi']);   // durum ne olursa olsun kopya taslak

$once = say();
$r = kopyala(['id' => (string)$KAYNAK, 'csrf' => 'TOKEN-OK']);
ok('[A] çalıştı (fatal/uyarı yok)', hatasiz($r), (string)($r['fatal'] ?? '') . substr((string)$r['out'], 0, 300));
$yeni = say() - $once === 1 ? (int)db()->query('SELECT MAX(id) FROM customs_declarations')->fetchColumn() : 0;
ok('[A] tam 1 yeni satır oluştu', $yeni > $KAYNAK, 'satır sayısı farkı: ' . (say() - $once));
ok('[A] yönlendirme beyan_edit.php?id=<yeni>', konum($r) === 'beyan_edit.php?id=' . $yeni, 'Location: ' . konum($r));
$k = satir($yeni); $s = satir($KAYNAK);
ok('[A] kopya durumu taslak (kaynak yuklendi iken)', ($k['status'] ?? '') === 'taslak', (string)($k['status'] ?? ''));
foreach (['party_no', 'vehicle_plate', 'raw_text', 'unmatched_text', 'analysis_note', 'sample_taken_at',
          'analysis_result_at', 'loading_record_id', 'hks_durum'] as $kol) {
    ok("[A] KOPYALANMAZ: $kol", ($k[$kol] ?? null) === null || ($k[$kol] ?? '') === '',
       "$kol = " . var_export($k[$kol] ?? null, true));
}
foreach (['product_name', 'product_variety', 'buyer_name', 'brand', 'exit_depot', 'net_kg', 'gross_kg',
          'pallet_count', 'crate_count', 'crate_type', 'company_name', 'company_address', 'transport_type',
          'line_type', 'contact_person', 'hks_firma_id', 'hks_urun_id', 'hks_urun_ad', 'hks_ulke_id', 'hks_ulke_ad'] as $kol) {
    ok("[A] KOPYALANIR: $kol", (string)($k[$kol] ?? '') === (string)($s[$kol] ?? '') && (string)$s[$kol] !== '',
       "$kol kaynak=" . var_export($s[$kol] ?? null, true) . ' kopya=' . var_export($k[$kol] ?? null, true));
}
ok('[A] created_by/updated_by = oturum kullanıcısı', (int)$k['created_by'] === 7 && (int)$k['updated_by'] === 7,
   'created_by=' . var_export($k['created_by'] ?? null, true));
ok('[A] kaynak değişmedi (status yuklendi, party_no duruyor)',
   $s['status'] === 'yuklendi' && $s['party_no'] === '13-41' && $s['vehicle_plate'] === '34ABC123');
ok('[A] audit beyan_kopyala yazıldı', audit_say('beyan_kopyala') === 1, 'adet: ' . audit_say('beyan_kopyala'));
$au = db()->query("SELECT * FROM audit_log WHERE action='beyan_kopyala'")->fetch();
$av = json_decode((string)($au['new_values'] ?? ''), true) ?: [];
ok('[A] audit kaydı kaynak id + yeni kayıt id taşıyor',
   (int)($av['kaynak_id'] ?? 0) === $KAYNAK && (int)($au['record_id'] ?? 0) === $yeni && ($au['module'] ?? '') === 'declarations',
   (string)($au['new_values'] ?? ''));
ok('[A] başarı flash\'ı', ($r['flash']['type'] ?? '') === 'success', json_encode($r['flash']));

// Kopyanın kopyası (zincir) çalışır; kopyadan da taslak üretilir
$r2 = kopyala(['id' => (string)$yeni, 'csrf' => 'TOKEN-OK']);
ok('[A] kopyanın kopyası alınabiliyor', konum($r2) !== '' && strpos(konum($r2), 'beyan_edit.php?id=') === 0 && hatasiz($r2));

// Hata yolları
$once = say(); $audit0 = audit_say('beyan_kopyala');
$r = kopyala(['id' => (string)$KAYNAK, 'csrf' => 'YANLIS']);
ok('[A] yanlış CSRF → kopya YOK', say() === $once, 'satır eklendi');
ok('[A] yanlış CSRF → 403 ya da reddedildi', ($r['rc'] ?? null) === 403 || strpos((string)$r['out'], 'doğrulama') !== false || !empty($r['fatal']) === false && konum($r) === '' ,
   'rc=' . var_export($r['rc'], true) . ' loc=' . konum($r));
ok('[A] yanlış CSRF → audit yok', audit_say('beyan_kopyala') === $audit0);
$r = kopyala(['id' => (string)$KAYNAK]);
ok('[A] CSRF yok → kopya YOK', say() === $once);

$r = kopyala(['id' => (string)$KAYNAK, 'csrf' => 'TOKEN-OK'], ['perms' => ['beyan.read', 'records.write']]);
ok('[A] beyan.write yok → forbidden, kopya YOK', say() === $once && strpos((string)$r['out'], 'forbidden') !== false,
   substr((string)$r['out'], 0, 200));
$r = kopyala(['id' => (string)$KAYNAK, 'csrf' => 'TOKEN-OK'], ['perms' => ['beyan.read']]);
ok('[A] yalnız beyan.read → kopya YOK', say() === $once);

// Silinmiş / olmayan / geçersiz id
$SILINMIS = beyan_ekle($TAM + ['deleted_at' => '2026-07-05 10:00:00']);
$once = say();
$r = kopyala(['id' => (string)$SILINMIS, 'csrf' => 'TOKEN-OK']);
ok('[A] silinmiş beyan → kopya YOK', say() === $once);
ok('[A] silinmiş beyan → error flash + beyanlar.php',
   ($r['flash']['type'] ?? '') === 'error' && konum($r) === 'beyanlar.php', json_encode($r['flash']) . ' ' . konum($r));
foreach (['99999', '0', '-3', 'abc', ''] as $badId) {
    $r = kopyala(['id' => $badId, 'csrf' => 'TOKEN-OK']);
    ok("[A] geçersiz id '$badId' → hata flash'ı, kopya yok",
       say() === $once && ($r['flash']['type'] ?? '') === 'error' && konum($r) === 'beyanlar.php' && hatasiz($r),
       json_encode($r['flash']) . ' ' . konum($r));
}
$r = kopyala([], ['method' => 'GET', 'get' => ['id' => (string)$KAYNAK]]);
ok('[A] GET → beyanlar.php, kopya YOK', konum($r) === 'beyanlar.php' && say() === $once, 'loc=' . konum($r));

// Kayıtsız eşleştirmeli kaynak: NULL hks alanları sorun çıkarmaz
$BOSKAYNAK = beyan_ekle([]);
$r = kopyala(['id' => (string)$BOSKAYNAK, 'csrf' => 'TOKEN-OK']);
$y2 = (int)db()->query('SELECT MAX(id) FROM customs_declarations')->fetchColumn();
ok('[A] eşleştirmesiz kaynak da kopyalanır (NULL hks alanları)', hatasiz($r) && konum($r) === 'beyan_edit.php?id=' . $y2
   && satir($y2)['hks_firma_id'] === null, konum($r));

// ══ B) otomatik eşleştirme fonksiyonları ═════════════════════════════════
echo "── B) beyan_hks_otomatik_eslestir / beyan_hks_firma_adlari ──\n";
$tamBeyan = ['product_name' => 'KAYISI', 'company_address' => 'RUSSIA, 108811, G.MOSKVA', 'buyer_name' => 'ALICI'];

$o = beyan_hks_otomatik_eslestir($tamBeyan);
ok('[B] boş ürün ADdan tahmin edildi (KAYISI → Kayısı/10)', $o['urun_id'] === '10' && $o['urun_ad'] === 'Kayısı', json_encode($o, JSON_UNESCAPED_UNICODE));
ok('[B] boş ülke adres ipucundan (RUSSIA → Rusya/20)', $o['ulke_id'] === '20' && $o['ulke_ad'] === 'Rusya', json_encode($o, JSON_UNESCAPED_UNICODE));
ok('[B] tek firma → otomatik seçilir', $o['firma_id'] === 'f1', (string)$o['firma_id']);
ok('[B] hepsi doldu → eksik boş, degisen 3 alan',
   $o['eksik'] === [] && count($o['degisen']) === 3, json_encode($o, JSON_UNESCAPED_UNICODE));

// Kayıtlı değer ASLA ezilmez
$o = beyan_hks_otomatik_eslestir($tamBeyan + ['hks_firma_id' => 'XX', 'hks_urun_id' => '11', 'hks_urun_ad' => 'Kiraz',
                                              'hks_ulke_id' => '21', 'hks_ulke_ad' => 'Almanya']);
ok('[B] kayıtlı firma/ürün/ülke EZİLMEZ',
   $o['firma_id'] === 'XX' && $o['urun_id'] === '11' && $o['urun_ad'] === 'Kiraz'
   && $o['ulke_id'] === '21' && $o['ulke_ad'] === 'Almanya' && $o['degisen'] === [], json_encode($o, JSON_UNESCAPED_UNICODE));
// Yalnız boş olan doldurulur (kısmi)
$o = beyan_hks_otomatik_eslestir($tamBeyan + ['hks_urun_id' => '11', 'hks_urun_ad' => 'Kiraz']);
ok('[B] kısmi: ürün korunur, ülke+firma doldurulur',
   $o['urun_id'] === '11' && $o['ulke_id'] === '20' && $o['firma_id'] === 'f1' && $o['degisen'] === ['Ülke', 'Firma'],
   json_encode($o, JSON_UNESCAPED_UNICODE));
// Boşluk-yalnız değer = boş sayılır
$o = beyan_hks_otomatik_eslestir($tamBeyan + ['hks_urun_id' => '   ']);
ok('[B] yalnız boşluktan oluşan id boş sayılır', $o['urun_id'] === '10');

// Tahmin başarısız → eksik listesi, id UYDURULMAZ
$o = beyan_hks_otomatik_eslestir(['product_name' => 'BİLİNMEYEN ÜRÜN', 'company_address' => 'ATLANTIS', 'buyer_name' => 'X']);
ok('[B] bulunamayan ürün/ülke → eksik, id boş',
   $o['urun_id'] === '' && $o['ulke_id'] === '' && in_array('HKS ürünü', $o['eksik'], true) && in_array('ülke', $o['eksik'], true),
   json_encode($o, JSON_UNESCAPED_UNICODE));
ok('[B] eksik listesinde firma YOK (tek firma var)', !in_array('HKS firması', $o['eksik'], true));

// Firma: iki firma + son kullanılan yok → eksik; son firma geçerli → o seçilir; geçersiz son → eksik
db()->exec("INSERT INTO hks_firmalar (id,ad,vergi_no) VALUES ('f2','İKİNCİ FİRMA','9876543210')");
$o = beyan_hks_otomatik_eslestir($tamBeyan);
ok('[B] iki firma, son yok → firma tahmin EDİLMEZ, eksik', $o['firma_id'] === '' && in_array('HKS firması', $o['eksik'], true),
   json_encode($o, JSON_UNESCAPED_UNICODE));
ok('[B] firma eksik → degisen listesinde Firma YOK', !in_array('Firma', $o['degisen'], true));
db()->prepare("REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)")->execute([BB_SON_FIRMA_KEY, json_encode('f2')]);
$o = beyan_hks_otomatik_eslestir($tamBeyan);
ok('[B] son kullanılan firma (f2) seçilir', $o['firma_id'] === 'f2' && $o['eksik'] === [], json_encode($o, JSON_UNESCAPED_UNICODE));
db()->prepare("REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)")->execute([BB_SON_FIRMA_KEY, json_encode('YOKFIRMA')]);
$o = beyan_hks_otomatik_eslestir($tamBeyan);
ok('[B] son firma artık firmalar listesinde yoksa seçilmez', $o['firma_id'] === '', (string)$o['firma_id']);
db()->exec("DELETE FROM hks_kv WHERE anahtar = '" . BB_SON_FIRMA_KEY . "'");
db()->exec("DELETE FROM hks_firmalar WHERE id = 'f2'");

// beyan_hks_firma_adlari
db()->exec("INSERT INTO hks_firmalar (id,ad,vergi_no) VALUES ('f3','ÜÇÜNCÜ','1')");
$ad = beyan_hks_firma_adlari(['f1', 'f3', 'f1', '', 'yok']);
ok('[B] firma_adlari id→ad haritası, tekilleştirir, yoksa atlar',
   $ad === ['f1' => 'TEST FİRMA', 'f3' => 'ÜÇÜNCÜ'], json_encode($ad, JSON_UNESCAPED_UNICODE));
ok('[B] firma_adlari([]) boş', beyan_hks_firma_adlari([]) === []);
ok('[B] firma_adlari yalnız boş id\'ler → boş', beyan_hks_firma_adlari(['', '']) === []);
db()->exec("ALTER TABLE hks_firmalar RENAME TO hks_firmalar_x");
$ad = beyan_hks_firma_adlari(['f1']);
db()->exec("ALTER TABLE hks_firmalar_x RENAME TO hks_firmalar");
ok('[B] firma_adlari tablo yoksa boş döner (istisna atmaz)', $ad === [], json_encode($ad));
$ad = beyan_hks_firma_adlari([5, 'f1']);
ok('[B] firma_adlari sayısal id kabul eder (string\'e çevirir)', ($ad['f1'] ?? '') === 'TEST FİRMA');
db()->exec("DELETE FROM hks_firmalar WHERE id = 'f3'");

// Katalog yok → katalogYok, ürün/ülke eksik; firma yine tahmin edilir
$ham = db()->query("SELECT deger FROM hks_kv WHERE anahtar='listeler_cache'")->fetchColumn();
db()->exec("DELETE FROM hks_kv WHERE anahtar='listeler_cache'");
$o = beyan_hks_otomatik_eslestir($tamBeyan);
ok('[B] katalog yok → katalogYok=true, ürün/ülke eksik', $o['katalogYok'] === true && $o['urun_id'] === '' && $o['ulke_id'] === ''
   && in_array('HKS ürünü', $o['eksik'], true) && in_array('ülke', $o['eksik'], true), json_encode($o, JSON_UNESCAPED_UNICODE));
db()->prepare("INSERT INTO hks_kv (anahtar, deger) VALUES ('listeler_cache', ?)")->execute([$ham]);

// ══ C) api_beyan_bildirim.php?action=eslestir ═════════════════════════════
echo "── C) api eslestir ──\n";
function api(array $govde, array $o = []): array {
    $r = calistir($o + ['script' => 'api_beyan_bildirim.php', 'method' => 'POST',
        'get' => ['action' => 'eslestir'], 'input' => json_encode($govde, JSON_UNESCAPED_UNICODE)]);
    $p = strrpos((string)$r['out'], '{');
    $r['json'] = json_decode((string)$r['out'], true);
    if (!is_array($r['json']) && $p !== false) $r['json'] = json_decode(substr((string)$r['out'], $p), true);
    return $r;
}
$ESL = beyan_ekle(['vehicle_plate' => null]);
$a0 = audit_say('beyan_hks_eslestir');
$r = api(['beyan_id' => $ESL, 'csrf' => 'TOKEN-OK']);
$j = $r['json'] ?? [];
ok('[C] çalıştı, JSON döndü', hatasiz($r) && is_array($j), substr((string)$r['out'], 0, 300) . ($r['fatal'] ?? ''));
ok('[C] yanıt {tamam:true, tam:true, eksik:[]}', ($j['tamam'] ?? null) === true && ($j['tam'] ?? null) === true && ($j['eksik'] ?? null) === [],
   json_encode($j, JSON_UNESCAPED_UNICODE));
ok('[C] yanıtta mesaj var', !empty($j['mesaj']));
$k = satir($ESL);
ok('[C] DB güncellendi (firma f1, ürün 10 Kayısı, ülke 20 Rusya)',
   $k['hks_firma_id'] === 'f1' && $k['hks_urun_id'] === '10' && $k['hks_urun_ad'] === 'Kayısı'
   && $k['hks_ulke_id'] === '20' && $k['hks_ulke_ad'] === 'Rusya', json_encode($k, JSON_UNESCAPED_UNICODE));
ok('[C] updated_by = oturum kullanıcısı', (int)$k['updated_by'] === 7, var_export($k['updated_by'], true));
ok('[C] plaka/durum/parti dokunulmadı', $k['vehicle_plate'] === null && $k['status'] === 'beyan_acildi' && $k['party_no'] === '13-41');
ok('[C] audit beyan_hks_eslestir yazıldı', audit_say('beyan_hks_eslestir') === $a0 + 1);
// İkinci çağrı: değişen yok → ikinci audit yok
$r = api(['beyan_id' => $ESL, 'csrf' => 'TOKEN-OK']);
ok('[C] ikinci çağrı değişiklik yok → ek audit yazılmaz', audit_say('beyan_hks_eslestir') === $a0 + 1 && ($r['json']['tam'] ?? null) === true,
   json_encode($r['json'] ?? null));

// Kayıtlı değer ezilmez
$KAYITLI = beyan_ekle(['hks_firma_id' => 'f1', 'hks_urun_id' => '11', 'hks_urun_ad' => 'Kiraz', 'hks_ulke_id' => '21', 'hks_ulke_ad' => 'Almanya']);
api(['beyan_id' => $KAYITLI, 'csrf' => 'TOKEN-OK']);
$k = satir($KAYITLI);
ok('[C] kayıtlı seçimler uç tarafından ezilmez', $k['hks_urun_id'] === '11' && $k['hks_ulke_id'] === '21' && $k['hks_urun_ad'] === 'Kiraz', json_encode($k, JSON_UNESCAPED_UNICODE));

// Kısmen eşleşen: eksik döner, tam=false, bulunan yazılır
$KISMI = beyan_ekle(['product_name' => 'BİLİNMEYEN', 'company_address' => 'ATLANTIS', 'company_name' => 'BİLİNMEZ LTD', 'buyer_name' => 'KİMSE']);
$r = api(['beyan_id' => $KISMI, 'csrf' => 'TOKEN-OK']); $j = $r['json'] ?? [];
ok('[C] eşleşmeyen → tamam:true, tam:false, eksik dolu',
   ($j['tamam'] ?? null) === true && ($j['tam'] ?? null) === false && in_array('HKS ürünü', $j['eksik'] ?? [], true) && in_array('ülke', $j['eksik'] ?? [], true),
   json_encode($j, JSON_UNESCAPED_UNICODE));
ok('[C] eksik varsa mesaj eksikleri sayar', strpos((string)($j['mesaj'] ?? ''), 'ülke') !== false);
ok('[C] bulunan firma yine de yazıldı, ürün/ülke NULL', satir($KISMI)['hks_firma_id'] === 'f1' && satir($KISMI)['hks_urun_id'] === null);

// Güvenlik kapıları
$SNAP = json_encode(satir($ESL));
$BOSB = beyan_ekle(['vehicle_plate' => null]);
$r = api(['beyan_id' => $BOSB, 'csrf' => 'YANLIS']);
ok('[C] yanlış CSRF → reddedilir (CSRF mesajı), DB değişmedi', satir($BOSB)['hks_urun_id'] === null && stripos((string)$r['out'], 'CSRF') !== false, 'rc=' . var_export($r['rc'], true) . ' ' . substr((string)$r['out'], 0, 150));
$r = api(['beyan_id' => $BOSB]);
ok('[C] CSRF yok → DB değişmedi', satir($BOSB)['hks_urun_id'] === null);
$r = api(['beyan_id' => $BOSB, 'csrf' => 'TOKEN-OK'], ['perms' => ['beyan.read', 'beyan.write']]);
ok('[C] records.write yok (çift yetki) → 403, DB değişmedi', ($r['rc'] ?? null) === 403 && satir($BOSB)['hks_urun_id'] === null, 'rc=' . var_export($r['rc'], true));
$r = api(['beyan_id' => $BOSB, 'csrf' => 'TOKEN-OK'], ['perms' => ['records.write', 'beyan.read']]);
ok('[C] beyan.write yok → 403, DB değişmedi', ($r['rc'] ?? null) === 403 && satir($BOSB)['hks_urun_id'] === null);
$r = api(['beyan_id' => 99999, 'csrf' => 'TOKEN-OK']);
ok('[C] olmayan beyan → 404', ($r['rc'] ?? null) === 404, 'rc=' . var_export($r['rc'], true));
$r = api(['beyan_id' => $SILINMIS, 'csrf' => 'TOKEN-OK']);
ok('[C] silinmiş beyan → 400, değişmedi', ($r['rc'] ?? null) === 400);
$r = api(['csrf' => 'TOKEN-OK']);
ok('[C] beyan_id yok → 400', ($r['rc'] ?? null) === 400);

// Katalog yok + firma yok → 409 katalogYok
$ham = db()->query("SELECT deger FROM hks_kv WHERE anahtar='listeler_cache'")->fetchColumn();
db()->exec("DELETE FROM hks_kv WHERE anahtar='listeler_cache'");
db()->exec("DELETE FROM hks_firmalar");
$r = api(['beyan_id' => $BOSB, 'csrf' => 'TOKEN-OK']);
ok('[C] katalog + firma yok → 409 katalogYok, DB değişmedi',
   ($r['rc'] ?? null) === 409 && !empty($r['json']['katalogYok']) && satir($BOSB)['hks_urun_id'] === null,
   'rc=' . var_export($r['rc'], true) . ' ' . substr((string)$r['out'], 0, 200));
db()->prepare("INSERT INTO hks_kv (anahtar, deger) VALUES ('listeler_cache', ?)")->execute([$ham]);
db()->exec("INSERT INTO hks_firmalar (id,ad,vergi_no) VALUES ('f1','TEST FİRMA','1234567890')");

// ══ D/E) Render: beyan_view + beyanlar ═══════════════════════════════════
echo "── D/E) render ──\n";
function render_kaynak(string $dosya, string $tmpAd, array $degistir = []): string {
    global $ROOT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    foreach ($degistir as $a => $b) $src = str_replace($a, $b, $src);
    $tmp = sys_get_temp_dir() . '/' . $tmpAd . getmypid() . '.php';
    file_put_contents($tmp, $src);
    return $tmp;
}
function render_beyan(int $id): string {
    static $tmp = null;
    if ($tmp === null) $tmp = render_kaynak('beyan_view.php', 'kop_view_');
    $_GET = ['id' => (string)$id];
    ob_start();
    try { include $tmp; }
    catch (Throwable $e) { ob_end_clean(); return '__HATA__' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
    return (string)ob_get_clean();
}
function render_liste(array $get): string {
    global $ROOT;
    global $q, $f_status, $f_urun, $f_marka, $f_depo, $tarih_bas, $tarih_bit, $page, $bek_q, $bek_page;
    static $tmp = null;
    if ($tmp === null) {
        $tmp = render_kaynak('beyanlar.php', 'kop_liste_', [
            'const BEYAN_PER_PAGE = 50;' => "defined('BEYAN_PER_PAGE') || define('BEYAN_PER_PAGE', 50);",
            "__DIR__ . '/_beyan_liste.php'" => var_export($ROOT . '/_beyan_liste.php', true),
        ]);
        $src = (string)file_get_contents($tmp);
        $a = strpos($src, 'function valid_date_beyan');
        $b = strpos($src, "defined('BEYAN_PER_PAGE')");
        $src = substr($src, 0, $a) . "if (!function_exists('beyan_url')) {\n" . substr($src, $a, $b - $a) . "\n}\n" . substr($src, $b);
        file_put_contents($tmp, $src);
    }
    $_GET = $get;
    ob_start();
    try { include $tmp; }
    catch (Throwable $e) { ob_end_clean(); return '__HATA__' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
    return (string)ob_get_clean();
}
function temiz(string $h): bool {
    return strncmp($h, '__HATA__', 8) !== 0 && stripos($h, 'Warning:') === false && stripos($h, 'Notice:') === false
        && stripos($h, 'Deprecated:') === false && stripos($h, 'Fatal error') === false;
}

// E) beyan_view
$V_BOS  = beyan_ekle(['vehicle_plate' => '34ABC123']);                   // eşleştirme eksik
$V_TAM  = beyan_ekle(['vehicle_plate' => '34ABC123'] + $TAM);            // eşleştirme tam
$V_SILI = beyan_ekle(['deleted_at' => '2026-07-05 10:00:00']);
$h = render_beyan($V_BOS);
ok('[E] eşleştirme eksik: render temiz', temiz($h), substr($h, 0, 300));
ok('[E] eşleştirme eksik: hksEslestirBtn VAR, hksOpenBtn YOK',
   strpos($h, 'id="hksEslestirBtn"') !== false && strpos($h, 'id="hksOpenBtn"') === false);
ok('[E] eşleştirme eksik: HKS modalı (hksOverlay) basılmaz', strpos($h, 'id="hksOverlay"') === false);
ok('[E] eşleştirme eksik: eslestir fetch betiği var', strpos($h, 'api_beyan_bildirim.php?action=eslestir') !== false
   && strpos($h, '"beyan_id"') === false && strpos($h, 'beyan_id: ' . $V_BOS) !== false);
$h = render_beyan($V_TAM);
ok('[E] eşleştirme tam: hksOpenBtn VAR, hksEslestirBtn YOK',
   temiz($h) && strpos($h, 'id="hksOpenBtn"') !== false && strpos($h, 'id="hksEslestirBtn"') === false);
ok('[E] eşleştirme tam: modal basılır', strpos($h, 'id="hksOverlay"') !== false);
ok('[E] modal kaydı sonrası halkayit/index.php?ekran=taslaklar yönlendirmesi',
   preg_match("/location\.href\s*=\s*'halkayit\/index\.php\?ekran=taslaklar'/", $h) === 1);
// Kopyala formu
foreach ([$V_BOS, $V_TAM] as $vid) {
    $h = render_beyan($vid);
    ok("[E] #$vid üstte beyan_kopyala.php POST formu (id + csrf)",
       preg_match('/<form[^>]*method="post"[^>]*action="beyan_kopyala\.php"/', $h) === 1
       && strpos($h, '<input type="hidden" name="id"   value="' . $vid . '">') !== false
       && preg_match('/name="csrf" value="[0-9a-f]{20,}"/', $h) === 1);
    // Kopyala formu, Düzenle bağlantısından önce ve bildirim kartından önce
    ok("[E] #$vid kopyala formu sayfa başında (bildirim kartından önce)",
       strpos($h, 'beyan_kopyala.php') < strpos($h, 'bk-hucre'), '');
}
// Kopyala formu iç içe değil
$h = render_beyan($V_TAM);
preg_match_all('/<form\b|<\/form>/', $h, $mf);
$d = 0; $ic = false; foreach ($mf[0] as $t) { if ($t === '</form>') $d--; else { $d++; if ($d > 1) $ic = true; } }
ok('[E] iç içe <form> yok', !$ic);
// Silinmiş beyanda kopyala yok
$h = render_beyan($V_SILI);
ok('[E] silinmiş (arşiv) beyanda Kopyala formu YOK', temiz($h) && strpos($h, 'beyan_kopyala.php') === false);
// Yetki
global $PERMS;
$PERMS_ESKI = $PERMS;
$PERMS = ['beyan.read'];
$h = render_beyan($V_BOS);
ok('[E] beyan.write yoksa Kopyala formu + Eşleştir düğmesi YOK',
   strpos($h, 'beyan_kopyala.php') === false && strpos($h, 'hksEslestirBtn') === false);
$PERMS = ['beyan.read', 'beyan.write'];   // records.write yok → HKS düğmeleri yok, kopyala var
$h = render_beyan($V_BOS);
ok('[E] records.write yoksa Eşleştir düğmesi YOK ama Kopyala VAR',
   strpos($h, 'hksEslestirBtn') === false && strpos($h, 'beyan_kopyala.php') !== false);
$PERMS = $PERMS_ESKI;
// Zaten aktif bildirimi olan beyanda eşleştir çıkmaz
$V_BAG = beyan_ekle(['vehicle_plate' => '34XYZ1']);
db()->prepare("INSERT INTO beyan_hks_bildirim (beyan_id,hks_firma_ad,taslak_id,durum,urun_ad,ulke_ad,plaka,kg,fiyat) VALUES (?,?,?,'taslak',?,?,?,?,?)")
   ->execute([$V_BAG, 'TEST FİRMA', 't9', 'Kayısı', 'Rusya', '34XYZ1', 1, 1]);
$h = render_beyan($V_BAG);
ok('[E] aktif bildirimi olan beyanda Eşleştir düğmesi YOK', temiz($h) && strpos($h, 'hksEslestirBtn') === false);

// D) beyanlar.php liste
$L1 = beyan_ekle(['party_no' => 'LISTE-A', 'vehicle_plate' => '06 ABC 06'] + $TAM);
$L2 = beyan_ekle(['party_no' => 'LISTE-B', 'vehicle_plate' => null]);
$h = render_liste([]);
ok('[D] liste render temiz', temiz($h), substr($h, 0, 300));
foreach (['Plaka', 'HKS Firma', 'Ülke'] as $kolon) {
    ok("[D] masaüstü tablo başlığı: $kolon", preg_match('/<th>' . preg_quote($kolon, '/') . '<\/th>/u', $h) === 1);
}
ok('[D] Kopyala formları basılıyor (beyan_kopyala.php)', substr_count($h, 'action="beyan_kopyala.php"') >= 2);
ok('[D] kopyala formu satırın id\'sini taşıyor', strpos($h, '<input type="hidden" name="id" value="' . $L1 . '">') !== false);
ok('[D] kopyala formunda csrf alanı', preg_match('/class="bl-kop-form"[^>]*>\s*<input type="hidden" name="id" value="\d+">\s*<input type="hidden" name="csrf" value="[0-9a-f]{20,}"/s', $h) === 1);
ok('[D] HKS firma adı gösteriliyor (id değil)', strpos($h, 'TEST FİRMA') !== false);
ok('[D] ülke adı gösteriliyor', strpos($h, '>Rusya<') !== false);
ok('[D] plaka gösteriliyor', strpos($h, 'bl-plaka') !== false && strpos($h, '06 ABC 06') !== false);
ok('[D] plakası olmayan satırda "eksik" işareti', strpos($h, 'bl-eksik">eksik<') !== false);
ok('[D] mobil kartta Plaka / HKS Firma etiketleri', strpos($h, '<b>Plaka</b>') !== false && strpos($h, '<b>HKS Firma</b>') !== false);
ok('[D] kopyala sütunu başlığı sr-only', strpos($h, 'bl-kop-col') !== false);
$bozuk = [];
preg_match_all('/<(\/?)(div|table|tbody|thead|tr|td|th|form)\b/i', $h, $mm, PREG_SET_ORDER);
$ac = $kp = [];
foreach ($mm as $m) { $t = strtolower($m[2]); if ($m[1] === '/') $kp[$t] = ($kp[$t] ?? 0) + 1; else $ac[$t] = ($ac[$t] ?? 0) + 1; }
foreach ($ac as $t => $n) if (($kp[$t] ?? 0) !== $n) $bozuk[] = "$t: $n/" . ($kp[$t] ?? 0);
ok('[D] liste HTML etiket dengesi', !$bozuk, implode(', ', $bozuk));
// Yetkisiz: kopyala + sütun yok
$PERMS = ['beyan.read'];
$h = render_liste([]);
ok('[D] beyan.write yoksa Kopyala formu ve sütunu YOK', temiz($h) && strpos($h, 'beyan_kopyala.php') === false && strpos($h, 'bl-kop-col') === false);
$PERMS = $PERMS_ESKI;
// Kolon sayısı: thead th sayısı = satır td sayısı (kopya sütunu dahil)
$h = render_liste([]);
if (preg_match('/<thead>(.*?)<\/thead>/s', $h, $mt) && preg_match('/<tbody>.*?<tr[^>]*>(.*?)<\/tr>/s', $h, $mr)) {
    $nth = preg_match_all('/<th\b/', $mt[1]); $ntd = preg_match_all('/<td\b/', $mr[1]);
    ok('[D] ilk tablo: başlık sütun sayısı = hücre sayısı', $nth === $ntd, "th=$nth td=$ntd");
} else ok('[D] ilk tablo bulunamadı', false, 'thead/tbody regex');

// Real restricted edit: forged section values must not replace stored data.
$limited_id = beyan_ekle($TAM + ['status' => 'temiz', 'analysis_result_at' => '2026-07-03 12:34:56']);
$before = satir($limited_id);
$forged = $before;
$forged['csrf'] = 'TOKEN-OK';
$forged['edit_token'] = beyan_edit_token($before);
$forged['party_no'] = 'UPDATED-42';
$forged['vehicle_plate'] = '34ABC123';
$forged['status'] = 'red';
foreach (beyan_permission_fields() as $fields) foreach ($fields as $field) $forged[$field] = 'FORGED';
$r = calistir(['script' => 'beyan_edit.php', 'get' => ['id' => $limited_id], 'post' => $forged,
    'perms' => ['beyan.read', 'beyan.edit', 'beyan.permissions.detailed']]);
ok('[E] restricted edit succeeds without hidden-section catalog access', hatasiz($r) && konum($r) === 'beyan_view.php?id=' . $limited_id, json_encode($r));
$after = satir($limited_id);
ok('[E] restricted edit updates allowed base field', $after['party_no'] === 'UPDATED-42');
ok('[E] restricted edit preserves submitted base plate', $after['vehicle_plate'] === '34ABC123');
ok('[E] restricted edit cannot forge status', $after['status'] === $before['status']);
foreach (beyan_permission_fields() as $fields) foreach ($fields as $field) {
    ok('[E] restricted edit preserves ' . $field, $after[$field] === $before[$field]);
}
$r = kopyala(['id' => (string)$limited_id, 'csrf' => 'TOKEN-OK'],
    ['perms' => ['beyan.read', 'beyan.create', 'beyan.permissions.detailed']]);
ok('[E] create-only copy opens accessible detail page', hatasiz($r) && str_starts_with(konum($r), 'beyan_view.php?id='));

// ══ F) Statik kontroller ══════════════════════════════════════════════════
echo "── F) statik ──\n";
$kop = (string)file_get_contents($ROOT . '/beyan_kopyala.php');
ok('[F] beyan_kopyala: POST kapısı, csrf_check, can_beyan(create)',
   strpos($kop, "REQUEST_METHOD'] !== 'POST'") !== false && strpos($kop, 'csrf_check(') !== false && strpos($kop, "can_beyan('create')") !== false);
ok('[F] beyan_kopyala: parti/plaka/ham metin INSERT\'te yok',
   preg_match('/INSERT INTO customs_declarations\s*\(([^)]*)\)/s', $kop, $mi) === 1
   && !preg_match('/party_no|vehicle_plate|raw_text|unmatched_text|loading_record_id|hks_durum|analysis_note|sample_taken_at|analysis_result_at/', $mi[1]));
ok('[F] beyan_kopyala: CSRF yetki kontrolünden SONRA, POST kapısından SONRA ve yazmadan ÖNCE',
   strpos($kop, 'csrf_check(') < strpos($kop, 'INSERT INTO customs_declarations') && strpos($kop, "can_beyan('create')") < strpos($kop, 'csrf_check('));
ok('[F] sw.js/APP_SURUM senkron', (function () use ($ROOT) {
    preg_match("/APP_SURUM',\s*'v(\d+)/", (string)file_get_contents($ROOT . '/config/helpers.php'), $a);
    preg_match("/yukleme-plani-v(\d+)/", (string)file_get_contents($ROOT . '/sw.js'), $b);
    return isset($a[1], $b[1]) && $a[1] === $b[1];
})(), 'APP_SURUM ile sw.js CACHE farklı ya da bulunamadı');

echo "\n$toplam test, " . ($toplam - $fail) . " geçti, $fail kaldı\n";
echo $fail === 0 ? "TUM TESTLER GECTI\n" : "$fail TEST BASARISIZ\n";
exit($fail === 0 ? 0 : 1);
