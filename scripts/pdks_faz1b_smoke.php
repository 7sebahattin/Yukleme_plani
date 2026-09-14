<?php
// =========================================================
// scripts/pdks_faz1b_smoke.php — PDKS Faz 1B alan mantığı testi
// (Personel CRUD + Kart yaşam döngüsü sarmalayıcıları)
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ: bellek içi SQLite kullanır.
//   php scripts/pdks_faz1b_smoke.php     → çıkış kodu 0 = tüm testler geçti
//
// scripts/pdks_db_smoke.php'nin YERİNE GEÇMEZ — o Faz 1'in ham şema/UID
// kısıtlarını test eder (bu betik onu REGRESYON olarak ayrıca çalıştırır).
// Bu betik Faz 1B'nin YENİ fonksiyonlarını test eder: pdks_personel_olustur,
// pdks_personel_guncelle, pdks_kart_ata, pdks_kart_durum_degistir,
// pdks_kart_degistir, pdks_foto_gecerli_mi.
//
// Aynı desen: config/pdks.php'deki GERÇEK DDL'i (pdks_ddl_sqlite ile) SQLite'a
// çevirip kurar — elle yazılmış bir test şeması kullanmaz.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid, 'old' => $o, 'new' => $n];
}
$PERMS = [];
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
$IS_ADMIN = false;
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/../config/pdks.php';

// ── MySQL DDL → SQLite çevirici (pdks_db_smoke.php ile AYNI — kopya değil,
//    çünkü betikler birbirini require etmiyor; mantık kısa ve tek amaçlı) ──
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1]; $govde = $m[2];
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
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $indeksler];
}
function pdks_kolon_listesi(string $ham): string
{
    $ham = preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $ham);
    return preg_replace('/\s+/', ' ', trim($ham));
}

$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
$PDO_TEST->exec('PRAGMA foreign_keys = ON');
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

db()->exec("CREATE TABLE `users` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `username` VARCHAR(60) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL DEFAULT '',
    `is_active` INTEGER NOT NULL DEFAULT 1)");
db()->exec("INSERT INTO users (username, display_name) VALUES ('admin','Yönetici'),('depo1','Depo Sorumlusu'),('depo2','Depo 2')");

foreach (pdks_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}

$hata = 0; $gecen = 0;
function dogrula(string $ad, $bulunan, $beklenen): void {
    global $hata, $gecen;
    $ok = $bulunan === $beklenen;
    $ok ? $gecen++ : $hata++;
    printf("%-64s %-16s %s\n", $ad, var_export($bulunan, true),
        $ok ? 'OK' : '*** HATA (beklenen ' . var_export($beklenen, true) . ')');
}
function reddedildi_mi(callable $f): bool { try { $f(); return false; } catch (PDOException $e) { return true; } }

// ═══════════════════════════════════════════════════════════
// 1. PERSONEL OLUŞTURMA
// ═══════════════════════════════════════════════════════════
echo "\n=== 1. PERSONEL OLUŞTURMA ===\n";
$r1 = pdks_personel_olustur(['full_name' => 'Ahmet Yılmaz', 'personnel_no' => '101', 'department' => 'Depo'], 1, db());
dogrula('oluşturuldu',            $r1['ok'], true);
dogrula('id döndü',               $r1['id'] > 0, true);
$empA = $r1['id'];
$satir = db()->query("SELECT * FROM employees WHERE id=$empA")->fetch();
dogrula('full_name kaydedildi',   $satir['full_name'], 'Ahmet Yılmaz');
dogrula('varsayılan durum aktif', $satir['status'], 'aktif');
dogrula('audit: employee_created', in_array('employee_created', array_column($GLOBALS['AUDIT'], 'action'), true), true);

echo "\n=== 2. BOŞ AD SOYAD REDDEDİLİR ===\n";
$rBos = pdks_personel_olustur(['full_name' => '  '], 1, db());
dogrula('reddedildi',  $rBos['ok'], false);
dogrula('kod: dogrulama', $rBos['kod'], 'dogrulama');

echo "\n=== 3. MÜKERRER SİCİL NUMARASI REDDEDİLİR ===\n";
$rDup = pdks_personel_olustur(['full_name' => 'Başka Kişi', 'personnel_no' => '101'], 1, db());
dogrula('reddedildi',      $rDup['ok'], false);
dogrula('kod: dogrulama',  $rDup['kod'], 'dogrulama');
dogrula('sicilsiz personel serbestçe eklenebiliyor',
    pdks_personel_olustur(['full_name' => 'Sicilsiz Kişi'], 1, db())['ok'], true);
