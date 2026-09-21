<?php
// =========================================================
// cavus_toplu_dokum_detay_yazdir.php — Çavuş Kart Dökümü
// (Sprint Print-PDKS-02)
//
// cavus_toplu_dokum_detay.php'nin YAZDIRILABİLİR, SALT OKUNUR görünümü —
// KENDİ agregasyon mantığını YAZMAZ: pdks_rapor_cavus_kart_dokumu() REUSE
// edilir.
//
// ⚠ Güvenlik: require_pdks_rapor() — kaynak sayfa İLE AYNI yetki. Depo
// filtresi de AYNI (pdks_rapor_cavus_kart_dokumu() zaten depo'yu alır;
// oturum başka depoya aitse $session null döner, sayfa "bulunamadı" gösterir).
//
// ⚠ Okunan UID (ham donanım kimliği) kâğıda BASILMAZ — gunluk_puantaj_yazdir.php
// İLE AYNI ilke (bkz. o dosyanın smoke testindeki "NFC UID çavuş fişinde
// basılmıyor" kuralı): yalnız card_no basılır. Manuel çıkış geçmişi ve
// İşlem kolonu da kâğıtta tıklanamayacağı için basılmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/pdks_faz8e.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_rapor();

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sessionId = $sessionId === false ? 0 : (int)$sessionId;

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

$data = pdks_rapor_cavus_kart_dokumu($sessionId, $depo !== '' ? $depo : null, $pdo);
$session = $data['session'];
$cards = $data['cards'];

$eksik = 0;
foreach ($cards as $c) { if ($c['eksik_cikis']) $eksik++; }

$ay = trim((string)($_GET['ay'] ?? ''));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) {
    $ay = $session ? date('Y-m', strtotime($session['tarih'])) : date('Y-m');
}
$cavusId = filter_var($_GET['cavus'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cavusId = $cavusId === false ? null : (int)$cavusId;

$mode = 'detail';
$orientation = print_orientation($mode, 5);

$geriUrl = 'cavus_toplu_dokum_detay.php?' . http_build_query(array_filter([
    'session_id' => $sessionId, 'ay' => $ay, 'cavus' => $cavusId,
], fn($v) => $v !== null && $v !== ''));

render_print_page_start('Çavuş Kart Dökümü', 'account', $mode, $orientation, ['print_pdks.css']);
?>
<div class="print-sheet">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="<?= h($geriUrl) ?>" class="pr-btn">← Kart Dökümüne Dön</a>
    </div>

    <?php if (!$session): ?>
    <?= render_print_header_html('ÇAVUŞ KART DÖKÜMÜ', '', 'Yazdırma: ' . date('d.m.Y H:i')) ?>
    <p class="pr-note">Oturum bulunamadı veya seçili depoya ait değil.</p>
    <?php else: ?>

    <?= render_print_header_html(
        'ÇAVUŞ KART DÖKÜMÜ',
        h($session['cavus_adi']) . ' · ' . h(date('d.m.Y', strtotime($session['tarih']))) . ' · ' . h($session['depo']),
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <div class="print-summary-row">
        <div class="print-summary-box"><div class="psb-label">Kart / Katılım</div><div class="psb-value"><?= count($cards) ?></div></div>
        <div class="print-summary-box<?= $eksik > 0 ? ' psb-warn' : ' psb-ok' ?>"><div class="psb-label">Eksik Çıkış</div><div class="psb-value"><?= $eksik ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Oturum</div><div class="psb-value"><?= h($session['oturum_durumu'] === 'closed' ? 'Kapalı' : 'Açık') ?></div></div>
    </div>

    <h3 class="pr-section">Kart Hareketleri</h3>
    <table class="print-table">
        <thead>
        <tr>
            <th>Kart No</th><th>İşçi Tipi</th><th>Giriş Tarih / Saat</th>
            <th>Çıkış Tarih / Saat</th><th>Durum</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($cards as $c): ?>
        <tr>
            <td><?= h($c['card_no']) ?></td>
            <td><?= h($c['isci_tipi']) ?></td>
            <td><?= h(date('d.m.Y H:i:s', strtotime($c['entry_time']))) ?></td>
            <td><?= $c['exit_time'] ? h(date('d.m.Y H:i:s', strtotime($c['exit_time']))) : '—' ?></td>
            <td><?php
                if ($c['eksik_cikis']) echo 'Eksik Çıkış';
                else echo $c['exit_source'] === 'manual' ? 'Tamamlandı (Manuel)' : 'Tamamlandı';
            ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($cards)): ?>
        <tr><td colspan="5" class="pr-empty">Bu oturumda kart kaydı bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>
<?php render_print_page_end(); ?>
