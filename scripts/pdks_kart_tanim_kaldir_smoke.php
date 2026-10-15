<?php
// =========================================================
// scripts/pdks_kart_tanim_kaldir_smoke.php — v364 SERİ TANIM KALDIR
//
// Kart Havuzu'nda kartlar art arda okutulur, liste kontrol edilir, tek düğmeyle
// hepsinin (aktif depodaki) tanımı kaldırılır; kartlar havuzda nötr kalır.
// Bu test: sınıflama (kaldirilacak · tanimsiz · baska_depo · hata), HEP-YA-HİÇ
// (engel varsa hiçbir şey), başarı (aktif_kart_id NULL, valid_to dolu, geçmiş
// satır korunur, kart durur), içeride uyarısı, kart başına audit, istek_id
// tekrarı, limit, rollback (bitir dış tx'te erken commit etmez) ve statik
// "ikinci yazma yolu yok" sözleşmeleri.
// Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_kart_tanim_kaldir_smoke.php   → çıkış kodu 0 = tüm testler geçti
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
function tanimYaz(string $no, int $cavus, int $tip, string $depo = 'Depo A'): array { return pdks_gunluk_kart_tanim_kaydet(kartId($no), $cavus, $tip, $depo, 1, db()); }
function usb(string $uid): array { return ['ham_uid' => $uid, 'kaynak' => 'usb_decimal']; }
function uid(int $i): string { return (string)(100000000 + $i); }
function kartSayisi(): int { return say("SELECT COUNT(*) FROM worker_cards"); }
function tanimSayisi(): int { return say("SELECT COUNT(*) FROM worker_card_assignments"); }
function aktifSayisi(): int { return say("SELECT COUNT(*) FROM worker_card_assignments WHERE aktif_kart_id IS NOT NULL"); }
function aktifTanim(string $no): ?array { return pdks_gunluk_kart_tanim_aktif(db(), kartId($no)); }
function kaldir(array $satirlar, string $istek = '', string $neden = ''): array {
    static $n = 0;
    if ($istek === '') $istek = str_pad(dechex(++$n), 16, 'b', STR_PAD_LEFT);
    return pdks_gunluk_kart_tanim_kaldir_toplu($satirlar, $neden, $istek, 1, db());
}
function auditSay(): int { return say("SELECT COUNT(*) FROM audit_log"); }

$kadin = say("SELECT id FROM worker_types WHERE code='KADIN'");
$erkek = say("SELECT id FROM worker_types WHERE code='ERKEK'");
$cavus = [];
foreach (['A', 'B'] as $i => $h) {
    $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT), 'name' => 'Çavuş ' . $h], 1, db())['id'];
}
for ($i = 1; $i <= 12; $i++) {
    pdks_gunluk_kart_olustur(['card_no' => 'K' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'ham_uid' => uid($i), 'kaynak' => 'usb_decimal'], 1, db());
}

echo "\n=== 0. Tablo YOKKEN ===\n";
$r = kaldir([usb(uid(1))]);
ok('tanım tablosu yokken → gecersiz (şema), hiçbir şey yazılmadı', ($r['kod'] ?? '') === 'gecersiz' && str_contains((string)$r['hata'], 'migrate'), j($r));
sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);

tanimYaz('K001', $cavus['A'], $kadin);
tanimYaz('K002', $cavus['A'], $erkek);
tanimYaz('K003', $cavus['B'], $kadin);
tanimYaz('K004', $cavus['A'], $kadin, 'Depo B');   // başka depo
tanimYaz('K008', $cavus['B'], $erkek);
tanimYaz('K009', $cavus['A'], $kadin);
pdks_gunluk_kart_durum_degistir(kartId('K006'), 'lost', 1, db());
tanimYaz('K007', $cavus['A'], $kadin);
pdks_gunluk_kart_durum_degistir(kartId('K007'), 'lost', 1, db());
db()->prepare("INSERT INTO worker_cards (card_no, worker_type_id, canonical_uid, uid_bytes, enrolled_source, status) VALUES ('KARTSIZ-000999', ?, ?, 0, 'kartsiz', 'disabled')")->execute([$kadin, 'KARTSIZABC']);

