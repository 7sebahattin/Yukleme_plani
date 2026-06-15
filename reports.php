<?php
// =========================================================
// reports.php - Raporlar modülü
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (($_GET['export'] ?? '') !== '') { require_perm('reports.export'); }
else { require_perm('reports.read'); }

$type      = trim($_GET['type']      ?? '');
$export    = trim($_GET['export']    ?? '');
$f_from    = trim($_GET['date_from'] ?? '');
$f_to      = trim($_GET['date_to']   ?? '');
$f_firma   = trim($_GET['firma']     ?? '');
$f_durum   = trim($_GET['durum']     ?? '');
$f_cikma_rapor = trim($_GET['cikma_rapor'] ?? '');
if (!in_array($f_cikma_rapor, ['raporlanmamis', 'raporlandi'], true)) $f_cikma_rapor = '';
$f_urun    = trim($_GET['urun']      ?? '');
$f_bolge   = trim($_GET['bolge']     ?? '');
$f_depo    = trim($_GET['depo']      ?? '');
$f_plaka   = trim($_GET['plaka']     ?? '');
$f_mtype   = trim($_GET['mat_type']  ?? '');
$f_q       = trim($_GET['q']         ?? '');
$f_sort    = trim($_GET['sort']      ?? 'tarih');
$f_palet_islendi  = trim($_GET['palet_islendi']  ?? '');
$f_kantar_firma   = isset($_GET['kantar_firma']) ? trim($_GET['kantar_firma']) : 'Asya Fresh';
$f_urun_sahibi    = trim($_GET['urun_sahibi']   ?? '');
$f_casus          = trim($_GET['casus']          ?? '');
if (!in_array($f_casus, ['dolu','bos'], true)) $f_casus = '';

$valid_types = ['yukleme','cikma','depo','urun','firma','malzeme','kantar','gunluk'];
if ($type !== '' && !in_array($type, $valid_types, true)) { $type = ''; }

$report_meta = [
    'yukleme'  => ['label'=>'Yüklemeler', 'icon'=>'🚚', 'bg'=>'#eaf1ff'],
    'cikma'    => ['label'=>'Çıkmalar',   'icon'=>'📋', 'bg'=>'#fdecea'],
    'depo'     => ['label'=>'Depo',       'icon'=>'🏭', 'bg'=>'#f0faf4'],
    'urun'     => ['label'=>'Ürün',       'icon'=>'🌿', 'bg'=>'#fdf6dc'],
    'firma'    => ['label'=>'Firma',      'icon'=>'🏢', 'bg'=>'#f5f0ff'],
    'malzeme'  => ['label'=>'Malzeme',    'icon'=>'📦', 'bg'=>'#fff5f0'],
    'kantar'   => ['label'=>'Kantar',          'icon'=>'⚖️',  'bg'=>'#f0f9ff'],
    'gunluk'   => ['label'=>'Günlük Rapor',      'icon'=>'📅', 'bg'=>'#f0fdf4'],
];

function col_label(string $c): string {
    static $m = [
        'id'=>'ID','tarih'=>'Tarih','firma'=>'Firma','bolge'=>'Bölge','alici'=>'Alıcı',
        'urun'=>'Ürün','parti_no'=>'Parti No','casus_no'=>'Casus No','cikis_nedeni'=>'Çıkma Nedeni','on_plaka'=>'Plaka','arka_plaka'=>'Arka Plaka',
        'gumruk'=>'Gümrük','fatura_no'=>'Fatura No','etiket'=>'Not','brand'=>'Marka',
        'urun_sahibi_adi'=>'Ürün Sahibi',
        'sofor_adi'=>'Şoför','telefon'=>'Telefon','nakliye_sirketi'=>'Nakliye Şirketi',
        'durum'=>'Durum','palet_sayisi'=>'Palet','toplam_kasa'=>'Kasa',
        'toplam_brut'=>'Brüt KG','toplam_dara'=>'Dara KG','toplam_net'=>'Net KG',
        'nakliye_bedeli'=>'Nakliye Bedeli','avans'=>'Avans','created_at'=>'Oluşturulma',
        'depo'=>'Depo','kayit_sayisi'=>'Kayıt Sayısı','ilk_tarih'=>'İlk Tarih','son_tarih'=>'Son Tarih',
        'toplam_kayit'=>'Toplam Kayıt','yukleme_sayisi'=>'Yükleme','cikma_sayisi'=>'Çıkma',
        'material_type'=>'Tür','material_name'=>'Malzeme','unit_dara_kg'=>'Birim Dara (kg)',
        'toplam_adet'=>'Yükl. Adet','toplam_dara_kg'=>'Yükl. Dara KG',
        'kullanim_sayisi'=>'Kullanım','is_active'=>'Aktif',
        'stok_giris'=>'Stok Giriş','stok_sevk'=>'Stok Sevk','stok_mevcut'=>'Stok Mevcut',
        'fis_no'=>'Fiş No','giris_tarih'=>'Giriş','cikis_tarih'=>'Çıkış',
        'firma_adi'=>'Firma','plaka'=>'Plaka','malin_cinsi'=>'Malın Cinsi',
        'tartim1'=>'1.Tartım','tartim2'=>'2.Tartım','net_kg'=>'Net KG',
        'kasa_sayisi'=>'Kasa Sayısı','kasa_cinsi'=>'Kasa Cinsi',
        'palet_cinsi'=>'Palet Cinsi','operator_adi'=>'Operatör',
    ];
    return $m[$c] ?? $c;
}

// ── Veri çekme ──────────────────────────────────────────
$rows  = [];
$cols  = [];
$totals = [];
$islendi_totals = null;

function rpt_date_filter(string &$sql, array &$p, string $col, string $from, string $to): void {
    if ($from !== '') { $sql .= " AND $col >= :df"; $p[':df'] = $from; }
    if ($to   !== '') { $sql .= " AND $col <= :dt"; $p[':dt'] = $to;   }
}

// Türkçe karakter duyarsız normalleştirme (KAYISI → kayısı gibi birleştirmek için)
function rpt_norm(string $s): string {
    return mb_strtolower(strtr($s, [
        'İ'=>'i', 'I'=>'ı', 'Ş'=>'ş', 'Ğ'=>'ğ', 'Ü'=>'ü', 'Ö'=>'ö', 'Ç'=>'ç',
    ]), 'UTF-8');
}

function rpt_merge_rows(array $rows, string $key_col, array $sum_int, array $sum_float): array {
    $merged  = [];
    $key_map = [];
    foreach ($rows as $row) {
        $key = rpt_norm((string)($row[$key_col] ?? ''));
        if (!isset($key_map[$key])) {
            $key_map[$key] = count($merged);
            $merged[] = $row;
        } else {
            $idx = $key_map[$key];
            foreach ($sum_int   as $c) $merged[$idx][$c] = (int)($merged[$idx][$c] ?? 0) + (int)($row[$c] ?? 0);
            foreach ($sum_float as $c) $merged[$idx][$c] = (float)($merged[$idx][$c] ?? 0) + (float)($row[$c] ?? 0);
        }
    }
    return $merged;
}

