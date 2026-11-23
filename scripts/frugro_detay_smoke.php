<?php
// =========================================================
// scripts/frugro_detay_smoke.php — Frugro sipariş DETAYI (P2): frugro_view.php + frugro_kaydet.php form dalı + frugro_eml.php +
// frugro_zip.php + frugro_ayarlar.php (Mail ayarları) + frugro_pkl.php GET indirme + config/frugro_detay.php.
//   php scripts/frugro_detay_smoke.php   → çıkış 0 = hepsi geçti
// Bellek içi SQLite + stub auth + SENTETİK veri (adresler yalnız @ornek.test). Sayfalar gerçekten çalıştırılır (header/exit yakalanır).
// Kapsam: adım şeridi + zamanlar, hızlı düğme görünürlüğü (durum × yetki), ✎ alan (form-POST, PRG, olay + audit, hassas değer audit'e
// girmez), CSRF 403 (alt süreç), mail/evrak onayının SUNUCUDA zorlanması, .eml (X-Unsent, To/Cc ayardan, From yok, 2 PDF eki %PDF,
// xlsx eki YOK, govde= uygulanır, başlık enjeksiyonu yok), ZIP içeriği, depo izolasyonu (aynı 404), mail ayarı (geçersiz e-posta,
// audit yalnız sayı), XSS.
// =========================================================
if (in_array('--csrf', $argv ?? [], true)) {            // alt süreç: CSRF'siz form-POST → reddedilmeli
    require_once __DIR__ . '/_siparis_test_lib.php';
    require_once $ROOT . '/config/frugro_core.php';
    $db = db(); siparis_test_diger_tablolar($db);
    foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
    frg_siparis_kaydet($db, ['po_no' => '26-5000-00001', 'kutu' => 10], ['depo' => 'DEPO1']);
    $IS_ADMIN = true; $UID = 1; $PERMS = ['siparis.read', 'siparis.write', 'siparis.delete'];
    ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['islem' => (string)$argv[array_search('--csrf', $argv) + 1], 'id' => '1', 'alan' => 'urun', 'deger' => 'X', 'hedef' => 'hazirlik']; $_GET = [];
    $src = file_get_contents($ROOT . '/frugro_kaydet.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $tmp = sys_get_temp_dir() . '/frgd_csrf_' . getmypid() . '.php'; file_put_contents($tmp, $src);
    ob_start(); include $tmp; ob_get_clean();
    echo "CSRF-GECTI\n";
    exit(0);
}
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_detay.php';
require_once $ROOT . '/vendor/autoload.php';
ini_set('memory_limit', '512M');
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$db = db();
siparis_test_diger_tablolar($db);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');

class FrgExit extends RuntimeException {}
$FT = ['h' => [], 'kod' => 200];
function frgt_header(string $h, bool $replace = true, int $code = 0): void { global $FT; if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $FT['h'][strtolower(trim($a))] = trim($b); } if ($code > 0) $FT['kod'] = $code; }
function frgt_code(?int $c = null) { global $FT; if ($c !== null) $FT['kod'] = $c; return $FT['kod']; }
function frg_calistir(string $tmp): void { include $tmp; }
/** @return array{html:string,kod:int,git:?string,hata:?string,h:array} */
function sayfa(string $dosya, array $get = [], array $post = []): array
{
    global $ROOT, $FT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'frgt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'frgt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new FrgExit();', $src);
    $src = str_replace(['while (ob_get_level() > 0) { ob_end_clean(); }', 'session_write_close();'], '', $src);   // test çıktı tamponu korunur
    $tmp = sys_get_temp_dir() . '/frgd_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $FT = ['h' => [], 'kod' => 200];
    $_GET = $get; $_POST = $post; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya; $_SERVER['REQUEST_URI'] = '/' . $dosya;
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['flash']);
    ob_start(); $hata = null;
    try { frg_calistir($tmp); } catch (FrgExit $e) {} catch (Throwable $e) { $hata = $e->getMessage(); }
    $html = (string)ob_get_clean(); @unlink($tmp);
    return ['html' => $html, 'kod' => $FT['kod'], 'git' => $FT['h']['location'] ?? null, 'hata' => $hata, 'h' => $FT['h']];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null): void { global $PERMS, $IS_ADMIN, $UID, $DEPOLAR; $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar; }
