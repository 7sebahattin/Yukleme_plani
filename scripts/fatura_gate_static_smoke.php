<?php
// =========================================================
// scripts/fatura_gate_static_smoke.php — Fatura: kapı / csrf / audit / dosya adı / değişmezlik / bağımlılık yönü STATİK denetimi
//   php scripts/fatura_gate_static_smoke.php     → çıkış kodu 0 = hepsi geçti
// Kaynağa bakar (yorumlar token_get_all ile atılır; çalıştırma YOK). Davranış testleri: fatura_core_smoke.php (+ Dalga B: fatura_ui_smoke.php).
// Dalga A + B'yi birlikte tanır: 5 sayfa ya yer tutucu ("hazırlanıyor"; alinan_siparis_fisleri 302) ya da çalışan modül kabuğu olmalı.
// Dalga B bu dosyaya ekler: istemci varlıkları (assets/fatura.css|js) ve sürüm eşitliği (≥526) bölümleri.
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
$sirali = fn(array $ps): bool => !in_array(-1, $ps, true) && $ps === array_values(array_unique($ps)) && $ps == array_values((function ($x) { sort($x); return $x; })($ps));
$SQL_YAZ = '/\b(INSERT\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM)\b/i';
$sayfalar = ['alis_faturalari.php', 'satis_faturalari.php', 'sevk_irsaliyeleri.php', 'alinan_siparis_fisleri.php', 'fatura_tanimlari.php'];

echo "=== 1. Dosya kümesi ve adlar ===\n";
$kok = array_map('basename', glob($ROOT . '/fatura_*.php'));
sort($kok);
$A = ['fatura_kaydet.php', 'fatura_tanimlari.php'];
$B = ['fatura_cikti.php', 'fatura_kaydet.php', 'fatura_tanimlari.php'];
ok('kökte fatura_*.php: Dalga A {fatura_kaydet, fatura_tanimlari} ya da Dalga B {+ fatura_cikti} — başka yok', $kok === $A || $kok === $B, json_encode($kok));
ok('5 fatura sayfası var (URL\'ler sabit)', count(array_filter($sayfalar, fn($f) => is_file($ROOT . '/' . $f))) === 5);
ok('api_fatura*.php / _fatura*.php kökte YOK', !glob($ROOT . '/api_fatura*.php') && !glob($ROOT . '/_fatura*.php'));
ok('config/fatura_core.php var', is_file($ROOT . '/config/fatura_core.php'));
$sw = (string)file_get_contents($ROOT . '/sw.js');
ok('sw.js SW_MUHASEBE_RE bulundu', (bool)preg_match('#var SW_MUHASEBE_RE = /(.+)/;#', $sw, $rm));
$re = '#' . str_replace('\\/', '/', $rm[1] ?? 'x') . '#';
foreach ([...$sayfalar, 'fatura_kaydet.php', 'fatura_cikti.php', 'fatura_yeni_bir_sayfa.php'] as $f) ok("sw.js ağ-only kuralı /$f dosyasını kapsar (ticari veri önbelleğe girmez)", @preg_match($re, '/' . $f) === 1, $re);
ok('sw.js SHELL\'e fatura.css/js girmez', !preg_match('#SHELL[^;]*assets/fatura\.(css|js)#s', $sw));
ok('urun_siparis*.php fatura modülüne bağlanmadı (fatura_ sözcüğü yok)', !array_filter(glob($ROOT . '/urun_siparis*.php'), fn($f) => str_contains(kod($f), 'fatura_core')));

