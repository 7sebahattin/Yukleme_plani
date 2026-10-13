<?php
// =========================================================
// scripts/beyan_ui_smoke.php — beyan_view.php RENDER testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth ile sayfayı GERÇEKTEN render eder, sonra HTML'i doğrular.
// (hesap_ui_smoke.php ile aynı desen.)
//
//   php scripts/beyan_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
//
// NEDEN: beyan_bildirim_smoke.php statiktir — kaynak kodda dize arar. Bu
// dosya sayfayı ÇALIŞTIRIR; "buton pasif görünmeli" gibi bir kural ancak
// üretilen HTML'de `disabled` özniteliği aranarak doğrulanabilir.
// bb_* ve beyan_hks_* fonksiyonlarının GERÇEĞİ kullanılır (config/helpers.php
// yüklenir), yalnız auth ve DB stub'lanır.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
// Migration gurultusunu (SQLite'ta SHOW COLUMNS calismaz) log dosyasina yonlendir.
$LOG = sys_get_temp_dir() . '/beyan_ui_smoke.log';
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
  hks_ulke_id TEXT, hks_ulke_ad TEXT, note TEXT, sample_gross_kg REAL, sample_net_kg REAL, sample_crate_type TEXT, sample_crate_count INTEGER, tractor_plate TEXT, sender_company_name TEXT,
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

// ── Senaryolar ────────────────────────────────────────────────────────────
function beyan_ekle(array $a): int {
    $v = array_merge([
        'party_no' => '13-41', 'product_name' => 'KAYISI', 'product_variety' => 'PRICIA',
        'net_kg' => 19800, 'pallet_count' => 26, 'status' => 'beyan_acildi',
        'vehicle_plate' => null, 'hks_firma_id' => null, 'hks_urun_id' => null,
        'hks_urun_ad' => null, 'hks_ulke_id' => null, 'hks_ulke_ad' => null,
        'buyer_name' => 'ALICI', 'transport_type' => 'DENİZYOLU',
    ], $a);
    $st = db()->prepare("INSERT INTO customs_declarations
        (party_no,product_name,product_variety,net_kg,pallet_count,status,vehicle_plate,
         hks_firma_id,hks_urun_id,hks_urun_ad,hks_ulke_id,hks_ulke_ad,buyer_name,transport_type)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$v['party_no'],$v['product_name'],$v['product_variety'],$v['net_kg'],
        $v['pallet_count'],$v['status'],$v['vehicle_plate'],$v['hks_firma_id'],$v['hks_urun_id'],
        $v['hks_urun_ad'],$v['hks_ulke_id'],$v['hks_ulke_ad'],$v['buyer_name'],$v['transport_type']]);
    return (int)db()->lastInsertId();
}

$TAM = ['vehicle_plate' => '34ABC123', 'hks_firma_id' => 'f1',
        'hks_urun_id' => '10', 'hks_urun_ad' => 'Kayısı',
        'hks_ulke_id' => '20', 'hks_ulke_ad' => 'Rusya'];

$ID_BOS     = beyan_ekle([]);                                            // hiçbir şey yok
$ID_PLAKA   = beyan_ekle(['vehicle_plate' => '34ABC123']);               // plaka var, eşleşme yok
$ID_TAM     = beyan_ekle($TAM);                                          // her şey tam
$ID_BILDIRIM = beyan_ekle($TAM);                                         // zaten bildirimi var
db()->prepare("INSERT INTO beyan_hks_bildirim
    (beyan_id,hks_firma_ad,taslak_id,durum,urun_ad,ulke_ad,plaka,kg,fiyat)
    VALUES (?,?,?,'taslak',?,?,?,?,?)")
   ->execute([$ID_BILDIRIM,'TEST FİRMA','t1','Kayısı','Rusya','34ABC123',19800,12.5]);
$ID_NETKG   = beyan_ekle($TAM + [] ); db()->exec("UPDATE customs_declarations SET net_kg=0 WHERE id=$ID_NETKG");

// ── Sayfayı render et ─────────────────────────────────────────────────────
// Sayfayı require'ları sıyırarak çalıştır (hesap_ui_smoke.php ile aynı desen):
// config/db.php ve config/auth.php burada zaten stub'lanmış durumda, yeniden
// yüklenirlerse db()/can() çakışır.
function render_beyan(int $id, array $get = []): string {
    global $ROOT;
    static $tmp = null;
    if ($tmp === null) {
        $src = (string)file_get_contents($ROOT . '/beyan_view.php');
        $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
        $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
        // Unique per process: PHP and browser smoke checks can run concurrently.
        $tmp = sys_get_temp_dir() . '/beyan_view_smoke_' . getmypid() . '.php';
        file_put_contents($tmp, $src);
        register_shutdown_function(static function () use ($tmp): void { if (is_file($tmp)) unlink($tmp); });
    }
    $_GET = array_merge($get, ['id' => (string)$id]);
    ob_start();
    try { include $tmp; }
    catch (Throwable $e) { ob_end_clean(); return '__HATA__' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
    return (string)ob_get_clean();
}

// Browser regression fixture: reuse this in-memory schema and real detail render.
// php scripts/beyan_ui_smoke.php --render-status OUTPUT_PATH [status] [restricted|deleted]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--render-status') {
    $fixtureStatus = $argv[3] ?? 'beyan_acildi';
    if (empty($argv[2]) || !array_key_exists($fixtureStatus, beyan_statuses())) {
        fwrite(STDERR, "Usage: --render-status OUTPUT_PATH [valid status] [restricted|deleted]\n");
        exit(1);
    }
    db()->prepare('UPDATE customs_declarations SET status=? WHERE id=?')->execute([$fixtureStatus, $ID_TAM]);
    if (($argv[4] ?? '') === 'restricted') $PERMS = ['beyan.read', 'beyan.permissions.detailed'];
    if (($argv[4] ?? '') === 'deleted') db()->prepare('UPDATE customs_declarations SET deleted_at=? WHERE id=?')->execute(['2026-10-12 12:00:00', $ID_TAM]);
    $fixtureHtml = render_beyan($ID_TAM);
    if (str_starts_with($fixtureHtml, '__HATA__')) { fwrite(STDERR, $fixtureHtml); exit(1); }
    file_put_contents($argv[2], $fixtureHtml);
    exit(0);
}

// beyanlar.php'yi de GERCEKTEN render et. Kaynak taramasi filtre serigi
// gibi bir duzenin PHP uyarisi uretip uretmedigini goremez; ust uste kacan
// sorunlarin hepsi bu katmandaydi.
// Sayfa iki kez include edildigi icin ust seviye bildirimler korunur.
function render_liste(array $get): string {
    global $ROOT;
    global $q, $f_status, $f_urun, $f_marka, $f_depo, $tarih_bas, $tarih_bit, $page, $bek_q, $bek_page;
    static $tmp = null;
    if ($tmp === null) {
        $src = (string)file_get_contents($ROOT . '/beyanlar.php');
        $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
        $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
        // const + fonksiyonlar tek blokta: ikinci include'da yeniden tanimlanmasin.
        $src = str_replace('const BEYAN_PER_PAGE = 50;',
                           "defined('BEYAN_PER_PAGE') || define('BEYAN_PER_PAGE', 50);", $src);
        // Gecici kopya /tmp'te durdugu icin __DIR__ proje kokunu gostermez;
        // partial include'lari gercek koke sabitle.
        $src = str_replace("__DIR__ . '/_beyan_liste.php'",
                           var_export($ROOT . '/_beyan_liste.php', true), $src);
        $a = strpos($src, 'function valid_date_beyan');
        $b = strpos($src, "defined('BEYAN_PER_PAGE')");
        $src = substr($src, 0, $a) . "if (!function_exists('beyan_url')) {\n"
             . substr($src, $a, $b - $a) . "\n}\n" . substr($src, $b);
        $tmp = sys_get_temp_dir() . '/beyanlar_smoke.php';
        file_put_contents($tmp, $src);
    }
    $_GET = $get;
    ob_start();
    try { include $tmp; }
    catch (Throwable $e) { ob_end_clean(); return '__HATA__' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
    return (string)ob_get_clean();
}

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-62s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : "\n    → " . $ipucu);
}
// "Bildirim Yap" butonu pasif mi? (aktif = disabled özniteliği YOK)
function buton_pasif(string $html): ?bool {
    if (!preg_match('/<button[^>]*>\s*🏛 HKS Taslağı Oluştur\s*<\/button>/u', $html, $m)) return null;
    return strpos($m[0], 'disabled') !== false;
}
function etiket_dengesi(string $html): array {
    $acik = $kapali = [];
    preg_match_all('/<(\/?)(div|table|tbody|thead|tr|td|th|form|dl|section)\b/i', $html, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) { $t = strtolower($m[2]); if ($m[1] === '/') $kapali[$t] = ($kapali[$t] ?? 0) + 1; else $acik[$t] = ($acik[$t] ?? 0) + 1; }
    $bozuk = [];
    foreach ($acik as $t => $n) if (($kapali[$t] ?? 0) !== $n) $bozuk[] = "$t: $n açık, " . ($kapali[$t] ?? 0) . " kapalı";
    return $bozuk;
}

