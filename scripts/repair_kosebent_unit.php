<?php
// =========================================================
// scripts/repair_kosebent_unit.php — Sprint 36
// Köşebent m/adet karışıklığı onarımı.
//   CLI-only. Web erişimi kapalı.
//   php scripts/repair_kosebent_unit.php          → DRY-RUN (yalnız rapor)
//   php scripts/repair_kosebent_unit.php --apply   → uygula (transaction)
//
// Yaptığı (apply):
//   1) Tüm köşebent pallet_materials satırlarını TEK aktif köşebent tanımına bağlar.
//   2) Köşebent tanımının unit_dara_kg = 0 (sarf, dara kullanılmaz).
//   3) Etkilenen YÜKLEME kayıtları için sync_malzeme_kullanim() → hareketler
//      adet olarak yeniden üretilir (çıkma artık hareket üretmez).
//
// DOKUNMADIĞI: material_stock_movements'taki MANUEL unit='m' köşebent girişleri
//   (miktar anlamı belirsiz olduğundan otomatik çevrilmez) — rapor edilir,
//   malzeme_stok ekranından elle düzenlenmeli/silinmeli.
//
// ⚠ Tek seferlik; çalıştıktan sonra silinmesi önerilir.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

require_once __DIR__ . '/../config/db.php';

$apply = in_array('--apply', $argv, true);
$pdo   = db();

function pr(string $s = ''): void { echo $s . "\n"; }

pr('== KÖŞEBENT ONARIM ' . ($apply ? '(APPLY)' : '(DRY-RUN)') . ' ==');
pr('');

// ── 1) Köşebent tanımları ──────────────────────────────────
$defs = $pdo->query(
    "SELECT id, name, type, unit_dara_kg, is_active
       FROM material_definitions
      WHERE type = 'kosebent'
         OR LOWER(name) LIKE '%köşebent%'
         OR LOWER(name) LIKE '%kosebent%'
      ORDER BY (type='kosebent') DESC, is_active DESC, id"
)->fetchAll();

if (empty($defs)) {
    pr('Köşebent tanımı bulunamadı. İşlem yok.');
    exit;
}

pr('Köşebent tanımları:');
foreach ($defs as $d) {
    pr(sprintf('  #%d  %-28s type=%-12s dara=%s aktif=%d',
        $d['id'], $d['name'], $d['type'], $d['unit_dara_kg'], $d['is_active']));
}
$kose_ids = array_map('intval', array_column($defs, 'id'));

// Kanonik: type='kosebent' + aktif + en küçük id; yoksa type='kosebent'; yoksa ilk
$canon = null;
foreach ($defs as $d) { if ($d['type'] === 'kosebent' && (int)$d['is_active'] === 1) { $canon = $d; break; } }
if (!$canon) foreach ($defs as $d) { if ($d['type'] === 'kosebent') { $canon = $d; break; } }
if (!$canon) $canon = $defs[0];
$canon_id = (int)$canon['id'];
pr('');
pr("Kanonik köşebent tanımı: #{$canon_id}  {$canon['name']}  (type={$canon['type']})");
pr('');

// ── 2) pallet_materials köşebent satırları ─────────────────
$ph  = implode(',', array_fill(0, count($kose_ids), '?'));
$pms = $pdo->prepare(
    "SELECT pm.id, pm.loading_pallet_id, pm.material_id, pm.quantity,
            lp.loading_record_id, lr.type AS rec_type
       FROM pallet_materials pm
       JOIN loading_pallets lp ON lp.id = pm.loading_pallet_id
       JOIN loading_records lr ON lr.id = lp.loading_record_id
      WHERE pm.material_id IN ($ph)"
);
$pms->execute($kose_ids);
$pm_rows = $pms->fetchAll();

$pm_rebind   = array_values(array_filter($pm_rows, fn($r) => (int)$r['material_id'] !== $canon_id));
$affected_lr = array_values(array_unique(array_map(fn($r) => (int)$r['loading_record_id'], $pm_rows)));

pr('pallet_materials köşebent satırı: ' . count($pm_rows)
   . ' (kanonik dışı → bağlanacak: ' . count($pm_rebind) . ')');
pr('Etkilenen yükleme/çıkma kaydı: ' . count($affected_lr));

// ── 3) material_stock_movements köşebent hareketleri ───────
$mv = $pdo->prepare(
    "SELECT unit, movement_type, source_type, COUNT(*) c, SUM(quantity) q
       FROM material_stock_movements
      WHERE material_id IN ($ph) OR LOWER(material_name) LIKE '%köşebent%' OR LOWER(material_name) LIKE '%kosebent%'
      GROUP BY unit, movement_type, source_type
      ORDER BY unit, movement_type"
);
$mv->execute($kose_ids);
$mv_rows = $mv->fetchAll();
pr('');
pr('material_stock_movements köşebent dağılımı (unit | tip | kaynak | adet | toplam):');
$manual_m = 0;
foreach ($mv_rows as $r) {
    pr(sprintf('  %-5s | %-9s | %-8s | %4d | %s',
        $r['unit'], $r['movement_type'], $r['source_type'] ?: '(manuel)', $r['c'], $r['q']));
    if ($r['unit'] === 'm' && ($r['source_type'] === '' || $r['source_type'] === null)) $manual_m += (int)$r['c'];
}
if ($manual_m > 0) {
    pr('');
    pr("⚠ MANUEL unit='m' köşebent hareketi: $manual_m adet — bu script DOKUNMAZ.");
    pr("   Malzeme Stok ekranından elle gözden geçirin/silin (miktar anlamı belirsiz).");
}

// ── 4) Uygula ──────────────────────────────────────────────
if (!$apply) {
    pr('');
    pr('DRY-RUN bitti. Uygulamak için: php scripts/repair_kosebent_unit.php --apply');
    exit;
}

pr('');
pr('Uygulanıyor…');
$pdo->beginTransaction();
try {
    // 4a) kanonik dışı köşebent pallet_materials → kanonik id
    if (!empty($pm_rebind)) {
        $ids = array_map(fn($r) => (int)$r['id'], $pm_rebind);
        $ph2 = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE pallet_materials SET material_id=? WHERE id IN ($ph2)")
            ->execute(array_merge([$canon_id], $ids));
        pr('  ✓ ' . count($ids) . ' pallet_materials satırı kanonik köşebente bağlandı.');
    }
    // 4b) köşebent tanım daraları sıfır (sarf)
    $pdo->prepare("UPDATE material_definitions SET unit_dara_kg=0 WHERE id IN ($ph) AND unit_dara_kg<>0")
        ->execute($kose_ids);
    pr('  ✓ Köşebent tanım daraları sıfırlandı.');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pr('  ❌ HATA: ' . $e->getMessage());
    exit;
}

// 4c) etkilenen kayıtlar için sync (yükleme → adet hareket; çıkma → temizlik)
$ok = 0;
foreach ($affected_lr as $rid) {
    if (function_exists('sync_malzeme_kullanim')) { sync_malzeme_kullanim($rid); $ok++; }
}
pr("  ✓ $ok kayıt için stok hareketleri yeniden üretildi (sync).");
pr('');
pr('APPLY bitti. ⚠ Bu script tek seferliktir; sunucudan silmeniz önerilir.');