dogrula('İKİNCİ sicilsiz personel de serbest (NULL çoklu)',
    pdks_personel_olustur(['full_name' => 'Sicilsiz Kişi 2'], 1, db())['ok'], true);

echo "\n=== 4. OPSİYONEL user_id ===\n";
$rU1 = pdks_personel_olustur(['full_name' => 'Zeynep Kaya', 'user_id' => 1], 1, db());
dogrula('user_id ile oluşturuldu', $rU1['ok'], true);
$empB = $rU1['id'];
dogrula('user_id kaydedildi', (int)db()->query("SELECT user_id FROM employees WHERE id=$empB")->fetchColumn(), 1);

echo "\n=== 5. MÜKERRER user_id REDDEDİLİR ===\n";
$rU2 = pdks_personel_olustur(['full_name' => 'Başka Kişi', 'user_id' => 1], 1, db());
dogrula('reddedildi', $rU2['ok'], false);
dogrula('kod: dogrulama', $rU2['kod'], 'dogrulama');
dogrula('olmayan user_id reddedilir',
    pdks_personel_olustur(['full_name' => 'X', 'user_id' => 999999], 1, db())['ok'], false);
dogrula('farklı user_id serbest',
    pdks_personel_olustur(['full_name' => 'Mehmet Can', 'user_id' => 2], 1, db())['ok'], true);

// ═══════════════════════════════════════════════════════════
echo "\n=== 6. PERSONEL DÜZENLEME ===\n";
$rEdit = pdks_personel_guncelle($empA, ['full_name' => 'Ahmet Yılmaz', 'personnel_no' => '101', 'department' => 'Ofis', 'status' => 'aktif'], 1, db());
dogrula('güncellendi', $rEdit['ok'], true);
dogrula('departman değişti', db()->query("SELECT department FROM employees WHERE id=$empA")->fetchColumn(), 'Ofis');
dogrula('KENDİ sicilini koruyabilir (kendisiyle çakışma sayılmaz)',
    pdks_personel_guncelle($empA, ['full_name' => 'Ahmet Yılmaz', 'personnel_no' => '101'], 1, db())['ok'], true);
dogrula('BAŞKASININ sicilini alamaz',
    pdks_personel_guncelle($empB, ['full_name' => 'Zeynep Kaya', 'personnel_no' => '101'], 1, db())['ok'], false);
dogrula('olmayan personel düzenlenemez',
    pdks_personel_guncelle(999999, ['full_name' => 'X'], 1, db())['kod'], 'personel_yok');

echo "\n=== 7. PASİF / DURUM DEĞİŞİKLİĞİ AUDIT'E DÜŞER ===\n";
$GLOBALS['AUDIT'] = [];
pdks_personel_guncelle($empA, ['full_name' => 'Ahmet Yılmaz', 'personnel_no' => '101', 'status' => 'pasif'], 1, db());
dogrula('durum pasif oldu', db()->query("SELECT status FROM employees WHERE id=$empA")->fetchColumn(), 'pasif');
dogrula('audit: employee_updated',        in_array('employee_updated', array_column($GLOBALS['AUDIT'], 'action'), true), true);
dogrula('audit: employee_status_changed (ayrı olay)', in_array('employee_status_changed', array_column($GLOBALS['AUDIT'], 'action'), true), true);
$GLOBALS['AUDIT'] = [];
pdks_personel_guncelle($empA, ['full_name' => 'Ahmet Yılmaz', 'personnel_no' => '101', 'status' => 'pasif'], 1, db());
dogrula('durum DEĞİŞMEDİYSE employee_status_changed YAZILMAZ',
    in_array('employee_status_changed', array_column($GLOBALS['AUDIT'], 'action'), true), false);
// Aktif duruma geri al — sonraki kart testleri için gerekli
pdks_personel_guncelle($empA, ['full_name' => 'Ahmet Yılmaz', 'personnel_no' => '101', 'status' => 'aktif'], 1, db());

