<?php
// =========================================================
// cavus_ekstre_yazdir.php — Çavuş Cari Hesap Ekstresi (Faz 7)
//
// cavus_ekstre.php'nin YAZDIRILABİLİR görünümü — KENDİ SQL'ini YAZMAZ,
// pdks_cari_ekstre()'yi (Faz 5) REUSE eder (ikinci bir bakiye hesabı YOK).
// Sprint Print-Arch-01 mimarisini REUSE eder (bkz. gunluk_puantaj_yazdir.php).
//
// ⚠ Güvenlik: require_pdks_cari('accounts') — kaynak sayfayla AYNI yetki.
// ⚠ Para birimleri ASLA karıştırılmaz — her para birimi KENDİ ayrı
// bölümünde, kendi TOPLAM/KALAN BAKİYE satırıyla basılır (görev talimatı
// madde 13/19).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_cari('accounts');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_cari_sayfa_kapisi($pdo);

$foremanId = filter_var($_GET['foreman_id'] ?? '', FILTER_VALIDATE_INT);
if (!$foremanId) { set_flash('error', 'Geçersiz çavuş.'); header('Location: cavus_cari.php'); exit; }

$stF = $pdo->prepare("SELECT id, code, name FROM foremen WHERE id = ?");
$stF->execute([$foremanId]);
$cavus = $stF->fetch();
if (!$cavus) { set_flash('error', 'Çavuş bulunamadı.'); header('Location: cavus_cari.php'); exit; }

$baslangic = trim($_GET['baslangic'] ?? '');
if ($baslangic !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $baslangic) || !strtotime($baslangic))) $baslangic = '';
$bitis = trim($_GET['bitis'] ?? '');
if ($bitis !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bitis) || !strtotime($bitis))) $bitis = '';

$ekstre = pdks_cari_ekstre($foremanId, $baslangic ?: null, $bitis ?: null, $pdo);

render_print_page_start('Çavuş Cari Hesap Ekstresi', 'account', 'detail', 'portrait', ['print_pdks.css']);
?>
<div class="print-sheet">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="cavus_ekstre.php?foreman_id=<?= (int)$foremanId ?>" class="pr-btn">← Ekstreye Dön</a>
    </div>

    <?= render_print_header_html(
        'ÇAVUŞ CARİ HESAP EKSTRESİ',
        h($cavus['name']) . ' (' . h($cavus['code']) . ')',
        ($baslangic !== '' || $bitis !== ''
            ? 'Tarih Aralığı: ' . ($baslangic !== '' ? h(date('d.m.Y', strtotime($baslangic))) : '—') . ' – ' . ($bitis !== '' ? h(date('d.m.Y', strtotime($bitis))) : '—')
            : 'Tüm Tarihler') . ' · Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <?php if (empty($ekstre)): ?>
    <p style="text-align:center;color:#666;padding:20px">Bu tarih aralığında hiçbir para biriminde hareket bulunamadı.</p>
    <?php else: foreach ($ekstre as $cur => $satirlar): if (empty($satirlar)) continue; ?>

    <h3 class="pr-section"><?= h($cur) ?> Hareketleri</h3>
    <table class="print-table">
        <thead><tr><th>Tarih</th><th>İşlem Türü</th><th>Belge / Referans</th><th>Açıklama</th><th>Hakediş / Borç Artışı</th><th>Ödeme / Azalış</th><th>Bakiye</th></tr></thead>
        <tbody>
        <?php $toplamArtis = 0.0; $toplamAzalis = 0.0; foreach ($satirlar as $s):
            $toplamArtis += (float)($s['artis'] ?? 0);
            $toplamAzalis += (float)($s['azalis'] ?? 0);
        ?>
        <tr>
            <td><?= h(date('d.m.Y', strtotime($s['tarih']))) ?></td>
            <td><?= h($s['tip_etiket']) ?></td>
            <td><?= h($s['belge']) ?></td>
            <td><?= h($s['aciklama']) ?></td>
            <td><?= $s['artis'] !== null ? h(number_format((float)$s['artis'], 2, ',', '.')) : '' ?></td>
            <td><?= $s['azalis'] !== null ? h(number_format((float)$s['azalis'], 2, ',', '.')) : '' ?></td>
            <td class="num"><?= h(number_format((float)$s['kosan_bakiye'], 2, ',', '.')) ?></td>
        </tr>
        <?php endforeach; $sonBakiye = (float)end($satirlar)['kosan_bakiye'];
            $durum = $sonBakiye > 0 ? 'borc' : ($sonBakiye < 0 ? 'avans' : 'kapali');
            $durumEtiket = match ($durum) {
                'borc'  => 'Çavuşa Borcumuz',
                'avans' => 'Çavuş Avansı / Fazla Ödeme',
                default => 'Hesap Kapalı',
            };
        ?>
        </tbody>
        <tfoot>
        <tr><td colspan="4" style="text-align:right">TOPLAM</td>
            <td class="num"><?= h(number_format($toplamArtis, 2, ',', '.')) ?></td>
            <td class="num"><?= h(number_format($toplamAzalis, 2, ',', '.')) ?></td>
            <td>—</td></tr>
        <tr><td colspan="6" style="text-align:right">KALAN BAKİYE — <?= h($durumEtiket) ?></td>
            <td class="num"><?= h(number_format(abs($sonBakiye), 2, ',', '.')) ?> <?= h($cur) ?></td></tr>
        </tfoot>
    </table>
    <?php endforeach; endif; ?>

    <div class="print-signatures">
        <div class="print-sig-box">Hazırlayan<br><br><br>Ad Soyad / İmza</div>
        <div class="print-sig-box">Çavuş<br><br><br>Ad Soyad / İmza</div>
    </div>
</div>
<?php render_print_page_end(); ?>
