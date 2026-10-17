<?php
// =========================================================
// scripts/siparis_gate_static_smoke.php — Siparişler (v371) STATİK değişmezleri
// Kaynak koda bakar (sayfa çalıştırmaz): kapı tek kaynak, her uç oturum + kapı +
// (POST'ta) CSRF ister, migrate yalnız migrate.php'de, dosya sunumu başlıkları,
// SW siparis*.php'yi önbelleğe almaz, sürüm eşitliği. CLI; DB'ye dokunmaz.
//   php scripts/siparis_gate_static_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function oku(string $f): string { global $ROOT; return is_file($ROOT . '/' . $f) ? (string)file_get_contents($ROOT . '/' . $f) : ''; }
/** PHP yorumlarını atar (yorumdaki ad/anahtar sözcük yanlış alarm vermesin); HTML/string içeriği kalır. */
function kod_oku(string $f): string {
    $src = oku($f);
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $out .= $t[1]; }
        else $out .= $t;
    }
    return $out;
}

$helpers = oku('config/helpers.php'); $index = oku('index.php'); $sw = oku('sw.js');
$core = oku('config/siparis_core.php'); $migrate = oku('migrate.php'); $auth = oku('config/auth.php');

// Kök dizindeki tüm sipariş uç/sayfaları (partial `_siparis_liste.php` "_" ile başlar, eşleşmez)
$sayfalar = [];
foreach (glob($ROOT . '/siparis*.php') ?: [] as $f) $sayfalar[] = basename($f);
sort($sayfalar);
$BEKLENEN = ['siparis_ayarlar.php', 'siparis_belge.php', 'siparis_beyan_bagla.php', 'siparis_dosya.php', 'siparis_kaydet.php',
             'siparis_pkl.php', 'siparis_view.php', 'siparis_yukle.php', 'siparis_zip.php', 'siparisler.php'];

echo "=== Kapı tek kaynak ===\n";
ok('helpers.php can_siparis() tanımlı', str_contains($helpers, 'function can_siparis(string $perm): bool'));
ok('can_siparis fail-closed (can() yoksa false)', (bool)preg_match("/function can_siparis[^{]*\{\s*if \(!function_exists\('can'\)\) return false;/", $helpers));
$a = strpos($helpers, 'function nav_ptak_sayfalari(): array {'); $b = strpos($helpers, 'function render_desktop_sidebar(');
$c = strpos($helpers, 'function can_siparis(');
ok('can_siparis nav_ptak_sayfalari ile render_desktop_sidebar ARASINDA (rol_kapilari_smoke eval bölgesi)',
    $a !== false && $b !== false && $c !== false && $a < $c && $c < $b);
ok("sidebar \$p_sip = can_siparis('read')", str_contains($helpers, "\$p_sip   = can_siparis('read');"));
ok('sidebar sipariş bağlantısı $p_sip kapılı', str_contains($helpers, "if (\$p_sip)  \$lnk('siparisler.php'"));
ok('topnav sipariş bağlantısı can_siparis kapılı', (bool)preg_match("/if \(can_siparis\('read'\)\): \?>\s*\n\s*<a href=\"<\?= \\\$base \?>siparisler\.php\"/", $helpers));
ok("nav_alt_izinler siparis = can_siparis('read')", str_contains($helpers, "'siparis' => \$fn && can_siparis('read')"));
ok('nav_alt_sayfalar siparis kaydı (siparisler.php, ikon, grup Operasyon)',
    (bool)preg_match("/'siparis' => \['href' => 'siparisler\.php',[^\n]*'grup' => 'Operasyon', 'ikon' => 'assets\/nav-icons\/siparis\.svg'\]/", $helpers));
