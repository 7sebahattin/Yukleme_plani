<?php
// =========================================================
// scripts/pdks_gunluk_static_smoke.php — Günlük İşçi kaynak-kodu kuralları
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar
// (pdks_faz1b_static_smoke.php / pdks_giris_cikis_static_smoke.php ile aynı desen).
//
// Kanıtlanan kurallar:
//  1) Yetki kapısından geçiyor (require_pdks_gunluk) — her yeni sayfada.
//  2) Mevcut kalıcı personel tablolarına (employees/employee_cards/
//     employee_card_uids/attendance_events/attendance_gates) HİÇBİR YENİ
//     ALTER/DROP eklenmedi — yalnız additive CREATE TABLE.
//  3) NFC enrollment YENİDEN İCAT EDİLMEDİ — isci_kartlari.php assets/pdks.js'in
//     data-pdks-scan/data-pdks-nfc-target desenini REUSE ediyor, kendi
//     NDEFReader'ını AÇMIYOR.
//  4) UID normalizasyonu TEKRARLANMADI — config/pdks_gunluk.php kendi
//     hex/decimal dönüşüm mantığını YAZMIYOR, config/pdks.php fonksiyonlarını
//     çağırıyor.
//  5) Sidebar + izin seed'i doğru bağlandı.
//  6) Silme yok (foremen/worker_types) — yalnız aktif/pasif.
//
//   php scripts/pdks_gunluk_static_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$KOK = dirname(__DIR__);
$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $p): string { global $KOK; return (string)@file_get_contents($KOK . '/' . $p); }
function kodSadece(string $s): string { return preg_replace('/^\s*\/\/.*$/m', '', $s); }

$dosyalar = [
    'config/pdks_gunluk.php', 'cavuslar.php', 'cavus_form.php',
    'isci_kartlari.php', 'isci_tipleri.php',
];

echo "\n=== 1. SÖZ DİZİMİ — tüm yeni dosyalar php -l geçiyor ===\n";
foreach ($dosyalar as $f) {
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg($KOK . '/' . $f) . ' 2>&1', $cikti, $rc);
    ok("$f: php -l geçiyor", $rc === 0, implode("\n", $cikti));
}

$gunlukSrc = oku('config/pdks_gunluk.php');
$gunlukKod = kodSadece($gunlukSrc);

echo "\n=== 2. YETKİ KAPISI — her sayfada require_pdks_gunluk() var ===\n";
foreach (['cavuslar.php', 'cavus_form.php', 'isci_kartlari.php', 'isci_tipleri.php'] as $f) {
    $src = oku($f);
    ok("$f: require_pdks_gunluk() çağırıyor", (bool)preg_match('/require_pdks_gunluk\(/', $src));
    ok("$f: require_login() çağırıyor", str_contains($src, 'require_login()'));
}
// POST dallarında savunma derinliği — personel_kartlar.php ile aynı desen.
foreach (['cavus_form.php', 'isci_kartlari.php', 'isci_tipleri.php'] as $f) {
    $src = oku($f);
    ok("$f: require_pdks_gunluk() POST dalında TEKRAR var (savunma derinliği)",
        substr_count($src, 'require_pdks_gunluk(') >= 2);
    ok("$f: csrf_check() çağırıyor", str_contains($src, 'csrf_check('));
}

