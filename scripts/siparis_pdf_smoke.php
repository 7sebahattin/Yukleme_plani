<?php
// =========================================================
// scripts/siparis_pdf_smoke.php — Sipariş PDF ayrıştırıcısı (config/siparis_pdf.php)
//   php scripts/siparis_pdf_smoke.php                       → ağsız temel testler (gerçek belge gerekmez)
//   SIPARIS_ORNEK_DIR=<klasör> php scripts/siparis_pdf_smoke.php [--fuzz]
//       → gerçek örnek belgeler (set 1 / set 2) + sayfa bölme fuzz testi (örnekler varken hep koşar)
// Gerçek örnekler kişisel veri içerir ve repoda YOKTUR; değişken yoksa o bölümler ATLANIR.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';

echo "=== 1. Statik ===\n";
$src = (string)file_get_contents($ROOT . '/config/siparis_pdf.php');
ok('strict_types YOK (gevşek tip dönüşümüne dayanır)', !preg_match('/^\s*declare\s*\(\s*strict_types/m', $src));
ok('iç önek sipdf_, eski sp_ öneki kalmadı', !preg_match('/\bsp_[a-z]/', preg_replace('#//[^\n]*#', '', $src)) && str_contains($src, 'function sipdf_lines('));
ok('genel API adları duruyor', function_exists('siparis_pdf_oku') && function_exists('siparis_pdf_belge_turu') && function_exists('siparis_pdf_parse_order')
    && function_exists('siparis_pdf_parse_price_confirmation') && function_exists('siparis_pdf_parse_loading_list') && function_exists('siparis_pdf_isle') && function_exists('siparis_pdf_capraz_kontrol'));
ok('SIPARIS_PDF_SURUM = v4 (v4: PO sayfa devamı)', SIPARIS_PDF_SURUM === 'v4');
ok('exec/shell/eval yok, CLI bloğu yok', !preg_match('/\b(shell_exec|passthru|proc_open|popen|eval)\s*\(|(?<!->)\bexec\s*\(|PHP_SAPI|\$argv/', $src));
ok('XML okumada LIBXML_NOENT / DTDLOAD yok', !preg_match('/LIBXML_NOENT|LIBXML_DTDLOAD/', $src));

echo "\n=== 2. Hatalı girdi asla istisna sızdırmaz ===\n";
$tmp = $SIPARIS_TEST_DIR . '/pdf'; @mkdir($tmp, 0700, true);
file_put_contents("$tmp/metin.pdf", "merhaba, bu bir PDF değil");
$d = siparis_pdf_isle("$tmp/metin.pdf");
ok('PDF olmayan dosya → doc_type error', $d['doc_type'] === 'error' && $d['_file'] === 'metin.pdf' && ($d['warnings'][0]['code'] ?? '') === 'exception');
file_put_contents("$tmp/sifreli.pdf", "%PDF-1.4\n1 0 obj << /Encrypt 2 0 R >> endobj\n%%EOF");
ok('/Encrypt → error', siparis_pdf_isle("$tmp/sifreli.pdf")['doc_type'] === 'error' && str_contains((string)siparis_pdf_isle("$tmp/sifreli.pdf")['error'], 'encrypted'));
$h = fopen("$tmp/buyuk.pdf", 'w'); fwrite($h, '%PDF-1.4'); ftruncate($h, SIPARIS_MAX_DOSYA + 1); fclose($h);
ok('SIPARIS_MAX_DOSYA üstü → error (too large)', str_contains((string)siparis_pdf_isle("$tmp/buyuk.pdf")['error'], 'too large'));
file_put_contents("$tmp/agacsiz.pdf", "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");
ok('sayfa ağacı yok → error', siparis_pdf_isle("$tmp/agacsiz.pdf")['doc_type'] === 'error');
file_put_contents("$tmp/dongu.pdf", "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [2 0 R] >> endobj\n%%EOF");
ok('döngülü sayfa ağacı yığın taşırmaz → error', siparis_pdf_isle("$tmp/dongu.pdf")['doc_type'] === 'error');
ok('olmayan dosya → error', siparis_pdf_isle("$tmp/yok.pdf")['doc_type'] === 'error');
$rasgele = 0;
for ($i = 0; $i < 20; $i++) {
    file_put_contents("$tmp/r$i.pdf", "%PDF-1.4\n" . random_bytes(2000) . "\n1 0 obj << /Type /Pages /Kids [2 0 R] >> endobj stream\n" . random_bytes(500) . "\nendstream");
    $d = siparis_pdf_isle("$tmp/r$i.pdf");
    if (in_array($d['doc_type'], ['error', 'unknown'], true)) $rasgele++;
}
ok('rastgele baytlı 20 "PDF" istisnasız error/unknown', $rasgele === 20);

