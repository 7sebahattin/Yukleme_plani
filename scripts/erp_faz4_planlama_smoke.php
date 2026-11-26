<?php
// =========================================================
// scripts/erp_faz4_planlama_smoke.php — ERP Faz 4 (ERP-8 CRM/Fiyat, ERP-9 Sabit Kıymet/Bakım, ERP-10 Bütçe/Aging/Nakit Akış)
// Çalıştırma: php scripts/erp_faz4_planlama_smoke.php
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
require_once $ROOT . '/config/crm_core.php';
require_once $ROOT . '/config/sabit_kiymet_core.php';
require_once $ROOT . '/config/finans_plan_core.php';
require_once $ROOT . '/config/erp_planlama_ui.php';

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

// ── 1) 2× İdempotent Migrasyon ──────────────────────────────────────────────
echo "=== 1. Migrasyonlar (2× İdempotent) ===\n";
muhasebe_migrate($pdo);
banka_migrate($pdo);
ik_bordro_migrate($pdo);
crm_migrate($pdo);          $rCrm2 = crm_migrate($pdo);
sabit_kiymet_migrate($pdo); $rSk2  = sabit_kiymet_migrate($pdo);
finans_plan_migrate($pdo);  $rFp2  = finans_plan_migrate($pdo);

ok('ERP-8/9/10 tüm tablolar hazır', crm_hazir($pdo) && sabit_kiymet_hazir($pdo) && finans_plan_hazir($pdo));
ok('2. tur migrasyonlar idempotent (hepsi mevcut)',
    !in_array('olusturuldu', array_column($rCrm2, 'durum'), true)
    && !in_array('olusturuldu', array_column($rSk2, 'durum'), true)
    && !in_array('olusturuldu', array_column($rFp2, 'durum'), true)
);

// ── 2) ERP-8: CRM Pipeline, Fiyat Listeleri, Teklif→Sipariş & Claim ─────────
echo "\n=== 2. ERP-8 CRM & Fiyat Listeleri ===\n";
$f1 = crm_firsat_kaydet($pdo, [
    'musteri_ad'    => 'EuroFresh GmbH',
    'baslik'        => '2026 Yaz Kiraz İhracat Programı',
    'asama'         => 'gorusme',
    'tahmini_tutar' => 180000,
    'para_birimi'   => 'EUR',
], 1);
$ak1 = crm_aktivite_ekle($pdo, ['firsat_id' => (int)($f1['id'] ?? 0), 'tarih' => '2026-05-10', 'tur' => 'gorusme', 'ozet' => 'Numune onaylandı'], 1);
$as1 = crm_firsat_asama_guncelle($pdo, (int)($f1['id'] ?? 0), 'teklif', 1);
ok('Fırsat, aktivite ve aşama güncellemesi çalışır', !empty($f1['ok']) && !empty($ak1['ok']) && !empty($as1['ok']));

$fl = crm_fiyat_listesi_kaydet($pdo, [
    'kod'                => 'EUR-2026-YAZ',
    'ad'                 => 'Avrupa İhracat Listesi',
    'musteri_grubu'      => 'IHRACAT',
    'para_birimi'        => 'EUR',
    'varsayilan_iskonto' => 5.0,
], 1);
$flId = (int)($fl['id'] ?? 0);
crm_fiyat_satir_ekle($pdo, ['liste_id' => $flId, 'urun_ad' => 'Napolyon Kiraz', 'birim' => 'kg', 'birim_fiyat' => 4.00, 'min_miktar' => 0, 'iskonto_yuzde' => 0], 1);
crm_fiyat_satir_ekle($pdo, ['liste_id' => $flId, 'urun_ad' => 'Napolyon Kiraz', 'birim' => 'kg', 'birim_fiyat' => 3.50, 'min_miktar' => 5000, 'iskonto_yuzde' => 10], 1);

$pAz  = crm_fiyat_hesapla($pdo, $flId, 'Napolyon Kiraz', 1000); // 4.00 × 0.95 = 3.80
$pCok = crm_fiyat_hesapla($pdo, $flId, 'Napolyon Kiraz', 6000); // 3.50 × 0.90 = 3.15
ok('Kademeli fiyat çözücü: 1000kg → 3.80 EUR, 6000kg → 3.15 EUR',
    $pAz['bulundu'] && abs($pAz['net_birim_fiyat'] - 3.80) < 0.001
    && $pCok['bulundu'] && abs($pCok['net_birim_fiyat'] - 3.15) < 0.001,
    json_encode([$pAz, $pCok])
);

