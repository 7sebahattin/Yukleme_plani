<?php
// =========================================================
// scripts/ceksenet_ui_smoke.php — Çek-Senet sayfaları/uçları: GERÇEK HTML üzerinde PHP düzeyi testler (Aşama 4: ön yüz)
//   php scripts/ceksenet_ui_smoke.php        → çıkış kodu 0 = hepsi geçti
// Her istek ALT SÜREÇTE (scripts/_ceksenet_istek.php) gerçek exit/header ile çalışır; DB geçici dosya SQLite + uydurma veri. Canlı DB'ye dokunmaz.
// Kapsam: şema yok · yönetici değil · boş durumlar (kayıtsız / kasasız) · dolu liste (özet pb ayrı, vade renkleri, durum rozetleri, süzgeç, seçim kancaları) · detay + kısmi yanıt ·
//         işlem modalı (ceksenet_islemler() yapısından) · bordro formu (paralel dizi alanları) · tanımlar · XSS · HTML denge · cek_cikti bordro + xlsx/csv yetkisi + audit.
// =========================================================
ini_set('error_log', '/dev/null');
if (PHP_SAPI !== 'cli') exit(1);
$DB = sys_get_temp_dir() . '/ceksenet_ui_smoke_' . getmypid() . '.sqlite';
exec('php ' . escapeshellarg(__DIR__ . '/ceksenet_ui_e2e_setup.php') . ' ' . escapeshellarg($DB) . ' fisler', $cikti, $kod);
if ($kod !== 0) { fwrite(STDERR, "kurulum başarısız:\n" . implode("\n", $cikti) . "\n"); exit(1); }
putenv('CEKSENET_E2E_DB=' . $DB);
require_once __DIR__ . '/_ceksenet_test_lib.php';
require_once $ROOT . '/config/siparis_invoice.php';
register_shutdown_function(static function () use ($DB): void { @unlink($DB); });
$db = db();
$IS_ADMIN = true; $UID = 1;
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function get(string $dosya, array $get = [], array $ek = []): array { return ceksenet_t_istek_cagri(['dosya' => $dosya, 'get' => $get] + $ek + ['depolar' => ['Merkez Depo']]); }
function uyari_yok(array $r): bool { return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b/', $r['govde']) && $r['hata'] === null; }
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
        if ($t[1] === '/') { $son = array_pop($st); if ($son !== $ad) { $hata[] = "kapanış </$ad> beklenen <" . ($son ?? 'yok') . '>'; if (count($hata) > 3) break; } }
        else $st[] = $ad;
    }
    if ($st) $hata[] = 'kapanmamış: ' . implode(',', array_slice($st, -4));
    preg_match_all('/\sid="([^"]+)"/', $h, $ids);
    foreach (array_count_values($ids[1]) as $id => $n) if ($n > 1) $hata[] = "yinelenen id=$id";
    return $hata;
}
function form_hata(string $h): array
{
    $hata = [];
    preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $h, $fm, PREG_SET_ORDER);
    foreach ($fm as $i => $f) if (stripos($f[1], 'method="post"') !== false && !str_contains($f[2], 'name="csrf"')) $hata[] = "form#$i csrf yok";
    return $hata;
}
function satir_say(string $h): int { return preg_match_all('#<tr class="cs-satir[^"]*"#', $h); }
$CARI = []; foreach ($db->query('SELECT id, unvan FROM cari_kartlar ORDER BY id')->fetchAll() as $r) $CARI[$r['unvan']] = (int)$r['id'];
$BRD = (string)$db->query('SELECT bordro_no FROM ceksenet_kayitlar WHERE id = 1')->fetchColumn();

echo "=== 1. Şema yok ===\n";
$bos = sys_get_temp_dir() . '/ceksenet_ui_sema_' . getmypid() . '.sqlite';
@unlink($bos); touch($bos);
register_shutdown_function(static function () use ($bos): void { @unlink($bos); });
foreach (['cek_dekontu.php', 'senet_dekontu.php', 'cek_tanimlari.php', 'cek_cikti.php'] as $f) {
    $r = get($f, ['bordro' => 'BRD-2026-000001'], ['db' => $bos]);
    ok("$f: şema yok → uyarı kartı (data-cs-sema) + Cari / Kasa / Çek-Senet migrate bağlantıları, 200, PHP uyarısı yok", $r['kod'] === 200 && str_contains($r['govde'], 'data-cs-sema') && str_contains($r['govde'], 'data-sema-eksik="cari"') && str_contains($r['govde'], 'data-sema-eksik="kasa"') && str_contains($r['govde'], 'data-sema-eksik="ceksenet"') && uyari_yok($r), $r['hata'] ?? substr($r['govde'], 0, 200));
    ok("$f: şema yok → liste / form render EDİLMEZ", !str_contains($r['govde'], 'id="ceksenet"') && !str_contains($r['govde'], 'cs-tablo'));
}

echo "\n=== 2. Yönetici değil ===\n";
foreach (['cek_dekontu.php', 'senet_dekontu.php', 'cek_tanimlari.php', 'cek_cikti.php'] as $f) {
    $r = get($f, [], ['admin' => false]);
    ok("$f: yönetici değil → forbidden (içerik sızmaz)", str_contains((string)$r['hata'], 'forbidden') || $r['kod'] === 403, $r['hata'] ?? $r['kod']);
    ok("$f: yönetici değil → evrak / tanım metni YOK", !str_contains($r['govde'], 'ÇA-2026') && !str_contains($r['govde'], 'Karne A'));
}

