<?php
// =========================================================
// gunluk_puantaj_liste_yazdir.php — Günlük Puantaj Listesi
// (Sprint Print-PDKS-01)
//
// gunluk_isci_puantaj.php'nin YAZDIRILABİLİR görünümü — MEVCUT filtreleri
// (tarih/çavuş/durum) AYNEN uygular ve KENDİ agregasyon mantığını YAZMAZ:
// pdks_gunluk_gun_ozeti() + pdks_gunluk_gun_listesi() +
// pdks_gunluk_eksik_cikislar() REUSE edilir.
//
// ⚠ gunluk_puantaj_yazdir.php ile KARIŞTIRMA: o TEK mesainin kart fişidir
// (session_id ile), bu ise GÜNÜN tüm çavuşlarının listesidir.
//
// ⚠ Güvenlik: require_pdks_gunluk('daily_reports') — gunluk_isci_puantaj.php
// İLE AYNI yetki. Sayfa SALT OKUNUR; manuel çıkış/düzeltme bağlantısı
// İÇERMEZ (kâğıtta tıklanamaz, yetki kapısı da burada tekrarlanmaz).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$tarih = trim($_GET['tarih'] ?? '');
if ($tarih === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) {
    $tarih = date('Y-m-d');
}
$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$durum_f = trim($_GET['durum'] ?? '');
if (!in_array($durum_f, ['acik', 'kapali', 'eksik_cikis'], true)) $durum_f = '';

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

$gunOzeti   = pdks_gunluk_gun_ozeti($tarih, $depo, $pdo);
$gunListesi = pdks_gunluk_gun_listesi($tarih, $depo, $cavusId, $durum_f !== '' ? $durum_f : null, $pdo);
$eksikler   = pdks_gunluk_eksik_cikislar($tarih, $depo, $cavusId, $pdo);

$cavusAdi = null;
if ($cavusId !== null) {
    $stC = $pdo->prepare("SELECT name FROM foremen WHERE id = ?");
    $stC->execute([$cavusId]);
    $cavusAdi = $stC->fetchColumn() ?: null;
}

$durumEtiketleri = ['acik' => 'Açık Mesai', 'kapali' => 'Kapalı Mesai', 'eksik_cikis' => 'Eksik Çıkış'];

// Listedeki (filtrelenmiş) satırların toplamı — üstteki gün özeti TÜM günü
// gösterir, bu ise basılan tablonun kendi toplamıdır; ikisi filtre altında
// bilerek farklı olabilir.
$lKadin = 0; $lErkek = 0; $lGiris = 0; $lCikis = 0; $lEksik = 0;
foreach ($gunListesi as $row) {
    $lKadin += (int)($row['giris']['Kadın'] ?? 0);
    $lErkek += (int)($row['giris']['Erkek'] ?? 0);
    $lGiris += (int)$row['giris_toplam'];
    $lCikis += (int)$row['cikis_toplam'];
    $lEksik += (int)$row['eksik_toplam'];
}

$mode = 'summary';
$orientation = print_orientation($mode, 10);

$geriUrl = 'gunluk_isci_puantaj.php?' . http_build_query(array_filter([
    'tarih' => $tarih, 'cavus' => $cavusId, 'durum' => $durum_f,
], fn($v) => $v !== null && $v !== ''));

