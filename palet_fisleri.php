<?php
// Palet Fişleri — yönetim tablosu. Yalnız yönetici.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cari_core.php';
require_once __DIR__ . '/config/palet_fisleri_core.php';
require_once __DIR__ . '/config/xlsx_export.php';
require_once __DIR__ . '/config/kullanici_tercih.php';
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

/**
 * Son işlem bilgisi (Eklendi / Çoğaltıldı / Düzeltildi + zaman + kullanıcı) audit_log'tan okunur; şema değişmez.
 * Bu sayfadan hiç işlem görmemiş paletlerde (ör. Yükleme Planı'ndan gelenler) boş kalır.
 * audit_log / users okunamazsa sütunlar boş döner, liste yine çalışır.
 * @return array{0:string,1:string} [SELECT parçası, JOIN parçası]
 */
function pf_islem_sql(PDO $pdo): array {
    static $hazir = null;
    if ($hazir === null) {
        try { $pdo->query('SELECT 1 FROM audit_log LIMIT 0'); $pdo->query('SELECT 1 FROM users LIMIT 0'); $hazir = true; }
        catch (Throwable $e) { $hazir = false; }
    }
    if (!$hazir) return ["NULL AS islem_kod, 0 AS islem_kopya, NULL AS islem_zaman, '' AS islem_kullanici", ''];
    return [
        "al.action AS islem_kod, (al.new_values LIKE '%\"duplicate_of\"%') AS islem_kopya, al.created_at AS islem_zaman, COALESCE(NULLIF(u.display_name, ''), u.username, '') AS islem_kullanici",
        "LEFT JOIN audit_log al ON al.id = (SELECT MAX(a2.id) FROM audit_log a2 WHERE a2.module = 'palet_fisleri' AND a2.record_id = p.id AND a2.action IN ('create', 'update'))
         LEFT JOIN users u ON u.id = al.user_id",
    ];
}

