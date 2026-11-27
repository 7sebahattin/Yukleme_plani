<?php
// =========================================================
// scripts/chrome_whatsapp_bridge_smoke.php
//
// ASYA WHATSAPP WEB BRIDGE (Aşama 1) — İzole PHP Smoke Testi
// Bellek içi SQLite + mock AI HTTP transport kullanır; canlı DB'ye ve ağa dokunmaz.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config/beyan_permissions.php';

// helpers.php üst düzeyde auto-migration tetikleyebildiğinden yalnız ilgili gerçek
// beyan yardımcı fonksiyonlarını tokenizer ile izole yükle (beyan_data_smoke.php deseni).
$wanted = array_flip([
    'tr_upper',
    'hks_eslesme_norm',
    'beyan_statuses',
    'beyan_durum_akis_sirasi',
    'beyan_kapali_durumlar',
    'beyan_suggest_fields',
    'beyan_suggest_normalize',
    'beyan_num_or_null',
    'beyan_int_or_null',
    'beyan_veri_dogrula',
    'beyan_plaka_normalize',
    'beyan_telefon_normalize',
    'beyan_gonderici_coz',
    'beyan_hks_secimleri_coz',
    'beyan_hks_form_oku',
    'beyan_hks_urun_otomatik',
    'beyan_hks_urun_post_coz',
    'bb_tahmin',
]);

$tokens = token_get_all((string)file_get_contents($root . '/config/helpers.php'));
for ($i = 0, $n = count($tokens); $i < $n; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $j = $i + 1;
    while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
    if (!isset($tokens[$j]) || !is_array($tokens[$j]) || !isset($wanted[$tokens[$j][1]])) continue;
    $code = '';
    $depth = 0;
    $started = false;
    for (; $i < $n; $i++) {
        $tok = $tokens[$i];
        $code .= is_array($tok) ? $tok[1] : $tok;
        if ($tok === '{' || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            $started = true;
        }
        if ($tok === '}' && --$depth === 0 && $started) break;
    }
    eval($code);
}

// İzole DB + yetki + katalog stubları (canlı DB YOK)
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

function db(): PDO
{
    global $pdo;
    return $pdo;
}

$GLOBALS['_test_perms'] = [
    'create' => true, 'read' => true, 'status' => true,
    'contact.read' => true, 'contact.write' => true,
    'analysis.read' => true, 'analysis.write' => true,
    'source.read' => true, 'source.write' => true,
    'hks.read' => true, 'hks.write' => true,
];
$GLOBALS['_test_kap_edit'] = false;
$GLOBALS['_test_audit_events'] = [];

function can_beyan(string $perm): bool
{
    return (bool)($GLOBALS['_test_perms'][$perm] ?? false);
}
function beyan_kart_of(string $status): string
{
    return in_array($status, beyan_kapali_durumlar(), true) ? 'kap' : 'acik';
}
function can_beyan_kart(string $status, string $action): bool
{
    if (beyan_kart_of($status) === 'kap') return (bool)$GLOBALS['_test_kap_edit'];
    return true;
}
function bb_katalog(): array
{
    return [
        'urunler' => [
            ['id' => '101', 'ad' => 'BİBER (KAPYA)'],
            ['id' => '102', 'ad' => 'DOMATES'],
        ],
        'ulkeler' => [
            ['id' => '643', 'ad' => 'RUSYA'],
            ['id' => '276', 'ad' => 'ALMANYA'],
        ],
    ];
}
function hks_eslesme_bul(string $tip, string $ad): ?array { return null; }
function audit_log_event(string $action, string $entity, int $id, $old = null, $new = null): void
{
    $GLOBALS['_test_audit_events'][] = compact('action', 'entity', 'id', 'new');
}

// Test tabloları (users, hks_firmalar, customs_declarations)
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, display_name TEXT, is_active INTEGER)');
$pdo->exec("INSERT INTO users VALUES (1, 'op1', 'op1@example.test', 'Operatör Bir', 1)");
$pdo->exec("INSERT INTO users VALUES (2, 'pasif', 'p@example.test', 'Pasif Kullanıcı', 0)");

