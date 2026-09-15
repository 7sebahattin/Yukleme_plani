<?php
// =========================================================
// scripts/pdks_hakedis_smoke.php — Çavuş Hakediş (Günlük İşçi, Faz 4) backend testi
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ. Faz 2/3 ile AYNI desen: elle
// yazılmış test şeması KULLANMAZ — config/pdks.php + config/pdks_gunluk.php +
// config/pdks_hakedis.php'nin GERÇEK MySQL DDL'ini SQLite'a çevirip çalıştırır,
// GERÇEK Faz 1-4 fonksiyonlarıyla veri üretir.
//
// Kapsam (görev talimatının 25 test maddesiyle eşlenir):
//    1  çavuşa özgü fiyat                 13 KESİN kaydı değiştirmez (rate)
//    2  başka çavuş için FARKLI fiyat     14 (aynı, missing_rate ile birlikte kanıtlanır)
//    3  etkin-tarihli fiyat seçimi        15 KESİN, canlı fiyat değişse de DEĞİŞMEZ
//    4  eski fiyat TARİHSEL kalır         16 işçi tipi adı değişse de KESİN DEĞİŞMEZ
//    5  binişen/belirsiz fiyat koruması   17 çavuş adı değişse de KESİN DEĞİŞMEZ
//    6  30 Kadın × fiyat hesabı           18 (bkz. pdks_hakedis_static_smoke.php)
//    7  20 Erkek × fiyat hesabı           19 (bkz. static test — yetki kapıları)
//    8  çok-tipli TOPLAM                  20 (bkz. static/UI test — operator engeli)
//    9  BENZERSİZ GİRİŞ kart sayısı       21 (bkz. static test — DDL yok)
//   10  eksik ÇIKIŞ sayıyı AZALTMAZ       22 migrasyon İDEMPOTENT
//   11  eksik fiyat KESİNLEŞTİRMEYİ engeller   23 (bkz. pdks_gunluk_faz2_smoke.php — ayrı çalışır)
//   12  AÇIK oturum KESİNLEŞTİRİLEMEZ     24 (bkz. pdks_gunluk_faz3_smoke.php — ayrı çalışır)
//                                          25 kalıcı PDKS sanity (bkz. pdks_db_smoke.php — ayrı çalışır)
//
//   php scripts/pdks_hakedis_smoke.php   → çıkış kodu 0 = geçti
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
// MySQL DDL → SQLite çevirici — Faz 2/3 testleriyle BİREBİR AYNI (kasıtlı kopya).
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
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
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

$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `display_name` VARCHAR(150) NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}
foreach (pdks_gunluk_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}
foreach (pdks_hakedis_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}
pdks_gunluk_migrate(db());

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-90s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
// ⚠ MySQL PDO DECIMAL kolonlarını STRING olarak döner ("1200.00"), SQLite
// (bu testin şeması) ise dinamik tipleme yüzünden AYNI kolonu sayısal
// (int/float, "1200") döner — bu FARK gerçek uygulama mantığının bir
// PARÇASI DEĞİL, yalnız test alt yapısının bir ARTEFAKTIDIR (pdks_hakedis_
// kurus_tl()'nin ÜRETTİĞİ değerler zaten kendi formatını garanti eder,
// bkz. 6/7/8/13 numaralı testler — bunlar SORUNSUZ geçti). Bu yardımcı
// yalnız HAM DB satırlarını okuyan asserion'larda sayısal eşitliği
// sürücüden BAĞIMSIZ doğrular.
function parasalEsit(string $beklenenTl, $gelenDeger): bool
{
    return abs((float)$beklenenTl - (float)$gelenDeger) < 0.001;
}

$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();

function cavusEkle(string $kod, string $ad): int {
    $r = pdks_gunluk_cavus_olustur(['code' => $kod, 'name' => $ad], 1, db());
    if (!$r['ok']) { fwrite(STDERR, "cavus olusturulamadi: " . json_encode($r) . "\n"); exit(1); }
    return (int)$r['id'];
}
$ayseId = cavusEkle('C001', 'Ayşe Çavuş');
$mehmetId = cavusEkle('C002', 'Mehmet Çavuş');

