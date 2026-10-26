<?php
// =========================================================
// scripts/siparis_invoice_ui_smoke.php — OGL Invoice SAYFALARI (v442): siparis_invoice.php + siparis_view.php kartı + siparis_ayarlar.php kartı
//   php scripts/siparis_invoice_ui_smoke.php
// Bellek içi SQLite + stub auth + SENTETİK fixture'lar (siparis_ui_render.php::siparis_ui_tohum; kişisel veri / gerçek IBAN-REX YOK).
// Sayfalar gerçekten çalıştırılır (header()/exit kaynak üzerinde yakalanır). Gerçek config/siparis_invoice.php fonksiyonları kullanılır.
// Canlı DB'ye ve storage/siparis'e HİÇ dokunmaz. Tarayıcı düzeni: node scripts/siparis_invoice_ui_smoke.js (SIPARIS_INV_UI_OUT).
// =========================================================
if (in_array('--csrf', $argv ?? [], true)) {            // alt süreç: CSRF'siz POST → reddedilmeli
    require_once __DIR__ . '/siparis_ui_render.php';
    $dosya = (string)$argv[array_search('--csrf', $argv) + 1];
    $db = db(); siparis_ui_tohum($db);
    $db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
    $IS_ADMIN = true; $UID = 1; $PERMS = ['siparis.read', 'siparis.write', 'siparis.delete'];
    ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
    $once = (int)$db->query("SELECT COUNT(*) FROM siparis_belgeler WHERE tur = 'invoice'")->fetchColumn();
    $_POST = ['islem' => 'uret', 'invoice_ayar' => '1', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '1,37', 'iskonto' => '4', 'eksik_izin' => '1'];
    $_GET = ['id' => '1']; $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    $src = file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $tmp = sys_get_temp_dir() . '/sip_inv_csrf_' . getmypid() . '.php'; file_put_contents($tmp, $src);
    include $tmp;
    ob_get_clean();
    echo "CSRF-GECTI\n";      // buraya ulaşıldıysa reddedilmedi
    exit(0);
}
require_once __DIR__ . '/siparis_ui_render.php';        // siparis_ui_tohum() + _siparis_test_lib (render betiği require edilince yalnız tanım yapar)
require_once dirname(__DIR__) . '/config/siparis_invoice.php';

$ROOT = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$db = db();

class SipExit extends RuntimeException {}
$SIPT = ['h' => [], 'kod' => 200];
function sipt_header(string $h, bool $replace = true, int $code = 0): void {
    global $SIPT;
    if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $SIPT['h'][strtolower(trim($a))] = trim($b); }
    if ($code > 0) $SIPT['kod'] = $code;
}
function sipt_code(?int $c = null) { global $SIPT; if ($c !== null) $SIPT['kod'] = $c; return $SIPT['kod']; }
function sip_calistir(string $tmp): void { include $tmp; }
/** @return array{html:string,h:array,kod:int,git:?string,hata:?string,flash:?array} */
function sayfa(string $dosya, array $get = [], array $post = [], array $files = []): array
{
    global $ROOT, $SIPT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sipt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sipt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SipExit();', $src);
    $tmp = sys_get_temp_dir() . '/sip_inv_ui_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $SIPT = ['h' => [], 'kod' => 200];
    $_GET = $get; $_POST = $post; $_FILES = $files;
    $_SERVER['REQUEST_METHOD'] = $post || $files ? 'POST' : 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya;
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['flash']);
    ob_start();
    $hata = null;
    try { sip_calistir($tmp); }
    catch (SipExit $e) { /* normal çıkış */ }
    catch (Throwable $e) { $hata = $e->getMessage(); }
    $html = (string)ob_get_clean();
    @unlink($tmp);
    return ['html' => $html, 'h' => $SIPT['h'], 'kod' => $SIPT['kod'], 'git' => $SIPT['h']['location'] ?? null, 'hata' => $hata, 'flash' => $_SESSION['flash'] ?? null];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null): void
{
    global $PERMS, $IS_ADMIN, $UID, $DEPOLAR;
    $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar;
}
function csrf(): string { if (session_status() === PHP_SESSION_NONE) session_start(); return csrf_token(); }
function html_dengeli(string $h): ?string
{
    $h = preg_replace('#<(script|style|textarea)\b.*?</\1>#si', '', $h);
    $h = preg_replace('/<!--.*?-->/s', '', $h);
    $void = ['meta', 'link', 'br', 'hr', 'img', 'input', 'source', 'col', 'area', 'base', 'wbr', 'path', 'circle', 'rect'];
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
function temiz(array $r): bool { return $r['hata'] === null && !preg_match('/\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b:/', $r['html']); }
function say(PDO $db, string $t, string $w = '1=1', array $p = []): int { return siparis_test_say($db, $t, $w, $p); }
/** Metinli basit PDF (e-fatura simülasyonu): her satır ayrı Tj. */
function mini_pdf(array $satirlar): string
{
    $ic = "BT /F1 12 Tf 14 TL 50 780 Td\n";
    foreach ($satirlar as $s) $ic .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s) . ") Tj T*\n";
    $ic .= 'ET';
    $o = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        4 => '<< /Length ' . strlen($ic) . " >>\nstream\n$ic\nendstream", 5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $pdf = "%PDF-1.4\n"; $off = [];
    foreach ($o as $n => $b) { $off[$n] = strlen($pdf); $pdf .= "$n 0 obj\n$b\nendobj\n"; }
    $x = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($off as $f) $pdf .= sprintf("%010d 00000 n \n", $f);
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
}
function yukle_dizisi(string $icerik, string $ad): array
{
    $t = sys_get_temp_dir() . '/sipinv_' . bin2hex(random_bytes(4)); file_put_contents($t, $icerik);
    return ['efatura' => ['name' => $ad, 'type' => 'application/pdf', 'tmp_name' => $t, 'error' => UPLOAD_ERR_OK, 'size' => strlen($icerik)]];
}

