<?php
// =========================================================
// maliyet_view.php — Maliyet hesabı görüntüleme + yazdırma
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';
require_once __DIR__ . '/config/cost_link.php';

$auth_user = require_login();
cost_migrate();
require_maliyet('read');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: maliyet.php'); exit; }

$st = db()->prepare("SELECT * FROM cost_sheets WHERE id=? AND deleted_at IS NULL");
$st->execute([$id]);
$sheet = $st->fetch();
if (!$sheet) { set_flash('error', 'Maliyet hesabı bulunamadı.'); header('Location: maliyet.php'); exit; }

if (!depot_visible_to_user((string)$sheet['depo'])) {
    forbidden('Bu maliyet hesabı “' . h((string)$sheet['depo']) . '” deposuna ait — aktif deponuzda görünmüyor.');
}

// Kaynak yükleme planı — CANLI okunur (JOIN gibi), cost_sheets'e hiçbir
// alan kopyalanmaz. Kayıt silinmiş/görünmüyor olabilir → linkler o zaman gösterilmez.
$linked_record = null;
$stale_info    = null;
if (!empty($sheet['record_id'])) {
    $linked_record = cost_link_record_summary((int)$sheet['record_id']);
    $stale_info    = cost_link_stale_info((int)$sheet['record_id'], $sheet['linked_at'] ?? null);
}
$show_record_link = $linked_record !== null && can('records.read')
    && depot_visible_to_user($linked_record['depo']);

$st = db()->prepare("SELECT * FROM cost_sheet_sections WHERE sheet_id=? ORDER BY sort_order ASC, id ASC");
$st->execute([$id]);
$sections = $st->fetchAll();

$st = db()->prepare("SELECT * FROM cost_sheet_items WHERE sheet_id=? ORDER BY sort_order ASC, id ASC");
$st->execute([$id]);
$items = $st->fetchAll();

// Görüntülerken daima yeniden hesapla — tanım/formül değişmişse güncel sonucu gösterir
$calc = cost_compute($sheet, $sections, $items);
$T    = $calc['totals'];
$cur  = (string)$sheet['currency_code'];

$extra = [];
if (!empty($sheet['extra_json'])) {
    $d = json_decode((string)$sheet['extra_json'], true);
    if (is_array($d)) $extra = $d;
}
$field_defs = cost_fields();

$items_by_sec = [];
foreach ($items as $it) $items_by_sec[(int)$it['section_id']][] = $it;

$print_mode = isset($_GET['print']);

render_header('Maliyet: ' . ($sheet['sheet_no'] ?: ('#' . $id)), $print_mode);
if (!$print_mode) render_flash();
?>
<link rel="stylesheet" href="assets/maliyet.css?v=<?= @filemtime(__DIR__ . '/assets/maliyet.css') ?: time() ?>">

<?php if (!$print_mode): ?>
<div class="page-head no-print">
    <div>
        <h1>🧮 <?= h($sheet['sheet_no'] ?: ('Maliyet #' . $id)) ?></h1>
        <p class="muted">
            <?= h($sheet['product'] ?: '—') ?>
            <?= $sheet['brand'] ? ' · ' . h($sheet['brand']) : '' ?>
            <?= $sheet['sheet_date'] ? ' · ' . h(date('d.m.Y', strtotime((string)$sheet['sheet_date']))) : '' ?>
            · <span class="mly-badge mly-badge-<?= h($sheet['status']) ?>"><?= $sheet['status'] === 'kesin' ? 'Kesinleşti' : 'Taslak' ?></span>
        </p>
    </div>
    <div class="page-head-actions">
        <a href="maliyet.php" class="btn">← Liste</a>
        <?php if ($show_record_link): ?>
        <a href="record_view.php?id=<?= (int)$sheet['record_id'] ?>" class="btn no-print">📦 Kaynak Yükleme Planı</a>
        <?php endif; ?>
        <a href="maliyet_view.php?id=<?= $id ?>&print=1" class="btn" target="_blank">🖨️ Yazdır</a>
        <?php if (can_maliyet('write')): ?>
        <a href="maliyet_form.php?id=<?= $id ?>" class="btn btn-primary">✏️ Düzenle</a>
        <?php endif; ?>
        <?php if (can_maliyet('admin')): ?>
        <form method="post" action="maliyet_sablon.php" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="from_sheet">
            <input type="hidden" name="sheet_id" value="<?= $id ?>">
            <input type="hidden" name="name" value="<?= h(($sheet['product'] ?: 'Maliyet') . ' Şablonu') ?>">
            <button type="submit" class="btn">📑 Şablon Yap</button>
        </form>
        <?php endif; ?>
        <?php if (can_maliyet('delete')): ?>
        <form method="post" action="maliyet_sil.php" style="display:inline"
              onsubmit="return confirm('Bu maliyet hesabı silinsin mi?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-danger">Sil</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($stale_info !== null): ?>
