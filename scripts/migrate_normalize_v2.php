<?php
declare(strict_types=1);
// ================================================================
// scripts/migrate_normalize_v2.php
// normalize_text_v2 Geçiş Dry-Run Analizi
//
// CLI:  php scripts/migrate_normalize_v2.php --dry-run
//       php scripts/migrate_normalize_v2.php --dry-run --output-dir=/tmp
//
// Web:  Geliştirici görünümü. Üretimde .htaccess bloklar.
//       ?csv=dry_run   → normalize_dry_run.csv indir
//       ?csv=merge_plan → merge_plan.csv indir
//
// KURAL: UPDATE / DELETE / TRANSACTION çalıştırmaz.
//        Sadece analiz ve rapor.
// ================================================================

$is_cli  = (PHP_SAPI === 'cli');
$out_dir = __DIR__;

// ── CLI: --dry-run zorunlu ──────────────────────────────────────
if ($is_cli) {
    $opts = getopt('', ['dry-run', 'output-dir:']);
    if (!isset($opts['dry-run'])) {
        fwrite(STDERR, "\n  KULLANIM: php " . basename(__FILE__) . " --dry-run [--output-dir=DIR]\n\n");
        fwrite(STDERR, "    --dry-run          Zorunlu. Hiçbir DB değişikliği yapılmaz.\n");
        fwrite(STDERR, "    --output-dir=DIR   CSV dosyaları için dizin (varsayılan: " . __DIR__ . ")\n\n");
        exit(1);
    }
    if (isset($opts['output-dir'])) {
        $out_dir = rtrim((string)$opts['output-dir'], '/\\');
    }
}

require_once __DIR__ . '/../config/db.php';
// db.php → helpers.php dahil eder → normalize_text() ve normalize_text_v2() hazır

$pdo    = db();
$ts     = date('Ymd_His');
$run_at = date('Y-m-d H:i:s');

// ── Yardımcılar ─────────────────────────────────────────────────

