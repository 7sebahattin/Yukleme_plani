<?php
// =========================================================
// config/chrome_whatsapp_bridge.php — ASYA WHATSAPP WEB BRIDGE (Aşama 1)
//
// Chrome Manifest V3 eklentisi ile mevcut Asya Yeni Beyan altyapısı arasındaki
// bağımsız köprü çekirdeği.
//
// ÖNEMLİ:
//   • Mevcut WhatsApp modülüne (whatsapp_yonetim.php, whatsapp_core.php,
//     WhatsAppService.php, api_whatsapp_webhook.php, Evolution/Baileys) DOKUNMAZ.
//   • Mevcut Yeni Beyan formunun (beyan_create.php / _beyan_form.php) GERÇEK
//     alan şemasını dinamik olarak üretir; formda olmayan alan uydurmaz.
//   • AI çıktısını asla doğrudan veritabanına yazmaz; her taslak kullanıcı onayı
//     sonrası mevcut beyan validasyonları + yetki kontrolleriyle kaydedilir.
//   • Modüler parser kayıt defteri ile ileride yeni çıkarıcılar eklenebilir.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/whatsapp_config.php';
require_once __DIR__ . '/AIParserService.php';

const CW_BRIDGE_SCHEMA_VERSION = 'beyan-schema-v1';
const CW_BRIDGE_PAIR_TTL_SEC   = 600;       // 10 dakika
const CW_BRIDGE_MAX_BODY_BYTES = 131072;    // 128 KB

// ── Zaman & Şema Yardımcıları ─────────────────────────────

function cw_bridge_now(): string
{
    return date('Y-m-d H:i:s');
}

