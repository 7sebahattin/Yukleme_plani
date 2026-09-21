<?php
// =========================================================
// rapor_yazdir.php — Personel / Günlük İşçi Yönetim Raporu (Faz 7)
//
// raporlar.php'nin YAZDIRILABİLİR, SALT OKUNUR görünümü — MEVCUT filtreleri
// (dönem/çavuş/işçi tipi) AYNEN uygular, KENDİ agregasyon mantığını
// YAZMAZ: config/pdks_rapor.php'nin (Faz 6) paylaşılan bulk fonksiyonları
// REUSE edilir.
//
// ⚠ Güvenlik (görev talimatı madde 21 + 15): sayfa require_pdks_rapor() ile
// açılır — raporlar.php İLE AYNI yetki. Finansal bölüm AYRICA
// pdks_rapor_can('financial') (Faz 5'in attendance.foreman_accounts izni)
// ister — ?print=1 ile bu kontrol ATLANAMAZ, sunucu tarafında ZORUNLUDUR.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_rapor();

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$finansalYetki = pdks_rapor_can('financial');
$semaDurumu = pdks_rapor_sema_durumu($pdo);
$finansalGosterilebilir = $finansalYetki && $semaDurumu['hakedis'] && $semaDurumu['cari'];

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

$preset = trim($_GET['donem'] ?? 'bugun');
if (!array_key_exists($preset, pdks_rapor_presetler())) $preset = 'bugun';
$baslangicGirdi = trim($_GET['baslangic'] ?? '');
$bitisGirdi = trim($_GET['bitis'] ?? '');
$araligi = pdks_rapor_tarih_araligi($preset, $baslangicGirdi ?: null, $bitisGirdi ?: null);
$start = $araligi['start']; $end = $araligi['end'];

$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$tipId   = filter_var($_GET['tip'] ?? '', FILTER_VALIDATE_INT) ?: null;
$cavusAdi = null;
if ($cavusId !== null) {
    $stC = $pdo->prepare("SELECT name FROM foremen WHERE id = ?");
    $stC->execute([$cavusId]);
    $cavusAdi = $stC->fetchColumn() ?: null;
}

$kpi        = pdks_rapor_operasyonel_kpi($start, $end, $depo, $cavusId, $tipId, $pdo);
$tipDagilim = pdks_rapor_isci_tipi_dagilimi($start, $end, $depo, $cavusId, $pdo, $tipId);
$cavusOzeti = pdks_rapor_cavus_ozeti($start, $end, $depo, $cavusId, $tipId, $pdo);
$eksikler   = pdks_rapor_eksik_cikislar_araligi($start, $end, $depo, $cavusId, $pdo, $tipId);
$acikMesai  = pdks_rapor_acik_mesailer_araligi($start, $end, $depo, $cavusId, $pdo, $tipId);
$finansalKpi = []; $guncelBakiye = [];
if ($finansalGosterilebilir) {
    $finansalKpi  = pdks_rapor_finansal_kpi($start, $end, $depo, $cavusId, $pdo);
    $guncelBakiye = pdks_rapor_bakiye_toplu($depo, $cavusId, $pdo);
}