// Fiyat listesinden otomatik fiyat çeken teklif + idempotent siparişe dönüşüm
$tk = crm_teklif_kaydet($pdo, [
    'firsat_id'      => (int)$f1['id'],
    'fiyat_liste_id' => $flId,
    'musteri_ad'     => 'EuroFresh GmbH',
    'urun_ozet'      => 'Napolyon Kiraz',
    'miktar'         => 6000,
    'birim_fiyat'    => 0, // listeden otomatik çekilecek (3.50, %10 iskonto → 18.900 ara toplam)
    'kdv_orani'      => 0,
    'para_birimi'    => 'EUR',
], 1);
$don1 = crm_teklif_siparise_donustur($pdo, (int)($tk['id'] ?? 0), 1);
$don2 = crm_teklif_siparise_donustur($pdo, (int)($tk['id'] ?? 0), 1);
$fAsamaSon = $pdo->query('SELECT asama FROM crm_firsatlar WHERE id = ' . (int)$f1['id'])->fetchColumn();
ok('Teklif listeden otomatik fiyat aldı (18.900 EUR), siparişe dönüştü (tekrarda 409) & fırsat KAZANILDI',
    !empty($tk['ok']) && abs((float)$tk['genel_toplam'] - 18900.0) < 0.01
    && !empty($don1['ok']) && empty($don2['ok']) && (int)($don2['kod'] ?? 0) === 409
    && $fAsamaSon === 'kazanildi',
    json_encode([$tk, $don1, $fAsamaSon])
);

$cl = crm_claim_kaydet($pdo, ['musteri_ad' => 'EuroFresh GmbH', 'sevkiyat_ref' => 'TC900512.01', 'kategori' => 'kalite', 'talep_tutar' => 1200, 'para_birimi' => 'EUR', 'aciklama' => '2 palet soğuk zincir'], 1);
$clCoz = crm_claim_sonuclandir($pdo, (int)($cl['id'] ?? 0), 'cozuldu', 850.0, 'Kısmi kredi notu onaylandı', 1);
ok('Müşteri kalite claim kaydı açıldı ve 850 EUR ile çözüldü', !empty($cl['ok']) && !empty($clCoz['ok']));

// ── 3) ERP-9: Sabit Kıymet, Normal/Azalan Amortisman (770/257) & Bakım ──────
echo "\n=== 3. ERP-9 Sabit Kıymet, Amortisman & Bakım ===\n";
$skN = sk_kart_kaydet($pdo, [
    'kod'              => 'MAK-001',
    'ad'               => 'Otomatik Boylama Hattı',
    'kategori'         => 'makine',
    'alis_tarih'       => '2026-01-15',
    'alis_bedeli'      => 200000,
    'hurda_degeri'     => 0,
    'faydali_omur_yil' => 5,
    'yontem'           => 'normal',
    'zimmet_kisi'      => 'Üretim Şefi',
], 1);
$skA = sk_kart_kaydet($pdo, [
    'kod'              => 'ARC-001',
    'ad'               => 'Frigorifik Kamyon',
    'kategori'         => 'arac',
    'alis_tarih'       => '2026-01-15',
    'alis_bedeli'      => 100000,
    'hurda_degeri'     => 0,
    'faydali_omur_yil' => 5,
    'yontem'           => 'azalan', // %40 azalan: 40000, 24000, 14400, 8640, 12960 = 100000
], 1);
$kartlar = sk_kart_listesi($pdo);
$azPlan = [];
$normAmId = 0;
foreach ($kartlar as $k) {
    if ($k['kod'] === 'ARC-001') $azPlan = $k['amortismanlar'];
    if ($k['kod'] === 'MAK-001') $normAmId = (int)($k['amortismanlar'][0]['id'] ?? 0);
}
$azToplam = round(array_sum(array_map(static fn($x) => (float)$x['donem_tutar'], $azPlan)), 2);
ok('Normal (5×40.000) & Azalan Bakiyeler (%40, 1.yıl=40.000, toplam=100.000) planları doğru',
    !empty($skN['ok']) && !empty($skA['ok'])
    && count($azPlan) === 5 && abs((float)$azPlan[0]['donem_tutar'] - 40000.0) < 0.01
    && abs((float)$azPlan[1]['donem_tutar'] - 24000.0) < 0.01
    && abs($azToplam - 100000.0) < 0.01,
    json_encode($azPlan)
);