echo "\n=== 1. Sınıflama (saf + satır) ===\n";
$sn = fn(string $ham, string $kay = 'usb_decimal') => pdks_gunluk_kart_tanim_kaldir_satir(db(), $ham, $kay, 'Depo A');
$r = $sn(uid(1));
ok('tanımlı kart (aktif depo) → kaldirilacak + tanım özeti + uyarı yok', $r['sinif'] === 'kaldirilacak' && $r['card_no'] === 'K001' && $r['tanim']['foreman_name'] === 'Çavuş A' && $r['uyari'] === null, j($r));
$r = $sn(uid(5));
ok('tanımsız kart → tanimsiz (atlanır)', $r['sinif'] === 'tanimsiz' && $r['card_no'] === 'K005', j($r));
$r = $sn(uid(4));
ok('başka depoda tanımlı → baska_depo (ENGEL, depo adı yazılır)', $r['sinif'] === 'baska_depo' && str_contains((string)$r['hata'], 'Depo B'), j($r));
ok('depo karşılaştırması TR/harf duyarsız (DEPO A = Depo A)', pdks_gunluk_kart_tanim_kaldir_satir(db(), uid(1), 'usb_decimal', 'DEPO A')['sinif'] === 'kaldirilacak');
$r = $sn('abc');
ok('geçersiz UID → hata (gecersiz_uid)', $r['sinif'] === 'hata' && $r['kod'] === 'gecersiz_uid', j($r));
$r = $sn('299999999');
ok('havuzda olmayan kart → hata (kart_yok)', $r['sinif'] === 'hata' && $r['kod'] === 'kart_yok', j($r));
$r = $sn('KARTSIZABC', 'nfc_hex');
ok('kartsız sanal kartın UID\'i okutulamaz → hata', $r['sinif'] === 'hata', j($r));
$kartsizB = ['ok' => true, 'exists' => true, 'kartsiz' => true, 'status' => 'disabled', 'card_no' => 'KARTSIZ-000999',
             'tanim' => ['foreman_id' => 1, 'worker_type_id' => 1, 'depo' => 'Depo A'], 'kalici_cakisma' => false];
ok('sinifla: kartsız sanal kart (tanımlı olsa bile) → hata (kartsiz_kart)', pdks_gunluk_kart_tanim_kaldir_satir_sinifla($kartsizB, 'Depo A')['kod'] === 'kartsiz_kart');
$r = $sn(uid(7));
ok('KAYIP ama tanımlı kart → kaldirilacak (tanım kendiliğinden bitmez, kaldırılabilir)', $r['sinif'] === 'kaldirilacak', j($r));
ok('sinifla saf: ok=false → hata; exists=false → kart_yok',
    pdks_gunluk_kart_tanim_kaldir_satir_sinifla(['ok' => false, 'kod' => 'x', 'hata' => 'y'], 'Depo A')['sinif'] === 'hata'
    && pdks_gunluk_kart_tanim_kaldir_satir_sinifla(['ok' => true, 'exists' => false], 'Depo A')['kod'] === 'kart_yok');
$d = pdks_gunluk_kart_tanim_kaldir_degerlendir(db(), [usb(uid(1)), usb(uid(5)), usb(uid(1)), ['ham_uid' => pdks_uid_from_decimal(uid(2)), 'kaynak' => 'nfc_hex'], usb(uid(2))], 'Depo A');
ok('değerlendir: yinelenen (aynı ham + aynı kanonik farklı kaynak) → hata; özet 2/1/2', !$d['ok'] && $d['satirlar'][2]['kod'] === 'yinelenen' && $d['satirlar'][4]['kod'] === 'yinelenen'
    && $d['ozet'] === ['kaldirilacak' => 2, 'tanimsiz' => 1, 'engel' => 2], j($d['ozet']));

