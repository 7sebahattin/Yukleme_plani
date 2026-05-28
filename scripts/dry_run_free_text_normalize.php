<?php
// =============================================================
// scripts/dry_run_free_text_normalize.php — Sprint 14
// Serbest metin alanları normalize_text_v2 dry-run analizi.
// CLI ONLY. Hiçbir UPDATE/DELETE çalıştırmaz.
// =============================================================
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Hata: CLI'dan çalıştırın.\n");
}

// ── Credentials — db.php'yi require etmeden oku ─────────────
$_src = file_get_contents(dirname(__DIR__) . '/config/db.php');
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $_c) {
    define($_c, preg_match("/const $_c\s*=\s*'([^']*)'/", $_src, $_m) ? $_m[1] : '');
}
unset($_src, $_c, $_m);

// ── normalize_text_v2 — helpers.php'ye bağımlılık yok ───────
function normalize_text_v2(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    $v = preg_replace('/\s+/u', ' ', $v);
    $v = str_replace(['I', 'İ'], ['ı', 'i'], $v);
    $v = mb_strtolower($v, 'UTF-8');
    $words = explode(' ', $v);
    $words = array_map(function (string $w): string {
        if ($w === '') return '';
        $first = mb_substr($w, 0, 1, 'UTF-8');
        $rest  = mb_substr($w, 1, null, 'UTF-8');
        if ($first === 'i') return 'İ' . $rest;
        if ($first === 'ı') return 'I' . $rest;
        return mb_strtoupper($first, 'UTF-8') . $rest;
    }, $words);
    return implode(' ', $words);
}

function has_u0307(string $s): bool {
    return strpos(bin2hex($s), 'cc87') !== false;
}

// Sadece büyük/küçük harf + boşluk farkı mı?
function is_case_only(string $old, string $new): bool {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $old)), 'UTF-8')
        === mb_strtolower(trim(preg_replace('/\s+/u', ' ', $new)), 'UTF-8');
}

// Risk seviyesi
function risk_level(string $old, string $new): string {
    if (has_u0307($old))   return 'YÜKSEK';   // U+0307 içeriyor
    if (!is_case_only($old, $new)) return 'ORTA'; // anlam değişebilir
    return 'DÜŞÜK';                              // sadece harf/boşluk
}

// ── PDO ─────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS !== '' ? DB_PASS : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB bağlantı hatası: {$e->getMessage()}\n");
    exit(1);
}

// ── Taranacak alan tanımları ─────────────────────────────────
// [tablo, id_kolonu, değer_kolonu, stok_bağlantısı_açıklaması]
$targets = [
    ['loading_records', 'id', 'firma',      'loading_records filtresi'],
    ['loading_records', 'id', 'urun',       'loading_records/stok filtresi'],
    ['loading_pallets', 'id', 'urun_cinsi', 'pallet_materials/stok urun_cinsi'],
    ['loading_pallets', 'id', 'depo',       'stok depo filtresi'],
    ['kantar_fisleri',  'id', 'firma_adi',  'kantar raporu firma'],
    ['kantar_fisleri',  'id', 'malin_cinsi','kantar raporu ürün'],
    ['kantar_fisleri',  'id', 'depo',       'kantar raporu depo'],
    ['kantar_gruplar',  'id', 'grup_adi',   'kantar grup adı'],
];

// ── Tarama ──────────────────────────────────────────────────
$C = [
    'ok'   => "\033[32m",
    'fail' => "\033[31m",
    'warn' => "\033[33m",
    'bold' => "\033[1m",
    'off'  => "\033[0m",
];

$ts      = date('Ymd');
$csv_path = __DIR__ . "/free_text_normalize_dry_run_{$ts}.csv";
$rows    = [];   // tüm değişecek kayıtlar

$summary = [];   // tablo → [total, will_change, u0307, case_only, medium_risk, high_risk]

echo "{$C['bold']}==========================================================\n";
echo " Sprint 14 — Free-Text Normalize Dry-Run\n";
echo "=========================================================={$C['off']}\n";
echo "  Tarih   : " . date('Y-m-d H:i:s') . "\n";
echo "  DB      : " . DB_NAME . "\n";
echo "  Çıktı   : $csv_path\n\n";