function csrf(): string { if (session_status() === PHP_SESSION_NONE) session_start(); return csrf_token(); }
function temiz(array $r): bool { return $r['hata'] === null && !preg_match('/\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b:/', $r['html']); }
function form(array $p): array { $p['csrf'] = csrf(); return sayfa('frugro_kaydet.php', [], $p); }
function flash(): string { return (string)($_SESSION['flash']['msg'] ?? $_SESSION['flash']['message'] ?? json_encode($_SESSION['flash'] ?? null, JSON_UNESCAPED_UNICODE)); }
function say(PDO $db, string $sql): int { return (int)$db->query($sql)->fetchColumn(); }
/** .eml'i çözer: başlıklar + parçalar [{tip, ad, govde(bayt)}]. */
function eml_coz(string $eml): array
{
    [$bas, $govde] = explode("\r\n\r\n", $eml, 2) + [1 => ''];
    $h = [];
    foreach (explode("\r\n", $bas) as $l) if (str_contains($l, ':')) { [$a, $b] = explode(':', $l, 2); $h[strtolower(trim($a))] = trim($b); }
    $parca = [];
    if (preg_match('/boundary="([^"]+)"/', $h['content-type'] ?? '', $m)) {
        foreach (array_slice(explode('--' . $m[1], $govde), 1) as $p) {
            if (str_starts_with($p, '--')) break;
            [$ph, $pb] = explode("\r\n\r\n", ltrim($p, "\r\n"), 2) + [1 => ''];
            preg_match('/Content-Type: ([^;\r\n]+)/', $ph, $t); preg_match('/filename="([^"]+)"/', $ph, $f);
            $parca[] = ['tip' => trim($t[1] ?? ''), 'ad' => $f[1] ?? '', 'govde' => base64_decode(preg_replace('/\s+/', '', $pb))];
        }
    } else {
        $parca[] = ['tip' => 'text/plain', 'ad' => '', 'govde' => base64_decode(preg_replace('/\s+/', '', $govde))];
    }
    return ['h' => $h, 'p' => $parca];
}

$ADMIN = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'];
$OPER  = ['siparis.read', 'siparis.write', 'siparis.match'];
$VIEW  = ['siparis.read'];

// ── Sentetik veri ──
$yeni = function (string $po, string $durum, bool $paletli, string $depo = 'DEPO1') use ($db): int {
    $r = frg_siparis_kaydet($db, ['po_no' => $po, 'urun' => 'NAR', 'cesit' => 'Wonderful', 'boy' => '80-100', 'kutu_palet' => 200, 'palet' => 2, 'kutu' => 400,
        'fiyat_kutu_birim' => 52000, 'cikis_tarihi' => date('Y-m-d'), 'son_musteri' => 'Musteri-A', 'giris_limani' => 'LIMAN-1'], ['depo' => $depo, 'user_id' => 1, 'kaynak' => 'yukleme']);
    $id = (int)$r['id'];
    if ($paletli) frg_palet_kaydet($db, $id, [
        ['palet_no' => '1', 'kalibre' => '14', 'lot' => 'L1', 'ggn_no' => '4000000000001', 'ggn_adi' => 'ORNEK', 'kutu' => 200, 'brut_kg' => 700, 'net_kg' => 640],
        ['palet_no' => '2', 'kalibre' => '16', 'lot' => 'L2', 'ggn_no' => '4000000000001', 'ggn_adi' => 'ORNEK', 'kutu' => 200, 'brut_kg' => 702, 'net_kg' => 642]]);
    if ($durum !== 'yeni') $db->prepare('UPDATE frugro_siparisler SET durum = ? WHERE id = ?')->execute([$durum, $id]);   // test kurulumu (uygulama yolu değil)
    return $id;
};
$ID_Y = $yeni('26-5000-00001', 'yeni', false);
$ID_P = $yeni('26-5000-00002', 'pkl_hazir', true);
$ID_M = $yeni('26-5000-00003', 'mail_gonderildi', true);
$ID_D2 = $yeni('26-5000-00004', 'pkl_hazir', true, 'DEPO2');
$db->prepare('INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)')->execute([$ID_P, 'durum', 'yeni → pkl_hazir', '2026-11-01 10:00:00']);

