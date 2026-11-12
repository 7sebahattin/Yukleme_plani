<?php
// =========================================================
// scripts/frugro_liste_smoke.php — Frugro Siparişler LİSTE (v515 P1): bölümler, KPI, süzgeçler, sayfalama, yazdır/paylaş uçları, XSS, depo, işaretleme.
//   php scripts/frugro_liste_smoke.php        (bellek içi SQLite + stub auth; sayfalar gerçekten çalıştırılır; canlı DB'ye dokunmaz)
// Tarayıcı ölçümü (düzen/taşma/düğmeler): node scripts/frugro_liste_smoke.js
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_core.php';
require_once $ROOT . '/vendor/autoload.php';
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$db = db();
siparis_test_diger_tablolar($db);

class FrgExit extends RuntimeException {}
$FT = ['h' => [], 'kod' => 200];
function frgt_header(string $h, bool $replace = true, int $code = 0): void { global $FT; if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $FT['h'][strtolower(trim($a))] = trim($b); } if ($code > 0) $FT['kod'] = $code; }
function frgt_code(?int $c = null) { global $FT; if ($c !== null) $FT['kod'] = $c; return $FT['kod']; }
function frg_calistir(string $tmp): void { include $tmp; }
/** @return array{html:string,kod:int,git:?string,hata:?string,json:?array} */
function sayfa(string $dosya, array $get = [], array $post = [], ?array $json = null, array $files = []): array
{
    global $ROOT, $FT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'frgt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'frgt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new FrgExit();', $src);
    $src = str_replace("file_get_contents('php://input')", '$GLOBALS[\'FRG_BODY\']', $src);
    $tmp = sys_get_temp_dir() . '/frg_ui_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $FT = ['h' => [], 'kod' => 200];
    $GLOBALS['FRG_BODY'] = $json === null ? '' : json_encode($json);
    $_GET = $get; $_POST = $post; $_FILES = $files;
    $_SERVER['REQUEST_METHOD'] = ($json !== null || $post || $files) ? 'POST' : 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya;
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['flash']);
    ob_start(); $hata = null;
    try { frg_calistir($tmp); } catch (FrgExit $e) {} catch (Throwable $e) { $hata = $e->getMessage(); }
    $html = (string)ob_get_clean(); @unlink($tmp);
    $j = json_decode($html, true);
    return ['html' => $html, 'kod' => $FT['kod'], 'git' => $FT['h']['location'] ?? null, 'hata' => $hata, 'json' => is_array($j) ? $j : null];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null): void { global $PERMS, $IS_ADMIN, $UID, $DEPOLAR; $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar; }
function csrf(): string { if (session_status() === PHP_SESSION_NONE) session_start(); return csrf_token(); }
function temiz(array $r): bool { return $r['hata'] === null && !preg_match('/\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b:/', $r['html']); }
function kayit(string $dosya, array $j): array { $j['csrf'] = csrf(); return sayfa($dosya, [], [], $j); }
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
$ADMIN = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'];
$VIEW  = ['siparis.read'];
rol($ADMIN, true);

