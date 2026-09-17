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
require_once __DIR__ . '/config/pdks_faz8j.php';
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

// ⚠ Faz 9A / M-01 düzeltmesi: bu sayfa ?id= ile DOĞRUDAN açılıyor —
// GÖRÜNTÜLEME dahil, oturumun aktif depoya ait olduğu SUNUCU tarafında
// doğrulanır. Aşağıdaki puantaj_duzeltme/puantaj_iptal/yeniden_ac zaten
// KENDİ depo kontrollerini de yapıyor (bkz. pdks_faz8j_aktif_depo_kontrol,
// pdks_gunluk_oturum_yeniden_ac) — bu, sayfanın TAMAMI (özet/kart listesi/
// iptal geçmişi) için erken ve tek bir kapıdır.
if ($depoHata = pdks_gunluk_depo_kontrol((string)$oturum['depo'])) {
    forbidden($depoHata);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'yeniden_ac') {
    csrf_check($_POST['csrf'] ?? null);
    $sonuc = pdks_gunluk_oturum_yeniden_ac((int)$id, (string)($_POST['sebep'] ?? ''), (int)$auth_user['id'], $pdo);
    set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? 'Mesai yeniden açıldı. Aynı oturumda taramaya devam edebilirsiniz.' : $sonuc['hata']);
    header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id);
    exit;
}

$aktifDepo = function_exists('active_depot') ? (active_depot() ?? '') : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['puantaj_duzeltme', 'puantaj_iptal'], true)) {
    csrf_check($_POST['csrf'] ?? null);
    if (($_POST['action'] ?? '') === 'puantaj_iptal') {
        $sonuc = pdks_faz8j_void((int)($_POST['period_id'] ?? 0), (int)$id, $aktifDepo, (string)($_POST['reason'] ?? ''), (int)$auth_user['id'], $pdo);
    } else {
        $sonuc = pdks_faz8j_duzelt($_POST + ['session_id' => $id, 'depo' => $aktifDepo], (int)$auth_user['id'], $pdo);
    }
    set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? 'Puantaj kaydı güncellendi.' : $sonuc['hata']);
    header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id); exit;
}

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
$faz8jHazir = function_exists('pdks_faz8j_sema_hazir') && pdks_faz8j_sema_hazir($pdo);
$iptaller = [];
if ($faz8jHazir && is_admin() && $oturum['depo'] === $aktifDepo) {
    $stVoid = $pdo->prepare("SELECT p.*, w.card_no, u.display_name FROM daily_worker_work_periods p JOIN worker_cards w ON w.id=p.worker_card_id LEFT JOIN users u ON u.id=p.voided_by_user_id WHERE p.session_id=? AND p.is_voided=1 ORDER BY p.voided_at DESC");
    $stVoid->execute([$id]); $iptaller = $stVoid->fetchAll();
}
$duzeltmeKartlar = $faz8jHazir && is_admin() ? $pdo->query("SELECT id, card_no FROM worker_cards WHERE status <> 'disabled' ORDER BY card_no")->fetchAll() : [];
// ⚠ Faz 9B / H-01 kapanışı: TEK paylaşılan politikadan (config/pdks_gunluk.php)
// gelir — backend'in (pdks_faz8j_desteklenen_tip(), AYNI politikayı SARAR)
// kabul ettiğiyle BİREBİR AYNI küme. UI'nin sunduğu bir tip backend'de
// asla reddedilmez.
$duzeltmeTipler = $faz8jHazir && is_admin() ? pdks_gunluk_desteklenen_tip_listele($pdo) : [];

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

<?php if (is_admin() && !$faz8jHazir): ?><div class="flash flash-error">Puantaj düzeltme merkezi için Faz 8J migrasyonu henüz çalıştırılmadı.</div><?php endif; ?>

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
    <?php if (is_admin() && $faz8jHazir && $oturum['depo'] === $aktifDepo): ?><th>İşlem</th><?php endif; ?>
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
    <?php if (is_admin() && $faz8jHazir && $oturum['depo'] === $aktifDepo): ?><td><button type="button" class="btn btn-sm" onclick="document.getElementById('edit<?= (int)$k['period_id'] ?>').showModal()">Düzenle</button><button type="button" class="btn btn-sm btn-danger" onclick="document.getElementById('void<?= (int)$k['period_id'] ?>').showModal()">Kaydı İptal Et</button></td><?php endif; ?>
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
    <?php if (is_admin() && $faz8jHazir && $oturum['depo'] === $aktifDepo): ?><div class="isk-card-form-actions"><button type="button" class="btn btn-sm" onclick="document.getElementById('edit<?= (int)$k['period_id'] ?>').showModal()">Düzenle</button><button type="button" class="btn btn-sm btn-danger" onclick="document.getElementById('void<?= (int)$k['period_id'] ?>').showModal()">Kaydı İptal Et</button></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php if (is_admin() && $faz8jHazir && $oturum['depo'] === $aktifDepo): foreach ($kartlar as $k): ?>
