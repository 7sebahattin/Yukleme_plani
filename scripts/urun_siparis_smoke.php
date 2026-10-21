<?php
// =========================================================
// scripts/urun_siparis_smoke.php — Ürün Siparişleri (v409): hesap, doğrulama, kaydet/düzelt/sil, depo izolasyonu,
// referans listeleri, uç (GET/POST JSON), yazdırma verisi, sayfa render + statik değişmezler.
//   php scripts/urun_siparis_smoke.php
// Bellek içi SQLite + stub auth + SENTETİK veri. Canlı DB'ye HİÇ dokunmaz. (_siparis_test_lib harness'i)
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/_siparis_ortak.php';
require_once $ROOT . '/config/urun_siparis.php';
// CSRF: gerçek csrf_check() çalışır; oturum çıktıdan ÖNCE açılır, geçerli token 'x'
session_start();
$_SESSION['csrf'] = 'x';

$db = db();
foreach (usip_tablolar() as $ddl) {
    [$tablo, $idx] = siparis_ddl_sqlite($ddl);
    $db->exec($tablo);
    foreach ($idx as $i) $db->exec($i);
}
// referans kaynakları (Tanımlar) — gerçek şema yerine asgari tablo
$db->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, unit_dara_kg REAL DEFAULT 0, is_active INTEGER DEFAULT 1)");
foreach ([['palet_tipi', '100x120 EPAL Palet'], ['kasa_cinsi', '30x40x11 Plastik Kasa'], ['firma', 'Tanımlı Firma A.Ş.'], ['urun', 'Tanımlı Ürün']] as [$t, $n])
    $db->prepare('INSERT INTO material_definitions (type, name) VALUES (?, ?)')->execute([$t, $n]);

class UsExit extends RuntimeException {}
$UST = ['h' => [], 'kod' => 200];
function ust_header(string $h, bool $replace = true, int $code = 0): void { global $UST; if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $UST['h'][strtolower(trim($a))] = trim($b); } if ($code > 0) $UST['kod'] = $code; }
function ust_code(?int $c = null) { global $UST; if ($c !== null) $UST['kod'] = $c; return $UST['kod']; }
function us_calistir(string $tmp): void { include $tmp; }
/** Uç/sayfa dosyasını çalıştırır. @return array{govde:string,json:?array,kod:int,h:array,hata:?string} */
function uc(string $dosya, array $get = [], string $yontem = 'GET', ?array $govde = null): array
{
    global $ROOT, $UST;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'ust_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'ust_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new UsExit();', $src);
    $src = str_replace("file_get_contents('php://input')", '$GLOBALS["US_GOVDE"]', $src);
    $src = preg_replace('/\bsession_write_close\(\);/', '', $src);
    $tmp = sys_get_temp_dir() . '/us_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $UST = ['h' => [], 'kod' => 200];
    $GLOBALS['US_GOVDE'] = $govde !== null ? json_encode($govde) : '';
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = $yontem; $_SERVER['PHP_SELF'] = '/' . $dosya;
    ob_start(); $hata = null;
    try { us_calistir($tmp); } catch (UsExit $e) { } catch (Throwable $e) { $hata = $e->getMessage(); }
    $o = (string)ob_get_clean(); @unlink($tmp);
    $j = json_decode($o, true);
    return ['govde' => $o, 'json' => is_array($j) ? $j : null, 'kod' => $UST['kod'], 'h' => $UST['h'], 'hata' => $hata];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null, string $aktif = 'DEPO1'): void
{
    global $PERMS, $IS_ADMIN, $UID, $DEPOLAR, $AKTIF_DEPO;
    $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar; $AKTIF_DEPO = $aktif;
}
/** Geçerli kaydet gövdesi (anahtarlar ezilebilir). */
function govde(array $o = []): array
{
    return $o + ['csrf' => 'x', 'islem' => 'kaydet', 'firma' => 'Bereket Tarım A.Ş.', 'urun' => 'Nar', 'teslimat_tarihi' => '2026-10-25',
        'kalibreler' => [['kalibre' => '7', 'palet' => '1'], ['kalibre' => '8', 'palet' => '1']],
        'palet_cinsi' => '100x120 epal palet', 'kasa_cinsi' => '30x40x11 plastik kasa', 'palet_kasa' => '50', 'birim_agirlik' => '10,0', 'birim_fiyat' => '28,50',
        'nakliyeci' => 'Test Nakliyat', 'cekici_plaka' => '34 tst 001', 'dorse_plaka' => '34tst002', 'sofor_adi' => 'Test Sürücü', 'sofor_telefon' => '+90 532 000 00 01',
        'yurtici_gumruk' => 'Test Gümrük', 'navlun_no' => 'N-1', 'aciklama' => 'İlk parti sevkiyat.'];
}

