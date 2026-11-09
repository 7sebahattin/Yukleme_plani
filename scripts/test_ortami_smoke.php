<?php
// =========================================================
// scripts/test_ortami_smoke.php — Test ortamı (APP_ORTAM) kapısı (v504)
//
// APP_ORTAM='test' iken HKS / IMAP+SMTP / çeviri çağrıları AĞA ÇIKMADAN reddedilir; ortam çözümü fail-closed;
// başlık şeridi + CSS var; yeni bir dış bağlantı yolu eklenirse (kapısız) test düşer.
// CLI; DB'ye ve ağa dokunmaz (her senaryo ayrı php alt süreci, kapı ağdan ÖNCE çalıştığı için bağlantı denenmez).
//   php scripts/test_ortami_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function oku(string $f): string { global $ROOT; return (string)file_get_contents($ROOT . '/' . $f); }

/** Verilen kodu ayrı süreçte çalıştırır. $ortam null ise APP_ORTAM HİÇ tanımlanmaz. */
function calistir(?string $ortam, string $kod): string
{
    global $ROOT;
    $on = $ortam === null ? '' : 'define("APP_ORTAM", ' . var_export($ortam, true) . ');';
    $tam = '<?php ' . $on . ' chdir(' . var_export($ROOT, true) . '); ' . $kod;
    $tmp = tempnam(sys_get_temp_dir(), 'ortam_');
    file_put_contents($tmp, $tam);
    $cikti = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim($cikti);
}

// ── 1) Ortam çözümü (fail-closed) ─────────────────────────────────────
$orn = [['canli', 'canli'], ['CANLI', 'canli'], [' canli ', 'canli'],
        ['test', 'test'], ['Test ', 'test'], ['staging', 'test'], ['', 'test'], ['0', 'test']];
foreach ($orn as [$girdi, $beklenen]) {
    $cik = calistir($girdi, 'require "config/ortam.php"; echo app_ortam(), "|", var_export(app_dis_gonderim_kapali(), true);');
    ok('ortam ' . var_export($girdi, true) . ' → ' . $beklenen, $cik === $beklenen . '|' . ($beklenen === 'test' ? 'true' : 'false'), $cik);
}
$cik = calistir(null, 'require "config/ortam.php"; echo app_ortam(), "|", var_export(app_dis_gonderim_kapali(), true);');
ok('APP_ORTAM tanımsız → canli, dış gönderim AÇIK', $cik === 'canli|false', $cik);

// ── 2) HKS: SOAP çağrısı ağa çıkmadan HksAgHatasi(errno 7) ───────────
$cik = calistir('test', 'require "halkayit/hks_soap.php"; try { hks_soap_cagir("Bildirim", "X", "<a/>"); echo "GITTI"; } catch (HksAgHatasi $e) { echo "ENGEL|", $e->errno, "|", (int)(strpos($e->getMessage(), "Test ortam") !== false); }');
ok('HKS: test ortamında hks_soap_cagir reddedilir (errno 7 = istek hiç gitmedi)', $cik === 'ENGEL|7|1', $cik);

// ── 3) Mail: IMAP/SMTP ortak soketi ──────────────────────────────────
$cik = calistir('test', 'require "config/mail_imap.php"; try { MailSocketStream::baglan("imap.example.invalid", 993, "ssl", 1.0); echo "BAGLANDI"; } catch (MailImapException $e) { echo "ENGEL|", (int)(strpos($e->getMessage(), "Test ortam") !== false); }');
ok('Mail: test ortamında MailSocketStream::baglan reddedilir', $cik === 'ENGEL|1', $cik);

// ── 4) Mail çevirisi (HTTPS) ──────────────────────────────────────────
$cik = calistir('test', 'require "config/mail_translate.php"; try { mail_http_istek("POST", "https://api.example.invalid/x", [], "{}", 1.0); echo "GITTI"; } catch (MailTranslateException $e) { echo "ENGEL|", $e->kind, "|", (int)(strpos($e->getMessage(), "Test ortam") !== false); }');
ok('Çeviri: test ortamında mail_http_istek reddedilir (kind=config)', $cik === 'ENGEL|config|1', $cik);