echo "\n=== 3. Çek Dekontu — alınan (varsayılan: açık durumlar) ===\n";
$r = get('cek_dekontu.php');
$h = $r['govde'];
ok('200, PHP uyarısı yok, başlık Çek Dekontu, kök .ceksenet#ceksenet data-sinif=cek data-yon=alinan data-bugun', $r['kod'] === 200 && uyari_yok($r) && str_contains($h, '<title>') && str_contains($h, 'id="ceksenet"') && str_contains($h, 'data-sinif="cek"') && str_contains($h, 'data-yon="alinan"') && str_contains($h, 'data-bugun="' . date('Y-m-d') . '"'));
ok('HTML dengesi + yinelenen id yok + her POST formunda csrf', !html_hata($h) && !form_hata($h), json_encode(html_hata($h) ?: form_hata($h)));
ok('açık durumlar: 4 satır (portföy ×3 [TRY ×2, USD ×1] + tahsilde); ciro edilen satır listede YOK', satir_say($h) === 4 && !str_contains($h, '2000014'), (string)satir_say($h));
ok('özet kartları: Portföyde, Tahsilde, Ciro edilen, Vadesi geçen — TRY ve USD AYRI (20.750,50 ₺ ve 1.200,00 $; birleşik 21.950,50 YOK)', str_contains($h, 'data-ozet="portfoy"') && str_contains($h, 'data-ozet="tahsilde"') && str_contains($h, 'data-ozet="ciro"') && str_contains($h, 'data-ozet="gecmis"')
    && str_contains($h, '20.750,50 ₺') && str_contains($h, '1.200,00 $') && !str_contains($h, '21.950,50') && str_contains($h, '30.000,00 ₺') && str_contains($h, '5.000,00 ₺') && str_contains($h, '12.500,00 ₺'));
ok('vadesi geçmiş açık evrak KIRMIZI (cs-vade--gecmis + "gün geçti"); ≤7 gün SARI (cs-vade--yakin + "gün kaldı")', str_contains($h, 'cs-vade--gecmis') && str_contains($h, '9 gün geçti') && str_contains($h, 'cs-vade--yakin') && str_contains($h, '4 gün kaldı'));
ok('durum rozetleri cs-d-portfoy / cs-d-tahsilde; tarih TR biçimi', str_contains($h, 'cs-rozet cs-d-portfoy') && str_contains($h, 'cs-rozet cs-d-tahsilde') && str_contains($h, date('d.m.Y', strtotime('-9 days'))));
ok('satır seçimi kancaları: tablo + kart onay kutuları [data-cs-sec], tümünü seç [data-cs-hepsi]; satırda data-vade / data-kurus / data-pb / data-izinli', str_contains($h, 'data-cs-hepsi') && substr_count($h, 'data-cs-sec value="') >= 8 && (bool)preg_match('/<tr class="cs-satir"[^>]*data-vade="[\d-]+" data-kurus="1250000" data-pb="TRY" data-izinli="[a-z_ ]*ciro/', $h));
ok('mobil kartlar (cs-hk) ve tablo (cs-tablo) AYNI veriden iki kez; kartta onay kutusu', substr_count($h, '<article class="cs-hk') === 4 && str_contains($h, 'class="cs-hk-sec"'));
ok('Yeni giriş bordrosu düğmesi (?yeni=1&yon=alinan) + XLSX/CSV bağlantıları (reports.export var)', str_contains($h, 'cek_dekontu.php?yeni=1&amp;yon=alinan') && str_contains($h, 'cek_cikti.php?') && str_contains($h, 'bicim=xlsx') && str_contains($h, 'bicim=csv') && str_contains($h, 'sinif=cek'));
$r2 = get('cek_dekontu.php', [], ['perms' => ['records.read']]);
ok('reports.export yoksa XLSX/CSV bağlantısı basılmaz', !str_contains($r2['govde'], 'bicim=xlsx'));
ok('detay paneli (#csPanel) boş durumla; seçim çubuğu (#csSecimBar hidden) + toplu menü; alt eylem çubuğu (mobil)', str_contains($h, 'id="csPanel"') && str_contains($h, 'Evrak seçin') && str_contains($h, 'id="csSecimBar"') && str_contains($h, 'id="csTopluMenu"') && str_contains($h, 'id="csEylemBar"'));
ok('script + css: assets/ceksenet.css ve ceksenet.js (cache-bust ?v=)', (bool)preg_match('#assets/ceksenet\.css\?v=\d+#', $h) && (bool)preg_match('#assets/ceksenet\.js\?v=\d+#', $h));
ok('liste özeti: "4 kayıt" + para birimi başına toplam (iptal hariç notu)', str_contains($h, '4 kayıt') && str_contains($h, 'iptal edilenler toplama girmez'));

echo "\n=== 4. Süzgeçler ===\n";
$say = fn(array $q) => satir_say(get('cek_dekontu.php', $q)['govde']);
ok('durum=tumu: 5 satır (ciro edilen dahil)', $say(['durum' => 'tumu']) === 5);
ok('durum=ciro: 1 satır; durum=tahsilde: 1; durum=karsiliksiz: 0 → "Eşleşen çek yok" + temizle', $say(['durum' => 'ciro']) === 1 && $say(['durum' => 'tahsilde']) === 1 && str_contains(get('cek_dekontu.php', ['durum' => 'karsiliksiz'])['govde'], 'Eşleşen çek yok'));
ok('ara=Garanti → 1 satır; ara=2000012 (çek no) → 1; ara=ÖRNEK TEDARİK (cari unvanı) → USD satırı', $say(['ara' => 'Garanti']) === 1 && $say(['ara' => '2000012']) === 1 && $say(['ara' => 'ÖRNEK TEDARİK']) >= 1);
ok('donem=gecmis → yalnız vadesi geçmiş (1); donem=7g → 1 (4 gün kaldı); donem=30g → 2 (7g + USD 20g)', $say(['donem' => 'gecmis']) === 1 && $say(['donem' => '7g']) === 1 && $say(['donem' => '30g']) === 2);
ok('donem=ozel + bas/bit: aralık alanları görünür (hidden değil), değerler korunur', str_contains(get('cek_dekontu.php', ['donem' => 'ozel', 'bas' => date('Y-m-d'), 'bit' => date('Y-m-d', strtotime('+10 days'))])['govde'], 'value="' . date('Y-m-d') . '"'));
ok('cari=ID süzgeci: yalnız o carinin evrakı + kaldırma çipi (cari adı)', ($rc = get('cek_dekontu.php', ['cari' => (string)$CARI['ÖRNEK TEDARİK LTD.'], 'durum' => 'tumu'])) && satir_say($rc['govde']) === 1 && str_contains($rc['govde'], 'cs-cip--cari') && str_contains($rc['govde'], 'ÖRNEK TEDARİK LTD.'));
$rv = get('cek_dekontu.php', ['yon' => 'verilen']);
ok('yon=verilen: 1 satır (verilen çek 9.000,00 ₺ Verildi), özet Ödenecek (data-ozet=verildi), "Yeni çıkış bordrosu"', satir_say($rv['govde']) === 1 && str_contains($rv['govde'], 'data-ozet="verildi"') && str_contains($rv['govde'], '9.000,00 ₺') && str_contains($rv['govde'], 'Yeni çıkış bordrosu') && str_contains($rv['govde'], 'cs-d-verildi'));
ok('geçersiz yon / durum / donem → varsayılana düşer, hata yok', ($rg = get('cek_dekontu.php', ['yon' => 'x', 'durum' => '<b>', 'donem' => 'zz'])) && $rg['kod'] === 200 && uyari_yok($rg) && satir_say($rg['govde']) === 4);

