<?php
// =========================================================
// veri_kalite_rapor.php — Geçici veri kalitesi raporu
// Tarayıcı: ?key=asya2024  |  CLI: php veri_kalite_rapor.php
// Rapor gösterildikten sonra kendini siler.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$is_cli = PHP_SAPI === 'cli';

// ── Erişim kontrolü (tarayıcı modunda) ───────────────────
if (!$is_cli && ($_GET['key'] ?? '') !== 'asya2024') {
    http_response_code(403);
    die('Erişim reddedildi. ?key= parametresi gerekli.');
}
$pdo    = db();

// ── Yardımcı: tablo / metin çıktısı ──────────────────────
function row_table(array $rows, array $cols, bool $is_cli): void {
    if (empty($rows)) {
        echo $is_cli ? "  (kayit yok)\n" : "<p style='color:#6b7280;margin:4px 0 12px'>Kayıt yok.</p>";
        return;
    }
    if ($is_cli) {
        $widths = array_map(fn($c) => max(strlen($c), 6), $cols);
        foreach ($rows as $r) {
            foreach (array_keys($cols) as $i => $key) {
                $widths[$i] = max($widths[$i], strlen((string)($r[$key] ?? '')));
            }
        }
        $line = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
        echo $line . "\n";
        $h = '|';
        foreach (array_values($cols) as $i => $label) {
            $h .= ' ' . str_pad($label, $widths[$i]) . ' |';
        }
        echo $h . "\n" . $line . "\n";
        foreach ($rows as $r) {
            $row = '|';
            foreach (array_keys($cols) as $i => $key) {
                $row .= ' ' . str_pad((string)($r[$key] ?? ''), $widths[$i]) . ' |';
            }
            echo $row . "\n";
        }
        echo $line . "\n";
    } else {
        echo '<div style="overflow-x:auto;margin-bottom:16px"><table border="1" style="border-collapse:collapse;font-size:13px;width:100%">';
        echo '<thead><tr style="background:#f3f4f6">';
        foreach ($cols as $label) {
            echo '<th style="padding:6px 10px;white-space:nowrap">' . htmlspecialchars($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $i => $r) {
            $bg = $i % 2 ? '#fafafa' : '#fff';
            echo '<tr style="background:' . $bg . '">';
            foreach (array_keys($cols) as $key) {
                $val = (string)($r[$key] ?? '');
                echo '<td style="padding:5px 10px;white-space:nowrap">' . htmlspecialchars($val) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}

function section(string $title, int $total, array $rows, array $cols, bool $is_cli): void {
    if ($is_cli) {
        echo "\n══════════════════════════════════════════════════════\n";
        echo " $title\n";
        echo " Toplam: $total kayit\n";
        echo "══════════════════════════════════════════════════════\n";
    } else {
        $color = $total > 0 ? '#991b1b' : '#065f46';
        $bg    = $total > 0 ? '#fee2e2' : '#d1fae5';
        echo '<div style="margin:20px 0 4px;padding:8px 14px;background:' . $bg . ';border-radius:6px;display:flex;justify-content:space-between;align-items:center">';
        echo '<strong style="font-size:15px">' . htmlspecialchars($title) . '</strong>';
        echo '<span style="color:' . $color . ';font-weight:700;font-size:14px">Toplam: ' . $total . ' kayıt</span>';
        echo '</div>';
    }
    row_table($rows, $cols, $is_cli);
}

// ── HTML başlık ───────────────────────────────────────────
if (!$is_cli) {
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Veri Kalite Raporu</title>
<style>
body{font-family:system-ui,sans-serif;margin:0;padding:16px;background:#f9fafb;color:#111}
h1{font-size:20px;margin:0 0 4px}
.meta{font-size:12px;color:#6b7280;margin-bottom:20px}
</style></head><body>
<h1>🔍 Veri Kalite Raporu</h1>
<div class="meta">Oluşturulma: ' . date('d.m.Y H:i:s') . ' · Sunucu: ' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? gethostname()) . '</div>';
} else {
    echo "\n=== VERİ KALİTE RAPORU — " . date('d.m.Y H:i:s') . " ===\n";
}

// ─────────────────────────────────────────────────────────
// 1. Deposu boş kantar fişleri
// ─────────────────────────────────────────────────────────
$total = (int)$pdo->query("SELECT COUNT(*) FROM kantar_fisleri WHERE depo = ''")->fetchColumn();
$rows  = $pdo->query("SELECT id, giris_tarih AS tarih, firma_adi AS firma,
    malin_cinsi AS mal_cinsi, depo, net_kg,
    CONCAT('kantar_edit.php?id=', id) AS duzeltme_linki
  FROM kantar_fisleri WHERE depo = '' ORDER BY id DESC LIMIT 20")->fetchAll();
section('1. Deposu Boş Kantar Fişleri', $total, $rows, [
    'id' => 'ID', 'tarih' => 'Tarih', 'firma' => 'Firma',
    'mal_cinsi' => 'Mal Cinsi', 'depo' => 'Depo', 'net_kg' => 'Net KG',
    'duzeltme_linki' => 'Düzeltme Linki',
], $is_cli);

// ─────────────────────────────────────────────────────────
// 2. Mal cinsi boş kantar fişleri
// ─────────────────────────────────────────────────────────
$total = (int)$pdo->query("SELECT COUNT(*) FROM kantar_fisleri WHERE malin_cinsi = ''")->fetchColumn();
$rows  = $pdo->query("SELECT id, giris_tarih AS tarih, firma_adi AS firma,
    malin_cinsi AS mal_cinsi, depo, net_kg,
    CONCAT('kantar_edit.php?id=', id) AS duzeltme_linki
  FROM kantar_fisleri WHERE malin_cinsi = '' ORDER BY id DESC LIMIT 20")->fetchAll();
section('2. Mal Cinsi Boş Kantar Fişleri', $total, $rows, [
    'id' => 'ID', 'tarih' => 'Tarih', 'firma' => 'Firma',
    'mal_cinsi' => 'Mal Cinsi', 'depo' => 'Depo', 'net_kg' => 'Net KG',
    'duzeltme_linki' => 'Düzeltme Linki',
], $is_cli);

// ─────────────────────────────────────────────────────────
// 3. Net KG sıfır kantar fişleri
// ─────────────────────────────────────────────────────────
$total = (int)$pdo->query("SELECT COUNT(*) FROM kantar_fisleri WHERE net_kg = 0")->fetchColumn();
$rows  = $pdo->query("SELECT id, giris_tarih AS tarih, firma_adi AS firma,
    malin_cinsi AS mal_cinsi, depo,
    brut_kg, tara_kg, net_kg,
    CONCAT('kantar_edit.php?id=', id) AS duzeltme_linki
  FROM kantar_fisleri WHERE net_kg = 0 ORDER BY id DESC LIMIT 20")->fetchAll();
section('3. Net KG Sıfır Kantar Fişleri', $total, $rows, [
    'id' => 'ID', 'tarih' => 'Tarih', 'firma' => 'Firma',
    'mal_cinsi' => 'Mal Cinsi', 'depo' => 'Depo',
    'brut_kg' => 'Brüt KG', 'tara_kg' => 'Tara KG', 'net_kg' => 'Net KG',
    'duzeltme_linki' => 'Düzeltme Linki',
], $is_cli);

// ─────────────────────────────────────────────────────────
// 4. Deposu boş paletler
// ─────────────────────────────────────────────────────────
$total = (int)$pdo->query("
    SELECT COUNT(*) FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    WHERE lp.depo = '' AND lr.type IN ('yukleme','cikma')")->fetchColumn();
$rows  = $pdo->query("
    SELECT lp.id AS palet_id, lr.id AS kayit_id, lr.type AS tip,
        lr.tarih, lr.firma, lp.urun_cinsi AS urun, lp.depo, lp.net_kg,
        CONCAT('record_edit.php?id=', lr.id) AS duzeltme_linki
    FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    WHERE lp.depo = '' AND lr.type IN ('yukleme','cikma')
    ORDER BY lp.id DESC LIMIT 20")->fetchAll();
section('4. Deposu Boş Paletler', $total, $rows, [
    'palet_id' => 'Palet ID', 'kayit_id' => 'Kayıt ID', 'tip' => 'Tip',
    'tarih' => 'Tarih', 'firma' => 'Firma', 'urun' => 'Ürün',
    'depo' => 'Depo', 'net_kg' => 'Net KG',
    'duzeltme_linki' => 'Düzeltme Linki',
], $is_cli);

// ─────────────────────────────────────────────────────────
// 5. Ürün cinsi boş paletler
// ─────────────────────────────────────────────────────────
$total = (int)$pdo->query("
    SELECT COUNT(*) FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    WHERE lp.urun_cinsi = '' AND lr.type IN ('yukleme','cikma')")->fetchColumn();
$rows  = $pdo->query("
    SELECT lp.id AS palet_id, lr.id AS kayit_id, lr.type AS tip,
        lr.tarih, lr.firma, lp.urun_cinsi AS urun, lp.depo, lp.net_kg,
        CONCAT('record_edit.php?id=', lr.id) AS duzeltme_linki
    FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    WHERE lp.urun_cinsi = '' AND lr.type IN ('yukleme','cikma')
    ORDER BY lp.id DESC LIMIT 20")->fetchAll();
section('5. Ürün Cinsi Boş Paletler', $total, $rows, [
    'palet_id' => 'Palet ID', 'kayit_id' => 'Kayıt ID', 'tip' => 'Tip',
    'tarih' => 'Tarih', 'firma' => 'Firma', 'urun' => 'Ürün',
    'depo' => 'Depo', 'net_kg' => 'Net KG',
    'duzeltme_linki' => 'Düzeltme Linki',
], $is_cli);

// ─────────────────────────────────────────────────────────
// 6. Net KG sıfır paletler
// ─────────────────────────────────────────────────────────
$total = (int)$pdo->query("
    SELECT COUNT(*) FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    WHERE lp.net_kg = 0 AND lr.type IN ('yukleme','cikma')")->fetchColumn();
$rows  = $pdo->query("
    SELECT lp.id AS palet_id, lr.id AS kayit_id, lr.type AS tip,
        lr.tarih, lr.firma, lp.urun_cinsi AS urun, lp.depo,
        lp.brut_kg, lp.dara_kg, lp.net_kg,
        CONCAT('record_edit.php?id=', lr.id) AS duzeltme_linki
    FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    WHERE lp.net_kg = 0 AND lr.type IN ('yukleme','cikma')
    ORDER BY lp.id DESC LIMIT 20")->fetchAll();
section('6. Net KG Sıfır Paletler', $total, $rows, [
    'palet_id' => 'Palet ID', 'kayit_id' => 'Kayıt ID', 'tip' => 'Tip',
    'tarih' => 'Tarih', 'firma' => 'Firma', 'urun' => 'Ürün',
    'depo' => 'Depo', 'brut_kg' => 'Brüt KG', 'dara_kg' => 'Dara KG', 'net_kg' => 'Net KG',
    'duzeltme_linki' => 'Düzeltme Linki',
], $is_cli);

// ─────────────────────────────────────────────────────────
// 7. Paletsiz yükleme/çıkma kayıtları
// ─────────────────────────────────────────────────────────
$total = (int)$pdo->query("
    SELECT COUNT(*) FROM loading_records lr
    WHERE lr.type IN ('yukleme','cikma')
      AND NOT EXISTS (SELECT 1 FROM loading_pallets WHERE loading_record_id = lr.id)")->fetchColumn();
$rows  = $pdo->query("
    SELECT lr.id AS kayit_id, lr.type AS tip, lr.tarih, lr.firma,
        lr.bolge, lr.urun,
        lr.created_at,
        CONCAT('record_edit.php?id=', lr.id) AS duzeltme_linki
    FROM loading_records lr
    WHERE lr.type IN ('yukleme','cikma')
      AND NOT EXISTS (SELECT 1 FROM loading_pallets WHERE loading_record_id = lr.id)
    ORDER BY lr.id DESC LIMIT 20")->fetchAll();
section('7. Paletsiz Yükleme / Çıkma Kayıtları', $total, $rows, [
    'kayit_id' => 'Kayıt ID', 'tip' => 'Tip', 'tarih' => 'Tarih',
    'firma' => 'Firma', 'bolge' => 'Bölge', 'urun' => 'Ürün',
    'created_at' => 'Oluşturma',
    'duzeltme_linki' => 'Düzeltme Linki',
], $is_cli);

// ─────────────────────────────────────────────────────────
// 8. Çıkış nedeni boş çıkma kayıtları
// ─────────────────────────────────────────────────────────
$total = 0;
$rows  = [];
try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM loading_records WHERE type = 'cikma' AND cikis_nedeni = ''")->fetchColumn();
    $rows  = $pdo->query("
        SELECT lr.id AS kayit_id, lr.tarih, lr.firma, lr.urun,
            lr.cikis_nedeni,
            (SELECT COALESCE(SUM(lp.net_kg),0) FROM loading_pallets lp WHERE lp.loading_record_id = lr.id) AS toplam_net_kg,
            CONCAT('record_edit.php?id=', lr.id) AS duzeltme_linki
        FROM loading_records lr
        WHERE lr.type = 'cikma' AND lr.cikis_nedeni = ''
        ORDER BY lr.id DESC LIMIT 20")->fetchAll();
} catch (PDOException $e) {}
section('8. Çıkış Nedeni Boş Çıkma Kayıtları', $total, $rows, [
    'kayit_id' => 'Kayıt ID', 'tarih' => 'Tarih', 'firma' => 'Firma',
    'urun' => 'Ürün', 'cikis_nedeni' => 'Çıkış Nedeni',
    'toplam_net_kg' => 'Toplam Net KG',
    'duzeltme_linki' => 'Düzeltme Linki',
], $is_cli);

// ── Self-delete ───────────────────────────────────────────
$self = __FILE__;
$deleted = @unlink($self);

if (!$is_cli) {
    $del_msg = $deleted
        ? '<span style="color:#065f46;font-weight:700">✓ Bu dosya sunucudan silindi.</span>'
        : '<span style="color:#991b1b">⚠ Dosya silinemedi — manuel silin: <code>rm veri_kalite_rapor.php</code></span>';
    echo '<hr style="margin:24px 0;border-color:#e5e7eb">
<p style="font-size:12px">' . $del_msg . '</p>
</body></html>';
} else {
    echo "\n=== RAPOR TAMAM ===\n";
    echo ($deleted ? "Dosya silindi: $self\n" : "UYARI: Dosya silinemedi — manuel silin: rm veri_kalite_rapor.php\n") . "\n";
}
