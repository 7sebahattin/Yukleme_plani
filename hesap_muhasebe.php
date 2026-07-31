<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('approve');
hesap_migrate();

// Toplu durum geçişi — her kayıt hesap_transition() üzerinden tek tek doğrulanır,
// böylece yetki/geçiş kuralı atlanamaz ve her geçiş ayrı ayrı audit'e düşer (B8).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toplu_durum'])) {
    csrf_check($_POST['csrf'] ?? null);
    $ids   = array_values(array_filter(array_map('intval', $_POST['secili'] ?? []), fn($v) => $v > 0));
    $hedef = trim($_POST['toplu_durum']);
    $not   = trim($_POST['toplu_not'] ?? '');

    if (empty($ids)) {
        set_flash('error', 'Kayıt seçilmedi.');
    } elseif (!hesap_status_valid($hedef)) {
        set_flash('error', 'Geçersiz durum.');
    } else {
        $ok = 0; $atlanan = 0;
        foreach ($ids as $tid) {
            $res = hesap_transition($tid, $hedef, $not);
            if (!empty($res['ok'])) $ok++; else $atlanan++;
        }
        audit_log_event('bulk_update', 'hesap', null, null, [
            'operation'      => 'status_change',
            'target_status'  => $hedef,
            'affected_count' => $ok,
            'skipped_count'  => $atlanan,
        ]);
        if ($ok > 0) {
            set_flash('success', $ok . ' kayıt ' . hesap_status_label($hedef) . ' olarak işaretlendi.'
                . ($atlanan ? ' ' . $atlanan . ' kayıt atlandı (geçiş uygun değil).' : ''));
        } else {
            set_flash('error', 'Hiçbir kayıt güncellenemedi — seçilen kayıtlar bu geçişe uygun değil.');
        }
    }
    header('Location: hesap_muhasebe.php?' . http_build_query(array_filter([
        'tarih_bas' => $_POST['f_tarih_bas'] ?? '',
        'tarih_son' => $_POST['f_tarih_son'] ?? '',
        'durum'     => $_POST['f_durum'] ?? '',
    ])));
    exit;
}

$tarih_b = trim($_GET['tarih_bas'] ?? '');
$tarih_s = trim($_GET['tarih_son'] ?? '');
$durum_f = trim($_GET['durum'] ?? 'bekleyen');   // 'bekleyen' | '' (tümü) | durum kodu

$where  = ['1=1'];
$params = [];
if ($durum_f === 'bekleyen') {
    $ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));
    $where[] = "at.status IN ($ph)";
    $params  = array_merge($params, hesap_pending_statuses());
} elseif ($durum_f !== '' && hesap_status_valid($durum_f)) {
    $where[]  = "at.status=?";
    $params[] = $durum_f;
}
if ($tarih_b) { $where[] = "at.transaction_date>=?"; $params[] = $tarih_b; }
if ($tarih_s) { $where[] = "at.transaction_date<=?"; $params[] = $tarih_s; }
[$dsql, $dparams] = depo_sql_in('at.depo');
if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }
$wstr = implode(' AND ', $where);

