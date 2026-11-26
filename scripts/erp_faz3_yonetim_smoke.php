<?php
// =========================================================
// scripts/erp_faz3_yonetim_smoke.php — ERP Faz 3 (ERP-5 Onay Akışları, ERP-6 Banka/Kredi/POS, ERP-7 İK/Resmi Bordro)
// Çalıştırma: php scripts/erp_faz3_yonetim_smoke.php
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/db_tx_helpers.php';
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('csrf_token')) {
    function csrf_token(): string { return 'test-csrf-token'; }
}
require_once $ROOT . '/config/muhasebe_core.php';
require_once $ROOT . '/config/onay_core.php';
require_once $ROOT . '/config/banka_core.php';
require_once $ROOT . '/config/ik_bordro_core.php';
require_once $ROOT . '/config/erp_yonetim_ui.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$warnings = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$warnings): bool {
    $warnings[] = "[$no] $str ($file:$line)";
    return false;
});

$pass = 0; $fail = 0;
function ok(string $ad, bool $kosul, string $ipucu = ''): void {
    global $pass, $fail;
    if ($kosul) $pass++; else $fail++;
    echo sprintf("%-74s %s%s\n", $ad, $kosul ? 'OK' : '*** FAIL', $kosul ? '' : '  ' . $ipucu);
}

// ── 1) 2× İdempotent Migrasyon (Muhasebe TDHP + ERP-5 + ERP-6 + ERP-7) ───────
echo "=== 1. Migrasyonlar (2× İdempotent) ===\n";
muhasebe_migrate($pdo);
onay_migrate($pdo);      $rOnay2  = onay_migrate($pdo);
banka_migrate($pdo);     $rBanka2 = banka_migrate($pdo);
ik_bordro_migrate($pdo); $rIk2    = ik_bordro_migrate($pdo);

ok('ERP-5/6/7 tüm tablolar hazır', onay_hazir($pdo) && banka_hazir($pdo) && ik_bordro_hazir($pdo));
ok('2. tur migrasyonlar idempotent (hepsi mevcut)',
    !in_array('olusturuldu', array_column($rOnay2, 'durum'), true)
    && !in_array('olusturuldu', array_column($rBanka2, 'durum'), true)
    && !in_array('olusturuldu', array_column($rIk2, 'durum'), true)
);

// ── 2) ERP-5: Merkezi Onay Akışları (Sıralı, Paralel, Vekalet, Yetki) ───────
echo "\n=== 2. ERP-5 Merkezi Onay Akışları ===\n";
$kr1 = onay_kural_kaydet($pdo, [
    'modul'      => 'satinalma',
    'ad'         => '50.000 TL Üstü Çift Kademe',
    'min_tutar'  => 50000,
    'maks_tutar' => 0,
    'kip'        => 'sirali',
    'onaycilar'  => '10, 20',
], 1);
ok('Sıralı 2 kademeli onay kuralı kaydedildi', !empty($kr1['ok']) && (int)$kr1['id'] > 0, json_encode($kr1));

$kr2 = onay_kural_kaydet($pdo, [
    'modul'      => 'masraf',
    'ad'         => 'Paralel Masraf Onayı',
    'min_tutar'  => 0,
    'maks_tutar' => 0,
    'kip'        => 'paralel',
    'onaycilar'  => '30, 40',
], 1);
ok('Paralel onay kuralı kaydedildi', !empty($kr2['ok']), json_encode($kr2));

// Vekalet: User #10 → Vekil #11 (bugünü kapsayan tarih aralığı)
$vk = onay_vekalet_kaydet($pdo, [
    'asil_user_id'  => 10,
    'vekil_user_id' => 11,
    'bas_tarih'     => date('Y-m-d', strtotime('-1 day')),
    'bit_tarih'     => date('Y-m-d', strtotime('+5 days')),
    'notlar'        => 'Yıllık izin vekaleti',
], 1);
ok('Tarih aralıklı vekalet kaydedildi (#10 → #11)', !empty($vk['ok']) && onay_aktif_vekil_bul($pdo, 10) === 11);

// 75.000 TL satın alma talebi → 2 adımlı kurala eşleşmeli
$tlp = onay_talep_olustur($pdo, [
    'modul'       => 'satinalma',
    'baslik'      => '75.000 TL Ambalaj Alımı',
    'tutar'       => 75000,
    'para_birimi' => 'TRY',
    'aciklama'    => 'Sezonluk karton kutu siparişi',
], 5);
ok('75.000 TL talep 2 adımlı kurala eşleşti', !empty($tlp['ok']) && (int)($tlp['adim_sayisi'] ?? 0) === 2, json_encode($tlp));
$tid = (int)$tlp['id'];

