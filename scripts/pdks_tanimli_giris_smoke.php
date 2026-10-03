<?php
// =========================================================
// scripts/pdks_tanimli_giris_smoke.php — v298 TANIMLI GİRİŞ
//
// Kart Havuzu'nda karta çavuş + tip (Kadın/Erkek) + depo tanımı; kioskta
// "🏷 TANIMLI GİRİŞ" ile kartın tanımlı çavuşunun bugünkü mesaisine giriş.
// Bu test: tanım yazma/geçmiş/tek aktif tanım (UNIQUE), ret kuralları,
// pdks_gunluk_tanimli_giris_kaydet() (yazmayı giris_kaydet'e devreder,
// dönem source='tanimli'), normal ekranda tanımlı kart kapısı
// (kart_baska_tanimli), Faz 8J elle/toplu eklemede yalnız uyarı, hakediş
// eşitliği, tablo yokken eski davranış ve kaynak sözleşmeleri.
// Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_tanimli_giris_smoke.php   → çıkış kodu 0 = tüm testler geçti
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
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8j.php';

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
pdks_faz8j_migrate(db());
db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
foreach (pdks_hakedis_tablolar() as $sql) sqlite_kur($sql);
pdks_faz8b_migrate(db());

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

$kadin   = say("SELECT id FROM worker_types WHERE code='KADIN'");
$erkek   = say("SELECT id FROM worker_types WHERE code='ERKEK'");
$karisik = say("SELECT id FROM worker_types WHERE code='KARISIK'");
$cavus = [];
foreach (['A', 'B', 'P', 'Q', 'R', 'S', 'T', 'U', 'H'] as $i => $h) {
    $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT), 'name' => 'Çavuş ' . $h], 1, db())['id'];
}
for ($i = 1; $i <= 20; $i++) {
    $no = 'K' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    pdks_gunluk_kart_olustur(['card_no' => $no, 'ham_uid' => (string)(100000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
}
function uid(string $no): string { return (string)(100000000 + (int)substr($no, 1)); }
$bugun = date('Y-m-d');
$dun = date('Y-m-d', strtotime('-1 day'));

echo "\n=== 0. Tablo YOKKEN (opsiyonel şema — eski davranış) ===\n";
ok('tanım tablosu yok → sema_hazir false', pdks_gunluk_kart_tanim_sema_hazir(db()) === false);
ok('Günlük İşçi genel hazır-mı kontrolü ETKİLENMEDİ (true)', pdks_gunluk_sema_hazir(db()) === true && pdks_gunluk_faz8a_sema_hazir(db()) === true);
ok('tablo yokken aktif tanım null', pdks_gunluk_kart_tanim_aktif(db(), kartId('K001')) === null);
$r = tanimli(uid('K001'));
ok('tablo yokken tanımlı giriş → sema_hazir_degil', ($r['kod'] ?? '') === 'sema_hazir_degil', j($r));
$r = tanimYaz('K001', $cavus['A'], $kadin);
ok('tablo yokken tanım yazılamaz → sema_hazir_degil', ($r['kod'] ?? '') === 'sema_hazir_degil', j($r));
$sA = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['A'], 1, db())['session']['id'];
$r = normal(uid('K020'), $sA, $kadin);
ok('tablo yokken normal kiosk girişi AYNEN çalışır (source=scan)', !empty($r['ok']) && (donem('K020')['source'] ?? '') === 'scan', j($r));
$r = pdks_gunluk_ortak_cikis_kaydet(uid('K020'), 'usb_decimal', 1, db());
ok('… ve ortak çıkış çalışır', !empty($r['ok']), j($r));

echo "\n=== 1. Kurulum ===\n";
sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);
ok('tablo kuruldu → sema_hazir true', pdks_gunluk_kart_tanim_sema_hazir(db()) === true);
$mig = pdks_gunluk_kart_tanim_migrate(db());
ok('migrate idempotent (tablo var → durum=var)', ($mig[0]['durum'] ?? '') === 'var', j($mig));

echo "\n=== 2. Tanım yazma / geçmiş / tek aktif tanım ===\n";
$AUDIT = [];
$r = tanimYaz('K001', $cavus['A'], $kadin);
ok('K001 → Çavuş A / Kadın / Depo A kaydedildi', !empty($r['ok']) && empty($r['degisiklik_yok']), j($r));
$t = pdks_gunluk_kart_tanim_aktif(db(), kartId('K001'));
ok('aktif tanım okunur (çavuş adı, tip kodu, depo)', ($t['foreman_name'] ?? '') === 'Çavuş A' && ($t['tip_kodu'] ?? '') === 'KADIN' && ($t['depo'] ?? '') === 'Depo A', j($t));
ok('audit kart_tanim (modül worker_cards, record = kart id)', count(array_filter($AUDIT, fn($a) => $a[0] === 'kart_tanim' && $a[1] === 'worker_cards' && $a[2] === kartId('K001'))) === 1, j($AUDIT));
$r = tanimYaz('K001', $cavus['A'], $kadin, 'DEPO A');
ok('aynı tanım (depo harf farkı) → degisiklik_yok, yeni satır YOK', !empty($r['degisiklik_yok']) && say("SELECT COUNT(*) FROM worker_card_assignments WHERE worker_card_id = ?", [kartId('K001')]) === 1, j($r));
$r = tanimYaz('K001', $cavus['B'], $erkek);
ok('K001 → Çavuş B / Erkek olarak değiştirildi', !empty($r['ok']), j($r));
ok('geçmiş korunur: 2 satır, eskisi kapandı (valid_to dolu, aktif_kart_id NULL)',
    say("SELECT COUNT(*) FROM worker_card_assignments WHERE worker_card_id = ?", [kartId('K001')]) === 2
    && say("SELECT COUNT(*) FROM worker_card_assignments WHERE worker_card_id = ? AND aktif_kart_id IS NULL AND valid_to IS NOT NULL AND ended_by_user_id = 1", [kartId('K001')]) === 1);
ok('kart başına TEK aktif tanım', say("SELECT COUNT(*) FROM worker_card_assignments WHERE aktif_kart_id = ?", [kartId('K001')]) === 1
    && (int)pdks_gunluk_kart_tanim_aktif(db(), kartId('K001'))['foreman_id'] === $cavus['B']);
$unique = false; $uniqueHata = null;
try {
    db()->prepare("INSERT INTO worker_card_assignments (worker_card_id, foreman_id, worker_type_id, depo, aktif_kart_id, valid_from) VALUES (?,?,?,?,?,?)")
        ->execute([kartId('K001'), $cavus['A'], $kadin, 'Depo A', kartId('K001'), date('Y-m-d H:i:s')]);
} catch (PDOException $e) { $unique = true; $uniqueHata = $e; }
ok('UNIQUE(aktif_kart_id): ikinci aktif tanım VERİTABANINDA reddedilir', $unique);
ok('UNIQUE ihlali eşzamanlı-hata olarak tanınır (23000 → Türkçe mesaj)', $uniqueHata !== null && pdks_gunluk_kart_tanim_eszamanli($uniqueHata));

db()->prepare("INSERT INTO worker_cards (card_no, worker_type_id, canonical_uid, uid_bytes, enrolled_source, status) VALUES ('KARTSIZ-000999', ?, 'KARTSIZABC', 0, 'kartsiz', 'disabled')")->execute([$kadin]);
$r = tanimYaz('KARTSIZ-000999', $cavus['A'], $kadin);
ok('kartsız sanal karta tanım → kartsiz_kart', ($r['kod'] ?? '') === 'kartsiz_kart', j($r));
$pasif = (int)pdks_gunluk_cavus_olustur(['code' => 'C099', 'name' => 'Pasif Çavuş'], 1, db())['id'];
pdks_gunluk_cavus_aktiflik($pasif, false, 1, db());
$r = tanimYaz('K019', $pasif, $kadin);
ok('pasif çavuşa tanım → cavus_pasif', ($r['kod'] ?? '') === 'cavus_pasif', j($r));
$r = tanimYaz('K019', $cavus['A'], $karisik);
ok('Karışık tipe tanım → tip_desteklenmiyor', ($r['kod'] ?? '') === 'tip_desteklenmiyor', j($r));
$r = tanimYaz('K019', $cavus['A'], $kadin, '  ');
ok('boş depo → depo_yok', ($r['kod'] ?? '') === 'depo_yok', j($r));
$r = pdks_gunluk_kart_tanim_kaydet(999999, $cavus['A'], $kadin, 'Depo A', 1, db());
ok('olmayan kart → kart_yok', ($r['kod'] ?? '') === 'kart_yok', j($r));
ok('reddedilen tanımlar hiçbir satır yazmadı', say("SELECT COUNT(*) FROM worker_card_assignments WHERE worker_card_id IN (?, ?)", [kartId('K019'), kartId('KARTSIZ-000999')]) === 0);
$r = pdks_gunluk_kart_tanim_bitir(kartId('K001'), 'deneme', 1, db());
ok('tanım bitir → aktif tanım yok, satır SİLİNMEDİ (end_reason yazıldı)', !empty($r['ok']) && pdks_gunluk_kart_tanim_aktif(db(), kartId('K001')) === null
    && say("SELECT COUNT(*) FROM worker_card_assignments WHERE worker_card_id = ? AND end_reason = 'deneme'", [kartId('K001')]) === 1, j($r));
$r = pdks_gunluk_kart_tanim_bitir(kartId('K001'), '', 1, db());
ok('tanımı olmayan kartı bitir → tanim_yok', ($r['kod'] ?? '') === 'tanim_yok', j($r));
ok('audit kart_tanim_bitir', count(array_filter($AUDIT, fn($a) => $a[0] === 'kart_tanim_bitir')) === 1);
ok("yönelme eki: Kadın'a / Erkek'e", pdks_gunluk_yonelme_eki('Kadın') === "'a" && pdks_gunluk_yonelme_eki('Erkek') === "'e");

echo "\n=== 3. Tanımlı giriş — başarı yolu ===\n";
tanimYaz('K002', $cavus['B'], $kadin);
$onceB = mesaiSayisi($cavus['B']);
$AUDIT = [];
$r = tanimli(uid('K002'));
ok('K002 (Çavuş B / Kadın) tanımlı giriş başarılı', !empty($r['ok']) && ($r['event_type'] ?? '') === 'GIRIS', j($r));
ok('mesai yokken çavuş B için bugünkü mesai AÇILDI', mesaiSayisi($cavus['B']) === $onceB + 1);
$sB = (int)($r['cavus']['session_id'] ?? 0);
$sBRow = db()->query("SELECT * FROM daily_work_sessions WHERE id = $sB")->fetch();
ok('mesai: çavuş B, bugün, aktif depo', (int)$sBRow['foreman_id'] === $cavus['B'] && $sBRow['work_date'] === $bugun && $sBRow['depo'] === 'Depo A');
$d = donem('K002');
ok("dönem: doğru mesai + tip Kadın + source='tanimli' + status open", (int)$d['session_id'] === $sB && (int)$d['worker_type_id_snapshot'] === $kadin && $d['source'] === 'tanimli' && $d['status'] === 'open', j($d));
ok('ham GİRİŞ olayı yazıldı (olay source = okuma kaynağı, değişmedi)', say("SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id = ? AND event_type='GIRIS' AND source='usb_decimal'", [kartId('K002')]) === 1);
ok('yanıtta çavuş + tanım + kart tipi', ($r['cavus']['ad'] ?? '') === 'Çavuş B' && ($r['tanim']['tip_kodu'] ?? '') === 'KADIN' && ($r['card']['worker_type_name'] ?? '') === 'Kadın', j($r));
$g = array_values(array_filter($AUDIT, fn($a) => $a[0] === 'gunluk_giris'));
ok("audit gunluk_giris: donem_kaynak='tanimli' + tanim_id", ($g[0][4]['donem_kaynak'] ?? '') === 'tanimli' && (int)($g[0][4]['tanim_id'] ?? 0) === (int)pdks_gunluk_kart_tanim_aktif(db(), kartId('K002'))['id'], j($g));
$sayi = say("SELECT COUNT(*) FROM daily_worker_work_periods");
$r = tanimli(uid('K002'));
ok('aynı kart tekrar → mukerrer_giris (çavuş bilgisiyle), yeni dönem YOK', ($r['kod'] ?? '') === 'mukerrer_giris' && ($r['cavus']['ad'] ?? '') === 'Çavuş B' && say("SELECT COUNT(*) FROM daily_worker_work_periods") === $sayi, j($r));
tanimYaz('K003', $cavus['B'], $erkek);
$r = tanimli(uid('K003'));
ok('aynı çavuşun açık mesaisine (yeni açmadan) Erkek girişi', !empty($r['ok']) && (int)$r['cavus']['session_id'] === $sB && (int)donem('K003')['worker_type_id_snapshot'] === $erkek && mesaiSayisi($cavus['B']) === $onceB + 1, j($r));

echo "\n=== 4. Tanımlı giriş — ret yolları ===\n";
$kartSayisi = say("SELECT COUNT(*) FROM worker_cards");
$r = tanimli('999999999');
ok('havuzda olmayan UID → kart_tanimsiz', ($r['kod'] ?? '') === 'kart_tanimsiz', j($r));
ok('… ve otomatik (AUTO-) kart KAYDEDİLMEDİ', say("SELECT COUNT(*) FROM worker_cards") === $kartSayisi && say("SELECT COUNT(*) FROM worker_cards WHERE card_no LIKE 'AUTO-%'") === 0);
$r = tanimli(uid('K004'));
ok('tanımı olmayan kart → kart_tanimli_degil', ($r['kod'] ?? '') === 'kart_tanimli_degil', j($r));
tanimYaz('K005', $cavus['A'], $kadin, 'Depo B');
$r = tanimli(uid('K005'));
ok('başka depoya tanımlı kart → tanim_baska_depo ("Depo B deposuna tanımlı")', ($r['kod'] ?? '') === 'tanim_baska_depo' && str_contains($r['hata'] ?? '', 'Depo B deposuna tanımlı'), j($r));
tanimYaz('K006', $cavus['A'], $kadin, 'depo a');
$r = tanimli(uid('K006'));
ok('depo karşılaştırması harf-duyarsız ("depo a" == "Depo A") → giriş', !empty($r['ok']) && (int)$r['cavus']['session_id'] === $sA, j($r));
$AKTIF_DEPO = '';
$r = tanimli(uid('K003'));
ok('aktif depo yok → depo_yok', ($r['kod'] ?? '') === 'depo_yok', j($r));
$AKTIF_DEPO = 'Depo A';

tanimYaz('K007', $cavus['P'], $kadin);
pdks_gunluk_cavus_aktiflik($cavus['P'], false, 1, db());
$r = tanimli(uid('K007'));
ok('tanımlı çavuş sonradan pasif → cavus_pasif (çavuş adıyla), tanım OTOMATİK BİTMEDİ', ($r['kod'] ?? '') === 'cavus_pasif' && str_contains($r['hata'] ?? '', 'Çavuş P') && pdks_gunluk_kart_tanim_aktif(db(), kartId('K007')) !== null, j($r));
ok('… pasif çavuşa mesai AÇILMADI', mesaiSayisi($cavus['P']) === 0);

$sQ = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['Q'], 1, db())['session']['id'];
pdks_gunluk_oturum_kapat($sQ, null, 1, db());
tanimYaz('K008', $cavus['Q'], $kadin);
$r = tanimli(uid('K008'));
ok('çavuşun bugünkü mesaisi kapalı → oturum_kapali_zaten', ($r['kod'] ?? '') === 'oturum_kapali_zaten' && str_contains($r['hata'] ?? '', 'Çavuş Q'), j($r));

