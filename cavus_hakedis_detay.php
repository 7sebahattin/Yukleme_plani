<?php
// =========================================================
// cavus_hakedis_detay.php — Tek Hakediş Detayı
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_hakedis('entitlements_view');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) { set_flash('error', 'Geçersiz hakediş.'); header('Location: cavus_hakedis.php'); exit; }

$st = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE id=?");
$st->execute([$id]);
$hakedis = $st->fetch();
if (!$hakedis) { set_flash('error', 'Hakediş bulunamadı.'); header('Location: cavus_hakedis.php'); exit; }

// ⚠ Faz 9A / M-01 düzeltmesi: ?id= elle başka bir depoya ait bir hakedişe
// değiştirilebiliyordu — GÖRÜNTÜLEME dahil hesapla/finalize/yeniden_ac'tan
// ÖNCE, sayfanın tamamı için TEK kontrol noktası.
if ($depoHata = pdks_gunluk_depo_kontrol((string)($hakedis['depo'] ?? ''))) {
    forbidden($depoHata);
}
$sid = (int)$hakedis['session_id'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'hesapla') {
        // ⚠ Faz 9A / M-04 düzeltmesi: taslak hesapla/yeniden hesapla bir
        // FİNANSAL YAZMADIR — değerlendirme/kesinleştirme İLE AYNI izne
        // hizalandı (bkz. cavus_hakedis.php'deki aynı düzeltme).
        require_pdks_hakedis('entitlements_finalize');
        $sonuc = $faz8bHazir ? pdks_faz8b_hakedis_hesapla($sid, (int)$auth_user['id'], $pdo) : pdks_hakedis_hesapla($sid, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . (int)$sonuc['entitlement_id'] . '&ok=' . urlencode('Yeniden hesaplandı.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Hesaplanamadı.';
    } elseif ($action === 'finalize') {
        require_pdks_hakedis('entitlements_finalize');
        $eksikOnay = isset($_POST['eksik_cikis_onay']);
        $sonuc = $faz8bHazir ? pdks_faz8b_hakedis_finalize($sid, (int)$auth_user['id'], $eksikOnay, $pdo) : pdks_hakedis_finalize($sid, (int)$auth_user['id'], $eksikOnay, $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . (int)$sonuc['entitlement_id'] . '&ok=' . urlencode('Hakediş KESİNLEŞTİRİLDİ.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kesinleştirilemedi.';
    } elseif ($action === 'yeniden_ac') {
        require_pdks_hakedis('entitlements_finalize');
        $sebep = trim((string)($_POST['sebep'] ?? ''));
        $sonuc = pdks_hakedis_yeniden_ac($id, $sebep, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . $id . '&ok=' . urlencode('Hakediş yeniden AÇILDI (taslağa döndü).'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Yeniden açılamadı.';
    }
}

$satirlar = pdks_hakedis_satirlar($id, $pdo);
$ozetPuantaj = pdks_gunluk_oturum_ozet((int)$hakedis['session_id'], $pdo);
$stS = $pdo->prepare("SELECT status FROM daily_work_sessions WHERE id=?");
$stS->execute([(int)$hakedis['session_id']]);
$oturumDurumRaw = (string)$stS->fetchColumn();
$puantajDurum = pdks_gunluk_oturum_durumu($oturumDurumRaw, (int)($ozetPuantaj['eksik_toplam'] ?? 0));
$faz8bOzet = $faz8bHazir ? pdks_faz8b_oturum_ozeti((int)$hakedis['session_id'], $pdo) : null;

$basari = '';
if (empty($errors) && isset($_GET['ok'])) $basari = trim((string)$_GET['ok']);

render_header('Hakediş Detayı');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>🧾 <?= h($hakedis['foreman_name_snapshot']) ?></h1>
    <div class="page-head-actions">
        <a href="cavus_hakedis.php" class="btn">← Hakediş Listesi</a>
        <?php if ($faz8bHazir && pdks_hakedis_can('entitlements_finalize')): ?><a href="mesai_degerlendirme.php?session_id=<?= (int)$hakedis['session_id'] ?>" class="btn">🧮 Mesai Değerlendir</a><?php endif; ?>
        <a href="cavus_hakedis_yazdir.php?id=<?= (int)$id ?>" class="btn btn-ghost">🖨️ Yazdır</a>
    </div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>
<?php if ($faz8bHazir && !empty($hakedis['needs_recalculation']) && $hakedis['status'] === 'draft'): ?><div class="flash flash-warning">Mesai değerlendirmesi değişti. Bu taslak yeniden hesaplanmalıdır.</div><?php endif; ?>

<div class="table-wrap pc-only" style="margin:0 0 18px">
<table class="data-table"><tbody>
<tr><th style="width:180px">Çavuş</th><td><?= h($hakedis['foreman_name_snapshot']) ?> (<?= h($hakedis['foreman_code_snapshot']) ?>)</td></tr>
<tr><th>Tarih</th><td><?= h(date('d.m.Y', strtotime($hakedis['work_date']))) ?></td></tr>
<tr><th>Depo</th><td><?= h($hakedis['depo'] ?: '—') ?></td></tr>
<tr><th>Puantaj Durumu</th><td><span class="pdks-badge pdks-badge-<?= h($puantajDurum['kod']) ?>"><?= h($puantajDurum['etiket']) ?></span><?php if ((int)($ozetPuantaj['eksik_toplam'] ?? 0) > 0): ?> <span style="color:var(--warn)">⚠️ Eksik çıkış var.</span><?php endif; ?></td></tr>
<?php if ($faz8bHazir): ?><tr><th>Mesai Değerlendirme</th><td><?= $faz8bOzet['tam_hazir'] ? '✓ Hazır' : '⚠️ Bekliyor ' . (int)$faz8bOzet['hazir'] . '/' . (int)$faz8bOzet['toplam'] ?></td></tr><?php endif; ?>
<tr><th>Hakediş Durumu</th><td><span class="pdks-badge <?= $hakedis['status'] === 'final' ? 'pdks-badge-tamamlandi' : 'pdks-badge-acik' ?>"><?= $hakedis['status'] === 'final' ? 'KESİN' : 'TASLAK' ?></span></td></tr>
<tr><th>Hesaplayan</th><td><?= h(date('d.m.Y H:i', strtotime($hakedis['calculated_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($hakedis['calculated_by_user_id'] !== null ? (int)$hakedis['calculated_by_user_id'] : null, $pdo)) ?></td></tr>
<tr><th>Kesinleştiren</th><td><?= $hakedis['finalized_at'] ? h(date('d.m.Y H:i', strtotime($hakedis['finalized_at']))) . ' — ' . h(pdks_gunluk_kullanici_adi($hakedis['finalized_by_user_id'] !== null ? (int)$hakedis['finalized_by_user_id'] : null, $pdo)) : '—' ?></td></tr>
<tr><th>Notlar</th><td style="white-space:pre-wrap"><?= h($hakedis['notes'] ?: '—') ?></td></tr>
</tbody></table>
</div>

<div class="pdks-cards mobile-only" style="margin-bottom:18px">
<div class="pdks-card-item">
    <div class="pdks-row-sub">Tarih: <?= h(date('d.m.Y', strtotime($hakedis['work_date']))) ?><?= $hakedis['depo'] ? ' / ' . h($hakedis['depo']) : '' ?></div>
    <div class="pdks-row-sub">Puantaj: <?= h($puantajDurum['etiket']) ?></div>
    <?php if ($faz8bHazir): ?><div class="pdks-row-sub">Mesai Değ.: <?= $faz8bOzet['tam_hazir'] ? 'Hazır' : 'Bekliyor ' . (int)$faz8bOzet['hazir'] . '/' . (int)$faz8bOzet['toplam'] ?></div><?php endif; ?>
    <div class="pdks-row-sub">Hakediş: <?= $hakedis['status'] === 'final' ? 'KESİN' : 'TASLAK' ?></div>
</div>
</div>

<h2 style="font-size:1.05rem">Hakediş Kalemleri</h2>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>İşçi Tipi</th><?php if ($faz8bHazir): ?><th>Mesai</th><th>FM</th><?php endif; ?><th>Kişi</th><th>Temel Ücret</th><th>Tutar</th></tr></thead>
<tbody>
<?php foreach ($satirlar as $sl): ?>
<tr>
    <td class="pdks-row-name"><?= h($sl['worker_type_name_snapshot']) ?></td>
    <?php if ($faz8bHazir): ?>
    <td><?= h(($sl['attendance_class_snapshot'] ?? 'tam') === 'yarim' ? 'Yarım' : 'Tam') ?></td>
    <td>
        <?php if ((int)($sl['overtime_hours'] ?? 0) > 0): ?>
            <?= (int)$sl['overtime_hours'] ?> saat · <?= h(($sl['overtime_mode_snapshot'] ?? '') === 'fixed' ? 'Sabit' : 'Saatlik') ?> · <?= h(number_format((float)($sl['overtime_total'] ?? 0),2,',','.')) ?>
        <?php else: ?>—<?php endif; ?>
    </td>
    <?php endif; ?>
    <td><?= (int)$sl['worker_count'] ?></td>
    <td><?= h(number_format((float)$sl['unit_rate'], 2, ',', '.')) ?></td>
    <td><strong><?= h(number_format((float)$sl['line_total'], 2, ',', '.')) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="<?= $faz8bHazir ? '5' : '3' ?>" style="text-align:right;font-weight:700">TOPLAM</td><td style="font-weight:800;font-size:1.1rem"><?= h(number_format((float)$hakedis['total_amount'],2,',','.')) ?> <?= h($hakedis['currency']) ?></td></tr></tfoot>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($satirlar as $sl): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top"><div class="pdks-card-meta"><div class="pdks-row-name"><?= h($sl['worker_type_name_snapshot']) ?></div><div class="pdks-row-sub"><?= (int)$sl['worker_count'] ?> × <?= h(number_format((float)$sl['unit_rate'],2,',','.')) ?><?= $faz8bHazir ? ' · ' . h(($sl['attendance_class_snapshot'] ?? 'tam') === 'yarim' ? 'Yarım' : 'Tam') : '' ?></div><?php if ($faz8bHazir && (int)($sl['overtime_hours'] ?? 0) > 0): ?><div class="pdks-row-sub">FM: <?= (int)$sl['overtime_hours'] ?> saat · <?= h(number_format((float)($sl['overtime_total'] ?? 0),2,',','.')) ?></div><?php endif; ?></div><strong><?= h(number_format((float)$sl['line_total'],2,',','.')) ?></strong></div>
</div>
<?php endforeach; ?>
<div class="pdks-card-item" style="font-weight:800"><div class="pdks-card-top"><span>TOPLAM</span><span><?= h(number_format((float)$hakedis['total_amount'],2,',','.')) ?> <?= h($hakedis['currency']) ?></span></div></div>
</div>

<?php if ($hakedis['status'] === 'draft'): ?>
<div class="card" style="padding:18px 20px;margin-top:20px">
    <h2 style="margin-top:0;font-size:1rem">Taslak İşlemleri</h2>
    <form method="post" style="display:inline-block;margin-right:10px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="hesapla">
        <button type="submit" class="btn">🔄 Yeniden Hesapla</button>
    </form>

    <?php if (pdks_hakedis_can('entitlements_finalize')): ?>
    <?php if ($oturumDurumRaw !== 'closed'): ?>
        <p class="muted" style="margin-top:10px">Mesai AÇIK — hakediş yalnız KAPALI mesai için kesinleştirilebilir.</p>
    <?php elseif ($faz8bHazir && !$faz8bOzet['tam_hazir']): ?>
        <p style="color:var(--warn);font-weight:600">Mesai değerlendirmesi tamamlanmadan hakediş kesinleştirilemez.</p>
        <a class="btn" href="mesai_degerlendirme.php?session_id=<?= (int)$hakedis['session_id'] ?>">🧮 Mesai Değerlendir</a>
    <?php else: ?>
    <form method="post" style="margin-top:14px" onsubmit="return confirm('Bu hakedişi KESİNLEŞTİRMEK istediğinize emin misiniz?');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="finalize">
        <?php if ((int)($ozetPuantaj['eksik_toplam'] ?? 0) > 0): ?>
        <p style="color:var(--warn);font-weight:600">⚠️ Eksik çıkış var. Açıkça onaylayın:</p>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-bottom:10px"><input type="checkbox" name="eksik_cikis_onay" value="1" required>Eksik çıkışa rağmen hakedişi onaylıyorum.</label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">✅ Kesinleştir</button>
    </form>
    <?php endif; ?>
    <?php else: ?><p class="muted" style="margin-top:10px">Kesinleştirme için ticari fiyat yönetim yetkisi de gerekir.</p><?php endif; ?>
</div>
<?php else: ?>
<?php if (function_exists('is_admin') && is_admin()): ?>
<div class="card" style="padding:18px 20px;margin-top:20px">
    <h2 style="margin-top:0;font-size:1rem">Yeniden Aç (yalnız sistem yöneticisi)</h2>
    <form method="post" onsubmit="return confirm('Bu KESİN hakedişi taslağa geri açmak istediğinize emin misiniz?');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="yeniden_ac">
        <label><span class="form-label">Gerekçe *</span><textarea name="sebep" rows="2" required maxlength="500"></textarea></label>
        <button type="submit" class="btn" style="margin-top:10px">🔓 Yeniden Aç</button>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
