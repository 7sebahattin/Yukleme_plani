<?php
// =============================================================
// scripts/plan_normalize_migration.php — Sprint 8
// Onaylı kayıtlardan migration planı üretir.
// HİÇBİR UPDATE/DELETE çalıştırılmaz — sadece plan çıktısı.
//
// Kullanım:
//   php scripts/plan_normalize_migration.php
//   php scripts/plan_normalize_migration.php --output-dir /tmp/plan
// =============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Hata: Bu script yalnızca CLI'dan çalıştırılabilir.\n");
}

// ── Argümanlar ───────────────────────────────────────────────
$output_dir = __DIR__;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--output-dir' && isset($argv[$i + 1])) {
        $output_dir = rtrim($argv[$i + 1], '/');
        $i++;
    }
}
if (!is_dir($output_dir) && !mkdir($output_dir, 0755, true)) {
    fwrite(STDERR, "Hata: '$output_dir' klasörü oluşturulamadı.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/db.php';

$pdo = db();
$ts  = date('Ymd_His');

// ── Bağımlı tablolar var mı? ─────────────────────────────────
function tbl_exists(PDO $pdo, string $name): bool {
    return (bool) $pdo->query("SHOW TABLES LIKE '$name'")->fetchColumn();
}

echo "\n";
echo "==========================================================\n";
echo " Normalize Migration Plan — Sprint 8\n";
echo " Tarih: " . date('Y-m-d H:i:s') . "\n";
echo " NOT: Hiçbir UPDATE/DELETE çalıştırılmaz.\n";
echo "==========================================================\n\n";

// Tablo kontrolü
if (!tbl_exists($pdo, 'normalize_migration_queue')) {
    fwrite(STDERR, "HATA: normalize_migration_queue tablosu bulunamadı.\n");
    fwrite(STDERR, "Önce audit.php'den 'Analizi Başlat' butonunu çalıştırın.\n");
    exit(1);
}

$has_lp  = tbl_exists($pdo, 'loading_pallets');
$has_pm  = tbl_exists($pdo, 'pallet_materials');
$has_msm = tbl_exists($pdo, 'material_stock_movements');
$has_md  = tbl_exists($pdo, 'material_definitions');

// ── Onaylı kayıtları yükle ───────────────────────────────────
$approved = $pdo->query("
    SELECT q.*,
           COALESCE(md.unit_dara_kg, 0)  AS unit_dara_kg,
           COALESCE(md.is_active,    1)  AS is_active
    FROM normalize_migration_queue q
    LEFT JOIN material_definitions md ON md.id = q.target_id
    WHERE q.status = 'approved'
    ORDER BY q.is_merge DESC, q.is_survivor DESC, q.fk_total DESC, q.target_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($approved)) {
    echo "Onaylı kayıt bulunamadı.\n";
    echo "Önce audit.php 'Normalize Migration Review' panelinde kayıtları onaylayın.\n\n";
    exit(0);
}

echo "Onaylı kayıt: " . count($approved) . "\n\n";

// ── FK operasyonlarını üret ──────────────────────────────────
$pm_types = [
    'sapka','kosebent','serit','casus','kasa_etiketi','minti',
    'kenar_kartonu','taban_kagidi','sale','viyol','kose_karton',
    'kraft_kagit','file','diger',
];

/**
 * Verilen type için from_id → to_id FK güncelleme SQL'lerini üret.
 * @return list<array{table:string, sql:string}>
 */
function fk_ops(string $type, int $from_id, int $to_id, array $pm_types,
                bool $has_lp, bool $has_pm, bool $has_msm): array
{
    $ops = [];
    if ($type === 'kasa_cinsi' && $has_lp) {
        $ops[] = [
            'table' => 'loading_pallets',
            'sql'   => "UPDATE `loading_pallets` SET `kasa_cinsi_id` = $to_id WHERE `kasa_cinsi_id` = $from_id;",
        ];
    }
    if ($type === 'palet_tipi' && $has_lp) {
        $ops[] = [
            'table' => 'loading_pallets',
            'sql'   => "UPDATE `loading_pallets` SET `palet_tipi_id` = $to_id WHERE `palet_tipi_id` = $from_id;",
        ];
    }
    if (in_array($type, $pm_types, true) && $has_pm) {
        $ops[] = [
            'table' => 'pallet_materials',
            'sql'   => "UPDATE `pallet_materials` SET `material_id` = $to_id WHERE `material_id` = $from_id;",
        ];
    }
    if ($has_msm) {
        $ops[] = [
            'table' => 'material_stock_movements',
            'sql'   => "UPDATE `material_stock_movements` SET `material_id` = $to_id WHERE `material_id` = $from_id;",
        ];
    }
    return $ops;
}

// ── Kayıtları sınıflandır ────────────────────────────────────
$text_updates  = []; // will_change=1, is_merge=0
$merge_groups  = []; // [surviving_id => ['survivor'=>row|null, 'dups'=>[row,...]]]
$risky_dups    = []; // is_merge=1, is_survivor=0, fk_total>0
$affected_tbls = [];

foreach ($approved as $r) {
    $id = (int) $r['target_id'];

    if ($r['is_merge']) {
        $surv = (int) $r['surviving_id'];
        if (!isset($merge_groups[$surv])) {
            $merge_groups[$surv] = ['survivor' => null, 'dups' => []];
        }
        if ($r['is_survivor']) {
            $merge_groups[$surv]['survivor'] = $r;
        } else {
            $merge_groups[$surv]['dups'][] = $r;
            if ((int) $r['fk_total'] > 0) {
                $risky_dups[] = $r;
            }
        }
    } elseif ($r['will_change']) {
        $text_updates[] = $r;
    }
}

// ── SQL buffer'ları ──────────────────────────────────────────
$fwd = [];
$rbk = [];

$hdr_fwd = [
    "-- ============================================================",
    "-- NORMALIZE MİGRASYON — FORWARD PLAN",
    "-- Oluşturulma: " . date('Y-m-d H:i:s'),
    "-- ÇALIŞTIRMADAN ÖNCE TAM YEDEK ALIN!",
    "--",
    "-- Adımlar:",
    "--   1. mysqldump ile tam yedek al",
    "--   2. Bu dosyayı transaction içinde çalıştır",
    "--   3. Uygulamayı test et",
    "--   4. Sorun yoksa rollback dosyasını sil",
    "-- ============================================================",
    "",
    "START TRANSACTION;",
    "",
];
$hdr_rbk = [
    "-- ============================================================",
    "-- NORMALIZE MİGRASYON — ROLLBACK PLAN",
    "-- SADECE forward migration uygulandıktan sonra kullanın.",
    "--",
    "-- ⚠  UYARI: FK merge rollback'i garantili değildir.",
    "--    Forward migration'dan sonra oluşan yeni kayıtlar",
    "--    surviving_id'yi referans alacağından bunlar da geri",
    "--    döner. Manuel kontrol gerektirir.",
    "-- ============================================================",
    "",
    "START TRANSACTION;",
    "",
];
array_push($fwd, ...$hdr_fwd);
array_push($rbk, ...$hdr_rbk);

$cnt_text_upd = 0;
$cnt_fk_merge = 0;
$cnt_fk_ops   = 0;
$cnt_delete   = 0;
$csv_rows     = [];
$seq          = 0;

// CSV başlığı
$csv_rows[] = [
    'Sıra', 'İşlem Tipi', 'Tür', 'Mevcut Değer', 'V2 Değeri',
    'Surviving ID', 'Dup ID',
    'FK lp_kasa', 'FK lp_palet', 'FK pm', 'FK msm', 'FK Toplam',
    'Risk', 'Unicode Bayraklar', 'SQL Özeti',
];

// ── [A] Sadece isim güncellemeleri ──────────────────────────
if (!empty($text_updates)) {
    $fwd[] = "-- ─────────────────────────────────────────────────────────";
    $fwd[] = "-- [A] Sadece isim güncellemeleri (" . count($text_updates) . " kayıt)";
    $fwd[] = "-- ─────────────────────────────────────────────────────────";
    $rbk[] = "-- ─────────────────────────────────────────────────────────";
    $rbk[] = "-- [A] Rollback — isim geri yükleme";
    $rbk[] = "-- ─────────────────────────────────────────────────────────";

    foreach ($text_updates as $r) {
        $seq++;
        $id  = (int) $r['target_id'];
        $cur = addslashes($r['current_value']);
        $v2  = addslashes($r['v2_value']);

        $fwd[] = "  UPDATE `material_definitions` SET `name` = '$v2' WHERE `id` = $id;"
               . "  -- [{$r['mat_type']}] \"{$r['current_value']}\" → \"{$r['v2_value']}\"";
        $rbk[] = "  UPDATE `material_definitions` SET `name` = '$cur' WHERE `id` = $id;"
               . "  -- rollback id=$id";

        $affected_tbls['material_definitions'] = true;
        $cnt_text_upd++;

        $csv_rows[] = [
            $seq, 'TEXT_UPDATE', $r['mat_type'],
            $r['current_value'], $r['v2_value'],
            '', '',
            $r['fk_lp_kasa'], $r['fk_lp_palet'], $r['fk_pm'], $r['fk_msm'], $r['fk_total'],
            $r['risk_level'], $r['unicode_flags'],
            "UPDATE material_definitions SET name='{$r['v2_value']}' WHERE id=$id",
        ];
    }
    $fwd[] = "";
    $rbk[] = "";
}

// ── [B] Merge grupları ───────────────────────────────────────
if (!empty($merge_groups)) {
    $grp_count = count($merge_groups);
    $fwd[] = "-- ─────────────────────────────────────────────────────────";
    $fwd[] = "-- [B] Merge işlemleri ({$grp_count} grup)";
    $fwd[] = "-- Sıra: önce FK taşı, sonra sil — transaction içinde.";
    $fwd[] = "-- ─────────────────────────────────────────────────────────";
    $rbk[] = "-- ─────────────────────────────────────────────────────────";
    $rbk[] = "-- [B] Rollback — merge (silinen tanımları önce geri yükle!)";
    $rbk[] = "-- ─────────────────────────────────────────────────────────";

    foreach ($merge_groups as $surv_id => $grp) {
        $surv     = $grp['survivor'];
        $dups     = $grp['dups'];
        $grp_type = $surv ? $surv['mat_type'] : ($dups[0]['mat_type'] ?? '?');
        $dup_ids  = implode(', ', array_map(fn($d) => (int) $d['target_id'], $dups));

        // Survivor adını bul (approved listede olmayabilir)
        $surv_name = $surv ? $surv['current_value'] : '';
        if ($surv_name === '' && $has_md) {
            $st = $pdo->prepare("SELECT name FROM material_definitions WHERE id=? LIMIT 1");
            $st->execute([$surv_id]);
            $surv_name = (string) ($st->fetchColumn() ?: "id=$surv_id");
        }

        $fwd[] = "";
        $fwd[] = "  -- ── Grup: surviving_id=$surv_id \"$surv_name\" [{$grp_type}]"
               . ", birleştirilecek: [$dup_ids]";
        $rbk[] = "";
        $rbk[] = "  -- ── Rollback grubu: surviving_id=$surv_id";

        // Survivor adı değişiyorsa güncelle
        if ($surv && $surv['will_change']) {
            $seq++;
            $cur = addslashes($surv['current_value']);
            $v2  = addslashes($surv['v2_value']);
            $id  = $surv_id;

            $fwd[] = "  UPDATE `material_definitions` SET `name` = '$v2' WHERE `id` = $id;"
                   . "  -- survivor isim güncelle";
            $rbk[] = "  UPDATE `material_definitions` SET `name` = '$cur' WHERE `id` = $id;"
                   . "  -- rollback survivor name";

            $affected_tbls['material_definitions'] = true;
            $cnt_text_upd++;

            $csv_rows[] = [
                $seq, 'MERGE_SURVIVOR_UPDATE', $grp_type,
                $surv['current_value'], $surv['v2_value'],
                $surv_id, '',
                $surv['fk_lp_kasa'], $surv['fk_lp_palet'], $surv['fk_pm'], $surv['fk_msm'], $surv['fk_total'],
                $surv['risk_level'], $surv['unicode_flags'],
                "UPDATE material_definitions SET name='{$surv['v2_value']}' WHERE id=$id",
            ];
        }

        // Dup'ları işle
        foreach ($dups as $dup) {
            $seq++;
            $dup_id  = (int) $dup['target_id'];
            $fk_tot  = (int) $dup['fk_total'];
            $dup_cur = addslashes($dup['current_value']);
            $unit    = $dup['unit_dara_kg'] ?? '0';
            $active  = $dup['is_active'] ?? '1';

            $ops = fk_ops($grp_type, $dup_id, $surv_id, $pm_types, $has_lp, $has_pm, $has_msm);

            $fwd[] = "";
            $fwd[] = "  -- Dup id=$dup_id \"{$dup['current_value']}\" → surviving id=$surv_id"
                   . " (FK: $fk_tot)";

            $rbk[] = "  -- Dup id=$dup_id rollback:";
            // Rollback: önce re-insert (INSERT IGNORE — FK eklenmeden önce tablo hazır olmalı)
            $rbk[] = "  INSERT IGNORE INTO `material_definitions`"
                   . " (`id`, `type`, `name`, `unit_dara_kg`, `is_active`)"
                   . " VALUES ($dup_id, '$grp_type', '$dup_cur', $unit, $active);";

            $fk_sql_list = [];
            foreach ($ops as $op) {
                $fwd[] = "  " . $op['sql'];
                $fk_sql_list[] = $op['sql'];
                $affected_tbls[$op['table']] = true;
                $cnt_fk_ops++;
                $cnt_fk_merge++;

                // Rollback FK — takas: surviving → dup (yeni kayıtları da etkiler, uyarı verilir)
                $rev = preg_replace(
                    "/= $surv_id WHERE/",
                    "= $dup_id WHERE",
                    str_replace("= $dup_id;", "= $surv_id;", $op['sql'])
                );
                $rbk[] = "  -- ⚠ Dikkat: forward migration sonrası oluşan yeni kayıtlar da etkilenir!";
                $rbk[] = "  $rev";
            }

            $fwd[] = "  DELETE FROM `material_definitions` WHERE `id` = $dup_id;"
                   . "  -- dup siliniyor: \"{$dup['current_value']}\"";
            $affected_tbls['material_definitions'] = true;
            $cnt_delete++;

            $csv_rows[] = [
                $seq, 'MERGE_DELETE_DUP', $grp_type,
                $dup['current_value'], $dup['v2_value'],
                $surv_id, $dup_id,
                $dup['fk_lp_kasa'], $dup['fk_lp_palet'], $dup['fk_pm'], $dup['fk_msm'], $fk_tot,
                $dup['risk_level'], $dup['unicode_flags'],
                count($fk_sql_list) . " FK UPDATE + DELETE id=$dup_id",
            ];
        }
    }
    $fwd[] = "";
}

// ── SQL footer ───────────────────────────────────────────────
$tbl_list = implode(', ', array_keys($affected_tbls));
$fwd[] = "COMMIT;";
$fwd[] = "";
$fwd[] = "-- Etkilenen tablolar: $tbl_list";
$fwd[] = "-- TEXT UPDATE: $cnt_text_upd | FK merge: $cnt_fk_merge | DELETE: $cnt_delete";

$rbk[] = "COMMIT;";
$rbk[] = "";
$rbk[] = "-- Rollback tamamlandı.";

// ── Dosyalara yaz ────────────────────────────────────────────
$csv_path = "$output_dir/approved_migration_plan_{$ts}.csv";
$fwd_path = "$output_dir/forward_migration_{$ts}.sql";
$rbk_path = "$output_dir/rollback_migration_{$ts}.sql";

// CSV (UTF-8 BOM — Excel uyumlu)
$fh = fopen($csv_path, 'w');
fwrite($fh, "\xEF\xBB\xBF");
foreach ($csv_rows as $row) {
    fputcsv($fh, $row, ';');
}
fclose($fh);

file_put_contents($fwd_path, implode("\n", $fwd) . "\n");
file_put_contents($rbk_path, implode("\n", $rbk) . "\n");

// ── Özet ─────────────────────────────────────────────────────
$total_ops = $cnt_text_upd + $cnt_fk_merge + $cnt_delete;

echo "─────────────────────────────────────────────────────────\n";
echo "İŞLEM ÖZETİ\n";
echo "─────────────────────────────────────────────────────────\n";
printf("  Onaylı kayıt sayısı     : %d\n", count($approved));
printf("  İsim güncellemeleri     : %d  UPDATE material_definitions\n", $cnt_text_upd);
printf("  Merge grubu             : %d  grup\n", count($merge_groups));
printf("  FK taşıma (UPDATE)      : %d  satır (FK tablolarında)\n", $cnt_fk_ops);
printf("  Silme (DELETE dup)      : %d  material_definitions satırı\n", $cnt_delete);
printf("  Riskli dup (FK>0)       : %d  kayıt\n", count($risky_dups));
printf("  Toplam operasyon        : %d\n", $total_ops);
echo "\n";

echo "Etkilenecek tablolar:\n";
foreach (array_keys($affected_tbls) as $t) {
    echo "  ✦ $t\n";
}
echo "\n";

// Riskli dup listesi
if (!empty($risky_dups)) {
    echo "⚠  RİSKLİ KAYITLAR (FK taşıma gerekiyor):\n";
    foreach ($risky_dups as $r) {
        printf("   id=%-5d [%-20s]  %-35s  FK:%d\n",
            $r['target_id'], $r['mat_type'],
            '"' . $r['current_value'] . '"',
            $r['fk_total']
        );
    }
    echo "\n";
}

// Pending/excluded bilgisi
$pending = (int) $pdo->query(
    "SELECT COUNT(*) FROM normalize_migration_queue WHERE status='pending'"
)->fetchColumn();
$excluded = (int) $pdo->query(
    "SELECT COUNT(*) FROM normalize_migration_queue WHERE status='excluded'"
)->fetchColumn();

if ($pending > 0 || $excluded > 0) {
    echo "ℹ  Kuyruk durumu:\n";
    if ($pending > 0) {
        echo "   $pending kayıt hâlâ 'pending' — henüz incelenmedi.\n";
    }
    if ($excluded > 0) {
        echo "   $excluded kayıt 'excluded' — bu planda yer almıyor.\n";
    }
    echo "\n";
}

echo "─────────────────────────────────────────────────────────\n";
echo "Oluşturulan dosyalar:\n";
echo "  CSV    : $csv_path\n";
echo "  Forward: $fwd_path\n";
echo "  Rollback: $rbk_path\n";
echo "─────────────────────────────────────────────────────────\n";
echo "\n";
echo "✓ Plan hazır. Henüz hiçbir veri değiştirilmedi.\n";
echo "  Sprint 9'da forward_migration_*.sql dosyasını\n";
echo "  önce staging'de, ardından üretimde çalıştırın.\n\n";