ok("first_allowed_page siparisler.php = can_siparis('read')", str_contains($helpers, "'siparisler.php'     => can_siparis('read')"));
ok('nav_aktif_anahtar siparis bölümü', str_contains($helpers, "'siparis' => \$a_sip") && str_contains($helpers, "\$a_sip   = in_array(\$cur, ['siparisler.php'"));
ok('index.php kartı can_siparis kapılı', (bool)preg_match("/if \(can_siparis\('read'\)\): \?>\s*\n\s*<a href=\"siparisler\.php\"/", $index));
ok('index.php bölüm görünürlüğü can_siparis içeriyor', str_contains($index, "|| can_siparis('read') || can_mail('read');"));
ok('siparis.* yetkileri helpers.php\'de YALNIZ can_siparis içinde okunuyor (4 çağrı)', substr_count($helpers, "can('siparis.") === 4,
    'sayım: ' . substr_count($helpers, "can('siparis."));
foreach (['index.php', 'migrate.php'] as $f) {
    ok("$f kapıyı can('siparis.*') ile KOPYALAMIYOR", preg_match("/\bcan\('siparis\./", oku($f)) === 0);
}
ok('sipariş sayfa/uçları sayısı beklenenle aynı (Paket 2 dosyaları mevcut)', $sayfalar === $BEKLENEN,
    'bulunan: ' . implode(',', $sayfalar) . ' | eksik: ' . implode(',', array_diff($BEKLENEN, $sayfalar)));
foreach ($sayfalar as $f) {
    $k = kod_oku($f);
    ok("$f require_login() çağırıyor", str_contains($k, 'require_login()'));
    ok("$f require_siparis( kapısı var", str_contains($k, 'require_siparis('));
    ok("$f kapıyı can('siparis.*') ile KOPYALAMIYOR", preg_match("/\bcan\('siparis\./", $k) === 0);
}
ok("siparisler.php birebir require_siparis('read'); satırı (bottomnav kapı iğnesi)", str_contains(oku('siparisler.php'), "require_siparis('read');"));

echo "\n=== POST uçlarında CSRF mutasyondan ÖNCE ===\n";
$MUT = ['siparis_alan_elle', 'siparis_alan_kilidi_kaldir', 'siparis_catisma_coz', 'siparis_durum_degistir', 'siparis_durum_ilerlet',
        'siparis_sevkiyat_elle_ac', 'siparis_sevkiyat_sil', 'siparis_belge_sil', 'siparis_dosya_kaydet', 'siparis_belge_isle',
        'siparis_pkl_kaydet_taslak', 'siparis_pkl_uret', 'siparis_profil_kaydet', 'siparis_profil_sil', 'siparis_sayac_ayarla',
        'siparis_beyan_bagla', 'siparis_beyan_bag_kaldir', 'siparis_sevkiyat_yenile'];
$mutRe = '/\b(' . implode('|', $MUT) . ')\s*\(/';
foreach ($sayfalar as $f) {
    $k = kod_oku($f);
    if (!preg_match($mutRe, $k, $m, PREG_OFFSET_CAPTURE)) continue;
    $ilk = $m[0][1];
    $cs = strpos($k, 'csrf_check(');
    ok("$f: csrf_check( var ve ilk mutasyon ({$m[1][0]}) öncesinde", $cs !== false && $cs < $ilk);
    ok("$f: mutasyon POST'a bağlı", (bool)preg_match("/REQUEST_METHOD'\]\s*(!==|===)\s*'POST'/", $k));
    ok("$f: yazma kapısı require_siparis('write'|'delete'|'match') içeriyor", (bool)preg_match("/require_siparis\('(write|delete|match)'\)|can_siparis\('(write|delete|match)'\)/", $k));
}
foreach (['siparis_dosya.php', 'siparis_zip.php', 'siparis_belge.php', 'siparis_view.php', 'siparisler.php', 'siparis_ayarlar.php'] as $f) {
    $k = kod_oku($f);
    if ($k === '') continue;
    // GET-only görünüm sayfaları (POST'u siparis_kaydet/… uçlarına gider): ayarlar sayfası profil/sayaç formu içerebilir → muaf.
    if ($f === 'siparis_ayarlar.php') continue;
    ok("$f: doğrudan veri değiştiren çekirdek çağrısı YOK", preg_match($mutRe, $k) === 0);
}

