<?php
// =========================================================
// scripts/cari_gate_static_smoke.php — Cari İşlemler: kapı / csrf / audit / dosya adı / ledger değişmezliği STATİK denetimi
//   php scripts/cari_gate_static_smoke.php     → çıkış kodu 0 = hepsi geçti
// Kaynağa bakar (yorumlar token_get_all ile atılır; çalıştırma YOK). Davranış testleri: cari_core_smoke.php · cari_ui_smoke.php.
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

echo "=== 1. Dosya kümesi ve adlar ===\n";
$kok = array_map('basename', glob($ROOT . '/cari*.php'));
sort($kok);
$beklenen = ['cari_dekontlar.php', 'cari_ekstre.php', 'cari_fisler.php', 'cari_kartlari.php', 'cari_kaydet.php', 'cari_tanimlari.php'];
ok('kökte cari*.php tam 6 dosya', $kok === $beklenen, json_encode($kok));
ok('hepsi `cari_` ile başlar (sw.js SW_MUHASEBE_RE kapsamı için); api_cari*.php / _cari_*.php kökte YOK', !glob($ROOT . '/api_cari*.php') && !glob($ROOT . '/_cari*.php') && !array_filter($kok, fn($f) => !str_starts_with($f, 'cari_')));
$sw = (string)file_get_contents($ROOT . '/sw.js');
ok('sw.js SW_MUHASEBE_RE bulundu', (bool)preg_match('#var SW_MUHASEBE_RE = /(.+)/;#', $sw, $rm));
$re = '#' . str_replace('\\/', '/', $rm[1] ?? 'x') . '#';
foreach ($beklenen as $f) ok("sw.js ağ-only kuralı /$f dosyasını kapsar", @preg_match($re, '/' . $f) === 1, $re);
ok('sw.js SHELL\'e cari.css/js girmez (Frugro emsali)', !preg_match('/SHELL[^;]*cari\./s', $sw));
ok('config/cari_core.php + cari_ui.php var; assets/cari.css|js var', is_file($ROOT . '/config/cari_core.php') && is_file($ROOT . '/config/cari_ui.php') && is_file($ROOT . '/assets/cari.css') && is_file($ROOT . '/assets/cari.js'));

echo "\n=== 2. Her sayfa/uç: kapı + no-store + şema kapısı ===\n";
foreach ($beklenen as $f) {
    $s = kod($ROOT . '/' . $f);
    ok("$f: <?php ile başlar, strict_types", str_starts_with($s, '<?php') && str_contains($s, 'declare(strict_types=1)'));
    ok("$f: require_login()", str_contains($s, 'require_login()'));
    if ($f !== 'cari_kaydet.php') ok("$f: is_admin() kapısı (nav_muhasebe_static_smoke deseni)", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s));
    ok("$f: cari_no_store()", str_contains($s, 'cari_no_store()'));
    ok("$f: cari_sayfa_kapisi(\$pdo) (tablo yoksa çökmez)", str_contains($s, 'cari_sayfa_kapisi('));
    ok("$f: cari_kapi( çağrısı", str_contains($s, 'cari_kapi('));
    ok("$f: config/cari_core.php yüklenir", str_contains($s, "config/cari_core.php"));
}
$cari_pos = fn(string $s, string $a): int => (int)strpos($s, $a);