$sR = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['R'], 1, db())['session']['id'];
db()->prepare("UPDATE daily_work_sessions SET work_date = ? WHERE id = ?")->execute([$dun, $sR]);
tanimYaz('K009', $cavus['R'], $kadin);
$r = tanimli(uid('K009'));
ok('önceki günden açık mesai → onceki_mesai_acik + eski_oturumlar', ($r['kod'] ?? '') === 'onceki_mesai_acik' && !empty($r['eski_oturumlar']) && (int)$r['eski_oturumlar'][0]['id'] === $sR, j($r));
ok('… bugün için yeni mesai AÇILMADI', mesaiSayisi($cavus['R']) === 1);

$sS = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['S'], 1, db())['session']['id'];
normal(uid('K010'), $sS, $kadin);
pdks_gunluk_oturum_kapat($sS, 'K010 sahada kaldı', 1, db());
tanimYaz('K010', $cavus['U'], $kadin);
$r = tanimli(uid('K010'));
ok('aynı gün eksik çıkışla kapanmış mesaide kalan kart → bugun_eksik_cikis', ($r['kod'] ?? '') === 'bugun_eksik_cikis', j($r));
ok('… başarısız ön kontrol çavuş U için BOŞ MESAİ AÇMADI', mesaiSayisi($cavus['U']) === 0);

