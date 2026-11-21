<?php
// Palet Fişleri — yönetim tablosu. Yalnız yönetici.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cari_core.php';
require_once __DIR__ . '/config/palet_fisleri_core.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }

$pdo = db();
// Şema yalnız migrate.php › "Palet Fişleri" kartından kurulur; sayfa kolon EKLEMEZ, yalnız var mı diye bakar.
$has_aciklama1 = palet_fisleri_kolon_var($pdo, 'aciklama1');
$has_aciklama2 = palet_fisleri_kolon_var($pdo, 'aciklama2');
$has_mustahsil = palet_fisleri_kolon_var($pdo, 'mustahsil_id') && palet_fisleri_kolon_var($pdo, 'mustahsil_ad');
$cari_var = cari_hazir($pdo);
$mustahsil_aktif = $has_mustahsil && $cari_var;

function pf_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pf_require_note_columns(): void {
    global $has_aciklama1, $has_aciklama2;
    if (!$has_aciklama1 || !$has_aciklama2) throw new RuntimeException('Açıklama alanları için veritabanı güncellemesi gerekli: migrate.php › Palet Fişleri kartını çalıştırın.');
}

function pf_input_text(string $key, int $limit = 500): string {
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $limit, 'UTF-8');
}

// Ekle penceresindeki "+ ekle": yalnız bu dört tanım türü açılabilir (sayfa zaten yalnız yöneticiye açık).
function pf_tanim_turleri(): array {
    return ['palet_tipi' => 'palet', 'firma' => 'müşteri', 'urun' => 'ürün', 'kasa_cinsi' => 'kasa'];
}

function pf_tanim_bul_ekle(string $type, string $name): array {
    $pdo = db();
    $name = tr_upper(trim((string)preg_replace('/\s+/u', ' ', $name)));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150 || !preg_match('/[\p{L}\p{N}]/u', $name)) throw new RuntimeException('Geçerli bir ad girin (en fazla 150 karakter).');
    $st = $pdo->prepare("SELECT id, name FROM material_definitions WHERE type = ? AND is_active = 1");
    $st->execute([$type]);
    foreach ($st->fetchAll() as $d) {
        if (tr_upper((string)$d['name']) === $name) return ['id' => (int)$d['id'], 'name' => (string)$d['name'], 'yeni' => false];
    }
    $pdo->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES (?, ?, 0, 1)")->execute([$type, $name]);
    $id = (int)$pdo->lastInsertId();
    audit_log_event('create', 'tanim', $id, null, ['type' => $type, 'name' => $name, 'kaynak' => 'palet_fisleri']);
    return ['id' => $id, 'name' => $name, 'yeni' => true];
}

