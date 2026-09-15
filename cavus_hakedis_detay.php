<?php
// =========================================================
// cavus_hakedis_detay.php — Tek Hakediş Detayı (Günlük İşçi, Faz 4)
//
// TASLAK ↔ KESİN döngüsü BURADA yönetilir: Yeniden Hesapla (taslak),
// Kesinleştir (sıkı kurallar — bkz. config/pdks_hakedis.php), Yeniden Aç
// (yalnız admin + zorunlu gerekçe). Kendi hesap SQL'ini YAZMAZ.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_hakedis('entitlements_view');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) { set_flash('error', 'Geçersiz hakediş.'); header('Location: cavus_hakedis.php'); exit; }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = trim($_POST['action'] ?? '');

    if ($action === 'hesapla') {
        require_pdks_hakedis('entitlements_view');
        $st = $pdo->prepare("SELECT session_id FROM foreman_daily_entitlements WHERE id = ?");
        $st->execute([$id]);
        $sid = (int)$st->fetchColumn();
        $sonuc = $sid ? pdks_hakedis_hesapla($sid, (int)$auth_user['id'], $pdo) : ['ok' => false, 'hata' => 'Hakediş bulunamadı.'];
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . (int)$sonuc['entitlement_id'] . '&ok=' . urlencode('Yeniden hesaplandı.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Hesaplanamadı.';
    } elseif ($action === 'finalize') {
        require_pdks_hakedis('entitlements_finalize');
        $stS = $pdo->prepare("SELECT session_id FROM foreman_daily_entitlements WHERE id = ?");
        $stS->execute([$id]);
        $sid = (int)$stS->fetchColumn();
        $eksikOnay = isset($_POST['eksik_cikis_onay']);
        $sonuc = $sid ? pdks_hakedis_finalize($sid, (int)$auth_user['id'], $eksikOnay, $pdo) : ['ok' => false, 'hata' => 'Hakediş bulunamadı.'];
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . (int)$sonuc['entitlement_id'] . '&ok=' . urlencode('Hakediş KESİNLEŞTİRİLDİ.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kesinleştirilemedi.';
    } elseif ($action === 'yeniden_ac') {
        require_pdks_hakedis('entitlements_finalize');   // sayfa düzeyi kapı; asıl yetki kontrolü fonksiyon içinde (yalnız is_admin())
        $sebep = trim((string)($_POST['sebep'] ?? ''));
        $sonuc = pdks_hakedis_yeniden_ac($id, $sebep, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . $id . '&ok=' . urlencode('Hakediş yeniden AÇILDI (taslağa döndü).'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Yeniden açılamadı.';
    }
}

$st = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE id = ?");
$st->execute([$id]);
$hakedis = $st->fetch();
if (!$hakedis) { set_flash('error', 'Hakediş bulunamadı.'); header('Location: cavus_hakedis.php'); exit; }

$satirlar = pdks_hakedis_satirlar($id, $pdo);
$ozetPuantaj = pdks_gunluk_oturum_ozet((int)$hakedis['session_id'], $pdo);
$stS = $pdo->prepare("SELECT status FROM daily_work_sessions WHERE id = ?");
$stS->execute([(int)$hakedis['session_id']]);
$oturumDurumRaw = (string)$stS->fetchColumn();
$puantajDurum = pdks_gunluk_oturum_durumu($oturumDurumRaw, (int)($ozetPuantaj['eksik_toplam'] ?? 0));

$basari = '';
if (empty($errors) && isset($_GET['ok'])) $basari = trim($_GET['ok']);

render_header('Hakediş Detayı');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>🧾 <?= h($hakedis['foreman_name_snapshot']) ?></h1>
    <div class="page-head-actions">
        <a href="cavus_hakedis.php" class="btn">← Hakediş Listesi</a>
        <a href="cavus_hakedis_yazdir.php?id=<?= (int)$id ?>" class="btn btn-ghost">🖨️ Yazdır</a>
    </div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>

<div class="table-wrap pc-only" style="margin:0 0 18px">
<table class="data-table">
<tbody>
<tr><th style="width:180px">Çavuş</th><td><?= h($hakedis['foreman_name_snapshot']) ?> (<?= h($hakedis['foreman_code_snapshot']) ?>)</td></tr>
<tr><th>Tarih</th><td><?= h(date('d.m.Y', strtotime($hakedis['work_date']))) ?></td></tr>
<tr><th>Depo</th><td><?= h($hakedis['depo'] ?: '—') ?></td></tr>
<tr><th>Puantaj Durumu</th><td><span class="pdks-badge pdks-badge-<?= h($puantajDurum['kod']) ?>"><?= h($puantajDurum['etiket']) ?></span>
    <?php if ((int)($ozetPuantaj['eksik_toplam'] ?? 0) > 0): ?> <span style="color:var(--warn)">⚠️ Bu mesai eksik çıkışla kapatılmıştır.</span><?php endif; ?></td></tr>
<tr><th>Hakediş Durumu</th><td><span class="pdks-badge <?= $hakedis['status'] === 'final' ? 'pdks-badge-tamamlandi' : 'pdks-badge-acik' ?>"><?= $hakedis['status'] === 'final' ? 'KESİN' : 'TASLAK' ?></span></td></tr>
<tr><th>Hesaplayan</th><td><?= h(date('d.m.Y H:i', strtotime($hakedis['calculated_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($hakedis['calculated_by_user_id'] !== null ? (int)$hakedis['calculated_by_user_id'] : null, $pdo)) ?></td></tr>
<tr><th>Kesinleştiren</th><td><?= $hakedis['finalized_at'] ? h(date('d.m.Y H:i', strtotime($hakedis['finalized_at']))) . ' — ' . h(pdks_gunluk_kullanici_adi($hakedis['finalized_by_user_id'] !== null ? (int)$hakedis['finalized_by_user_id'] : null, $pdo)) : '—' ?></td></tr>
<tr><th>Notlar</th><td style="white-space:pre-wrap"><?= h($hakedis['notes'] ?: '—') ?></td></tr>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only" style="margin-bottom:18px">
<div class="pdks-card-item">
    <div class="pdks-row-sub">Tarih: <?= h(date('d.m.Y', strtotime($hakedis['work_date']))) ?><?= $hakedis['depo'] ? ' / ' . h($hakedis['depo']) : '' ?></div>
    <div class="pdks-row-sub">Puantaj: <span class="pdks-badge pdks-badge-<?= h($puantajDurum['kod']) ?>"><?= h($puantajDurum['etiket']) ?></span></div>
    <div class="pdks-row-sub">Hakediş: <span class="pdks-badge <?= $hakedis['status'] === 'final' ? 'pdks-badge-tamamlandi' : 'pdks-badge-acik' ?>"><?= $hakedis['status'] === 'final' ? 'KESİN' : 'TASLAK' ?></span></div>
    <?php if ((int)($ozetPuantaj['eksik_toplam'] ?? 0) > 0): ?><div class="pdks-row-sub" style="color:var(--warn)">⚠️ Bu mesai eksik çıkışla kapatılmıştır.</div><?php endif; ?>
    <div class="pdks-row-sub">Hesaplayan: <?= h(date('d.m.Y H:i', strtotime($hakedis['calculated_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($hakedis['calculated_by_user_id'] !== null ? (int)$hakedis['calculated_by_user_id'] : null, $pdo)) ?></div>
    <?php if ($hakedis['finalized_at']): ?><div class="pdks-row-sub">Kesinleştiren: <?= h(date('d.m.Y H:i', strtotime($hakedis['finalized_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($hakedis['finalized_by_user_id'] !== null ? (int)$hakedis['finalized_by_user_id'] : null, $pdo)) ?></div><?php endif; ?>
    <?php if ($hakedis['notes']): ?><div class="pdks-row-sub">Not: <?= h($hakedis['notes']) ?></div><?php endif; ?>
</div>
</div>

<h2 style="font-size:1.05rem">Hakediş Kalemleri</h2>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>İşçi Tipi</th><th>Kişi Sayısı</th><th>Birim Fiyat</th><th>Tutar</th></tr></thead>
<tbody>
<?php foreach ($satirlar as $sl): ?>
<tr>
    <td class="pdks-row-name"><?= h($sl['worker_type_name_snapshot']) ?></td>
    <td><?= (int)$sl['worker_count'] ?></td>
    <td><?= h(number_format((float)$sl['unit_rate'], 2, ',', '.')) ?></td>
    <td><strong><?= h(number_format((float)$sl['line_total'], 2, ',', '.')) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
    <td colspan="3" style="text-align:right;font-weight:700">TOPLAM</td>
    <td style="font-weight:800;font-size:1.1rem"><?= h(number_format((float)$hakedis['total_amount'], 2, ',', '.')) ?> <?= h($hakedis['currency']) ?></td>
</tr></tfoot>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($satirlar as $sl): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($sl['worker_type_name_snapshot']) ?></div>
            <div class="pdks-row-sub"><?= (int)$sl['worker_count'] ?> × <?= h(number_format((float)$sl['unit_rate'], 2, ',', '.')) ?></div>
        </div>
        <strong><?= h(number_format((float)$sl['line_total'], 2, ',', '.')) ?></strong>
    </div>
</div>
<?php endforeach; ?>
<div class="pdks-card-item" style="font-weight:800">
    <div class="pdks-card-top"><span>TOPLAM</span><span><?= h(number_format((float)$hakedis['total_amount'], 2, ',', '.')) ?> <?= h($hakedis['currency']) ?></span></div>
</div>
</div>

<?php if ($hakedis['status'] === 'draft'): ?>
<div class="card" style="padding:18px 20px;margin-top:20px">
    <h2 style="margin-top:0;font-size:1rem">Taslak İşlemleri</h2>
    <form method="post" style="display:inline-block;margin-right:10px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="hesapla">
        <button type="submit" class="btn">🔄 Yeniden Hesapla</button>
    </form>

    <?php if (pdks_hakedis_can('entitlements_finalize')): ?>
    <?php if ($oturumDurumRaw !== 'closed'): ?>
    <p class="muted" style="margin-top:10px">Mesai AÇIK — hakediş yalnız KAPALI mesai için kesinleştirilebilir.</p>
    <?php else: ?>
    <form method="post" style="margin-top:14px" onsubmit="return confirm('Bu hakedişi KESİNLEŞTİRMEK istediğinize emin misiniz? Kesinleşen tutar bir daha otomatik değişmez.');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="finalize">
        <?php if ((int)($ozetPuantaj['eksik_toplam'] ?? 0) > 0): ?>
        <p style="color:var(--warn);font-weight:600">⚠️ Bu mesai eksik çıkışla kapatılmıştır. Devam etmek istediğinizi açıkça onaylayın:</p>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-bottom:10px">
            <input type="checkbox" name="eksik_cikis_onay" value="1" required>
            Eksik çıkışa rağmen bu işçilerin hak edişini onaylıyorum.
        </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">✅ Kesinleştir</button>
    </form>
    <?php endif; ?>
    <?php else: ?>
    <p class="muted" style="margin-top:10px">Kesinleştirme için ticari fiyat yönetim yetkisi de gerekir.</p>
    <?php endif; ?>
</div>
<?php else: ?>
<?php if (function_exists('is_admin') && is_admin()): ?>
<div class="card" style="padding:18px 20px;margin-top:20px">
    <h2 style="margin-top:0;font-size:1rem">Yeniden Aç (yalnız sistem yöneticisi)</h2>
    <p class="muted" style="font-size:.85rem">Bu KESİN hakedişi taslağa geri açar — finansal geçmiş sessizce üzerine YAZILMAZ, yalnız açık bir gerekçeyle KONTROLLÜ olarak yeniden hesaplamaya alınır.</p>
    <form method="post" onsubmit="return confirm('Bu KESİN hakedişi taslağa geri açmak istediğinize emin misiniz?');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="yeniden_ac">
        <label>
            <span class="form-label">Gerekçe *</span>
            <textarea name="sebep" rows="2" required maxlength="500"></textarea>
        </label>
        <button type="submit" class="btn" style="margin-top:10px">🔓 Yeniden Aç</button>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