$OKU = ['siparis.read']; $YAZ = ['siparis.read', 'siparis.write']; $SIL = ['siparis.read', 'siparis.write', 'siparis.delete'];

// ── 1. hesap (tek yer) ──
$h = usip_hesap([['palet' => 1], ['palet' => 1]], 50, 10.0, 28.5);
ok('hesap: 2 palet × 50 kasa × 10 kg = 100 kasa / 1000 kg / 28.500 TL', $h['palet'] === 2 && $h['kasa'] === 100 && $h['kg'] === 1000.0 && $h['tutar'] === 28500.0, json_encode($h));
$h2 = usip_hesap([['palet' => 3]], 48, 12.5, null);
ok('hesap: fiyat yoksa tutar 0', $h2['kasa'] === 144 && $h2['kg'] === 1800.0 && $h2['tutar'] === 0.0, json_encode($h2));
ok('sayı ayrıştırma', usip_num('1.234,5') === 1234.5 && usip_num('28.50') === 28.5 && usip_num('1.234.567') === 1234567.0 && usip_num('10,0') === 10.0 && usip_num('abc') === null && usip_num('') === null);
ok('kalibre metni "9 PLT 8"', usip_kalibre_metin([['kalibre' => '9', 'palet' => 8], ['kalibre' => '7', 'palet' => 1]]) === '9 PLT 8, 7 PLT 1');

// ── 2. doğrulama ──
[$t, $e] = usip_dogrula(govde());
ok('doğrulama: geçerli gövde hatasız, plaka boşluksuz+büyük', !$e && $t['cekici_plaka'] === '34TST001' && $t['dorse_plaka'] === '34TST002' && $t['palet_kasa'] === 50 && $t['birim_agirlik'] === 10.0 && $t['birim_fiyat'] === 28.5, json_encode($e));
foreach ([
    'firma boş' => [['firma' => ' '], 'Firma'], 'ürün boş' => [['urun' => ''], 'Ürün'],
    'tarih geçersiz' => [['teslimat_tarihi' => '2026-13-45'], 'Teslimat tarihi'],
    'kalibre yok' => [['kalibreler' => []], 'En az bir kalibre'],
    'palet 0' => [['kalibreler' => [['kalibre' => '7', 'palet' => '0']]], 'palet adedi'],
    'palet ondalık' => [['kalibreler' => [['kalibre' => '7', 'palet' => '1,5']]], 'palet adedi'],
    'kalibre tekrar' => [['kalibreler' => [['kalibre' => '7', 'palet' => '1'], ['kalibre' => '7', 'palet' => '2']]], 'birden fazla'],
    'kalibre no boş' => [['kalibreler' => [['kalibre' => '', 'palet' => '1']]], 'numarası boş'],
    'palet kasa 0' => [['palet_kasa' => '0'], 'Palet kasa'], 'birim ağırlık 0' => [['birim_agirlik' => '0'], 'Birim ağırlık'],
    'fiyat negatif' => [['birim_fiyat' => '-1'], 'Birim fiyat'], 'telefon kısa' => [['sofor_telefon' => '123'], 'telefon'],
] as $ad => [$ek, $beklenen]) {
    [, $e2] = usip_dogrula(govde($ek));
    ok("doğrulama reddi: $ad", $e2 && str_contains(implode(' ', $e2), $beklenen), implode('|', $e2));
}
[$t3, $e3] = usip_dogrula(govde(['birim_fiyat' => '', 'sofor_telefon' => '', 'nakliyeci' => '']));
ok('doğrulama: fiyat/telefon/nakliyeci isteğe bağlı', !$e3 && $t3['birim_fiyat'] === null && $t3['sofor_telefon'] === null && $t3['nakliyeci'] === null);
ok('doğrulama: 31 kalibre reddedilir', usip_dogrula(govde(['kalibreler' => array_map(fn($i) => ['kalibre' => (string)$i, 'palet' => '1'], range(1, 31))]))[1] !== []);

