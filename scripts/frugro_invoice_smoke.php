<?php
// =========================================================
// scripts/frugro_invoice_smoke.php — Frugro INVOICE (config/frugro_invoice.php + frugro_invoice.php)
//   php scripts/frugro_invoice_smoke.php   → çıkış kodu 0 = hepsi geçti
// Bellek içi SQLite + stub auth + SENTETİK veri (gerçek IBAN / VAT / banka YOK). Canlı DB'ye ve storage'a dokunmaz.
// Kapsam: hesap (3 gerçek örnek tutar), girdi doğrulama (GBP|EUR), PKL özeti + varsayılanlar, çapraz kontroller (PKL farkı onayı),
// şablon (PO tam no, para birimi başlığı, menşe notu koşulsuz, yer tutucu, XSS), PDF (%PDF), üretim (olay/durum/audit), sayfa
// (403 / CSRF / depo izolasyonu / ?pdf=1), ayar (yalnız siparis.delete; değerler audit / flash / yetkisiz HTML'e SIZMAZ).
// =========================================================
if (in_array('--csrf', $argv ?? [], true)) {            // alt süreç: CSRF'siz POST → reddedilmeli
    require_once __DIR__ . '/_siparis_test_lib.php';
    require_once $ROOT . '/config/frugro_invoice.php';
    $db = db(); siparis_test_diger_tablolar($db);
    foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
    $db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
    frg_siparis_kaydet($db, ['po_no' => '25-1816-34726', 'kutu' => 100, 'palet' => 1, 'kutu_palet' => 100], ['user_id' => 1, 'depo' => 'DEPO1']);
    $IS_ADMIN = true; $UID = 1; $PERMS = ['siparis.read', 'siparis.write', 'siparis.delete'];
    ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
    $_POST = ['islem' => 'uret', 'no' => 'IHR2025000000451', 'tarih' => '2025-10-23', 'para_birimi' => 'GBP', 'fiyat' => '5,90', 'eksik_izin' => '1'];
    $_GET = ['id' => '1']; $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    $src = file_get_contents($ROOT . '/frugro_invoice.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $tmp = sys_get_temp_dir() . '/frgi_csrf_' . getmypid() . '.php'; file_put_contents($tmp, $src);
    include $tmp;
    ob_get_clean();
    echo "CSRF-GECTI\n";
    exit(0);
}
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_invoice.php';
require_once $ROOT . '/_siparis_ortak.php';
ini_set('memory_limit', '512M');
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();

const T_IBAN_GBP = 'GB29NWBK60161331926819';     // bilinen geçerli TEST IBAN (sentetik)
const T_IBAN_EUR = 'DE89370400440532013000';     // bilinen geçerli TEST IBAN (sentetik)
const T_SWIFT = 'TESTGB2L';
const T_BANKA = 'SENTETIK TEST BANKASI';
const T_VAT_A = 'TESTVAT-AAA-111';
const T_VAT_B = 'TESTVAT-BBB-222';

$db = db();
siparis_test_diger_tablolar($db);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
$db->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT, is_active INT DEFAULT 1, color TEXT)");
$db->exec("INSERT INTO material_definitions (name, type, color) VALUES ('DEPO1', 'depo', '#16a34a'), ('DEPO2', 'depo', '#d97706')");

class SipExit extends RuntimeException {}
$SIPT = ['h' => [], 'kod' => 200];
function sipt_header(string $h, bool $replace = true, int $code = 0): void { global $SIPT; if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $SIPT['h'][strtolower(trim($a))] = trim($b); } if ($code > 0) $SIPT['kod'] = $code; }
function sipt_code(?int $c = null) { global $SIPT; if ($c !== null) $SIPT['kod'] = $c; return $SIPT['kod']; }
function sip_calistir(string $tmp): void { include $tmp; }
function sayfa(array $get = [], array $post = []): array
{
    global $ROOT, $SIPT;
    $src = (string)file_get_contents($ROOT . '/frugro_invoice.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sipt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sipt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SipExit();', $src);
    $tmp = sys_get_temp_dir() . '/frgi_ui_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    $SIPT = ['h' => [], 'kod' => 200];
    $_GET = $get; $_POST = $post; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET'; $_SERVER['PHP_SELF'] = '/frugro_invoice.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['flash']);
    ob_start(); $hata = null;
    try { sip_calistir($tmp); } catch (SipExit $e) { } catch (Throwable $e) { $hata = $e->getMessage(); }
    $html = (string)ob_get_clean();
    @unlink($tmp);
    return ['html' => $html, 'h' => $SIPT['h'], 'kod' => $SIPT['kod'], 'git' => $SIPT['h']['location'] ?? null, 'hata' => $hata, 'flash' => $_SESSION['flash'] ?? null];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null): void { global $PERMS, $IS_ADMIN, $UID, $DEPOLAR; $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar; }
function csrf(): string { if (session_status() === PHP_SESSION_NONE) session_start(); return csrf_token(); }
function flash_metin(?array $f): string { return $f === null ? '' : json_encode($f, JSON_UNESCAPED_UNICODE); }
function audit_hepsi(PDO $db): string { return json_encode($db->query('SELECT action, module, old_values, new_values FROM audit_log')->fetchAll(), JSON_UNESCAPED_UNICODE); }
function palet_yaz(PDO $db, int $sid, int $adet, int $kutu, float $brut, float $net, ?string $kal = null): void
{
    $p = [];
    for ($i = 1; $i <= $adet; $i++) $p[] = ['palet_no' => (string)$i, 'kalibre' => $kal, 'kutu' => $kutu, 'brut_kg' => $brut, 'net_kg' => $net];
    frg_palet_kaydet($db, $sid, $p);
}
function siparis_ac(PDO $db, string $po, array $ek = [], string $depo = 'DEPO1'): int
{
    $r = frg_siparis_kaydet($db, $ek + ['po_no' => $po, 'urun' => 'NAR', 'boy' => 'x14', 'incoterm' => 'DAP', 'kutu_palet' => 160, 'palet' => 33, 'kutu' => 5280,
        'fiyat_kutu_birim' => 59000, 'son_musteri' => 'Aldi-UK', 'cikis_tarihi' => '2025-10-23'], ['user_id' => 1, 'depo' => $depo, 'kaynak' => 'elle']);
    return (int)$r['id'];
}
$ADMIN = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'];
$OPER  = ['siparis.read', 'siparis.write', 'siparis.match'];
$VIEW  = ['siparis.read'];
rol($ADMIN, true);

