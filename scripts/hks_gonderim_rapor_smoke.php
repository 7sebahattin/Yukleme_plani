<?php
// =========================================================
// scripts/hks_gonderim_rapor_smoke.php — Hal Kayıt Gönderim Raporu (v494)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: bellek içi SQLite +
// halkayit/gonderim_rapor_lib.php'nin GERÇEK fonksiyonları (+ tekrar_gonder_lib.php
// kuralları). Sayfa alt süreçte gerçekten render edilir (hks_gonderim_rapor_render.php).
// Örnekler sentetiktir (_hks_gonderim_rapor_fixture.php).
//
//   php scripts/hks_gonderim_rapor_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);
require_once "$KOK/halkayit/tekrar_gonder_lib.php";
require_once "$KOK/halkayit/gonderim_rapor_lib.php";
require_once __DIR__ . '/_hks_gonderim_rapor_fixture.php';

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-80s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}
$j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
hks_grapor_test_kur($db);
$T = 'hks_gonderilenler';
$BUGUN = '2026-10-20';

// ── 1. Filtre ayrıştırma ──
$f = hks_grapor_filtre([], $BUGUN);
ok('filtre: varsayılan bas = ayın 1i, bit = bugün, grup = ulke', $f['bas'] === '2026-10-01' && $f['bit'] === $BUGUN && $f['grup'] === 'ulke' && !$f['notlar'], $j($f));
$f = hks_grapor_filtre(['bas' => '2026-10-10', 'bit' => '2026-11-30'], $BUGUN);
ok('filtre: gelecek bitiş bugüne çekilir (+not)', $f['bit'] === $BUGUN && $f['notlar'], $j($f));
$f = hks_grapor_filtre(['bas' => '2026-10-15', 'bit' => '2026-10-02'], $BUGUN);
ok('filtre: bas > bit → yer değiştirir', $f['bas'] === '2026-10-02' && $f['bit'] === '2026-10-15', $j($f));
$f = hks_grapor_filtre(['bas' => '2024-01-01', 'bit' => '2026-10-20'], $BUGUN);
$gun = (strtotime($f['bit']) - strtotime($f['bas'])) / 86400 + 1;
ok('filtre: aralık en çok 366 gün (başlangıç kırpılır)', (int)round($gun) === 366 && $f['notlar'], $j($f));
$f = hks_grapor_filtre(['bas' => '2026-02-30', 'bit' => 'x', 'grup' => 'evil', 'tur' => 'yok', 'firma' => "f1' OR 1=1", 'plaka' => ' 33 abc-01 ', 'ulke' => ['dizi']], $BUGUN);
ok('filtre: geçersiz tarih/grup/tür/firma/dizi → varsayılan', $f['bas'] === '2026-10-01' && $f['bit'] === $BUGUN && $f['grup'] === 'ulke'
   && $f['tur'] === '' && $f['firma'] === '' && $f['ulke'] === '', $j($f));
ok('filtre: plaka normalize (büyük harf, yalnız harf/rakam)', $f['plaka'] === '33ABC01', $f['plaka']);
ok('plaka_norm: "33 ncn-99" → 33NCN99', hks_grapor_plaka_norm(' 33 ncn-99 ') === '33NCN99');

// ── 2. Tür / ad ayrıştırma (app.html gonderilenUlkeParcala aynası) ──
$t = hks_grapor_tur_coz('Rusya', 'SATIS');
ok('tür: öneksiz → İhracat, ad = metin', $t['kod'] === 'ihracat' && $t['etiket'] === 'İhracat' && $t['ad'] === 'Rusya', $j($t));
ok('tür: Satın Alım ← ', hks_grapor_tur_coz('Satın Alım ← Ali', null)['kod'] === 'alim');
ok('tür: Üreticiden Sevk Alım ← ', hks_grapor_tur_coz('Üreticiden Sevk Alım ← X', null)['etiket'] === 'Üretici sevk');
ok('tür: Yurt içi → + SEVK_ETME = Sevk etme', hks_grapor_tur_coz('Yurt içi → Y', 'SEVK_ETME')['kod'] === 'sevk');
ok('tür: Yurt içi → + SATIS = Yurt içi satış', hks_grapor_tur_coz('Yurt içi → Y', 'SATIS')['kod'] === 'satis');
ok('tür: Yurt içi → + kod yok = Yurt içi', hks_grapor_tur_coz('Yurt içi → Y', null)['kod'] === 'yurtici');
ok('tür: " (İl/İlçe)" adres eki gruplama için atılır', hks_grapor_tur_coz('Yurt içi → Örnek Firma (Mersin/Merkez)', 'SEVK_ETME')['ad'] === 'Örnek Firma');
ok('tür: boş metin → etiket boş', hks_grapor_tur_coz('', null)['etiket'] === '');