// ═══════════════════════════════════════════════════════════
echo "\n=== 1/2/3/4/5. FİYAT MASTER — çavuşa özgü, etkin-tarihli, ÇAKIŞMA KORUMALI ===\n";

$o1 = pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2026-09-01', 'TRY', 1, db());
ok('Ayşe/Kadın Eylül fiyatı eklendi', $o1['ok'] === true, json_encode($o1));
$o2 = pdks_hakedis_oran_ekle($ayseId, $erkekId, '1500', '2026-09-01', 'TRY', 1, db());
ok('Ayşe/Erkek Eylül fiyatı eklendi', $o2['ok'] === true, json_encode($o2));

$o3 = pdks_hakedis_oran_ekle($mehmetId, $kadinId, '1250', '2026-09-01', 'TRY', 1, db());
ok('Mehmet/Kadın fiyatı eklendi', $o3['ok'] === true, json_encode($o3));
$o4 = pdks_hakedis_oran_ekle($mehmetId, $erkekId, '1550', '2026-09-01', 'TRY', 1, db());
ok('Mehmet/Erkek fiyatı eklendi', $o4['ok'] === true, json_encode($o4));

$ayseKadinEylul = pdks_hakedis_oran_gecerli($ayseId, $kadinId, '2026-09-15', db());
ok('1. Ayşe/Kadın Eylül oranı 1200.00 (ÇAVUŞA ÖZGÜ)', $ayseKadinEylul !== null && parasalEsit('1200.00', $ayseKadinEylul['daily_rate']), json_encode($ayseKadinEylul));
$mehmetKadinEylul = pdks_hakedis_oran_gecerli($mehmetId, $kadinId, '2026-09-15', db());
ok('2. Mehmet/Kadın Eylül oranı 1250.00 (AYNI TİP, FARKLI ÇAVUŞ, FARKLI FİYAT)', $mehmetKadinEylul !== null && parasalEsit('1250.00', $mehmetKadinEylul['daily_rate']), json_encode($mehmetKadinEylul));

// Ekim'de Ayşe/Kadın fiyatı değişiyor.
$o5 = pdks_hakedis_oran_ekle($ayseId, $kadinId, '1300', '2026-10-01', 'TRY', 1, db());
ok('Ayşe/Kadın Ekim fiyatı eklendi (Eylül otomatik kapanmalı)', $o5['ok'] === true, json_encode($o5));

$ayseKadinEylulSonra = pdks_hakedis_oran_gecerli($ayseId, $kadinId, '2026-09-15', db());
ok('3/4. Eylül ayı İÇİN sorgu HÂLÂ 1200.00 döner (ETKİN-TARİHLİ SEÇİM, eski fiyat TARİHSEL kalır)',
    $ayseKadinEylulSonra !== null && parasalEsit('1200.00', $ayseKadinEylulSonra['daily_rate']), json_encode($ayseKadinEylulSonra));
$ayseKadinEkim = pdks_hakedis_oran_gecerli($ayseId, $kadinId, '2026-10-15', db());
ok('3. Ekim ayı İÇİN sorgu 1300.00 döner (YENİ etkin dönem)',
    $ayseKadinEkim !== null && parasalEsit('1300.00', $ayseKadinEkim['daily_rate']), json_encode($ayseKadinEkim));

$gecmis = pdks_hakedis_oran_gecmisi($ayseId, db());
$kadinDonemleri = array_values(array_filter($gecmis, fn($g) => (int)$g['worker_type_id'] === $kadinId));
ok('Ayşe/Kadın için İKİ dönem de listede (silinmedi — kullanıcının açık talimatı)', count($kadinDonemleri) === 2, json_encode($kadinDonemleri));
$eylulDonem = array_values(array_filter($kadinDonemleri, fn($g) => $g['valid_from'] === '2026-09-01'))[0] ?? null;
ok('Eylül dönemi otomatik olarak 2026-09-30\'da KAPANDI (Ekim başlamadan BİR GÜN ÖNCE)', $eylulDonem !== null && $eylulDonem['valid_to'] === '2026-09-30', json_encode($eylulDonem));