echo "\n=== 5. Senet Dekontu ===\n";
$rs = get('senet_dekontu.php');
ok('senet: 2 satır, başlık "Senet Dekontu", sütun "Borçlu / senet no" (banka yok), Protesto etiketi işlem meta\'sında', satir_say($rs['govde']) === 2 && str_contains($rs['govde'], 'Senet Dekontu') && str_contains($rs['govde'], 'Borçlu / senet no') && str_contains($rs['govde'], '"etiket_senet":"Protesto"') && str_contains($rs['govde'], 'data-sinif="senet"') && uyari_yok($rs));
ok('senet satırı: borçlu + senet no + kefil; tutar 15.000,00 ₺', str_contains($rs['govde'], 'S-001') && str_contains($rs['govde'], 'Kefil: ÖRNEK KEFİL') && str_contains($rs['govde'], '15.000,00 ₺'));

echo "\n=== 6. Detay (?parca=detay) ===\n";
$d = get('cek_dekontu.php', ['parca' => 'detay', 'id' => '3'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('kısmi yanıt: header/footer YOK, #csDetay, Garanti BBVA, portföy no, Tahsilde rozeti', $d['kod'] === 200 && !str_contains($d['govde'], '<html') && !str_contains($d['govde'], 'desktop-sidebar') && str_contains($d['govde'], 'id="csDetay"') && str_contains($d['govde'], 'Garanti BBVA') && str_contains($d['govde'], 'ÇA-' . date('Y') . '-000003') && str_contains($d['govde'], 'cs-d-tahsilde'));
ok('tahsilde: izinli işlem düğmeleri = portfoye_al + tahsil_banka + karsiliksiz (ceksenet_izinli_islemler); ciro / tahsil_kasa YOK', str_contains($d['govde'], 'data-cs-islem="portfoye_al"') && str_contains($d['govde'], 'data-cs-islem="tahsil_banka"') && str_contains($d['govde'], 'data-cs-islem="karsiliksiz"') && !str_contains($d['govde'], 'data-cs-islem="ciro"') && !str_contains($d['govde'], 'data-cs-islem="tahsil_kasa"'));
ok('hareket geçmişi: 2 hareket (Giriş + Tahsile Ver), SON hareket için iptal düğmesi (data-cs-iptal-ac data-hareket) TEK', substr_count($d['govde'], '<li class="cs-zc') === 2 && substr_count($d['govde'], 'data-cs-iptal-ac') === 1 && str_contains($d['govde'], 'Tahsile Ver') && str_contains($d['govde'], 'Örnek Banka TL'));
ok('bağlı cari belge no + bordro bağlantısı (cek_cikti.php?bordro=BRD-)', (bool)preg_match('/Cari CBD|Cari [A-Z]{2,4}-\d{4}-\d+/', $d['govde']) && str_contains($d['govde'], 'cek_cikti.php?bordro=BRD-'));
$d1 = get('cek_dekontu.php', ['parca' => 'detay', 'id' => '1'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('portföy: ciro · tahsile_ver · teminata_ver · tahsil_kasa · karsiliksiz · iade düğmeleri (çek etiketleri)', !array_diff(['ciro', 'tahsile_ver', 'teminata_ver', 'tahsil_kasa', 'karsiliksiz', 'iade'], array_map(fn($a) => str_contains($d1['govde'], 'data-cs-islem="' . $a . '"') ? $a : '', ['ciro', 'tahsile_ver', 'teminata_ver', 'tahsil_kasa', 'karsiliksiz', 'iade'])) && str_contains($d1['govde'], '>Karşılıksız<') && str_contains($d1['govde'], 'cs-vade--gecmis'));
$ds = get('senet_dekontu.php', ['parca' => 'detay', 'id' => '6'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('senet detayı: düğme etiketi "Protesto" (karşılıksız yerine); Kefil + Ödeme yeri alanları', str_contains($ds['govde'], '>Protesto<') && !str_contains($ds['govde'], '>Karşılıksız<') && str_contains($ds['govde'], 'Kefil') && str_contains($ds['govde'], 'Ödeme yeri'));
ok('olmayan evrak → 404; senet id çek sayfasında → 404 (sınıf karışmaz)', get('cek_dekontu.php', ['parca' => 'detay', 'id' => '999'])['kod'] === 404 && get('cek_dekontu.php', ['parca' => 'detay', 'id' => '6'])['kod'] === 404);
ok('bilinmeyen parça → 400', get('cek_dekontu.php', ['parca' => 'x'])['kod'] === 400);
$fd = get('cek_dekontu.php', ['id' => '3']);
ok('?id=3 tam sayfa: panel AÇIK (cs-panel--acik) + detay sunucuda basılı', str_contains($fd['govde'], 'cs-panel--acik') && str_contains($fd['govde'], 'id="csDetay"') && str_contains($fd['govde'], 'Garanti BBVA') && !html_hata($fd['govde']));
ok('?id=999 → uyarı bandı, panel boş (200)', ($fx = get('cek_dekontu.php', ['id' => '999'])) && $fx['kod'] === 200 && str_contains($fx['govde'], 'Evrak bulunamadı') && !str_contains($fx['govde'], 'cs-panel--acik'));
$co = get('cek_dekontu.php', ['parca' => 'cari_ozet', 'cari' => (string)$CARI['ÖRNEK TİCARET A.Ş.']], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('cari_ozet: açık çek-senet satırları (Çek · Alınan / Senet · Alınan) + vadesi geçmiş adedi; kısmi yanıt', $co['kod'] === 200 && str_contains($co['govde'], 'cs-cari-ozet') && str_contains($co['govde'], 'Çek · Alınan') && str_contains($co['govde'], 'Senet · Alınan') && str_contains($co['govde'], '1 vadesi geçmiş') && !str_contains($co['govde'], '<html'));
ok('cari_ozet: açık evrakı olmayan cari → "açık çek / senedi yok"; olmayan cari 404', str_contains(get('cek_dekontu.php', ['parca' => 'cari_ozet', 'cari' => (string)$CARI['ÖRNEK NAKLİYAT LTD.']])['govde'], 'açık çek / senedi yok') && get('cek_dekontu.php', ['parca' => 'cari_ozet', 'cari' => '999'])['kod'] === 404);

echo "\n=== 7. İşlem modalı (ceksenet_islemler() yapısından) ===\n";
$m = $h;
ok('#csModalIslem + #csIslemForm: islem=islem_uygula, gecis, istek_anahtari, eksiye_izin, csrf; alanlar cari / kasa / tarih / açıklama', str_contains($m, 'id="csModalIslem"') && str_contains($m, 'name="islem" value="islem_uygula"') && str_contains($m, 'name="gecis"') && str_contains($m, 'name="istek_anahtari"') && str_contains($m, 'name="eksiye_izin"') && str_contains($m, 'data-alan="cari"') && str_contains($m, 'data-alan="kasa"'));
ok('yetersiz bakiye kutusu #csEksik + "Yine de kaydet" + "Düzelt"; geçersiz geçiş listesi [data-gecersiz]', str_contains($m, 'id="csEksik"') && str_contains($m, 'data-eksik-kaydet') && str_contains($m, 'data-eksik-duzelt') && str_contains($m, 'data-gecersiz'));
preg_match('#<script type="application/json" id="csVeri">(.*?)</script>#s', $m, $jm);
$veri = json_decode($jm[1] ?? '', true);
$beklenen = array_keys(array_filter(ceksenet_islemler(), fn($t) => $t['onceki']));
ok('csVeri.islemler anahtarları = ceksenet_islemler() (oluşturma hariç) — durum makinesi elle çoğaltılmaz', is_array($veri) && array_keys($veri['islemler']) === $beklenen, json_encode(array_keys($veri['islemler'] ?? [])));
ok('csVeri.islemler.ciro: cari_zorunlu; tahsile_ver: kasa_sec tip banka zorunlu; odendi: kasa zorunlu (tip yok); etki metni çekirdekten', $veri['islemler']['ciro']['cari_zorunlu'] === true && $veri['islemler']['tahsile_ver']['kasa_sec'] === ['zorunlu' => true, 'tip' => 'banka'] && $veri['islemler']['odendi']['kasa_sec']['tip'] === null && $veri['islemler']['ciro']['etki'] === ceksenet_islemler()['ciro']['etki']);
ok('csVeri: aktif cariler + kasalar (id, ad, pb, tip) + karneKasa; kasa seçeneklerinde data-pb / data-tip', count($veri['cariler']) === 3 && count($veri['kasalar']) === 4 && str_contains($m, 'data-pb="TRY" data-tip="banka"') && str_contains($m, 'data-pb="USD" data-tip="nakit"') && isset($veri['karneKasa']));
ok('iptal modalı: hareket_iptal, neden zorunlu (minlength 3), id gizli alanı (HAREKET id)', str_contains($m, 'id="csModalIptal"') && str_contains($m, 'name="islem" value="hareket_iptal"') && str_contains($m, 'minlength="3"'));
ok('işlem modalı z-index: .pm-overlay (style.css ≥600) kullanır; kendi z-index tanımı yok', str_contains($m, 'class="pm-overlay" id="csModalIslem"'));

echo "\n=== 8. Bordro formu ===\n";
$f = get('cek_dekontu.php', ['yeni' => '1', 'yon' => 'alinan']);
$g = $f['govde'];
ok('200 + PHP uyarısı yok + HTML dengesi + csrf', $f['kod'] === 200 && uyari_yok($f) && !html_hata($g) && !form_hata($g), json_encode(html_hata($g)));
ok('form: islem=bordro_kaydet, sinif=cek, yon=alinan, istek_anahtari (24 hex), geri; cari + tarih + açıklama', str_contains($g, 'name="islem" value="bordro_kaydet"') && str_contains($g, 'name="sinif" value="cek"') && str_contains($g, 'name="yon" value="alinan"') && (bool)preg_match('/name="istek_anahtari" value="[0-9a-f]{24}"/', $g) && str_contains($g, 'name="cari_ara"') && str_contains($g, 'name="tarih"') && str_contains($g, 'name="aciklama"'));
$alanlar = ['seri_no', 'banka_ad', 'sube', 'hesap_no', 'borclu', 'kefil', 'yer_adi', 'duzenleme_tarihi', 'vade', 'tutar', 'para_birimi', 'karne_id'];
preg_match('#<tbody class="cs-fsatirlar">(.*?)</tbody>#s', $g, $tb);
ok('ilk satırda 12 paralel dizi alanının HEPSİ tam 1 kez (görünmeyenler gizli girdi) — dizi hizası', !array_filter($alanlar, fn($a) => substr_count($tb[1] ?? '', 'name="' . $a . '[]"') !== 1));
preg_match('#<template id="csSatirSablon">(.*?)</template>#s', $g, $tp);
ok('satır şablonu (<template>) aynı 12 alan; boş değerli (seri_no / banka_ad önceden DOLDURULMAZ)', !array_filter($alanlar, fn($a) => substr_count($tp[1] ?? '', 'name="' . $a . '[]"') !== 1) && !preg_match('/name="(seri_no|banka_ad)\[\]"[^>]*value="[^"]+"/', $tp[1] ?? ''));
ok('çek alınan: görünen sütunlar Çek no / Banka / Şube / Hesap / Keşideci / Keşide yeri / Vade / Tutar / PB; banka datalist (10 tohum banka)', str_contains($g, '>Keşideci<') && str_contains($g, '>Keşide yeri<') && str_contains($g, 'list="csBankaListe"') && substr_count($g, '<datalist id="csBankaListe">') === 1 && str_contains($g, 'Ziraat Bankası') && str_contains($g, 'Garanti BBVA'));
ok('canlı özet alanları: adet / toplam / ortalama vade ([data-top]); Kaydet + Vazgeç; ipucu "en çok 30 satır"', str_contains($g, 'data-top="adet"') && str_contains($g, 'data-top-pb') && str_contains($g, 'data-top-ort') && str_contains($g, 'en çok 30 satır') && str_contains($g, 'data-kaydet') && str_contains($g, 'cs-vazgec'));
$fv = get('cek_dekontu.php', ['yeni' => '1', 'yon' => 'verilen'])['govde'];
preg_match('#<script type="application/json" id="csFormVeri">(.*?)</script>#s', $fv, $fj); $fvd = json_decode($fj[1] ?? '', true);
ok('verilen çek: karne seçimi (data-cs-karne) + "Karne A" + karne verisi (siradaki 1000002, kalan 24, pb TRY, banka)', str_contains($fv, 'data-cs-karne') && str_contains($fv, 'Karne A') && ($fvd['karneler'][0]['siradaki'] ?? '') === '1000002' && ($fvd['karneler'][0]['kalan'] ?? -1) === 24 && ($fvd['karneler'][0]['pb'] ?? '') === 'TRY' && ($fvd['karneler'][0]['banka'] ?? '') === 'Örnek Banka' && str_contains($fv, 'Yeni çıkış bordrosu'));
preg_match('#<tbody class="cs-fsatirlar">(.*?)</tbody>#s', $fv, $tbv);
ok('verilen çek satırı: 12 alan hizalı; keşideci / şube / hesap gizli; satır başlığı Karne', !array_filter($alanlar, fn($a) => substr_count($tbv[1] ?? '', 'name="' . $a . '[]"') !== 1) && str_contains($fv, '>Karne<') && !str_contains($fv, '>Keşideci<'));
$fsn = get('senet_dekontu.php', ['yeni' => '1', 'yon' => 'alinan'])['govde'];
preg_match('#<tbody class="cs-fsatirlar">(.*?)</tbody>#s', $fsn, $tbs);
ok('senet formu: Borçlu / Kefil / Ödeme yeri / Düzenleme sütunları; banka girdisi YOK (gizli taşınır); 12 alan hizalı', str_contains($fsn, '>Kefil<') && str_contains($fsn, '>Ödeme yeri<') && str_contains($fsn, '>Düzenleme<') && !str_contains($fsn, 'list="csBankaListe"') && !array_filter($alanlar, fn($a) => substr_count($tbs[1] ?? '', 'name="' . $a . '[]"') !== 1) && str_contains($fsn, 'Senet Dekontu') || str_contains($fsn, 'Yeni giriş bordrosu — Senet'));
ok('?yeni=1 + yon geçersiz → alınan; kasasız / cari yok durumda da form açılır (200)', ($fy = get('cek_dekontu.php', ['yeni' => '1', 'yon' => 'zzz'])) && str_contains($fy['govde'], 'name="yon" value="alinan"') && $fy['kod'] === 200);
$fp = get('cek_dekontu.php', ['yeni' => '1', 'yon' => 'alinan'], ['perms' => ['records.read']]);
ok('form tam sayfa: sayfa kabuğu (sidebar) + ceksenet.js yüklü', str_contains($g, 'desktop-sidebar') && str_contains($g, 'assets/ceksenet.js'));

echo "\n=== 9. Tanımlar ===\n";
$t = get('cek_tanimlari.php');
ok('200 + uyarı yok + HTML dengesi + csrf; sekmeler Bankalar (10) / Çek Karneleri (1)', $t['kod'] === 200 && uyari_yok($t) && !html_hata($t['govde']) && !form_hata($t['govde']) && str_contains($t['govde'], 'Bankalar') && str_contains($t['govde'], '<small>10</small>') && str_contains($t['govde'], '<small>1</small>'), json_encode(html_hata($t['govde'])));
ok('banka listesi: tohum bankalar (Ziraat Bankası … TEB); Karne paneli gizli (hidden)', str_contains($t['govde'], 'Ziraat Bankası') && str_contains($t['govde'], 'TEB') && (bool)preg_match('#id="csPanelKarne" role="tabpanel" data-tur="karne" hidden#', $t['govde']));
ok('karne paneli: Karne A · Örnek Banka TL · No 1000001 · 25 yaprak · Kullanılan 1 · Kalan 24 · Sıradaki 1000002; kullanılan karne SİLİNEMEZ (sil formu yok)', ($tk = get('cek_tanimlari.php', ['sekme' => 'karne'])) && str_contains($tk['govde'], 'Karne A') && str_contains($tk['govde'], 'Örnek Banka TL') && str_contains($tk['govde'], 'Kullanılan 1 · Kalan 24') && str_contains($tk['govde'], 'Sıradaki: 1000002'));
preg_match('#id="csPanelKarne".*?</section>#s', $tk['govde'], $pk);
ok('karne satırında tanim_sil formu YOK (kullanımda); tanim_durum anahtarı + Düzenle var', !str_contains($pk[0] ?? '', 'value="tanim_sil"') && str_contains($pk[0] ?? '', 'value="tanim_durum"') && str_contains($pk[0] ?? '', 'data-cs-tanim-ac="karne"'));
preg_match('#id="csPanelBanka".*?</section>#s', $t['govde'], $pb);
ok('kullanılmayan banka: sil formu VAR (tanim_sil + onay); kullanılan banka (Ziraat — kayıtta) silinemez', substr_count($pb[0] ?? '', 'value="tanim_sil"') === 5);
ok('tanım modalı #csModalTanim: tanim_kaydet, tür alanları (şube | banka hesabı + ilk no + yaprak), banka-tipi kasa seçenekleri', str_contains($t['govde'], 'id="csModalTanim"') && str_contains($t['govde'], 'name="islem" value="tanim_kaydet"') && str_contains($t['govde'], 'name="ilk_no"') && str_contains($t['govde'], 'name="yaprak"') && str_contains($t['govde'], 'Örnek Banka TL · TRY') && !str_contains(substr($t['govde'], (int)strpos($t['govde'], 'id="csModalTanim"')), 'Merkez Kasa · TRY'));
ok('geçersiz sekme → banka', str_contains(get('cek_tanimlari.php', ['sekme' => 'zz'])['govde'], 'id="csPanelBanka" role="tabpanel" data-tur="banka">'));

echo "\n=== 10. Boş durumlar ===\n";
$B1 = sys_get_temp_dir() . '/ceksenet_ui_bos1_' . getmypid() . '.sqlite'; $B2 = sys_get_temp_dir() . '/ceksenet_ui_bos2_' . getmypid() . '.sqlite';
register_shutdown_function(static function () use ($B1, $B2): void { @unlink($B1); @unlink($B2); });
exec('php ' . escapeshellarg(__DIR__ . '/ceksenet_ui_e2e_setup.php') . ' ' . escapeshellarg($B1) . ' ornek >/dev/null');
exec('php ' . escapeshellarg(__DIR__ . '/ceksenet_ui_e2e_setup.php') . ' ' . escapeshellarg($B2) . ' kasasiz >/dev/null');
$e = get('cek_dekontu.php', [], ['db' => $B1]);
ok('kayıt yok: "Henüz çek kaydı yok" + Yeni giriş bordrosu; tablo yok; kasa uyarısı yok (banka hesabı var)', str_contains($e['govde'], 'Henüz çek kaydı yok') && str_contains($e['govde'], 'cs-bos') && !str_contains($e['govde'], 'cs-kasa-not') && satir_say($e['govde']) === 0 && uyari_yok($e) && !html_hata($e['govde']));
ok('kayıt yok: özet kartları "—" ve 0 adet (hata yok)', str_contains($e['govde'], 'cs-ozet-yok') && str_contains($e['govde'], '0 adet'));
$k = get('cek_dekontu.php', [], ['db' => $B2]);
ok('kasa yok → yönlendirme: "Kasa Tanımları\'ndan banka hesabı ekleyin" (kasa_tanimlari.php?sekme=kasa&yeni=1)', str_contains($k['govde'], 'cs-kasa-not') && str_contains($k['govde'], 'kasa_tanimlari.php?sekme=kasa&amp;yeni=1') && str_contains($k['govde'], 'banka hesabı ekleyin'));
$kt = get('cek_tanimlari.php', ['sekme' => 'karne'], ['db' => $B2]);
ok('banka hesabı yokken karne sekmesi: "Önce Kasa Tanımları\'ndan banka hesabı ekleyin" bağlantısı; "Yeni karne" düğmesi devre dışı', str_contains($kt['govde'], 'Önce Kasa Tanımları') && str_contains($kt['govde'], 'kasa_tanimlari.php?sekme=kasa&amp;yeni=1') && (bool)preg_match('/data-cs-tanim-ac="karne"[^>]*disabled/', $kt['govde']));
$kf = get('cek_dekontu.php', ['yeni' => '1'], ['db' => $B2]);
ok('kasasız + cari var: bordro formu yine açılır (giriş bordrosu kasa istemez)', $kf['kod'] === 200 && str_contains($kf['govde'], 'id="csBordroForm"'));

echo "\n=== 12. cek_cikti: bordro yazdır + dışa aktarım ===\n";
$bc = get('cek_cikti.php', ['bordro' => $BRD]);
ok('bordro yazdır: 200, A4 (cs-yz), bordro no, 4 satır, toplam + ortalama vade, firma başlığı, imza, ÇEK GİRİŞ BORDROSU', $bc['kod'] === 200 && uyari_yok($bc) && str_contains($bc['govde'], 'cs-yz') && str_contains($bc['govde'], $BRD) && substr_count($bc['govde'], 'ÇA-' . date('Y') . '-00000') === 4 && str_contains($bc['govde'], 'Toplam TRY') && str_contains($bc['govde'], 'Ortalama vade TRY') && str_contains($bc['govde'], 'ÇEK GİRİŞ BORDROSU') && str_contains($bc['govde'], 'Teslim eden') && str_contains($bc['govde'], 'ceksenet.css'), substr($bc['govde'], 0, 300));
ok('bordro toplamı: 55.750,50 ₺ (12.500 + 8.250,50 + 30.000 + 5.000) — tek para birimi', str_contains($bc['govde'], '55.750,50 ₺'));
$sunucuOrt = ceksenet_ortalama_vade(array_map(fn($k) => ['vade' => $k['vade'], 'tutar' => $k['tutar'], 'para_birimi' => $k['para_birimi']], $db->query("SELECT vade, tutar, para_birimi FROM ceksenet_kayitlar WHERE bordro_no = '$BRD'")->fetchAll()), date('Y-m-d'));
ok('yazdırılan ortalama vade = ceksenet_ortalama_vade (gün + tarih)', str_contains($bc['govde'], $sunucuOrt['TRY']['gun'] . ' gün · ' . date('d.m.Y', strtotime($sunucuOrt['TRY']['tarih']))), json_encode($sunucuOrt));
ok('biçim dışı / olmayan bordro → 404', get('cek_cikti.php', ['bordro' => 'x'])['kod'] === 404 && get('cek_cikti.php', ['bordro' => 'BRD-2026-999999'])['kod'] === 404 && get('cek_cikti.php')['kod'] === 404);
$ao = say('audit_log', "module = 'ceksenet' AND action = 'export'");
$c = get('cek_cikti.php', ['sinif' => 'cek', 'bicim' => 'csv', 'durum' => 'tumu']);
$sat = array_values(array_filter(explode("\n", $c['govde'])));
ok('CSV: BOM + ; ayraç + başlık (Portföy No;…) + 5 evrak satırı + TOPLAM TRY / TOPLAM USD AYRI satırlar; tutar binlik ayraçsız 12500,00', $c['kod'] === 200 && str_starts_with($c['govde'], "\xEF\xBB\xBF") && (bool)preg_match('/Portföy No"?;/', $sat[0]) && count($sat) === 1 + 5 + 2 && str_contains($c['govde'], 'TOPLAM TRY') && str_contains($c['govde'], 'TOPLAM USD') && str_contains($c['govde'], '12500,00') && !str_contains($c['govde'], '12.500,00'), implode(' | ', array_slice($sat, -3)));
ok('CSV: export audit kaydı (+1, module=ceksenet, report=liste)', say('audit_log', "module = 'ceksenet' AND action = 'export'") === $ao + 1);
$x = get('cek_cikti.php', ['sinif' => 'senet', 'bicim' => 'xlsx']);
ok('xlsx: 200, ZIP imzası (PK), export audit +1', $x['kod'] === 200 && str_starts_with($x['govde'], 'PK') && say('audit_log', "module = 'ceksenet' AND action = 'export'") === $ao + 2, substr($x['govde'], 0, 40));
$n = get('cek_cikti.php', ['sinif' => 'cek', 'bicim' => 'xlsx'], ['perms' => ['records.read']]);
ok('reports.export yoksa forbidden; audit ARTMAZ', str_contains((string)$n['hata'], 'forbidden') && say('audit_log', "module = 'ceksenet' AND action = 'export'") === $ao + 2);
ok('geçersiz sinif → 400', get('cek_cikti.php', ['sinif' => 'x', 'bicim' => 'csv'])['kod'] === 400);
ok('süzgeç dışa aktarıma taşınır: yon=verilen CSV → 1 evrak satırı', count(array_values(array_filter(explode("\n", get('cek_cikti.php', ['sinif' => 'cek', 'bicim' => 'csv', 'yon' => 'verilen', 'durum' => 'tumu'])['govde'])))) === 1 + 1 + 1);

echo "\n=== 13. XSS ===\n";
$xs = '<img src=x onerror=alert(1)>';
$r = ceksenet_bordro_kaydet($db, ['sinif' => 'cek', 'yon' => 'alinan', 'cari_id' => (string)$CARI['ÖRNEK TİCARET A.Ş.'], 'tarih' => date('Y-m-d'), 'aciklama' => $xs] + ['seri_no' => ['XSS1'], 'banka_ad' => ['Ban"ka'], 'sube' => [''], 'hesap_no' => [''], 'borclu' => [$xs], 'kefil' => [''], 'yer_adi' => [''], 'duzenleme_tarihi' => [''], 'vade' => [date('Y-m-d', strtotime('+3 days'))], 'tutar' => ['1,00'], 'para_birimi' => ['TRY'], 'karne_id' => ['']], 1);
$xid = (int)($r['id'] ?? 0);
$lx = get('cek_dekontu.php', ['ara' => 'XSS1'])['govde'];
$dx = get('cek_dekontu.php', ['parca' => 'detay', 'id' => (string)$xid])['govde'];
ok('liste + detay: keşideci / açıklama kaçışlı (ham <img YOK, &lt;img var)', $xid > 0 && !str_contains($lx, '<img src=x') && !str_contains($dx, '<img src=x') && str_contains($lx, '&lt;img') && str_contains($dx, '&lt;img'), (string)$xid);
ok('arama kutusu değeri kaçışlı (ara="><script>)', !str_contains(get('cek_dekontu.php', ['ara' => '"><script>alert(1)</script>'])['govde'], '"><script>alert(1)'));
ok('csVeri JSON içinde </script> kaçışı (JSON_HEX_TAG)', !preg_match('#id="csVeri">[^<]*</script>[^<]*<img#', $lx));


echo "\n=== 14. v528 denetim düzeltmeleri ===\n";
$TIC = $CARI['ÖRNEK TİCARET A.Ş.'];
$senetSatir = static function (string $no, string $tutar = '10,00', string $borclu = 'Sayfa Borçlu'): array { return ['seri_no' => $no, 'borclu' => $borclu, 'vade' => date('Y-m-d', strtotime('+40 days')), 'tutar' => $tutar, 'para_birimi' => 'TRY']; };
$topla = static function (array $satirlar): array { $g = ['seri_no' => [], 'banka_ad' => [], 'sube' => [], 'hesap_no' => [], 'borclu' => [], 'kefil' => [], 'yer_adi' => [], 'duzenleme_tarihi' => [], 'vade' => [], 'tutar' => [], 'para_birimi' => [], 'karne_id' => []]; foreach ($satirlar as $x) foreach ($g as $k => $_) $g[$k][] = (string)($x[$k] ?? ''); return $g; };
// 105 senet (4 bordro) → 107 açık senet = 2 sayfa
$sayfaIdler = [];
for ($b = 0; $b < 4; $b++) {
    $sat = []; for ($i = 0; $i < ($b < 3 ? 30 : 15); $i++) $sat[] = $senetSatir('SY-' . $b . '-' . $i);
    $rr = ceksenet_bordro_kaydet($db, ['sinif' => 'senet', 'yon' => 'alinan', 'cari_id' => (string)$TIC, 'tarih' => date('Y-m-d')] + $topla($sat), 1);
    if (empty($rr['ok'])) { ok('sayfa testi kurulumu', false, json_encode($rr, JSON_UNESCAPED_UNICODE)); break; }
    array_push($sayfaIdler, ...$rr['idler']);
}
$sp = get('senet_dekontu.php', ['sayfa' => '9999']); $hp = $sp['govde'];
ok('?sayfa=9999 (son sayfadan büyük): "Henüz senet kaydı yok" YOK; son sayfa (2 / 2) 7 satır + önceki bağlantısı; 7 / 107 kayıt', !str_contains($hp, 'Henüz senet kaydı yok') && str_contains($hp, '<span>2 / 2</span>') && satir_say($hp) === 7 && str_contains($hp, '7 / 107 kayıt') && uyari_yok($sp), (string)satir_say($hp));
ok('?sayfa=2 ile aynı; ?sayfa=0 → 1. sayfa (100 satır)', satir_say(get('senet_dekontu.php', ['sayfa' => '2'])['govde']) === 7 && satir_say(get('senet_dekontu.php', ['sayfa' => '0'])['govde']) === 100);
ok('çek listesinde (tek sayfalık) ?sayfa=50: liste yine görünür (yanlış boş durum yok)', ($cp = get('cek_dekontu.php', ['sayfa' => '50'])) && satir_say($cp['govde']) >= 4 && !str_contains($cp['govde'], 'Henüz çek kaydı yok'));
// iptal süzgeci özeti
$ihid = (int)$db->query('SELECT id FROM ceksenet_hareketler WHERE kayit_id = ' . (int)$sayfaIdler[0] . " AND islem = 'giris'")->fetchColumn();
$ip = ceksenet_hareket_iptal($db, $ihid, 'Özet testi iptali', 1);
$li = get('senet_dekontu.php', ['durum' => 'iptal'])['govde'];
ok('durum=iptal: liste özeti iptal edilenlerin toplamı (10,00 ₺, 1 kayıt) + "yalnız iptal edilen kayıtlara aittir" notu', $ip['ok'] && satir_say($li) === 1 && preg_match('#id="csListeOzet".*?10,00 ₺.*?yalnız iptal edilen kayıtlara aittir#s', $li) === 1 && !str_contains($li, 'iptal edilenler toplama girmez'), '');
$lt = get('senet_dekontu.php', ['durum' => 'acik'])['govde'];
ok('durum=acik (varsayılan): özet iptal HARİÇ + "iptal edilenler toplama girmez" notu', str_contains($lt, 'iptal edilenler toplama girmez') && !str_contains($lt, 'yalnız iptal edilen kayıtlara aittir'));
// işlem bordrosu başlığı + cari etiketi
$cb = (string)$db->query("SELECT bordro_no FROM ceksenet_hareketler WHERE islem = 'ciro' LIMIT 1")->fetchColumn();
$tb = (string)$db->query("SELECT bordro_no FROM ceksenet_hareketler WHERE islem = 'tahsile_ver' LIMIT 1")->fetchColumn();
$yb = get('cek_cikti.php', ['bordro' => $cb])['govde']; $zb = get('cek_cikti.php', ['bordro' => $tb])['govde'];
ok('ciro bordrosu: başlık "ÇEK CİRO ET BORDROSU" (GİRİŞ DEĞİL), cari satırı "Ciro edilen cari" + hedef cari', str_contains($yb, 'ÇEK CİRO ET BORDROSU') && !str_contains($yb, 'GİRİŞ BORDROSU') && str_contains($yb, '<th>Ciro edilen cari</th><td>ÖRNEK NAKLİYAT LTD.</td>'), substr(strip_tags($yb), 0, 200));
ok('tahsile verme bordrosu: başlık "ÇEK TAHSİLE VER BORDROSU"; carisi olmayan işlemde cari satırı BASILMAZ', str_contains($zb, 'ÇEK TAHSİLE VER BORDROSU') && !str_contains($zb, '<th>Cari</th>') && !str_contains($zb, 'Ciro edilen cari'), substr(strip_tags($zb), 0, 200));
ok('giriş bordrosu hâlâ "ÇEK GİRİŞ BORDROSU" + <th>Cari</th>; verilen çıkış bordrosu "ÇEK ÇIKIŞ BORDROSU"', str_contains(get('cek_cikti.php', ['bordro' => $BRD])['govde'], 'ÇEK GİRİŞ BORDROSU') && str_contains(get('cek_cikti.php', ['bordro' => $BRD])['govde'], '<th>Cari</th>')
    && str_contains(get('cek_cikti.php', ['bordro' => (string)$db->query("SELECT bordro_no FROM ceksenet_hareketler WHERE islem = 'cikis' LIMIT 1")->fetchColumn()])['govde'], 'ÇEK ÇIKIŞ BORDROSU'));
// detay zaman çizgisinde her hareketin bordro bağlantısı (yeni sekme)
$ciroKayit = (int)$db->query("SELECT kayit_id FROM ceksenet_hareketler WHERE islem = 'ciro' LIMIT 1")->fetchColumn();
$dz = get('cek_dekontu.php', ['parca' => 'detay', 'id' => (string)$ciroKayit], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']])['govde'];
ok('detay zaman çizgisi: giriş + ciro hareketlerinin bordro no\'su cek_cikti.php?bordro= bağlantısı (target=_blank, rel=noopener)', substr_count($dz, 'data-bordro-bag') === 2 && str_contains($dz, 'cek_cikti.php?bordro=' . rawurlencode($cb) . '" target="_blank" rel="noopener"'), (string)substr_count($dz, 'data-bordro-bag'));
// önceki durum etiketi kaçışlı (h) — zaman çizgisi
ok('zaman çizgisi: önceki durum etiketi h() ile (kaynakta ham dinamik çıktı yok)', preg_match('/\$h\[\'onceki_durum_etiket\'\] \./', file_get_contents(__DIR__ . '/../config/ceksenet_ui.php')) === 0);
// CSV formül enjeksiyonu
$cs = ceksenet_bordro_kaydet($db, ['sinif' => 'cek', 'yon' => 'alinan', 'cari_id' => (string)$TIC, 'tarih' => date('Y-m-d')] + $topla([['seri_no' => '=1+1', 'banka_ad' => '@banka', 'hesap_no' => '-hesap', 'borclu' => '+borclu', 'vade' => date('Y-m-d', strtotime('+3 days')), 'tutar' => '10,00', 'para_birimi' => 'TRY']]), 1);
$cc = get('cek_cikti.php', ['sinif' => 'cek', 'bicim' => 'csv', 'durum' => 'tumu'])['govde'];
ok("CSV: metin hücreleri (seri '=1+1', banka '@banka', hesap '-hesap', keşideci '+borclu') başına ' alır; ham =1+1 hücresi YOK", !empty($cs['ok']) && str_contains($cc, "'=1+1") && str_contains($cc, "'@banka") && str_contains($cc, "'-hesap") && str_contains($cc, "'+borclu") && !preg_match('/(^|;)"?=1\+1/m', $cc), substr($cc, 0, 400));
ok("CSV: tutar (10,00) ve tarih hücreleri ' ALMAZ; vadeye gün (negatif olabilir) sayısal", str_contains($cc, ';10,00;') && !str_contains($cc, "'10,00") && preg_match('/;\d{2}\.\d{2}\.\d{4};-?\d+;TRY;/', $cc) === 1 && !preg_match("/;'-?\d+;/", $cc), substr($cc, 0, 300));
$ci = get('cek_cikti.php', ['sinif' => 'senet', 'bicim' => 'csv', 'durum' => 'iptal'])['govde'];
ok('CSV durum=iptal: toplam satırı "(yalnız iptal edilenler N)" (iptal hariç DEĞİL)', str_contains($ci, 'yalnız iptal edilenler 1') && !str_contains($ci, 'iptal hariç'), $ci);
ok('CSV durum=tumu: toplam satırı "(iptal hariç N)"', str_contains($cc, '(iptal hariç '));
// bordro formu: tutar önizleme alanı + belirsiz tutar sunucu reddi
$fx = get('cek_dekontu.php', ['yeni' => '1', 'yon' => 'alinan'])['govde'];
ok('bordro formu: her tutar girdisinin altında [data-tutar-onizleme] (PC satırı + mobil kart AYNI DOM; şablonda da)', substr_count($fx, 'data-tutar-onizleme') >= 2 && (bool)preg_match('#<template id="csSatirSablon">.*data-tutar-onizleme.*</template>#s', $fx));


siparis_test_bitir();
