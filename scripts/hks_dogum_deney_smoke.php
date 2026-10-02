<?php
// =========================================================
// scripts/hks_dogum_deney_smoke.php — Doğum tarihi biçim deneyi + teşhis kaydı
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: hks_kv_oku/hks_kv_yaz
// bellek içi taklitle tanımlanır ve halkayit/dogum_deney_lib.php'nin GERÇEK
// fonksiyonları çağrılır. api.php / dogum_deney.php akış kuralları kaynakta
// (statik) denetlenir. Plan: docs/HKS_MERNIS_ILK_KAYIT_ANALIZ.md §10.
//
//   php scripts/hks_dogum_deney_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['__KV'] = [];
function hks_kv_oku($anahtar, $varsayilan = null) {
    return array_key_exists($anahtar, $GLOBALS['__KV'])
        ? json_decode($GLOBALS['__KV'][$anahtar], true) : $varsayilan;
}
function hks_kv_yaz($anahtar, $veri) {
    $GLOBALS['__KV'][$anahtar] = json_encode($veri, JSON_UNESCAPED_UNICODE);
}

require_once __DIR__ . '/../halkayit/dogum_deney_lib.php';

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-70s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}
// Algoritmayı geçen TC üret — örneklerde gerçek kişi yok.
function tc_uret(string $govde9): string {
    $d = array_map('intval', str_split($govde9));
    $tek = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $cift = $d[1] + $d[3] + $d[5] + $d[7];
    $d10 = ((($tek * 7) - $cift) % 10 + 10) % 10;
    $d11 = (array_sum($d) + $d10) % 10;
    return $govde9 . $d10 . $d11;
}
$TC1 = tc_uret('123456789');
$TC2 = tc_uret('234567891');

echo "── Deney kurma / okuma / tüketme ──\n";
ok('başlangıçta deney yok', hks_dogum_deney_oku() === null);
ok('geçersiz biçim reddedilir', hks_dogum_deney_kur('kotu', $TC1) !== null);
ok('geçersiz TC reddedilir', hks_dogum_deney_kur('iso_oglen', '12345678901') !== null);
ok('geçerli deney kurulur', hks_dogum_deney_kur('iso_oglen', $TC1, 'test') === null);
$d = hks_dogum_deney_oku();
ok('kurulu deney okunur (biçim)', is_array($d) && $d['bicim'] === 'iso_oglen');
ok('yalnız son 4 hane gösterilir', ($d['tcSon4'] ?? '') === '***' . substr($TC1, -4));
ok('TAM TC kv\'ye YAZILMAZ (kişisel veri)', !str_contains($GLOBALS['__KV']['dogum_deney'], $TC1));
ok('aynı TC eşleşir', hks_dogum_deney_bu_tc($TC1) === 'iso_oglen');
ok('boşluklu/biçimli aynı TC de eşleşir', hks_dogum_deney_bu_tc(substr($TC1, 0, 3) . ' ' . substr($TC1, 3)) === 'iso_oglen');
ok('başka TC eşleşmez', hks_dogum_deney_bu_tc($TC2) === null);
ok('bu_tc deneyi TÜKETMEZ', hks_dogum_deney_oku() !== null);
$__eski = json_decode($GLOBALS['__KV']['dogum_deney'], true);
$__eski['ts'] = time() - HKS_DOGUM_DENEY_SURE_SN - 5;
hks_kv_yaz('dogum_deney', $__eski);
ok('süresi dolan deney yok sayılır', hks_dogum_deney_oku() === null && hks_dogum_deney_bu_tc($TC1) === null);
hks_dogum_deney_kur('gtb_tarih', $TC1);
hks_dogum_deney_iptal();
ok('iptal sonrası deney yok', hks_dogum_deney_oku() === null);

echo "\n── Öğrenilen biçim (yalnız kanıtlı kayıt) ──\n";
hks_kv_yaz('dogum_varyant', ['konum' => 'son', 'bicim' => 'gtb', 'kanit' => 'GTBWSRV0000002']);
ok('eski merdivenin KANITSIZ kaydı yok sayılır', hks_dogum_varyant_ogrenilen() === null);
ok('kanıtsız kayıtta yürürlük config/gtb', hks_dogum_varyant_coz(null)['bicim'] === 'gtb');
hks_dogum_bicim_ogren('iso_oglen', 'kunye 123');
ok('kanıtlı öğrenme okunur', hks_dogum_varyant_ogrenilen() === ['konum' => 'alfabetik', 'bicim' => 'iso_oglen']);
ok('yürürlükteki biçim öğrenilen olur', hks_dogum_varyant_coz(null)['bicim'] === 'iso_oglen');
ok('çağrı biçimi öğrenilenin önüne geçer (deney)', hks_dogum_varyant_coz(['bicim' => 'gtb_tarih'])['bicim'] === 'gtb_tarih');
hks_dogum_bicim_ogren('kotu', 'x');
ok('geçersiz biçim öğrenilmez', hks_dogum_varyant_ogrenilen()['bicim'] === 'iso_oglen');
hks_kv_yaz('dogum_varyant', null);
ok('sıfırlama sonrası öğrenilen yok', hks_dogum_varyant_ogrenilen() === null);

