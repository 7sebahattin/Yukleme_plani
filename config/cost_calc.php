<?php
// =========================================================
// config/cost_calc.php — Maliyet Hesabı modülü
//   * Şema (idempotent CREATE TABLE IF NOT EXISTS)
//   * Güvenli formül motoru (eval YOK — kendi tokenizer/RPN'i)
//   * Sayfa hesaplama (bölüm bazlı, bağımlılık sıralı)
// PHP tarafı hesabın TEK otoritesidir; assets/maliyet.js yalnızca
// aynı semantiği canlı önizleme için tekrarlar.
// =========================================================

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ---------------------------------------------------------
// 1) ŞEMA
// ---------------------------------------------------------

function cost_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    $sqls = [

        "CREATE TABLE IF NOT EXISTS `cost_sheets` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `sheet_no`        VARCHAR(60)  NOT NULL DEFAULT '',
            `sheet_date`      DATE         NULL,
            `title`           VARCHAR(200) NOT NULL DEFAULT '',
            `product`         VARCHAR(150) NOT NULL DEFAULT '',
            `brand`           VARCHAR(60)  NOT NULL DEFAULT '',
            `depo`            VARCHAR(100) NOT NULL DEFAULT '',
            `record_id`       INT          NULL,
            `brut_kg`         DECIMAL(16,3) NOT NULL DEFAULT 0,
            `linked_at`       DATETIME     NULL,
            `gumruk`          VARCHAR(150) NOT NULL DEFAULT '',
            `plaka`           VARCHAR(60)  NOT NULL DEFAULT '',
            `alici`           VARCHAR(200) NOT NULL DEFAULT '',
            `firma`           VARCHAR(200) NOT NULL DEFAULT '',
            `gidecegi_yer`    VARCHAR(200) NOT NULL DEFAULT '',
            `ambalaj_tipi`    VARCHAR(150) NOT NULL DEFAULT '',
            `net_kg`          DECIMAL(16,3) NOT NULL DEFAULT 0,
            `currency_code`   VARCHAR(5)   NOT NULL DEFAULT 'EUR',
            `currency_rate`   DECIMAL(16,4) NOT NULL DEFAULT 0,
            `freight`         DECIMAL(16,4) NOT NULL DEFAULT 0,
            `sale_qty`        DECIMAL(16,3) NOT NULL DEFAULT 0,
            `sale_unit_price` DECIMAL(16,4) NOT NULL DEFAULT 0,
            `extra_json`      LONGTEXT     NULL,
            `totals_json`     LONGTEXT     NULL,
            `notes`           TEXT         NULL,
            `template_id`     INT          NULL,
            `status`          VARCHAR(20)  NOT NULL DEFAULT 'taslak',
            `locked_at`       DATETIME     NULL,
            `locked_by`       INT          NULL,
            `revision_reason` TEXT         NULL,
            `created_by`      INT          NULL,
            `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by`      INT          NULL,
            `updated_at`      DATETIME     NULL,
            `deleted_at`      DATETIME     NULL,
            INDEX `idx_cs_date`    (`sheet_date`),
            INDEX `idx_cs_product` (`product`),
            INDEX `idx_cs_depo`    (`depo`),
            INDEX `idx_cs_status`  (`status`),
            INDEX `idx_cs_deleted` (`deleted_at`),
            INDEX `idx_cs_record`  (`record_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `cost_sheet_sections` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `sheet_id`         INT NOT NULL,
            `code`             VARCHAR(40)  NOT NULL DEFAULT '',
            `title`            VARCHAR(150) NOT NULL DEFAULT '',
            `basis_type`       VARCHAR(20)  NOT NULL DEFAULT 'sheet',
            `basis_value`      DECIMAL(16,3) NOT NULL DEFAULT 0,
            `basis_formula`    VARCHAR(500) NOT NULL DEFAULT '',
            `basis_label`      VARCHAR(80)  NOT NULL DEFAULT 'NET KG',
            `include_in_total` TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order`       INT          NOT NULL DEFAULT 0,
            INDEX `idx_css_sheet` (`sheet_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `cost_sheet_items` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `sheet_id`     INT NOT NULL,
            `section_id`   INT NULL,
            `sort_order`   INT NOT NULL DEFAULT 0,
            `code`         VARCHAR(40)  NOT NULL DEFAULT '',
            `label`        VARCHAR(200) NOT NULL DEFAULT '',
            `calc_type`    VARCHAR(20)  NOT NULL DEFAULT 'qty_price',
            `qty`          DECIMAL(18,4) NOT NULL DEFAULT 0,
            `qty_formula`  VARCHAR(500) NOT NULL DEFAULT '',
            `unit`         VARCHAR(24)  NOT NULL DEFAULT '',
            `unit_price`   DECIMAL(18,4) NOT NULL DEFAULT 0,
            `percent`      DECIMAL(12,4) NOT NULL DEFAULT 0,
            `percent_base` VARCHAR(500) NOT NULL DEFAULT 'ust_toplam',
            `formula`      VARCHAR(500) NOT NULL DEFAULT '',
            `is_income`    TINYINT(1)   NOT NULL DEFAULT 0,
            `amount`       DECIMAL(18,4) NOT NULL DEFAULT 0,
            `unit_cost`    DECIMAL(18,6) NOT NULL DEFAULT 0,
            `note`         VARCHAR(255) NOT NULL DEFAULT '',
            INDEX `idx_csi_sheet`   (`sheet_id`),
            INDEX `idx_csi_section` (`section_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Kullanıcının sonradan açtığı başlık alanları — formüllerde [kod] ile kullanılır
        "CREATE TABLE IF NOT EXISTS `cost_field_defs` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `code`          VARCHAR(40)  NOT NULL,
            `label`         VARCHAR(150) NOT NULL DEFAULT '',
            `field_type`    VARCHAR(20)  NOT NULL DEFAULT 'text',
            `options`       TEXT         NULL,
            `default_value` VARCHAR(255) NOT NULL DEFAULT '',
            `suffix`        VARCHAR(24)  NOT NULL DEFAULT '',
            `formula`       VARCHAR(500) NOT NULL DEFAULT '',
            `decimals`      TINYINT      NOT NULL DEFAULT 2,
            `hint`          VARCHAR(255) NOT NULL DEFAULT '',
            `sort_order`    INT          NOT NULL DEFAULT 0,
            `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
            `show_in_list`  TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_cfd_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `cost_templates` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(150) NOT NULL,
            `product`     VARCHAR(150) NOT NULL DEFAULT '',
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
            `is_default`  TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`  INT          NULL,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME     NULL,
            INDEX `idx_ct_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `cost_template_sections` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `template_id`      INT NOT NULL,
            `code`             VARCHAR(40)  NOT NULL DEFAULT '',
            `title`            VARCHAR(150) NOT NULL DEFAULT '',
            `basis_type`       VARCHAR(20)  NOT NULL DEFAULT 'sheet',
            `basis_value`      DECIMAL(16,3) NOT NULL DEFAULT 0,
            `basis_formula`    VARCHAR(500) NOT NULL DEFAULT '',
            `basis_label`      VARCHAR(80)  NOT NULL DEFAULT 'NET KG',
            `include_in_total` TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order`       INT          NOT NULL DEFAULT 0,
            INDEX `idx_cts_tpl` (`template_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `cost_template_items` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `template_id`  INT NOT NULL,
            `section_code` VARCHAR(40)  NOT NULL DEFAULT '',
            `sort_order`   INT NOT NULL DEFAULT 0,
            `code`         VARCHAR(40)  NOT NULL DEFAULT '',
            `label`        VARCHAR(200) NOT NULL DEFAULT '',
            `calc_type`    VARCHAR(20)  NOT NULL DEFAULT 'qty_price',
            `qty`          DECIMAL(18,4) NOT NULL DEFAULT 0,
            `qty_formula`  VARCHAR(500) NOT NULL DEFAULT '',
            `unit`         VARCHAR(24)  NOT NULL DEFAULT '',
            `unit_price`   DECIMAL(18,4) NOT NULL DEFAULT 0,
            `percent`      DECIMAL(12,4) NOT NULL DEFAULT 0,
            `percent_base` VARCHAR(500) NOT NULL DEFAULT 'ust_toplam',
            `formula`      VARCHAR(500) NOT NULL DEFAULT '',
            `is_income`    TINYINT(1)   NOT NULL DEFAULT 0,
            `note`         VARCHAR(255) NOT NULL DEFAULT '',
            INDEX `idx_cti_tpl` (`template_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // AMBALAJ FİYAT sayfasının karşılığı — kalem birim fiyatı buradan çekilir
        "CREATE TABLE IF NOT EXISTS `cost_packaging_prices` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `firma`      VARCHAR(150) NOT NULL DEFAULT '',
            `urun`       VARCHAR(200) NOT NULL DEFAULT '',
            `fiyat`      DECIMAL(16,4) NOT NULL DEFAULT 0,
            `birim`      VARCHAR(24)  NOT NULL DEFAULT 'adet',
            `price_date` DATE         NULL,
            `note`       VARCHAR(255) NOT NULL DEFAULT '',
            `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
            `created_by` INT          NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME     NULL,
            INDEX `idx_cpp_urun`  (`urun`(80)),
            INDEX `idx_cpp_firma` (`firma`(80)),
            INDEX `idx_cpp_date`  (`price_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); }
        catch (PDOException $e) { error_log('[MALIYET MIGRATION] ' . $e->getMessage()); }
    }

    cost_seed_default_template();
}

