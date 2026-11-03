<?php
// =========================================================
// mesai_degerlendirme.php — Faz 8B muhasebe mesai değerlendirmesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
require_pdks_hakedis('entitlements_finalize');
$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_faz8b_sayfa_kapisi($pdo);

$sessionId = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? '', FILTER_VALIDATE_INT);
if (!$sessionId) { set_flash('error', 'Geçersiz mesai.'); header('Location: cavus_hakedis.php'); exit; }

$stS = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id=?");
$stS->execute([$sessionId]);
$oturum = $stS->fetch();
if (!$oturum) { set_flash('error', 'Mesai bulunamadı.'); header('Location: cavus_hakedis.php'); exit; }

// ⚠ Faz 9A / M-01 düzeltmesi: liste ekranı aktif depoya göre filtreler ama
// bu sayfa ?session_id= ile DOĞRUDAN açılabiliyordu — depo A'da aktif bir
// kullanıcı, id'yi elle depo B'nin oturumuna değiştirerek onun mesai
// değerlendirmesini görebilir/değiştirebilirdi (IDOR). GÖRÜNTÜLEME dahil,
// hem GET hem POST için — sayfanın tamamı bu oturuma ait.
if ($depoHata = pdks_gunluk_depo_kontrol((string)$oturum['depo'])) {
    forbidden($depoHata);
}

