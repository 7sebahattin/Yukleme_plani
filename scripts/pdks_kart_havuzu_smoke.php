<?php
// =========================================================
// scripts/pdks_kart_havuzu_smoke.php — v303 KART HAVUZU (sunucu)
//
// pdks_gunluk_kart_havuzu_ozet() sayımları (tanımlı/tanımsız/arşiv/kayıp, kartsız
// HARİÇ, çavuş → tip özeti ve sıraları), pdks_gunluk_kart_tanimsiz_sil() (geçmişsiz
// DELETE, geçmişli ARŞİV, tanımlı / içeride / kartsız reddi, HEP-YA-HİÇ, istek_id
// tekrarı, yönetici kapısı, audit), pdks_faz8j_bos_kartlar() tanım süzmesi ve Faz 8J
// elle ekleme / kart değiştiren düzeltme reddi (pdks_faz8j_tanim_kart_engeli).
// Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_kart_havuzu_smoke.php   → çıkış kodu 0 = tüm testler geçti
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
$ADMIN = true;
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
function current_user(): ?array { return ['id' => 1]; }
function can(string $p): bool { return true; }
function is_admin(): bool { global $ADMIN; return $ADMIN; }
function audit_log_event(string $a, string $m, ?int $id, ?array $o = null, ?array $n = null): void {}
// config/auth.php depo_fold() ile AYNI (canlıda pdks_gunluk_depo_fold buna devreder)
function depo_fold(string $s): string {
    $s = strtr($s, ['İ'=>'i','I'=>'i','Ş'=>'s','Ğ'=>'g','Ü'=>'u','Ö'=>'o','Ç'=>'c','ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c']);
    return mb_strtolower(trim($s), 'UTF-8');
}

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_faz8j.php';

