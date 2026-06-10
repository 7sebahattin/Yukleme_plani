<?php
// =========================================================
// malzeme_stok_tehis.php — Malzeme Stok Teşhis Raporu
// Sprint MalzemeStok-01A
// SADECE OKUMA — Hiçbir veri değiştirilmez.
// Yalnızca admin erişimine açık.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }

// ── Güvenlik köprüsü: normalize_text_v2 yoksa yerel tanım ──
if (!function_exists('normalize_text_v2')) {
    function normalize_text_v2(string $v): string {
        $v = trim($v);
        if ($v === '') return '';
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = str_replace(['I', 'İ'], ['ı', 'i'], $v);
        $v = mb_strtolower($v, 'UTF-8');
        $words = explode(' ', $v);
        $words = array_map(function(string $w): string {
            if ($w === '') return '';
            $first = mb_substr($w, 0, 1, 'UTF-8');
            $rest  = mb_substr($w, 1, null, 'UTF-8');
            if ($first === 'i') return 'İ' . $rest;
            if ($first === 'ı') return 'I' . $rest;
            return mb_strtoupper($first, 'UTF-8') . $rest;
        }, $words);
        return implode(' ', $words);
    }
}

// ── Normalize malzeme anahtarı ──────────────────────────────
// Amacı: "C-5 Siyah 30x40x14" = "C-5 SIYAH 30X40X14" = "C-5 SİYAH 30X40X14"
function nmk(string $name): string {
    $s = trim($name);
    // Görünmez karakterleri temizle
    $s = preg_replace('/[\x00-\x1F\x7F\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
    // Çoklu boşluk → tek
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);
    // Türkçe büyük harf (İ→I dönüşüm standartlaştırması)
    $s = str_replace(['ı','i','İ','I','ş','Ş','ğ','Ğ','ü','Ü','ö','Ö','ç','Ç'],
                     ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'], $s);
    $s = mb_strtoupper($s, 'UTF-8');
    // x / X ölçü normalize (boşluklu yazım da dahil)
    $s = preg_replace('/\s*[xX]\s*/', 'X', $s);
    // Tire çevresindeki boşlukları kaldır
    $s = preg_replace('/\s*-\s*/', '-', $s);
    return $s;
}

// ── Tablo var mı yardımcı ───────────────────────────────────
function tbl_exists(string $t): bool {
    try { db()->query("SELECT 1 FROM `$t` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

$pdo = db();
$has_msm   = tbl_exists('material_stock_movements');
$has_pm    = tbl_exists('pallet_materials');
$has_lp    = tbl_exists('loading_pallets');
$has_lr    = tbl_exists('loading_records');
$has_md    = tbl_exists('material_definitions');

// ============================================================
// VERİ TOPLAMA — SADECE SELECT
// ============================================================

// ── A) material_definitions tam listesi ─────────────────────
$all_defs = $has_md
    ? $pdo->query("SELECT id, type, name, is_active, created_at FROM material_definitions ORDER BY name, type")->fetchAll()
    : [];

// Normalize anahtarlarla grupla
$norm_groups = []; // [norm_key => [type => [rows]]]
foreach ($all_defs as $d) {
    $nk = nmk($d['name']);
    $norm_groups[$nk][$d['type']][] = $d;
}

// Duplicate gruplar: aynı normalize key + type altında 2+ id
$duplicate_groups = [];
foreach ($norm_groups as $nk => $by_type) {
    foreach ($by_type as $type => $rows) {
        if (count($rows) > 1) {
            $duplicate_groups[] = [
                'norm_key' => $nk,
                'type'     => $type,
                'rows'     => $rows,
            ];
        }
    }
}

// Kritik malzemeler listesi
$kritik_names = ['C-5 Siyah', 'Yunan Kasa', 'C-10 Mavi', 'Şale Kasa', 'C-10 Siyah', 'C-5 Siyah 30x40x14', 'Yunan Kasa 30x40x18'];
$kritik_defs  = [];
foreach ($all_defs as $d) {
    foreach ($kritik_names as $kn) {
        if (mb_stripos($d['name'], $kn) !== false) {
            $kritik_defs[$d['id']] = $d;
        }
    }
}

// ── Stok hareketlerini material_id bazında özetle ────────────
$msm_by_mid = []; // material_id → [giris, kullanim, sevk, duzeltme, depolar]
if ($has_msm) {
    $msm_agg = $pdo->query("
        SELECT
            material_id,
            SUM(CASE WHEN movement_type='giris'    THEN quantity ELSE 0 END) AS giris,
            SUM(CASE WHEN movement_type='kullanim' THEN quantity ELSE 0 END) AS kullanim,
            SUM(CASE WHEN movement_type='sevk'     THEN quantity ELSE 0 END) AS sevk,
            SUM(CASE WHEN movement_type='duzeltme' THEN quantity ELSE 0 END) AS duzeltme,
            GROUP_CONCAT(DISTINCT COALESCE(NULLIF(depo,''), '[Boş]') ORDER BY depo SEPARATOR ', ') AS depolar
        FROM material_stock_movements
        GROUP BY material_id
    ")->fetchAll();
    foreach ($msm_agg as $row) {
        $mid = $row['material_id'] ?? 'NULL';
        $msm_by_mid[(string)$mid] = $row;
    }
}

// Duplicate gruplara stok bilgisi ekle
foreach ($duplicate_groups as &$dg) {
    foreach ($dg['rows'] as &$r) {
        $mid = (string)$r['id'];
        $r['msm'] = $msm_by_mid[$mid] ?? null;
    }
    unset($r);
}
unset($dg);

// ── B) material_id NULL hareketler ──────────────────────────
$null_mid_rows = [];
$null_mid_count = 0;
$null_mid_qty   = 0;
if ($has_msm) {
    $null_mid_count = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements WHERE material_id IS NULL")->fetchColumn();
    $null_mid_rows  = $pdo->query("
        SELECT id, movement_date, movement_type, material_name, material_type,
               depo, quantity, unit, source_type, source_id, note
        FROM material_stock_movements
        WHERE material_id IS NULL
        ORDER BY id DESC
        LIMIT 100
    ")->fetchAll();
    foreach ($null_mid_rows as $r) $null_mid_qty += (float)$r['quantity'];
}

// ── C) Depo uyuşmazlığı: aynı material_id, farklı depo ──────
$depo_rows = [];
if ($has_msm) {
    $depo_rows = $pdo->query("
        SELECT
            msm.material_id,
            COALESCE(md.name, msm.material_name, '—') AS mat_name,
            COALESCE(NULLIF(msm.depo,''), '[Boş]') AS depo,
            SUM(CASE WHEN msm.movement_type='giris'    THEN msm.quantity ELSE 0 END) AS giris,
            SUM(CASE WHEN msm.movement_type='kullanim' THEN msm.quantity ELSE 0 END) AS kullanim,
            SUM(CASE WHEN msm.movement_type='sevk'     THEN msm.quantity ELSE 0 END) AS sevk,
            SUM(CASE WHEN msm.movement_type='duzeltme' THEN msm.quantity ELSE 0 END) AS duzeltme
        FROM material_stock_movements msm
        LEFT JOIN material_definitions md ON md.id = msm.material_id
        WHERE msm.material_id IS NOT NULL
        GROUP BY msm.material_id, msm.depo
        ORDER BY mat_name, msm.depo
    ")->fetchAll();
}

// Hangi material_id'lerde hem Boş hem dolu depo var?
$depo_mixed_mids = [];
$depo_by_mid = [];
foreach ($depo_rows as $r) {
    $depo_by_mid[$r['material_id']][] = $r;
}
foreach ($depo_by_mid as $mid => $rows) {
    $depolar = array_column($rows, 'depo');
    $has_bos  = in_array('[Boş]', $depolar);
    $has_dolu = count(array_filter($depolar, fn($d) => $d !== '[Boş]')) > 0;
    if ($has_bos && $has_dolu) {
        $depo_mixed_mids[] = $mid;
    }
}
$depo_bos_count = count(array_filter($depo_rows, fn($r) => $r['depo'] === '[Boş]' && $r['giris'] > 0));

// ── D) Giriş başka ID, kullanım başka ID ────────────────────
// Aynı normalize_key + type için farklı ID'lere dağılmış giriş/kullanım
$split_risks = [];
if ($has_msm && $has_md) {
    // Her id için toplam giriş ve kullanım al
    $msm_totals = $pdo->query("
        SELECT
            material_id,
            SUM(CASE WHEN movement_type='giris'    THEN quantity ELSE 0 END) AS giris,
            SUM(CASE WHEN movement_type='kullanim' THEN quantity ELSE 0 END) AS kullanim
        FROM material_stock_movements
        WHERE material_id IS NOT NULL
        GROUP BY material_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR + 0); // fetchAll sonra key olarak material_id kullanacağız
    // PDO::FETCH_KEY_PAIR iki kolon ister, burada 3 kolon var, normal fetchAll kullan
    $msm_totals = $pdo->query("
        SELECT material_id,
               SUM(CASE WHEN movement_type='giris'    THEN quantity ELSE 0 END) AS giris,
               SUM(CASE WHEN movement_type='kullanim' THEN quantity ELSE 0 END) AS kullanim,
               GROUP_CONCAT(DISTINCT COALESCE(NULLIF(depo,''),'[Boş]') SEPARATOR ', ') AS depolar
        FROM material_stock_movements
        WHERE material_id IS NOT NULL
        GROUP BY material_id
    ")->fetchAll();
    $mid_totals = [];
    foreach ($msm_totals as $r) $mid_totals[(int)$r['material_id']] = $r;

    // Duplicate gruplar zaten var — her biri potansiyel split risk
    foreach ($duplicate_groups as $dg) {
        $group_giris    = 0;
        $group_kullanim = 0;
        $ids_with_giris    = [];
        $ids_with_kullanim = [];
        foreach ($dg['rows'] as $row) {
            $mid = (int)$row['id'];
            $t   = $mid_totals[$mid] ?? null;
            if (!$t) continue;
            if ((float)$t['giris'] > 0) {
                $ids_with_giris[] = ['id' => $mid, 'name' => $row['name'], 'giris' => (float)$t['giris'], 'depolar' => $t['depolar']];
                $group_giris += (float)$t['giris'];
            }
            if ((float)$t['kullanim'] > 0) {
                $ids_with_kullanim[] = ['id' => $mid, 'name' => $row['name'], 'kullanim' => (float)$t['kullanim'], 'depolar' => $t['depolar']];
                $group_kullanim += (float)$t['kullanim'];
            }
        }
        // En az bir id'de giriş, başka id'de kullanım varsa split risk
        $giris_ids    = array_column($ids_with_giris, 'id');
        $kullanim_ids = array_column($ids_with_kullanim, 'id');
        $intersect    = array_intersect($giris_ids, $kullanim_ids);
        $is_split     = (count($giris_ids) > 0 && count($kullanim_ids) > 0 && count($intersect) < count($kullanim_ids));
        if ($is_split || (count($ids_with_giris) > 0 && count($ids_with_kullanim) > 0)) {
            $risk = 'ORTA';
            if ($is_split) $risk = 'YÜKSEK';
            $net = $group_giris - $group_kullanim;
            if ($net < 0) $risk = 'KRİTİK';
            $split_risks[] = [
                'norm_key'      => $dg['norm_key'],
                'type'          => $dg['type'],
                'rows'          => $dg['rows'],
                'ids_giris'     => $ids_with_giris,
                'ids_kullanim'  => $ids_with_kullanim,
                'group_giris'   => $group_giris,
                'group_kullanim'=> $group_kullanim,
                'net'           => $net,
                'risk'          => $risk,
            ];
        }
    }
}

// ── E) Orphan hareketler ─────────────────────────────────────
$orphan_count = 0;
$orphan_rows  = [];
if ($has_msm && $has_lr) {
    $orphan_count = (int)$pdo->query("
        SELECT COUNT(*)
        FROM material_stock_movements m
        WHERE m.source_type='loading'
          AND m.source_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id = m.source_id)
    ")->fetchColumn();
    $orphan_rows = $pdo->query("
        SELECT m.id, m.movement_date, m.movement_type, m.material_name, m.material_type,
               m.depo, m.quantity, m.unit, m.source_id, m.note
        FROM material_stock_movements m
        WHERE m.source_type='loading'
          AND m.source_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id = m.source_id)
        ORDER BY m.id DESC
        LIMIT 50
    ")->fetchAll();
}

// ── F) Sync eksik yükleme kayıtları ─────────────────────────
$sync_eksik_count = 0;
$sync_eksik_rows  = [];
if ($has_lr && $has_lp && $has_msm) {
    $sync_eksik_count = (int)$pdo->query("
        SELECT COUNT(DISTINCT lr.id)
        FROM loading_records lr
        WHERE COALESCE(lr.type,'yukleme') = 'yukleme'
          AND EXISTS (SELECT 1 FROM loading_pallets lp WHERE lp.loading_record_id = lr.id)
          AND NOT EXISTS (SELECT 1 FROM material_stock_movements m WHERE m.source_type='loading' AND m.source_id = lr.id)
    ")->fetchColumn();
    $sync_eksik_rows = $pdo->query("
        SELECT lr.id, lr.tarih, lr.firma, lr.urun, lr.durum,
               COUNT(lp.id) AS palet_sayisi,
               COALESCE(SUM(lp.kasa_adeti),0) AS kasa_toplam
        FROM loading_records lr
        LEFT JOIN loading_pallets lp ON lp.loading_record_id = lr.id
        WHERE COALESCE(lr.type,'yukleme') = 'yukleme'
          AND EXISTS (SELECT 1 FROM loading_pallets lp2 WHERE lp2.loading_record_id = lr.id)
          AND NOT EXISTS (SELECT 1 FROM material_stock_movements m WHERE m.source_type='loading' AND m.source_id = lr.id)
        GROUP BY lr.id, lr.tarih, lr.firma, lr.urun, lr.durum
        ORDER BY lr.tarih DESC, lr.id DESC
        LIMIT 50
    ")->fetchAll();
}

// ── G) Pasif tanım hâlâ kullanımda mı? ──────────────────────
$pasif_kullanim_rows = [];
if ($has_md && $has_lp) {
    $pasif_kullanim_rows = $pdo->query("
        SELECT md.id, md.type, md.name, md.is_active,
               COUNT(DISTINCT lp.id) AS palet_kullanim
        FROM material_definitions md
        LEFT JOIN loading_pallets lp ON lp.kasa_cinsi_id = md.id OR lp.palet_tipi_id = md.id
        WHERE md.is_active = 0
          AND lp.id IS NOT NULL
        GROUP BY md.id, md.type, md.name, md.is_active
        ORDER BY palet_kullanim DESC
    ")->fetchAll();
}

// ── Özet sayılar ─────────────────────────────────────────────
$summary = [
    'duplicate_grup'    => count($duplicate_groups),
    'null_mid'          => $null_mid_count,
    'depo_bos_giris'    => $depo_bos_count,
    'split_risk'        => count($split_risks),
    'orphan'            => $orphan_count,
    'sync_eksik'        => $sync_eksik_count,
    'pasif_kullanim'    => count($pasif_kullanim_rows),
];
$toplam_sorun = $summary['duplicate_grup'] + ($summary['null_mid'] > 0 ? 1 : 0)
              + ($summary['split_risk'])    + ($summary['orphan'] > 0 ? 1 : 0)
              + ($summary['sync_eksik'] > 0 ? 1 : 0);

// ============================================================
// HTML
// ============================================================
render_header('Malzeme Stok Teşhis');
?>

<style>
.tehis-warn { background:#fff7ed;border:1.5px solid #fb923c;border-radius:8px;padding:10px 16px;margin-bottom:18px;font-size:.9rem;color:#c2410c;font-weight:600; }
.tehis-summary { display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:22px; }
.tehis-kart { border-radius:10px;padding:12px 14px;text-align:center;border:1.5px solid; }
.tehis-kart-num { font-size:1.8rem;font-weight:800;line-height:1; }
.tehis-kart-lbl { font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-top:4px; }
.tehis-kart.ok   { background:#f0fdf4;border-color:#86efac;color:#166534; }
.tehis-kart.warn { background:#fffbeb;border-color:#fde68a;color:#92400e; }
.tehis-kart.crit { background:#fef2f2;border-color:#fca5a5;color:#991b1b; }

.tehis-section { border:1px solid var(--border);border-radius:10px;margin-bottom:18px;overflow:hidden; }
.tehis-section-head { display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8fafc;border-bottom:1px solid var(--border);cursor:pointer;user-select:none; }
.tehis-section-head h3 { margin:0;font-size:.95rem;display:flex;align-items:center;gap:8px; }
.tehis-section-body { padding:14px; }
.tehis-badge { display:inline-block;padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:700; }
.badge-crit  { background:#fee2e2;color:#991b1b; }
.badge-warn  { background:#fef3c7;color:#92400e; }
.badge-ok    { background:#dcfce7;color:#166534; }
.badge-info  { background:#dbeafe;color:#1e40af; }

.tehis-table { width:100%;border-collapse:collapse;font-size:.82rem;margin-top:8px; }
.tehis-table th { background:#f3f4f6;padding:5px 8px;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap; }
.tehis-table td { padding:5px 8px;border-top:1px solid #f0f0f0;vertical-align:top;word-break:break-word; }
.tehis-table tr:hover td { background:#f9fafb; }
.tehis-table .num { text-align:right; }
.tehis-table .neg { color:#dc2626;font-weight:700; }
.tehis-table .pos { color:#16a34a; }
.tehis-norm { font-family:monospace;font-size:.78rem;background:#f3f4f6;padding:1px 4px;border-radius:3px; }
.crit-row td { background:#fff1f2 !important; }
.warn-row td { background:#fffbeb !important; }
.tehis-sub { font-size:.8rem;color:var(--muted);margin:6px 0 10px; }

@media(max-width:767px){
    .tehis-table { font-size:.75rem; }
    .tehis-table th,.tehis-table td { padding:4px 5px; }
    .tehis-summary { grid-template-columns: repeat(3,1fr); }
}
</style>

<div class="page-head">
    <div>
        <h1>🔬 Malzeme Stok Teşhis Raporu</h1>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:4px">
            Sprint MalzemeStok-01A &nbsp;·&nbsp; Çalışma: <strong><?= date('d.m.Y H:i:s') ?></strong>
        </p>
    </div>
</div>

<div class="tehis-warn">
    ⚠ Bu sayfa yalnızca okuma amaçlıdır. Hiçbir veri değiştirilmez.
    Yalnızca admin kullanıcılara açıktır.
</div>

<!-- Özet kartları -->
<div class="tehis-summary">
    <div class="tehis-kart <?= $summary['duplicate_grup'] > 0 ? 'crit' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['duplicate_grup'] ?></div>
        <div class="tehis-kart-lbl">Duplicate Grup</div>
    </div>
    <div class="tehis-kart <?= $summary['null_mid'] > 0 ? 'crit' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['null_mid'] ?></div>
        <div class="tehis-kart-lbl">material_id NULL</div>
    </div>
    <div class="tehis-kart <?= $summary['depo_bos_giris'] > 0 ? 'warn' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['depo_bos_giris'] ?></div>
        <div class="tehis-kart-lbl">Depo Boş Giriş</div>
    </div>
    <div class="tehis-kart <?= $summary['split_risk'] > 0 ? 'crit' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['split_risk'] ?></div>
        <div class="tehis-kart-lbl">Giriş/Kullanım Split</div>
    </div>
    <div class="tehis-kart <?= $summary['orphan'] > 0 ? 'warn' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['orphan'] ?></div>
        <div class="tehis-kart-lbl">Orphan Hareket</div>
    </div>
    <div class="tehis-kart <?= $summary['sync_eksik'] > 0 ? 'warn' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['sync_eksik'] ?></div>
        <div class="tehis-kart-lbl">Sync Eksik Kayıt</div>
    </div>
    <div class="tehis-kart <?= $summary['pasif_kullanim'] > 0 ? 'warn' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['pasif_kullanim'] ?></div>
        <div class="tehis-kart-lbl">Pasif Def. Kullanımda</div>
    </div>
</div>

<?php
// ── Section toggle JS yardımcısı ─────────────────────────────
function tehis_section_open(string $id, string $title, string $badge_html, bool $open = true): void {
    $chev = $open ? '▾' : '▸';
    echo '<div class="tehis-section">';
    echo '<div class="tehis-section-head" onclick="this.nextElementSibling.hidden=!this.nextElementSibling.hidden;this.querySelector(\'.th-chev\').textContent=this.nextElementSibling.hidden?\'▸\':\'▾\'">';
    echo '<h3>' . $title . ' ' . $badge_html . '</h3>';
    echo '<span class="th-chev">' . $chev . '</span>';
    echo '</div>';
    echo '<div class="tehis-section-body"' . ($open ? '' : ' hidden') . '>';
}
function tehis_section_close(): void { echo '</div></div>'; }
?>

<!-- ──────────────────────────────────────────────────────────
     KRİTİK TAKİP: şüpheli malzemeler
     ────────────────────────────────────────────────────────── -->
<?php tehis_section_open('kritik', '⚡ Kritik Takip Malzemeleri', '<span class="tehis-badge badge-crit">'.count($kritik_defs).' tanım</span>'); ?>
<p class="tehis-sub">Sorun bildirilen malzemelerin tüm material_definitions kayıtları ve stok hareketleri.</p>
<?php if (empty($kritik_defs)): ?>
    <p style="color:#16a34a">✓ Kritik listede eşleşen tanım bulunamadı.</p>
<?php else: ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>ID</th><th>Type</th><th>İsim</th><th>Aktif</th>
        <th class="num">Giriş</th><th class="num">Kullanım</th><th class="num">Sevk</th><th class="num">Net Kalan</th>
        <th>Depolar</th>
    </tr>
    </thead>
    <tbody>
    <?php
    // Normalize key bazında grupla
    $kritik_by_norm = [];
    foreach ($kritik_defs as $d) {
        $kritik_by_norm[nmk($d['name']) . '::' . $d['type']][] = $d;
    }
    foreach ($kritik_by_norm as $grp_rows):
        $is_dup = count($grp_rows) > 1;
        foreach ($grp_rows as $d):
            $mid = (string)$d['id'];
            $msm = $msm_by_mid[$mid] ?? null;
            $giris    = (float)($msm['giris']    ?? 0);
            $kull     = (float)($msm['kullanim'] ?? 0);
            $sevk     = (float)($msm['sevk']     ?? 0);
            $duz      = (float)($msm['duzeltme'] ?? 0);
            $net      = $giris - $kull - $sevk + $duz;
            $row_cls  = $is_dup ? 'crit-row' : ($net < 0 ? 'warn-row' : '');
    ?>
    <tr class="<?= $row_cls ?>">
        <td><strong><?= (int)$d['id'] ?></strong><?= $is_dup ? ' <span class="tehis-badge badge-crit">DUP</span>' : '' ?></td>
        <td><?= h($d['type']) ?></td>
        <td><?= h($d['name']) ?></td>
        <td><?= $d['is_active'] ? '<span class="tehis-badge badge-ok">Aktif</span>' : '<span class="tehis-badge badge-warn">Pasif</span>' ?></td>
        <td class="num <?= $giris > 0 ? 'pos' : '' ?>"><?= $giris > 0 ? number_format($giris, 0) : '—' ?></td>
        <td class="num"><?= $kull > 0 ? number_format($kull, 0) : '—' ?></td>
        <td class="num"><?= $sevk > 0 ? number_format($sevk, 0) : '—' ?></td>
        <td class="num <?= $net < 0 ? 'neg' : ($net > 0 ? 'pos' : '') ?>"><?= number_format($net, 0) ?></td>
        <td style="font-size:.75rem"><?= h($msm['depolar'] ?? '—') ?></td>
    </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 1: Duplicate material_definitions
     ────────────────────────────────────────────────────────── -->
<?php
$dup_badge = $summary['duplicate_grup'] > 0
    ? '<span class="tehis-badge badge-crit">'.$summary['duplicate_grup'].' grup</span>'
    : '<span class="tehis-badge badge-ok">Yok</span>';
tehis_section_open('b1', '1 · Duplicate Tanımlar', $dup_badge, $summary['duplicate_grup'] > 0);
?>
<p class="tehis-sub">Aynı normalize isim + type altında 2+ farklı ID bulunduran malzeme tanımları. Bu gruplar giriş/kullanım karışmasının temel kaynağıdır.</p>
<?php if (empty($duplicate_groups)): ?>
    <p style="color:#16a34a">✓ Aynı tip ve normalize isimde tekrar eden tanım bulunamadı.</p>
<?php else: ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>Normalize Anahtar</th><th>Type</th><th>ID</th><th>Gerçek İsim</th><th>Aktif</th>
        <th class="num">Giriş</th><th class="num">Kullanım</th><th class="num">Net</th><th>Depolar</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($duplicate_groups as $dg):
        $first = true;
        foreach ($dg['rows'] as $r):
            $msm   = $r['msm'];
            $giris = (float)($msm['giris']    ?? 0);
            $kull  = (float)($msm['kullanim'] ?? 0);
            $sevk  = (float)($msm['sevk']     ?? 0);
            $duz   = (float)($msm['duzeltme'] ?? 0);
            $net   = $giris - $kull - $sevk + $duz;
    ?>
    <tr class="crit-row">
        <td><?= $first ? '<span class="tehis-norm">'.h($dg['norm_key']).'</span>' : '' ?></td>
        <td><?= $first ? h($dg['type']) : '' ?></td>
        <td><strong><?= (int)$r['id'] ?></strong></td>
        <td><?= h($r['name']) ?></td>
        <td><?= $r['is_active'] ? '<span class="tehis-badge badge-ok">Aktif</span>' : '<span class="tehis-badge badge-warn">Pasif</span>' ?></td>
        <td class="num <?= $giris > 0 ? 'pos' : '' ?>"><?= $giris > 0 ? number_format($giris,0) : '—' ?></td>
        <td class="num"><?= $kull > 0 ? number_format($kull,0) : '—' ?></td>
        <td class="num <?= $net < 0 ? 'neg' : '' ?>"><?= number_format($net,0) ?></td>
        <td style="font-size:.75rem"><?= h($msm['depolar'] ?? '—') ?></td>
    </tr>
    <?php $first = false; endforeach; endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 2: material_id NULL hareketler
     ────────────────────────────────────────────────────────── -->
<?php
$null_badge = $summary['null_mid'] > 0
    ? '<span class="tehis-badge badge-crit">'.$summary['null_mid'].' hareket</span>'
    : '<span class="tehis-badge badge-ok">Yok</span>';
tehis_section_open('b2', '2 · material_id NULL Hareketler', $null_badge, $summary['null_mid'] > 0);
?>
<p class="tehis-sub">Bu hareketler hiçbir malzeme tanımına bağlanamamış. Stok ekranında "tanımsız" satır olarak görünür.</p>
<?php if ($null_mid_count === 0): ?>
    <p style="color:#16a34a">✓ material_id NULL hareket bulunamadı.</p>
<?php else: ?>
<p><strong><?= $null_mid_count ?></strong> hareket, toplam miktar: <strong><?= number_format($null_mid_qty, 0) ?></strong></p>
<?php
// Malzeme adı dağılımı
$null_names = array_count_values(array_filter(array_column($null_mid_rows, 'material_name')));
arsort($null_names);
if ($null_names):
?>
<p style="font-size:.82rem;margin-bottom:6px">
    Geçen malzeme isimleri: <?= h(implode(', ', array_map(fn($n,$c) => "$n ($c)", array_keys($null_names), $null_names))) ?>
</p>
<?php endif; ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>ID</th><th>Tarih</th><th>Tür</th><th>Malzeme Adı</th><th>Malzeme Tipi</th>
        <th class="num">Miktar</th><th>Birim</th><th>Depo</th><th>Kaynak Tip</th><th>Kaynak ID</th><th>Not</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($null_mid_rows as $r): ?>
    <tr class="warn-row">
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['movement_date'] ?? '') ?></td>
        <td><?= h($r['movement_type']) ?></td>
        <td><?= h($r['material_name'] ?? '—') ?></td>
        <td><?= h($r['material_type'] ?? '—') ?></td>
        <td class="num"><?= number_format((float)$r['quantity'], 2) ?></td>
        <td><?= h($r['unit'] ?? '') ?></td>
        <td><?= h($r['depo'] ?? '') ?: '<em class="muted">Boş</em>' ?></td>
        <td><?= h($r['source_type'] ?? '') ?></td>
        <td><?= $r['source_id'] ? (int)$r['source_id'] : '—' ?></td>
        <td style="font-size:.75rem"><?= h(mb_substr($r['note'] ?? '', 0, 60)) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($null_mid_count > 100): ?>
    <tr><td colspan="11" style="text-align:center;color:var(--muted);font-size:.8rem">… ilk 100 satır gösteriliyor (toplam <?= $null_mid_count ?>)</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 3: Depo Boş / Depo Uyuşmazlığı
     ────────────────────────────────────────────────────────── -->
<?php
$depo_badge = count($depo_mixed_mids) > 0
    ? '<span class="tehis-badge badge-warn">'.count($depo_mixed_mids).' malzeme karışık</span>'
    : ($depo_bos_count > 0 ? '<span class="tehis-badge badge-warn">'.$depo_bos_count.' boş giriş</span>' : '<span class="tehis-badge badge-ok">Temiz</span>');
tehis_section_open('b3', '3 · Depo Boş / Depo Uyuşmazlığı', $depo_badge, count($depo_mixed_mids) > 0);
?>
<p class="tehis-sub">Aynı material_id için hem "Depo Boş" hem de dolu depo hareketi olan malzemeler işaretlendi. Giriş ve kullanım farklı depo anahtarına düşüyorsa negatif stok oluşur.</p>

<?php if (empty($depo_mixed_mids) && $depo_bos_count === 0): ?>
    <p style="color:#16a34a">✓ Depo uyuşmazlığı bulunamadı.</p>
<?php else: ?>
<?php if (!empty($depo_mixed_mids)): ?>
<p style="color:#b45309;font-weight:600;font-size:.85rem;margin-bottom:8px">
    ⚠ <?= count($depo_mixed_mids) ?> malzeme için aynı ID'de hem "Boş Depo" hem de dolu depo hareketi var.
</p>
<?php endif; ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>material_id</th><th>Malzeme</th><th>Depo</th>
        <th class="num">Giriş</th><th class="num">Kullanım</th><th class="num">Sevk</th><th class="num">Net</th><th>Durum</th>
    </tr>
    </thead>
    <tbody>
    <?php
    $prev_mid = null;
    foreach ($depo_rows as $r):
        $mid     = $r['material_id'];
        $is_bos  = $r['depo'] === '[Boş]';
        $is_mix  = in_array($mid, $depo_mixed_mids);
        $giris   = (float)$r['giris'];
        $kull    = (float)$r['kullanim'];
        $sevk    = (float)$r['sevk'];
        $duz     = (float)$r['duzeltme'];
        $net     = $giris - $kull - $sevk + $duz;
        $row_cls = $is_mix ? ($is_bos ? 'crit-row' : 'warn-row') : '';
        // Sadece karışık ya da boş girişleri göster
        if (!$is_mix && !($is_bos && $giris > 0)) continue;
    ?>
    <tr class="<?= $row_cls ?>">
        <td><?= (int)$mid ?></td>
        <td><?= h($r['mat_name']) ?></td>
        <td><?= $is_bos ? '<em class="muted">[Boş Depo]</em>' : h($r['depo']) ?></td>
        <td class="num <?= $giris > 0 ? 'pos' : '' ?>"><?= $giris > 0 ? number_format($giris,0) : '—' ?></td>
        <td class="num"><?= $kull > 0 ? number_format($kull,0) : '—' ?></td>
        <td class="num"><?= $sevk > 0 ? number_format($sevk,0) : '—' ?></td>
        <td class="num <?= $net < 0 ? 'neg' : '' ?>"><?= number_format($net,0) ?></td>
        <td style="font-size:.75rem">
            <?php if ($is_mix && $is_bos): ?>
            <span class="tehis-badge badge-crit">Karışık-Boş</span>
            <?php elseif ($is_mix): ?>
            <span class="tehis-badge badge-warn">Karışık-Dolu</span>
            <?php elseif ($is_bos && $giris > 0): ?>
            <span class="tehis-badge badge-warn">Boş Depo Girişi</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 4: Giriş başka ID, kullanım başka ID
     ────────────────────────────────────────────────────────── -->
<?php
$split_badge = count($split_risks) > 0
    ? '<span class="tehis-badge badge-crit">'.count($split_risks).' grup</span>'
    : '<span class="tehis-badge badge-ok">Yok</span>';
tehis_section_open('b4', '4 · Giriş / Kullanım Farklı ID Split Riski', $split_badge, count($split_risks) > 0);
?>
<p class="tehis-sub">Aynı normalize malzeme adı altında: bir ID'de giriş, başka ID'de kullanım var. Bu durum negatif stok ve çift satır görünümünün doğrudan kaynağıdır.</p>
<?php if (empty($split_risks)): ?>
    <p style="color:#16a34a">✓ Giriş/kullanım split riski bulunamadı.</p>
<?php else: ?>
<?php foreach ($split_risks as $sr): ?>
<div style="border:1.5px solid <?= $sr['risk']==='KRİTİK'?'#dc2626':($sr['risk']==='YÜKSEK'?'#ea580c':'#f59e0b') ?>;border-radius:8px;padding:12px;margin-bottom:12px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span class="tehis-norm"><?= h($sr['norm_key']) ?></span>
        <span style="color:var(--muted);font-size:.8rem"><?= h($sr['type']) ?></span>
        <span class="tehis-badge <?= $sr['risk']==='KRİTİK'?'badge-crit':($sr['risk']==='YÜKSEK'?'badge-crit':'badge-warn') ?>"><?= h($sr['risk']) ?></span>
        <span style="font-size:.8rem">Toplam Giriş: <strong><?= number_format($sr['group_giris'],0) ?></strong>
        &nbsp;|&nbsp; Toplam Kullanım: <strong><?= number_format($sr['group_kullanim'],0) ?></strong>
        &nbsp;|&nbsp; Net: <strong class="<?= $sr['net']<0?'neg':'' ?>"><?= number_format($sr['net'],0) ?></strong></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:.82rem">
        <div>
            <strong style="color:#16a34a">Giriş yapılan ID'ler:</strong>
            <?php if (empty($sr['ids_giris'])): ?>
            <p class="muted">Giriş yok</p>
            <?php else: ?>
            <table class="tehis-table" style="margin-top:4px">
                <tr><th>ID</th><th>İsim</th><th class="num">Giriş</th><th>Depolar</th></tr>
                <?php foreach ($sr['ids_giris'] as $ig): ?>
                <tr><td><?= (int)$ig['id'] ?></td><td><?= h($ig['name']) ?></td>
                    <td class="num pos"><?= number_format($ig['giris'],0) ?></td>
                    <td style="font-size:.73rem"><?= h($ig['depolar']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
        <div>
            <strong style="color:#dc2626">Kullanım yapılan ID'ler:</strong>
            <?php if (empty($sr['ids_kullanim'])): ?>
            <p class="muted">Kullanım yok</p>
            <?php else: ?>
            <table class="tehis-table" style="margin-top:4px">
                <tr><th>ID</th><th>İsim</th><th class="num">Kullanım</th><th>Depolar</th></tr>
                <?php foreach ($sr['ids_kullanim'] as $ik): ?>
                <tr><td><?= (int)$ik['id'] ?></td><td><?= h($ik['name']) ?></td>
                    <td class="num neg"><?= number_format($ik['kullanim'],0) ?></td>
                    <td style="font-size:.73rem"><?= h($ik['depolar']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 5: Orphan hareketler
     ────────────────────────────────────────────────────────── -->
<?php
$orphan_badge = $summary['orphan'] > 0
    ? '<span class="tehis-badge badge-warn">'.$summary['orphan'].' hareket</span>'
    : '<span class="tehis-badge badge-ok">Temiz</span>';
tehis_section_open('b5', '5 · Orphan Hareketler', $orphan_badge, $summary['orphan'] > 0);
?>
<p class="tehis-sub">source_type='loading' ama ilgili loading_records kaydı artık yok. Silinmiş kayıtlardan kalan stok hareketleri.</p>
<?php if ($orphan_count === 0): ?>
    <p style="color:#16a34a">✓ Orphan hareket bulunamadı.</p>
<?php else: ?>
<p><strong><?= $orphan_count ?></strong> orphan hareket bulundu.</p>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>ID</th><th>Tarih</th><th>Tür</th><th>Malzeme</th>
        <th class="num">Miktar</th><th>Birim</th><th>Depo</th><th>Kaynak ID (silinmiş)</th><th>Not</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($orphan_rows as $r): ?>
    <tr class="warn-row">
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['movement_date'] ?? '') ?></td>
        <td><?= h($r['movement_type']) ?></td>
        <td><?= h($r['material_name'] ?? '—') ?></td>
        <td class="num"><?= number_format((float)$r['quantity'], 0) ?></td>
        <td><?= h($r['unit'] ?? '') ?></td>
        <td><?= h($r['depo'] ?? '') ?: '<em class="muted">Boş</em>' ?></td>
        <td style="color:#dc2626"><strong><?= (int)$r['source_id'] ?></strong></td>
        <td style="font-size:.75rem"><?= h(mb_substr($r['note'] ?? '', 0, 60)) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($orphan_count > 50): ?>
    <tr><td colspan="9" style="text-align:center;color:var(--muted);font-size:.8rem">… ilk 50 satır gösteriliyor (toplam <?= $orphan_count ?>)</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 6: Sync eksik yükleme kayıtları
     ────────────────────────────────────────────────────────── -->
<?php
$sync_badge = $summary['sync_eksik'] > 0
    ? '<span class="tehis-badge badge-warn">'.$summary['sync_eksik'].' kayıt</span>'
    : '<span class="tehis-badge badge-ok">Temiz</span>';
tehis_section_open('b6', '6 · Sync Eksik Yükleme Kayıtları', $sync_badge, $summary['sync_eksik'] > 0);
?>
<p class="tehis-sub">Yükleme kaydı + paleti var ama material_stock_movements'ta bu kayda ait hiç hareket yazılmamış. Bu kayıtların kasa/palet kullanımı stoka yansımıyor.</p>
<?php if ($sync_eksik_count === 0): ?>
    <p style="color:#16a34a">✓ Sync eksik kayıt bulunamadı.</p>
<?php else: ?>
<p><strong><?= $sync_eksik_count ?></strong> kayıt sync edilmemiş.</p>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>ID</th><th>Tarih</th><th>Firma</th><th>Ürün</th><th>Durum</th>
        <th class="num">Palet</th><th class="num">Kasa</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($sync_eksik_rows as $r): ?>
    <tr class="warn-row">
        <td><a href="record_view.php?id=<?= (int)$r['id'] ?>" target="_blank"><?= (int)$r['id'] ?></a></td>
        <td><?= h($r['tarih'] ?? '') ?></td>
        <td><?= h($r['firma'] ?? '—') ?></td>
        <td><?= h($r['urun'] ?? '—') ?></td>
        <td><?= h($r['durum'] ?? '') ?: '<em class="muted">—</em>' ?></td>
        <td class="num"><?= (int)$r['palet_sayisi'] ?></td>
        <td class="num"><?= (int)$r['kasa_toplam'] ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($sync_eksik_count > 50): ?>
    <tr><td colspan="7" style="text-align:center;color:var(--muted);font-size:.8rem">… ilk 50 satır gösteriliyor (toplam <?= $sync_eksik_count ?>)</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 7: Pasif tanım hâlâ kullanımda
     ────────────────────────────────────────────────────────── -->
<?php
$pasif_badge = $summary['pasif_kullanim'] > 0
    ? '<span class="tehis-badge badge-warn">'.$summary['pasif_kullanim'].' tanım</span>'
    : '<span class="tehis-badge badge-ok">Temiz</span>';
tehis_section_open('b7', '7 · Pasif Tanımlar Yükleme Planında Kullanımda', $pasif_badge, false);
?>
<p class="tehis-sub">is_active=0 olarak işaretlenmiş ama hâlâ loading_pallets.kasa_cinsi_id veya palet_tipi_id ile bağlantılı tanımlar.</p>
<?php if (empty($pasif_kullanim_rows)): ?>
    <p style="color:#16a34a">✓ Pasif tanım yükleme planında kullanılmıyor.</p>
<?php else: ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr><th>ID</th><th>Type</th><th>İsim</th><th class="num">Palet Kullanım</th></tr>
    </thead>
    <tbody>
    <?php foreach ($pasif_kullanim_rows as $r): ?>
    <tr class="warn-row">
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['type']) ?></td>
        <td><?= h($r['name']) ?></td>
        <td class="num"><?= (int)$r['palet_kullanim'] ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- Altbilgi -->
<div style="margin-top:20px;padding:10px 14px;background:#f8fafc;border:1px solid var(--border);border-radius:8px;font-size:.78rem;color:var(--muted)">
    <strong>Teşhis raporu:</strong> <?= date('d.m.Y H:i:s') ?> &nbsp;·&nbsp;
    Sadece SELECT sorguları kullanıldı. Hiçbir veri değiştirilmedi. &nbsp;·&nbsp;
    <a href="audit.php">← Sistem Audit</a>
</div>

<?php render_footer(); ?>
