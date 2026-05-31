<?php
// =========================================================
// scripts/repair_kosebent_unit.php — Sprint 36 (revize: Sprint 36C)
// Köşebent TANI/RAPOR script'i. CLI-only. SADECE RAPOR — veri değiştirmez.
//
//   php scripts/repair_kosebent_unit.php
//
// NOT: Köşebent markaları (AgroNatural / Asya Fresh / Asya Fresh sarı /
// Ural / Uras Energy ...) AYRI ürünlerdir; BİRLEŞTİRİLMEMELİDİR.
// Bu nedenle eski "tek tanıma bağla" (merge) davranışı KALDIRILDI.
//
// - Sarf dara'ları config/db.php migration'ı zaten 0'lar.
// - Çıkma kaynaklı takılı (silinemeyen) köşebent hareketleri için:
//     scripts/clean_cikma_movements.php
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

require_once __DIR__ . '/../config/db.php';
$pdo = db();
function pr(string $s = ''): void { echo $s . "\n"; }

pr('== KÖŞEBENT TANI (yalnız rapor) ==');
pr('');

// 1) Köşebent tanımları
$defs = $pdo->query(
    "SELECT id, name, type, unit_dara_kg, is_active
       FROM material_definitions
      WHERE type = 'kosebent'
         OR LOWER(name) LIKE '%köşebent%'
         OR LOWER(name) LIKE '%kosebent%'
      ORDER BY is_active DESC, name"
)->fetchAll();

if (empty($defs)) { pr('Köşebent tanımı bulunamadı.'); exit; }

pr('Köşebent tanımları (her marka AYRI ürün):');
$ids = [];
foreach ($defs as $d) {
    $ids[] = (int)$d['id'];
    pr(sprintf('  #%-4d %-28s type=%-10s dara=%s aktif=%d',
        $d['id'], $d['name'], $d['type'], $d['unit_dara_kg'], $d['is_active']));
}

// 2) material_stock_movements köşebent dağılımı
$ph = implode(',', array_fill(0, count($ids), '?'));
$mv = $pdo->prepare(
    "SELECT m.material_name, m.unit, m.movement_type, m.source_type,
            COUNT(*) c, SUM(m.quantity) q
       FROM material_stock_movements m
      WHERE m.material_id IN ($ph)
         OR LOWER(m.material_name) LIKE '%köşebent%' OR LOWER(m.material_name) LIKE '%kosebent%'
      GROUP BY m.material_name, m.unit, m.movement_type, m.source_type
      ORDER BY m.material_name, m.unit"
);
$mv->execute($ids);
pr('');
pr('Hareketler (malzeme | birim | tip | kaynak | adet | toplam):');
foreach ($mv->fetchAll() as $r) {
    $kaynak = $r['source_type'] !== '' ? $r['source_type'] : '(manuel)';
    $kilit  = $r['source_type'] === 'loading' ? '  🔒 (Malzeme Stok ekranından silinemez)' : '';
    pr(sprintf('  %-24s | %-5s | %-9s | %-8s | %4d | %s%s',
        mb_substr((string)$r['material_name'], 0, 24), $r['unit'], $r['movement_type'], $kaynak, $r['c'], $r['q'], $kilit));
}

pr('');
pr('— Manuel (source boş) hareketler Malzeme Stok ekranından ✕ ile silinebilir.');
pr('— 🔒 loading kaynaklı çıkma hareketleri için: php scripts/clean_cikma_movements.php');
pr('— Markalar ayrıdır; birleştirme YAPILMAZ.');
