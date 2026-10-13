<?php
// =========================================================
// scripts/pdks_ters_kart_smoke.php — v354 TERS KART TAKİP (sunucu tarafı)
//
// Senaryolar (görev tanımındaki A–J):
//   0 tablo yokken eski sistem aynen · A ilk kayıt (60 kart kalıcı havuz)
//   B sabah ters sayım (12 kalan → 48 giriş, UID bazında) · C sabit saat (08:00 ≠ işlem anı)
//   D akşam çıkış (Ortak Çıkış, gerçek saat) + mutabakat · E yeni gün (yeniden okutmadan,
//   dönmemiş kart kararı) · F paralel kullanım (normal kiosk / tanım / elle ekleme kapıları)
//   G aynı kart iki sistemde · H kayıp/bozuk · I eşzamanlılık/idempotens/geri alma
//   J statik sözleşmeler (tek yazma yolu, opsiyonel şema)
// Bellek içi SQLite — canlı DB'ye dokunmaz.
//   php scripts/pdks_ters_kart_smoke.php   → çıkış kodu 0 = tüm testler geçti
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
function audit_log_event(string $a, string $m, ?int $id, ?array $o = null, ?array $n = null): void { global $AUDIT; $AUDIT[] = [$a, $m, $id, $o, $n]; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_ters.php';

// MySQL DDL → SQLite (pdks_tanimli_giris_smoke.php ile aynı çevirici)
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
db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test')");
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
sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-100s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function say(string $sql, array $p = []): int { $st = db()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
function satir(string $sql, array $p = []): array { $st = db()->prepare($sql); $st->execute($p); return $st->fetch() ?: []; }
function istek(): string { return bin2hex(random_bytes(16)); }
function kartIdUid(string $uid): int { return say("SELECT id FROM worker_cards WHERE canonical_uid = ?", [pdks_uid_from_decimal($uid)]); }

$kadin = say("SELECT id FROM worker_types WHERE code='KADIN'");
$erkek = say("SELECT id FROM worker_types WHERE code='ERKEK'");
$cavus = [];
foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $h) {
    $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT), 'name' => 'Çavuş ' . $h], 1, db())['id'];
}
$uidA = fn(int $i): string => (string)(200000000 + $i);   // Çavuş A havuzu (1..60)
$uidB = fn(int $i): string => (string)(300000000 + $i);   // Çavuş B'nin normal kartları
$uidC = fn(int $i): string => (string)(400000000 + $i);   // Çavuş C havuzu
$bugun = date('Y-m-d');
$saat = date('H') . ':00';                                 // her zaman ≤ şimdi ve bugün
$girisZamani = "$bugun $saat:00";

echo "\n=== 0. Tablolar YOKKEN — eski sistem aynen çalışır ===\n";
ok('ters şeması yok → pdks_ters_sema_hazir false', pdks_ters_sema_hazir(db()) === false);
ok('Günlük İşçi / Faz 8A genel hazır-mı ETKİLENMEDİ', pdks_gunluk_sema_hazir(db()) && pdks_gunluk_faz8a_sema_hazir(db()));
ok('tablo yokken ters havuz engeli null (kiosk değişmez)', pdks_gunluk_ters_havuz_engeli(db(), 1, 1, 'Depo A') === null);
pdks_gunluk_kart_olustur(['card_no' => 'N001', 'ham_uid' => $uidB(99), 'kaynak' => 'usb_decimal'], 1, db());
$sB0 = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['B'], 1, db())['session']['id'];
$r = pdks_gunluk_faz8a_giris_kaydet($uidB(99), 'usb_decimal', $sB0, $kadin, 'auto', 1, db());
ok('tablo yokken normal kiosk girişi çalışır', !empty($r['ok']), j($r));
$r = pdks_gunluk_ortak_cikis_kaydet($uidB(99), 'usb_decimal', 1, db());
ok('… ortak çıkış çalışır', !empty($r['ok']), j($r));
pdks_gunluk_oturum_kapat($sB0, null, 1, db());
$r = pdks_ters_sayim_ac($cavus['A'], 1, db());
ok('tablo yokken ters işlemleri → sema_hazir_degil', ($r['kod'] ?? '') === 'sema_hazir_degil', j($r));

echo "\n=== Kurulum ===\n";
$mig = pdks_ters_migrate(db());
// Migrate MySQL DDL'ini SQLite'ta çalıştıramaz (ENGINE=...) → testte çevirici ile kur, sonra idempotens.
foreach (pdks_ters_tablolar() as $sql) sqlite_kur($sql);
ok('4 tablo kuruldu → pdks_ters_sema_hazir true', pdks_ters_sema_hazir(db()) === true);
$mig = pdks_ters_migrate(db());
ok('migrate idempotent (hepsi "var")', count($mig) === 4 && count(array_filter($mig, fn($x) => $x['durum'] === 'var')) === 4, j($mig));
ok('genel sema_hazir listelerine EKLENMEDİ', !array_intersect(array_keys(pdks_ters_tablolar()), array_keys(pdks_gunluk_tablolar())));