$senaryolar = [
    'bos'      => [$ID_BOS,      true,  'Araç plakası'],
    'plaka'    => [$ID_PLAKA,    true,  'Hal Bildirim bilgileri eksik'],
    'tam'      => [$ID_TAM,      false, ''],
    'bildirim' => [$ID_BILDIRIM, true,  'bekleyen bir HKS taslağı'],
    'netkg'    => [$ID_NETKG,    true,  'Net KG'],
];

foreach ($senaryolar as $ad => [$id, $pasifBekleniyor, $engelMetni]) {
    $html = render_beyan($id);
    if (strncmp($html, '__HATA__', 8) === 0) {
        ok("[$ad] sayfa render edildi", false, substr($html, 8));
        continue;
    }
    ok("[$ad] sayfa render edildi", strlen($html) > 500);
    ok("[$ad] PHP uyarısı yok",
       stripos($html, 'Warning:') === false && stripos($html, 'Notice:') === false
       && stripos($html, 'Deprecated:') === false && stripos($html, 'Fatal error') === false,
       'HTML icinde PHP hatasi var');

    $bozuk = etiket_dengesi($html);
    ok("[$ad] HTML etiket dengesi", empty($bozuk), implode(' | ', $bozuk));

    $pasif = buton_pasif($html);
    // Eşleştirme eksikse HKS Taslağı düğmesinin YERİNE "Eşleştir" düğmesi çıkar (v330).
    $esl = strpos($html, 'id="hksEslestirBtn"') !== false;
    ok("[$ad] Bildirim Yap / Eşleştir butonu var", $pasif !== null || $esl, 'buton hic render edilmemis');
    if ($esl) {
        ok("[$ad] eşleştir modunda HKS Taslağı düğmesi YOK", $pasif === null && strpos($html, 'id="hksOpenBtn"') === false,
           'iki düğme birden — değişimli çalışmalı');
    }
    if ($pasif !== null) {
        ok("[$ad] buton durumu " . ($pasifBekleniyor ? 'PASİF' : 'AKTİF'), $pasif === $pasifBekleniyor,
           $pasif ? 'pasif ama aktif olmaliydi' : 'AKTIF gorunuyor ama olmamali — kullanici tiklar, calismaz');
    }
    if ($engelMetni !== '') {
        ok("[$ad] engel sebebi ekranda yaziyor",
           strpos($html, 'bk-engel') !== false && mb_strpos($html, $engelMetni) !== false,
           'sebep gorunmuyor — kullanici neden calismadigini anlamaz');
    }
    // Bildirim bolumu TEK olmali
    ok("[$ad] bildirim bölümü tek",
       substr_count($html, 'Hal Kayıt Bildirimi') === (strpos($html, 'hksOverlay') !== false ? 2 : 1),
       'ayni bilgi birden fazla yerde (kart + modal basligi disinda)');
    // Kart dort alani da gostermeli
    ok("[$ad] kartta 5 alan var", substr_count($html, 'class="bk-hucre') === 5,
       'kart alan sayisi: ' . substr_count($html, 'class="bk-hucre'));
}

// Geçmiş tablosu yalnız bildirim varken
// SİL: form gerçekten render ediliyor mu, iç içe form var mı?
$hs = render_beyan($ID_BOS);
file_put_contents(sys_get_temp_dir() . '/beyan_view_ciktisi.html', $hs);
ok('[sil] silme formu var',
   preg_match('/<form[^>]*action="beyan_delete\.php"/', $hs) === 1,
   'Sil formu hic render edilmemis');
ok('[sil] silme butonu var', mb_strpos($hs, '>Sil<') !== false);
// İÇ İÇE FORM: tarayıcı iç formu DÜŞÜRÜR, buton hiçbir şey göndermez.
preg_match_all('/<form\b|<\/form>/', $hs, $mf, PREG_OFFSET_CAPTURE);
$derinlik = 0; $icice = false;
foreach ($mf[0] as $t) { if ($t[0] === '</form>') $derinlik--; else { $derinlik++; if ($derinlik > 1) $icice = true; } }
ok('[sil] iç içe <form> YOK', !$icice,
   'ic ice form: tarayici ic formu dusurur, Sil butonu hicbir sey gondermez');

$h1 = render_beyan($ID_TAM); $h2 = render_beyan($ID_BILDIRIM);
ok('geçmiş tablosu yalnız bildirim varken görünür',
   strpos($h1, 'beyan-badge">HKS TASLAK') === false && strpos($h2, 'HKS TASLAK') !== false,
   'gecmis tablosu yanlis kosulda ciziliyor');

// ── DURUM ŞERİDİ: tum durumlar tiklanabilir, secili olan cerceveli ───────
// Eskiden yalniz beyan_next_statuses() ciziliyordu; sonuca varmak icin ara
// durumlara tek tek tiklamak gerekiyordu ve terminal durumda serit hic
// cikmadigi icin geri donus yolu yoktu.
$TUM_DURUM = array_keys(beyan_statuses());
foreach (['beyan_acildi' => $ID_TAM, 'yuklendi' => $ID_TAM, 'iptal' => $ID_TAM] as $dur => $did) {
    db()->exec("UPDATE customs_declarations SET status='$dur' WHERE id=$did");
    $hd = render_beyan($did);
    if (strncmp($hd, '__HATA__', 8) === 0) { ok("[durum:$dur] render edildi", false, substr($hd, 8)); continue; }

    // Her durum ya buton ya da secili pil olarak ciziliyor mu?
    $eksik = [];
    foreach ($TUM_DURUM as $d) {
        $btn = strpos($hd, 'name="status" value="' . $d . '"') !== false;
        $sec = $d === $dur && strpos($hd, 'beyan-durum-secili') !== false;
        if (!$btn && !$sec) $eksik[] = $d;
    }
    ok("[durum:$dur] dokuz durumun hepsi serit uzerinde", empty($eksik),
       'seritte olmayan durum(lar): ' . implode(', ', $eksik));

    // Secili durum BUTON DEGIL: kendine gecis anlamsiz, cerceve ile isaretli.
    ok("[durum:$dur] secili durum buton degil, cerceveli",
       strpos($hd, 'name="status" value="' . $dur . '"') === false
       && preg_match('/beyan-durum-secili[^>]*>\s*✓/u', $hd) === 1,
       'secili durum ya buton olarak cizilmis ya da cerceve/tik isareti yok');

    // Serit TEK form olmali — eskiden her buton kendi formunu ve ~26 gizli
    // alanini tasiyordu; dokuz durumla bu 230+ gizli alan demekti.
    ok("[durum:$dur] serit tek form", substr_count($hd, 'class="beyan-durum-form"') === 1,
       'durum formu sayisi: ' . substr_count($hd, 'class="beyan-durum-form"'));

    // `status` icin AYRI hidden alan OLMAMALI: ayni name iki yerden gelirse
    // hangisinin kazandigi belirsiz olurdu (butonun kendi value'su tasir).
    ok("[durum:$dur] status icin hidden alan yok",
       preg_match('/<input type="hidden" name="status"/', $hd) !== 1,
       'hidden status alani butonun value\'su ile cakisir');

    // Durum işlemi diğer alanların eski kopyasını yeniden yazmamalı.
    preg_match('/<form[^>]*class="beyan-durum-form".*?<\/form>/s', $hd, $status_form);
    ok("[durum:$dur] serit yalnız durum işlemini kullanıyor",
       strpos($status_form[0] ?? '', 'name="status_only"') !== false
       && strpos($status_form[0] ?? '', 'name="vehicle_plate"') === false
       && strpos($status_form[0] ?? '', 'name="net_kg"') === false,
       'durum formu eski alan kopyalarını yazabilir');
}
// Terminal durumda da serit cizilmeli: geri donus yolu olsun.
db()->exec("UPDATE customs_declarations SET status='yuklendi' WHERE id=$ID_TAM");
$h_term = render_beyan($ID_TAM);
ok('[durum] terminal durumda serit gizlenmiyor',
   strpos($h_term, 'class="beyan-durum-form"') !== false
   && strpos($h_term, 'name="status" value="taslak"') !== false,
   'terminal durumda serit yok — kullanici durumu geri alamaz');
// Uzak bir duruma DOGRUDAN gecis butonu var mi? ("direkt sonuca gidebilelim")
db()->exec("UPDATE customs_declarations SET status='taslak' WHERE id=$ID_TAM");
$h_jump = render_beyan($ID_TAM);
ok('[durum] taslaktan dogrudan YUKLENDI butonu var',
   strpos($h_jump, 'name="status" value="yuklendi"') !== false,
   'ara durumlar atlanamiyor — tek tek tiklamak gerekiyor');
// Akis sirasi: olumsuz cikislar (red/iptal) SONDA olmali.
$sira = beyan_durum_akis_sirasi();
ok('[durum] akis sirasi red/iptal ile bitiyor',
   array_slice($sira, -2) === ['red', 'iptal'] && count($sira) === count($TUM_DURUM),
   'sira: ' . implode(',', $sira));
// Listede olmayan bir durum SONA eklenmeli (yeni durum sessizce kaybolmasin).
ok('[durum] akis sirasi tum durumlari kapsiyor',
   array_diff($TUM_DURUM, $sira) === [] && array_diff($sira, $TUM_DURUM) === [],
   'akis sirasi ile beyan_statuses ayrismis');
