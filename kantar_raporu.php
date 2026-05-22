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

// ── Gruplar + kasa/palet satırları toplu yükle ────────────
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

// ── Hesap yardımcıları ────────────────────────────────────
function kf_calc(array $fis): array {
    $t1  = (float)($fis['tartim1'] ?? 0);
    $t2  = (float)($fis['tartim2'] ?? 0);
    $brut = max(0.0, $t1 - $t2);
    $ks  = (int)($fis['kasa_sayisi']  ?? 0);
    $ps  = (int)($fis['palet_sayisi'] ?? 0);
    $kdt = (float)($fis['kasa_dara_total']  ?? 0);
    $pdt = (float)($fis['palet_dara_total'] ?? 0);
    $kdu = (float)($fis['kasa_dara']  ?? 0);
    $pdu = (float)($fis['palet_dara'] ?? 0);
    $dara = ($kdt > 0 || $pdt > 0) ? $kdt + $pdt : $ks * $kdu + $ps * $pdu;
    $net  = max(0.0, $brut - $dara);
    $eff_kdu = ($kdt > 0 && $ks > 0) ? $kdt / $ks : $kdu;
    $eff_pdu = ($pdt > 0 && $ps > 0) ? $pdt / $ps : $pdu;
    return ['brut'=>$brut,'dara'=>$dara,'net'=>$net,
            'eff_kdu'=>$eff_kdu,'eff_pdu'=>$eff_pdu,'kasa_say'=>$ks,'palet_say'=>$ps];
}

function kf_grup_dist(array $gruplar, float $brut, float $eff_kdu, float $eff_pdu): array {
    $manual_sum = 0.0; $auto_weight = 0;
    foreach ($gruplar as $g) {
        $gb = (float)($g['brut_kg'] ?? 0);
        if ($gb > 0) $manual_sum  += $gb;
        else         $auto_weight += (int)$g['kasa_adedi'] + (int)$g['palet_sayisi'];
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
        $rows[] = ['firma'=>$g['grup_adi']?:'—','palet'=>$gp,'kasa'=>$gk,
                   'brut_kg'=>$gbrut,'dara_kg'=>$gdara,'net_kg'=>$gnet,'is_manual'=>$gb>0];
    }
    return $rows;
}

// ── Rapor verisi üret ─────────────────────────────────────
$entries         = [];
$firma_ozet      = [];           // firma → [net_kg, brut_kg, dara_kg, kasa, palet]
$kp_type_ozet    = ['kasa'=>[], 'palet'=>[]];  // tip → cinsi → toplam adet
$toplam_brut     = $toplam_dara = $toplam_net = 0.0;
$toplam_kasa     = $toplam_palet = $toplam_fis = $toplam_grup_fis = 0;

