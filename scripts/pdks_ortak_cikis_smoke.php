<?php
// =========================================================
// scripts/pdks_ortak_cikis_smoke.php — v288 ORTAK ÇIKIŞ (çavuş seçmeden çıkış)
//
// pdks_gunluk_ortak_cikis_kaydet() kartın açık mesaisini BULUR ve yazmayı
// değiştirilmemiş pdks_gunluk_faz8a_cikis_kaydet()'e devreder. Bu test:
// doğru mesaiye yazma, depo/kapalı mesai/tanımsız kart reddi, dünden açık
// mesai (gece vardiyası), çavuş çavuş sayaç ve uç noktanın kapıları.
// Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_ortak_cikis_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$AKTIF_DEPO = 'Depo A';
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
function current_user(): ?array { return ['id' => 1]; }
function can(string $p): bool { return true; }
function is_admin(): bool { return true; }
$AUDIT = [];
function audit_log_event(string $a, string $m, int $id, ?array $o = null, ?array $n = null): void { global $AUDIT; $AUDIT[] = [$a, $m, $id]; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_faz8j.php';

// MySQL DDL → SQLite (pdks_gunluk_faz3_ui_smoke.php ile aynı çevirici)
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    [$tablo, $govde] = [$m[1], $m[2]];
    $parcalar = []; $buf = ''; $d = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $d++;
        if ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kol = []; $ix = [];
    $liste = fn(string $s) => preg_replace('/\s+/', ' ', trim(preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $s)));
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ix[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `$tablo` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ix[] = "CREATE INDEX `{$mm[1]}` ON `$tablo` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kol[] = $p;
    }
    return ["CREATE TABLE `$tablo` (\n  " . implode(",\n  ", $kol) . "\n)", $ix];
}

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` TEXT, `display_name` TEXT, `is_active` INTEGER DEFAULT 1)");
db()->exec("INSERT INTO users (id, username) VALUES (1, 'test')");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
foreach (pdks_gunluk_tablolar() as $sql) {
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
pdks_gunluk_migrate(db());
[$c, $ix] = pdks_ddl_sqlite(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
db()->exec($c); foreach ($ix as $x) db()->exec($x);
pdks_gunluk_faz8a_migrate(db());
pdks_faz8j_migrate(db());

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-86s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }

$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$cavusA = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Çavuş A'], 1, db())['id'];
$cavusB = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Çavuş B'], 1, db())['id'];
$cavusC = (int)pdks_gunluk_cavus_olustur(['code' => 'C003', 'name' => 'Çavuş C'], 1, db())['id'];
foreach (['K001' => '100000001', 'K002' => '100000002', 'K003' => '100000003', 'K004' => '100000004',
          'K005' => '100000005', 'K006' => '100000006'] as $no => $uid) {
    pdks_gunluk_kart_olustur(['card_no' => $no, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
}
function giris(string $uid, int $sid, int $tip): void {
    $r = pdks_gunluk_faz8a_giris_kaydet($uid, 'usb_decimal', $sid, $tip, 'auto', 1, db());
    if (empty($r['ok'])) throw new RuntimeException('giriş kurulamadı: ' . j($r));
}
function donem(string $uid): array {
    $st = db()->prepare("SELECT p.* FROM daily_worker_work_periods p JOIN worker_cards w ON w.id = p.worker_card_id WHERE w.canonical_uid = ? ORDER BY p.id DESC LIMIT 1");
    $st->execute([pdks_uid_from_decimal($uid)]);
    return $st->fetch() ?: [];
}

// Depo A: Çavuş A ve B bugün; Depo B: Çavuş C bugün.
$sA = (int)pdks_gunluk_oturum_ac_veya_getir($cavusA, 1, db())['session']['id'];
$sB = (int)pdks_gunluk_oturum_ac_veya_getir($cavusB, 1, db())['session']['id'];
giris('100000001', $sA, $kadin);
giris('100000002', $sA, $erkek);
giris('100000003', $sB, $kadin);
$AKTIF_DEPO = 'Depo B';
$sC = (int)pdks_gunluk_oturum_ac_veya_getir($cavusC, 1, db())['session']['id'];
giris('100000004', $sC, $kadin);
$AKTIF_DEPO = 'Depo A';

echo "\n=== 1. Doğru mesaiye yazma ===\n";
$r1 = pdks_gunluk_ortak_cikis_kaydet('100000001', 'usb_decimal', 1, db());
ok('K001 (Çavuş A) ortak çıkış başarılı', !empty($r1['ok']), j($r1));
ok('çıkış Çavuş A mesaisine yazıldı (cavus.session_id)', ($r1['cavus']['session_id'] ?? 0) === $sA && ($r1['cavus']['ad'] ?? '') === 'Çavuş A', j($r1['cavus'] ?? null));
ok('K001 dönemi kapandı (status=closed, exit_time dolu)', (donem('100000001')['status'] ?? '') === 'closed' && !empty(donem('100000001')['exit_time']));
ok('yanıt mevcut çıkış biçiminde (event_type=CIKIS, card, ozet)', ($r1['event_type'] ?? '') === 'CIKIS' && isset($r1['card']['card_no'], $r1['ozet']));
ok('bugünkü mesai → onceki_gun=false', ($r1['cavus']['onceki_gun'] ?? null) === false);
$r3 = pdks_gunluk_ortak_cikis_kaydet('100000003', 'usb_decimal', 1, db());
ok('K003 (Çavuş B) aynı uçtan çıkış — Çavuş B mesaisine yazıldı', !empty($r3['ok']) && ($r3['cavus']['session_id'] ?? 0) === $sB, j($r3));
$ev = (int)db()->query("SELECT COUNT(*) FROM daily_worker_card_events WHERE event_type='CIKIS'")->fetchColumn();
ok('ham CIKIS olayı tam 2 (her çıkış için bir)', $ev === 2, (string)$ev);
ok('audit gunluk_cikis yazıldı (mevcut yazma yolu)', count(array_filter($AUDIT, fn($a) => $a[0] === 'gunluk_cikis')) === 2);

echo "\n=== 2. Ret yolları ===\n";
$r = pdks_gunluk_ortak_cikis_kaydet('100000001', 'usb_decimal', 1, db());
ok('aynı kart ikinci okutma → acik_donem_yok', empty($r['ok']) && ($r['kod'] ?? '') === 'acik_donem_yok', j($r));
$r = pdks_gunluk_ortak_cikis_kaydet('100000005', 'usb_decimal', 1, db());
ok('hiç giriş yapmamış kart → acik_donem_yok', empty($r['ok']) && ($r['kod'] ?? '') === 'acik_donem_yok', j($r));
$kartSayisi = (int)db()->query("SELECT COUNT(*) FROM worker_cards")->fetchColumn();
$r = pdks_gunluk_ortak_cikis_kaydet('999999999', 'usb_decimal', 1, db());
ok('tanımsız kart → kart_tanimsiz', empty($r['ok']) && ($r['kod'] ?? '') === 'kart_tanimsiz', j($r));
ok('tanımsız kart otomatik KAYDEDİLMEDİ', (int)db()->query("SELECT COUNT(*) FROM worker_cards")->fetchColumn() === $kartSayisi);
$r = pdks_gunluk_ortak_cikis_kaydet('100000004', 'usb_decimal', 1, db());
ok('başka depodaki kart (Depo B / Çavuş C) → yanlis_depo, depo adıyla', empty($r['ok']) && ($r['kod'] ?? '') === 'yanlis_depo' && str_contains($r['hata'] ?? '', 'Depo B') && str_contains($r['hata'] ?? '', 'Çavuş C'), j($r));
ok('başka depodaki kartın dönemi AÇIK kaldı', (donem('100000004')['status'] ?? '') === 'open');
$r = pdks_gunluk_ortak_cikis_kaydet('', 'usb_decimal', 1, db());
ok('boş UID → bos_uid', ($r['kod'] ?? '') === 'bos_uid');
$r = pdks_gunluk_ortak_cikis_kaydet('100000002', 'uydurma', 1, db());
ok('geçersiz kaynak → gecersiz_kaynak', ($r['kod'] ?? '') === 'gecersiz_kaynak');

echo "\n=== 3. Kapalı mesai ===\n";
$kap = pdks_gunluk_oturum_kapat($sA, 'test: eksik çıkışla kapat', 1, db());
ok('Çavuş A mesaisi eksik çıkışla kapatıldı (kurulum)', !empty($kap['ok']), j($kap));
$r = pdks_gunluk_ortak_cikis_kaydet('100000002', 'usb_decimal', 1, db());
ok('kapalı mesaideki kart → acik_donem_yok + eksik çıkış uyarısı', empty($r['ok']) && ($r['kod'] ?? '') === 'acik_donem_yok' && str_contains($r['hata'] ?? '', 'eksik çıkış'), j($r));
ok('kapalı mesaideki dönem DEĞİŞMEDİ (open, exit_time yok)', (donem('100000002')['status'] ?? '') === 'open' && empty(donem('100000002')['exit_time']));
$r = pdks_gunluk_faz8a_cikis_kaydet('100000002', 'usb_decimal', $sA, 1, db());
ok('yazma yolu kapalı mesaiyi reddeder (yarış güvencesi: oturum_kapali)', ($r['kod'] ?? '') === 'oturum_kapali', j($r));

echo "\n=== 4. Dünden açık mesai (gece vardiyası — kullanıcı kararı: izin) ===\n";
$dun = date('Y-m-d', strtotime('-1 day'));
db()->prepare("UPDATE daily_work_sessions SET work_date = ? WHERE id = ?")->execute([$dun, $sB]);
giris('100000006', $sB, $erkek);
$r = pdks_gunluk_ortak_cikis_kaydet('100000006', 'usb_decimal', 1, db());
ok('dünkü açık mesaide içerideki kart çıkış yaptı', !empty($r['ok']) && ($r['cavus']['session_id'] ?? 0) === $sB, j($r));
ok('yanıt onceki_gun=true ve work_date=dün', ($r['cavus']['onceki_gun'] ?? null) === true && ($r['cavus']['work_date'] ?? '') === $dun, j($r['cavus'] ?? null));
ok('çıkış dünkü mesaiye yazıldı (dönem kapandı)', (donem('100000006')['status'] ?? '') === 'closed');

echo "\n=== 5. Çavuş çavuş sayaç (pdks_gunluk_ortak_cikis_mesailer) ===\n";
$m = pdks_gunluk_ortak_cikis_mesailer(null, db());
$ids = array_column($m, 'session_id');
ok('yalnız aktif depodaki AÇIK mesailer (A kapalı, C başka depo → yalnız B)', $ids === [$sB], j($m));
$b = $m[0] ?? [];
ok('Çavuş B: giriş 2 · çıkış 2 · içeride 0', ($b['giris'] ?? -1) === 2 && ($b['cikis'] ?? -1) === 2 && ($b['icerde'] ?? -1) === 0, j($b));
ok('Çavuş B dünkü mesai → onceki_gun=true', ($b['onceki_gun'] ?? null) === true);
$AKTIF_DEPO = 'Depo B';
$m2 = pdks_gunluk_ortak_cikis_mesailer(null, db());
ok('Depo B aktifken yalnız Çavuş C (içeride 1)', array_column($m2, 'session_id') === [$sC] && ($m2[0]['icerde'] ?? 0) === 1, j($m2));
$r = pdks_gunluk_ortak_cikis_kaydet('100000004', 'usb_decimal', 1, db());
ok('Depo B aktifken Çavuş C kartı çıkış yaptı', !empty($r['ok']) && ($r['cavus']['session_id'] ?? 0) === $sC, j($r));
$AKTIF_DEPO = 'Depo A';
// İptal (Faz 8J) edilmiş dönem sayılmaz
$AKTIF_DEPO = 'Depo B';
// Faz 8J iptali hakediş tablolarını ister; burada yalnız sayım kuralı test
// edildiği için is_voided bayrağı doğrudan işaretlenir.
db()->prepare("UPDATE daily_worker_work_periods SET is_voided = 1 WHERE id = ?")->execute([(int)donem('100000004')['id']]);
ok('iptal edilen (is_voided) dönem sayaçtan düşer (Çavuş C giriş 0)', (pdks_gunluk_ortak_cikis_mesailer(null, db())[0]['giris'] ?? -1) === 0);
$AKTIF_DEPO = '';
ok('aktif depo yoksa sayaç boş', pdks_gunluk_ortak_cikis_mesailer(null, db()) === []);
$r = pdks_gunluk_ortak_cikis_kaydet('100000002', 'usb_decimal', 1, db());
ok('aktif depo yokken çıkış yazılmaz', empty($r['ok']), j($r));
$AKTIF_DEPO = 'Depo A';

echo "\n=== 6. Uç nokta (statik) ===\n";
$src = (string)file_get_contents($ROOT . '/gunluk_isci_giris_cikis.php');
$uc = function (string $ad) use ($src): string {
    $a = strpos($src, "=== '$ad')");
    if ($a === false) return '';
    $b = strpos($src, "exit;\n}", $a);
    return substr($src, $a, $b - $a);
};
$ortak = $uc('ortak_cikis');
ok('ortak_cikis ucu var', $ortak !== '');
ok('ortak_cikis: CSRF kontrolü', str_contains($ortak, 'csrf_check('));
ok('ortak_cikis: daily_scan yetkisi', str_contains($ortak, "require_pdks_gunluk('daily_scan')"));
ok('ortak_cikis: istemciden session_id OKUMAZ', !str_contains($ortak, 'session_id'));
ok('ortak_cikis: yazma yalnız pdks_gunluk_ortak_cikis_kaydet() üzerinden', str_contains($ortak, 'pdks_gunluk_ortak_cikis_kaydet(') && !preg_match('/INSERT|UPDATE|_giris_kaydet|faz8a_cikis_kaydet/', $ortak));
$mes = $uc('ortak_mesailer');
ok('ortak_mesailer: CSRF + daily_scan', str_contains($mes, 'csrf_check(') && str_contains($mes, "require_pdks_gunluk('daily_scan')"));
$lib = (string)file_get_contents($ROOT . '/config/pdks_gunluk.php');
$a = strpos($lib, 'function pdks_gunluk_ortak_cikis_kaydet(');
$fn = substr($lib, $a, strpos($lib, 'function pdks_gunluk_ortak_cikis_mesailer(') - $a);
ok('ortak çıkış kendi INSERT/UPDATE YAZMAZ (tek yazma yolu korunur)', !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $fn));
ok('ortak çıkış yazmayı pdks_gunluk_faz8a_cikis_kaydet()\'e devreder', str_contains($fn, 'pdks_gunluk_faz8a_cikis_kaydet('));
ok('açık dönem kuralı paylaşılan fonksiyondan (kart_acik_donemi)', str_contains($fn, 'pdks_gunluk_faz8a_kart_acik_donemi('));
ok('JS: ortak modda istek ortak_cikis ucuna, session_id olmadan', str_contains($src, "? { csrf: csrf, ham_uid: deger, kaynak: kaynak }") && str_contains($src, "ortakMod ? 'gunluk_isci_giris_cikis.php?ajax=ortak_cikis' : 'gunluk_isci_giris_cikis.php?ajax=kaydet'"));

echo "\n" . ($fail ? "$fail HATA, $gecen geçti\n" : "Tümü geçti ($gecen)\n");
exit($fail ? 1 : 0);