/** Müstahsil (cari kartı) doğrula: [id, ünvan] ya da [null, null]. */
function pf_mustahsil_coz(PDO $pdo): array
{
    global $has_mustahsil, $cari_var;
    $id = (int)($_POST['mustahsil_id'] ?? 0);
    if ($id <= 0) return [null, null];
    if (!$has_mustahsil) throw new RuntimeException('Müstahsil alanı için veritabanı güncellemesi gerekli: migrate.php › Palet Fişleri kartını çalıştırın.');
    if (!$cari_var) throw new RuntimeException('Cari İşlemler şeması kurulu değil; müstahsil seçilemez.');
    $st = $pdo->prepare('SELECT unvan FROM cari_kartlar WHERE id = ? AND aktif = 1');
    $st->execute([$id]);
    $unvan = $st->fetchColumn();
    if ($unvan === false) throw new RuntimeException('Müstahsil cari kartı bulunamadı ya da pasif.');
    return [$id, (string)$unvan];
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
            $kasa_cinsi_id = (int)($_POST['kasa_cinsi_id'] ?? 0);
            $adet = max(0, min(1000000, intval_safe($_POST['kasa_adeti'] ?? 0)));
            $brut = max(0, min(999999999, parse_decimal($_POST['brut_kg'] ?? 0)));
            $dara = max(0, min(999999999, parse_decimal($_POST['dara_kg'] ?? 0)));
            if ($dara > $brut) throw new RuntimeException('Dara, brüt ağırlıktan büyük olamaz.');
            $net = round(max(0, $brut - $dara), 3);
            $aciklama1 = pf_input_text('aciklama1', 2000);
            $aciklama2 = pf_input_text('aciklama2', 2000);
            [$must_id, $must_ad] = pf_mustahsil_coz($pdo);
            if ($firma === '' || $urun === '' || !pf_valid_date($tarih)) throw new RuntimeException('Müşteri, ürün ve geçerli tarih zorunludur.');
            if ($kasa_cinsi_id > 0) {
                $kdef = $pdo->prepare("SELECT id FROM material_definitions WHERE id = ? AND type = 'kasa_cinsi' AND is_active = 1");
                $kdef->execute([$kasa_cinsi_id]);
                if (!$kdef->fetchColumn()) throw new RuntimeException('Kasa tanımı geçerli değil.');
            } else $kasa_cinsi_id = null;
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
                $ek_kol = $has_mustahsil ? ', mustahsil_id, mustahsil_ad' : '';
                $ek_val = $has_mustahsil ? ', ?, ?' : '';
                $ek_par = $has_mustahsil ? [$must_id, $must_ad] : [];
                $pdo->prepare("INSERT INTO loading_pallets
                    (loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg, kasa_cinsi_id, palet_tipi_id, urun_cinsi, depo, sira_no, islendi, aciklama1, aciklama2$ek_kol)
                    VALUES (?, '', ?, '', ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?$ek_val)")
                    ->execute([$record_id, $adet, $brut, $dara, $net, $kasa_cinsi_id, $palet_tipi_id, $urun, active_depot() ?? '', $aciklama1, $aciklama2, ...$ek_par]);
                $pallet_id = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            sync_malzeme_kullanim($record_id);
            audit_log_event('create', 'palet_fisleri', $pallet_id, null, ['belge_no' => $record_id, 'firma' => $firma, 'parti_no' => $parti, 'brut_kg' => $brut, 'net_kg' => $net, 'mustahsil_id' => $must_id]);
            $yeni = pf_get_row($pallet_id);
            pf_json(['ok' => true, 'id' => $pallet_id, 'next_belge' => $record_id + 1, 'row' => [
                'id' => $pallet_id, 'loading_record_id' => $record_id, 'belge_no' => (int)$yeni['belge_no'], 'parti_no' => (string)$yeni['parti_no'],
                'palet_tipi_id' => (int)($yeni['palet_tipi_id'] ?? 0), 'palet_adi' => (string)$yeni['palet_adi'], 'firma' => (string)$yeni['firma'], 'urun' => (string)$yeni['urun'],
                'kasa_adeti' => (int)$yeni['kasa_adeti'], 'brut_kg' => (float)$yeni['brut_kg'], 'dara_kg' => (float)$yeni['dara_kg'], 'net_kg' => (float)$yeni['net_kg'],
                'aciklama1' => (string)($yeni['aciklama1'] ?? ''), 'aciklama2' => (string)($yeni['aciklama2'] ?? ''), 'mustahsil_ad' => (string)($yeni['mustahsil_ad'] ?? '')]]);
        }
        if ($action === 'tanim_ekle') {
            $tur = (string)($_POST['tur'] ?? '');
            if (!isset(pf_tanim_turleri()[$tur])) throw new RuntimeException('Bu tür için tanım eklenemez.');
            pf_json(['ok' => true] + pf_tanim_bul_ekle($tur, (string)($_POST['ad'] ?? '')));
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
                $ek_kol = $has_mustahsil ? ', mustahsil_id, mustahsil_ad' : '';
                $ek_val = $has_mustahsil ? ', ?, ?' : '';
                $ek_par = $has_mustahsil ? [$old['mustahsil_id'] ?? null, $old['mustahsil_ad'] ?? null] : [];
                $pdo->prepare("INSERT INTO loading_pallets
                    (loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg, kasa_cinsi_id, palet_tipi_id, urun_cinsi, depo, sira_no, islendi, aciklama1, aciklama2$ek_kol)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?$ek_val)")
                    ->execute([$new_record_id, $old['palet_no'], $old['kasa_adeti'], $old['size'], $old['brut_kg'], $old['dara_kg'], $old['net_kg'], $old['kasa_cinsi_id'], $old['palet_tipi_id'], $old['urun_cinsi'], $old['depo'], $old['aciklama1'] ?? '', $old['aciklama2'] ?? '', ...$ek_par]);
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
$pf_listeler = [
    'palet' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name']], $palet_tipi_list),
    'firma' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name']], get_definitions_by_type('firma')),
    'urun'  => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name']], get_definitions_by_type('urun')),
    'kasa'  => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name']], get_definitions_by_type('kasa_cinsi')),
    'mustahsil' => $mustahsil_aktif ? array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['unvan']], $pdo->query('SELECT id, unvan FROM cari_kartlar WHERE aktif = 1 ORDER BY unvan LIMIT 5000')->fetchAll(PDO::FETCH_ASSOC)) : [],
    'parti' => array_map(fn($x) => ['id' => 0, 'name' => (string)$x], $pdo->query("SELECT parti_no FROM loading_records WHERE type = 'yukleme' AND parti_no <> '' GROUP BY parti_no ORDER BY MAX(id) DESC LIMIT 300")->fetchAll(PDO::FETCH_COLUMN)),
];
$pf_sonraki_belge = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM loading_records')->fetchColumn();
$desc1_sql = $has_aciklama1 ? 'p.aciklama1' : "'' AS aciklama1";
$desc2_sql = $has_aciklama2 ? 'p.aciklama2' : "'' AS aciklama2";
$must_sql = $has_mustahsil ? 'p.mustahsil_ad' : "'' AS mustahsil_ad";
$st = $pdo->query("SELECT p.id, p.loading_record_id, p.palet_no, p.kasa_adeti, p.size, p.brut_kg, p.dara_kg, p.net_kg,
                          p.palet_tipi_id, $desc1_sql, $desc2_sql, $must_sql,
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
        </div>
        <div class="pf-toolbar" role="toolbar" aria-label="Palet fişi araçları">
            <div class="pf-tb-grup">
                <button type="button" class="pf-tb pf-tb-ekle" id="pfAdd" <?= (!$has_aciklama1 || !$has_aciklama2) ? 'disabled' : '' ?>>＋ F2 - Ekle</button>
                <button type="button" class="pf-tb" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">⧉ Çoğalt</button>
                <button type="button" class="pf-tb pf-tb-sil" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">🗑 F3 - Sil</button>
                <button type="button" class="pf-tb" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">⌕ Bul</button>
                <button type="button" class="pf-tb" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">✎ Düzelt</button>
                <button type="button" class="pf-tb" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">▮▮ Parti Özeti</button>
            </div>
            <div class="pf-tb-grup">
                <button type="button" class="pf-tb" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">⎙ Yazdır</button>
                <button type="button" class="pf-tb pf-tb-wp" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">◔ WhatsApp</button>
                <button type="button" class="pf-tb pf-tb-xl" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">▦ Excel</button>
            </div>
        </div>
        <div class="pf-filtre">
            <label class="pf-f-alan">Başlangıç Tarihi <input type="date" value="<?= h(date('Y-m-d', strtotime('-3 months'))) ?>" data-pf-pasif></label>
            <label class="pf-f-alan pf-f-chk">Yüklenen Paletleri Göster <input type="checkbox" data-pf-pasif></label>
            <button type="button" class="pf-getir" data-pf-pasif aria-disabled="true" title="Henüz çalışmıyor">⌕ Getir</button>
            <div class="pf-hints"><span>Hücre seç: tıkla</span><span>Kopyala: Ctrl + Shift + D</span><span>Sil: Delete</span></div>
        </div>
    </div>
    <?php if (!$has_aciklama1 || !$has_aciklama2): ?><div class="flash flash-error">Açıklama alanları için veritabanı güncellemesi gerekli: migrate.php › Palet Fişleri kartını çalıştırın.</div><?php endif; ?>
    <div class="pf-table-wrap" id="pfTableWrap" tabindex="0" aria-label="Palet fişleri tablosu">
        <table class="pf-table" id="pfTable">
            <thead><tr>
                <th data-col="belge_no">Belge No</th><th data-col="parti_no">Parti No</th><th data-col="palet_adi">Palet</th><th data-col="firma">Ünvan</th>
                <th data-col="kasa_adeti">Adet</th><th data-col="brut_kg">BRÜT</th><th data-col="dara_kg">Dara</th><th data-col="net_kg">NET</th>
                <?php if ($has_mustahsil): ?><th data-col="mustahsil_ad">Müstahsil</th><?php endif; ?>
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
                    <td class="pf-cell pf-num" data-col="brut_kg" data-value="<?= h($r['brut_kg']) ?>"><?= number_format((float)$r['brut_kg'], 0, ',', '.') ?></td>
                    <td class="pf-cell pf-num" data-col="dara_kg" data-value="<?= h($r['dara_kg']) ?>"><?= number_format((float)$r['dara_kg'], 0, ',', '.') ?></td>
                    <td class="pf-cell pf-num" data-col="net_kg" data-value="<?= h($r['net_kg']) ?>"><?= number_format((float)$r['net_kg'], 0, ',', '.') ?></td>
                    <?php if ($has_mustahsil): ?><td class="pf-cell" data-col="mustahsil_ad" data-value="<?= h($r['mustahsil_ad']) ?>"><?= h($r['mustahsil_ad']) ?></td><?php endif; ?>
                    <td class="pf-cell" data-col="aciklama1" data-value="<?= h($r['aciklama1']) ?>"><?= h($r['aciklama1']) ?></td>
                    <td class="pf-cell" data-col="aciklama2" data-value="<?= h($r['aciklama2']) ?>"><?= h($r['aciklama2']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pf-empty" id="pfEmpty" <?= $rows ? 'hidden' : '' ?>><?= $rows ? 'Aramanızla eşleşen kayıt yok.' : 'Henüz palet fişi yok. F2 - Ekle ile başlayın.' ?></div>
    </div>
    <div class="pf-bottom">
        <div class="pf-kart pf-kart-parti"><i aria-hidden="true">▤</i><div><span>PARTİ</span><strong data-sum="parti">—</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">▥</i><div><span>PALET TOPLAMI</span><strong data-sum="palet">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">▢</i><div><span>AMBALAJ TOPLAMI</span><strong data-sum="adet">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span>BRÜT</span><strong data-sum="brut">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span>DARA</span><strong data-sum="dara">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span>NET</span><strong data-sum="net">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">▮</i><div><span>AMB. ORTALAMA</span><strong data-sum="ort">0</strong></div></div>
    </div>
    <div class="pf-foot"><span>T.Kayıt: <strong id="pfCount"><?= count($rows) ?></strong></span><span class="pf-status" id="pfStatus" role="status" aria-live="polite">Özet kartları tıklanan satırın partisini gösterir. Sütunları taşımak için başlığa basılı tutun.</span></div>
</section>

<dialog class="pf-dialog" id="pfDialog">
    <form id="pfForm" method="dialog" autocomplete="off" novalidate>
        <div class="pf-dialog-head"><div><h2>Palet Fişi Ekle</h2><p>Kaydedince pencere açık kalır; parti, palet, müşteri, ürün ve kasa bir sonraki palet için korunur. <b>Enter</b> sonraki alan · <b>F10</b> kaydet · <b>Esc</b> kapat</p></div><button type="button" class="pf-close" id="pfClose" aria-label="Kapat">×</button></div>
        <div class="pf-form-grid">
            <label>Tarih <input name="tarih" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Belge No <input name="belge_no" id="pfBelgeNo" value="<?= (int)$pf_sonraki_belge ?>" readonly tabindex="-1" class="pf-readonly" title="Otomatik verilir"></label>
            <label class="pf-cb">Parti No <input name="parti_no" data-cb="parti" maxlength="100" placeholder="Yazın veya listeden seçin"></label>
            <label class="pf-cb">Palet <input name="palet" data-cb="palet" placeholder="Yazdıkça bulunur"></label>
            <label class="pf-cb">Müşteri <input name="firma" data-cb="firma" maxlength="150" placeholder="Yazdıkça bulunur" required></label>
            <label class="pf-cb">Ürün <input name="urun" data-cb="urun" maxlength="150" placeholder="Yazdıkça bulunur" required></label>
            <label class="pf-cb">Kasa Ambalaj <input name="kasa" data-cb="kasa" placeholder="Yazdıkça bulunur"></label>
            <label>Kasa Adedi <input name="kasa_adeti" inputmode="numeric" type="number" min="0" value="0"></label>
            <label class="pf-cb pf-must">Müstahsil <input name="mustahsil" data-cb="mustahsil" maxlength="200" placeholder="<?= $mustahsil_aktif ? 'Yazdıkça cari kartlardan bulunur' : ($has_mustahsil ? 'Cari İşlemler kurulu değil' : 'Kurulu değil: migrate.php › Palet Fişleri') ?>" <?= $mustahsil_aktif ? '' : 'disabled' ?>></label>
            <label class="pf-kg">Brüt (kg) <span class="pf-kg-satir"><input name="brut_kg" inputmode="decimal" autocomplete="off"><button type="button" class="btn btn-ghost pf-kantar" disabled title="Kantar entegrasyonu sonra bağlanacak">Kantardan al</button></span></label>
            <label class="pf-kg">Dara (kg) <input name="dara_kg" inputmode="decimal"></label>
            <label class="pf-kg">Net (kg) <input name="net_kg" id="pfNet" readonly tabindex="-1" class="pf-readonly" value="0"></label>
            <div class="pf-uyari" id="pfUyari" role="status" aria-live="polite"></div>
            <label>Açıklama-1 <input name="aciklama1" maxlength="2000"></label>
            <label>Açıklama-2 <input name="aciklama2" maxlength="2000"></label>
        </div>
        <div class="pf-dialog-foot"><span id="pfFormError" class="pf-error" role="alert"></span><button type="button" class="btn btn-ghost" id="pfCancel">Kapat</button><button type="submit" class="btn btn-primary" id="pfSave">Kaydet <span class="pf-shortcut">F10</span></button></div>
    </form>
</dialog>
<script>window.PALET_FISLERI = <?= json_encode(['listeler' => $pf_listeler, 'sonrakiBelge' => $pf_sonraki_belge, 'mustahsilAktif' => $mustahsil_aktif, 'tanimTurleri' => array_keys(pf_tanim_turleri())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/palet-fisleri.js?v=<?= (int)@filemtime(__DIR__ . '/assets/palet-fisleri.js') ?>" defer></script>
<?php render_footer();
