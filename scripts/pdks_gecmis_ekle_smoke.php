<?php
// =========================================================
// scripts/pdks_gecmis_ekle_smoke.php — v291 GEÇMİŞE DÖNÜK ÇALIŞMA EKLE
//
// pdks_faz8j_gecmis_ekle(): giriş yapmayı unutan personelin kaydını sonradan
// ekler (yalnız yönetici). Kayıt normal çalışma gibi sayılır (puantaj/özet/
// kart listesi), ham olaylar `manual` olarak yazılır, dönem `source=manual`.
// Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_gecmis_ekle_smoke.php   → çıkış kodu 0 = tüm testler geçti
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

db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
db()->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, status TEXT, needs_recalculation INTEGER DEFAULT 0, notes TEXT, updated_at TEXT, finalized_at TEXT, finalized_by_user_id INTEGER, total_amount TEXT)");

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-88s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }

$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$cavusA = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Çavuş A'], 1, db())['id'];
$cavusB = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Çavuş B'], 1, db())['id'];
$kartlar = [];
foreach (['K001' => '100000001', 'K002' => '100000002', 'K003' => '100000003', 'K004' => '100000004'] as $no => $uid) {
    pdks_gunluk_kart_olustur(['card_no' => $no, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
    $kartlar[$no] = (int)db()->query("SELECT id FROM worker_cards WHERE card_no='$no'")->fetchColumn();
}
$dun = date('Y-m-d', strtotime('-1 day'));
$bugun = date('Y-m-d');
$gelecek = date('Y-m-d', strtotime('+1 day'));
function ekle(array $o = []): array {
    global $cavusA, $kartlar, $kadin, $dun, $AKTIF_DEPO;
    return pdks_faz8j_gecmis_ekle($o + [
        'foreman_id' => $cavusA, 'work_date' => $dun, 'worker_card_id' => $kartlar['K001'], 'worker_type_id' => $kadin,
        'entry_date' => $dun, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '17:30',
        'reason' => 'Dün giriş yapmayı unuttu', 'note' => '', 'depo' => $AKTIF_DEPO,
    ], 1, db());
}

echo "\n=== 1. Dün için, mesaisi OLMAYAN çavuşa ekleme (kapalı mesai oluşur) ===\n";
$r = ekle();
ok('ekleme başarılı', !empty($r['ok']), j($r));
ok('yeni mesai oluşturuldu (yeni_mesai=true)', ($r['yeni_mesai'] ?? false) === true, j($r));
$sid = (int)($r['session_id'] ?? 0);
$s = db()->query("SELECT * FROM daily_work_sessions WHERE id = $sid")->fetch();
ok('mesai KAPALI, çavuş/tarih/depo doğru', ($s['status'] ?? '') === 'closed' && (int)$s['foreman_id'] === $cavusA && $s['work_date'] === $dun && $s['depo'] === 'Depo A', j($s));
ok('mesai adı/kodu donduruldu (snapshot)', ($s['foreman_name_snapshot'] ?? '') === 'Çavuş A' && ($s['foreman_code_snapshot'] ?? '') === 'C001');
$p = db()->query("SELECT * FROM daily_worker_work_periods WHERE id = " . (int)$r['period_id'])->fetch();
ok('dönem KAPALI, giriş 08:00 çıkış 17:30, kaynak=manual', ($p['status'] ?? '') === 'closed' && $p['entry_time'] === "$dun 08:00:00" && $p['exit_time'] === "$dun 17:30:00" && $p['source'] === 'manual', j($p));
ok('dönem iptal edilmemiş, tip Kadın', (int)$p['is_voided'] === 0 && $p['worker_type_name_snapshot'] === 'Kadın');
$ev = db()->query("SELECT event_type, source, server_event_time FROM daily_worker_card_events WHERE session_id = $sid ORDER BY id")->fetchAll();
ok('ham olaylar: GİRİŞ + ÇIKIŞ, ikisi de manual, saatleri doğru', count($ev) === 2 && $ev[0]['event_type'] === 'GIRIS' && $ev[1]['event_type'] === 'CIKIS' && $ev[0]['source'] === 'manual' && $ev[1]['server_event_time'] === "$dun 17:30:00", j($ev));
ok('dönem bu olaylara bağlı (entry/exit event id)', (int)$p['entry_event_id'] > 0 && (int)$p['exit_event_id'] > (int)$p['entry_event_id']);

echo "\n=== 2. Raporda görünüyor (mevcut okuma fonksiyonları) ===\n";
$oz = pdks_gunluk_oturum_ozet($sid, db());
ok('oturum özeti: Kadın giriş 1, çıkış 1, içeride 0, eksik 0', ($oz['giris']['Kadın'] ?? 0) === 1 && $oz['giris_toplam'] === 1 && $oz['cikis_toplam'] === 1 && $oz['icerde_toplam'] === 0 && $oz['eksik_toplam'] === 0, j($oz));
ok('özette ilk giriş 08:00 / son çıkış 17:30', substr((string)$oz['ilk_giris'], 11, 5) === '08:00' && substr((string)$oz['son_cikis'], 11, 5) === '17:30', j([$oz['ilk_giris'], $oz['son_cikis']]));
$kl = pdks_gunluk_oturum_kartlari($sid, db());
ok('kart listesinde 1 satır, kaynak=manual, durum "tam"', count($kl) === 1 && ($kl[0]['kaynak'] ?? '') === 'manual' && ($kl[0]['durum']['kod'] ?? '') === 'tam', j($kl));
$gun = pdks_gunluk_gun_ozeti($dun, 'Depo A', db());
ok('gün özeti (Günlük Puantaj): toplam giriş 1, tam çıkış 1', ($gun['giris_toplam'] ?? 0) === 1 && ($gun['tam_cikis'] ?? 0) === 1, j($gun));
$liste = pdks_gunluk_gun_listesi($dun, 'Depo A', null, null, db());
ok('gün listesinde çavuşun mesaisi var (kapalı)', count($liste) === 1 && (int)$liste[0]['session']['id'] === $sid, j(array_column(array_column($liste, 'session'), 'id')));
ok('eksik çıkış listesi BOŞ', pdks_gunluk_eksik_cikislar($dun, 'Depo A', null, db()) === []);
$dn = pdks_gunluk_puantaj_denetim_gecmisi([(int)$r['period_id']], db());
ok('işlem geçmişinde "geçmişe dönük çalışma eklendi" + sebep', count($dn) === 1 && str_contains($dn[0]['islem_etiket'], 'eklendi') && str_contains((string)$dn[0]['detay'], 'unuttu'), j($dn));
ok('audit: puantaj_ekle + mesai create', count(array_filter($GLOBALS['AUDIT'], fn($a) => $a[0] === 'create' && $a[1] === 'daily_work_sessions')) === 1);
$au = db()->query("SELECT action FROM audit_log WHERE module='daily_worker_work_periods'")->fetchAll(PDO::FETCH_COLUMN);
ok('audit_log satırı action=puantaj_ekle', in_array('puantaj_ekle', $au, true), j($au));

echo "\n=== 3. Aynı mesaiye ikinci kişi ekleme (mevcut mesai kullanılır) ===\n";
$r2 = ekle(['worker_card_id' => $kartlar['K002'], 'worker_type_id' => $erkek, 'entry_clock' => '08:15', 'exit_clock' => '16:45']);
ok('ikinci ekleme başarılı, AYNI mesai, yeni mesai DEĞİL', !empty($r2['ok']) && (int)$r2['session_id'] === $sid && $r2['yeni_mesai'] === false, j($r2));
$oz2 = pdks_gunluk_oturum_ozet($sid, db());
ok('özet: Kadın 1 + Erkek 1 = 2 giriş, 2 çıkış', $oz2['giris_toplam'] === 2 && ($oz2['giris']['Erkek'] ?? 0) === 1 && $oz2['cikis_toplam'] === 2, j($oz2));

echo "\n=== 4. Reddedilen durumlar ===\n";
$say = (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods")->fetchColumn();
$ret = function (string $ad, array $o, string $parca) use (&$say) {
    $r = ekle($o);
    $yeni = (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods")->fetchColumn();
    ok($ad, empty($r['ok']) && str_contains((string)($r['hata'] ?? ''), $parca) && $yeni === $say, j($r) . " satır=$yeni/$say");
};
global $kartlar;
$ret('aynı kartın çakışan dönemi → reddedilir', ['entry_clock' => '09:00', 'exit_clock' => '10:00'], 'çakışan');
$ret('sebep boş → reddedilir', ['worker_card_id' => $kartlar['K003'], 'reason' => ''], 'Alanları kontrol');
$ret('çıkış yok → reddedilir', ['worker_card_id' => $kartlar['K003'], 'exit_date' => '', 'exit_clock' => ''], 'zorunlu');
$ret('çıkış girişten önce → reddedilir', ['worker_card_id' => $kartlar['K003'], 'exit_clock' => '07:00'], 'kurallarına uymuyor');
$ret('giriş mesai gününden farklı gün → reddedilir', ['worker_card_id' => $kartlar['K003'], 'entry_date' => date('Y-m-d', strtotime('-2 day')), 'exit_date' => $dun], 'kurallarına uymuyor');
$ret('süre 24 saati aşıyor → reddedilir', ['worker_card_id' => $kartlar['K003'], 'exit_date' => $bugun, 'exit_clock' => '09:00'], 'kurallarına uymuyor');
$ret('gelecek mesai tarihi → reddedilir', ['worker_card_id' => $kartlar['K003'], 'work_date' => $gelecek, 'entry_date' => $gelecek, 'exit_date' => $gelecek], 'gelecekte');
$ret('geçersiz kart → reddedilir', ['worker_card_id' => 99999], 'bulunamadı');
$ret('geçersiz işçi tipi → reddedilir', ['worker_card_id' => $kartlar['K003'], 'worker_type_id' => 99999], 'tip');
$ret('olmayan çavuş (yeni mesai gerekir) → reddedilir', ['worker_card_id' => $kartlar['K003'], 'foreman_id' => 99999], 'Çavuş bulunamadı');
ok('reddedilen denemeler mesai OLUŞTURMADI (yalnız 1 mesai)', (int)db()->query("SELECT COUNT(*) FROM daily_work_sessions")->fetchColumn() === 1);
// ⚠ Bilinçli beklenti değişikliği (Toplu İşlem / bugün kuralı, onaylı): eskiden
// "bugün için mesai yok → reddedilir" idi. Artık bugünün mesaisi yoksa KİOSK
// yolu (pdks_gunluk_oturum_ac_veya_getir) ile AÇIK mesai açılır.
if (date('H:i') > '00:00') {
    $r = ekle(['worker_card_id' => $kartlar['K003'], 'foreman_id' => $cavusB, 'work_date' => $bugun, 'entry_date' => $bugun, 'entry_clock' => '00:00', 'exit_date' => $bugun, 'exit_clock' => date('H:i')]);
    $sb = !empty($r['ok']) ? db()->query("SELECT status FROM daily_work_sessions WHERE id = " . (int)$r['session_id'])->fetchColumn() : null;
    ok('bugün için mesai yok → kiosk yoluyla AÇIK mesai açılır ve kayıt eklenir', !empty($r['ok']) && $r['yeni_mesai'] === true && $sb === 'open', j($r));
}

echo "\n=== 5. Yetki, depo, kesinleşmiş hakediş ===\n";
$ADMIN = false;
$r = ekle(['worker_card_id' => $kartlar['K003']]);
ok('yönetici DEĞİL → reddedilir', empty($r['ok']) && str_contains((string)$r['hata'], 'yönetici'), j($r));
$ADMIN = true;
$r = ekle(['worker_card_id' => $kartlar['K003'], 'depo' => 'Depo B']);
ok('istemciden gelen depo aktif depoyla uyuşmuyor → reddedilir', empty($r['ok']), j($r));
$AKTIF_DEPO = '';
$r = ekle(['worker_card_id' => $kartlar['K003'], 'depo' => '']);
ok('aktif depo yok → reddedilir', empty($r['ok']), j($r));
$AKTIF_DEPO = 'Depo A';
db()->exec("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, status, needs_recalculation) VALUES ($sid, $cavusA, 'final', 0)");
$r = ekle(['worker_card_id' => $kartlar['K003']]);
ok('KESİNLEŞMİŞ hakediş → reddedilir (önce yeniden açılmalı)', empty($r['ok']) && str_contains((string)$r['hata'], 'kesinleşmiş'), j($r));
db()->exec("UPDATE foreman_daily_entitlements SET status = 'draft' WHERE session_id = $sid");
$r = ekle(['worker_card_id' => $kartlar['K003'], 'entry_clock' => '09:00', 'exit_clock' => '12:00']);
ok('TASLAK hakediş → eklenir ve "yeniden hesapla" işareti alır', !empty($r['ok']) && (int)db()->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id = $sid")->fetchColumn() === 1, j($r));

echo "\n=== 6. Boş kart listesi ===\n";
$bos = array_column(pdks_faz8j_bos_kartlar($dun, db()), 'card_no');
ok('o gün kullanılan kartlar (K001/K002/K003) listede YOK, K004 var', $bos === ['K004'], j($bos));
$bos2 = array_column(pdks_faz8j_bos_kartlar($gelecek, db()), 'card_no');
ok('başka günde dört kart da boş', count($bos2) === 4, j($bos2));
$ok = db()->prepare("UPDATE daily_worker_work_periods SET is_voided = 1 WHERE worker_card_id = ?");
$ok->execute([$kartlar['K001']]);
ok('iptal edilen dönemin kartı yeniden boş sayılır', in_array('K001', array_column(pdks_faz8j_bos_kartlar($dun, db()), 'card_no'), true));
$ozI = pdks_gunluk_oturum_ozet($sid, db());
ok('iptal edilen dönem özetten düşer (giriş 2: K002 + K003)', $ozI['giris_toplam'] === 2, j($ozI));

echo "\n=== 7. Kaynak kod kuralları (statik) ===\n";
$src = (string)file_get_contents($ROOT . '/config/pdks_faz8j.php');
$a = strpos($src, 'function pdks_faz8j_gecmis_ekle(');
$fn = substr($src, $a);
ok('kart okutma yazma fonksiyonlarını ÇAĞIRMAZ (ayrı, elle yol)', !preg_match('/faz8a_giris_kaydet|faz8a_cikis_kaydet|oturum_kaydet\(/', $fn));
ok('işlem tek transaction içinde (beginTransaction + commit + rollBack)', str_contains($fn, 'beginTransaction') && str_contains($fn, 'commit') && str_contains($fn, 'rollBack'));
ok('kart satırı kilitlenir (çift kayıt/çakışma yarışı)', str_contains($fn, 'pdks_gunluk_faz8a_kart_kilitle'));
ok('yönetici + aktif depo kapıları var', str_contains($fn, 'pdks_faz8j_yetki()') && str_contains($fn, 'pdks_faz8j_aktif_depo_kontrol'));
ok('kesinleşmiş hakediş kapısı var', str_contains($fn, "=== 'final'"));

echo "\n" . ($fail ? "$fail HATA, $gecen geçti\n" : "Tümü geçti ($gecen)\n");
exit($fail ? 1 : 0);
