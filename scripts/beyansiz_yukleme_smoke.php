<?php
// =========================================================
// scripts/beyansiz_yukleme_smoke.php — "Yükleme Bildir" (beyansız yükleme, v498)
//
// SADECE CLI, bellek içi SQLite; canlı DB/HKS'e dokunmaz.
//   php scripts/beyansiz_yukleme_smoke.php   → çıkış 0 = hepsi geçti
//
// Doğrular: migrate idempotent · bekleyen/yapılan bölümleme (ölü taslak = "belirsiz",
// çapraz-collation: JOIN/EXISTS yok) · arama kaçışı · isaretle/tasi ikinci UPDATE
// (beyan bağı OLMADAN da) · tablo yokken fail-soft · bb_taslak_kur ↔ bsz_ortak_yurtdisi
// alan kümesi paritesi · api_beyansiz_yukleme.php statik kuralları.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
ini_set('display_errors', '1');
$ROOT = dirname(__DIR__);
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/beyansiz_yukleme_smoke.log');

$API_MOD = ($argv[1] ?? '') === '--api';
$PDO_TEST = new PDO($API_MOD ? 'sqlite:' . $argv[2] : 'sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
$PERMS = [];
function current_user(): ?array { return ($GLOBALS['OTURUMSUZ'] ?? false) ? null : ['id' => 1, 'username' => 't', 'display_name' => 'T']; }
require_once $ROOT . '/config/role_permission_matrix.php';
function can(string $p): bool { global $PERMS; return in_array($p, role_matrix_expand_permissions($PERMS), true); }
function is_admin(): bool { return false; }
function require_login(): array { return current_user(); }
function forbidden($m = ''): void { throw new RuntimeException('forbidden'); }
function active_depot(): ?string { return 'DEPO'; }
function enforce_active_depot(): void {}
function depot_options(): array { return ['DEPO']; }
function depot_visible_to_user(?string $d): bool { return true; }
function user_allowed_depots(): array { return ['DEPO']; }
function depo_sql_records(string $a = 'r'): array { return ['', []]; }
function depo_sql_records_in(string $a = 'r'): array { return ['', []]; }
function depo_sql_column(string $c): array { return ['', []]; }
function depo_sql_in(string $c): array { return ['', []]; }
function user_primary_role(): ?array { return ['slug' => 'operator', 'label' => 'Operatör']; }

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-72s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : "\n    → $ipucu");
}


// ── Alt süreç: api_beyansiz_yukleme.php'yi GERÇEKTEN çalıştırır (uçtan uca) ──
// argv: --api <sqlite dosyası> <action> <json gövde> <izinler(virgüllü)> [oturumsuz]
if ($API_MOD) {
    $PERMS = array_values(array_filter(explode(',', (string)($argv[5] ?? ''))));
    $OTURUMSUZ = ($argv[6] ?? '') === 'oturumsuz';
    $_GET = ['action' => $argv[3]];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $BODY = (string)$argv[4];
    function csrf_check_stub(?string $t): void { if ($t !== 'tok') { http_response_code(403); echo json_encode(['hata' => 'csrf']); exit; } }
    require_once $ROOT . '/config/helpers.php';   // csrf_check'i aşağıda kaynakta stub'la değiştiriyoruz
    // halkayit katmanı: db/config require'ları kaldırılır; hks_db()/hks_tablo()/hks_coz()/hks_kv_oku() SQLite'a bağlanır.
    function hks_db() { return db(); }
    function hks_tablo($ad) { return 'hks_' . $ad; }
    function hks_coz($x) { return 'x'; }
    function hks_kv_oku($a, $v = null) {
        $st = db()->prepare('SELECT deger FROM hks_kv WHERE anahtar = ?'); $st->execute([$a]);
        $r = $st->fetchColumn(); return $r === false ? $v : (json_decode((string)$r, true) ?? $v);
    }
    function hks_kv_yaz($a, $v) {}
    define('HKS_TABLO_ON', 'hks_');
    require_once $ROOT . '/halkayit/hks_soap.php';
    $tl = (string)file_get_contents($ROOT . '/halkayit/taslak_lib.php');
    $tl = preg_replace("#^require_once __DIR__ \. '/(config|db|hks_soap|dogum_deney_lib)\.php';.*$#m", '', $tl);
    $tmp = tempnam(sys_get_temp_dir(), 'bsztl'); file_put_contents($tmp, $tl); require $tmp; @unlink($tmp);
    require_once $ROOT . '/config/beyansiz_yukleme.php';
    $api = (string)file_get_contents($ROOT . '/api_beyansiz_yukleme.php');
    $api = preg_replace("#^require_once [^\n]*;\s*$#m", '', $api);
    $api = str_replace("file_get_contents('php://input')", '$BODY', $api);
    $api = str_replace('csrf_check(', 'csrf_check_stub(', $api);
    $api = str_replace('header(', '(function(...$a){})(', $api);
    $api = preg_replace('/^<\?php/', '', $api, 1);
    $api = str_replace('declare(strict_types=1);', '', $api);
    // API bsz_cikti() ile exit eder: durum + gövde kapanışta (shutdown) sarılıp yazılır.
    ob_start();
    register_shutdown_function(static function (): void {
        $c = (string)ob_get_clean();
        echo json_encode(['status' => http_response_code() ?: 200, 'govde' => json_decode($c, true) ?? $c], JSON_UNESCAPED_UNICODE);
    });
    try { eval($api); } catch (Throwable $e) { echo json_encode(['__istisna' => get_class($e) . ': ' . $e->getMessage()]); }
    exit(0);
}

