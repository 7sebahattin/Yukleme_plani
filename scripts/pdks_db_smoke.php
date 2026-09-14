<?php
// =========================================================
// scripts/pdks_db_smoke.php — PDKS şema kısıtları + kart alan mantığı testi
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ: bellek içi SQLite kullanır.
//   php scripts/pdks_db_smoke.php     → çıkış kodu 0 = tüm testler geçti
//
// ÖNEMLİ: Test, elle yazılmış bir test şeması KULLANMAZ. config/pdks.php'deki
// GERÇEK MySQL DDL'i SQLite'a çevirip çalıştırır (pdks_ddl_sqlite). Böylece
// test edilen UNIQUE/FK kısıtları, üretime gidecek olanların TA KENDİSİDİR.
// Elle yazılmış bir test şeması, kendi yazdığımız kısıtları test etmek olurdu.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

// ── Stub'lar: config/pdks.php'nin opsiyonel dış bağımlılıkları ──
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid];
}
$PERMS = [];
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
$IS_ADMIN = false;
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }

require_once __DIR__ . '/../config/pdks.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici
// ─────────────────────────────────────────────────────────
/**
 * @return array{0:string,1:array<int,string>} [CREATE TABLE, [CREATE INDEX...]]
 */
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1];
    $govde = $m[2];

    // Üst seviye virgüllerden böl (parantez derinliğine saygılı)
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

        // Kolon / CONSTRAINT tanımı — SQLite uyumlu hâle getir
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);   // SQLite desteklemez
        $kolonlar[] = $p;
    }

    $create = "CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)";
    return [$create, $indeksler];
}

/** `col`(80), `col2`  →  `col`, `col2`   (SQLite önek uzunluğu bilmez) */
function pdks_kolon_listesi(string $ham): string
{
    $ham = preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $ham);
    return preg_replace('/\s+/', ' ', trim($ham));
}

// ─────────────────────────────────────────────────────────
// Şemayı kur
// ─────────────────────────────────────────────────────────
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
$PDO_TEST->exec('PRAGMA foreign_keys = ON');       // FK'lar gerçekten uygulansın
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

// users: MEVCUT tablo — testte asgari hâliyle temsil edilir (PDKS ona dokunmaz)
db()->exec("CREATE TABLE `users` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `username` VARCHAR(60) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL DEFAULT '',
    `is_active` INTEGER NOT NULL DEFAULT 1)");
db()->exec("INSERT INTO users (username, display_name) VALUES ('admin','Yönetici'),('depo1','Depo Sorumlusu')");

$hata = 0; $gecen = 0;
function dogrula(string $ad, $bulunan, $beklenen): void {
    global $hata, $gecen;
    $ok = $bulunan === $beklenen;
    $ok ? $gecen++ : $hata++;
    printf("%-62s %-16s %s\n", $ad, var_export($bulunan, true),
        $ok ? 'OK' : '*** HATA (beklenen ' . var_export($beklenen, true) . ')');
}
/** Bir yazmanın veritabanı kısıtına takılmasını bekler. */
function reddedildi_mi(callable $f): bool {
    try { $f(); return false; } catch (PDOException $e) { return true; }
}

echo "\n=== 1. ŞEMA KURULUMU (gerçek DDL, SQLite'a çevrilmiş) ===\n";
foreach (pdks_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
    dogrula("tablo kuruldu: $ad", pdks_tablo_var(db(), $ad), true);
}
dogrula('pdks_sema_hazir()', pdks_sema_hazir(db()), true);

echo "\n=== 2. PERSONEL — user_id ilişkisi (karar #11) ===\n";
$ins = db()->prepare("INSERT INTO employees (personnel_no, full_name, department, user_id) VALUES (?,?,?,?)");
$ins->execute(['027', 'Personel A', 'Depo', null]);   $empA = (int)db()->lastInsertId();
$ins->execute(['028', 'Personel B', 'Depo', null]);   $empB = (int)db()->lastInsertId();
$ins->execute(['029', 'Personel C', 'Ofis', 1]);      $empC = (int)db()->lastInsertId();

dogrula('user_id NULL kabul (A)',  $empA > 0, true);
dogrula('user_id NULL İKİNCİ kez de kabul (B)', $empB > 0, true);
dogrula('user_id dolu kabul (C)',  $empC > 0, true);
dogrula('AYNI user_id ikinci kez REDDEDİLİYOR',
    reddedildi_mi(fn() => db()->prepare("INSERT INTO employees (full_name, user_id) VALUES (?,?)")
        ->execute(['Personel D', 1])), true);
