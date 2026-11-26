<?php
// =========================================================
// scripts/faz3_perf_ux_smoke.php — Faz 3 Performans, Altyapı ve UX Smoke Testi
// (B-28, B-29, B-30, B-31, B-32, B-33, B-34, B-35, B-36, B-42)
// SADECE CLI. Bellek içi SQLite + geçici klasör; canlı DB'ye dokunmaz.
//   php scripts/faz3_perf_ux_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;
function ok(string $ad, bool $kosul, string $ipucu = ''): void {
    global $pass, $fail;
    if ($kosul) $pass++; else $fail++;
    fwrite(STDOUT, sprintf("%-76s %s%s\n", $ad, $kosul ? 'OK' : '*** FAIL', $kosul ? '' : '  ' . $ipucu));
}
function oku(string $rel): string {
    global $ROOT;
    return (string)file_get_contents($ROOT . '/' . $rel);
}

// ── 1) B-28: Sayfalama, SELECT * temizliği ve batch audit_log ──────────────
$rec = oku('records.php');
$pf  = oku('palet_fisleri.php');
$pfJs = oku('assets/palet-fisleri.js');
$kf  = oku('kantar.php');

ok('B-28a records.php SELECT r.* yerine açık kolon listesi (etiket_foto yok)',
    !str_contains($rec, 'SELECT r.*,') && str_contains($rec, '$_rec_sel') && !str_contains($rec, "'r.etiket_foto'")
);
ok('B-28b palet_fisleri.php LIMIT 5000 cari yükü kalktı + AJAX mustahsil_ara + SQL sayfalama + batch audit',
    !str_contains($pf, 'LIMIT 5000')
    && str_contains($pf, "isset(\$_GET['mustahsil_ara'])")
    && str_contains($pf, 'function pf_islem_batch(')
    && str_contains($pf, '$pf_total_pages')
    && str_contains($pfJs, 'mustahsil_ara=')
);
ok('B-28c kantar.php LIMIT 2000 + correlated subquery yerine SQL sayfalama + batch audit',
    !str_contains($kf, 'LIMIT 2000')
    && !str_contains($kf, 'SELECT MAX(a2.id) FROM audit_log a2')
    && str_contains($kf, '$kf_total_pages')
    && str_contains($kf, 'GROUP BY record_id')
);

// ── 2) B-29: Composite indeks migrasyonu (SQLite idempotency + migrate.php) ─
require_once $ROOT . '/config/cekirdek_migrate.php';
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT, record_id INTEGER, action TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE loading_records (id INTEGER PRIMARY KEY, type TEXT, durum TEXT, tarih TEXT)');
$pdo->exec('CREATE TABLE loading_pallets (id INTEGER PRIMARY KEY, loading_record_id INTEGER, depo TEXT, islendi INTEGER)');
$pdo->exec('CREATE TABLE kantar_fisleri (id INTEGER PRIMARY KEY, giris_tarih TEXT, depo TEXT)');

$d0 = cekirdek_perf_indeksler_durum($pdo);
ok('B-29a Başlangıçta 4 composite indeks eksik görünür', !$d0['hazir'] && count($d0['eksik']) === 4, json_encode($d0));
$m1 = cekirdek_perf_indeksler_migrate($pdo);
$d1 = cekirdek_perf_indeksler_durum($pdo);
ok('B-29b İlk migrasyon 4 composite indeksi oluşturur',
    $d1['hazir'] && count(array_filter($m1, fn($r) => $r['durum'] === 'olusturuldu')) === 4,
    json_encode([$m1, $d1])
);
$m2 = cekirdek_perf_indeksler_migrate($pdo);
ok('B-29c İkinci çalıştırma tamamen idempotent (4/4 var)',
    count(array_filter($m2, fn($r) => $r['durum'] === 'var')) === 4,
    json_encode($m2)
);
$mig = oku('migrate.php');
ok('B-29d migrate.php #perf-indeksler kartı ve CSRF korumalı POST dalı mevcut',
    str_contains($mig, "mg_kart_ac('perf-indeksler')") && str_contains($mig, "=== 'perf_indeksler'")
);

// ── 3) B-30: sw.js allowlist + app_cache_* ──────────────────────────────────
$sw = oku('sw.js');
ok('B-30a sw.js SHELL içinde ./index.php YOK ve SW_STATIK_IZIN_RE allowlist kapısı var',
    !str_contains($sw, "'./index.php'")
    && str_contains($sw, 'SW_STATIK_IZIN_RE')
    && str_contains($sw, 'u.origin !== self.location.origin || !SW_STATIK_IZIN_RE.test(u.pathname)')
);