// ── Alt süreç: beyansiz_tablo_var() statik önbellekli → iki durum ayrı süreçte ──
if (($argv[1] ?? '') === '--tablo-var') {
    require_once $ROOT . '/config/helpers.php';
    if (($argv[2] ?? '') === 'var') db()->exec("CREATE TABLE beyansiz_yukleme (id INTEGER PRIMARY KEY)");
    echo beyansiz_tablo_var() ? 'VAR' : 'YOK';
    exit(0);
}

require_once $ROOT . '/config/helpers.php';
require_once $ROOT . '/config/beyansiz_yukleme.php';

// ── Şema / migrate ───────────────────────────────────────────────────────
echo "\n=== 1. migrate ===\n";
$m1 = beyansiz_yukleme_migrate(db());
ok('ilk migrate: olusturuldu', $m1[0]['durum'] === 'olusturuldu', json_encode($m1));
$m2 = beyansiz_yukleme_migrate(db());
ok('ikinci migrate: mevcut (idempotent)', $m2[0]['durum'] === 'mevcut', json_encode($m2));
$ddl = beyansiz_yukleme_ddl(new PDO('sqlite::memory:'));
$kaynak = (string)file_get_contents($ROOT . '/config/beyansiz_yukleme.php');
ok('MySQL DDL: istek_kodu UNIQUE (uq_bsz_istek) + taslak_id indeks + utf8mb4_unicode_ci',
   str_contains($kaynak, 'UNIQUE KEY `uq_bsz_istek` (`istek_kodu`)') && str_contains($kaynak, 'KEY `ix_bsz_taslak`')
   && str_contains($kaynak, 'utf8mb4_unicode_ci'));
ok('karşı kişi için TC/cep/doğum kolonu YOK',
   !preg_match('/`?(tc|cep|dogum)`?\s+(VARCHAR|DATE)/i', $kaynak . $ddl));

// ── Bölümleme ────────────────────────────────────────────────────────────
echo "\n=== 2. bekleyen / yapılan bölümleme ===\n";
db()->exec("CREATE TABLE hks_taslaklar (id VARCHAR(40) PRIMARY KEY, zaman TEXT, firma_id TEXT, firma_ad TEXT, veri TEXT)");
db()->exec("INSERT INTO hks_taslaklar (id) VALUES ('tAlive'), ('tAlive2')");
function bsz_satir(array $a): int {
    $v = array_merge(['parti_no' => null, 'hks_firma_id' => 'f1', 'hks_firma_ad' => 'FİRMA', 'yon' => 'yurt_disi',
        'ulke_ad' => 'Rusya', 'urun_id' => '10', 'urun_ad' => 'KAYISI', 'kg' => 1000, 'plaka' => '34ABC123',
        'taslak_id' => null, 'durum' => 'taslak', 'created_at' => date('Y-m-d H:i:s')], $a);
    db()->prepare("INSERT INTO beyansiz_yukleme (parti_no,hks_firma_id,hks_firma_ad,yon,ulke_ad,karsi_kisi_ad,urun_id,urun_ad,kg,plaka,taslak_id,durum,created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$v['parti_no'], $v['hks_firma_id'], $v['hks_firma_ad'], $v['yon'], $v['ulke_ad'], $v['karsi_kisi_ad'] ?? null,
                   $v['urun_id'], $v['urun_ad'], $v['kg'], $v['plaka'], $v['taslak_id'], $v['durum'], $v['created_at']]);
    return (int)db()->lastInsertId();
}
$idA = bsz_satir(['taslak_id' => 'tAlive', 'plaka' => '34AAA111']);
$idA2 = bsz_satir(['taslak_id' => 'tAlive2', 'yon' => 'yurt_ici', 'karsi_kisi_ad' => 'KİŞİ ADI', 'ulke_ad' => null, 'plaka' => '06BBB222']);
$idB = bsz_satir(['taslak_id' => 'tOlu', 'plaka' => '35CCC333']);
$idC = bsz_satir(['taslak_id' => 'tG', 'durum' => 'gonderildi', 'plaka' => '16DDD444']);
$idD = bsz_satir(['taslak_id' => 'tS', 'durum' => 'silindi', 'plaka' => '07EEE555']);
$idE = bsz_satir(['taslak_id' => null, 'plaka' => '01FFF666', 'parti_no' => '100%_X']);
$say = bsz_sayilar();
ok('bekleyen = 2 (canlı taslak), yapılan = 4 (gönderildi+silindi+ölü+taslaksız)', $say['bekleyen'] === 2 && $say['yapilan'] === 4, json_encode($say));
$bek = bsz_bekleyen('', 1, 50);
ok('bekleyen satırlar goster=bekliyor', count($bek['satirlar']) === 2 && $bek['satirlar'][0]['goster'] === 'bekliyor');
$yap = bsz_yapilan('', 1, 50);
$goster = array_count_values(array_column($yap['satirlar'], 'goster'));
ok('yapılan: 1 gönderildi, 1 silindi, 2 belirsiz', ($goster['gonderildi'] ?? 0) === 1 && ($goster['silindi'] ?? 0) === 1 && ($goster['belirsiz'] ?? 0) === 2, json_encode($goster));
ok('arama plaka', bsz_bekleyen('AAA', 1, 50)['toplam'] === 1);
ok('arama joker kaçışı: "%_" yalnız gerçek eşleşmeyi bulur', bsz_yapilan('%_X', 1, 50)['toplam'] === 1 && bsz_yapilan('%', 1, 50)['toplam'] === 1);
ok('sayfalama: per=1', count(bsz_bekleyen('', 1, 1)['satirlar']) === 1 && bsz_bekleyen('', 2, 1)['sayfa'] === 2 && bsz_bekleyen('', 9, 1)['sayfa'] === 2);
ok('bsz_canli_taslak_idleri: ölü id dönmez, parçalı IN (450 id)',
   bsz_canli_taslak_idleri(array_merge(['tAlive', 'tOlu'], array_map(fn($i) => "x$i", range(1, 450)))) === ['tAlive']);
