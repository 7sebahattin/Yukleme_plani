<?php
// =========================================================
// scripts/stok_gate_static_smoke.php — Stok (muhasebe): kapı / csrf / audit / dosya adı / defter değişmezliği STATİK denetimi
//   php scripts/stok_gate_static_smoke.php     → çıkış kodu 0 = hepsi geçti
// Kaynağa bakar (yorumlar token_get_all ile atılır; çalıştırma YOK). Davranış testleri: stok_core_smoke.php · stok_ui_smoke.php.
// Parça A + B'yi birlikte tanır: 4 fiş sayfası ya yer tutucu ("hazırlanıyor") ya da çalışan modül kabuğu olmalı.
// =========================================================
declare(strict_types=1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
/** PHP kaynağından yorumları atar. */
function kod(string $yol): string
{
    $s = (string)file_get_contents($yol); $o = '';
    foreach (token_get_all($s) as $t) { if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $o .= $t[1]; } else $o .= $t; }
    return $o;
}
/** Fonksiyon gövdesi (süslü parantez dengesiyle). */
function govde(string $kod, string $ad): ?string
{
    if (!preg_match('/function\s+' . preg_quote($ad, '/') . '\s*\(/', $kod, $m, PREG_OFFSET_CAPTURE)) return null;
    $i = strpos($kod, '{', $m[0][1]); if ($i === false) return null;
    $d = 0;
    for ($j = $i, $n = strlen($kod); $j < $n; $j++) { if ($kod[$j] === '{') $d++; elseif ($kod[$j] === '}' && --$d === 0) return substr($kod, $i, $j - $i + 1); }
    return null;
}
$pos = fn(string $s, string $a): int => ($p = strpos($s, $a)) === false ? -1 : $p;
$SQL_YAZ = '/\b(INSERT\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM)\b/i';

echo "=== 1. Dosya kümesi ve adlar ===\n";
$kok = array_map('basename', glob($ROOT . '/stok_*.php'));
sort($kok);
$beklenen = ['stok_cikis_fisleri.php', 'stok_donusum_fisleri.php', 'stok_giris_fisleri.php', 'stok_kartlari.php', 'stok_kaydet.php', 'stok_tanimlari.php'];
ok('kökte stok_*.php tam 6 dosya (+ transfer_fisleri.php)', $kok === $beklenen && is_file($ROOT . '/transfer_fisleri.php'), json_encode($kok));
ok('api_stok*.php / _stok*.php kökte YOK', !glob($ROOT . '/api_stok*.php') && !glob($ROOT . '/_stok*.php'));
$sw = (string)file_get_contents($ROOT . '/sw.js');
ok('sw.js SW_MUHASEBE_RE bulundu', (bool)preg_match('#var SW_MUHASEBE_RE = /(.+)/;#', $sw, $rm));
$re = '#' . str_replace('\\/', '/', $rm[1] ?? 'x') . '#';
foreach ([...$beklenen, 'transfer_fisleri.php', 'stok_yeni_bir_sayfa.php'] as $f) ok("sw.js ağ-only kuralı /$f dosyasını kapsar", @preg_match($re, '/' . $f) === 1, $re);
foreach (['stok.php', 'malzeme_stok.php', 'malzeme_stok_islem.php', 'malzeme_stok_rapor.php', 'malzeme_stok_import.php', 'malzeme_stok_tehis.php', 'records.php'] as $f) {
    ok("sw.js ağ-only kuralı /$f dosyasını KAPSAMAZ (eski stok ekranları önbellek davranışı değişmez)", @preg_match($re, '/' . $f) === 0);
}
ok('sw.js SHELL\'e stok.css/js girmez', !preg_match('#SHELL[^;]*assets/stok\.(css|js)#s', $sw));
ok('config/stok_core.php + stok_ui.php var; assets/stok.css|js var', is_file($ROOT . '/config/stok_core.php') && is_file($ROOT . '/config/stok_ui.php') && is_file($ROOT . '/assets/stok.css') && is_file($ROOT . '/assets/stok.js'));
ok('kantar_fisleri.php değişmedi: 302 → kantar.php, stok_core yok', str_contains(kod($ROOT . '/kantar_fisleri.php'), "header('Location: kantar.php', true, 302)") && !str_contains(kod($ROOT . '/kantar_fisleri.php'), 'stok_core'));

