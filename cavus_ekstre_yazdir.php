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
// ⚠ Fix 3 (Personel Takibi denetimi) — cavus_ekstre.php'deki İLE AYNI sebep:
// $baslangic filtreliyken koşan bakiye 0'dan başlar, bu yüzden "KALAN
// BAKİYE" GERÇEK güncel bakiye değil, filtreli dönemin net hareketidir.
$_ekstreBaslangicFiltreli = $baslangic !== '';
$_ekstreGercekBakiye = $_ekstreBaslangicFiltreli ? pdks_cari_bakiye($foremanId, $pdo) : [];

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

    <div style="text-align:right;margin:14px 0 2px"><span class="pr-tag"><?= h($cur) ?></span></div>
    <?php
        $kol = pdks_cari_kirilim_kolonlar($satirlar);
        $kBaslik = pdks_cari_kirilim_basliklar($kol);
        $kTop = pdks_cari_kirilim_degerleri(null, $kol, true);
        $nK = count($kBaslik);
        $sonBakiye = (float)end($satirlar)['kosan_bakiye'];
        $durumEtiket = $sonBakiye > 0 ? 'Alacak (Çavuşa Borcumuz)' : ($sonBakiye < 0 ? 'Borç (Çavuş Avansı / Fazla Ödeme)' : 'Hesap Kapalı');
    ?>
    <table class="print-table" style="font-size:<?= $nK > 6 ? '9' : '10.5' ?>px">
        <thead><tr><th>Tarih</th><th>İşlem Türü</th><th>Belge / Açıklama</th><?php foreach ($kBaslik as $kb): ?><th><?= h($kb['ad']) ?></th><?php endforeach; ?><th>Alacak</th><th>Borç</th><th>Bakiye</th></tr></thead>
        <tbody>
        <?php foreach ($satirlar as $s): $kd = pdks_cari_kirilim_degerleri($s, $kol); [$bTutar, $bYon] = pdks_cari_kirilim_bakiye_yonu($s['kosan_bakiye']); ?>
        <tr>
            <td><?= h(date('d.m.Y', strtotime($s['tarih']))) ?></td>
            <td><?= h($s['tip_etiket']) ?></td>
            <td><?= h($s['belge']) ?><br><small><?= h($s['aciklama']) ?></small></td>
            <?php foreach ($kd as $i => $v): ?><td class="num"><?= $v === null ? '' : h(pdks_cari_kirilim_hucre_metni($v, $kBaslik[$i]['tur'])) ?></td><?php endforeach; ?>
            <td class="num"><?= $s['artis'] !== null ? h(number_format((float)$s['artis'], 2, ',', '.')) : '' ?></td>
            <td class="num"><?= $s['azalis'] !== null ? h(number_format((float)$s['azalis'], 2, ',', '.')) : '' ?></td>
            <td class="num"><?= h($bTutar) ?> <?= h($bYon) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr><td colspan="3" style="text-align:right">TOPLAM</td>
            <?php foreach ($kTop as $i => $v): ?><td class="num"><?= $v === null ? '' : h(pdks_cari_kirilim_hucre_metni($v, $kBaslik[$i]['tur'])) ?></td><?php endforeach; ?>
            <td class="num"><?= h(number_format($kol['toplam']['alacak_kurus'] / 100, 2, ',', '.')) ?></td>
            <td class="num"><?= h(number_format($kol['toplam']['borc_kurus'] / 100, 2, ',', '.')) ?></td>
            <td>—</td></tr>
        <tr><td colspan="<?= 5 + $nK ?>" style="text-align:right"><?= $_ekstreBaslangicFiltreli ? 'BU DÖNEMİN NET HAREKETİ' : ('KALAN BAKİYE — ' . h($durumEtiket)) ?></td>
            <td class="num"><?= h(number_format(abs($sonBakiye), 2, ',', '.')) ?> <?= h($cur) ?></td></tr>
        <?php if ($_ekstreBaslangicFiltreli):
            $_gercekKurus = (float)($_ekstreGercekBakiye[$cur]['bakiye'] ?? 0);
            $_gercekDurumEtiket = match (true) {
                $_gercekKurus > 0 => 'Alacak (Çavuşa Borcumuz)',
                $_gercekKurus < 0 => 'Borç (Çavuş Avansı / Fazla Ödeme)',
                default => 'Hesap Kapalı',
            };
        ?>
        <tr><td colspan="<?= 5 + $nK ?>" style="text-align:right">GÜNCEL BAKİYE (tüm zamanlar) — <?= h($_gercekDurumEtiket) ?></td>
            <td class="num"><?= h(number_format(abs($_gercekKurus), 2, ',', '.')) ?> <?= h($cur) ?></td></tr>
        <?php endif; ?>
        </tfoot>
    </table>
    <?php endforeach; endif; ?>
</div>
<?php render_print_page_end(); ?>