// Red hala not istiyor (analysis_note zorunlu — sunucu reddediyor).
ok('[durum] RED butonu not soruyor',
   preg_match('/name="status" value="red"[^>]*prompt_note/s', $h_jump) === 1
   || preg_match('/prompt_note[^>]*name="status" value="red"/s', $h_jump) === 1,
   'red butonu not sormuyor — sunucu "analiz notu zorunlu" ile reddeder');
db()->exec("UPDATE customs_declarations SET status='beyan_acildi' WHERE id=$ID_TAM");

// beyan_edit.php'nin TAM guncelleme dali dogrudan gecisi kabul etmeli:
// serit oraya POST eder, next_statuses kapisi YALNIZ status_only dalinda olmali.
$edit_src = (string)file_get_contents($ROOT . '/beyan_edit.php');
ok('[durum] geçiş doğrulaması ortak durum kümesini kullanıyor',
   strpos($edit_src, 'beyan_statuses()') !== false
   && strpos($edit_src, "\$_POST['status_only']") !== false,
   'durum işlemi bulunamadı');

// Yetkisiz kullanıcı: buton HİÇ olmamalı
$PERMS = ['beyan.read'];
$h3 = render_beyan($ID_TAM);
ok('yetkisiz kullanıcıda Bildirim Yap butonu hiç yok', buton_pasif($h3) === null,
   'records.write olmadan buton goruluyor');
$PERMS = ['beyan.read', 'beyan.write', 'beyan.delete', 'records.write', 'maliyet.read'];

