<?php
// CLI only. Hızlı Hesapla: PHP motoru + kayıt + yetki/CSRF/depo + sayfa denetleyicisi. Anonim bellek içi SQLite.
//   php scripts/hizli_hesapla_smoke.php            → testler
//   php scripts/hizli_hesapla_smoke.php --json     → stdin'den girdi listesi, hh_calculate sonuçları (JS paritesi)
//   php scripts/hizli_hesapla_smoke.php --render   → tarayıcı testi için statik HTML
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/quick_cost_store.php';
require_once $root . '/config/quick_cost_schema.php';

if (($argv[1] ?? '') === '--json') {
    $cases = json_decode((string)stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $out = [];
    foreach ($cases as $case) {
        try { $out[] = ['ok' => hh_calculate($case)]; } catch (InvalidArgumentException $e) { $out[] = ['error' => $e->getMessage()]; }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    exit;
}

$testPdo = new PDO('sqlite::memory:');
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$testPdo->exec('CREATE TABLE quick_cost_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT, product TEXT, sheet_date TEXT, operation_type TEXT, method TEXT, net_kg NUMERIC, document_json TEXT, total NUMERIC, unit_cost NUMERIC, notes TEXT, depo TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, version INTEGER)');
$testPermissions = ['read', 'write', 'delete'];
$testDepot = 'DEMO';
$testStatus = 200; $testHeaders = []; $testAudit = []; $testFlash = [];
function db(): PDO { return $GLOBALS['testPdo']; }
function current_user(): array { return ['id' => 7]; }
function active_depot(): string { return $GLOBALS['testDepot']; }
function can_hizli_maliyet(string $p): bool { $x = $GLOBALS['testPermissions']; return in_array('read', $x, true) && in_array($p, $x, true); }
function can_maliyet(string $p): bool { throw new LogicException('Hızlı Hesapla can_maliyet() kullanmamalı.'); }
function is_admin(): bool { return false; }
function require_login(): array { return current_user(); }
class HHExit extends Exception {}
function forbidden(string $m): void { test_status(403); throw new HHExit($m); }
function depo_sql_in(string $c): array { return ["$c IN (?)", [active_depot()]]; }
function depot_visible_to_user(?string $d): bool { return $d === active_depot(); }
function csrf_check(?string $t): void { if ($t !== 'anonymous-csrf') forbidden('csrf'); }
function csrf_token(): string { return 'anonymous-csrf'; }
function set_flash(string $k, string $m): void { $GLOBALS['testFlash'][] = [$k, $m]; }
function render_flash(): void {}
function audit_log_event(...$a): void { $GLOBALS['testAudit'][] = $a; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function test_header(string $v): void { $GLOBALS['testHeaders'][] = $v; }
function test_status(int $s): void { $GLOBALS['testStatus'] = $s; }
function render_header(string $t): void { echo '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>' . h($t) . '</title></head><body><main class="container">'; }
function render_footer(): void { echo '</main></body></html>'; }

function page_src(string $file): string {
    global $root;
    $s = file_get_contents($root . '/' . $file);
    $s = preg_replace("/^require_once __DIR__ \\. '\/config\/[^']+\.php';\r?\n/m", '', $s);
    $s = preg_replace('/^declare\(strict_types=1\);\r?\n/m', '', $s);
    $s = str_replace('__DIR__', var_export($root, true), $s);
    $s = preg_replace('/(?<![\w>:])header\(/', 'test_header(', $s);
    $s = str_replace('http_response_code(', 'test_status(', $s);
    $s = str_replace('exit;', 'throw new HHExit();', $s);
    return preg_replace('/\bexit\(([^;]+)\);/', 'throw new HHExit($1);', $s);
}
$src = ['hh' => page_src('hizli_hesapla.php'), 'form' => page_src('hizli_maliyet_form.php'), 'list' => page_src('hizli_maliyet.php')];
$hs = strpos($src['form'], '/** Recover ordinary'); $he = strpos($src['form'], '$id_raw =');
if ($hs === false || $he === false) throw new RuntimeException('Form helper extraction failed.');
eval(substr($src['form'], $hs, $he - $hs));
$src['form'] = substr_replace($src['form'], '', $hs, $he - $hs);
function page(string $route, array $get = [], array $post = []): array {
    $_GET = $get; $_POST = $post; $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $GLOBALS['testStatus'] = 200; $GLOBALS['testHeaders'] = []; $GLOBALS['testFlash'] = [];
    ob_start();
    try { eval('?>' . $GLOBALS['src'][$route]); } catch (HHExit $e) {}
    return ['status' => $GLOBALS['testStatus'], 'headers' => $GLOBALS['testHeaders'], 'html' => ob_get_clean(), 'flash' => $GLOBALS['testFlash']];
}
function sample(array $o = []): array {
    return $o + ['model' => 3, 'product' => 'Nar - Litvanya', 'sheet_date' => '2026-11-01', 'sale_kg' => '20.000', 'sale_price' => '1,10', 'currency' => 'USD',
        'rate' => '50,00', 'price_in_fx' => true, 'raw_price' => '20', 'freight' => '15.000', 'material_kg' => '4', 'docs' => '5.000',
        'other_kg' => '0,50', 'other_fixed' => '0', 'waste_pct' => '8', 'target_margin' => '20'];
}

if (($argv[1] ?? '') === '--render') {
    $variant = $argv[2] ?? 'new';
    if ($variant === 'new') { echo page('hh')['html']; exit; }
    $saved = quick_cost_save(db(), sample());
    if ($variant === 'readonly') $testPermissions = ['read'];
    echo page('hh', ['id' => (string)$saved['id']])['html'];
    exit;
}

$checks = 0;
function check(bool $v, string $m): void { if (!$v) throw new RuntimeException('FAIL: ' . $m); $GLOBALS['checks']++; }
function near(float $a, float $b, float $tol, string $m): void { check(abs($a - $b) <= $tol, $m . " (alınan $a, beklenen $b)"); }
function fails(callable $f, string $needle, string $m): void {
    try { $f(); } catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), $needle), $m . ' — mesaj: ' . $e->getMessage()); return; }
    throw new RuntimeException('FAIL: hata bekleniyordu: ' . $m);
}

