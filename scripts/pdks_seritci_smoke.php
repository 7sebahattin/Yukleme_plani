<?php
// =========================================================
// scripts/pdks_seritci_smoke.php — v455 SERITCI (4. sabit sistem tipi; Rampacı emsali)
//
// Tip garantisi (idempotent, pasifse AÇMAZ), politika listesi, tip kayıt defteri, kiosk giriş +
// tanımlı giriş, kartsız/toplu ekleme, önizleme "fiyat yok" uyarısı, hakediş (fiyat yoksa durur [fail-closed] /
// fiyatla doğru tutar), Yöntem B kişi-günü, toplu döküm sayaçları, Otomatik Ata (Şeritçi'ye atamaz), statik denetimler.
// Bellek içi SQLite — canlı DB'ye dokunmaz.   php scripts/pdks_seritci_smoke.php
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
$seritci = (int)db()->query("SELECT id FROM worker_types WHERE code='SERITCI'")->fetchColumn();
$rampaci = (int)db()->query("SELECT id FROM worker_types WHERE code='RAMPACI'")->fetchColumn();
$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$karisik = (int)db()->query("SELECT id FROM worker_types WHERE code='KARISIK'")->fetchColumn();
ok('migrate SERITCI/Şeritçi satırını tohumlar (sort_order 5)', $seritci > 0 && db()->query("SELECT name FROM worker_types WHERE id=$seritci")->fetchColumn() === 'Şeritçi' && say("SELECT sort_order FROM worker_types WHERE id=$seritci") === 5);
ok('mevcut KADIN/ERKEK/RAMPACI sort_order DEĞİŞMEDİ (1, 2, 4)', say("SELECT sort_order FROM worker_types WHERE id=$kadin") === 1 && say("SELECT sort_order FROM worker_types WHERE id=$erkek") === 2 && say("SELECT sort_order FROM worker_types WHERE id=$rampaci") === 4);
pdks_gunluk_migrate(db());
ok('seed idempotent', say("SELECT COUNT(*) FROM worker_types WHERE code='SERITCI'") === 1);
db()->exec("DELETE FROM worker_types WHERE code='SERITCI'");
$g = pdks_gunluk_seritci_tip_garanti(db());
ok('tembel garanti: yoksa oluşturur', $g !== null && $g['code'] === 'SERITCI' && $g['name'] === 'Şeritçi' && (int)$g['sort_order'] === 5, j($g));
$g2 = pdks_gunluk_seritci_tip_garanti(db());
ok('tembel garanti idempotent (aynı id, tek satır)', (int)$g2['id'] === (int)$g['id'] && say("SELECT COUNT(*) FROM worker_types WHERE code='SERITCI'") === 1);
$seritci = (int)$g['id'];
db()->exec("UPDATE worker_types SET is_active=0 WHERE id=$seritci");
$g3 = pdks_gunluk_seritci_tip_garanti(db());
ok('pasif Şeritçi yeniden AÇILMAZ', (int)$g3['is_active'] === 0 && say("SELECT is_active FROM worker_types WHERE id=$seritci") === 0);
ok('pasif Şeritçi politika listesinde yok', array_column(pdks_gunluk_desteklenen_tip_listele(db()), 'code') === ['KADIN', 'ERKEK', 'RAMPACI']);
ok('pasif Şeritçi giriş kapısında reddedilir', pdks_gunluk_giris_tip_coz($seritci, db()) === null && pdks_gunluk_desteklenen_tip_coz($seritci, db()) === null);
db()->exec("UPDATE worker_types SET is_active=1 WHERE id=$seritci");
ok('politika kodları = KADIN, ERKEK, RAMPACI, SERITCI (Karışık hariç)', pdks_gunluk_desteklenen_tip_kodlari() === ['KADIN', 'ERKEK', 'RAMPACI', 'SERITCI']);
ok('politika listesi sırası Kadın, Erkek, Rampacı, Şeritçi', array_column(pdks_gunluk_desteklenen_tip_listele(db()), 'code') === ['KADIN', 'ERKEK', 'RAMPACI', 'SERITCI']);
ok('kiosk giriş listesi Kadın, Erkek, Rampacı, Şeritçi, Karışık', array_column(pdks_gunluk_giris_tip_listele(db()), 'code') === ['KADIN', 'ERKEK', 'RAMPACI', 'SERITCI', 'KARISIK']);
ok('Karışık HÂLÂ politika listesinde DEĞİL', !in_array('KARISIK', pdks_gunluk_desteklenen_tip_kodlari(), true));
ok('giriş kapısı Şeritçi\'yi kabul eder', (pdks_gunluk_giris_tip_coz($seritci, db())['code'] ?? '') === 'SERITCI' && (pdks_gunluk_desteklenen_tip_coz($seritci, db())['code'] ?? '') === 'SERITCI');
$kayit = pdks_gunluk_tip_kayit();
ok('kayıt defteri sırası KADIN, ERKEK, RAMPACI, SERITCI, KARISIK', array_keys($kayit) === ['KADIN', 'ERKEK', 'RAMPACI', 'SERITCI', 'KARISIK'], j(array_keys($kayit)));
ok('renkler kadin/erkek/rampaci/seritci/karisik', array_column($kayit, 'renk') === ['kadin', 'erkek', 'rampaci', 'seritci', 'karisik']);
ok('ad → kod: Şeritçi / ŞERİTÇİ / seritci / SERITCI → SERITCI',
    pdks_gunluk_tip_kod_adindan('Şeritçi') === 'SERITCI' && pdks_gunluk_tip_kod_adindan('ŞERİTÇİ') === 'SERITCI' && pdks_gunluk_tip_kod_adindan('seritci') === 'SERITCI' && pdks_gunluk_tip_kod_adindan('SERITCI') === 'SERITCI');
