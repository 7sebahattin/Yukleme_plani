<?php
// Palet Fişleri — yönetim tablosu. Yalnız yönetici.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }

$pdo = db();
$has_aciklama1 = ensure_column('loading_pallets', 'aciklama1', "ALTER TABLE `loading_pallets` ADD COLUMN `aciklama1` TEXT NULL");
$has_aciklama2 = ensure_column('loading_pallets', 'aciklama2', "ALTER TABLE `loading_pallets` ADD COLUMN `aciklama2` TEXT NULL");

function pf_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pf_require_note_columns(): void {
    global $has_aciklama1, $has_aciklama2;
    if (!$has_aciklama1 || !$has_aciklama2) throw new RuntimeException('Açıklama alanları için veritabanı güncellemesi gerekli. DB kullanıcısına ALTER TABLE yetkisi verin.');
}

function pf_input_text(string $key, int $limit = 500): string {
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $limit, 'UTF-8');
}

function pf_valid_date(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function pf_get_row(int $id): array {
    $st = db()->prepare("SELECT p.*, r.id AS belge_no, r.parti_no, r.firma, r.urun, r.tarih, r.durum, r.locked_at,
                                COALESCE(md.name, p.palet_no, '') AS palet_adi
                         FROM loading_pallets p
                         JOIN loading_records r ON r.id = p.loading_record_id
                         LEFT JOIN material_definitions md ON md.id = p.palet_tipi_id AND md.type = 'palet_tipi'
                         WHERE p.id = ? AND r.type = 'yukleme'");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Palet fişi bulunamadı.');
    return $row;
}

function pf_save_fields(int $id): void {
    pf_require_note_columns();
    $old = pf_get_row($id);
    if ((!empty($old['locked_at']) || ($old['durum'] ?? '') === 'yuklendi') && !can('records.unlock')) {
        throw new RuntimeException('Bu palet fişi kilitli. Değişiklik için records.unlock yetkisi gereklidir.');
    }
    $pdo = db();
    $firma = pf_input_text('firma', 150);
    $parti = pf_input_text('parti_no', 100);
    $palet_tipi_id = (int)($_POST['palet_tipi_id'] ?? 0);
    $adet = max(0, min(1000000, intval_safe($_POST['kasa_adeti'] ?? 0)));
    $brut = max(0, min(999999999, parse_decimal($_POST['brut_kg'] ?? 0)));
    $dara = max(0, min(999999999, parse_decimal($_POST['dara_kg'] ?? 0)));
    $net = round(max(0, $brut - $dara), 3);
    $aciklama1 = pf_input_text('aciklama1', 2000);
    $aciklama2 = pf_input_text('aciklama2', 2000);
    if ($firma === '') throw new RuntimeException('Ünvan boş bırakılamaz.');
    if ($palet_tipi_id > 0) {
        $def = $pdo->prepare("SELECT id FROM material_definitions WHERE id = ? AND type = 'palet_tipi'");
        $def->execute([$palet_tipi_id]);
        if (!$def->fetchColumn()) throw new RuntimeException('Palet tipi tanımı geçerli değil.');
    } else {
        $palet_tipi_id = 0;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE loading_records SET firma = ?, parti_no = ? WHERE id = ?')
            ->execute([$firma, $parti, (int)$old['loading_record_id']]);
        $sql = 'UPDATE loading_pallets SET palet_tipi_id = ?, kasa_adeti = ?, brut_kg = ?, dara_kg = ?, net_kg = ?, aciklama1 = ?, aciklama2 = ? WHERE id = ?';
        $pdo->prepare($sql)->execute([$palet_tipi_id ?: null, $adet, $brut, $dara, $net, $aciklama1, $aciklama2, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    sync_malzeme_kullanim((int)$old['loading_record_id']);
    audit_log_event('update', 'palet_fisleri', $id, $old, ['firma' => $firma, 'parti_no' => $parti, 'kasa_adeti' => $adet, 'brut_kg' => $brut, 'dara_kg' => $dara, 'net_kg' => $net]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pf_action'])) {
    csrf_check($_POST['csrf'] ?? null);
    try {
        $action = (string)$_POST['pf_action'];
        if ($action === 'create') {
            pf_require_note_columns();
            $firma = pf_input_text('firma', 150);
            $parti = pf_input_text('parti_no', 100);
            $urun = pf_input_text('urun', 150);
            $tarih = pf_input_text('tarih', 10);
            $palet_tipi_id = (int)($_POST['palet_tipi_id'] ?? 0);
            $adet = max(0, min(1000000, intval_safe($_POST['kasa_adeti'] ?? 0)));
            $brut = max(0, min(999999999, parse_decimal($_POST['brut_kg'] ?? 0)));
            $dara = max(0, min(999999999, parse_decimal($_POST['dara_kg'] ?? 0)));
            $net = round(max(0, $brut - $dara), 3);
            $aciklama1 = pf_input_text('aciklama1', 2000);
            $aciklama2 = pf_input_text('aciklama2', 2000);
            if ($firma === '' || $urun === '' || !pf_valid_date($tarih)) throw new RuntimeException('Ünvan, ürün ve geçerli tarih zorunludur.');
            if ($palet_tipi_id > 0) {
                $def = $pdo->prepare("SELECT id FROM material_definitions WHERE id = ? AND type = 'palet_tipi' AND is_active = 1");
                $def->execute([$palet_tipi_id]);
                if (!$def->fetchColumn()) throw new RuntimeException('Palet tipi tanımı geçerli değil.');
            } else $palet_tipi_id = null;
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO loading_records (type, tarih, firma, parti_no, urun) VALUES ('yukleme', ?, ?, ?, ?)")
                    ->execute([$tarih, $firma, $parti, $urun]);
                $record_id = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO loading_pallets
                    (loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg, kasa_cinsi_id, palet_tipi_id, urun_cinsi, depo, sira_no, islendi, aciklama1, aciklama2)
                    VALUES (?, '', ?, '', ?, ?, ?, NULL, ?, ?, ?, 0, 0, ?, ?)")
                    ->execute([$record_id, $adet, $brut, $dara, $net, $palet_tipi_id, $urun, active_depot() ?? '', $aciklama1, $aciklama2]);
                $pallet_id = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            sync_malzeme_kullanim($record_id);
            audit_log_event('create', 'palet_fisleri', $pallet_id, null, ['belge_no' => $record_id, 'firma' => $firma, 'parti_no' => $parti, 'brut_kg' => $brut, 'net_kg' => $net]);
            pf_json(['ok' => true, 'id' => $pallet_id]);
        }

        $id = max(0, (int)($_POST['id'] ?? 0));
        if ($id <= 0) throw new RuntimeException('Geçersiz palet fişi.');
        if ($action === 'save') {
            pf_save_fields($id);
            pf_json(['ok' => true]);
        }
        if ($action === 'delete') {
            $old = pf_get_row($id);
            if ((!empty($old['locked_at']) || ($old['durum'] ?? '') === 'yuklendi') && !can('records.unlock')) throw new RuntimeException('Kilitli kayıtlar silinemez. Silmek için records.unlock yetkisi gereklidir.');
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM pallet_materials WHERE loading_pallet_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM loading_pallets WHERE id = ?')->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            sync_malzeme_kullanim((int)$old['loading_record_id']);
            audit_log_event('delete', 'palet_fisleri', $id, $old, null);
            pf_json(['ok' => true]);
        }
        if ($action === 'duplicate') {
            pf_require_note_columns();
            $old = pf_get_row($id);
            if ((!empty($old['locked_at']) || ($old['durum'] ?? '') === 'yuklendi') && !can('records.unlock')) throw new RuntimeException('Kilitli kayıtlar kopyalanamaz. Kopyalamak için records.unlock yetkisi gereklidir.');
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO loading_records (type, tarih, firma, parti_no, urun) VALUES ('yukleme', ?, ?, ?, ?)")
                    ->execute([$old['tarih'], $old['firma'], $old['parti_no'], $old['urun']]);
                $new_record_id = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO loading_pallets
                    (loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg, kasa_cinsi_id, palet_tipi_id, urun_cinsi, depo, sira_no, islendi, aciklama1, aciklama2)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)")
                    ->execute([$new_record_id, $old['palet_no'], $old['kasa_adeti'], $old['size'], $old['brut_kg'], $old['dara_kg'], $old['net_kg'], $old['kasa_cinsi_id'], $old['palet_tipi_id'], $old['urun_cinsi'], $old['depo'], $old['aciklama1'] ?? '', $old['aciklama2'] ?? '']);
                $new_id = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO pallet_materials (loading_pallet_id, material_id, quantity, total_dara_kg)
                               SELECT ?, material_id, quantity, total_dara_kg FROM pallet_materials WHERE loading_pallet_id = ?")
                    ->execute([$new_id, $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            sync_malzeme_kullanim($new_record_id);
            audit_log_event('create', 'palet_fisleri', $new_id, null, ['duplicate_of' => $id, 'belge_no' => $new_record_id]);
            pf_json(['ok' => true, 'id' => $new_id]);
        }
        throw new RuntimeException('Geçersiz işlem.');
    } catch (Throwable $e) {
        pf_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

$palet_tipi_list = get_definitions_by_type('palet_tipi');
$desc1_sql = $has_aciklama1 ? 'p.aciklama1' : "'' AS aciklama1";
$desc2_sql = $has_aciklama2 ? 'p.aciklama2' : "'' AS aciklama2";
$st = $pdo->query("SELECT p.id, p.loading_record_id, p.palet_no, p.kasa_adeti, p.size, p.brut_kg, p.dara_kg, p.net_kg,
                          p.palet_tipi_id, $desc1_sql, $desc2_sql,
                          r.id AS belge_no, r.tarih, r.parti_no, r.firma, r.urun,
                          COALESCE(md.name, p.palet_no, '') AS palet_adi
                   FROM loading_pallets p
                   JOIN loading_records r ON r.id = p.loading_record_id
                   LEFT JOIN material_definitions md ON md.id = p.palet_tipi_id AND md.type = 'palet_tipi'
                   WHERE r.type = 'yukleme'
                   ORDER BY r.id DESC, p.sira_no ASC, p.id DESC");
$rows = $st->fetchAll();

render_header('Palet Fişleri');
?>
<link rel="stylesheet" href="assets/palet-fisleri.css?v=<?= (int)@filemtime(__DIR__ . '/assets/palet-fisleri.css') ?>">
<section class="pf-page" id="pfPage" data-csrf="<?= h(csrf_token()) ?>">
    <div class="pf-top">
        <div class="pf-title"><div><h1>Palet Fişleri</h1><p>Yeni kayıtlar listenin en üstünde görünür.</p></div>
            <button type="button" class="btn btn-primary" id="pfAdd" <?= (!$has_aciklama1 || !$has_aciklama2) ? 'disabled' : '' ?>>＋ Yeni Palet Fişi <span class="pf-shortcut">Ctrl + N</span></button>
        </div>
        <div class="pf-tools">
            <label class="pf-search"><span aria-hidden="true">⌕</span><input id="pfSearch" type="search" placeholder="Belge, parti, palet veya ünvan ara" autocomplete="off"></label>
            <div class="pf-hints"><span>Hücre seç: tıkla</span><span>Düzenle: çift tık / F2</span><span>Kopyala: Ctrl + Shift + D</span><span>Sil: Delete</span></div>
        </div>
    </div>
    <?php if (!$has_aciklama1 || !$has_aciklama2): ?><div class="flash flash-error">Açıklama alanları için veritabanı güncellemesi tamamlanamadı. DB kullanıcısına ALTER TABLE yetkisi verin.</div><?php endif; ?>
    <div class="pf-table-wrap" id="pfTableWrap" tabindex="0" aria-label="Palet fişleri tablosu">
        <table class="pf-table" id="pfTable">
            <thead><tr>
                <th data-col="belge_no">Belge No</th><th data-col="parti_no">Parti No</th><th data-col="palet_adi">Palet</th><th data-col="firma">Ünvan</th>
                <th data-col="kasa_adeti">Adet</th><th data-col="brut_kg">BRÜT</th><th data-col="dara_kg">Dara</th><th data-col="net_kg">NET</th>
                <th data-col="aciklama1">Açıklama-1</th><th data-col="aciklama2">Açıklama-2</th>
            </tr></thead>
            <tbody id="pfBody">
            <?php foreach ($rows as $r): ?>
                <tr data-id="<?= (int)$r['id'] ?>" data-record-id="<?= (int)$r['loading_record_id'] ?>" data-search="<?= h(mb_strtolower(implode(' ', [(string)$r['belge_no'], (string)$r['parti_no'], (string)$r['palet_adi'], (string)$r['firma'], (string)$r['urun']]), 'UTF-8')) ?>">
                    <td class="pf-cell" data-col="belge_no" data-value="<?= (int)$r['belge_no'] ?>"><?= (int)$r['belge_no'] ?></td>
                    <td class="pf-cell" data-col="parti_no" data-value="<?= h($r['parti_no']) ?>"><?= h($r['parti_no']) ?></td>
                    <td class="pf-cell" data-col="palet_adi" data-value="<?= h($r['palet_tipi_id']) ?>"><?= h($r['palet_adi']) ?></td>
                    <td class="pf-cell" data-col="firma" data-value="<?= h($r['firma']) ?>"><?= h($r['firma']) ?></td>
                    <td class="pf-cell pf-num" data-col="kasa_adeti" data-value="<?= (int)$r['kasa_adeti'] ?>"><?= number_format((int)$r['kasa_adeti'], 0, ',', '.') ?></td>
                    <td class="pf-cell pf-num" data-col="brut_kg" data-value="<?= h($r['brut_kg']) ?>"><?= number_format((float)$r['brut_kg'], 3, ',', '.') ?></td>
                    <td class="pf-cell pf-num" data-col="dara_kg" data-value="<?= h($r['dara_kg']) ?>"><?= number_format((float)$r['dara_kg'], 3, ',', '.') ?></td>
                    <td class="pf-cell pf-num" data-col="net_kg" data-value="<?= h($r['net_kg']) ?>"><?= number_format((float)$r['net_kg'], 3, ',', '.') ?></td>
                    <td class="pf-cell" data-col="aciklama1" data-value="<?= h($r['aciklama1']) ?>"><?= h($r['aciklama1']) ?></td>
                    <td class="pf-cell" data-col="aciklama2" data-value="<?= h($r['aciklama2']) ?>"><?= h($r['aciklama2']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pf-empty" id="pfEmpty" <?= $rows ? 'hidden' : '' ?>><?= $rows ? 'Aramanızla eşleşen kayıt yok.' : 'Henüz palet fişi yok. Yeni Palet Fişi ile başlayın.' ?></div>
    </div>
    <div class="pf-bottom">
        <div class="pf-total"><span>Kayıt</span><strong id="pfCount"><?= count($rows) ?></strong></div>
        <div class="pf-total"><span>Adet Toplamı</span><strong data-total="kasa_adeti"><?= number_format(array_sum(array_map(fn($x) => (int)$x['kasa_adeti'], $rows)), 0, ',', '.') ?></strong></div>
        <div class="pf-total"><span>BRÜT</span><strong data-total="brut_kg"><?= number_format(array_sum(array_map(fn($x) => (float)$x['brut_kg'], $rows)), 3, ',', '.') ?></strong></div>
        <div class="pf-total"><span>Dara</span><strong data-total="dara_kg"><?= number_format(array_sum(array_map(fn($x) => (float)$x['dara_kg'], $rows)), 3, ',', '.') ?></strong></div>
        <div class="pf-total"><span>NET</span><strong data-total="net_kg"><?= number_format(array_sum(array_map(fn($x) => (float)$x['net_kg'], $rows)), 3, ',', '.') ?></strong></div>
        <div class="pf-status" id="pfStatus" role="status" aria-live="polite">Sütunları taşımak için başlığa basılı tutun.</div>
    </div>
</section>

<dialog class="pf-dialog" id="pfDialog">
    <form id="pfForm" method="dialog">
        <div class="pf-dialog-head"><div><h2>Yeni Palet Fişi</h2><p>Kaydettiğinde fiş listenin en üstüne eklenir.</p></div><button type="button" class="pf-close" id="pfClose" aria-label="Kapat">×</button></div>
        <div class="pf-form-grid">
            <label>Ünvan <input name="firma" maxlength="150" required autofocus></label>
            <label>Parti No <input name="parti_no" maxlength="100"></label>
            <label>Ürün <input name="urun" maxlength="150" required></label>
            <label>Tarih <input name="tarih" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Palet <select name="palet_tipi_id"><option value="">Seçiniz</option><?php foreach ($palet_tipi_list as $pt): ?><option value="<?= (int)$pt['id'] ?>"><?= h($pt['name']) ?></option><?php endforeach; ?></select></label>
            <label>Adet <input name="kasa_adeti" inputmode="numeric" type="number" min="0" value="0"></label>
            <label>BRÜT (kg) <input name="brut_kg" inputmode="decimal" value=""></label>
            <label>Dara (kg) <input name="dara_kg" inputmode="decimal" value=""></label>
            <label>Açıklama-1 <input name="aciklama1" maxlength="2000"></label>
            <label>Açıklama-2 <input name="aciklama2" maxlength="2000"></label>
        </div>
        <div class="pf-dialog-foot"><span id="pfFormError" class="pf-error" role="alert"></span><button type="button" class="btn btn-ghost" id="pfCancel">Vazgeç</button><button type="submit" class="btn btn-primary" id="pfSave">Kaydet</button></div>
    </form>
</dialog>
<script>window.PALET_FISLERI = {paletTipleri: <?= json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name']], $palet_tipi_list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>};</script>
<script src="assets/palet-fisleri.js?v=<?= (int)@filemtime(__DIR__ . '/assets/palet-fisleri.js') ?>" defer></script>
<?php render_footer();
