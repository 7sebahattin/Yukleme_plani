<?php
// =========================================================
// scripts/hks_parti_fatura_smoke.php — Hal Bildirimi Parti No + Fatura durumu (v526)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: bellek içi SQLite +
// GERÇEK fonksiyonlar (halkayit/tekrar_gonder_lib.php, config/beyansiz_yukleme.php,
// config/helpers.php). taslak_lib.php MySQL bağlantısı istediği için require
// edilemez — kullanılan iki fonksiyonu (hks_uret_sevk_mi, hks_bildirim_turu_kodu)
// ve hks_tr_normalize KAYNAKTAN ayıklanır. api.php'nin 'gonderilen_fatura' ve
// 'gonderilenler' case gövdeleri kaynaktan ayıklanıp GERÇEKTEN çalıştırılır
// (hks_json_cikti istisna ile yanıtı taşır). Örnekler sentetiktir.
//
// Kapsam: partiNo normalize + kaynak zorlaması · liste ucu partiNo/partiKaynak ·
// kopya beyaz listesinde partiNo YOK · fatura varsayılanı (yurt dışı true / yurt
// içi false / eski kayıt önek-tür) · gonderilen_fatura veri anahtarlarını korur +
// izolasyon + 404 + audit · oto beyan (yuklendi + bağ gonderildi) · oto beyansız
// (gonderildi, TC saklanmaz) · tekrar → mükerrer yok · alım türü / kaynaklı taslak /
// partiNo boş → oluşmaz · statik: muaf listede yok, eylem sınıfı 'edit', yerleşim.
//
//   php scripts/hks_parti_fatura_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/hks_parti_fatura_smoke.log');
$KOK = dirname(__DIR__);

$PDO_TEST = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function current_user(): ?array { return ['id' => 7, 'username' => 'ornek', 'display_name' => 'Örnek Kullanıcı']; }
define('HKS_TABLO_ON', 'hks_');
function hks_db() { return db(); }
function hks_tablo($ad) { return 'hks_' . $ad; }
function hks_kv_oku($a, $v = null) { return $v; }
function hks_kv_yaz($a, $v) {}

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : "\n    → $ipucu");
}
function yorumsuz(string $s): string {
    $o = '';
    foreach (token_get_all($s) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $o .= is_array($t) ? $t[1] : $t;
    }
    return $o;
}

require_once "$KOK/config/helpers.php";

// taslak_lib.php'den GERÇEK gövdeler (config → MySQL istediği için require edilemez).
$taslakSrc = (string)file_get_contents("$KOK/halkayit/taslak_lib.php");
$ayik = '';
foreach (['hks_tr_normalize', 'hks_uret_sevk_mi', 'hks_bildirim_turu_kodu'] as $fn) {
    if (!preg_match('/^function ' . $fn . '\(.*?^\}/ms', $taslakSrc, $m)) { fwrite(STDERR, "$fn bulunamadı\n"); exit(1); }
    $ayik .= $m[0] . "\n";
}
$__tmp = tempnam(sys_get_temp_dir(), 'hkspf');
file_put_contents($__tmp, "<?php\n" . $ayik);
require $__tmp;
@unlink($__tmp);

require_once "$KOK/halkayit/tekrar_gonder_lib.php";
require_once "$KOK/config/beyansiz_yukleme.php";

$db = db();
hks_kisi_tablo_hazirla($db);
foreach ([
    "CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, action TEXT, module TEXT, record_id INT, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT)",
    "CREATE TABLE hks_gonderilenler (id VARCHAR(40) PRIMARY KEY, zaman DATETIME, firma_id VARCHAR(40), firma_ad VARCHAR(200),
       plaka VARCHAR(30), belge_no VARCHAR(60), ulke_ad VARCHAR(200), urun_ad VARCHAR(150), adet INT, toplam_kg DECIMAL(12,3),
       fiyat DECIMAL(12,4), rusum DECIMAL(12,2), hata_sayisi INT, genel_hata TEXT, bildirim_turu VARCHAR(30), veri TEXT)",
    "CREATE TABLE hks_taslaklar (id VARCHAR(40) PRIMARY KEY, zaman DATETIME, firma_id VARCHAR(40), firma_ad VARCHAR(200), veri TEXT)",
    "CREATE TABLE customs_declarations (id INTEGER PRIMARY KEY AUTOINCREMENT, raw_text TEXT, unmatched_text TEXT, declaration_title TEXT,
       company_name TEXT, company_address TEXT, transport_type TEXT, vehicle_plate TEXT, tractor_plate TEXT, line_type TEXT, party_no TEXT,
       pallet_count INT, product_name TEXT, product_variety TEXT, gross_kg REAL, net_kg REAL, crate_count INT, crate_type TEXT,
       exit_depot TEXT, contact_person TEXT, buyer_name TEXT, brand TEXT, status TEXT NOT NULL DEFAULT 'beyan_acildi',
       analysis_note TEXT, sample_taken_at DATETIME, analysis_result_at DATETIME, loading_record_id INT, created_by INT,
       updated_by INT, created_at DATETIME, updated_at DATETIME, deleted_at DATETIME, hks_durum TEXT, note TEXT,
       sender_company_name TEXT, hks_firma_id TEXT, hks_urun_id TEXT, hks_urun_ad TEXT, hks_ulke_id TEXT, hks_ulke_ad TEXT)",
    "CREATE TABLE beyan_hks_bildirim (id INTEGER PRIMARY KEY AUTOINCREMENT, beyan_id INT NOT NULL, hks_firma_id TEXT, hks_firma_ad TEXT,
       taslak_id TEXT, gonderim_id TEXT, durum TEXT NOT NULL DEFAULT 'taslak', urun_id TEXT, urun_ad TEXT, ulke_id TEXT, ulke_ad TEXT,
       plaka TEXT, kg REAL, fiyat REAL, hata_metni TEXT, created_by INT, created_at DATETIME, updated_at DATETIME)",
] as $ddl) $db->exec($ddl);

