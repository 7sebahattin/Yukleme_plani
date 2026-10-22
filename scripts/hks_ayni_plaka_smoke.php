<?php
// =========================================================
// scripts/hks_ayni_plaka_smoke.php — Hal Kayıt "aynı gün aynı plaka" uyarısı (v416)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: bellek içi SQLite +
// halkayit/tekrar_gonder_lib.php'nin GERÇEK hks_bugun_ayni_plaka() / hks_plaka_norm().
// api.php uç kuralları kaynakta (statik) denetlenir. Örnekler sentetiktir.
//
//   php scripts/hks_ayni_plaka_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);
require_once "$KOK/halkayit/tekrar_gonder_lib.php";

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-74s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE g (id TEXT PRIMARY KEY, zaman TEXT, firma_id TEXT, firma_ad TEXT, plaka TEXT,
  belge_no TEXT, ulke_ad TEXT, urun_ad TEXT, toplam_kg REAL)');
$ekle = $db->prepare('INSERT INTO g VALUES (?,?,?,?,?,?,?,?,?)');
$GUN = '2026-10-21';
foreach ([
    ['g1', "$GUN 08:10:00", 'f1', 'Firma A', '33NCN99', '', 'Satın Alım ← Örnek Bir', 'Nar', 22000],
    ['g2', "$GUN 15:40:00", 'f1', 'Firma A', '33 ncn-99', '', 'Satın Alım ← Örnek İki', 'Nar', 21000],
    ['g3', "$GUN 09:00:00", 'f2', 'Firma B', '33NCN99', '', 'Rusya', 'Nar', 20000],   // başka firma
    ['g4', '2026-10-20 23:59:59', 'f1', 'Firma A', '33NCN99', '', 'Rusya', 'Nar', 1], // dün
    ['g5', '2026-10-22 00:00:00', 'f1', 'Firma A', '33NCN99', '', 'Rusya', 'Nar', 1], // yarın
    ['g6', "$GUN 10:00:00", '', 'Firma A', '33NCN99', '', 'Eski kayıt', 'Nar', 5000],  // eski: firma_id boş, ad aynı
    ['g7', "$GUN 10:00:00", '', 'Firma C', '33NCN99', '', 'Başka ad', 'Nar', 5000],    // eski: başka firma adı
    ['g8', "$GUN 11:00:00", 'f1', 'Firma A', '07ABC12', '', 'Rusya', 'Nar', 9000],
    ['g9', "$GUN 12:00:00", 'f1', 'Firma A', '', 'B-77', 'Rusya', 'Nar', 1680],
    ['g10', "$GUN 12:30:00", 'f1', 'Firma A', '06XYZ1', 'B-77', 'Rusya', 'Nar', 1680], // plakalı, belge aynı
] as $r) $ekle->execute($r);

$idler = fn(array $l) => implode(',', array_column($l, 'id'));

// --- plaka normalleştirme
ok('norm: boşluk/tire/küçük harf → "33NCN99"', hks_plaka_norm(' 33 ncn-99 ') === '33NCN99');
ok('norm: boş → ""', hks_plaka_norm('') === '' && hks_plaka_norm(null) === '');

// --- eşleşme
$l = hks_bugun_ayni_plaka($db, 'g', 'f1', 'Firma A', '33ncn 99', '', $GUN);
ok('aynı firma + aynı gün + yazımı farklı plaka: g2, g6, g1 (en yeni önce)', $idler($l) === 'g2,g6,g1', $idler($l));
ok('başka firma (g3), dün (g4), yarın 00:00 (g5), başka ad eski kayıt (g7) GİRMEZ',
   !array_intersect(['g3', 'g4', 'g5', 'g7'], array_column($l, 'id')));
ok('alanlar: zaman / ulkeAd / urunAd / toplamKg', $l[0]['zaman'] === "$GUN 15:40:00" && $l[0]['ulkeAd'] === 'Satın Alım ← Örnek İki'
   && $l[0]['urunAd'] === 'Nar' && $l[0]['toplamKg'] === 21000.0, json_encode($l[0], JSON_UNESCAPED_UNICODE));