$ADMIN = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'];
$OPER  = ['siparis.read', 'siparis.write', 'siparis.match', 'beyan.read'];
$VIEW  = ['siparis.read'];

echo "=== 1. Tohum + PKL (brüt 812 / net 722 kg ortak değer) ===\n";
$ID = siparis_ui_tohum($db);
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
$S = $ID['s1'];                                                     // PKL'li, plakalı, Loading List'li sevkiyat
$d0 = siparis_pkl_durum($db, $S); $v0 = $d0['veri'];
$v0['varsayilan']['M'] = '812'; $v0['varsayilan']['N'] = '722';
siparis_pkl_kaydet_taslak($db, $S, $v0, [], 1);
$pr = siparis_pkl_uret($db, $S, 1, true);
ok('PKL (net dolu) üretildi', !empty($pr['ok']), json_encode($pr));
$ozet = siparis_inv_varsayilan($db, siparis_sevkiyat($db, $S))['ozet'];
$NET = (float)$ozet['net_kg'];
ok('özet: PKL kaynaklı, net > 0', $ozet['kaynak'] === 'pkl' && $NET > 0, json_encode($ozet));
$NETS = number_format($NET, 0, ',', '.');
rol($ADMIN, true);
$tok = csrf();

echo "\n=== 2. Sayfa (GET, yönetici) ===\n";
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('sayfa temiz (uyarı yok) + HTML dengeli', temiz($g) && html_dengeli($g['html']) === null, (string)$g['hata'] . ' ' . (string)html_dengeli($g['html']));
$h = $g['html'];
ok('başlık "Invoice (OGL)" + durum Yok + geri bağlantı #invoice', str_contains($h, 'Invoice (OGL)') && str_contains($h, 'sip-inv-durum--yok') && str_contains($h, 'siparis_view.php?id=' . $S . '#invoice'));
ok('form: multipart + csrf + 5 alan + e-fatura girdisi', str_contains($h, 'enctype="multipart/form-data"') && str_contains($h, 'name="csrf"') && preg_match('/name="no"/', $h) && preg_match('/name="tarih"/', $h)
    && preg_match('/name="fiyat"/', $h) && preg_match('/name="iskonto"/', $h) && preg_match('/name="efatura_toplam"/', $h) && str_contains($h, 'name="efatura"'));