// ── 3. Okuma + gruplama (tüm firmalar, 01–20.10) ──
$f = hks_grapor_filtre(['bas' => '2026-10-01', 'bit' => '2026-10-20'], $BUGUN);
$r = hks_grapor_rapor($db, $T, $f);
$idler = array_column($r['satirlar'], 'id');
sort($idler);
ok('aralık: 01.10 00:00 – 20.10 23:59:59 dahil; 15.09 ve 21.10 00:00 hariç', $idler === ['g1', 'g11', 'g2', 'g3', 'g4', 'g5', 'g6', 'g8', 'g9'], $j($idler));
$byId = array_column($r['satirlar'], null, 'id');
ok('TC/VKN → Kişi Havuzu adı (hks_gonderilen_ulke_isimle)', $byId['g3']['ad'] === 'Örnek Müstahsil' && $byId['g3']['tur'] === 'alim', $j($byId['g3']));
ok('kısmi hatalı kayıt: gerçek toplam veri.sonuclar\'dan (20.000 kg / 2 künye)', $byId['g5']['kg'] == 20000 && $byId['g5']['adet'] === 2 && $byId['g5']['durum'] === 'kismi', $j($byId['g5']));
ok('tamamen başarısız: durum basarisiz, kg/rüsum 0', $byId['g4']['durum'] === 'basarisiz' && $byId['g4']['kg'] == 0 && $byId['g4']['rusum'] == 0, $j($byId['g4']));
$g = $r['gruplar'];
ok('grup ulke: kg azalan sıra', array_column($g, 'ad') === ['Rusya', 'Irak', 'Örnek Müstahsil', 'Örnek Firma', 'Üretici X'], $j(array_column($g, 'ad')));
ok('grup Rusya: 4 gönderim · 7 künye · 47.333 kg · 205 TL', $g[0]['gonderim'] === 4 && $g[0]['kunye'] === 7 && $g[0]['kg'] == 47333 && $g[0]['rusum'] == 205, $j($g[0]));
ok('grup Irak: başarısız toplama GİRMEZ, hatalı = 1', $g[1]['gonderim'] === 1 && $g[1]['kunye'] === 2 && $g[1]['kg'] == 20000 && $g[1]['hatali'] === 1 && $g[1]['rusum'] == 50, $j($g[1]));
$t = $r['toplam'];
ok('genel toplam: 8 gönderim · 12 künye · 73.833 kg · 275 TL · 1 hatalı', $t['gonderim'] === 8 && $t['kunye'] === 12 && $t['kg'] == 73833 && $t['rusum'] == 275 && $t['hatali'] === 1, $j($t));
ok('grup toplamları = genel toplam', array_sum(array_column($g, 'kg')) == $t['kg'] && array_sum(array_column($g, 'gonderim')) === $t['gonderim']);

// ── 4. Firma izolasyonu (Gönderilenler listesiyle aynı) ──
$f1 = hks_grapor_filtre(['bas' => '2026-10-01', 'bit' => '2026-10-20', 'firma' => 'f1'], $BUGUN);
$r1 = hks_grapor_rapor($db, $T, $f1, 'Firma A');
$i1 = array_column($r1['satirlar'], 'id'); sort($i1);
ok('firma f1: firma_id eşleşir + firma_id\'siz eski kayıt AYNI ADLA (g8); başka firma/ad (g6, g11) yok', $i1 === ['g1', 'g2', 'g3', 'g4', 'g5', 'g8', 'g9'], $j($i1));
ok('firma f1: toplam 66.500 kg', $r1['toplam']['kg'] == 66500, (string)$r1['toplam']['kg']);
$r2 = hks_grapor_rapor($db, $T, hks_grapor_filtre(['bas' => '2026-10-01', 'bit' => '2026-10-20', 'firma' => 'f9'], $BUGUN), '');
ok('bilinmeyen firma: yalnız firma_id eşleşir (boş)', $r2['satirlar'] === []);