$pdo->exec('CREATE TABLE hks_firmalar (id TEXT PRIMARY KEY, ad TEXT)');
$pdo->exec("INSERT INTO hks_firmalar VALUES ('F1', 'TEST İHRACAT A.Ş.')");

$pdo->exec('CREATE TABLE customs_declarations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_text TEXT, unmatched_text TEXT, declaration_title TEXT, company_name TEXT, company_address TEXT,
    transport_type TEXT, vehicle_plate TEXT, line_type TEXT, party_no TEXT, pallet_count INTEGER,
    product_name TEXT, product_variety TEXT, gross_kg NUMERIC, net_kg NUMERIC, crate_count INTEGER,
    crate_type TEXT, exit_depot TEXT, contact_person TEXT, buyer_name TEXT, brand TEXT, status TEXT,
    analysis_note TEXT, sample_taken_at TEXT, analysis_result_at TEXT,
    hks_firma_id TEXT, hks_urun_id TEXT, hks_urun_ad TEXT, hks_ulke_id TEXT, hks_ulke_ad TEXT, note TEXT,
    sample_gross_kg NUMERIC, sample_net_kg NUMERIC, sample_crate_type TEXT, sample_crate_count INTEGER,
    tractor_plate TEXT, sender_company_name TEXT, driver_name TEXT, driver_phone TEXT,
    created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT
)');

require_once $root . '/config/chrome_whatsapp_bridge.php';

$rlTmp = sys_get_temp_dir() . '/cw_bridge_rl_test_' . getmypid();
@mkdir($rlTmp, 0700, true);
$GLOBALS['_cw_bridge_rl_dir'] = $rlTmp;
register_shutdown_function(static function () use ($rlTmp): void {
    foreach (glob($rlTmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($rlTmp);
});

$tests = 0;
$fails = 0;
function ok(string $label, bool $cond): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) {
        $fails++;
        echo "FAIL: {$label}\n";
    }
}

// ── 1. Gerçek Yeni Beyan Form Şeması ──────────────────────
$schema = cw_bridge_beyan_schema($pdo, true);
$fieldNames = array_column($schema['fields'], 'name');
ok('şema versiyonu tanımlı', $schema['version'] === CW_BRIDGE_SCHEMA_VERSION);
foreach ([
    'party_no', 'buyer_name', 'exit_depot', 'transport_type', 'line_type',
    'sender_company_name', 'hks_ulke_id', 'tractor_plate', 'vehicle_plate',
    'driver_name', 'driver_phone', 'product_name', 'hks_urun_id',
    'product_variety', 'brand', 'pallet_count', 'crate_count', 'crate_type',
    'gross_kg', 'net_kg', 'sample_crate_count', 'sample_crate_type',
    'sample_gross_kg', 'sample_net_kg', 'company_name', 'contact_person',
    'company_address', 'status', 'note', 'sample_taken_at',
    'analysis_result_at', 'analysis_note', 'raw_text', 'unmatched_text',
] as $expectedField) {
    ok("şema gerçek Yeni Beyan alanını içerir: {$expectedField}", in_array($expectedField, $fieldNames, true));
}
ok('şema HKS firmalarını okur', count($schema['hks_firmalar']) === 1 && $schema['hks_firmalar'][0]['id'] === 'F1');
ok('şema HKS ülke kataloğunu okur', count($schema['hks_ulkeler']) === 2);
ok('şema varsayılan durum taslak', current(array_filter($schema['fields'], fn($f) => $f['name'] === 'status'))['default'] === 'taslak');

// ── 2. AI JSON Şeması, Prompt, Sanitizer ve Mock Yanıt ────
$openAiSchema = AIParserService::beyanBridgeSemaOpenAi();
$geminiSchema = AIParserService::beyanBridgeSemaGemini();
ok('OpenAI şeması strict uyumlu (additionalProperties=false)', $openAiSchema['additionalProperties'] === false && $openAiSchema['properties']['fields']['additionalProperties'] === false);
ok('Gemini şeması OBJECT ve zorunlu alanlı', $geminiSchema['type'] === 'OBJECT' && in_array('fields', $geminiSchema['required'], true));