ok('rapor sistem sütunları = Kadın, Erkek, Rampacı, Şeritçi', array_column(pdks_gunluk_tip_sistem_sutunlari(), 'kisa') === ['Kadın', 'Erkek', 'Rampacı', 'Şeritçi']);
ok('tüm rapor sütunları (Karışık dahil) 5 adet, sutun anahtarları benzersiz', count(pdks_gunluk_tip_sutunlari()) === 5 && count(array_unique(array_column(pdks_gunluk_tip_sutunlari(), 'sutun'))) === 5);

echo "\n=== 2. Kiosk giriş + tanımlı giriş ===\n";
$cavus = [];
foreach (['A', 'B'] as $i => $h) $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C00' . ($i + 1), 'name' => 'Çavuş ' . $h], 1, db())['id'];
for ($i = 1; $i <= 12; $i++) pdks_gunluk_kart_olustur(['card_no' => sprintf('K%03d', $i), 'ham_uid' => (string)(300000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
$bugun = date('Y-m-d'); $dun = date('Y-m-d', strtotime('-1 day'));
$sid = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['A'], 1, db())['session']['id'];
$gir = pdks_gunluk_faz8a_giris_kaydet('300000001', 'usb_decimal', $sid, $seritci, 'auto', 1, db());
ok('kiosk GİRİŞ Şeritçi başarılı, tip adı Şeritçi', !empty($gir['ok']) && ($gir['card']['worker_type_name'] ?? '') === 'Şeritçi', j($gir));
pdks_gunluk_faz8a_giris_kaydet('300000002', 'usb_decimal', $sid, $seritci, 'auto', 1, db());
pdks_gunluk_faz8a_giris_kaydet('300000003', 'usb_decimal', $sid, $kadin, 'auto', 1, db());
$oz = pdks_gunluk_oturum_ozet($sid, db());
ok('özet: Şeritçi 2, Kadın 1, toplam 3', ($oz['giris']['Şeritçi'] ?? 0) === 2 && ($oz['giris']['Kadın'] ?? 0) === 1 && $oz['giris_toplam'] === 3, j($oz['giris']));
$rc = pdks_gunluk_ortak_cikis_kaydet('300000001', 'usb_decimal', 1, db());
ok('ortak çıkış Şeritçi kartı için çalışır, tip Şeritçi', !empty($rc['ok']) && ($rc['card']['worker_type_name'] ?? '') === 'Şeritçi', j($rc));
$oz = pdks_gunluk_oturum_ozet($sid, db());
ok('ÇIKIŞ "kalan" kaynağı: eksik_tip Şeritçi = 1', ($oz['eksik_tip']['Şeritçi'] ?? 0) === 1, j($oz['eksik_tip']));

sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);
$t = pdks_gunluk_kart_tanim_kaydet(kartId('K010'), $cavus['B'], $seritci, 'Depo A', 1, db());
ok('karta Şeritçi tanımı yazılır', !empty($t['ok']), j($t));
$tg = pdks_gunluk_tanimli_giris_kaydet('300000010', 'usb_decimal', 1, db());
ok('tanımlı giriş Şeritçi olarak girer (source=tanimli)', !empty($tg['ok']) && ($tg['card']['worker_type_name'] ?? '') === 'Şeritçi'
    && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE worker_card_id=" . kartId('K010') . " AND source='tanimli' AND worker_type_id_snapshot=$seritci") === 1, j($tg));

echo "\n=== 3. Kartsız + toplu ekleme (geçmiş gün) + önizleme 'fiyat yok' uyarısı ===\n";
$r = pdks_faz8j_gecmis_ekle(['foreman_id' => $cavus['B'], 'work_date' => $dun, 'kartsiz' => 1, 'worker_card_id' => 0, 'worker_type_id' => $seritci,
    'entry_date' => $dun, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00', 'reason' => 'Test', 'note' => '', 'depo' => 'Depo A'], 1, db());
ok('kartsız mesai Şeritçi olarak eklenir', !empty($r['ok']), j($r));
$tp = pdks_faz8j_toplu_onizle(['foreman_id' => $cavus['B'], 'work_date' => $dun, 'depo' => 'Depo A', 'reason' => 'x',
    'gruplar' => [['worker_type_id' => $seritci, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00', 'kart_ids' => [kartId('K006')], 'kartsiz_adet' => 2]]], 1, db());
ok('toplu önizleme Şeritçi grubunu kabul eder', !empty($tp['ok']), j($tp['hatalar'] ?? $tp));
$fy = array_values(array_filter($tp['uyarilar'] ?? [], fn($u) => str_contains($u, 'geçerli fiyat YOK')));
ok('önizleme: fiyat tanımsız Şeritçi için UYARI (engel DEĞİL; ok=true)', count($fy) === 1 && str_contains($fy[0], '«Şeritçi»') && str_contains($fy[0], date('d.m.Y', strtotime($dun))), j($tp['uyarilar'] ?? null));
db()->prepare("INSERT INTO foreman_worker_rates (foreman_id, worker_type_id, daily_rate, half_day_rate, overtime_mode, overtime_rate, currency, valid_from, valid_to, is_active) VALUES (?,?,?,?,?,?,?,?,NULL,1)")
    ->execute([$cavus['B'], $seritci, '1800.00', '900.00', 'hourly', '100.00', 'TRY', '2020-01-01']);
$tp2 = pdks_faz8j_toplu_onizle(['foreman_id' => $cavus['B'], 'work_date' => $dun, 'depo' => 'Depo A', 'reason' => 'x',
    'gruplar' => [['worker_type_id' => $seritci, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00', 'kart_ids' => [kartId('K006')], 'kartsiz_adet' => 2]]], 1, db());
ok('fiyat tanımlanınca uyarı kalkar', array_values(array_filter($tp2['uyarilar'] ?? [], fn($u) => str_contains($u, 'geçerli fiyat YOK'))) === [], j($tp2['uyarilar'] ?? null));
db()->exec("DELETE FROM foreman_worker_rates WHERE foreman_id={$cavus['B']} AND worker_type_id=$seritci");

echo "\n=== 4. Hakediş: fiyat yoksa durur (fail-closed), fiyatla doğru tutar; Yöntem B kişi-günü ===\n";
db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id, closed_at, closed_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute([$cavus['A'], 'Çavuş A', 'C001', $dun, 'Depo A', 'closed', "$dun 07:00:00", 1, "$dun 18:00:00", 1]);
$sidD = (int)db()->lastInsertId();
$insEv = db()->prepare("INSERT INTO daily_worker_card_events (session_id, worker_card_id, event_type, source, canonical_uid_snapshot, worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot, recorded_by_user_id, server_event_time) VALUES (?,?,?,'scan',?,?,?,?,?,1,?)");
$insP = db()->prepare("INSERT INTO daily_worker_work_periods (session_id, worker_card_id, worker_type_id_snapshot, worker_type_name_snapshot, entry_event_id, exit_event_id, entry_time, exit_time, declared_attendance_class, work_date_snapshot, depo_snapshot, status, source) VALUES (?,?,?,?,?,?,?,?,'auto',?,?,'closed','scan')");
$dagit = [[1, $kadin, 'Kadın'], [2, $kadin, 'Kadın'], [3, $seritci, 'Şeritçi'], [4, $seritci, 'Şeritçi'], [5, $seritci, 'Şeritçi']];
foreach ($dagit as [$n, $tid, $ad]) {
    $kid = kartId(sprintf('K%03d', $n));
    $insEv->execute([$sidD, $kid, 'GIRIS', 'U' . $n, $tid, $ad, $dun, 'Depo A', "$dun 08:00:00"]); $e1 = (int)db()->lastInsertId();
    $insEv->execute([$sidD, $kid, 'CIKIS', 'U' . $n, $tid, $ad, $dun, 'Depo A', "$dun 17:00:00"]); $e2 = (int)db()->lastInsertId();
    $insP->execute([$sidD, $kid, $tid, $ad, $e1, $e2, "$dun 08:00:00", "$dun 17:00:00", $dun, 'Depo A']);
}
$o = pdks_faz8b_oran_ekle($cavus['A'], $kadin, '1000', '500', 'hourly', '100', '2020-01-01', 'TRY', 1, db());
ok('Kadın fiyatı tanımlı', !empty($o['ok']), j($o));
$h = pdks_faz8b_hakedis_hesapla($sidD, 1, db());
ok('Şeritçi fiyatı YOKKEN hakediş durur (geçerli fiyat yok — fail-closed)', empty($h['ok']) && str_contains(j($h), 'geçerli fiyat yok'), j($h));
ok('… hiç hakediş satırı yazılmadı', say("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id=$sidD") === 0);
$o = pdks_faz8b_oran_ekle($cavus['A'], $seritci, '1800', '900', 'hourly', '100', '2020-01-01', 'TRY', 1, db());
ok('Şeritçi fiyatı tanımlanır (ayrı tip fiyatı)', !empty($o['ok']), j($o));
$h = pdks_faz8b_hakedis_hesapla($sidD, 1, db());
ok('hakediş: 2×1000 + 3×1800 = 7400.00', !empty($h['ok']) && $h['total_amount'] === '7400.00' && count($h['lines']) === 5, j($h));
ok('Şeritçi satırlarında worker_type_id dolu (Yöntem B "worker_type_id IS NOT NULL" kişi-günü sayar)', say("SELECT COUNT(*) FROM foreman_daily_entitlement_lines l JOIN foreman_daily_entitlements e ON e.id=l.entitlement_id WHERE e.session_id=$sidD AND l.worker_type_id=$seritci") === 3);
$bSrc = (string)file_get_contents($ROOT . '/config/pdks_faz8b_cavus_b.php');
ok('Yöntem B sayımı tip kimliğine göre (worker_type_id IS NOT NULL) — Şeritçi kendiliğinden dahil', str_contains($bSrc, 'worker_type_id IS NOT NULL') && !str_contains($bSrc, "'SERITCI'") && !str_contains($bSrc, "'RAMPACI'"));

echo "\n=== 5. Toplu döküm + gün listesi sayaçları ===\n";
$kidK = kartId('K007');
$insEv->execute([$sidD, $kidK, 'GIRIS', 'U7', $karisik, 'Karışık', $dun, 'Depo A', "$dun 08:00:00"]); $e1 = (int)db()->lastInsertId();
$insEv->execute([$sidD, $kidK, 'CIKIS', 'U7', $karisik, 'Karışık', $dun, 'Depo A', "$dun 17:00:00"]); $e2 = (int)db()->lastInsertId();
$insP->execute([$sidD, $kidK, $karisik, 'Karışık', $e1, $e2, "$dun 08:00:00", "$dun 17:00:00", $dun, 'Depo A']);
$td = pdks_rapor_cavus_toplu_dokum(substr($dun, 0, 7), 'Depo A', $cavus['A'], false, db());
$satir = null; foreach ($td as $r) if ($r['session_id'] === $sidD) $satir = $r;
ok('toplu döküm satırı: kadin 2, erkek 0, rampaci 0, seritci 3, karisik 1', $satir && $satir['kadin'] === 2 && $satir['erkek'] === 0 && $satir['rampaci'] === 0 && $satir['seritci'] === 3 && $satir['karisik'] === 1, j($satir));
ok('Kadın + Erkek + Rampacı + Şeritçi + Karışık = Toplam (6)', $satir && $satir['kadin'] + $satir['erkek'] + $satir['rampaci'] + $satir['seritci'] + $satir['karisik'] === $satir['toplam_isci'] && $satir['toplam_isci'] === 6, j($satir));
$gl = pdks_gunluk_gun_listesi($dun, 'Depo A', null, null, db());
$gs = null; foreach ($gl as $r) if ((int)$r['session']['id'] === $sidD) $gs = $r;
ok('gün listesi: giris[Şeritçi]=3, Kadın=2, Karışık=1, toplam=6', $gs && ($gs['giris']['Şeritçi'] ?? 0) === 3 && ($gs['giris']['Kadın'] ?? 0) === 2 && ($gs['giris']['Karışık'] ?? 0) === 1 && $gs['giris_toplam'] === 6, j($gs['giris'] ?? null));

echo "\n=== 6. Otomatik Ata yalnız Kadın/Erkek (Şeritçi'ye atamaz) ===\n";
$ka = pdks_faz8j_karisik_ata($sidD, 1, 0, 'test', hex(), 1, db());
ok('Karışık → Kadın atandı; Şeritçi sayısı DEĞİŞMEDİ (3)', !empty($ka['ok']) && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id=$sidD AND worker_type_id_snapshot=$seritci AND is_voided=0") === 3, j($ka));
$fSrc = (string)file_get_contents($ROOT . '/config/pdks_faz8j.php');
ok('Otomatik Ata kaynağında SERITCI/RAMPACI hedefi YOK', !preg_match('/function pdks_faz8j_karisik_ata.*?SERITCI/s', $fSrc));

echo "\n=== 7. Statik: CSV başlıkları DEĞİŞMEDİ; ekran/CSS/ikon/XLSX/İşçi Tipleri ===\n";
$pz = (string)file_get_contents($ROOT . '/gunluk_isci_puantaj.php');
ok('Günlük puantaj CSV başlığı aynen (Şeritçi sütunu YOK)', str_contains($pz, "fputcsv(\$out, ['Tarih', 'Depo', 'Çavuş', 'Kadın Sayısı', 'Erkek Sayısı', 'Toplam Giriş',") && !str_contains($pz, 'Şeritçi Sayısı'));
$css = (string)file_get_contents($ROOT . '/assets/pdks.css');
ok('pdks.css: Şeritçi (teal) düğme/çember/sonuç/rozet/toplu grup/sayaç sınıfları', str_contains($css, 'pdks-kiosk-typebtn-seritci') && str_contains($css, 'pdks-tip-seritci') && str_contains($css, 'pdks-sonuc-seritci') && str_contains($css, 'pdks-kiosk-type-badge-seritci') && str_contains($css, 'is-seritci') && str_contains($css, 'tv-grup-seritci') && str_contains($css, 'tk-sayac-tip-seritci'));
ok('pdks.css: 5 düğmeli tip seçimi taşma çözümü (yalnız #giTipSec popup\'ı, max-height + 2 kolon)', str_contains($css, 'calc(100dvh - 24px)') && str_contains($css, '.pdks-kiosk-type-popup > #giTipVazgec'));
$tp = (string)file_get_contents($ROOT . '/_puantaj_toplu.php');
ok('Toplu İşlem: Şeritçi ikon beyaz listede (kadın ikonuna düşmez)', str_contains($tp, "'seritci'     =>") && str_contains($tp, "['erkek', 'rampaci', 'seritci']"));
$tx = (string)file_get_contents($ROOT . '/cavus_toplu_dokum.php');
ok('Toplu Döküm XLSX: Şeritçi renk haritası', str_contains($tx, "'seritci' => ['ac'"));
$it = (string)file_get_contents($ROOT . '/isci_tipleri.php');
ok('İşçi Tipleri: "dört sabit sistem tipi" + ŞERİTÇİ; eski "üç" metni yok', str_contains($it, 'dört sabit sistem tipi') && str_contains($it, 'ŞERİTÇİ') && !str_contains($it, 'üç sabit sistem tipi') && str_contains($it, 'pdks_gunluk_seritci_tip_garanti'));

echo "\nSONUÇ: $gecen geçti, $fail hata\n";
exit($fail === 0 ? 0 : 1);