echo "\n=== 2. Sayfa kabuğu ===\n";
$calisan = []; $yerTutucu = [];
foreach ($sayfalar as $f) {
    $s = kod($ROOT . '/' . $f);
    if (str_contains($s, 'config/fatura_core.php')) { $calisan[] = $f; continue; }
    $yerTutucu[] = $f;
    if ($f === 'alinan_siparis_fisleri.php' && str_contains($s, "header('Location: urun_siparis.php', true, 302)")) {
        ok("$f: (Dalga A) v439 302 → urun_siparis.php + is_admin kapısı korunur", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s) && !str_contains($s, 'render_header('));
    } else {
        ok("$f: (Dalga A) yer tutucu: is_admin kapısı + 'hazırlanıyor', \$_POST yok", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s) && str_contains($s, 'hazırlanıyor') && !str_contains($s, '$_POST'));
    }
}
echo '   (' . count($calisan) . ' çalışan sayfa, ' . count($yerTutucu) . " yer tutucu — Dalga B hepsini çalışan modüle çevirir)\n";
foreach ($calisan as $f) {
    $s = kod($ROOT . '/' . $f);
    ok("$f: <?php + strict_types", str_starts_with($s, '<?php') && str_contains($s, 'declare(strict_types=1)'));
    $ps = array_map(fn($a) => $pos($s, $a), ['require_login()', 'if (!is_admin()) { forbidden(', "fatura_kapi('read')", 'fatura_no_store()', 'fatura_sayfa_kapisi($pdo)', 'render_header(']);
    ok("$f: kabuk sırası require_login → is_admin → fatura_kapi('read') → fatura_no_store → fatura_sayfa_kapisi → render_header", $sirali($ps), json_encode($ps));
    ok("$f: config/fatura_core.php + fatura_ui.php yüklenir; config/cari_core.php / config/stok_core.php metni GEÇMEZ (Y3)", str_contains($s, 'config/fatura_ui.php') && !str_contains($s, 'config/cari_core.php') && !str_contains($s, 'config/stok_core.php'));
    ok("$f: fatura_assets() + fatura_scripts() + render_footer()", str_contains($s, 'fatura_assets()') && str_contains($s, 'fatura_scripts()') && str_contains($s, 'render_footer()'));
    ok("$f: yalnız GET — \$_POST YOK, csrf_check YOK, Location YOK", !str_contains($s, '$_POST') && !str_contains($s, 'csrf_check(') && !preg_match("/header\\s*\\(\\s*['\"]Location/i", $s));
    ok("$f: SQL yazma YOK, ->prepare/->query YOK", !preg_match($SQL_YAZ, $s) && !preg_match('/->(prepare|query|exec)\(/', $s));
    ok("$f: 'hazırlanıyor' yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
}

echo "\n=== 3. fatura_kaydet.php: tek yazma ucu ===\n";
$k = kod($ROOT . '/fatura_kaydet.php');
$p405 = $pos($k, '405'); $pCsrf = $pos($k, 'csrf_check(');
ok('POST dışı → 405 + Allow, require_login ve csrf_check\'ten ÖNCE', $p405 > 0 && $p405 < $pos($k, 'require_login()') && $p405 < $pCsrf && str_contains($k, "'POST'") && str_contains($k, "header('Allow: POST')"));
ok('sıra: require_login → fatura_no_store → JSON başlığı → csrf_check($_POST[\'csrf\'])', $sirali([$pos($k, 'require_login()'), $pos($k, 'fatura_no_store()'), $pos($k, 'application/json'), $pCsrf]) && str_contains($k, "csrf_check(\$_POST['csrf']"));
ok('sıra: csrf_check → $pdo = db() → fatura_sayfa_kapisi → ilk fatura_kapi', $sirali([$pCsrf, $pos($k, '$pdo = db()'), $pos($k, 'fatura_sayfa_kapisi($pdo)'), $pos($k, 'fatura_kapi(')]));
ok('yalnız config/fatura_core.php yüklenir (cari_core/stok_core doğrudan değil)', str_contains($k, "config/fatura_core.php") && !str_contains($k, 'config/cari_core.php') && !str_contains($k, 'config/stok_core.php'));
$ilkYaz = PHP_INT_MAX;
foreach (['fatura_kaydet(', 'fatura_iptal(', 'fatura_siparis_kapat(', 'fatura_tanim_kaydet(', 'fatura_tanim_durum(', 'fatura_tanim_sil('] as $fn) { $p = $pos($k, $fn); if ($p >= 0) $ilkYaz = min($ilkYaz, $p); }
ok('csrf_check, ilk fatura_ yazma çağrısından ÖNCE', $pCsrf > 0 && $pCsrf < $ilkYaz);
preg_match_all("/case '([a-z_]+)':(.*?)(?=case '|default:)/s", $k, $cm, PREG_SET_ORDER);
$isl = array_map(fn($m) => $m[1], $cm);
ok('işlem dalları (6, bu sırayla): belge_kaydet · belge_iptal · siparis_kapat · tanim_kaydet · tanim_durum · tanim_sil', $isl === ['belge_kaydet', 'belge_iptal', 'siparis_kapat', 'tanim_kaydet', 'tanim_durum', 'tanim_sil'], json_encode($isl));
$yetki = ['belge_kaydet' => 'write', 'belge_iptal' => 'delete', 'siparis_kapat' => 'write', 'tanim_kaydet' => 'admin', 'tanim_durum' => 'admin', 'tanim_sil' => 'admin'];
$cagri = ['belge_kaydet' => 'fatura_kaydet(', 'belge_iptal' => 'fatura_iptal(', 'siparis_kapat' => 'fatura_siparis_kapat(', 'tanim_kaydet' => 'fatura_tanim_kaydet(', 'tanim_durum' => 'fatura_tanim_durum(', 'tanim_sil' => 'fatura_tanim_sil('];
foreach ($cm as $m) {
    $y = $yetki[$m[1]] ?? '?';
    ok("dal $m[1]: fatura_kapi('$y') İLK ifade; tek fatura_kapi; yalnız fatura_ fonksiyonu (cari_/stok_ çağrısı YOK); fatura_cevap", (bool)preg_match("/^\\s*fatura_kapi\\('" . $y . "'\\);/", $m[2]) && substr_count($m[2], 'fatura_kapi(') === 1
        && !preg_match('/\b(cari|stok)_[a-z_]+\(/', $m[2]) && str_contains($m[2], $cagri[$m[1]] ?? '#') && str_contains($m[2], 'fatura_cevap($s, $geri,'), substr($m[2], 0, 80));
}
ok('`geri` yerel beyaz liste: fatura_geri_guvenli($_POST[\'geri\']) + çekirdekte 5 sayfalık regex', str_contains($k, "fatura_geri_guvenli(\$_POST['geri']")
    && str_contains((string)file_get_contents($ROOT . '/config/fatura_core.php'), '^(alis_faturalari|satis_faturalari|sevk_irsaliyeleri|alinan_siparis_fisleri|fatura_tanimlari)\.php(\?[^\r\n]*)?$'));
ok('fatura_kaydet.php: $_GET kullanmaz; doğrudan SQL yazmaz; ->prepare yok', !str_contains($k, '$_GET') && !preg_match($SQL_YAZ, $k) && !preg_match('/->(prepare|query|exec)\(/', $k));
ok('PDOException → 500 genel mesaj (ayrıntı yalnız error_log)', str_contains($k, 'catch (PDOException $e)') && str_contains($k, 'http_response_code(500)') && str_contains($k, 'error_log(') && str_contains($k, "'kod' => 'sunucu_hatasi'"));

echo "\n=== 4. Değişmezlik (yalnız çekirdek yazar) ===\n";
$ihlal = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $yol = $f->getPathname();
    if (!str_ends_with($yol, '.php') || preg_match('#/(vendor|scripts|storage|\.git|\.claude|node_modules)/#', $yol)) continue;
    if ($yol === $ROOT . '/config/fatura_core.php') continue;
    if (preg_match('/\b(INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?fatura_(belgeler|satirlar|tanimlar)\b/i', kod($yol))) $ihlal[] = substr($yol, strlen($ROOT) + 1);
}
ok('fatura_* tablolarına INSERT/UPDATE/DELETE yazan başka dosya YOK (config/fatura_core.php hariç)', !$ihlal, implode(',', $ihlal));
$c = kod($ROOT . '/config/fatura_core.php');
ok('core: fatura_satirlar\'da UPDATE / DELETE YOK (INSERT-only)', !preg_match('/(UPDATE\s+`?fatura_satirlar|DELETE\s+FROM\s+`?fatura_satirlar)/i', $c));
ok('core: fatura_belgeler\'de DELETE YOK', !preg_match('/DELETE\s+FROM\s+`?fatura_belgeler/i', $c));
preg_match_all('/UPDATE\s+`?fatura_belgeler`?\s+SET\s+(.+?)\s+WHERE/i', $c, $um);
$setler = array_map(fn($x) => preg_replace('/\s+/', ' ', trim($x)), $um[1]);
$beyaz = ['belge_no = ?, cari_hareket_id = ?, stok_fis_id = ?', "durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?", 'kapali = ?, kapali_at = ?, kapali_by = ?, kapali_neden = ?'];
ok('core: fatura_belgeler UPDATE\'leri YALNIZ beyaz liste (3 kalıp, tam metin) ve üçü de var', count($setler) === 3 && !array_diff($setler, $beyaz) && !array_diff($beyaz, $setler), json_encode($setler, JSON_UNESCAPED_UNICODE));
ok('core: INSERT INTO fatura_belgeler / fatura_satirlar birer kez (fatura_kaydet içinde)', substr_count($c, 'INSERT INTO fatura_belgeler') === 1 && substr_count($c, 'INSERT INTO fatura_satirlar') === 1
    && str_contains((string)govde($c, 'fatura_kaydet'), 'INSERT INTO fatura_belgeler') && str_contains((string)govde($c, 'fatura_kaydet'), 'INSERT INTO fatura_satirlar'));