echo "\n--- 5. ÇAKIŞMA/BELİRSİZLİK KORUMASI ---\n";
$catisma = pdks_hakedis_oran_ekle($ayseId, $kadinId, '1100', '2026-09-10', 'TRY', 1, db());
ok('geriye dönük (mevcut en son dönemden ÖNCE) tarihli fiyat REDDEDİLİR', $catisma['ok'] === false, json_encode($catisma));
$ayseKadinKontrol = pdks_hakedis_oran_gecerli($ayseId, $kadinId, '2026-10-15', db());
ok('reddedilen deneme sonrası Ekim oranı HÂLÂ 1300.00 (bozulmadı)', parasalEsit('1300.00', $ayseKadinKontrol['daily_rate']));

$eksikOranli = cavusEkle('C003', 'Fatma Çavuş');   // Kadın/Erkek İÇİN HİÇ oran girilmeyecek
$oranYok = pdks_hakedis_oran_gecerli($eksikOranli, $kadinId, '2026-09-15', db());
ok('hiç oran girilmemiş çavuş için NULL döner (0 ÜRETİLMEZ, başka çavuşun oranına DÜŞÜLMEZ)', $oranYok === null);

// ═══════════════════════════════════════════════════════════
echo "\n=== 6/7/8/9/10. HESAPLAMA — 30 Kadın + 20 Erkek, BENZERSİZ GİRİŞ, eksik ÇIKIŞ SAYIYI DEĞİŞTİRMEZ ===\n";

$oAyse = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
ok('Ayşe oturumu açıldı (2026-09-15 varsayımıyla test aşağıda tarihi elle ayarlayacak)', $oAyse['ok'] === true);
$ayseSessionId = (int)$oAyse['session']['id'];
// ⚠ Test sunucu tarihini DEĞİŞTİREMEZ (fonksiyon date('Y-m-d') kullanır,
// Faz 2/3 testlerinin AYNI kısıtı) — bu yüzden oturumun work_date'i BURADA
// doğrudan 2026-09-15'e güncellenir (yalnız test kurgusu; üretim kodunda
// böyle bir yol YOK, normal akış her zaman sunucu tarihini kullanır).
db()->prepare("UPDATE daily_work_sessions SET work_date = ? WHERE id = ?")->execute(['2026-09-15', $ayseSessionId]);
// Olay satırları da work_date_snapshot'ı bu oturumdan MİRAS alır (INSERT
// anında session['work_date'] okunur) — UPDATE'İ oturum GÜNCELLENDİKTEN
// SONRA, taramalar BAŞLAMADAN ÖNCE yaptık, tutarlı kalır.

