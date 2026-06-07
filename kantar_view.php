<?php
// =========================================================
// kantar_view.php - Kantar fişi görüntüle + yazdır
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('kantar.read');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz fiş.');
    header('Location: kantar.php'); exit;
}

$st = db()->prepare("SELECT * FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$fis = $st->fetch();
if (!$fis) {
    set_flash('error', 'Fiş bulunamadı.');
    header('Location: kantar.php'); exit;
}

$t1  = (float)($fis['tartim1'] ?? 0);
$t2  = (float)($fis['tartim2'] ?? 0);
$net = max(0.0, $t1 - $t2);

$kasa_say          = (int)($fis['kasa_sayisi']      ?? 0);
$palet_say         = (int)($fis['palet_sayisi']     ?? 0);
$kasa_dara_u       = (float)($fis['kasa_dara']      ?? 0);
$palet_dara_u      = (float)($fis['palet_dara']     ?? 0);
$kasa_dara_total   = (float)($fis['kasa_dara_total']  ?? 0);
$palet_dara_total  = (float)($fis['palet_dara_total'] ?? 0);
$brut_hesap = $net;
$dara_hesap = ($kasa_dara_total > 0 || $palet_dara_total > 0)
    ? $kasa_dara_total + $palet_dara_total
    : $kasa_say * $kasa_dara_u + $palet_say * $palet_dara_u;
$net_hesap  = max(0.0, $brut_hesap - $dara_hesap);
// Efektif birim dara — gruplandırma fallback için
$eff_kasa_dara_u  = ($kasa_dara_total > 0 && $kasa_say  > 0) ? $kasa_dara_total  / $kasa_say  : $kasa_dara_u;
$eff_palet_dara_u = ($palet_dara_total > 0 && $palet_say > 0) ? $palet_dara_total / $palet_say : $palet_dara_u;

// Kp satırları (çok-tip kasa/palet)
$kp_rows_view = [];
try {
    $st_kp = db()->prepare("SELECT tip, cinsi, sayisi, birim_dara_kg FROM kantar_kasa_palet_satir WHERE fis_id=? ORDER BY id");
    $st_kp->execute([$id]);
    $kp_rows_view = $st_kp->fetchAll();
} catch (PDOException $e) {}
$has_foto     = !empty($fis['foto_data']);
$st_g = db()->prepare("SELECT grup_adi, palet_sayisi, kasa_adedi, kasa_dara_kg, palet_dara_kg, brut_kg FROM kantar_gruplar WHERE fis_id = ? ORDER BY sira");
$st_g->execute([$id]);
$gruplar = $st_g->fetchAll() ?: [];

render_header('Kantar Fişi #' . ($fis['fis_no'] ?: $id));
render_flash();
?>

<style>
/* ── EKRAN: sarmalayıcılar şeffaf ── */
.kv-pb,
.kv-pb-photo,
.kv-pb-bottom,
.kv-pb-left,
.kv-pb-right { display: contents; }

/* Sapma uyarısı — ekran */
.kv-sapma-uyari {
    background: rgba(239,68,68,.09);
    border: 1px solid rgba(239,68,68,.35);
    border-radius: var(--radius, 6px);
    padding: 10px 14px;
    margin-bottom: 12px;
    font-size: .88rem;
    color: #7f1d1d;
}
.kv-sapma-detail {
    display: flex; gap: 12px; margin-top: 6px;
    flex-wrap: wrap; font-size: .82rem; color: #6b1414;
}

/* ── PRINT ── */
@page { size: A4 portrait; margin: 8mm; }
@media print {
    .topbar, .bottomnav, .page-head, .flash,
    .kv-no-print { display: none !important; }

    html, body { font-size: 10pt !important; }
    body, .container {
        background: #fff !important;
        padding: 0 !important; margin: 0 !important;
    }
    .container { padding-bottom: 0 !important; max-width: 100% !important; }

    /* ── Başlık ── */
    .kv-print-header {
        display: flex !important;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid #000;
        padding-bottom: 4px;
        margin-bottom: 5px;
    }
    .kv-print-h-title { font-size: 14pt; font-weight: 700; }
    .kv-print-h-sub   { font-size: 9pt; color: #444; margin-top: 1px; }
    .kv-print-h-right { text-align: right; font-size: 9pt; line-height: 1.5; }

    /* ── Fotoğraf: yarım sayfa yüksekliği ── */
    .kv-pb         { display: block !important; }
    .kv-pb-photo   { display: block !important; width: 100%; margin-bottom: 4mm; }
    .kv-photo-card {
        border: 1px solid #ccc !important;
        box-shadow: none !important;
        margin: 0 !important; padding: 0 !important;
        overflow: hidden;
    }
    .kv-photo-wrap { background: #fff !important; min-height: unset !important; padding: 0 !important; }
    .kv-photo {
        width: 100% !important;
        height: auto !important;
        max-height: 96mm !important;
        object-fit: contain;
        display: block !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .kv-photo-ph { display: none !important; }

    /* ── Fiş + Tartımlar yanyana, Gruplandırma tam genişlik altında ── */
    .kv-pb-bottom { display: block !important; }
    .kv-top-row   { display: flex !important; gap: 5mm; align-items: stretch; margin-bottom: 4px; }
    .kv-pb-left   { display: flex !important; flex-direction: column; flex: 1; min-width: 0; }
    .kv-pb-right  { display: flex !important; flex-direction: column; flex: 0 0 36%; min-width: 0; }
    .kv-pb-left > .card,
    .kv-pb-right > .card { flex: 1; }
    .kv-grup-col  { display: block !important; }

    /* ── Kartlar ── */
    .card {
        border: 1px solid #bbb !important;
        box-shadow: none !important;
        margin-bottom: 4px !important;
        page-break-inside: avoid;
    }
    .card-head { border-bottom: 1px solid #bbb !important; padding: 4px 8px !important; }
    .card-head h2 { font-size: 9pt !important; font-weight: 700; margin: 0 !important; text-transform: uppercase; letter-spacing: .04em; }
    .card-body { padding: 6px 8px !important; }

    /* ── Fiş bilgileri: 3 kolon, etiket+değer tek satırda ── */
    .card-body { padding: 4px 6px !important; }
    .info-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr 1fr !important;
        gap: 2px 8px !important;
    }
    .info-grid > div { min-width: 0; display: flex; align-items: baseline; gap: 3px; }
    .info-grid .span-2 { grid-column: span 3; }
    .info-grid .lbl {
        font-size: 5pt;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: #888;
        white-space: nowrap;
        flex-shrink: 0;
        line-height: 1.5;
    }
    .info-grid .lbl::after { content: ':'; }
    .info-grid strong { font-size: 8pt; font-weight: 600; line-height: 1.5; min-width: 0; }

    /* ── Tartımlar ── */
    .kv-tartim-list {
        display: block;
        margin-bottom: 5px;
        border: 1px solid #ddd;
        border-radius: 3px;
        overflow: hidden;
    }
    .kv-tartim-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 8px;
        border-bottom: 1px solid #eee;
    }
    .kv-tartim-row:last-child { border-bottom: none; }
    .kv-tartim-label { font-size: 7.5pt; color: #666; }
    .kv-tartim-val { font-size: 11pt; font-weight: 700; text-align: right; }
    .kv-tartim-unit { font-size: 7pt; font-weight: 400; }
    .kv-tartim-alibi { font-size: 6pt; color: #888; text-align: right; }

    .kv-net-box {
        background: #e85d04 !important; color: #fff !important;
        padding: 4px 10px !important; margin-top: 5px !important; margin-bottom: 6px !important;
        border-radius: 3px;
        display: flex; justify-content: space-between; align-items: center;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .kv-net-label { font-size: 9pt !important; font-weight: 600; letter-spacing: .05em; }
    .kv-net-val   { font-size: 14pt !important; font-weight: 700 !important; }

    .kv-nd-box { margin-top: 0 !important; border: 1px solid #ddd; border-radius: 3px; }
    .kv-nd-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 4px 10px !important; font-size: 9pt !important;
        border-bottom: 1px solid #eee;
    }
    .kv-nd-row:last-child { border-bottom: none; }
    .kv-nd-net { background: #1a56db !important; color: #fff !important; font-weight: 700; padding: 6px 12px !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .kv-nd-net .kv-nd-lbl { color: rgba(255,255,255,.8) !important; }
    .kv-nd-lbl { color: #555; }
    .kv-nd-val { font-weight: 600; }
    .kv-nd-net-val { font-size: 16pt !important; }

    /* ── Gruplandırma tablosu ── */
    .kv-grup-table { width: 100%; border-collapse: collapse; font-size: 11pt; }
    .kv-grup-table th {
        font-size: 9pt; text-transform: uppercase; letter-spacing: .04em;
        color: #333; font-weight: 700; text-align: right;
        border-bottom: 2px solid #374151; padding: 5px 10px;
        background: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .kv-grup-table th:first-child { text-align: left; }
    .kv-grup-table td {
        padding: 8px 10px; border-bottom: 1px solid rgba(0,0,0,.1);
        text-align: right; font-variant-numeric: tabular-nums;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .kv-grup-table td:first-child { text-align: left; font-weight: 700; font-size: 12pt; }
    .kv-grup-table tfoot td {
        border-top: 2px solid #374151; font-weight: 700; font-size: 11pt;
        background: #e2e8f0 !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .kv-grup-net { color: #1a56db !important; font-weight: 800 !important; font-size: 13pt !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    /* Sapma uyarısı — print */
    .kv-sapma-uyari {
        background: #fef2f2 !important; border: 1pt solid #ef4444 !important;
        padding: 4px 8px !important; margin-bottom: 4px !important;
        font-size: 8pt !important; color: #7f1d1d !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
        page-break-inside: avoid;
    }
    .kv-sapma-detail { font-size: 7pt !important; margin-top: 2px !important; gap: 8px !important; }
}
</style>

<!-- Yazdırma başlığı — sadece print'te görünür -->
<div class="kv-print-header" style="display:none">
    <div>
        <div class="kv-print-h-title">⚖️ KANTAR FİŞİ<?= $fis['fis_no'] ? ' · ' . h($fis['fis_no']) : '' ?></div>
        <?php if ($fis['firma_adi']): ?>
        <div class="kv-print-h-sub"><?= h($fis['firma_adi']) ?><?= $fis['plaka'] ? ' · ' . h($fis['plaka']) : '' ?></div>
        <?php endif; ?>
    </div>
    <div class="kv-print-h-right">
        <?php if ($fis['giris_tarih']): ?>
        <div><strong>Giriş:</strong> <?= h(fmt_datetime($fis['giris_tarih'])) ?></div>
        <?php endif; ?>
        <?php if ($fis['cikis_tarih']): ?>
        <div><strong>Çıkış:</strong> <?= h(fmt_datetime($fis['cikis_tarih'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- Ekran başlığı -->
<div class="page-head kv-no-print">
    <div>
        <h1>⚖️ Kantar Fişi <?= $fis['fis_no'] ? '· ' . h($fis['fis_no']) : '#' . $id ?></h1>
    </div>
    <div class="page-head-actions">
        <a href="kantar.php" class="btn btn-ghost">← Liste</a>
        <a href="kantar_edit.php?id=<?= $id ?>" class="btn">Düzenle</a>
        <button onclick="window.print()" class="btn btn-primary">🖨 Yazdır</button>
    </div>
</div>

<div class="kv-pb">

    <!-- ÜST: Fotoğraf — yazdırmada tam genişlik -->
    <div class="kv-pb-photo">
        <?php if ($has_foto): ?>
        <section class="card kv-photo-card">
            <div class="kv-photo-wrap">
                <img class="kv-photo" src="kantar_foto.php?id=<?= $id ?>" alt="Kantar fişi görseli">
            </div>
        </section>
        <?php else: ?>
        <section class="card kv-no-print">
            <div class="kv-photo-wrap">
                <div class="kv-photo-ph">📷<br><span>Bu fiş için henüz görsel eklenmemiş</span></div>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <!-- ALT: Fiş bilgileri + (tartımlar | gruplandırma) -->
    <div class="kv-pb-bottom<?= !empty($gruplar) ? ' has-gruplar' : '' ?>">

        <!-- Fiş Bilgileri + Tartımlar yanyana -->
        <div class="kv-top-row">

        <div class="kv-pb-left">
            <section class="card">
                <div class="card-head"><h2>Fiş Bilgileri</h2></div>
                <div class="card-body">
                    <div class="info-grid">
                        <?php if ($fis['fis_no']): ?>
                        <div><span class="lbl">Fiş No</span><strong><?= h($fis['fis_no']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['plaka']): ?>
                        <div><span class="lbl">Plaka</span><strong><?= h($fis['plaka']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['firma_adi']): ?>
                        <div><span class="lbl">Firma</span><strong><?= h($fis['firma_adi']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['operator_adi']): ?>
                        <div><span class="lbl">Operatör</span><strong><?= h($fis['operator_adi']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['giris_tarih']): ?>
                        <div><span class="lbl">Giriş</span><strong><?= h(fmt_datetime($fis['giris_tarih'])) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['cikis_tarih']): ?>
                        <div><span class="lbl">Çıkış</span><strong><?= h(fmt_datetime($fis['cikis_tarih'])) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['malin_cinsi']): ?>
                        <div><span class="lbl">Malın Cinsi</span><strong><?= h($fis['malin_cinsi']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['geldigi_yer']): ?>
                        <div><span class="lbl">Geldiği Yer</span><strong><?= h($fis['geldigi_yer']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['gittigi_yer']): ?>
                        <div><span class="lbl">Gittiği Yer</span><strong><?= h($fis['gittigi_yer']) ?></strong></div>
                        <?php endif; ?>
                        <?php if (!empty($kp_rows_view)): ?>
                            <?php foreach ($kp_rows_view as $kpr): ?>
                            <div><span class="lbl"><?= $kpr['tip'] === 'palet' ? 'Palet' : 'Kasa' ?>: <?= h($kpr['cinsi']) ?></span><strong><?= number_format((int)$kpr['sayisi']) ?> adet</strong></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php if ($palet_say): ?><div><span class="lbl">Palet</span><strong><?= $palet_say ?> adet</strong></div><?php endif; ?>
                            <?php if ($fis['palet_cinsi'] ?? ''): ?><div><span class="lbl">Palet Cinsi</span><strong><?= h($fis['palet_cinsi']) ?></strong></div><?php endif; ?>
                            <?php if ($kasa_say): ?><div><span class="lbl">Kasa</span><strong><?= $kasa_say ?> adet</strong></div><?php endif; ?>
                            <?php if ($fis['kasa_cinsi']): ?><div><span class="lbl">Kasa Cinsi</span><strong><?= h($fis['kasa_cinsi']) ?></strong></div><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($fis['depo'] ?? ''): ?>
                        <div><span class="lbl">Depo</span><strong><?= h($fis['depo']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['aciklama'] ?? ''): ?>
                        <div class="span-2"><span class="lbl">Açıklama</span><strong><?= h($fis['aciklama']) ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <div class="kv-pb-right">
            <!-- Tartımlar -->
            <section class="card kv-tartim-card">
                <div class="card-head"><h2>Tartımlar</h2></div>
                <div class="card-body">
                    <div class="kv-tartim-list">
                        <?php if ($t1 > 0): ?>
                        <div class="kv-tartim-row">
                            <div class="kv-tartim-label">1. Tartım</div>
                            <div class="kv-tartim-val"><?= fmt_kg($t1) ?> <span class="kv-tartim-unit">kg</span></div>
                            <?php if ($fis['alibi1'] ?? ''): ?><div class="kv-tartim-alibi">Alibi: <?= h($fis['alibi1']) ?></div><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($t2 > 0): ?>
                        <div class="kv-tartim-row">
                            <div class="kv-tartim-label">2. Tartım</div>
                            <div class="kv-tartim-val"><?= fmt_kg($t2) ?> <span class="kv-tartim-unit">kg</span></div>
                            <?php if ($fis['alibi2'] ?? ''): ?><div class="kv-tartim-alibi">Alibi: <?= h($fis['alibi2']) ?></div><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($net > 0): ?>
                    <div class="kv-net-box">
                        <div class="kv-net-label">NET KG</div>
                        <div class="kv-net-val"><?= fmt_kg($net) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($dara_hesap > 0): ?>
                    <div class="kv-nd-box">
                        <div class="kv-nd-row"><span class="kv-nd-lbl">Brüt (Tartım Neti)</span><strong class="kv-nd-val"><?= fmt_kg($brut_hesap) ?> kg</strong></div>
                        <div class="kv-nd-row"><span class="kv-nd-lbl">Kasa+Palet Dara</span><strong class="kv-nd-val"><?= fmt_kg($dara_hesap) ?> kg</strong></div>
                        <div class="kv-nd-row kv-nd-net"><span class="kv-nd-lbl">Net KG</span><strong class="kv-nd-val kv-nd-net-val"><?= fmt_kg($net_hesap) ?> kg</strong></div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </div><!-- /kv-pb-right -->

        </div><!-- /kv-top-row -->

        <!-- Gruplandırma — tam genişlik -->
        <?php if (!empty($gruplar)):
            $grup_tot_palet = array_sum(array_column($gruplar, 'palet_sayisi'));
            $grup_tot_kasa  = array_sum(array_column($gruplar, 'kasa_adedi'));
            $kc             = kantar_calc($fis);
            $grup_rows      = kantar_grup_dist($gruplar, $kc['brut'], $kc['eff_kdu'], $kc['eff_pdu']);
            $grup_tot_brut  = array_sum(array_column($grup_rows, 'brut_kg'));
            $grup_tot_dara  = array_sum(array_column($grup_rows, 'dara_kg'));
            $grup_tot_net   = array_sum(array_column($grup_rows, 'net_kg'));
            $grup_sapma     = abs($grup_tot_net - $kc['net']);
        ?>
        <div class="kv-grup-col">
            <?php if ($grup_sapma > 1.0): ?>
            <div class="kv-sapma-uyari">
                <strong>⚠ Sapma Uyarısı:</strong>
                Grup toplamı ile fiş neti arasında fark var. Stok dağılımı hatalı görünebilir.
                <div class="kv-sapma-detail">
                    <span>Fiş neti: <strong><?= fmt_kg($kc['net']) ?> kg</strong></span>
                    <span>Grup toplamı: <strong><?= fmt_kg($grup_tot_net) ?> kg</strong></span>
                    <span>Fark: <strong><?= fmt_kg($grup_sapma) ?> kg</strong></span>
                </div>
            </div>
            <?php endif; ?>
            <!-- Gruplandırma -->
            <section class="card kv-grup-card">
                <div class="card-head"><h2>🗂 Gruplandırma</h2></div>
                <div class="card-body" style="padding:0">
                    <table class="kv-grup-table">
                        <thead><tr>
                            <th>Grup / Firma</th>
                            <th>Palet</th>
                            <th>Kasa</th>
                            <th>Brüt KG</th>
                            <th>Dara KG</th>
                            <th>Net KG</th>
                        </tr></thead>
                        <tbody>
                        <?php
                        $kv_grup_colors = [
                            '#dbeafe','#dcfce7','#fce7f3','#fef9c3',
                            '#f3e8ff','#ffedd5','#e0f2fe','#fef2f2',
                        ];
                        foreach ($grup_rows as $_gi => $gr):
                            $_gbg = $kv_grup_colors[$_gi % count($kv_grup_colors)];
                        ?>
                            <tr style="background:<?= $_gbg ?> !important; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                <td>
                                    <?= h($gr['firma']) ?>
                                    <div class="kv-no-print" style="font-size:.72rem;color:#6b7280;margin-top:1px">
                                        ham giriş · <?= h($fis['depo'] ?: '—') ?>
                                    </div>
                                </td>
                                <td><?= $gr['palet'] ?: '—' ?></td>
                                <td><?= $gr['kasa'] ?></td>
                                <td><?= number_format(round($gr['brut_kg']), 0, ',', '.') ?></td>
                                <td><?= number_format(round($gr['dara_kg']), 0, ',', '.') ?></td>
                                <td class="kv-grup-net"><?= number_format(round($gr['net_kg']), 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <?php if (count($grup_rows) > 1): ?>
                        <tfoot><tr>
                            <td>TOPLAM</td>
                            <td><?= $grup_tot_palet ?: '—' ?></td>
                            <td><?= $grup_tot_kasa ?></td>
                            <td><?= number_format(round($grup_tot_brut), 0, ',', '.') ?></td>
                            <td><?= number_format(round($grup_tot_dara), 0, ',', '.') ?></td>
                            <td class="kv-grup-net"><?= number_format(round($grup_tot_net), 0, ',', '.') ?></td>
                        </tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </section>
            </div><!-- /kv-grup-col -->
            <?php endif; ?>

    </div><!-- /kv-pb-bottom -->
</div><!-- /kv-pb -->

<?php if (!empty($_GET['print'])): ?>
<script>
/* Paylaşılan ?print=1 linki açılınca otomatik yazdır (fotoğraf yüklensin diye kısa gecikme) */
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 600); });
</script>
<?php endif; ?>

<?php render_footer(); ?>