// ── 3. uç: yetki, CSRF, kaydet, getir, düzelt, sil ──
rol($OKU, false);
$r = uc('urun_siparis_islem.php', [], 'POST', govde());
ok('uç: okuma yetkisi yazamaz (403)', $r['kod'] === 403, (string)$r['kod'] . ' ' . $r['govde']);
rol($YAZ, false);
$r = uc('urun_siparis_islem.php', [], 'POST', govde());
ok('uç: kaydet 200 + id', $r['kod'] === 200 && !empty($r['json']['ok']) && ($r['json']['id'] ?? 0) > 0, $r['govde'] . ' ' . ($r['hata'] ?? ''));
$id1 = (int)($r['json']['id'] ?? 0);
$sat = $db->query("SELECT * FROM urun_siparisleri WHERE id = $id1")->fetch(PDO::FETCH_ASSOC);
ok('kayıt: sunucu hesapladı (100 kasa / 1000 kg / 28500 TL)', (int)$sat['toplam_palet'] === 2 && (int)$sat['toplam_kasa'] === 100 && (float)$sat['toplam_kg'] === 1000.0 && (float)$sat['tutar'] === 28500.0, json_encode($sat));
ok('kayıt: depo aktif depodan, istemciden değil', $sat['depo'] === 'DEPO1' && (int)$sat['created_by'] === 1);
$kn = $db->query("SELECT kalibre, palet FROM urun_siparis_kalibreler WHERE siparis_id = $id1 ORDER BY sira")->fetchAll(PDO::FETCH_NUM);
ok('kayıt: kalibre satırları sıralı', $kn === [['7', 1], ['8', 1]], json_encode($kn));
$r = uc('urun_siparis_islem.php', [], 'POST', govde(['firma' => 'Bereket Tarım A.Ş.', 'urun' => 'nar', 'islem' => 'kaydet', 'teslimat_tarihi' => '2026-10-26']));
$id2 = (int)($r['json']['id'] ?? 0);
$u2 = $db->query("SELECT urun, firma, palet_cinsi, kasa_cinsi FROM urun_siparisleri WHERE id = $id2")->fetch(PDO::FETCH_ASSOC);
ok('kayıt: yazım mevcut kanonik adla eşlenir (nar→Nar, palet/kasa cinsi Tanımlar yazımı)', $u2 && $u2['urun'] === 'Nar' && $u2['palet_cinsi'] === '100x120 EPAL Palet' && $u2['kasa_cinsi'] === '30x40x11 Plastik Kasa', json_encode($u2));
$r = uc('urun_siparis_islem.php', [], 'POST', govde(['firma' => '']));
ok('uç: doğrulama hatası 422 + mesaj', $r['kod'] === 422 && str_contains((string)($r['json']['hata'] ?? ''), 'Firma'), $r['govde']);
$r = uc('urun_siparis_islem.php', ['id' => $id1]);
ok('uç: GET getir — şoför/telefon + kalibreler dahil', $r['kod'] === 200 && ($r['json']['siparis']['sofor_telefon'] ?? '') === '+90 532 000 00 01' && count($r['json']['siparis']['kalibreler'] ?? []) === 2, $r['govde']);
$r = uc('urun_siparis_islem.php', [], 'POST', govde(['id' => $id1, 'kalibreler' => [['kalibre' => '9', 'palet' => '8']], 'birim_fiyat' => '30']));
ok('düzelt: 200', !empty($r['json']['ok']), $r['govde']);
$sat = $db->query("SELECT * FROM urun_siparisleri WHERE id = $id1")->fetch(PDO::FETCH_ASSOC);
$kn = $db->query("SELECT kalibre, palet FROM urun_siparis_kalibreler WHERE siparis_id = $id1")->fetchAll(PDO::FETCH_NUM);
ok('düzelt: kalibreler yenilendi, toplamlar yeniden hesaplandı, ikinci satır AÇILMADI', $kn === [['9', 8]] && (int)$sat['toplam_palet'] === 8 && (int)$sat['toplam_kasa'] === 400 && (float)$sat['tutar'] === 120000.0 && (int)$db->query('SELECT COUNT(*) FROM urun_siparisleri')->fetchColumn() === 2, json_encode($sat));
ok('düzelt: updated_by/updated_at yazıldı', (int)$sat['updated_by'] === 1 && !empty($sat['updated_at']));
$r = uc('urun_siparis_islem.php', [], 'POST', ['csrf' => 'x', 'islem' => 'sil', 'id' => $id1]);
ok('silme: siparis.delete yoksa 403', $r['kod'] === 403 && (int)$db->query("SELECT COUNT(*) FROM urun_siparisleri WHERE id=$id1 AND deleted_at IS NULL")->fetchColumn() === 1);

