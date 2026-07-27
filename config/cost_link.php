<?php
// =========================================================
// config/cost_link.php — Maliyet ↔ Yükleme Planı bağlantısı
//
// Tek yönlü ÖN-DOLDURMA katmanı. Senkronizasyon YOKTUR:
//   - Bu dosyadaki fonksiyonlar yalnız OKUR, hiçbir loading_* tablosuna yazmaz.
//   - cost_sheets'e yazma işi burada değil, maliyet_form.php'nin kendi
//     kaydetme akışındadır (yalnız İLK OLUŞTURMADA çağrılır).
//   - cost_compute() TEK hesap otoritesidir; bu dosya hiçbir tutar hesaplamaz,
//     yalnız "hangi kalemin miktarı ne olsun" haritalamasını yapar.
// =========================================================

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cost_calc.php';

// material_definitions içindeki saf lookup tipleri — malzeme/dara değildir,
// gruplama sorgusuna asla kalem olarak sızmamalı (bkz. CLAUDE.md: de265a4 emsali).
function cost_link_lookup_types(): array
{
    return ['firma', 'depo', 'bolge', 'urun', 'marka', 'cikis_nedeni'];
}

/**
 * Parti No / firma / plaka / ürün üzerinde canlı arama.
 * Yalnız type='yukleme', aktif kullanıcının depo kapsamına uygun kayıtlar.
 * Dönen: ['items' => [...], 'truncated' => bool]
 */
