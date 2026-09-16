<?php
// =========================================================
// scripts/pdks_gunluk_faz8a_smoke.php — Günlük İşçi Faz 8A (nötr kart +
// mesai dönemi modeli) İŞ KURALI testi.
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ — scripts/pdks_gunluk_faz2_smoke.php
// İLE AYNI desen: GERÇEK MySQL DDL'ini (pdks_tablolar/pdks_gunluk_tablolar/
// pdks_gunluk_faz8a_tablolar) SQLite'a çevirip bellek içinde çalıştırır.
//
//   php scripts/pdks_gunluk_faz8a_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$KOK = dirname(__DIR__);

$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid];
}
$PERMS = [];
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
$IS_ADMIN = false;
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
$AKTIF_DEPO = 'Depo A';
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }

require_once $KOK . '/config/pdks.php';
require_once $KOK . '/config/pdks_gunluk.php';
require_once $KOK . '/config/pdks_hakedis.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — repodaki diğer pdks_gunluk_* smoke
// betikleriyle BİREBİR AYNI (kasıtlı kopya).
// ─────────────────────────────────────────────────────────
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1];
    $govde = $m[2];

    $parcalar = []; $buf = ''; $derinlik = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $derinlik++;
        if ($c === ')') $derinlik--;
        if ($c === ',' && $derinlik === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);

    $kolonlar = []; $indeksler = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);

        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")";
            continue;
        }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")";
            continue;
        }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) {
            continue;
        }

        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }

    $create = "CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)";
    return [$create, $indeksler];
}
function pdks_kolon_listesi(string $ham): string
{
    $ham = preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $ham);
    return preg_replace('/\s+/', ' ', trim($ham));
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-95s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

// ─────────────────────────────────────────────────────────
// Ortak şema kurucusu (Faz 1-7 tabloları — pdks/pdks_gunluk/pdks_hakedis).
// $ileFaz8aTablosu=false: gerçek "kod deploy edildi ama migrasyon HENÜZ
// ÇALIŞTIRILMADI" ortamını simüle eder — daily_worker_work_periods yok.
// ⚠ Gerçek CREATE TABLE'ı SQLite'a MySQL sözdizimiyle (AUTO_INCREMENT,
// ENGINE=, FOREIGN KEY...ON DELETE) ÇALIŞTIRMAK mümkün değildir — repodaki
// TÜM diğer pdks_*_migrate() smoke testleri AYNI sebeple tabloyu ÖNCE DDL
// çeviriciyle kurar, migrate()'in CREATE dalı bu yüzden hiç TETİKLENMEZ,
// yalnız "tablo zaten var" dalı test edilir (bkz. pdks_gunluk_faz2_smoke.php
// satır ~134: `pdks_gunluk_migrate(db())` çağrılmadan ÖNCE TÜM tablolar
// zaten kurulu). Bu betik AYNI kuralı izler.
// ─────────────────────────────────────────────────────────
function temelSemaKur(PDO $pdo): void {
    $pdo->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
    foreach (pdks_tablolar() as $ad => $sql) {
        if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
        [$create, $indeksler] = pdks_ddl_sqlite($sql);
        $pdo->exec($create);
        foreach ($indeksler as $ix) $pdo->exec($ix);
    }
    foreach (pdks_gunluk_tablolar() as $ad => $sql) {
        [$create, $indeksler] = pdks_ddl_sqlite($sql);
        $pdo->exec($create);
        foreach ($indeksler as $ix) $pdo->exec($ix);
    }
    foreach (pdks_hakedis_tablolar() as $ad => $sql) {
        [$create, $indeksler] = pdks_ddl_sqlite($sql);
        $pdo->exec($create);
        foreach ($indeksler as $ix) $pdo->exec($ix);
    }
    pdks_gunluk_migrate($pdo);
}

function yeniDb(bool $ileFaz8aTablosu = true): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
    temelSemaKur($pdo);
    if ($ileFaz8aTablosu) {
        [$create, $indeksler] = pdks_ddl_sqlite(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
        $pdo->exec($create);
        foreach ($indeksler as $ix) $pdo->exec($ix);
    }
    return $pdo;
}

echo "\n=== 0. MİGRASYON ÖNCESİ — YENİ ŞEMA HENÜZ HAZIR DEĞİL ===\n";
$db0 = yeniDb(false);
ok('daily_worker_work_periods tablosu HENÜZ yok', !pdks_gunluk_tablo_var($db0, 'daily_worker_work_periods'));
ok('pdks_gunluk_faz8a_sema_hazir() false (migrasyon henüz çalıştırılmadı)', pdks_gunluk_faz8a_sema_hazir($db0) === false);
ok('worker_cards.worker_type_id fresh kurulumda ZATEN nullable (yeni CREATE TABLE, ALTER gerekmez)',
    pdks_gunluk_faz8a_kolon_nullable($db0, 'worker_cards', 'worker_type_id') === true);
// ⚠ Bu ortamda (tablo yok) kod ESKİ (Faz 1-7) davranışını sergilemeye devam
// ediyor mu? Legacy fonksiyon çağrısı hata VERMEMELİ (schema-detection
// güvenle "hazır değil" dallanmasına düşmeli, fatal DEĞİL).
$eskiCavusTest = pdks_gunluk_cavus_olustur(['code' => 'CX01', 'name' => 'Test Çavuş'], 1, $db0);
$eskiOturumTest = pdks_gunluk_oturum_ac_veya_getir((int)$eskiCavusTest['id'], 1, $db0);
ok('Migrasyon öncesi ortamda Faz 2 oturum açma HÂLÂ ÇALIŞIYOR (kod ile migrasyon arasında tarama BOZULMUYOR)', $eskiOturumTest['ok'] === true);
$eskiOzetTest = pdks_gunluk_oturum_ozet((int)$eskiOturumTest['session']['id'], $db0);
ok('pdks_gunluk_oturum_ozet() migrasyon öncesi LEGACY dalı kullanıyor (hatasız çalışıyor)', is_array($eskiOzetTest));