echo "\n=== A. İlk kayıt — 60 kart kalıcı havuz ===\n";
$r = pdks_ters_giris_saati_kaydet($cavus['A'], '8:00', date('Y-m-d', strtotime('-1 day')), '', 1, db());
ok('giriş saati geçmiş tarihli kaydedilemez (geriye dönük değil)', ($r['kod'] ?? '') === 'gecmis_tarih', j($r));
$r = pdks_ters_giris_saati_kaydet($cavus['A'], '25:00', $bugun, '', 1, db());
ok('geçersiz saat reddedilir', ($r['kod'] ?? '') === 'saat_gecersiz', j($r));
$r = pdks_ters_giris_saati_kaydet($cavus['A'], $saat, $bugun, 'ilk kurulum', 1, db());
ok('giriş saati çavuş+depo için kaydedildi (' . $saat . ')', !empty($r['ok']) && $r['giris_saati'] === $saat, j($r));
$ayar = pdks_ters_giris_saati(db(), $cavus['A'], 'DEPO A', $bugun);
ok('ayar TR-duyarsız depo ile çözülür', ($ayar['giris_saati'] ?? '') === $saat, j($ayar));
$hata = 0;
for ($i = 1; $i <= 60; $i++) {
    $r = pdks_ters_havuz_ekle($uidA($i), 'usb_decimal', $cavus['A'], $i <= 35 ? $kadin : $erkek, 1, db());
    if (empty($r['ok']) || $r['sonuc'] !== 'eklendi' || empty($r['yeni_kart'])) { $hata++; if ($hata === 1) echo '    ilk hata: ' . j($r) . "\n"; }
}
ok('60 yeni kart okutuldu → havuza eklendi (bilinmeyen UID havuza kart olarak girer)', $hata === 0);
$uyeler = pdks_ters_havuz_uyeleri(db(), $cavus['A'], 'Depo A');
$oz = pdks_ters_havuz_ozet($uyeler);
ok('havuz: 60 aktif (35 Kadın + 25 Erkek)', $oz['toplam'] === 60 && $oz['aktif'] === 60 && ($oz['tipler']['Kadın'] ?? 0) === 35 && ($oz['tipler']['Erkek'] ?? 0) === 25, j($oz));
ok('kartlar worker_cards\'ta, UID kanonik olarak saklandı', say("SELECT COUNT(*) FROM worker_cards WHERE canonical_uid = ?", [pdks_uid_from_decimal($uidA(7))]) === 1);
$r = pdks_ters_havuz_ekle($uidA(5), 'usb_decimal', $cavus['A'], $kadin, 1, db());
ok('aynı kart ikinci kez okutuldu → "zaten" (sayılmaz)', !empty($r['ok']) && $r['sonuc'] === 'zaten' && $r['ozet']['toplam'] === 60, j($r));
$r = pdks_ters_havuz_ekle($uidA(5), 'usb_decimal', $cavus['B'], $kadin, 1, db());
ok('başka çavuşun havuzundaki kart B\'ye eklenemez', ($r['kod'] ?? '') === 'baska_havuzda', j($r));
$r = pdks_ters_havuz_ekle('abc', 'usb_decimal', $cavus['A'], $kadin, 1, db());
ok('geçersiz UID reddedilir', ($r['kod'] ?? '') === 'gecersiz_uid', j($r));
// tip değişikliği: geçmiş korunur
$r = pdks_ters_havuz_ekle($uidA(60), 'usb_decimal', $cavus['A'], $kadin, 1, db());
ok('aynı çavuş farklı tip → tip güncellendi', !empty($r['ok']) && $r['sonuc'] === 'tip_guncellendi', j($r));
ok('… eski üyelik SİLİNMEDİ, kapatıldı (geçmiş)', say("SELECT COUNT(*) FROM ters_havuz_kart WHERE worker_card_id = ? AND aktif_kart_id IS NULL AND valid_to IS NOT NULL", [kartIdUid($uidA(60))]) === 1);
pdks_ters_havuz_ekle($uidA(60), 'usb_decimal', $cavus['A'], $erkek, 1, db());
// Kart Havuzu'nda başka çavuşa tanımlı kart havuza giremez
pdks_gunluk_kart_olustur(['card_no' => 'T001', 'ham_uid' => $uidB(50), 'kaynak' => 'usb_decimal'], 1, db());
$r = pdks_gunluk_kart_tanim_kaydet(kartIdUid($uidB(50)), $cavus['B'], $kadin, 'Depo A', 1, db());
ok('(hazırlık) T001 Çavuş B\'ye tanımlandı', !empty($r['ok']), j($r));
$r = pdks_ters_havuz_ekle($uidB(50), 'usb_decimal', $cavus['A'], $kadin, 1, db());
ok('başka çavuşa TANIMLI kart ters havuza eklenemez', ($r['kod'] ?? '') === 'kart_uygun_degil' && str_contains($r['hata'], 'Tanımı Kaldır'), j($r));