ok('SQL\'de JOIN/EXISTS/COLLATE YOK (çapraz-collation)',
   !preg_match('/\bJOIN\b|\bEXISTS\b|\bCOLLATE\b/', preg_replace('/\/\/.*$|\/\*.*?\*\//ms', '', preg_replace('/^\s*\/\/.*$/m', '', $kaynak)) ?? ''), 'kodda JOIN/EXISTS/COLLATE geçiyor');
ok('plaka kontrol: bugün aynı plaka bulunur, silindi sayılmaz',
   count(bsz_ayni_plaka_bugun('34AAA111')) === 1 && bsz_ayni_plaka_bugun('07EEE555') === [] && bsz_ayni_plaka_bugun('') === []);

// ── Seçenekler ───────────────────────────────────────────────────────────
echo "\n=== 3. seçenekler (yalnız id + ad) ===\n";
db()->exec("CREATE TABLE hks_kisiler (id INTEGER PRIMARY KEY AUTOINCREMENT, tc TEXT, ad TEXT, cep TEXT, dogum TEXT, sifat_id INT)");
db()->exec("INSERT INTO hks_kisiler (tc,ad,cep,dogum) VALUES ('12345678901','KİŞİ AD','0532','1980-01-02'), ('1234567890','','', NULL), ('2345678901','VELİ GIDA','0532','1980-01-02')");
$ks = bsz_kisi_secenekleri();
ok('kişi seçenekleri: YALNIZ firma (10 haneli VKN), kişi (TC) ve adsız elenir; yalnız id+ad', count($ks) === 1 && array_keys($ks[0]) === ['id', 'ad'] && $ks[0]['ad'] === 'VELİ GIDA');
db()->exec("CREATE TABLE hks_firmalar (id TEXT PRIMARY KEY, ad TEXT, servis_sifre TEXT)");
db()->exec("INSERT INTO hks_firmalar VALUES ('f1','ASYA','GIZLI')");
$fs = bsz_firma_secenekleri();
ok('firma seçenekleri: yalnız id+ad (şifre sızmaz)', $fs === [['id' => 'f1', 'ad' => 'ASYA']]);

// ── isaretle / tasi ──────────────────────────────────────────────────────
echo "\n=== 4. beyan_hks_taslak_isaretle / tasi — beyansiz UPDATE ===\n";
// beyan_hks_bildirim tablosu HİÇ yok (beyan bağı olmadan da ikinci UPDATE çalışmalı)
beyan_hks_taslak_tasi('tAlive', 'tYeni');
$r = db()->query("SELECT taslak_id FROM beyansiz_yukleme WHERE id = $idA")->fetchColumn();
ok('tasi: beyansiz satırı yeni taslak id\'sine taşındı (beyan bağı tablosu yokken)', $r === 'tYeni', (string)$r);
beyan_hks_taslak_isaretle('tYeni', 'gonderildi', ['gonderim_id' => 'g99']);
$row = db()->query("SELECT durum, gonderim_id FROM beyansiz_yukleme WHERE id = $idA")->fetch();
ok('isaretle gonderildi: durum + gonderim_id', $row['durum'] === 'gonderildi' && $row['gonderim_id'] === 'g99', json_encode($row));
beyan_hks_taslak_isaretle('tAlive2', 'silindi');
ok('isaretle silindi', db()->query("SELECT durum FROM beyansiz_yukleme WHERE id = $idA2")->fetchColumn() === 'silindi');
// yedek yol: taslak_id eşleşmez, beyansizId ile (yalnız gönderim, yalnız durum=taslak)
beyan_hks_taslak_isaretle('tBaska', 'gonderildi', ['beyansizId' => $idB, 'gonderim_id' => 'g1']);
ok('yedek yol: beyansizId ile gönderildi işaretlenir', db()->query("SELECT durum FROM beyansiz_yukleme WHERE id = $idB")->fetchColumn() === 'gonderildi');
beyan_hks_taslak_isaretle('tBaska2', 'silindi', ['beyansizId' => $idE]);
ok('yedek yol SİLMEDE kullanılmaz', db()->query("SELECT durum FROM beyansiz_yukleme WHERE id = $idE")->fetchColumn() === 'taslak');
beyan_hks_taslak_isaretle('tBaska3', 'gonderildi', ['beyansizId' => $idC]);
ok('yedek yol zaten sonuçlanmış satıra dokunmaz', db()->query("SELECT gonderim_id FROM beyansiz_yukleme WHERE id = $idC")->fetchColumn() === null);
// Beyan bağı varken ikisi bağımsız çalışır
db()->exec("CREATE TABLE beyan_hks_bildirim (id INTEGER PRIMARY KEY AUTOINCREMENT, beyan_id INT, taslak_id TEXT, gonderim_id TEXT, durum TEXT, hata_metni TEXT, updated_at TEXT, created_at TEXT)");
db()->exec("INSERT INTO beyan_hks_bildirim (beyan_id,taslak_id,durum) VALUES (5,'tBeyan','taslak')");
bsz_satir(['taslak_id' => 'tBeyan']);
beyan_hks_taslak_tasi('tBeyan', 'tBeyan2');
ok('tasi: iki tablo da taşındı', db()->query("SELECT taslak_id FROM beyan_hks_bildirim WHERE beyan_id = 5")->fetchColumn() === 'tBeyan2'
   && (int)db()->query("SELECT COUNT(*) FROM beyansiz_yukleme WHERE taslak_id = 'tBeyan2'")->fetchColumn() === 1);