echo "=== 1. Detay görünümü ===\n";
rol($ADMIN, true);
$r = sayfa('frugro_view.php', ['id' => $ID_P]);
ok('detay temiz', temiz($r), (string)$r['hata'] . substr(strip_tags($r['html']), 0, 200));
$h = $r['html'];
ok('OGL düzeni: .sip-mod.sip-view + adım şeridi + .sip-grid iki sütun', str_contains($h, 'class="sip-mod sip-view frg-detay"') && str_contains($h, '<ol class="sip-steps') && str_contains($h, 'sip-col sip-col-ana') && str_contains($h, 'sip-col sip-col-yan'));
ok('7 adım (iptal/eski kapalı şeritte yok)', substr_count($h, '<li class="sip-step') === 7);
ok('adım zamanı olaydan (01.11.2026 10:00)', str_contains($h, '01.11.2026 10:00'));
ok('PKL hazır şu anki adım, Invoice sıradaki', (bool)preg_match('/sip-step--su-an"[^>]*>\s*<span class="sip-step-nokta"[^>]*>3</', $h) && str_contains($h, 'sip-step--sonraki'));
ok('kartlar: Sipariş bilgileri, Paletler, Belgeler, PKL, Invoice, Kayıt, Geçmiş', str_contains($h, 'id="bilgi"') && str_contains($h, 'id="paletler"') && str_contains($h, 'id="belgeler"') && str_contains($h, 'id="pkl"') && str_contains($h, 'id="invoice"') && str_contains($h, 'id="gecmis"'));
ok('✎ alan düzenleme (data-sip-alan) + #sipAlanModal form → frugro_kaydet.php islem=alan', substr_count($h, 'data-sip-alan="') >= 20 && str_contains($h, 'id="sipAlanModal"') && str_contains($h, 'name="islem" value="alan"'));
ok('hızlı düğmeler: Yola çıktı, Mail gönderildi (pencere), İptal, Durumu değiştir', str_contains($h, '🛣 Yola çıktı ✓') && str_contains($h, 'data-sip-ac="frgMailModal"') && str_contains($h, 'data-sip-ac="frgIptalModal"') && str_contains($h, 'data-sip-ac="frgDurumModal"'));
ok('Belgeler: PKL PDF/Excel GET indirme + ZIP; Order Log saklanmaz', str_contains($h, 'frugro_pkl.php?id=' . $ID_P . '&amp;indir=pdf') && str_contains($h, '&amp;indir=xlsx') && str_contains($h, 'frugro_zip.php?id=' . $ID_P) && str_contains($h, 'saklanmaz'));
ok('⋮ Sil (delete) + #frgSilModal (onay kutulu)', str_contains($h, 'data-sip-ac="frgSilModal"') && str_contains($h, 'id="frgSilModal"') && str_contains($h, 'name="onay" value="1" required'));
ok('ns="frg" görülme kaydı (OGL kaydıyla çakışmaz)', (bool)preg_match('/<span hidden data-sip-gor-ns="frg" data-sip-gor-uid="1"[^>]*data-sip-gor-id="' . $ID_P . '"/', $h));
$govde = substr($h, (int)strpos($h, 'class="sip-mod sip-view'));   // genel üst bilgi (tema düğmesi) hariç
ok('eski <dialog> / frugro.js detay kodu YOK; satır içi onclick YOK', !str_contains($govde, '<dialog') && !str_contains($h, 'assets/frugro.js') && !preg_match('/\son(click|submit|change)=/i', $govde));
ok('mail penceresi: alıcı ayarı yok notu + Ayarlar bağlantısı', str_contains($h, 'Alıcı adresi tanımlı değil') && str_contains($h, 'frugro_ayarlar.php#pkl-mail'));
ok('mail penceresi: Invoice yok uyarısı + "Invoice olmadan işaretle" onayı', str_contains($h, 'Invoice henüz üretilmedi') && str_contains($h, 'name="onay_invoice_yok"'));
ok('mail penceresi: mailto + .eml + düzenlenebilir metin + sıfırla', str_contains($h, 'href="mailto:') && str_contains($h, 'frugro_eml.php?id=' . $ID_P . '&amp;tur=pkl') && str_contains($h, 'data-sip-govde ') && str_contains($h, 'data-sip-govde-sifirla'));