// ── 1. partiNo normalize + kaynak zorlaması ─────────────────────────────────
echo "\n=== 1. partiNo (taslak) ===\n";
ok('normalize: trim + kontrol karakteri temizlenir', hks_parti_no_normalize("  P-12\x00\x07\u{200B}  ") === 'P-12');
ok('normalize: iç boşluk teklenir, satır sonu temizlenir', hks_parti_no_normalize("A  B\n C\t") === 'A B C');
ok('normalize: en çok 60 karakter (çok baytlı)', mb_strlen(hks_parti_no_normalize(str_repeat('Ş', 80))) === 60);
ok('normalize: dizi/null → boş', hks_parti_no_normalize(['x']) === '' && hks_parti_no_normalize(null) === '');
ok('normalize: sayı kabul', hks_parti_no_normalize(1234) === '1234');
$o = hks_taslak_parti_uygula(['urunAd' => 'K', 'partiNo' => '  P-7 ']);
ok('elle: partiNo normalize edilip yazılır', ($o['partiNo'] ?? null) === 'P-7');
$o = hks_taslak_parti_uygula(['urunAd' => 'K', 'partiNo' => " \x01 "]);
ok('elle: boş partiNo → anahtar KALDIRILIR', !array_key_exists('partiNo', $o));
$o = hks_taslak_parti_uygula(['partiNo' => 'ISTEMCI', 'kaynak' => ['tip' => 'beyan', 'beyanId' => 3, 'partiNo' => 'BEYAN-1']]);
ok('beyan kaynaklı: kaynak.partiNo ZORLANIR (istemci yok sayılır)', ($o['partiNo'] ?? null) === 'BEYAN-1');
$o = hks_taslak_parti_uygula(['partiNo' => 'ISTEMCI', 'kaynak' => ['tip' => 'beyansiz', 'kayitId' => 4, 'partiNo' => '']]);
ok('beyansız kaynaklı + kaynakta parti boş → istemci değeri yine yok, anahtar yok', !array_key_exists('partiNo', $o));
$o = hks_taslak_parti_uygula(['partiNo' => 'ISTEMCI', 'kaynak' => ['tip' => 'baska']]);
ok('tanınmayan kaynak tipi zorlamaz', ($o['partiNo'] ?? null) === 'ISTEMCI');
ok('liste: partiNo = ortak.partiNo', hks_taslak_parti_no(['partiNo' => 'A1', 'kaynak' => ['tip' => 'beyan', 'partiNo' => 'B1']]) === 'A1');
ok('liste: ortak.partiNo yoksa kaynak.partiNo (eski beyan taslağı)', hks_taslak_parti_no(['kaynak' => ['tip' => 'beyan', 'partiNo' => 'B1']]) === 'B1');
ok('liste: ikisi de yoksa ""', hks_taslak_parti_no([]) === '');
ok('liste: partiKaynak beyan/beyansiz/""', hks_taslak_parti_kaynak_tipi(['kaynak' => ['tip' => 'beyan']]) === 'beyan'
    && hks_taslak_parti_kaynak_tipi(['kaynak' => ['tip' => 'beyansiz']]) === 'beyansiz' && hks_taslak_parti_kaynak_tipi([]) === '');
ok('kopya beyaz listesinde partiNo YOK (yeni sevkiyat = yeni parti)', !in_array('partiNo', hks_kopya_ortak_anahtarlari(), true));
$k = hks_gonderim_kopyasi(['ortak' => ['urunAd' => 'K', 'partiNo' => 'P-9'], 'satirlar' => []]);
ok('hks_gonderim_kopyasi partiNo taşımaz', is_array($k) && !array_key_exists('partiNo', $k['ortak']));