// Amortisman yevmiye fişi (730/257) + idempotent 409
$amFis1 = sk_amortisman_muhasebelestir($pdo, $normAmId, 1);
$amFis2 = sk_amortisman_muhasebelestir($pdo, $normAmId, 1);
$amFid  = (int)($amFis1['fis_id'] ?? 0);
$amHesap = $amFid > 0 ? $pdo->query("SELECT hesap_kod FROM muh_fis_satirlar WHERE fis_id = $amFid")->fetchAll(PDO::FETCH_COLUMN) : [];
ok('Amortisman yevmiye fişi (730/257) dengeli oluşturuldu (tekrarda 409)',
    !empty($amFis1['ok']) && empty($amFis2['ok']) && (int)($amFis2['kod'] ?? 0) === 409
    && in_array('730', $amHesap, true) && in_array('257', $amHesap, true),
    json_encode([$amFis1, $amHesap])
);

$bk = sk_bakim_kaydet($pdo, ['kart_id' => (int)$skN['id'], 'bakim_tarih' => '2026-05-10', 'sonraki_bakim_tarih' => '2026-11-10', 'tur' => 'periyodik', 'maliyet' => 4500, 'aciklama' => 'Rulman değişimi'], 1);
ok('Periyodik makine bakım kaydı eklendi', !empty($bk['ok']));

// ── 4) ERP-10: Bütçe & Sapma, Vade Yaşlandırma (Aging) & Nakit Akış ─────────
echo "\n=== 4. ERP-10 Bütçe, Vade Yaşlandırma (Aging) & Nakit Akış ===\n";
$b1 = fp_butce_kaydet($pdo, ['yil' => 2026, 'ay' => 12, 'departman' => 'URETIM', 'hesap_kod' => '730', 'tur' => 'gider', 'planlanan_tutar' => 30000], 1);
$bRap = fp_butce_raporu($pdo, 2026);
// 2026-12'de az önce kestiğimiz 40.000 TL 730 amortisman fişi → 30.000 bütçeyi aşmalı!
ok('Bütçe raporu TDHP 730 fişini (40.000 TL) otomatik okudu ve bütçe aşımını yakaladı',
    !empty($b1['ok']) && !empty($bRap[0]['asim']) && abs((float)$bRap[0]['gerceklesen_etkin'] - 40000.0) < 0.01,
    json_encode($bRap)
);

// Aging: 2026-05-20 referans tarihiyle 10 gün (0-30), 45 gün (31-60), 130 gün (120+) gecikmeli + güncel kalemler
fp_nakit_kalem_kaydet($pdo, ['vade_tarih' => '2026-05-10', 'yon' => 'giris', 'karsi_taraf' => 'Müşteri A', 'tutar' => 10000], 1); // 10 gün → g0_30
fp_nakit_kalem_kaydet($pdo, ['vade_tarih' => '2026-04-05', 'yon' => 'giris', 'karsi_taraf' => 'Müşteri B', 'tutar' => 20000], 1); // 45 gün → g31_60
fp_nakit_kalem_kaydet($pdo, ['vade_tarih' => '2026-01-05', 'yon' => 'cikis', 'karsi_taraf' => 'Tedarikçi X', 'tutar' => 15000], 1); // 135 gün → g120_plus
fp_nakit_kalem_kaydet($pdo, ['vade_tarih' => '2026-05-28', 'yon' => 'cikis', 'karsi_taraf' => 'Tedarikçi Y', 'tutar' => 80000], 1); // güncel çıkış (nakit açığı tetikler)

$ag = fp_vade_yaslandirma($pdo, '2026-05-20');
ok('Vade Yaşlandırma 30/60/90/120+ kovaları doğru dağıldı',
    abs($ag['alacak']['g0_30'] - 10000.0) < 0.01
    && abs($ag['alacak']['g31_60'] - 20000.0) < 0.01
    && abs($ag['borc']['g120_plus'] - 15000.0) < 0.01
    && abs($ag['borc']['guncel'] - 80000.0) < 0.01,
    json_encode([$ag['alacak'], $ag['borc']])
);

