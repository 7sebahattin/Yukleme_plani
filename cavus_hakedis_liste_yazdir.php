<?php
// =========================================================
// cavus_hakedis_liste_yazdir.php — Çavuş Hakediş Listesi (Sprint Print-PDKS-01)
//
// cavus_hakedis.php'nin YAZDIRILABİLİR, SALT OKUNUR görünümü — MEVCUT
// filtreleri (tarih/çavuş/durum) AYNEN uygular ve KENDİ agregasyon mantığını
// YAZMAZ: pdks_gunluk_gun_listesi() + pdks_hakedis_gun_listesi() +
// pdks_faz8b_oturum_ozeti() REUSE edilir.
//
// ⚠ Güvenlik: require_pdks_hakedis('entitlements_view') — cavus_hakedis.php
// İLE AYNI yetki. Bu sayfa HİÇBİR YAZMA yapmaz (hesapla/kesinleştir yok).
//
// ⚠ Para birimleri ASLA toplanmaz — hakediş özeti para birimi BAŞINA ayrı
// satırdır (CLAUDE.md kuralı).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_hakedis('entitlements_view');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);

$tarih = trim($_GET['tarih'] ?? '');
if ($tarih === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) $tarih = date('Y-m-d');
$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$durum_f = trim($_GET['durum'] ?? '');
if (!in_array($durum_f, ['hesaplanmadi', 'draft', 'final'], true)) $durum_f = '';
$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

$gunListesi = pdks_gunluk_gun_listesi($tarih, $depo, $cavusId, null, $pdo);
$hakedisler = pdks_hakedis_gun_listesi($tarih, $depo, $cavusId, null, $pdo);
$hakedisBySession = [];
foreach ($hakedisler as $h) $hakedisBySession[(int)$h['session_id']] = $h;

$satirlar = [];
foreach ($gunListesi as $row) {
    $sid = (int)$row['session']['id'];
    $hk = $hakedisBySession[$sid] ?? null;
    $hkDurum = $hk ? $hk['status'] : 'hesaplanmadi';
    if ($durum_f !== '' && $hkDurum !== $durum_f) continue;
    $faz8bOzet = $faz8bHazir ? pdks_faz8b_oturum_ozeti($sid, $pdo) : null;
    $satirlar[] = ['puantaj' => $row, 'hakedis' => $hk, 'hakedis_durum' => $hkDurum, 'faz8b' => $faz8bOzet];
}

$durumEtiket = ['hesaplanmadi' => 'Hesaplanmadı', 'draft' => 'Taslak', 'final' => 'Kesin'];

$cavusAdi = null;
if ($cavusId !== null) {
    $stC = $pdo->prepare("SELECT name FROM foremen WHERE id = ?");
    $stC->execute([$cavusId]);
    $cavusAdi = $stC->fetchColumn() ?: null;
}

$topKadin = 0; $topErkek = 0; $topIsci = 0; $topEksik = 0;
$hakedisParaBirimi = [];   // ⚠ para birimi BAŞINA ayrı — kurlar toplanmaz
foreach ($satirlar as $s) {
    $p = $s['puantaj'];
    $topKadin += (int)($p['giris']['Kadın'] ?? 0);
    $topErkek += (int)($p['giris']['Erkek'] ?? 0);
    $topIsci  += (int)$p['giris_toplam'];
    $topEksik += (int)$p['eksik_toplam'];
    if ($s['hakedis']) {
        $cur = (string)$s['hakedis']['currency'];
        if (!isset($hakedisParaBirimi[$cur])) $hakedisParaBirimi[$cur] = ['tutar' => 0.0, 'adet' => 0];
        $hakedisParaBirimi[$cur]['tutar'] += (float)$s['hakedis']['total_amount'];
        $hakedisParaBirimi[$cur]['adet']++;
    }
}

$mode = 'summary';
$orientation = print_orientation($mode, 8);

$geriUrl = 'cavus_hakedis.php?' . http_build_query(array_filter([
    'tarih' => $tarih, 'cavus' => $cavusId, 'durum' => $durum_f,
], fn($v) => $v !== null && $v !== ''));