ok('menşe beyanı (REX) bilgi satırı HER durumda + REX boş → kırmızı uyarı', str_contains($h, 'Menşe beyanı (REX) her invoice') && str_contains($h, 'REX numarası ayarlı değil') && str_contains($h, '______'));
ok('ayar eksik → "eksik bilgiyle oluştur" onay kutusu (zorunlu)', (bool)preg_match('/<input type="checkbox" name="eksik_izin" value="1" required/', $h));
ok('özet: PO, palet, koli, net kg tam sayı biçimli (binlik noktalı), kalibre, plaka, teslim şekli', str_contains($h, 'TC900001.10') && str_contains($h, $NETS) && str_contains($h, '14') && str_contains($h, '34TST777') && str_contains($h, 'INCOTERMS 2020 DAP'));
ok('JS önizleme kancaları: data-sip-inv + net + giriş kancaları (fiyat/iskonto/net/brüt/e-fatura)', str_contains($h, 'data-sip-inv ') && preg_match('/data-sip-inv-net="[0-9.]+"/', $h) && substr_count($h, 'data-sip-inv-giris=') === 5);
ok('Net / Brüt ağırlık alanları: placeholder = PKL toplamı, ipucu "PKL: … · nominal: …"', str_contains($h, 'name="net_kg"') && str_contains($h, 'name="brut_kg"') && str_contains($h, 'placeholder="' . $NETS . '"') && str_contains($h, 'PKL: ' . $NETS) && str_contains($h, 'nominal:') && str_contains($h, 'boş = PKL'));
ok('kontrol: eksik alanlar "✎ Doldurun" (Oluştur\'u kilitlemez), Oluştur etkin, pasif-neden yok', str_contains($h, '✎ Doldurun') && str_contains($h, 'name="islem" value="uret"') && !str_contains($h, 'sipInvNeden'));
$govde = substr($h, (int)strpos($h, 'class="sip-mod sip-inv-sayfa"'));   // sayfa gövdesi (üst çubuk/layout hariç)
ok('sayfa gövdesinde satır içi olay (onclick…) yok; e-fatura + kontrol düğmesi formnovalidate', !preg_match('/\son(click|submit|change|input)=/i', $govde) && substr_count($govde, 'formnovalidate') >= 2);
ok('invoice yokken: PDF önizleme / indir YOK', !str_contains($h, 'data-sip-inv-onizle') && !str_contains($h, '⬇ İndir'));
ok('hesap kutusu: fiyat yokken "—" (sunucu başlangıç değeri)', (bool)preg_match('/data-inv-toplam>—<\/strong>/', $h));

echo "\n=== 3. Yetki / depo / bulunamadı ===\n";
rol($VIEW, false);
$v = sayfa('siparis_invoice.php', ['id' => $S]);
ok('izleyici: sayfa açılır, form alanları devre dışı (fieldset disabled), yazan düğme yok', temiz($v) && str_contains($v['html'], '<fieldset class="sip-fieldset" disabled>') && !str_contains($v['html'], 'name="islem" value="uret"')
    && !str_contains($v['html'], 'value="efatura"') && str_contains($v['html'], 'Salt okunur görünüm'));
$p = sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'uret', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '1,37', 'iskonto' => '4', 'eksik_izin' => '1']);
ok('izleyici POST → 403 (forbidden), invoice YAZILMADI', str_starts_with((string)$p['hata'], 'forbidden') && say($db, 'siparis_belgeler', "tur = 'invoice'") === 0, (string)$p['hata']);
rol($ADMIN, true);
$yok = sayfa('siparis_invoice.php', ['id' => 999999]);
ok('olmayan sevkiyat → "Sevkiyat bulunamadı" sayfası (404 sayfası; kod _siparis_ortak.php içinde basılır)', str_contains($yok['html'], 'Sevkiyat bulunamadı') && !str_contains($yok['html'], 'sipInvForm'));
rol($ADMIN, true, 1, ['DEPO1']);
$d2 = sayfa('siparis_invoice.php', ['id' => $ID['s31']]);
ok('başka depodaki sevkiyat: olmayanla AYNI sayfa (varlık sızmaz)', !str_contains($d2['html'], 'sipInvForm') && str_contains($d2['html'], 'Sevkiyat bulunamadı') && !str_contains($d2['html'], 'DEPO2'));
rol($ADMIN, true);

echo "\n=== 4. POST: CSRF ===\n";
$php = escapeshellarg(PHP_BINARY);
$cikti = shell_exec("$php " . escapeshellarg(__FILE__) . ' --csrf siparis_invoice.php 2>&1') ?: '';
ok('CSRF\'siz POST reddedilir (siparis_invoice.php)', !str_contains($cikti, 'CSRF-GECTI'), substr($cikti, 0, 200));
$cikti = shell_exec("$php " . escapeshellarg(__FILE__) . ' --csrf siparis_ayarlar.php 2>&1') ?: '';
ok('CSRF\'siz POST reddedilir (siparis_ayarlar.php invoice_ayar)', !str_contains($cikti, 'CSRF-GECTI'), substr($cikti, 0, 200));

echo "\n=== 5. Kontrol / hatalı gönderim (PRG, tek kullanımlık) ===\n";
$post = ['csrf' => $tok, 'islem' => 'kontrol', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '1,37', 'iskonto' => '4', 'efatura_toplam' => '19000,00'];
$k = sayfa('siparis_invoice.php', ['id' => $S], $post);
ok('kontrol: 302 → #kontrol, hiçbir şey yazılmadı', $k['hata'] === null && str_contains((string)$k['git'], 'siparis_invoice.php?id=' . $S . '#kontrol') && say($db, 'siparis_belgeler', "tur = 'invoice'") === 0);
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('kontrol sonrası GET: yazılan değerler formda, e-fatura farkı uyarısı, sunucu hesabı (19.782,80 / 791,31 / 18.991,49)', temiz($g) && str_contains($g['html'], 'value="IHR2026000000999"') && str_contains($g['html'], 'value="1,37"')
    && str_contains($g['html'], 'E-fatura toplamı') && str_contains($g['html'], '19.782,80') && str_contains($g['html'], '791,31') && str_contains($g['html'], '18.991,49'), (string)$g['hata']);
