<?php
// =========================================================
// scripts/hks_depo_tasnif_kaynak_test.php — Depo/Tasnif kaynak kuralı (v366)
//
// HKS HataKodu 108: "Depo/Tasnif sıfatından sevk ederken, ancak referans künye
// bildirimini yapan kişiye ve sıfatına geri bildirim yapabilirsiniz". Bu test GERÇEK
// hks_depo_tasnif_sifat_id() / hks_kaynak_sevk_mi() (taslak_lib.php) ile gönderim
// öncesi engel kararlarını (kunye_secim_lib.php: hks_kaynak_tc_engeli,
// hks_kaynak_red_engeli) çalıştırır; api.php akışı kaynakta denetlenir.
// Ağ/DB YOK: taslak_lib'in config/db require'ları geçici kopyada çıkarılır,
// hks_kv_oku bellek içi taklittir. Numaralar sentetiktir.
//
//   php scripts/hks_depo_tasnif_kaynak_test.php   → çıkış 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
$KOK = dirname(__DIR__);

$KATALOG = ['sifatlar' => [
    ['id' => 2, 'ad' => 'İhracat'], ['id' => 9, 'ad' => 'Depo/Tasnif'], ['id' => 11, 'ad' => 'Tüccar (Hal Dışı)'],
]];
$GLOBALS['__KV'] = ['listeler_cache' => $KATALOG];
function hks_kv_oku($a, $v = null) { return $GLOBALS['__KV'][$a] ?? $v; }
function hks_kv_yaz($a, $v) { $GLOBALS['__KV'][$a] = $v; }

require_once "$KOK/halkayit/hks_soap.php";
$src = (string)file_get_contents("$KOK/halkayit/taslak_lib.php");
$src = preg_replace("#^require_once __DIR__ \. '/(config|db|hks_soap|dogum_deney_lib)\.php';.*$#m", '', $src);
$tmp = tempnam(sys_get_temp_dir(), 'hkstl');
file_put_contents($tmp, $src);
require $tmp;
@unlink($tmp);
require_once "$KOK/halkayit/kunye_secim_lib.php";

$fail = 0;
function ok(string $ad, bool $c, string $ip = ''): void {
    global $fail; if (!$c) $fail++;
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ip);
}

$AGRO = '0091880872'; $BASKA = '1234567890'; $KENDI = '1111111111';
$KUNYE = '1204159260026133175';
$sevk = ['yurtIci' => true, 'referanssiz' => false, 'fiyatGonder' => false, 'uretSevk' => false,
         'sifatId' => '9', 'turAd' => 'Sevk Etme', 'ikinciTc' => $AGRO, 'ikinciSifatId' => '2'];

echo "── Katalog: hks_depo_tasnif_sifat_id ──\n";
ok('ADA göre bulunur (id hard-code yok)', hks_depo_tasnif_sifat_id() === 9);
$GLOBALS['__KV']['listeler_cache'] = ['sifatlar' => [['id' => 31, 'ad' => 'DEPO / TASNİF']]];
ok('büyük harf / boşluk / İ duyarsız', hks_depo_tasnif_sifat_id() === 31);
$GLOBALS['__KV']['listeler_cache'] = ['sifatlar' => [['id' => 31, 'ad' => 'Depo/Tasnif'], ['id' => 32, 'ad' => 'Depo Tasnif Merkezi']]];
ok('birden çok eşleşme → null (belirsiz)', hks_depo_tasnif_sifat_id() === null);
$GLOBALS['__KV']['listeler_cache'] = ['sifatlar' => [['id' => 5, 'ad' => 'Depo']]];
ok('yalnız "Depo" → null', hks_depo_tasnif_sifat_id() === null);
unset($GLOBALS['__KV']['listeler_cache']);
ok('katalog yok → null', hks_depo_tasnif_sifat_id() === null);
ok('katalog yok → kaynak sevk kuralı uygulanmaz', !hks_kaynak_sevk_mi($sevk));
$GLOBALS['__KV']['listeler_cache'] = $KATALOG;

echo "\n── Mod: hks_kaynak_sevk_mi ──\n";
ok('Depo/Tasnif + Sevk Etme → evet', hks_kaynak_sevk_mi($sevk));
ok('İhracat sıfatıyla sevk → hayır', !hks_kaynak_sevk_mi(['sifatId' => 2] + $sevk));
ok('Satış (fiyatlı) → hayır', !hks_kaynak_sevk_mi(['fiyatGonder' => true] + $sevk));
ok('Satın Alım (referanssız) → hayır', !hks_kaynak_sevk_mi(['referanssiz' => true] + $sevk));
ok('yurt dışı → hayır', !hks_kaynak_sevk_mi(['yurtIci' => false] + $sevk));
ok('Üreticiden Sevk Alım → hayır', !hks_kaynak_sevk_mi(['uretSevk' => true] + $sevk));

