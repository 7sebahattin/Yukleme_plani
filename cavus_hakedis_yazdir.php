<?php
// =========================================================
// cavus_hakedis_yazdir.php — Çavuş Hakediş Dökümü
//
// cavus_hakedis_detay.php'nin YAZDIRILABİLİR, SALT OKUNUR görünümü — hiçbir
// taslak/kesinleştir/yeniden-aç eylemi BURADA YOK (bu sayfa yalnız GÖRÜNTÜLER).
// Faz 8B şeması hazırsa Tam/Yarım/Fazla Mesai finansal snapshot'larını da
// basar; eski Faz 4/7 snapshot'larında mevcut dört kolonlu görünüm korunur.
//
// ⚠ Güvenlik: require_pdks_hakedis('entitlements_view') — kaynak sayfayla
// AYNI yetki, ?id= elle değiştirerek atlanamaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_hakedis('entitlements_view');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) { set_flash('error', 'Geçersiz hakediş.'); header('Location: cavus_hakedis.php'); exit; }

$st = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE id = ?");
$st->execute([$id]);
$hakedis = $st->fetch();
if (!$hakedis) { set_flash('error', 'Hakediş bulunamadı.'); header('Location: cavus_hakedis.php'); exit; }

$satirlar = pdks_hakedis_satirlar($id, $pdo);
$ozetPuantaj = pdks_gunluk_oturum_ozet((int)$hakedis['session_id'], $pdo);

// Faz 8B migrasyonu eski finans tablolarına additive kolonlar ekler. Yazdırma
// sayfası bu modülü ayrıca yüklemez; donmuş finansal satırların KENDİ şema
// işaretlerinden güvenli biçimde görünümü seçer. Böylece Faz 8B öncesi eski
// snapshot'lar da aynı sayfadan yazdırılabilir.
$faz8bHazir = array_key_exists('needs_recalculation', $hakedis);
if ($faz8bHazir && !empty($satirlar)) {
    $ilkSatir = $satirlar[0];
    $faz8bHazir = array_key_exists('attendance_class_snapshot', $ilkSatir)
        && array_key_exists('overtime_hours', $ilkSatir)
        && array_key_exists('overtime_total', $ilkSatir);
}

render_print_page_start('Çavuş Hakediş Dökümü', 'account', 'detail', 'portrait');
?>
<div class="print-sheet">
    <div class="rapor-actions no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button type="button" onclick="window.print()" style="padding:7px 13px;border:1px solid #1a73e8;border-radius:6px;background:#1a73e8;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer">🖨️ Yazdır</button>
        <a href="cavus_hakedis_detay.php?id=<?= (int)$id ?>" style="padding:7px 13px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1e293b;font-size:.85rem;font-weight:600;text-decoration:none">← Hakediş Detayına Dön</a>
    </div>

    <?= render_print_header_html(
        'ÇAVUŞ HAKEDİŞ DÖKÜMÜ',
        h($hakedis['foreman_name_snapshot']) . ' (' . h($hakedis['foreman_code_snapshot']) . ')' . ($hakedis['depo'] ? ' · ' . h($hakedis['depo']) : ''),
        'Tarih: ' . h(date('d.m.Y', strtotime($hakedis['work_date']))) . ' · Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <p style="margin:0 0 10px;font-size:.85rem">
        Durum: <strong><?= $hakedis['status'] === 'final' ? 'KESİNLEŞMİŞ HAKEDİŞ' : 'TASLAK (henüz kesinleşmemiş)' ?></strong>
        · Para Birimi: <strong><?= h($hakedis['currency']) ?></strong>
        <?php if ((int)($ozetPuantaj['eksik_toplam'] ?? 0) > 0): ?>
        <br>⚠️ Bu mesai eksik çıkışla kapatılmıştır<?= (int)$hakedis['missing_exit_ack'] === 1 ? ' — eksik çıkışa rağmen hakediş AÇIKÇA onaylanarak kesinleştirilmiştir.' : '.' ?>
        <?php endif; ?>
        <?php if ($faz8bHazir && $hakedis['status'] === 'draft' && !empty($hakedis['needs_recalculation'])): ?>
        <br>⚠️ Mesai değerlendirmesi değişti; bu TASLAK yeniden hesaplanmadan güncel kabul edilmemelidir.
        <?php endif; ?>
    </p>

    <table class="print-table">
        <thead><tr>
            <th>İşçi Tipi</th>
            <?php if ($faz8bHazir): ?><th>Mesai</th><th>Fazla Mesai</th><?php endif; ?>
            <th>Kişi Sayısı</th>
            <th><?= $faz8bHazir ? 'Temel Ücret' : 'Birim Fiyat' ?></th>
            <th>Tutar</th>
        </tr></thead>
        <tbody>
        <?php foreach ($satirlar as $sl): ?>
        <tr>
            <td><?= h($sl['worker_type_name_snapshot']) ?></td>
            <?php if ($faz8bHazir): ?>
            <td><?= h(($sl['attendance_class_snapshot'] ?? 'tam') === 'yarim' ? 'Yarım Mesai' : 'Tam Mesai') ?></td>
            <td>
                <?php
                $fmSaat = (int)($sl['overtime_hours'] ?? 0);
                $fmMod = (string)($sl['overtime_mode_snapshot'] ?? '');
                $fmBirim = (float)($sl['overtime_unit_rate'] ?? 0);
                $fmToplam = (float)($sl['overtime_total'] ?? 0);
                ?>
                <?php if ($fmSaat > 0 && $fmToplam > 0): ?>
                    <?= $fmSaat ?> saat · <?= h($fmMod === 'fixed' ? 'Sabit' : 'Saatlik') ?>
                    <?php if ($fmMod === 'hourly'): ?> · <?= h(number_format($fmBirim, 2, ',', '.')) ?>/saat<?php endif; ?>
                    · <?= h(number_format($fmToplam, 2, ',', '.')) ?> <?= h($hakedis['currency']) ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <?php endif; ?>
            <td><?= (int)$sl['worker_count'] ?></td>
            <td><?= h(number_format((float)$sl['unit_rate'], 2, ',', '.')) ?></td>
            <td><?= h(number_format((float)$sl['line_total'], 2, ',', '.')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="<?= $faz8bHazir ? '5' : '3' ?>" style="text-align:right">GENEL TOPLAM</td>
            <td><?= h(number_format((float)$hakedis['total_amount'], 2, ',', '.')) ?> <?= h($hakedis['currency']) ?></td>
        </tr></tfoot>
    </table>

    <div class="print-signatures">
        <div class="print-sig-box">Hazırlayan<br><br><br>Ad Soyad / İmza</div>
        <div class="print-sig-box">Çavuş<br><br><br>Ad Soyad / İmza</div>
        <div class="print-sig-box">Onay<br><br><br>Ad Soyad / İmza</div>
    </div>
</div>
<?php render_print_page_end(); ?>
