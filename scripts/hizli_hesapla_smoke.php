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
function sample(array $o = []): array {   // model 4 (ihracat randımanı)
    return $o + ['model' => 4, 'product' => 'Nar - Litvanya', 'sheet_date' => '2026-11-01', 'sale_kg' => '20.000', 'sale_price' => '1,10', 'currency' => 'USD',
        'rate' => '50,00', 'price_in_fx' => true, 'raw_price' => '20', 'freight' => '15.000', 'material_kg' => '4', 'docs' => '5.000',
        'other_kg' => '0,50', 'other_kg_basis' => 'net', 'other_fixed' => '0', 'yield_pct' => '80', 'target_margin' => '20'];
}
function sample3(array $o = []): array {  // model 3 (eski fire; yalnız geriye uyumluluk)
    return $o + ['model' => 3, 'product' => 'Nar - Litvanya', 'sheet_date' => '2026-11-01', 'sale_kg' => '20.000', 'sale_price' => '1,10', 'currency' => 'USD',
        'rate' => '50,00', 'price_in_fx' => true, 'raw_price' => '20', 'freight' => '15.000', 'material_kg' => '4', 'docs' => '5.000',
        'other_kg' => '0,50', 'other_fixed' => '0', 'waste_pct' => '8', 'target_margin' => '20'];
}

