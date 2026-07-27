<?php
// =========================================================
// maliyet_form.php — Maliyet hesabı oluştur / düzenle
//   * Bölüm ve kalem satırları serbestçe eklenip silinebilir
//   * Kalem hesaplama tipi satır bazında seçilir (formül dahil)
//   * Kullanıcı tanımlı başlık alanları otomatik render edilir
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';
require_once __DIR__ . '/config/cost_link.php';

$auth_user = require_login();
cost_migrate();
require_maliyet('write');

$id      = (int)($_GET['id'] ?? 0);
$tpl_id  = (int)($_GET['tpl'] ?? 0);
$is_edit = $id > 0;
$uid     = (int)$auth_user['id'];

$sheet    = null;
$sections = [];
$items    = [];
$errors   = [];

// Yükleme planından ön-doldurma (?record=<id>) — YALNIZ ilk oluşturma GET'inde
// doldurulur (aşağıdaki !$is_edit && REQUEST_METHOD!=='POST' bloğu içinde).
// Düzenlemede ve POST-hata-sonrası yeniden gösterimde bu blok HİÇ çalışmaz —
// senkronizasyon değil, tek seferlik öneri (bkz. config/cost_link.php).
$record_id       = 0;
$record_summary  = null;

// ── Mevcut kaydı yükle ───────────────────────────────────
if ($is_edit) {
    $st = db()->prepare("SELECT * FROM cost_sheets WHERE id=? AND deleted_at IS NULL");
    $st->execute([$id]);
    $sheet = $st->fetch();
    if (!$sheet) { set_flash('error', 'Maliyet hesabı bulunamadı.'); header('Location: maliyet.php'); exit; }

    if (!depot_visible_to_user((string)$sheet['depo'])) {
        forbidden('Bu maliyet hesabı “' . h((string)$sheet['depo']) . '” deposuna ait — aktif deponuzda görünmüyor.');
    }
    if ($sheet['status'] === 'kesin' && !can_maliyet('unlock')) {
        set_flash('error', 'Bu hesap kesinleşmiş. Düzenlemek için kilidi açma yetkisi gerekir.');
        header('Location: maliyet_view.php?id=' . $id); exit;
    }

    $st = db()->prepare("SELECT * FROM cost_sheet_sections WHERE sheet_id=? ORDER BY sort_order ASC, id ASC");
    $st->execute([$id]);
    $sections = $st->fetchAll();

    $st = db()->prepare("SELECT * FROM cost_sheet_items WHERE sheet_id=? ORDER BY sort_order ASC, id ASC");
    $st->execute([$id]);
    $items = $st->fetchAll();
}

