<?php
// =========================================================
// scripts/hesap_izolasyon_smoke.php — Kişisel hesap izolasyonu regresyon testi
//
// SADECE CLI. Canlı veritabanına ve canlı uploads/ dizinine HİÇ dokunmaz:
// bellek içi SQLite + geçici yükleme klasörü. Gerçek sayfaları (hesap_*.php)
// require satırları sıyrılarak çalıştırır; header()/exit/http_response_code
// yakalanır.
//
//   php scripts/hesap_izolasyon_smoke.php   → çıkış kodu 0 = tüm testler geçti
//
// Profiller: A (uid 10) ve B (uid 20) operator · M (30) muhasebe (approve/pay)
// · Y (40) hesap.admin (is_admin DEĞİL) · S (50) süper admin (is_admin) ·
// V (60) yalnız hesap.read · 70 pasif kullanıcı.
//
// Depo stub'ı GERÇEĞE YAKIN (aktif depo DEPO1): izolasyonun depodan bağımsız
// olduğu ve DEPO2 kaydının kaybolmadığı böylece kanıtlanır.
//
// Her h02 bulgusu (K1-K4, Y1, Y2, Y4, O1-O4) burada bir regresyondur.
// Kendi kaydını onaylama (Y3) BİLEREK serbest (kullanıcı kararı K-3) — test
// bunun SERBEST kaldığını sabitler.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);

// ── Geçici yükleme klasörü ──
$TMPUP = sys_get_temp_dir() . '/hesap_izo_' . getmypid() . '/';
@mkdir($TMPUP, 0777, true);
define('HESAP_UPLOAD_DIR', $TMPUP);
$TMPPG = sys_get_temp_dir() . '/hesap_izo_pg_' . getmypid() . '/';
@mkdir($TMPPG, 0777, true);
register_shutdown_function(function () use ($TMPUP, $TMPPG) {
    foreach (array_merge(glob($TMPUP . '*') ?: [], glob($TMPPG . '*') ?: []) as $f) @unlink($f);
    @rmdir($TMPUP); @rmdir($TMPPG);
});