echo "\n=== Yetki kataloğu ve seed ===\n";
foreach (['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'] as $p) {
    ok("katalogda $p", str_contains($auth, "'$p'"));
    ok("admin seed listesinde $p", str_contains($helpers, "'$p'"));
}
ok("katalog grubu 'Siparişler'", str_contains($auth, "'Siparişler' => ["));
ok('operator seed: siparis.read/write/match', (bool)preg_match("/'operator' => \[[^\]]*'siparis\.read','siparis\.write','siparis\.match'\]/", $helpers));
ok('operator seed siparis.delete İÇERMİYOR', (bool)preg_match("/'operator' => \[[^\]]*\]/", $helpers, $om) && !str_contains($om[0], 'siparis.delete'));
ok('viewer + muhasebe seed: yalnız siparis.read',
    (bool)preg_match("/'viewer'   => \[[^\]]*'siparis\.read'\]/", $helpers) && (bool)preg_match("/'muhasebe' => \[[^\]]*'siparis\.read'/", $helpers));
ok('sync_depot_name_in_data siparis_sevkiyatlar + siparis_belgeler', str_contains($helpers, "['siparis_sevkiyatlar',      'depo']") && str_contains($helpers, "['siparis_belgeler',         'depo']"));

echo "\n=== Şema yalnız migrate.php'den ===\n";
$cagiran = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS)) as $f) {
    $yol = (string)$f;
    if (!str_ends_with($yol, '.php') || str_contains($yol, '/vendor/') || str_contains($yol, '/scripts/') || str_contains($yol, '/.git/')) continue;
    if (preg_match('/\bsiparis_migrate\s*\(/', kod_oku(substr($yol, strlen($ROOT) + 1)))) $cagiran[] = substr($yol, strlen($ROOT) + 1);
}
sort($cagiran);
ok('siparis_migrate() yalnız tanım + migrate.php', $cagiran === ['config/siparis_core.php', 'migrate.php'], implode(',', $cagiran));
ok('migrate.php sipariş kartı: CSRF + audit + id="siparis"',
    str_contains($migrate, "'ne'] ?? '') === 'siparis'") && str_contains($migrate, "audit_log_event('migrate', 'siparis'") && str_contains($migrate, 'id="siparis"'));
ok('migrate.php kartı ne=siparis formu + 10 tabloyu siparis_tablolar() ile listeler', str_contains($migrate, 'name="ne" value="siparis"') && str_contains($migrate, 'siparis_tablolar()'));
ok('db.php siparis_ tablosunu auto-migration\'a eklemiyor', !str_contains(oku('config/db.php'), 'siparis_'));
ok('helpers.php siparis_tablolar içermiyor (IIFE migrasyonuna eklenmedi)', !str_contains($helpers, 'siparis_tablolar'));
ok('DDL\'de ALTER yok', stripos($core, 'ALTER TABLE') === false);
ok('DDL\'de FOREIGN KEY yok (bütünlük uygulama kodunda)', stripos($core, 'FOREIGN KEY') === false);