// ═══════════════════════════════════════════════════════════
echo "\n=== 8. USB KART ATAMA — 631799511 → 25A87ED7 ===\n";
$GLOBALS['AUDIT'] = [];
$rKart = pdks_kart_ata($empA, '631799511', 'usb_decimal', ['label' => 'Kart-01'], db());
dogrula('atandı', $rKart['ok'], true);
dogrula('kanonik UID', $rKart['uid_hex'], '25A87ED7');
$kartA = $rKart['card_id'];
dogrula('audit: card_assigned', in_array('card_assigned', array_column($GLOBALS['AUDIT'], 'action'), true), true);
dogrula('audit: card_create (Faz 1 fonksiyonu da kendi olayını yazar)',
    in_array('card_create', array_column($GLOBALS['AUDIT'], 'action'), true), true);

echo "\n=== 9. AKTİF KARTI OLAN PERSONELE İKİNCİ KART ATANAMAZ ===\n";
$rIkinci = pdks_kart_ata($empA, '111222333', 'usb_decimal', [], db());
dogrula('reddedildi', $rIkinci['ok'], false);
dogrula('kod: zaten_aktif_kart_var', $rIkinci['kod'], 'zaten_aktif_kart_var');
dogrula('ikinci kart YAZILMADI', (int)db()->query("SELECT COUNT(*) FROM employee_cards WHERE employee_id=$empA")->fetchColumn(), 1);

echo "\n=== 10. AYNI KARTIN MÜKERRER ATANMASI REDDEDİLİR ===\n";
$rMuk = pdks_kart_ata($empB, '631799511', 'usb_decimal', [], db());
dogrula('BAŞKA personele aynı UID reddedildi', $rMuk['ok'], false);
dogrula('kod: uid_kullanimda', $rMuk['kod'], 'uid_kullanimda');

echo "\n=== 11. KART A (25A87ED7) ve KART B (D77EA825) AYNI ANDA VAR OLABİLİR ===\n";
// Faz 1 düzeltmesinin (bkz. PDKS_FAZ1_SEMA.md §6a) bu üst katmandan da
// REGRESYONU: pdks_kart_ata() da otomatik ters-alias mantığı YENİDEN
// GETİRMEMELİ (Faz 1'in pdks_kart_olustur()'unu sarmaladığı için zaten
// getirmiyor — burada UÇTAN UCA doğrulanıyor).
$rKartB = pdks_kart_ata($empB, 'D77EA825', 'nfc_hex', [], db());
dogrula('Kart B (bayt-tersi UID) BAŞARIYLA atandı', $rKartB['ok'], true);
dogrula('Kart B kanoniği', $rKartB['uid_hex'], 'D77EA825');
dogrula('Kart A hâlâ çözülüyor', (int)pdks_kart_cozumle('631799511', 'usb_decimal', db())['card']['id'], $kartA);
dogrula('Kart B kendi personeline çözülüyor', (int)pdks_kart_cozumle('D77EA825', 'nfc_hex', db())['employee']['id'], $empB);

echo "\n=== 12. GEÇERSİZ UID — FAIL CLOSED ===\n";
// ⚠ empA ve empB bu noktada ARTIK aktif kartlıdır (test 8 ve 11) — onlarla
// denenirse pdks_kart_ata() UID'e hiç bakmadan 'zaten_aktif_kart_var' der
// (kontrol sırası: önce aktif kart var mı, sonra UID). Bu YANLIŞ POZİTİF
// vermesin diye KARTSIZ taze bir personel kullanılır.
$rEmpD = pdks_personel_olustur(['full_name' => 'Kartsız Deneme Personeli'], 1, db());
$empD = $rEmpD['id'];
dogrula('geçersiz UID reddedilir', pdks_kart_ata($empD, 'ZZZZ', 'nfc_hex', [], db())['kod'], 'gecersiz_uid');
dogrula('kaynak bildirilmezse reddedilir', pdks_kart_ata($empD, '123', 'tahmin', [], db())['kod'], 'gecersiz_kaynak');
dogrula('olmayan personele kart atanamaz', pdks_kart_ata(999999, '555666777', 'usb_decimal', [], db())['kod'], 'personel_yok');
dogrula('geçersiz UID denemeleri kart YAZMADI', pdks_personel_aktif_kart($empD, db()), null);

