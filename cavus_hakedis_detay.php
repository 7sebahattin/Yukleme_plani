<?php
// =========================================================
// cavus_hakedis_detay.php — Tek Hakediş Detayı
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz9d.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_hakedis('entitlements_view');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);
$faz9dHazir = pdks_faz9d_sema_hazir($pdo);

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
    } elseif ($action === 'duzeltme_ekle') {
        // Faz 9D / H-03: KESİN hakedişi DEĞİŞTİRMEZ — AYRI, imzalı bir
        // finansal katman ekler (bkz. config/pdks_faz9d.php).
        require_pdks_hakedis('entitlements_finalize');
        $yon = trim((string)($_POST['yon'] ?? ''));
        $tutarHam = trim((string)($_POST['tutar'] ?? ''));
        $sebep = trim((string)($_POST['sebep'] ?? ''));
        $sonuc = pdks_faz9d_duzeltme_ekle($id, $yon, $tutarHam, $sebep, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . $id . '&ok=' . urlencode('Düzeltme kaydedildi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Düzeltme kaydedilemedi.';
    } elseif ($action === 'duzeltme_ters_kayit') {
        require_pdks_hakedis('entitlements_finalize');
        $adjustmentId = filter_var($_POST['adjustment_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $sebep = trim((string)($_POST['sebep'] ?? ''));
        $sonuc = $adjustmentId
            ? pdks_faz9d_duzeltme_ters_kayit($adjustmentId, $sebep, (int)$auth_user['id'], $pdo)
            : ['ok' => false, 'hata' => 'Düzeltme bulunamadı.'];
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . $id . '&ok=' . urlencode('Düzeltme ters kayıtla nötrlendi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Ters kayıt yapılamadı.';
    }
}

$satirlar = pdks_hakedis_satirlar($id, $pdo);
$ozetPuantaj = pdks_gunluk_oturum_ozet((int)$hakedis['session_id'], $pdo);
$stS = $pdo->prepare("SELECT status FROM daily_work_sessions WHERE id=?");
$stS->execute([(int)$hakedis['session_id']]);
$oturumDurumRaw = (string)$stS->fetchColumn();
$puantajDurum = pdks_gunluk_oturum_durumu($oturumDurumRaw, (int)($ozetPuantaj['eksik_toplam'] ?? 0));
$faz8bOzet = $faz8bHazir ? pdks_faz8b_oturum_ozeti((int)$hakedis['session_id'], $pdo) : null;

// Faz 9D / H-03: yalnız KESİN (final) hakedişlerde anlamlıdır (bkz.
// pdks_faz9d_duzeltme_ekle()'nin kendi kapısı) — taslakta liste boş kalır.
$duzeltmeler = ($faz9dHazir && $hakedis['status'] === 'final') ? pdks_faz9d_duzeltmeler($id, $pdo) : [];
$duzeltmeNetKurus = ($faz9dHazir && $hakedis['status'] === 'final') ? pdks_faz9d_entitlement_net_kurus($id, $pdo) : 0;
$netHakedisKurus = pdks_hakedis_tl_kurus((string)$hakedis['total_amount']) + $duzeltmeNetKurus;

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
<?php elseif ($faz9dHazir): ?>
<div class="card" style="padding:18px 20px;margin-top:20px">
    <h2 style="margin-top:0;font-size:1rem">🧮 Hakediş Düzeltme / Mahsup</h2>
    <p class="muted" style="font-size:.85rem;margin-top:0">
        KESİN hakediş (<?= h(number_format((float)$hakedis['total_amount'], 2, ',', '.')) ?> <?= h($hakedis['currency']) ?>)
        BİR DAHA DEĞİŞMEZ — yanlış/eksik tespit edilen bir tutar bunun yerine AYRI, imzalı bir
        düzeltme olarak eklenir. Bu çavuşa yapılmış ödemeler HÂLÂ AYRI kayıtlardır (Çavuş Ödeme
        ekranından), buradan ETKİLENMEZ.
    </p>
    <div class="table-wrap pc-only" style="margin-bottom:14px">
    <table class="data-table"><tbody>
        <tr><th style="width:220px">Orijinal Kesin Hakediş</th><td><?= h(number_format((float)$hakedis['total_amount'], 2, ',', '.')) ?> <?= h($hakedis['currency']) ?></td></tr>
        <tr><th>Geçerli Düzeltmeler (net)</th><td><?= h(pdks_hakedis_kurus_tl($duzeltmeNetKurus)) ?> <?= h($hakedis['currency']) ?></td></tr>
        <tr><th>Net Düzeltilmiş Hakediş</th><td><strong><?= h(pdks_hakedis_kurus_tl($netHakedisKurus)) ?> <?= h($hakedis['currency']) ?></strong></td></tr>
    </tbody></table>
    </div>

    <?php if (empty($duzeltmeler)): ?>
    <p class="muted">Bu hakedişe henüz düzeltme eklenmemiş.</p>
    <?php else: ?>
    <div class="table-wrap pc-only" style="margin-bottom:14px">
    <table class="data-table">
    <thead><tr><th>Tarih</th><th>Tutar</th><th>Gerekçe</th><th>Kaydeden</th><th>Durum</th><th class="actions-col">İşlem</th></tr></thead>
    <tbody>
    <?php foreach ($duzeltmeler as $dz):
        $dzKurus = pdks_hakedis_tl_kurus((string)$dz['signed_amount']);
        $tersKayitMi = $dz['reversal_of_adjustment_id'] !== null;
    ?>
    <tr>
        <td class="muted"><?= h(date('d.m.Y H:i', strtotime($dz['created_at']))) ?></td>
        <td><strong style="color:<?= $dzKurus >= 0 ? 'var(--ok, #1f9d55)' : 'var(--danger)' ?>"><?= $dzKurus >= 0 ? '+' : '' ?><?= h(number_format((float)$dz['signed_amount'], 2, ',', '.')) ?> <?= h($dz['currency']) ?></strong><?= $tersKayitMi ? ' <span class="pdks-badge pdks-badge-pasif">Ters Kayıt</span>' : '' ?></td>
        <td><?= h($dz['reason']) ?></td>
        <td><?= h(pdks_gunluk_kullanici_adi($dz['created_by_user_id'] !== null ? (int)$dz['created_by_user_id'] : null, $pdo)) ?></td>
        <td>
            <?php if ($dz['reversed_at'] !== null): ?>
            <span class="pdks-badge pdks-badge-pasif">Ters Kayıtlı</span>
            <div class="pdks-row-sub">Gerekçe: <?= h($dz['reversal_reason']) ?></div>
            <?php else: ?>
            <span class="pdks-badge pdks-badge-aktif">Geçerli</span>
            <?php endif; ?>
        </td>
        <td class="actions-col">
            <?php if (!$tersKayitMi && $dz['reversed_at'] === null): ?>
            <details>
                <summary class="btn btn-sm">Ters Kayıt</summary>
                <form method="post" style="margin-top:8px">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="duzeltme_ters_kayit">
                    <input type="hidden" name="adjustment_id" value="<?= (int)$dz['id'] ?>">
                    <textarea name="sebep" rows="2" required placeholder="Ters kayıt gerekçesi *" style="width:220px"></textarea><br>
                    <button type="submit" class="btn btn-sm" onclick="return confirm('Bu düzeltmeyi ters kayıtla nötrlemek istediğinize emin misiniz?');">Onayla</button>
                </form>
            </details>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if (pdks_hakedis_can('entitlements_finalize')): ?>
    <h3 style="font-size:.95rem">Yeni Düzeltme Ekle</h3>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="duzeltme_ekle">
        <div class="pdks-form-grid">
            <label>
                <span class="form-label">Yön *</span>
                <select name="yon" required>
                    <option value="+">➕ Artır</option>
                    <option value="-">➖ Azalt / Mahsup</option>
                </select>
            </label>
            <label>
                <span class="form-label">Tutar (<?= h($hakedis['currency']) ?>) *</span>
                <input type="text" name="tutar" required inputmode="decimal" placeholder="ör. 2500 veya 2500,50">
            </label>
            <label class="span-2">
                <span class="form-label">Gerekçe *</span>
                <textarea name="sebep" rows="2" required maxlength="1000"></textarea>
            </label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:12px">Düzeltmeyi Kaydet</button>
    </form>
    <?php else: ?>
    <p class="muted" style="margin-top:10px">Düzeltme eklemek için ticari fiyat yönetim yetkisi de gerekir.</p>
    <?php endif; ?>
</div>

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
