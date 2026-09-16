<?php
// =========================================================
// scripts/pdks_faz8c_smoke.php — Faz 8C rapor/yazdırma tamamlama testi
//
// SADECE CLI. Canlı veritabanına dokunmaz. Bellek içi SQLite + stub
// fonksiyonlarla cavus_hakedis_yazdir.php'yi gerçek kaynak kodundan render
// eder; ayrıca salt-okunur/yetki/kapsam kurallarını statik doğrular.
//
//   php scripts/pdks_faz8c_smoke.php  → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$ROOT = dirname(__DIR__);
$PDO_TEST = null;
$SATIRLAR = [];
$OZET = ['eksik_toplam' => 0];

function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { return $p === 'attendance.entitlements'; }
function is_admin(): bool { return false; }
function require_login(): array { return current_user(); }
function require_pdks_hakedis(string $eylem): void {}
function pdks_gunluk_sayfa_kapisi(?PDO $pdo = null): void {}
function pdks_hakedis_sayfa_kapisi(?PDO $pdo = null): void {}
function set_flash($a, $b): void {}
function base_url(): string { return '/'; }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pdks_hakedis_satirlar(int $id, ?PDO $pdo = null): array { global $SATIRLAR; return $SATIRLAR[$id] ?? []; }
function pdks_gunluk_oturum_ozet(int $id, ?PDO $pdo = null): array { global $OZET; return $OZET; }
function render_print_page_start(string $title, string $module = '', string $type = '', string $orientation = 'portrait'): void {
    echo '<!doctype html><html><head><title>' . h($title) . '</title><link rel="stylesheet" href="/assets/print_base.css"></head><body>';
}
function render_print_page_end(): void { echo '</body></html>'; }
function render_print_header_html(string $title, string $subtitle = '', string $meta = ''): string {
    return '<header><h1>' . h($title) . '</h1><div>' . $subtitle . '</div><div>' . $meta . '</div></header>';
}

$fail = 0;
$gecen = 0;
function ok8c(string $ad, bool $kosul, string $ipucu = ''): void {
    global $fail, $gecen;
    $kosul ? $gecen++ : $fail++;
    printf("%-92s %s%s\n", $ad, $kosul ? 'OK' : '*** HATA', $kosul ? '' : "\n    → " . $ipucu);
}