// 1) Zorunlu sayısal örnek
$r = hh_calculate(sample())['results'];
near($r['raw_kg'], 21739.1304347826, 1e-6, 'gerekli ham KG');
near($r['raw_cost'], 434782.608695652, 1e-4, 'ham mal maliyeti');
near($r['material_cost'], 80000, 1e-9, 'malzeme'); near($r['other_kg_cost'], 10000, 1e-9, 'diğer kg gideri');
near($r['fixed_cost'], 20000, 1e-9, 'sabitler');
near(round($r['total'], 2), 544782.61, 1e-9, 'toplam maliyet');
near(round($r['unit_cost'], 4), 27.2391, 1e-9, 'net kg maliyeti');
near(round($r['unit_cost_fx'], 4), 0.5448, 1e-9, 'döviz net kg maliyeti');
near($r['sale_tl'], 1100000, 1e-6, 'satış toplamı');
near(round($r['profit'], 2), 555217.39, 1e-9, 'kâr');
near(round($r['margin_pct'], 2), 50.47, 1e-9, 'kâr marjı');
near(round($r['suggested_fx'], 4), 0.6810, 1e-9, 'hedef %20 önerilen USD/kg');
near(round($r['breakeven_fx'], 4), 0.5448, 1e-9, 'başabaş');
// 2) Fire
$z = hh_calculate(sample(['waste_pct' => '0']))['results']; near($z['raw_kg'], 20000, 1e-9, 'fire 0 → ham = net');
check(hh_calculate(sample(['waste_pct' => '15']))['results']['raw_cost'] > $r['raw_cost'], 'fire artınca ham mal maliyeti artar');
// 3) Fiyat döviz / TL yorumu, EUR
$t = hh_calculate(sample(['price_in_fx' => false, 'sale_price' => '55']))['results'];
near($t['sale_tl'], 1100000, 1e-6, 'TL fiyat'); near($t['price_fx'], 1.1, 1e-12, 'TL fiyatın döviz karşılığı');
$e = hh_calculate(sample(['currency' => 'EUR', 'rate' => '55,5']));
near($e['results']['sale_tl'], 20000 * 1.1 * 55.5, 1e-6, 'EUR kur'); check($e['currency'] === 'EUR', 'EUR etiketi');
// 4) Zarar, sabitin net kg ile çarpılmaması, diğer kg çarpılması
$loss = hh_calculate(sample(['sale_price' => '0,40']))['results']; check($loss['profit'] < 0 && $loss['margin_pct'] < 0, 'zarar negatif');
$a = hh_calculate(sample(['freight' => '0', 'docs' => '0']))['results']; near($r['total'] - $a['total'], 20000, 1e-6, 'sabit giderler tekrar çarpılmaz');
$b = hh_calculate(sample(['other_kg' => '1,50']))['results']; near($b['total'] - $r['total'], 20000, 1e-6, 'diğer masraf net kg ile çarpılır');
// 5) Hedef marj
near(hh_calculate(sample(['target_margin' => '30']))['results']['suggested_tl'], $r['unit_cost'] / 0.7, 1e-9, 'marj 30');
check(hh_calculate(sample(['target_margin' => '']))['results']['suggested_fx'] === null, 'hedef marj boşsa öneri uydurulmaz');
// 6) Geçersiz giriş
fails(fn() => hh_calculate(sample(['waste_pct' => '100'])), 'Fire', 'fire %100');
fails(fn() => hh_calculate(sample(['target_margin' => '100'])), 'marj', 'marj %100');
fails(fn() => hh_calculate(sample(['sale_kg' => '0'])), 'Satış net KG', 'sıfır kg');
fails(fn() => hh_calculate(sample(['sale_kg' => ''])), 'Satış net KG', 'boş kg');
fails(fn() => hh_calculate(sample(['rate' => '0'])), 'kuru', 'sıfır kur');
fails(fn() => hh_calculate(sample(['rate' => ''])), 'kuru', 'boş kur');
fails(fn() => hh_calculate(sample(['sale_price' => '1.10'])), 'Satış fiyatı', 'belirsiz nokta biçimi sessizce sıfır olmaz');
fails(fn() => hh_calculate(sample(['raw_price' => 'abc'])), 'Ham mal', 'harf');
fails(fn() => hh_calculate(sample(['freight' => '-5'])), 'Nakliye', 'negatif');
fails(fn() => hh_calculate(sample(['currency' => 'JPY'])), 'USD veya EUR', 'döviz beyaz liste');
fails(fn() => hh_calculate(sample(['price_in_fx' => 'evet'])), 'Fiyat döviz', 'checkbox değeri');
check(hh_calculate(sample(['sale_price' => '1.100']))['sale_price'] === 1100.0, '1.100 = bin yüz');