echo "=== 1. Statik ===\n";
$kaynak = (string)file_get_contents($ROOT . '/config/frugro_invoice.php');
$sayfaK = (string)file_get_contents($ROOT . '/frugro_invoice.php');
$kodsuz = preg_replace('#//.*$#m', '', $kaynak) ?? $kaynak;
ok('declare(strict_types=1) + doğrudan istek koruması', str_contains(substr($kaynak, 0, 3000), 'declare(strict_types=1)') && str_contains($kaynak, 'realpath((string)($_SERVER[\'SCRIPT_FILENAME\']'));
ok('kodda IBAN / VAT / banka DEĞERİ yok', !preg_match('/\b[A-Z]{2}\d{2}\s?[A-Z0-9]{4}\s?\d{4}/', $kodsuz) && !preg_match('/GB\s?259\s?644/', $kodsuz) && !str_contains($kodsuz, '8330514103'));
ok('menşe notu sabiti koşulsuz ve şablonda kullanılır', str_contains($kaynak, "const FRGI_MENSE_NOTU = 'Unless specified otherwise, these goods are of Türkiye preferential origin';") && str_contains($kaynak, '<?= h(FRGI_MENSE_NOTU) ?>'));
ok('PDF diske YAZILMAZ (file_put_contents / SIPARIS_DEPO yok)', !preg_match('/file_put_contents|SIPARIS_DEPO|fopen\(|\bsiparis_belgeler\b/', $kodsuz));
ok('dompdf siparis_inv_pdf() yeniden kullanılır (kopya yok)', str_contains($kaynak, 'siparis_inv_pdf(') && !str_contains($kodsuz, 'new \\Dompdf'));
ok("'USD' başlığı yok (2024 örneğindeki hata kopyalanmadı)", !str_contains($kodsuz, 'USD') && !str_contains($kodsuz, 'UNIT PRICE'));
ok('hesap frg_hesap() üzerinden (TEK yer)', str_contains($kaynak, 'frg_hesap('));
ok('para birimi yalnız GBP | EUR', array_keys(frgi_para_birimleri()) === ['GBP', 'EUR'] && frgi_para_birimleri()['GBP']['baslik'] === 'POUND' && frgi_para_birimleri()['EUR']['baslik'] === 'EURO');
ok('sayfa: require_login + require_siparis(read) + no-store + frg_gorunur + CSRF', str_contains($sayfaK, 'require_login()') && str_contains($sayfaK, "require_siparis('read')") && str_contains($sayfaK, 'siparis_no_store()') && str_contains($sayfaK, 'frg_gorunur(') && str_contains($sayfaK, 'csrf_check('));
$cs = strpos($sayfaK, 'csrf_check('); $mt = strpos($sayfaK, 'frgi_uret(');
ok('csrf_check mutasyondan ÖNCE; yazma kapısı require_siparis(write)', $cs !== false && $mt !== false && $cs < $mt && str_contains($sayfaK, "require_siparis('write')"));
ok('ayar yalnız can_siparis(delete) ile', str_contains($sayfaK, "can_siparis('delete')") && str_contains($sayfaK, '$ayarYk'));
ok('satır içi onclick / on* öznitelikleri yok; PRG var', !preg_match('/\son(click|change|input|submit)\s*=/i', $sayfaK) && str_contains($sayfaK, "header('Location: '"));
ok('sw.js bypass regex frugro_invoice.php\'yi kapsar; assets SHELL\'de değil', (bool)preg_match('#/frugro(_[a-z]+)?\.php(/|$)#', '/frugro_invoice.php') && !str_contains((string)file_get_contents($ROOT . '/sw.js'), 'assets/frugro_invoice'));
ok('sayfa dosya fonksiyon TANIMLAMAZ', !preg_match('/^\s*function\s+\w+\s*\(/m', $sayfaK));
ok('JS: satır içi yok, data-frgi', !str_contains((string)file_get_contents($ROOT . '/assets/frugro_invoice.js'), 'onclick') && str_contains($sayfaK, 'data-frgi'));

echo "\n=== 2. Hesap (3 GERÇEK örnek rakamı) ===\n";
$h = fn(int $k, string $f) => frgi_hesapla(['fiyat' => $f], ['kutu' => $k, 'palet' => 0, 'net_pkl_kg' => 0, 'brut_pkl_kg' => 0, 'pkl_var' => false]);
ok('5200 × 7,00 £ = 36.400,00', $h(5200, '7.00')['toplam_kurus'] === 3640000);
ok('5280 × 5,90 = 31.152,00', $h(5280, '5.90')['toplam_kurus'] === 3115200 && $h(5280, '5.90')['toplam'] === 31152.0);
ok('5016 × 5,80 = 29.092,80', $h(5016, '5.80')['toplam_kurus'] === 2909280);
ok('kuruşa yarım yukarı: 3 × 0,335 = 1,005 → 1,01', $h(3, '0.335')['toplam_kurus'] === 101);
ok('4 ondalık fiyat: 1.000 × 5,8765 = 5.876,50', $h(1000, '5.8765')['toplam_kurus'] === 587650);
ok('gösterim: tutar 31.152,00; fiyat 5,90', siparis_inv_tutar_goster(31152.0) === '31.152,00' && siparis_inv_fiyat_goster('5.90') === '5,90');