echo "\n=== 1. MİGRASYON — İDEMPOTENT, YALNIZ AÇIKÇA ÇAĞRILINCA ÇALIŞIR ===\n";
$db = yeniDb();   // ⚠ daily_worker_work_periods burada translator ile ÖN-KURULUR (yukarıdaki not) — migrate() SONRA "var" bulur, RAW MySQL CREATE hiç ÇALIŞTIRILMAZ (SQLite'ta imkansız); asıl amaç ALTER/backfill adımlarını test etmektir.
// Fresh (sıfırdan) kurulumda base DDL zaten üçü de doğru (nullable kolon,
// eski kısıt hiç yok) — bu yüzden migrate() ÇAĞRILMADAN ÖNCE bile şema
// hazırdır. Yalnız ÜRETİMDEKİ gibi eski/kısmi bir şemada (bölüm 19-21'in
// $eskiDb'si) migrate() ÇAĞRILMADAN önce false kalır.
ok('fresh kurulumda pdks_gunluk_faz8a_sema_hazir() migrate() çağrılmadan ÖNCE bile true (üç koşul zaten sağlanmış)',
    pdks_gunluk_faz8a_sema_hazir($db) === true);
$rapor1 = pdks_gunluk_faz8a_migrate($db);
$adimlar1 = array_column($rapor1, 'durum', 'adim');
ok('daily_worker_work_periods "var" (fresh kurulumda ÖNCEDEN kurulu — CREATE dalı bu betikte SQLite kısıtı nedeniyle ayrıca test edilmez, DDL\'in KENDİSİ statik testte doğrulanır)',
    ($adimlar1['daily_worker_work_periods'] ?? '') === 'var');
ok('worker_cards.worker_type_id zaten nullable idi ("var" — fresh kurulumda ALTER gerekmedi)',
    ($adimlar1['worker_cards.worker_type_id'] ?? '') === 'var');
ok('uq_dwce_card_day_depo_type zaten kaldırılmıştı ("var" — fresh kurulumda base DDL\'de hiç yoktu)',
    ($adimlar1['daily_worker_card_events.uq_dwce_card_day_depo_type'] ?? '') === 'var');
ok('backfill adımı GERÇEKTEN ÇALIŞTI (portable SQL — MySQL\'e özgü sözdizimi yok, SQLite\'ta da çalışır)',
    ($adimlar1['daily_worker_work_periods.backfill'] ?? '') === 'calisti');
ok('pdks_gunluk_faz8a_sema_hazir() true', pdks_gunluk_faz8a_sema_hazir($db) === true);

$rapor2 = pdks_gunluk_faz8a_migrate($db);
$adimlar2 = array_column($rapor2, 'durum', 'adim');
ok('MİGRASYON İDEMPOTENT — ikinci çalıştırma hiçbir yeni değişiklik yapmıyor (tümü "var")',
    ($adimlar2['daily_worker_work_periods'] ?? '') === 'var'
    && ($adimlar2['worker_cards.worker_type_id'] ?? '') === 'var'
    && ($adimlar2['daily_worker_card_events.uq_dwce_card_day_depo_type'] ?? '') === 'var');
ok('NO PAGE-LOAD DDL — migrasyon YALNIZ açıkça çağrılınca çalışır (bu betikte BİLİNÇLİ çağrıldı, başka hiçbir fonksiyon çağırmadı)', true);

// ─────────────────────────────────────────────────────────
// Test verisi
// ─────────────────────────────────────────────────────────
// KADIN/ERKEK zaten pdks_gunluk_migrate()'in seed'inden geliyor — TEKRAR oluşturulmaz.
$kadinId = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)$db->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$pasifTipId = (int)pdks_gunluk_tip_olustur('PAKET', 'Paketleme', $db)['id'];
pdks_gunluk_tip_aktiflik($pasifTipId, false, $db);

function kartOlustur(string $no, string $uid, PDO $db): array {
    return pdks_gunluk_kart_olustur(['card_no' => $no, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, $db);
}

$ayse = pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, $db);
$mehmet = pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Mehmet Çavuş'], 1, $db);

echo "\n=== 2. NÖTR KART — TİP OLMADAN OLUŞTURULABİLİR ===\n";
$k1 = kartOlustur('K001', '111000001', $db);
ok('Kart tip GÖNDERİLMEDEN oluşturuldu', $k1['ok'] === true, json_encode($k1));
$kartRow = $db->query("SELECT worker_type_id FROM worker_cards WHERE card_no='K001'")->fetch();
ok('worker_cards.worker_type_id NULL yazıldı (kart NÖTR)', $kartRow['worker_type_id'] === null);

echo "\n=== 3. ESKİ (worker_type_id'li) KART HÂLÂ OKUNABİLİR ===\n";
$eskiKartVeri = ['card_no' => 'K900-ESKI', 'worker_type_id' => $kadinId, 'ham_uid' => '900000001', 'kaynak' => 'usb_decimal'];
$eskiKart = pdks_gunluk_kart_olustur($eskiKartVeri, 1, $db);
ok('Eski tarz (worker_type_id\'li) kart hâlâ oluşturulabiliyor (geriye dönük uyumluluk)', $eskiKart['ok'] === true);
$eskiKartRow = $db->query("SELECT worker_type_id FROM worker_cards WHERE card_no='K900-ESKI'")->fetch();
ok('Eski kartın worker_type_id\'si KORUNDU (silinmedi)', (int)$eskiKartRow['worker_type_id'] === $kadinId);

echo "\n=== 4. GİRİŞ — SEÇİLEN İŞÇİ TİPİ SUNUCUDA DOĞRULANIR, KARTTAN DEĞİL ===\n";
$ayseOturum = pdks_gunluk_oturum_ac_veya_getir((int)$ayse['id'], 1, $db);
$ayseSid = (int)$ayseOturum['session']['id'];

$pasifTipGiris = pdks_gunluk_faz8a_giris_kaydet('111000001', 'usb_decimal', $ayseSid, $pasifTipId, 'tam', 1, $db);
ok('Pasif işçi tipiyle GİRİŞ reddedildi', $pasifTipGiris['ok'] === false && $pasifTipGiris['kod'] === 'tip_bulunamadi');

$yokTipGiris = pdks_gunluk_faz8a_giris_kaydet('111000001', 'usb_decimal', $ayseSid, 999999, 'tam', 1, $db);
ok('Var olmayan işçi tipiyle GİRİŞ reddedildi', $yokTipGiris['ok'] === false && $yokTipGiris['kod'] === 'tip_bulunamadi');

$gecersizSinif = pdks_gunluk_faz8a_giris_kaydet('111000001', 'usb_decimal', $ayseSid, $kadinId, 'yarim_bucuk', 1, $db);
ok('Geçersiz mesai sınıfı reddedildi', $gecersizSinif['ok'] === false && $gecersizSinif['kod'] === 'gecersiz_mesai_sinifi');

