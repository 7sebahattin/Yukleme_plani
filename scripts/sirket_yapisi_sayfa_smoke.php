<?php
// =========================================================
// scripts/sirket_yapisi_sayfa_smoke.php — program_tanimlari / sube_tanimlari / depo_tanimlari GERÇEKTEN çalıştırılır (Faz 0.1, v505)
// Bellek içi SQLite + stub auth (siparis harness'i); sayfa kaynağı geçici dosyaya yazılıp include edilir.
//   php scripts/sirket_yapisi_sayfa_smoke.php   → çıkış kodu 0 = tüm testler geçti
// Canlı DB'ye dokunmaz. Test verisi SENTETİKTİR.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/sirket_yapisi.php';
require_once $ROOT . '/config/siparis_yazdir.php';

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();

class SayfaCikis extends RuntimeException {}
$GLOBALS['SY_LOCATION'] = null;
function sy_t_header(string $h, bool $replace = true, int $code = 0): void { if (stripos($h, 'Location:') === 0) $GLOBALS['SY_LOCATION'] = trim(substr($h, 9)); }
function sy_t_code(?int $c = null) { return 200; }
function sy_t_calistir(string $tmp): void { include $tmp; }
/** @return array{html:string,hata:?string,yon:?string} */
function sy_sayfa(string $dosya, array $get = [], array $post = []): array
{
    global $ROOT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sy_t_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sy_t_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SayfaCikis();', $src);
    $tmp = sys_get_temp_dir() . '/sy_sayfa_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    $_GET = $get; $_POST = $post; $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $GLOBALS['SY_LOCATION'] = null;
    unset($_SESSION['flash']);
    ob_start(); $hata = null;
    try { sy_t_calistir($tmp); } catch (SayfaCikis $e) {} catch (Throwable $e) { $hata = $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $html = (string)ob_get_clean();
    @unlink($tmp);
    return ['html' => $html, 'hata' => $hata, 'yon' => $GLOBALS['SY_LOCATION']];
}
function rol(array $perms, bool $admin = false): void { global $PERMS, $IS_ADMIN; $PERMS = $perms; $IS_ADMIN = $admin; }
function csrf(): string { if (session_status() === PHP_SESSION_NONE) session_start(); return csrf_token(); }

$db = db();
siparis_test_diger_tablolar($db);
$db->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)");
$db->exec("INSERT INTO material_definitions (type, name) VALUES ('depo','Depo A'),('depo','Depo B')");
$SAYFALAR = ['program_tanimlari.php', 'sube_tanimlari.php', 'depo_tanimlari.php'];

echo "=== 1. Yönetici değilse üç sayfa da kapalı ===\n";
rol([], false);
foreach ($SAYFALAR as $p) {
    $r = sy_sayfa($p);
    ok("$p: yönetici değil → forbidden", $r['hata'] !== null && str_contains($r['hata'], 'forbidden'), (string)$r['hata']);
}

echo "\n=== 2. Tablolar kurulmamışken ===\n";
rol([], true);
foreach ($SAYFALAR as $p) {
    $r = sy_sayfa($p);
    ok("$p: hatasız, 'kurulmadı' + migrate bağlantısı, form/liste yok", $r['hata'] === null && str_contains($r['html'], 'tabloları kurulmadı') && str_contains($r['html'], 'migrate.php#sirket-yapisi') && !str_contains($r['html'], 'name="eylem"'), (string)$r['hata']);
    $r = sy_sayfa($p, [], ['csrf' => csrf(), 'eylem' => 'kaydet']);
    ok("$p: tablo yokken POST bile hata vermez (şema kurmaz)", $r['hata'] === null && $r['yon'] === null, (string)$r['hata']);
}
ok('sayfalar tablo kurmadı', !sirket_tablo_var($db, 'sirketler'));