ok('IHR biçimi doğruysa "e-fatura biçiminde değil" uyarısı YOK', !str_contains($g['html'], 'e-fatura biçiminde değil'));
ok('tek kullanımlık: sonraki GET varsayılan (boş fiyat)', !str_contains(sayfa('siparis_invoice.php', ['id' => $S])['html'], 'value="IHR2026000000999"'));
$kotu = sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'uret', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '', 'iskonto' => '4', 'eksik_izin' => '1']);
ok('uret: fiyat eksik → #bilgiler\'e dön, hata bayrağı, belge YOK', str_contains((string)$kotu['git'], '#bilgiler') && ($kotu['flash']['type'] ?? '') === 'error' && say($db, 'siparis_belgeler', "tur = 'invoice'") === 0);
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('alan hatası fiyat alanının altında (role=alert) + aria-invalid', str_contains($g['html'], 'Birim fiyat (€/kg) gerekli') && str_contains($g['html'], 'aria-invalid="true"'));

echo "\n=== 5b. Net / Brüt override (e-fatura nominal net) ===\n";
$ov = sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'kontrol', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '1,37', 'iskonto' => '4', 'net_kg' => '10.000', 'brut_kg' => '11.000']);
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('net 10.000 × 1,37 = 13.700,00; %4 = 548,00; toplam 13.152,00 (sunucu hesabı override\'ı kullanır)', temiz($g) && str_contains($g['html'], '13.700,00') && str_contains($g['html'], '548,00') && str_contains($g['html'], '13.152,00'), (string)$g['hata']);
ok('özet efektif net/brüt "(elle; PKL …)" + net_elle bilgi satırı', str_contains($g['html'], '10.000 (elle; PKL ' . $NETS . ')') && str_contains($g['html'], '11.000 (elle; PKL ') && str_contains($g['html'], 'elle girildi (10.000 kg'));
sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'kontrol', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '1,37', 'net_kg' => '12.5', 'brut_kg' => '']);
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('net tam sayı değilse alan hatası (Oluştur\'u kilitlemez, ✎ Doldurun)', str_contains($g['html'], 'tam sayı kg olmalıdır') && str_contains($g['html'], '✎ Doldurun'));
sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'kontrol', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '1,37', 'net_kg' => '20000', 'brut_kg' => '15000']);
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('brüt < net → kırmızı engel + Oluştur PASİF (sebep yazılı)', str_contains($g['html'], 'net ağırlıktan') && str_contains($g['html'], 'aria-describedby="sipInvNeden"') && !str_contains($g['html'], 'name="islem" value="uret"'));

echo "\n=== 6. PDF üretimi ===\n";
$uret = ['csrf' => $tok, 'islem' => 'uret', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '1,37', 'iskonto' => '4'];
$x = sayfa('siparis_invoice.php', ['id' => $S], $uret);
ok('ayar eksik + onay kutusu işaretsiz → reddedilir (REX/banka), belge YOK', ($x['flash']['type'] ?? '') === 'error' && str_contains((string)($x['flash']['msg'] ?? $x['flash']['message'] ?? json_encode($x['flash'])), 'eksik') && say($db, 'siparis_belgeler', "tur = 'invoice'") === 0, json_encode($x['flash']));
$x = sayfa('siparis_invoice.php', ['id' => $S], $uret + ['eksik_izin' => '1']);
$nInv = say($db, 'siparis_belgeler', "tur = 'invoice'");
ok('eksik izniyle üretildi: 302 → #uretim, success, 1 invoice belgesi, sevkiyata bağlı', $x['hata'] === null && ($x['flash']['type'] ?? '') === 'success' && str_contains((string)$x['git'], '#uretim') && $nInv === 1
    && say($db, 'siparis_belge_bag', "gecerli = 1 AND sevkiyat_id = $S AND belge_id IN (SELECT id FROM siparis_belgeler WHERE tur = 'invoice')") === 1, json_encode($x['flash']));