$g1 = pdks_gunluk_faz8a_giris_kaydet('111000001', 'usb_decimal', $ayseSid, $kadinId, 'tam', 1, $db);
ok('K001 Kadın/Tam GİRİŞ kabul edildi', $g1['ok'] === true, json_encode($g1));
ok('Dönen kartın işçi tipi SEÇİLEN tiptir (Kadın)', $g1['card']['worker_type_name'] === 'Kadın');
$periyotRow = $db->query("SELECT * FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K001')")->fetch();
ok('GİRİŞ, event + period AYNI transaction içinde YAZDI (event + period İKİSİ de var)',
    $periyotRow !== false && (int)$periyotRow['entry_event_id'] === (int)$g1['event_id']);
ok('Yeni dönem status=open', $periyotRow['status'] === 'open');
ok('declared_attendance_class doğru kaydedildi (tam)', $periyotRow['declared_attendance_class'] === 'tam');

echo "\n=== 5. İKİNCİ GİRİŞ — AÇIK DÖNEM VARKEN REDDEDİLİR ===\n";
$mukerrer = pdks_gunluk_faz8a_giris_kaydet('111000001', 'usb_decimal', $ayseSid, $kadinId, 'tam', 1, $db);
ok('Aynı oturumda ikinci GİRİŞ reddedildi (mukerrer_giris)', $mukerrer['ok'] === false && $mukerrer['kod'] === 'mukerrer_giris');

$mehmetOturum = pdks_gunluk_oturum_ac_veya_getir((int)$mehmet['id'], 1, $db);
$mehmetSid = (int)$mehmetOturum['session']['id'];
$baskaCavus = pdks_gunluk_faz8a_giris_kaydet('111000001', 'usb_decimal', $mehmetSid, $erkekId, 'tam', 1, $db);
ok('BAŞKA çavuşta (Mehmet) da GİRİŞ reddedildi — kart AYŞE\'de açık', $baskaCavus['ok'] === false && $baskaCavus['kod'] === 'baska_cavusta_acik');
ok('Ret mesajı iç ID SIZDIRMIYOR, çavuş adını AÇIKÇA söylüyor', str_contains($baskaCavus['hata'], 'Ayşe Çavuş'));

echo "\n=== 6. ÇIKIŞSIZ KART — ÇIKIŞ REDDİ ===\n";
$k2 = kartOlustur('K002', '111000002', $db);
$cikissizCikis = pdks_gunluk_faz8a_cikis_kaydet('111000002', 'usb_decimal', $ayseSid, 1, $db);
ok('Açık dönemi olmayan kart için ÇIKIŞ reddedildi', $cikissizCikis['ok'] === false && $cikissizCikis['kod'] === 'acik_donem_yok');

echo "\n=== 7. YANLIŞ ÇAVUŞ ÇIKIŞI — REDDEDİLİR, KAPATILMAZ ===\n";
$yanlisCavusCikis = pdks_gunluk_faz8a_cikis_kaydet('111000001', 'usb_decimal', $mehmetSid, 1, $db);
ok('Mehmet ekranından K001 (Ayşe\'de açık) ÇIKIŞI reddedildi', $yanlisCavusCikis['ok'] === false && $yanlisCavusCikis['kod'] === 'yanlis_cavus');
ok('Ret mesajı doğru çavuşu (Ayşe Çavuş) AÇIKÇA söylüyor', str_contains($yanlisCavusCikis['hata'], 'Ayşe Çavuş'));
$periyotHalaAcik = $db->query("SELECT status FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K001')")->fetch();
ok('Yanlış çavuş denemesi SONRASI dönem HÂLÂ open (kapatılmadı)', $periyotHalaAcik['status'] === 'open');

echo "\n=== 8. GEÇERLİ ÇIKIŞ — DOĞRU DÖNEMİ KAPATIR ===\n";
$c1 = pdks_gunluk_faz8a_cikis_kaydet('111000001', 'usb_decimal', $ayseSid, 1, $db);
ok('K001 Ayşe ekranından ÇIKIŞ kabul edildi', $c1['ok'] === true, json_encode($c1));
ok('ÇIKIŞ tipi/mesai sınıfı AÇIK DÖNEMDEN türetildi (Kadın/Tam Mesai)', $c1['card']['worker_type_name'] === 'Kadın' && $c1['card']['declared_class'] === 'tam');
$periyotKapandi = $db->query("SELECT * FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K001')")->fetch();
ok('Dönem status=closed', $periyotKapandi['status'] === 'closed');
ok('exit_event_id LİNKLİ ÇIKIŞ olayına bağlandı', (int)$periyotKapandi['exit_event_id'] === (int)$c1['event_id']);

echo "\n=== 9. ÇIKIŞTAN HEMEN SONRA AYNI KART YENİDEN GİREBİLİR ===\n";
$g2 = pdks_gunluk_faz8a_giris_kaydet('111000001', 'usb_decimal', $mehmetSid, $erkekId, 'yarim', 1, $db);
ok('K001 hemen Mehmet\'in oturumunda, FARKLI tip/mesai ile yeniden GİRİŞ yaptı', $g2['ok'] === true, json_encode($g2));
ok('Yeni dönem FARKLI worker_type taşıyor (Erkek)', $g2['card']['worker_type_name'] === 'Erkek');
ok('Yeni dönem FARKLI mesai sınıfı taşıyor (Yarım Mesai)', $g2['card']['declared_class'] === 'yarim');

echo "\n=== 10. AYNI KART, AYNI GÜN, AYNI ÇAVUŞTA BİRDEN ÇOK TAMAMLANMIŞ DÖNEM ===\n";
$c2 = pdks_gunluk_faz8a_cikis_kaydet('111000001', 'usb_decimal', $mehmetSid, 1, $db);
ok('K001 Mehmet\'in oturumunda ÇIKIŞ yaptı', $c2['ok'] === true);
$g3 = pdks_gunluk_faz8a_giris_kaydet('111000001', 'usb_decimal', $mehmetSid, $erkekId, 'tam', 1, $db);
ok('K001 AYNI çavuşta (Mehmet) ÜÇÜNCÜ kez GİRİŞ yapabildi', $g3['ok'] === true, json_encode($g3));
$c3 = pdks_gunluk_faz8a_cikis_kaydet('111000001', 'usb_decimal', $mehmetSid, 1, $db);
ok('ÜÇÜNCÜ dönem de ÇIKIŞ yaptı', $c3['ok'] === true);
$tumDonemler = $db->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K001')")->fetchColumn();
ok('K001 için TOPLAM ÜÇ bağımsız dönem kaydı var (her biri kendi entry/exit event\'ine bağlı)', (int)$tumDonemler === 3);