// ── 5. Süzgeçler ──
$s = fn(array $ek) => hks_grapor_rapor($db, $T, hks_grapor_filtre(['bas' => '2026-10-01', 'bit' => '2026-10-20'] + $ek, $BUGUN));
$x = $s(['plaka' => '33abc']);
ok('plaka "33abc" (içerir, normalize): "33 abc-01" de eşleşir', array_column($x['satirlar'], 'id') === ['g11', 'g5', 'g2', 'g1'], $j(array_column($x['satirlar'], 'id')));
ok('ülke "rusya" (harf duyarsız tam)', count($s(['ulke' => 'rusya'])['satirlar']) === 4);
ok('ürün "LİMON" (TR İ)', count($s(['urun' => 'LİMON'])['satirlar']) === 2);
ok('tür sevk → yalnız g8', array_column($s(['tur' => 'sevk'])['satirlar'], 'id') === ['g8']);
ok('tür uretici → yalnız g9', array_column($s(['tur' => 'uretici'])['satirlar'], 'id') === ['g9']);
ok('tür ihracat → 6 kayıt', count($s(['tur' => 'ihracat'])['satirlar']) === 6);
$pg = $s(['grup' => 'plaka'])['gruplar'];
ok('grup plaka: "33 abc-01" ile "33ABC01" aynı grup (63.333 kg)', $pg[0]['ad'] === '33ABC01' && $pg[0]['gonderim'] === 4 && $pg[0]['kg'] == 63333, $j($pg[0]));
$bos = array_values(array_filter($pg, fn($x) => $x['ad'] === '(boş)'));
ok('grup plaka: plakasız → "(boş)" 1.500 kg', $bos && $bos[0]['kg'] == 1500, $j($bos));
$fg = $s(['grup' => 'firma'])['gruplar'];
ok('grup firma: Firma A, Firma B, Firma C', array_column($fg, 'ad') === ['Firma A', 'Firma B', 'Firma C'], $j(array_column($fg, 'ad')));
$ug = $s(['grup' => 'urun'])['gruplar'];
ok('grup ürün: Nar 68.833 / Limon 5.000 + 1 hatalı', $ug[0]['ad'] === 'Nar' && $ug[0]['kg'] == 68833 && $ug[1]['ad'] === 'Limon' && $ug[1]['hatali'] === 1, $j($ug));
$sec = $s(['ulke' => 'rusya'])['secenekler'];
ok('seçenekler süzgeçten ÖNCEKİ kapsamdan (ülke filtresine rağmen tüm ülkeler)', in_array('Irak', $sec['ulke'], true) && in_array('Örnek Müstahsil', $sec['ulke'], true), $j($sec['ulke']));
ok('seçenekler: ürün + plaka + tür', $sec['urun'] === ['Limon', 'Nar'] && in_array('33ABC01', $sec['plaka'], true) && isset($sec['tur']['sevk']) && !isset($sec['tur']['satis']), $j($sec));

// ── 6. Limit / kesildi ──
$k = hks_grapor_oku($db, $T, $f, '', 3);
ok('limit 3: kesildi bayrağı + en yeni 3 kayıt', $k['kesildi'] === true && array_column($k['satirlar'], 'id') === ['g9', 'g11', 'g8'], $j(array_column($k['satirlar'], 'id')));
ok('limit yeterli: kesildi false', hks_grapor_oku($db, $T, $f, '', 50)['kesildi'] === false);

// ── 7. Biçim + XLSX ──
ok('kg biçimi: 73833.4 → "73.833" (virgülsüz)', hks_grapor_kg(73833.4) === '73.833' && hks_grapor_kg(500) === '500');
ok('tutar biçimi: 1234.5 → "1.234,50"', hks_grapor_tl(1234.5) === '1.234,50');
$xs = hks_grapor_xlsx_sayfalar($r, $f, '');
ok('xlsx: iki sayfa Özet + Ayrıntı', count($xs) === 2 && $xs[0]['ad'] === 'Özet' && $xs[1]['ad'] === 'Ayrıntı');
ok('xlsx Özet: kg sütunu topla=true, satır sayısı = grup sayısı', $xs[0]['sutunlar'][3]['tip'] === 'kg' && !empty($xs[0]['sutunlar'][3]['topla']) && count($xs[0]['satirlar']) === 5);
ok('xlsx Ayrıntı: satır başına 11 hücre, başarısız "Hatalı"', count($xs[1]['satirlar']) === 9 && count($xs[1]['satirlar'][0]) === 11
   && in_array('Hatalı', array_column($xs[1]['satirlar'], 10), true));
