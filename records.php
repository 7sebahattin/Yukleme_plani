<?php
// =========================================================
// records.php - Yükleme Planları listesi (Excel görünümlü; Palet Fişleri / Mal Kabul Fişleri ile aynı yapı)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/kullanici_tercih.php';
$auth_user = require_login();
if (!function_exists('muhasebe_sayfa_can') || !muhasebe_sayfa_can('arac_yukleme_fisleri')) { require_perm('records.loading.read'); }

// Sütun genişliği/sırası/gizli: KULLANICI BAZINDA (kullanici_tercihleri). Tablo kurulu değilse istemci tarayıcıya düşer.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tercih_kaydet') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check($_POST['csrf'] ?? null);
    $anahtar = (string)($_POST['anahtar'] ?? '');
    $deger   = json_decode((string)($_POST['deger'] ?? ''), true);
    if (!kt_hazir(db())) { echo json_encode(['ok' => true, 'sunucu' => false]); exit; }
    $ok = $anahtar === 'records.tablo' && kt_yaz(db(), (int)($auth_user['id'] ?? 0), $anahtar, $deger);
    if (!$ok) http_response_code(400);
    echo json_encode(['ok' => $ok, 'sunucu' => true, 'hata' => $ok ? '' : 'Tercih kaydedilemedi.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ürün sahibi firma adına göre belirgin, sabit renk üret.
// CİHAT → kırmızı, ASYA → mavi; diğer firmalar paletten otomatik (aynı ad hep aynı renk).
function urun_sahibi_renk(string $name): string {
    $norm = mb_strtoupper(trim($name), 'UTF-8');
    $norm = strtr($norm, ['İ'=>'I','I'=>'I','Ş'=>'S','Ğ'=>'G','Ü'=>'U','Ö'=>'O','Ç'=>'C']);
    if ($norm === '') return '#6b7280';
    if (strpos($norm, 'CIHAT') !== false) return '#dc2626'; // kırmızı
    if (strpos($norm, 'ASYA')  !== false) return '#2563eb'; // mavi
    $palette = ['#059669','#7c3aed','#d97706','#db2777','#0891b2','#65a30d','#9333ea','#ea580c','#0d9488','#c026d3'];
    return $palette[crc32($norm) % count($palette)];
}

function fmt_date_short(?string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    return $ts ? date('d.m.y', $ts) : '';
}

function fmt_dt_short(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('d.m.y - H:i', $ts) : '';
}

const REC_PER_PAGE = 250;

$page = max(1, (int)($_GET['page'] ?? 1));

// ── WHERE koşulları ────────────────────────────────────────
$where  = "WHERE r.type = 'yukleme'";
$params = [];

// Depo sorumluluğu: kullanıcı belirli depolarla sınırlıysa yalnız o depodaki paletleri olan kayıtlar
[$_depo_sql, $_depo_params] = depo_sql_records('r');
if ($_depo_sql !== '') {
    $where .= $_depo_sql;
    $params = array_merge($params, $_depo_params);
}

// ── Toplam kayıt (sayfalama için) ──────────────────────────
$st_count = db()->prepare("SELECT COUNT(*) FROM loading_records r $where");
$st_count->execute($params);
$total       = (int)$st_count->fetchColumn();
$total_pages = max(1, (int)ceil($total / REC_PER_PAGE));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * REC_PER_PAGE;

// ── Ana sorgu — JOIN + GROUP BY (N+1 yok, açık kolon listesi) ──
$_rec_cols = [
    'r.id', 'r.tarih', 'r.firma', 'r.parti_no', 'r.alici', 'r.bolge', 'r.urun', 'r.casus_no',
    'r.gumruk', 'r.sofor_adi', 'r.on_plaka', 'r.arka_plaka', 'r.nakliye_sirketi',
    'r.ulasim', 'r.gidecek_ulke', 'r.telefon', 'r.fatura_no', 'r.nakliye_bedeli', 'r.avans',
    'r.created_at', 'r.updated_at', 'r.urun_sahibi_id',
];
foreach (['brand', 'etiket', 'locked_at', 'revision_reason', 'urun_cesidi'] as $_opt_col) {
    if (db_has_column('loading_records', $_opt_col)) {
        $_rec_cols[] = 'r.' . $_opt_col;
    }
}
$_rec_sel = implode(', ', $_rec_cols);

$sql = "SELECT {$_rec_sel},
               COUNT(p.id)                   AS toplam_palet,
               COALESCE(SUM(p.kasa_adeti),0) AS toplam_kasa,
               COALESCE(SUM(p.brut_kg),0)    AS toplam_brut,
               COALESCE(SUM(p.dara_kg),0)    AS toplam_dara,
               COALESCE(SUM(p.net_kg),0)     AS toplam_net,
               us.name                        AS urun_sahibi_adi
        FROM loading_records r
        LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
        LEFT JOIN material_definitions us ON us.id = r.urun_sahibi_id AND us.type = 'firma'
        $where
        GROUP BY r.id
        ORDER BY COALESCE(r.tarih,'0000-00-00') DESC, r.id DESC
        LIMIT :lim OFFSET :off";

$st = db()->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':lim', REC_PER_PAGE, PDO::PARAM_INT);
$st->bindValue(':off', $offset,      PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

// ── Detay satırı verisi: kasa dağılımı + ek malzemeler (batch, N+1 yok) ──
$kasa_bd_map = [];  // [rid][kasa_cinsi_adi] => toplam kasa_adeti
$em_map      = [];  // [rid][material_id]    => ['name','palet_count','total_qty']
if (!empty($rows)) {
    $rid_list = array_map(fn($r) => (int)$r['id'], $rows);
    $in_ph    = implode(',', array_fill(0, count($rid_list), '?'));

    // Kasa dağılımı
    $st_kbd = db()->prepare("
        SELECT p.loading_record_id AS rid, p.kasa_adeti,
               COALESCE(kc.name, '—') AS kasa_cinsi_adi
        FROM loading_pallets p
        LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
        WHERE p.loading_record_id IN ($in_ph) AND p.kasa_adeti > 0
    ");
    $st_kbd->execute($rid_list);
    foreach ($st_kbd->fetchAll() as $row) {
        $rid  = (int)$row['rid'];
        $name = trim((string)$row['kasa_cinsi_adi']) ?: '—';
        $kasa_bd_map[$rid][$name] = ($kasa_bd_map[$rid][$name] ?? 0) + (int)$row['kasa_adeti'];
    }

    // Ek malzemeler — kasa bazlı: quantity × kasa_adeti; palet bazlı: quantity
    $st_em = db()->prepare("
        SELECT p.loading_record_id AS rid, p.kasa_adeti,
               pm.material_id, pm.quantity,
               m.name AS material_name, m.type AS material_type
        FROM pallet_materials pm
        JOIN loading_pallets p ON p.id = pm.loading_pallet_id
        JOIN material_definitions m ON m.id = pm.material_id
        WHERE p.loading_record_id IN ($in_ph) AND pm.quantity > 0
    ");
    $st_em->execute($rid_list);
    foreach ($st_em->fetchAll() as $row) {
        $rid   = (int)$row['rid'];
        $kid   = (int)$row['material_id'];
        $basis = material_calc_basis((string)$row['material_type'], (string)$row['material_name']);
        $eff   = ($basis === 'kasa')
            ? (float)$row['quantity'] * (int)$row['kasa_adeti']
            : (float)$row['quantity'];
        if (!isset($em_map[$rid][$kid])) {
            $em_map[$rid][$kid] = ['name' => $row['material_name'], 'palet_count' => 0, 'total_qty' => 0.0];
        }
        $em_map[$rid][$kid]['palet_count']++;
        $em_map[$rid][$kid]['total_qty'] += $eff;
    }
}

$can_create = can('records.loading.create');

// Sütun sırası (başlık, süzgeç satırı ve hücreler aynı listeden): [anahtar, başlık, tür]
$REC_KOLONLAR = [
    ['tarih',       'Tarih',       'tarih'],
    ['urun_sahibi', 'Ürün Sahibi', ''],
    ['firma',       'Firma',       ''],
    ['parti_no',    'Parti No',    ''],
    ['alici',       'Alıcı',       ''],
    ['bolge',       'Bölge',       ''],
    ['urun',        'Ürün',        ''],
    ['casus_no',    'Casus No',    ''],
    ['palet',       'Palet',       'sayi'],
    ['kasa',        'Kasa',        'sayi'],
    ['brut',        'Brüt',        'sayi'],
    ['dara',        'Dara',        'sayi'],
    ['net',         'Net',         'sayi'],
];

// İlk yükleme alt toplamları (JS süzgeç uyguladıkça dinamik günceller)
$sum_palet = 0;
$sum_kasa  = 0;
$sum_brut  = 0.0;
$sum_dara  = 0.0;
$sum_net   = 0.0;
foreach ($rows as $_sr) {
    $sum_palet += (int)$_sr['toplam_palet'];
    $sum_kasa  += (int)$_sr['toplam_kasa'];
    $sum_brut  += (float)$_sr['toplam_brut'];
    $sum_dara  += (float)$_sr['toplam_dara'];
    $sum_net   += (float)$_sr['toplam_net'];
}

render_header('Yükleme Planları');
render_flash();
?>
<link rel="stylesheet" href="assets/palet-fisleri.css?v=<?= (int)@filemtime(__DIR__ . '/assets/palet-fisleri.css') ?>">
<section class="pf-page" id="recPage" data-csrf="<?= h(csrf_token()) ?>">
    <div class="pf-top">
        <div class="pf-title">
            <div>
                <h1 style="color:#166534">Yükleme Planları</h1>
                <p>Satıra tıklayınca görüntülenir · ▸ ile alt detay açılır · Başlıktan sürükle: sütun taşı · Kenardan sürükle: genişlik · Çift tık: sütunlar</p>
            </div>
            <div class="pf-tb-grup">
                <button type="button" class="pf-tb" id="recFiltreTemizle" title="Tüm süzgeçleri kaldırır" hidden>✕ Süzgeçleri temizle</button>
                <?php if ($can_create): ?><a href="record_create.php" id="recAddBtn" class="pf-tb pf-tb-ekle rec-btn-yeni" title="Yeni yükleme planı (F2)">+F2 Yeni</a><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="pf-table-wrap" id="recWrap" tabindex="0" aria-label="Yükleme planları tablosu">
        <table class="pf-table kf-table rec-table" id="recTable">
            <thead>
            <tr id="recHead">
                <?php foreach ($REC_KOLONLAR as [$ck, $cl, $ct]): ?>
                <th data-col="<?= $ck ?>"<?= $ct ? ' data-tip="' . $ct . '"' : '' ?>><?= h($cl) ?></th>
                <?php endforeach; ?>
                <th class="rec-exp-th" data-col="_detay" title="Alt detay aç/kapat" aria-label="Detay">▾</th>
            </tr>
            <tr id="recFiltreSatiri" class="kf-filtre-satir rec-filtre-satir">
                <?php foreach ($REC_KOLONLAR as [$ck, $cl]): ?>
                <th data-col="<?= $ck ?>"><input type="search" data-f="<?= $ck ?>" placeholder="süz" aria-label="<?= h($cl) ?> süz" autocomplete="off"></th>
                <?php endforeach; ?>
                <th class="rec-exp-th" data-col="_detay"></th>
            </tr>
            </thead>
            <tbody id="recBody">
            <?php foreach ($rows as $r):
                $rid        = (int)$r['id'];
                $tarih_str  = fmt_date_short($r['tarih'] ?? null);
                $upd_str    = !empty($r['updated_at']) ? fmt_dt_short($r['updated_at']) : '';
                $tarih_val  = trim($tarih_str . ($upd_str !== '' ? ' ' . $upd_str : ''));
                $tarih_sort = (string)(strtotime((string)($r['tarih'] ?? '')) ?: 0);

                $us_adi     = trim((string)($r['urun_sahibi_adi'] ?? ''));
                $firma_str  = (string)($r['firma'] ?? '');
                $parti_str  = (string)($r['parti_no'] ?? '');
                $alici_str  = (string)($r['alici'] ?? '');
                $bolge_str  = (string)($r['bolge'] ?? '');
                $urun_str   = trim((string)($r['urun'] ?? ''));
                $cesit_str  = isset($r['urun_cesidi']) ? trim((string)$r['urun_cesidi']) : '';
                $urun_val   = trim($urun_str . ($cesit_str !== '' ? ' ' . $cesit_str : ''));
                $casus_str  = (string)($r['casus_no'] ?? '');

                $palet_num  = (int)$r['toplam_palet'];
                $kasa_num   = (int)$r['toplam_kasa'];
                $brut_num   = (float)$r['toplam_brut'];
                $dara_num   = (float)$r['toplam_dara'];
                $net_num    = (float)$r['toplam_net'];
                $brut_fmt   = fmt_kg($brut_num);
                $dara_fmt   = fmt_kg($dara_num);
                $net_fmt    = fmt_kg($net_num);

                // ── Açılır detay satırı: tabloda yer almayan tüm alanlar ──
                $_b = strtoupper(trim((string)($r['brand'] ?? '')));
                $_brand_names = ['ASYA'=>'ASYA FRESH','URAL'=>'URAL','URAS'=>'URAS ENERGY','AGRO'=>'AGRONATURAL'];
                $_brand_label = $_b !== '' ? ($_brand_names[$_b] ?? $_b) : '';
                $detail_pairs = [];
                $_add = function(string $label, $val) use (&$detail_pairs): void {
                    $val = trim((string)$val);
                    if ($val !== '') $detail_pairs[] = [$label, $val];
                };
                $_add('Gümrük',          $r['gumruk']          ?? '');
                $_add('Şoför Adı',       $r['sofor_adi']       ?? '');
                $_add('Plaka',           trim(($r['on_plaka'] ?? '') . ' / ' . ($r['arka_plaka'] ?? ''), ' /'));
                $_add('Nakliye Şirketi', $r['nakliye_sirketi'] ?? '');
                $_add('Ulaşım',          $r['ulasim']          ?? '');
                $_add('Gideceği Ülke',   $r['gidecek_ulke']    ?? '');
                $_add('Telefon',         $r['telefon']         ?? '');
                $_add('Fatura No',       $r['fatura_no']       ?? '');
                if ((float)($r['nakliye_bedeli'] ?? 0) > 0) $_add('Nakliye Bedeli', fmt_money($r['nakliye_bedeli']));
                if ((float)($r['avans'] ?? 0) > 0)          $_add('Avans',         fmt_money($r['avans']));
                $_add('Marka',           $_brand_label);
                $_add('Not',             $r['etiket']     ?? '');
                $_add('Oluşturma',       fmt_dt_short($r['created_at'] ?? ''));
                $_add('Son Düzenleme',   fmt_dt_short($r['updated_at'] ?? ''));
                if (!empty($r['locked_at']) && !empty($r['revision_reason'])) $_add('Revizyon Nedeni', $r['revision_reason']);
            ?>
                <tr class="rec-main-row" data-record-id="<?= $rid ?>" data-href="record_view.php?id=<?= $rid ?>" tabindex="0" title="Görüntülemek için satıra tıklayın">
                    <td class="pf-cell td-tarih-cell" data-col="tarih" data-value="<?= h($tarih_val) ?>" data-sort="<?= h($tarih_sort) ?>">
                        <span><?= $tarih_str !== '' ? h($tarih_str) : '—' ?></span>
                        <?php if ($upd_str !== ''): ?><span class="td-son-duz"><?= h($upd_str) ?></span><?php endif; ?>
                    </td>
                    <td class="pf-cell td-urun-sahibi" data-col="urun_sahibi" data-value="<?= h($us_adi) ?>"><?php if ($us_adi !== ''): ?><strong style="color:<?= h(urun_sahibi_renk($us_adi)) ?>"><?= h($us_adi) ?></strong><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td class="pf-cell" data-col="firma" data-value="<?= h($firma_str) ?>"><?= h($firma_str) ?></td>
                    <td class="pf-cell" data-col="parti_no" data-value="<?= h($parti_str) ?>"><?= h($parti_str) ?></td>
                    <td class="pf-cell" data-col="alici" data-value="<?= h($alici_str) ?>"><?= h($alici_str) ?></td>
                    <td class="pf-cell" data-col="bolge" data-value="<?= h($bolge_str) ?>"><?= h($bolge_str) ?></td>
                    <td class="pf-cell td-urun-cell" data-col="urun" data-value="<?= h($urun_val) ?>"><?= h($urun_str) ?><?php if ($cesit_str !== ''): ?> <span class="rec-cesit-sub"><?= h($cesit_str) ?></span><?php endif; ?></td>
                    <td class="pf-cell td-casus-no" data-col="casus_no" data-value="<?= h($casus_str) ?>"><?= h($casus_str) ?></td>
                    <td class="pf-cell pf-num" data-col="palet" data-value="<?= $palet_num ?>" data-sort="<?= $palet_num ?>"><?= $palet_num ?></td>
                    <td class="pf-cell pf-num" data-col="kasa" data-value="<?= $kasa_num ?>" data-sort="<?= $kasa_num ?>"><?= $kasa_num ?></td>
                    <td class="pf-cell pf-num" data-col="brut" data-value="<?= h($brut_fmt) ?>" data-sort="<?= h((string)$brut_num) ?>"><?= h($brut_fmt) ?></td>
                    <td class="pf-cell pf-num" data-col="dara" data-value="<?= h($dara_fmt) ?>" data-sort="<?= h((string)$dara_num) ?>"><?= h($dara_fmt) ?></td>
                    <td class="pf-cell pf-num strong" data-col="net" data-value="<?= h($net_fmt) ?>" data-sort="<?= h((string)$net_num) ?>"><?= h($net_fmt) ?></td>
                    <td class="rec-exp-td" data-col="_detay"><button type="button" class="rec-exp-btn" aria-expanded="false" aria-label="Alt detayları aç/kapat" title="Alt detayları aç/kapat">▸</button></td>
                </tr>
                <tr class="rec-detail-row" data-detail-for="<?= $rid ?>" hidden>
                    <td colspan="14">
                        <div class="rec-detail-wrap">
                            <?php if (empty($detail_pairs)): ?>
                            <span class="rec-detail-empty">Ek bilgi bulunmuyor.</span>
                            <?php else: foreach ($detail_pairs as [$lbl, $val]): ?>
                            <div class="rec-detail-item">
                                <span class="rdi-label"><?= h($lbl) ?></span>
                                <span class="rdi-value"><?= h($val) ?></span>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <?php
                        $_kbd = $kasa_bd_map[$rid] ?? [];
                        $_em  = $em_map[$rid]      ?? [];
                        if (!empty($_kbd) || !empty($_em)):
                        ?>
                        <div class="rec-detail-stok">
                            <?php if (!empty($_kbd)): ?>
                            <div class="rds-block">
                                <div class="rds-title">Kasa Dağılımı</div>
                                <?php foreach ($_kbd as $kn => $ka): ?>
                                <div class="rds-row"><span><?= h($kn) ?></span><strong><?= (int)$ka ?></strong></div>
                                <?php endforeach; ?>
                                <div class="rds-row rds-total"><span>Toplam</span><strong><?= $kasa_num ?></strong></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($_em)): ?>
                            <div class="rds-block rds-block-em">
                                <div class="rds-title">Ek Malzemeler</div>
                                <div class="rds-em-grid">
                                    <?php foreach ($_em as $eg): ?>
                                    <div class="rds-em-item">
                                        <span class="rds-em-name"><?= h($eg['name']) ?></span>
                                        <span class="rds-em-meta"><?= (int)$eg['palet_count'] ?> palet · <?= h(fmt_kg($eg['total_qty'])) ?> adet</span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pf-empty" id="recEmpty" <?= $rows ? 'hidden' : '' ?>><?= $rows ? 'Süzgeçlerle eşleşen yükleme planı yok.' : 'Henüz yükleme planı yok.' ?><?php if (!$rows && $can_create): ?> <a href="record_create.php" class="pf-tb pf-tb-ekle rec-btn-yeni">+F2 Yeni</a><?php endif; ?></div>
    </div>

    <div class="pf-bottom rec-bottom" id="recOzet">
        <div class="pf-kart"><i aria-hidden="true">▤</i><div><span><em class="pf-u">PLAN</em><em class="pf-k">PLAN</em></span><strong data-sum="plan"><?= count($rows) ?></strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">▥</i><div><span><em class="pf-u">PALET</em><em class="pf-k">PALET</em></span><strong data-sum="palet"><?= $sum_palet ?></strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">▢</i><div><span><em class="pf-u">KASA</em><em class="pf-k">KASA</em></span><strong data-sum="kasa"><?= $sum_kasa ?></strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">BRÜT KG</em><em class="pf-k">BRÜT</em></span><strong data-sum="brut"><?= h(fmt_kg($sum_brut)) ?></strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">DARA</em><em class="pf-k">DARA</em></span><strong data-sum="dara"><?= h(fmt_kg($sum_dara)) ?></strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">NET KG</em><em class="pf-k">NET</em></span><strong data-sum="net"><?= h(fmt_kg($sum_net)) ?></strong></div></div>
    </div>

    <div class="pf-foot">
        <span>Toplam: <strong id="recCount"><?= count($rows) ?></strong><?php if ($total > count($rows)): ?> / <?= $total ?><?php endif; ?> kayıt</span>
        <?php if ($total_pages > 1): ?>
        <span class="pf-sayfalama" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px">
            <?php if ($page > 1): ?><a class="btn btn-sm btn-ghost" href="records.php<?= $page - 1 > 1 ? '?page=' . ($page - 1) : '' ?>" aria-label="Önceki sayfa">‹ Önceki</a><?php endif; ?>
            <span>Sayfa <?= $page ?> / <?= $total_pages ?></span>
            <?php if ($page < $total_pages): ?><a class="btn btn-sm btn-ghost" href="records.php?page=<?= $page + 1 ?>" aria-label="Sonraki sayfa">Sonraki ›</a><?php endif; ?>
        </span>
        <?php endif; ?>
        <span class="pf-status" id="recStatus" role="status" aria-live="polite"></span>
    </div>
</section>

<dialog class="pf-dialog pf-sutun-dialog" id="recSutunDialog">
    <div class="pf-dialog-head"><div><h2>Sütunlar</h2></div><button type="button" class="pf-close" id="recSutunKapat" aria-label="Kapat">×</button></div>
    <div class="pf-sutun-liste" id="recSutunListe"></div>
    <div class="pf-dialog-foot"><button type="button" class="btn btn-ghost" id="recSutunHepsi" style="margin-right:auto">Tümünü göster</button><button type="button" class="btn btn-primary" id="recSutunTamam">Tamam</button></div>
</dialog>

<script>window.RECORDS_LISTE = <?= json_encode([
    'tercihSunucu' => kt_hazir(db()),
    'tercih'       => kt_oku(db(), (int)($auth_user['id'] ?? 0), 'records.tablo'),
    'anahtar'      => 'records.tablo',
    'canCreate'    => $can_create,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/records-liste.js?v=<?= (int)@filemtime(__DIR__ . '/assets/records-liste.js') ?>"></script>
<?php render_footer(); ?>