$prompt = AIParserService::beyanBridgeSistemTalimati([
    'schema_version' => $schema['version'],
    'fields'         => $schema['fields'],
    'hks_companies'  => ['TEST İHRACAT A.Ş.'],
    'hks_countries'  => ['RUSYA', 'ALMANYA'],
]);
ok('prompt gerçek alan adlarını içerir', str_contains($prompt, 'sender_company_name') && str_contains($prompt, 'gross_kg'));
ok('prompt uydurma yasağını içerir', str_contains($prompt, 'uydurma') && str_contains($prompt, 'conflicts'));

// Mock AI (1. çağrı geçici 503 → 2. çağrı başarılı JSON — retry testi)
$calls = 0;
$mockPayload = [
    'is_beyan' => true,
    'fields' => [
        'party_no' => '901-A',
        'buyer_name' => 'MOCK ALICI',
        'exit_depot' => 'MOCK DEPO',
        'transport_type' => 'DENİZYOLU',
        'line_type' => 'MOCK HAT',
        'sender_company_name' => 'TEST İHRACAT A.Ş.',
        'country_name' => 'RUSYA',
        'tractor_plate' => '34 abc 123',
        'vehicle_plate' => '34 ddd 456',
        'driver_name' => 'Test Şoför',
        'driver_phone' => '+90 555 111 22 33',
        'product_name' => 'DOMATES',
        'product_variety' => 'Salkım',
        'brand' => 'MOCKMARKA',
        'pallet_count' => 12,
        'crate_count' => 1200,
        'crate_type' => 'PLASTİK',
        'gross_kg' => '6.400',
        'net_kg' => '5.800',
        'sample_crate_count' => null,
        'sample_crate_type' => null,
        'sample_gross_kg' => null,
        'sample_net_kg' => null,
        'company_name' => null,
        'contact_person' => null,
        'company_address' => null,
        'note' => 'Sentetik birim test verisi',
    ],
    'confidence' => 88,
    'field_confidence' => [
        ['field' => 'party_no', 'level' => 'high'],
        ['field' => 'brand', 'level' => 'low'],
    ],
    'uncertain_fields' => ['brand'],
    'conflicts' => [],
    'missing_required' => [],
    'explanation' => 'Sentetik test eşleştirmesi.',
    'unmatched_text' => 'Ek kontrol satırı',
];

$mockAi = new AIParserService('groq', 'test-key', 'openai/gpt-oss-120b', function () use (&$calls, $mockPayload): array {
    $calls++;
    if ($calls === 1) {
        return ['status' => 503, 'body' => '{"error":{"message":"transient"}}'];
    }
    return [
        'status' => 200,
        'body' => json_encode(['choices' => [['message' => ['content' => json_encode($mockPayload, JSON_UNESCAPED_UNICODE)]]]]),
    ];
});

$parsedAi = $mockAi->parseBeyanBridge('Sentetik test metni', ['schema_version' => $schema['version'], 'fields' => $schema['fields']]);
ok('geçici 503 hatasında 1 kez tekrar dener', $calls === 2);
ok('parseBeyanBridge is_beyan=true döner', $parsedAi['is_beyan'] === true);
ok('Türkçe binlik sayı doğru çevrilir (6.400 -> 6400)', $parsedAi['fields']['gross_kg'] === 6400.0 && $parsedAi['fields']['net_kg'] === 5800.0);
ok('düşük güvenli alan uncertain_fields içine alınır', in_array('brand', $parsedAi['uncertain_fields'], true));

// Çelişki ve beyan-olmayan mesaj sanitizer testleri
$conflictSan = AIParserService::sanitizeBeyanBridge([
    'is_beyan' => true,
    'fields' => ['gross_kg' => 1000, 'net_kg' => 1500, 'party_no' => 'X1'],
    'confidence' => 50,
    'field_confidence' => [],
    'uncertain_fields' => [],
    'conflicts' => [],
    'missing_required' => [],
    'explanation' => '',
    'unmatched_text' => null,
]);
ok('net_kg > gross_kg çelişkisi gizlenmez', count($conflictSan['conflicts']) >= 1 && in_array('net_kg', $conflictSan['uncertain_fields'], true));