echo "\n=== 3. 4 sayfa + ekstre: yalnız GET ===\n";
foreach (['cari_kartlari.php', 'cari_fisler.php', 'cari_dekontlar.php', 'cari_tanimlari.php', 'cari_ekstre.php'] as $f) {
    $s = kod($ROOT . '/' . $f);
    ok("$f: \$_POST YOK", !str_contains($s, '$_POST'));
    ok("$f: header('Location YOK (yönlendirme sayfası değil)", !preg_match("/header\\s*\\(\\s*['\"]Location/i", $s));
    ok("$f: INSERT/UPDATE/DELETE SQL YOK (yazma yalnız cari_kaydet.php → cari_core)", !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $s));
    ok("$f: csrf_check YOK (yazma yok)", !str_contains($s, 'csrf_check('));
}
foreach (['cari_kartlari.php', 'cari_fisler.php', 'cari_dekontlar.php', 'cari_tanimlari.php'] as $f) {
    $s = kod($ROOT . '/' . $f);
    ok("$f: render_header( + render_footer() (nav testinin \"çalışan modül\" dalı)", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()') && !str_contains($s, 'hazırlanıyor'));
    ok("$f: cari_assets() + cari_scripts()", str_contains($s, 'cari_assets()') && str_contains($s, 'cari_scripts()'));
}
$e = kod($ROOT . '/cari_ekstre.php');
ok('cari_ekstre.php: xlsx/csv kolunda require_perm(\'reports.export\') + export_audit( (excel-export kuralı)', str_contains($e, "require_perm('reports.export')") && substr_count($e, 'export_audit(') >= 2);
ok('cari_ekstre.php: require_perm, export_audit\'ten ÖNCE; xlsx_indir( kullanılır; setCellValue(/fromArray( YOK', $cari_pos($e, "require_perm('reports.export')") < $cari_pos($e, 'export_audit(') && str_contains($e, 'xlsx_indir(') && !str_contains($e, 'setCellValue(') && !str_contains($e, 'fromArray('));
ok('cari_ekstre.php: SUBTOTAL/toplam satırı kullanılmaz (para birimleri toplanmaz, iptal toplama girmez)', !str_contains($e, "'toplam' =>") && !str_contains($e, "'topla' =>"));
ok('cari_ekstre.php: yazdırma otomatik print YOK (render_print_page_end(false))', str_contains($e, 'render_print_page_end(false)') && !str_contains($e, 'window.print'));
ok('cari_ekstre.php: fputcsv ; " \\\\ ile (PHP 8.4 escape kuralı)', preg_match_all("/fputcsv\\([^;]*';', '\"', '\\\\\\\\'\\)/", $e) >= 3, (string)preg_match_all("/fputcsv\\([^;]*';', '\"', '\\\\\\\\'\\)/", $e));

echo "\n=== 4. cari_kaydet.php: tek yazma ucu ===\n";
$k = kod($ROOT . '/cari_kaydet.php');
$p405 = $cari_pos($k, '405'); $pCsrf = $cari_pos($k, 'csrf_check(');
ok('GET/POST dışı → 405, require_login ve csrf_check\'ten ÖNCE', $p405 > 0 && $p405 < $cari_pos($k, 'require_login()') && $p405 < $pCsrf && str_contains($k, "'POST'"));
ok('csrf_check($_POST[\'csrf\']) var; JSON başlığı csrf_check\'ten ÖNCE (başarısızlık JSON döner)', str_contains($k, "csrf_check(\$_POST['csrf']") && $cari_pos($k, 'application/json') < $pCsrf);
$ilkYaz = PHP_INT_MAX;
foreach (['cari_kart_kaydet(', 'cari_kart_durum(', 'cari_kart_sil(', 'cari_hareket_ekle(', 'cari_virman_ekle(', 'cari_hareket_iptal(', 'cari_tanim_kaydet(', 'cari_tanim_durum(', 'cari_tanim_sil(', 'cari_ice_aktar('] as $fn) $ilkYaz = min($ilkYaz, $cari_pos($k, $fn));
ok('csrf_check(, ilk cari_ yazma çağrısından ÖNCE', $pCsrf > 0 && $pCsrf < $ilkYaz);
ok('csrf_check(, cari_sayfa_kapisi( ve cari_kapi( sırası: csrf → şema kapısı → yetki', $pCsrf < $cari_pos($k, 'cari_sayfa_kapisi(') && $cari_pos($k, 'cari_sayfa_kapisi(') < $cari_pos($k, 'cari_kapi('));
preg_match_all("/case '([a-z_]+)':(.*?)(?=case '|default:)/s", $k, $cm, PREG_SET_ORDER);
$isl = array_map(fn($m) => $m[1], $cm);
ok('10 işlem dalı: kart_kaydet kart_durum kart_sil hareket_ekle virman_ekle hareket_iptal tanim_kaydet tanim_durum tanim_sil ice_aktar',
    $isl === ['kart_kaydet', 'kart_durum', 'kart_sil', 'hareket_ekle', 'virman_ekle', 'hareket_iptal', 'tanim_kaydet', 'tanim_durum', 'tanim_sil', 'ice_aktar'], json_encode($isl));
$yetki = ['kart_kaydet' => 'write', 'kart_durum' => 'write', 'kart_sil' => 'delete', 'hareket_ekle' => 'write', 'virman_ekle' => 'write', 'hareket_iptal' => 'delete', 'tanim_kaydet' => 'admin', 'tanim_durum' => 'admin', 'tanim_sil' => 'admin', 'ice_aktar' => 'admin'];
foreach ($cm as $m) ok("dal $m[1]: cari_kapi('{$yetki[$m[1]]}') ilk ifade; dalda tek cari_kapi", (bool)preg_match("/^\\s*cari_kapi\\('" . $yetki[$m[1]] . "'\\);/", $m[2]) && substr_count($m[2], 'cari_kapi(') === 1, substr($m[2], 0, 80));
ok('`geri` yerel beyaz liste: cari_geri_guvenli($_POST[\'geri\']) + çekirdekte ^cari_[a-z]+\\.php regex', str_contains($k, "cari_geri_guvenli(\$_POST['geri']") && str_contains((string)file_get_contents($ROOT . '/config/cari_core.php'), '^cari_[a-z]+\\.php'));
ok('Location yalnız $geri ile (ham $_POST yönlendirmesi YOK)', !preg_match("/Location:\\s*'?\\s*\\.?\\s*\\\$_(POST|GET|REQUEST)/", (string)file_get_contents($ROOT . '/config/cari_core.php') . $k) && str_contains(kod($ROOT . '/config/cari_core.php'), "header('Location: ' . \$geri, true, 303)"));
ok('cari_kaydet.php: $_GET kullanmaz; hareket tablosuna doğrudan SQL yazmaz', !str_contains($k, '$_GET') && !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $k));

