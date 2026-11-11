<?php
// =========================================================
// scripts/frugro_gate_static_smoke.php — Frugro Siparişler STATİK değişmezleri (kaynağa bakar; sayfa çalıştırmaz; DB yok)
//   php scripts/frugro_gate_static_smoke.php
// Kök frugro*.php: oturum + require_siparis kapısı, POST uçlarında csrf_check, tek alt çizgili ad (sw bypass regex'i kapsar),
// can('siparis.*') kopyası YOK, sw.js bypass + SHELL, nav (sidebar/topnav/alt çubuk/index), sürüm eşitliği.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function oku(string $f): string { global $ROOT; return is_file($ROOT . '/' . $f) ? (string)file_get_contents($ROOT . '/' . $f) : ''; }
function kod_oku(string $f): string {
    $out = '';
    foreach (token_get_all(oku($f)) as $t) {
        if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $out .= $t[1]; } else $out .= $t;
    }
    return $out;
}
$sw = oku('sw.js'); $helpers = oku('config/helpers.php'); $index = oku('index.php');

echo "=== Sayfa / uç kapıları ===\n";
$sayfalar = array_map('basename', glob($ROOT . '/frugro*.php') ?: []);
sort($sayfalar);
foreach (['frugro_siparis.php', 'frugro_view.php', 'frugro_yukle.php', 'frugro_kaydet.php'] as $b) ok("$b mevcut", in_array($b, $sayfalar, true));
$MUT = ['frugro_yukle.php', 'frugro_kaydet.php'];
foreach ($sayfalar as $f) {
    $k = kod_oku($f);
    ok("$f: ad TEK alt çizgili (sw bypass regex'i kapsar)", (bool)preg_match('/^frugro(_[a-z]+)?\.php$/', $f));
    ok("$f: require_login()", str_contains($k, 'require_login()'));
    ok("$f: require_siparis( kapısı", str_contains($k, 'require_siparis(') || str_contains($k, 'frgui_yetki('));
    ok("$f: can('siparis.*') kopyası YOK", preg_match("/\bcan\('siparis\./", $k) === 0);
}
foreach ($MUT as $f) {
    $k = kod_oku($f);
    $c = strpos($k, 'csrf_check(');
    ok("$f: csrf_check var", $c !== false);
    ok("$f: csrf_check yazmadan (INSERT/UPDATE/frg_toplu_kaydet) ÖNCE", $c !== false && ($c < (strpos($k, 'UPDATE ') ?: PHP_INT_MAX)) && ($c < (strpos($k, 'frg_toplu_kaydet(') ?: PHP_INT_MAX)));
    ok("$f: yazma kapısı frgui_yetki(", str_contains($k, 'frgui_yetki('));
    ok("$f: POST dışı yöntem reddi", str_contains($k, "'POST'"));
    ok("$f: Cache-Control no-store (siparis_no_store)", str_contains($k, 'siparis_no_store()'));
}
ok('frugro_kaydet: audit_log_event + frugro_olaylar (frg_olay_yaz)', str_contains(kod_oku('frugro_kaydet.php'), 'audit_log_event(') && str_contains(kod_oku('frugro_kaydet.php'), 'frg_olay_yaz('));
ok('frugro_kaydet: alan beyaz listesi (frgui_duzenle_dogrula)', str_contains(kod_oku('frugro_kaydet.php'), 'frgui_duzenle_dogrula('));
ok('frugro_kaydet: sil yalnız delete kapısı + onay', (bool)preg_match("/islem === 'sil'\)\s*\{\s*if \(!frgui_yetki\('delete'\)\)/", kod_oku('frugro_kaydet.php')) && str_contains(kod_oku('frugro_kaydet.php'), "'onay'"));
ok('frugro_yukle: dosya diske KAYDEDİLMİYOR (move_uploaded_file/file_put_contents yok)', !str_contains(kod_oku('frugro_yukle.php'), 'move_uploaded_file') && !str_contains(kod_oku('frugro_yukle.php'), 'file_put_contents'));
ok('yazma uçları depo kapısı (frg_gorunur) kullanıyor', str_contains(kod_oku('frugro_kaydet.php'), 'frg_gorunur(') && str_contains(kod_oku('frugro_view.php'), 'frg_gorunur('));
ok('liste/detay/uç migrate çağırmıyor (şema yalnız migrate.php)', !str_contains(kod_oku('frugro_siparis.php') . kod_oku('frugro_view.php') . kod_oku('frugro_yukle.php') . kod_oku('frugro_kaydet.php'), 'frg_migrate('));
ok("yeni yetki YOK: frugro_ui yalnız can_siparis + role_matrix_page_can", str_contains(oku('config/frugro_ui.php'), 'can_siparis(') && preg_match("/\bcan\('/", kod_oku('config/frugro_ui.php')) === 0);
$ui = oku('frugro_siparis.php') . oku('frugro_view.php') . oku('_frugro_liste.php');
ok('arayüzde satır içi onclick/onsubmit YOK', preg_match('/\son(click|submit|change)=/i', $ui) === 0);