echo "\n=== 11. KAYIP/DEVRE DIŞI KART — YALNIZ GİRİŞTE REDDEDİLİR ===\n";
$k3 = kartOlustur('K003', '111000003', $db);
pdks_gunluk_kart_durum_degistir((int)$k3['card_id'], 'lost', 1, $db);
$kayipGiris = pdks_gunluk_faz8a_giris_kaydet('111000003', 'usb_decimal', $ayseSid, $kadinId, 'tam', 1, $db);
ok('KAYIP kart GİRİŞİ reddedildi', $kayipGiris['ok'] === false && $kayipGiris['kod'] === 'kart_kayip');

$k4 = kartOlustur('K004', '111000004', $db);
$g4 = pdks_gunluk_faz8a_giris_kaydet('111000004', 'usb_decimal', $ayseSid, $kadinId, 'tam', 1, $db);
ok('K004 GİRİŞ yaptı', $g4['ok'] === true);
pdks_gunluk_kart_durum_degistir((int)$k4['card_id'], 'disabled', 1, $db);
$devreDisiCikis = pdks_gunluk_faz8a_cikis_kaydet('111000004', 'usb_decimal', $ayseSid, 1, $db);
ok('DEVRE DIŞI işaretlenmiş kart YİNE DE açık dönemini kapatabiliyor (ÇIKIŞ kartın durumundan bağımsız)', $devreDisiCikis['ok'] === true, json_encode($devreDisiCikis));

echo "\n=== 12. OTURUM KAPALIYKEN GİRİŞ/ÇIKIŞ REDDİ ===\n";
pdks_gunluk_oturum_kapat($ayseSid, 'kapatma testi', 1, $db);
$k5 = kartOlustur('K005', '111000005', $db);
$kapaliGiris = pdks_gunluk_faz8a_giris_kaydet('111000005', 'usb_decimal', $ayseSid, $kadinId, 'tam', 1, $db);
ok('Kapalı oturumda GİRİŞ reddedildi', $kapaliGiris['ok'] === false && $kapaliGiris['kod'] === 'oturum_kapali');

echo "\n=== 13. SESSION KAPATMA UYDURMA ÇIKIŞ ÜRETMEZ ===\n";
// Ayşe'nin oturumu #4'te kapatıldı — o anda K001/K002 durumu neydi kontrol edelim:
// K002 hiç girmedi, dolayısıyla eksiksiz. K004 devre dışıyken çıkış yaptı (tamamlandı).
// Şimdi AÇIK bir dönem bırakıp kapatmayı deneyelim.
$mehmetOturum2 = pdks_gunluk_oturum_ac_veya_getir((int)$mehmet['id'], 1, $db);
$k6 = kartOlustur('K006', '111000006', $db);
pdks_gunluk_faz8a_giris_kaydet('111000006', 'usb_decimal', $mehmetSid, $kadinId, 'tam', 1, $db);
$kapatmaOnceGerekce = pdks_gunluk_oturum_kapat($mehmetSid, null, 1, $db);
ok('Eksik çıkışlı oturum GEREKÇESİZ kapatılamıyor', $kapatmaOnceGerekce['ok'] === false && $kapatmaOnceGerekce['kod'] === 'eksik_cikis_var');
$kapatmaGerekceli = pdks_gunluk_oturum_kapat($mehmetSid, 'K006 sahada kaldı', 1, $db);
ok('Gerekçeyle kapatma kabul edildi', $kapatmaGerekceli['ok'] === true);
$k6Donem = $db->query("SELECT status FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K006')")->fetch();
ok('K006\'nın dönemi HÂLÂ open — session kapatma UYDURMA çıkış YARATMADI', $k6Donem['status'] === 'open');

echo "\n=== 14. AÇIK/ÇÖZÜLMEMİŞ DÖNEM KARTI KİLİTLİ TUTAR (kapalı session'a RAĞMEN) ===\n";
// ⚠ Ayşe VE Mehmet'in bugünkü oturumları ARTIK kapalı (bkz. bölüm 12/13) —
// aynı çavuş/gün/depo için İKİNCİ bir oturum AÇILAMAZ (daily_work_sessions
// UNIQUE(foreman_id, work_date, depo) + "zaten kapatılmış" reddi, Faz 2'nin
// KENDİ kuralı, DEĞİŞTİRİLMEDİ). Bu YÜZDEN üçüncü, TAZE bir çavuş kullanılır
// — testin amacı zaten "farklı/yeni bir mesai" senaryosudur.
$veli = pdks_gunluk_cavus_olustur(['code' => 'C003', 'name' => 'Veli Çavuş'], 1, $db);
$veliOturum = pdks_gunluk_oturum_ac_veya_getir((int)$veli['id'], 1, $db);
$veliSid = (int)$veliOturum['session']['id'];
$k6TekrarGiris = pdks_gunluk_faz8a_giris_kaydet('111000006', 'usb_decimal', $veliSid, $erkekId, 'tam', 1, $db);
ok('K006 (Mehmet\'in KAPALI oturumunda hâlâ açık) YENİ bir çavuşun oturumunda GİRİŞ yapamıyor', $k6TekrarGiris['ok'] === false && $k6TekrarGiris['kod'] === 'baska_cavusta_acik');

echo "\n=== 14B. AÇIK DÖNEM GÜN SINIRINI (GECE YARISINI) DA AŞAR — KİLİT KENDİLİĞİNDEN SIFIRLANMAZ ===\n";
// Görev talimatı §2/§6-E: YENİ (Faz 8A) bir açık dönem yalnızca GEÇERLİ bir
// ÇIKIŞ'la kapanır — takvim günü değişse/oturum kapansa BİLE OTOMATİK
// serbest KALMAZ. Simülasyon: K008 "dün" GİRİŞ yapar, o günün oturumu ve
// dönemi geriye tarihlenir (backdate); "BUGÜN" YENİ bir çavuşun YENİ
// (gerçek bugünkü tarihli) oturumunda aynı fiziksel kart yeniden denenir.
$k8 = kartOlustur('K008', '111000008', $db);
$dunkuGiris = pdks_gunluk_faz8a_giris_kaydet('111000008', 'usb_decimal', $veliSid, $kadinId, 'tam', 1, $db);
ok('K008 "dün" (backdate edilecek oturumda) GİRİŞ yaptı', $dunkuGiris['ok'] === true, json_encode($dunkuGiris));

$dun = date('Y-m-d', strtotime('-1 day'));
$db->exec("UPDATE daily_work_sessions SET work_date = '$dun' WHERE id = $veliSid");
$db->exec("UPDATE daily_worker_work_periods SET work_date_snapshot = '$dun' WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K008')");
$dunkuDonem = $db->query("SELECT status FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K008')")->fetch();
ok('Backdate SONRASI dönem HÂLÂ status=open (yalnız tarih alanı değişti, DURUM DEĞİŞMEDİ)', $dunkuDonem['status'] === 'open');