// ── 2. fatura varsayılanı ───────────────────────────────────────────────────
echo "\n=== 2. fatura varsayılanı ===\n";
ok('veri.yurtDisi=true → kesildi', hks_fatura_kesildi_mi([], ['yurtDisi' => true]) === true);
ok('veri.yurtDisi=false → kesilmedi', hks_fatura_kesildi_mi([], ['yurtDisi' => false]) === false);
ok('kopya (yurtIci yok) → yurt dışı → kesildi', hks_fatura_kesildi_mi(['ulke_ad' => 'Yurt içi → X'], ['kopya' => ['v' => 1, 'ortak' => ['urunAd' => 'K']]]) === true);
ok('kopya yurtIci=true → kesilmedi', hks_fatura_kesildi_mi([], ['kopya' => ['v' => 1, 'ortak' => ['yurtIci' => true]]]) === false);
ok('eski kayıt: öneksiz ülke, tür NULL → ihracat → kesildi', hks_fatura_kesildi_mi(['ulke_ad' => 'Rusya', 'bildirim_turu' => null], []) === true);
ok('eski kayıt: "Yurt içi →" önek → kesilmedi', hks_fatura_kesildi_mi(['ulke_ad' => 'Yurt içi → Örnek Firma', 'fiyat' => 5], []) === false);
ok('eski kayıt: Satın Alım öneki → kesilmedi', hks_fatura_kesildi_mi(['ulke_ad' => 'Satın Alım ← Örnek'], []) === false);
ok('eski kayıt: bildirim_turu SEVK_ETME → kesilmedi', hks_fatura_kesildi_mi(['ulke_ad' => 'X', 'bildirim_turu' => 'SEVK_ETME'], []) === false);
ok('eski kayıt: SATIS + yurt içi öneki → kesilmedi', hks_fatura_kesildi_mi(['ulke_ad' => 'Yurt içi → Y', 'bildirim_turu' => 'SATIS'], []) === false);
ok('eski kayıt: SATIS öneksiz → kesildi', hks_fatura_kesildi_mi(['ulke_ad' => 'Irak', 'bildirim_turu' => 'SATIS'], []) === true);
ok('açık kayıt varsayılanı ezer (yurt dışı ama kesildi=false)', hks_fatura_kesildi_mi([], ['yurtDisi' => true, 'fatura' => ['kesildi' => false]]) === false);
ok('açık kayıt (yurt içi, kesildi=true)', hks_fatura_kesildi_mi([], ['yurtDisi' => false, 'fatura' => ['kesildi' => true]]) === true);
ok('bozuk fatura kaydı (bool değil) → varsayılan', hks_fatura_kesildi_mi([], ['yurtDisi' => false, 'fatura' => ['kesildi' => 'evet']]) === false);
$v = hks_fatura_veri_isle(['a' => 1, 'kopya' => ['v' => 1]], true, ['id' => 3, 'display_name' => 'Ad', 'password' => 'x'], '2026-11-14 10:00:00');
ok('veri_isle: diğer anahtarlar korunur, fatura = {kesildi, zaman, kullanici{id,ad}}', $v['a'] === 1 && $v['kopya'] === ['v' => 1]
    && $v['fatura'] === ['kesildi' => true, 'zaman' => '2026-11-14 10:00:00', 'kullanici' => ['id' => 3, 'ad' => 'Ad']]);

// ── 3. api.php gerçek case gövdeleri: gonderilenler + gonderilen_fatura ─────
echo "\n=== 3. api.php gonderilenler / gonderilen_fatura (çalıştırılır) ===\n";
$api = (string)file_get_contents("$KOK/halkayit/api.php");
function case_govdesi(string $api, string $ad): string {
    $p = strpos($api, "    case '$ad': {");
    if ($p === false) return '';
    $son = strpos($api, "\n    case '", $p + 10);
    if ($son === false) $son = strpos($api, "\n    default:", $p);
    $g = substr($api, $p, $son - $p);
    $bas = strlen("    case '$ad': {");
    $kap = strrpos($g, "\n    }");
    return $kap === false ? '' : substr($g, $bas, $kap - $bas);
}
final class HksYanit extends Exception { public array $veri; public int $kod;
    public function __construct(array $v, int $k) { parent::__construct('yanit'); $this->veri = $v; $this->kod = $k; } }