echo "\n── Engel kararı (kullanıcı senaryosu) ──\n";
// Künye ASYA'ya BAŞKA biri (fiyatlı satış) tarafından bildirilmiş; AGRO'ya geri sevk → 108.
$koken = hks_kunye_koken_haritasi([$KUNYE => ['kunyeNo' => $KUNYE, 'bildirimciTc' => $BASKA]], $KENDI);
$e = hks_kaynak_tc_engeli([['kunyeNo' => $KUNYE, 'miktar' => 100]], $koken, $AGRO);
ok('kaynak başka kişi → TC engeli (göndermeden önce)', count($e) === 1 && $e[0]['kaynakTc'] === $BASKA, json_encode($e));
// Fiyatsız künye: AGRO'nun sevkiyle gelmiş → AGRO'ya geri sevk serbest.
$koken = hks_kunye_koken_haritasi([$KUNYE => ['kunyeNo' => $KUNYE, 'bildirimciTc' => $AGRO]], $KENDI);
ok('kaynak AGRO → engel yok', hks_kaynak_tc_engeli([['kunyeNo' => $KUNYE]], $koken, $AGRO) === []);
ok('kaynak bilinmiyor → engel yok', hks_kaynak_tc_engeli([['kunyeNo' => $KUNYE]], [], $AGRO) === []);
// HKS VKN'yi baştaki sıfırsız dönebilir: '91880872' == '0091880872' (yanlış engel YOK).
$__kh0 = hks_kunye_koken_haritasi([$KUNYE => ['kunyeNo' => $KUNYE, 'bildirimciTc' => '91880872']], '1111111111');
ok('baştaki sıfırsız VKN 10 haneye tamamlanır', ($__kh0[hks_kunye_no_norm($KUNYE)] ?? '') === '0091880872', json_encode($__kh0));
ok('baştaki sıfırsız kaynak = 0091880872 → engel yok', hks_kaynak_tc_engeli([['kunyeNo' => $KUNYE]], $__kh0, '0091880872') === []);
ok('kendi VKN sıfırsız gelse de kaynak sayılmaz', hks_kunye_koken_haritasi([$KUNYE => ['kunyeNo' => $KUNYE, 'bildirimciTc' => '91880872']], '0091880872') === []);
ok('künyeyi kendimiz doğurduysak (bildirimci = kendi VKN) kaynak sayılmaz',
    hks_kunye_koken_haritasi([$KUNYE => ['kunyeNo' => $KUNYE, 'bildirimciTc' => $KENDI]], $KENDI) === []);
// 108 öğrenildi: AGRO + İhracat reddedildi → aynı üçlü gönderilmez, Tüccar serbest.
$map = hks_kunye_kaynak_map_ekle([], [$KUNYE], $AGRO, 2, date('Y-m-d'));
ok('108 sonrası aynı TC + sıfat → red engeli', hks_kaynak_red_engeli([['kunyeNo' => $KUNYE]], $map, $AGRO, 2) === [$KUNYE]);
ok('108 sonrası farklı sıfat (Tüccar) → engel yok', hks_kaynak_red_engeli([['kunyeNo' => $KUNYE]], $map, $AGRO, 11) === []);
ok('108 sınıflaması: künye×sıfat uyumsuzluğu DEĞİL',
    hks_kunye_sifat_hatali_satirlar([['yeniKunyeNo' => '0', 'hataKodu' => 108, 'mesaj' =>
        'Depo/Tasnif sıfatından sevk ederken, ancak referans künye bildirimini yapan kişiye ve sıfatına geri bildirim yapabilirsiniz.. Referans künye no : "' . $KUNYE . '"']],
        [['kunyeNo' => $KUNYE]]) === []);

echo "\n── api.php akışı (statik) ──\n";
$api = (string)file_get_contents("$KOK/halkayit/api.php");
$pMod = strpos($api, 'if (hks_kaynak_sevk_mi($ortak)) {' . "\n" . '        // Elle seçilmiş');
$pKaydet = strpos($api, '$sonuc = hks_bildirim_kaydet($cfg, $satirlar, $ortak, $__secenek);');
ok('engel bloğu kaynak sevk modunda ve gönderimden ÖNCE', $pMod !== false && $pKaydet !== false && $pMod < $pKaydet);
$blok = substr($api, (int)$pMod, (int)$pKaydet - (int)$pMod);
ok('elle seçimde detay best-effort (try/catch) sorgulanır', strpos($blok, 'hks_kunye_detaylari_getir($cfg') !== false && strpos($blok, 'catch (Throwable $e)') !== false);
ok('engelde taslak geri konur + 400 + taslakKorundu',
    substr_count($blok, '$taslagiGeriKoy();') >= 2 && substr_count($blok, "'taslakKorundu' => true") >= 2 && substr_count($blok, '], 400);') >= 2);
ok('plan çözümü kaynak kipini geçirir', (bool)preg_match('/function hks_plan_kunye_coz\(.*?hks_kaynak_sevk_mi\(\$ortak\).*?\$kaynak\s*\)\s*;/s', $api));
ok('108 öğrenmesi koken haritasını kullanır', strpos($api, 'hks_kunye_kaynak_ekle($t[\'firma_id\'], $__kh, $__ktc, $__ksf, $__kk);') !== false);
ok('tek gönderim yolu korunur', preg_match_all('/\bhks_bildirim_kaydet\s*\(/', preg_replace('#//.*$#m', '', $api)) === 1);

echo $fail === 0 ? "\nTÜM TESTLER GEÇTİ\n" : "\n$fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
