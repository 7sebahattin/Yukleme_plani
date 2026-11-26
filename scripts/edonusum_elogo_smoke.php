<?php
// =========================================================
// scripts/edonusum_elogo_smoke.php — e-Logo (Logo Özel Entegratör) SOAP 1.1 uçtan uca testi
//   php scripts/edonusum_elogo_smoke.php   → çıkış 0 = geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$ROOT = dirname(__DIR__);
define('EDONUSUM_CRED_KEY', 'test_elogo_master_key_32_bytes_ok!');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$__audit = [];
function db(): PDO { global $pdo; return $pdo; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'csrf-elogo-test'; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function active_depot(): ?string { return 'Merkez Depo'; }
function audit_log_event(string $a, string $m, $id = null, $o = null, $n = null): void {
    global $__audit;
    $__audit[] = ['action' => $a, 'module' => $m, 'id' => $id, 'new' => $n];
}

require_once $ROOT . '/config/db_tx_helpers.php';
require_once $ROOT . '/config/muhasebe_core.php';
require_once $ROOT . '/config/edonusum_core.php';
require_once $ROOT . '/config/muhasebe_ui.php';

$pass = 0; $fail = 0;
function ok(string $ad, bool $k, string $ip = ''): void {
    global $pass, $fail;
    if ($k) $pass++; else $fail++;
    echo sprintf("%-78s %s%s\n", $ad, $k ? 'OK' : '*** FAIL', $k ? '' : '  ' . $ip);
}

echo "=== 1. Şema & AES-256-GCM Şifreleme & SSRF Koruması ===\n";
$m1 = edonusum_migrate($pdo);
$m2 = edonusum_migrate($pdo);
ok('edonusum_migrate 3 tabloyu (zarflar, gelen, ayarlar) 2x idempotent kurar',
    count($m1) === 3 && edonusum_hazir($pdo) && $m2[2]['durum'] === 'var');

$enc = edonusum_sifre_sifrele('CokGizliElogoSifre!2026');
ok('AES-256-GCM şifreleme/çözme birebir çalışır',
    is_string($enc) && str_starts_with($enc, 'v1:') && edonusum_sifre_coz($enc) === 'CokGizliElogoSifre!2026');

$bozuk = substr((string)$enc, 0, -4) . 'AAAA';
ok('Değiştirilmiş (tamper) şifre metni reddedilir', edonusum_sifre_coz($bozuk) === null);

ok('SSRF koruması yalnız HTTPS *.elogo.com.tr / *.logo.com.tr kabul eder',
    edonusum_elogo_url_guvenli('https://pb.elogo.com.tr/PostBoxService.svc?singlewsdl') === 'https://pb.elogo.com.tr/PostBoxService.svc'
    && edonusum_elogo_url_guvenli('https://pb-demo.elogo.com.tr/PostBoxService.svc') === 'https://pb-demo.elogo.com.tr/PostBoxService.svc'
    && edonusum_elogo_url_guvenli('http://pb.elogo.com.tr/PostBoxService.svc') === null
    && edonusum_elogo_url_guvenli('https://169.254.169.254/latest/meta-data/') === null
    && edonusum_elogo_url_guvenli('https://evil.example.com/PostBoxService.svc') === null);

echo "\n=== 2. e-Logo Ayar Kaydetme & Sağlayıcı Çözümleme ===\n";
$badUrl = edonusum_ayar_kaydet($pdo, ['saglayici' => 'elogo', 'ortam' => 'test', 'servis_url' => 'https://kotu.site/svc'], 1);
ok('Geçersiz servis URL 422 ile reddedilir', !$badUrl['ok'] && ($badUrl['kod'] ?? 0) === 422);

$kay = edonusum_ayar_kaydet($pdo, [
    'saglayici'           => 'elogo',
    'ortam'               => 'test',
    'kullanici_adi'       => '1234567890',
    'sifre'               => 'ElogoWsPass2026!',
    'firma_vkn'           => '1234567890',
    'firma_unvan'         => 'ASYA FRESH TARIM LTD. ŞTİ.',
    'firma_vergi_dairesi' => 'KURUMLAR',
    'firma_il'            => 'ANTALYA',
    'firma_ilce'          => 'KEPEZ',
    'firma_adres'         => 'Hal Kompleksi No:12',
    'seri_efatura'        => 'ASY',
    'seri_earsiv'         => 'ASA',
    'seri_eirsaliye'      => 'ASI',
], 1);
ok('e-Logo ayarları DB\'ye şifreli kaydedilir', !empty($kay['ok']));

$uiAyar = edonusum_ayar_getir($pdo);
ok('edonusum_ayar_getir düz metin şifre DÖNDÜRMEZ (yalnız sifre_kayitli=true)',
    !array_key_exists('sifre', $uiAyar) && !array_key_exists('sifre_enc', $uiAyar) && !empty($uiAyar['sifre_kayitli']));

// Şifre boş bırakılarak tekrar kaydedildiğinde eski şifre korunmalı
edonusum_ayar_kaydet($pdo, [
    'saglayici' => 'elogo', 'ortam' => 'test', 'kullanici_adi' => '1234567890', 'sifre' => '',
    'firma_vkn' => '1234567890', 'firma_unvan' => 'ASYA FRESH TARIM LTD. ŞTİ.',
], 1);
$cozulu = edonusum_ayar_cozulu($pdo);
ok('Boş şifre alanı mevcut şifreli parolayı korur', ($cozulu['sifre'] ?? '') === 'ElogoWsPass2026!');

$otoProv = edonusum_provider(null, $pdo);
ok('edonusum_provider(null, $pdo) otomatik EdonusumElogoProvider döndürür',
    $otoProv instanceof EdonusumElogoProvider && $otoProv->ad() === 'elogo_test');

echo "\n=== 3. e-Logo SOAP 1.1 Tam Yaşam Döngüsü (Sahte Transport ile Çevrimdışı) ===\n";
$gelenEttn = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$gelenUbl = edonusum_ubl_xml_uret([
    'ettn'           => $gelenEttn,
    'belge_turu'     => 'efatura',
    'senaryo'        => 'TICARIFATURA',
    'belge_no'       => 'TED2026000000123',
    'tarih'          => '2026-11-20',
    'para_birimi'    => 'TRY',
    'gonderen_vkn'   => '9876543210',
    'gonderen_unvan' => 'TEDARİKÇİ GIDA A.Ş.',
    'alici_vkn'      => '1234567890',
    'alici_unvan'    => 'ASYA FRESH TARIM LTD. ŞTİ.',
    'ara_toplam'     => 5000.00,
    'kdv_toplam'     => 1000.00,
    'genel_toplam'   => 6000.00,
    'satirlar'       => [['ad' => 'Ambalaj Kasası', 'miktar' => 500, 'birim' => 'kasa', 'birim_fiyat' => 10, 'kdv_oran' => 20, 'tutar' => 5000]],
]);
$gelenZip = edonusum_zip_olustur($gelenUbl, $gelenEttn . '.xml');
$pdfZip   = edonusum_zip_olustur("%PDF-1.4\n%eLogoTestPDF\n%%EOF", 'belge.pdf');

$cagrilar = [];
$gonderilenZipHashOk = false;
$gonderilenXmlOk = false;
$appRespOk = false;

$mockTransport = static function (string $url, string $soapAction, string $envelope, string $metot) use (
    &$cagrilar, &$gonderilenZipHashOk, &$gonderilenXmlOk, &$appRespOk, $gelenEttn, $gelenZip, $pdfZip
): array {
    $cagrilar[] = $metot;
    if ($metot === 'Login') {
        return ['ok' => true, 'kod' => 200, 'govde' =>
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<LoginResponse xmlns="http://tempuri.org/"><LoginResult>true</LoginResult><sessionID>SESS-ELOGO-777</sessionID></LoginResponse>'
            . '</s:Body></s:Envelope>'];
    }
    if ($metot === 'Logout') {
        return ['ok' => true, 'kod' => 200, 'govde' =>
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><LogoutResponse xmlns="http://tempuri.org/"/></s:Body></s:Envelope>'];
    }
    if ($metot === 'GetValidateGIBUser') {
        return ['ok' => true, 'kod' => 200, 'govde' =>
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<GetValidateGIBUserResponse xmlns="http://tempuri.org/">'
            . '<GetValidateGIBUserResult><resultCode>1</resultCode><resultMsg>OK</resultMsg></GetValidateGIBUserResult>'
            . '<outputList xmlns:a="http://schemas.microsoft.com/2003/10/Serialization/Arrays">'
            . '<a:string>ISGIBUSER=1</a:string>'
            . '<a:string>PKALIAS=urn:mail:defaultpk@musteri.com.tr</a:string>'
            . '<a:string>GBALIAS=urn:mail:defaultgb@musteri.com.tr</a:string>'
            . '<a:string>TITLE=ÖRNEK MARKET A.Ş.</a:string>'
            . '</outputList></GetValidateGIBUserResponse></s:Body></s:Envelope>'];
    }
    if ($metot === 'SendDocument') {
        if (preg_match('/<efat:Value>([^<]+)<\/efat:Value>/', $envelope, $mVal)
            && preg_match('/<efat:hash>([^<]+)<\/efat:hash>/', $envelope, $mHash)) {
            $zBytes = base64_decode($mVal[1], true);
            if (is_string($zBytes) && md5($zBytes) === $mHash[1]) {
                $ac = edonusum_zip_ac($zBytes, 'xml');
                if (!empty($ac['ok'])) {
                    if (str_contains($envelope, 'DOCUMENTTYPE=APPLICATIONRESPONSE')) {
                        $appRespOk = str_contains((string)$ac['icerik'], '<ApplicationResponse') && str_contains((string)$ac['icerik'], '<cbc:ResponseCode>KABUL</cbc:ResponseCode>');
                    } else {
                        $gonderilenZipHashOk = true;
                        $gonderilenXmlOk = str_contains((string)$ac['icerik'], '<cbc:IssueTime>')
                            && str_contains((string)$ac['icerik'], '<cac:PostalAddress>')
                            && str_contains((string)$ac['icerik'], 'unitCode="KGM"');
                    }
                }
            }
        }
        return ['ok' => true, 'kod' => 200, 'govde' =>
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<SendDocumentResponse xmlns="http://tempuri.org/">'
            . '<SendDocumentResult><resultCode>1</resultCode><resultMsg>Kuyruğa alındı</resultMsg></SendDocumentResult>'
            . '<refId>991122</refId></SendDocumentResponse></s:Body></s:Envelope>'];
    }
    if ($metot === 'GetDocumentStatus') {
        return ['ok' => true, 'kod' => 200, 'govde' =>
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<GetDocumentStatusResponse xmlns="http://tempuri.org/">'
            . '<GetDocumentStatusResult><resultCode>1</resultCode><resultMsg>OK</resultMsg></GetDocumentStatusResult>'
            . '<statusInfo><code>1300</code><status>2</status><description>BAŞARIYLA TAMAMLANDI</description><envelopeId>ENV-UUID-1300</envelopeId></statusInfo>'
            . '</GetDocumentStatusResponse></s:Body></s:Envelope>'];
    }
    if ($metot === 'GetDocumentList') {
        return ['ok' => true, 'kod' => 200, 'govde' =>
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<GetDocumentListResponse xmlns="http://tempuri.org/">'
            . '<GetDocumentListResult><resultCode>1</resultCode><resultMsg>OK</resultMsg></GetDocumentListResult>'
            . '<docList><DocumentInfo><documentUuid>' . $gelenEttn . '</documentUuid></DocumentInfo></docList>'
            . '</GetDocumentListResponse></s:Body></s:Envelope>'];
    }
    if ($metot === 'GetDocumentData') {
        $isPdf = str_contains($envelope, 'DATAFORMAT=PDF');
        $b64 = base64_encode((string)($isPdf ? $pdfZip['zip'] : $gelenZip['zip']));
        return ['ok' => true, 'kod' => 200, 'govde' =>
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<GetDocumentDataResponse xmlns="http://tempuri.org/">'
            . '<GetDocumentDataResult><resultCode>1</resultCode><resultMsg>OK</resultMsg></GetDocumentDataResult>'
            . '<documentData><binaryData><Value>' . $b64 . '</Value></binaryData></documentData>'
            . '</GetDocumentDataResponse></s:Body></s:Envelope>'];
    }
    return ['ok' => false, 'kod' => 500, 'govde' => '', 'hata' => 'Bilinmeyen metot: ' . $metot];
};

$elogo = new EdonusumElogoProvider($cozulu, $mockTransport);

$bt = edonusum_baglanti_test($pdo, $elogo);
ok('edonusum_baglanti_test e-Logo Login+Logout başarısını doğrular ve DB\'ye yazar',
    !empty($bt['ok']) && (edonusum_ayar_getir($pdo)['son_test_durum'] ?? '') === 'ok');

$mk = edonusum_mukellef_sorgula($pdo, '1112223334', 'efatura', $elogo);
ok('GetValidateGIBUser mükellef kaydını + PK etiketini + ünvanı çözer',
    !empty($mk['ok']) && !empty($mk['kayitli']) && ($mk['posta_kutusu'] ?? '') === 'urn:mail:defaultpk@musteri.com.tr');

$zarf = edonusum_zarf_olustur($pdo, [
    'belge_turu'  => 'efatura',
    'tarih'       => '2026-11-26',
    'alici_vkn'   => '1112223334',
    'alici_unvan' => 'ÖRNEK MARKET A.Ş.',
    'ara_toplam'  => '1000,00',
    'kdv_toplam'  => '200,00',
    'satirlar'    => [['ad' => 'Salkım Domates', 'miktar' => 100, 'birim' => 'kg', 'birim_fiyat' => 10, 'kdv_oran' => 20, 'tutar' => 1000]],
], 1, $elogo);
ok('e-Logo üzerinden UBL-TR 1.2 taslak zarf oluşturulur (alici_pk dolu)', !empty($zarf['ok']) && !empty($zarf['id']));

$gond = edonusum_zarf_gonder($pdo, (int)$zarf['id'], 1, $elogo);
ok('SendDocument geçerli ZIP + MD5 + GİB UBL-TR (IssueTime/PostalAddress/KGM) ile gönderilir',
    !empty($gond['ok']) && $gonderilenZipHashOk && $gonderilenXmlOk && ($gond['zarf_uuid'] ?? '') === 'elogo-ref-991122');

$dur = edonusum_zarf_durum_sorgula($pdo, (int)$zarf['id'], $elogo);
ok('GetDocumentStatus 1300 kodu zarfı onaylandi durumuna geçirir',
    !empty($dur['ok']) && ($dur['durum'] ?? '') === 'onaylandi');

$sync1 = edonusum_gelen_senkronize($pdo, '2026-11-01', '2026-11-26', 'efatura', 1, $elogo);
$sync2 = edonusum_gelen_senkronize($pdo, '2026-11-01', '2026-11-26', 'efatura', 1, $elogo);
ok('GetDocumentList + GetDocumentData gelen faturayı ZIP+UBL çözüp 1 kez kaydeder (idempotent)',
    !empty($sync1['ok']) && ($sync1['eklenen'] ?? 0) === 1 && ($sync2['eklenen'] ?? 0) === 0 && ($sync2['atlanan'] ?? 0) === 1);

$gelenList = edonusum_gelen_listesi($pdo);
ok('Gelen faturanın gönderen VKN/ünvan/tutar bilgileri UBL-TR\'den doğru okundu',
    count($gelenList) === 1 && ($gelenList[0]['gonderen_vkn'] ?? '') === '9876543210' && abs((float)$gelenList[0]['genel_toplam'] - 6000.0) < 0.01);

$yan = edonusum_gelen_yanitla($pdo, (int)$gelenList[0]['id'], 'kabul', '', 1, $elogo);
ok('Ticari kabul yanıtı UBL-TR ApplicationResponse ZIP olarak SendDocument ile iletilir',
    !empty($yan['ok']) && ($yan['durum'] ?? '') === 'kabul' && $appRespOk);

$pdf = $elogo->belgePdfGetir((string)$zarf['ettn'], 'efatura');
ok('GetDocumentData (DATAFORMAT=PDF) sıkıştırılmış PDF verisini çözer',
    !empty($pdf['ok']) && str_starts_with((string)($pdf['pdf'] ?? ''), '%PDF-1.4'));

$loginSay  = count(array_filter($cagrilar, static fn(string $m): bool => $m === 'Login'));
$logoutSay = count(array_filter($cagrilar, static fn(string $m): bool => $m === 'Logout'));
ok('Her e-Logo Login oturumu finally bloğunda Logout ile kapatılır', $loginSay > 0 && $loginSay === $logoutSay, "login=$loginSay logout=$logoutSay");

echo "\n=== 4. UI Render & Statik Güvenlik ===\n";
$htmlAll = edonusum_render('giden', edonusum_zarf_listesi($pdo), $gelenList, [], [], true, $uiAyar, 'elogo_test', null, true)
         . edonusum_render('gelen', edonusum_zarf_listesi($pdo), $gelenList, [], [], true, $uiAyar, 'elogo_test', null, true)
         . edonusum_render('ayarlar', edonusum_zarf_listesi($pdo), $gelenList, [], [], true, $uiAyar, 'elogo_test', $mk + ['vkn' => '1112223334', 'tur' => 'efatura'], true);
ok('3 sekme (giden/gelen/ayarlar) temiz çizilir ve ham şifre HTML\'e SIZMAZ',
    str_contains($htmlAll, 'e-Logo TEST / DEMO')
    && str_contains($htmlAll, 'Bağlantıyı Test Et')
    && str_contains($htmlAll, 'e-Logo Gelen Kutusunu Çek')
    && !str_contains($htmlAll, 'ElogoWsPass2026!'));

$kaydetSrc = (string)file_get_contents($ROOT . '/muh_kaydet.php');
$elogoSrc  = (string)file_get_contents($ROOT . '/config/edonusum_elogo.php');
$xmlSrc    = (string)file_get_contents($ROOT . '/edonusum_xml.php');
ok('Statik güvenlik: direct-access guard, admin-only ayar kaydı, CURLOPT_SSL_VERIFYPEER ve nosniff',
    str_contains($elogoSrc, 'SCRIPT_FILENAME')
    && str_contains($elogoSrc, 'CURLOPT_SSL_VERIFYPEER => true')
    && str_contains($kaydetSrc, "case 'edonusum_ayar_kaydet':")
    && str_contains($kaydetSrc, 'if (!is_admin()) { forbidden(); }')
    && str_contains($xmlSrc, 'X-Content-Type-Options: nosniff'));

echo "\nÖZET: $pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