tanimYaz('K011', $cavus['T'], $kadin);
pdks_gunluk_kart_durum_degistir(kartId('K011'), 'lost', 1, db());
$r = tanimli(uid('K011'));
ok('kayıp kart → kart_kayip, tanım OTOMATİK BİTMEDİ', ($r['kod'] ?? '') === 'kart_kayip' && pdks_gunluk_kart_tanim_aktif(db(), kartId('K011')) !== null, j($r));
ok('… çavuş T için boş mesai AÇILMADI', mesaiSayisi($cavus['T']) === 0);
pdks_gunluk_kart_durum_degistir(kartId('K011'), 'disabled', 1, db());
$r = tanimli(uid('K011'));
ok('devre dışı kart → kart_devre_disi', ($r['kod'] ?? '') === 'kart_devre_disi', j($r));

tanimYaz('K012', $cavus['T'], $kadin);
$r = normal(uid('K012'), $sA, $kadin);   // K012 Çavuş T'ye tanımlı — normal ekranda A'ya giremez
ok('(hazırlık) tanımlı kart başka çavuşa normal giriş yapamadı', ($r['kod'] ?? '') === 'kart_baska_tanimli', j($r));
$r = tanimli('');
ok('boş UID → bos_uid', ($r['kod'] ?? '') === 'bos_uid');
$r = pdks_gunluk_tanimli_giris_kaydet(uid('K002'), 'uydurma', 1, db());
ok('geçersiz kaynak → gecersiz_kaynak', ($r['kod'] ?? '') === 'gecersiz_kaynak');

