<?php
// =========================================================
// scripts/hks_kisi_havuzu_smoke.php — Hal Kayıt Kişi Havuzu (karşı taraf)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: bellek içi SQLite +
// halkayit/kisi_havuzu_lib.php'nin GERÇEK fonksiyonları (api.php değil — o
// oturum/JSON/exit yan etkileri taşır). Tablo DDL'i de lib'in kendi
// hks_kisi_tablo_hazirla()'sından gelir; şema testte ayrı yazılmaz.
//
// Kapsam: doğrulama (TC algoritması, VKN, cep, gelecek doğum, ad kuralı:
// VKN adsız OK / TC adsız 400, sıfat biçimi + katalog), yeni/güncelle/
// 409 çakışma/404, sil, gönderim upsert'i (boş alan eskiyi silmez, sayaç
// artar, geçersiz TC yazılmaz), tek seferlik içe aktarma (idempotent, bayrak,
// eski kv silinmez), liste sırası, maskeleme/audit değerleri, sıfat upsert'i
// (son kullanılan kazanır; Üreticiden Sevk Alım'da yazılmaz), v282 tablosundan
// sifat_id yükseltmesi (idempotent).
//
//   php scripts/hks_kisi_havuzu_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../halkayit/kisi_havuzu_lib.php';

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-66s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

// Algoritmayı geçen TC üret (9 haneli gövdeden) — örneklerde gerçek kişi yok.
function tc_uret(string $govde9): string {
    $d = array_map('intval', str_split($govde9));
    $tek = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $cift = $d[1] + $d[3] + $d[5] + $d[7];
    $d10 = ((($tek * 7) - $cift) % 10 + 10) % 10;
    $d11 = (array_sum($d) + $d10) % 10;
    return $govde9 . $d10 . $d11;
}

function yeni_db(): PDO {
    $db = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
    hks_kisi_tablo_hazirla($db);
    hks_kisi_tablo_hazirla($db);   // idempotent olmalı
    return $db;
}

$TC1 = tc_uret('123456789');
$TC2 = tc_uret('234567891');
$TC3 = tc_uret('345678912');
$TC_BOZUK = substr($TC1, 0, 10) . (string)(((int)$TC1[10] + 1) % 10);
$VKN = '1234567890';
$BUGUN = '2026-09-30';

echo "── Doğrulama ──\n";
ok('üretilen TC algoritmayı geçer', hks_tc_algoritma_gecerli($TC1) && hks_tc_algoritma_gecerli($TC2));
[$k, $h] = hks_kisi_dogrula(['tc' => " {$TC1} ", 'ad' => '  Örnek   Üretici ', 'cep' => '0 (532) 000 00 00', 'dogum' => '1980-01-15'], $BUGUN);
ok('geçerli TC kabul', $h === null && $k['tc'] === $TC1, (string)$h);
ok('ad trim + tek boşluk', ($k['ad'] ?? '') === 'Örnek Üretici');
ok('cep rakama indirgenir', ($k['cep'] ?? '') === '05320000000');
ok('doğum korunur', ($k['dogum'] ?? '') === '1980-01-15');
[$k, $h] = hks_kisi_dogrula(['tc' => $VKN, 'ad' => 'Örnek Ltd', 'cep' => '', 'dogum' => ''], $BUGUN);
ok('10 haneli VKN (algoritmasız) kabul', $h === null && $k['tc'] === $VKN && $k['cep'] === '' && $k['dogum'] === '');
[, $h] = hks_kisi_dogrula(['tc' => $TC_BOZUK, 'ad' => 'X'], $BUGUN);
ok('algoritmayı geçmeyen TC → hata', $h !== null);
[, $h] = hks_kisi_dogrula(['tc' => '123456789', 'ad' => 'X'], $BUGUN);
ok('9 hane → hata', $h !== null);
[, $h] = hks_kisi_dogrula(['tc' => '', 'ad' => 'X'], $BUGUN);
ok('boş TC → hata', $h !== null);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => '   '], $BUGUN);
ok('TC + boş ad → hata', $h !== null);
[$k, $h] = hks_kisi_dogrula(['tc' => $VKN, 'ad' => '   '], $BUGUN);
ok('VKN + boş ad → kabul (ad yalnız takip için)', $h === null && ($k['ad'] ?? 'x') === '', (string)$h);