echo "\n=== 3. dompdf ile üretilmiş Loading List başlıklı PDF ===\n";
if (is_file($ROOT . '/vendor/autoload.php')) {
    require_once $ROOT . '/vendor/autoload.php';
    $opt = new \Dompdf\Options(); $opt->set('isRemoteEnabled', false); $opt->set('isPhpEnabled', false); $opt->set('defaultFont', 'Helvetica');
    $dp = new \Dompdf\Dompdf($opt);
    $dp->loadHtml('<html><body style="font-family:Helvetica"><p>Loading List - Ladeplan</p><p>Agency TEST</p><p>Transport 6100000001</p></body></html>');
    $dp->setPaper('A4', 'landscape');
    $dp->render();
    file_put_contents("$tmp/dompdf_ll.pdf", $dp->output());
    $r = siparis_pdf_oku("$tmp/dompdf_ll.pdf");
    $metin = implode(' ', array_map(fn($s) => $s['text'], $r['pages'][1] ?? []));
    ok('sayfa segmentleri çıkarıldı (x/y/size/text)', isset($r['pages'][1][0]['x'], $r['pages'][1][0]['y'], $r['pages'][1][0]['size']) && str_contains($metin, 'Ladeplan'), $metin);
    ok('meta: sayfa sayısı + boyut', $r['meta']['pages'] === 1 && $r['meta']['bytes'] > 0);
    ok('belge türü loading_list', siparis_pdf_belge_turu($r['pages']) === 'loading_list');
    $d = siparis_pdf_isle("$tmp/dompdf_ll.pdf");
    ok('isle: loading_list, istisnasız (tablo yok → uyarılar)', $d['doc_type'] === 'loading_list' && is_array($d['warnings']) && is_array($d['checks']));
    $dp2 = new \Dompdf\Dompdf($opt);
    $dp2->loadHtml('<p style="font-family:Helvetica">Rastgele bir yazi</p>');
    $dp2->render();
    file_put_contents("$tmp/dompdf_x.pdf", $dp2->output());
    ok('tanınmayan PDF → unknown', siparis_pdf_isle("$tmp/dompdf_x.pdf")['doc_type'] === 'unknown');
} else {
    echo "ATLANDI (vendor yok)\n";
}

echo "\n=== 4. Çapraz denetim (SENTETİK fixture'lar) ===\n";
$cc = siparis_pdf_capraz_kontrol([siparis_test_fixture('po_uc_kalibre'), siparis_test_fixture('ll_plakasiz'), siparis_test_fixture('pc_bolum_farkli')]);
$sd = array_values(array_filter($cc['warnings'], fn($w) => $w['code'] === 'split_differs'));
ok('TC900011.10 split_differs (3 kalibre)', count($sd) === 3 && !empty($cc['shipments']['TC900011.10']['split_differs']) && $cc['shipments']['TC900011.10']['present_in'] === ['po', 'll', 'pc']);
ok('yalnız PO\'daki sipariş uyarı üretmez', empty($cc['shipments']['TC900012.10']['split_differs']) && $cc['shipments']['TC900012.10']['present_in'] === ['po']);
ok('tur / tarih / toplam palet denetimleri geçer', count(array_filter($cc['checks'], fn($c) => in_array($c['id'], ['tour_po_vs_ll', 'loading_date_po_vs_ll', 'pallets_po_vs_ll'], true) && $c['ok'])) === 3);
ok('toplam koli LL ≠ PC denetimi başarısız', count(array_filter($cc['checks'], fn($c) => $c['id'] === 'quantity_ll_vs_pc' && !$c['ok'])) === 1);