$r = sayfa('frugro_view.php', ['id' => $ID_Y]);
ok('yeni: Hazırlık başladı düğmesi; Mail pasif (PKL yok, nedeni yazılı); ZIP pasif', str_contains($r['html'], '🧺 Hazırlık başladı') && str_contains($r['html'], 'id="frgMailNeden"') && !str_contains($r['html'], 'id="frgMailModal"') && !str_contains($r['html'], 'href="frugro_zip.php'));
$r = sayfa('frugro_view.php', ['id' => $ID_M]);
ok('mail_gonderildi: Evrak takımı penceresi (onay kutusu + .eml evrak) var, Mail düğmesi yok', str_contains($r['html'], 'id="frgEvrakModal"') && str_contains($r['html'], 'name="onay_evrak"') && str_contains($r['html'], 'tur=evrak') && !str_contains($r['html'], 'data-sip-ac="frgMailModal"'));
rol($VIEW);
$r = sayfa('frugro_view.php', ['id' => $ID_P]);
ok('yalnız okuma: ✎ / hızlı düğme / pencere / ⋮ Sil YOK, bilgi notu var', temiz($r) && !str_contains($r['html'], 'data-sip-alan=') && !str_contains($r['html'], 'pm-overlay') && !str_contains($r['html'], 'frgSilModal') && str_contains($r['html'], 'siparis.write yetkisi gerekir'));
rol($OPER);
$r = sayfa('frugro_view.php', ['id' => $ID_P]);
ok('operatör: düzenler, ⋮ Sil YOK', str_contains($r['html'], 'data-sip-alan=') && !str_contains($r['html'], 'frgSilModal'));
rol($ADMIN, false, 1, ['DEPO1']);
$r = sayfa('frugro_view.php', ['id' => $ID_D2]);
ok('başka depo: 404 (varlığı sızdırmaz)', $r['kod'] === 404 && !str_contains($r['html'], '26-5000-00004'));
$r = sayfa('frugro_view.php', ['id' => 99999]);
ok('olmayan id: 404', $r['kod'] === 404);

echo "\n=== 2. ✎ alan (form-POST, PRG) ===\n";
rol($OPER);
$r = form(['islem' => 'alan', 'id' => $ID_P, 'alan' => 'parti_no', 'deger' => 'P-77']);
ok('alan: 303 → frugro_view.php?id=…#bilgi + flash başarı', $r['kod'] === 303 && $r['git'] === 'frugro_view.php?id=' . $ID_P . '#bilgi' && ($_SESSION['flash']['type'] ?? '') === 'success', json_encode([$r['kod'], $r['git'], $_SESSION['flash'] ?? null, $r['hata']]));
ok('alan: yazıldı + olay duzenlendi + audit update', frg_getir($db, $ID_P)['parti_no'] === 'P-77' && say($db, "SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id=$ID_P AND tur='duzenlendi' AND metin LIKE '%parti_no%'") === 1
    && say($db, "SELECT COUNT(*) FROM audit_log WHERE action='update' AND module='frugro_siparis' AND new_values LIKE '%P-77%'") === 1);
$r = form(['islem' => 'alan', 'id' => $ID_P, 'alan' => 'sofor_telefon', 'deger' => '+90 555 111 22 33']);
ok('hassas alan: değer yazıldı ama audit\'e DEĞERİ girmedi', frg_getir($db, $ID_P)['sofor_telefon'] === '+90 555 111 22 33' && say($db, "SELECT COUNT(*) FROM audit_log WHERE new_values LIKE '%555 111%' OR old_values LIKE '%555 111%'") === 0
    && say($db, "SELECT COUNT(*) FROM audit_log WHERE new_values LIKE '%sofor_telefon%'") === 1);
$r = form(['islem' => 'alan', 'id' => $ID_P, 'alan' => 'durum', 'deger' => 'iptal']);
ok('beyaz liste dışı alan (durum) reddedilir, durum değişmez', ($_SESSION['flash']['type'] ?? '') === 'error' && frg_getir($db, $ID_P)['durum'] === 'pkl_hazir');
$r = form(['islem' => 'alan', 'id' => $ID_P, 'alan' => 'cikis_tarihi', 'deger' => 'abc']);
ok('geçersiz tarih → flash hata, PRG #bilgi', ($_SESSION['flash']['type'] ?? '') === 'error' && str_ends_with((string)$r['git'], '#bilgi'));
$r = form(['islem' => 'alan', 'id' => $ID_P, 'alan' => 'son_musteri', 'deger' => '<script>alert(1)</script>']);
$v = sayfa('frugro_view.php', ['id' => $ID_P]);
ok('XSS: detayda kaçırılır', !str_contains($v['html'], '<script>alert(1)') && str_contains($v['html'], '&lt;script&gt;alert(1)'));
rol($ADMIN, false, 1, ['DEPO1']);
$r = form(['islem' => 'alan', 'id' => $ID_D2, 'alan' => 'urun', 'deger' => 'X']);
ok('başka depo kaydına form-POST → 404 akışı (liste), değer değişmez', $r['git'] === 'frugro_siparis.php' && frg_getir($db, $ID_D2)['urun'] === 'NAR');
rol($VIEW);
$r = form(['islem' => 'alan', 'id' => $ID_P, 'alan' => 'urun', 'deger' => 'X']);
ok('yalnız okuma: form-POST require_siparis(write) ile 403', $r['hata'] !== null && str_contains($r['hata'], 'forbidden') && frg_getir($db, $ID_P)['urun'] === 'NAR');
foreach (['alan', 'durum', 'sil'] as $isl) {
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --csrf ' . escapeshellarg($isl) . ' 2>&1');
    ok("CSRF'siz form-POST islem=$isl reddedildi", !str_contains($o, 'CSRF-GECTI'), substr($o, 0, 200));
}