// ── Stub'lar ──
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
$PDO_TEST->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$UID = 10; $PERMS = []; $ADMIN = false;
$AUDIT = []; $FLASH = []; $HDR = []; $HRC = null; $UYARI = [];
function current_user(): ?array {
    global $UID;
    $st = db()->prepare("SELECT id, username, display_name FROM users WHERE id=?");
    $st->execute([$UID]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'username' => $r['username'], 'display_name' => $r['display_name']] : null;
}
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { global $ADMIN; return $ADMIN; }
function depo_sql_in(string $c): array { return ["($c IN (?) OR $c='')", ['DEPO1']]; }
function depot_visible_to_user(?string $d): bool { return $d === '' || $d === null || $d === 'DEPO1'; }
function active_depot(): ?string { return 'DEPO1'; }
function audit_log_event(string $a, string $m, ?int $id = null, ?array $o = null, ?array $n = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'id' => $id, 'old' => $o, 'new' => $n];
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'tok'; }
function csrf_check($t): void { if ($t !== 'tok') throw new Forbidden('csrf'); }
function set_flash($t, $m): void { global $FLASH; $FLASH[] = [$t, $m]; }
function render_flash(): void {}
class Forbidden extends RuntimeException {}
class Redirect extends RuntimeException {}
function forbidden($m = ''): void { throw new Forbidden('forbidden: ' . $m); }
function require_perm(string $p): void { if (!can($p) && !is_admin()) forbidden($p); }
function base_url(): string { return '/'; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function fmt_date($d) { return $d; }
function hdr(...$a) { $GLOBALS['HDR'][] = $a[0]; }
function hrc(int $c) { $GLOBALS['HRC'] = $c; }
function render_header(string $t, bool $p = false): void { echo "<!doctype html><html><head><title>" . h($t) . "</title></head><body><main>"; }
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/hesap_calc.php';
require_once $ROOT . '/hesap_config.php';
require_once $ROOT . '/config/hesap_pdf.php';

// PHP uyarısı = test hatası (sayfa render'ı sırasında toplanır)
set_error_handler(function (int $no, string $str, string $file, int $line) {
    $GLOBALS['UYARI'][] = "$str @" . basename($file) . ":$line";
    return true;
});

// ── Şema + veri ──
db()->exec("CREATE TABLE account_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, created_by INT,
  transaction_date TEXT, transaction_time TEXT DEFAULT '09:00', type TEXT, category TEXT DEFAULT '', amount REAL,
  currency TEXT DEFAULT 'TRY', payment_method TEXT DEFAULT 'nakit', person_company TEXT DEFAULT '',
  description TEXT DEFAULT '', document_no TEXT DEFAULT '', has_invoice INT DEFAULT 0, is_for_company INT DEFAULT 1,
  is_given_to_accountant INT DEFAULT 0, notes TEXT DEFAULT '', has_files INT DEFAULT 0, status TEXT DEFAULT 'submitted',
  submitted_at TEXT, reviewed_by INT, reviewed_at TEXT, review_note TEXT DEFAULT '', paid_at TEXT, depo TEXT DEFAULT '',
  created_at TEXT DEFAULT '2026-07-01', updated_at TEXT)");
db()->exec("CREATE TABLE account_files (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INT, file_name TEXT,
  original_name TEXT DEFAULT '', file_type TEXT DEFAULT '', file_size INT DEFAULT 0, uploaded_at TEXT)");
db()->exec("CREATE TABLE users (id INT, username TEXT, display_name TEXT, is_active INT DEFAULT 1)");
db()->exec("INSERT INTO users (id,username,display_name,is_active) VALUES
  (10,'a','PersonelA',1),(20,'b','PersonelB',1),(30,'m','Muhasebeci',1),(40,'y','Yonetici',1),
  (50,'s','SuperAdmin',1),(60,'v','Izleyici',1),(70,'p','PasifKisi',0)");

$ins = db()->prepare("INSERT INTO account_transactions
  (user_id,transaction_date,type,category,amount,currency,status,description,depo,is_given_to_accountant)
  VALUES (?,?,?,?,?,?,?,?,?,?)");
$ins->execute([10,  '2026-06-10','gider','Yakıt',            1000,'TRY','approved', 'A_GIZLI',        'DEPO1',1]); // 1
$ins->execute([10,  '2026-06-11','gider','Yemek gideri',      200,'TRY','submitted','A_TASLAK',       'DEPO1',0]); // 2
$ins->execute([20,  '2026-06-12','gider','Otel / Konaklama', 7777,'TRY','approved', 'B_GIZLI',        'DEPO1',1]); // 3
$ins->execute([null,'2026-06-13','gider','Kargo',            5555,'TRY','approved', 'LEGACY_ORTAK',   '',     1]); // 4
$ins->execute([10,  '2026-06-14','gider','Kargo',             300,'TRY','approved', 'A_DEPO2',        'DEPO2',1]); // 5
$ins->execute([20,  '2026-06-15','gider','Kargo',             111,'TRY','submitted','B_BEKLEYEN',     'DEPO1',0]); // 6
$ins->execute([30,  '2026-06-16','gider','Kargo',             500,'TRY','submitted','M_KENDI',        'DEPO1',0]); // 7
$ins->execute([20,  '2026-06-17','gider','Kargo',              50,'TRY','draft',    'B_TASLAK',       'DEPO1',0]); // 8
$ins->execute([null,'2026-06-18','gider','Kargo',              60,'TRY','draft',    'LEGACY_TASLAK',  '',     0]); // 9
$ins->execute([10,  '2026-06-19','gider','Kargo',              70,'TRY','submitted','A_DEPO2_BEKLEYEN','DEPO2',0]); // 10
$ins->execute([10,  '2026-06-20','gider','Kargo',              40,'USD','approved', 'A_USD',          'DEPO1',1]); // 11

// Gerçek fiş görselleri (hesap_dosya.php finfo ile MIME doğrular)
function testJpeg(string $path): void {
    $im = imagecreatetruecolor(40, 40);
    imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 240));
    imagejpeg($im, $path, 80);
    imagedestroy($im);
}
$FIS = ['legacy' => str_repeat('b', 32) . '.jpg', 'b_onayli' => str_repeat('c', 32) . '.jpg', 'a_bekleyen' => str_repeat('d', 32) . '.jpg'];
$fi = db()->prepare("INSERT INTO account_files (transaction_id,file_name,original_name) VALUES (?,?,?)");
foreach ([4 => 'legacy', 3 => 'b_onayli', 2 => 'a_bekleyen'] as $tid => $k) {
    testJpeg(HESAP_UPLOAD_DIR . $FIS[$k]);
    $fi->execute([$tid, $FIS[$k], $k . '.jpg']);
    db()->exec("UPDATE account_transactions SET has_files=1 WHERE id=$tid");
}
$FILE_ID = fn(string $k) => (int)db()->query("SELECT id FROM account_files WHERE file_name='" . $FIS[$k] . "'")->fetchColumn();

// ── Sayfa çalıştırıcı ──
function prep(string $file): string {
    global $ROOT, $TMPPG;
    $tmp = $TMPPG . basename($file);
    if (is_file($tmp)) return $tmp;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|hesap_config|config\/auth|config\/hesap_pdf)\.php\';\s*$/m', '', $src);
    $src = str_replace("__DIR__ . '/config/xlsx_export.php'", var_export($ROOT . '/config/xlsx_export.php', true), $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^\s*hesap_migrate\(\);\s*$/m', '', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $src = preg_replace('/(?<![_a-zA-Z>:])header\(/', 'hdr(', $src);
    $src = str_replace('http_response_code(', 'hrc(', $src);
    $src = preg_replace('/\bexit;/', 'throw new Redirect("exit");', $src);
    $src = preg_replace('/\bexit\(/', 'throw new Redirect(', $src);
    file_put_contents($tmp, "<?php\n" . $src);
    return $tmp;
}
/** @return string çıktı; __FORBIDDEN__ / __REDIRECT__ <çıktı> / __ERROR__ */
function run(string $file, array $get = [], ?array $post = null): string {
    $GLOBALS['FLASH'] = []; $GLOBALS['HDR'] = []; $GLOBALS['HRC'] = null; $GLOBALS['UYARI'] = [];
    $_GET = $get; $_POST = $post ?? []; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
    $tmp = prep($file);
    ob_start();
    try { (function () use ($tmp) { global $auth_user; include $tmp; })(); }
    catch (Forbidden $e) { ob_end_clean(); return '__FORBIDDEN__'; }
    catch (Redirect $e)  { $o = ob_get_clean(); return '__REDIRECT__ ' . ($e->getMessage() === 'exit' ? '' : $e->getMessage()) . $o; }
    catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getLine(); }
    return (string)ob_get_clean();
}
function as_user(int $uid, array $perms, bool $admin = false): void {
    $GLOBALS['UID'] = $uid; $GLOBALS['PERMS'] = $perms; $GLOBALS['ADMIN'] = $admin;
}
function row(int $id): ?array {
    $r = db()->query("SELECT * FROM account_transactions WHERE id=$id")->fetch();
    return $r ?: null;
}
function audit_var(string $action, ?int $id = null): bool {
    foreach ($GLOBALS['AUDIT'] as $a) {
        if ($a['action'] === $action && ($id === null || $a['id'] === $id)) return true;
    }
    return false;
}
function flash_icerir(string $s): bool {
    foreach ($GLOBALS['FLASH'] as [$t, $m]) { if (str_contains($m, $s)) return true; }
    return false;
}

$fail = 0; $pass = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $pass;
    $c ? $pass++ : $fail++;
    printf("%-72s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}
/** Render temiz mi: PHP uyarısı yok + div/form/table etiketleri dengeli */
function temiz(string $ad, string $html): void {
    $u = $GLOBALS['UYARI'];
    ok("$ad: PHP uyarısı yok", empty($u), implode(' | ', array_slice($u, 0, 3)));
    foreach (['div', 'form', 'table', 'select'] as $t) {
        $a = preg_match_all('/<' . $t . '[\s>]/i', $html);
        $k = substr_count(strtolower($html), '</' . $t . '>');
        if ($a !== $k) { ok("$ad: <$t> dengeli", false, "$a açılış / $k kapanış"); return; }
    }
    ok("$ad: HTML etiketleri dengeli", true);
}

$OPER = ['hesap.read', 'hesap.write', 'reports.export'];
$MUH  = ['hesap.read', 'hesap.write', 'hesap.approve', 'hesap.pay', 'reports.export'];
$YON  = ['hesap.read', 'hesap.write', 'hesap.delete', 'hesap.approve', 'hesap.pay', 'hesap.admin', 'reports.export'];

// ═══════════ K2 — sahipsiz kayıt herkese açık DEĞİL ═══════════
echo "── K2: sahipsiz (NULL) kayıt ──\n";
as_user(20, $OPER);
$l = run('hesap_liste.php');
temiz('B liste', $l);
ok('B liste: LEGACY_ORTAK (sahipsiz) YOK',          !str_contains($l, 'LEGACY_ORTAK'));
ok('B liste: A_GIZLI (A kaydı) YOK',                 !str_contains($l, 'A_GIZLI'));
ok('B liste: kendi B_GIZLI var',                     str_contains($l, 'B_GIZLI'));
ok('B bakiyesi −7.777 (sahipsiz 5.555 eklenmedi)',   abs(hesap_balance()['TRY']['net'] + 7777) < 0.01, (string)hesap_balance()['TRY']['net']);
ok('B hesap_kayit?id=4 (sahipsiz) → 403',            run('hesap_kayit.php', ['id' => '4']) === '__FORBIDDEN__');
$o = run('hesap_dosya.php', ['f' => $FIS['legacy']]);
ok('B sahipsiz kaydın fişi → 403',                   $GLOBALS['HRC'] === 403 && str_contains($o, 'Erişim reddedildi'));
$PERMS[] = 'hesap.delete';
ok('B (hesap.delete) sahipsiz kaydı silemez',        run('hesap_sil.php', ['id' => '4'], ['csrf' => 'tok']) === '__FORBIDDEN__' && row(4) !== null);
ok('B sahipsiz onaylı kayda geçiş yapamaz',          hesap_transition(4, 'rejected', 'x')['ok'] === false);
ok('B sahipsiz TASLAĞI gönderemez (O4)',             hesap_transition(9, 'submitted')['ok'] === false && row(9)['status'] === 'draft');
as_user(60, ['hesap.read']);
ok('Izleyici sahipsiz taslağı gönderemez',           hesap_transition(9, 'submitted')['ok'] === false);
as_user(10, $OPER);
ok('A bakiyesi −1.300 (DEPO2 dahil, sahipsiz hariç)', abs(hesap_balance()['TRY']['net'] + 1300) < 0.01, (string)hesap_balance()['TRY']['net']);
$o = run('hesap_dosya.php', ['f' => $FIS['b_onayli']]);
ok('A, B\'nin fişini açamaz',                        $GLOBALS['HRC'] === 403);
$o = run('hesap_dosya.php', ['f' => $FIS['a_bekleyen']]);
ok('A kendi fişini açabilir',                        $GLOBALS['HRC'] === null && !str_starts_with($o, '__') && strlen($o) > 100);

// ═══════════ K1 — PDF raporu başkasının bakiyesini sızdırmaz ═══════════
echo "\n── K1: PDF dönem raporu ──\n";
as_user(20, $OPER);
$rp = hesap_report_data(['tarih_bas' => '2026-07-01', 'tarih_son' => '2026-07-31']);
$html = hesap_report_html($rp, false);
ok('B Temmuz raporu: personel yalnız PersonelB',     array_keys($rp['personel']) === ['PersonelB'], implode(',', array_keys($rp['personel'])));
ok('B raporu: PersonelA YOK',                        !str_contains($html, 'PersonelA'));
ok('B raporu: Atanmamış YOK',                        !str_contains($html, 'Atanmamış'));
ok('B raporu: PersonelB var',                        str_contains($html, 'PersonelB'));
$o = run('hesap_yazdir.php', ['tarih_bas' => '2026-07-01', 'tarih_son' => '2026-07-31', 'goruntule' => 'html', 'personel' => 'tum']);
ok('B hesap_yazdir ?personel=tum yok sayılır',       !str_contains($o, 'PersonelA') && str_contains($o, 'PersonelB'));
as_user(30, $MUH);
$rpm = hesap_report_data(['tarih_bas' => '2026-07-01', 'tarih_son' => '2026-07-31']);
ok('Muhasebe raporu: başka personel yok',           array_diff(array_keys($rpm['personel']), ['Muhasebeci']) === [], implode(',', array_keys($rpm['personel'])));
as_user(40, $YON);
$rpy = hesap_report_data(['tarih_bas' => '2026-07-01', 'tarih_son' => '2026-07-31', 'kapsam' => hesap_kapsam_coz('tum')]);
$hy = hesap_report_html($rpy, false);
ok('Yönetici "tum" raporu: PersonelA ve PersonelB', str_contains($hy, 'PersonelA') && str_contains($hy, 'PersonelB'));
ok('Yönetici "tum" raporu: Atanmamış YOK',           !str_contains($hy, 'Atanmamış'));
$o = run('hesap_yazdir.php', ['tarih_bas' => '2026-06-01', 'tarih_son' => '2026-06-30', 'goruntule' => 'html', 'personel' => '10']);
ok('Yönetici ?personel=10 raporu: yalnız A',         str_contains($o, 'A_GIZLI') === false && str_contains($o, 'Kapsam: PersonelA') && !str_contains($o, 'PersonelB'));

// ═══════════ K3 — ana sayfa Hesap kartı kişisel ═══════════
echo "\n── K3: index.php Hesap kartı ──\n";
$isrc = file_get_contents($ROOT . '/index.php');
$ib = strpos($isrc, '// Hesap modülü özet');
$ie = strpos($isrc, '// Stok özet');
$blok = ($ib !== false && $ie !== false) ? substr($isrc, $ib, $ie - $ib) : '';
ok('index: Hesap bloğu bulundu',                     $blok !== '');
ok('index: sorgular user_id = ? ile',                substr_count($blok, 'user_id = ?') >= 2);
ok('index: tutar yalnız TRY',                        str_contains($blok, "currency = 'TRY'"));
ok('index: legacy is_given_to_accountant sayımı yok', !str_contains($blok, 'is_given_to_accountant'));
db()->exec("INSERT INTO account_transactions (user_id,transaction_date,type,amount,currency,status,description)
            VALUES (10,'" . date('Y-m-d') . "','gider',25,'TRY','submitted','A_BUGUN'),
                   (20,'" . date('Y-m-d') . "','gider',999,'TRY','submitted','B_BUGUN'),
                   (10,'" . date('Y-m-d') . "','gider',15,'USD','submitted','A_BUGUN_USD')");
$bugun_ids = db()->query("SELECT id FROM account_transactions WHERE description LIKE '%_BUGUN%'")->fetchAll(PDO::FETCH_COLUMN);
$index_hesap = function () use ($blok): array {
    eval($blok);   // yalnız index.php'nin Hesap bloğu — stub'lı db()/can()/current_user() ile
    return [$hesap_bugun, $hesap_bekleyen, $hesap_onay_bekleyen];
};
as_user(10, $OPER);
[$bg, $bk, $ob] = $index_hesap();
ok('index A: bugün yalnız kendi TRY (25)',           abs($bg - 25) < 0.01, (string)$bg);
ok('index A: bekleyen yalnız kendi (4, B hariç)',     $bk === 4, (string)$bk);
ok('index A: yönetici sayaçları 0',                  $ob === 0);
as_user(40, $YON);
[$bg, $bk, $ob] = $index_hesap();
ok('index Yönetici: onay bekleyen (sahipli submitted)', $ob === 7, (string)$ob);
ok('index: sahipsiz sayacı YOK',                     !str_contains($blok, '$hesap_sahipsiz') && !str_contains($blok, 'user_id IS NULL'));
db()->exec("DELETE FROM account_transactions WHERE id IN (" . implode(',', $bugun_ids) . ")");

// ═══════════ O3 — liste toplamları bakiye/bekleyen ayrı ═══════════
echo "\n── O3: liste toplamları ──\n";
as_user(10, $OPER);
$l = run('hesap_liste.php');
temiz('A liste', $l);
ok('A liste: DEPO2 kaydı görünüyor (Y1)',            str_contains($l, 'A_DEPO2'));
ok('A liste: bakiyeye giren net −1.300',             str_contains($l, '-1.300,00 ₺'));
ok('A liste: bekleyen net ayrı (−270)',              str_contains($l, '-270,00 ₺'));
ok('A liste: USD ayrı satır',                        str_contains($l, '-40,00 $'));

// ═══════════ K4 — onaylı kaydın içeriği kilitli ═══════════
echo "\n── K4: içerik kilidi ──\n";
as_user(20, $OPER);
$o = run('hesap_kayit.php', [], ['csrf' => 'tok', 'id' => '3', 'amount' => '1', 'type' => 'gelir', 'currency' => 'TRY',
                                  'transaction_date' => '2026-06-12', 'payment_method' => 'nakit']);
ok('B kendi ONAYLI kaydını değiştiremez (redirect)', str_starts_with($o, '__REDIRECT__') && flash_icerir('Onaylanmış kayıt kilitlidir'));
ok('B onaylı kayıt: tutar 7.777 / gider kaldı',      (float)row(3)['amount'] === 7777.0 && row(3)['type'] === 'gider');
ok('B onaylı kaydın GET formu da kilitli',           str_starts_with(run('hesap_kayit.php', ['id' => '3']), '__REDIRECT__'));
$o = run('hesap_dosya_sil.php', [], ['csrf' => 'tok', 'id' => (string)$FILE_ID('b_onayli')]);
ok('B onaylı kaydın fişini silemez',                 str_contains($o, '"ok":false') && is_file(HESAP_UPLOAD_DIR . $FIS['b_onayli']));
as_user(30, $MUH);
ok('Muhasebe A\'nın kaydını açamaz (403)',           run('hesap_kayit.php', ['id' => '1']) === '__FORBIDDEN__');
$o = run('hesap_kayit.php', [], ['csrf' => 'tok', 'id' => '1', 'amount' => '99999', 'type' => 'gelir', 'currency' => 'TRY',
                                  'transaction_date' => '2026-06-10', 'payment_method' => 'nakit']);
ok('Muhasebe A\'nın kaydını POST ile değiştiremez',  $o === '__FORBIDDEN__' && (float)row(1)['amount'] === 1000.0);
as_user(40, $YON);
$GLOBALS['AUDIT'] = [];
$post3 = ['csrf' => 'tok', 'id' => '3', 'amount' => '7.700', 'type' => 'gider', 'currency' => 'TRY',
          'transaction_date' => '2026-06-12', 'payment_method' => 'nakit', 'category' => 'Otel / Konaklama',
          'description' => 'B_GIZLI', 'sahip_id' => '20'];
$o = run('hesap_kayit.php', [], $post3);
ok('Yönetici gerekçesiz düzeltme → hata',            str_contains($o, 'gerekçe') && (float)row(3)['amount'] === 7777.0);
$o = run('hesap_kayit.php', [], $post3 + ['duzeltme_nedeni' => 'Fiş tutarı yanlış girilmiş']);
ok('Yönetici gerekçeli düzeltme → güncellendi',      str_starts_with($o, '__REDIRECT__') && (float)row(3)['amount'] === 7700.0, substr($o, 0, 120));
ok('Yönetici düzeltmesi: durum approved kaldı',      row(3)['status'] === 'approved');
$upd = array_values(array_filter($AUDIT, fn($a) => $a['action'] === 'update' && $a['id'] === 3));
ok('audit update: duzeltme_nedeni + yonetici_duzeltmesi', !empty($upd) && ($upd[0]['new']['duzeltme_nedeni'] ?? '') === 'Fiş tutarı yanlış girilmiş'
                                                     && ($upd[0]['new']['yonetici_duzeltmesi'] ?? false) === true);
ok('audit update: başkasının kaydı işaretli',        !empty($upd) && ($upd[0]['new']['baskasinin_kaydi'] ?? false) === true && (int)$upd[0]['new']['sahip'] === 20);
ok('sahip_id aynı → owner_change yok',               !audit_var('owner_change', 3));
$o = run('hesap_dosya_sil.php', [], ['csrf' => 'tok', 'id' => (string)$FILE_ID('b_onayli')]);
ok('Yönetici de onaylı kaydın fişini silemez',       str_contains($o, '"ok":false') && is_file(HESAP_UPLOAD_DIR . $FIS['b_onayli']));
db()->exec("UPDATE account_transactions SET amount=7777 WHERE id=3");

// ═══════════ Y1 / Y2 / K-2 — onay kuyruğu ═══════════
echo "\n── Y2 / K-2: onay kuyruğu ──\n";
as_user(30, $MUH);
$m = run('hesap_muhasebe.php');
temiz('Muhasebe kuyruğu', $m);
ok('Muhasebe kuyruğu: kendi M_KENDI var',            str_contains($m, 'M_KENDI'));
ok('Muhasebe kuyruğu: B_BEKLEYEN YOK',               !str_contains($m, 'B_BEKLEYEN'));
ok('Muhasebe kuyruğu: A_TASLAK YOK',                 !str_contains($m, 'A_TASLAK'));
ok('Muhasebe kuyruğu: Personel Bakiyeleri YOK',      !str_contains($m, 'Personel Bakiyeleri'));
ok('Muhasebe ?personel=tum yok sayılır',             !str_contains(run('hesap_muhasebe.php', ['personel' => 'tum']), 'B_BEKLEYEN'));
$o = run('hesap_durum.php', [], ['csrf' => 'tok', 'durum' => 'approved', 'ids' => ['6']]);
ok('Muhasebe B\'nin kaydını onaylayamaz (JSON)',     str_contains($o, '"ok":false') && row(6)['status'] === 'submitted');
ok('Muhasebe hata mesajı var olanı sızdırmaz',       str_contains($o, 'bulunamad'));
$o = run('hesap_muhasebe.php', [], ['csrf' => 'tok', 'toplu_durum' => 'approved', 'secili' => ['6', '2']]);
ok('Muhasebe toplu onay başkasınınkini atlar',       row(6)['status'] === 'submitted' && row(2)['status'] === 'submitted');
as_user(40, $YON);
$m = run('hesap_muhasebe.php');
temiz('Yönetici kuyruğu', $m);
ok('Yönetici kuyruğu: B_BEKLEYEN var',               str_contains($m, 'B_BEKLEYEN'));
ok('Yönetici kuyruğu: DEPO2 kaydı var (Y1)',         str_contains($m, 'A_DEPO2_BEKLEYEN'));
ok('Yönetici kuyruğu: taslak (B_TASLAK) YOK',        !str_contains($m, 'B_TASLAK'));
ok('Yönetici kuyruğu: sahipsiz YOK',                 !str_contains($m, 'LEGACY'));
ok('Yönetici kuyruğu: Personel Bakiyeleri var',      str_contains($m, 'Personel Bakiyeleri'));
$mk = run('hesap_muhasebe.php', ['personel' => '20']);
ok('Yönetici kuyruğu ?personel=20: yalnız B',        str_contains($mk, 'B_BEKLEYEN') && !str_contains($mk, 'M_KENDI'));

// ═══════════ K-3 — kendi kaydını onaylama SERBEST ═══════════
echo "\n── K-3: kendi kaydını onaylama serbest (bilinçli) ──\n";
as_user(30, $MUH);
ok('Muhasebe kendi kaydını onaylar',                 hesap_transition(7, 'approved')['ok'] === true);
ok('Muhasebe kendi kaydını ödendi yapar',            hesap_transition(7, 'paid')['ok'] === true && row(7)['status'] === 'paid');
ok('hesap_can_transition sahip≠ben kuralı YOK',      hesap_can_transition(['user_id' => 30, 'status' => 'submitted'], 'approved') === true);

// ═══════════ O2 — biçim doğrulama ═══════════
echo "\n── O2: biçim doğrulama ──\n";
as_user(20, $OPER);
$n0 = (int)db()->query("SELECT COUNT(*) FROM account_transactions")->fetchColumn();
$yeni = ['csrf' => 'tok', 'amount' => '10', 'type' => 'gider', 'currency' => 'TRY', 'transaction_date' => '2026-06-30',
         'payment_method' => 'nakit', 'kaydet_turu' => 'gonder'];
$o = run('hesap_kayit.php', [], ['currency' => 'XXX<b>'] + $yeni);
ok('POST currency "XXX<b>" → hata',                  str_contains($o, 'Geçersiz para birimi'));
ok('POST currency "XXX<b>" → HTML kaçırıldı',        !str_contains($o, 'XXX<b>'));
$o = run('hesap_kayit.php', [], ['transaction_date' => 'abc'] + $yeni);
ok('POST tarih "abc" → hata',                        str_contains($o, 'Geçersiz tarih'));
$o = run('hesap_kayit.php', [], ['amount' => '1.234.56'] + $yeni);
ok('POST tutar "1.234.56" → hata',                   str_contains($o, 'Tutar 0'));
$o = run('hesap_kayit.php', [], ['amount' => '0,005'] + $yeni);
ok('POST tutar "0,005" → en fazla 2 ondalık',        str_contains($o, 'En fazla 2 ondalık'));
$o = run('hesap_kayit.php', [], ['payment_method' => 'bitcoin'] + $yeni);
ok('POST ödeme yöntemi beyaz liste dışı → hata',     str_contains($o, 'Geçersiz ödeme yöntemi'));
ok('hatalı POST\'ların hiçbiri yazılmadı',           (int)db()->query("SELECT COUNT(*) FROM account_transactions")->fetchColumn() === $n0);
$o = run('hesap_kayit.php', [], ['amount' => '1.234.567', 'user_id' => '10', 'sahip_id' => '10'] + $yeni);
$son = db()->query("SELECT * FROM account_transactions ORDER BY id DESC LIMIT 1")->fetch();
ok('geçerli POST: 1.234.567 → 1234567',              (float)$son['amount'] === 1234567.0, (string)$son['amount']);
ok('POST user_id/sahip_id ile başkası adına kayıt YOK', (int)$son['user_id'] === 20 && (int)$son['created_by'] === 20);
db()->exec("DELETE FROM account_transactions WHERE id=" . (int)$son['id']);

// ═══════════ Kapsam parametresi ═══════════
echo "\n── Kapsam parametresi (?personel=) ──\n";
as_user(20, $OPER);
$l = run('hesap_liste.php', ['personel' => '10']);
ok('B ?personel=10 → yine yalnız kendi',             !str_contains($l, 'A_GIZLI') && str_contains($l, 'B_GIZLI') && !str_contains($l, 'yönetici görünümü'));
$d = run('hesap.php', ['personel' => '10']);
ok('B hesap.php?personel=10 → kendi Hesabım',        str_contains($d, 'Hesabım') && !str_contains($d, 'PersonelA'));
$x = run('hesap_export.php', ['bicim' => 'csv', 'personel' => 'tum']);
ok('B export ?personel=tum → yalnız kendi',          str_contains($x, 'B_GIZLI') && !str_contains($x, 'A_GIZLI') && !str_contains($x, 'LEGACY'));
as_user(40, $YON);
$GLOBALS['AUDIT'] = [];
$l = run('hesap_liste.php', ['personel' => '10']);
temiz('Yönetici A listesi', $l);
ok('Yönetici ?personel=10 → A kayıtları',            str_contains($l, 'A_GIZLI') && !str_contains($l, 'B_GIZLI'));
ok('Yönetici ?personel=10 → yönetici şeridi',        str_contains($l, 'yönetici görünümü') && str_contains($l, 'PersonelA'));
ok('Yönetici görüntüleme audit\'i (view)',          audit_var('view'));
ok('sayfa linkleri personel parametresini taşır',   str_contains($l, 'personel=10'));
$d = run('hesap.php', ['personel' => '10']);
temiz('Yönetici A hesabı', $d);
ok('Yönetici A hesabı: bakiye 1.300',                str_contains($d, '1.300,00') && str_contains($d, 'Şirket personele borçlu'));
$r = run('hesap.php', ['personel' => 'tum']);
ok('Yönetici hesap.php?personel=tum → Tüm Personel', str_starts_with($r, '__REDIRECT__') && in_array('Location: hesap_personel.php', $GLOBALS['HDR'], true));
$GLOBALS['AUDIT'] = [];
as_user(10, $OPER);
run('hesap_liste.php');
ok('kendi hesabında view audit\'i YAZILMAZ',         !audit_var('view'));

// ═══════════ Sahipsiz Kayıtlar ekranı → 410 tombstone (v281) ═══════════
// Sahip ataması artık YALNIZ hesap_kayit.php "Kayıt sahibi" alanından (yönetici).
echo "\n── Sahipsiz kayıt ataması (hesap_kayit.php) ──\n";
$ts = (string)file_get_contents(dirname(__DIR__) . '/hesap_sahipsiz.php');
ok('hesap_sahipsiz.php 410 tombstone (DB/oturum yok)', str_contains($ts, 'http_response_code(410)')
                                                     && !preg_match('/require|include|db\(|session_/', $ts) && str_contains($ts, 'hesap.php'));
as_user(40, $YON);
$GLOBALS['AUDIT'] = [];
$f9 = run('hesap_kayit.php', ['id' => '4']);
temiz('Sahipsiz kaydın formu', $f9);
ok('sahipsiz kayıt: "Sahip seçin" yer tutucusu (seçilemez)', str_contains($f9, '<option value="" selected disabled>— Sahip seçin —</option>'));
ok('"— Sahipsiz —" seçeneği YOK',                    !str_contains($f9, '— Sahipsiz —'));
$post4 = ['id' => '4', 'amount' => '5.555', 'type' => 'gider', 'currency' => 'TRY',
          'transaction_date' => '2026-06-13', 'payment_method' => 'nakit', 'category' => 'Kargo',
          'description' => 'LEGACY_ORTAK', 'sahip_id' => '20', 'duzeltme_nedeni' => 'Sahip atandı'];
$o = run('hesap_kayit.php', [], ['csrf' => 'bad'] + $post4);
ok('CSRF yanlış → reddedildi',                       $o === '__FORBIDDEN__' && row(4)['user_id'] === null);
$o = run('hesap_kayit.php', [], ['csrf' => 'tok'] + $post4);
ok('Yönetici id=4 → B\'ye atandı',                   str_starts_with($o, '__REDIRECT__') && (int)row(4)['user_id'] === 20, substr($o, 0, 100));
ok('audit owner_change',                             audit_var('owner_change', 4));
ok('created_by / status / depo değişmedi',           row(4)['created_by'] === null && row(4)['status'] === 'approved' && row(4)['depo'] === '');
as_user(20, $OPER);
ok('B bakiyesi artık −13.332 (atama etkili)',        abs(hesap_balance()['TRY']['net'] + 13332) < 0.01, (string)hesap_balance()['TRY']['net']);
as_user(40, $YON);
$post9 = ['csrf' => 'tok', 'id' => '9', 'amount' => '60', 'type' => 'gider', 'currency' => 'TRY',
          'transaction_date' => '2026-06-18', 'payment_method' => 'nakit', 'category' => 'Kargo',
          'description' => 'LEGACY_TASLAK'];
run('hesap_kayit.php', [], $post9 + ['sahip_id' => '999']);
ok('olmayan kullanıcı (999) → reddedildi',           row(9)['user_id'] === null);
run('hesap_kayit.php', [], $post9 + ['sahip_id' => '70']);
ok('pasif kullanıcı (70) → reddedildi',              row(9)['user_id'] === null);
$o = run('hesap_kayit.php', [], $post9 + ['sahip_id' => '']);
ok('sahipsiz kayıtta boş sahip = değişiklik yok (kayıt kaydedilir)', str_starts_with($o, '__REDIRECT__') && row(9)['user_id'] === null, substr($o, 0, 100));

// ═══════════ Sahip değiştir (hesap_kayit.php) ═══════════
echo "\n── Sahip değiştir ──\n";
as_user(40, $YON);
$GLOBALS['AUDIT'] = [];
$f4 = run('hesap_kayit.php', ['id' => '4']);
temiz('Yönetici kayıt formu', $f4);
ok('Yönetici formunda "Kayıt sahibi" alanı',         str_contains($f4, 'name="sahip_id"'));
$sahipSel = preg_match('#<select name="sahip_id">(.*?)</select>#s', $f4, $mm) ? $mm[1] : '';
ok('sahipli kayıtta "— Sahipsiz —" / boş seçenek YOK', $sahipSel !== '' && !str_contains($f4, '— Sahipsiz —') && !str_contains($sahipSel, 'value=""'));
ok('onaylı kayıtta gerekçe alanı var',               str_contains($f4, 'name="duzeltme_nedeni"'));
ok('onaylı kayıtta fiş sil düğmesi yok',             !str_contains($f4, 'data-hs-file-del'));
$o = run('hesap_kayit.php', [], ['csrf' => 'tok', 'id' => '4', 'amount' => '5.555', 'type' => 'gider', 'currency' => 'TRY',
                                  'transaction_date' => '2026-06-13', 'payment_method' => 'nakit', 'category' => 'Kargo',
                                  'description' => 'LEGACY_ORTAK', 'sahip_id' => '', 'duzeltme_nedeni' => 'Yanlış kişiye atandı']);
ok('sahip_id="" → REDDEDİLDİ (sahipsiz yapılamaz)',  !str_starts_with($o, '__REDIRECT__') && (int)row(4)['user_id'] === 20
                                                     && str_contains($o, 'Kayıt sahibi boş bırakılamaz'), substr($o, 0, 100));
ok('red: audit owner_change YAZILMADI',              !audit_var('owner_change', 4));
$o = run('hesap_kayit.php', [], ['csrf' => 'tok', 'id' => '4', 'amount' => '5.555', 'type' => 'gider', 'currency' => 'TRY',
                                  'transaction_date' => '2026-06-13', 'payment_method' => 'nakit', 'category' => 'Kargo',
                                  'description' => 'LEGACY_ORTAK', 'sahip_id' => '10', 'duzeltme_nedeni' => 'Yanlış kişiye atandı']);
ok('sahip değişikliği B → A',                        str_starts_with($o, '__REDIRECT__') && (int)row(4)['user_id'] === 10, substr($o, 0, 100));
ok('audit owner_change',                             audit_var('owner_change', 4));
ok('tutar değişmedi (5.555 binlik)',                 (float)row(4)['amount'] === 5555.0);
// Aşağıdaki NULL-güvenlik iddiaları (Tüm Personel / CSV'de LEGACY yok) sahipsiz bir
// satıra ihtiyaç duyar; arayüz artık sahipsiz yapamadığı için fikstür doğrudan geri alır.
db()->exec("UPDATE account_transactions SET user_id = NULL WHERE id = 4");
as_user(20, $OPER);
ok('B formunda "Kayıt sahibi" alanı YOK',            !str_contains(run('hesap_kayit.php', ['id' => '6']), 'name="sahip_id"'));
$o = run('hesap_kayit.php', [], ['csrf' => 'tok', 'id' => '6', 'amount' => '111', 'type' => 'gider', 'currency' => 'TRY',
                                  'transaction_date' => '2026-06-15', 'payment_method' => 'nakit', 'description' => 'B_BEKLEYEN',
                                  'sahip_id' => '10']);
ok('B\'nin POST\'undaki sahip_id yok sayılır',        (int)row(6)['user_id'] === 20 && str_starts_with($o, '__REDIRECT__'));

// ═══════════ Tüm Personel ═══════════
echo "\n── Tüm Personel ──\n";
as_user(20, $OPER);
ok('B → 403',                                        run('hesap_personel.php') === '__FORBIDDEN__');
as_user(30, $MUH);
ok('Muhasebe → 403',                                 run('hesap_personel.php') === '__FORBIDDEN__');
as_user(40, $YON);
$p = run('hesap_personel.php');
temiz('Tüm Personel', $p);
ok('A ve B satırları var',                           str_contains($p, 'PersonelA') && str_contains($p, 'PersonelB'));
ok('sahipsiz satırı yok (Atanmamış yok)',            !str_contains($p, 'Atanmamış'));
ok('USD ayrı satır (A)',                             str_contains($p, '40,00 $'));
ok('satır linkleri ?personel=',                      str_contains($p, 'hesap.php?personel=10') && str_contains($p, 'hesap_liste.php?personel=20'));
ok('Tüm Personel: sahipsiz linki/sayacı YOK',        !str_contains($p, 'hesap_sahipsiz.php') && !str_contains($p, 'Sahipsiz Kayıtlar'));

// ═══════════ Y4 — onaylı kaydı silme ═══════════
echo "\n── Y4: silme ──\n";
as_user(10, array_merge($OPER, ['hesap.delete']));
$o = run('hesap_sil.php', ['id' => '1'], ['csrf' => 'tok']);
ok('A kendi ONAYLI kaydını silemez',                 row(1) !== null && flash_icerir('kilitli'));
$o = run('hesap_sil.php', ['id' => '2'], ['csrf' => 'tok']);
ok('A kendi GÖNDERİLMİŞ kaydını silebilir',          row(2) === null);
as_user(40, $YON);
$o = run('hesap_sil.php', ['id' => '5'], ['csrf' => 'tok']);
ok('Yönetici onaylı kaydı gerekçesiz silemez',       row(5) !== null && str_contains($o, 'gerekçe'));
$GLOBALS['AUDIT'] = [];
$o = run('hesap_sil.php', ['id' => '5'], ['csrf' => 'tok', 'gerekce' => 'Mükerrer kayıt']);
$del = array_values(array_filter($AUDIT, fn($a) => $a['action'] === 'delete' && $a['id'] === 5));
ok('Yönetici gerekçeli siler + audit gerekce',       row(5) === null && !empty($del) && ($del[0]['old']['gerekce'] ?? '') === 'Mükerrer kayıt');

// ═══════════ Export ═══════════
echo "\n── Export ──\n";
as_user(20, $OPER);
$x = run('hesap_export.php', ['bicim' => 'csv']);
$csv = substr($x, strlen('__REDIRECT__ '));
ok('B CSV: yalnız B satırları',                      str_contains($csv, 'B_GIZLI') && !str_contains($csv, 'A_GIZLI') && !str_contains($csv, 'LEGACY'));
$ilk = strtok($csv, "\n");
ok('CSV başlık satırı bayt bayt aynı',               $ilk === "\xEF\xBB\xBF#;Tarih;Tür;Kategori;Kişi/Firma;Açıklama;\"Belge No\";Tutar;Döviz;Ödeme;Fatura;\"Şirket İçin\";Durum;Not",
                                                     bin2hex(substr((string)$ilk, 0, 20)));
as_user(40, $YON);
$x = run('hesap_export.php', ['bicim' => 'csv', 'personel' => 'tum']);
ok('Yönetici CSV ?personel=tum: A ve B',             str_contains($x, 'A_GIZLI') && str_contains($x, 'B_GIZLI') && !str_contains($x, 'LEGACY'));

// ═══════════ İzleyici (yalnız hesap.read) ═══════════
echo "\n── İzleyici ──\n";
as_user(60, ['hesap.read']);
$d = run('hesap.php');
temiz('İzleyici Hesabım', $d);
ok('İzleyici: boş kendi hesabı, başkası yok',        !str_contains($d, 'A_GIZLI') && !str_contains($d, 'B_GIZLI') && str_contains($d, 'Henüz kayıt yok'));
ok('İzleyici: yönetici kartı yok',                   !str_contains($d, 'hesap_personel.php'));

echo $fail === 0 ? "\n>>> TÜM İZOLASYON TESTLERİ GEÇTİ ($pass)\n" : "\n>>> $fail TEST BAŞARISIZ ($pass geçti)\n";
exit($fail === 0 ? 0 : 1);
