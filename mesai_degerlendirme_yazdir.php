<?php
// =========================================================
// mesai_degerlendirme_yazdir.php — Mesai Değerlendirme Dökümü
// (Sprint Print-PDKS-01)
//
// mesai_degerlendirme.php'nin YAZDIRILABİLİR, SALT OKUNUR görünümü —
// KENDİ süre/sınıf mantığını YAZMAZ: pdks_faz8b_oturum_donemleri() +
// pdks_faz8b_oturum_ozeti() REUSE edilir (karar motoru tek yerdedir).
//
// ⚠ Güvenlik: require_pdks_hakedis('entitlements_finalize') — kaynak sayfa
// İLE AYNI yetki. Sayfa ?session_id= ile DOĞRUDAN açılabildiği için depo
// kontrolü (IDOR guard) burada da ZORUNLUDUR: depo A'daki bir kullanıcı
// id'yi elle değiştirip depo B'nin mesaisini BASAMAZ.
//
// Bu sayfa HİÇBİR YAZMA yapmaz — Tam/Yarım kararı yalnız kaynak ekrandan
// verilir (ikinci bir yazma yolu AÇILMAZ).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_hakedis('entitlements_finalize');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_faz8b_sayfa_kapisi($pdo);

$sessionId = filter_var($_GET['session_id'] ?? '', FILTER_VALIDATE_INT);
if (!$sessionId) { set_flash('error', 'Geçersiz mesai.'); header('Location: cavus_hakedis.php'); exit; }

$stS = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id=?");
$stS->execute([$sessionId]);
$oturum = $stS->fetch();
if (!$oturum) { set_flash('error', 'Mesai bulunamadı.'); header('Location: cavus_hakedis.php'); exit; }

if ($depoHata = pdks_gunluk_depo_kontrol((string)$oturum['depo'])) {
    forbidden($depoHata);
}

$donemler = pdks_faz8b_oturum_donemleri($sessionId, $pdo);
$ozet = pdks_faz8b_oturum_ozeti($sessionId, $pdo);
$normalDk = (int)($oturum['normal_work_minutes_snapshot'] ?? 540);

$sayimTam = 0; $sayimYarim = 0; $sayimBekleyen = 0; $fmOnayToplam = 0;
foreach ($donemler as $d) {
    $f = $d['faz8b'];
    if ($f['sinif_onayi_gerekli'] && !$f['etkin_sinif']) $sayimBekleyen++;
    elseif (($f['etkin_sinif'] ?? '') === 'yarim') $sayimYarim++;
    else $sayimTam++;
    if ($f['fazla_mesai_onay_saat'] !== null) $fmOnayToplam += (int)$f['fazla_mesai_onay_saat'];
}

$mode = 'detail';
$orientation = print_orientation($mode, 8);

$geriUrl = 'mesai_degerlendirme.php?session_id=' . (int)$sessionId;

render_print_page_start('Mesai Değerlendirme Dökümü', 'daily', $mode, $orientation, ['print_pdks.css']);
?>
<div class="print-sheet">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="<?= h($geriUrl) ?>" class="pr-btn">← Değerlendirmeye Dön</a>
    </div>

    <?= render_print_header_html(
        'MESAİ DEĞERLENDİRME DÖKÜMÜ',
        $oturum['foreman_name_snapshot']
            . ' · ' . date('d.m.Y', strtotime($oturum['work_date']))
            . ($oturum['depo'] ? ' · ' . $oturum['depo'] : ''),
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <h3 class="pr-section">Mesai Özeti</h3>
    <div class="print-summary-row">
        <div class="print-summary-box"><div class="psb-label">Dönem</div><div class="psb-value"><?= count($donemler) ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Tam Mesai</div><div class="psb-value"><?= $sayimTam ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Yarım Mesai</div><div class="psb-value"><?= $sayimYarim ?></div></div>
        <div class="print-summary-box<?= $sayimBekleyen > 0 ? ' psb-warn' : '' ?>"><div class="psb-label">Karar Bekleyen</div><div class="psb-value"><?= $sayimBekleyen ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Onaylı Fazla Mesai</div><div class="psb-value"><?= $fmOnayToplam ?> sa</div></div>
        <div class="print-summary-box"><div class="psb-label">Normal Mesai</div><div class="psb-value"><?= h(sprintf('%ds %02ddk', intdiv($normalDk, 60), $normalDk % 60)) ?></div></div>
    </div>
    <p class="pr-note">Normal mesai süresi bu oturum açılırken çavuşun ayarından dondurulmuştur; sabit bir başlangıç/bitiş saati yoktur, yalnız geçen süre esas alınır.</p>

    <h3 class="pr-section">Kart Bazlı Değerlendirme</h3>
    <table class="print-table">
        <thead>
        <tr>
            <th>Kart</th><th>Tip</th><th>Giriş</th><th>Çıkış</th><th>Süre</th>
            <th>Giriş Beyanı</th><th>Sistem / Muhasebe</th><th>Fazla Mesai</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($donemler as $d): $f = $d['faz8b']; ?>
        <tr>
            <td><?= h($d['card_no']) ?></td>
            <td><?= h($d['worker_type_name_snapshot']) ?></td>
            <td><?= h(date('H:i', strtotime($d['entry_time']))) ?></td>
            <td><?= $d['exit_time'] ? h(date('H:i', strtotime($d['exit_time']))) : '—' ?></td>
            <td><?= $f['toplam_dk'] === null ? '—' : h(sprintf('%ds %02ddk', intdiv((int)$f['toplam_dk'], 60), (int)$f['toplam_dk'] % 60)) ?></td>
            <td><?= h(match (($d['declared_attendance_class'] ?? '')) { 'auto' => 'Otomatik', 'yarim' => 'Yarım', default => 'Tam' }) ?></td>
            <td><?php
                if ($f['sinif_kaynak'] === 'otomatik') echo 'Otomatik Tam';
                elseif ($f['etkin_sinif']) echo 'Muhasebe: ' . h($f['etkin_sinif'] === 'yarim' ? 'Yarım' : 'Tam');
                else echo 'Karar bekliyor';
            ?></td>
            <td><?php
                if ((int)$f['fazla_mesai_saat'] <= 0) {
                    echo '—';
                } else {
                    echo 'Hesaplanan: ' . (int)$f['fazla_mesai_saat'] . ' sa';
                    if ($f['fazla_mesai_onay_saat'] !== null) echo ' · Onaylanan: ' . (int)$f['fazla_mesai_onay_saat'] . ' sa';
                    else echo ' · Onaylanmadı';
                }
            ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($donemler)): ?>
        <tr><td colspan="8" class="pr-empty">Bu oturumda mesai dönemi bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php render_print_page_end(); ?>