echo "\n=== 5. Gerçek örnekler ===\n";
$orn = siparis_test_ornekler();
$fuzzIste = in_array('--fuzz', $argv, true);
if ($orn === null) {
    echo "ATLANDI (SIPARIS_ORNEK_DIR yok)\n";
    if ($fuzzIste) echo "fuzz ATLANDI (örnek yok)\n";
} else {
    $tumOk = fn(array $d): bool => $d['checks'] !== [] && array_filter($d['checks'], fn($c) => !$c['ok']) === [];
    // — set 1 —
    $s = $orn['1'] ?? [];
    ok('set 1: PO/LL/PC bulundu', isset($s['po'], $s['ll'], $s['pc']));
    if (isset($s['po'], $s['ll'], $s['pc'])) {
        $po = siparis_pdf_isle($s['po']); $ll = siparis_pdf_isle($s['ll']); $pc = siparis_pdf_isle($s['pc']);
        ok('set 1 PO: 2 yükleme TC426865.10 / TC426866.10', array_column($po['loadings'], 'order_ref') === ['TC426865.10', 'TC426866.10']);
        ok('set 1 PO: palet 23/6, koli 3680/960, tur 9000166899', array_map(fn($l) => $l['totals']['pallets'], $po['loadings']) === [23, 6]
            && array_map(fn($l) => $l['totals']['quantity'], $po['loadings']) === [3680, 960] && $po['loadings'][0]['tour'] === '9000166899');
        ok('set 1 PO: CW 43.2026, alıcı 1038529, 1 kalem 14 PC', $po['header']['cw'] === 43 && $po['destination']['id'] === '1038529' && count($po['items']) === 1 && $po['items'][0]['pcs_per_box'] === 14);
        ok('set 1 LL: navlun 6100127207, 2 plaka', $ll['header']['transport_no'] === '6100127207' && count($ll['header']['plates']) === 2);
        ok('set 1 PC: 2 satır, fiyat 4.76 (ikinci miras)', count($pc['rows']) === 2 && $pc['rows'][0]['price'] === 4.76 && $pc['rows'][1]['price'] === 4.76 && $pc['rows'][1]['price_inherited'] === true);
        ok('set 1: tüm belge içi denetimler OK', $tumOk($po) && $tumOk($ll) && $tumOk($pc));
        $cc = siparis_pdf_capraz_kontrol([$po, $ll, $pc]);
        ok('set 1 çapraz: 8 denetim, hepsi OK, uyarı yok', count($cc['checks']) === 8 && array_filter($cc['checks'], fn($c) => !$c['ok']) === [] && $cc['warnings'] === []);
    }
    // — set 2 —
    $s = $orn['2'] ?? [];
    ok('set 2: PO/LL/PC bulundu', isset($s['po'], $s['ll'], $s['pc']));
    if (isset($s['po'], $s['ll'], $s['pc'])) {
        $po = siparis_pdf_isle($s['po']); $ll = siparis_pdf_isle($s['ll']); $pc = siparis_pdf_isle($s['pc']);
        ok('set 2 PO: 3 kalem (10/12/14), 2 sipariş', count($po['items']) === 3 && array_column($po['items'], 'pcs_per_box') === [10, 12, 14] && count($po['loadings']) === 2);
        ok('set 2 PO: sayfalar ters dosya sırasında olsa da 4 sayfa okundu', $po['meta']['pages'] === 4 && $po['header']['page_count'] === 4);
        ok('set 2 PC: 7 satır, 2 sold-to', count($pc['rows']) === 7 && count($pc['sold_to_list']) === 2);
        ok('set 2 LL: plaka boş, no_plates bilgisi', $ll['header']['plates'] === [] && in_array('no_plates', array_column($ll['warnings'], 'code'), true) && $ll['header']['transport_no'] === '6100125022');
        ok('set 2: tüm belge içi denetimler OK', $tumOk($po) && $tumOk($ll) && $tumOk($pc));
        $cc = siparis_pdf_capraz_kontrol([$po, $ll, $pc]);
        ok('set 2 çapraz: 6 split_differs uyarısı, denetimler OK', count(array_filter($cc['warnings'], fn($w) => $w['code'] === 'split_differs')) === 6 && array_filter($cc['checks'], fn($c) => !$c['ok']) === []);
    }
    // — set 3 (v374): tek yükleme listesinde İKİ Transport bloğu — her tır kendi navlun/nakliyeci/plakasını alır —
    $s = $orn['3'] ?? [];
    if (!isset($s['ll'])) echo "set 3 ATLANDI (iki tırlı LL örneği yok)\n";
    else {
        $ll = siparis_pdf_isle($s['ll']);
        $byRef = array_column($ll['shipments'], null, 'order_ref');
        ok('set 3 LL: 2 transport bloğu, 2 sevkiyat', count($ll['header']['transports']) === 2 && count($ll['shipments']) === 2);
        ok('set 3 LL: TC426877.10 → 6100128178 · ÖZ LIDERTRANS · 2 plaka', ($byRef['TC426877.10']['transport_no'] ?? null) === '6100128178'
            && $byRef['TC426877.10']['transport_company'] === 'ÖZ LIDERTRANS' && $byRef['TC426877.10']['plates'] === ['33ARP359', '33ARP360']);
        ok('set 3 LL: TC427747.10 → 6100128599 · NILCARGO · plakasız', ($byRef['TC427747.10']['transport_no'] ?? null) === '6100128599'
            && $byRef['TC427747.10']['transport_company'] === 'NILCARGO' && $byRef['TC427747.10']['plates'] === []);
        ok('set 3 LL: etiket satırları satıra yapışmaz (IAN / tur no temiz)', array_unique(array_column($ll['rows'], 'ian')) === ['80646']
            && $byRef['TC426877.10']['tour_id'] === '9000168107' && count($ll['rows']) === 7);
        ok('set 3: tüm belge içi denetimler OK', $tumOk($ll));
        $cc = siparis_pdf_capraz_kontrol([$ll]);
        ok('set 3 çapraz: navlun sevkiyat bazında', ($cc['shipments']['TC426877.10']['transport_no'] ?? null) === '6100128178' && ($cc['shipments']['TC427747.10']['transport_no'] ?? null) === '6100128599');
    }
    // — fuzz: run_v2 --selftest portu — her satır sınırında sayfa bölünür, sonuç bölünmemiş ayrıştırmayla AYNI olmalı —
    $norm = function (array $d): string {
        $keep = array_intersect_key($d, array_flip(['items', 'loadings', 'unloading_locations', 'pallet_types', 'rows', 'shipments', 'totals_by_order_ref', 'checks']));
        array_walk_recursive($keep, function (&$v, $k) { if ($k === 'page') $v = 0; });
        return (string)json_encode($keep);
    };
    $fail = 0; $runs = 0;
    foreach (['1', '2'] as $set) foreach (['po', 'll', 'pc'] as $tur) {
        $f = $orn[$set][$tur] ?? null;
        if ($f === null) continue;
        $r = siparis_pdf_oku($f); $type = siparis_pdf_belge_turu($r['pages']);
        $parse = fn($pages) => match ($type) { 'purchase_order' => siparis_pdf_parse_order($pages), 'price_confirmation' => siparis_pdf_parse_price_confirmation($pages), 'loading_list' => siparis_pdf_parse_loading_list($pages), default => [] };
        $base = $norm($parse($r['pages']));
        foreach ($r['pages'] as $pn => $segs) {
            $lines = sipdf_lines($segs); $ys = array_column($lines, 'y');
            $hz = []; $inH = false;     // başlık kelime yığınları gerçek düzende bölünmez → içinde kesme yok
            foreach ($lines as $li => $ln) { $tt = sipdf_ltext($ln); if (preg_match('/^Order ref\./', $tt)) $inH = true; elseif ($inH && preg_match('/^[A-Z]{1,3}\d{5,}\.\d+/', $tt)) $inH = false; $hz[$li] = $inH; }
            foreach ($lines as $li => $ln) {
                if ($li < 2 || $li >= count($lines) - 1 || !empty($hz[$li])) continue;
                $cut = ($ys[$li] + $ys[$li - 1]) / 2;
                $upper = array_values(array_filter($segs, fn($s) => $s['y'] > $cut)); $lower = array_values(array_filter($segs, fn($s) => $s['y'] <= $cut));
                if (!$upper || !$lower) continue;
                $hdr = [['x' => 31, 'y' => 518.6, 'size' => 8, 'text' => 'Agency'], ['x' => 31, 'y' => 506.6, 'size' => 8, 'text' => 'OGL Food Trade'], ['x' => 648, 'y' => 511.5, 'size' => 8, 'text' => 'Date'],
                        ['x' => 697, 'y' => 511.5, 'size' => 8, 'text' => 'No. supplier'], ['x' => 761, 'y' => 511.5, 'size' => 8, 'text' => 'Page'], ['x' => 648, 'y' => 499.5, 'size' => 8, 'text' => '02.10.2026'],
                        ['x' => 697, 'y' => 499.5, 'size' => 8, 'text' => '1102720'], ['x' => 761, 'y' => 499.5, 'size' => 8, 'text' => '9 / 9']];
                $shift = max(array_column($lower, 'y')) - 470; $lower2 = array_map(function ($s) use ($shift) { $s['y'] -= $shift; return $s; }, $lower);
                $variants = ['plain' => array_merge($hdr, $lower2)];
                if ($type === 'purchase_order') {
                    $lab = null; $first = null;
                    for ($k = $li - 1; $k >= 0; $k--) if (preg_match('/^Order ref\./', sipdf_ltext($lines[$k]))) { $lab = $k; break; }
                    if ($lab !== null) for ($k = $lab + 1; $k < count($lines); $k++) if (preg_match('/^[A-Z]{1,3}\d{5,}\.\d+/', sipdf_ltext($lines[$k]))) { $first = $k; break; }
                    if ($lab !== null && $first !== null && $li > $first) {
                        $hl = []; foreach (array_slice($lines, $lab, $first - $lab) as $hln) { foreach ($hln['segs'] as $sg) { $sg['y'] = 470 - (($lines[$lab]['y'] - $hln['y'])); $hl[] = $sg; } }
                        $low3 = array_map(function ($sg) { $sg['y'] -= 70; return $sg; }, $lower2);
                        $variants['repeat_header'] = array_merge($hdr, $hl, $low3);
                    }
                }
                foreach ($variants as $vn => $contPage) {
                    $np = []; foreach ($r['pages'] as $k => $sg) { if ($k === $pn) { $np[] = $upper; $np[] = $contPage; } else $np[] = $sg; }
                    $pages2 = []; foreach ($np as $i => $sg) $pages2[$i + 1] = $sg;
                    $runs++;
                    if ($norm($parse($pages2)) !== $base) { $fail++; if ($fail <= 5) echo "   bölme farkı [$vn] set $set $tur sayfa $pn satır $li\n"; }
                }
            }
        }
    }
    echo "   fuzz: $runs çalıştırma, $fail hata\n";
    ok('fuzz: sayfa bölme denemeleri (≥ 250) 0 hata', $runs >= 250 && $fail === 0, "runs=$runs fail=$fail");
}