$nonBeyanSan = AIParserService::sanitizeBeyanBridge([
    'is_beyan' => false,
    'fields' => ['party_no' => '999', 'product_name' => 'DOMATES'],
    'confidence' => 80,
]);
ok('is_beyan=false ise tüm alanlar null ve confidence=0', $nonBeyanSan['fields']['party_no'] === null && $nonBeyanSan['confidence'] === 0);

// ── 3. Eşleştirme Kodu (Tek Kullanımlık / Süreli) + Token Hash & İptal ──
$pair = cw_bridge_create_pairing_code($pdo, 1, 600);
ok('eşleştirme kodu üretilir', str_starts_with($pair['code'], 'ASYA-'));
$storedCode = (string)$pdo->query('SELECT code_hash FROM cw_bridge_pairings ORDER BY id DESC LIMIT 1')->fetchColumn();
ok('eşleştirme kodu DB\'de düz metin değil hash saklanır', $storedCode !== $pair['code'] && strlen($storedCode) === 64);

$ex1 = cw_bridge_exchange_pairing_code($pdo, $pair['code'], 'Test Chrome');
ok('eşleştirme kodu token üretir', $ex1['ok'] === true && str_starts_with((string)$ex1['token'], 'cwb_'));
$storedTok = (string)$pdo->query('SELECT token_hash FROM cw_bridge_tokens ORDER BY id DESC LIMIT 1')->fetchColumn();
ok('token DB\'de hash olarak saklanır', $storedTok !== $ex1['token'] && strlen($storedTok) === 64);

$exReuse = cw_bridge_exchange_pairing_code($pdo, $pair['code'], 'Tekrar');
ok('tek kullanımlık kod ikinci kez reddedilir', $exReuse['ok'] === false);

// Süresi dolmuş kod reddedilir
$pairExp = cw_bridge_create_pairing_code($pdo, 1, 600);
$pdo->exec("UPDATE cw_bridge_pairings SET expires_at = '2020-01-01 00:00:00' WHERE code_hash = " . $pdo->quote(cw_bridge_hash_secret($pairExp['code'])));
ok('süresi dolmuş kod reddedilir', cw_bridge_exchange_pairing_code($pdo, $pairExp['code'])['ok'] === false);

// Pasif kullanıcı kodu reddedilir
$pairPassive = cw_bridge_create_pairing_code($pdo, 2, 600);
ok('pasif kullanıcı eşleştirmesi reddedilir', cw_bridge_exchange_pairing_code($pdo, $pairPassive['code'])['ok'] === false);

// Token doğrulama ve iptal
$authOk = cw_bridge_authenticate_token($pdo, (string)$ex1['token']);
ok('geçerli token kullanıcıyı doğrular', $authOk !== null && $authOk['user']['id'] === 1);
ok('token iptal edilir', cw_bridge_revoke_token($pdo, (int)$ex1['token_id'], 1) === true);
ok('iptal edilen token reddedilir', cw_bridge_authenticate_token($pdo, (string)$ex1['token']) === null);

// ── 4. Mesaj İşleme, Mükerrer Kontrolü, Katalog Eşleştirme ─
$mockAiOk = new AIParserService('groq', 'test-key', 'openai/gpt-oss-120b', static function () use ($mockPayload): array {
    return [
        'status' => 200,
        'body' => json_encode(['choices' => [['message' => ['content' => json_encode($mockPayload, JSON_UNESCAPED_UNICODE)]]]]),
    ];
});

$proc1 = cw_bridge_process_message($pdo, [
    'chat_name' => 'Test Grup',
    'chat_type' => 'group',
    'source_message_id' => 'msg_001',
    'sender_name' => 'Kullanıcı A',
    'message_timestamp' => '10:00, 27.11.2026',
    'raw_text' => 'Sentetik beyan mesajı 901-A',
], ['id' => 1], 1, $mockAiOk);

ok('mesaj AI ile işlenir (henüz beyan tablosuna yazmaz)', $proc1['ok'] === true && $proc1['duplicate'] === false && (int)$pdo->query('SELECT COUNT(*) FROM customs_declarations')->fetchColumn() === 0);
ok('HKS firma, ürün ve ülke katalogdan çözülür',
    $proc1['ai_result']['form_fields']['hks_firma_id'] === 'F1'
    && $proc1['ai_result']['form_fields']['hks_urun_id'] === '102'
    && $proc1['ai_result']['form_fields']['hks_ulke_id'] === '643');

