<?php
// =========================================================
// scripts/hks_fiyatsiz_taslak_test.php — v498: fiyat Hal Kayıt'ta GÖNDERİMDE girilir
//   php scripts/hks_fiyatsiz_taslak_test.php   → çıkış 0 = geçti
// GERÇEK hks_bildirim_dogrula()'yı çalıştırır ($fiyatsizIzin / $eksikIzin) + api.php /
// tekrar_gonder_lib.php kaynak denetimleri. Ağ/DB YOK.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
$KOK = dirname(__DIR__);

$GLOBALS['__KV'] = ['listeler_cache' => ['sifatlar' => [
    ['id' => 2, 'ad' => 'İhracat'], ['id' => 4, 'ad' => 'Üretici'], ['id' => 7, 'ad' => 'Komisyoncu'],
]]];
function hks_kv_oku($a, $v = null) { return $GLOBALS['__KV'][$a] ?? $v; }
function hks_kv_yaz($a, $v) { $GLOBALS['__KV'][$a] = $v; }

require_once "$KOK/halkayit/hks_soap.php";
$src = (string)file_get_contents("$KOK/halkayit/taslak_lib.php");
$src = preg_replace("#^require_once __DIR__ \. '/(config|db|hks_soap|dogum_deney_lib)\.php';.*$#m", '', $src);
$tmp = tempnam(sys_get_temp_dir(), 'hkstl');
file_put_contents($tmp, $src);
require $tmp;
@unlink($tmp);

