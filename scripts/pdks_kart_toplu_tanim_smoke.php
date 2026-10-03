<?php
// =========================================================
// scripts/pdks_kart_toplu_tanim_smoke.php — v299 SERİ KART TANIMLA
//
// Kart Havuzu'nda çavuş + tip seçilip kartlar art arda okutulur, liste kontrol
// edilir, tek Kaydet ile hepsi tanımlanır (yeni kartlar havuza otomatik eklenir).
// Bu test: değerlendirme/sınıflama (yeni · tanımlanacak · ayni · başka çavuş ·
// hata), HEP-YA-HİÇ tek transaction (hata varsa ne kart ne tanım), istek_id
// tekrarı, kart no sıralı tahsisi, limit, rollback bütünlüğü ve statik
// "ikinci yazma yolu yok" sözleşmeleri.
// Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_kart_toplu_tanim_smoke.php   → çıkış kodu 0 = tüm testler geçti
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
function audit_log_event(string $a, string $m, ?int $id, ?array $o = null, ?array $n = null): void { global $AUDIT; $AUDIT[] = [$a, $m, $id, $o, $n]; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';

// MySQL DDL → SQLite (pdks_ortak_cikis_smoke.php ile aynı çevirici)
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
function sqlite_kur(string $mysql): void { [$c, $ix] = pdks_ddl_sqlite($mysql); db()->exec($c); foreach ($ix as $x) db()->exec($x); }

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` TEXT, `display_name` TEXT, `is_active` INTEGER DEFAULT 1)");
db()->exec("INSERT INTO users (id, username) VALUES (1, 'test')");
foreach (pdks_tablolar() as $ad => $sql) {
    if (in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) sqlite_kur($sql);
}
foreach (pdks_gunluk_tablolar() as $sql) sqlite_kur($sql);
pdks_gunluk_migrate(db());
sqlite_kur(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
pdks_gunluk_faz8a_migrate(db());
db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-96s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function say(string $sql, array $p = []): int { $st = db()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
function kartId(string $no): int { return say("SELECT id FROM worker_cards WHERE card_no = ?", [$no]); }
function donem(string $no): array {
    $st = db()->prepare("SELECT p.* FROM daily_worker_work_periods p WHERE p.worker_card_id = ? ORDER BY p.id DESC LIMIT 1");
    $st->execute([kartId($no)]);
    return $st->fetch() ?: [];
}
function mesaiSayisi(int $foremanId): int { return say("SELECT COUNT(*) FROM daily_work_sessions WHERE foreman_id = ?", [$foremanId]); }
function tanimli(string $uid): array { return pdks_gunluk_tanimli_giris_kaydet($uid, 'usb_decimal', 1, db()); }
function normal(string $uid, int $sid, int $tip): array { return pdks_gunluk_faz8a_giris_kaydet($uid, 'usb_decimal', $sid, $tip, 'auto', 1, db()); }
function tanimYaz(string $no, int $cavus, int $tip, string $depo = 'Depo A'): array { return pdks_gunluk_kart_tanim_kaydet(kartId($no), $cavus, $tip, $depo, 1, db()); }

function toplu(array $satirlar, int $cavus, int $tip, string $istek = '', string $depo = 'Depo A'): array {
    static $n = 0;
    if ($istek === '') $istek = str_pad(dechex(++$n), 16, 'a', STR_PAD_LEFT);
    return pdks_gunluk_kart_tanim_toplu_kaydet($satirlar, $cavus, $tip, $depo, $istek, 1, db());
}
function usb(string $uid): array { return ['ham_uid' => $uid, 'kaynak' => 'usb_decimal']; }
function kartSayisi(): int { return say("SELECT COUNT(*) FROM worker_cards"); }
function tanimSayisi(): int { return say("SELECT COUNT(*) FROM worker_card_assignments"); }
function aktifTanim(string $no): ?array { return pdks_gunluk_kart_tanim_aktif(db(), kartId($no)); }

$kadin   = say("SELECT id FROM worker_types WHERE code='KADIN'");
$erkek   = say("SELECT id FROM worker_types WHERE code='ERKEK'");
$karisik = say("SELECT id FROM worker_types WHERE code='KARISIK'");
$cavus = [];
foreach (['A', 'B', 'P'] as $i => $h) {
    $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT), 'name' => 'Çavuş ' . $h], 1, db())['id'];
}
pdks_gunluk_cavus_aktiflik($cavus['P'], false, 1, db());
for ($i = 1; $i <= 10; $i++) {
    pdks_gunluk_kart_olustur(['card_no' => 'K' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'ham_uid' => (string)(100000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
}
function uid(int $i): string { return (string)(100000000 + $i); }

echo "\n=== 0. Tablo YOKKEN ===\n";
$r = toplu([usb('200000001')], $cavus['A'], $kadin);
ok('tanım tablosu yokken toplu kaydet → gecersiz (şema), hiçbir kart yazılmadı', ($r['kod'] ?? '') === 'gecersiz' && kartSayisi() === 10, j($r));

sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);
$AUDIT = [];

echo "\n=== 1. Saf yardımcılar ===\n";
ok('uid_kanonik: usb_decimal / geçersiz / boş / kötü kaynak',
    pdks_gunluk_uid_kanonik('100000001', 'usb_decimal') === pdks_uid_from_decimal('100000001')
    && pdks_gunluk_uid_kanonik('abc', 'usb_decimal') === null
    && pdks_gunluk_uid_kanonik('', 'usb_decimal') === null
    && pdks_gunluk_uid_kanonik('100000001', 'kotu') === null);
$b = pdks_gunluk_kart_tanim_satir_bilgi(db(), uid(1), 'usb_decimal');
ok('satir_bilgi: mevcut kart (var, K001, tanımsız, kalıcı yok)', $b['ok'] && $b['exists'] && $b['card_no'] === 'K001' && $b['tanim'] === null && !$b['kalici_cakisma'], j($b));
$b = pdks_gunluk_kart_tanim_satir_bilgi(db(), '299999999', 'usb_decimal');
ok('satir_bilgi: havuzda olmayan UID → exists=false', $b['ok'] && !$b['exists'] && $b['card_no'] === null, j($b));
$b = pdks_gunluk_kart_tanim_satir_bilgi(db(), 'x1', 'usb_decimal');
ok('satir_bilgi: geçersiz UID → ok=false', !$b['ok'] && $b['kod'] === 'gecersiz_uid', j($b));
$bas = pdks_gunluk_kart_tanim_toplu_baslik(db(), $cavus['A'], $kadin, 'Depo A');
ok('başlık geçerli → null', $bas === null, (string)$bas);
ok('başlık: pasif çavuş / Karışık tip / boş depo / olmayan çavuş reddedilir',
    pdks_gunluk_kart_tanim_toplu_baslik(db(), $cavus['P'], $kadin, 'Depo A') !== null
    && pdks_gunluk_kart_tanim_toplu_baslik(db(), $cavus['A'], $karisik, 'Depo A') !== null
    && pdks_gunluk_kart_tanim_toplu_baslik(db(), $cavus['A'], $kadin, '  ') !== null
    && pdks_gunluk_kart_tanim_toplu_baslik(db(), 99999, $kadin, 'Depo A') !== null);

echo "\n=== 2. Başarı yolu: yeni + mevcut + zaten tanımlı (tek transaction) ===\n";
tanimYaz('K002', $cavus['A'], $kadin);           // K002 zaten Çavuş A / Kadın / Depo A
$K = kartSayisi(); $T = tanimSayisi();
$AUDIT = [];
$r = toplu([usb('200000001'), usb(uid(1)), usb(uid(2)), usb('200000002')], $cavus['A'], $kadin, 'a1b2c3d4e5f60708');
ok('kaydedildi: 4 kart (2 yeni, 2 tanımlanan, 1 zaten)', !empty($r['ok']) && $r['toplam'] === 4 && $r['yeni'] === 2 && $r['tanimlanan'] === 3 && $r['ayni'] === 1, j($r));
ok('2 yeni kart havuza eklendi, kart no SIRALI (K011, K012) ve okutma sırasıyla',
    kartSayisi() === $K + 2
    && db()->query("SELECT canonical_uid FROM worker_cards WHERE card_no='K011'")->fetchColumn() === pdks_uid_from_decimal('200000001')
    && db()->query("SELECT canonical_uid FROM worker_cards WHERE card_no='K012'")->fetchColumn() === pdks_uid_from_decimal('200000002'));
ok('yeni kartlar NÖTR (tip yok), available, kaynak usb_decimal', say("SELECT COUNT(*) FROM worker_cards WHERE card_no IN ('K011','K012') AND worker_type_id IS NULL AND status='available' AND enrolled_source='usb_decimal'") === 2);
ok('hepsi Çavuş A / Kadın / Depo A tanımlı (K001, K002, K011, K012)',
    array_reduce(['K001', 'K002', 'K011', 'K012'], fn($c, $no) => $c && ($t = aktifTanim($no)) && (int)$t['foreman_id'] === $cavus['A'] && (int)$t['worker_type_id'] === $kadin && $t['depo'] === 'Depo A', true));
ok('K002 (zaten tanımlı) için yeni tanım satırı AÇILMADI; toplam +3 tanım', say("SELECT COUNT(*) FROM worker_card_assignments WHERE worker_card_id = ?", [kartId('K002')]) === 1 && tanimSayisi() === $T + 3);
$st = db()->query("SELECT * FROM audit_log WHERE action='kart_tanim_toplu'");
$ozet = $st->fetchAll();
$nv = $ozet ? json_decode($ozet[0]['new_values'], true) : [];
ok('özet audit kart_tanim_toplu: 1 satır, modül worker_cards, istek_id + toplu_id + kart listesi',
    count($ozet) === 1 && $ozet[0]['module'] === 'worker_cards' && ($nv['istek_id'] ?? '') === 'a1b2c3d4e5f60708' && str_starts_with((string)($nv['toplu_id'] ?? ''), 'KT')
    && count($nv['kartlar'] ?? []) === 4 && (int)$nv['yeni'] === 2 && (int)$nv['tanimlanan'] === 3 && (int)$nv['ayni'] === 1, j($ozet));
ok('audit: mevcut yol da çalıştı (create worker_cards x2 + kart_tanim x3)',
    count(array_filter($AUDIT, fn($a) => $a[0] === 'create' && $a[1] === 'worker_cards')) === 2
    && count(array_filter($AUDIT, fn($a) => $a[0] === 'kart_tanim')) === 3, j(array_map(fn($a) => $a[0], $AUDIT)));
ok('transaction kapandı', !db()->inTransaction());

echo "\n=== 3. istek_id tekrarı (çift gönderim) ===\n";
$K = kartSayisi(); $T = tanimSayisi(); $A = say("SELECT COUNT(*) FROM audit_log");
$r = toplu([usb('200000001'), usb('200000003')], $cavus['A'], $kadin, 'a1b2c3d4e5f60708');
ok('aynı istek_id → tekrar, HİÇBİR ŞEY yazılmadı (kart/tanım/audit)', ($r['kod'] ?? '') === 'tekrar' && !empty($r['tekrar']) && kartSayisi() === $K && tanimSayisi() === $T && say("SELECT COUNT(*) FROM audit_log") === $A, j($r));
$r = toplu([usb('200000003')], $cavus['A'], $kadin, 'XYZ');
ok('geçersiz istek_id biçimi → istek_gecersiz', ($r['kod'] ?? '') === 'istek_gecersiz' && kartSayisi() === $K, j($r));

echo "\n=== 4. Başka çavuşa tanımlı → ENGEL, hiçbir şey yazılmaz ===\n";
tanimYaz('K003', $cavus['B'], $erkek);
$K = kartSayisi(); $T = tanimSayisi(); $A = say("SELECT COUNT(*) FROM audit_log");
$r = toplu([usb('200000010'), usb(uid(3)), usb(uid(4))], $cavus['A'], $kadin);
$T += 0;
ok('başka çavuşa tanımlı kart → hatali_satir (baska_cavus), satır bazlı hata', ($r['kod'] ?? '') === 'hatali_satir' && ($r['satirlar'][1]['sinif'] ?? '') === 'baska_cavus' && str_contains((string)$r['satirlar'][1]['hata'], 'Çavuş B / Erkek\'e') && str_contains((string)$r['satirlar'][1]['hata'], 'Tanımı Kaldır'), j($r));
ok('diğer satırlar sınıflanmış: yeni + tanimlanacak', ($r['satirlar'][0]['sinif'] ?? '') === 'yeni' && ($r['satirlar'][2]['sinif'] ?? '') === 'tanimlanacak');
ok('HİÇBİR kart/tanım/audit yazılmadı; K003 hâlâ Çavuş B', kartSayisi() === $K && tanimSayisi() === $T + 0 && say("SELECT COUNT(*) FROM audit_log") === $A && (int)aktifTanim('K003')['foreman_id'] === $cavus['B'] && aktifTanim('K004') === null);

echo "\n=== 5. Hata satırları (kalıcı kart, geçersiz, kayıp, devre dışı, kartsız, yinelenen) ===\n";
$kalKanonik = pdks_uid_from_decimal('300000001');
db()->exec("INSERT INTO employees (id, full_name) VALUES (1, 'Test Personel')");
db()->exec("INSERT INTO employee_cards (id, employee_id, uid_hex, status) VALUES (1, 1, '$kalKanonik', 'aktif')");
db()->exec("INSERT INTO employee_card_uids (id, card_id, uid_hex) VALUES (1, 1, '$kalKanonik')");
pdks_gunluk_kart_durum_degistir(kartId('K005'), 'lost', 1, db());
pdks_gunluk_kart_durum_degistir(kartId('K006'), 'disabled', 1, db());
db()->prepare("INSERT INTO worker_cards (card_no, worker_type_id, canonical_uid, uid_bytes, enrolled_source, status) VALUES ('KARTSIZ-000999', ?, ?, 0, 'kartsiz', 'disabled')")->execute([$kadin, 'KARTSIZABC']);
$K = kartSayisi(); $T = tanimSayisi(); $A = say("SELECT COUNT(*) FROM audit_log");
$sat = [usb('300000001'), usb('abc'), usb(uid(5)), usb(uid(6)), usb(uid(7)), usb(uid(7)), ['ham_uid' => pdks_uid_from_decimal(uid(8)), 'kaynak' => 'nfc_hex'], usb(uid(8))];
$r = toplu($sat, $cavus['A'], $kadin);
$sn = array_column($r['satirlar'] ?? [], 'kod', 'idx');
ok('kalıcı personel kartı → hata (kalici_kart)', ($sn[0] ?? '') === 'kalici_kart');
ok('geçersiz UID → hata (gecersiz_uid)', ($sn[1] ?? '') === 'gecersiz_uid');
ok('KAYIP kart → hata (kart_kayip)', ($sn[2] ?? '') === 'kart_kayip');
ok('DEVRE DIŞI kart → hata (kart_devre_disi)', ($sn[3] ?? '') === 'kart_devre_disi');
ok('yinelenen UID (aynı ham) → ikincisi hata (yinelenen), ilki geçerli', ($sn[5] ?? '') === 'yinelenen' && ($r['satirlar'][4]['sinif'] ?? '') === 'tanimlanacak', j($r['satirlar'] ?? null));
ok('yinelenen UID (nfc_hex + usb_decimal, aynı kanonik) → hata', ($sn[7] ?? '') === 'yinelenen' && ($r['satirlar'][6]['sinif'] ?? '') === 'tanimlanacak');
ok('sonuç: hatali_satir + hiçbir şey yazılmadı', ($r['kod'] ?? '') === 'hatali_satir' && kartSayisi() === $K && tanimSayisi() === $T && say("SELECT COUNT(*) FROM audit_log") === $A);
$ks = pdks_gunluk_kart_tanim_toplu_degerlendir(db(), [usb(uid(9)), ['ham_uid' => 'KARTSIZABC', 'kaynak' => 'nfc_hex']], $cavus['A'], $kadin, 'Depo A');
ok('kartsız sanal kart (kanonik KARTSIZ…) okutulamaz → hata', !$ks['ok'] && $ks['satirlar'][1]['sinif'] === 'hata', j($ks));
$kb = pdks_gunluk_kart_tanim_satir_bilgi(db(), 'x', 'usb_decimal');
$kartsizB = ['ok' => true, 'exists' => true, 'kartsiz' => true, 'status' => 'disabled', 'card_no' => 'KARTSIZ-000999', 'tanim' => null, 'kalici_cakisma' => false];
ok('sinifla: kartsız sanal kart → hata (kartsiz_kart)', pdks_gunluk_kart_tanim_satir_sinifla($kartsizB, $cavus['A'], $kadin, 'Depo A')['kod'] === 'kartsiz_kart');

echo "\n=== 6. Başlık / limit ===\n";
$K = kartSayisi();
$r = toplu([usb(uid(9))], $cavus['P'], $kadin);
ok('pasif çavuş → gecersiz', ($r['kod'] ?? '') === 'gecersiz' && kartSayisi() === $K, j($r));
$r = toplu([usb(uid(9))], $cavus['A'], $karisik);
ok('Karışık tip → gecersiz', ($r['kod'] ?? '') === 'gecersiz', j($r));
$r = toplu([usb(uid(9))], $cavus['A'], $kadin, '', '');
ok('boş depo → gecersiz', ($r['kod'] ?? '') === 'gecersiz', j($r));
$r = toplu([], $cavus['A'], $kadin);
ok('boş liste → gecersiz', ($r['kod'] ?? '') === 'gecersiz', j($r));
$yuzOn = []; for ($i = 1; $i <= 101; $i++) $yuzOn[] = usb((string)(400000000 + $i));
$r = toplu($yuzOn, $cavus['A'], $kadin);
ok('101 satır → gecersiz (limit 100), hiçbir kart yazılmadı', ($r['kod'] ?? '') === 'gecersiz' && kartSayisi() === $K && str_contains((string)$r['hata'], '100'), j($r));
$yuz = array_slice($yuzOn, 0, 100);
$r = toplu($yuz, $cavus['A'], $kadin);
ok('tam 100 satır kabul: 100 yeni kart + 100 tanım, kart no ardışık', !empty($r['ok']) && $r['yeni'] === 100 && $r['tanimlanan'] === 100 && kartSayisi() === $K + 100, j($r));
ok('100 kartta kart no benzersiz ve sıralı (K013…K112)', say("SELECT COUNT(DISTINCT card_no) FROM worker_cards") === kartSayisi() && kartId('K013') > 0 && kartId('K112') === kartId('K013') + 99);

echo "\n=== 7. Rollback bütünlüğü (ortada yazma hatası) ===\n";
db()->exec("CREATE TRIGGER t_boom BEFORE INSERT ON worker_card_assignments WHEN NEW.worker_card_id = (SELECT id FROM worker_cards WHERE card_no = 'K008') BEGIN SELECT RAISE(ABORT, 'boom'); END");
$K = kartSayisi(); $T = tanimSayisi(); $A = say("SELECT COUNT(*) FROM audit_log");
$r = toplu([usb('500000001'), usb('500000002'), usb(uid(7)), usb(uid(8))], $cavus['A'], $kadin);
ok('ortada hata (K008 tanımı yazılırken) → yazma_reddi, "hiçbir kart yazılmadı", transaction kapalı', ($r['kod'] ?? '') === 'yazma_reddi' && str_contains((string)$r['hata'], 'hiçbir kart yazılmadı') && !db()->inTransaction(), j($r));
ok('ROLLBACK: yeni kartlar YOK, K007/K008 tanımsız, audit değişmedi', kartSayisi() === $K && tanimSayisi() === $T && aktifTanim('K007') === null && aktifTanim('K008') === null
    && say("SELECT COUNT(*) FROM worker_cards WHERE card_no IN ('K113','K114')") === 0 && say("SELECT COUNT(*) FROM audit_log") === $A);
db()->exec("DROP TRIGGER t_boom");
$r = toplu([usb('500000001'), usb('500000002'), usb(uid(7)), usb(uid(8))], $cavus['A'], $kadin);
ok('sorun giderilince aynı liste yeni anahtarla yazılır; kart no K113, K114 (rollback numarayı tüketmedi)', !empty($r['ok']) && kartId('K113') > 0 && kartId('K114') > 0 && kartId('K115') === 0, j($r));

echo "\n=== 8. Çavuş/tip/depo değişimi ve zaten-tanımlı ===\n";
$r = toplu([usb(uid(1))], $cavus['A'], $erkek);
ok('aynı çavuş, farklı tip → tanım GÜNCELLENİR (geçmiş korunur)', !empty($r['ok']) && $r['tanimlanan'] === 1 && (int)aktifTanim('K001')['worker_type_id'] === $erkek && say("SELECT COUNT(*) FROM worker_card_assignments WHERE worker_card_id = ?", [kartId('K001')]) === 2, j($r));
$T = tanimSayisi();
$r = toplu([usb(uid(1))], $cavus['A'], $erkek, '', 'DEPO A');
ok('hepsi zaten tanımlı (depo harf farkı) → ok, ayni=1, yeni satır YOK', !empty($r['ok']) && $r['ayni'] === 1 && $r['tanimlanan'] === 0 && tanimSayisi() === $T, j($r));
$sA = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['A'], 1, db())['session']['id'];
$g = pdks_gunluk_faz8a_giris_kaydet(uid(9), 'usb_decimal', $sA, $kadin, 'auto', 1, db());
$r = toplu([usb(uid(9))], $cavus['B'], $kadin);
ok('kart başka çavuşun açık mesaisinde içeride → tanım yazılır + uyarilar döner (engel değil)', !empty($g['ok']) && !empty($r['ok']) && count($r['uyarilar']) === 1 && $r['uyarilar'][0]['card_no'] === 'K009', j([$g, $r]));
ok('açık dönem eski çavuşta KALDI (tanım yalnız bir sonraki girişi etkiler)', (int)donem('K009')['session_id'] === $sA && donem('K009')['status'] === 'open');

echo "\n=== 9. Kaynak sözleşmeleri (statik) ===\n";
$lib = (string)file_get_contents($ROOT . '/config/pdks_gunluk.php');
$fnGovde = function (string $src, string $ad): string {
    $a = strpos($src, 'function ' . $ad . '(');
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a);
};
$tk = $fnGovde($lib, 'pdks_gunluk_kart_tanim_toplu_kaydet');
ok('toplu_kaydet var', $tk !== '');
ok('gövdede worker_cards / worker_card_assignments\'a DOĞRUDAN INSERT/UPDATE/DELETE YOK', !preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(worker_cards|worker_card_assignments)\b/i', $tk));
ok('yazma MEVCUT yollardan: pdks_gunluk_kart_olustur + pdks_gunluk_kart_tanim_kaydet', str_contains($tk, 'pdks_gunluk_kart_olustur(') && str_contains($tk, 'pdks_gunluk_kart_tanim_kaydet('));
ok('değerlendirme KİLİT ALTINDA yeniden çağrılır (kilitle → degerlendir)', strpos($tk, 'pdks_gunluk_faz8a_kart_kilitle(') !== false && strrpos($tk, 'pdks_gunluk_kart_tanim_toplu_degerlendir(') > strpos($tk, 'pdks_gunluk_faz8a_kart_kilitle('));
ok('tek transaction: beginTransaction 1, commit 1', substr_count($tk, 'beginTransaction()') === 1 && substr_count($tk, '->commit()') === 1);
ok('istek_id kontrolü transaction İÇİNDE (beginTransaction sonrası)', strrpos($tk, 'pdks_gunluk_kart_tanim_toplu_istek_kayitli(') > strpos($tk, 'beginTransaction()'));
$au = $fnGovde($lib, 'pdks_gunluk_kart_tanim_toplu_audit');
ok('özet audit doğrudan INSERT + JSON_THROW_ON_ERROR (audit_log_event hata yutar)', str_contains($au, 'INSERT INTO audit_log') && str_contains($au, 'JSON_THROW_ON_ERROR') && !str_contains($tk, 'audit_log_event('));
$dg = $fnGovde($lib, 'pdks_gunluk_kart_tanim_toplu_degerlendir') . $fnGovde($lib, 'pdks_gunluk_kart_tanim_satir_bilgi') . $fnGovde($lib, 'pdks_gunluk_kart_tanim_satir_sinifla');
ok('değerlendirme/bilgi/sınıflama YAN ETKİSİZ (INSERT/UPDATE/DELETE yok)', $dg !== '' && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $dg));
ok("limit sabiti 100", PDKS_GUNLUK_TOPLU_TANIM_LIMIT === 100);
ok('tablo genel hazır-mı listelerine EKLENMEDİ', !str_contains($fnGovde($lib, 'pdks_gunluk_faz8a_sema_hazir'), 'kart_tanim'));

$isk = (string)file_get_contents($ROOT . '/isci_kartlari.php');
$a = strpos($isk, "=== 'tanim_satir'");
$uc1 = $a === false ? '' : substr($isk, $a, strpos($isk, "exit;\n}", $a) - $a);
ok('ajax=tanim_satir ucu var, GET + salt okunur (yazma çağrısı YOK)', $uc1 !== '' && !preg_match('/INSERT|UPDATE|DELETE|_kaydet\(|_olustur\(/', $uc1));
ok('tanim_satir: depo = aktif depo, çavuş/tip sunucuda doğrulanır', str_contains($uc1, '$aktifDepo') && str_contains($uc1, 'pdks_gunluk_kart_tanim_toplu_baslik('));
$a = strpos($isk, "=== 'tanim_toplu_kaydet'");
$uc2 = $a === false ? '' : substr($isk, $a, strpos($isk, "exit;\n}", $a) - $a);
ok('ajax=tanim_toplu_kaydet ucu var: POST + CSRF + sayfa kapısı', $uc2 !== '' && str_contains($uc2, "REQUEST_METHOD'] === 'POST'") && str_contains($uc2, 'csrf_check(') && str_contains($uc2, "require_pdks_gunluk('worker_cards')"));
ok('uç: depo istemciden ALINMAZ ($aktifDepo), yazma yalnız pdks_gunluk_kart_tanim_toplu_kaydet()', str_contains($uc2, '$aktifDepo') && str_contains($uc2, 'pdks_gunluk_kart_tanim_toplu_kaydet(') && !preg_match('/govde\[.depo|INSERT|UPDATE|DELETE/i', $uc2));
ok('ekranda migrate ÇAĞRILMAZ', !str_contains($isk, 'pdks_gunluk_kart_tanim_migrate('));

echo "\n" . ($fail ? "$fail HATA, $gecen geçti\n" : "Tümü geçti ($gecen)\n");
exit($fail ? 1 : 0);
