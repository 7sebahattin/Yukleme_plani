<?php
// =========================================================
// scripts/_cari_test_lib.php — Cari UI testleri/render için ORTAK harness (include-only; yalnız CLI).
//   _siparis_test_lib.php (stub auth + gerçek helpers) + gerçek config/cari_core.php / cari_ui.php.
//   DB: DOSYA tabanlı geçici SQLite (alt süreçler — scripts/_cari_istek.php — aynı DB'yi açar); çıkışta silinir.
//   cari_t_istek(['dosya'=>'cari_kartlari.php','get'=>[…],'post'=>[…],'server'=>[…],'admin'=>true,'perms'=>[…]]) → ['govde','kod','basliklar','flash','hata'].
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/cari_ui.php';

// CARI_E2E_DB verilirse (scripts/cari_ui_smoke.js sunucu köprüsü) o dosya kullanılır ve SİLİNMEZ (istekler arası kalıcı durum).
$CARI_T_DB = (string)(getenv('CARI_E2E_DB') ?: '');
if ($CARI_T_DB === '') {
    $CARI_T_DB = sys_get_temp_dir() . '/cari_t_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.sqlite';
    register_shutdown_function(static function () use ($CARI_T_DB): void { @unlink($CARI_T_DB); });
}
$CARI_T_MEVCUT = is_file($CARI_T_DB) && filesize($CARI_T_DB) > 0;   // kalıcı e2e DB zaten kurulduysa tablolar yeniden yaratılmaz
$PDO_TEST = new PDO('sqlite:' . $CARI_T_DB);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
if (!$CARI_T_MEVCUT) {
    siparis_test_diger_tablolar($PDO_TEST);   // users + audit_log
    $PDO_TEST->exec('CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)');
}

/** Alt süreçte tek istek çalıştırır. @return array{govde:string,kod:int,basliklar:list<string>,flash:?array,hata:?string} */
function cari_t_istek(array $spec): array
{
    global $CARI_T_DB, $ROOT;
    if (!empty($spec['govde_dosya'])) @unlink($spec['govde_dosya']);
    $spec += ['get' => [], 'post' => [], 'server' => [], 'admin' => true, 'perms' => ['reports.export', 'records.read'], 'uid' => 1];
    $spec['db'] = $CARI_T_DB;
    $cmd = 'CARI_T_SPEC=' . escapeshellarg(base64_encode((string)json_encode($spec, JSON_UNESCAPED_UNICODE))) . ' php ' . escapeshellarg($ROOT . '/scripts/_cari_istek.php') . ' 2>&1';
    $cikti = (string)shell_exec($cmd);
    $p = strrpos($cikti, "\n@@CARI@@");
    if ($p === false) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç çıktısı çözülemedi'];
    $r = json_decode(substr($cikti, $p + 9), true);
    if (!is_array($r)) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç JSON bozuk'];
    $on = substr($cikti, 0, $p);
    if (!empty($spec['govde_dosya']) && str_starts_with($on, "PK\x03\x04")) {   // XLSX: xlsx_gonder tamponları kapatıp doğrudan yazar → ön çıktı = dosya
        file_put_contents($spec['govde_dosya'], $on);
        $on = '';
    }
    $r['govde'] = $on . (string)$r['govde'];   // süreç çıktısı (ör. PHP uyarıları) + gövde
    return $r;
}

/** Şemayı cari_migrate ile kurar (SQLite çeviricisi + ödeme şekli tohumu). */
function cari_t_sema_kur(PDO $db): void
{
    $GLOBALS['_cari_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void {
        [$c, $ix] = siparis_ddl_sqlite($ddl);
        $p->exec($c);
        foreach ($ix as $x) $p->exec($x);
    };
    cari_migrate($db);
}

/**
 * Uydurma örnek veri (kişisel veri YOK): 13 kart (10 = pasif, 13 = hareketsiz), karışık para birimli hareketler, 1 iptal, 1 virman, 7 grup.
 * @return array{kart:array<string,int>,hareket:array<string,int>}
 */
