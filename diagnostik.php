<?php
// =========================================================
// diagnostik.php - Çıkma kayıt veritabanı durumu
// Kullanım: ?key=asya2024
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

if (($_GET['key'] ?? '') !== 'asya2024') {
    http_response_code(403);
    die('Erişim reddedildi. ?key= parametresi gerekli.');
}

$pdo = db();

// ── Sayaçlar ──
$total_cikma   = (int)$pdo->query("SELECT COUNT(*) FROM loading_records WHERE type='cikma'")->fetchColumn();
$total_yukleme = (int)$pdo->query("SELECT COUNT(*) FROM loading_records WHERE type='yukleme'")->fetchColumn();

$no_pallet_cikma = (int)$pdo->query(
    "SELECT COUNT(*) FROM loading_records r
     WHERE r.type='cikma'
       AND NOT EXISTS (SELECT 1 FROM loading_pallets p WHERE p.loading_record_id = r.id)"
)->fetchColumn();

$has_pallet_cikma = $total_cikma - $no_pallet_cikma;

$net_zero_cikma = (int)$pdo->query(
    "SELECT COUNT(*) FROM loading_records r
     WHERE r.type='cikma'
       AND (SELECT COALESCE(SUM(p.net_kg),0) FROM loading_pallets p WHERE p.loading_record_id = r.id) = 0"
)->fetchColumn();

// ── Palet istatistikleri ──
$pal_stats = $pdo->query(
    "SELECT COUNT(*) AS cnt,
            ROUND(SUM(p.brut_kg),2) AS tot_brut,
            ROUND(SUM(p.dara_kg),2) AS tot_dara,
            ROUND(SUM(p.net_kg),2)  AS tot_net,
            MIN(p.net_kg)  AS min_net,
            MAX(p.net_kg)  AS max_net
     FROM loading_pallets p
     JOIN loading_records r ON r.id = p.loading_record_id
     WHERE r.type = 'cikma'"
)->fetch();

// ── Kayıt listesi (son 30) ──
$records = $pdo->query(
    "SELECT r.id, r.firma, r.tarih, r.created_at,
            COUNT(p.id) AS pallets,
            ROUND(COALESCE(SUM(p.brut_kg),0),2) AS brut,
            ROUND(COALESCE(SUM(p.net_kg),0),2)  AS net
     FROM loading_records r
     LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
     WHERE r.type = 'cikma'
     GROUP BY r.id
     ORDER BY r.id DESC
     LIMIT 30"
)->fetchAll();

// ── Palet örnekleri (brut/net = 0 olanlar) ──
$zero_pallets = $pdo->query(
    "SELECT p.id, p.loading_record_id, p.brut_kg, p.dara_kg, p.net_kg, p.kasa_adeti
     FROM loading_pallets p
     JOIN loading_records r ON r.id = p.loading_record_id
     WHERE r.type = 'cikma' AND (p.brut_kg = 0 OR p.net_kg = 0)
     LIMIT 20"
)->fetchAll();