echo "\n=== 3. Durum pencereleri (onay sunucuda) ===\n";
rol($OPER);
$r = form(['islem' => 'durum', 'id' => $ID_P, 'hedef' => 'mail_gonderildi']);
ok('mail: onay_pkl yok → hata, durum aynı', ($_SESSION['flash']['type'] ?? '') === 'error' && frg_getir($db, $ID_P)['durum'] === 'pkl_hazir' && str_ends_with((string)$r['git'], '#durum'));
$r = form(['islem' => 'durum', 'id' => $ID_P, 'hedef' => 'mail_gonderildi', 'onay_pkl' => '1']);
ok('mail: Invoice üretilmemiş + onay_invoice_yok yok → hata', ($_SESSION['flash']['type'] ?? '') === 'error' && str_contains(flash(), 'Invoice olmadan') && frg_getir($db, $ID_P)['durum'] === 'pkl_hazir');
$r = form(['islem' => 'durum', 'id' => $ID_Y, 'hedef' => 'mail_gonderildi', 'onay_pkl' => '1', 'onay_invoice_yok' => '1']);
ok('mail: paletsiz sipariş 422 (frg_durum_degistir)', ($_SESSION['flash']['type'] ?? '') === 'error' && frg_getir($db, $ID_Y)['durum'] === 'yeni');
$r = form(['islem' => 'durum', 'id' => $ID_P, 'hedef' => 'mail_gonderildi', 'onay_pkl' => '1', 'onay_invoice_yok' => '1']);
ok('mail: iki onayla → mail_gonderildi + audit frugro_mail_gonderildi (invoice_yok_onayi)', frg_getir($db, $ID_P)['durum'] === 'mail_gonderildi'
    && say($db, "SELECT COUNT(*) FROM audit_log WHERE action='frugro_mail_gonderildi' AND new_values LIKE '%invoice_yok_onayi\":true%'") === 1, flash());
$r = form(['islem' => 'durum', 'id' => $ID_P, 'hedef' => 'evrak_gonderildi']);
ok('evrak: onay_evrak yok → hata', ($_SESSION['flash']['type'] ?? '') === 'error' && frg_getir($db, $ID_P)['durum'] === 'mail_gonderildi');
$r = form(['islem' => 'durum', 'id' => $ID_P, 'hedef' => 'evrak_gonderildi', 'onay_evrak' => '1']);
ok('evrak: onayla → evrak_gonderildi (kapanış)', frg_getir($db, $ID_P)['durum'] === 'evrak_gonderildi' && in_array('evrak_gonderildi', frg_kapali_durumlar(), true));
$r = form(['islem' => 'durum', 'id' => $ID_P, 'hedef' => 'geri']);
ok('geri: gerekçesiz reddedilir', frg_getir($db, $ID_P)['durum'] === 'evrak_gonderildi');
$r = form(['islem' => 'durum', 'id' => $ID_P, 'hedef' => 'geri', 'gerekce' => 'Yanlış işaretlendi']);
ok('geri: gerekçeyle evrak → mail', frg_getir($db, $ID_P)['durum'] === 'mail_gonderildi');
$r = form(['islem' => 'durum', 'id' => $ID_Y, 'hedef' => 'hazirlik']);
ok('hazırlık başladı (form) → hazirlik, PRG #durum', frg_getir($db, $ID_Y)['durum'] === 'hazirlik' && $r['git'] === 'frugro_view.php?id=' . $ID_Y . '#durum');
$v = sayfa('frugro_view.php', ['id' => $ID_P]);
ok('Geçmiş: durum olayları etiketle (Evrak takımı → Mail gönderildi · Gerekçe)', str_contains($v['html'], 'Durum değişti: Evrak takımı → Mail gönderildi · Gerekçe: Yanlış işaretlendi'));