function pf_get_row(int $id): array {
    [$islem_sel, $islem_join] = pf_islem_sql(db());
    $st = db()->prepare("SELECT p.*, r.id AS belge_no, r.parti_no, r.firma, r.urun, r.tarih, r.durum, r.locked_at,
                                COALESCE(md.name, p.palet_no, '') AS palet_adi, COALESCE(kd.name, '') AS kasa_adi, $islem_sel
                         FROM loading_pallets p
                         JOIN loading_records r ON r.id = p.loading_record_id
                         LEFT JOIN material_definitions md ON md.id = p.palet_tipi_id AND md.type = 'palet_tipi'
                         LEFT JOIN material_definitions kd ON kd.id = p.kasa_cinsi_id AND kd.type = 'kasa_cinsi'
                         $islem_join
                         WHERE p.id = ? AND r.type = 'yukleme'");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Palet fişi bulunamadı.');
    return $row;
}

/** İstemciye giden tek satır (liste hücreleri + Düzelt penceresi için ek alanlar). */
function pf_tr_tarih(?string $iso, bool $saat = false): string {
    $t = $iso ? strtotime($iso) : false;
    return $t ? date($saat ? 'd.m.Y H:i' : 'd.m.Y', $t) : '';
}
function pf_islem_adi(array $y): string {
    $kod = (string)($y['islem_kod'] ?? '');
    if ($kod === 'update') return 'Düzeltildi';
    if ($kod === 'create') return !empty($y['islem_kopya']) ? 'Çoğaltıldı' : 'Eklendi';
    return '';
}
function pf_satir_json(array $y): array {
    return [
        'sube' => (string)($y['depo'] ?? ''), 'islem' => pf_islem_adi($y), 'islem_zaman' => pf_tr_tarih($y['islem_zaman'] ?? null, true), 'kullanici' => (string)($y['islem_kullanici'] ?? ''),
        'id' => (int)$y['id'], 'loading_record_id' => (int)$y['loading_record_id'], 'belge_no' => (int)$y['belge_no'], 'parti_no' => (string)$y['parti_no'],
        'palet_tipi_id' => (int)($y['palet_tipi_id'] ?? 0), 'palet_adi' => (string)$y['palet_adi'], 'firma' => (string)$y['firma'], 'urun' => (string)$y['urun'],
        'tarih' => (string)($y['tarih'] ?? ''), 'kasa_cinsi_id' => (int)($y['kasa_cinsi_id'] ?? 0), 'mustahsil_id' => (int)($y['mustahsil_id'] ?? 0),
        'kasa_adeti' => (int)$y['kasa_adeti'], 'brut_kg' => (float)$y['brut_kg'], 'dara_kg' => (float)$y['dara_kg'], 'net_kg' => (float)$y['net_kg'],
        'aciklama1' => (string)($y['aciklama1'] ?? ''), 'aciklama2' => (string)($y['aciklama2'] ?? ''), 'mustahsil_ad' => (string)($y['mustahsil_ad'] ?? ''), 'kasa_adi' => (string)($y['kasa_adi'] ?? ''),
        'kilit' => (!empty($y['locked_at']) || ($y['durum'] ?? '') === 'yuklendi') ? 1 : 0,
    ];
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
    if ($dara > $brut) throw new RuntimeException('Dara, brüt ağırlıktan büyük olamaz.');
    // Düzelt penceresi (tam=1): tarih, ürün, kasa ve müstahsil da güncellenir.
    $tam = !empty($_POST['tam']);
    $urun = $old['urun']; $tarih = $old['tarih']; $kasa_id = $old['kasa_cinsi_id'] ?? null; $must_id = null; $must_ad = null; $must_dokun = false;
    if ($tam) {
        global $has_mustahsil;
        $urun = pf_input_text('urun', 150);
        $tarih = pf_input_text('tarih', 10);
        if ($urun === '' || !pf_valid_date($tarih)) throw new RuntimeException('Ürün ve geçerli tarih zorunludur.');
        $kasa_id = (int)($_POST['kasa_cinsi_id'] ?? 0);
        if ($kasa_id > 0) {
            $kdef = $pdo->prepare("SELECT id FROM material_definitions WHERE id = ? AND type = 'kasa_cinsi' AND is_active = 1");
            $kdef->execute([$kasa_id]);
            if (!$kdef->fetchColumn()) throw new RuntimeException('Kasa tanımı geçerli değil.');
        } else $kasa_id = null;
        // Müstahsil yalnız alan etkinse (kolon + Cari kurulu) ve istemci bayrak gönderdiyse değişir — aksi halde mevcut değer KORUNUR.
        $must_dokun = $has_mustahsil && !empty($_POST['mustahsil_duzenle']);
        if ($must_dokun) [$must_id, $must_ad] = pf_mustahsil_coz($pdo);
    }
    $pdo->beginTransaction();
    try {
        if ($tam) {
            $pdo->prepare('UPDATE loading_records SET firma = ?, parti_no = ?, urun = ?, tarih = ? WHERE id = ?')
                ->execute([$firma, $parti, $urun, $tarih, (int)$old['loading_record_id']]);
            $ek_set = $must_dokun ? ', mustahsil_id = ?, mustahsil_ad = ?' : '';
            $ek_par = $must_dokun ? [$must_id, $must_ad] : [];
            $pdo->prepare("UPDATE loading_pallets SET palet_tipi_id = ?, kasa_cinsi_id = ?, urun_cinsi = ?, kasa_adeti = ?, brut_kg = ?, dara_kg = ?, net_kg = ?, aciklama1 = ?, aciklama2 = ?$ek_set WHERE id = ?")
                ->execute([$palet_tipi_id ?: null, $kasa_id, $urun, $adet, $brut, $dara, $net, $aciklama1, $aciklama2, ...$ek_par, $id]);
        } else {
            $pdo->prepare('UPDATE loading_records SET firma = ?, parti_no = ? WHERE id = ?')
                ->execute([$firma, $parti, (int)$old['loading_record_id']]);
            $sql = 'UPDATE loading_pallets SET palet_tipi_id = ?, kasa_adeti = ?, brut_kg = ?, dara_kg = ?, net_kg = ?, aciklama1 = ?, aciklama2 = ? WHERE id = ?';
            $pdo->prepare($sql)->execute([$palet_tipi_id ?: null, $adet, $brut, $dara, $net, $aciklama1, $aciklama2, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    sync_malzeme_kullanim((int)$old['loading_record_id']);
    audit_log_event('update', 'palet_fisleri', $id, $old, ['firma' => $firma, 'parti_no' => $parti, 'urun' => $urun, 'tarih' => $tarih, 'kasa_adeti' => $adet, 'brut_kg' => $brut, 'dara_kg' => $dara, 'net_kg' => $net, 'mustahsil_id' => $must_id]);
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
            pf_json(['ok' => true, 'id' => $pallet_id, 'next_belge' => $record_id + 1, 'row' => pf_satir_json(pf_get_row($pallet_id))]);
        }
        if ($action === 'tercih_kaydet') {
            // Sütun genişliği/sırası: KULLANICI BAZINDA (kullanici_tercihleri). Tablo kurulu değilse istemci tarayıcıya düşer.
            $anahtar = (string)($_POST['anahtar'] ?? '');
            $deger = json_decode((string)($_POST['deger'] ?? ''), true);
            if (!kt_hazir($pdo)) pf_json(['ok' => true, 'sunucu' => false]);
            if (!kt_yaz($pdo, (int)($auth_user['id'] ?? 0), $anahtar, $deger)) throw new RuntimeException('Tercih kaydedilemedi.');
            pf_json(['ok' => true, 'sunucu' => true]);
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
            pf_json(['ok' => true, 'row' => pf_satir_json(pf_get_row($id))]);
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
            pf_json(['ok' => true, 'id' => $new_id, 'next_belge' => $new_record_id + 1, 'row' => pf_satir_json(pf_get_row($new_id))]);
        }
        throw new RuntimeException('Geçersiz işlem.');
    } catch (Throwable $e) {
        pf_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

$palet_tipi_list = get_definitions_by_type('palet_tipi');
$pf_listeler = [
    'palet' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'unit' => (float)($x['unit_dara_kg'] ?? 0)], $palet_tipi_list),
    'firma' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name']], get_definitions_by_type('firma')),
    'urun'  => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name']], get_definitions_by_type('urun')),
    'kasa'  => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'unit' => (float)($x['unit_dara_kg'] ?? 0)], get_definitions_by_type('kasa_cinsi')),
    'mustahsil' => $mustahsil_aktif ? array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['unvan']], $pdo->query('SELECT id, unvan FROM cari_kartlar WHERE aktif = 1 ORDER BY unvan LIMIT 5000')->fetchAll(PDO::FETCH_ASSOC)) : [],
    'parti' => array_map(fn($x) => ['id' => 0, 'name' => (string)$x], $pdo->query("SELECT parti_no FROM loading_records WHERE type = 'yukleme' AND parti_no <> '' GROUP BY parti_no ORDER BY MAX(id) DESC LIMIT 300")->fetchAll(PDO::FETCH_COLUMN)),
];
$pf_sonraki_belge = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM loading_records')->fetchColumn();
$desc1_sql = $has_aciklama1 ? 'p.aciklama1' : "'' AS aciklama1";
$desc2_sql = $has_aciklama2 ? 'p.aciklama2' : "'' AS aciklama2";
$must_sql = $has_mustahsil ? 'p.mustahsil_ad' : "'' AS mustahsil_ad";
$kasa_sql = 'p.kasa_cinsi_id';
$must_id_sql = $has_mustahsil ? 'p.mustahsil_id' : 'NULL AS mustahsil_id';