// 7) Kayıt: sunucu yeniden hesaplar, tarayıcı toplamına güvenilmez
$in = sample(['total' => 1, 'results' => ['total' => 1], 'unit_cost' => 1]);
$saved = quick_cost_save(db(), $in);
check($saved['document']['model'] === 3 && $saved['method'] === 'hizli', 'model 3 / method hizli');
near((float)$saved['total'], 544782.61, 1e-9, 'saklanan toplam sunucu hesabı'); check($saved['document']['product'] === 'NAR - LİTVANYA', 'ad büyük harf');
$again = quick_cost_find(db(), (int)$saved['id']);
near($again['document']['results']['total'], $r['total'], 1e-6, 'yeniden açılış hesabı');
check($again['document']['rate'] === 50.0 && $again['document']['currency'] === 'USD', 'kur/döviz korunur');
check(count($testAudit) === 1 && $testAudit[0][0] === 'create', 'audit yazıldı');
$upd = quick_cost_save(db(), sample(['sale_price' => '1,20']), (int)$saved['id'], (int)$saved['version']);
check($upd['version'] === $saved['version'] + 1, 'düzenleme sürümü');
// 8) Eski v1/v2 uyumu
$v1 = quick_cost_defaults('2026-10-30'); $v1['product'] = 'x'; $v1['net_kg'] = 100;
$oldSaved = quick_cost_save(db(), $v1); check(!hh_is_model($oldSaved['document']), 'v1 kaydı model 3 değil');
$v2 = quick_cost_v2_defaults('2026-10-31'); $v2['product'] = 'y'; $v2['quantities']['export_kg'] = 100; $v2['quantities']['raw_kg'] = 120;
$v2Saved = quick_cost_save(db(), $v2); check(quick_cost_is_v2($v2Saved['document']), 'v2 kaydı v2 kalır');

