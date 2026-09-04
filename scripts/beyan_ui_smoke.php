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
function render_beyan(int $id): string {
    global $ROOT;
    static $tmp = null;
    if ($tmp === null) {
        $src = (string)file_get_contents($ROOT . '/beyan_view.php');
        $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
        $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
        $tmp = sys_get_temp_dir() . '/beyan_view_smoke.php';
        file_put_contents($tmp, $src);
    }
    $_GET = ['id' => (string)$id];
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
    if (!preg_match('/<button[^>]*>\s*🏛 Bildirim Yap\s*<\/button>/u', $html, $m)) return null;
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
    ok("[$ad] Bildirim Yap butonu var", $pasif !== null, 'buton hic render edilmemis');
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
    ok("[$ad] kartta 4 alan var", substr_count($html, 'class="bk-hucre') === 4,
       'kart alan sayisi: ' . substr_count($html, 'class="bk-hucre'));
}

// Geçmiş tablosu yalnız bildirim varken
$h1 = render_beyan($ID_TAM); $h2 = render_beyan($ID_BILDIRIM);
ok('geçmiş tablosu yalnız bildirim varken görünür',
   strpos($h1, 'beyan-badge">HKS TASLAK') === false && strpos($h2, 'HKS TASLAK') !== false,
   'gecmis tablosu yanlis kosulda ciziliyor');

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
    ok('[form] plaka Hal Bildirim bölümünde',
       preg_match('/Hal Bildirim Bilgileri.*?name="vehicle_plate"/s', $form) === 1,
       'plaka hala Temel Bilgiler bolumunde');

    // Dört alan da orada mı?
    foreach (['hks_firma_id', 'hks_urun_id', 'hks_ulke_id', 'vehicle_plate'] as $alan) {
        ok("[form] $alan alanı var", strpos($form, 'name="' . $alan . '"') !== false);
    }
}

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
ok('öğrenilmeden ülke tahmin EDİLMİYOR', $t0['id'] === '',
   'ingilizce ad turkce katalogla eslestirilmis — tahmin yapiliyor');

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

// Serbest metin ÖĞRENİLMEMELİ — "Yeni Beyan" her beyanda geçer, öğrenilseydi
// tüm beyanlara yanlış ülke ön-dolardı.
ok('serbest metin satırı öğrenilmiyor', hks_eslesme_bul('ulke', 'Yeni Beyan') === null,
   'her beyanda gecen bir satir ulke anahtari olmus');

echo "\n" . ($fail === 0 ? "TUM TESTLER GECTI\n" : "$fail TEST BASARISIZ\n");
exit($fail === 0 ? 0 : 1);