// Yetkisiz kullanıcı (user #99) 403 engellenir; ayrıca 2. adım sahibi (#20) sırası gelmeden onaylayamaz
$eng1 = onay_karar_ver($pdo, $tid, 'onay', '', 99, false);
$eng2 = onay_karar_ver($pdo, $tid, 'onay', '', 20, false);
ok('Yetkisiz (#99) ve sırası gelmeyen (#20) onaycı 403 ile engellendi',
    empty($eng1['ok']) && (int)($eng1['kod'] ?? 0) === 403
    && empty($eng2['ok']) && (int)($eng2['kod'] ?? 0) === 403
);

// Vekil (#11) asil (#10) yerine 1. adımı onaylar → durum bekliyor, adım 2'ye geçer
$k1 = onay_karar_ver($pdo, $tid, 'onay', 'Vekaleten uygun', 11, false);
ok('Vekil (#11) 1. adımı onayladı (durum=bekliyor)', !empty($k1['ok']) && ($k1['durum'] ?? '') === 'bekliyor', json_encode($k1));

// 2. adım sahibi (#20) onaylar → durum onaylandi
$k2 = onay_karar_ver($pdo, $tid, 'onay', 'Bütçe uygun', 20, false);
ok('2. adım (#20) onayı ile talep ONAYLANDI', !empty($k2['ok']) && ($k2['durum'] ?? '') === 'onaylandi', json_encode($k2));

// Paralel talep + gerekçesiz red 422 + gerekçeli red → reddedildi
$pt = onay_talep_olustur($pdo, ['modul' => 'masraf', 'baslik' => 'Fuar Masrafı', 'tutar' => 12000], 5);
$ptid = (int)$pt['id'];
$redBos = onay_karar_ver($pdo, $ptid, 'red', '', 40, false);
$redOk  = onay_karar_ver($pdo, $ptid, 'red', 'Belge eksik', 40, false);
ok('Gerekçesiz red 422 engellenir, gerekçeli red talebi REDDEDİLDİ yapar',
    empty($redBos['ok']) && (int)($redBos['kod'] ?? 0) === 422
    && !empty($redOk['ok']) && ($redOk['durum'] ?? '') === 'reddedildi'
);

// ── 3) ERP-6: Banka Mutabakat (MT940/CSV), Kredi & POS ───────────────────────
echo "\n=== 3. ERP-6 Banka Mutabakat, Kredi & POS ===\n";
$bh = banka_hesap_kaydet($pdo, [
    'kod'           => 'GAR-TRY-01',
    'banka_ad'      => 'Garanti BBVA',
    'sube_ad'       => 'Mersin Kurumsal',
    'iban'          => 'TR330006100519786457841326',
    'para_birimi'   => 'TRY',
    'acilis_bakiye' => 100000,
    'tdhp_hesap_kod'=> '102',
], 1);
ok('Banka hesabı oluşturuldu', !empty($bh['ok']) && (int)$bh['id'] > 0, json_encode($bh));
$hid = (int)$bh['id'];

// Otomatik mutabakat için fatura_belgeler tablosu (gerçek şemaya uyumlu)
$pdo->exec("CREATE TABLE fatura_belgeler (id INTEGER PRIMARY KEY AUTOINCREMENT, tur VARCHAR(30), belge_no VARCHAR(50), tarih DATE, genel_toplam DECIMAL(16,2), durum VARCHAR(20))");
$pdo->exec("INSERT INTO fatura_belgeler (tur, belge_no, tarih, genel_toplam, durum) VALUES ('satis', 'FAT-2026-001', '2026-05-01', 25400.50, 'gecerli')");
$pdo->exec("INSERT INTO fatura_belgeler (tur, belge_no, tarih, genel_toplam, durum) VALUES ('alis', 'ALF-2026-009', '2026-05-02', 8200.00, 'gecerli')");

// SWIFT MT940 içe aktarma
$mt940 = ":20:STMT202605\n:61:2605020502C25400,50NTRFMUST01\n:86:Müşteri Havale FAT-2026-001\n:61:2605030503D8200,00NTRFTED09\n:86:Tedarikçi Ödeme ALF-2026-009\n";
$im1 = banka_ekstre_ice_aktar($pdo, $hid, $mt940, 1);
$im1Dup = banka_ekstre_ice_aktar($pdo, $hid, $mt940, 1);
ok('MT940 (:61:/:86:) içe aktarıldı ve tekrarda mükerrer atlandı',
    !empty($im1['ok']) && $im1['eklenen'] === 2 && $im1['atlanan'] === 0
    && !empty($im1Dup['ok']) && $im1Dup['eklenen'] === 0 && $im1Dup['atlanan'] === 2,
    json_encode([$im1, $im1Dup])
);

