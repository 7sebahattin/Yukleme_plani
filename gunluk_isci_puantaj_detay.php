<?php
// =========================================================
// gunluk_isci_puantaj_detay.php — Tek Mesai Detayı (Günlük İşçi, Faz 3)
//
// Kart dökümü ve sayaçlar mevcut ortak fonksiyonlardan gelir. Faz 8H'nin
// yalnız admin yeniden açma eylemi de paylaşılan backend işlevini kullanır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_faz8h.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) { set_flash('error', 'Geçersiz mesai.'); header('Location: gunluk_isci_puantaj.php'); exit; }

$st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
$st->execute([$id]);
$oturum = $st->fetch();
if (!$oturum) { set_flash('error', 'Mesai bulunamadı.'); header('Location: gunluk_isci_puantaj.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'yeniden_ac') {
    csrf_check($_POST['csrf'] ?? null);
    $sonuc = pdks_gunluk_oturum_yeniden_ac((int)$id, (string)($_POST['sebep'] ?? ''), (int)$auth_user['id'], $pdo);
    set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? 'Mesai yeniden açıldı. Aynı oturumda taramaya devam edebilirsiniz.' : $sonuc['hata']);
    header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id);
    exit;
}

$aktifDepo = function_exists('active_depot') ? (active_depot() ?? '') : '';
$yenidenAcGoster = function_exists('is_admin') && is_admin()
    && $oturum['status'] === 'closed'
    && $oturum['work_date'] === date('Y-m-d')
    && $aktifDepo !== '' && $oturum['depo'] === $aktifDepo;
$kesinHakedis = null;
$hakedisKontrolHatasi = false;
if ($yenidenAcGoster) {
    try {
        $stEnt = $pdo->prepare("SELECT id FROM foreman_daily_entitlements WHERE session_id = ? AND status = 'final'");
        $stEnt->execute([(int)$id]);
        $kesinHakedis = $stEnt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        $hakedisKontrolHatasi = true;
    }
}

$ozet   = pdks_gunluk_oturum_ozet($id, $pdo);
$durum  = pdks_gunluk_oturum_durumu((string)$oturum['status'], (int)$ozet['eksik_toplam']);
$kartlar = pdks_gunluk_oturum_kartlari($id, $pdo);

render_header('Mesai Detayı');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>📅 <?= h($oturum['foreman_name_snapshot']) ?></h1>
    <div class="page-head-actions">
        <a href="gunluk_isci_puantaj.php" class="btn">← Günlük Puantaj</a>
        <?php if (is_admin() && $oturum['status'] === 'open' && $oturum['work_date'] === date('Y-m-d') && $oturum['depo'] === $aktifDepo): ?>
        <a href="gunluk_isci_giris_cikis.php" class="btn btn-primary">Giriş / Çıkışa Dön</a>
        <?php endif; ?>
        <a href="gunluk_puantaj_yazdir.php?id=<?= (int)$id ?>" class="btn btn-ghost">🖨️ Yazdır — Çavuş Gün Sonu Fişi</a>
    </div>
</div>

<?php if ($yenidenAcGoster): ?>
<div class="card" style="padding:18px 20px;margin-bottom:18px">
    <h2 style="margin-top:0;font-size:1rem">Mesaiyi Yeniden Aç · Yalnız Yönetici</h2>
    <?php if ($hakedisKontrolHatasi): ?>
    <p>Hakediş durumu doğrulanamadı. Mesaiyi yeniden açmadan önce muhasebe kaydını kontrol edin.</p>
    <?php elseif ($kesinHakedis): ?>
    <p>Bu mesai için kesinleşmiş hakediş bulunmaktadır. Önce hakedişi admin tarafından yeniden açın.</p>
    <a class="btn" href="cavus_hakedis_detay.php?id=<?= (int)$kesinHakedis ?>">Hakediş Detayı</a>
    <?php else: ?>
    <form method="post" onsubmit="return confirm('Bu mesaiyi aynı oturum kimliğiyle yeniden açmak istiyor musunuz?');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="yeniden_ac">
        <label><span class="form-label">Yeniden açma gerekçesi *</span>
            <textarea name="sebep" rows="2" maxlength="500" required placeholder="Örn. Yanlışlıkla kapatıldı; operasyon devam ediyor"></textarea>
        </label>
        <button type="submit" class="btn btn-primary" style="margin-top:10px">Mesaiyi Yeniden Aç</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="pdks-kiosk-counters" style="margin:0 0 18px">
    <h3><?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?><?= $oturum['depo'] ? ' — ' . h($oturum['depo']) : '' ?>
        · <span class="pdks-badge pdks-badge-<?= h($durum['kod']) ?>"><?= h($durum['etiket']) ?></span></h3>
    <div class="pdks-kiosk-counter-totals">
        <div class="pdks-kiosk-counter-box"><div class="lbl">Kadın</div><div class="val"><?= (int)($ozet['giris']['Kadın'] ?? 0) ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Erkek</div><div class="val"><?= (int)($ozet['giris']['Erkek'] ?? 0) ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Toplam Giriş</div><div class="val"><?= (int)$ozet['giris_toplam'] ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Toplam Çıkış</div><div class="val"><?= (int)$ozet['cikis_toplam'] ?></div></div>
        <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val"><?= (int)$ozet['eksik_toplam'] ?></div></div>
    </div>
