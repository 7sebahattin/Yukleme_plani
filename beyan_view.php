<?php
// =========================================================
// beyan_view.php — Beyan detay ekranı (Sprint Beyan-01)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('read')) forbidden();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('error', 'Geçersiz beyan.'); header('Location: beyanlar.php'); exit; }

$st = db()->prepare("SELECT * FROM customs_declarations WHERE id = ?");
$st->execute([$id]);
$beyan = $st->fetch();
if (!$beyan) { set_flash('error', 'Beyan bulunamadı.'); header('Location: beyanlar.php'); exit; }

$is_deleted = !empty($beyan['deleted_at']);
$statuses   = beyan_statuses();
$cur_status = (string)($beyan['status'] ?? 'beyan_acildi');

// Yükleme planı bağlantısı
$linked_record = null;
if (!empty($beyan['loading_record_id'])) {
    $slr = db()->prepare("SELECT id, firma, parti_no, tarih FROM loading_records WHERE id = ?");
    $slr->execute([(int)$beyan['loading_record_id']]);
    $linked_record = $slr->fetch() ?: null;
}

render_header('Beyan Detayı');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🧾 <?= h($beyan['party_no'] ?: 'Beyan #' . $id) ?></h1>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:4px">
            <?= beyan_badge_html($cur_status) ?>
            <?php if ($is_deleted): ?>
            <span class="beyan-badge" style="background:#fef3c7;color:#92400e">ARŞİVLENDİ</span>
            <?php endif; ?>
            <span class="muted" style="font-size:.82rem">#<?= $id ?></span>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="beyanlar.php" class="btn btn-ghost">← Beyanlar</a>
        <?php if (!$is_deleted && can_beyan('write')): ?>
        <a href="beyan_edit.php?id=<?= $id ?>" class="btn btn-primary">Düzenle</a>
        <?php endif; ?>
        <?php if (!$is_deleted && can_beyan('delete')): ?>
        <form method="post" action="beyan_delete.php" style="display:inline"
              onsubmit="return confirm('Bu beyan arşivden gizlenecek. Devam edilsin mi?')">
            <input type="hidden" name="id"   value="<?= $id ?>">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button type="submit" class="btn btn-ghost" style="color:var(--danger)">Sil</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_deleted): ?>
<div class="flash flash-error">
    Bu beyan <?= h(fmt_datetime($beyan['deleted_at'])) ?> tarihinde arşivlendi (silinmiş).
</div>
<?php endif; ?>

