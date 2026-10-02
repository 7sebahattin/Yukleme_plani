<?php
// =========================================================
// scripts/pdks_karisik_smoke.php — v295 KARIŞIK GİRİŞ + OTOMATİK ATA
//
// Kiosk giriş kapısı (KARISIK), politika listesinin değişmediği, tekil/toplu/
// kartsız/düzelt/fiyat kapılarının KARISIK'ı reddettiği, hakediş kapısı,
// pdks_faz8j_karisik_ata/ozet/atamalar/geri_al ve toplu döküm Karışık sayımı.
// Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_karisik_smoke.php   → çıkış kodu 0 = tüm testler geçti
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

echo "\n=== 1. Seed + kiosk giriş kapısı ===\n";
$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$karisik = (int)db()->query("SELECT id FROM worker_types WHERE code='KARISIK'")->fetchColumn();
ok('migrate KARISIK/Karışık satırını tohumlar', $karisik > 0 && db()->query("SELECT name FROM worker_types WHERE id=$karisik")->fetchColumn() === 'Karışık');
pdks_gunluk_migrate(db());
ok('seed idempotent (ikinci migrate yeni satır eklemez)', (int)db()->query("SELECT COUNT(*) FROM worker_types WHERE code='KARISIK'")->fetchColumn() === 1);
db()->exec("DELETE FROM worker_types WHERE code='KARISIK'");
$g = pdks_gunluk_karisik_tip_garanti(db());
ok('tembel garanti: satır yoksa oluşturur', $g !== null && $g['code'] === 'KARISIK' && $g['name'] === 'Karışık', j($g));
$g2 = pdks_gunluk_karisik_tip_garanti(db());
ok('tembel garanti idempotent (aynı id)', (int)$g2['id'] === (int)$g['id'] && (int)db()->query("SELECT COUNT(*) FROM worker_types WHERE code='KARISIK'")->fetchColumn() === 1);
$karisik = (int)$g['id'];
ok('politika listesi DEĞİŞMEDİ: desteklenen = KADIN, ERKEK', pdks_gunluk_desteklenen_tip_kodlari() === ['KADIN', 'ERKEK']);
ok('desteklenen liste KARISIK içermez', !in_array('KARISIK', array_column(pdks_gunluk_desteklenen_tip_listele(db()), 'code'), true));
ok('desteklenen çözüm KARISIK\'ı reddeder', pdks_gunluk_desteklenen_tip_coz($karisik, db()) === null);
ok('giriş kodları = KADIN, ERKEK, KARISIK', pdks_gunluk_giris_tip_kodlari() === ['KADIN', 'ERKEK', 'KARISIK']);
ok('giriş tip listesi Kadın, Erkek, Karışık sırasıyla', array_column(pdks_gunluk_giris_tip_listele(db()), 'code') === ['KADIN', 'ERKEK', 'KARISIK'], j(array_column(pdks_gunluk_giris_tip_listele(db()), 'code')));
ok('giriş çözümü KARISIK\'ı kabul eder', (pdks_gunluk_giris_tip_coz($karisik, db())['code'] ?? '') === 'KARISIK');
db()->exec("INSERT INTO worker_types (code, name, sort_order) VALUES ('FORKLIFT', 'Forklift', 9)");
$fork = (int)db()->lastInsertId();
ok('giriş çözümü desteklenmeyen FORKLIFT\'i yine reddeder', pdks_gunluk_giris_tip_coz($fork, db()) === null);
db()->exec("UPDATE worker_types SET is_active=0 WHERE id=$karisik");
ok('pasif KARISIK girişte reddedilir ve listede yok', pdks_gunluk_giris_tip_coz($karisik, db()) === null && count(pdks_gunluk_giris_tip_listele(db())) === 2);
db()->exec("UPDATE worker_types SET is_active=1 WHERE id=$karisik");