$bid = (int)$db->query("SELECT id FROM siparis_belgeler WHERE tur = 'invoice'")->fetchColumn();
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('GET: durum Hazır, dosya adı INV-…pdf, İndir + yeni sekmede aç + önizleme (iframe data-src), kayıtlı girdi forma döner', temiz($g) && str_contains($g['html'], 'sip-inv-durum--hazir') && str_contains($g['html'], 'INV-TC900001.10-')
    && str_contains($g['html'], 'siparis_dosya.php?id=' . $bid . '&amp;dl=1') && str_contains($g['html'], 'data-src="siparis_dosya.php?id=' . $bid . '"') && str_contains($g['html'], 'value="IHR2026000000999"') && str_contains($g['html'], 'value="1,37"')
    && str_contains($g['html'], '↻ Invoice PDF yeniden oluştur'), (string)$g['hata']);
$f = sayfa('siparis_dosya.php', ['id' => $bid]);
ok('siparis_dosya.php PDF\'i satır içi sunar (%PDF, frame-ancestors self → iframe önizleme çalışır)', str_starts_with($f['html'], '%PDF-') && str_contains($f['h']['content-security-policy'] ?? '', "frame-ancestors 'self'") && str_contains($f['h']['content-disposition'] ?? '', 'inline'), json_encode($f['h']));
$x2 = sayfa('siparis_invoice.php', ['id' => $S], $uret + ['eksik_izin' => '1', 'fiyat' => '1,40']);
ok('yeniden üretim: önceki invoice eski sürüm (gecerli=0), yeni geçerli, "2. üretim"', say($db, 'siparis_belgeler', "tur = 'invoice'") === 2 && say($db, 'siparis_belge_bag', "gecerli = 1 AND belge_id IN (SELECT id FROM siparis_belgeler WHERE tur = 'invoice')") === 1
    && str_contains(sayfa('siparis_invoice.php', ['id' => $S])['html'], '2. üretim'));
$au = siparis_test_audit($db);
ok('audit siparis_invoice_uret yazıldı; REX/IBAN değeri audit\'e girmedi', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_invoice_uret'")->fetchColumn() === 2 && !preg_match('/iban|"rex_no"/i', (string)$db->query("SELECT GROUP_CONCAT(new_values) FROM audit_log WHERE action = 'siparis_invoice_uret'")->fetchColumn()));
// motor engeli: net 0 → Oluştur pasif + sebep (s11'in PKL'i kısmi, M/N boş)
$k0 = sayfa('siparis_invoice.php', ['id' => $ID['s11']]);
ok('net 0 (PKL M/N boş) → kırmızı engel + Oluştur PASİF + sip-pasif-neden (aria-describedby)', temiz($k0) && str_contains($k0['html'], 'sip-ok-hata') && str_contains($k0['html'], 'Net kg 0') && str_contains($k0['html'], 'aria-describedby="sipInvNeden"') && str_contains($k0['html'], 'id="sipInvNeden"')
    && !str_contains($k0['html'], 'name="islem" value="uret"'));
$px = sayfa('siparis_invoice.php', ['id' => $ID['s11']], $uret + ['eksik_izin' => '1']);
ok('engel varken POST uret de reddedilir (sunucu tarafı), belge eklenmedi', ($px['flash']['type'] ?? '') === 'error' && say($db, 'siparis_belgeler', "tur = 'invoice'") === 2);

echo "\n=== 7. e-fatura yükleme ===\n";
$ef1 = sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'efatura', 'fiyat' => '1,50'], yukle_dizisi(siparis_test_pdf('ef-resim'), 'efatura_resim.pdf'));
ok('görüntü-yalnız PDF: yüklendi (tur efatura, sevkiyata bağlı), 302 → #bilgiler', $ef1['hata'] === null && str_contains((string)$ef1['git'], '#bilgiler') && say($db, 'siparis_belgeler', "tur = 'efatura'") === 1, json_encode([$ef1['hata'], $ef1['flash']]));
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('metin yok → "görüntü olduğundan değerler okunamadı" notu, yüklü e-fatura bağlantısı', str_contains($g['html'], 'Bu PDF görüntü olduğundan değerler okunamadı; aşağıya elle girin.') && str_contains($g['html'], 'Yüklü e-fatura:') && str_contains($g['html'], 'efatura_resim.pdf'));
ok('metin yok: yazılan fiyat korunur (formdaki değer kaybolmaz)', str_contains($g['html'], 'value="1,50"'));
$efm = sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'efatura'], yukle_dizisi(mini_pdf(['Fatura Tarihi: 05.10.2026', 'IHR2026000000998', 'Birim Fiyati 1,37', 'Iskonto Orani 4', 'Odenecek Tutar 23.936,64']), 'efatura_metin.pdf'));
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('metin katmanı var → alanlar forma ön-doldurulur (no/tarih/fiyat/iskonto/toplam) + "e-faturadan okundu — kontrol edin" rozeti', temiz($g) && str_contains($g['html'], 'value="IHR2026000000998"') && str_contains($g['html'], 'value="2026-10-05"') && str_contains($g['html'], 'value="1,37"')
    && str_contains($g['html'], 'value="23936,64"') && substr_count($g['html'], 'e-faturadan okundu — kontrol edin') >= 5, (string)$g['hata'] . json_encode($efm['flash']));