echo "=== v4. PO sayfa devamı (siparişsiz blok) ===\n";
// Fixture: gerçek bir PO'nun kişisel veriden arındırılmış metin parçaları. TC…924.10 tırı iki kalem taşır (14 PC: 10 palet, 16 PC: 15 palet);
// 16 PC satırı 2. sayfada, "Loading date" başlığı tekrarlanarak ve Order ref hücresi BOŞ basılmıştır.
$__fx = json_decode((string)file_get_contents($ROOT . '/scripts/fixtures/siparis/po_sayfa_devami_pages.json'), true)['pages'];
$__pg = function (array $fx): array { $o = []; foreach ($fx as $pn => $sg) $o[(int)$pn] = $sg; return $o; };
$__d = siparis_pdf_parse_order($__pg($__fx));
$__tr = null; foreach ($__d['loadings'] as $o) if ($o['order_ref'] === 'TC432924.10') $__tr = $o;
ok('v4 devam: 3 sipariş (TC…924.10 ikiye bölünmedi, orphan yok)', count($__d['loadings']) === 3 && $__tr !== null && !$__d['orphan_items']);
ok('v4 devam: TC…924.10 iki kalem — 14 PC 10 palet/1.600 + 16 PC 15 palet/2.400 (toplam 25 / 4.000)',
    $__tr && count($__tr['items']) === 2 && $__tr['items'][0]['pcs_per_box'] === 14 && $__tr['items'][0]['pallets'] === 10 && $__tr['items'][1]['pcs_per_box'] === 16
    && $__tr['items'][1]['pallets'] === 15 && $__tr['items'][1]['quantity'] === 2400 && $__tr['totals']['pallets'] === 25 && $__tr['totals']['quantity'] === 4000);