echo "\n=== 5. Ortak Çıkış ile çıkış + yeniden giriş ===\n";
$r = pdks_gunluk_ortak_cikis_kaydet(uid('K002'), 'usb_decimal', 1, db());
ok('tanımlı girişle giren kart ORTAK ÇIKIŞ ile çıktı (aynı mesai)', !empty($r['ok']) && (int)$r['cavus']['session_id'] === $sB && donem('K002')['status'] === 'closed', j($r));
$r = tanimli(uid('K002'));
ok('çıkıştan sonra aynı gün tanımlı yeniden giriş (Faz 8A nötr kart)', !empty($r['ok']) && donem('K002')['source'] === 'tanimli', j($r));

echo "\n=== 6. Normal ekranda tanımlı kart kapısı (kart_baska_tanimli) ===\n";
tanimYaz('K013', $cavus['A'], $kadin);
$r = normal(uid('K013'), $sB, $kadin);
ok("farklı çavuş → kart_baska_tanimli, \"Çavuş A / Kadın'a tanımlı\"", ($r['kod'] ?? '') === 'kart_baska_tanimli' && str_contains($r['hata'] ?? '', "Çavuş A / Kadın'a tanımlı"), j($r));
$r = normal(uid('K013'), $sA, $erkek);
ok('aynı çavuş farklı tip → kart_baska_tanimli', ($r['kod'] ?? '') === 'kart_baska_tanimli', j($r));
ok('reddedilen girişler dönem YAZMADI', say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE worker_card_id = ?", [kartId('K013')]) === 0);
$r = normal(uid('K013'), $sA, $kadin);
ok("uyuşan çavuş + tip → normal giriş geçer (source='scan')", !empty($r['ok']) && donem('K013')['source'] === 'scan', j($r));
tanimYaz('K014', $cavus['A'], $kadin, 'Depo B');
$r = normal(uid('K014'), $sA, $kadin);
ok('tanım deposu ≠ mesai deposu → kart_baska_tanimli, depo adıyla', ($r['kod'] ?? '') === 'kart_baska_tanimli' && str_contains($r['hata'] ?? '', 'Depo B'), j($r));
$r = normal(uid('K015'), $sB, $erkek);
ok('tanımsız kart normal ekranda eskisi gibi girer', !empty($r['ok']), j($r));
tanimYaz('K016', $cavus['A'], $kadin);
pdks_gunluk_kart_tanim_bitir(kartId('K016'), '', 1, db());
$r = normal(uid('K016'), $sB, $erkek);
ok('tanım bitince normal ekran serbest', !empty($r['ok']), j($r));
$kSay = say("SELECT COUNT(*) FROM worker_cards");
$r = normal('888888888', $sB, $kadin);
ok('normal ekranda tanımsız UID otomatik kaydı DEĞİŞMEDİ (AUTO kart)', !empty($r['ok']) && say("SELECT COUNT(*) FROM worker_cards") === $kSay + 1, j($r));

echo "\n=== 7. Kart içerideyken tanım değişikliği (engel değil, uyarı) ===\n";
$r = tanimYaz('K013', $cavus['B'], $kadin);
ok('içerideki kartın tanımı değişti + uyarı (açık dönem eski çavuşta)', !empty($r['ok']) && str_contains((string)($r['uyari'] ?? ''), 'Çavuş A'), j($r));
ok('açık dönem eski mesaide kaldı', (int)donem('K013')['session_id'] === $sA && donem('K013')['status'] === 'open');

echo "\n=== 8. Faz 8J — elle / toplu ekleme yalnız UYARI ===\n";
tanimYaz('K017', $cavus['A'], $kadin);
$r = pdks_faz8j_gecmis_ekle([
    'foreman_id' => $cavus['H'], 'work_date' => $dun, 'worker_card_id' => kartId('K017'), 'worker_type_id' => $erkek,
    'entry_date' => $dun, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00',
    'reason' => 'unutuldu', 'note' => '', 'depo' => 'Depo A', 'istek_id' => bin2hex(random_bytes(16)),
], 1, db());
ok('başka çavuşa tanımlı kart elle eklenir (ENGEL DEĞİL)', !empty($r['ok']), j($r));
ok('… yanıtta tanım uyarısı', str_contains(implode(' ', $r['uyarilar'] ?? []), 'K017') && str_contains(implode(' ', $r['uyarilar'] ?? []), 'Çavuş A'), j($r['uyarilar'] ?? null));
tanimYaz('K018', $cavus['A'], $kadin);
$v = [
    'foreman_id' => $cavus['H'], 'work_date' => date('Y-m-d', strtotime('-2 days')), 'depo' => 'Depo A', 'reason' => 'toplu', 'note' => '',
    'istek_id' => bin2hex(random_bytes(16)),
    'gruplar' => [['worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_date' => date('Y-m-d', strtotime('-2 days')), 'exit_clock' => '17:00',
                   'kart_ids' => [kartId('K018')], 'kartsiz_adet' => 0]],
];
$on = pdks_faz8j_toplu_onizle($v, 1, db());
ok('toplu önizleme ok=true (engel yok) + uyarilar içinde tanım uyarısı', !empty($on['ok']) && str_contains(implode(' ', $on['uyarilar']), 'K018'), j($on));
$te = pdks_faz8j_toplu_ekle($v, 1, db());
ok('toplu ekleme yazıldı (uyarıya rağmen)', !empty($te['ok']) && (int)$te['eklenen'] === 1, j($te));
$v2 = $v; $v2['foreman_id'] = $cavus['A']; $v2['work_date'] = date('Y-m-d', strtotime('-3 days')); $v2['istek_id'] = bin2hex(random_bytes(16));
$v2['gruplar'][0]['exit_date'] = $v2['work_date'];
$on2 = pdks_faz8j_toplu_onizle($v2, 1, db());
ok('uyuşan çavuş/tip/depo → tanım uyarısı YOK', !empty($on2['ok']) && !str_contains(implode(' ', $on2['uyarilar']), 'K018'), j($on2));

echo "\n=== 9. Hakediş — tanımlı dönem normal dönemle AYNI satırı üretir ===\n";
$sH = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['H'], 1, db())['session']['id'];
// H'nin bugünkü mesaisi açıldı; K019 tanımlı, K020 normal — ikisi de Kadın.
tanimYaz('K019', $cavus['H'], $kadin);
$r1 = tanimli(uid('K019'));
$r2 = normal(uid('K020'), $sH, $kadin);
ok('hazırlık: K019 tanımlı + K020 normal giriş (aynı mesai)', !empty($r1['ok']) && !empty($r2['ok']) && (int)$r1['cavus']['session_id'] === $sH, j([$r1, $r2]));
pdks_gunluk_ortak_cikis_kaydet(uid('K019'), 'usb_decimal', 1, db());
pdks_gunluk_ortak_cikis_kaydet(uid('K020'), 'usb_decimal', 1, db());
db()->prepare("UPDATE daily_worker_work_periods SET entry_time = ?, exit_time = ?, overtime_approved_hours = 0 WHERE session_id = ?")
    ->execute(["$bugun 07:00:00", "$bugun 16:00:00", $sH]);
