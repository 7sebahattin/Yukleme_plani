<?php
// =========================================================
// scripts/stok_core_smoke.php — Stok (muhasebe) çekirdeği (config/stok_core.php) davranış testleri — Parça A
//   php scripts/stok_core_smoke.php      → çıkış kodu 0 = hepsi geçti
// Geçici dosya SQLite + stub auth; canlı DB'ye HİÇ dokunmaz. Kapsam: şema/tohum · kapı · miktar ayrıştırma/biçim · fiş no · geri ·
// kart CRUD (TR-duyarsız kod, ana birim kilidi, sil/hareketli) · liste/bakiye/depo filtresi · hareket listesi (yürüyen bakiye) ·
// maliyet motoru (ağırlıklı ortalama, eksi stok, ters satır) · tanımlar (sistem kilidi, kullanımda) · içe aktar (idempotent) · audit ·
// Parça B (§12–19): fiş doğrulama · giriş/çıkış/transfer/dönüşüm defteri · yetersiz stok/eksiye izin · idempotent · iptal · cari bağı · kantar.
// =========================================================
require_once __DIR__ . '/_stok_test_lib.php';
$db = db();
$IS_ADMIN = true; $UID = 1;
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function audit_say(string $eylem): int { return say('audit_log', "module = 'stok' AND action = ?", [$eylem]); }

echo "=== 1. Şema / tohum ===\n";
ok('kurulum öncesi stok_hazir() = false', !stok_hazir($db));
ok('stok_tablolar: 5 tablo, hepsi CREATE TABLE IF NOT EXISTS, FK/ALTER yok', count(stok_tablolar()) === 5
    && !array_filter(stok_tablolar(), fn($d) => !str_starts_with($d, 'CREATE TABLE IF NOT EXISTS') || preg_match('/FOREIGN KEY|ALTER TABLE/i', $d)));
$rap = stok_t_sema_kur($db);
ok('stok_migrate: 5 tablo oluşturuldu', count($rap) === 5 && !array_filter($rap, fn($r) => $r['durum'] !== 'olusturuldu'), json_encode($rap, JSON_UNESCAPED_UNICODE));
ok('stok_hazir() = true', stok_hazir($db));
ok('tohum: 1 grup (Genel) + 4 giriş + 6 çıkış nedeni, hepsi sistem kodlu (grup hariç)', say('stok_tanimlar', "tur='grup'") === 1 && say('stok_tanimlar', "tur='giris_neden' AND kod IS NOT NULL") === 4
    && say('stok_tanimlar', "tur='cikis_neden' AND kod IS NOT NULL") === 6 && say('stok_tanimlar', "tur='grup' AND kod IS NULL") === 1);
$rap2 = stok_migrate($db);
ok('ikinci migrate: hepsi "var", tohum çoğalmaz', !array_filter($rap2, fn($r) => $r['durum'] !== 'var') && say('stok_tanimlar') === 11);

echo "\n=== 2. Kapı ===\n";
ok('stok_can(read|write|delete|admin) admin için true', stok_can('read') && stok_can('write') && stok_can('delete') && stok_can('admin'));
ok('stok_can bilinmeyen eylem false', !stok_can('sil') && !stok_can(''));
$IS_ADMIN = false;
ok('yönetici değilse hepsi false (stok.read/write izinleri olsa bile)', (function () { global $PERMS; $PERMS = ['stok.read', 'stok.write']; $r = !stok_can('read') && !stok_can('write'); $PERMS = []; return $r; })());
$IS_ADMIN = true;
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
ok('stok_json_mi: X-Requested-With fetch', stok_json_mi());
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
ok('stok_json_mi: düz istek false', !stok_json_mi());

echo "\n=== 3. Miktar ayrıştırma / biçim ===\n";
$durumlar = [
    ['1.250', 'kg', 1250000], ['1250', 'kg', 1250000], ['12.500.000', 'kg', 12500000000], ['1250,5', 'lt', 1250500], ['1.234,567', 'lt', 1234567], ['12.5', 'lt', 12500],
    [' 1 250 ', 'adet', 1250000], [5, 'kg', 5000], ['1250,5', 'kg', null], ['12.5', 'kg', null], ['0', 'kg', null], ['-5', 'kg', null], ['abc', 'kg', null],
    ['1,2345', 'lt', null], ['', 'kg', null], [[1], 'kg', null], [true, 'kg', null], ['9999999999999', 'kg', null], ['0,001', 'm', 1],
];
foreach ($durumlar as [$g, $b, $bek]) ok('stok_miktar_coz(' . json_encode($g) . ", $b) = " . json_encode($bek), stok_miktar_coz($g, $b) === $bek, json_encode(stok_miktar_coz($g, $b)));
foreach ([[18450000, 'kg', '18.450'], [1250500, 'lt', '1.250,5'], [-350000, 'kg', '-350'], [0, 'kg', '0'], [12500, 'lt', '12,5'], [1000, 'm', '1'], [999, 'kg', '1'], [1234567, 'lt', '1.234,567']] as [$m, $b, $bek]) {
    ok("stok_miktar_bicim($m, $b) = $bek", stok_miktar_bicim($m, $b) === $bek, stok_miktar_bicim($m, $b));
}
ok('stok_milli_db: 1250500 → "1250.500", -350000 → "-350.000", 5 → "0.005"', stok_milli_db(1250500) === '1250.500' && stok_milli_db(-350000) === '-350.000' && stok_milli_db(5) === '0.005');
ok('stok_milli: "1250.500" → 1250500; null → 0', stok_milli('1250.500') === 1250500 && stok_milli(null) === 0 && stok_milli(12.5) === 12500);
ok('stok_birim_tam_mi: kg/adet/kasa tam; lt/m kesirli', stok_birim_tam_mi('kg') && stok_birim_tam_mi('adet') && stok_birim_tam_mi('kasa') && !stok_birim_tam_mi('lt') && !stok_birim_tam_mi('m'));
ok('stok_birimler: 8 birim', array_keys(stok_birimler()) === ['kg', 'adet', 'kasa', 'palet', 'paket', 'koli', 'lt', 'm']);
ok('stok_kdv_oranlari = [0,1,10,20]', stok_kdv_oranlari() === [0, 1, 10, 20]);

echo "\n=== 4. Fiş no / geri / sabitler ===\n";
ok('stok_fis_no giris → GRS-2026-000123', stok_fis_no('giris', '2026-11-13', 123) === 'GRS-2026-000123');
ok('stok_fis_no cikis/transfer/donusum önekleri', stok_fis_no('cikis', '2026-01-01', 1) === 'CKS-2026-000001' && stok_fis_no('transfer', '2026-01-01', 2) === 'TRF-2026-000002' && stok_fis_no('donusum', '2026-01-01', 3) === 'DNS-2026-000003');
ok('stok_fis_turleri: 4 tür, sayfalar (transfer = transfer_fisleri.php)', array_keys(stok_fis_turleri()) === ['giris', 'cikis', 'transfer', 'donusum'] && stok_fis_turleri()['transfer']['sayfa'] === 'transfer_fisleri.php'
    && stok_fis_turleri()['giris']['sayfa'] === 'stok_giris_fisleri.php' && stok_fis_turleri()['donusum']['sayfa'] === 'stok_donusum_fisleri.php');
foreach (['stok_kartlari.php?id=3' => 'stok_kartlari.php?id=3', 'stok_tanimlari.php?sekme=grup' => 'stok_tanimlari.php?sekme=grup', 'transfer_fisleri.php' => 'transfer_fisleri.php',
    'cari_kartlari.php' => 'stok_kartlari.php', 'https://ornek.test/stok_kartlari.php' => 'stok_kartlari.php', "stok_kartlari.php\r\nX: 1" => 'stok_kartlari.php', 'stok.php' => 'stok_kartlari.php',
    '/stok_kartlari.php' => 'stok_kartlari.php', '../stok_kartlari.php' => 'stok_kartlari.php'] as $g => $bek) {
    ok('stok_geri_guvenli(' . json_encode($g) . ") = $bek", stok_geri_guvenli($g) === $bek);
}
ok('stok_geri_guvenli(dizi) = varsayılan', stok_geri_guvenli(['x']) === 'stok_kartlari.php');