if (is_file("$KOK/vendor/autoload.php")) {
    require_once "$KOK/config/xlsx_export.php";
    $ss = xlsx_olustur($xs, ['baslik' => 'Gönderim Raporu']);
    $vals = [];
    foreach ($ss->getSheet(0)->toArray(null, false, false) as $row) foreach ($row as $c) if ($c !== null) $vals[] = (string)$c;
    ok('xlsx üretimi: Özet sayfasında "Rusya" + SUBTOTAL toplam formülü', in_array('Rusya', $vals, true)
       && (bool)array_filter($vals, fn($v) => str_contains($v, 'SUBTOTAL')), $j(array_slice($vals, 0, 12)));
    ok('xlsx sekme adları', $ss->getSheet(0)->getTitle() === 'Özet' && $ss->getSheet(1)->getTitle() === 'Ayrıntı');
} else {
    echo "(vendor yok — xlsx üretimi atlandı)\n";
}

// ── 8. Statik denetim ──
$sayfa = (string)file_get_contents("$KOK/halkayit/gonderim_rapor.php");
$lib = (string)file_get_contents("$KOK/halkayit/gonderim_rapor_lib.php");
ok('sayfa: require_login + require_perm(\'hks.access\')', str_contains($sayfa, 'require_login()') && str_contains($sayfa, "require_perm('hks.access')"));
$xp = strpos($sayfa, "require_perm('reports.export')"); $xi = strpos($sayfa, 'xlsx_indir('); $rh = strpos($sayfa, "render_header('Gönderim Raporu')");
ok('sayfa: XLSX kolu reports.export ister, xlsx_indir render_header\'dan ÖNCE', $xp !== false && $xi !== false && $rh !== false && $xp < $xi && $xi < $rh);
ok('sayfa: export_audit + XLSX düğmesi can(\'reports.export\') kapılı', str_contains($sayfa, "export_audit('hks', 'gonderim_rapor', 'xlsx'") && str_contains($sayfa, "if (can('reports.export')):"));
ok('sayfa: CSV kolu YOK', !preg_match('/fputcsv|text\/csv|csv=1/i', $sayfa));
foreach (['sayfa' => $sayfa, 'lib' => $lib] as $ad => $kod) {
    ok("$ad: yazma SQL'i yok (INSERT/UPDATE/DELETE/ALTER)", !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\S+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE)\b/i', $kod));
    ok("$ad: POST/CSRF yolu yok", !str_contains($kod, '$_POST') && !str_contains($kod, 'csrf_check'));
}
ok('sayfa: no-store başlığı', str_contains($sayfa, "header('Cache-Control: no-store, private')"));
ok('sayfa: satır içi onclick yok, hk-page sınıfı yok', !preg_match('/\sonclick=/i', $sayfa) && !str_contains($sayfa, 'hk-page'));
ok('sayfa: <tfoot> yok (TOPLAM tbody sonunda)', !str_contains($sayfa, '<tfoot'));
// echo edilen kullanıcı verisi h() ile
preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $sayfa, $m);
$kacissiz = array_filter($m[1], fn($e) => !preg_match('/^(h\(|\(int\)|\$__k === |\(string\)\$__id === |\$gr_secili\(|\$__s\[\'durum\'\] === )/', $e));
ok('sayfa: tüm <?= ?> çıktıları h()/int/seçim ifadesi', !$kacissiz, $j(array_values($kacissiz)));
ok('lib: hks_gonderilen_ ile başlayan YENİ fonksiyon yok', !preg_match('/function\s+hks_gonderilen_/', $lib));
ok('lib: hks_gonderilen_gercek_toplam + ulke_isimle + adlari kullanır (kopya değil)', str_contains($lib, 'hks_gonderilen_gercek_toplam(') && str_contains($lib, 'hks_gonderilen_ulke_isimle(') && str_contains($lib, 'hks_gonderilen_adlari('));
$ht = (string)file_get_contents("$KOK/halkayit/.htaccess");
ok('.htaccess: lib iki FilesMatch listesinde kapalı', substr_count($ht, 'gonderim_rapor_lib') >= 3 && preg_match_all('/FilesMatch "\^\([^"]*\|gonderim_rapor_lib\)/', $ht) === 2);
ok('.htaccess: sayfa (gonderim_rapor.php) kapalı listede DEĞİL', !preg_match('/\|gonderim_rapor\)/', $ht));
$sw = (string)file_get_contents("$KOK/sw.js");
ok('sw.js: gonderim_rapor.php önbellek dışı', str_contains($sw, '/\/halkayit\/gonderim_rapor\.php(\/|$)/.test(u.pathname)) return;'));
preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", $sw, $cm);
preg_match("/define\('APP_SURUM', '(v\d+)'\)/", (string)file_get_contents("$KOK/config/helpers.php"), $am);
ok('sürüm: APP_SURUM = CACHE_NAME', ($cm[1] ?? 'a') === ($am[1] ?? 'b'), ($cm[1] ?? '?') . ' / ' . ($am[1] ?? '?'));
$app = (string)file_get_contents("$KOK/halkayit/app.html");
ok('app.html: Gönderilenler başlığında "📊 Rapor" (target=_top) + firma JS', str_contains($app, 'id="gonderilenRapor" href="gonderim_rapor.php" target="_top"') && str_contains($app, "\$('gonderilenRapor').addEventListener('click'"));

