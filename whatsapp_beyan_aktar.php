<?php
// =========================================================
// whatsapp_beyan_aktar.php — ASYA WHATSAPP WEB BRIDGE (Aşama 1)
//
// Bağımsız Chrome eklentisi eşleştirme, son aktarılan mesajları takip
// ve yapay zekâ beyan taslağı inceleme/onay ekranı.
//
// Mevcut whatsapp_yonetim.php ve beyan_create.php dosyalarına DOKUNMAZ.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/chrome_whatsapp_bridge.php';

$auth_user = require_login();
if (!can_beyan('read') && !can_beyan('create')) {
    forbidden();
}

$pdo = db();
cw_bridge_ensure_schema($pdo);
$userId = (int)($auth_user['id'] ?? 0);
$canCreate = can_beyan('create');

$newPairing = null;
$reviewErrors = [];
$reviewId = (int)($_GET['incele'] ?? $_POST['bridge_message_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'create_pairing') {
        $newPairing = cw_bridge_create_pairing_code($pdo, $userId, CW_BRIDGE_PAIR_TTL_SEC);
    } elseif ($op === 'revoke_token') {
        $tokId = (int)($_POST['token_id'] ?? 0);
        if ($tokId > 0 && cw_bridge_revoke_token($pdo, $tokId, $userId)) {
            set_flash('success', 'Chrome eklentisi bağlantısı kesildi.');
        } else {
            set_flash('error', 'Bağlantı bulunamadı veya zaten iptal edilmiş.');
        }
        header('Location: whatsapp_beyan_aktar.php');
        exit;
    } elseif ($op === 'approve_draft') {
        if (!$canCreate) {
            forbidden();
        }
        $msgId = (int)($_POST['bridge_message_id'] ?? 0);
        $fields = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
        $res = cw_bridge_create_beyan_draft($pdo, $fields, $auth_user, $msgId > 0 ? $msgId : null, true);
        if ($res['ok']) {
            set_flash('success', 'Beyan taslağı #' . (int)$res['declaration_id'] . ' başarıyla oluşturuldu (WhatsApp kaynaklı).');
            header('Location: ' . (string)$res['view_url']);
            exit;
        }
        $reviewErrors = $res['errors'] ?? ['Taslak oluşturulamadı.'];
        $reviewId = $msgId;
    }
}

$tokens = cw_bridge_list_tokens($pdo, $userId);
$schema = cw_bridge_beyan_schema($pdo, true);

