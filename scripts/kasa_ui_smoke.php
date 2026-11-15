<?php
// =========================================================
// scripts/kasa_ui_smoke.php — Kasa sayfaları/uçları: GERÇEK HTML/JSON üzerinde PHP düzeyi testler (Aşama 2: ön yüz)
//   php scripts/kasa_ui_smoke.php        → çıkış kodu 0 = hepsi geçti
// Her istek ALT SÜREÇTE (scripts/_kasa_istek.php) gerçek exit/header ile çalışır; DB geçici dosya SQLite + uydurma veri. Canlı DB'ye dokunmaz.
// Kapsam: şema yok (cari + kasa eksik) · yönetici değil · hiç kasa yok · dolu liste (yürüyen bakiye, süzgeç, iptal, virman, depo) · detay + kısmi yanıt ·
//         formlar/modallar · tanımlar · XSS · HTML denge + yinelenen id · kasa_cikti yazdır + xlsx/csv yetkisi + audit · kasa_kaydet JSON/PRG.
// =========================================================
ini_set('error_log', '/dev/null');
if (PHP_SAPI !== 'cli') exit(1);
$DB = sys_get_temp_dir() . '/kasa_ui_smoke_' . getmypid() . '.sqlite';
exec('php ' . escapeshellarg(__DIR__ . '/kasa_ui_e2e_setup.php') . ' ' . escapeshellarg($DB) . ' fisler', $cikti, $kod);
if ($kod !== 0) { fwrite(STDERR, "kurulum başarısız:\n" . implode("\n", $cikti) . "\n"); exit(1); }
putenv('KASA_E2E_DB=' . $DB);
require_once __DIR__ . '/_kasa_test_lib.php';
require_once $ROOT . '/config/siparis_invoice.php';
register_shutdown_function(static function () use ($DB): void { @unlink($DB); });
$db = db();
$IS_ADMIN = true; $UID = 1;
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function get(string $dosya, array $get = [], array $ek = []): array { return kasa_t_istek_cagri(['dosya' => $dosya, 'get' => $get] + $ek + ['depolar' => ['Merkez Depo']]); }
function post(array $p, bool $json = true, array $ek = []): array { return kasa_t_istek_cagri(['dosya' => 'kasa_kaydet.php', 'post' => $p + ['csrf' => 'test-csrf-belirteci'], 'server' => $json ? ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ACCEPT' => 'application/json'] : []] + $ek + ['depolar' => ['Merkez Depo']]); }
function js(array $r): array { $j = json_decode(trim($r['govde']), true); return is_array($j) ? $j : ['_cozulemedi' => $r['govde']]; }
function uyari_yok(array $r): bool { return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b/', $r['govde']) && $r['hata'] === null; }
function bolge(string $h): string { $h = preg_replace('#<template\b[^>]*>.*?</template>#s', '', $h) ?? $h; $a = strpos($h, '<div class="kasa'); if ($a === false) return $h; $b = strpos($h, '<script src=', $a); return $b === false ? substr($h, $a) : substr($h, $a, $b - $a); }
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
/** Her POST formunda csrf alanı. */
function form_hata(string $h): array
{
    $hata = [];
    preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $h, $fm, PREG_SET_ORDER);
    foreach ($fm as $i => $f) if (stripos($f[1], 'method="post"') !== false && !str_contains($f[2], 'name="csrf"')) $hata[] = "form#$i csrf yok";
    return $hata;
}
$K = []; foreach ($db->query('SELECT id, ad FROM kasa_kartlar ORDER BY id')->fetchAll() as $r) $K[$r['ad']] = (int)$r['id'];
$M = $K['Merkez Kasa']; $B = $K['Örnek Banka TL']; $D = $K['Döviz Kasası']; $SUBE = $K['Şube Kasası'];

echo "=== 1. Şema yok ===\n";
$bos = sys_get_temp_dir() . '/kasa_ui_sema_' . getmypid() . '.sqlite';
@unlink($bos); touch($bos);
register_shutdown_function(static function () use ($bos): void { @unlink($bos); });
foreach (['kasa_fisleri.php', 'kasa_tanimlari.php', 'kasa_cikti.php'] as $f) {
    $r = get($f, ['kasa' => '1'], ['db' => $bos]);
    ok("$f: şema uyarı kartı (data-ks-sema), cari → kasa sırası migrate bağlantıları, sekme şeridi YOK", $r['kod'] === 200 && str_contains($r['govde'], 'data-ks-sema') && str_contains($r['govde'], 'data-sema-eksik="cari"') && str_contains($r['govde'], 'data-sema-eksik="kasa"')
        && strpos($r['govde'], 'data-sema-eksik="cari"') < strpos($r['govde'], 'data-sema-eksik="kasa"') && str_contains($r['govde'], 'href="migrate.php#kasa"') && !str_contains($r['govde'], 'ks-sekmeler') && uyari_yok($r), substr($r['govde'], 0, 200) . $r['hata']);
}
$r = post(['islem' => 'kart_kaydet', 'ad' => 'Deneme'], true, ['db' => $bos]);
ok('kasa_kaydet (şema yok): 503 JSON', $r['kod'] === 503 && js($r)['ok'] === false);

echo "\n=== 2. Yönetici değil ===\n";
foreach (['kasa_fisleri.php', 'kasa_tanimlari.php', 'kasa_cikti.php'] as $f) {
    $r = get($f, ['kasa' => (string)$M], ['admin' => false]);
    ok("$f: yönetici değil → forbidden, kasa verisi sızmaz", str_contains((string)$r['hata'], 'forbidden') && !str_contains($r['govde'], 'Merkez Kasa') && !str_contains($r['govde'], 'KTH-'), (string)$r['kod']);
}
$r = post(['islem' => 'hareket_ekle', 'kasa_id' => (string)$M, 'tur' => 'gider', 'tutar' => '1', 'tarih' => date('Y-m-d')], true, ['admin' => false]);
ok('yönetici değil: kasa_kaydet 403 JSON', $r['kod'] === 403 && js($r)['ok'] === false);

echo "\n=== 3. Hiç kasa yok ===\n";
$bosK = sys_get_temp_dir() . '/kasa_ui_bos_' . getmypid() . '.sqlite';
@unlink($bosK);
exec('php ' . escapeshellarg(__DIR__ . '/kasa_ui_e2e_setup.php') . ' ' . escapeshellarg($bosK) . ' bos', $c2, $k2);
register_shutdown_function(static function () use ($bosK): void { @unlink($bosK); });
$r = get('kasa_fisleri.php', [], ['db' => $bosK]);
ok('kasa yokken fiş sayfası: "İlk kasanızı ekleyin" → kasa_tanimlari.php?sekme=kasa&yeni=1, form/modal YOK', $k2 === 0 && str_contains($r['govde'], 'Henüz kasa yok') && str_contains($r['govde'], 'href="kasa_tanimlari.php?sekme=kasa&amp;yeni=1"') && !str_contains($r['govde'], 'id="ksModalForm"') && uyari_yok($r), (string)$r['hata']);
$r = get('kasa_tanimlari.php', ['yeni' => '1'], ['db' => $bosK]);
ok('tanımlar boş: "İlk kasanızı ekleyin" + yeni kasa modalı + data-yeni=kart', str_contains($r['govde'], 'İlk kasanızı ekleyin') && str_contains($r['govde'], 'id="ksModalKart"') && str_contains($r['govde'], 'data-yeni="kart"') && str_contains($r['govde'], 'value="KS-001"'));

echo "\n=== 4. Fiş listesi (dolu) ===\n";
$r = get('kasa_fisleri.php', ['kasa' => (string)$M]); $h = bolge($r['govde']);
ok('HTTP 200, uyarı yok, kasa.css + kasa.js yüklenir', $r['kod'] === 200 && uyari_yok($r) && str_contains($r['govde'], 'assets/kasa.css?v=') && str_contains($r['govde'], 'assets/kasa.js?v='), (string)$r['hata']);
ok('HTML dengeli, yinelenen id yok, formlarda csrf', !($e = html_hata($h)) && !($e2 = form_hata($h)), implode('; ', $e ?: ($e2 ?? [])));
ok('Merkez Kasa: bakiye 81.325,50 ₺ (50.000 + 1.500 − 250 + 75,50 − 5.000 + 35.000; 10 iptalli sayılmaz)', str_contains($h, 'data-bakiye="8132550"') && str_contains($h, '81.325,50 ₺'), '');
ok('sol sütun: Tüm kasalar + 3 görünür kasa (Şube Kasası farklı depoda → görünmez)', str_contains($h, 'Merkez Kasa') && str_contains($h, 'Örnek Banka TL') && str_contains($h, 'Döviz Kasası') && !str_contains($h, 'Şube Kasası'));
ok('genel özet para birimi başına AYRI kutu (TRY ve USD; toplanmaz)', str_contains($h, 'class="ks-genel-kutu" data-pb="TRY"') && str_contains($h, 'class="ks-genel-kutu" data-pb="USD"') && str_contains($h, '1.000,00 $'));
ok('yürüyen bakiye sütunu (tek kasa + geçerli): Bakiye başlığı', str_contains($h, '>Bakiye</th>') && !str_contains($h, '>Kasa</th>'));
ok('geçerli süzgeç: iptalli hareket YOK, rozet İPTAL yok', !str_contains($h, 'Hatalı giriş') && !str_contains($h, '>İPTAL<'));
ok('tür rozetleri: Cari Tahsilat ▲, Gider ▼, Virman ⇄', str_contains($h, 'ks-rozet--giris') && str_contains($h, 'Cari Tahsilat') && str_contains($h, 'ks-rozet--cikis') && str_contains($h, 'Gider Fişi') && str_contains($h, 'ks-rozet--virman'));
ok('virman satırı "→ Örnek Banka TL" (karşı kasa)', str_contains($h, '→ Örnek Banka TL'));
ok('eylem düğmeleri 6 tür (data-ks-form) + alt çubuk + Diğer', preg_match_all('/data-ks-form="(cari_tahsilat|cari_odeme|gelir|gider|virman|acilis)"/', $h) >= 6 && str_contains($h, 'class="ks-eylem-bar"') && str_contains($h, 'data-ks-daha'));
ok('Kasa Defteri bağlantısı kasa_cikti.php?kasa=ID + XLSX/CSV (reports.export var)', str_contains($h, 'kasa_cikti.php?kasa=' . $M) && str_contains($h, 'bicim=xlsx') && str_contains($h, 'bicim=csv'));
$r2 = get('kasa_fisleri.php', ['kasa' => (string)$M], ['perms' => ['records.read']]);
ok('reports.export yoksa XLSX/CSV bağlantısı basılmaz (Kasa Defteri kalır)', !str_contains($r2['govde'], 'bicim=xlsx') && str_contains($r2['govde'], 'data-ks-defter'));
ok('modallar: form (#ksModalForm, #ksEksik, "Yine de kaydet"), iptal (#ksModalIptal), detay (#ksModalDetay); veri #ksVeri', str_contains($h, 'id="ksModalForm"') && str_contains($h, 'id="ksEksik"') && str_contains($h, 'data-eksik-kaydet') && str_contains($h, 'id="ksModalIptal"') && str_contains($h, 'id="ksModalDetay"') && str_contains($h, 'id="ksVeri"'));
ok('form: csrf + istek_anahtari + eksiye_izin=0 + geri yerel sayfa; kasa_kaydet.php hedefi', str_contains($h, 'action="kasa_kaydet.php" id="ksForm"') && str_contains($h, 'name="istek_anahtari"') && str_contains($h, 'name="eksiye_izin" value="0"') && str_contains($h, 'name="geri" value="kasa_fisleri.php"'));
$r = get('kasa_fisleri.php', ['kasa' => (string)$M, 'durum' => 'iptal']); $h = bolge($r['govde']);
ok('durum=iptal: yalnız iptalli hareket (Hatalı giriş), İPTAL rozeti, bakiye sütunu YOK', str_contains($h, 'Hatalı giriş') && str_contains($h, '>İPTAL<') && !str_contains($h, '>Bakiye</th>'));
$r = get('kasa_fisleri.php', ['durum' => 'tumu']); $h = bolge($r['govde']);
ok('tüm kasalar + tümü: Kasa sütunu, bakiye sütunu YOK, kasa başlığı "Tüm kasalar"', str_contains($h, '>Kasa</th>') && !str_contains($h, '>Bakiye</th>') && str_contains($h, 'Tüm kasalar'));
$r = get('kasa_fisleri.php', ['kasa' => (string)$M, 'tur' => 'gider']); $h = bolge($r['govde']);
ok('tür süzgeci gider: yalnız gider satırı; bakiye sütunu YOK (süzgeçli)', str_contains($h, 'Araç yakıtı') && !str_contains($h, 'Örnek tahsilat') && !str_contains($h, '>Bakiye</th>'));
$r = get('kasa_fisleri.php', ['kasa' => (string)$M, 'ara' => 'zzzyok']);
ok('arama eşleşmez → "Eşleşen hareket yok" + temizle bağlantısı', str_contains($r['govde'], 'Eşleşen hareket yok'));
$r = get('kasa_fisleri.php', ['kasa' => (string)$SUBE]);
ok('başka depodaki kasa (?kasa=) → uyarı notu, tüm kasalar gösterilir', str_contains($r['govde'], 'bulunamadı ya da aktif deponuzda') && uyari_yok($r));
$r = get('kasa_fisleri.php', ['kasa' => (string)$D]); $h = bolge($r['govde']);
ok('Döviz Kasası: bakiye 1.000,00 $; kur alanı JS ile (data-kur)', str_contains($h, '1.000,00 $') && str_contains($h, 'data-kur'));
$r = get('kasa_fisleri.php', ['kasa' => (string)$M, 'donem' => 'ozel', 'bas' => date('Y-m-d', strtotime('+1 day')), 'bit' => date('Y-m-d', strtotime('+2 days'))]);
ok('dönem=ozel: tarih aralığı alanları açık; hareket yok → boş durum yok, süzgeç mesajı', !str_contains($r['govde'], '<div class="ks-ozel-tarih" hidden>') && str_contains($r['govde'], 'Eşleşen hareket yok'));

echo "\n=== 5. Detay + kısmi yanıt ===\n";
$hr = $db->query("SELECT id FROM kasa_hareketler WHERE tur = 'cari_tahsilat' AND aciklama = 'Örnek tahsilat'")->fetchColumn();
$r = get('kasa_fisleri.php', ['kasa' => (string)$M, 'id' => (string)$hr]); $h = $r['govde'];
ok('?id= detay modalı AÇIK (hidden yok), belge no KTH-, cari bağlantısı, cari belgesi (TAH-/ALA…), tutar +1.500,00 ₺', (bool)preg_match('/<div class="pm-overlay" id="ksModalDetay">/', $h) && str_contains($h, 'KTH-' . date('Y')) && str_contains($h, 'ÖRNEK TİCARET A.Ş.') && str_contains($h, 'Cari belgesi') && str_contains($h, '+1.500,00 ₺'));
ok('detay: İptal et düğmesi görünür (data-ks-iptal-ac, hidden değil)', (bool)preg_match('/<button type="button" class="btn ks-btn-tehlike" data-ks-iptal-ac>/', $h));
$r = get('kasa_fisleri.php', ['id' => (string)$hr, 'parca' => 'detay'], ['server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('?parca=detay: kısmi yanıt (header/footer YOK), #ksDetay[data-iptal=1]', $r['kod'] === 200 && str_starts_with(trim($r['govde']), '<div class="ks-detay"') && !str_contains($r['govde'], '<html') && str_contains($r['govde'], 'data-iptal="1"') && !html_hata($r['govde']));
$r = get('kasa_fisleri.php', ['id' => '99999', 'parca' => 'detay']);
ok('?parca=detay olmayan hareket → 404', $r['kod'] === 404);
$sh = kasa_hareket_ekle($db, kasa_t_hareket($SUBE, 'acilis', '10,00'), 1);   // kurulum: başka depodaki kasaya hareket (404 senaryosu için)
$r = get('kasa_fisleri.php', ['id' => (string)$sh['id'], 'parca' => 'detay']);
ok('aktif depoda görünmeyen kasanın hareketi (?parca=detay) → 404', $r['kod'] === 404);
$vm = $db->query("SELECT id FROM kasa_hareketler WHERE tur = 'virman' AND yon = 'C'")->fetchColumn();
$r = get('kasa_fisleri.php', ['id' => (string)$vm, 'parca' => 'detay']);
ok('virman detayı: Hedef kasa satırı (Örnek Banka TL)', str_contains($r['govde'], 'Hedef kasa') && str_contains($r['govde'], 'Örnek Banka TL'));
$ip = $db->query("SELECT id FROM kasa_hareketler WHERE durum = 'iptal'")->fetchColumn();
$r = get('kasa_fisleri.php', ['id' => (string)$ip, 'parca' => 'detay']);
ok('iptalli detay: iptal şeridi + neden, data-iptal=0 (iptal düğmesi yok)', str_contains($r['govde'], 'ks-iptal-serit') && str_contains($r['govde'], 'Hatalı giriş iptali') && str_contains($r['govde'], 'data-iptal="0"'));

echo "\n=== 6. Tanımlar ===\n";
$r = get('kasa_tanimlari.php'); $h = bolge($r['govde']);
ok('HTTP 200, uyarı yok, HTML dengeli, csrf', $r['kod'] === 200 && uyari_yok($r) && !($e = html_hata($h)) && !($e2 = form_hata($h)), implode('; ', $e ?: ($e2 ?? [])));
ok('3 sekme (Kasalar / Gelir / Gider) + sayaçlar (3 görünür kasa, 2 gelir, 6 gider)', preg_match_all('/class="ks-tanim-nav-oge"/', $h) === 3 && str_contains($h, 'Kasalar <small>3</small>') && str_contains($h, 'Gelir Kalemleri <small>2</small>') && str_contains($h, 'Gider Kalemleri <small>6</small>'));
ok('kasa kartları: bakiye, düzenle (data-kart JSON hareketli=true), Sil devre dışı (hareketli), Fişler bağlantısı', str_contains($h, 'data-ks-modal="kart" data-duzenle="1" data-kart=') && str_contains($h, '&quot;hareketli&quot;:true') && str_contains($h, 'title="Hareketi olan kasa silinemez') && str_contains($h, 'kasa_fisleri.php?kasa=' . $M));
ok('kasa modalı: tip/para birimi/depo/banka (IBAN)/eksi_izin/aktif alanları; kod önerisi KS-005', str_contains($h, 'name="iban"') && str_contains($h, 'name="eksi_izin"') && str_contains($h, 'name="para_birimi"') && str_contains($h, 'name="depo"') && str_contains($h, 'data-oneri="KS-005"'));
ok('gider paneli: kullanılan kalem (Yakıt) Sil düğmesi yok, kullanılmayan (Yemek) Sil var', (bool)preg_match('/Yakıt<small>[^<]*harekette kullanıldı/', $h) && str_contains($h, 'aria-label="Yemek sil"') && !str_contains($h, 'aria-label="Yakıt sil"'));

echo "\n=== 7. kasa_kaydet (uç) — ön yüzün gönderdiği biçim ===\n";
$ik = 'ui' . bin2hex(random_bytes(6));
$post = ['islem' => 'hareket_ekle', 'kasa_id' => (string)$M, 'tur' => 'cari_tahsilat', 'tarih' => date('Y-m-d'), 'tutar' => '100,00', 'cari_id' => (string)$db->query('SELECT MIN(id) FROM cari_kartlar')->fetchColumn(), 'aciklama' => 'UI testi', 'istek_anahtari' => $ik, 'eksiye_izin' => '0', 'geri' => 'kasa_fisleri.php'];
$r = post($post); $j = js($r);
ok('hareket_ekle JSON 200: ok, belge_no KTH-, cari_belge_no, yonlendir kasa_fisleri.php?kasa=ID', $r['kod'] === 200 && $j['ok'] === true && str_starts_with((string)$j['belge_no'], 'KTH-') && !empty($j['cari_belge_no']) && $j['yonlendir'] === 'kasa_fisleri.php?kasa=' . $M, $r['govde']);
$n0 = say('kasa_hareketler');
$r = post($post); $j = js($r);
ok('aynı istek_anahtari → tekrar=true, yeni satır YOK', $r['kod'] === 200 && $j['tekrar'] === true && say('kasa_hareketler') === $n0);
$yb = ['islem' => 'hareket_ekle', 'kasa_id' => (string)$D, 'tur' => 'gider', 'tarih' => date('Y-m-d'), 'tutar' => '5.000,00', 'kalem_id' => (string)$db->query("SELECT id FROM kasa_tanimlar WHERE ad = 'Yakıt'")->fetchColumn(), 'istek_anahtari' => 'yb' . bin2hex(random_bytes(5)), 'eksiye_izin' => '0'];
$r = post($yb); $j = js($r);
ok('yetersiz bakiye → 409, kod yetersiz_bakiye, bakiye + eksik (kuruş), kayıt yok', $r['kod'] === 409 && $j['kod'] === 'yetersiz_bakiye' && (int)$j['bakiye'] === 100000 && (int)$j['eksik'] === 400000 && say('kasa_hareketler', "istek_anahtari = ?", [$yb['istek_anahtari']]) === 0, $r['govde']);
$r = post(['eksiye_izin' => '1'] + $yb); $j = js($r);
ok('aynı istek_anahtari + eksiye_izin=1 ("Yine de kaydet") → 200 + uyari (eksiye düştü)', $r['kod'] === 200 && $j['ok'] === true && str_contains((string)$j['uyari'], 'eksiye düştü'), $r['govde']);
$r = post(['islem' => 'virman_ekle', 'kaynak_kasa_id' => (string)$M, 'hedef_kasa_id' => (string)$B, 'tarih' => date('Y-m-d'), 'tutar' => '100,00', 'istek_anahtari' => 'vm' . bin2hex(random_bytes(5)), 'eksiye_izin' => '0', 'tur' => 'virman']); $j = js($r);
ok('virman_ekle JSON 200 (KVR-), yonlendir kaynak kasa', $r['kod'] === 200 && str_starts_with((string)$j['belge_no'], 'KVR-') && $j['yonlendir'] === 'kasa_fisleri.php?kasa=' . $M, $r['govde']);
$r = post(['islem' => 'hareket_iptal', 'id' => (string)$j['id'], 'neden' => 'UI testi iptali', 'geri' => 'kasa_fisleri.php']); $j2 = js($r);
ok('hareket_iptal JSON 200 (virman iki bacak)', $r['kod'] === 200 && $j2['ok'] === true);
$r = post(['islem' => 'hareket_iptal', 'id' => (string)$hr, 'neden' => 'ab']);
ok('iptal nedeni < 3 → 422 alan hatası (neden)', $r['kod'] === 422 && isset(js($r)['alanlar']['neden']));
$r = post(['islem' => 'kart_kaydet', 'ad' => 'Test Banka', 'tip' => 'banka', 'para_birimi' => 'EUR', 'iban' => 'TR330006100519786457841326', 'aktif' => '1', 'geri' => 'kasa_tanimlari.php?sekme=kasa']); $j = js($r);
ok('kart_kaydet (banka, EUR, IBAN) JSON 200; yonlendir kasa_tanimlari.php?sekme=kasa', $r['kod'] === 200 && $j['ok'] === true && $j['yonlendir'] === 'kasa_tanimlari.php?sekme=kasa');
$r = post(['islem' => 'kart_kaydet', 'ad' => 'X', 'tip' => 'nakit', 'para_birimi' => 'TRY']);
ok('kısa ad → 422 + alanlar.ad', $r['kod'] === 422 && isset(js($r)['alanlar']['ad']));
$r = post(['islem' => 'hareket_ekle', 'kasa_id' => (string)$M, 'tur' => 'gider', 'tutar' => '1', 'tarih' => date('Y-m-d'), 'geri' => 'https://kotu.example/x'], false);
ok('klasik form: 303 + flash; geri açık yönlendirme beyaz listesinden geçmez (kasa_fisleri.php)', $r['kod'] === 303 && in_array('Location: kasa_fisleri.php', $r['basliklar'], true));

echo "\n=== 8. XSS ===\n";
$xs = kasa_kart_kaydet($db, ['ad' => '<img src=x onerror=alert(1)>', 'tip' => 'nakit', 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
$xc = cari_kart_kaydet($db, ['unvan' => '"><svg onload=alert(2)>', 'tip' => 'musteri', 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
$xh = kasa_hareket_ekle($db, kasa_t_hareket((int)$xs['id'], 'acilis', '5,00', ['aciklama' => '<script>alert(3)</script>']), 1);
$xh2 = kasa_hareket_ekle($db, kasa_t_hareket((int)$xs['id'], 'cari_tahsilat', '1,00', ['cari_id' => (string)$xc['id'], 'aciklama' => '"><b onmouseover=x>']), 1);
ok('XSS kurulumu', !empty($xs['ok']) && !empty($xc['ok']) && !empty($xh['ok']) && !empty($xh2['ok']));
foreach ([['kasa_fisleri.php', ['kasa' => (string)$xs['id']]], ['kasa_fisleri.php', ['kasa' => (string)$xs['id'], 'id' => (string)$xh2['id']]], ['kasa_fisleri.php', []], ['kasa_tanimlari.php', []], ['kasa_cikti.php', ['kasa' => (string)$xs['id']]]] as [$f, $gq]) {
    $r = get($f, $gq);
    ok("XSS: $f " . json_encode($gq) . ' — kasa adı / cari unvanı / açıklama kaçışlı', !str_contains($r['govde'], '<img src=x') && !str_contains($r['govde'], '<svg onload') && !str_contains($r['govde'], '<script>alert') && !str_contains($r['govde'], '<b onmouseover') && str_contains($r['govde'], '&lt;'));
}

echo "\n=== 9. kasa_cikti (yazdır + dışa aktarım) ===\n";
$r = get('kasa_cikti.php', ['kasa' => (string)$M]); $g = $r['govde'];
ok('yazdır: A4 sayfası (print_base + kasa.css), KASA DEFTERİ, firma başlığı (siparis_inv_ihracatci), alt not, uyarı yok', $r['kod'] === 200 && str_contains($g, 'assets/print_base.css') && str_contains($g, 'assets/kasa.css') && str_contains($g, 'KASA DEFTERİ') && str_contains($g, siparis_inv_ihracatci()['unvan'])
    && str_contains($g, 'resmî defter değildir') && uyari_yok($r), (string)$r['hata']);
ok('yazdır: devir 0,00 · yürüyen bakiye · kapanış = kasa bakiyesi (data-yz)', str_contains($g, 'data-yz="devir">0,00 ₺') && str_contains($g, 'DEVİR') && str_contains($g, 'data-yz="kapanis">' . cari_tutar_bicim(kasa_bakiye($db, $M), 'TRY')));
ok('yazdır: iptalli hareket defterde YOK', !str_contains($g, 'Hatalı giriş'));
$r = get('kasa_cikti.php', ['kasa' => (string)$M, 'bas' => date('Y-m-d', strtotime('+1 day'))]);
ok('yazdır: dönem başlangıcı gelecekte → devir = tüm bakiye, hareket yok mesajı', str_contains($r['govde'], 'Bu dönemde hareket yok') && str_contains($r['govde'], 'data-yz="devir">' . cari_tutar_bicim(kasa_bakiye($db, $M), 'TRY')));
$r = get('kasa_cikti.php', ['kasa' => '99999']);
ok('olmayan kasa → 404', $r['kod'] === 404);
$r = get('kasa_cikti.php', ['kasa' => (string)$SUBE]);
ok('aktif depoda görünmeyen kasa → 404', $r['kod'] === 404);
$ao = say('audit_log', "module = 'kasa' AND action = 'export'");
$r = get('kasa_cikti.php', ['kasa' => (string)$M, 'bicim' => 'csv']);
$sat = array_values(array_filter(explode("\n", $r['govde'])));
ok('CSV: BOM + ; ayraç + başlık + DEVİR + hareket satırları + TOPLAM satırı (binlik ayraçsız 50000,00)', $r['kod'] === 200 && str_starts_with($r['govde'], "\xEF\xBB\xBF") && (bool)preg_match('/Tarih;"?Belge No"?;/', $sat[0]) && str_contains($r['govde'], 'DEVİR') && str_contains($r['govde'], 'TOPLAM TRY') && str_contains($r['govde'], '50000,00') && !str_contains($r['govde'], '50.000,00'), substr($r['govde'], 0, 300));
ok('CSV: export audit kaydı (+1, module=kasa)', say('audit_log', "module = 'kasa' AND action = 'export'") === $ao + 1);
$r = get('kasa_cikti.php', ['kasa' => (string)$M, 'bicim' => 'xlsx']);
ok('xlsx (reports.export var): 200 + PK (zip) gövde, uyarı/hata yok, audit +1 (format=xlsx)', $r['kod'] === 200 && str_starts_with($r['govde'], 'PK') && $r['hata'] === null && !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)\b/', substr($r['govde'], 0, 300)) && say('audit_log', "module = 'kasa' AND action = 'export'") === $ao + 2,
    substr($r['govde'], 0, 200) . (string)$r['hata']);
$ao += 1;
$r = get('kasa_cikti.php', ['kasa' => (string)$M, 'bicim' => 'xlsx'], ['perms' => ['records.read']]);
ok('xlsx: reports.export yoksa forbidden, audit artmaz', str_contains((string)$r['hata'], 'forbidden') && say('audit_log', "module = 'kasa' AND action = 'export'") === $ao + 1);
$r = get('kasa_cikti.php', ['kasa' => (string)$M, 'bicim' => 'csv'], ['perms' => ['records.read']]);
ok('csv: reports.export yoksa forbidden', str_contains((string)$r['hata'], 'forbidden'));

siparis_test_bitir();