echo "\n=== 5. Ledger değişmezliği (yalnız çekirdek yazar) ===\n";
$ihlal = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $yol = $f->getPathname();
    if (!str_ends_with($yol, '.php') || str_contains($yol, '/vendor/') || str_contains($yol, '/scripts/') || str_contains($yol, '/storage/') || str_contains($yol, '/.git/') || str_contains($yol, '/.claude/') || str_contains($yol, '/node_modules/')) continue;
    if ($yol === $ROOT . '/config/cari_core.php') continue;
    if (preg_match('/\b(INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?cari_hareketler\b/i', kod($yol))) $ihlal[] = substr($yol, strlen($ROOT) + 1);
}
ok('cari_hareketler\'e INSERT/UPDATE/DELETE yazan başka dosya YOK (config/cari_core.php hariç)', !$ihlal, implode(',', $ihlal));
$c = kod($ROOT . '/config/cari_core.php');
ok('core: cari_hareketler\'de DELETE YOK (satır silinmez)', !preg_match('/DELETE\s+FROM\s+`?cari_hareketler/i', $c));
preg_match_all('/UPDATE\s+cari_hareketler\s+SET\s+([^"\']+?)\s+WHERE/i', $c, $um);
$setler = array_map(fn($x) => preg_replace('/\s+/', ' ', trim($x)), $um[1]);
ok('core: cari_hareketler UPDATE\'leri yalnız belge_no[/bagli_id] ve durum/iptal_* setleri', $setler && !array_filter($setler, fn($x) => !preg_match('/^(belge_no = \?(, bagli_id = \?)?|durum = \'iptal\', iptal_at = \?, iptal_by = \?, iptal_neden = \?)$/', $x)), json_encode($setler));
ok('core: tutar|cari_id|tarih|para_birimi|yon UPDATE\'i YOK', !preg_match('/UPDATE\s+cari_hareketler\s+SET[^;]*\b(tutar|cari_id|tarih|para_birimi|yon)\s*=/i', $c));
foreach (['cari_kart_kaydet', 'cari_kart_durum', 'cari_kart_sil', 'cari_hareket_ekle', 'cari_virman_ekle', 'cari_hareket_iptal', 'cari_kaynakli_iptal', 'cari_tanim_kaydet', 'cari_tanim_durum', 'cari_tanim_sil', 'cari_ice_aktar'] as $fn) {
    $g = govde($c, $fn);
    ok("core $fn: audit_log_event( var", $g !== null && str_contains($g, 'audit_log_event('));
}
$yetkiliCagri = [];
foreach (['cari_kart_kaydet', 'cari_kart_durum', 'cari_kart_sil', 'cari_hareket_ekle', 'cari_virman_ekle', 'cari_hareket_iptal', 'cari_kaynakli_iptal', 'cari_tanim_kaydet', 'cari_tanim_durum', 'cari_tanim_sil', 'cari_ice_aktar'] as $fn) {
    if (preg_match('/\b(can|is_admin|require_perm|cari_can|cari_kapi)\s*\(/', (string)govde($c, $fn))) $yetkiliCagri[] = $fn;
}
ok('core: yazan fonksiyonlarda yetki kontrolü YOK (uçların işi; testte saf çağrılabilir)', !$yetkiliCagri, implode(',', $yetkiliCagri));
ok('core: cari_hareket_ekle kaynak bağını YALNIZ $kaynak parametresinden alır ($g[\'kaynak_*\'] okunmaz); cari_kaydet.php 4. parametre geçmez',
    ($g = govde($c, 'cari_hareket_ekle')) !== null && str_contains($g, "\$kaynak['modul']") && !str_contains($g, '$g[\'kaynak') && !str_contains($g, '$g["kaynak') && !preg_match('/cari_hareket_ekle\(([^;,]+,){3}/', $k));