echo "\n=== B. Sabah ters sayım — 12 kalan → 48 dağıtılan (UID bazında) ===\n";
$r = pdks_ters_sayim_ac($cavus['A'], 1, db());
ok('sayım açıldı (taslak, varsayılan saat ayardan)', !empty($r['ok']) && $r['yeni'] && $r['sayim']['durum'] === 'taslak' && $r['sayim']['varsayilan_giris_saati'] === $saat, j($r));
$sayimId = (int)$r['sayim']['id'];
$r2 = pdks_ters_sayim_ac($cavus['A'], 1, db());
ok('ikinci operatör aynı sayımı alır (tek aktif sayım)', !empty($r2['ok']) && !$r2['yeni'] && (int)$r2['sayim']['id'] === $sayimId);
$kalanlar = [3, 7, 11, 15, 19, 23, 27, 31, 40, 44, 52, 58];   // 9 Kadın? (≤35: 3,7,11,15,19,23,27,31 = 8 Kadın) + 4 Erkek
foreach ($kalanlar as $i) $r = pdks_ters_sayim_okut($sayimId, $uidA($i), 'usb_decimal', 1, db());
ok('12 kalan okutuldu → sayaç kalan 12, dağıtılacak 48', $r['ok'] && $r['sayac']['kalan'] === 12 && $r['sayac']['dagitilacak'] === 48, j($r));
$r = pdks_ters_sayim_okut($sayimId, $uidA(3), 'usb_decimal', 1, db());
ok('aynı kalan kart iki kez → mükerrer, kalan 12 kalır', $r['ok'] && $r['mukerrer'] && $r['sayac']['kalan'] === 12, j($r));
pdks_gunluk_kart_olustur(['card_no' => 'BX01', 'ham_uid' => $uidB(1), 'kaynak' => 'usb_decimal'], 1, db());
$r = pdks_ters_sayim_okut($sayimId, $uidB(1), 'usb_decimal', 1, db());
ok('havuz dışı kart kalan sayılmaz (havuz_disi)', ($r['kod'] ?? '') === 'havuz_disi', j($r));
$r = pdks_ters_sayim_okut($sayimId, '999999991', 'usb_decimal', 1, db());
ok('tanımsız UID kalan sayılmaz (kart_tanimsiz)', ($r['kod'] ?? '') === 'kart_tanimsiz', j($r));
// yanlış okutma düzeltme: 13 yanlışlıkla okutuldu, listeden çıkarıldı
pdks_ters_sayim_okut($sayimId, $uidA(13), 'usb_decimal', 1, db());
$r = pdks_ters_sayim_karar($sayimId, kartIdUid($uidA(13)), 'kalan_sil', '', 1, db());
ok('yanlış okutulan kart listeden çıkarıldı → kalan yine 12', $r['ok'] && $r['sayac']['kalan'] === 12, j($r));
$onizle = pdks_ters_sayim_onizle($sayimId, $saat, '', '', db());
$d = $onizle['degerlendirme'];
ok('önizleme: 48 dağıtılacak, 12 kalan, hata yok', $d['ok'] && $d['ozet']['dagitilacak'] === 48 && $d['ozet']['kalan'] === 12 && $d['ozet']['calisan'] === 48, j($d['ozet']) . j($d['genel']));
ok('önizleme YAN ETKİSİZ: dönem/mesai yazılmadı', say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE source = 'ters'") === 0 && !pdks_ters_bugunku_mesai(db(), $cavus['A'], $bugun, 'Depo A'));
$dagitilanBeklenen = [];
for ($i = 1; $i <= 60; $i++) if (!in_array($i, $kalanlar, true)) $dagitilanBeklenen[] = kartIdUid($uidA($i));
$liste = array_map(fn($x) => $x['kart_id'], array_filter($d['satirlar'], fn($x) => $x['durum'] === 'dagitilacak'));
sort($liste); sort($dagitilanBeklenen);
ok('dağıtılan kartlar UID bazında doğru 48 kart', $liste === $dagitilanBeklenen);
$r = pdks_ters_sayim_onizle($sayimId, $saat, '', '47', db());
ok('beyan 47 ≠ 48 → UYARI (engel değil)', $r['degerlendirme']['ok'] && count($r['degerlendirme']['uyarilar']) === 1, j($r['degerlendirme']['uyarilar']));
$ileri = (int)date('H') + 3;
if ($ileri <= 23) {
    $r = pdks_ters_sayim_onizle($sayimId, sprintf('%02d:00', $ileri), 'test', '', db());
    ok('sabit saat 2 saatten ileride → engel', !$r['degerlendirme']['ok'] && str_contains(implode(' ', $r['degerlendirme']['genel']), '2 saat'), j($r['degerlendirme']['genel']));
} else { ok('(gece saati — ileri saat testi atlandı)', true); }
$r = pdks_ters_sayim_onayla($sayimId, '09:30', '', '', istek(), 1, db());
ok('varsayılandan farklı saat gerekçesiz → red', ($r['kod'] ?? '') === 'onaylanamaz' && str_contains($r['hata'], 'gerekçe'), j($r));
$r = pdks_ters_sayim_onayla($sayimId, $saat, '', '', 'kotu', 1, db());
ok('istek anahtarı geçersiz → red', ($r['kod'] ?? '') === 'istek_gecersiz', j($r));
$ist = istek();
$once = time();
$r = pdks_ters_sayim_onayla($sayimId, $saat, '', '48', $ist, 1, db());
ok('ONAY: 48 giriş, mesai açıldı', !empty($r['ok']) && $r['ozet']['yeni_giris'] === 48 && $r['ozet']['dagitilan'] === 48 && $r['yeni_mesai'], j($r));
$sidA = (int)($r['session_id'] ?? 0);
ok('48 açık dönem source=ters', say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = ? AND source = 'ters' AND status = 'open'", [$sidA]) === 48);
ok('kalan 12 kart için dönem YOK', say("SELECT COUNT(*) FROM daily_worker_work_periods p WHERE p.worker_card_id IN (" . implode(',', array_map(fn($i) => kartIdUid($uidA($i)), $kalanlar)) . ")") === 0);
ok('sayım anlık görüntüsü: 12 kalan + 48 dağıtılan = 60 satır', say("SELECT COUNT(*) FROM ters_sayim_kart WHERE sayim_id = ?", [$sayimId]) === 60
    && say("SELECT COUNT(*) FROM ters_sayim_kart WHERE sayim_id = ? AND rol = 'dagitilan' AND period_id IS NOT NULL", [$sayimId]) === 48);
$oturumOzet = pdks_gunluk_faz8a_oturum_ozet($sidA, db());
ok('mevcut oturum özeti ters girişleri sayar (giriş 48, içeride 48)', $oturumOzet['giris_toplam'] === 48 && $oturumOzet['icerde_toplam'] === 48, j($oturumOzet['giris']));

echo "\n=== C. Sabit saat — giriş = tanımlı saat, işlem anı ayrı ===\n";
$p = satir("SELECT * FROM daily_worker_work_periods WHERE session_id = ? AND source = 'ters' ORDER BY id LIMIT 1", [$sidA]);
ok('dönem entry_time = sabit saat (' . $girisZamani . ')', $p['entry_time'] === $girisZamani, j($p));
$e = satir("SELECT * FROM daily_worker_card_events WHERE id = ?", [(int)$p['entry_event_id']]);
ok('GİRİŞ olayı source=ters (fiziksel okutma DEĞİL)', $e['source'] === 'ters' && $e['event_type'] === 'GIRIS', j($e));
ok('olay server_event_time = GERÇEK kayıt anı (sabit saat DEĞİL)', abs(strtotime($e['server_event_time']) - $once) <= 5, $e['server_event_time']);
$s = pdks_ters_sayim_getir(db(), $sayimId);
ok('sayım: giriş saati, giriş zamanı, onay zamanı ayrı saklandı', $s['giris_saati'] === $saat && $s['giris_zamani'] === $girisZamani && abs(strtotime((string)$s['onay_zamani']) - $once) <= 5 && (int)$s['beyan_adet'] === 48, j($s));
$aud = satir("SELECT * FROM audit_log WHERE action = 'ters_sayim_onay' AND record_id = ?", [$sayimId]);
ok('özet audit ters_sayim_onay (istek_id dahil)', $aud && str_contains((string)$aud['new_values'], $ist));
ok('satır audit ters_giris × 48', say("SELECT COUNT(*) FROM audit_log WHERE action = 'ters_giris'") === 48);
$r = pdks_ters_sayim_onayla($sayimId, $saat, '', '48', $ist, 1, db());
ok('aynı istekle tekrar onay → "tekrar", yeni dönem yok', !empty($r['ok']) && !empty($r['tekrar']) && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE source='ters'") === 48, j($r));
$r = pdks_ters_sayim_onayla($sayimId, $saat, '', '', istek(), 1, db());
ok('farklı istekle ikinci onay → zaten_onayli', ($r['kod'] ?? '') === 'zaten_onayli', j($r));
$r = pdks_ters_sayim_okut($sayimId, $uidA(1), 'usb_decimal', 1, db());
ok('onaylı sayıma kalan okutulamaz', ($r['kod'] ?? '') === 'sayim_kapali', j($r));
$sinif = pdks_faz8b_donem_siniflandir($p, []);
ok('hakediş sınıflandırıcısı ters dönemi sorunsuz işler (çıkışsız → bekliyor)', is_array($sinif), j($sinif));

echo "\n=== D. Akşam çıkış (Ortak Çıkış) + mutabakat ===\n";
$dagitilanlar = array_values(array_diff(range(1, 60), $kalanlar));
$cikanlar = array_slice($dagitilanlar, 0, 39);
$hataD = 0; $son = null;
foreach ($cikanlar as $i) { $son = pdks_ters_cikis_kaydet($uidA($i), 'usb_decimal', 1, db()); if (empty($son['ok'])) $hataD++; }
ok('39 kart çıkış okuttu', $hataD === 0, j($son));
ok('yanıt: çavuş adı + içeride 9 + ters bayrağı', ($son['cavus']['ad'] ?? '') === 'Çavuş A' && ($son['cavus']['icerde'] ?? -1) === 9 && !empty($son['ters']), j($son['cavus'] ?? null));
$p = satir("SELECT * FROM daily_worker_work_periods WHERE worker_card_id = ?", [kartIdUid($uidA($cikanlar[0]))]);
ok('çıkış gerçek sunucu saatiyle, giriş sabit kaldı', $p['status'] === 'closed' && abs(strtotime($p['exit_time']) - time()) <= 5 && $p['entry_time'] === $girisZamani, j($p));
$r = pdks_ters_cikis_kaydet($uidA($cikanlar[0]), 'usb_decimal', 1, db());
ok('mükerrer çıkış okutması ikinci kayıt oluşturmaz', empty($r['ok']) && say("SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id = ? AND event_type = 'CIKIS'", [kartIdUid($uidA($cikanlar[0]))]) === 1, j($r));
$r = pdks_ters_cikis_kaydet($uidA($kalanlar[0]), 'usb_decimal', 1, db());
ok('o gün dağıtılmamış (kalan) kart çıkışta → reddedilir', empty($r['ok']) && ($r['kod'] ?? '') === 'acik_donem_yok', j($r));
$mt = pdks_ters_mutabakat(db(), $bugun, 'Depo A');
$ra = array_values(array_filter($mt['satirlar'], fn($x) => $x['foreman_id'] === $cavus['A']))[0] ?? [];
ok('mutabakat: dağıtılan 48 · giriş 48 · çıkış 39 · içeride 9 · kalan 12', ($ra['dagitilan'] ?? 0) === 48 && $ra['giris'] === 48 && $ra['cikis'] === 39 && $ra['iceride'] === 9 && $ra['kalan'] === 12, j($ra));
ok('mutabakat: dönmeyen 9 kart listelenir', count($ra['donmeyen_kartlar'] ?? []) === 9);
$r = pdks_gunluk_oturum_kapat($sidA, null, 1, db());
ok('Mesaiyi Kapat: eksik çıkış varken gerekçesiz kapanmaz (mevcut kural)', ($r['kod'] ?? '') === 'eksik_cikis_var', j($r));
$r = pdks_gunluk_oturum_kapat($sidA, '9 kart dönmedi', 1, db());
ok('Mesaiyi Kapat gerekçeyle kapandı', !empty($r['ok']), j($r));
$mt = pdks_ters_mutabakat(db(), $bugun, 'Depo A');
$ra = array_values(array_filter($mt['satirlar'], fn($x) => $x['foreman_id'] === $cavus['A']))[0] ?? [];
ok('mesai kapanınca: içeride 0, eksik çıkış 9 (çıkış UYDURULMAZ)', $ra['iceride'] === 0 && $ra['eksik_cikis'] === 9 && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = ? AND status = 'open'", [$sidA]) === 9, j($ra));

echo "\n=== E. Yeni gün — havuz yeniden okutulmaz, dünkü kayıtlarla karışmaz ===\n";
// Bugünkü her şeyi DÜNE kaydır (gün geçişi simülasyonu)
foreach ([
    "UPDATE daily_work_sessions SET work_date = date(work_date, '-1 day'), opened_at = datetime(opened_at, '-1 day'), closed_at = datetime(closed_at, '-1 day')",
    "UPDATE daily_worker_work_periods SET work_date_snapshot = date(work_date_snapshot, '-1 day'), entry_time = datetime(entry_time, '-1 day'), exit_time = datetime(exit_time, '-1 day')",
    "UPDATE daily_worker_card_events SET work_date_snapshot = date(work_date_snapshot, '-1 day'), server_event_time = datetime(server_event_time, '-1 day')",
    "UPDATE ters_sayim SET work_date = date(work_date, '-1 day'), giris_zamani = datetime(giris_zamani, '-1 day'), onay_zamani = datetime(onay_zamani, '-1 day')",
] as $q) db()->exec($q);
$dun = date('Y-m-d', strtotime('-1 day'));
db()->prepare('UPDATE ters_sayim SET aktif_anahtar = ? WHERE id = ?')->execute([pdks_ters_anahtar($cavus['A'], $dun, 'Depo A'), $sayimId]);
$donemDun = say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE source = 'ters'");
$r = pdks_ters_sayim_ac($cavus['A'], 1, db());
ok('yeni gün: yeni sayım açıldı (havuz 60 kart, yeniden okutma YOK)', !empty($r['ok']) && $r['yeni'] && (int)$r['sayim']['id'] !== $sayimId, j($r));
$sayim2 = (int)$r['sayim']['id'];
ok('dünkü sayım dokunulmadı (onaylı, 48)', ($x = pdks_ters_sayim_getir(db(), $sayimId)) && $x['durum'] === 'onayli' && (int)$x['dagitilan_adet'] === 48);
$donmeyenler = array_slice($dagitilanlar, 39);   // 9 kart dün dönmedi
ok('(kontrol) 9 dönmemiş kart', count($donmeyenler) === 9);
$d = pdks_ters_sayim_onizle($sayim2, $saat, '', '', db())['degerlendirme'];
$dm = array_filter($d['hatalar'], fn($x) => !empty($x['donmemis']));
ok('dönmemiş 9 kart kararsızken onay ENGELLİ', !$d['ok'] && count($dm) === 9, j($d['ozet']));
$r = pdks_ters_sayim_onayla($sayim2, $saat, '', '', istek(), 1, db());
ok('… onay denemesi reddedildi, hiçbir şey yazılmadı', ($r['kod'] ?? '') === 'onaylanamaz' && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE source='ters'") === $donemDun, j($r['hata'] ?? ''));
[$k1, $k2, $k3, $k4, $k5, $k6, $k7, $k8, $k9] = $donmeyenler;
foreach ([$k1, $k2, $k3] as $i) $r = pdks_ters_sayim_karar($sayim2, kartIdUid($uidA($i)), 'kayip', 'Çavuş bulamadı', 1, db());
ok('3 dönmemiş kart KAYIP işaretlendi', !empty($r['ok']) && say("SELECT COUNT(*) FROM worker_cards WHERE status = 'lost'") === 3, j($r));
foreach ([$k4, $k5, $k6] as $i) $r = pdks_ters_sayim_karar($sayim2, kartIdUid($uidA($i)), 'dagitildi', '', 1, db());
ok('3 dönmemiş kart "bugün dağıtıldı" teyidi', !empty($r['ok']) && $r['sayac']['teyit'] === 3, j($r));
foreach ([$k7, $k8, $k9] as $i) pdks_ters_sayim_okut($sayim2, $uidA($i), 'usb_decimal', 1, db());
pdks_ters_sayim_okut($sayim2, $uidA($k6), 'usb_decimal', 1, db());   // teyitli kart aslında elde → kalan
$kalan2 = [1, 2, 4, 5, 6, 8, 9];
foreach ($kalan2 as $i) pdks_ters_sayim_okut($sayim2, $uidA($i), 'usb_decimal', 1, db());
$d = pdks_ters_sayim_onizle($sayim2, $saat, '', '', db())['degerlendirme'];
// aktif 57 (3 kayıp) · kalan 7 + 3 (dönmemiş ama elde) + 1 (teyit→kalan) = 11 · dağıtılan 46
ok('yeni gün önizleme: aktif 57 · kalan 11 · hariç 3 · dağıtılan 46', $d['ok'] && $d['ozet']['havuz_aktif'] === 57 && $d['ozet']['kalan'] === 11 && $d['ozet']['haric'] === 3 && $d['ozet']['dagitilacak'] === 46, j($d['ozet']) . j($d['genel']) . j(array_column($d['hatalar'], 'sebep')));
$r = pdks_ters_sayim_onayla($sayim2, $saat, '', '', istek(), 1, db());
ok('yeni gün ONAY: 46 giriş, yeni mesai', !empty($r['ok']) && $r['ozet']['yeni_giris'] === 46, j($r));
$sidA2 = (int)($r['session_id'] ?? 0);
ok('yeni mesai dünkünden farklı, dünkü dönemler değişmedi', $sidA2 !== $sidA && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = ?", [$sidA]) === 48);
ok('teyitli 2 kart (k4,k5) dağıtıldı ve teyitli işaretli', say("SELECT COUNT(*) FROM ters_sayim_kart WHERE sayim_id = ? AND rol = 'dagitilan' AND teyitli = 1", [$sayim2]) === 2);
ok('kayıp 3 kart hariç (sayıma girmedi)', say("SELECT COUNT(*) FROM ters_sayim_kart WHERE sayim_id = ? AND rol = 'haric'", [$sayim2]) === 3);
$r = pdks_ters_cikis_kaydet($uidA($k4), 'usb_decimal', 1, db());
ok('dünden dönmemiş, bugün teyitle dağıtılan kart BUGÜNKÜ dönemden çıkar', !empty($r['ok']) && (int)$r['cavus']['session_id'] === $sidA2, j($r));

echo "\n=== F. Paralel kullanım — normal kiosk / tanım / elle ekleme kapıları ===\n";
$sB = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['B'], 1, db())['session']['id'];
pdks_gunluk_kart_olustur(['card_no' => 'BX02', 'ham_uid' => $uidB(2), 'kaynak' => 'usb_decimal'], 1, db());
$r = pdks_gunluk_faz8a_giris_kaydet($uidB(2), 'usb_decimal', $sB, $kadin, 'auto', 1, db());
ok('Çavuş B normal girişi (kendi kartı) çalışır, source=scan', !empty($r['ok']) && satir("SELECT source FROM daily_worker_work_periods WHERE id = ?", [$r['period_id']])['source'] === 'scan', j($r));
$r = pdks_gunluk_faz8a_giris_kaydet($uidA(1), 'usb_decimal', $sB, $kadin, 'auto', 1, db());
ok('A havuzundaki (kalan) kart B\'nin normal girişine GİREMEZ', ($r['kod'] ?? '') === 'kart_ters_havuzda', j($r));
$r = pdks_gunluk_kart_tanim_kaydet(kartIdUid($uidA(1)), $cavus['B'], $kadin, 'Depo A', 1, db());
ok('A havuzundaki karta B için tanım yapılamaz', ($r['kod'] ?? '') === 'kart_ters_havuzda', j($r));
$b = pdks_gunluk_kart_tanim_satir_bilgi(db(), $uidA(1), 'usb_decimal');
$c = pdks_gunluk_kart_tanim_satir_sinifla($b, $cavus['B'], $kadin, 'Depo A');
ok('Seri Kart Tanımla sınıflaması: başka çavuşun ters havuzu → ENGEL', $c['sinif'] === 'baska_cavus' && ($c['kod'] ?? '') === 'kart_ters_havuzda', j($c));
$c = pdks_gunluk_kart_tanim_satir_sinifla($b, $cavus['A'], $kadin, 'Depo A');
ok('… aynı çavuş için tanım serbest (tanimlanacak)', $c['sinif'] === 'tanimlanacak', j($c));
ok('elle ekleme kapısı (Faz 8J) başka çavuşa REDDEDER', pdks_faz8j_tanim_kart_engeli(db(), kartIdUid($uidA(1)), $cavus['B'], 'Depo A') !== null);
ok('elle ekleme kapısı aynı çavuşa izin verir', pdks_faz8j_tanim_kart_engeli(db(), kartIdUid($uidA(1)), $cavus['A'], 'Depo A') === null);
$r = pdks_gunluk_faz8a_giris_kaydet($uidA(1), 'usb_decimal', $sidA2, $kadin, 'auto', 1, db());
ok('kalan karttan geç gelen işçi aynı çavuşa NORMAL okutmayla girer (karışık gün)', !empty($r['ok']), j($r));
$r = pdks_gunluk_ortak_cikis_kaydet($uidB(2), 'usb_decimal', 1, db());
ok('B\'nin normal kartı ortak çıkışla çıkar', !empty($r['ok']), j($r));
$AKTIF_DEPO = 'Depo B';
$r = pdks_ters_cikis_kaydet($uidA($k5), 'usb_decimal', 1, db());
ok('yanlış depodan çıkış → reddedilir (depo izolasyonu)', empty($r['ok']) && ($r['kod'] ?? '') === 'yanlis_depo', j($r));
$r = pdks_ters_sayim_okut($sayim2, $uidA(3), 'usb_decimal', 1, db());
ok('başka depodan sayım işlemi → reddedilir', ($r['kod'] ?? '') !== '' && empty($r['ok']), j($r));
$AKTIF_DEPO = 'Depo A';

echo "\n=== G. Aynı kart iki sistemde — çift giriş/çıkış yok ===\n";
$r = pdks_gunluk_faz8a_giris_kaydet($uidA($k5), 'usb_decimal', $sidA2, $kadin, 'auto', 1, db());
ok('ters girişli (içeride) karta normal giriş → mükerrer', ($r['kod'] ?? '') === 'mukerrer_giris', j($r));
// Çavuş C: normal okutmayla ÖNCE içeri giren havuz kartı → onayda zaten_iceride (ikinci dönem yok)
for ($i = 1; $i <= 10; $i++) pdks_ters_havuz_ekle($uidC($i), 'usb_decimal', $cavus['C'], $erkek, 1, db());
$sC = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['C'], 1, db())['session']['id'];
$r = pdks_gunluk_faz8a_giris_kaydet($uidC(1), 'usb_decimal', $sC, $erkek, 'auto', 1, db());
ok('C havuz kartı C\'nin normal girişine girebilir', !empty($r['ok']), j($r));
$sc = (int)pdks_ters_sayim_ac($cavus['C'], 1, db())['sayim']['id'];
foreach ([9, 10] as $i) pdks_ters_sayim_okut($sc, $uidC($i), 'usb_decimal', 1, db());
$d = pdks_ters_sayim_onizle($sc, $saat, 'ayar yok', '', db())['degerlendirme'];
ok('C önizleme: zaten içeride 1 · dağıtılacak 7 · çalışan 8', $d['ok'] && $d['ozet']['zaten_iceride'] === 1 && $d['ozet']['dagitilacak'] === 7 && $d['ozet']['calisan'] === 8, j($d['ozet']) . j($d['genel']));
$r = pdks_ters_sayim_onayla($sc, $saat, 'ayar yok', '', istek(), 1, db());
ok('C onay: 7 yeni giriş, zaten içerideki kart için ikinci dönem YOK', !empty($r['ok']) && $r['ozet']['yeni_giris'] === 7
    && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE worker_card_id = ? AND is_voided = 0", [kartIdUid($uidC(1))]) === 1, j($r));

echo "\n=== H. Kayıp / bozuk kart ===\n";
$r = pdks_ters_kart_durum(kartIdUid($uidC(2)), $cavus['C'], 'bozuk', 'kırık', 1, db());
ok('C kartı BOZUK işaretlendi (status disabled + etiket)', !empty($r['ok']) && satir("SELECT status FROM worker_cards WHERE id = ?", [kartIdUid($uidC(2))])['status'] === 'disabled', j($r));
$oz = pdks_ters_havuz_ozet(pdks_ters_havuz_uyeleri(db(), $cavus['C'], 'Depo A'));
ok('havuz özeti: bozuk 1, aktif 9, toplam 10', $oz['bozuk'] === 1 && $oz['aktif'] === 9 && $oz['toplam'] === 10, j($oz));
$r = pdks_ters_cikis_kaydet($uidC(2), 'usb_decimal', 1, db());
ok('bozuk işaretli ama içerideki kart yine ÇIKIŞ yapabilir', !empty($r['ok']), j($r));
$sD = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['C'], 1, db())['session']['id'];
$r = pdks_gunluk_faz8a_giris_kaydet($uidC(2), 'usb_decimal', $sD, $erkek, 'auto', 1, db());
ok('bozuk kart kioskta yeni girişe kapalı', ($r['kod'] ?? '') === 'kart_devre_disi', j($r));
$r = pdks_ters_kart_durum(kartIdUid($uidC(2)), $cavus['C'], 'kullanima_al', '', 1, db());
ok('kullanıma alındı → aktif 10', !empty($r['ok']) && pdks_ters_havuz_ozet(pdks_ters_havuz_uyeleri(db(), $cavus['C'], 'Depo A'))['aktif'] === 10, j($r));
$r = pdks_ters_kart_durum(kartIdUid($uidA(1)), $cavus['C'], 'kayip', '', 1, db());
ok('başka çavuşun havuz kartının durumu bu çavuştan değiştirilemez', ($r['kod'] ?? '') === 'uye_degil', j($r));
$r = pdks_gunluk_kart_tanimsiz_sil([kartIdUid($uidC(3))], 'test', istek(), 1, db());
ok('ters havuzdaki kart Kart Havuzu\'ndan silinemez/arşivlenemez', empty($r['ok']) && str_contains(j($r['hatalar'] ?? []), 'ters kart havuzunda'), j($r));

echo "\n=== I. Eşzamanlılık / geri alma ===\n";
$r = pdks_ters_sayim_geri_al($sc, '', 1, db());
ok('geri alma gerekçesiz → red', ($r['kod'] ?? '') === 'gerekce', j($r));
$r = pdks_ters_sayim_geri_al($sc, 'yanlış sayım', 1, db());
ok('C sayımı: çıkış okutulmuş kart (bozuk testi) varken geri alınamaz', ($r['kod'] ?? '') === 'geri_alinamaz' && str_contains($r['hata'], 'Çıkış'), j($r));
// D çavuşu: temiz geri alma senaryosu
$uidD = fn(int $i): string => (string)(500000000 + $i);
for ($i = 1; $i <= 6; $i++) pdks_ters_havuz_ekle($uidD($i), 'usb_decimal', $cavus['D'], $kadin, 1, db());
$sd = (int)pdks_ters_sayim_ac($cavus['D'], 1, db())['sayim']['id'];
pdks_ters_sayim_okut($sd, $uidD(6), 'usb_decimal', 1, db());
$r = pdks_ters_sayim_onayla($sd, $saat, '', '', istek(), 1, db());
ok('ayarsız çavuşta elle saat GEREKÇESİZ → red (denetim bulgusu)', ($r['kod'] ?? '') === 'onaylanamaz' && str_contains($r['hata'], 'gerekçe'), j($r));
$r = pdks_ters_sayim_onayla($sd, $saat, 'ayar yok', '', istek(), 1, db());
ok('D onay (ayarsız çavuş: saat elle) → 5 giriş', !empty($r['ok']) && $r['ozet']['yeni_giris'] === 5, j($r));
$sidD = (int)$r['session_id'];
$r = pdks_ters_sayim_geri_al($sd, 'kalan kart eksik okutuldu', 1, db());
ok('D geri alındı → 5 dönem İPTAL (silinmedi), yeni taslak açıldı', !empty($r['ok']) && $r['iptal_edilen'] === 5 && $r['yeni_sayim_id'] > 0
    && say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = ? AND is_voided = 1", [$sidD]) === 5, j($r));
$yeniD = (int)$r['yeni_sayim_id'];
ok('yeni taslak kalan okutmayı devraldı (1 kalan)', pdks_ters_sayim_sayac(db(), pdks_ters_sayim_getir(db(), $yeniD))['kalan'] === 1);
ok('eski sayım geri_alindi, anahtar boşaldı', ($x = pdks_ters_sayim_getir(db(), $sd)) && $x['durum'] === 'geri_alindi' && $x['aktif_anahtar'] === null);
pdks_ters_sayim_okut($yeniD, $uidD(5), 'usb_decimal', 1, db());
$r = pdks_ters_sayim_onayla($yeniD, $saat, 'ayar yok', '', istek(), 1, db());
ok('düzeltilmiş sayım onaylandı → 4 giriş (iptal edilenler kart kilitlemez)', !empty($r['ok']) && $r['ozet']['yeni_giris'] === 4, j($r));
ok('mesai özeti yalnız etkin dönemleri sayar (4)', pdks_gunluk_faz8a_oturum_ozet($sidD, db())['giris_toplam'] === 4);
// aynı anahtar ikinci aktif sayım: UNIQUE
$hataUq = false;
try { db()->prepare("INSERT INTO ters_sayim (foreman_id, depo, work_date, durum, aktif_anahtar) VALUES (?,?,?,?,?)")->execute([$cavus['D'], 'Depo A', $bugun, 'taslak', pdks_ters_anahtar($cavus['D'], $bugun, 'Depo A')]); }
catch (PDOException $e) { $hataUq = true; }
ok('çavuş+gün+depo başına ikinci aktif sayım UNIQUE ile engelli', $hataUq);
// kesin hakediş → onay reddi
for ($i = 1; $i <= 3; $i++) pdks_ters_havuz_ekle((string)(600000000 + $i), 'usb_decimal', $cavus['B'], $erkek, 1, db());
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status, calculated_at) VALUES (?,?,?,?, 'final', ?)")->execute([$sB, $cavus['B'], $bugun, 'Depo A', date('Y-m-d H:i:s')]);
$sb = (int)pdks_ters_sayim_ac($cavus['B'], 1, db())['sayim']['id'];
$r = pdks_ters_sayim_onayla($sb, $saat, 'x', '', istek(), 1, db());
ok('kesinleşmiş hakedişli mesaiye ters giriş yazılamaz', ($r['kod'] ?? '') === 'onaylanamaz' && str_contains($r['hata'], 'kesinleşmiş'), j($r));
// pool değişimi gün içinde: onaylı sayımın anlık görüntüsü korunur
$r = pdks_ters_havuz_cikar(kartIdUid($uidD(1)), $cavus['D'], 'kart değişti', 1, db());
ok('havuzdan çıkarma (gerekçeli) — onaylı sayım anlık görüntüsü değişmez', !empty($r['ok']) && say("SELECT COUNT(*) FROM ters_sayim_kart WHERE sayim_id = ? AND worker_card_id = ?", [$yeniD, kartIdUid($uidD(1))]) === 1, j($r));
$r = pdks_ters_havuz_aktar(kartIdUid($uidD(2)), $cavus['D'], $cavus['C'], 'devir', 1, db());
ok('aktarma: kart C havuzuna geçti', !empty($r['ok']) && (int)pdks_gunluk_ters_havuz_aktif(db(), kartIdUid($uidD(2)))['foreman_id'] === $cavus['C'], j($r));
$r = pdks_ters_havuz_sifirla($cavus['D'], 'yeniden kurulum', 1, db());
ok('havuz sıfırlandı (üyelikler kapandı, satırlar duruyor)', !empty($r['ok']) && pdks_ters_havuz_uyeleri(db(), $cavus['D'], 'Depo A') === [] && say("SELECT COUNT(*) FROM ters_havuz_kart WHERE foreman_id = ?", [$cavus['D']]) >= 6, j($r));
ok('geçmiş: kart hareketleri okunur', count(pdks_ters_kart_hareketleri(db(), kartIdUid($uidA(10)))) >= 2);

echo "\n=== K. Denetim bulguları (v354 düzeltmeleri) ===\n";
$uidE = fn(int $i): string => (string)(900000000 + $i);
for ($i = 1; $i <= 4; $i++) pdks_ters_havuz_ekle($uidE($i), 'usb_decimal', $cavus['E'], $kadin, 1, db());
// (3) bugün normal girip çıkmış kart sayımda okutulmazsa → İKİNCİ katılım UYARISI (engel değil)
$sE = (int)pdks_gunluk_oturum_ac_veya_getir($cavus['E'], 1, db())['session']['id'];
pdks_gunluk_faz8a_giris_kaydet($uidE(1), 'usb_decimal', $sE, $kadin, 'auto', 1, db());
pdks_gunluk_ortak_cikis_kaydet($uidE(1), 'usb_decimal', 1, db());
$se = (int)pdks_ters_sayim_ac($cavus['E'], 1, db())['sayim']['id'];
$d = pdks_ters_sayim_onizle($se, $saat, 'x', '', db())['degerlendirme'];
ok('bugün sabit saatten SONRA çıkmış kart → çakışma ENGELİ (aynı saatlerde iki dönem yok)', !$d['ok'] && count($d['hatalar']) === 1 && str_contains($d['hatalar'][0]['sebep'], 'çakışan'), j($d['hatalar']));
if ((int)date('H') <= 22) {
    $d = pdks_ters_sayim_onizle($se, sprintf('%02d:00', (int)date('H') + 1), 'x', '', db())['degerlendirme'];
    ok('bugün çalışıp ÇIKMIŞ kart, sabit saat sonra → ikinci katılım UYARISI (engel değil)', $d['ok'] && str_contains(implode(' ', $d['uyarilar']), 'İKİNCİ'), j($d['uyarilar']) . j($d['genel']));
} else { ok('(23:xx — ikinci katılım uyarısı testi atlandı)', true); }
// (4)/(5) teyit ve sayım ekranından kayıp YALNIZ dönmemiş kart için
$r = pdks_ters_sayim_karar($se, kartIdUid($uidE(2)), 'dagitildi', '', 1, db());
ok('dönmemiş olmayan karta "bugün dağıtıldı" teyidi verilemez', ($r['kod'] ?? '') === 'donmemis_degil', j($r));
$r = pdks_ters_sayim_karar($se, kartIdUid($uidE(2)), 'kayip', '', 1, db());
ok('sayım ekranından dönmemiş olmayan kart Kayıp işaretlenemez', ($r['kod'] ?? '') === 'donmemis_degil' && satir("SELECT status FROM worker_cards WHERE id = ?", [kartIdUid($uidE(2))])['status'] === 'available', j($r));
// (1) ileri tarihli sabit saat: çıkış giriş saatinden önce yazılamaz
$ileriSaat = (int)date('H') + 1;
if ($ileriSaat <= 23) {
    $uidF = fn(int $i): string => (string)(950000000 + $i);
    for ($i = 1; $i <= 2; $i++) pdks_ters_havuz_ekle($uidF($i), 'usb_decimal', $cavus['F'], $kadin, 1, db());
    $sf = (int)pdks_ters_sayim_ac($cavus['F'], 1, db())['sayim']['id'];
    $r = pdks_ters_sayim_onayla($sf, sprintf('%02d:00', $ileriSaat), 'erken onay', '', istek(), 1, db());
    ok('sabit saat ileride (≤2 sa) → onay kabul', !empty($r['ok']), j($r));
    $r = pdks_ters_cikis_kaydet($uidF(1), 'usb_decimal', 1, db());
    ok('giriş saati gelmeden çıkış → giris_saati_gelmedi, çıkış yazılmadı', ($r['kod'] ?? '') === 'giris_saati_gelmedi'
        && say("SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id = ? AND event_type = 'CIKIS'", [kartIdUid($uidF(1))]) === 0, j($r));
} else { ok('(23:xx — ileri saat çıkış testi atlandı)', true); }
$mt = pdks_ters_mutabakat(db(), date('Y-m-d'), 'Depo A');
$ra = array_values(array_filter($mt['satirlar'], fn($x) => $x['foreman_id'] === $cavus['A']))[0] ?? [];
ok('mutabakat teyitli (dönmemiş) kart sayısını ayrıca gösterir (A: 2)', ($ra['teyitli'] ?? -1) === 2, j($ra['teyitli'] ?? null));

echo "\n=== J. Statik sözleşmeler ===\n";
$src = file_get_contents($ROOT . '/config/pdks_ters.php');
ok('pdks_ters.php dönem/olay tablolarına DOĞRUDAN yazmaz (tek yazma yolu)', !preg_match('/(INSERT\s+INTO|UPDATE)\s+`?daily_worker_(work_periods|card_events)/i', $src));
ok('pdks_ters.php worker_cards\'a doğrudan UPDATE/INSERT yazmaz', !preg_match('/(INSERT\s+INTO|UPDATE)\s+`?worker_cards\b/i', $src));
ok('giriş yazımı pdks_faz8j_satir_yaz, çıkış ortak çıkış üzerinden', str_contains($src, 'pdks_faz8j_satir_yaz(') && str_contains($src, 'pdks_gunluk_ortak_cikis_kaydet('));
$gsrc = file_get_contents($ROOT . '/config/pdks_gunluk.php');
ok('pdks_gunluk.php pdks_ters.php\'yi require ETMEZ', !preg_match('/require(_once)?[^;]*pdks_ters\.php/', $gsrc));
ok('normal giriş kart kilidi altında ters havuz kapısını çağırır', (bool)preg_match('/pdks_gunluk_faz8a_kart_kilitle\(\$pdo, \(int\)\$kart\[\'id\'\]\);.*?pdks_gunluk_ters_havuz_engeli\(/s', $gsrc));
$jsrc = file_get_contents($ROOT . '/config/pdks_faz8j.php');
ok('satir_yaz varsayılan kaynak manual (eski davranış)', str_contains($jsrc, "\$kaynak = (string)(\$meta['kaynak'] ?? 'manual');"));

echo "\n$gecen geçti, $fail hata\n";
exit($fail ? 1 : 0);