$bos = sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'efatura']);
ok('dosya seçilmeden yükle → hata mesajı, belge eklenmedi', ($bos['flash']['type'] ?? '') === 'error' && say($db, 'siparis_belgeler', "tur = 'efatura'") === 2);
$kotuEf = sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'efatura'], yukle_dizisi('bu pdf degil', 'sahte.pdf'));
ok('PDF olmayan dosya reddedilir', ($kotuEf['flash']['type'] ?? '') === 'error' && say($db, 'siparis_belgeler', "tur = 'efatura'") === 2, json_encode($kotuEf['flash']));

echo "\n=== 8. siparis_view.php — #invoice kartı ===\n";
$vw = sayfa('siparis_view.php', ['id' => $S]);
ok('kart PKL kartından SONRA, durum Hazır, invoice no + dosya + indir + "düzenle / yeniden oluştur"', temiz($vw) && strpos($vw['html'], 'id="invoice"') > strpos($vw['html'], 'id="pkl"') && str_contains($vw['html'], 'sip-inv-durum--hazir') && str_contains($vw['html'], 'IHR2026000000999')
    && str_contains($vw['html'], 'INV-TC900001.10-') && str_contains($vw['html'], '🧾 Invoice düzenle / yeniden oluştur') && str_contains($vw['html'], 'siparis_invoice.php?id=' . $S) && str_contains($vw['html'], 'siparis_dosya.php?id='), (string)$vw['hata']);
ok('belgeler listesinde Invoice + e-Fatura grupları ve rozetleri', str_contains($vw['html'], 'sip-tur--invoice') && str_contains($vw['html'], 'sip-tur--efatura'));
$nopkl = sayfa('siparis_view.php', ['id' => $ID['s99']]);
ok('PKL yoksa: "Önce PKL oluşturun" + düğme PASİF (sebep yazılı), hazırla bağlantısı yok', temiz($nopkl) && str_contains($nopkl['html'], 'id="invoice"') && str_contains($nopkl['html'], 'Önce PKL oluşturun ya da yükleyin.') && !str_contains($nopkl['html'], 'siparis_invoice.php?id=' . $ID['s99']));
$bosKart = sayfa('siparis_view.php', ['id' => $ID['s11']]);
ok('PKL var, invoice yok: "Invoice hazırla" bağlantısı + Yok rozeti', str_contains($bosKart['html'], '🧾 Invoice hazırla') && str_contains($bosKart['html'], 'sip-inv-durum--yok'));
rol($VIEW, false);
$vv = sayfa('siparis_view.php', ['id' => $S]);
ok('yalnız-okuma: görüntüle + indir var, hazırla/yeniden oluştur YOK', str_contains($vv['html'], 'Invoice’u görüntüle') && str_contains($vv['html'], 'siparis_dosya.php?id=') && !str_contains($vv['html'], '🧾 Invoice hazırla') && !str_contains($vv['html'], 'yeniden oluştur'));
$vv2 = sayfa('siparis_view.php', ['id' => $ID['s11']]);
ok('yalnız-okuma + invoice yok: yetki notu, düğme yok', str_contains($vv2['html'], 'Invoice hazırlamak için siparis.write yetkisi gerekir.') && !str_contains($vv2['html'], '🧾 Invoice hazırla'));
rol($ADMIN, true);