ok('core: cari_kaynakli_iptal UPDATE yalnız kaynak_modul + kaynak_id + durum=gecerli WHERE ile', (bool)preg_match("/SET durum = 'iptal', iptal_at = \\?, iptal_by = \\?, iptal_neden = \\? WHERE kaynak_modul = \\? AND kaynak_id = \\? AND durum = 'gecerli'/", (string)govde($c, 'cari_kaynakli_iptal')));
ok('core: depo filtre yardımcıları KULLANILMAZ (Hesap gibi bilinçli istisna: kart firma geneli)', !str_contains($c, 'depo_sql_') && !str_contains($c, 'depot_visible_to_user'));
ok('core: named parametre (:ad) kullanılmaz — pozisyonel ? (HY093 tekrar riski yok)', !preg_match('/[ (,=]:[a-z_]{2,}\b/', $c));
$ui = kod($ROOT . '/config/cari_ui.php');
ok('ui: sorgu YAZMAZ (INSERT/UPDATE/DELETE yok; yalnız cari_core okuyucuları)', !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $ui) && !preg_match('/->(exec|prepare)\(/', $ui));
ok('ui: depo filtre yardımcıları yok; setCellValue yok', !str_contains($ui, 'depo_sql_') && !str_contains($ui, 'setCellValue('));

echo "\n=== 6. Şema yalnız migrate.php'den ===\n";
$gecerli = [];
foreach (glob($ROOT . '/*.php') as $f) if (preg_match('/\bcari_migrate\s*\(/', kod($f))) $gecerli[] = basename($f);
ok('cari_migrate( tek çağıran migrate.php (kök sayfalar)', $gecerli === ['migrate.php'], json_encode($gecerli));
$db = (string)file_get_contents($ROOT . '/config/db.php');
ok('config/db.php\'de cari_ CREATE yok (otomatik migrasyon YOK, GO kuralı)', !preg_match('/CREATE\s+TABLE[^;]*cari_/i', $db) && !str_contains($db, 'cari_kartlar'));
ok('cari tablolarında FOREIGN KEY / ALTER yok (cari_tablolar DDL\'i)', !preg_match('/FOREIGN\s+KEY|ALTER\s+TABLE/i', (string)(govde($c, 'cari_tablolar') ?? '')));
ok('migrate.php: cari kartı + ne=cari dalı', str_contains((string)file_get_contents($ROOT . '/migrate.php'), "mg_kart_ac('cari')") && str_contains((string)file_get_contents($ROOT . '/migrate.php'), "'cari'"));

echo "\n=== 7. Sürüm eşitliği ===\n";
preg_match('/define\(\'APP_SURUM\',\s*\'v(\d+)\'/', (string)file_get_contents($ROOT . '/config/helpers.php'), $a);
preg_match('/CACHE_NAME\s*=\s*[\'"]yukleme-plani-v(\d+)/', $sw, $b);
ok('APP_SURUM == sw.js CACHE_NAME numarası', isset($a[1], $b[1]) && $a[1] === $b[1], json_encode([$a[1] ?? null, $b[1] ?? null]));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