dogrula('farklı user_id kabul',
    reddedildi_mi(fn() => db()->prepare("INSERT INTO employees (full_name, user_id) VALUES (?,?)")
        ->execute(['Personel E', 2])), false);
dogrula('aynı personnel_no REDDEDİLİYOR',
    reddedildi_mi(fn() => db()->prepare("INSERT INTO employees (personnel_no, full_name) VALUES (?,?)")
        ->execute(['027', 'Kopya'])), true);
dogrula('personnel_no NULL çoklu kabul',
    reddedildi_mi(fn() => db()->prepare("INSERT INTO employees (personnel_no, full_name) VALUES (?,?)")
        ->execute([null, 'Sicilsiz 2'])), false);

echo "\n=== 3. KART OLUŞTURMA — USB ondalık (631799511) → Kart A ===\n";
$r = pdks_kart_olustur($empA, '631799511', 'usb_decimal', ['label' => 'Kart-01'], db());
dogrula('kart oluşturuldu',        $r['ok'], true);
dogrula('kanonik UID',             $r['uid_hex'], '25A87ED7');
dogrula('ondalık kaydedildi',      $r['uid_decimal'], '631799511');
dogrula('bayt sayısı',             $r['uid_bytes'], 4);
$kartA = (int)$r['card_id'];

// ⚠ FAZ 1 DÜZELTMESİ: Artık yalnız 1 alias yazılır (kanonik). Bayt-tersi
// ARTIK otomatik ikinci bir alias olarak YAZILMIYOR — bkz. §5 (bunun neden
// gerekli olduğunun kanıtı orada).
$aliaslar = db()->query("SELECT uid_hex, kind FROM employee_card_uids WHERE card_id = $kartA")->fetchAll();
dogrula('YALNIZ 1 alias yazıldı (bayt-tersi artık otomatik yazılmıyor)', count($aliaslar), 1);
dogrula('yazılan tek alias kanonik',   $aliaslar[0]['uid_hex'], '25A87ED7');
dogrula('kind = canonical',            $aliaslar[0]['kind'], 'canonical');
dogrula('D77EA825 (bayt-tersi) YAZILMADI',
    (int)db()->query("SELECT COUNT(*) FROM employee_card_uids WHERE uid_hex = 'D77EA825'")->fetchColumn(), 0);
dogrula('audit yazıldı', in_array('card_create', array_column($GLOBALS['AUDIT'], 'action'), true), true);

echo "\n=== 4. AYNI KAYNAK İÇİNDE YAZIM FARKLARI AYNI KARTA ÇÖZÜLÜR ===\n";
echo "    (ayraç/büyük-küçük harf farkı — bayt SIRASI farkı DEĞİL)\n\n";
$c1 = pdks_kart_cozumle('631799511',  'usb_decimal', db());
$c2 = pdks_kart_cozumle('25 A8 7E D7','nfc_hex',     db());
$c3 = pdks_kart_cozumle('25:A8:7E:D7','nfc_hex',     db());
dogrula("USB '631799511' → Kart A",         (int)$c1['card']['id'], $kartA);
dogrula("NFC '25 A8 7E D7' → Kart A",       (int)$c2['card']['id'], $kartA);
dogrula("NFC '25:A8:7E:D7' → Kart A",       (int)$c3['card']['id'], $kartA);
dogrula('personel doğru çözüldü',           (int)$c1['employee']['id'], $empA);
dogrula('personel adı',                     $c1['employee']['full_name'], 'Personel A');
dogrula('tanımsız UID → null',              pdks_kart_cozumle('999999999', 'usb_decimal', db()), null);
dogrula('kaynak bildirilmezse → null',      pdks_kart_cozumle('631799511', 'bilinmiyor', db()), null);

// ⚠ BURASI DÜZELTMENİN KALBİ: bayt-tersi (D7:7E:A8:25) HENÜZ hiçbir karta
// tanımlanmadı — bu yüzden Kart A'ya OTOMATİK olarak çözülmemeli. Eski
// davranış (Faz 1'in ilk sürümü) burada Kart A'yı döndürürdü; bu YANLIŞTI.
dogrula("NFC 'D7:7E:A8:25' (Kart A'nın bayt-tersi) HENÜZ TANIMLI DEĞİL → null",
    pdks_kart_cozumle('D7:7E:A8:25', 'nfc_hex', db()), null);

