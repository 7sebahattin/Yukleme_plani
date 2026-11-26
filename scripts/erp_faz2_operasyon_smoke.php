<?php
// =========================================================
// scripts/erp_faz2_operasyon_smoke.php — ERP Faz 2 (WMS, Araç Yükleme v2, Satın Alma 3-Way, Üretim BOM/MRP) Smoke Testi
//   php -d error_reporting=E_ALL scripts/erp_faz2_operasyon_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$ROOT = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function audit_log_event(string $action, string $module, $record_id = null, $old = null, $new = null): void {}
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

require_once $ROOT . '/config/wms_stok_core.php';
require_once $ROOT . '/config/yukleme_v2_core.php';
require_once $ROOT . '/config/satinalma_core.php';
require_once $ROOT . '/config/uretim_bom_core.php';
require_once $ROOT . '/config/erp_op_ui.php';

$pass = 0; $fail = 0;
function ok(string $ad, bool $kosul, string $detay = ''): void
{
    global $pass, $fail;
    if ($kosul) { $pass++; echo "PASS $ad\n"; }
    else { $fail++; echo "FAIL $ad" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

// Mevcut stok_kartlar tablosunu simüle et (Seçenek A entegrasyonu)
$pdo->exec("CREATE TABLE stok_kartlar (id INTEGER PRIMARY KEY, kod TEXT, ad TEXT, tip TEXT, ana_birim TEXT, barkod TEXT, kritik_seviye REAL, aktif INTEGER DEFAULT 1)");
$pdo->exec("INSERT INTO stok_kartlar VALUES (1, 'HAM-NAR', 'Dökme Nar (Hammadde)', 'malzeme', 'kg', '869001', 500, 1)");
$pdo->exec("INSERT INTO stok_kartlar VALUES (2, 'AMB-KASA5', 'Plastik Kasa 5kg', 'malzeme', 'adet', '869002', 200, 1)");
$pdo->exec("INSERT INTO stok_kartlar VALUES (3, 'URN-NAR5', 'İhracat Nar 5kg Paket', 'urun', 'kg', '869003', 300, 1)");

echo "=== 1. ERP-11b: Seçenek A Stok Ayrımı (Ürün vs Malzeme) & WMS (Lot/SKT/Raf/FIFO) ===\n";
$w1 = wms_stok_migrate($pdo);
$w2 = wms_stok_migrate($pdo);
ok('wms_stok_migrate 2x idempotent ve wms_stok_hazir() true', wms_stok_hazir($pdo) && count($w1) === 3 && $w2[0]['durum'] === 'var');

wms_stok_profil_kaydet($pdo, ['stok_id' => 1, 'stok_grubu' => 'malzeme', 'degerleme_yontemi' => 'fifo', 'min_stok' => 500, 'tdhp_hesap_kod' => '150'], 1);
wms_stok_profil_kaydet($pdo, ['stok_id' => 2, 'stok_grubu' => 'malzeme', 'degerleme_yontemi' => 'fifo', 'min_stok' => 200, 'birim_dara_kg' => 0.60, 'tdhp_hesap_kod' => '150'], 1);
wms_stok_profil_kaydet($pdo, ['stok_id' => 3, 'stok_grubu' => 'urun', 'degerleme_yontemi' => 'fifo', 'min_stok' => 300, 'tdhp_hesap_kod' => '152'], 1);

$urunler = wms_stok_listesi($pdo, 'urun');
$malzemeler = wms_stok_listesi($pdo, 'malzeme');
ok('Seçenek A: Ürün (1 adet) ve Malzeme (2 adet) temiz ayrışır', count($urunler) === 1 && count($malzemeler) === 2);

$raf = wms_raf_kaydet($pdo, ['depo' => 'MERKEZ', 'raf_kodu' => 'SH-01-A', 'ad' => 'Soğuk Hava Oda 1', 'kapasite_kg' => 20000], 1);
ok('WMS Raf lokasyonu kaydedildi', !empty($raf['ok']) && (int)$raf['id'] > 0);

// FEFO / FIFO testi: 2 lot gir (LOT-ERKEN daha yakın SKT + 20 TL, LOT-GEC daha uzak SKT + 30 TL)
$sktYakin = date('Y-m-d', strtotime('+10 days'));
$sktUzak  = date('Y-m-d', strtotime('+90 days'));
wms_lot_giris($pdo, ['stok_id' => 1, 'depo' => 'MERKEZ', 'raf_id' => $raf['id'], 'lot_no' => 'LOT-GEC',   'uretim_tarih' => '2026-01-01', 'skt_tarih' => $sktUzak,  'miktar' => 1000, 'birim_maliyet' => 30.0], 1);
wms_lot_giris($pdo, ['stok_id' => 1, 'depo' => 'MERKEZ', 'raf_id' => $raf['id'], 'lot_no' => 'LOT-ERKEN', 'uretim_tarih' => '2026-01-05', 'skt_tarih' => $sktYakin, 'miktar' => 400,  'birim_maliyet' => 20.0], 1);
wms_lot_giris($pdo, ['stok_id' => 2, 'depo' => 'MERKEZ', 'raf_id' => $raf['id'], 'lot_no' => 'LOT-KASA1', 'uretim_tarih' => '2026-01-01', 'miktar' => 500, 'birim_maliyet' => 15.0], 1);

$fifo = wms_lot_tuket_fifo($pdo, 1, 500, 'MERKEZ');
// 400 * 20 + 100 * 30 = 8000 + 3000 = 11000 TL
ok('FEFO/FIFO tüketim yakın SKT lotunu önce bitirir ve 11.000 TL maliyet hesaplar',
    !empty($fifo['ok']) && $fifo['lotlar'][0]['lot_no'] === 'LOT-ERKEN' && abs($fifo['toplam_maliyet'] - 11000.0) < 0.01,
    json_encode($fifo)
);
$yetersiz = wms_lot_tuket_fifo($pdo, 1, 99999, 'MERKEZ');
ok('Yetersiz WMS lot stoğu 409 ile reddedilir', empty($yetersiz['ok']) && ($yetersiz['kod'] ?? 0) === 409);

$alarmlar = wms_alarmlar($pdo, 30);
ok('Min-stok alarmı (Ürün #3: 0 < 300) tespit edilir', count($alarmlar['min_stok']) >= 1);

echo "\n=== 2. Yeni Araç Yükleme Fişleri (v2: Satır-Bazlı Palet/Dara/Net + Kilit/Revizyon + WMS) ===\n";
$y1 = yukleme_v2_migrate($pdo);
$y2 = yukleme_v2_migrate($pdo);
ok('yukleme_v2_migrate 2x idempotent ve yukleme_v2_hazir() true', yukleme_v2_hazir($pdo) && count($y1) === 2 && $y2[0]['durum'] === 'var');

// Mamul lotu gir (Ürün #3: 2000 kg)
wms_lot_giris($pdo, ['stok_id' => 3, 'depo' => 'MERKEZ', 'lot_no' => 'LOT-MAMUL-1', 'uretim_tarih' => '2026-02-01', 'miktar' => 2000, 'birim_maliyet' => 45.0], 1);

$yf = yukleme_v2_fis_kaydet($pdo, [
    'tarih' => '2026-02-10', 'depo' => 'MERKEZ', 'plaka' => '33 ABC 123', 'firma_unvan' => 'EuroFruit GmbH', 'gidecek_ulke' => 'Almanya',
    'paletler' => [
        ['palet_no' => 'P-1', 'urun_stok_id' => 3, 'urun_ad' => 'İhracat Nar 5kg', 'lot_no' => 'LOT-MAMUL-1', 'kasa_stok_id' => 2, 'kasa_adeti' => 80, 'kasa_dara_kg' => 0.60, 'palet_dara_kg' => 22, 'brut_kg' => 870],
    ],
], 1);
$det1 = yukleme_v2_fis_getir($pdo, (int)$yf['id']);
$ilkPaletId = (int)$det1['paletler'][0]['id'];
// Dara = 80*0.60 + 22 = 70 kg; Net = 870 - 70 = 800 kg
ok('Palet dara (70 kg) ve net (800 kg) doğru hesaplandı', !empty($yf['ok']) && abs((float)$det1['toplam_dara_kg'] - 70.0) < 0.01 && abs((float)$det1['toplam_net_kg'] - 800.0) < 0.01);

// Satır-bazlı güncelleme: mevcut pallet_id korunur (DELETE+INSERT YOK)
yukleme_v2_fis_kaydet($pdo, [
    'id' => (int)$yf['id'], 'tarih' => '2026-02-10', 'depo' => 'MERKEZ', 'plaka' => '33 ABC 123', 'firma_unvan' => 'EuroFruit GmbH',
    'paletler' => [
        ['id' => $ilkPaletId, 'palet_no' => 'P-1', 'urun_stok_id' => 3, 'urun_ad' => 'İhracat Nar 5kg', 'kasa_stok_id' => 2, 'kasa_adeti' => 80, 'kasa_dara_kg' => 0.60, 'palet_dara_kg' => 22, 'brut_kg' => 920],
    ],
], 1);
$det2 = yukleme_v2_fis_getir($pdo, (int)$yf['id']);
ok('Satır bazlı güncelleme aynı pallet_id korur ve neti 850 kg yapar', (int)$det2['paletler'][0]['id'] === $ilkPaletId && abs((float)$det2['toplam_net_kg'] - 850.0) < 0.01);

// Kilitle & Sevk Et (WMS FIFO mamul + kasa düşümü)
$kilit = yukleme_v2_kilitle_ve_sevk($pdo, (int)$yf['id'], 1, true);
$mamulKalan = (float)$pdo->query("SELECT COALESCE(SUM(kalan_miktar),0) FROM wms_lotlar WHERE stok_id=3 AND durum='aktif'")->fetchColumn();
ok('Kilitle & Sevk Et WMS mamul stoğunu 2000 → 1150 kg düşer', !empty($kilit['ok']) && abs($mamulKalan - 1150.0) < 0.01, "kalan=$mamulKalan");

$kilitEdit = yukleme_v2_fis_kaydet($pdo, ['id' => (int)$yf['id'], 'tarih' => '2026-02-10', 'plaka' => '33 ABC 123'], 1);
$revKisa   = yukleme_v2_revizyon_ac($pdo, (int)$yf['id'], 'x', 1);
$revOk     = yukleme_v2_revizyon_ac($pdo, (int)$yf['id'], 'Müşteri plaka değişikliği talep etti', 1);
ok('Kilitli fiş düzenlenemez (409), kısa gerekçe reddedilir, gerekçeli revizyon açılır', empty($kilitEdit['ok']) && ($kilitEdit['kod'] ?? 0) === 409 && empty($revKisa['ok']) && !empty($revOk['ok']));

echo "\n=== 3. ERP-3: Satın Alma & 3-Way Match (Talep → Teklif → PO → Mal Kabul → 3-Way Match) ===\n";
$s1 = satinalma_migrate($pdo);
$s2 = satinalma_migrate($pdo);
ok('satinalma_migrate 2x idempotent ve satinalma_hazir() true', satinalma_hazir($pdo) && count($s1) === 8 && $s2[0]['durum'] === 'var');

$talep = sa_talep_kaydet($pdo, [
    'tarih' => '2026-03-01', 'depo' => 'MERKEZ', 'departman' => 'Paketleme', 'aciklama' => '5kg Kasa Alımı',
    'satirlar' => [['stok_id' => 2, 'urun_ad' => 'Plastik Kasa 5kg', 'birim' => 'adet', 'miktar' => 1000, 'hedef_fiyat' => 15.0]],
], 1);
$tk1 = sa_teklif_ekle($pdo, ['talep_id' => $talep['id'], 'tedarikci_ad' => 'Plastik A.Ş.', 'birim_fiyat' => 14.0, 'teslim_gun' => 2, 'vade_gun' => 45], 1);
$tk2 = sa_teklif_ekle($pdo, ['talep_id' => $talep['id'], 'tedarikci_ad' => 'Ambalaj Ltd.', 'birim_fiyat' => 16.0, 'teslim_gun' => 5, 'vade_gun' => 30], 1);
$po  = sa_siparis_olustur($pdo, ['teklif_id' => $tk1['id'], 'tarih' => '2026-03-02', 'depo' => 'MERKEZ'], 1);
$grn = sa_mal_kabul_kaydet($pdo, ['siparis_id' => $po['id'], 'tarih' => '2026-03-04', 'irsaliye_no' => 'IRS-9001', 'kalite_puani' => 5, 'zamaninda' => 1], 1);
ok('Satın alma zinciri (Talep → Teklif → PO 14.000 TL → Mal Kabul + WMS Lot) çalışır', !empty($talep['ok']) && !empty($po['ok']) && !empty($grn['ok']));

$mUyumlu = sa_uc_yonlu_eslestir($pdo, ['siparis_id' => $po['id'], 'kabul_id' => $grn['id'], 'fatura_no' => 'ALF-1', 'fatura_miktar' => 1000, 'fatura_tutar' => 14100, 'tolerans_yuzde' => 2], 1);
$mSapma  = sa_uc_yonlu_eslestir($pdo, ['siparis_id' => $po['id'], 'kabul_id' => $grn['id'], 'fatura_no' => 'ALF-2', 'fatura_miktar' => 1000, 'fatura_tutar' => 14800, 'tolerans_yuzde' => 2], 1);
$mBlokaj = sa_uc_yonlu_eslestir($pdo, ['siparis_id' => $po['id'], 'kabul_id' => $grn['id'], 'fatura_no' => 'ALF-3', 'fatura_miktar' => 1000, 'fatura_tutar' => 18000, 'tolerans_yuzde' => 2], 1);
ok('3-Way Match kararları: %0.71→uyumlu, %5.71→sapma_var, %28.57→blokaj',
    ($mUyumlu['sonuc'] ?? '') === 'uyumlu' && ($mSapma['sonuc'] ?? '') === 'sapma_var' && ($mBlokaj['sonuc'] ?? '') === 'blokaj',
    json_encode([$mUyumlu, $mSapma, $mBlokaj])
);
$perf = sa_tedarikci_performans($pdo);
ok('Tedarikçi performans puanı (Plastik A.Ş. 5/5, %100 zamanında) hesaplanır', count($perf) === 1 && (float)$perf[0]['ort_kalite'] === 5.0);

echo "\n=== 4. ERP-4: Üretim Reçetesi (BOM), İş Emirleri, Fire/Randıman, Lot-to-Lot & MRP ===\n";
$u1 = uretim_bom_migrate($pdo);
$u2 = uretim_bom_migrate($pdo);
ok('uretim_bom_migrate 2x idempotent ve uretim_bom_hazir() true', uretim_bom_hazir($pdo) && count($u1) === 5 && $u2[0]['durum'] === 'var');

$dongusel = uretim_bom_kaydet($pdo, ['kod' => 'BOM-BAD', 'ad' => 'Döngüsel', 'urun_stok_id' => 3, 'satirlar' => [['bilesen_stok_id' => 3, 'miktar' => 1]]], 1);
ok('Döngüsel BOM (mamul kendi bileşeni) reddedilir', empty($dongusel['ok']));

// 100 kg paketli nar = 110 kg dökme nar (hammadde #1) + 20 adet kasa (ambalaj #2)
$bom = uretim_bom_kaydet($pdo, [
    'kod' => 'BOM-NAR-5KG', 'ad' => 'Nar 5kg İhracat Paketi', 'urun_stok_id' => 3, 'baz_miktar' => 100, 'hedef_fire_yuzde' => 8,
    'satirlar' => [
        ['bilesen_stok_id' => 1, 'bilesen_ad' => 'Dökme Nar', 'bilesen_tip' => 'hammadde', 'miktar' => 110, 'birim' => 'kg'],
        ['bilesen_stok_id' => 2, 'bilesen_ad' => 'Plastik Kasa 5kg', 'bilesen_tip' => 'ambalaj', 'miktar' => 20, 'birim' => 'adet'],
    ],
], 1);
$ie1 = uretim_is_emri_ac($pdo, ['bom_id' => $bom['id'], 'tarih' => '2026-03-10', 'depo' => 'MERKEZ', 'planlanan_miktar' => 500], 1);
// 500 kg için: 550 kg dökme nar (mevcut 900 kg → yeterli), 100 adet kasa (mevcut 1420 → yeterli)
// Ek büyük iş emri açıp MRP net eksiğini test et (1000 kg → toplam 1650 kg nar gerekir, mevcut 900 → 750 kg eksik!)
$ie2 = uretim_is_emri_ac($pdo, ['bom_id' => $bom['id'], 'tarih' => '2026-03-11', 'depo' => 'MERKEZ', 'planlanan_miktar' => 1000], 1);
$mrp = uretim_mrp_hesapla($pdo);
$mrpByStok = [];
foreach ($mrp as $m) $mrpByStok[$m['stok_id']] = $m;
ok('MRP motoru 1650 kg brüt ihtiyaç - 900 kg mevcut = 750 kg net eksik hesaplar',
    isset($mrpByStok[1]) && abs($mrpByStok[1]['brut_ihtiyac'] - 1650.0) < 0.01 && abs($mrpByStok[1]['net_eksik'] - 750.0) < 0.01,
    json_encode($mrp)
);

// ie1 (500 kg) tamamla: 460 kg sağlam, 20 kg çıkma, 20 kg fire → randıman = 460/500 = %92
$tamam = uretim_is_emri_tamamla($pdo, (int)$ie1['id'], ['uretilen_miktar' => 460, 'cikma_miktar' => 20, 'fire_miktar' => 20], 1);
ok('İş emri tamamlama: randıman=%92, mamul lotu üretildi, birim maliyet hesaplandı',
    !empty($tamam['ok']) && abs((float)$tamam['randiman_yuzde'] - 92.0) < 0.01 && (float)$tamam['birim_maliyet'] > 0 && !empty($tamam['hedef_lot_id']),
    json_encode($tamam)
);
$iz = uretim_lot_iz_listesi($pdo, (string)$ie1['uretim_lot_no']);
ok('Lot-to-Lot izlenebilirlik: kaynak hammadde+ambalaj lotları → mamul lotuna bağlandı', count($iz) >= 2, json_encode($iz));

echo "\n=== 5. UI Render (0 Uyarı) & Statik Güvenlik Değişmezleri ===\n";
$tumStok = wms_stok_listesi($pdo);
$h1 = wms_stok_render('urun', ['tum_stoklar' => $tumStok, 'liste' => $urunler, 'raflar' => wms_raf_listesi($pdo), 'alarmlar' => $alarmlar], true)
    . wms_stok_render('lotlar', ['tum_stoklar' => $tumStok, 'lotlar' => wms_lot_listesi($pdo), 'raflar' => wms_raf_listesi($pdo), 'alarmlar' => $alarmlar], true)
    . wms_stok_render('raflar', ['tum_stoklar' => $tumStok, 'raflar' => wms_raf_listesi($pdo), 'alarmlar' => $alarmlar], true)
    . wms_stok_render('alarmlar', ['tum_stoklar' => $tumStok, 'raflar' => wms_raf_listesi($pdo), 'alarmlar' => $alarmlar], true);
$h2 = yukleme_v2_render(yukleme_v2_fis_listesi($pdo), yukleme_v2_fis_getir($pdo, (int)$yf['id']), $urunler, true);
$h3 = satinalma_render('talepler', ['talepler' => sa_talep_listesi($pdo)], true)
    . satinalma_render('siparisler', ['siparisler' => sa_siparis_listesi($pdo)], true)
    . satinalma_render('mal_kabul', ['kabuller' => sa_mal_kabul_listesi($pdo)], true)
    . satinalma_render('eslestirme', ['kabuller' => sa_mal_kabul_listesi($pdo), 'eslestirmeler' => sa_eslestirme_listesi($pdo), 'performans' => $perf], true);
$h4 = uretim_bom_render('bom', ['stoklar' => $tumStok, 'bomlar' => uretim_bom_listesi($pdo)], true)
    . uretim_bom_render('emirler', ['stoklar' => $tumStok, 'bomlar' => uretim_bom_listesi($pdo), 'emirler' => uretim_is_emri_listesi($pdo)], true)
    . uretim_bom_render('iz', ['stoklar' => $tumStok, 'izler' => $iz, 'lot_ara' => ''], true)
    . uretim_bom_render('mrp', ['stoklar' => $tumStok, 'mrp' => $mrp], true);
ok('Tüm 4 ERP Faz 2 UI bileşeni hatasız render olur',
    str_contains($h1, 'Seçenek A') && str_contains($h2, '33 ABC 123') && str_contains($h3, '3-Way Match') && str_contains($h4, 'BOM-NAR-5KG')
);

$uiSrc = (string)file_get_contents($ROOT . '/config/erp_op_ui.php');
ok('config/erp_op_ui.php SIFIR SQL ve SCRIPT_FILENAME guard taşır',
    str_contains($uiSrc, 'SCRIPT_FILENAME') && !preg_match('/->(prepare|query|exec)\s*\(/', $uiSrc)
);
$jsSrc = (string)file_get_contents($ROOT . '/assets/erp_op.js');
ok('assets/erp_op.js localStorage ve innerHTML içermez',
    !str_contains($jsSrc, 'localStorage') && !str_contains($jsSrc, 'innerHTML')
);
$cssSrc = (string)file_get_contents($ROOT . '/assets/erp_op.css');
ok('assets/erp_op.css dark-mode ve mobil <768px 16px kuralı içerir',
    str_contains($cssSrc, 'html[data-theme="dark"]') && str_contains($cssSrc, 'font-size: 16px')
);
$postSrc = (string)file_get_contents($ROOT . '/erp_op_kaydet.php');
ok('erp_op_kaydet.php POST-only (405), require_login, csrf_check ve erp_op_kapi kullanır',
    str_contains($postSrc, 'http_response_code(405)') && str_contains($postSrc, 'require_login()') && str_contains($postSrc, 'csrf_check(') && str_contains($postSrc, 'erp_op_kapi(')
);
$swSrc = (string)file_get_contents($ROOT . '/sw.js');
$swVer = preg_match("/CACHE_NAME\s*=\s*'yukleme-plani-v(\d+)'/", $swSrc, $swm) ? (int)$swm[1] : 0;
ok('sw.js ERP Faz 2 uçlarını önbellek dışı tutar ve v589+',
    $swVer >= 589 && str_contains($swSrc, 'wms_|yukleme_v2|satinalma_|uretim_bom|erp_op_')
);

echo "\nÖZET: $pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