foreach ($fisleri as $fis) {
    $fid      = (int)$fis['id'];
    $c        = kf_calc($fis);
    $gruplar  = $grup_map[$fid] ?? [];
    $kp_rows  = $kp_map[$fid]   ?? [];
    $has_grup = !empty($gruplar);

    // Firma filtresi (exact match for select)
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

    // Kasa/palet tip toplamları (fiş düzeyinde — firma ayrımı yapılmaz, global)
    if (!empty($kp_rows)) {
        foreach ($kp_rows as $kp) {
            $t = $kp['tip'] === 'palet' ? 'palet' : 'kasa';
            $kp_type_ozet[$t][$kp['cinsi']] = ($kp_type_ozet[$t][$kp['cinsi']] ?? 0) + (int)$kp['sayisi'];
        }
    } else {
        // Eski fiş formatı (tek tip kasa/palet)
        if (($fis['kasa_cinsi'] ?? '') !== '' && $c['kasa_say'] > 0)
            $kp_type_ozet['kasa'][$fis['kasa_cinsi']] = ($kp_type_ozet['kasa'][$fis['kasa_cinsi']] ?? 0) + $c['kasa_say'];
        if (($fis['palet_cinsi'] ?? '') !== '' && $c['palet_say'] > 0)
            $kp_type_ozet['palet'][$fis['palet_cinsi']] = ($kp_type_ozet['palet'][$fis['palet_cinsi']] ?? 0) + $c['palet_say'];
    }

    $fo_add = function(string $fk, float $brut, float $dara, float $net, int $kasa, int $palet) use (&$firma_ozet) {
        if (!isset($firma_ozet[$fk])) $firma_ozet[$fk] = ['net_kg'=>0.0,'brut_kg'=>0.0,'dara_kg'=>0.0,'kasa'=>0,'palet'=>0];
        $firma_ozet[$fk]['net_kg']  += $net;
        $firma_ozet[$fk]['brut_kg'] += $brut;
        $firma_ozet[$fk]['dara_kg'] += $dara;
        $firma_ozet[$fk]['kasa']    += $kasa;
        $firma_ozet[$fk]['palet']   += $palet;
    };

    if (!$has_grup) {
        $toplam_net   += $c['net'];
        $toplam_kasa  += $c['kasa_say'];
        $toplam_palet += $c['palet_say'];
        $fk = $fis['firma_adi'] ?: '—';
        $fo_add($fk, $c['brut'], $c['dara'], $c['net'], $c['kasa_say'], $c['palet_say']);
        $entries[] = ['fis'=>$fis,'calc'=>$c,'has_grup'=>false,'kp_rows'=>$kp_rows,
                      'dist'=>[['firma'=>$fk,'palet'=>$c['palet_say'],'kasa'=>$c['kasa_say'],
                                'brut_kg'=>$c['brut'],'dara_kg'=>$c['dara'],'net_kg'=>$c['net'],'is_manual'=>false]]];
    } else {
        $toplam_grup_fis++;
        $dist = kf_grup_dist($gruplar, $c['brut'], $c['eff_kdu'], $c['eff_pdu']);
        if ($f_firma !== '')
            $dist = array_values(array_filter($dist, fn($d) => $d['firma'] === $f_firma));
        foreach ($dist as $d) {
            $toplam_net   += $d['net_kg'];
            $toplam_kasa  += $d['kasa'];
            $toplam_palet += $d['palet'];
            $fo_add($d['firma'], $d['brut_kg'], $d['dara_kg'], $d['net_kg'], $d['kasa'], $d['palet']);
        }
        $entries[] = ['fis'=>$fis,'calc'=>$c,'has_grup'=>true,'kp_rows'=>$kp_rows,'dist'=>$dist];
    }
}

uasort($firma_ozet, fn($a,$b) => $b['net_kg'] <=> $a['net_kg']);
arsort($kp_type_ozet['kasa']);
arsort($kp_type_ozet['palet']);

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

$filter_label_parts = [];
if ($f_tarih_bas || $f_tarih_bit)
    $filter_label_parts[] = ($f_tarih_bas ? fmt_date($f_tarih_bas) : '…') . ' – ' . ($f_tarih_bit ? fmt_date($f_tarih_bit) : '…');
if ($f_firma)  $filter_label_parts[] = 'Firma: ' . $f_firma;
if ($f_malin)  $filter_label_parts[] = 'Ürün: ' . $f_malin;
if ($f_depo)   $filter_label_parts[] = 'Depo: ' . $f_depo;
$filter_label = implode(' · ', $filter_label_parts) ?: 'Tüm kayıtlar';
?>

<!-- Baskı başlığı — sadece yazdırmada görünür -->
<div class="kr-print-header">
    <div class="kr-ph-row">
        <div>
            <div class="kr-ph-title">⚖️ KANTAR RAPORU</div>
            <div class="kr-ph-sub"><?= h($filter_label) ?></div>
        </div>
        <div class="kr-ph-right">
            <div>Tarih: <?= date('d.m.Y H:i') ?></div>
            <?php if ($toplam_fis): ?>
            <div><?= $toplam_fis ?> fiş · <?= $toplam_kasa ?> kasa · <?= $toplam_palet ?> palet</div>
            <div>Net: <strong><?= fmt_kg($toplam_net) ?> kg</strong></div>
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
        <button onclick="printKantar('landscape')" class="btn btn-sm">🖨 Yatay</button>
        <button onclick="printKantar('portrait')"  class="btn btn-sm">🖨 Dikey</button>
        <?php endif; ?>
    </div>
</div>

