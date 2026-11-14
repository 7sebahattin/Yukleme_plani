<?php
// =========================================================
// scripts/fatura_core_smoke.php — Fatura çekirdeği (config/fatura_core.php) + tek yazma ucu (fatura_kaydet.php) davranış testleri — Dalga A
//   php scripts/fatura_core_smoke.php      → çıkış kodu 0 = hepsi geçti
// Geçici dosya SQLite + stub auth; canlı DB'ye HİÇ dokunmaz. Kapsam (plan §13.1): şema/tohum · kapı/503 · saf hesap (kuruşu kuruşuna) ·
// numara (seri/elle, mükerrer, geriye tarih) · doğrulama · satış/alış/müstahsil/ihracat/iade · stok + cari bağı (tek dekont, cari'siz stok fişi) ·
// yetersiz stok · rollback · idempotans · dönüşüm/kalan · iptal zinciri · stoktan kaynaklı fiş iptali · depo · tanımlar · uç (gerçek fatura_kaydet.php) ·
// audit · cari özeti/son fiyat.
// =========================================================
require_once __DIR__ . '/_fatura_test_lib.php';
$db = db();
$IS_ADMIN = true; $UID = 1;
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function audit_say(string $eylem): int { return say('audit_log', "module = 'fatura' AND action = ?", [$eylem]); }
function uc(array $post, bool $json = true, array $ek = []): array
{
    return fatura_t_istek_cagri(['dosya' => 'fatura_kaydet.php', 'post' => $post + ['csrf' => 'test-csrf-belirteci'], 'server' => $json ? ['HTTP_X_REQUESTED_WITH' => 'fetch'] : []] + $ek);
}
function js(array $r): array { $j = json_decode(trim($r['govde']), true); return is_array($j) ? $j : ['_cozulemedi' => $r['govde']]; }
function baslik(array $r, string $ad): ?string { foreach ($r['basliklar'] as $b) if (stripos($b, $ad . ':') === 0) return trim(substr($b, strlen($ad) + 1)); return null; }
function sayilar(): array { return [say('fatura_belgeler'), say('fatura_satirlar'), say('stok_fisler'), say('stok_hareketler'), say('cari_hareketler')]; }
$Y = date('Y'); $bugun = date('Y-m-d');

echo "=== 1. Şema / tohum / 503 kapısı ===\n";
ok('kurulum öncesi fatura_hazir() = false', !fatura_hazir($db));
ok('fatura_tablolar: 3 tablo, hepsi CREATE TABLE IF NOT EXISTS, FK/ALTER/ENUM/JSON/ON UPDATE/GENERATED yok', count(fatura_tablolar()) === 3
    && array_keys(fatura_tablolar()) === ['fatura_belgeler', 'fatura_satirlar', 'fatura_tanimlar']
    && !array_filter(fatura_tablolar(), fn($d) => !str_starts_with($d, 'CREATE TABLE IF NOT EXISTS') || preg_match('/FOREIGN KEY|ALTER TABLE|ENUM\(|\bJSON\b|ON UPDATE|GENERATED/i', $d)));
$r = uc(['islem' => 'belge_kaydet']);
ok('hiç şema yok: fatura_kaydet 503 JSON, eksikler = cari, stok, fatura', $r['kod'] === 503 && js($r)['ok'] === false && js($r)['eksikler'] === ['cari', 'stok', 'fatura'] && str_contains((string)js($r)['hata'], 'kurulmamış'), $r['govde']);
stok_t_sema_kur($db); stok_t_cari_kur($db);
$r = uc(['islem' => 'belge_kaydet']);
ok('cari + stok var, fatura yok: 503, eksikler = [fatura]', $r['kod'] === 503 && js($r)['eksikler'] === ['fatura'], $r['govde']);
$tmp = sys_get_temp_dir() . '/fatura_t_yalniz_' . getmypid() . '.sqlite';
@unlink($tmp);
$tdb = new PDO('sqlite:' . $tmp); $tdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
siparis_test_diger_tablolar($tdb); fatura_t_kanca(); fatura_migrate($tdb); $tdb = null;
$r = uc(['islem' => 'belge_kaydet'], true, ['db' => $tmp]);
ok('yalnız fatura şeması var (cari/stok YOK): yine 503, eksikler = cari, stok', $r['kod'] === 503 && js($r)['eksikler'] === ['cari', 'stok'], $r['govde']);
$r = fatura_t_istek_cagri(['dosya' => 'fatura_kaydet.php', 'post' => ['islem' => 'belge_kaydet', 'csrf' => 'test-csrf-belirteci'], 'db' => $tmp]);
ok('klasik istekte 503 yerine uyarı kartı (migrate.php#cari / #stok bağlantıları; render_header + footer)', str_contains($r['govde'], 'migrate.php#cari') && str_contains($r['govde'], 'migrate.php#stok') && !str_contains($r['govde'], 'migrate.php#fatura'));
@unlink($tmp);
fatura_t_kanca();
$rap = fatura_migrate($db);
ok('fatura_migrate: 3 tablo oluşturuldu', count($rap) === 3 && !array_filter($rap, fn($x) => $x['durum'] !== 'olusturuldu'), json_encode($rap, JSON_UNESCAPED_UNICODE));
ok('fatura_hazir() = true', fatura_hazir($db));
$ts = fn(string $t): int => say('fatura_tanimlar', 'tur = ?', [$t]);
ok('tohum 26: kdv 4, tevkifat 4, stopaj 1, bagkur 1, istisna 1, seri 3, teslim 11, sablon 1', say('fatura_tanimlar') === 26 && count(fatura_tanim_tohumu()) === 26 && $ts('kdv') === 4 && $ts('tevkifat') === 4
    && $ts('stopaj') === 1 && $ts('bagkur') === 1 && $ts('istisna') === 1 && $ts('seri') === 3 && $ts('teslim') === 11 && $ts('sablon') === 1);
ok('tohum: KDV %1 varsayılan, seriler FTR/IRS/MMK grup varsayılanı, tevkifat payda 10', say('fatura_tanimlar', "tur = 'kdv' AND varsayilan = 1 AND deger1 = 1") === 1
    && say('fatura_tanimlar', "tur = 'seri' AND varsayilan = 1") === 3 && say('fatura_tanimlar', "tur = 'tevkifat' AND deger2 = 10") === 4
    && fatura_seri_sec($db, 'fatura', 0)['kod'] === 'FTR' && fatura_seri_sec($db, 'irsaliye', 0)['kod'] === 'IRS' && fatura_seri_sec($db, 'mustahsil', 0)['kod'] === 'MMK');
$rap2 = fatura_migrate($db);
ok('ikinci migrate: hepsi "var", tohum çoğalmaz', !array_filter($rap2, fn($x) => $x['durum'] !== 'var') && say('fatura_tanimlar') === 26);

echo "\n=== 2. Kapı ===\n";
ok('fatura_can(read|write|delete|admin) admin için true; bilinmeyen false', fatura_can('read') && fatura_can('write') && fatura_can('delete') && fatura_can('admin') && !fatura_can('sil') && !fatura_can(''));
$IS_ADMIN = false;
ok('yönetici değilse hepsi false (izinler olsa bile)', (function () { global $PERMS; $PERMS = ['stok.read', 'stok.write', 'siparis.write', 'reports.export']; $r = !fatura_can('read') && !fatura_can('write'); $PERMS = []; return $r; })());
$IS_ADMIN = true;
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
ok('fatura_json_mi: X-Requested-With fetch', fatura_json_mi());
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
ok('fatura_json_mi: düz istek false', !fatura_json_mi());

echo "\n=== 3. Saf hesap (kuruş) ===\n";
$s1 = fatura_satir_hesapla(1250000, 123456, 250, 1);
$s2 = fatura_satir_hesapla(3000, 3333, 0, 20);
ok('satış satır 1: 1250 kg × 12,3456 → brüt 1.543.200 kr, isk %2,5 = 38.580, net 1.504.620, KDV %1 = 15.046', $s1 === ['brut' => 1543200, 'isk' => 38580, 'net' => 1504620, 'kdv' => 15046], json_encode($s1));
ok('satış satır 2: 3 adet × 0,3333 → 99,99 → 100 kr, KDV %20 = 20', $s2 === ['brut' => 100, 'isk' => 0, 'net' => 100, 'kdv' => 20], json_encode($s2));
$T = fatura_toplam_hesapla([$s1 + ['kdv_orani' => 1], $s2 + ['kdv_orani' => 20]], []);
ok('satış toplam: net 1.504.720 · KDV 15.066 · genel 1.519.786; KDV grupları %1 / %20', $T['net'] === 1504720 && $T['kdv'] === 15066 && $T['genel'] === 1519786 && $T['cari'] === 1519786
    && $T['kdv_gruplari'] === [1 => ['net' => 1504620, 'kdv' => 15046], 20 => ['net' => 100, 'kdv' => 20]], json_encode($T));
$st = fatura_satir_hesapla(1000000, 100000, 0, 20);
$T = fatura_toplam_hesapla([$st + ['kdv_orani' => 20]], ['tev_pay' => 7, 'tev_payda' => 10]);
ok('tevkifat: net 1.000.000, KDV %20 = 200.000, 7/10 = 140.000 → cari 1.060.000', $T['net'] === 1000000 && $T['kdv'] === 200000 && $T['tevkifat'] === 140000 && $T['cari'] === 1060000, json_encode($T));
$sm = fatura_satir_hesapla(5000000, 200000, 0, 0);
$T = fatura_toplam_hesapla([$sm + ['kdv_orani' => 0]], ['stopaj_bp' => 200, 'bagkur_bp' => 100]);
ok('müstahsil: 5000 kg × 20 = 10.000.000; stopaj %2 = 200.000; bağkur %1 = 100.000 → cari 9.700.000', $T['net'] === 10000000 && $T['stopaj'] === 200000 && $T['bagkur'] === 100000 && $T['cari'] === 9700000, json_encode($T));
$si = fatura_satir_hesapla(2000000, 11500, 0, 0);
ok('ihracat USD: 2000 kg × 1,15 = 230.000 cent, KDV 0; TL karşılığı round(230000 × 34,5678) = 7.950.594 kr', $si['net'] === 230000 && $si['kdv'] === 0 && (int)round(230000 * 34.5678) === 7950594);
ok('yarım kuruş: net 1.005 × %10 = 100,5 → 101 (yarım sıfırdan uzağa)', fatura_satir_hesapla(1000, 100500, 0, 10)['kdv'] === 101);
ok('fiyatsız satır: hepsi null; toplamda sayılmaz', fatura_satir_hesapla(1000, null, 0, 1) === ['brut' => null, 'isk' => null, 'net' => null, 'kdv' => null]
    && fatura_toplam_hesapla([['brut' => null, 'isk' => null, 'net' => null, 'kdv' => null]], [])['genel'] === 0);
foreach ([['2,5', 250], ['%10', 1000], ['0', 0], ['99,99', 9999], ['2.5', 250], [' 12,34 ', 1234], ['100', null], ['1,234', null], ['', null], ['-1', null], ['abc', null], [[1], null]] as [$g, $bek]) {
    ok('fatura_bp_coz(' . json_encode($g) . ') = ' . json_encode($bek), fatura_bp_coz($g) === $bek, json_encode(fatura_bp_coz($g)));
}
ok('fatura_bp_bicim: 250 → "2,5", 200 → "2", 1234 → "12,34", 5 → "0,05"', fatura_bp_bicim(250) === '2,5' && fatura_bp_bicim(200) === '2' && fatura_bp_bicim(1234) === '12,34' && fatura_bp_bicim(5) === '0,05');
ok('fatura_stok_fiyat4: iskontosuz → f4 aynen; iskontolu → net birim fiyat', fatura_stok_fiyat4(1000000, 1000000, 0, 100000) === 100000 && fatura_stok_fiyat4(975000, 1000000, 250, 100000) === 97500);