echo "\n=== 5. Kart CRUD ===\n";
ok('stok_kod_oner: boş tabloda STK-0001', stok_kod_oner($db) === 'STK-0001');
$a0 = audit_say('create');
$r = stok_kart_kaydet($db, ['ad' => 'Deneme Ürün', 'tip' => 'urun', 'ana_birim' => 'kg'], 1);
ok('kart_kaydet: kod boş → STK-0001, aktif=1, kdv varsayılan 1', $r['ok'] && ($k1 = stok_kart_oku($db, (int)$r['id'])) && $k1['kod'] === 'STK-0001' && (int)$k1['aktif'] === 1 && (int)$k1['kdv_orani'] === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$id1 = (int)$r['id'];
ok('audit create +1', audit_say('create') === $a0 + 1);
$r = stok_kart_kaydet($db, ['ad' => 'İkinci Kart', 'kod' => 'kiraz-1', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'cevrim_birim' => 'koli', 'cevrim_katsayi' => '24', 'kdv_orani' => '20', 'barkod' => '8690 0000 0001 2', 'kritik_seviye' => '1.000'], 1);
$k2 = $r['ok'] ? stok_kart_oku($db, (int)$r['id']) : null;
ok('kart_kaydet: kod büyük harfe (TR: kiraz → KİRAZ-1), çevrim 24, kdv 20, barkod boşluksuz, kritik 1000', $k2 && $k2['kod'] === 'KİRAZ-1' && $k2['cevrim_birim'] === 'koli'
    && stok_milli($k2['cevrim_katsayi']) === 24000 && (int)$k2['kdv_orani'] === 20 && $k2['barkod'] === '8690000000012' && stok_milli($k2['kritik_seviye']) === 1000000, json_encode($k2, JSON_UNESCAPED_UNICODE));
$id2 = (int)$r['id'];
ok('stok_kod_oner: STK-0002 (yalnız STK-\\d+ sayılır)', stok_kod_oner($db) === 'STK-0002');
$hatali = [
    'kod çakışması (büyük/küçük)' => [['ad' => 'X Kart', 'kod' => 'stk-0001'], 'kod'],
    'kod çakışması TR-duyarsız (KIRAZ-1 == KİRAZ-1)' => [['ad' => 'X Kart', 'kod' => 'KIRAZ-1'], 'kod'],
    'kod geçersiz karakter' => [['ad' => 'X Kart', 'kod' => 'A B'], 'kod'],
    'ad kısa' => [['ad' => 'X'], 'ad'],
    'tip geçersiz' => [['ad' => 'X Kart', 'tip' => 'hizmet'], 'tip'],
    'ana birim geçersiz' => [['ad' => 'X Kart', 'ana_birim' => 'ton'], 'ana_birim'],
    'çevrim = ana birim' => [['ad' => 'X Kart', 'ana_birim' => 'kg', 'cevrim_birim' => 'kg', 'cevrim_katsayi' => '1'], 'cevrim_birim'],
    'katsayı var, çevrim birimi yok' => [['ad' => 'X Kart', 'cevrim_katsayi' => '8'], 'cevrim_birim'],
    'çevrim katsayısı geçersiz' => [['ad' => 'X Kart', 'cevrim_birim' => 'kasa', 'cevrim_katsayi' => '0'], 'cevrim_katsayi'],
    'KDV 18 geçersiz' => [['ad' => 'X Kart', 'kdv_orani' => '18'], 'kdv_orani'],
    'kg kritik seviye kesirli' => [['ad' => 'X Kart', 'ana_birim' => 'kg', 'kritik_seviye' => '12,5'], 'kritik_seviye'],
    'barkod geçersiz' => [['ad' => 'X Kart', 'barkod' => '<script>'], 'barkod'],
    'grup yok' => [['ad' => 'X Kart', 'grup_id' => '999'], 'grup_id'],
];
foreach ($hatali as $ad => [$g, $alan]) {
    $r = stok_kart_kaydet($db, $g, 1);
    ok("kart_kaydet reddi: $ad → alanlar.$alan", !$r['ok'] && isset($r['alanlar'][$alan]), json_encode($r, JSON_UNESCAPED_UNICODE));
}
ok('reddedilen kayıtlar yazılmadı (2 kart)', say('stok_kartlar') === 2);
$r = stok_kart_kaydet($db, ['ad' => 'Sıvı Ürün', 'ana_birim' => 'lt', 'kritik_seviye' => '12,5'], 1);
ok('lt kartta kesirli kritik seviye kabul (12,5 → 12.500)', $r['ok'] && stok_milli(stok_kart_oku($db, (int)$r['id'])['kritik_seviye']) === 12500);
$id3 = (int)$r['id'];
$au = audit_say('update');
$r = stok_kart_kaydet($db, ['ad' => 'Deneme Ürün (güncel)', 'kod' => 'STK-0001', 'tip' => 'urun', 'ana_birim' => 'kg', 'kdv_orani' => '1'], 1, $id1);
ok('güncelle: ad değişti, audit update +1', $r['ok'] && stok_kart_oku($db, $id1)['ad'] === 'Deneme Ürün (güncel)' && audit_say('update') === $au + 1);
$r = stok_kart_kaydet($db, ['ad' => 'Deneme Ürün (güncel)', 'kod' => 'STK-0001', 'tip' => 'urun', 'ana_birim' => 'kg', 'kdv_orani' => '1'], 1, $id1);
ok('değişiklik yoksa UPDATE/audit yok', $r['ok'] && audit_say('update') === $au + 1);
$r = stok_kart_kaydet($db, ['ad' => 'Deneme Ürün (güncel)', 'kod' => 'STK-0001', 'ana_birim' => 'adet'], 1, $id1);
ok('hareketsiz kartta ana birim değişebilir', $r['ok'] && stok_kart_oku($db, $id1)['ana_birim'] === 'adet');
stok_kart_kaydet($db, ['ad' => 'Deneme Ürün (güncel)', 'kod' => 'STK-0001', 'ana_birim' => 'kg'], 1, $id1);
ok('olmayan kart güncellenemez', !stok_kart_kaydet($db, ['ad' => 'Yok'], 1, 9999)['ok']);

$fid = stok_t_fis($db, 'giris', '2026-11-01', 'Merkez Depo', [[$id1, 'Merkez Depo', 1000000, 1400000]], 'Alış');
ok('stok_kart_hareket_say: fiş satırı + defter satırı = 2', stok_kart_hareket_say($db, $id1) === 2);
$r = stok_kart_kaydet($db, ['ad' => 'Deneme Ürün (güncel)', 'kod' => 'STK-0001', 'ana_birim' => 'adet'], 1, $id1);
ok('hareketli kartta ana birim DEĞİŞMEZ (alanlar.ana_birim)', !$r['ok'] && isset($r['alanlar']['ana_birim']) && stok_kart_oku($db, $id1)['ana_birim'] === 'kg');
$r = stok_kart_kaydet($db, ['ad' => 'Deneme Ürün (güncel)', 'kod' => 'STK-0001', 'ana_birim' => 'kg', 'cevrim_birim' => 'kasa', 'cevrim_katsayi' => '8'], 1, $id1);
ok('hareketli kartta çevrim birimi eklenebilir', $r['ok'] && stok_kart_oku($db, $id1)['cevrim_birim'] === 'kasa');

$ad0 = audit_say('deactivate');
$r = stok_kart_durum($db, $id1, false, 1);
ok('pasifleştir: bakiye 1000 kg → uyarı döner, aktif=0, audit deactivate', $r['ok'] && $r['uyari'] !== null && (int)stok_kart_oku($db, $id1)['aktif'] === 0 && audit_say('deactivate') === $ad0 + 1);
$r = stok_kart_durum($db, $id1, true, 1);
ok('aktifleştir: uyarı yok, audit activate', $r['ok'] && $r['uyari'] === null && (int)stok_kart_oku($db, $id1)['aktif'] === 1 && audit_say('activate') >= 1);
$r = stok_kart_durum($db, $id2, false, 1);
ok('hareketsiz kart pasifleştirilirken uyarı yok', $r['ok'] && $r['uyari'] === null);
stok_kart_durum($db, $id2, true, 1);
$r = stok_kart_sil($db, $id1, 1);
ok('hareketli kart silinemez (kod hareketli)', !$r['ok'] && ($r['kod'] ?? '') === 'hareketli' && stok_kart_oku($db, $id1) !== null);
$adl = audit_say('delete');
$r = stok_kart_sil($db, $id3, 1);
ok('hareketsiz kart silinir, audit delete', $r['ok'] && stok_kart_oku($db, $id3) === null && audit_say('delete') === $adl + 1);
ok('olmayan kart silinemez', !stok_kart_sil($db, 9999, 1)['ok']);

echo "\n=== 6. Liste / bakiye / depo filtresi ===\n";
stok_t_fis($db, 'giris', '2026-11-02', 'Şube Depo', [[$id1, 'Şube Depo', 500000, 750000]], 'Alış');
stok_t_fis($db, 'cikis', '2026-11-03', 'Merkez Depo', [[$id1, 'Merkez Depo', -200000, null]], 'Fire');
stok_t_fis($db, 'giris', '2026-11-03', '', [[$id2, '', 30000, 30000]], 'Alış');   // depo atanmamış satır (tüm depolarda görünür)
$B = stok_bakiye($db, $id1);
ok('stok_bakiye: toplam 1300 kg, 2 depo (Merkez 800, Şube 500)', $B['toplam_milli'] === 1300000 && count($B['depolar']) === 2
    && $B['depolar'][0]['depo'] === 'Merkez Depo' && $B['depolar'][0]['milli'] === 800000 && $B['depolar'][1]['milli'] === 500000, json_encode($B, JSON_UNESCAPED_UNICODE));
ok('stok_bakiye: ort = (14.000 + 7.500) / 1500 = 14,3333 ₺/kg (1433,33 kuruş), değer = 1300 × ort', abs($B['ort'] - 1433.3333) < 0.01 && $B['deger_kurus'] === (int)round(1300 * $B['ort']), json_encode($B));
$M = stok_maliyet_durumu($db, $id1);
ok('stok_maliyet_durumu: q=1300 kg, v = 18.633,33 ₺ (çıkış ortalamadan)', $M['q'] === 1300000 && abs($M['v'] - 1863333) <= 1, json_encode($M));
ok('stok_maliyet_durumu: hareketsiz kart sıfır', stok_maliyet_durumu($db, 9999) === ['q' => 0, 'v' => 0, 'ort' => 0.0]);
$L = stok_kart_listesi($db);
$byId = []; foreach ($L['satirlar'] as $s) $byId[(int)$s['id']] = $s;
ok('stok_kart_listesi: 2 kart, bakiye_milli (1300 kg / 30 adet), kesildi=false', count($L['satirlar']) === 2 && $byId[$id1]['bakiye_milli'] === 1300000 && $byId[$id2]['bakiye_milli'] === 30000 && !$L['kesildi']);
ok('kritik: KİRAZ-1 kritik 1000 ≥ bakiye 30 → kritik=true; STK-0001 kritik tanımsız → false', $byId[$id2]['kritik'] === true && $byId[$id1]['kritik'] === false && $byId[$id1]['kritik_milli'] === null);
ok('filtre ara (kod/ad/barkod)', count(stok_kart_listesi($db, ['ara' => 'KİRAZ'])['satirlar']) === 1 && count(stok_kart_listesi($db, ['ara' => '86900000'])['satirlar']) === 1 && count(stok_kart_listesi($db, ['ara' => 'yok-yok'])['satirlar']) === 0);
ok('filtre tip / aktif / kritik', count(stok_kart_listesi($db, ['tip' => 'malzeme'])['satirlar']) === 1 && count(stok_kart_listesi($db, ['aktif' => 0])['satirlar']) === 0
    && array_column(stok_kart_listesi($db, ['kritik' => 1])['satirlar'], 'id') === [$id2]);
$gid = (int)stok_tanim_listesi($db, 'grup')[0]['id'];
stok_kart_kaydet($db, ['ad' => 'İkinci Kart', 'kod' => 'KİRAZ-1', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'grup_id' => (string)$gid, 'kdv_orani' => '20', 'kritik_seviye' => '1.000', 'cevrim_birim' => 'koli', 'cevrim_katsayi' => '24', 'barkod' => '8690000000012'], 1, $id2);
ok('filtre grup_id', array_column(stok_kart_listesi($db, ['grup_id' => $gid])['satirlar'], 'id') === [$id2]);
$DEPOLAR = ['Merkez Depo'];
$B2 = stok_bakiye($db, $id1);
ok('depo filtresi (yalnız Merkez Depo): bakiye 800 kg, 1 depo; ort depo filtresiz aynı', $B2['toplam_milli'] === 800000 && count($B2['depolar']) === 1 && abs($B2['ort'] - $B['ort']) < 0.0001, json_encode($B2, JSON_UNESCAPED_UNICODE));
ok('depo filtresi: depo boş satır tüm depolarda görünür (KİRAZ-1 = 30)', stok_bakiyeler_toplu($db, [$id1, $id2]) === [$id1 => 800000, $id2 => 30000]);
ok('depo filtresi: hareket listesi Şube satırını göstermez', !array_filter(stok_kart_hareketleri($db, $id1), fn($h) => $h['depo'] === 'Şube Depo'));
$DEPOLAR = ['Başka Depo'];
ok('depo filtresi: hiç izinli depo satırı yoksa bakiye 0', stok_bakiye($db, $id1)['toplam_milli'] === 0 && stok_kart_listesi($db)['satirlar'][0]['bakiye_milli'] === 0);
$DEPOLAR = null;
ok('stok_bakiyeler_toplu: boş dizi → []', stok_bakiyeler_toplu($db, []) === []);

echo "\n=== 7. Hareket listesi (yürüyen bakiye) ===\n";
$fIptal = stok_t_fis($db, 'giris', '2026-11-04', 'Merkez Depo', [[$id1, 'Merkez Depo', 100000, 160000]], 'Alış');
stok_t_iptal($db, $fIptal, 'Yanlış giriş');
$H = stok_kart_hareketleri($db, $id1);
ok('5 defter satırı, EN YENİ ÖNCE', count($H) === 5 && $H[0]['id'] > $H[4]['id']);
ok('yürüyen bakiye: en eskiden 1000 → 1500 → 1300 → 1400 → 1300', array_column(array_reverse($H), 'yuruyen_milli') === [1000000, 1500000, 1300000, 1400000, 1300000], json_encode(array_column($H, 'yuruyen_milli')));
ok('iptal fişinin iki satırı iptal=true; ters satır ters=true', $H[0]['iptal'] && $H[0]['ters'] && $H[1]['iptal'] && !$H[1]['ters'] && !$H[2]['iptal']);
ok('fiş no / tür / neden alanları dolu', $H[2]['fis_no'] !== '' && str_starts_with($H[2]['fis_no'], 'CKS-') && $H[2]['fis_tur'] === 'cikis' && $H[2]['neden_ad'] === 'Fire' && $H[2]['miktar_milli'] === -200000);
$H2 = stok_kart_hareketleri($db, $id1, [], 2);
ok('limit=2: pencere öncesi toplam eklenir (en yeni yürüyen = 1300)', count($H2) === 2 && $H2[0]['yuruyen_milli'] === 1300000 && $H2[1]['yuruyen_milli'] === 1400000);
ok('depo süzgeci (f.depo=Şube Depo): 1 satır', count(stok_kart_hareketleri($db, $id1, ['depo' => 'Şube Depo'])) === 1);
ok('iptal sonrası maliyet ortalaması iptal öncesine döner', abs(stok_maliyet_durumu($db, $id1)['ort'] - $B['ort']) < 0.01, json_encode(stok_maliyet_durumu($db, $id1)));
ok('stok_kart_secenekleri: yalnız aktif kartlar, birim/çevrim/kdv', ($sec = stok_kart_secenekleri($db)) && count($sec) === 2 && $sec[0]['birim'] !== '' && array_key_exists('cevrim_katsayi', $sec[0]));

echo "\n=== 8. Maliyet motoru (saf) ===\n";
$bos = ['q' => 0, 'v' => 0, 'ort' => 0.0];
$r1 = stok_maliyet_uygula($bos, 100000, 100000);
ok('boş → giriş 100 kg / 1.000 ₺: q=100, v=1.000 ₺, ort=10 ₺/kg', $r1['d'] === ['q' => 100000, 'v' => 100000, 'ort' => 1000.0] && $r1['deger'] === 100000, json_encode($r1));
$r2 = stok_maliyet_uygula($r1['d'], 100000, 120000);
ok('giriş 100 kg / 1.200 ₺: v=2.200, ort=11', $r2['d'] === ['q' => 200000, 'v' => 220000, 'ort' => 1100.0], json_encode($r2));
$r3 = stok_maliyet_uygula($r2['d'], -50000, null);
ok('çıkış 50 kg (ortalamadan): deger=-550 ₺, v=1.650, ort DEĞİŞMEZ', $r3['deger'] === -55000 && $r3['d'] === ['q' => 150000, 'v' => 165000, 'ort' => 1100.0], json_encode($r3));
$r4 = stok_maliyet_uygula($r3['d'], -200000, null);
ok('eksiye çıkış 200 kg: Q=-50, V = Q·ort = -550 ₺, ort 11', $r4['deger'] === -220000 && $r4['d'] === ['q' => -50000, 'v' => -55000, 'ort' => 1100.0], json_encode($r4));
$r5 = stok_maliyet_uygula($r4['d'], 100000, 100000);
ok('Q≤0 iken giriş 100 kg / 1.000 ₺: ort = 10 (yeni fiyat), Q=50, V=500', $r5['d'] === ['q' => 50000, 'v' => 50000, 'ort' => 1000.0], json_encode($r5));
$r6 = stok_maliyet_uygula($r2['d'], -100000, -120000);
ok('ters satır (girişin tersi: -100 kg, -1.200 ₺) önceki duruma döner', $r6['d'] === $r1['d'] && $r6['deger'] === -120000, json_encode($r6));
$r7 = stok_maliyet_uygula($r3['d'], 50000, 55000);
ok('ters satır (çıkışın tersi: +50 kg, +550 ₺) önceki duruma döner', $r7['d'] === $r2['d'], json_encode($r7));
$r8 = stok_maliyet_uygula($r2['d'], -200000, null);
ok('tamamı çıkış: Q=0 → V=0', $r8['d']['q'] === 0 && $r8['d']['v'] === 0 && $r8['d']['ort'] === 1100.0);
ok('q=0: durum değişmez, deger 0', stok_maliyet_uygula($r2['d'], 0, null) === ['d' => $r2['d'], 'deger' => 0]);
$r9 = stok_maliyet_uygula($r2['d'], 10000, null);
ok('fiyatsız giriş (deger=null): deger = q·ort = 110 ₺, ort değişmez', $r9['deger'] === 11000 && $r9['d'] === ['q' => 210000, 'v' => 231000, 'ort' => 1100.0]);
$r10 = stok_maliyet_uygula(['q' => 10000, 'v' => 1000, 'ort' => 10.0], -5000, -5000);
ok('V\'<0 ve Q\'>0 → V\'=0, ort ≥ 0', $r10['d']['v'] === 0 && $r10['d']['ort'] >= 0.0 && $r10['d']['q'] === 5000);
$r11 = stok_maliyet_uygula($bos, 3, 1);
ok('kesirli küçük miktar (0,003 birim / 0,01 ₺): ort = 333,3333 kuruş', abs($r11['d']['ort'] - 333.3333) < 0.001);

echo "\n=== 9. Tanımlar ===\n";
$gir = stok_tanim_listesi($db, 'giris_neden');
ok('giriş nedenleri sıralı: Alış, Açılış/Devir, Sayım Fazlası, Diğer Giriş', array_column($gir, 'ad') === ['Alış', 'Açılış/Devir', 'Sayım Fazlası', 'Diğer Giriş']);
ok('geçersiz tür → []', stok_tanim_listesi($db, 'odeme') === []);
$r = stok_tanim_kaydet($db, 'grup', 'Ambalaj', 20, 1);
ok('tanım ekle (grup Ambalaj)', $r['ok'] && say('stok_tanimlar', "tur='grup'") === 2);
$tamb = (int)$r['id'];
ok('aynı ad TR-duyarsız reddedilir (AMBALAJ)', !stok_tanim_kaydet($db, 'grup', 'AMBALAJ', 30, 1)['ok']);
ok('farklı türde aynı ad serbest', stok_tanim_kaydet($db, 'giris_neden', 'Ambalaj', 90, 1)['ok']);
ok('ad boş / sıra eksi reddedilir', !stok_tanim_kaydet($db, 'grup', '  ', 1, 1)['ok'] && !stok_tanim_kaydet($db, 'grup', 'Yeni', -1, 1)['ok']);
ok('geçersiz tür reddedilir', !stok_tanim_kaydet($db, 'odeme', 'X', 1, 1)['ok']);
$alis = (int)$gir[0]['id'];
$r = stok_tanim_kaydet($db, 'giris_neden', 'Satın Alma', 10, 1, $alis);
ok('sistem nedeninin adı değişmez', !$r['ok'] && isset($r['alanlar']['ad']));
$r = stok_tanim_kaydet($db, 'giris_neden', 'Alış', 15, 1, $alis);
ok('sistem nedeninin sırası değişir', $r['ok'] && (int)stok_tanim_bul($db, $alis)['sira'] === 15);
$r = stok_tanim_sil($db, $alis, 1);
ok('sistem nedeni silinmez (kod kullanimda)', !$r['ok'] && ($r['kod'] ?? '') === 'kullanimda');
$r = stok_tanim_durum($db, $alis, false, 1);
ok('sistem nedeni pasifleşir', $r['ok'] && (int)stok_tanim_bul($db, $alis)['aktif'] === 0 && count(stok_tanim_listesi($db, 'giris_neden', true)) === 4);
stok_tanim_durum($db, $alis, true, 1);
ok('kullanımdaki grup silinmez (KİRAZ-1 Genel grubunda)', !stok_tanim_sil($db, $gid, 1)['ok'] && (stok_tanim_sil($db, $gid, 1)['kod'] ?? '') === 'kullanimda');
ok('grup kullanım sayısı listede (Genel: 1 kart)', (int)stok_tanim_listesi($db, 'grup')[0]['kullanim'] === 1);
$ad0 = audit_say('delete');
ok('kullanılmayan tanım silinir + audit', stok_tanim_sil($db, $tamb, 1)['ok'] && say('stok_tanimlar', 'id = ?', [$tamb]) === 0 && audit_say('delete') === $ad0 + 1);
ok('olmayan tanım: sil/durum reddedilir', !stok_tanim_sil($db, 9999, 1)['ok'] && !stok_tanim_durum($db, 9999, true, 1)['ok']);
$tu = stok_tanim_kaydet($db, 'cikis_neden', 'Bağış', 70, 1);
ok('kullanıcı nedeni yeniden adlandırılır', $tu['ok'] && stok_tanim_kaydet($db, 'cikis_neden', 'Bağış/Hibe', 70, 1, (int)$tu['id'])['ok'] && stok_tanim_bul($db, (int)$tu['id'])['ad'] === 'Bağış/Hibe');
$db->prepare("UPDATE stok_fisler SET neden_id = ? WHERE id = ?")->execute([(int)$tu['id'], $fid]);   // YALNIZ TEST: nedeni kullanımda yap
ok('fişte kullanılan neden silinmez', !stok_tanim_sil($db, (int)$tu['id'], 1)['ok']);

echo "\n=== 10. İçe aktar ===\n";
foreach ([['Kayısı', 'urun', 1], ['Deneme Ürün (güncel)', 'urun', 1], ['Köşebent 120 cm', 'kosebent', 1], ['Şerit 15 mm', 'serit', 1], ['Firma Örnek', 'firma', 1], ['Pasif Ürün', 'urun', 0], ['kayısı', 'urun', 1]] as [$n, $t, $a]) {
    $db->prepare('INSERT INTO material_definitions (type, name, is_active) VALUES (?, ?, ?)')->execute([$t, $n, $a]);
}
$on = stok_ice_aktar_onizle($db);
$adlar = array_column($on, 'durum', 'ad');
ok('önizleme: firma ve pasif hariç; aynı ad (TR-duyarsız) tek; mevcut kart adı "var"', count($on) === 4 && ($adlar['Kayısı'] ?? '') === 'yeni' && ($adlar['Deneme Ürün (güncel)'] ?? '') === 'var'
    && !isset($adlar['Firma Örnek']) && !isset($adlar['Pasif Ürün']), json_encode($on, JSON_UNESCAPED_UNICODE));
$kos = array_values(array_filter($on, fn($o) => $o['ad'] === 'Köşebent 120 cm'))[0] ?? [];
ok('malzeme türü → tip malzeme, adet; ürün → urun, kg', ($kos['hedef_tip'] ?? '') === 'malzeme' && ($kos['ana_birim'] ?? '') === 'adet' && $on[0]['hedef_tip'] === 'urun' && $on[0]['ana_birim'] === 'kg');
$k0 = say('stok_kartlar');
$r = stok_ice_aktar($db, 1);
ok('içe aktar: 3 eklendi, 1 atlandı', $r['ok'] && $r['eklenen'] === 3 && $r['atlanan'] === 1 && say('stok_kartlar') === $k0 + 3, json_encode($r));
ok('eklenen kartlar md_id bağlı, STK- kodlu, kdv 1, aktif', say('stok_kartlar', "md_id IS NOT NULL AND kod LIKE 'STK-%' AND aktif = 1 AND kdv_orani = 1") === 3);
$r = stok_ice_aktar($db, 1);
ok('ikinci kez: 0 eklendi (idempotent)', $r['ok'] && $r['eklenen'] === 0 && say('stok_kartlar') === $k0 + 3);
$db->exec("UPDATE material_definitions SET name = 'Kayısı (Yeni Ad)' WHERE name = 'Kayısı'");
ok('kaynakta ad değişse de md_id bağı çift kart üretmez', stok_ice_aktar($db, 1)['eklenen'] === 0 && say('stok_kartlar', "ad = 'Kayısı'") === 1);

echo "\n=== 11. Audit ===\n";
$son = $db->query("SELECT * FROM audit_log WHERE module = 'stok' ORDER BY id DESC LIMIT 1")->fetch();
ok('audit satırları module=stok; içe aktar özeti', $son && $son['action'] === 'create' && str_contains((string)$son['new_values'], 'ice_aktar'));
ok('audit eylemleri yalnız create|update|activate|deactivate|delete', !$db->query("SELECT COUNT(*) FROM audit_log WHERE module = 'stok' AND action NOT IN ('create','update','activate','deactivate','delete')")->fetchColumn());

// ═════════════════════════ PARÇA B — FİŞLER ═════════════════════════
$bugun = date('Y-m-d');
$nd = function (string $tur, string $ad) use ($db): int { foreach (stok_tanim_listesi($db, $tur) as $t) if ($t['ad'] === $ad) return (int)$t['id']; return 0; };
$ALIS = $nd('giris_neden', 'Alış'); $SATIS = $nd('cikis_neden', 'Satış/Sevk');
$fis = fn(string $tur, string $depo, array $sat, array $ek = []) => stok_fis_kaydet($db, stok_t_fis_istek($tur, $depo, $sat, $ek + ['tarih' => $bugun]), 1);
$bak = fn(int $k, ?string $depo = null): int => $depo === null ? stok_milli($db->query("SELECT COALESCE(SUM(miktar),0) FROM stok_hareketler WHERE kart_id = $k")->fetchColumn())
    : (function () use ($db, $k, $depo) { $st = $db->prepare('SELECT COALESCE(SUM(miktar),0) FROM stok_hareketler WHERE kart_id = ? AND depo = ?'); $st->execute([$k, $depo]); return stok_milli($st->fetchColumn()); })();
$A = (int)stok_kart_kaydet($db, ['ad' => 'Fiş Test Ürün A', 'ana_birim' => 'kg', 'kdv_orani' => '1'], 1)['id'];
$Bk = (int)stok_kart_kaydet($db, ['ad' => 'Fiş Test Ürün B', 'ana_birim' => 'kg', 'cevrim_birim' => 'kasa', 'cevrim_katsayi' => '8'], 1)['id'];
$Ck = (int)stok_kart_kaydet($db, ['ad' => 'Fiş Test Ürün C', 'ana_birim' => 'kg'], 1)['id'];
$Pas = (int)stok_kart_kaydet($db, ['ad' => 'Fiş Test Pasif', 'ana_birim' => 'kg'], 1)['id'];
stok_kart_durum($db, $Pas, false, 1);

echo "\n=== 12. Fiş doğrulama ===\n";
$hata = fn(array $g): array => stok_fis_dogrula($db, $g + ['tarih' => $bugun]);
$d = $hata(['tur' => 'yok']);
ok('geçersiz tür → alanlar.tur', !$d['ok'] && isset($d['alanlar']['tur']));
$d = $hata(stok_t_fis_istek('giris', 'Olmayan Depo', [[$A, '10']], ['neden_id' => (string)$ALIS]));
ok('depo seçeneklerde değil → alanlar.depo', !$d['ok'] && isset($d['alanlar']['depo']));
$d = $hata(stok_t_fis_istek('giris', 'Merkez Depo', [[$A, '10']]));
ok('giriş: neden zorunlu', !$d['ok'] && isset($d['alanlar']['neden_id']));
$d = $hata(stok_t_fis_istek('cikis', 'Merkez Depo', [[$A, '10']], ['neden_id' => (string)$ALIS]));
ok('çıkışta giriş nedeni reddedilir', !$d['ok'] && isset($d['alanlar']['neden_id']));
$d = $hata(['tur' => 'giris', 'depo' => 'Merkez Depo', 'neden_id' => (string)$ALIS]);
ok('satırsız fiş reddedilir', !$d['ok'] && isset($d['alanlar']['satir']));
$d = $hata(stok_t_fis_istek('giris', 'Merkez Depo', [[$A, '12,5'], [$Pas, '5'], [9999, '1'], [$A, '0']], ['neden_id' => (string)$ALIS]));
ok('satır hataları: kg kesirli (0) · pasif kart (1) · olmayan kart (2) · sıfır miktar (3)', !$d['ok'] && isset($d['satir_hatalari'][0]['miktar'], $d['satir_hatalari'][1]['kart_id'], $d['satir_hatalari'][2]['kart_id'], $d['satir_hatalari'][3]['miktar']), json_encode($d, JSON_UNESCAPED_UNICODE));
$d = $hata(stok_t_fis_istek('transfer', 'Merkez Depo', [[$A, '10']], ['hedef_depo' => 'merkez depo']));
ok('transfer: hedef = kaynak (TR-duyarsız) reddedilir', !$d['ok'] && isset($d['alanlar']['hedef_depo']));
$d = $hata(stok_t_fis_istek('transfer', 'Merkez Depo', [[$A, '10', '5,00']], ['hedef_depo' => 'Şube Depo']));
ok('transfer: fiyat yasak', !$d['ok'] && isset($d['satir_hatalari'][0]['birim_fiyat']));
$d = $hata(stok_t_fis_istek('donusum', 'Merkez Depo', [[$A, '10', '', 'C']]));
ok('dönüşüm: yalnız girdi → en az bir girdi + bir çıktı', !$d['ok'] && isset($d['alanlar']['satir']));
$d = $hata(stok_t_fis_istek('giris', 'Merkez Depo', [[$A, '10', '5']], ['neden_id' => (string)$ALIS, 'cari_id' => '1']));
ok('cari tabloları yokken cari seçimi reddedilir', !$d['ok'] && isset($d['alanlar']['cari_id']));
$d = $hata(stok_t_fis_istek('giris', 'Merkez Depo', [[$A, '10', '5']], ['neden_id' => (string)$ALIS, 'para_birimi' => 'USD']));
ok('USD fiyatlı girişte kur zorunlu', !$d['ok'] && isset($d['alanlar']['kur']));
$d = $hata(stok_t_fis_istek('giris', 'Merkez Depo', [[$Bk, '3', '', '', 'kasa']], ['neden_id' => (string)$ALIS]));
ok('çevrim birimi: 3 kasa × 8 = 24 kg ana birime çevrilir', $d['ok'] && $d['veri']['satirlar'][0]['milli'] === 24000, json_encode($d, JSON_UNESCAPED_UNICODE));
$d = $hata(stok_t_fis_istek('giris', 'Merkez Depo', [[$Bk, '3', '', '', 'koli']], ['neden_id' => (string)$ALIS]));
ok('kartta olmayan birim reddedilir', !$d['ok'] && isset($d['satir_hatalari'][0]['birim']));
$g = stok_t_fis_istek('giris', 'Merkez Depo', [[$A, '10']], ['neden_id' => (string)$ALIS]); $g['kart_id'] = ['']; $g['kart_ara'] = [stok_kart_oku($db, $A)['kod'] . ' — x'];
ok('kart metinle çözülür ("KOD — Ad"; JS kapalı form)', ($d = $hata($g))['ok'] && $d['veri']['satirlar'][0]['kart_id'] === $A);
$g['kart_id'] = ['', '', '']; $g['kart_ara'] = [stok_kart_oku($db, $A)['kod'], '', '']; $g['miktar'] = ['10', '', ''];
ok('tamamen boş satırlar atılır', ($d = $hata($g))['ok'] && count($d['veri']['satirlar']) === 1);

echo "\n=== 13. Giriş / çıkış / idempotent ===\n";
$f0 = say('stok_fisler'); $h0 = say('stok_hareketler'); $ac0 = audit_say('create');
$r = $fis('giris', 'Merkez Depo', [[$A, '100', '10,00']], ['neden_id' => (string)$ALIS, 'istek_anahtari' => 'grs-1', 'evrak_no' => 'IRS-1']);
$F1 = (int)($r['id'] ?? 0); $fr = $F1 ? stok_fis_ham($db, $F1) : [];
ok('giriş: ok, fiş no GRS-YYYY-<id>, 1 defter satırı, audit create', $r['ok'] && $r['fis_no'] === stok_fis_no('giris', $bugun, $F1) && say('stok_hareketler') === $h0 + 1 && audit_say('create') === $ac0 + 1, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('giriş: toplam 1.000,00 ₺ + KDV %1 (kart) 10,00; neden adı saklanır', cari_kurus($fr['toplam_tutar']) === 100000 && cari_kurus($fr['toplam_kdv']) === 1000 && $fr['neden_ad'] === 'Alış' && $fr['durum'] === 'gecerli');
ok('giriş: maliyet ort = 10 ₺/kg, bakiye 100 kg (Merkez)', abs(stok_maliyet_durumu($db, $A)['ort'] - 1000.0) < 0.001 && $bak($A, 'Merkez Depo') === 100000);
$r2 = $fis('giris', 'Merkez Depo', [[$A, '100', '10,00']], ['neden_id' => (string)$ALIS, 'istek_anahtari' => 'grs-1']);
ok('aynı istek_anahtari → tekrar=true, aynı fiş, yeni satır yok', $r2['ok'] && !empty($r2['tekrar']) && (int)$r2['id'] === $F1 && say('stok_fisler') === $f0 + 1);
$r = $fis('cikis', 'Merkez Depo', [[$A, '30']], ['neden_id' => (string)$SATIS]);
$F2 = (int)$r['id'];
ok('çıkış 30 kg: deger −300 ₺ (ortalamadan), ort DEĞİŞMEZ, bakiye 70', $r['ok'] && cari_kurus($db->query("SELECT deger FROM stok_hareketler WHERE fis_id = $F2")->fetchColumn()) === -30000
    && abs(stok_maliyet_durumu($db, $A)['ort'] - 1000.0) < 0.001 && $bak($A) === 70000 && str_starts_with($r['fis_no'], 'CKS-'));
ok('çıkış toplamı: fiyatsız → toplam_tutar NULL', stok_fis_ham($db, $F2)['toplam_tutar'] === null);

echo "\n=== 14. Yetersiz stok / eksiye izin ===\n";
$f0 = say('stok_fisler'); $h0 = say('stok_hareketler');
$r = $fis('cikis', 'Merkez Depo', [[$A, '200']], ['neden_id' => (string)$SATIS, 'istek_anahtari' => 'cks-eksi']);
$e = $r['eksikler'][0] ?? [];
ok('çıkış 200 > 70: kod yetersiz_stok, eksik listesi (mevcut 70, istenen 200, eksik 130), HİÇBİR ŞEY yazılmadı', !$r['ok'] && ($r['kod'] ?? '') === 'yetersiz_stok' && ($e['mevcut_milli'] ?? 0) === 70000
    && ($e['istenen_milli'] ?? 0) === 200000 && ($e['eksik_milli'] ?? 0) === 130000 && $e['depo'] === 'Merkez Depo' && say('stok_fisler') === $f0 && say('stok_hareketler') === $h0, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $fis('cikis', 'Şube Depo', [[$A, '1']], ['neden_id' => (string)$SATIS]);
ok('depo bazında: Şube\'de A yok → yetersiz (Merkez bakiyesi saymaz)', !$r['ok'] && ($r['kod'] ?? '') === 'yetersiz_stok');
$r = $fis('cikis', 'Merkez Depo', [[$A, '200']], ['neden_id' => (string)$SATIS, 'istek_anahtari' => 'cks-eksi', 'eksiye_izin' => '1']);
$FE = (int)$r['id'];
ok('eksiye_izin=1 (aynı istek anahtarı): kaydedildi + uyarı "Eksiye düşürüldü", bakiye −130', $r['ok'] && str_contains((string)$r['uyari'], 'Eksiye düşürüldü') && str_contains((string)stok_fis_ham($db, $FE)['uyari'], 'Eksiye') && $bak($A) === -130000);

echo "\n=== 15. İptal ===\n";
$h0 = say('stok_hareketler'); $c0 = audit_say('cancel');
ok('iptal nedeni < 3 reddedilir', !stok_fis_iptal($db, $FE, 'ab', 1)['ok'] && stok_fis_ham($db, $FE)['durum'] === 'gecerli');
$r = stok_fis_iptal($db, $FE, 'Yanlış miktar', 1);
ok('iptal: durum iptal + neden/kim; 1 ters satır (ters_id); bakiye 70\'e döner; audit cancel', $r['ok'] && ($x = stok_fis_ham($db, $FE))['durum'] === 'iptal' && $x['iptal_neden'] === 'Yanlış miktar' && (int)$x['iptal_by'] === 1
    && say('stok_hareketler') === $h0 + 1 && say('stok_hareketler', 'fis_id = ? AND ters_id IS NOT NULL', [$FE]) === 1 && $bak($A) === 70000 && audit_say('cancel') === $c0 + 1, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('iptal sonrası ortalama 10 ₺/kg (ters satır değeriyle)', abs(stok_maliyet_durumu($db, $A)['ort'] - 1000.0) < 0.01, json_encode(stok_maliyet_durumu($db, $A)));
$r = stok_fis_iptal($db, $FE, 'Tekrar iptal', 1);
ok('ikinci iptal → kod zaten_iptal; yeni ters satır yok', !$r['ok'] && ($r['kod'] ?? '') === 'zaten_iptal' && say('stok_hareketler') === $h0 + 1);
ok('olmayan fiş iptal edilemez', !stok_fis_iptal($db, 99999, 'Neden yok', 1)['ok']);
$r = $fis('giris', 'Merkez Depo', [[$Ck, '10', '4']], ['neden_id' => (string)$ALIS]);
$FC = (int)$r['id'];
$fis('cikis', 'Merkez Depo', [[$Ck, '8']], ['neden_id' => (string)$SATIS]);
$r = stok_fis_iptal($db, $FC, 'Giriş hatalı', 1);
ok('tüketilmiş girişin iptali: izin verilir, uyarı "İptal sonrası eksi bakiye" (C = −8)', $r['ok'] && str_contains((string)$r['uyari'], 'İptal sonrası eksi bakiye') && $bak($Ck) === -8000, json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n=== 16. Transfer / dönüşüm ===\n";
$ort0 = stok_maliyet_durumu($db, $A)['ort'];
$r = $fis('transfer', 'Merkez Depo', [[$A, '50']], ['hedef_depo' => 'Şube Depo']);
$FT = (int)$r['id'];
$th = $db->query("SELECT depo, miktar, deger, satir_id FROM stok_hareketler WHERE fis_id = $FT ORDER BY id")->fetchAll();
ok('transfer: 2 defter satırı (Merkez −50, Şube +50), değerler eşit/ters, aynı satır', $r['ok'] && count($th) === 2 && $th[0]['depo'] === 'Merkez Depo' && stok_milli($th[0]['miktar']) === -50000 && $th[1]['depo'] === 'Şube Depo'
    && stok_milli($th[1]['miktar']) === 50000 && cari_kurus($th[0]['deger']) === -cari_kurus($th[1]['deger']) && $th[0]['satir_id'] === $th[1]['satir_id'], json_encode($th));
ok('transfer ürün ortalamasını DEĞİŞTİRMEZ; toplam bakiye aynı; depo bakiyeleri 20 / 50', abs(stok_maliyet_durumu($db, $A)['ort'] - $ort0) < 0.001 && $bak($A) === 70000 && $bak($A, 'Merkez Depo') === 20000 && $bak($A, 'Şube Depo') === 50000);
ok('transfer fiş no TRF-, toplam_miktar 50, hedef_depo saklanır', str_starts_with($r['fis_no'], 'TRF-') && stok_milli(stok_fis_ham($db, $FT)['toplam_miktar']) === 50000 && stok_fis_ham($db, $FT)['hedef_depo'] === 'Şube Depo');
$DEPOLAR = ['Şube Depo'];
$LT = stok_fis_listesi($db, 'transfer', ['bas' => '']); $LC = stok_fis_listesi($db, 'cikis', ['bas' => '']);
ok('depo filtresi: aktif Şube → transfer (hedef) listede; Merkez çıkışları görünmez', count($LT['satirlar']) === 1 && $LC['toplam_satir'] === 0);
ok('stok_fis_gorunur: hedef depoda görünür', stok_fis_gorunur(stok_fis_ham($db, $FT)) && !stok_fis_gorunur(stok_fis_ham($db, $F1)));
$DEPOLAR = null;
$r = $fis('donusum', 'Merkez Depo', [[$A, '20', '', 'C'], [$Bk, '15', '', 'G'], [$Ck, '3', '', 'G']]);
$FD = (int)$r['id'];
$dh = []; foreach ($db->query("SELECT kart_id, miktar, deger FROM stok_hareketler WHERE fis_id = $FD ORDER BY id")->fetchAll() as $x) $dh[(int)$x['kart_id']] = cari_kurus($x['deger']);
ok('dönüşüm: girdi A −20 kg (−200 ₺); çıktılar B/C miktar oranıyla (166,67 + 33,33 = 200 ₺, fark son satıra)', $r['ok'] && $dh[$A] === -20000 && $dh[$Bk] === 16667 && $dh[$Ck] === 3333 && str_starts_with($r['fis_no'], 'DNS-'), json_encode($dh));
ok('dönüşüm: B ortalaması 200×15/18 ÷ 15 ≈ 11,11 ₺/kg; A bakiye 50', abs(stok_maliyet_durumu($db, $Bk)['ort'] - 1111.1333) < 0.01 && $bak($A) === 50000, json_encode(stok_maliyet_durumu($db, $Bk)));
$r = $fis('donusum', 'Merkez Depo', [[$A, '500', '', 'C'], [$Bk, '1', '', 'G']]);
ok('dönüşüm girdisi yetersiz → yetersiz_stok', !$r['ok'] && ($r['kod'] ?? '') === 'yetersiz_stok');
$O = stok_fis_oku($db, $FD);
ok('stok_fis_oku: 3 satır (kod/ad/birim), satır maliyetleri, 3 defter satırı', count($O['satirlar']) === 3 && $O['satirlar'][0]['kod'] !== '' && $O['satirlar'][0]['maliyet_kurus'] === 20000 && count($O['hareketler']) === 3);
$K = stok_fis_kopya(stok_fis_oku($db, $F1));
ok('stok_fis_kopya: tarih bugün, evrak boş, miktar "100", fiyat "10,00", neden aynı', $K['tarih'] === $bugun && $K['evrak_no'] === '' && $K['satirlar'][0]['miktar'] === '100' && $K['satirlar'][0]['birim_fiyat'] === '10,00' && $K['neden_id'] === $ALIS);
$LG = stok_fis_listesi($db, 'giris', ['bas' => '', 'durum' => 'iptal']);
ok('liste: durum=iptal süzgeci (Ck girişi), ara=evrak no, ara=ürün adı', $LG['toplam_satir'] >= 1 && !array_filter($LG['satirlar'], fn($x) => $x['durum'] !== 'iptal')
    && stok_fis_listesi($db, 'giris', ['bas' => '', 'ara' => 'IRS-1'])['toplam_satir'] === 1 && stok_fis_listesi($db, 'giris', ['bas' => '', 'ara' => 'Ürün C'])['toplam_satir'] === 1);
ok('liste: bas anahtarı verilmezse son 30 gün (eski tarihli fiş hariç)', !in_array('2026-11-01', array_column(stok_fis_listesi($db, 'giris', [])['satirlar'], 'tarih'), true) || date('Y-m-d', strtotime('-30 days')) <= '2026-11-01');

echo "\n=== 17. Kantar (salt okunur ön-doldurma) ===\n";
ok('kantar tablosu yokken adaylar = []', stok_kantar_adaylari($db) === []);
stok_t_kantar_kur($db);
$KN = stok_t_kantar($db, '501', date('d.m.Y') . ' 08:30', 'Bilinmeyen Firma', 'Fiş Test Ürün A', 'Merkez Depo', 6480.0, 60, 2.0, 2, 120.0);
$KS = stok_t_kantar($db, '502', $bugun . ' 09:00', 'X', 'Y', 'Şube Depo', 100.0);
$ad = stok_kantar_adaylari($db);
ok('adaylar: 2 kantar fişi; net = 6480 − 60×2 − 2×120 = 6.120 kg; tarih d.m.Y → Y-m-d; kart adı eşleşti', count($ad) === 2 && $ad[1]['miktar_milli'] === 6120000 && $ad[1]['tarih'] === $bugun && $ad[1]['kart_id'] === $A && $ad[1]['aktarildi'] === null, json_encode($ad, JSON_UNESCAPED_UNICODE));
$DEPOLAR = ['Merkez Depo'];
ok('depo filtresi: Şube kantarı görünmez; öneri null', count(stok_kantar_adaylari($db)) === 1 && stok_kantar_oneri($db, $KS) === null);
$DEPOLAR = null;
$kanG = fn(array $ek = []) => $fis('giris', 'Merkez Depo', [[$A, '6.120', '9']], ['neden_id' => (string)$ALIS, 'kaynak_modul' => 'kantar', 'kaynak_id' => (string)$KN] + $ek);
$r = $kanG();
$FK = (int)$r['id'];
ok('kantardan giriş: kaynak_modul=kantar, kaynak_id saklanır', $r['ok'] && ($x = stok_fis_ham($db, $FK))['kaynak_modul'] === 'kantar' && (int)$x['kaynak_id'] === $KN);
$r = $kanG();
ok('aynı kantar fişine ikinci giriş → kod kantar_aktarilmis', !$r['ok'] && ($r['kod'] ?? '') === 'kantar_aktarilmis', json_encode($r, JSON_UNESCAPED_UNICODE));
ok('adaylarda "aktarildi" işaretli (fiş no)', (stok_kantar_adaylari($db)[1]['aktarildi']['fis_no'] ?? '') === stok_fis_ham($db, $FK)['fis_no']);
ok('kaynak yalnız giriş + kantar: çıkışta kaynak reddedilir', !$fis('cikis', 'Merkez Depo', [[$A, '1']], ['neden_id' => (string)$SATIS, 'kaynak_modul' => 'kantar', 'kaynak_id' => (string)$KN])['ok']);
stok_fis_iptal($db, $FK, 'Yanlış kart', 1);
ok('iptal edilen kantar fişi yeniden aktarılabilir', $kanG()['ok']);
ok('kantar_fisleri DEĞİŞMEDİ (2 satır, net aynı)', say('kantar_fisleri') === 2 && (float)$db->query("SELECT net_kg FROM kantar_fisleri WHERE id = $KN")->fetchColumn() === 6480.0);

echo "\n=== 18. Cari bağı ===\n";
stok_t_cari_kur($db);
$cr = cari_kart_kaydet($db, ['unvan' => 'Örnek Tedarik Ltd.', 'tip' => 'tedarikci', 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
$CR = (int)$cr['id'];
$r = $fis('giris', 'Merkez Depo', [[$A, '100']], ['neden_id' => (string)$ALIS, 'cari_id' => (string)$CR]);
ok('cari seçiliyken birim fiyat zorunlu', !$r['ok'] && isset($r['satir_hatalari'][0]['birim_fiyat']));
$ch0 = say('cari_hareketler');
$r = $fis('giris', 'Merkez Depo', [[$A, '100', '2,50', '', '', '1'], [$Bk, '2', '1,00', '', 'kasa', '10']], ['neden_id' => (string)$ALIS, 'cari_id' => (string)$CR, 'evrak_no' => 'FTR-9']);
$FCR = (int)$r['id']; $fh = stok_fis_ham($db, $FCR);
$ch = cari_hareket_oku($db, (int)$fh['cari_hareket_id']);
ok('cari alış: alacak_dekont (yön A), tutar = (250 + 16) + KDV (2,50 + 1,60) = 270,10 ₺, kaynak stok/fiş id, evrak no', $r['ok'] && $ch && $ch['tur'] === 'alacak_dekont' && $ch['yon'] === 'A'
    && cari_kurus($ch['tutar']) === 27010 && $ch['kaynak_modul'] === 'stok' && (int)$ch['kaynak_id'] === $FCR && $ch['evrak_no'] === 'FTR-9' && str_contains($ch['aciklama'], $fh['fis_no']) && say('cari_hareketler') === $ch0 + 1, json_encode([$r, $ch], JSON_UNESCAPED_UNICODE));
ok('cari bakiyesi −270,10 (tedarikçi alacaklı)', cari_bakiye($db, $CR)['TRY']['bakiye'] === -27010);
$r = $fis('cikis', 'Merkez Depo', [[$A, '10', '20']], ['neden_id' => (string)$SATIS, 'cari_id' => (string)$CR]);
$chc = cari_hareket_oku($db, (int)stok_fis_ham($db, (int)$r['id'])['cari_hareket_id']);
ok('cari satış: borc_dekont (yön B) 200 + KDV %1 = 202,00; defter değeri ORTALAMADAN (satış fiyatı değil)', $r['ok'] && $chc['tur'] === 'borc_dekont' && $chc['yon'] === 'B' && cari_kurus($chc['tutar']) === 20200
    && cari_kurus($db->query('SELECT deger FROM stok_hareketler WHERE fis_id = ' . (int)$r['id'])->fetchColumn()) !== -20000);
$r = cari_hareket_iptal($db, (int)$ch['id'], 'Cariden iptal', 1);
ok('cari_hareket_iptal kaynaklı satırı reddeder (kaynakli; "stok fişinden" ipucu)', !$r['ok'] && ($r['kod'] ?? '') === 'kaynakli' && str_contains($r['hata'], 'stok fişinden'));
$r = stok_fis_iptal($db, $FCR, 'Fatura hatalı', 1);
ok('stok fişi iptali bağlı cari hareketini iptal eder (cari_kaynakli_iptal), bakiye geri döner', $r['ok'] && cari_hareket_oku($db, (int)$ch['id'])['durum'] === 'iptal' && $r['cari_iptal'] === [(int)$ch['id']]
    && cari_bakiye($db, $CR)['TRY']['bakiye'] === 20200);
$r = $fis('giris', 'Merkez Depo', [[$A, '10', '1,50']], ['neden_id' => (string)$ALIS, 'cari_id' => (string)$CR, 'para_birimi' => 'USD', 'kur' => '32,5']);
$fu = stok_fis_ham($db, (int)$r['id']); $chu = cari_hareket_oku($db, (int)$fu['cari_hareket_id']);
ok('USD alış: cari hareketi USD 15,15 (kur bilgi); defter değeri TRY = 15 × 32,5 = 487,50 ₺', $r['ok'] && $chu['para_birimi'] === 'USD' && cari_kurus($chu['tutar']) === 1515 && (float)$chu['kur'] === 32.5
    && cari_kurus($db->query('SELECT deger FROM stok_hareketler WHERE fis_id = ' . (int)$r['id'])->fetchColumn()) === 48750 && $fu['para_birimi'] === 'USD');
$f0 = say('stok_fisler'); $h0 = say('stok_hareketler'); $ch0 = say('cari_hareketler');
$db->exec('ALTER TABLE cari_hareketler RENAME TO cari_hareketler_gecici');
$r = $fis('giris', 'Merkez Depo', [[$A, '5', '1']], ['neden_id' => (string)$ALIS, 'cari_id' => (string)$CR]);
$db->exec('ALTER TABLE cari_hareketler_gecici RENAME TO cari_hareketler');
ok('cari hareketi yazılamazsa HEPSİ geri alınır (fiş/defter yok)', !$r['ok'] && str_contains((string)$r['hata'], 'Cari hareketi yazılamadı') && say('stok_fisler') === $f0 && say('stok_hareketler') === $h0 && say('cari_hareketler') === $ch0, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $fis('transfer', 'Merkez Depo', [[$A, '1']], ['hedef_depo' => 'Şube Depo', 'cari_id' => (string)$CR]);
ok('transferde cari yasak', !$r['ok'] && isset($r['alanlar']['cari_id']));
ok('stok_cari_secenekleri: aktif cariler (id/kod/unvan/pb)', ($cs = stok_cari_secenekleri($db)) && $cs[0]['unvan'] === 'Örnek Tedarik Ltd.' && $cs[0]['pb'] === 'TRY');

echo "\n=== 19. Defter değişmezliği (B) ===\n";
ok('her fişin defter satırı fiş satırıyla bağlı (satir_id dolu, ters satırlar dahil)', say('stok_hareketler', 'fis_id IN (SELECT id FROM stok_fisler WHERE istek_anahtari IS NULL OR istek_anahtari IS NOT NULL) AND satir_id IS NULL AND fis_id > ?', [$F1 - 1]) === 0);
ok('iptal fişlerinde her orijinal satırın TEK tersi var', !$db->query('SELECT ters_id FROM stok_hareketler WHERE ters_id IS NOT NULL GROUP BY ters_id HAVING COUNT(*) > 1')->fetchAll());
ok('stok_bakiye_haritasi + stok_ortlar_toplu', ($bh = stok_bakiye_haritasi($db)) && isset($bh[$A]['Merkez Depo']) && ($ot = stok_ortlar_toplu($db)) && isset($ot[$A]));
ok('audit eylemleri: create|update|activate|deactivate|delete|cancel', !$db->query("SELECT COUNT(*) FROM audit_log WHERE module = 'stok' AND action NOT IN ('create','update','activate','deactivate','delete','cancel')")->fetchColumn());

siparis_test_bitir();