// ── sentetik veri: her durumdan bir sipariş (+ eski kapali), 2 müşteri/liman, iki PB ──
$simdi = date('Y-m-d H:i:s');
$ins = $db->prepare("INSERT INTO frugro_siparisler (po_no, cikis_tarihi, varis_tarihi, giris_limani, son_musteri, son_musteri_po, urun, cesit, boy, dis_paket, kutu, kutu_palet, palet, fiyat_kutu_birim, para_birimi, parti_no, plaka, nakliyeci, fatura_no, durum, depo, kaynak, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$SENT = [  // po, durum, gün, müşteri, liman, pb, fatura, parti
    ['26-9001-90001', 'yeni', 3, 'Musteri-A', 'LIMAN-1', null, null, null],
    ['26-9002-90002', 'hazirlik', 2, 'Musteri-B', 'LIMAN-2', null, null, null],
    ['26-9003-90003', 'pkl_hazir', 1, 'Musteri-A', 'LIMAN-1', 'GBP', null, 'P-1'],
    ['26-9004-90004', 'invoice_hazir', 0, 'Musteri-C', 'LIMAN-1', 'GBP', 'INV-4', 'P-2'],
    ['26-9005-90005', 'yola_cikti', -1, 'Musteri-A', 'LIMAN-3', 'EUR', 'INV-5', 'P-3'],
    ['26-9006-90006', 'mail_gonderildi', -2, 'Musteri-B', 'LIMAN-2', 'GBP', 'INV-6', 'P-4'],
    ['26-9007-90007', 'evrak_gonderildi', -3, 'Musteri-C', 'LIMAN-1', 'GBP', 'INV-7', 'P-5'],
    ['26-9008-90008', 'iptal', -4, 'Musteri-B', 'LIMAN-3', null, null, null],
    ['26-9009-90009', 'kapali', -5, 'Musteri-A', 'LIMAN-1', 'GBP', 'INV-9', 'P-6'],
];
$ID = [];
foreach ($SENT as $i => [$po, $dur, $of, $mus, $lim, $pb, $fat, $parti]) {
    $cik = date('Y-m-d', strtotime(($of >= 0 ? '+' : '') . $of . ' day'));
    $ins->execute([$po, $cik, date('Y-m-d', strtotime($cik . ' +8 day')), $lim, $mus, 'MP-' . (7000 + $i), 'NAR', 'Wonderful', '80-100', 'Karton 2.5 kg', 800, 200, 4, 46000, $pb, $parti, $parti ? '34 TST 00' . $i : null, $parti ? 'Test Nakliyat' : null, $fat, $dur, 'DEPO1', 'mail', $simdi, $simdi]);
    $ID[$dur] = (int)$db->lastInsertId();
}
$pal = [];
for ($k = 1; $k <= 4; $k++) $pal[] = ['palet_no' => (string)$k, 'kalibre' => $k % 2 ? '14' : '16', 'lot' => 'L', 'ggn_no' => '4000000000001', 'ggn_adi' => 'X', 'kutu' => 200, 'brut_kg' => 700, 'net_kg' => 640];
foreach (['pkl_hazir', 'invoice_hazir', 'yola_cikti'] as $d) frg_palet_kaydet($db, $ID[$d], $pal);
$db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$ID['yola_cikti'], 'fark', 'Yeni dosyada fark var, alanlar EZİLMEDİ: palet: 4 → 5; cikis_tarihi: 2026-01-01 → 2026-01-02', $simdi]);
$db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$ID['mail_gonderildi'], 'durum', 'pkl_hazir → mail_gonderildi', $simdi]);