echo "\n=== 4. Numara biçimleri / sabitler ===\n";
ok('fatura_belge_no satis → STF-2026-000123; irsaliye IRS; sipariş ASF', fatura_belge_no('satis', '2026-11-14', 123) === 'STF-2026-000123' && fatura_belge_no('irsaliye', '2026-01-01', 1) === 'IRS-2026-000001' && fatura_belge_no('siparis', '2026-01-01', 7) === 'ASF-2026-000007');
ok('fatura_seri_no: FTR2026000000012 (16 hane)', fatura_seri_no('FTR', 2026, 12) === 'FTR2026000000012' && strlen(fatura_seri_no('FTR', 2026, 12)) === 16);
ok('fatura_turleri: 8 tür, 4 sınıf, sayfalar', array_keys(fatura_turleri()) === ['satis', 'satis_ihracat', 'satis_iade', 'alis', 'alis_mustahsil', 'alis_iade', 'irsaliye', 'siparis']
    && fatura_sinif_turleri('satis') === ['satis', 'satis_ihracat', 'satis_iade'] && fatura_sinif_turleri('alis') === ['alis', 'alis_mustahsil', 'alis_iade']
    && fatura_tur_bilgi('irsaliye')['sayfa'] === 'sevk_irsaliyeleri.php' && fatura_tur_bilgi('siparis')['sayfa'] === 'alinan_siparis_fisleri.php' && fatura_tur_bilgi('alis')['sayfa'] === 'alis_faturalari.php');
ok('cari yönleri: satış/ihracat/alış iadesi borc_dekont; alış/müstahsil/satış iadesi alacak_dekont; irsaliye/sipariş cari yok', fatura_tur_bilgi('satis')['cari'] === 'borc_dekont' && fatura_tur_bilgi('alis_iade')['cari'] === 'borc_dekont'
    && fatura_tur_bilgi('alis')['cari'] === 'alacak_dekont' && fatura_tur_bilgi('satis_iade')['cari'] === 'alacak_dekont' && fatura_tur_bilgi('irsaliye')['cari'] === null && fatura_tur_bilgi('siparis')['cari'] === null);
foreach (['satis_faturalari.php?id=3' => 'satis_faturalari.php?id=3', 'alinan_siparis_fisleri.php' => 'alinan_siparis_fisleri.php', 'fatura_tanimlari.php?sekme=seri' => 'fatura_tanimlari.php?sekme=seri',
    'https://kotu.example/x' => 'satis_faturalari.php', '//kotu.example' => 'satis_faturalari.php', "satis_faturalari.php\r\nSet-Cookie: x=1" => 'satis_faturalari.php', 'fatura_kaydet.php' => 'satis_faturalari.php', '' => 'satis_faturalari.php'] as $g => $bek) {
    ok('fatura_geri_guvenli(' . json_encode($g) . ") = $bek", fatura_geri_guvenli($g) === $bek);
}
ok('fatura_geri_guvenli(dizi) = varsayılan', fatura_geri_guvenli(['x']) === 'satis_faturalari.php');

// ── Test verisi (uydurma) ───────────────────────────────────────────────────
$kart = fn(string $ad, string $birim, int $kdv, array $ek = []) => (int)stok_kart_kaydet($db, ['ad' => $ad, 'ana_birim' => $birim, 'kdv_orani' => (string)$kdv, 'aktif' => '1'] + $ek, 1)['id'];
$DOM = $kart('Fatura Test Domates', 'kg', 1, ['cevrim_birim' => 'kasa', 'cevrim_katsayi' => '8']);
$KASA = $kart('Fatura Test Kasa', 'adet', 20);
$PAT = $kart('Fatura Test Patates', 'kg', 1);
$PAS = $kart('Fatura Test Pasif', 'kg', 1);
stok_kart_durum($db, $PAS, false, 1);
$cari = fn(string $u, string $tip, string $pb, string $vade = '') => (int)cari_kart_kaydet($db, ['unvan' => $u, 'tip' => $tip, 'para_birimi' => $pb, 'vade_gun' => $vade, 'aktif' => '1'], 1)['id'];
$MUS = $cari('Örnek Alıcı A.Ş.', 'musteri', 'TRY', '30');
$TED = $cari('Örnek Tedarikçi Ltd.', 'tedarikci', 'TRY');
$YD = $cari('Örnek Yurtdışı Alıcı', 'musteri', 'USD');
$MUS2 = $cari('Deneme Gıda A.Ş.', 'musteri', 'TRY');
$PCARI = $cari('Pasif Cari Ltd.', 'musteri', 'TRY');
cari_kart_durum($db, $PCARI, false, 1);
$ALIS_N = fatura_stok_neden_id($db, 'giris', 'alis');
$r = stok_fis_kaydet($db, stok_t_fis_istek('giris', 'Merkez Depo', [[$DOM, '10.000', '10,00'], [$KASA, '1.000', '2,00']], ['neden_id' => (string)$ALIS_N, 'tarih' => "$Y-11-01"]), 1);
ok('test verisi: açılış stok girişi (Domates 10.000 kg, Kasa 1.000 adet)', $r['ok'] && fatura_stok_neden_id($db, 'cikis', 'satis') !== null, json_encode($r, JSON_UNESCAPED_UNICODE));
$bak = function (int $k, string $d = 'Merkez Depo') use ($db): int { $st = $db->prepare('SELECT COALESCE(SUM(miktar),0) FROM stok_hareketler WHERE kart_id = ? AND depo = ?'); $st->execute([$k, $d]); return stok_milli($st->fetchColumn()); };
$kdv = fn(string $ad) => fatura_t_tanim($db, 'kdv', $ad);
$TEV7 = fatura_t_tanim($db, 'tevkifat', 'Tevkifat 7/10');
$STOP = fatura_t_tanim($db, 'stopaj', 'Müstahsil GV stopajı %2');
$BAGK = fatura_t_tanim($db, 'bagkur', 'Bağ-Kur kesintisi %1');
$IST = fatura_t_tanim($db, 'istisna', '301 — KDV 11/1-a Mal ihracatı');
$FOB = fatura_t_tanim($db, 'teslim', 'FOB');
$dg = fn(string $tur, array $sat, array $ek = []) => fatura_dogrula($db, fatura_t_istek($tur, $sat, $ek));
$kay = fn(string $tur, array $sat, array $ek = []) => fatura_kaydet($db, fatura_t_istek($tur, $sat, $ek), 1);