// Aynı mesaj tekrar gelirse mükerrer yakalanır
$procDup = cw_bridge_process_message($pdo, [
    'chat_name' => 'Test Grup',
    'chat_type' => 'group',
    'source_message_id' => 'msg_001',
    'sender_name' => 'Kullanıcı A',
    'message_timestamp' => '10:00, 27.11.2026',
    'raw_text' => 'Sentetik beyan mesajı 901-A',
], ['id' => 1], 1, $mockAiOk);
ok('aynı mesaj tekrar tarandığında mükerrer olarak yakalanır', $procDup['ok'] === true && $procDup['duplicate'] === true);

// ── 5. Onay, Validasyon, Geçersiz Seçenekler, Yetki ve DB Kaydı ─
$noConfirm = cw_bridge_create_beyan_draft($pdo, $proc1['ai_result']['form_fields'], ['id' => 1], $proc1['bridge_message_id'], false);
ok('kullanıcı onayı olmadan kayıt reddedilir', $noConfirm['ok'] === false && $noConfirm['http_status'] === 422);

$badStatus = cw_bridge_create_beyan_draft($pdo, array_merge($proc1['ai_result']['form_fields'], ['status' => 'uydurma_durum']), ['id' => 1], $proc1['bridge_message_id'], true);
ok('geçersiz durum seçeneği reddedilir', $badStatus['ok'] === false);

$closedNoPerm = cw_bridge_create_beyan_draft($pdo, array_merge($proc1['ai_result']['form_fields'], ['status' => 'yuklendi']), ['id' => 1], $proc1['bridge_message_id'], true);
ok('yetkisiz kapalı durum reddedilir', $closedNoPerm['ok'] === false);

$badHksCountry = cw_bridge_create_beyan_draft($pdo, array_merge($proc1['ai_result']['form_fields'], ['hks_ulke_id' => '999999']), ['id' => 1], $proc1['bridge_message_id'], true);
ok('katalog dışı HKS ülke ID reddedilir', $badHksCountry['ok'] === false);

$missingSender = cw_bridge_create_beyan_draft($pdo, array_merge($proc1['ai_result']['form_fields'], ['sender_company_name' => '']), ['id' => 1], $proc1['bridge_message_id'], true);
ok('zorunlu Gönderici Firma boşsa reddedilir', $missingSender['ok'] === false);

$netGtGross = cw_bridge_create_beyan_draft($pdo, array_merge($proc1['ai_result']['form_fields'], ['gross_kg' => '100', 'net_kg' => '200']), ['id' => 1], $proc1['bridge_message_id'], true);
ok('net KG > brüt KG reddedilir', $netGtGross['ok'] === false);

// Yetkisiz kullanıcı (beyan.create = false) reddedilir
$GLOBALS['_test_perms']['create'] = false;
$noPerm = cw_bridge_create_beyan_draft($pdo, $proc1['ai_result']['form_fields'], ['id' => 1], $proc1['bridge_message_id'], true);
ok('beyan.create yetkisi yoksa 403 döner', $noPerm['ok'] === false && $noPerm['http_status'] === 403);
$GLOBALS['_test_perms']['create'] = true;

// Geçerli onaylı taslak kaydı
$saved = cw_bridge_create_beyan_draft($pdo, $proc1['ai_result']['form_fields'], ['id' => 1], $proc1['bridge_message_id'], true);
ok('onay sonrası beyan taslağı customs_declarations tablosuna kaydedilir', $saved['ok'] === true && $saved['declaration_id'] > 0 && $saved['status'] === 'taslak');

$declRow = $pdo->query('SELECT * FROM customs_declarations WHERE id = ' . (int)$saved['declaration_id'])->fetch();
ok('kayıtta orijinal raw_text, plaka normalize ve HKS eşleşmeleri korunur',
    $declRow['raw_text'] === 'Sentetik beyan mesajı 901-A'
    && $declRow['tractor_plate'] === '34ABC123'
    && $declRow['vehicle_plate'] === '34DDD456'
    && $declRow['hks_firma_id'] === 'F1'
    && $declRow['hks_ulke_id'] === '643'
    && $declRow['status'] === 'taslak');