// Son aktarılan mesajlar (admin tümünü, diğer kullanıcılar kendininkini görür)
if (function_exists('is_admin') && is_admin()) {
    $st = $pdo->query('SELECT m.*, u.display_name AS user_display_name, u.username AS user_username
        FROM cw_bridge_messages m
        LEFT JOIN users u ON u.id = m.user_id
        ORDER BY m.id DESC LIMIT 40');
    $messages = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $pdo->prepare('SELECT m.*, u.display_name AS user_display_name, u.username AS user_username
        FROM cw_bridge_messages m
        LEFT JOIN users u ON u.id = m.user_id
        WHERE m.user_id = ?
        ORDER BY m.id DESC LIMIT 40');
    $st->execute([$userId]);
    $messages = $st->fetchAll(PDO::FETCH_ASSOC);
}

// İnceleme için seçili mesaj
$reviewRow = null;
$reviewAi = null;
$reviewFormValues = [];
$reviewRefWarnings = [];
if ($reviewId > 0) {
    $sql = (function_exists('is_admin') && is_admin())
        ? 'SELECT * FROM cw_bridge_messages WHERE id = ? LIMIT 1'
        : 'SELECT * FROM cw_bridge_messages WHERE id = ? AND user_id = ? LIMIT 1';
    $rst = $pdo->prepare($sql);
    $rst->execute((function_exists('is_admin') && is_admin()) ? [$reviewId] : [$reviewId, $userId]);
    $reviewRow = $rst->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($reviewRow) {
        $reviewAi = json_decode((string)($reviewRow['ai_result_json'] ?? ''), true);
        if (!is_array($reviewAi)) $reviewAi = [];
        $aiFields = is_array($reviewAi['form_fields'] ?? null) ? $reviewAi['form_fields'] : [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'approve_draft' && is_array($_POST['f'] ?? null)) {
            $reviewFormValues = array_merge($aiFields, $_POST['f']);
        } else {
            $reviewFormValues = $aiFields;
        }
        if (empty($reviewFormValues['raw_text'])) {
            $reviewFormValues['raw_text'] = (string)$reviewRow['raw_text'];
        }
        if (empty($reviewFormValues['status'])) {
            $reviewFormValues['status'] = 'taslak';
        }
        $reviewRefWarnings = cw_bridge_find_reference_warnings($pdo, (string)($reviewFormValues['party_no'] ?? $reviewRow['reference_key'] ?? ''), (int)$reviewRow['id']);
    }
}

$statusLabels = [
    'parsed'        => ['İnceleme Bekliyor', '#b45309', '#fef3c7'],
    'draft_created' => ['Taslak Oluşturuldu', '#166534', '#dcfce7'],
    'duplicate'     => ['Mükerrer', '#475569', '#e2e8f0'],
    'error'         => ['Hata', '#991b1b', '#fee2e2'],
    'rejected'      => ['Beyan Değil', '#64748b', '#f1f5f9'],
];

render_header('WhatsApp Beyan Aktar (Chrome Bridge)');
render_flash();
?>
<style>
.cwb-wrap { max-width: 1220px; margin: 0 auto; padding: 12px 0 36px; }
.cwb-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
@media (max-width: 900px) { .cwb-grid { grid-template-columns: 1fr; } }
.cwb-card { background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 16px; }
.cwb-card h2 { margin: 0 0 10px; font-size: 16px; }
.cwb-code-box { background: #f0fdf4; border: 1px dashed #16a34a; border-radius: 8px; padding: 12px 14px; margin-top: 12px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.cwb-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 20px; font-weight: 700; letter-spacing: 1px; color: #14532d; }
.cwb-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.cwb-review { display: grid; grid-template-columns: 360px 1fr; gap: 18px; margin: 18px 0 24px; }
@media (max-width: 960px) { .cwb-review { grid-template-columns: 1fr; } }
.cwb-raw { white-space: pre-wrap; word-break: break-word; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; font-size: 13px; line-height: 1.5; max-height: 420px; overflow: auto; }
.cwb-form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; }
.cwb-field { display: flex; flex-direction: column; gap: 4px; }
.cwb-field.is-uncertain input,
.cwb-field.is-uncertain select,
.cwb-field.is-uncertain textarea { border-color: #d97706; background: #fffbeb; }
.cwb-field-label { font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.cwb-pill-warn { background: #fef3c7; color: #92400e; font-size: 10px; padding: 1px 6px; border-radius: 4px; font-weight: 600; }
.cwb-section-title { margin: 14px 0 8px; font-size: 13px; font-weight: 700; color: var(--muted, #475569); border-bottom: 1px solid var(--border, #e2e8f0); padding-bottom: 4px; }
.cwb-alert { border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; font-size: 13px; }
.cwb-alert-warn { background: #fffbeb; border: 1px solid #f59e0b; color: #78350f; }
.cwb-alert-err { background: #fef2f2; border: 1px solid #ef4444; color: #7f1d1d; }
.cwb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.cwb-table th, .cwb-table td { padding: 8px 10px; border-bottom: 1px solid var(--border, #e2e8f0); text-align: left; vertical-align: top; }
</style>

<div class="cwb-wrap">
    <div class="page-head" style="margin-bottom:14px;">
        <div>
            <h1>🧩 WhatsApp Beyan Aktar (Chrome Bridge)</h1>
            <p class="muted">WhatsApp Web Chrome eklentisi ile beyan mesajlarını yapay zekâ eşleştirmesiyle inceleyip Yeni Beyan taslağı oluşturun.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="beyanlar.php" class="btn btn-ghost">Beyanlar</a>
            <?php if ($canCreate): ?><a href="beyan_create.php" class="btn btn-secondary">+ Manuel Yeni Beyan</a><?php endif; ?>
        </div>
    </div>

    <?php if ($reviewRow !== null):
        $uncertainSet = array_flip(is_array($reviewAi['uncertain_fields'] ?? null) ? $reviewAi['uncertain_fields'] : []);
        $conflicts = is_array($reviewAi['conflicts'] ?? null) ? $reviewAi['conflicts'] : [];
        $missingReq = is_array($reviewAi['missing_required'] ?? null) ? $reviewAi['missing_required'] : [];
        $explanation = (string)($reviewAi['explanation'] ?? '');
        $confidence = (int)($reviewAi['confidence'] ?? $reviewRow['ai_confidence'] ?? 0);
        $alreadyDrafted = $reviewRow['declaration_id'] !== null;
    ?>
    <div class="cwb-card" style="margin-bottom:20px; border-color:#3b82f6;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
            <h2 style="margin:0;">🔍 Beyan Taslağı İnceleme (#<?= (int)$reviewRow['id'] ?>)</h2>
            <a href="whatsapp_beyan_aktar.php" class="btn btn-ghost btn-sm">İncelemeyi Kapat</a>
        </div>

        <?php if ($reviewErrors): ?>
        <div class="cwb-alert cwb-alert-err" role="alert">
            <?php foreach ($reviewErrors as $err): ?><div>• <?= h($err) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($reviewRefWarnings): ?>
        <div class="cwb-alert cwb-alert-warn" role="status">
            <strong>⚠️ Aynı Parti / Referans Uyarısı:</strong> Sistemde aynı parti numarasıyla başka kayıt(lar) var. Mevcut kayıtlar otomatik değiştirilmez:
            <ul style="margin:6px 0 0 18px;padding:0;">
                <?php foreach ($reviewRefWarnings as $w): ?>
                <li><?= $w['source'] === 'customs_declarations' ? 'Beyan #' . (int)$w['id'] : 'Aktarım #' . (int)$w['id'] ?> — Parti: <strong><?= h($w['party_no']) ?></strong> (<?= h($w['status']) ?>, <?= h($w['created_at']) ?>)</li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($conflicts): ?>
        <div class="cwb-alert cwb-alert-warn">
            <strong>⚠️ AI Çelişki Tespiti:</strong>
            <?php foreach ($conflicts as $c): ?><div>• <?= h($c) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="cwb-review">
            <div>
                <h3 style="margin:0 0 8px;font-size:14px;">A. WhatsApp Kaynak Mesajı</h3>
                <div style="font-size:12px;color:var(--muted,#64748b);margin-bottom:8px;line-height:1.6;">
                    <div><strong>Grup / Sohbet:</strong> <?= h((string)$reviewRow['chat_name']) ?> (<?= h((string)$reviewRow['chat_type']) ?>)</div>
                    <div><strong>Gönderen:</strong> <?= h((string)($reviewRow['sender_name'] ?: '—')) ?></div>
                    <div><strong>Mesaj Zamanı:</strong> <?= h((string)($reviewRow['message_timestamp'] ?: '—')) ?></div>
                    <div><strong>AI Sağlayıcı:</strong> <?= h((string)($reviewRow['ai_provider'] ?: '—')) ?> · Güven: <strong>%<?= $confidence ?></strong></div>
                </div>
                <div class="cwb-raw"><?= h((string)$reviewRow['raw_text']) ?></div>
                <?php if ($explanation !== ''): ?>
                <div style="margin-top:10px;font-size:12px;background:#f1f5f9;padding:8px 10px;border-radius:6px;">
                    <strong>AI Açıklaması:</strong> <?= h($explanation) ?>
                </div>
                <?php endif; ?>
                <?php if ($missingReq): ?>
                <div style="margin-top:8px;font-size:12px;color:#92400e;">
                    <strong>Eksik Zorunlu Alanlar:</strong> <?= h(implode(', ', $missingReq)) ?>
                </div>
                <?php endif; ?>
            </div>

            <div>
                <h3 style="margin:0 0 8px;font-size:14px;">B. Beyan Bilgileri (Gerçek Yeni Beyan Formu)</h3>
                <?php if ($alreadyDrafted): ?>
                    <div class="cwb-alert" style="background:#dcfce7;border:1px solid #16a34a;color:#14532d;">
                        Bu mesaj için zaten <strong>#<?= (int)$reviewRow['declaration_id'] ?></strong> numaralı beyan taslağı oluşturuldu.
                        <a href="beyan_view.php?id=<?= (int)$reviewRow['declaration_id'] ?>" class="btn btn-sm btn-primary" style="margin-left:8px;">Beyanı Aç</a>
                    </div>
                <?php else: ?>
                <form method="post" action="whatsapp_beyan_aktar.php?incele=<?= (int)$reviewRow['id'] ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="op" value="approve_draft">
                    <input type="hidden" name="bridge_message_id" value="<?= (int)$reviewRow['id'] ?>">

                    <datalist id="cwbHksFirmalar">
                        <?php foreach ($schema['hks_firmalar'] as $hf): ?>
                        <option value="<?= h($hf['ad']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <?php foreach ($schema['sections'] as $secKey => $secTitle):
                        $secFields = array_values(array_filter($schema['fields'], static fn($x) => $x['section'] === $secKey));
                        if (!$secFields) continue;
                    ?>
                        <div class="cwb-section-title"><?= h($secTitle) ?></div>
                        <div class="cwb-form-grid">
                        <?php foreach ($secFields as $fld):
                            $name = $fld['name'];
                            $permGrp = $fld['permission_group'] ?? null;
                            if ($permGrp !== null && !can_beyan($permGrp . '.read')) continue;
                            $disabled = ($permGrp !== null && !can_beyan($permGrp . '.write')) || ($name === 'status' && !can_beyan('status'));
                            $val = (string)($reviewFormValues[$name] ?? ($fld['default'] ?? ''));
                            $isUnc = isset($uncertainSet[$name]);
                        ?>
                            <div class="cwb-field<?= $isUnc ? ' is-uncertain' : '' ?>"<?= $fld['type'] === 'textarea' ? ' style="grid-column: 1 / -1;"' : '' ?>>
                                <label class="cwb-field-label" for="cwb_<?= h($name) ?>">
                                    <?= h($fld['label']) ?><?= !empty($fld['required']) ? ' *' : '' ?>
                                    <?php if ($isUnc): ?><span class="cwb-pill-warn">Kontrol Et</span><?php endif; ?>
                                </label>
                                <?php if ($name === 'status'): ?>
                                    <select id="cwb_status" name="f[status]" class="form-control"<?= $disabled ? ' disabled' : '' ?>>
                                        <?php foreach ($fld['options'] as $opt): ?>
                                        <option value="<?= h($opt['value']) ?>"<?= $val === $opt['value'] ? ' selected' : '' ?>><?= h($opt['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($name === 'hks_ulke_id'): ?>
                                    <select id="cwb_hks_ulke_id" name="f[hks_ulke_id]" class="form-control"<?= $disabled ? ' disabled' : '' ?>>
                                        <option value="">— seçilmedi —</option>
                                        <?php foreach ($schema['hks_ulkeler'] as $u): ?>
                                        <option value="<?= h($u['id']) ?>"<?= $val === (string)$u['id'] ? ' selected' : '' ?>><?= h($u['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($name === 'hks_urun_id'): ?>
                                    <select id="cwb_hks_urun_id" name="f[hks_urun_id]" class="form-control"<?= $disabled ? ' disabled' : '' ?>>
                                        <option value="">— otomatik / seçilmedi —</option>
                                        <?php foreach ($schema['hks_urunler'] as $u): ?>
                                        <option value="<?= h($u['id']) ?>"<?= $val === (string)$u['id'] ? ' selected' : '' ?>><?= h($u['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($fld['type'] === 'textarea'): ?>
                                    <textarea id="cwb_<?= h($name) ?>" name="f[<?= h($name) ?>]" rows="2" class="form-control"<?= $disabled ? ' disabled' : '' ?>><?= h($val) ?></textarea>
                                <?php elseif ($fld['type'] === 'datetime'): ?>
                                    <input type="datetime-local" id="cwb_<?= h($name) ?>" name="f[<?= h($name) ?>]" class="form-control" value="<?= h(str_replace(' ', 'T', substr($val, 0, 16))) ?>"<?= $disabled ? ' disabled' : '' ?>>
                                <?php else: ?>
                                    <input type="<?= $fld['type'] === 'tel' ? 'tel' : 'text' ?>" id="cwb_<?= h($name) ?>" name="f[<?= h($name) ?>]" class="form-control"
                                           value="<?= h($val) ?>"<?= $name === 'sender_company_name' ? ' list="cwbHksFirmalar" required' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;align-items:center;">
                        <span class="muted" style="font-size:12px;">Onayınızla mevcut Yeni Beyan altyapısında taslak açılır; resmî HKS bildirimi gönderilmez.</span>
                        <?php if ($canCreate): ?>
                        <button type="submit" class="btn btn-primary">✅ Onayla ve Beyan Taslağı Oluştur</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="cwb-grid">
        <div class="cwb-card">
            <h2>1. Chrome Eklentisi Eşleştirme</h2>
            <p class="muted" style="font-size:13px;margin:0 0 10px;">
                Tek kullanımlık, 10 dakika geçerli eşleştirme kodu üretin ve Chrome eklentisine yapıştırın. Token sunucuda hash olarak saklanır ve istediğiniz zaman iptal edilebilir.
            </p>
            <form method="post" action="whatsapp_beyan_aktar.php">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="op" value="create_pairing">
                <button type="submit" class="btn btn-primary">🔑 Yeni Eşleştirme Kodu Üret</button>
            </form>

            <?php if ($newPairing !== null): ?>
            <div class="cwb-code-box" id="cwbPairingResult">
                <div>
                    <div style="font-size:12px;color:#166534;">Tek Kullanımlık Eşleştirme Kodu (Son geçerlilik: <?= h($newPairing['expires_at']) ?>)</div>
                    <div class="cwb-code" id="cwbPairingCode"><?= h($newPairing['code']) ?></div>
                </div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('cwbPairingCode').textContent)">Kopyala</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="cwb-card">
            <h2>2. Bağlı Chrome Eklentileri</h2>
            <?php if (!$tokens): ?>
                <p class="muted" style="font-size:13px;">Henüz eşleştirilmiş bir Chrome eklentisi yok.</p>
            <?php else: ?>
                <table class="cwb-table">
                    <thead>
                        <tr><th>Cihaz</th><th>Son Kullanım</th><th>Durum</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tokens as $tk): $revoked = $tk['revoked_at'] !== null; ?>
                        <tr>
                            <td><?= h((string)($tk['device_label'] ?: 'Chrome Eklentisi')) ?></td>
                            <td><?= h((string)($tk['last_used_at'] ?: $tk['created_at'])) ?></td>
                            <td>
                                <?php if ($revoked): ?>
                                    <span class="cwb-badge" style="background:#f1f5f9;color:#64748b;">İptal Edildi</span>
                                <?php else: ?>
                                    <span class="cwb-badge" style="background:#dcfce7;color:#166534;">Aktif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$revoked): ?>
                                <form method="post" action="whatsapp_beyan_aktar.php" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="op" value="revoke_token">
                                    <input type="hidden" name="token_id" value="<?= (int)$tk['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost" onclick="return confirm('Bu Chrome eklentisinin bağlantısı kesilsin mi?')">Bağlantıyı Kes</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="cwb-card">
        <h2>3. Son Aktarılan WhatsApp Mesajları ve Beyan Taslakları</h2>
        <?php if (!$messages): ?>
            <p class="muted" style="font-size:13px;">Henüz Chrome eklentisinden aktarılmış bir mesaj bulunmuyor. WhatsApp Web'de bir beyan grubunu açıp eklentiden <strong>BU GRUBU TARA</strong> butonuna basın.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="cwb-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Grup / Gönderen</th>
                        <th>Mesaj Özeti</th>
                        <th>Parti / Ref</th>
                        <th>AI Güven</th>
                        <th>Durum</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($messages as $m):
                    [$stLabel, $stColor, $stBg] = $statusLabels[(string)$m['status']] ?? [(string)$m['status'], '#334155', '#e2e8f0'];
                ?>
                    <tr>
                        <td>#<?= (int)$m['id'] ?></td>
                        <td>
                            <strong><?= h((string)$m['chat_name']) ?></strong><br>
                            <span class="muted" style="font-size:12px;"><?= h((string)($m['sender_name'] ?: '—')) ?> · <?= h((string)($m['message_timestamp'] ?: $m['created_at'])) ?></span>
                        </td>
                        <td style="max-width:320px;">
                            <div style="font-size:12px;color:#334155;"><?= h(mb_substr((string)$m['raw_text'], 0, 130, 'UTF-8')) ?><?= mb_strlen((string)$m['raw_text'], 'UTF-8') > 130 ? '…' : '' ?></div>
                            <?php if (!empty($m['error_message'])): ?>
                                <div style="font-size:11px;color:#991b1b;margin-top:3px;">Hata: <?= h((string)$m['error_message']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= h((string)($m['reference_key'] ?: '—')) ?></td>
                        <td><?= $m['ai_confidence'] !== null ? '%' . (int)$m['ai_confidence'] : '—' ?></td>
                        <td><span class="cwb-badge" style="background:<?= h($stBg) ?>;color:<?= h($stColor) ?>;"><?= h($stLabel) ?></span></td>
                        <td>
                            <?php if ($m['declaration_id'] !== null): ?>
                                <a href="beyan_view.php?id=<?= (int)$m['declaration_id'] ?>" class="btn btn-sm btn-primary">Beyan #<?= (int)$m['declaration_id'] ?></a>
                            <?php elseif ((string)$m['status'] === 'parsed'): ?>
                                <a href="whatsapp_beyan_aktar.php?incele=<?= (int)$m['id'] ?>" class="btn btn-sm btn-secondary">İncele &amp; Onayla</a>
                            <?php else: ?>
                                <a href="whatsapp_beyan_aktar.php?incele=<?= (int)$m['id'] ?>" class="btn btn-sm btn-ghost">Detay</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
render_footer();