function yn(bool $b): string { return $b ? '<span style="color:green">✓ Evet</span>' : '<span style="color:red">✗ Hayır</span>'; }
function bad($v, $bad = 0): string {
    return $v == $bad
        ? '<strong style="color:red">'.$v.'</strong>'
        : '<strong style="color:green">'.$v.'</strong>';
}
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8">
<title>Diagnostik — Çıkma Kayıtları</title>
<style>
body{font-family:sans-serif;max-width:900px;margin:20px auto;padding:0 16px;font-size:14px}
h2{border-bottom:2px solid #ccc;padding-bottom:8px}
table{border-collapse:collapse;width:100%;margin-bottom:24px}
th,td{border:1px solid #ccc;padding:6px 10px;text-align:left}
th{background:#f0f0f0}
.ok{color:green}.err{color:red}.warn{color:#b45309}
.box{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:16px;margin-bottom:16px}
.stat{display:inline-block;background:#fff;border:1px solid #ccc;border-radius:6px;padding:10px 16px;margin:4px;min-width:130px;text-align:center}
.stat span{display:block;font-size:11px;color:#666;margin-bottom:4px}
.stat strong{font-size:24px}
</style>
</head><body>
<h2>🔍 Çıkma Kayıtları — Veritabanı Tanılama</h2>

<div class="box">
<h3>Genel İstatistikler</h3>
<div class="stat"><span>Toplam Çıkma Kaydı</span><strong><?= $total_cikma ?></strong></div>
<div class="stat"><span>Palet Var</span><strong class="ok"><?= $has_pallet_cikma ?></strong></div>
<div class="stat"><span>Paletsiz Kayıt</span><strong <?= $no_pallet_cikma > 0 ? 'class="err"' : 'class="ok"' ?>><?= $no_pallet_cikma ?></strong></div>
<div class="stat"><span>Net = 0 Kayıt</span><strong <?= $net_zero_cikma > 0 ? 'class="err"' : 'class="ok"' ?>><?= $net_zero_cikma ?></strong></div>
<div class="stat"><span>Toplam Yükleme</span><strong><?= $total_yukleme ?></strong></div>
</div>

<?php if ($pal_stats['cnt'] > 0): ?>
<div class="box">
<h3>Palet Toplamları (tüm çıkma paletleri)</h3>
<div class="stat"><span>Palet Sayısı</span><strong><?= $pal_stats['cnt'] ?></strong></div>
<div class="stat"><span>Toplam Brüt KG</span><strong><?= $pal_stats['tot_brut'] ?></strong></div>
<div class="stat"><span>Toplam Dara KG</span><strong><?= $pal_stats['tot_dara'] ?></strong></div>
<div class="stat"><span>Toplam Net KG</span><strong <?= $pal_stats['tot_net'] == 0 ? 'class="err"' : 'class="ok"' ?>><?= $pal_stats['tot_net'] ?></strong></div>
<div class="stat"><span>Min Net KG</span><strong><?= $pal_stats['min_net'] ?></strong></div>
<div class="stat"><span>Max Net KG</span><strong><?= $pal_stats['max_net'] ?></strong></div>
</div>
<?php else: ?>
<div class="box err"><strong>⚠️ Hiç çıkma paleti yok!</strong> import_cikmalar.php çalıştırılmamış olabilir.</div>
<?php endif; ?>

<?php if (!empty($zero_pallets)): ?>
<div class="box">
<h3 class="err">Brüt veya Net = 0 olan Paletler (ilk 20)</h3>
<table>
<tr><th>Palet ID</th><th>Kayıt ID</th><th>Kasa Adeti</th><th>Brüt KG</th><th>Dara KG</th><th>Net KG</th></tr>
<?php foreach ($zero_pallets as $p): ?>
<tr>
    <td><?= $p['id'] ?></td>
    <td><a href="record_view.php?id=<?= $p['loading_record_id'] ?>"><?= $p['loading_record_id'] ?></a></td>
    <td><?= $p['kasa_adeti'] ?></td>
    <td <?= $p['brut_kg'] == 0 ? 'class="err"' : '' ?>><?= $p['brut_kg'] ?></td>
    <td><?= $p['dara_kg'] ?></td>
    <td <?= $p['net_kg'] == 0 ? 'class="err"' : '' ?>><?= $p['net_kg'] ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<h3>Son 30 Çıkma Kaydı</h3>
<table>
<tr><th>ID</th><th>Firma</th><th>Tarih</th><th>Oluşturma</th><th>Palet Sayısı</th><th>Brüt KG</th><th>Net KG</th></tr>
<?php foreach ($records as $r): ?>
<tr <?= $r['pallets'] == 0 ? 'style="background:#fff3cd"' : ($r['net'] == 0 && $r['brut'] > 0 ? 'style="background:#f8d7da"' : '') ?>>
    <td><a href="record_view.php?id=<?= $r['id'] ?>"><?= $r['id'] ?></a></td>
    <td><?= htmlspecialchars($r['firma']) ?></td>
    <td><?= $r['tarih'] ? date('d.m.Y', strtotime($r['tarih'])) : '—' ?></td>
    <td><?= $r['created_at'] ? date('d.m.Y H:i', strtotime($r['created_at'])) : '—' ?></td>
    <td <?= $r['pallets'] == 0 ? 'class="warn"' : 'class="ok"' ?>><?= $r['pallets'] ?></td>
    <td><?= $r['brut'] ?></td>
    <td <?= $r['net'] == 0 ? 'class="err"' : '' ?>><?= $r['net'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<p style="color:#666;font-size:12px">Sarı satır: palet yok. Kırmızı satır: brüt > 0 ama net = 0 (bozuk veri).</p>

<p><a href="cikmalar.php">← Çıkmalar listesine dön</a> &nbsp;
   <a href="import_cikmalar.php">→ Import scriptine git</a></p>
</body></html>