render_print_page_start('Yönetim Raporu', 'daily', 'summary', 'portrait', ['print_pdks.css']);
?>
<div class="print-sheet">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="<?= h('raporlar.php?' . http_build_query(array_filter(['donem' => $preset, 'baslangic' => $start, 'bitis' => $end, 'cavus' => $cavusId, 'tip' => $tipId], fn($v) => $v !== null && $v !== ''))) ?>" class="pr-btn">← Rapora Dön</a>
    </div>

    <?= render_print_header_html(
        'PERSONEL / GÜNLÜK İŞÇİ YÖNETİM RAPORU',
        h(date('d.m.Y', strtotime($start))) . ($start !== $end ? ' – ' . h(date('d.m.Y', strtotime($end))) : '')
            . ($depo !== '' ? ' · ' . h($depo) : '') . ($cavusAdi !== null ? ' · ' . h($cavusAdi) : ''),
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <h3 class="pr-section">Operasyonel Özet</h3>
    <div class="print-summary-row">
        <div class="print-summary-box"><div class="psb-label">Toplam Çalışan</div><div class="psb-value"><?= (int)$kpi['toplam_calisan'] ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Aktif Çavuş</div><div class="psb-value"><?= (int)$kpi['aktif_cavus'] ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Tamamlanan Mesai</div><div class="psb-value"><?= (int)$kpi['tamamlanan_mesai'] ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Eksik Çıkış</div><div class="psb-value"><?= (int)$kpi['eksik_cikis_mesai'] ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Açık Mesai</div><div class="psb-value"><?= (int)$kpi['acik_mesai'] ?></div></div>
    </div>

    <h3 class="pr-section">İşçi Tipi Dağılımı</h3>
    <table class="print-table">
        <thead><tr><th>İşçi Tipi</th><th>Katılım</th><th>Yüzde</th></tr></thead>
        <tbody>
        <?php foreach ($tipDagilim as $t): ?>
        <tr><td><?= h($t['ad']) ?></td><td class="num"><?= (int)$t['adet'] ?></td><td class="num"><?= h(number_format($t['yuzde'], 1, ',', '.')) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($finansalGosterilebilir && !empty($finansalKpi)): ?>
    <h3 class="pr-section">Finansal Özet</h3>
    <table class="print-table">
        <thead><tr><th>Para Birimi</th><th>Kesinleşmiş Hakediş</th><th>Yapılan Ödeme</th><th>Dönem Net Hareket</th><th>Güncel Bakiye</th></tr></thead>
        <tbody>
        <?php $tumPara = array_values(array_unique(array_merge(array_keys($finansalKpi), array_keys($guncelBakiye))));
        foreach ($tumPara as $cur):
            $f = $finansalKpi[$cur] ?? ['hakedis' => '0.00', 'odeme' => '0.00', 'net' => '0.00'];
            $b = $guncelBakiye[$cur] ?? ['bakiye' => '0.00', 'durum_etiket' => 'Hesap Kapalı'];
        ?>
        <tr>
            <td><?= h($cur) ?></td>
            <td class="num"><?= h(number_format((float)$f['hakedis'], 2, ',', '.')) ?></td>
            <td class="num"><?= h(number_format((float)$f['odeme'], 2, ',', '.')) ?></td>
            <td class="num"><?= h(number_format((float)$f['net'], 2, ',', '.')) ?></td>
            <td class="num"><?= h(number_format((float)$b['bakiye'], 2, ',', '.')) ?> (<?= h($b['durum_etiket']) ?>)</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3 class="pr-section">Çavuş Bazlı Özet</h3>
    <table class="print-table">
        <thead><tr><th>Çavuş</th><th>Çalışılan Gün</th><th>Toplam İşçi</th><th>Eksik Çıkış</th><?php if ($finansalGosterilebilir): ?><th>Güncel Bakiye</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($cavusOzeti as $c): $f = $c['foreman']; ?>
        <tr>
            <td><?= h($f['name']) ?></td>
            <td class="num"><?= (int)$c['calisilan_gun'] ?></td>
            <td class="num"><?= (int)$c['toplam_isci'] ?></td>
            <td class="num"><?= (int)$c['eksik_cikis'] ?></td>
            <?php if ($finansalGosterilebilir): ?>
            <td><?php if (empty($c['guncel_bakiye'])) echo '—'; else foreach ($c['guncel_bakiye'] as $cur => $b) echo h(number_format((float)$b['bakiye'], 2, ',', '.')) . ' ' . h($cur) . ' '; ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($cavusOzeti)): ?>
        <tr><td colspan="<?= $finansalGosterilebilir ? 5 : 4 ?>" class="pr-empty">Bu tarih/filtrelerde çavuş hareketi yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3 class="pr-section">İstisnalar</h3>
    <p class="pr-note">Eksik Çıkış: <strong><?= count($eksikler) ?></strong> · Açık Mesai (hâlâ içeride): <strong><?= count($acikMesai) ?></strong></p>
    <?php if (!empty($eksikler)): ?>
    <table class="print-table">
        <thead><tr><th>Tarih</th><th>Çavuş</th><th>Kart No</th><th>Tip</th><th>Giriş Saati</th></tr></thead>
        <tbody>
        <?php foreach ($eksikler as $e): ?>
        <tr>
            <td><?= h(date('d.m.Y', strtotime($e['tarih']))) ?></td>
            <td><?= h($e['cavus_adi']) ?></td>
            <td><?= h($e['card_no']) ?></td>
            <td><?= h($e['tip']) ?></td>
            <td><?= h(date('H:i', strtotime($e['giris_saat']))) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php render_print_page_end(); ?>
