<?php
// =========================================================
// scripts/siparis_denge_ui_smoke.php — "Fiyat Dengeleme" (v448) SAYFALARI: siparis_denge.php + siparis_cari.php B tablosu fatura sütunları
//   php scripts/siparis_denge_ui_smoke.php            (SIPARIS_DENGE_OUT=<klasör> → render'ları dosyaya döker: tarayıcı/ekran görüntüsü kontrolü)
// Bellek içi SQLite + stub auth + SENTETİK veri (TC810…, 34TST…; kişisel veri / gerçek fiyat YOK). Sayfalar GERÇEKTEN çalıştırılır
// (header()/exit kaynak üzerinde yakalanır); gerçek config/siparis_cari.php + config/siparis_denge.php kullanılır. Canlı DB'ye dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/_siparis_ortak.php';
require_once $ROOT . '/config/print_helpers.php';
require_once $ROOT . '/config/siparis_cari.php';
require_once $ROOT . '/config/siparis_denge.php';

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$db = db();
siparis_test_diger_tablolar($db);
siparis_test_sema_kur($db);

class SipExit extends RuntimeException {}
$SIPT = ['h' => [], 'kod' => 200];
function sipt_header(string $h, bool $replace = true, int $code = 0): void
{
    global $SIPT;
    if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $SIPT['h'][strtolower(trim($a))] = trim($b); }
    if ($code > 0) $SIPT['kod'] = $code;
}
function sipt_code(?int $c = null) { global $SIPT; if ($c !== null) $SIPT['kod'] = $c; return $SIPT['kod']; }
function sip_calistir(string $tmp): void { include $tmp; }
/** @return array{govde:string,kod:int,hata:?string} */
function uc(string $dosya, array $get = []): array
{
    global $ROOT, $SIPT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sipt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sipt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SipExit();', $src);
    $tmp = sys_get_temp_dir() . '/sip_denge_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $SIPT = ['h' => [], 'kod' => 200];
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya;
    ob_start();
    $hata = null;
    try { sip_calistir($tmp); }
    catch (SipExit $e) { /* normal çıkış */ }
    catch (Throwable $e) { $hata = $e->getMessage(); }
    $govde = (string)ob_get_clean();
    @unlink($tmp);
    return ['govde' => $govde, 'kod' => $SIPT['kod'], 'hata' => $hata];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null): void
{
    global $PERMS, $IS_ADMIN, $UID, $DEPOLAR;
    $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar;
}
function sev(PDO $db, array $a): int
{
    $v = $a + ['tedarikci_no' => '', 'durum' => 'mail_gonderildi', 'depo' => 'DEPO1', 'var_po' => 1];
    $db->prepare('INSERT INTO siparis_sevkiyatlar (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
    return (int)$db->lastInsertId();
}
function kalem(PDO $db, int $sid, array $a): void
{
    $v = $a + ['sevkiyat_id' => $sid, 'ian' => '', 'sira' => 1];
    $db->prepare('INSERT INTO siparis_kalemler (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
}
/** Üretilmiş invoice girdisi (hks_kv). */
function invoice(PDO $db, int $sid, string $fiyat, bool $uretildi = true): void
{
    $j = ['no' => 'IHR2026000000' . str_pad((string)$sid, 3, '0', STR_PAD_LEFT), 'tarih' => '2026-10-20', 'fiyat' => $fiyat, 'iskonto' => '4'];
    if ($uretildi) {
        $j += ['uretildi_at' => '2026-10-21 10:00:00', 'belge_id' => 900 + $sid];
        $db->prepare("INSERT INTO siparis_belgeler (id, tur, kaynak, durum, sha256, disk_adi, orijinal_ad, mime) VALUES (?, 'invoice', 'uretim', 'islendi', ?, ?, ?, 'application/pdf')")
            ->execute([900 + $sid, str_pad((string)(900 + $sid), 64, '0', STR_PAD_LEFT), 'd' . $sid . '.pdf', 'INV-' . $sid . '.pdf']);
        $db->prepare('INSERT INTO siparis_belge_bag (belge_id, sevkiyat_id, gecerli) VALUES (?, ?, 1)')->execute([900 + $sid, $sid]);
    }
    $db->prepare('INSERT INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_invoice_' . $sid, json_encode($j)]);
}
/** DOM'a benzer dengeli-etiket denetimi (script/style/textarea ve yorumlar atlanır). */
function html_dengeli(string $h): ?string
{
    $h = preg_replace('#<(script|style|textarea)\b.*?</\1>#si', '', $h);
    $h = preg_replace('/<!--.*?-->/s', '', $h);
    $void = ['meta', 'link', 'br', 'hr', 'img', 'input', 'source', 'col', 'area', 'base', 'wbr'];
    $yigin = [];
    preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>#', $h, $m, PREG_SET_ORDER);
    foreach ($m as $t) {
        $ad = strtolower($t[2]);
        if (in_array($ad, $void, true) || $t[3] === '/') continue;
        if ($t[1] === '') { $yigin[] = $ad; continue; }
        $son = array_pop($yigin);
        if ($son !== $ad) return "beklenen </$son>, bulunan </$ad>";
    }
    return $yigin === [] ? null : 'kapanmayan: ' . implode(',', array_slice($yigin, -3));
}
function temiz(array $r): bool { return $r['hata'] === null && !preg_match('/\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b:/', $r['govde']); }
/** Verilen kesitteki her <tr>'nin hücre toplamı (colspan dahil). @return list<int> */
function satir_genislikleri(string $kesit): array
{
    $out = [];
    preg_match_all('#<tr\b[^>]*>(.*?)</tr>#s', $kesit, $m);
    foreach ($m[1] as $tr) {
        $t = 0;
        preg_match_all('#<t[dh]\b([^>]*)>#', $tr, $h, PREG_SET_ORDER);
        foreach ($h as $c) $t += preg_match('/colspan="(\d+)"/', $c[1], $cs) ? (int)$cs[1] : 1;
        $out[] = $t;
    }
    return $out;
}
function kesit(string $g, string $bas, string $son): string
{
    $a = strpos($g, $bas); $b = strpos($g, $son, (int)$a);
    return ($a === false || $b === false) ? '' : substr($g, $a, $b - $a);
}

$dun = date('Y-m-d', strtotime('-1 day')); $d2 = date('Y-m-d', strtotime('-2 day')); $d3 = date('Y-m-d', strtotime('-3 day'));
$d5 = date('Y-m-d', strtotime('-5 day')); $d10 = date('Y-m-d', strtotime('-10 day'));
$OKU = ['siparis.read', 'reports.export'];
$cikti = (string)(getenv('SIPARIS_DENGE_OUT') ?: '');
if ($cikti !== '' && !is_dir($cikti)) @mkdir($cikti, 0777, true);

// ── tohum (SENTETİK; hepsi 3,5 kg koli, KG bazlı fiyat → PC fiyat/kg tam) ──
$S1 = sev($db, ['order_ref' => 'TC810001.10', 'yukleme_tarihi' => $d10, 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS', 'plakalar' => '34TST001', 'nakliyeci' => 'Test Lojistik A']);
kalem($db, $S1, ['kalibre' => '14', 'koli_icerik' => '3,5 KG', 'etkin_palet' => 52, 'etkin_koli' => 5200, 'pc_koli' => 5200, 'fiyat' => 1.45, 'para_birimi' => 'EUR', 'fiyat_birim' => 'KG', 'fiyat_tarihi' => $d10]);   // 18.200 kg × 1,45
$S2 = sev($db, ['order_ref' => 'TC810002.10', 'yukleme_tarihi' => $d5, 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS', 'plakalar' => '34TST002']);
kalem($db, $S2, ['kalibre' => '12', 'sira' => 1, 'koli_icerik' => '3,5 KG', 'etkin_palet' => 20, 'etkin_koli' => 2000, 'pc_koli' => 2000, 'fiyat' => 1.50, 'para_birimi' => 'EUR', 'fiyat_birim' => 'KG', 'fiyat_tarihi' => $d5]);   // 7.000 kg × 1,50
kalem($db, $S2, ['kalibre' => '10', 'sira' => 2, 'koli_icerik' => '3,5 KG', 'etkin_palet' => 10, 'etkin_koli' => 1000, 'pc_koli' => 1000, 'fiyat' => 1.40, 'para_birimi' => 'EUR', 'fiyat_birim' => 'KG', 'fiyat_tarihi' => $d5]);   // 3.500 kg × 1,40
$S3 = sev($db, ['order_ref' => 'TC810003.10', 'yukleme_tarihi' => $d3, 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS']);
kalem($db, $S3, ['kalibre' => '14', 'koli_icerik' => '3,5 KG', 'etkin_koli' => 400, 'pc_koli' => 400, 'fiyat' => 1.45, 'para_birimi' => 'EUR', 'fiyat_birim' => 'KG']);       // PC var, invoice YOK (üretilmemiş girdi aşağıda)
$S4 = sev($db, ['order_ref' => 'TC810004.10', 'yukleme_tarihi' => $d3, 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS']);
kalem($db, $S4, ['kalibre' => '14', 'koli_icerik' => '3,5 KG', 'etkin_koli' => 100, 'pc_koli' => 100, 'fiyat' => 1.20, 'para_birimi' => 'USD', 'fiyat_birim' => 'KG']);       // USD → karşılaştırılmaz
$S5 = sev($db, ['order_ref' => '<script>alert(1)</script>', 'yukleme_tarihi' => $d2, 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS']);
kalem($db, $S5, ['kalibre' => '14', 'koli_icerik' => '3,5 KG', 'etkin_koli' => 300]);                                                                                        // PC fiyatı yok → "Fiyat bekleniyor"
$S6 = sev($db, ['order_ref' => 'TC810006.10', 'yukleme_tarihi' => $dun, 'alici_kodu' => '1038512', 'alici_adi' => 'LIDL GB']);
kalem($db, $S6, ['kalibre' => '12', 'koli_icerik' => '3,5 KG', 'etkin_koli' => 1000, 'pc_koli' => 1000, 'fiyat' => 1.50, 'para_birimi' => 'EUR', 'fiyat_birim' => 'KG']);       // fatura = PC → denge

$KESIT_B = ['B) Yüklenen Sevkiyatlar', 'C) Fiyatlandırılmış Sevkiyatlar'];

echo "=== 1. hks_kv tablosu YOK → fail soft (sayfalar çalışır, fatura sütunu '—') ===\n";
rol($OKU, false, 3, ['DEPO1']);
$r = uc('siparis_cari.php'); $g = $r['govde'];
ok('Cari: hks_kv yokken 200, hata/uyarı yok, HTML dengeli', temiz($r) && $r['kod'] === 200 && html_dengeli($g) === null, (string)$r['hata'] . ' ' . (string)html_dengeli($g));
$B = kesit($g, $KESIT_B[0], $KESIT_B[1]);
ok('Cari B: iki yeni sütun başlığı var; hiçbir satırda fatura fiyatı yok', str_contains($B, 'Fatura fiyatı / kg') && str_contains($B, 'Fark / kg') && !str_contains($B, 'EUR / kg</td><td class="num">1,37'));
$r = uc('siparis_denge.php'); $g = $r['govde'];
ok('Denge: hks_kv yokken 200, hata/uyarı yok, HTML dengeli; boş plan + boş karşılaştırma mesajı', temiz($r) && $r['kod'] === 200 && html_dengeli($g) === null
    && str_contains($g, 'Karşılaştırılacak sevkiyat yok') && str_contains($g, 'Plan çıkarılamadı'), (string)$r['hata'] . ' ' . (string)html_dengeli($g));

// invoice girdileri
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
invoice($db, $S1, '1.37');
invoice($db, $S2, '1,42');
invoice($db, $S3, '1.45', false);      // üretilmemiş (uretildi_at yok) → sayılmaz
invoice($db, $S4, '1.37');             // USD sevkiyat
invoice($db, $S5, '1,40');             // PC yok
invoice($db, $S6, '1.50');
$db->prepare('INSERT INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_invoice_99999', '{bozuk json']);

echo "\n=== 2. Cari Döküm B tablosu ===\n";
rol($OKU, false, 3, ['DEPO1']);
$r = uc('siparis_cari.php'); $g = $r['govde'];
if ($cikti !== '') file_put_contents($cikti . '/cari.html', $g);
ok('Cari: 200, hata/uyarı yok, HTML dengeli', temiz($r) && $r['kod'] === 200 && html_dengeli($g) === null, (string)$r['hata'] . ' ' . (string)html_dengeli($g));
$B = kesit($g, $KESIT_B[0], $KESIT_B[1]);
$A = kesit($g, 'A) Gelen', $KESIT_B[0]); $C = kesit($g, $KESIT_B[1], 'Ödemeler (OGL');
ok('B: başlıklar "Fatura fiyatı / kg" (+ "fatura − PC" notu) ve "Fark / kg"; başlık TEK kez', substr_count($g, 'Fatura fiyatı / kg') === 1 && str_contains($B, 'fatura − PC') && str_contains($B, '<th class="num">Fark / kg</th>'));
ok('B: S1 satırı: PC 1,45 EUR / kg · fatura 1,37 EUR / kg · fark -0,08 EUR (sc-fark-eksi)', (bool)preg_match('~1,45 EUR / kg</td>\s*<td class="num">1,37 EUR / kg</td>\s*<td class="num sc-fark-eksi">-0,08 EUR</td>~u', $B));
ok('B: S2 çok kalibre: fatura 1,42 HER satırda tekrar; 12 → -0,08 (eksi), 10 → +0,02 (arti)', substr_count($B, '1,42 EUR / kg') === 2 && str_contains($B, 'sc-fark-arti">+0,02 EUR') && substr_count($B, 'sc-fark-eksi">-0,08 EUR') === 2);
ok('B: fatura YOK (S3: üretilmemiş girdi) → fatura/fark "—"', (bool)preg_match('~TC810003\.10.*?1,45 EUR / kg</td>\s*<td class="num">—</td>\s*<td class="num">—</td>~su', $B));
ok('B: USD sevkiyat (S4): PC "1,20 USD / kg", fatura/fark "—" (invoice EUR; karşılaştırılmaz)', (bool)preg_match('~1,20 USD / kg</td>\s*<td class="num">—</td>\s*<td class="num">—</td>~u', $B));
ok('B: PC bekleyen (S5, invoice var): "Fiyat bekleniyor" KALIR, fatura 1,40 görünür, fark "—"', (bool)preg_match('~Fiyat bekleniyor</td>\s*<td class="num">1,40 EUR / kg</td>\s*<td class="num">—</td>~u', $B));
ok('B: fark 0 → işaretsiz "0,00 EUR", renk sınıfı yok (S6)', (bool)preg_match('~1,50 EUR / kg</td>\s*<td class="num">1,50 EUR / kg</td>\s*<td class="num ">0,00 EUR</td>~u', $B));
$gen = satir_genislikleri($B);
ok('B: tüm satırlar 12 hücre genişliğinde (Parti/Sipariş/Ülke/Plaka bloğu dahil) (colspan\'lar bozulmadı) — ' . count($gen) . ' satır', $gen !== [] && count(array_unique($gen)) === 1 && $gen[0] === 12, json_encode(array_count_values($gen)));
ok('A ve C bölümleri DEĞİŞMEDİ: fatura sütunu/başlığı/sınıfı yok; A 10, C 8 hücre', !str_contains($A, 'Fatura fiyatı') && !str_contains($C, 'Fatura fiyatı') && !str_contains($A . $C, 'sc-fark')
    && count(array_unique(satir_genislikleri($A))) === 1 && satir_genislikleri($A)[0] === 10 && count(array_unique(satir_genislikleri($C))) === 1 && satir_genislikleri($C)[0] === 8);
ok('dipnot: "Fatura fiyatı = Invoice (OGL) ekranında üretilen invoice\'taki birim fiyat (iskonto öncesi); fark = fatura − PC."', str_contains($g, "Fatura fiyatı = Invoice (OGL) ekranında üretilen invoice'taki birim fiyat (iskonto öncesi); fark = fatura − PC."));
ok('cari özet (D) etkilenmez: fatura sütunu içermez', str_contains($g, 'D) Cari Özet') && !str_contains(kesit($g, 'D) Cari Özet', '</html>'), 'Fatura fiyatı'));
ok('XSS: S5 sipariş no kaçırılır; hiçbir yerde çıplak <script>alert', !str_contains($g, '<script>alert(1)') && str_contains($g, '&lt;script&gt;alert(1)&lt;/script&gt;'));
ok('kapı/çıktı değişmedi: tfoot yok, onclick yok, form POST yok', !str_contains($g, '<tfoot') && !preg_match('/\sonclick=/i', $g) && !str_contains($g, 'method="post"'));
ok('Fiyat dengeleme bağlantısı: no-print çubukta, yeni sekme, noopener, mevcut filtreyi taşır (bas/bit/alici)', (bool)preg_match('~<div class="pr-actions no-print">.*?<a class="pr-btn" href="siparis_denge\.php\?bas=[^"]*&amp;bit=&amp;alici=" target="_blank" rel="noopener">📊 Fiyat dengeleme</a>~su', $g));
$r = uc('siparis_cari.php', ['alici' => '1038512', 'bas' => '', 'bit' => $dun]); $g2 = $r['govde'];
ok('Fiyat dengeleme bağlantısı filtreyi korur (alici + boş bas + bit)', str_contains($g2, 'siparis_denge.php?bas=&amp;bit=' . $dun . '&amp;alici=1038512'), '');

echo "\n=== 3. Fiyat Dengeleme sayfası (varsayılan parametreler) ===\n";
$r = uc('siparis_denge.php'); $g = $r['govde'];
if ($cikti !== '') file_put_contents($cikti . '/denge.html', $g);
ok('200, hata/uyarı yok, HTML dengeli, title', temiz($r) && $r['kod'] === 200 && html_dengeli($g) === null && str_contains($g, '<title>Fiyat Dengeleme</title>'), (string)$r['hata'] . ' ' . (string)html_dengeli($g));
foreach (['print_pdks.css', 'A4 landscape', 'data-sc-yazdir', 'window.print()', 'Sevkiyat bazında karşılaştırma', 'Sonraki 5 fatura planı', 'Uyarılar ve notlar', 'Önerilen fatura fiyatı / kg',
    'Karşılaştırılan sevkiyat', 'Ort. PC fiyatı', 'Ort. fatura fiyatı', 'Kapatılacak tutar', '+ = ileride bu kadar fazla faturalanmalı', 'Fark = Fatura − PC'] as $p) ok('sayfa içerir: ' . $p, str_contains($g, $p));
// Karşılaştırılanlar: S1, S2, S6 (denge; EUR+PC+invoice). S3/S4/S5 dışarıda.
$K = kesit($g, 'id="sdKarsi"', 'Sonraki 5 fatura planı');
foreach (['TC810001.10', 'TC810002.10', 'TC810006.10'] as $ref) ok("karşılaştırma tablosunda $ref var", str_contains($K, $ref));
foreach (['TC810003.10', 'TC810004.10', 'alert(1)'] as $ref) ok("karşılaştırma tablosunda $ref YOK (invoice yok / USD / PC yok)", !str_contains($K, $ref));
// Elle doğrulanmış sayılar: S1 18.200 kg PC 1,45 / fatura 1,37 → -1.456,00 ; S2 10.500 kg PC 14.666,67→1,4667 / fatura 1,42 → -490,00 ; S6 1.000×3,5=3.500 kg fark 0
ok('S1 satırı: 18.200 kg · 1,45 · 1,37 · -0,08 · -1.456,00 · kümülatif -1.456,00', (bool)preg_match('~TC810001\.10</td>\s*<td>.*?</td>\s*<td>.*?</td>\s*<td class="num">18\.200</td>\s*<td class="num">1,45</td>\s*<td class="num">1,37</td>\s*<td class="num sc-fark-eksi">-0,08</td>\s*<td class="num sc-fark-eksi">-1\.456,00</td>\s*<td class="num sc-fark-eksi">-1\.456,00</td>~us', $K));
ok('S2 satırı: 10.500 kg · PC ağırlıklı 1,4667 · fatura 1,42 · fark -0,0467 · -490,00 · kümülatif -1.946,00', (bool)preg_match('~TC810002\.10</td>\s*<td>.*?</td>\s*<td>.*?</td>\s*<td class="num">10\.500</td>\s*<td class="num">1,4667</td>\s*<td class="num">1,42</td>\s*<td class="num sc-fark-eksi">-0,0467</td>\s*<td class="num sc-fark-eksi">-490,00</td>\s*<td class="num sc-fark-eksi">-1\.946,00</td>~us', $K));
ok('S6 satırı (fark 0): 0,00 · kümülatif -1.946,00', (bool)preg_match('~TC810006\.10</td>\s*<td>.*?</td>\s*<td>.*?</td>\s*<td class="num">3\.500</td>\s*<td class="num">1,50</td>\s*<td class="num">1,50</td>\s*<td class="num">0,00</td>\s*<td class="num">0,00</td>\s*<td class="num sc-fark-eksi">-1\.946,00</td>~us', $K));
ok('TOPLAM: 3 sevkiyat, 32.200 kg, ağırlıklı PC 1,4609 / fatura 1,4004 (basit ortalama 1,4722 / 1,4400 DEĞİL), fark -1.946,00', str_contains($K, 'TOPLAM — 3 sevkiyat') && str_contains($K, '32.200') && preg_match('~<tbody class="sc-toplam">.*?1,4609</td>\s*<td class="num">1,4004</td>~su', $K) === 1 && substr_count($K, '-1.946,00') >= 3, '');
ok('özet kutusu: Kapatılacak tutar "+1.946,00 EUR" (işaret açıklamalı)', str_contains($g, '+1.946,00 EUR') && str_contains($g, 'Fark / kg (fatura − PC)'));
$ap = kesit($g, '<ul class="sd-uyari-liste"', '</ul>');
ok('uyarı: 1 sevkiyat invoice\'suz (S3 üretilmemiş) + 1 EUR dışı (S4) + 1 PC bekleyen (S5) — yalnız bilgi satırları', str_contains($ap, '1 sevkiyat için üretilmiş invoice yok') && str_contains($ap, '1 sevkiyat EUR dışı') && str_contains($ap, 'henüz gelmemiş'), $ap);
$gen = satir_genislikleri($K);
ok('karşılaştırma tablosu: tüm satırlar 11 hücre (kimlik bloğu + toplam dahil)', $gen !== [] && count(array_unique($gen)) === 1 && $gen[0] === 11, json_encode($gen));
$P = kesit($g, 'id="sdPlan"', 'Uyarılar ve notlar');
$planSatir = preg_match_all('~<tr>\s*<td class="num">(\d+)</td>~', $P, $pm);
ok('plan: 5 satır, sıra 1..5', $planSatir === 5 && $pm[1] === ['1', '2', '3', '4', '5'], json_encode($pm[1] ?? []));
ok('plan: önerilen fatura fiyatı hücreleri vurgulu (sd-oneri; başlık + 5 hücre)', substr_count($P, 'sd-oneri') === 6);
$gen = satir_genislikleri($P);
ok('plan tablosu: tüm satırlar 9 hücre (özet satırı dahil)', $gen !== [] && count(array_unique($gen)) === 1 && $gen[0] === 9, json_encode($gen));
// plan kapanış tutarlılığı: Σ kapanan + kalan = kapatılacak (sayfadaki sayılardan bağımsız, hesaplama fonksiyonuyla)
$cv = siparis_cari_veri(siparis_cari_oku($db, siparis_cari_filtre([], date('Y-m-d'))), ['bugun' => date('Y-m-d'), 'tahsilat' => [], 'fatura' => siparis_cari_fatura_fiyatlari($db, array_column(siparis_cari_oku($db, siparis_cari_filtre([], date('Y-m-d')))['rows'], 'id'))]);
$hh = siparis_denge_hesapla($cv, siparis_denge_param([], $cv));
ok('hesap tutarlılığı: Σkapanan + kalan = kapatılacak; varsayılan kg = ortalama (10.733), hedef = son sevkiyat PC fiyatı', $hh['ozet']['kapatilacak_kurus'] === 194600 && $hh['plan_ozet']['toplam_kapanan'] + $hh['plan_ozet']['kalan'] === 194600 && $hh['plan'][0]['gram'] === 10733000, json_encode($hh['plan_ozet']));

echo "\n=== 4. Sabit parametrelerle elle doğrulanmış plan (kg=18.200, hedef=1,45, n=5, hane=2) ===\n";
$r = uc('siparis_denge.php', ['alici' => '1038529', 'bas' => '', 'kg' => '18200', 'hedef' => '1,45', 'n' => '5', 'hane' => '2']); $g = $r['govde'];
// alici=1038529 → S1,S2 (S6 dışı) → fark -1.946,00 → kapatılacak +1.946,00; hedefTutar 26.390,00
$P = kesit($g, 'id="sdPlan"', 'Uyarılar ve notlar');
ok('200 + temiz', temiz($r) && html_dengeli($g) === null, (string)$r['hata']);
ok('plan: önerilen fiyat 1,47 ×4 sonra 1,48 (son fatura kalan borcu toplar)', preg_match_all('~class="num sd-oneri">([^<]+)</td>~', $P, $om) === 5 && $om[1] === ['1,47', '1,47', '1,47', '1,47', '1,48'], json_encode($om[1] ?? []));
ok('plan: beklenen PC tutarı 26.390,00; fatura 1 tutarı 26.754,00; kapanan +364,00; kalan +1.582,00', str_contains($P, '26.390,00') && str_contains($P, '26.754,00') && str_contains($P, '+364,00') && str_contains($P, '+1.582,00'));
ok('plan: son satır kalan -56,00 (yuvarlama artığı); toplam kapanan +2.002,00', str_contains($P, '-56,00') && str_contains($P, '+2.002,00'));
ok('plan özeti: ort. düzeltme +0,0220 €/kg (hedefin +1,52 %)', str_contains($P, '+0,022') && str_contains($g, '+1,52 %'));
ok('büyük sıçrama uyarısı YOK (düzeltme hedefin %5\'inden küçük)', !str_contains($g, 'büyük sıçrama'));
$r = uc('siparis_denge.php', ['alici' => '1038529', 'bas' => '', 'kg' => '1000', 'hedef' => '1,45', 'n' => '1']); $g = $r['govde'];
ok('büyük düzeltme: "büyük sıçrama" uyarısı + fatura sayısı önerisi (uyari seviyesi)', str_contains($g, 'büyük sıçrama; fatura sayısını') && str_contains($g, 'sd-u-uyari'), '');
$r = uc('siparis_denge.php', ['alici' => '1038512', 'bas' => '']); $g = $r['govde'];
ok('fark 0 (yalnız S6): "Fatura fiyatları PC ile dengede."; plan düzeltmesiz (1,50 ×5)', str_contains($g, 'Fatura fiyatları PC ile dengede.') && preg_match_all('~class="num sd-oneri">1,50</td>~', kesit($g, 'id="sdPlan"', 'Uyarılar ve notlar')) === 5);
$r = uc('siparis_denge.php', ['alici' => '777']); $g = $r['govde'];
ok('veri yok: boş karşılaştırma + boş plan mesajı + "Karşılaştırılacak sevkiyat yok" uyarısı; sayfa çökmez', temiz($r) && str_contains($g, 'Karşılaştırılacak sevkiyat yok') && str_contains($g, 'Plan çıkarılamadı') && substr_count($g, 'Karşılaştırılacak sevkiyat yok') >= 2);

echo "\n=== 5. Parametre doğrulama + XSS ===\n";
$r = uc('siparis_denge.php', ['n' => '99', 'kg' => 'abc', 'hedef' => '0', 'hane' => '7']); $g = $r['govde'];
ok('geçersiz n/kg/hedef/hane: sayfa açılır, hata kutusu (role=alert) 4 madde, varsayılanlarla hesap', temiz($r) && str_contains($g, 'role="alert"') && substr_count(kesit($g, 'class="sd-hata"', '</ul>'), '<li>') === 4 && str_contains($g, 'Sonraki 5 fatura planı'), (string)$r['hata']);
$r = uc('siparis_denge.php', ['n' => '3']); $g = $r['govde'];
ok('geçerli n=3: hata kutusu yok, "Sonraki 3 fatura planı", form kutusu değeri 3', !str_contains($g, 'role="alert"') && str_contains($g, 'Sonraki 3 fatura planı') && str_contains($g, 'name="n" min="1" max="12" step="1" value="3"'));
$xs = '"><script>alert(9)</script>';
$r = uc('siparis_denge.php', ['kg' => $xs, 'hedef' => $xs, 'n' => $xs, 'alici' => $xs]); $g = $r['govde'];
ok('XSS: form değerleri/mesajlar kaçırılır; çıplak <script>alert(9) yok; sayfa 200', temiz($r) && !str_contains($g, '<script>alert(9)') && html_dengeli($g) === null && str_contains($g, 'role="alert"'), (string)$r['hata']);
ok('formlar: 2 GET formu (filtre + parametre), hane seçimi 2/3/4, param formu bas/bit/alici taşır', substr_count($g, 'method="get" action="siparis_denge.php"') === 2 && str_contains($g, '<option value="2" selected>2 ondalık</option>') && str_contains($g, '<option value="4">4 ondalık</option>') && str_contains($g, 'name="bas"'));
$r = uc('siparis_denge.php', ['hane' => '4', 'kg' => '18.200', 'hedef' => '1.4512']); $g = $r['govde'];
ok('hane=4 + binlik kg "18.200" + hedef 1.4512 geçerli; hata yok; 4 ondalık fiyat', !str_contains($g, 'role="alert"') && preg_match('~class="num sd-oneri">\d,\d{3,4}</td>~', $g) === 1 && str_contains($g, '<option value="4" selected>4 ondalık</option>'));
ok('Cari Döküm bağlantısı korunur (bas/bit/alici)', str_contains($g, 'href="siparis_cari.php?bas='));

echo "\n=== 6. Kapılar + audit ===\n";
$audAdet = static fn(): int => (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_denge_goruntule'")->fetchColumn();
rol($OKU, false, 3, ['DEPO1']);
$once = $audAdet(); uc('siparis_denge.php');
$au = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_denge_goruntule' ORDER BY id DESC LIMIT 1")->fetchColumn();
$auJ = is_string($au) ? json_decode($au, true) : null;
ok('audit: siparis_denge_goruntule +1, yalnız sayılar (sevkiyat/n/kesildi)', $audAdet() === $once + 1 && is_array($auJ) && array_keys($auJ) === ['sevkiyat', 'n', 'kesildi'] && $auJ['sevkiyat'] === 3 && $auJ['n'] === 5, (string)$au);
ok('audit: sipariş no / plaka / fiyat / tutar YAZILMAZ', is_string($au) && !str_contains($au, 'TC810') && !str_contains($au, '34TST') && !str_contains($au, '1,4') && !str_contains($au, '1946'));
rol(['dashboard.read', 'records.read', 'reports.read', 'siparis.read'], false, 5, ['DEPO1']);
$n0 = $audAdet(); $r = uc('siparis_denge.php');
ok('izleyici (reports.export yok): 403, gövde boş, audit yok', $r['hata'] !== null && str_contains($r['hata'], 'forbidden') && str_contains($r['hata'], 'reports.export') && $r['govde'] === '' && $audAdet() === $n0, (string)$r['hata']);
rol(['reports.export'], false, 6, ['DEPO1']);
$r = uc('siparis_denge.php');
ok('siparis.read yoksa 403, gövde boş', $r['hata'] !== null && str_contains($r['hata'], 'siparis.read') && $r['govde'] === '', (string)$r['hata']);
rol($OKU, false, 7, ['DEPO2']);
$r = uc('siparis_denge.php');
ok('depo süzgeci: DEPO2 kullanıcısı DEPO1 sevkiyatlarını görmez (karşılaştırma boş)', temiz($r) && !str_contains($r['govde'], 'TC810001.10') && str_contains($r['govde'], 'Karşılaştırılacak sevkiyat yok'));

echo "\n=== 7. Doğrudan istek koruması (ayrı süreç) ===\n";
$__kos = function (string $script) use ($ROOT): string {
    $kod = '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($script, true) . '; function h($s){return htmlspecialchars((string)$s);}'
         . ' require ' . var_export($ROOT . '/config/siparis_core.php', true) . '; require ' . var_export($ROOT . '/_siparis_ortak.php', true) . ';'
         . ' require ' . var_export($ROOT . '/config/siparis_denge.php', true) . '; echo "YUKLENDI";';
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($kod) . ' 2>&1');
};
ok('sayfa (kök siparis_denge.php) çalışırken config/siparis_denge.php YÜKLENİR', str_contains($__kos($ROOT . '/siparis_denge.php'), 'YUKLENDI'));
ok('config/siparis_denge.php doğrudan istenirse durur', !str_contains($__kos($ROOT . '/config/siparis_denge.php'), 'YUKLENDI'));

echo "\n=== 4. Kimlik bloğu (v449): Parti no · Sipariş no · Alıcı ülke · Plaka — TÜM sevkiyat tablolarında ===\n";
$db->exec("INSERT INTO customs_declarations (status, party_no, created_at) VALUES ('taslak', 'P-810/1', '2026-10-01 10:00:00'), ('taslak', 'P-810/2', '2026-10-01 10:00:00')");
$cdSon = (int)$db->lastInsertId();
foreach ([$cdSon - 1, $cdSon] as $bid) $db->exec("INSERT INTO siparis_beyan (sevkiyat_id, beyan_id, tur, durum, created_by, created_at) VALUES ($S1, $bid, 'elle', 'aktif', 1, '2026-10-02 10:00:00')");
$KB = '<th>Parti no</th><th>Sipariş no</th><th>Alıcı ülke</th><th>Plaka</th>';
rol(array_merge($OKU, ['beyan.read']), false, 3, ['DEPO1']);
$r = uc('siparis_cari.php'); $g = $r['govde'];
if ($cikti !== '') file_put_contents($cikti . '/cari_parti.html', $g);
ok('cari (beyan.read var): 200, uyarı yok', temiz($r) && $r['kod'] === 200, (string)$r['hata']);
ok('A ve B tablolarının başlığında kimlik bloğu SIRAYLA (Parti no · Sipariş no · Alıcı ülke · Plaka)', substr_count($g, $KB) === 2, (string)substr_count($g, $KB));
ok('C bölümü grup başlığında dört alan etiketli: Parti no / Sipariş no / Alıcı ülke / Plaka', str_contains($g, '<strong>Parti no:</strong>') && str_contains($g, '<strong>Sipariş no:</strong>') && str_contains($g, '<strong>Alıcı ülke:</strong>') && str_contains($g, '<strong>Plaka:</strong>'));
$Bk = kesit($g, $KESIT_B[0], $KESIT_B[1]);
ok('S1: iki bağlı beyanın parti no\'su AYRI satırda ve sipariş no\'nun HEMEN solunda; ardından ülke + plaka (B)', (bool)preg_match('~<td>P-810/1<br>P-810/2</td><td>TC810001\.10</td><td>[^<]+</td><td>34TST001</td>~u', $Bk));
ok('C grup başlığında S1: "Parti no: P-810/1 / P-810/2 · Sipariş no: TC810001.10"', str_contains($g, '<strong>Parti no:</strong> P-810/1 / P-810/2 · <strong>Sipariş no:</strong> TC810001.10'));
ok('bağı olmayan sevkiyatta parti no "—" (uydurma YOK)', (bool)preg_match('~<td>—</td><td>TC810002\.10</td>~u', $g));
$gen = satir_genislikleri($Bk);
ok('B tablosu tüm satırlar 12 hücre', $gen !== [] && count(array_unique($gen)) === 1 && $gen[0] === 12);
rol($OKU, false, 3, ['DEPO1']);
$r = uc('siparis_cari.php'); $g2 = $r['govde'];
ok('beyan.read YOK: hiçbir parti no sızmaz, sütun yine var, açıklayıcı not', temiz($r) && !str_contains($g2, 'P-810/') && substr_count($g2, $KB) === 2 && str_contains($g2, 'parti no görmek için beyan okuma yetkisi gerekir'));
rol(array_merge($OKU, ['beyan.read']), false, 3, ['DEPO1']);
$r = uc('siparis_denge.php'); $g3 = $r['govde'];
if ($cikti !== '') file_put_contents($cikti . '/denge_parti.html', $g3);
ok('denge: karşılaştırma tablosunda kimlik bloğu + S1 parti no', temiz($r) && str_contains($g3, $KB) && (bool)preg_match('~<td>P-810/1<br>P-810/2</td><td>TC810001\.10</td><td>[^<]+</td><td>34TST001</td>~u', $g3));
$kh = siparis_cari_kimlik_html(['parti' => ['P<1>'], 'order_ref' => '<b>X</b>', 'ulke' => '', 'plaka' => '33ABC123-33DEF456']);
ok('siparis_cari_kimlik_html: XSS kaçışı, boş ülke "—", bitişik plakalar ayrı satır', str_contains($kh, 'P&lt;1&gt;') && str_contains($kh, '&lt;b&gt;X&lt;/b&gt;') && str_contains($kh, '<td>—</td>') && str_contains($kh, '33ABC123<br>33DEF456') && !str_contains($kh, '<b>'));
ok('boş parti + boş plaka → "—"', siparis_cari_kimlik_html(['order_ref' => 'T']) === '<td>—</td><td>T</td><td>—</td><td>—</td>');

siparis_test_bitir();