$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string)($_POST['action'] ?? '')) === 'toplu_kaydet') {
    // ⚠ Sprint Toplu-Degerlendirme-01 — kapsam kullanıcıyla netleştirildi:
    // yalnız "Tam/Yarım seç" bekleyen dönemler işlenir (checkbox'lar zaten
    // yalnız o satırlarda render edilir, bkz. aşağısı). Fazla mesaiye BURADA
    // hiç dokunulmaz — pdks_faz8b_sure_karari()'nin YAPISAL kuralı gereği
    // "karar bekleyen" bir dönemde FM adayı olamaz (bkz. pdks_faz8b.php'deki
    // pdks_faz8b_toplu_degerlendirme_kaydet() docblock'u). Gerçek yazma
    // pdks_faz8b_degerlendirme_kaydet() üzerinden (İKİNCİ yol YOK).
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('entitlements_finalize');
    $topluKarar = trim((string)($_POST['attendance_decision'] ?? ''));
    $periodIdsRaw = $_POST['period_ids'] ?? [];
    $periodIds = is_array($periodIdsRaw) ? array_map('intval', $periodIdsRaw) : [];
    if (!in_array($topluKarar, ['tam', 'yarim'], true)) {
        $errors[] = 'Toplu işlem için Tam veya Yarım Mesai seçmelisiniz.';
    } elseif (empty($periodIds)) {
        $errors[] = 'Toplu işlem için en az bir dönem seçmelisiniz.';
    } else {
        $sonuc = pdks_faz8b_toplu_degerlendirme_kaydet($periodIds, $topluKarar, $sessionId, (int)$auth_user['id'], $pdo);
        if ($sonuc['hatalar']) {
            $errors = array_merge($errors, $sonuc['hatalar']);
        }
        if ($sonuc['basarili'] > 0 && !$sonuc['hatalar']) {
            $mesaj = $sonuc['basarili'] . ' dönem güncellendi.' . ($sonuc['atlandi'] > 0 ? ' (' . $sonuc['atlandi'] . ' dönem zaten karar gerektirmediği için atlandı.)' : '');
            header('Location: mesai_degerlendirme.php?session_id=' . $sessionId . '&ok=' . urlencode($mesaj));
            exit;
        }
        if ($sonuc['basarili'] === 0 && !$sonuc['hatalar']) {
            $errors[] = 'Seçilen dönemlerin hiçbiri güncellenmedi — hepsi zaten karar gerektirmiyor olabilir.';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string)($_POST['action'] ?? '')) === 'fm_toplu_kaydet') {
    // v493 — toplu fazla mesai onayı/reddi (gerçek yazma pdks_faz8b_degerlendirme_kaydet()).
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('entitlements_finalize');
    $fmKarar = trim((string)($_POST['fm_karar'] ?? ''));
    $fmIdsRaw = $_POST['fm_period_ids'] ?? [];
    $fmIds = is_array($fmIdsRaw) ? array_map('intval', $fmIdsRaw) : [];
    if (!in_array($fmKarar, ['onayla', 'reddet'], true)) {
        $errors[] = 'Toplu işlem için Onayla veya Reddet seçmelisiniz.';
    } elseif (empty($fmIds)) {
        $errors[] = 'Toplu işlem için en az bir satır seçmelisiniz.';
    } else {
        $sonuc = pdks_faz8b_toplu_fm_kaydet($fmIds, $fmKarar, $sessionId, (int)$auth_user['id'], $pdo);
        if ($sonuc['hatalar']) $errors = array_merge($errors, array_slice($sonuc['hatalar'], 0, 5));
        if ($sonuc['basarili'] > 0 && !$sonuc['hatalar']) {
            $mesaj = $sonuc['basarili'] . ' satırın fazla mesaisi ' . ($fmKarar === 'onayla' ? 'onaylandı.' : 'reddedildi.');
            header('Location: mesai_degerlendirme.php?session_id=' . $sessionId . '&ok=' . urlencode($mesaj));
            exit;
        }
        if ($sonuc['basarili'] === 0 && !$sonuc['hatalar']) $errors[] = 'Seçilen satırların hiçbirinde fazla mesai adayı yok.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string)($_POST['action'] ?? '')) === 'tekli_kaydet') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('entitlements_finalize');
    $periodId = filter_var($_POST['period_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $attendanceDecision = trim((string)($_POST['attendance_decision'] ?? '')) ?: null;
    // Faz 9C / UX-03: "onayla"/"reddet" YERİNE muhasebenin belirlediği
    // onaylanan FM SAATİ — geçersiz/boş girdi null'a düşer, backend hesaplanan
    // adayın gerekip gerekmediğine göre 0..aday aralığını doğrular.
    $overtimeApprovedHoursRaw = trim((string)($_POST['overtime_approved_hours'] ?? ''));
    $overtimeApprovedHours = $overtimeApprovedHoursRaw === '' ? null : filter_var($overtimeApprovedHoursRaw, FILTER_VALIDATE_INT);
    if ($overtimeApprovedHours === false) $overtimeApprovedHours = null;
    if (!$periodId) {
        $errors[] = 'Mesai dönemi seçilemedi.';
    } else {
        $stOwn = $pdo->prepare("SELECT id FROM daily_worker_work_periods WHERE id=? AND session_id=? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo));
        $stOwn->execute([$periodId, $sessionId]);
        if (!$stOwn->fetchColumn()) {
            $errors[] = 'Mesai dönemi bu oturuma ait değil.';
        } else {
            $sonuc = pdks_faz8b_degerlendirme_kaydet($periodId, $attendanceDecision, $overtimeApprovedHours, (int)$auth_user['id'], $pdo);
            if ($sonuc['ok']) {
                header('Location: mesai_degerlendirme.php?session_id=' . $sessionId . '&ok=' . urlencode('Değerlendirme kaydedildi.'));
                exit;
            }
            $errors[] = $sonuc['hata'] ?? 'Değerlendirme kaydedilemedi.';
        }
    }
}
if (!$errors && isset($_GET['ok'])) $success = trim((string)$_GET['ok']);

$donemler = pdks_faz8b_oturum_donemleri($sessionId, $pdo);
// ⚠ Fix 11 (Personel Takibi denetimi): "Sabit Toplam" FM modunda girilen SAAT
// SAYISI ödeme tutarını DEĞİŞTİRMEZ (bkz. config/pdks_faz8b.php'deki
// $fmToplamKurus = $fmMode === 'fixed' ? $fmBirimKurus : ($fmBirimKurus *
// $fmOnaySaat) — sabit modda çarpan YOK). Numara giren muhasebeci "3 saat
// onayladım, 5 değil" sanabiliyordu; tutar HER İKİSİNDE de AYNIYDI. Bu yüzden
// oran modunu ÖNCEDEN okuyup ekrana taşıyoruz — pdks_hakedis_oran_gecerli()
// AYNI otorite (config/pdks_faz8b.php'nin kendi hakediş hesabının okuduğu
// KAYNAK), burada İKİNCİ bir oran sorgusu İCAT edilmedi.
foreach ($donemler as &$d) {
    $d['faz8b']['fazla_mesai_oran_modu'] = null;
    if ((int)$d['faz8b']['fazla_mesai_saat'] > 0) {
        $oranD = pdks_hakedis_oran_gecerli(
            (int)$oturum['foreman_id'],
            (int)($d['worker_type_id_snapshot'] ?? 0),
            (string)($d['work_date_snapshot'] ?? $oturum['work_date']),
            $pdo
        );
        $d['faz8b']['fazla_mesai_oran_modu'] = $oranD['overtime_mode'] ?? null;
    }
}
unset($d);
$ozet = pdks_faz8b_oturum_ozeti($sessionId, $pdo);
$normalDkGosterim = (int)($oturum['normal_work_minutes_snapshot'] ?? 540);
if ($normalDkGosterim <= 0) $normalDkGosterim = 540;
// ⚠ Toplu işlem çubuğu/checkbox sütunu YALNIZ en az bir "karar bekliyor"
// dönem varsa render edilir — hepsi zaten Otomatik Tam ise sayfada
// tıklanacak hiçbir şey olmayan bir çubuk göstermenin anlamı yok.
$topluUygunSayisi = 0;
foreach ($donemler as $d) { if ($d['faz8b']['sinif_onayi_gerekli']) $topluUygunSayisi++; }
// v493: toplu FM — durum sayıları (bekliyor / onaylı / reddedilen) satır filtresi için.
$fmSayi = ['hepsi' => 0, 'bekliyor' => 0, 'onayli' => 0, 'red' => 0];
$fmDurumu = static function (array $f): string {
    if ((int)$f['fazla_mesai_saat'] <= 0) return '';
    if ($f['fazla_mesai_onay_saat'] === null) return 'bekliyor';
    return $f['fazla_mesai_durum'] === 'onayli' ? 'onayli' : 'red';
};
foreach ($donemler as $d) { $dk = $fmDurumu($d['faz8b']); if ($dk !== '') { $fmSayi['hepsi']++; $fmSayi[$dk]++; } }
// v299: fiyat döneminden gelen saat eşikleri (işçi tipi bazında) — eşikler
// sınıflandırıcının çıktısıdır, burada yeniden hesaplanmaz.
$esikOzet = [];
foreach ($donemler as $d) {
    $f = $d['faz8b'];
    $ozel = $f['tam_kaynak'] === 'fiyat' || $f['cift_dk'] !== null || (int)$f['fm_bas_dk'] !== (int)$f['tam_dk'] || $f['yarim_dk'] !== null;
    if (!$ozel) continue;
    $par = ['Tam ' . pdks_faz8b_dakika_etiket((int)$f['tam_dk'])];
    if ((int)$f['fm_bas_dk'] !== (int)$f['tam_dk']) $par[] = 'FM başı ' . pdks_faz8b_dakika_etiket((int)$f['fm_bas_dk']);
    if ($f['yarim_dk'] !== null) $par[] = 'Yarım ' . pdks_faz8b_dakika_etiket((int)$f['yarim_dk']) . ' (bilgi)';
    if ($f['cift_dk'] !== null) $par[] = 'Çift ' . pdks_faz8b_dakika_etiket((int)$f['cift_dk']) . ' / ' . number_format((float)$f['cift_ucret'], 2, ',', '.');
    $esikOzet[(string)$d['worker_type_name_snapshot']] = implode(' · ', $par);
}

render_header('Mesai Değerlendirme');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>
<div class="page-head">
    <h1>🧮 Mesai Değerlendirme — <?= h($oturum['foreman_name_snapshot']) ?></h1>
    <div class="page-head-actions">
        <a href="mesai_degerlendirme_yazdir.php?session_id=<?= (int)$sessionId ?>" class="btn" target="_blank" rel="noopener">🖨️ Yazdır</a>
        <a href="cavus_hakedis.php?tarih=<?= h($oturum['work_date']) ?>" class="btn">← Hakediş</a>
        <a href="gunluk_isci_puantaj_detay.php?id=<?= (int)$sessionId ?>" class="btn btn-ghost">📅 Puantaj</a>
    </div>
</div>

<?php if ($success !== ''): ?><div class="flash flash-success"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>

<div class="card" style="padding:16px 18px;margin-bottom:18px">
    <strong><?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?><?= $oturum['depo'] ? ' — ' . h($oturum['depo']) : '' ?></strong>
    <div class="pdks-row-sub" style="margin-top:6px">
        Bu mesai için normal günlük çalışma süresi: <strong><?= h(pdks_faz8b_dakika_etiket($normalDkGosterim)) ?></strong>
        (bu oturum açılırken çavuşun ayarından donduruldu) · sabit bir başlangıç/bitiş SAATİ YOKTUR, yalnız GEÇEN SÜRE
        sayılır · süre tamamlanınca otomatik Tam · 15 dk tolerans sonrası her başlayan saat FM.
    </div>
    <?php if ($esikOzet): ?>
    <div class="pdks-row-sub" style="margin-top:6px" id="mdEsikler">
        Fiyat dönemi saatleri (mesai süresinin yerine geçer):
        <?php $i = 0; foreach ($esikOzet as $tipAd => $metin): ?><?= $i++ ? ' — ' : ' ' ?><strong><?= h($tipAd) ?>:</strong> <?= h($metin) ?><?php endforeach; ?>.
        Çift yevmiye FM onayına bağlıdır: onaylı süre çift eşiğine ulaşırsa Tam yerine çift ücret, eşikten sonrası FM.
    </div>
    <?php endif; ?>
    <div class="pdks-row-sub" style="margin-top:6px">
        Hazır: <strong><?= (int)$ozet['hazir'] ?>/<?= (int)$ozet['toplam'] ?></strong>
        · Kısa/eksik mesai kararı bekleyen: <strong><?= (int)$ozet['bekleyen_sinif'] ?></strong>
        · Fazla mesai onayı bekleyen: <strong><?= (int)$ozet['bekleyen_fazla_mesai'] ?></strong>
    </div>
</div>

<?php if (!$donemler): ?>
<div class="pdks-empty"><p>Bu oturumda mesai dönemi bulunamadı.</p></div>
<?php else: ?>

<?php if ($fmSayi['hepsi'] > 0): ?>
<!-- v493 — TOPLU FAZLA MESAİ: filtre + seçerek onay/ret. Satır kutuları form="fmTopluForm" ile bağlanır
     (satırların kendi tekli formları olduğu için iç içe <form> açılamaz). -->
<form id="fmTopluForm" method="post" class="card" style="padding:12px 16px;margin-bottom:14px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
    <input type="hidden" name="action" value="fm_toplu_kaydet">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        <strong>⏱ Toplu fazla mesai</strong>
        <label class="muted" style="font-size:.85rem">Göster:
            <select id="fmFiltre" style="margin-left:4px">
                <option value="bekliyor">Onay bekleyen (<?= (int)$fmSayi['bekliyor'] ?>)</option>
                <option value="onayli">Onaylı (<?= (int)$fmSayi['onayli'] ?>)</option>
                <option value="red">Reddedilen (<?= (int)$fmSayi['red'] ?>)</option>
                <option value="hepsi">Fazla mesaisi olan hepsi (<?= (int)$fmSayi['hepsi'] ?>)</option>
                <option value="">Tüm satırlar</option>
            </select>
        </label>
        <label class="muted" style="font-size:.85rem">FM saati:
            <select id="fmSaatFiltre" style="margin-left:4px"><option value="">Hepsi</option></select>
        </label>
        <label style="font-size:.85rem;display:flex;align-items:center;gap:6px"><input type="checkbox" id="fmTumunuSec"> Görünenlerin tümünü seç</label>
        <span id="fmSayac" class="muted" style="font-size:.85rem">0 satır seçili</span>
        <span style="flex:1"></span>
        <button type="submit" name="fm_karar" value="onayla" class="btn btn-sm btn-primary" id="fmOnaylaBtn" disabled>✅ Seçilenleri Onayla</button>
        <button type="submit" name="fm_karar" value="reddet" class="btn btn-sm" id="fmReddetBtn" disabled>❌ Seçilenleri Reddet</button>
    </div>
    <div class="pdks-row-sub" style="margin-top:6px">Onay = hesaplanan fazla mesai saatinin tamamı. Kısmi saat için satırdaki “Kaydet”i kullanın.</div>
</form>
<?php endif; ?>

<?php if ($topluUygunSayisi > 0): ?>
<!-- ── Toplu işlem (Sprint Toplu-Degerlendirme-01) ───────────
     Checkbox'lar tablo/kart satırlarının İÇİNDE ama bu <form>'un
     DIŞINDA yaşar — HTML'de <form> içinde <form> AÇILAMAZ (her satırın
     zaten kendi tekli-kaydet formu var). Bunun yerine input'lar
     form="topluForm" özniteliğiyle BAĞLANIR — DOM'da nerede olurlarsa
     olsun bu forma dahil olurlar. GERÇEK yazma pdks_faz8b_degerlendirme_kaydet()
     üzerinden geçer (config/pdks_faz8b.php → pdks_faz8b_toplu_degerlendirme_kaydet),
     İKİNCİ bir yol AÇILMAZ. -->
<form id="topluForm" method="post" class="pdks-toplu-bar" hidden>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
    <input type="hidden" name="action" value="toplu_kaydet">
    <span id="topluSayac">0 satır seçili</span>
    <select name="attendance_decision" required>
        <option value="">Tam / Yarım seç</option>
        <option value="tam">Tam Mesai</option>
        <option value="yarim">Yarım Mesai</option>
    </select>
    <button type="submit" class="btn btn-sm btn-primary">Seçilenleri Kaydet</button>
</form>
<?php endif; ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <?php if ($topluUygunSayisi > 0): ?><th><input type="checkbox" id="topluTumunuSec" title="Tümünü Seç"></th><?php endif; ?>
    <th>Kart</th><th>Tip</th><th>Giriş</th><th>Çıkış</th><th>Süre</th><th>Giriş Beyanı</th><th>Sistem / Muhasebe</th><th>Fazla Mesai</th><th>İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($donemler as $d): $f = $d['faz8b']; $fmD = $fmDurumu($f); ?>
<tr data-fm-durum="<?= h($fmD) ?>" data-fm-saat="<?= (int)$f['fazla_mesai_saat'] ?>">
    <?php if ($topluUygunSayisi > 0): ?>
    <td><?php if ($f['sinif_onayi_gerekli']): ?><input type="checkbox" name="period_ids[]" value="<?= (int)$d['id'] ?>" form="topluForm" class="js-toplu-check"><?php endif; ?></td>
    <?php endif; ?>
    <td class="pdks-row-name"><?= h($d['card_no']) ?></td>
    <td><?= h($d['worker_type_name_snapshot']) ?></td>
    <td><?= h(date('H:i', strtotime($d['entry_time']))) ?></td>
    <td><?= $d['exit_time'] ? h(date('H:i', strtotime($d['exit_time']))) : '—' ?></td>
    <td><?= h(pdks_faz8b_sure_metni($f)) ?></td>
    <td><?= h(match (($d['declared_attendance_class'] ?? '')) { 'auto' => 'Otomatik', 'yarim' => 'Yarım', default => 'Tam' }) ?></td>
    <td>
        <?php if ($f['cift']): ?>
            <span class="pdks-badge pdks-badge-cift">Çift Yevmiye</span>
        <?php elseif ($f['sinif_kaynak'] === 'otomatik'): ?>
            <span class="pdks-badge pdks-badge-tamamlandi">Otomatik Tam</span>
        <?php elseif ($f['etkin_sinif']): ?>
            <span class="pdks-badge pdks-badge-acik">Muhasebe: <?= h(pdks_faz8b_sinif_etiketi($f['etkin_sinif'])) ?></span>
        <?php else: ?>
            <span class="pdks-badge pdks-badge-eksik_cikis">Karar bekliyor</span>
        <?php endif; ?>
        <?php if ($f['cift_aday'] && !$f['cift']): ?><div class="pdks-row-sub">Çift adayı (≥ <?= h(pdks_faz8b_dakika_etiket((int)$f['cift_dk'])) ?>) — FM onayına bağlı</div><?php endif; ?>
        <?php if ($f['yarim_alti']): ?><div class="pdks-row-sub">Yarım saatinin (<?= h(pdks_faz8b_dakika_etiket((int)$f['yarim_dk'])) ?>) altında</div><?php endif; ?>
    </td>
    <td>
        <?php if ((int)$f['fazla_mesai_saat'] <= 0): ?>—
        <?php else: ?>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-right:6px"><input type="checkbox" name="fm_period_ids[]" value="<?= (int)$d['id'] ?>" form="fmTopluForm" class="js-fm-check" aria-label="Toplu fazla mesai için seç"></label>
            Hesaplanan: <?= (int)$f['fazla_mesai_saat'] ?> saat
            <?php if ($f['fazla_mesai_onay_saat'] !== null): ?>
                · Onaylanan: <?= (int)$f['fazla_mesai_onay_saat'] ?> saat ·
                <?php if ($f['fazla_mesai_durum'] === 'onayli'): ?><strong>Onaylı</strong>
                <?php else: ?><strong>Reddedildi</strong><?php endif; ?>
            <?php else: ?>
                · <strong>Onay bekliyor</strong>
            <?php endif; ?>
            <?php if ($f['cift']): ?><div class="pdks-row-sub">Çift sonrası ödenecek FM: <?= (int)$f['odenecek_fm_saat'] ?> saat<?= ($f['fm_modu'] ?? '') === 'fixed' ? ' (sabit FM çift gününe eklenmez)' : '' ?></div><?php endif; ?>
        <?php endif; ?>
    </td>
    <td>
        <form method="post" style="min-width:230px">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="tekli_kaydet">
            <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
            <input type="hidden" name="period_id" value="<?= (int)$d['id'] ?>">
            <?php if ($f['sinif_onayi_gerekli']): ?>
            <select name="attendance_decision" required style="margin-bottom:6px">
                <option value="">Tam / Yarım seç</option>
                <option value="tam" <?= ($d['approved_attendance_class'] ?? '') === 'tam' ? 'selected' : '' ?>>Tam Mesai</option>
                <option value="yarim" <?= ($d['approved_attendance_class'] ?? '') === 'yarim' ? 'selected' : '' ?>>Yarım Mesai</option>
            </select>
            <?php endif; ?>
            <?php if ((int)$f['fazla_mesai_saat'] > 0):
                $fmAday = (int)$f['fazla_mesai_saat'];
                $fmOnaySaatMevcut = $f['fazla_mesai_onay_saat'];
                $fmVarsayilan = $fmOnaySaatMevcut !== null ? (int)$fmOnaySaatMevcut : $fmAday;
            ?>
            <?php if (($f['fazla_mesai_oran_modu'] ?? null) === 'fixed'): ?>
            <div style="font-size:.8rem;margin-bottom:6px">
                Fazla Mesai (Sabit Tutar — saat sayısı tutarı DEĞİŞTİRMEZ)<br>
                <label style="margin-right:10px"><input type="radio" name="overtime_approved_hours" value="<?= $fmAday ?>" <?= ($fmOnaySaatMevcut !== null && (int)$fmOnaySaatMevcut > 0) ? 'checked' : '' ?> required> Onayla</label>
                <label><input type="radio" name="overtime_approved_hours" value="0" <?= ($fmOnaySaatMevcut !== null && (int)$fmOnaySaatMevcut <= 0) ? 'checked' : '' ?> required> Reddet</label>
            </div>
            <?php else: ?>
            <label style="display:block;font-size:.8rem;margin-bottom:6px">
                Onaylanan FM Saati (Hesaplanan: <?= $fmAday ?>)
                <input type="number" name="overtime_approved_hours" min="0" max="<?= $fmAday ?>" step="1"
                       value="<?= $fmVarsayilan ?>" required style="max-width:100px">
            </label>
            <?php endif; ?>
            <?php endif; ?>
            <button class="btn btn-sm btn-primary" type="submit">Kaydet</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($donemler as $d): $f = $d['faz8b']; $fmD = $fmDurumu($f); ?>
<div class="pdks-card-item" data-fm-durum="<?= h($fmD) ?>" data-fm-saat="<?= (int)$f['fazla_mesai_saat'] ?>">
    <div class="pdks-row-name"><?= h($d['card_no']) ?> · <?= h($d['worker_type_name_snapshot']) ?></div>
    <div class="pdks-row-sub">Giriş <?= h(date('H:i', strtotime($d['entry_time']))) ?> · Çıkış <?= $d['exit_time'] ? h(date('H:i', strtotime($d['exit_time']))) : '—' ?></div>
    <div class="pdks-row-sub">Süre: <?= h(pdks_faz8b_sure_metni($f)) ?></div>
    <div class="pdks-row-sub">Beyan: <?= h(match (($d['declared_attendance_class'] ?? '')) { 'auto' => 'Otomatik', 'yarim' => 'Yarım', default => 'Tam' }) ?></div>
    <div class="pdks-row-sub">Finans: <?= $f['etkin_sinif'] ? h(pdks_faz8b_sinif_etiketi($f['etkin_sinif'])) : 'Karar bekliyor' ?><?= (int)$f['fazla_mesai_saat'] > 0 ? ' · FM Hesaplanan ' . (int)$f['fazla_mesai_saat'] . ' saat' . ($f['fazla_mesai_onay_saat'] !== null ? ' / Onaylanan ' . (int)$f['fazla_mesai_onay_saat'] . ' saat / ' . h($f['fazla_mesai_durum']) : ' / Onay bekliyor') : '' ?></div>
    <?php if ($f['cift']): ?><div class="pdks-row-sub">Çift Yevmiye · çift sonrası FM <?= (int)$f['odenecek_fm_saat'] ?> saat</div><?php elseif ($f['cift_aday']): ?><div class="pdks-row-sub">Çift adayı — FM onayına bağlı</div><?php endif; ?>
    <?php if ($f['yarim_alti']): ?><div class="pdks-row-sub">Yarım saatinin (<?= h(pdks_faz8b_dakika_etiket((int)$f['yarim_dk'])) ?>) altında</div><?php endif; ?>
    <?php if ((int)$f['fazla_mesai_saat'] > 0): ?>
    <label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:.85rem">
        <input type="checkbox" name="fm_period_ids[]" value="<?= (int)$d['id'] ?>" form="fmTopluForm" class="js-fm-check"> Toplu fazla mesai için seç
    </label>
    <?php endif; ?>
    <?php if ($topluUygunSayisi > 0 && $f['sinif_onayi_gerekli']): ?>
    <label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:.85rem">
        <input type="checkbox" name="period_ids[]" value="<?= (int)$d['id'] ?>" form="topluForm" class="js-toplu-check"> Toplu işlem için seç
    </label>
    <?php endif; ?>
    <form method="post" style="margin-top:8px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="tekli_kaydet">
        <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
        <input type="hidden" name="period_id" value="<?= (int)$d['id'] ?>">
        <?php if ($f['sinif_onayi_gerekli']): ?>
        <select name="attendance_decision" required>
            <option value="">Tam / Yarım seç</option>
            <option value="tam" <?= ($d['approved_attendance_class'] ?? '') === 'tam' ? 'selected' : '' ?>>Tam Mesai</option>
            <option value="yarim" <?= ($d['approved_attendance_class'] ?? '') === 'yarim' ? 'selected' : '' ?>>Yarım Mesai</option>
        </select>
        <?php endif; ?>
        <?php if ((int)$f['fazla_mesai_saat'] > 0):
            $fmAdayM = (int)$f['fazla_mesai_saat'];
            $fmOnaySaatMevcutM = $f['fazla_mesai_onay_saat'];
            $fmVarsayilanM = $fmOnaySaatMevcutM !== null ? (int)$fmOnaySaatMevcutM : $fmAdayM;
        ?>
        <?php if (($f['fazla_mesai_oran_modu'] ?? null) === 'fixed'): ?>
        <div style="font-size:.8rem;margin-top:6px">
            Fazla Mesai (Sabit Tutar — saat sayısı tutarı DEĞİŞTİRMEZ)<br>
            <label style="margin-right:10px"><input type="radio" name="overtime_approved_hours" value="<?= $fmAdayM ?>" <?= ($fmOnaySaatMevcutM !== null && (int)$fmOnaySaatMevcutM > 0) ? 'checked' : '' ?> required> Onayla</label>
            <label><input type="radio" name="overtime_approved_hours" value="0" <?= ($fmOnaySaatMevcutM !== null && (int)$fmOnaySaatMevcutM <= 0) ? 'checked' : '' ?> required> Reddet</label>
        </div>
        <?php else: ?>
        <label style="display:block;font-size:.8rem;margin-top:6px">
            Onaylanan FM Saati (Hesaplanan: <?= $fmAdayM ?>)
            <input type="number" name="overtime_approved_hours" min="0" max="<?= $fmAdayM ?>" step="1"
                   value="<?= $fmVarsayilanM ?>" required style="max-width:100px">
        </label>
        <?php endif; ?>
        <?php endif; ?>
        <button class="btn btn-sm btn-primary" type="submit" style="margin-top:8px">Kaydet</button>
    </form>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($topluUygunSayisi > 0): ?>
<script>
(function () {
    'use strict';
    var bar = document.getElementById('topluForm');
    var sayac = document.getElementById('topluSayac');
    var tumunuSec = document.getElementById('topluTumunuSec');
    if (!bar || !sayac) return;

    function guncelle() {
        var kutular = document.querySelectorAll('.js-toplu-check');
        var secili = 0;
        kutular.forEach(function (k) { if (k.checked) secili++; });
        bar.hidden = secili === 0;
        sayac.textContent = secili + ' satır seçili';
    }

    document.querySelectorAll('.js-toplu-check').forEach(function (kutu) {
        kutu.addEventListener('change', guncelle);
    });
    if (tumunuSec) {
        tumunuSec.addEventListener('change', function () {
            // ⚠ Yalnız o an GÖRÜNÜR olan katmandaki (masaüstü tablo VEYA mobil
            // kartlar — CSS pc-only/mobile-only) kutuları toggler. İkisi de
            // AYNI period_ids[] için ayrı DOM düğümleridir (bkz. sayfanın
            // üstündeki yorum); görünmeyen katmanı da işaretlemek gereksiz
            // tekrar submit'e yol açardı (zararsız ama anlamsız).
            document.querySelectorAll('.js-toplu-check').forEach(function (kutu) {
                if (kutu.offsetParent !== null) kutu.checked = tumunuSec.checked;
            });
            guncelle();
        });
    }

    bar.addEventListener('submit', function (e) {
        var karar = bar.querySelector('[name="attendance_decision"]').value;
        var seciliVar = document.querySelectorAll('.js-toplu-check:checked').length > 0;
        if (!karar || !seciliVar) {
            e.preventDefault();
            alert('Toplu kaydetmek için önce en az bir satır ve bir Tam/Yarım kararı seçin.');
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($fmSayi['hepsi'] > 0): ?>
<script>
(function () {
    'use strict';
    var form = document.getElementById('fmTopluForm');
    if (!form) return;
    var filtre = document.getElementById('fmFiltre');
    var saatSel = document.getElementById('fmSaatFiltre');
    var tumu = document.getElementById('fmTumunuSec');
    var sayac = document.getElementById('fmSayac');
    var btnO = document.getElementById('fmOnaylaBtn');
    var btnR = document.getElementById('fmReddetBtn');
    var satirlar = Array.prototype.slice.call(document.querySelectorAll('tr[data-fm-durum], .pdks-card-item[data-fm-durum]'));

    // FM saati seçenekleri verideki değerlerden
    var saatler = {};
    satirlar.forEach(function (s) { var n = parseInt(s.getAttribute('data-fm-saat'), 10) || 0; if (n > 0) saatler[n] = true; });
    Object.keys(saatler).map(Number).sort(function (a, b) { return a - b; }).forEach(function (n) {
        var o = document.createElement('option'); o.value = String(n); o.textContent = n + ' saat'; saatSel.appendChild(o);
    });

    function uygula() {
        var f = filtre.value, sa = saatSel.value;
        satirlar.forEach(function (s) {
            var d = s.getAttribute('data-fm-durum'), n = s.getAttribute('data-fm-saat');
            var goster = true;
            if (f === 'hepsi') goster = d !== '';
            else if (f !== '') goster = d === f;
            if (goster && sa !== '') goster = n === sa;
            s.style.display = goster ? '' : 'none';
            // gizlenen satırın seçimi kaybolsun (görünmeyeni yanlışlıkla göndermeyelim)
            if (!goster) { var k = s.querySelector('.js-fm-check'); if (k) k.checked = false; }
        });
        tumu.checked = false;
        guncelle();
    }
    function guncelle() {
        var n = document.querySelectorAll('.js-fm-check:checked').length;
        sayac.textContent = n + ' satır seçili';
        btnO.disabled = btnR.disabled = n === 0;
    }
    filtre.addEventListener('change', uygula);
    saatSel.addEventListener('change', uygula);
    tumu.addEventListener('change', function () {
        document.querySelectorAll('.js-fm-check').forEach(function (k) { if (k.offsetParent !== null) k.checked = tumu.checked; });
        guncelle();
    });
    document.querySelectorAll('.js-fm-check').forEach(function (k) { k.addEventListener('change', guncelle); });
    form.addEventListener('submit', function (e) {
        var n = document.querySelectorAll('.js-fm-check:checked').length;
        var btn = e.submitter, onay = btn && btn.value === 'onayla';
        if (!n) { e.preventDefault(); return; }
        if (!window.confirm(n + ' satırın fazla mesaisi ' + (onay ? 'ONAYLANACAK (hesaplanan saatin tamamı)' : 'REDDEDİLECEK (0 saat)') + '. Devam edilsin mi?')) e.preventDefault();
    });
    uygula();
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