// ── 9. Sayfa render (alt süreç; gerçek sayfa kodu) ──
$render = function (string $qs, string $perms) use ($KOK): array {
    $cmd = 'GR_QS=' . escapeshellarg($qs) . ' GR_PERMS=' . escapeshellarg($perms) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$KOK/scripts/hks_gonderim_rapor_render.php") . ' 2>&1';
    $out = []; exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
};
[$rc, $html] = $render('bas=2026-10-01&bit=2026-10-20', 'hks.access,reports.export');
ok('render: çıkış 0, PHP uyarısı yok', $rc === 0 && !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)/', $html), substr($html, 0, 300));
ok('render: başlık + geri düğmesi + özet/ayrıntı tabloları', str_contains($html, '📊 Gönderim Raporu') && str_contains($html, 'href="index.php">← Hal Kayıt')
   && str_contains($html, 'id="grOzet"') && str_contains($html, 'id="grAyrinti"'));
ok('render: TOPLAM satırı 73.833 kg / 275,00', (bool)preg_match('#<tr class="gr-toplam">\s*<td>TOPLAM</td>\s*<td class="gr-s">8</td>\s*<td class="gr-s">12</td>\s*<td class="gr-s">73\.833</td>\s*<td class="gr-s">275,00</td>#', $html));
ok('render: XLSX bağlantısı (reports.export) query taşır', str_contains($html, 'gonderim_rapor.php?bas=2026-10-01&amp;bit=2026-10-20&amp;grup=ulke&amp;xlsx=1'));
ok('render: firma adı kaçırılır (XSS)', str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($html, '<img src=x'));
ok('render: no-store başlığı gönderildi', str_contains($html, 'Cache-Control: no-store, private'));
ok('render: hatalı satır işaretli', str_contains($html, 'class="gr-hatali"') && str_contains($html, '1 hatalı'));
[$rc2, $html2] = $render('bas=2026-10-01&bit=2026-10-20&grup=plaka&plaka=33abc', 'hks.access');
ok('render (export yok): XLSX bağlantısı YOK, plaka grubu', $rc2 === 0 && !str_contains($html2, 'xlsx=1') && str_contains($html2, '<td>33ABC01')
   && str_contains($html2, 'value="33ABC01"'), substr($html2, 0, 200));
[$rc3, $html3] = $render('bas=2026-10-01&bit=2026-10-20&ulke=Yok', 'hks.access');
ok('render: boş sonuç mesajı', $rc3 === 0 && str_contains($html3, 'Bu filtreyle gönderim bulunamadı.'));
[$rc4, $html4] = $render('bas=2026-10-01&bit=2026-10-20', 'reports.read');
ok('render: hks.access yoksa reddedilir', $rc4 !== 0 || str_contains($html4, 'forbidden: hks.access'));

echo $fail === 0 ? "\nTÜM TESTLER GEÇTİ\n" : "\n$fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