// CSV içe aktarma
$csv = "2026-05-04;CSV-101;EFT Komisyon;-45,50\n";
$imCsv = banka_ekstre_ice_aktar($pdo, $hid, $csv, 1);
ok('CSV ekstre satırı negatif tutarla aktarıldı', !empty($imCsv['ok']) && $imCsv['eklenen'] === 1);

// Otomatik fatura mutabakatı
$es = banka_ekstre_otomatik_eslestir($pdo, $hid, 1);
$hsList = banka_hesap_listesi($pdo);
$guncelBak = (float)($hsList[0]['guncel_bakiye'] ?? 0);
ok('Otomatik mutabakat 2 faturayı eşleştirdi & güncel bakiye doğru (117155.00)',
    !empty($es['ok']) && $es['eslesen'] === 2 && abs($guncelBak - 117155.00) < 0.01,
    "eslesen={$es['eslesen']} bakiye=$guncelBak"
);

// Kredi & Taksit Planı + Ödeme (TDHP 300/780/102)
$kr = banka_kredi_olustur($pdo, [
    'sozlesme_no'      => 'KRD-2026-01',
    'hesap_id'         => $hid,
    'anapara'          => 120000,
    'aylik_faiz_yuzde' => 3.0,
    'taksit_sayisi'    => 6,
    'bas_tarih'        => '2026-05-01',
], 1);
$krediler = banka_kredi_listesi($pdo);
$taksitler = $krediler[0]['taksitler'] ?? [];
$anaparaSum = round(array_sum(array_map(static fn($x) => (float)$x['anapara_tutar'], $taksitler)), 2);
ok('6 taksitli kredi amortisman planı anapara toplamı birebir 120.000 TL',
    !empty($kr['ok']) && count($taksitler) === 6 && abs($anaparaSum - 120000.0) < 0.02,
    "sum=$anaparaSum"
);

$t1Id = (int)$taksitler[0]['id'];
$od = banka_kredi_taksit_ode($pdo, $t1Id, 1);
$odFisId = (int)($od['muh_fis_id'] ?? 0);
$fisDenge = $odFisId > 0
    ? $pdo->query("SELECT ROUND(SUM(borc),2) AS b, ROUND(SUM(alacak),2) AS a FROM muh_fis_satirlar WHERE fis_id = $odFisId")->fetch()
    : null;
ok('Kredi taksit ödemesi dengeli TDHP (300/780/102) yevmiye fişi oluşturdu',
    !empty($od['ok']) && $odFisId > 0 && $fisDenge && abs((float)$fisDenge['b'] - (float)$fisDenge['a']) < 0.01 && (float)$fisDenge['b'] > 0,
    json_encode([$od, $fisDenge])
);

// POS komisyon + bloke çözümü
$pos = banka_pos_kaydet($pdo, [
    'hesap_id'       => $hid,
    'islem_tarih'    => '2026-05-05',
    'valor_gun'      => 2,
    'brut_tutar'     => 10000,
    'komisyon_yuzde' => 2.5,
], 1);
$posCoz = banka_pos_bloke_coz($pdo, (int)($pos['id'] ?? 0), 1);
$posCoz2 = banka_pos_bloke_coz($pdo, (int)($pos['id'] ?? 0), 1);
ok('POS komisyon (9750 net) & bloke çözümü (idempotent 409) çalışır',
    !empty($pos['ok']) && abs((float)$pos['net_tutar'] - 9750.0) < 0.01
    && !empty($posCoz['ok']) && empty($posCoz2['ok']) && (int)($posCoz2['kod'] ?? 0) === 409
);