$fail = 0;
function ok(string $ad, bool $c, string $ip = ''): void {
    global $fail; if (!$c) $fail++;
    printf("%-84s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ip);
}

// Yurt dışı plan taslağı (Beyan ekranı ürünü): fiyatsız
$dis = [
    'sifatId' => 2, 'bildirimTuruId' => 1, 'isletmeTuruId' => 6, 'plaka' => '34ABC123',
    'urunId' => 5, 'urunAd' => 'Kayısı', 'ulkeId' => 3, 'ulkeAd' => 'Rusya',
    'planKg' => 18000, 'planSorgu' => ['urunId' => 5, 'aySayisi' => 12, 'isletmeTuruId' => 0, 'sirala' => 'azalan'],
    'fiyatBekliyor' => true,
];
$g = fn(array $o, array $s = []) => ['satirlar' => $s, 'ortak' => $o];

echo "── Yurt dışı: fiyatsız izin ──\n";
$h = hks_bildirim_dogrula($g($dis), true);
ok('varsayılan çağrıda fiyatsız taslak REDDEDİLİR (gönderim katı)', is_string($h) && str_contains($h, 'fiyat'), (string)$h);
$h = hks_bildirim_dogrula($g($dis), true, true);
ok('$fiyatsizIzin ile fiyat atlanır', $h === null, (string)$h);
$h = hks_bildirim_dogrula($g(['ulkeId' => 0] + $dis), true, true);
ok('$fiyatsizIzin ile ülke eksikse yine reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['plaka' => ''] + $dis), true, true);
ok('$fiyatsizIzin ile plaka eksikse yine reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['planKg' => 0] + $dis), true, true);
ok('$fiyatsizIzin ile planKg=0 yine reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['fiyat' => 12.5] + $dis), true);
ok('fiyat yazılınca varsayılan (katı) çağrıdan geçer', $h === null, (string)$h);

echo "\n── Yurt içi: eksik (tamamlanacak) izin ──\n";
$ic = [
    'yurtIci' => true, 'tamamlanacak' => true, 'fiyatBekliyor' => true, 'fiyatGonder' => true,
    'kayitZorunlu' => false, 'bildirimTuruId' => 1, 'turAd' => 'Satış', 'urunId' => 5, 'urunAd' => 'Kayısı',
    'plaka' => '34ABC123', 'planKg' => 18000,
    'planSorgu' => ['urunId' => 5, 'aySayisi' => 12, 'isletmeTuruId' => 0, 'sirala' => 'azalan'],
    'ikinciTc' => '1234567890',   // 10 hane VKN
];
$h = hks_bildirim_dogrula($g($ic), true, true, true);
ok('sıfat/işletme türü/işyeri/il/fiyat boş olsa da eksik izinle GEÇER', $h === null, (string)$h);
$h = hks_bildirim_dogrula($g($ic), true);
ok('katı çağrıda (gönderim) aynı taslak reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g($ic), true, true, false);
ok('$eksikIzin=false iken (yalnız fiyatsız) eksik alanlar reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['tamamlanacak' => false] + $ic), true, true, true);
ok('$eksikIzin tamamlanacak bayrağı olmadan GEVŞETMEZ', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['ikinciTc' => '12345'] + $ic), true, true, true);
ok('bozuk TC/VKN hane sayısı reddedilir', is_string($h) && str_contains($h, 'geçersiz'), (string)$h);
$h = hks_bildirim_dogrula($g(['ikinciTc' => '12345678901'] + $ic), true, true, true);
ok('11 hane algoritma dışı TC reddedilir', is_string($h) && str_contains($h, 'algoritma'), (string)$h);
$h = hks_bildirim_dogrula($g(['ikinciTc' => '10000000146'] + $ic), true, true, true);
ok('geçerli 11 haneli TC geçer', $h === null, (string)$h);
$h = hks_bildirim_dogrula($g(['ikinciTc' => ''] + $ic), true, true, true);
ok('boş TC reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['plaka' => ''] + $ic), true, true, true);
ok('boş plaka reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['planKg' => 0] + $ic), true, true, true);
ok('planKg=0 reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['planSorgu' => []] + $ic), true, true, true);
ok('planSorgu.urunId yoksa reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['referanssiz' => true] + $ic), true, true, true);
ok('referanssız reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['bildirimTuruId' => 0] + $ic), true, true, true);
ok('bildirimTuruId boşsa reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula($g(['yurtIci' => false, 'ulkeId' => 0] + $ic), true, true, true);
ok('yurtIci=false iken eksik izni yok (yurt dışı kuralları)', is_string($h), (string)$h);

echo "\n── hks_ikinci_tc_hatasi (normal yolla ortak yardımcı) ──\n";
ok('TC yok → mesaj', hks_ikinci_tc_hatasi([]) === 'Karşı taraf TC/Vergi No gerekli.');
ok('10 hane → null', hks_ikinci_tc_hatasi(['ikinciTc' => '1234567890']) === null);
$normal = ['sifatId' => 2, 'bildirimTuruId' => 1, 'isletmeTuruId' => 6, 'plaka' => '34ABC123', 'yurtIci' => true,
    'ikinciTc' => '12345', 'ikinciSifatId' => 7, 'gidecekIsyeriId' => 4, 'fiyatGonder' => true, 'fiyat' => 3];
$h = hks_bildirim_dogrula($g($normal, [['kunyeNo' => '1', 'miktar' => 10]]));
ok('normal yurt içi yol bozuk TC\'yi AYNI mesajla reddeder', $h === hks_ikinci_tc_hatasi($normal), (string)$h);

echo "\n── Kaynak denetimleri ──\n";
$api = (string)file_get_contents("$KOK/halkayit/api.php");
$lib = (string)file_get_contents("$KOK/halkayit/taslak_lib.php");
$tg  = (string)file_get_contents("$KOK/halkayit/tekrar_gonder_lib.php");
$app = (string)file_get_contents("$KOK/halkayit/app.html");

preg_match_all("/^    case '([a-z_]+)': \{/m", $api, $cm, PREG_OFFSET_CAPTURE);
$govde = function (string $ad) use ($api, $cm): string {
    foreach ($cm[1] as $i => $m) {
        if ($m[0] === $ad) {
            $son = $cm[1][$i + 1][1] ?? strlen($api);
            return substr($api, $m[1], $son - $m[1]);
        }
    }
    return '';
};
$f = $govde('taslak_fiyat_yaz');
ok('taslak_fiyat_yaz case var', $f !== '');
ok('muaf listede DEĞİL (CSRF ister)', !preg_match("/function hks_csrf_muaf_eylemler[^}]*'taslak_fiyat_yaz'/s", $api));
ok('firmaId/id boşsa 400', str_contains($f, "\$fid === '' || \$tid === ''"));
ok('fiyat 4 ondalık, >0 ve <1e7', str_contains($f, 'round((float)') && str_contains($f, '1e7'));
ok('firma izolasyonu: WHERE id = ? AND firma_id = ?', substr_count($f, 'WHERE id = ? AND firma_id = ?') >= 3);
ok('tamamlanacak taslağa 409', str_contains($f, "!empty(\$ortak['tamamlanacak'])") && str_contains($f, '409'));
ok('yurtIci && !fiyatGonder → 400', str_contains($f, "empty(\$ortak['fiyatGonder'])"));
ok('katı hks_bildirim_dogrula (fiyatsız/eksik izni YOK)', preg_match('/hks_bildirim_dogrula\(\[.*?\], \$planMi\);/', $f) === 1);
ok('rowCount KULLANILMAZ, SELECT 1 teyidi var', !str_contains($f, 'rowCount') && str_contains($f, 'SELECT 1 FROM'));
ok('fiyatBekliyor silinir', str_contains($f, "unset(\$ortak['fiyatBekliyor'])"));
ok('audit yazılır, kişisel veri yok', str_contains($f, "audit_log_event('update', 'hks_taslak_fiyat'") && !preg_match('/ikinciTc|ikinciAd|ikinciCep|ikinciDogum|plaka/', substr($f, strpos($f, 'audit_log_event'))));
ok('HKS gönderimi yok', !str_contains($f, 'hks_bildirim_kaydet') && !str_contains($f, 'taslak_gonder'));
ok('yanıt {tamam,id,fiyat}', str_contains($f, "['tamam' => true, 'id' => \$tid, 'fiyat' => \$fiyat]"));

$k = $govde('taslak_kaydet');
ok("taslak_kaydet istemci ortak'ından fiyatBekliyor/tamamlanacak siler",
    str_contains($k, "unset(\$g['ortak']['fiyatBekliyor'], \$g['ortak']['tamamlanacak'], \$g['ortak']['sifatBekliyor'])"));
ok('hks_taslak_olustur bayrakları ortak\'tan türetir',
    str_contains($lib, "!empty(\$g['ortak']['fiyatBekliyor'])") && str_contains($lib, "!empty(\$g['ortak']['tamamlanacak'])")
    && str_contains($lib, 'hks_bildirim_dogrula($g, $planMi, $fiyatsiz, $eksik, !empty($g[\'ortak\'][\'sifatBekliyor\']))'));

$tg_ = $govde('taslak_gonder');
ok('taslak_gonder: katı yeniden doğrulama satırı AYNEN', str_contains($tg_, "\$__revalHata = hks_bildirim_dogrula(['satirlar' => \$satirlar, 'ortak' => \$ortak]);"));
ok('taslak_gonder: fiyatBekliyor/tamamlanacak → eksik bilgi hatası',
    str_contains($tg_, "Taslakta eksik bilgi var (fiyat/yurt içi alanlar). Düzenle ile tamamlayın.")
    && str_contains($tg_, "!empty(\$ortak['fiyatBekliyor']) || !empty(\$ortak['tamamlanacak'])"));
ok("taslak_gonder: 'ayni_plaka' ve 'hks_gonderilen_' alt dizesi YOK", !str_contains($tg_, 'ayni_plaka') && !str_contains($tg_, 'hks_gonderilen_'));
ok('taslak_gonder: hks_taslak_olustur( çağrısı yok', !str_contains($tg_, 'hks_taslak_olustur('));
ok('taslak_gonder: isaretle çağrısına beyansizId', str_contains($tg_, "'beyansizId' =>") && str_contains($tg_, "=== 'beyansiz'"));
ok('hks_taslaklar INSERT yalnız taslak_lib.php + api.php geri-koy (taslak_fiyat_yaz\'da yok)', substr_count($api, "INSERT INTO ' . hks_tablo('taslaklar')") === 1 && !str_contains($f, 'INSERT') && str_contains($lib, "INSERT INTO ' . hks_tablo('taslaklar')"));

$tl = $govde('taslaklar');
ok('taslaklar: fiyatBekliyor taslaklara sonFiyat, hks_gonderilenler fiyat>0 son kayıt',
    str_contains($tl, "'sonFiyat'") && str_contains($tl, 'fiyat > 0 ORDER BY zaman DESC LIMIT 1') && str_contains($tl, 'firma_id = ? AND urun_ad = ?'));

ok('audit ctx/payload beyansiz_id taşır (beyaz liste)', str_contains($tg, "'beyansiz_id' =>") && str_contains($tg, "\$p['beyansiz_id']"));

echo "\n── v500: yeni firma (yalnız ad) + başka firmaya kopya (sifatBekliyor) ──\n";
$yf = array_merge($ic, ['ikinciTc' => '', 'ikinciAd' => 'YENİ FİRMA LTD']);
ok('eksik izin: TC boş ama ad var → GEÇER (TC taslakta tamamlanır)', hks_bildirim_dogrula($g($yf), true, true, true) === null);
ok('eksik izin: TC boş ve ad boş → reddedilir', is_string(hks_bildirim_dogrula($g(array_merge($ic, ['ikinciTc' => '', 'ikinciAd' => ''])), true, true, true)));
ok('eksik izin: dolu ama bozuk TC yine reddedilir', is_string(hks_bildirim_dogrula($g(array_merge($yf, ['ikinciTc' => '123'])), true, true, true)));
ok('katı çağrıda (gönderim) TC boş yeni firma reddedilir', is_string(hks_bildirim_dogrula($g($yf), true)));
$sb = $dis; unset($sb['sifatId']); $sb['sifatBekliyor'] = true;
ok('sifatBekliyor + izin: sıfatsız yurt dışı taslak GEÇER', hks_bildirim_dogrula($g($sb), true, true, false, true) === null);
ok('sifatBekliyor ama izin parametresi yok → sıfat reddedilir', is_string(hks_bildirim_dogrula($g($sb), true, true)));
$sb2 = $sb; unset($sb2['sifatBekliyor']);
ok('bayrak olmadan izin parametresi GEVŞETMEZ', is_string(hks_bildirim_dogrula($g($sb2), true, true, false, true)));
$api2 = file_get_contents("$KOK/halkayit/api.php");
$f2 = (preg_match('/case \'taslak_kopya_firmaya\': \{(.*?)\n    \}\n/s', $api2, $m2)) ? $m2[1] : '';
ok('taslak_kopya_firmaya case var, muaf listede DEĞİL', $f2 !== '' && !preg_match("/function hks_csrf_muaf_eylemler[^}]*'taslak_kopya_firmaya'/s", $api2));
ok('kaynak firma_id ile okunur, aynı firma reddedilir', str_contains($f2, 'WHERE id = ? AND firma_id = ?') && str_contains($f2, '$kaynakFid === $hedefFid'));
ok('yazma TEK yoldan (hks_taslak_olustur), INSERT yok', str_contains($f2, 'hks_taslak_olustur(') && !str_contains($f2, 'INSERT'));
ok('taslak_kaydet sifatBekliyor bayrağını istemciden siler', str_contains($api2, "\$g['ortak']['sifatBekliyor']"));
ok('gönderim sifatBekliyor taslağı bloklar', str_contains($api2, "!empty(\$ortak['sifatBekliyor'])"));

echo "\n── Tekrar gönder beyaz listesi bayrak KOPYALAMAZ ──\n";
require_once "$KOK/halkayit/tekrar_gonder_lib.php";
ok('beyaz listede fiyatBekliyor yok', !in_array('fiyatBekliyor', hks_kopya_ortak_anahtarlari(), true));
ok('beyaz listede tamamlanacak yok', !in_array('tamamlanacak', hks_kopya_ortak_anahtarlari(), true));
$kp = hks_gonderim_kopyasi(['ortak' => $dis + ['tamamlanacak' => true, 'kaynak' => ['tip' => 'beyansiz', 'kayitId' => 3]], 'satirlar' => []]);
ok('kopyada bayrak/kaynak yok', is_array($kp) && !isset($kp['ortak']['fiyatBekliyor']) && !isset($kp['ortak']['tamamlanacak']) && !isset($kp['ortak']['kaynak']));

echo "\n── app.html ──\n";
ok("API_ROTA 'POST /api/taslak_fiyat_yaz'", str_contains($app, "'POST /api/taslak_fiyat_yaz': 'taslak_fiyat_yaz'"));
ok('API_GOVDE_CSRF taslak_fiyat_yaz:1', str_contains($app, 'taslak_fiyat_yaz: 1'));
ok("API_ROTA 'POST /api/taslak_kopya_firmaya'", str_contains($app, "'POST /api/taslak_kopya_firmaya': 'taslak_kopya_firmaya'") && str_contains($app, 'taslak_kopya_firmaya: 1'));
ok('Kopyala menü kipinde (taslak) açılır', str_contains($app, "tekrarMenuAc(b, b.dataset.kopyala, 'taslak')") && str_contains($app, 'taslakFirmayaKopyala('));
ok('taslakOnayAc içinde fiyat kapısı YOK (kapı Gönder tıkında)', !preg_match('/function taslakOnayAc\(t\) \{[^}]{0,200}fiyatPenceresiAc/s', $app));

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
