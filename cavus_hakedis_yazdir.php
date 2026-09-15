<?php
// =========================================================
// cavus_hakedis_yazdir.php — Çavuş Hakediş Dökümü (Faz 7)
//
// cavus_hakedis_detay.php'nin YAZDIRILABİLİR, SALT OKUNUR görünümü — hiçbir
// taslak/kesinleştir/yeniden-aç eylemi BURADA YOK (bu sayfa yalnız GÖRÜNTÜLER).
// Sprint Print-Arch-01'in mevcut mimarisini REUSE eder (bkz.
// gunluk_puantaj_yazdir.php başlığı — AYNI gerekçe).
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
    </p>

    <table class="print-table">
        <thead><tr><th>İşçi Tipi</th><th>Kişi Sayısı</th><th>Birim Fiyat</th><th>Tutar</th></tr></thead>
        <tbody>
        <?php foreach ($satirlar as $sl): ?>
        <tr>
            <td><?= h($sl['worker_type_name_snapshot']) ?></td>
            <td><?= (int)$sl['worker_count'] ?></td>
            <td><?= h(number_format((float)$sl['unit_rate'], 2, ',', '.')) ?></td>
            <td><?= h(number_format((float)$sl['line_total'], 2, ',', '.')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="3" style="text-align:right">GENEL TOPLAM</td>
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