/**
 * İlk kurulumda Excel taslağının birebir karşılığı olan şablonu ekler.
 * Yalnızca hiç şablon yoksa çalışır — kullanıcının düzenlediği şablonu ezmez.
 */
function cost_seed_default_template(): void
{
    $pdo = db();
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM cost_templates")->fetchColumn() > 0) return;
    } catch (PDOException $e) { return; }

    try {
        $pdo->prepare("INSERT INTO cost_templates (name, product, description, is_active, is_default)
                       VALUES (?, ?, ?, 1, 1)")
            ->execute(['Kayısı — İhracat Maliyeti', 'KAYISI', 'Excel taslağından aktarılan başlangıç şablonu']);
        $tid = (int)$pdo->lastInsertId();

        $ins_sec = $pdo->prepare("INSERT INTO cost_template_sections
            (template_id, code, title, basis_type, basis_value, basis_formula, basis_label, include_in_total, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $ins_sec->execute([$tid, 'ihracat', 'İhracat Maliyeti', 'sheet', 0, '', 'NET KG', 1, 1]);
        // Depo bloğunun bazı kalem MİKTARLARINDAN türer (tutarlardan değil): alım kg − çıkma kg
        $ins_sec->execute([$tid, 'depo', 'Depo / Tonaj Ortalaması', 'formula', 0,
                           '[alim_kg.miktar]-[cikma_kg.miktar]', 'NET KG', 0, 2]);

        $ins = $pdo->prepare("INSERT INTO cost_template_items
            (template_id, section_code, sort_order, code, label, calc_type, qty, qty_formula,
             unit, unit_price, percent, percent_base, formula, is_income, note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        // [section, code, label, calc_type, qty, qty_formula, unit, price, percent, percent_base, formula, is_income]
        $rows = [
            ['ihracat', 'urun_fiyat', 'ÜRÜN FİYATI',            'per_kg',    0, '',            'kg',    25,      0, '', '', 0],
            ['ihracat', 'kasa',       'PLASTİK KASA 30X40X18',  'qty_price', 4576, '',         'adet',  27,      0, '', '', 0],
            ['ihracat', 'kosebent',   'KÖŞEBENT',               'qty_price', 104, '',          'adet',  46.92,   0, '', '', 0],
            ['ihracat', 'sapka',      'ŞAPKA',                  'qty_price', 26, '',           'adet',  62.40,   0, '', '', 0],
            ['ihracat', 'palet',      'İHRACAT PALETİ 100X120', 'qty_price', 26, '',           'adet',  420,     0, '', '', 0],
            ['ihracat', 'serit',      'ŞERİT (KG BAZINDA)',     'qty_price', 20, '',           'kg',    102,     0, '', '', 0],
            ['ihracat', 'frigga',     'A-90 FRIGGA',            'qty_price', 1, '',            'adet',  1915.59, 0, '', '', 0],
            ['ihracat', 'taban_kagit','TABAN KÂĞIDI',           'qty_price', 0, '[kasa.miktar]','adet', 0,       0, '', '', 0],
            ['ihracat', 'kenar_karton','KENAR KARTONU',         'qty_price', 0, '[kasa.miktar]*2','adet',0,      0, '', '', 0],
            ['ihracat', 'kasa_etiket','KASA ETİKETİ',           'qty_price', 0, '[kasa.miktar]','adet', 0.216,   0, '', '', 0],
            ['ihracat', 'analiz',     'ANALİZ',                 'fixed',     1, '',            '',      20000,   0, '', '', 0],
            ['ihracat', 'gumruk_gid', 'GÜMRÜK',                 'fixed',     1, '',            '',      25000,   0, '', '', 0],
            ['ihracat', 'iscilik',    'PRİM + İŞÇİLİK + SOĞUTMA','per_kg',   0, '',            'kg',    9,       0, '', '', 0],
            ['ihracat', 'komisyon',   'KOMİSYON',               'per_kg',    0, '',            'kg',    5,       0, '', '', 0],
            ['ihracat', 'stopaj',     'STOPAJ %3',              'percent',   0, '',            '',      0,       3, 'ust_toplam', '', 0],

            ['depo',    'alim_kg',    'ALIM (TONAJ)',           'qty_price', 28125, '',        'kg',    50,      0, '', '', 0],
            ['depo',    'cikma_kg',   'ÇIKMA %20',              'qty_price', 0, '[alim_kg.miktar]*0,20', 'kg', 35, 0, '', '', 1],
            ['depo',    'soguk_hava', 'SOĞUK HAVA BEDELİ',      'fixed',     1, '',            '',      113332,  0, '', '', 0],
            ['depo',    'map_torba',  'BUZHANE MAP TORBA',      'fixed',     1, '',            '',      10199,   0, '', '', 0],
            ['depo',    'bahce_isc',  'BAHÇE İŞÇİLİĞİ',         'fixed',     1, '',            '',      127498,  0, '', '', 0],
            ['depo',    'depo_isc',   'DEPO İŞÇİLİĞİ',          'fixed',     1, '',            '',      90665,   0, '', '', 0],
            ['depo',    'nakliye',    'NAKLİYE',                'fixed',     1, '',            '',      39666,   0, '', '', 0],
            ['depo',    'stopaj_tes', 'STOPAJ + TESCİL',        'fixed',     1, '',            '',      22640,   0, '', '', 0],
        ];
        foreach ($rows as $i => $r) {
            $ins->execute([$tid, $r[0], ($i + 1) * 10, $r[1], $r[2], $r[3], $r[4], $r[5],
                           $r[6], $r[7], $r[8], $r[9] ?: 'ust_toplam', $r[10], $r[11], '']);
        }
    } catch (PDOException $e) {
        error_log('[MALIYET SEED] ' . $e->getMessage());
    }
}

// ---------------------------------------------------------
// 2) SABİTLER / KAYITLAR
// ---------------------------------------------------------

/** Satır hesaplama tipleri — yeni tip eklemek için burası + cost_item_amount() + maliyet.js */
function cost_calc_types(): array
{
    return [
        'qty_price' => ['label' => 'Miktar × Birim Fiyat', 'hint' => 'Klasik kalem: adet/kg × fiyat'],
        'per_kg'    => ['label' => 'Net KG × Birim Fiyat', 'hint' => 'Miktar otomatik = bölüm bazı (net kg)'],
        'fixed'     => ['label' => 'Sabit Tutar',          'hint' => 'Doğrudan tutar girilir (analiz, gümrük…)'],
        'percent'   => ['label' => 'Yüzde (%)',            'hint' => 'Seçilen bazın yüzdesi — stopaj, komisyon'],
        'formula'   => ['label' => 'Formül',               'hint' => 'Serbest formül: [kod]*2+[net_kg]/1000'],
        'subtotal'  => ['label' => 'Ara Toplam (bilgi)',   'hint' => 'Üstündeki satırların toplamını gösterir, tekrar eklenmez'],
        'info'      => ['label' => 'Bilgi Satırı',         'hint' => 'Hesaba katılmaz — başlık/not satırı'],
    ];
}

/** Yüzde hazır bazları */
function cost_percent_bases(): array
{
    return [
        'ust_toplam'    => 'Üstündeki satırların toplamı',
        'bolum_toplam'  => 'Bölüm toplamı',
        'genel_toplam'  => 'Genel toplam',
        'baz'           => 'Bölüm bazı (net kg)',
    ];
}

/** Kullanıcı tanımlı alan tipleri */
function cost_field_types(): array
{
    return [
        'text'     => 'Metin',
        'number'   => 'Sayı',
        'date'     => 'Tarih',
        'select'   => 'Liste (seçenekli)',
        'checkbox' => 'Evet / Hayır',
        'textarea' => 'Uzun metin',
        'formula'  => 'Formül (hesaplanan)',
    ];
}

/** Aktif kullanıcı-tanımlı alanlar */
function cost_fields(bool $only_active = true): array
{
    static $cache = null;
    if ($cache !== null && $only_active) return $cache;
    try {
        $sql = "SELECT * FROM cost_field_defs " . ($only_active ? "WHERE is_active=1 " : "")
             . "ORDER BY sort_order ASC, id ASC";
        $rows = db()->query($sql)->fetchAll();
    } catch (PDOException $e) { $rows = []; }
    if ($only_active) $cache = $rows;
    return $rows;
}

/** Sistem değişkenleri — formül yardımında listelenir */
function cost_system_vars(): array
{
    return [
        'net_kg'       => 'Sayfa net KG',
        'baz'          => 'Bölüm bazı (TL/KG böleni)',
        'kur'          => 'Döviz kuru',
        'navlun'       => 'Navlun (döviz)',
        'satis_kg'     => 'Fatura KG',
        'satis_fiyat'  => 'Fatura birim fiyatı (döviz)',
        'ust_toplam'   => 'Bu satırın üstündeki satırların toplamı',
        'bolum_toplam' => 'Bulunduğu bölümün toplamı',
        'genel_toplam' => 'Genel toplam',
    ];
}

// ---------------------------------------------------------
// 3) FORMÜL MOTORU (eval yok)
// ---------------------------------------------------------

class CostFormulaError extends RuntimeException {}

/** Formülü token'lara böler. */
function cost_tokenize(string $expr): array
{
    $tokens = [];
    $len    = strlen($expr);
    $i      = 0;
    $prev   = null; // önceki anlamlı token tipi — tekli eksi tespiti için

    while ($i < $len) {
        $c = $expr[$i];

        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") { $i++; continue; }

        // Değişken: [kod] veya [kod.miktar]
        if ($c === '[') {
            $end = strpos($expr, ']', $i);
            if ($end === false) throw new CostFormulaError('Kapanmayan köşeli parantez');
            $name = trim(substr($expr, $i + 1, $end - $i - 1));
            if ($name === '') throw new CostFormulaError('Boş değişken adı: []');
            $tokens[] = ['t' => 'var', 'v' => cost_normalize_var($name)];
            $prev = 'val';
            $i = $end + 1;
            continue;
        }

        // Sayı — hem "1234.56" hem "1234,56" kabul edilir
        if (ctype_digit($c) || (($c === '.' || $c === ',') && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
            $j = $i; $seen_sep = false;
            while ($j < $len) {
                $d = $expr[$j];
                if (ctype_digit($d)) { $j++; continue; }
                if (($d === '.' || $d === ',') && !$seen_sep && $j + 1 < $len && ctype_digit($expr[$j + 1])) {
                    $seen_sep = true; $j++; continue;
                }
                break;
            }
            $raw = str_replace(',', '.', substr($expr, $i, $j - $i));
            $tokens[] = ['t' => 'num', 'v' => (float)$raw];
            $prev = 'val';
            $i = $j;
            continue;
        }

        // Fonksiyon adı / çıplak değişken
        if (ctype_alpha($c) || $c === '_') {
            $j = $i;
            while ($j < $len && (ctype_alnum($expr[$j]) || $expr[$j] === '_' || ord($expr[$j]) > 127)) $j++;
            $word = strtolower(substr($expr, $i, $j - $i));
            // sonrası '(' ise fonksiyon, değilse değişken (köşeli parantezsiz yazım toleransı)
            $k = $j; while ($k < $len && $expr[$k] === ' ') $k++;
            if ($k < $len && $expr[$k] === '(') {
                $tokens[] = ['t' => 'func', 'v' => $word];
                $prev = 'func';
            } else {
                $tokens[] = ['t' => 'var', 'v' => cost_normalize_var($word)];
                $prev = 'val';
            }
            $i = $j;
            continue;
        }

        // Çok karakterli operatörler
        $two = substr($expr, $i, 2);
        if (in_array($two, ['>=', '<=', '==', '!=', '<>'], true)) {
            $tokens[] = ['t' => 'op', 'v' => ($two === '<>' ? '!=' : $two)];
            $prev = 'op'; $i += 2; continue;
        }

        if (strpos('+-*/%^', $c) !== false) {
            if ($c === '-' && ($prev === null || $prev === 'op' || $prev === 'lp' || $prev === 'sep' || $prev === 'func')) {
                $tokens[] = ['t' => 'op', 'v' => 'u-'];
            } elseif ($c === '+' && ($prev === null || $prev === 'op' || $prev === 'lp' || $prev === 'sep' || $prev === 'func')) {
                // tekli artı — yok say
            } else {
                $tokens[] = ['t' => 'op', 'v' => $c];
            }
            $prev = 'op'; $i++; continue;
        }
        if ($c === '>' || $c === '<' || $c === '=') {
            $tokens[] = ['t' => 'op', 'v' => ($c === '=' ? '==' : $c)];
            $prev = 'op'; $i++; continue;
        }
        if ($c === '(') { $tokens[] = ['t' => 'lp']; $prev = 'lp'; $i++; continue; }
        if ($c === ')') { $tokens[] = ['t' => 'rp']; $prev = 'val'; $i++; continue; }
        if ($c === ';' || $c === ',') {
            // virgül burada ayraç: sayı içindeki ondalık virgül yukarıda tüketildi
            $tokens[] = ['t' => 'sep']; $prev = 'sep'; $i++; continue;
        }

        throw new CostFormulaError('Tanınmayan karakter: ' . $c);
    }

    return $tokens;
}

/** Değişken adını normalize eder: küçük harf, TR karakter sadeleştirme, boşluk→_ */
function cost_normalize_var(string $name): string
{
    $name = trim($name);
    $tr   = ['İ'=>'i','I'=>'i','Ş'=>'s','ş'=>'s','Ğ'=>'g','ğ'=>'g','Ü'=>'u','ü'=>'u',
             'Ö'=>'o','ö'=>'o','Ç'=>'c','ç'=>'c','ı'=>'i'];
    $name = strtr($name, $tr);
    $name = mb_strtolower($name, 'UTF-8');
    $name = preg_replace('/[^a-z0-9_.]+/u', '_', $name) ?? $name;
    return trim($name, '_');
}

/** Operatör öncelikleri */
function cost_op_info(string $op): array
{
    switch ($op) {
        case 'u-': return ['p' => 5, 'right' => true,  'args' => 1];
        case '^':  return ['p' => 4, 'right' => true,  'args' => 2];
        case '*': case '/': case '%': return ['p' => 3, 'right' => false, 'args' => 2];
        case '+': case '-': return ['p' => 2, 'right' => false, 'args' => 2];
        default:   return ['p' => 1, 'right' => false, 'args' => 2]; // karşılaştırma
    }
}

/** Shunting-yard: token dizisi → RPN */
function cost_to_rpn(array $tokens): array
{
    $out = []; $stack = []; $argc = [];

    foreach ($tokens as $tk) {
        switch ($tk['t']) {
            case 'num':
            case 'var':
                $out[] = $tk;
                break;
            case 'func':
                $stack[] = $tk;
                $argc[]  = 1;
                break;
            case 'sep':
                while ($stack && end($stack)['t'] !== 'lp') $out[] = array_pop($stack);
                if (!$stack) throw new CostFormulaError('Parantez hatası (ayraç)');
                if ($argc) $argc[count($argc) - 1]++;
                break;
            case 'op':
                $o1 = cost_op_info($tk['v']);
                while ($stack) {
                    $top = end($stack);
                    if ($top['t'] !== 'op') break;
                    $o2 = cost_op_info($top['v']);
                    if (($o1['right'] && $o1['p'] < $o2['p']) || (!$o1['right'] && $o1['p'] <= $o2['p'])) {
                        $out[] = array_pop($stack);
                    } else break;
                }
                $stack[] = $tk;
                break;
            case 'lp':
                $stack[] = $tk;
                break;
            case 'rp':
                while ($stack && end($stack)['t'] !== 'lp') $out[] = array_pop($stack);
                if (!$stack) throw new CostFormulaError('Fazla kapanış parantezi');
                array_pop($stack); // lp
                if ($stack && end($stack)['t'] === 'func') {
                    $f = array_pop($stack);
                    $f['argc'] = (int)array_pop($argc);
                    $out[] = $f;
                }
                break;
        }
    }
    while ($stack) {
        $top = array_pop($stack);
        if ($top['t'] === 'lp') throw new CostFormulaError('Kapanmayan parantez');
        $out[] = $top;
    }
    return $out;
}

/** RPN'i değerlendirir. $vars: ['kod' => float] */
function cost_eval_rpn(array $rpn, array $vars): float
{
    $st = [];
    foreach ($rpn as $tk) {
        if ($tk['t'] === 'num') { $st[] = (float)$tk['v']; continue; }
        if ($tk['t'] === 'var') { $st[] = (float)($vars[$tk['v']] ?? 0.0); continue; }

        if ($tk['t'] === 'op') {
            $o = cost_op_info($tk['v']);
            if ($o['args'] === 1) {
                if (!$st) throw new CostFormulaError('Eksik işlenen');
                $a = array_pop($st);
                $st[] = -$a;
                continue;
            }
            if (count($st) < 2) throw new CostFormulaError('Eksik işlenen: ' . $tk['v']);
            $b = array_pop($st); $a = array_pop($st);
            switch ($tk['v']) {
                case '+': $st[] = $a + $b; break;
                case '-': $st[] = $a - $b; break;
                case '*': $st[] = $a * $b; break;
                case '/': $st[] = (abs($b) < 1e-12) ? 0.0 : $a / $b; break;
                case '%': $st[] = (abs($b) < 1e-12) ? 0.0 : fmod($a, $b); break;
                case '^': $st[] = (float)($a ** $b); break;
                case '>':  $st[] = $a >  $b ? 1.0 : 0.0; break;
                case '<':  $st[] = $a <  $b ? 1.0 : 0.0; break;
                case '>=': $st[] = $a >= $b ? 1.0 : 0.0; break;
                case '<=': $st[] = $a <= $b ? 1.0 : 0.0; break;
                case '==': $st[] = abs($a - $b) < 1e-9 ? 1.0 : 0.0; break;
                case '!=': $st[] = abs($a - $b) < 1e-9 ? 0.0 : 1.0; break;
                default: throw new CostFormulaError('Bilinmeyen operatör: ' . $tk['v']);
            }
            continue;
        }

        if ($tk['t'] === 'func') {
            $n    = (int)($tk['argc'] ?? 1);
            $args = [];
            for ($k = 0; $k < $n; $k++) {
                if (!$st) throw new CostFormulaError('Fonksiyon argümanı eksik: ' . $tk['v']);
                array_unshift($args, array_pop($st));
            }
            $st[] = cost_call_func($tk['v'], $args);
            continue;
        }
    }
    if (count($st) !== 1) throw new CostFormulaError('Geçersiz ifade');
    $r = (float)$st[0];
    return (is_nan($r) || is_infinite($r)) ? 0.0 : $r;
}

function cost_call_func(string $name, array $a): float
{
    switch ($name) {
        case 'yuvarla': case 'round':
            return round($a[0] ?? 0, (int)($a[1] ?? 0));
        case 'tavan': case 'ceil':   return ceil($a[0] ?? 0);
        case 'taban': case 'floor':  return floor($a[0] ?? 0);
        case 'mutlak': case 'abs':   return abs($a[0] ?? 0);
        case 'min':    return $a ? min($a) : 0.0;
        case 'maks': case 'max': return $a ? max($a) : 0.0;
        case 'topla': case 'sum':    return array_sum($a);
        case 'ort': case 'avg':      return $a ? array_sum($a) / count($a) : 0.0;
        case 'eger': case 'if':
            return (($a[0] ?? 0) != 0.0) ? (float)($a[1] ?? 0) : (float)($a[2] ?? 0);
        default:
            throw new CostFormulaError('Bilinmeyen fonksiyon: ' . $name . '()');
    }
}

/** Formülü hesaplar. Hata → 0 döner ve $err doldurulur. */
function cost_formula_eval(string $expr, array $vars, ?string &$err = null): float
{
    $err  = null;
    $expr = trim($expr);
    if ($expr === '') return 0.0;
    try {
        static $cache = [];
        if (!isset($cache[$expr])) $cache[$expr] = cost_to_rpn(cost_tokenize($expr));
        return cost_eval_rpn($cache[$expr], $vars);
    } catch (CostFormulaError $e) {
        $err = $e->getMessage();
        return 0.0;
    } catch (Throwable $e) {
        $err = 'Formül hatası';
        return 0.0;
    }
}

/** Formülün referans verdiği değişken köklerini döner (['kasa','net_kg'] gibi) */
function cost_formula_deps(string $expr): array
{
    $expr = trim($expr);
    if ($expr === '') return [];
    try { $tokens = cost_tokenize($expr); }
    catch (Throwable $e) { return []; }
    $deps = [];
    foreach ($tokens as $tk) {
        if ($tk['t'] !== 'var') continue;
        $root = explode('.', $tk['v'])[0];
        if ($root !== '') $deps[$root] = true;
    }
    return array_keys($deps);
}

/** Formülün geçerliliğini test eder — kaydetmeden önce uyarı vermek için */
function cost_formula_check(string $expr): ?string
{
    $expr = trim($expr);
    if ($expr === '') return null;
    try { cost_to_rpn(cost_tokenize($expr)); return null; }
    catch (Throwable $e) { return $e->getMessage(); }
}

// ---------------------------------------------------------
// 4) SAYFA HESAPLAMA
// ---------------------------------------------------------

/**
 * Bir maliyet sayfasını hesaplar.
 *
 * @param array $sheet    cost_sheets satırı (net_kg, currency_rate, freight, sale_*, extra_json)
 * @param array $sections cost_sheet_sections satırları (sort_order sıralı)
 * @param array $items    cost_sheet_items satırları (sort_order sıralı)
 * @return array ['items'=>[id=>[amount,unit_cost,qty,error]], 'sections'=>[...], 'totals'=>[...], 'vars'=>[...], 'errors'=>[]]
 */
function cost_compute(array $sheet, array $sections, array $items): array
{
    $errors = [];

    $net_kg = (float)($sheet['net_kg'] ?? 0);
    $rate   = (float)($sheet['currency_rate'] ?? 0);

    // --- Sistem + kullanıcı alan değişkenleri ---
    $vars = [
        'net_kg'      => $net_kg,
        'kur'         => $rate,
        'navlun'      => (float)($sheet['freight'] ?? 0),
        'satis_kg'    => (float)($sheet['sale_qty'] ?? 0),
        'satis_fiyat' => (float)($sheet['sale_unit_price'] ?? 0),
        'baz'         => $net_kg,
        'ust_toplam'  => 0.0,
        'bolum_toplam'=> 0.0,
        'genel_toplam'=> 0.0,
    ];

    $extra = [];
    if (!empty($sheet['extra_json'])) {
        $decoded = json_decode((string)$sheet['extra_json'], true);
        if (is_array($decoded)) $extra = $decoded;
    }
    $field_defs = cost_fields();
    foreach ($field_defs as $fd) {
        $code = (string)$fd['code'];
        if ($fd['field_type'] === 'formula') continue; // aşağıda, kalemlerden sonra
        if ($fd['field_type'] === 'number') {
            $vars[$code] = num($extra[$code] ?? $fd['default_value']);
        } elseif ($fd['field_type'] === 'checkbox') {
            $vars[$code] = !empty($extra[$code]) ? 1.0 : 0.0;
        } else {
            $vars[$code] = num($extra[$code] ?? '');
        }
    }

    // --- Bölümleri hazırla ---
    if (!$sections) {
        $sections = [['id' => 0, 'code' => 'genel', 'title' => 'Maliyet Kalemleri',
                      'basis_type' => 'sheet', 'basis_value' => 0, 'basis_formula' => '',
                      'basis_label' => 'NET KG', 'include_in_total' => 1, 'sort_order' => 1]];
    }

    $by_section = [];
    foreach ($items as $it) {
        $sid = (int)($it['section_id'] ?? 0);
        $by_section[$sid][] = $it;
    }
    // Bölümü olmayan/silinmiş bölüme bağlı kalemler ilk bölüme düşer
    $valid_sids = array_map(static fn($s) => (int)$s['id'], $sections);
    $first_sid  = (int)$sections[0]['id'];
    foreach ($by_section as $sid => $rows) {
        if (!in_array($sid, $valid_sids, true)) {
            $by_section[$first_sid] = array_merge($by_section[$first_sid] ?? [], $rows);
            unset($by_section[$sid]);
        }
    }

    $res_items    = [];
    $res_sections = [];
    $grand_total  = 0.0;

    foreach ($sections as $sec) {
        $sid  = (int)$sec['id'];
        $rows = $by_section[$sid] ?? [];
        usort($rows, static fn($a, $b) => ((int)$a['sort_order'] <=> (int)$b['sort_order']) ?: ((int)$a['id'] <=> (int)$b['id']));

        // Bölüm bazı — formül tipi kalemlere referans verebilir, bu yüzden
        // önce kalemleri "baz=net_kg" ile bir kez, sonra gerçek bazla tekrar hesaplarız.
        $basis = cost_section_basis($sec, $vars, $rows, $errors);
        $vars['baz'] = $basis;

        [$computed, $sec_total] = cost_compute_section_items($rows, $vars, $basis, $errors);

        // Baz formülü kalemlere dayanıyorsa ikinci geçiş (ör. [alim_kg]-[cikma_kg])
        if (($sec['basis_type'] ?? 'sheet') === 'formula') {
            $vars2 = $vars;
            foreach ($computed as $c) {
                if ($c['code'] === '') continue;
                $vars2[$c['code']]                = $c['amount'];
                $vars2[$c['code'] . '.tutar']     = $c['amount'];
                $vars2[$c['code'] . '.miktar']    = $c['qty'];
                $vars2[$c['code'] . '.fiyat']     = $c['unit_price'];
            }
            $basis2 = cost_section_basis($sec, $vars2, $rows, $errors);
            if (abs($basis2 - $basis) > 1e-9) {
                $basis = $basis2;
                $vars['baz'] = $basis;
                [$computed, $sec_total] = cost_compute_section_items($rows, $vars, $basis, $errors);
            }
        }

        foreach ($computed as $c) {
            $c['unit_cost'] = $basis > 0 ? $c['amount'] / $basis : 0.0;
            $res_items[(int)$c['id']] = $c;
            // Kalem sonuçlarını sonraki bölümlerin de görebilmesi için değişkenlere yaz
            if ($c['code'] !== '') {
                $vars[$c['code']]             = $c['amount'];
                $vars[$c['code'] . '.tutar']  = $c['amount'];
                $vars[$c['code'] . '.miktar'] = $c['qty'];
                $vars[$c['code'] . '.fiyat']  = $c['unit_price'];
                $vars[$c['code'] . '.birim_maliyet'] = $c['unit_cost'];
            }
        }

        $include = (int)($sec['include_in_total'] ?? 1) === 1;
        if ($include) $grand_total += $sec_total;

        $res_sections[$sid] = [
            'id'         => $sid,
            'code'       => (string)($sec['code'] ?? ''),
            'title'      => (string)($sec['title'] ?? ''),
            'basis'      => $basis,
            'basis_label'=> (string)($sec['basis_label'] ?? 'NET KG'),
            'total'      => $sec_total,
            'unit_cost'  => $basis > 0 ? $sec_total / $basis : 0.0,
            'include'    => $include,
            'item_count' => count($rows),
        ];
        $vars['bolum:' . ($sec['code'] ?: $sid)] = $sec_total;
    }

    $vars['genel_toplam'] = $grand_total;
    $vars['baz']          = $net_kg;

    // --- Formül tipli kullanıcı alanları (kalemler hesaplandıktan sonra) ---
    $field_values = [];
    foreach ($field_defs as $fd) {
        if ($fd['field_type'] !== 'formula') continue;
        $err = null;
        $val = cost_formula_eval((string)$fd['formula'], $vars, $err);
        if ($err) $errors[] = 'Alan “' . $fd['label'] . '”: ' . $err;
        $vars[(string)$fd['code']] = $val;
        $field_values[(string)$fd['code']] = $val;
    }

    // --- Özet / döviz / kâr ---
    $unit_cost_tl = $net_kg > 0 ? $grand_total / $net_kg : 0.0;
    $total_fx     = $rate > 0 ? $grand_total / $rate : 0.0;
    $freight      = (float)($sheet['freight'] ?? 0);
    $total_cost_fx= $total_fx + $freight;
    $invoice_fx   = (float)($sheet['sale_qty'] ?? 0) * (float)($sheet['sale_unit_price'] ?? 0);
    $profit_fx    = $invoice_fx - $total_cost_fx;

    $totals = [
        'grand_total'   => $grand_total,                                   // TL
        'unit_cost_tl'  => $unit_cost_tl,                                  // TL/KG
        'currency'      => (string)($sheet['currency_code'] ?? 'EUR'),
        'rate'          => $rate,
        'total_fx'      => $total_fx,                                      // TIR üstü maliyet
        'freight'       => $freight,
        'total_cost_fx' => $total_cost_fx,
        'invoice_fx'    => $invoice_fx,
        'profit_fx'     => $profit_fx,
        'profit_tl'     => $profit_fx * $rate,
        'unit_cost_fx'  => ($net_kg > 0 && $rate > 0) ? $total_cost_fx / $net_kg : 0.0,
        'net_kg'        => $net_kg,
        'field_values'  => $field_values,
    ];

    return [
        'items'    => $res_items,
        'sections' => $res_sections,
        'totals'   => $totals,
        'vars'     => $vars,
        'errors'   => $errors,
    ];
}

/** Bölüm bazını (TL/KG böleni) hesaplar */
function cost_section_basis(array $sec, array $vars, array $rows, array &$errors): float
{
    switch ((string)($sec['basis_type'] ?? 'sheet')) {
        case 'fixed':
            return (float)($sec['basis_value'] ?? 0);
        case 'formula':
            $err = null;
            $v = cost_formula_eval((string)($sec['basis_formula'] ?? ''), $vars, $err);
            if ($err) $errors[] = 'Bölüm “' . ($sec['title'] ?? '') . '” bazı: ' . $err;
            return $v;
        case 'none':
            return 0.0;
        case 'sheet':
        default:
            return (float)($vars['net_kg'] ?? 0);
    }
}

/**
 * Bir bölümün kalemlerini bağımlılık sırasına göre hesaplar.
 * @return array [computed_rows, section_total]
 */
function cost_compute_section_items(array $rows, array $base_vars, float $basis, array &$errors): array
{
    $vars = $base_vars;
    $vars['baz'] = $basis;

    // id → satır, code → id haritası
    $by_id   = [];
    $code_id = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $by_id[$id] = $r;
        $c = cost_normalize_var((string)($r['code'] ?? ''));
        if ($c !== '') $code_id[$c] = $id;
    }

    // Bağımlılık grafiği
    $deps = [];
    foreach ($rows as $r) {
        $id  = (int)$r['id'];
        $set = [];
        $exprs = [];
        $ct = (string)$r['calc_type'];
        if (($r['qty_formula'] ?? '') !== '') $exprs[] = (string)$r['qty_formula'];
        if ($ct === 'formula')  $exprs[] = (string)$r['formula'];
        if ($ct === 'percent')  $exprs[] = (string)$r['percent_base'];
        foreach ($exprs as $e) {
            foreach (cost_formula_deps($e) as $d) {
                if (isset($code_id[$d]) && $code_id[$d] !== $id) $set[$code_id[$d]] = true;
                if ($d === 'ust_toplam' || $d === 'bolum_toplam') {
                    // Bu satırdan önceki tüm satırlara bağımlı
                    foreach ($rows as $r2) {
                        $id2 = (int)$r2['id'];
                        if ($id2 === $id) continue;
                        if ($d === 'bolum_toplam' || (int)$r2['sort_order'] < (int)$r['sort_order']) $set[$id2] = true;
                    }
                }
            }
        }
        if ($ct === 'subtotal') {
            foreach ($rows as $r2) {
                if ((int)$r2['sort_order'] < (int)$r['sort_order']) $set[(int)$r2['id']] = true;
            }
        }
        $deps[$id] = array_keys($set);
    }

    // Topolojik sıralama (Kahn) — döngü kalanlar sıra sonunda 0 ile hesaplanır
    $order = [];
    $state = []; // 0=ziyaret edilmedi 1=işlemde 2=bitti
    $cycle = [];
    $visit = function (int $id) use (&$visit, &$state, &$order, $deps, &$cycle) {
        if (($state[$id] ?? 0) === 2) return;
        if (($state[$id] ?? 0) === 1) { $cycle[$id] = true; return; }
        $state[$id] = 1;
        foreach ($deps[$id] ?? [] as $d) $visit($d);
        $state[$id] = 2;
        $order[] = $id;
    };
    foreach ($rows as $r) $visit((int)$r['id']);

    if ($cycle) {
        $names = [];
        foreach (array_keys($cycle) as $cid) $names[] = (string)($by_id[$cid]['label'] ?? $cid);
        $errors[] = 'Döngüsel formül: ' . implode(', ', $names) . ' — bu satırlar 0 kabul edildi.';
    }

    // Hesap
    $computed_by_id = [];
    $running        = 0.0; // ust_toplam — SIRA bazlı, aşağıda tekrar hesaplanır

    foreach ($order as $id) {
        if (!isset($by_id[$id])) continue;
        $r  = $by_id[$id];
        $ct = (string)$r['calc_type'];

        // ust_toplam: bu satırdan önceki (sort_order küçük) hesaplanmış satırların net toplamı
        $prev_sum = 0.0;
        foreach ($computed_by_id as $cid => $c) {
            if ((int)($by_id[$cid]['sort_order'] ?? 0) < (int)$r['sort_order']) {
                $prev_sum += $c['signed'];
            }
        }
        $vars['ust_toplam']   = $prev_sum;
        $vars['bolum_toplam'] = array_sum(array_map(static fn($c) => $c['signed'], $computed_by_id));

        $row_err = null;

        // 1) Miktar
        $qty = (float)($r['qty'] ?? 0);
        if (trim((string)($r['qty_formula'] ?? '')) !== '') {
            $e = null;
            $qty = cost_formula_eval((string)$r['qty_formula'], $vars, $e);
            if ($e) { $row_err = 'Miktar formülü: ' . $e; }
        }
        if ($ct === 'per_kg') $qty = $basis;

        // 2) Tutar
        $price  = (float)($r['unit_price'] ?? 0);
        $amount = 0.0;
        switch ($ct) {
            case 'qty_price':
            case 'per_kg':
                $amount = $qty * $price;
                break;
            case 'fixed':
                $amount = $price * ($qty > 0 ? $qty : 1);
                break;
            case 'percent':
                $e = null;
                $base_expr = trim((string)($r['percent_base'] ?? 'ust_toplam'));
                if ($base_expr === '') $base_expr = 'ust_toplam';
                $base_val = cost_formula_eval($base_expr, $vars, $e);
                if ($e) $row_err = $row_err ?? ('Yüzde bazı: ' . $e);
                $amount = $base_val * ((float)($r['percent'] ?? 0) / 100.0);
                break;
            case 'formula':
                $e = null;
                $amount = cost_formula_eval((string)($r['formula'] ?? ''), $vars, $e);
                if ($e) $row_err = $row_err ?? ('Formül: ' . $e);
                break;
            case 'subtotal':
                $amount = $prev_sum;
                break;
            case 'info':
            default:
                $amount = 0.0;
                break;
        }

        if (is_nan($amount) || is_infinite($amount)) $amount = 0.0;
        $amount = round($amount, 4);

        $counts = !in_array($ct, ['info', 'subtotal'], true);
        $signed = $counts ? ((int)($r['is_income'] ?? 0) === 1 ? -$amount : $amount) : 0.0;

        if ($row_err) $errors[] = ($r['label'] ?: 'Satır') . ': ' . $row_err;

        $computed_by_id[$id] = [
            'id'         => $id,
            'code'       => cost_normalize_var((string)($r['code'] ?? '')),
            'label'      => (string)($r['label'] ?? ''),
            'calc_type'  => $ct,
            'qty'        => round($qty, 4),
            'unit_price' => $price,
            'amount'     => $amount,
            'signed'     => $signed,
            'counts'     => $counts,
            'is_income'  => (int)($r['is_income'] ?? 0),
            'unit_cost'  => 0.0,
            'error'      => $row_err,
        ];

        // Bu satırın sonucu sonraki satırlarda [kod] ile kullanılabilsin
        $c = $computed_by_id[$id];
        if ($c['code'] !== '') {
            $vars[$c['code']]                     = $c['amount'];
            $vars[$c['code'] . '.tutar']          = $c['amount'];
            $vars[$c['code'] . '.miktar']         = $c['qty'];
            $vars[$c['code'] . '.fiyat']          = $c['unit_price'];
        }
    }

    // Görsel sıraya geri döndür
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if (isset($computed_by_id[$id])) $out[] = $computed_by_id[$id];
    }
    $total = 0.0;
    foreach ($out as $c) $total += $c['signed'];

    return [$out, round($total, 4)];
}

// ---------------------------------------------------------
// 5) BİÇİMLENDİRME
// ---------------------------------------------------------

function cost_fmt_money($v, int $dec = 2): string
{
    return number_format((float)$v, $dec, ',', '.');
}

function cost_fmt_qty($v): string
{
    $f = (float)$v;
    // Tam sayıysa ondalık gösterme (KG ekranda virgülsüz kuralı)
    if (abs($f - round($f)) < 0.0005) return number_format($f, 0, ',', '.');
    return rtrim(rtrim(number_format($f, 3, ',', '.'), '0'), ',');
}

function cost_fmt_unit($v, int $dec = 4): string
{
    return number_format((float)$v, $dec, ',', '.');
}

/** Kalem/alan kodu üretir — benzersizleştirme çağıran tarafta */
function cost_slug(string $label): string
{
    $s = cost_normalize_var($label);
    $s = preg_replace('/[^a-z0-9_]+/', '_', $s) ?? $s;
    $s = trim(preg_replace('/_+/', '_', $s) ?? $s, '_');
    if ($s === '') $s = 'alan';
    return substr($s, 0, 36);
}

/** Yetki kısayolu — maliyet.* yoksa reports.* ile geriye dönük uyum */
function can_maliyet(string $perm): bool
{
    if (!function_exists('can')) return false;
    if (can('maliyet.' . $perm)) return true;
    if (function_exists('is_admin') && is_admin()) return true;
    return false;
}

function require_maliyet(string $perm): void
{
    if (function_exists('current_user') && current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    if (function_exists('enforce_active_depot')) enforce_active_depot();
    if (!can_maliyet($perm)) {
        if (function_exists('forbidden')) forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: maliyet.{$perm})");
        http_response_code(403);
        exit('Yetkisiz');
    }
}