echo "\n=== 2. ENGEL → HEP-YA-HİÇ (hiçbir şey yazılmaz) ===\n";
$T = tanimSayisi(); $AK = aktifSayisi(); $A = auditSay(); $AUDIT = [];
$r = kaldir([usb(uid(1)), usb(uid(2)), usb(uid(4))]);
ok('başka depo satırı → hatali_satir + satır bazlı sonuç', ($r['kod'] ?? '') === 'hatali_satir' && ($r['satirlar'][2]['sinif'] ?? '') === 'baska_depo' && ($r['satirlar'][0]['sinif'] ?? '') === 'kaldirilacak', j($r));
ok('HİÇBİR tanım kapanmadı, audit yok, transaction kapalı', tanimSayisi() === $T && aktifSayisi() === $AK && auditSay() === $A && !$AUDIT && aktifTanim('K001') !== null && !db()->inTransaction());
$r = kaldir([usb(uid(1)), usb('abc')]);
ok('hata satırı (geçersiz UID) → hatali_satir, K001 hâlâ tanımlı', ($r['kod'] ?? '') === 'hatali_satir' && aktifTanim('K001') !== null, j($r));
$r = kaldir([usb(uid(5)), usb(uid(10))]);
ok('yalnız tanımsız kartlar → kaldirilacak_yok, hiçbir şey yazılmadı', ($r['kod'] ?? '') === 'kaldirilacak_yok' && auditSay() === $A, j($r));

echo "\n=== 3. Başarı: tanımlar kapanır, kartlar havuzda kalır, geçmiş korunur ===\n";
$K = kartSayisi(); $T = tanimSayisi(); $AUDIT = [];
$sA = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['A'], 1, db())['session']['id'];
$g = pdks_gunluk_faz8a_giris_kaydet(uid(9), 'usb_decimal', $sA, $kadin, 'auto', 1, db());
ok('hazırlık: K009 Çavuş A mesaisinde içeride', !empty($g['ok']), j($g));
$r = $sn(uid(9));
ok('içerideki kart → kaldirilacak + uyarı (engel DEĞİL)', $r['sinif'] === 'kaldirilacak' && str_contains((string)$r['uyari'], 'içeride'), j($r));
$eskiId = (int)aktifTanim('K001')['id'];
$r = kaldir([usb(uid(1)), usb(uid(2)), usb(uid(5)), usb(uid(9)), usb(uid(7))], 'c0ffee00c0ffee01', '  Sezon sonu  ');
ok('ok: 4 kaldırıldı, 1 atlandı (tanımsız), 1 uyarı (K009)', !empty($r['ok']) && $r['kaldirilan'] === 4 && $r['atlanan'] === 1 && count($r['uyarilar']) === 1 && $r['uyarilar'][0]['card_no'] === 'K009', j($r));
ok('K001/K002/K009/K007 artık tanımsız; K003 (B) ve K004 (Depo B) dokunulmadı',
    aktifTanim('K001') === null && aktifTanim('K002') === null && aktifTanim('K009') === null && aktifTanim('K007') === null
    && aktifTanim('K003') !== null && aktifTanim('K004') !== null);
$st = db()->prepare("SELECT * FROM worker_card_assignments WHERE id = ?"); $st->execute([$eskiId]); $eski = $st->fetch();
ok('geçmiş satırı SİLİNMEDİ: aktif_kart_id NULL, valid_to dolu, ended_by + gerekçe (kırpılmış)', $eski && $eski['aktif_kart_id'] === null && $eski['valid_to'] !== null
    && (int)$eski['ended_by_user_id'] === 1 && $eski['end_reason'] === 'Sezon sonu', j($eski));