// ── POST: kaydet ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $post_status = ($_POST['status'] ?? 'taslak') === 'kesin' ? 'kesin' : 'taslak';

    // Başlık alanları
    $data = [
        'sheet_no'        => trim((string)($_POST['sheet_no'] ?? '')),
        'sheet_date'      => trim((string)($_POST['sheet_date'] ?? '')) ?: null,
        'title'           => trim((string)($_POST['title'] ?? '')),
        'product'         => tr_upper(trim((string)($_POST['product'] ?? ''))),
        'brand'           => trim((string)($_POST['brand'] ?? '')),
        'gumruk'          => trim((string)($_POST['gumruk'] ?? '')),
        'plaka'           => tr_upper(trim((string)($_POST['plaka'] ?? ''))),
        'alici'           => trim((string)($_POST['alici'] ?? '')),
        'firma'           => trim((string)($_POST['firma'] ?? '')),
        'gidecegi_yer'    => trim((string)($_POST['gidecegi_yer'] ?? '')),
        'ambalaj_tipi'    => trim((string)($_POST['ambalaj_tipi'] ?? '')),
        'net_kg'          => num($_POST['net_kg'] ?? 0),
        'currency_code'   => strtoupper(trim((string)($_POST['currency_code'] ?? 'EUR'))) ?: 'EUR',
        'currency_rate'   => num($_POST['currency_rate'] ?? 0),
        'freight'         => num($_POST['freight'] ?? 0),
        'sale_qty'        => num($_POST['sale_qty'] ?? 0),
        'sale_unit_price' => num($_POST['sale_unit_price'] ?? 0),
        'notes'           => trim((string)($_POST['notes'] ?? '')),
        'status'          => $post_status,
    ];
    if ($data['sheet_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['sheet_date'])) {
        $data['sheet_date'] = null;
    }
    $data['currency_code'] = substr(preg_replace('/[^A-Z]/', '', $data['currency_code']) ?: 'EUR', 0, 5);

    // Kullanıcı tanımlı alanlar
    $extra   = [];
    $post_ek = is_array($_POST['ek'] ?? null) ? $_POST['ek'] : [];
    foreach (cost_fields() as $fd) {
        $code = (string)$fd['code'];
        if ($fd['field_type'] === 'formula') continue;      // hesaplanan — saklanmaz
        if ($fd['field_type'] === 'checkbox') { $extra[$code] = !empty($post_ek[$code]) ? 1 : 0; continue; }
        $extra[$code] = substr(trim((string)($post_ek[$code] ?? '')), 0, 255);
    }
    $data['extra_json'] = json_encode($extra, JSON_UNESCAPED_UNICODE);

    // ── Bölümler ──
    $post_sec  = is_array($_POST['sec'] ?? null) ? $_POST['sec'] : [];
    $new_secs  = [];
    $key_to_id = [];   // form anahtarı → geçici id
    $tmp_id    = 0;
    foreach ($post_sec as $key => $s) {
        if (!is_array($s)) continue;
        $title = trim((string)($s['title'] ?? ''));
        if ($title === '' && trim((string)($s['code'] ?? '')) === '') continue;
        $tmp_id++;
        $code = cost_slug((string)($s['code'] ?? '') ?: $title);
        $new_secs[] = [
            'id'               => $tmp_id,
            'form_key'         => (string)$key,
            'code'             => $code,
            'title'            => $title ?: $code,
            'basis_type'       => in_array($s['basis_type'] ?? 'sheet', ['sheet','fixed','formula','none'], true) ? (string)$s['basis_type'] : 'sheet',
            'basis_value'      => num($s['basis_value'] ?? 0),
            'basis_formula'    => substr(trim((string)($s['basis_formula'] ?? '')), 0, 500),
            'basis_label'      => substr(trim((string)($s['basis_label'] ?? 'NET KG')) ?: 'NET KG', 0, 80),
            'include_in_total' => !empty($s['include_in_total']) ? 1 : 0,
            'sort_order'       => (int)($s['sort'] ?? $tmp_id) ?: $tmp_id,
        ];
        $key_to_id[(string)$key] = $tmp_id;
    }
    if (!$new_secs) {
        $new_secs = [[
            'id' => 1, 'form_key' => 's1', 'code' => 'genel', 'title' => 'Maliyet Kalemleri',
            'basis_type' => 'sheet', 'basis_value' => 0, 'basis_formula' => '', 'basis_label' => 'NET KG',
            'include_in_total' => 1, 'sort_order' => 1,
        ]];
        $key_to_id['s1'] = 1;
    }
    usort($new_secs, static fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

    // ── Kalemler ──
    $post_items = is_array($_POST['item'] ?? null) ? $_POST['item'] : [];
    $new_items  = [];
    $item_tmp   = 0;
    $used_codes = [];
    foreach ($post_items as $key => $it) {
        if (!is_array($it)) continue;
        $label = trim((string)($it['label'] ?? ''));
        if ($label === '') continue;               // boş satır → yok say
        $item_tmp++;

        $code = cost_slug((string)($it['code'] ?? '') ?: $label);
        if (isset($used_codes[$code])) { $code = substr($code, 0, 32) . '_' . $item_tmp; }
        $used_codes[$code] = true;

        $ct  = (string)($it['calc_type'] ?? 'qty_price');
        if (!array_key_exists($ct, cost_calc_types())) $ct = 'qty_price';

        $sec_key = (string)($it['section'] ?? '');
        $sec_id  = $key_to_id[$sec_key] ?? $new_secs[0]['id'];

        $new_items[] = [
            'id'           => $item_tmp,
            'section_id'   => $sec_id,
            'sort_order'   => (int)($it['sort'] ?? $item_tmp * 10) ?: $item_tmp * 10,
            'code'         => $code,
            'label'        => substr($label, 0, 200),
            'calc_type'    => $ct,
            'qty'          => num($it['qty'] ?? 0),
            'qty_formula'  => substr(trim((string)($it['qty_formula'] ?? '')), 0, 500),
            'unit'         => substr(trim((string)($it['unit'] ?? '')), 0, 24),
            'unit_price'   => num($it['unit_price'] ?? 0),
            'percent'      => num($it['percent'] ?? 0),
            'percent_base' => substr(trim((string)($it['percent_base'] ?? 'ust_toplam')) ?: 'ust_toplam', 0, 500),
            'formula'      => substr(trim((string)($it['formula'] ?? '')), 0, 500),
            'is_income'    => !empty($it['is_income']) ? 1 : 0,
            'note'         => substr(trim((string)($it['note'] ?? '')), 0, 255),
        ];
    }
    usort($new_items, static fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

    // Formül sözdizimi ön kontrolü — hatalıysa kaydetmeden uyar
    foreach ($new_items as $ni) {
        foreach (['qty_formula' => 'miktar formülü', 'formula' => 'formül', 'percent_base' => 'yüzde bazı'] as $fk => $fl) {
            if (trim((string)$ni[$fk]) === '') continue;
            if ($fk === 'formula' && $ni['calc_type'] !== 'formula') continue;
            if ($fk === 'percent_base' && $ni['calc_type'] !== 'percent') continue;
            $e = cost_formula_check((string)$ni[$fk]);
            if ($e) $errors[] = '“' . $ni['label'] . '” ' . $fl . ': ' . $e;
        }
    }
    foreach ($new_secs as $ns) {
        if ($ns['basis_type'] === 'formula' && ($e = cost_formula_check($ns['basis_formula']))) {
            $errors[] = 'Bölüm “' . $ns['title'] . '” bazı: ' . $e;
        }
    }

    if (!$errors) {
        // Sunucu tarafı hesap — kaydedilen tutarların tek otoritesi
        $calc = cost_compute($data + ['id' => $id], $new_secs, $new_items);
        foreach ($calc['errors'] as $ce) $errors[] = $ce;

        $data['totals_json'] = json_encode($calc['totals'], JSON_UNESCAPED_UNICODE);

        try {
            db()->beginTransaction();

            if ($is_edit) {
                $old = $sheet;
                $was_locked = ($sheet['status'] === 'kesin');
                $sql_set = [];
                foreach (array_keys($data) as $k) $sql_set[] = "`$k`=:$k";
                $sql_set[] = "`updated_by`=:ub";
                $sql_set[] = "`updated_at`=NOW()";
                if ($was_locked && $post_status === 'taslak') {
                    $sql_set[] = "`locked_at`=NULL";
                    $sql_set[] = "`locked_by`=NULL";
                    $sql_set[] = "`revision_reason`=:rr";
                }
                if (!$was_locked && $post_status === 'kesin') {
                    $sql_set[] = "`locked_at`=NOW()";
                    $sql_set[] = "`locked_by`=:lb";
                }
                $st = db()->prepare("UPDATE cost_sheets SET " . implode(',', $sql_set) . " WHERE id=:id");
                $bind = $data;
                $bind['ub'] = $uid;
                $bind['id'] = $id;
                if ($was_locked && $post_status === 'taslak') $bind['rr'] = trim((string)($_POST['revision_reason'] ?? 'Revizyon'));
                if (!$was_locked && $post_status === 'kesin') $bind['lb'] = $uid;
                $st->execute($bind);
                $sheet_id = $id;
            } else {
                $record_id_post = (int)($_POST['record_id'] ?? 0);

                $data['depo']       = (string)(active_depot() ?? '');
                $data['template_id']= $tpl_id ?: null;
                // record_id/brut_kg BİLEREK $data'nın (ve dolayısıyla yukarıdaki
                // UPDATE dalının) dışında tutulur — ikisi de yalnız İLK OLUŞTURMADA
                // yazılır, sonradan hiçbir düzenleme bunlara dokunmaz (madde 8).
                //
                // Kolon VARLIĞI kontrol edilir: migration (ALTER) bir kurulumda
                // çalışmamış olabilir (ör. DB kullanıcısının ALTER yetkisi yok).
                // O durumda bağlantı özelliği devre dışı kalır ama KAYIT ÇALIŞMAYA
                // DEVAM EDER — maliyet hesabı, bağlantı kolonlarına bağımlı değildir.
                if (db_has_column('cost_sheets', 'record_id')) $data['record_id'] = $record_id_post ?: null;
                if (db_has_column('cost_sheets', 'brut_kg'))   $data['brut_kg']   = num($_POST['brut_kg'] ?? 0);

                $cols = array_keys($data);
                $st = db()->prepare("INSERT INTO cost_sheets (`" . implode('`,`', $cols) . "`, `created_by`)
                                     VALUES (:" . implode(',:', $cols) . ", :cb)");
                $st->execute($data + ['cb' => $uid]);
                $sheet_id = (int)db()->lastInsertId();
                if ($post_status === 'kesin') {
                    db()->prepare("UPDATE cost_sheets SET locked_at=NOW(), locked_by=? WHERE id=?")->execute([$uid, $sheet_id]);
                }
                // linked_at: SADECE ilk oluşturma anında, SQL NOW() ile (loading_records
                // ile aynı saat kaynağı — bkz. cost_link_stale_info() PHP/DB saat kayması notu).
                // Asla güncellenmez; bu satır bu kod yolunda BİR KEZ çalışır.
                if ($record_id_post > 0 && db_has_column('cost_sheets', 'linked_at')) {
                    db()->prepare("UPDATE cost_sheets SET linked_at = NOW() WHERE id = ?")->execute([$sheet_id]);
                }
            }

            // Bölüm ve kalemler tam olarak yeniden yazılır (sıralama/silme sadeliği için)
            db()->prepare("DELETE FROM cost_sheet_items    WHERE sheet_id=?")->execute([$sheet_id]);
            db()->prepare("DELETE FROM cost_sheet_sections WHERE sheet_id=?")->execute([$sheet_id]);

            $ins_s = db()->prepare("INSERT INTO cost_sheet_sections
                (sheet_id, code, title, basis_type, basis_value, basis_formula, basis_label, include_in_total, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $sec_real = [];
            foreach ($new_secs as $ns) {
                $ins_s->execute([$sheet_id, $ns['code'], $ns['title'], $ns['basis_type'], $ns['basis_value'],
                                 $ns['basis_formula'], $ns['basis_label'], $ns['include_in_total'], $ns['sort_order']]);
                $sec_real[$ns['id']] = (int)db()->lastInsertId();
            }

            $ins_i = db()->prepare("INSERT INTO cost_sheet_items
                (sheet_id, section_id, sort_order, code, label, calc_type, qty, qty_formula, unit,
                 unit_price, percent, percent_base, formula, is_income, amount, unit_cost, note)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($new_items as $ni) {
                $c = $calc['items'][$ni['id']] ?? ['amount' => 0, 'unit_cost' => 0, 'qty' => $ni['qty']];
                $ins_i->execute([
                    $sheet_id, $sec_real[$ni['section_id']] ?? null, $ni['sort_order'], $ni['code'], $ni['label'],
                    $ni['calc_type'], $c['qty'], $ni['qty_formula'], $ni['unit'], $ni['unit_price'],
                    $ni['percent'], $ni['percent_base'], $ni['formula'], $ni['is_income'],
                    $c['amount'], $c['unit_cost'], $ni['note'],
                ]);
            }

            db()->commit();

            audit_log_event(
                $is_edit ? 'update' : 'create',
                'maliyet',
                $sheet_id,
                $is_edit ? ['status' => $sheet['status'], 'net_kg' => $sheet['net_kg']] : null,
                ['sheet_no' => $data['sheet_no'], 'product' => $data['product'], 'status' => $post_status,
                 'net_kg' => $data['net_kg'], 'toplam' => $calc['totals']['grand_total'],
                 'kalem_sayisi' => count($new_items), 'record_id' => $data['record_id'] ?? null]
            );

            $msg = 'Maliyet hesabı kaydedildi.';
            if ($calc['errors']) $msg .= ' (Uyarı: ' . count($calc['errors']) . ' formül uyarısı var.)';
            set_flash($calc['errors'] ? 'error' : 'success', $msg);
            header('Location: maliyet_view.php?id=' . $sheet_id);
            exit;

        } catch (PDOException $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('[maliyet_form save] ' . $e->getMessage());
            // Yöneticiye gerçek hatayı göster — aksi halde canlıda teşhis
            // imkansız oluyor (log erişimi olmayabilir). Diğer roller için
            // hassas ayrıntı sızdırmayan genel mesaj korunur.
            $errors[] = (function_exists('is_admin') && is_admin())
                ? 'Kayıt sırasında veritabanı hatası: ' . $e->getMessage()
                : 'Kayıt sırasında veritabanı hatası oluştu.';
        }
    }

    // Hata varsa girilen veriyi forma geri bas — record_id/brut_kg şu an
    // $data'nın DIŞINDA tutuluyor (bkz. aşağıdaki !$is_edit dalı), o yüzden
    // redisplay'de kaybolmasınlar diye burada elle eklenir.
    $sheet    = $data + [
        'id' => $id,
        'depo' => $sheet['depo'] ?? (string)(active_depot() ?? ''),
        'record_id' => (int)($_POST['record_id'] ?? 0),
        'brut_kg'   => num($_POST['brut_kg'] ?? 0),
    ];
    $sections = [];
    foreach ($new_secs as $ns) { $ns['id'] = $ns['form_key']; $sections[] = $ns; }
    $items = [];
    foreach ($new_items as $ni) {
        $sec_key = 's' . $ni['section_id'];
        foreach ($new_secs as $ns) if ($ns['id'] === $ni['section_id']) $sec_key = $ns['form_key'];
        $ni['section_key'] = $sec_key;
        $items[] = $ni;
    }
}

// ── Yeni kayıt: şablondan doldur (+ opsiyonel: yükleme planından ön-doldur) ──
if (!$is_edit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($tpl_id === 0) {
        $tpl_id = (int)(db()->query("SELECT id FROM cost_templates WHERE is_active=1
                                     ORDER BY is_default DESC, id ASC LIMIT 1")->fetchColumn() ?: 0);
    }
    if ($tpl_id > 0) {
        $tpl      = cost_link_load_template($tpl_id);
        $sections = $tpl['sections'];
        $items    = $tpl['items'];
    }
    $tpl_row = null;
    if ($tpl_id > 0) {
        $st = db()->prepare("SELECT * FROM cost_templates WHERE id=?");
        $st->execute([$tpl_id]);
        $tpl_row = $st->fetch() ?: null;
    }

    // Parti No'dan ön-doldurma — bu blok yalnız burada, yalnız bir kez çalışır.
    $record_id = (int)($_GET['record'] ?? 0);
    if ($record_id > 0) {
        $record_summary = cost_link_record_summary($record_id);
        if ($record_summary === null) {
            set_flash('error', 'Seçilen yükleme kaydı bulunamadı.');
            $record_id = 0;
        } elseif (!depot_visible_to_user($record_summary['depo'])) {
            set_flash('error', 'Bu yükleme kaydı “' . $record_summary['depo'] . '” deposuna ait — aktif deponuzda görünmüyor.');
            $record_id      = 0;
            $record_summary = null;
        } else {
            $materials = cost_link_materials($record_id);
            $applied   = cost_link_apply($sections, $items, $materials);
            $sections  = $applied['sections'];
            $items     = $applied['items'];
        }
    }
}

// Varsayılanlar
$sheet = $sheet ?: [
    'id' => 0, 'sheet_no' => '', 'sheet_date' => date('Y-m-d'), 'title' => '', 'product' => '',
    'brand' => '', 'gumruk' => '', 'plaka' => '', 'alici' => '', 'firma' => '', 'gidecegi_yer' => '',
    'ambalaj_tipi' => '', 'net_kg' => 0, 'currency_code' => 'EUR', 'currency_rate' => 0, 'freight' => 0,
    'sale_qty' => 0, 'sale_unit_price' => 0, 'extra_json' => '', 'notes' => '', 'status' => 'taslak',
    'depo' => (string)(active_depot() ?? ''),
];

// Yükleme planından ön-doldurulan başlık alanları. sheet_no'ya KESİNLİKLE
// dokunulmaz — Sheet No (belge no) ile Parti No ayrı kavramlardır (bkz.
// config/cost_link.php). brut_kg burada Adım 1'de eklenen bilgi amaçlı
// kolondur; hesaba girmez, yalnız gösterim/kayıt amaçlıdır.
if ($record_summary !== null) {
    $sheet['product']  = $record_summary['product'];
    $sheet['firma']    = $record_summary['firma'];
    $sheet['alici']    = $record_summary['alici'];
    $sheet['brand']    = $record_summary['brand'];
    $sheet['gumruk']   = $record_summary['gumruk'];
    $sheet['plaka']    = $record_summary['plaka'];
    if ($record_summary['sheet_date']) $sheet['sheet_date'] = $record_summary['sheet_date'];
    $sheet['net_kg']   = $record_summary['net_kg'];
    $sheet['brut_kg']  = $record_summary['brut_kg'];
}

if (!$sections) {
    $sections = [[
        'id' => 's1', 'code' => 'genel', 'title' => 'Maliyet Kalemleri', 'basis_type' => 'sheet',
        'basis_value' => 0, 'basis_formula' => '', 'basis_label' => 'NET KG',
        'include_in_total' => 1, 'sort_order' => 1,
    ]];
}

// Kalemleri bölüme göre grupla (form_key bazlı)
$sec_keys = [];
foreach ($sections as $s) $sec_keys[(string)$s['id']] = $s;
$items_by_sec = [];
foreach ($items as $it) {
    $k = (string)($it['section_key'] ?? $it['section_id'] ?? '');
    if (!isset($sec_keys[$k])) $k = (string)$sections[0]['id'];
    $items_by_sec[$k][] = $it;
}

$extra_vals = [];
if (!empty($sheet['extra_json'])) {
    $d = json_decode((string)$sheet['extra_json'], true);
    if (is_array($d)) $extra_vals = $d;
}

$field_defs   = cost_fields();
$calc_types   = cost_calc_types();
$pct_bases    = cost_percent_bases();
$sys_vars     = cost_system_vars();
$templates    = db()->query("SELECT id, name, product FROM cost_templates WHERE is_active=1 ORDER BY is_default DESC, name")->fetchAll();
$pkg_prices   = db()->query("SELECT id, firma, urun, fiyat, birim FROM cost_packaging_prices
                             WHERE is_active=1 ORDER BY urun ASC")->fetchAll();
$brand_defs   = get_definitions_by_type('marka');
$urun_defs    = get_definitions_by_type('urun');

// Düzenleme modunda: bağlı olduğu parti no'yu göstermek için (salt-okunur,
// madde 8 — burada hiçbir alan yeniden doldurulmaz, sadece bilgi amaçlı okunur).
$edit_record_parti = null;
$edit_stale_info    = null;
if ($is_edit && !empty($sheet['record_id'])) {
    $edit_record_parti = cost_link_record_summary((int)$sheet['record_id'])['parti_no'] ?? null;
    // Bilgi amaçlı, buluşsal bir uyarı — hiçbir alanı değiştirmez, kaydetmeyi
    // engellemez (bkz. config/cost_link.php::cost_link_stale_info()).
    $edit_stale_info = cost_link_stale_info((int)$sheet['record_id'], $sheet['linked_at'] ?? null);
}

render_header($is_edit ? 'Maliyet Hesabı Düzenle' : 'Yeni Maliyet Hesabı');
render_flash();
?>
<link rel="stylesheet" href="assets/maliyet.css?v=<?= @filemtime(__DIR__ . '/assets/maliyet.css') ?: time() ?>">

<div class="page-head">
    <div>
        <h1><?= $is_edit ? '✏️ Maliyet Hesabı Düzenle' : '🧮 Yeni Maliyet Hesabı' ?></h1>
        <p class="muted">
            <?php if ($is_edit): ?>
                #<?= (int)$sheet['id'] ?> · <?= h($sheet['sheet_no'] ?: 'Belge no yok') ?>
            <?php elseif ($record_summary !== null): ?>
                Parti: <strong><?= h($record_summary['parti_no'] ?: '—') ?></strong> — yükleme planından dolduruldu
                <?php if (!empty($tpl_row)): ?> · Şablon: <strong><?= h($tpl_row['name']) ?></strong><?php endif; ?>
            <?php elseif (!empty($tpl_row)): ?>
                Şablon: <strong><?= h($tpl_row['name']) ?></strong>
            <?php else: ?>
                Boş sayfa — kalemleri kendiniz ekleyin
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head-actions">
        <a href="<?= $is_edit ? 'maliyet_view.php?id=' . (int)$sheet['id'] : 'maliyet.php' ?>" class="btn">Vazgeç</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-error">
    <strong>Kaydedilemedi / uyarılar:</strong>
    <ul style="margin:6px 0 0;padding-left:18px">
        <?php foreach (array_slice($errors, 0, 12) as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!$is_edit && count($templates) > 1): ?>
<div class="mly-tpl-bar no-print">
    <span class="muted">Şablon:</span>
    <?php foreach ($templates as $t): ?>
    <a href="maliyet_form.php?tpl=<?= (int)$t['id'] ?>"
       class="btn btn-sm <?= $tpl_id === (int)$t['id'] ? 'btn-primary' : '' ?>"><?= h($t['name']) ?></a>
    <?php endforeach; ?>
    <a href="maliyet_form.php?tpl=-1" class="btn btn-sm <?= $tpl_id < 0 ? 'btn-primary' : '' ?>">Boş</a>
</div>
<?php endif; ?>

<form method="post" id="mlyForm" autocomplete="off">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="status" id="mlyStatus" value="<?= h($sheet['status']) ?>">
<input type="hidden" name="record_id" value="<?= (int)($sheet['record_id'] ?? $record_id) ?>">
<input type="hidden" name="brut_kg" value="<?= fmt_edit_num($sheet['brut_kg'] ?? 0, 3) ?>">

<!-- ═══ GENEL BİLGİLER ═══ -->
<div class="form-section">
    <div class="form-section-title">📋 Genel Bilgiler</div>

    <?php if (!$is_edit): ?>
    <div class="mly-parti-search no-print" id="mlyPartiSearchWrap">
        <label for="mlyPartiInput">Parti No <small>(opsiyonel — yükleme planından doldurmak için)</small></label>
        <div class="mly-parti-search-box">
            <input type="text" id="mlyPartiInput" autocomplete="off" spellcheck="false"
                   placeholder="Parti no, firma veya plaka yazın…"
                   value="<?= $record_summary !== null
                        ? h(trim($record_summary['parti_no'] . ' · ' . ($record_summary['firma'] ?: '—')))
                        : '' ?>">
            <button type="button" id="mlyPartiClear" class="btn btn-sm" <?= $record_id > 0 ? '' : 'hidden' ?>>✕ Temizle</button>
        </div>
        <div class="mly-parti-panel" id="mlyPartiPanel" hidden></div>
        <div class="mly-parti-status" id="mlyPartiStatus"></div>
    </div>
    <?php elseif ($edit_record_parti !== null): ?>
    <div class="mly-parti-search no-print">
        <span class="muted">Parti No: <strong><?= h($edit_record_parti) ?></strong>
            <small>— yükleme planından bağlı, değiştirilemez</small></span>
        <?php if ($edit_stale_info !== null): ?>
        <div class="flash flash-error" style="margin-top:8px">
            ⚠️ Bu maliyet oluşturulduktan sonra yükleme planı değiştirilmiştir. Verileri kontrol etmeniz önerilir.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="grid mly-grid">
        <label>Belge No
            <input type="text" name="sheet_no" value="<?= h($sheet['sheet_no']) ?>" placeholder="Serbest belge/dosya no">
        </label>
        <label>Tarih
            <input type="date" name="sheet_date" value="<?= h($sheet['sheet_date']) ?>">
        </label>
        <label>Ürün
            <input type="text" name="product" list="mlyUrunList" value="<?= h($sheet['product']) ?>" placeholder="KAYISI">
            <datalist id="mlyUrunList">
                <?php foreach ($urun_defs as $u): ?><option value="<?= h($u['name']) ?>"><?php endforeach; ?>
            </datalist>
        </label>
        <label>Marka
            <input type="text" name="brand" list="mlyMarkaList" value="<?= h($sheet['brand']) ?>">
            <datalist id="mlyMarkaList">
                <?php foreach ($brand_defs as $b): ?><option value="<?= h($b['name']) ?>"><?php endforeach; ?>
            </datalist>
        </label>
        <label>Gümrük
            <input type="text" name="gumruk" value="<?= h($sheet['gumruk']) ?>">
        </label>
        <label>Plaka
            <input type="text" name="plaka" value="<?= h($sheet['plaka']) ?>">
        </label>
        <label>Alıcı
            <input type="text" name="alici" value="<?= h($sheet['alici']) ?>">
        </label>
        <label>Firma
            <input type="text" name="firma" value="<?= h($sheet['firma']) ?>">
        </label>
        <label>Gideceği Yer
            <input type="text" name="gidecegi_yer" value="<?= h($sheet['gidecegi_yer']) ?>">
        </label>
        <label>Ambalaj / Yükleme Tipi
            <input type="text" name="ambalaj_tipi" value="<?= h($sheet['ambalaj_tipi']) ?>" placeholder="DÖKME KASA">
        </label>
        <label class="mly-hl">Net KG <small>hesabın bölme tabanı</small>
            <input type="text" inputmode="decimal" name="net_kg" id="mlyNetKg"
                   value="<?= fmt_edit_num($sheet['net_kg'], 0) ?>" data-mly="net_kg">
        </label>
        <label>Başlık / Açıklama
            <input type="text" name="title" value="<?= h($sheet['title']) ?>" placeholder="Serbest başlık">
        </label>

        <?php foreach ($field_defs as $fd):
            $code = (string)$fd['code'];
            $val  = (string)($extra_vals[$code] ?? $fd['default_value']);
        ?>
        <label class="mly-userfield">
            <?= h($fd['label']) ?>
            <?php if ($fd['suffix']): ?><small>(<?= h($fd['suffix']) ?>)</small><?php endif; ?>
            <?php if ($fd['field_type'] === 'select'):
                $opts = preg_split('/\r\n|\r|\n/', (string)$fd['options']) ?: []; ?>
                <select name="ek[<?= h($code) ?>]">
                    <option value="">—</option>
                    <?php foreach ($opts as $o): $o = trim($o); if ($o === '') continue; ?>
                    <option value="<?= h($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($fd['field_type'] === 'checkbox'): ?>
                <select name="ek[<?= h($code) ?>]" data-mly="<?= h($code) ?>">
                    <option value="0" <?= !$val ? 'selected' : '' ?>>Hayır</option>
                    <option value="1" <?= $val ? 'selected' : '' ?>>Evet</option>
                </select>
            <?php elseif ($fd['field_type'] === 'date'): ?>
                <input type="date" name="ek[<?= h($code) ?>]" value="<?= h($val) ?>">
            <?php elseif ($fd['field_type'] === 'textarea'): ?>
                <textarea name="ek[<?= h($code) ?>]" rows="2"><?= h($val) ?></textarea>
            <?php elseif ($fd['field_type'] === 'formula'): ?>
                <output class="mly-out" data-mly-field="<?= h($code) ?>" data-dec="<?= (int)$fd['decimals'] ?>">0</output>
                <small class="muted"><?= h($fd['formula']) ?></small>
            <?php elseif ($fd['field_type'] === 'number'): ?>
                <input type="text" inputmode="decimal" name="ek[<?= h($code) ?>]"
                       value="<?= h($val) ?>" data-mly="<?= h($code) ?>">
            <?php else: ?>
                <input type="text" name="ek[<?= h($code) ?>]" value="<?= h($val) ?>">
            <?php endif; ?>
            <?php if ($fd['hint']): ?><small class="muted"><?= h($fd['hint']) ?></small><?php endif; ?>
        </label>
        <?php endforeach; ?>
    </div>
    <?php if (can_maliyet('admin')): ?>
    <p class="muted" style="margin:10px 0 0">
        Yeni alan mı gerekiyor? <a href="maliyet_alanlar.php">Alan &amp; Formül Tanımları</a> sayfasından ekleyin —
        buraya ve tüm formüllere otomatik gelir.
    </p>
    <?php endif; ?>
</div>

<!-- ═══ BÖLÜMLER + KALEMLER ═══ -->
<div id="mlySections">
<?php foreach ($sections as $si => $sec):
    $skey  = (string)$sec['id'];
    $srows = $items_by_sec[$skey] ?? [];
?>
<div class="mly-section" data-sec-key="<?= h($skey) ?>">
    <input type="hidden" name="sec[<?= h($skey) ?>][sort]" value="<?= (int)($sec['sort_order'] ?: $si + 1) ?>" class="mly-sec-sort">

    <div class="mly-section-head">
        <div class="mly-section-title-row">
            <input type="text" class="mly-sec-title" name="sec[<?= h($skey) ?>][title]"
                   value="<?= h($sec['title']) ?>" placeholder="Bölüm adı">
            <input type="hidden" name="sec[<?= h($skey) ?>][code]" value="<?= h($sec['code']) ?>">
            <button type="button" class="btn btn-sm btn-ghost mly-sec-toggle" title="Bölüm ayarları">⚙️</button>
            <button type="button" class="btn btn-sm btn-danger mly-sec-del" title="Bölümü sil">✕</button>
        </div>

        <div class="mly-sec-opts" hidden>
            <label>TL/KG böleni
                <select name="sec[<?= h($skey) ?>][basis_type]" class="mly-basis-type">
                    <option value="sheet"   <?= $sec['basis_type'] === 'sheet'   ? 'selected' : '' ?>>Sayfa Net KG</option>
                    <option value="fixed"   <?= $sec['basis_type'] === 'fixed'   ? 'selected' : '' ?>>Sabit değer</option>
                    <option value="formula" <?= $sec['basis_type'] === 'formula' ? 'selected' : '' ?>>Formül</option>
                    <option value="none"    <?= $sec['basis_type'] === 'none'    ? 'selected' : '' ?>>Yok (birim maliyet gösterme)</option>
                </select>
            </label>
            <label class="mly-basis-fixed" <?= $sec['basis_type'] === 'fixed' ? '' : 'hidden' ?>>Sabit baz
                <input type="text" inputmode="decimal" name="sec[<?= h($skey) ?>][basis_value]"
                       value="<?= fmt_edit_num($sec['basis_value'], 0) ?>">
            </label>
            <label class="mly-basis-formula" <?= $sec['basis_type'] === 'formula' ? '' : 'hidden' ?>>Baz formülü
                <input type="text" name="sec[<?= h($skey) ?>][basis_formula]"
                       value="<?= h($sec['basis_formula']) ?>" placeholder="[alim_kg]-[cikma_kg]">
            </label>
            <label>Baz etiketi
                <input type="text" name="sec[<?= h($skey) ?>][basis_label]" value="<?= h($sec['basis_label'] ?: 'NET KG') ?>">
            </label>
            <label class="mly-check">
                <input type="checkbox" name="sec[<?= h($skey) ?>][include_in_total]" value="1"
                       class="mly-sec-include" <?= (int)$sec['include_in_total'] === 1 ? 'checked' : '' ?>>
                Genel toplama dahil et
            </label>
        </div>
    </div>

    <div class="table-wrap mly-table-wrap">
        <table class="mly-table">
            <thead>
                <tr>
                    <th class="mly-c-drag"></th>
                    <th class="mly-c-label">Kalem</th>
                    <th class="mly-c-type">Hesap Tipi</th>
                    <th class="mly-c-qty num">Miktar</th>
                    <th class="mly-c-unit">Birim</th>
                    <th class="mly-c-price num">Birim Fiyat</th>
                    <th class="mly-c-amount num">Tutar (TL)</th>
                    <th class="mly-c-ucost num">TL/KG</th>
                    <th class="mly-c-act"></th>
                </tr>
            </thead>
            <tbody class="mly-items">
            <?php foreach ($srows as $ri => $it):
                $ikey = (string)($it['id'] ?? ('n' . $ri));
                include __DIR__ . '/_maliyet_row.php';
            endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="mly-sec-total">
                    <td colspan="6" class="right"><strong>Bölüm Toplamı</strong>
                        <span class="muted mly-sec-basis-txt"></span></td>
                    <td class="num"><strong class="mly-sec-total-amount">0,00</strong></td>
                    <td class="num"><strong class="mly-sec-total-unit">0,0000</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="mly-section-foot">
        <button type="button" class="btn btn-sm mly-add-item">+ Kalem Ekle</button>
        <span class="muted mly-sec-hint">Kalem kodları formüllerde <code>[kod]</code> ile kullanılır.</span>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="mly-add-section-bar no-print">
    <button type="button" class="btn" id="mlyAddSection">+ Bölüm Ekle</button>
    <span class="muted">Ayrı bir hesap bloğu (ör. depo ortalaması) açar — kendi bazı ve toplamı olur.</span>
</div>

<!-- ═══ ÖZET / DÖVİZ / KÂR ═══ -->
<div class="form-section">
    <div class="form-section-title">💱 Döviz, Navlun ve Fatura</div>
    <div class="grid mly-grid">
        <label>Döviz
            <input type="text" name="currency_code" id="mlyCur" value="<?= h($sheet['currency_code']) ?>" maxlength="5">
        </label>
        <label>Kur (1 döviz = ? TL)
            <input type="text" inputmode="decimal" name="currency_rate" value="<?= fmt_edit_num($sheet['currency_rate'], 4) ?>" data-mly="kur">
        </label>
        <label>Navlun (döviz)
            <input type="text" inputmode="decimal" name="freight" value="<?= fmt_edit_num($sheet['freight'], 2) ?>" data-mly="navlun">
        </label>
        <label>Fatura KG
            <input type="text" inputmode="decimal" name="sale_qty" value="<?= fmt_edit_num($sheet['sale_qty'], 0) ?>" data-mly="satis_kg">
        </label>
        <label>Fatura Birim Fiyatı (döviz/kg)
            <input type="text" inputmode="decimal" name="sale_unit_price" value="<?= fmt_edit_num($sheet['sale_unit_price'], 4) ?>" data-mly="satis_fiyat">
        </label>
    </div>

    <div class="mly-summary" id="mlySummary">
        <div class="mly-sum-box"><span>Toplam Maliyet</span><strong data-sum="grand_total">0,00</strong><small>TL</small></div>
        <div class="mly-sum-box"><span>KG Maliyeti</span><strong data-sum="unit_cost_tl">0,0000</strong><small>TL/KG</small></div>
        <div class="mly-sum-box"><span>TIR Üstü Maliyet</span><strong data-sum="total_fx">0,00</strong><small class="mly-cur">EUR</small></div>
        <div class="mly-sum-box"><span>Navlun Dahil</span><strong data-sum="total_cost_fx">0,00</strong><small class="mly-cur">EUR</small></div>
        <div class="mly-sum-box"><span>Fatura Bedeli</span><strong data-sum="invoice_fx">0,00</strong><small class="mly-cur">EUR</small></div>
        <div class="mly-sum-box mly-sum-profit"><span>Kâr / Zarar</span><strong data-sum="profit_fx">0,00</strong><small class="mly-cur">EUR</small></div>
    </div>
    <div class="mly-warn" id="mlyWarn" hidden></div>
</div>

<div class="form-section">
    <div class="form-section-title">📝 Not</div>
    <textarea name="notes" rows="3" style="width:100%"><?= h($sheet['notes']) ?></textarea>
    <?php if ($is_edit && $sheet['status'] === 'kesin'): ?>
    <label style="display:block;margin-top:12px">Revizyon Nedeni <span style="color:var(--danger)">*</span>
        <input type="text" name="revision_reason" required
               placeholder="Kesinleşmiş hesap değiştiriliyor — neden?">
    </label>
    <?php endif; ?>
</div>

<div class="form-foot mly-form-foot">
    <button type="submit" class="btn btn-primary btn-lg" onclick="document.getElementById('mlyStatus').value='taslak'">
        💾 Kaydet (Taslak)
    </button>
    <button type="submit" class="btn btn-secondary btn-lg" onclick="document.getElementById('mlyStatus').value='kesin'">
        🔒 Kaydet ve Kesinleştir
    </button>
    <a href="<?= $is_edit ? 'maliyet_view.php?id=' . (int)$sheet['id'] : 'maliyet.php' ?>" class="btn btn-lg">Vazgeç</a>
</div>
</form>

<!-- Kalem satırı şablonu (JS klonlar) -->
<template id="mlyRowTpl">
<?php
$it   = ['id' => '__KEY__', 'code' => '', 'label' => '', 'calc_type' => 'qty_price', 'qty' => 0,
         'qty_formula' => '', 'unit' => '', 'unit_price' => 0, 'percent' => 0,
         'percent_base' => 'ust_toplam', 'formula' => '', 'is_income' => 0, 'note' => ''];
$ikey = '__KEY__';
$skey = '__SEC__';
include __DIR__ . '/_maliyet_row.php';
?>
</template>

<!-- Bölüm şablonu -->
<template id="mlySecTpl">
<div class="mly-section" data-sec-key="__SEC__">
    <input type="hidden" name="sec[__SEC__][sort]" value="99" class="mly-sec-sort">
    <div class="mly-section-head">
        <div class="mly-section-title-row">
            <input type="text" class="mly-sec-title" name="sec[__SEC__][title]" value="" placeholder="Bölüm adı">
            <input type="hidden" name="sec[__SEC__][code]" value="">
            <button type="button" class="btn btn-sm btn-ghost mly-sec-toggle" title="Bölüm ayarları">⚙️</button>
            <button type="button" class="btn btn-sm btn-danger mly-sec-del" title="Bölümü sil">✕</button>
        </div>
        <div class="mly-sec-opts" hidden>
            <label>TL/KG böleni
                <select name="sec[__SEC__][basis_type]" class="mly-basis-type">
                    <option value="sheet">Sayfa Net KG</option>
                    <option value="fixed">Sabit değer</option>
                    <option value="formula">Formül</option>
                    <option value="none">Yok (birim maliyet gösterme)</option>
                </select>
            </label>
            <label class="mly-basis-fixed" hidden>Sabit baz
                <input type="text" inputmode="decimal" name="sec[__SEC__][basis_value]" value="">
            </label>
            <label class="mly-basis-formula" hidden>Baz formülü
                <input type="text" name="sec[__SEC__][basis_formula]" value="" placeholder="[alim_kg]-[cikma_kg]">
            </label>
            <label>Baz etiketi
                <input type="text" name="sec[__SEC__][basis_label]" value="NET KG">
            </label>
            <label class="mly-check">
                <input type="checkbox" name="sec[__SEC__][include_in_total]" value="1" class="mly-sec-include" checked>
                Genel toplama dahil et
            </label>
        </div>
    </div>
    <div class="table-wrap mly-table-wrap">
        <table class="mly-table">
            <thead>
                <tr>
                    <th class="mly-c-drag"></th>
                    <th class="mly-c-label">Kalem</th>
                    <th class="mly-c-type">Hesap Tipi</th>
                    <th class="mly-c-qty num">Miktar</th>
                    <th class="mly-c-unit">Birim</th>
                    <th class="mly-c-price num">Birim Fiyat</th>
                    <th class="mly-c-amount num">Tutar (TL)</th>
                    <th class="mly-c-ucost num">TL/KG</th>
                    <th class="mly-c-act"></th>
                </tr>
            </thead>
            <tbody class="mly-items"></tbody>
            <tfoot>
                <tr class="mly-sec-total">
                    <td colspan="6" class="right"><strong>Bölüm Toplamı</strong> <span class="muted mly-sec-basis-txt"></span></td>
                    <td class="num"><strong class="mly-sec-total-amount">0,00</strong></td>
                    <td class="num"><strong class="mly-sec-total-unit">0,0000</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="mly-section-foot">
        <button type="button" class="btn btn-sm mly-add-item">+ Kalem Ekle</button>
        <span class="muted mly-sec-hint">Kalem kodları formüllerde <code>[kod]</code> ile kullanılır.</span>
    </div>
</div>
</template>

<datalist id="mlyPkgList">
    <?php foreach ($pkg_prices as $p): ?>
    <option value="<?= h($p['urun']) ?>" data-fiyat="<?= h((string)$p['fiyat']) ?>"><?= h($p['firma']) ?> · <?= cost_fmt_money($p['fiyat'], 4) ?> ₺</option>
    <?php endforeach; ?>
</datalist>

<datalist id="mlyUnitList">
    <?php foreach (['adet', 'kg', 'palet', 'kasa', 'koli', 'ton', 'metre', 'sefer'] as $u): ?>
    <option value="<?= h($u) ?>">
    <?php endforeach; ?>
</datalist>

<datalist id="mlyPctBaseList">
    <?php foreach ($pct_bases as $code => $label): ?>
    <option value="<?= h($code) ?>"><?= h($label) ?></option>
    <?php endforeach; ?>
</datalist>

<details class="mly-help no-print" style="margin-top:18px">
    <summary style="cursor:pointer;font-weight:700">📖 Formül yardımı</summary>
    <p style="margin:10px 0 6px">
        Formüllerde diğer kalemlere <code>[kod]</code> ile ulaşırsınız. Kodu görmek/değiştirmek için
        kalem adının yanındaki <strong>⋯</strong> düğmesine basın.
    </p>
    <ul>
        <li><code>[kasa]</code> → o kalemin <strong>tutarı</strong></li>
        <li><code>[kasa.miktar]</code> · <code>[kasa.fiyat]</code> → miktarı / birim fiyatı</li>
        <li>Sistem: <?php foreach ($sys_vars as $c => $l): ?><code>[<?= h($c) ?>]</code> <?php endforeach; ?></li>
        <li>Fonksiyonlar: <code>yuvarla(x; n)</code>, <code>min</code>, <code>maks</code>, <code>mutlak</code>,
            <code>tavan</code>, <code>taban</code>, <code>topla</code>, <code>eger(kosul; a; b)</code></li>
        <li>Ondalık ayracı virgül de nokta da olur: <code>0,20</code> = <code>0.20</code>.
            Fonksiyon argümanlarını <strong>noktalı virgülle</strong> ayırın.</li>
    </ul>
    <?php if (can_maliyet('admin')): ?>
    <p style="margin:10px 0 0">Yeni başlık alanı / hesaplanan alan için →
        <a href="maliyet_alanlar.php">Alan &amp; Formül Tanımları</a></p>
    <?php endif; ?>
</details>

<script>
window.MLY_CONFIG = <?= json_encode([
    'fields'      => array_map(static fn($f) => [
        'code' => $f['code'], 'label' => $f['label'], 'type' => $f['field_type'],
        'formula' => $f['formula'], 'decimals' => (int)$f['decimals'],
    ], $field_defs),
    'calcTypes'   => $calc_types,
    'pctBases'    => $pct_bases,
    'sysVars'     => $sys_vars,
    'pkgPrices'   => array_map(static fn($p) => [
        'urun' => $p['urun'], 'firma' => $p['firma'], 'fiyat' => (float)$p['fiyat'], 'birim' => $p['birim'],
    ], $pkg_prices),
    // Parti No autocomplete'in (Adım 4) api_maliyet_link.php?action=detail
    // çağrısına EKLEMESİ gereken şablon id'si — açık sayfanın DOM'da hangi
    // kalemleri render ettiğiyle AYNI şablon olmalı, aksi halde API'nin
    // eşleştirdiği kodlar DOM'da bulunamaz (bkz. cost_link_apply() notu).
    'tplId'       => (int)$tpl_id,
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/maliyet.js?v=<?= @filemtime(__DIR__ . '/assets/maliyet.js') ?: time() ?>"></script>
<?php render_footer(); ?>