// ── 4. depo izolasyonu ──
rol($SIL, false, 1, ['DEPO2'], 'DEPO2');
$r = uc('urun_siparis_islem.php', ['id' => $id1]);
ok('depo: başka depoya ait sipariş 404 (varlık sızmaz)', $r['kod'] === 404, (string)$r['kod']);
$r = uc('urun_siparis_islem.php', [], 'POST', ['csrf' => 'x', 'islem' => 'sil', 'id' => $id1]);
ok('depo: başka depodaki sipariş silinemez', $r['kod'] === 404 && (int)$db->query("SELECT COUNT(*) FROM urun_siparisleri WHERE id=$id1 AND deleted_at IS NULL")->fetchColumn() === 1);
$F2 = usip_filtre([]);
ok('depo: liste filtresi yalnız izinli depo', usip_liste($db, $F2, 1)['adet'] === 0);
$r = uc('urun_siparis_islem.php', [], 'POST', govde(['firma' => 'Depo2 Firma']));
$id3 = (int)($r['json']['id'] ?? 0);
ok('depo: yeni kayıt aktif depoyu (DEPO2) damgalar', $db->query("SELECT depo FROM urun_siparisleri WHERE id=$id3")->fetchColumn() === 'DEPO2');

// ── 5. liste / arama / yazdırma ──
rol($SIL, false, 1, ['DEPO1'], 'DEPO1');
$L = usip_liste($db, usip_filtre([]), 1);
ok('liste: DEPO1 görünümünde 2 sipariş, teslimat tarihine göre azalan', $L['adet'] === 2 && $L['satirlar'][0]['teslimat_tarihi'] >= $L['satirlar'][1]['teslimat_tarihi'], json_encode(array_column($L['satirlar'], 'teslimat_tarihi')));
ok('liste: toplamlar (palet 8+2=10)', (int)$L['toplam']['palet'] === 10, json_encode($L['toplam']));
ok('arama: kalibre ile', usip_liste($db, usip_filtre(['q' => '9']), 1)['adet'] === 1);
ok('arama: firma ile (TR büyük/küçük duyarsız LIKE)', usip_liste($db, usip_filtre(['q' => 'bereket']), 1)['adet'] === 2);
ok('arama: % ve _ düz metin (joker değil)', usip_liste($db, usip_filtre(['q' => '%']), 1)['adet'] === 0 && usip_liste($db, usip_filtre(['q' => '_']), 1)['adet'] === 0);
ok('arama: plaka ile', usip_liste($db, usip_filtre(['q' => '34TST002']), 1)['adet'] === 2);
$r = uc('urun_siparis_yazdir.php', ['q' => '9', 'amac' => 'pdf']);
$j = $r['json'];
ok('yazdırma: 200 + siparis_yazdir.js sözleşmesi (kolonlar/satirlar/toplam)', $r['kod'] === 200 && !empty($j['ok']) && is_array($j['kolonlar']) && count($j['kolonlar']) === 12 && count($j['satirlar']) === 1 && isset($j['toplam']['tutar']) && $j['adet'] === 1, $r['govde'] . ($r['hata'] ?? ''));
ok('yazdırma: her satır hücre sayısı = kolon sayısı, kalibre "9 PLT 8"', count($j['satirlar'][0]['h']) === count($j['kolonlar']) && $j['satirlar'][0]['h'][3] === '9 PLT 8', json_encode($j['satirlar'][0] ?? null));
ok('yazdırma: kolon genişlikleri toplamı 100', array_sum(array_column($j['kolonlar'], 'w')) === 100);
ok('yazdırma: toplam satırı 400 kasa / 4.000 kg / 120.000,00 TL', $j['toplam']['kasa'] === '400' && $j['toplam']['kg'] === '4.000' && $j['toplam']['tutar'] === '120.000,00', json_encode($j['toplam']));
ok('yazdırma: dosya adı + filtre özeti', str_starts_with($j['dosya'], 'Urun-Siparisleri-') && str_contains($j['filtre'], 'Arama'));
ok('yazdırma: POST 405', uc('urun_siparis_yazdir.php', [], 'POST')['kod'] === 405);
rol([], false);
ok('yazdırma: yetkisiz erişim reddedilir', (uc('urun_siparis_yazdir.php')['hata'] ?? '') !== null && uc('urun_siparis_yazdir.php')['json'] === null);

