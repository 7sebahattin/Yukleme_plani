<?php
// =========================================================
// cleanup_cikmalar3.php — IMPORT_CIKMALAR_3 verilerini sil
// Kullanım: Tarayıcıdan aç veya: php cleanup_cikmalar3.php
// Çalıştırdıktan sonra bu dosyayı sunucudan silin.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');

$FATURA_NO = 'IMPORT_CIKMALAR_3';

$pdo = db();

// ── 1. Silinecek kayıtları say ────────────────────────────
$rec_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM loading_records WHERE fatura_no = '$FATURA_NO'"
)->fetchColumn();

$pal_count = 0;
if ($rec_count > 0) {
    $ids_raw = $pdo->query(
        "SELECT GROUP_CONCAT(id) FROM loading_records WHERE fatura_no = '$FATURA_NO'"
    )->fetchColumn();
    if ($ids_raw) {
        $pal_count = (int)$pdo->query(
            "SELECT COUNT(*) FROM loading_pallets WHERE loading_record_id IN ($ids_raw)"
        )->fetchColumn();
    }
}

echo "<!DOCTYPE html><html lang='tr'><head><meta charset='utf-8'>
<title>IMPORT_CIKMALAR_3 Temizle</title>
<style>
body { font-family: monospace; max-width: 700px; margin: 40px auto; padding: 20px; }
.ok  { color: green; } .err { color: red; } .warn { color: orange; }
pre  { background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; }
button { padding: 10px 24px; font-size: 1rem; cursor: pointer; }
.danger { background: #c0392b; color: #fff; border: none; border-radius: 4px; }
</style></head><body>
<h2>IMPORT_CIKMALAR_3 Temizleme</h2>";

if ($rec_count === 0) {
    echo "<p class='ok'>✓ Silinecek kayıt yok. IMPORT_CIKMALAR_3 zaten temiz.</p></body></html>";
    exit;
}

echo "<p class='warn'>⚠ Silinecek: <strong>$rec_count loading_record</strong>, <strong>$pal_count loading_pallet</strong></p>";

// ── 2. Yedek JSON ──────────────────────────────────────────
$backup_file = __DIR__ . '/backups/IMPORT_CIKMALAR_3_backup_' . date('Ymd_His') . '.json';
$backup_dir  = dirname($backup_file);
if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

$records = $pdo->query(
    "SELECT * FROM loading_records WHERE fatura_no = '$FATURA_NO' ORDER BY id"
)->fetchAll();

$pallets_by_rec = [];
if (!empty($ids_raw)) {
    $all_pallets = $pdo->query(
        "SELECT * FROM loading_pallets WHERE loading_record_id IN ($ids_raw) ORDER BY loading_record_id, sira_no"
    )->fetchAll();
    foreach ($all_pallets as $p) {
        $pallets_by_rec[(int)$p['loading_record_id']][] = $p;
    }
}

$backup_data = ['generated_at' => date('c'), 'records' => []];
foreach ($records as $r) {
    $backup_data['records'][] = array_merge($r, [
        'pallets' => $pallets_by_rec[(int)$r['id']] ?? [],
    ]);
}

$written = file_put_contents($backup_file, json_encode($backup_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if ($written === false) {
    echo "<p class='err'>✗ Yedek dosyası yazılamadı: $backup_file — İzin kontrol edin.</p>";
    $backup_ok = false;
} else {
    echo "<p class='ok'>✓ Yedek alındı: <code>" . basename($backup_file) . "</code> (" . number_format($written) . " byte)</p>";
    $backup_ok = true;
}

// ── 3. Onay veya Silme ────────────────────────────────────
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === '1';

if (!$confirmed) {
    echo "<hr><p>Silmek için onaylayın:</p>
    <form method='get'>
      <input type='hidden' name='confirm' value='1'>
      <button type='submit' class='danger'>✓ Evet, " . $rec_count . " kaydı ve " . $pal_count . " paleti sil</button>
      &nbsp; <a href='cikmalar.php'>İptal</a>
    </form></body></html>";
    exit;
}

// ── 4. Sil ────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    $del_pal = $pdo->prepare(
        "DELETE lp FROM loading_pallets lp
         INNER JOIN loading_records lr ON lp.loading_record_id = lr.id
         WHERE lr.fatura_no = ?"
    );
    $del_pal->execute([$FATURA_NO]);
    $deleted_pal = $del_pal->rowCount();

    $del_rec = $pdo->prepare("DELETE FROM loading_records WHERE fatura_no = ?");
    $del_rec->execute([$FATURA_NO]);
    $deleted_rec = $del_rec->rowCount();

    $pdo->commit();

    echo "<hr>
    <p class='ok'>✓ Silindi: <strong>$deleted_rec kayıt</strong>, <strong>$deleted_pal palet</strong></p>
    <p>Geri dönmek için: <a href='cikmalar.php'>Çıkmalar sayfası</a></p>
    <p class='warn'>Bu dosyayı sunucudan silin: <code>cleanup_cikmalar3.php</code></p>";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<p class='err'>✗ Hata: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>İşlem geri alındı. Yedek dosyası: <code>" . basename($backup_file) . "</code></p>";
}

echo "</body></html>";