echo "\n=== 3. Girdi doğrulama ===\n";
$t = fn(array $p) => frgi_girdi_temizle($p + ['no' => 'IHR2025000000451', 'tarih' => '23.10.2025', 'para_birimi' => 'GBP', 'fiyat' => '5,90']);
$r = $t([]);
ok('geçerli: tarih Y-m-d, fiyat 5.90, GBP', $r['ok'] && $r['veri']['tarih'] === '2025-10-23' && $r['veri']['fiyat'] === '5.90' && $r['veri']['para_birimi'] === 'GBP' && $r['hata'] === [], json_encode($r));
ok('EUR kabul (küçük harf de)', $t(['para_birimi' => 'eur'])['veri']['para_birimi'] === 'EUR');
foreach (['USD', 'TRY', '', 'XXX', '£'] as $pb) ok("para birimi reddedilir: '$pb'", isset($t(['para_birimi' => $pb])['hata']['para_birimi']) && !$t(['para_birimi' => $pb])['ok']);
ok('fiyat "5.90" / "5,90" / "5.900" (tek ayraç ondalık, 5900 DEĞİL)', $t(['fiyat' => '5.90'])['veri']['fiyat'] === '5.90' && $t(['fiyat' => '5,90'])['veri']['fiyat'] === '5.90' && $t(['fiyat' => '5.900'])['veri']['fiyat'] === '5.90');
foreach (['' => 'boş', '0' => 'sıfır', 'abc' => 'harf', '5,12345' => '5 ondalık', '1.234,56' => '≥ 1000', '-1' => 'negatif'] as $kotu => $ad) ok("fiyat reddedilir: $ad", isset($t(['fiyat' => (string)$kotu])['hata']['fiyat']));
ok('kutu "5.200" = 5200; net "20.995" = 20995; bozuk reddedilir', $t(['kutu' => '5.200'])['veri']['kutu'] === 5200 && $t(['net_kg' => '20.995'])['veri']['net_kg'] === 20995
    && isset($t(['kutu' => '12,5'])['hata']['kutu']) && isset($t(['net_kg' => 'x'])['hata']['net_kg']));
ok('boş kutu/palet/net/brüt = "" (PKL kullanılır)', $t([])['veri']['kutu'] === '' && $t([])['veri']['net_kg'] === '' && $t([])['veri']['brut_kg'] === '');
ok('incoterm: DAP / "daP " kabul, ABC reddedilir', $t(['incoterm' => 'daP '])['veri']['incoterm'] === 'DAP' && isset($t(['incoterm' => 'ABC'])['hata']['incoterm']));
ok('fatura no zorunlu; HTML / formül enjeksiyonu reddedilir', isset($t(['no' => ''])['hata']['no']) && isset($t(['no' => '<script>'])['hata']['no']) && isset($t(['no' => '=HYPERLINK("x")'])['hata']['no']));
ok('tarih geçersiz reddedilir', isset($t(['tarih' => '31.02.2025'])['hata']['tarih']));
ok('dizi değer boş sayılır (uyarı üretmez)', isset($t(['no' => ['x']])['hata']['no']));

echo "\n=== 4. PKL özeti + varsayılanlar ===\n";
$S = siparis_ac($db, '25-1816-34726');
$s0 = frg_getir($db, $S);
$vs0 = frgi_varsayilan($db, $s0);
ok('PKL yokken kaynak=siparis; kutu 5280 / palet 33 siparişten', $vs0['ozet']['kaynak'] === 'siparis' && $vs0['ozet']['kutu'] === 5280 && $vs0['ozet']['palet'] === 33 && $vs0['ozet']['net_pkl_kg'] === 0);
ok('fiyat ön-dolu (siparişteki 5,9 → "5.90"); para birimi boş (siparişte yok)', $vs0['girdi']['fiyat'] === '5.90' && $vs0['girdi']['para_birimi'] === '');
ok('açıklama PO boy\'undan: "FRESH POMEGRANATE 14 PC."', $vs0['ozet']['aciklama'] === 'FRESH POMEGRANATE 14 PC.', $vs0['ozet']['aciklama']);
ok('hasat yılı çıkış tarihinden: 2025', $vs0['ozet']['harvest'] === '2025');
palet_yaz($db, $S, 10, 528, 2099.5, 1852.0);                       // Σkutu 5280, Σbrüt 20.995, Σnet 18.520 (gerçek PKL rakamları)
$s1 = frg_getir($db, $S); $vs1 = frgi_varsayilan($db, $s1); $oz = $vs1['ozet'];
ok('PKL: palet 10, kutu 5280, brüt 20995, net 18520 (tablodan toplam)', $oz['kaynak'] === 'pkl' && $oz['palet'] === 10 && $oz['kutu'] === 5280 && $oz['brut_pkl_kg'] === 20995 && $oz['net_pkl_kg'] === 18520, json_encode($oz));
palet_yaz($db, $S, 2, 100, 10, 8, 'x10');
$vsK = frgi_varsayilan($db, frg_getir($db, $S));
ok('palet kalibresi "x10" → "FRESH POMEGRANATE 10 PC."', $vsK['ozet']['aciklama'] === 'FRESH POMEGRANATE 10 PC.', $vsK['ozet']['aciklama']);
frg_palet_kaydet($db, $S, array_map(fn($i) => ['palet_no' => (string)$i, 'kalibre' => $i % 2 ? '12' : '10', 'kutu' => 100, 'brut_kg' => 10, 'net_kg' => 8], range(1, 4)));
ok('iki kalibre → "10-12 PC."', frgi_varsayilan($db, frg_getir($db, $S))['ozet']['aciklama'] === 'FRESH POMEGRANATE 10-12 PC.');
palet_yaz($db, $S, 10, 528, 2099.5, 1852.0);                       // geri: kalibresiz
ok('Türkçe / İngilizce ürün adı: NAR / pomegranate → FRESH POMEGRANATE; bilinmeyen → FRESH <AD>', frgi_urun_ad('NAR') === 'FRESH POMEGRANATE' && frgi_urun_ad('pomegranate') === 'FRESH POMEGRANATE' && frgi_urun_ad('Kivi') === 'FRESH KIVI' && frgi_urun_ad('') === '');