echo "\n=== 4. Mail ayarı (frugro_ayarlar.php #pkl-mail) ===\n";
rol($ADMIN, true);
$r = sayfa('frugro_ayarlar.php', [], ['csrf' => csrf(), 'islem' => 'pkl_mail', 'kime' => "siparis@ornek.test\nhatali-adres", 'bilgi' => '', 'govde' => '', 'evrak_govde' => '']);
ok('geçersiz e-posta reddedilir (kayıt yok)', ($_SESSION['flash']['type'] ?? '') === 'error' && frgd_mail_ayar_oku($db)['kime'] === []);
$r = sayfa('frugro_ayarlar.php', [], ['csrf' => csrf(), 'islem' => 'pkl_mail', 'kime' => 'Siparis@Ornek.test', 'bilgi' => "packinglist@ornek.test, lojistik@ornek.test", 'govde' => "Hi,\nPKL attached.\n{ad}", 'evrak_govde' => '']);
$ay = frgd_mail_ayar_oku($db);
ok('kaydedildi (küçük harf, 1 Kime + 2 Bilgi), PRG #pkl-mail', $ay['kime'] === ['siparis@ornek.test'] && count($ay['bilgi']) === 2 && $r['git'] === 'frugro_ayarlar.php#pkl-mail', json_encode($ay));
ok('audit yalnız SAYI (adres yok)', say($db, "SELECT COUNT(*) FROM audit_log WHERE action='frugro_mail_alici_ayar' AND new_values LIKE '%kime_sayisi\":1%'") === 1 && say($db, "SELECT COUNT(*) FROM audit_log WHERE new_values LIKE '%ornek.test%'") === 0);
rol($OPER);
$r = sayfa('frugro_ayarlar.php');
ok('operatör (siparis.delete yok): ayar sayfası 403', $r['hata'] !== null && str_contains($r['hata'], 'forbidden'));
rol($ADMIN, true);
$r = sayfa('frugro_ayarlar.php');
ok('ayar sayfası: Mail ayarları bölümü + kayıtlı adres', temiz($r) && str_contains($r['html'], 'id="pkl-mail"') && str_contains($r['html'], 'siparis@ornek.test'), (string)$r['hata']);

echo "\n=== 5. .eml ucu ===\n";
// ID_P'ye gerçek Invoice üret (eksik ayar onaylı; sentetik)
$db->prepare("UPDATE frugro_siparisler SET durum='pkl_hazir' WHERE id=?")->execute([$ID_P]);
$inv = frgi_uret($db, $ID_P, 1, ['no' => 'TST2026000001', 'tarih' => date('Y-m-d'), 'para_birimi' => 'GBP', 'fiyat' => '5,20'], true);
ok('test kurulumu: Invoice üretildi', $inv['ok'] === true, json_encode($inv, JSON_UNESCAPED_UNICODE));
$r = sayfa('frugro_eml.php', ['id' => $ID_P, 'tur' => 'pkl', 'govde' => "Merhaba\r\nSubject: enjekte\nTest"]);
$e = eml_coz($r['html']);
ok('eml: 200, message/rfc822, no-store (siparis_no_store), nosniff, .eml adı', $r['kod'] === 200 && ($r['h']['content-type'] ?? '') === 'message/rfc822' && str_contains((string)file_get_contents($ROOT . '/frugro_eml.php'), 'siparis_no_store();') && ($r['h']['x-content-type-options'] ?? '') === 'nosniff' && str_ends_with((string)($r['h']['content-disposition'] ?? ''), '.eml"'), json_encode([$r['kod'], $r['h'], $r['hata']]));
ok('eml: X-Unsent: 1, From YOK', ($e['h']['x-unsent'] ?? '') === '1' && !isset($e['h']['from']));
ok('eml: To/Cc ayardan', ($e['h']['to'] ?? '') === 'siparis@ornek.test' && ($e['h']['cc'] ?? '') === 'packinglist@ornek.test, lojistik@ornek.test', json_encode($e['h']));
ok('eml: konu = PKL dosya adı (uzantısız), başlık enjeksiyonu yok', str_starts_with((string)($e['h']['subject'] ?? ''), 'PO 26-5000-PCK. LIST-ASYA FRESH-FRUGRO') && !isset($e['h']['subject ']) && substr_count($r['html'], "\r\nSubject:") === 1);
$ekler = array_values(array_filter($e['p'], static fn($p) => $p['ad'] !== ''));
ok('eml: TAM 2 ek, ikisi de PDF ve %PDF ile başlar (PKL + Invoice)', count($ekler) === 2 && $ekler[0]['tip'] === 'application/pdf' && $ekler[1]['tip'] === 'application/pdf'
    && str_starts_with($ekler[0]['govde'], '%PDF') && str_starts_with($ekler[1]['govde'], '%PDF') && str_starts_with($ekler[0]['ad'], 'PO 26-5000-PCK. LIST-') && str_starts_with($ekler[1]['ad'], 'PO 26-5000-INVOICE-'), json_encode(array_map(static fn($p) => [$p['tip'], $p['ad']], $e['p'])));