function dr_tbl(PDO $p, string $t): bool {
    try { $p->query("SELECT 1 FROM `$t` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

function dr_col(PDO $p, string $t, string $c): bool {
    try { $p->query("SELECT `$c` FROM `$t` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

/** Unicode risk bayrakları */
function dr_uc(string $s): array {
    $f = [];
    if (preg_match('/\x{0307}/u',          $s)) $f[] = 'U+0307(birleştirici-nokta)';
    if (preg_match('/\x{200B}/u',          $s)) $f[] = 'U+200B(ZWSP)';
    if (preg_match('/[\x{200C}\x{200D}]/u',$s)) $f[] = 'U+200C/D(ZWJ)';
    if (preg_match('/\x{FEFF}/u',          $s)) $f[] = 'U+FEFF(BOM)';
    return $f;
}

/**
 * Tek bir kolonu tarar; her satır için v1/v2 karşılaştırması döner.
 * $extra_col = ek meta kolon (material_definitions için 'type')
 */
function dr_scan(PDO $p, string $tbl, string $id_col, string $col, ?string $extra_col = null): array {
    $sel  = "`$id_col`, `$col`" . ($extra_col ? ", `$extra_col`" : '');
    $rows = $p->query("SELECT $sel FROM `$tbl` ORDER BY `$id_col`")->fetchAll();
    $out  = [];
    foreach ($rows as $row) {
        $cur = (string)($row[$col] ?? '');
        if ($cur === '') continue;
        $v1     = normalize_text($cur);
        $v2     = normalize_text_v2($cur);
        $uf_c   = dr_uc($cur);
        $uf_v1  = dr_uc($v1);
        $uf_all = array_values(array_unique(array_merge($uf_c, $uf_v1)));
        $out[]  = [
            'table'        => $tbl,
            'id'           => $row[$id_col],
            'column'       => $col,
            'extra'        => $extra_col ? (string)($row[$extra_col] ?? '') : '',
            'current'      => $cur,
            'v1'           => $v1,
            'v2'           => $v2,
            'will_change'  => ($v2 !== $cur),
            'u0307'        => !empty($uf_c) || !empty($uf_v1),
            'flags'        => implode(', ', $uf_all),
            'creates_merge'=> false,
        ];
    }
    return $out;
}

/**
 * material_definitions kaydının FK kullanım sayıları.
 * Hangi tablolarda bu id referanslanıyor?
 */
function dr_fk(PDO $p, int $id, string $type): array {
    static $mat_types = [
        'sapka','kosebent','serit','casus','kasa_etiketi','minti',
        'kenar_kartonu','taban_kagidi','sale','viyol','kose_karton',
        'kraft_kagit','file','diger',
    ];
    $r = ['lp_kasa'=>0, 'lp_palet'=>0, 'pm'=>0, 'msm'=>0];
    try {
        if ($type === 'kasa_cinsi' && dr_tbl($p, 'loading_pallets')) {
            $st = $p->prepare("SELECT COUNT(*) FROM loading_pallets WHERE kasa_cinsi_id=?");
            $st->execute([$id]); $r['lp_kasa'] = (int)$st->fetchColumn();
        }
        if ($type === 'palet_tipi' && dr_tbl($p, 'loading_pallets')) {
            $st = $p->prepare("SELECT COUNT(*) FROM loading_pallets WHERE palet_tipi_id=?");
            $st->execute([$id]); $r['lp_palet'] = (int)$st->fetchColumn();
        }
        if (in_array($type, $mat_types) && dr_tbl($p, 'pallet_materials')) {
            $st = $p->prepare("SELECT COUNT(*) FROM pallet_materials WHERE material_id=?");
            $st->execute([$id]); $r['pm'] = (int)$st->fetchColumn();
        }
        if (dr_tbl($p, 'material_stock_movements')) {
            $st = $p->prepare("SELECT COUNT(*) FROM material_stock_movements WHERE material_id=?");
            $st->execute([$id]); $r['msm'] = (int)$st->fetchColumn();
        }
    } catch (PDOException $e) {}
    $r['total'] = $r['lp_kasa'] + $r['lp_palet'] + $r['pm'] + $r['msm'];
    return $r;
}

// ── Veri Toplama ─────────────────────────────────────────────────

$sections = []; // section_key => rows
$all_rows = []; // tüm tarama sonuçları (CSV için)

// 1. material_definitions — duplicate tespiti dahil
$md_rows = [];
if (dr_tbl($pdo, 'material_definitions')) {
    $md_rows = dr_scan($pdo, 'material_definitions', 'id', 'name', 'type');

    // v2 çıktısına göre aynı (type, normalize) grubunu işaretle
    $v2_idx = [];
    foreach ($md_rows as $i => $r) {
        $k = $r['extra'] . '||' . mb_strtolower(trim($r['v2']), 'UTF-8');
        $v2_idx[$k][] = $i;
    }
    foreach ($v2_idx as $idxs) {
        if (count($idxs) > 1) {
            foreach ($idxs as $i) $md_rows[$i]['creates_merge'] = true;
        }
    }
    $sections['material_definitions'] = $md_rows;
    $all_rows = array_merge($all_rows, $md_rows);
}

// 2-7. Free-text kolonlar
$ft_targets = [
    ['loading_records', 'id', 'firma',       null],
    ['loading_pallets', 'id', 'urun_cinsi',  null],
    ['loading_pallets', 'id', 'depo',        null],
    ['kantar_fisleri',  'id', 'firma_adi',   null],
    ['kantar_fisleri',  'id', 'malin_cinsi', null],
    ['kantar_fisleri',  'id', 'depo',        null],
    ['kantar_gruplar',  'id', 'grup_adi',    null],
];
foreach ($ft_targets as [$tbl, $id_c, $col, $extra]) {
    if (dr_tbl($pdo, $tbl) && dr_col($pdo, $tbl, $col)) {
        $r = dr_scan($pdo, $tbl, $id_c, $col, $extra);
        $sections["$tbl → $col"] = $r;
        $all_rows = array_merge($all_rows, $r);
    }
}

// ── FK Merge Planı ───────────────────────────────────────────────

$merge_plan = [];
if (!empty($md_rows)) {
    $groups = [];
    foreach ($md_rows as $row) {
        $k = $row['extra'] . '||' . mb_strtolower(trim($row['v2']), 'UTF-8');
        if (!isset($groups[$k])) {
            $groups[$k] = ['type' => $row['extra'], 'v2' => $row['v2'], 'members' => []];
        }
        $groups[$k]['members'][] = ['id' => (int)$row['id'], 'name' => $row['current']];
    }
    foreach ($groups as $g) {
        if (count($g['members']) < 2) continue;
        // FK sayılarını çek
        $mwf = [];
        foreach ($g['members'] as $m) {
            $m['fk'] = dr_fk($pdo, $m['id'], $g['type']);
            $mwf[]   = $m;
        }
        // Sırala: en fazla FK referansı → surviving. Eşitlikte: en düşük id.
        usort($mwf, fn($a, $b) =>
            $b['fk']['total'] !== $a['fk']['total']
                ? $b['fk']['total'] - $a['fk']['total']
                : $a['id'] - $b['id']
        );
        $surv = $mwf[0];
        foreach (array_slice($mwf, 1) as $dup) {
            $merge_plan[] = [
                'type'      => $g['type'],
                'v2'        => $g['v2'],
                'surv_id'   => $surv['id'],
                'surv_name' => $surv['name'],
                'dup_id'    => $dup['id'],
                'dup_name'  => $dup['name'],
                'lp_kasa'   => $dup['fk']['lp_kasa'],
                'lp_palet'  => $dup['fk']['lp_palet'],
                'pm'        => $dup['fk']['pm'],
                'msm'       => $dup['fk']['msm'],
                'total_fk'  => $dup['fk']['total'],
            ];
        }
    }
}

// ── Özet İstatistik ──────────────────────────────────────────────

$sec_stat = [];
foreach ($sections as $k => $rows) {
    $sec_stat[$k] = [
        'total'   => count($rows),
        'changed' => count(array_filter($rows, fn($r) => $r['will_change'])),
        'u0307'   => count(array_filter($rows, fn($r) => $r['u0307'])),
        'merges'  => count(array_filter($rows, fn($r) => $r['creates_merge'])),
    ];
}

$md_type_stat = [];
foreach ($md_rows as $row) {
    $t = $row['extra'] ?: '(unknown)';
    if (!isset($md_type_stat[$t])) $md_type_stat[$t] = ['total'=>0,'changed'=>0,'u0307'=>0,'merges'=>0];
    $md_type_stat[$t]['total']++;
    if ($row['will_change'])    $md_type_stat[$t]['changed']++;
    if ($row['u0307'])          $md_type_stat[$t]['u0307']++;
    if ($row['creates_merge'])  $md_type_stat[$t]['merges']++;
}
// En fazla sorunlu type'lar üste
uasort($md_type_stat, fn($a,$b) =>
    ($b['u0307'] + $b['merges'] * 2 + $b['changed']) -
    ($a['u0307'] + $a['merges'] * 2 + $a['changed'])
);

$tot_changed = array_sum(array_column($sec_stat, 'changed'));
$tot_u0307   = array_sum(array_column($sec_stat, 'u0307'));
$tot_merges  = count($merge_plan);
$tot_fk      = array_sum(array_column($merge_plan, 'total_fk'));

if ($tot_fk > 0)          $risk = 'YÜKSEK';
elseif ($tot_merges > 0)  $risk = 'ORTA';
elseif ($tot_u0307 > 0)   $risk = 'ORTA';
elseif ($tot_changed > 0) $risk = 'DÜŞÜK';
else                       $risk = 'YOK';

// ── CSV Üretimi (CLI) ────────────────────────────────────────────

$csv1     = $out_dir . '/normalize_dry_run_' . $ts . '.csv';
$csv2     = $out_dir . '/merge_plan_' . $ts . '.csv';
$csv1_ok  = false;
$csv2_ok  = false;

if ($is_cli) {
    $fh = @fopen($csv1, 'w');
    if ($fh) {
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['tablo','id','kolon','type','mevcut','v1_normalize','v2_normalize',
                      'degisecek_mi','u0307_var_mi','unicode_flags','merge_olusturur_mu']);
        foreach ($all_rows as $r) {
            fputcsv($fh, [
                $r['table'], $r['id'], $r['column'], $r['extra'],
                $r['current'], $r['v1'], $r['v2'],
                $r['will_change']   ? 'EVET' : 'HAYIR',
                $r['u0307']         ? 'EVET' : 'HAYIR',
                $r['flags'],
                $r['creates_merge'] ? 'EVET' : 'HAYIR',
            ]);
        }
        fclose($fh);
        $csv1_ok = true;
    }

    $fh2 = @fopen($csv2, 'w');
    if ($fh2) {
        fwrite($fh2, "\xEF\xBB\xBF");
        fputcsv($fh2, ['type','v2_sonucu','surviving_id','surviving_isim','dup_id','dup_isim',
                       'lp_kasa_guncelleme','lp_palet_guncelleme','pallet_materials','stock_movements','toplam_fk']);
        foreach ($merge_plan as $m) {
            fputcsv($fh2, [
                $m['type'], $m['v2'],
                $m['surv_id'], $m['surv_name'],
                $m['dup_id'],  $m['dup_name'],
                $m['lp_kasa'], $m['lp_palet'], $m['pm'], $m['msm'], $m['total_fk'],
            ]);
        }
        fclose($fh2);
        $csv2_ok = true;
    }
}

// ── Web: CSV indirme isteği ──────────────────────────────────────

if (!$is_cli && isset($_GET['csv'])) {
    $which = (string)($_GET['csv'] ?? '');

    $csv_configs = [
        'dry_run' => [
            'filename' => "normalize_dry_run_{$ts}.csv",
            'headers'  => ['tablo','id','kolon','type','mevcut','v1_normalize','v2_normalize',
                           'degisecek_mi','u0307_var_mi','unicode_flags','merge_olusturur_mu'],
            'rows'     => &$all_rows,
            'map'      => fn($r) => [
                $r['table'], $r['id'], $r['column'], $r['extra'],
                $r['current'], $r['v1'], $r['v2'],
                $r['will_change']   ? 'EVET':'HAYIR',
                $r['u0307']         ? 'EVET':'HAYIR',
                $r['flags'],
                $r['creates_merge'] ? 'EVET':'HAYIR',
            ],
        ],
        'merge_plan' => [
            'filename' => "merge_plan_{$ts}.csv",
            'headers'  => ['type','v2_sonucu','surviving_id','surviving_isim','dup_id','dup_isim',
                           'lp_kasa','lp_palet','pallet_materials','stock_movements','toplam_fk'],
            'rows'     => &$merge_plan,
            'map'      => fn($m) => [
                $m['type'], $m['v2'],
                $m['surv_id'], $m['surv_name'],
                $m['dup_id'],  $m['dup_name'],
                $m['lp_kasa'], $m['lp_palet'], $m['pm'], $m['msm'], $m['total_fk'],
            ],
        ],
    ];

    if (isset($csv_configs[$which])) {
        $cfg = $csv_configs[$which];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $cfg['filename'] . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $cfg['headers']);
        foreach ($cfg['rows'] as $row) {
            fputcsv($out, ($cfg['map'])($row));
        }
        fclose($out);
        exit;
    }
}

// ================================================================
// ÇIKTI
// ================================================================

// ── HTML yardımcısı ──────────────────────────────────────────────
function he(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── CLI ÇIKTISI ──────────────────────────────────────────────────
if ($is_cli) {
    $W = 80;
    $ln  = str_repeat('─', $W);
    $dln = str_repeat('═', $W);

    echo "\n$dln\n";
    echo "  normalize_text_v2  DRY-RUN Analizi\n";
    echo "  $run_at  |  SALT OKUMA MODU — DB değişikliği yok\n";
    echo "$dln\n\n";

    // ── GENEL ÖZET ──
    $risk_label = ['YOK'=>'YOK', 'DÜŞÜK'=>'DÜŞÜK ↑', 'ORTA'=>'ORTA ⚠', 'YÜKSEK'=>'YÜKSEK ⚠⚠'][$risk] ?? $risk;
    echo "GENEL ÖZET\n$ln\n";
    printf("  Risk Seviyesi        : %s\n", $risk_label);
    printf("  Toplam değişecek     : %d kayıt\n", $tot_changed);
    printf("  U+0307 (bozuk char)  : %d kayıt\n", $tot_u0307);
    printf("  Merge operasyonu     : %d işlem\n", $tot_merges);
    printf("  FK etkilenecek       : %d kayıt\n", $tot_fk);
    echo "\n";

    // ── BÖLÜM ÖZETİ ──
    echo "BÖLÜM ÖZETİ\n$ln\n";
    printf("  %-42s %7s %7s %7s %7s\n", 'Bölüm', 'Toplam', 'Değişir', 'U+0307', 'Merge');
    printf("  %-42s %7s %7s %7s %7s\n", str_repeat('-',42), '-------','-------','-------','-------');
    foreach ($sec_stat as $k => $s) {
        $mark = ($s['u0307'] > 0 || $s['merges'] > 0) ? ' ⚠' : ($s['changed'] > 0 ? ' ↻' : '');
        printf("  %-42s %7d %7d %7d %7d%s\n",
            mb_substr($k, 0, 42, 'UTF-8'),
            $s['total'], $s['changed'], $s['u0307'], $s['merges'], $mark
        );
    }
    echo "\n";

    // ── MATERIAL_DEFINITIONS TYPE BAZINDA ──
    if (!empty($md_type_stat)) {
        echo "MATERIAL_DEFINITIONS — TYPE BAZINDA\n$ln\n";
        printf("  %-22s %7s %7s %7s %7s\n", 'Type', 'Toplam', 'Değişir', 'U+0307', 'Merge');
        printf("  %-22s %7s %7s %7s %7s\n", str_repeat('-',22),'-------','-------','-------','-------');
        foreach ($md_type_stat as $t => $s) {
            $mark = ($s['u0307'] > 0) ? ' ⚠U' : (($s['merges'] > 0) ? ' ⚡M' : ($s['changed'] > 0 ? ' ↻' : ''));
            printf("  %-22s %7d %7d %7d %7d%s\n",
                mb_substr($t, 0, 22, 'UTF-8'),
                $s['total'], $s['changed'], $s['u0307'], $s['merges'], $mark
            );
        }
        echo "\n";
    }

    // ── DEĞİŞECEK KAYITLAR (material_definitions) ──
    $md_notable = array_filter($md_rows, fn($r) => $r['will_change'] || $r['u0307']);
    echo "DEĞİŞECEK / RİSKLİ KAYITLAR (material_definitions)\n$ln\n";
    if (empty($md_notable)) {
        echo "  ✓ Değişecek kayıt yok.\n";
    } else {
        printf("  %-5s %-14s %-22s %-22s %-22s %s\n",
            'ID', 'Type', 'Mevcut', 'V1', 'V2', 'Durum');
        printf("  %-5s %-14s %-22s %-22s %-22s %s\n",
            '-----','-------------- ','----------------------','----------------------','----------------------','------');
        $shown = 0;
        foreach ($md_notable as $r) {
            $st = [];
            if ($r['u0307'])         $st[] = 'U+0307';
            if ($r['creates_merge']) $st[] = 'MERGE';
            if ($r['will_change'])   $st[] = 'DEĞIŞIR';
            printf("  %-5s %-14s %-22s %-22s %-22s %s\n",
                $r['id'],
                mb_substr($r['extra'],   0, 14, 'UTF-8'),
                mb_substr($r['current'], 0, 22, 'UTF-8'),
                mb_substr($r['v1'],      0, 22, 'UTF-8'),
                mb_substr($r['v2'],      0, 22, 'UTF-8'),
                implode('+', $st)
            );
            if (++$shown >= 60) {
                printf("  ... toplam %d kayıt — tamamı CSV'de.\n", count($md_notable));
                break;
            }
        }
    }
    echo "\n";

    // ── FK MERGE PLANI ──
    echo "FK MERGE PLANI\n$ln\n";
    if (empty($merge_plan)) {
        echo "  ✓ Duplicate / merge gerektirecek kayıt bulunamadı.\n";
    } else {
        foreach ($merge_plan as $i => $m) {
            echo "\n  " . ($i + 1) . ". TYPE: " . strtoupper($m['type']) . "\n";
            printf("     Surviving (kalacak) : #%d  \"%s\"\n", $m['surv_id'], $m['surv_name']);
            printf("     Duplicate (silinecek): #%d  \"%s\"\n", $m['dup_id'],  $m['dup_name']);
            printf("     v2 hedef isim       : \"%s\"\n", $m['v2']);
            if ($m['lp_kasa'] > 0)  printf("     loading_pallets.kasa_cinsi_id   : %d kayıt → #%d'e taşınacak\n", $m['lp_kasa'],  $m['surv_id']);
            if ($m['lp_palet'] > 0) printf("     loading_pallets.palet_tipi_id  : %d kayıt → #%d'e taşınacak\n", $m['lp_palet'], $m['surv_id']);
            if ($m['pm'] > 0)       printf("     pallet_materials.material_id   : %d kayıt → #%d'e taşınacak\n", $m['pm'],       $m['surv_id']);
            if ($m['msm'] > 0)      printf("     stock_movements.material_id    : %d kayıt → #%d'e taşınacak\n", $m['msm'],      $m['surv_id']);
            if ($m['total_fk'] === 0) echo "     FK etkisi yok — yalnızca material_definitions satırı silinecek.\n";
        }
    }
    echo "\n";

    // ── FREE-TEXT DEĞİŞİKLİKLERİ ──
    $ft_keys = array_filter(array_keys($sections), fn($k) => $k !== 'material_definitions');
    echo "FREE-TEXT KOLON DEĞİŞİKLİKLERİ\n$ln\n";
    foreach ($ft_keys as $k) {
        $changed = array_filter($sections[$k], fn($r) => $r['will_change']);
        if (empty($changed)) {
            printf("  %-38s — değişiklik yok\n", mb_substr($k, 0, 38, 'UTF-8'));
            continue;
        }
        printf("  %-38s — %d kayıt değişecek:\n", mb_substr($k, 0, 38, 'UTF-8'), count($changed));
        $shown = 0;
        foreach ($changed as $r) {
            printf("    ID %-6s  %-28s → %s\n",
                $r['id'],
                mb_substr($r['current'], 0, 28, 'UTF-8'),
                mb_substr($r['v2'],      0, 28, 'UTF-8')
            );
            if (++$shown >= 8) {
                printf("    ... (%d kayıt daha — tamamı CSV'de)\n", count($changed) - $shown);
                break;
            }
        }
    }
    echo "\n";

    // ── CSV ÇIKTILARI ──
    echo "CSV ÇIKTILARI\n$ln\n";
    echo "  normalize_dry_run : " . ($csv1_ok ? $csv1 : "YAZILAMADI — dizin izni yok: $out_dir") . "\n";
    echo "  merge_plan        : " . ($csv2_ok ? $csv2 : "YAZILAMADI — dizin izni yok: $out_dir") . "\n";
    echo "\n$dln\n";
    echo "  ⚠  DRY-RUN tamamlandı. Hiçbir kayıt değiştirilmedi.\n";
    echo "$dln\n\n";
    exit(0);
}

// ── HTML ÇIKTISI ─────────────────────────────────────────────────

$risk_color = ['YOK'=>'#d1fae5','DÜŞÜK'=>'#dbeafe','ORTA'=>'#fef3c7','YÜKSEK'=>'#fee2e2'][$risk] ?? '#f1f5f9';
$risk_text  = ['YOK'=>'#065f46','DÜŞÜK'=>'#1e40af','ORTA'=>'#92400e','YÜKSEK'=>'#991b1b'][$risk] ?? '#334155';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>normalize_text_v2 Dry-Run — <?= he($run_at) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;font-size:14px;background:#f8fafc;color:#1e293b;padding:16px 12px 60px}
h1{font-size:1.25rem;font-weight:700;margin-bottom:4px}
h2{font-size:1rem;font-weight:700;margin:20px 0 8px}
h3{font-size:.9rem;font-weight:600;margin:12px 0 6px}
.meta{font-size:.8rem;color:#64748b;margin-bottom:20px}
.alert{background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:.85rem}
.cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;min-width:140px;flex:1}
.card .num{font-size:1.7rem;font-weight:700;line-height:1.2}
.card .lbl{font-size:.75rem;color:#64748b;margin-top:2px}
.risk-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-weight:700;font-size:.85rem;background:<?= he($risk_color) ?>;color:<?= he($risk_text) ?>}
details{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:10px;overflow:hidden}
details summary{cursor:pointer;padding:10px 14px;background:#fff;font-weight:600;display:flex;align-items:center;gap:10px;list-style:none;user-select:none}
details summary::-webkit-details-marker{display:none}
details[open] summary{border-bottom:1px solid #e2e8f0}
.sec-body{padding:12px 14px;overflow-x:auto}
.badge{font-size:.72rem;padding:2px 9px;border-radius:12px;font-weight:700;white-space:nowrap}
.b-ok{background:#d1fae5;color:#065f46}.b-warn{background:#fef3c7;color:#92400e}.b-err{background:#fee2e2;color:#991b1b}.b-info{background:#dbeafe;color:#1e40af}
table{width:100%;border-collapse:collapse;font-size:.8rem;margin-top:6px}
th{background:#f1f5f9;padding:6px 10px;text-align:left;white-space:nowrap;border-bottom:2px solid #e2e8f0}
td{padding:5px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top;word-break:break-word;max-width:220px}
tr:last-child td{border-bottom:none}
tr:hover td{background:#f8fafc}
.chg{background:#fef9c3}
.u307{background:#fee2e2}
.merge{background:#ede9fe}
.ok{color:#6ee7b7}
.mono{font-family:monospace;font-size:.77rem}
.merge-block{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:10px}
.merge-block h4{font-weight:700;font-size:.85rem;margin-bottom:8px;color:#374151}
.fk-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.fk-chip{background:#f1f5f9;border-radius:6px;padding:3px 10px;font-size:.76rem;color:#475569}
.fk-chip.fk-warn{background:#fef3c7;color:#92400e;font-weight:600}
.dl-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.btn{display:inline-block;padding:7px 16px;background:#0284c7;color:#fff;border-radius:7px;text-decoration:none;font-size:.83rem;font-weight:600}
.btn:hover{background:#0369a1}
.empty-note{font-size:.83rem;color:#94a3b8;padding:6px 0}
.type-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:4px}
.type-chip{border-radius:7px;padding:5px 12px;font-size:.78rem;border:1px solid #e2e8f0;background:#fff}
.type-chip .tc-num{font-weight:700;font-size:1rem}
.type-chip .tc-lbl{font-size:.7rem;color:#64748b;margin-top:1px}
.type-chip.has-issue{border-color:#fbbf24;background:#fffbeb}
.type-chip.has-u307{border-color:#f87171;background:#fff1f1}
.max-note{font-size:.77rem;color:#94a3b8;margin-top:6px}
</style>
</head>
<body>

<div class="alert">⚠ Geliştirici aracı — üretimde <code>.htaccess</code> tarafından korunur. Hiçbir veri değiştirilmedi.</div>

<h1>normalize_text_v2 Dry-Run Analizi</h1>
<p class="meta">Çalıştırma: <strong><?= he($run_at) ?></strong> &nbsp;·&nbsp; SALT OKUMA MODU</p>

<div class="dl-bar">
    <a class="btn" href="?csv=dry_run">⬇ normalize_dry_run.csv</a>
    <a class="btn" href="?csv=merge_plan">⬇ merge_plan.csv</a>
</div>

<!-- Özet Kartları -->
<div class="cards">
    <div class="card">
        <div class="num"><?= $tot_changed ?></div>
        <div class="lbl">Değişecek kayıt</div>
    </div>
    <div class="card">
        <div class="num" style="color:#dc2626"><?= $tot_u0307 ?></div>
        <div class="lbl">U+0307 bozuk char</div>
    </div>
    <div class="card">
        <div class="num" style="color:#7c3aed"><?= $tot_merges ?></div>
        <div class="lbl">Merge işlemi</div>
    </div>
    <div class="card">
        <div class="num" style="color:#d97706"><?= $tot_fk ?></div>
        <div class="lbl">FK etkilenecek</div>
    </div>
    <div class="card">
        <div class="num"><span class="risk-badge"><?= he($risk) ?></span></div>
        <div class="lbl">Risk seviyesi</div>
    </div>
</div>

<!-- material_definitions type özeti -->
<?php if (!empty($md_type_stat)): ?>
<h2>material_definitions — Type Bazında</h2>
<div class="type-grid">
<?php foreach ($md_type_stat as $t => $s):
    $cls = $s['u0307'] > 0 ? 'has-u307' : ($s['merges'] > 0 || $s['changed'] > 0 ? 'has-issue' : '');
?>
<div class="type-chip <?= he($cls) ?>">
    <div class="tc-num"><?= $s['changed'] ?>/<?= $s['total'] ?></div>
    <div class="tc-lbl"><?= he($t) ?><?= $s['u0307'] > 0 ? ' ⚠' : '' ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Bölüm Tabloları -->
<h2>Bölüm Detayları</h2>
<?php foreach ($sections as $sec_key => $rows):
    $ss = $sec_stat[$sec_key];
    $notable = array_filter($rows, fn($r) => $r['will_change'] || $r['u0307'] || $r['creates_merge']);
    $badge_cls  = $ss['u0307'] > 0 || $ss['merges'] > 0 ? 'b-err' : ($ss['changed'] > 0 ? 'b-warn' : 'b-ok');
    $badge_txt  = $ss['changed'] > 0 ? $ss['changed'].' değişir' : '✓ Değişmez';
    if ($ss['u0307'] > 0) $badge_txt .= ' · ' . $ss['u0307'] . ' U+0307';
    if ($ss['merges'] > 0) $badge_txt .= ' · ' . $ss['merges'] . ' merge';
    $auto_open  = $ss['changed'] > 0 || $ss['u0307'] > 0 || $ss['merges'] > 0;
?>
<details <?= $auto_open ? 'open' : '' ?>>
    <summary>
        <?= he($sec_key) ?>
        <span class="badge <?= $badge_cls ?>" style="margin-left:auto"><?= he($badge_txt) ?></span>
        <span style="font-size:.75rem;color:#94a3b8"><?= $ss['total'] ?> kayıt</span>
    </summary>
    <div class="sec-body">
    <?php if (empty($notable)): ?>
        <p class="empty-note">✓ Bu bölümde değişecek veya riskli kayıt yok.</p>
    <?php else: ?>
        <?php if (count($notable) < count($rows)): ?>
        <p class="max-note">Yalnızca değişecek / riskli kayıtlar gösteriliyor (<?= count($notable) ?>/<?= count($rows) ?> kayıt). Tamamı CSV'de.</p>
        <?php endif; ?>
        <table>
            <thead><tr>
                <th>ID</th>
                <?php if ($sec_key === 'material_definitions'): ?><th>Type</th><?php endif; ?>
                <th>Mevcut</th>
                <th>V1 (normalize)</th>
                <th>V2 (v2)</th>
                <th>Durum</th>
                <th>Unicode</th>
            </tr></thead>
            <tbody>
            <?php $shown = 0; foreach ($notable as $r):
                $row_cls = $r['u0307'] ? 'u307' : ($r['creates_merge'] ? 'merge' : ($r['will_change'] ? 'chg' : ''));
                $st_badges = [];
                if ($r['u0307'])         $st_badges[] = '<span class="badge b-err">U+0307</span>';
                if ($r['creates_merge']) $st_badges[] = '<span class="badge" style="background:#ede9fe;color:#5b21b6">MERGE</span>';
                if ($r['will_change'])   $st_badges[] = '<span class="badge b-warn">DEĞIŞIR</span>';
            ?>
            <tr class="<?= $row_cls ?>">
                <td><?= he($r['id']) ?></td>
                <?php if ($sec_key === 'material_definitions'): ?><td class="mono"><?= he($r['extra']) ?></td><?php endif; ?>
                <td><?= he($r['current']) ?></td>
                <td><?= he($r['v1']) ?></td>
                <td><strong><?= he($r['v2']) ?></strong></td>
                <td><?= implode(' ', $st_badges) ?></td>
                <td class="mono" style="font-size:.7rem;color:#ef4444"><?= he($r['flags']) ?></td>
            </tr>
            <?php if (++$shown >= 100): ?>
            <tr><td colspan="7" class="max-note">... <?= count($notable) - 100 ?> kayıt daha — tamamı CSV'de.</td></tr>
            <?php break; endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</details>
<?php endforeach; ?>

<!-- FK Merge Planı -->
<h2>FK Merge Planı</h2>
<?php if (empty($merge_plan)): ?>
<p class="empty-note">✓ Duplicate / merge gerektiren kayıt bulunamadı.</p>
<?php else: ?>
<p style="font-size:.82rem;color:#64748b;margin-bottom:12px">
    Aşağıdaki işlemler <strong>Sprint 7 migration scriptinde</strong> transaction içinde yapılacak.
    Bu sayfa sadece gösterim amaçlıdır — hiçbir şey çalıştırılmadı.
</p>
<?php foreach ($merge_plan as $i => $m):
    $has_fk = $m['total_fk'] > 0;
    $border  = $has_fk ? '#f87171' : '#e2e8f0';
?>
<div class="merge-block" style="border-color:<?= he($border) ?>">
    <h4><?= ($i+1) ?>. <?= he(strtoupper($m['type'])) ?> — v2 hedef: "<?= he($m['v2']) ?>"</h4>
    <table style="font-size:.78rem">
        <tr>
            <th style="width:140px">Rol</th>
            <th>ID</th>
            <th>Mevcut isim</th>
            <th>İşlem</th>
        </tr>
        <tr>
            <td><span class="badge b-ok">KALACAK</span></td>
            <td><?= he($m['surv_id']) ?></td>
            <td><?= he($m['surv_name']) ?></td>
            <td>isim → "<?= he($m['v2']) ?>" güncellenecek</td>
        </tr>
        <tr>
            <td><span class="badge b-err">SİLİNECEK</span></td>
            <td><?= he($m['dup_id']) ?></td>
            <td><?= he($m['dup_name']) ?></td>
            <td>FK'lar taşınacak → DELETE</td>
        </tr>
    </table>
    <?php if ($has_fk): ?>
    <div class="fk-row">
        <?php if ($m['lp_kasa'] > 0): ?>
        <div class="fk-chip fk-warn">loading_pallets.kasa_cinsi_id: <?= $m['lp_kasa'] ?> kayıt</div>
        <?php endif; ?>
        <?php if ($m['lp_palet'] > 0): ?>
        <div class="fk-chip fk-warn">loading_pallets.palet_tipi_id: <?= $m['lp_palet'] ?> kayıt</div>
        <?php endif; ?>
        <?php if ($m['pm'] > 0): ?>
        <div class="fk-chip fk-warn">pallet_materials.material_id: <?= $m['pm'] ?> kayıt</div>
        <?php endif; ?>
        <?php if ($m['msm'] > 0): ?>
        <div class="fk-chip fk-warn">material_stock_movements.material_id: <?= $m['msm'] ?> kayıt</div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <p style="font-size:.78rem;color:#10b981;margin-top:6px">✓ FK etkisi yok — yalnızca material_definitions satırı silinecek.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

</body>
</html>