<!-- Filtre formu -->
<div class="card kr-no-print" style="margin-bottom:14px">
    <form method="get" action="kantar_raporu.php">
        <div class="kr-filter-grid">
            <div class="form-group">
                <label class="form-label">Başlangıç Tarihi</label>
                <input type="date" name="tarih_bas" value="<?= h($f_tarih_bas) ?>" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Bitiş Tarihi</label>
                <input type="date" name="tarih_bit" value="<?= h($f_tarih_bit) ?>" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Firma / Grup</label>
                <select name="firma" class="form-control">
                    <option value="">— Tümü —</option>
                    <?php foreach ($filter_firms as $fw): ?>
                    <option value="<?= h($fw) ?>"<?= $f_firma === $fw ? ' selected' : '' ?>><?= h($fw) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Malın Cinsi</label>
                <select name="malin" class="form-control">
                    <option value="">— Tümü —</option>
                    <?php foreach ($filter_malins as $mw): ?>
                    <option value="<?= h($mw) ?>"<?= $f_malin === $mw ? ' selected' : '' ?>><?= h($mw) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Depo</label>
                <select name="depo" class="form-control">
                    <option value="">— Tümü —</option>
                    <?php foreach ($filter_depolar as $dw): ?>
                    <option value="<?= h($dw) ?>"<?= $f_depo === $dw ? ' selected' : '' ?>><?= h($dw) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group kr-filter-actions">
                <label class="form-label">&nbsp;</label>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Filtrele</button>
                    <a href="kantar_raporu.php" class="btn btn-ghost">Sıfırla</a>
                </div>
            </div>
        </div>
    </form>
</div>

<?php if (empty($entries)): ?>
<div class="empty"><p>Kriterlere uyan kantar fişi bulunamadı.</p></div>
<?php else: ?>

<!-- Özet kartlar -->
<div class="kr-stat-grid kr-no-print">
    <?php foreach ([
        [$toplam_fis,                       'Toplam Fiş'],
        [fmt_kg($toplam_brut) . ' kg',      'Toplam Brüt'],
        [fmt_kg($toplam_dara) . ' kg',      'Toplam Dara'],
        [fmt_kg($toplam_net)  . ' kg',      'Toplam Net'],
        [$toplam_palet,                     'Toplam Palet'],
        [$toplam_kasa,                      'Toplam Kasa'],
        [$toplam_grup_fis,                  'Gruplandırılmış'],
    ] as [$val, $lbl]): ?>
    <div class="kr-stat-card">
        <div class="kr-stat-val"><?= $val ?></div>
        <div class="kr-stat-lbl"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Fiş dağıtım tablosu ─────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <div class="card-head kr-card-head-row">
        <h2>Fiş Dağıtım Listesi</h2>
        <div class="kr-legend kr-no-print">
            <span class="kr-leg-swatch" style="background:#dbeafe;border-color:#93c5fd"></span>Tek Firma
            <span class="kr-leg-swatch" style="background:#fef9c3;border-color:#fde68a;margin-left:10px"></span>Gruplandırılmış
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
                    <th class="kr-no-print">Tür</th>
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
                    <td colspan="12" class="kr-grup-head-cell">
                        <div class="kr-grup-head-inner">
                            <span>
                                <a href="<?= $fis_url ?>" class="kr-fis-link kr-no-print">#<?= h($f['fis_no'] ?: (string)$f['id']) ?></a>
                                <span class="kr-print-only">#<?= h($f['fis_no'] ?: (string)$f['id']) ?></span>
                                <?= $tarih_d  ? ' · ' . h($tarih_d) : '' ?>
                                <?= $f['plaka'] ? ' · ' . h($f['plaka']) : '' ?>
                                <?= $f['malin_cinsi'] ? ' · ' . h($f['malin_cinsi']) : '' ?>
                                <?= $f['parti_no'] ? ' (' . h($f['parti_no']) . ')' : '' ?>
                            </span>
                            <div class="kr-grup-head-totals">
                                <span class="kr-badge kr-badge-grup kr-no-print">GRUPLANDIRMIŞ · <?= count($dist) ?> grup</span>
                                <span>Brüt: <strong><?= fmt_kg($c['brut']) ?> kg</strong></span>
                                <span>Dara: <strong><?= fmt_kg($c['dara']) ?> kg</strong></span>
                                <span class="kr-net-total">Net: <strong><?= fmt_kg($c['net']) ?> kg</strong></span>
                            </div>
                        </div>
                        <?php if (!empty($kp_rows)): ?>
                        <div class="kr-kp-row-info">
                            <?php foreach ($kp_rows as $kp): ?>
                            <span class="kr-kp-chip"><?= $kp['tip']==='palet'?'▪':'▫' ?> <?= h($kp['cinsi']) ?> ×<?= (int)$kp['sayisi'] ?><?= $kp['birim_dara_kg']>0?' ('.fmt_kg($kp['birim_dara_kg']).'kg)':'' ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php foreach ($dist as $i => $d): ?>
                <tr class="kr-row-grup-item">
                    <td class="kr-grup-indent" colspan="4">
                        <span class="kr-grup-no"><?= $i+1 ?>.</span>
                        <?php if ($f['depo']): ?><span class="kr-depo-chip"><?= h($f['depo']) ?></span><?php endif; ?>
                        <span class="kr-no-print <?= ($d['is_manual']??false)?'kr-badge-manual':'kr-badge-auto' ?>"><?= ($d['is_manual']??false)?'elle':'oto' ?></span>
                    </td>
                    <td><strong><?= h($d['firma']) ?></strong></td>
                    <td><?= h($f['depo']?:'—') ?></td>
                    <td class="num"><?= $d['palet']?:0 ?></td>
                    <td class="num"><?= $d['kasa'] ?></td>
                    <td class="num"><?= fmt_kg($d['brut_kg']) ?></td>
                    <td class="num kr-dara"><?= fmt_kg($d['dara_kg']) ?></td>
                    <td class="num kr-net"><strong><?= fmt_kg($d['net_kg']) ?></strong></td>
                    <td class="kr-no-print">—</td>
                </tr>
                <?php endforeach; ?>
            <?php else:
                $d = $dist[0]; ?>
                <tr class="kr-row-tek">
                    <td><a href="<?= $fis_url ?>" class="kr-fis-link kr-no-print">#<?= h($f['fis_no']?:(string)$f['id']) ?></a><span class="kr-print-only">#<?= h($f['fis_no']?:(string)$f['id']) ?></span></td>
                    <td><?= h($tarih_d) ?></td>
                    <td><?= h($f['plaka']?:'—') ?></td>
                    <td><?= h($f['malin_cinsi']?:'—') ?><?= $f['parti_no'] ? '<br><small>'.h($f['parti_no']).'</small>' : '' ?></td>
                    <td><strong><?= h($d['firma']) ?></strong></td>
                    <td><?= h($f['depo']?:'—') ?></td>
                    <td class="num"><?= $d['palet']?:0 ?></td>
                    <td class="num"><?= $d['kasa'] ?></td>
                    <td class="num"><?= fmt_kg($d['brut_kg']) ?></td>
                    <td class="num kr-dara"><?= fmt_kg($d['dara_kg']) ?></td>
                    <td class="num kr-net"><strong><?= fmt_kg($d['net_kg']) ?></strong></td>
                    <td class="kr-no-print"><span class="kr-badge kr-badge-tek">TEK</span></td>
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
                    <td class="num kr-dara"><strong><?= fmt_kg($toplam_dara) ?></strong></td>
                    <td class="num kr-net"><strong><?= fmt_kg($toplam_net) ?></strong></td>
                    <td class="kr-no-print"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ── Özet: Firma Bazlı ───────────────────────────────── -->