ok('eml: xlsx eki YOK', !preg_match('/spreadsheetml|\.xlsx/i', $r['html']));
ok('eml: düzenlenmiş metin (govde=) uygulandı, satır sonları temiz', str_contains($e['p'][0]['govde'], 'Merhaba') && str_contains($e['p'][0]['govde'], 'Test'));
ok('eml: audit frugro_eml yalnız sayılar (adres yok)', say($db, "SELECT COUNT(*) FROM audit_log WHERE action='frugro_eml' AND new_values LIKE '%\"ek\":2%' AND new_values NOT LIKE '%@%'") === 1);
ok('eml: yan etkisiz (durum aynı, pkl_uretildi olayı yok)', frg_getir($db, $ID_P)['durum'] === 'invoice_hazir' && say($db, "SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id=$ID_P AND tur='pkl_uretildi'") === 0);
$db->exec("DELETE FROM hks_kv WHERE anahtar = 'frugro_invoice_" . $ID_M . "'");
$r = sayfa('frugro_eml.php', ['id' => $ID_M, 'tur' => 'pkl']);
$e = eml_coz($r['html']);
ok('eml: Invoice üretilmemişse YALNIZ PKL PDF eki', count(array_filter($e['p'], static fn($p) => $p['ad'] !== '')) === 1);
$r = sayfa('frugro_eml.php', ['id' => $ID_M, 'tur' => 'evrak']);
$e = eml_coz($r['html']);
ok('eml evrak: ek YOK, X-Unsent, To ayardan, From yok', $r['kod'] === 200 && count($e['p']) === 1 && ($e['h']['x-unsent'] ?? '') === '1' && ($e['h']['to'] ?? '') === 'siparis@ornek.test' && !isset($e['h']['from']) && str_contains($e['p'][0]['govde'], 'DOCs'));
$r = sayfa('frugro_eml.php', ['id' => $ID_Y, 'tur' => 'evrak']);
ok('eml evrak: mail gönderilmemiş siparişte 409', $r['kod'] === 409);
$r = sayfa('frugro_eml.php', ['id' => $ID_Y, 'tur' => 'pkl']);
ok('eml pkl: paletsiz 409', $r['kod'] === 409);
rol($ADMIN, false, 1, ['DEPO1']);
$r = sayfa('frugro_eml.php', ['id' => $ID_D2, 'tur' => 'pkl']);
ok('eml: başka depo 404', $r['kod'] === 404 && !str_contains($r['html'], '%PDF'));
rol([]);
$r = sayfa('frugro_eml.php', ['id' => $ID_P]);
ok('eml: yetkisiz 403', $r['hata'] !== null && str_contains($r['hata'], 'forbidden'));
rol($ADMIN, true);
$db->exec("DELETE FROM hks_kv WHERE anahtar = 'frugro_pkl_mail_ayar'");
$r = sayfa('frugro_eml.php', ['id' => $ID_P, 'tur' => 'pkl']);
$e = eml_coz($r['html']);
ok('eml: ayar boşsa taslak alıcısız (To/Cc YOK) ama üretilir', $r['kod'] === 200 && !isset($e['h']['to']) && !isset($e['h']['cc']) && ($e['h']['x-unsent'] ?? '') === '1');
$r = sayfa('frugro_eml.php', ['id' => $ID_P], ['x' => '1']);
ok('eml: GET dışı 405', $r['kod'] === 405);