// ── Fail-soft: tablo yok ─────────────────────────────────────────────────
echo "\n=== 5. tablo yokken fail-soft ===\n";
foreach (['var' => 'VAR', 'yok' => 'YOK'] as $mod => $beklenen) {
    $cikti = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --tablo-var ' . $mod . ' 2>/dev/null');
    ok("beyansiz_tablo_var(): tablo $mod → $beklenen", trim($cikti) === $beklenen, $cikti);
}
db()->exec("DROP TABLE beyansiz_yukleme");
ok('tablo yok: bsz_* boş döner, istisna yok',
   bsz_sayilar() === ['bekleyen' => 0, 'yapilan' => 0] && bsz_bekleyen()['satirlar'] === [] && bsz_yapilan('x')['toplam'] === 0 && bsz_ayni_plaka_bugun('X') === []);
beyan_hks_taslak_isaretle('tX', 'gonderildi');
beyan_hks_taslak_tasi('a', 'b');
ok('tablo yok: isaretle/tasi sessiz (istisna yok)', true);

// ── Parite: bb_taslak_kur ortak alan kümesi ↔ bsz_ortak_yurtdisi ─────────
echo "\n=== 6. parite ===\n";
$bb = (string)file_get_contents($ROOT . '/api_beyan_bildirim.php');
$a = strpos($bb, "'ortak'    => [");
$b = strpos($bb, "\n        ],\n    ];", $a);
$blok = substr($bb, $a, $b - $a);
preg_match_all("/^            '(\w+)'\s*=>/m", $blok, $mm);
$bbAnahtar = $mm[1];
$bszAnahtar = array_keys(bsz_ortak_yurtdisi(['sifatId' => '7', 'turId' => '5', 'urunId' => '10', 'urunAd' => 'K', 'plaka' => 'P',
    'isletmeTuruId' => '3', 'ulkeId' => '20', 'ulkeAd' => 'R', 'kg' => 1, 'kayitId' => 9, 'partiNo' => 'X']));
sort($bbAnahtar); sort($bszAnahtar);
ok('yurt dışı ortak alan kümesi bb_taslak_kur ile AYNI', $bbAnahtar === $bszAnahtar, json_encode([$bbAnahtar, $bszAnahtar]));
$o = bsz_ortak_yurtdisi(['sifatId' => '7', 'turId' => '5', 'urunId' => '10', 'urunAd' => 'K', 'plaka' => 'P', 'isletmeTuruId' => '3', 'ulkeId' => '20', 'ulkeAd' => 'R', 'kg' => 1, 'kayitId' => 9, 'partiNo' => 'X']);
ok('yurt dışı: fiyatBekliyor=true, fiyat anahtarı YOK, kaynak.tip=beyansiz', $o['fiyatBekliyor'] === true && !array_key_exists('fiyat', $o) && $o['kaynak']['tip'] === 'beyansiz' && $o['kaynak']['kayitId'] === 9);
$ic = bsz_ortak_yurtici(['turId' => '5', 'turAd' => 'Satış', 'urunId' => '10', 'urunAd' => 'K', 'plaka' => 'P', 'kg' => 5, 'kayitId' => 3, 'partiNo' => '',
    'ikinciTc' => '12345678901', 'ikinciAd' => 'A', 'ikinciCep' => '0532', 'ikinciDogumTarihi' => '02.01.1980', 'ikinciSifatId' => '']);
ok('yurt içi: tamamlanacak + fiyatBekliyor + fiyatGonder, fiyat YOK',
   $ic['yurtIci'] === true && $ic['tamamlanacak'] === true && $ic['fiyatBekliyor'] === true && $ic['fiyatGonder'] === true && !array_key_exists('fiyat', $ic));
ok('yurt içi: boş ikinciSifatId anahtar koymaz; sifatId/isletmeTuruId/gidecek/il yok',
   !array_key_exists('ikinciSifatId', $ic) && !array_key_exists('sifatId', $ic) && !array_key_exists('isletmeTuruId', $ic)
   && !array_key_exists('gidecekIsyeriId', $ic) && !array_key_exists('ilId', $ic));
$ic2 = bsz_ortak_yurtici(['turId' => '5', 'urunId' => '10', 'urunAd' => 'K', 'plaka' => 'P', 'kg' => 5, 'kayitId' => 3, 'ikinciSifatId' => '12']);
ok('yurt içi: dolu ikinciSifatId taşınır', ($ic2['ikinciSifatId'] ?? null) === '12');

