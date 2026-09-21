<?php
// =========================================================
// cavus_cari_yazdir.php — Çavuş Cari Hesap Listesi (Sprint Print-PDKS-01)
//
// cavus_cari.php'nin YAZDIRILABİLİR görünümü — MEVCUT filtreleri (arama/durum)
// AYNEN uygular ve KENDİ hesap SQL'ini YAZMAZ: pdks_cari_hesap_listesi()
// REUSE edilir. Bakiye canlı türetilir, ikinci bir defter YOKTUR.
//
// ⚠ Güvenlik: require_pdks_cari('accounts') — cavus_cari.php İLE AYNI yetki.
//
// ⚠ Para birimleri ASLA toplanmaz — hem özet hem tablo para birimi BAŞINA
// ayrı satırdır (CLAUDE.md kuralı: TRY ile USD toplanamaz).
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

$q = trim($_GET['q'] ?? '');
$durum_f = trim($_GET['durum'] ?? '');
if (!in_array($durum_f, ['borc', 'avans', 'kapali'], true)) $durum_f = '';

$hesaplar = pdks_cari_hesap_listesi($pdo);

// Filtre mantığı cavus_cari.php ile AYNI — çıktı ekranda görülenin aynısı olmalı.
if ($q !== '' || $durum_f !== '') {
    $hesaplar = array_values(array_filter($hesaplar, function ($h) use ($q, $durum_f) {
        if ($q !== '' && stripos($h['foreman']['name'], $q) === false && stripos($h['foreman']['code'], $q) === false) return false;
        if ($durum_f !== '') {
            $varMi = false;
            foreach ($h['bakiyeler'] as $b) { if ($b['durum'] === $durum_f) { $varMi = true; break; } }
            if (!$varMi) return false;
        }
        return true;
    }));
}

// Para birimi BAŞINA özet — kurlar birbirine KARIŞTIRILMAZ.
// Toplama KURUŞ (tam sayı) üzerinden yapılır: TL float toplamı yüzlerce
// satırda kuruş kayması üretir, kâğıda basılan tutar tutmaz.
$ozet = [];
$satirSayisi = 0;
foreach ($hesaplar as $h) {
    foreach ($h['bakiyeler'] as $cur => $b) {
        $satirSayisi++;
        if (!isset($ozet[$cur])) $ozet[$cur] = ['hakedis' => 0, 'duzeltme' => 0, 'odeme' => 0, 'bakiye' => 0];
        $ozet[$cur]['hakedis']  += (int)$b['hakedis_kurus'];
        $ozet[$cur]['duzeltme'] += (int)$b['duzeltme_kurus'];
        $ozet[$cur]['odeme']    += (int)$b['odeme_kurus'];
        $ozet[$cur]['bakiye']   += (int)$b['bakiye_kurus'];
    }
}

$durumEtiketleri = ['borc' => 'Çavuşa Borcumuz', 'avans' => 'Çavuş Avansı', 'kapali' => 'Hesap Kapalı'];

$mode = 'summary';
$orientation = print_orientation($mode, 7);

$geriUrl = 'cavus_cari.php?' . http_build_query(array_filter([
    'q' => $q, 'durum' => $durum_f,
], fn($v) => $v !== null && $v !== ''));

render_print_page_start('Çavuş Cari Hesap', 'account', $mode, $orientation, ['print_pdks.css']);
?>
<div class="print-sheet">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="<?= h($geriUrl) ?>" class="pr-btn">← Cariye Dön</a>
    </div>

    <?= render_print_header_html(
        'ÇAVUŞ CARİ HESAP LİSTESİ',
        ($q !== '' ? 'Arama: ' . $q . ' · ' : '')
            . ($durum_f !== '' ? $durumEtiketleri[$durum_f] : 'Tüm bakiyeler')
            . ' · ' . count($hesaplar) . ' çavuş',
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <?php if (!empty($ozet)): ?>
    <h3 class="pr-section">Para Birimi Bazlı Toplam</h3>
    <table class="print-table">
        <thead><tr><th>Para Birimi</th><th>Kesinleşmiş Hakediş</th><th>Düzeltme</th><th>Toplam Ödeme</th><th>Net Bakiye</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach ($ozet as $cur => $v):
            // İşaret sözleşmesi pdks_cari_bakiye() ile AYNI (bkz. config/pdks_cari.php):
            // bakiye = hakediş + düzeltme − ödeme; pozitif → çavuşa borcumuz,
            // negatif → çavuş avansı. Düzeltme kolonu kâğıtta aritmetiğin
            // kapanması için gösterilir (hakediş − ödeme tek başına tutmaz).
            $durumMetni = $v['bakiye'] > 0 ? 'Çavuşa Borcumuz' : ($v['bakiye'] < 0 ? 'Çavuş Avansı / Fazla Ödeme' : 'Hesap Kapalı');
        ?>
        <tr>
            <td><?= h($cur) ?></td>
            <td class="num"><?= h(number_format((float)pdks_hakedis_kurus_tl($v['hakedis']), 2, ',', '.')) ?></td>
            <td class="num"><?= h(number_format((float)pdks_hakedis_kurus_tl($v['duzeltme']), 2, ',', '.')) ?></td>
            <td class="num"><?= h(number_format((float)pdks_hakedis_kurus_tl($v['odeme']), 2, ',', '.')) ?></td>
            <td class="num"><?= h(number_format((float)pdks_hakedis_kurus_tl(abs($v['bakiye'])), 2, ',', '.')) ?></td>
            <td><?= h($durumMetni) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3 class="pr-section">Çavuş Bazlı Hesap Dökümü</h3>
    <table class="print-table">
        <thead>
        <tr>
            <th>Çavuş</th><th>Para Birimi</th><th>Kesinleşmiş Hakediş</th>
            <th>Toplam Ödeme</th><th>Bakiye</th><th>Son Hakediş</th><th>Son Ödeme</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($hesaplar as $h): $f = $h['foreman']; ?>
            <?php foreach ($h['bakiyeler'] as $cur => $b): ?>
            <tr>
                <td><?= h($f['name']) ?><?= ($f['code'] ?? '') !== '' ? ' (' . h($f['code']) . ')' : '' ?></td>
                <td><?= h($cur) ?></td>
                <td class="num"><?= h(number_format((float)$b['hakedis_toplam'], 2, ',', '.')) ?></td>
                <td class="num"><?= h(number_format((float)$b['odeme_toplam'], 2, ',', '.')) ?></td>
                <td><?= h($b['durum_etiket']) ?><?= $b['durum'] !== 'kapali' ? ': ' . h(number_format(abs((float)$b['bakiye']), 2, ',', '.')) : '' ?></td>
                <td><?= $b['son_hakedis_tarihi'] ? h(date('d.m.Y', strtotime($b['son_hakedis_tarihi']))) : '—' ?></td>
                <td><?= $b['son_odeme_tarihi'] ? h(date('d.m.Y', strtotime($b['son_odeme_tarihi']))) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if ($satirSayisi === 0): ?>
        <tr><td colspan="7" class="pr-empty">Bu filtrelerde hesap kaydı bulunamadı. (Yalnız en az bir KESİN hakedişi veya ödemesi olan çavuşlar listelenir.)</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php render_print_page_end(); ?>