// Filtre (Getir): başlangıç tarihi + "Yüklenen paletleri göster". Varsayılan: son 90 gün, yüklenenler gizli.
$f_baslangic = (string)($_GET['baslangic'] ?? '');
if (!pf_valid_date($f_baslangic)) $f_baslangic = date('Y-m-d', strtotime('-90 days'));
$f_yuklenen = !empty($_GET['yuklenen']);

function pf_liste_getir(PDO $pdo, string $baslangic, bool $yuklenen, string $desc1_sql, string $desc2_sql, string $must_sql, string $must_id_sql): array {
    [$islem_sel, $islem_join] = pf_islem_sql($pdo);
    $w = "r.type = 'yukleme' AND r.tarih >= ?";
    if (!$yuklenen) $w .= " AND COALESCE(r.durum, '') <> 'yuklendi'";
    $st = $pdo->prepare("SELECT p.id, p.loading_record_id, p.palet_no, p.kasa_adeti, p.size, p.brut_kg, p.dara_kg, p.net_kg,
                          p.palet_tipi_id, p.kasa_cinsi_id, $desc1_sql, $desc2_sql, $must_sql, $must_id_sql,
                          r.id AS belge_no, r.tarih, r.parti_no, r.firma, r.urun, r.durum, r.locked_at,
                          p.depo, COALESCE(md.name, p.palet_no, '') AS palet_adi, COALESCE(kd.name, '') AS kasa_adi, $islem_sel
                   FROM loading_pallets p
                   JOIN loading_records r ON r.id = p.loading_record_id
                   LEFT JOIN material_definitions md ON md.id = p.palet_tipi_id AND md.type = 'palet_tipi'
                   LEFT JOIN material_definitions kd ON kd.id = p.kasa_cinsi_id AND kd.type = 'kasa_cinsi'
                   $islem_join
                   WHERE $w
                   ORDER BY r.id DESC, p.sira_no ASC, p.id DESC");
    $st->execute([$baslangic]);
    return $st->fetchAll();
}
$rows = pf_liste_getir($pdo, $f_baslangic, $f_yuklenen, $desc1_sql, $desc2_sql, $must_sql, $must_id_sql);

// Excel / CSV (aynı filtre). Ortak kapı: reports.export; her iki kolda export_audit.
$export = (string)($_GET['export'] ?? '');
if ($export === 'csv' || $export === 'xlsx') {
    require_perm('reports.export');
    $bas = ['Belge No', 'Tarih', 'Parti No', 'Palet', 'Ünvan', 'Ürün', 'Ambalaj', 'Adet', 'BRÜT', 'Dara', 'NET'];
    if ($has_mustahsil) $bas[] = 'Müstahsil';
    array_push($bas, 'Açıklama-1', 'Açıklama-2', 'İşlem', 'İşlem Tarihi', 'Kullanıcı', 'Şube');
    $satirlar = [];
    foreach ($rows as $r) {
        $x = [(int)$r['belge_no'], (string)$r['tarih'], (string)$r['parti_no'], (string)$r['palet_adi'], (string)$r['firma'], (string)$r['urun'], (string)$r['kasa_adi'],
              (int)$r['kasa_adeti'], (float)$r['brut_kg'], (float)$r['dara_kg'], (float)$r['net_kg']];
        if ($has_mustahsil) $x[] = (string)$r['mustahsil_ad'];
        array_push($x, (string)$r['aciklama1'], (string)$r['aciklama2'], pf_islem_adi($r), pf_tr_tarih($r['islem_zaman'] ?? null, true), (string)($r['islem_kullanici'] ?? ''), (string)($r['depo'] ?? ''));
        $satirlar[] = $x;
    }
    export_audit('palet_fisleri', 'liste', $export, count($satirlar), ['baslangic' => $f_baslangic, 'yuklenen' => $f_yuklenen ? '1' : '0']);
    $dosya = 'palet_fisleri_' . date('Y-m-d');
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $dosya . '.csv"');
        header('Cache-Control: no-cache');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $bas, ';', '"', '\\');
        foreach ($satirlar as $x) {
            $x[1] = $x[1] !== '' ? date('d.m.Y', strtotime($x[1])) : '';
            foreach ([8, 9, 10] as $i) $x[$i] = number_format((float)$x[$i], 3, ',', '');   // binlik ayraçsız — aktarımda güvenli
            fputcsv($out, $x, ';', '"', '\\');
        }
        fclose($out);
        exit;
    }
    $tip = ['Belge No' => 'tamsayi', 'Tarih' => 'tarih', 'Adet' => 'tamsayi', 'BRÜT' => 'kg', 'Dara' => 'kg', 'NET' => 'kg'];
    $topla = ['Adet' => true, 'BRÜT' => true, 'Dara' => true, 'NET' => true];
    xlsx_indir($dosya . '.xlsx', [
        ['ad' => 'Palet Fişleri', 'baslik' => 'Asya Fresh — Palet Fişleri',
         'aciklama' => 'Başlangıç: ' . date('d.m.Y', strtotime($f_baslangic)) . ' · ' . ($f_yuklenen ? 'yüklenen paletler dahil' : 'yüklenen paletler hariç') . ' · ' . count($satirlar) . ' kayıt',
         'sutunlar' => array_map(fn($b) => ['baslik' => $b, 'tip' => $tip[$b] ?? 'metin'] + (isset($topla[$b]) ? ['topla' => true] : []), $bas),
         'satirlar' => $satirlar, 'toplam' => true],
    ], 'palet_fisleri.php?' . http_build_query(['baslangic' => $f_baslangic, 'yuklenen' => $f_yuklenen ? '1' : '', 'export' => 'csv']));
}