echo "\n=== 9. siparis_ayarlar.php — Invoice ayarları ===\n";
$ay = sayfa('siparis_ayarlar.php');
ok('kart #invoice-ayar + 8 alan + CSRF', temiz($ay) && str_contains($ay['html'], 'id="invoice-ayar"') && preg_match_all('/name="inv_(rex_no|iskonto|banka_ad|banka_adres|sube|swift|iban_eur|teslim)"/', $ay['html']) === 8 && str_contains($ay['html'], 'value="invoice_ayar"'));
$A = ['csrf' => $tok, 'islem' => 'invoice_ayar', 'inv_rex_no' => 'TRREX0000SYN', 'inv_banka_ad' => 'SENTETIK BANKASI', 'inv_banka_adres' => 'Ornek Cad. 1', 'inv_sube' => 'MERKEZ', 'inv_swift' => 'SYNTTRIS', 'inv_iban_eur' => 'TR000000000000000000000000', 'inv_teslim' => 'INCOTERMS 2020 DAP', 'inv_iskonto' => '4'];
$ak = sayfa('siparis_ayarlar.php', [], $A);
ok('ayar kaydedildi (302 #invoice-ayar, başarı) — REX/IBAN değeri flash\'a girmez', str_contains((string)$ak['git'], '#invoice-ayar') && ($ak['flash']['type'] ?? '') === 'success' && !str_contains(json_encode($ak['flash']), 'TRREX0000SYN') && !str_contains(json_encode($ak['flash']), 'TR0000'), json_encode($ak['flash']));
$ay = sayfa('siparis_ayarlar.php');
ok('kayıt sonrası değerler formda; varsayılan iskonto "4"', str_contains($ay['html'], 'value="TRREX0000SYN"') && str_contains($ay['html'], 'value="SENTETIK BANKASI"') && str_contains($ay['html'], 'name="inv_iskonto" value="4"'));
$kaud = (string)$db->query("SELECT GROUP_CONCAT(COALESCE(new_values,'')) FROM audit_log WHERE action = 'siparis_invoice_ayar'")->fetchColumn();
ok('audit yalnız alan ADLARI (REX/IBAN/banka DEĞERİ yok)', $kaud !== '' && !str_contains($kaud, 'TRREX0000SYN') && !str_contains($kaud, 'SENTETIK') && !str_contains($kaud, 'TR0000'));
$kt = sayfa('siparis_ayarlar.php', [], ['inv_iban_eur' => 'X'] + $A + ['inv_iban_eur' => 'abc']);
ok('geçersiz IBAN reddedilir (hata flash), eski ayar korunur', ($kt['flash']['type'] ?? '') === 'error' && str_contains(sayfa('siparis_ayarlar.php')['html'], 'value="TR000000000000000000000000"'));
rol($OPER, false);
$po = sayfa('siparis_ayarlar.php', [], $A + ['inv_rex_no' => 'DEGISTI']);
ok('siparis.delete yok → POST 403, ayar değişmedi', str_starts_with((string)$po['hata'], 'forbidden') && !str_contains(json_encode(siparis_inv_ayar_oku($db)), 'DEGISTI'));
$pv = sayfa('siparis_ayarlar.php');
ok('operatör (delete yok): kart durum rozetleri, REX/IBAN DEĞERİ ekranda YOK, form yok', str_contains($pv['html'], 'id="invoice-ayar"') && str_contains($pv['html'], 'sip-inv-ayar-durum') && !str_contains($pv['html'], 'TRREX0000SYN') && !str_contains($pv['html'], 'TR000000000000000000000000') && !str_contains($pv['html'], 'value="invoice_ayar"'));
rol($ADMIN, true);
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('ayar tamam → REX uyarısı ve eksik izni kutusu kalkar; ayarla üretim izinsiz de geçer', !str_contains($g['html'], 'REX numarası ayarlı değil') && !str_contains($g['html'], 'name="eksik_izin"'));
$x3 = sayfa('siparis_invoice.php', ['id' => $S], $uret);
ok('ayar tamam: eksik izni olmadan üretim başarılı', ($x3['flash']['type'] ?? '') === 'success' && say($db, 'siparis_belgeler', "tur = 'invoice'") === 3, json_encode($x3['flash']));

echo "\n=== 10. XSS kaçışı ===\n";
$xss = sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'kontrol', 'no' => '"><script>alert(1)</script>', 'tarih' => '2026-10-20', 'fiyat' => '1,37<b>', 'iskonto' => '4', 'efatura_toplam' => '</textarea>']);
$g = sayfa('siparis_invoice.php', ['id' => $S]);
ok('kullanıcı girdisi kaçırılır (script/etiket enjeksiyonu yok)', temiz($g) && !str_contains($g['html'], '<script>alert(1)') && !str_contains($g['html'], '1,37<b>') && str_contains($g['html'], '&quot;&gt;&lt;script&gt;'));