function hks_json_cikti($veri, $kod = 200) { throw new HksYanit((array)$veri, (int)$kod); }
$GOVDE = ['gonderilen_fatura' => case_govdesi($api, 'gonderilen_fatura'), 'gonderilenler' => case_govdesi($api, 'gonderilenler')];
ok('case gövdeleri ayıklandı', $GOVDE['gonderilen_fatura'] !== '' && $GOVDE['gonderilenler'] !== '');
function uc(string $ad, array $g): array {
    global $GOVDE;
    $db = db();
    $__hks_user = current_user();
    try { eval($GOVDE[$ad]); } catch (HksYanit $y) { return [$y->kod, $y->veri]; }
    return [0, []];
}
$ins = $db->prepare('INSERT INTO hks_gonderilenler (id, zaman, firma_id, firma_ad, plaka, belge_no, ulke_ad, urun_ad, adet, toplam_kg, fiyat, rusum, hata_sayisi, genel_hata, bildirim_turu, veri)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$Z = date('Y-m-d H:i:s');
$ins->execute(['g1', $Z, 'f1', 'Firma A', '34AB1', '', 'Rusya', 'KAYISI', 1, 20000, 5, 1, 0, null, 'SATIS',
    json_encode(['yeniKunyeler' => ['k1'], 'sonuclar' => [], 'kopya' => ['v' => 1, 'ortak' => ['urunAd' => 'KAYISI']], 'gonderen' => ['id' => 1, 'ad' => 'X'],
                 'partiNo' => 'P-100', 'yurtDisi' => true], JSON_UNESCAPED_UNICODE)]);
$ins->execute(['g2', $Z, 'f1', 'Firma A', '34AB2', '', 'Yurt içi → Örnek Alıcı', 'ELMA', 1, 9000, 4, 1, 0, null, 'SATIS',
    json_encode(['yeniKunyeler' => ['k2'], 'sonuclar' => [], 'partiNo' => 'P-200', 'yurtDisi' => false], JSON_UNESCAPED_UNICODE)]);
$ins->execute(['g3', $Z, '', 'Firma A', '34AB3', '', 'Irak', 'NAR', 1, 5000, 3, 1, 0, null, null,
    json_encode(['yeniKunyeler' => ['k3'], 'sonuclar' => []], JSON_UNESCAPED_UNICODE)]);   // eski kayıt (firma_id yok)
$ins->execute(['g4', $Z, 'f2', 'Firma B', '34AB4', '', 'Rusya', 'NAR', 1, 5000, 3, 1, 0, null, 'SATIS',
    json_encode(['partiNo' => 'P-400', 'yurtDisi' => true], JSON_UNESCAPED_UNICODE)]);

[$kod, $y] = uc('gonderilenler', ['firmaId' => 'f1', 'firmaAd' => 'Firma A']);
$byId = []; foreach ($y['gonderilenler'] ?? [] as $r) $byId[$r['id']] = $r;
ok('liste: 200 + izolasyon (f1 + firma_id\'siz eski kayıt, f2 YOK)', $kod === 200 && isset($byId['g1'], $byId['g2'], $byId['g3']) && !isset($byId['g4']), json_encode(array_keys($byId)));
ok('liste: partiNo döner (eski kayıtta "")', ($byId['g1']['partiNo'] ?? null) === 'P-100' && ($byId['g2']['partiNo'] ?? null) === 'P-200' && ($byId['g3']['partiNo'] ?? null) === '');
ok('liste: faturaKesildi varsayılanı (yurt dışı true / yurt içi false / eski ihracat true)',
    ($byId['g1']['faturaKesildi'] ?? null) === true && ($byId['g2']['faturaKesildi'] ?? null) === false && ($byId['g3']['faturaKesildi'] ?? null) === true);
ok('liste: kopyaVar / gonderen biçimi bozulmadı', ($byId['g1']['kopyaVar'] ?? null) === true && ($byId['g1']['gonderen'] ?? null) === 'X');

[$kod, $y] = uc('gonderilen_fatura', ['firmaId' => 'f1', 'firmaAd' => 'Firma A', 'id' => 'g1', 'kesildi' => false]);
ok('fatura: 200 + {tamam, faturaKesildi:false}', $kod === 200 && $y === ['tamam' => true, 'faturaKesildi' => false], json_encode([$kod, $y]));
$v1 = json_decode((string)$db->query("SELECT veri FROM hks_gonderilenler WHERE id='g1'")->fetchColumn(), true);
ok('fatura: veri.fatura yazıldı (kesildi=false, zaman, kullanici{id,ad})', ($v1['fatura']['kesildi'] ?? null) === false
    && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string)($v1['fatura']['zaman'] ?? '')) === 1
    && ($v1['fatura']['kullanici'] ?? null) === ['id' => 7, 'ad' => 'Örnek Kullanıcı']);
ok('fatura: diğer veri anahtarları KORUNDU (yeniKunyeler, kopya, gonderen, partiNo, yurtDisi)',
    ($v1['yeniKunyeler'] ?? null) === ['k1'] && ($v1['kopya']['ortak']['urunAd'] ?? null) === 'KAYISI'
    && ($v1['gonderen']['ad'] ?? null) === 'X' && ($v1['partiNo'] ?? null) === 'P-100' && ($v1['yurtDisi'] ?? null) === true);
$au = $db->query("SELECT module, record_id, old_values, new_values FROM audit_log WHERE action='hks_fatura_durum'")->fetch();
ok('fatura: audit hks_fatura_durum / hks_gonderim, eski=true yeni=false, kişisel veri yok', is_array($au) && $au['module'] === 'hks_gonderim'
    && $au['record_id'] === null && json_decode((string)$au['old_values'], true) === ['kesildi' => true]
    && json_decode((string)$au['new_values'], true) === ['gonderim_id' => 'g1', 'kesildi' => false], json_encode($au));