// MySQL DDL → SQLite (pdks_toplu_ekle_smoke.php ile aynı çevirici)
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
pdks_faz8j_migrate(db());
foreach (['approved_by_user_id INTEGER', 'approved_at TEXT', 'overtime_approved INTEGER', 'overtime_approved_hours INTEGER', 'overtime_approved_by_user_id INTEGER', 'overtime_approved_at TEXT'] as $k) {
    db()->exec("ALTER TABLE daily_worker_work_periods ADD COLUMN $k");
}
db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
db()->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, status TEXT, needs_recalculation INTEGER DEFAULT 0, notes TEXT, updated_at TEXT, finalized_at TEXT, finalized_by_user_id INTEGER, total_amount TEXT)");

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-100s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function say(string $sql, array $p = []): int { $st = db()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
function kid(string $no): int { return say('SELECT id FROM worker_cards WHERE card_no = ?', [$no]); }
function kart(string $no): array { $st = db()->prepare('SELECT * FROM worker_cards WHERE card_no = ?'); $st->execute([$no]); return $st->fetch() ?: []; }
function istek(): string { static $n = 0; return str_pad(dechex(++$n), 32, '0', STR_PAD_LEFT); }
function sil(array $nolar, string $reason = 'Test silme', ?string $ist = null): array {
    return pdks_gunluk_kart_tanimsiz_sil(array_map(fn($n) => is_int($n) ? $n : kid($n), $nolar), $reason, $ist ?? istek(), 1, db());
}

$kadin   = say("SELECT id FROM worker_types WHERE code='KADIN'");
$erkek   = say("SELECT id FROM worker_types WHERE code='ERKEK'");
$rampaci = (int)(pdks_gunluk_rampaci_tip_garanti(db())['id'] ?? 0);
// Ad sırası TR: Ali < Çetin < Zeki (C/Ç ayrımı)
$cav = [];
foreach (['Z' => 'Zeki', 'A' => 'Ali', 'C' => 'Çetin', 'P' => 'Pasif'] as $h => $ad) {
    $cav[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C' . $h, 'name' => $ad], 1, db())['id'];
}
for ($i = 1; $i <= 20; $i++) {
    pdks_gunluk_kart_olustur(['card_no' => sprintf('K%03d', $i), 'ham_uid' => (string)(100000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
}
$dun = date('Y-m-d', strtotime('-1 day'));
$dun2 = date('Y-m-d', strtotime('-2 day'));
$bugun = date('Y-m-d');
function ekle(array $o = []): array {
    global $cav, $kadin, $dun, $AKTIF_DEPO;
    return pdks_faz8j_gecmis_ekle($o + [
        'foreman_id' => $cav['A'], 'work_date' => $dun, 'worker_card_id' => kid('K020'), 'worker_type_id' => $kadin,
        'entry_date' => $o['work_date'] ?? $dun, 'entry_clock' => '08:00', 'exit_date' => $o['work_date'] ?? $dun, 'exit_clock' => '17:00',
        'reason' => 'Test', 'note' => '', 'depo' => $AKTIF_DEPO,
    ], 1, db());
}

echo "\n=== 0. Tanım tablosu YOKKEN ===\n";
$oz = pdks_gunluk_kart_havuzu_ozet(db());
ok('tanim_hazir=false, toplam=20, tanimli=0, tanimsiz=20, cavuslar boş', $oz['tanim_hazir'] === false && $oz['toplam'] === 20 && $oz['tanimli'] === 0 && $oz['tanimsiz'] === 20 && $oz['cavuslar'] === [], j($oz));
$bk = pdks_faz8j_bos_kartlar($dun, db(), $cav['A'], 'Depo A');
ok('tablo yokken bos_kartlar süzmez, tanim_foreman_id null', count($bk) === 20 && $bk[0]['tanim_foreman_id'] === null, j($bk[0] ?? null));
$r = sil(['K019'], 'Tablo yokken silme');
ok('tablo yokken silme yine çalışır (geçmişsiz K019 → DELETE)', ($r['ok'] ?? false) && $r['silinen'] === 1 && kid('K019') === 0, j($r));

sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);

// Veri: tanımlar + durumlar + kartsız sanal kart
$t = fn(string $no, string $c, int $tip, string $depo = 'Depo A') => pdks_gunluk_kart_tanim_kaydet(kid($no), $cav[$c], $tip, $depo, 1, db());
$t('K001', 'A', $kadin); $t('K002', 'A', $kadin); $t('K003', 'A', $erkek); $t('K004', 'A', $rampaci, 'Depo B');
$t('K005', 'Z', $kadin, 'FİNİKE'); $t('K006', 'Z', $erkek, 'Finike');
$t('K007', 'C', $erkek);
$t('K008', 'P', $kadin);
$t('K009', 'C', $kadin);  // sonra devre dışı → özetten düşer
pdks_gunluk_cavus_aktiflik($cav['P'], false, 1, db());
pdks_gunluk_kart_durum_degistir(kid('K007'), 'lost', 1, db());   // tanımlı + kayıp
pdks_gunluk_kart_durum_degistir(kid('K009'), 'disabled', 1, db()); // tanımlı + arşiv
pdks_gunluk_kart_durum_degistir(kid('K010'), 'lost', 1, db());   // tanımsız + kayıp
pdks_gunluk_kart_durum_degistir(kid('K011'), 'disabled', 1, db()); // tanımsız + arşiv
db()->beginTransaction(); $sanal = pdks_faz8j_kartsiz_kart_olustur(db(), $kadin, 1); db()->commit();

echo "\n=== 1. Özet sayımları ===\n";
$oz = pdks_gunluk_kart_havuzu_ozet(db());
// fiziksel 19 kart (K019 silindi), 2 disabled (K009, K011) → toplam 17; tanımlı (disabled hariç): K001-K008 = 8
ok('toplam=17 (disabled + kartsız HARİÇ)', $oz['toplam'] === 17, j($oz));
ok('tanimli=8, tanimsiz=9, toplam = tanimli + tanimsiz', $oz['tanimli'] === 8 && $oz['tanimsiz'] === 9 && $oz['toplam'] === $oz['tanimli'] + $oz['tanimsiz'], j($oz));
ok('arsiv=2 (kartsız sanal kart disabled olsa da sayılmaz)', $oz['arsiv'] === 2, j($oz));
ok('kayip=2 (K007 tanımlı + K010 tanımsız)', $oz['kayip'] === 2, j($oz));
$adlar = array_column($oz['cavuslar'], 'foreman_name');
ok('çavuşlar TR ad sırasıyla: Ali, Çetin, Pasif, Zeki (disabled tanımı olan Çetin yalnız K007 ile)', $adlar === ['Ali', 'Çetin', 'Pasif', 'Zeki'], j($adlar));
$ali = $oz['cavuslar'][0];
ok('Ali: toplam 4, tipler Kadın 2 · Erkek 1 · Rampacı 1 (görüntü sırası)', $ali['toplam'] === 4 && array_map(fn($x) => [$x['tip_kodu'], $x['adet']], $ali['tipler']) === [['KADIN', 2], ['ERKEK', 1], ['RAMPACI', 1]], j($ali));
ok('Ali: depolar [Depo A, Depo B], foreman_aktif true, kod/ad/id alanları', $ali['depolar'] === ['Depo A', 'Depo B'] && $ali['foreman_aktif'] === true && $ali['foreman_code'] === 'CA' && $ali['foreman_id'] === $cav['A'], j($ali));
$cet = $oz['cavuslar'][1];
ok('Çetin: disabled K009 sayılmaz → yalnız Erkek 1 (kayıp K007)', $cet['toplam'] === 1 && count($cet['tipler']) === 1 && $cet['tipler'][0]['tip_kodu'] === 'ERKEK', j($cet));
ok('Pasif çavuş: foreman_aktif false', $oz['cavuslar'][2]['foreman_aktif'] === false, j($oz['cavuslar'][2]));
$zek = $oz['cavuslar'][3];
ok('Zeki: FİNİKE / Finike TR-duyarsız TEK depo', count($zek['depolar']) === 1 && $zek['toplam'] === 2, j($zek));
ok('tip satırı alanları: worker_type_id, tip_adi, tip_kodu, adet', isset($ali['tipler'][0]['worker_type_id'], $ali['tipler'][0]['tip_adi'], $ali['tipler'][0]['tip_kodu'], $ali['tipler'][0]['adet']) && $ali['tipler'][0]['worker_type_id'] === $kadin);

echo "\n=== 2. bos_kartlar tanım süzmesi ===\n";
$no = fn(array $l) => array_column($l, 'card_no');
$bA = pdks_faz8j_bos_kartlar($dun2, db(), $cav['A'], 'Depo A');
ok('Ali / Depo A: kendi Depo A tanımları + tanımsızlar var', in_array('K001', $no($bA), true) && in_array('K003', $no($bA), true) && in_array('K012', $no($bA), true), j($no($bA)));
ok('Ali / Depo A: Ali\'nin Depo B tanımı (K004), Zeki/Çetin/Pasif kartları YOK', !array_intersect(['K004', 'K005', 'K006', 'K008'], $no($bA)), j($no($bA)));
ok('disabled kart yok, kartsız sanal kart yok', !in_array('K011', $no($bA), true) && !array_filter($no($bA), fn($n) => str_starts_with($n, 'KARTSIZ')));
$r1 = array_values(array_filter($bA, fn($k) => $k['card_no'] === 'K001'))[0];
$r12 = array_values(array_filter($bA, fn($k) => $k['card_no'] === 'K012'))[0];
ok('satır alanları: tanim_foreman_id / tanim_depo / tanim_worker_type_id / tanim_tip_adi', $r1['tanim_foreman_id'] === $cav['A'] && $r1['tanim_depo'] === 'Depo A' && $r1['tanim_worker_type_id'] === $kadin && $r1['tanim_tip_adi'] === 'Kadın' && $r12['tanim_foreman_id'] === null && $r12['tanim_depo'] === null, j([$r1, $r12]));
$bL = pdks_faz8j_bos_kartlar($dun2, db(), null, 'Depo A');
ok('liste sayfası (çavuş null, Depo A): tüm çavuşların Depo A tanımlıları var (JS süzer), başka depo yok', in_array('K001', $no($bL), true) && in_array('K008', $no($bL), true) && !in_array('K004', $no($bL), true) && !in_array('K005', $no($bL), true), j($no($bL)));
$bH = pdks_faz8j_bos_kartlar($dun2, db());
ok('eski imza (süzme yok): tüm aktif kartlar', in_array('K004', $no($bH), true) && in_array('K005', $no($bH), true) && count($bH) === 17, (string)count($bH));
$bZ = pdks_faz8j_bos_kartlar($dun2, db(), $cav['Z'], 'finike');
ok('Zeki / "finike": FİNİKE+Finike tanımlıları var (TR-duyarsız depo)', in_array('K005', $no($bZ), true) && in_array('K006', $no($bZ), true) && !in_array('K001', $no($bZ), true), j($no($bZ)));

echo "\n=== 3. Faz 8J elle ekleme reddi (satir_kontrol) ===\n";
$r = ekle(['foreman_id' => $cav['C'], 'worker_card_id' => kid('K001')]);
ok('Çetin mesaisine Ali\'ye tanımlı K001 → RED (tanımlı — yalnız o çavuşun mesaisine)', empty($r['ok']) && str_contains((string)$r['hata'], "Ali / Kadın'a tanımlı — yalnız o çavuşun mesaisine eklenebilir."), j($r));
ok('red sonrası mesai/dönem yazılmadı', say('SELECT COUNT(*) FROM daily_worker_work_periods') === 0 && say('SELECT COUNT(*) FROM daily_work_sessions') === 0);
$r = ekle(['foreman_id' => $cav['A'], 'worker_card_id' => kid('K004')]);
ok('Ali / Depo A mesaisine Ali\'nin Depo B tanımlı K004 → RED (depo adı mesajda)', empty($r['ok']) && str_contains((string)$r['hata'], 'Depo B deposunda Ali / Rampacı'), j($r));
$r = ekle(['foreman_id' => $cav['A'], 'worker_card_id' => kid('K001')]);
ok('Ali mesaisine kendi K001 (Kadın) → eklenir, uyarı yok', !empty($r['ok']) && ($r['uyarilar'] ?? []) === [], j($r));
$sidA = (int)($r['session_id'] ?? 0);
$r = ekle(['foreman_id' => $cav['A'], 'worker_card_id' => kid('K002'), 'worker_type_id' => $erkek]);
ok('Ali mesaisine kendi K002 ERKEK tipiyle → eklenir + mevcut uyarı (engel değil)', !empty($r['ok']) && count($r['uyarilar'] ?? []) === 1 && str_contains($r['uyarilar'][0], 'K002'), j($r));
$r = ekle(['foreman_id' => $cav['C'], 'worker_card_id' => kid('K012')]);
ok('tanımsız kart her çavuşa eklenir', !empty($r['ok']), j($r));
$sidC = (int)($r['session_id'] ?? 0);
$tv = ['foreman_id' => $cav['C'], 'work_date' => $dun, 'depo' => 'Depo A', 'reason' => 'Toplu test', 'note' => '', 'istek_id' => istek(),
       'gruplar' => [['worker_type_id' => $kadin, 'entry_clock' => '09:00', 'exit_date' => $dun, 'exit_clock' => '12:00', 'kart_ids' => [kid('K003'), kid('K013')], 'kartsiz_adet' => 0]]];
$on = pdks_faz8j_toplu_onizle($tv, 1, db());
$sat = array_column($on['satirlar'], null, 'kart_no');
ok('toplu önizleme: Ali\'ye tanımlı K003 satırı HATA, tanımsız K013 uygun', empty($on['ok']) && $sat['K003']['durum'] === 'hata' && str_contains((string)$sat['K003']['hata'], 'tanımlı — yalnız') && $sat['K013']['durum'] === 'ok', j($on));
ok('toplu önizleme: başka çavuş kartı için "kayıt yine de eklenir" uyarısı ÜRETİLMEZ', ($on['uyarilar'] ?? []) === [], j($on['uyarilar'] ?? null));
$te = pdks_faz8j_toplu_ekle($tv, 1, db());
ok('toplu ekleme: HEP-YA-HİÇ — hiçbir satır yazılmadı', empty($te['ok']) && say('SELECT COUNT(*) FROM daily_worker_work_periods WHERE worker_card_id = ?', [kid('K013')]) === 0, j($te));
$hedefYok = pdks_faz8j_satir_kontrol(db(), null, $dun, ['kartsiz' => false, 'kart' => kart('K001'), 'entry' => "$dun 08:00:00", 'exit' => "$dun 09:00:00"]);
ok('satir_kontrol: hedef + mesai yokken tanımlı kart → fail-closed RED', is_string($hedefYok) && str_contains($hedefYok, 'hedef mesai'), (string)$hedefYok);
$hedefMesai = pdks_faz8j_satir_kontrol(db(), $sidC, $dun, ['kartsiz' => false, 'kart' => kart('K001'), 'entry' => "$dun 18:00:00", 'exit' => "$dun 19:00:00"]);
ok('satir_kontrol: hedef verilmezse mesaiden okunur (Çetin mesaisi + Ali kartı → RED)', is_string($hedefMesai) && str_contains($hedefMesai, 'tanımlı — yalnız'), (string)$hedefMesai);

echo "\n=== 4. Kart değiştiren düzeltme reddi (duzelt_satir) ===\n";
$pidC = say('SELECT id FROM daily_worker_work_periods WHERE session_id = ? AND worker_card_id = ?', [$sidC, kid('K012')]);
$dz = fn(int $pid, int $sid, int $card, array $o = []) => pdks_faz8j_duzelt($o + ['period_id' => $pid, 'session_id' => $sid, 'depo' => 'Depo A', 'reason' => 'Düzeltme', 'note' => '',
    'worker_card_id' => $card, 'worker_type_id' => $kadin, 'entry_date' => $dun, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00'], 1, db());
$r = $dz($pidC, $sidC, kid('K001'));
ok('Çetin dönemini Ali\'ye tanımlı K001\'e taşıma → RED', empty($r['ok']) && str_contains((string)$r['hata'], 'tanımlı — yalnız o çavuşun'), j($r));
ok('dönem kartı değişmedi', say('SELECT worker_card_id FROM daily_worker_work_periods WHERE id = ?', [$pidC]) === kid('K012'));
$r = $dz($pidC, $sidC, kid('K014'));
ok('Çetin dönemini tanımsız K014\'e taşıma → izin', !empty($r['ok']), j($r));
// Kart sonradan başka çavuşa tanımlanır: yalnız saat/tip düzeltme ENGELLENMEZ.
$t('K014', 'A', $kadin);
$r = $dz($pidC, $sidC, kid('K014'), ['entry_clock' => '08:30', 'worker_type_id' => $erkek]);
ok('kart DEĞİŞMİYORSA (yalnız saat/tip) başka çavuşa tanımlı olsa da düzeltme serbest', !empty($r['ok']), j($r));
$pidA = say('SELECT id FROM daily_worker_work_periods WHERE session_id = ? AND worker_card_id = ?', [$sidA, kid('K001')]);
$r = $dz($pidA, $sidA, kid('K003'), ['entry_clock' => '18:00', 'exit_clock' => '19:00']);
ok('Ali dönemini Ali\'nin başka tanımlı kartına (K003) taşıma → izin (aynı çavuş)', !empty($r['ok']), j($r));
$src = (string)file_get_contents($ROOT . '/config/pdks_faz8j.php');
$govde = function (string $fn) use ($src): string { $i = strpos($src, "function $fn("); if ($i === false) return ''; $j = strpos($src, "\nfunction ", $i + 10); return substr($src, $i, ($j === false ? strlen($src) : $j) - $i); };
ok('kural TEK yardımcıda: satir_kontrol + duzelt_satir pdks_faz8j_tanim_kart_engeli çağırır', str_contains($govde('pdks_faz8j_satir_kontrol'), 'pdks_faz8j_tanim_kart_engeli(') && str_contains($govde('pdks_faz8j_duzelt_satir'), 'pdks_faz8j_tanim_kart_engeli('));
ok('düzeltme UPDATE\'i hâlâ TEK yerde', substr_count($src, 'UPDATE daily_worker_work_periods SET worker_card_id=') === 1);
$gsrc = (string)file_get_contents($ROOT . '/config/pdks_gunluk.php');
$ggovde = function (string $fn) use ($gsrc): string { $i = strpos($gsrc, "function $fn("); if ($i === false) return ''; $j = strpos($gsrc, "\nfunction ", $i + 10); return substr($gsrc, $i, ($j === false ? strlen($gsrc) : $j) - $i); };
ok('kiosk kapısı pdks_gunluk_kart_tanim_engeli DEĞİŞMEDİ (tip uyuşmazlığını da reddeder)', pdks_gunluk_kart_tanim_engeli(['foreman_id' => 1, 'worker_type_id' => 2, 'depo' => 'D', 'foreman_name' => 'X', 'tip_adi' => 'Kadın'], ['foreman_id' => 1, 'depo' => 'D'], 3) !== null);

echo "\n=== 5. Silme — kapılar ===\n";
$ADMIN = false;
$r = sil(['K012']);
ok('yönetici değil → RED (yetki), kart duruyor', empty($r['ok']) && $r['kod'] === 'yetki' && kid('K012') > 0, j($r));
$ADMIN = true;
ok('boş liste → RED', (pdks_gunluk_kart_tanimsiz_sil([], 'x', istek(), 1, db())['kod'] ?? '') === 'bos');
ok('yinelenen id → RED', (pdks_gunluk_kart_tanimsiz_sil([kid('K015'), kid('K015')], 'x', istek(), 1, db())['kod'] ?? '') === 'yinelenen');
ok('geçersiz id (0 / metin / dizi) → RED', (pdks_gunluk_kart_tanimsiz_sil([0], 'x', istek(), 1, db())['kod'] ?? '') === 'gecersiz'
    && (pdks_gunluk_kart_tanimsiz_sil(['abc'], 'x', istek(), 1, db())['kod'] ?? '') === 'gecersiz'
    && (pdks_gunluk_kart_tanimsiz_sil([[1]], 'x', istek(), 1, db())['kod'] ?? '') === 'gecersiz');
ok('251 kart → RED (limit 250)', (pdks_gunluk_kart_tanimsiz_sil(range(1, 251), 'x', istek(), 1, db())['kod'] ?? '') === 'limit');
ok('gerekçe boş / >500 → RED', (sil(['K015'], '  ')['kod'] ?? '') === 'gerekce' && (sil(['K015'], str_repeat('a', 501))['kod'] ?? '') === 'gerekce');
ok('istek_id geçersiz / boş → RED', (sil(['K015'], 'x', 'XYZ')['kod'] ?? '') === 'istek_gecersiz' && (sil(['K015'], 'x', '')['kod'] ?? '') === 'istek_gecersiz');
ok('kartlar kapı reddinden sonra duruyor', kid('K015') > 0);

echo "\n=== 6. Silme — satır reddi + HEP-YA-HİÇ ===\n";
$kartAudit = fn() => say("SELECT COUNT(*) FROM audit_log WHERE action IN ('kart_sil','kart_arsiv','kart_toplu_sil')");
$a0 = $kartAudit();
$r = sil(['K015', 'K001']);
$h = array_column($r['hatalar'] ?? [], null, 'card_no');
ok('tanımlı K001 + tanımsız K015 → RED (önce Tanımı Kaldır), K015 SİLİNMEDİ', empty($r['ok']) && $r['kod'] === 'hatali_satir' && isset($h['K001']) && str_contains($h['K001']['hata'], 'önce Tanımı Kaldır') && !isset($h['K015']) && kid('K015') > 0, j($r));
ok('hatalar satırı: kart_id + card_no + hata', ($h['K001']['kart_id'] ?? 0) === kid('K001'));
$r = sil([(int)$sanal['id']]);
ok('kartsız sanal kart → RED', empty($r['ok']) && str_contains($r['hatalar'][0]['hata'] ?? '', 'Kartsız') && say('SELECT COUNT(*) FROM worker_cards WHERE id = ?', [(int)$sanal['id']]) === 1, j($r));
$r = pdks_gunluk_kart_tanimsiz_sil([999999], 'x', istek(), 1, db());
ok('olmayan kart → RED (Kart bulunamadı.)', empty($r['ok']) && ($r['hatalar'][0]['hata'] ?? '') === 'Kart bulunamadı.', j($r));
// İçeride: bugün açık mesaide çıkışsız dönem (tanımsız K016)
$ri = ekle(['foreman_id' => $cav['C'], 'work_date' => $bugun, 'worker_card_id' => kid('K016'), 'entry_date' => $bugun, 'entry_clock' => '00:00', 'exit_date' => '', 'exit_clock' => '']);
ok('hazırlık: K016 bugün içeride (açık dönem)', !empty($ri['ok']) && !empty($ri['acik']), j($ri));
$r = sil(['K016', 'K017']);
$h = array_column($r['hatalar'] ?? [], null, 'card_no');
ok('içerideki K016 → RED (mesaisinde içeride), K017 de silinmedi', empty($r['ok']) && str_contains($h['K016']['hata'] ?? '', 'Çetin mesaisinde içeride') && kid('K017') > 0, j($r));
ok('reddedilen istekler audit\'e HİÇBİR ŞEY yazmadı', $kartAudit() === $a0, (string)$kartAudit());

echo "\n=== 7. Silme — DELETE / ARŞİV ===\n";
// K013: geçmişsiz; K012: dönem geçmişi var (Çetin); K018: yalnız bitmiş tanım geçmişi; K011: zaten disabled + geçmişsiz;
// K010: kayıp + geçmişsiz; K020: zaten disabled + olay geçmişi
$t('K018', 'A', $kadin); pdks_gunluk_kart_tanim_bitir(kid('K018'), 'test', 1, db());
$r20 = ekle(['foreman_id' => $cav['Z'], 'work_date' => $dun2, 'worker_card_id' => kid('K020'), 'entry_date' => $dun2, 'exit_date' => $dun2]);
pdks_gunluk_kart_durum_degistir(kid('K020'), 'disabled', 1, db());
db()->exec("UPDATE worker_cards SET notes = 'Eski not' WHERE card_no = 'K020'");
$ids = ['K013' => kid('K013'), 'K012' => kid('K012'), 'K018' => kid('K018'), 'K011' => kid('K011'), 'K010' => kid('K010'), 'K020' => kid('K020')];
$donemOnce = say('SELECT COUNT(*) FROM daily_worker_work_periods');
$olayOnce = say('SELECT COUNT(*) FROM daily_worker_card_events');
$auditBas = say('SELECT COALESCE(MAX(id), 0) FROM audit_log');
$ist = istek();
$r = sil(array_keys($ids), 'Kullanılmayan kartlar temizlendi', $ist);
ok('silme başarılı: silinen 3 (K013, K011, K010), arşivlenen 3 (K012, K018, K020)', ($r['ok'] ?? false) && $r['silinen'] === 3 && $r['arsivlenen'] === 3, j($r));
ok('geçmişsiz kartlar kalıcı DELETE', kid('K013') === 0 && kid('K011') === 0 && kid('K010') === 0);
$k12 = kart('K012'); $k18 = kart('K018'); $k20 = kart('K020');
ok('geçmişli K012 arşiv: status disabled + "[Arşiv <bugün>] <gerekçe>" notu', $k12['status'] === 'disabled' && str_contains((string)$k12['notes'], '[Arşiv ' . $bugun . '] Kullanılmayan kartlar temizlendi'), j($k12));
ok('yalnız BİTMİŞ tanım geçmişi olan K018 de arşivlenir (silinmez)', ($k18['status'] ?? '') === 'disabled' && str_contains((string)$k18['notes'], '[Arşiv '), j($k18));
ok('zaten disabled + geçmişli K020: eski not korunur, arşiv notu eklenir', $k20['status'] === 'disabled' && str_starts_with((string)$k20['notes'], "Eski not\n[Arşiv "), j($k20));
ok('dönem + olay geçmişi korundu (raporlar; K012 olayları duruyor)', say('SELECT COUNT(*) FROM daily_worker_work_periods') === $donemOnce && say('SELECT COUNT(*) FROM daily_worker_card_events') === $olayOnce && say('SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id = ?', [$ids['K012']]) >= 1);
$aud = db()->query("SELECT action, module, record_id, old_values, new_values FROM audit_log WHERE id > $auditBas AND action IN ('kart_sil','kart_arsiv','kart_toplu_sil') ORDER BY id")->fetchAll();
$act = array_count_values(array_column($aud, 'action'));
ok('audit: 3 kart_sil + 3 kart_arsiv + 1 kart_toplu_sil (modül worker_cards)', ($act['kart_sil'] ?? 0) === 3 && ($act['kart_arsiv'] ?? 0) === 3 && ($act['kart_toplu_sil'] ?? 0) === 1 && count(array_unique(array_column($aud, 'module'))) === 1 && $aud[0]['module'] === 'worker_cards', j($act));
$silA = array_values(array_filter($aud, fn($x) => $x['action'] === 'kart_sil' && (int)$x['record_id'] === $ids['K013']))[0] ?? null;
ok('kart_sil: record_id = kart id, eski değerler (card_no, status)', $silA && (json_decode($silA['old_values'], true)['card_no'] ?? '') === 'K013' && (json_decode($silA['old_values'], true)['status'] ?? '') === 'available', j($silA));
$arsA = array_values(array_filter($aud, fn($x) => $x['action'] === 'kart_arsiv' && (int)$x['record_id'] === $ids['K012']))[0] ?? null;
ok('kart_arsiv: eski status available, yeni status disabled + reason', $arsA && json_decode($arsA['old_values'], true)['status'] === 'available' && json_decode($arsA['new_values'], true)['status'] === 'disabled' && json_decode($arsA['new_values'], true)['reason'] === 'Kullanılmayan kartlar temizlendi', j($arsA));
$oz2 = json_decode((string)(array_values(array_filter($aud, fn($x) => $x['action'] === 'kart_toplu_sil'))[0]['new_values'] ?? '{}'), true);
ok('özet: istek_id + silinen/arsivlenen id listeleri + reason', $oz2['istek_id'] === $ist && $oz2['silinen'] == [$ids['K010'], $ids['K011'], $ids['K013']] && $oz2['arsivlenen'] == [$ids['K012'], $ids['K018'], $ids['K020']] && $oz2['reason'] === 'Kullanılmayan kartlar temizlendi', j($oz2));
$a1 = say('SELECT COUNT(*) FROM audit_log');
$r = sil(['K017'], 'Tekrar', $ist);
ok('aynı istek_id ile tekrar → tekrar=true, HİÇBİR ŞEY yazılmaz (K017 duruyor)', empty($r['ok']) && !empty($r['tekrar']) && kid('K017') > 0 && say('SELECT COUNT(*) FROM audit_log') === $a1, j($r));
// Yazma aşamasında hata (ör. bilinmeyen bir tablonun FK'si) → TÜM işlem geri alınır.
$r15 = ekle(['foreman_id' => $cav['C'], 'work_date' => $dun2, 'worker_card_id' => kid('K015'), 'entry_date' => $dun2, 'exit_date' => $dun2, 'entry_clock' => '10:00', 'exit_clock' => '11:00']);
db()->exec("CREATE TRIGGER trg_test_sil BEFORE DELETE ON worker_cards WHEN OLD.card_no = 'K017' BEGIN SELECT RAISE(ABORT, 'bagli kayit'); END");
$a2 = $kartAudit();
$r = sil(['K015', 'K017'], 'Geri alma testi');
ok('yazma hatası: K015 (önce arşivlenen) geri alındı, K017 duruyor, audit yok', !empty($r15['ok']) && empty($r['ok']) && kart('K015')['status'] === 'available' && !str_contains((string)kart('K015')['notes'], '[Arşiv') && kid('K017') > 0 && $kartAudit() === $a2, j($r));
db()->exec('DROP TRIGGER trg_test_sil');
$oz = pdks_gunluk_kart_havuzu_ozet(db());
ok('özet sonrası: arşiv sayısı arttı (K009 + K012 + K018 + K020 = 4), kartsız hâlâ hariç', $oz['arsiv'] === 4, j($oz));

echo "\n=== 8. Statik ===\n";
$sg = $ggovde('pdks_gunluk_kart_tanimsiz_sil');
ok('silme: is_admin kapısı fonksiyon içinde', str_contains($sg, "is_admin()"));
ok('silme: kartlar girişle AYNI kilitle (faz8a_kart_kilitle) ve istek kontrolü kilitten SONRA', ($k = strpos($sg, 'pdks_gunluk_faz8a_kart_kilitle(')) !== false && ($i = strpos($sg, 'pdks_gunluk_kart_sil_istek_kayitli', $k)) !== false);
ok('silme: catch\'te PDOException kontrolü genel hatadan önce', str_contains($sg, 'catch (Throwable $e)') && strpos($sg, 'instanceof PDOException') < strpos($sg, "'yazma_hatasi'"));
ok('geçmiş tabloları listesi: events + periods + assignments', pdks_gunluk_kart_gecmis_tablolari() === ['daily_worker_card_events' => 'worker_card_id', 'daily_worker_work_periods' => 'worker_card_id', 'worker_card_assignments' => 'worker_card_id']);
$fk = [];
foreach (glob($ROOT . '/config/*.php') as $f) {
    if (preg_match_all('/`(\w+)`\s+INT[^,]*,(?:(?!CREATE TABLE).)*?FOREIGN KEY \(`\1`\)\s*REFERENCES `worker_cards`/s', (string)file_get_contents($f), $mm)) $fk = array_merge($fk, $mm[1]);
}
$fkTablo = [];
foreach (glob($ROOT . '/config/*.php') as $f) {
    $c = (string)file_get_contents($f);
    if (preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)`((?:(?!CREATE TABLE).)*?)ENGINE=/s', $c, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) if (str_contains($m[2], 'REFERENCES `worker_cards`')) $fkTablo[] = $m[1];
    }
}
sort($fkTablo);
$liste = array_keys(pdks_gunluk_kart_gecmis_tablolari()); sort($liste);
ok('config/ altındaki worker_cards FK\'li TÜM tablolar geçmiş listesinde', $fkTablo === $liste, j($fkTablo));

echo "\n" . ($fail ? "$fail HATA, $gecen geçti\n" : "Tümü geçti ($gecen)\n");
exit($fail ? 1 : 0);
