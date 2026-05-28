<?php
// =========================================================
// kantar_raporu.php — Kantar raporu: dağıtım + özet + yazdır
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$pdo = db();

// ── Filtre seçenek listeleri ──────────────────────────────
$filter_firms = $filter_malins = $filter_depolar = [];
try {
    $filter_firms = $pdo->query("
        SELECT DISTINCT v FROM (
            SELECT firma_adi AS v FROM kantar_fisleri WHERE firma_adi != ''
            UNION
            SELECT grup_adi  AS v FROM kantar_gruplar WHERE grup_adi  != ''
        ) t ORDER BY v
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
try {
    $filter_malins = $pdo->query(
        "SELECT DISTINCT malin_cinsi FROM kantar_fisleri WHERE malin_cinsi != '' ORDER BY malin_cinsi"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
try {
    $filter_depolar = $pdo->query(
        "SELECT DISTINCT depo FROM kantar_fisleri WHERE depo != '' ORDER BY depo"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// ── Filtre değerleri ──────────────────────────────────────
$f_tarih_bas = trim($_GET['tarih_bas'] ?? '');
$f_tarih_bit = trim($_GET['tarih_bit'] ?? '');
$f_firma     = trim($_GET['firma']     ?? '');
$f_malin     = trim($_GET['malin']     ?? '');
$f_depo      = trim($_GET['depo']      ?? '');
$is_csv      = isset($_GET['csv']);

// ── SQL ───────────────────────────────────────────────────
$where = []; $params = [];
if ($f_tarih_bas !== '') { $where[] = "kf.giris_tarih >= ?"; $params[] = $f_tarih_bas . ' 00:00:00'; }
if ($f_tarih_bit !== '') { $where[] = "kf.giris_tarih <= ?"; $params[] = $f_tarih_bit . ' 23:59:59'; }
if ($f_malin     !== '') { $where[] = "kf.malin_cinsi = ?";  $params[] = $f_malin; }
if ($f_depo      !== '') { $where[] = "kf.depo = ?";         $params[] = $f_depo; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("
    SELECT kf.id, kf.fis_no, kf.giris_tarih, kf.cikis_tarih,
           kf.plaka, kf.firma_adi, kf.operator_adi, kf.malin_cinsi, kf.geldigi_yer,
           kf.parti_no, kf.depo, kf.aciklama,
           kf.palet_sayisi, kf.kasa_sayisi, kf.kasa_cinsi, kf.palet_cinsi,
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

// ── Gruplar + kp satırları ────────────────────────────────
$grup_map = $kp_map = [];
if (!empty($fisleri)) {
    $ids = implode(',', array_map('intval', array_column($fisleri, 'id')));
    foreach ($pdo->query(
        "SELECT * FROM kantar_gruplar WHERE fis_id IN ($ids) ORDER BY fis_id, sira"
    )->fetchAll() as $g) {
        $grup_map[(int)$g['fis_id']][] = $g;
    }
    try {
        foreach ($pdo->query(
            "SELECT fis_id, tip, cinsi, sayisi, birim_dara_kg
             FROM kantar_kasa_palet_satir WHERE fis_id IN ($ids) ORDER BY fis_id, id"
        )->fetchAll() as $kp) {
            $kp_map[(int)$kp['fis_id']][] = $kp;
        }
    } catch (PDOException $e) {}
}

// Firma özetine kasa/palet tiplerini ekler (orantılı dağıtım)
function fo_add_types(string $fk, array $kp_rows, int $grp_kasa, int $grp_palet,
                      int $fis_kasa_total, int $fis_palet_total, array &$firma_ozet): void {
    foreach ($kp_rows as $kp) {
        $is_palet = $kp['tip'] === 'palet';
        $key   = $is_palet ? 'palet_types' : 'kasa_types';
        $total = $is_palet ? $fis_palet_total : $fis_kasa_total;
        $grp   = $is_palet ? $grp_palet : $grp_kasa;
        $ratio = $total > 0 ? $grp / $total : 1.0;
        $n     = (int)round((int)$kp['sayisi'] * $ratio);
        if ($n > 0) {
            $firma_ozet[$fk][$key][$kp['cinsi']] = ($firma_ozet[$fk][$key][$kp['cinsi']] ?? 0) + $n;
        }
    }
}

// ── Rapor verisi üret ─────────────────────────────────────
$entries         = [];
$firma_ozet      = [];
$toplam_brut     = $toplam_dara = $toplam_net = 0.0;
$toplam_kasa     = $toplam_palet = $toplam_fis = $toplam_grup_fis = 0;

foreach ($fisleri as $fis) {
    $fid      = (int)$fis['id'];
    $c        = kantar_calc($fis);
    $gruplar  = $grup_map[$fid] ?? [];
    $kp_rows  = $kp_map[$fid]   ?? [];
    $has_grup = !empty($gruplar);

    // Firma filtresi
    if ($f_firma !== '') {
        if (!$has_grup) {
            if ($fis['firma_adi'] !== $f_firma) continue;
        } else {
            $any = false;
            foreach ($gruplar as $g) { if ($g['grup_adi'] === $f_firma) { $any = true; break; } }
            if (!$any) continue;
        }
    }

    $toplam_fis++;
    $toplam_brut += $c['brut'];
    $toplam_dara += $c['dara'];

    // kp toplamları (oran hesabı için)
    $fis_kasa_kp = $fis_palet_kp = 0;
    foreach ($kp_rows as $kp) {
        if ($kp['tip'] === 'palet') $fis_palet_kp += (int)$kp['sayisi'];
        else                        $fis_kasa_kp  += (int)$kp['sayisi'];
    }
    // Legacy fallback
    if (empty($kp_rows)) {
        $fis_kasa_kp  = $c['kasa_say'];
        $fis_palet_kp = $c['palet_say'];
    }

    $fo_ensure = function(string $fk) use (&$firma_ozet): void {
        if (!isset($firma_ozet[$fk])) {
            $firma_ozet[$fk] = ['net_kg'=>0.0,'brut_kg'=>0.0,'dara_kg'=>0.0,
                                'kasa'=>0,'palet'=>0,'kasa_types'=>[],'palet_types'=>[]];
        }
    };

    if (!$has_grup) {
        $toplam_net   += $c['net'];
        $toplam_kasa  += $c['kasa_say'];
        $toplam_palet += $c['palet_say'];
        $fk = $fis['firma_adi'] ?: '—';
        $fo_ensure($fk);
        $firma_ozet[$fk]['net_kg']  += $c['net'];
        $firma_ozet[$fk]['brut_kg'] += $c['brut'];
        $firma_ozet[$fk]['dara_kg'] += $c['dara'];
        $firma_ozet[$fk]['kasa']    += $c['kasa_say'];
        $firma_ozet[$fk]['palet']   += $c['palet_say'];
        if (!empty($kp_rows)) {
            fo_add_types($fk, $kp_rows, $c['kasa_say'], $c['palet_say'],
                         $fis_kasa_kp, $fis_palet_kp, $firma_ozet);
        } elseif ($fis['kasa_cinsi'] !== '' && $c['kasa_say'] > 0) {
            $firma_ozet[$fk]['kasa_types'][$fis['kasa_cinsi']] =
                ($firma_ozet[$fk]['kasa_types'][$fis['kasa_cinsi']] ?? 0) + $c['kasa_say'];
        }
        $entries[] = ['fis'=>$fis,'calc'=>$c,'has_grup'=>false,'kp_rows'=>$kp_rows,
                      'dist'=>[['firma'=>$fk,'palet'=>$c['palet_say'],'kasa'=>$c['kasa_say'],
                                'brut_kg'=>$c['brut'],'dara_kg'=>$c['dara'],'net_kg'=>$c['net'],'is_manual'=>false]]];
    } else {
        $toplam_grup_fis++;
        $dist        = kantar_grup_dist($gruplar, $c['brut'], $c['eff_kdu'], $c['eff_pdu']);
        $rapor_sapma = abs(array_sum(array_column($dist, 'net_kg')) - $c['net']);
        if ($f_firma !== '')
            $dist = array_values(array_filter($dist, fn($d) => $d['firma'] === $f_firma));
        foreach ($dist as $d) {
            $toplam_net   += $d['net_kg'];
            $toplam_kasa  += $d['kasa'];
            $toplam_palet += $d['palet'];
            $fk = $d['firma'];
            $fo_ensure($fk);
            $firma_ozet[$fk]['net_kg']  += $d['net_kg'];
            $firma_ozet[$fk]['brut_kg'] += $d['brut_kg'];
            $firma_ozet[$fk]['dara_kg'] += $d['dara_kg'];
            $firma_ozet[$fk]['kasa']    += $d['kasa'];
            $firma_ozet[$fk]['palet']   += $d['palet'];
            if (!empty($kp_rows)) {
                fo_add_types($fk, $kp_rows, $d['kasa'], $d['palet'],
                             $fis_kasa_kp, $fis_palet_kp, $firma_ozet);
            }
        }
        $entries[] = ['fis'=>$fis,'calc'=>$c,'has_grup'=>true,'kp_rows'=>$kp_rows,'dist'=>$dist,'sapma'=>$rapor_sapma];
    }
}

uasort($firma_ozet, fn($a,$b) => $b['net_kg'] <=> $a['net_kg']);

// ── CSV export ────────────────────────────────────────────
if ($is_csv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kantar_raporu_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fiş No','Tarih','Plaka','Malın Cinsi','Parti No','Firma/Grup','Depo',
                   'Palet','Kasa','Brüt KG','Dara KG','Net KG','Tür'], ';', '"', '\\');
    foreach ($entries as $e) {
        $f = $e['fis'];
        foreach ($e['dist'] as $d) {
            fputcsv($out, [
                $f['fis_no'] ?: $f['id'],
                $f['giris_tarih'] ? date('d.m.Y H:i', strtotime($f['giris_tarih'])) : '',
                $f['plaka'], $f['malin_cinsi'], $f['parti_no'], $d['firma'], $f['depo'],
                $d['palet'], $d['kasa'],
                number_format($d['brut_kg'],3,',','.'),
                number_format($d['dara_kg'],3,',','.'),
                number_format($d['net_kg'], 3,',','.'),
                $e['has_grup'] ? 'Gruplandırılmış' : 'Tek Firma',
            ], ';', '"', '\\');
        }
    }
    fclose($out); exit;
}

// ── Render ────────────────────────────────────────────────
render_header('Kantar Raporu');
render_flash();

$filter_parts = [];
if ($f_tarih_bas || $f_tarih_bit)
    $filter_parts[] = ($f_tarih_bas ? fmt_date($f_tarih_bas) : '…') . ' – ' . ($f_tarih_bit ? fmt_date($f_tarih_bit) : '…');
if ($f_firma) $filter_parts[] = $f_firma;
if ($f_malin) $filter_parts[] = $f_malin;
if ($f_depo)  $filter_parts[] = $f_depo;
$filter_label = implode(' · ', $filter_parts) ?: 'Tüm kayıtlar';
?>

<!-- Baskı başlığı — sadece yazdırmada -->
<div class="kr-print-header">
    <div class="kr-ph-row">
        <div>
            <div class="kr-ph-title">⚖️ KANTAR — FİŞ DAĞITIM LİSTESİ</div>
            <div class="kr-ph-sub"><?= h($filter_label) ?></div>
        </div>
        <div class="kr-ph-right">
            <div><?= date('d.m.Y H:i') ?></div>
            <?php if ($toplam_fis): ?>
            <div><?= $toplam_fis ?> fiş · Brüt <?= fmt_kg($toplam_brut) ?> kg · Net <strong><?= fmt_kg($toplam_net) ?> kg</strong></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Ekran başlığı -->
<div class="page-head kr-no-print">
    <div>
        <h1>⚖️ Kantar Raporu</h1>
        <?php if ($entries): ?>
        <p class="muted"><?= $toplam_fis ?> fiş · <?= fmt_kg($toplam_net) ?> kg net</p>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a href="kantar.php" class="btn btn-ghost btn-sm">← Fişler</a>
        <a href="?<?= h(http_build_query(array_filter(['tarih_bas'=>$f_tarih_bas,'tarih_bit'=>$f_tarih_bit,'firma'=>$f_firma,'malin'=>$f_malin,'depo'=>$f_depo,'csv'=>'1'],fn($v)=>$v!==''))) ?>" class="btn btn-ghost btn-sm">⬇ CSV</a>
        <?php if ($entries): ?>
        <button onclick="window.print()" class="btn btn-primary btn-sm">🖨 Yazdır</button>
        <?php endif; ?>
    </div>
</div>

<!-- Filtre -->
<div class="card kr-no-print" style="margin-bottom:14px">
    <form method="get" action="kantar_raporu.php">
        <div class="kr-filter-grid">
            <div class="form-group">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="tarih_bas" value="<?= h($f_tarih_bas) ?>" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Bitiş</label>
                <input type="date" name="tarih_bit" value="<?= h($f_tarih_bit) ?>" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Firma / Grup</label>
                <select name="firma" class="form-control">
                    <option value="">— Tümü —</option>
                    <?php foreach ($filter_firms as $fw): ?>
                    <option value="<?= h($fw) ?>"<?= $f_firma===$fw?' selected':'' ?>><?= h($fw) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Malın Cinsi</label>
                <select name="malin" class="form-control">
                    <option value="">— Tümü —</option>
                    <?php foreach ($filter_malins as $mw): ?>
                    <option value="<?= h($mw) ?>"<?= $f_malin===$mw?' selected':'' ?>><?= h($mw) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Depo</label>
                <select name="depo" class="form-control">
                    <option value="">— Tümü —</option>
                    <?php foreach ($filter_depolar as $dw): ?>
                    <option value="<?= h($dw) ?>"<?= $f_depo===$dw?' selected':'' ?>><?= h($dw) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;gap:8px">
                <button type="submit" class="btn btn-primary" style="flex:1">Filtrele</button>
                <a href="kantar_raporu.php" class="btn btn-ghost">↺</a>
            </div>
        </div>
    </form>
</div>

<?php if (empty($entries)): ?>
<div class="empty"><p>Kriterlere uyan kantar fişi bulunamadı.</p></div>
<?php else: ?>

<?php if (count($fisleri) >= 2000): ?>
<div class="kr-limit-uyari kr-no-print">
    ℹ Bu rapor en fazla 2.000 fiş gösterir. Daha kesin sonuç için tarih aralığı filtresi kullanın.
</div>
<?php endif; ?>

<!-- Özet şerit -->
<div class="kr-stat-strip kr-no-print">
    <?php foreach ([
        ['Toplam Fiş',   $toplam_fis],
        ['Brüt KG',      fmt_kg($toplam_brut) . ' kg'],
        ['Dara KG',      fmt_kg($toplam_dara) . ' kg'],
        ['Net KG',       fmt_kg($toplam_net)  . ' kg'],
        ['Toplam Palet', $toplam_palet],
        ['Toplam Kasa',  $toplam_kasa],
        ['Gruplu Fiş',   $toplam_grup_fis],
    ] as [$lbl, $val]): ?>
    <div class="kr-stat-item">
        <div class="kr-stat-val"><?= $val ?></div>
        <div class="kr-stat-lbl"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Fiş Dağıtım Listesi ─────────────────────────────── -->
<div class="card" id="krDagilimCard" style="margin-bottom:14px">
    <div class="card-head kr-card-head-row">
        <h2>Fiş Dağıtım Listesi</h2>
        <div class="kr-legend kr-no-print">
            <span class="kr-leg-sw" style="background:#dbeafe;border-color:#93c5fd"></span>Tek
            <span class="kr-leg-sw" style="background:#fef9c3;border-color:#fde68a;margin-left:8px"></span>Gruplandırılmış
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table kr-table">
            <thead>
                <tr>
                    <th>Fiş No</th>
                    <th class="kr-col-tarih">Tarih</th>
                    <th>Plaka</th>
                    <th class="kr-col-malin">Malın Cinsi</th>
                    <th class="kr-col-firma">Firma / Grup</th>
                    <th class="kr-col-depo">Depo</th>
                    <th class="num">Palet</th>
                    <th class="num">Kasa</th>
                    <th class="num">Brüt KG</th>
                    <th class="num kr-col-dara">Dara KG</th>
                    <th class="num">Net KG</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e):
                $f       = $e['fis'];
                $c       = $e['calc'];
                $dist    = $e['dist'];
                $grouped = $e['has_grup'];
                $kp_rows = $e['kp_rows'];
                $fis_url = 'kantar_view.php?id=' . (int)$f['id'];
                $tarih_d = $f['giris_tarih'] ? fmt_datetime($f['giris_tarih']) : fmt_datetime($f['created_at']);
            ?>
            <?php if ($grouped): ?>
                <tr class="kr-row-grup-head">
                    <td colspan="11" class="kr-grup-head-cell">
                        <div class="kr-grup-head-inner">
                            <span>
                                <a href="<?= $fis_url ?>" class="kr-fis-link">#<?= h($f['fis_no']?:(string)$f['id']) ?></a>
                                <?= $tarih_d ? ' · '.h($tarih_d) : '' ?>
                                <?= $f['plaka'] ? ' · '.h($f['plaka']) : '' ?>
                                <?= $f['malin_cinsi'] ? ' · '.h($f['malin_cinsi']) : '' ?>
                                <?= $f['parti_no'] ? ' ('.h($f['parti_no']).')' : '' ?>
                            </span>
                            <div class="kr-grup-head-totals">
                                <span class="kr-badge kr-badge-grup"><?= count($dist) ?> grup</span>
                                <span>Brüt: <strong><?= fmt_kg($c['brut']) ?> kg</strong></span>
                                <span>Dara: <strong><?= fmt_kg($c['dara']) ?> kg</strong></span>
                                <span class="kr-net-hi">Net: <strong><?= fmt_kg($c['net']) ?> kg</strong></span>
                            </div>
                        </div>
                        <?php if (!empty($kp_rows)): ?>
                        <div class="kr-kp-chips">
                            <?php foreach ($kp_rows as $kp): ?>
                            <span class="kr-kp-chip"><?= $kp['tip']==='palet'?'▪':'▫' ?> <?= h($kp['cinsi']) ?> ×<?= (int)$kp['sayisi'] ?><?= $kp['birim_dara_kg']>0?' ('.fmt_kg($kp['birim_dara_kg']).'kg)':'' ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (($e['sapma'] ?? 0) > 1.0): ?>
                        <div class="kr-sapma-uyari">
                            ⚠ Grup toplamı ile fiş neti arasında
                            <strong><?= fmt_kg($e['sapma']) ?> kg</strong> fark var.
                            Stok dağılımı hatalı görünebilir.
                            <span class="kr-sapma-nums">Fiş neti: <?= fmt_kg($c['net']) ?> kg</span>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php foreach ($dist as $i => $d): ?>
                <tr class="kr-row-grup-item">
                    <td class="kr-grup-indent" colspan="4">
                        <span class="kr-grup-no"><?= $i+1 ?>.</span>
                        <?php if ($f['depo']): ?><span class="kr-depo-chip"><?= h($f['depo']) ?></span><?php endif; ?>
                        <span class="<?= ($d['is_manual']??false)?'kr-tag-elle':'kr-tag-oto' ?>"><?= ($d['is_manual']??false)?'elle':'oto' ?></span>
                    </td>
                    <td class="kr-col-firma"><strong><?= h($d['firma']) ?></strong></td>
                    <td class="kr-col-depo"><?= h($f['depo']?:'—') ?></td>
                    <td class="num"><?= $d['palet']?:0 ?></td>
                    <td class="num"><?= $d['kasa'] ?></td>
                    <td class="num"><?= fmt_kg($d['brut_kg']) ?></td>
                    <td class="num kr-col-dara kr-dara"><?= fmt_kg($d['dara_kg']) ?></td>
                    <td class="num kr-net"><strong><?= fmt_kg($d['net_kg']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            <?php else:
                $d = $dist[0]; ?>
                <tr class="kr-row-tek">
                    <td><a href="<?= $fis_url ?>" class="kr-fis-link">#<?= h($f['fis_no']?:(string)$f['id']) ?></a></td>
                    <td class="kr-col-tarih"><?= h($tarih_d) ?></td>
                    <td><?= h($f['plaka']?:'—') ?></td>
                    <td class="kr-col-malin"><?= h($f['malin_cinsi']?:'—') ?><?= $f['parti_no'] ? '<br><small class="muted">'.h($f['parti_no']).'</small>' : '' ?></td>
                    <td class="kr-col-firma"><strong><?= h($d['firma']) ?></strong></td>
                    <td class="kr-col-depo"><?= h($f['depo']?:'—') ?></td>
                    <td class="num"><?= $d['palet']?:0 ?></td>
                    <td class="num"><?= $d['kasa'] ?></td>
                    <td class="num"><?= fmt_kg($d['brut_kg']) ?></td>
                    <td class="num kr-col-dara kr-dara"><?= fmt_kg($d['dara_kg']) ?></td>
                    <td class="num kr-net"><strong><?= fmt_kg($d['net_kg']) ?></strong></td>
                </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="kr-total-row">
                    <td colspan="6"><strong>TOPLAM</strong></td>
                    <td class="num"><strong><?= $toplam_palet ?></strong></td>
                    <td class="num"><strong><?= $toplam_kasa ?></strong></td>
                    <td class="num"><strong><?= fmt_kg($toplam_brut) ?></strong></td>
                    <td class="num kr-col-dara kr-dara"><strong><?= fmt_kg($toplam_dara) ?></strong></td>
                    <td class="num kr-net"><strong><?= fmt_kg($toplam_net) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ── Firma / Grup Özeti — SADECE EKRANDA ─────────────── -->
<div class="kr-no-print">

    <!-- Desktop tablo -->
    <div class="card pc-only">
        <div class="card-head"><h2>Firma / Grup Özeti</h2></div>
        <div class="table-wrap">
            <table class="data-table kr-ozet-table">
                <thead>
                    <tr>
                        <th>Firma / Grup</th>
                        <th class="num">Palet</th>
                        <th class="num">Kasa</th>
                        <th class="num">Brüt KG</th>
                        <th class="num">Dara KG</th>
                        <th class="num">Net KG</th>
                        <th class="num">Pay %</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($firma_ozet as $firma => $fo): ?>
                <tr>
                    <td>
                        <strong><?= h($firma) ?></strong>
                        <?php
                        $chips = [];
                        foreach ($fo['palet_types'] as $cinsi => $adet) $chips[] = '<span class="kr-kp-chip">▪ '.h($cinsi).' ×'.$adet.'</span>';
                        foreach ($fo['kasa_types']  as $cinsi => $adet) $chips[] = '<span class="kr-kp-chip">▫ '.h($cinsi).' ×'.$adet.'</span>';
                        if ($chips) echo '<div class="kr-type-chips">'.implode('', $chips).'</div>';
                        ?>
                    </td>
                    <td class="num"><?= $fo['palet'] ?></td>
                    <td class="num"><?= $fo['kasa']  ?></td>
                    <td class="num"><?= fmt_kg($fo['brut_kg']) ?></td>
                    <td class="num kr-dara"><?= fmt_kg($fo['dara_kg']) ?></td>
                    <td class="num kr-net"><strong><?= fmt_kg($fo['net_kg']) ?></strong></td>
                    <td class="num"><?= $toplam_net>0 ? number_format($fo['net_kg']/$toplam_net*100,1,',','.') . '%' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="kr-total-row">
                        <td><strong>TOPLAM</strong></td>
                        <td class="num"><strong><?= $toplam_palet ?></strong></td>
                        <td class="num"><strong><?= $toplam_kasa ?></strong></td>
                        <td class="num"><strong><?= fmt_kg($toplam_brut) ?></strong></td>
                        <td class="num kr-dara"><strong><?= fmt_kg($toplam_dara) ?></strong></td>
                        <td class="num kr-net"><strong><?= fmt_kg($toplam_net) ?></strong></td>
                        <td class="num">100%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Mobil kartlar -->
    <div class="mobile-only">
        <h3 style="margin:0 0 10px;font-size:1rem">Firma / Grup Özeti</h3>
        <?php foreach ($firma_ozet as $firma => $fo):
            $pay = $toplam_net > 0 ? number_format($fo['net_kg']/$toplam_net*100, 1, ',', '.') . '%' : '—';
        ?>
        <div class="kr-fo-card">
            <div class="kr-fo-card-head">
                <strong><?= h($firma) ?></strong>
                <span class="kr-net" style="font-size:1rem;font-weight:700"><?= fmt_kg($fo['net_kg']) ?> kg</span>
            </div>
            <div class="kr-fo-card-row">
                <span>Brüt</span><strong><?= fmt_kg($fo['brut_kg']) ?> kg</strong>
                <span style="margin-left:12px">Dara</span><strong class="kr-dara"><?= fmt_kg($fo['dara_kg']) ?> kg</strong>
                <span style="margin-left:12px">Pay</span><strong><?= $pay ?></strong>
            </div>
            <div class="kr-fo-card-row">
                <?php if ($fo['palet']): ?><span>🟦 <?= $fo['palet'] ?> palet</span><?php endif; ?>
                <?php if ($fo['kasa']):  ?><span style="margin-left:10px">📦 <?= $fo['kasa'] ?> kasa</span><?php endif; ?>
            </div>
            <?php
            $all_chips = [];
            foreach ($fo['palet_types'] as $cinsi => $adet) $all_chips[] = '▪ '.h($cinsi).' ×'.$adet;
            foreach ($fo['kasa_types']  as $cinsi => $adet) $all_chips[] = '▫ '.h($cinsi).' ×'.$adet;
            if ($all_chips): ?>
            <div class="kr-fo-card-types">
                <?php foreach ($all_chips as $ch): ?>
                <span class="kr-kp-chip"><?= $ch ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <!-- Mobil toplam -->
        <div class="kr-fo-card kr-fo-total">
            <div class="kr-fo-card-head">
                <strong>TOPLAM</strong>
                <span class="kr-net" style="font-size:1rem;font-weight:700"><?= fmt_kg($toplam_net) ?> kg</span>
            </div>
            <div class="kr-fo-card-row">
                <span>Brüt</span><strong><?= fmt_kg($toplam_brut) ?> kg</strong>
                <span style="margin-left:12px">Dara</span><strong class="kr-dara"><?= fmt_kg($toplam_dara) ?> kg</strong>
            </div>
            <div class="kr-fo-card-row">
                <?php if ($toplam_palet): ?><span>🟦 <?= $toplam_palet ?> palet</span><?php endif; ?>
                <?php if ($toplam_kasa):  ?><span style="margin-left:10px">📦 <?= $toplam_kasa ?> kasa</span><?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /kr-no-print -->

<?php endif; ?>

<style>
/* ── Kantar Raporu ─────────────────────────────────────── */
.kr-filter-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr) repeat(3, 1fr);
    gap: 10px 14px; padding: 14px;
}
@media (max-width:900px) { .kr-filter-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width:540px) { .kr-filter-grid { grid-template-columns: 1fr; } }

.kr-stat-strip {
    display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px;
}
.kr-stat-item {
    flex: 1 1 80px; min-width: 70px;
    background: #fff; border: 1px solid var(--border);
    border-radius: var(--radius); padding: 8px 12px; text-align: center;
    box-shadow: var(--shadow);
}
.kr-stat-val { font-size: 1rem; font-weight: 700; color: var(--primary); }
.kr-stat-lbl { font-size: .7rem; color: var(--muted); margin-top: 2px; }

.kr-card-head-row { display:flex; justify-content:space-between; align-items:center; }
.kr-legend { display:flex; align-items:center; gap:4px; font-size:.78rem; color:#6b7385; }
.kr-leg-sw { display:inline-block; width:12px; height:12px; border:1px solid; border-radius:2px; }

/* Tablo genel */
.kr-table { border-collapse:collapse; }
.kr-table th { white-space: nowrap; }
.kr-col-firma { white-space: nowrap; }

/* Sütun gizleme — dar ekranlarda */
@media (max-width:600px) {
    .kr-col-malin, .kr-col-depo, .kr-col-dara, .kr-col-tarih { display:none; }
    .kr-grup-head-inner { flex-direction:column; gap:3px; }
    .kr-grup-head-totals { font-size:.78rem; gap:5px; }
}

/* Tek-firma satırı */
.kr-row-tek { background:#eff6ff; }
.kr-row-tek:hover { background:#dbeafe; }

/* Gruplandırılmış başlık */
.kr-row-grup-head { background:#fefce8 !important; }
.kr-grup-head-cell { padding:8px 10px !important; border-top:2px solid #fde68a !important; }
.kr-grup-head-inner {
    display:flex; align-items:flex-start; justify-content:space-between;
    flex-wrap:wrap; gap:6px;
}
.kr-grup-head-totals { display:flex; gap:10px; align-items:center; flex-wrap:wrap; font-size:.85rem; }
.kr-net-hi { color:#1a56db; }

/* Grup alt satırı */
.kr-row-grup-item { background:#fffdf0; }
.kr-row-grup-item:hover { background:#fef9c3; }

.kr-grup-indent { color:#9ca3af; font-size:.82rem; }
.kr-grup-no { margin-right:4px; font-weight:600; color:#374151; }
.kr-depo-chip {
    display:inline-block; padding:1px 7px; border-radius:10px;
    background:#e0e7ff; color:#3730a3; font-size:.75rem; margin-right:4px;
}
.kr-kp-chips { margin-top:5px; display:flex; gap:5px; flex-wrap:wrap; }
.kr-kp-chip {
    display:inline-block; padding:2px 7px; border-radius:10px;
    background:#f3f4f6; border:1px solid #d1d5db; font-size:.75rem; color:#374151;
}
.kr-type-chips { margin-top:4px; display:flex; gap:4px; flex-wrap:wrap; }

.kr-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:700; }
.kr-badge-grup { background:#fde68a; color:#92400e; }
.kr-tag-elle { font-size:.7rem; padding:1px 5px; border-radius:8px; background:#d1fae5; color:#065f46; }
.kr-tag-oto  { font-size:.7rem; padding:1px 5px; border-radius:8px; background:#e5e7eb; color:#374151; }

.kr-fis-link { font-weight:700; color:var(--primary); text-decoration:none; }
.kr-fis-link:hover { text-decoration:underline; }
.kr-dara { color:#9ca3af; }
.kr-net  { color:#1a56db; }
.kr-total-row { background:#f1f5f9 !important; border-top:2px solid #cbd5e1; }

/* Özet tablo */
.kr-ozet-table { font-size:.88rem; }

/* Mobil firma kartları */
.kr-fo-card {
    background:#fff; border:1px solid var(--border); border-radius:var(--radius);
    padding:12px 14px; margin-bottom:10px; box-shadow:var(--shadow);
}
.kr-fo-card-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.kr-fo-card-row  { font-size:.85rem; color:#374151; margin-bottom:4px; display:flex; flex-wrap:wrap; align-items:center; gap:4px; }
.kr-fo-card-types { margin-top:6px; display:flex; flex-wrap:wrap; gap:4px; }
.kr-fo-total { background:#f1f5f9; border-color:#cbd5e1; }

/* Sapma uyarısı */
.kr-sapma-uyari {
    font-size:.82rem; color:#7f1d1d;
    background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.3);
    border-radius:4px; padding:4px 10px; margin-top:5px; display:inline-block;
}
.kr-sapma-nums { margin-left:8px; opacity:.75; }

/* LIMIT uyarısı */
.kr-limit-uyari {
    background:rgba(59,130,246,.07); border:1px solid rgba(59,130,246,.22);
    border-radius:var(--radius); padding:8px 14px; font-size:.86rem;
    color:#1e40af; margin-bottom:10px;
}

/* Print header — ekranda gizli */
.kr-print-header { display:none; }

/* ── Baskı ──────────────────────────────────────────────── */
@page { size: A4 landscape; margin: 8mm; }

@media print {
    .topbar, .bottomnav, .kr-no-print { display:none !important; }
    .kr-print-header { display:block !important; margin-bottom:5mm; }

    .kr-ph-row {
        display:flex; justify-content:space-between; align-items:flex-start;
        border-bottom:2pt solid #000; padding-bottom:3mm; margin-bottom:3mm;
    }
    .kr-ph-title  { font-size:12pt; font-weight:800; }
    .kr-ph-sub    { font-size:8pt; color:#555; margin-top:2px; }
    .kr-ph-right  { text-align:right; font-size:8pt; line-height:1.6; }

    html, body { font-size:8pt !important; }
    body, .container { background:#fff !important; padding:0 !important; margin:0 !important; }
    .container { padding-bottom:0 !important; max-width:100% !important; }

    .card { border:1pt solid #ccc !important; box-shadow:none !important; margin-bottom:3mm !important; page-break-inside:avoid; }
    .card-head { border-bottom:1pt solid #ccc !important; padding:3px 8px !important; }
    .card-head h2 { font-size:8pt !important; font-weight:700; margin:0 !important; }

    .data-table { font-size:7pt !important; }
    .data-table th { font-size:6pt !important; padding:2px 3px !important; background:#f5f5f5 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .data-table td { padding:2px 3px !important; }

    .kr-col-malin, .kr-col-depo, .kr-col-dara, .kr-col-tarih { display:table-cell !important; }
    .kr-col-firma { white-space:normal; }

    .kr-grup-head-cell { padding:4px 6px !important; }
    .kr-grup-head-totals { font-size:7pt !important; gap:6px !important; }
    .kr-kp-chips { margin-top:2px !important; }
    .kr-kp-chip { font-size:6pt !important; padding:1px 3px !important; }

    .kr-row-tek       { background:#eef5ff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .kr-row-grup-head { background:#fffde7 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .kr-row-grup-item { background:#fffdf0 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .kr-total-row     { background:#f0f4f8 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }

    .table-wrap { overflow:visible !important; }
    a { color:#000 !important; text-decoration:none !important; }
    .kr-sapma-uyari {
        background:#fef2f2 !important; border:1pt solid #ef4444 !important;
        padding:2px 6px !important; font-size:6pt !important; color:#7f1d1d !important;
        -webkit-print-color-adjust:exact; print-color-adjust:exact; display:block;
    }
}
</style>

<?php render_footer(); ?>