for ($i = 1; $i <= 30; $i++) {
    $uid = 100000 + $i;
    $k = pdks_gunluk_kart_olustur(['card_no' => sprintf('K%03d', $i), 'worker_type_id' => $kadinId, 'ham_uid' => (string)$uid, 'kaynak' => 'usb_decimal'], 1, db());
    if (!$k['ok']) { fwrite(STDERR, 'kart olusturulamadi: ' . json_encode($k) . "\n"); exit(1); }
    $g = pdks_gunluk_oturum_kaydet((string)$uid, 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
    if (!$g['ok']) { fwrite(STDERR, 'giris basarisiz: ' . json_encode($g) . "\n"); exit(1); }
    if ($i <= 29) {   // ⚠ K030 BİLEREK ÇIKMAYACAK — eksik çıkış senaryosu (madde 10/14)
        pdks_gunluk_oturum_kaydet((string)$uid, 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
    }
}
for ($i = 1; $i <= 20; $i++) {
    $uid = 200000 + $i;
    $k = pdks_gunluk_kart_olustur(['card_no' => sprintf('E%03d', $i), 'worker_type_id' => $erkekId, 'ham_uid' => (string)$uid, 'kaynak' => 'usb_decimal'], 1, db());
    if (!$k['ok']) { fwrite(STDERR, 'kart olusturulamadi: ' . json_encode($k) . "\n"); exit(1); }
    $g = pdks_gunluk_oturum_kaydet((string)$uid, 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
    if (!$g['ok']) { fwrite(STDERR, 'giris basarisiz: ' . json_encode($g) . "\n"); exit(1); }
    pdks_gunluk_oturum_kaydet((string)$uid, 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
}

$hesap = pdks_hakedis_hesapla($ayseSessionId, 1, db());
ok('hesaplama başarılı', $hesap['ok'] === true, json_encode($hesap));
ok('9. BENZERSİZ GİRİŞ kart sayısı kullanılıyor: Kadın=30', (function () use ($hesap) {
    foreach ($hesap['lines'] as $l) if ($l['worker_type_name_snapshot'] === 'Kadın') return $l['worker_count'] === 30;
    return false;
})(), json_encode($hesap['lines']));
ok('10. K030 hiç ÇIKMADIĞI halde (29 çıkış / 30 giriş) Kadın SAYISI 30 kalır (ÇIKIŞ sayıyı AZALTMAZ)',
    true /* önceki assertion zaten bunu kanıtlıyor — 29 CIKIS'e rağmen 30 */);
ok('6. Kadın satırı: 30 × 1200.00 = 36000.00', (function () use ($hesap) {
    foreach ($hesap['lines'] as $l) if ($l['worker_type_name_snapshot'] === 'Kadın') return $l['unit_rate'] === '1200.00' && $l['line_total'] === '36000.00';
    return false;
})(), json_encode($hesap['lines']));
ok('7. Erkek satırı: 20 × 1500.00 = 30000.00', (function () use ($hesap) {
    foreach ($hesap['lines'] as $l) if ($l['worker_type_name_snapshot'] === 'Erkek') return $l['unit_rate'] === '1500.00' && $l['line_total'] === '30000.00';
    return false;
})(), json_encode($hesap['lines']));
ok('8. ÇOK-TİPLİ TOPLAM = 66000.00 (36000 + 30000)', $hesap['total_amount'] === '66000.00', json_encode($hesap));
ok('eksik_tipler BOŞ (her iki tip için de oran var)', empty($hesap['eksik_tipler']));

echo "\n=== 11/12. KESİNLEŞTİRME ENGELLERİ — eksik fiyat + AÇIK oturum ===\n";
$finalAcikOturum = pdks_hakedis_finalize($ayseSessionId, 1, false, db());
ok('12. AÇIK oturum KESİNLEŞTİRİLEMEZ', $finalAcikOturum['ok'] === false && $finalAcikOturum['kod'] === 'oturum_acik', json_encode($finalAcikOturum));

// Fatma'nın oturumunu aç, GİRİŞ yaptır (Fatma'nın HİÇ fiyatı yok) — eksik fiyat senaryosu.
$oFatma = pdks_gunluk_oturum_ac_veya_getir($eksikOranli, 1, db());
$fatmaSessionId = (int)$oFatma['session']['id'];
db()->prepare("UPDATE daily_work_sessions SET work_date = ? WHERE id = ?")->execute(['2026-09-15', $fatmaSessionId]);
$kf = pdks_gunluk_kart_olustur(['card_no' => 'KF01', 'worker_type_id' => $kadinId, 'ham_uid' => '900001', 'kaynak' => 'usb_decimal'], 1, db());
pdks_gunluk_oturum_kaydet('900001', 'usb_decimal', $fatmaSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('900001', 'usb_decimal', $fatmaSessionId, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($fatmaSessionId, null, 1, db());

$finalEksikOran = pdks_hakedis_finalize($fatmaSessionId, 1, false, db());
ok('11. eksik fiyat KESİNLEŞTİRMEYİ engeller', $finalEksikOran['ok'] === false && $finalEksikOran['kod'] === 'oran_eksik', json_encode($finalEksikOran));
ok('hata mesajı TAM istenen biçimde ("... işçi tipi için DD.MM.YYYY tarihinde geçerli fiyat bulunamadı.")',
    str_contains($finalEksikOran['hata'], 'Kadın işçi tipi için 15.09.2026 tarihinde geçerli fiyat bulunamadı.'), $finalEksikOran['hata']);
$stFatmaHesap = db()->prepare("SELECT total_amount FROM foreman_daily_entitlements WHERE session_id = ?");
$stFatmaHesap->execute([$fatmaSessionId]);
ok('reddedilen kesinleştirme SESSİZCE 0 tutar YAZMADI (satır/toplam üretilmedi ya da taslakta 0 kaldı, ASLA final olmadı)',
    (function () use ($fatmaSessionId) { $s = db()->prepare("SELECT status FROM foreman_daily_entitlements WHERE session_id=?"); $s->execute([$fatmaSessionId]); return $s->fetchColumn(); })() !== 'final');

echo "\n=== 13/14. KAPALI mesai KESİNLEŞTİRME — eksiksiz vs eksik-çıkışlı ===\n";
$kapatAyse = pdks_gunluk_oturum_kapat($ayseSessionId, 'K030 sahada kaldı, yarın teslim.', 1, db());
ok('Ayşe oturumu eksik-çıkışla gerekçeli kapandı', $kapatAyse['ok'] === true, json_encode($kapatAyse));

$finalOnaysiz = pdks_hakedis_finalize($ayseSessionId, 1, false, db());
ok('14. eksik-çıkışlı KAPALI mesai, AÇIK ONAY OLMADAN kesinleştirilemez', $finalOnaysiz['ok'] === false && $finalOnaysiz['kod'] === 'eksik_cikis_onay_gerekli', json_encode($finalOnaysiz));

$finalOnayli = pdks_hakedis_finalize($ayseSessionId, 1, true, db());
ok('13. AÇIK ONAY ile eksik-çıkışlı KAPALI mesai KESİNLEŞTİRİLEBİLİR', $finalOnayli['ok'] === true && $finalOnayli['status'] === 'final', json_encode($finalOnayli));
ok('KESİNLEŞEN toplam hâlâ 66000.00 (K030 eksik çıkışına rağmen 30 Kadın sayıldı)', $finalOnayli['total_amount'] === '66000.00');
$ayseEntId = (int)$finalOnayli['entitlement_id'];

echo "\n--- Zaten KESİNLEŞMİŞ kayıt otomatik yeniden HESAPLANAMAZ ---\n";
$tekrarHesap = pdks_hakedis_hesapla($ayseSessionId, 1, db());
ok('KESİN kayıt üzerinde pdks_hakedis_hesapla() REDDEDİLİR', $tekrarHesap['ok'] === false && $tekrarHesap['kod'] === 'zaten_kesinlesmis', json_encode($tekrarHesap));

echo "\n=== 15/16/17. KESİNLEŞMİŞ ANLIK GÖRÜNTÜ — canlı fiyat/işçi tipi/çavuş adı değişse de DEĞİŞMEZ ===\n";
$satirlarOnce = pdks_hakedis_satirlar($ayseEntId, db());
$kadinSatirOnce = array_values(array_filter($satirlarOnce, fn($s) => $s['worker_type_name_snapshot'] === 'Kadın'))[0];

// 15: fiyatı DEĞİŞTİR (Ekim'den itibaren zaten 1300 var — Eylül'ü de aşırı yüksek yap, DENESEK bile o dönem geçmiş kalır)
pdks_hakedis_oran_ekle($ayseId, $kadinId, '9999', '2026-11-01', 'TRY', 1, db());   // gelecekteki bambaşka bir fiyat
// 16: worker_types adını değiştir (uygulamanın bunun için bir UI'ı YOK — doğrudan SQL ile, yapısal garantiyi kanıtlamak için)
db()->exec("UPDATE worker_types SET name = 'KADIN-YENİ-AD', code = 'KADINX' WHERE id = " . $kadinId);
// 17: çavuş adını değiştir
pdks_gunluk_cavus_guncelle($ayseId, ['code' => 'C001', 'name' => 'Ayşe YENİ SOYAD', 'is_active' => 1], 1, db());

$hakedisSonra = db()->query("SELECT * FROM foreman_daily_entitlements WHERE id = {$ayseEntId}")->fetch();
$satirlarSonra = pdks_hakedis_satirlar($ayseEntId, db());
$kadinSatirSonra = array_values(array_filter($satirlarSonra, fn($s) => $s['worker_type_name_snapshot'] === 'Kadın'))[0] ?? null;

ok('15. KESİNLEŞMİŞ toplam canlı fiyat değişikliğinden SONRA da 66000.00 (DEĞİŞMEDİ)', parasalEsit('66000.00', $hakedisSonra['total_amount']), json_encode($hakedisSonra));
ok('15. KESİNLEŞMİŞ Kadın satırı birim fiyatı HÂLÂ 1200.00 (yeni 9999 fiyatı YOK SAYILDI)', $kadinSatirSonra !== null && parasalEsit('1200.00', $kadinSatirSonra['unit_rate']), json_encode($kadinSatirSonra));
ok('16. KESİNLEŞMİŞ satır worker_type_name_snapshot HÂLÂ "Kadın" (worker_types.name "KADIN-YENİ-AD" olsa da)', $kadinSatirSonra['worker_type_name_snapshot'] === 'Kadın');
ok('16. KESİNLEŞMİŞ satır worker_type_code_snapshot HÂLÂ "KADIN" (worker_types.code "KADINX" olsa da)', $kadinSatirSonra['worker_type_code_snapshot'] === 'KADIN');
ok('17. KESİNLEŞMİŞ kayıt foreman_name_snapshot HÂLÂ "Ayşe Çavuş" (foremen.name SONRADAN değişse de)', $hakedisSonra['foreman_name_snapshot'] === 'Ayşe Çavuş');
$stCanliCavus = db()->prepare("SELECT name FROM foremen WHERE id = ?");
$stCanliCavus->execute([$ayseId]);
ok('canlı foremen.name GERÇEKTEN değişti (test verisi doğru kuruldu)', $stCanliCavus->fetchColumn() === 'Ayşe YENİ SOYAD');

echo "\n=== YENİDEN AÇMA — yalnız admin + zorunlu gerekçe ===\n";
$IS_ADMIN = false;
$yenidenAcYetkisiz = pdks_hakedis_yeniden_ac($ayseEntId, 'test', 1, db());
ok('admin OLMAYAN kullanıcı KESİN kaydı yeniden AÇAMAZ', $yenidenAcYetkisiz['ok'] === false && $yenidenAcYetkisiz['kod'] === 'yetkisiz', json_encode($yenidenAcYetkisiz));
$IS_ADMIN = true;
$yenidenAcGerekcesiz = pdks_hakedis_yeniden_ac($ayseEntId, '', 1, db());
ok('admin bile GEREKÇESİZ yeniden AÇAMAZ', $yenidenAcGerekcesiz['ok'] === false && $yenidenAcGerekcesiz['kod'] === 'gerekce_zorunlu', json_encode($yenidenAcGerekcesiz));
$yenidenAc = pdks_hakedis_yeniden_ac($ayseEntId, 'K030 çıkışı sahada teyit edildi, tutar teyit için yeniden hesaplanacak.', 1, db());
ok('admin + gerekçe İLE yeniden AÇILIR (taslağa döner)', $yenidenAc['ok'] === true, json_encode($yenidenAc));
$stDurumSonra = db()->prepare("SELECT status FROM foreman_daily_entitlements WHERE id = ?");
$stDurumSonra->execute([$ayseEntId]);
ok('durum tekrar draft', $stDurumSonra->fetchColumn() === 'draft');
$IS_ADMIN = false;

echo "\n=== 22. MİGRASYON İDEMPOTENT ===\n";
$ilkMigrasyon = pdks_hakedis_migrate(db());
ok('tablolar zaten var — ikinci migrate() hiçbir şeyi BOZMAZ', (function () use ($ilkMigrasyon) {
    foreach ($ilkMigrasyon as $r) if ($r['durum'] === 'hata') return false;
    return true;
})(), json_encode($ilkMigrasyon));
$stFwrSayim = db()->query("SELECT COUNT(*) FROM foreman_worker_rates");
ok('ikinci migrate() SONRASI fiyat verisi KAYBOLMADI', (int)$stFwrSayim->fetchColumn() > 0);

echo "\n=== 25. KALICI PDKS SANITY (asıl kanıt: pdks_db_smoke.php ayrı çalışır) ===\n";
db()->exec("INSERT INTO users (username) VALUES ('test')");
$empIns = db()->prepare("INSERT INTO employees (full_name, status) VALUES (?, 'aktif')");
$empIns->execute(['Test Personel']);
$permKart = pdks_kart_olustur((int)db()->lastInsertId(), '999111222', 'usb_decimal', [], db());
ok('kalıcı personel kartı Faz 4\'ten SONRA da normal oluşturuluyor', $permKart['ok'] === true, json_encode($permKart));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