ok('v4 devam: 16 PC kalemi özet kalemiyle eşleşti ve boşaltma yeri (LAPOVO) okundu', $__tr && $__tr['items'][1]['summary_item_no'] === 2 && ($__tr['items'][1]['locations'][0]['name'] ?? '') === 'LIDL LAPOVO' && $__tr['items'][1]['locations'][0]['pallets'] === 15);
ok('v4 devam: tüm tutarlılık denetimleri geçer, hata/uyarı yok (yalnız bilgi: continuation_merged)',
    !array_filter($__d['checks'], fn($c) => !$c['ok']) && array_column($__d['warnings'], 'code') === ['continuation_merged'] && $__d['warnings'][0]['level'] === 'info');
ok('v4 devam: ayrıştırıcı sürümü v4', SIPARIS_PDF_SURUM === 'v4');

// (b) devam bloğu BAŞKA tarihli → bağlanamaz: sessizce atılmaz — orphan_items + hata, sum_blocks denetimleri hata seviyesinde
$__f2 = $__fx;
foreach ($__f2[4] as &$x) if ($x['text'] === '29.10.2026') $x['text'] = '30.10.2026';          // sayfa 4'teki tekrarlanan "Loading date" başlığı
unset($x);
$__d2 = siparis_pdf_parse_order($__pg($__f2));
$__kod = array_column($__d2['warnings'], 'level', 'code');
ok('v4 yetim: farklı tarihli siparişsiz satır → orphan_items (1 kalem, 16 PC, 15 palet) + orphan_rows HATA', count($__d2['orphan_items']) === 1 && ($__d2['orphan_items'][0]['item']['pallets'] ?? null) === 15 && ($__kod['orphan_rows'] ?? '') === 'error', json_encode($__kod));
ok('v4 yetim: sum_blocks_pallets / sum_blocks_quantity başarısız ve HATA seviyesinde (diğer denetimler uyarı kalır)', ($__kod['check_sum_blocks_pallets'] ?? '') === 'error' && ($__kod['check_sum_blocks_quantity'] ?? '') === 'error', json_encode($__kod));
ok('v4 yetim: TC…924.10 yalnız 14 PC ile kalır (yanlış sipariş uydurulmaz)', (function () use ($__d2) { foreach ($__d2['loadings'] as $o) if ($o['order_ref'] === 'TC432924.10') return count($o['items']) === 1 && $o['totals']['pallets'] === 10; return false; })());

// (c) aynı tarihte İKİ gerçek tır: devam satırına sipariş no yazılırsa birleştirilmez
$__f3 = $__fx; $__refX = null; $__y = null;
foreach ($__f3[3] as $x) if ($x['text'] === 'TC432924.10') $__refX = $x['x'];                    // Order ref sütununun x'i (1. sayfadaki sipariş no)
foreach ($__f3[4] as $x) if (preg_match('/^80646, Pomegranate 16 PC/', $x['text'])) $__y = $x['y'];   // devam satırının y'si
$__f3[4][] = ['x' => $__refX, 'y' => $__y, 'size' => 6, 'text' => 'TC999999.10'];
$__d3 = siparis_pdf_parse_order($__pg($__f3));
$__r3 = array_column($__d3['loadings'], 'order_ref');
ok('v4 iki tır: devam satırı kendi sipariş no ile gelirse AYRI sipariş olur, birleştirilmez', in_array('TC999999.10', $__r3, true) && in_array('TC432924.10', $__r3, true) && !$__d3['orphan_items'] && !in_array('continuation_merged', array_column($__d3['warnings'], 'code'), true), json_encode($__r3));

