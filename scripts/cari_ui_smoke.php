<?php
// =========================================================
// scripts/cari_ui_smoke.php — Cari İşlemler sayfaları/uçları: GERÇEK HTML/JSON üzerinde PHP düzeyi testler
//   php scripts/cari_ui_smoke.php        → çıkış kodu 0 = hepsi geçti
// Her istek ALT SÜREÇTE (scripts/_cari_istek.php) gerçek exit/header ile çalışır; DB geçici dosya SQLite + uydurma veri. Canlı DB'ye dokunmaz.
// Kapsam: şema yok · boş · dolu (3 para birimi) · pasif · iptalli ekstre · XSS kaçışı · HTML denge · csrf · cari_kaydet JSON/PRG · ekstre yazdır/CSV/XLSX.
// =========================================================
require_once __DIR__ . '/_cari_test_lib.php';
require_once $ROOT . '/vendor/autoload.php';

$db = db();
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function get(string $dosya, array $get = [], array $ek = []): array { return cari_t_istek(['dosya' => $dosya, 'get' => $get] + $ek); }
function fetch_get(string $dosya, array $get = []): array { return cari_t_istek(['dosya' => $dosya, 'get' => $get, 'server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]); }
function post(array $p, bool $json = true, array $ek = []): array
{
    return cari_t_istek(['dosya' => 'cari_kaydet.php', 'post' => $p + ['csrf' => 'test-csrf-belirteci'], 'server' => $json ? ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ACCEPT' => 'application/json'] : []] + $ek);   // CLI'de headers_list() boş: csrf_check JSON'u Accept'ten anlar
}
function js(array $r): array { $j = json_decode(trim($r['govde']), true); return is_array($j) ? $j : ['_cozulemedi' => $r['govde']]; }
function uyari_yok(array $r): bool { return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error):/', $r['govde']) && $r['hata'] === null; }
function baslik(array $r, string $ad): ?string { foreach ($r['basliklar'] as $b) if (stripos($b, $ad . ':') === 0) return trim(substr($b, strlen($ad) + 1)); return null; }

/** Cari bölgesi: ilk <div class="cari ...> ile cari.js betiği arası (sayfa kabuğu hariç). */
function bolge(string $h): string
{
    $a = strpos($h, '<div class="cari');
    $b = strpos($h, '<script src=', $a === false ? 0 : $a);
    if ($a === false) return $h;
    return $b === false ? substr($h, $a) : substr($h, $a, $b - $a);
}
/** Etiket dengesi + yinelenen id. @return list<string> hatalar */
function html_hata(string $h): array
{
    $h = preg_replace('#<script\b[^>]*>.*?</script>#si', '<script></script>', $h) ?? $h;
    $h = preg_replace('#<!--.*?-->#s', '', $h) ?? $h;
    $void = ['br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'wbr', 'col'];
    $st = []; $hata = [];
    preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(/?)>#s', $h, $m, PREG_SET_ORDER);
    foreach ($m as $t) {
        $ad = strtolower($t[2]);
        if (in_array($ad, $void, true) || $t[4] === '/') continue;
        if ($t[1] === '/') {
            $son = array_pop($st);
            if ($son !== $ad) { $hata[] = "kapanış </$ad> beklenen <" . ($son ?? 'yok') . '>'; if (count($hata) > 3) break; }
        } else $st[] = $ad;
    }
    if ($st) $hata[] = 'kapanmamış: ' . implode(',', array_slice($st, -4));
    preg_match_all('/\sid="([^"]+)"/', $h, $ids);
    foreach (array_count_values($ids[1]) as $id => $n) if ($n > 1) $hata[] = "yinelenen id=$id";
    return $hata;
}
/** Modal/gizli form/noscript hariç birincil düğme sayısı. */
function birincil_say(string $h): int
{
    $h = preg_replace('#<div class="pm-overlay".*?</form>\s*</div>\s*</div>#s', '', $h) ?? $h;
    $h = preg_replace('#<noscript>.*?</noscript>#s', '', $h) ?? $h;
    return preg_match_all('/class="[^"]*\bbtn-primary\b[^"]*"/', $h);
}
/** Her form: csrf alanı + (radyo/onay kutusu dışında) yinelenen name yok. */
function form_hata(string $h): array
{
    $hata = [];
    preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $h, $fm, PREG_SET_ORDER);
    foreach ($fm as $i => $f) {
        $post = stripos($f[1], 'method="post"') !== false;
        if ($post && !str_contains($f[2], 'name="csrf"')) $hata[] = "form#$i csrf yok";
        preg_match_all('/<(?:input|select|textarea)\b([^>]*)>/', $f[2], $al);
        $say = [];
        foreach ($al[1] as $a) {
            if (!preg_match('/\bname="([^"]+)"/', $a, $n)) continue;
            if (preg_match('/type="(radio|checkbox)"/', $a)) continue;
            $say[$n[1]] = ($say[$n[1]] ?? 0) + 1;
        }
        foreach ($say as $ad => $c) if ($c > 1) $hata[] = "form#$i: '$ad' $c kez";
    }
    return $hata;
}

// ═══ 1. Şema yok ═══
echo "=== 1. Şema yok (tablolar kurulmamış) ===\n";
foreach (['cari_kartlari.php', 'cari_fisler.php', 'cari_dekontlar.php', 'cari_tanimlari.php', 'cari_ekstre.php'] as $d) {
    $r = get($d, $d === 'cari_ekstre.php' ? ['id' => 1] : []);
    ok("$d: şema yok → uyarı kartı + migrate.php#cari, çökme yok", uyari_yok($r) && str_contains($r['govde'], 'Cari tabloları kurulmamış') && str_contains($r['govde'], 'migrate.php#cari'), substr($r['govde'], 0, 200) . ($r['hata'] ?? ''));
    ok("$d: şema yok → sekme şeridi basılmaz", !str_contains($r['govde'], 'cari-sekmeler'));
}
$r = fetch_get('cari_kartlari.php', ['parca' => 'detay', 'id' => 1]);
ok('şema yok + JSON isteği → 503 JSON', $r['kod'] === 503 && (js($r)['ok'] ?? null) === false, json_encode($r['kod']));
$r = post(['islem' => 'kart_kaydet', 'unvan' => 'X Ltd']);
ok('cari_kaydet şema yok → 503 JSON', $r['kod'] === 503 && (js($r)['ok'] ?? null) === false);

// ═══ 2. Boş (tablolar var, veri yok) ═══
cari_t_sema_kur($db);
echo "\n=== 2. Boş durum ===\n";
$r = get('cari_kartlari.php');
ok('boş: kartlar "Henüz cari yok" + İlk cariyi ekle (birincil) + içe aktar bağlantısı', uyari_yok($r) && str_contains($r['govde'], 'Henüz cari yok') && str_contains($r['govde'], 'İlk cariyi ekle') && str_contains($r['govde'], 'cari_tanimlari.php#ice-aktar'));
ok('boş: sekme şeridi var, tek birincil düğme', str_contains($r['govde'], 'cari-sekmeler') && birincil_say(bolge($r['govde'])) === 1, (string)birincil_say(bolge($r['govde'])));
$r = get('cari_fisler.php');
ok('boş: fişler "Bu filtreyle kayıt yok"', uyari_yok($r) && str_contains($r['govde'], 'Bu filtreyle kayıt yok'));
$r = get('cari_tanimlari.php');
ok('boş: tanımlar — ödeme şekilleri tohumu 6 satır, grup listesi boş durum', uyari_yok($r) && substr_count($r['govde'], 'data-tanim-duzen') === 6 && str_contains($r['govde'], 'Henüz tanım yok'));

// ═══ 3. Dolu ═══
$V = cari_t_ornek_veri($db);
$K = $V['kart']; $H = $V['hareket'];
$XSS = '<img src=x onerror=alert(1)>';
$xk = cari_kart_kaydet($db, ['unvan' => 'Zararlı ' . $XSS . ' A.Ş.', 'tip' => 'musteri', 'para_birimi' => 'TRY', 'aktif' => '1', 'notlar' => '<script>alert(1)</script>', 'yetkili' => '"><script>alert(2)</script>'], 1);
$xid = (int)$xk['id'];
cari_hareket_ekle($db, ['cari_id' => $xid, 'tur' => 'borc_dekont', 'tarih' => date('Y-m-d'), 'tutar' => '10,00', 'para_birimi' => 'TRY', 'aciklama' => '"><script>alert(3)</script>', 'evrak_no' => '<b>E</b>'], 1);
cari_tanim_kaydet($db, 'grup', '<i>Grup</i>', 99, 1);

echo "\n=== 3. Kartlar: liste ===\n";
$r = get('cari_kartlari.php');
$b = bolge($r['govde']);
ok('liste: uyarı yok, 200', uyari_yok($r) && $r['kod'] === 200);
ok('liste: 14 kart satırı (13 örnek + 1 XSS)', preg_match_all('/<a class="cari-kart[ "]/', $b) === 14, (string)preg_match_all('/<a class="cari-kart[ "]/', $b));
ok('liste: pasif kart (id=' . $K['Eski Müşteri Örnek Ltd.'] . ') cari-kart--pasif + data-pasif=1 + Pasif rozeti', (bool)preg_match('/cari-kart--pasif" href="cari_kartlari\.php\?id=' . $K['Eski Müşteri Örnek Ltd.'] . '"[^>]*data-pasif="1"/', $b) && str_contains($b, 'cari-rozet cari-rozet--pasif">Pasif'));
ok('liste: kancalar #crListe #crArama #crSonucYok #crSayi #crCariVeri .cari-cip[data-filtre]', str_contains($b, 'id="crListe"') && str_contains($b, 'id="crArama"') && str_contains($b, 'id="crSonucYok"') && str_contains($b, 'id="crSayi"') && str_contains($b, 'id="crCariVeri"') && str_contains($b, 'data-filtre="bakiye"'));
ok('liste: data-ara küçük harf (TR) ünvan+kod', str_contains($b, 'data-ara="örnek tarım ltd. c00001'));
ok('liste: bakiye çipi biçimi 125.400,00 ₺ + yön harfi B + aria-label "Borçlu"', str_contains($b, '125.400,00 ₺') && str_contains($b, 'aria-label="125.400,00 Türk Lirası, Borçlu"'));
ok('liste: alacaklı çip A + Alacaklı etiketi (EUR)', str_contains($b, 'aria-label="4.800,00 Euro, Alacaklı"') && str_contains($b, 'cari-bk--a'));
ok('liste: üç para birimi ÖZETTE ayrı çipler, karışık toplam YOK', preg_match('/Toplam borçlu<\/b>(.*?)<\/div>/s', $b, $m1) && substr_count($m1[1], 'class="cari-bk ') === 3 && str_contains($m1[1], '₺') && str_contains($m1[1], '$') && str_contains($m1[1], '€'));
ok('liste: Tümü/Borçlu/Alacaklı/Pasif çipleri (pasif sayısı 1)', preg_match('/data-deger="pasif"[^>]*>Pasif <span class="cari-cip-say">1</', $b) === 1);
ok('liste: seçim yok → "Soldan bir cari seçin" + .cari-ana--detay YOK', str_contains($b, 'Soldan bir cari seçin') && !str_contains($b, 'cari-ana--detay'));
ok('liste: seçim yokken birincil düğme yok (+ Yeni nötr)', birincil_say($b) === 0, (string)birincil_say($b));
ok('liste: HTML denge / yinelenen id yok', html_hata($b) === [], json_encode(html_hata($b)));
ok('liste: formlarda csrf, yinelenen alan adı yok', form_hata($b) === [], json_encode(form_hata($b)));
ok('liste: cari.css + cari.js ?v= ile yüklenir', (bool)preg_match('#assets/cari\.css\?v=\d+#', $r['govde']) && (bool)preg_match('#assets/cari\.js\?v=\d+#', $r['govde']));
$r = get('cari_kartlari.php', ['yeni' => '1']);
ok('?yeni=1 → #cari[data-yeni="kart"]', str_contains($r['govde'], 'data-yeni="kart"'));
$r = get('cari_kartlari.php', ['q' => 'Ambalaj']);
ok('JS\'siz ?q= → yalnız eşleşen kartlar (2 ambalaj: Deneme Ambalaj + Akdeniz Kasa grubu değil → ünvan/kod/telefon/vergi aranır)', preg_match_all('/<a class="cari-kart[ "]/', bolge($r['govde'])) === 1);
$r = get('cari_kartlari.php', ['q' => 'yokboyleadxyz']);
ok('eşleşme yok → #crSonucYok görünür', preg_match('/id="crSonucYok"(?! hidden)/', $r['govde']) === 1);

echo "\n=== 3b. Kartlar: detay + ekstre ===\n";
$id1 = $K['Örnek Tarım Ltd.'];
$r = get('cari_kartlari.php', ['id' => $id1]);
$b = bolge($r['govde']);
ok('detay: uyarı yok', uyari_yok($r));
ok('detay: .cari-ana--detay + #crDetay[data-id] + aria-current seçili satır', str_contains($b, 'cari-ana cari-ana--detay') && str_contains($b, 'id="crDetay" data-id="' . $id1 . '"') && (bool)preg_match('/data-id="' . $id1 . '"[^>]*aria-current="true"/', $b));
ok('detay: gerekli ID\'ler', array_reduce(['crPanelEkstre', 'crPanelBilgi', 'crSekmeEkstre', 'crSekmeBilgi', 'crMenuDekont', 'crMenuDiger', 'crModalHareket', 'crModalKart', 'crModalIptal', 'crCariVeri', 'crKartVeri', 'crIslemForm', 'crEkstreForm', 'crHareketBaslik'], fn($c, $x) => $c && str_contains($b, 'id="' . $x . '"'), true));
ok('detay: bakiye kutuları data-kurus/data-pb (TRY 12540000, USD 4825000, EUR -480000), sıra TRY,USD,EUR', (bool)preg_match('/data-kurus="12540000" data-pb="TRY".*data-kurus="4825000" data-pb="USD".*data-kurus="-480000" data-pb="EUR"/s', $b));
ok('detay: bakiye metni Borçlu (bize borçlu) / Alacaklı (biz borçluyuz)', str_contains($b, 'Borçlu <small>(bize borçlu)</small>') && str_contains($b, 'Alacaklı <small>(biz borçluyuz)</small>'));
ok('detay: tek birincil düğme = Tahsilat', birincil_say($b) === 1 && str_contains($b, 'btn btn-primary" data-cr-modal="hareket" data-tur="tahsilat" data-cari="' . $id1 . '"'), (string)birincil_say($b));
ok('detay: menü öğeleri (Borç/Alacak dekontu, Virman, Açılış) + Pasifleştir/Sil formu bağlı (form="crIslemForm")', str_contains($b, 'data-tur="virman" data-cari="' . $id1 . '"') && str_contains($b, 'form="crIslemForm" name="islem" value="kart_durum"') && str_contains($b, 'Sil (hareketi var)'));
ok('detay: Ekstre Yazdır/Excel bağlantıları', str_contains($b, 'cari_ekstre.php?id=' . $id1 . '&amp;bicim=yazdir') && str_contains($b, 'cari_ekstre.php?id=' . $id1 . '&amp;bicim=xlsx'));
preg_match('#<form class="cari-ekstre-filtre".*?</form>#s', $b, $ef);
ok('ekstre: Bu yıl varsayılan, pb segmenti yalnız hareketli birimler (TRY USD EUR; "Tümü" yok), TRY seçili', str_contains($b, 'data-donem="yil" aria-pressed="true"') && substr_count($ef[0] ?? '', 'name="pb" value=') === 3 && str_contains($ef[0] ?? '', 'value="TRY" checked') && !str_contains($ef[0] ?? '', 'name="pb" value=""'));
ok('ekstre: Devir satırı + 10 geçerli TRY hareket + dönem toplamı borç 471.400,00 / alacak 346.000,00 (iptal hariç)', str_contains($b, 'Devir (dönem öncesi bakiye)') && str_contains($b, '471.400,00') && str_contains($b, '346.000,00') && str_contains($b, '<span class="cari-yon-b">125.400,00<small>B</small></span>'));
ok('ekstre: iptal satırı varsayılan GİZLİ (yanlış cariye girilmiş tahsilat görünmez)', !str_contains($b, 'Yanlış cariye girilmiş tahsilat'));
ok('ekstre: virman satırında Karşı cari bağlantısı', str_contains($b, 'Karşı cari: <a href="cari_kartlari.php?id=' . $K['Yeşil Ova Üretici Birliği'] . '">Yeşil Ova Üretici Birliği</a>'));
ok('ekstre: satır başına hareket id (data-id) + iptal düğmesi data-id/data-kopya', str_contains($b, 'data-cr-modal="iptal" data-id="' . $H['c1_tah3'] . '"') && str_contains($b, 'data-kopya="'));
ok('ekstre: tablo + kart görünümü aynı veriden (cari-ekstre-satir 9 adet: 10 hareketten iptal hariç)', substr_count($b, 'class="cari-ekstre-satir') === 9, (string)substr_count($b, 'class="cari-ekstre-satir'));
ok('detay: HTML denge / yinelenen id yok', html_hata($b) === [], json_encode(html_hata($b)));
ok('detay: formlarda csrf + yinelenen ad yok', form_hata($b) === [], json_encode(form_hata($b)));

$r = get('cari_kartlari.php', ['id' => $id1, 'parca' => 'ekstre', 'iptal' => '1'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('ekstre iptaller gösterilir: iptal satırı üstü çizili sınıf + rozet + neden + bakiye "—" + iptal düğmesi YOK', uyari_yok($r) && str_contains($r['govde'], 'cari-iptal') && str_contains($r['govde'], 'Yanlış cari seçildi') && str_contains($r['govde'], '<span class="cari-yon-d">—</span>') && preg_match('#<tr class="cari-iptal" data-id="' . $H['c1_tah_hatali'] . '">.*?<td class="cari-islem-hucre"></td>#s', $r['govde']) === 1);
ok('ekstre iptaller gösterilince dönem toplamı DEĞİŞMEZ (iptal toplama girmez)', str_contains($r['govde'], '471.400,00') && str_contains($r['govde'], '346.000,00') && str_contains($r['govde'], '(1 iptal)'));
ok('ekstre parçası: yalnız #crPanelEkstre (header/footer yok)', str_starts_with(trim($r['govde']), '<div class="cari-panel" id="crPanelEkstre"') && !str_contains($r['govde'], '<html'));
$r = get('cari_kartlari.php', ['id' => $id1, 'parca' => 'ekstre', 'pb' => 'USD'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('ekstre pb=USD: yalnız USD (48.250,00 B), TRY tutarları yok', str_contains($r['govde'], 'Dönem toplamı (USD)') && str_contains($r['govde'], '48.250,00') && !str_contains($r['govde'], '125.400,00') && str_contains($r['govde'], '$'));
$r = get('cari_kartlari.php', ['id' => $id1, 'parca' => 'ekstre', 'pb' => 'TRY', 'bas' => '2026-09-01', 'bit' => '2026-09-30'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('ekstre dönem: Devir = dönem öncesi geçerli bakiye (Eylül öncesi 51.400... → kuruş tam)', str_contains($r['govde'], 'Devir (dönem öncesi bakiye)') && str_contains($r['govde'], '01.09.2026') && str_contains($r['govde'], 'class="cari-ozel-tarih"') && !str_contains($r['govde'], 'class="cari-ozel-tarih" hidden'));
$r = get('cari_kartlari.php', ['id' => $id1, 'parca' => 'detay'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('parca=detay: #crDetay + ARDINDAN yenilenmiş menüler; header/footer yok', uyari_yok($r) && str_starts_with(trim($r['govde']), '<section class="cari-detay" id="crDetay"') && str_contains($r['govde'], '</section><ul class="cari-menu-liste" id="crMenuDekont"') && !str_contains($r['govde'], '<html'), substr(trim($r['govde']), 0, 120));
ok('parca=detay: HTML denge', html_hata($r['govde']) === [], json_encode(html_hata($r['govde'])));
$r = get('cari_kartlari.php', ['id' => $id1, 'parca' => 'kart'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
$j = js($r);
ok('parca=kart: JSON {ok, kart{unvan,kod,tip,grup_id,para_birimi,aktif,vergi_no,iban…}}', ($j['ok'] ?? false) === true && $j['kart']['unvan'] === 'Örnek Tarım Ltd.' && $j['kart']['para_birimi'] === 'TRY' && array_key_exists('grup_id', $j['kart']) && $j['kart']['iban'] === 'TR120006400000112345678901' && str_contains((string)baslik($r, 'Content-Type'), 'application/json'));
$r = get('cari_kartlari.php', ['id' => 99999, 'parca' => 'detay'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('parca=detay olmayan kart → 404', $r['kod'] === 404);
$r = get('cari_kartlari.php', ['id' => $id1, 'sekme' => 'bilgiler']);
ok('sekme=bilgiler: Bilgiler seçili, Ekstre paneli hidden; IBAN kopyala; telefon/e-posta bağlantı', str_contains($r['govde'], 'id="crSekmeBilgi" aria-selected="true"') && str_contains($r['govde'], 'id="crPanelEkstre" hidden') && str_contains($r['govde'], 'data-kopyala="TR120006400000112345678901"') && str_contains($r['govde'], 'mailto:muhasebe@ornek.test'));

echo "\n=== 3c. Pasif / hareketsiz kart ===\n";
$r = get('cari_kartlari.php', ['id' => $K['Eski Müşteri Örnek Ltd.']]);
$b = bolge($r['govde']);
ok('pasif: Pasif rozeti + uyarı metni + Tahsilat/Ödeme/Dekont disabled', str_contains($b, 'Bu cari pasif: yeni hareket girilemez') && preg_match('/data-tur="tahsilat" data-cari="\d+" disabled>/', $b) === 1 && preg_match('/aria-controls="crMenuDekont" disabled>/', $b) === 1 && str_contains($b, 'cari-rozet--pasif">Pasif</span></div>'));
ok('pasif: menüde "Aktifleştir"', str_contains($b, 'Aktifleştir') && html_hata($b) === []);
$r = get('cari_kartlari.php', ['id' => $K['Yeni Hareketsiz Cari']]);
$b = bolge($r['govde']);
ok('hareketsiz kart: boş ekstre durumu (İlk tahsilatı/ödemeyi ekle) + Sil etkin (hareketsiz)', str_contains($b, 'Bu carinin henüz hareketi yok') && str_contains($b, 'İlk tahsilatı ekle') && str_contains($b, 'value="kart_sil"') && !str_contains($b, 'Sil (hareketi var)') && html_hata($b) === []);

echo "\n=== 4. Fişler / Dekontlar ===\n";
$r = get('cari_fisler.php');
$b = bolge($r['govde']);
ok('fişler: uyarı yok, Bu yıl varsayılan, tür filtresi yalnız fiş türleri', uyari_yok($r) && str_contains($b, '<option value="tahsilat"') && str_contains($b, '<option value="acilis"') && !str_contains($b, '<option value="virman"'));
ok('fişler: özet şeridi para birimi ayrı (TRY · Türk Lirası, USD, EUR) — karışık toplam yok', str_contains($b, '<b>TRY · Türk Lirası</b>') && str_contains($b, '<b>USD · ABD Doları</b>') && str_contains($b, '<b>EUR · Euro</b>'));
ok('fişler: iptal satırı (cari-iptal) + "İptal" rozeti + neden; iptal düğmesi yok', preg_match('/<tr class="cari-iptal" data-id="' . $H['lojistik_hatali'] . '">.*?Tutar hatalı girildi.*?<td class="cari-islem-hucre"><\/td>/s', $b) === 1);
ok('fişler: virman satırı YOK, borç dekontu YOK', !str_contains($b, 'VRM-') && !str_contains($b, 'BDK-'));
ok('fişler: satır başına data-id + iptal düğmesi data-kopya', str_contains($b, 'data-cr-modal="iptal" data-id="' . $H['c1_tah3'] . '"'));
ok('fişler: tek birincil düğme (+ Tahsilat), Excel İndir menüsü (reports.export)', birincil_say($b) === 1 && str_contains($b, 'data-tur="tahsilat">+ Tahsilat') && str_contains($b, 'dl-menu') && str_contains($b, 'kapsam=liste&amp;sinif=fis&amp;bicim=xlsx'), (string)birincil_say($b));
ok('fişler: modal alanları (tur radyo ×6, hedef cari, kur, odeme_sekli, yon, evrak_no, aciklama, csrf, istek_anahtari, geri)', substr_count($b, 'name="tur" value=') >= 6 && str_contains($b, 'name="hedef_id"') && str_contains($b, 'name="kur"') && str_contains($b, 'name="odeme_sekli"') && str_contains($b, 'name="yon"') && str_contains($b, 'name="evrak_no"') && str_contains($b, 'name="aciklama"') && preg_match('/name="istek_anahtari" value="[0-9a-f]{16}"/', $b) === 1 && str_contains($b, 'name="geri" value="cari_fisler.php'));
ok('fişler: ödeme şekli seçenekleri tanımlardan (Nakit…Mahsup/Diğer)', str_contains($b, '<option value="Havale/EFT">') && str_contains($b, '<option value="Mahsup/Diğer">'));
ok('fişler: HTML denge / form csrf', html_hata($b) === [] && form_hata($b) === [], json_encode([html_hata($b), form_hata($b)]));
$r = get('cari_fisler.php', ['tur' => 'tahsilat', 'pb' => 'USD']);
$tb = (string)preg_replace('#<div class="pm-overlay".*#s', '', bolge($r['govde']));
ok('fişler tur=tahsilat&pb=USD: yalnız USD tahsilat (₺/€ tutarı ve ODE- yok)', str_contains($tb, 'TAH-') && !str_contains($tb, 'ODE-') && !str_contains($tb, '₺</strong>') && !str_contains($tb, '€</strong>') && str_contains($tb, '$</strong>'));
$r = get('cari_fisler.php', ['durum' => 'iptal']);
ok('fişler durum=iptal: yalnız iptal edilenler (2)', substr_count(bolge($r['govde']), '<tr class="cari-iptal"') === 2, (string)substr_count(bolge($r['govde']), '<tr class="cari-iptal"'));
$r = get('cari_fisler.php', ['cari_q' => 'Ambalaj']);
ok('fişler cari_q=Ambalaj: yalnız o carinin fişleri', str_contains($r['govde'], 'ODE-') && substr_count(bolge($r['govde']), '<tr class="') <= 3, (string)substr_count(bolge($r['govde']), '<tr class="'));
$r = get('cari_fisler.php', ['yeni' => 'odeme', 'cari' => $id1]);
ok('fişler ?yeni=odeme&cari=ID → data-yeni + data-yeni-cari', str_contains($r['govde'], 'data-yeni="odeme" data-yeni-cari="' . $id1 . '"'));
$r = get('cari_dekontlar.php');
$b = bolge($r['govde']);
ok('dekontlar: tür filtresi yalnız dekont türleri; ilk birincil = + Borç Dekontu', uyari_yok($r) && str_contains($b, '<option value="virman"') && !str_contains($b, '<option value="tahsilat"') && birincil_say($b) === 1 && str_contains($b, 'data-tur="borc_dekont">+ Borç Dekontu'));
ok('dekontlar: virman ÇİFTİ listede TEK satır "Kaynak → Hedef" (tabloda 1 VRM satırı)', preg_match_all('#<td><span class="cari-satir-ad">VRM-#', $b) === 1 && str_contains($b, 'aria-label="aktarıldı">&#8594;</span>'));
ok('dekontlar: modalda varsayılan tür borç dekontu seçili; açıklama zorunlu işaretli', str_contains($b, 'name="tur" value="borc_dekont" checked') && !str_contains($b, 'data-zorunlu-aciklama aria-hidden="true" hidden'));
ok('dekontlar: HTML denge / form csrf', html_hata($b) === [] && form_hata($b) === [], json_encode([html_hata($b), form_hata($b)]));
$r = get('cari_dekontlar.php', ['yeni' => 'virman']);
ok('dekontlar ?yeni=virman: virman alanları görünür', str_contains($r['govde'], 'data-yeni="virman"') && str_contains($r['govde'], 'name="tur" value="virman" checked') && str_contains($r['govde'], 'name="islem" value="virman_ekle"') && preg_match('/data-alan-grup="virman"(?! hidden)/', $r['govde']) === 1);
$r = get('cari_dekontlar.php', ['bas' => '', 'bit' => '', 's' => '1']);
ok('dekontlar Tümü (bas/bit boş): hata yok', uyari_yok($r) && str_contains($r['govde'], 'data-donem="tumu" aria-pressed="true"'));
$r = get('cari_dekontlar.php', ['s' => '9']);
ok('dekontlar geçersiz sayfa → çökme yok, boş durum', uyari_yok($r) && str_contains($r['govde'], 'Bu filtreyle kayıt yok'));

echo "\n=== 5. Tanımlar ===\n";
$r = get('cari_tanimlari.php');
$b = bolge($r['govde']);
ok('tanımlar: 3 sekme (Cari Grupları n, Ödeme Şekilleri 6, İçe Aktar 2 yeni)', str_contains($b, 'Ödeme Şekilleri <small>6</small>') && str_contains($b, 'İçe Aktar <small>2 yeni</small>') && str_contains($b, 'id="crPanelGrup"') && str_contains($b, 'id="crPanelOdeme" role="tabpanel" aria-labelledby="crTabOdeme" hidden'));
ok('tanımlar: kullanılan grupta (Ambalaj) Sil YOK, ödeme şekillerinde Sil var', (bool)preg_match('/Ambalaj<small>Sıra 1 · 2 kartta kullanılıyor.*?<\/li>/s', $b, $mm) && !str_contains($mm[0], 'tanim_sil') && substr_count($b, 'value="tanim_sil"') >= 6 + 1);
ok('tanımlar: aktif switch formu (role=switch, tanim_durum) + satır içi düzenleme formu (tanim_kaydet + id)', str_contains($b, 'role="switch" name="aktif"') && str_contains($b, 'name="islem" value="tanim_durum"') && preg_match('/cari-tanim-satir--duzen" hidden><form[^>]*><input type="hidden" name="csrf"[^>]*><input type="hidden" name="islem" value="tanim_kaydet">/', $b) === 1);
ok('tanımlar: bilgi kutusu (sabit listeler)', str_contains($b, 'hareket türleri sabittir'));
ok('tanımlar: HTML denge / form csrf', html_hata($b) === [] && form_hata($b) === [], json_encode([html_hata($b), form_hata($b)]));
$r = get('cari_tanimlari.php', ['sekme' => 'ice_aktar']);
$b = bolge($r['govde']);
ok('içe aktar: önizleme (2 Yeni, 3 Kartı var), "2 kartı içe aktar" düğmesi + ice_aktar formu', substr_count($b, 'cari-rozet cari-rozet--aktif">Yeni') >= 2 && str_contains($b, '2 kartı içe aktar') && str_contains($b, 'value="ice_aktar"') && str_contains($b, 'firma + tedarikçi') && str_contains($b, 'id="crPanelIce" role="tabpanel" aria-labelledby="crTabIce">'));

echo "\n=== 6. XSS kaçışı ===\n";
$sayfalar = [['cari_kartlari.php', []], ['cari_kartlari.php', ['id' => $xid]], ['cari_kartlari.php', ['id' => $xid, 'sekme' => 'bilgiler']], ['cari_fisler.php', []], ['cari_dekontlar.php', []], ['cari_tanimlari.php', []], ['cari_ekstre.php', ['id' => $xid, 'bas' => '', 'bit' => '']]];
foreach ($sayfalar as [$d, $g]) {
    $r = get($d, $g);
    $ham = preg_match('/<img src=x onerror|<script>alert|<b>E<\/b>|<i>Grup<\/i>|"><script>/', preg_replace('#<script type="application/json".*?</script>#s', '', $r['govde']));
    ok("XSS: $d " . json_encode($g) . ' → kaçışlı, ham etiket yok', $ham === 0 && uyari_yok($r));
}
$r = get('cari_kartlari.php', ['id' => $xid]);
ok('XSS: kaçışlı metin gerçekten basıldı (&lt;img …) ve JSON bloklarında <', str_contains($r['govde'], '&lt;img src=x onerror=alert(1)&gt;') && str_contains($r['govde'], '<img'));

echo "\n=== 7. Yönetici değil ===\n";
foreach (['cari_kartlari.php', 'cari_fisler.php', 'cari_dekontlar.php', 'cari_tanimlari.php', 'cari_ekstre.php'] as $d) {
    $r = get($d, ['id' => 1], ['admin' => false]);
    ok("$d: yönetici değil → forbidden, veri sızmaz", str_contains((string)$r['hata'], 'forbidden') && !str_contains($r['govde'], 'Örnek Tarım'));
}

echo "\n=== 8. cari_kaydet.php ===\n";
$r = cari_t_istek(['dosya' => 'cari_kaydet.php', 'get' => []]);
ok('GET → 405 + Allow: POST', $r['kod'] === 405 && baslik($r, 'Allow') === 'POST');
$n0 = say('cari_kartlar');
$r = cari_t_istek(['dosya' => 'cari_kaydet.php', 'post' => ['islem' => 'kart_kaydet', 'unvan' => 'Csrfsiz Ltd', 'csrf' => 'yanlis'], 'server' => ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ACCEPT' => 'application/json']]);
ok('csrf yanlış → 403 JSON, yazma yok', $r['kod'] === 403 && say('cari_kartlar') === $n0 && (js($r)['ok'] ?? null) === false, $r['govde']);
$r = cari_t_istek(['dosya' => 'cari_kaydet.php', 'post' => ['islem' => 'kart_kaydet', 'unvan' => 'Csrfsiz Ltd'], 'server' => ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ACCEPT' => 'application/json']]);
ok('csrf hiç yok → 403', $r['kod'] === 403 && say('cari_kartlar') === $n0);
$r = post(['islem' => 'kart_kaydet', 'unvan' => 'Yetkisiz Ltd'], true, ['admin' => false]);
ok('admin değil → 403 JSON {ok:false}, yazma yok', $r['kod'] === 403 && (js($r)['ok'] ?? null) === false && say('cari_kartlar') === $n0, $r['govde']);

// kart
$r = post(['islem' => 'kart_kaydet', 'unvan' => '', 'tip' => 'musteri']);
$j = js($r);
ok('kart_kaydet geçersiz → 422 + alanlar{unvan}', $r['kod'] === 422 && ($j['ok'] ?? true) === false && isset($j['alanlar']['unvan']), $r['govde']);
$a0 = say('audit_log', "module = 'cari'");
$r = post(['islem' => 'kart_kaydet', 'id' => '', 'unvan' => 'Yeni Test Cari A.Ş.', 'kod' => '', 'tip' => 'tedarikci', 'para_birimi' => 'EUR', 'aktif' => '1', 'geri' => 'cari_kartlari.php']);
$j = js($r);
$yid = (int)($j['id'] ?? 0);
ok('kart_kaydet yeni → 200 {ok,id} + yonlendir=cari_kartlari.php?id=ID + audit +1', $r['kod'] === 200 && ($j['ok'] ?? false) === true && $yid > 0 && ($j['yonlendir'] ?? '') === 'cari_kartlari.php?id=' . $yid && say('audit_log', "module = 'cari'") === $a0 + 1, $r['govde']);
$kk = cari_kart_oku($db, $yid);
ok('kart_kaydet: kod otomatik (C000nn), onay kutusu işaretli → aktif=1, pb EUR', $kk !== null && preg_match('/^C\d{5}$/', (string)$kk['kod']) === 1 && (int)$kk['aktif'] === 1 && $kk['para_birimi'] === 'EUR');
$r = post(['islem' => 'kart_kaydet', 'id' => (string)$yid, 'unvan' => 'Yeni Test Cari A.Ş.', 'kod' => $kk['kod'], 'tip' => 'tedarikci', 'para_birimi' => 'EUR', 'geri' => 'cari_kartlari.php?id=' . $yid]);
ok('kart_kaydet düzenleme: "aktif" gelmedi (işaretsiz kutu) → pasif', ($j = js($r))['ok'] === true && (int)cari_kart_oku($db, $yid)['aktif'] === 0);
$r = post(['islem' => 'kart_durum', 'id' => (string)$yid, 'aktif' => '1']);
ok('kart_durum aktif=1 → aktif', js($r)['ok'] === true && (int)cari_kart_oku($db, $yid)['aktif'] === 1);
$r = post(['islem' => 'kart_sil', 'id' => (string)$K['Örnek Tarım Ltd.']]);
$j = js($r);
ok('kart_sil hareketli → 422 kod=hareketli', $r['kod'] === 422 && ($j['kod'] ?? '') === 'hareketli' && cari_kart_oku($db, $K['Örnek Tarım Ltd.']) !== null);
$r = post(['islem' => 'kart_sil', 'id' => (string)$yid]);
ok('kart_sil hareketsiz → silindi + audit', js($r)['ok'] === true && cari_kart_oku($db, $yid) === null && say('audit_log', "module = 'cari' AND action = 'delete'") >= 1);

// hareket
$cid = $K['Deneme Çavuş Grubu'];
$p = ['islem' => 'hareket_ekle', 'cari_id' => (string)$cid, 'tur' => 'tahsilat', 'tarih' => date('Y-m-d'), 'tutar' => '1.234,56', 'para_birimi' => 'TRY', 'odeme_sekli' => 'Nakit', 'istek_anahtari' => 'abcdef0123456789', 'geri' => 'cari_kartlari.php?id=' . $cid];
$h0 = say('cari_hareketler');
$r = post($p); $j = js($r);
ok('hareket_ekle tahsilat → {ok,id,belge_no TAH-…}', $r['kod'] === 200 && ($j['ok'] ?? false) === true && preg_match('/^TAH-\d{4}-\d{6}$/', (string)($j['belge_no'] ?? '')) === 1 && say('cari_hareketler') === $h0 + 1, $r['govde']);
$r = post($p); $j2 = js($r);
ok('aynı istek_anahtari tekrar → tekrar=true, yeni satır AÇILMAZ, aynı id', ($j2['ok'] ?? false) === true && ($j2['tekrar'] ?? false) === true && $j2['id'] === $j['id'] && say('cari_hareketler') === $h0 + 1);
$r = post(['tutar' => '1,2345', 'istek_anahtari' => 'yenianahtar0001'] + $p); $j = js($r);
ok('tutar 3 ondalık → 422 + alanlar{tutar}', $r['kod'] === 422 && isset($j['alanlar']['tutar']));
$r = post(['tur' => 'borc_dekont', 'aciklama' => '', 'istek_anahtari' => 'yenianahtar0002'] + $p); $j = js($r);
ok('dekont açıklamasız → 422 + alanlar{aciklama}', $r['kod'] === 422 && isset($j['alanlar']['aciklama']));
$r = post(['para_birimi' => 'TRY', 'kur' => '30', 'istek_anahtari' => 'yenianahtar0003'] + $p); $j = js($r);
ok('TRY\'de kur → 422 + alanlar{kur}', $r['kod'] === 422 && isset($j['alanlar']['kur']));
$pasifId = $K['Eski Müşteri Örnek Ltd.'];
$r = post(['cari_id' => (string)$pasifId, 'istek_anahtari' => 'yenianahtar0004'] + $p);
ok('pasif karta hareket → 422', $r['kod'] === 422 && (js($r)['ok'] ?? true) === false);
$r = post(['tur' => 'acilis', 'yon' => '', 'aciklama' => 'devir', 'istek_anahtari' => 'yenianahtar0005'] + $p); $j = js($r);
ok('açılışta yön seçilmedi → 422 + alanlar{yon}', $r['kod'] === 422 && isset($j['alanlar']['yon']));

// virman (tek modal yedek yolu ve doğrudan)
$ka = $K['Test Nakliyat San. Tic.']; $he = $K['Ege Soğuk Depo Hizmetleri'];
$v = ['islem' => 'hareket_ekle', 'tur' => 'virman', 'cari_id' => (string)$ka, 'hedef_id' => (string)$he, 'tarih' => date('Y-m-d'), 'tutar' => '500,00', 'para_birimi' => 'TRY', 'aciklama' => 'Mahsup test', 'istek_anahtari' => 'virman0000000001'];
$h0 = say('cari_hareketler');
$r = post($v); $j = js($r);
ok('hareket_ekle tur=virman+hedef_id → virman: 2 satır, ortak belge_no VRM-…', ($j['ok'] ?? false) === true && say('cari_hareketler') === $h0 + 2 && preg_match('/^VRM-/', (string)$j['belge_no']) === 1, $r['govde']);
$r = post(['hedef_id' => (string)$ka, 'istek_anahtari' => 'virman0000000002'] + $v); $j = js($r);
ok('virman kaynak=hedef → 422 + alanlar{hedef_id}', $r['kod'] === 422 && isset($j['alanlar']['hedef_id']));
$r = post(['islem' => 'virman_ekle', 'hedef_id' => '', 'istek_anahtari' => 'virman0000000003'] + $v);
ok('virman_ekle hedefsiz → 422', $r['kod'] === 422);
$r = post(['hedef_id' => '', 'istek_anahtari' => 'virman0000000004'] + $v);
ok('hareket_ekle tur=virman hedefsiz → 422 (virman türü tek satırlık yola düşmez)', $r['kod'] === 422 && say('cari_hareketler') === $h0 + 2);

// iptal
$hid = (int)js(post($p))['id'];
$r = post(['islem' => 'hareket_iptal', 'id' => (string)$hid, 'neden' => '']); $j = js($r);
ok('hareket_iptal nedensiz → 422 + alanlar{neden}', $r['kod'] === 422 && isset($j['alanlar']['neden']));
$r = post(['islem' => 'hareket_iptal', 'id' => (string)$hid, 'neden' => 'Test iptali']); $j = js($r);
ok('hareket_iptal → ok; satır silinmedi durum=iptal', ($j['ok'] ?? false) === true && (cari_hareket_oku($db, $hid)['durum'] ?? '') === 'iptal');
$r = post(['islem' => 'hareket_iptal', 'id' => (string)$hid, 'neden' => 'Tekrar']); $j = js($r);
ok('çift iptal → 422 kod=zaten_iptal', $r['kod'] === 422 && ($j['kod'] ?? '') === 'zaten_iptal');
$vid = (int)js(post(['istek_anahtari' => 'virman0000000009'] + $v))['id'];
$r = post(['islem' => 'hareket_iptal', 'id' => (string)$vid, 'neden' => 'Virman iptal']);
$vr = cari_hareket_oku($db, $vid);
ok('virman iptali bağlı satırı da iptal eder', js($r)['ok'] === true && ($vr['durum'] ?? '') === 'iptal' && (cari_hareket_oku($db, (int)$vr['bagli_id'])['durum'] ?? '') === 'iptal');

// tanım
$r = post(['islem' => 'tanim_kaydet', 'tur' => 'grup', 'ad' => 'Test Grubu', 'sira' => '50']); $j = js($r); $tid = (int)($j['id'] ?? 0);
ok('tanim_kaydet yeni grup → ok', ($j['ok'] ?? false) === true && $tid > 0);
$r = post(['islem' => 'tanim_kaydet', 'tur' => 'grup', 'ad' => 'test grubu', 'sira' => '51']); $j = js($r);
ok('tanim_kaydet aynı ad (harf farkı) → 422 + alanlar{ad}', $r['kod'] === 422 && isset($j['alanlar']['ad']));
$r = post(['islem' => 'tanim_durum', 'id' => (string)$tid]);
ok('tanim_durum aktif gelmedi → pasif', js($r)['ok'] === true && (int)$db->query("SELECT aktif FROM cari_tanimlar WHERE id = $tid")->fetchColumn() === 0);
$r = post(['islem' => 'tanim_sil', 'id' => (string)$grup_ambalaj = (int)$db->query("SELECT id FROM cari_tanimlar WHERE ad = 'Ambalaj'")->fetchColumn()]); $j = js($r);
ok('tanim_sil kullanılan grup → 422 kod=kullanimda', $r['kod'] === 422 && ($j['kod'] ?? '') === 'kullanimda');
$r = post(['islem' => 'tanim_sil', 'id' => (string)$tid]);
ok('tanim_sil kullanılmayan → silindi', js($r)['ok'] === true && (int)$db->query("SELECT COUNT(*) FROM cari_tanimlar WHERE id = $tid")->fetchColumn() === 0);
$r = post(['islem' => 'tanim_kaydet', 'tur' => 'grup', 'ad' => 'X', 'sira' => '1'], true, ['admin' => false]);
ok('tanım yazma admin değil → 403', $r['kod'] === 403);
// içe aktar
$r = post(['islem' => 'ice_aktar']); $j = js($r);
ok('ice_aktar → eklenen=2 atlanan=3; audit', ($j['ok'] ?? false) === true && $j['eklenen'] === 2 && $j['atlanan'] === 3 && say('audit_log', "action = 'cari_ice_aktar'") === 1, $r['govde']);
$r = post(['islem' => 'ice_aktar']); $j = js($r);
ok('ice_aktar tekrar → eklenen=0 (çift kart yok)', $j['eklenen'] === 0);
$r = post(['islem' => 'bilinmeyen']);
ok('bilinmeyen işlem → 422 JSON', $r['kod'] === 422 && (js($r)['ok'] ?? true) === false);

echo "\n=== 8b. Klasik POST (JS'siz): PRG + flash + geri beyaz listesi ===\n";
$ks = ['islem' => 'kart_durum', 'id' => (string)$K['Akdeniz Kasa Üretim'], 'aktif' => '1'];
$r = post($ks + ['geri' => 'cari_fisler.php?bas=2026-01-01&bit=2026-12-31'], false);
ok('PRG: 303 + Location=geri (yerel cari_*.php) + flash success', $r['kod'] === 303 && baslik($r, 'Location') === 'cari_fisler.php?bas=2026-01-01&bit=2026-12-31' && ($r['flash']['type'] ?? '') === 'success', json_encode([$r['kod'], $r['basliklar'], $r['flash']]));
foreach (['https://kotu.example/', '//kotu.example', 'http://x', 'cari_kartlari.php%0d%0aX:y', "cari_x.php\r\nSet-Cookie: a=b", '../index.php', 'index.php', 'cari_kartlari.php/../x', ''] as $kotu) {
    $r = post($ks + ['geri' => $kotu], false);
    ok('açık yönlendirme YOK: geri=' . json_encode($kotu) . ' → cari_kartlari.php', baslik($r, 'Location') === 'cari_kartlari.php', json_encode($r['basliklar']));
}
$r = post(['islem' => 'kart_kaydet', 'unvan' => '', 'geri' => 'cari_kartlari.php'], false);
ok('PRG hata: 303 + flash error (alan hatası metni)', $r['kod'] === 303 && ($r['flash']['type'] ?? '') === 'error' && str_contains((string)($r['flash']['msg'] ?? ''), 'Unvan'));
$r = post(['islem' => 'hareket_ekle'] + $p, false);
ok('PRG hareket_ekle başarı: flash mesajı belge no taşır', str_contains((string)($r['flash']['msg'] ?? ''), 'TAH-') || str_contains((string)($r['flash']['msg'] ?? ''), 'Kaydedildi'));

echo "\n=== 9. cari_ekstre.php ===\n";
$r = get('cari_ekstre.php', ['id' => $id1]);
$b = $r['govde'];
ok('yazdır: uyarı yok, print-cari gövde sınıfı, cari.css yüklü, otomatik print YOK', uyari_yok($r) && str_contains($b, 'class="print-page print-cari print-detail print-portrait"') && preg_match('#assets/cari\.css#', $b) === 1 && !str_contains($b, 'window.print'));
ok('yazdır: künye + 3 para birimi bölümü (TRY/USD/EUR) ayrı tablo, her birinde Dönem toplamı + Kapanış', substr_count($b, 'class="cari-yz-pb-bolum"') === 3 && substr_count($b, 'Dönem toplamı') === 3 && substr_count($b, 'Kapanış bakiyesi (') === 3 && str_contains($b, 'Kapanış bakiyesi (TRY)') && str_contains($b, '125.400,00 ₺ — Borçlu (cari bize borçlu)'));
ok('yazdır: iptal satırı YOK varsayılan; imza alanı YOK', !str_contains($b, 'Yanlış cariye girilmiş tahsilat') && !str_contains($b, 'İmza') && str_contains($b, 'cari-yz-not'));
$r = get('cari_ekstre.php', ['id' => $id1, 'iptal' => '1', 'pb' => 'TRY']);
ok('yazdır iptal=1&pb=TRY: tek bölüm; iptal satırı cari-yz-iptal + neden + bakiye —; kapanış değişmez', substr_count($r['govde'], 'class="cari-yz-pb-bolum"') === 1 && str_contains($r['govde'], 'cari-yz-iptal') && str_contains($r['govde'], 'İPTAL: Yanlış cari seçildi') && str_contains($r['govde'], '125.400,00 ₺ — Borçlu'));
ok('yazdır: HTML denge', html_hata($b) === [], json_encode(html_hata($b)));
$r = get('cari_ekstre.php', ['id' => 99999]);
ok('olmayan kart → 404', $r['kod'] === 404);
$r = get('cari_ekstre.php', ['kapsam' => 'liste', 'sinif' => 'fis']);
ok('liste kapsamı yazdır → 400 (yalnız xlsx/csv)', $r['kod'] === 400);

$a0 = say('audit_log', "module = 'cari' AND action = 'export'");
$r = get('cari_ekstre.php', ['id' => $id1, 'bicim' => 'csv']);
ok('csv: Content-Type text/csv, BOM, ; ayırıcı, tutar binlik ayraçsız, para birimi sütunlu', str_contains((string)baslik($r, 'Content-Type'), 'text/csv') && str_starts_with($r['govde'], "\xEF\xBB\xBF\"Para Birimi\";Tarih;\"Belge No\"") && str_contains($r['govde'], ';125400,00;B;') && str_contains($r['govde'], 'TRY;') && str_contains($r['govde'], 'USD;') && str_contains($r['govde'], 'EUR;'), substr($r['govde'], 0, 300));
ok('csv: export audit (+1, module=cari, action=export)', say('audit_log', "module = 'cari' AND action = 'export'") === $a0 + 1);
$r = get('cari_ekstre.php', ['id' => $id1, 'bicim' => 'csv'], ['perms' => []]);
ok('csv: reports.export yoksa forbidden', str_contains((string)$r['hata'], 'forbidden') && !str_contains($r['govde'], 'Para Birimi;'));
$r = get('cari_ekstre.php', ['id' => $id1, 'bicim' => 'xlsx'], ['perms' => []]);
ok('xlsx: reports.export yoksa forbidden', str_contains((string)$r['hata'], 'forbidden'));
$tmp = sys_get_temp_dir() . '/cari_ekstre_' . getmypid() . '.xlsx';
$r = get('cari_ekstre.php', ['id' => $id1, 'bicim' => 'xlsx'], ['govde_dosya' => $tmp]);
ok('xlsx: dosya üretildi (PK zip), uyarı yok', is_file($tmp) && str_starts_with((string)file_get_contents($tmp, false, null, 0, 4), "PK\x03\x04") && !preg_match('/(Warning|Fatal)/', $r['govde']) && $r['hata'] === null, ($r['hata'] ?? '') . $r['govde']);
if (is_file($tmp)) {
    $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
    $adlar = $ss->getSheetNames();
    ok('xlsx: para birimi başına AYRI sayfa (Ekstre TRY/USD/EUR)', $adlar === ['Ekstre TRY', 'Ekstre USD', 'Ekstre EUR'], json_encode($adlar));
    $sh = $ss->getSheetByName('Ekstre TRY');
    $sayisal = 0; $formul = 0; $bulundu = false;
    foreach ($sh->getRowIterator() as $row) foreach ($row->getCellIterator() as $c) {
        $v = $c->getValue();
        if (is_string($v) && str_starts_with($v, '=')) $formul++;
        if ($c->getDataType() === 'n' && is_numeric($v)) { $sayisal++; if (abs((float)$v - 125400.0) < 0.001) $bulundu = true; }
    }
    ok('xlsx: tutar hücreleri SAYI (kapanış 125400 sayı olarak), formül yok (iptal toplama girmez)', $sayisal > 10 && $bulundu && $formul === 0, "sayisal=$sayisal formul=$formul");
    $tum = [];
    foreach ($ss->getSheetByName('Ekstre USD')->getRowIterator() as $row) foreach ($row->getCellIterator() as $c) if (is_numeric($c->getValue())) $tum[] = round((float)$c->getValue(), 2);
    ok('xlsx: USD sayfasında TRY tutarı yok, USD kapanış 48250 (ham sayı)', in_array(48250.0, $tum, true) && !in_array(125400.0, $tum, true) && !in_array(40000.0, $tum, true));
    $ss->disconnectWorksheets(); @unlink($tmp);
}
$r = get('cari_ekstre.php', ['kapsam' => 'liste', 'sinif' => 'dekont', 'bicim' => 'csv']);
$satirSay = substr_count($r['govde'], "\n") - 1;
ok('liste csv (dekont): başlık + satırlar, virman TEK satır, toplam satırı YOK', str_starts_with($r['govde'], "\xEF\xBB\xBFTarih;\"Belge No\";Tür;Cari;\"Karşı Cari (virman)\"") && substr_count($r['govde'], 'VRM-') >= 1 && !str_contains($r['govde'], 'Toplam') && $satirSay >= 5, "satir=$satirSay");
$r = get('cari_ekstre.php', ['kapsam' => 'liste', 'sinif' => 'fis', 'bicim' => 'xlsx'], ['govde_dosya' => $tmp]);
if (is_file($tmp)) {
    $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
    ok('liste xlsx (fiş): sayfalar [Cari Fişler, Para Birimi Özeti]; formül yok', $ss->getSheetNames() === ['Cari Fişler', 'Para Birimi Özeti'] && !str_contains(json_encode($ss->getSheet(0)->toArray()), '"=SUB'));
    $ss->disconnectWorksheets(); @unlink($tmp);
} else ok('liste xlsx üretildi', false, $r['govde'] . ($r['hata'] ?? ''));

echo "\n=== 10. Ham çıktı denetimi (render betiği) ===\n";
foreach (['kartlar id=1', 'fisler', 'dekontlar', 'tanimlar', 'ekstre id=1'] as $a) {
    $o = (string)shell_exec('php ' . escapeshellarg(__DIR__ . '/cari_ui_render.php') . ' ' . $a . ' 2>&1');
    ok("cari_ui_render.php $a: HTML üretir, uyarı yok, assets file:// çözülür", str_contains($o, '<!doctype html>') && !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $o) && !preg_match('#(href|src)="assets/#', $o) && str_contains($o, 'file://' . $ROOT . '/assets/'));
}

siparis_test_bitir();