// ── Statik: API ──────────────────────────────────────────────────────────
echo "\n=== 7. api_beyansiz_yukleme.php statik ===\n";
$api = (string)file_get_contents($ROOT . '/api_beyansiz_yukleme.php');
ok('HKS gönderim sözcüğü yok (hks_bildirim_kaydet / taslak_gonder)', !str_contains($api, 'hks_bildirim_kaydet') && !str_contains($api, 'taslak_gonder') && !str_contains($api, 'bb_cikti'));
ok('csrf_check( yalnız olustur + sil (yazan) eylemlerinde', str_contains($api, 'csrf_check(') && substr_count($api, 'csrf_check(') === 2);
ok('istemciden ad/TC OKUNMAZ', !preg_match('/\$govde\[\'(urunAd|ulkeAd|firmaAd|tc)\'\]/', $api));
ok('hks_taslaklar\'a doğrudan INSERT yok, taslak yazma TEK yol hks_taslak_olustur', !preg_match('/INSERT\s+INTO\s+[`\'"]?\w*taslaklar/i', $api) && str_contains($api, 'hks_taslak_olustur('));
ok('kapı sırası: 401 → can_yukleme_bildir 403 → gövde', strpos($api, 'Oturum gerekli') < strpos($api, 'can_yukleme_bildir()') && strpos($api, 'can_yukleme_bildir()') < strpos($api, "php://input"));
ok('audit kişisel alan yazmaz', preg_match("/audit_log_event\('beyansiz_yukleme_olustur'.*?\]\);/s", $api, $am) === 1 && !preg_match('/karsi_kisi|Tc|Cep|dogum|kisi\[/', $am[0]));
ok('istek_kodu UNIQUE çakışması → 409 tekrar', str_contains($api, "'23000'") && str_contains($api, "'tekrar'"));
ok('hata durumunda satır DELETE edilir', preg_match('/DELETE FROM beyansiz_yukleme/', $api) === 1);
ok('SQL değerleri bind (kullanıcı verisi SQL\'e yapıştırılmıyor)', !preg_match('/\$(govde|plaka|partiNo|firmaId)\s*\./', preg_replace('/\/\/.*$/m', '', $api)));
$mod = (string)file_get_contents($ROOT . '/_yukleme_bildir_modal.php');
ok('modal: satır içi onclick yok, kullanıcı verisi textContent ile', !preg_match('/\sonclick=/i', $mod) && str_contains($mod, 'textContent'));
ok('modal: z-index ≥600 sınıfı (.beyan-hks-overlay)', str_contains($mod, 'beyan-hks-overlay'));


// ── 8) Uçtan uca: api_beyansiz_yukleme.php + GERÇEK hks_taslak_olustur() ──────
echo "\n=== 8. uçtan uca (alt süreç, dosya SQLite) ===\n";
$dbf = tempnam(sys_get_temp_dir(), 'bszdb');
$dp = new PDO('sqlite:' . $dbf);
$dp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    "CREATE TABLE hks_kv (anahtar TEXT PRIMARY KEY, deger TEXT)",
    "CREATE TABLE hks_firmalar (id TEXT PRIMARY KEY, ad TEXT, renk TEXT, user_name TEXT, password_enc TEXT, service_password_enc TEXT, vergi_no TEXT)",
    "CREATE TABLE hks_taslaklar (id VARCHAR(40) PRIMARY KEY, zaman TEXT, firma_id TEXT, firma_ad TEXT, veri TEXT)",
    "CREATE TABLE hks_kisiler (id INTEGER PRIMARY KEY AUTOINCREMENT, tc TEXT, ad TEXT, cep TEXT, dogum TEXT, sifat_id INT)",
    "CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, action TEXT, module TEXT, record_id INT, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT)",
    "INSERT INTO hks_firmalar VALUES ('f1','ASYA FRESH','teal','u','p','sp','1234567890')",
    "INSERT INTO hks_kisiler (tc,ad,cep,dogum,sifat_id) VALUES ('1234567890','VELİ GIDA LTD','05321112233','1980-01-02',NULL), ('12345678901','BOZUK KİŞİ','','',NULL)",
] as $q) $dp->exec($q);
$dp->prepare("INSERT INTO hks_kv VALUES ('listeler_cache', ?)")->execute([json_encode([
    'zaman' => '2026-11-01T10:00:00+03:00',
    'urunler' => [['id' => '10', 'ad' => 'Kayısı']], 'ulkeler' => [['id' => '20', 'ad' => 'Rusya']],
    'sifatlar' => [['id' => '7', 'ad' => 'İhracat']], 'bildirimTurleri' => [['id' => '5', 'ad' => 'Satış']],
    'isletmeTurleri' => [['id' => '3', 'ad' => 'Yurt Dışı'], ['id' => '4', 'ad' => 'Depo']],
], JSON_UNESCAPED_UNICODE)]);
$dp->exec("CREATE TABLE beyansiz_yukleme_geciciyok (x)");   // (tablo henüz yok: aşağıda migrate ile kurulacak)
$dp->exec("DROP TABLE beyansiz_yukleme_geciciyok");
function api_cagir(string $dbf, string $action, array $govde, string $izin = 'beyan.yukleme_bildir', bool $oturumsuz = false): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($dbf) . ' ' . escapeshellarg($action) . ' '
         . escapeshellarg(json_encode($govde, JSON_UNESCAPED_UNICODE)) . ' ' . escapeshellarg($izin) . ($oturumsuz ? ' oturumsuz' : '') . ' 2>&1';
    $o = (string)shell_exec($cmd);
    $j = json_decode($o, true);
    return is_array($j) ? $j : ['status' => 0, 'govde' => $o];
}
$g0 = ['csrf' => 'tok', 'firmaId' => 'f1', 'yon' => 'yurt_disi', 'ulkeId' => '20', 'urunId' => '10', 'kg' => '18.000', 'plaka' => '34 abc 123', 'partiNo' => 'P-1', 'istek_kodu' => 'k1'];
$r = api_cagir($dbf, 'olustur', $g0);
ok('tablo kurulu değilken olustur → 409 tabloYok', $r['status'] === 409 && !empty($r['govde']['tabloYok']), json_encode($r));
$dp2 = new PDO('sqlite:' . $dbf); $dp2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['PDO_E2E'] = $dp2;
$dp2->exec(beyansiz_yukleme_ddl($dp2));
$h = api_cagir($dbf, 'hazirla', []);
ok('hazirla: tabloVar + katalog + firmalar + son firma', $h['status'] === 200 && $h['govde']['tabloVar'] === true && count($h['govde']['firmalar']) === 1
   && count($h['govde']['ulkeler']) === 1 && count($h['govde']['kisiler']) === 1 && ($h['govde']['kisiler'][0]['ad'] ?? '') === 'VELİ GIDA LTD' && !isset($h['govde']['kisiler'][0]['tc']), json_encode($h));