if ($type === 'yukleme' || $type === 'cikma') {
    // Palet işaret durumuna göre aggregate sütunları değişir
    if ($f_palet_islendi === 'isaretli') {
        $agg_palet = "COALESCE(COUNT(CASE WHEN p.islendi=1 THEN 1 END),0)";
        $agg_kasa  = "COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.kasa_adeti ELSE 0 END),0)";
        $agg_brut  = "COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.brut_kg    ELSE 0 END),0)";
        $agg_dara  = "COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.dara_kg    ELSE 0 END),0)";
        $agg_net   = "COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.net_kg     ELSE 0 END),0)";
    } elseif ($f_palet_islendi === 'hicbiri') {
        $agg_palet = "COALESCE(COUNT(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN 1 END),0)";
        $agg_kasa  = "COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.kasa_adeti ELSE 0 END),0)";
        $agg_brut  = "COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.brut_kg    ELSE 0 END),0)";
        $agg_dara  = "COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.dara_kg    ELSE 0 END),0)";
        $agg_net   = "COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.net_kg     ELSE 0 END),0)";
    } else {
        $agg_palet = "COUNT(p.id)";
        $agg_kasa  = "COALESCE(SUM(p.kasa_adeti),0)";
        $agg_brut  = "COALESCE(SUM(p.brut_kg),0)";
        $agg_dara  = "COALESCE(SUM(p.dara_kg),0)";
        $agg_net   = "COALESCE(SUM(p.net_kg),0)";
    }
    // urun_sahibi_id kolonu var mı kontrol et (SELECT ve WHERE'de kullanılır)
    $_us_col_ok = false;
    try { $_us_col_ok = (bool)db()->query("SHOW COLUMNS FROM `loading_records` LIKE 'urun_sahibi_id'")->fetchColumn(); }
    catch (Throwable $_) {}
    $_us_subq = $_us_col_ok
        ? "(SELECT md.name FROM material_definitions md WHERE md.id = r.urun_sahibi_id LIMIT 1) AS urun_sahibi_adi"
        : "'' AS urun_sahibi_adi";

    $sql = "SELECT r.id, r.tarih, r.firma, r.bolge, r.alici, r.urun, r.parti_no, r.casus_no, r.cikis_nedeni,
                   r.gumruk, r.fatura_no, COALESCE(r.brand,'') AS brand, r.etiket,
                   r.on_plaka, r.arka_plaka, r.durum, r.nakliye_bedeli, r.avans,
                   r.sofor_adi, r.nakliye_sirketi, r.telefon,
                   {$_us_subq},
                   (SELECT p2.depo FROM loading_pallets p2
                    WHERE p2.loading_record_id = r.id AND p2.depo != ''
                    ORDER BY p2.id LIMIT 1)  AS depo,
                   {$agg_palet}              AS palet_sayisi,
                   {$agg_kasa}               AS toplam_kasa,
                   {$agg_brut}               AS toplam_brut,
                   {$agg_dara}               AS toplam_dara,
                   {$agg_net}                AS toplam_net,
                   r.created_at
            FROM loading_records r
            LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
            WHERE r.type = :rtype";
    $p = [':rtype' => $type];
    if ($f_firma !== '') { $sql .= " AND r.firma = :firma"; $p[':firma'] = $f_firma; }
    if ($f_durum !== '' && $type === 'yukleme') { $sql .= " AND r.durum = :durum"; $p[':durum'] = $f_durum; }
    if ($f_urun  !== '') { $sql .= " AND r.urun  = :urun";  $p[':urun']  = $f_urun;  }
    if ($f_bolge !== '') { $sql .= " AND r.bolge = :bolge"; $p[':bolge'] = $f_bolge; }
    if ($f_depo  !== '') { $sql .= " AND p.depo  = :depo";  $p[':depo']  = $f_depo;  }
    // Sprint ÜrünSahibi-01: ürün sahibi filtresi (yalnızca yukleme)
    if ($f_urun_sahibi !== '' && $type === 'yukleme') {
        if ($_us_col_ok) {
            if ($f_urun_sahibi === '0') {
                $sql .= " AND r.urun_sahibi_id IS NULL";
            } elseif (ctype_digit($f_urun_sahibi) && (int)$f_urun_sahibi > 0) {
                $sql .= " AND r.urun_sahibi_id = :urun_sahibi";
                $p[':urun_sahibi'] = (int)$f_urun_sahibi;
            }
        }
    }
    if ($type === 'cikma' && $f_cikma_rapor !== '') {
        static $_rpt_col_checked = null;
        if ($_rpt_col_checked === null) {
            try { $_rpt_col_checked = (bool)db()->query("SHOW COLUMNS FROM `loading_records` LIKE 'reported_at'")->fetchColumn(); }
            catch (Throwable $_) { $_rpt_col_checked = false; }
        }
        if ($_rpt_col_checked) {
            if ($f_cikma_rapor === 'raporlandi')    { $sql .= " AND r.reported_at IS NOT NULL"; }
            if ($f_cikma_rapor === 'raporlanmamis') { $sql .= " AND r.reported_at IS NULL"; }
        }
    }
    if ($f_casus === 'dolu') { $sql .= " AND r.casus_no != '' AND r.casus_no IS NOT NULL"; }
    if ($f_casus === 'bos')  { $sql .= " AND (r.casus_no IS NULL OR r.casus_no = '')"; }
    if ($f_q     !== '') {
        $sql .= " AND (r.firma LIKE :q OR r.parti_no LIKE :q OR r.alici LIKE :q OR r.urun LIKE :q)";
        $p[':q'] = '%'.$f_q.'%';
    }
    rpt_date_filter($sql, $p, 'r.tarih', $f_from, $f_to);
    $sort_map = [
        'tarih' => 'r.tarih DESC, r.id DESC',
        'firma' => 'r.firma ASC, r.tarih DESC',
        'durum' => 'r.durum ASC, r.tarih DESC',
        'palet' => 'palet_sayisi DESC, r.tarih DESC',
        'net'   => 'toplam_net DESC',
    ];
    $order_by = $sort_map[$f_sort] ?? 'r.tarih DESC, r.id DESC';
    $sql .= " GROUP BY r.id";
    if ($f_palet_islendi === 'isaretli') $sql .= " HAVING COUNT(CASE WHEN p.islendi=1  THEN 1 END) > 0";
    if ($f_palet_islendi === 'hicbiri')  $sql .= " HAVING COUNT(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN 1 END) > 0";
    $sql .= " ORDER BY $order_by LIMIT 2000";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    if ($type === 'yukleme') {
        // Kolon grupları: Temel | Ürün | Nakliye | Toplamlar | Durum
        $cols = [
            'id','tarih','firma','bolge','parti_no',
            'alici','urun','brand','urun_sahibi_adi','etiket','gumruk','fatura_no','casus_no','depo',
            'sofor_adi','telefon','on_plaka','nakliye_sirketi','nakliye_bedeli','avans',
            'palet_sayisi','toplam_kasa','toplam_brut','toplam_dara','toplam_net',
            'durum',
        ];
    } else { // cikma — durum sütunu yok, alıcı yok, çıkma nedeni göster
        $cols = ['tarih','firma','bolge','depo','urun','cikis_nedeni','palet_sayisi','toplam_kasa','toplam_brut','toplam_dara','toplam_net'];
    }
    foreach ($rows as $r) {
        $totals['palet_sayisi']   = ($totals['palet_sayisi']   ?? 0) + (int)$r['palet_sayisi'];
        $totals['toplam_kasa']    = ($totals['toplam_kasa']    ?? 0) + (int)$r['toplam_kasa'];
        $totals['toplam_brut']    = ($totals['toplam_brut']    ?? 0) + (float)$r['toplam_brut'];
        $totals['toplam_dara']    = ($totals['toplam_dara']    ?? 0) + (float)$r['toplam_dara'];
        $totals['toplam_net']     = ($totals['toplam_net']     ?? 0) + (float)$r['toplam_net'];
        $totals['nakliye_bedeli'] = ($totals['nakliye_bedeli'] ?? 0) + (float)$r['nakliye_bedeli'];
    }

    // İşaretli / İşaretsiz palet ayrımı
    $sp = "SELECT
        COALESCE(SUM(CASE WHEN p.islendi=1 THEN 1 ELSE 0 END),0)              AS is_palet,
        COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.kasa_adeti ELSE 0 END),0)   AS is_kasa,
        COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.brut_kg ELSE 0 END),0)      AS is_brut,
        COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.dara_kg ELSE 0 END),0)      AS is_dara,
        COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.net_kg ELSE 0 END),0)       AS is_net,
        COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN 1 ELSE 0 END),0)             AS nis_palet,
        COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.kasa_adeti ELSE 0 END),0)  AS nis_kasa,
        COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.brut_kg ELSE 0 END),0)     AS nis_brut,
        COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.dara_kg ELSE 0 END),0)     AS nis_dara,
        COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.net_kg ELSE 0 END),0)      AS nis_net
        FROM loading_records r
        LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
        WHERE r.type = :rtype2";
    $sp2 = [':rtype2' => $type];
    if ($f_firma !== '') { $sp .= " AND r.firma = :firma2"; $sp2[':firma2'] = $f_firma; }
    if ($f_durum !== '') { $sp .= " AND r.durum = :durum2"; $sp2[':durum2'] = $f_durum; }
    if ($f_urun  !== '') { $sp .= " AND r.urun  = :urun2";  $sp2[':urun2']  = $f_urun;  }
    if ($f_bolge !== '') { $sp .= " AND r.bolge = :bolge2"; $sp2[':bolge2'] = $f_bolge; }
    if ($f_depo  !== '') { $sp .= " AND p.depo  = :depo2";  $sp2[':depo2']  = $f_depo;  }
    if ($f_q     !== '') { $sp .= " AND (r.firma LIKE :q2 OR r.parti_no LIKE :q2 OR r.alici LIKE :q2 OR r.urun LIKE :q2)"; $sp2[':q2'] = '%'.$f_q.'%'; }
    if ($f_from  !== '') { $sp .= " AND r.tarih >= :df2"; $sp2[':df2'] = $f_from; }
    if ($f_to    !== '') { $sp .= " AND r.tarih <= :dt2"; $sp2[':dt2'] = $f_to; }
    if ($f_urun_sahibi !== '' && $type === 'yukleme' && $_us_col_ok) {
        if ($f_urun_sahibi === '0') {
            $sp .= " AND r.urun_sahibi_id IS NULL";
        } elseif (ctype_digit($f_urun_sahibi) && (int)$f_urun_sahibi > 0) {
            $sp .= " AND r.urun_sahibi_id = :urun_sahibi2";
            $sp2[':urun_sahibi2'] = (int)$f_urun_sahibi;
        }
    }
    if ($f_palet_islendi === 'isaretli') { $sp .= " GROUP BY r.id HAVING COUNT(CASE WHEN p.islendi=1  THEN 1 END)>0"; }
    elseif ($f_palet_islendi === 'hicbiri') { $sp .= " GROUP BY r.id HAVING COUNT(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN 1 END)>0"; }
    $sp_st = db()->prepare($sp); $sp_st->execute($sp2);
    $islendi_totals = $sp_st->fetch() ?: null;

} elseif ($type === 'depo') {
    $sql = "SELECT p.depo,
                   COUNT(DISTINCT r.id)          AS kayit_sayisi,
                   COUNT(p.id)                   AS palet_sayisi,
                   SUM(p.kasa_adeti)             AS toplam_kasa,
                   ROUND(SUM(p.brut_kg),3)       AS toplam_brut,
                   ROUND(SUM(p.dara_kg),3)       AS toplam_dara,
                   ROUND(SUM(p.net_kg),3)        AS toplam_net,
                   MIN(r.tarih)                  AS ilk_tarih,
                   MAX(r.tarih)                  AS son_tarih
            FROM loading_pallets p
            JOIN loading_records r ON r.id = p.loading_record_id
            WHERE p.depo != ''";
    $p = [];
    if ($f_depo  !== '') { $sql .= " AND p.depo LIKE :depo";   $p[':depo']  = '%'.$f_depo.'%'; }
    if ($f_firma !== '') { $sql .= " AND r.firma LIKE :firma";  $p[':firma'] = '%'.$f_firma.'%'; }
    rpt_date_filter($sql, $p, 'r.tarih', $f_from, $f_to);
    $sql .= " GROUP BY p.depo ORDER BY toplam_kasa DESC LIMIT 500";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    $rows = rpt_merge_rows($rows, 'depo',
        ['kayit_sayisi','palet_sayisi','toplam_kasa'],
        ['toplam_brut','toplam_dara','toplam_net']);
    $cols = ['depo','kayit_sayisi','palet_sayisi','toplam_kasa','toplam_brut','toplam_dara','toplam_net','ilk_tarih','son_tarih'];
    foreach ($rows as $r) {
        $totals['toplam_kasa']  = ($totals['toplam_kasa']  ?? 0) + (float)$r['toplam_kasa'];
        $totals['palet_sayisi'] = ($totals['palet_sayisi'] ?? 0) + (int)$r['palet_sayisi'];
        $totals['toplam_net']   = ($totals['toplam_net']   ?? 0) + (float)$r['toplam_net'];
    }

} elseif ($type === 'urun') {
    $sql = "SELECT COALESCE(NULLIF(r.urun,''),'—') AS urun,
                   COUNT(DISTINCT r.id)          AS kayit_sayisi,
                   COUNT(p.id)                   AS palet_sayisi,
                   COALESCE(SUM(p.kasa_adeti),0) AS toplam_kasa,
                   ROUND(COALESCE(SUM(p.brut_kg),0),3) AS toplam_brut,
                   ROUND(COALESCE(SUM(p.net_kg),0),3)  AS toplam_net
            FROM loading_records r
            LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
            WHERE 1=1";
    $p = [];
    if ($f_urun  !== '') { $sql .= " AND r.urun LIKE :urun";    $p[':urun']  = '%'.$f_urun.'%'; }
    if ($f_firma !== '') { $sql .= " AND r.firma LIKE :firma";  $p[':firma'] = '%'.$f_firma.'%'; }
    rpt_date_filter($sql, $p, 'r.tarih', $f_from, $f_to);
    $sql .= " GROUP BY r.urun ORDER BY toplam_kasa DESC LIMIT 500";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    // Türkçe karakter nedeniyle aynı ürün farklı satır olarak görünebilir — birleştir
    $rows = rpt_merge_rows($rows, 'urun',
        ['kayit_sayisi','palet_sayisi','toplam_kasa'],
        ['toplam_brut','toplam_net']);
    $cols = ['urun','kayit_sayisi','palet_sayisi','toplam_kasa','toplam_brut','toplam_net'];
    foreach ($rows as $r) {
        $totals['toplam_kasa'] = ($totals['toplam_kasa'] ?? 0) + (float)$r['toplam_kasa'];
        $totals['toplam_net']  = ($totals['toplam_net']  ?? 0) + (float)$r['toplam_net'];
    }

} elseif ($type === 'firma') {
    $sql = "SELECT COALESCE(NULLIF(r.firma,''),'—') AS firma,
                   COUNT(DISTINCT r.id)              AS toplam_kayit,
                   SUM(CASE WHEN r.type='yukleme' THEN 1 ELSE 0 END) AS yukleme_sayisi,
                   SUM(CASE WHEN r.type='cikma'   THEN 1 ELSE 0 END) AS cikma_sayisi,
                   COALESCE(SUM(p.kasa_adeti),0)     AS toplam_kasa,
                   ROUND(COALESCE(SUM(p.brut_kg),0),3) AS toplam_brut,
                   ROUND(COALESCE(SUM(p.net_kg),0),3)  AS toplam_net,
                   MIN(r.tarih)                      AS ilk_tarih,
                   MAX(r.tarih)                      AS son_tarih
            FROM loading_records r
            LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
            WHERE 1=1";
    $p = [];
    if ($f_firma !== '') { $sql .= " AND r.firma LIKE :firma"; $p[':firma'] = '%'.$f_firma.'%'; }
    rpt_date_filter($sql, $p, 'r.tarih', $f_from, $f_to);
    $sql .= " GROUP BY r.firma ORDER BY toplam_kasa DESC LIMIT 500";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    // Aynı firma farklı büyük/küçük harf ile kaydedilmiş olabilir — birleştir
    $rows = rpt_merge_rows($rows, 'firma',
        ['toplam_kayit','yukleme_sayisi','cikma_sayisi','toplam_kasa'],
        ['toplam_brut','toplam_net']);
    $cols = ['firma','toplam_kayit','yukleme_sayisi','cikma_sayisi','toplam_kasa','toplam_brut','toplam_net','ilk_tarih','son_tarih'];
    foreach ($rows as $r) {
        $totals['toplam_kasa'] = ($totals['toplam_kasa'] ?? 0) + (float)$r['toplam_kasa'];
        $totals['toplam_net']  = ($totals['toplam_net']  ?? 0) + (float)$r['toplam_net'];
    }

} elseif ($type === 'malzeme') {
    $sql = "SELECT md.type AS material_type, md.name AS material_name,
                   md.unit_dara_kg, md.is_active,
                   COALESCE(pm_s.kullanim_sayisi, 0)             AS kullanim_sayisi,
                   COALESCE(pm_s.toplam_adet, 0)                 AS toplam_adet,
                   COALESCE(ROUND(pm_s.toplam_dara_kg, 3), 0)   AS toplam_dara_kg,
                   COALESCE(ms_g.stok_giris, 0)                  AS stok_giris,
                   COALESCE(ms_s.stok_sevk,  0)                  AS stok_sevk,
                   COALESCE(ms_g.stok_giris, 0) - COALESCE(ms_s.stok_sevk, 0) AS stok_mevcut
            FROM material_definitions md
            LEFT JOIN (
                SELECT material_id,
                       COUNT(id)          AS kullanim_sayisi,
                       SUM(quantity)      AS toplam_adet,
                       SUM(total_dara_kg) AS toplam_dara_kg
                FROM pallet_materials WHERE material_id IS NOT NULL GROUP BY material_id
            ) pm_s ON pm_s.material_id = md.id
            LEFT JOIN (
                SELECT material_id, SUM(quantity) AS stok_giris
                FROM material_stock_movements
                WHERE movement_type = 'giris' AND material_id IS NOT NULL
                GROUP BY material_id
            ) ms_g ON ms_g.material_id = md.id
            LEFT JOIN (
                SELECT material_id, SUM(quantity) AS stok_sevk
                FROM material_stock_movements
                WHERE movement_type = 'sevk' AND material_id IS NOT NULL
                GROUP BY material_id
            ) ms_s ON ms_s.material_id = md.id
            WHERE md.type NOT IN ('firma','depo','urun')";
    $p = [];
    if ($f_mtype !== '') { $sql .= " AND md.type = :mtype"; $p[':mtype'] = $f_mtype; }
    if ($f_q     !== '') { $sql .= " AND md.name LIKE :q";  $p[':q']     = '%'.$f_q.'%'; }
    $sql .= " ORDER BY md.type, md.name LIMIT 1000";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    $cols = ['material_type','material_name','unit_dara_kg','stok_giris','stok_sevk','stok_mevcut','toplam_adet','toplam_dara_kg','kullanim_sayisi','is_active'];
    foreach ($rows as $r) {
        $totals['toplam_dara_kg'] = ($totals['toplam_dara_kg'] ?? 0) + (float)$r['toplam_dara_kg'];
        $totals['stok_giris']     = ($totals['stok_giris']     ?? 0) + (float)$r['stok_giris'];
        $totals['stok_sevk']      = ($totals['stok_sevk']      ?? 0) + (float)$r['stok_sevk'];
        $totals['stok_mevcut']    = ($totals['stok_mevcut']    ?? 0) + (float)$r['stok_mevcut'];
    }

} elseif ($type === 'kantar') {
    $sql = "SELECT id, fis_no, giris_tarih, cikis_tarih,
                   firma_adi, plaka, malin_cinsi,
                   tartim1, tartim2, net_kg,
                   kasa_sayisi, palet_sayisi, kasa_cinsi, palet_cinsi, operator_adi, created_at
            FROM kantar_fisleri WHERE 1=1";
    $p = [];
    if ($f_firma !== '') { $sql .= " AND firma_adi LIKE :firma"; $p[':firma'] = '%'.$f_firma.'%'; }
    if ($f_plaka !== '') { $sql .= " AND plaka LIKE :plaka";     $p[':plaka'] = '%'.$f_plaka.'%'; }
    if ($f_q     !== '') {
        $sql .= " AND (firma_adi LIKE :q OR fis_no LIKE :q OR plaka LIKE :q OR malin_cinsi LIKE :q)";
        $p[':q'] = '%'.$f_q.'%';
    }
    if ($f_from !== '') { $sql .= " AND giris_tarih >= :df"; $p[':df'] = $f_from; }
    if ($f_to   !== '') { $sql .= " AND giris_tarih <= :dt"; $p[':dt'] = $f_to; }
    $sql .= " ORDER BY id DESC LIMIT 1000";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    $cols = ['id','fis_no','giris_tarih','cikis_tarih','firma_adi','plaka','malin_cinsi','tartim1','tartim2','net_kg','kasa_sayisi','palet_sayisi','operator_adi'];
    foreach ($rows as $r) {
        $totals['net_kg'] = ($totals['net_kg'] ?? 0) + (float)$r['net_kg'];
    }

} elseif ($type === 'gunluk') {
    // Tarih cross-fill — tek tarih girilince aynı gün yapılır; her ikisi boşsa filtre yok
    if ($f_from !== '' && $f_to === '') { $f_to   = $f_from; }
    elseif ($f_from === '' && $f_to !== '') { $f_from = $f_to; }

    $gk_rows = $yk_rows = $ck_rows = [];

    // Kantar girişleri
    try {
        // Kolon yoksa ekle (idempotent fallback — db.php migration çalışmamışsa)
        $rep_col_exists = (bool)db()->query("SHOW COLUMNS FROM `kantar_fisleri` LIKE 'reported_at'")->fetchColumn();
        if (!$rep_col_exists) {
            try {
                db()->exec("ALTER TABLE `kantar_fisleri`
                    ADD COLUMN `reported_at` DATETIME NULL,
                    ADD COLUMN `reported_by` INT NULL");
                $rep_col_exists = true;
            } catch (PDOException $_me) {}
        }
        // Kantar: tarih filtresiz — raporlanmamış fiş tarihe bakılmaksızın görünmeli.
        // kantar.php'deki "Raporla" koşuluyla birebir aynı: reported_at IS NULL
        $kw = ["1=1"];
        if ($rep_col_exists) { $kw[] = "kf.reported_at IS NULL"; }
        $kp = [];
        if ($f_firma       !== '') { $kw[] = "kf.firma_adi = ?"; $kp[] = $f_firma; }
        if ($f_kantar_firma !== '') { $kw[] = "(EXISTS (SELECT 1 FROM kantar_gruplar _kg WHERE _kg.fis_id=kf.id AND _kg.grup_adi = ?) OR NOT EXISTS (SELECT 1 FROM kantar_gruplar _kg2 WHERE _kg2.fis_id=kf.id))"; $kp[] = $f_kantar_firma; }
        if ($f_depo  !== '') { $kw[] = "kf.depo = ?";            $kp[] = $f_depo;  }
        if ($f_urun  !== '') { $kw[] = "kf.malin_cinsi LIKE ?";  $kp[] = '%'.$f_urun.'%'; }
        $st = db()->prepare("SELECT kf.*,
            (SELECT COUNT(*) FROM kantar_gruplar WHERE fis_id=kf.id) AS grup_count
            FROM kantar_fisleri kf
            WHERE " . implode(' AND ', $kw) . "
            ORDER BY kf.giris_tarih DESC, kf.id DESC LIMIT 500");
        $st->execute($kp);
        $gk_rows = $st->fetchAll();
    } catch (PDOException $e) {}
    // Pre-fetch kantar grupları tüm fişler için (tek sorgu)
    $gk_gruplar = [];
    if (!empty($gk_rows)) {
        $_gk_ids = array_column($gk_rows, 'id');
        $_gk_ph  = implode(',', array_fill(0, count($_gk_ids), '?'));
        try {
            $_st_g = db()->prepare("SELECT fis_id, grup_adi, palet_sayisi, kasa_adedi, kasa_dara_kg, palet_dara_kg, brut_kg FROM kantar_gruplar WHERE fis_id IN ($_gk_ph) ORDER BY fis_id, sira");
            $_st_g->execute($_gk_ids);
            foreach ($_st_g->fetchAll() as $_grp) {
                $gk_gruplar[(int)$_grp['fis_id']][] = $_grp;
            }
        } catch (PDOException $_ge) {}
    }

    // Yükleme kayıtları — tarih opsiyonel
    $yw = ["r.type='yukleme'"]; $yp = [];
    if ($f_from !== '' && $f_to !== '') { $yw[] = "r.tarih BETWEEN ? AND ?"; $yp[] = $f_from; $yp[] = $f_to; }
    elseif ($f_from !== '') { $yw[] = "r.tarih >= ?"; $yp[] = $f_from; }
    elseif ($f_to   !== '') { $yw[] = "r.tarih <= ?"; $yp[] = $f_to; }
    if ($f_firma !== '') { $yw[] = "r.firma = ?"; $yp[] = $f_firma; }
    if ($f_urun  !== '') { $yw[] = "r.urun = ?";  $yp[] = $f_urun;  }
    if ($f_depo  !== '') {
        $yw[] = "EXISTS (SELECT 1 FROM loading_pallets _p2 WHERE _p2.loading_record_id=r.id AND _p2.depo=?)";
        $yp[] = $f_depo;
    }
    $st = db()->prepare("
        SELECT r.id, r.tarih, r.firma, r.urun, r.parti_no, r.durum, r.on_plaka,
               (SELECT _p.depo FROM loading_pallets _p
                WHERE _p.loading_record_id=r.id AND _p.depo!='' ORDER BY _p.id LIMIT 1) AS depo,
               COUNT(p.id)                        AS palet_sayisi,
               COALESCE(SUM(p.kasa_adeti),0)       AS toplam_kasa,
               ROUND(COALESCE(SUM(p.brut_kg),0),3) AS toplam_brut,
               ROUND(COALESCE(SUM(p.dara_kg),0),3) AS toplam_dara,
               ROUND(COALESCE(SUM(p.net_kg),0),3)  AS toplam_net
        FROM loading_records r
        LEFT JOIN loading_pallets p ON p.loading_record_id=r.id
        WHERE " . implode(' AND ', $yw) . "
        GROUP BY r.id ORDER BY r.tarih DESC, r.id DESC LIMIT 500");
    $st->execute($yp);
    $yk_rows = $st->fetchAll();

    // Çıkma kayıtları — raporlanmamış, tarih opsiyonel
    // Kolon yoksa ekle (idempotent fallback — db.php migration çalışmamışsa)
    $cikma_rep_col = false;
    try {
        $cikma_rep_col = (bool)db()->query("SHOW COLUMNS FROM `loading_records` LIKE 'reported_at'")->fetchColumn();
        if (!$cikma_rep_col) {
            try {
                db()->exec("ALTER TABLE `loading_records`
                    ADD COLUMN `reported_at` DATETIME NULL,
                    ADD COLUMN `reported_by` INT NULL");
                $cikma_rep_col = true;
            } catch (PDOException $_me) {}
        }
    } catch (PDOException $_ce) {}
    $cw = ["r.type='cikma'"]; $cp = [];
    if ($cikma_rep_col) { $cw[] = "r.reported_at IS NULL"; }
    if ($f_firma !== '') { $cw[] = "r.firma = ?"; $cp[] = $f_firma; }
    if ($f_urun  !== '') { $cw[] = "r.urun = ?";  $cp[] = $f_urun;  }
    if ($f_depo  !== '') {
        $cw[] = "EXISTS (SELECT 1 FROM loading_pallets _p2 WHERE _p2.loading_record_id=r.id AND _p2.depo=?)";
        $cp[] = $f_depo;
    }
    if ($f_from !== '') { $cw[] = "r.tarih >= ?"; $cp[] = $f_from; }
    if ($f_to   !== '') { $cw[] = "r.tarih <= ?"; $cp[] = $f_to; }
    $st = db()->prepare("
        SELECT r.id, r.tarih, r.firma, r.urun, r.parti_no, r.durum, r.cikis_nedeni,
               (SELECT _p.depo FROM loading_pallets _p
                WHERE _p.loading_record_id=r.id AND _p.depo!='' ORDER BY _p.id LIMIT 1) AS depo,
               COUNT(p.id)                        AS palet_sayisi,
               COALESCE(SUM(p.kasa_adeti),0)       AS toplam_kasa,
               ROUND(COALESCE(SUM(p.brut_kg),0),3) AS toplam_brut,
               ROUND(COALESCE(SUM(p.dara_kg),0),3) AS toplam_dara,
               ROUND(COALESCE(SUM(p.net_kg),0),3)  AS toplam_net
        FROM loading_records r
        LEFT JOIN loading_pallets p ON p.loading_record_id=r.id
        WHERE " . implode(' AND ', $cw) . "
        GROUP BY r.id ORDER BY r.tarih DESC, r.id DESC LIMIT 500");
    $st->execute($cp);
    $ck_rows = $st->fetchAll();

    // Makineye Dökülen — kayıt düzeyi, Yükleme Raporu CASE WHEN p.islendi mantığı
    // Günlük'te varsayılan: işaretli
    if ($f_palet_islendi === '') $f_palet_islendi = 'hicbiri';
    if ($f_palet_islendi === 'isaretli') {
        $mk_agg_palet = "COALESCE(COUNT(CASE WHEN p.islendi=1 THEN 1 END),0)";
        $mk_agg_kasa  = "COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.kasa_adeti ELSE 0 END),0)";
        $mk_agg_brut  = "COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.brut_kg    ELSE 0 END),0)";
        $mk_agg_dara  = "COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.dara_kg    ELSE 0 END),0)";
        $mk_agg_net   = "COALESCE(SUM(CASE WHEN p.islendi=1 THEN p.net_kg     ELSE 0 END),0)";
        $mk_having    = " HAVING COUNT(CASE WHEN p.islendi=1 THEN 1 END) > 0";
    } elseif ($f_palet_islendi === 'hicbiri') {
        $mk_agg_palet = "COALESCE(COUNT(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN 1 END),0)";
        $mk_agg_kasa  = "COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.kasa_adeti ELSE 0 END),0)";
        $mk_agg_brut  = "COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.brut_kg    ELSE 0 END),0)";
        $mk_agg_dara  = "COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.dara_kg    ELSE 0 END),0)";
        $mk_agg_net   = "COALESCE(SUM(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN p.net_kg     ELSE 0 END),0)";
        $mk_having    = " HAVING COUNT(CASE WHEN (p.islendi IS NULL OR p.islendi=0) THEN 1 END) > 0";
    } else {
        $mk_agg_palet = "COUNT(p.id)";
        $mk_agg_kasa  = "COALESCE(SUM(p.kasa_adeti),0)";
        $mk_agg_brut  = "COALESCE(SUM(p.brut_kg),0)";
        $mk_agg_dara  = "COALESCE(SUM(p.dara_kg),0)";
        $mk_agg_net   = "COALESCE(SUM(p.net_kg),0)";
        $mk_having    = "";
    }
    $mk_rows = [];
    $mkw = ["r.type='yukleme'"]; $mkp = [];
    if ($f_from !== '' && $f_to !== '') { $mkw[] = "r.tarih BETWEEN ? AND ?"; $mkp[] = $f_from; $mkp[] = $f_to; }
    elseif ($f_from !== '') { $mkw[] = "r.tarih >= ?"; $mkp[] = $f_from; }
    elseif ($f_to   !== '') { $mkw[] = "r.tarih <= ?"; $mkp[] = $f_to; }
    if ($f_firma !== '') { $mkw[] = "r.firma = ?"; $mkp[] = $f_firma; }
    if ($f_urun  !== '') { $mkw[] = "r.urun = ?";  $mkp[] = $f_urun;  }
    if ($f_depo  !== '') {
        $mkw[] = "EXISTS (SELECT 1 FROM loading_pallets _p2 WHERE _p2.loading_record_id=r.id AND _p2.depo=?)";
        $mkp[] = $f_depo;
    }
    $st = db()->prepare("
        SELECT r.id, r.tarih, r.firma, r.bolge, r.alici, r.urun, r.parti_no, r.durum,
               (SELECT _p.depo FROM loading_pallets _p
                WHERE _p.loading_record_id=r.id AND _p.depo!='' ORDER BY _p.id LIMIT 1) AS depo,
               {$mk_agg_palet}               AS palet_sayisi,
               {$mk_agg_kasa}                AS toplam_kasa,
               ROUND({$mk_agg_brut},3)        AS toplam_brut,
               ROUND({$mk_agg_dara},3)        AS toplam_dara,
               ROUND({$mk_agg_net},3)         AS toplam_net
        FROM loading_records r
        LEFT JOIN loading_pallets p ON p.loading_record_id=r.id
        WHERE " . implode(' AND ', $mkw) . "
        GROUP BY r.id{$mk_having}
        ORDER BY r.tarih DESC, r.id DESC LIMIT 500");
    $st->execute($mkp);
    $mk_rows = $st->fetchAll();

    // ── Hazır Palet (Soğuk Hava — canlı sorgu, sadece Yazdır çıktısı için) ─
    // Kural: durum='yuklendi' + mevcut sayfadaki mk_rows hariç + firma/urun/depo filtresi
    // Tarih filtresi UYGULANMAZ (hazır palet güncel stok mantığıdır)
    $_gl_hazir = ['palet' => 0, 'kasa' => 0, 'brut' => 0.0, 'dara' => 0.0, 'net' => 0.0];
    try {
        $hw = ["lr.type='yukleme'", "lr.durum='yuklendi'"];
        $hp = [];
        $exclude_ids = array_values(array_filter(
            array_map('intval', array_column($mk_rows, 'id')),
            fn($v) => $v > 0
        ));
        if (!empty($exclude_ids)) {
            $hw[] = "lr.id NOT IN (" . implode(',', $exclude_ids) . ")";
        }
        if ($f_firma !== '') { $hw[] = "lr.firma=?"; $hp[] = $f_firma; }
        if ($f_urun  !== '') { $hw[] = "lr.urun=?";  $hp[] = $f_urun; }
        if ($f_depo  !== '') { $hw[] = "lp.depo=?";  $hp[] = $f_depo; }
        $st = db()->prepare("
            SELECT COUNT(DISTINCT lr.id) AS record_count,
                   COUNT(lp.id)          AS palet_count,
                   COALESCE(SUM(lp.kasa_adeti),0)       AS kasa_total,
                   ROUND(COALESCE(SUM(lp.brut_kg),0),3) AS brut_total,
                   ROUND(COALESCE(SUM(lp.dara_kg),0),3) AS dara_total,
                   ROUND(COALESCE(SUM(lp.net_kg),0),3)  AS net_total
            FROM loading_records lr
            JOIN loading_pallets lp ON lp.loading_record_id = lr.id
            WHERE " . implode(' AND ', $hw));
        $st->execute($hp);
        $hp_row = $st->fetch();
        if ($hp_row) {
            $_gl_hazir = [
                'palet' => (int)($hp_row['palet_count']  ?? 0),
                'kasa'  => (int)($hp_row['kasa_total']   ?? 0),
                'brut'  => (float)($hp_row['brut_total'] ?? 0),
                'dara'  => (float)($hp_row['dara_total'] ?? 0),
                'net'   => (float)($hp_row['net_total']  ?? 0),
            ];
        }
    } catch (PDOException $_he) {}

    // Özet
    $ozet_kantar_brut = 0.0; $ozet_kantar_dara = 0.0; $ozet_kantar_net = 0.0;
    foreach ($gk_rows as $_kf) {
        $_kc = kantar_calc($_kf);
        $ozet_kantar_brut += $_kc['brut'];
        $ozet_kantar_dara += $_kc['dara'];
        $ozet_kantar_net  += $_kc['net'];
    }
    $ozet_yukleme_net  = (float)array_sum(array_column($yk_rows, 'toplam_net'));
    $ozet_cikma_net    = (float)array_sum(array_column($ck_rows, 'toplam_net'));
    $ozet_palet        = (int)  array_sum(array_column($yk_rows, 'palet_sayisi'));
    $ozet_kasa         = (int)  array_sum(array_column($yk_rows, 'toplam_kasa'));
}

// ── CSV Export ──────────────────────────────────────────
if ($type !== '' && ($export === 'csv' || $export === 'csv_summary')) {
    audit_log_event('export', 'reports', null, null, ['type' => $type, 'export' => $export, 'from' => $f_from ?? '', 'to' => $f_to ?? '']);
    // Günlük Rapor CSV — bölümlü
    if ($type === 'gunluk') {
        $_gl_date_from = $f_from ?: date('Y-m-d');
        $gl_fname = 'gunluk_rapor_' . $_gl_date_from . ($f_to !== '' && $f_to !== $f_from ? '_'.$f_to : '') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $gl_fname . '"');
        header('Cache-Control: no-cache, must-revalidate');
        echo "\xEF\xBB\xBF";
        $gl_fp = fopen('php://output', 'w');
        // ÖZET
        fputcsv($gl_fp, ['BÖLÜM', 'ÖZET'], ';');
        $gl_tarih = ($f_from === '' && $f_to === '') ? 'Tüm dönem' : ($f_from === $f_to ? $f_from : $f_from . ' – ' . $f_to);
        fputcsv($gl_fp, ['Tarih', $gl_tarih], ';');
        fputcsv($gl_fp, ['Kantar Brüt KG',   str_replace('.', ',', number_format($ozet_kantar_brut, 3, '.', ''))], ';');
        fputcsv($gl_fp, ['Kantar Dara KG',   str_replace('.', ',', number_format($ozet_kantar_dara, 3, '.', ''))], ';');
        fputcsv($gl_fp, ['Kantar Net KG',    str_replace('.', ',', number_format($ozet_kantar_net,  3, '.', ''))], ';');
        fputcsv($gl_fp, ['Yükleme Net KG',   str_replace('.', ',', number_format($ozet_yukleme_net, 3, '.', ''))], ';');
        fputcsv($gl_fp, ['Çıkma Net KG',     str_replace('.', ',', number_format($ozet_cikma_net,   3, '.', ''))], ';');
        fputcsv($gl_fp, ['Yükleme Palet',    (string)$ozet_palet], ';');
        fputcsv($gl_fp, ['Yükleme Kasa',     (string)$ozet_kasa],  ';');
        fputcsv($gl_fp, [], ';');
        // KANTAR
        fputcsv($gl_fp, ['--- KANTAR GİRİŞLERİ ---'], ';');
        fputcsv($gl_fp, ['Tarih','Fiş No','Firma','Malın Cinsi','Plaka','Brüt KG','Dara KG','Net KG','Kasa','Palet'], ';');
        foreach ($gk_rows as $_gkr) {
            $_kc2  = kantar_calc($_gkr);
            $_fid2 = (int)$_gkr['id'];
            $_grps2 = $gk_gruplar[$_fid2] ?? [];
            if ($f_kantar_firma !== '' && !empty($_grps2)) {
                $_dist2 = kantar_grup_dist($_grps2, $_kc2['brut'], $_kc2['eff_kdu'], $_kc2['eff_pdu']);
                foreach ($_dist2 as $_dr2) {
                    if (mb_strtolower(trim((string)($_dr2['firma'] ?? '')), 'UTF-8') !== mb_strtolower(trim($f_kantar_firma), 'UTF-8')) continue;
                    fputcsv($gl_fp, [
                        $_gkr['giris_tarih'] ?? '',
                        $_gkr['fis_no']      ?? '',
                        $_dr2['firma'],
                        $_gkr['malin_cinsi'] ?? '',
                        $_gkr['plaka']       ?? '',
                        str_replace('.', ',', number_format($_dr2['brut_kg'], 3, '.', '')),
                        str_replace('.', ',', number_format($_dr2['dara_kg'], 3, '.', '')),
                        str_replace('.', ',', number_format($_dr2['net_kg'],  3, '.', '')),
                        (int)$_dr2['kasa'],
                        (int)$_dr2['palet'],
                    ], ';');
                }
            } else {
                fputcsv($gl_fp, [
                    $_gkr['giris_tarih'] ?? '',
                    $_gkr['fis_no']      ?? '',
                    $_gkr['firma_adi']   ?? '',
                    $_gkr['malin_cinsi'] ?? '',
                    $_gkr['plaka']       ?? '',
                    str_replace('.', ',', number_format($_kc2['brut'], 3, '.', '')),
                    str_replace('.', ',', number_format($_kc2['dara'], 3, '.', '')),
                    str_replace('.', ',', number_format($_kc2['net'],  3, '.', '')),
                    (int)$_gkr['kasa_sayisi'],
                    (int)$_gkr['palet_sayisi'],
                ], ';');
            }
        }
        fputcsv($gl_fp, [], ';');
        // YÜKLEME
        fputcsv($gl_fp, ['--- YÜKLEME KAYITLARI ---'], ';');
        fputcsv($gl_fp, ['Tarih','Firma','Ürün','Depo','Palet','Kasa','Brüt KG','Dara KG','Net KG','Durum'], ';');
        foreach ($yk_rows as $_ykr) {
            fputcsv($gl_fp, [
                $_ykr['tarih'],
                $_ykr['firma'],
                $_ykr['urun'],
                $_ykr['depo'] ?? '',
                (int)$_ykr['palet_sayisi'],
                (int)$_ykr['toplam_kasa'],
                str_replace('.', ',', number_format((float)$_ykr['toplam_brut'], 3, '.', '')),
                str_replace('.', ',', number_format((float)$_ykr['toplam_dara'], 3, '.', '')),
                str_replace('.', ',', number_format((float)$_ykr['toplam_net'],  3, '.', '')),
                $_ykr['durum'],
            ], ';');
        }
        fputcsv($gl_fp, [], ';');
        // ÇIKMA
        fputcsv($gl_fp, ['--- ÇIKMA KAYITLARI ---'], ';');
        fputcsv($gl_fp, ['Tarih','Firma','Ürün','Çıkış Nedeni','Depo','Palet','Kasa','Net KG','Durum'], ';');
        foreach ($ck_rows as $_ckr) {
            fputcsv($gl_fp, [
                $_ckr['tarih'],
                $_ckr['firma'],
                $_ckr['urun'],
                $_ckr['cikis_nedeni'] ?? '',
                $_ckr['depo'] ?? '',
                (int)$_ckr['palet_sayisi'],
                (int)$_ckr['toplam_kasa'],
                str_replace('.', ',', number_format((float)$_ckr['toplam_net'], 3, '.', '')),
                $_ckr['durum'],
            ], ';');
        }
        // MAKİNEYE DÖKÜLEN
        $_mk_label = match($f_palet_islendi) {
            'hicbiri' => '--- MAKİNEYE DÖKÜLEN ---',
            'isaretli' => '--- RAPORLANDI ---',
            default   => '--- YÜKLEME KAYITLARI (TÜMÜ) ---',
        };
        fputcsv($gl_fp, [$_mk_label], ';');
        fputcsv($gl_fp, ['Tarih','Firma','Bölge','Alıcı','Depo','Ürün','Parti No','Durum','Palet','Kasa','Brüt KG','Dara KG','Net KG'], ';');
        foreach ($mk_rows as $_mkr) {
            fputcsv($gl_fp, [
                $_mkr['tarih'],
                $_mkr['firma'],
                $_mkr['bolge']    ?? '',
                $_mkr['alici']    ?? '',
                $_mkr['depo']     ?? '',
                $_mkr['urun'],
                $_mkr['parti_no'] ?? '',
                $_mkr['durum']    ?? '',
                (int)$_mkr['palet_sayisi'],
                (int)$_mkr['toplam_kasa'],
                str_replace('.', ',', number_format((float)$_mkr['toplam_brut'], 3, '.', '')),
                str_replace('.', ',', number_format((float)$_mkr['toplam_dara'], 3, '.', '')),
                str_replace('.', ',', number_format((float)$_mkr['toplam_net'],  3, '.', '')),
            ], ';');
        }
        fclose($gl_fp);
        exit;
    }

    $filename = 'rapor_' . $type . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');

    // Yüklemeler / Çıkmalar → palet bazında tam detay (limit yok)
    if (in_array($type, ['yukleme', 'cikma'], true) && $export === 'csv') {
        $det_sql = "
            SELECT
                lr.id            AS kayit_id,
                lr.tarih,
                lr.firma,
                lr.bolge,
                lr.alici,
                lr.urun          AS kayit_urun,
                lr.parti_no,
                lr.cikis_nedeni,
                lr.on_plaka,
                lr.arka_plaka,
                lr.durum,
                lr.sofor_adi,
                lr.nakliye_sirketi,
                COALESCE(lr.nakliye_bedeli,0) AS nakliye_bedeli,
                COALESCE(lr.avans,0)          AS avans,
                COALESCE(lp.sira_no+1,'')     AS sira,
                COALESCE(lp.palet_no,'')      AS palet_no,
                COALESCE(lp.kasa_adeti,0)     AS kasa_adeti,
                COALESCE(mk.name,'')          AS kasa_cinsi,
                COALESCE(mp.name,'')          AS palet_tipi,
                COALESCE(lp.urun_cinsi,'')    AS palet_urun,
                COALESCE(lp.depo,'')          AS depo,
                COALESCE(lp.brut_kg,0)        AS brut_kg,
                COALESCE(lp.dara_kg,0)        AS dara_kg,
                COALESCE(lp.net_kg,0)         AS net_kg,
                GROUP_CONCAT(
                    CASE WHEN mmat.name IS NOT NULL
                    THEN CONCAT(mmat.name, ' x', CAST(pm.quantity AS CHAR))
                    END
                    ORDER BY pm.id SEPARATOR ' | '
                ) AS malzemeler,
                lr.created_at
            FROM loading_records lr
            LEFT JOIN loading_pallets lp ON lp.loading_record_id = lr.id
            LEFT JOIN material_definitions mk   ON mk.id   = lp.kasa_cinsi_id
            LEFT JOIN material_definitions mp   ON mp.id   = lp.palet_tipi_id
            LEFT JOIN pallet_materials pm       ON pm.loading_pallet_id = lp.id
            LEFT JOIN material_definitions mmat ON mmat.id = pm.material_id
            WHERE lr.type = :rtype";
        $det_p = [':rtype' => $type];
        if ($f_firma !== '') { $det_sql .= " AND lr.firma LIKE :firma"; $det_p[':firma'] = '%'.$f_firma.'%'; }
        if ($f_durum !== '') { $det_sql .= " AND lr.durum = :durum";    $det_p[':durum'] = $f_durum; }
        if ($f_urun  !== '') { $det_sql .= " AND lr.urun  LIKE :urun";  $det_p[':urun']  = '%'.$f_urun.'%'; }
        if ($f_bolge !== '') { $det_sql .= " AND lr.bolge LIKE :bolge"; $det_p[':bolge'] = '%'.$f_bolge.'%'; }
        if ($f_q     !== '') {
            $det_sql .= " AND (lr.firma LIKE :q OR lr.parti_no LIKE :q OR lr.alici LIKE :q OR lr.urun LIKE :q)";
            $det_p[':q'] = '%'.$f_q.'%';
        }
        rpt_date_filter($det_sql, $det_p, 'lr.tarih', $f_from, $f_to);
        $det_sql .= " GROUP BY lr.id, lp.id ORDER BY lr.tarih DESC, lr.id DESC, lp.sira_no ASC";

        $det_st = db()->prepare($det_sql);
        $det_st->execute($det_p);
        $det_rows = $det_st->fetchAll();

        $det_cols = [
            'kayit_id'         => 'Kayıt ID',
            'tarih'            => 'Tarih',
            'firma'            => 'Firma',
            'bolge'            => 'Bölge',
            'alici'            => 'Alıcı',
            'kayit_urun'       => 'Ürün (Kayıt)',
            ($type === 'cikma' ? 'cikis_nedeni' : 'parti_no') => ($type === 'cikma' ? 'Çıkma Nedeni' : 'Parti No'),
            'on_plaka'         => 'Ön Plaka',
            'arka_plaka'       => 'Arka Plaka',
            'durum'            => 'Durum',
            'sofor_adi'        => 'Şoför',
            'nakliye_sirketi'  => 'Nakliye Şirketi',
            'nakliye_bedeli'   => 'Nakliye Bedeli',
            'avans'            => 'Avans',
            'sira'             => 'Palet Sıra',
            'palet_no'         => 'Palet No',
            'kasa_adeti'       => 'Kasa Adeti',
            'kasa_cinsi'       => 'Kasa Cinsi',
            'palet_tipi'       => 'Palet Tipi',
            'palet_urun'       => 'Ürün Cinsi',
            'depo'             => 'Depo',
            'brut_kg'          => 'Brüt KG',
            'dara_kg'          => 'Dara KG',
            'net_kg'           => 'Net KG',
            'malzemeler'       => 'Malzemeler',
            'created_at'       => 'Oluşturulma',
        ];
        $float_cols = ['brut_kg','dara_kg','net_kg','nakliye_bedeli','avans'];

        fputcsv($fp, array_values($det_cols), ';');
        foreach ($det_rows as $r) {
            $line = [];
            foreach (array_keys($det_cols) as $c) {
                $v = $r[$c] ?? '';
                if (in_array($c, $float_cols, true))
                    $v = str_replace('.', ',', number_format((float)$v, 3, '.', ''));
                $line[] = $v;
            }
            fputcsv($fp, $line, ';');
        }
        fclose($fp);
        exit;
    }

    // Diğer rapor türleri → özet CSV (mevcut davranış)
    if (empty($rows)) { fclose($fp); exit; }
    fputcsv($fp, array_map('col_label', $cols), ';');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            if (in_array($c, ['toplam_brut','toplam_dara','toplam_net','toplam_dara_kg','net_kg','tartim1','tartim2','unit_dara_kg'], true)) {
                $v = str_replace('.', ',', (string)$v);
            }
            $line[] = $v;
        }
        fputcsv($fp, $line, ';');
    }
    if (!empty($totals)) {
        $total_line = array_fill(0, count($cols), '');
        $total_line[0] = 'TOPLAM';
        foreach ($cols as $i => $c) {
            if (isset($totals[$c])) $total_line[$i] = str_replace('.', ',', (string)round((float)$totals[$c], 3));
        }
        fputcsv($fp, $total_line, ';');
    }
    fclose($fp);
    exit;
}

// ── CSV URL helper ──────────────────────────────────────
$csv_params = array_filter([
    'type'           => $type,
    'export'         => 'csv',
    'date_from'      => $f_from,
    'date_to'        => $f_to,
    'firma'          => $f_firma,
    'durum'          => ($type !== 'cikma') ? $f_durum : '',
    'cikma_rapor'    => ($type === 'cikma') ? $f_cikma_rapor : '',
    'urun'           => $f_urun,
    'bolge'          => $f_bolge,
    'depo'           => $f_depo,
    'plaka'          => $f_plaka,
    'mat_type'       => $f_mtype,
    'q'              => $f_q,
    'sort'           => ($f_sort !== 'tarih') ? $f_sort : '',
    'palet_islendi'  => ($type !== 'cikma') ? $f_palet_islendi : '',
    'urun_sahibi'    => ($type === 'yukleme') ? $f_urun_sahibi : '',
    'casus'          => ($type === 'yukleme') ? $f_casus : '',
]);
$csv_url = 'reports.php?' . http_build_query($csv_params);
$csv_summary_params = $csv_params;
$csv_summary_params['export'] = 'csv_summary';
$csv_summary_url = 'reports.php?' . http_build_query($csv_summary_params);

$page_title = $type !== '' ? (($report_meta[$type]['icon'] ?? '') . ' ' . ($report_meta[$type]['label'] ?? '')) . ($type === 'gunluk' ? '' : ' Raporu') : 'Raporlar';
$filter_firma_list = db()->query("SELECT DISTINCT firma FROM loading_records WHERE firma!='' ORDER BY firma LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
$filter_kantar_firma_list = [];
try { $filter_kantar_firma_list = db()->query("SELECT DISTINCT grup_adi FROM kantar_gruplar WHERE grup_adi!='' ORDER BY grup_adi LIMIT 200")->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $_kfl) {}
$filter_urun_list     = db()->query("SELECT DISTINCT urun  FROM loading_records WHERE urun !='' ORDER BY urun  LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
$filter_bolge_list    = db()->query("SELECT DISTINCT bolge FROM loading_records WHERE bolge!='' ORDER BY bolge LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
$filter_depo_list     = db()->query("SELECT DISTINCT depo  FROM loading_pallets  WHERE depo !='' ORDER BY depo  LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
$filter_urun_def_list = db()->query("SELECT name FROM material_definitions WHERE type='urun' AND is_active=1 ORDER BY name LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
$filter_urun_sahibi_list = db()->query("SELECT id, name FROM material_definitions WHERE type='firma' AND is_active=1 ORDER BY name LIMIT 200")->fetchAll();
render_header($page_title);
render_flash();
?>

<style>
/* Rapor sayfası yazdırma — @page en üst seviyede olmalı */
@page { size: A4 landscape; margin: 8mm; }

@media print {
    /* Gizle */
    .topbar, .bottomnav,
    .rpt-head .rpt-actions,
    .rpt-filter-card,
    .rpt-no-print,
    #islendiRptWrap { display: none !important; }

    /* Tam genişlik */
    html, body { background: #fff !important; margin: 0 !important; }
    .container {
        padding: 0 !important;
        max-width: none !important;
        width: 100% !important;
        margin: 0 !important;
    }

    /* Özet kutu */
    .rpt-summary {
        border: 1px solid #ccc !important;
        margin: 0 0 8px !important;
        padding: 5px 8px !important;
        gap: 4px !important;
        flex-wrap: wrap;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .rpt-sum-item { padding: 3px 8px !important; min-width: 55px; }
    .rpt-sum-item span  { font-size: 6pt !important; }
    .rpt-sum-item strong{ font-size: 8pt !important; }
    .rpt-sum-highlight  { background: rgba(37,99,235,.08) !important; border-radius: 4px; }

    /* Başlık */
    .rpt-head { margin-bottom: 6px; }
    .rpt-title h1 { font-size: 11pt !important; margin: 0 !important; }
    .rpt-title p  { font-size: 8pt !important;  margin: 1px 0 !important; }

    /* Tablo sarıcı */
    .table-wrap {
        overflow: visible !important;
        border: 1px solid #ccc !important;
        border-radius: 0 !important;
        width: 100%;
    }

    /* Tablo genel */
    .data-table {
        font-size: 6.5pt !important;
        width: 100%;
        border-collapse: collapse !important;
        table-layout: auto;
    }
    .data-table th {
        font-size: 6pt !important;
        padding: 3px 4px !important;
        white-space: nowrap;
        background: #f0f0f0 !important;
        position: static !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .data-table td {
        padding: 3px 4px !important;
        white-space: nowrap;
        max-width: 120px;
        overflow: hidden;
        text-overflow: ellipsis;
        border-bottom: 1px solid #e8e8e8 !important;
    }
    .data-table tr:hover td { background: #fff !important; }
    .data-table tr.tr-islendi > td  { background: #fffde7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .data-table tr.tr-yuklendi > td { background: #f0fdf4 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    /* Toplam satırı */
    .totals-row td {
        background: #fffbcc !important;
        font-weight: 700 !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }

    /* Badge'ler */
    .rpt-badge {
        border: 1px solid #999 !important;
        padding: 1px 3px !important;
        border-radius: 2px !important;
        font-size: 6pt !important;
        background: #fff !important;
    }
    .badge-islendi  { border-color: #d97706 !important; color: #d97706 !important; }
    .badge-yuklendi { border-color: #16a34a !important; color: #16a34a !important; }
}
</style>

<?php if ($type === ''): ?>
<!-- ── Landing ───────────────────────────────────────── -->
<div class="page-head">
    <div>
        <h1>📊 Raporlar</h1>
        <p class="muted">Verilerinizi analiz edin ve dışa aktarın</p>
    </div>
    <a href="index.php" class="btn btn-ghost">← Ana Sayfa</a>
</div>

<div class="home-grid">
<?php foreach ($report_meta as $rtype => $m): ?>
    <a href="reports.php?type=<?= h($rtype) ?>" class="home-card">
        <div class="home-card-icon" style="background:<?= h($m['bg']) ?>"><?= $m['icon'] ?></div>
        <div class="home-card-title"><?= h($m['label']) ?></div>
    </a>
<?php endforeach; ?>

<?php if (can('kantar.read') || can('stok.read')): ?>
<div class="home-section-title">Stok &amp; Kantar</div>

<?php if (can('kantar.read')): ?>
    <a href="kantar_raporu.php" class="home-card">
        <div class="home-card-icon" style="background:#e8f5f0">📈</div>
        <div class="home-card-title">Kantar Raporu</div>
    </a>
<?php endif; ?>

<?php if (can('stok.read')): ?>
    <a href="stok.php" class="home-card">
        <div class="home-card-icon" style="background:#e8f5e9">📦</div>
        <div class="home-card-title">Ürün Stok</div>
    </a>
    <a href="malzeme_stok.php" class="home-card">
        <div class="home-card-icon" style="background:#e3f2fd">🧰</div>
        <div class="home-card-title">Malzeme Stok</div>
    </a>
<?php endif; ?>
<?php endif; ?>
</div>

<?php elseif ($type === 'gunluk'): ?>
<style>
@page { size: A4 landscape; margin: 8mm; }
@media print {
    .gl-no-print { display: none !important; }
    .gl-print-header { display: block !important; text-align: center; border-bottom: 2px solid #333; padding-bottom: 6px; margin-bottom: 10px; }
    .gl-print-header h2 { font-size: 13pt; margin: 0 0 2px; }
    .gl-print-header p  { font-size: 8pt; color: #555; margin: 0; }
    .gl-section { margin-bottom: 8mm; }
    .gl-section-title { font-size: 22pt; font-weight: 700; border-bottom: 1px solid #ccc; padding-bottom: 3px; margin-bottom: 6px; }
    .gl-ozet-strip { display: flex; flex-wrap: wrap; gap: 3px; margin-bottom: 6px; }
    .gl-ozet-card { border: 1px solid #ccc; padding: 3px 7px; flex: 1 1 80px; min-width: 70px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .gl-ozet-card span   { font-size: 6.5pt; display: block; color: #555; }
    .gl-ozet-card strong { font-size: 8pt; }
    .gl-mv-badge { border: 1px solid #999 !important; background: #fff !important; font-size: 6pt; padding: 0 2px; }
    html, body { background: #fff !important; }
    .container { max-width: none !important; padding: 0 !important; }
    .topbar, .bottomnav, .rpt-filter-card { display: none !important; }
    .gl-section .data-table { font-size: 12pt !important; }
    .gl-section .data-table th { font-size: 14pt !important; padding: 4px 6px !important; font-weight: 700; }
    .gl-section .data-table td { font-size: 12pt !important; padding: 4px 6px !important; }
    .gl-section .data-table tfoot { display: none !important; }
    .gl-ps-strip { display: flex !important; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .gl-ps-item { border: 2px solid; padding: 7px 12px; flex: 1 1 90px; min-width: 80px; page-break-inside: avoid; break-inside: avoid; -webkit-print-color-adjust: exact; print-color-adjust: exact; display: flex; flex-direction: row; align-items: center; gap: 8px; }
    .gl-ps-item span   { font-size: 12pt; font-weight: 700; white-space: nowrap; flex-shrink: 0; color: #000 !important; }
    .gl-ps-item strong { font-size: 16pt; font-weight: 800; }
    .gl-ps-palet { border-color: #7c3aed !important; color: #7c3aed !important; }
    .gl-ps-kasa  { border-color: #6b7280 !important; color: #6b7280 !important; }
    .gl-ps-brut  { border-color: #2563eb !important; color: #2563eb !important; }
    .gl-ps-dara  { border-color: #d97706 !important; color: #d97706 !important; }
    .gl-ps-net   { border-color: #059669 !important; color: #059669 !important; }
    /* Hazır Palet bölümü — sadece print'te görünür */
    .gl-hazir-section { display: block !important; margin-bottom: 8mm; }
    .gl-hazir-strip { display: flex !important; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .gl-hazir-title {
        font-size: 14pt; font-weight: 700; color: #dc2626 !important;
        border-bottom: 1.5px solid #dc2626 !important;
        padding-bottom: 3px; margin-bottom: 2px;
        break-after: avoid;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .gl-hazir-subtitle {
        font-size: 9pt; color: #dc2626 !important; font-style: italic; margin-bottom: 5px;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .gl-ps-hazir { border-color: #dc2626 !important; color: #dc2626 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
.gl-hazir-section { display: none; }
.gl-print-header { display: none; }
.gl-section { margin-bottom: 20px; }
.gl-section-title { font-size: .9rem; font-weight: 700; margin: 0 0 8px; border-bottom: 1px solid var(--border); padding-bottom: 4px; }
.gl-ozet-strip { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.gl-ozet-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 14px; flex: 1 1 120px; min-width: 100px; }
.gl-ozet-card span   { font-size: .72rem; color: var(--muted); display: block; margin-bottom: 2px; }
.gl-ozet-card strong { font-size: 1.05rem; font-weight: 700; }
.gl-ozet-card.gl-ozet-hi { background: rgba(37,99,235,.06); border-color: rgba(37,99,235,.2); }
.gl-mv-badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: .72rem; font-weight: 600; }
.gl-mv-giris    { background: #dcfce7; color: #166534; }
.gl-mv-sevk     { background: #fef9c3; color: #854d0e; }
.gl-mv-kullanim { background: #fff7ed; color: #9a3412; }
.gl-mv-duzeltme { background: #f0f9ff; color: #0c4a6e; }
.gl-section .data-table { font-size: .92rem; }
.gl-section .data-table th { font-weight: 700; padding: 7px 10px; }
.gl-section .data-table td { padding: 6px 10px; }
.gl-section .data-table tfoot td { font-size: .92rem; padding: 7px 10px; }
.gl-ps-strip { display: none; }
</style>

<?php
function fmt_date_tr_long_gl(string $d): string {
    static $months = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    static $days   = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    $ts = strtotime($d);
    if ($ts === false) return $d;
    return (int)date('j',$ts).' '.$months[(int)date('n',$ts)-1].' '.date('Y',$ts).' '.$days[(int)date('w',$ts)];
}
// Başlık ve PDF dosyası için referans tarih (filtre yoksa bugün)
$_gl_ref = $f_from !== '' ? $f_from : date('Y-m-d');
$_gl_date_long = fmt_date_tr_long_gl($_gl_ref);
$_gl_is_range  = ($f_from !== '' && $f_to !== '' && $f_from !== $f_to);
$_gl_tr_short  = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
$_gl_pdf_ts    = strtotime($_gl_ref);
$_gl_pdf_title = sprintf('%02d',(int)date('j',$_gl_pdf_ts)).$_gl_tr_short[(int)date('n',$_gl_pdf_ts)-1].'- Günlük Rapor';
?>
<script>document.title = <?= json_encode($_gl_pdf_title) ?>;</script>

<!-- Yazdırma başlığı (sadece print'te görünür) -->
<div class="gl-print-header">
    <h2>Günlük Rapor — <?php if ($_gl_is_range): ?><?= h(fmt_date($f_from)) ?> – <?= h(fmt_date($f_to)) ?><?php else: ?><?= h($_gl_date_long) ?><?php endif; ?></h2>
    <p><?php
        if ($f_from !== '' || $f_to !== '') {
            echo $f_from === $f_to ? h(fmt_date($f_from)) : h(fmt_date($f_from)) . ' – ' . h(fmt_date($f_to));
        }
    ?><?= $f_firma ? ($f_from !== '' || $f_to !== '' ? ' · ' : '') . h($f_firma) : '' ?><?= $f_depo ? ' · Depo: ' . h($f_depo) : '' ?></p>
</div>

<!-- Sayfa başlığı (ekran) -->
<div class="page-head rpt-head gl-no-print">
    <div class="rpt-title">
        <a href="reports.php" class="btn btn-ghost btn-sm">← Raporlar</a>
        <h1>📅 Günlük Rapor</h1>
        <p class="muted">
            <?php if ($f_from !== '' || $f_to !== ''): ?>
            <?= $f_from === $f_to ? h(fmt_date($f_from)) : h(fmt_date($f_from)) . ' – ' . h(fmt_date($f_to)) ?>
            &nbsp;·&nbsp;
            <?php endif; ?>
            <?= count($gk_rows) + count($ck_rows) + count($mk_rows) ?> kayıt
        </p>
    </div>
    <div class="rpt-actions">
        <?php
        $gl_csv_params = array_filter(['type'=>'gunluk','export'=>'csv',
            'date_from'=>$f_from,'date_to'=>$f_to,'firma'=>$f_firma,'depo'=>$f_depo,'urun'=>$f_urun,
            'palet_islendi'=>$f_palet_islendi,'kantar_firma'=>$f_kantar_firma]);
        $gl_csv_url = 'reports.php?' . http_build_query($gl_csv_params);
        ?>
        <a href="<?= h($gl_csv_url) ?>" class="btn btn-sm">⬇ Excel/CSV</a>
        <button onclick="window.print()" class="btn btn-sm">🖨 Yazdır</button>
        <a href="daily_report_archive.php" class="btn btn-sm">📁 Arşiv</a>
        <form method="post" action="daily_report_create.php" style="display:contents">
            <input type="hidden" name="csrf"          value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="report_type"   value="X">
            <input type="hidden" name="date_from"     value="<?= h($f_from) ?>">
            <input type="hidden" name="date_to"       value="<?= h($f_to) ?>">
            <input type="hidden" name="firma"         value="<?= h($f_firma) ?>">
            <input type="hidden" name="urun"          value="<?= h($f_urun) ?>">
            <input type="hidden" name="depo"          value="<?= h($f_depo) ?>">
            <input type="hidden" name="palet_islendi" value="<?= h($f_palet_islendi) ?>">
            <input type="hidden" name="kantar_firma"  value="<?= h($f_kantar_firma) ?>">
            <button type="submit" class="btn btn-sm btn-primary"
                    onclick="return confirm('Bu rapor X Raporu olarak arşivlenecek. Hiçbir kayıt kapatılmayacak. Devam edilsin mi?')">
                📋 X Raporu Al
            </button>
        </form>
        <form method="post" action="daily_report_create.php" style="display:contents">
            <input type="hidden" name="csrf"          value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="report_type"   value="Z">
            <input type="hidden" name="date_from"     value="<?= h($f_from) ?>">
            <input type="hidden" name="date_to"       value="<?= h($f_to) ?>">
            <input type="hidden" name="firma"         value="<?= h($f_firma) ?>">
            <input type="hidden" name="urun"          value="<?= h($f_urun) ?>">
            <input type="hidden" name="depo"          value="<?= h($f_depo) ?>">
            <input type="hidden" name="palet_islendi" value="hicbiri">
            <input type="hidden" name="kantar_firma"  value="<?= h($f_kantar_firma) ?>">
            <button type="submit" class="btn btn-sm btn-success"
                    onclick="return confirm('Bu rapordaki kantar fişleri, makineye dökülen paletler ve çıkma kayıtları raporlandı olarak kapatılacak. Bu işlem günlük açık listeden düşürür. Devam edilsin mi?')">
                🔒 Z Raporu Al ve Kapat
            </button>
        </form>
    </div>
</div>

<!-- Filtre formu -->
<div class="rpt-filter-card gl-no-print">
<form method="get" class="rpt-filter-form">
    <input type="hidden" name="type" value="gunluk">
    <div class="rpt-filter-group">
        <label>Başlangıç<input type="date" name="date_from" value="<?= h($f_from) ?>"></label>
        <label>Bitiş<input type="date" name="date_to" value="<?= h($f_to) ?>"></label>
    </div>
    <div class="rpt-filter-group">
        <label>Firma
            <select name="firma">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_firma_list as $_ff): ?>
                <option value="<?= h($_ff) ?>" <?= $f_firma===$_ff?'selected':'' ?>><?= h($_ff) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Depo
            <select name="depo">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_depo_list as $_fd): ?>
                <option value="<?= h($_fd) ?>" <?= $f_depo===$_fd?'selected':'' ?>><?= h($_fd) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ürün
            <select name="urun">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_urun_def_list as $_ud): ?>
                <option value="<?= h($_ud) ?>" <?= $f_urun===$_ud?'selected':'' ?>><?= h($_ud) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Yükleme Rapor Durumu
            <select name="palet_islendi">
                <option value="hicbiri"  <?= $f_palet_islendi==='hicbiri'  ?'selected':'' ?>>Makinaya Dökülen</option>
                <option value=""         <?= $f_palet_islendi===''          ?'selected':'' ?>>Tümü</option>
                <option value="isaretli" <?= $f_palet_islendi==='isaretli'  ?'selected':'' ?>>Raporlandı</option>
            </select>
        </label>
        <?php if (!empty($filter_kantar_firma_list)): ?>
        <label>Kantar Grubu
            <select name="kantar_firma">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_kantar_firma_list as $_kgf): ?>
                <option value="<?= h($_kgf) ?>" <?= $f_kantar_firma===$_kgf?'selected':'' ?>><?= h($_kgf) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
    </div>
    <div class="rpt-filter-actions">
        <button class="btn btn-primary btn-sm">Filtrele</button>
        <a href="reports.php?type=gunluk" class="btn btn-ghost btn-sm">Sıfırla</a>
    </div>
</form>
</div>

<!-- Kantar -->
<div class="gl-section">
    <div class="gl-section-title">Kantar<?= !empty($gk_rows) ? ' ('.count($gk_rows).')' : '' ?></div>
    <?php if (!empty($gk_rows)):
        $_gk_tot_brut = 0.0; $_gk_tot_net = 0.0; $_gk_tot_dara = 0.0; $_gk_tot_kasa = 0; $_gk_tot_palet = 0;
        // Pre-compute totals for summary strip
        foreach ($gk_rows as $_gkrp):
            $_kcp   = kantar_calc($_gkrp);
            $_fidp  = (int)$_gkrp['id'];
            $_grpsp = $gk_gruplar[$_fidp] ?? [];
            if ($f_kantar_firma !== '' && !empty($_grpsp)):
                $_distp = kantar_grup_dist($_grpsp, $_kcp['brut'], $_kcp['eff_kdu'], $_kcp['eff_pdu']);
                foreach ($_distp as $_drp):
                    if (mb_strtolower(trim((string)($_drp['firma'] ?? '')), 'UTF-8') !== mb_strtolower(trim($f_kantar_firma), 'UTF-8')) continue;
                    $_gk_tot_brut  += $_drp['brut_kg'];
                    $_gk_tot_dara  += $_drp['dara_kg'];
                    $_gk_tot_net   += $_drp['net_kg'];
                    $_gk_tot_kasa  += (int)$_drp['kasa'];
                    $_gk_tot_palet += (int)$_drp['palet'];
                endforeach;
            else:
                $_gk_tot_brut  += $_kcp['brut'];
                $_gk_tot_dara  += $_kcp['dara'];
                $_gk_tot_net   += $_kcp['net'];
                $_gk_tot_kasa  += (int)$_gkrp['kasa_sayisi'];
                $_gk_tot_palet += (int)$_gkrp['palet_sayisi'];
            endif;
        endforeach;
    ?>
    <div class="gl-ps-strip">
        <div class="gl-ps-item gl-ps-palet"><span>Palet</span><strong><?= number_format($_gk_tot_palet, 0, ',', '.') ?></strong></div>
        <div class="gl-ps-item gl-ps-kasa"><span>Kasa</span><strong><?= number_format($_gk_tot_kasa, 0, ',', '.') ?></strong></div>
        <div class="gl-ps-item gl-ps-brut"><span>Brüt KG</span><strong><?= fmt_kg($_gk_tot_brut) ?></strong></div>
        <div class="gl-ps-item gl-ps-dara"><span>Dara KG</span><strong><?= fmt_kg(round($_gk_tot_dara)) ?></strong></div>
        <div class="gl-ps-item gl-ps-net"><span>Net KG</span><strong><?= fmt_kg($_gk_tot_net) ?></strong></div>
    </div>
    <?php
        // Reset for in-loop accumulation (used by tfoot)
        $_gk_tot_brut = 0.0; $_gk_tot_net = 0.0; $_gk_tot_dara = 0.0; $_gk_tot_kasa = 0; $_gk_tot_palet = 0;
    ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead><tr>
            <th>Fiş No</th><th>Plaka</th><th>Firma</th>
            <th class="num">Palet</th><th class="num">Kasa</th>
            <th class="num">Brüt KG</th><th class="num">Dara KG</th><th class="num">Net KG</th>
            <th class="gl-no-print">Bağlantı</th>
        </tr></thead>
        <tbody>
        <?php foreach ($gk_rows as $_gkr):
            $_kc   = kantar_calc($_gkr);
            $_fid  = (int)$_gkr['id'];
            $_grps = $gk_gruplar[$_fid] ?? [];
            if ($f_kantar_firma !== '' && !empty($_grps)):
                // Kantar grubu filtresi aktif → dağıtılmış gruba göre göster
                $_dist = kantar_grup_dist($_grps, $_kc['brut'], $_kc['eff_kdu'], $_kc['eff_pdu']);
                foreach ($_dist as $_dr):
                    if (mb_strtolower(trim((string)($_dr['firma'] ?? '')), 'UTF-8') !== mb_strtolower(trim($f_kantar_firma), 'UTF-8')) continue;
                    $_gk_tot_brut  += $_dr['brut_kg'];
                    $_gk_tot_net   += $_dr['net_kg'];
                    $_gk_tot_kasa  += (int)$_dr['kasa'];
                    $_gk_tot_palet += (int)$_dr['palet'];
        ?>
            <tr>
                <td><?= h($_gkr['fis_no'] ?? '—') ?></td>
                <td><?= h($_gkr['plaka'] ?? '—') ?></td>
                <td><?= h($_dr['firma']) ?></td>
                <td class="num"><?= (int)$_dr['palet'] ?></td>
                <td class="num"><?= number_format((int)$_dr['kasa'], 0, ',', '.') ?></td>
                <td class="num"><?= fmt_kg($_dr['brut_kg']) ?></td>
                <td class="num"><?= fmt_kg($_dr['dara_kg']) ?></td>
                <td class="num"><strong><?= fmt_kg($_dr['net_kg']) ?></strong></td>
                <td class="gl-no-print"><a href="kantar_view.php?id=<?= $_fid ?>" class="btn btn-sm">Görüntüle</a></td>
            </tr>
        <?php     endforeach;
            else:
                // Firma filtresi yok veya grup yok → fiş düzeyinde göster
                $_gk_tot_brut  += $_kc['brut'];
                $_gk_tot_net   += $_kc['net'];
                $_gk_tot_kasa  += (int)$_gkr['kasa_sayisi'];
                $_gk_tot_palet += (int)$_gkr['palet_sayisi'];
        ?>
            <tr>
                <td><?= h($_gkr['fis_no'] ?? '—') ?></td>
                <td><?= h($_gkr['plaka'] ?? '—') ?></td>
                <td><?= h($_gkr['firma_adi'] ?: '—') ?></td>
                <td class="num"><?= (int)$_gkr['palet_sayisi'] ?></td>
                <td class="num"><?= number_format((int)$_gkr['kasa_sayisi'], 0, ',', '.') ?></td>
                <td class="num"><?= fmt_kg($_kc['brut']) ?></td>
                <td class="num"><?= fmt_kg($_kc['dara']) ?></td>
                <td class="num"><strong><?= fmt_kg($_kc['net']) ?></strong></td>
                <td class="gl-no-print"><a href="kantar_view.php?id=<?= $_fid ?>" class="btn btn-sm">Görüntüle</a></td>
            </tr>
        <?php endif; endforeach; ?>
        </tbody>
        <tfoot><tr class="totals-row">
            <td colspan="3"><strong>TOPLAM</strong></td>
            <td class="num"><strong><?= $_gk_tot_palet ?></strong></td>
            <td class="num"><strong><?= number_format($_gk_tot_kasa, 0, ',', '.') ?></strong></td>
            <td class="num"><strong><?= fmt_kg($_gk_tot_brut) ?></strong></td>
            <td></td>
            <td class="num"><strong><?= fmt_kg($_gk_tot_net) ?></strong></td>
            <td class="gl-no-print"></td>
        </tr></tfoot>
    </table>
    </div>
    <?php else: ?>
    <p class="muted">İşlem yok</p>
    <?php endif; ?>
</div>

<!-- Makineye Dökülen -->
<?php
$_mk_tot_palet = (int)  array_sum(array_column($mk_rows,'palet_sayisi'));
$_mk_tot_kasa  = (int)  array_sum(array_column($mk_rows,'toplam_kasa'));
$_mk_tot_brut  = (float)array_sum(array_column($mk_rows,'toplam_brut'));
$_mk_tot_dara  = (float)array_sum(array_column($mk_rows,'toplam_dara'));
$_mk_tot_net   = (float)array_sum(array_column($mk_rows,'toplam_net'));
?>
<div class="gl-section">
    <div class="gl-section-title">Makineye Dökülen<?= !empty($mk_rows) ? ' ('.count($mk_rows).' kayıt)' : '' ?></div>
    <?php if (!empty($mk_rows)): ?>
    <div class="rpt-summary gl-no-print" style="margin-bottom:10px">
        <div class="rpt-sum-item"><span>Kayıt</span><strong><?= count($mk_rows) ?></strong></div>
        <div class="rpt-sum-item"><span>Palet</span><strong><?= number_format($_mk_tot_palet, 0, ',', '.') ?></strong></div>
        <div class="rpt-sum-item"><span>Kasa</span><strong><?= number_format($_mk_tot_kasa, 0, ',', '.') ?></strong></div>
        <div class="rpt-sum-item"><span>Brüt KG</span><strong><?= fmt_kg($_mk_tot_brut) ?></strong></div>
        <div class="rpt-sum-item rpt-sum-highlight"><span>Net KG</span><strong><?= fmt_kg(round($_mk_tot_net)) ?></strong></div>
    </div>
    <div class="gl-ps-strip">
        <div class="gl-ps-item gl-ps-palet"><span>Palet</span><strong><?= number_format($_mk_tot_palet, 0, ',', '.') ?></strong></div>
        <div class="gl-ps-item gl-ps-kasa"><span>Kasa</span><strong><?= number_format($_mk_tot_kasa, 0, ',', '.') ?></strong></div>
        <div class="gl-ps-item gl-ps-brut"><span>Brüt KG</span><strong><?= fmt_kg($_mk_tot_brut) ?></strong></div>
        <div class="gl-ps-item gl-ps-dara"><span>Dara KG</span><strong><?= fmt_kg(round($_mk_tot_dara)) ?></strong></div>
        <div class="gl-ps-item gl-ps-net"><span>Net KG</span><strong><?= fmt_kg(round($_mk_tot_net)) ?></strong></div>
    </div>
    <div class="table-wrap">
    <table class="data-table">
        <thead><tr>
            <th>Firma</th><th>Ürün</th><th>Parti No</th>
            <th class="num">Palet</th><th class="num">Kasa</th>
            <th class="num">Brüt KG</th><th class="num">Dara KG</th><th class="num">Net KG</th>
            <th class="gl-no-print">Bağlantı</th>
        </tr></thead>
        <tbody>
        <?php foreach ($mk_rows as $_mkr): ?>
        <tr>
            <td><?= h($_mkr['firma']   ?: '—') ?></td>
            <td><?= h($_mkr['urun']    ?: '—') ?></td>
            <td><?= h($_mkr['parti_no']?: '—') ?></td>
            <td class="num"><?= number_format((int)$_mkr['palet_sayisi'], 0, ',', '.') ?></td>
            <td class="num"><?= number_format((int)$_mkr['toplam_kasa'],  0, ',', '.') ?></td>
            <td class="num"><?= fmt_kg((float)$_mkr['toplam_brut']) ?></td>
            <td class="num"><?= fmt_kg(round((float)$_mkr['toplam_dara'])) ?></td>
            <td class="num"><strong><?= fmt_kg(round((float)$_mkr['toplam_net'])) ?></strong></td>
            <td class="gl-no-print"><a href="record_view.php?id=<?= (int)$_mkr['id'] ?>" class="btn btn-sm">Görüntüle</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="totals-row">
            <td colspan="3"><strong>TOPLAM</strong></td>
            <td class="num"><strong><?= number_format($_mk_tot_palet, 0, ',', '.') ?></strong></td>
            <td class="num"><strong><?= number_format($_mk_tot_kasa,  0, ',', '.') ?></strong></td>
            <td class="num"><strong><?= fmt_kg($_mk_tot_brut) ?></strong></td>
            <td class="num"><strong><?= fmt_kg(round($_mk_tot_dara)) ?></strong></td>
            <td class="num"><strong><?= fmt_kg(round($_mk_tot_net)) ?></strong></td>
            <td class="gl-no-print"></td>
        </tr></tfoot>
    </table>
    </div>
    <?php else: ?>
    <p class="muted">İşlem yok</p>
    <?php endif; ?>
</div>

<!-- Hazır Palet (sadece window.print() çıktısında görünür, @media print) -->
<div class="gl-hazir-section">
    <div class="gl-hazir-title">Soğuk Havada Hazır Palet</div>
    <div class="gl-hazir-subtitle">(Bugün makineye dökülen hariç, soğuk havada hazır palet adeti — tarih filtresi uygulanmaz)</div>
    <div class="gl-ps-strip gl-hazir-strip">
        <?php if ($_gl_hazir['palet'] === 0): ?>
        <span style="color:#dc2626;font-style:italic;font-size:12pt;">Hazır palet kaydı bulunamadı</span>
        <?php else: ?>
        <div class="gl-ps-item gl-ps-hazir"><span>Palet</span><strong><?= number_format($_gl_hazir['palet'], 0, ',', '.') ?></strong></div>
        <?php if ($_gl_hazir['kasa'] > 0): ?>
        <div class="gl-ps-item gl-ps-kasa"><span>Kasa</span><strong><?= number_format($_gl_hazir['kasa'], 0, ',', '.') ?></strong></div>
        <?php endif; ?>
        <div class="gl-ps-item gl-ps-brut"><span>Brüt KG</span><strong><?= fmt_kg($_gl_hazir['brut']) ?></strong></div>
        <?php if ($_gl_hazir['dara'] > 0): ?>
        <div class="gl-ps-item gl-ps-dara"><span>Dara KG</span><strong><?= fmt_kg(round($_gl_hazir['dara'])) ?></strong></div>
        <?php endif; ?>
        <div class="gl-ps-item gl-ps-net"><span>Net KG</span><strong><?= fmt_kg(round($_gl_hazir['net'])) ?></strong></div>
        <?php endif; ?>
    </div>
</div>

<!-- Çıkmalar -->
<div class="gl-section">
    <div class="gl-section-title">Çıkmalar<?= !empty($ck_rows) ? ' ('.count($ck_rows).')' : '' ?></div>
    <?php if (!empty($ck_rows)):
        $_ck_tot_palet = (int)  array_sum(array_column($ck_rows, 'palet_sayisi'));
        $_ck_tot_kasa  = (int)  array_sum(array_column($ck_rows, 'toplam_kasa'));
        $_ck_tot_brut  = (float)array_sum(array_column($ck_rows, 'toplam_brut'));
        $_ck_tot_dara  = (float)array_sum(array_column($ck_rows, 'toplam_dara'));
        $_ck_tot_net   = (float)array_sum(array_column($ck_rows, 'toplam_net'));
    ?>
    <div class="gl-ps-strip">
        <div class="gl-ps-item gl-ps-palet"><span>Palet</span><strong><?= number_format($_ck_tot_palet, 0, ',', '.') ?></strong></div>
        <div class="gl-ps-item gl-ps-kasa"><span>Kasa</span><strong><?= number_format($_ck_tot_kasa, 0, ',', '.') ?></strong></div>
        <div class="gl-ps-item gl-ps-brut"><span>Brüt KG</span><strong><?= fmt_kg($_ck_tot_brut) ?></strong></div>
        <div class="gl-ps-item gl-ps-dara"><span>Dara KG</span><strong><?= fmt_kg(round($_ck_tot_dara)) ?></strong></div>
        <div class="gl-ps-item gl-ps-net"><span>Net KG</span><strong><?= fmt_kg($_ck_tot_net) ?></strong></div>
    </div>
    <?php endif; if (!empty($ck_rows)): ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead><tr>
            <th>Firma</th><th>Ürün</th><th>Çıkma Nedeni</th>
            <th class="num">Palet</th><th class="num">Kasa</th>
            <th class="num">Brüt KG</th><th class="num">Dara KG</th><th class="num">Net KG</th>
            <th class="gl-no-print">Bağlantı</th>
        </tr></thead>
        <tbody>
        <?php foreach ($ck_rows as $_ckr): ?>
        <tr>
            <td><?= h($_ckr['firma'] ?: '—') ?></td>
            <td><?= h($_ckr['urun']  ?: '—') ?></td>
            <td><?php $_cn = trim($_ckr['cikis_nedeni'] ?? ''); echo $_cn !== '' ? '<span class="cikis-nedeni-badge">' . h($_cn) . '</span>' : '—'; ?></td>
            <td class="num"><?= (int)$_ckr['palet_sayisi'] ?></td>
            <td class="num"><?= number_format((int)$_ckr['toplam_kasa'], 0, ',', '.') ?></td>
            <td class="num"><?= fmt_kg((float)$_ckr['toplam_brut']) ?></td>
            <td class="num"><?= fmt_kg(round((float)$_ckr['toplam_dara'])) ?></td>
            <td class="num"><strong><?= fmt_kg(round((float)$_ckr['toplam_net'])) ?></strong></td>
            <td class="gl-no-print"><a href="record_view.php?id=<?= (int)$_ckr['id'] ?>" class="btn btn-sm">Görüntüle</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="totals-row">
            <td colspan="3"><strong>TOPLAM</strong></td>
            <td class="num"><strong><?= number_format((int)array_sum(array_column($ck_rows,'palet_sayisi')), 0, ',', '.') ?></strong></td>
            <td class="num"><strong><?= number_format((int)array_sum(array_column($ck_rows,'toplam_kasa')),  0, ',', '.') ?></strong></td>
            <td class="num"><strong><?= fmt_kg((float)array_sum(array_column($ck_rows,'toplam_brut'))) ?></strong></td>
            <td class="num"><strong><?= fmt_kg(round((float)array_sum(array_column($ck_rows,'toplam_dara')))) ?></strong></td>
            <td class="num"><strong><?= fmt_kg((float)array_sum(array_column($ck_rows,'toplam_net'))) ?></strong></td>
            <td class="gl-no-print"></td>
        </tr></tfoot>
    </table>
    </div>
    <?php else: ?>
    <p class="muted">İşlem yok</p>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ── Rapor Sayfası ─────────────────────────────────── -->
<?php $meta = $report_meta[$type]; ?>

<div class="page-head rpt-head">
    <div class="rpt-title">
        <a href="reports.php" class="btn btn-ghost btn-sm rpt-no-print">← Raporlar</a>
        <h1><?= $meta['icon'] ?> <?= h($meta['label']) ?> Raporu</h1>
        <p class="muted"><?= count($rows) ?> kayıt</p>
    </div>
    <div class="rpt-actions rpt-no-print">
        <button onclick="window.print()" class="btn btn-sm">🖨 Yazdır</button>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
        <a href="<?= h($csv_summary_url) ?>" class="btn btn-sm">⬇ Özet Excel</a>
        <a href="<?= h($csv_url) ?>" class="btn btn-sm btn-primary">⬇ Detay Excel</a>
        <?php if ($type === 'yukleme'): ?>
        <?php
        $_bulk_params = array_filter([
            'date_from'     => $f_from,
            'date_to'       => $f_to,
            'firma'         => $f_firma,
            'urun'          => $f_urun,
            'bolge'         => $f_bolge,
            'depo'          => $f_depo,
            'durum'         => $f_durum,
            'q'             => $f_q,
            'palet_islendi' => $f_palet_islendi,
            'urun_sahibi'   => $f_urun_sahibi,
            'casus'         => $f_casus,
            'sort'          => ($f_sort !== 'tarih' ? $f_sort : ''),
        ]);
        $_bulk_url = 'records_bulk_print.php?' . http_build_query($_bulk_params);
        ?>
        <a href="<?= h($_bulk_url) ?>" class="btn btn-sm" target="_blank" title="Filtreli tüm yüklemeleri tek PDF'te aç (max 50)">📄 Toplu PDF</a>
        <?php endif; ?>
        <?php else: ?>
        <a href="<?= h($csv_url) ?>" class="btn btn-sm btn-primary">⬇ Excel/CSV</a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Filtre Formu ── -->
<div class="rpt-filter-card rpt-no-print">
<form method="get" class="rpt-filter-form">
    <input type="hidden" name="type" value="<?= h($type) ?>">

    <?php if (in_array($type, ['yukleme','cikma','depo','urun','firma','kantar'], true)): ?>
    <div class="rpt-filter-group">
        <label>Başlangıç<input type="date" name="date_from" value="<?= h($f_from) ?>"></label>
        <label>Bitiş<input type="date" name="date_to" value="<?= h($f_to) ?>"></label>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['yukleme','cikma','firma','urun'], true)): ?>
    <div class="rpt-filter-group">
        <label>Firma
            <select name="firma">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_firma_list as $_f): ?>
                <option value="<?= h($_f) ?>" <?= $f_firma===$_f?'selected':'' ?>><?= h($_f) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
        <label>Ürün
            <select name="urun">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_urun_list as $_u): ?>
                <option value="<?= h($_u) ?>" <?= $f_urun===$_u?'selected':'' ?>><?= h($_u) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
    <div class="rpt-filter-group">
        <label>Bölge
            <select name="bolge">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_bolge_list as $_b): ?>
                <option value="<?= h($_b) ?>" <?= $f_bolge===$_b?'selected':'' ?>><?= h($_b) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Depo
            <select name="depo">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_depo_list as $_d): ?>
                <option value="<?= h($_d) ?>" <?= $f_depo===$_d?'selected':'' ?>><?= h($_d) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <?php if ($type === 'yukleme'): ?>
    <div class="rpt-filter-group">
        <label>Kayıt Durumu
            <select name="durum">
                <option value="" <?= $f_durum==='' ? 'selected':'' ?>>Tümü</option>
                <option value="islendi"  <?= $f_durum==='islendi' ? 'selected':'' ?>>İşlendi</option>
                <option value="yuklendi" <?= $f_durum==='yuklendi'? 'selected':'' ?>>Yüklendi</option>
            </select>
        </label>
        <label>Yükleme Rapor Durumu
            <select name="palet_islendi">
                <option value=""         <?= $f_palet_islendi===''         ? 'selected':'' ?>>Tümü</option>
                <option value="isaretli" <?= $f_palet_islendi==='isaretli' ? 'selected':'' ?>>Raporlandı</option>
                <option value="hicbiri"  <?= $f_palet_islendi==='hicbiri'  ? 'selected':'' ?>>Makinaya Dökülen</option>
            </select>
        </label>
        <?php if (!empty($filter_urun_sahibi_list)): ?>
        <label>Ürün Sahibi
            <select name="urun_sahibi">
                <option value="">-- Tümü --</option>
                <option value="0" <?= $f_urun_sahibi==='0' ? 'selected' : '' ?>>Asya Fresh (Bizim)</option>
                <?php foreach ($filter_urun_sahibi_list as $_us): ?>
                <option value="<?= (int)$_us['id'] ?>" <?= $f_urun_sahibi===(string)(int)$_us['id'] ? 'selected' : '' ?>>
                    <?= h($_us['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label>Casus No
            <select name="casus">
                <option value=""    <?= $f_casus===''    ? 'selected':'' ?>>Tümü</option>
                <option value="dolu" <?= $f_casus==='dolu' ? 'selected':'' ?>>Yazılı</option>
                <option value="bos"  <?= $f_casus==='bos'  ? 'selected':'' ?>>Boş</option>
            </select>
        </label>
    </div>
    <?php elseif ($type === 'cikma'): ?>
    <div class="rpt-filter-group">
        <label>Rapor Durumu
            <select name="cikma_rapor">
                <option value=""              <?= $f_cikma_rapor===''              ? 'selected':'' ?>>Tümü</option>
                <option value="raporlanmamis" <?= $f_cikma_rapor==='raporlanmamis' ? 'selected':'' ?>>Raporlanmadı</option>
                <option value="raporlandi"    <?= $f_cikma_rapor==='raporlandi'    ? 'selected':'' ?>>Raporlandı</option>
            </select>
        </label>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($type === 'depo'): ?>
    <div class="rpt-filter-group">
        <label>Depo
            <select name="depo">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_depo_list as $_d): ?>
                <option value="<?= h($_d) ?>" <?= $f_depo===$_d?'selected':'' ?>><?= h($_d) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Firma
            <select name="firma">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_firma_list as $_f): ?>
                <option value="<?= h($_f) ?>" <?= $f_firma===$_f?'selected':'' ?>><?= h($_f) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <?php endif; ?>

    <?php if ($type === 'kantar'): ?>
    <div class="rpt-filter-group">
        <label>Firma
            <select name="firma">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_firma_list as $_f): ?>
                <option value="<?= h($_f) ?>" <?= $f_firma===$_f?'selected':'' ?>><?= h($_f) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Plaka<input type="text" name="plaka" value="<?= h($f_plaka) ?>" placeholder="Plaka..."></label>
    </div>
    <?php endif; ?>

    <?php if ($type === 'malzeme'): ?>
    <div class="rpt-filter-group">
        <?php $type_labels = definition_types(); ?>
        <label>Tür
            <select name="mat_type">
                <option value="">Tüm Türler</option>
                <?php foreach ($type_labels as $tk => $tl): if (in_array($tk,['firma','depo','urun'],true)) continue; ?>
                <option value="<?= h($tk) ?>" <?= $f_mtype===$tk?'selected':'' ?>><?= h($tl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ara<input type="text" name="q" value="<?= h($f_q) ?>" placeholder="Malzeme adı..."></label>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['yukleme','cikma','kantar'], true)): ?>
    <div class="rpt-filter-group">
        <label class="rpt-search-label">Genel Arama<input type="text" name="q" value="<?= h($f_q) ?>" placeholder="Firma, parti, alıcı..."></label>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
        <label>Sıralama
            <select name="sort">
                <option value="tarih" <?= $f_sort==='tarih'?'selected':'' ?>>Tarih</option>
                <option value="firma" <?= $f_sort==='firma'?'selected':'' ?>>Firma</option>
                <option value="durum" <?= $f_sort==='durum'?'selected':'' ?>>Durum</option>
                <option value="palet" <?= $f_sort==='palet'?'selected':'' ?>>Palet Sayısı</option>
                <option value="net"   <?= $f_sort==='net'  ?'selected':'' ?>>Net KG</option>
            </select>
        </label>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="rpt-filter-actions">
        <button class="btn btn-primary btn-sm">Filtrele</button>
        <a href="reports.php?type=<?= h($type) ?>" class="btn btn-ghost btn-sm">Sıfırla</a>
    </div>
</form>
</div>

<!-- ── Özet ── -->
<?php if (!empty($totals)): ?>
<div class="rpt-summary">
    <div class="rpt-sum-item"><span>Kayıt</span><strong><?= count($rows) ?></strong></div>
    <?php if (isset($totals['palet_sayisi'])): ?>
    <div class="rpt-sum-item"><span>Palet</span><strong><?= number_format((int)$totals['palet_sayisi'], 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['toplam_kasa'])): ?>
    <div class="rpt-sum-item"><span>Kasa</span><strong><?= number_format((int)$totals['toplam_kasa'], 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['toplam_brut'])): ?>
    <div class="rpt-sum-item"><span>Brüt KG</span><strong><?= fmt_kg($totals['toplam_brut']) ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['toplam_net'])): ?>
    <div class="rpt-sum-item rpt-sum-highlight"><span>Net KG</span><strong><?= fmt_kg($totals['toplam_net']) ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['net_kg'])): ?>
    <div class="rpt-sum-item rpt-sum-highlight"><span>Net KG</span><strong><?= fmt_kg($totals['net_kg']) ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['toplam_dara_kg'])): ?>
    <div class="rpt-sum-item"><span>Toplam Dara</span><strong><?= fmt_kg($totals['toplam_dara_kg']) ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['nakliye_bedeli']) && $totals['nakliye_bedeli'] > 0): ?>
    <div class="rpt-sum-item"><span>Nakliye Bedeli</span><strong><?= fmt_money($totals['nakliye_bedeli']) ?></strong></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($islendi_totals) && in_array($type, ['yukleme','cikma'], true)): ?>
<div id="islendiRptWrap" style="margin-top:6px">
    <button type="button" id="islendiRptToggle" class="islendi-ozet-toggle rpt-no-print">▸ Raporlandı / Raporlanmadı Palet Ayrımı</button>
    <div id="islendiRptPanel" class="islendi-ozet-panel" style="display:none">
        <div class="islendi-ozet-grid">
            <div class="islendi-ozet-section islendi-ozet-done">
                <div class="islendi-ozet-head">✓ Raporlandı Paletler</div>
                <div class="islendi-ozet-row"><span>Palet</span><strong><?= number_format((int)$islendi_totals['is_palet'],0,',','.') ?></strong></div>
                <div class="islendi-ozet-row"><span>Kasa</span><strong><?= number_format((int)$islendi_totals['is_kasa'],0,',','.') ?></strong></div>
                <div class="islendi-ozet-row"><span>Brüt KG</span><strong><?= fmt_kg((float)$islendi_totals['is_brut']) ?></strong></div>
                <div class="islendi-ozet-row"><span>Dara KG</span><strong><?= fmt_kg(round((float)$islendi_totals['is_dara'])) ?></strong></div>
                <div class="islendi-ozet-row"><span>Net KG</span><strong><?= fmt_kg(round((float)$islendi_totals['is_net'])) ?></strong></div>
            </div>
            <div class="islendi-ozet-section islendi-ozet-pending">
                <div class="islendi-ozet-head">○ Raporlanmadı Paletler</div>
                <div class="islendi-ozet-row"><span>Palet</span><strong><?= number_format((int)$islendi_totals['nis_palet'],0,',','.') ?></strong></div>
                <div class="islendi-ozet-row"><span>Kasa</span><strong><?= number_format((int)$islendi_totals['nis_kasa'],0,',','.') ?></strong></div>
                <div class="islendi-ozet-row"><span>Brüt KG</span><strong><?= fmt_kg((float)$islendi_totals['nis_brut']) ?></strong></div>
                <div class="islendi-ozet-row"><span>Dara KG</span><strong><?= fmt_kg(round((float)$islendi_totals['nis_dara'])) ?></strong></div>
                <div class="islendi-ozet-row"><span>Net KG</span><strong><?= fmt_kg(round((float)$islendi_totals['nis_net'])) ?></strong></div>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    var btn=document.getElementById('islendiRptToggle');
    var panel=document.getElementById('islendiRptPanel');
    if(!btn||!panel)return;
    btn.addEventListener('click',function(){
        var open=panel.style.display!=='none';
        panel.style.display=open?'none':'block';
        btn.textContent=(open?'▸':'▾')+' Raporlandı / Raporlanmadı Palet Ayrımı';
    });
})();
</script>
<?php endif; ?>

<!-- ── Tablo ── -->
<?php if (empty($rows)): ?>
<div class="empty"><p>Filtre kriterlerine uygun kayıt bulunamadı.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="data-table rpt-table">
    <thead>
    <tr>
        <?php foreach ($cols as $c): ?>
        <th class="<?= in_array($c, ['id','toplam_brut','toplam_dara','toplam_net','toplam_kasa','palet_sayisi','kayit_sayisi','toplam_adet','toplam_dara_kg','kullanim_sayisi','unit_dara_kg','net_kg','tartim1','tartim2','nakliye_bedeli','avans','toplam_kayit','yukleme_sayisi','cikma_sayisi','kasa_sayisi'], true) ? 'num' : '' ?>"><?= h(col_label($c)) ?></th>
        <?php endforeach; ?>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?><th class="actions-col rpt-no-print">Bağlantı</th><?php endif; ?>
        <?php if ($type === 'kantar'): ?><th class="actions-col rpt-no-print">Bağlantı</th><?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php $num_cols = ['toplam_brut','toplam_dara','toplam_net','toplam_kasa','toplam_adet','toplam_dara_kg','unit_dara_kg','net_kg','tartim1','tartim2','nakliye_bedeli','avans','toplam_brut','stok_giris','stok_sevk']; ?>
    <?php $int_cols = ['id','palet_sayisi','kayit_sayisi','kullanim_sayisi','toplam_kayit','yukleme_sayisi','cikma_sayisi','kasa_sayisi','palet_sayisi']; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
        <?php foreach ($cols as $c): ?>
        <?php
            $v = $r[$c] ?? '';
            if ($c === 'durum') {
                $cls = $v === 'islendi' ? 'badge-islendi' : ($v === 'yuklendi' ? 'badge-yuklendi' : '');
                $lbl = $v === 'islendi' ? 'İşlendi' : ($v === 'yuklendi' ? 'Yüklendi' : h($v));
                echo '<td>' . ($v !== '' ? '<span class="rpt-badge '.$cls.'">'.$lbl.'</span>' : '<span class="muted">—</span>') . '</td>';
            } elseif ($c === 'cikis_nedeni') {
                $cn = trim((string)$v);
                echo '<td>' . ($cn !== '' ? '<span class="cikis-nedeni-badge">' . h($cn) . '</span>' : '<span class="muted">—</span>') . '</td>';
            } elseif ($c === 'toplam_net' && $type === 'cikma') {
                $rv = round((float)$v);
                echo '<td class="num"><span class="cikma-net-kg">' . ($rv != 0 ? h(fmt_kg($rv)) : '<span class="muted">—</span>') . '</span></td>';
            } elseif ($c === 'stok_mevcut') {
                $sv = (float)$v;
                $color = $sv < 0 ? ' style="color:#dc2626;font-weight:700"' : ($sv > 0 ? ' style="font-weight:600"' : '');
                echo '<td class="num"' . $color . '>' . h(fmt_kg($sv)) . '</td>';
            } elseif ($c === 'is_active') {
                echo '<td>' . ($v ? '<span class="rpt-badge badge-yuklendi">Aktif</span>' : '<span class="muted">Pasif</span>') . '</td>';
            } elseif ($c === 'material_type') {
                $tl = definition_types();
                echo '<td>' . h($tl[$v] ?? $v) . '</td>';
            } elseif ($c === 'on_plaka') {
                $arka = $r['arka_plaka'] ?? '';
                echo '<td>' . h(trim($v . ($arka ? ' / '.$arka : ''), ' /')) . '</td>';
            } elseif ($c === 'arka_plaka') {
                // skip, handled with on_plaka
                continue;
            } elseif (in_array($c, ['toplam_dara','toplam_net'], true)) {
                $rv = round((float)$v);
                echo '<td class="num">' . ($rv != 0 ? h(fmt_kg($rv)) : '<span class="muted">—</span>') . '</td>';
            } elseif (in_array($c, $num_cols, true)) {
                echo '<td class="num">' . (($v != 0) ? h(fmt_kg((float)$v)) : '<span class="muted">—</span>') . '</td>';
            } elseif (in_array($c, $int_cols, true)) {
                echo '<td class="num">' . ($v > 0 ? number_format((int)$v, 0, ',', '.') : '<span class="muted">0</span>') . '</td>';
            } elseif ($c === 'tarih' || $c === 'ilk_tarih' || $c === 'son_tarih') {
                echo '<td>' . h($v ? fmt_date($v) : '—') . '</td>';
            } elseif ($c === 'giris_tarih' || $c === 'cikis_tarih' || $c === 'created_at') {
                echo '<td>' . h($v ? fmt_datetime($v) : '—') . '</td>';
            } else {
                echo '<td>' . h($v !== '' ? $v : '—') . '</td>';
            }
        ?>
        <?php endforeach; ?>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
        <td class="rpt-no-print"><a href="record_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Görüntüle</a></td>
        <?php endif; ?>
        <?php if ($type === 'kantar'): ?>
        <td class="rpt-no-print"><a href="kantar_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Görüntüle</a></td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if (!empty($totals)): ?>
    <tfoot>
    <tr class="totals-row">
        <?php
        $shown_cols = $cols;
        if (in_array('arka_plaka', $shown_cols, true)) {
            $shown_cols = array_filter($shown_cols, fn($c) => $c !== 'arka_plaka');
        }
        $i = 0;
        foreach ($shown_cols as $c):
            $i++;
            if ($i === 1) { echo '<td class="right strong">TOPLAM</td>'; continue; }
            if (isset($totals[$c])):
                $is_int = in_array($c, $int_cols, true);
                if (in_array($c, ['toplam_dara','toplam_net'], true)) {
                    echo '<td class="num strong">' . fmt_kg(round((float)$totals[$c])) . '</td>';
                } elseif ($is_int) {
                    echo '<td class="num strong">' . number_format((int)$totals[$c], 0, ',', '.') . '</td>';
                } else {
                    echo '<td class="num strong">' . fmt_kg((float)$totals[$c]) . '</td>';
                }
            else:
                echo '<td></td>';
            endif;
        endforeach;
        if (in_array($type, ['yukleme','cikma','kantar'], true)) echo '<td class="rpt-no-print"></td>';
        ?>
    </tr>
    </tfoot>
    <?php endif; ?>
</table>
</div>
<?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