echo "\n── Sıfat doğrulama ──\n";
foreach ([null, '', 0, '0'] as $bos) {
    [$k, $h] = hks_kisi_dogrula(['tc' => $VKN, 'sifatId' => $bos], $BUGUN);
    ok('sifatId ' . var_export($bos, true) . ' → null', $h === null && $k['sifatId'] === null, (string)$h);
}
[$k, $h] = hks_kisi_dogrula(['tc' => $VKN, 'sifatId' => 5], $BUGUN);
ok('sifatId 5 (int) kabul', $h === null && $k['sifatId'] === 5, (string)$h);
[$k, $h] = hks_kisi_dogrula(['tc' => $VKN, 'sifatId' => '12'], $BUGUN);
ok('sifatId "12" (metin) → 12', $h === null && $k['sifatId'] === 12, (string)$h);
foreach ([-3, '-3', 'abc', '1.5', 1.5, '5x', true, [5], '99999999999'] as $kotu) {
    [, $h] = hks_kisi_dogrula(['tc' => $VKN, 'sifatId' => $kotu], $BUGUN);
    ok('sifatId ' . json_encode($kotu) . ' → 400 hatası', $h !== null);
}
[, $h] = hks_kisi_dogrula(['tc' => $VKN, 'sifatId' => 7], $BUGUN, [5, 6]);
ok('katalogda olmayan sıfat → hata', $h !== null && strpos($h, 'katalogda yok') !== false, (string)$h);
[$k, $h] = hks_kisi_dogrula(['tc' => $VKN, 'sifatId' => 6], $BUGUN, [5, 6]);
ok('katalogdaki sıfat → kabul', $h === null && $k['sifatId'] === 6);
[, $h] = hks_kisi_dogrula(['tc' => $VKN, 'sifatId' => 0], $BUGUN, [5, 6]);
ok('katalog varken boş sıfat → kabul', $h === null);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => str_repeat('ş', 201)], $BUGUN);
ok('201 karakter ad → hata', $h !== null);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => str_repeat('ş', 200)], $BUGUN);
ok('200 karakter (çok baytlı) ad → kabul', $h === null, (string)$h);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => 'X', 'cep' => '532123'], $BUGUN);
ok('kısa cep → hata', $h !== null);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => 'X', 'cep' => '90532000000000'], $BUGUN);
ok('14 haneli cep → hata', $h !== null);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => 'X', 'dogum' => '2026-10-01'], $BUGUN);
ok('gelecek doğum → hata', $h !== null);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => 'X', 'dogum' => $BUGUN], $BUGUN);
ok('bugün doğum → kabul', $h === null);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => 'X', 'dogum' => '2023-02-29'], $BUGUN);
ok('olmayan tarih → hata', $h !== null);
[, $h] = hks_kisi_dogrula(['tc' => $TC1, 'ad' => 'X', 'dogum' => 'dün'], $BUGUN);
ok('biçimsiz tarih → hata', $h !== null);

echo "\n── Maskeleme / audit ──\n";
ok('TC maskesi son 4 hane', hks_kisi_tc_maskele($TC1) === '*******' . substr($TC1, -4));
ok('VKN maskesi son 4 hane', hks_kisi_tc_maskele($VKN) === '******7890');
$av = hks_kisi_audit_degerleri(['ad' => 'A', 'tc' => $TC1, 'cep' => '05320000000', 'dogum' => '1980-01-15'],
                               ['ad' => 'A', 'tc' => $TC1, 'cep' => '', 'dogum' => '1980-01-15']);
ok('audit: cep/doğum değeri YOK', !array_key_exists('cep', $av) && !array_key_exists('dogum', $av));
ok('audit: tam TC YOK', strpos(json_encode($av), $TC1) === false);
ok('audit: cep_degisti=true, dogum_degisti=false', $av['cep_degisti'] === true && $av['dogum_degisti'] === false);
ok('audit (create): bool alanlar yok', !array_key_exists('cep_degisti', hks_kisi_audit_degerleri(['ad' => 'A', 'tc' => $TC1])));