ok('hazirla: kişi listesi YALNIZ firma (10 haneli VKN; kişi/TC yok), isletmeTurleri YOK, sonFirma YOK', !array_key_exists('isletmeTurleri', $h['govde']) && !array_key_exists('sonFirma', $h['govde']), json_encode($h['govde']['kisiler'] ?? null));
ok('kapı: oturumsuz → 401', api_cagir($dbf, 'hazirla', [], 'beyan.yukleme_bildir', true)['status'] === 401);
ok('kapı: yetkisiz (yalnız beyan.write) → 403, DB\'ye dokunmadan', api_cagir($dbf, 'olustur', $g0, 'beyan.write,beyan.read')['status'] === 403);
$r = api_cagir($dbf, 'olustur', array_merge($g0, ['csrf' => 'yanlis']));
ok('CSRF yoksa/yanlışsa → 403, kayıt oluşmaz', $r['status'] === 403 && (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn() === 0, json_encode($r));
$r = api_cagir($dbf, 'olustur', $g0);
ok('yurt dışı olustur → 200 + kayitId + taslakId', $r['status'] === 200 && !empty($r['govde']['tamam']) && !empty($r['govde']['taslakId']), json_encode($r));
$satir = $dp2->query("SELECT * FROM beyansiz_yukleme")->fetch(PDO::FETCH_ASSOC);
ok('satır: plaka boşluksuz/büyük, kg 18000, durum taslak, taslak_id bağlı, adlar katalogdan',
   $satir && $satir['plaka'] === '34ABC123' && (float)$satir['kg'] === 18000.0 && $satir['durum'] === 'taslak' && $satir['taslak_id'] === ($r['govde']['taslakId'] ?? null)
   && $satir['urun_ad'] === 'Kayısı' && $satir['ulke_ad'] === 'Rusya' && $satir['hks_firma_ad'] === 'ASYA FRESH' && $satir['yon'] === 'yurt_disi', json_encode($satir));
$tas = $dp2->query("SELECT veri FROM hks_taslaklar")->fetchColumn();
$tv = json_decode((string)$tas, true);
ok('taslak ortak: fiyatBekliyor=true, fiyat YOK, kaynak beyansiz+kayitId, planKg',
   ($tv['ortak']['fiyatBekliyor'] ?? null) === true && !array_key_exists('fiyat', $tv['ortak']) && ($tv['ortak']['kaynak']['tip'] ?? '') === 'beyansiz'
   && ($tv['ortak']['kaynak']['kayitId'] ?? 0) === (int)$satir['id'] && (float)$tv['ortak']['planKg'] === 18000.0 && $tv['satirlar'] === [], (string)$tas);
$au = (string)$dp2->query("SELECT new_values FROM audit_log WHERE action = 'beyansiz_yukleme_olustur'")->fetchColumn();
ok('audit yazıldı, kişisel veri yok', $au !== '' && !str_contains($au, 'ALİ') && !str_contains($au, '10000000146'), $au);
// Çift tıklama (aynı istek_kodu) → ikinci kayıt YOK
$r2 = api_cagir($dbf, 'olustur', array_merge($g0, ['plakaOnay' => true]));
ok('aynı istek_kodu tekrar → 409 tekrar, tek satır/tek taslak', $r2['status'] === 409 && ($r2['govde']['kod'] ?? '') === 'tekrar'
   && (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn() === 1 && (int)$dp2->query("SELECT COUNT(*) FROM hks_taslaklar")->fetchColumn() === 1, json_encode($r2));
// Aynı plaka, yeni istek_kodu
$r3 = api_cagir($dbf, 'olustur', array_merge($g0, ['istek_kodu' => 'k2']));
ok('aynı gün aynı plaka → 409 ayni_plaka + liste (onaysız)', $r3['status'] === 409 && ($r3['govde']['kod'] ?? '') === 'ayni_plaka' && count($r3['govde']['liste']) === 1, json_encode($r3));
$r3 = api_cagir($dbf, 'olustur', array_merge($g0, ['istek_kodu' => 'k2', 'plakaOnay' => true]));
ok('plakaOnay ile yeni istek → 200, ikinci satır', $r3['status'] === 200 && (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn() === 2, json_encode($r3));
ok('plaka_kontrol: bugün 2 kayıt', count(api_cagir($dbf, 'plaka_kontrol', ['plaka' => '34 abc 123'])['govde']['liste']) === 2);
// Doğrulama hataları
foreach ([
    'ürün katalogda yok' => [['urunId' => '999'], 400],
    'ülke katalogda yok' => [['ulkeId' => '999'], 400],
    'firma yok' => [['firmaId' => 'zz'], 400],
    'kg 0' => [['kg' => '0'], 400],
    'plaka boş' => [['plaka' => ' '], 400],
    'istek_kodu boş' => [['istek_kodu' => ''], 400],
] as $ad => [$deg, $beklenen]) {
    $n0 = (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn();
    $rr = api_cagir($dbf, 'olustur', array_merge($g0, ['istek_kodu' => 'kx' . substr(md5($ad), 0, 6), 'plakaOnay' => true], $deg));
    ok("doğrulama: $ad → $beklenen, kayıt oluşmaz", $rr['status'] === $beklenen && (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn() === $n0, json_encode($rr));
}
// Yurt içi
$gi = ['csrf' => 'tok', 'firmaId' => 'f1', 'yon' => 'yurt_ici', 'kisiId' => '1', 'urunId' => '10', 'kg' => '2500', 'plaka' => '06 ICI 22', 'istek_kodu' => 'ki1'];
$ri = api_cagir($dbf, 'olustur', $gi);
ok('yurt içi olustur → 200 (EKSİK taslak yazılabildi: gerçek hks_bildirim_dogrula eksikIzin)', $ri['status'] === 200 && !empty($ri['govde']['tamam']), json_encode($ri));
$ti = json_decode((string)$dp2->query("SELECT veri FROM hks_taslaklar ORDER BY zaman DESC, id DESC LIMIT 1")->fetchColumn(), true);
$orow = $dp2->query("SELECT * FROM beyansiz_yukleme WHERE yon = 'yurt_ici'")->fetch(PDO::FETCH_ASSOC);
ok('yurt içi taslak: tamamlanacak + fiyatBekliyor + TC/ad/cep/doğum havuzdan, sifatId/işyeri yok',
   ($ti['ortak']['tamamlanacak'] ?? null) === true && ($ti['ortak']['fiyatBekliyor'] ?? null) === true && ($ti['ortak']['ikinciTc'] ?? '') === '1234567890'
   && ($ti['ortak']['ikinciAd'] ?? '') === 'VELİ GIDA LTD' && ($ti['ortak']['ikinciDogumTarihi'] ?? '') === '02.01.1980'
   && !isset($ti['ortak']['sifatId']) && !isset($ti['ortak']['gidecekIsyeriId']) && !isset($ti['ortak']['fiyat'])
   && !array_key_exists('isletmeTuruId', $ti['ortak']), json_encode($ti['ortak'] ?? null));
$n0 = (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn();
// v500: kişi (11 haneli TC) seçilemez — yalnız firma; yeni firma = yalnız ad
$rn = api_cagir($dbf, 'olustur', array_merge($gi, ['kisiId' => '2', 'istek_kodu' => 'ki4', 'plaka' => '07 ISC 1']));
$rk0 = api_cagir($dbf, 'olustur', array_merge($gi, ['kisiId' => '', 'yeniFirmaAd' => 'AB', 'istek_kodu' => 'ki5', 'plaka' => '07 ISC 2']));
ok('yurt içi: kişi (TC) seçimi → 400; çok kısa yeni firma adı → 400; kayıt oluşmaz', $rn['status'] === 400 && $rk0['status'] === 400 && (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn() === $n0, json_encode([$rn, $rk0]));
$ry = api_cagir($dbf, 'olustur', array_merge($gi, ['kisiId' => '', 'yeniFirmaAd' => '  yeni   gıda ltd ', 'istek_kodu' => 'ki6', 'plaka' => '07 YNI 1']));
$ty = json_decode((string)$dp2->query("SELECT veri FROM hks_taslaklar ORDER BY zaman DESC, id DESC LIMIT 1")->fetchColumn(), true);
$yr = $dp2->query("SELECT * FROM beyansiz_yukleme WHERE plaka = '07YNI1'")->fetch(PDO::FETCH_ASSOC);
ok('yeni firma (yalnız ad) → 200: taslakta ikinciAd var, TC/cep YOK, tamamlanacak; satır karsi_kisi_id NULL',
   $ry['status'] === 200 && ($ty['ortak']['ikinciAd'] ?? '') === 'yeni gıda ltd' && ($ty['ortak']['ikinciTc'] ?? 'x') === '' && ($ty['ortak']['tamamlanacak'] ?? null) === true
   && $yr && $yr['karsi_kisi_id'] === null && $yr['karsi_kisi_ad'] === 'yeni gıda ltd', json_encode([$ry, $ty['ortak'] ?? null, $yr]));
ok('yurt içi satır: karsi_kisi_ad yalnız ad, TC/cep/doğum saklanmaz', $orow && $orow['karsi_kisi_ad'] === 'VELİ GIDA LTD' && (int)$orow['karsi_kisi_id'] === 1 && $orow['ulke_id'] === null
   && !in_array('tc', array_keys($orow), true), json_encode($orow));
$au = implode('|', $dp2->query("SELECT new_values FROM audit_log")->fetchAll(PDO::FETCH_COLUMN));
ok('audit: hiçbir kayıtta TC/ad/cep yok', !str_contains($au, '1234567890') && !str_contains($au, 'VELİ GIDA') && !str_contains($au, '0532'), $au);
// Yurt içi bozuk TC → hata ve satır temizlenir
$n0 = (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn();
$rb = api_cagir($dbf, 'olustur', array_merge($gi, ['kisiId' => '2', 'istek_kodu' => 'ki2', 'plaka' => '07 BOZ 1']));
ok('yurt içi kişi (11 haneli TC) → 400 (yalnız firma), kayıt oluşmaz', $rb['status'] === 400 && (int)$dp2->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn() === $n0 && str_contains((string)($rb['govde']['hata'] ?? ''), 'firma'), json_encode($rb));
$rk = api_cagir($dbf, 'olustur', array_merge($gi, ['kisiId' => '99', 'istek_kodu' => 'ki3', 'plaka' => '07 YOK 1']));
ok('yurt içi olmayan kişi → 400', $rk['status'] === 400);
// Gönderim köprüsü: isaretle (gerçek helpers) uçtan uca
$tid = (string)$satir['taslak_id'];
$cp = new PDO('sqlite:' . $dbf); $cp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['PDO_TEST'] = $cp;   // db() artık dosya DB'si
$cp->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
beyan_hks_taslak_isaretle($tid, 'gonderildi', ['gonderim_id' => 'gid-1', 'beyansizId' => (int)$satir['id']]);
ok('HKS gönderimi → beyansiz satırı gonderildi + gonderim_id', $cp->query("SELECT durum||'/'||gonderim_id FROM beyansiz_yukleme WHERE id = " . (int)$satir['id'])->fetchColumn() === 'gonderildi/gid-1');
// ── v501: SİL — yalnız "Bildirim Bekleyenler"; Yapılanlar (gönderilen/silinen/belirsiz) ASLA ──
$ydi = (int)$cp->query("SELECT id FROM beyansiz_yukleme WHERE yon='yurt_ici' ORDER BY id DESC LIMIT 1")->fetchColumn();   // taslağı canlı → bekleyen
$gid = (int)$satir['id'];                                                                                          // gönderildi
$cp->exec("INSERT INTO beyansiz_yukleme (hks_firma_id,yon,urun_id,urun_ad,kg,plaka,taslak_id,durum) VALUES ('f1','yurt_disi','10','K',1,'OLU1','tYok','taslak')");
$oluId = (int)$cp->lastInsertId();                                                                                  // taslağı ölü → belirsiz
$cp->exec("INSERT INTO beyansiz_yukleme (hks_firma_id,yon,urun_id,urun_ad,kg,plaka,taslak_id,durum) VALUES ('f1','yurt_disi','10','K',1,'SIL1','tS','silindi')");
$silmisId = (int)$cp->lastInsertId();
$sg = fn(int $id) => ['csrf' => 'tok', 'id' => $id];
ok('sil: yetkisiz (yalnız yukleme_bildir) → 403, kayıt durmaz', api_cagir($dbf, 'sil', $sg($ydi), 'beyan.yukleme_bildir')['status'] === 403
   && $cp->query("SELECT durum FROM beyansiz_yukleme WHERE id = $ydi")->fetchColumn() === 'taslak');
ok('sil: CSRF yanlış → 403', api_cagir($dbf, 'sil', ['csrf' => 'x', 'id' => $ydi], 'beyan.yukleme_sil')['status'] === 403);
foreach (['gönderilmiş' => $gid, 'taslağı ölü (belirsiz)' => $oluId, 'taslağı silinen' => $silmisId] as $ad => $id) {
    $once = $cp->query("SELECT durum FROM beyansiz_yukleme WHERE id = $id")->fetchColumn();
    $rs = api_cagir($dbf, 'sil', $sg($id), 'beyan.yukleme_sil');
    ok("sil: Yapılanlar ($ad) SİLİNEMEZ → 409, durum değişmez", $rs['status'] === 409 && $cp->query("SELECT durum FROM beyansiz_yukleme WHERE id = $id")->fetchColumn() === $once, json_encode($rs));
}
$tasId = (string)$cp->query("SELECT taslak_id FROM beyansiz_yukleme WHERE id = $ydi")->fetchColumn();
$rs = api_cagir($dbf, 'sil', $sg($ydi), 'beyan.yukleme_sil');
ok('sil: Bekleyen → 200; kayıt kaldirildi + Hal Kayıt taslağı silindi',
   $rs['status'] === 200 && !empty($rs['govde']['tamam']) && $cp->query("SELECT durum FROM beyansiz_yukleme WHERE id = $ydi")->fetchColumn() === 'kaldirildi'
   && (int)$cp->query("SELECT COUNT(*) FROM hks_taslaklar WHERE id = " . $cp->quote($tasId))->fetchColumn() === 0, json_encode($rs));
ok('sil: aynı kayıt tekrar → 409', api_cagir($dbf, 'sil', $sg($ydi), 'beyan.yukleme_sil')['status'] === 409);
$bol = bsz_bolumle('');
ok('silinen (kaldirildi) hiçbir listede/sayaçta yok', !in_array($ydi, array_column(array_merge($bol['bekleyen'], $bol['yapilan']), 'id')));
ok('sil audit: kişisel alan yok, yalnız id/durum', (string)$cp->query("SELECT new_values FROM audit_log WHERE action='beyansiz_yukleme_sil'")->fetchColumn() !== ''
   && !str_contains((string)$cp->query("SELECT group_concat(new_values||old_values) FROM audit_log WHERE action='beyansiz_yukleme_sil'")->fetchColumn(), 'GIDA'));
ok('sil: yukleme_bildir + beyan.write ile 403 (sil yetkisi ayrı)', api_cagir($dbf, 'sil', $sg($oluId), 'beyan.write,beyan.yukleme_bildir')['status'] === 403);
@unlink($dbf);

echo "\n" . ($fail === 0 ? "TUM TESTLER GECTI\n" : "$fail TEST BASARISIZ\n");
exit($fail === 0 ? 0 : 1);
