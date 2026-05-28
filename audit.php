<?php
// =========================================================
// audit.php — Sistem veri kalitesi / orphan / duplicate audit
// Geçici — işiniz bitince git rm yapın.
// Web erişimine açık, otomatik silme/merge yapmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

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

// ── CSV Export (tüm header'lardan önce) ─────────────────────
$csv_key = trim($_GET['csv'] ?? '');
if ($csv_key !== '') {
    $export_keys = ($csv_key === 'all')
        ? array_keys($results)
        : (isset($results[$csv_key]) ? [$csv_key] : []);

    if (!empty($export_keys)) {
        $fname = 'audit_' . ($csv_key === 'all' ? 'tum' : $csv_key) . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo "\xEF\xBB\xBF"; // BOM — Excel Türkçe charset
        $out = fopen('php://output', 'w');

        foreach ($export_keys as $k) {
            $sec = $results[$k];
            if (empty($sec['rows'])) continue;
            // Bölüm başlığı
            fputcsv($out, ['=== ' . $sec['title'] . ' ==='], ';');
            fputcsv($out, $sec['cols'], ';');
            foreach ($sec['rows'] as $row) {
                $line = [];
                foreach ($sec['keys'] as $fk) $line[] = $row[$fk] ?? '';
                fputcsv($out, $line, ';');
            }
            fputcsv($out, [], ';'); // boş satır
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
    <a href="?csv=all" class="btn btn-ghost btn-sm">⬇ Tüm Raporu CSV İndir</a>
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
            <a href="?csv=<?= urlencode($key) ?>" class="btn btn-sm btn-ghost" style="font-size:.78rem">⬇ CSV</a>
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
// ── Normalize Simülasyonu ──────────────────────────────────
if ($has_md):
    $norm_rows = $pdo->query("
        SELECT id, type, name FROM material_definitions ORDER BY type, name LIMIT 300
    ")->fetchAll();

    // Unicode risk detection helper
    function au_unicode_risk(string $s): array {
        $flags = [];
        if (preg_match('/\x{0307}/u', $s))       $flags[] = 'U+0307 birleştirici nokta';
        if (preg_match('/\x{200B}/u', $s))        $flags[] = 'sıfır genişlikli boşluk';
        if (preg_match('/\x{200C}|\x{200D}/u', $s)) $flags[] = 'ZWNJ/ZWJ';
        if (preg_match('/\x{FEFF}/u', $s))        $flags[] = 'BOM';
        if (preg_match('/[^\x00-\x7F\xC0-\xFF\x{0100}-\x{024F}\x{011E}\x{011F}\x{0130}\x{0131}\x{015E}\x{015F}]/u', $s))
            $flags[] = 'olağandışı unicode';
        return $flags;
    }

    $sim_changed = 0; $sim_risk = 0;
    $sim_rows = [];
    foreach ($norm_rows as $r) {
        $v1 = normalize_text($r['name']);
        $v2 = normalize_text_v2($r['name']);
        $risk = au_unicode_risk($r['name']);
        $risk_v1 = au_unicode_risk($v1);
        $changed_v1 = ($v1 !== $r['name']);
        $changed_v2 = ($v2 !== $r['name']);
        $diff_v1_v2 = ($v1 !== $v2);
        $has_risk = !empty($risk) || !empty($risk_v1);
        if ($diff_v1_v2 || $has_risk) {
            $sim_rows[] = [
                'id'       => $r['id'],
                'type'     => $r['type'],
                'mevcut'   => $r['name'],
                'v1'       => $v1,
                'v2'       => $v2,
                'risk'     => implode(', ', array_merge($risk, $risk_v1)),
                'changed'  => $diff_v1_v2 || $changed_v1 || $changed_v2,
            ];
            if ($diff_v1_v2) $sim_changed++;
            if ($has_risk) $sim_risk++;
        }
    }
    $sim_badge = ($sim_changed > 0 || $sim_risk > 0) ? 'b-warn' : 'b-ok';
    $sim_badge_txt = ($sim_changed > 0 || $sim_risk > 0)
        ? $sim_changed . ' farklı, ' . $sim_risk . ' riskli'
        : '✓ Temiz';
?>
<details class="au-sec" <?= ($sim_changed > 0 || $sim_risk > 0) ? 'open' : '' ?>>
    <summary>
        Normalize Simülasyonu (v1 vs v2)
        <span class="au-badge <?= $sim_badge ?>"><?= h($sim_badge_txt) ?></span>
    </summary>
    <div class="au-body">
        <div class="au-risk"><strong>ℹ Bilgi:</strong>
            <code>normalize_text()</code> (v1) — MB_CASE_TITLE kullanır; Türkçe büyük harf İ için U+0307 combining dot üretebilir.<br>
            <code>normalize_text_v2()</code> (v2) — Türkçe i/ı/İ/I kurallarını doğru işler, combining char üretmez.<br>
            Bu tablo yalnızca v1 ≠ v2 veya unicode riski olan kayıtları gösterir.
        </div>
        <?php if (empty($sim_rows)): ?>
        <p class="au-empty">✓ Mevcut kayıtlarda v1/v2 farkı veya unicode riski bulunamadı.</p>
        <?php else: ?>
        <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:8px">
            <?= count($sim_rows) ?> kayıt gösteriliyor
            (<?= count($norm_rows) ?> toplam içinden, yalnızca farklı/riskli olanlar)
        </div>
        <div class="au-tbl-wrap">
        <table class="au-tbl">
            <thead><tr>
                <th>ID</th><th>Tür</th><th>Mevcut</th>
                <th>v1 (mevcut normalize)</th><th>v2 (yeni normalize)</th><th>Unicode Riski</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sim_rows as $sr): ?>
            <tr>
                <td><?= (int)$sr['id'] ?></td>
                <td><?= h($sr['type']) ?></td>
                <td><?= h($sr['mevcut']) ?></td>
                <td style="<?= $sr['v1'] !== $sr['mevcut'] ? 'background:#fef3c7' : '' ?>">
                    <?= h($sr['v1']) ?>
                </td>
                <td style="<?= $sr['v2'] !== $sr['v1'] ? 'background:#d1fae5' : '' ?>">
                    <?= h($sr['v2']) ?>
                </td>
                <td style="color:#b91c1c;font-size:.75rem"><?= h($sr['risk']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<?php render_footer();