// ── 5) Kapı kaynakta app_dis_gonderim_kapali() ile bağlı, ortam.php'yi her dosya kendisi yükler ──
foreach (['halkayit/hks_soap.php' => 'hks_soap_cagir', 'config/mail_imap.php' => 'baglan', 'config/mail_translate.php' => 'mail_http_istek'] as $f => $fn) {
    $s = oku($f);
    ok("$f: ortam.php'yi KENDİSİ yükler", (bool)preg_match("#require_once\s+__DIR__\s*\.\s*'/(?:\.\./config/)?ortam\.php'#", $s));
    ok("$f: $fn() başında app_dis_gonderim_kapali() kapısı var", (bool)preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\([^)]*\)[^{]*\{\s*(?:\/\/[^\n]*\n\s*)*(?:require_once[^\n]*\n\s*)?(?:\/\/[^\n]*\n\s*)*if\s*\(app_dis_gonderim_kapali\(\)\)/s', $s));
}

// ── 6) Kapısız yeni dış bağlantı yolu YASAK ──────────────────────────
// Yorumlar ve metinler atlanır (token tabanlı): yalnız GERÇEK işlev çağrıları sayılır.
$izinli = ['halkayit/hks_soap.php', 'config/mail_imap.php', 'config/mail_translate.php', 'halkayit/endpoint_test.php'];
$yasak = ['curl_init', 'curl_multi_init', 'stream_socket_client', 'fsockopen', 'pfsockopen', 'socket_create', 'socket_connect', 'mail'];
function dis_baglanti_cagrilari(string $kod): array
{
    global $yasak;
    $bulunan = [];
    $t = token_get_all($kod);
    $n = count($t);
    $kullan = static fn($x) => is_array($x) ? $x[0] : null;
    for ($i = 0; $i < $n; $i++) {
        if ($kullan($t[$i]) !== T_STRING) continue;
        $ad = strtolower($t[$i][1]);
        // sonraki anlamlı token '(' mi, öncekiler -> / :: / function / new değil mi?
        $k = $i + 1; while ($k < $n && in_array($kullan($t[$k]), [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $k++;
        $c = $i - 1; while ($c >= 0 && in_array($kullan($t[$c]), [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $c--;
        $once = $c >= 0 ? $t[$c] : null;
        $oncekiTur = $kullan($once);
        if (($t[$k] ?? null) !== '(') continue;
        if (in_array($oncekiTur, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) continue;
        if (in_array($ad, $yasak, true)) { $bulunan[] = $ad . '()'; continue; }
        if (in_array($ad, ['file_get_contents', 'fopen', 'get_headers'], true)) {
            $a = $k + 1; while ($a < $n && in_array($kullan($t[$a]), [T_WHITESPACE, T_COMMENT], true)) $a++;
            if (($kullan($t[$a] ?? null) === T_CONSTANT_ENCAPSED_STRING) && preg_match('/^[\'"]https?:/i', $t[$a][1])) $bulunan[] = $ad . '(http…)';
        }
    }
    return $bulunan;
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
$ihlal = []; $izinliGorulen = [];
foreach ($it as $dosya) {
    $yol = str_replace('\\', '/', substr((string)$dosya, strlen($ROOT) + 1));
    if (!str_ends_with($yol, '.php')) continue;
    if (preg_match('#^(vendor|scripts|tools|docs|storage|uploads|\.git|\.claude)/#', $yol)) continue;
    if ($yol === 'deploy.php') continue;   // sunucuya özgü, repoda yok; deploy atlar
    $bul = dis_baglanti_cagrilari((string)file_get_contents((string)$dosya));
    if ($bul === []) continue;
    if (in_array($yol, $izinli, true)) { $izinliGorulen[] = $yol; continue; }
    $ihlal[] = $yol . ' [' . implode(',', array_unique($bul)) . ']';
}
ok('Repoda izinli dosyalar dışında dış bağlantı kodu yok (yenisi eklenirse önce kapı kurulmalı)', $ihlal === [], implode('; ', $ihlal));
ok('İzinli dosyaların hepsi gerçekten dış bağlantı kodu taşıyor (liste bayatlamamış)', count(array_diff($izinli, $izinliGorulen)) === 0, implode(', ', array_diff($izinli, $izinliGorulen)));
$ep = oku('halkayit/endpoint_test.php');
ok('endpoint_test.php: curl öncesi app_dis_gonderim_kapali() kapısı', (bool)preg_match('/function endpoint_dene\([^)]*\)[^{]*\{.*?app_dis_gonderim_kapali\(\).*?curl_init/s', $ep));

// ── 7) Şerit + sekme başlığı + CSS ───────────────────────────────────
$h = oku('config/helpers.php');
ok('render_header: test şeridi yalnız app_test_ortami() iken basılır', (bool)preg_match('/!\$print_mode && app_test_ortami\(\)\):\s*\?>\s*<div class="test-serit"/', $h));
ok('render_header: sekme başlığına [TEST] öneki', str_contains($h, "app_test_ortami() ? '[TEST] ' : ''"));
ok('helpers.php ortam.php\'yi yükler', str_contains($h, "require_once __DIR__ . '/ortam.php'"));
ok('db.php ortam.php\'yi yükler', str_contains(oku('config/db.php'), "require_once __DIR__ . '/ortam.php'"));
$css = oku('assets/style.css');
ok('style.css: .test-serit tıklamayı engellemez + baskıda gizli', (bool)preg_match('/\.test-serit\s*\{[^}]*pointer-events:\s*none/s', $css) && (bool)preg_match('/@media print \{ \.test-serit \{ display: none !important; \} \}/', $css));
ok('ortam.php doğrudan istekte 404', str_contains(oku('config/ortam.php'), 'http_response_code(404)'));

// ── 8) Sürüm eşleşmesi ───────────────────────────────────────────────
preg_match("/define\('APP_SURUM', '(v\d+)'\)/", $h, $a);
preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", oku('sw.js'), $b);
preg_match('/\*\*SW Cache:\*\* `yukleme-plani-(v\d+)`/', oku('CLAUDE.md'), $c);
ok('APP_SURUM == sw.js CACHE_NAME == CLAUDE.md', ($a[1] ?? 'x') === ($b[1] ?? 'y') && ($a[1] ?? 'x') === ($c[1] ?? 'z'), ($a[1] ?? '?') . '/' . ($b[1] ?? '?') . '/' . ($c[1] ?? '?'));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