echo "\n── Kaydet / güncelle / çakışma / sil ──\n";
$db = yeni_db();
$r = hks_kisi_kaydet($db, ['tc' => $TC1, 'ad' => 'Birinci Üretici', 'cep' => '5320000000', 'dogum' => '1970-05-05'], 7, $BUGUN);
ok('yeni kayıt 200 + create', $r['kod'] === 200 && $r['islem'] === 'create', json_encode($r, JSON_UNESCAPED_UNICODE));
$id1 = $r['kisi']['id'];
ok('API biçimi alanları', array_keys($r['kisi']) === ['id', 'tc', 'ad', 'cep', 'dogum', 'sifatId', 'kullanimSayisi', 'sonKullanim']);
ok('sifatId verilmediyse null', $r['kisi']['sifatId'] === null);
ok('yeni: sayaç 0, sonKullanim boş', $r['kisi']['kullanimSayisi'] === 0 && $r['kisi']['sonKullanim'] === '');
ok('olusturan_id yazıldı', (int)$r['satir']['olusturan_id'] === 7);
$r = hks_kisi_kaydet($db, ['tc' => $TC2, 'ad' => 'İkinci Üretici'], 7, $BUGUN);
$id2 = $r['kisi']['id'];
ok('ikinci kayıt 200, doğum boş', $r['kod'] === 200 && $r['kisi']['dogum'] === '');
$r = hks_kisi_kaydet($db, ['tc' => $TC1, 'ad' => 'Kopya'], 7, $BUGUN);
ok('aynı TC yeni kayıt → 409 + ad', $r['kod'] === 409 && strpos($r['hata'], 'Birinci Üretici') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = hks_kisi_kaydet($db, ['id' => $id2, 'tc' => $TC1, 'ad' => 'Kopya'], 7, $BUGUN);
ok('güncellemede başkasının TC\'si → 409', $r['kod'] === 409);
$r = hks_kisi_kaydet($db, ['id' => $id1, 'tc' => $TC1, 'ad' => 'Birinci Üretici Yeni', 'cep' => '', 'dogum' => ''], 7, $BUGUN);
ok('kendi TC\'siyle güncelleme 200 + update', $r['kod'] === 200 && $r['islem'] === 'update');
ok('güncelleme: pencere boş cep/doğumu boşaltır', $r['kisi']['cep'] === '' && $r['kisi']['dogum'] === '');
ok('güncelleme: eski satır döner (audit için)', ($r['eski']['ad'] ?? '') === 'Birinci Üretici');
ok('güncelleme dolu', $r['satir']['guncelleme'] !== null);
$r = hks_kisi_kaydet($db, ['id' => 9999, 'tc' => $TC3, 'ad' => 'Yok'], 7, $BUGUN);
ok('olmayan id → 404', $r['kod'] === 404);
$r = hks_kisi_kaydet($db, ['tc' => $TC_BOZUK, 'ad' => 'X'], 7, $BUGUN);
ok('geçersiz TC → 400', $r['kod'] === 400);
// UNIQUE son savunma: açık kontrolü atlayıp doğrudan INSERT → 23000
$yakalandi = false;
try {
    $db->prepare('INSERT INTO hks_kisiler (tc, ad, olusturma) VALUES (?, ?, ?)')->execute([$TC1, 'x', '2026-01-01 00:00:00']);
} catch (PDOException $e) { $yakalandi = (string)$e->getCode() === '23000'; }
ok('tc UNIQUE kısıtı 23000 verir', $yakalandi);
$r = hks_kisi_sil($db, $id2);
ok('sil 200', $r['kod'] === 200 && hks_kisi_getir($db, $id2) === null);
ok('tekrar sil → 404', hks_kisi_sil($db, $id2)['kod'] === 404);
ok('id 0 sil → 404', hks_kisi_sil($db, 0)['kod'] === 404);

echo "\n── Gönderim upsert'i ──\n";
$db = yeni_db();
hks_kisi_kaydet($db, ['tc' => $TC1, 'ad' => 'Kayıtlı Kişi', 'cep' => '5320000000', 'dogum' => '1970-05-05'], 1, $BUGUN);
ok('var olan kişi: yazıldı', hks_kisi_upsert($db, ['tc' => $TC1, 'ad' => '', 'cep' => '', 'dogum' => ''], 1, '2026-09-30 10:00:00') === true);
$s = hks_kisi_tc_ile($db, $TC1);
ok('boş ad/cep/doğum eskiyi SİLMEZ', $s['ad'] === 'Kayıtlı Kişi' && $s['cep'] === '5320000000' && substr((string)$s['dogum'], 0, 10) === '1970-05-05');
ok('sayaç 1, son_kullanim yazıldı', (int)$s['kullanim_sayisi'] === 1 && $s['son_kullanim'] === '2026-09-30 10:00:00');
hks_kisi_upsert($db, ['tc' => $TC1, 'ad' => 'Yeni Ad', 'cep' => '5559998877', 'dogum' => '01.02.1971'], 1, '2026-09-30 11:00:00');
$s = hks_kisi_tc_ile($db, $TC1);
ok('dolu değerler üzerine yazar (GG.AA.YYYY → ISO)', $s['ad'] === 'Yeni Ad' && $s['cep'] === '5559998877' && substr((string)$s['dogum'], 0, 10) === '1971-02-01');
ok('sayaç 2', (int)$s['kullanim_sayisi'] === 2);
hks_kisi_upsert($db, ['tc' => $TC1, 'ad' => '', 'cep' => '12', 'dogum' => '2099-01-01'], 1, '2026-09-30 12:00:00');
$s = hks_kisi_tc_ile($db, $TC1);
ok('geçersiz cep / gelecek doğum eskiyi ezmez', $s['cep'] === '5559998877' && substr((string)$s['dogum'], 0, 10) === '1971-02-01');
ok('yeni kişi: eklendi', hks_kisi_upsert($db, ['tc' => $TC2, 'ad' => 'Yeni Müstahsil'], 1, '2026-09-30 09:00:00') === true);
$s = hks_kisi_tc_ile($db, $TC2);
ok('yeni kişi: sayaç 1', $s && (int)$s['kullanim_sayisi'] === 1 && $s['son_kullanim'] === '2026-09-30 09:00:00');
ok('kirli TC rakama indirgenip eşleşir', hks_kisi_upsert($db, ['tc' => ' ' . substr($TC2, 0, 5) . ' ' . substr($TC2, 5)], 1) === true
    && (int)hks_kisi_tc_ile($db, $TC2)['kullanim_sayisi'] === 2);
ok('geçersiz 11 haneli TC yazılmaz', hks_kisi_upsert($db, ['tc' => $TC_BOZUK, 'ad' => 'X']) === false && hks_kisi_tc_ile($db, $TC_BOZUK) === null);
ok('12 hane yazılmaz', hks_kisi_upsert($db, ['tc' => '123456789012', 'ad' => 'X']) === false);
ok('VKN yazılır', hks_kisi_upsert($db, ['tc' => $VKN, 'ad' => 'Firma']) === true);

echo "\n── Liste sırası ──\n";
hks_kisi_kaydet($db, ['tc' => $TC3, 'ad' => 'Aaa Hiç Kullanılmamış'], 1, $BUGUN);
$l = hks_kisi_liste($db);
$sira = array_map(fn($x) => $x['tc'], $l);
// TC1 son 12:00, TC2 ~şimdi (tarihsiz çağrı), VKN şimdi; TC3 NULL → en sonda
ok('NULL son_kullanim en sonda', end($sira) === $TC3, implode(',', $sira));
ok('son kullanılan önce (TC1 12:00, TC2 09:00\'dan önce değil)', array_search($TC1, $sira) < array_search($TC3, $sira));
$dbs = yeni_db();
hks_kisi_upsert($dbs, ['tc' => $TC1, 'ad' => 'Z'], null, '2026-09-01 00:00:00');
hks_kisi_upsert($dbs, ['tc' => $TC2, 'ad' => 'Y'], null, '2026-09-20 00:00:00');
hks_kisi_kaydet($dbs, ['tc' => $TC3, 'ad' => 'B'], null, $BUGUN);
hks_kisi_kaydet($dbs, ['tc' => $VKN, 'ad' => 'A'], null, $BUGUN);
$sira = array_map(fn($x) => $x['ad'], hks_kisi_liste($dbs));
ok('sıra: son_kullanim DESC, NULL\'lar ada göre', $sira === ['Y', 'Z', 'A', 'B'], implode(',', $sira));

echo "\n── Tek seferlik içe aktarma ──\n";
$db = yeni_db();
$kv = $db->prepare('INSERT INTO hks_kv (anahtar, deger) VALUES (?, ?)');
$kv->execute(['sonlar_f1', json_encode(['plakalar' => ['06ABC123'], 'karsiTaraflar' => [
    ['tc' => $TC1, 'ad' => 'Eski Bir', 'cep' => '5320000000', 'dogum' => '1970-05-05'],
    ['tc' => $TC_BOZUK, 'ad' => 'Bozuk'],
    ['tc' => '', 'ad' => 'Boş'],
    'metin-bozuk-satir',
]], JSON_UNESCAPED_UNICODE)]);
$kv->execute(['sonlar_f2', json_encode(['karsiTaraflar' => [
    ['tc' => $TC1, 'ad' => 'Aynı Kişi Başka Firma'],
    ['tc' => $VKN, 'ad' => 'Eski Firma', 'cep' => '12', 'dogum' => '2099-01-01'],
]], JSON_UNESCAPED_UNICODE)]);
$kv->execute(['sonlar', json_encode(['plakalar' => []])]);          // karsiTaraflar yok
$kv->execute(['listeler_cache', json_encode(['karsiTaraflar' => [['tc' => $TC2, 'ad' => 'X']]])]);  // sonlar% değil
hks_kisi_kaydet($db, ['tc' => $TC3, 'ad' => 'Zaten Havuzda'], 1, $BUGUN);
$n = hks_kisi_havuzu_ice_aktar($db, '2026-09-30 08:00:00');
ok('2 kişi aktarıldı (TC1 + VKN)', $n === 2, var_export($n, true));
ok('ilk görülen kazanır, var olan atlanır', hks_kisi_tc_ile($db, $TC1)['ad'] === 'Eski Bir');
ok('geçersiz TC aktarılmaz', hks_kisi_tc_ile($db, $TC_BOZUK) === null);
ok('sonlar% dışı anahtar okunmaz', hks_kisi_tc_ile($db, $TC2) === null);
$v = hks_kisi_tc_ile($db, $VKN);
ok('geçersiz cep/doğum boş aktarılır', $v['cep'] === '' && $v['dogum'] === null);
ok('aktarılan: sayaç 0, son_kullanim NULL', (int)$v['kullanim_sayisi'] === 0 && $v['son_kullanim'] === null);
ok('havuzdaki kişi korunur', hks_kisi_tc_ile($db, $TC3)['ad'] === 'Zaten Havuzda');
$bayrak = $db->query("SELECT deger FROM hks_kv WHERE anahtar = 'kisi_havuzu_aktarildi'")->fetchColumn();
ok('bayrak yazıldı', $bayrak !== false && (json_decode((string)$bayrak, true)['aktarilan'] ?? -1) === 2);
ok('eski kv SİLİNMEDİ', (int)$db->query("SELECT COUNT(*) FROM hks_kv WHERE anahtar LIKE 'sonlar%'")->fetchColumn() === 3);
hks_kisi_sil($db, (int)hks_kisi_tc_ile($db, $TC1)['id']);
ok('ikinci çağrı null (bayrak var)', hks_kisi_havuzu_ice_aktar($db) === null);
ok('bayraktan sonra silinen kişi GERİ GELMEZ', hks_kisi_tc_ile($db, $TC1) === null);
// Bayrak silinse bile idempotent: var olanlar atlanır, yalnız eksik olan döner
$db->exec("DELETE FROM hks_kv WHERE anahtar = 'kisi_havuzu_aktarildi'");
ok('bayraksız yeniden çalıştırma idempotent (yalnız eksik 1)', hks_kisi_havuzu_ice_aktar($db) === 1);
ok('toplam kişi 3', (int)$db->query('SELECT COUNT(*) FROM hks_kisiler')->fetchColumn() === 3);
$db2 = yeni_db();
ok('boş kv: 0 aktarım + bayrak', hks_kisi_havuzu_ice_aktar($db2) === 0 && hks_kisi_havuzu_ice_aktar($db2) === null);

echo "\n── Kaydet: VKN adsız / TC adsız / sıfat + katalog ──\n";
$db = yeni_db();
$r = hks_kisi_kaydet($db, ['tc' => $VKN, 'ad' => ''], 1, $BUGUN);
ok('VKN adsız kayıt 200', $r['kod'] === 200 && $r['kisi']['ad'] === '', json_encode($r, JSON_UNESCAPED_UNICODE));
$idV = $r['kisi']['id'];
$r = hks_kisi_kaydet($db, ['tc' => $TC1, 'ad' => ''], 1, $BUGUN);
ok('TC adsız kayıt 400', $r['kod'] === 400);
$r = hks_kisi_kaydet($db, ['tc' => $VKN, 'ad' => 'Kopya'], 1, $BUGUN);
ok('adsız kayda çakışma 409 "(ad girilmemiş)"', $r['kod'] === 409 && strpos($r['hata'], 'ad girilmemiş') !== false, (string)($r['hata'] ?? ''));
$r = hks_kisi_kaydet($db, ['id' => $idV, 'tc' => $VKN, 'ad' => '', 'sifatId' => 9], 1, $BUGUN);
ok('önbellek YOKKEN sıfat id kabul + yazılır', $r['kod'] === 200 && $r['kisi']['sifatId'] === 9, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('audit değerinde sifat_id', ($r['kisi']['sifatId'] ?? 0) === 9 && (hks_kisi_audit_degerleri($r['satir'])['sifat_id'] ?? 0) === 9);
$db->prepare('INSERT INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['listeler_cache',
    json_encode(['sifatlar' => [['id' => 3, 'ad' => 'Üretici'], ['id' => 9, 'ad' => 'Tüccar'], ['id' => '11', 'ad' => 'Komisyoncu']]], JSON_UNESCAPED_UNICODE)]);
ok('katalog okunur (metin id int\'e)', hks_kisi_sifat_katalogu($db) === [3, 9, 11]);
$r = hks_kisi_kaydet($db, ['id' => $idV, 'tc' => $VKN, 'sifatId' => 42], 1, $BUGUN);
ok('katalogda olmayan sıfat → 400', $r['kod'] === 400 && strpos($r['hata'], 'katalogda yok') !== false);
ok('400 sonrası kayıt değişmedi', (int)hks_kisi_getir($db, $idV)['sifat_id'] === 9);
$r = hks_kisi_kaydet($db, ['id' => $idV, 'tc' => $VKN, 'sifatId' => 'x'], 1, $BUGUN);
ok('biçimsiz sıfat → 400', $r['kod'] === 400);
$r = hks_kisi_kaydet($db, ['id' => $idV, 'tc' => $VKN, 'sifatId' => '11'], 1, $BUGUN);
ok('katalogdaki sıfat (metin) → 200, 11', $r['kod'] === 200 && $r['kisi']['sifatId'] === 11);
$r = hks_kisi_kaydet($db, ['id' => $idV, 'tc' => $VKN, 'sifatId' => ''], 1, $BUGUN);
ok('pencerede sıfat boşaltılır → NULL', $r['kod'] === 200 && $r['kisi']['sifatId'] === null && hks_kisi_getir($db, $idV)['sifat_id'] === null);
$db->exec("UPDATE hks_kv SET deger = '{\"sifatlar\":[]}' WHERE anahtar = 'listeler_cache'");
ok('boş sifatlar listesi = önbellek yok (denetimsiz)', hks_kisi_sifat_katalogu($db) === null);

echo "\n── Upsert: sıfat yazımı / Üreticiden Sevk ──\n";
$db = yeni_db();
ok('upsert yeni kişi sifatId ile', hks_kisi_upsert($db, ['tc' => $TC1, 'ad' => 'S', 'sifatId' => '4'], 1, '2026-09-30 10:00:00'));
ok('yeni kişi sifat_id = 4', (int)hks_kisi_tc_ile($db, $TC1)['sifat_id'] === 4);
hks_kisi_upsert($db, ['tc' => $TC1, 'sifatId' => 8], 1, '2026-09-30 10:05:00');
ok('son kullanılan sıfat kazanır (8)', (int)hks_kisi_tc_ile($db, $TC1)['sifat_id'] === 8);
hks_kisi_upsert($db, ['tc' => $TC1, 'sifatId' => null], 1, '2026-09-30 10:06:00');
hks_kisi_upsert($db, ['tc' => $TC1, 'sifatId' => 'bozuk'], 1, '2026-09-30 10:07:00');
hks_kisi_upsert($db, ['tc' => $TC1], 1, '2026-09-30 10:08:00');
$s = hks_kisi_tc_ile($db, $TC1);
ok('boş/geçersiz/eksik sifatId eski sıfatı SİLMEZ', (int)$s['sifat_id'] === 8 && (int)$s['kullanim_sayisi'] === 5);
$ortak = ['ikinciTc' => $TC1, 'ikinciAd' => 'Üretici Kişi', 'ikinciCep' => '', 'ikinciDogumTarihi' => '', 'ikinciSifatId' => 3];
$k = hks_kisi_ortaktan($ortak, true);
ok('ortaktan (üretici sevk): sifatId YOK', $k['sifatId'] === null && $k['tc'] === $TC1 && $k['ad'] === 'Üretici Kişi');
hks_kisi_upsert($db, $k, 1, '2026-09-30 11:00:00');
ok('Üreticiden Sevk Alım sıfatı yazmaz (8 korunur)', (int)hks_kisi_tc_ile($db, $TC1)['sifat_id'] === 8);
$k = hks_kisi_ortaktan($ortak, false);
ok('ortaktan (diğer türler): sifatId taşınır', $k['sifatId'] === 3);
hks_kisi_upsert($db, $k, 1, '2026-09-30 11:05:00');
ok('diğer türde sıfat yazılır (3)', (int)hks_kisi_tc_ile($db, $TC1)['sifat_id'] === 3);
hks_kisi_upsert($db, hks_kisi_ortaktan(['ikinciTc' => $TC2, 'ikinciSifatId' => 3], true), 1);
ok('üretici sevkte YENİ kişi sifat_id NULL', hks_kisi_tc_ile($db, $TC2)['sifat_id'] === null);
ok('ortaktan: eksik alanlar boş', hks_kisi_ortaktan([], false) === ['tc' => '', 'ad' => '', 'cep' => '', 'dogum' => '', 'sifatId' => null]);

echo "\n── Şema yükseltme (v282 → sifat_id) ──\n";
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
// v282'deki tablo biçimi (sifat_id YOK) + mevcut veri
$db->exec("CREATE TABLE hks_kisiler (
  id INTEGER PRIMARY KEY AUTOINCREMENT, tc VARCHAR(11) NOT NULL UNIQUE, ad VARCHAR(200) NOT NULL DEFAULT '',
  cep VARCHAR(20) NOT NULL DEFAULT '', dogum DATE NULL, kullanim_sayisi INT NOT NULL DEFAULT 0,
  son_kullanim DATETIME NULL, olusturma DATETIME NOT NULL, guncelleme DATETIME NULL, olusturan_id INT NULL)");
$db->prepare('INSERT INTO hks_kisiler (tc, ad, olusturma) VALUES (?, ?, ?)')->execute([$TC1, 'Eski Kayıt', '2026-09-01 00:00:00']);
ok('eski şemada kolon yok', hks_kisi_sifat_kolonu_var($db) === false);
hks_kisi_tablo_hazirla($db);
ok('yükseltme sonrası kolon var', hks_kisi_sifat_kolonu_var($db) === true);
$istisna = null;
try { hks_kisi_tablo_hazirla($db); hks_kisi_tablo_hazirla($db); hks_kisi_sifat_kolonu_hazirla($db); }
catch (Throwable $e) { $istisna = $e->getMessage(); }
ok('tekrar çağrı idempotent (istisna yok)', $istisna === null, (string)$istisna);
$kolonlar = array_column($db->query('PRAGMA table_info(hks_kisiler)')->fetchAll(), 'name');
ok('sifat_id tek kez eklendi', count(array_keys($kolonlar, 'sifat_id', true)) === 1, implode(',', $kolonlar));
$e1 = hks_kisi_tc_ile($db, $TC1);
ok('eski veri korunur, sifat_id NULL', $e1['ad'] === 'Eski Kayıt' && $e1['sifat_id'] === null);
ok('eski satır API biçiminde sifatId null', hks_kisi_liste($db)[0]['sifatId'] === null);
ok('yükseltilmiş tabloda upsert sıfat yazar', hks_kisi_upsert($db, ['tc' => $TC1, 'sifatId' => 5]) && (int)hks_kisi_tc_ile($db, $TC1)['sifat_id'] === 5);
// Eşzamanlı ekleme: yoklama "yok" dedikten sonra başkası kolonu eklemiş → duplicate column yutulur
$db2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db2->exec("CREATE TABLE hks_kisiler (id INTEGER PRIMARY KEY, tc VARCHAR(11), sifat_id INT NULL)");
$istisna = null;
try { $db2->exec('ALTER TABLE hks_kisiler ADD COLUMN sifat_id INT NULL'); } catch (PDOException $e) { $istisna = $e; }
ok('(ön koşul) ikinci ALTER SQLite\'ta hata verir', $istisna !== null);
$istisna = null;
try { hks_kisi_sifat_kolonu_hazirla($db2); } catch (Throwable $e) { $istisna = $e->getMessage(); }
ok('kolon zaten varsa hazırla sessiz geçer', $istisna === null, (string)$istisna);

echo "\n── api.php: CSRF girdisi + 'csrf' ucu + sıfat kararı (kaynak) ──\n";
$api = (string)file_get_contents(__DIR__ . '/../halkayit/api.php');
// hks_csrf_girdi() api.php'den ayıklanıp ayrı dosyada çalıştırılır (api.php
// require edilemez: oturum/JSON/exit yan etkileri).
if (preg_match('/^function hks_csrf_girdi\(.*?^\}/ms', $api, $m)) {
    $tmp = tempnam(sys_get_temp_dir(), 'hkscsrf');
    file_put_contents($tmp, "<?php\n" . $m[0] . "\n");
    require $tmp;
    @unlink($tmp);
    $eskiSrv = $_SERVER;
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    ok('csrf: başlık yok → gövde', hks_csrf_girdi(['csrf' => 'G']) === 'G');
    ok('csrf: ikisi de yok → null', hks_csrf_girdi([]) === null);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'B';
    ok('csrf: başlık önce gelir', hks_csrf_girdi(['csrf' => 'G']) === 'B');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = '';
    ok('csrf: boş başlık yok sayılır → gövde', hks_csrf_girdi(['csrf' => 'G']) === 'G');
    ok('csrf: dizi gövde değeri → null', hks_csrf_girdi(['csrf' => ['x']]) === null);
    $_SERVER = $eskiSrv;
} else {
    ok('api.php hks_csrf_girdi() tanımlı', false);
}
ok("api.php 'csrf' ucu csrf_token() döner", (bool)preg_match("/case 'csrf':\s*\{[^}]*hks_json_cikti\(\['csrf' => csrf_token\(\)\]\)/s", $api));
ok("'csrf' ucu records.write kapısından SONRA", strpos($api, "can('records.write')") !== false
    && strpos($api, "can('records.write')") < strpos($api, "case 'csrf':"));
ok('kisi_kaydet + kisi_sil hks_csrf_girdi() ile denetler', substr_count($api, 'csrf_check(hks_csrf_girdi($g))') === 2
    && strpos($api, "csrf_check(\$_SERVER['HTTP_X_CSRF_TOKEN']") === false);
ok('havuza işleme sıfat kararını hks_uret_sevk_mi() ile verir',
    strpos($api, 'hks_kisi_ortaktan((array)$ortak, hks_uret_sevk_mi($ortak))') !== false);
$lib = (string)file_get_contents(__DIR__ . '/../halkayit/kisi_havuzu_lib.php');
ok('lib taslak_lib.php\'yi require ETMEZ (döngü yok)', !preg_match('/require[^;]*taslak_lib/', $lib));
$sema = (string)file_get_contents(__DIR__ . '/../halkayit/schema.sql');
ok('schema.sql hks_kisiler sifat_id içerir', (bool)preg_match('/CREATE TABLE IF NOT EXISTS hks_kisiler \([^;]*sifat_id INT NULL/s', $sema));

echo "\n" . ($fail === 0 ? 'TÜM TESTLER GEÇTİ' : "$fail TEST BAŞARISIZ") . "\n";
exit($fail === 0 ? 0 : 1);