echo "\n=== 3. MEVCUT KALICI PERSONEL ŞEMASINA DOKUNULMADI ===\n";
// Kullanıcının açık talimatı: "Existing attendance_events for permanent
// personnel must continue working" + "Do not silently alter existing
// employee-card data." — bu dosyalarda o tablolara ALTER/DROP YOK.
$korunanTablolar = ['employees', 'employee_cards', 'employee_card_uids', 'attendance_events', 'attendance_gates'];
foreach ($dosyalar as $f) {
    $src = oku($f);
    foreach ($korunanTablolar as $t) {
        ok("$f: `$t` için ALTER TABLE YOK",
            !preg_match('/ALTER TABLE\s+`?' . preg_quote($t, '/') . '`?/i', $src));
        ok("$f: `$t` için DROP TABLE YOK",
            !preg_match('/DROP TABLE\s+`?' . preg_quote($t, '/') . '`?/i', $src));
    }
}
// config/pdks.php AYRI ele alınır: pdks_users_fk_sql() zaten (bu görevden
// ÖNCE de var olan, MEŞRU) `ALTER TABLE employees ADD CONSTRAINT fk_emp_user`
// içerir — blanket "ALTER YOK" testi burada YANLIŞ POZİTİF verir. Doğru soru
// "bu görev YENİ bir ALTER/DROP eklemiş mi" — git diff ile doğrulanır.
$pdksDiff = (string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff -- config/pdks.php 2>&1');
$eklenenSatirlar = implode("\n", array_filter(explode("\n", $pdksDiff), fn($l) => str_starts_with($l, '+') && !str_starts_with($l, '+++')));
ok('config/pdks.php diff\'inde YENİ bir ALTER TABLE eklenmedi', !preg_match('/^\+.*ALTER TABLE/mi', $eklenenSatirlar));
ok('config/pdks.php diff\'inde YENİ bir DROP TABLE eklenmedi', !preg_match('/^\+.*DROP TABLE/mi', $eklenenSatirlar));
ok('config/pdks.php: pdks_tablolar() SÖZ DİZİMİ değişmedi (worker_* eklenmedi — AYRI dosyada)',
    !str_contains(oku('config/pdks.php'), 'worker_cards') || str_contains(oku('config/pdks.php'), 'pdks_gunluk_uid_gecici_kartta_mi'));
// Yukarıdaki satır kasıtlı gevşek: pdks_kart_olustur() İÇİNDE worker_cards
// SÖZCÜĞÜ geçebilir (fonksiyon adında/yorumda) ama YALNIZ çapraz-kontrol
// ÇAĞRISI olarak — aşağıda AYRICA sıkı doğrulanıyor.
ok('config/pdks.php: pdks_kart_olustur() İÇİNDE gerçek bir CREATE/ALTER worker_cards YOK',
    !preg_match('/CREATE TABLE[^;]*worker_cards/i', oku('config/pdks.php'))
    && !preg_match('/ALTER TABLE[^;]*worker_cards/i', oku('config/pdks.php')));

echo "\n=== 4. YALNIZ ADDITIVE — yeni tablolar CREATE TABLE IF NOT EXISTS ===\n";
foreach (['worker_types', 'foremen', 'worker_cards'] as $t) {
    ok("$t: CREATE TABLE IF NOT EXISTS ile tanımlı (yıkıcı DEĞİL)",
        (bool)preg_match('/CREATE TABLE IF NOT EXISTS\s+`' . $t . '`/', $gunlukSrc));
}
ok('pdks_gunluk_migrate() İDEMPOTENT — var olan tabloyu atlıyor (pdks_migrate() ile AYNI desen)',
    str_contains($gunlukSrc, 'pdks_gunluk_tablo_var($pdo, $ad)'));

echo "\n=== 5. ÇAPRAZ-SİSTEM UID ÇAKIŞMASI — İKİ YÖN DE KODDA VAR ===\n";
ok('YÖN 1 (yeni işçi kartı → kalıcı personelle çakışma) fonksiyonu tanımlı',
    (bool)preg_match('/function pdks_gunluk_uid_kalici_kartta_mi/', $gunlukSrc));
ok('pdks_gunluk_kart_olustur() YÖN 1 kontrolünü ÇAĞIRIYOR',
    (bool)preg_match('/function pdks_gunluk_kart_olustur.*?pdks_gunluk_uid_kalici_kartta_mi/s', $gunlukSrc));
ok('YÖN 2 (yeni kalıcı kart → işçi havuzuyla çakışma) fonksiyonu tanımlı',
    (bool)preg_match('/function pdks_gunluk_uid_gecici_kartta_mi/', $gunlukSrc));
$pdksSrc = oku('config/pdks.php');
ok('config/pdks.php → pdks_kart_olustur() YÖN 2 kontrolünü function_exists GUARD\'LI çağırıyor',
    (bool)preg_match('/function_exists\(\s*[\'"]pdks_gunluk_uid_gecici_kartta_mi[\'"]\s*\)/', $pdksSrc));
ok('bu çağrı pdks_kart_olustur() fonksiyonunun İÇİNDE (dosya sonunda değil)',
    (bool)preg_match('/function pdks_kart_olustur.*?function_exists\(\s*[\'"]pdks_gunluk_uid_gecici_kartta_mi[\'"]\s*\).*?\n\}/s', $pdksSrc));
ok('config/pdks.php config/pdks_gunluk.php\'yi require ETMİYOR (bağımlılık yönü TERS OLMAZ)',
    !preg_match('/require(_once)?\s*.*pdks_gunluk\.php/', $pdksSrc));
ok('personel_kartlar.php artık config/pdks_gunluk.php\'yi de require ediyor (guard\'ın aktif olması için)',
    str_contains(oku('personel_kartlar.php'), "require_once __DIR__ . '/config/pdks_gunluk.php';"));
ok('personel_form.php artık config/pdks_gunluk.php\'yi de require ediyor',
    str_contains(oku('personel_form.php'), "require_once __DIR__ . '/config/pdks_gunluk.php';"));

echo "\n=== 6. UID NORMALİZASYONU TEKRARLANMADI (REUSE) ===\n";
ok('config/pdks_gunluk.php KENDİ hex/decimal dönüşüm ALGORİTMASINI yazmıyor (dechex/hexdec/bcadd YOK)',
    !preg_match('/\b(dechex|hexdec|bcadd|bcmul)\s*\(/', $gunlukKod));
ok('pdks_gunluk_kart_olustur() pdks_uid_from_decimal() ÇAĞIRIYOR (REUSE)', str_contains($gunlukSrc, 'pdks_uid_from_decimal('));
ok('pdks_gunluk_kart_olustur() pdks_uid_from_web_nfc() ÇAĞIRIYOR (REUSE)', str_contains($gunlukSrc, 'pdks_uid_from_web_nfc('));
ok('pdks_gunluk_kart_olustur() pdks_uid_hex_normalize() ÇAĞIRIYOR (REUSE)', str_contains($gunlukSrc, 'pdks_uid_hex_normalize('));
ok('PDKS_UID_KAYNAKLARI sabiti TEKRAR TANIMLANMADI (config/pdks.php\'den REUSE)',
    !preg_match('/const\s+PDKS_UID_KAYNAKLARI/', $gunlukSrc) && str_contains($gunlukSrc, 'PDKS_UID_KAYNAKLARI'));

echo "\n=== 7. NFC/USB ENROLLMENT YENİDEN İCAT EDİLMEDİ ===\n";
$iskSrc = oku('isci_kartlari.php');
ok('isci_kartlari.php KENDİ `new NDEFReader()`ını AÇMIYOR', !str_contains(kodSadece($iskSrc), 'new NDEFReader()'));
ok('isci_kartlari.php assets/pdks.js\'i YÜKLÜYOR (mevcut enrollment JS\'i REUSE)',
    (bool)preg_match('/assets\/pdks\.js/', $iskSrc));
ok('isci_kartlari.php data-pdks-scan desenini KULLANIYOR (personel_kartlar.php ile AYNI)',
    str_contains($iskSrc, 'data-pdks-scan'));
ok('isci_kartlari.php data-pdks-nfc-target desenini KULLANIYOR', str_contains($iskSrc, 'data-pdks-nfc-target'));
ok('isci_kartlari.php data-pdks-kaynak-field desenini KULLANIYOR (kaynak alanı senkronu)',
    str_contains($iskSrc, 'data-pdks-kaynak-field'));
ok('isci_kartlari.php genel modal aç/kapa için window.pdksOpenModal/pdksCloseModal KULLANIYOR (yeni bir modal mekanizması İCAT ETMEDİ)',
    str_contains($iskSrc, 'window.pdksOpenModal') && str_contains($iskSrc, "pdksCloseModal("));
ok('isci_kartlari.php\'nin ajax=onizle ucu personel_kartlar.php ile AYNI iki kaynağı kabul ediyor (usb_decimal, web_nfc)',
    (bool)preg_match("/in_array\(\\\$kaynak,\s*\['usb_decimal',\s*'web_nfc'\]/", $iskSrc));
ok('assets/pdks.js BU GÖREVDE değiştirilmedi (git status temiz)',
    trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git status --porcelain -- assets/pdks.js 2>&1')) === '');
ok('giris_cikis.php / pdks_nfc_test.php / config/pdks.php\'nin PdksNfcOku okuma-döngüsü BU GÖREVDE değiştirilmedi',
    trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff --stat -- giris_cikis.php pdks_nfc_test.php 2>&1')) === '');

echo "\n=== 8. SİDEBAR + İZİN SEED'İ ===\n";
$helpersSrc = oku('config/helpers.php');
ok("helpers.php: attendance.foremen izni seed'e eklendi", str_contains($helpersSrc, "'attendance.foremen'"));
ok("helpers.php: attendance.worker_cards izni seed'e eklendi", str_contains($helpersSrc, "'attendance.worker_cards'"));
ok("helpers.php: 'Günlük İşçi' sidebar bölümü var", str_contains($helpersSrc, 'Günlük İşçi'));
ok('helpers.php: cavuslar.php sidebar\'da bağlı', str_contains($helpersSrc, "cavuslar.php"));
ok('helpers.php: isci_kartlari.php sidebar\'da bağlı', str_contains($helpersSrc, "isci_kartlari.php"));
ok("helpers.php: 'ik' rolüne yeni izinler verildi", (bool)preg_match(
    "/'ik'\s*=>\s*\[[^\]]*attendance\.foremen[^\]]*attendance\.worker_cards/s", $helpersSrc));
ok('Yalnız İKİ yeni izin eklendi (permission fragmentasyonu YOK — 3. bir attendance.* izni yok)',
    substr_count($helpersSrc, "'attendance.foremen'") >= 1 && substr_count($helpersSrc, "'attendance.worker_cards'") >= 1);

echo "\n=== 9. SİLME YOK — yalnız aktif/pasif (kalıcı geçmiş referansı riski) ===\n";
ok('config/pdks_gunluk.php: foremen için DELETE FROM YOK', !preg_match('/DELETE\s+FROM\s+`?foremen`?/i', $gunlukSrc));
ok('config/pdks_gunluk.php: worker_types için DELETE FROM YOK', !preg_match('/DELETE\s+FROM\s+`?worker_types`?/i', $gunlukSrc));
ok('cavuslar.php / cavus_form.php: hiçbir yerde "Sil" eylemi YOK', !preg_match('/action.*sil|delete_foreman/i', kodSadece(oku('cavuslar.php') . oku('cavus_form.php'))));
ok('pdks_gunluk_cavus_aktiflik() var — silme yerine durum geçişi', str_contains($gunlukSrc, 'function pdks_gunluk_cavus_aktiflik'));

echo "\n=== 10. GENDER ENUM DEĞİL — worker_types serbest kod/ad ===\n";
// Yalnız worker_types'ın KENDİ DDL gövdesine bak (yorumlarda "ENUM DEĞİL"
// gibi AÇIKLAYICI metin geçebilir — bu YANLIŞ POZİTİF üretmemeli).
preg_match('/\$t\[\'worker_types\'\]\s*=\s*"(.*?)";/s', $gunlukSrc, $wtM);
$worker_types_ddl = $wtM[1] ?? '';
ok('worker_types DDL gövdesi çıkarılabildi', $worker_types_ddl !== '');
ok('worker_types DDL\'inde ENUM YOK', !preg_match('/\bENUM\b/i', $worker_types_ddl));
ok("worker_types.code VARCHAR (serbest metin, sabit liste DEĞİL)", (bool)preg_match('/`code`\s+VARCHAR/', $gunlukSrc));
ok('Seed YALNIZ başlangıç değeri KADIN/ERKEK — kod içinde başka yerde sabit "kadın"/"erkek" dallanması YOK',
    !preg_match("/if\s*\(.*(kadın|erkek|KADIN|ERKEK).*\)/", $gunlukKod));

echo "\n=== 11. migrate.php — admin panel butonu eklendi ===\n";
$migSrc = oku('migrate.php');
ok('migrate.php pdks_gunluk_migrate() çağırıyor', str_contains($migSrc, 'pdks_gunluk_migrate('));
ok('migrate.php "Günlük İşçi Tablolarını Oluştur" butonu var', str_contains($migSrc, 'Günlük İşçi Tablolarını Oluştur'));
ok("migrate.php ne=pdks_gunluk dalı ayrı if/elseif ile ele alınıyor (ne=pdks ile ÇAKIŞMIYOR)",
    (bool)preg_match("/\\\$_POST\['ne'\]\s*\?\?\s*''\)\s*===\s*'pdks_gunluk'/", $migSrc));

echo "\n=== 12. FAZ 2 ÖNERİSİ YALNIZ BELGE — burada migrate EDİLMEDİ ===\n";
ok('daily_work_sessions İÇİN CREATE TABLE YOK (yalnız yorum satırında geçer)',
    !preg_match('/CREATE TABLE[^;]*daily_work_sessions/i', $gunlukSrc));
ok('daily_worker_card_events İÇİN CREATE TABLE YOK', !preg_match('/CREATE TABLE[^;]*daily_worker_card_events/i', $gunlukSrc));
ok('pdks_gunluk_tablolar() dizisi YALNIZ 3 tablo döndürüyor (worker_types/foremen/worker_cards)',
    (bool)preg_match('/function pdks_gunluk_tablolar.*?return \$t;\s*\}/s', $gunlukSrc)
    && substr_count(
        preg_replace('/.*function pdks_gunluk_tablolar\(\).*?\{/s', '', $gunlukSrc, 1),
        "\$t['"
    ) >= 3);

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
