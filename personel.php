<?php
// =========================================================
// personel.php — Personel Listesi (PDKS Faz 1B)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks('employees');
pdks_migrate();   // idempotent — hesap_migrate() ile aynı desen, yalnız bu modülün sayfalarından çağrılır

$pdo = db();

$q       = trim($_GET['q'] ?? '');
$durum_f = trim($_GET['durum'] ?? '');
$dept_f  = trim($_GET['dept'] ?? '');
if ($durum_f !== '' && !array_key_exists($durum_f, pdks_personel_durumlari())) $durum_f = '';

$sayfa = max(1, (int)($_GET['sayfa'] ?? 1));
$limit = 50;
$offset = ($sayfa - 1) * $limit;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(e.full_name LIKE ? OR e.personnel_no LIKE ? OR e.job_title LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
}
if ($durum_f !== '') { $where[] = "e.status = ?"; $params[] = $durum_f; }
if ($dept_f !== '')  { $where[] = "e.department = ?"; $params[] = $dept_f; }

[$dsql, $dparams] = depo_sql_in('e.depo');
if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }

$whereSql = implode(' AND ', $where);

$toplam = 0;
$rows = [];
try {
    $stC = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE $whereSql");
    $stC->execute($params);
    $toplam = (int)$stC->fetchColumn();

    $sql = "SELECT e.*, u.username AS linked_username, u.display_name AS linked_display_name,
                (SELECT c.uid_hex FROM employee_cards c
                  WHERE c.employee_id = e.id AND c.status = 'aktif'
                  ORDER BY c.id DESC LIMIT 1) AS aktif_uid,
                (SELECT COUNT(*) FROM employee_cards c2 WHERE c2.employee_id = e.id) AS kart_sayisi
            FROM employees e
            LEFT JOIN users u ON u.id = e.user_id
            WHERE $whereSql
            ORDER BY e.full_name ASC
            LIMIT $limit OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    // Tablolar henüz kurulmamış olabilir (migrasyon admin tarafından çalıştırılmadıysa)
    set_flash('error', 'Personel tabloları henüz hazır değil. Bir yöneticinin migrate.php sayfasından "PDKS Tablolarını Oluştur" demesi gerekiyor.');
}

// ── Teşhis: filtresiz sonuç 0 ise, bunun GERÇEKTEN boş bir tablo mu yoksa
// depo filtresinin mi (depo_sql_in) sonucu olduğunu ayırt et — "Henüz
// personel kaydı yok" mesajı aksi hâlde YANILTICI olur: kayıt VAR ama aktif
// depoda görünmüyor olabilir (ör. masaüstünde bir depo seçiliyken oluşturulan
// personel, farklı bir depo seçili mobil oturumda depo_sql_in tarafından
// elenir — bkz. "Atanmamış veri kuralı" CLAUDE.md). Bu yalnız TEŞHİS
// amaçlıdır, filtreyi ASLA zayıflatmaz/atlamaz.
$genelToplam = null;
if ($toplam === 0 && $q === '' && $durum_f === '' && $dept_f === '' && $dsql !== '') {
    try { $genelToplam = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn(); }
    catch (PDOException $e) { $genelToplam = null; }
}

$toplamSayfa = max(1, (int)ceil($toplam / $limit));