require_once $ROOT . '/config/helpers.php';
$hit = false;
ok('B-30b app_cache_get başlangıçta miss döner', app_cache_get('faz3_test', $hit) === null && $hit === false);
app_cache_set('faz3_test', ['a' => 42], 30);
ok('B-30c app_cache_set sonrası app_cache_get aynı değeri döner', app_cache_get('faz3_test', $hit) === ['a' => 42] && $hit === true);
app_cache_del('faz3_test');
ok('B-30d app_cache_del sonrası tekrar miss döner', app_cache_get('faz3_test', $hit) === null && $hit === false);

// ── 4) B-31: export_prepare_runtime() ───────────────────────────────────────
ok('B-31a export_prepare_runtime() tanımlı ve çalışır', function_exists('export_prepare_runtime'));
export_prepare_runtime(256, 120);
foreach (['config/xlsx_export.php', 'config/hesap_pdf.php', 'config/frugro_pkl.php', 'record_excel_template.php', 'rapor_malzeme.php'] as $ef) {
    ok("B-31b $ef içinde export_prepare_runtime çağrısı var", str_contains(oku($ef), 'export_prepare_runtime'));
}

// ── 5) B-32: Yedekleme, global hata yakalayıcı, audit_retention_cron, health ─
$dbSrc = oku('config/db.php');
$efSrc = oku('api_etiket_foto.php');
$idxSrc = oku('index.php');
$hlthSrc = oku('health.php');
ok('B-32a config/db.php display_errors=0 + set_exception_handler + ham PDO mesajı gizli',
    str_contains($dbSrc, "ini_set('display_errors', '0')")
    && str_contains($dbSrc, 'set_exception_handler(')
    && !str_contains($dbSrc, 'htmlspecialchars($e->getMessage())')
);
ok('B-32b api_etiket_foto.php ham exception mesajını istemciye dönmez',
    !str_contains($efSrc, "'error' => \$e->getMessage()") && str_contains($efSrc, "error_log('[api_etiket_foto]")
);
ok('B-32c index.php yedeklemeyi fastcgi_finish_request / DB_BACKUP_CRON_ONLY ile bloklamaz',
    str_contains($idxSrc, 'fastcgi_finish_request') && str_contains($idxSrc, 'DB_BACKUP_CRON_ONLY')
);
ok('B-32d health.php token + admin korumalı DB ping ucu mevcut',
    str_contains($hlthSrc, 'HEALTH_CHECK_TOKEN') && str_contains($hlthSrc, 'hash_equals') && str_contains($hlthSrc, 'is_admin()')
);