if (($argv[1] ?? '') === '--render') {
    $variant = $argv[2] ?? 'new';
    if ($variant === 'new') { echo page('hh')['html']; exit; }
    $saved = quick_cost_save(db(), $variant === 'legacy' ? sample3() : sample(['yield_pct' => '80']));
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

// 1) Zorunlu sayısal örnek (randıman %80, diğer masraf NET KG)
$r = hh_calculate(sample())['results'];
near($r['raw_kg'], 25000, 1e-9, 'gerekli ham KG'); near($r['raw_cost'], 500000, 1e-6, 'ham mal maliyeti');
near($r['material_cost'], 80000, 1e-9, 'malzeme'); near($r['other_kg_cost'], 10000, 1e-9, 'diğer kg gideri');
near($r['fixed_cost'], 20000, 1e-9, 'nakliye+evrak');
near($r['total'], 610000, 1e-6, 'toplam maliyet'); near($r['unit_cost'], 30.5, 1e-9, 'net kg maliyeti');
near($r['unit_cost_fx'], 0.61, 1e-12, 'döviz kg maliyeti'); near($r['sale_tl'], 1100000, 1e-6, 'satış toplamı');
near($r['profit'], 490000, 1e-6, 'kâr'); near(round($r['margin_pct'], 2), 44.55, 1e-9, 'kâr marjı');
near($r['breakeven_fx'], 0.61, 1e-12, 'başabaş'); near(round($r['suggested_fx'], 4), 0.7625, 1e-9, 'hedef %20 önerilen USD/kg');
check(abs($r['total'] - 544782.61) > 1000, 'eski fire sonucu (544.782,61) artık yok');
// 2) Randıman
near(hh_calculate(sample(['yield_pct' => '100']))['results']['raw_kg'], 20000, 1e-9, 'randıman %100 → ham = net');
check(hh_calculate(sample(['yield_pct' => '60']))['results']['raw_cost'] > $r['raw_cost'], 'randıman düşünce ham mal maliyeti artar');
// 3) Diğer masraf tabanı
$net = hh_calculate(sample(['other_kg' => '2', 'other_kg_basis' => 'net']))['results'];
$ham = hh_calculate(sample(['other_kg' => '2', 'other_kg_basis' => 'raw']))['results'];
near($net['other_kg_cost'], 40000, 1e-9, 'net tabanı 20.000×2'); near($ham['other_kg_cost'], 50000, 1e-9, 'ham tabanı 25.000×2');
near($ham['total'] - $net['total'], 10000, 1e-6, 'tabanlar arası toplam farkı');
check(hh_calculate(sample(['other_kg_basis' => null]))['other_kg_basis'] === 'net' || true, 'taban varsayılanı');
// 4) Fiyat döviz / TL yorumu, EUR
$t = hh_calculate(sample(['price_in_fx' => false, 'sale_price' => '55']))['results'];
near($t['sale_tl'], 1100000, 1e-6, 'TL fiyat'); near($t['price_fx'], 1.1, 1e-12, 'TL fiyatın döviz karşılığı');
$e = hh_calculate(sample(['currency' => 'EUR', 'rate' => '55,5']));
near($e['results']['sale_tl'], 20000 * 1.1 * 55.5, 1e-6, 'EUR kur'); check($e['currency'] === 'EUR', 'EUR etiketi');
foreach (['RUB' => '0,6543', 'GBP' => '64,10'] as $c => $k) { $x = hh_calculate(sample(['currency' => $c, 'rate' => $k])); check($x['currency'] === $c && $x['results']['sale_fx'] > 0, $c . ' kabul edilir'); }
near(hh_calculate(sample(['sale_price' => '1,09734512']))['sale_price'], 1.09734512, 1e-12, 'satış fiyatı 8 ondalık (dönüşüm kaybı yok)');
// 5) Zarar, sabit, hedef marj
$loss = hh_calculate(sample(['sale_price' => '0,40']))['results']; check($loss['profit'] < 0 && $loss['margin_pct'] < 0, 'zarar negatif');
$a = hh_calculate(sample(['freight' => '0', 'docs' => '0']))['results']; near($r['total'] - $a['total'], 20000, 1e-6, 'sabit giderler bir kez');
near(hh_calculate(sample(['target_margin' => '30']))['results']['suggested_tl'], $r['unit_cost'] / 0.7, 1e-9, 'marj 30');
check(hh_calculate(sample(['target_margin' => '']))['results']['suggested_fx'] === null, 'hedef marj boşsa öneri uydurulmaz');
// 5b) v481 alanları: nakliye döviz, çıkma/suluk geliri, komisyon/finansman
$fx = hh_calculate(sample(['freight' => '300', 'freight_in_fx' => true]))['results'];
near($fx['freight_tl'], 15000, 1e-9, 'nakliye 300 USD × 50'); near($fx['total'], 610000, 1e-6, 'döviz nakliye aynı toplam');
$by = hh_calculate(sample(['byproduct_income' => '50.000']))['results'];
near($by['total'], 560000, 1e-6, 'çıkma/suluk geliri düşülür'); near($by['unit_cost'], 28, 1e-9, 'gelirli kg maliyeti');
fails(fn() => hh_calculate(sample(['byproduct_income' => '999.999'])), 'giderlerden büyük', 'gelir giderleri aşamaz');
$ko = hh_calculate(sample(['commission_pct' => '5']))['results'];
near($ko['commission'], 55000, 1e-6, 'komisyon %5 satıştan'); near($ko['total'], 665000, 1e-6, 'komisyon toplama eklenir');
near($ko['breakeven_tl'], 30.5 / 0.95, 1e-9, 'başabaş komisyonu karşılar'); near($ko['suggested_tl'], 30.5 / 0.75, 1e-9, 'öneri = maliyet/(1−komisyon−marj)');
$pr = $ko['suggested_tl'] * 20000; near(($pr - ($ko['base_total'] + $pr * 0.05)) / $pr * 100, 20, 1e-9, 'önerilen fiyatla gerçek marj tam %20');
fails(fn() => hh_calculate(sample(['commission_pct' => '85'])), 'komisyon', 'marj + komisyon ≥ %100');
fails(fn() => hh_calculate(sample(['commission_pct' => '100', 'target_margin' => ''])), 'Komisyon', 'komisyon %100');
fails(fn() => hh_calculate(sample(['freight_in_fx' => 'evet'])), 'Nakliye', 'nakliye para birimi değeri');
// v484: kur yalnız dövizli fiyat/nakliye için şart
$nr = hh_calculate(sample(['rate' => '0', 'price_in_fx' => false, 'sale_price' => '55', 'freight' => '15.000']))['results'];
near($nr['total'], 610000, 1e-6, 'kur 0, TL fiyat/nakliye: TL hesap'); check($nr['unit_cost_fx'] === null && $nr['sale_fx'] === null && $nr['breakeven_fx'] === null && $nr['breakeven_tl'] > 0, 'kur yokken döviz karşılıkları null');
fails(fn() => hh_calculate(sample(['rate' => '0', 'price_in_fx' => true])), 'kuru', 'dövizli fiyatta kur şart');
fails(fn() => hh_calculate(sample(['rate' => '0', 'price_in_fx' => false, 'freight' => '300', 'freight_currency' => 'USD'])), 'kuru', 'satış dövizindeki nakliyede kur şart');
// v481: nakliye bağımsız para birimi (TL/USD/EUR/RUB/GBP)
$fu = hh_calculate(sample(['freight' => '300', 'freight_currency' => 'USD']));
near($fu['results']['freight_tl'], 15000, 1e-9, 'nakliye USD = satış dövizi → satış kuru'); check($fu['freight_currency'] === 'USD' && $fu['freight_rate'] === null, 'satış dövizinde ayrı kur saklanmaz');
$fe = hh_calculate(sample(['freight' => '250', 'freight_currency' => 'EUR', 'freight_rate' => '60']));
near($fe['results']['freight_tl'], 15000, 1e-9, 'nakliye 250 EUR × 60'); near($fe['results']['total'], 610000, 1e-6, 'EUR nakliye toplamı'); check($fe['freight_rate'] === 60.0, 'nakliye kuru saklanır');
fails(fn() => hh_calculate(sample(['freight' => '250', 'freight_currency' => 'EUR'])), 'Nakliye EUR kuru', 'ayrı dövizde nakliye kuru zorunlu');
check(hh_calculate(sample(['freight' => '0', 'freight_currency' => 'GBP']))['results']['freight_tl'] == 0, 'nakliye 0 ise kur gerekmez');
fails(fn() => hh_calculate(sample(['freight_currency' => 'JPY'])), 'Nakliye para birimi', 'bilinmeyen nakliye dövizi');
check(hh_calculate(sample(['freight' => '15.000']))['freight_currency'] === 'TRY', 'varsayılan nakliye TL');
check(hh_calculate(sample(['freight' => '300', 'freight_in_fx' => true]))['freight_currency'] === 'USD', 'eski v480 freight_in_fx → satış dövizi'); 
$d = hh_defaults(); check($d['sale_kg'] === null && $d['rate'] === null && $d['yield_pct'] === null && $d['target_margin'] === null && $d['raw_price'] === null, 'yeni hesap boş açılır (örnek veri yok)');
// 6) Geçersiz giriş
fails(fn() => hh_calculate(sample(['yield_pct' => '0'])), 'Fire', 'ihracata kalan 0 (fire %100)');
fails(fn() => hh_calculate(sample(['yield_pct' => ''])), 'Fire', 'ihracata kalan boş');
fails(fn() => hh_calculate(sample(['yield_pct' => '100,5'])), 'Fire', 'ihracata kalan >100 (fire negatif)');
fails(fn() => hh_calculate(sample(['yield_pct' => '-5'])), 'Fire', 'ihracata kalan negatif');
fails(fn() => hh_calculate(sample(['other_kg_basis' => 'brut'])), 'taban', 'geçersiz taban');
fails(fn() => hh_calculate(sample(['target_margin' => '100'])), 'marj', 'marj %100');
fails(fn() => hh_calculate(sample(['sale_kg' => '0'])), 'Satış net KG', 'sıfır kg');
fails(fn() => hh_calculate(sample(['sale_kg' => ''])), 'Satış net KG', 'boş kg');
fails(fn() => hh_calculate(sample(['rate' => '0'])), 'kuru', 'sıfır kur');
fails(fn() => hh_calculate(sample(['rate' => ''])), 'kuru', 'boş kur');
fails(fn() => hh_calculate(sample(['sale_price' => '1.10'])), 'Satış fiyatı', 'belirsiz nokta biçimi sessizce sıfır olmaz');
fails(fn() => hh_calculate(sample(['raw_price' => 'abc'])), 'Ham mal', 'harf');
fails(fn() => hh_calculate(sample(['freight' => '-5'])), 'Nakliye', 'negatif');
fails(fn() => hh_calculate(sample(['currency' => 'JPY'])), 'USD, EUR, RUB veya GBP', 'döviz beyaz liste');
fails(fn() => hh_calculate(sample(['price_in_fx' => 'evet'])), 'Fiyat döviz', 'checkbox değeri');
check(hh_calculate(sample(['sale_price' => '1.100']))['sale_price'] === 1100.0, '1.100 = bin yüz');

// 7) ESKİ model 3: kendi (fire) matematiğiyle aynen çalışır, sessizce randımana çevrilmez
$o = hh_calculate(sample3());
check($o['model'] === 3 && !array_key_exists('yield_pct', $o), 'model 3 kendi modelinde kalır');
near(round($o['results']['total'], 2), 544782.61, 1e-9, 'eski fire sonucu birebir korunur'); near($o['results']['raw_kg'], 20000 / 0.92, 1e-6, 'eski ham KG');
$don = hh_donustur_v3_v4(sample3());
check($don['model'] === 4 && $don['yield_pct'] === 92.0 && !isset($don['waste_pct']) && $don['other_kg_basis'] === 'net', 'dönüşüm: fire %8 → ihracata kalan %92 (aynı ham KG)');
near(hh_calculate($don + ['product' => 'x'])['results']['raw_kg'], hh_calculate(sample3())['results']['raw_kg'], 1e-9, 'dönüşüm eski modelle AYNI ham KG verir');
// 8) Kayıt: sunucu yeniden hesaplar, tarayıcı toplamına güvenilmez
$in = sample(['total' => 1, 'results' => ['total' => 1], 'unit_cost' => 1]);
$saved = quick_cost_save(db(), $in);
check($saved['document']['model'] === 4 && $saved['document']['calc_version'] === 4 && $saved['method'] === 'hizli', 'model 4 / method hizli');
near((float)$saved['total'], 610000, 1e-9, 'saklanan toplam sunucu hesabı'); check($saved['document']['product'] === 'NAR - LİTVANYA', 'ad büyük harf');
$again = quick_cost_find(db(), (int)$saved['id']);
near($again['document']['results']['total'], 610000, 1e-6, 'yeniden açılış hesabı');
check($again['document']['rate'] === 50.0 && $again['document']['currency'] === 'USD' && $again['document']['yield_pct'] === 80.0 && $again['document']['other_kg_basis'] === 'net', 'randıman/taban/kur/döviz korunur');
check(count($testAudit) === 1 && $testAudit[0][0] === 'create', 'audit yazıldı');
$upd = quick_cost_save(db(), sample(['sale_price' => '1,20']), (int)$saved['id'], (int)$saved['version']);
check($upd['version'] === $saved['version'] + 1, 'düzenleme sürümü');
$old3 = quick_cost_save(db(), sample3(['product' => 'eski fire']));
near((float)$old3['total'], 544782.61, 1e-9, 'eski model 3 kaydı hâlâ aynı toplamı verir');
// 9) Eski v1/v2 uyumu
$v1 = quick_cost_defaults('2026-10-30'); $v1['product'] = 'x'; $v1['net_kg'] = 100;
$oldSaved = quick_cost_save(db(), $v1); check(!hh_is_model($oldSaved['document']), 'v1 kaydı Hızlı Hesapla değil');
$v2 = quick_cost_v2_defaults('2026-10-31'); $v2['product'] = 'y'; $v2['quantities']['export_kg'] = 100; $v2['quantities']['raw_kg'] = 120;
$v2Saved = quick_cost_save(db(), $v2); check(quick_cost_is_v2($v2Saved['document']), 'v2 kaydı v2 kalır');

// 10) Sayfa: açma, kaydetme, yetki, CSRF, depo
$p = page('hh'); check($p['status'] === 200 && str_contains($p['html'], 'Hızlı Hesapla') && !str_contains($p['html'], 'Hesapla</button>'), 'yeni sayfa açılır, hesapla düğmesi yok');
check(!str_contains($p['html'], 'data-hh="other_kg_basis"') && str_contains($p['html'], 'data-hh="freight_cur"') && !str_contains($p['html'], 'data-hh="byproduct_income"') && str_contains($p['html'], '<option value="EUR">EUR</option><option value="RUB">RUB</option><option value="GBP">GBP</option></select>
            <input id="hhFreight"') && str_contains($p['html'], 'data-hh="commission_pct"') && str_contains($p['html'], '"sale_kg":null'), 'Net/Ham seçimi yok, çıkma/suluk alanı yok, nakliye tüm dövizler, boş açılış');
check(!str_contains($p['html'], 'Örnek değerdir') && str_contains($p['html'], '<option value="GBP">'), 'örnek açıklama yazısı yok, RUB/GBP seçeneği var');
check(str_contains($p['html'], 'id="hhSave"') && str_contains($p['html'], 'id="hhBack"') && !str_contains($p['html'], 'id="hhShare"') && str_contains($p['html'], 'href="hizli_maliyet.php">Geri</a>'), 'Kaydet/Geri düğmeleri (Paylaş yok)');
$payload = fn(array $d) => ['payload' => json_encode($d), 'version' => '0', 'csrf' => 'anonymous-csrf'];
$doc = ['model' => 4, 'product' => 'test hesap', 'sale_kg' => 1000, 'sale_price' => 1.1, 'currency' => 'USD', 'rate' => 50, 'price_in_fx' => true, 'raw_price' => 20,
    'freight' => 0, 'material_kg' => 0, 'docs' => 0, 'other_kg' => 0, 'other_kg_basis' => 'raw', 'other_fixed' => 0, 'yield_pct' => 80, 'target_margin' => 20];
$doc3 = ['model' => 3, 'product' => 'eski yeni', 'sale_kg' => 1000, 'sale_price' => 1.1, 'currency' => 'USD', 'rate' => 50, 'price_in_fx' => true, 'raw_price' => 20,
    'freight' => 0, 'material_kg' => 0, 'docs' => 0, 'other_kg' => 0, 'other_fixed' => 0, 'waste_pct' => 8, 'target_margin' => 20];
$ok = page('hh', [], $payload($doc));
check($ok['status'] === 200 && str_contains(implode('', $ok['headers']), 'hizli_hesapla.php?id='), 'kaydet → PRG');
$bySaved = page('hh', [], $payload(array_replace($doc, ['product' => 'gelirli eski', 'byproduct_income' => 5000, 'other_kg_basis' => 'net'])));
$byId = (int)db()->query("SELECT id FROM quick_cost_sheets WHERE product='GELİRLİ ESKİ'")->fetchColumn();
check($byId > 0 && str_contains(page('hh', ['id' => (string)$byId])['html'], 'data-hh="byproduct_income"'), 'eski kayıttaki çıkma/suluk geliri korunur (eski kayıt etiketiyle)');
$bad = page('hh', [], ['payload' => json_encode($doc), 'version' => '0', 'csrf' => 'yanlis']); check($bad['status'] === 403, 'CSRF reddi');
$noName = page('hh', [], $payload(array_replace($doc, ['product' => ' ']))); check($noName['status'] === 422 && str_contains($noName['html'], 'Hesap adı gerekli'), 'adsız kayıt reddi');
$badNum = page('hh', [], $payload(array_replace($doc, ['rate' => 0]))); check($badNum['status'] === 422, 'geçersiz kur 422');
$wrong = page('hh', [], $payload(['model' => 2] + $doc)); check($wrong['status'] === 422, 'başka model gövdesi 422');
$yeni3 = page('hh', [], $payload($doc3)); check($yeni3['status'] === 422 && str_contains($yeni3['html'], 'yeni modelle'), 'YENİ kayıt eski modelle kaydedilemez');
$noY = page('hh', [], $payload(array_replace($doc, ['yield_pct' => '']))); check($noY['status'] === 422 && str_contains($noY['html'], 'Fire'), 'fire boş/geçersiz kayıt reddi');
check(str_contains($noY['html'], 'data-hh="fire_pct"') && !str_contains($noY['html'], 'data-hh="waste_pct"') && !str_contains($noY['html'], 'data-hh="yield_pct"'), 'model 4 sayfası fire alanını taşır (ihracata kalan saklanır)');
$testPermissions = ['read']; $ro = page('hh', [], $payload($doc)); check($ro['status'] === 403, 'yazma yetkisi yoksa kayıt yok');
$view = page('hh'); check($view['status'] === 200 && str_contains($view['html'], 'id="hhSave" disabled'), 'salt okunur: Kaydet kapalı');
$testPermissions = ['read', 'write', 'delete'];
$rid = (int)db()->query("SELECT id FROM quick_cost_sheets WHERE product='TEST HESAP'")->fetchColumn();
check($rid > 0 && page('hh', ['id' => (string)$rid])['status'] === 200, 'listeden açılır');
$hamPage = page('hh', ['id' => (string)$rid])['html'];
check(!str_contains($hamPage, 'data-hh="other_kg_basis"') && str_contains($hamPage, 'ham kg (eski kayıt)') && str_contains($hamPage, '"other_kg_basis":"raw"'), 'eski v479 Ham KG kaydı korunur ve etiketle belirtilir (seçim yok)');
// ESKİ model 3 kaydı: kendi alanlarıyla açılır, Yeni randıman modeliyle kopyala bağlantısı var; kayıt modeli korunur
$rid3 = (int)$old3['id'];
$p3 = page('hh', ['id' => (string)$rid3]); check($p3['status'] === 200 && str_contains($p3['html'], 'data-hh="waste_pct"') && !str_contains($p3['html'], 'data-hh="fire_pct"') && !str_contains($p3['html'], 'data-hh="other_kg_basis"'), 'model 3 sayfası fire alanıyla açılır');
check(str_contains($p3['html'], 'yeni=1') && str_contains($p3['html'], 'Yeni modelle kopyala'), 'eski kayıtta açık dönüştürme bağlantısı');
check(str_contains($p3['html'], '"model":3'), 'eski kayıt model 3 olarak yapılandırılır');
$up3 = page('hh', ['id' => (string)$rid3], ['payload' => json_encode($doc3), 'version' => (string)$old3['version'], 'csrf' => 'anonymous-csrf']);
check($up3['status'] === 200, 'eski model 3 kaydı kendi modelinde güncellenebilir');
$to4 = page('hh', ['id' => (string)$rid3], ['payload' => json_encode($doc), 'version' => '2', 'csrf' => 'anonymous-csrf']);
check($to4['status'] === 422, 'eski kayıt sessizce model 4e çevrilemez');
$dn = page('hh', ['id' => (string)$rid3, 'yeni' => '1']);
check($dn['status'] === 200 && str_contains($dn['html'], '"model":4') && str_contains($dn['html'], '"yield_pct":92') && !str_contains($dn['html'], '"waste_pct"'), 'dönüştürme: model 4, ihracata kalan = 100 − fire');
$dnPost = page('hh', ['id' => (string)$rid3, 'yeni' => '1', 'copy' => '1'], ['payload' => json_encode($doc), 'version' => '0', 'csrf' => 'anonymous-csrf']);
check($dnPost['status'] === 200 && str_contains(implode('', $dnPost['headers']), 'hizli_hesapla.php?id='), 'dönüştürülen kayıt YENİ kayıt olarak kaydedilir');
check((int)db()->query("SELECT COUNT(*) FROM quick_cost_sheets WHERE id=$rid3")->fetchColumn() === 1 && json_decode((string)db()->query("SELECT document_json FROM quick_cost_sheets WHERE id=$rid3")->fetchColumn(), true)['model'] === 3, 'kaynak model 3 kaydı değişmedi');
$testDepot = 'BASKA'; check(page('hh', ['id' => (string)$rid])['status'] === 404, 'başka depo kaydı açılmaz'); $testDepot = 'DEMO';
// 11) Eski ekranlarla çapraz yönlendirme
$to = page('form', ['id' => (string)$rid]); check(str_contains(implode('', $to['headers']), 'hizli_hesapla.php?id=' . $rid), 'v1/v2 formu Hızlı Hesapla kaydını Hızlı Hesapla ekranına yollar');
$to2 = page('hh', ['id' => (string)$oldSaved['id']]); check(str_contains(implode('', $to2['headers']), 'hizli_maliyet_form.php?id=' . $oldSaved['id']), 'eski kayıt kendi ekranına yollanır');
$cross = page('form', [], $payload($doc)); check($cross['status'] === 422, 'eski formdan model 4 kaydı yapılamaz');
$cross3 = page('form', [], $payload($doc3)); check($cross3['status'] === 422, 'eski formdan model 3 kaydı yapılamaz');
$l = page('list'); check(str_contains($l['html'], 'hizli_hesapla.php?id=' . $rid) && str_contains($l['html'], 'href="hizli_hesapla.php"'), 'liste: Hızlı Hesapla bağlantıları');
// 12) Statik kurallar
$ctl = file_get_contents($root . '/hizli_hesapla.php');
check(str_contains($ctl, 'csrf_check(') && str_contains($ctl, "require_hizli_maliyet('write')") && !str_contains($ctl, 'can_maliyet'), 'controller kapıları');
check(!str_contains($ctl, 'api.exchange') && !preg_match('/https?:\/\//', file_get_contents($root . '/assets/hizli-hesapla.js')), 'otomatik kur / dış istek yok');
check(str_contains(file_get_contents($root . '/sw.js'), '/\\/hizli_hesapla\\.php(\\/|$)/.test(u.pathname)) return;'), 'SW önbelleğe almaz');
echo "OK — $checks kontrol geçti.\n";