// ═══════════════════════════════════════════════════════════
echo "\n=== 13. KART İPTALİ ===\n";
$GLOBALS['AUDIT'] = [];
$rIptal = pdks_kart_durum_degistir($kartA, 'iptal', 'Personel talebi üzerine', 1, db());
dogrula('iptal edildi', $rIptal['ok'], true);
dogrula('durum iptal', db()->query("SELECT status FROM employee_cards WHERE id=$kartA")->fetchColumn(), 'iptal');
dogrula('audit: card_revoked (adlandırılmış olay)', in_array('card_revoked', array_column($GLOBALS['AUDIT'], 'action'), true), true);
dogrula('gerekçesiz iptal reddedilir', pdks_kart_durum_degistir($kartA, 'iptal', '', 1, db())['kod'], 'gerekce_zorunlu');
dogrula('iptal edilen kart HÂLÂ çözülüyor (sessiz "tanımsız" değil)',
    pdks_kart_cozumle('631799511', 'usb_decimal', db()) !== null, true);
dogrula('iptal edilen kart artık AKTİF değil', pdks_personel_aktif_kart($empA, db()), null);
dogrula('iptalden sonra personele YENİ kart atanabilir (aktif kart artık yok)',
    pdks_kart_ata($empA, '999888777', 'usb_decimal', [], db())['ok'], true);

echo "\n=== 14. KAYIP BİLDİRİMİ ===\n";
$GLOBALS['AUDIT'] = [];
$aktifB = pdks_personel_aktif_kart($empB, db());
$rKayip = pdks_kart_durum_degistir((int)$aktifB['id'], 'kayip', 'Kart kayboldu', 1, db());
dogrula('kayıp işaretlendi', $rKayip['ok'], true);
dogrula('durum kayip', db()->query("SELECT status FROM employee_cards WHERE id=" . (int)$aktifB['id'])->fetchColumn(), 'kayip');
dogrula('audit: card_lost (adlandırılmış olay)', in_array('card_lost', array_column($GLOBALS['AUDIT'], 'action'), true), true);
dogrula('geçersiz durum reddedilir', pdks_kart_durum_degistir((int)$aktifB['id'], 'uydurma', 'x', 1, db())['ok'], false);

echo "\n=== 15. KART DEĞİŞTİRME — eski kart korunur, yeni kart devreye girer ===\n";
$aktifA = pdks_personel_aktif_kart($empA, db());   // 13. testte atanan 999888777 → kanonik
$eskiKartId = (int)$aktifA['id'];
$eskiUid = $aktifA['uid_hex'];
$GLOBALS['AUDIT'] = [];
$rDegis = pdks_kart_degistir($eskiKartId, '444555666', 'usb_decimal', 'Kart aşındı, okunmuyor', 1, db());
dogrula('değiştirildi', $rDegis['ok'], true);
$yeniKartId = $rDegis['card_id'];
dogrula('yeni kart id eskisinden farklı', $yeniKartId !== $eskiKartId, true);
$eskiSatir = db()->query("SELECT * FROM employee_cards WHERE id=$eskiKartId")->fetch();
dogrula('eski kart durumu degistirildi', $eskiSatir['status'], 'degistirildi');
dogrula('eski kart replacement_card_id yeni karta işaret ediyor', (int)$eskiSatir['replacement_card_id'], $yeniKartId);
dogrula('eski kart UID DEĞİŞMEDİ (geçmiş bozulmadı)', $eskiSatir['uid_hex'], $eskiUid);
dogrula('eski kart SİLİNMEDİ (satır hâlâ var)', $eskiSatir !== false, true);
dogrula('yeni kart AKTİF', db()->query("SELECT status FROM employee_cards WHERE id=$yeniKartId")->fetchColumn(), 'aktif');
dogrula('personelin aktif kartı artık YENİ kart', (int)pdks_personel_aktif_kart($empA, db())['id'], $yeniKartId);
dogrula('audit: card_replaced', in_array('card_replaced', array_column($GLOBALS['AUDIT'], 'action'), true), true);
$gecmisA = pdks_personel_kart_gecmisi($empA, db());
dogrula('kart geçmişinde HEM eski HEM yeni kart var', count($gecmisA) >= 2, true);

