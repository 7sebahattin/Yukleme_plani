<?php
// =========================================================
// scripts/stok_ui_smoke.php — Stok (muhasebe) sayfaları/uçları: GERÇEK HTML/JSON üzerinde PHP düzeyi testler (Parça A + B fiş sayfaları)
//   php scripts/stok_ui_smoke.php        → çıkış kodu 0 = hepsi geçti
// Her istek ALT SÜREÇTE (scripts/_stok_istek.php) gerçek exit/header ile çalışır; DB geçici dosya SQLite + uydurma veri. Canlı DB'ye dokunmaz.
// Kapsam: şema yok · yönetici değil · boş · dolu (kritik/pasif/iptal/2 depo) · kısmi yanıt · XSS · HTML denge · csrf · stok_kaydet JSON/PRG · tanımlar.
// =========================================================
require_once __DIR__ . '/_stok_test_lib.php';

$db = db();
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function get(string $dosya, array $get = [], array $ek = []): array { return stok_t_istek(['dosya' => $dosya, 'get' => $get] + $ek); }
function post(array $p, bool $json = true, array $ek = []): array
{
    return stok_t_istek(['dosya' => 'stok_kaydet.php', 'post' => $p + ['csrf' => 'test-csrf-belirteci'], 'server' => $json ? ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ACCEPT' => 'application/json'] : []] + $ek);
}
function js(array $r): array { $j = json_decode(trim($r['govde']), true); return is_array($j) ? $j : ['_cozulemedi' => $r['govde']]; }
function uyari_yok(array $r): bool { return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error):/', $r['govde']) && $r['hata'] === null; }
function baslik(array $r, string $ad): ?string { foreach ($r['basliklar'] as $b) if (stripos($b, $ad . ':') === 0) return trim(substr($b, strlen($ad) + 1)); return null; }
/** Stok bölgesi: ilk <div class="stok ...> ile stok.js betiği arası (sayfa kabuğu hariç). */
function bolge(string $h): string
{
    $a = strpos($h, '<div class="stok');
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
            if (preg_match('/type="(radio|checkbox)"/', $a) || str_ends_with($n[1], '[]')) continue;   // dizi alanları (fiş satırları) bilerek yinelenir
            $say[$n[1]] = ($say[$n[1]] ?? 0) + 1;
        }
        foreach ($say as $ad => $c) if ($c > 1) $hata[] = "form#$i: '$ad' $c kez";
    }
    return $hata;
}


// ═══ 1. Şema yok ═══
echo "=== 1. Şema yok (tablolar kurulmamış) ===\n";
foreach (['stok_kartlari.php', 'stok_tanimlari.php', 'stok_giris_fisleri.php', 'stok_cikis_fisleri.php', 'transfer_fisleri.php', 'stok_donusum_fisleri.php'] as $f) {
    $r = get($f);
    ok("$f: şema uyarı kartı + migrate.php#stok bağlantısı, uyarı yok", uyari_yok($r) && str_contains($r['govde'], 'Stok tabloları kurulmamış') && str_contains($r['govde'], 'migrate.php#stok'), (string)$r['hata']);
}
$r = post(['islem' => 'kart_kaydet', 'ad' => 'Deneme']);
ok('stok_kaydet (şema yok): 503 JSON', $r['kod'] === 503 && js($r)['ok'] === false && str_contains((string)js($r)['hata'], 'kurulmamış'));

stok_t_sema_kur($db);