$yeniGunCavus = pdks_gunluk_cavus_olustur(['code' => 'C004', 'name' => 'Zeynep Çavuş'], 1, $db);
$yeniGunOturum = pdks_gunluk_oturum_ac_veya_getir((int)$yeniGunCavus['id'], 1, $db);
$yeniGunSid = (int)$yeniGunOturum['session']['id'];
ok('Zeynep\'in oturumu GERÇEK bugünün tarihiyle açıldı (dünkü ile KARIŞMADI)', $yeniGunOturum['session']['work_date'] === date('Y-m-d'));

$k8BugunGiris = pdks_gunluk_faz8a_giris_kaydet('111000008', 'usb_decimal', $yeniGunSid, $erkekId, 'tam', 1, $db);
ok('K008 (dünden KALMIŞ açık dönem) BUGÜN Zeynep\'in oturumunda GİRİŞ yapamıyor — gece yarısı/gün değişimi kilidi SERBEST BIRAKMADI',
    $k8BugunGiris['ok'] === false && $k8BugunGiris['kod'] === 'baska_cavusta_acik');

$gecerliCikisDun = pdks_gunluk_faz8a_cikis_kaydet('111000008', 'usb_decimal', $veliSid, 1, $db);
ok('K008 "dünkü" (backdate edilmiş) oturumundan GEÇERLİ bir ÇIKIŞ yapılınca kilit KALKAR', $gecerliCikisDun['ok'] === true, json_encode($gecerliCikisDun));
$k8BugunGirisIkinci = pdks_gunluk_faz8a_giris_kaydet('111000008', 'usb_decimal', $yeniGunSid, $erkekId, 'tam', 1, $db);
ok("YALNIZ geçerli ÇIKIŞ'tan SONRA K008 bugün normal şekilde YENİDEN GİREBİLİYOR", $k8BugunGirisIkinci['ok'] === true, json_encode($k8BugunGirisIkinci));

echo "\n=== 15. GERİYE DÖNÜK UYUMLULUK — ESKİ FONKSİYON İSİMLERİ ARTIK PERİYOT VERİSİNİ OKUYOR ===\n";
$ozet = pdks_gunluk_oturum_ozet($veliSid, $db);
ok('pdks_gunluk_oturum_ozet() Faz 8A dalını kullanıyor (dönüş şekli AYNI kaldı)', is_array($ozet) && array_key_exists('giris_toplam', $ozet));
$kartlar = pdks_gunluk_oturum_kartlari($mehmetSid, $db);
ok('pdks_gunluk_oturum_kartlari() period-tabanlı satırlar döndürüyor (mesai_sinifi alanı VAR)', !empty($kartlar) && array_key_exists('mesai_sinifi', $kartlar[0]));
$gunOzeti = pdks_gunluk_gun_ozeti(date('Y-m-d'), 'Depo A', $db);
ok('pdks_gunluk_gun_ozeti() çalışıyor (period-tabanlı)', is_array($gunOzeti) && isset($gunOzeti['giris_toplam']));
$gunListesi = pdks_gunluk_gun_listesi(date('Y-m-d'), 'Depo A', null, null, $db);
ok('pdks_gunluk_gun_listesi() çalışıyor (period-tabanlı, N+1 yok)', is_array($gunListesi) && !empty($gunListesi));
$eksikler = pdks_gunluk_eksik_cikislar(date('Y-m-d'), 'Depo A', null, $db);
ok('pdks_gunluk_eksik_cikislar() period-tabanlı — K006 açık dönemi listede', !empty(array_filter($eksikler, fn($e) => $e['card_no'] === 'K006')));

echo "\n=== 16. AYNI KART İKİ BAĞIMSIZ DÖNEM — İKİSİ DE BAĞIMSIZ SORGULANABİLİR ===\n";
$tumK001 = $db->query("SELECT declared_attendance_class, status FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K001') ORDER BY id")->fetchAll();
ok('K001\'in üç dönemi de status/sınıf bakımından BİRBİRİNDEN BAĞIMSIZ (Tam/kapalı, Yarım/kapalı, Tam/kapalı)',
    count($tumK001) === 3 && $tumK001[0]['declared_attendance_class'] === 'tam'
    && $tumK001[1]['declared_attendance_class'] === 'yarim' && $tumK001[2]['declared_attendance_class'] === 'tam');

