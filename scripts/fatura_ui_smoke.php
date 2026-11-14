<?php
// =========================================================
// scripts/fatura_ui_smoke.php — Fatura sayfaları/uçları: GERÇEK HTML/JSON üzerinde PHP düzeyi testler (Dalga B)
//   php scripts/fatura_ui_smoke.php        → çıkış kodu 0 = hepsi geçti
// Her istek ALT SÜREÇTE (scripts/_fatura_istek.php) gerçek exit/header ile çalışır; DB geçici dosya SQLite + uydurma veri
// (scripts/fatura_ui_e2e_setup.php ornek: sipariş → irsaliye, satış, alış, ihracat USD). Canlı DB'ye dokunmaz.
// Kapsam: şema yok (fatura / cari / stok varyantları) · yönetici değil · boş · dolu liste · detay · her türde form alanları · kopya ·
//         kaynak doldurma · ?parca= JSON · tanımlar · XSS · HTML denge + yinelenen id · fatura_cikti yazdır + xlsx/csv yetkisi + audit ·
//         kaynaklı stok fişinde iptal düğmesi yok.
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
$DB = sys_get_temp_dir() . '/fatura_ui_smoke_' . getmypid() . '.sqlite';
exec('php ' . escapeshellarg(__DIR__ . '/fatura_ui_e2e_setup.php') . ' ' . escapeshellarg($DB) . ' ornek', $cikti, $kod);
if ($kod !== 0) { fwrite(STDERR, "kurulum başarısız:\n" . implode("\n", $cikti) . "\n"); exit(1); }
$ORNEK = json_decode((string)end($cikti), true) ?: [];
putenv('FATURA_E2E_DB=' . $DB);
require_once __DIR__ . '/_fatura_test_lib.php';
require_once $ROOT . '/config/siparis_invoice.php';   // siparis_inv_ihracatci (yazdırma başlığı kaynağı)
register_shutdown_function(static function () use ($DB): void { @unlink($DB); });
$db = db();
function get(string $dosya, array $get = [], array $ek = []): array { return fatura_t_istek_cagri(['dosya' => $dosya, 'get' => $get] + $ek); }
function getj(string $dosya, array $get): array { $r = get($dosya, $get, ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ACCEPT' => 'application/json']]); $j = json_decode(trim($r['govde']), true); return [$r, is_array($j) ? $j : ['_cozulemedi' => $r['govde']]]; }
function uyari_yok(array $r): bool { return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b/', $r['govde']) && $r['hata'] === null; }
function bolge(string $h): string { $h = preg_replace('#<template\b[^>]*>.*?</template>#s', '', $h) ?? $h; $a = strpos($h, '<div class="fatura'); if ($a === false) return $h; $b = strpos($h, '<script src=', $a); return $b === false ? substr($h, $a) : substr($h, $a, $b - $a); }
function html_hata(string $h): array
{
    $h = preg_replace('#<script\b[^>]*>.*?</script>#si', '<script></script>', $h) ?? $h;
    $h = preg_replace('#<template\b[^>]*>.*?</template>#si', '', $h) ?? $h;
    $void = ['br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'wbr', 'col'];
    $st = []; $hata = [];
    preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(/?)>#s', $h, $m, PREG_SET_ORDER);
    foreach ($m as $t) {
        $ad = strtolower($t[2]);
        if (in_array($ad, $void, true) || $t[4] === '/') continue;
        if ($t[1] === '/') { $son = array_pop($st); if ($son !== $ad) { $hata[] = "kapanış </$ad> beklenen <" . ($son ?? 'yok') . '>'; if (count($hata) > 3) break; } }
        else $st[] = $ad;
    }
    if ($st) $hata[] = 'kapanmamış: ' . implode(',', array_slice($st, -4));
    preg_match_all('/\sid="([^"]+)"/', $h, $ids);
    foreach (array_count_values($ids[1]) as $id => $n) if ($n > 1) $hata[] = "yinelenen id=$id";
    return $hata;
}
/** Gizli olmayan (hidden özniteliksiz) data-ft-goster sarmalayıcısı içinde $ad girdisi var mı (sunucu ilk görünürlük)? */
function alan_gorunur(string $h, string $ad): bool
{
    if (!preg_match_all('#<(label|div|span)\b([^>]*data-ft-goster="[^"]*"[^>]*)>(.*?)</\1>#s', $h, $m, PREG_SET_ORDER)) return str_contains($h, 'name="' . $ad . '"');
    foreach ($m as $x) if (str_contains($x[3], 'name="' . $ad . '"')) return !preg_match('/\shidden(\s|>|$)/', $x[2] . ' ');
    return str_contains($h, 'name="' . $ad . '"');
}
$SAYFALAR = ['satis_faturalari.php' => 'satis', 'alis_faturalari.php' => 'alis', 'sevk_irsaliyeleri.php' => 'irsaliye', 'alinan_siparis_fisleri.php' => 'siparis'];

echo "=== 1. Şema yok ===\n";
$bosDb = sys_get_temp_dir() . '/fatura_ui_sema_' . getmypid() . '.sqlite';
@unlink($bosDb); touch($bosDb);
foreach ([...array_keys($SAYFALAR), 'fatura_tanimlari.php', 'fatura_cikti.php'] as $f) {
    $r = get($f, $f === 'fatura_cikti.php' ? ['id' => '1'] : [], ['db' => $bosDb]);
    ok("$f: şema yok → uyarı kartı (data-ft-sema), 3 eksik (cari → stok → fatura) migrate bağlantıları, sekme şeridi YOK", $r['kod'] === 200 && str_contains($r['govde'], 'data-ft-sema')
        && str_contains($r['govde'], 'data-sema-eksik="cari"') && str_contains($r['govde'], 'data-sema-eksik="stok"') && str_contains($r['govde'], 'data-sema-eksik="fatura"')
        && strpos($r['govde'], 'data-sema-eksik="cari"') < strpos($r['govde'], 'data-sema-eksik="fatura"') && str_contains($r['govde'], 'href="migrate.php#fatura"') && !str_contains($r['govde'], 'ft-sekmeler') && uyari_yok($r), substr($r['govde'], 0, 300) . $r['hata']);
}
$r = get('satis_faturalari.php', [], ['db' => $bosDb, 'server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
$j = json_decode(trim($r['govde']), true);
ok('şema yok + fetch → 503 JSON {ok:false, eksikler:[cari,stok,fatura]}', $r['kod'] === 503 && is_array($j) && $j['ok'] === false && $j['eksikler'] === ['cari', 'stok', 'fatura'], $r['govde']);
// Yalnız fatura eksik (cari + stok kurulu)
$yDb = sys_get_temp_dir() . '/fatura_ui_sema2_' . getmypid() . '.sqlite';
@unlink($yDb);
$pdo2 = new PDO('sqlite:' . $yDb); $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
stok_t_sema_kur($pdo2); stok_t_cari_kur($pdo2);
$r = get('satis_faturalari.php', [], ['db' => $yDb]);
ok('yalnız fatura şeması eksik → kartta yalnız "fatura" eksik', str_contains($r['govde'], 'data-sema-eksik="fatura"') && !str_contains($r['govde'], 'data-sema-eksik="cari"') && !str_contains($r['govde'], 'data-sema-eksik="stok"'));
@unlink($bosDb); @unlink($yDb);

echo "\n=== 2. Yönetici değil ===\n";
foreach ([...array_keys($SAYFALAR), 'fatura_tanimlari.php', 'fatura_cikti.php'] as $f) {
    $r = get($f, ['id' => '3'], ['admin' => false]);
    ok("$f: yönetici değil → forbidden (403), belge verisi sızmaz", str_contains((string)$r['hata'], 'forbidden') && !str_contains($r['govde'], 'STF-2026') && !str_contains($r['govde'], 'Örnek Market'), (string)$r['kod']);
}

echo "\n=== 3. Listeler ===\n";
foreach ($SAYFALAR as $f => $sinif) {
    $r = get($f);
    $h = bolge($r['govde']);
    ok("$f: 200, uyarı yok, #fatura[data-sayfa=liste][data-sinif=$sinif]", $r['kod'] === 200 && uyari_yok($r) && str_contains($h, 'id="fatura" data-sayfa="liste" data-sinif="' . $sinif . '"'), $r['hata'] . substr($r['govde'], 0, 200));
    ok("$f: sekme şeridi (5 sekme + Cari Ekstre), etkin sekme aria-current", preg_match_all('/class="ft-sekme[ "]/', $h) === 6 && (bool)preg_match('#<a class="ft-sekme" href="' . preg_quote($f, '#') . '" aria-current="page">#', $h));
    ok("$f: arama (#ftArama) + [data-yeni-belge] + #ftListe + #ftSayi + #ftToplamPb + ray [data-liste-ac]", str_contains($h, 'id="ftArama"') && str_contains($h, 'data-yeni-belge') && str_contains($h, 'id="ftListe"') && str_contains($h, 'id="ftSayi"') && str_contains($h, 'id="ftToplamPb"') && str_contains($h, 'data-liste-ac'));
    ok("$f: HTML dengeli, yinelenen id yok", !($e = html_hata($h)), implode('; ', $e));
}
$r = get('satis_faturalari.php'); $h = bolge($r['govde']);
ok('satış listesi: 2 belge (STF + IHF), satır kancaları data-id/data-tur/data-durum, tür çipleri (Tümü 2 · Normal 1 · İhracat 1 · İade 0)', substr_count($h, 'class="ft-satir"') === 2 && str_contains($h, 'data-tur="satis_ihracat" data-durum="gecerli"')
    && (bool)preg_match('#Tümü <span class="ft-cip-say">2</span>#', $h) && (bool)preg_match('#İhracat <span class="ft-cip-say">1</span>#', $h) && (bool)preg_match('#İade <span class="ft-cip-say">0</span>#', $h));
ok('satış listesi: tutar cari_tutar (15.197,86 ₺ · 2.300,00 $), pb şeridi para birimi başına ayrı (TRY / USD; toplanmaz)', str_contains($h, '15.197,86 ₺') && str_contains($h, '2.300,00 $') && (bool)preg_match('#<b>TRY</b> 15\.197,86#', $h) && (bool)preg_match('#<b>USD</b> 2\.300,00#', $h));
ok('satış listesi: dışa aktarım bağlantıları (reports.export var) → fatura_cikti.php?sinif=satis&bicim=xlsx|csv', str_contains($h, 'fatura_cikti.php?sinif=satis&amp;bicim=xlsx') && str_contains($h, 'fatura_cikti.php?sinif=satis&amp;bicim=csv'));
$r2 = get('satis_faturalari.php', [], ['perms' => ['records.read']]);
ok('reports.export yoksa dışa aktarım bağlantısı basılmaz', !str_contains($r2['govde'], 'bicim=xlsx'));
$r = get('satis_faturalari.php', ['tur' => 'satis_ihracat']);
ok('?tur=satis_ihracat: yalnız IHF; İhracat çipi aria-current', substr_count(bolge($r['govde']), 'class="ft-satir"') === 1 && str_contains($r['govde'], 'IHF-') && (bool)preg_match('#aria-current="true">İhracat#', $r['govde']));
$r = get('satis_faturalari.php', ['ara' => 'zzzyok']);
ok('arama eşleşmez → #ftSonucYok "Eşleşen belge yok" + temizle bağlantısı', str_contains($r['govde'], 'id="ftSonucYok"') && str_contains($r['govde'], 'Eşleşen belge yok'));
$r = get('sevk_irsaliyeleri.php', ['sd' => 'kismi']);
ok('irsaliye ?sd=kismi: faturalanmamış irsaliye listede YOK; ?sd=bekleyen: var (Faturalanmadı rozeti)', !str_contains(bolge($r['govde']), 'class="ft-satir"') && str_contains(get('sevk_irsaliyeleri.php', ['sd' => 'bekleyen'])['govde'], 'Faturalanmadı'));
$r = get('alinan_siparis_fisleri.php');
ok('sipariş listesi: "Kısmi" rozeti (1.000 kg siparişin 400 kg\'ı irsaliyelendi) + sevk durumu süzgeci', str_contains(bolge($r['govde']), 'ft-rozet--kismi') && str_contains($r['govde'], 'name="sd"'));
$r = get('satis_faturalari.php', ['cari_ara' => 'Örnek İhracat Ltd.']);
ok('cari süzgeci metinle (JS kapalı): ?cari_ara= → yalnız o carinin belgesi', substr_count(bolge($r['govde']), 'class="ft-satir"') === 1 && str_contains($r['govde'], 'IHF-'));

echo "\n=== 4. Boş ===\n";
$bDb = sys_get_temp_dir() . '/fatura_ui_bos_' . getmypid() . '.sqlite';
exec('php ' . escapeshellarg(__DIR__ . '/fatura_ui_e2e_setup.php') . ' ' . escapeshellarg($bDb) . ' bos');
$r = get('satis_faturalari.php', [], ['db' => $bDb]);
ok('boş liste: "Henüz satış faturası yok" + Yeni düğmesi; boş sağ panel', str_contains($r['govde'], 'Henüz satış faturası yok') && str_contains($r['govde'], 'Soldan bir belge seçin') && uyari_yok($r));
@unlink($bDb);

echo "\n=== 5. Detay ===\n";
$S = $ORNEK;
$r = get('satis_faturalari.php', ['id' => (string)$S['satis']]); $h = bolge($r['govde']);
ok('satış detayı: #ftDetay[data-id][data-tur=satis][data-durum=gecerli], resmî no 16 hane, ana sayfa data-sayfa=detay', str_contains($h, 'id="ftDetay" data-id="' . $S['satis'] . '" data-tur="satis" data-durum="gecerli"') && (bool)preg_match('/FTR\d{4}\d{9}/', $h) && str_contains($h, 'data-sayfa="detay"'));
ok('satış detayı: toplam kutusu genel 15.197,86 · KDV kırılımı %1 / %20 · cariye 15.197,86', str_contains($h, 'data-top="genel">15.197,86') && str_contains($h, 'data-kdv-grup="1"') && str_contains($h, 'data-kdv-grup="20"') && str_contains($h, 'data-top="cari">15.197,86'));
ok('satış detayı: Yazdır (fatura_cikti, yeni sekme) · Kopyala · İade oluştur · İptal (data-ft-modal=iptal, data-belge-no)', str_contains($h, 'href="fatura_cikti.php?id=' . $S['satis'] . '" target="_blank" rel="noopener" data-yazdir') && str_contains($h, 'data-kopya')
    && str_contains($h, 'data-iade href="satis_faturalari.php?yeni=1&amp;tur=satis_iade&amp;kaynak=' . $S['satis'] . '"') && str_contains($h, 'data-ft-modal="iptal" data-id="' . $S['satis'] . '"'));
ok('satış detayı: bağlantılar (cari dekont data-bag=cari_hareket, ekstre data-bag=ekstre), "Kartsız" rozeti, iptal modalı sayfada', str_contains($h, 'data-bag="cari_hareket"') && str_contains($h, 'data-bag="ekstre"') && str_contains($h, '>Kartsız<') && str_contains($h, 'id="ftModalIptal"'));
ok('satış detayı: HTML dengeli, yinelenen id yok', !($e = html_hata($h)), implode('; ', $e));
$r = get('sevk_irsaliyeleri.php', ['id' => (string)$S['irsaliye']]); $h = bolge($r['govde']);
ok('irsaliye detayı: Faturalaştır birincil (satis_faturalari.php?yeni=1&tur=satis&kaynak=ID) · Faturalanan/Kalan sütunları · zincir (ASF → IRS)', str_contains($h, 'data-faturalastir href="satis_faturalari.php?yeni=1&amp;tur=satis&amp;kaynak=' . $S['irsaliye'] . '"')
    && str_contains($h, '>Faturalanan<') && str_contains($h, '>Kalan<') && str_contains($h, 'id="ftZincir"') && str_contains($h, 'data-zincir-tur="siparis"') && str_contains($h, 'data-bag="stok_fis"'));
$r = get('alinan_siparis_fisleri.php', ['id' => (string)$S['siparis']]); $h = bolge($r['govde']);
ok('sipariş detayı: İrsaliye oluştur + Fatura oluştur + Kapat (data-ft-modal=kapat) · Karşılanan/Faturalanan/Kalan · kalan 600 kg (açık)', str_contains($h, 'data-irsaliye-olustur') && str_contains($h, 'data-fatura-olustur') && str_contains($h, 'data-ft-modal="kapat"')
    && str_contains($h, '>Karşılanan<') && str_contains($h, 'ft-kalan--acik">600 kg') && str_contains($r['govde'], 'id="ftModalKapat"'));
$r = get('satis_faturalari.php', ['id' => (string)$S['ihracat']]); $h = bolge($r['govde']);
ok('ihracat detayı: TL karşılığı (kur 34,5678) = 79.505,94 ₺ (plan §4: 7.950.594 kr), istisna notu', str_contains($h, 'data-top="tl">79.505,94 ₺') && str_contains($h, 'KDV istisnası: 301'));
$r = get('alis_faturalari.php', ['id' => (string)$S['satis']]);
ok('başka sınıfın belgesi (?id=satış alış sayfasında) gösterilmez: "başka bir sayfaya ait"', str_contains($r['govde'], 'başka bir sayfaya ait') && !str_contains(bolge($r['govde']), 'id="ftDetay" data-id'));
$r = get('satis_faturalari.php', ['id' => (string)$S['satis'], 'parca' => 'detay']);
ok('?parca=detay: yalnız #ftDetay (sayfa kabuğu YOK)', str_starts_with(trim($r['govde']), '<section class="ft-detay" id="ftDetay"') && !str_contains($r['govde'], '<html'));
$r = get('satis_faturalari.php', ['id' => '999', 'parca' => 'detay']);
ok('?parca=detay olmayan id → 404', $r['kod'] === 404);

echo "\n=== 6. Form (her tür) ===\n";
$r = get('satis_faturalari.php', ['yeni' => '1']); $h = bolge($r['govde']); $g = $r['govde'];
ok('yeni satış formu: #ftForm[data-ft-fatura][data-tur=satis] · csrf · islem=belge_kaydet · istek_anahtari · eksiye_izin=0 · #ftVeri · #ftSatirSablon · #ftKartListe · #ftCariListe', str_contains($h, 'id="ftForm" data-ft-fatura data-tur="satis"')
    && str_contains($h, 'name="csrf" value="test-csrf-belirteci"') && str_contains($h, 'name="islem" value="belge_kaydet"') && (bool)preg_match('/name="istek_anahtari" value="[0-9a-f]{24}"/', $h)
    && str_contains($h, 'name="eksiye_izin" value="0"') && str_contains($g, 'id="ftVeri"') && str_contains($g, 'id="ftSatirSablon"') && str_contains($h, 'id="ftKartListe"') && str_contains($g, 'id="ftCariListe"'));
ok('satış formu: tür segmenti (radio satis/satis_ihracat/satis_iade), satış checked; ana sayfa ft-ana--form', substr_count($h, 'type="radio" name="tur"') === 3 && str_contains($h, 'value="satis" id="ftTur_satis" checked') && str_contains($h, 'ft-ana ft-ana--detay ft-ana--form'));
ok('satış formu: seri seçimi görünür (FTR, varsayılan) · istisna GİZLİ+disabled · tevkifat görünür · stopaj yok · fatura_no (iade) gizli', alan_gorunur($h, 'seri_id') && !alan_gorunur($h, 'istisna_id') && (bool)preg_match('/name="istisna_id" disabled/', $h)
    && alan_gorunur($h, 'tevkifat_id') && !str_contains($h, 'name="stopaj_id"') && !alan_gorunur($h, 'fatura_no'));
ok('satış formu: satır alanları paralel diziler (kart_id[] kart_ara[] urun_adi[] miktar[] birim[] birim_fiyat[] iskonto[] kdv_orani[] lot[] satir_aciklama[] kaynak_satir_id[] gtip[] …)', count(array_filter(['kart_id[]', 'kart_ara[]', 'urun_adi[]', 'miktar[]', 'birim[]', 'birim_fiyat[]', 'iskonto[]', 'kdv_orani[]', 'gtip[]', 'satir_brut_kg[]', 'satir_net_kg[]', 'satir_kap[]', 'lot[]', 'satir_aciklama[]', 'kaynak_satir_id[]'], fn($a) => str_contains($h, 'name="' . $a . '"'))) === 15);
ok('satış formu: toplam paneli (#ftToplam data-top brut/isk/net/kdv/genel/tevkifat/cari/tl) + eylem çubuğu [data-kaydet] + #ftEksik (gizli) + #ftHataOzet', count(array_filter(['brut', 'isk', 'net', 'kdv', 'genel', 'tevkifat', 'stopaj', 'bagkur', 'cari', 'tl'], fn($a) => str_contains($h, 'data-top="' . $a . '"'))) === 10
    && str_contains($h, 'class="ft-eylem-bar"') && str_contains($h, 'data-kaydet') && (bool)preg_match('/id="ftEksik"[^>]*hidden/', $h) && str_contains($h, 'id="ftHataOzet"'));
ok('satış formu: kaynak modalı + mobil satır alt sayfası (içinde <form> YOK; data-sh girdileri name\'siz)', str_contains($g, 'id="ftModalKaynak"') && str_contains($g, 'id="ftSatirSheet"')
    && (bool)preg_match('#id="ftSatirSheet".*?</div></div></div>#s', $g, $sh) && !str_contains($sh[0], '<form') && !preg_match('/data-sh="[a-z_]+"[^>]*\sname=/', $sh[0]));
ok('satış formu: HTML dengeli, yinelenen id yok, uyarı yok', !($e = html_hata($h)) && uyari_yok($r), implode('; ', $e));
preg_match('#<script type="application/json" id="ftVeri">(.*?)</script>#s', $g, $vm);
$V = json_decode($vm[1] ?? '', true);
ok('#ftVeri JSON: kartlar · cariler · bakiye · kdv{oranlar,varsayilan=1} · tanimlar · seri_onizleme · satir_max=60 · gecisler', is_array($V) && count($V['kartlar']) >= 7 && count($V['cariler']) >= 5 && $V['kdv']['varsayilan'] === 1
    && isset($V['tanimlar']['tevkifat'], $V['tanimlar']['odeme_sekli']) && $V['satir_max'] === FATURA_SATIR_MAX && $V['gecisler']['satis'] === ['siparis', 'irsaliye'] && count((array)$V['seri_onizleme']) === 3);
$r = get('satis_faturalari.php', ['yeni' => '1', 'tur' => 'satis_ihracat']); $h = bolge($r['govde']);
ok('ihracat formu: istisna görünür (301 varsayılan seçili) · KDV satırda yalnız %0 (aria-readonly) · GTİP/kg/kap görünür · tevkifat gizli', alan_gorunur($h, 'istisna_id') && (bool)preg_match('/<option value="\d+" selected[^>]*data-kod="301"/', $h)
    && str_contains($h, 'aria-readonly="true"><option value="0" selected>%0</option></select>') && alan_gorunur($h, 'gtip[]') && !alan_gorunur($h, 'tevkifat_id'));
$r = get('satis_faturalari.php', ['yeni' => '1', 'tur' => 'satis_iade']); $h = bolge($r['govde']);
ok('satış iade formu: karşı tarafın numarası (fatura_no) görünür; seri gizli', alan_gorunur($h, 'fatura_no') && !alan_gorunur($h, 'seri_id'));
$r = get('alis_faturalari.php', ['yeni' => '1', 'tur' => 'alis_mustahsil']); $h = bolge($r['govde']);
ok('müstahsil formu: stopaj + Bağ-Kur görünür (varsayılan seçili) · MMK serisi · "Net ödenecek (üreticiye)" · stok etkisi varsayılan açık', alan_gorunur($h, 'stopaj_id') && alan_gorunur($h, 'bagkur_id')
    && (bool)preg_match('/name="stopaj_id"[^>]*><option value="">Yok<\/option><option value="\d+" selected/', $h) && str_contains($h, 'MMK') && str_contains($h, 'Net ödenecek (üreticiye)') && (bool)preg_match('/name="stok_etkisi" value="1" checked/', $h));
$r = get('alis_faturalari.php', ['yeni' => '1']); $h = bolge($r['govde']);
ok('alış formu: fatura_no zorunlu görünür · seri yok (karşı taraf) · stopaj gizli', alan_gorunur($h, 'fatura_no') && !alan_gorunur($h, 'stopaj_id'));
$r = get('sevk_irsaliyeleri.php', ['yeni' => '1']); $h = bolge($r['govde']);
ok('irsaliye formu: tek tür (gizli tur=irsaliye) · plaka/şoför/sevk adresi · IRS serisi · stok notu · cari hareketi oluşmaz notu', str_contains($h, 'name="tur" value="irsaliye"') && str_contains($h, 'name="plaka"') && str_contains($h, 'name="sofor_adi"') && str_contains($h, 'IRS — İrsaliye serisi')
    && str_contains($h, 'Stoktan düşer') && str_contains($h, 'Cari hareketi oluşmaz'));
$r = get('alinan_siparis_fisleri.php', ['yeni' => '1']); $h = bolge($r['govde']);
ok('sipariş formu: termin + müşteri sipariş no + teslim şekli; stok etkisi YOK; kaynaktan getir YOK', str_contains($h, 'name="termin_tarihi"') && str_contains($h, 'Müşteri sipariş no') && str_contains($h, 'name="teslim_id"') && !str_contains($h, 'name="stok_etkisi"') && !str_contains($h, 'data-kaynak-getir'));

echo "\n=== 7. Kopya / kaynak ===\n";
$r = get('satis_faturalari.php', ['kopya' => (string)$S['satis']]); $h = bolge($r['govde']);
ok('?kopya=: 2 satır dolu (Domates 1.250 · Nakliye 3), cari seçili, bilgi şeridi "… belgesinden kopyalandı", başlık özet (daraltılmış)', substr_count($h, '<tr class="ft-fsatir') === 2 && str_contains($h, 'value="STK-0001 — Domates (Salkım)"') && str_contains($h, 'value="1.250"')
    && str_contains($h, 'value="Nakliye hizmeti"') && str_contains($h, 'belgesinden kopyalandı') && str_contains($h, 'ft-bas ft-bas--ozet'));
$r = get('satis_faturalari.php', ['yeni' => '1', 'tur' => 'satis', 'kaynak' => (string)$S['irsaliye']]); $h = bolge($r['govde']);
ok('?kaynak=IRS (JS kapalı dönüşüm): 1 kaynaklı satır (kaynak_satir_id dolu, data-kalan-milli=400000, kalan 400 kg) + IRS rozeti + bilgi şeridi', substr_count($h, 'class="ft-fsatir ft-fsatir--kaynakli"') === 1 && str_contains($h, 'data-kalan-milli="400000"')
    && str_contains($h, 'kalan 400 kg') && (bool)preg_match('/name="kaynak_satir_id\[\]" value="\d+"/', $h) && str_contains($h, 'ft-rozet--kaynak">IRS-') && str_contains($h, 'satır getirildi'));
$r = get('sevk_irsaliyeleri.php', ['yeni' => '1', 'tur' => 'irsaliye', 'kaynak' => [(string)$S['siparis']]]); $h = bolge($r['govde']);
ok('?kaynak[]= dizi biçimi de çalışır (onay kutuları): siparişten 2 satır (kalan Domates 600 + Kasa 100)', substr_count($h, 'ft-fsatir--kaynakli') === 2 && str_contains($h, 'value="600"') && str_contains($h, 'value="100"'));

echo "\n=== 8. ?parca= JSON ===\n";
$market = (int)$S['cari']['Örnek Market A.Ş.'];
[$r, $j] = getj('satis_faturalari.php', ['parca' => 'cari_ozet', 'cari_id' => (string)$market]);
ok('cari_ozet: {ok, bakiyeler{TRY}, son_fiyatlar{kart_id:{f4,pb,tarih,belge_no}}, acik_kaynak_say}', $r['kod'] === 200 && $j['ok'] === true && isset($j['bakiyeler']['TRY']) && $j['son_fiyatlar']['1']['f4'] === 123456 && $j['son_fiyatlar']['1']['pb'] === 'TRY' && (int)$j['acik_kaynak_say'] >= 1, json_encode($j, JSON_UNESCAPED_UNICODE));
[$r, $j] = getj('satis_faturalari.php', ['parca' => 'kaynaklar', 'tur' => 'satis', 'cari_id' => (string)$market]);
ok('kaynaklar (satış ← irsaliye + sipariş): 2 aday, kalan_milli + para_birimi', $j['ok'] === true && count($j['adaylar']) === 2 && isset($j['adaylar'][0]['kalan_milli'], $j['adaylar'][0]['para_birimi']), json_encode($j, JSON_UNESCAPED_UNICODE));
[$r, $j] = getj('satis_faturalari.php', ['parca' => 'kaynak_satirlari', 'tur' => 'satis', 'ids' => $S['siparis'] . ',' . $S['irsaliye']]);
ok('kaynak_satirlari: 3 satır (sipariş 2 + irsaliye 1) kaynak_satir_id/kaynak_belge_no/kalan_milli/miktar', $j['ok'] === true && count($j['satirlar']) === 3 && isset($j['satirlar'][0]['kaynak_satir_id'], $j['satirlar'][0]['kaynak_belge_no'], $j['satirlar'][0]['kalan_milli']));
[$r, $j] = getj('satis_faturalari.php', ['parca' => 'kaynaklar', 'tur' => 'irsaliye', 'cari_id' => '1']);
ok('parca: sınıf dışı tür → 400 JSON', $r['kod'] === 400 && $j['ok'] === false);

echo "\n=== 9. Tanımlar ===\n";
$r = get('fatura_tanimlari.php'); $h = bolge($r['govde']);
ok('tanımlar: 8 sekme (ftTabKdv … ftTabOdeme) + paneller, KDV etkin, uyarı yok', count(array_filter(['Kdv', 'Tevkifat', 'Kesinti', 'Istisna', 'Seri', 'Teslim', 'Sablon', 'Odeme'], fn($a) => str_contains($h, 'id="ftTab' . $a . '"') && str_contains($h, 'id="ftPanel' . $a . '"'))) === 8
    && str_contains($h, 'id="ftTabKdv" href="fatura_tanimlari.php?sekme=kdv" aria-controls="ftPanelKdv" aria-selected="true"') && uyari_yok($r));
ok('tanımlar: ekle formları (.ft-tanim-ekle, islem=tanim_kaydet), satırlar data-id + switch + düzenle; seri sonraki numara önizlemesi', substr_count($h, 'class="ft-tanim-ekle"') === 8 && str_contains($h, 'role="switch"') && str_contains($h, 'data-tanim-duzen') && (bool)preg_match('/sonraki FTR\d{13}/', $h));
ok('tanımlar: kullanılan seri (FTR) silinemez → "kullanımda" rozeti; kod salt okunur', str_contains($h, '&#128274; kullanımda') && (bool)preg_match('/name="kod" maxlength="3" pattern="\[A-Z\]\{3\}" value="FTR"[^>]*readonly/', $h));
ok('tanımlar: ödeme şekilleri salt okunur + Cari Tanımları bağlantısı · mali müşavir uyarısı', str_contains($h, 'cari_tanimlari.php?sekme=odeme_sekli') && str_contains($h, 'mali müşavirinizle teyit edin'));
ok('tanımlar: HTML dengeli, yinelenen id yok', !($e = html_hata($h)), implode('; ', $e));
$r = get('fatura_tanimlari.php', ['sekme' => 'seri']);
ok('?sekme=seri → Seriler sekmesi etkin', str_contains($r['govde'], 'id="ftTabSeri" href="fatura_tanimlari.php?sekme=seri" aria-controls="ftPanelSeri" aria-selected="true"'));

echo "\n=== 10. XSS ===\n";
$x = cari_kart_kaydet($db, ['unvan' => '<script>alert(1)</script> Ltd', 'tip' => 'musteri', 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
$xr = fatura_kaydet($db, fatura_t_istek('satis', [['urun_adi' => '<img src=x onerror=alert(2)>', 'birim' => 'adet', 'miktar' => '1', 'birim_fiyat' => '10', 'kdv_orani' => '20']], ['cari_id' => (string)$x['id'], 'aciklama' => '"><svg onload=alert(3)>']), 1);
ok('XSS kurulumu: belge kaydedildi', !empty($xr['ok']), json_encode($xr, JSON_UNESCAPED_UNICODE));
foreach ([['satis_faturalari.php', []], ['satis_faturalari.php', ['id' => (string)$xr['id']]], ['satis_faturalari.php', ['kopya' => (string)$xr['id']]], ['fatura_cikti.php', ['id' => (string)$xr['id']]]] as [$f, $gq]) {
    $r = get($f, $gq);
    ok("XSS: $f " . json_encode($gq) . ' — cari unvan / ürün adı / açıklama kaçışlı', !str_contains($r['govde'], '<script>alert(1)') && !str_contains($r['govde'], '<img src=x') && !str_contains($r['govde'], '<svg onload') && str_contains($r['govde'], '&lt;'));
}

echo "\n=== 11. fatura_cikti (yazdır + dışa aktarım) ===\n";
$r = get('fatura_cikti.php', ['id' => (string)$S['satis']]); $g = $r['govde'];
ok('yazdır: A4 sayfası (print_base + fatura.css), SATIŞ FATURASI, 16 hane no, firma başlığı (siparis_inv_ihracatci), alt not', $r['kod'] === 200 && str_contains($g, 'assets/fatura.css') && str_contains($g, 'SATIŞ FATURASI') && (bool)preg_match('/FTR\d{13}/', $g)
    && str_contains($g, siparis_inv_ihracatci()['unvan']) && str_contains($g, 'Bu çıktı bilgi amaçlıdır, resmî e-belge değildir.') && uyari_yok($r));
ok('yazdır: toplam kutusu data-yz genel 15.197,86 · kdv-1 150,46 · kdv-20 0,20 · tutar yazıyla YOK (yönetici kararı #8)', str_contains($g, 'data-yz="genel"><th>GENEL TOPLAM</th><td class="ft-sayi">15.197,86 TRY') && str_contains($g, 'data-yz="kdv-1"><th>KDV %1</th><td class="ft-sayi">150,46')
    && str_contains($g, 'data-yz="kdv-20"') && !str_contains($g, 'Yalnız'));
$r = get('fatura_cikti.php', ['id' => (string)$S['ihracat']]);
ok('yazdır ihracat: İHRACAT FATURASI · GTİP sütunu · KDV sütunu yok · TL karşılığı 79.505,94 ₺ · istisna', str_contains($r['govde'], 'İHRACAT FATURASI') && str_contains($r['govde'], '<th>GTİP</th>') && !str_contains($r['govde'], 'KDV%') && str_contains($r['govde'], 'data-yz="tl"') && str_contains($r['govde'], '79.505,94 ₺') && str_contains($r['govde'], '301'));
$r = get('fatura_cikti.php', ['id' => (string)$S['irsaliye']]);
ok('yazdır irsaliye: SEVK İRSALİYESİ · Lot sütunu · imza kutuları · toplam miktar', str_contains($r['govde'], 'SEVK İRSALİYESİ') && str_contains($r['govde'], '<th>Lot</th>') && str_contains($r['govde'], 'fatura-yz-imza') && str_contains($r['govde'], 'Toplam miktar'));
// Tevkifat + müstahsil belgeleri
$tev = fatura_t_tanim($db, 'tevkifat', 'Tevkifat 7/10');
$tr = fatura_kaydet($db, fatura_t_istek('satis', [['urun_adi' => 'Danışmanlık', 'birim' => 'adet', 'miktar' => '1', 'birim_fiyat' => '10.000', 'kdv_orani' => '20']], ['cari_id' => (string)$market, 'tevkifat_id' => (string)$tev]), 1);
$r = get('fatura_cikti.php', ['id' => (string)$tr['id']]);
ok('yazdır tevkifat: Tevkifat (7/10) (−) 1.400,00 · ÖDENECEK 10.600,00 (plan §4)', !empty($tr['ok']) && str_contains($r['govde'], 'data-yz="tevkifat"><th>Tevkifat (7/10) (−)</th><td class="ft-sayi">1.400,00') && str_contains($r['govde'], 'data-yz="cari"><th>ÖDENECEK</th><td class="ft-sayi">10.600,00'), json_encode($tr, JSON_UNESCAPED_UNICODE));
$uret = (int)$S['cari']['Örnek Üretici Kooperatifi'];
$mr = fatura_kaydet($db, fatura_t_istek('alis_mustahsil', [['kart_id' => (string)$S['kart']['Domates (Salkım)'], 'miktar' => '5.000', 'birim_fiyat' => '20,00', 'kdv_orani' => '0']], ['cari_id' => (string)$uret, 'stok_etkisi' => '1',
    'stopaj_id' => (string)fatura_t_tanim($db, 'stopaj', 'Müstahsil GV stopajı %2'), 'bagkur_id' => (string)fatura_t_tanim($db, 'bagkur', 'Bağ-Kur kesintisi %1')]), 1);
$r = get('fatura_cikti.php', ['id' => (string)$mr['id']]);
ok('yazdır müstahsil: MÜSTAHSİL MAKBUZU · stopaj 2.000,00 · Bağ-Kur 1.000,00 · NET ÖDENECEK 97.000,00 · ÜRETİCİ kutusu', !empty($mr['ok']) && str_contains($r['govde'], 'MÜSTAHSİL MAKBUZU') && str_contains($r['govde'], 'data-yz="stopaj"') && str_contains($r['govde'], '2.000,00')
    && str_contains($r['govde'], 'data-yz="cari"><th>NET ÖDENECEK</th><td class="ft-sayi">97.000,00') && str_contains($r['govde'], 'ÜRETİCİ'), json_encode($mr, JSON_UNESCAPED_UNICODE));
$r = get('alis_faturalari.php', ['id' => (string)$mr['id']]);
ok('müstahsil detayı: Net ödenecek (üreticiye) 97.000,00 · stok fişi bağlantısı stok_giris_fisleri.php', str_contains($r['govde'], 'Net ödenecek (üreticiye)') && str_contains($r['govde'], 'data-top="cari">97.000,00') && str_contains($r['govde'], 'data-bag="stok_fis" href="stok_giris_fisleri.php?id='));
$r = get('fatura_cikti.php', ['id' => '99999']);
ok('yazdır: olmayan belge → 404', $r['kod'] === 404);
$auditOnce = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE module = 'fatura' AND action = 'export'")->fetchColumn();
$r = get('fatura_cikti.php', ['sinif' => 'satis', 'bicim' => 'csv']);
$satirlar = array_values(array_filter(explode("\n", $r['govde'])));
ok('CSV: BOM + ; ayraç + başlık + belge satırları + para birimi başına TOPLAM satırı (binlik ayraçsız: 15197,86)', $r['kod'] === 200 && str_starts_with($r['govde'], "\xEF\xBB\xBF") && (bool)preg_match('/Tarih;"?Belge No"?;/', $satirlar[0])
    && str_contains($r['govde'], 'TOPLAM TRY') && str_contains($r['govde'], '15197,86') && !str_contains($r['govde'], '15.197,86'), substr($r['govde'], 0, 400));
ok('CSV: export audit kaydı (+1, module=fatura, report=liste)', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE module = 'fatura' AND action = 'export'")->fetchColumn() === $auditOnce + 1);
$r = get('fatura_cikti.php', ['sinif' => 'satis', 'bicim' => 'xlsx'], ['perms' => ['records.read']]);
ok('reports.export yoksa dışa aktarım reddedilir (require_perm → forbidden 403; yazdır yetkisi yetmez)', str_contains((string)$r['hata'], 'forbidden') && !str_starts_with($r['govde'], 'PK'));
$r = get('fatura_cikti.php', ['sinif' => 'satis', 'bicim' => 'xlsx']);
ok('XLSX: geçerli zip (PK, [Content_Types].xml) — xlsx_indir ortak üreticisi', $r['kod'] === 200 && str_starts_with($r['govde'], 'PK') && str_contains($r['govde'], '[Content_Types].xml'), (string)$r['hata']);
$r = get('fatura_cikti.php', ['sinif' => 'yok', 'bicim' => 'csv']);
ok('dışa aktarım: geçersiz sınıf → 400', $r['kod'] === 400);

echo "\n=== 12. Kaynaklı stok fişi (stok_ui) ===\n";
$al = fatura_oku($db, (int)$S['alis']);
$r = get('stok_giris_fisleri.php', ['id' => (string)$al['stok_fis_id']]);
ok('alış faturasından üretilen stok fişi: "Kaynak belge #ID" rozeti, iptal düğmesi YOK ("Kaynak belgeden iptal edilir.")', str_contains($r['govde'], 'Kaynak belge #' . $S['alis']) && str_contains($r['govde'], 'Kaynak belgeden iptal edilir.') && !str_contains($r['govde'], 'data-st-modal="iptal" data-id="' . $al['stok_fis_id'] . '"'), substr($r['govde'], 0, 300) . $r['hata']);

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