<!-- ── Özet üst satır ── -->
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <?php if ($beyan['product_name']): ?>
    <div class="beyan-section" style="flex:1;min-width:200px;margin-bottom:0">
        <div class="beyan-section-title">Ürün</div>
        <div style="font-size:1.1rem;font-weight:700"><?= h($beyan['product_name']) ?><?= $beyan['product_variety'] ? ' · ' . h($beyan['product_variety']) : '' ?></div>
    </div>
    <?php endif; ?>
    <?php if ($beyan['net_kg'] !== null): ?>
    <div class="beyan-section" style="flex:1;min-width:120px;margin-bottom:0;text-align:center">
        <div class="beyan-section-title">Net KG</div>
        <div style="font-size:1.3rem;font-weight:700;color:var(--primary)"><?= fmt_kg($beyan['net_kg']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($beyan['pallet_count'] !== null): ?>
    <div class="beyan-section" style="flex:1;min-width:100px;margin-bottom:0;text-align:center">
        <div class="beyan-section-title">Palet</div>
        <div style="font-size:1.3rem;font-weight:700"><?= (int)$beyan['pallet_count'] ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- 1. Temel Bilgiler -->
<div class="beyan-section">
    <div class="beyan-section-title">📋 Temel Bilgiler</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row">
            <span class="lbl">Parti No</span>
            <span class="val"><?= h($beyan['party_no'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Başlık / Beyan Tipi</span>
            <span class="val"><?= h($beyan['declaration_title'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Nakliye Türü</span>
            <span class="val"><?= h($beyan['transport_type'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Hat / Güzergah</span>
            <span class="val"><?= h($beyan['line_type'] ?: '—') ?></span>
        </div>
        <?php if ($beyan['company_name']): ?>
        <div class="beyan-view-row" style="grid-column:1/-1">
            <span class="lbl">Şirket Adı</span>
            <span class="val"><?= h($beyan['company_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($beyan['company_address']): ?>
        <div class="beyan-view-row" style="grid-column:1/-1">
            <span class="lbl">Şirket Adresi</span>
            <span class="val" style="white-space:pre-wrap"><?= h($beyan['company_address']) ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Ürün Bilgileri -->
<div class="beyan-section">
    <div class="beyan-section-title">🍎 Ürün Bilgileri</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row">
            <span class="lbl">Ürün Adı</span>
            <span class="val"><?= h($beyan['product_name'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Ürün Çeşidi</span>
            <span class="val"><?= h($beyan['product_variety'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Palet Adedi</span>
            <span class="val"><?= $beyan['pallet_count'] !== null ? (int)$beyan['pallet_count'] : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Brüt KG</span>
            <span class="val"><?= $beyan['gross_kg'] !== null ? fmt_kg($beyan['gross_kg']) : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Net KG</span>
            <span class="val strong"><?= $beyan['net_kg'] !== null ? fmt_kg($beyan['net_kg']) : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Kasa Adedi</span>
            <span class="val"><?= $beyan['crate_count'] !== null ? number_format((int)$beyan['crate_count'], 0, ',', '.') : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Kasa Cinsi</span>
            <span class="val"><?= h($beyan['crate_type'] ?: '—') ?></span>
        </div>
    </div>
</div>

<!-- 3. Lojistik / Alıcı -->
<div class="beyan-section">
    <div class="beyan-section-title">🚚 Lojistik / Alıcı</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row">
            <span class="lbl">Çıkış Depo</span>
            <span class="val"><?= h($beyan['exit_depot'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Alıcı</span>
            <span class="val"><?= h($beyan['buyer_name'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">İlgili Kişi</span>
            <span class="val"><?= h($beyan['contact_person'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Marka</span>
            <span class="val"><?= h($beyan['brand'] ?: '—') ?></span>
        </div>
    </div>
</div>

<!-- 4. Analiz / Durum -->
<div class="beyan-section">
    <div class="beyan-section-title">📊 Analiz / Durum</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row">
            <span class="lbl">Durum</span>
            <span class="val"><?= beyan_badge_html($cur_status) ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Numune Alındı</span>
            <span class="val"><?= $beyan['sample_taken_at'] ? h(fmt_datetime($beyan['sample_taken_at'])) : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Analiz Sonuç Tarihi</span>
            <span class="val"><?= $beyan['analysis_result_at'] ? h(fmt_datetime($beyan['analysis_result_at'])) : '—' ?></span>
        </div>
    </div>
    <?php if ($beyan['analysis_note']): ?>
    <div style="margin-top:10px;padding:10px;background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius-sm)">
        <div class="muted" style="font-size:.78rem;margin-bottom:4px">Analiz Notu</div>
        <div style="white-space:pre-wrap"><?= h($beyan['analysis_note']) ?></div>
    </div>
    <?php endif; ?>

    <?php
    $next_states = beyan_next_statuses($cur_status);
    if (!empty($next_states) && !$is_deleted && can_beyan('write')):
    ?>
    <div style="margin-top:10px">
        <div class="muted" style="font-size:.82rem;margin-bottom:6px">Hızlı Durum Geçişi:</div>
        <div class="beyan-next-status-wrap">
            <?php foreach ($next_states as $ns):
                $sv = $statuses[$ns] ?? ['label' => $ns, 'css' => 'taslak'];
            ?>
            <form method="post" action="beyan_edit.php?id=<?= $id ?>" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="status" value="<?= h($ns) ?>">
                <?php
                // Durum değiştirilirken tüm mevcut alanları hidden olarak gönder
                foreach (['raw_text','unmatched_text','declaration_title','company_name','company_address',
                          'transport_type','line_type','party_no','pallet_count','product_name','product_variety',
                          'gross_kg','net_kg','crate_count','crate_type','exit_depot','contact_person',
                          'buyer_name','brand','analysis_note','sample_taken_at','analysis_result_at'] as $hf):
                    $hval = '';
                    if ($hf === 'pallet_count') $hval = $beyan['pallet_count'] !== null ? (string)(int)$beyan['pallet_count'] : '';
                    elseif ($hf === 'crate_count') $hval = $beyan['crate_count'] !== null ? (string)(int)$beyan['crate_count'] : '';
                    elseif ($hf === 'gross_kg') $hval = $beyan['gross_kg'] !== null ? number_format((float)$beyan['gross_kg'], 3, '.', '') : '';
                    elseif ($hf === 'net_kg')   $hval = $beyan['net_kg']   !== null ? number_format((float)$beyan['net_kg'],   3, '.', '') : '';
                    elseif ($hf === 'sample_taken_at')    $hval = $beyan['sample_taken_at']    ? date('Y-m-d\TH:i', strtotime($beyan['sample_taken_at']))    : '';
                    elseif ($hf === 'analysis_result_at') $hval = $beyan['analysis_result_at'] ? date('Y-m-d\TH:i', strtotime($beyan['analysis_result_at'])) : '';
                    else   $hval = (string)($beyan[$hf] ?? '');
                ?>
                <input type="hidden" name="<?= h($hf) ?>" value="<?= h($hval) ?>">
                <?php endforeach; ?>
                <button type="submit"
                        class="btn btn-sm beyan-badge beyan-badge-<?= h($sv['css']) ?>"
                        style="border:none;cursor:pointer;padding:4px 12px;font-size:.75rem"
                        <?= ($ns === 'red') ? 'onclick="return prompt_note(this)"' : '' ?>>
                    → <?= h($sv['label']) ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 5. WhatsApp Ham Metni (collapsible) -->
<?php if ($beyan['raw_text']): ?>
<div class="beyan-section">
    <button type="button" class="beyan-collapsible-toggle" onclick="toggleCollapsible(this)">
        📱 WhatsApp Ham Metni <span>▶</span>
    </button>
    <div class="beyan-collapsible-body" style="display:none">
<?= nl2br(h($beyan['raw_text'])) ?>
    </div>
</div>
<?php endif; ?>

<!-- 6. Eşleşmeyen Satırlar (collapsible) -->
<?php if ($beyan['unmatched_text']): ?>
<div class="beyan-section">
    <button type="button" class="beyan-collapsible-toggle" onclick="toggleCollapsible(this)">
        ⚠️ Eşleşmeyen / Kontrol Edilecek Satırlar <span>▶</span>
    </button>
    <div class="beyan-collapsible-body" style="display:none">
<?= nl2br(h($beyan['unmatched_text'])) ?>
        <button type="button" class="btn btn-sm btn-ghost" style="margin-top:8px"
                onclick="copyText(this.previousElementSibling)">📋 Kopyala</button>
    </div>
</div>
<?php endif; ?>

<!-- 7. Yükleme Planı Bağlantısı -->
<div class="beyan-section">
    <div class="beyan-section-title">🔗 Yükleme Planı Bağlantısı</div>
    <?php if ($linked_record): ?>
    <p>
        <a href="record_view.php?id=<?= (int)$linked_record['id'] ?>" class="btn btn-sm btn-ghost">
            → Yükleme Planını Aç: <?= h($linked_record['parti_no'] ?: '#' . $linked_record['id']) ?>
        </a>
    </p>
    <?php elseif (!$is_deleted && $cur_status === 'temiz'): ?>
    <p class="muted" style="font-size:.88rem">
        Yükleme planı bağlanmadı.
        <button type="button" class="btn btn-sm" disabled title="Sonraki sprintte aktif olacak">
            Yükleme Planı Oluştur (yakında)
        </button>
    </p>
    <?php else: ?>
    <p class="muted" style="font-size:.88rem">Henüz yükleme planına bağlanmadı.</p>
    <?php endif; ?>
</div>

<!-- Sistem bilgisi -->
<div class="muted" style="font-size:.8rem;margin:12px 0 24px;text-align:right">
    Oluşturma: <?= h(fmt_datetime($beyan['created_at'])) ?>
    <?php if ($beyan['updated_at']): ?>
    · Güncelleme: <?= h(fmt_datetime($beyan['updated_at'])) ?>
    <?php endif; ?>
</div>

<script>
function toggleCollapsible(btn) {
    var body = btn.nextElementSibling;
    var arrow = btn.querySelector('span');
    if (!body) return;
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (arrow) arrow.textContent = open ? '▶' : '▼';
}

function copyText(el) {
    var text = el ? el.textContent : '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            el.style.background = '#d1fae5';
            setTimeout(function () { el.style.background = ''; }, 1500);
        });
    }
}

function prompt_note(btn) {
    var note = prompt('Red için analiz notu giriniz (zorunlu):');
    if (!note || !note.trim()) return false;
    // Formun içindeki analysis_note hidden input'unu güncelle
    var form = btn.closest('form');
    if (!form) return true;
    var noteInput = form.querySelector('input[name="analysis_note"]');
    if (noteInput) noteInput.value = note.trim();
    return true;
}
</script>

<?php render_footer(); ?>
