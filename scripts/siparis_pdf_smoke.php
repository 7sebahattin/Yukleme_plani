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
ok('SIPARIS_PDF_SURUM = v2', SIPARIS_PDF_SURUM === 'v2');
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

siparis_test_bitir();