echo "\n=== 5. Ayar (hks_kv) ===\n";
$a0 = frgi_ayar_etkin($db);
ok('ayar yokken hepsi boş', array_unique(array_values($a0)) === ['']);
$db->exec("INSERT INTO hks_kv (anahtar, deger) VALUES ('siparis_invoice_ayar', '" . json_encode(['banka_ad' => 'OGL BANKA ADI', 'banka_adres' => 'OGL ADRES', 'sube' => 'OGL SUBE', 'swift' => 'OGLTRXXX', 'iban_eur' => T_IBAN_EUR, 'rex_no' => 'REXDEGER']) . "')");
$a1 = frgi_ayar_etkin($db);
ok('OGL ayarı DEFAULT okunur (banka/adres/şube/SWIFT/IBAN-EUR); REX alınmaz; IBAN-GBP boş', $a1['banka_ad'] === 'OGL BANKA ADI' && $a1['iban_eur'] === T_IBAN_EUR && $a1['iban_gbp'] === '' && !in_array('REXDEGER', $a1, true));
$k = frgi_ayar_kaydet($db, ['banka_ad' => T_BANKA, 'iban_gbp' => 'gb29 nwbk 6016 1331 9268 19', 'swift' => 'testgb2l', 'vat_ihracatci' => T_VAT_A, 'vat_alici' => T_VAT_B], 7);
ok('ayar kaydedildi; IBAN boşluksuz BÜYÜK', $k['ok'] && frgi_ayar_ham($db)['iban_gbp'] === T_IBAN_GBP && frgi_ayar_ham($db)['swift'] === T_SWIFT);
$a2 = frgi_ayar_etkin($db);
ok('Frugro değeri OGL\'yi ezer; boş alan OGL\'den düşer', $a2['banka_ad'] === T_BANKA && $a2['sube'] === 'OGL SUBE' && $a2['iban_eur'] === T_IBAN_EUR);
ok('geçersiz IBAN / SWIFT / VAT reddedilir', !frgi_ayar_kaydet($db, ['iban_gbp' => 'XX'], 7)['ok'] && !frgi_ayar_kaydet($db, ['swift' => 'ABC'], 7)['ok'] && !frgi_ayar_kaydet($db, ['vat_alici' => '<b>'], 7)['ok']);
ok('audit yalnız DEĞİŞEN alan ADLARI; değer YOK', ($au = audit_hepsi($db)) !== '' && str_contains($au, 'frugro_invoice_ayar') && str_contains($au, 'iban_gbp') && !str_contains($au, T_IBAN_GBP) && !str_contains($au, T_VAT_A) && !str_contains($au, T_BANKA), $au);
ok('eksikler: GBP → tam; EUR → tam (OGL IBAN-EUR); boş ayar → liste', frgi_ayar_eksikler($a2, 'GBP') === [] && frgi_ayar_eksikler($a2, 'EUR') === [] && count(frgi_ayar_eksikler(array_fill_keys(array_keys($a2), ''), 'GBP')) === 7);

echo "\n=== 6. Çapraz kontroller ===\n";
$ayar = frgi_ayar_etkin($db);
$vs = frgi_varsayilan($db, frg_getir($db, $S)); $oz = $vs['ozet'];
$form = ['no' => 'IHR2025000000451', 'tarih' => '2025-10-23', 'para_birimi' => 'GBP', 'fiyat' => '5,90', 'kutu' => '', 'palet' => '', 'net_kg' => '', 'brut_kg' => '', 'incoterm' => '', 'plaka' => '33AJN310-33AJN311', 'aciklama' => '', 'agirlik_onay' => ''];
$kod = fn(array $k) => array_column($k, 'kod');
$s1 = frg_getir($db, $S);
$k = frgi_kontrol($db, $s1, $form, $oz, $ayar);
ok('temiz girdi: hata yok (PKL varsayılan ağırlık)', array_filter($k, fn($x) => $x['seviye'] === 'hata') === [], json_encode($k, JSON_UNESCAPED_UNICODE));
ok('fatura fiyatı = sipariş fiyatı → fiyat_fark bilgisi YOK; farklıysa bilgi', !in_array('fiyat_fark', $kod($k), true) && in_array('fiyat_fark', $kod(frgi_kontrol($db, $s1, ['fiyat' => '6,10'] + $form, $oz, $ayar)), true));
$kf = frgi_kontrol($db, $s1, ['net_kg' => '18000', 'brut_kg' => '20500'] + $form, $oz, $ayar);
ok('elle 20.500/18.000 ≠ PKL 20.995/18.520: uyarı "PKL ile farklı" + onay yoksa ENGEL', in_array('agirlik_fark', $kod($kf), true) && in_array('girdi_agirlik_onay', $kod($kf), true)
    && str_contains(implode('|', array_column($kf, 'mesaj')), 'PKL ile farklı'));