ok('başka plaka → yalnız kendisi', $idler(hks_bugun_ayni_plaka($db, 'g', 'f1', 'Firma A', '07abc12', '', $GUN)) === 'g8');
ok('eşleşme yok → boş', hks_bugun_ayni_plaka($db, 'g', 'f1', 'Firma A', '99ZZZ99', '', $GUN) === []);
ok('plaka ve belge boş → sorgu yok, boş', hks_bugun_ayni_plaka($db, 'g', 'f1', 'Firma A', ' ', '', $GUN) === []);
// belge no: yalnız plakasız kayıtlarla
ok('plakasız + belge no → yalnız plakasız aynı belge (g9; plakalı g10 GİRMEZ)',
   $idler(hks_bugun_ayni_plaka($db, 'g', 'f1', 'Firma A', '', 'B-77', $GUN)) === 'g9');
ok('plaka doluysa belge no karşılaştırılmaz', $idler(hks_bugun_ayni_plaka($db, 'g', 'f1', 'Firma A', '06XYZ1', 'B-77', $GUN)) === 'g10');
ok('firma f2 kendi gönderimini görür', $idler(hks_bugun_ayni_plaka($db, 'g', 'f2', 'Firma B', '33NCN99', '', $GUN)) === 'g3');
$ekle->execute(['gb', date('Y-m-d H:i:s'), 'f1', 'Firma A', '01BGN01', '', 'Rusya', 'Nar', 1]);
$ekle->execute(['gd', date('Y-m-d', strtotime('-1 day')) . ' 12:00:00', 'f1', 'Firma A', '01BGN01', '', 'Rusya', 'Nar', 1]);
ok('gün verilmezse BUGÜN (dünkü aynı plaka girmez)', $idler(hks_bugun_ayni_plaka($db, 'g', 'f1', 'Firma A', '01BGN01', '')) === 'gb');

// --- api.php statik denetimler
$api = (string)file_get_contents("$KOK/halkayit/api.php");
$govde = '';
if (preg_match("/case 'ayni_plaka_bugun': \\{(.*?)\\n    \\}/s", $api, $m)) $govde = $m[1];
ok('api: ayni_plaka_bugun ucu var', $govde !== '');
ok('api: uç SALT OKUNUR (INSERT/UPDATE/DELETE/SOAP yok)', $govde !== '' && !preg_match('/\b(INSERT|UPDATE|DELETE)\b|hks_soap_cagir|hks_bildirim_kaydet/i', $govde));
ok('api: uç hks_bugun_ayni_plaka + gonderilenler tablosu + firma zorunlu',
   str_contains($govde, 'hks_bugun_ayni_plaka($db, hks_tablo(\'gonderilenler\')') && str_contains($govde, "'Firma seçilmedi.'"));
ok('api: taslaklar yanıtı ayniPlakaBugun taşır', str_contains($api, "'ayniPlakaBugun' => \$__plakaBugun"));
ok('api: taslak_gonder DEĞİŞMEDİ (uyarı gönderimi engellemez)',
   preg_match("/case 'taslak_gonder':.*?case /s", $api, $tg) === 1 && !str_contains($tg[0], 'ayni_plaka'));

// --- app.html statik denetimler
$app = (string)file_get_contents("$KOK/halkayit/app.html");
ok('app: rota POST /api/ayni_plaka → ayni_plaka_bugun', str_contains($app, "'POST /api/ayni_plaka': 'ayni_plaka_bugun'"));
ok('app: onay penceresi kontrolü çağırır', str_contains($app, 'ayniPlakaKontrol(t);'));
ok('app: kartta uyarı ayniPlakaBugun ile', str_contains($app, 't.ayniPlakaBugun > 0'));

echo $fail ? "\n$fail test BAŞARISIZ\n" : "\nTÜM TESTLER GEÇTİ\n";
exit($fail ? 1 : 0);