// ── 6. referans listeleri ──
rol($YAZ, false, 1, ['DEPO1'], 'DEPO1');
$ref = usip_referanslar($db);
$tag = fn(array $l, string $ad) => (array_values(array_filter($l, fn($x) => $x['ad'] === $ad))[0]['t'] ?? null);
ok('referans: Tanımlar (palet_tipi/kasa_cinsi/firma/urun) etiketli gelir', $tag($ref['palet'], '100x120 EPAL Palet') === 'tanim' && $tag($ref['kasa'], '30x40x11 Plastik Kasa') === 'tanim' && $tag($ref['firma'], 'Tanımlı Firma A.Ş.') === 'tanim' && $tag($ref['urun'], 'Tanımlı Ürün') === 'tanim', json_encode($ref, JSON_UNESCAPED_UNICODE));
ok('referans: önceki siparişlerin firması "kayit" olarak gelir, tekrar yok', $tag($ref['firma'], 'Bereket Tarım A.Ş.') === 'kayit' && count(array_filter($ref['firma'], fn($x) => usip_fold($x['ad']) === usip_fold('Bereket Tarım A.Ş.'))) === 1);
ok('referans: silinmiş siparişin firması gelmez', (function () use ($db) { $db->exec("UPDATE urun_siparisleri SET deleted_at='2026-10-01 00:00:00' WHERE firma='Depo2 Firma'"); return $GLOBALS['tag'](usip_referanslar($db)['firma'], 'Depo2 Firma') === null; })());

// ── 7. silme (soft) ──
rol($SIL, false, 1, ['DEPO1'], 'DEPO1');
$r = uc('urun_siparis_islem.php', [], 'POST', ['csrf' => 'x', 'islem' => 'sil', 'id' => $id1]);
ok('silme: 200, satır SOFT silinir (kayıt durur)', !empty($r['json']['ok']) && (int)$db->query("SELECT COUNT(*) FROM urun_siparisleri WHERE id=$id1")->fetchColumn() === 1 && $db->query("SELECT deleted_at FROM urun_siparisleri WHERE id=$id1")->fetchColumn() !== null, $r['govde']);
ok('silme: listeden ve getir ucundan kalkar', usip_liste($db, usip_filtre([]), 1)['adet'] === 1 && uc('urun_siparis_islem.php', ['id' => $id1])['kod'] === 404);

// ── 8. sayfa render ──
rol($SIL, false, 1, ['DEPO1'], 'DEPO1');
$k = uc('urun_siparis.php');
$html = $k['govde'];
ok('sayfa: hatasız render', $k['hata'] === null && str_contains($html, 'Ürün Siparişleri'), (string)$k['hata']);
ok('sayfa: satır tıklanabilir (data-id, tabindex), "Düzenle" düğmesi YOK', str_contains($html, 'class="usip-satir" data-id="' . $id2 . '" tabindex="0"') && !str_contains($html, '>Düzenle<'));
ok('sayfa: kalibre "9 PLT 8" biçimi, tire biçimi yok', str_contains($html, 'PLT <b>') && !preg_match('/\b\d+-\d+\b.*usip-chip/', $html));
ok('sayfa: şoför adı/telefonu liste HTML\'inde YOK', !str_contains($html, 'Test Sürücü') && !str_contains($html, '532 000 00 01'));
ok('sayfa: Yeni/Temizle düğmeleri YOK, Sil yalnız pencerede (id=usipSil), Yeni Sipariş var', !str_contains($html, 'id="usipTemizle"') && !str_contains($html, 'id="usipYeniForm"') && str_contains($html, 'id="usipSil"') && str_contains($html, 'id="usipYeni"'));
ok('sayfa: Ambalaj / Lojistik / Fiyat açılır bölümleri, fiyat Ambalaj\'da değil', preg_match('#id="accAmb".*?</details>.*?id="accLoj".*?</details>.*?id="accFiyat".*?</details>#s', $html) === 1
    && !preg_match('#id="accAmb".*?id="f_fiyat".*?</details>#s', preg_replace('#id="accLoj".*#s', '', $html)) );