echo "\n=== 5. İKİ FARKLI FİZİKSEL KART AYNI ANDA VAR OLABİLİR (Faz 1 düzeltmesi) ===\n";
echo "    Kart A: 25A87ED7   ·   Kart B: D77EA825 (Kart A'nın bayt-tersi, AMA GERÇEKTEN\n";
echo "    FARKLI bir fiziksel kart). Kart B'nin kaydı ARTIK REDDEDİLMİYOR.\n\n";
$rB = pdks_kart_olustur($empB, 'D7:7E:A8:25', 'nfc_hex', ['label' => 'Kart-02'], db());
dogrula('Kart B (bayt-tersi UID) BAŞARIYLA oluşturuldu', $rB['ok'], true);
dogrula('Kart B kanoniği doğru',                         $rB['uid_hex'], 'D77EA825');
$kartB = (int)($rB['card_id'] ?? 0);
dogrula('Kart B, Kart A\'dan FARKLI bir satır',          $kartB !== $kartA, true);
dogrula('toplam kart sayısı ŞİMDİ 2',
    (int)db()->query("SELECT COUNT(*) FROM employee_cards")->fetchColumn(), 2);
dogrula('toplam alias sayısı ŞİMDİ 2 (kart başına 1)',
    (int)db()->query("SELECT COUNT(*) FROM employee_card_uids")->fetchColumn(), 2);

// İkisi de BAĞIMSIZ ve DOĞRU çözülüyor — biri diğerini gölgelemiyor.
$ca = pdks_kart_cozumle('631799511', 'usb_decimal', db());   // USB — hâlâ Kart A'yı bulmalı
$cb = pdks_kart_cozumle('D77EA825',  'nfc_hex',     db());   // Kart B'nin kendi kanonik hâli
$cb2 = pdks_kart_cozumle('D7:7E:A8:25', 'nfc_hex',  db());   // Kart B'yi tanımlarken kullanılan ham girdi
dogrula("USB '631799511' HÂLÂ Kart A'yı buluyor (Kart B onu bozmadı)", (int)$ca['card']['id'], $kartA);
dogrula('Kart A\'nın çalışanı doğru',                                  (int)$ca['employee']['id'], $empA);
dogrula("NFC 'D77EA825' → Kart B",                                     (int)$cb['card']['id'], $kartB);
dogrula('Kart B\'nin çalışanı doğru',                                  (int)$cb['employee']['id'], $empB);
dogrula('Kart B\'nin çalışan adı',                                     $cb['employee']['full_name'], 'Personel B');
dogrula("Aynı ham girdi ('D7:7E:A8:25') tutarlı biçimde Kart B'yi buluyor", (int)$cb2['card']['id'], $kartB);
dogrula('Kart A ile Kart B FARKLI kartlar olarak kalıyor', $ca['card']['id'] !== $cb['card']['id'], true);

echo "\n=== 6. GERÇEK ÇAKIŞMA HÂLÂ REDDEDİLİYOR (kanonik benzersizlik ZAYIFLATILMADI) ===\n";
$r2 = pdks_kart_olustur($empA, '631799511', 'usb_decimal', [], db());
dogrula('AYNI personele aynı kart (USB) 2. kez reddedildi', $r2['ok'], false);
dogrula('  red kodu',                                       $r2['kod'], 'uid_kullanimda');

$r3 = pdks_kart_olustur($empC, '631799511', 'usb_decimal', [], db());
dogrula('BAŞKA personele Kart A\'nın UID\'si (USB) reddedildi', $r3['ok'], false);
dogrula('  red kodu',                                            $r3['kod'], 'uid_kullanimda');

$r4 = pdks_kart_olustur($empC, '25A87ED7', 'nfc_hex', [], db());
dogrula('Kart A\'nın UID\'si HEX kaynağıyla da reddedildi',      $r4['ok'], false);

$r5 = pdks_kart_olustur($empC, 'D77EA825', 'nfc_hex', [], db());
dogrula('Kart B\'nin UID\'si (kanonik) de reddedildi',           $r5['ok'], false);
dogrula('  red kodu',                                             $r5['kod'], 'uid_kullanimda');

$r6 = pdks_kart_olustur($empC, 'D7:7E:A8:25', 'nfc_hex', [], db());
dogrula('Kart B\'nin UID\'si (ham/ayraçlı) de reddedildi',       $r6['ok'], false);

dogrula('toplam kart sayısı HÂLÂ 2 (yanlış denemeler yeni kart açmadı)',
    (int)db()->query("SELECT COUNT(*) FROM employee_cards")->fetchColumn(), 2);
dogrula('toplam alias sayısı HÂLÂ 2',
    (int)db()->query("SELECT COUNT(*) FROM employee_card_uids")->fetchColumn(), 2);