echo "\n=== 2. Sayfa kabuğu (çalışan sayfalar) ===\n";
$fisSayfalari = ['stok_giris_fisleri.php', 'stok_cikis_fisleri.php', 'transfer_fisleri.php', 'stok_donusum_fisleri.php'];
$calisan = ['stok_kartlari.php', 'stok_tanimlari.php'];
foreach ($fisSayfalari as $f) {
    $s = kod($ROOT . '/' . $f);
    if (str_contains($s, 'config/stok_core.php')) $calisan[] = $f;
    else ok("$f: yer tutucu (Parça B'de dolacak): is_admin kapısı + 'hazırlanıyor'", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s) && str_contains($s, 'hazırlanıyor') && !str_contains($s, '$_POST'));
}
foreach ($calisan as $f) {
    $s = kod($ROOT . '/' . $f);
    ok("$f: <?php + strict_types", str_starts_with($s, '<?php') && str_contains($s, 'declare(strict_types=1)'));
    $sira = ['require_login()', 'if (!is_admin()) { forbidden(', "stok_kapi('read')", 'stok_no_store()', 'stok_sayfa_kapisi($pdo)', 'render_header('];
    $ps = array_map(fn($a) => $pos($s, $a), $sira);
    ok("$f: kabuk sırası require_login → is_admin → stok_kapi('read') → stok_no_store → stok_sayfa_kapisi → render_header", !in_array(-1, $ps, true) && $ps === array_values(array_unique($ps)) && $ps == array_values((function ($x) { sort($x); return $x; })($ps)), json_encode($ps));
    ok("$f: config/stok_core.php + stok_ui.php yüklenir", str_contains($s, 'config/stok_core.php') && str_contains($s, 'config/stok_ui.php'));
    ok("$f: stok_assets() + stok_scripts() + render_footer()", str_contains($s, 'stok_assets()') && str_contains($s, 'stok_scripts()') && str_contains($s, 'render_footer()'));
    ok("$f: yalnız GET — \$_POST YOK, csrf_check YOK, Location YOK", !str_contains($s, '$_POST') && !str_contains($s, 'csrf_check(') && !preg_match("/header\\s*\\(\\s*['\"]Location/i", $s));
    ok("$f: SQL yazma YOK, ->prepare/->query YOK (okuma yalnız stok_core'dan)", !preg_match($SQL_YAZ, $s) && !preg_match('/->(prepare|query|exec)\(/', $s));
    ok("$f: 'hazırlanıyor' yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
}

echo "\n=== 3. stok_kaydet.php: tek yazma ucu ===\n";
$k = kod($ROOT . '/stok_kaydet.php');
$p405 = $pos($k, '405'); $pCsrf = $pos($k, 'csrf_check(');
ok('POST dışı → 405 + Allow, require_login ve csrf_check\'ten ÖNCE', $p405 > 0 && $p405 < $pos($k, 'require_login()') && $p405 < $pCsrf && str_contains($k, "'POST'") && str_contains($k, "header('Allow: POST')"));
ok('csrf_check($_POST[\'csrf\']) var; JSON başlığı csrf_check\'ten ÖNCE', str_contains($k, "csrf_check(\$_POST['csrf']") && $pos($k, 'application/json') < $pCsrf);
ok('sıra: csrf_check → stok_sayfa_kapisi → ilk stok_kapi', $pCsrf > 0 && $pCsrf < $pos($k, 'stok_sayfa_kapisi(') && $pos($k, 'stok_sayfa_kapisi(') < $pos($k, 'stok_kapi('));
$ilkYaz = PHP_INT_MAX;
foreach (['stok_kart_kaydet(', 'stok_kart_durum(', 'stok_kart_sil(', 'stok_tanim_kaydet(', 'stok_tanim_durum(', 'stok_tanim_sil(', 'stok_ice_aktar(', 'stok_fis_kaydet(', 'stok_fis_iptal('] as $fn) { $p = $pos($k, $fn); if ($p >= 0) $ilkYaz = min($ilkYaz, $p); }
ok('csrf_check, ilk stok_ yazma çağrısından ÖNCE', $pCsrf > 0 && $pCsrf < $ilkYaz);
preg_match_all("/case '([a-z_]+)':(.*?)(?=case '|default:)/s", $k, $cm, PREG_SET_ORDER);
$isl = array_map(fn($m) => $m[1], $cm);
$A = ['kart_kaydet', 'kart_durum', 'kart_sil', 'tanim_kaydet', 'tanim_durum', 'tanim_sil', 'ice_aktar'];
ok('işlem dalları: Parça A 7 dal (Parça B: + fis_kaydet, fis_iptal)', $isl === $A || $isl === [...$A, 'fis_kaydet', 'fis_iptal'] || (array_slice($isl, 0, 7) === $A && !array_diff($isl, [...$A, 'fis_kaydet', 'fis_iptal'])), json_encode($isl));
$yetki = ['kart_kaydet' => 'write', 'kart_durum' => 'write', 'kart_sil' => 'delete', 'tanim_kaydet' => 'admin', 'tanim_durum' => 'admin', 'tanim_sil' => 'admin', 'ice_aktar' => 'admin', 'fis_kaydet' => 'write', 'fis_iptal' => 'delete'];
foreach ($cm as $m) {
    $y = $yetki[$m[1]] ?? '?';
    ok("dal $m[1]: stok_kapi('$y') İLK ifade; dalda tek stok_kapi; yalnız stok_ fonksiyonu", (bool)preg_match("/^\\s*stok_kapi\\('" . $y . "'\\);/", $m[2]) && substr_count($m[2], 'stok_kapi(') === 1 && !preg_match('/\bcari_[a-z_]+\(/', $m[2]), substr($m[2], 0, 80));
}
ok('`geri` yerel beyaz liste: stok_geri_guvenli($_POST[\'geri\']) + çekirdekte ^(stok_[a-z_]+|transfer_fisleri)\\.php regex', str_contains($k, "stok_geri_guvenli(\$_POST['geri']") && str_contains((string)file_get_contents($ROOT . '/config/stok_core.php'), '^(stok_[a-z_]+|transfer_fisleri)\\.php'));
ok('stok_kaydet.php: $_GET kullanmaz; doğrudan SQL yazmaz; ->prepare yok', !str_contains($k, '$_GET') && !preg_match($SQL_YAZ, $k) && !preg_match('/->(prepare|query|exec)\(/', $k));
ok('PDOException → 500 genel mesaj (ayrıntı yalnız error_log)', str_contains($k, 'catch (PDOException $e)') && str_contains($k, 'http_response_code(500)') && str_contains($k, 'error_log('));

echo "\n=== 4. Defter değişmezliği (yalnız çekirdek yazar) ===\n";
$ihlal = []; $cariIhlal = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $yol = $f->getPathname();
    if (!str_ends_with($yol, '.php') || preg_match('#/(vendor|scripts|storage|\.git|\.claude|node_modules)/#', $yol)) continue;
    if ($yol === $ROOT . '/config/stok_core.php') continue;
    if (preg_match('/\b(INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?stok_(hareketler|fisler|fis_satirlar|kartlar|tanimlar)\b/i', kod($yol))) $ihlal[] = substr($yol, strlen($ROOT) + 1);
}
ok('stok_* tablolarına INSERT/UPDATE/DELETE yazan başka dosya YOK (config/stok_core.php hariç)', !$ihlal, implode(',', $ihlal));
$c = kod($ROOT . '/config/stok_core.php');
ok('core: stok_hareketler\'de UPDATE / DELETE YOK (INSERT-only defter)', !preg_match('/(UPDATE\s+`?stok_hareketler|DELETE\s+FROM\s+`?stok_hareketler)/i', $c));
ok('core: stok_fis_satirlar\'da UPDATE / DELETE YOK (insert sonrası değişmez)', !preg_match('/(UPDATE\s+`?stok_fis_satirlar|DELETE\s+FROM\s+`?stok_fis_satirlar)/i', $c));
ok('core: stok_fisler\'de DELETE YOK', !preg_match('/DELETE\s+FROM\s+`?stok_fisler/i', $c));
preg_match_all('/UPDATE\s+`?stok_fisler`?\s+SET\s+([^"\']+?)\s+WHERE/i', $c, $um);
$setler = array_map(fn($x) => preg_replace('/\s+/', ' ', trim($x)), $um[1]);
ok('core: stok_fisler UPDATE\'leri yalnız beyaz liste (fis_no[, cari_hareket_id] · durum=iptal + iptal_*)', !array_filter($setler, fn($x) => !preg_match('/^(fis_no = \?(, cari_hareket_id = \?)?|cari_hareket_id = \?|durum = \'iptal\', iptal_at = \?, iptal_by = \?, iptal_neden = \?)$/', $x)), json_encode($setler));
ok('core: cari tablolarına doğrudan SQL YOK (cari bağı yalnız cari_core fonksiyonlarıyla)', !preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+`?cari_(hareketler|kartlar|tanimlar)\b/i', $c));
ok('core: named parametre (:ad) kullanılmaz — pozisyonel ?', !preg_match('/[ (,=]:[a-z_]{2,}\b/', $c));
ok('core: depo filtresi yalnız depo_sql_in (depo_sql_column / depo_sql_records YOK)', str_contains($c, 'depo_sql_in(') && !str_contains($c, 'depo_sql_column(') && !str_contains($c, 'depo_sql_records'));
$yazanlar = ['stok_kart_kaydet', 'stok_kart_durum', 'stok_kart_sil', 'stok_tanim_kaydet', 'stok_tanim_durum', 'stok_tanim_sil', 'stok_ice_aktar'];
foreach (['stok_fis_kaydet', 'stok_fis_iptal'] as $b) if (govde($c, $b) !== null) $yazanlar[] = $b;
foreach ($yazanlar as $fn) ok("core $fn: audit_log_event( var", ($g = govde($c, $fn)) !== null && str_contains($g, 'audit_log_event('));
$yetkili = [];
foreach ($yazanlar as $fn) if (preg_match('/\b(can|is_admin|require_perm|stok_can|stok_kapi|cari_can|cari_kapi)\s*\(/', (string)govde($c, $fn))) $yetkili[] = $fn;
ok('core: yazan fonksiyonlarda yetki kontrolü YOK (uçların işi)', !$yetkili, implode(',', $yetkili));
ok('core: stok_can yalnız is_admin() (tek değişim noktası; stok.read/write izinlerine bağlanmadı)', ($g = govde($c, 'stok_can')) !== null && str_contains($g, 'is_admin()') && !str_contains($g, 'can('));
ok('core: maliyet motoru SAF (stok_maliyet_uygula içinde PDO/sorgu YOK)', ($g = govde($c, 'stok_maliyet_uygula')) !== null && !preg_match('/PDO|->|audit_log_event|\$GLOBALS/', $g));
$ui = kod($ROOT . '/config/stok_ui.php');
ok('ui: sorgu YAZMAZ (INSERT/UPDATE/DELETE yok, ->prepare/->exec/->query yok)', !preg_match($SQL_YAZ, $ui) && !preg_match('/->(exec|prepare|query)\(/', $ui));
ok('ui + core: named parametre yok; ui depo_sql_ yok', !preg_match('/[ (,=]:[a-z_]{2,}\b/', $ui) && !str_contains($ui, 'depo_sql_'));
$cariCore = kod($ROOT . '/config/cari_core.php');
ok('cari_core.php stok_ adı içermez (Parça A cari çekirdeğine dokunmaz)', !str_contains($cariCore, 'stok_'));

echo "\n=== 5. Şema yalnız migrate.php'den ===\n";
$gecerli = [];
foreach (glob($ROOT . '/*.php') as $f) if (preg_match('/\bstok_migrate\s*\(/', kod($f))) $gecerli[] = basename($f);
ok('stok_migrate( tek çağıran migrate.php (kök sayfalar)', $gecerli === ['migrate.php'], json_encode($gecerli));
$db = (string)file_get_contents($ROOT . '/config/db.php');
ok('config/db.php\'de stok_ CREATE yok (otomatik migrasyon YOK)', !preg_match('/CREATE\s+TABLE[^;]*stok_(kartlar|tanimlar|fisler|fis_satirlar|hareketler)/i', $db) && !str_contains($db, 'stok_kartlar'));
ok('stok tablolarında FOREIGN KEY / ALTER yok', !preg_match('/FOREIGN\s+KEY|ALTER\s+TABLE/i', (string)(govde($c, 'stok_tablolar') ?? '')));
$mg = (string)file_get_contents($ROOT . '/migrate.php');
ok('migrate.php: require stok_core + mg_kart_ac(\'stok\') + ne=stok dalı (csrf + audit migrate)', str_contains($mg, "config/stok_core.php") && str_contains($mg, "mg_kart_ac('stok')")
    && (bool)preg_match("/=== 'stok'\)\s*\{\s*\n\s*csrf_check[^}]*stok_migrate\(\\\$pdo\)[^}]*audit_log_event\('migrate', 'stok'/s", $mg));
ok('migrate.php: stok kartı cari kartından SONRA', $pos($mg, "mg_kart_ac('cari')") > 0 && $pos($mg, "mg_kart_ac('stok')") > $pos($mg, "mg_kart_ac('cari')"));

echo "\n=== 6. İstemci varlıkları ===\n";
$css = (string)file_get_contents($ROOT . '/assets/stok.css');
$js = (string)file_get_contents($ROOT . '/assets/stok.js');
ok('stok.css: html overflow-x:hidden YOK; yalnız .stok / st- / pm- seçicileri', !preg_match('/html\s*\{[^}]*overflow-x\s*:\s*hidden/', $css) && !preg_match('/(^|\})\s*(body|html|input|select|\.btn|\.card)\s*[\{,]/m', $css));
ok('stok.css: <768 girdi 16px kuralı + --bn-h ile alt çubuk payı (sabit px yok)', str_contains($css, 'font-size: 16px') && str_contains($css, 'var(--bn-h)') && str_contains($css, 'env(safe-area-inset-bottom'));
ok('stok.css: karanlık mod tokenları (html[data-theme="dark"] .stok)', str_contains($css, 'html[data-theme="dark"] .stok'));
ok('stok.css: z-index değerleri < 600 ya da ≥ 600 modal/toast (eylem çubuğuna z-index YOK)', !preg_match('/\.st-islemler\s*\{[^}]*z-index/', $css));
ok('style.css / app.js stok sınıflarına dokunmaz', !str_contains((string)file_get_contents($ROOT . '/assets/style.css'), '.st-') && !str_contains((string)file_get_contents($ROOT . '/assets/app.js'), 'stok_kaydet'));
ok('stok.js: yazma yalnız stok_kaydet.php; yönlendirme beyaz listesi; localStorage YOK', str_contains($js, "kaydet: 'stok_kaydet.php'") && str_contains($js, 'yonRe: /^(stok_[a-z_]+|transfer_fisleri)\\.php') && !str_contains($js, 'localStorage'));
ok('stok.js: innerHTML yalnız sunucu kısmi yanıtı için (tek yer)', substr_count($js, 'innerHTML') === 1);

echo "\n=== 7. Sürüm eşitliği ===\n";
preg_match('/define\(\'APP_SURUM\',\s*\'v(\d+)\'/', (string)file_get_contents($ROOT . '/config/helpers.php'), $a);
preg_match('/CACHE_NAME\s*=\s*[\'"]yukleme-plani-v(\d+)/', $sw, $b);
preg_match('/\*\*SW Cache:\*\* `yukleme-plani-v(\d+)`/', (string)file_get_contents($ROOT . '/CLAUDE.md'), $cc);
ok('APP_SURUM == sw.js CACHE_NAME == CLAUDE.md SW Cache; ≥ 524', isset($a[1], $b[1], $cc[1]) && $a[1] === $b[1] && $b[1] === $cc[1] && (int)$a[1] >= 524, json_encode([$a[1] ?? null, $b[1] ?? null, $cc[1] ?? null]));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