[$kod, $y] = uc('gonderilenler', ['firmaId' => 'f1', 'firmaAd' => 'Firma A']);
$byId = []; foreach ($y['gonderilenler'] ?? [] as $r) $byId[$r['id']] = $r;
ok('liste: açık kayıt varsayılanı ezer (g1 artık false)', ($byId['g1']['faturaKesildi'] ?? null) === false);
[$kod, $y] = uc('gonderilen_fatura', ['firmaId' => 'f1', 'firmaAd' => 'Firma A', 'id' => 'g2', 'kesildi' => 'true']);
ok('fatura: "true" metni bool kabul edilir (yurt içi → kesildi)', $kod === 200 && $y['faturaKesildi'] === true);
[$kod, $y] = uc('gonderilen_fatura', ['firmaId' => 'f1', 'firmaAd' => 'Firma A', 'id' => 'g3', 'kesildi' => false]);
ok('fatura: firma_id\'siz eski kayıt firma ADIYLA güncellenir', $kod === 200
    && json_decode((string)$db->query("SELECT veri FROM hks_gonderilenler WHERE id='g3'")->fetchColumn(), true)['fatura']['kesildi'] === false);
$v4once = (string)$db->query("SELECT veri FROM hks_gonderilenler WHERE id='g4'")->fetchColumn();
[$kod, $y] = uc('gonderilen_fatura', ['firmaId' => 'f1', 'firmaAd' => 'Firma A', 'id' => 'g4', 'kesildi' => false]);
ok('fatura: başka firmanın kaydı → 404, DEĞİŞMEZ', $kod === 404 && (string)$db->query("SELECT veri FROM hks_gonderilenler WHERE id='g4'")->fetchColumn() === $v4once);
[$kod, $y] = uc('gonderilen_fatura', ['firmaId' => 'f1', 'firmaAd' => 'Firma A', 'id' => 'yok', 'kesildi' => true]);
ok('fatura: olmayan kayıt → 404', $kod === 404);
[$kod, $y] = uc('gonderilen_fatura', ['firmaId' => 'f1', 'firmaAd' => 'Firma A', 'id' => 'g1', 'kesildi' => 'belki']);
ok('fatura: geçersiz kesildi → 400', $kod === 400);
[$kod, $y] = uc('gonderilen_fatura', ['firmaId' => '', 'id' => 'g1', 'kesildi' => true]);
ok('fatura: firma yok → 400', $kod === 400);

// ── 4. oto beyan / beyansız ─────────────────────────────────────────────────
echo "\n=== 4. elle parti no → beyan / beyansız ===\n";
$T = ['id' => 't100', 'firma_id' => 'f1', 'firma_ad' => 'Firma A', 'zaman' => $Z];
$disi = ['partiNo' => 'p-55', 'urunId' => '10', 'urunAd' => 'Kayısı', 'plaka' => '34 ab 55', 'ulkeId' => '20', 'ulkeAd' => 'Rusya',
         'bildirimTuruId' => '5', 'turAd' => 'Satış', 'fiyat' => 12.5, 'sifatId' => '3'];
$r = hks_partili_gonderim_beyana_isle($disi, $T, 'g900', 19500.0, 7);
ok('yurt dışı: beyan oluşturuldu', $r['tip'] === 'beyan' && $r['id'] > 0, json_encode($r));
$b = $db->query('SELECT * FROM customs_declarations WHERE id = ' . (int)$r['id'])->fetch() ?: [];
ok('beyan: durum yuklendi ("Kapanan Beyanlar")', ($b['status'] ?? '') === 'yuklendi' && in_array('yuklendi', beyan_kapali_durumlar(), true));
ok('beyan: party_no / product_name / net_kg / plaka', ($b['party_no'] ?? '') === 'P-55' && ($b['product_name'] ?? '') === 'KAYISI'
    && (float)($b['net_kg'] ?? 0) === 19500.0 && ($b['vehicle_plate'] ?? '') === '34AB55', json_encode($b));
ok('beyan: hks firma/ürün/ülke + gönderici + not + created_by + hks_durum', ($b['hks_firma_id'] ?? '') === 'f1' && ($b['hks_urun_id'] ?? '') === '10'
    && ($b['hks_urun_ad'] ?? '') === 'Kayısı' && ($b['hks_ulke_id'] ?? '') === '20' && ($b['hks_ulke_ad'] ?? '') === 'Rusya'
    && ($b['sender_company_name'] ?? '') === 'Firma A' && ($b['note'] ?? '') === 'Hal Bildiriminden otomatik oluşturuldu'
    && (int)($b['created_by'] ?? 0) === 7 && ($b['hks_durum'] ?? '') === 'gonderildi');
$bag = $db->query('SELECT * FROM beyan_hks_bildirim WHERE beyan_id = ' . (int)$r['id'])->fetchAll();
ok('bağ: tek satır, durum gonderildi + gonderim_id + taslak_id', count($bag) === 1 && $bag[0]['durum'] === 'gonderildi'
    && $bag[0]['gonderim_id'] === 'g900' && $bag[0]['taslak_id'] === 't100' && (float)$bag[0]['fiyat'] === 12.5);