$o = pdks_faz8b_oran_ekle($cavus['H'], $kadin, '1000', '500', 'hourly', '100', '2020-01-01', 'TRY', 1, db());
ok('oran tanımı kabul', !empty($o['ok']), j($o));
$h = pdks_faz8b_hakedis_hesapla($sH, 1, db());
// work_period_id dışındaki TÜM satır alanları (tip, sınıf, adet, birim, FM, toplam) karşılaştırılır.
$tutarlar = array_map(function ($l) { unset($l['work_period_id']); return json_encode($l); }, $h['lines'] ?? []);
ok('hakediş hesaplandı: 2 satır, toplam 2000.00', !empty($h['ok']) && count($h['lines'] ?? []) === 2 && ($h['total_amount'] ?? '') === '2000.00', j($h));
ok('iki satır (tanımlı + normal) work_period_id dışında BİREBİR aynı (line_total 1000.00)', count($tutarlar) === 2 && count(array_unique($tutarlar)) === 1 && (string)($h['lines'][0]['line_total'] ?? '') === '1000.00', j($h['lines'] ?? null));

echo "\n=== 10. Kart Sorgula — tanım bilgisi (salt okunur) ===\n";
$q = pdks_gunluk_faz8a_kart_sorgula(pdks_uid_from_decimal(uid('K002')), db());
ok('sorgu yanıtında tanım (çavuş, tip, depo)', ($q['tanim']['foreman_name'] ?? '') === 'Çavuş B' && ($q['tanim']['tip_adi'] ?? '') === 'Kadın' && ($q['tanim']['depo'] ?? '') === 'Depo A', j($q['tanim'] ?? null));
$q = pdks_gunluk_faz8a_kart_sorgula(pdks_uid_from_decimal(uid('K004')), db());
ok('tanımsız kartta tanim = null', array_key_exists('tanim', $q) && $q['tanim'] === null);