echo "\n=== v490. payment_advice (OGL ödeme avizosu; SENTETİK fixture) ===\n";
// Fixture tamamen uydurmadır; gerçek avizonun düzenini taklit eder: iki sütunlu sayfa 1 + tek sütunlu sayfa 2, Zahlung tutarı akışta Rechnung'dan ÖNCE,
// "Zahlbetrag total" satırında tutar "EUR"dan önce, başlık cümlesinde çift boşluk, sağa dayalı tutarlar, '@#'.
$__av = json_decode((string)file_get_contents($ROOT . '/scripts/fixtures/siparis/odeme_avizosu_pages.json'), true)['pages'];
$__avp = function (callable $mut = null) use ($__av): array { $p = []; foreach ($__av as $n => $sg) $p[(int)$n] = $sg; if ($mut) $p = $mut($p); return $p; };
$__avs = function (array $p, callable $f): array { foreach ($p as $n => $sg) $p[$n] = array_values(array_filter($sg, $f)); return $p; };
$__avd = siparis_pdf_parse_payment_advice($__avp());
ok('v490 sınıflama: sentetik avizo → payment_advice', siparis_pdf_belge_turu($__avp()) === 'payment_advice');
$__po = json_decode((string)file_get_contents($ROOT . '/scripts/fixtures/siparis/po_sayfa_devami_pages.json'), true)['pages'];
$__po2 = []; foreach ($__po as $n => $sg) $__po2[(int)$n] = $sg;
ok('v490 sınıflama: PO fixture hâlâ purchase_order, boş girdi unknown', siparis_pdf_belge_turu($__po2) === 'purchase_order' && siparis_pdf_belge_turu([]) === 'unknown');
ok('v490 taban: ok, 15 satır, ΣZahlung 553775, ΣRechnung 556275, başlık 553775 EUR, mektup tarihi 2026-10-28, gönderen/alıcı',
    $__avd['ok'] === true && count($__avd['rows']) === 15 && $__avd['sums']['zahlung_kurus'] === 553775 && $__avd['sums']['rechnung_kurus'] === 556275
    && $__avd['header']['amount_kurus'] === 553775 && $__avd['header']['currency'] === 'EUR' && $__avd['header']['date'] === '2026-10-28'
    && $__avd['header']['sender_ok'] && $__avd['header']['recipient_ok'] && $__avd['total']['amount_kurus'] === 553775, json_encode($__avd['hata'] ?? null));
$__sf = array_count_values(array_column(array_filter($__avd['rows'], fn($r) => $r['page'] === 1), 'page'));
ok('v490 taban: sayfa 1 → 12, sayfa 2 → 3 satır', ($__sf[1] ?? 0) === 12 && count(array_filter($__avd['rows'], fn($r) => $r['page'] === 2)) === 3);
$__r8 = null; foreach ($__avd['rows'] as $r) if ($r['no'] === '2026-9002RO') $__r8 = $r;
ok('v490 tuzak: Zahlung akışta ÖNCE gelse de satır 8 → Rechnung 0, Zahlung -2000 (konumla ayrılır)', $__r8 && $__r8['rechnung_kurus'] === 0 && $__r8['zahlung_kurus'] === -2000);
ok('v490 taban: op_date ISO, OP-Nummer ham', $__avd['rows'][0]['op_date'] === '2026-10-02' && $__avd['rows'][0]['no'] === '26-IHR2026000009001');
ok('v490 SIPARIS_PDF_SURUM değişmedi (v4)', SIPARIS_PDF_SURUM === 'v4');

$__kod = fn(array $p) => (function () use ($p) { $d = siparis_pdf_parse_payment_advice($p); return [$d['ok'], $d['hata_kodu'], $d['hata']]; })();
// M1 — tek Zahlung 1 kuruş farklı
$__m = $__kod($__avp(function ($p) { foreach ($p[1] as &$s) if ($s['text'] === '1.000,00' && $s['x'] > 240) { $s['text'] = '1.000,01'; break; } return $p; }));
ok('v490 M1: bir Zahlung +1 kuruş → aviz_toplam ve mesajda iki toplam', $__m[1] === 'aviz_toplam' && str_contains((string)$__m[2], '5.537,76') && str_contains((string)$__m[2], '5.537,75'), json_encode($__m));
// M2 — Rechnung/Zahlung tutarları her satırda yer değiştirmiş
$__m = $__kod($__avp(function ($p) {
    foreach ($p as $n => $sg) {
        $g = [];
        foreach ($sg as $i => $s) if (preg_match(SIPDF_AMOUNT_RE, $s['text']) && $s['text'] !== '5.537,75') $g[(int)round($s['y'] * 100) . '|' . ($s['x'] < 311 ? 'L' : 'R')][] = $i;
        foreach ($g as $idx) if (count($idx) === 2) { $t = $p[$n][$idx[0]]['text']; $p[$n][$idx[0]]['text'] = $p[$n][$idx[1]]['text']; $p[$n][$idx[1]]['text'] = $t; }
    }
    return $p; }));