echo "\n=== 7. VERİTABANI KISITLARI DOĞRUDAN ===\n";
dogrula('employee_cards.uid_hex UNIQUE',
    reddedildi_mi(fn() => db()->prepare("INSERT INTO employee_cards (employee_id, uid_hex) VALUES (?,?)")
        ->execute([$empC, '25A87ED7'])), true);
dogrula('employee_card_uids.uid_hex UNIQUE',
    reddedildi_mi(fn() => db()->prepare("INSERT INTO employee_card_uids (card_id, uid_hex, kind) VALUES (?,?,?)")
        ->execute([$kartB, '25A87ED7', 'canonical'])), true);
dogrula('kart FK: olmayan personel reddedilir',
    reddedildi_mi(fn() => db()->prepare("INSERT INTO employee_cards (employee_id, uid_hex) VALUES (?,?)")
        ->execute([999999, 'AABBCCDD'])), true);
dogrula('alias FK: olmayan kart reddedilir',
    reddedildi_mi(fn() => db()->prepare("INSERT INTO employee_card_uids (card_id, uid_hex) VALUES (?,?)")
        ->execute([999999, 'AABBCCDD'])), true);

echo "\n=== 8. GEÇERSİZ GİRDİ — FAIL CLOSED ===\n";
dogrula('kaynak bildirilmemiş',      pdks_kart_olustur($empB, '631799511', 'tahmin', [], db())['kod'], 'gecersiz_kaynak');
dogrula('geçersiz UID',              pdks_kart_olustur($empB, 'ZZZZ', 'nfc_hex', [], db())['kod'], 'gecersiz_uid');
dogrula('3 baytlık UID reddedildi',  pdks_kart_olustur($empB, '25A87E', 'nfc_hex', [], db())['kod'], 'gecersiz_uid');
dogrula('olmayan personel',          pdks_kart_olustur(999999, 'AABBCCDD', 'nfc_hex', [], db())['kod'], 'personel_yok');
dogrula('başarısız denemeler yeni kart yaratmadı (Kart A + Kart B = 2, fazlası yok)',
    (int)db()->query("SELECT COUNT(*) FROM employee_cards")->fetchColumn(), 2);

echo "\n=== 9. 7 ve 10 BAYTLIK KARTLAR ===\n";
$r7 = pdks_kart_olustur($empB, '04A2B3C4D5E6F0', 'nfc_hex', [], db());
dogrula('7 baytlık kart oluştu', $r7['ok'], true);
dogrula('  uid_bytes = 7',       $r7['uid_bytes'], 7);
$r10 = pdks_kart_olustur($empC, '0102030405060708090A', 'nfc_hex', [], db());
dogrula('10 baytlık kart oluştu', $r10['ok'], true);
dogrula('  uid_bytes = 10',       $r10['uid_bytes'], 10);
$c10 = pdks_kart_cozumle('0102030405060708090A', 'nfc_hex', db());
dogrula('10 baytlık kart çözülüyor', (int)$c10['card']['id'], (int)$r10['card_id']);
$dec10 = db()->query("SELECT uid_decimal FROM employee_cards WHERE id = " . (int)$r10['card_id'])->fetchColumn();
dogrula('10 bayt ondalığı 25 haneye sığdı', strlen((string)$dec10) <= 25, true);

echo "\n=== 10. PALİNDROM UID — tek alias ===\n";
$rp = pdks_kart_olustur($empB, 'A5A5A5A5', 'nfc_hex', [], db());
dogrula('palindrom kart oluştu', $rp['ok'], true);
$pa = (int)db()->query("SELECT COUNT(*) FROM employee_card_uids WHERE card_id = " . (int)$rp['card_id'])->fetchColumn();
dogrula('yalnız 1 alias yazıldı (çift kayıt yok)', $pa, 1);

echo "\n=== 11. KART İPTALİ — geçmiş korunur ===\n";
$ri = pdks_kart_iptal($kartA, 'kayip', 'Personel kartı kaybetti', 1, db());
dogrula('iptal başarılı', $ri['ok'], true);
$kart = db()->query("SELECT * FROM employee_cards WHERE id = $kartA")->fetch();
dogrula('durum kayip',              $kart['status'], 'kayip');
dogrula('gerekçe kaydedildi',       $kart['revoke_reason'], 'Personel kartı kaybetti');
dogrula('kart SİLİNMEDİ',           (int)$kart['id'], $kartA);
dogrula('pdks_kart_aktif_mi false', pdks_kart_aktif_mi($kart['status']), false);
$ci = pdks_kart_cozumle('631799511', 'usb_decimal', db());
dogrula('iptal kart HÂLÂ çözülüyor (sessiz "tanımsız" değil)', (int)$ci['card']['id'], $kartA);
dogrula('audit: card_revoke', in_array('card_revoke', array_column($GLOBALS['AUDIT'], 'action'), true), true);
dogrula('aktif olmayan duruma iptal reddi', pdks_kart_iptal($kartA, 'aktif', 'x', 1, db())['ok'], false);