ok('bağ: beyan aktif bildirimli (yeniden "bildirime uygun" görünmez)', (beyan_hks_aktif((int)$r['id'])['durum'] ?? '') === 'gonderildi');
$au = (string)$db->query("SELECT new_values FROM audit_log WHERE action='beyan_oto_olustur' AND module='declarations'")->fetchColumn();
ok('audit beyan_oto_olustur (kişisel veri yok)', $au !== '' && str_contains($au, 'g900') && !preg_match('/ikinci|tc"|cep|dogum/i', $au), $au);
$r2 = hks_partili_gonderim_beyana_isle($disi, $T, 'g901', 100.0, 7);
ok('tekrar (aynı parti + ürün, farklı gönderim) → mükerrer beyan YOK', $r2['tip'] === '' && (int)$db->query("SELECT COUNT(*) FROM customs_declarations")->fetchColumn() === 1);
$r3 = hks_partili_gonderim_beyana_isle(['urunId' => '11', 'urunAd' => 'Nar'] + $disi, $T, 'g902', 100.0, 7);
ok('aynı parti + BAŞKA ürün → yeni beyan', $r3['tip'] === 'beyan' && (int)$db->query("SELECT COUNT(*) FROM customs_declarations")->fetchColumn() === 2);

$sayB = fn() => (int)$db->query("SELECT COUNT(*) FROM customs_declarations")->fetchColumn();
$once = $sayB();
ok('kaynaklı taslak → oluşmaz', hks_partili_gonderim_beyana_isle(['partiNo' => 'K-1', 'kaynak' => ['tip' => 'beyan', 'beyanId' => 1]] + $disi, $T, 'g903', 1.0, 7)['tip'] === '' && $sayB() === $once);
ok('partiNo boş → oluşmaz', hks_partili_gonderim_beyana_isle(['partiNo' => '  '] + $disi, $T, 'g904', 1.0, 7)['tip'] === '' && $sayB() === $once);
ok('Satın Alım (referanssız) → oluşmaz', hks_partili_gonderim_beyana_isle(['partiNo' => 'A-1', 'yurtIci' => true, 'referanssiz' => true, 'turAd' => 'Satın Alım'] + $disi, $T, 'g905', 1.0, 7)['tip'] === '');
ok('Üreticiden Sevk Alım → oluşmaz', hks_partili_gonderim_beyana_isle(['partiNo' => 'A-2', 'yurtIci' => true, 'uretSevk' => true, 'turAd' => 'Üreticiden Sevk Alım'] + $disi, $T, 'g906', 1.0, 7)['tip'] === '');
ok('tür adı "alım" içeriyorsa (bayraksız) → oluşmaz', hks_partili_gonderim_beyana_isle(['partiNo' => 'A-3', 'turAd' => 'Satın Alım'] + $disi, $T, 'g907', 1.0, 7)['tip'] === '' && $sayB() === $once);
ok('bsz_oto_cikis_yonlu_mu: Satış / Sevk Etme evet', bsz_oto_cikis_yonlu_mu(['turAd' => 'Satış']) && bsz_oto_cikis_yonlu_mu(['yurtIci' => true, 'turAd' => 'Sevk Etme']));

// Yurt içi: beyansız tablo YOKKEN sessiz atlanır
$ici = ['partiNo' => 'YI-1', 'yurtIci' => true, 'fiyatGonder' => true, 'urunId' => '12', 'urunAd' => 'Elma', 'plaka' => '06 xy 9',
        'turAd' => 'Satış', 'ikinciTc' => '1234567890', 'ikinciAd' => '', 'ulkeAd' => 'Yurt içi → 1234567890'];
ok('yurt içi: beyansiz_yukleme tablosu yok → sessiz atlanır', hks_partili_gonderim_beyana_isle($ici, $T, 'g950', 9000.0, 7) === ['tip' => '', 'id' => 0]);
beyansiz_yukleme_migrate($db);
$db->prepare('INSERT INTO hks_kisiler (tc, ad, cep, olusturma) VALUES (?,?,?,?)')->execute(['1234567890', 'Örnek Alıcı Ltd', '', $Z]);
$r = hks_partili_gonderim_beyana_isle($ici, $T, 'g950', 9000.0, 7);
ok('yurt içi: beyansız satır oluşturuldu', $r['tip'] === 'beyansiz' && $r['id'] > 0, json_encode($r));
$s = $db->query('SELECT * FROM beyansiz_yukleme WHERE id = ' . (int)$r['id'])->fetch() ?: [];
ok('beyansız: durum gonderildi + gonderim_id + parti + yon yurt_ici', ($s['durum'] ?? '') === 'gonderildi' && ($s['gonderim_id'] ?? '') === 'g950'
    && ($s['parti_no'] ?? '') === 'YI-1' && ($s['yon'] ?? '') === 'yurt_ici', json_encode($s));