$cavus = [];
foreach (['A', 'B', 'C'] as $i => $h) $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C00' . ($i + 1), 'name' => 'Çavuş ' . $h], 1, db())['id'];
$kartlar = [];
for ($i = 1; $i <= 14; $i++) {
    $no = sprintf('K%03d', $i);
    pdks_gunluk_kart_olustur(['card_no' => $no, 'ham_uid' => (string)(200000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
    $kartlar[$no] = (int)db()->query("SELECT id FROM worker_cards WHERE card_no='$no'")->fetchColumn();
}
$bugun = date('Y-m-d'); $dun = date('Y-m-d', strtotime('-1 day'));
$oc = pdks_gunluk_oturum_ac_veya_getir($cavus['A'], 1, db());
$sidK = (int)$oc['session']['id'];
$gir = pdks_gunluk_faz8a_giris_kaydet('200000001', 'usb_decimal', $sidK, $karisik, 'auto', 1, db());
ok('kiosk GİRİŞ KARISIK ile başarılı', !empty($gir['ok']) && ($gir['card']['worker_type_name'] ?? '') === 'Karışık', j($gir));
$gf = pdks_gunluk_faz8a_giris_kaydet('200000002', 'usb_decimal', $sidK, $fork, 'auto', 1, db());
ok('kiosk GİRİŞ FORKLIFT reddedilir (tip_bulunamadi)', empty($gf['ok']) && ($gf['kod'] ?? '') === 'tip_bulunamadi', j($gf));
for ($i = 2; $i <= 6; $i++) pdks_gunluk_faz8a_giris_kaydet((string)(200000000 + $i), 'usb_decimal', $sidK, $karisik, 'auto', 1, db());
pdks_gunluk_faz8a_giris_kaydet('200000007', 'usb_decimal', $sidK, $kadin, 'auto', 1, db());
$ck = pdks_gunluk_faz8a_cikis_kaydet('200000002', 'usb_decimal', $sidK, 1, db());
ok('Karışık kişinin ÇIKIŞI çalışır, tip Karışık döner', !empty($ck['ok']) && ($ck['card']['worker_type_name'] ?? '') === 'Karışık', j($ck));
$oz = pdks_gunluk_oturum_ozet($sidK, db());
ok('oturum özeti Karışık satırı: giriş 6, çıkış 1, içeride 5', ($oz['giris']['Karışık'] ?? 0) === 6 && ($oz['cikis']['Karışık'] ?? 0) === 1 && ($oz['eksik_tip']['Karışık'] ?? 0) === 5, j($oz));
$ko = pdks_faz8j_karisik_ozet($sidK, db());
ok('karisik_ozet: kalan 6, açık 5, kapalı 1', $ko === ['karisik_kalan' => 6, 'acik' => 5, 'kapali' => 1], j($ko));

echo "\n=== 2. Diğer yollar KARISIK'ı reddeder ===\n";
$ekle = fn(array $o) => pdks_faz8j_gecmis_ekle($o + ['foreman_id' => $cavus['B'], 'work_date' => $dun, 'worker_card_id' => $kartlar['K010'], 'worker_type_id' => $karisik,
    'entry_date' => $dun, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00', 'reason' => 'Test', 'note' => '', 'depo' => 'Depo A'], 1, db());
$r = $ekle([]);
ok('tekil Çalışma Ekle KARISIK → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'desteklenmiyor'), j($r));
$r = $ekle(['kartsiz' => 1, 'worker_card_id' => 0]);
ok('kartsız ekleme KARISIK → ret', empty($r['ok']), j($r));
$tp = pdks_faz8j_toplu_onizle(['foreman_id' => $cavus['B'], 'work_date' => $dun, 'depo' => 'Depo A', 'reason' => 'x',
    'gruplar' => [['worker_type_id' => $karisik, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:00', 'kart_ids' => [$kartlar['K011']], 'kartsiz_adet' => 1]]], 1, db());
ok('toplu önizleme KARISIK → hata', empty($tp['ok']) && (bool)array_filter($tp['hatalar'], fn($h) => str_contains($h, 'desteklenmiyor')), j($tp['hatalar']));
$pK = (int)db()->query("SELECT id FROM daily_worker_work_periods WHERE session_id=$sidK AND worker_type_id_snapshot=$kadin")->fetchColumn();
$r = pdks_faz8j_duzelt(['period_id' => $pK, 'session_id' => $sidK, 'depo' => 'Depo A', 'worker_card_id' => $kartlar['K007'], 'worker_type_id' => $karisik,
    'entry_date' => $bugun, 'entry_clock' => '00:00', 'exit_date' => '', 'exit_clock' => '', 'reason' => 'x'], 1, db());
ok('düzelt hedefi KARISIK → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'desteklenmiyor'), j($r));
$r = pdks_faz8b_oran_ekle($cavus['A'], $karisik, '1000', '500', 'hourly', '100', '2020-01-01', 'TRY', 1, db());
ok('Faz 8B oran tanımı KARISIK → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'Karışık'), j($r));
$r = pdks_hakedis_oran_ekle($cavus['A'], $karisik, '1000', '2020-01-01', 'TRY', 1, db());
ok('Faz 4 oran tanımı KARISIK → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'Karışık'), j($r));
ok('KARISIK için hiç oran satırı yazılmadı', (int)db()->query("SELECT COUNT(*) FROM foreman_worker_rates WHERE worker_type_id=$karisik")->fetchColumn() === 0);
$src = (string)file_get_contents($ROOT . '/cavus_fiyatlari.php');
ok('cavus_fiyatlari.php tip listesi desteklenen listeden (KARISIK sunulmaz)', str_contains($src, 'pdks_gunluk_desteklenen_tip_listele(') && !str_contains($src, 'giris_tip_listele'));

echo "\n=== 3. Otomatik Ata — kapılar ===\n";
// Dünkü kapalı mesai: 10 Karışık (biri iptal), Tam/Yarım + FM onaylı satırlar
db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id, closed_at, closed_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute([$cavus['C'], 'Çavuş C', 'C003', $dun, 'Depo A', 'closed', "$dun 07:00:00", 1, "$dun 18:00:00", 1]);
$sidD = (int)db()->lastInsertId();
$insEv = db()->prepare("INSERT INTO daily_worker_card_events (session_id, worker_card_id, event_type, source, canonical_uid_snapshot, worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot, recorded_by_user_id, server_event_time) VALUES (?,?,?,'scan','X',?,?,?,?,1,?)");
$insP = db()->prepare("INSERT INTO daily_worker_work_periods (session_id, worker_card_id, worker_type_id_snapshot, worker_type_name_snapshot, entry_event_id, exit_event_id, entry_time, exit_time, declared_attendance_class, work_date_snapshot, depo_snapshot, status, source) VALUES (?,?,?,?,?,?,?,?,'auto',?,?,'closed','scan')");
$pidD = [];
for ($i = 1; $i <= 10; $i++) {
    $kid = $kartlar[sprintf('K%03d', $i)];
    $insEv->execute([$sidD, $kid, 'GIRIS', $karisik, 'Karışık', $dun, 'Depo A', "$dun 08:00:00"]); $e1 = (int)db()->lastInsertId();
    $insEv->execute([$sidD, $kid, 'CIKIS', $karisik, 'Karışık', $dun, 'Depo A', "$dun 17:00:00"]); $e2 = (int)db()->lastInsertId();
    $insP->execute([$sidD, $kid, $karisik, 'Karışık', $e1, $e2, "$dun 08:00:00", "$dun 17:00:00", $dun, 'Depo A']);
    $pidD[] = (int)db()->lastInsertId();
}
db()->exec("UPDATE daily_worker_work_periods SET is_voided=1, void_reason='test' WHERE id={$pidD[9]}");
db()->exec("UPDATE daily_worker_work_periods SET approved_attendance_class='yarim', approved_by_user_id=1, approved_at='$dun 18:00:00', overtime_approved=1, overtime_approved_hours=2, overtime_approved_by_user_id=1, overtime_approved_at='$dun 18:00:00' WHERE id IN ({$pidD[0]}, {$pidD[1]})");
$onaylarOnce = db()->query("SELECT id, approved_attendance_class, approved_by_user_id, overtime_approved, overtime_approved_hours FROM daily_worker_work_periods WHERE session_id=$sidD ORDER BY id")->fetchAll();
$olaylarOnce = db()->query("SELECT * FROM daily_worker_card_events WHERE session_id=$sidD ORDER BY id")->fetchAll();

ok('karisik_ozet iptal edileni saymaz (9)', pdks_faz8j_karisik_ozet($sidD, db())['karisik_kalan'] === 9);
$h = pdks_faz8b_hakedis_hesapla($sidD, 1, db());
ok('hakediş (8B) atanmamış Karışık varken DURUR, açık mesaj', empty($h['ok']) && ($h['kod'] ?? '') === 'karisik_atanmamis' && str_contains((string)$h['hata'], '9 Karışık kayıt atanmamış') && str_contains((string)$h['hata'], 'Otomatik Ata'), j($h));
$h = pdks_faz8b_hakedis_finalize($sidD, 1, true, db());
ok('kesinleştirme (8B) de DURUR', empty($h['ok']) && ($h['kod'] ?? '') === 'karisik_atanmamis', j($h));
$h = pdks_hakedis_hesapla($sidD, 1, db());
ok('eski motor (Faz 4) da DURUR', empty($h['ok']) && ($h['kod'] ?? '') === 'karisik_atanmamis', j($h));
ok('hiç hakediş satırı yazılmadı', (int)db()->query("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id=$sidD")->fetchColumn() === 0);

$ADMIN = false;
$r = pdks_faz8j_karisik_ata($sidD, 1, 1, 'x', hex(), 1, db());
ok('yönetici değil → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'yönetici'), j($r));
$ADMIN = true; $AKTIF_DEPO = 'Depo B';
$r = pdks_faz8j_karisik_ata($sidD, 1, 1, 'x', hex(), 1, db());
ok('başka aktif depo → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'depo'), j($r));
$AKTIF_DEPO = 'Depo A';
ok('sebep zorunlu', empty(pdks_faz8j_karisik_ata($sidD, 1, 1, '  ', hex(), 1, db())['ok']));
ok('istek_id zorunlu / biçimli', empty(pdks_faz8j_karisik_ata($sidD, 1, 1, 'x', '', 1, db())['ok']) && empty(pdks_faz8j_karisik_ata($sidD, 1, 1, 'x', 'ZZZ', 1, db())['ok']));
ok('toplam 0 → ret', empty(pdks_faz8j_karisik_ata($sidD, 0, 0, 'x', hex(), 1, db())['ok']));
ok('negatif → ret', empty(pdks_faz8j_karisik_ata($sidD, -1, 3, 'x', hex(), 1, db())['ok']));
$r = pdks_faz8j_karisik_ata($sidD, 6, 4, 'x', hex(), 1, db());
ok('havuzu aşan (10 > 9) → ret, mesaj havuzu söyler', empty($r['ok']) && str_contains((string)$r['hata'], '(9)'), j($r));
ok('reddedilen denemeler hiçbir dönemi değiştirmedi', tipSay($sidD, $karisik) === 9 && (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='karisik_ata'")->fetchColumn() === 0);
ok('Mesai yok → ret', empty(pdks_faz8j_karisik_ata(999999, 1, 0, 'x', hex(), 1, db())['ok']));

echo "\n=== 4. Otomatik Ata — kısmi atama ===\n";
db()->exec("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, currency, total_amount, needs_recalculation, calculated_at, calculated_by_user_id) VALUES ($sidD, {$cavus['C']}, 'Çavuş C', 'C003', '$dun', 'Depo A', 'draft', 'TRY', '0', 0, '$dun 18:00:00', 1)");
$ist1 = hex();
$r = pdks_faz8j_karisik_ata($sidD, 3, 2, 'Sayım: 3 kadın 2 erkek', $ist1, 1, db());
ok('kısmi atama başarılı: 3 Kadın, 2 Erkek, kalan 4', !empty($r['ok']) && $r['kadin'] === 3 && $r['erkek'] === 2 && $r['kalan'] === 4 && preg_match('/^KA\d{8}[0-9a-f]{8}$/', (string)$r['atama_id']), j($r));
$ka1 = (string)($r['atama_id'] ?? '');
ok('sayımlar birebir: Kadın 3, Erkek 2, Karışık 4', tipSay($sidD, $kadin) === 3 && tipSay($sidD, $erkek) === 2 && tipSay($sidD, $karisik) === 4);
ok('isim snapshot\'ları güncel (Kadın/Erkek)', (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id=$sidD AND ((worker_type_id_snapshot=$kadin AND worker_type_name_snapshot<>'Kadın') OR (worker_type_id_snapshot=$erkek AND worker_type_name_snapshot<>'Erkek'))")->fetchColumn() === 0);
ok('iptal edilmiş dönem havuzda değildi (Karışık kaldı)', (int)db()->query("SELECT worker_type_id_snapshot FROM daily_worker_work_periods WHERE id={$pidD[9]}")->fetchColumn() === $karisik);
$onaylarSonra = db()->query("SELECT id, approved_attendance_class, approved_by_user_id, overtime_approved, overtime_approved_hours FROM daily_worker_work_periods WHERE session_id=$sidD ORDER BY id")->fetchAll();
ok('Tam/Yarım + FM onayları KORUNDU', $onaylarOnce === $onaylarSonra, j($onaylarSonra));
ok('ham kart olayları DEĞİŞMEDİ', db()->query("SELECT * FROM daily_worker_card_events WHERE session_id=$sidD ORDER BY id")->fetchAll() === $olaylarOnce);
ok('taslak hakediş needs_recalculation=1', (int)db()->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=$sidD")->fetchColumn() === 1);
$au = json_decode((string)db()->query("SELECT new_values FROM audit_log WHERE action='karisik_ata' AND module='daily_work_sessions' AND record_id=$sidD")->fetchColumn(), true) ?: [];
ok('audit karisik_ata: atama_id, istek_id, sebep, sayılar, 5 dönem eski→yeni', ($au['atama_id'] ?? '') === $ka1 && ($au['istek_id'] ?? '') === $ist1 && ($au['reason'] ?? '') === 'Sayım: 3 kadın 2 erkek'
    && ($au['kadin'] ?? 0) === 3 && ($au['erkek'] ?? 0) === 2 && count($au['donemler'] ?? []) === 5
    && !array_filter($au['donemler'], fn($d) => $d['eski_tip_id'] !== $karisik || !in_array($d['yeni_kod'], ['KADIN', 'ERKEK'], true)), j($au));
$r = pdks_faz8j_karisik_ata($sidD, 1, 0, 'tekrar', $ist1, 1, db());
ok('aynı istek_id tekrar → ret (tekrar gönderim), hiçbir şey değişmez', empty($r['ok']) && !empty($r['tekrar']) && tipSay($sidD, $karisik) === 4, j($r));
$dg = pdks_gunluk_puantaj_denetim_gecmisi([], db(), 20, $sidD);
ok('işlem geçmişi etiketi "🎲 Karışık kayıtlar otomatik atandı" + sayılar', (bool)array_filter($dg, fn($d) => $d['action'] === 'karisik_ata' && str_contains($d['islem_etiket'], 'otomatik atandı') && str_contains((string)$d['detay'], '3 Kadın, 2 Erkek')), j($dg));

echo "\n=== 5. Toplu döküm Karışık sayımı ===\n";
$td = pdks_rapor_cavus_toplu_dokum(substr($dun, 0, 7), 'Depo A', $cavus['C'], false, db());
$tr = $td[0] ?? [];
ok('toplu döküm: Kadın 3 + Erkek 2 + Karışık 4 = toplam 9', ($tr['kadin'] ?? -1) === 3 && ($tr['erkek'] ?? -1) === 2 && ($tr['karisik'] ?? -1) === 4 && ($tr['toplam_isci'] ?? -1) === 9, j($tr));

echo "\n=== 6. Geri Al ===\n";
// Bir dönemi elle değiştir (Erkek→Kadın) — geri alma onu ATLAMALI.
$au1 = $au['donemler'];
$elleErkek = array_values(array_filter($au1, fn($d) => $d['yeni_kod'] === 'ERKEK'))[0]['period_id'];
db()->exec("UPDATE daily_worker_work_periods SET worker_type_id_snapshot=$kadin, worker_type_name_snapshot='Kadın' WHERE id=$elleErkek");
$lst = pdks_faz8j_karisik_atamalar($sidD, db());
ok('atamalar listesi: 1 atama, 4 geri alınabilir (1 elle değişti)', count($lst) === 1 && $lst[0]['atama_id'] === $ka1 && $lst[0]['geri_alinabilir'] === 4 && !$lst[0]['geri_alindi'] && $lst[0]['kadin'] === 3, j($lst));
ok('geri al: sebep zorunlu', empty(pdks_faz8j_karisik_geri_al($ka1, ' ', 1, db())['ok']));
ok('geri al: geçersiz kimlik', empty(pdks_faz8j_karisik_geri_al('KA00000000deadbeef', 'x', 1, db())['ok']));
$ADMIN = false; ok('geri al: yönetici değil → ret', empty(pdks_faz8j_karisik_geri_al($ka1, 'x', 1, db())['ok'])); $ADMIN = true;
db()->exec("UPDATE foreman_daily_entitlements SET status='final' WHERE session_id=$sidD");
$r = pdks_faz8j_karisik_geri_al($ka1, 'x', 1, db());
ok('geri al: kesin hakediş → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'kesinleşmiş'), j($r));
$r = pdks_faz8j_karisik_ata($sidD, 1, 0, 'x', hex(), 1, db());
ok('ata: kesin hakediş → ret', empty($r['ok']) && str_contains((string)$r['hata'], 'kesinleşmiş'), j($r));
db()->exec("UPDATE foreman_daily_entitlements SET status='draft', needs_recalculation=0 WHERE session_id=$sidD");
$r = pdks_faz8j_karisik_geri_al($ka1, 'Yanlış sayım', 1, db());
ok('geri al: 4 dönem Karışık\'a döndü, 1 atlandı', !empty($r['ok']) && $r['geri_alinan'] === 4 && $r['atlanan'] === 1, j($r));
ok('sayımlar: Karışık 8, Kadın 1 (elle), Erkek 0', tipSay($sidD, $karisik) === 8 && tipSay($sidD, $kadin) === 1 && tipSay($sidD, $erkek) === 0);
ok('elle değiştirilen dönem DOKUNULMADI', (int)db()->query("SELECT worker_type_id_snapshot FROM daily_worker_work_periods WHERE id=$elleErkek")->fetchColumn() === $kadin);
ok('geri al: needs_recalculation=1', (int)db()->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=$sidD")->fetchColumn() === 1);
ok('geri al ikinci kez → ret', empty(pdks_faz8j_karisik_geri_al($ka1, 'x', 1, db())['ok']));
$lst = pdks_faz8j_karisik_atamalar($sidD, db());
ok('atamalar listesi: geri alındı, 0 geri alınabilir', $lst[0]['geri_alindi'] === true && $lst[0]['geri_alinabilir'] === 0, j($lst));
ok('işlem geçmişi "↩️ Karışık ataması geri alındı"', (bool)array_filter(pdks_gunluk_puantaj_denetim_gecmisi([], db(), 20, $sidD), fn($d) => $d['action'] === 'karisik_geri_al' && str_contains($d['islem_etiket'], 'geri alındı')));
ok('ham kart olayları hâlâ DEĞİŞMEDİ', db()->query("SELECT * FROM daily_worker_card_events WHERE session_id=$sidD ORDER BY id")->fetchAll() === $olaylarOnce);

echo "\n=== 7. Rastgelelik + açık (içeride) dönemler ===\n";
// Bugünkü kiosk mesaisi: 6 Karışık (5 içeride). 1 Kadın atanır, geri alınır — 25 kez.
$secilen = [];
for ($t = 0; $t < 25; $t++) {
    $r = pdks_faz8j_karisik_ata($sidK, 1, 0, 'rastgele', hex(), 1, db());
    if (empty($r['ok'])) { ok('rastgelelik turu', false, j($r)); break; }
    $secilen[] = (int)db()->query("SELECT id FROM daily_worker_work_periods WHERE session_id=$sidK AND worker_type_id_snapshot=$kadin AND worker_card_id<>{$kartlar['K007']}")->fetchColumn();
    pdks_faz8j_karisik_geri_al($r['atama_id'], 'tur', 1, db());
}
ok('25 turda birden fazla farklı dönem seçildi (sabit sıra değil)', count(array_unique($secilen)) > 1, j(array_count_values($secilen)));
$r = pdks_faz8j_karisik_ata($sidK, 4, 2, 'tamamı', hex(), 1, db());
ok('açık dönemler dahil tamamı atandı (6/6, kalan 0)', !empty($r['ok']) && $r['kalan'] === 0 && tipSay($sidK, $karisik) === 0, j($r));
$oz = pdks_gunluk_oturum_ozet($sidK, db());
ok('açık dönemler atandıktan sonra içeride sayımı Kadın/Erkek\'e geçti', !isset($oz['eksik_tip']['Karışık']) && array_sum($oz['eksik_tip']) === 6, j($oz['eksik_tip']));
ok('boş havuz → ret', empty(pdks_faz8j_karisik_ata($sidK, 1, 0, 'x', hex(), 1, db())['ok']));

echo "\n=== 8. Tam atama sonrası hakediş çalışır ===\n";
$r = pdks_faz8j_karisik_ata($sidD, 5, 3, 'hepsi', hex(), 1, db());
ok('dünkü mesai: kalan 8 Karışık atandı', !empty($r['ok']) && $r['kalan'] === 0 && tipSay($sidD, $karisik) === 0, j($r));
db()->exec("UPDATE daily_worker_work_periods SET overtime_approved_hours=0 WHERE session_id=$sidD");
foreach ([$kadin, $erkek] as $t) {
    $o = pdks_faz8b_oran_ekle($cavus['C'], $t, '1000', '500', 'hourly', '100', '2020-01-01', 'TRY', 1, db());
    ok('oran tanımı (' . ($t === $kadin ? 'Kadın' : 'Erkek') . ') kabul', !empty($o['ok']), j($o));
}
$h = pdks_faz8b_hakedis_hesapla($sidD, 1, db());
ok('hakediş hesaplandı (9 dönem × 1000 — 9 saatlik dönemler otomatik Tam)', !empty($h['ok']) && $h['total_amount'] === '9000.00' && count($h['lines']) === 9, j($h));
$f = pdks_faz8b_hakedis_finalize($sidD, 1, true, db());
ok('kesinleştirme çalışır', !empty($f['ok']), j($f));

echo "\n=== 9. Kaynak sözleşmeleri ===\n";
$gi = (string)file_get_contents($ROOT . '/gunluk_isci_giris_cikis.php');
ok('kiosk tip düğmeleri pdks_gunluk_giris_tip_listele()\'den', str_contains($gi, 'pdks_gunluk_giris_tip_listele($pdo)'));
ok('kiosk KARIŞIK düğme sınıfı', str_contains($gi, 'pdks-kiosk-typebtn-karisik'));
$dt = (string)file_get_contents($ROOT . '/gunluk_isci_puantaj_detay.php');
ok('detay: ekleme/toplu/düzelt listeleri desteklenen listeden (KARISIK yok)', str_contains($dt, '$duzeltmeTipler = $faz8jHazir && is_admin() ? pdks_gunluk_desteklenen_tip_listele($pdo)') && !str_contains($dt, 'giris_tip_listele'));
ok('detay: Otomatik Ata native dialog + CSRF + istek_id', str_contains($dt, '<dialog id="karisikAta" class="pm-dialog isk-card-modal isk-karisik"') && str_contains($dt, 'name="action" value="karisik_ata"') && str_contains($dt, 'name="istek_id"'));
$f8j = (string)file_get_contents($ROOT . '/config/pdks_faz8j.php');
$ataSrc = substr($f8j, strpos($f8j, 'function pdks_faz8j_karisik_ata('), 6000);
ok('ata pdks_faz8j_duzelt\'i ÇAĞIRMAZ, olay tablosuna yazmaz', !str_contains(substr($ataSrc, 0, strpos($ataSrc, "\n}\n")), 'pdks_faz8j_duzelt(') && !str_contains(substr($ataSrc, 0, strpos($ataSrc, "\n}\n")), 'daily_worker_card_events'));

echo "\nSONUÇ: $gecen geçti, $fail hata\n";
exit($fail === 0 ? 0 : 1);