</div>

<div class="table-wrap pc-only" style="margin-bottom:18px">
<table class="data-table">
<tbody>
<tr><th style="width:180px">Çavuş</th><td><?= h($oturum['foreman_name_snapshot']) ?> (<?= h($oturum['foreman_code_snapshot']) ?>)</td></tr>
<tr><th>Tarih</th><td><?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?></td></tr>
<tr><th>Depo</th><td><?= h($oturum['depo'] ?: '—') ?></td></tr>
<tr><th>Açılış</th><td><?= h(date('d.m.Y H:i', strtotime($oturum['opened_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($oturum['opened_by_user_id'] !== null ? (int)$oturum['opened_by_user_id'] : null, $pdo)) ?></td></tr>
<tr><th>Kapanış</th><td><?= $oturum['closed_at'] ? h(date('d.m.Y H:i', strtotime($oturum['closed_at']))) . ' — ' . h(pdks_gunluk_kullanici_adi($oturum['closed_by_user_id'] !== null ? (int)$oturum['closed_by_user_id'] : null, $pdo)) : '—' ?></td></tr>
<tr><th>İlk Giriş</th><td><?= $ozet['ilk_giris'] ? h(date('H:i', strtotime($ozet['ilk_giris']))) : '—' ?></td></tr>
<tr><th>Son Çıkış</th><td><?= $ozet['son_cikis'] ? h(date('H:i', strtotime($ozet['son_cikis']))) : '—' ?></td></tr>
<tr><th>Kapanış Notu</th><td><?= h($oturum['notes'] ?: '—') ?></td></tr>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only" style="margin-bottom:18px">
<div class="pdks-card-item">
    <div class="pdks-row-sub">Tarih: <?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?><?= $oturum['depo'] ? ' / ' . h($oturum['depo']) : '' ?></div>
    <div class="pdks-row-sub">Açılış: <?= h(date('d.m.Y H:i', strtotime($oturum['opened_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($oturum['opened_by_user_id'] !== null ? (int)$oturum['opened_by_user_id'] : null, $pdo)) ?></div>
    <?php if ($oturum['closed_at']): ?>
    <div class="pdks-row-sub">Kapanış: <?= h(date('d.m.Y H:i', strtotime($oturum['closed_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($oturum['closed_by_user_id'] !== null ? (int)$oturum['closed_by_user_id'] : null, $pdo)) ?></div>
    <?php endif; ?>
    <?php if ($oturum['notes']): ?>
    <div class="pdks-row-sub">Not: <?= h($oturum['notes']) ?></div>
    <?php endif; ?>
</div>
</div>

<h2 style="font-size:1.05rem">Kart Hareketleri</h2>

<?php if (empty($kartlar)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">🪪</span>
    <p>Bu mesaide henüz kart hareketi yok.</p>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Kart No</th>
    <th>Tip</th>
    <th>Mesai</th>
    <th>Giriş Saati</th>
    <th>Çıkış Saati</th>
    <th>Süre</th>
    <th>Durum</th>
</tr></thead>
<tbody>
<?php foreach ($kartlar as $k): ?>
<tr>
    <td class="pdks-uid"><?= h($k['card_no']) ?></td>
    <td><?= h($k['tip']) ?></td>
    <td class="muted"><?= isset($k['mesai_sinifi_etiket']) ? h($k['mesai_sinifi_etiket']) : '—' ?></td>
    <td><?= h(date('H:i', strtotime($k['giris_saat']))) ?></td>
    <td class="muted"><?= $k['cikis_saat'] ? h(date('H:i', strtotime($k['cikis_saat']))) : '—' ?></td>
    <td class="muted"><?= $k['cikis_saat'] ? h(pdks_gunluk_sure_etiketi($k['giris_saat'], $k['cikis_saat'])) : '—' ?></td>
    <td><span class="pdks-badge pdks-badge-<?= h($k['durum']['kod']) ?>"><?= h($k['durum']['etiket']) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($kartlar as $k): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($k['card_no']) ?> · <?= h($k['tip']) ?><?= isset($k['mesai_sinifi_etiket']) ? ' · ' . h($k['mesai_sinifi_etiket']) : '' ?></div>
            <div class="pdks-row-sub">Giriş <?= h(date('H:i', strtotime($k['giris_saat']))) ?> · Çıkış <?= $k['cikis_saat'] ? h(date('H:i', strtotime($k['cikis_saat']))) : '—' ?><?= $k['cikis_saat'] ? ' · ' . h(pdks_gunluk_sure_etiketi($k['giris_saat'], $k['cikis_saat'])) : '' ?></div>
        </div>
        <span class="pdks-badge pdks-badge-<?= h($k['durum']['kod']) ?>"><?= h($k['durum']['etiket']) ?></span>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php render_footer(); ?>