ok('beyansız: firma, ürün, kg, plaka', ($s['hks_firma_id'] ?? '') === 'f1' && ($s['hks_firma_ad'] ?? '') === 'Firma A' && ($s['urun_id'] ?? '') === '12'
    && ($s['urun_ad'] ?? '') === 'Elma' && (float)($s['kg'] ?? 0) === 9000.0 && ($s['plaka'] ?? '') === '06XY9');
ok('beyansız: karşı taraf adı Kişi Havuzu\'ndan (ikinciAd boş), id bağlı', ($s['karsi_kisi_ad'] ?? '') === 'Örnek Alıcı Ltd' && (int)($s['karsi_kisi_id'] ?? 0) > 0);
ok('beyansız: TC SAKLANMAZ (hiçbir kolonda)', !str_contains(json_encode($s, JSON_UNESCAPED_UNICODE), '1234567890'));
$bol = bsz_bolumle('');
ok('beyansız: "Bildirim Yapılanlar"da görünür (goster=gonderildi)', count(array_filter($bol['yapilan'], fn($x) => (int)$x['id'] === $r['id'] && $x['goster'] === 'gonderildi')) === 1);
$auB = (string)$db->query("SELECT new_values FROM audit_log WHERE action='beyan_oto_olustur' AND module='beyansiz_yukleme'")->fetchColumn();
ok('audit beyan_oto_olustur (beyansız; TC/ad yok)', $auB !== '' && !str_contains($auB, '1234567890') && !str_contains($auB, 'Örnek Alıcı'), $auB);
$say = fn() => (int)$db->query("SELECT COUNT(*) FROM beyansiz_yukleme")->fetchColumn();
$n = $say();
ok('tekrar aynı gönderim → mükerrer YOK', hks_partili_gonderim_beyana_isle($ici, $T, 'g950', 9000.0, 7)['tip'] === '' && $say() === $n);
ok('aynı parti + ürün başka gönderim → mükerrer YOK', hks_partili_gonderim_beyana_isle($ici, $T, 'g951', 9000.0, 7)['tip'] === '' && $say() === $n);
$r = hks_partili_gonderim_beyana_isle(['partiNo' => 'YI-2', 'ikinciAd' => 'Elle Yazılan Ad', 'ikinciTc' => ''] + $ici, $T, 'g952', 100.0, null);
$s = $db->query('SELECT * FROM beyansiz_yukleme WHERE id = ' . (int)$r['id'])->fetch() ?: [];
ok('yurt içi: ikinciAd doluysa o yazılır; kullanıcısız created_by NULL', $r['tip'] === 'beyansiz' && ($s['karsi_kisi_ad'] ?? '') === 'Elle Yazılan Ad' && $s['created_by'] === null);
ok('yurt içi Sevk Etme (fiyatsız) da işlenir', hks_partili_gonderim_beyana_isle(['partiNo' => 'YI-3', 'fiyatGonder' => false, 'turAd' => 'Sevk Etme'] + $ici, $T, 'g953', 100.0, 7)['tip'] === 'beyansiz');
ok('yurt içi Satın Alım → oluşmaz', hks_partili_gonderim_beyana_isle(['partiNo' => 'YI-4', 'referanssiz' => true, 'turAd' => 'Satın Alım'] + $ici, $T, 'g954', 1.0, 7)['tip'] === '');
ok('yurt içi kaynaklı (beyansiz) → oluşmaz', hks_partili_gonderim_beyana_isle(['partiNo' => 'YI-5', 'kaynak' => ['tip' => 'beyansiz', 'kayitId' => 1]] + $ici, $T, 'g955', 1.0, 7)['tip'] === '');
// Hata yutulur: tablo bozuk olsa da istisna dışarı çıkmaz
$db->exec('DROP TABLE beyan_hks_bildirim');
$atti = false; $sonuc = null;
try { $sonuc = hks_partili_gonderim_beyana_isle(['partiNo' => 'Z-1', 'urunId' => '99'] + $disi, $T, 'g999', 1.0, 7); } catch (Throwable $e) { $atti = true; }
ok('bağ tablosu yokken: istisna YUTULUR, yarım beyan kalmaz (rollback)', !$atti && $sonuc === ['tip' => '', 'id' => 0]
    && (int)$db->query("SELECT COUNT(*) FROM customs_declarations WHERE party_no = 'Z-1'")->fetchColumn() === 0);