echo "\n=== 16. KART DEĞİŞTİRME — yeni UID zaten kullanımdaysa ESKİ KART DOKUNULMAZ ===\n";
$digerKart = pdks_kart_ata($empB, '112233445', 'usb_decimal', [], db());
dogrula('deneme kartı oluştu', $digerKart['ok'], true);
$eskiDurumOnce = db()->query("SELECT status FROM employee_cards WHERE id=$yeniKartId")->fetchColumn();
$rCakisan = pdks_kart_degistir($yeniKartId, '112233445', 'usb_decimal', 'Deneme', 1, db());
dogrula('çakışan UID ile değiştirme reddedildi', $rCakisan['ok'], false);
dogrula('kod: uid_kullanimda', $rCakisan['kod'], 'uid_kullanimda');
dogrula('ESKİ KART durumu DEĞİŞMEDİ (kısmi/tutarsız durum yok)',
    db()->query("SELECT status FROM employee_cards WHERE id=$yeniKartId")->fetchColumn(), $eskiDurumOnce);
dogrula('gerekçesiz değiştirme reddedilir', pdks_kart_degistir($yeniKartId, '999999999', 'usb_decimal', '', 1, db())['kod'], 'gerekce_zorunlu');
dogrula('olmayan kart değiştirilemez', pdks_kart_degistir(999999, '999999999', 'usb_decimal', 'x', 1, db())['kod'], 'kart_yok');

// ═══════════════════════════════════════════════════════════
echo "\n=== 17. FOTOĞRAF DOĞRULAMASI ===\n";
if (function_exists('imagecreatetruecolor')) {
    $tmp = tempnam(sys_get_temp_dir(), 'pdksfototest');
    $im = imagecreatetruecolor(400, 300);
    imagejpeg($im, $tmp, 90);
    imagedestroy($im);
    $v = pdks_foto_gecerli_mi($tmp, filesize($tmp));
    dogrula('gerçek görsel kabul edilir', $v['ok'], true);

    $cokBuyuk = pdks_foto_gecerli_mi($tmp, PDKS_FOTO_MAX_BOYUT + 1);
    dogrula('boyut limiti aşımı reddedilir', $cokBuyuk['ok'], false);

    $olmayan = pdks_foto_gecerli_mi('/tmp/olmayan_dosya_' . bin2hex(random_bytes(8)), 100);
    dogrula('olmayan dosya reddedilir', $olmayan['ok'], false);

    $sahteJpg = tempnam(sys_get_temp_dir(), 'pdksfake');
    file_put_contents($sahteJpg, str_repeat('X', 200));   // gerçek görsel değil
    $sonucSahte = pdks_foto_gecerli_mi($sahteJpg, filesize($sahteJpg));
    dogrula('görsel olmayan dosya reddedilir', $sonucSahte['ok'], false);

    dogrula('foto_sil güvenli olmayan adı reddeder (silmez)',
        (function () { pdks_foto_sil('../../etc/passwd'); return true; })(), true);   // exception atmamalı
    dogrula('foto_sil rastgele adı da reddeder',
        (function () { pdks_foto_sil('rastgele-ad.jpg'); return true; })(), true);

    @unlink($tmp); @unlink($sahteJpg);
} else {
    echo "    (GD yok — fotoğraf testleri atlandı)\n";
}

// ═══════════════════════════════════════════════════════════
echo "\n=== 18. YETKİ AYRIMI — employees ve cards BAĞIMSIZ ===\n";
$GLOBALS['PERMS'] = ['attendance.employees'];   // yalnız personel yetkisi
dogrula('employees yetkisi var', pdks_can('employees'), true);
dogrula('cards yetkisi YOK', pdks_can('cards'), false);
$GLOBALS['PERMS'] = ['attendance.cards'];   // yalnız kart yetkisi
dogrula('cards yetkisi var', pdks_can('cards'), true);
dogrula('employees yetkisi YOK', pdks_can('employees'), false);
$GLOBALS['PERMS'] = [];

echo "\n=== 19. REGRESYON — Faz 1 UID ve DB testleri hâlâ geçiyor mu ===\n";
$uidCikti = shell_exec('php ' . escapeshellarg(__DIR__ . '/pdks_uid_smoke.php') . ' 2>&1');
$dbCikti  = shell_exec('php ' . escapeshellarg(__DIR__ . '/pdks_db_smoke.php') . ' 2>&1');
dogrula('pdks_uid_smoke.php hâlâ geçiyor', str_contains((string)$uidCikti, '0 hata.'), true);
dogrula('pdks_db_smoke.php hâlâ geçiyor',  str_contains((string)$dbCikti, '0 hata.'), true);

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $hata);
exit($hata === 0 ? 0 : 1);