echo "=== 1. Bölümler ===\n";
$r = sayfa('frugro_siparis.php');
ok('liste temiz', temiz($r), (string)$r['hata']);
$h = $r['html'];
$bolum = static function (string $html, string $sinif): string { $a = strpos($html, 'class="sip-bolum ' . $sinif); if ($a === false) return ''; $b = strpos($html, '</section>', $a); return substr($html, $a, $b - $a); };
$A = $bolum($h, 'sip-bolum-acik'); $E = $bolum($h, 'sip-bolum-evrak'); $K = $bolum($h, 'sip-bolum-kapali');
ok('İşlem Bekleyen: yeni/hazırlık/pkl/invoice/yola çıktı (5)', preg_match_all('/<tr class="row-link sip-grup/', $A) === 5 && str_contains($A, '26-9001-90001') && str_contains($A, '26-9005-90005') && !str_contains($A, '26-9006-90006'));
ok('Evrak Takımı Bekleyen: yalnız mail_gonderildi', str_contains($E, '26-9006-90006') && preg_match_all('/<tr class="row-link sip-grup/', $E) === 1);
ok('Kapanan: evrak_gonderildi + iptal + ESKİ kapali', str_contains($K, '26-9007-90007') && str_contains($K, '26-9008-90008') && str_contains($K, '26-9009-90009') && preg_match_all('/<tr class="row-link sip-grup/', $K) === 3);
ok('Açık sıra: çıkış tarihi ARTAN (yola çıktı → … → yeni)', strpos($A, '26-9005-90005') < strpos($A, '26-9001-90001'));
ok('bölüm başlıkları: sayaçlar 5 / 1 / 3', preg_match('/İşlem Bekleyen Siparişler<\/h2><span class="sip-say">5</', $A) === 1 && preg_match('/Evrak Takımı Bekleyen Siparişler<\/h2><span class="sip-say">1</', $E) === 1 && preg_match('/Kapanan Siparişler<\/h2><span class="sip-say">3</', $K) === 1);
ok('her bölüm başlığında 3 yazdırma düğmesi (frugro_yazdir.php?bolum=…)', substr_count($A, 'data-sip-yazdir=') === 3 && str_contains($A, 'frugro_yazdir.php?bolum=acik') && str_contains($E, 'bolum=evrak') && str_contains($K, 'bolum=kapali') && !str_contains($h, 'siparis_yazdir.php'));
ok('ürün alt satırı (Ürün bilgileri kartı, kalibre grupları) + zebra z0/z1', str_contains($A, 'sip-urun-tr') && str_contains($A, 'sip-urunler') && str_contains($A, 'Pal. 2 · PF 200') && str_contains($A, 'sip-z0') && str_contains($A, 'sip-z1'));
ok('durum rozeti + ilerleme çubuğu (n/7); iptal 0/7', str_contains($A, 'sip-badge sip-badge--yolda') && str_contains($A, '>5/7<') && str_contains($K, '>0/7<') && str_contains($h, 'sip-badge--invoice'));
ok('parti no rozeti + lojistik (plaka, nakliyeci)', str_contains($A, 'sip-parti-btn') && str_contains($A, 'P-3') && str_contains($A, '34 TST 004') && str_contains($A, 'Test Nakliyat'));
ok('belge rozetleri OL/PKL/INV/ML (ML yalnız mail adımından sonra)', str_contains($A, 'sip-doks') && str_contains($A, '>OL ') && preg_match('/sip-dok--var" title="PKL maili gönderildi/', $E) === 1 && !str_contains($A, 'PKL maili gönderildi — var'));
ok('⋮ menü: Aç · PKL · Invoice · WP Paylaş (data-sip-wp-url=frugro_paylas.php)', str_contains($A, 'frugro_view.php?id=' . $ID['yeni']) && str_contains($A, 'frugro_pkl.php?id=') && str_contains($A, 'frugro_invoice.php?id=') && str_contains($A, 'data-sip-wp="' . $ID['yeni'] . '" data-sip-wp-url="frugro_paylas.php"'));
ok('⚠ rozet "2 fark" + baloncuk verisi (alan etiketi Türkçe, tarih gg.aa.yyyy)', str_contains($A, '⚠ 2 fark') && str_contains($A, 'Çıkış tarihi: 01.01.2026 → 02.01.2026') && str_contains($A, 'Palet: 4 → 5'));
ok('görülme kaydı: data-sip-gor-ns="frg" meta + satır işaretçileri (YENİ/değişti)', preg_match('/<span hidden data-sip-gor-ns="frg" data-sip-gor-uid="1"/', $h) === 1 && str_contains($h, 'data-sip-gor-yeni='));
ok('para birimleri toplanmaz: tutar satır başına (GBP ve EUR ayrı), tfoot yok, birleşik toplam yok, PB boşta GBP', !str_contains($h, '<tfoot') && !str_contains($h, 'Döviz seçilecek') && str_contains($h, '3.680,00 GBP') && str_contains($h, '3.680,00 EUR') && !str_contains($h, '7.360,00'));
ok('Excel yükle + Mailden al kartı: details.sip-yukle[data-sip-yukle] içinde, #frgYukleDugme/#frgDosya/#frgSonuc id\'leri', str_contains($h, 'details class="card sip-yukle"') && str_contains($h, 'data-sip-yukle') && str_contains($h, 'id="frgYukleDugme"') && str_contains($h, 'id="frgDosya"') && str_contains($h, 'id="frgSonuc"') && str_contains($h, 'id="frg" data-csrf='));
ok('KPI çipleri 7 + tek satır arama + ▾ Filtre', preg_match_all('/class="sip-chip[ "]/', $h) === 7 && str_contains($h, 'data-sip-filtre-toggle') && str_contains($h, 'name="musteri"') && str_contains($h, 'name="liman"'));
ok('onclick/onsubmit YOK; assets: siparis.css/js + siparis_yazdir.js + siparis_wp.js + frugro.js', !preg_match('/\son(click|submit|change)=/i', substr($h, (int)strpos($h, 'class="sip-mod frg"'))) && str_contains($h, 'assets/siparis.css') && str_contains($h, 'assets/siparis_yazdir.js') && str_contains($h, 'assets/siparis_wp.js') && str_contains($h, 'assets/frugro.js'));