echo "\n=== 3. Şema kuruldu: Şirketler ===\n";
foreach (sirket_tablolar() as $sql) { [$c, $ix] = siparis_ddl_sqlite($sql); $db->exec($c); foreach ($ix as $x) $db->exec($x); }
$r = sy_sayfa('program_tanimlari.php');
ok('boş liste: yönlendirme metni + "＋ Şirket Ekle", form kapalı', $r['hata'] === null && str_contains($r['html'], 'Henüz şirket tanımlanmadı') && str_contains($r['html'], '＋ Şirket Ekle') && !str_contains($r['html'], 'Yeni Şirket'), (string)$r['hata']);
$r = sy_sayfa('program_tanimlari.php', ['yeni' => '1']);
ok('?yeni=1: form alanları (kod, unvan, vergi no, Logo firma no, renk) + CSRF', $r['hata'] === null && str_contains($r['html'], 'Yeni Şirket') && str_contains($r['html'], 'name="kod"') && str_contains($r['html'], 'name="unvan"') && str_contains($r['html'], 'name="vergi_no"') && str_contains($r['html'], 'name="logo_firma_no"') && str_contains($r['html'], 'name="renk"') && str_contains($r['html'], 'name="csrf"'), (string)$r['hata']);
$r = sy_sayfa('program_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'kaydet', 'kod' => 'xa', 'unvan' => 'Deneme Bir', 'vergi_no' => '1111111111', 'renk' => '#112233']);
ok('POST ekle → kayıt + Location + başarı flash', $r['hata'] === null && $r['yon'] === 'program_tanimlari.php' && (int)$db->query("SELECT COUNT(*) FROM sirketler WHERE kod='XA'")->fetchColumn() === 1 && ($_SESSION['flash']['type'] ?? '') === 'success', (string)$r['hata'] . ' / ' . (string)$r['yon']);
$r = sy_sayfa('program_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'kaydet', 'kod' => 'XA', 'unvan' => 'Çakışan', 'vergi_no' => '']);
ok('hata: aynı kod → kaydedilmez, hata kutusu + girilen değerler formda KALIR', $r['hata'] === null && $r['yon'] === null && str_contains($r['html'], 'flash-error') && str_contains($r['html'], 'value="Çakışan"') && (int)$db->query("SELECT COUNT(*) FROM sirketler")->fetchColumn() === 1);
// (Yanlış CSRF: csrf_check() exit eder → süreci keserdi; her POST'un csrf_check ile başladığı sirket_yapisi_smoke.php'de statik denetlenir.)
$sid = (int)$db->query("SELECT id FROM sirketler WHERE kod='XA'")->fetchColumn();
$r = sy_sayfa('program_tanimlari.php', ['duzenle' => (string)$sid]);
ok('?duzenle=ID: form mevcut değerlerle dolu', $r['hata'] === null && str_contains($r['html'], 'Şirketi Düzenle') && str_contains($r['html'], 'value="Deneme Bir"') && str_contains($r['html'], 'value="#112233"') && str_contains($r['html'], 'name="id" value="' . $sid . '"'));
$r = sy_sayfa('program_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'kaydet', 'id' => (string)$sid, 'kod' => 'XA', 'unvan' => 'Deneme Bir (yeni)', 'vergi_no' => '1111111111']);
ok('POST düzenle → güncellenir', $r['yon'] === 'program_tanimlari.php' && $db->query("SELECT unvan FROM sirketler WHERE id=$sid")->fetchColumn() === 'Deneme Bir (yeni)');
$r = sy_sayfa('program_tanimlari.php');
ok('liste: şirket satırı, Düzenle/Şubeler/Pasife al düğmeleri', str_contains($r['html'], 'Deneme Bir (yeni)') && str_contains($r['html'], 'program_tanimlari.php?duzenle=' . $sid) && str_contains($r['html'], 'sube_tanimlari.php?sirket=' . $sid) && str_contains($r['html'], 'Pasife al'));
$r = sy_sayfa('program_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'aktif', 'id' => (string)$sid, 'aktif' => '0']);
ok('POST pasife al → aktif=0', $r['yon'] === 'program_tanimlari.php' && (int)$db->query("SELECT aktif FROM sirketler WHERE id=$sid")->fetchColumn() === 0);
$r = sy_sayfa('program_tanimlari.php');
ok('pasif şirket listede kalır (silinmez): "Pasif" rozeti + "Aktif et"', str_contains($r['html'], 'Pasif') && str_contains($r['html'], 'Aktif et'));
sy_sayfa('program_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'aktif', 'id' => (string)$sid, 'aktif' => '1']);
ok('şirket SİLME yolu yok (sayfada sil eylemi yok)', !str_contains((string)file_get_contents($ROOT . '/program_tanimlari.php'), "'sil'") && !str_contains((string)file_get_contents($ROOT . '/program_tanimlari.php'), 'DELETE'));
sy_sayfa('program_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'kaydet', 'kod' => 'XB', 'unvan' => 'İkinci Şirket']);

echo "\n=== 4. Şubeler ===\n";
$sidB = (int)$db->query("SELECT id FROM sirketler WHERE kod='XB'")->fetchColumn();
$r = sy_sayfa('sube_tanimlari.php');
ok('boş liste + "＋ Şube Ekle" + şirket süzgeci', $r['hata'] === null && str_contains($r['html'], '＋ Şube Ekle') && str_contains($r['html'], 'name="sirket"') && str_contains($r['html'], 'XA — Deneme Bir (yeni)'), (string)$r['hata']);
$r = sy_sayfa('sube_tanimlari.php', ['yeni' => '1', 'sirket' => (string)$sid]);
ok('?yeni=1&sirket=ID: şirket önceden seçili', $r['hata'] === null && str_contains($r['html'], 'Yeni Şube') && (bool)preg_match('/<option value="' . $sid . '" selected>/', $r['html']), (string)$r['hata']);
foreach ([['MRK', 'Merkez'], ['B1', 'Bölge 1'], ['B2', 'Bölge 2']] as [$k, $ad]) {
    $r = sy_sayfa('sube_tanimlari.php', ['sirket' => (string)$sid], ['csrf' => csrf(), 'eylem' => 'kaydet', 'suz' => (string)$sid, 'sirket_id' => (string)$sid, 'kod' => $k, 'ad' => $ad]);
}
ok('3 şube eklendi; Location süzgeci korur', $r['yon'] === 'sube_tanimlari.php?sirket=' . $sid && (int)$db->query("SELECT COUNT(*) FROM subeler WHERE sirket_id=$sid")->fetchColumn() === 3, (string)$r['yon']);
$r = sy_sayfa('sube_tanimlari.php', ['sirket' => (string)$sid], ['csrf' => csrf(), 'eylem' => 'kaydet', 'suz' => (string)$sid, 'sirket_id' => (string)$sid, 'kod' => 'MRK', 'ad' => 'Çift']);
ok('hata: aynı şirkette aynı şube kodu → form + hata, kayıt yok', $r['yon'] === null && str_contains($r['html'], 'flash-error') && (int)$db->query("SELECT COUNT(*) FROM subeler WHERE sirket_id=$sid")->fetchColumn() === 3);
$r = sy_sayfa('sube_tanimlari.php', ['sirket' => (string)$sid]);
ok('süzgeçli liste yalnız o şirketin şubelerini gösterir', str_contains($r['html'], 'Bölge 1') && str_contains($r['html'], 'Bölge 2') && !str_contains($r['html'], 'İkinci Şirket</td>'));
$subeId = (int)$db->query("SELECT id FROM subeler WHERE kod='MRK'")->fetchColumn();
$r = sy_sayfa('sube_tanimlari.php', ['duzenle' => (string)$subeId]);
ok('?duzenle=ID: şirket seçici YOK (şirket sonradan değişmez), alanlar dolu', $r['hata'] === null && str_contains($r['html'], 'Şubeyi Düzenle') && !str_contains($r['html'], 'name="sirket_id"') && str_contains($r['html'], 'value="Merkez"'));
sy_sayfa('sube_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'aktif', 'id' => (string)$subeId, 'aktif' => '0']);
ok('şube pasife alınır, listede kalır', (int)$db->query("SELECT aktif FROM subeler WHERE id=$subeId")->fetchColumn() === 0 && str_contains(sy_sayfa('sube_tanimlari.php')['html'], 'Merkez'));

echo "\n=== 5. Depolar ===\n";
$r = sy_sayfa('depo_tanimlari.php');
ok('iki depo listelenir: "Girilmedi" rozeti, "Kısıtsız", tanımlar bağlantısı', $r['hata'] === null && str_contains($r['html'], 'Depo A') && str_contains($r['html'], 'Depo B') && str_contains($r['html'], 'Girilmedi') && str_contains($r['html'], 'Kısıtsız') && str_contains($r['html'], 'definitions.php?type=depo'), (string)$r['hata']);
$depoA = (int)$db->query("SELECT id FROM material_definitions WHERE name='Depo A'")->fetchColumn();
$r = sy_sayfa('depo_tanimlari.php', ['duzenle' => (string)$depoA]);
ok('?duzenle=ID: tür seçenekleri (3), şube seçici, şirket kutuları', $r['hata'] === null && substr_count($r['html'], 'name="tur"') === 3 && str_contains($r['html'], 'name="sube_id"') && str_contains($r['html'], 'name="sirketler[]"') && str_contains($r['html'], 'Fason'));
$r = sy_sayfa('depo_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'kaydet', 'depo_id' => (string)$depoA, 'tur' => 'fason', 'sube_id' => '', 'aciklama' => 'Deneme tesisi', 'sirketler' => [(string)$sid, (string)$sidB]]);
ok('POST kaydet → depo_detay + 2 şirket izni + Location', $r['yon'] === 'depo_tanimlari.php' && $db->query("SELECT tur FROM depo_detay WHERE depo_id=$depoA")->fetchColumn() === 'fason' && (int)$db->query("SELECT COUNT(*) FROM depo_sirket WHERE depo_id=$depoA")->fetchColumn() === 2, (string)$r['hata']);
$r = sy_sayfa('depo_tanimlari.php');
ok('liste: tür etiketi + şirket kodu rozetleri', str_contains($r['html'], 'Fason') && str_contains($r['html'], '<b>XA</b>') && str_contains($r['html'], '<b>XB</b>'));
$r = sy_sayfa('depo_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'kaydet', 'depo_id' => (string)$depoA, 'tur' => 'sahte']);
ok('hata: geçersiz tür → hata kutusu + form, kayıt değişmez', $r['yon'] === null && str_contains($r['html'], 'flash-error') && $db->query("SELECT tur FROM depo_detay WHERE depo_id=$depoA")->fetchColumn() === 'fason');
ok('depo adı DEĞİŞMEDİ', $db->query("SELECT name FROM material_definitions WHERE id=$depoA")->fetchColumn() === 'Depo A');
$r = sy_sayfa('depo_tanimlari.php', ['duzenle' => '9999']);
ok('olmayan depo ?duzenle → "Depo bulunamadı", çökmez', $r['hata'] === null && str_contains($r['html'], 'Depo bulunamadı'));

echo "\n=== 6. XSS: kullanıcı verisi kaçışlı basılır ===\n";
sy_sayfa('program_tanimlari.php', [], ['csrf' => csrf(), 'eylem' => 'kaydet', 'kod' => 'XS', 'unvan' => '<script>alert(1)</script>', 'adres' => '"><img src=x onerror=alert(2)>']);
$r = sy_sayfa('program_tanimlari.php');
ok('liste: <script> kaçışlı', !str_contains($r['html'], '<script>alert(1)</script>') && str_contains($r['html'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
$xsId = (int)$db->query("SELECT id FROM sirketler WHERE kod='XS'")->fetchColumn();
$r = sy_sayfa('program_tanimlari.php', ['duzenle' => (string)$xsId]);
ok('form değeri: tırnak kaçışlı (nitelik kırılamaz)', !str_contains($r['html'], 'value=""><img') && str_contains($r['html'], '&quot;&gt;&lt;img'));

// Görsel kontrol için (isteğe bağlı): SY_HTML_OUT=<klasör> → üç ekranı HTML olarak yazar (Playwright ile bakılır)
if (($out = (string)getenv('SY_HTML_OUT')) !== '' && is_dir($out)) {
    $base = '<base href="file://' . $ROOT . '/">';
    $yaz = function (string $ad, array $r) use ($out, $base): void { file_put_contents("$out/$ad.html", str_replace('../assets/', 'assets/', preg_replace('/<head>/', '<head>' . $base, $r['html'], 1))); };
    $yaz('sirket', sy_sayfa('program_tanimlari.php', ['duzenle' => (string)$sid]));
    $yaz('sirket_liste', sy_sayfa('program_tanimlari.php'));
    $yaz('sube', sy_sayfa('sube_tanimlari.php', ['yeni' => '1', 'sirket' => (string)$sid]));
    $yaz('depo', sy_sayfa('depo_tanimlari.php', ['duzenle' => (string)$depoA]));
}

siparis_test_bitir();
