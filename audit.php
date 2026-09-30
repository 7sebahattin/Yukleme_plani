<?php
// =========================================================
// audit.php — Sistem veri kalitesi / orphan / duplicate audit
// Yalnızca admin erişimine açık. Otomatik silme/merge yapmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/xlsx_export.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
// Dışa aktarım — tüm uç noktalarda ortak kapı (reports.export) + audit
if (isset($_GET['csv']) || isset($_GET['xlsx'])) { require_perm('reports.export'); }

$pdo = db();

function audit_tbl(string $t): bool {
    try { db()->query("SELECT 1 FROM `$t` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

$has_msm = audit_tbl('material_stock_movements');
$has_md  = audit_tbl('material_definitions');
$has_lr  = audit_tbl('loading_records');

// ── Sorgu sonuçları ──────────────────────────────────────────
$results = [];

// 1. Orphan movements
if ($has_msm && $has_lr) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements m
        WHERE m.source_type='loading' AND m.source_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id=m.source_id)"
    )->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT m.id, m.movement_date, m.movement_type, m.material_name,
               m.quantity, m.unit, m.depo, m.source_id, m.created_at
        FROM material_stock_movements m
        WHERE m.source_type='loading' AND m.source_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id=m.source_id)
        ORDER BY m.id DESC LIMIT 20")->fetchAll() : [];
    $results['orphan'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['ID','Tarih','Tip','Malzeme','Miktar','Birim','Depo','source_id','Oluşturma'],
        'keys' => ['id','movement_date','movement_type','material_name','quantity','unit','depo','source_id','created_at'],
        'title' => 'Yetim Stok Hareketleri',
        'risk'  => 'loading_records silinmiş ama material_stock_movements kalmış. Malzeme stok hesabı yüksek görünür.',
        'fix'   => "DELETE FROM material_stock_movements\n  WHERE source_type='loading'\n    AND source_id NOT IN (SELECT id FROM loading_records);",
    ];
} else {
    $results['orphan'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Yetim Stok Hareketleri', 'risk' => '', 'fix' => ''];
}

// 2. Geçersiz material_id
if ($has_msm && $has_md) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements
        WHERE material_id IS NOT NULL
          AND material_id NOT IN (SELECT id FROM material_definitions)"
    )->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT m.id, m.movement_date, m.movement_type, m.material_id,
               m.material_name, m.quantity, m.unit, m.source_type, m.source_id
        FROM material_stock_movements m
        WHERE m.material_id IS NOT NULL
          AND m.material_id NOT IN (SELECT id FROM material_definitions)
        ORDER BY m.id DESC LIMIT 20")->fetchAll() : [];
    $results['invalid_mat'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['ID','Tarih','Tip','material_id','Malzeme','Miktar','Birim','source_type','source_id'],
        'keys' => ['id','movement_date','movement_type','material_id','material_name','quantity','unit','source_type','source_id'],
        'title' => 'Geçersiz material_id',
        'risk'  => 'material_definitions kaydı silinmiş ama harekette referans kalmış. Malzeme bazında stok raporları hatalı.',
        'fix'   => "UPDATE material_stock_movements\n  SET material_id=NULL\n  WHERE material_id NOT IN (SELECT id FROM material_definitions);",
    ];
} else {
    $results['invalid_mat'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Geçersiz material_id', 'risk' => '', 'fix' => ''];
}

// 3. Negatif quantity
if ($has_msm) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements WHERE quantity < 0")->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT id, movement_date, movement_type, material_name,
               quantity, unit, depo, source_type, source_id
        FROM material_stock_movements WHERE quantity < 0
        ORDER BY quantity ASC LIMIT 20")->fetchAll() : [];
    $results['negative'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['ID','Tarih','Tip','Malzeme','Miktar','Birim','Depo','source_type','source_id'],
        'keys' => ['id','movement_date','movement_type','material_name','quantity','unit','depo','source_type','source_id'],
        'title' => 'Negatif Miktar',
        'risk'  => 'Stok hareketi negatif miktar içeriyor. Stok toplamı yanlış hesaplanabilir.',
        'fix'   => "İlgili kayıtları manuel incele. Geçersizse sil, düzeltmeyse\nmovement_type='duzeltme' ile pozitif kayıt gir.",
    ];
} else {
    $results['negative'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Negatif Miktar', 'risk' => '', 'fix' => ''];
}

// 4. Birebir duplicate material_definitions
if ($has_md) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM (
        SELECT 1 FROM material_definitions GROUP BY type, name HAVING COUNT(*) > 1
    ) x")->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT type, name, COUNT(*) AS sayi,
               GROUP_CONCAT(id ORDER BY id SEPARATOR ', ')          AS idler,
               GROUP_CONCAT(is_active ORDER BY id SEPARATOR ', ')   AS aktifler
        FROM material_definitions
        GROUP BY type, name HAVING sayi > 1
        ORDER BY type, name LIMIT 20")->fetchAll() : [];
    $results['dup_exact'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['Tip','İsim','Tekrar','ID\'ler','is_active listesi'],
        'keys' => ['type','name','sayi','idler','aktifler'],
        'title' => 'Birebir Duplicate Tanımlar (type+name)',
        'risk'  => 'Aynı (type, name) çifti birden fazla kayıtta. UNIQUE constraint eklenemez; form listelerinde tekrar görünür.',
        'fix'   => "Küçük ID'yi koru, büyük ID'leri sil (FK kontrolü yap!):\nDELETE FROM material_definitions WHERE id IN (...büyük_idler...);",
    ];
} else {
    $results['dup_exact'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Birebir Duplicate Tanımlar', 'risk' => '', 'fix' => ''];
}

// 5. Normalize sonrası duplicate
if ($has_md) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM (
        SELECT 1 FROM material_definitions
        GROUP BY type, LOWER(TRIM(name)) HAVING COUNT(*) > 1
    ) x")->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT type,
               LOWER(TRIM(name))                                         AS norm,
               COUNT(*)                                                  AS sayi,
               GROUP_CONCAT(id ORDER BY id SEPARATOR ', ')               AS idler,
               GROUP_CONCAT(name ORDER BY id SEPARATOR ' | ')            AS isimler
        FROM material_definitions
        GROUP BY type, LOWER(TRIM(name)) HAVING sayi > 1
        ORDER BY type, norm LIMIT 20")->fetchAll() : [];
    $results['dup_norm'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['Tip','Normalize İsim','Tekrar','ID\'ler','Orijinal İsimler'],
        'keys' => ['type','norm','sayi','idler','isimler'],
        'title' => 'Normalize Sonrası Duplicate (trim+lowercase)',
        'risk'  => 'Trim/büyük-küçük harf farkı olan ama aynı anlama gelen tanımlar. Form seçimlerinde karışıklık yaratır.',
        'fix'   => "normalize_text() ile isimleri standartlaştır, fazla olanı sil.\nensure_definition() zaten case-insensitive kontrol yapıyor.",
    ];
} else {
    $results['dup_norm'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Normalize Sonrası Duplicate', 'risk' => '', 'fix' => ''];
}

// 6. Tip bazında dağılım
if ($has_md) {
    $rows = $pdo->query("
        SELECT type,
               COUNT(*)                                          AS toplam,
               COUNT(DISTINCT LOWER(TRIM(name)))                 AS uniq,
               COUNT(*) - COUNT(DISTINCT LOWER(TRIM(name)))      AS fark,
               SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END)      AS pasif
        FROM material_definitions
        GROUP BY type ORDER BY fark DESC, type")->fetchAll();
    $cnt = count($rows);
    $results['type_dist'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['Tip','Toplam','Unique (norm)','Dup Farkı','Pasif Sayı'],
        'keys' => ['type','toplam','uniq','fark','pasif'],
        'title' => 'Tanım Tipleri Dağılımı',
        'risk'  => 'Fark > 0 olan tipler normalize duplicate içeriyor. UNIQUE eklemek için bu farkların sıfırlanması gerekir.',
        'fix'   => "Fark > 0 tipler için yukarıdaki duplicate raporlarına bakın.\nFark = 0 olan tipler için UNIQUE(type,name) güvenli eklenebilir.",
    ];
} else {
    $results['type_dist'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Tanım Tipleri Dağılımı', 'risk' => '', 'fix' => ''];
}

// ── XLSX Export — her kontrol bölümü AYRI sayfa (CSV'de alt alta diziliyordu) ──
$xlsx_key = trim($_GET['xlsx'] ?? '');
if ($xlsx_key !== '') {
    $export_keys = ($xlsx_key === 'all') ? array_keys($results) : (isset($results[$xlsx_key]) ? [$xlsx_key] : []);
    $sayfalar = [];
    $say = 0;
    foreach ($export_keys as $k) {
        $sec = $results[$k];
        if (empty($sec['rows'])) continue;
        $say += count($sec['rows']);
        $sayfalar[] = [
            'ad' => $sec['title'], 'baslik' => $sec['title'],
            'aciklama' => trim(preg_replace('/\s+/', ' ', (string)($sec['risk'] ?? ''))) ?: 'Veri denetimi',
            'sutunlar' => array_map(fn($c) => ['baslik' => $c], $sec['cols']),
            'satirlar' => array_map(fn($row) => array_map(fn($fk) => $row[$fk] ?? '', $sec['keys']), $sec['rows']),
        ];
    }
    if ($sayfalar) {
        export_audit('audit', 'veri_denetimi_' . $xlsx_key, 'xlsx', $say);
        xlsx_indir('audit_' . ($xlsx_key === 'all' ? 'tum' : $xlsx_key) . '_' . date('Ymd_His') . '.xlsx', $sayfalar, '?csv=' . urlencode($xlsx_key));
    }
}

// ── CSV Export (tüm header'lardan önce) ─────────────────────
$csv_key = trim($_GET['csv'] ?? '');
if ($csv_key !== '') {
    $export_keys = ($csv_key === 'all')
        ? array_keys($results)
        : (isset($results[$csv_key]) ? [$csv_key] : []);

    if (!empty($export_keys)) {
        export_audit('audit', 'veri_denetimi_' . $csv_key, 'csv', array_sum(array_map(fn($k) => count($results[$k]['rows']), $export_keys)));
        $fname = 'audit_' . ($csv_key === 'all' ? 'tum' : $csv_key) . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo "\xEF\xBB\xBF"; // BOM — Excel Türkçe charset
        $out = fopen('php://output', 'w');

        foreach ($export_keys as $k) {
            $sec = $results[$k];
            if (empty($sec['rows'])) continue;
            // Bölüm başlığı
            fputcsv($out, ['=== ' . $sec['title'] . ' ==='], ';', '"', '\\');
            fputcsv($out, $sec['cols'], ';', '"', '\\');
            foreach ($sec['rows'] as $row) {
                $line = [];
                foreach ($sec['keys'] as $fk) $line[] = $row[$fk] ?? '';
                fputcsv($out, $line, ';', '"', '\\');
            }
            fputcsv($out, [], ';', '"', '\\'); // boş satır
        }
        fclose($out);
        exit;
    }
}

// ── HTML ─────────────────────────────────────────────────────
render_header('Sistem Audit');

$issue_count = array_sum(array_map(function($s) {
    if ($s['count'] === null) return 0;
    // type_dist: fark toplamı
    if (isset($s['rows'][0]['fark'])) {
        return (int)array_sum(array_column($s['rows'], 'fark'));
    }
    return (int)$s['count'];
}, $results));
?>
<div class="page-head">
    <h1>🔍 Sistem Audit</h1>
    <p style="color:var(--text-muted);font-size:.85rem;margin-top:4px">
        Otomatik silme / merge yapılmaz — sadece raporlama.
        &nbsp;·&nbsp; Son çalışma: <strong><?= date('d.m.Y H:i:s') ?></strong>
    </p>
</div>

<?php if ($issue_count > 0): ?>
<div class="flash flash-error">⚠ Toplam <strong><?= $issue_count ?></strong> sorunlu kayıt tespit edildi.</div>
<?php else: ?>
<div class="flash flash-success">✓ Tüm kontrollerde sorun bulunamadı.</div>
<?php endif; ?>

<div style="text-align:right;margin-bottom:12px">
    <?= export_menu('?csv=all', '?xlsx=all', 'Tüm Raporu İndir', 'btn btn-ghost btn-sm') ?>
</div>

<style>
.au-sec{border:1px solid var(--border);border-radius:10px;margin-bottom:12px;overflow:hidden}
.au-sec summary{cursor:pointer;padding:11px 16px;background:var(--card-bg,#fff);display:flex;align-items:center;gap:10px;list-style:none;user-select:none;font-weight:600}
.au-sec summary::-webkit-details-marker{display:none}
.au-sec[open]>summary{border-bottom:1px solid var(--border)}
.au-badge{font-size:.75rem;padding:2px 10px;border-radius:20px;font-weight:700;margin-left:auto;white-space:nowrap}
.b-ok{background:#d1fae5;color:#065f46}.b-warn{background:#fef3c7;color:#92400e}
.b-err{background:#fee2e2;color:#991b1b}.b-info{background:#dbeafe;color:#1e40af}
.au-body{padding:14px 16px}
.au-risk{background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:0 6px 6px 0;font-size:.82rem;margin-bottom:8px}
.au-fix{background:#f0f9ff;border-left:3px solid #0284c7;padding:8px 12px;border-radius:0 6px 6px 0;font-size:.82rem;margin-bottom:10px}
.au-fix code{display:block;margin-top:5px;font-size:.78rem;color:#0369a1;white-space:pre-wrap;word-break:break-all}
.au-tbl-wrap{overflow-x:auto;margin-top:8px}
.au-tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.au-tbl th{background:var(--thead-bg,#f1f5f9);padding:6px 10px;text-align:left;white-space:nowrap;border-bottom:2px solid var(--border)}
.au-tbl td{padding:5px 10px;border-bottom:1px solid var(--border);vertical-align:top;word-break:break-word;max-width:260px}
.au-tbl tr:last-child td{border-bottom:none}
.au-tbl tr:hover td{background:var(--hover-bg,#f8fafc)}
.au-empty{color:var(--text-muted);font-size:.85rem;padding:6px 0}
.au-meta{font-size:.8rem;color:var(--text-muted)}
</style>

<?php
$section_order = ['type_dist','dup_exact','dup_norm','orphan','invalid_mat','negative'];
foreach ($section_order as $key):
    if (!isset($results[$key])) continue;
    $sec = $results[$key];
    $cnt = $sec['count'];

    // Badge
    if ($cnt === null) {
        $badge = 'b-info'; $badge_txt = 'Tablo yok';
    } elseif ($key === 'type_dist') {
        $diff = (int)array_sum(array_column($sec['rows'], 'fark'));
        $badge = $diff > 0 ? 'b-warn' : 'b-ok';
        $badge_txt = $cnt . ' tip' . ($diff > 0 ? " · $diff dup" : ' · temiz');
    } elseif ($cnt === 0) {
        $badge = 'b-ok'; $badge_txt = '✓ Temiz';
    } else {
        $badge = ($cnt >= 5) ? 'b-err' : 'b-warn';
        $badge_txt = $cnt . ' kayıt';
    }

    // Auto-open if has issues (except type_dist and always-open is distracting)
    $auto_open = ($cnt !== null && $cnt > 0 && $key !== 'type_dist');
?>
<details class="au-sec" <?= $auto_open ? 'open' : '' ?>>
    <summary>
        <?= h($sec['title']) ?>
        <span class="au-badge <?= $badge ?>"><?= h($badge_txt) ?></span>
    </summary>
    <div class="au-body">
    <?php if ($cnt === null): ?>
        <p class="au-empty">Tablo mevcut değil.</p>
    <?php else: ?>
        <?php if ($sec['risk'] !== ''): ?>
        <div class="au-risk"><strong>⚠ Risk:</strong> <?= h($sec['risk']) ?></div>
        <?php endif; ?>
        <?php if ($sec['fix'] !== ''): ?>
        <div class="au-fix">
            <strong>💡 Önerilen işlem:</strong>
            <code><?= h($sec['fix']) ?></code>
        </div>
        <?php endif; ?>

        <?php if (count($sec['rows']) > 0): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">
            <span class="au-meta">
                <?= $cnt > 20
                    ? 'İlk 20 kayıt (toplam: <strong>' . $cnt . '</strong>)'
                    : '<strong>' . $cnt . '</strong> kayıt' ?>
            </span>
            <?= export_menu('?csv=' . urlencode($key), '?xlsx=' . urlencode($key), 'Excel', 'btn btn-sm btn-ghost') ?>
        </div>
        <div class="au-tbl-wrap">
            <table class="au-tbl">
                <thead><tr>
                    <?php foreach ($sec['cols'] as $col): ?>
                    <th><?= h($col) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($sec['rows'] as $row): ?>
                    <tr>
                        <?php foreach ($sec['keys'] as $k): ?>
                        <td><?= h((string)($row[$k] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="au-empty">✓ Bu kontrolde sorun bulunamadı.</p>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</details>
<?php endforeach; ?>

<?php
// ── İşlem Geçmişi (audit_log) ──────────────────────────────
$has_audit_log = false;
try {
    db()->query("SELECT 1 FROM audit_log LIMIT 0");
    $has_audit_log = true;
} catch (PDOException $e) {}

$al_page    = max(1, (int)($_GET['al_page'] ?? 1));
$al_per     = 100;
$al_offset  = ($al_page - 1) * $al_per;
$al_module  = trim($_GET['al_module']  ?? '');
$al_action  = trim($_GET['al_action']  ?? '');
$al_user    = trim($_GET['al_user']    ?? '');
$al_rid     = trim($_GET['al_rid']     ?? '');
$al_from    = trim($_GET['al_from']    ?? '');
$al_to      = trim($_GET['al_to']      ?? '');

$al_rows    = [];
$al_total   = 0;
$al_users   = [];
$al_modules = [];
$al_actions = [];

if ($has_audit_log) {
    try {
        // Distinct filters
        $al_users   = db()->query("SELECT DISTINCT u.id, COALESCE(u.display_name, u.username) AS name FROM audit_log al JOIN users u ON u.id = al.user_id WHERE al.user_id IS NOT NULL ORDER BY name")->fetchAll();
        $al_modules = db()->query("SELECT DISTINCT module FROM audit_log ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
        $al_actions = db()->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

        $where = []; $params = [];
        if ($al_module !== '') { $where[] = "al.module = ?"; $params[] = $al_module; }
        if ($al_action !== '') { $where[] = "al.action = ?"; $params[] = $al_action; }
        if ($al_user   !== '') { $where[] = "al.user_id = ?"; $params[] = (int)$al_user; }
        if ($al_rid    !== '') { $where[] = "al.record_id = ?"; $params[] = (int)$al_rid; }
        if ($al_from   !== '') { $where[] = "al.created_at >= ?"; $params[] = $al_from . ' 00:00:00'; }
        if ($al_to     !== '') { $where[] = "al.created_at <= ?"; $params[] = $al_to   . ' 23:59:59'; }
        $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $cnt_st = db()->prepare("SELECT COUNT(*) FROM audit_log al $wsql");
        $cnt_st->execute($params);
        $al_total = (int)$cnt_st->fetchColumn();

        $row_st = db()->prepare("
            SELECT al.id, al.user_id, COALESCE(u.display_name, u.username, '—') AS user_name,
                   al.action, al.module, al.record_id,
                   al.old_values, al.new_values, al.ip, al.created_at
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            $wsql
            ORDER BY al.id DESC
            LIMIT $al_per OFFSET $al_offset
        ");
        $row_st->execute($params);
        $al_rows = $row_st->fetchAll();
    } catch (PDOException $e) { }
}

$al_pages = max(1, (int)ceil($al_total / $al_per));

function _al_pager_url(array $extra = []): string {
    $base = array_filter([
        'al_module' => $_GET['al_module'] ?? '',
        'al_action' => $_GET['al_action'] ?? '',
        'al_user'   => $_GET['al_user']   ?? '',
        'al_rid'    => $_GET['al_rid']    ?? '',
        'al_from'   => $_GET['al_from']   ?? '',
        'al_to'     => $_GET['al_to']     ?? '',
    ], fn($v) => $v !== '');
    return 'audit.php?' . http_build_query(array_merge($base, $extra)) . '#al-section';
}

$al_badge_cls = ($al_total > 0 && $has_audit_log) ? 'b-info' : 'b-ok';
$al_badge_txt = $has_audit_log ? ($al_total . ' kayıt') : 'Tablo yok';
?>

<details class="au-sec" id="al-section">
<summary>
    📋 İşlem Geçmişi
    <span class="au-badge <?= $al_badge_cls ?>" style="margin-left:auto"><?= h($al_badge_txt) ?></span>
</summary>
<div class="au-body">

<?php if (!$has_audit_log): ?>
<p class="au-empty">audit_log tablosu henüz oluşturulmadı. Sayfayı yenileyin.</p>
<?php else: ?>

<style>
.al-filters{display:flex;flex-wrap:wrap;gap:6px;padding:8px 10px;background:#f8fafc;
            border:1px solid #e2e8f0;border-radius:7px;margin-bottom:10px;align-items:flex-end}
.al-filters label{display:flex;flex-direction:column;gap:3px;font-size:.78rem;font-weight:600;color:#475569}
.al-filters input,.al-filters select{font-size:.8rem;padding:4px 7px;border:1px solid #cbd5e1;border-radius:5px;min-width:100px}
.al-badge-action{display:inline-block;font-size:.7rem;padding:1px 8px;border-radius:9px;font-weight:700;white-space:nowrap}
.al-a-create{background:#d1fae5;color:#065f46}
.al-a-update{background:#dbeafe;color:#1e40af}
.al-a-delete{background:#fee2e2;color:#991b1b}
.al-a-login_success{background:#f0fdf4;color:#166534}
.al-a-login_failed{background:#fff1f2;color:#9f1239}
.al-a-logout{background:#f1f5f9;color:#475569}
.al-a-default{background:#f3f4f6;color:#6b7280}
.al-detail{display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;margin-top:4px;font-size:.73rem;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
.al-row-toggle{cursor:pointer}
.al-row-toggle:hover td{background:#f0f9ff}
</style>

<form method="get" action="audit.php">
<div class="al-filters">
    <label>Tarih başlangıç
        <input type="date" name="al_from" value="<?= h($al_from) ?>">
    </label>
    <label>Tarih bitiş
        <input type="date" name="al_to" value="<?= h($al_to) ?>">
    </label>
    <label>Kullanıcı
        <select name="al_user">
            <option value="">Tümü</option>
            <?php foreach ($al_users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (string)$u['id'] === $al_user ? 'selected' : '' ?>>
                <?= h($u['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Modül
        <select name="al_module">
            <option value="">Tümü</option>
            <?php foreach ($al_modules as $m): ?>
            <option value="<?= h($m) ?>" <?= $m === $al_module ? 'selected' : '' ?>><?= h($m) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>İşlem
        <select name="al_action">
            <option value="">Tümü</option>
            <?php foreach ($al_actions as $a): ?>
            <option value="<?= h($a) ?>" <?= $a === $al_action ? 'selected' : '' ?>><?= h($a) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Kayıt ID
        <input type="number" name="al_rid" value="<?= h($al_rid) ?>" style="width:80px">
    </label>
    <div style="display:flex;gap:6px;align-self:flex-end;margin-top:2px">
        <button type="submit" class="btn btn-sm">Filtrele</button>
        <a href="audit.php#al-section" class="btn btn-sm btn-ghost">Sıfırla</a>
    </div>
</div>
</form>

<div style="font-size:.8rem;color:var(--text-muted);margin-bottom:8px">
    Toplam <strong><?= $al_total ?></strong> kayıt
    <?php if ($al_pages > 1): ?>&nbsp;·&nbsp; Sayfa <strong><?= $al_page ?></strong> / <?= $al_pages ?><?php endif; ?>
</div>

<?php if (empty($al_rows)): ?>
<p class="au-empty">Kayıt bulunamadı.</p>
<?php else: ?>
<div class="au-tbl-wrap">
<table class="au-tbl" id="alTable">
    <thead><tr>
        <th>ID</th><th>Tarih</th><th>Kullanıcı</th><th>Modül</th><th>İşlem</th><th>Kayıt</th><th>IP</th>
    </tr></thead>
    <tbody>
    <?php foreach ($al_rows as $r):
        $action_cls = 'al-a-' . (in_array($r['action'], ['create','update','delete','login_success','login_failed','logout']) ? $r['action'] : 'default');
        $has_detail = ($r['old_values'] !== null || $r['new_values'] !== null);
    ?>
    <tr class="<?= $has_detail ? 'al-row-toggle' : '' ?>"
        <?= $has_detail ? 'onclick="alToggle(this,' . (int)$r['id'] . ')"' : '' ?>>
        <td style="font-size:.72rem;color:#94a3b8">#<?= (int)$r['id'] ?></td>
        <td style="white-space:nowrap;font-size:.78rem"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
        <td style="font-size:.8rem"><?= h($r['user_name']) ?></td>
        <td><code style="font-size:.75rem"><?= h($r['module']) ?></code></td>
        <td><span class="al-badge-action <?= $action_cls ?>"><?= h($r['action']) ?></span></td>
        <td style="font-size:.78rem"><?= $r['record_id'] ? '#' . (int)$r['record_id'] : '—' ?></td>
        <td style="font-size:.72rem;color:#94a3b8"><?= h($r['ip'] ?? '') ?></td>
    </tr>
    <?php if ($has_detail): ?>
    <tr id="alDetail<?= (int)$r['id'] ?>" style="display:none">
        <td colspan="7" style="padding:0 8px 8px">
            <?php if ($r['old_values'] !== null): ?>
            <div style="font-size:.73rem;font-weight:600;color:#64748b;margin:6px 0 2px">Eski:</div>
            <pre class="al-detail" style="display:block"><?= h($r['old_values']) ?></pre>
            <?php endif; ?>
            <?php if ($r['new_values'] !== null): ?>
            <div style="font-size:.73rem;font-weight:600;color:#64748b;margin:6px 0 2px">Yeni:</div>
            <pre class="al-detail" style="display:block"><?= h($r['new_values']) ?></pre>
            <?php endif; ?>
        </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($al_pages > 1): ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:12px;font-size:.83rem">
    <?php if ($al_page > 1): ?>
    <a href="<?= h(_al_pager_url(['al_page' => $al_page - 1])) ?>" class="btn btn-sm btn-ghost">← Önceki</a>
    <?php endif; ?>
    <?php
    $pstart = max(1, $al_page - 2);
    $pend   = min($al_pages, $al_page + 2);
    for ($pi = $pstart; $pi <= $pend; $pi++): ?>
    <a href="<?= h(_al_pager_url(['al_page' => $pi])) ?>"
       class="btn btn-sm <?= $pi === $al_page ? '' : 'btn-ghost' ?>"
       style="<?= $pi === $al_page ? 'font-weight:700' : '' ?>">
        <?= $pi ?>
    </a>
    <?php endfor; ?>
    <?php if ($al_page < $al_pages): ?>
    <a href="<?= h(_al_pager_url(['al_page' => $al_page + 1])) ?>" class="btn btn-sm btn-ghost">Sonraki →</a>
    <?php endif; ?>
    <span style="color:var(--text-muted)"><?= $al_page ?> / <?= $al_pages ?></span>
</div>
<?php endif; ?>

<script>
window.alToggle = function(row, id) {
    var det = document.getElementById('alDetail' + id);
    if (det) det.style.display = det.style.display === 'none' ? 'table-row' : 'none';
};
</script>

<?php endif; ?>
<?php endif; ?>
</div>
</details>

<?php render_footer();