render_header('Palet Fişleri');
?>
<link rel="stylesheet" href="assets/palet-fisleri.css?v=<?= (int)@filemtime(__DIR__ . '/assets/palet-fisleri.css') ?>">
<section class="pf-page" id="pfPage" data-csrf="<?= h(csrf_token()) ?>">
    <div class="pf-top">
        <form class="pf-filtre" id="pfFiltre" method="get" action="palet_fisleri.php">
            <label class="pf-f-alan">Başlangıç Tarihi <input type="date" name="baslangic" value="<?= h($f_baslangic) ?>" required></label>
            <label class="pf-f-alan pf-f-chk">Yüklenen Paletleri Göster <input type="checkbox" name="yuklenen" value="1" <?= $f_yuklenen ? 'checked' : '' ?>></label>
            <div class="pf-tb-grup" role="toolbar" aria-label="Palet fişi araçları">
                <button type="button" class="pf-tb" data-pf="yazdir" title="Listeyi yazdırır">⎙ Yazdır</button>
                <?php if (can('reports.export')): ?><?= export_menu('palet_fisleri.php?' . http_build_query(['baslangic' => $f_baslangic, 'yuklenen' => $f_yuklenen ? '1' : '', 'export' => 'csv']), 'palet_fisleri.php?' . http_build_query(['baslangic' => $f_baslangic, 'yuklenen' => $f_yuklenen ? '1' : '', 'export' => 'xlsx']), 'Excel', 'pf-tb pf-tb-xl') ?><?php endif; ?>
                <button type="button" class="pf-tb" data-pf="duzelt" title="Seçili palet fişini düzenler (F4 / çift tık)">✎ Düzelt</button>
                <button type="button" class="pf-tb" data-pf="bul" title="Listede ara">⌕ Bul</button>
                <button type="button" class="pf-tb pf-tb-sil" data-pf="sil" title="Seçili palet fişini siler: ilk basış satırı kırmızı yapar, ikinci basış siler (F3 / Delete)">🗑 F3 - Sil</button>
                <button type="button" class="pf-tb" data-pf="coglat" title="Seçili palet fişini kopyalar ve kopyayı düzeltme penceresinde açar (Ctrl+Shift+D)">⧉ Çoğalt</button>
                <button type="button" class="pf-tb pf-tb-ekle" id="pfAdd" <?= (!$has_aciklama1 || !$has_aciklama2) ? 'disabled' : '' ?> title="Yeni palet fişi (F2)">＋ F2 - Ekle</button>
            </div>
        </form>
    </div>
    <?php if (!$has_aciklama1 || !$has_aciklama2): ?><div class="flash flash-error">Açıklama alanları için veritabanı güncellemesi gerekli: migrate.php › Palet Fişleri kartını çalıştırın.</div><?php endif; ?>
    <div class="pf-table-wrap" id="pfTableWrap" tabindex="0" aria-label="Palet fişleri tablosu">
        <table class="pf-table" id="pfTable">
            <thead><tr>
                <th data-col="belge_no">Belge No</th><th data-col="tarih">Tarih</th><th data-col="parti_no">Parti No</th><th data-col="palet_adi">Palet</th><th data-col="firma">Ünvan</th>
                <th data-col="kasa_adi">Ambalaj</th><th data-col="kasa_adeti">Adet</th><th data-col="brut_kg">BRÜT</th><th data-col="dara_kg">Dara</th><th data-col="net_kg">NET</th>
                <?php if ($has_mustahsil): ?><th data-col="mustahsil_ad">Müstahsil</th><?php endif; ?>
                <th data-col="aciklama1">Açıklama-1</th><th data-col="aciklama2">Açıklama-2</th>
                <th data-col="islem">İşlem</th><th data-col="islem_zaman">İşlem Tarihi</th><th data-col="kullanici">Kullanıcı</th><th data-col="sube">Şube</th>
            </tr></thead>
            <tbody id="pfBody">
            <?php foreach ($rows as $r): ?>
                <tr data-id="<?= (int)$r['id'] ?>" data-record-id="<?= (int)$r['loading_record_id'] ?>" data-urun="<?= h($r['urun']) ?>" data-tarih="<?= h($r['tarih']) ?>" data-kasa-id="<?= (int)($r['kasa_cinsi_id'] ?? 0) ?>" data-must-id="<?= (int)($r['mustahsil_id'] ?? 0) ?>" data-kilit="<?= (!empty($r['locked_at']) || ($r['durum'] ?? '') === 'yuklendi') ? 1 : 0 ?>" data-search="<?= h(mb_strtolower(implode(' ', [(string)$r['belge_no'], (string)$r['parti_no'], (string)$r['palet_adi'], (string)$r['firma'], (string)$r['urun']]), 'UTF-8')) ?>">
                    <td class="pf-cell" data-col="belge_no" data-value="<?= (int)$r['belge_no'] ?>"><?= (int)$r['belge_no'] ?></td>
                    <td class="pf-cell" data-col="tarih" data-value="<?= h(pf_tr_tarih($r['tarih'])) ?>"><?= h(pf_tr_tarih($r['tarih'])) ?></td>
                    <td class="pf-cell" data-col="parti_no" data-value="<?= h($r['parti_no']) ?>"><?= h($r['parti_no']) ?></td>
                    <td class="pf-cell" data-col="palet_adi" data-value="<?= h($r['palet_tipi_id']) ?>"><?= h($r['palet_adi']) ?></td>
                    <td class="pf-cell" data-col="firma" data-value="<?= h($r['firma']) ?>"><?= h($r['firma']) ?></td>
                    <td class="pf-cell" data-col="kasa_adi" data-value="<?= h($r['kasa_adi']) ?>"><?= h($r['kasa_adi']) ?></td>
                    <td class="pf-cell pf-num" data-col="kasa_adeti" data-value="<?= (int)$r['kasa_adeti'] ?>"><?= number_format((int)$r['kasa_adeti'], 0, ',', '.') ?></td>
                    <td class="pf-cell pf-num" data-col="brut_kg" data-value="<?= h($r['brut_kg']) ?>"><?= number_format((float)$r['brut_kg'], 0, ',', '.') ?></td>
                    <td class="pf-cell pf-num" data-col="dara_kg" data-value="<?= h($r['dara_kg']) ?>"><?= number_format((float)$r['dara_kg'], 0, ',', '.') ?></td>
                    <td class="pf-cell pf-num" data-col="net_kg" data-value="<?= h($r['net_kg']) ?>"><?= number_format((float)$r['net_kg'], 0, ',', '.') ?></td>
                    <?php if ($has_mustahsil): ?><td class="pf-cell" data-col="mustahsil_ad" data-value="<?= h($r['mustahsil_ad']) ?>"><?= h($r['mustahsil_ad']) ?></td><?php endif; ?>
                    <td class="pf-cell" data-col="aciklama1" data-value="<?= h($r['aciklama1']) ?>"><?= h($r['aciklama1']) ?></td>
                    <td class="pf-cell" data-col="aciklama2" data-value="<?= h($r['aciklama2']) ?>"><?= h($r['aciklama2']) ?></td>
                    <td class="pf-cell" data-col="islem" data-value="<?= h(pf_islem_adi($r)) ?>"><?= h(pf_islem_adi($r)) ?></td>
                    <td class="pf-cell" data-col="islem_zaman" data-value="<?= h(pf_tr_tarih($r['islem_zaman'] ?? null, true)) ?>"><?= h(pf_tr_tarih($r['islem_zaman'] ?? null, true)) ?></td>
                    <td class="pf-cell" data-col="kullanici" data-value="<?= h($r['islem_kullanici'] ?? '') ?>"><?= h($r['islem_kullanici'] ?? '') ?></td>
                    <td class="pf-cell" data-col="sube" data-value="<?= h($r['depo'] ?? '') ?>"><?= h($r['depo'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pf-empty" id="pfEmpty" <?= $rows ? 'hidden' : '' ?>><?= $rows ? 'Aramanızla eşleşen kayıt yok.' : 'Seçilen filtreye uyan palet fişi yok. Başlangıç tarihini geri alın ya da “Yüklenen Paletleri Göster”i işaretleyip Getir’e basın; ya da F2 - Ekle ile başlayın.' ?></div>
    </div>
    <div class="pf-bottom">
        <div class="pf-kart pf-kart-parti" role="button" tabindex="0" data-pf-liste title="Partinin palet listesini aç"><i aria-hidden="true">▤</i><div><span><em class="pf-u">PARTİ</em><em class="pf-k">PARTİ</em></span><strong data-sum="parti">—</strong></div></div>
        <div class="pf-kart" role="button" tabindex="0" data-pf-liste title="Partinin palet listesini aç"><i aria-hidden="true">▥</i><div><span><em class="pf-u">PALET TOPLAMI</em><em class="pf-k">PALET</em></span><strong data-sum="palet">0</strong></div></div>
        <div class="pf-kart" role="button" tabindex="0" data-pf-liste title="Partinin palet listesini aç"><i aria-hidden="true">▢</i><div><span><em class="pf-u">AMBALAJ TOPLAMI</em><em class="pf-k">AMB.</em></span><strong data-sum="adet">0</strong></div></div>
        <div class="pf-kart" role="button" tabindex="0" data-pf-liste title="Partinin palet listesini aç"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">BRÜT</em><em class="pf-k">BRÜT</em></span><strong data-sum="brut">0</strong></div></div>
        <div class="pf-kart" role="button" tabindex="0" data-pf-liste title="Partinin palet listesini aç"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">DARA</em><em class="pf-k">DARA</em></span><strong data-sum="dara">0</strong></div></div>
        <div class="pf-kart" role="button" tabindex="0" data-pf-liste title="Partinin palet listesini aç"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">NET</em><em class="pf-k">NET</em></span><strong data-sum="net">0</strong></div></div>
        <div class="pf-kart" role="button" tabindex="0" data-pf-liste title="Partinin palet listesini aç"><i aria-hidden="true">▮</i><div><span><em class="pf-u">AMB. ORTALAMA</em><em class="pf-k">ORT.</em></span><strong data-sum="ort">0</strong></div></div>
    </div>
    <div class="pf-foot"><span>T.Kayıt: <strong id="pfCount"><?= count($rows) ?></strong></span><span class="pf-filtreler" id="pfFiltreler" hidden></span><span class="pf-status" id="pfStatus" role="status" aria-live="polite"></span></div>
</section>

<dialog class="pf-dialog" id="pfDialog">
    <form id="pfForm" method="dialog" autocomplete="off" novalidate>
        <div class="pf-dialog-head"><div><h2>Palet Fişi Ekle</h2></div><button type="button" class="pf-close" id="pfClose" aria-label="Kapat">×</button></div>
        <div class="pf-form-grid">
            <label class="pf-w3">Tarih <input name="tarih" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label class="pf-w3">Belge No <input name="belge_no" id="pfBelgeNo" value="<?= (int)$pf_sonraki_belge ?>" readonly tabindex="-1" class="pf-readonly" title="Otomatik verilir"></label>
            <label class="pf-cb pf-w6">Parti No <input name="parti_no" data-cb="parti" maxlength="100"></label>
            <label class="pf-cb pf-w6">Palet <input name="palet" data-cb="palet" class="pf-birimli"><span class="pf-birim" data-birim="palet" hidden></span></label>
            <label class="pf-cb pf-w6">Müşteri <input name="firma" data-cb="firma" maxlength="150" required></label>
            <label class="pf-cb pf-w6">Ürün <input name="urun" data-cb="urun" maxlength="150" required></label>
            <label class="pf-cb pf-w4">Kasa Ambalaj <input name="kasa" data-cb="kasa" class="pf-birimli"><span class="pf-birim" data-birim="kasa" hidden></span></label>
            <label class="pf-w2">Kasa Adedi <input name="kasa_adeti" inputmode="numeric" type="number" min="0" value="0"></label>
            <label class="pf-cb pf-must pf-w6">Müstahsil <input name="mustahsil" data-cb="mustahsil" maxlength="200" placeholder="<?= $mustahsil_aktif ? '' : ($has_mustahsil ? 'Cari İşlemler kurulu değil' : 'Kurulu değil: migrate.php › Palet Fişleri') ?>" <?= $mustahsil_aktif ? '' : 'disabled' ?>></label>
            <label class="pf-kg pf-w2">Brüt (kg) <span class="pf-kg-satir"><input name="brut_kg" inputmode="decimal" autocomplete="off"><button type="button" class="btn btn-ghost pf-kantar" hidden disabled title="Kantar entegrasyonu sonra bağlanacak">Kantardan al</button></span></label>
            <label class="pf-kg pf-w2">Dara (kg) <input name="dara_kg" id="pfDara" readonly tabindex="-1" class="pf-readonly" value="0" title="Palet + kasa adedi × kasa darasından otomatik hesaplanır"></label>
            <label class="pf-kg pf-w2">Net (kg) <input name="net_kg" id="pfNet" readonly tabindex="-1" class="pf-readonly" value="0"></label>
            <div class="pf-uyari" id="pfUyari" role="status" aria-live="polite"></div>
            <label class="pf-w6">Açıklama-1 <input name="aciklama1" maxlength="2000"></label>
            <label class="pf-w6">Açıklama-2 <input name="aciklama2" maxlength="2000"></label>
        </div>
        <div class="pf-dialog-foot"><span id="pfFormError" class="pf-error" role="alert"></span><button type="button" class="btn btn-ghost" id="pfCancel">Kapat</button><button type="submit" class="btn btn-primary" id="pfSave">Kaydet <span class="pf-shortcut">F10</span></button></div>
    </form>
</dialog>
<dialog class="pf-dialog pf-bul-dialog" id="pfBulDialog">
    <form id="pfBulForm" method="dialog" autocomplete="off">
        <div class="pf-bul-baslik" id="pfBulBaslik">Parti No</div>
        <select id="pfBulMod" aria-label="Arama biçimi">
            <option value="basla">Başlayan</option><option value="icinde">İçinde geçen</option><option value="esit">Eşit Olan</option>
            <option value="basla_degil">İle Başlamayan</option><option value="icinde_degil">İçinde Geçmeyen</option><option value="akilli" selected>Akıllı arama</option>
            <option value="bos">Boş Olan</option><option value="dolu">Dolu Olan</option><option value="esit_degil">Eşit Olmayan</option>
        </select>
        <input id="pfBulMetin" type="text" aria-label="Aranacak değer" placeholder="Aranacak değer">
        <div class="pf-bul-dugme"><button type="submit" class="pf-bul-tamam" id="pfBulTamam" title="Uygula (Enter)" aria-label="Uygula">✔</button><button type="button" class="pf-bul-iptal" id="pfBulIptal" title="Vazgeç (Esc)" aria-label="Vazgeç">✖</button></div>
    </form>
</dialog>
<dialog class="pf-dialog pf-sil-dialog" id="pfSilDialog">
    <div class="pf-dialog-head"><div><h2>Palet fişi silinsin mi?</h2></div></div>
    <p class="pf-sil-metin" id="pfSilMetin"></p>
    <div class="pf-dialog-foot pf-sil-dugme"><button type="button" class="btn btn-ghost" id="pfSilIptal">İptal</button><button type="button" class="btn btn-ghost" id="pfSilGeriAl">Geri Al</button><button type="button" class="btn pf-sil-onay" id="pfSilOnay">Sil</button></div>
</dialog>
<dialog class="pf-dialog pf-sutun-dialog" id="pfSutunDialog">
    <div class="pf-dialog-head"><div><h2>Sütunlar</h2></div><button type="button" class="pf-close" id="pfSutunKapat" aria-label="Kapat">×</button></div>
    <div class="pf-sutun-liste" id="pfSutunListe"></div>
    <div class="pf-dialog-foot"><button type="button" class="btn btn-ghost" id="pfSutunHepsi" style="margin-right:auto">Tümünü göster</button><button type="button" class="btn btn-primary" id="pfSutunTamam">Tamam</button></div>
</dialog>
<dialog class="pf-dialog pf-ozet-dialog" id="pfOzetDialog">
    <div class="pf-dialog-head"><div><h2 id="pfOzetBaslik">Parti</h2><p id="pfOzetAlt">Partinin paletleri. Bir satıra tıklarsanız listede o palete gidilir.</p></div><button type="button" class="pf-close" id="pfOzetKapat" aria-label="Kapat">×</button></div>
    <div class="pf-ozet-gov"><table class="pf-ozet-tablo" id="pfOzetTablo"><thead><tr></tr></thead><tbody></tbody><tfoot></tfoot></table></div>
</dialog>
<script>window.PALET_FISLERI = <?= json_encode(['listeler' => $pf_listeler, 'sonrakiBelge' => $pf_sonraki_belge, 'mustahsilAktif' => $mustahsil_aktif, 'tercihSunucu' => kt_hazir($pdo), 'tercih' => kt_oku($pdo, (int)($auth_user['id'] ?? 0), 'palet_fisleri.tablo'), 'baslangic' => $f_baslangic, 'yuklenen' => $f_yuklenen, 'tanimTurleri' => array_keys(pf_tanim_turleri())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/palet-fisleri.js?v=<?= (int)@filemtime(__DIR__ . '/assets/palet-fisleri.js') ?>" defer></script>
<?php render_footer();
