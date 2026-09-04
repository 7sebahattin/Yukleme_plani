<?php
// =========================================================
// beyan_edit.php — Beyan düzenleme (Sprint Beyan-01)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('write')) forbidden();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('error', 'Geçersiz beyan.'); header('Location: beyanlar.php'); exit; }

$st = db()->prepare("SELECT * FROM customs_declarations WHERE id = ? AND deleted_at IS NULL");
$st->execute([$id]);
$beyan = $st->fetch();
if (!$beyan) { set_flash('error', 'Beyan bulunamadı veya silinmiş.'); header('Location: beyanlar.php'); exit; }

$errors = [];

// Formu DB değerleri ile önceden doldur
$f = [
    'raw_text'           => (string)($beyan['raw_text']           ?? ''),
    'unmatched_text'     => (string)($beyan['unmatched_text']     ?? ''),
    'declaration_title'  => (string)($beyan['declaration_title']  ?? ''),
    'company_name'       => (string)($beyan['company_name']       ?? ''),
    'company_address'    => (string)($beyan['company_address']    ?? ''),
    'transport_type'     => (string)($beyan['transport_type']     ?? ''),
    'vehicle_plate'      => (string)($beyan['vehicle_plate']      ?? ''),
    'line_type'          => (string)($beyan['line_type']          ?? ''),
    'party_no'           => (string)($beyan['party_no']           ?? ''),
    'pallet_count'       => $beyan['pallet_count'] !== null ? (string)(int)$beyan['pallet_count'] : '',
    'product_name'       => (string)($beyan['product_name']       ?? ''),
    'product_variety'    => (string)($beyan['product_variety']    ?? ''),
    'gross_kg'           => fmt_edit_num($beyan['gross_kg'], 0),
    'net_kg'             => fmt_edit_num($beyan['net_kg'],   0),
    'crate_count'        => $beyan['crate_count'] !== null ? (string)(int)$beyan['crate_count'] : '',
    'crate_type'         => (string)($beyan['crate_type']         ?? ''),
    'exit_depot'         => (string)($beyan['exit_depot']         ?? ''),
    'contact_person'     => (string)($beyan['contact_person']     ?? ''),
    'buyer_name'         => (string)($beyan['buyer_name']         ?? ''),
    'brand'              => (string)($beyan['brand']              ?? ''),
    'status'             => (string)($beyan['status']             ?? 'beyan_acildi'),
    'sample_taken_at'    => $beyan['sample_taken_at']    ? date('Y-m-d\TH:i', strtotime($beyan['sample_taken_at']))    : '',
    'analysis_result_at' => $beyan['analysis_result_at'] ? date('Y-m-d\TH:i', strtotime($beyan['analysis_result_at'])) : '',
    'analysis_note'      => (string)($beyan['analysis_note']      ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old_status = $beyan['status'];

    // Yalnızca durum geçişi (liste ekranındaki hızlı butonlar) — diğer alanlara dokunma
    if (!empty($_POST['status_only'])) {
        $new_status     = (string)($_POST['status'] ?? '');
        $valid_statuses = array_keys(beyan_statuses());
        $allowed        = is_admin()
            ? $valid_statuses
            : array_merge([$old_status], beyan_next_statuses($old_status));
        if (!in_array($new_status, $valid_statuses, true) || !in_array($new_status, $allowed, true)) {
            set_flash('error', 'Geçersiz durum geçişi.');
            header('Location: beyanlar.php'); exit;
        }
        db()->prepare("UPDATE customs_declarations SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$new_status, (int)($auth_user['id'] ?? 0), $id]);
        if ($old_status !== $new_status) {
            audit_log_event('beyan_status_change', 'declarations', $id,
                ['status' => $old_status], ['status' => $new_status]);
        }
        set_flash('success', 'Durum güncellendi: ' . (beyan_statuses()[$new_status]['label'] ?? $new_status));
        header('Location: beyanlar.php'); exit;
    }

    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }

    // Büyük harf — seçili metin alanları
    foreach (['declaration_title', 'company_name', 'company_address', 'transport_type', 'vehicle_plate',
              'line_type', 'party_no', 'product_name', 'product_variety', 'crate_type',
              'exit_depot', 'contact_person', 'buyer_name', 'brand'] as $_tf) {
        if ($f[$_tf] !== '') $f[$_tf] = tr_upper($f[$_tf]);
    }

    // Validasyon
    if ($f['raw_text'] === '' && $f['party_no'] === '' && $f['product_name'] === '') {
        $errors[] = 'En az bir alan dolu olmalıdır: WhatsApp metni, Parti No veya Ürün Adı.';
    }

    $valid_statuses = array_keys(beyan_statuses());
    if (!in_array($f['status'], $valid_statuses, true)) {
        $f['status'] = $old_status;
    }

    // Red → analysis_note zorunlu
    if ($f['status'] === 'red' && trim($f['analysis_note']) === '') {
        $errors[] = '"Red" durumu için analiz notu zorunludur.';
    }

    // Sayı normalize
    $gross_kg  = $f['gross_kg']    !== '' ? num($f['gross_kg'])    : null;
    $net_kg    = $f['net_kg']      !== '' ? num($f['net_kg'])      : null;
    $pallet_ct = $f['pallet_count'] !== '' ? (int)num($f['pallet_count']) : null;
    $crate_ct  = $f['crate_count']  !== '' ? (int)num($f['crate_count'])  : null;

    $sample_at = ($f['sample_taken_at']    !== '' && strtotime($f['sample_taken_at']))    ? $f['sample_taken_at']    : null;
    $result_at = ($f['analysis_result_at'] !== '' && strtotime($f['analysis_result_at'])) ? $f['analysis_result_at'] : null;

    // temiz/red geçişinde analysis_result_at yoksa otomatik set
    if (in_array($f['status'], ['temiz', 'red'], true) && $result_at === null) {
        $result_at = date('Y-m-d H:i:s');
        $f['analysis_result_at'] = date('Y-m-d\TH:i');
    }

    if (empty($errors)) {
        $user_id = (int)($auth_user['id'] ?? 0);

        $st = db()->prepare("UPDATE customs_declarations SET
            raw_text = ?, unmatched_text = ?, declaration_title = ?,
            company_name = ?, company_address = ?,
            transport_type = ?, vehicle_plate = ?, line_type = ?, party_no = ?,
            pallet_count = ?, product_name = ?, product_variety = ?,
            gross_kg = ?, net_kg = ?, crate_count = ?, crate_type = ?,
            exit_depot = ?, contact_person = ?, buyer_name = ?, brand = ?,
            status = ?, analysis_note = ?, sample_taken_at = ?, analysis_result_at = ?,
            updated_by = ?, updated_at = NOW()
            WHERE id = ?");

        $st->execute([
            $f['raw_text']          ?: null,
            $f['unmatched_text']    ?: null,
            $f['declaration_title'] ?: null,
            $f['company_name']      ?: null,
            $f['company_address']   ?: null,
            $f['transport_type']    ?: null,
            $f['vehicle_plate']     ?: null,
            $f['line_type']         ?: null,
            $f['party_no']          ?: null,
            $pallet_ct,
            $f['product_name']      ?: null,
            $f['product_variety']   ?: null,
            $gross_kg,
            $net_kg,
            $crate_ct,
            $f['crate_type']        ?: null,
            $f['exit_depot']        ?: null,
            $f['contact_person']    ?: null,
            $f['buyer_name']        ?: null,
            $f['brand']             ?: null,
            $f['status'],
            $f['analysis_note']     ?: null,
            $sample_at,
            $result_at,
            $user_id,
            $id,
        ]);

        // Durum değişti ise ayrı audit
        if ($old_status !== $f['status']) {
            audit_log_event('beyan_status_change', 'declarations', $id,
                ['status' => $old_status],
                ['status' => $f['status'], 'analysis_note' => $f['analysis_note']]
            );
        } else {
            audit_log_event('beyan_update', 'declarations', $id, null, [
                'party_no'     => $f['party_no'],
                'product_name' => $f['product_name'],
                'status'       => $f['status'],
            ]);
        }

        set_flash('success', 'Beyan güncellendi.');
        header('Location: beyan_view.php?id=' . $id);
        exit;
    }
}

$statuses    = beyan_statuses();
$cur_status  = $beyan['status'];
$next_states = beyan_next_statuses($cur_status);
$is_terminal = in_array($cur_status, ['red', 'iptal', 'yukleme_olustu', 'yuklendi'], true);
$is_admin_   = is_admin();

render_header('Beyan Düzenle');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>✏️ Beyan Düzenle</h1>
        <p class="muted">
            <?= h($beyan['party_no'] ?: '(parti no yok)') ?>
            <?= beyan_badge_html($cur_status) ?>
        </p>
    </div>
    <a href="beyan_view.php?id=<?= $id ?>" class="btn btn-ghost">← Görüntüle</a>
</div>

<?php if ($errors): ?>
<div class="flash flash-error">
    <?php foreach ($errors as $e): ?>
    <div><?= h($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($is_terminal && !$is_admin_): ?>
<div class="flash flash-error">
    Bu beyan terminal durumda (<strong><?= h($statuses[$cur_status]['label'] ?? $cur_status) ?></strong>).
    Yalnızca yönetici geri alabilir.
</div>
<?php endif; ?>

<form method="post" action="beyan_edit.php?id=<?= $id ?>" data-beyan-form>
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<!-- 1. WhatsApp Ham Metni -->
<div class="beyan-section">
    <div class="beyan-section-title">📱 WhatsApp Metni</div>
    <div class="form-group">
        <label class="form-label">Ham Metin</label>
        <textarea name="raw_text" rows="8" class="form-control"
                  style="font-size:14px;font-family:monospace"><?= h($f['raw_text']) ?></textarea>
    </div>
    <div style="margin-top:8px">
        <button type="button" class="btn btn-secondary"
                data-beyan-parse-btn
                data-base-url="<?= h(base_url()) ?>">🔍 Metni Ayrıştır</button>
    </div>
    <div id="beyanParseStatus" hidden></div>
    <div class="form-group" style="margin-top:12px">
        <label class="form-label">Eşleşmeyen / Dikkat Edilecek Satırlar</label>
        <textarea name="unmatched_text" rows="3" class="form-control"
                  style="font-size:13px;font-family:monospace"><?= h($f['unmatched_text']) ?></textarea>
    </div>
</div>

<!-- 2. Temel Bilgiler -->
<div class="beyan-section">
    <div class="beyan-section-title">📋 Temel Bilgiler</div>
    <div class="beyan-form-grid">
        <div class="form-group">
            <label class="form-label">Başlık / Beyan Tipi</label>
            <input type="text" name="declaration_title" class="form-control"
                   value="<?= h($f['declaration_title']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Parti No</label>
            <input type="text" name="party_no" class="form-control"
                   value="<?= h($f['party_no']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Nakliye Türü</label>
            <input type="text" name="transport_type" class="form-control"
                   value="<?= h($f['transport_type']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Araç Plakası</label>
            <input type="text" name="vehicle_plate" class="form-control"
                   value="<?= h($f['vehicle_plate']) ?>" data-uppercase="tr"
                   placeholder="34 ABC 123">
            <small class="muted">Hal Kayıt bildirimi için zorunlu — girilmeden "Bildirim Yap" açılmaz.</small>
        </div>
        <div class="form-group">
            <label class="form-label">Hat / Güzergah</label>
            <input type="text" name="line_type" class="form-control"
                   value="<?= h($f['line_type']) ?>" data-uppercase="tr">
        </div>
    </div>
    <div class="form-group">
        <label class="form-label">Şirket Adı</label>
        <input type="text" name="company_name" class="form-control" value="<?= h($f['company_name']) ?>" data-uppercase="tr">
    </div>
    <div class="form-group">
        <label class="form-label">Şirket Adresi</label>
        <textarea name="company_address" rows="2" class="form-control" data-uppercase="tr"><?= h($f['company_address']) ?></textarea>
    </div>
</div>

<!-- 3. Ürün Bilgileri -->
<div class="beyan-section">
    <div class="beyan-section-title">🍎 Ürün Bilgileri</div>
    <div class="beyan-form-grid">
        <div class="form-group">
            <label class="form-label">Ürün Adı</label>
            <input type="text" name="product_name" class="form-control" value="<?= h($f['product_name']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Ürün Çeşidi</label>
            <input type="text" name="product_variety" class="form-control" value="<?= h($f['product_variety']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Palet Adedi</label>
            <input type="text" name="pallet_count" class="form-control"
                   inputmode="numeric" value="<?= h($f['pallet_count']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Brüt KG</label>
            <input type="text" name="gross_kg" class="form-control"
                   inputmode="decimal" value="<?= h($f['gross_kg']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Net KG</label>
            <input type="text" name="net_kg" class="form-control"
                   inputmode="decimal" value="<?= h($f['net_kg']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Kasa Adedi</label>
            <input type="text" name="crate_count" class="form-control"
                   inputmode="numeric" value="<?= h($f['crate_count']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Kasa Cinsi</label>
            <input type="text" name="crate_type" class="form-control" value="<?= h($f['crate_type']) ?>" data-uppercase="tr">
        </div>
    </div>
</div>

<!-- 4. Lojistik / Alıcı -->
<div class="beyan-section">
    <div class="beyan-section-title">🚚 Lojistik / Alıcı</div>
    <div class="beyan-form-grid">
        <div class="form-group">
            <label class="form-label">Çıkış Depo</label>
            <input type="text" name="exit_depot" class="form-control" value="<?= h($f['exit_depot']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Alıcı</label>
            <input type="text" name="buyer_name" class="form-control" value="<?= h($f['buyer_name']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">İlgili Kişi</label>
            <input type="text" name="contact_person" class="form-control" value="<?= h($f['contact_person']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Marka</label>
            <input type="text" name="brand" class="form-control" value="<?= h($f['brand']) ?>" data-uppercase="tr">
        </div>
    </div>
</div>

<!-- 5. Durum / Analiz -->
<div class="beyan-section">
    <div class="beyan-section-title">📊 Durum / Analiz</div>

    <?php if (!empty($next_states) || $is_admin_): ?>
    <div class="form-group">
        <label class="form-label">Durum</label>
        <select name="status" class="form-control">
            <?php
            // Admin → tüm durumlar; diğerleri → mevcut + izin verilenler
            $allowed = $is_admin_ ? array_keys($statuses) : array_merge([$cur_status], $next_states);
            foreach ($statuses as $sk => $sv):
                if (!in_array($sk, $allowed, true)) continue;
            ?>
            <option value="<?= h($sk) ?>"<?= $f['status'] === $sk ? ' selected' : '' ?>>
                <?= h($sv['label']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($is_terminal && !$is_admin_): ?>
        <div class="form-hint" style="color:var(--danger)">Terminal durum — geri alınamaz.</div>
        <?php elseif (!empty($next_states)): ?>
        <div class="form-hint">İzin verilen geçişler:
            <?php foreach ($next_states as $ns): ?>
            <strong><?= h($statuses[$ns]['label'] ?? $ns) ?></strong>
            <?php if ($ns !== end($next_states)): ?>, <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <input type="hidden" name="status" value="<?= h($cur_status) ?>">
    <div class="form-group">
        <label class="form-label">Durum</label>
        <div><?= beyan_badge_html($cur_status) ?> <span class="muted">(terminal — değiştirilemez)</span></div>
    </div>
    <?php endif; ?>

    <div class="beyan-form-grid" style="margin-top:10px">
        <div class="form-group">
            <label class="form-label">Numune Alındı Tarihi</label>
            <input type="datetime-local" name="sample_taken_at" class="form-control"
                   value="<?= h($f['sample_taken_at']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Analiz Sonuç Tarihi</label>
            <input type="datetime-local" name="analysis_result_at" class="form-control"
                   value="<?= h($f['analysis_result_at']) ?>">
            <div class="form-hint">Temiz/Red seçilince boşsa otomatik doldurulur.</div>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">
            Analiz Notu <span class="muted" id="noteRequired">(Red durumunda zorunlu)</span>
        </label>
        <textarea name="analysis_note" rows="3" class="form-control"
                  id="analysisNote"><?= h($f['analysis_note']) ?></textarea>
    </div>
</div>

<div style="display:flex;gap:10px;justify-content:flex-end;padding:8px 0 24px">
    <a href="beyan_view.php?id=<?= $id ?>" class="btn btn-ghost">İptal</a>
    <?php if (can_beyan('delete')): ?>
    <a href="beyan_delete.php?id=<?= $id ?>"
       class="btn btn-ghost" style="color:var(--danger)"
       onclick="return confirm('Bu beyanı arşivden gizlemek istediğinizden emin misiniz?')">Sil</a>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Güncelle</button>
</div>

</form>

<script>
(function () {
    var statusSel  = document.querySelector('select[name="status"]');
    var noteReq    = document.getElementById('noteRequired');
    var analysisNt = document.getElementById('analysisNote');
    if (!statusSel || !noteReq) return;

    function checkStatus() {
        var isRed = statusSel.value === 'red';
        noteReq.style.color = isRed ? 'var(--danger)' : '';
        noteReq.textContent  = isRed ? '(Red durumu — ZORUNLU)' : '(Red durumunda zorunlu)';
        if (analysisNt) analysisNt.style.borderColor = isRed && analysisNt.value.trim() === '' ? 'var(--danger)' : '';
    }

    statusSel.addEventListener('change', checkStatus);
    checkStatus();
})();
</script>

<?php render_footer(); ?>