// Banka açılış bakiyesi 50.000 TL → 80.000 TL çıkışla negatif nakit riski yakalanmalı!
banka_hesap_kaydet($pdo, ['kod' => 'BNK-01', 'banka_ad' => 'Ziraat', 'acilis_bakiye' => 50000], 1);
$proj = fp_nakit_akis_projeksiyon($pdo, '2026-05-20', 60);
ok('Nakit Akış Projeksiyonu açılış (50.000) + planlı çıkışı (80.000) hesapladı ve NEGATİF RİSK verdi',
    abs($proj['acilis_nakit'] - 50000.0) < 0.01
    && $proj['negatif_risk'] === true
    && $proj['en_dusuk_bakiye'] <= -30000.0,
    json_encode(['acilis' => $proj['acilis_nakit'], 'min' => $proj['en_dusuk_bakiye'], 'risk' => $proj['negatif_risk']])
);

// ── 5) UI Render (Sıfır Uyarı) & Statik Güvenlik ────────────────────────────
echo "\n=== 5. UI Render & Statik Güvenlik ===\n";
$hCrm = crm_yonetim_render('pipeline', ['firsatlar' => crm_firsat_listesi($pdo)], true)
      . crm_yonetim_render('fiyat', ['fiyat_listeleri' => crm_fiyat_listesi_tumu($pdo)], true)
      . crm_yonetim_render('teklif', ['fiyat_listeleri' => crm_fiyat_listesi_tumu($pdo), 'teklifler' => crm_teklif_listesi($pdo)], true)
      . crm_yonetim_render('claim', ['claimler' => crm_claim_listesi($pdo)], true);
$hSk  = sabit_kiymet_render('kartlar', ['kartlar' => sk_kart_listesi($pdo)], true)
      . sabit_kiymet_render('bakim', ['kartlar' => sk_kart_listesi($pdo), 'bakimlar' => sk_bakim_listesi($pdo)], true);
$hFp  = finans_planlama_render('aging', ['aging' => $ag], true)
      . finans_planlama_render('nakit', ['projeksiyon' => $proj], true)
      . finans_planlama_render('butce', ['butceler' => $bRap], true);

ok('Tüm ERP Faz 4 ekranları sıfır PHP uyarısı ile çizildi',
    $warnings === [] && str_contains($hCrm, 'EuroFresh GmbH') && str_contains($hSk, 'MAK-001') && str_contains($hFp, 'NEGATİF NAKİT RİSKİ'),
    implode('; ', $warnings)
);

$uiSrc = (string)file_get_contents($ROOT . '/config/erp_planlama_ui.php');
ok('config/erp_planlama_ui.php SIFIR SQL ve SCRIPT_FILENAME guard taşır',
    str_contains($uiSrc, 'SCRIPT_FILENAME') && !preg_match('/->(prepare|query|exec)\s*\(/', $uiSrc)
);
$jsSrc = (string)file_get_contents($ROOT . '/assets/erp_planlama.js');
ok('assets/erp_planlama.js localStorage ve innerHTML içermez',
    !str_contains($jsSrc, 'localStorage') && !str_contains($jsSrc, 'innerHTML')
);
$cssSrc = (string)file_get_contents($ROOT . '/assets/erp_planlama.css');
ok('assets/erp_planlama.css dark-mode ve mobil <768px 16px kuralı içerir',
    str_contains($cssSrc, 'html[data-theme="dark"]') && str_contains($cssSrc, 'font-size: 16px')
);
$postSrc = (string)file_get_contents($ROOT . '/erp_plan_kaydet.php');
ok('erp_plan_kaydet.php POST-only (405), require_login, csrf_check ve erp_plan_kapi kullanır',
    str_contains($postSrc, 'http_response_code(405)') && str_contains($postSrc, 'require_login()') && str_contains($postSrc, 'csrf_check(') && str_contains($postSrc, 'erp_plan_kapi(')
);
$swSrc = (string)file_get_contents($ROOT . '/sw.js');
$swVer = preg_match("/CACHE_NAME\s*=\s*'yukleme-plani-v(\d+)'/", $swSrc, $swm) ? (int)$swm[1] : 0;
ok('sw.js ERP Faz 4 uçlarını önbellek dışı tutar ve v591+',
    $swVer >= 591 && str_contains($swSrc, 'crm_|sabit_kiymet|finans_planlama|erp_plan_')
);

echo "\nÖZET: $pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