ok('sayfa: yazdır / PDF / WP resim düğmeleri + siparis_yazdir.js', substr_count($html, 'data-sip-yazdir="') === 3 && str_contains($html, 'assets/siparis_yazdir.js') && str_contains($html, 'urun_siparis_yazdir.php'));
ok('sayfa: referans JSON güvenli gömülü', preg_match('#<script type="application/json" id="usipRef">(.+?)</script>#s', $html, $mm) === 1 && is_array(json_decode($mm[1], true)));
$k2 = uc('urun_siparis.php', ['q' => 'yokboyle']);
ok('sayfa: boş arama sonucu mesajı, yazdırma düğmesi yok', str_contains($k2['govde'], 'Aramaya uyan sipariş yok') && !str_contains($k2['govde'], 'data-sip-yazdir="'));
rol($OKU, false, 1, ['DEPO1'], 'DEPO1');
$k3 = uc('urun_siparis.php');
ok('sayfa: salt okuyucuda Yeni Sipariş düğmesi yok, data-yazma=0', !str_contains($k3['govde'], 'id="usipYeni"') && str_contains($k3['govde'], 'data-yazma="0"'));

// ── 9. statik değişmezler ──
$cfg = (string)file_get_contents($ROOT . '/config/urun_siparis.php');
$js = (string)file_get_contents($ROOT . '/assets/usip.js');
$islem = (string)file_get_contents($ROOT . '/urun_siparis_islem.php');
$yz = (string)file_get_contents($ROOT . '/urun_siparis_yazdir.php');
$sayfa = (string)file_get_contents($ROOT . '/urun_siparis.php');
ok('statik: şema yalnız CREATE TABLE IF NOT EXISTS (ALTER yok)', !preg_match('/\bALTER\s+TABLE\b/i', $cfg) && substr_count($cfg, 'CREATE TABLE IF NOT EXISTS') === 2);
ok('statik: uç POST\'ta CSRF + yetki kapıları', str_contains($islem, 'csrf_check(') && str_contains($islem, "can_siparis('write')") && str_contains($islem, "can_siparis('delete')") && str_contains($islem, "can_siparis('read')"));
ok('statik: audit\'e şoför/telefon yazılmaz', !preg_match("/audit_log_event\([^;]*sofor/s", $islem . $yz));
ok('statik: yazdırma ucu yalnız GET + siparis.read + no-store', str_contains($yz, "require_siparis('read')") && str_contains($yz, 'siparis_no_store()') && str_contains($yz, "!== 'GET'"));
ok('statik: sayfa kapısı require_siparis(\'read\') satırı', str_contains($sayfa, "require_siparis('read');"));
ok('statik: JS eval/onclick/localStorage yok, esc() var, textContent ile hata', !preg_match('/\beval\(|\bonclick=|localStorage/', $js) && str_contains($js, 'function esc(') && str_contains($js, "textContent = m"));
ok('statik: Yeni/Temizle düğmesi kodu kalktı', !str_contains($js, 'usipTemizle') && !str_contains($sayfa, 'Temizle</button>'));
ok('statik: yeni sayfa/uç adları sw.js bypass regex\'ine uyar', (function () use ($ROOT) {
    $sw = (string)file_get_contents($ROOT . '/sw.js'); preg_match('#if \((/\\\\/urun_siparis[^\n]+?/)\.test\(u\.pathname\)\) return;#', $sw, $m);
    if (!$m) return false;
    $re = '#\\/urun_siparis(_[a-z]+)?\\.php(\\/|$)#';
    foreach (['/urun_siparis.php', '/urun_siparis_islem.php', '/urun_siparis_yazdir.php', '/x/urun_siparis.php/p'] as $p) if (!preg_match($re, $p)) return false;
    return !preg_match($re, '/siparisler.php') && !preg_match($re, '/urun.php');
})());
ok('statik: OGL Siparişler başlığında Ürün Siparişleri bağlantısı + nav aktif listesinde', str_contains((string)file_get_contents($ROOT . '/siparisler.php'), 'href="urun_siparis.php"') && str_contains((string)file_get_contents($ROOT . '/config/helpers.php'), "'urun_siparis.php'"));
ok('statik: migrate.php kartı + POST dalı', str_contains((string)file_get_contents($ROOT . '/migrate.php'), "mg_kart_ac('urun-siparis')") && str_contains((string)file_get_contents($ROOT . '/migrate.php'), "=== 'urun_siparis'"));

siparis_test_bitir();
