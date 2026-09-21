<?php
// =========================================================
// cavus_odeme_yazdir.php — Çavuş Ödeme Dökümü (Faz 7)
//
// cavus_odeme.php'nin ödeme GEÇMİŞİNİN yazdırılabilir, SALT OKUNUR görünümü
// — ödeme kaydı/iptali BURADA YOK. pdks_cari_odeme_listesi()/
// pdks_cari_bakiye()'yi (Faz 5) REUSE eder, ikinci bir hesap YOK.
//
// ⚠ Güvenlik: require_pdks_cari('payments') — kaynak sayfayla (cavus_odeme.php)
// AYNI yetki.
// ⚠ İPTAL edilen ödemeler geçmişte GÖRÜNÜR KALIR (denetim izi) ama GEÇERLİ
// ÖDEME TOPLAMINA sayılmaz (görev talimatı madde 14) — toplam
// pdks_cari_bakiye()'nin KENDİSİNDEN (yalnız status='valid') gelir, CSV/
// ekran satırlarından yeniden toplanmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_cari('payments');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_cari_sayfa_kapisi($pdo);

$foremanId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT);
if (!$foremanId) { set_flash('error', 'Geçersiz çavuş.'); header('Location: cavus_odeme.php'); exit; }

$stF = $pdo->prepare("SELECT id, code, name FROM foremen WHERE id = ?");
$stF->execute([$foremanId]);
$cavus = $stF->fetch();
if (!$cavus) { set_flash('error', 'Çavuş bulunamadı.'); header('Location: cavus_odeme.php'); exit; }

$bakiyeler = pdks_cari_bakiye($foremanId, $pdo);
$odemeler = pdks_cari_odeme_listesi($foremanId, $pdo);

render_print_page_start('Çavuş Ödeme Dökümü', 'account', 'detail', 'portrait', ['print_pdks.css']);
?>
<div class="print-sheet">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="cavus_odeme.php?cavus=<?= (int)$foremanId ?>" class="pr-btn">← Ödeme Sayfasına Dön</a>
    </div>

    <?= render_print_header_html(
        'ÇAVUŞ ÖDEME DÖKÜMÜ',
        h($cavus['name']) . ' (' . h($cavus['code']) . ')',
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <?php if (empty($odemeler)): ?>
    <p style="text-align:center;color:#666;padding:20px">Bu çavuş için henüz ödeme kaydı yok.</p>
    <?php else: ?>
    <table class="print-table">
        <thead><tr><th>Tarih</th><th>Yöntem</th><th>Referans</th><th>Açıklama</th><th>Tutar</th><th>Para Birimi</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach ($odemeler as $o): ?>
        <tr>
            <td><?= h(date('d.m.Y', strtotime($o['payment_date']))) ?></td>
            <td><?= h(pdks_cari_odeme_yontem_etiketi($o['payment_method'])) ?></td>
            <td><?= h($o['reference_no'] ?: '—') ?></td>
            <td><?= h($o['description'] ?: '—') ?></td>
            <td class="num"><?= h(number_format((float)$o['amount'], 2, ',', '.')) ?></td>
            <td><?= h($o['currency']) ?></td>
            <td>
                <?= h(pdks_cari_odeme_durum_etiketi($o['status'])) ?>
                <?php if ($o['status'] === 'cancelled' && $o['cancellation_reason']): ?>
                <br><span style="font-size:.85em;color:#555">Gerekçe: <?= h($o['cancellation_reason']) ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 class="pr-section">Geçerli Ödeme Toplamı (İptal Edilenler Hariç)</h3>
    <table class="print-table" style="max-width:420px">
        <thead><tr><th>Para Birimi</th><th>Toplam Geçerli Ödeme</th></tr></thead>
        <tbody>
        <?php if (empty($bakiyeler)): ?>
        <tr><td colspan="2" class="pr-empty">Geçerli ödeme yok.</td></tr>
        <?php else: foreach ($bakiyeler as $cur => $b): ?>
        <tr><td><?= h($cur) ?></td><td class="num"><?= h(number_format((float)$b['odeme_toplam'], 2, ',', '.')) ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="print-signatures">
        <div class="print-sig-box">Hazırlayan<br><br><br>Ad Soyad / İmza</div>
        <div class="print-sig-box">Çavuş<br><br><br>Ad Soyad / İmza</div>
    </div>
</div>
<?php render_print_page_end(); ?>