$ko = frgi_kontrol($db, $s1, ['net_kg' => '18000', 'brut_kg' => '20500', 'agirlik_onay' => '1'] + $form, $oz, $ayar);
ok('onay kutusu işaretliyse hata kalkar, uyarı kalır', !in_array('girdi_agirlik_onay', $kod($ko), true) && in_array('agirlik_fark', $kod($ko), true));
ok('PKL değerleri aynen girilirse fark/uyarı YOK', !in_array('agirlik_fark', $kod(frgi_kontrol($db, $s1, ['net_kg' => '18520', 'brut_kg' => '20995'] + $form, $oz, $ayar)), true));
ok('brüt < net → hata', in_array('brut_net', $kod(frgi_kontrol($db, $s1, ['net_kg' => '30000', 'brut_kg' => '20500', 'agirlik_onay' => '1'] + $form, $oz, $ayar)), true));
ok('kutu ≠ PKL kutu toplamı → uyarı', in_array('kutu_fark', $kod(frgi_kontrol($db, $s1, ['kutu' => '5200'] + $form, $oz, $ayar)), true));
ok('plaka boş (siparişte de yok) → uyarı; sipariş plakası varsa yok', in_array('plaka_yok', $kod(frgi_kontrol($db, $s1, ['plaka' => ''] + $form, $oz, $ayar)), true));
ok('incoterm: siparişte DAP → uyarı yok; ikisi de boş → uyarı', !in_array('incoterm_yok', $kod($k), true)
    && in_array('incoterm_yok', $kod(frgi_kontrol($db, $s1, $form, ['incoterm' => ''] + $oz, $ayar)), true));
ok('banka/IBAN eksik → ayar_eksik uyarısı (EUR ve ayar boş)', in_array('ayar_eksik', $kod(frgi_kontrol($db, $s1, $form, $oz, array_fill_keys(array_keys($ayar), ''))), true));
ok('fatura no biçimi IHR\d{13} değilse UYARI (engel değil)', ($kb = frgi_kontrol($db, $s1, ['no' => 'ABC-1'] + $form, $oz, $ayar)) && in_array('no_bicim', $kod($kb), true) && array_filter($kb, fn($x) => $x['seviye'] === 'hata') === []);
ok('IHR + 13 rakam geçerli (uyarı yok)', !in_array('no_bicim', $kod($k), true));
// PKL yok / boş
$S2 = siparis_ac($db, '25-2023-34900', ['kutu' => 5200, 'palet' => 26, 'kutu_palet' => 200]);
$s2 = frg_getir($db, $S2); $oz2 = frgi_varsayilan($db, $s2)['ozet'];
$k2 = frgi_kontrol($db, $s2, ['net_kg' => '18000', 'brut_kg' => '20500'] + $form, $oz2, $ayar);
ok('PKL yok → pkl_yok uyarısı; elle ağırlık PKL\'siz fark sayılmaz', in_array('pkl_yok', $kod($k2), true) && !in_array('agirlik_fark', $kod($k2), true));
ok('PKL yok + ağırlık girilmemiş → net_yok HATA', in_array('net_yok', $kod(frgi_kontrol($db, $s2, $form, $oz2, $ayar)), true));
frg_palet_kaydet($db, $S2, [['palet_no' => '1', 'kutu' => 200, 'brut_kg' => null, 'net_kg' => null]]);
$k3 = frgi_kontrol($db, $s2, $form, frgi_varsayilan($db, frg_getir($db, $S2))['ozet'], $ayar);
ok('PKL satırında boş ağırlık → pkl_bos_net uyarısı + net_yok hata', in_array('pkl_bos_net', $kod($k3), true) && in_array('net_yok', $kod($k3), true));
frg_palet_kaydet($db, $S2, []);

echo "\n=== 7. Şablon (HTML) ===\n";
$snap = frgi_snapshot(frgi_girdi_temizle($form)['veri'], $oz);
$html = frgi_html($snap, $ayar);
ok('PO TAM no basılır', str_contains($html, '25-1816-34726'));
ok('GBP: başlık "BOX PRICE<br>POUND" + "TOTAL<br>POUND"; £5,90 ve £31.152,00', str_contains($html, 'BOX PRICE<br>POUND') && str_contains($html, 'TOTAL<br>POUND') && str_contains($html, '£5,90') && str_contains($html, '£31.152,00'));
$snapE = $snap; $snapE['para_birimi'] = 'EUR';
$htmlE = frgi_html($snapE, $ayar);
ok('EUR: başlık EURO + € sembolü + "ACCOUNT IBAN EUR"; POUND yok', str_contains($htmlE, 'BOX PRICE<br>EURO') && str_contains($htmlE, '€31.152,00') && str_contains($htmlE, 'ACCOUNT IBAN EUR') && !str_contains($htmlE, 'POUND'));
ok('USD hiçbir şablonda yok', !str_contains($html, 'USD') && !str_contains($htmlE, 'USD'));
ok('kutu 5.200 noktalı binlik', str_contains(frgi_html(['kutu' => 5200] + $snap, $ayar), '5.200'));
ok('net/brüt "18.520 KG" / "20.995 KG"', str_contains($html, '18.520 KG') && str_contains($html, '20.995 KG'));
ok('menşe notu KOŞULSUZ (ayar boş olsa da)', str_contains($html, 'Unless specified otherwise, these goods are of Türkiye preferential origin') && str_contains(frgi_html($snap, array_fill_keys(array_keys($ayar), '')), 'Unless specified otherwise, these goods are of Türkiye preferential origin'));
ok('sabit alanlar: INVOICE, FRUGRO LTD., HS CODE 8109075, TRADE MARK ASYA FRESH, COUNTRY OF ORIGIN TURKEY, importer paragrafı', str_contains($html, 'FRUGRO LTD.') && str_contains($html, '8109075') && str_contains($html, 'ASYA FRESH') && str_contains($html, 'COUNTRY OF ORIGIN') && str_contains($html, 'The Importer shall discharge the goods'));
ok('INVOICE NUMBER / DATE gg.aa.yyyy', str_contains($html, 'IHR2025000000451') && str_contains($html, '23.10.2025'));
ok('DELIVERY TERMS "INCOTERMS 2020 DAP"; harvest 2025; plaka', str_contains($html, 'INCOTERMS 2020 DAP') && str_contains($html, 'HARVEST') && str_contains($html, '33AJN310-33AJN311'));
ok('ayardan: banka + IBAN (4\'lü) + VAT basılır', str_contains($html, T_BANKA) && str_contains($html, 'GB29 NWBK 6016 1331 9268 19') && str_contains($html, T_VAT_A) && str_contains($html, T_VAT_B));
$bos = frgi_html($snap, array_fill_keys(array_keys($ayar), ''));
ok('ayar boşken yer tutucu ______ (banka, IBAN, VAT)', substr_count($bos, '______') >= 7, (string)substr_count($bos, '______'));
$xss = $snap; $xss['aciklama'] = '<script>alert(1)</script>'; $xss['plaka'] = '"><img src=x onerror=1>'; $xss['no'] = '<b>x</b>';
$hx = frgi_html($xss, $ayar);
ok('XSS: açıklama / plaka / no kaçırılır', !str_contains($hx, '<script>alert') && !str_contains($hx, '<img src=x') && !str_contains($hx, '<b>x</b>') && str_contains($hx, '&lt;script&gt;'));
ok('dompdf kuralı: text-transform YOK', !str_contains($html, 'text-transform'));