foreach ($targets as [$table, $id_col, $val_col, $stok_note]) {
    // Tablo var mı?
    try {
        $check = $pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
    } catch (PDOException $e) { $check = false; }
    if (!$check) {
        echo "  {$C['warn']}⚠  $table tablosu bulunamadı, atlanıyor.{$C['off']}\n";
        continue;
    }
    // Kolon var mı?
    try {
        $col_check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$val_col'")->fetchColumn();
    } catch (PDOException $e) { $col_check = false; }
    if (!$col_check) {
        echo "  {$C['warn']}⚠  $table.$val_col kolonu bulunamadı, atlanıyor.{$C['off']}\n";
        continue;
    }

    $st = $pdo->query("SELECT `$id_col`, `$val_col` FROM `$table` WHERE `$val_col` != '' ORDER BY `$id_col`");
    $total = 0; $will_change = 0; $u307 = 0; $case_only_cnt = 0; $mid = 0; $high = 0;

    foreach ($st->fetchAll() as $r) {
        $id  = $r[$id_col];
        $old = $r[$val_col];
        $new = normalize_text_v2($old);
        $total++;

        $has307  = has_u0307($old);
        $changed = ($old !== $new);
        $risk    = $changed ? risk_level($old, $new) : '';
        $case_ok = $changed && is_case_only($old, $new);

        if ($has307) $u307++;
        if ($changed) {
            $will_change++;
            if ($risk === 'YÜKSEK') $high++;
            elseif ($risk === 'ORTA') $mid++;
            if ($case_ok) $case_only_cnt++;

            $rows[] = [
                'tablo'        => $table,
                'kolon'        => $val_col,
                'id'           => $id,
                'mevcut'       => $old,
                'v2_sonucu'    => $new,
                'degisecek'    => 'EVET',
                'u0307'        => $has307 ? 'EVET' : 'HAYIR',
                'risk'         => $risk,
                'stok_etkisi'  => $stok_note,
                'sadece_harf'  => $case_ok ? 'EVET' : 'HAYIR',
            ];
        }
    }

    $summary["{$table}.{$val_col}"] = [
        'tablo' => $table, 'kolon' => $val_col,
        'toplam' => $total, 'degisecek' => $will_change,
        'u307' => $u307, 'case_only' => $case_only_cnt,
        'mid' => $mid, 'high' => $high,
        'stok_note' => $stok_note,
    ];

    $color = $will_change > 0 ? $C['warn'] : $C['ok'];
    printf("  %s%-35s toplam: %3d | değişecek: %3d | u0307: %d | risk(O/Y): %d/%d%s\n",
        $color, "$table.$val_col", $total, $will_change, $u307, $mid, $high, $C['off']);
}

// ── CSV üret ────────────────────────────────────────────────
$fp = fopen($csv_path, 'w');
fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
fputcsv($fp, ['tablo','kolon','id','mevcut_deger','v2_sonucu',
               'degisecek','u0307','risk','sadece_harf','stok_etkisi'], ';');
foreach ($rows as $row) {
    fputcsv($fp, [
        $row['tablo'], $row['kolon'], $row['id'],
        $row['mevcut'], $row['v2_sonucu'],
        $row['degisecek'], $row['u0307'],
        $row['risk'], $row['sadece_harf'], $row['stok_etkisi'],
    ], ';');
}
fclose($fp);

// ── Özet ────────────────────────────────────────────────────
$total_change   = array_sum(array_column($summary, 'degisecek'));
$total_u307     = array_sum(array_column($summary, 'u307'));
$total_case     = array_sum(array_column($summary, 'case_only'));
$total_mid      = array_sum(array_column($summary, 'mid'));
$total_high     = array_sum(array_column($summary, 'high'));
$total_scanned  = array_sum(array_column($summary, 'toplam'));

echo "\n{$C['bold']}── Özet ──────────────────────────────────────────────────{$C['off']}\n";
printf("  Toplam taranan değer      : %d\n", $total_scanned);
printf("  Değişecek (toplam)        : %d\n", $total_change);
printf("  Sadece harf/boşluk farkı  : %d\n", $total_case);
printf("  Anlam değişebilir (ORTA)  : %d\n", $total_mid);
printf("  U+0307 içeren (YÜKSEK)    : %d\n", $total_high);
printf("  U+0307 bulunan kayıt      : %d\n", $total_u307);

echo "\n{$C['bold']}── Kolon bazlı özet ──────────────────────────────────────{$C['off']}\n";
printf("  %-35s %6s %8s %6s %6s\n", 'Kolon', 'Toplam', 'Değişir', 'Orta', 'Yüksek');
foreach ($summary as $key => $s) {
    $color = $s['degisecek'] > 0 ? $C['warn'] : $C['ok'];
    printf("  %s%-35s %6d %8d %6d %6d%s\n",
        $color, $key, $s['toplam'], $s['degisecek'], $s['mid'], $s['high'], $C['off']);
}

// ORTA ve YÜKSEK riskli kayıtları listele
$risk_rows = array_filter($rows, fn($r) => in_array($r['risk'], ['ORTA', 'YÜKSEK']));
if (!empty($risk_rows)) {
    echo "\n{$C['bold']}── Riskli Kayıtlar (ORTA + YÜKSEK) ──────────────────────{$C['off']}\n";
    foreach ($risk_rows as $r) {
        $c = $r['risk'] === 'YÜKSEK' ? $C['fail'] : $C['warn'];
        printf("  %s[%s] %s.%s id=%s%s\n", $c, $r['risk'], $r['tablo'], $r['kolon'], $r['id'], $C['off']);
        printf("    Mevcut : %s\n", $r['mevcut']);
        printf("    V2     : %s\n", $r['v2_sonucu']);
        if ($r['u0307'] === 'EVET') echo "    {$C['fail']}⚠ U+0307 içeriyor{$C['off']}\n";
    }
}

echo "\n{$C['bold']}── CSV Dosyası ───────────────────────────────────────────{$C['off']}\n";
printf("  %s (%s KB)\n", $csv_path, round(filesize($csv_path) / 1024, 1));

echo "\n{$C['bold']}── Güvenlik Değerlendirmesi ──────────────────────────────{$C['off']}\n";
if ($total_high === 0 && $total_mid === 0) {
    echo "  {$C['ok']}✓ Tümü düşük risk — güvenli normalize edilebilir.{$C['off']}\n";
} elseif ($total_high > 0) {
    echo "  {$C['fail']}✗ U+0307 içeren kayıtlar var — önce incelemeli.{$C['off']}\n";
} else {
    echo "  {$C['warn']}⚠  Orta riskli kayıtlar var — inceleme önerilir.{$C['off']}\n";
}
echo "\n";
