<?php
// =========================================================
// cavus_toplu_dokum_yazdir.php — Çavuş Toplu Döküm (Sprint Print-PDKS-01)
//
// cavus_toplu_dokum.php'nin YAZDIRILABİLİR, SALT OKUNUR görünümü — MEVCUT
// filtreleri (ay/çavuş) AYNEN uygular ve KENDİ agregasyon mantığını YAZMAZ:
// pdks_rapor_cavus_toplu_dokum() REUSE edilir (rapor_yazdir.php emsali).
//
// ⚠ Güvenlik: sayfa require_pdks_rapor() ile açılır — cavus_toplu_dokum.php
// İLE AYNI yetki. Hakediş kolonu AYRICA pdks_rapor_can('financial') ister;
// bu kontrol sunucu tarafında ZORUNLUDUR, URL ile ATLANAMAZ.
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

$ay = trim((string)($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) {
    $ay = date('Y-m');
}

$cavusId = filter_var($_GET['cavus'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cavusId = $cavusId === false ? null : (int)$cavusId;

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

$faz8aHazir = pdks_gunluk_faz8a_sema_hazir($pdo);

$finansalGosterilebilir =
    pdks_rapor_can('financial')
    && function_exists('pdks_hakedis_sema_hazir')
    && pdks_hakedis_sema_hazir($pdo);

$satirlar = $faz8aHazir
    ? pdks_rapor_cavus_toplu_dokum($ay, $depo !== '' ? $depo : null, $cavusId, $finansalGosterilebilir, $pdo)
    : [];

$cavusAdi = null;
if ($cavusId !== null) {
    $stC = $pdo->prepare("SELECT name FROM foremen WHERE id = ?");
    $stC->execute([$cavusId]);
    $cavusAdi = $stC->fetchColumn() ?: null;
}

$toplamIsci = 0;
$toplamKadin = 0;
$toplamErkek = 0;
$toplamEksik = 0;
foreach ($satirlar as $r) {
    $toplamIsci  += (int)$r['toplam_isci'];
    $toplamKadin += (int)$r['kadin'];
    $toplamErkek += (int)$r['erkek'];
    $toplamEksik += (int)$r['eksik_cikis'];
}

// Hakediş kolonu yalnız yetkiliye çizilir; yetkisizde kolon hiç AÇILMAZ
// (bir kolon dolusu "—" basmak kâğıtta yer kaybıdır).
$kolonSayisi = $finansalGosterilebilir ? 9 : 8;
$mode = 'summary';
$orientation = print_orientation($mode, $kolonSayisi);

$geriUrl = 'cavus_toplu_dokum.php?' . http_build_query(array_filter([
    'ay' => $ay,
    'cavus' => $cavusId,
], fn($v) => $v !== null && $v !== ''));

render_print_page_start('Çavuş Toplu Döküm', 'account', $mode, $orientation);
?>
<div class="print-sheet">
    <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button type="button" onclick="window.print()" style="padding:7px 13px;border:1px solid #1a73e8;border-radius:6px;background:#1a73e8;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer">🖨️ Yazdır</button>
        <a href="<?= h($geriUrl) ?>" style="padding:7px 13px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1e293b;font-size:.85rem;font-weight:600;text-decoration:none">← Döküme Dön</a>
    </div>

    <?= render_print_header_html(
        'ÇAVUŞ TOPLU DÖKÜM',
        date('m/Y', strtotime($ay . '-01'))
            . ($depo !== '' ? ' · ' . $depo : '')
            . ($cavusAdi !== null ? ' · ' . $cavusAdi : ' · Tüm çavuşlar'),
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <?php if (!$faz8aHazir): ?>
    <p style="font-size:.9rem">Günlük işçi work-period şeması hazır olmadığı için döküm üretilemiyor.</p>
    <?php else: ?>

    <h3 style="font-size:1rem;margin:12px 0 6px">Ay Özeti</h3>
    <div class="print-summary-row">
        <div class="print-summary-box"><div class="psb-label">Kadın</div><div class="psb-value"><?= $toplamKadin ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Erkek</div><div class="psb-value"><?= $toplamErkek ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Toplam İşçi</div><div class="psb-value"><?= $toplamIsci ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Eksik Çıkış</div><div class="psb-value"><?= $toplamEksik ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Mesai Günü</div><div class="psb-value"><?= count($satirlar) ?></div></div>
    </div>

    <h3 style="font-size:1rem;margin:12px 0 6px">Gün Bazlı Döküm</h3>
    <table class="print-table">
        <thead>
        <tr>
            <th>Tarih</th>
            <th>Çavuş</th>
            <th>Kadın</th>
            <th>Erkek</th>
            <th>Toplam İşçi</th>
            <?php if ($finansalGosterilebilir): ?><th>Hakediş</th><?php endif; ?>
            <th>İlk Giriş</th>
            <th>Son Çıkış</th>
            <th>Eksik Çıkış</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($satirlar as $r): $hakedis = $r['hakedis']; ?>
        <tr>
            <td><?= h(date('d.m.Y', strtotime($r['tarih']))) ?></td>
            <td><?= h($r['cavus_adi']) ?><?= $r['cavus_kodu'] !== '' ? ' (' . h($r['cavus_kodu']) . ')' : '' ?></td>
            <td><?= (int)$r['kadin'] ?></td>
            <td><?= (int)$r['erkek'] ?></td>
            <td><?= (int)$r['toplam_isci'] ?></td>
            <?php if ($finansalGosterilebilir): ?>
            <td><?php if ($hakedis === null): ?>Hesaplanmadı<?php else: ?><?= h(pdks_rapor_para_formatla($hakedis['total_amount'])) ?> <?= h($hakedis['currency']) ?> (<?= $hakedis['status'] === 'final' ? 'Kesin' : 'Taslak' ?>)<?php endif; ?></td>
            <?php endif; ?>
            <td><?= $r['ilk_giris'] ? h(date('H:i', strtotime($r['ilk_giris']))) : '—' ?></td>
            <td><?= $r['son_cikis'] ? h(date('H:i', strtotime($r['son_cikis']))) : '—' ?></td>
            <td><?= (int)$r['eksik_cikis'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($satirlar)): ?>
        <tr><td colspan="<?= $kolonSayisi ?>" style="text-align:center;color:#666">Seçilen ay için kayıt bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if (!empty($satirlar)): ?>
        <tfoot>
        <tr>
            <td colspan="2">TOPLAM (<?= count($satirlar) ?> mesai günü)</td>
            <td><?= $toplamKadin ?></td>
            <td><?= $toplamErkek ?></td>
            <td><?= $toplamIsci ?></td>
            <?php if ($finansalGosterilebilir): ?><td>—</td><?php endif; ?>
            <td>—</td>
            <td>—</td>
            <td><?= $toplamEksik ?></td>
        </tr>
        </tfoot>
        <?php endif; ?>
    </table>

    <?php endif; ?>
</div>
<?php render_print_page_end(); ?>