echo "\n=== 12. PERSONEL SİLİNİRSE KART DA GİDER (FK CASCADE) ===\n";
$oncekiKart = (int)db()->query("SELECT COUNT(*) FROM employee_cards WHERE employee_id = $empC")->fetchColumn();
dogrula('C personelinin kartı var', $oncekiKart, 1);
db()->exec("DELETE FROM employees WHERE id = $empC");
dogrula('personel silinince kartı da silindi',
    (int)db()->query("SELECT COUNT(*) FROM employee_cards WHERE employee_id = $empC")->fetchColumn(), 0);
dogrula('kartın aliasları da silindi',
    (int)db()->query("SELECT COUNT(*) FROM employee_card_uids u
                      LEFT JOIN employee_cards c ON c.id = u.card_id WHERE c.id IS NULL")->fetchColumn(), 0);

echo "\n=== 13. YETKİ KAPISI ===\n";
$GLOBALS['PERMS'] = []; $GLOBALS['IS_ADMIN'] = false;
dogrula('yetkisiz: read',      pdks_can('read'), false);
dogrula('yetkisiz: cards',     pdks_can('cards'), false);
$GLOBALS['PERMS'] = ['attendance.read','attendance.employees','attendance.cards','attendance.report'];  // 'ik' rolü
dogrula('ik: read',            pdks_can('read'), true);
dogrula('ik: employees',       pdks_can('employees'), true);
dogrula('ik: cards',           pdks_can('cards'), true);
dogrula('ik: scan YOK',        pdks_can('scan'), false);
dogrula('ik: devices YOK',     pdks_can('devices'), false);
dogrula('ik: admin YOK',       pdks_can('admin'), false);
$GLOBALS['PERMS'] = ['attendance.scan'];   // Faz 2 'guvenlik' rolü
dogrula('guvenlik: scan',      pdks_can('scan'), true);
dogrula('guvenlik: read YOK',  pdks_can('read'), false);
dogrula('guvenlik: cards YOK', pdks_can('cards'), false);
$GLOBALS['PERMS'] = []; $GLOBALS['IS_ADMIN'] = true;
dogrula('admin her şeye evet', pdks_can('admin') && pdks_can('scan') && pdks_can('cards'), true);
dogrula('bilinmeyen eylem (admin dışı)', (function () {
    $GLOBALS['IS_ADMIN'] = false; $r = pdks_can('uydurma'); $GLOBALS['IS_ADMIN'] = true; return $r;
})(), false);

echo "\n=== 14. MİGRASYON IDEMPOTENT ve HATAYA DAYANIKLI ===\n";
$rap = pdks_migrate(db());
$tabloAdlari = array_keys(pdks_tablolar());
$tabloSat = array_values(array_filter($rap, fn($r) => in_array($r['tablo'], $tabloAdlari, true)));
$fkSat    = array_values(array_filter($rap, fn($r) => $r['tablo'] === 'employees.fk_emp_user'));

dogrula('her tablo raporlandı', count($tabloSat), count($tabloAdlari));
dogrula('hiçbir TABLO hata vermedi',
    count(array_filter($tabloSat, fn($r) => $r['durum'] === 'hata')), 0);
dogrula('hepsi "var" (idempotent — yeniden oluşturmadı)',
    count(array_filter($tabloSat, fn($r) => $r['durum'] === 'var')), count($tabloAdlari));

// users FK'sı MySQL'e özgü ALTER ... ADD CONSTRAINT sözdizimidir; SQLite kabul
// etmez. BU BEKLENEN DURUMDUR ve migrasyonu DURDURMAMALIDIR — FK opsiyoneldir,
// iş kuralını UNIQUE kısıtı zaten uyguluyor (bkz. config/pdks.php açıklaması).
dogrula('users FK denendi ve raporlandı', count($fkSat), 1);
dogrula('FK hatası migrasyonu DURDURMADI', pdks_sema_hazir(db()), true);
dogrula('FK hatası veriyi bozmadı',
    (int)db()->query("SELECT COUNT(*) FROM employee_cards")->fetchColumn() > 0, true);

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $hata);
exit($hata === 0 ? 0 : 1);