$msgRow = $pdo->query('SELECT status, declaration_id FROM cw_bridge_messages WHERE id = ' . (int)$proc1['bridge_message_id'])->fetch();
ok('cw_bridge_messages kaydı oluşturulan beyan ID ile bağlanır', $msgRow['status'] === 'draft_created' && (int)$msgRow['declaration_id'] === (int)$saved['declaration_id']);
ok('audit_log_event chrome_whatsapp_bridge kaynağıyla çağrılır', count($GLOBALS['_test_audit_events']) === 1 && $GLOBALS['_test_audit_events'][0]['new']['source'] === 'chrome_whatsapp_bridge');

// Aynı köprü mesajıyla 2. kez taslak oluşturma engellenir (409)
$dupDraft = cw_bridge_create_beyan_draft($pdo, $proc1['ai_result']['form_fields'], ['id' => 1], $proc1['bridge_message_id'], true);
ok('aynı köprü mesajı için ikinci taslak 409 ile engellenir', $dupDraft['ok'] === false && $dupDraft['http_status'] === 409);

// Farklı mesaj ama aynı parti numarası → uyarı döner, mevcut kayda dokunmaz
$procSameRef = cw_bridge_process_message($pdo, [
    'chat_name' => 'Test Grup',
    'chat_type' => 'group',
    'source_message_id' => 'msg_002',
    'sender_name' => 'Kullanıcı B',
    'message_timestamp' => '10:05, 27.11.2026',
    'raw_text' => 'Farklı mesaj ama aynı parti 901-A',
], ['id' => 1], 1, $mockAiOk);
ok('farklı mesajda aynı parti no için referans uyarısı döner', $procSameRef['ok'] === true && count($procSameRef['duplicate_reference_warnings']) >= 1);

// ── 6. Güvenlik: CORS, HTTPS ve Hız Sınırı ────────────────
ok('CORS yalnız izinli originleri kabul eder',
    cw_bridge_allowed_origin('https://asya.scai.tr') === 'https://asya.scai.tr'
    && cw_bridge_allowed_origin('chrome-extension://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa') !== null
    && cw_bridge_allowed_origin('https://evil.example.com') === null);

$rl1 = cw_bridge_rate_limit('test-bucket', 2, 60, 1000);
$rl2 = cw_bridge_rate_limit('test-bucket', 2, 60, 1001);
$rl3 = cw_bridge_rate_limit('test-bucket', 2, 60, 1002);
ok('hız sınırı limiti aşılınca engeller', $rl1['ok'] === true && $rl2['ok'] === true && $rl3['ok'] === false && $rl3['retry_after'] >= 1);

// AI hata senaryoları (quota 429 → HTTP 429)
$mockQuotaAi = new AIParserService('groq', 'test-key', 'openai/gpt-oss-120b', static fn() => ['status' => 429, 'body' => '{"error":{"message":"rate limit"}}']);
$procErr = cw_bridge_process_message($pdo, [
    'chat_name' => 'Test Grup',
    'source_message_id' => 'msg_err_1',
    'raw_text' => 'Hata testi mesajı',
], ['id' => 1], 1, $mockQuotaAi);
ok('AI 429 kota hatası düzgün HTTP 429 olarak döner', $procErr['ok'] === false && $procErr['http_status'] === 429);

// ── 7. Chrome Eklentisi Tek-Tık ZIP Paketi ────────────────
$extZip = cw_bridge_extension_zip($root . '/chrome-extension/asya-whatsapp-bridge');
ok('eklenti ZIP geçerli PKZIP başlığıyla üretilir', strncmp($extZip, "PK\x03\x04", 4) === 0 && strlen($extZip) > 5000);
foreach (['manifest.json', 'service-worker.js', 'content.js', 'popup.html', 'popup.css', 'popup.js', 'README.md'] as $zf) {
    ok("eklenti ZIP dosyasını içerir: {$zf}", str_contains($extZip, 'asya-whatsapp-bridge/' . $zf));
}

echo "chrome_whatsapp_bridge_smoke.php: {$tests} test, {$fails} başarısız\n";
exit($fails === 0 ? 0 : 1);