// Filtre şeridindeki departman listesi — mevcut kayıtlardan, tanım tablosuna dokunmadan
$departmanlar = [];
try {
    $departmanlar = $pdo->query("SELECT DISTINCT department FROM employees WHERE department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { /* tablo yoksa sessizce boş */ }

render_header('Personeller');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>Personeller</h1>
    <div class="page-head-actions">
        <?php if (pdks_can('employees')): ?>
        <a href="personel_form.php" class="btn btn-primary">+ Yeni Personel</a>
        <?php endif; ?>
        <?php if (pdks_can('cards')): ?>
        <a href="personel_kartlar.php" class="btn">🪪 Kart Yönetimi</a>
        <?php endif; ?>
        <?php if (pdks_can('scan')): ?>
        <a href="giris_cikis.php" class="btn btn-primary">🚪 Giriş / Çıkış</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Ad, sicil no veya görev ara…">
    <select name="durum">
        <option value="">Tüm durumlar</option>
        <?php foreach (pdks_personel_durumlari() as $k => $lbl): ?>
        <option value="<?= h($k) ?>" <?= $durum_f === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (!empty($departmanlar)): ?>
    <select name="dept">
        <option value="">Tüm departmanlar</option>
        <?php foreach ($departmanlar as $d): ?>
        <option value="<?= h($d) ?>" <?= $dept_f === $d ? 'selected' : '' ?>><?= h($d) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn">Filtrele</button>
    <?php if ($q !== '' || $durum_f !== '' || $dept_f !== ''): ?>
    <a href="personel.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($rows)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">👤</span>
    <?php if ($genelToplam !== null && $genelToplam > 0): ?>
    <p>Sistemde <?= (int)$genelToplam ?> personel kaydı var, ama hiçbiri <strong>aktif depo</strong>nuzda görünmüyor.</p>
    <p class="muted" style="margin-top:-8px">
        Aktif depo: <strong><?= h(active_depot() ?? '—') ?></strong> —
        <a href="<?= h($base) ?>depo_sec.php?next=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>">depo değiştir</a>
    </p>
    <?php else: ?>
    <p><?= $toplam === 0 && $q === '' && $durum_f === '' ? 'Henüz personel kaydı yok.' : 'Bu filtrelerle personel bulunamadı.' ?></p>
    <?php endif; ?>
    <?php if (pdks_can('employees')): ?>
    <a href="personel_form.php" class="btn btn-primary">+ İlk Personeli Ekle</a>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th></th>
    <th>Sicil</th>
    <th>Ad Soyad</th>
    <th>Departman</th>
    <th>Görev</th>
    <th>Durum</th>
    <th>Kart</th>
    <th>Hesap</th>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= pdks_avatar_html($r['full_name'], $r['photo_file'], $r['photo_updated_at'], $base) ?></td>
    <td class="muted"><?= h($r['personnel_no'] ?: '—') ?></td>
    <td class="pdks-row-name"><?= h($r['full_name']) ?></td>
    <td><?= h($r['department'] ?: '—') ?></td>
    <td class="muted"><?= h($r['job_title'] ?: '—') ?></td>
    <td><span class="pdks-badge pdks-badge-<?= h($r['status']) ?>"><?= h(pdks_personel_durumlari()[$r['status']] ?? $r['status']) ?></span></td>
    <td>
        <?php if ($r['aktif_uid']): ?>
        <span class="pdks-badge pdks-badge-aktif" title="Kanonik UID"><?= h($r['aktif_uid']) ?></span>
        <?php elseif ((int)$r['kart_sayisi'] > 0): ?>
        <span class="pdks-badge pdks-badge-yok">Aktif kart yok</span>
        <?php else: ?>
        <span class="pdks-badge pdks-badge-yok">Kart yok</span>
        <?php endif; ?>
    </td>
    <td class="muted"><?= $r['linked_username'] ? h($r['linked_display_name'] ?: $r['linked_username']) : '—' ?></td>
    <td class="actions-col">
        <a href="personel_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Görüntüle / Düzenle</a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($rows as $r): ?>
<a href="personel_form.php?id=<?= (int)$r['id'] ?>" class="pdks-card-item" style="text-decoration:none;color:inherit">
    <div class="pdks-card-top">
        <?= pdks_avatar_html($r['full_name'], $r['photo_file'], $r['photo_updated_at'], $base) ?>
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($r['full_name']) ?></div>
            <div class="pdks-row-sub">
                <?= h($r['personnel_no'] ?: 'Sicilsiz') ?>
                <?= $r['department'] ? ' · ' . h($r['department']) : '' ?>
            </div>
        </div>
        <span class="pdks-badge pdks-badge-<?= h($r['status']) ?>"><?= h(pdks_personel_durumlari()[$r['status']] ?? $r['status']) ?></span>
    </div>
    <div class="pdks-row-sub">
        Kart:
        <?php if ($r['aktif_uid']): ?>
        <span class="pdks-uid"><?= h($r['aktif_uid']) ?></span>
        <?php else: ?>
        yok
        <?php endif; ?>
    </div>
</a>
<?php endforeach; ?>
</div>

<?php if ($toplamSayfa > 1): ?>
<div style="display:flex;gap:8px;justify-content:center;margin:18px 0">
    <?php
    $qs = $_GET; unset($qs['sayfa']);
    $qsStr = http_build_query($qs);
    $qsStr = $qsStr !== '' ? $qsStr . '&' : '';
    ?>
    <?php if ($sayfa > 1): ?>
    <a class="btn btn-sm" href="?<?= h($qsStr) ?>sayfa=<?= $sayfa - 1 ?>">‹ Önceki</a>
    <?php endif; ?>
    <span class="muted" style="align-self:center">Sayfa <?= $sayfa ?> / <?= $toplamSayfa ?></span>
    <?php if ($sayfa < $toplamSayfa): ?>
    <a class="btn btn-sm" href="?<?= h($qsStr) ?>sayfa=<?= $sayfa + 1 ?>">Sonraki ›</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