function renderHakedis(int $id): string
{
    global $ROOT;
    $_GET = ['id' => (string)$id];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/cavus_hakedis_yazdir.php?id=' . $id;

    $src = (string)file_get_contents($ROOT . '/cavus_hakedis_yazdir.php');
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/[^\']+\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);

    $tmp = sys_get_temp_dir() . '/pdksfaz8c_' . md5((string)$id . microtime(true)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try {
        include $tmp;
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $html = '__ERROR__: ' . $e->getMessage();
    }
    @unlink($tmp);
    return $html;
}

function yeniDb(bool $faz8b): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($faz8b) {
        $db->exec("CREATE TABLE foreman_daily_entitlements (
            id INTEGER PRIMARY KEY, session_id INTEGER NOT NULL,
            foreman_name_snapshot TEXT NOT NULL, foreman_code_snapshot TEXT NOT NULL,
            work_date TEXT NOT NULL, depo TEXT NOT NULL DEFAULT '', status TEXT NOT NULL,
            currency TEXT NOT NULL, total_amount DECIMAL(14,2) NOT NULL,
            missing_exit_ack INTEGER NOT NULL DEFAULT 0,
            needs_recalculation INTEGER NOT NULL DEFAULT 0
        )");
    } else {
        $db->exec("CREATE TABLE foreman_daily_entitlements (
            id INTEGER PRIMARY KEY, session_id INTEGER NOT NULL,
            foreman_name_snapshot TEXT NOT NULL, foreman_code_snapshot TEXT NOT NULL,
            work_date TEXT NOT NULL, depo TEXT NOT NULL DEFAULT '', status TEXT NOT NULL,
            currency TEXT NOT NULL, total_amount DECIMAL(14,2) NOT NULL,
            missing_exit_ack INTEGER NOT NULL DEFAULT 0
        )");
    }
    return $db;
}

echo "\n=== 1. FAZ 8B SNAPSHOT → FAZ 8C YAZDIRMA ===\n";
$PDO_TEST = yeniDb(true);
$PDO_TEST->exec("INSERT INTO foreman_daily_entitlements
    (id,session_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,missing_exit_ack,needs_recalculation)
    VALUES (1,101,'Test Çavuş','C900','2026-09-16','Depo A','final','TRY',4150.00,0,0)");
$SATIRLAR = [
    1 => [
        [
            'worker_type_name_snapshot' => 'Kadın', 'attendance_class_snapshot' => 'yarim',
            'worker_count' => 1, 'unit_rate' => '900.00', 'overtime_hours' => 0,
            'overtime_mode_snapshot' => null, 'overtime_unit_rate' => '0.00',
            'overtime_total' => '0.00', 'line_total' => '900.00',
        ],
        [
            'worker_type_name_snapshot' => 'Erkek', 'attendance_class_snapshot' => 'tam',
            'worker_count' => 1, 'unit_rate' => '1500.00', 'overtime_hours' => 2,
            'overtime_mode_snapshot' => 'hourly', 'overtime_unit_rate' => '200.00',
            'overtime_total' => '400.00', 'line_total' => '1900.00',
        ],
        [
            'worker_type_name_snapshot' => 'Paketleme', 'attendance_class_snapshot' => 'tam',
            'worker_count' => 1, 'unit_rate' => '1000.00', 'overtime_hours' => 1,
            'overtime_mode_snapshot' => 'fixed', 'overtime_unit_rate' => '350.00',
            'overtime_total' => '350.00', 'line_total' => '1350.00',
        ],
    ],
];
$OZET = ['eksik_toplam' => 0];
$html = renderHakedis(1);
ok8c('sayfa hata sızdırmadan render edildi', !str_starts_with($html, '__ERROR__'), $html);
ok8c('PHP Warning/Notice yok', !str_contains($html, 'Warning:') && !str_contains($html, 'Notice:'), $html);
ok8c('Faz 8C kolonları: Mesai + Fazla Mesai + Temel Ücret', str_contains($html, '<th>Mesai</th>') && str_contains($html, '<th>Fazla Mesai</th>') && str_contains($html, 'Temel Ücret'));
ok8c('Yarım Mesai snapshot yazdırılıyor', str_contains($html, 'Yarım Mesai'));
ok8c('Tam Mesai snapshot yazdırılıyor', str_contains($html, 'Tam Mesai'));
ok8c('Saatlik FM: 2 saat + 200,00/saat + 400,00 TRY', str_contains($html, '2 saat') && str_contains($html, 'Saatlik') && str_contains($html, '200,00/saat') && str_contains($html, '400,00') && str_contains($html, 'TRY'));
ok8c('Sabit FM: 1 saat + Sabit + 350,00 TRY', str_contains($html, '1 saat') && str_contains($html, 'Sabit') && str_contains($html, '350,00'));
ok8c('genel toplam 4.150,00 TRY', (bool)preg_match('/GENEL TOPLAM.*?4\.150,00 TRY/s', $html), $html);

echo "\n=== 2. BAYAT TASLAK UYARISI ===\n";
$PDO_TEST->exec("INSERT INTO foreman_daily_entitlements
    (id,session_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,missing_exit_ack,needs_recalculation)
    VALUES (2,102,'Test Çavuş','C900','2026-09-16','Depo A','draft','TRY',900.00,0,1)");
$SATIRLAR[2] = [$SATIRLAR[1][0]];
$htmlTaslak = renderHakedis(2);
ok8c('needs_recalculation=1 taslakta açık yeniden-hesaplama uyarısı var', str_contains($htmlTaslak, 'yeniden hesaplanmadan güncel kabul edilmemelidir'), $htmlTaslak);


echo "\n=== 3. FAZ 8B ÖNCESİ GERİYE UYUMLULUK ===\n";
$PDO_TEST = yeniDb(false);
$PDO_TEST->exec("INSERT INTO foreman_daily_entitlements
    (id,session_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,missing_exit_ack)
    VALUES (3,103,'Eski Çavuş','C100','2026-09-15','Depo A','final','TRY',1200.00,0)");
$SATIRLAR = [
    3 => [[
        'worker_type_name_snapshot' => 'Kadın', 'worker_count' => 1,
        'unit_rate' => '1200.00', 'line_total' => '1200.00',
    ]],
];
$OZET = ['eksik_toplam' => 0];
$htmlEski = renderHakedis(3);
ok8c('eski snapshot hata sızdırmadan render edildi', !str_starts_with($htmlEski, '__ERROR__'), $htmlEski);
ok8c('eski görünümde Birim Fiyat korunuyor', str_contains($htmlEski, '<th>Birim Fiyat</th>'));
ok8c('eski görünüm Faz 8B Mesai/FM kolonlarını uydurmuyor', !str_contains($htmlEski, '<th>Mesai</th>') && !str_contains($htmlEski, '<th>Fazla Mesai</th>'));
ok8c('eski toplam 1.200,00 TRY korunuyor', (bool)preg_match('/GENEL TOPLAM.*?1\.200,00 TRY/s', $htmlEski), $htmlEski);

echo "\n=== 4. STATİK GÜVENLİK / KAPSAM ===\n";
$printSrc = (string)file_get_contents($ROOT . '/cavus_hakedis_yazdir.php');
$takipSrc = (string)file_get_contents($ROOT . '/personel_takip.php');
$docSrc = (string)file_get_contents($ROOT . '/docs/PDKS_GUNLUK_FAZ8C.md');
$printKod = preg_replace('/^\s*\/\/.*$/m', '', $printSrc);
ok8c("yazdırma yetkisi entitlements_view olarak korunuyor", str_contains($printSrc, "require_pdks_hakedis('entitlements_view')"));
ok8c('yazdırma sayfası INSERT/UPDATE/DELETE içermiyor', !preg_match('/\b(INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM)\b/i', $printKod));
ok8c('yazdırma kaynağı Faz 8B snapshot alanlarını okuyor', str_contains($printSrc, 'attendance_class_snapshot') && str_contains($printSrc, 'overtime_hours') && str_contains($printSrc, 'overtime_total'));
ok8c('Personel Takip kartları Tam/Yarım/FM ve mesai değerlendirmesini anlatıyor', str_contains($takipSrc, 'Tam / Yarım / Fazla mesai ücretleri') && str_contains($takipSrc, 'Mesai değerlendirme + taslak / kesin hakediş'));
ok8c('Faz 8C dokümanı offline kuyruğu kapsam dışı tutuyor', str_contains($docSrc, 'offline') && str_contains($docSrc, 'Kapsam dışı') || str_contains($docSrc, 'kapsam dışı'));
ok8c('Faz 8C dokümanı migrasyon olmadığını açıkça belirtiyor', str_contains($docSrc, 'Migrasyon') && str_contains($docSrc, 'Yok.'));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
