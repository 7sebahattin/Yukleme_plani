<?php
// =========================================================
// scripts/hks_endpoint_test.php — Yeni HKS endpoint doğrulaması
//
// GTB 12.03.2025 duyurusu yeni endpoint adresleri yayımladı:
//   https://ws.gtb.gov.tr:8443/HKSBildirimService
//   https://ws.gtb.gov.tr:8443/HKSGenelService
//   https://ws.gtb.gov.tr:8443/HKSUrunService
// "27 Mart 2025 tarihine kadar mevcut endpointler ve yeni endpointler birlikte
// kullanılabilecektir." Kayıtsız ikinci kişide zorunlu olan "DogumTarihi" alanı
// büyük olasılıkla yalnız YENİ endpoint şemasında bulunuyor.
//
// Bu betik ESKİ ve YENİ endpoint'i AYNI salt-okunur çağrıyla karşılaştırır:
//   GenelServisUlkeler (ülke listesi)
//
// GÜVENLİK: Bu betik HKS'te KAYIT OLUŞTURMAZ, bildirim göndermez, rüsum
// doğurmaz. Yalnızca referans listesi okur — panelin "🔄 Listeler" butonunun
// yaptığı çağrının aynısı.
//
// KULLANIM (sunucuda, panelin kurulu olduğu dizinde):
//   php scripts/hks_endpoint_test.php <firmaId>
//   php scripts/hks_endpoint_test.php            → firma listesini gösterir
//
// Firma kimlik bilgileri DB'den (şifreli) okunur; ekrana YAZILMAZ.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Bu betik yalnız komut satırından çalıştırılabilir.\n"); exit(1); }

require_once __DIR__ . '/../halkayit/config.php';
require_once __DIR__ . '/../halkayit/db.php';
require_once __DIR__ . '/../halkayit/hks_soap.php';

$firmaId = $argv[1] ?? '';

// --- Firma seçimi ---
$db = hks_db();
hks_tablolari_hazirla();
if ($firmaId === '') {
    echo "Kullanım: php scripts/hks_endpoint_test.php <firmaId>\n\nKayıtlı firmalar:\n";
    foreach ($db->query('SELECT id, ad FROM ' . hks_tablo('firmalar') . ' ORDER BY olusturma') as $f) {
        printf("  %-20s %s\n", $f['id'], $f['ad']);
    }
    exit(0);
}
$st = $db->prepare('SELECT * FROM ' . hks_tablo('firmalar') . ' WHERE id = ?');
$st->execute([$firmaId]);
$f = $st->fetch();
if (!$f) { fwrite(STDERR, "Firma bulunamadı: {$firmaId}\n"); exit(1); }

$cfg = [
    'id' => $f['id'], 'ad' => $f['ad'], 'vergiNo' => $f['vergi_no'],
    'userName' => $f['user_name'],
    'password' => hks_coz($f['password_enc']),
    'servicePassword' => hks_coz($f['service_password_enc']),
];
echo "Firma: {$cfg['ad']}  (kimlik bilgileri DB'den okundu, ekrana yazılmaz)\n";
echo str_repeat('─', 72) . "\n";