echo "\n=== 5. Doğrulama ===\n";
$sat1 = [['kart_id' => $DOM, 'miktar' => '10', 'birim_fiyat' => '12,00']];
$d = $dg('satis', $sat1);
ok('cari zorunlu', !$d['ok'] && isset($d['alanlar']['cari_id']));
$d = $dg('satis', $sat1, ['cari_id' => (string)$PCARI]);
ok('pasif cari seçilemez', !$d['ok'] && str_contains((string)($d['alanlar']['cari_id'] ?? ''), 'Pasif'));
$d = $dg('satis', $sat1, ['cari_ara' => 'Örnek Alıcı A.Ş.']);
ok('cari metinle çözülür (unvan; JS kapalı form) + vade = tarih + 30 gün', $d['ok'] && $d['veri']['cari_id'] === $MUS && $d['veri']['vade'] === date('Y-m-d', strtotime($bugun . ' +30 days')), json_encode($d['alanlar'] ?? [], JSON_UNESCAPED_UNICODE));
$d = $dg('satis', $sat1, ['cari_id' => (string)$YD, 'para_birimi' => 'USD']);
ok('TRY dışı para biriminde kur zorunlu', !$d['ok'] && isset($d['alanlar']['kur']));
$d = $dg('satis', $sat1, ['cari_id' => (string)$MUS, 'kur' => '32,5']);
ok('TRY\'de kur dolu → hata (TL için kur girilmez)', !$d['ok'] && ($d['alanlar']['kur'] ?? '') === 'TL için kur girilmez.');
$d = $dg('satis_ihracat', [['kart_id' => $DOM, 'miktar' => '10', 'birim_fiyat' => '1,15', 'kdv_orani' => '20']], ['cari_id' => (string)$YD, 'para_birimi' => 'USD', 'kur' => '34,5678']);
ok('ihracat: istisna kodu zorunlu + KDV ≠ 0 satır hatası', !$d['ok'] && isset($d['alanlar']['istisna_id']) && isset($d['satir_hatalari'][0]['kdv_orani']), json_encode($d, JSON_UNESCAPED_UNICODE));
$d = $dg('alis_mustahsil', [['kart_id' => $DOM, 'miktar' => '10', 'birim_fiyat' => '1']], ['cari_id' => (string)$TED, 'tevkifat_id' => (string)$TEV7]);
ok('müstahsilde tevkifat yasak', !$d['ok'] && isset($d['alanlar']['tevkifat_id']));
$d = $dg('satis', $sat1, ['cari_id' => (string)$MUS, 'stopaj_id' => (string)$STOP]);
ok('satışta stopaj yasak (yalnız müstahsil)', !$d['ok'] && isset($d['alanlar']['stopaj_id']));
$d = $dg('irsaliye', [['urun_adi' => 'Serbest hizmet', 'miktar' => '1']], ['cari_id' => (string)$MUS]);
ok('irsaliyede kartsız satır yasak', !$d['ok'] && isset($d['satir_hatalari'][0]['kart_id']));
$d = $dg('satis', [['urun_adi' => 'Nakliye hizmeti', 'miktar' => '1', 'birim' => 'adet', 'birim_fiyat' => '500']], ['cari_id' => (string)$MUS, 'stok_etkisi' => '1']);
ok('satışta serbest (kartsız) satır izinli; stoğa girmez (stoklu 0)', $d['ok'] && $d['veri']['satirlar'][0]['kart_id'] === null && $d['veri']['satirlar'][0]['stoklu'] === 0 && $d['veri']['satirlar'][0]['urun_adi'] === 'Nakliye hizmeti');
$d = $dg('satis', [['miktar' => ''], ['kart_id' => $DOM, 'miktar' => '5', 'birim_fiyat' => '1'], ['kart_id' => $DOM, 'miktar' => '12,5', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS]);
ok('satır hatası DOM indeksiyle (boş satır atlanır, hatalı satır i=2: kg kesir)', !$d['ok'] && array_keys($d['satir_hatalari']) === [2] && isset($d['satir_hatalari'][2]['miktar']), json_encode($d['satir_hatalari'] ?? []));
$d = $dg('satis', [['kart_id' => $PAS, 'miktar' => '5', 'birim_fiyat' => '1'], ['kart_ara' => 'YOK-KOD', 'miktar' => '5', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS]);
ok('pasif kart / bilinmeyen kart reddedilir', !$d['ok'] && str_contains((string)$d['satir_hatalari'][0]['kart_id'], 'Pasif') && isset($d['satir_hatalari'][1]['kart_id']));
$d = $dg('satis', [['kart_id' => $DOM, 'miktar' => '3', 'birim' => 'kasa', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS]);
ok('çevrim birimi: 3 kasa × 8 = 24 kg (ana birime)', $d['ok'] && $d['veri']['satirlar'][0]['milli'] === 24000 && $d['veri']['satirlar'][0]['birim'] === 'kg');
$d = $dg('satis', [['kart_id' => $DOM, 'miktar' => '5']], ['cari_id' => (string)$MUS]);
ok('faturada fiyat zorunlu', !$d['ok'] && isset($d['satir_hatalari'][0]['birim_fiyat']));
$d = $dg('siparis', [['kart_id' => $DOM, 'miktar' => '5']], ['cari_id' => (string)$MUS]);
ok('siparişte fiyat opsiyonel (tutar NULL); stok etkisi zorla 0', $d['ok'] && $d['veri']['satirlar'][0]['net'] === null && $d['veri']['stok_etkisi'] === 0);
$d = $dg('satis', [['kart_id' => $DOM, 'miktar' => '5', 'birim_fiyat' => '1', 'kdv_orani' => '18']], ['cari_id' => (string)$MUS]);
ok('KDV oranı tanımlı oranlardan biri olmalı (18 yok)', !$d['ok'] && isset($d['satir_hatalari'][0]['kdv_orani']));
$d = $dg('satis', [['kart_id' => $DOM, 'miktar' => '5', 'birim_fiyat' => '1', 'iskonto' => '100']], ['cari_id' => (string)$MUS]);
ok('%100 iskonto yasak', !$d['ok'] && isset($d['satir_hatalari'][0]['iskonto']));
$d = $dg('alis', $sat1, ['cari_id' => (string)$TED]);
ok('alışta karşı tarafın numarası zorunlu', !$d['ok'] && isset($d['alanlar']['fatura_no']));
$d = $dg('satis', array_fill(0, 61, ['kart_id' => $DOM, 'miktar' => '1', 'birim_fiyat' => '1']), ['cari_id' => (string)$MUS]);
ok('61 satır reddedilir (FATURA_SATIR_MAX = 60)', FATURA_SATIR_MAX === 60 && !$d['ok'] && isset($d['alanlar']['satir']));
$d = $dg('satis', array_fill(0, 60, ['kart_id' => $DOM, 'miktar' => '1', 'birim_fiyat' => '1']), ['cari_id' => (string)$MUS]);
ok('60 satır kabul', $d['ok']);
$d = $dg('satis', [['kart_id' => $DOM, 'miktar' => '1', 'birim_fiyat' => '0,0001']], ['cari_id' => (string)$MUS]);
ok('tutarı sıfır çıkan satır reddedilir (belge tutarı sıfır)', !$d['ok'] && isset($d['satir_hatalari'][0]['birim_fiyat']));
$d = $dg('satis', $sat1, ['cari_id' => (string)$MUS, 'depo' => 'Olmayan Depo']);
ok('depo seçeneklerde olmalı', !$d['ok'] && isset($d['alanlar']['depo']));
$d = $dg('satis', $sat1, ['cari_id' => (string)$MUS, 'odeme_sekli' => 'Havale/EFT']);
$d2 = $dg('satis', $sat1, ['cari_id' => (string)$MUS, 'odeme_sekli' => 'Kripto']);
ok('ödeme şekli cari tanımlarından (Havale/EFT geçerli, uydurma ad red)', $d['ok'] && $d['veri']['odeme_sekli'] === 'Havale/EFT' && !$d2['ok'] && isset($d2['alanlar']['odeme_sekli']));
ok('geçersiz tür', !$dg('fatura', $sat1, ['cari_id' => (string)$MUS])['ok']);

echo "\n=== 6. Satış (stok etkisi 0): yalnız cari dekontu ===\n";
[$nb, $ns, $nf, $nh, $nc] = sayilar(); $ac0 = audit_say('create');
$r = $kay('satis', [['kart_id' => $DOM, 'miktar' => '1.250', 'birim_fiyat' => '12,3456', 'iskonto' => '2,5', 'kdv_orani' => '1'], ['kart_id' => $KASA, 'miktar' => '3', 'birim_fiyat' => '0,3333', 'kdv_orani' => '20']],
    ['cari_id' => (string)$MUS, 'tarih' => "$Y-11-10", 'istek_anahtari' => 'stf-1', 'odeme_sekli' => 'Havale/EFT']);
$S1 = (int)($r['id'] ?? 0); $b = $S1 ? fatura_ham($db, $S1) : [];
ok('kaydet: ok, belge_no STF-Y-<id>, fatura_no FTR{Y}000000001 (seri), cari_belge_no BDK-', $r['ok'] && $r['belge_no'] === fatura_belge_no('satis', "$Y-11-10", $S1) && $r['fatura_no'] === "FTR{$Y}000000001"
    && str_starts_with((string)$r['cari_belge_no'], 'BDK-') && $r['stok_fis_no'] === null, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('toplamlar DB\'de: net 15.047,20 · KDV 150,66 · genel 15.197,86 · cari 15.197,86', cari_kurus($b['net_toplam']) === 1504720 && cari_kurus($b['kdv_toplam']) === 15066 && cari_kurus($b['genel_toplam']) === 1519786 && cari_kurus($b['cari_tutar']) === 1519786
    && cari_kurus($b['brut_toplam']) === 1543300 && cari_kurus($b['iskonto_toplam']) === 38580);
ok('cari snapshot (kod/unvan) + vade = tarih + 30 + seri sırası 1 + ödeme şekli', $b['cari_unvan'] === 'Örnek Alıcı A.Ş.' && $b['cari_kod'] !== '' && $b['vade_tarihi'] === date('Y-m-d', strtotime("$Y-11-10 +30 days"))
    && (int)$b['seri_sira'] === 1 && (int)$b['seri_yil'] === (int)$Y && $b['odeme_sekli'] === 'Havale/EFT');
$ch = cari_hareket_oku($db, (int)$b['cari_hareket_id']);
ok('TEK cari satırı: borc_dekont (B), 15.197,86 TRY, kaynak fatura:id, evrak = fatura no', say('cari_hareketler') === $nc + 1 && $ch['tur'] === 'borc_dekont' && $ch['yon'] === 'B' && cari_kurus($ch['tutar']) === 1519786
    && $ch['para_birimi'] === 'TRY' && $ch['kaynak_modul'] === 'fatura' && (int)$ch['kaynak_id'] === $S1 && $ch['evrak_no'] === "FTR{$Y}000000001");
ok('stok etkisi 0: stok fişi YOK (stok_fis_id NULL, defter değişmedi), satırlar stoklu=0', $b['stok_fis_id'] === null && say('stok_fisler') === $nf && say('stok_hareketler') === $nh && say('fatura_satirlar', 'belge_id = ? AND stoklu = 1', [$S1]) === 0);
ok('2 satır + audit create +1 (fatura) + cari create', say('fatura_satirlar', 'belge_id = ?', [$S1]) === 2 && audit_say('create') === $ac0 + 1 && say('audit_log', "module = 'cari' AND action = 'create' AND new_values LIKE '%\"kaynak_modul\":\"fatura\"%'") >= 1);
$sr = $db->query("SELECT * FROM fatura_satirlar WHERE belge_id = $S1 ORDER BY sira")->fetchAll();
ok('satır snapshot: kart kodu/adı, birim kg, miktar 1250.000, fiyat 12.3456, iskonto 250 bp, KDV 1', $sr[0]['urun_adi'] === 'Fatura Test Domates' && $sr[0]['birim'] === 'kg' && stok_milli($sr[0]['miktar']) === 1250000
    && abs((float)$sr[0]['birim_fiyat'] - 12.3456) < 1e-9 && (int)$sr[0]['iskonto_bp'] === 250 && (int)$sr[0]['kdv_orani'] === 1 && cari_kurus($sr[0]['tutar']) === 1504620);

echo "\n=== 7. Satış (stok etkisi 1): cari'siz stok çıkış fişi + TEK dekont ===\n";
[$nb, $ns, $nf, $nh, $nc] = sayilar(); $b0 = $bak($DOM);
$r = $kay('satis', [['kart_id' => $DOM, 'miktar' => '100', 'birim_fiyat' => '15,00', 'lot' => 'L-01']], ['cari_id' => (string)$MUS, 'tarih' => "$Y-11-11", 'stok_etkisi' => '1']);
$S2 = (int)($r['id'] ?? 0); $b = $S2 ? fatura_ham($db, $S2) : []; $sf = $b ? stok_fis_ham($db, (int)$b['stok_fis_id']) : [];
ok('kaydet ok; fatura_no FTR{Y}000000002 (ardışık), stok_fis_no CKS-', $r['ok'] && $r['fatura_no'] === "FTR{$Y}000000002" && str_starts_with((string)$r['stok_fis_no'], 'CKS-'), json_encode($r, JSON_UNESCAPED_UNICODE));
ok('stok fişi: çıkış, cari_id NULL, kaynak fatura:<id>, neden Satış/Sevk, evrak = fatura no, fiyatsız', $sf['tur'] === 'cikis' && $sf['cari_id'] === null && $sf['cari_hareket_id'] === null && $sf['kaynak_modul'] === 'fatura'
    && (int)$sf['kaynak_id'] === $S2 && $sf['neden_ad'] === 'Satış/Sevk' && $sf['evrak_no'] === "FTR{$Y}000000002" && $sf['toplam_tutar'] === null);
ok('defter −100 kg (Merkez), satır stoklu=1', $bak($DOM) === $b0 - 100000 && say('fatura_satirlar', 'belge_id = ? AND stoklu = 1', [$S2]) === 1);
ok('cari satırı TAM 1 (çift dekont YOK — tuzak #1): 1.515,00 (1.500 + %1)', say('cari_hareketler') === $nc + 1 && say('cari_hareketler', "kaynak_modul = 'fatura' AND kaynak_id = ?", [$S2]) === 1
    && say('cari_hareketler', "kaynak_modul = 'stok' AND kaynak_id = ?", [(int)$b['stok_fis_id']]) === 0 && cari_kurus(cari_hareket_oku($db, (int)$b['cari_hareket_id'])['tutar']) === 151500);

echo "\n=== 8. Alış / müstahsil / tevkifat / USD ===\n";
$r = $kay('alis', [['kart_id' => $PAT, 'miktar' => '1.000', 'birim_fiyat' => '5,00']], ['cari_id' => (string)$TED, 'fatura_no' => 'ABC2026000000077', 'stok_etkisi' => '1', 'tarih' => "$Y-11-05"]);
$A1 = (int)($r['id'] ?? 0); $b = $A1 ? fatura_ham($db, $A1) : [];
$deger = fn(int $fis): int => cari_kurus($db->query('SELECT COALESCE(SUM(deger),0) FROM stok_hareketler WHERE fis_id = ' . $fis)->fetchColumn());
ok('alış: fatura_no elle (ALF-), alacak_dekont 5.050,00, giriş fişi değer = net 5.000,00 (iskontosuz birebir)', $r['ok'] && str_starts_with((string)$r['belge_no'], 'ALF-') && $b['fatura_no'] === 'ABC2026000000077'
    && cari_hareket_oku($db, (int)$b['cari_hareket_id'])['tur'] === 'alacak_dekont' && cari_kurus(cari_hareket_oku($db, (int)$b['cari_hareket_id'])['tutar']) === 505000
    && stok_fis_ham($db, (int)$b['stok_fis_id'])['tur'] === 'giris' && $deger((int)$b['stok_fis_id']) === 500000 && stok_fis_ham($db, (int)$b['stok_fis_id'])['neden_ad'] === 'Alış', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $kay('alis', [['kart_id' => $PAT, 'miktar' => '333', 'birim_fiyat' => '7,7777', 'iskonto' => '3,33']], ['cari_id' => (string)$TED, 'fatura_no' => 'ABC-78', 'stok_etkisi' => '1']);
$b = fatura_ham($db, (int)$r['id']);
$net = cari_kurus($b['net_toplam']);
ok('iskontolu alış: stok değeri net ile sapma ≤ milli/200000 + 1 kuruş', $r['ok'] && abs($deger((int)$b['stok_fis_id']) - $net) <= (int)ceil(333000 / 200000) + 1, $deger((int)$b['stok_fis_id']) . ' / ' . $net);
$r = $kay('alis', [['kart_id' => $PAT, 'miktar' => '100', 'birim_fiyat' => '1,50']], ['cari_id' => (string)$TED, 'fatura_no' => 'USD-1', 'para_birimi' => 'USD', 'kur' => '32,5', 'stok_etkisi' => '1']);
$b = fatura_ham($db, (int)$r['id']); $chu = cari_hareket_oku($db, (int)$b['cari_hareket_id']);
ok('USD alış: dekont 151,50 USD + kur; stok değeri TRY = 150 × 32,5 = 4.875,00', $r['ok'] && $chu['para_birimi'] === 'USD' && cari_kurus($chu['tutar']) === 15150 && (float)$chu['kur'] === 32.5 && $deger((int)$b['stok_fis_id']) === 487500);
$r = $kay('alis_mustahsil', [['kart_id' => $DOM, 'miktar' => '5.000', 'birim_fiyat' => '20,00']], ['cari_id' => (string)$TED, 'stopaj_id' => (string)$STOP, 'bagkur_id' => (string)$BAGK, 'stok_etkisi' => '1']);
$M1 = (int)($r['id'] ?? 0); $b = $M1 ? fatura_ham($db, $M1) : [];
ok('müstahsil: MMK seri no, dekont NET 97.000,00 (alacak), stok değeri BRÜT 100.000,00, stopaj/bağkur snapshot', $r['ok'] && $r['fatura_no'] === "MMK{$Y}000000001"
    && cari_kurus(cari_hareket_oku($db, (int)$b['cari_hareket_id'])['tutar']) === 9700000 && $deger((int)$b['stok_fis_id']) === 10000000 && cari_kurus($b['stopaj_tutar']) === 200000
    && cari_kurus($b['bagkur_tutar']) === 100000 && (int)$b['stopaj_bp'] === 200 && $b['stopaj_ad'] === 'Müstahsil GV stopajı %2' && cari_kurus($b['kdv_toplam']) === 0, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $kay('alis', [['kart_id' => $PAT, 'miktar' => '1.000', 'birim_fiyat' => '10,00', 'kdv_orani' => '20']], ['cari_id' => (string)$TED, 'fatura_no' => 'TEV-1', 'tevkifat_id' => (string)$TEV7]);
$b = fatura_ham($db, (int)$r['id']);
ok('tevkifatlı alış: KDV 2.000, tevkifat 7/10 = 1.400 → dekont 10.600,00; tevkifat snapshot', $r['ok'] && cari_kurus($b['tevkifat_tutar']) === 140000 && (int)$b['tevkifat_pay'] === 7 && (int)$b['tevkifat_payda'] === 10
    && cari_kurus(cari_hareket_oku($db, (int)$b['cari_hareket_id'])['tutar']) === 1060000 && $b['stok_fis_id'] === null);
$TEVB = (int)$r['id'];
$r = $kay('satis_ihracat', [['kart_id' => $DOM, 'miktar' => '2.000', 'birim_fiyat' => '1,15', 'gtip' => '070200000000', 'satir_net_kg' => '2.000']],
    ['cari_id' => (string)$YD, 'para_birimi' => 'USD', 'kur' => '34,5678', 'istisna_id' => (string)$IST, 'teslim_id' => (string)$FOB, 'teslim_yeri' => 'Mersin Limanı', 'beyanname_no' => 'BYN-1', 'brut_kg' => '2.100', 'net_kg' => '2.000', 'kap_adedi' => '250']);
$I1 = (int)($r['id'] ?? 0); $b = $I1 ? fatura_ham($db, $I1) : [];
ok('ihracat USD: IHF, KDV 0, dekont 2.300,00 USD + kur, istisna 301, FOB, kg/kap, seri FTR (fatura grubu)', $r['ok'] && str_starts_with((string)$r['belge_no'], 'IHF-') && cari_kurus($b['genel_toplam']) === 230000
    && cari_kurus($b['kdv_toplam']) === 0 && $b['istisna_kod'] === '301' && $b['teslim_sekli'] === 'FOB' && stok_milli($b['brut_kg']) === 2100000 && (int)$b['kap_adedi'] === 250
    && cari_hareket_oku($db, (int)$b['cari_hareket_id'])['para_birimi'] === 'USD' && cari_kurus(cari_hareket_oku($db, (int)$b['cari_hareket_id'])['tutar']) === 230000 && $r['fatura_no'] === "FTR{$Y}000000003", json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n=== 9. Numara: seri sırası / yıl / geriye tarih / mükerrer ===\n";
$r = $kay('satis', $sat1, ['cari_id' => (string)$MUS, 'tarih' => "$Y-11-02"]);
ok('geriye tarihli seri belgesi 422 (seride daha ileri tarihli belge var)', !$r['ok'] && isset($r['alanlar']['tarih']) && str_contains((string)$r['hata'], 'geriye tarihli'), json_encode($r, JSON_UNESCAPED_UNICODE));
$ileri = ((int)$Y + 1) . '-01-05';
if ($ileri <= date('Y-m-d', strtotime('+366 days'))) {
    $r = $kay('satis', $sat1, ['cari_id' => (string)$MUS, 'tarih' => $ileri]);
    ok('yıl değişince seri 1\'den başlar (FTR{Y+1}000000001)', $r['ok'] && $r['fatura_no'] === 'FTR' . ((int)$Y + 1) . '000000001', json_encode($r, JSON_UNESCAPED_UNICODE));
    $YIL2 = (int)$r['id'];
} else { ok('yıl değişimi (tarih sınırı nedeniyle atlanmadı; seri yılı tarihten)', fatura_seri_no('FTR', (int)$Y + 1, 1) === 'FTR' . ((int)$Y + 1) . '000000001'); $YIL2 = 0; }
$r = $kay('alis', $sat1, ['cari_id' => (string)$TED, 'fatura_no' => 'ABC2026000000077']);
ok('elle fatura_no mükerrer (aynı cari + sınıf) → kod mukerrer', !$r['ok'] && ($r['kod'] ?? '') === 'mukerrer' && isset($r['alanlar']['fatura_no']));
$r = $kay('alis', $sat1, ['cari_id' => (string)$MUS2, 'fatura_no' => 'ABC2026000000077']);
ok('aynı numara başka caride serbest', $r['ok']);
$r = $kay('satis_iade', $sat1, ['cari_id' => (string)$MUS, 'fatura_no' => 'X1']);
ok('satış iadesi karşı tarafın numarasıyla (seri yok, SIF-)', $r['ok'] && str_starts_with((string)$r['belge_no'], 'SIF-') && fatura_ham($db, (int)$r['id'])['seri_id'] === null);
$SIX = (int)$r['id'];

echo "\n=== 10. Yetersiz stok / eksiye izin ===\n";
[$nb, $ns, $nf, $nh, $nc] = sayilar();
$r = $kay('satis', [['kart_id' => $KASA, 'miktar' => '999.999', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS, 'stok_etkisi' => '1', 'istek_anahtari' => 'stf-eksi']);
$e = $r['eksikler'][0] ?? [];
ok('yetersiz stok: kod yetersiz_stok + eksikler; HİÇBİR tabloda satır yok (fatura/satır/stok fiş/defter/cari)', !$r['ok'] && ($r['kod'] ?? '') === 'yetersiz_stok' && ($e['kart_id'] ?? 0) === $KASA && sayilar() === [$nb, $ns, $nf, $nh, $nc], json_encode($r, JSON_UNESCAPED_UNICODE));
$sn = fatura_seri_durum($db, fatura_seri_sec($db, 'fatura', 0), (int)$Y)['son_sira'];
$r = $kay('satis', [['kart_id' => $KASA, 'miktar' => '999.999', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS, 'stok_etkisi' => '1', 'istek_anahtari' => 'stf-eksi', 'eksiye_izin' => '1']);
ok('eksiye_izin=1: kayıt + uyarı (Eksiye düşürüldü); seri boşluk bırakmadı (geri alınan deneme sıra tüketmez)', $r['ok'] && str_contains((string)$r['uyari'], 'Eksiye') && $r['fatura_no'] === fatura_seri_no('FTR', (int)$Y, $sn + 1)
    && fatura_ham($db, (int)$r['id'])['uyari'] === null, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('uyarı audit create kaydında', say('audit_log', "module = 'fatura' AND action = 'create' AND record_id = ? AND new_values LIKE '%Eksiye%'", [(int)$r['id']]) === 1);
$EKSI = (int)$r['id'];

echo "\n=== 11. Rollback (iç çağrı !ok → HEPSİ geri) ===\n";
[$nb, $ns, $nf, $nh, $nc] = sayilar(); $sn = fatura_seri_durum($db, fatura_seri_sec($db, 'fatura', 0), (int)$Y)['son_sira'];
$db->exec('ALTER TABLE cari_hareketler RENAME TO cari_hareketler_gecici');
$r = $kay('satis', [['kart_id' => $DOM, 'miktar' => '5', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS, 'stok_etkisi' => '1']);
$db->exec('ALTER TABLE cari_hareketler_gecici RENAME TO cari_hareketler');
ok('cari dekontu yazılamazsa (stok fişi yazılmışken) fatura + satır + stok fişi + defter KALMAZ (tuzak #3)', !$r['ok'] && str_contains((string)$r['hata'], 'Cari hareketi yazılamadı') && sayilar() === [$nb, $ns, $nf, $nh, $nc]
    && fatura_seri_durum($db, fatura_seri_sec($db, 'fatura', 0), (int)$Y)['son_sira'] === $sn, json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n=== 12. İdempotans ===\n";
[$nb] = sayilar();
$r = $kay('satis', [['kart_id' => $DOM, 'miktar' => '1', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS, 'istek_anahtari' => 'stf-1']);
ok('aynı istek_anahtari → tekrar=true, aynı belge, yeni satır yok', $r['ok'] && !empty($r['tekrar']) && (int)$r['id'] === $S1 && $r['belge_no'] === fatura_ham($db, $S1)['belge_no'] && say('fatura_belgeler') === $nb);
$r = $kay('satis', $sat1, ['cari_id' => (string)$MUS, 'istek_anahtari' => 'kötü anahtar!']);
ok('geçersiz istek anahtarı reddedilir', !$r['ok'] && isset($r['alanlar']['istek_anahtari']));

echo "\n=== 13. Dönüşüm (sipariş → irsaliye → fatura → iade) ===\n";
$r = $kay('siparis', [['kart_id' => $DOM, 'miktar' => '1.000', 'birim_fiyat' => '11,00'], ['kart_id' => $KASA, 'miktar' => '100']], ['cari_id' => (string)$MUS2, 'fatura_no' => 'MUS-SIP-9', 'termin_tarihi' => date('Y-m-d', strtotime('+10 days'))]);
$SP1 = (int)($r['id'] ?? 0); $sp = $SP1 ? fatura_oku($db, $SP1) : [];
ok('sipariş: ASF-, cari/stok etkisi yok, termin saklanır, akış "yok"', $r['ok'] && str_starts_with((string)$r['belge_no'], 'ASF-') && $sp['cari_hareket_id'] === null && $sp['stok_fis_id'] === null && $sp['termin_tarihi'] !== null && $sp['akis_durum'] === 'yok', json_encode($r, JSON_UNESCAPED_UNICODE));
[$spD, $spK] = [(int)$sp['satirlar'][0]['id'], (int)$sp['satirlar'][1]['id']];
ok('adaylar: irsaliye için sipariş listelenir (kalan 1.100)', ($ad = fatura_kaynak_adaylari($db, 'irsaliye', $MUS2)) && $ad[0]['id'] === $SP1 && $ad[0]['kalan_milli'] === 1100000 && fatura_kaynak_adaylari($db, 'irsaliye', $MUS) === []);
$ks = fatura_kaynak_satirlari($db, [$SP1], 'irsaliye');
ok('kaynak satırları: 2 satır, miktar = kalan, kaynak_satir_id, fiyat ekran biçiminde', count($ks) === 2 && $ks[0]['kaynak_satir_id'] === $spD && $ks[0]['miktar'] === '1.000' && $ks[0]['birim_fiyat'] === '11,00' && $ks[1]['birim_fiyat'] === '');
$nf = say('stok_fisler'); $b0 = $bak($DOM);
$r = $kay('irsaliye', [['kart_id' => $DOM, 'miktar' => '400', 'kaynak_satir_id' => (string)$spD]], ['cari_id' => (string)$MUS2, 'plaka' => '34 ABC 123', 'sofor_adi' => 'Örnek Şoför', 'sevk_adresi' => 'Örnek Mah. Depo Sk. No:1']);
$IR1 = (int)($r['id'] ?? 0); $ir = $IR1 ? fatura_ham($db, $IR1) : [];
ok('irsaliye 400 kg: IRS seri no, stok çıkışı HER ZAMAN (−400), cari YOK, plaka/şoför', $r['ok'] && $r['fatura_no'] === "IRS{$Y}000000001" && say('stok_fisler') === $nf + 1 && $bak($DOM) === $b0 - 400000 && $ir['cari_hareket_id'] === null
    && (int)$ir['stok_etkisi'] === 1 && $ir['plaka'] === '34 ABC 123', json_encode($r, JSON_UNESCAPED_UNICODE));
ok('sipariş satırı kalan 600 kg; sipariş akış "kismi"', fatura_kalanlar($db, [$spD]) === [$spD => 600000] && fatura_oku($db, $SP1)['akis_durum'] === 'kismi');
$r = $kay('irsaliye', [['kart_id' => $DOM, 'miktar' => '700', 'kaynak_satir_id' => (string)$spD]], ['cari_id' => (string)$MUS2]);
ok('aşım: 700 > 600 → satır hatası "kalan: 600", kod kalan_asimi', !$r['ok'] && ($r['kod'] ?? '') === 'kalan_asimi' && str_contains((string)($r['satir_hatalari'][0]['miktar'] ?? ''), 'kalan: 600'), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $kay('irsaliye', [['kart_id' => $DOM, 'miktar' => '400', 'kaynak_satir_id' => (string)$spD], ['kart_id' => $DOM, 'miktar' => '300', 'kaynak_satir_id' => (string)$spD]], ['cari_id' => (string)$MUS2]);
ok('aynı kaynak satırı iki kez (400 + 300 > 600) → aşım', !$r['ok'] && ($r['kod'] ?? '') === 'kalan_asimi');
$r = $kay('irsaliye', [['kart_id' => $KASA, 'miktar' => '5', 'kaynak_satir_id' => (string)$spD]], ['cari_id' => (string)$MUS2]);
ok('kaynak satırdan kart değişmez', !$r['ok'] && isset($r['satir_hatalari'][0]['kart_id']));
$r = $kay('irsaliye', [['kart_id' => $DOM, 'miktar' => '10', 'kaynak_satir_id' => (string)$spD]], ['cari_id' => (string)$MUS]);
ok('farklı cari → red', !$r['ok'] && isset($r['alanlar']['cari_id']));
$r = $kay('satis_iade', [['kart_id' => $DOM, 'miktar' => '10', 'birim_fiyat' => '1', 'kaynak_satir_id' => (string)$spD]], ['cari_id' => (string)$MUS2, 'fatura_no' => 'Z1']);
ok('izinsiz geçiş (sipariş → satış iadesi) red', !$r['ok'] && isset($r['satir_hatalari'][0]['kaynak_satir_id']));
$r = $kay('siparis', [['kart_id' => $PAT, 'miktar' => '50', 'birim_fiyat' => '2']], ['cari_id' => (string)$MUS2]);
$SP2 = (int)$r['id']; $sp2P = (int)fatura_oku($db, $SP2)['satirlar'][0]['id'];
$r = $kay('irsaliye', [['kart_id' => $DOM, 'miktar' => '100', 'kaynak_satir_id' => (string)$spD], ['kart_id' => $PAT, 'miktar' => '50', 'kaynak_satir_id' => (string)$sp2P]], ['cari_id' => (string)$MUS2]);
$IR2 = (int)($r['id'] ?? 0);
ok('çok kaynaklı irsaliye (2 sipariş, aynı cari) izinli; sipariş 2 akış "tam"', $r['ok'] && fatura_oku($db, $SP2)['akis_durum'] === 'tam' && fatura_kalanlar($db, [$spD]) === [$spD => 500000], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $kay('siparis', [['kart_id' => $DOM, 'miktar' => '10', 'birim_fiyat' => '1']], ['cari_id' => (string)$YD, 'para_birimi' => 'USD']);
$SPU = (int)$r['id']; $spuS = (int)fatura_oku($db, $SPU)['satirlar'][0]['id'];
$r = $kay('satis', [['kart_id' => $DOM, 'miktar' => '10', 'birim_fiyat' => '1', 'kaynak_satir_id' => (string)$spuS]], ['cari_id' => (string)$YD]);
ok('fatura hedefinde kaynak para birimi (USD) ≠ hedef (TRY) → alan hatası para_birimi', !$r['ok'] && isset($r['alanlar']['para_birimi']), json_encode($r, JSON_UNESCAPED_UNICODE));
$irS = (int)fatura_oku($db, $IR1)['satirlar'][0]['id'];
$nf = say('stok_fisler'); $nh = say('stok_hareketler'); $b0 = $bak($DOM);
$r = $kay('satis', [['kart_id' => $DOM, 'miktar' => '400', 'birim_fiyat' => '11,00', 'kaynak_satir_id' => (string)$irS]], ['cari_id' => (string)$MUS2, 'stok_etkisi' => '1']);
$FIR = (int)($r['id'] ?? 0); $fi = $FIR ? fatura_ham($db, $FIR) : [];
ok('irsaliye → fatura (stok etkisi 1 olsa bile) stok ÜRETMEZ (çift sayım yok): stok fişi yok, defter aynı, satır stoklu=0; cari dekont var', $r['ok'] && $fi['stok_fis_id'] === null && say('stok_fisler') === $nf
    && say('stok_hareketler') === $nh && $bak($DOM) === $b0 && say('fatura_satirlar', 'belge_id = ? AND stoklu = 1', [$FIR]) === 0 && $fi['cari_hareket_id'] !== null, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('irsaliye akış "tam" (faturalandı); sipariş satırı faturalanan 400 (2 seviye), karşılanan 500', fatura_oku($db, $IR1)['akis_durum'] === 'tam' && ($so = fatura_oku($db, $SP1)['satirlar'][0])['faturalanan_milli'] === 400000
    && $so['kullanilan_milli'] === 500000 && $so['irsaliyelenen_milli'] === 500000 && $so['kalan_milli'] === 500000);
$r = $kay('satis', [['kart_id' => $DOM, 'miktar' => '200', 'birim_fiyat' => '11,00', 'kaynak_satir_id' => (string)$spD]], ['cari_id' => (string)$MUS2, 'stok_etkisi' => '1']);
$FSP = (int)($r['id'] ?? 0);
ok('sipariş → fatura (stok etkisi 1) stok ÜRETİR (−200); sipariş kalanı 300', $r['ok'] && fatura_ham($db, $FSP)['stok_fis_id'] !== null && $bak($DOM) === $b0 - 200000 && fatura_kalanlar($db, [$spD]) === [$spD => 300000]);
$fspS = (int)fatura_oku($db, $FSP)['satirlar'][0]['id'];
$r = $kay('satis_iade', [['kart_id' => $DOM, 'miktar' => '50', 'birim_fiyat' => '11,00', 'kaynak_satir_id' => (string)$fspS]], ['cari_id' => (string)$MUS2, 'fatura_no' => 'IADE-77', 'stok_etkisi' => '1']);
$IA1 = (int)($r['id'] ?? 0); $ia = $IA1 ? fatura_ham($db, $IA1) : [];
$ort = stok_maliyet_durumu($db, $DOM)['ort'];
ok('fatura → satış iadesi: alacak_dekont 555,50; stok GİRİŞ fiyatsız (değer ortalamadan), neden Diğer Giriş; fatura satırı kalan 150, akış kısmi', $r['ok'] && cari_hareket_oku($db, (int)$ia['cari_hareket_id'])['tur'] === 'alacak_dekont'
    && cari_kurus(cari_hareket_oku($db, (int)$ia['cari_hareket_id'])['tutar']) === 55550 && ($isf = stok_fis_ham($db, (int)$ia['stok_fis_id']))['tur'] === 'giris' && $isf['toplam_tutar'] === null && $isf['neden_ad'] === 'Diğer Giriş'
    && fatura_kalanlar($db, [$fspS]) === [$fspS => 150000] && fatura_oku($db, $FSP)['akis_durum'] === 'kismi', json_encode($r, JSON_UNESCAPED_UNICODE));
$aS = (int)fatura_oku($db, $A1)['satirlar'][0]['id'];
$b0 = $bak($PAT);
$r = $kay('alis_iade', [['kart_id' => $PAT, 'miktar' => '100', 'birim_fiyat' => '5,00', 'kaynak_satir_id' => (string)$aS]], ['cari_id' => (string)$TED, 'stok_etkisi' => '1']);
$AI1 = (int)($r['id'] ?? 0); $ai = $AI1 ? fatura_ham($db, $AI1) : [];
ok('alış iadesi: bizim seri (FTR), borc_dekont 505,00, stok çıkış −100 ortalamadan, neden Diğer Çıkış', $r['ok'] && str_starts_with((string)$r['fatura_no'], "FTR{$Y}") && cari_hareket_oku($db, (int)$ai['cari_hareket_id'])['tur'] === 'borc_dekont'
    && cari_kurus(cari_hareket_oku($db, (int)$ai['cari_hareket_id'])['tutar']) === 50500 && $bak($PAT) === $b0 - 100000 && stok_fis_ham($db, (int)$ai['stok_fis_id'])['neden_ad'] === 'Diğer Çıkış', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = fatura_siparis_kapat($db, $SP1, true, 'ab', 1);
ok('sipariş kapatma nedeni ≥3', !$r['ok'] && isset($r['alanlar']['neden']));
$au = audit_say('update');
$r = fatura_siparis_kapat($db, $SP1, true, 'Müşteri kalanı istemiyor', 1);
ok('sipariş kapatıldı: kapali=1, akış "kapali", audit update', $r['ok'] && (int)fatura_ham($db, $SP1)['kapali'] === 1 && fatura_oku($db, $SP1)['akis_durum'] === 'kapali' && audit_say('update') === $au + 1);
$r = $kay('irsaliye', [['kart_id' => $DOM, 'miktar' => '10', 'kaynak_satir_id' => (string)$spD]], ['cari_id' => (string)$MUS2]);
ok('kapalı siparişten dönüşüm reddedilir; aday listesinde yok', !$r['ok'] && str_contains((string)($r['satir_hatalari'][0]['kaynak_satir_id'] ?? ''), 'kapatılmış') && !in_array($SP1, array_column(fatura_kaynak_adaylari($db, 'irsaliye', $MUS2), 'id'), true));
$r = fatura_siparis_kapat($db, $SP1, false, '', 1);
ok('sipariş yeniden açılır (kapali=0, neden temizlenir)', $r['ok'] && (int)fatura_ham($db, $SP1)['kapali'] === 0 && fatura_ham($db, $SP1)['kapali_neden'] === null);
ok('sipariş kapatma yalnız siparişte', !fatura_siparis_kapat($db, $S1, true, 'Deneme neden', 1)['ok']);

echo "\n=== 14. İptal zinciri ===\n";
$r = fatura_iptal($db, $SP1, 'Sipariş iptal', 1);
ok('bağlı irsaliyesi/faturası olan sipariş → 409 bagli_belge (mesajda IRS-…)', !$r['ok'] && ($r['kod'] ?? '') === 'bagli_belge' && str_contains((string)$r['hata'], fatura_ham($db, $IR1)['belge_no']), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = fatura_iptal($db, $IR1, 'İrsaliye iptal', 1);
ok('faturalanmış irsaliye → 409 bagli_belge', !$r['ok'] && ($r['kod'] ?? '') === 'bagli_belge' && str_contains((string)$r['hata'], fatura_ham($db, $FIR)['belge_no']));
$r = fatura_iptal($db, $FIR, 'ab', 1);
ok('neden < 3 → red', !$r['ok'] && isset($r['alanlar']['neden']));
$ac = audit_say('cancel'); $chF = (int)fatura_ham($db, $FIR)['cari_hareket_id'];
$r = fatura_iptal($db, $FIR, 'Yanlış fiyat', 1);
ok('fatura iptal → ok; cari dekont iptal (cari_kaynakli_iptal); audit cancel', $r['ok'] && fatura_ham($db, $FIR)['durum'] === 'iptal' && cari_hareket_oku($db, $chF)['durum'] === 'iptal' && $r['cari_iptal'] === [$chF] && audit_say('cancel') === $ac + 1);
ok('irsaliye satırı kalanı geri geldi (400); irsaliye akış "yok"', fatura_kalanlar($db, [$irS]) === [$irS => 400000] && fatura_oku($db, $IR1)['akis_durum'] === 'yok');
$b0 = $bak($DOM); $nh = say('stok_hareketler');
$r = fatura_iptal($db, $IR1, 'Sevk yapılmadı', 1);
ok('irsaliye iptal → ters stok (+400), stok fişi iptal', $r['ok'] && $bak($DOM) === $b0 + 400000 && say('stok_hareketler') === $nh + 1 && stok_fis_ham($db, (int)fatura_ham($db, $IR1)['stok_fis_id'])['durum'] === 'iptal');
ok('sipariş satırı kalanı geri geldi (300 + 400 = 700)', fatura_kalanlar($db, [$spD]) === [$spD => 700000]);
$r = fatura_iptal($db, $IR1, 'Tekrar iptal', 1);
ok('zaten iptal → kod zaten_iptal', !$r['ok'] && ($r['kod'] ?? '') === 'zaten_iptal');
$r = fatura_iptal($db, $FSP, 'İadesi var', 1);
ok('iadesi olan fatura → bagli_belge (önce iade)', !$r['ok'] && ($r['kod'] ?? '') === 'bagli_belge');
$sf2 = (int)fatura_ham($db, $S2)['stok_fis_id']; $ch2 = (int)fatura_ham($db, $S2)['cari_hareket_id']; $b0 = $bak($DOM); $cb0 = cari_bakiye($db, $MUS)['TRY']['bakiye'];
$r = fatura_iptal($db, $S2, 'Müşteri iptal etti', 1);
ok('stoklu satış iptali: stok fişi iptal + ters (+100), cari iptal, bakiye 1.515,00 azaldı', $r['ok'] && $r['stok_iptal'] === $sf2 && stok_fis_ham($db, $sf2)['durum'] === 'iptal' && $bak($DOM) === $b0 + 100000
    && cari_hareket_oku($db, $ch2)['durum'] === 'iptal' && cari_bakiye($db, $MUS)['TRY']['bakiye'] === $cb0 - 151500 && str_starts_with((string)stok_fis_ham($db, $sf2)['iptal_neden'], 'Fatura iptali: '), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $kay('satis', [['kart_id' => $DOM, 'miktar' => '1', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS, 'tarih' => $bugun]);
ok('seride en ileri tarihe EŞİT tarih izinli (yalnız geriye tarih reddedilir)', $r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $kay('alis', $sat1, ['cari_id' => (string)$TED, 'fatura_no' => 'USD-1']);
ok('iptal edilmemiş belge numarası mükerrer kalır', !$r['ok'] && ($r['kod'] ?? '') === 'mukerrer');
$r = fatura_iptal($db, $A1, 'Hatalı giriş', 1);
ok('iadesi olan alış iptal edilemez (bagli_belge)', !$r['ok'] && ($r['kod'] ?? '') === 'bagli_belge');
$r = fatura_iptal($db, $AI1, 'İade iptal', 1);
$r2 = fatura_iptal($db, $A1, 'Hatalı giriş', 1);
$r3 = $kay('alis', $sat1, ['cari_id' => (string)$TED, 'fatura_no' => 'ABC2026000000077']);
ok('alttan yukarı iptal (iade → alış) olur; iptal edilmiş belgenin numarası yeniden girilebilir', $r['ok'] && $r2['ok'] && $r3['ok'], json_encode([$r, $r2, $r3], JSON_UNESCAPED_UNICODE));
ok('fatura_iptal bilinmeyen belge', !fatura_iptal($db, 999999, 'Yok böyle', 1)['ok']);

echo "\n=== 15. Stok ekranından kaynaklı fiş iptali (Y1) ===\n";
$sfM = (int)fatura_ham($db, $M1)['stok_fis_id'];
$r = stok_fis_iptal($db, $sfM, 'Stok ekranından', 1);
ok('fatura stok fişi stok_fis_iptal (parametresiz) → 409 kaynakli, fiş geçerli', !$r['ok'] && ($r['kod'] ?? '') === 'kaynakli' && stok_fis_ham($db, $sfM)['durum'] === 'gecerli');
$r = cari_hareket_iptal($db, (int)fatura_ham($db, $M1)['cari_hareket_id'], 'Cariden iptal', 1);
ok('fatura dekontu cari ekranından iptal edilemez (kaynakli)', !$r['ok'] && ($r['kod'] ?? '') === 'kaynakli');

echo "\n=== 16. Depo ===\n";
$r = $kay('satis', $sat1, ['cari_id' => (string)$MUS, 'depo' => 'Şube Depo']);
$SB = (int)($r['id'] ?? 0);
ok('Şube Depo belgesi (kısıtsız kullanıcı) kaydedilir', $r['ok']);
$DEPOLAR = ['Merkez Depo'];
ok('aktif depo Merkez: Şube belgesi listede YOK, Merkez belgesi var', !in_array($SB, array_map('intval', array_column(fatura_listesi($db, 'satis')['satirlar'], 'id')), true) && in_array($S1, array_map('intval', array_column(fatura_listesi($db, 'satis')['satirlar'], 'id')), true));
$r = fatura_iptal($db, $SB, 'Başka depo', 1);
ok('başka depodaki belge iptal edilemez ("görünmüyor")', !$r['ok'] && str_contains((string)$r['hata'], 'görünmüyor'));
$d = $dg('satis', $sat1, ['cari_id' => (string)$MUS, 'depo' => 'Şube Depo']);
ok('aktif depo dışı depoya belge girilemez', !$d['ok'] && isset($d['alanlar']['depo']));
ok('boş depo herkese görünür (fatura_gorunur)', fatura_gorunur(['depo' => '']) && !fatura_gorunur(['depo' => 'Şube Depo']));
$DEPOLAR = null;

echo "\n=== 17. Liste / okuyucular ===\n";
$L = fatura_listesi($db, 'satis');
ok('liste (satış sınıfı): satis + ihracat + iade, en yeni önce; toplamlar para birimi başına (TRY + USD ayrı)', $L['toplam_satir'] >= 8 && !array_diff(array_unique(array_column($L['satirlar'], 'tur')), fatura_sinif_turleri('satis'))
    && isset($L['toplamlar_pb']['TRY'], $L['toplamlar_pb']['USD']) && $L['toplamlar_pb']['USD']['genel'] === 230000 + 0, json_encode(array_keys($L['toplamlar_pb'])));
ok('süzgeç tur=satis_ihracat: 1 belge', fatura_listesi($db, 'satis', ['tur' => 'satis_ihracat'])['toplam_satir'] === 1);
ok('süzgeç durum=iptal: iptal belgeler', ($Li = fatura_listesi($db, 'satis', ['durum' => 'iptal'])) && $Li['toplam_satir'] >= 2 && !array_filter($Li['satirlar'], fn($x) => $x['durum'] !== 'iptal'));
ok('süzgeç ara (fatura no / ürün adı / cari)', fatura_listesi($db, 'satis', ['ara' => "FTR{$Y}000000001"])['toplam_satir'] === 1 && fatura_listesi($db, 'alis', ['ara' => 'Patates'])['toplam_satir'] >= 3
    && fatura_listesi($db, 'satis', ['ara' => 'Yurtdışı'])['toplam_satir'] === 1);
ok('süzgeç cari_id + dönem (bas/bit)', fatura_listesi($db, 'satis', ['cari_id' => $YD])['toplam_satir'] === 1 && fatura_listesi($db, 'satis', ['donem' => 'ozel', 'bas' => "$Y-11-10", 'bit' => "$Y-11-10"])['toplam_satir'] >= 1
    && fatura_listesi($db, 'satis', ['donem' => 'ozel', 'bas' => '2001-01-01', 'bit' => '2001-01-02'])['toplam_satir'] === 0);
ok('sipariş süzgeci sd: acik / kismi / tamam / kapali', in_array($SP2, array_map('intval', array_column(fatura_listesi($db, 'siparis', ['sd' => 'tamam'])['satirlar'], 'id')), true)
    && in_array($SP1, array_map('intval', array_column(fatura_listesi($db, 'siparis', ['sd' => 'kismi'])['satirlar'], 'id')), true)
    && in_array($SPU, array_map('intval', array_column(fatura_listesi($db, 'siparis', ['sd' => 'acik'])['satirlar'], 'id')), true) && fatura_listesi($db, 'siparis', ['sd' => 'kapali'])['toplam_satir'] === 0);
ok('irsaliye süzgeci sd=bekleyen: iptal irsaliye hariç, IR2 bekliyor', in_array($IR2, array_map('intval', array_column(fatura_listesi($db, 'irsaliye', ['sd' => 'bekleyen'])['satirlar'], 'id')), true)
    && !in_array($IR1, array_map('intval', array_column(fatura_listesi($db, 'irsaliye', ['sd' => 'bekleyen'])['satirlar'], 'id')), true));
$o = fatura_oku($db, $FIR);
ok('fatura_oku: satırlar + kaynaklar (IRS) + stok fişi yok + cari dekont (iptal) + bilgi', $o !== null && count($o['satirlar']) === 1 && $o['kaynaklar'][0]['id'] == $IR1 && $o['stok_fis'] === null && $o['cari_hareket']['durum'] === 'iptal' && $o['bilgi']['etiket'] === 'Satış Faturası');
ok('fatura_oku: siparişin hedefleri (irsaliyeler + fatura, iptaller dahil durumla)', count(fatura_oku($db, $SP1)['hedefler']) >= 3);
$k = fatura_kopya(fatura_oku($db, $S1));
ok('fatura_kopya: tarih bugün, numaralar/kaynak düşer, satırlar ekran biçiminde (1.250 · 12,3456 · 2,5)', $k['tarih'] === $bugun && !isset($k['belge_no']) && !isset($k['fatura_no']) && $k['satirlar'][0]['miktar'] === '1.250'
    && $k['satirlar'][0]['birim_fiyat'] === '12,3456' && $k['satirlar'][0]['iskonto'] === '2,5' && !isset($k['satirlar'][0]['kaynak_satir_id']) && $k['kopya_no'] === fatura_ham($db, $S1)['belge_no'] && $k['odeme_sekli'] === 'Havale/EFT');
ok('fatura_tanimlar_paket: 8 tür + ödeme şekilleri (cari tanımlarından)', count(array_intersect(array_keys(fatura_tanimlar_paket($db)), array_keys(fatura_tanim_turleri()))) === 8 && in_array('Havale/EFT', fatura_tanimlar_paket($db)['odeme_sekli'], true));
ok('fatura_cari_secenekleri: aktif cariler (pasif hariç) + vade_gun', ($cs = fatura_cari_secenekleri($db)) && !in_array($PCARI, array_column($cs, 'id'), true) && array_column($cs, 'vade_gun', 'id')[$MUS] === 30);

echo "\n=== 18. Tanımlar ===\n";
$at = fn(array $g, ?int $id = null) => fatura_tanim_kaydet($db, $g, 1, $id);
ok('seri kodu ^[A-Z]{3}$ (küçük harf büyütülür; 4 harf red); grup zorunlu', !$at(['tur' => 'seri', 'ad' => 'Yeni seri', 'kod' => 'ABCD', 'belge_turu' => 'fatura'])['ok']
    && !$at(['tur' => 'seri', 'ad' => 'Yeni seri', 'kod' => 'ABC'])['ok'] && ($r = $at(['tur' => 'seri', 'ad' => 'Yeni seri', 'kod' => 'abc', 'belge_turu' => 'fatura']))['ok'] && fatura_tanim_bul($db, (int)$r['id'])['kod'] === 'ABC');
$ABC = (int)$r['id'];
ok('tevkifat pay 1–9, payda 10', !$at(['tur' => 'tevkifat', 'ad' => 'T0', 'deger1' => '0'])['ok'] && !$at(['tur' => 'tevkifat', 'ad' => 'T5', 'deger1' => '3', 'deger2' => '5'])['ok']
    && ($r = $at(['tur' => 'tevkifat', 'ad' => 'Tevkifat 3/10', 'deger1' => '3', 'kod' => '601']))['ok'] && (int)fatura_tanim_bul($db, (int)$r['id'])['deger2'] === 10);
ok('stopaj bp: "2,5" → 250; "100" red', ($r = $at(['tur' => 'stopaj', 'ad' => 'Stopaj %2,5', 'deger1' => '2,5']))['ok'] && (int)fatura_tanim_bul($db, (int)$r['id'])['deger1'] === 250 && !$at(['tur' => 'stopaj', 'ad' => 'X', 'deger1' => '100'])['ok']);
ok('KDV 0–100; aynı oran ikinci kez red; ad TR-duyarsız tekil', !$at(['tur' => 'kdv', 'ad' => 'KDV %101', 'deger1' => '101'])['ok'] && !$at(['tur' => 'kdv', 'ad' => 'Bir daha bir', 'deger1' => '1'])['ok']
    && !$at(['tur' => 'kdv', 'ad' => 'kdv %1', 'deger1' => '8'])['ok']);
$r = $at(['tur' => 'kdv', 'ad' => 'KDV %8', 'deger1' => '8', 'varsayilan' => '1']);
ok('varsayılan tekilliği: yeni varsayılan KDV %8 → %1 varsayılan değil', $r['ok'] && say('fatura_tanimlar', "tur = 'kdv' AND varsayilan = 1") === 1 && (int)fatura_tanim_bul($db, (int)$r['id'])['varsayilan'] === 1 && fatura_kdv_oranlari($db)['varsayilan'] === 8);
$K8 = (int)$r['id'];
ok('seri varsayılanı grup içinde tekil (ABC varsayılan → FTR değil; irsaliye/müstahsil grubu etkilenmez)', $at(['ad' => 'Yeni seri', 'kod' => 'ABC', 'belge_turu' => 'fatura', 'varsayilan' => '1'], $ABC)['ok']
    && say('fatura_tanimlar', "tur = 'seri' AND varsayilan = 1") === 3 && fatura_seri_sec($db, 'fatura', 0)['kod'] === 'ABC' && fatura_seri_sec($db, 'irsaliye', 0)['kod'] === 'IRS');
$FTR = fatura_t_tanim($db, 'seri', 'FTR — Fatura serisi');
$r = fatura_tanim_sil($db, $FTR, 1);
ok('kullanılan seri silinemez → kod kullanimda', !$r['ok'] && ($r['kod'] ?? '') === 'kullanimda' && fatura_tanim_bul($db, $FTR) !== null);
$r = $at(['ad' => 'FTR — Fatura serisi', 'kod' => 'FTX', 'belge_turu' => 'fatura'], $FTR);
ok('kullanılan serinin kodu değiştirilemez → kullanimda; adı değişebilir', !$r['ok'] && ($r['kod'] ?? '') === 'kullanimda' && $at(['ad' => 'FTR — Ana fatura serisi', 'kod' => 'FTR', 'belge_turu' => 'fatura'], $FTR)['ok']);
ok('fatura_seri_durum: FTR son sıra ≥ 5, sonraki no önizlemesi', ($sd = fatura_seri_durum($db, fatura_tanim_bul($db, $FTR), (int)$Y)) && $sd['son_sira'] >= 5 && $sd['sonraki'] === fatura_seri_no('FTR', (int)$Y, $sd['son_sira'] + 1));
$ad0 = audit_say('delete');
$r = fatura_tanim_sil($db, $TEV7, 1);
ok('kullanılan tevkifat tanımı silinir; eski belge snapshot\'ı bozulmaz; audit delete', $r['ok'] && fatura_tanim_bul($db, $TEV7) === null && fatura_ham($db, $TEVB)['tevkifat_ad'] === 'Tevkifat 7/10' && audit_say('delete') === $ad0 + 1);
$r = fatura_tanim_durum($db, $ABC, false, 1);
ok('tanım pasifleştirilir; pasif seri seçilemez (varsayılan FTR\'ye düşmez — ilk aktif)', $r['ok'] && (int)fatura_tanim_bul($db, $ABC)['aktif'] === 0 && fatura_seri_sec($db, 'fatura', $ABC) === null && fatura_seri_sec($db, 'fatura', 0)['kod'] === 'FTR');
ok('sablon: metin zorunlu, belge_turu tür kodu ya da boş', !$at(['tur' => 'sablon', 'ad' => 'Boş'])['ok'] && !$at(['tur' => 'sablon', 'ad' => 'X', 'metin' => 'y', 'belge_turu' => 'yok'])['ok'] && $at(['tur' => 'sablon', 'ad' => 'Genel not', 'metin' => 'Teşekkürler.'])['ok']);
ok('istisna kodu zorunlu; teslim kodu 3 harf', !$at(['tur' => 'istisna', 'ad' => 'Kodsuz'])['ok'] && !$at(['tur' => 'teslim', 'ad' => 'Uzun', 'kod' => 'ABCD'])['ok']);
ok('geçersiz tanım türü', !$at(['tur' => 'yok', 'ad' => 'x'])['ok'] && fatura_tanim_listesi($db, 'yok') === []);
fatura_tanim_kaydet($db, ['ad' => 'KDV %1', 'deger1' => '1', 'varsayilan' => '1'], 1, $kdv('KDV %1'));
fatura_tanim_durum($db, $K8, false, 1);

echo "\n=== 19. Uç: gerçek fatura_kaydet.php ===\n";
$r = fatura_t_istek_cagri(['dosya' => 'fatura_kaydet.php', 'get' => ['islem' => 'belge_kaydet']]);
ok('GET → 405 + Allow: POST', $r['kod'] === 405 && baslik($r, 'Allow') === 'POST');
$n0 = say('fatura_belgeler');
$r = uc(['islem' => 'belge_kaydet', 'csrf' => 'yanlis'] + fatura_t_istek('satis', $sat1, ['cari_id' => (string)$MUS]));
ok('yanlış csrf → 403, kayıt yok', $r['kod'] === 403 && say('fatura_belgeler') === $n0, $r['govde']);
$r = uc(['islem' => 'belge_kaydet'] + fatura_t_istek('satis', $sat1, ['cari_id' => (string)$MUS]), true, ['admin' => false]);
ok('yönetici değil → 403 JSON, kayıt yok', $r['kod'] === 403 && js($r)['ok'] === false && say('fatura_belgeler') === $n0, $r['govde']);
$r = uc(['islem' => 'belge_kaydet'] + fatura_t_istek('satis', [['kart_id' => $DOM, 'miktar' => '12,5', 'birim_fiyat' => '1']], ['cari_id' => '']));
$j = js($r);
ok('JSON 422: alanlar.cari_id + satir_hatalari[0].miktar', $r['kod'] === 422 && $j['ok'] === false && isset($j['alanlar']['cari_id']) && isset($j['satir_hatalari']['0']['miktar']), $r['govde']);
$r = uc(['islem' => 'belge_kaydet', 'istek_anahtari' => 'uc-1', 'geri' => 'satis_faturalari.php?yeni=1'] + fatura_t_istek('satis', $sat1, ['cari_id' => (string)$MUS]));
$j = js($r); $UC1 = (int)($j['id'] ?? 0);
ok('belge_kaydet JSON 200: id, belge_no STF-, fatura_no, cari_belge_no, yonlendir satis_faturalari.php?id=<id>', $r['kod'] === 200 && $j['ok'] === true && str_starts_with((string)$j['belge_no'], 'STF-') && str_starts_with((string)$j['fatura_no'], "FTR{$Y}")
    && str_starts_with((string)$j['cari_belge_no'], 'BDK-') && $j['yonlendir'] === 'satis_faturalari.php?id=' . $UC1 && say('fatura_belgeler') === $n0 + 1, $r['govde']);
$r = uc(['islem' => 'belge_kaydet', 'istek_anahtari' => 'uc-1'] + fatura_t_istek('satis', $sat1, ['cari_id' => (string)$MUS]));
ok('çift gönderim: tekrar=true, aynı id', js($r)['ok'] === true && js($r)['tekrar'] === true && (int)js($r)['id'] === $UC1 && say('fatura_belgeler') === $n0 + 1);
$r = uc(['islem' => 'belge_kaydet', 'istek_anahtari' => 'uc-eksi'] + fatura_t_istek('satis', [['kart_id' => $PAT, 'miktar' => '99.999', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS, 'stok_etkisi' => '1']));
ok('409 yetersiz_stok + eksikler', $r['kod'] === 409 && js($r)['kod'] === 'yetersiz_stok' && !empty(js($r)['eksikler']), $r['govde']);
$r = uc(['islem' => 'belge_kaydet', 'istek_anahtari' => 'uc-eksi', 'eksiye_izin' => '1'] + fatura_t_istek('satis', [['kart_id' => $PAT, 'miktar' => '99.999', 'birim_fiyat' => '1']], ['cari_id' => (string)$MUS, 'stok_etkisi' => '1']));
ok('"Yine de kaydet" (aynı anahtar + eksiye_izin=1): 200 + uyari + stok_fis_no', $r['kod'] === 200 && js($r)['ok'] === true && str_contains((string)js($r)['uyari'], 'Eksiye') && str_starts_with((string)js($r)['stok_fis_no'], 'CKS-'), $r['govde']);
$r = uc(['islem' => 'belge_kaydet'] + fatura_t_istek('alis', $sat1, ['cari_id' => (string)$TED, 'fatura_no' => 'ABC2026000000077']));
ok('409 mukerrer', $r['kod'] === 409 && js($r)['kod'] === 'mukerrer', $r['govde']);
$sp3 = $kay('siparis', [['kart_id' => $DOM, 'miktar' => '10']], ['cari_id' => (string)$MUS]);
$sp3S = (int)fatura_oku($db, (int)$sp3['id'])['satirlar'][0]['id'];
$r = uc(['islem' => 'belge_kaydet'] + fatura_t_istek('irsaliye', [['kart_id' => $DOM, 'miktar' => '11', 'kaynak_satir_id' => (string)$sp3S]], ['cari_id' => (string)$MUS]));
ok('409 kalan_asimi', $r['kod'] === 409 && js($r)['kod'] === 'kalan_asimi', $r['govde']);
$ir3 = $kay('irsaliye', [['kart_id' => $DOM, 'miktar' => '10', 'kaynak_satir_id' => (string)$sp3S]], ['cari_id' => (string)$MUS]);
$r = uc(['islem' => 'belge_iptal', 'id' => (string)$sp3['id'], 'neden' => 'Bağlı belge var']);
ok('belge_iptal 409 bagli_belge', $ir3['ok'] && $r['kod'] === 409 && js($r)['kod'] === 'bagli_belge', $r['govde']);
$r = uc(['islem' => 'belge_iptal', 'id' => (string)$UC1, 'neden' => 'x']);
ok('belge_iptal kısa neden → 422 alanlar.neden', $r['kod'] === 422 && isset(js($r)['alanlar']['neden']));
$r = uc(['islem' => 'belge_iptal', 'id' => (string)$UC1, 'neden' => 'Uçtan iptal', 'geri' => 'satis_faturalari.php?id=' . $UC1]);
ok('belge_iptal → ok, yonlendir geri, belge iptal', js($r)['ok'] === true && js($r)['yonlendir'] === 'satis_faturalari.php?id=' . $UC1 && fatura_ham($db, $UC1)['durum'] === 'iptal', $r['govde']);
$r = uc(['islem' => 'belge_iptal', 'id' => (string)$UC1, 'neden' => 'Yine iptal']);
ok('ikinci iptal → 422 zaten_iptal', $r['kod'] === 422 && js($r)['kod'] === 'zaten_iptal');
$r = uc(['islem' => 'belge_iptal', 'id' => (string)$sp3['id'], 'neden' => 'Yetkisiz'], true, ['admin' => false]);
ok('yönetici değil: belge_iptal 403', $r['kod'] === 403 && fatura_ham($db, (int)$sp3['id'])['durum'] === 'gecerli');
$r = uc(['islem' => 'siparis_kapat', 'id' => (string)$sp3['id'], 'kapali' => '1', 'neden' => 'Uçtan kapat']);
ok('siparis_kapat → ok, kapali=1', js($r)['ok'] === true && (int)fatura_ham($db, (int)$sp3['id'])['kapali'] === 1, $r['govde']);
$r = uc(['islem' => 'siparis_kapat', 'id' => (string)$sp3['id'], 'kapali' => '0']);
ok('siparis_kapat kapali=0 → açılır', js($r)['ok'] === true && (int)fatura_ham($db, (int)$sp3['id'])['kapali'] === 0);
$r = uc(['islem' => 'tanim_kaydet', 'tur' => 'teslim', 'ad' => 'DAT (eski)', 'kod' => 'DAT', 'geri' => 'fatura_tanimlari.php?sekme=teslim']);
$TD = (int)(js($r)['id'] ?? 0);
ok('tanim_kaydet → ok + id, yonlendir fatura_tanimlari.php?sekme=teslim', js($r)['ok'] === true && $TD > 0 && js($r)['yonlendir'] === 'fatura_tanimlari.php?sekme=teslim' && fatura_tanim_bul($db, $TD)['kod'] === 'DAT', $r['govde']);
$r = uc(['islem' => 'tanim_kaydet', 'tur' => 'teslim', 'ad' => 'Kötü', 'kod' => 'X']);
ok('tanim_kaydet hatalı → 422 alanlar.kod', $r['kod'] === 422 && isset(js($r)['alanlar']['kod']));
$r = uc(['islem' => 'tanim_durum', 'id' => (string)$TD]);
ok('tanim_durum (aktif gelmedi) → pasif', js($r)['ok'] === true && (int)fatura_tanim_bul($db, $TD)['aktif'] === 0);
$r = uc(['islem' => 'tanim_sil', 'id' => (string)$TD]);
ok('tanim_sil → ok', js($r)['ok'] === true && fatura_tanim_bul($db, $TD) === null);
$r = uc(['islem' => 'tanim_sil', 'id' => (string)$FTR]);
ok('tanim_sil kullanılan seri → 409 kullanimda', $r['kod'] === 409 && js($r)['kod'] === 'kullanimda');
$r = uc(['islem' => 'tanim_kaydet', 'tur' => 'kdv', 'ad' => 'KDV %5', 'deger1' => '5'], true, ['admin' => false]);
ok('yönetici değil: tanim_kaydet 403', $r['kod'] === 403 && fatura_t_tanim($db, 'kdv', 'KDV %5') === 0);
$r = uc(['islem' => 'bilinmeyen']);
ok('bilinmeyen işlem → 422', $r['kod'] === 422 && js($r)['hata'] === 'Bilinmeyen işlem.');
$r = uc(['islem' => 'belge_kaydet', 'geri' => 'satis_faturalari.php?yeni=1'] + fatura_t_istek('satis', $sat1, ['cari_id' => (string)$MUS]), false);
ok('klasik form: 303 → satis_faturalari.php?id=<yeni> + flash success (belge no)', $r['kod'] === 303 && preg_match('/^satis_faturalari\.php\?id=\d+$/', (string)baslik($r, 'Location')) === 1 && ($r['flash']['type'] ?? '') === 'success'
    && str_contains(json_encode($r['flash'], JSON_UNESCAPED_UNICODE), 'STF-'), json_encode([$r['kod'], $r['basliklar'], $r['flash']], JSON_UNESCAPED_UNICODE));
$r = uc(['islem' => 'belge_kaydet', 'geri' => 'sevk_irsaliyeleri.php?yeni=1'] + fatura_t_istek('irsaliye', [['kart_id' => $PAT, 'miktar' => '99.999']], ['cari_id' => (string)$MUS]), false);
ok('klasik form yetersiz stok: 303 geri + flash error (mevcut/istenen)', $r['kod'] === 303 && baslik($r, 'Location') === 'sevk_irsaliyeleri.php?yeni=1' && ($r['flash']['type'] ?? '') === 'error' && str_contains(json_encode($r['flash'], JSON_UNESCAPED_UNICODE), 'mevcut'));
$r = uc(['islem' => 'belge_iptal', 'id' => '999999', 'neden' => 'Yok', 'geri' => 'https://kotu.example/x'], false);
ok('açık yönlendirme reddi: dış `geri` → satis_faturalari.php', $r['kod'] === 303 && baslik($r, 'Location') === 'satis_faturalari.php');
$r = uc(['islem' => 'belge_iptal', 'id' => '999999', 'neden' => 'Yok', 'geri' => "alis_faturalari.php\r\nSet-Cookie: x=1"], false);
ok('başlık enjeksiyonu: Location yalnız güvenli varsayılan', baslik($r, 'Location') === 'satis_faturalari.php');
$r = uc(['islem' => 'belge_kaydet'] + fatura_t_istek('alis', [['kart_id' => $PAT, 'miktar' => '1', 'birim_fiyat' => '1']], ['cari_id' => (string)$TED, 'fatura_no' => 'UC-AL-1']));
ok('alış yonlendir alis_faturalari.php?id=', js($r)['ok'] === true && js($r)['yonlendir'] === 'alis_faturalari.php?id=' . (int)js($r)['id']);

echo "\n=== 20. Audit / cari özeti / son fiyat ===\n";
ok('audit eylemleri (fatura): yalnız create|cancel|update|delete|migrate', !$db->query("SELECT COUNT(*) FROM audit_log WHERE module = 'fatura' AND action NOT IN ('create','cancel','update','delete','migrate')")->fetchColumn()
    && audit_say('create') >= 25 && audit_say('cancel') === 6 && audit_say('update') >= 4 && audit_say('delete') >= 2, json_encode($db->query("SELECT action, COUNT(*) n FROM audit_log WHERE module = 'fatura' GROUP BY action")->fetchAll()));
ok('audit create belge kaydı: tur, belge_no, cari_id, stok_fis_id, cari_hareket_id', say('audit_log', "module = 'fatura' AND action = 'create' AND record_id = ? AND new_values LIKE '%\"tur\":\"satis\"%' AND new_values LIKE '%stok_fis_id%' AND new_values LIKE '%cari_hareket_id%'", [$S2]) === 1);
ok('audit cancel: before gecerli → after iptal + neden', say('audit_log', "module = 'fatura' AND action = 'cancel' AND record_id = ? AND old_values LIKE '%gecerli%' AND new_values LIKE '%Müşteri iptal etti%'", [$S2]) === 1);
ok('iç çağrılar kendi audit\'ini yazar (stok create kaynak fatura:, cari cancel kaynak_modul fatura)', say('audit_log', "module = 'stok' AND action = 'create' AND new_values LIKE '%\"kaynak\":\"fatura:%'") >= 5
    && say('audit_log', "module = 'cari' AND action = 'cancel' AND new_values LIKE '%\"kaynak_modul\":\"fatura\"%'") >= 3);
$oz = fatura_cari_ozet($db, $MUS2, 'satis');
ok('cari özeti: bakiye (TRY) + son fiyat Domates 11,00 (son GEÇERLİ satış sınıfı satırı, iptal hariç)', isset($oz['bakiyeler']['TRY']) && ($oz['son_fiyatlar'][$DOM]['f4'] ?? 0) === 110000 && ($oz['son_fiyatlar'][$DOM]['pb'] ?? '') === 'TRY', json_encode($oz, JSON_UNESCAPED_UNICODE));
$r1 = $kay('satis', [['kart_id' => $KASA, 'miktar' => '1', 'birim_fiyat' => '3,00']], ['cari_id' => (string)$MUS2]);
$r2 = $kay('satis', [['kart_id' => $KASA, 'miktar' => '1', 'birim_fiyat' => '3,50']], ['cari_id' => (string)$MUS2]);
ok('son fiyat = en yeni geçerli (3,50)', fatura_cari_ozet($db, $MUS2, 'satis')['son_fiyatlar'][$KASA]['f4'] === 35000 && fatura_cari_ozet($db, $MUS2, 'satis')['son_fiyatlar'][$KASA]['belge_no'] === $r2['belge_no']);
fatura_iptal($db, (int)$r2['id'], 'Fiyat hatalı', 1);
ok('en yeni iptal edilince önceki geçerli fiyat (3,00)', fatura_cari_ozet($db, $MUS2, 'satis')['son_fiyatlar'][$KASA]['f4'] === 30000);
ok('alış sınıfı son fiyatları ayrı (satış fiyatı karışmaz)', !isset(fatura_cari_ozet($db, $MUS2, 'alis')['son_fiyatlar'][$KASA]) && isset(fatura_cari_ozet($db, $TED, 'alis')['son_fiyatlar'][$PAT]));
ok('acik_kaynak_say: satış için açık sipariş/irsaliye sayısı', fatura_cari_ozet($db, $MUS2, 'satis')['acik_kaynak_say'] === count(fatura_kaynak_adaylari($db, 'satis', $MUS2)) && fatura_cari_ozet($db, $MUS2, 'satis')['acik_kaynak_say'] >= 2);

echo "\n=== 21. Değişmezlik (çalışma sonrası) ===\n";
ok('her belge numaralı (belge_no boş kalmadı)', say('fatura_belgeler', "belge_no = ''") === 0);
ok('her fatura türü belgede TEK geçerli/iptal cari satırı (kaynak fatura:id)', !$db->query("SELECT b.id FROM fatura_belgeler b WHERE b.tur NOT IN ('irsaliye','siparis') AND b.cari_hareket_id IS NULL")->fetchAll()
    && say('cari_hareketler', "kaynak_modul = 'fatura'") === say('fatura_belgeler', "tur NOT IN ('irsaliye','siparis')"));
ok('stok fişi bağlı her belge: fiş kaynak fatura:<belge id>, cari_id NULL', !array_filter($db->query('SELECT id, stok_fis_id FROM fatura_belgeler WHERE stok_fis_id IS NOT NULL')->fetchAll(), function ($b) use ($db) {
    $f = stok_fis_ham($db, (int)$b['stok_fis_id']); return $f === null || $f['kaynak_modul'] !== 'fatura' || (int)$f['kaynak_id'] !== (int)$b['id'] || $f['cari_id'] !== null; }));
ok('iptal belgenin stok fişi ve dekontu da iptal', !array_filter($db->query("SELECT * FROM fatura_belgeler WHERE durum = 'iptal'")->fetchAll(), function ($b) use ($db) {
    return ($b['stok_fis_id'] !== null && stok_fis_ham($db, (int)$b['stok_fis_id'])['durum'] !== 'iptal') || ($b['cari_hareket_id'] !== null && cari_hareket_oku($db, (int)$b['cari_hareket_id'])['durum'] !== 'iptal'); }));
ok('seri numaraları tekil (seri, yıl, sıra)', !$db->query('SELECT seri_id FROM fatura_belgeler WHERE seri_id IS NOT NULL GROUP BY seri_id, seri_yil, seri_sira HAVING COUNT(*) > 1')->fetchAll());

siparis_test_bitir();