// ── FORM RENDER: beyan_edit.php ───────────────────────────────────────────
function render_form(int $id): string {
    global $ROOT;
    static $tmp = null;
    if ($tmp === null) {
        $src = (string)file_get_contents($ROOT . '/beyan_edit.php');
        $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
        $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
        $src = str_replace("__DIR__ . '/_beyan_form.php'", var_export($ROOT . '/_beyan_form.php', true), $src);
        $tmp = sys_get_temp_dir() . '/beyan_edit_smoke.php';
        file_put_contents($tmp, $src);
    }
    $_GET = ['id' => (string)$id];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try { include $tmp; }
    catch (Throwable $e) { ob_end_clean(); return '__HATA__' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
    return (string)ob_get_clean();
}

$form = render_form($ID_BOS);
ok('[form] render edildi', strncmp($form, '__HATA__', 8) !== 0, substr($form, 8, 200));

// Duzenle formundaki durum listesi, beyan_view.php'deki serit ile AYNI kurali
// izlemeli: tum durumlar secilebilir. Eskiden liste sonraki durumlarla
// siniliydi ve terminal durumda "degistirilemez" yaziyordu — sunucu ise
// (tam guncelleme dali) hicbir zaman kisitlamiyordu, yani iddia yanlisti.
db()->exec("UPDATE customs_declarations SET status='yuklendi' WHERE id=$ID_BOS");
$form_t = render_form($ID_BOS);
if (strncmp($form_t, '__HATA__', 8) === 0) {
    ok('[form:terminal] render edildi', false, substr($form_t, 8));
} else {
    $sel = '';
    if (preg_match('/<select[^>]*name="status".*?<\/select>/s', $form_t, $ms)) $sel = $ms[0];
    $eksik = [];
    foreach (array_keys(beyan_statuses()) as $d)
        if (strpos($sel, 'value="' . $d . '"') === false) $eksik[] = $d;
    ok('[form:terminal] durum listesi tum durumlari tasiyor', $sel !== '' && empty($eksik),
       $sel === '' ? 'status select hic cizilmemis' : 'eksik: ' . implode(',', $eksik));
    // HTML yorumlari SAYILMAZ: onlar gecmisi anlatir, kullaniciya gorunmez.
    $gorunur = preg_replace('/<!--.*?-->/s', '', $form_t);
    ok('[form:terminal] "degistirilemez" iddiasi yok',
       strpos($gorunur, 'değiştirilemez') === false && strpos($gorunur, 'geri alınamaz') === false,
       'form durumun degistirilemedigini soyluyor — oysa serit de sunucu da degistiriyor');
    ok('[form:terminal] status icin salt hidden alan yok',
       preg_match('/<input type="hidden" name="status"/', $form_t) !== 1,
       'terminal durumda select yerine hidden alan cizilmis');
}
db()->exec("UPDATE customs_declarations SET status='beyan_acildi' WHERE id=$ID_BOS");
$form = render_form($ID_BOS);
if (strncmp($form, '__HATA__', 8) !== 0) {
    ok('[form] PHP uyarısı yok',
       stripos($form, 'Warning:') === false && stripos($form, 'Notice:') === false
       && stripos($form, 'Fatal error') === false);
    $bozuk = etiket_dengesi($form);
    ok('[form] HTML etiket dengesi', empty($bozuk), implode(' | ', $bozuk));

    // KRİTİK: plaka alanı TEK olmalı. İki tane olsaydı POST'ta çakışır ve
    // hangisinin kaydedildiği JS'e/tarayıcıya kalırdı (CLAUDE.md kuralı).
    ok('[form] vehicle_plate alanı TEK',
       substr_count($form, 'name="vehicle_plate"') === 1,
       'bulunan: ' . substr_count($form, 'name="vehicle_plate"') . ' adet');

    // Plaka "Hal Bildirim Bilgileri" bölümünün İÇİNDE olmalı.
    ok('[form] Dorse plaka Parti/Sevkiyat kartında (Hal Bildirim sekmesinden önce)',
       preg_match('/Parti\/Sevkiyat.*?name="vehicle_plate".*?Hal Bildirim Bilgileri/s', $form) === 1,
       'dorse plaka Parti/Sevkiyat kartında olmalı');
    ok('[form] Çekici plaka + Gönderici firma + Ülke Parti/Sevkiyat kartında',
       preg_match('/Parti\/Sevkiyat.*?name="sender_company_name".*?name="hks_ulke_id".*?name="tractor_plate".*?name="vehicle_plate".*?Ürün ve Miktarlar/s', $form) === 1);
    ok('[form] Hal Bildirim sekmesinde yalnız HKS Ürünü (firma/ülke select\'i yok)',
       preg_match('/<select[^>]*name="hks_firma_id"/', $form) !== 1
       && preg_match('/Hal Bildirim Bilgileri.*name="hks_urun_id"/s', $form) === 1
       && preg_match('/Hal Bildirim Bilgileri.*name="hks_ulke_id"/s', $form) !== 1);

    // Plaka: örnek metin YOK, boşluksuz giriş açık.
    ok('[form] plaka alanında örnek metin yok',
       preg_match('/name="vehicle_plate"[^>]*placeholder=/s', $form) !== 1,
       'placeholder hala duruyor');
    ok('[form] plaka boşluksuz yazılıyor',
       preg_match('/name="vehicle_plate"[^>]*data-nospace/s', $form) === 1,
       'data-nospace yok — "34 ABC 123" boslukla kaydedilir');

    // Bölümlü yapı: ana bilgiler önce, kaynak ayrı panelde, HKS ayrı sekmede.
    $iw = mb_strpos($form, 'WhatsApp Metni');
    $ih = mb_strpos($form, 'Hal Bildirim Bilgileri');
    $it = mb_strpos($form, 'Parti/Sevkiyat');
    $pa = strpos($form, 'id="beyanPanel_analiz"'); $ph = strpos($form, 'id="beyanPanel_hks"'); $pk = strpos($form, 'id="beyanPanel_kaynak"');
    ok('[form] beyan bilgileri kaynak metinden önce, Hal Bildirim AYRI sekme (Analiz ile Kaynak arasında)',
       $iw !== false && $ih !== false && $it !== false && $it < $iw
       && strpos($form, 'class="beyan-workspace-hks"') !== false
       && $pa !== false && $pa < $ph && $ph < $pk && strpos($form, 'data-beyan-tab="hks"') !== false
       && strpos($form, "<aside class=\"beyan-workspace-hks\"") === false && ($bh = strpos($form, "Hal Bildirim Bilgileri")) > $ph && $bh < $pk,
       "konumlar: whatsapp=$iw hks=$ih temel=$it pa=$pa ph=$ph pk=$pk aside=" . var_export(strpos($form, "<aside"), true));

    // Uzun katalog listesi YAZARAK ARANABİLİR olmalı; kısa liste olmamalı.
    // (Test kataloğunda 2 ürün / 1 ülke var → eşiğin altında, aramasız.)
    ok('[form] kısa listede arama kutusu YOK',
       preg_match('/name="hks_ulke_id"[^>]*data-aramali/s', $form) !== 1,
       'kisa listede arama kutusu fazladan tiklama getirir');

    // Asıl <select> DOM'da KALMALI — değeri o taşır, POST ona bağlı.
    foreach (['hks_urun_id', 'hks_ulke_id'] as $alan) {
        ok("[form] $alan hâlâ <select>", preg_match('/<select[^>]*name="' . $alan . '"/', $form) === 1,
           'select input ile DEGISTIRILMIS — POST degeri kaybolur');
    }

    // Dört alan da orada mı?
    foreach (['sender_company_name', 'hks_urun_id', 'hks_ulke_id', 'tractor_plate', 'vehicle_plate'] as $alan) {
        ok("[form] $alan alanı var", strpos($form, 'name="' . $alan . '"') !== false);
    }
}

// UZUN katalogda arama kutusu AÇILMALI — eşik davranışını doğrula.
$__uzun = ['zaman' => '2026-07-01T10:00:00+03:00',
           'urunler' => array_map(fn($i) => ['id' => (string)$i, 'ad' => "URUN $i"], range(1, 25)),
           'ulkeler' => array_map(fn($i) => ['id' => (string)(100 + $i), 'ad' => "ULKE $i"], range(1, 25)),
           'sifatlar' => [['id' => '7', 'ad' => 'İhracat']],
           'bildirimTurleri' => [['id' => '5', 'ad' => 'Satış']],
           'isletmeTurleri' => [['id' => '3', 'ad' => 'Yurt Dışı']]];
db()->exec("DELETE FROM hks_kv");
db()->prepare("INSERT INTO hks_kv (anahtar, deger) VALUES ('listeler_cache', ?)")
   ->execute([json_encode($__uzun, JSON_UNESCAPED_UNICODE)]);
$formU = render_form($ID_BOS);
ok('[form] uzun listede yazarak arama açık',
   preg_match('/name="hks_urun_id"[^>]*data-aramali="Ürün yazın/u', $formU) === 1,
   '25 kayitlik listede hala duz acilir liste');
ok('[form] uzun listede de asıl select duruyor',
   preg_match('/<select[^>]*name="hks_urun_id"[^>]*>.*?URUN 25/s', $formU) === 1,
   'option listesi kaybolmus — datalist onerileri select den uretiliyor');

// Katalog boşken bile plaka girilebilmeli (katalogdan bağımsız alan).
db()->exec("DELETE FROM hks_kv");
$form2 = render_form($ID_BOS);
ok('[form] katalog boşken plaka yine girilebiliyor',
   substr_count($form2, 'name="vehicle_plate"') === 1,
   'katalog yokken plaka alani da kayboluyor — beyan bildirime asla hazirlanamaz');
ok('[form] katalog boşken yönlendirme yazıyor',
   mb_strpos($form2, 'Listeleri Güncelle') !== false);
// Önbelleği geri yükle
db()->prepare("INSERT INTO hks_kv (anahtar, deger) VALUES ('listeler_cache', ?)")
   ->execute([json_encode([
        'zaman' => '2026-07-01T10:00:00+03:00',
        'urunler' => [['id' => '10', 'ad' => 'Kayısı']], 'ulkeler' => [['id' => '20', 'ad' => 'Rusya']],
        'sifatlar' => [['id' => '7', 'ad' => 'İhracat']],
        'bildirimTurleri' => [['id' => '5', 'ad' => 'Satış']],
        'isletmeTurleri' => [['id' => '3', 'ad' => 'Yurt Dışı']],
   ], JSON_UNESCAPED_UNICODE)]);

// Plaka normalizasyonu — SUNUCU tarafı tek doğruluk kaynağı.
ok('plaka boşlukları siliniyor', beyan_plaka_normalize(' 34 abc 123 ') === '34ABC123',
   'gelen: [' . beyan_plaka_normalize(' 34 abc 123 ') . ']');
ok('plaka büyük harfe çevriliyor', beyan_plaka_normalize('34 tır 05') === '34TIR05',
   'gelen: [' . beyan_plaka_normalize('34 tır 05') . ']');
ok('boş plaka boş kalıyor', beyan_plaka_normalize(null) === '' && beyan_plaka_normalize('   ') === '');

// Kaydetme yollarının HEPSİ normalize etmeli (JS kapaliysa da bosluksuz gitsin).
foreach (['beyan_create.php', 'beyan_edit.php', 'api_beyan_bildirim.php'] as $ff) {
    ok("$ff plakayı normalize ediyor",
       strpos(file_get_contents(dirname(__DIR__) . '/' . $ff), 'beyan_plaka_normalize(') !== false,
       'bu yoldan bosluklu plaka kaydedilebilir');
}

// ── ÜLKE İPUCU ZİNCİRİ — gerçek WhatsApp beyan metniyle ──────────────────
// Kullanıcının paylaştığı örnek: şirket bloğu ülkeyi taşıyor.
$ORNEK = [
    'company_name'    => 'LLC "ZAPADNYE VOROTA"',
    'company_address' => "RUSSIA, 108811, G.MOSKVA, VN. TER. G. MUNICIPAL DISTRICT SOLNTSEVO, "
                       . "KIEVSKOE SHOSSE 23KM, D.8, STR.1 INN/KPP: 5004025693 / 775101001",
    'buyer_name'      => 'TOLGA KRASNODAR',
];
ok('adres ilk parçası ülke olarak çıkarılıyor',
   bb_adres_ulke_parcasi($ORNEK['company_address']) === 'RUSSIA',
   'gelen: [' . bb_adres_ulke_parcasi($ORNEK['company_address']) . ']');
ok('sokak satırı ülke sanılmıyor',
   bb_adres_ulke_parcasi('KIEVSKOE SHOSSE 23KM, D.8') === '',
   'rakam iceren satir anahtar olmamali');
ok('çok uzun ilk parça reddediliyor',
   bb_adres_ulke_parcasi(str_repeat('A', 40) . ', X') === '');

$adaylar = bb_ulke_adaylari($ORNEK, null);
$kaynaklar = array_column($adaylar, 'kaynak');
ok('adaylar arasında şirket ve adres var',
   in_array('sirket', $kaynaklar, true) && in_array('adres', $kaynaklar, true),
   'gelen kaynaklar: ' . implode(',', $kaynaklar));

// Öğrenilmemişken ülke ÇÖZÜLMEMELİ — "RUSSIA" katalogda "Rusya" olarak duruyor,
// TAHMİN YAPILMAZ. Kullanıcı bir kez seçer.
$ULKELER = [['id' => '20', 'ad' => 'Rusya']];
db()->exec("DELETE FROM hks_eslesme");
$t0 = bb_ulke_tahmin($adaylar, $ULKELER);
// ARTIK ÖĞRENMEDEN DE ÇÖZÜLMELİ: katalog Türkçe, metin İngilizce; aradaki
// köprü kanonik AD KARŞILIĞI tablosudur (tahmin değil, olgusal karşılık).
ok('öğrenilmeden RUSSIA → Rusya çözülüyor',
   $t0['id'] === '20' && $t0['kaynak'] === 'ad_karsiligi',
   'gelen: ' . json_encode($t0, JSON_UNESCAPED_UNICODE));

// Ad karşılığı tablosu doğrudan
ok('RUSSIAN FEDERATION karşılığı var',
   in_array('Rusya', bb_ulke_karsiligi('RUSSIAN FEDERATION'), true));
ok('nokta/boşluk temizleniyor',
   in_array('Rusya', bb_ulke_karsiligi('  RUSSIA.  '), true));
ok('ülke OLMAYAN metnin karşılığı YOK',
   bb_ulke_karsiligi('Yeni Beyan') === [] && bb_ulke_karsiligi('ASYA') === []
   && bb_ulke_karsiligi('TOLGA KRASNODAR') === [],
   'ulke olmayan metin ulkeye cozuluyor — yanlis pozitif');
// Parçalı eşleşme YOK — "Niger" ile "Nigeria" karışmamalı.
ok('parçalı eşleşme yapılmıyor',
   bb_ulke_karsiligi('RUSS') === [] && bb_ulke_karsiligi('RUSSIA FEDERATION X') === [],
   'alt-dize eslesmesi var — sessizce yanlis ulke secilebilir');

// Katalogda Türkçe karşılık YOKSA eşleşme de olmamalı (id uydurulmaz).
$t0b = bb_ulke_tahmin($adaylar, [['id' => '99', 'ad' => 'Almanya']]);
ok('katalogda karşılığı yoksa eşleşme yok', $t0b['id'] === '',
   'katalogda olmayan ulke icin id uretilmis');

// Kullanıcı bir kez seçer → öğrenilir → aynı adresli HER müşteride çalışır.
beyan_hks_ulke_ogren($ORNEK, '20', 'Rusya');
$t1 = bb_ulke_tahmin(bb_ulke_adaylari($ORNEK, null), $ULKELER);
ok('öğrendikten sonra ülke otomatik geliyor', $t1['id'] === '20',
   'ogrenme calismadi: ' . json_encode($t1, JSON_UNESCAPED_UNICODE));

// ASIL KAZANÇ: aynı ülkeye giden BAŞKA bir müşteri de çözülmeli (anahtar adres).
$BASKA = ['company_name' => 'OOO "DRUGAYA"', 'buyer_name' => 'BASKA ALICI',
          'company_address' => "RUSSIA, 190000, SANKT-PETERBURG, NEVSKIY PR. 1"];
$t2 = bb_ulke_tahmin(bb_ulke_adaylari($BASKA, null), $ULKELER);
ok('aynı ülkeye giden BAŞKA müşteri de çözülüyor', $t2['id'] === '20' && $t2['ipucu'] === 'adres',
   'adres anahtari musteriler arasi calismiyor: ' . json_encode($t2, JSON_UNESCAPED_UNICODE));

// HAM METİN TARAMASI: "RUSSIAN FEDERATION" hiçbir yapısal alana düşmüyor
// (parser eşleşmeyen satırlara atıyor) ama ülkeyi açıkça söylüyor.
$SADECE_METIN = ['unmatched_text' => "BRÜT. 21.800\nRUSSIAN FEDERATION\nASYA\nYeni Beyan"];
$tm = bb_ulke_tahmin(bb_ulke_adaylari($SADECE_METIN, null), $ULKELER);
ok('ham metindeki ülke satırı çözülüyor',
   $tm['id'] === '20' && $tm['ipucu'] === 'metin',
   'gelen: ' . json_encode($tm, JSON_UNESCAPED_UNICODE));

// Ülke içermeyen metin ASLA ülke üretmemeli.
$YOK = ['unmatched_text' => "BRÜT. 21.800\nASYA\nYeni Beyan\nMEHMET BEY\nDENİZYOLU"];
$ty = bb_ulke_tahmin(bb_ulke_adaylari($YOK, null), $ULKELER);
ok('ülkesiz metinden ülke UYDURULMUYOR', $ty['id'] === '',
   'yanlis pozitif: ' . json_encode($ty, JSON_UNESCAPED_UNICODE));

// Ham metin taraması DB'ye yük bindirmemeli: 'metin' adaylari icin ogrenme
// sorgusu ACILMAMALI (zaten hicbiri ogrenilmiyor).
$GENIS = ['unmatched_text' => implode("\n", array_map(fn($i) => "SATIR $i", range(1, 60)))];
$sorgu_once = 0;
$adaylar_g = bb_ulke_adaylari($GENIS, null);
ok('ham metin adayları sınırlanıyor', count($adaylar_g) <= 61,
   'aday sayisi: ' . count($adaylar_g));
ok("'metin' adayları öğrenme sorgusu açmıyor",
   strpos(file_get_contents(dirname(__DIR__) . '/config/helpers.php'),
          "\$a['kaynak'] !== 'metin'") !== false,
   'her metin parcasi icin ayri DB sorgusu aciliyor');

// Serbest metin ÖĞRENİLMEMELİ — "Yeni Beyan" her beyanda geçer, öğrenilseydi
// tüm beyanlara yanlış ülke ön-dolardı.
ok('serbest metin satırı öğrenilmiyor', hks_eslesme_bul('ulke', 'Yeni Beyan') === null,
   'her beyanda gecen bir satir ulke anahtari olmus');

// ── SİLME: beyan_delete.php YALNIZ POST kabul eder ───────────────────────
// GET ile cagirirsa sessizce listeye geri atar — kullanici tiklar, onaylar,
// HICBIR SEY OLMAZ ve hata da gormez. Duzenle ekranindaki Sil tam olarak bu
// yuzden calismiyordu (<a href="beyan_delete.php?id=...">).
foreach (['beyan_edit.php', 'beyan_view.php', 'beyanlar.php'] as $ff) {
    $fs = file_get_contents(dirname(__DIR__) . '/' . $ff);
    ok("[$ff] beyan_delete'e GET baglantisi YOK",
       preg_match('/<a[^>]+href="beyan_delete\.php/', $fs) !== 1,
       'GET ile silme sessizce basarisiz olur — POST kullan');
}
$ed = file_get_contents(dirname(__DIR__) . '/beyan_edit.php') . file_get_contents(dirname(__DIR__) . '/_beyan_form.php');
ok('[duzenle] Sil butonu POST ediyor',
   preg_match('/formaction="beyan_delete\.php"[^>]*formmethod="post"/s', $ed) === 1,
   'Sil hala POST etmiyor');
ok('[duzenle] form id alanini POST govdesinde tasiyor',
   preg_match('/<input type="hidden" name="id" value/', $ed) === 1,
   'beyan_delete $_POST[id] okur; id yalniz adres cubugundaysa "Gecersiz beyan ID" doner');
ok('[duzenle] silme yetki ile kapili',
   strpos($ed, "can_beyan('delete')") !== false);

// ENTER TUZAGI: bir metin kutusunda Enter'a basinca tarayici formun ILK submit
// butonunu tetikler. Sil once gelseydi Enter SILME baslatirdi.
preg_match_all('/<button[^>]*type="submit"[^>]*>/', $ed, $mb);
$ilk = $mb[0][0] ?? '';
ok('[duzenle] formun ILK submit butonu Sil DEGIL',
   $ilk !== '' && strpos($ilk, 'beyan_delete.php') === false,
   'Enter tusu silmeyi tetikler — ilk buton: ' . substr($ilk, 0, 80));

// Liste sayfasi: silme BILEREK yok (detay/duzenle ekranindan yapilir).
$liste_src = file_get_contents(dirname(__DIR__) . '/beyanlar.php');
$css_src   = file_get_contents(dirname(__DIR__) . '/assets/style.css');
ok('[liste] silme butonu yok (istenmedi)',
   strpos($liste_src, 'beyan_delete.php') === false,
   'listeye silme geri gelmis');

// Durum pilleri artik detayli filtrenin ICINDE olmali (liste ustunde degil).
ok('[liste] durum pilleri detayli filtre icinde',
   preg_match('/id="beyanFilterPanel".*?class="bff-durum"/s', $liste_src) === 1,
   'piller hala liste ustunde — ekranin yarisini kapliyordu');
ok('[liste] durum secilince panel acik geliyor',
   strpos($liste_src, '$detay_aktif ? \' bff-open\'') !== false,
   'durum filtresi etkinken panel kapali kalir, kullanici hangi filtrede oldugunu goremez');

// Kompakt filtre serigi: ac/kapa dugmesi arama satirinin ICINDE olmali ki
// kendine satir acmasin (masaustunde ~200px'lik blok bu yuzden kucuydu).
ok('[liste] filtre dugmesi arama satirinin icinde',
   preg_match('/class="bff-main".*?id="beyanFilterToggle".*?<\/div>/s', $liste_src) === 1,
   'toggle bff-main disinda — yeniden kendi satirini kapliyor');
// Detay paneli HER genislikte katlanmali: >=768px'de toggle'i gizleyen kural
// geri gelirse panel masaustunde kalici acik olur.
ok('[liste] toggle masaustunde gizlenmiyor',
   preg_match('/min-width:\s*768px\s*\)\s*\{[^}]*\.beyan-filter-toggle[^}]*display:\s*none/s', $css_src) !== 1,
   'toggle >=768px gizlenmis — panel masaustunde kalici acik kalir');
ok('[liste] detay paneli varsayilan kapali',
   preg_match('/\.beyan-filter-form \.bff-filters \{[^}]*display:\s*none/s', $css_src) === 1,
   'panel varsayilan acik — liste ustu yine dolar');
// On durum pili sarmamali; tasinca yatay kaymali.
ok('[liste] durum serigi sarmiyor (yatay kayar)',
   preg_match('/> \.bff-durum \{[^}]*flex-wrap:\s*nowrap[^}]*overflow-x:\s*auto/s', $css_src) === 1,
   'piller sariyor — uc satira yayilir');
// Panel kapaliyken hangi filtrenin etkin oldugu dugmede yazmali.
ok('[liste] etkin filtre dugmede yaziyor',
   strpos($liste_src, 'bft-rozet') !== false && strpos($liste_src, '$detay_ilk') !== false,
   'panel kapaliyken kullanici hangi filtrede oldugunu goremez');

// ── Liste sayfasi RENDER testleri ─────────────────────────────────────────
$liste_kapali = render_liste([]);
file_put_contents(sys_get_temp_dir() . '/beyan_liste_ciktisi.html', $liste_kapali);
ok('[durum-menü] liste: masaüstü durum rozeti açılır menü + return_to=liste', strpos($liste_kapali, 'class="bl-durum"') !== false && (
   strpos($liste_kapali, 'name="return_to" value="liste"') !== false && strpos($liste_kapali, 'bl-durum-sec') !== false));
$PERMS_D = $PERMS; $PERMS = ['beyan.read'];
ok('[durum-menü] beyan.status yoksa menü YOK (düz rozet)', strpos(render_liste([]), 'class="bl-durum"') === false);
$PERMS = $PERMS_D;
// Durum filtresi arsiv bolumunun sifatidir — gecerli deger KAPALI bir durum.
$liste_acik   = render_liste(['status' => 'yuklendi']);
foreach (['filtresiz' => $liste_kapali, 'durum filtreli' => $liste_acik] as $ad => $html) {
    if (strncmp($html, '__HATA__', 8) === 0) { ok("[liste:$ad] render edildi", false, substr($html, 8)); continue; }
    ok("[liste:$ad] render edildi", strlen($html) > 500);
    ok("[liste:$ad] PHP uyarisi yok",
       stripos($html, 'Warning:') === false && stripos($html, 'Notice:') === false
       && stripos($html, 'Deprecated:') === false && stripos($html, 'Fatal error') === false,
       'HTML icinde PHP hatasi var');
    $bozuk = etiket_dengesi($html);
    ok("[liste:$ad] HTML etiket dengesi", empty($bozuk), implode(' | ', $bozuk));
}
// Filtre yokken panel KAPALI gelmeli — liste ustu bos kalir.
ok('[liste:filtresiz] detay paneli kapali',
   strpos($liste_kapali, 'bff-filters bff-open') === false
   && strpos($liste_kapali, 'bft-rozet') === false,
   'panel filtre yokken acik geliyor — eski kalabalik geri gelmis');
// Durum secilince panel acik gelmeli ve dugmede etkin filtre yazmali.
ok('[liste:durum filtreli] panel acik + rozet var',
   strpos($liste_acik, 'bff-filters bff-open') !== false
   && strpos($liste_acik, 'bft-rozet') !== false,
   'durum etkinken panel kapali ya da rozet yok');
// Durum pilleri toggle DEGIL, bagimsiz baglanti olarak kalmali (JS'siz calisir).
ok('[liste] durum pilleri bagimsiz baglanti',
   preg_match('/class="bff-durum".*?<a href="beyanlar\.php\?status=yuklendi"/s', $liste_kapali) === 1,
   'piller <a> olmaktan cikmis — JS kapaliyken filtre calismaz');
// Durum pilleri ALT bolumun (arsiv) sifatidir: yalnız kapali durumlar. Bekleyen
// bir durum pili secilse arsiv sebepsiz bos gorunurdu.
// Eski bir yer imi (?status=taslak) filtre olarak UYGULANMAZ: arsiv sebepsiz
// bos gorunmesin. Ust bolum o beyani zaten gosteriyor.
$liste_eski = render_liste(['status' => 'taslak']);
ok('[liste] bekleyen durum yer imi filtre olarak uygulanmiyor',
   strncmp($liste_eski, '__HATA__', 8) !== 0
   && strpos($liste_eski, 'bff-filters bff-open') === false
   && strpos($liste_eski, 'bft-rozet') === false,
   'gecersiz durum filtresi uygulanmis — arsiv sebepsiz bos kalir');

ok('[liste] durum pilleri yalniz kapali durumlar',
   strpos($liste_kapali, 'status=taslak') === false
   && strpos($liste_kapali, 'status=temiz') === false,
   'bekleyen durum pili arsiv filtresinde — secilince liste sebepsiz bosalir');

// ── IKI BOLUMLU LISTE: yuklenmeyenler ustte, yuklenenler + filtre altta ────
// Bolum sirasi ve filtrenin hangi bolume ait oldugu bu ozelligin TAMAMI;
// ters donerse kullanici islem bekleyenleri arsivin altinda arar.
$bek_poz = strpos($liste_kapali, 'beyan-blok-acik');
$kap_poz = strpos($liste_kapali, 'beyan-blok-kapanmis');
$flt_poz = strpos($liste_kapali, 'beyan-filter-form');
ok('[liste] iki bolum de cizildi', $bek_poz !== false && $kap_poz !== false,
   'bolumler yok — sayfa tek listeye donmus');
ok('[liste] yuklenmeyenler bolumu USTTE',
   $bek_poz !== false && $kap_poz !== false && $bek_poz < $kap_poz,
   'arsiv bolumu one gecmis — islem bekleyenler asagida kalir');
ok('[liste] filtre serigi ALT bolumun icinde',
   $flt_poz !== false && $kap_poz !== false && $flt_poz > $kap_poz,
   'filtre ust bolume tasinmis — bekleyen is listesi filtreyle eksilir gorunur');

// Ust bolum filtreden BAGIMSIZ: bir arama ust bolumu eksiltmemeli.
$liste_ara = render_liste(['q' => 'ZZZ_HICBIRSEY_' . mt_rand()]);
if (strncmp($liste_ara, '__HATA__', 8) === 0) {
    ok('[liste:arama] render edildi', false, substr($liste_ara, 8));
} else {
    ok('[liste:arama] PHP uyarisi yok',
       stripos($liste_ara, 'Warning:') === false && stripos($liste_ara, 'Notice:') === false
       && stripos($liste_ara, 'Deprecated:') === false && stripos($liste_ara, 'Fatal error') === false);
    $bozuk = etiket_dengesi($liste_ara);
    ok('[liste:arama] HTML etiket dengesi', empty($bozuk), implode(' | ', $bozuk));
    // Test verisinde bekleyen (taslak) beyanlar var; eslesmeyen arama onlari
    // GIZLEMEMELI, yalnız arsiv bolumunu bosaltmali.
    ok('[liste:arama] ust bolum aramadan etkilenmiyor',
       strpos($liste_ara, 'beyan-blok-acik') !== false
       && preg_match('/beyan-blok-acik.*?class="bb-sec-col"/s', $liste_ara) === 1,
       'eslesmeyen arama islem bekleyen listesini de bosaltti');
    ok('[liste:arama] arsiv bolumu bos mesaji veriyor',
       preg_match('/beyan-blok-kapanmis.*?beyan-blok-bos/s', $liste_ara) === 1,
       'eslesme yokken arsiv bos durumu gostermiyor');
}

// Iki bolumde de "tumu" kutusu olabilir — id DEGIL sinif kullanilmali,
// yoksa ayni id iki kez cizilir ve JS yalnız birini gorur.
ok('[liste] "tumu" kutusu sinifla (id tekrarı yok)',
   strpos($liste_src, 'id="bbTumu"') === false
   && strpos(file_get_contents(dirname(__DIR__) . '/_beyan_liste.php'), 'class="bb-tumu"') !== false,
   'bbTumu id olarak kalmis — iki bolumde tekrarlanir, HTML gecersiz olur');
ok('[liste] satir bicimi tek yerde (partial)',
   is_file(dirname(__DIR__) . '/_beyan_liste.php')
   && substr_count($liste_src, "_beyan_liste.php") === 2,
   'satir/kart biçimi kopyalanmis — dort kopya ayrisir');

// ── HKS BAĞ YAŞAM DÖNGÜSÜ (taslak düzenleme / silme / elle düzeltme) ──────
// Hata: Hal Kayıt'ta "Düzenle" yeni id'li taslak kaydedip eskisini siliyordu;
// silme bağı 'iptal'e çekiyor, gönderilen yeni taslak beyana yansımıyordu →
// HKS'e gönderilmiş beyan yeniden "bildirime uygun" görünüyordu.
db()->exec("ALTER TABLE hks_gonderilenler ADD COLUMN plaka TEXT");
$bag = fn(int $b) => beyan_hks_aktif($b);
$yeni_bag = function (int $b, string $tid): void {
    db()->prepare("INSERT INTO beyan_hks_bildirim (beyan_id,hks_firma_id,taslak_id,durum,urun_ad,plaka,kg)
                   VALUES (?,?,?,'taslak','Kayısı','34ABC123',19800)")->execute([$b, 'f1', $tid]);
};

// 1) Düzenle → bağ yeni taslağa taşınır, eski taslağın silinmesi dokunmaz,
//    yeni taslağın gönderimi bağı 'gonderildi' yapar.
$B1 = beyan_ekle($TAM); $yeni_bag($B1, 'eski1');
beyan_hks_taslak_tasi('eski1', 'yeni1');
beyan_hks_taslak_isaretle('eski1', 'silindi', ['hata_metni' => 'x']);
ok('[bag] duzenlemede bag yeni taslaga tasiniyor', ($bag($B1)['taslak_id'] ?? '') === 'yeni1'
   && ($bag($B1)['durum'] ?? '') === 'taslak', json_encode($bag($B1)));
beyan_hks_taslak_isaretle('yeni1', 'gonderildi', ['gonderim_id' => 'g1']);
ok('[bag] duzenlenen taslagin gonderimi beyana yansiyor', ($bag($B1)['durum'] ?? '') === 'gonderildi');

// 2) Silinen taslak beyanı yeniden AÇMAZ ('silindi' aktif sayılır).
$B2 = beyan_ekle($TAM); $yeni_bag($B2, 'sil2');
beyan_hks_taslak_isaretle('sil2', 'silindi', ['hata_metni' => 'silindi']);
ok('[bag] silinen taslak beyani bildirime acmiyor', ($bag($B2)['durum'] ?? '') === 'silindi');
$h_sil = render_beyan($B2);
ok('[bag] silinmis taslakta Bildirim Yap pasif ve sebep yaziyor',
   buton_pasif($h_sil) === true && mb_strpos($h_sil, 'Tekrar Aktif Et') !== false);
$f_sil = render_form($B2);
// Not alanı (v332): formun altında textarea; önizlemede yalnız doluyken görünür.
ok('[not] formda Not alanı (textarea name=note, 2000 sınırlı) var',
   preg_match('/<textarea[^>]*name="note"[^>]*maxlength="2000"/', $f_sil) === 1);
$B_NOT = beyan_ekle(['party_no' => 'NOT-1']);
db()->prepare("UPDATE customs_declarations SET note = ? WHERE id = ?")->execute(["İlk satır\nİKİ <b>x</b>", $B_NOT]);
$h_not = render_beyan($B_NOT);
ok('[not] önizlemede not görünüyor ve kaçışlı', mb_strpos($h_not, 'İlk satır') !== false && strpos($h_not, '&lt;b&gt;x&lt;/b&gt;') !== false);
ok('[not] boş notta bölüm çizilmiyor', strpos(render_beyan($ID_BOS), 'bf-emoji" aria-hidden="true">📝</span> Not') === false);
ok('[not] düzenleme formu kayıtlı notu dolduruyor', mb_strpos(render_form($B_NOT), 'İlk satır') !== false);
// Hızlı plaka girişi (v340)
$B_PL = beyan_ekle(['party_no' => 'PL-1']);
db()->prepare("UPDATE customs_declarations SET vehicle_plate = NULL WHERE id = ?")->execute([$B_PL]);
$h_pl = render_beyan($B_PL);
file_put_contents(sys_get_temp_dir() . '/beyan_view_plaka.html', $h_pl);
ok('[plaka] eksik plakada tıklanabilir hücre + hızlı pencere', strpos($h_pl, 'id="plakaHizliBtn"') !== false && strpos($h_pl, 'id="plakaHizliDlg"') !== false
   && strpos($h_pl, 'api_beyan_plaka.php') !== false);
db()->prepare("UPDATE customs_declarations SET vehicle_plate = '34ABC123' WHERE id = ?")->execute([$B_PL]);
$h_pl2 = render_beyan($B_PL);
ok('[plaka] plaka doluyken hücre düz ve pencere yok', strpos($h_pl2, 'plakaHizliBtn') === false && strpos($h_pl2, '34ABC123') !== false);
db()->prepare("UPDATE customs_declarations SET vehicle_plate = NULL, deleted_at = '2026-01-01 00:00:00' WHERE id = ?")->execute([$B_PL]);
ok('[plaka] arşivlenmiş beyanda hızlı giriş yok', strpos(render_beyan($B_PL), 'plakaHizliBtn') === false);
$api_pl = (string)file_get_contents($ROOT . '/api_beyan_plaka.php');
ok('[plaka] uç: POST + CSRF + yetki + yalnız vehicle_plate + audit',
   strpos($api_pl, "REQUEST_METHOD'] !== 'POST'") !== false && strpos($api_pl, 'csrf_check(') !== false
   && strpos($api_pl, "can_beyan('write') && can_beyan('hks.write')") !== false
   && preg_match('/UPDATE customs_declarations SET vehicle_plate = \?, updated_by = \?, updated_at = NOW\(\) WHERE id = \? AND deleted_at IS NULL/', $api_pl) === 1
   && strpos($api_pl, 'beyan_plaka_normalize(') !== false && strpos($api_pl, 'audit_log_event(') !== false);
// Form temizliği (v340): parantez içi ipuçları ve alt metinler yok
$f_tmp = render_form($ID_BOS);
ok('[temiz] düzenleme formunda ipucu/alt metin yok',
   strpos($f_tmp, 'isteğe bağlı') === false && strpos($f_tmp, 'Red durumunda zorunlu') === false
   && strpos($f_tmp, 'HKS hazırlığı için') === false && strpos($f_tmp, 'Silme işlemi beyanı') === false
   && strpos($f_tmp, 'katalogdan eşleşti') === false && strpos($f_tmp, 'Akıştaki sıradaki durum') === false
   && strpos($f_tmp, 'Dolu alanlar WhatsApp') === false && strpos($f_tmp, 'Mesajı yapıştırıp') === false);
// Gönderici / Ülke / Çekici / Dorse (v343)
$B_LJ = beyan_ekle(['party_no' => 'LJ-1']);
db()->prepare("UPDATE customs_declarations SET sender_company_name='ÖZEL FİRMA LTD', tractor_plate='06TRK06', vehicle_plate='34DRS34', hks_ulke_ad='Rusya' WHERE id=?")->execute([$B_LJ]);
$h_lj = render_beyan($B_LJ);
ok('[lojistik] önizleme: Gönderici Firma, Ülke, Çekici Plaka, Dorse Plaka listeleniyor',
   preg_match('/Lojistik \/ Alıcı.*Gönderici.*ÖZEL FİRMA LTD.*Ülke.*Rusya.*Çekici Plaka.*06TRK06.*Dorse Plaka.*34DRS34/s', $h_lj) === 1);
ok('[lojistik] WhatsApp metnine gönderici/çekici/dorse/ülke EKLENMEDİ',
   preg_match('/id="waMetin">(.*?)<\/script>/s', $h_lj, $mw) === 1
   && strpos($mw[1], 'ÖZEL') === false && strpos($mw[1], '06TRK06') === false && strpos($mw[1], '34DRS34') === false && stripos($mw[1], 'Rusya') === false);
db()->exec("INSERT INTO hks_firmalar (id,ad,vergi_no) VALUES ('f9','Agronatural Tarım','9999999999')");
$g1 = beyan_gonderici_coz('AGRONATURAL TARIM');
$g2 = beyan_gonderici_coz('Başka Firma');
ok('[gönderici] HKS firma adı TR-duyarsız eşleşince id çözülür, resmi ad döner', $g1['id'] === 'f9' && $g1['ad'] === 'Agronatural Tarım');
ok('[gönderici] listede olmayan firma adı serbest kabul edilir (id boş)', $g2['id'] === '' && $g2['ad'] === 'Başka Firma');
$kat_u = [['id' => '1', 'ad' => 'Biber Salçalık (Kapya)'], ['id' => '2', 'ad' => 'Biber Dolmalık'], ['id' => '3', 'ad' => 'Elma Starking'], ['id' => '4', 'ad' => 'Elma Golden']];
ok('[benzer] "Kapya" → "Biber Salçalık (Kapya)"', (bb_urun_benzer('Kapya', $kat_u)['id'] ?? '') === '1');
ok('[benzer] "kapya biber" tek adaya iner', (bb_urun_benzer('kapya biber', $kat_u)['id'] ?? '') === '1');
ok('[benzer] belirsiz ("Elma") eşleşme YOK', bb_urun_benzer('Elma', $kat_u) === null);
ok('[benzer] alakasız ad eşleşmez', bb_urun_benzer('Portakal', $kat_u) === null);
ok('[benzer] bb_tahmin urun için benzer kaynağı döner', (bb_tahmin('urun', 'Kapya', $kat_u, false)['kaynak'] ?? '') === 'benzer');
ok('[benzer] ülkede benzer eşleşme YOK', bb_tahmin('ulke', 'Rus', [['id' => '9', 'ad' => 'Rusya']], false)['id'] === '');
// Numune (v334)
ok('[numune] formda 4 numune alanı BEYAN sekmesinde (Ürün ve Miktarlar sonrası, Analiz sekmesinden önce)', ($pn = strpos($f_sil, 'name="sample_crate_count"')) !== false
   && strpos($f_sil, 'name="sample_crate_type"') !== false && strpos($f_sil, 'name="sample_gross_kg"') !== false
   && strpos($f_sil, 'name="sample_net_kg"') !== false && $pn > strpos($f_sil, 'name="net_kg"') && $pn < strpos($f_sil, 'id="beyanPanel_analiz"'));
ok('[numune] boşken önizlemede numune kartı yok', strpos(render_beyan($ID_BOS), '🧪</span> Numune') === false);
$B_NM = beyan_ekle(['party_no' => 'NM-1']);
db()->prepare("UPDATE customs_declarations SET sample_gross_kg=5, sample_net_kg=4, sample_crate_type='TAHTA KASA', sample_crate_count=1 WHERE id=?")->execute([$B_NM]);
$h_nm = render_beyan($B_NM);
file_put_contents(sys_get_temp_dir() . '/beyan_view_nm.html', $h_nm);
ok('[numune] önizlemede Numune Bilgileri kartı Ürün Bilgileri ile Lojistik arasında, tek satır ızgara',
   preg_match('/Ürün Bilgileri.*Numune Bilgileri.*Kasa Adedi.*Net KG.*Lojistik \/ Alıcı.*Analiz \/ Durum/s', $h_nm) === 1
   && strpos($h_nm, 'bv-yanyana') === false);
ok('[numune] doluyken önizlemede kart + WhatsApp metni', strpos($h_nm, '🧪</span> Numune') !== false
   && strpos($h_nm, 'Net: 4 KG') !== false && strpos($h_nm, '\ud83e\uddea') === false && strpos($h_nm, '🧪 *NUMUNE*') !== false);
file_put_contents(sys_get_temp_dir() . '/beyan_form_ciktisi.html', render_form($B_NM));
ok('[numune] düzenleme formu kayıtlı numuneyi dolduruyor', strpos(render_form($B_NM), 'value="TAHTA KASA"') !== false);
ok('[form] kaldırılan başlık alanı çizilmiyor', strpos($f_sil, 'name="declaration_title"') === false);
ok('[form] gümrük adı/adresi görünür ve önerili',
   strpos($f_sil, '>Gümrük Adı</label>') !== false
   && strpos($f_sil, '>Gümrük Adresi</label>') !== false
   && strpos($f_sil, '<summary>Şirket ve iletişim') === false
   && strpos($f_sil, 'data-suggest="company_address"') !== false);
ok('[form] analiz tarihini kaydıran açıklama kaldırılmış',
   strpos($f_sil, 'Temiz/Red seçilince boşsa otomatik doldurulur.') === false);
ok('[bag] bağlantı düzeltme alanı kullanıcı isteğiyle gizli',
   (bool)preg_match('/<details[^>]*class="beyan-workspace-bag"[^>]*\bhidden\b/', $f_sil));
ok('[bag] duzenle ekraninda Tekrar Aktif Et var',
   strpos($f_sil, 'value="tekrar_aktif"') !== false && strpos($f_sil, 'value="gonderildi_isaretle"') === false);
ok('[bag] duzenle ekrani HTML dengesi', empty(etiket_dengesi($f_sil)), implode(' | ', etiket_dengesi($f_sil)));
ok('[bag] tekrar aktif et beyani acıyor',
   beyan_hks_bag_duzelt($B2, 'tekrar_aktif', 1) === null && $bag($B2) === null);
ok('[bag] gonderilmis bildirim tekrar aktif edilemiyor',
   beyan_hks_bag_duzelt($B1, 'tekrar_aktif', 1) !== null && ($bag($B1)['durum'] ?? '') === 'gonderildi');

// 3) Eski kopmuş kayıt (bugünkü 4 beyan): bağ 'iptal', HKS'e gönderilmiş.
$B3 = beyan_ekle($TAM + ['party_no' => '25-34']);
db()->prepare("INSERT INTO beyan_hks_bildirim (beyan_id,hks_firma_id,taslak_id,durum,urun_ad,plaka,kg,created_at)
               VALUES (?,?,?,'iptal','Kayısı','34ABC123',19800,'2026-09-05 12:00')")->execute([$B3, 'f1', 'kopuk3']);
db()->exec("INSERT INTO hks_gonderilenler (id,zaman,firma_id,urun_ad,plaka) VALUES
            ('g3','2026-09-05 13:00','f1','Kayısı','34 ABC 123'),
            ('g3b','2026-09-05 13:30','f1','Kiraz','34ABC123')");
$f_kop = render_form($B3);
ok('[bag] kopuk kayitta Gonderildi Olarak Isaretle var', strpos($f_kop, 'value="gonderildi_isaretle"') !== false);
ok('[bag] elle gonderildi isaretleme',
   beyan_hks_bag_duzelt($B3, 'gonderildi_isaretle', 1) === null && ($bag($B3)['durum'] ?? '') === 'gonderildi');
ok('[bag] isaretlemede tek eslesen gonderim baglaniyor', ($bag($B3)['gonderim_id'] ?? '') === 'g3',
   json_encode($bag($B3)));
ok('[bag] liste rozeti tazeleniyor',
   db()->query("SELECT hks_durum FROM customs_declarations WHERE id=$B3")->fetchColumn() === 'gonderildi');
ok('[bag] aktif bagi olan beyan tekrar isaretlenemiyor', beyan_hks_bag_duzelt($B3, 'gonderildi_isaretle', 1) !== null);

// 4) Hiç bağı olmayan beyan da işaretlenebilir (yeni satır açılır).
$B4 = beyan_ekle($TAM);
ok('[bag] bagsiz beyan gonderildi isaretlenebiliyor',
   beyan_hks_bag_duzelt($B4, 'gonderildi_isaretle', 1) === null && ($bag($B4)['durum'] ?? '') === 'gonderildi');

// 5) Gönderimde yedek yol: taslak id'si tutmasa da kaynak.beyanId ile sonuçlanır.
$B5 = beyan_ekle($TAM); $yeni_bag($B5, 'x5');
db()->exec("UPDATE beyan_hks_bildirim SET durum='iptal' WHERE beyan_id=$B5");
beyan_hks_taslak_isaretle('baska5', 'gonderildi', ['gonderim_id' => 'g5', 'beyanId' => $B5]);
ok('[bag] kaynak izi ile gonderim beyana baglaniyor', ($bag($B5)['gonderim_id'] ?? '') === 'g5');
// Silmede yedek yol KULLANILMAZ
$B6 = beyan_ekle($TAM); $yeni_bag($B6, 'x6');
beyan_hks_taslak_isaretle('baska6', 'silindi', ['beyanId' => $B6]);
ok('[bag] silmede yedek yol baska taslagin bagini kapatmiyor', ($bag($B6)['durum'] ?? '') === 'taslak');

// 6) Kaynak kodda köprü uçları
$api = (string)file_get_contents(dirname(__DIR__) . '/halkayit/api.php');
$spa = (string)file_get_contents(dirname(__DIR__) . '/halkayit/app.html');
ok('[bag] api: taslak_kaydet eskiTaslakId ile bagi tasiyor',
   strpos($api, 'beyan_hks_taslak_tasi($eskiId') !== false && strpos($api, "\$g['ortak']['kaynak'] = \$eskiVeri") !== false);
ok('[bag] api: taslak silme bagi silindi yapiyor (iptal degil)',
   preg_match("/taslak_isaretle\(\(string\)\(\\\$g\['id'\] \?\? ''\), 'silindi'/", $api) === 1);
ok('[bag] SPA: iki kayit yolu da eskiTaslakId gonderiyor', substr_count($spa, 'eskiTaslakId') >= 2);

// Büyük bekleyen listesi: ilk kayda sayfa ve arama yoluyla erişilebilmeli.
for ($i = 0; $i < 205; $i++) beyan_ekle(['party_no' => 'SAYFA-' . $i]);
$son_sayfa = render_liste(['bek_page' => '999']);
ok('[liste] 200 kayıt sınırının gerisindeki beyan erişilebilir',
   strpos($son_sayfa, 'beyan_view.php?id=' . $ID_BOS . '"') !== false);
$eski_ara = render_liste(['bek_q' => 'SAYFA-0']);
ok('[liste] bekleyen araması eski kaydı buluyor',
   strpos($eski_ara, '>SAYFA-0</a>') !== false && strpos($eski_ara, '>SAYFA-204</a>') === false);
ok('[liste] bekleyen sayfalama bağlantısı var',
   strpos($son_sayfa, 'bek_page=') !== false);
ok('[liste] kapanan başlığı red ve iptali yüklenmiş diye adlandırmıyor',
   strpos($son_sayfa, 'Kapanan Beyanlar') !== false);

// Plan araması son 90 gün/200 satırla sınırlanmamalı.
db()->exec("INSERT INTO loading_records (tarih,firma,parti_no,urun,durum) VALUES ('2020-01-01','ESKİ PLAN','ESKI-PLAN-1','KAYISI','yeni')");
for ($i = 0; $i < 55; $i++) db()->exec("INSERT INTO loading_records (tarih,firma,parti_no,urun,durum) VALUES ('2026-07-01','PLAN','YENI-$i','KAYISI','yeni')");
$eski_plan = render_beyan($ID_BOS, ['match_q' => 'ESKI-PLAN-1', 'match_open' => '1']);
ok('[eşleştirme] eski plan tüm tarihlerde bulunuyor',
   strpos($eski_plan, 'data-parti="ESKI-PLAN-1"') !== false && strpos($eski_plan, 'data-parti="YENI-54"') === false);
db()->exec("UPDATE customs_declarations SET status='yuklendi' WHERE id=$ID_BOS");
$yuklu_bagsiz = render_beyan($ID_BOS);
ok('[eşleştirme] yüklendi işaretli bağlantısız beyan eşleştirilebilir', strpos($yuklu_bagsiz, 'id="beyanMatchOpenBtn"') !== false);
$PERMS = ['beyan.read', 'beyan.write'];
ok('[eşleştirme] plan düzenleme yetkisi olmadan eşleştirme açılmaz', strpos(render_beyan($ID_BOS), 'id="beyanMatchOpenBtn"') === false);

// Detailed roles: protected content must never reach HTML or inline scripts.
db()->exec("UPDATE customs_declarations SET buyer_name='CONTACT_SECRET_942', company_address='ADDRESS_SECRET_942', contact_person='PERSON_SECRET_942', analysis_note='ANALYSIS_SECRET_942', raw_text='SOURCE_SECRET_942', unmatched_text='UNMATCHED_SECRET_942', hks_ulke_ad='COUNTRY_SECRET_942' WHERE id=$ID_BOS");
$PERMS = ['beyan.read', 'beyan.edit', 'beyan.permissions.detailed'];
db()->exec("UPDATE customs_declarations SET sample_crate_type='SAMPLE_SECRET_942', sample_gross_kg=9, sample_net_kg=8, sample_crate_count=2 WHERE id=$ID_BOS");
$restricted_detail = render_beyan($ID_BOS);
$restricted_list = render_liste([]);
foreach (['CONTACT_SECRET_942','ADDRESS_SECRET_942','PERSON_SECRET_942','ANALYSIS_SECRET_942','SOURCE_SECRET_942','UNMATCHED_SECRET_942','COUNTRY_SECRET_942','SAMPLE_SECRET_942'] as $secret) {
    ok('[yetki] gizli veri HTML/JS içinde yok: ' . $secret, !str_contains($restricted_detail . $restricted_list, $secret));
}
ok('[yetki] HKS kartı ve tablo başlığı gizli', !str_contains($restricted_detail, 'bk-kart') && !str_contains($restricted_list, '<th>HKS Firma</th>'));
ok('[yetki] durum, eşleştirme ve bildirim işlemleri kapalı', !str_contains($restricted_detail, 'class="beyan-durum-form"') && !str_contains($restricted_detail, 'id="beyanMatchOpenBtn"') && !str_contains($restricted_detail, 'id="hksOpenBtn"'));
ok('[yetki] genel düzenleme izni kopyalama izni vermez', !str_contains($restricted_detail . $restricted_list, 'action="beyan_kopyala.php"'));

$PERMS = ['beyan.read', 'beyan.status', 'beyan.permissions.detailed'];
$status_role = render_beyan($ID_BOS);
ok('[yetki] sadece durum yetkisi durum formunu açar', str_contains($status_role, 'class="beyan-durum-form"') && !str_contains($status_role, 'id="beyanMatchOpenBtn"'));
$PERMS = ['beyan.read', 'beyan.match', 'records.write', 'beyan.permissions.detailed'];
$match_role = render_beyan($ID_BOS);
ok('[yetki] sadece eşleştirme yetkisi durum formunu açmaz', !str_contains($match_role, 'class="beyan-durum-form"') && str_contains($match_role, 'id="beyanMatchOpenBtn"'));

echo "\n" . ($fail === 0 ? "TUM TESTLER GECTI\n" : "$fail TEST BASARISIZ\n");
exit($fail === 0 ? 0 : 1);