render_print_page_start('Günlük Puantaj Listesi', 'daily', $mode, $orientation, ['print_pdks.css']);
?>
<div class="print-sheet">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="<?= h($geriUrl) ?>" class="pr-btn">← Puantaja Dön</a>
    </div>

    <?= render_print_header_html(
        'GÜNLÜK PUANTAJ LİSTESİ',
        date('d.m.Y', strtotime($tarih))
            . ($depo !== '' ? ' · ' . $depo : '')
            . ($cavusAdi !== null ? ' · ' . $cavusAdi : ' · Tüm çavuşlar')
            . ($durum_f !== '' ? ' · ' . $durumEtiketleri[$durum_f] : ''),
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <h3 class="pr-section">Gün Özeti</h3>
    <div class="print-summary-row">
        <div class="print-summary-box"><div class="psb-label">Aktif Çavuş</div><div class="psb-value"><?= (int)$gunOzeti['aktif_cavus'] ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Kadın İşçi</div><div class="psb-value"><?= (int)($gunOzeti['giris']['Kadın'] ?? 0) ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Erkek İşçi</div><div class="psb-value"><?= (int)($gunOzeti['giris']['Erkek'] ?? 0) ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Toplam İşçi</div><div class="psb-value"><?= (int)$gunOzeti['giris_toplam'] ?></div></div>
        <div class="print-summary-box psb-ok"><div class="psb-label">Tam Çıkış</div><div class="psb-value"><?= (int)$gunOzeti['tam_cikis'] ?></div></div>
        <div class="print-summary-box<?= (int)$gunOzeti['eksik_cikis'] > 0 ? ' psb-warn' : '' ?>"><div class="psb-label">Eksik Çıkış</div><div class="psb-value"><?= (int)$gunOzeti['eksik_cikis'] ?></div></div>
    </div>
    <?php if ($cavusId !== null || $durum_f !== ''): ?>
    <p class="pr-note">Gün özeti günün TAMAMINI gösterir; aşağıdaki tablo seçili filtreye göre süzülmüştür.</p>
    <?php endif; ?>

    <h3 class="pr-section">Çavuş Bazlı Mesai</h3>
    <table class="print-table">
        <thead>
        <tr>
            <th>Çavuş</th><th>Depo</th><th>Kadın</th><th>Erkek</th>
            <th>Toplam Giriş</th><th>Toplam Çıkış</th><th>Eksik</th>
            <th>İlk Giriş</th><th>Son Çıkış</th><th>Durum</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($gunListesi as $row): $s = $row['session']; ?>
        <tr>
            <td><?= h($s['foreman_name_snapshot']) ?></td>
            <td><?= h($s['depo'] ?: '—') ?></td>
            <td class="num"><?= (int)($row['giris']['Kadın'] ?? 0) ?></td>
            <td class="num"><?= (int)($row['giris']['Erkek'] ?? 0) ?></td>
            <td class="num"><?= (int)$row['giris_toplam'] ?></td>
            <td class="num"><?= (int)$row['cikis_toplam'] ?></td>
            <td class="num"><?= (int)$row['eksik_toplam'] ?></td>
            <td><?= $row['ilk_giris'] ? h(date('H:i', strtotime($row['ilk_giris']))) : '—' ?></td>
            <td><?= $row['son_cikis'] ? h(date('H:i', strtotime($row['son_cikis']))) : '—' ?></td>
            <td><?= h($row['durum']['etiket']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($gunListesi)): ?>
        <tr><td colspan="10" class="pr-empty">Bu tarih/filtrelerde mesai kaydı bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if (!empty($gunListesi)): ?>
        <tfoot>
        <tr>
            <td colspan="2">TOPLAM (<?= count($gunListesi) ?> mesai)</td>
            <td class="num"><?= $lKadin ?></td>
            <td class="num"><?= $lErkek ?></td>
            <td class="num"><?= $lGiris ?></td>
            <td class="num"><?= $lCikis ?></td>
            <td class="num"><?= $lEksik ?></td>
            <td>—</td><td>—</td><td>—</td>
        </tr>
        </tfoot>
        <?php endif; ?>
    </table>

    <h3 class="pr-section">Eksik Çıkışlar (<?= count($eksikler) ?>)</h3>
    <?php if (empty($eksikler)): ?>
    <p class="pr-note">Bu tarihte eksik çıkış yok.</p>
    <?php else: ?>
    <table class="print-table">
        <thead>
        <tr>
            <th>Çavuş</th><th>Kart No</th><th>Tip</th><th>Giriş Saati</th>
            <th>Mesai Durumu</th><th>Kayıt Türü</th><th>Kapanış Notu</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($eksikler as $e):
            $donemDurum = $e['donem_durumu'] ?? null;
            $donemRozet = $donemDurum ? pdks_gunluk_faz8a_donem_durumu((string)$donemDurum) : null;
        ?>
        <tr>
            <td><?= h($e['cavus_adi']) ?></td>
            <td><?= h($e['card_no']) ?></td>
            <td><?= h($e['tip']) ?></td>
            <td><?= h(date('H:i', strtotime($e['giris_saat']))) ?></td>
            <td><?= $e['oturum_durumu'] === 'closed' ? 'Kapalı' : 'Açık' ?><?= $e['oturum_kapali_mesaji'] ? ' — ' . h($e['oturum_kapali_mesaji']) : '' ?></td>
            <td><?= $donemRozet ? h($donemRozet['etiket']) : '—' ?></td>
            <td><?= h($e['kapanis_notu'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php render_print_page_end(); ?>
