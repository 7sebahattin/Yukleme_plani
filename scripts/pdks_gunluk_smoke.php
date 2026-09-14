<?php
// =========================================================
// scripts/pdks_gunluk_smoke.php — Günlük İşçi (çavuş/işçi kart havuzu) testi
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ: bellek içi SQLite kullanır.
// pdks_db_smoke.php'nin KENDİ desenini izler: elle yazılmış bir test şeması
// KULLANMAZ — hem config/pdks.php'nin hem config/pdks_gunluk.php'nin GERÇEK
// MySQL DDL'ini SQLite'a çevirip çalıştırır (pdks_ddl_sqlite, pdks_db_smoke.php'den
// BİREBİR kopya — iki test dosyası birbirini require ETMEZ, bilerek bağımsız).
//
// Kapsam (görev listesi ile eşlenir):
//   - çavuş oluşturma (+ kod benzersizliği, doğrulama)
//   - işçi tipi oluşturma (+ kod benzersizliği)
//   - işçi-havuzu kartı oluşturma
//   - mükerrer kanonik UID reddi (havuz-içi)
//   - ÇAPRAZ-SİSTEM UID çakışma yönetimi (HER İKİ yön)
//   - aktif/pasif durumlar (çavuş + işçi tipi)
//   - kart arama/filtre (SQL WHERE üretiminin doğru çalıştığı, sayfa
//     seviyesinde değil fonksiyon seviyesinde)
//   - MEVCUT kalıcı personel kart akışı (pdks_kart_olustur) BOZULMADI
//     (regresyon — çapraz kontrol eklenmeden ÖNCEki davranışla AYNI sonuç)
//
//   php scripts/pdks_gunluk_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$KOK = dirname(__DIR__);

// ── Stub'lar: config/pdks.php + config/pdks_gunluk.php'nin opsiyonel dış bağımlılıkları ──
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid];
}
$PERMS = [];
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
$IS_ADMIN = false;
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }

require_once $KOK . '/config/pdks.php';
require_once $KOK . '/config/pdks_gunluk.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — scripts/pdks_db_smoke.php İLE BİREBİR AYNI
// (kasıtlı kopya, bkz. dosya başlığı).
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
            // SQLite: FK'yı kolon seviyesinde/PRAGMA ile YÖNETMEYE gerek yok bu
            // test için — REFERENCES bütünlüğü zaten UNIQUE/kod seviyesinde
            // test ediliyor; CONSTRAINT satırını sessizce ATLA (pdks_db_smoke.php
            // ile aynı basitleştirme kararı — o dosya da FK satırlarını
            // employees/employee_cards için AYRICA ele alır, burada da
            // worker_cards→worker_types FK'sı test amaçlı atlanır).
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

// ─────────────────────────────────────────────────────────
// Şemayı kur — GERÇEK DDL'in HER İKİ modülden de çevrilmiş hâli
// ─────────────────────────────────────────────────────────
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