function cost_link_search(string $q, int $limit = 20): array
{
    $q = trim($q);
    if (mb_strlen($q, 'UTF-8') < 2) return ['items' => [], 'truncated' => false];

    // LIKE joker karakterlerini kaçır — aksi halde "%" yazan kullanıcı tüm tabloyu çeker
    $esc  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    $like = '%' . $esc . '%';

    // Pozisyonel (?) parametreler kullanılır — db.php PDO::ATTR_EMULATE_PREPARES=false
    // ile çalıştığı için aynı NAMED parametreyi (:q) birden çok yerde kullanmak
    // "SQLSTATE[HY093]" hatası verir (bkz. CLAUDE.md Yaygın Hatalar tablosu).
    $where  = "r.type = 'yukleme' AND (
        r.parti_no   LIKE ? OR
        r.firma      LIKE ? OR
        r.on_plaka   LIKE ? OR
        r.arka_plaka LIKE ? OR
        r.urun       LIKE ?
    )";
    $params = [$like, $like, $like, $like, $like];

    [$depo_sql, $depo_params] = depo_sql_records_in('r');
    if ($depo_sql !== '') {
        $where .= ' AND ' . $depo_sql;
        $params = array_merge($params, $depo_params);
    }

    $lim = max(1, min(100, $limit));

    try {
        $st = db()->prepare("SELECT r.id, r.parti_no, r.tarih, r.firma, r.on_plaka, r.arka_plaka, r.urun
                             FROM loading_records r
                             WHERE $where
                             ORDER BY r.tarih DESC, r.id DESC
                             LIMIT " . ($lim + 1));
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (PDOException $e) {
        error_log('[cost_link_search] ' . $e->getMessage());
        return ['items' => [], 'truncated' => false];
    }

    $truncated = count($rows) > $lim;
    if ($truncated) $rows = array_slice($rows, 0, $lim);

    $items = array_map(static function (array $r): array {
        $on   = trim((string)$r['on_plaka']);
        $arka = trim((string)$r['arka_plaka']);
        $plaka = $on . ($arka !== '' ? ' / ' . $arka : '');
        return [
            'id'       => (int)$r['id'],
            'parti_no' => (string)$r['parti_no'],
            'tarih'    => $r['tarih'],
            'firma'    => (string)$r['firma'],
            'plaka'    => $plaka,
            'urun'     => (string)$r['urun'],
        ];
    }, $rows);

    return ['items' => $items, 'truncated' => $truncated];
}

/**
 * Bir loading_record'un başlık bilgileri + palet toplamları.
 * type != 'yukleme' veya kayıt yoksa null döner.
 *
 * DİKKAT: dönen dizide 'sheet_no' ANAHTARI YOKTUR ve olmamalıdır — Sheet No
 * (cost_sheets.sheet_no, belge no) ile Parti No (loading_records.parti_no)
 * ayrı kavramlardır. Parti No burada yalnız gösterim amaçlı 'parti_no' ile
 * döner; cost_sheets'e YAZILMAZ, maliyet ekranlarında record_id üzerinden
 * JOIN ile gösterilir.
 */
function cost_link_record_summary(int $record_id): ?array
{
    if ($record_id <= 0) return null;

    try {
        $st = db()->prepare("SELECT r.*,
                COUNT(p.id)                   AS toplam_palet,
                COALESCE(SUM(p.kasa_adeti),0) AS toplam_kasa,
                COALESCE(SUM(p.brut_kg),0)    AS toplam_brut,
                COALESCE(SUM(p.net_kg),0)     AS toplam_net
            FROM loading_records r
            LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
            WHERE r.id = ? AND r.type = 'yukleme'
            GROUP BY r.id");
        $st->execute([$record_id]);
        $r = $st->fetch();
    } catch (PDOException $e) {
        error_log('[cost_link_record_summary] ' . $e->getMessage());
        return null;
    }
    if (!$r) return null;

    // Depo türetmesi: kaydın paletlerinde en sık geçen boş-olmayan depo.
    // Boşsa aktif depoya düşer — hiçbir palet depo bilgisi taşımıyor olabilir.
    $depo = '';
    try {
        $st2 = db()->prepare("SELECT depo, COUNT(*) c FROM loading_pallets
                              WHERE loading_record_id = ? AND TRIM(COALESCE(depo,'')) <> ''
                              GROUP BY depo ORDER BY c DESC LIMIT 1");
        $st2->execute([$record_id]);
        $depo_row = $st2->fetch();
        $depo = $depo_row ? (string)$depo_row['depo'] : '';
    } catch (PDOException $e) { /* atlanır, aşağıda active_depot()'a düşer */ }
    if ($depo === '') $depo = (string)(active_depot() ?? '');

    $on   = trim((string)($r['on_plaka'] ?? ''));
    $arka = trim((string)($r['arka_plaka'] ?? ''));
    $plaka = $on . ($arka !== '' ? ' / ' . $arka : '');

    return [
        'record_id'  => (int)$r['id'],
        'parti_no'   => (string)($r['parti_no'] ?? ''),   // yalnız gösterim — sheet_no'ya yazılmaz
        'product'    => (string)($r['urun'] ?? ''),
        'firma'      => (string)($r['firma'] ?? ''),
        'alici'      => (string)($r['alici'] ?? ''),
        'brand'      => (string)($r['brand'] ?? ''),
        'gumruk'     => (string)($r['gumruk'] ?? ''),
        'plaka'      => $plaka,
        'sheet_date' => $r['tarih'],
        'depo'       => $depo,
        'net_kg'     => (float)$r['toplam_net'],
        'brut_kg'    => (float)$r['toplam_brut'],
    ];
}

/**
 * Bir cost_templates id'sinden $sections/$items dizilerini, maliyet_form.php'nin
 * !$is_edit bloğundaki AYNI biçimde (form anahtarları 's1'/'n1' vb.) kurar.
 * cost_link_apply()'ın beklediği girdi budur. Şablon bulunamazsa boş diziler döner
 * (çağıran taraf hata üretmeli — bu fonksiyon sessizce boş sayfa varsaymaz).
 *
 * NOT: Bu, maliyet_form.php'deki şablon-yükleme mantığının BİREBİR aynısıdır —
 * iki yerde ayrı ayrı yazılmasın diye buraya taşınmıştır (api_maliyet_link.php
 * ve maliyet_form.php aynı fonksiyonu çağırır).
 */
function cost_link_load_template(int $template_id): array
{
    $sections = [];
    $code_key = [];
    if ($template_id > 0) {
        try {
            $st = db()->prepare("SELECT * FROM cost_template_sections WHERE template_id=? ORDER BY sort_order ASC, id ASC");
            $st->execute([$template_id]);
            foreach ($st->fetchAll() as $i => $ts) {
                $key = 's' . ($i + 1);
                $code_key[$ts['code']] = $key;
                $ts['id'] = $key;
                $sections[] = $ts;
            }
        } catch (PDOException $e) {
            error_log('[cost_link_load_template] ' . $e->getMessage());
        }
    }

    $items = [];
    if ($template_id > 0) {
        try {
            $st = db()->prepare("SELECT * FROM cost_template_items WHERE template_id=? ORDER BY sort_order ASC, id ASC");
            $st->execute([$template_id]);
            foreach ($st->fetchAll() as $i => $ti) {
                $ti['id']          = 'n' . ($i + 1);
                $ti['section_key'] = $code_key[$ti['section_code']] ?? ($sections[0]['id'] ?? 's1');
                $items[] = $ti;
            }
        } catch (PDOException $e) {
            error_log('[cost_link_load_template] ' . $e->getMessage());
        }
    }

    return ['sections' => $sections, 'items' => $items];
}

/**
 * Bir malzeme tanımı için eşleşme anahtarını üretir.
 *
 * GELECEK KANCASI: material_definitions.cost_item_code alanı eklendiğinde
 * (bugün YOK — YAGNI) birebir eşleştirme burada devreye girer. Çağıran hiçbir
 * yer (cost_link_apply, api_maliyet_link.php) değişmeden bu tek fonksiyon
 * güncellenir. Bugün isim normalizasyonu ile çalışır (cost_normalize_var).
 */
function cost_link_match_key(array $material_def): string
{
    if (!empty($material_def['cost_item_code'])) {
        return cost_normalize_var((string)$material_def['cost_item_code']);
    }
    return cost_normalize_var((string)($material_def['name'] ?? ''));
}

/**
 * Bir loading_record'a bağlı malzemeleri gruplayıp toplar: kasa cinsi,
 * palet tipi ve pallet_materials — üçü de material_definitions'a bağlı
 * "malzeme" satırlarıdır (lookup tipleri hariç tutulur).
 * Aynı malzeme birden çok palette geçiyorsa TEK satırda toplanır.
 */
function cost_link_materials(int $record_id): array
{
    if ($record_id <= 0) return [];

    $lookup_types = cost_link_lookup_types();
    $ph = implode(',', array_fill(0, count($lookup_types), '?'));

    $sql = "SELECT md.id AS material_id, md.name, md.type, SUM(x.qty) AS qty
            FROM (
                SELECT kasa_cinsi_id AS material_id, kasa_adeti AS qty
                FROM loading_pallets
                WHERE loading_record_id = ? AND kasa_cinsi_id IS NOT NULL AND kasa_adeti > 0

                UNION ALL

                SELECT palet_tipi_id AS material_id, 1 AS qty
                FROM loading_pallets
                WHERE loading_record_id = ? AND palet_tipi_id IS NOT NULL

                UNION ALL

                SELECT pm.material_id AS material_id, pm.quantity AS qty
                FROM pallet_materials pm
                JOIN loading_pallets lp ON lp.id = pm.loading_pallet_id
                WHERE lp.loading_record_id = ?
            ) x
            JOIN material_definitions md ON md.id = x.material_id
            WHERE md.type NOT IN ($ph)
            GROUP BY md.id, md.name, md.type
            HAVING SUM(x.qty) > 0
            ORDER BY md.name";

    try {
        $st = db()->prepare($sql);
        $st->execute(array_merge([$record_id, $record_id, $record_id], $lookup_types));
        $rows = $st->fetchAll();
    } catch (PDOException $e) {
        error_log('[cost_link_materials] ' . $e->getMessage());
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'material_id' => (int)$r['material_id'],
            'name'        => (string)$r['name'],
            'type'        => (string)$r['type'],
            'qty'         => (float)$r['qty'],
            'key'         => cost_link_match_key($r),
        ];
    }
    return $out;
}

/** cost_packaging_prices'ta ada göre en güncel aktif fiyatı arar. Bulamazsa 0. */
function cost_link_packaging_price(string $urun_adi): float
{
    $urun_adi = trim($urun_adi);
    if ($urun_adi === '') return 0.0;
    try {
        $st = db()->prepare("SELECT fiyat FROM cost_packaging_prices
                             WHERE is_active = 1 AND urun = ?
                             ORDER BY price_date DESC, id DESC LIMIT 1");
        $st->execute([$urun_adi]);
        $v = $st->fetchColumn();
        return $v !== false ? (float)$v : 0.0;
    } catch (PDOException $e) {
        return 0.0;
    }
}

/**
 * Şablondan kurulmuş $sections/$items dizilerine (maliyet_form.php'nin
 * !$is_edit bloğundaki biçimiyle) yükleme planı verisini uygular:
 *   - Eşleşen şablon kalemi → miktarı (qty) ezilir
 *     (yalnız calc_type='qty_price' VE qty_formula boş olan kalemlerde —
 *      formüllü/per_kg/fixed/percent kalemlerin miktar kavramı farklıdır,
 *      ezmek onları bozar)
 *   - Eşleşmeyen malzeme → yeni bir qty_price kalemi olarak eklenir
 *     (ilk include_in_total=1 bölüme; fiyatı varsa cost_packaging_prices'tan)
 *
 * Dönen: ['sections'=>..., 'items'=>..., 'matched'=>int, 'unmatched'=>int,
 *         'matched_codes'=>string[]] — matched_codes, gerçekten bir malzemeyle
 * eşleşmiş kalem kodlarıdır (çağıran taraf "hangi alan yükleme planından
 * geldi" ayrımını buradan yapar — calc_type='qty_price' olan HER kalemi değil).
 * Hiçbir DB yazımı yapmaz.
 */
function cost_link_apply(array $sections, array $items, array $materials): array
{
    // Bölüm yoksa (ör. "Boş sayfa" / tpl=-1) ERKEN ÇIKILMAZ — aksi halde
    // malzemeler sessizce kaybolur. Yerine maliyet_form.php/cost_compute()'daki
    // AYNI varsayılan-tek-bölüm deseni fabrikatörlenir; eşleşecek kalem
    // olmadığı için tüm malzemeler doğal olarak YENİ satır olarak eklenir.
    if (!$sections) {
        $sections = [[
            'id' => 's1', 'code' => 'genel', 'title' => 'Maliyet Kalemleri', 'basis_type' => 'sheet',
            'basis_value' => 0, 'basis_formula' => '', 'basis_label' => 'NET KG',
            'include_in_total' => 1, 'sort_order' => 1,
        ]];
    }

    // Miktarı ezilecek/eşleşmeye açık şablon kalemleri: ETİKET → items dizisindeki anahtar.
    // Eşleştirme 'code' (kasa, kosebent — formül içi kısa referans) üzerinden DEĞİL,
    // 'label' (PLASTİK KASA 30X40X18 — material_definitions.name ile aynı görünen ad)
    // üzerinden yapılır. cost_link_match_key() malzeme tarafında da 'name'i kullanıyor —
    // iki taraf da aynı normalize edilmiş görünen-ad uzayında buluşur.
    $label_to_key = [];
    foreach ($items as $ikey => $it) {
        if ((string)($it['calc_type'] ?? 'qty_price') !== 'qty_price') continue;
        if (trim((string)($it['qty_formula'] ?? '')) !== '') continue;
        $label_key = cost_normalize_var((string)($it['label'] ?? ''));
        if ($label_key !== '') $label_to_key[$label_key] = $ikey;
    }

    // Yeni kalemlerin düşeceği bölüm: ilk include_in_total=1, yoksa ilk bölüm
    $target_section = (string)$sections[0]['id'];
    foreach ($sections as $s) {
        if ((int)($s['include_in_total'] ?? 1) === 1) { $target_section = (string)$s['id']; break; }
    }

    $matched = 0;
    $unmatched = 0;
    $tmp = 0;
    $matched_codes = []; // hangi kalem KODLARI gerçekten bir malzemeyle eşleşti (çağıran taraf için)

    foreach ($materials as $m) {
        $key = (string)$m['key'];
        if ($key !== '' && isset($label_to_key[$key])) {
            $ikey = $label_to_key[$key];
            $items[$ikey]['qty'] = $m['qty'];
            $matched_codes[] = (string)($items[$ikey]['code'] ?? '');
            $matched++;
            continue;
        }

        $unmatched++;
        $tmp++;
        $items[] = [
            'id'           => 'ml' . $tmp,
            'section_key'  => $target_section,
            'code'         => cost_slug($m['name']),
            'label'        => $m['name'],
            'calc_type'    => 'qty_price',
            'qty'          => $m['qty'],
            'qty_formula'  => '',
            'unit'         => 'adet',
            'unit_price'   => cost_link_packaging_price($m['name']),
            'percent'      => 0,
            'percent_base' => 'ust_toplam',
            'formula'      => '',
            'is_income'    => 0,
            'note'         => '',
        ];
    }

    return [
        'sections'      => $sections,
        'items'         => $items,
        'matched'       => $matched,
        'unmatched'     => $unmatched,
        'matched_codes' => $matched_codes,
    ];
}

/**
 * Bir maliyet sayfası oluşturulduktan (linked_at) SONRA kaynak yükleme
 * kaydında değişiklik olup olmadığını tespit eder. Bu bir BULUŞSAL YÖNTEMDİR
 * — kesin garanti değildir (bkz. mimari notu: bir satırın silinmesi ve aynı
 * işlemde başka hiçbir satırın güncellenmemesi tespit edilemez).
 *
 * Hiçbir alanı değiştirmez, hiçbir şeyi kilitlemez — yalnız bilgi amaçlı
 * bir uyarı göstermek isteyen ekranlar için ['changed_at'=>...] ya da null döner.
 */
function cost_link_stale_info(int $record_id, ?string $linked_at): ?array
{
    if ($record_id <= 0 || !$linked_at) return null;

    // NOT: fallback değeri lr.updated_at DEĞİL lr.created_at'tır — updated_at
    // hiç düzenlenmemiş kayıtlarda NULL kalır (migration DEFAULT NULL ekledi) ve
    // MySQL GREATEST() tek bir NULL argümanla TÜM sonucu NULL yapar; bu da
    // palet/malzeme değişse bile uyarıyı sessizce hep devre dışı bırakırdı.
    // created_at her zaman NOT NULL (DEFAULT CURRENT_TIMESTAMP) — güvenli taban.
    try {
        $st = db()->prepare("SELECT GREATEST(
                COALESCE(lr.updated_at, lr.created_at),
                COALESCE((SELECT MAX(lp.updated_at) FROM loading_pallets lp
                          WHERE lp.loading_record_id = lr.id), lr.created_at),
                COALESCE((SELECT MAX(pm.created_at) FROM pallet_materials pm
                          JOIN loading_pallets lp2 ON lp2.id = pm.loading_pallet_id
                          WHERE lp2.loading_record_id = lr.id), lr.created_at)
            ) AS son_degisim
            FROM loading_records lr WHERE lr.id = ?");
        $st->execute([$record_id]);
        $row = $st->fetch();
    } catch (PDOException $e) {
        return null;
    }
    if (!$row || !$row['son_degisim']) return null;

    if (strtotime((string)$row['son_degisim']) > strtotime($linked_at)) {
        return ['changed_at' => $row['son_degisim']];
    }
    return null;
}