echo "\n=== Durum akışı değişmezleri (v515) ===\n";
$tumKok = array_merge($sayfalar, array_map(static fn($f) => 'config/' . basename($f), glob($ROOT . '/config/frugro*.php') ?: []));
$sabit = []; $durumYaz = [];
foreach ($tumKok as $f) {
    $k = kod_oku($f);
    if (preg_match("/'kapali'\s*,\s*'iptal'|'iptal'\s*,\s*'kapali'|'evrak_gonderildi'\s*,\s*'iptal'/", $k)) $sabit[] = $f;
    if (preg_match('/UPDATE\s+frugro_siparisler\s+SET\s+durum\b/i', $k)) $durumYaz[] = $f;
}
ok("kapalı listesi SABİT yazılmaz: yalnız frg_kapali_durumlar() (config/frugro_core.php)", $sabit === ['config/frugro_core.php'], implode(',', $sabit));
ok('"UPDATE frugro_siparisler SET durum" yalnız frg_durum_degistir içinde (config/frugro_core.php, tek yer)', $durumYaz === ['config/frugro_core.php'] && substr_count(kod_oku('config/frugro_core.php'), 'SET durum') === 1, implode(',', $durumYaz));
ok('durum yazımı frg_durum_degistir içinde: koşullu (AND durum = ?) + olay + audit', (bool)preg_match('/function frg_durum_degistir\(.*?SET durum = \?, updated_at = \?[^;]*AND durum = \?.*?frg_durum_olay_yaz\(.*?audit_log_event\(\'frugro_durum\'/s', kod_oku('config/frugro_core.php')));
ok('frugro_kaydet durum dalı frg_durum_degistir çağırır (kendi UPDATE\'i yok)', str_contains(kod_oku('frugro_kaydet.php'), 'frg_durum_degistir(') && !preg_match('/UPDATE\s+frugro_siparisler\s+SET\s+durum/i', kod_oku('frugro_kaydet.php')));
ok('PKL/Invoice üretimi terfiyi frg_durum_degistir(…, \'otomatik\') ile yapar', str_contains(kod_oku('config/frugro_pkl.php'), "'otomatik'") && str_contains(kod_oku('config/frugro_invoice.php'), "'otomatik'"));
ok('frgi_yazilabilir kapalı listesini fonksiyondan okur', str_contains(kod_oku('config/frugro_invoice.php'), 'frg_kapali_durumlar()'));
$migCagri = [];
foreach (array_merge(glob($ROOT . '/*.php') ?: [], glob($ROOT . '/config/*.php') ?: []) as $f) { if (basename($f) !== 'frugro_core.php' && preg_match('/\bfrg_migrate\s*\(/', (string)file_get_contents($f))) $migCagri[] = basename($f); }
ok('frg_migrate() çağrısı yalnız migrate.php\'de', $migCagri === ['migrate.php'], implode(',', $migCagri));
ok('ortak JS kancaları geriye uyumlu: siparis.js ns varsayılanı "sip", siparis_wp.js varsayılan siparis_paylas.php, yazdır birim varsayılanı', str_contains(oku('assets/siparis.js'), "(ns || 'sip')") && str_contains(oku('assets/siparis_wp.js'), "'siparis_paylas.php?id='") && str_contains(oku('assets/siparis_yazdir.js'), "birim.adet || 'sevkiyat'"));