// audit_retention_cron.php gerçek SQLite + gzip arşiv doğrulaması
require_once $ROOT . '/scripts/audit_retention_cron.php';
$pdo->exec("INSERT INTO audit_log (module, record_id, action, created_at) VALUES ('stok', 1, 'create', '2024-01-15 10:00:00')");
$pdo->exec("INSERT INTO audit_log (module, record_id, action, created_at) VALUES ('stok', 2, 'update', '2024-02-20 11:00:00')");
$pdo->exec("INSERT INTO audit_log (module, record_id, action, created_at) VALUES ('stok', 3, 'create', '2026-11-20 12:00:00')");
$tmpDir = sys_get_temp_dir() . '/asya_audit_ret_' . getmypid() . '_' . bin2hex(random_bytes(3));
try {
    $nowFixed = strtotime('2026-11-26 12:00:00');
    $dry = audit_retention_calistir($pdo, $tmpDir, 365, false, $nowFixed);
    ok('B-32e audit_retention dry-run 2 adayı bulur ve silmez',
        $dry['dry_run'] === true && $dry['aday'] === 2 && $dry['silinen'] === 0 && (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 3,
        json_encode($dry)
    );
    $app = audit_retention_calistir($pdo, $tmpDir, 365, true, $nowFixed);
    $kalan = (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $gz1 = is_file($tmpDir . '/audit_2024-01.jsonl.gz') ? gzdecode((string)file_get_contents($tmpDir . '/audit_2024-01.jsonl.gz')) : '';
    ok('B-32f audit_retention --apply 2 kaydı gzip JSONL arşivler ve yalnız onları siler',
        $app['arsivlenen'] === 2 && $app['silinen'] === 2 && $kalan === 1 && str_contains((string)$gz1, '"record_id":1'),
        json_encode([$app, $kalan])
    );
} finally {
    if (is_dir($tmpDir)) {
        foreach (glob($tmpDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (!in_array(basename($f), ['.', '..'], true) && is_file($f)) @unlink($f);
        }
        @rmdir($tmpDir);
    }
}

// ── 6) B-33, B-34, B-35, B-36, B-42: Frontend, UX, A11y ve Altyapı ─────────
$css = oku('assets/style.css');
$appJs = oku('assets/app.js');
$hesapJs = oku('assets/hesap.js');
$kalanJs = oku('assets/kalan.js');
$hlp = oku('config/helpers.php');
$hkIdx = oku('halkayit/index.php');
$hkApp = oku('halkayit/app.html');
$man = oku('manifest.json');
$authSrc = oku('config/auth.php');
$dvUi = oku('config/doviz_ui.php');
$dvApi = oku('api_doviz_kur.php');
$comp = oku('composer.json');

ok('B-33 Tablet 899px bottomnav + 900px sidebar hizalaması + topnav Ana Sayfa',
    str_contains($css, '@media (min-width: 900px) { :root { --bn-h: 0px; } }')
    && str_contains($css, "@media (max-width: 899px) {\n    .bottomnav { display: block; }")
    && str_contains($css, '@media (min-width: 900px) { .bn-sheet-ovl { display: none !important; } }')
    && str_contains($hkIdx, '@media (max-width: 899px)')
    && str_contains($hlp, '>Ana Sayfa</a>')
);
ok('B-34 Çift gönderim koruması: global POST guard + HesapUI.durum + kalanSaveTarget',
    str_contains($appJs, 'asyaSubmitting') && str_contains($appJs, "addEventListener('pageshow'")
    && str_contains($hesapJs, 'var durumBusy = false;')
    && str_contains($kalanJs, 'if (!btn || btn.disabled) return;')
);
ok('B-35 Pinch-to-zoom açık (maximum-scale/user-scalable yok) + manifest orientation=any',
    !str_contains($hlp, 'user-scalable=no') && !str_contains($hlp, 'maximum-scale=1')
    && !str_contains($hkApp, 'maximum-scale=1')
    && str_contains($man, '"orientation": "any"')
);
ok('B-36 Koyu tema kontrastı + halkayit/app.html --ozet-bg döngüsü düzeltildi',
    !str_contains($hkApp, '--ozet-bg:var(--ozet-bg)')
    && str_contains($hkApp, '--ozet-bg:rgba(255,255,255,.94)')
    && str_contains($hkIdx, 'var(--bg, #eef4f7)')
    && str_contains(oku('assets/frugro_pkl.css'), 'html[data-theme="dark"]')
    && str_contains(oku('assets/mail.css'), 'html[data-theme="dark"] .mail-govde')
    && str_contains(oku('assets/hesap.css'), 'html[data-theme="dark"] .hs-card')
    && str_contains(oku('assets/hizli-maliyet.css'), 'html[data-theme="dark"] .qc-page')
);
ok('B-42a Tek --sb-w sidebar genişliği + 11px taban + render_flash ARIA + focusTrap + ms-sug dinamik dinleyici',
    str_contains($css, 'width: var(--sb-w')
    && str_contains($css, 'font-size: max(11px, .82rem)')
    && str_contains($hlp, 'aria-live="polite"')
    && str_contains($appJs, 'window.asyaFocusTrap')
    && str_contains($appJs, 'function unbindPosListeners()')
);
ok('B-42b auth.php sliding UPDATE >300 sn + GC, doviz_ui filemtime, api_doviz_kur sınır, json_html, composer 8.3.0',
    str_contains($authSrc, '(time() - $lastSeenTs) > 300')
    && str_contains($authSrc, 'DELETE FROM user_sessions WHERE expires_at < NOW()')
    && str_contains($dvUi, '@filemtime(')
    && str_contains($dvApi, '_doviz_oto_ts')
    && json_html(['x' => '</script>']) === '{"x":"\u003C/script\u003E"}'
    && str_contains($comp, '"php": "8.3.0"')
    && str_contains($sw, "u.pathname.indexOf('/assets/') === -1")
);

fwrite(STDOUT, "\nÖZET: $pass geçti, $fail kaldı\n");
exit($fail > 0 ? 1 : 0);