// ── 5. statik ───────────────────────────────────────────────────────────────
echo "\n=== 5. statik denetim ===\n";
$apiK = yorumsuz($api);
$muaf = [];
if (preg_match('/^function hks_csrf_muaf_eylemler\(\): array \{.*?^\}/ms', $api, $m)) { eval($m[0]); $muaf = hks_csrf_muaf_eylemler(); }
ok('gonderilen_fatura CSRF muaf listesinde DEĞİL', $muaf !== [] && !in_array('gonderilen_fatura', $muaf, true));
if (preg_match('/^function hks_eylem_sinifi\(string \$action, array \$g\): string \{.*?^\}/ms', $api, $m)) eval($m[0]);
ok("hks_eylem_sinifi('gonderilen_fatura') === 'edit'", function_exists('hks_eylem_sinifi') && hks_eylem_sinifi('gonderilen_fatura', []) === 'edit');
ok('gonderilen_fatura gövdesi: SOAP/gönderim/taslak yazımı YOK, yalnız veri kolonu', !preg_match('/hks_bildirim_kaydet|hks_soap|hks_taslak_olustur|hks_kv_yaz/', $GOVDE['gonderilen_fatura'])
    && (bool)preg_match("/UPDATE ' \. hks_tablo\('gonderilenler'\) \. ' SET veri = \?\s/", $GOVDE['gonderilen_fatura']));
ok('gonderilen_fatura izolasyonu gonderilenler ile aynı', substr_count($GOVDE['gonderilen_fatura'], "(firma_id = ? OR (COALESCE(firma_id, \\'\\') = \\'\\' AND firma_ad = ?))") === 2);
$pTk = strpos($apiK, "case 'taslak_kaydet':");
$pKay = strpos($apiK, "\$g['ortak']['kaynak'] = \$eskiVeri['ortak']['kaynak'];", (int)$pTk);
$pPar = strpos($apiK, "\$g['ortak'] = hks_taslak_parti_uygula(\$g['ortak']);", (int)$pTk);
$pOlus = strpos($apiK, '$sonuc = hks_taslak_olustur($g);', (int)$pTk);
ok('taslak_kaydet: parti uygulaması kaynak aktarımından SONRA, hks_taslak_olustur\'dan ÖNCE', $pKay !== false && $pPar !== false && $pOlus !== false && $pKay < $pPar && $pPar < $pOlus);
$pG = strpos($api, "case 'taslak_gonder':");
$pGs = strpos($api, "case 'gonderilenler':", (int)$pG);
$tg = substr($api, (int)$pG, (int)$pGs - (int)$pG);
$pIns = strpos($tg, "INSERT INTO ' . hks_tablo('gonderilenler')");
$pKopru = strpos($tg, "beyan_hks_taslak_isaretle((string)\$t['id'], 'gonderildi'");
$pOto = strpos($tg, 'hks_partili_gonderim_beyana_isle($ortak, $t, $gid, (float)$toplamKg');
ok('taslak_gonder: oto kayıt INSERT ve beyan köprüsünden SONRA', $pIns !== false && $pKopru !== false && $pOto !== false && $pIns < $pKopru && $pKopru < $pOto);
ok("taslak_gonder: veri'ye partiNo + yurtDisi; fatura INSERT'te YAZILMAZ", str_contains($tg, "'partiNo' => hks_taslak_parti_no(\$ortak), 'yurtDisi' => empty(\$ortak['yurtIci'])") && !str_contains($tg, "'fatura'"));
ok("taslak_gonder: 'ayni_plaka' / 'hks_gonderilen_' / audit_log_event( yok", !str_contains($tg, 'ayni_plaka') && !str_contains($tg, 'hks_gonderilen_') && !str_contains($tg, 'audit_log_event('));
ok("taslak_gonder yanıtı otoKayit taşır", str_contains($tg, "'otoKayit' => \$__otoKayit"));
ok('api.php config/beyansiz_yukleme.php\'yi yükler', str_contains($api, "require_once __DIR__ . '/../config/beyansiz_yukleme.php';"));
$tl = strpos($api, "case 'taslaklar':"); $tlS = strpos($api, "case 'taslak_kaydet':", (int)$tl);
$tlG = substr($api, (int)$tl, (int)$tlS - (int)$tl);
ok("taslaklar ucu partiNo + partiKaynak döner, yazmaz", str_contains($tlG, "'partiNo' => hks_taslak_parti_no(") && str_contains($tlG, "'partiKaynak' => hks_taslak_parti_kaynak_tipi(")
    && !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', $tlG));
$soap = (string)file_get_contents("$KOK/halkayit/hks_soap.php");
ok('hks_soap.php partiNo okumaz (HKS\'e GİTMEZ)', !str_contains($soap, 'partiNo'));
$libK = yorumsuz((string)file_get_contents("$KOK/halkayit/tekrar_gonder_lib.php"));
ok('tekrar_gonder_lib: yeni yardımcılar hks_gonderilen_ ile BAŞLAMAZ', !preg_match('/function\s+hks_gonderilen_(fatura|parti)/', $libK)
    && str_contains($libK, 'function hks_fatura_yurtdisi_mi(array $row, array $veri): bool'));
$bszK = yorumsuz((string)file_get_contents("$KOK/config/beyansiz_yukleme.php"));
ok('oto audit kişisel alan yazmaz (ikinci*/tc/karsi yok)', preg_match("/audit_log_event\('beyan_oto_olustur'.*?\]\);/s", $bszK, $am) === 1
    && !preg_match('/ikinci|karsi|\btc\b|cep|dogum/i', $am[0]));

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