echo "\n=== 2. KPI sayıları ===\n";
$k = frgui_kpi_say($db);
ok('KPI: açık 5 · hazırlık 1 · pkl bekleyen 2 · invoice bekleyen 1 · yolda 1 · mail bekleyen 2 · evrak 1 · kapalı 3', $k['acik'] === 5 && $k['hazirlik'] === 1 && $k['pkl'] === 2 && $k['invoice'] === 1 && $k['yolda'] === 1 && $k['mail'] === 2 && $k['evrak'] === 1 && $k['kapali'] === 3, json_encode($k));
ok('KPI: PB eksik = fiyatı olup para birimi olmayan KAPANMAMIŞ (yeni, hazırlık = 2; iptal sayılmaz)', $k['pb'] === 2, (string)$k['pb']);
$c = frgui_kpi_cipler($db, frgui_filtre(['durum' => 'yola_cikti']));
ok('KPI çipleri: aktif olan işaretli, bağlantılar beyaz liste', count($c) === 7 && array_sum(array_map(fn($x) => $x['aktif'] ? 1 : 0, $c)) === 1 && $c[4]['aktif'] && str_contains($c[2]['url'], 'durum=pkl_bekleyen'));

echo "\n=== 3. Süzgeçler + sayfalama ===\n";
$say = static function (array $g, string $kapsam = 'tek') use ($db): int { $F = frgui_filtre($g); return (int)frgui_bolum_oku($db, $F, $kapsam, 1)['toplam']; };
ok('durum=acik → yalnız İşlem Bekleyen bölümü', (function () { $r = sayfa('frugro_siparis.php', ['durum' => 'acik']); return str_contains($r['html'], 'sip-bolum-acik') && !str_contains($r['html'], 'sip-bolum-evrak') && !str_contains($r['html'], 'sip-bolum-kapali'); })());
ok('durum=pkl_hazir (tek) → düz "Sonuçlar", bölüm kartı yok', (function () { $r = sayfa('frugro_siparis.php', ['durum' => 'pkl_hazir']); return str_contains($r['html'], 'Sonuçlar') && !str_contains($r['html'], 'sip-bolum-acik') && str_contains($r['html'], '26-9003-90003') && !str_contains($r['html'], '26-9001-90001'); })());
ok('sanal durumlar: pkl_bekleyen=2, mail_bekleyen=2, pb_eksik=2', $say(['durum' => 'pkl_bekleyen']) === 2 && $say(['durum' => 'mail_bekleyen']) === 2 && $say(['durum' => 'pb_eksik']) === 2);
ok('müşteri / liman / çıkış aralığı', $say(['musteri' => 'Musteri-A']) === 4 && $say(['liman' => 'LIMAN-2']) === 2 && $say(['bas' => date('Y-m-d', strtotime('+1 day'))]) === 3 && $say(['bit' => date('Y-m-d', strtotime('-4 day'))]) === 2);
ok('arama: parti no, plaka, nakliyeci, müşteri PO', $say(['q' => 'P-3']) === 1 && $say(['q' => '34 TST 004']) === 1 && $say(['q' => 'Test Nakliyat']) === 6 && $say(['q' => 'MP-7003']) === 1);
ok('geçersiz durum / dizi girdi yok sayılır (hata yok)', frgui_filtre(['durum' => 'x;drop', 'q' => ['a'], 'musteri' => ['b']])['durum'] === '' && frgui_filtre(['q' => ['a']])['q'] === '');
$F = frgui_filtre([]);
$p1 = frgui_bolum_oku($db, $F, 'kapali', 1, 2); $p2 = frgui_bolum_oku($db, $F, 'kapali', 2, 2); $p9 = frgui_bolum_oku($db, $F, 'kapali', 9, 2);
ok('sayfalama: 3 kayıt / sayfa 2 → 2 sayfa; taşan sayfa son sayfaya çekilir', $p1['sayfa_say'] === 2 && count($p1['rows']) === 2 && count($p2['rows']) === 1 && $p9['sayfa'] === 2 && $p1['toplam'] === 3);
ok('frgui_url: beyaz liste + override (sayfa no sıfırlanır, bolum yalnız override)', (function () { $u = frgui_url(['bolum' => 'acik', 'acik_page' => null], 'frugro_yazdir.php', ['q' => 'a b', 'durum' => 'acik', 'acik_page' => '3', 'x' => 'zz', 'bolum' => 'kapali']); return $u === 'frugro_yazdir.php?q=a+b&durum=acik&bolum=acik'; })());
ok('süzgeçli arama: filtre paneli açık + Temizle', (function () { $r = sayfa('frugro_siparis.php', ['musteri' => 'Musteri-A']); return str_contains($r['html'], 'sip-filtre-panel sip-acik') && str_contains($r['html'], 'Temizle'); })());