// 9) Sayfa: açma, kaydetme, yetki, CSRF, depo
$p = page('hh'); check($p['status'] === 200 && str_contains($p['html'], 'Hızlı Hesapla') && !str_contains($p['html'], 'Hesapla</button>'), 'yeni sayfa açılır, hesapla düğmesi yok');
check(str_contains($p['html'], 'id="hhSave"') && str_contains($p['html'], 'id="hhShare"'), 'Kaydet/Paylaş düğmeleri');
$payload = fn(array $d) => ['payload' => json_encode($d), 'version' => '0', 'csrf' => 'anonymous-csrf'];
$doc = ['model' => 3, 'product' => 'test hesap', 'sale_kg' => 1000, 'sale_price' => 1.1, 'currency' => 'USD', 'rate' => 50, 'price_in_fx' => true, 'raw_price' => 20,
    'freight' => 0, 'material_kg' => 0, 'docs' => 0, 'other_kg' => 0, 'other_fixed' => 0, 'waste_pct' => 8, 'target_margin' => 20];
$ok = page('hh', [], $payload($doc));
check($ok['status'] === 200 && str_contains(implode('', $ok['headers']), 'hizli_hesapla.php?id='), 'kaydet → PRG');
$bad = page('hh', [], ['payload' => json_encode($doc), 'version' => '0', 'csrf' => 'yanlis']); check($bad['status'] === 403, 'CSRF reddi');
$noName = page('hh', [], $payload(array_replace($doc, ['product' => ' ']))); check($noName['status'] === 422 && str_contains($noName['html'], 'Hesap adı gerekli'), 'adsız kayıt reddi');
$badNum = page('hh', [], $payload(array_replace($doc, ['rate' => 0]))); check($badNum['status'] === 422, 'geçersiz kur 422');
$wrong = page('hh', [], $payload(['model' => 2] + $doc)); check($wrong['status'] === 422, 'başka model gövdesi 422');
$testPermissions = ['read']; $ro = page('hh', [], $payload($doc)); check($ro['status'] === 403, 'yazma yetkisi yoksa kayıt yok');
$view = page('hh'); check($view['status'] === 200 && str_contains($view['html'], 'id="hhSave" disabled'), 'salt okunur: Kaydet kapalı');
$testPermissions = ['read', 'write', 'delete'];
$rid = (int)db()->query("SELECT id FROM quick_cost_sheets WHERE product='TEST HESAP'")->fetchColumn();
check($rid > 0 && page('hh', ['id' => (string)$rid])['status'] === 200, 'listeden açılır');
$testDepot = 'BASKA'; check(page('hh', ['id' => (string)$rid])['status'] === 404, 'başka depo kaydı açılmaz'); $testDepot = 'DEMO';
// 10) Eski ekranlarla çapraz yönlendirme
$to = page('form', ['id' => (string)$rid]); check(str_contains(implode('', $to['headers']), 'hizli_hesapla.php?id=' . $rid), 'v1/v2 formu model 3 kaydını Hızlı Hesapla ekranına yollar');
$to2 = page('hh', ['id' => (string)$oldSaved['id']]); check(str_contains(implode('', $to2['headers']), 'hizli_maliyet_form.php?id=' . $oldSaved['id']), 'eski kayıt kendi ekranına yollanır');
$cross = page('form', [], $payload($doc)); check($cross['status'] === 422, 'eski formdan model 3 kaydı yapılamaz');
$l = page('list'); check(str_contains($l['html'], 'hizli_hesapla.php?id=' . $rid) && str_contains($l['html'], 'href="hizli_hesapla.php"'), 'liste: Hızlı Hesapla bağlantıları');
// 11) Statik kurallar
$ctl = file_get_contents($root . '/hizli_hesapla.php');
check(str_contains($ctl, 'csrf_check(') && str_contains($ctl, "require_hizli_maliyet('write')") && !str_contains($ctl, 'can_maliyet'), 'controller kapıları');
check(!str_contains($ctl, 'api.exchange') && !preg_match('/https?:\/\//', file_get_contents($root . '/assets/hizli-hesapla.js')), 'otomatik kur / dış istek yok');
check(str_contains(file_get_contents($root . '/sw.js'), '/\\/hizli_hesapla\\.php(\\/|$)/.test(u.pathname)) return;'), 'SW önbelleğe almaz');
echo "OK — $checks kontrol geçti.\n";
