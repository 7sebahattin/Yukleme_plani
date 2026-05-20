<?php
// =========================================================
// kantar_view.php - Kantar fişi görüntüle + yazdır
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

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

$kasa_say     = (int)($fis['kasa_sayisi']  ?? 0);
$palet_say    = (int)($fis['palet_sayisi'] ?? 0);
$kasa_dara_u  = (float)($fis['kasa_dara']  ?? 0);
$palet_dara_u = (float)($fis['palet_dara'] ?? 0);
$brut_hesap   = $net;
$dara_hesap   = $kasa_say * $kasa_dara_u + $palet_say * $palet_dara_u;
$net_hesap    = max(0.0, $brut_hesap - $dara_hesap);
$has_foto     = !empty($fis['foto_data']);
$st_g = db()->prepare("SELECT grup_adi, palet_sayisi, kasa_adedi, kasa_dara_kg, palet_dara_kg FROM kantar_gruplar WHERE fis_id = ? ORDER BY sira");
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

/* ── PRINT ── */
@page { size: A4 portrait; margin: 8mm; }
@media print {
    .topbar, .bottomnav, .page-head, .flash,
    .kv-no-print { display: none !important; }

    html, body { font-size: 9pt !important; }
    body, .container {
        background: #fff !important;
        padding: 0 !important; margin: 0 !important;
    }
    .container { padding-bottom: 0 !important; max-width: 100% !important; }

    /* ── Başlık tam genişlik ── */
    .kv-print-header {
        display: flex !important;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid #000;
        padding-bottom: 4px;
        margin-bottom: 5px;
    }
    .kv-print-h-title { font-size: 13pt; font-weight: 700; }
    .kv-print-h-sub   { font-size: 8pt; color: #444; margin-top: 1px; }
    .kv-print-h-right { text-align: right; font-size: 8pt; line-height: 1.5; }

    /* ── Üst: fotoğraf tam genişlik ── */
    .kv-pb         { display: block !important; }
    .kv-pb-photo   {
        display: block !important;
        width: 100%;
        margin-bottom: 5mm;
    }
    .kv-photo-card {
        border: 1px solid #ccc !important;
        box-shadow: none !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden;
    }
    .kv-photo-wrap {
        background: #fff !important;
        min-height: unset !important;
        padding: 0 !important;
    }
    .kv-photo {
        width: 100% !important;
        height: auto !important;
        max-height: 80mm !important;
        object-fit: contain;
        display: block !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .kv-photo-ph { display: none !important; }

    /* ── Alt: iki kolon ── */
    .kv-pb-bottom {
        display: flex !important;
        gap: 5mm;
        align-items: flex-start;
    }
    .kv-pb-left  { display: block !important; flex: 1; min-width: 0; }
    .kv-pb-right { display: block !important; flex: 1; min-width: 0; }

    /* ── Kartlar ── */
    .card {
        border: 1px solid #bbb !important;
        box-shadow: none !important;
        margin-bottom: 4px !important;
        page-break-inside: avoid;
    }
    .card-head { border-bottom: 1px solid #bbb !important; padding: 3px 7px !important; }
    .card-head h2 { font-size: 7.5pt !important; font-weight: 700; margin: 0 !important; text-transform: uppercase; letter-spacing: .04em; }
    .card-body { padding: 5px 7px !important; }

    /* ── Fiş bilgileri: 2 kolon ── */
    .info-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 3px 8px !important;
    }
    .info-grid > div { min-width: 0; }
    .info-grid .span-2 { grid-column: span 2; }
    .info-grid .lbl {
        font-size: 5.5pt;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #777;
        display: block;
        line-height: 1.2;
    }
    .info-grid strong {
        font-size: 8pt;
        font-weight: 600;
        display: block;
        line-height: 1.3;
    }

    /* ── Tartımlar ── */
    .kv-tartim-list { display: flex; gap: 5px; margin-bottom: 4px; }
    .kv-tartim-row {
        flex: 1;
        border: 1px solid #ddd;
        border-radius: 3px;
        padding: 3px 6px;
    }
    .kv-tartim-label { font-size: 6pt; color: #666; }
    .kv-tartim-val { font-size: 10pt; font-weight: 700; line-height: 1.3; }
    .kv-tartim-unit { font-size: 7pt; font-weight: 400; }
    .kv-tartim-alibi { font-size: 6pt; color: #666; }

    .kv-net-box {
        background: #e85d04 !important;
        color: #fff !important;
        padding: 4px 8px !important;
        margin-top: 4px !important;
        border-radius: 3px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .kv-net-label { font-size: 7pt !important; font-weight: 600; letter-spacing: .05em; }
    .kv-net-val { font-size: 14pt !important; font-weight: 700 !important; }

    .kv-nd-box { margin-top: 4px !important; border: 1px solid #ddd; border-radius: 3px; }
    .kv-nd-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 2px 6px !important;
        font-size: 7pt !important;
        border-bottom: 1px solid #eee;
    }
    .kv-nd-row:last-child { border-bottom: none; }
    .kv-nd-net { background: #f0f4ff; font-weight: 700; }
    .kv-nd-lbl { color: #555; }
    .kv-nd-val { font-weight: 600; }
    .kv-nd-net-val { font-size: 8.5pt !important; }

    /* ── Gruplandırma tablosu ── */
    .kv-grup-table { width: 100%; border-collapse: collapse; font-size: 6.5pt; }
    .kv-grup-table th { font-size: 5.5pt; text-transform: uppercase; letter-spacing:.04em; color:#555; font-weight:700; text-align:right; border-bottom:1px solid #bbb; padding: 2px 3px; }
    .kv-grup-table th:first-child { text-align:left; }
    .kv-grup-table td { padding: 2px 3px; border-bottom: 1px solid #eee; text-align:right; font-variant-numeric:tabular-nums; }
    .kv-grup-table td:first-child { text-align:left; font-weight:600; }
    .kv-grup-table tfoot td { border-top: 1.5px solid #374151; font-weight:700; background:#f5f5f5 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .kv-grup-net { color: #1a56db !important; font-weight:800 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
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

    <!-- ALT: İki kolon -->
    <div class="kv-pb-bottom">

        <!-- ALT SOL: Fiş Bilgileri -->
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
                        <div><span class="lbl">Giriş Tarihi</span><strong><?= h(fmt_datetime($fis['giris_tarih'])) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['cikis_tarih']): ?>
                        <div><span class="lbl">Çıkış Tarihi</span><strong><?= h(fmt_datetime($fis['cikis_tarih'])) ?></strong></div>
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
                        <?php if ($palet_say): ?>
                        <div><span class="lbl">Palet Sayısı</span><strong><?= $palet_say ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['palet_cinsi'] ?? ''): ?>
                        <div><span class="lbl">Palet Cinsi</span><strong><?= h($fis['palet_cinsi']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($kasa_say): ?>
                        <div><span class="lbl">Kasa Sayısı</span><strong><?= $kasa_say ?></strong></div>
                        <?php endif; ?>
                        <?php if ($fis['kasa_cinsi']): ?>
                        <div><span class="lbl">Kasa Cinsi</span><strong><?= h($fis['kasa_cinsi']) ?></strong></div>
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

        <!-- ALT SAĞ: Tartımlar + Gruplandırma -->
        <div class="kv-pb-right">

            <!-- Tartımlar -->
            <section class="card">
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

            <?php if (!empty($gruplar)):
                $grup_tot_palet = array_sum(array_column($gruplar, 'palet_sayisi'));
                $grup_tot_kasa  = array_sum(array_column($gruplar, 'kasa_adedi'));
                $brut_per_kasa  = $grup_tot_kasa > 0 ? $net / $grup_tot_kasa : 0.0;
                $grup_tot_brut  = 0.0;
                $grup_tot_dara  = 0.0;
                $grup_tot_net   = 0.0;
                $grup_rows      = [];
                foreach ($gruplar as $g) {
                    $gp          = (int)$g['palet_sayisi'];
                    $gk          = (int)$g['kasa_adedi'];
                    $gbrut       = $gk * $brut_per_kasa;
                    $gkasa_dara  = (float)($g['kasa_dara_kg']  ?? 0) ?: $kasa_dara_u;
                    $gpalet_dara = (float)($g['palet_dara_kg'] ?? 0) ?: $palet_dara_u;
                    $gdara       = $gp * $gpalet_dara + $gk * $gkasa_dara;
                    $gnet        = max(0.0, $gbrut - $gdara);
                    $grup_tot_brut += $gbrut;
                    $grup_tot_dara += $gdara;
                    $grup_tot_net  += $gnet;
                    $grup_rows[] = ['ad' => $g['grup_adi'], 'palet' => $gp, 'kasa' => $gk,
                                    'brut' => $gbrut, 'dara' => $gdara, 'net' => $gnet];
                }
            ?>
            <!-- Gruplandırma -->
            <section class="card">
                <div class="card-head"><h2>🗂 Gruplandırma</h2></div>
                <div class="card-body" style="padding:0">
                    <table class="kv-grup-table">
                        <thead><tr>
                            <th>Grup</th>
                            <th>Palet</th>
                            <th>Kasa</th>
                            <th>Brüt KG</th>
                            <th>Dara KG</th>
                            <th>Net KG</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($grup_rows as $gr): ?>
                            <tr>
                                <td><?= h($gr['ad']) ?></td>
                                <td><?= $gr['palet'] ?: '—' ?></td>
                                <td><?= $gr['kasa'] ?></td>
                                <td><?= number_format(round($gr['brut']), 0, ',', '.') ?></td>
                                <td><?= number_format(round($gr['dara']), 0, ',', '.') ?></td>
                                <td class="kv-grup-net"><?= number_format(round($gr['net']), 0, ',', '.') ?></td>
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
            <?php endif; ?>

        </div><!-- /kv-pb-right -->
    </div><!-- /kv-pb-bottom -->
</div><!-- /kv-pb -->

<?php render_footer(); ?>