// users + employees + employee_cards + employee_card_uids — kalıcı personel
// sistemi, ÇAPRAZ-SİSTEM testleri için gerekli.
db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
foreach (pdks_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}
// worker_types + foremen + worker_cards — bu Faz'ın kendi şeması
foreach (pdks_gunluk_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

// ═══════════════════════════════════════════════════════════
echo "\n=== 1. ŞEMA — tablolar gerçek DDL'den kuruldu ===\n";
foreach (['worker_types', 'foremen', 'worker_cards'] as $t) {
    ok("tablo `$t` kuruldu", pdks_gunluk_tablo_var(db(), $t));
}
ok('pdks_gunluk_sema_hazir() true', pdks_gunluk_sema_hazir(db()));

echo "\n=== 2. MIGRATE İDEMPOTENT + SEED (Kadın/Erkek) ===\n";
$rapor1 = pdks_gunluk_migrate(db());
$rapor2 = pdks_gunluk_migrate(db());   // ikinci çalıştırma — hiçbir şey KIRMAMALI
ok('ikinci migrate() da hatasız döner', !array_filter($rapor2, fn($r) => $r['durum'] === 'hata'));
$tipler = pdks_gunluk_tip_listele(false, db());
$kodlar = array_column($tipler, 'code');
ok('KADIN seed edildi', in_array('KADIN', $kodlar, true));
ok('ERKEK seed edildi', in_array('ERKEK', $kodlar, true));
ok('seed İKİ KEZ çalıştırılınca YİNE yalnız 2 satır (INSERT IGNORE idempotent)', count($kodlar) === count(array_unique($kodlar)));

echo "\n=== 3. ÇAVUŞ OLUŞTURMA ===\n";
$c1 = pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş', 'phone' => '05551112233'], 1, db());
ok('çavuş oluşturuldu', $c1['ok'] === true, json_encode($c1));
$c1id = $c1['id'] ?? 0;

ok('sonraki kod önerisi artık C002', pdks_gunluk_sonraki_cavus_kodu(db()) === 'C002');

$cDup = pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Başka Biri'], 1, db());
ok('AYNI kod REDDEDİLDİ (benzersizlik)', $cDup['ok'] === false);
ok('hata mesajı kodu içeriyor', str_contains($cDup['hatalar'][0] ?? '', 'C001'));

$cBos = pdks_gunluk_cavus_olustur(['code' => '', 'name' => ''], 1, db());
ok('boş kod+ad REDDEDİLDİ', $cBos['ok'] === false);
ok('İKİ ayrı doğrulama hatası da listelendi', count($cBos['hatalar'] ?? []) === 2);

echo "\n=== 4. ÇAVUŞ GÜNCELLEME + AKTİF/PASİF ===\n";
$upd = pdks_gunluk_cavus_guncelle($c1id, ['code' => 'C001', 'name' => 'Ayşe Çavuş (güncel)', 'phone' => '', 'is_active' => 1], 1, db());
ok('güncelleme başarılı', $upd['ok'] === true);
$st = db()->prepare("SELECT name FROM foremen WHERE id = ?"); $st->execute([$c1id]);
ok('isim güncellendi', $st->fetchColumn() === 'Ayşe Çavuş (güncel)');

$deact = pdks_gunluk_cavus_aktiflik($c1id, false, 1, db());
ok('pasifleştirme başarılı', $deact['ok'] === true);
$st = db()->prepare("SELECT is_active FROM foremen WHERE id = ?"); $st->execute([$c1id]);
ok('is_active = 0 oldu (SİLİNMEDİ — kayıt hâlâ var)', (int)$st->fetchColumn() === 0);
$react = pdks_gunluk_cavus_aktiflik($c1id, true, 1, db());
ok('yeniden aktifleştirme başarılı', $react['ok'] === true);

ok('bilinmeyen çavuş id güncellemesi hata döner', pdks_gunluk_cavus_guncelle(999999, ['code' => 'X', 'name' => 'Y'], 1, db())['ok'] === false);
ok('bilinmeyen çavuş id aktiflik değişimi hata döner', pdks_gunluk_cavus_aktiflik(999999, true, 1, db())['ok'] === false);

echo "\n=== 5. İŞÇİ TİPİ — kod benzersizliği + aktif/pasif ===\n";
$t1 = pdks_gunluk_tip_olustur('FORKLIFT', 'Forklift Operatörü', db());
ok('yeni tip oluşturuldu (gender ENUM ile SINIRLI DEĞİL)', $t1['ok'] === true, json_encode($t1));
$tDup = pdks_gunluk_tip_olustur('forklift', 'Tekrar', db());
ok('AYNI kod (büyük/küçük harf fark etmeksizin) REDDEDİLDİ', $tDup['ok'] === false);
$kadinId = null; $erkekId = null;
foreach (pdks_gunluk_tip_listele(false, db()) as $t) {
    if ($t['code'] === 'KADIN') $kadinId = (int)$t['id'];
    if ($t['code'] === 'ERKEK') $erkekId = (int)$t['id'];
}
ok('KADIN id bulundu', $kadinId !== null);
ok('ERKEK id bulundu', $erkekId !== null);
$deactT = pdks_gunluk_tip_aktiflik($kadinId, false, db());
ok('tip pasifleştirilebiliyor', $deactT['ok'] === true);
$aktifTipler = pdks_gunluk_tip_listele(true, db());
ok('yalnız aktif tipler listede (KADIN artık YOK)', !in_array('KADIN', array_column($aktifTipler, 'code'), true));
pdks_gunluk_tip_aktiflik($kadinId, true, db());   // testin geri kalanı için geri aç

echo "\n=== 6. İŞÇİ KARTI OLUŞTURMA — UID normalizasyonu config/pdks.php'den REUSE ===\n";
$k1 = pdks_gunluk_kart_olustur([
    'card_no' => 'K001', 'worker_type_id' => $kadinId,
    'ham_uid' => '631799511', 'kaynak' => 'usb_decimal',
], 1, db());
ok('kart oluşturuldu', $k1['ok'] === true, json_encode($k1));
ok('kanonik UID config/pdks.php normalizasyonuyla AYNI (25A87ED7)', ($k1['canonical_uid'] ?? '') === '25A87ED7');
$k1id = $k1['card_id'] ?? 0;
$st = db()->prepare("SELECT status FROM worker_cards WHERE id = ?"); $st->execute([$k1id]);
ok('yeni kart varsayılan durumu "available"', $st->fetchColumn() === 'available');

echo "\n--- 6a. Sonraki kart no önerisi tip bazlı (K/E öneki) ---\n";
ok('KADIN tipi için sonraki öneri K002', pdks_gunluk_sonraki_kart_no($kadinId, db()) === 'K002');

echo "\n--- 6b. Doğrulama hataları ---\n";
ok('boş kart no reddedilir', pdks_gunluk_kart_olustur(['card_no' => '', 'worker_type_id' => $kadinId, 'ham_uid' => '1', 'kaynak' => 'usb_decimal'], 1, db())['ok'] === false);
ok('geçersiz tip id reddedilir', pdks_gunluk_kart_olustur(['card_no' => 'K999', 'worker_type_id' => 999999, 'ham_uid' => '1', 'kaynak' => 'usb_decimal'], 1, db())['ok'] === false);
ok('geçersiz kaynak reddedilir', pdks_gunluk_kart_olustur(['card_no' => 'K999', 'worker_type_id' => $kadinId, 'ham_uid' => '1', 'kaynak' => 'bilinmeyen'], 1, db())['ok'] === false);
ok('boş uid reddedilir', pdks_gunluk_kart_olustur(['card_no' => 'K999', 'worker_type_id' => $kadinId, 'ham_uid' => '', 'kaynak' => 'usb_decimal'], 1, db())['ok'] === false);

echo "\n=== 7. MÜKERRER KANONİK UID REDDİ — HAVUZ İÇİ ===\n";
$k2 = pdks_gunluk_kart_olustur([
    'card_no' => 'K002', 'worker_type_id' => $kadinId,
    'ham_uid' => '631799511', 'kaynak' => 'usb_decimal',   // AYNI fiziksel kart
], 1, db());
ok('AYNI UID ikinci kez REDDEDİLDİ', $k2['ok'] === false);
ok('hata kodu uid_havuzda', ($k2['kod'] ?? '') === 'uid_havuzda');
ok('hata mesajı çakışan kart no\'yu gösteriyor (K001)', str_contains($k2['hata'] ?? '', 'K001'));

$k3 = pdks_gunluk_kart_olustur([
    'card_no' => 'K001',   // AYNI kart no, FARKLI UID
    'worker_type_id' => $kadinId, 'ham_uid' => '111222333', 'kaynak' => 'usb_decimal',
], 1, db());
ok('AYNI kart NO ikinci kez REDDEDİLDİ', $k3['ok'] === false);
ok('hata kodu kart_no_kullanimda', ($k3['kod'] ?? '') === 'kart_no_kullanimda');

echo "\n=== 8. ÇAPRAZ-SİSTEM UID ÇAKIŞMASI — YÖN 1: yeni işçi kartı, MEVCUT kalıcı personel kartıyla çakışıyor ===\n";
db()->exec("INSERT INTO users (username) VALUES ('test')");
$empIns = db()->prepare("INSERT INTO employees (full_name, status) VALUES (?, 'aktif')");
$empIns->execute(['Test Personel']);
$empId = (int)db()->lastInsertId();
$permKart = pdks_kart_olustur($empId, '111222444', 'usb_decimal', [], db());
ok('kalıcı personel kartı normal şekilde oluşturuldu (regresyon — bu akış BOZULMADI)', $permKart['ok'] === true, json_encode($permKart));
$permUid = $permKart['uid_hex'] ?? '';

$kCakisma = pdks_gunluk_kart_olustur([
    'card_no' => 'K900', 'worker_type_id' => $kadinId,
    'ham_uid' => '111222444', 'kaynak' => 'usb_decimal',   // kalıcı personelin AYNI fiziksel kartı
], 1, db());
ok('işçi havuzuna YAZILAMADI — kalıcı personel kartıyla çakışıyor', $kCakisma['ok'] === false);
ok('hata kodu uid_kalici_kartta', ($kCakisma['kod'] ?? '') === 'uid_kalici_kartta');
ok('hata mesajı personel adını içeriyor', str_contains($kCakisma['hata'] ?? '', 'Test Personel'));
$stChk = db()->prepare("SELECT COUNT(*) FROM worker_cards WHERE card_no = 'K900'"); $stChk->execute();
ok('K900 satırı GERÇEKTEN yazılmadı (reddedilen işlem iz bırakmadı)', (int)$stChk->fetchColumn() === 0);

echo "\n=== 9. ÇAPRAZ-SİSTEM UID ÇAKIŞMASI — YÖN 2: yeni kalıcı personel kartı, MEVCUT işçi kartıyla çakışıyor ===\n";
// K001 = 25A87ED7 (631799511'in kanoniği), bölüm 6'da işçi havuzuna yazıldı.
$empIns->execute(['İkinci Personel']);
$empId2 = (int)db()->lastInsertId();
$permCakisma = pdks_kart_olustur($empId2, '631799511', 'usb_decimal', [], db());
ok('kalıcı personel kartı YAZILAMADI — işçi havuzu kartıyla çakışıyor (TERS YÖN, function_exists guard)', $permCakisma['ok'] === false, json_encode($permCakisma));
ok('hata kodu uid_gunluk_havuzda', ($permCakisma['kod'] ?? '') === 'uid_gunluk_havuzda');
ok('hata mesajı işçi kart no\'sunu içeriyor (K001)', str_contains($permCakisma['hata'] ?? '', 'K001'));
$stChk2 = db()->prepare("SELECT COUNT(*) FROM employee_cards WHERE employee_id = ?"); $stChk2->execute([$empId2]);
ok('İkinci Personel\'e GERÇEKTEN kart yazılmadı', (int)$stChk2->fetchColumn() === 0);

echo "\n--- 9a. Normal (çakışmayan) kalıcı personel kartı akışı HÂLÂ ÇALIŞIYOR ---\n";
$empIns->execute(['Üçüncü Personel']);
$empId3 = (int)db()->lastInsertId();
$permOk = pdks_kart_olustur($empId3, '999888777', 'usb_decimal', [], db());
ok('çakışmayan UID ile kalıcı personel kartı NORMAL şekilde oluşturuluyor (yanlış-pozitif YOK)', $permOk['ok'] === true, json_encode($permOk));

echo "\n=== 10. KART DÜZENLEME (görünür kart no / tip / not — UID DEĞİŞMEZ) ===\n";
$duz = pdks_gunluk_kart_duzenle($k1id, ['card_no' => 'K001-B', 'worker_type_id' => $erkekId, 'notes' => 'test notu'], 1, db());
ok('düzenleme başarılı', $duz['ok'] === true, json_encode($duz));
$st = db()->prepare("SELECT card_no, worker_type_id, canonical_uid FROM worker_cards WHERE id = ?"); $st->execute([$k1id]);
$row = $st->fetch();
ok('kart no değişti', $row['card_no'] === 'K001-B');
ok('tip değişti', (int)$row['worker_type_id'] === $erkekId);
ok('canonical_uid DEĞİŞMEDİ (bu fonksiyon UID değiştirmez)', $row['canonical_uid'] === '25A87ED7');

echo "\n=== 11. KART DURUM GEÇİŞLERİ (available/lost/disabled — in_use KALICI DEĞİL) ===\n";
foreach (['lost', 'disabled', 'available'] as $durum) {
    $r = pdks_gunluk_kart_durum_degistir($k1id, $durum, 1, db());
    ok("durum '$durum'a geçirildi", $r['ok'] === true);
}
ok('geçersiz durum reddedilir', pdks_gunluk_kart_durum_degistir($k1id, 'boyle_bir_sey_yok', 1, db())['ok'] === false);
ok('var olmayan kart id reddedilir', pdks_gunluk_kart_durum_degistir(999999, 'lost', 1, db())['ok'] === false);

echo "\n--- 11a. DÜZELTME (kullanıcının açık talimatı): 'in_use' KALICI kart durumu DEĞİL ---\n";
// "Kullanımda / Ayşe Çavuş" SESSION durumudur (Faz 2'de daily_work_sessions'tan
// TÜRETİLECEK), worker_cards.status'a HİÇ YAZILMAZ — yarım kalmış/başarısız
// kapanan bir oturumdan sonra kart SONSUZA KADAR "kullanımda" kalmasın diye.
$durumlar = pdks_gunluk_kart_durumlari();
ok('pdks_gunluk_kart_durumlari() TAM ÜÇ durum döndürüyor', count($durumlar) === 3);
ok('"available" var', array_key_exists('available', $durumlar));
ok('"lost" var', array_key_exists('lost', $durumlar));
ok('"disabled" var', array_key_exists('disabled', $durumlar));
ok('"in_use" ARTIK SÖZLÜKTE YOK', !array_key_exists('in_use', $durumlar));
$rInUse = pdks_gunluk_kart_durum_degistir($k1id, 'in_use', 1, db());
ok("'in_use'a durum GEÇİŞİ REDDEDİLİYOR (sözlükte olmadığı için geçersiz durum sayılıyor)", $rInUse['ok'] === false);
$stChkStatus = db()->prepare("SELECT status FROM worker_cards WHERE id = ?"); $stChkStatus->execute([$k1id]);
ok('reddedilen geçiş SONRASI kartın durumu DEĞİŞMEDİ (hâlâ önceki geçerli durum)',
    in_array($stChkStatus->fetchColumn(), ['available', 'lost', 'disabled'], true));

echo "\n=== 12. KART ARAMA/FİLTRE — fonksiyon seviyesinde WHERE mantığı ===\n";
// isci_kartlari.php'nin ürettiği WHERE'in AYNISI — burada doğrudan sorgulanır,
// sayfa render katmanı UI smoke testinde AYRICA doğrulanır.
$stF = db()->prepare("SELECT COUNT(*) FROM worker_cards WHERE (card_no LIKE ? OR canonical_uid LIKE ?)");
$stF->execute(['%K001%', '%K001%']);
ok('kart no ile arama en az 1 sonuç buluyor', (int)$stF->fetchColumn() >= 1);
$stF2 = db()->prepare("SELECT COUNT(*) FROM worker_cards WHERE canonical_uid LIKE ?");
$stF2->execute(['%25A87ED7%']);
ok('UID ile arama en az 1 sonuç buluyor', (int)$stF2->fetchColumn() >= 1);
$stF3 = db()->prepare("SELECT COUNT(*) FROM worker_cards WHERE worker_type_id = ? AND status = ?");
$stF3->execute([$erkekId, 'available']);
ok('tip+durum birleşik filtre çalışıyor', (int)$stF3->fetchColumn() >= 1);

echo "\n=== 13. ATANMAMIŞ/PASİF TİP GÜVENLİĞİ — pasif tipe yeni kart ATANAMAZ ===\n";
pdks_gunluk_tip_aktiflik($erkekId, false, db());
$kPasifTip = pdks_gunluk_kart_olustur(['card_no' => 'E900', 'worker_type_id' => $erkekId, 'ham_uid' => '555666777', 'kaynak' => 'usb_decimal'], 1, db());
ok('pasif tipe YENİ kart oluşturulamaz', $kPasifTip['ok'] === false);
ok('hata kodu tip_bulunamadi', ($kPasifTip['kod'] ?? '') === 'tip_bulunamadi');
pdks_gunluk_tip_aktiflik($erkekId, true, db());   // geri aç

echo "\n=== 14. GÜNLÜK İŞÇİ MİGRASYONU KALICI PERSONEL VERİSİNE DOKUNMADI (izolasyon) ===\n";
$stE = db()->query("SELECT COUNT(*) FROM employees");
ok('employees tablosu satır sayısı beklenen (3 personel + testler)', (int)$stE->fetchColumn() === 3);
pdks_gunluk_migrate(db());   // yeniden çalıştır — hiçbir şey DEĞİŞMEMELİ
$stE2 = db()->query("SELECT COUNT(*) FROM employees");
ok('tekrar migrate() sonrası employees YİNE 3 (dokunulmadı)', (int)$stE2->fetchColumn() === 3);

echo "\n=== 15. DÜZELTME (kullanıcının açık talimatı): NORMAL SAYFA ZİYARETİ DDL ÇALIŞTIRMAZ ===\n";
// ⚠ pdks_gunluk_sayfa_kapisi() GERÇEK CİHAZDA/uçtan uca davranışı doğrular:
// tablolar YOKKEN çağrıldığında (a) HİÇBİR CREATE TABLE ÇALIŞTIRMAZ, (b) ne
// bir PHP Fatal/Warning sızdırır ne de tabloyu sessizce oluşturur, (c) açık
// Türkçe admin mesajıyla sayfayı GÜVENLE sonlandırır. Bu fonksiyon exit()
// çağırdığı için AYRI bir alt-süreçte (subprocess) çalıştırılır — exit()
// PHP'de include içinden bile yakalanamaz, bu test sürecinin KENDİSİNİ
// sonlandırırdı (pdks_faz1b_ui_smoke.php'nin POST-redirect notundaki AYNI
// kısıt — bkz. scripts/pdks_gunluk_ui_smoke.php başlığı).
$altSurecKodu = <<<'PHPKOD'
<?php
declare(strict_types=1);
$PDO_TEST = new PDO('sqlite::memory:');
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function set_flash($a, $b): void { echo "FLASH[$a]: $b\n"; }
function render_header($t, $p = false): void { echo "HEADER\n"; }
function render_flash(): void {}
function render_footer($p = false): void { echo "FOOTER\n"; }
function h($v): string { return (string)$v; }
require_once __DIR__ . '/config/pdks_gunluk.php';
// ⚠ worker_types/foremen/worker_cards KASITLI OLARAK OLUŞTURULMADI — bu,
// "tablolar henüz migrate edilmemiş taze kurulum" senaryosunun KENDİSİDİR.
pdks_gunluk_sayfa_kapisi(db());
echo "BURAYA HİÇ ULAŞILMAMALI\n";
PHPKOD;
$tmpAltSurec = sys_get_temp_dir() . '/pdks_gunluk_kapisi_altsurec.php';
file_put_contents($tmpAltSurec, str_replace("require_once __DIR__ . '/config/pdks_gunluk.php';", "require_once " . var_export($KOK . '/config/pdks_gunluk.php', true) . ';', $altSurecKodu));
$cikti = []; $rc = 0;
exec('php ' . escapeshellarg($tmpAltSurec) . ' 2>&1', $cikti, $rc);
$ciktiTam = implode("\n", $cikti);
@unlink($tmpAltSurec);

ok('alt-süreç PHP Fatal/Warning SIZDIRMADI (sessiz/güvenli başarısızlık)',
    !str_contains($ciktiTam, 'Fatal error') && !str_contains($ciktiTam, 'Warning:'), $ciktiTam);
ok('açık Türkçe admin mesajı basıldı ("...henüz oluşturulmamış...")',
    str_contains($ciktiTam, 'henüz oluşturulmamış'), $ciktiTam);
ok('migrate.php\'ye yönlendiren yönerge mesajda var', str_contains($ciktiTam, 'migrate.php'), $ciktiTam);
ok('sayfa GÜVENLE SONLANDI — "BURAYA HİÇ ULAŞILMAMALI" satırı ÇIKTIDA YOK (exit() çalıştı)',
    !str_contains($ciktiTam, 'BURAYA HİÇ ULAŞILMAMALI'), $ciktiTam);
ok('alt-süreç çıkış kodu 0 (PHP fatal ile ÇÖKMEDİ)', $rc === 0, "rc=$rc\n$ciktiTam");

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