render_print_page_start('Çavuş Hakediş Listesi', 'account', $mode, $orientation);
?>
<div class="print-sheet">
    <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button type="button" onclick="window.print()" style="padding:7px 13px;border:1px solid #1a73e8;border-radius:6px;background:#1a73e8;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer">🖨️ Yazdır</button>
        <a href="<?= h($geriUrl) ?>" style="padding:7px 13px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1e293b;font-size:.85rem;font-weight:600;text-decoration:none">← Hakedişe Dön</a>
    </div>

    <?= render_print_header_html(
        'ÇAVUŞ HAKEDİŞ LİSTESİ',
        date('d.m.Y', strtotime($tarih))
            . ($depo !== '' ? ' · ' . $depo : '')
            . ($cavusAdi !== null ? ' · ' . $cavusAdi : ' · Tüm çavuşlar')
            . ($durum_f !== '' ? ' · ' . $durumEtiket[$durum_f] : ''),
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <h3 style="font-size:1rem;margin:12px 0 6px">Gün Özeti</h3>
    <div class="print-summary-row">
        <div class="print-summary-box"><div class="psb-label">Mesai</div><div class="psb-value"><?= count($satirlar) ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Kadın</div><div class="psb-value"><?= $topKadin ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Erkek</div><div class="psb-value"><?= $topErkek ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Toplam İşçi</div><div class="psb-value"><?= $topIsci ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Eksik Çıkış</div><div class="psb-value"><?= $topEksik ?></div></div>
    </div>

    <?php if (!empty($hakedisParaBirimi)): ?>
    <h3 style="font-size:1rem;margin:12px 0 6px">Hakediş Toplamı</h3>
    <table class="print-table">
        <thead><tr><th>Para Birimi</th><th>Hesaplanmış Mesai</th><th>Toplam Hakediş</th></tr></thead>
        <tbody>
        <?php foreach ($hakedisParaBirimi as $cur => $v): ?>
        <tr>
            <td><?= h($cur) ?></td>
            <td><?= (int)$v['adet'] ?></td>
            <td><?= h(number_format($v['tutar'], 2, ',', '.')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3 style="font-size:1rem;margin:12px 0 6px">Çavuş Bazlı Hakediş</h3>
    <table class="print-table">
        <thead>
        <tr>
            <th>Çavuş</th><th>Kadın</th><th>Erkek</th><th>Toplam</th>
            <th>Mesai Değ.</th><th>Hakediş</th><th>Durum</th><th>Uyarı</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($satirlar as $s):
            $p = $s['puantaj']; $sess = $p['session']; $hk = $s['hakedis']; $f8 = $s['faz8b'];
        ?>
        <tr>
            <td><?= h($sess['foreman_name_snapshot']) ?></td>
            <td><?= (int)($p['giris']['Kadın'] ?? 0) ?></td>
            <td><?= (int)($p['giris']['Erkek'] ?? 0) ?></td>
            <td><?= (int)$p['giris_toplam'] ?></td>
            <td><?php
                if (!$faz8bHazir) echo '—';
                elseif ($f8['tam_hazir']) echo 'Hazır';
                else echo 'Bekliyor ' . (int)$f8['hazir'] . '/' . (int)$f8['toplam'];
            ?></td>
            <td><?= $hk ? h(number_format((float)$hk['total_amount'], 2, ',', '.') . ' ' . $hk['currency']) : '—' ?></td>
            <td><?= h($durumEtiket[$s['hakedis_durum']]) ?></td>
            <td><?php
                $uyarilar = [];
                if ((int)$p['eksik_toplam'] > 0) $uyarilar[] = 'Eksik Çıkış (' . (int)$p['eksik_toplam'] . ')';
                if ($hk && !empty($hk['needs_recalculation'])) $uyarilar[] = 'Yeniden hesaplama gerekli';
                echo $uyarilar ? h(implode(' · ', $uyarilar)) : '—';
            ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($satirlar)): ?>
        <tr><td colspan="8" style="text-align:center;color:#666">Bu tarih/filtrelerde mesai kaydı bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if (!empty($satirlar)): ?>
        <tfoot>
        <tr>
            <td>TOPLAM (<?= count($satirlar) ?> mesai)</td>
            <td><?= $topKadin ?></td>
            <td><?= $topErkek ?></td>
            <td><?= $topIsci ?></td>
            <td>—</td><td>—</td><td>—</td>
            <td><?= $topEksik ?></td>
        </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>
<?php render_print_page_end(); ?>