function cw_bridge_is_sqlite(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/**
 * Bağımsız cw_bridge_* tablolarını idempotent olarak oluşturur (MySQL + SQLite).
 */
function cw_bridge_ensure_schema(PDO $pdo): void
{
    if (cw_bridge_is_sqlite($pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cw_bridge_pairings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            code_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at TEXT NULL,
            created_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cw_bridge_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            device_label TEXT NULL,
            last_used_at TEXT NULL,
            revoked_at TEXT NULL,
            created_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cw_bridge_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_id INTEGER NULL,
            chat_name TEXT NOT NULL,
            chat_type TEXT NOT NULL DEFAULT 'unknown',
            source_message_id TEXT NULL,
            sender_name TEXT NULL,
            message_timestamp TEXT NULL,
            raw_text TEXT NOT NULL,
            normalized_hash TEXT NOT NULL,
            text_hash TEXT NOT NULL,
            reference_key TEXT NULL,
            status TEXT NOT NULL DEFAULT 'parsed',
            ai_provider TEXT NULL,
            ai_model TEXT NULL,
            ai_confidence INTEGER NULL,
            ai_result_json TEXT NULL,
            declaration_id INTEGER NULL,
            error_message TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `cw_bridge_pairings` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id`    INT NOT NULL,
        `code_hash`  CHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at`    DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_cwb_pair_hash` (`code_hash`),
        INDEX `idx_cwb_pair_user` (`user_id`, `expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `cw_bridge_tokens` (
        `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id`      INT NOT NULL,
        `token_hash`   CHAR(64) NOT NULL,
        `device_label` VARCHAR(120) NULL DEFAULT NULL,
        `last_used_at` DATETIME NULL DEFAULT NULL,
        `revoked_at`   DATETIME NULL DEFAULT NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_cwb_tok_hash` (`token_hash`),
        INDEX `idx_cwb_tok_user` (`user_id`, `revoked_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `cw_bridge_messages` (
        `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id`           INT NOT NULL,
        `token_id`          INT UNSIGNED NULL DEFAULT NULL,
        `chat_name`         VARCHAR(180) NOT NULL,
        `chat_type`         VARCHAR(20) NOT NULL DEFAULT 'unknown',
        `source_message_id` VARCHAR(180) NULL DEFAULT NULL,
        `sender_name`       VARCHAR(150) NULL DEFAULT NULL,
        `message_timestamp` VARCHAR(64) NULL DEFAULT NULL,
        `raw_text`          LONGTEXT NOT NULL,
        `normalized_hash`   CHAR(64) NOT NULL,
        `text_hash`         CHAR(64) NOT NULL,
        `reference_key`     VARCHAR(120) NULL DEFAULT NULL,
        `status`            VARCHAR(30) NOT NULL DEFAULT 'parsed',
        `ai_provider`       VARCHAR(30) NULL DEFAULT NULL,
        `ai_model`          VARCHAR(100) NULL DEFAULT NULL,
        `ai_confidence`     INT NULL DEFAULT NULL,
        `ai_result_json`    LONGTEXT NULL DEFAULT NULL,
        `declaration_id`    INT NULL DEFAULT NULL,
        `error_message`     VARCHAR(500) NULL DEFAULT NULL,
        `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_cwb_msg_source` (`chat_name`(120), `source_message_id`(120)),
        INDEX `idx_cwb_msg_hash` (`chat_name`(120), `normalized_hash`),
        INDEX `idx_cwb_msg_texthash` (`chat_name`(120), `text_hash`),
        INDEX `idx_cwb_msg_ref` (`reference_key`(80)),
        INDEX `idx_cwb_msg_decl` (`declaration_id`),
        INDEX `idx_cwb_msg_user` (`user_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ── Modüler Parser Kayıt Defteri (İleri Aşamalar İçin) ────

/**
 * İleride plaka, yükleme planı, belge (PDF/Excel), fotoğraf ve ses ayrıştırıcıları
 * bu kayıt defterine modül olarak eklenebilir. Aşama 1'de yalnız beyan_v1 etkindir.
 *
 * @return array<string, array{id:string, label:string, active:bool, schema_version:string}>
 */
function cw_bridge_parser_registry(): array
{
    return [
        'beyan_v1' => [
            'id'             => 'beyan_v1',
            'label'          => 'Yeni Beyan Taslağı (Metin)',
            'active'         => true,
            'schema_version' => CW_BRIDGE_SCHEMA_VERSION,
        ],
    ];
}

// ── Dinamik / Versiyonlanabilir Yeni Beyan Alan Şeması ────

/**
 * Mevcut HKS firmalarını salt-okunur okur (tablo yoksa boş dizi).
 * @return list<array{id:string, ad:string}>
 */
function cw_bridge_hks_firmalar(PDO $pdo): array
{
    $on = defined('HKS_TABLO_ON') ? HKS_TABLO_ON : 'hks_';
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $on)) return [];
    $out = [];
    try {
        foreach ($pdo->query("SELECT id, ad FROM `{$on}firmalar` ORDER BY ad") as $r) {
            $out[] = ['id' => (string)$r['id'], 'ad' => (string)$r['ad']];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * Mevcut HKS kataloğunu (ürünler + ülkeler) salt-okunur okur.
 * @return array{urunler:list<array{id:string, ad:string}>, ulkeler:list<array{id:string, ad:string}>}
 */
function cw_bridge_hks_katalog(): array
{
    if (!function_exists('bb_katalog')) {
        return ['urunler' => [], 'ulkeler' => []];
    }
    try {
        $k = bb_katalog();
        if (!is_array($k)) return ['urunler' => [], 'ulkeler' => []];
        return [
            'urunler' => is_array($k['urunler'] ?? null) ? $k['urunler'] : [],
            'ulkeler' => is_array($k['ulkeler'] ?? null) ? $k['ulkeler'] : [],
        ];
    } catch (Throwable $e) {
        return ['urunler' => [], 'ulkeler' => []];
    }
}

/**
 * Gerçek Yeni Beyan (beyan_create.php + _beyan_form.php) alan şemasını üretir.
 * Formda bulunmayan alan uydurulmaz; seçenekler ve yetkiler mevcut sistemden okunur.
 *
 * @return array{
 *   version:string,
 *   sections:array<string, string>,
 *   fields:list<array<string, mixed>>,
 *   statuses:array<string, array{label:string, css:string}>,
 *   hks_firmalar:list<array{id:string, ad:string}>,
 *   hks_urunler:list<array{id:string, ad:string}>,
 *   hks_ulkeler:list<array{id:string, ad:string}>,
 *   permissions:array<string, bool>
 * }
 */
function cw_bridge_beyan_schema(PDO $pdo, bool $includeCatalogs = true): array
{
    $statuses = function_exists('beyan_statuses') ? beyan_statuses() : [
        'taslak'          => ['label' => 'TASLAK',          'css' => 'taslak'],
        'beyan_acildi'    => ['label' => 'AÇILDI',          'css' => 'beyan_acildi'],
        'numune_bekliyor' => ['label' => 'NUMUNE',          'css' => 'numune_bekliyor'],
        'analiz_bekliyor' => ['label' => 'ANALİZ',          'css' => 'analiz_bekliyor'],
        'temiz'           => ['label' => 'TEMİZ',           'css' => 'temiz'],
        'red'             => ['label' => 'RED',             'css' => 'red'],
        'iptal'           => ['label' => 'İPTAL',           'css' => 'iptal'],
        'yukleme_olustu'  => ['label' => 'YÜKLEME OLUŞTU', 'css' => 'yukleme_olustu'],
        'yuklendi'        => ['label' => 'YÜKLENDİ',       'css' => 'yuklendi'],
    ];
    $statusOrder = function_exists('beyan_durum_akis_sirasi')
        ? beyan_durum_akis_sirasi()
        : array_keys($statuses);

    $statusOptions = [];
    foreach ($statusOrder as $sk) {
        if (!isset($statuses[$sk])) continue;
        $statusOptions[] = ['value' => $sk, 'label' => (string)$statuses[$sk]['label']];
    }

    $firmalar = $includeCatalogs ? cw_bridge_hks_firmalar($pdo) : [];
    $katalog  = $includeCatalogs ? cw_bridge_hks_katalog() : ['urunler' => [], 'ulkeler' => []];

    $can = static function (string $perm): bool {
        return function_exists('can_beyan') ? can_beyan($perm) : true;
    };

    $fields = [
        // ── 1. Parti / Sevkiyat ──
        [
            'name' => 'party_no', 'label' => 'Parti No', 'section' => 'parti', 'db_column' => 'party_no',
            'type' => 'string', 'max_length' => 100, 'required' => false, 'required_group' => 'any_identity',
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Parti numarası / kodu ("parti" kelimesi hariç, ör. "61-67", "46/22").',
        ],
        [
            'name' => 'buyer_name', 'label' => 'Alıcı', 'section' => 'parti', 'db_column' => 'buyer_name',
            'type' => 'string', 'max_length' => 150, 'required' => false,
            'permission_group' => 'contact', 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Alıcı / müşteri firma adı.',
        ],
        [
            'name' => 'exit_depot', 'label' => 'Çıkış Depo', 'section' => 'parti', 'db_column' => 'exit_depot',
            'type' => 'string', 'max_length' => 150, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Çıkış deposu / paketleme tesisi ("Çıkış", "Depo" ifadesinden).',
        ],
        [
            'name' => 'transport_type', 'label' => 'Nakliye Türü', 'section' => 'parti', 'db_column' => 'transport_type',
            'type' => 'string', 'max_length' => 100, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Taşıma türü (DENİZYOLU, KARAYOLU, HAVAYOLU, RO-RO, KONTEYNER, TIR vb.).',
        ],
        [
            'name' => 'line_type', 'label' => 'Hat / Güzergah', 'section' => 'parti', 'db_column' => 'line_type',
            'type' => 'string', 'max_length' => 100, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Hat, güzergah veya teslim şekli + varış limanı/şehri.',
        ],
        [
            'name' => 'sender_company_name', 'label' => 'Gönderici Firma', 'section' => 'parti', 'db_column' => 'sender_company_name',
            'type' => 'datalist', 'max_length' => 150, 'required' => true,
            'permission_group' => 'hks', 'ai_extractable' => true, 'uppercase' => false,
            'ai_hint' => 'Yalnız mesajda açıkça gönderici/ihracatçı olarak belirtilen firma adı; alıcı veya çıkış deposunu buraya yazma.',
        ],
        [
            'name' => 'hks_ulke_id', 'label' => 'Ülke', 'section' => 'parti', 'db_column' => 'hks_ulke_id',
            'type' => 'select', 'max_length' => 40, 'required' => false,
            'permission_group' => 'hks', 'ai_extractable' => false, 'ai_source_field' => 'country_name',
            'ai_hint' => 'Hedef ülke (AI country_name çıkarır, backend HKS ülke kataloğundan ID eşleştirir).',
        ],
        [
            'name' => 'tractor_plate', 'label' => 'Çekici Plaka', 'section' => 'parti', 'db_column' => 'tractor_plate',
            'type' => 'string', 'max_length' => 20, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Çekici / kamyon plakası (tek plaka varsa ve ayrım yoksa buraya).',
        ],
        [
            'name' => 'vehicle_plate', 'label' => 'Dorse Plaka', 'section' => 'parti', 'db_column' => 'vehicle_plate',
            'type' => 'string', 'max_length' => 30, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Dorse / römork plakası.',
        ],
        [
            'name' => 'driver_name', 'label' => 'Şoför Adı', 'section' => 'parti', 'db_column' => 'driver_name',
            'type' => 'string', 'max_length' => 150, 'required' => false,
            'permission_group' => 'contact', 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Şoför adı soyadı.',
        ],
        [
            'name' => 'driver_phone', 'label' => 'Şoför Telefonu', 'section' => 'parti', 'db_column' => 'driver_phone',
            'type' => 'tel', 'max_length' => 40, 'required' => false,
            'permission_group' => 'contact', 'ai_extractable' => true, 'uppercase' => false,
            'ai_hint' => 'Şoför telefon numarası (7-15 rakam).',
        ],

        // ── 2. Ürün ve Miktarlar ──
        [
            'name' => 'product_name', 'label' => 'Ürün Adı', 'section' => 'urun', 'db_column' => 'product_name',
            'type' => 'string', 'max_length' => 150, 'required' => false, 'required_group' => 'any_identity',
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Ana tarım ürünü / mahsul adı.',
        ],
        [
            'name' => 'hks_urun_id', 'label' => 'HKS Ürün Adı', 'section' => 'urun', 'db_column' => 'hks_urun_id',
            'type' => 'select', 'max_length' => 40, 'required' => false,
            'permission_group' => 'hks', 'ai_extractable' => false,
            'ai_hint' => 'HKS ürün kataloğu karşılığı (backend product_name üzerinden otomatik eşler veya kullanıcı seçer).',
        ],
        [
            'name' => 'product_variety', 'label' => 'Ürün Çeşidi', 'section' => 'urun', 'db_column' => 'product_variety',
            'type' => 'string', 'max_length' => 150, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Ürün çeşidi, varyete veya kalibre bilgisi.',
        ],
        [
            'name' => 'brand', 'label' => 'Ambalaj Marka', 'section' => 'urun', 'db_column' => 'brand',
            'type' => 'string', 'max_length' => 50, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Ambalaj / kutu markası.',
        ],
        [
            'name' => 'pallet_count', 'label' => 'Palet Adedi', 'section' => 'urun', 'db_column' => 'pallet_count',
            'type' => 'integer', 'max_length' => 12, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true,
            'ai_hint' => 'Toplam palet adedi (tam sayı; çoklu blok varsa toplamı).',
        ],
        [
            'name' => 'crate_count', 'label' => 'Kasa Adedi', 'section' => 'urun', 'db_column' => 'crate_count',
            'type' => 'integer', 'max_length' => 12, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true,
            'ai_hint' => 'Toplam kasa / sandık / kutu adedi (tam sayı; çoklu blok varsa toplamı).',
        ],
        [
            'name' => 'crate_type', 'label' => 'Kasa Cinsi', 'section' => 'urun', 'db_column' => 'crate_type',
            'type' => 'string', 'max_length' => 100, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Kasa / ambalaj cinsi (PLASTİK, KARTON, TAHTA vb.).',
        ],
        [
            'name' => 'gross_kg', 'label' => 'Brüt KG', 'section' => 'urun', 'db_column' => 'gross_kg',
            'type' => 'number', 'max_length' => 16, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true,
            'ai_hint' => 'Toplam brüt ağırlık (KG; çoklu blok varsa toplamı; nokta binlik ayraç).',
        ],
        [
            'name' => 'net_kg', 'label' => 'Net KG', 'section' => 'urun', 'db_column' => 'net_kg',
            'type' => 'number', 'max_length' => 16, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true,
            'ai_hint' => 'Toplam net ağırlık (KG; Brüt KG değerinden büyük olamaz).',
        ],

        // ── 3. Numune ──
        [
            'name' => 'sample_crate_count', 'label' => 'Numune Kasa Adedi', 'section' => 'numune', 'db_column' => 'sample_crate_count',
            'type' => 'integer', 'max_length' => 12, 'required' => false,
            'permission_group' => 'analysis', 'ai_extractable' => true,
            'ai_hint' => 'Yalnız mesajda açıkça numune/örnek için ayrı kasa adedi verildiyse.',
        ],
        [
            'name' => 'sample_crate_type', 'label' => 'Numune Kasa Cinsi', 'section' => 'numune', 'db_column' => 'sample_crate_type',
            'type' => 'string', 'max_length' => 100, 'required' => false,
            'permission_group' => 'analysis', 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Numune kasa cinsi (yalnız açıkça belirtildiyse).',
        ],
        [
            'name' => 'sample_gross_kg', 'label' => 'Numune Brüt KG', 'section' => 'numune', 'db_column' => 'sample_gross_kg',
            'type' => 'number', 'max_length' => 16, 'required' => false,
            'permission_group' => 'analysis', 'ai_extractable' => true,
            'ai_hint' => 'Numune brüt ağırlık (KG; yalnız açıkça belirtildiyse).',
        ],
        [
            'name' => 'sample_net_kg', 'label' => 'Numune Net KG', 'section' => 'numune', 'db_column' => 'sample_net_kg',
            'type' => 'number', 'max_length' => 16, 'required' => false,
            'permission_group' => 'analysis', 'ai_extractable' => true,
            'ai_hint' => 'Numune net ağırlık (KG; Numune Brüt KG değerinden büyük olamaz).',
        ],

        // ── 4. Gümrük ve İletişim ──
        [
            'name' => 'company_name', 'label' => 'Gümrük Adı', 'section' => 'gumruk', 'db_column' => 'company_name',
            'type' => 'string', 'max_length' => 255, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Gümrük müşavirliği / gümrük firması adı (yalnız açıkça geçiyorsa).',
        ],
        [
            'name' => 'contact_person', 'label' => 'İlgili Kişi', 'section' => 'gumruk', 'db_column' => 'contact_person',
            'type' => 'string', 'max_length' => 150, 'required' => false,
            'permission_group' => 'contact', 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'İlgili / yetkili kişi adı.',
        ],
        [
            'name' => 'company_address', 'label' => 'Gümrük Adresi', 'section' => 'gumruk', 'db_column' => 'company_address',
            'type' => 'textarea', 'max_length' => 2000, 'required' => false,
            'permission_group' => 'contact', 'ai_extractable' => true, 'uppercase' => true,
            'ai_hint' => 'Gümrük adresi (yalnız açıkça geçiyorsa).',
        ],
        [
            'name' => 'status', 'label' => 'Durum', 'section' => 'gumruk', 'db_column' => 'status',
            'type' => 'select', 'max_length' => 50, 'required' => true, 'default' => 'taslak',
            'permission_group' => null, 'ai_extractable' => false,
            'options' => $statusOptions, 'allowed_values' => array_keys($statuses),
            'ai_hint' => 'Beyan durumu; Chrome Bridge taslakları varsayılan olarak "taslak" açılır.',
        ],

        // ── 5. Not ──
        [
            'name' => 'note', 'label' => 'Not', 'section' => 'not', 'db_column' => 'note',
            'type' => 'textarea', 'max_length' => 2000, 'required' => false,
            'permission_group' => null, 'ai_extractable' => true, 'uppercase' => false,
            'ai_hint' => 'Sipariş kodu, çoklu kalem kırılımı ve diğer alanlara sığmayan önemli bilgiler.',
        ],

        // ── 6. Analiz Bilgileri ──
        [
            'name' => 'sample_taken_at', 'label' => 'Numune Alındı Tarihi', 'section' => 'analiz', 'db_column' => 'sample_taken_at',
            'type' => 'datetime', 'max_length' => 19, 'required' => false,
            'permission_group' => 'analysis', 'ai_extractable' => false,
            'ai_hint' => 'Numune alınma tarih/saati (isteğe bağlı manuel giriş).',
        ],
        [
            'name' => 'analysis_result_at', 'label' => 'Analiz Sonuç Tarihi', 'section' => 'analiz', 'db_column' => 'analysis_result_at',
            'type' => 'datetime', 'max_length' => 19, 'required' => false,
            'permission_group' => 'analysis', 'ai_extractable' => false,
            'ai_hint' => 'Analiz sonuç tarih/saati (isteğe bağlı manuel giriş).',
        ],
        [
            'name' => 'analysis_note', 'label' => 'Analiz Notu', 'section' => 'analiz', 'db_column' => 'analysis_note',
            'type' => 'textarea', 'max_length' => 2000, 'required' => false,
            'permission_group' => 'analysis', 'ai_extractable' => false,
            'ai_hint' => '"red" durumunda zorunlu analiz açıklaması.',
        ],

        // ── 7. Kaynak Metin ──
        [
            'name' => 'raw_text', 'label' => 'Kaynak metin', 'section' => 'kaynak', 'db_column' => 'raw_text',
            'type' => 'textarea', 'max_length' => 65000, 'required' => false, 'required_group' => 'any_identity',
            'permission_group' => 'source', 'ai_extractable' => false,
            'ai_hint' => 'Orijinal WhatsApp mesaj metni (otomatik korunur).',
        ],
        [
            'name' => 'unmatched_text', 'label' => 'Kontrol edilecek metin', 'section' => 'kaynak', 'db_column' => 'unmatched_text',
            'type' => 'textarea', 'max_length' => 4000, 'required' => false,
            'permission_group' => 'source', 'ai_extractable' => false,
            'ai_hint' => 'Eşleştirilemeyen veya belirsiz kalan ham satırlar.',
        ],
    ];

    return [
        'version' => CW_BRIDGE_SCHEMA_VERSION,
        'sections' => [
            'parti'  => 'Parti / Sevkiyat',
            'urun'   => 'Ürün ve Miktarlar',
            'numune' => 'Numune',
            'gumruk' => 'Gümrük ve İletişim',
            'not'    => 'Not',
            'analiz' => 'Analiz Bilgileri',
            'kaynak' => 'Kaynak Metin',
        ],
        'fields'       => $fields,
        'statuses'     => $statuses,
        'hks_firmalar' => $firmalar,
        'hks_urunler'  => $katalog['urunler'],
        'hks_ulkeler'  => $katalog['ulkeler'],
        'permissions'  => [
            'create'         => $can('create'),
            'status'         => $can('status'),
            'contact_read'   => $can('contact.read'),
            'contact_write'  => $can('contact.write'),
            'analysis_read'  => $can('analysis.read'),
            'analysis_write' => $can('analysis.write'),
            'source_read'    => $can('source.read'),
            'source_write'   => $can('source.write'),
            'hks_read'       => $can('hks.read'),
            'hks_write'      => $can('hks.write'),
        ],
    ];
}

// ── Güvenlik: HTTPS, CORS, Hız Sınırı ─────────────────────

function cw_bridge_is_https_request(): bool
{
    if (defined('CW_BRIDGE_ALLOW_HTTP') && CW_BRIDGE_ALLOW_HTTP === true) return true;
    if (function_exists('is_https') && is_https()) return true;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $hostOnly = preg_replace('/:\d+$/', '', $host);
    if (in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)) return true;
    return false;
}

function cw_bridge_allowed_origin(?string $origin): ?string
{
    if ($origin === null || $origin === '') return null;
    $origin = trim($origin);
    if (preg_match('#^chrome-extension://[a-p]{32}$#', $origin)) return $origin;
    if ($origin === 'https://asya.scai.tr') return $origin;
    if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) return $origin;
    return null;
}

function cw_bridge_rate_limit_dir(): string
{
    if (!empty($GLOBALS['_cw_bridge_rl_dir']) && is_string($GLOBALS['_cw_bridge_rl_dir'])) {
        $dir = $GLOBALS['_cw_bridge_rl_dir'];
    } else {
        $dir = dirname(__DIR__) . '/storage/cw_bridge_rl';
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $dir = rtrim(sys_get_temp_dir(), '/\\') . '/asya_cw_bridge_rl';
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
        }
    }
    return $dir;
}

/**
 * Basit ve atomik kayan pencere hız sınırlayıcı.
 * @return array{ok:bool, remaining:int, retry_after:int}
 */
function cw_bridge_rate_limit(string $bucket, int $limit = 60, int $windowSec = 60, ?int $now = null): array
{
    $now = $now ?? time();
    $file = cw_bridge_rate_limit_dir() . '/' . hash('sha256', $bucket) . '.json';
    $fp = @fopen($file, 'c+');
    if ($fp === false) return ['ok' => true, 'remaining' => $limit, 'retry_after' => 0];
    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['ok' => true, 'remaining' => $limit, 'retry_after' => 0];
    }
    $raw = stream_get_contents($fp);
    $st = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $hits = is_array($st['hits'] ?? null) ? array_values(array_filter($st['hits'], static fn($t) => is_int($t) && $t > ($now - $windowSec))) : [];
    if (count($hits) >= $limit) {
        $oldest = min($hits);
        $retry = max(1, ($oldest + $windowSec) - $now);
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['ok' => false, 'remaining' => 0, 'retry_after' => $retry];
    }
    $hits[] = $now;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, (string)json_encode(['hits' => $hits]));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return ['ok' => true, 'remaining' => max(0, $limit - count($hits)), 'retry_after' => 0];
}

// ── Eşleştirme Kodu & İptal Edilebilir Token Yönetimi ─────

function cw_bridge_hash_secret(string $secret): string
{
    return hash('sha256', $secret);
}

/**
 * Tek kullanımlık, süreli (varsayılan 10 dk) eşleştirme kodu üretir.
 * @return array{code:string, expires_at:string, ttl:int}
 */
function cw_bridge_create_pairing_code(PDO $pdo, int $userId, int $ttlSeconds = CW_BRIDGE_PAIR_TTL_SEC): array
{
    cw_bridge_ensure_schema($pdo);
    $ttl = max(60, min(1800, $ttlSeconds));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parts = [];
    for ($g = 0; $g < 3; $g++) {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $parts[] = $chunk;
    }
    $code = 'ASYA-' . implode('-', $parts);
    $now = cw_bridge_now();
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $st = $pdo->prepare('INSERT INTO cw_bridge_pairings (user_id, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?)');
    $st->execute([$userId, cw_bridge_hash_secret($code), $expiresAt, $now]);

    return ['code' => $code, 'expires_at' => $expiresAt, 'ttl' => $ttl];
}

/**
 * Tek kullanımlık eşleştirme kodunu tüketir ve iptal edilebilir erişim token'ı üretir.
 * Token veritabanında YALNIZ SHA-256 hash olarak saklanır.
 *
 * @return array{ok:bool, error?:string, token?:string, token_id?:int, user_id?:int, device_label?:string}
 */
function cw_bridge_exchange_pairing_code(PDO $pdo, string $rawCode, string $deviceLabel = ''): array
{
    cw_bridge_ensure_schema($pdo);
    $code = strtoupper(trim($rawCode));
    if ($code === '' || strlen($code) > 64) {
        return ['ok' => false, 'error' => 'Eşleştirme kodu geçersiz.'];
    }
    $hash = cw_bridge_hash_secret($code);
    $now = cw_bridge_now();
    $label = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $deviceLabel)), 0, 120, 'UTF-8');
    if ($label === '') $label = 'Chrome Eklentisi';

    $pdo->beginTransaction();
    try {
        $lock = cw_bridge_is_sqlite($pdo) ? '' : ' FOR UPDATE';
        $st = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM cw_bridge_pairings WHERE code_hash = ?' . $lock);
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Eşleştirme kodu bulunamadı veya hatalı.'];
        }
        if ($row['used_at'] !== null) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Bu eşleştirme kodu daha önce kullanılmış.'];
        }
        if ((string)$row['expires_at'] < $now) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Eşleştirme kodunun süresi dolmuş. Yeni kod üretin.'];
        }

        // Kullanıcının aktif olduğunu doğrula
        $ust = $pdo->prepare('SELECT id, is_active FROM users WHERE id = ? LIMIT 1');
        $ust->execute([(int)$row['user_id']]);
        $u = $ust->fetch(PDO::FETCH_ASSOC);
        if (!$u || !(bool)$u['is_active']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Kullanıcı hesabı aktif değil.'];
        }

        $pdo->prepare('UPDATE cw_bridge_pairings SET used_at = ? WHERE id = ?')->execute([$now, (int)$row['id']]);

        $rawToken = 'cwb_' . bin2hex(random_bytes(32));
        $tokHash = cw_bridge_hash_secret($rawToken);
        $tst = $pdo->prepare('INSERT INTO cw_bridge_tokens (user_id, token_hash, device_label, last_used_at, created_at) VALUES (?, ?, ?, ?, ?)');
        $tst->execute([(int)$row['user_id'], $tokHash, $label, $now, $now]);
        $tokenId = (int)$pdo->lastInsertId();

        $pdo->commit();
        return [
            'ok'           => true,
            'token'        => $rawToken,
            'token_id'     => $tokenId,
            'user_id'      => (int)$row['user_id'],
            'device_label' => $label,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'Eşleştirme tamamlanamadı.'];
    }
}

/**
 * Bearer token'ı doğrular ve aktif kullanıcı bilgisini döndürür.
 * İptal edilmiş veya pasif kullanıcılı token'ı reddeder.
 *
 * @return array{token_id:int, device_label:string, user:array<string, mixed>}|null
 */
function cw_bridge_authenticate_token(PDO $pdo, string $rawToken): ?array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || strlen($rawToken) < 20 || strlen($rawToken) > 128) return null;
    cw_bridge_ensure_schema($pdo);
    $hash = cw_bridge_hash_secret($rawToken);

    $st = $pdo->prepare('
        SELECT t.id AS token_id, t.device_label, t.revoked_at,
               u.id, u.username, u.email, u.display_name, u.is_active
        FROM cw_bridge_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ?
        LIMIT 1
    ');
    $st->execute([$hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ($row['revoked_at'] !== null) return null;
    if (!(bool)$row['is_active']) return null;

    try {
        $pdo->prepare('UPDATE cw_bridge_tokens SET last_used_at = ? WHERE id = ?')
            ->execute([cw_bridge_now(), (int)$row['token_id']]);
    } catch (Throwable $e) {}

    $user = [
        'id'           => (int)$row['id'],
        'username'     => (string)$row['username'],
        'email'        => (string)($row['email'] ?? ''),
        'display_name' => (string)($row['display_name'] ?? $row['username']),
        'is_active'    => (int)$row['is_active'],
    ];
    return [
        'token_id'     => (int)$row['token_id'],
        'device_label' => (string)($row['device_label'] ?? ''),
        'user'         => $user,
    ];
}

function cw_bridge_revoke_token(PDO $pdo, int $tokenId, ?int $userId = null): bool
{
    cw_bridge_ensure_schema($pdo);
    $now = cw_bridge_now();
    if ($userId !== null) {
        $st = $pdo->prepare('UPDATE cw_bridge_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
        $st->execute([$now, $tokenId, $userId]);
    } else {
        $st = $pdo->prepare('UPDATE cw_bridge_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
        $st->execute([$now, $tokenId]);
    }
    return $st->rowCount() > 0;
}

/** @return list<array<string, mixed>> */
function cw_bridge_list_tokens(PDO $pdo, int $userId): array
{
    cw_bridge_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id, device_label, last_used_at, revoked_at, created_at FROM cw_bridge_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 30');
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Mükerrer Kontrol & Referans Çakışma Uyarısı ───────────

function cw_bridge_normalize_text(string $text): string
{
    $t = str_replace(["\r\n", "\r", "\xc2\xa0", "\xe2\x80\x8b", "\xef\xbb\xbf"], ["\n", "\n", ' ', '', ''], $text);
    $t = (string)preg_replace('/[ \t]+/u', ' ', $t);
    $t = (string)preg_replace('/\n{2,}/u', "\n", $t);
    return mb_strtolower(trim($t), 'UTF-8');
}

/** @return array{normalized_hash:string, text_hash:string} */
function cw_bridge_message_hashes(string $chatName, string $senderName, string $timestamp, string $rawText): array
{
    $normText = cw_bridge_normalize_text($rawText);
    $normChat = mb_strtolower(trim($chatName), 'UTF-8');
    $normSender = mb_strtolower(trim($senderName), 'UTF-8');
    $normTs = mb_strtolower(trim($timestamp), 'UTF-8');
    return [
        'normalized_hash' => hash('sha256', $normChat . '|' . $normSender . '|' . $normTs . '|' . $normText),
        'text_hash'       => hash('sha256', $normChat . '|' . $normText),
    ];
}

/**
 * Aynı WhatsApp mesajının daha önce aktarılıp aktarılmadığını kontrol eder.
 * @return array<string, mixed>|null
 */
function cw_bridge_find_duplicate(PDO $pdo, string $chatName, string $sourceMsgId, string $normHash, string $textHash): ?array
{
    cw_bridge_ensure_schema($pdo);
    $chatName = trim($chatName);
    $sourceMsgId = trim($sourceMsgId);

    if ($sourceMsgId !== '') {
        $st = $pdo->prepare('SELECT * FROM cw_bridge_messages WHERE chat_name = ? AND source_message_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$chatName, $sourceMsgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) return $row;
    }

    $st = $pdo->prepare('SELECT * FROM cw_bridge_messages WHERE chat_name = ? AND (normalized_hash = ? OR text_hash = ?) ORDER BY id DESC LIMIT 1');
    $st->execute([$chatName, $normHash, $textHash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Farklı bir mesajda aynı parti/referans numarası varsa kullanıcıya uyarı listesi döndürür.
 * Mevcut kaydı ASLA silmez, değiştirmez veya birleştirmez.
 *
 * @return list<array{source:string, id:int, party_no:string, status:string, created_at:string}>
 */
function cw_bridge_find_reference_warnings(PDO $pdo, ?string $partyNo, ?int $excludeBridgeId = null): array
{
    $party = trim((string)$partyNo);
    if ($party === '') return [];
    $partyUpper = function_exists('tr_upper') ? tr_upper($party) : mb_strtoupper($party, 'UTF-8');
    $warnings = [];

    try {
        $st = $pdo->prepare('SELECT id, party_no, status, created_at FROM customs_declarations WHERE deleted_at IS NULL AND UPPER(party_no) = UPPER(?) ORDER BY id DESC LIMIT 5');
        $st->execute([$partyUpper]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $warnings[] = [
                'source'     => 'customs_declarations',
                'id'         => (int)$r['id'],
                'party_no'   => (string)$r['party_no'],
                'status'     => (string)$r['status'],
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}

    try {
        $sql = 'SELECT id, reference_key, status, created_at FROM cw_bridge_messages WHERE UPPER(reference_key) = UPPER(?)';
        $params = [$partyUpper];
        if ($excludeBridgeId !== null && $excludeBridgeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeBridgeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 5';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $warnings[] = [
                'source'     => 'cw_bridge_messages',
                'id'         => (int)$r['id'],
                'party_no'   => (string)$r['reference_key'],
                'status'     => (string)$r['status'],
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}

    return $warnings;
}

// ── Mevcut AI Servisini Salt-Okunur Yapılandırmayla Kurma ─

/**
 * Mevcut sistemdeki etkin AI sağlayıcısı ve anahtarıyla AIParserService kurar.
 * Mevcut whatsapp_ayarlar tablosunu yalnız SALT-OKUNUR okur; yoksa config/whatsapp_config.php sabitlerini kullanır.
 */
function cw_bridge_resolve_ai_service(PDO $pdo, ?callable $transport = null): AIParserService
{
    $provider = (string)AI_PROVIDER;
    $apiKey   = (string)AI_API_KEY;
    try {
        $r = $pdo->query('SELECT ai_saglayici, ai_api_key FROM whatsapp_ayarlar WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (is_array($r)) {
            $p = strtolower(trim((string)($r['ai_saglayici'] ?? '')));
            if (in_array($p, ['groq', 'gemini'], true)) $provider = $p;
            $k = trim((string)($r['ai_api_key'] ?? ''));
            if ($k !== '') $apiKey = $k;
        }
    } catch (Throwable $e) {
        // Tablo yoksa veya ai_api_key kolonu yoksa config sabitleri kullanılır.
    }
    return new AIParserService($provider, $apiKey, wa_etkin_model($provider), $transport);
}

// ── AI Çıktısını Katalog ve Kurallarla Doğrulama ──────────

/**
 * Şehir / İngilizce ülke adı → Türkçe ülke adı eşleme tablosu.
 * @return array<string, string>
 */
function cw_bridge_country_alias_map(): array
{
    return [
        'russia' => 'rusya', 'russian federation' => 'rusya', 'rusya federasyonu' => 'rusya', 'rusya' => 'rusya',
        'moscow' => 'rusya', 'moskova' => 'rusya', 'novorossiysk' => 'rusya', 'novorossisk' => 'rusya',
        'krasnodar' => 'rusya', 'sochi' => 'rusya', 'soci' => 'rusya', 'petersburg' => 'rusya',
        'peterburg' => 'rusya', 'rostov' => 'rusya', 'kazan' => 'rusya', 'samara' => 'rusya', 'yekaterinburg' => 'rusya',
        'iraq' => 'irak', 'irak' => 'irak', 'erbil' => 'irak', 'bagdat' => 'irak', 'baghdad' => 'irak',
        'zaho' => 'irak', 'zakho' => 'irak', 'suleymaniye' => 'irak', 'basra' => 'irak', 'musul' => 'irak',
        'romania' => 'romanya', 'romanya' => 'romanya', 'bukres' => 'romanya', 'bucharest' => 'romanya', 'kostence' => 'romanya', 'constanta' => 'romanya',
        'bulgaria' => 'bulgaristan', 'bulgaristan' => 'bulgaristan', 'sofya' => 'bulgaristan', 'sofia' => 'bulgaristan', 'varna' => 'bulgaristan', 'plovdiv' => 'bulgaristan',
        'poland' => 'polonya', 'polonya' => 'polonya', 'varsova' => 'polonya', 'warsaw' => 'polonya', 'krakow' => 'polonya', 'gdansk' => 'polonya',
        'germany' => 'almanya', 'almanya' => 'almanya', 'berlin' => 'almanya', 'munih' => 'almanya', 'munich' => 'almanya', 'frankfurt' => 'almanya', 'hamburg' => 'almanya',
        'ukraine' => 'ukrayna', 'ukrayna' => 'ukrayna', 'kiev' => 'ukrayna', 'kyiv' => 'ukrayna', 'odessa' => 'ukrayna', 'lviv' => 'ukrayna',
        'moldova' => 'moldova', 'kisinev' => 'moldova', 'chisinau' => 'moldova',
        'georgia' => 'gurcistan', 'gurcistan' => 'gurcistan', 'tiflis' => 'gurcistan', 'tbilisi' => 'gurcistan', 'batum' => 'gurcistan', 'batumi' => 'gurcistan',
        'azerbaijan' => 'azerbaycan', 'azerbaycan' => 'azerbaycan', 'baku' => 'azerbaycan',
        'belarus' => 'belarus', 'minsk' => 'belarus',
        'serbia' => 'sirbistan', 'sirbistan' => 'sirbistan', 'belgrad' => 'sirbistan', 'belgrade' => 'sirbistan',
        'hungary' => 'macaristan', 'macaristan' => 'macaristan', 'budapeste' => 'macaristan', 'budapest' => 'macaristan',
        'netherlands' => 'hollanda', 'hollanda' => 'hollanda', 'rotterdam' => 'hollanda', 'amsterdam' => 'hollanda',
        'greece' => 'yunanistan', 'yunanistan' => 'yunanistan', 'atina' => 'yunanistan', 'selanik' => 'yunanistan',
    ];
}

function cw_bridge_norm_ascii_tr(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['ı' => 'i', 'İ' => 'i', 'i̇' => 'i', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u']);
    return (string)preg_replace('/\s+/u', ' ', $s);
}

/**
 * HKS ülke adından katalog ID'si çözer (uydurma ID üretmez).
 * Şehir ve İngilizce ülke takma adlarını da destekler (MOSKOVA/RUSSIA → RUSYA FEDERASYONU).
 * @param list<array{id:string, ad:string}> $ulkeler
 * @return array{id:?string, ad:?string}
 */
function cw_bridge_match_hks_country(?string $countryName, array $ulkeler): array
{
    $c = trim((string)$countryName);
    if ($c === '' || !$ulkeler) return ['id' => null, 'ad' => null];
    $target = cw_bridge_norm_ascii_tr($c);
    $aliases = cw_bridge_country_alias_map();
    if (isset($aliases[$target])) {
        $target = $aliases[$target];
    }
    foreach ($ulkeler as $u) {
        if (cw_bridge_norm_ascii_tr((string)($u['ad'] ?? '')) === $target) {
            return ['id' => (string)$u['id'], 'ad' => (string)$u['ad']];
        }
    }
    // Kısmi eşleşme yalnız tekilse kabul edilir (ör. "rusya" → "RUSYA FEDERASYONU")
    $partial = [];
    foreach ($ulkeler as $u) {
        $n = cw_bridge_norm_ascii_tr((string)($u['ad'] ?? ''));
        if ($n !== '' && (str_contains($n, $target) || str_contains($target, $n))) {
            $partial[] = $u;
        }
    }
    if (count($partial) === 1) {
        return ['id' => (string)$partial[0]['id'], 'ad' => (string)$partial[0]['ad']];
    }
    return ['id' => null, 'ad' => null];
}

/**
 * Ham metin / adres / alıcı / hat bilgilerinden şehir veya ülke adına göre HKS ülkesini çözer.
 * @param list<array{id:string, ad:string}> $ulkeler
 * @return array{id:?string, ad:?string, matched_token:?string}
 */
function cw_bridge_infer_country_from_text(string $haystack, array $ulkeler): array
{
    if (trim($haystack) === '' || !$ulkeler) return ['id' => null, 'ad' => null, 'matched_token' => null];
    $normHay = cw_bridge_norm_ascii_tr($haystack);
    foreach (cw_bridge_country_alias_map() as $token => $trCountry) {
        if (preg_match('/(?<![a-z0-9])' . preg_quote($token, '/') . '(?![a-z0-9])/u', $normHay)) {
            $m = cw_bridge_match_hks_country($trCountry, $ulkeler);
            if ($m['id'] !== null) {
                return ['id' => $m['id'], 'ad' => $m['ad'], 'matched_token' => $token];
            }
        }
    }
    foreach ($ulkeler as $u) {
        $adNorm = cw_bridge_norm_ascii_tr((string)($u['ad'] ?? ''));
        if (strlen($adNorm) >= 4 && preg_match('/(?<![a-z0-9])' . preg_quote($adNorm, '/') . '(?![a-z0-9])/u', $normHay)) {
            return ['id' => (string)$u['id'], 'ad' => (string)$u['ad'], 'matched_token' => $adNorm];
        }
    }
    return ['id' => null, 'ad' => null, 'matched_token' => null];
}

/**
 * AI çıktısını mevcut Yeni Beyan form alanlarına, kataloglara ve doğrulama kurallarına uygular.
 * Veritabanına HİÇBİR kayıt oluşturmaz (eşleşmeyen firma/ürün için yeni kayıt açmaz).
 *
 * @param array<string, mixed> $aiOut   AIParserService::parseBeyanBridge() sonucu
 * @param array<string, mixed> $schema  cw_bridge_beyan_schema() sonucu
 * @return array{
 *   form_fields:array<string, string|int|float|null>,
 *   hks_matches:array<string, mixed>,
 *   validation_errors:list<string>,
 *   missing_required:list<string>,
 *   uncertain_fields:list<string>,
 *   conflicts:list<string>
 * }
 */
function cw_bridge_validate_and_match_ai(PDO $pdo, array $aiOut, array $schema, string $rawText): array
{
    $aiFields = is_array($aiOut['fields'] ?? null) ? $aiOut['fields'] : [];
    $uncertain = is_array($aiOut['uncertain_fields'] ?? null) ? $aiOut['uncertain_fields'] : [];
    $conflicts = is_array($aiOut['conflicts'] ?? null) ? $aiOut['conflicts'] : [];

    $form = [
        'party_no'            => '',
        'buyer_name'          => '',
        'exit_depot'          => '',
        'transport_type'      => '',
        'line_type'           => '',
        'sender_company_name' => '',
        'hks_firma_id'        => '',
        'hks_ulke_id'         => '',
        'hks_ulke_ad'         => '',
        'tractor_plate'       => '',
        'vehicle_plate'       => '',
        'driver_name'         => '',
        'driver_phone'        => '',
        'product_name'        => '',
        'hks_urun_id'         => '',
        'hks_urun_ad'         => '',
        'product_variety'     => '',
        'brand'               => '',
        'pallet_count'        => '',
        'crate_count'         => '',
        'crate_type'          => '',
        'gross_kg'            => '',
        'net_kg'              => '',
        'sample_crate_count'  => '',
        'sample_crate_type'   => '',
        'sample_gross_kg'     => '',
        'sample_net_kg'       => '',
        'company_name'        => '',
        'contact_person'      => '',
        'company_address'     => '',
        'status'              => 'taslak',
        'note'                => '',
        'sample_taken_at'     => '',
        'analysis_result_at'  => '',
        'analysis_note'       => '',
        'raw_text'            => $rawText,
        'unmatched_text'      => (string)($aiOut['unmatched_text'] ?? ''),
    ];

    $upper = static fn(string $s): string => function_exists('tr_upper') ? tr_upper($s) : mb_strtoupper($s, 'UTF-8');

    $copyUpper = [
        'party_no', 'buyer_name', 'exit_depot', 'transport_type', 'line_type',
        'product_name', 'product_variety', 'brand', 'crate_type', 'sample_crate_type',
        'company_name', 'contact_person', 'company_address', 'driver_name',
    ];
    foreach ($copyUpper as $k) {
        $v = $aiFields[$k] ?? null;
        if ($v !== null && trim((string)$v) !== '') {
            $form[$k] = $upper(trim((string)$v));
        }
    }

    foreach (['pallet_count', 'crate_count', 'gross_kg', 'net_kg', 'sample_crate_count', 'sample_gross_kg', 'sample_net_kg'] as $nk) {
        $v = $aiFields[$nk] ?? null;
        if ($v !== null && $v !== '') $form[$nk] = $v;
    }

    // Deterministik destek 1: DENİZYOLU(YEŞİLHAT) / KARAYOLU (KIRMIZI HAT) parantezli hat
    if ($form['line_type'] === '' && preg_match('/\b(DEN[İI]ZYOLU|KARAYOLU|HAVAYOLU|RO-RO)\s*\(\s*([^)\r\n]{2,40})\s*\)/ui', $rawText, $lm)) {
        if ($form['transport_type'] === '') $form['transport_type'] = $upper(trim($lm[1]));
        $hat = $upper(trim($lm[2]));
        $hat = (string)preg_replace('/^(YEŞİL|KIRMIZI|SARI|MAVİ)HAT$/u', '$1 HAT', $hat);
        $form['line_type'] = $hat;
        $uncertain = array_values(array_diff($uncertain, ['line_type']));
    }

    // Deterministik destek 2: KASA satırındaki adet (TAHTA KASA: 4.680 / PLASTİK: 2.704 KASA / KASA: 500)
    if ($form['crate_count'] === '' && preg_match_all('/^[^\r\n]*\bKASA\b[^\r\n]*$/umi', $rawText, $kasaSatirlari)) {
        $toplamKasa = 0;
        foreach ($kasaSatirlari[0] as $ks) {
            if (preg_match('/\bNUMUNE\b/ui', $ks)) continue;
            if (preg_match('/\b(\d{1,3}(?:\.\d{3})+|\d{1,6})\b/', $ks, $km)) {
                $n = (int)str_replace('.', '', $km[1]);
                if ($n > 0 && $n <= 1000000) $toplamKasa += $n;
            }
        }
        if ($toplamKasa > 0) {
            $form['crate_count'] = $toplamKasa;
            $uncertain = array_values(array_diff($uncertain, ['crate_count']));
        }
    }

    if (!empty($aiFields['tractor_plate'])) {
        $form['tractor_plate'] = function_exists('beyan_plaka_normalize')
            ? mb_substr(beyan_plaka_normalize((string)$aiFields['tractor_plate']), 0, 20, 'UTF-8')
            : mb_substr(preg_replace('/\s+/', '', strtoupper((string)$aiFields['tractor_plate'])), 0, 20, 'UTF-8');
    }
    if (!empty($aiFields['vehicle_plate'])) {
        $form['vehicle_plate'] = function_exists('beyan_plaka_normalize')
            ? beyan_plaka_normalize((string)$aiFields['vehicle_plate'])
            : preg_replace('/\s+/', '', strtoupper((string)$aiFields['vehicle_plate']));
    }
    if (!empty($aiFields['driver_phone'])) {
        $form['driver_phone'] = function_exists('beyan_telefon_normalize')
            ? beyan_telefon_normalize((string)$aiFields['driver_phone'])
            : trim((string)$aiFields['driver_phone']);
    }
    if (!empty($aiFields['note'])) {
        $form['note'] = mb_substr(trim((string)$aiFields['note']), 0, 2000, 'UTF-8');
    }

    $hksMatches = [
        'firma' => ['matched' => false, 'id' => null, 'ad' => null],
        'urun'  => ['matched' => false, 'id' => null, 'ad' => null],
        'ulke'  => ['matched' => false, 'id' => null, 'ad' => null, 'extracted' => $aiFields['country_name'] ?? null],
    ];

    // Gönderici Firma: mevcut HKS firmalarıyla eşleştir; alıcı veya yabancı gümrük firması yanlışlıkla geldiyse temizle
    if (!empty($aiFields['sender_company_name'])) {
        $rawSender = trim((string)$aiFields['sender_company_name']);
        if (function_exists('beyan_gonderici_coz')) {
            $g = beyan_gonderici_coz($rawSender);
            $form['sender_company_name'] = $g['ad'];
            $form['hks_firma_id'] = $g['id'];
            $hksMatches['firma'] = ['matched' => $g['id'] !== '', 'id' => $g['id'] ?: null, 'ad' => $g['ad'] ?: null];
        } else {
            $form['sender_company_name'] = mb_substr($rawSender, 0, 150, 'UTF-8');
        }
        $normSender = cw_bridge_norm_ascii_tr((string)$form['sender_company_name']);
        $normBuyer  = cw_bridge_norm_ascii_tr((string)$form['buyer_name']);
        $normComp   = cw_bridge_norm_ascii_tr((string)$form['company_name']);
        if (!$hksMatches['firma']['matched'] && $normSender !== '' && ($normSender === $normBuyer || $normSender === $normComp)) {
            $form['sender_company_name'] = '';
            $form['hks_firma_id'] = '';
        } elseif (!$hksMatches['firma']['matched']) {
            $uncertain[] = 'sender_company_name';
        }
    }

    // HKS Ürün: product_name üzerinden deterministik katalog eşleşmesi
    if ($form['product_name'] !== '' && !empty($schema['hks_urunler'])) {
        if (function_exists('beyan_hks_urun_otomatik')) {
            $t = beyan_hks_urun_otomatik($form['product_name'], $schema['hks_urunler']);
            if ($t['id'] !== '') {
                $form['hks_urun_id'] = (string)$t['id'];
                $form['hks_urun_ad'] = (string)$t['ad'];
                $hksMatches['urun'] = ['matched' => true, 'id' => (string)$t['id'], 'ad' => (string)$t['ad']];
            }
        }
    }

    // HKS Ülke: önce AI country_name, eşleşmezse metin/adres/alıcı/hat içinden şehir-ülke çıkarımı
    $cm = cw_bridge_match_hks_country(isset($aiFields['country_name']) ? (string)$aiFields['country_name'] : null, $schema['hks_ulkeler'] ?? []);
    if ($cm['id'] === null && !empty($schema['hks_ulkeler'])) {
        $inferred = cw_bridge_infer_country_from_text(
            implode("\n", [$form['company_address'], $form['buyer_name'], $form['line_type'], $rawText]),
            $schema['hks_ulkeler']
        );
        if ($inferred['id'] !== null) {
            $cm = ['id' => $inferred['id'], 'ad' => $inferred['ad']];
        }
    }
    if ($cm['id'] !== null) {
        $form['hks_ulke_id'] = $cm['id'];
        $form['hks_ulke_ad'] = (string)$cm['ad'];
        $hksMatches['ulke'] = ['matched' => true, 'id' => $cm['id'], 'ad' => $cm['ad'], 'extracted' => $aiFields['country_name'] ?? $cm['ad']];
        $uncertain = array_values(array_diff($uncertain, ['hks_ulke_id']));
    } elseif (!empty($aiFields['country_name'])) {
        $uncertain[] = 'hks_ulke_id';
    }

    // Mevcut beyan_veri_dogrula ile ön kontrol
    $valErrors = [];
    if (function_exists('beyan_veri_dogrula')) {
        $checked = beyan_veri_dogrula($form);
        $valErrors = $checked['errors'];
    }

    $missingReq = is_array($aiOut['missing_required'] ?? null) ? $aiOut['missing_required'] : [];
    if (($schema['permissions']['hks_read'] ?? true) && trim((string)$form['sender_company_name']) === '') {
        $missingReq[] = 'sender_company_name';
    }
    if (trim((string)$form['party_no']) === '' && trim((string)$form['product_name']) === '') {
        $missingReq[] = 'party_no_or_product_name';
    }

    return [
        'form_fields'       => $form,
        'hks_matches'       => $hksMatches,
        'validation_errors' => array_values(array_unique($valErrors)),
        'missing_required'  => array_values(array_unique(array_map('strval', $missingReq))),
        'uncertain_fields'  => array_values(array_unique(array_map('strval', $uncertain))),
        'conflicts'         => array_values(array_unique(array_map('strval', $conflicts))),
    ];
}

// ── Mesaj Aktarımı + AI Ayrıştırma Akışı ──────────────────

/**
 * WhatsApp mesajını alır, mükerrer kontrolü yapar, AI ile gerçek Yeni Beyan şemasına
 * eşleştirir, cw_bridge_messages tablosuna kaydeder ve inceleme çıktısını döndürür.
 * Beyan tablosuna HİÇBİR şey yazmaz!
 */
function cw_bridge_process_message(
    PDO $pdo,
    array $payload,
    array $authUser,
    ?int $tokenId = null,
    ?AIParserService $ai = null
): array {
    cw_bridge_ensure_schema($pdo);
    $userId = (int)($authUser['id'] ?? 0);
    $chatName = mb_substr(trim((string)($payload['chat_name'] ?? '')), 0, 180, 'UTF-8');
    $chatType = strtolower(trim((string)($payload['chat_type'] ?? 'unknown')));
    if (!in_array($chatType, ['group', 'direct', 'unknown'], true)) $chatType = 'unknown';
    $sourceMsgId = mb_substr(trim((string)($payload['source_message_id'] ?? '')), 0, 180, 'UTF-8');
    $senderName = mb_substr(trim((string)($payload['sender_name'] ?? '')), 0, 150, 'UTF-8');
    $msgTs = mb_substr(trim((string)($payload['message_timestamp'] ?? '')), 0, 64, 'UTF-8');
    $rawText = trim((string)($payload['raw_text'] ?? ''));
    $forceReparse = !empty($payload['force_reparse']);

    if ($chatName === '') return ['ok' => false, 'error' => 'Sohbet adı boş olamaz.', 'http_status' => 422];
    if ($rawText === '') return ['ok' => false, 'error' => 'Mesaj metni boş olamaz.', 'http_status' => 422];
    if (mb_strlen($rawText, 'UTF-8') > 10000) return ['ok' => false, 'error' => 'Mesaj metni çok uzun (en fazla 10.000 karakter).', 'http_status' => 413];

    $hashes = cw_bridge_message_hashes($chatName, $senderName, $msgTs, $rawText);
    $dup = cw_bridge_find_duplicate($pdo, $chatName, $sourceMsgId, $hashes['normalized_hash'], $hashes['text_hash']);
    if ($dup !== null && !$forceReparse) {
        $prevAi = json_decode((string)($dup['ai_result_json'] ?? ''), true);
        return [
            'ok'                => true,
            'duplicate'         => true,
            'bridge_message_id' => (int)$dup['id'],
            'existing_status'   => (string)$dup['status'],
            'declaration_id'    => $dup['declaration_id'] !== null ? (int)$dup['declaration_id'] : null,
            'message'           => $dup['declaration_id'] !== null
                ? 'Bu WhatsApp mesajı için daha önce #' . (int)$dup['declaration_id'] . ' numaralı beyan taslağı oluşturulmuş.'
                : 'Bu WhatsApp mesajı daha önce aktarılmış ve inceleme bekliyor.',
            'ai_result'         => is_array($prevAi) ? $prevAi : null,
        ];
    }

    $schema = cw_bridge_beyan_schema($pdo, true);
    $aiService = $ai ?? cw_bridge_resolve_ai_service($pdo);

    $aiContext = [
        'schema_version' => $schema['version'],
        'fields'         => $schema['fields'],
        'hks_companies'  => array_map(static fn($x) => (string)$x['ad'], $schema['hks_firmalar']),
        'hks_countries'  => array_map(static fn($x) => (string)$x['ad'], $schema['hks_ulkeler']),
        'chat_name'      => $chatName,
        'sender_name'    => $senderName,
    ];

    $now = cw_bridge_now();
    try {
        $aiOut = $aiService->parseBeyanBridge($rawText, $aiContext);
    } catch (AIParserException $e) {
        $st = $pdo->prepare('INSERT INTO cw_bridge_messages
            (user_id, token_id, chat_name, chat_type, source_message_id, sender_name, message_timestamp,
             raw_text, normalized_hash, text_hash, status, ai_provider, ai_model, error_message, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $userId, $tokenId, $chatName, $chatType, $sourceMsgId ?: null, $senderName ?: null, $msgTs ?: null,
            $rawText, $hashes['normalized_hash'], $hashes['text_hash'], 'error',
            $aiService->saglayici(), $aiService->model(), mb_substr($e->getMessage(), 0, 500, 'UTF-8'), $now, $now,
        ]);
        $httpCode = match ($e->tur) {
            'config' => 503,
            'quota'  => 429,
            'perm'   => 422,
            default  => 502,
        };
        return [
            'ok'                => false,
            'error'             => $e->getMessage(),
            'error_type'        => $e->tur,
            'bridge_message_id' => (int)$pdo->lastInsertId(),
            'http_status'       => $httpCode,
        ];
    }

    $validated = cw_bridge_validate_and_match_ai($pdo, $aiOut, $schema, $rawText);
    $refKey = trim((string)($validated['form_fields']['party_no'] ?? '')) ?: null;

    $storedPayload = [
        'schema_version'    => $schema['version'],
        'is_beyan'          => $aiOut['is_beyan'],
        'confidence'        => $aiOut['confidence'],
        'field_confidence'  => $aiOut['field_confidence'],
        'explanation'       => $aiOut['explanation'],
        'form_fields'       => $validated['form_fields'],
        'hks_matches'       => $validated['hks_matches'],
        'validation_errors' => $validated['validation_errors'],
        'missing_required'  => $validated['missing_required'],
        'uncertain_fields'  => $validated['uncertain_fields'],
        'conflicts'         => $validated['conflicts'],
    ];

    $status = $aiOut['is_beyan'] ? 'parsed' : 'rejected';
    $st = $pdo->prepare('INSERT INTO cw_bridge_messages
        (user_id, token_id, chat_name, chat_type, source_message_id, sender_name, message_timestamp,
         raw_text, normalized_hash, text_hash, reference_key, status, ai_provider, ai_model,
         ai_confidence, ai_result_json, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        $userId, $tokenId, $chatName, $chatType, $sourceMsgId ?: null, $senderName ?: null, $msgTs ?: null,
        $rawText, $hashes['normalized_hash'], $hashes['text_hash'], $refKey, $status,
        $aiService->saglayici(), $aiService->model(), (int)$aiOut['confidence'],
        json_encode($storedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $now, $now,
    ]);
    $bridgeId = (int)$pdo->lastInsertId();

    $refWarnings = cw_bridge_find_reference_warnings($pdo, $refKey, $bridgeId);

    return [
        'ok'                           => true,
        'duplicate'                    => false,
        'bridge_message_id'            => $bridgeId,
        'ai_provider'                  => $aiService->saglayici(),
        'ai_model'                     => $aiService->model(),
        'ai_result'                    => $storedPayload,
        'duplicate_reference_warnings' => $refWarnings,
        'source'                       => [
            'chat_name'         => $chatName,
            'chat_type'         => $chatType,
            'sender_name'       => $senderName,
            'message_timestamp' => $msgTs,
            'source_message_id' => $sourceMsgId,
            'raw_text'          => $rawText,
        ],
    ];
}

// ── Kullanıcı Onayı Sonrası Mevcut Beyan Altyapısıyla Taslak Kaydı ──

/**
 * Kullanıcı onayı (confirm=true) sonrası mevcut Yeni Beyan validasyonları ve yetki
 * kurallarıyla customs_declarations tablosuna taslak kaydeder.
 * Resmî HKS bildirimi veya kesinleştirme YAPMAZ.
 *
 * @return array{ok:bool, declaration_id?:int, view_url?:string, status?:string, errors?:list<string>, http_status?:int}
 */
function cw_bridge_create_beyan_draft(
    PDO $pdo,
    array $inputFields,
    array $authUser,
    ?int $bridgeMessageId = null,
    bool $confirmed = false
): array {
    if (!$confirmed) {
        return ['ok' => false, 'errors' => ['Kullanıcı onayı olmadan beyan taslağı oluşturulamaz.'], 'http_status' => 422];
    }
    if (function_exists('can_beyan') && !can_beyan('create')) {
        return ['ok' => false, 'errors' => ['Yeni beyan oluşturma yetkiniz yok.'], 'http_status' => 403];
    }

    cw_bridge_ensure_schema($pdo);
    $userId = (int)($authUser['id'] ?? 0);

    // Köprü mesajı verilmişse mükerrer taslak oluşumunu engelle ve orijinal raw_text'i koru
    $bridgeRow = null;
    if ($bridgeMessageId !== null && $bridgeMessageId > 0) {
        $bst = $pdo->prepare('SELECT * FROM cw_bridge_messages WHERE id = ? LIMIT 1');
        $bst->execute([$bridgeMessageId]);
        $bridgeRow = $bst->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$bridgeRow) {
            return ['ok' => false, 'errors' => ['İlgili WhatsApp kaynak mesajı bulunamadı.'], 'http_status' => 404];
        }
        if ($bridgeRow['declaration_id'] !== null) {
            return [
                'ok'             => false,
                'errors'         => ['Bu mesaj için zaten #' . (int)$bridgeRow['declaration_id'] . ' numaralı beyan taslağı oluşturulmuş.'],
                'declaration_id' => (int)$bridgeRow['declaration_id'],
                'http_status'    => 409,
            ];
        }
        if (trim((string)($inputFields['raw_text'] ?? '')) === '') {
            $inputFields['raw_text'] = (string)$bridgeRow['raw_text'];
        }
    }

    // Varsayılan durum her zaman 'taslak' (kullanıcı yetkiliyse başka geçerli açık durum seçebilir)
    if (!isset($inputFields['status']) || trim((string)$inputFields['status']) === '') {
        $inputFields['status'] = 'taslak';
    }

    // Mevcut beyan_create.php alan listesi (birebir)
    $f = [
        'raw_text'            => '',
        'unmatched_text'      => '',
        'declaration_title'   => '',
        'company_name'        => '',
        'company_address'     => '',
        'transport_type'      => '',
        'vehicle_plate'       => '',
        'tractor_plate'       => '',
        'driver_name'         => '',
        'driver_phone'        => '',
        'sender_company_name' => '',
        'hks_firma_id'        => '',
        'hks_urun_id'         => '',
        'hks_ulke_id'         => '',
        'line_type'           => '',
        'party_no'            => '',
        'pallet_count'        => '',
        'product_name'        => '',
        'product_variety'     => '',
        'gross_kg'            => '',
        'net_kg'              => '',
        'crate_count'         => '',
        'crate_type'          => '',
        'exit_depot'          => '',
        'contact_person'      => '',
        'buyer_name'          => '',
        'brand'               => '',
        'status'              => 'taslak',
        'sample_taken_at'     => '',
        'analysis_result_at'  => '',
        'analysis_note'       => '',
        'note'                => '',
        'sample_gross_kg'     => '',
        'sample_net_kg'       => '',
        'sample_crate_type'   => '',
        'sample_crate_count'  => '',
    ];

    $post = function_exists('beyan_protect_input') ? beyan_protect_input($inputFields) : $inputFields;
    // Yetkisiz status değişikliğinde bile bridge varsayılanı taslak kalsın
    if (function_exists('can_beyan') && !can_beyan('status')) {
        $post['status'] = 'taslak';
    }

    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)($post[$k] ?? ''));
    }

    $upper = static fn(string $s): string => function_exists('tr_upper') ? tr_upper($s) : mb_strtoupper($s, 'UTF-8');
    foreach ([
        'declaration_title', 'company_name', 'company_address', 'transport_type', 'vehicle_plate',
        'line_type', 'party_no', 'product_name', 'product_variety', 'crate_type', 'sample_crate_type',
        'exit_depot', 'contact_person', 'buyer_name', 'brand', 'driver_name',
    ] as $_tf) {
        if ($f[$_tf] !== '') $f[$_tf] = $upper($f[$_tf]);
    }
    if (function_exists('beyan_suggest_fields') && function_exists('beyan_suggest_normalize')) {
        foreach (beyan_suggest_fields() as $_tf => $_meta) {
            $f[$_tf] = beyan_suggest_normalize($_tf, $f[$_tf]);
        }
    }
    if (function_exists('beyan_telefon_normalize')) {
        $f['driver_phone'] = beyan_telefon_normalize($f['driver_phone']);
    }

    // Mevcut beyan_veri_dogrula validasyonu
    $checked = beyan_veri_dogrula($f);
    $errors = $checked['errors'];
    if (mb_strlen($f['note'], 'UTF-8') > 2000) $errors[] = 'Not: en fazla 2000 karakter olabilir.';

    // Kapalı durum koruması (beyan_create.php ile birebir)
    if (function_exists('can_beyan_kart') && function_exists('beyan_kart_of')) {
        if (!can_beyan_kart((string)$f['status'], 'edit') && beyan_kart_of((string)$f['status']) === 'kap') {
            $errors[] = 'Kapanan Beyanlar bölümünde düzenleme yetkiniz olmadığı için kapalı durumla beyan açılamaz.';
        }
    }

    // Gönderici Firma zorunluluğu ve HKS firma çözümü (beyan_create.php ile birebir)
    $canHksRead = function_exists('can_beyan') ? can_beyan('hks.read') : true;
    if ($canHksRead && trim((string)$f['sender_company_name']) === '') {
        $errors[] = 'Gönderici Firma seçilmelidir.';
    }
    if (function_exists('beyan_gonderici_coz')) {
        $g = beyan_gonderici_coz($f['sender_company_name']);
        $post['hks_firma_id'] = $g['id'];
        $f['sender_company_name'] = $g['ad'];
    }

    // HKS Ürün ve Ülke doğrulaması (katalog dışı uydurma ID'leri reddeder!)
    $hks = ['firma_id' => null, 'urun_id' => null, 'urun_ad' => null, 'ulke_id' => null, 'ulke_ad' => null];
    if (function_exists('beyan_hks_urun_post_coz') && function_exists('beyan_hks_form_oku')) {
        $post['hks_urun_alan'] = '1';
        [$post, $_elle] = beyan_hks_urun_post_coz($post, $f['product_name']);
        try {
            $hks = beyan_hks_form_oku($post);
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    } elseif (function_exists('beyan_hks_secimleri_coz')) {
        try {
            $hks = beyan_hks_secimleri_coz($post, [], cw_bridge_hks_katalog());
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if ($errors) {
        return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'http_status' => 422];
    }

    // Plaka normalize (beyan_create.php ile birebir)
    if (function_exists('beyan_plaka_normalize')) {
        $f['vehicle_plate'] = beyan_plaka_normalize($f['vehicle_plate']);
        $f['tractor_plate'] = mb_substr(beyan_plaka_normalize($f['tractor_plate']), 0, 20, 'UTF-8');
    }

    $grossKg   = $checked['values']['gross_kg'];
    $netKg     = $checked['values']['net_kg'];
    $palletCt  = $checked['values']['pallet_count'];
    $crateCt   = $checked['values']['crate_count'];
    $sampleAt  = $checked['values']['sample_taken_at'];
    $resultAt  = $checked['values']['analysis_result_at'];
    if (in_array($f['status'], ['temiz', 'red'], true) && $resultAt === null) {
        $resultAt = cw_bridge_now();
    }

    $nowSql = cw_bridge_is_sqlite($pdo) ? 'CURRENT_TIMESTAMP' : 'NOW()';

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO customs_declarations
            (raw_text, unmatched_text, declaration_title, company_name, company_address,
             transport_type, vehicle_plate, line_type, party_no, pallet_count, product_name, product_variety,
             gross_kg, net_kg, crate_count, crate_type, exit_depot, contact_person,
             buyer_name, brand, status, analysis_note, sample_taken_at, analysis_result_at,
             hks_firma_id, hks_urun_id, hks_urun_ad, hks_ulke_id, hks_ulke_ad, note,
             sample_gross_kg, sample_net_kg, sample_crate_type, sample_crate_count,
             tractor_plate, sender_company_name, driver_name, driver_phone,
             created_by, updated_by, created_at, updated_at)
            VALUES
            (?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, {$nowSql}, {$nowSql})");

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
            $palletCt,
            $f['product_name']      ?: null,
            $f['product_variety']   ?: null,
            $grossKg,
            $netKg,
            $crateCt,
            $f['crate_type']        ?: null,
            $f['exit_depot']        ?: null,
            $f['contact_person']    ?: null,
            $f['buyer_name']        ?: null,
            $f['brand']             ?: null,
            $f['status'],
            $f['analysis_note']     ?: null,
            $sampleAt,
            $resultAt,
            $hks['firma_id'],
            $hks['urun_id'],
            $hks['urun_ad'],
            $hks['ulke_id'],
            $hks['ulke_ad'],
            $f['note'] ?: null,
            $checked['values']['sample_gross_kg'] ?? null,
            $checked['values']['sample_net_kg'] ?? null,
            $f['sample_crate_type'] ?: null,
            $checked['values']['sample_crate_count'] ?? null,
            $f['tractor_plate'] ?: null,
            $f['sender_company_name'] ?: null,
            $f['driver_name'] ?: null,
            $f['driver_phone'] ?: null,
            $userId,
            $userId,
        ]);

        $newId = (int)$pdo->lastInsertId();

        if ($bridgeRow !== null) {
            $pdo->prepare('UPDATE cw_bridge_messages SET declaration_id = ?, status = ?, reference_key = COALESCE(?, reference_key), updated_at = ? WHERE id = ?')
                ->execute([$newId, 'draft_created', $f['party_no'] ?: null, cw_bridge_now(), (int)$bridgeRow['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Beyan taslağı kaydedilemedi.'], 'http_status' => 500];
    }

    if (function_exists('audit_log_event')) {
        try {
            audit_log_event('beyan_create', 'declarations', $newId, null, [
                'party_no'          => $f['party_no'],
                'product_name'      => $f['product_name'],
                'status'            => $f['status'],
                'source'            => 'chrome_whatsapp_bridge',
                'bridge_message_id' => $bridgeMessageId,
            ]);
        } catch (Throwable $e) {}
    }

    return [
        'ok'             => true,
        'declaration_id' => $newId,
        'status'         => $f['status'],
        'view_url'       => 'beyan_view.php?id=' . $newId,
    ];
}

// ── Chrome Eklentisi Tek-Tık ZIP Paketi (saf PHP, ext-zip gerektirmez) ──

/**
 * chrome-extension/asya-whatsapp-bridge klasörünü tek-tık indirilebilir
 * asya-whatsapp-bridge.zip (PKZIP store biçimi) olarak bellek içinde üretir.
 */
function cw_bridge_extension_zip(string $extDir): string
{
    $allowedFiles = [
        'manifest.json',
        'service-worker.js',
        'content.js',
        'popup.html',
        'popup.css',
        'popup.js',
        'README.md',
    ];
    $dir = rtrim($extDir, '/\\');
    $entries = [];
    foreach ($allowedFiles as $name) {
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            throw new RuntimeException('Chrome eklenti dosyası bulunamadı: ' . $name);
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Chrome eklenti dosyası okunamadı: ' . $name);
        }
        $entries['asya-whatsapp-bridge/' . $name] = $data;
    }

    $localData = '';
    $centralDir = '';
    $offset = 0;
    $count = 0;

    foreach ($entries as $zipName => $data) {
        $crc  = crc32($data);
        $size = strlen($data);
        $nameLen = strlen($zipName);

        // Local file header (30 bytes + filename + data)
        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50, // signature
            20,         // version needed
            0x0800,     // UTF-8 flag
            0,          // method: store
            0,          // mod time
            0,          // mod date
            $crc,
            $size,
            $size,
            $nameLen,
            0           // extra len
        );
        $localData .= $localHeader . $zipName . $data;

        // Central directory header (46 bytes + filename)
        $centralDir .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50, // signature
            20,         // version made by
            20,         // version needed
            0x0800,     // UTF-8 flag
            0,          // method: store
            0,          // mod time
            0,          // mod date
            $crc,
            $size,
            $size,
            $nameLen,
            0,          // extra len
            0,          // comment len
            0,          // disk start
            0,          // internal attrs
            0,          // external attrs
            $offset
        ) . $zipName;

        $offset += strlen($localHeader) + $nameLen + $size;
        $count++;
    }

    $eocd = pack(
        'VvvvvVVv',
        0x06054b50, // EOCD signature
        0,          // disk number
        0,          // disk with CD
        $count,
        $count,
        strlen($centralDir),
        $offset,
        0           // comment len
    );

    return $localData . $centralDir . $eocd;
}