echo "\n=== 11. Kaynak sözleşmeleri (statik) ===\n";
$lib = (string)file_get_contents($ROOT . '/config/pdks_gunluk.php');
$fnGovde = function (string $src, string $ad): string {
    $a = strpos($src, 'function ' . $ad . '(');
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a);
};
$tg = $fnGovde($lib, 'pdks_gunluk_tanimli_giris_kaydet');
ok('tanimli_giris_kaydet var', $tg !== '');
ok('tanimli_giris_kaydet kendi INSERT/UPDATE/DELETE YAZMAZ (tek yazma yolu)', $tg !== '' && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $tg));
ok("yazmayı pdks_gunluk_faz8a_giris_kaydet(..., 'tanimli')'ye devreder", (bool)preg_match("/pdks_gunluk_faz8a_giris_kaydet\([^;]*'tanimli'\)/", $tg));
ok('mesaiyi kiosk yolundan açar (pdks_gunluk_oturum_ac_veya_getir)', str_contains($tg, 'pdks_gunluk_oturum_ac_veya_getir('));
ok('tanımsız kartı otomatik kaydetmez (kart_olustur ÇAĞRILMAZ)', !str_contains($tg, 'pdks_gunluk_kart_olustur('));
$gk = $fnGovde($lib, 'pdks_gunluk_faz8a_giris_kaydet');
$kilitPos = strpos($gk, 'pdks_gunluk_faz8a_kart_kilitle(');
$tanimPos = strpos($gk, 'pdks_gunluk_kart_tanim_aktif(');
ok('giris_kaydet: tanım kart KİLİDİNDEN SONRA okunur', $kilitPos !== false && $tanimPos !== false && $tanimPos > $kilitPos);
ok("giris_kaydet: dönem kaynağı beyaz liste ('scan','tanimli')", str_contains($gk, "in_array(\$donemKaynak, ['scan', 'tanimli'], true)"));
ok('tablo genel hazır-mı listelerine EKLENMEDİ', !array_key_exists('worker_card_assignments', pdks_gunluk_tablolar())
    && !array_key_exists('worker_card_assignments', pdks_gunluk_faz8a_tablolar())
    && !str_contains($fnGovde($lib, 'pdks_gunluk_sema_hazir'), 'worker_card_assignments')
    && !str_contains($fnGovde($lib, 'pdks_gunluk_faz8a_sema_hazir'), 'worker_card_assignments')
    && !str_contains($fnGovde($lib, 'pdks_gunluk_faz8a_sema_hazir'), 'kart_tanim'));