ok('core: cari_* / stok_* TABLOLARINA hiç SQL yok (okuma dahil; yalnız onların fonksiyonları)', !preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+`?(cari_(hareketler|kartlar|tanimlar)|stok_(hareketler|fisler|fis_satirlar|kartlar|tanimlar))\b/i', $c));
ok('core: named parametre (:ad) kullanılmaz — pozisyonel ?', !preg_match('/[ (,=]:[a-z_]{2,}\b/', $c));
ok('core: depo filtresi yalnız stok_depo_filtre / depot_visible_to_user (depo_sql_column / depo_sql_records YOK)', str_contains($c, 'stok_depo_filtre(') && !str_contains($c, 'depo_sql_column(') && !str_contains($c, 'depo_sql_records'));
$yazanlar = ['fatura_kaydet', 'fatura_iptal', 'fatura_siparis_kapat', 'fatura_tanim_kaydet', 'fatura_tanim_durum', 'fatura_tanim_sil'];
foreach ($yazanlar as $fn) ok("core $fn: audit_log_event(…, 'fatura', …) var", ($g = govde($c, $fn)) !== null && (bool)preg_match("/audit_log_event\\('[a-z]+', 'fatura'/", $g) || ($g !== null && str_contains($g, "audit_log_event(\$id === null ? 'create' : 'update', 'fatura'")));
$yetkili = [];
foreach ([...$yazanlar, 'fatura_dogrula', 'fatura_tanim_dogrula'] as $fn) if (preg_match('/\b(can|is_admin|require_perm|fatura_can|fatura_kapi|stok_can|stok_kapi|cari_can|cari_kapi)\s*\(/', (string)govde($c, $fn))) $yetkili[] = $fn;
ok('core: yazan / doğrulayan fonksiyonlarda yetki kontrolü YOK (uçların işi)', !$yetkili, implode(',', $yetkili));
ok('core: fatura_can yalnız is_admin() (tek değişim noktası; izin kataloğuna bağlanmadı)', ($g = govde($c, 'fatura_can')) !== null && str_contains($g, 'is_admin()') && !preg_match('/\bcan\(/', $g));
foreach (['fatura_satir_hesapla', 'fatura_toplam_hesapla', 'fatura_stok_fiyat4', 'fatura_bp_coz'] as $fn) ok("core $fn SAF (PDO/sorgu/audit/\$GLOBALS YOK)", ($g = govde($c, $fn)) !== null && !preg_match('/PDO|->|audit_log_event|\$GLOBALS/', $g));
ok('core: istemci fatura_no ALMAZ — seri no yalnız fatura_seri_kilitle sonrası fatura_seri_no ile', str_contains((string)govde($c, 'fatura_kaydet'), '$faturaNo = fatura_seri_no(') && $pos((string)govde($c, 'fatura_kaydet'), 'fatura_seri_kilitle(') < $pos((string)govde($c, 'fatura_kaydet'), '$faturaNo = fatura_seri_no('));

echo "\n=== 5. Zorunlu metinler (stok + cari bağı) ===\n";
$fk = (string)govde($c, 'fatura_kaydet');
ok('fatura_kaydet: stok_fis_kaydet( 4 argümanlı, kaynak [\'modul\' => \'fatura\', \'id\' => $fid]; tek çağrı', str_contains($fk, "stok_fis_kaydet(\$pdo, \$sg, \$uid, ['modul' => 'fatura', 'id' => \$fid])") && substr_count($c, 'stok_fis_kaydet(') === 1);
ok("fatura_kaydet: stok fişi cari'siz ('cari_id' => '') — dekontu yalnız fatura yazar (tuzak #1)", str_contains($fk, "'cari_id' => ''"));
ok('fatura_kaydet: cari_hareket_ekle( + [\'modul\' => \'fatura\', \'id\' => $fid]; tek çağrı', str_contains($fk, 'cari_hareket_ekle(') && str_contains($fk, "\$uid, ['modul' => 'fatura', 'id' => \$fid]);") && substr_count($c, 'cari_hareket_ekle(') === 1);
ok('fatura_kaydet: her iç !ok → RuntimeException(FATURA_IC_HATA) (stok + cari), catch kodu ayırır', substr_count($fk, 'FATURA_IC_HATA)') >= 2 && str_contains($fk, "if (empty(\$sr['ok']))") && str_contains($fk, "if (empty(\$cr['ok']))") && str_contains($fk, '$e->getCode() !== FATURA_IC_HATA'));
ok('fatura_kaydet: kilit sırası kartlar → kaynak belgeler → seri → INSERT', $sirali([$pos($fk, 'stok_kart_kilitle('), $pos($fk, 'fatura_belge_kilitle('), $pos($fk, 'fatura_seri_kilitle('), $pos($fk, 'fatura_kaynak_kontrol('), $pos($fk, 'INSERT INTO fatura_belgeler')]));
ok('fatura_kaydet: audit commit SONRASI (fatura_tx bloğundan sonra)', $pos($fk, "audit_log_event('create', 'fatura'") > $pos($fk, '} catch (PDOException $e)'));
$fi = (string)govde($c, 'fatura_iptal');
ok("fatura_iptal: stok_fis_iptal(…, 'fatura') + cari_kaynakli_iptal(\$pdo, 'fatura', \$id; tx içinde !ok → FATURA_IC_HATA", str_contains($fi, 'stok_fis_iptal(') && str_contains($fi, ", 'fatura')") && str_contains($fi, "cari_kaynakli_iptal(\$pdo, 'fatura', \$id")
    && substr_count($fi, 'FATURA_IC_HATA)') >= 2 && substr_count($c, 'stok_fis_iptal(') === 1 && substr_count($c, 'cari_kaynakli_iptal(') === 1);
ok('fatura_iptal: aşağı akış (bagli_belge) kontrolü kilit altında TEKRAR', substr_count($fi, 'fatura_bagli_belgeler($pdo, $id)') === 2 && $pos($fi, 'fatura_belge_kilitle(') < strrpos($fi, 'fatura_bagli_belgeler($pdo, $id)'));
ok('fatura_cevap: 409 kodları (yetersiz_stok, mukerrer, kalan_asimi, bagli_belge, kullanimda, kaynakli)', str_contains((string)govde($c, 'fatura_cevap'), "['yetersiz_stok', 'mukerrer', 'kalan_asimi', 'bagli_belge', 'kullanimda', 'kaynakli']"));
ok('fatura_core: stok_core require_once (cari_core onun içinden); stok/cari kendi fonksiyonlarıyla', str_contains($c, "require_once __DIR__ . '/stok_core.php';") && !str_contains($c, "require_once __DIR__ . '/cari_core.php'"));

echo "\n=== 6. Bağımlılık yönü ===\n";
foreach (['config/cari_core.php', 'config/stok_core.php', 'config/stok_ui.php', 'config/cari_ui.php', 'stok_kaydet.php', 'cari_kaydet.php'] as $f) {
    ok("$f 'fatura' sözcüğü içermez (cari/stok fatura'yı bilmez)", is_file($ROOT . '/' . $f) && stripos((string)file_get_contents($ROOT . '/' . $f), 'fatura') === false);
}
$sc = kod($ROOT . '/config/stok_core.php');
ok("stok_core: çekirdek kaynak bağı genel (\$kaynak; 'kantar' hariç), POST yolu yalnız kantar", str_contains($sc, 'function stok_fis_kaydet(PDO $pdo, array $g, int $uid, ?array $kaynak = null): array')
    && str_contains($sc, 'function stok_fis_iptal(PDO $pdo, int $id, string $neden, int $uid, ?string $kaynakModul = null): array') && str_contains((string)govde($sc, 'stok_fis_dogrula'), "if (\$km !== 'kantar' || \$tur !== 'giris')"));

echo "\n=== 7. Şema yalnız migrate.php'den ===\n";
$gecerli = [];
foreach (glob($ROOT . '/*.php') as $f) if (preg_match('/\bfatura_migrate\s*\(/', kod($f))) $gecerli[] = basename($f);
ok('fatura_migrate( tek çağıran migrate.php (kök sayfalar)', $gecerli === ['migrate.php'], json_encode($gecerli));
$db = (string)file_get_contents($ROOT . '/config/db.php');
ok('config/db.php\'de fatura_ CREATE yok (otomatik migrasyon YOK)', !preg_match('/CREATE\s+TABLE[^;]*fatura_(belgeler|satirlar|tanimlar)/i', $db) && !str_contains($db, 'fatura_belgeler'));
ok('fatura tablolarında FOREIGN KEY / ALTER / ENUM / JSON / ON UPDATE / GENERATED yok', ($g = govde($c, 'fatura_tablolar')) !== null && !preg_match('/FOREIGN\s+KEY|ALTER\s+TABLE|ENUM\(|\bJSON\b|ON\s+UPDATE|GENERATED/i', $g));
$mg = (string)file_get_contents($ROOT . '/migrate.php');
ok('migrate.php: require fatura_core + mg_kart_ac(\'fatura\') + ne=fatura dalı (csrf + fatura_migrate + audit migrate)', str_contains($mg, "config/fatura_core.php") && str_contains($mg, "mg_kart_ac('fatura')")
    && (bool)preg_match("/=== 'fatura'\)\s*\{\s*\n\s*csrf_check[^}]*fatura_migrate\(\\\$pdo\)[^}]*audit_log_event\('migrate', 'fatura'/s", $mg));
ok('migrate.php: fatura kartı stok kartından SONRA; ✓/✗ durum satırı + form ne=fatura', $pos($mg, "mg_kart_ac('stok')") > 0 && $pos($mg, "mg_kart_ac('fatura')") > $pos($mg, "mg_kart_ac('stok')")
    && str_contains($mg, "\$faturaHazir ? '✓ Tablolar var' : '✗ Tablolar eksik'") && str_contains($mg, 'name="ne" value="fatura"'));

echo "\n(Dalga B: istemci varlıkları + sürüm eşitliği bölümleri henüz yok — assets/fatura.* ve v526 Dalga B'de.)\n";
echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