echo "\n=== sw.js ===\n";
preg_match_all('#if \((/\\\\/frugro[^\n]*?)\.test\(u\.pathname\)\) return;#', $sw, $m);
ok('sw.js: frugro bypass satırı var', count($m[1]) === 1);
$re = $m[1][0] ?? '';
$js = $re === '' ? false : @preg_match('#' . str_replace('#', '\#', substr($re, 1, strrpos($re, '/') - 1)) . '#', '/x');
$desen = '#' . substr($re, 1, strrpos($re, '/') - 1) . '#';
$desen = str_replace('\\/', '/', $desen);
foreach (['frugro_siparis.php', 'frugro_view.php', 'frugro_yukle.php', 'frugro_kaydet.php', 'frugro_pkl.php', 'frugro_invoice.php', 'frugro_ayarlar.php', 'frugro_mail.php'] as $p) {
    ok("sw bypass regex'i /$p yolunu kapsar", @preg_match($desen, '/' . $p) === 1 && @preg_match($desen, '/app/' . $p . '/x') === 1, $desen);
}
ok('sw.js: assets/frugro_* SHELL\'de DEĞİL', !preg_match("#'\./assets/frugro[^']*\.(js|css)'#", $sw));
ok('sw.js: frugro.svg ikonu SHELL\'de', str_contains($sw, './assets/nav-icons/frugro.svg'));
ok('sw.js bypass fetch dinleyicisinde statik varlık kontrolünden ÖNCE', strpos($sw, '/\/frugro(_[a-z]+)?\.php') !== false);

echo "\n=== Gezinme ===\n";
ok('nav_aktif_anahtar: frugro bayrağı + 6 sayfa', (bool)preg_match("/\\\$a_frg\s*=\s*in_array\(\\\$cur, \[[^\]]*'frugro_siparis\.php'[^\]]*'frugro_view\.php'[^\]]*'frugro_pkl\.php'[^\]]*'frugro_invoice\.php'[^\]]*'frugro_ayarlar\.php'[^\]]*'frugro_mail\.php'/", $helpers) && str_contains($helpers, "'frugro' => \$a_frg"));
ok('sidebar: Frugro OGL\'nin HEMEN altında (usip\'ten önce)', (function () use ($helpers) { $a = strpos($helpers, "\$lnk('siparisler.php'"); $b = strpos($helpers, "\$lnk('frugro_siparis.php'"); $c = strpos($helpers, "\$lnk('urun_siparis.php'"); return $a && $b && $c && $a < $b && $b < $c; })());
ok('sidebar kapısı OGL ile aynı ($p_frg = $p_sip)', str_contains($helpers, '$p_frg   = $p_sip;'));
ok('topnav Frugro bağlantısı', str_contains($helpers, 'frugro_siparis.php" <?= nav_aktif_anahtar() === \'frugro\''));
ok('nav_alt_sayfalar frugro kaydı', (bool)preg_match("/'frugro'\s*=> \['href' => 'frugro_siparis\.php',[^\n]*'grup' => 'Operasyon', 'ikon' => 'assets\/nav-icons\/frugro\.svg'\]/", $helpers) && is_file($ROOT . '/assets/nav-icons/frugro.svg'));
ok('nav_alt_izinler frugro = OGL kapısı', str_contains($helpers, "'frugro'  => \$fn && role_matrix_page_can('siparis','ogl')"));
ok('index.php Frugro kartı OGL kapılı', (function () use ($index) { $g = strpos($index, "<?php if (role_matrix_page_can('siparis','ogl')): ?>\n    <a href=\"frugro_siparis.php\""); return $g !== false; })());
ok("siparis.* yetkisi helpers.php'de yalnız can_siparis içinde (4 çağrı; yeni kopya yok)", substr_count($helpers, "can('siparis.") === 4);

echo "\n=== Sürüm ===\n";
preg_match("/define\('APP_SURUM', 'v(\d+)'\)/", $helpers, $a); preg_match("/CACHE_NAME = 'yukleme-plani-v(\d+)'/", $sw, $b); preg_match('/\*\*SW Cache:\*\* `yukleme-plani-v(\d+)`/', oku('CLAUDE.md'), $c);
ok('APP_SURUM = CACHE_NAME = CLAUDE.md', ($a[1] ?? 'a') === ($b[1] ?? 'b') && ($b[1] ?? 'b') === ($c[1] ?? 'c'), json_encode([$a[1] ?? null, $b[1] ?? null, $c[1] ?? null]));

echo "\n$pass geçti, $fail başarısız\n";
exit($fail > 0 ? 1 : 0);
