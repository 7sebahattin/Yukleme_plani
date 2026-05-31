<?php
// =========================================================
// scripts/clean_cikma_movements.php — Sprint 36B
// Çıkma kaynaklı ESKİ otomatik stok hareketlerini temizler.
//   (Sprint 36 öncesi çıkma = stoğa 'giris' yazıyordu; artık yazmıyor.
//    Bu hareketler source_type='loading' olduğu için Malzeme Stok
//    ekranından SİLİNEMİYOR → 🔒. Bu script onları kaldırır.)
//
//   CLI-only.
//   php scripts/clean_cikma_movements.php          → DRY-RUN (rapor)
//   php scripts/clean_cikma_movements.php --apply    → uygula (sil)
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

pr('== ÇIKMA STOK HAREKETİ TEMİZLİĞİ ' . ($apply ? '(APPLY)' : '(DRY-RUN)') . ' ==');
pr('');

// Çıkma kaynaklı otomatik (loading) hareketler
$base = "FROM material_stock_movements m
         JOIN loading_records lr ON lr.id = m.source_id
        WHERE m.source_type = 'loading' AND lr.type = 'cikma'";

$total = (int)$pdo->query("SELECT COUNT(*) $base")->fetchColumn();
pr("Toplam çıkma kaynaklı hareket: $total");

if ($total === 0) {
    pr('Temizlenecek kayıt yok.');
    exit;
}

// Malzeme/birim/tip dağılımı
$rows = $pdo->query(
    "SELECT m.material_name, m.unit, m.movement_type, COUNT(*) c, SUM(m.quantity) q
     $base
     GROUP BY m.material_name, m.unit, m.movement_type
     ORDER BY c DESC LIMIT 40"
)->fetchAll();
pr('');
pr('Dağılım (malzeme | birim | tip | adet | toplam):');
foreach ($rows as $r) {
    pr(sprintf('  %-26s | %-5s | %-9s | %4d | %s',
        mb_substr((string)$r['material_name'], 0, 26), $r['unit'], $r['movement_type'], $r['c'], $r['q']));
}

// Etkilenen çıkma kayıtları
$recs = (int)$pdo->query("SELECT COUNT(DISTINCT m.source_id) $base")->fetchColumn();
pr('');
pr("Etkilenen çıkma kaydı: $recs");

if (!$apply) {
    pr('');
    pr('DRY-RUN bitti. Silmek için: php scripts/clean_cikma_movements.php --apply');
    exit;
}

pr('');
pr('Siliniyor…');
$pdo->beginTransaction();
try {
    $n = $pdo->exec(
        "DELETE m FROM material_stock_movements m
         JOIN loading_records lr ON lr.id = m.source_id
        WHERE m.source_type = 'loading' AND lr.type = 'cikma'"
    );
    $pdo->commit();
    pr("  ✓ $n çıkma kaynaklı hareket silindi.");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pr('  ❌ HATA: ' . $e->getMessage());
    exit;
}
pr('');
pr('APPLY bitti. ⚠ Bu script tek seferliktir; sunucudan silmeniz önerilir.');