echo "\n=== Güvenlik: PKL / ayrıştırıcı / dosya sunumu ===\n";
$pkl = kod_oku('config/siparis_pkl.php');
ok('siparis_pkl.php mevcut', $pkl !== '');
ok('siparis_pkl.php PhpOffice kullanmıyor (doğrudan XML yamalama)', $pkl !== '' && stripos($pkl, 'PhpOffice') === false);
ok('siparis_pkl.php exec/shell_exec/eval/popen YOK', $pkl !== '' && preg_match('/\b(exec|shell_exec|passthru|system|popen|proc_open|eval)\s*\(/', $pkl) === 0);
$pdf = kod_oku('config/siparis_pdf.php');
ok('siparis_pdf.php strict_types DEĞİL (gevşek tip dönüşümüne dayanır)', $pdf !== '' && !preg_match('/declare\s*\(\s*strict_types\s*=\s*1/', $pdf));
ok('siparis_pdf.php exec/shell_exec/eval YOK', $pdf !== '' && preg_match('/\b(exec|shell_exec|passthru|system|popen|proc_open|eval)\s*\(/', $pdf) === 0);
$dosya = oku('siparis_dosya.php');
ok('siparis_dosya.php nosniff', str_contains($dosya, 'nosniff'));
ok('siparis_dosya.php no-store', str_contains($dosya, 'no-store'));
ok('siparis_dosya.php session_write_close', str_contains($dosya, 'session_write_close'));
ok('siparis_dosya.php belge görünürlük (depo) kapısı', str_contains($dosya, 'siparis_belge_gorunur('));
ok('siparis_zip.php belge görünürlük (depo) kapısı', str_contains(oku('siparis_zip.php'), 'siparis_belge_gorunur('));
ok('storage/siparis/.htaccess "Require all denied"', str_contains(oku('storage/siparis/.htaccess'), 'Require all denied'));
$gi = oku('.gitignore');
ok('.gitignore storage/siparis/* + !storage/siparis/.htaccess', str_contains($gi, 'storage/siparis/*') && str_contains($gi, '!storage/siparis/.htaccess'));
ok('PKL şablonu templates/excel/pkl_v14_blank.xlsx mevcut', is_file($ROOT . '/templates/excel/pkl_v14_blank.xlsx'));

echo "\n=== Audit eylem adları ≤ 40 karakter ===\n";
$uzun = []; $sayi = 0;
$dosyalar = array_merge(['config/siparis_core.php', 'config/siparis_eslestir.php', 'config/siparis_pkl.php', 'config/siparis_beyan.php', 'config/siparis_pdf.php', 'migrate.php'], $sayfalar, ['_siparis_liste.php']);
foreach ($dosyalar as $f) {
    $k = kod_oku($f);
    if (preg_match_all("/\b(?:siparis_audit|audit_log_event)\(\s*'([^']+)'/", $k, $mm)) {
        foreach ($mm[1] as $act) { $sayi++; if (strlen($act) > 40) $uzun[] = "$f:$act"; }
    }
}
ok("audit eylem sabitleri bulundu ($sayi) ve hepsi ≤ 40 karakter", $sayi > 0 && !$uzun, implode(', ', $uzun));

echo "\n=== Service Worker + sürüm ===\n";
ok('sw.js siparis bypass kuralı', str_contains($sw, "/\\/siparis(ler|_[a-z]+)?\\.php(\\/|\$)/.test(u.pathname)"));
ok('sw.js SHELL siparis.svg', str_contains($sw, "'./assets/nav-icons/siparis.svg'"));
ok('siparis.css/js SHELL\'de DEĞİL (mail emsali)', !str_contains($sw, 'siparis.css') && !str_contains($sw, 'siparis.js'));
ok('assets/nav-icons/siparis.svg mevcut + geçerli SVG', is_file($ROOT . '/assets/nav-icons/siparis.svg') && str_contains(oku('assets/nav-icons/siparis.svg'), '<svg') && str_contains(oku('assets/nav-icons/siparis.svg'), 'viewBox="0 0 64 64"'));
preg_match("/define\('APP_SURUM', '(v\d+)'\)/", $helpers, $m1);
preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", $sw, $m2);
ok('APP_SURUM == sw.js CACHE_NAME', ($m1[1] ?? 'a') === ($m2[1] ?? 'b'), (($m1[1] ?? '?') . ' / ' . ($m2[1] ?? '?')));
ok('sürüm ≥ v371 (Siparişler modülünün sürümü)', (int)ltrim($m1[1] ?? 'v0', 'v') >= 371);
ok('CLAUDE.md üst satırı aynı sürümü taşıyor', str_contains(oku('CLAUDE.md'), '`yukleme-plani-' . ($m1[1] ?? '?') . '`'));

echo "\n";
printf("%d geçti, %d başarısız\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