$gi = (string)file_get_contents($ROOT . '/gunluk_isci_giris_cikis.php');
$a = strpos($gi, "=== 'tanimli_giris')");
$uc = $a === false ? '' : substr($gi, $a, strpos($gi, "exit;\n}", $a) - $a);
ok('tanimli_giris ucu var', $uc !== '');
ok('uç: CSRF + daily_scan', str_contains($uc, 'csrf_check(') && str_contains($uc, "require_pdks_gunluk('daily_scan')"));
ok('uç: istemciden session_id / foreman_id / worker_type_id OKUMAZ', !preg_match('/session_id|foreman_id|worker_type_id/', $uc));
ok('uç: yazma yalnız pdks_gunluk_tanimli_giris_kaydet() üzerinden', str_contains($uc, 'pdks_gunluk_tanimli_giris_kaydet(') && !preg_match('/INSERT|UPDATE|_giris_kaydet\(\s*\$|faz8a_cikis_kaydet/', $uc));
ok('ekranda migrate ÇAĞRILMAZ', !str_contains($gi, 'pdks_gunluk_kart_tanim_migrate(') && !str_contains((string)file_get_contents($ROOT . '/isci_kartlari.php'), 'pdks_gunluk_kart_tanim_migrate('));
ok('düğme yalnız tablo hazırsa çizilir ($tanimHazir)', (bool)preg_match('/if \(\$tanimHazir\): \?>\s*<!--[^>]*-->\s*<button[^>]*id="giTanimliGirisBtn"/s', $gi));
ok("JS: tanımlı modda istek ajax=tanimli_giris'e, session_id olmadan", str_contains($gi, "tanimliMod ? 'gunluk_isci_giris_cikis.php?ajax=tanimli_giris'") && str_contains($gi, '(ortakMod || tanimliMod)'));
ok('JS: ikinci kapatma yolu yok (ajax=kapat tek fetch)', substr_count($gi, "gunluk_isci_giris_cikis.php?ajax=kapat'") === 1);
$mig = (string)file_get_contents($ROOT . '/migrate.php');
ok('migrate.php: ayrı kart + pdks_gunluk_kart_tanim_migrate()', str_contains($mig, "'pdks_kart_tanim'") && str_contains($mig, 'pdks_gunluk_kart_tanim_migrate($pdo)'));
$isk = (string)file_get_contents($ROOT . '/isci_kartlari.php');
ok('Kart Havuzu: kart_tanim / kart_tanim_bitir POST + depo = aktif depo (istemciden değil)',
    str_contains($isk, "\$action === 'kart_tanim'") && str_contains($isk, "\$action === 'kart_tanim_bitir'") && str_contains($isk, '$aktifDepo, (int)$auth_user')
    && !preg_match('/\$_POST\[.tanim_depo/', $isk));
foreach (['config/pdks_hakedis.php', 'config/pdks_cari.php', 'config/pdks_rapor.php', 'config/pdks_faz8b.php', 'config/pdks_faz8b_cavus_b.php'] as $f) {
    ok("$f: 'tanimli' kaynağına göre dallanma YOK", !str_contains((string)@file_get_contents($ROOT . '/' . $f), "'tanimli'"));
}

echo "\n" . ($fail ? "$fail HATA, $gecen geçti\n" : "Tümü geçti ($gecen)\n");
exit($fail ? 1 : 0);