// ═══ 2. Kapı / yöntem / csrf ═══
echo "\n=== 2. Kapı / yöntem / csrf ===\n";
$r = get('stok_kartlari.php', [], ['admin' => false]);
ok('yönetici değil: stok_kartlari forbidden', str_contains((string)$r['hata'], 'forbidden'));
$r = get('stok_tanimlari.php', [], ['admin' => false]);
ok('yönetici değil: stok_tanimlari forbidden', str_contains((string)$r['hata'], 'forbidden'));
$r = post(['islem' => 'kart_kaydet', 'ad' => 'Yetkisiz Kart'], true, ['admin' => false]);
ok('yönetici değil: stok_kaydet 403 JSON, kayıt yok', $r['kod'] === 403 && js($r)['ok'] === false && say('stok_kartlar') === 0, $r['govde']);
$r = stok_t_istek(['dosya' => 'stok_kaydet.php', 'server' => ['REQUEST_METHOD' => 'GET']]);
ok('GET stok_kaydet → 405 + Allow: POST', $r['kod'] === 405 && baslik($r, 'Allow') === 'POST');
$r = stok_t_istek(['dosya' => 'stok_kaydet.php', 'post' => ['islem' => 'kart_kaydet', 'ad' => 'Csrfsiz', 'csrf' => 'yanlis'], 'server' => ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ACCEPT' => 'application/json']]);
ok('yanlış csrf → 403, kayıt yok', $r['kod'] === 403 && say('stok_kartlar') === 0);

// ═══ 3. Boş ═══
echo "\n=== 3. Boş durum ===\n";
$r = get('stok_kartlari.php');
ok('boş: HTTP 200, uyarı yok, başlık Stok Kartları', uyari_yok($r) && $r['kod'] === 200 && str_contains($r['govde'], '<title>') && str_contains($r['govde'], 'Stok Kartları'), (string)$r['hata']);
ok('boş: "Henüz stok kartı yok" + Yeni kart + içe aktar bağlantısı', str_contains($r['govde'], 'Henüz stok kartı yok') && str_contains($r['govde'], 'stok_tanimlari.php?sekme=ice_aktar'));
ok('boş: stok.css + stok.js yüklenir; sekme şeridi 7 bağlantı (Kantar dahil)', str_contains($r['govde'], 'assets/stok.css?v=') && str_contains($r['govde'], 'assets/stok.js?v=') && preg_match_all('/class="st-sekme[" ]/', bolge($r['govde'])) === 7);
ok('boş: kart modalı kod önerisi STK-0001', str_contains($r['govde'], 'data-oneri="STK-0001"'));

$O = stok_t_ornek_veri($db);
$K = $O['kart'];

// ═══ 4. Dolu liste + detay ═══
echo "\n=== 4. Dolu: liste + detay ===\n";
$r = get('stok_kartlari.php', ['id' => (string)$K['Domates (Salkım)']]);
$h = $r['govde']; $b = bolge($h);
ok('detay: HTTP 200, uyarı yok', uyari_yok($r) && $r['kod'] === 200, (string)$r['hata'] . substr($h, 0, 300));
ok('HTML dengeli, yinelenen id yok', html_hata($b) === [], json_encode(html_hata($b)));
ok('formlarda csrf, yinelenen alan adı yok', form_hata($b) === [], json_encode(form_hata($b)));
ok('liste: 8 kart satırı; pasif (Limon) varsayılan Aktif çipinde gizli', substr_count($b, 'class="st-satir') - substr_count($b, 'class="st-satir-') === 8
    && preg_match('/<li hidden><a class="st-satir st-satir--pasif"[^>]*>.*?Limon/s', $b) === 1);
ok('çip sayaçları: Aktif 7 · Pasif 1 · Tümü 8 · Kritik altı 2', (bool)preg_match('/data-deger="aktif" aria-pressed="true">Aktif <span class="st-cip-say">7</', $b)
    && str_contains($b, 'Pasif <span class="st-cip-say">1<') && str_contains($b, 'Tümü <span class="st-cip-say">8<') && str_contains($b, 'Kritik altı <span class="st-cip-say">2<'));
ok('kritik rozet: Patlıcan (240 ≤ 500) ve Euro Palet (86 ≤ 100)', substr_count($b, 'data-kritik="1"') === 2 && (bool)preg_match('/Patlıcan \(Kemer\).*?240 kg<\/span><span class="st-rozet st-rozet--kritik">Kritik/s', $b));
ok('seçili kart aria-current + st-ana--detay', str_contains($b, 'data-id="' . $K['Domates (Salkım)'] . '" data-ara') && str_contains($b, 'aria-current="true"') && str_contains($b, 'st-ana st-ana--detay'));
ok('detay başlık/meta: ad, kod STK-0001, Ürün, Taze Ürün, 1 kasa = 8 kg, KDV %1, barkod, kritik 1.000 kg', str_contains($b, '<h2 class="st-unvan">Domates (Salkım)</h2>') && str_contains($b, 'STK-0001')
    && str_contains($b, '1 kasa = 8 kg') && str_contains($b, 'KDV %1') && str_contains($b, 'Barkod: 8690000000012') && str_contains($b, 'Kritik seviye: 1.000 kg'));
ok('bakiye kutuları: Toplam 14.300 kg · Merkez Depo 7.050 kg · Şube Depo 7.250 kg', str_contains($b, 'data-milli="14300000">14.300 kg') && (bool)preg_match('/Merkez Depo<\/div><div class="st-bk-deger">7\.050 kg/', $b) && (bool)preg_match('/Şube Depo<\/div><div class="st-bk-deger">7\.250 kg/', $b));
$M = stok_maliyet_durumu($db, $K['Domates (Salkım)']);
ok('ort. maliyet + değer: DB ile aynı (' . cari_tutar_bicim((int)round($M['ort']), 'TRY') . '/kg)', str_contains($b, h(cari_tutar_bicim((int)round($M['ort']), 'TRY')) . '/kg') && str_contains($b, h(cari_tutar_bicim((int)round(14300 * $M['ort']), 'TRY'))));
ok('bağımsız defter notu görünür', str_contains($b, 'Bu defter Ürün Stoğu (stok.php) ve Malzeme Stok ekranlarından bağımsızdır.'));
$tbl = substr($b, (int)strpos($b, '<table class="data-table st-tablo">'), 20000);
ok('hareket tablosu: 6 satır (iptal fişi 2 satır dahil), en yeni önce (CKS satış ilk)', substr_count(substr($tbl, 0, (int)strpos($tbl, '</table>')), '<tr data-id=') + substr_count(substr($tbl, 0, (int)strpos($tbl, '</table>')), '<tr class="st-iptal" data-id=') === 6
    && (bool)preg_match('/<tbody><tr data-id="\d+"><td class="st-tar">\d{2}\.11\.\d{4}<\/td><td class="st-fisno"><a href="stok_cikis_fisleri\.php\?id=\d+">CKS-/', $tbl));
ok('iptal satırları soluk (st-iptal) + İPTAL / İptal (ters) rozetleri', substr_count($tbl, '<tr class="st-iptal"') === 2 && str_contains($tbl, 'İPTAL') && str_contains($tbl, 'İptal (ters)'));
ok('fire rozeti turuncu (st-rozet--fire), giriş yeşil, işaretler ▲▼', str_contains($tbl, 'st-rozet st-rozet--fire') && str_contains($tbl, 'st-rozet st-rozet--giris') && str_contains($tbl, '&#9650;') && str_contains($tbl, '&#9660;'));
ok('fiş no bağlantıları tür sayfasına ?id= ile', (bool)preg_match('/href="stok_giris_fisleri\.php\?id=\d+">GRS-\d{4}-\d{6}</', $tbl));
ok('KG ekranda tam sayı (virgüllü kg yok)', !preg_match('/\d,\d+ kg/', $b) && !preg_match('/[+−-]\d[\d.]*,\d+<\/span>/', $tbl));
ok('mobil hareket kartları da basılır (aynı veri)', substr_count($b, '<article class="st-hk') === 6);
ok('işlemler: Düzenle (modal) · Pasifleştir (kart_durum aktif=0) · Sil DEVRE DIŞI (hareketli)', str_contains($b, 'data-st-modal="kart" data-duzenle="1"') && (bool)preg_match('/name="islem" value="kart_durum"><input type="hidden" name="id" value="\d+"><input type="hidden" name="aktif" value="0">/', $b)
    && (bool)preg_match('/<button type="button" class="btn st-tehlike" disabled title="Hareketi olan kart silinemez/', $b));
ok('#stKartVeri JSON: kod/çevrim/kritik + hareketli=true', (bool)preg_match('#<script type="application/json" id="stKartVeri">(.*?)</script>#', $b, $kv) && ($j = json_decode($kv[1], true)) && $j['kod'] === 'STK-0001' && $j['cevrim_birim'] === 'kasa' && $j['cevrim_katsayi'] === '8' && $j['kritik_seviye'] === '1.000' && $j['hareketli'] === true);
ok('kart modalı: .pm-overlay > .pm-dialog.st-dialog > form; csrf; kritik inputmode numeric', (bool)preg_match('/<div class="pm-overlay" id="stModalKart" hidden>\s*<div class="pm-dialog st-dialog[^"]*"[^>]*>\s*<div class="pm-header">.*?<\/div>\s*<form method="post" action="stok_kaydet\.php" data-st-ajax/s', $h)
    && str_contains($h, 'name="kritik_seviye" inputmode="numeric"'));

$r = get('stok_kartlari.php', ['id' => (string)$K['Streç Film']]);
ok('hareketsiz kart: boş hareket durumu + Sil formu (kart_sil, onaylı)', uyari_yok($r) && str_contains($r['govde'], 'Henüz stok hareketi yok') && str_contains($r['govde'], 'value="kart_sil"') && str_contains($r['govde'], 'data-onay="Bu stok kartı kalıcı olarak silinecek'));
$r = get('stok_kartlari.php', ['id' => (string)$K['Limon (Yerli)']]);
ok('pasif kart: Pasif rozeti + uyarı + Aktifleştir (aktif=1)', str_contains($r['govde'], 'st-rozet st-rozet--pasif">Pasif') && str_contains($r['govde'], 'Bu kart pasif') && str_contains($r['govde'], '>Aktifleştir</button>') && str_contains($r['govde'], 'name="aktif" value="1"'));
$r = get('stok_kartlari.php', ['id' => (string)$K['Sıvı Gübre']]);
ok('lt kart: kesirli miktar 12,5 lt', str_contains($r['govde'], '12,5 lt'));
$r = get('stok_kartlari.php', ['id' => '9999']);
ok('olmayan id: 200 + "bulunamadı" boş detay (liste görünür)', uyari_yok($r) && str_contains($r['govde'], 'Stok kartı bulunamadı') && !str_contains($r['govde'], 'st-ana--detay'));
$r = get('stok_kartlari.php', ['durum' => 'kritik']);
ok('?durum=kritik: yalnız 2 kart görünür (diğerleri hidden), çip basılı', substr_count($r['govde'], '<li><a class="st-satir') === 2 && str_contains($r['govde'], 'data-deger="kritik" aria-pressed="true"'));
$r = get('stok_kartlari.php', ['durum' => 'tumu', 'ara' => 'palet']);
ok('?ara=palet&durum=tumu: 1 görünür satır (Euro Palet), sayaç "1 kart"', substr_count($r['govde'], '<li><a class="st-satir') === 1 && str_contains($r['govde'], '<span id="stSayi">1 kart</span>'));
$r = get('stok_kartlari.php', ['durum' => 'tumu', 'ara' => 'yokyok']);
ok('eşleşme yok: "Eşleşen kart yok" görünür', (bool)preg_match('/<p class="st-liste-bos" id="stSonucYok">Eşleşen kart yok/', $r['govde']));
$r = get('stok_kartlari.php', ['yeni' => '1']);
ok('?yeni=1 → data-yeni="kart"', str_contains($r['govde'], 'data-yeni="kart"'));

echo "\n=== 5. Kısmi yanıt (parca=detay) ===\n";
$r = get('stok_kartlari.php', ['id' => (string)$K['Domates (Salkım)'], 'parca' => 'detay'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('parca=detay: yalnız <section id="stDetay"> (header/footer YOK)', $r['kod'] === 200 && str_starts_with(trim($r['govde']), '<section class="st-detay" id="stDetay"') && !str_contains($r['govde'], '<html') && !str_contains($r['govde'], 'st-liste-kol'));
$r = get('stok_kartlari.php', ['id' => '9999', 'parca' => 'detay']);
ok('parca=detay olmayan kart → 404', $r['kod'] === 404);
$r = get('stok_kartlari.php', ['id' => (string)$K['Domates (Salkım)'], 'parca' => 'xyz']);
ok('parca bilinmeyen → 400', $r['kod'] === 400);
$DEPOLAR = null;
$r = stok_t_istek(['dosya' => 'stok_kartlari.php', 'get' => ['id' => (string)$K['Domates (Salkım)'], 'parca' => 'detay'], 'depolar' => ['Merkez Depo']]);
ok('aktif depo = Merkez Depo: toplam 7.050 kg, Şube kutusu yok', str_contains($r['govde'], 'data-milli="7050000">7.050 kg') && !str_contains($r['govde'], 'Şube Depo</div>'));

echo "\n=== 6. stok_kaydet: JSON ===\n";
$n0 = say('stok_kartlar');
$r = post(['islem' => 'kart_kaydet', 'ad' => '<script>alert(1)</script> Kart', 'tip' => 'urun', 'ana_birim' => 'kg', 'aktif' => '1', 'geri' => 'stok_kartlari.php']);
$j = js($r);
ok('kart_kaydet (yeni): 200 ok, id, yonlendir ?id=<yeni>', $r['kod'] === 200 && $j['ok'] === true && $j['id'] > 0 && $j['yonlendir'] === 'stok_kartlari.php?id=' . $j['id'] && say('stok_kartlar') === $n0 + 1, $r['govde']);
$xid = (int)$j['id'];
$r = get('stok_kartlari.php', ['id' => (string)$xid]);
ok('XSS: ad kaçışlı basılır (<script> yok)', !str_contains(bolge($r['govde']), '<script>alert(1)') && str_contains($r['govde'], '&lt;script&gt;alert(1)&lt;/script&gt; Kart'));
$r = post(['islem' => 'kart_kaydet', 'ad' => 'X', 'kod' => 'STK-0001']);
$j = js($r);
ok('kart_kaydet hatalı: 422 + alanlar (ad, kod)', $r['kod'] === 422 && $j['ok'] === false && isset($j['alanlar']['ad']) && isset($j['alanlar']['kod']), $r['govde']);
$r = post(['islem' => 'kart_kaydet', 'id' => (string)$xid, 'ad' => 'Düzenlenen Kart', 'kod' => 'STK-0009', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'geri' => 'stok_kartlari.php?id=' . $xid]);
ok('kart_kaydet (düzenle): onay kutusu gelmedi → pasif; tip/birim güncellendi', js($r)['ok'] === true && ($xk = stok_kart_oku($db, $xid)) && $xk['ad'] === 'Düzenlenen Kart' && (int)$xk['aktif'] === 0 && $xk['ana_birim'] === 'adet');
$r = post(['islem' => 'kart_durum', 'id' => (string)$xid, 'aktif' => '1', 'geri' => 'https://kotu.ornek/x']);
ok('kart_durum aktif=1; dış `geri` → stok_kartlari.php', js($r)['ok'] === true && (int)stok_kart_oku($db, $xid)['aktif'] === 1 && js($r)['yonlendir'] === 'stok_kartlari.php');
$r = post(['islem' => 'kart_sil', 'id' => (string)$K['Domates (Salkım)']]);
ok('kart_sil hareketli → 409 kod hareketli', $r['kod'] === 409 && js($r)['kod'] === 'hareketli');
$r = post(['islem' => 'kart_sil', 'id' => (string)$xid, 'geri' => 'stok_kartlari.php?id=' . $xid]);
ok('kart_sil hareketsiz → ok, yonlendir stok_kartlari.php', js($r)['ok'] === true && js($r)['yonlendir'] === 'stok_kartlari.php' && stok_kart_oku($db, $xid) === null);
$gn = stok_tanim_listesi($db, 'giris_neden');
$r = post(['islem' => 'tanim_sil', 'id' => (string)$gn[0]['id']]);
ok('tanim_sil sistem nedeni → 409 kullanimda', $r['kod'] === 409 && js($r)['kod'] === 'kullanimda');
$r = post(['islem' => 'tanim_kaydet', 'tur' => 'cikis_neden', 'ad' => 'Bağış', 'sira' => '70', 'geri' => 'stok_tanimlari.php?sekme=cikis_neden']);
ok('tanim_kaydet → ok; yonlendir sekme=cikis_neden', js($r)['ok'] === true && js($r)['yonlendir'] === 'stok_tanimlari.php?sekme=cikis_neden' && say('stok_tanimlar', "ad = 'Bağış'") === 1);
$bid = (int)js($r)['id'];
$r = post(['islem' => 'tanim_durum', 'id' => (string)$bid]);
ok('tanim_durum (aktif gelmedi) → pasif', js($r)['ok'] === true && (int)stok_tanim_bul($db, $bid)['aktif'] === 0);
$r = post(['islem' => 'tanim_kaydet', 'tur' => 'grup', 'ad' => 'taze ürün', 'sira' => '5']);
ok('tanim_kaydet TR-duyarsız çift ad → 422', $r['kod'] === 422 && isset(js($r)['alanlar']['ad']));
$r = post(['islem' => 'tanim_sil', 'id' => (string)$bid]);
ok('tanim_sil kullanılmayan → ok', js($r)['ok'] === true && stok_tanim_bul($db, $bid) === null);
$r = post(['islem' => 'ice_aktar', 'geri' => 'stok_tanimlari.php?sekme=ice_aktar']);
ok('ice_aktar → eklenen 3 (Kayısı, Köşebent, Şerit; Domates var, firma/pasif hariç)', js($r)['ok'] === true && js($r)['eklenen'] === 3, $r['govde']);
$r = post(['islem' => 'yok_boyle']);
ok('bilinmeyen işlem → 422', $r['kod'] === 422 && js($r)['hata'] === 'Bilinmeyen işlem.');
ok('audit: uçlardan gelen yazmalar audit\'te (kart_sil + tanim_sil = delete ≥ 2; ice_aktar özeti)', say('audit_log', "module = 'stok' AND action = 'delete'") >= 2
    && say('audit_log', "module = 'stok' AND action = 'create' AND new_values LIKE '%ice_aktar%'") === 1);

echo "\n=== 7. stok_kaydet: klasik form (PRG) ===\n";
$r = post(['islem' => 'kart_kaydet', 'ad' => 'Formdan Kart', 'aktif' => '1', 'geri' => 'stok_kartlari.php'], false);
ok('klasik form: 303 + Location stok_kartlari.php?id=<yeni> + flash success', $r['kod'] === 303 && preg_match('/^stok_kartlari\.php\?id=\d+$/', (string)baslik($r, 'Location')) === 1 && ($r['flash']['type'] ?? '') === 'success', json_encode([$r['kod'], $r['basliklar'], $r['flash']], JSON_UNESCAPED_UNICODE));
$r = post(['islem' => 'kart_kaydet', 'ad' => 'X', 'geri' => 'stok_kartlari.php'], false);
ok('klasik form hata: 303 + flash error', $r['kod'] === 303 && ($r['flash']['type'] ?? '') === 'error');
$r = post(['islem' => 'kart_durum', 'id' => '1', 'aktif' => '1', 'geri' => "stok_kartlari.php\r\nSet-Cookie: x=1"], false);
ok('başlık enjeksiyonu: Location yalnız güvenli varsayılan', baslik($r, 'Location') === 'stok_kartlari.php');

echo "\n=== 8. Tanımlar sayfası ===\n";
$r = get('stok_tanimlari.php');
$b = bolge($r['govde']);
ok('tanımlar: 200, uyarı yok, HTML dengeli, csrf', uyari_yok($r) && html_hata($b) === [] && form_hata($b) === [], json_encode([html_hata($b), form_hata($b), $r['hata']]));
ok('4 sekme (Gruplar · Giriş · Çıkış · İçe Aktar), varsayılan grup seçili; diğer paneller hidden', substr_count($b, 'class="st-tanim-nav-oge" role="tab"') === 4 && (bool)preg_match('/id="stTabGrup"[^>]*aria-selected="true"/', $b)
    && (bool)preg_match('/id="stPanelGiris" role="tabpanel" aria-labelledby="stTabGiris" hidden/', $b));
ok('sistem nedenleri kilit rozetli; silme formu yok, ad readonly', substr_count($b, 'st-rozet--kilit') === 10 && (bool)preg_match('/name="ad" value="Alış" maxlength="100" required readonly/', $b));
ok('kullanılan grup (Taze Ürün) için Sil yok; aktif anahtarı role=switch', !preg_match('/aria-label="Taze Ürün sil"/', $b) && str_contains($b, 'role="switch"'));
$r = get('stok_tanimlari.php', ['sekme' => 'ice_aktar']);
ok('?sekme=ice_aktar: önizleme tablosu, "Kartı var" rozetleri, 0 yeni → düğme disabled', str_contains($r['govde'], 'Tanımlardan içe aktar') && str_contains($r['govde'], 'Kartı var') && str_contains($r['govde'], 'data-ice-aktar disabled'));
$r = get('stok_tanimlari.php', ['sekme' => 'cikis_neden']);
ok('?sekme=cikis_neden: Çıkış sekmesi seçili, Fire listede', (bool)preg_match('/id="stTabCikis"[^>]*aria-selected="true"/', $r['govde']) && str_contains($r['govde'], 'Fire'));

// ═══ 9. Fiş sayfaları (Parça B) ═══
echo "\n=== 9. Fiş sayfaları (Parça B) ===\n";
$FB = stok_t_ornek_fisler($db, $O);
$sayfalar = ['giris' => 'stok_giris_fisleri.php', 'cikis' => 'stok_cikis_fisleri.php', 'transfer' => 'transfer_fisleri.php', 'donusum' => 'stok_donusum_fisleri.php'];
foreach ($sayfalar as $tur => $f) {
    $r = get($f); $b = bolge($r['govde']);
    ok("$f: 200, uyarı yok, HTML dengeli, csrf, liste + boş detay", uyari_yok($r) && $r['kod'] === 200 && html_hata($b) === [] && form_hata($b) === [] && str_contains($b, 'id="stListe"') && str_contains($b, 'Soldan bir fiş seçin'), json_encode([html_hata($b), form_hata($b), $r['hata']]));
    ok("$f: sekme şeridinde aktif sekme + data-sayfa=fis", str_contains($b, 'data-sayfa="fis" data-tur="' . $tur . '"') && (bool)preg_match('/href="' . preg_quote($f, '/') . '" aria-current="page"/', $b));
    $r = get($f, ['yeni' => '1']); $b = bolge($r['govde']);
    ok("$f ?yeni=1: form (#stFisForm, islem=fis_kaydet, tur, istek_anahtari, eksiye_izin=0), satır şablonu, #stFisVeri, HTML dengeli", uyari_yok($r) && html_hata($b) === [] && form_hata($b) === []
        && str_contains($b, 'id="stFisForm" data-st-fis data-tur="' . $tur . '"') && str_contains($b, 'name="islem" value="fis_kaydet"') && (bool)preg_match('/name="istek_anahtari" value="[0-9a-f]{24}"/', $b)
        && str_contains($b, 'name="eksiye_izin" value="0"') && str_contains($b, '<template id="stSatirSablon">') && str_contains($b, 'id="stFisVeri"') && str_contains($b, 'st-ana st-ana--detay st-ana--form'), json_encode([html_hata($b), form_hata($b), $r['hata']]));
    ok("$f ?yeni=1: miktar inputmode, mobil 3 adım başlıkları, alt eylem çubuğu Kaydet", str_contains($b, 'name="miktar[]"') && str_contains($b, 'inputmode="numeric"') && substr_count($b, 'class="st-adim"') >= 3 && str_contains($b, 'data-kaydet>Kaydet</button>'));
}
$r = get('stok_giris_fisleri.php', ['yeni' => '1']); $b = bolge($r['govde']);
ok('giriş formu: neden (sistem nedenleri), cari datalist (3 cari), PB + kur (TRY → kur kapalı), fiyat/KDV/tutar sütunları', str_contains($b, '>Alış</option>') && substr_count(substr($b, (int)strpos($b, 'id="stCariListe"')), '<option value=') >= 3
    && (bool)preg_match('/name="kur"[^>]*disabled/', $b) && str_contains($b, 'name="birim_fiyat[]"') && str_contains($b, 'name="kdv_orani[]"') && !str_contains($b, 'st-form--fiyatsiz'));
ok('giriş formu: Kantardan doldur + kantar modalı (.pm-overlay > .pm-dialog.st-dialog > form, GET, 2 aday Merkez; Şube kantarı da kısıtsızda görünür)', str_contains($b, 'data-st-modal="kantar"')
    && (bool)preg_match('/<div class="pm-overlay" id="stModalKantar" hidden><div class="pm-dialog st-dialog[^"]*"[^>]*>.*?<form method="get" action="stok_giris_fisleri\.php" data-st-kantar-form>/s', $b) && substr_count($b, 'name="kantar_id"') === 3);
ok('kantar adayı: net 6.120 kg (dara düşülmüş), öneri JSON (kart + cari eşleşti)', str_contains($b, '6.120 kg') && (bool)preg_match('/name="kantar_id" value="' . $O['kantar']['k1'] . '" data-oneri="([^"]+)"/', $b, $om) && ($oj = json_decode(html_entity_decode($om[1]), true)) && $oj['miktar_milli'] === 6120000 && $oj['kart_id'] === $K['Domates (Salkım)'] && $oj['cari_id'] === $O['cari']['Örnek Tarım Ltd.']);
$r = get('stok_giris_fisleri.php', ['yeni' => '1', 'kantar_id' => (string)$O['kantar']['k1']]); $b = bolge($r['govde']);
ok('?yeni=1&kantar_id= (JS kapalı yol): form kantardan dolu (kaynak_modul=kantar, kart Domates, miktar 6.120, bilgi şeridi)', str_contains($b, 'name="kaynak_modul" value="kantar"') && str_contains($b, 'name="kaynak_id" value="' . $O['kantar']['k1'] . '"')
    && str_contains($b, 'name="kart_id[]" value="' . $K['Domates (Salkım)'] . '"') && str_contains($b, 'name="miktar[]" value="6.120"') && str_contains($b, 'Kantar #1201'));
$r = get('stok_cikis_fisleri.php', ['yeni' => '1']); $b = bolge($r['govde']);
ok('çıkış formu: cari yokken fiyat sütunları gizli (st-form--fiyatsiz), Kantardan doldur YOK', str_contains($b, 'st-form--fiyatsiz') && !str_contains($b, 'data-st-modal="kantar"') && !str_contains($b, 'id="stModalKantar"'));
$r = get('transfer_fisleri.php', ['yeni' => '1']); $b = bolge($r['govde']);
ok('transfer formu: kaynak + hedef depo + takas düğmesi; neden/cari/fiyat YOK', str_contains($b, 'name="hedef_depo"') && str_contains($b, 'data-depo-takas') && !str_contains($b, 'name="neden_id"') && !str_contains($b, 'name="cari_ara"') && !str_contains($b, 'birim_fiyat[]'));
$r = get('stok_donusum_fisleri.php', ['yeni' => '1']); $b = bolge($r['govde']);
ok('dönüşüm formu: Girdiler (C) + Çıktılar (G) iki tablo, fire özeti', str_contains($b, 'data-satir-ekle="C"') && str_contains($b, 'data-satir-ekle="G"') && str_contains($b, 'name="yon[]" value="C"') && str_contains($b, 'name="yon[]" value="G"') && str_contains($b, 'data-fire'));

$r = get('transfer_fisleri.php', ['id' => (string)$FB['transfer']]); $b = bolge($r['govde']);
ok('transfer detayı: TRF no, Merkez → Şube, Giden rozeti (aktif Merkez), 1.000 kg, maliyet sütunu, İptal et + Kopyala', uyari_yok($r) && html_hata($b) === [] && (bool)preg_match('/<h2 class="st-unvan">TRF-\d{4}-\d{6}<\/h2>/', $b)
    && str_contains($b, '<b>Merkez Depo</b>') && str_contains($b, '<b>Şube Depo</b>') && str_contains($b, '>Giden</span>') && str_contains($b, '1.000 kg') && str_contains($b, 'Maliyet ₺') && str_contains($b, 'data-st-modal="iptal" data-id="' . $FB['transfer'] . '"') && str_contains($b, '?kopya=' . $FB['transfer']), json_encode(html_hata($b)));
$r = stok_t_istek(['dosya' => 'transfer_fisleri.php', 'get' => ['id' => (string)$FB['transfer']], 'aktif_depo' => 'Şube Depo', 'depolar' => ['Şube Depo']]);
ok('aktif depo Şube: aynı transfer "Gelen" ve görünür (hedef depo)', str_contains($r['govde'], '>Gelen</span>') && str_contains($r['govde'], 'class="st-unvan">TRF-'));
$r = stok_t_istek(['dosya' => 'stok_giris_fisleri.php', 'get' => ['id' => (string)$FB['cari_alis']], 'depolar' => ['Şube Depo'], 'aktif_depo' => 'Şube Depo']);
ok('başka depodaki fiş: detay yok, "aktif deponuzda görünmüyor"', str_contains($r['govde'], 'aktif deponuzda görünmüyor') && !str_contains($r['govde'], 'class="st-unvan">GRS-'));
$r = get('stok_donusum_fisleri.php', ['id' => (string)$FB['donusum']]); $b = bolge($r['govde']);
ok('dönüşüm detayı: Girdiler / Çıktılar, fire 50 (%10,0), çıktı birim maliyeti', str_contains($b, 'Girdiler') && str_contains($b, 'Çıktılar') && str_contains($b, 'Fire / fark: <b>50</b> (%10,0)') && str_contains($b, 'Çıktı birim maliyeti'));
$r = get('stok_giris_fisleri.php', ['id' => (string)$FB['cari_alis']]); $b = bolge($r['govde']);
ok('cari alış detayı: cari bağlantısı, evrak, fiyat/KDV/tutar, cari hareket belge no (ADK-)', str_contains($b, 'Cari: <a href="cari_kartlari.php?id=' . $O['cari']['Örnek Tarım Ltd.'] . '">') && str_contains($b, 'Evrak: IRS-1001') && str_contains($b, 'Birim fiyat') && str_contains($b, '1,80') && (bool)preg_match('/Cari hareketi: <a[^>]*>ADK-/', $b));
$r = get('stok_giris_fisleri.php', ['id' => (string)$FB['cari_alis'], 'parca' => 'detay'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('parca=detay: yalnız <section id="stDetay"> (header/footer YOK)', $r['kod'] === 200 && str_starts_with(trim($r['govde']), '<section class="st-detay st-fis-detay" id="stDetay"') && !str_contains($r['govde'], '<html'));
ok('parca=detay başka türün fişi → 404', get('stok_cikis_fisleri.php', ['id' => (string)$FB['cari_alis'], 'parca' => 'detay'])['kod'] === 404);
$r = get('stok_giris_fisleri.php', ['parca' => 'kantar_oneri', 'kantar_id' => (string)$O['kantar']['k1']]);
ok('parca=kantar_oneri: JSON öneri (6.120 kg)', ($j = js($r)) && $j['ok'] === true && $j['oneri']['miktar_milli'] === 6120000);
$r = get('stok_giris_fisleri.php', ['kopya' => (string)$FB['cari_alis']]); $b = bolge($r['govde']);
ok('?kopya=ID: form, satır dolu (200 adet, 1,80, %20), cari dolu, "kopyalandı" bilgisi, evrak boş', str_contains($b, 'id="stFisForm"') && str_contains($b, 'name="miktar[]" value="200"') && str_contains($b, 'name="birim_fiyat[]" value="1,80"')
    && str_contains($b, '<option value="20" selected>') && str_contains($b, 'name="cari_id" value="' . $O['cari']['Örnek Tarım Ltd.'] . '"') && str_contains($b, 'fişinden kopyalandı') && str_contains($b, 'name="evrak_no" maxlength="60" value=""'));
$r = get('stok_giris_fisleri.php', ['durum' => 'iptal']);
ok('liste süzgeci ?durum=iptal: yalnız iptal fişleri (st-satir--iptal), çip aria-current', substr_count($r['govde'], 'class="st-satir st-fis-satir st-satir--iptal"') === 1 && substr_count($r['govde'], 'class="st-satir st-fis-satir"') === 0 && (bool)preg_match('/class="st-cip" href="[^"]*durum=iptal[^"]*" aria-current="true"/', $r['govde']));
$r = get('stok_giris_fisleri.php', ['ara' => 'IRS-1001']);
ok('liste ara=evrak no: 1 fiş', substr_count($r['govde'], 'class="st-satir st-fis-satir') === 1);

echo "\n=== 10. stok_kaydet: fis_kaydet / fis_iptal ===\n";
$nd = []; foreach (stok_tanim_listesi($db, 'giris_neden') as $t) $nd[$t['ad']] = (int)$t['id']; foreach (stok_tanim_listesi($db, 'cikis_neden') as $t) $nd[$t['ad']] = (int)$t['id'];
$fp = fn(string $tur, array $sat, array $ek = []) => ['islem' => 'fis_kaydet'] + stok_t_fis_istek($tur, 'Merkez Depo', $sat, $ek);
$f0 = say('stok_fisler');
$r = post($fp('giris', [[$K['Biber (Dolmalık)'], '100', '20,00']], ['neden_id' => (string)$nd['Alış'], 'aciklama' => '<script>alert(2)</script>', 'istek_anahtari' => 'ui-grs-1', 'geri' => 'stok_giris_fisleri.php?yeni=1']));
$j = js($r); $GID = (int)($j['id'] ?? 0);
ok('fis_kaydet JSON: 200 ok, fis_no GRS-, yonlendir stok_giris_fisleri.php?id=<yeni>', $r['kod'] === 200 && $j['ok'] === true && str_starts_with((string)$j['fis_no'], 'GRS-') && $j['yonlendir'] === 'stok_giris_fisleri.php?id=' . $GID && say('stok_fisler') === $f0 + 1, $r['govde']);
$r = post($fp('giris', [[$K['Biber (Dolmalık)'], '100', '20,00']], ['neden_id' => (string)$nd['Alış'], 'istek_anahtari' => 'ui-grs-1']));
ok('çift gönderim (aynı istek_anahtari): tekrar=true, aynı id', js($r)['ok'] === true && js($r)['tekrar'] === true && (int)js($r)['id'] === $GID && say('stok_fisler') === $f0 + 1);
$r = get('stok_giris_fisleri.php', ['id' => (string)$GID]);
ok('XSS: açıklama kaçışlı', !str_contains(bolge($r['govde']), '<script>alert(2)') && str_contains($r['govde'], '&lt;script&gt;alert(2)'));
$r = post($fp('cikis', [[$K['Patlıcan (Kemer)'], '999']], ['neden_id' => (string)$nd['Satış/Sevk'], 'istek_anahtari' => 'ui-cks-eksi']));
$j = js($r);
ok('yetersiz stok → 409, kod yetersiz_stok, eksikler (Patlıcan, mevcut 240, eksik 759)', $r['kod'] === 409 && $j['kod'] === 'yetersiz_stok' && ($j['eksikler'][0]['mevcut_milli'] ?? 0) === 240000 && ($j['eksikler'][0]['eksik_milli'] ?? 0) === 759000 && say('stok_fisler') === $f0 + 1, $r['govde']);
$r = post($fp('cikis', [[$K['Patlıcan (Kemer)'], '999']], ['neden_id' => (string)$nd['Satış/Sevk'], 'istek_anahtari' => 'ui-cks-eksi', 'eksiye_izin' => '1']));
$EID = (int)(js($r)['id'] ?? 0);
ok('"Yine de kaydet" (eksiye_izin=1): ok + uyarı; listede "Eksi" rozeti', js($r)['ok'] === true && str_contains((string)js($r)['uyari'], 'Eksiye') && str_contains(get('stok_cikis_fisleri.php')['govde'], 'st-rozet--uyari'));
$r = post($fp('giris', [[$K['Biber (Dolmalık)'], '12,5']], ['neden_id' => (string)$nd['Alış']]));
ok('satır hatası → 422 + satir_hatalari[0].miktar', $r['kod'] === 422 && isset(js($r)['satir_hatalari']['0']['miktar']), $r['govde']);
$r = post($fp('giris', [[$K['Domates (Salkım)'], '6.120']], ['neden_id' => (string)$nd['Alış'], 'kaynak_modul' => 'kantar', 'kaynak_id' => (string)$O['kantar']['k1']]));
$r2 = post($fp('giris', [[$K['Domates (Salkım)'], '6.120']], ['neden_id' => (string)$nd['Alış'], 'kaynak_modul' => 'kantar', 'kaynak_id' => (string)$O['kantar']['k1']]));
ok('kantardan giriş ok; ikinci kez → 409 kantar_aktarilmis', js($r)['ok'] === true && $r2['kod'] === 409 && js($r2)['kod'] === 'kantar_aktarilmis', $r2['govde']);
$r = get('stok_giris_fisleri.php', ['yeni' => '1']);
ok('aktarılmış kantar adayı modalda devre dışı + "Aktarıldı"', (bool)preg_match('/name="kantar_id" value="' . $O['kantar']['k1'] . '" disabled/', $r['govde']) && str_contains($r['govde'], 'Aktarıldı · GRS-'));
$r = post(['islem' => 'fis_iptal', 'id' => (string)$EID, 'neden' => 'ab']);
ok('fis_iptal kısa neden → 422 alanlar.neden', $r['kod'] === 422 && isset(js($r)['alanlar']['neden']));
$r = post(['islem' => 'fis_iptal', 'id' => (string)$EID, 'neden' => 'Yanlış çıkış', 'geri' => 'stok_cikis_fisleri.php?id=' . $EID]);
ok('fis_iptal → ok, yonlendir geri, fiş iptal', js($r)['ok'] === true && js($r)['yonlendir'] === 'stok_cikis_fisleri.php?id=' . $EID && stok_fis_ham($db, $EID)['durum'] === 'iptal');
$r = post(['islem' => 'fis_iptal', 'id' => (string)$EID, 'neden' => 'Yine iptal']);
ok('ikinci iptal → 422 zaten_iptal', $r['kod'] === 422 && js($r)['kod'] === 'zaten_iptal');
$r = get('stok_cikis_fisleri.php', ['id' => (string)$EID]); $b = bolge($r['govde']);
ok('iptal detayı: kırmızı şerit (neden), İPTAL rozeti, İptal et devre dışı, Kopyala açık', str_contains($b, 'class="st-iptal-serit"') && str_contains($b, 'Yanlış çıkış') && str_contains($b, 'st-rozet st-rozet--iptal">İPTAL') && (bool)preg_match('/<button type="button" class="btn st-tehlike" disabled[^>]*>İptal et/', $b) && str_contains($b, '?kopya=' . $EID));
$r = post($fp('giris', [[$K['Biber (Dolmalık)'], '5', '1']], ['neden_id' => (string)$nd['Alış'], 'geri' => 'stok_giris_fisleri.php?yeni=1']), false);
ok('klasik form: 303 → stok_giris_fisleri.php?id=<yeni> + flash success (fiş no)', $r['kod'] === 303 && preg_match('/^stok_giris_fisleri\.php\?id=\d+$/', (string)baslik($r, 'Location')) === 1 && ($r['flash']['type'] ?? '') === 'success' && str_contains((string)($r['flash']['message'] ?? $r['flash']['msg'] ?? json_encode($r['flash'])), 'GRS-'), json_encode([$r['kod'], $r['basliklar'], $r['flash']], JSON_UNESCAPED_UNICODE));
$r = post($fp('cikis', [[$K['Patlıcan (Kemer)'], '5000']], ['neden_id' => (string)$nd['Satış/Sevk'], 'geri' => 'stok_cikis_fisleri.php?yeni=1']), false);
ok('klasik form yetersiz: 303 geri + flash error eksik listesi', $r['kod'] === 303 && baslik($r, 'Location') === 'stok_cikis_fisleri.php?yeni=1' && ($r['flash']['type'] ?? '') === 'error' && str_contains(json_encode($r['flash'], JSON_UNESCAPED_UNICODE), 'mevcut'));
$r = post($fp('giris', [[$K['Biber (Dolmalık)'], '5', '1']], ['neden_id' => (string)$nd['Alış']]), true, ['admin' => false]);
ok('yönetici değil: fis_kaydet 403', $r['kod'] === 403);
$r = post(['islem' => 'fis_iptal', 'id' => (string)$GID, 'neden' => 'Yetkisiz'], true, ['admin' => false]);
ok('yönetici değil: fis_iptal 403, fiş geçerli kalır', $r['kod'] === 403 && stok_fis_ham($db, $GID)['durum'] === 'gecerli');
foreach ($sayfalar as $f) ok("yönetici değil: $f forbidden", str_contains((string)get($f, [], ['admin' => false])['hata'], 'forbidden'));
$kr = stok_fis_kaydet($db, stok_t_fis_istek('cikis', 'Merkez Depo', [[$K['Biber (Dolmalık)'], '3']], ['neden_id' => (string)$nd['Satış/Sevk']]), 1, ['modul' => 'ornek_modul', 'id' => 41]);
$r = get('stok_cikis_fisleri.php', ['id' => (string)($kr['id'] ?? 0)]); $b = bolge($r['govde']);
ok('kaynaklı fiş detayı: "Kaynak belge #41" rozeti, İptal et düğmesi YOK ("Kaynak belgeden iptal edilir."), Kopyala var', $kr['ok'] && uyari_yok($r) && html_hata($b) === [] && str_contains($b, 'Kaynak belge #41')
    && str_contains($b, 'Kaynak belgeden iptal edilir.') && !str_contains($b, 'data-st-modal="iptal" data-id="' . (int)$kr['id'] . '"') && !preg_match('/>İptal et<\/button>/', $b) && str_contains($b, '?kopya=' . (int)$kr['id']), json_encode([$kr, html_hata($b)], JSON_UNESCAPED_UNICODE));
$r = post(['islem' => 'fis_iptal', 'id' => (string)($kr['id'] ?? 0), 'neden' => 'Stok ekranından']);
ok('kaynaklı fişi stok_kaydet ile iptal → 409 kaynakli, fiş geçerli kalır', $r['kod'] === 409 && js($r)['kod'] === 'kaynakli' && stok_fis_ham($db, (int)$kr['id'])['durum'] === 'gecerli', $r['govde']);
$r = get('stok_kartlari.php', ['id' => (string)$K['Domates (Salkım)']]);
ok('kart detayı: transfer/dönüşüm hareket fiş no bağlantıları kendi sayfalarına', (bool)preg_match('/href="transfer_fisleri\.php\?id=' . $FB['transfer'] . '">TRF-/', $r['govde']) && (bool)preg_match('/href="stok_donusum_fisleri\.php\?id=' . $FB['donusum'] . '">DNS-/', $r['govde']));

siparis_test_bitir();