function cari_t_ornek_veri(PDO $db): array
{
    global $UID, $IS_ADMIN;
    $UID = 1; $IS_ADMIN = true;
    foreach (['Ambalaj', 'Depolama', 'Hal Komisyoncuları', 'Nakliye', 'Üretici', 'Yurt Dışı Müşteriler', 'Yurt İçi Müşteriler'] as $i => $g) cari_tanim_kaydet($db, 'grup', $g, $i + 1, 1);
    $grup = []; foreach (cari_tanim_listesi($db, 'grup') as $t) $grup[$t['ad']] = (int)$t['id'];
    $kartlar = [
        ['Örnek Tarım Ltd.', 'musteri', 'Yurt Dışı Müşteriler', 'TRY', ['telefon' => '0212 555 01 01', 'yetkili' => 'Örnek Yetkili', 'eposta' => 'muhasebe@ornek.test', 'vade_gun' => 45, 'vergi_dairesi' => 'Örnek V.D.', 'vergi_no' => '1234567890',
            'iban' => 'TR120006400000112345678901', 'adres' => 'Örnek Mah. 100. Sk. No:1', 'il' => 'Antalya', 'ulke' => 'Türkiye', 'notlar' => 'Sevkiyat öncesi %30 peşin; kalan vade ile.']],
        ['Deneme Ambalaj A.Ş.', 'tedarikci', 'Ambalaj', 'TRY', ['telefon' => '0232 555 02 02']],
        ['Test Nakliyat San. Tic.', 'tedarikci', 'Nakliye', 'TRY', ['telefon' => '0242 555 03 03']],
        ['Örnek Gıda İhracat Ltd.', 'musteri', 'Yurt Dışı Müşteriler', 'EUR', ['telefon' => '0216 555 04 04']],
        ['Mavi Vadi Hal Komisyonculuğu', 'ikisi', 'Hal Komisyoncuları', 'TRY', ['telefon' => '0242 555 05 05']],
        ['Yeşil Ova Üretici Birliği', 'tedarikci', 'Üretici', 'TRY', ['telefon' => '0322 555 06 06']],
        ['Ege Soğuk Depo Hizmetleri', 'tedarikci', 'Depolama', 'TRY', ['telefon' => '0232 555 07 07']],
        ['Kuzey Pazarlama GmbH', 'musteri', 'Yurt Dışı Müşteriler', 'EUR', ['telefon' => '+49 30 5550 0808']],
        ['Deneme Çavuş Grubu', 'personel', '', 'TRY', ['telefon' => '0242 555 09 09']],
        ['Eski Müşteri Örnek Ltd.', 'musteri', 'Yurt İçi Müşteriler', 'TRY', ['telefon' => '0212 555 10 10']],
        ['Akdeniz Kasa Üretim', 'tedarikci', 'Ambalaj', 'TRY', ['telefon' => '0242 555 11 11']],
        ['Anadolu Lojistik Örnek', 'tedarikci', 'Nakliye', 'USD', ['telefon' => '0312 555 12 12']],
        ['Yeni Hareketsiz Cari', 'diger', '', 'TRY', []],
    ];
    $kart = [];
    foreach ($kartlar as [$unvan, $tip, $g, $pb, $ek]) {
        $r = cari_kart_kaydet($db, ['unvan' => $unvan, 'tip' => $tip, 'grup_id' => $g !== '' ? $grup[$g] : '', 'para_birimi' => $pb, 'aktif' => '1'] + $ek, 1);
        if (!$r['ok']) throw new RuntimeException('örnek kart: ' . $r['hata']);
        $kart[$unvan] = (int)$r['id'];
    }
    $K = static fn(string $u): int => $kart[$u];
    $y = date('Y');
    $hr = [];
    $ekle = static function (string $anahtar, array $g) use ($db, &$hr): void {
        $r = cari_hareket_ekle($db, $g, 1);
        if (!$r['ok']) throw new RuntimeException("örnek hareket $anahtar: " . $r['hata']);
        $hr[$anahtar] = (int)$r['id'];
    };
    $c1 = $K('Örnek Tarım Ltd.');
    $ekle('c1_acilis', ['cari_id' => $c1, 'tur' => 'acilis', 'yon' => 'B', 'tarih' => "$y-01-14", 'tutar' => '40.000,00', 'para_birimi' => 'TRY', 'aciklama' => 'Devir bakiyesi']);
    $ekle('c1_bdk1', ['cari_id' => $c1, 'tur' => 'borc_dekont', 'tarih' => "$y-03-03", 'tutar' => '185.000,00', 'para_birimi' => 'TRY', 'evrak_no' => 'FT-2026/118', 'aciklama' => 'Mart sevkiyatı satış bedeli']);
    $ekle('c1_tah1', ['cari_id' => $c1, 'tur' => 'tahsilat', 'tarih' => "$y-03-21", 'tutar' => '120.000,00', 'para_birimi' => 'TRY', 'evrak_no' => 'Dekont 8841', 'odeme_sekli' => 'Havale/EFT', 'aciklama' => 'Mart sevkiyatı kısmi tahsilat']);
    $ekle('c1_bdk2', ['cari_id' => $c1, 'tur' => 'borc_dekont', 'tarih' => "$y-05-09", 'tutar' => '96.400,00', 'para_birimi' => 'TRY', 'evrak_no' => 'FT-2026/164', 'aciklama' => 'Nisan sevkiyatı satış bedeli']);
    $ekle('c1_tah2', ['cari_id' => $c1, 'tur' => 'tahsilat', 'tarih' => "$y-05-28", 'tutar' => '150.000,00', 'para_birimi' => 'TRY', 'evrak_no' => 'Çek 0045', 'odeme_sekli' => 'Çek', 'aciklama' => 'Nisan sevkiyatı tahsilatı']);
    $ekle('c1_adk', ['cari_id' => $c1, 'tur' => 'alacak_dekont', 'tarih' => "$y-07-17", 'tutar' => '6.000,00', 'para_birimi' => 'TRY', 'aciklama' => 'Fiyat farkı düzeltmesi (iskonto)']);
    $ekle('c1_tah_hatali', ['cari_id' => $c1, 'tur' => 'tahsilat', 'tarih' => "$y-09-02", 'tutar' => '30.000,00', 'para_birimi' => 'TRY', 'evrak_no' => 'Dekont 9120', 'odeme_sekli' => 'Havale/EFT', 'aciklama' => 'Yanlış cariye girilmiş tahsilat']);
    $v = cari_virman_ekle($db, ['kaynak_id' => $c1, 'hedef_id' => $K('Yeşil Ova Üretici Birliği'), 'tarih' => "$y-09-14", 'tutar' => '20.000,00', 'para_birimi' => 'TRY', 'aciklama' => 'Mahsup: üretici birliği ile ortak sevkiyat'], 1);
    $hr['virman'] = (int)$v['id']; $hr['virman_b'] = (int)$v['id2'];
    $ekle('c1_bdk3', ['cari_id' => $c1, 'tur' => 'borc_dekont', 'tarih' => "$y-10-06", 'tutar' => '150.000,00', 'para_birimi' => 'TRY', 'evrak_no' => 'FT-2026/233', 'aciklama' => 'Ekim sevkiyatı satış bedeli']);
    $ekle('c1_tah3', ['cari_id' => $c1, 'tur' => 'tahsilat', 'tarih' => "$y-10-29", 'tutar' => '50.000,00', 'para_birimi' => 'TRY', 'evrak_no' => 'Nakit makbuzu 12', 'odeme_sekli' => 'Nakit', 'aciklama' => 'Ekim sevkiyatı kısmi tahsilat']);
    $ekle('c1_usd1', ['cari_id' => $c1, 'tur' => 'borc_dekont', 'tarih' => "$y-02-11", 'tutar' => '80.000,00', 'para_birimi' => 'USD', 'kur' => '32,4512', 'evrak_no' => 'FT-2026/071', 'aciklama' => 'Şubat ihracat bedeli']);
    $ekle('c1_usd2', ['cari_id' => $c1, 'tur' => 'tahsilat', 'tarih' => "$y-06-19", 'tutar' => '31.750,00', 'para_birimi' => 'USD', 'kur' => '32,9', 'evrak_no' => 'SWIFT 6620', 'odeme_sekli' => 'Havale/EFT', 'aciklama' => 'Haziran tahsilatı']);
    $ekle('c1_eur', ['cari_id' => $c1, 'tur' => 'tahsilat', 'tarih' => "$y-08-05", 'tutar' => '4.800,00', 'para_birimi' => 'EUR', 'odeme_sekli' => 'Havale/EFT', 'aciklama' => 'Avro avans']);
    $ac = [['Deneme Ambalaj A.Ş.', 'A', '86.320,50', 'TRY'], ['Test Nakliyat San. Tic.', 'A', '42.000,00', 'TRY'], ['Test Nakliyat San. Tic.', 'A', '1.200,00', 'EUR'],
        ['Örnek Gıda İhracat Ltd.', 'B', '31.780,00', 'EUR'], ['Örnek Gıda İhracat Ltd.', 'B', '5.400,00', 'USD'], ['Yeşil Ova Üretici Birliği', 'A', '235.900,00', 'TRY'],
        ['Ege Soğuk Depo Hizmetleri', 'A', '18.750,00', 'TRY'], ['Kuzey Pazarlama GmbH', 'B', '72.400,00', 'EUR'], ['Deneme Çavuş Grubu', 'A', '9.600,00', 'TRY'],
        ['Akdeniz Kasa Üretim', 'A', '3.150,75', 'TRY'], ['Anadolu Lojistik Örnek', 'A', '2.100,00', 'USD']];
    foreach ($ac as $i => [$u, $yon, $t, $pb]) $ekle('ac' . $i, ['cari_id' => $K($u), 'tur' => 'acilis', 'yon' => $yon, 'tarih' => "$y-01-02", 'tutar' => $t, 'para_birimi' => $pb, 'aciklama' => 'Açılış bakiyesi']);
    $ekle('mavi_b', ['cari_id' => $K('Mavi Vadi Hal Komisyonculuğu'), 'tur' => 'borc_dekont', 'tarih' => "$y-04-01", 'tutar' => '12.750,00', 'para_birimi' => 'TRY', 'aciklama' => 'Hal satış bedeli']);
    $ekle('mavi_t', ['cari_id' => $K('Mavi Vadi Hal Komisyonculuğu'), 'tur' => 'tahsilat', 'tarih' => "$y-04-09", 'tutar' => '12.750,00', 'para_birimi' => 'TRY', 'odeme_sekli' => 'Nakit', 'aciklama' => 'Hal satış tahsilatı']);
    $ekle('amb_ode', ['cari_id' => $K('Deneme Ambalaj A.Ş.'), 'tur' => 'odeme', 'tarih' => "$y-10-22", 'tutar' => '35.000,00', 'para_birimi' => 'TRY', 'evrak_no' => 'Fatura 4471', 'odeme_sekli' => 'Havale/EFT', 'aciklama' => 'Kasa ve karton ödemesi']);
    $ekle('lojistik_hatali', ['cari_id' => $K('Anadolu Lojistik Örnek'), 'tur' => 'odeme', 'tarih' => "$y-10-05", 'tutar' => '4.200,00', 'para_birimi' => 'USD', 'odeme_sekli' => 'Havale/EFT', 'aciklama' => 'Navlun avansı']);
    $r = cari_hareket_iptal($db, $hr['c1_tah_hatali'], 'Yanlış cari seçildi', 1); if (!$r['ok']) throw new RuntimeException('iptal: ' . $r['hata']);
    $r = cari_hareket_iptal($db, $hr['lojistik_hatali'], 'Tutar hatalı girildi', 1); if (!$r['ok']) throw new RuntimeException('iptal2: ' . $r['hata']);
    cari_kart_durum($db, $K('Eski Müşteri Örnek Ltd.'), false, 1);
    foreach ([['Örnek Meyve Paketleme', 'firma'], ['Test Narenciye Kooperatifi', 'tedarikci'], ['Örnek Tarım Ltd.', 'firma'], ['Deneme Ambalaj A.Ş.', 'tedarikci'], ['Mavi Vadi Hal Komisyonculuğu', 'firma'], ['Mavi Vadi Hal Komisyonculuğu', 'tedarikci']] as [$ad, $t]) {
        $db->prepare('INSERT INTO material_definitions (type, name, is_active) VALUES (?, ?, 1)')->execute([$t, $ad]);
    }
    return ['kart' => $kart, 'hareket' => $hr];
}
