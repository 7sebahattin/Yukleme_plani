<?php
// =========================================================
// scripts/pdks_rampaci_smoke.php — v299 RAMPACI (3. sabit sistem tipi)
//
// Tip garantisi (idempotent, pasifse AÇMAZ), politika listesi, tip kayıt defteri, kiosk giriş +
// ortak çıkış + tanımlı giriş, kartsız/toplu ekleme, hakediş (fiyat yoksa durur / fiyatla doğru tutar),
// toplu döküm sayaçları (Kadın+Erkek+Rampacı+Karışık=Toplam), Otomatik Ata ve CSV başlıkları (statik).
// Bellek içi SQLite — canlı DB'ye dokunmaz.   php scripts/pdks_rampaci_smoke.php
// =========================================================
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
$AUDIT = [];
function audit_log_event(string $a, string $m, int $id, ?array $o = null, ?array $n = null): void { global $AUDIT; $AUDIT[] = [$a, $m, $id]; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_rapor.php';

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

db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
foreach (pdks_hakedis_tablolar() as $sql) {
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
pdks_faz8b_migrate(db());

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-96s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function hex(): string { return bin2hex(random_bytes(16)); }
function tipSay(int $sid, int $tip): int {
    $st = db()->prepare('SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id=? AND worker_type_id_snapshot=? AND is_voided=0');
    $st->execute([$sid, $tip]); return (int)$st->fetchColumn();
}


function sqlite_kur(string $mysql): void { [$c, $ix] = pdks_ddl_sqlite($mysql); db()->exec($c); foreach ($ix as $x) db()->exec($x); }
function say(string $sql, array $p = []): int { $st = db()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
function kartId(string $no): int { return say("SELECT id FROM worker_cards WHERE card_no = ?", [$no]); }

echo "\n=== 1. Tip garantisi + politika + kayıt defteri ===\n";
$rampaci = (int)db()->query("SELECT id FROM worker_types WHERE code='RAMPACI'")->fetchColumn();
$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$karisik = (int)db()->query("SELECT id FROM worker_types WHERE code='KARISIK'")->fetchColumn();
ok('migrate RAMPACI/Rampacı satırını tohumlar', $rampaci > 0 && db()->query("SELECT name FROM worker_types WHERE id=$rampaci")->fetchColumn() === 'Rampacı');
ok('mevcut KADIN/ERKEK sort_order DEĞİŞMEDİ (1, 2); RAMPACI 4', say("SELECT sort_order FROM worker_types WHERE id=$kadin") === 1 && say("SELECT sort_order FROM worker_types WHERE id=$erkek") === 2 && say("SELECT sort_order FROM worker_types WHERE id=$rampaci") === 4);
pdks_gunluk_migrate(db());
ok('seed idempotent', say("SELECT COUNT(*) FROM worker_types WHERE code='RAMPACI'") === 1);
db()->exec("DELETE FROM worker_types WHERE code='RAMPACI'");
$g = pdks_gunluk_rampaci_tip_garanti(db());
ok('tembel garanti: yoksa oluşturur', $g !== null && $g['code'] === 'RAMPACI' && $g['name'] === 'Rampacı', j($g));
$g2 = pdks_gunluk_rampaci_tip_garanti(db());
ok('tembel garanti idempotent (aynı id, tek satır)', (int)$g2['id'] === (int)$g['id'] && say("SELECT COUNT(*) FROM worker_types WHERE code='RAMPACI'") === 1);
$rampaci = (int)$g['id'];
db()->exec("UPDATE worker_types SET is_active=0 WHERE id=$rampaci");
$g3 = pdks_gunluk_rampaci_tip_garanti(db());
ok('pasif Rampacı yeniden AÇILMAZ', (int)$g3['is_active'] === 0 && say("SELECT is_active FROM worker_types WHERE id=$rampaci") === 0);
ok('pasif Rampacı politika listesinde yok', array_column(pdks_gunluk_desteklenen_tip_listele(db()), 'code') === ['KADIN', 'ERKEK']);
db()->exec("UPDATE worker_types SET is_active=1 WHERE id=$rampaci");
ok('politika kodları = KADIN, ERKEK, RAMPACI (Karışık hariç)', pdks_gunluk_desteklenen_tip_kodlari() === ['KADIN', 'ERKEK', 'RAMPACI']);
ok('politika listesi sırası Kadın, Erkek, Rampacı', array_column(pdks_gunluk_desteklenen_tip_listele(db()), 'code') === ['KADIN', 'ERKEK', 'RAMPACI']);
ok('kiosk giriş listesi Kadın, Erkek, Rampacı, Karışık', array_column(pdks_gunluk_giris_tip_listele(db()), 'code') === ['KADIN', 'ERKEK', 'RAMPACI', 'KARISIK']);
ok('giriş kapısı Rampacı\'yı kabul eder', (pdks_gunluk_giris_tip_coz($rampaci, db())['code'] ?? '') === 'RAMPACI' && (pdks_gunluk_desteklenen_tip_coz($rampaci, db())['code'] ?? '') === 'RAMPACI');
$kayit = pdks_gunluk_tip_kayit();
ok('kayıt defteri sırası KADIN, ERKEK, RAMPACI, KARISIK', array_keys($kayit) === ['KADIN', 'ERKEK', 'RAMPACI', 'KARISIK'], j(array_keys($kayit)));
ok('renkler kadin/erkek/rampaci/karisik', array_column($kayit, 'renk') === ['kadin', 'erkek', 'rampaci', 'karisik']);
ok('ad → kod: Rampacı / RAMPACI / rampaci → RAMPACI; Karışık → KARISIK; bilinmeyen null',
    pdks_gunluk_tip_kod_adindan('Rampacı') === 'RAMPACI' && pdks_gunluk_tip_kod_adindan('RAMPACI') === 'RAMPACI' && pdks_gunluk_tip_kod_adindan('rampaci') === 'RAMPACI'
    && pdks_gunluk_tip_kod_adindan('Karışık') === 'KARISIK' && pdks_gunluk_tip_kod_adindan('Kadın') === 'KADIN' && pdks_gunluk_tip_kod_adindan('Forklift') === null);
ok('rapor sistem sütunları = Kadın, Erkek, Rampacı', array_column(pdks_gunluk_tip_sistem_sutunlari(), 'kisa') === ['Kadın', 'Erkek', 'Rampacı']);

echo "\n=== 2. Kiosk giriş + ortak çıkış ===\n";
$cavus = [];
foreach (['A', 'B'] as $i => $h) $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C00' . ($i + 1), 'name' => 'Çavuş ' . $h], 1, db())['id'];
for ($i = 1; $i <= 12; $i++) pdks_gunluk_kart_olustur(['card_no' => sprintf('K%03d', $i), 'ham_uid' => (string)(300000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
$bugun = date('Y-m-d'); $dun = date('Y-m-d', strtotime('-1 day'));
$sid = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['A'], 1, db())['session']['id'];
$gir = pdks_gunluk_faz8a_giris_kaydet('300000001', 'usb_decimal', $sid, $rampaci, 'auto', 1, db());
ok('kiosk GİRİŞ Rampacı başarılı, tip adı Rampacı', !empty($gir['ok']) && ($gir['card']['worker_type_name'] ?? '') === 'Rampacı', j($gir));
pdks_gunluk_faz8a_giris_kaydet('300000002', 'usb_decimal', $sid, $rampaci, 'auto', 1, db());
pdks_gunluk_faz8a_giris_kaydet('300000003', 'usb_decimal', $sid, $kadin, 'auto', 1, db());
pdks_gunluk_faz8a_giris_kaydet('300000004', 'usb_decimal', $sid, $erkek, 'auto', 1, db());
pdks_gunluk_faz8a_giris_kaydet('300000005', 'usb_decimal', $sid, $karisik, 'auto', 1, db());
$oz = pdks_gunluk_oturum_ozet($sid, db());
ok('özet: Rampacı 2, Kadın 1, Erkek 1, Karışık 1, toplam 5', ($oz['giris']['Rampacı'] ?? 0) === 2 && ($oz['giris']['Kadın'] ?? 0) === 1 && ($oz['giris']['Erkek'] ?? 0) === 1 && ($oz['giris']['Karışık'] ?? 0) === 1 && $oz['giris_toplam'] === 5, j($oz['giris']));
$rc = pdks_gunluk_ortak_cikis_kaydet('300000001', 'usb_decimal', 1, db());
ok('ortak çıkış Rampacı kartı için çalışır, tip Rampacı', !empty($rc['ok']) && ($rc['card']['worker_type_name'] ?? '') === 'Rampacı', j($rc));
$oz = pdks_gunluk_oturum_ozet($sid, db());
ok('ÇIKIŞ "kalan" kaynağı: eksik_tip Rampacı = 1', ($oz['eksik_tip']['Rampacı'] ?? 0) === 1, j($oz['eksik_tip']));

echo "\n=== 3. Tanımlı giriş (Rampacı tanımı) ===\n";
sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);
$t = pdks_gunluk_kart_tanim_kaydet(kartId('K010'), $cavus['B'], $rampaci, 'Depo A', 1, db());
ok('karta Rampacı tanımı yazılır', !empty($t['ok']), j($t));
$tg = pdks_gunluk_tanimli_giris_kaydet('300000010', 'usb_decimal', 1, db());
ok('tanımlı giriş Rampacı olarak girer (source=tanimli)', !empty($tg['ok']) && ($tg['card']['worker_type_name'] ?? '') === 'Rampacı'
    && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE worker_card_id=" . kartId('K010') . " AND source='tanimli' AND worker_type_id_snapshot=$rampaci") === 1, j($tg));
$tk = pdks_gunluk_kart_tanim_kaydet(kartId('K011'), $cavus['B'], $karisik, 'Depo A', 1, db());
ok('Karışık tanımı hâlâ reddedilir', empty($tk['ok']), j($tk));

echo "\n=== 4. Kartsız + toplu ekleme (geçmiş gün) ===\n";
$r = pdks_faz8j_gecmis_ekle(['foreman_id' => $cavus['B'], 'work_date' => $dun, 'kartsiz' => 1, 'worker_card_id' => 0, 'worker_type_id' => $rampaci,
    'entry_date' => $dun, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00', 'reason' => 'Test', 'note' => '', 'depo' => 'Depo A'], 1, db());
ok('kartsız mesai Rampacı olarak eklenir', !empty($r['ok']), j($r));
$tp = pdks_faz8j_toplu_onizle(['foreman_id' => $cavus['B'], 'work_date' => $dun, 'depo' => 'Depo A', 'reason' => 'x',
    'gruplar' => [['worker_type_id' => $rampaci, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00', 'kart_ids' => [kartId('K006')], 'kartsiz_adet' => 2]]], 1, db());
ok('toplu önizleme Rampacı grubunu kabul eder', !empty($tp['ok']), j($tp['hatalar'] ?? $tp));

echo "\n=== 5. Hakediş: fiyat yoksa durur, fiyatla doğru tutar ===\n";
db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id, closed_at, closed_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute([$cavus['A'], 'Çavuş A', 'C001', $dun, 'Depo A', 'closed', "$dun 07:00:00", 1, "$dun 18:00:00", 1]);
$sidD = (int)db()->lastInsertId();
$insEv = db()->prepare("INSERT INTO daily_worker_card_events (session_id, worker_card_id, event_type, source, canonical_uid_snapshot, worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot, recorded_by_user_id, server_event_time) VALUES (?,?,?,'scan',?,?,?,?,?,1,?)");
$insP = db()->prepare("INSERT INTO daily_worker_work_periods (session_id, worker_card_id, worker_type_id_snapshot, worker_type_name_snapshot, entry_event_id, exit_event_id, entry_time, exit_time, declared_attendance_class, work_date_snapshot, depo_snapshot, status, source) VALUES (?,?,?,?,?,?,?,?,'auto',?,?,'closed','scan')");
$dagit = [[1, $kadin, 'Kadın'], [2, $kadin, 'Kadın'], [3, $rampaci, 'Rampacı'], [4, $rampaci, 'Rampacı'], [5, $rampaci, 'Rampacı']];
foreach ($dagit as [$n, $tid, $ad]) {
    $kid = kartId(sprintf('K%03d', $n));
    $insEv->execute([$sidD, $kid, 'GIRIS', 'U' . $n, $tid, $ad, $dun, 'Depo A', "$dun 08:00:00"]); $e1 = (int)db()->lastInsertId();
    $insEv->execute([$sidD, $kid, 'CIKIS', 'U' . $n, $tid, $ad, $dun, 'Depo A', "$dun 17:00:00"]); $e2 = (int)db()->lastInsertId();
    $insP->execute([$sidD, $kid, $tid, $ad, $e1, $e2, "$dun 08:00:00", "$dun 17:00:00", $dun, 'Depo A']);
}
$o = pdks_faz8b_oran_ekle($cavus['A'], $kadin, '1000', '500', 'hourly', '100', '2020-01-01', 'TRY', 1, db());
ok('Kadın fiyatı tanımlı', !empty($o['ok']), j($o));
$h = pdks_faz8b_hakedis_hesapla($sidD, 1, db());
ok('Rampacı fiyatı YOKKEN hakediş durur (geçerli fiyat yok)', empty($h['ok']), j($h));
ok('… hiç hakediş satırı yazılmadı', say("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id=$sidD") === 0);
$o = pdks_faz8b_oran_ekle($cavus['A'], $rampaci, '1500', '750', 'hourly', '100', '2020-01-01', 'TRY', 1, db());
ok('Rampacı fiyatı tanımlanır (ayrı tip fiyatı)', !empty($o['ok']), j($o));
$h = pdks_faz8b_hakedis_hesapla($sidD, 1, db());
ok('hakediş: 2×1000 + 3×1500 = 6500.00', !empty($h['ok']) && $h['total_amount'] === '6500.00' && count($h['lines']) === 5, j($h));

echo "\n=== 6. Toplu döküm + gün listesi sayaçları ===\n";
$kidK = kartId('K007');
$insEv->execute([$sidD, $kidK, 'GIRIS', 'U7', $karisik, 'Karışık', $dun, 'Depo A', "$dun 08:00:00"]); $e1 = (int)db()->lastInsertId();
$insEv->execute([$sidD, $kidK, 'CIKIS', 'U7', $karisik, 'Karışık', $dun, 'Depo A', "$dun 17:00:00"]); $e2 = (int)db()->lastInsertId();
$insP->execute([$sidD, $kidK, $karisik, 'Karışık', $e1, $e2, "$dun 08:00:00", "$dun 17:00:00", $dun, 'Depo A']);
$td = pdks_rapor_cavus_toplu_dokum(substr($dun, 0, 7), 'Depo A', $cavus['A'], false, db());
$satir = null; foreach ($td as $r) if ($r['session_id'] === $sidD) $satir = $r;
ok('toplu döküm satırı: kadin 2, erkek 0, rampaci 3, karisik 1', $satir && $satir['kadin'] === 2 && $satir['erkek'] === 0 && $satir['rampaci'] === 3 && $satir['karisik'] === 1, j($satir));
ok('Kadın + Erkek + Rampacı + Karışık = Toplam (6)', $satir && $satir['kadin'] + $satir['erkek'] + $satir['rampaci'] + $satir['karisik'] === $satir['toplam_isci'] && $satir['toplam_isci'] === 6, j($satir));
$gl = pdks_gunluk_gun_listesi($dun, 'Depo A', null, null, db());
$gs = null; foreach ($gl as $r) if ((int)$r['session']['id'] === $sidD) $gs = $r;
ok('gün listesi: giris[Rampacı]=3, Kadın=2, Karışık=1, toplam=6', $gs && ($gs['giris']['Rampacı'] ?? 0) === 3 && ($gs['giris']['Kadın'] ?? 0) === 2 && ($gs['giris']['Karışık'] ?? 0) === 1 && $gs['giris_toplam'] === 6, j($gs['giris'] ?? null));

echo "\n=== 7. Otomatik Ata yalnız Kadın/Erkek ===\n";
$ka = pdks_faz8j_karisik_ata($sidD, 1, 0, 'test', hex(), 1, db());
ok('Karışık → Kadın atandı; Rampacı sayısı DEĞİŞMEDİ (3)', !empty($ka['ok']) && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id=$sidD AND worker_type_id_snapshot=$rampaci AND is_voided=0") === 3, j($ka));

echo "\n=== 8. Statik: CSV/XLSX başlıkları DEĞİŞMEDİ, ekran kayıt defterinden ===\n";
$pz = (string)file_get_contents($ROOT . '/gunluk_isci_puantaj.php');
ok('Günlük puantaj CSV başlığı aynen (Kadın Sayısı, Erkek Sayısı — Rampacı sütunu YOK)', str_contains($pz, "fputcsv(\$out, ['Tarih', 'Depo', 'Çavuş', 'Kadın Sayısı', 'Erkek Sayısı', 'Toplam Giriş',") && !str_contains($pz, 'Rampacı Sayısı'));
foreach (['gunluk_isci_puantaj.php', 'cavus_hakedis.php', 'cavus_toplu_dokum.php', 'cavus_toplu_dokum_yazdir.php', 'gunluk_puantaj_liste_yazdir.php', 'cavus_hakedis_liste_yazdir.php', 'gunluk_isci_puantaj_detay.php'] as $f) {
    $src = (string)file_get_contents($ROOT . '/' . $f);
    ok("$f: tip sütunları kayıt defterinden döngüyle (elle Erkek <th>/kutu yok)", str_contains($src, 'pdks_gunluk_tip_') && !preg_match('#<th>\s*Erkek\s*</th>#u', $src) && !preg_match('#<div class="lbl">Erkek#u', $src));
}
$rp = (string)file_get_contents($ROOT . '/config/pdks_rapor.php');
ok('toplu döküm SQL sütunları tip kayıt defterinden (elle CASE yok)', str_contains($rp, 'pdks_gunluk_tip_kayit()') && !str_contains($rp, '{$kadinId}'));
$kiosk = (string)file_get_contents($ROOT . '/gunluk_isci_giris_cikis.php');
ok('kiosk: TIP_KAYIT sunucudan, ÇIKIŞ kalan dairesi sistem tipleri için', str_contains($kiosk, 'var TIP_KAYIT =') && str_contains($kiosk, 'tipKayit && tipKayit.sistem') && !str_contains($kiosk, "tipUst === 'KADIN' || tipUst === 'ERKEK'"));
$css = (string)file_get_contents($ROOT . '/assets/pdks.css');
ok('pdks.css: Rampacı (turuncu) düğme/çember/sonuç/rozet sınıfları', str_contains($css, 'pdks-kiosk-typebtn-rampaci') && str_contains($css, 'pdks-tip-rampaci') && str_contains($css, 'pdks-sonuc-rampaci') && str_contains($css, 'pdks-kiosk-type-badge-rampaci') && str_contains($css, 'is-rampaci'));

echo "\nSONUÇ: $gecen geçti, $fail hata\n";
exit($fail === 0 ? 0 : 1);