echo "\n=== 6. ZIP ucu ===\n";
$r = sayfa('frugro_zip.php', ['id' => $ID_P]);
$tmp = tempnam(sys_get_temp_dir(), 'frgdz'); file_put_contents($tmp, $r['html']);
$z = new ZipArchive(); $acik = $z->open($tmp) === true; $adlar = [];
if ($acik) { for ($i = 0; $i < $z->numFiles; $i++) $adlar[] = $z->getNameIndex($i); }
ok('zip: 200, application/zip, no-store, nosniff, Frugro-<po>-Evrak.zip', $r['kod'] === 200 && ($r['h']['content-type'] ?? '') === 'application/zip' && str_contains((string)file_get_contents($ROOT . '/frugro_zip.php'), 'siparis_no_store();') && ($r['h']['x-content-type-options'] ?? '') === 'nosniff' && str_contains((string)($r['h']['content-disposition'] ?? ''), 'Frugro-26-5000-00002-Evrak.zip'), json_encode([$r['kod'], $r['h'], $r['hata']]));
ok('zip: 2 dosya (PKL PDF + Invoice PDF), ikisi de %PDF; xlsx YOK', $acik && count($adlar) === 2 && $adlar[0] === 'PO 26-5000-PCK. LIST-ASYA FRESH-FRUGRO.pdf' && $adlar[1] === 'PO 26-5000-INVOICE-ASYA FRESH-FRUGRO.pdf'
    && str_starts_with((string)$z->getFromIndex(0), '%PDF') && str_starts_with((string)$z->getFromIndex(1), '%PDF') && !preg_grep('/\.xlsx$/', $adlar), json_encode($adlar));
if ($acik) $z->close(); @unlink($tmp);
ok('zip: geçici dosya bırakılmadı', (glob(sys_get_temp_dir() . '/frgzip*') ?: []) === []);
$r = sayfa('frugro_zip.php', ['id' => $ID_Y]);
ok('zip: paletsiz 409', $r['kod'] === 409);
rol($ADMIN, false, 1, ['DEPO1']);
$r = sayfa('frugro_zip.php', ['id' => $ID_D2]);
ok('zip: başka depo 404', $r['kod'] === 404);
rol($ADMIN, true);

echo "\n=== 7. PKL GET indirme (detay bağlantıları) ===\n";
$once = say($db, "SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id=$ID_M");
$r = sayfa('frugro_pkl.php', ['id' => $ID_M, 'indir' => 'pdf']);
ok('PKL PDF GET: %PDF, attachment, no-store', str_starts_with($r['html'], '%PDF') && str_starts_with((string)($r['h']['content-disposition'] ?? ''), 'attachment') && str_contains($r['h']['cache-control'] ?? '', 'no-store'));
$r = sayfa('frugro_pkl.php', ['id' => $ID_M, 'indir' => 'xlsx']);
ok('PKL Excel GET: xlsx (PK zip imzası)', str_starts_with($r['html'], 'PK'));
ok('PKL GET indirme yan etkisiz (olay yazmaz)', say($db, "SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id=$ID_M") === $once);
$r = sayfa('frugro_pkl.php', ['id' => $ID_Y, 'indir' => 'pdf']);
ok('PKL GET paletsiz: detaya #pkl yönlendirir + flash hata', $r['git'] === 'frugro_view.php?id=' . $ID_Y . '#pkl' && ($_SESSION['flash']['type'] ?? '') === 'error');

echo "\n=== 8. Sil (⋮ → pencere, form-POST) ===\n";
rol($OPER);
$r = form(['islem' => 'sil', 'id' => $ID_Y, 'onay' => '1']);
ok('operatör silemez (require_siparis delete → 403)', $r['hata'] !== null && frg_getir($db, $ID_Y) !== null);
rol($ADMIN, true);
$r = form(['islem' => 'sil', 'id' => $ID_Y]);
ok('sil: onaysız reddedilir', frg_getir($db, $ID_Y) !== null && ($_SESSION['flash']['type'] ?? '') === 'error');
$r = form(['islem' => 'sil', 'id' => $ID_Y, 'onay' => '1']);
ok('sil: onayla soft silme → listeye PRG + audit', frg_getir($db, $ID_Y) === null && $r['git'] === 'frugro_siparis.php' && say($db, "SELECT COUNT(*) FROM audit_log WHERE action='delete' AND module='frugro_siparis'") === 1);

siparis_test_bitir();