ok('v490 M2: Rechnung/Zahlung yer değişmiş → aviz_sutun_ters (sessiz takas yok)', $__m[1] === 'aviz_sutun_ters', json_encode($__m));
$__m = $__kod($__avs($__avp(), fn($s) => trim($s['text']) !== 'Zahlung'));
ok('v490 M3: başlıktan Zahlung etiketi → aviz_baslik', $__m[1] === 'aviz_baslik', json_encode($__m));
$__m = $__kod($__avp(function ($p) { foreach ($p as $n => $sg) foreach ($sg as $i => $s) $p[$n][$i]['text'] = str_replace('5.537,75  zum', '5.537,76  zum', $s['text']); return $p; }));
ok('v490 M4: iki sayfada da başlık tutarı 5.537,76 → aviz_baslik_toplam', $__m[1] === 'aviz_baslik_toplam', json_encode($__m));
$__m = $__kod($__avp(function ($p) { foreach ($p as $n => $sg) foreach ($sg as $i => $s) $p[$n][$i]['text'] = str_replace('über  EUR', 'über  USD', $s['text']); return $p; }));
ok('v490 M5: başlık USD, toplam EUR → aviz_para', $__m[1] === 'aviz_para', json_encode($__m));
$__m = $__kod($__avs($__avp(), fn($s) => !($s['y'] > 355 && $s['y'] < 356)));
ok('v490 M6: «Zahlbetrag total» satırı yok → aviz_toplam_yok', $__m[1] === 'aviz_toplam_yok', json_encode($__m));
$__m = $__kod($__avp(function ($p) { $p[1][] = ['x' => 524.4, 'y' => 600.0, 'size' => 9, 'text' => '29.10.2026']; return $p; }));
$__m2 = $__kod($__avs($__avp(), fn($s) => $s['text'] !== '28.10.2026'));
ok('v490 M7: iki farklı mektup tarihi / hiç yok → aviz_tarih', $__m[1] === 'aviz_tarih' && $__m2[1] === 'aviz_tarih', json_encode([$__m, $__m2]));
$__m = $__kod($__avp(function ($p) { $p[1][] = ['x' => 29.76, 'y' => 300.0, 'size' => 9, 'text' => 'anlamsiz metin']; return $p; }));
ok('v490 M8: tabloda anlamsız metin → aviz_satir', $__m[1] === 'aviz_satir', json_encode($__m));
$__m = $__kod($__avp(function ($p) { $p[1][] = ['x' => 240.0, 'y' => 488.4, 'size' => 9, 'text' => '1,00']; return $p; }));
ok('v490 M9: üç tutarlı satır → aviz_satir', $__m[1] === 'aviz_satir', json_encode($__m));
$__m = $__kod($__avp(function ($p) { foreach ($p[2] as &$s) $s['text'] = str_replace('5.537,75', '5.537,77', $s['text']); return $p; }));
ok('v490 M10: sayfa 2 başlık cümlesi farklı → aviz_cok_blok', $__m[1] === 'aviz_cok_blok', json_encode($__m));
$__m = $__kod($__avp(function ($p) { $p[1] = array_values(array_filter($p[1], fn($s) => $s['y'] > 500 || $s['y'] < 34)); $p[2] = array_values(array_filter($p[2], fn($s) => $s['y'] > 700 || $s['y'] < 400)); return $p; }));
ok('v490 M11: tüm veri satırları silinmiş → aviz_bos', $__m[1] === 'aviz_bos', json_encode($__m));
$__m = $__kod($__avp(function ($p) { $p[1][] = ['x' => 29.77, 'y' => 300.0, 'size' => 9, 'text' => 'Zahlbetrag total']; $p[1][] = ['x' => 260.0, 'y' => 300.0, 'size' => 9, 'text' => '5.537,75']; $p[1][] = ['x' => 218.65, 'y' => 300.0, 'size' => 9, 'text' => 'EUR']; return $p; }));
ok('v490 M12: «Zahlbetrag total» iki kez → aviz_cok_blok', $__m[1] === 'aviz_cok_blok', json_encode($__m));
$__m = $__kod($__avp(function ($p) { foreach ($p[1] as &$s) if ($s['text'] === '1.000,00' && $s['x'] > 240) { $s['text'] = '1,000.00'; break; } return $p; }));
ok('v490 M13: İngilizce biçim 1,000.00 → aviz_satir', $__m[1] === 'aviz_satir', json_encode($__m));
$__m = $__kod($__avp(function ($p) { $p[1][] = ['x' => 29.76, 'y' => 100.0, 'size' => 9, 'text' => '@#']; return $p; }));
ok('v490: yalnız "@#" tablo bölgesinde yok sayılır', $__m[0] === true, json_encode($__m));
ok('v490 sipdf_de_kurus: geçerli biçimler', sipdf_de_kurus('1.234,56') === 123456 && sipdf_de_kurus('-162,12') === -16212 && sipdf_de_kurus('0,00') === 0 && sipdf_de_kurus('62377,44') === 6237744);
ok('v490 sipdf_de_kurus: geçersiz biçimler null', sipdf_de_kurus('1234.56') === null && sipdf_de_kurus('1.234,5') === null && sipdf_de_kurus('1,234,56') === null && sipdf_de_kurus('12.34,56') === null && sipdf_de_kurus('') === null);
ok('v490 sipdf_de_goster tersi', sipdf_de_goster(6237744) === '62.377,44' && sipdf_de_goster(-5) === '-0,05' && sipdf_de_goster(0) === '0,00');
$__ornek = (string)getenv('ODEME_ORNEK_PDF');
if ($__ornek !== '' && is_file($__ornek)) {
    $__g = siparis_pdf_isle($__ornek); $__gr = siparis_pdf_oku($__ornek);
    $__tur = array_count_values(array_map(function ($r) { return preg_match('~^(?:\d{2}[-/])?IHR\d{13}$~', $r['no']) ? 'ana' : (preg_match('~^(?:\d{2}[-/])?IHR\d{13}D~', $r['no']) ? 'duz' : 'diger'); }, $__g['rows'] ?? []));
    ok('v490 GERÇEK PDF: payment_advice, ok, 80 satır, ΣZahlung 6237744 = başlık = toplam, tarih 2026-10-27',
        siparis_pdf_belge_turu($__gr['pages']) === 'payment_advice' && ($__g['ok'] ?? false) === true && count($__g['rows']) === 80 && $__g['sums']['zahlung_kurus'] === 6237744
        && $__g['header']['amount_kurus'] === 6237744 && $__g['total']['amount_kurus'] === 6237744 && $__g['header']['date'] === '2026-10-27', json_encode($__g['hata'] ?? null));
    ok('v490 GERÇEK PDF: ΣRechnung 6512784', $__g['sums']['rechnung_kurus'] === 6512784);
} else echo "ATLANDI (ODEME_ORNEK_PDF yok)\n";