<div class="flash flash-error no-print">
    ⚠️ Bu maliyet oluşturulduktan sonra yükleme planı değiştirilmiştir. Verileri kontrol etmeniz önerilir.
    <?php if ($show_record_link): ?>
        <a href="record_view.php?id=<?= (int)$sheet['record_id'] ?>">Kaynak yükleme planını gör</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($calc['errors']): ?>
<div class="flash flash-error no-print">
    <strong>Formül uyarıları:</strong>
    <ul style="margin:6px 0 0;padding-left:18px">
        <?php foreach (array_slice($calc['errors'], 0, 8) as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="mly-view-head">
    <h2><?= h($sheet['title'] ?: ('ASYA FRESH — ' . ($sheet['product'] ?: 'MALİYET') . ' HESABI')) ?></h2>
    <div class="mly-meta">
        <div><em>Belge No</em><b><?= h($sheet['sheet_no'] ?: '—') ?></b></div>
        <div><em>Parti No</em><b>
            <?php if ($linked_record !== null): ?>
                <?= h($linked_record['parti_no'] ?: '—') ?>
            <?php elseif (!empty($sheet['record_id'])): ?>
                <span class="muted">(kayıt bulunamadı)</span>
            <?php else: ?>
                <span class="muted">Bağsız</span>
            <?php endif; ?>
        </b></div>
        <div><em>Tarih</em><b><?= $sheet['sheet_date'] ? h(date('d.m.Y', strtotime((string)$sheet['sheet_date']))) : '—' ?></b></div>
        <div><em>Ürün</em><b><?= h($sheet['product'] ?: '—') ?></b></div>
        <div><em>Marka</em><b><?= h($sheet['brand'] ?: '—') ?></b></div>
        <div><em>Gümrük</em><b><?= h($sheet['gumruk'] ?: '—') ?></b></div>
        <div><em>Plaka</em><b><?= h($sheet['plaka'] ?: '—') ?></b></div>
        <div><em>Alıcı</em><b><?= h($sheet['alici'] ?: '—') ?></b></div>
        <div><em>Firma</em><b><?= h($sheet['firma'] ?: '—') ?></b></div>
        <div><em>Gideceği Yer</em><b><?= h($sheet['gidecegi_yer'] ?: '—') ?></b></div>
        <div><em>Ambalaj / Tip</em><b><?= h($sheet['ambalaj_tipi'] ?: '—') ?></b></div>
        <div><em>Net KG</em><b><?= cost_fmt_qty($sheet['net_kg']) ?></b></div>
        <div><em>Depo</em><b><?= h($sheet['depo'] ?: 'Atanmamış') ?></b></div>
        <?php if (!empty($sheet['linked_at'])): ?>
        <div><em>Bağlantı Tarihi</em><b class="muted" style="font-weight:400">
            <?= h(date('d.m.Y H:i', strtotime((string)$sheet['linked_at']))) ?>
        </b></div>
        <?php endif; ?>
        <?php foreach ($field_defs as $fd):
            $code = (string)$fd['code'];
            if ($fd['field_type'] === 'formula') {
                $val = cost_fmt_money((float)($T['field_values'][$code] ?? 0), (int)$fd['decimals']);
            } else {
                $raw = (string)($extra[$code] ?? '');
                if ($fd['field_type'] === 'checkbox') $raw = $raw ? 'Evet' : 'Hayır';
                if ($fd['field_type'] === 'number' && $raw !== '') $raw = cost_fmt_money(num($raw), (int)$fd['decimals']);
                $val = $raw !== '' ? $raw : '—';
            }
        ?>
        <div><em><?= h($fd['label']) ?></em><b><?= h($val) ?><?= $fd['suffix'] ? ' ' . h($fd['suffix']) : '' ?></b></div>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($sections as $sec):
    $sid  = (int)$sec['id'];
    $rows = $items_by_sec[$sid] ?? [];
    $cs   = $calc['sections'][$sid] ?? ['basis' => 0, 'total' => 0, 'unit_cost' => 0, 'include' => true];
?>
<div class="mly-section">
    <div class="mly-section-head" style="padding:12px 14px">
        <h3 style="margin:0">
            <?= h($sec['title']) ?>
            <?php if (!$cs['include']): ?><span class="muted" style="font-weight:400"> · genel toplama dahil değil</span><?php endif; ?>
        </h3>
        <?php if ($cs['basis'] > 0): ?>
        <div class="muted" style="font-size:.8rem"><?= h($sec['basis_label'] ?: 'NET KG') ?>: <?= cost_fmt_qty($cs['basis']) ?></div>
        <?php endif; ?>
    </div>
    <div class="table-wrap mly-table-wrap">
        <table class="mly-view-table">
            <thead>
                <tr>
                    <th>Kalem</th>
                    <th class="num">Miktar</th>
                    <th>Birim</th>
                    <th class="num">Birim Fiyat</th>
                    <th class="num">Tutar (TL)</th>
                    <th class="num">TL/KG</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $it):
                $c = $calc['items'][(int)$it['id']] ?? ['qty' => 0, 'amount' => 0, 'unit_cost' => 0];
                $cls = '';
                if ((int)$it['is_income'] === 1) $cls = 'mly-vr-income';
                if (in_array($it['calc_type'], ['info', 'subtotal'], true)) $cls = 'mly-vr-info';
            ?>
                <tr class="<?= $cls ?>">
                    <td data-label="Kalem">
                        <?= h($it['label']) ?>
                        <?php if ($it['calc_type'] === 'percent'): ?>
                            <span class="muted">(%<?= cost_fmt_money($it['percent'], 2) ?>)</span>
                        <?php elseif ($it['calc_type'] === 'formula'): ?>
                            <span class="muted">(<?= h($it['formula']) ?>)</span>
                        <?php endif; ?>
                        <?php if ($it['note']): ?><br><small class="muted"><?= h($it['note']) ?></small><?php endif; ?>
                    </td>
                    <td class="num" data-label="Miktar"><?= in_array($it['calc_type'], ['info','subtotal','fixed'], true) ? '' : cost_fmt_qty($c['qty']) ?></td>
                    <td data-label="Birim"><?= h($it['unit']) ?></td>
                    <td class="num" data-label="Birim Fiyat"><?= in_array($it['calc_type'], ['percent','formula','info','subtotal'], true) ? '' : cost_fmt_unit($it['unit_price'], 4) ?></td>
                    <td class="num" data-label="Tutar"><?= ((int)$it['is_income'] === 1 ? '−' : '') . cost_fmt_money($c['amount']) ?></td>
                    <td class="num" data-label="TL/KG"><?= $cs['basis'] > 0 ? cost_fmt_unit($c['unit_cost'], 4) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="muted">Bu bölümde kalem yok.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="right"><strong>Bölüm Toplamı</strong></td>
                    <td class="num" data-label="Bölüm Toplamı"><?= cost_fmt_money($cs['total']) ?></td>
                    <td class="num" data-label="TL/KG"><?= $cs['basis'] > 0 ? cost_fmt_unit($cs['unit_cost'], 4) : '—' ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endforeach; ?>