echo "\n── Sonuç sınıflandırma ──\n";
ok('künye', hks_dogum_sonuc_sinifi(['genelHata' => null, 'sonuclar' => [['yeniKunyeNo' => '100285', 'hataKodu' => 0, 'mesaj' => '']]]) === 'kunye');
ok('satır düzeyi Mernis (HataKodu 21)', hks_dogum_sonuc_sinifi(['genelHata' => 'GTBWSRV0000002',
    'sonuclar' => [['yeniKunyeNo' => '0', 'hataKodu' => 21, 'mesaj' => 'Tc kimlik numarası Mernis sisteminde bulunamadı']]]) === 'mernis');
ok('girilmelidir (zarf, satırsız)', hks_dogum_sonuc_sinifi(['genelHata' => 'X kişi/kişiler doğum tarihi girilmelidir.', 'sonuclar' => []]) === 'girilmelidir');
ok('diğer', hks_dogum_sonuc_sinifi(['genelHata' => 'İhracat Üreticiden Sevk Alım bildirimi yapamaz', 'sonuclar' => []]) === 'diger');

echo "\n── Teşhis kaydı (halka tampon) ──\n";
for ($i = 0; $i < HKS_DOGUM_KAYIT_LIMIT + 5; $i++) hks_dogum_deneme_kaydet(['sira' => $i, 'tc' => hks_tc_son4($TC1)]);
$l = hks_dogum_denemeleri();
ok('en çok ' . HKS_DOGUM_KAYIT_LIMIT . ' kayıt tutulur', count($l) === HKS_DOGUM_KAYIT_LIMIT, (string)count($l));
ok('en yeni başta', ($l[0]['sira'] ?? -1) === HKS_DOGUM_KAYIT_LIMIT + 4);
ok('kayda zaman eklenir', !empty($l[0]['zaman']));
ok('kayıtta tam TC yok', !str_contains($GLOBALS['__KV']['dogum_denemeleri'], $TC1));

echo "\n── Akış kuralları (kaynak denetimi) ──\n";
$KOK = dirname(__DIR__);
$api = (string)file_get_contents("$KOK/halkayit/api.php");
$pTuket = strpos($api, 'hks_dogum_deney_iptal();');
$pGonder = strpos($api, '$sonuc = hks_bildirim_kaydet($cfg, $satirlar, $ortak, $__secenek);');
ok('api.php deneyi ve seçenekleri hks_bildirim_kaydet\'e veriyor', $pGonder !== false);
ok('deney gönderimden ÖNCE tüketiliyor (tek kullanımlık)', $pTuket !== false && $pGonder !== false && $pTuket < $pGonder);
$pKayitsiz = strpos($api, 'if ($__kd === HKS_DURUM_NOT_REGISTERED) {');
ok('deney + adres yalnız KAYITSIZ dalında uygulanıyor',
    $pKayitsiz !== false && $pTuket > $pKayitsiz && strpos($api, 'hks_isyeri_adres_bul(') > $pKayitsiz);
ok('deney kurulu + kişi KAYITSIZ değil → 409, taslak geri konur',
    (bool)preg_match('/__deneyBicim !== null && \$__kd !== HKS_DURUM_NOT_REGISTERED\) \{\s*\$taslagiGeriKoy\(\);/', $api));
ok('belirsiz sonuç (istisna) da kayda düşüyor', str_contains($api, "'sonuc' => 'belirsiz'"));
$ekran = (string)file_get_contents("$KOK/halkayit/dogum_deney.php");
ok('deney ekranı yalnız yöneticiye', str_contains($ekran, 'if (!is_admin())'));
ok('deney ekranı POST\'ta CSRF denetliyor', str_contains($ekran, 'csrf_check($_POST[\'csrf\'] ?? null);'));
ok('deney ekranı audit yazıyor', substr_count($ekran, "audit_log_event('update', 'hks_dogum_deney'") === 3);
ok('deney ekranı HKS\'e gönderim yapmıyor', !preg_match('/=\s*hks_bildirim_kaydet(_tek)?\(|hks_soap_cagir\(/', $ekran));
$ht = (string)file_get_contents("$KOK/halkayit/.htaccess");
ok('.htaccess dogum_deney_lib.php\'yi kapatıyor (iki sözdizimi)', substr_count($ht, 'dogum_deney_lib') >= 2);

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