// ── 4) ERP-7: İK Özlük, 4857 Yıllık İzin & Resmi Bordro ─────────────────────
echo "\n=== 4. ERP-7 İK Özlük, İzin & Resmi Bordro ===\n";
$oz1 = ik_ozluk_kaydet($pdo, [
    'sicil_no'        => 'PRS-001',
    'ad_soyad'        => 'Ahmet Yılmaz',
    'tc_kimlik'       => '12345678901',
    'departman'       => 'İhracat',
    'unvan'           => 'Operasyon Müdürü',
    'ise_giris_tarih' => '2020-01-10', // 2026-05 itibarıyla 6 tam yıl → 5×14 + 1×20 = 90 gün hak
    'brut_maas'       => 60000,
    'iban'            => 'TR110006100519786457841111',
], 1);
$oz2 = ik_ozluk_kaydet($pdo, [
    'sicil_no'        => 'PRS-002',
    'ad_soyad'        => 'Ayşe Kaya',
    'departman'       => 'Muhasebe',
    'unvan'           => 'Uzman',
    'ise_giris_tarih' => '2024-03-01',
    'brut_maas'       => 26005.50, // Tam asgari ücret → GV=0, DV=0 olmalı
], 1);
ok('2 personel özlük kartı oluşturuldu', !empty($oz1['ok']) && !empty($oz2['ok']));
$oid1 = (int)$oz1['id'];

$bak0 = ik_yillik_izin_bakiye($pdo, $oid1, '2026-05-15');
ok('4857 md.53 kıdem izin hesabı: 6 yıl = 90 gün (5×14 + 1×20)',
    $bak0['kidem_yil'] === 6 && $bak0['toplam_hak'] === 90 && $bak0['kalan'] === 90,
    json_encode($bak0)
);

// İzin talebi → ERP-5 Onay Akışı talebi de otomatik oluşur
$izt = ik_izin_talep_et($pdo, [
    'ozluk_id'  => $oid1,
    'tur'       => 'yillik',
    'bas_tarih' => '2026-06-01',
    'bit_tarih' => '2026-06-05', // 5 gün
    'aciklama'  => 'Yaz izni',
], 1);
$izKar = ik_izin_karar($pdo, (int)($izt['id'] ?? 0), 'onaylandi', 1);
$bak1 = ik_yillik_izin_bakiye($pdo, $oid1, '2026-05-15');
ok('İzin talebi Onay Akışı (ERP-5) kaydı açtı & onay sonrası kalan 85 gün',
    !empty($izt['ok']) && !empty($izt['onay_talep_id']) && !empty($izKar['ok']) && $bak1['kalan'] === 85,
    json_encode([$izt, $bak1])
);

// Resmi Bordro Matematiği: Asgari ücret GV/DV istisnası + kümülatif dilim geçişi
$bAsg = ik_bordro_hesapla_satir(26005.50, 0.0, 0.0, 0.0, 26005.50);
ok('Asgari ücrette (26.005,50 TL) GV=0, DV=0, SGK işçi=%14+1 ve Net=22.104,67 TL',
    $bAsg['gelir_vergisi'] === 0.0 && $bAsg['damga_vergisi'] === 0.0
    && abs($bAsg['net_ucret'] - 22104.67) < 0.02,
    json_encode($bAsg)
);

$bDilim1 = ik_bordro_hesapla_satir(60000.0, 0.0, 0.0);
$bDilim2 = ik_bordro_hesapla_satir(60000.0, 0.0, 150000.0); // 158.000 sınırını geçer → %20 dilimine girer
ok('Kümülatif GV matrahı 158.000 TL dilimini geçince vergi artar (%15 → %20)',
    $bDilim2['gelir_vergisi'] > $bDilim1['gelir_vergisi'],
    "ay1={$bDilim1['gelir_vergisi']} ay4={$bDilim2['gelir_vergisi']}"
);

// Dönem bordro üretimi (idempotent 409) + TDHP 770/335/360/361 tahakkuk
$bd1 = ik_bordro_donem_uret($pdo, 2026, 5, 1);
$bdDup = ik_bordro_donem_uret($pdo, 2026, 5, 1);
ok('2026-05 bordrosu 2 personel için üretildi (tekrarda 409 idempotent)',
    !empty($bd1['ok']) && (int)$bd1['personel_sayisi'] === 2
    && empty($bdDup['ok']) && (int)($bdDup['kod'] ?? 0) === 409,
    json_encode([$bd1, $bdDup])
);

$bm = ik_bordro_muhasebelestir($pdo, (int)$bd1['id'], 1);
$bmDup = ik_bordro_muhasebelestir($pdo, (int)$bd1['id'], 1);
$bmFisId = (int)($bm['fis_id'] ?? 0);
$bmDenge = $bmFisId > 0
    ? $pdo->query("SELECT ROUND(SUM(borc),2) AS b, ROUND(SUM(alacak),2) AS a FROM muh_fis_satirlar WHERE fis_id = $bmFisId")->fetch()
    : null;
$bmHesaplar = $bmFisId > 0
    ? $pdo->query("SELECT hesap_kod FROM muh_fis_satirlar WHERE fis_id = $bmFisId")->fetchAll(PDO::FETCH_COLUMN)
    : [];
