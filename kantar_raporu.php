<?php
// =========================================================
// kantar_raporu.php — Kantar fişleri kapsamlı raporu
// Her fişin tam hesabı, gruplandırma dağıtımı, firma özeti
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$pdo = db();

// ── Filtreler ─────────────────────────────────────────────
$f_tarih_bas = trim($_GET['tarih_bas'] ?? '');
$f_tarih_bit = trim($_GET['tarih_bit'] ?? '');
$f_firma     = trim($_GET['firma']     ?? '');
$f_malin     = trim($_GET['malin']     ?? '');
$f_depo      = trim($_GET['depo']      ?? '');
$is_csv      = isset($_GET['csv']);

// ── SQL: tarih/malın/depo fişten filtrelenir; firma PHP'de ──
$where = []; $params = [];
if ($f_tarih_bas !== '') { $where[] = "kf.giris_tarih >= ?"; $params[] = $f_tarih_bas . ' 00:00:00'; }
if ($f_tarih_bit !== '') { $where[] = "kf.giris_tarih <= ?"; $params[] = $f_tarih_bit . ' 23:59:59'; }
if ($f_malin     !== '') { $where[] = "kf.malin_cinsi LIKE ?"; $params[] = '%' . $f_malin . '%'; }
if ($f_depo      !== '') { $where[] = "kf.depo LIKE ?"; $params[] = '%' . $f_depo . '%'; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("
    SELECT kf.id, kf.fis_no, kf.giris_tarih, kf.cikis_tarih,
           kf.plaka, kf.firma_adi, kf.operator_adi, kf.malin_cinsi, kf.geldigi_yer,
           kf.parti_no, kf.depo, kf.aciklama,
           kf.palet_sayisi, kf.kasa_sayisi,
           kf.tartim1, kf.tartim2,
           kf.kasa_dara, kf.palet_dara,
           kf.kasa_dara_total, kf.palet_dara_total,
           kf.created_at
    FROM kantar_fisleri kf
    $where_sql
    ORDER BY kf.giris_tarih DESC, kf.id DESC
    LIMIT 2000
");
$st->execute($params);
$fisleri = $st->fetchAll();

// ── Gruplar ve kasa/palet satırları toplu yükle ───────────
$grup_map = [];
$kp_map   = [];
if (!empty($fisleri)) {
    $ids = implode(',', array_map('intval', array_column($fisleri, 'id')));
    foreach ($pdo->query(
        "SELECT * FROM kantar_gruplar WHERE fis_id IN ($ids) ORDER BY fis_id, sira"
    )->fetchAll() as $g) {
        $grup_map[(int)$g['fis_id']][] = $g;
    }
    try {
        foreach ($pdo->query(
            "SELECT fis_id, tip, cinsi, sayisi, birim_dara_kg FROM kantar_kasa_palet_satir WHERE fis_id IN ($ids) ORDER BY fis_id, id"
        )->fetchAll() as $kp) {
            $kp_map[(int)$kp['fis_id']][] = $kp;
        }
    } catch (PDOException $e) {}
}

// ── Hesaplama yardımcıları ────────────────────────────────

/**
 * Fiş düzeyinde brüt/dara/net hesapla.
 * Dönüş: ['brut','dara','net','eff_kdu','eff_pdu']
 */
function kf_calc(array $fis): array {
    $t1   = (float)($fis['tartim1'] ?? 0);
    $t2   = (float)($fis['tartim2'] ?? 0);
    $brut = max(0.0, $t1 - $t2);

    $ks   = (int)($fis['kasa_sayisi']  ?? 0);
    $ps   = (int)($fis['palet_sayisi'] ?? 0);
    $kdt  = (float)($fis['kasa_dara_total']  ?? 0);
    $pdt  = (float)($fis['palet_dara_total'] ?? 0);
    $kdu  = (float)($fis['kasa_dara']  ?? 0);
    $pdu  = (float)($fis['palet_dara'] ?? 0);

    // Önce kayıtlı toplamı kullan; yoksa birim × adet
    $dara = ($kdt > 0 || $pdt > 0) ? $kdt + $pdt : $ks * $kdu + $ps * $pdu;
    $net  = max(0.0, $brut - $dara);

    // Efektif birim dara — gruplandırma fallback'i için
    $eff_kdu = ($kdt > 0 && $ks  > 0) ? $kdt / $ks  : $kdu;
    $eff_pdu = ($pdt > 0 && $ps  > 0) ? $pdt / $ps  : $pdu;

    return ['brut' => $brut, 'dara' => $dara, 'net' => $net,
            'eff_kdu' => $eff_kdu, 'eff_pdu' => $eff_pdu,
            'kasa_say' => $ks, 'palet_say' => $ps];
}

/**
 * Gruplandırılmış fiş için her grubun brüt/dara/net değerini hesapla.
 *
 * Dağıtım kuralı (manuel brüt yoksa):
 *   Auto-pool = fiş brütü − manuel brütlerin toplamı
 *   Her grubun payı = (grup_kasa + grup_palet) / toplam_auto_adet × auto_pool
 *   Bu sayede palet-only gruplar da doğru pay alır.
 */
function kf_grup_dist(array $gruplar, float $brut, float $eff_kdu, float $eff_pdu): array {
    $manual_sum  = 0.0;
    $auto_weight = 0;
    foreach ($gruplar as $g) {
        $gb = (float)($g['brut_kg'] ?? 0);
        if ($gb > 0) {
            $manual_sum += $gb;
        } else {
            $auto_weight += (int)$g['kasa_adedi'] + (int)$g['palet_sayisi'];
        }
    }
    $auto_pool = max(0.0, $brut - $manual_sum);
    $per_unit  = $auto_weight > 0 ? $auto_pool / $auto_weight : 0.0;

    $rows = [];
    foreach ($gruplar as $g) {
        $gp    = (int)$g['palet_sayisi'];
        $gk    = (int)$g['kasa_adedi'];
        $gb    = (float)($g['brut_kg'] ?? 0);
        $gbrut = $gb > 0 ? $gb : (($gp + $gk) * $per_unit);
        $gkd   = (float)($g['kasa_dara_kg']  ?? 0) ?: $eff_kdu;
        $gpd   = (float)($g['palet_dara_kg'] ?? 0) ?: $eff_pdu;
        $gdara = $gp * $gpd + $gk * $gkd;
        $gnet  = max(0.0, $gbrut - $gdara);
        $rows[] = [
            'firma'     => $g['grup_adi'] ?: '—',
            'palet'     => $gp,
            'kasa'      => $gk,
            'brut_kg'   => $gbrut,
            'dara_kg'   => $gdara,
            'net_kg'    => $gnet,
            'is_manual' => $gb > 0,
        ];
    }
    return $rows;
}

// ── Rapor satırlarını oluştur ─────────────────────────────
// Her $entry = bir fiş'e ait "blok" (tek veya birden fazla dağıtım satırı).
$entries         = [];
$firma_ozet      = [];   // firma → toplam net_kg
$toplam_brut     = 0.0;
$toplam_net      = 0.0;
$toplam_fis      = 0;
$toplam_grup_fis = 0;

foreach ($fisleri as $fis) {
    $fid     = (int)$fis['id'];
    $c       = kf_calc($fis);
    $gruplar = $grup_map[$fid] ?? [];
    $has_grup = !empty($gruplar);

    // Firma filtresi: gruplu fişlerde grup adlarına da bak
    if ($f_firma !== '') {
        $fl = mb_strtolower($f_firma);
        if (!$has_grup) {
            if (mb_stripos($fis['firma_adi'] ?? '', $f_firma) === false) continue;
        } else {
            $any = false;
            foreach ($gruplar as $g) {
                if (mb_stripos($g['grup_adi'] ?? '', $f_firma) !== false) { $any = true; break; }
            }
            if (!$any) continue;
        }
    }

    $toplam_fis++;
    $toplam_brut += $c['brut'];

    if (!$has_grup) {
        $toplam_net += $c['net'];
        $fk = $fis['firma_adi'] ?: '—';
        $firma_ozet[$fk] = ($firma_ozet[$fk] ?? 0.0) + $c['net'];

        $entries[] = [
            'fis'      => $fis,
            'calc'     => $c,
            'has_grup' => false,
            'dist'     => [[
                'firma'   => $fk,
                'palet'   => $c['palet_say'],
                'kasa'    => $c['kasa_say'],
                'brut_kg' => $c['brut'],
                'dara_kg' => $c['dara'],
                'net_kg'  => $c['net'],
            ]],
        ];
    } else {
        $toplam_grup_fis++;
        $dist = kf_grup_dist($gruplar, $c['brut'], $c['eff_kdu'], $c['eff_pdu']);

        // Firma filtresi: sadece eşleşen grupları dahil et
        if ($f_firma !== '') {
            $dist = array_values(array_filter($dist, fn($d) => mb_stripos($d['firma'], $f_firma) !== false));
        }

        foreach ($dist as $d) {
            $toplam_net += $d['net_kg'];
            $firma_ozet[$d['firma']] = ($firma_ozet[$d['firma']] ?? 0.0) + $d['net_kg'];
        }

        $entries[] = [
            'fis'      => $fis,
            'calc'     => $c,
            'has_grup' => true,
            'dist'     => $dist,
            'kp_rows'  => $kp_map[$fid] ?? [],
        ];
    }
}

arsort($firma_ozet);

// ── CSV export ────────────────────────────────────────────
if ($is_csv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kantar_raporu_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fiş No','Tarih','Plaka','Malın Cinsi','Parti No','Firma','Depo','Palet','Kasa','Brüt KG','Dara KG','Net KG','Tür'], ';', '"', '\\');
    foreach ($entries as $e) {
        $f = $e['fis'];
        foreach ($e['dist'] as $d) {
            fputcsv($out, [
                $f['fis_no'] ?: $f['id'],
                $f['giris_tarih'] ? date('d.m.Y H:i', strtotime($f['giris_tarih'])) : '',
                $f['plaka'],
                $f['malin_cinsi'],
                $f['parti_no'],
                $d['firma'],
                $f['depo'],
                $d['palet'],
                $d['kasa'],
                number_format($d['brut_kg'], 3, ',', '.'),
                number_format($d['dara_kg'], 3, ',', '.'),
                number_format($d['net_kg'],  3, ',', '.'),
                $e['has_grup'] ? 'Gruplandırılmış' : 'Tek Firma',
            ], ';', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

// ── Render ────────────────────────────────────────────────
render_header('Kantar Raporu');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>⚖️ Kantar Raporu</h1>
        <?php if ($entries): ?>
        <p class="muted"><?= $toplam_fis ?> fiş · <?= fmt_kg($toplam_net) ?> kg net</p>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="kantar.php" class="btn btn-ghost btn-sm">← Fişler</a>
        <a href="?<?= h(http_build_query(array_filter(['tarih_bas'=>$f_tarih_bas,'tarih_bit'=>$f_tarih_bit,'firma'=>$f_firma,'malin'=>$f_malin,'depo'=>$f_depo,'csv'=>'1'], fn($v)=>$v!==''))) ?>" class="btn btn-ghost btn-sm">⬇ CSV</a>
    </div>
</div>

<!-- Filtre -->
<div class="card" style="margin-bottom:14px">
    <form method="get" action="kantar_raporu.php" class="stok-filter-form">
        <div class="stok-filter-row" style="flex-wrap:wrap;gap:10px">
            <div class="form-group stok-fg">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="tarih_bas" value="<?= h($f_tarih_bas) ?>" class="form-control">
            </div>
            <div class="form-group stok-fg">
                <label class="form-label">Bitiş</label>
                <input type="date" name="tarih_bit" value="<?= h($f_tarih_bit) ?>" class="form-control">
            </div>
            <div class="form-group stok-fg">
                <label class="form-label">Firma / Grup</label>
                <input type="text" name="firma" value="<?= h($f_firma) ?>" class="form-control" placeholder="Firma ara…">
            </div>
            <div class="form-group stok-fg">
                <label class="form-label">Malın Cinsi</label>
                <input type="text" name="malin" value="<?= h($f_malin) ?>" class="form-control" placeholder="Ürün ara…">
            </div>
            <div class="form-group stok-fg">
                <label class="form-label">Depo</label>
                <input type="text" name="depo" value="<?= h($f_depo) ?>" class="form-control" placeholder="Depo ara…">
            </div>
            <div class="stok-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
                <a href="kantar_raporu.php" class="btn btn-ghost btn-sm">Sıfırla</a>
            </div>
        </div>
    </form>
</div>

<?php if (empty($entries)): ?>
<div class="empty"><p>Kriterlere uyan kantar fişi bulunamadı.</p></div>
<?php else: ?>

<!-- Özet kartlar -->
<div class="stats-grid" style="margin-bottom:16px">
    <div class="stat-card">
        <div class="stat-val"><?= $toplam_fis ?></div>
        <div class="stat-lbl">Toplam Fiş</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= fmt_kg($toplam_brut) ?></div>
        <div class="stat-lbl">Toplam Brüt KG</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= fmt_kg($toplam_net) ?></div>
        <div class="stat-lbl">Toplam Net KG</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= $toplam_grup_fis ?></div>
        <div class="stat-lbl">Gruplandırılmış Fiş</div>
    </div>
</div>

<!-- Fiş detay tablosu -->
<div class="card" style="margin-bottom:16px">
    <div class="card-head" style="display:flex;justify-content:space-between;align-items:center">
        <h2>Fiş Dağıtım Listesi</h2>
        <div style="font-size:.78rem;color:#6b7385">
            <span style="display:inline-block;width:10px;height:10px;background:#dbeafe;border:1px solid #93c5fd;border-radius:2px"></span> Tek Firma &nbsp;
            <span style="display:inline-block;width:10px;height:10px;background:#fef9c3;border:1px solid #fde68a;border-radius:2px"></span> Gruplandırılmış
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table kr-table">
            <thead>
                <tr>
                    <th>Fiş No</th>
                    <th>Tarih</th>
                    <th>Plaka</th>
                    <th>Malın Cinsi / Parti</th>
                    <th>Firma / Grup</th>
                    <th>Depo</th>
                    <th class="num">Palet</th>
                    <th class="num">Kasa</th>
                    <th class="num">Brüt KG</th>
                    <th class="num">Dara KG</th>
                    <th class="num">Net KG</th>
                    <th>Tür</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e):
                $f       = $e['fis'];
                $c       = $e['calc'];
                $dist    = $e['dist'];
                $grouped = $e['has_grup'];
                $kp_rows = $e['kp_rows'] ?? [];
                $row_class = $grouped ? 'kr-row-grup-fis' : 'kr-row-tek';
                $fis_url = 'kantar_view.php?id=' . (int)$f['id'];
                $tarih_disp = $f['giris_tarih'] ? fmt_datetime($f['giris_tarih']) : fmt_datetime($f['created_at']);
                $dist_count = count($dist);
            ?>

            <?php if ($grouped): ?>
                <!-- Gruplandırılmış fiş: başlık satırı -->
                <tr class="kr-row-grup-head">
                    <td colspan="12" class="kr-grup-head-cell">
                        <div class="kr-grup-head-inner">
                            <span>
                                <a href="<?= $fis_url ?>" class="kr-fis-link">#<?= h($f['fis_no'] ?: (string)$f['id']) ?></a>
                                <?= $tarih_disp ? ' · ' . h($tarih_disp) : '' ?>
                                <?= $f['plaka'] ? ' · ' . h($f['plaka']) : '' ?>
                                <?= $f['malin_cinsi'] ? ' · ' . h($f['malin_cinsi']) : '' ?>
                                <?= $f['parti_no'] ? ' <small>(' . h($f['parti_no']) . ')</small>' : '' ?>
                            </span>
                            <div class="kr-grup-head-totals">
                                <span class="kr-badge kr-badge-grup">GRUPLANDIRMIŞ · <?= $dist_count ?> grup</span>
                                <span>Brüt: <strong><?= fmt_kg($c['brut']) ?> kg</strong></span>
                                <span>Dara: <strong><?= fmt_kg($c['dara']) ?> kg</strong></span>
                                <span class="kr-net-total">Net: <strong><?= fmt_kg($c['net']) ?> kg</strong></span>
                            </div>
                        </div>
                        <?php if (!empty($kp_rows)): ?>
                        <div class="kr-kp-row-info">
                            <?php foreach ($kp_rows as $kp): ?>
                            <span class="kr-kp-chip">
                                <?= $kp['tip'] === 'palet' ? '🟦' : '📦' ?>
                                <?= h($kp['cinsi']) ?> × <?= (int)$kp['sayisi'] ?>
                                <?= $kp['birim_dara_kg'] > 0 ? '(' . fmt_kg($kp['birim_dara_kg']) . ' kg/adet)' : '' ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Grup dağıtım satırları -->
                <?php foreach ($dist as $i => $d): ?>
                <tr class="kr-row-grup-item">
                    <td class="kr-grup-indent" colspan="4">
                        <span class="kr-grup-no"><?= $i + 1 ?>.</span>
                        <?php if (!empty($f['depo'])): ?>
                        <span class="kr-grup-depo-chip"><?= h($f['depo']) ?></span>
                        <?php endif; ?>
                        <?php if ($d['is_manual'] ?? false): ?>
                        <span class="kr-badge-manual" title="Brüt elle girildi">elle</span>
                        <?php else: ?>
                        <span class="kr-badge-auto" title="Brüt orantılı dağıtıldı">oto</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= h($d['firma']) ?></strong></td>
                    <td><?= h($f['depo'] ?: '—') ?></td>
                    <td class="num"><?= $d['palet'] ?: '—' ?></td>
                    <td class="num"><?= $d['kasa']  ?: '—' ?></td>
                    <td class="num"><?= fmt_kg($d['brut_kg']) ?></td>
                    <td class="num kr-dara"><?= fmt_kg($d['dara_kg']) ?></td>
                    <td class="num kr-net"><strong><?= fmt_kg($d['net_kg']) ?></strong></td>
                    <td>—</td>
                </tr>
                <?php endforeach; ?>

            <?php else: /* ungrouped */
                $d = $dist[0]; ?>
                <tr class="kr-row-tek">
                    <td><a href="<?= $fis_url ?>" class="kr-fis-link">#<?= h($f['fis_no'] ?: (string)$f['id']) ?></a></td>
                    <td><?= h($tarih_disp) ?></td>
                    <td><?= h($f['plaka'] ?: '—') ?></td>
                    <td>
                        <?= h($f['malin_cinsi'] ?: '—') ?>
                        <?php if ($f['parti_no']): ?><br><small class="muted"><?= h($f['parti_no']) ?></small><?php endif; ?>
                    </td>
                    <td><strong><?= h($d['firma']) ?></strong></td>
                    <td><?= h($f['depo'] ?: '—') ?></td>
                    <td class="num"><?= $d['palet'] ?: '—' ?></td>
                    <td class="num"><?= $d['kasa']  ?: '—' ?></td>
                    <td class="num"><?= fmt_kg($d['brut_kg']) ?></td>
                    <td class="num kr-dara"><?= fmt_kg($d['dara_kg']) ?></td>
                    <td class="num kr-net"><strong><?= fmt_kg($d['net_kg']) ?></strong></td>
                    <td><span class="kr-badge kr-badge-tek">TEK</span></td>
                </tr>
            <?php endif; ?>

            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="kr-total-row">
                    <td colspan="8"><strong>TOPLAM</strong></td>
                    <td class="num"><strong><?= fmt_kg($toplam_brut) ?> kg</strong></td>
                    <td></td>
                    <td class="num kr-net"><strong><?= fmt_kg($toplam_net) ?> kg</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Firma bazlı özet -->
<?php if (count($firma_ozet) > 1): ?>
<div class="card">
    <div class="card-head"><h2>Firma / Grup Bazlı Net KG Özeti</h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Firma / Grup</th><th class="num">Toplam Net KG</th><th class="num">Pay %</th></tr></thead>
            <tbody>
            <?php foreach ($firma_ozet as $firma => $net): ?>
            <tr>
                <td><strong><?= h($firma) ?></strong></td>
                <td class="num"><?= fmt_kg($net) ?> kg</td>
                <td class="num">
                    <?= $toplam_net > 0 ? number_format($net / $toplam_net * 100, 1, ',', '.') . '%' : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td><strong>TOPLAM</strong></td>
                    <td class="num"><strong><?= fmt_kg($toplam_net) ?> kg</strong></td>
                    <td class="num">100%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<style>
/* ── Kantar Raporu Stilleri ────────────────────────────── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
@media (max-width: 767px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
.stat-card {
    background: #fff; border: 1px solid var(--border); border-radius: var(--radius);
    padding: 12px 16px; text-align: center;
    box-shadow: var(--shadow);
}
.stat-val { font-size: 1.4rem; font-weight: 700; color: var(--primary); line-height: 1.2; }
.stat-lbl { font-size: .78rem; color: var(--muted); margin-top: 3px; }

.kr-table { border-collapse: collapse; }

/* Tek-firma satırı */
.kr-row-tek { background: #eff6ff; }
.kr-row-tek:hover { background: #dbeafe; }

/* Gruplandırılmış fiş başlık satırı */
.kr-row-grup-head { background: #fefce8 !important; }
.kr-grup-head-cell { padding: 8px 10px !important; border-top: 2px solid #fde68a !important; }
.kr-grup-head-inner {
    display: flex; align-items: flex-start; justify-content: space-between;
    flex-wrap: wrap; gap: 6px;
}
.kr-grup-head-totals {
    display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
    font-size: .85rem;
}
.kr-net-total { color: #1a56db; }

/* Grup alt satırı */
.kr-row-grup-item { background: #fffdf0; }
.kr-row-grup-item:hover { background: #fef9c3; }
.kr-row-grup-item td:first-child { padding-left: 24px !important; }

.kr-grup-indent { color: #9ca3af; font-size: .82rem; }
.kr-grup-no { margin-right: 4px; font-weight: 600; color: #374151; }
.kr-grup-depo-chip {
    display: inline-block; padding: 1px 7px; border-radius: 10px;
    background: #e0e7ff; color: #3730a3; font-size: .75rem; margin-right: 4px;
}

/* kasa/palet chip'leri */
.kr-kp-row-info { margin-top: 5px; display: flex; gap: 6px; flex-wrap: wrap; }
.kr-kp-chip {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    background: #f3f4f6; border: 1px solid #d1d5db;
    font-size: .78rem; color: #374151;
}

/* Badgeler */
.kr-badge {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    font-size: .72rem; font-weight: 700; letter-spacing: .04em;
}
.kr-badge-tek  { background: #dbeafe; color: #1e40af; }
.kr-badge-grup { background: #fde68a; color: #92400e; }
.kr-badge-manual { font-size: .7rem; padding: 1px 5px; border-radius: 8px; background: #d1fae5; color: #065f46; }
.kr-badge-auto   { font-size: .7rem; padding: 1px 5px; border-radius: 8px; background: #e5e7eb; color: #374151; }

.kr-fis-link { font-weight: 700; color: var(--primary); text-decoration: none; }
.kr-fis-link:hover { text-decoration: underline; }

.kr-dara { color: #9ca3af; }
.kr-net  { color: #1a56db; }

.kr-total-row { background: #f1f5f9 !important; border-top: 2px solid #cbd5e1; }

@media (max-width: 767px) {
    .kr-grup-head-totals { font-size: .78rem; gap: 8px; }
    .kr-net-total strong { display: block; font-size: 1rem; }
}
@media print {
    .kr-row-tek { background: #f0f7ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .kr-row-grup-head { background: #fffde7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .kr-row-grup-item { background: #fffdf0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<?php render_footer(); ?>