echo "\n=== 10b. Kontrol listesi genel (A'nın yeni kodları sayfada görünür) ===\n";
sayfa('siparis_invoice.php', ['id' => $S], ['csrf' => $tok, 'islem' => 'kontrol', 'no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '150', 'iskonto' => '4', 'net_kg' => '5000']);
$g = sayfa('siparis_invoice.php', ['id' => $S]);
$kodlar = array_column(siparis_inv_kontrol($db, siparis_sevkiyat($db, $S), ['no' => 'IHR2026000000999', 'tarih' => '2026-10-20', 'fiyat' => '150', 'iskonto' => '4', 'net_kg' => '5000'], siparis_inv_varsayilan($db, siparis_sevkiyat($db, $S))['ozet'], siparis_inv_ayar_oku($db)), 'mesaj', 'kod');
$gorulmeyen = [];
foreach (['fiyat_yuksek', 'net_sapma', 'net_elle'] as $kod) if (isset($kodlar[$kod]) && !str_contains($g['html'], htmlspecialchars((string)$kodlar[$kod], ENT_QUOTES, 'UTF-8'))) $gorulmeyen[] = $kod;
ok('fiyat_yuksek / net_sapma / net_elle (A kodları) sayfada kod bazlı özel durum olmadan listelenir', isset($kodlar['fiyat_yuksek']) && isset($kodlar['net_sapma']) && $gorulmeyen === [], json_encode([array_keys($kodlar), $gorulmeyen]));
ok('sayfada yalnız girdi_* için özel kod dalı var (diğer kodlar genel liste)', substr_count((string)file_get_contents($ROOT . '/siparis_invoice.php'), "'girdi_'") === 2 && !preg_match("/\['kod'\]\s*===\s*'(?!girdi)/", (string)file_get_contents($ROOT . '/siparis_invoice.php')));

echo "\n=== 11. Belge sayfası / yeniden işle / bağla — invoice + e-fatura korunur ===\n";
$invB = (int)$db->query("SELECT id FROM siparis_belgeler WHERE tur = 'invoice' ORDER BY id DESC LIMIT 1")->fetchColumn();
$efB = (int)$db->query("SELECT id FROM siparis_belgeler WHERE tur = 'efatura' ORDER BY id DESC LIMIT 1")->fetchColumn();
$poB = (int)$ID['po1'];
$bi = sayfa('siparis_belge.php', ['id' => $invB]);
ok('invoice belge sayfası: açılır, "Yeniden işle" düğmesi YOK, İndir var, tür rozeti (sip-tur--invoice)', temiz($bi) && !str_contains($bi['html'], 'value="yeniden_isle"') && !str_contains($bi['html'], '↻ Yeniden işle') && str_contains($bi['html'], 'dl=1') && str_contains($bi['html'], 'sip-tur--invoice'), (string)$bi['hata']);
$be = sayfa('siparis_belge.php', ['id' => $efB]);
ok('e-fatura belge sayfası: "Yeniden işle" YOK, rozet sip-tur--efatura', temiz($be) && !str_contains($be['html'], 'value="yeniden_isle"') && str_contains($be['html'], 'sip-tur--efatura'));
$bp = sayfa('siparis_belge.php', ['id' => $poB]);
ok('PO belge sayfası: "Yeniden işle" düğmesi KALIR (gerileme yok)', str_contains($bp['html'], 'value="yeniden_isle"'));
$turOnce = $db->query("SELECT id, tur, durum FROM siparis_belgeler WHERE id IN ($invB, $efB) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$bagOnce = say($db, 'siparis_belge_bag');
foreach ([[$invB, 'yeniden_isle'], [$efB, 'yeniden_isle'], [$invB, 'belge_bagla'], [$efB, 'belge_bagla']] as [$bidx, $isl]) {
    $r = sayfa('siparis_kaydet.php', [], ['csrf' => $tok, 'islem' => $isl, 'belge_id' => (string)$bidx, 'sevkiyat_id' => (string)$ID['s2']]);
    ok("$isl belge #$bidx: reddedilir (hata flash, açık mesaj)", ($r['flash']['type'] ?? '') === 'error' && str_contains(json_encode($r['flash'], JSON_UNESCAPED_UNICODE), 'yeniden işlenmez'), json_encode($r['flash'], JSON_UNESCAPED_UNICODE));
}
$turSonra = $db->query("SELECT id, tur, durum FROM siparis_belgeler WHERE id IN ($invB, $efB) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok('red sonrası belge türü/durumu ve bağlar DEĞİŞMEDİ (invoice hâlâ invoice, e-fatura hâlâ efatura)', $turOnce === $turSonra && say($db, 'siparis_belge_bag') === $bagOnce);
$db->exec("UPDATE siparis_belgeler SET durum = 'hata' WHERE id = $efB");
$ls = sayfa('siparisler.php');
ok('"Eşleşmemiş / hatalı belgeler" kutusu invoice / e-fatura belgesini LİSTELEMEZ (durum hata olsa bile)', !str_contains($ls['html'], 'efatura_resim.pdf') && !str_contains($ls['html'], 'INV-TC900001.10'));
$db->exec("UPDATE siparis_belgeler SET durum = 'islendi' WHERE id = $efB");
$vw = sayfa('siparis_view.php', ['id' => $S]);
ok('sevkiyat belge listesi: Invoice + e-Fatura grup başlıkları, rozetler, "⚠ N" uyarı rozeti bozulmadı', temiz($vw) && str_contains($vw['html'], '>Invoice</h3>') && str_contains($vw['html'], '>e-Fatura</h3>') && str_contains($vw['html'], 'sip-uy-say') && str_contains($vw['html'], 'sistemde üretildi'));

echo "\n";
siparis_test_bitir();