ok('tanım satır sayısı değişmedi (satır silinmez/eklenmez), kart sayısı aynı', tanimSayisi() === $T && kartSayisi() === $K);
ok('kartlar havuzda: K001 available (Boşta), K007 lost durumunu korur', say("SELECT COUNT(*) FROM worker_cards WHERE card_no='K001' AND status='available'") === 1 && say("SELECT COUNT(*) FROM worker_cards WHERE card_no='K007' AND status='lost'") === 1);
ok('içerideki K009 açık dönemi o mesaide KALDI', say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE worker_card_id = ? AND session_id = ? AND status = 'open'", [kartId('K009'), $sA]) === 1);
$bit = array_values(array_filter($AUDIT, fn($a) => $a[0] === 'kart_tanim_bitir'));
ok('kart başına audit kart_tanim_bitir (4 satır, mevcut yol), gerekçe taşır', count($bit) === 4 && $bit[0][1] === 'worker_cards' && ($bit[0][4]['reason'] ?? '') === 'Sezon sonu', j($AUDIT));
$oz = db()->query("SELECT * FROM audit_log WHERE action='kart_tanim_kaldir_toplu'")->fetchAll();
$nv = $oz ? json_decode($oz[0]['new_values'], true) : [];
ok('özet audit kart_tanim_kaldir_toplu: 1 satır, worker_cards, istek_id + toplu_id KK + depo aktif depo + 5 kart',
    count($oz) === 1 && $oz[0]['module'] === 'worker_cards' && ($nv['istek_id'] ?? '') === 'c0ffee00c0ffee01' && str_starts_with((string)($nv['toplu_id'] ?? ''), 'KK')
    && $nv['depo'] === 'Depo A' && count($nv['kartlar']) === 5 && (int)$nv['kaldirilan'] === 4 && (int)$nv['atlanan'] === 1 && (int)$oz[0]['record_id'] === kartId('K001'), j($oz));
ok('transaction kapandı', !db()->inTransaction());
ok('kaldırılan kart artık tanımlı girişte "tanımlı değil"', (pdks_gunluk_tanimli_giris_kaydet(uid(1), 'usb_decimal', 1, db())['kod'] ?? '') === 'kart_tanimli_degil');

echo "\n=== 4. istek_id tekrarı + biçim ===\n";
tanimYaz('K001', $cavus['A'], $kadin);
$A = auditSay(); $AK = aktifSayisi();
$r = kaldir([usb(uid(1))], 'c0ffee00c0ffee01');
ok('aynı istek_id → tekrar, HİÇBİR ŞEY yazılmadı (K001 yeni tanımı duruyor)', ($r['kod'] ?? '') === 'tekrar' && !empty($r['tekrar']) && auditSay() === $A && aktifSayisi() === $AK && aktifTanim('K001') !== null, j($r));
ok('Seri Kart Tanımla istek_id alanı karışmaz (kart_tanim_toplu aramasında bulunmaz)', !pdks_gunluk_kart_tanim_toplu_istek_kayitli(db(), 'c0ffee00c0ffee01'));
$r = kaldir([usb(uid(1))], 'XYZ');
ok('geçersiz istek_id → istek_gecersiz', ($r['kod'] ?? '') === 'istek_gecersiz', j($r));
db()->beginTransaction();
$r = kaldir([usb(uid(1))]);
db()->rollBack();
ok('dış transaction içinde çağrı → ic_ice_islem', ($r['kod'] ?? '') === 'ic_ice_islem', j($r));

echo "\n=== 5. Varsayılan gerekçe + depo aktif depodan ===\n";
$AUDIT = [];
$id1 = (int)aktifTanim('K001')['id'];
$r = kaldir([usb(uid(1))]);
$st->execute([$id1]); $sat = $st->fetch();
ok('boş gerekçe → "Seri tanım kaldırma"', !empty($r['ok']) && $sat['end_reason'] === 'Seri tanım kaldırma', j([$r, $sat]));
$AKTIF_DEPO = 'Depo B';
$r = kaldir([usb(uid(4))]);
ok('aktif depo Depo B → K004 kaldırılır (depo istemciden değil aktif depodan)', !empty($r['ok']) && aktifTanim('K004') === null, j($r));
$AKTIF_DEPO = 'Depo A';
tanimYaz('K004', $cavus['A'], $kadin, 'Depo B');
$AKTIF_DEPO = '';
$r = kaldir([usb(uid(4))]);
ok('aktif depo yok → gecersiz', ($r['kod'] ?? '') === 'gecersiz' && aktifTanim('K004') !== null, j($r));
$AKTIF_DEPO = 'Depo A';

echo "\n=== 6. Limit ===\n";
$r = kaldir([]);
ok('boş liste → gecersiz', ($r['kod'] ?? '') === 'gecersiz', j($r));
$liste = []; for ($i = 1; $i <= 101; $i++) $liste[] = usb((string)(400000000 + $i));
$r = kaldir($liste);
ok('101 satır → gecersiz (limit 100)', ($r['kod'] ?? '') === 'gecersiz' && str_contains((string)$r['hata'], '100'), j($r));
ok('limit sabiti 100', PDKS_GUNLUK_TANIM_KALDIR_LIMIT === 100);
// 100 kart: 95 tanımlı + 5 tanımsız → hepsi tek seferde
for ($i = 1; $i <= 100; $i++) pdks_gunluk_kart_olustur(['card_no' => 'L' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'ham_uid' => (string)(600000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
for ($i = 1; $i <= 95; $i++) tanimYaz('L' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), $cavus['B'], $erkek);
$liste = []; for ($i = 1; $i <= 100; $i++) $liste[] = usb((string)(600000000 + $i));
$r = kaldir($liste);
ok('tam 100 satır kabul: 95 kaldırıldı, 5 atlandı', !empty($r['ok']) && $r['kaldirilan'] === 95 && $r['atlanan'] === 5, j($r));

echo "\n=== 7. Rollback bütünlüğü (ortada yazma hatası — erken commit YOK) ===\n";
tanimYaz('K010', $cavus['A'], $kadin);
tanimYaz('K011', $cavus['A'], $kadin);
db()->exec("CREATE TRIGGER t_boom BEFORE UPDATE ON worker_card_assignments WHEN OLD.worker_card_id = (SELECT id FROM worker_cards WHERE card_no = 'K011') BEGIN SELECT RAISE(ABORT, 'boom'); END");
$A = auditSay(); $AK = aktifSayisi();
$r = kaldir([usb(uid(10)), usb(uid(11))]);
ok('ikinci kartta hata → yazma_reddi, "hiçbir tanım kaldırılmadı", tx kapalı', ($r['kod'] ?? '') === 'yazma_reddi' && str_contains((string)$r['hata'], 'hiçbir tanım kaldırılmadı') && !db()->inTransaction(), j($r));
ok('ROLLBACK: ilk kart (K010) tanımı da DURUYOR (bitir dış tx\'te commit etmedi), audit yok', aktifTanim('K010') !== null && aktifTanim('K011') !== null && aktifSayisi() === $AK && auditSay() === $A);
db()->exec("DROP TRIGGER t_boom");
$r = kaldir([usb(uid(10)), usb(uid(11))]);
ok('sorun giderilince aynı liste yeni anahtarla işlenir', !empty($r['ok']) && $r['kaldirilan'] === 2, j($r));

echo "\n=== 8. Kaynak sözleşmeleri (statik) ===\n";
$lib = (string)file_get_contents($ROOT . '/config/pdks_gunluk.php');
$fnGovde = function (string $src, string $ad): string {
    $a = strpos($src, 'function ' . $ad . '(');
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a);
};
$tk = $fnGovde($lib, 'pdks_gunluk_kart_tanim_kaldir_toplu');
ok('kaldir_toplu var', $tk !== '');
ok('gövdede worker_cards / worker_card_assignments\'a DOĞRUDAN INSERT/UPDATE/DELETE YOK', !preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(worker_cards|worker_card_assignments)\b/i', $tk));
ok('yazma MEVCUT yoldan: pdks_gunluk_kart_tanim_bitir()', str_contains($tk, 'pdks_gunluk_kart_tanim_bitir('));
ok('MySQL: GET_LOCK (Seri Kart ile aynı kilit) beginTransaction\'dan hemen sonra',
    str_contains($tk, "GET_LOCK") && str_contains($tk, "'pdks_kart_tanim_toplu'") && strpos($tk, 'GET_LOCK') > strpos($tk, 'beginTransaction()') && str_contains($tk, 'RELEASE_LOCK'));
ok('değerlendirme KİLİT ALTINDA yeniden çağrılır (kilitle → degerlendir)', strpos($tk, 'pdks_gunluk_faz8a_kart_kilitle(') !== false && strrpos($tk, 'pdks_gunluk_kart_tanim_kaldir_degerlendir(') > strpos($tk, 'pdks_gunluk_faz8a_kart_kilitle('));
ok('tek transaction: beginTransaction 1, commit 1', substr_count($tk, 'beginTransaction()') === 1 && substr_count($tk, '->commit()') === 1);
ok('istek_id kontrolü transaction İÇİNDE + kart_tanim_kaldir_toplu eylemiyle', strrpos($tk, 'pdks_gunluk_kart_tanim_toplu_istek_kayitli(') > strpos($tk, 'beginTransaction()') && str_contains($tk, "'kart_tanim_kaldir_toplu'"));
ok('depo istemciden değil active_depot()\'tan', str_contains($tk, 'active_depot()'));
$dg = $fnGovde($lib, 'pdks_gunluk_kart_tanim_kaldir_degerlendir') . $fnGovde($lib, 'pdks_gunluk_kart_tanim_kaldir_satir') . $fnGovde($lib, 'pdks_gunluk_kart_tanim_kaldir_satir_sinifla');
ok('değerlendirme/satır/sınıflama YAN ETKİSİZ (INSERT/UPDATE/DELETE yok)', $dg !== '' && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $dg));
$bt = $fnGovde($lib, 'pdks_gunluk_kart_tanim_bitir');
ok('bitir dış transaction\'a katılır ($disTx: commit/rollback yalnız kendi tx\'inde)', str_contains($bt, '$disTx = $pdo->inTransaction()') && str_contains($bt, 'if (!$disTx) $pdo->commit()'));

$isk = (string)file_get_contents($ROOT . '/isci_kartlari.php');
$a = strpos($isk, "=== 'tanim_kaldir_satir'");
$uc1 = $a === false ? '' : substr($isk, $a, strpos($isk, "exit;\n}", $a) - $a);
ok('ajax=tanim_kaldir_satir ucu var, salt okunur, depo = aktif depo', $uc1 !== '' && !preg_match('/INSERT|UPDATE|DELETE|_kaydet\(|_toplu\(|_bitir\(/', $uc1) && str_contains($uc1, '$aktifDepo') && str_contains($uc1, '$seriHazir'));
$a = strpos($isk, "=== 'tanim_kaldir_toplu'");
$uc2 = $a === false ? '' : substr($isk, $a, strpos($isk, "exit;\n}", $a) - $a);
ok('ajax=tanim_kaldir_toplu: POST + CSRF + worker_cards kapısı + $seriHazir', $uc2 !== '' && str_contains($uc2, "REQUEST_METHOD'] === 'POST'") && str_contains($uc2, 'csrf_check(') && str_contains($uc2, "require_pdks_gunluk('worker_cards')") && str_contains($uc2, '$seriHazir'));
ok('uç: depo istemciden ALINMAZ, yazma yalnız pdks_gunluk_kart_tanim_kaldir_toplu()', str_contains($uc2, 'pdks_gunluk_kart_tanim_kaldir_toplu(') && !preg_match('/govde\[.depo|INSERT|UPDATE|DELETE/i', $uc2));
ok('düğme #iskKaldirAc ve pencere #iskSeriKaldirModal $seriHazir bloğunda', (bool)preg_match('/if \(\$seriHazir\): \?>\s*<!--[^>]*?-->\s*<div class="isk-seri-bar">.*?id="iskKaldirAc"/s', $isk)
    && (bool)preg_match('/<\?php if \(\$seriHazir\): \?>\s*<!-- ── v364.*?id="iskSeriKaldirModal"/s', $isk));
$m = strpos($isk, 'id="iskSeriKaldirModal"');
$mBlok = $m === false ? '' : substr($isk, $m, strpos($isk, '<script>', $m) - $m);
ok('pencere içinde <form> YOK (.pm-dialog flex zinciri)', $mBlok !== '' && !str_contains($mBlok, '<form'));
ok('v300 değişmezi korunur: kaynakta "Yeni Kart Tanımla" / kart_ekle YOK', !str_contains($isk, 'Yeni Kart Tanımla') && !str_contains($isk, 'kart_ekle'));
ok('tekli Tanımı Kaldır (kart_tanim_bitir) KORUNDU', str_contains($isk, "'kart_tanim_bitir'"));

echo "\n" . ($fail ? "$fail HATA, $gecen geçti\n" : "Tümü geçti ($gecen)\n");
exit($fail ? 1 : 0);