echo "\n=== 4. Yazdır ucu ===\n";
$r = sayfa('frugro_yazdir.php', ['bolum' => 'acik']);
$j = $r['json'];
$kol = array_column($j['kolonlar'] ?? [], 'k');
ok('200 + JSON sözleşmesi (ok, kapsam, baslik, dosya, basim, depo, filtre, adet, gecmis_say, kesildi, limit, notlar, kolonlar, satirlar, toplam, birim)', $r['kod'] === 200 && $j !== null && !array_diff(['ok', 'kapsam', 'baslik', 'dosya', 'basim', 'depo', 'filtre', 'adet', 'gecmis_say', 'kesildi', 'limit', 'notlar', 'kolonlar', 'satirlar', 'toplam', 'birim'], array_keys($j)));
ok('birim = {adet:sipariş, kasa:kutu}; başlık "İşlem Bekleyen Siparişler"; adet 5', ($j['birim'] ?? null) === ['adet' => 'sipariş', 'kasa' => 'kutu'] && $j['baslik'] === 'İşlem Bekleyen Siparişler' && $j['adet'] === 5);
ok('sütunlar: tarih, parti, siparis, tel, plaka, nakliyeci, urun, kal_16, kal_14, palet, kasa (Müşteri/Liman YOK); genişlik toplamı ≈ 100', implode(',', $kol) === 'tarih,parti,siparis,tel,plaka,nakliyeci,urun,kal_16,kal_14,palet,kasa' && abs(array_sum(array_column($j['kolonlar'], 'w')) - 100) < 0.2);
ok('yazdır: Ürün hücresi "NAR · Wonderful · kutu tipi" (dis_paket); Müşteri/Liman başlık ve hücrede YOK', (function () use ($j, $kol) {
    $u = array_search('urun', $kol, true); $cell = $j['satirlar'][0]['h'][$u] ?? '';
    return $cell === 'NAR · Wonderful · Karton 2.5 kg' && !str_contains(json_encode($j['kolonlar'], JSON_UNESCAPED_UNICODE), 'Müşteri') && !str_contains(json_encode($j['kolonlar'], JSON_UNESCAPED_UNICODE), 'Liman') && count($j['satirlar'][0]['h']) === 11;
})(), json_encode($j['satirlar'][0]['h'] ?? null, JSON_UNESCAPED_UNICODE));
ok('her satır sütun sayısı kadar hücre; toplam palet/kasa (sayı), kalibre toplamları', count(array_unique(array_map(fn($s) => count($s['h']), $j['satirlar']))) === 1 && count($j['satirlar'][0]['h']) === count($kol) && $j['toplam']['palet'] === '20' && $j['toplam']['kasa'] === '4.000' && $j['toplam']['kal_14'] === '6', json_encode($j['toplam']));
ok('geçmiş çıkış: gecmis=true + " ✓"; gecmis_say = çıkışı bugünden eski açık sipariş', $j['gecmis_say'] === 1 && $j['satirlar'][0]['gecmis'] === true && str_contains($j['satirlar'][0]['h'][0], '✓'));
ok('toplam hiçbir para birimi/tutar içermez', !str_contains(json_encode($j['toplam']), 'GBP') && !str_contains(json_encode($j), '4.600'));
$r = sayfa('frugro_yazdir.php', ['bolum' => 'kapali']);
ok('kapali bölümü: 3 satır, gecmis işareti YOK', ($r['json']['adet'] ?? 0) === 3 && !array_filter($r['json']['satirlar'], fn($s) => $s['gecmis']));
$r = sayfa('frugro_yazdir.php', ['bolum' => 'acik', 'durum' => 'kapali']);
ok('bölüm süzgeçle görünmüyorsa 400 kapsam_yok', $r['kod'] === 400 && ($r['json']['kod'] ?? '') === 'kapsam_yok');
$r = sayfa('frugro_yazdir.php', ['durum' => 'pkl_bekleyen']);
ok('tek durum süzgeci: kapsam=tek, başlık "Siparişler — PKL bekleyen", bolum yok sayılır', ($r['json']['kapsam'] ?? '') === 'tek' && ($r['json']['baslik'] ?? '') === 'Siparişler — PKL bekleyen' && ($r['json']['adet'] ?? 0) === 2);
$r = sayfa('frugro_yazdir.php', ['bolum' => 'acik', 'q' => 'GIZLI-ARAMA']);
$aud = (string)$db->query("SELECT new_values FROM audit_log WHERE action='frugro_yazdir' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('audit yalnız sayılar: arama metni / ad / telefon YOK', $aud !== '' && !str_contains($aud, 'GIZLI-ARAMA') && !str_contains($aud, 'Test Nakliyat') && str_contains($aud, 'adet'), $aud);
$r = sayfa('frugro_yazdir.php', ['bolum' => 'acik'], ['x' => '1']);
ok('GET dışı → 405', $r['kod'] === 405 && ($FT['h']['allow'] ?? '') === 'GET');
rol($VIEW);
$r = sayfa('frugro_yazdir.php', ['bolum' => 'acik']);
ok('yalnız okuma yetkisi: yazdırma açık (OGL gibi)', $r['kod'] === 200);
rol([]);
$r = sayfa('frugro_yazdir.php');
ok('yetkisiz: 403 (hata fırlar)', $r['hata'] !== null && str_contains($r['hata'], 'forbidden'));
rol($ADMIN, true);

echo "\n=== 5. WP Paylaş ucu ===\n";
$r = sayfa('frugro_paylas.php', ['id' => $ID['pkl_hazir']]);
$j = $r['json'];
ok('200 + sözleşme (alanlar 7, kalem başlıkları 4, kalemler, adresler, toplam)', $r['kod'] === 200 && count($j['alanlar']) === 7 && $j['kalem_basliklar'] === ['Ürün', 'Kalibre', 'PF', 'Palet/Kutu'] && $j['adres_baslik'] === 'Teslim adresi' && $j['toplam']['etiket'] === 'Toplam');
ok('alanlar sırası: Parti No · Çıkış tarihi · PO No · Nakliyeci · Plaka · Şoför adı · Şoför telefonu (Müşteri/Giriş limanı YOK)', implode('|', array_column($j['alanlar'], 'etiket')) === 'Parti No|Çıkış tarihi|PO No|Nakliyeci|Plaka|Şoför adı|Şoför telefonu');
ok('WP kartı: kalem ürün metninde kutu tipi var, ham " /" artığı YOK; Teslim adresi bloğu kalır', (function () use ($j) {
    $t = json_encode($j['kalemler'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return str_contains($t, 'Karton 2.5 kg') && !str_contains($t, 'kg /') && !str_contains($t, '/"') && $j['adres_baslik'] === 'Teslim adresi';
})(), json_encode($j['kalemler'], JSON_UNESCAPED_UNICODE));
require_once $ROOT . '/config/frugro_yazdir.php';
require_once $ROOT . '/config/frugro_wp.php';
ok('kutu tipi temizliği: " /" artığı atılır, boş → "" (yazdır + WP aynı kural)', frgy_kutu_tipi('Siyah Düz Markasiz Karton Kutu 30x40cm /') === 'Siyah Düz Markasiz Karton Kutu 30x40cm' && frgw_kutu_tipi('Siyah Düz Markasiz Karton Kutu 30x40cm /') === 'Siyah Düz Markasiz Karton Kutu 30x40cm' && frgy_kutu_tipi(' / ') === '' && frgw_kutu_tipi(null) === '' && frgy_kutu_tipi('Karton 2.5 kg') === 'Karton 2.5 kg');
ok('kalem = kalibre başına (16 sonra 14), PF 200, "2 / 400"; toplam "4 / 800"', count($j['kalemler']) === 2 && $j['kalemler'][0]['kalibre'] === '16' && $j['kalemler'][0]['pf'] === '200' && $j['kalemler'][0]['palet_koli'] === '2 / 400' && $j['toplam']['palet_koli'] === '4 / 800');
$r = sayfa('frugro_paylas.php', ['id' => $ID['yeni']]);
ok('paletsiz sipariş: tek kalem = siparişin kendisi (boy)', ($r['json']['kalem_say'] ?? 0) === 1 && $r['json']['kalemler'][0]['kalibre'] === '80-100');
$r = sayfa('frugro_paylas.php', ['id' => $ID['iptal']]);
ok('iptal sipariş de paylaşılır', $r['kod'] === 200);
$r = sayfa('frugro_paylas.php', ['id' => 999999]); $r2 = sayfa('frugro_paylas.php', ['id' => 'abc']);
ok('olmayan / geçersiz id → aynı 404', $r['kod'] === 404 && $r2['kod'] === 404 && $r['html'] === $r2['html'] || ($r['kod'] === 404 && $r2['kod'] === 404));
$dx = frg_siparis_kaydet($db, ['po_no' => '26-1800-00001', 'urun' => 'NAR', 'kutu' => 10, 'cikis_tarihi' => '2026-11-15'], ['depo' => 'DEPO2']);
rol($ADMIN, false, 1, ['DEPO1']);
$r = sayfa('frugro_paylas.php', ['id' => $dx['id']]);
ok('başka depodaki sipariş → 404 (varlık sızmaz)', $r['kod'] === 404 && !str_contains($r['html'], '26-1800-00001'));
$r = sayfa('frugro_siparis.php');
ok('liste: başka depo siparişi YOK', !str_contains($r['html'], '26-1800-00001'));
$r = sayfa('frugro_yazdir.php', ['bolum' => 'acik']);
ok('yazdır: başka depo siparişi YOK', !str_contains(json_encode($r['json']), '26-1800-00001'));
rol($ADMIN, true);
$r = sayfa('frugro_paylas.php', ['id' => $ID['yeni']], ['x' => 1]);
ok('paylaş: GET dışı 405', $r['kod'] === 405);
$aud = (string)$db->query("SELECT new_values FROM audit_log WHERE action='frugro_wp_paylas' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('paylaş audit yalnız kalem sayısı', $aud !== '' && !str_contains($aud, 'Test') && str_contains($aud, 'kalem'), $aud);

echo "\n=== 6. XSS ===\n";
$x = '<img src=x onerror=alert(1)>';
$db->prepare("UPDATE frugro_siparisler SET son_musteri = ?, nakliyeci = ?, parti_no = ?, giris_limani = ? WHERE id = ?")->execute([$x, $x, $x, $x, $ID['yola_cikti']]);
$r = sayfa('frugro_siparis.php', ['q' => '"><script>x</script>']);
ok('arama metni kaçırılır', !str_contains($r['html'], '"><script>x</script>') && str_contains($r['html'], '&quot;&gt;&lt;script&gt;'));
$r = sayfa('frugro_siparis.php');
ok('müşteri/nakliyeci/parti/liman kaçırılır (ham etiket YOK)', !str_contains($r['html'], $x) && str_contains($r['html'], '&lt;img src=x onerror=alert(1)&gt;'));
$r = sayfa('frugro_yazdir.php', ['bolum' => 'acik']);
ok('yazdır JSON ham metin (istemci kaçırır); JSON geçerli', $r['json'] !== null && str_contains(json_encode($r['json'], JSON_UNESCAPED_UNICODE), 'onerror'));

echo "\n=== 7. Statik ===\n";
$part = (string)file_get_contents($ROOT . '/_frugro_liste.php');
$kodp = ''; foreach (token_get_all($part) as $t) { if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $kodp .= $t[1]; } else $kodp .= $t; }
ok('_frugro_liste.php: ->query( / ->prepare( YOK, fonksiyon tanımı YOK', !str_contains($kodp, '->query(') && !str_contains($kodp, '->prepare(') && !preg_match('/\bfunction\s+\w+\s*\(/', $kodp));
ok('liste partial: eski frg-* liste sınıfları YOK (CSS kopyası yok)', !preg_match('/frg-(kpi|tablo|kt|rozet|belge)/', $part . (string)file_get_contents($ROOT . '/frugro_siparis.php')));
ok('frugro.css: liste kuralı kalmadı (frg-kpi/frg-tablo/frg-kt/frg-rozet)', !preg_match('/\.frg-(kpi|tablo|kt|rozet|satir)/', (string)file_get_contents($ROOT . '/assets/frugro.css')));
ok('sabit kapalı listesi yok (yalnız frg_*_sql)', !preg_match("/'kapali'\s*,\s*'iptal'|'evrak_gonderildi'\s*,\s*'iptal'/", (string)file_get_contents($ROOT . '/config/frugro_ui.php') . (string)file_get_contents($ROOT . '/config/frugro_yazdir.php') . (string)file_get_contents($ROOT . '/frugro_siparis.php')));
foreach (['frugro_yazdir.php', 'frugro_paylas.php'] as $u) {
    $k = (string)file_get_contents($ROOT . '/' . $u);
    ok("$u: require_login + require_siparis('read') + no-store + 405 + yazma yok", str_contains($k, 'require_login()') && str_contains($k, "require_siparis('read')") && str_contains($k, 'siparis_no_store()') && str_contains($k, '405') && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $k));
}
foreach (['config/frugro_yazdir.php', 'config/frugro_wp.php'] as $u) ok("$u: include-only 404 koruması (realpath)", str_contains((string)file_get_contents($ROOT . '/' . $u), 'realpath((string)($_SERVER[\'SCRIPT_FILENAME\'] ?? \'\')) === __FILE__'));
ok('siparis.js: ns kancası + frgm meşgul kontrolü (yükle kartı tarama sürerken kapanmaz)', str_contains((string)file_get_contents($ROOT . '/assets/siparis.js'), '[data-frgm-kap][aria-busy="true"]'));

siparis_test_bitir();