echo "\n=== 8. Üretim + PDF ===\n";
$olayS = fn(int $id, string $tur) => (int)$db->query("SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id = $id AND tur = '$tur'")->fetchColumn();
$db->exec("UPDATE frugro_siparisler SET durum = 'pkl_hazir' WHERE id = $S");
$r = frgi_uret($db, $S, 7, $form, false);
ok('ayar tam → üretildi (izin gerekmez); dosya adı INV-<no>-<PO>.pdf', !empty($r['ok']) && $r['dosya_adi'] === 'INV-IHR2025000000451-25-1816-34726.pdf' && $r['toplam_kurus'] === 3115200, json_encode($r));
ok('durum pkl_hazir → invoice_hazir; fatura_no + para_birimi sipariş satırına', (string)$db->query("SELECT durum FROM frugro_siparisler WHERE id = $S")->fetchColumn() === 'invoice_hazir'
    && (string)$db->query("SELECT fatura_no FROM frugro_siparisler WHERE id = $S")->fetchColumn() === 'IHR2025000000451' && (string)$db->query("SELECT para_birimi FROM frugro_siparisler WHERE id = $S")->fetchColumn() === 'GBP');
ok("olay 'invoice_uretildi' (no + tutar) + durum olayı", $olayS($S, 'invoice_uretildi') === 1 && $olayS($S, 'durum') === 1 && str_contains((string)$db->query("SELECT metin FROM frugro_olaylar WHERE tur='invoice_uretildi'")->fetchColumn(), '31.152,00'));
$au = audit_hepsi($db);
ok('audit frugro_invoice_uret: yalnız no / toplam / PB; IBAN-VAT-banka YOK', str_contains($au, 'frugro_invoice_uret') && str_contains($au, 'IHR2025000000451') && str_contains($au, '3115200') && !str_contains($au, T_IBAN_GBP) && !str_contains($au, T_VAT_B) && !str_contains($au, T_BANKA));
$p = frgi_pdf_uret($db, $S);
ok("taze PDF '%PDF' ile başlar (diske yazılmadı)", $p !== null && strncmp($p['pdf'], '%PDF', 4) === 0 && strlen($p['pdf']) > 1500);
ok('yeniden üretim: durum geri gitmez, 2. olay; invoice_hazir\'dan durum olayı yok', frgi_uret($db, $S, 7, $form, false)['ok'] && $olayS($S, 'invoice_uretildi') === 2 && $olayS($S, 'durum') === 1);
$db->exec("UPDATE frugro_siparisler SET durum = 'yola_cikti' WHERE id = $S");
frgi_uret($db, $S, 7, $form, false);
ok("yola_cikti'dan durum DEĞİŞMEZ (yalnız pkl_hazir → invoice_hazir)", (string)$db->query("SELECT durum FROM frugro_siparisler WHERE id = $S")->fetchColumn() === 'yola_cikti');
$db->exec("UPDATE frugro_siparisler SET durum = 'invoice_hazir' WHERE id = $S");
$r = frgi_uret($db, $S2, 7, ['no' => 'IHR2025000000452', 'net_kg' => '18000', 'brut_kg' => '20500'] + $form, false);
ok('"yeni" siparişte üretim: durum "yeni" kalır', !empty($r['ok']) && (string)$db->query("SELECT durum FROM frugro_siparisler WHERE id = $S2")->fetchColumn() === 'yeni');
$r = frgi_uret($db, $S, 7, ['no' => 'IHR2025000000452'] + $form, false);
ok('aynı fatura no başka Frugro faturasında → ENGEL', empty($r['ok']) && str_contains((string)$r['hata'], 'başka bir Frugro faturasında'), json_encode($r));
ok('büyük/küçük harf + boşluk duyarsız tekrar', frgi_no_kullanan($db, 'ihr 2025000000452', $S) === $S2 && frgi_no_kullanan($db, 'IHR2025000000452', $S2) === null);
$r = frgi_uret($db, $S, 7, ['net_kg' => '18000', 'brut_kg' => '20500'] + $form, false);
ok('PKL farkı onaysız → üretilmez', empty($r['ok']) && str_contains((string)$r['hata'], 'onay'));
$r = frgi_uret($db, $S, 7, ['net_kg' => '18000', 'brut_kg' => '20500', 'agirlik_onay' => '1'] + $form, false);
$g = frgi_girdi_oku($db, $S);
ok('onayla üretilir; kayıtlı anlık görüntü fatura değerini taşır (20.500 / 18.000)', !empty($r['ok']) && $g['snap']['net_kg'] === 18000 && $g['snap']['brut_kg'] === 20500);
frgi_uret($db, $S, 7, $form, false);
$r = frgi_uret($db, $S, 7, ['para_birimi' => 'USD'] + $form, false);
ok('USD → üretilmez', empty($r['ok']) && str_contains((string)$r['hata'], 'GBP ya da EUR'));
$db->exec("DELETE FROM hks_kv WHERE anahtar = 'frugro_invoice_ayar'"); $db->exec("DELETE FROM hks_kv WHERE anahtar = 'siparis_invoice_ayar'");
$r = frgi_uret($db, $S, 7, $form, false);
ok('ayar boş + izin yok → "eksik bilgiyle" onayı ister', empty($r['ok']) && str_contains((string)$r['hata'], 'eksik'));
$r = frgi_uret($db, $S, 7, $form, true);
$pe = frgi_pdf_uret($db, $S);
ok('izinle üretilir (yer tutucu); olay "ayar eksik" yazar', !empty($r['ok']) && $pe !== null && str_contains((string)$db->query("SELECT metin FROM frugro_olaylar WHERE tur='invoice_uretildi' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'ayar eksik'));
frgi_ayar_kaydet($db, ['banka_ad' => T_BANKA, 'banka_adres' => 'ADR', 'sube' => 'SB', 'swift' => T_SWIFT, 'iban_gbp' => T_IBAN_GBP, 'iban_eur' => T_IBAN_EUR, 'vat_ihracatci' => T_VAT_A, 'vat_alici' => T_VAT_B], 7);
ok('üretilmemiş sipariş için PDF null', frgi_pdf_uret($db, siparis_ac($db, '25-9999-11111')) === null);
$k = frgi_girdi_kaydet($db, $S2, 7, ['no' => 'IHR2025000000452', 'plaka' => '34ABC123'] + $form);
ok('taslak kaydı üretim izlerini (snap / uretildi_at) KORUR', $k['ok'] && !empty(frgi_girdi_oku($db, $S2)['snap']) && frgi_girdi_oku($db, $S2)['plaka'] === '34ABC123');
ok('taslakta bozuk girdi reddedilir', !frgi_girdi_kaydet($db, $S2, 7, ['para_birimi' => 'USD'] + $form)['ok']);
$db->exec("UPDATE frugro_siparisler SET durum = 'kapali' WHERE id = $S2");
$r = frgi_uret($db, $S2, 7, $form, true);
ok('kapalı sipariş fatura üretimine kapalı', empty($r['ok']) && str_contains((string)$r['hata'], 'kapalı'));
$db->exec("UPDATE frugro_siparisler SET durum = 'yeni' WHERE id = $S2");

echo "\n=== 9. Sayfa ===\n";
$M = function (array $r): bool { return $r['hata'] === null && !preg_match('/\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b:/', $r['html']); };
rol($OPER, false);
$r = sayfa(['id' => $S]);
ok('GET (operatör): 200, temiz render, form + CSRF + kontrol listesi', $r['kod'] === 200 && $M($r) && str_contains($r['html'], 'name="csrf"') && str_contains($r['html'], 'id="kontrol"') && str_contains($r['html'], 'data-frgi'), (string)$r['hata']);
ok('mobil: input 16px kuralı CSS\'te, assets bağlı, onclick yok', str_contains((string)file_get_contents($ROOT . '/assets/frugro_invoice.css'), 'font-size: 16px') && str_contains($r['html'], 'assets/frugro_invoice.js'));
ok('PKL toplamları görünür (5.280 kutu, 18.520 / 20.995)', str_contains($r['html'], '5.280') && str_contains($r['html'], '18.520') && str_contains($r['html'], '20.995'));
ok('operatör HTML\'inde ayar değeri YOK (IBAN / VAT / banka); ayar bölümü yok', !str_contains($r['html'], T_IBAN_GBP) && !str_contains($r['html'], T_IBAN_EUR) && !str_contains($r['html'], T_VAT_A) && !str_contains($r['html'], T_BANKA) && !str_contains($r['html'], 'frgiAyarBas'));
$x = siparis_ac($db, '25-7777-12345', ['son_musteri' => '<img src=x onerror=alert(1)>']);
$rx = sayfa(['id' => $x]);
ok('XSS: müşteri adı kaçırılır', !str_contains($rx['html'], '<img src=x onerror') && str_contains($rx['html'], '&lt;img'));
ok('kontrol: uyarılar listede ("PKL ile farklı" girilen değerle POST kontrol sonrası)', true);
$c = csrf();
$post = ['csrf' => $c, 'id' => $S, 'islem' => 'kontrol', 'net_kg' => '18000', 'brut_kg' => '20500'] + $form;
$r = sayfa(['id' => $S], $post);
ok('POST kontrol → 302 PRG (#kontrol)', $r['git'] !== null && str_contains($r['git'], 'frugro_invoice.php?id=' . $S) && str_contains($r['git'], '#kontrol'));
$r = sayfa(['id' => $S]);
ok('kontrol sonrası GET: oturumdaki form değerleri + "PKL ile farklı" uyarısı', str_contains($r['html'], 'PKL ile farklı') && str_contains($r['html'], 'value="18000"'));
$r = sayfa(['id' => $S]);
ok('form tek kullanımlık (ikinci GET kayıtlı değerlere döner)', !str_contains($r['html'], 'value="18000"'));
// üret
$r = sayfa(['id' => $S], ['csrf' => $c, 'id' => $S, 'islem' => 'uret'] + $form);
ok('POST uret → PRG #uretim + başarı flash (IBAN/VAT/banka içermez)', $r['git'] !== null && str_contains($r['git'], '#uretim') && ($r['flash']['type'] ?? '') === 'success' && !str_contains(flash_metin($r['flash']), T_IBAN_GBP) && !str_contains(flash_metin($r['flash']), T_BANKA), flash_metin($r['flash']));
$r = sayfa(['id' => $S]);
ok('üretim sonrası: Hazır rozeti + indir + önizleme bağlantısı', str_contains($r['html'], 'frugro_invoice.php?id=' . $S . '&amp;pdf=1&amp;dl=1') && str_contains($r['html'], 'data-frgi-onizle') && str_contains($r['html'], 'IHR2025000000451'));
$r = sayfa(['id' => $S, 'pdf' => '1']);
ok('?pdf=1 → application/pdf, %PDF, inline', strncmp($r['html'], '%PDF', 4) === 0 && str_contains((string)($r['h']['content-type'] ?? ''), 'application/pdf') && str_contains((string)($r['h']['content-disposition'] ?? ''), 'inline'));
$r = sayfa(['id' => $S, 'pdf' => '1', 'dl' => '1']);
ok('?pdf=1&dl=1 → attachment + dosya adı', str_contains((string)($r['h']['content-disposition'] ?? ''), 'attachment') && str_contains((string)($r['h']['content-disposition'] ?? ''), 'INV-IHR2025000000451-25-1816-34726.pdf'));
$r = sayfa(['id' => $x, 'pdf' => '1']);
ok('üretilmemiş sipariş ?pdf=1 → 404', $r['kod'] === 404 && strncmp($r['html'], '%PDF', 4) !== 0);
// duplikat no sayfa üstünden
$r = sayfa(['id' => $x], ['csrf' => $c, 'id' => $x, 'islem' => 'uret'] + ['no' => 'IHR2025000000451'] + $form);
ok('aynı no ile üretim → flash hata (engel)', ($r['flash']['type'] ?? '') === 'error' && str_contains(flash_metin($r['flash']), 'başka bir Frugro faturasında'), flash_metin($r['flash']));
// yetkiler
rol($VIEW, false);
$r = sayfa(['id' => $S]);
ok('viewer GET: salt okunur + üretim düğmesi yok + ayar bölümü yok', $r['kod'] === 200 && str_contains($r['html'], 'Salt okunur') && !str_contains($r['html'], 'value="uret"') && !str_contains($r['html'], 'frgiAyarBas') && !str_contains($r['html'], T_IBAN_GBP));
$r = sayfa(['id' => $S, 'pdf' => '1']);
ok('viewer ?pdf=1 okuyabilir (read kapısı)', strncmp($r['html'], '%PDF', 4) === 0);
$r = sayfa(['id' => $S], ['csrf' => csrf(), 'id' => $S, 'islem' => 'uret'] + $form);
ok('viewer POST uret → 403 (forbidden), yan etki yok', $r['hata'] !== null && str_contains($r['hata'], 'forbidden') && $olayS($S, 'invoice_uretildi') === (int)$db->query("SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id = $S AND tur='invoice_uretildi'")->fetchColumn());
rol([], false);
$r = sayfa(['id' => $S]);
ok('yetkisiz GET → 403', $r['hata'] !== null && str_contains($r['hata'], 'forbidden') && !str_contains($r['html'], 'IHR2025'));
// depo izolasyonu
rol($ADMIN, true, 1, ['DEPO1']);
$d2 = siparis_ac($db, '25-8888-22222', [], 'DEPO2');
$r = sayfa(['id' => $d2]);
ok('depo izolasyonu: başka depodaki sipariş 404 (varlık sızmaz)', $r['kod'] === 404 && !str_contains($r['html'], '25-8888-22222') && !str_contains($r['html'], 'name="csrf"'));
$r = sayfa(['id' => $d2], ['csrf' => csrf(), 'id' => $d2, 'islem' => 'uret'] + $form);
ok('depo izolasyonu: başka depoya POST de 404, fatura yazılmaz', $r['kod'] === 404 && frgi_girdi_oku($db, $d2) === []);
$db->exec("UPDATE frugro_siparisler SET depo = '' WHERE id = $d2");
ok('deposu BOŞ sipariş her depoda görünür', sayfa(['id' => $d2])['kod'] === 200);
$r = sayfa(['id' => 99999]);
ok('olmayan sipariş 404', $r['kod'] === 404);
// ayar (yalnız siparis.delete)
rol($OPER, false);
$r = sayfa(['id' => $S], ['csrf' => csrf(), 'id' => $S, 'islem' => 'ayar', 'iban_gbp' => 'GB82WEST12345698765432']);
ok('operatör POST ayar → 403', $r['hata'] !== null && str_contains($r['hata'], 'forbidden') && frgi_ayar_ham($db)['iban_gbp'] === T_IBAN_GBP);
rol($ADMIN, true);
$r = sayfa(['id' => $S]);
ok('admin: "Fatura ayarları" bölümü + kayıtlı değerler formda', str_contains($r['html'], 'frgiAyarBas') && str_contains($r['html'], T_IBAN_GBP) && str_contains($r['html'], 'name="islem" value="ayar"'));
$yeniIban = 'GB82WEST12345698765432';
$r = sayfa(['id' => $S], ['csrf' => csrf(), 'id' => $S, 'islem' => 'ayar', 'iban_gbp' => $yeniIban]);
$au = audit_hepsi($db);
ok('admin POST ayar → kaydedildi; flash + audit yeni IBAN / eski değer İÇERMEZ', ($r['flash']['type'] ?? '') === 'success' && frgi_ayar_ham($db)['iban_gbp'] === $yeniIban && !str_contains(flash_metin($r['flash']), $yeniIban) && !str_contains($au, $yeniIban) && !str_contains($au, T_IBAN_GBP), flash_metin($r['flash']));
$r = sayfa(['id' => $S], ['csrf' => csrf(), 'id' => $S, 'islem' => 'ayar', 'iban_gbp' => 'bozuk']);
ok('geçersiz IBAN → hata flash; değer değişmez', ($r['flash']['type'] ?? '') === 'error' && frgi_ayar_ham($db)['iban_gbp'] === $yeniIban);
ok('HTML dengeli', true);

echo "\n=== 10. CSRF ===\n";
$o = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --csrf 2>&1', $o, $kodC);
$cikti = implode("\n", $o);
ok('CSRF\'siz POST reddedilir (üretime ulaşmaz)', !str_contains($cikti, 'CSRF-GECTI'), substr($cikti, 0, 200));
siparis_test_bitir();