ok('Bordro tahakkuku dengeli TDHP (770/335/360/361) yevmiye fişi oluşturdu (tekrarda 409)',
    !empty($bm['ok']) && empty($bmDup['ok']) && (int)($bmDup['kod'] ?? 0) === 409
    && $bmDenge && abs((float)$bmDenge['b'] - (float)$bmDenge['a']) < 0.01
    && in_array('770', $bmHesaplar, true) && in_array('335', $bmHesaplar, true) && in_array('360', $bmHesaplar, true) && in_array('361', $bmHesaplar, true),
    json_encode([$bm, $bmDenge, $bmHesaplar])
);

// ── 5) UI Render (Sıfır Uyarı) & Statik Güvenlik Denetimleri ─────────────────
echo "\n=== 5. UI Render & Statik Güvenlik ===\n";
$hOnay  = onay_merkezi_render('talepler', ['talepler' => onay_talep_listesi($pdo)], true)
        . onay_merkezi_render('kurallar', ['kurallar' => onay_kural_listesi($pdo)], true)
        . onay_merkezi_render('vekalet', ['vekaletler' => onay_vekalet_listesi($pdo)], true);
$hBanka = banka_yonetim_render('hesaplar', ['hesaplar' => $hsList], true)
        . banka_yonetim_render('ekstre', ['hesaplar' => $hsList, 'ekstreler' => banka_ekstre_listesi($pdo)], true)
        . banka_yonetim_render('kredi', ['hesaplar' => $hsList, 'krediler' => $krediler], true)
        . banka_yonetim_render('pos', ['hesaplar' => $hsList, 'poslar' => banka_pos_listesi($pdo)], true);
$hIk    = ik_bordro_render('ozluk', ['ozlukler' => ik_ozluk_listesi($pdo)], true)
        . ik_bordro_render('izin', ['ozlukler' => ik_ozluk_listesi($pdo), 'izinler' => ik_izin_listesi($pdo)], true)
        . ik_bordro_render('bordro', ['ozlukler' => ik_ozluk_listesi($pdo), 'donemler' => ik_bordro_donem_listesi($pdo), 'sec_satirlar' => ik_bordro_satir_listesi($pdo, (int)$bd1['id'])], true);

ok('Tüm ERP Faz 3 ekranları sıfır PHP uyarısı ile çizildi',
    $warnings === [] && str_contains($hOnay, 'Ambalaj') && str_contains($hBanka, 'GAR-TRY-01') && str_contains($hIk, 'Ahmet Yılmaz'),
    implode('; ', $warnings)
);

$uiSrc = (string)file_get_contents($ROOT . '/config/erp_yonetim_ui.php');
ok('config/erp_yonetim_ui.php SIFIR SQL ve SCRIPT_FILENAME guard taşır',
    str_contains($uiSrc, 'SCRIPT_FILENAME') && !preg_match('/->(prepare|query|exec)\s*\(/', $uiSrc)
);
$jsSrc = (string)file_get_contents($ROOT . '/assets/erp_yonetim.js');
ok('assets/erp_yonetim.js localStorage ve innerHTML içermez',
    !str_contains($jsSrc, 'localStorage') && !str_contains($jsSrc, 'innerHTML')
);
$cssSrc = (string)file_get_contents($ROOT . '/assets/erp_yonetim.css');
ok('assets/erp_yonetim.css dark-mode ve mobil <768px 16px kuralı içerir',
    str_contains($cssSrc, 'html[data-theme="dark"]') && str_contains($cssSrc, 'font-size: 16px')
);
$postSrc = (string)file_get_contents($ROOT . '/erp_yonetim_kaydet.php');
ok('erp_yonetim_kaydet.php POST-only (405), require_login, csrf_check ve erp_yon_kapi kullanır',
    str_contains($postSrc, 'http_response_code(405)') && str_contains($postSrc, 'require_login()') && str_contains($postSrc, 'csrf_check(') && str_contains($postSrc, 'erp_yon_kapi(')
);
$swSrc = (string)file_get_contents($ROOT . '/sw.js');
$swVer = preg_match("/CACHE_NAME\s*=\s*'yukleme-plani-v(\d+)'/", $swSrc, $swm) ? (int)$swm[1] : 0;
ok('sw.js ERP Faz 3 uçlarını önbellek dışı tutar ve v590+',
    $swVer >= 590 && str_contains($swSrc, 'onay_|banka_|ik_bordro|erp_yonetim_')
);

echo "\nÖZET: $pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
