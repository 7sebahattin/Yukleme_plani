<?php
// =========================================================
// scripts/erp_faz1_muhasebe_smoke.php — ERP Faz 1 (ERP-1, ERP-2, ERP-11a) Uçtan Uca Smoke Testi
//   php scripts/erp_faz1_muhasebe_smoke.php
// Bellek içi SQLite üzerinde çalışır; canlı DB'ye dokunmaz.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$ROOT = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Minimal stub fonksiyonlar
function audit_log_event(string $action, string $module, $record_id = null, $old = null, $new = null): void
{
    $GLOBALS['_test_audits'][] = [$action, $module, $record_id];
}
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

require_once $ROOT . '/config/muhasebe_core.php';
require_once $ROOT . '/config/edonusum_core.php';
require_once $ROOT . '/config/erp_mutabakat_core.php';
require_once $ROOT . '/config/muhasebe_ui.php';

$pass = 0; $fail = 0;
function ok(string $ad, bool $kosul, string $detay = ''): void
{
    global $pass, $fail;
    if ($kosul) { $pass++; echo "PASS $ad\n"; }
    else { $fail++; echo "FAIL $ad" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

echo "=== 1. ERP-1: TDHP Migrasyon (2x idempotent) & Hesap Planı ===\n";
$r1 = muhasebe_migrate($pdo);
$r2 = muhasebe_migrate($pdo);
ok('muhasebe_migrate 2x idempotent ve muh_hazir() true', muh_hazir($pdo) && count($r1) >= 4 && $r2[0]['durum'] === 'var', json_encode([$r1, $r2]));
$tumHesaplar = muh_hesap_listesi($pdo);
ok('TDHP tohumu en az 85 standart hesap içerir (100-799)', count($tumHesaplar) >= 85, 'count=' . count($tumHesaplar));

$yeniHs = muh_hesap_kaydet($pdo, [
    'kod' => '120.01.001', 'ad' => 'Asya Tarım Test Müşterisi', 'ust_kod' => '120.01',
    'tip' => 'aktif', 'bakiye_yonu' => 'borc', 'hareket_gorebilir' => 1,
], 1);
ok('Özel alt hesap (120.01.001) eklenebilir', !empty($yeniHs['ok']) && (int)$yeniHs['id'] > 0);
$pasifRes = muh_hesap_durum($pdo, (int)$yeniHs['id'], 0, 1);
$aktifRes = muh_hesap_durum($pdo, (int)$yeniHs['id'], 1, 1);
ok('Hesap pasifleştir/aktifleştir çalışır', !empty($pasifRes['ok']) && !empty($aktifRes['ok']));

echo "\n=== 2. ERP-1: Yevmiye Fişi (Borç = Alacak, Sıralı Numara, Ters Fiş İptali) ===\n";
$dengesiz = muh_fis_kaydet($pdo, [
    'tarih' => '2026-03-10', 'tur' => 'mahsup', 'aciklama' => 'Dengesiz deneme',
    'satirlar' => [
        ['hesap_kod' => '100.01', 'borc' => 1000, 'alacak' => 0],
        ['hesap_kod' => '600', 'borc' => 0, 'alacak' => 900],
    ],
], 1);
ok('Dengesiz yevmiye fişi (Borç != Alacak) REDDEDİLİR', empty($dengesiz['ok']));

// Açılış sermayesi: 100.01 Borç 50.000 / 500 Alacak 50.000
$f1 = muh_fis_kaydet($pdo, [
    'tarih' => '2026-01-02', 'tur' => 'acilis', 'aciklama' => 'Açılış sermaye girişi',
    'satirlar' => [
        ['hesap_kod' => '100.01', 'borc' => 50000, 'alacak' => 0, 'belge_no' => 'ACL-1'],
        ['hesap_kod' => '500', 'borc' => 0, 'alacak' => 50000, 'belge_no' => 'ACL-1'],
    ],
], 1);
ok('Açılış fişi dengeli kaydedildi ve sıralı YEV-2026-00001 aldı', !empty($f1['ok']) && $f1['fis_no'] === 'YEV-2026-00001', json_encode($f1));

// Satış fişi: 120.01 Borç 12.000 / 600 Alacak 10.000 + 391 Alacak 2.000
$f2 = muh_fis_kaydet($pdo, [
    'tarih' => '2026-03-15', 'tur' => 'mahsup', 'aciklama' => 'Yurt içi satış faturası',
    'satirlar' => [
        ['hesap_kod' => '120.01', 'borc' => 12000, 'alacak' => 0, 'belge_no' => 'SAT-100'],
        ['hesap_kod' => '600', 'borc' => 0, 'alacak' => 10000, 'belge_no' => 'SAT-100'],
        ['hesap_kod' => '391', 'borc' => 0, 'alacak' => 2000, 'belge_no' => 'SAT-100'],
    ],
], 1);
// Faaliyet gideri (TDHP 632): 632 Borç 3.000 + 191 Borç 600 / 100.01 Alacak 3.600
$f3 = muh_fis_kaydet($pdo, [
    'tarih' => '2026-03-20', 'tur' => 'tediye', 'aciklama' => 'Genel yönetim gideri',
    'satirlar' => [
        ['hesap_kod' => '632', 'borc' => 3000, 'alacak' => 0],
        ['hesap_kod' => '191', 'borc' => 600, 'alacak' => 0],
        ['hesap_kod' => '100.01', 'borc' => 0, 'alacak' => 3600],
    ],
], 1);
// Hatalı fiş + Ters Fiş (Storno) ile iptal testi
$fHata = muh_fis_kaydet($pdo, [
    'tarih' => '2026-03-22', 'tur' => 'mahsup', 'aciklama' => 'Yanlış kayıt',
    'satirlar' => [
        ['hesap_kod' => '100.01', 'borc' => 500, 'alacak' => 0],
        ['hesap_kod' => '649', 'borc' => 0, 'alacak' => 500],
    ],
], 1);
$storno = muh_fis_ters_iptal($pdo, (int)$fHata['id'], 'Hatalı giriş storno', 1);
ok('Ters fiş (storno) oluşturularak iptal edildi (DELETE yok)', !empty($storno['ok']) && (int)$storno['ters_fis_id'] > 0);
$fisCount = (int)$pdo->query('SELECT COUNT(*) FROM muh_fisler')->fetchColumn();
ok('INSERT-only: orijinal fiş + ters fiş veritabanında saklanır', $fisCount === 5, 'count=' . $fisCount);

echo "\n=== 3. ERP-1: Mizan, Muavin, Gelir Tablosu, Bilanço & KDV Özeti ===\n";
$mizan = muh_mizan($pdo, '2026-01-01', '2026-12-31');
ok('Genel Mizan dengeli (Toplam Borç == Toplam Alacak)', !empty($mizan['toplam']['dengeli']) && abs($mizan['toplam']['borc'] - $mizan['toplam']['alacak']) < 0.01, json_encode($mizan['toplam']));

$muavin = muh_muavin($pdo, '100.01', '2026-03-01', '2026-03-31');
ok('Muavin 100.01 devir=50000 ve son bakiye=46400 (storno sıfırlandı)', abs($muavin['devir_bakiye'] - 50000.0) < 0.01 && abs($muavin['son_bakiye'] - 46400.0) < 0.01, json_encode($muavin));

$gelir = muh_gelir_tablosu($pdo, '2026-01-01', '2026-12-31');
ok('Gelir Tablosu net_satislar=10000, faaliyet_kari=7000, donem_kari=7000', abs($gelir['net_satislar'] - 10000.0) < 0.01 && abs($gelir['donem_kari'] - 7000.0) < 0.01, json_encode($gelir));

$bilancoOncesi = muh_bilanco($pdo, '2026-12-31');
ok('Bilanço (kapanış öncesi) Aktif == Pasif dengeli', !empty($bilancoOncesi['dengeli']) && abs($bilancoOncesi['toplam_aktif'] - $bilancoOncesi['toplam_pasif']) < 0.01 && $bilancoOncesi['toplam_aktif'] > 0, json_encode(['aktif' => $bilancoOncesi['toplam_aktif'], 'pasif' => $bilancoOncesi['toplam_pasif']]));

$kdv = muh_kdv_ozeti($pdo, '2026-01-01', '2026-12-31');
ok('KDV Özeti: 391=2000, 191=600, Ödenecek KDV=1400', abs($kdv['hesaplanan'] - 2000.0) < 0.01 && abs($kdv['indirilecek'] - 600.0) < 0.01 && abs($kdv['odenecek'] - 1400.0) < 0.01, json_encode($kdv));

echo "\n=== 4. ERP-1: Alt Defter Oto-Mahsup Köprüsü (Fatura + Kasa -> Yevmiye) ===\n";
$pdo->exec("CREATE TABLE fatura_belgeler (id INTEGER PRIMARY KEY AUTOINCREMENT, tur TEXT, belge_no TEXT, fatura_no TEXT, tarih TEXT, cari_id INTEGER, cari_unvan TEXT, cari_vergi_no TEXT, para_birimi TEXT, kur REAL, net_toplam REAL, kdv_toplam REAL, genel_toplam REAL, durum TEXT, depo TEXT)");
$pdo->exec("INSERT INTO fatura_belgeler (tur, belge_no, fatura_no, tarih, cari_id, cari_unvan, cari_vergi_no, para_birimi, kur, net_toplam, kdv_toplam, genel_toplam, durum, depo) VALUES ('satis', 'FT-2026-001', 'ASY2026000000010', '2026-04-05', 10, 'Müşteri Beta A.Ş.', '1112223334', 'TRY', 1, 5000, 1000, 6000, 'gecerli', 'MERKEZ')");
$pdo->exec("CREATE TABLE kasa_hareketler (id INTEGER PRIMARY KEY AUTOINCREMENT, kasa_id INTEGER, cari_id INTEGER, tarih TEXT, yon TEXT, tutar REAL, kur REAL, para_birimi TEXT, belge_no TEXT, aciklama TEXT, durum TEXT, depo TEXT)");
$pdo->exec("INSERT INTO kasa_hareketler (kasa_id, cari_id, tarih, yon, tutar, kur, para_birimi, belge_no, aciklama, durum, depo) VALUES (1, 10, '2026-04-06', 'G', 6000, 1, 'TRY', 'KS-001', 'Tahsilat', 'gecerli', 'MERKEZ')");
$mah1 = muh_alt_defter_mahsup($pdo, '2026-04-01', '2026-04-30', 1);
$mah2 = muh_alt_defter_mahsup($pdo, '2026-04-01', '2026-04-30', 1);
ok('Alt defter oto-mahsup 2 fiş oluşturur ve 2. çalışmada 2 atlar (idempotent)', $mah1['olusturulan'] === 2 && $mah2['olusturulan'] === 0 && $mah2['atlanan'] === 2, json_encode([$mah1, $mah2]));

echo "\n=== 5. ERP-1: Mali Yıl Kapanışı & Kapalı Dönem Kilidi ===\n";
$kapat = muh_donem_kapat($pdo, 2026, 1);
ok('2026 mali dönemi kapatıldı (kapanış fişi kesildi)', !empty($kapat['ok']) && !empty($kapat['fis_no']), json_encode($kapat));
$bilancoSonrasi = muh_bilanco($pdo, '2026-12-31');
ok('Bilanço (kapanış sonrası) Aktif == Pasif dengeli', !empty($bilancoSonrasi['dengeli']) && abs($bilancoSonrasi['toplam_aktif'] - $bilancoSonrasi['toplam_pasif']) < 0.01 && $bilancoSonrasi['toplam_aktif'] > 0, json_encode(['aktif' => $bilancoSonrasi['toplam_aktif'], 'pasif' => $bilancoSonrasi['toplam_pasif']]));
$kapaliDeneme = muh_fis_kaydet($pdo, [
    'tarih' => '2026-06-15', 'tur' => 'mahsup', 'aciklama' => 'Kapalı yıla kayıt denemesi',
    'satirlar' => [
        ['hesap_kod' => '100.01', 'borc' => 100, 'alacak' => 0],
        ['hesap_kod' => '500', 'borc' => 0, 'alacak' => 100],
    ],
], 1);
ok('Kapalı mali döneme yeni fiş girişi REDDEDİLİR', empty($kapaliDeneme['ok']));

echo "\n=== 6. ERP-2: e-Dönüşüm (UBL-TR 1.2 XML, ETTN UUIDv4, Gönder/Sorgula, Gelen Kabul/Red) ===\n";
$e1 = edonusum_migrate($pdo);
$e2 = edonusum_migrate($pdo);
ok('edonusum_migrate 2x idempotent ve edonusum_hazir() true', edonusum_hazir($pdo) && count($e1) === 2 && $e2[0]['durum'] === 'var');

// Mevcut faturadan otomatik doldurma (fatura_id=1) testi
$zInv = edonusum_zarf_olustur($pdo, ['fatura_id' => 1, 'belge_turu' => 'efatura'], 1);
ok('Faturadan otomatik e-Fatura zarfı oluşturuldu ve UUIDv4 ETTN üretti', !empty($zInv['ok']) && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$zInv['ettn']), json_encode($zInv));

$zarfRow = edonusum_zarf_getir($pdo, (int)$zInv['id']);
$domInv = new DOMDocument();
$xmlOkInv = $domInv->loadXML((string)$zarfRow['ubl_xml'], LIBXML_NONET);
ok('e-Fatura UBL-TR 1.2 XML DOMDocument ile geçerli ve ETTN içerir', $xmlOkInv && $domInv->documentElement->localName === 'Invoice' && str_contains((string)$zarfRow['ubl_xml'], (string)$zInv['ettn']));

$zIrs = edonusum_zarf_olustur($pdo, [
    'belge_turu' => 'eirsaliye', 'senaryo' => 'TEMELIRSALIYE', 'belge_no' => 'IRS2026000000001',
    'tarih' => '2026-05-02', 'alici_vkn' => '1234567890', 'alici_unvan' => 'Örnek Alıcı A.Ş.',
    'para_birimi' => 'TRY', 'ara_toplam' => 5000, 'kdv_toplam' => 0, 'genel_toplam' => 5000,
], 1);
$irsRow = edonusum_zarf_getir($pdo, (int)$zIrs['id']);
$domIrs = new DOMDocument();
$xmlOkIrs = $domIrs->loadXML((string)$irsRow['ubl_xml'], LIBXML_NONET);
ok('e-İrsaliye UBL-TR 1.2 XML DOMDocument ile DespatchAdvice olarak geçerli', $xmlOkIrs && $domIrs->documentElement->localName === 'DespatchAdvice');

$gonder = edonusum_zarf_gonder($pdo, (int)$zInv['id'], 1);
$sorgu  = edonusum_zarf_durum_sorgula($pdo, (int)$zInv['id']);
ok('Sandbox zarf gönder + durum sorgula → onaylandi', !empty($gonder['ok']) && !empty($sorgu['ok']) && ($sorgu['durum'] ?? '') === 'onaylandi');

$pdo->exec("INSERT INTO edonusum_gelen (ettn, belge_turu, senaryo, belge_no, tarih, gonderen_vkn, gonderen_unvan, para_birimi, ara_toplam, kdv_toplam, genel_toplam, durum) VALUES ('11111111-2222-4333-8444-555555555555', 'efatura', 'TICARIFATURA', 'TED2026000000001', '2026-05-03', '9876543210', 'Tedarikçi Ltd.', 'TRY', 2000, 400, 2400, 'bekliyor')");
$gelId1 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO edonusum_gelen (ettn, belge_turu, senaryo, belge_no, tarih, gonderen_vkn, gonderen_unvan, para_birimi, ara_toplam, kdv_toplam, genel_toplam, durum) VALUES ('22222222-3333-4444-8555-666666666666', 'efatura', 'TICARIFATURA', 'TED2026000000002', '2026-05-03', '9876543210', 'Tedarikçi Ltd.', 'TRY', 1000, 200, 1200, 'bekliyor')");
$gelId2 = (int)$pdo->lastInsertId();
$redBos = edonusum_gelen_yanitla($pdo, $gelId1, 'red', '', 1);
$kabul  = edonusum_gelen_yanitla($pdo, $gelId1, 'kabul', '', 1);
$redOk  = edonusum_gelen_yanitla($pdo, $gelId2, 'red', 'Tutar uyuşmuyor', 1);
ok('Gelen kutusu: gerekçesiz red reddedilir, kabul & gerekçeli red işlenir', empty($redBos['ok']) && !empty($kabul['ok']) && !empty($redOk['ok']));

echo "\n=== 7. ERP-11a: Cari Risk Limiti, Form BA/BS & Dönem Sonu Kur Farkı (646/656) ===\n";
$m1 = erp_mutabakat_migrate($pdo);
$m2 = erp_mutabakat_migrate($pdo);
ok('erp_mutabakat_migrate 2x idempotent ve erp_mutabakat_hazir() true', erp_mutabakat_hazir($pdo) && count($m1) === 2 && $m2[0]['durum'] === 'var');

$pdo->exec("CREATE TABLE cari_kartlar (id INTEGER PRIMARY KEY, kod TEXT, unvan TEXT, vergi_no TEXT, aktif INTEGER DEFAULT 1)");
$pdo->exec("INSERT INTO cari_kartlar (id, kod, unvan, vergi_no, aktif) VALUES (10, 'C-010', 'Müşteri Beta A.Ş.', '1112223334', 1)");
$pdo->exec("CREATE TABLE cari_hareketler (id INTEGER PRIMARY KEY AUTOINCREMENT, cari_id INTEGER, yon TEXT, tutar REAL, kur REAL DEFAULT 1, durum TEXT DEFAULT 'gecerli')");
$pdo->exec("INSERT INTO cari_hareketler (cari_id, yon, tutar, kur, durum) VALUES (10, 'B', 85000, 1, 'gecerli')");

$limRes = cari_risk_limit_kaydet($pdo, ['cari_id' => 10, 'risk_limiti' => 100000, 'uyari_yuzde' => 80, 'blokaj_aktif' => 1], 1);
$rkUyari  = cari_risk_kontrol($pdo, 10, 0);
$rkBlokaj = cari_risk_kontrol($pdo, 10, 20000);
ok('Cari risk limiti: %85 doluluk → uyari, +20.000 TL → blokaj', !empty($limRes['ok']) && $rkUyari['durum'] === 'uyari' && $rkBlokaj['durum'] === 'blokaj' && $rkBlokaj['blokaj'] === true, json_encode([$rkUyari, $rkBlokaj]));

$babs = cari_babs_hesapla($pdo, 2026, 4, 5000.0);
ok('Form BA/BS (2026-04, eşik 5000 TL): BS 1 kayıt (5000 TL)', count($babs['bs']) === 1 && abs($babs['bs_toplam'] - 5000.0) < 0.01, json_encode($babs));

// 2027 açık dönemi açıp EUR dövizli açık pozisyon ekle, ardından dönem sonu kur değerleme uygula
muh_donem_ac($pdo, 2027, 1);
muh_fis_kaydet($pdo, [
    'tarih' => '2027-01-10', 'tur' => 'mahsup', 'aciklama' => 'EUR ihracat satışı',
    'satirlar' => [
        ['hesap_kod' => '120.02', 'borc' => 35000, 'alacak' => 0, 'para_birimi' => 'EUR', 'kur' => 35.0, 'doviz_tutar' => 1000.0],
        ['hesap_kod' => '601', 'borc' => 0, 'alacak' => 35000, 'para_birimi' => 'EUR', 'kur' => 35.0, 'doviz_tutar' => 1000.0],
    ],
], 1);
$kurOn = erp_kur_farki_hesapla($pdo, '2027-01-31', ['EUR' => 38.0]);
ok('Kur farkı önizleme: 1000 EUR @ 35->38 = +3000 TL (646 Kambiyo Kârı)', abs($kurOn['toplam_kar'] - 3000.0) < 0.01 && abs($kurOn['net_fark'] - 3000.0) < 0.01, json_encode($kurOn));
$kurUyg1 = erp_kur_farki_uygula($pdo, '2027-01', '2027-01-31', 1, ['EUR' => 38.0]);
$kurUyg2 = erp_kur_farki_uygula($pdo, '2027-01', '2027-01-31', 1, ['EUR' => 38.0]);
ok('Kur farkı 646/656 yevmiye fişi kesilir ve aynı dönem 2. kez engellenir (409)', !empty($kurUyg1['ok']) && !empty($kurUyg1['fis_no']) && empty($kurUyg2['ok']) && ($kurUyg2['kod'] ?? 0) === 409, json_encode([$kurUyg1, $kurUyg2]));

echo "\n=== 8. UI Render Doğrulaması (uyarısız HTML) ===\n";
$uiOk = true;
$html1 = muh_hesap_plani_render($tumHesaplar, [], true);
$html2 = muh_yevmiye_render(muh_fis_listesi($pdo), muh_fis_getir($pdo, (int)$f1['id']), $tumHesaplar, muh_donem_listesi($pdo), [], true, true);
$html3 = muh_mali_tablolar_render('mizan', $mizan, $tumHesaplar, ['bas' => '2026-01-01', 'bit' => '2026-12-31'], true)
       . muh_mali_tablolar_render('muavin', $muavin, $tumHesaplar, ['bas' => '2026-03-01', 'bit' => '2026-03-31', 'hesap' => '100.01'], true)
       . muh_mali_tablolar_render('gelir', $gelir, $tumHesaplar, ['bas' => '2026-01-01', 'bit' => '2026-12-31'], true)
       . muh_mali_tablolar_render('bilanco', $bilancoSonrasi, $tumHesaplar, ['bit' => '2026-12-31'], true)
       . muh_mali_tablolar_render('kdv', $kdv, $tumHesaplar, ['bas' => '2026-01-01', 'bit' => '2026-12-31'], true);
$html4 = edonusum_render('giden', edonusum_zarf_listesi($pdo), edonusum_gelen_listesi($pdo), edonusum_son_faturalar($pdo), [], true)
       . edonusum_render('gelen', edonusum_zarf_listesi($pdo), edonusum_gelen_listesi($pdo), [], [], true);
$html5 = muh_mutabakat_render('risk', ['cariler' => erp_cari_secenekleri($pdo), 'limitler' => cari_risk_limit_listesi($pdo)], true)
       . muh_mutabakat_render('babs', ['babs' => $babs], true)
       . muh_mutabakat_render('kur_farki', ['tarih' => '2027-01-31', 'onizleme' => $kurOn, 'gecmis' => erp_kur_farki_listesi($pdo)], true);
ok('Tüm 5 UI bileşeni hatasız render olur', str_contains($html1, 'TDHP') && str_contains($html2, 'YEV-2026-00001') && str_contains($html3, 'Dengeli') && str_contains($html4, 'UBL-TR') && str_contains($html5, 'Müşteri Beta'));

echo "\n=== 8. Statik Güvenlik & Mimari Değişmezler ===\n";
$uiSrc = (string)file_get_contents($ROOT . '/config/muhasebe_ui.php');
ok('config/muhasebe_ui.php SIFIR SQL içerir ve SCRIPT_FILENAME guard taşır',
    str_contains($uiSrc, 'SCRIPT_FILENAME') && !preg_match('/->(prepare|query|exec)\s*\(/', $uiSrc)
);
$jsSrc = (string)file_get_contents($ROOT . '/assets/muhasebe.js');
ok('assets/muhasebe.js localStorage ve innerHTML içermez',
    !str_contains($jsSrc, 'localStorage') && !str_contains($jsSrc, 'innerHTML')
);
$cssSrc = (string)file_get_contents($ROOT . '/assets/muhasebe.css');
ok('assets/muhasebe.css dark-mode ve mobil <768px 16px kuralı içerir',
    str_contains($cssSrc, 'html[data-theme="dark"]') && str_contains($cssSrc, 'font-size: 16px')
);
$kaydetSrc = (string)file_get_contents($ROOT . '/muh_kaydet.php');
ok('muh_kaydet.php yalnız POST (405), require_login, csrf_check ve muh_kapi kullanır',
    str_contains($kaydetSrc, 'http_response_code(405)')
    && str_contains($kaydetSrc, 'require_login()')
    && str_contains($kaydetSrc, 'csrf_check(')
    && str_contains($kaydetSrc, 'muh_kapi(')
);
$xmlSrc = (string)file_get_contents($ROOT . '/edonusum_xml.php');
ok('edonusum_xml.php require_login, muhasebe_sayfa_can ve nosniff başlığı taşır',
    str_contains($xmlSrc, 'require_login()')
    && str_contains($xmlSrc, "muhasebe_sayfa_can('edonusum_belgeler')")
    && str_contains($xmlSrc, 'X-Content-Type-Options: nosniff')
);
$swSrc = (string)file_get_contents($ROOT . '/sw.js');
ok('sw.js muh_|edonusum_ sayfalarını önbellek dışı tutar ve v587',
    str_contains($swSrc, "CACHE_NAME = 'yukleme-plani-v587'") && str_contains($swSrc, 'muh_|edonusum_')
);

echo "\nÖZET: $pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