// --- Tek bir endpoint kalıbını salt-okunur çağrıyla dene ---
// hks_endpoint() config sabitlerine bakar; burada kalıbı GEÇİCİ olarak zorlamak
// için doğrudan cURL ile aynı SOAP zarfını göndeririz (üretim kodunu değiştirmeden).
function endpointDene(string $etiket, string $kalip, array $cfg): bool {
    $url = sprintf($kalip, 'Genel');
    echo "\n▶ {$etiket}\n  URL: {$url}\n";

    // Panelin GenelServisUlkeler çağrısının birebir aynısı (salt-okunur).
    $istek = '<BaseRequestMessageOf_UlkelerIstek xmlns="' . HKS_SVC_NS . '">'
           . '<Istek/>'
           . '<Password>' . hks_esc($cfg['password']) . '</Password>'
           . '<ServicePassword>' . hks_esc($cfg['servicePassword']) . '</ServicePassword>'
           . '<UserName>' . hks_esc($cfg['userName']) . '</UserName>'
           . '</BaseRequestMessageOf_UlkelerIstek>';
    $zarf = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
          . $istek . '</s:Body></s:Envelope>';
    $action = HKS_SVC_NS . '/IGenelService/GenelServisUlkeler';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $zarf,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $action . '"',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $t0 = microtime(true);
    $cevap = curl_exec($ch);
    $sure = round((microtime(true) - $t0) * 1000);

    if ($cevap === false) {
        echo "  ✗ BAĞLANTI HATASI: " . curl_error($ch) . "  ({$sure} ms)\n";
        curl_close($ch);
        return false;
    }
    $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "  HTTP {$kod}  ({$sure} ms)\n";

    if ($kod !== 200) {
        // SOAP Fault gövdesi teşhis için değerlidir (credential İÇERMEZ — yalnız yanıt).
        echo "  ✗ Beklenmeyen HTTP kodu. Yanıt (ilk 400 karakter):\n    "
           . preg_replace('/\s+/', ' ', substr((string)$cevap, 0, 400)) . "\n";
        return false;
    }

    $duz  = hks_sadelestir($cevap);
    $hata = hks_islem_kontrol($duz);
    if ($hata) {
        echo "  ✗ SERVİS HATASI: {$hata}\n";
        return false;
    }
    $adet = count(hks_bloklar($duz, 'UlkeDTO'));
    if ($adet === 0) {
        echo "  ✗ Başarılı yanıt ama ülke listesi BOŞ — şema/namespace uyuşmuyor olabilir.\n"
           . "    Yanıt (ilk 400 karakter): "
           . preg_replace('/\s+/', ' ', substr($duz, 0, 400)) . "\n";
        return false;
    }
    echo "  ✓ ÇALIŞIYOR — {$adet} ülke döndü.\n";
    return true;
}

$eskiOk = endpointDene('ESKİ endpoint (hâlihazırda kullanılan)', HKS_ENDPOINT_ESKI, $cfg);
$yeniOk = endpointDene('YENİ endpoint (GTB 12.03.2025 duyurusu)', HKS_ENDPOINT_YENI, $cfg);

echo "\n" . str_repeat('─', 72) . "\n";
echo "SONUÇ:  eski=" . ($eskiOk ? 'ÇALIŞIYOR' : 'BAŞARISIZ')
   . "   yeni=" . ($yeniOk ? 'ÇALIŞIYOR' : 'BAŞARISIZ') . "\n\n";

if ($yeniOk) {
    echo "→ Yeni endpoint doğrulandı. Geçiş için halkayit/config.php içinde:\n";
    echo "     define('HKS_YENI_ENDPOINT', true);\n";
    echo "  Ardından bir bildirim göndermeden önce panelde '🔄 Listeler' ile\n";
    echo "  referans listelerini yeniden indirip her şeyin geldiğini doğrulayın.\n";
    if (!$eskiOk) {
        echo "\n  NOT: Eski endpoint artık cevap vermiyor — geçiş ACİL.\n";
    }
} else {
    echo "→ Yeni endpoint bu sunucudan doğrulanamadı. Olası nedenler:\n";
    echo "   • Sunucunun güvenlik duvarı 8443 portuna giden trafiği engelliyor\n";
    echo "     (kontrol: curl -v https://ws.gtb.gov.tr:8443/HKSGenelService)\n";
    echo "   • Gateway farklı bir SOAPAction/yol bekliyor (WSDL ile doğrulanmalı)\n";
    echo "   • Firmanın web servis yetkisi yeni endpoint'te tanımlı değil\n";
    echo "  config.php'deki HKS_YENI_ENDPOINT değerini false BIRAKIN.\n";
}
exit(($yeniOk || $eskiOk) ? 0 : 1);