$st = db()->prepare("SELECT at.*, COALESCE(u.display_name, u.username, '') AS personel
                     FROM account_transactions at
                     LEFT JOIN users u ON u.id = at.user_id
                     WHERE $wstr
                     ORDER BY at.transaction_date ASC, at.id ASC");
$st->execute($params);
$rows = $st->fetchAll();

// Personel bazlı bakiye özeti
$personel_bakiye = hesap_balance_by_user($tarih_b ?: null, $tarih_s ? date('Y-m-d', strtotime($tarih_s . ' +1 day')) : null);

// Kategoriye göre gruplama
$gruplar = [];
foreach ($rows as $r) {
    $gruplar[$r['category'] ?: hesap_type_label($r['type'])][] = $r;
}

render_header('Muhasebe Onay Kuyruğu');
hesap_assets();
render_flash();
?>
<div class="hs">
<div class="page-head">
    <div>
        <h1>🗂️ Muhasebe Onay Kuyruğu</h1>
        <p class="muted"><?= count($rows) ?> kayıt</p>
    </div>
    <div>
        <a href="hesap_export.php?<?= http_build_query(array_filter(['durum'=>$durum_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s])) ?>" class="btn btn-ghost">📊 Excel</a>
        <a href="hesap_yazdir.php?<?= http_build_query(array_filter(['durum'=>$durum_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s])) ?>" class="btn btn-ghost" target="_blank">🖨️ Yazdır</a>
        <a href="hesap_muhasebe_fis_pdf.php?<?= http_build_query(array_filter(['tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s])) ?>" class="btn btn-ghost" target="_blank">📸 Fiş Foto PDF</a>
    </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center">
    <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <input type="date" name="tarih_bas" value="<?= h($tarih_b) ?>" class="btn btn-ghost" style="padding:4px 8px">
        <span>—</span>
        <input type="date" name="tarih_son" value="<?= h($tarih_s) ?>" class="btn btn-ghost" style="padding:4px 8px">
        <select name="durum" class="btn btn-ghost" style="padding:4px 8px">
            <option value="bekleyen" <?= $durum_f === 'bekleyen' ? 'selected' : '' ?>>Onay bekleyenler</option>
            <option value="" <?= $durum_f === '' ? 'selected' : '' ?>>Tümü</option>
            <?php foreach (hesap_statuses() as $kod => $meta): ?>
            <option value="<?= h($kod) ?>" <?= $durum_f === $kod ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm">Filtrele</button>
    </form>
    <a href="hesap_liste.php" class="btn btn-ghost">← Tüm Kayıtlar</a>
</div>

<!-- Personel bakiye özeti -->
<?php if (!empty($personel_bakiye)): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-head"><h2>Personel Bakiyeleri <span class="muted">(TRY · onaylı ve ödenen kayıtlar)</span></h2></div>
    <div class="table-wrap">
    <table class="data-table">
    <thead><tr><th>Personel</th><th class="num">Gelir</th><th class="num">Gider</th><th class="num">Net</th><th>Durum</th><th class="num">Kayıt</th></tr></thead>
    <tbody>
    <?php foreach ($personel_bakiye as $pb): $bi = hesap_balance_label((float)$pb['net']); ?>
    <tr>
        <td><?= h($pb['personel']) ?></td>
        <td class="num"><?= fmt_para((float)$pb['gelir']) ?></td>
        <td class="num"><?= fmt_para((float)$pb['gider']) ?></td>
        <td class="num strong <?= $bi['yon'] === 'borc' ? 'text-red' : 'text-green' ?>"><?= fmt_para($bi['tutar']) ?></td>
        <td class="muted"><?= h($bi['label']) ?></td>
        <td class="num muted"><?= (int)$pb['adet'] ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
<div class="empty"><p>Bekleyen muhasebe kaydı yok.</p></div>
<?php else: ?>

<form method="post" id="topluForm">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="f_tarih_bas" value="<?= h($tarih_b) ?>">
<input type="hidden" name="f_tarih_son" value="<?= h($tarih_s) ?>">
<input type="hidden" name="f_durum" value="<?= h($durum_f) ?>">
<input type="hidden" name="toplu_not" id="topluNot" value="">

<div class="hs-actions" style="margin-bottom:12px">
    <button type="button" onclick="document.querySelectorAll('.muh-chk').forEach(function(c){c.checked=true})" class="btn btn-sm btn-ghost">Tümünü Seç</button>
    <button type="button" onclick="document.querySelectorAll('.muh-chk').forEach(function(c){c.checked=false})" class="btn btn-sm btn-ghost">Seçimi Kaldır</button>
    <button type="submit" name="toplu_durum" value="approved" class="btn btn-sm btn-primary">✓ Seçilenleri Onayla</button>
    <button type="submit" name="toplu_durum" value="pending_payment" class="btn btn-sm">⏳ Ödemeye Al</button>
    <?php if (hesap_can('pay')): ?>
    <button type="submit" name="toplu_durum" value="paid" class="btn btn-sm">✓✓ Ödendi İşaretle</button>
    <?php endif; ?>
    <button type="submit" name="toplu_durum" value="rejected" class="btn btn-sm btn-danger" onclick="return topluRed()">✕ Reddet</button>
</div>
<p class="muted" style="font-size:.78rem;margin:-6px 0 12px">
    Geçişe uygun olmayan kayıtlar sessizce atlanır — her geçiş tek tek doğrulanır ve işlem geçmişine yazılır.
</p>

<?php $sira = 0; foreach ($gruplar as $grup_adi => $grup_rows): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-head">
        <h2><?= h($grup_adi) ?> <span class="muted">(<?= count($grup_rows) ?> kayıt)</span></h2>
        <strong><?= fmt_para((float)array_sum(array_column($grup_rows, 'amount'))) ?></strong>
    </div>
    <div class="table-wrap">
    <table class="data-table">
    <thead><tr>
        <th style="width:30px">
            <input type="checkbox" onchange="this.closest('table').querySelectorAll('.muh-chk').forEach(function(c){c.checked=this.checked}.bind(this))">
        </th>
        <th>#</th>
        <th>Tarih</th>
        <th>Tür</th>
        <th>Personel</th>
        <th>Durum</th>
        <th>Kişi/Firma</th>
        <th>Açıklama</th>
        <th>Belge No</th>
        <th class="num">Tutar</th>
        <th>Ödeme</th>
        <th>Fiş</th>
    </tr></thead>
    <tbody>
    <?php foreach ($grup_rows as $r): $sira++; ?>
    <tr>
        <td><input type="checkbox" name="secili[]" value="<?= $r['id'] ?>" class="muh-chk"></td>
        <td class="muted"><?= $sira ?></td>
        <td class="muted"><?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?></td>
        <td><span class="hesap-type-badge" style="background:<?= hesap_type_color($r['type']) ?>"><?= hesap_type_label($r['type']) ?></span></td>
        <td class="muted"><?= h($r['personel'] ?: 'Atanmamış') ?></td>
        <td><?= hesap_status_badge($r['status'] ?? null, true) ?></td>
        <td><?= h($r['person_company']) ?></td>
        <td><?= h($r['description']) ?></td>
        <td class="muted"><?= h($r['document_no']) ?></td>
        <td class="num strong"><?= fmt_para((float)$r['amount'], $r['currency']) ?></td>
        <td class="muted"><?= hesap_payment_label($r['payment_method']) ?></td>
        <?php $fd = hesap_fis_durumu($r); ?>
        <td title="<?= h($fd['label']) ?>"><?= $fd['var'] ? '✓' : '<span style="color:var(--danger)">⚠</span>' ?></td>
    </tr>
    <?php if ($r['notes'] || (($r['status'] ?? '') === 'rejected' && trim((string)$r['review_note']) !== '')): ?>
    <tr style="background:#fafafa">
        <td></td>
        <td colspan="11" style="font-size:.8rem">
            <?php if ($r['notes']): ?>
            <span style="color:var(--muted);font-style:italic">📝 <?= h($r['notes']) ?></span>
            <?php endif; ?>
            <?php if (($r['status'] ?? '') === 'rejected' && trim((string)$r['review_note']) !== ''): ?>
            <div class="hs-note">Red gerekçesi: <?= h($r['review_note']) ?></div>
            <?php endif; ?>
        </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>

<div class="hs-actions" style="margin-top:8px">
    <button type="submit" name="toplu_durum" value="approved" class="btn btn-primary">✓ Seçilenleri Onayla</button>
    <button type="submit" name="toplu_durum" value="rejected" class="btn btn-danger" onclick="return topluRed()">✕ Reddet</button>
</div>
</form>

<script>
// Red gerekçesi zorunlu — sunucu da ayrıca doğrular (hesap_transition)
function topluRed() {
    var not = prompt('Red gerekçesi (zorunlu):') || '';
    if (!not.trim()) { alert('Gerekçe girilmeden reddedilemez.'); return false; }
    document.getElementById('topluNot').value = not.trim();
    return true;
}
</script>
<?php endif; ?>
</div><!-- /.hs -->
<?php hesap_scripts(); ?>
<?php render_footer(); ?>