<dialog id="edit<?= (int)$k['period_id'] ?>" class="pm-dialog isk-card-modal"><div class="pm-header"><h2 class="pm-title">Çalışma Dönemini Düzenle</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div><form method="post" class="isk-card-modal-body"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="puantaj_duzeltme"><input type="hidden" name="period_id" value="<?= (int)$k['period_id'] ?>"><div class="pdks-form-grid"><label><span class="form-label">Kart</span><select name="worker_card_id"><?php foreach($duzeltmeKartlar as $c):?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===(int)$k['worker_card_id']?'selected':'' ?>><?=h($c['card_no'])?></option><?php endforeach;?></select></label><label><span class="form-label">İşçi tipi</span><select name="worker_type_id"><?php
    // ⚠ Faz 9B / görev talimatı §7: bu dönemin GEÇERLİ (snapshot) tipi
    // artık desteklenen listede yoksa (tarihsel/başka kurulumdan gelen bir
    // satır — ör. Faz 9B öncesi bir "FORKLIFT" ataması), SESSİZCE listedeki
    // İLK seçeneğe (bambaşka bir tipe) atlamak yerine gerçek değeri gösteren,
    // seçili ama devre dışı bir seçenek eklenir — kaydedilirse backend AÇIK
    // bir mesajla reddeder (pdks_faz8j_desteklenen_tip()), tip SESSİZCE
    // başka bir şeye DÖNÜŞTÜRÜLMEZ.
    $mevcutDestekliMi = in_array((int)$k['worker_type_id_snapshot'], array_column($duzeltmeTipler, 'id'), true);
    if (!$mevcutDestekliMi):
?><option value="<?= (int)$k['worker_type_id_snapshot'] ?>" selected disabled><?= h($k['tip'] ?? '') ?> (artık desteklenmiyor)</option><?php endif; ?><?php foreach($duzeltmeTipler as $t):?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id']===(int)$k['worker_type_id_snapshot']?'selected':'' ?>><?=h($t['name'])?></option><?php endforeach;?></select></label><label><span class="form-label">Giriş</span><input name="entry_date" type="date" value="<?=h(substr($k['giris_saat'],0,10))?>"><input name="entry_clock" type="time" value="<?=h(substr($k['giris_saat'],11,5))?>"></label><label><span class="form-label">Çıkış</span><input name="exit_date" type="date" value="<?=h($k['cikis_saat']?substr($k['cikis_saat'],0,10):'')?>"><input name="exit_clock" type="time" value="<?=h($k['cikis_saat']?substr($k['cikis_saat'],11,5):'')?>"></label><label class="span-2"><span class="form-label">Düzeltme nedeni *</span><textarea name="reason" maxlength="500" required></textarea></label><label class="span-2"><span class="form-label">Açıklama</span><textarea name="note" maxlength="1000"></textarea></label></div><div class="isk-card-form-actions"><button class="btn btn-primary">Kaydet</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div></form></dialog>
<dialog id="void<?= (int)$k['period_id'] ?>" class="pm-dialog isk-card-modal"><div class="pm-header"><h2 class="pm-title">Kaydı İptal Et</h2></div><form method="post" class="isk-card-modal-body"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="puantaj_iptal"><input type="hidden" name="period_id" value="<?= (int)$k['period_id'] ?>"><p><?=h($k['card_no'])?> kartının <?=h($k['giris_saat'])?>–<?=h($k['cikis_saat']?:'çıkış yok')?> çalışma kaydı puantajdan çıkarılacaktır. Ham kart okutma geçmişi silinmeyecektir.</p><label><span class="form-label">İptal nedeni *</span><textarea name="reason" maxlength="500" required></textarea></label><div class="isk-card-form-actions"><button class="btn btn-danger">Kaydı İptal Et</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div></form></dialog>
<?php endforeach; endif; ?>

<?php if ($iptaller): ?><h2>İptal Edilen Kayıtlar</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Kart No</th><th>Tip</th><th>Giriş</th><th>Çıkış</th><th>İptal nedeni</th><th>İptal eden</th><th>İptal zamanı</th></tr></thead><tbody><?php foreach($iptaller as $v): ?><tr><td><?=h($v['card_no'])?></td><td><?=h($v['worker_type_name_snapshot'])?></td><td><?=h($v['entry_time'])?></td><td><?=h($v['exit_time']?:'—')?></td><td><?=h($v['void_reason'])?></td><td><?=h($v['display_name']?:'—')?></td><td><?=h($v['voided_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif; ?>

<?php render_footer(); ?>