<div class="kr-summary-section">
    <div class="kr-ozet-grid">

        <!-- Firma / Grup Özeti -->
        <div class="card">
            <div class="card-head"><h2>Firma / Grup Bazlı Özet</h2></div>
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
                        <td><strong><?= h($firma) ?></strong></td>
                        <td class="num"><?= $fo['palet'] ?:0 ?></td>
                        <td class="num"><?= $fo['kasa']  ?:0 ?></td>
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

        <!-- Kasa / Palet Türü Dağılımı -->
        <div class="card">
            <div class="card-head"><h2>Kasa &amp; Palet Türü Dağılımı</h2></div>
            <div class="kr-kp-ozet-wrap">

                <?php if (!empty($kp_type_ozet['kasa'])): ?>
                <div class="kr-kp-ozet-block">
                    <div class="kr-kp-ozet-title">📦 Kasa Türleri</div>
                    <table class="data-table kr-ozet-table">
                        <thead><tr><th>Kasa Türü</th><th class="num">Adet</th><th class="num">%</th></tr></thead>
                        <tbody>
                        <?php
                        $kasa_total_t = array_sum($kp_type_ozet['kasa']);
                        foreach ($kp_type_ozet['kasa'] as $cinsi => $adet): ?>
                        <tr>
                            <td><?= h($cinsi ?: '(belirtilmemiş)') ?></td>
                            <td class="num"><strong><?= number_format($adet, 0, ',', '.') ?></strong></td>
                            <td class="num"><?= $kasa_total_t > 0 ? number_format($adet/$kasa_total_t*100, 1, ',', '.') . '%' : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="kr-total-row">
                                <td><strong>Toplam</strong></td>
                                <td class="num"><strong><?= number_format($kasa_total_t, 0, ',', '.') ?></strong></td>
                                <td class="num">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($kp_type_ozet['palet'])): ?>
                <div class="kr-kp-ozet-block">
                    <div class="kr-kp-ozet-title">🟦 Palet Türleri</div>
                    <table class="data-table kr-ozet-table">
                        <thead><tr><th>Palet Türü</th><th class="num">Adet</th><th class="num">%</th></tr></thead>
                        <tbody>
                        <?php
                        $palet_total_t = array_sum($kp_type_ozet['palet']);
                        foreach ($kp_type_ozet['palet'] as $cinsi => $adet): ?>
                        <tr>
                            <td><?= h($cinsi ?: '(belirtilmemiş)') ?></td>
                            <td class="num"><strong><?= number_format($adet, 0, ',', '.') ?></strong></td>
                            <td class="num"><?= $palet_total_t > 0 ? number_format($adet/$palet_total_t*100, 1, ',', '.') . '%' : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="kr-total-row">
                                <td><strong>Toplam</strong></td>
                                <td class="num"><strong><?= number_format($palet_total_t, 0, ',', '.') ?></strong></td>
                                <td class="num">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (empty($kp_type_ozet['kasa']) && empty($kp_type_ozet['palet'])): ?>
                <p class="muted" style="padding:12px">Kasa/palet tür verisi bulunamadı.</p>
                <?php endif; ?>

            </div>
        </div>

    </div><!-- /kr-ozet-grid -->