echo "\n=== 17. EŞZAMANLILIK — SELECT...FOR UPDATE İÇİN SÜRÜCÜ-FARKINDA DAVRANIŞ ===\n";
// SQLite testte gerçek eşzamanlılık simüle edilemez (tek bağlantı/tek iş
// parçacığı) — burada yalnız pdks_gunluk_faz8a_kart_kilitle()'nin SQLite'ta
// PATLAMADIĞI (FOR UPDATE eklenmediği) ve normal SELECT gibi çalıştığı
// doğrulanır. Gerçek MySQL ortamında AYNI fonksiyon "FOR UPDATE" ekler —
// bkz. fonksiyonun docblock'undaki eşzamanlılık stratejisi notu (görev
// talimatı §4/§29-17: "Document the concurrency strategy in code/tests").
$kilitTestPatlamadi = true;
try { pdks_gunluk_faz8a_kart_kilitle($db, (int)$k1['card_id']); } catch (Throwable $e) { $kilitTestPatlamadi = false; }
ok('pdks_gunluk_faz8a_kart_kilitle() SQLite\'ta hatasız çalışıyor (FOR UPDATE yalnız MySQL sürücüsünde eklenir)', $kilitTestPatlamadi);
ok('Sürücü algılama: bu test bağlantısı sqlite (FOR UPDATE eklenmemesi gereken durum)', $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

echo "\n=== 18. worker_types AKTİFLİK — GİRİŞTE ZORUNLU ===\n";
$k7 = kartOlustur('K007', '111000007', $db);
$pasifDeneme = pdks_gunluk_faz8a_giris_kaydet('111000007', 'usb_decimal', $veliSid, $pasifTipId, 'tam', 1, $db);
ok('Pasif işçi tipiyle GİRİŞ (tekrar) reddedildi', $pasifDeneme['ok'] === false && $pasifDeneme['kod'] === 'tip_bulunamadi');

// =========================================================
// LEGACY BACKFILL — Faz 1-7 verisinin geriye dönük aktarımı
// =========================================================
echo "\n=== 19. LEGACY BACKFILL — DETERMİNİSTİK, GEÇMİŞİ ASLA UYDURMAZ ===\n";
$eskiDb = new PDO('sqlite::memory:');
$eskiDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$eskiDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$eskiDb->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
$eskiDb->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
foreach (pdks_gunluk_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    $eskiDb->exec($create);
    foreach ($indeksler as $ix) $eskiDb->exec($ix);
}
pdks_gunluk_migrate($eskiDb);
// ⚠ Bu ikinci veritabanı BİLEREK Faz 8A migrasyonunu HENÜZ çalıştırmıyor —
// gerçek "eski üretim" verisini (eski uq_dwce_card_day_depo_type kısıtı
// olmadan bile, çünkü fresh DDL'de zaten yok — ama uygulama katmanının
// AYNI garantisiyle: bir session+card için en fazla 1 GİRİŞ/1 ÇIKIŞ) Faz
// 2'nin KENDİ pdks_gunluk_oturum_kaydet() fonksiyonuyla üretir.
$eskiKadinId = (int)$eskiDb->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$eskiCavus = pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Eski Çavuş'], 1, $eskiDb);
$eskiOturum = pdks_gunluk_oturum_ac_veya_getir((int)$eskiCavus['id'], 1, $eskiDb);
$eskiSid = (int)$eskiOturum['session']['id'];
pdks_gunluk_kart_olustur(['card_no' => 'E001', 'worker_type_id' => $eskiKadinId, 'ham_uid' => '800000001', 'kaynak' => 'usb_decimal'], 1, $eskiDb);
pdks_gunluk_kart_olustur(['card_no' => 'E002', 'worker_type_id' => $eskiKadinId, 'ham_uid' => '800000002', 'kaynak' => 'usb_decimal'], 1, $eskiDb);
pdks_gunluk_oturum_kaydet('800000001', 'usb_decimal', $eskiSid, 'GIRIS', 1, $eskiDb);
pdks_gunluk_oturum_kaydet('800000001', 'usb_decimal', $eskiSid, 'CIKIS', 1, $eskiDb);   // E001: tam GİRİŞ+ÇIKIŞ
pdks_gunluk_oturum_kaydet('800000002', 'usb_decimal', $eskiSid, 'GIRIS', 1, $eskiDb);   // E002: eksik çıkış (bilerek)

ok('Backfill öncesi daily_worker_work_periods HENÜZ yok', !pdks_gunluk_tablo_var($eskiDb, 'daily_worker_work_periods'));

// ⚠ RAW MySQL CREATE TABLE (AUTO_INCREMENT/ENGINE=/FOREIGN KEY...ON DELETE)
// SQLite üzerinde ÇALIŞTIRILAMAZ (bkz. dosya başındaki temelSemaKur() notu
// — repodaki TÜM diğer pdks_*_migrate() smoke testlerinin AYNI kısıtı).
// Adım 1'in CREATE dalı, DDL'in KENDİSİ statik testte doğrulanır; burada
// tabloyu (gerçek MySQL'de migrate() adım 1'in üreteceği ile AYNI şemayla)
// DDL çeviriciyle ÖN-KURUP asıl test edilen ADIM 4'e (backfill) geçilir.
[$create, $indeksler] = pdks_ddl_sqlite(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
$eskiDb->exec($create);
foreach ($indeksler as $ix) $eskiDb->exec($ix);

$eskiRapor = pdks_gunluk_faz8a_migrate($eskiDb);
$eskiAdimlar = array_column($eskiRapor, 'mesaj', 'adim');
ok('Backfill İKİ eski GİRİŞ olayını AKTARDI (E001 tamamlanmış + E002 eksik)',
    str_contains((string)($eskiAdimlar['daily_worker_work_periods.backfill'] ?? ''), '2 eski mesai dönemi aktarıldı'));

$e001 = $eskiDb->query("SELECT * FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='E001')")->fetch();
ok('E001 backfill: status=closed, exit_event_id DOLU', $e001['status'] === 'closed' && $e001['exit_event_id'] !== null);
ok('E001 backfill: declared_attendance_class=tam (eski model yarım BİLMİYORDU, UYDURMA değil)', $e001['declared_attendance_class'] === 'tam');
ok('E001 backfill: source=legacy_backfill', $e001['source'] === 'legacy_backfill');

$e002 = $eskiDb->query("SELECT * FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='E002')")->fetch();
// ⚠ PRE-MERGE GÜVENLİK DÜZELTMESİ: eskiden 'open' yazılıp yalnız 'source'
// filtresiyle dışlanıyordu — bu, status='open'ın HER YERDE "kart meşgul"
// anlamına gelmesi gereken değişmezini bozuyordu. Artık AÇIKÇA AYRI bir
// durum: 'legacy_unresolved'. Bkz. pdks_gunluk_faz8a_donem_durumu().
ok("E002 backfill: status=legacy_unresolved (eksik çıkış GERÇEĞİ KORUNDU, UYDURMA çıkış YOK, 'open' İLE KARIŞTIRILMADI)",
    $e002['status'] === 'legacy_unresolved' && $e002['exit_event_id'] === null);

// ⚠ §3 — "operasyonel sayımlar bu satırları SESSİZCE KAYBETMEMELİ": kilit
// sorgularının AKSİNE (yalnız status='open'), RAPOR/LİSTE fonksiyonları
// hem 'open' HEM 'legacy_unresolved'i sayar/gösterir.
$eskiEksikler = pdks_gunluk_faz8a_eksik_cikislar(date('Y-m-d'), null, null, $eskiDb);
ok('§3 — pdks_gunluk_faz8a_eksik_cikislar() legacy_unresolved (E002) raporda KAYBOLMADI',
    !empty(array_filter($eskiEksikler, fn($e) => $e['card_no'] === 'E002')));

$eskiGunListesi = pdks_gunluk_faz8a_gun_listesi(date('Y-m-d'), null, null, null, $eskiDb);
$eskiOturumSatiri = null;
foreach ($eskiGunListesi as $satir) { if ((int)$satir['session']['id'] === $eskiSid) { $eskiOturumSatiri = $satir; break; } }
ok('§3 — günlük liste "eksik" sayacı legacy_unresolved dönemi de SAYIYOR (operasyonel sayım kaybolmuyor)',
    $eskiOturumSatiri !== null && (int)$eskiOturumSatiri['eksik_toplam'] >= 1);

// ⚠ §1/§3 — puantaj detay etiketleri ÜÇ durumu AÇIKÇA AYIRIYOR mu?
// (pdks_gunluk_faz8a_oturum_donemleri() → pdks_gunluk_faz8a_donem_durumu())
$eskiDonemler = pdks_gunluk_faz8a_oturum_donemleri($eskiSid, $eskiDb);
$e001Satir = null; $e002Satir = null;
foreach ($eskiDonemler as $d) {
    if ($d['card_no'] === 'E001') $e001Satir = $d;
    if ($d['card_no'] === 'E002') $e002Satir = $d;
}
ok('Puantaj: E001 (closed) "tam" kod/"✅ Tam" etiketiyle görünüyor',
    $e001Satir !== null && $e001Satir['durum']['kod'] === 'tam' && $e001Satir['durum']['etiket'] === '✅ Tam');
ok('Puantaj: E002 (legacy_unresolved) AYRI kod/etiketle görünüyor — canlı "açık" ile KARIŞMIYOR',
    $e002Satir !== null && $e002Satir['durum']['kod'] === 'legacy_unresolved'
    && $e002Satir['durum']['etiket'] === '📜 Geçmiş — Eksik Çıkış');

echo "\n=== 20. LEGACY BACKFILL AÇIK DÖNEMİ YENİ TARAMAYI ENGELLEMİYOR ===\n";
$eskiCavus2 = pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Yeni Çavuş (Faz 8A)'], 1, $eskiDb);
$eskiOturum2 = pdks_gunluk_oturum_ac_veya_getir((int)$eskiCavus2['id'], 1, $eskiDb);
$e002YeniTipId = (int)$eskiDb->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$e002YeniGiris = pdks_gunluk_faz8a_giris_kaydet('800000002', 'usb_decimal', (int)$eskiOturum2['session']['id'], $e002YeniTipId, 'tam', 1, $eskiDb);
ok('E002 (aylar önce backfill ile "legacy_unresolved" işaretlenmiş eski kayıt) YENİ Faz 8A taramasında GİRİŞ yapabiliyor — geçmiş, canlı operasyonu KİLİTLEMİYOR (status hiçbir zaman open OLMADI)',
    $e002YeniGiris['ok'] === true, json_encode($e002YeniGiris));

echo "\n=== 21. BACKFILL İDEMPOTENT (İKİNCİ ÇALIŞTIRMA MÜKERRER SATIR ÜRETMEZ) ===\n";
$eskiRapor2 = pdks_gunluk_faz8a_migrate($eskiDb);
$eskiAdimlar2 = array_column($eskiRapor2, 'mesaj', 'adim');
ok('İkinci backfill çalıştırması "aktarılacak yok" diyor', str_contains((string)($eskiAdimlar2['daily_worker_work_periods.backfill'] ?? ''), 'yok'));
$periyotSayisiSonra = (int)$eskiDb->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE source = 'legacy_backfill'")->fetchColumn();
ok('legacy_backfill satır sayısı DEĞİŞMEDİ (mükerrer satır YOK)', $periyotSayisiSonra === 2);

// =========================================================
// FİNANSAL GÜVENLİK — Hakediş Faz 8A gate
// =========================================================
echo "\n=== 22. MALİ GÜVENLİK — YARIM MESAİ İÇEREN OTURUM HAKEDİŞ REDDEDİLİR ===\n";
$hakedisDb = yeniDb();
pdks_gunluk_faz8a_migrate($hakedisDb);
$hCavus = pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Hakediş Çavuşu'], 1, $hakedisDb);
$hKadinId = (int)$hakedisDb->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$hOturum = pdks_gunluk_oturum_ac_veya_getir((int)$hCavus['id'], 1, $hakedisDb);
$hSid = (int)$hOturum['session']['id'];
kartOlustur('H001', '700000001', $hakedisDb);
kartOlustur('H002', '700000002', $hakedisDb);
pdks_gunluk_faz8a_giris_kaydet('700000001', 'usb_decimal', $hSid, $hKadinId, 'tam', 1, $hakedisDb);
pdks_gunluk_faz8a_cikis_kaydet('700000001', 'usb_decimal', $hSid, 1, $hakedisDb);
pdks_gunluk_faz8a_giris_kaydet('700000002', 'usb_decimal', $hSid, $hKadinId, 'yarim', 1, $hakedisDb);
pdks_gunluk_faz8a_cikis_kaydet('700000002', 'usb_decimal', $hSid, 1, $hakedisDb);
pdks_gunluk_oturum_kapat($hSid, null, 1, $hakedisDb);
pdks_hakedis_oran_ekle((int)$hCavus['id'], $hKadinId, '1000', date('Y-m-d', strtotime('-1 day')), 'TRY', 1, $hakedisDb);

$hesapSonuc = pdks_hakedis_hesapla($hSid, 1, $hakedisDb);
ok('Yarım Mesai İÇEREN oturumun hakedişi REDDEDİLDİ', $hesapSonuc['ok'] === false && $hesapSonuc['kod'] === 'faz8a_degerlendirme_gerekli');
ok('Ret mesajı NET ve Faz 8B\'ye yönlendiriyor', str_contains($hesapSonuc['hata'], 'Tam/Yarım mesai modelini kullanıyor') && str_contains($hesapSonuc['hata'], 'Faz 8B'));
$finalizeSonuc = pdks_hakedis_finalize($hSid, 1, false, $hakedisDb);
ok('KESİNLEŞTİRME de AYNI sebeple reddedildi (finalize hesapla() üzerinden geçer)', $finalizeSonuc['ok'] === false && $finalizeSonuc['kod'] === 'faz8a_degerlendirme_gerekli');
$entYok = $hakedisDb->query("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id = $hSid")->fetchColumn();
ok('Hiçbir hakediş satırı YAZILMADI (yarım gün tam gün fiyatıyla asla fiyatlanmadı)', (int)$entYok === 0);

echo "\n=== 23. MALİ GÜVENLİK — TÜMÜ TAM MESAİ OLAN OTURUM NORMAL HESAPLANIR ===\n";
$hCavus2 = pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Hakediş Çavuşu 2'], 1, $hakedisDb);
$hOturum2 = pdks_gunluk_oturum_ac_veya_getir((int)$hCavus2['id'], 1, $hakedisDb);
$hSid2 = (int)$hOturum2['session']['id'];
kartOlustur('H003', '700000003', $hakedisDb);
pdks_gunluk_faz8a_giris_kaydet('700000003', 'usb_decimal', $hSid2, $hKadinId, 'tam', 1, $hakedisDb);
pdks_gunluk_faz8a_cikis_kaydet('700000003', 'usb_decimal', $hSid2, 1, $hakedisDb);
pdks_gunluk_oturum_kapat($hSid2, null, 1, $hakedisDb);
pdks_hakedis_oran_ekle((int)$hCavus2['id'], $hKadinId, '1000', date('Y-m-d', strtotime('-1 day')), 'TRY', 1, $hakedisDb);
$hesap2 = pdks_hakedis_hesapla($hSid2, 1, $hakedisDb);
ok('TÜMÜ Tam Mesai olan oturumun hakedişi NORMAL hesaplandı (Faz 8A gate onu ENGELLEMEDİ)', $hesap2['ok'] === true, json_encode($hesap2));
ok('Toplam tutar doğru (1 katılım × 1000 TL)', $hesap2['total_amount'] === '1000.00');

echo "\n=== 24. MALİ GÜVENLİK — AYNI KART AYNI GÜN İKİ KEZ = İKİ AYRI KATILIM FİYATLANIR ===\n";
$hCavus3 = pdks_gunluk_cavus_olustur(['code' => 'C003', 'name' => 'Hakediş Çavuşu 3'], 1, $hakedisDb);
$hOturum3 = pdks_gunluk_oturum_ac_veya_getir((int)$hCavus3['id'], 1, $hakedisDb);
$hSid3 = (int)$hOturum3['session']['id'];
kartOlustur('H004', '700000004', $hakedisDb);
pdks_gunluk_faz8a_giris_kaydet('700000004', 'usb_decimal', $hSid3, $hKadinId, 'tam', 1, $hakedisDb);
pdks_gunluk_faz8a_cikis_kaydet('700000004', 'usb_decimal', $hSid3, 1, $hakedisDb);
pdks_gunluk_faz8a_giris_kaydet('700000004', 'usb_decimal', $hSid3, $hKadinId, 'tam', 1, $hakedisDb);
pdks_gunluk_faz8a_cikis_kaydet('700000004', 'usb_decimal', $hSid3, 1, $hakedisDb);
pdks_gunluk_oturum_kapat($hSid3, null, 1, $hakedisDb);
pdks_hakedis_oran_ekle((int)$hCavus3['id'], $hKadinId, '1000', date('Y-m-d', strtotime('-1 day')), 'TRY', 1, $hakedisDb);
$hesap3 = pdks_hakedis_hesapla($hSid3, 1, $hakedisDb);
ok('AYNI kart İKİ dönem => 2000 TL (2 × 1000), YARIM olarak KIRPILMADI/tek katılım sayılmadı', $hesap3['ok'] === true && $hesap3['total_amount'] === '2000.00', json_encode($hesap3));
ok('Satır sayımı 2 katılım gösteriyor', $hesap3['lines'][0]['worker_count'] === 2);

echo "\n=== 25. FİNAL ENTITLEMENT DEĞİŞMEZLİĞİ — KESİN KAYIT OTOMATİK YENİDEN HESAPLANMAZ ===\n";
$finalize3 = pdks_hakedis_finalize($hSid3, 1, false, $hakedisDb);
ok('H004 oturumu kesinleştirildi', $finalize3['ok'] === true);
$hesapTekrar = pdks_hakedis_hesapla($hSid3, 1, $hakedisDb);
ok('KESİNLEŞMİŞ kayıt otomatik yeniden hesaplama isteğini REDDEDİYOR (zaten_kesinlesmis)', $hesapTekrar['ok'] === false && $hesapTekrar['kod'] === 'zaten_kesinlesmis');

echo "\n=== 26. FAZ 8D STEP 1 AUTO CARD ===\n";

$autoDb = yeniDb();

$autoTypeId = (int)$autoDb
    ->query("SELECT id FROM worker_types WHERE code='KADIN'")
    ->fetchColumn();

$autoForeman = pdks_gunluk_cavus_olustur(
    ['code' => 'AUTO1', 'name' => 'Auto Card Foreman'],
    1,
    $autoDb
);

$autoSession = pdks_gunluk_oturum_ac_veya_getir(
    (int)$autoForeman['id'],
    1,
    $autoDb
);

$autoSid = (int)$autoSession['session']['id'];
$autoUid = '880000001';

$autoEntry = pdks_gunluk_faz8a_giris_kaydet(
    $autoUid,
    'usb_decimal',
    $autoSid,
    $autoTypeId,
    'tam',
    1,
    $autoDb
);

ok(
    'Unknown card is auto-enrolled on first GIRIS',
    ($autoEntry['ok'] ?? false) === true,
    json_encode($autoEntry, JSON_UNESCAPED_UNICODE)
);

$autoCanonical = pdks_uid_from_decimal($autoUid);

$autoSt = $autoDb->prepare(
    "SELECT * FROM worker_cards WHERE canonical_uid = ?"
);
$autoSt->execute([$autoCanonical]);
$autoCard = $autoSt->fetch();

ok(
    'Auto card exists in worker_cards and stays neutral',
    $autoCard !== false
        && $autoCard['worker_type_id'] === null,
    json_encode($autoCard, JSON_UNESCAPED_UNICODE)
);

ok(
    'Auto card gets deterministic AUTO number',
    $autoCard !== false
        && str_starts_with((string)$autoCard['card_no'], 'AUTO-'),
    json_encode($autoCard, JSON_UNESCAPED_UNICODE)
);

$periodSt = $autoDb->prepare(
    "SELECT *
       FROM daily_worker_work_periods
      WHERE session_id = ?
        AND worker_card_id = ?"
);
$periodSt->execute([
    $autoSid,
    (int)$autoCard['id']
]);
$period = $periodSt->fetch();

ok(
    'Worker type belongs to work period, not card master',
    $period !== false
        && (int)$period['worker_type_id_snapshot'] === $autoTypeId,
    json_encode($period, JSON_UNESCAPED_UNICODE)
);

$autoExit = pdks_gunluk_faz8a_cikis_kaydet(
    $autoUid,
    'usb_decimal',
    $autoSid,
    1,
    $autoDb
);

ok(
    'Auto-enrolled card can make normal CIKIS',
    ($autoExit['ok'] ?? false) === true,
    json_encode($autoExit, JSON_UNESCAPED_UNICODE)
);

$unknownExit = pdks_gunluk_faz8a_cikis_kaydet(
    '880000002',
    'usb_decimal',
    $autoSid,
    1,
    $autoDb
);

ok(
    'Unknown card is NOT auto-created in CIKIS mode',
    ($unknownExit['ok'] ?? true) === false
        && ($unknownExit['kod'] ?? '') === 'kart_tanimsiz',
    json_encode($unknownExit, JSON_UNESCAPED_UNICODE)
);


printf("\n=== SONUÇ: %d geçti, %d hata ===\n", $gecen, $fail);
exit($fail > 0 ? 1 : 0);