<div class="form-section">
    <div class="form-section-title">💱 Özet</div>
    <div class="mly-summary">
        <div class="mly-sum-box"><span>Toplam Maliyet</span><strong><?= cost_fmt_money($T['grand_total']) ?></strong><small>TL</small></div>
        <div class="mly-sum-box"><span>KG Maliyeti</span><strong><?= cost_fmt_unit($T['unit_cost_tl'], 4) ?></strong><small>TL/KG</small></div>
        <div class="mly-sum-box"><span>Kur</span><strong><?= cost_fmt_unit($T['rate'], 4) ?></strong><small>TL / <?= h($cur) ?></small></div>
        <div class="mly-sum-box"><span>TIR Üstü Maliyet</span><strong><?= cost_fmt_money($T['total_fx']) ?></strong><small><?= h($cur) ?></small></div>
        <div class="mly-sum-box"><span>Navlun Dahil</span><strong><?= cost_fmt_money($T['total_cost_fx']) ?></strong><small><?= h($cur) ?></small></div>
        <div class="mly-sum-box"><span>Fatura Bedeli</span><strong><?= cost_fmt_money($T['invoice_fx']) ?></strong><small><?= h($cur) ?></small></div>
        <div class="mly-sum-box mly-sum-profit <?= $T['profit_fx'] >= 0 ? 'mly-pos' : 'mly-neg' ?>">
            <span>Kâr / Zarar</span><strong><?= cost_fmt_money($T['profit_fx']) ?></strong>
            <small><?= h($cur) ?> · <?= cost_fmt_money($T['profit_tl']) ?> TL</small>
        </div>
    </div>
</div>

<?php if ($sheet['notes']): ?>
<div class="form-section">
    <div class="form-section-title">📝 Not</div>
    <div style="white-space:pre-wrap"><?= h($sheet['notes']) ?></div>
</div>
<?php endif; ?>

<?php if ($sheet['revision_reason'] && !$print_mode): ?>
<p class="muted no-print">Son revizyon nedeni: <?= h($sheet['revision_reason']) ?></p>
<?php endif; ?>

<?php if ($print_mode): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
<?php endif; ?>
<?php render_footer($print_mode); ?>