</div><!-- /kr-summary-section -->

<?php endif; ?>

<style id="printOrientStyle">@page { size: A4 landscape; margin: 8mm; }</style>

<style>
/* ── Kantar Raporu: Ekran ──────────────────────────────── */
.kr-filter-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr) repeat(3, 1fr);
    gap: 10px 14px;
    padding: 14px;
}
@media (max-width: 900px) { .kr-filter-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 540px) { .kr-filter-grid { grid-template-columns: 1fr; } }
.kr-filter-actions { display: flex; flex-direction: column; justify-content: flex-end; }

.kr-stat-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px; margin-bottom: 14px;
}
@media (max-width: 900px) { .kr-stat-grid { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 540px) { .kr-stat-grid { grid-template-columns: repeat(2, 1fr); } }
.kr-stat-card {
    background: #fff; border: 1px solid var(--border); border-radius: var(--radius);
    padding: 10px 12px; text-align: center; box-shadow: var(--shadow);
}
.kr-stat-val { font-size: 1.1rem; font-weight: 700; color: var(--primary); }
.kr-stat-lbl { font-size: .72rem; color: var(--muted); margin-top: 2px; }

.kr-card-head-row {
    display: flex; justify-content: space-between; align-items: center;
}
.kr-legend { display: flex; align-items: center; gap: 4px; font-size: .78rem; color: #6b7385; }
.kr-leg-swatch {
    display: inline-block; width: 12px; height: 12px;
    border: 1px solid; border-radius: 2px;
}

.kr-table { border-collapse: collapse; }

/* Tek-firma satırı */
.kr-row-tek { background: #eff6ff; }
.kr-row-tek:hover { background: #dbeafe; }

/* Gruplandırılmış fiş başlık */
.kr-row-grup-head { background: #fefce8 !important; }
.kr-grup-head-cell { padding: 8px 10px !important; border-top: 2px solid #fde68a !important; }
.kr-grup-head-inner {
    display: flex; align-items: flex-start; justify-content: space-between;
    flex-wrap: wrap; gap: 6px;
}
.kr-grup-head-totals {
    display: flex; gap: 12px; align-items: center; flex-wrap: wrap; font-size: .85rem;
}
.kr-net-total { color: #1a56db; }

/* Grup alt satırı */
.kr-row-grup-item { background: #fffdf0; }
.kr-row-grup-item:hover { background: #fef9c3; }

.kr-grup-indent { color: #9ca3af; font-size: .82rem; }
.kr-grup-no { margin-right: 4px; font-weight: 600; color: #374151; }
.kr-depo-chip {
    display: inline-block; padding: 1px 7px; border-radius: 10px;
    background: #e0e7ff; color: #3730a3; font-size: .75rem; margin-right: 4px;
}
.kr-kp-row-info { margin-top: 5px; display: flex; gap: 6px; flex-wrap: wrap; }
.kr-kp-chip {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    background: #f3f4f6; border: 1px solid #d1d5db; font-size: .78rem; color: #374151;
}

.kr-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: .72rem; font-weight: 700; }
.kr-badge-tek  { background: #dbeafe; color: #1e40af; }
.kr-badge-grup { background: #fde68a; color: #92400e; }
.kr-badge-manual { font-size: .7rem; padding: 1px 5px; border-radius: 8px; background: #d1fae5; color: #065f46; }
.kr-badge-auto   { font-size: .7rem; padding: 1px 5px; border-radius: 8px; background: #e5e7eb; color: #374151; }

.kr-fis-link { font-weight: 700; color: var(--primary); text-decoration: none; }
.kr-fis-link:hover { text-decoration: underline; }
.kr-dara { color: #9ca3af; }
.kr-net  { color: #1a56db; }
.kr-total-row { background: #f1f5f9 !important; border-top: 2px solid #cbd5e1; }

/* Özet bölümü */
.kr-ozet-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 900px) { .kr-ozet-grid { grid-template-columns: 1fr; } }

.kr-kp-ozet-wrap { display: flex; flex-direction: column; gap: 0; }
.kr-kp-ozet-block { padding: 0; }
.kr-kp-ozet-title {
    font-size: .8rem; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .04em;
    padding: 8px 12px 4px; border-top: 1px solid var(--border);
}
.kr-kp-ozet-block:first-child .kr-kp-ozet-title { border-top: none; }
.kr-ozet-table { font-size: .88rem; }

/* Yazdırma başlığı: ekranda gizli */
.kr-print-header { display: none; }
.kr-print-only   { display: none; }

/* ── Kantar Raporu: Baskı ──────────────────────────────── */
@media print {
    .topbar, .bottomnav, .kr-no-print { display: none !important; }
    .kr-print-header { display: block !important; margin-bottom: 6mm; }
    .kr-print-only   { display: inline !important; }

    .kr-ph-row {
        display: flex; justify-content: space-between; align-items: flex-start;
        border-bottom: 2pt solid #000; padding-bottom: 3mm; margin-bottom: 3mm;
    }
    .kr-ph-title { font-size: 13pt; font-weight: 800; }
    .kr-ph-sub   { font-size: 8pt; color: #555; margin-top: 2px; }
    .kr-ph-right { text-align: right; font-size: 8pt; line-height: 1.6; }

    html, body { font-size: 8pt !important; }
    body, .container {
        background: #fff !important; padding: 0 !important; margin: 0 !important;
    }
    .container { padding-bottom: 0 !important; max-width: 100% !important; }

    .card { border: 1pt solid #ccc !important; box-shadow: none !important; margin-bottom: 4mm !important; page-break-inside: avoid; }
    .card-head { border-bottom: 1pt solid #ccc !important; padding: 3px 8px !important; }
    .card-head h2 { font-size: 8pt !important; font-weight: 700; margin: 0 !important; }
    .card-body { padding: 4px 8px !important; }

    .data-table { font-size: 7pt !important; }
    .data-table th { font-size: 6pt !important; padding: 2px 4px !important; }
    .data-table td { padding: 2px 4px !important; }

    .kr-grup-head-cell { padding: 4px 6px !important; }
    .kr-kp-chip { font-size: 6pt !important; padding: 1px 4px !important; }
    .kr-grup-head-totals { font-size: 7pt !important; gap: 6px !important; }

    .kr-row-tek { background: #eef5ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .kr-row-grup-head { background: #fffde7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .kr-row-grup-item { background: #fffdf0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .kr-total-row { background: #f0f4f8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    .kr-summary-section { page-break-before: always; }
    .kr-ozet-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5mm; }
    .kr-stat-grid { display: none; }
    .table-wrap { overflow: visible !important; }

    a { color: #000 !important; text-decoration: none !important; }
}
</style>

<script>
function printKantar(orient) {
    document.getElementById('printOrientStyle').textContent =
        '@page { size: A4 ' + orient + '; margin: 8mm; }';
    window.print();
}
</script>

<?php render_footer(); ?>