echo "\n=== v492. debit_note (OGL Invoice-Debit Note; SENTETİK fixture) ===\n";
$__db = json_decode((string)file_get_contents($ROOT . '/scripts/fixtures/siparis/debit_note_pages.json'), true)['pages'];
$__dbp = []; foreach ($__db as $n => $sg) $__dbp[(int)$n] = $sg;
ok('v492 sınıflama: sentetik debit → debit_note (payment_advice / unknown değil)', siparis_pdf_belge_turu($__dbp) === 'debit_note');
$__dd = siparis_pdf_parse_debit_note($__dbp);
ok('v492 3 sayfa → 3 belge (2029-100001/2/3), Σ tutar 191,01; sayfa_hata 0', $__dd['ok'] === true && array_column($__dd['notes'], 'no') === ['2029-100001', '2029-100002', '2029-100003'] && array_sum(array_column($__dd['notes'], 'amount_kurus')) === 19101 && $__dd['sayfa_hata'] === 0);
$__ddt = $__dbp; foreach ($__ddt[1] as &$__s) if ($__s['text'] === 'Amount EUR 74,88') $__s['text'] = 'Amount EUR 74,89'; unset($__s);
$__ddr = siparis_pdf_parse_debit_note($__ddt);
ok('v492 kalem toplamı ≠ belge toplamı → o sayfa reddedilir (ödeme/kayıt yok), diğerleri alınır', count($__ddr['notes']) === 2 && $__ddr['sayfa_hata'] === 1 && str_contains($__ddr['sayfa_hatalar'][0]['hata'], 'tutmuyor'));
ok('v492 sipdf_debit_fiyat: 4,58 → 45800, 4,6 → 46000, geçersiz → null', sipdf_debit_fiyat('4,58') === 45800 && sipdf_debit_fiyat('4,6') === 46000 && sipdf_debit_fiyat('abc') === null && sipdf_debit_fiyat('4.58') === null);
$__dornek = (string)getenv('DEBIT_ORNEK_PDF');
if ($__dornek !== '' && is_file($__dornek)) {
    $__dg = siparis_pdf_isle($__dornek);
    ok('v492 GERÇEK PDF: debit_note, ok, sayfa_hata 0, her belge EUR + fatura no + tutar > 0', ($__dg['doc_type'] ?? '') === 'debit_note' && ($__dg['ok'] ?? false) === true && ($__dg['sayfa_hata'] ?? 1) === 0 && count($__dg['notes']) >= 1
        && !array_filter($__dg['notes'], fn($n) => $n['pb'] !== 'EUR' || $n['amount_kurus'] <= 0 || !preg_match('/^IHR\d{13}D[IVX]+$/', $n['invoice_no'])), json_encode($__dg['sayfa_hatalar'] ?? null));
} else echo "ATLANDI (DEBIT_ORNEK_PDF yok)\n";

siparis_test_bitir();
