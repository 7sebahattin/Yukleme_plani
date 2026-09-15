<?php
// =========================================================
// scripts/pdks_rapor_smoke.php — Yönetim Raporlama Merkezi (Faz 6) backend testi
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ. Faz 2-5 ile AYNI desen: GERÇEK
// MySQL DDL'ini SQLite'a çevirip çalıştırır, GERÇEK Faz 1-6 fonksiyonlarıyla
// veri üretir — kendi paralel bir hesap/sayaç mantığı YAZMAZ.
//
// Kapsam (görev talimatının 38 test maddesiyle eşlenir — 1-26 ve 30 burada;
// 27-29 pdks_rapor_static_smoke.php + pdks_rapor_ui_smoke.php'de; 31
// pdks_rapor_ui_smoke.php'de; 32-33 pdks_rapor_static_smoke.php'de; 34-38
// AYRI paketlerin YENİDEN çalıştırılmasıyla — bkz. regresyon turu):
//    1  GİRİŞ+ÇIKIŞ TEK işçi sayılır          14 güncel bakiye ÖNCEKİ dönemleri de kapsar
//    2  aynı kart FARKLI günde AYRI sayılır   15 dönem net ≠ güncel bakiye (geçmiş varsa)
//    3  işçi tipi toplamları doğru            16 TRY/EUR AYRIŞIK
//    4  dinamik (KADIN/ERKEK dışı) tip dahil  17 çapraz para birimi toplamı YOK
//    5  çavuş sayıları doğru                  18 negatif bakiye = avans/fazla ödeme
//    6  eksik çıkışlar doğru                  19 çavuş adı değişse de geçmiş rapor DEĞİŞMEZ
//    7  açık mesailer doğru                   20 işçi tipi adı değişse de geçmiş rapor DEĞİŞMEZ
//    8  KESİN hakediş dahil                   21 tarih filtresi doğru
//    9  TASLAK hakediş HARİÇ                  22 depo filtresi doğru
//   10  iptal ödeme HARİÇ                     23 çavuş filtresi doğru
//   11  geçerli ödeme dahil                   24 işçi tipi filtresi doğru
//   12  dönem hakedişi work_date kullanır     25 karşılaştırma dönemi doğru
//   13  ödeme payment_date kullanır           26 önceki-sıfır karşılaştırması güvenli
//                                              30 admin/muhasebe finansal raporlama çalışır
//
//   php scripts/pdks_rapor_smoke.php   → çıkış kodu 0 = geçti
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
$PERMS = ['attendance.management_reports', 'attendance.foreman_accounts', 'attendance.foreman_payments'];
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
$IS_ADMIN = false;
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
$AKTIF_DEPO = 'Depo A';
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }

require_once $KOK . '/config/pdks.php';
require_once $KOK . '/config/pdks_gunluk.php';
require_once $KOK . '/config/pdks_hakedis.php';
require_once $KOK . '/config/pdks_cari.php';
require_once $KOK . '/config/pdks_rapor.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — Faz 2-5 testleriyle BİREBİR AYNI (kasıtlı kopya).
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
foreach (pdks_cari_tablolar() as $ad => $sql) {
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
function parasalEsit(string $beklenenTl, $gelenDeger): bool
{
    return abs((float)$beklenenTl - (float)$gelenDeger) < 0.001;
}

$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$paketlemeSonuc = pdks_gunluk_tip_olustur('PAKETLEME', 'Paketleme', db());
if (!$paketlemeSonuc['ok']) { fwrite(STDERR, 'paketleme tipi olusturulamadi: ' . json_encode($paketlemeSonuc) . "\n"); exit(1); }
$paketlemeId = (int)$paketlemeSonuc['id'];

function cavusEkle(string $kod, string $ad): int {
    $r = pdks_gunluk_cavus_olustur(['code' => $kod, 'name' => $ad], 1, db());
    if (!$r['ok']) { fwrite(STDERR, "cavus olusturulamadi: " . json_encode($r) . "\n"); exit(1); }
    return (int)$r['id'];
}
$ayseId = cavusEkle('C001', 'Ayşe Çavuş');
$mehmetId = cavusEkle('C002', 'Mehmet Çavuş');

/** Bir çavuş için, verilen tarihte/depoda YENİ bir oturum açar; dönen
 *  session_id ÜZERİNDE testler kendi kart GİRİŞ/ÇIKIŞ senaryosunu kurar. */
function oturumAc(int $foremanId, string $tarih, string $depo = 'Depo A'): int {
    $stF = db()->prepare("SELECT name, code FROM foremen WHERE id=?"); $stF->execute([$foremanId]); $f = $stF->fetch();
    $ins = db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id) VALUES (?,?,?,?,?,?,?,?)");
    $ins->execute([$foremanId, $f['name'], $f['code'], $tarih, $depo, 'open', $tarih . ' 08:00:00', 1]);
    return (int)db()->lastInsertId();
}
/** AYNI UID farklı günlerde tekrar çağrılabilir — kart yalnız İLK seferinde
 *  oluşturulur (gerçek dünyada da bir işçi kartı BİR KEZ zimmetlenir, her
 *  gün YENİDEN OLUŞTURULMAZ; test #2 tam olarak bunu sınıyor). */
function kartGiris(int $sessionId, string $uid, int $tipId): void {
    $mevcut = function_exists('pdks_gunluk_kart_coz') ? pdks_gunluk_kart_coz(pdks_uid_from_decimal($uid), db()) : null;
    if ($mevcut === null) {
        $k = pdks_gunluk_kart_olustur(['card_no' => 'K' . $uid, 'worker_type_id' => $tipId, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
        if (!$k['ok']) { fwrite(STDERR, "kart olusturulamadi ($uid): " . json_encode($k) . "\n"); exit(1); }
    }
    $g = pdks_gunluk_oturum_kaydet($uid, 'usb_decimal', $sessionId, 'GIRIS', 1, db());
    if (!$g['ok']) { fwrite(STDERR, "giris basarisiz ($uid): " . json_encode($g) . "\n"); exit(1); }
}
function kartCikis(string $uid, int $sessionId): void {
    $c = pdks_gunluk_oturum_kaydet($uid, 'usb_decimal', $sessionId, 'CIKIS', 1, db());
    if (!$c['ok']) { fwrite(STDERR, "cikis basarisiz ($uid): " . json_encode($c) . "\n"); exit(1); }
}

// ═══════════════════════════════════════════════════════════
echo "\n=== 1. GİRİŞ+ÇIKIŞ TEK işçi katılımı sayılır ===\n";
$s1 = oturumAc($ayseId, '2026-03-10');
kartGiris($s1, '811001', $kadinId);
kartCikis('811001', $s1);
$kpi1 = pdks_rapor_operasyonel_kpi('2026-03-10', '2026-03-10', null, null, null, db());
ok('1. GİRİŞ+ÇIKIŞ olsa da toplam_calisan = 1 (2 DEĞİL)', $kpi1['toplam_calisan'] === 1, json_encode($kpi1));

echo "\n=== 2. Aynı kart FARKLI günde AYRI sayılır ===\n";
$s1b = oturumAc($ayseId, '2026-03-11');
kartGiris($s1b, '811001', $kadinId);   // AYNI UID, FARKLI gün
kartCikis('811001', $s1b);
$kpi2 = pdks_rapor_operasyonel_kpi('2026-03-10', '2026-03-11', null, null, null, db());
ok('2. iki günlük aralıkta AYNI kart 2 KEZ sayıldı (her gün için bir)', $kpi2['toplam_calisan'] === 2, json_encode($kpi2));

echo "\n=== 3/4. İşçi tipi toplamları + DİNAMİK (KADIN/ERKEK dışı) tip dahil ===\n";
kartGiris($s1, '811002', $erkekId); kartCikis('811002', $s1);
kartGiris($s1, '811003', $paketlemeId); kartCikis('811003', $s1);
$kpi3 = pdks_rapor_operasyonel_kpi('2026-03-10', '2026-03-10', null, null, null, db());
ok('3. Kadın=1', ($kpi3['tip_dagilimi']['Kadın'] ?? 0) === 1, json_encode($kpi3['tip_dagilimi']));
ok('3. Erkek=1', ($kpi3['tip_dagilimi']['Erkek'] ?? 0) === 1, json_encode($kpi3['tip_dagilimi']));
ok('4. dinamik "Paketleme" tipi de dahil (=1) — KADIN/ERKEK hardcode edilmedi', ($kpi3['tip_dagilimi']['Paketleme'] ?? 0) === 1, json_encode($kpi3['tip_dagilimi']));
$tipRapor = pdks_rapor_isci_tipi_dagilimi('2026-03-10', '2026-03-10', null, null, db());
$tipAdlari = array_column($tipRapor, 'ad');
ok('4. pdks_rapor_isci_tipi_dagilimi() worker_types sort_order sırasıyla TÜM tipleri (0 katılımlı dahil) döner', in_array('Paketleme', $tipAdlari, true) && in_array('Kadın', $tipAdlari, true) && in_array('Erkek', $tipAdlari, true), json_encode($tipAdlari));

echo "\n=== 5. Çavuş sayıları doğru ===\n";
$s2 = oturumAc($mehmetId, '2026-03-10');
kartGiris($s2, '811010', $kadinId); kartCikis('811010', $s2);
$kpi5 = pdks_rapor_operasyonel_kpi('2026-03-10', '2026-03-10', null, null, null, db());
ok('5. aktif_cavus = 2 (Ayşe + Mehmet)', $kpi5['aktif_cavus'] === 2, json_encode($kpi5));

echo "\n=== 6. Eksik çıkışlar doğru (yalnız KAPALI oturumlarda) ===\n";
$s3 = oturumAc($ayseId, '2026-03-12');
kartGiris($s3, '811020', $kadinId);   // ÇIKIŞ YOK
$pdo = db();
$pdo->prepare("UPDATE daily_work_sessions SET status='closed' WHERE id=?")->execute([$s3]);
$kpi6 = pdks_rapor_operasyonel_kpi('2026-03-12', '2026-03-12', null, null, null, db());
ok('6. eksik_cikis_mesai = 1 (kapalı oturum, çıkışsız kart)', $kpi6['eksik_cikis_mesai'] === 1, json_encode($kpi6));
ok('6. tamamlanan_mesai = 0 (tek oturum, o da eksik çıkışlı)', $kpi6['tamamlanan_mesai'] === 0, json_encode($kpi6));
$eksikSatirlari = pdks_rapor_eksik_cikislar_araligi('2026-03-12', '2026-03-12', null, null, db());
ok('6. eksik çıkış satırı doğru karta ait', count($eksikSatirlari) === 1 && $eksikSatirlari[0]['card_no'] === 'K811020', json_encode($eksikSatirlari));

echo "\n=== 7. Açık mesailer doğru (session.status='open') ===\n";
$s4 = oturumAc($mehmetId, '2026-03-13');
kartGiris($s4, '811030', $erkekId);   // ÇIKIŞ YOK, oturum AÇIK bırakıldı
$kpi7 = pdks_rapor_operasyonel_kpi('2026-03-13', '2026-03-13', null, null, null, db());
ok('7. acik_mesai = 1', $kpi7['acik_mesai'] === 1, json_encode($kpi7));
$acikSatirlari = pdks_rapor_acik_mesailer_araligi('2026-03-13', '2026-03-13', null, null, db());
ok('7. açık mesai satırı doğru karta ait (uydurma çıkış YOK)', count($acikSatirlari) === 1 && $acikSatirlari[0]['card_no'] === 'K811030', json_encode($acikSatirlari));
ok('7. eksik çıkış listesinde AÇIK oturumun kartı YOK (yalnız kapalı oturumlar orada)', !in_array('K811030', array_column($eksikSatirlari, 'card_no'), true));

// ═══════════════════════════════════════════════════════════
echo "\n=== 8/9. KESİN hakediş DAHİL, TASLAK HARİÇ ===\n";
pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2026-01-01', 'TRY', 1, db());
$s5 = oturumAc($ayseId, '2026-03-15');
kartGiris($s5, '811040', $kadinId); kartCikis('811040', $s5);
$pdo->prepare("UPDATE daily_work_sessions SET status='closed' WHERE id=?")->execute([$s5]);
$finalAyseMart = pdks_hakedis_finalize($s5, 1, false, db());
ok('KESİN hakediş oluşturuldu (Ayşe, Mart)', $finalAyseMart['ok'] === true, json_encode($finalAyseMart));

$s6 = oturumAc($ayseId, '2026-03-16');
kartGiris($s6, '811041', $kadinId); kartCikis('811041', $s6);
$pdo->prepare("UPDATE daily_work_sessions SET status='closed' WHERE id=?")->execute([$s6]);
$taslakHesap = pdks_hakedis_hesapla($s6, 1, db());   // hesapla ama KESİNLEŞTİRME
ok('TASLAK hakediş oluşturuldu (kesinleştirilmedi)', $taslakHesap['ok'] === true && $taslakHesap['status'] === 'draft', json_encode($taslakHesap));

$finMart = pdks_rapor_finansal_kpi('2026-03-01', '2026-03-31', null, null, db());
ok('8. KESİN hakediş (1200) dönem finansal özetinde DAHİL', parasalEsit('1200.00', $finMart['TRY']['hakedis'] ?? '0'), json_encode($finMart));
ok('9. TASLAK hakediş (1200 daha) dönem özetine YANSIMADI — toplam HÂLÂ yalnız 1200 (2400 DEĞİL)', parasalEsit('1200.00', $finMart['TRY']['hakedis'] ?? '0'), json_encode($finMart));

echo "\n=== 10/11. Geçerli ödeme DAHİL, İPTAL edilen ödeme HARİÇ ===\n";
$odemeGecerli = pdks_cari_odeme_ekle($ayseId, '2026-03-18', '400', 'TRY', 'BANK', null, 'geçerli', 1, db());
ok('geçerli ödeme kaydedildi', $odemeGecerli['ok'] === true, json_encode($odemeGecerli));
$odemeIptalEdilecek = pdks_cari_odeme_ekle($ayseId, '2026-03-19', '300', 'TRY', 'CASH', null, 'iptal edilecek', 1, db());
ok('iptal edilecek ödeme kaydedildi', $odemeIptalEdilecek['ok'] === true, json_encode($odemeIptalEdilecek));
$iptalSonuc = pdks_cari_odeme_iptal((int)$odemeIptalEdilecek['id'], 'test iptali', 1, db());
ok('ödeme iptal edildi', $iptalSonuc['ok'] === true, json_encode($iptalSonuc));

$finMart2 = pdks_rapor_finansal_kpi('2026-03-01', '2026-03-31', null, null, db());
ok('10. İPTAL edilen ödeme (300) dönem özetine YANSIMADI', parasalEsit('400.00', $finMart2['TRY']['odeme'] ?? '0'), json_encode($finMart2));
ok('11. GEÇERLİ ödeme (400) dönem özetinde DAHİL', parasalEsit('400.00', $finMart2['TRY']['odeme'] ?? '0'), json_encode($finMart2));
ok('Dönem net = 1200 - 400 = 800.00', parasalEsit('800.00', $finMart2['TRY']['net'] ?? '0'), json_encode($finMart2));

echo "\n=== 12/13. Dönem hakedişi work_date, ödeme payment_date kullanır (calculated_at/created_at DEĞİL) ===\n";
// ⚠ finalize()/odeme_ekle() SUNUCU zamanını (test çalıştırma anı — GERÇEK
// dünya "bugün"ü) calculated_at/finalized_at/created_at'e yazar; work_date/
// payment_date ise BİLEREK 2026-03-xx (test senaryosu). Yukarıdaki dönem
// sorguları (2026-03-01..31) bu satırları YİNE DE buldu — bu, filtrenin
// work_date/payment_date'e göre çalıştığının, calculated_at/created_at'e
// göre DEĞİL, doğrudan KANITIDIR (aksi halde sonuç boş dönerdi, çünkü
// calculated_at/created_at test çalıştırma anının GERÇEK tarihidir).
$stHamTarih = db()->prepare("SELECT work_date, calculated_at FROM foreman_daily_entitlements WHERE id = ?");
$stHamTarih->execute([(int)$finalAyseMart['entitlement_id']]);
$hamTarih = $stHamTarih->fetch();
ok('12. work_date (2026-03-15) calculated_at\'ten (gerçek test-çalıştırma anı) FARKLI — period sorgusu work_date\'i kullandığı için üstteki testler geçti', $hamTarih['work_date'] === '2026-03-15' && substr($hamTarih['calculated_at'], 0, 4) !== '2026' || substr($hamTarih['calculated_at'], 5, 2) !== '03', json_encode($hamTarih));
$stOdemeHam = db()->prepare("SELECT payment_date, created_at FROM foreman_payments WHERE id = ?");
$stOdemeHam->execute([(int)$odemeGecerli['id']]);
$odemeHam = $stOdemeHam->fetch();
ok('13. payment_date (2026-03-18) sabit test tarihi — üstteki dönem sorgusu bunu BULDU', $odemeHam['payment_date'] === '2026-03-18');

echo "\n=== 14/15. GÜNCEL bakiye ÖNCEKİ dönemleri de kapsar — dönem neti ile AYNI ŞEY DEĞİLDİR ===\n";
pdks_hakedis_oran_ekle($mehmetId, $kadinId, '1000', '2025-12-01', 'TRY', 1, db());
$sOcak = oturumAc($mehmetId, '2026-01-05');
kartGiris($sOcak, '811050', $kadinId); kartCikis('811050', $sOcak);
$pdo->prepare("UPDATE daily_work_sessions SET status='closed' WHERE id=?")->execute([$sOcak]);
$finalMehmetOcak = pdks_hakedis_finalize($sOcak, 1, false, db());
ok('geçmiş dönem (Ocak) KESİN hakediş oluşturuldu', $finalMehmetOcak['ok'] === true, json_encode($finalMehmetOcak));

$finMartMehmet = pdks_rapor_finansal_kpi('2026-03-01', '2026-03-31', null, $mehmetId, db());
$guncelMehmet = pdks_rapor_bakiye_toplu(null, $mehmetId, db());
ok('14. Mart DÖNEMİNDE Mehmet\'in hiçbir hakediş/ödeme hareketi YOK (boş)', empty($finMartMehmet), json_encode($finMartMehmet));
ok('14. GÜNCEL bakiye Ocak\'taki hareketi KAPSIYOR (1000.00 borç)', parasalEsit('1000.00', $guncelMehmet['TRY']['bakiye'] ?? '0'), json_encode($guncelMehmet));
ok('15. Dönem net (Mart, 0) GÜNCEL bakiyeden (1000) FARKLI — iki kavram KARIŞTIRILMADI', empty($finMartMehmet) && ($guncelMehmet['TRY']['bakiye_kurus'] ?? 0) !== 0);

echo "\n=== 16/17. TRY/EUR AYRIŞIK — çapraz para birimi toplamı YOK ===\n";
pdks_hakedis_oran_ekle($mehmetId, $erkekId, '50', '2026-03-01', 'EUR', 1, db());
$sEur = oturumAc($mehmetId, '2026-03-20');
kartGiris($sEur, '811060', $erkekId); kartCikis('811060', $sEur);
$pdo->prepare("UPDATE daily_work_sessions SET status='closed' WHERE id=?")->execute([$sEur]);
$finalMehmetEur = pdks_hakedis_finalize($sEur, 1, false, db());
ok('EUR hakediş GERÇEK Faz 4 akışından oluşturuldu', $finalMehmetEur['ok'] === true, json_encode($finalMehmetEur));
$odemeEur = pdks_cari_odeme_ekle($mehmetId, '2026-03-21', '20', 'EUR', 'BANK', null, null, 1, db());
ok('EUR ödemesi kaydedildi', $odemeEur['ok'] === true, json_encode($odemeEur));

$finMartMehmet2 = pdks_rapor_finansal_kpi('2026-03-01', '2026-03-31', null, $mehmetId, db());
ok('16. Mehmet\'in TRY hareketi YOK bu dönemde (yalnız EUR var)', !isset($finMartMehmet2['TRY']), json_encode($finMartMehmet2));
ok('16. EUR hakediş 50.00, EUR ödeme 20.00', parasalEsit('50.00', $finMartMehmet2['EUR']['hakedis'] ?? '0') && parasalEsit('20.00', $finMartMehmet2['EUR']['odeme'] ?? '0'), json_encode($finMartMehmet2));
ok('17. finansal KPI dizisinde para birimlerini toplayan bir "toplam"/"grand_total" anahtarı YOK', !isset($finMartMehmet2['toplam']) && !isset($finMartMehmet2['grand_total']) && !isset($finMartMehmet2['hepsi']));
$guncelMehmetTum = pdks_rapor_bakiye_toplu(null, $mehmetId, db());
ok('17. TÜM-ZAMAN bakiyede de TRY (1000 borç) ve EUR (30 borç) AYRI anahtarlarda, TOPLANMADI', parasalEsit('1000.00', $guncelMehmetTum['TRY']['bakiye']) && parasalEsit('30.00', $guncelMehmetTum['EUR']['bakiye']), json_encode($guncelMehmetTum));

echo "\n=== 18. Negatif bakiye = avans/fazla ödeme ===\n";
pdks_cari_odeme_ekle($mehmetId, '2026-03-22', '100', 'EUR', 'BANK', null, 'asim odeme', 1, db());
$guncelMehmetAsim = pdks_rapor_bakiye_toplu(null, $mehmetId, db());
ok('18. EUR bakiye NEGATİF (30-100=-70) → durum=avans', $guncelMehmetAsim['EUR']['durum'] === 'avans' && parasalEsit('-70.00', $guncelMehmetAsim['EUR']['bakiye']), json_encode($guncelMehmetAsim['EUR']));
ok('18. etiket "Çavuş Avansı / Fazla Ödeme"', $guncelMehmetAsim['EUR']['durum_etiket'] === 'Çavuş Avansı / Fazla Ödeme');
$siralama = pdks_rapor_cavus_bakiye_siralamasi(null, $mehmetId, db());
ok('18. sıralama listesinde de aynı avans durumu görünüyor', ($siralama['EUR'][0]['durum'] ?? '') === 'avans', json_encode($siralama['EUR'] ?? []));

// ═══════════════════════════════════════════════════════════
echo "\n=== 19. Çavuş adı DEĞİŞSE de geçmiş rapor DEĞİŞMEZ (snapshot) ===\n";
pdks_gunluk_cavus_guncelle($ayseId, ['code' => 'C001', 'name' => 'Ayşe YENİ SOYAD', 'is_active' => 1], 1, db());
$eksiklerRenameSonrasi = pdks_rapor_eksik_cikislar_araligi('2026-03-12', '2026-03-12', null, null, db());
ok('19. eksik çıkış satırı HÂLÂ "Ayşe Çavuş" gösteriyor (foremen.name SONRADAN değişse de, snapshot bozulmadı)',
    $eksiklerRenameSonrasi[0]['cavus_adi'] === 'Ayşe Çavuş', json_encode($eksiklerRenameSonrasi));

echo "\n=== 20. İşçi tipi adı DEĞİŞSE de geçmiş yoklama raporu DEĞİŞMEZ (snapshot) ===\n";
// ⚠ worker_types'ın uygulama seviyesinde bir "yeniden adlandır" fonksiyonu
// YOK (pdks_gunluk.php'de yalnız tip_olustur/tip_aktiflik var) — bu yüzden
// senaryo doğrudan tabloyu günceller (Faz 4'ün EUR test istisnasıyla AYNI
// gerekçe: test EDİLEN şey worker_types.name'in DEĞİL, snapshot İZOLASYONUNUN
// kendisi — hangi yoldan değiştiği ÖNEMSİZ).
db()->prepare("UPDATE worker_types SET name = ? WHERE id = ?")->execute(['Paketleme YENİ AD', $paketlemeId]);
$tipRaporSonrasi = pdks_rapor_isci_tipi_dagilimi('2026-03-10', '2026-03-10', null, null, db());
$eskiAdSatiri = null; $yeniAdSatiri = null;
foreach ($tipRaporSonrasi as $t) {
    if ($t['ad'] === 'Paketleme') $eskiAdSatiri = $t;
    if ($t['ad'] === 'Paketleme YENİ AD') $yeniAdSatiri = $t;
}
ok('20. GEÇMİŞ (2026-03-10) katılım HÂLÂ ESKİ ada ("Paketleme") bağlı, adet=1 (snapshot korunuyor)',
    $eskiAdSatiri !== null && $eskiAdSatiri['adet'] === 1, json_encode($tipRaporSonrasi));
ok('20. YENİ ad o tarihte 0 katılımla görünüyor (worker_types güncel listesi, ama o GÜNÜN olayı YENİDEN YAZILMADI)',
    $yeniAdSatiri !== null && $yeniAdSatiri['adet'] === 0, json_encode($tipRaporSonrasi));

// ═══════════════════════════════════════════════════════════
echo "\n=== 21/22/23/24. FİLTRELER doğru ===\n";
$kpiBosTarih = pdks_rapor_operasyonel_kpi('2020-01-01', '2020-01-01', null, null, null, db());
ok('21. tarih filtresi — veri olmayan bir aralıkta toplam_calisan=0', $kpiBosTarih['toplam_calisan'] === 0, json_encode($kpiBosTarih));

$sDepoB = oturumAc($ayseId, '2026-03-25', 'Depo B');
kartGiris($sDepoB, '811070', $kadinId); kartCikis('811070', $sDepoB);
$kpiDepoA = pdks_rapor_operasyonel_kpi('2026-03-25', '2026-03-25', 'Depo A', null, null, db());
$kpiDepoB = pdks_rapor_operasyonel_kpi('2026-03-25', '2026-03-25', 'Depo B', null, null, db());
ok('22. depo filtresi — "Depo A" bu tarihte 0 (kart Depo B\'de okutuldu)', $kpiDepoA['toplam_calisan'] === 0, json_encode($kpiDepoA));
ok('22. depo filtresi — "Depo B" bu tarihte 1', $kpiDepoB['toplam_calisan'] === 1, json_encode($kpiDepoB));

$kpiSadeceAyse = pdks_rapor_operasyonel_kpi('2026-03-10', '2026-03-10', null, $ayseId, null, db());
$kpiSadeceMehmet = pdks_rapor_operasyonel_kpi('2026-03-10', '2026-03-10', null, $mehmetId, null, db());
ok('23. çavuş filtresi — Ayşe filtresinde Mehmet\'in işçisi SAYILMIYOR (3, Mehmet\'in 1\'i hariç)', $kpiSadeceAyse['toplam_calisan'] === 3, json_encode($kpiSadeceAyse));
ok('23. çavuş filtresi — Mehmet filtresinde yalnız Mehmet\'in işçisi (1)', $kpiSadeceMehmet['toplam_calisan'] === 1, json_encode($kpiSadeceMehmet));

// 2026-03-10: Ayşe'nin K811001 (Kadın) + Mehmet'in K811010 (Kadın) — 2 Kadın kartı, Erkek/Paketleme HARİÇ.
$kpiSadeceKadin = pdks_rapor_operasyonel_kpi('2026-03-10', '2026-03-10', null, null, $kadinId, db());
ok('24. işçi tipi filtresi — yalnız KADIN filtrelendiğinde toplam_calisan=2 (Ayşe+Mehmet\'in kadın kartları, Erkek/Paketleme HARİÇ)', $kpiSadeceKadin['toplam_calisan'] === 2, json_encode($kpiSadeceKadin));
ok('24. işçi tipi filtresi — tip_dagilimi YALNIZ Kadın anahtarını içeriyor', array_keys($kpiSadeceKadin['tip_dagilimi']) === ['Kadın'], json_encode($kpiSadeceKadin['tip_dagilimi']));

// ═══════════════════════════════════════════════════════════
echo "\n=== 25/26. DÖNEM KARŞILAŞTIRMASI doğru + önceki-sıfır güvenli ===\n";
$onceki = pdks_rapor_onceki_donem('bu_ay', '2026-03-01', '2026-03-31');
ok('25. bu_ay/gecen_ay presetinde önceki dönem TAKVİM AYI kayar (Şubat 1-28)', $onceki['start'] === '2026-02-01' && $onceki['end'] === '2026-02-28', json_encode($onceki));
$oncekiHafta = pdks_rapor_onceki_donem('bu_hafta', '2026-03-09', '2026-03-15');
ok('25. bu_hafta preseti AYNI UZUNLUKTA (7 gün) hemen ÖNCESİNE kayar', $oncekiHafta['start'] === '2026-03-02' && $oncekiHafta['end'] === '2026-03-08', json_encode($oncekiHafta));

$degisimNormal = pdks_rapor_yuzde_degisim(100, 150);
ok('25. yüzde değişim: 100→150 = %50 artış', $degisimNormal['yuzde'] !== null && abs($degisimNormal['yuzde'] - 50.0) < 0.01, json_encode($degisimNormal));
$degisimSifirdan = pdks_rapor_yuzde_degisim(0, 40);
ok('26. önceki değer 0 iken bölme YAPILMAZ — "yeni_mi" bayrağı true, yuzde NULL (yanıltıcı % YOK)', $degisimSifirdan['yuzde'] === null && $degisimSifirdan['yeni_mi'] === true, json_encode($degisimSifirdan));
$degisimIkiSifir = pdks_rapor_yuzde_degisim(0, 0);
ok('26. iki taraf da 0 iken "yeni_mi" de false (gerçekten hiçbir şey olmadı)', $degisimIkiSifir['yuzde'] === null && $degisimIkiSifir['yeni_mi'] === false, json_encode($degisimIkiSifir));

$karsilastirmaMart = pdks_rapor_karsilastirma('bu_ay', '2026-03-01', '2026-03-31', null, null, null, db());
ok('25. karşılaştırma: önceki dönem Şubat 1-28 olarak hesaplandı', $karsilastirmaMart['onceki_araligi']['start'] === '2026-02-01' && $karsilastirmaMart['onceki_araligi']['end'] === '2026-02-28', json_encode($karsilastirmaMart['onceki_araligi']));
ok('25. karşılaştırma: Şubat\'ta hiç çalışan katılımı yoktu (0) → "yeni_mi" true', $karsilastirmaMart['onceki_calisan'] === 0 && $karsilastirmaMart['calisan_degisim']['yeni_mi'] === true, json_encode($karsilastirmaMart['calisan_degisim']));

// ═══════════════════════════════════════════════════════════
echo "\n=== 30. admin/muhasebe finansal raporlama ÇALIŞIR ===\n";
ok('30. attendance.foreman_accounts izniyle pdks_rapor_can(\'financial\') = true', pdks_rapor_can('financial') === true);
$IS_ADMIN = true;
ok('30. is_admin() ile pdks_rapor_can(\'financial\') = true (bypass)', pdks_rapor_can('financial') === true);
$IS_ADMIN = false;
$PERMS_YEDEK = $PERMS; $PERMS = ['attendance.management_reports'];   // yalnız görüntüleme, finansal YOK
ok('\'ik\' senaryosu: attendance.foreman_accounts OLMADAN pdks_rapor_can(\'financial\') = false', pdks_rapor_can('financial') === false);
ok('\'ik\' senaryosu: attendance.management_reports İLE pdks_rapor_can(\'view\') HÂLÂ true', pdks_rapor_can('view') === true);
$PERMS = $PERMS_YEDEK;

// ═══════════════════════════════════════════════════════════
echo "\n=== EK — GÜNLÜK TREND: boş günler de dahil, doğru sırayla ===\n";
$trend = pdks_rapor_gunluk_trend('2026-03-09', '2026-03-11', null, null, null, db());
ok('trend dizisi aralıktaki TÜM günleri içeriyor (3 gün, boş olan da dahil)', count($trend) === 3, json_encode(array_column($trend, 'tarih')));
ok('trend kronolojik sırada', array_column($trend, 'tarih') === ['2026-03-09', '2026-03-10', '2026-03-11']);
$gun09 = $trend[0]; $gun10 = $trend[1];
ok('2026-03-09 (hiç veri yok) toplam_calisan=0 — sessiz gün HATA gibi görünmüyor, 0 ile temsil ediliyor', $gun09['toplam_calisan'] === 0, json_encode($gun09));
ok('2026-03-10 toplam_calisan doğru (Kadın×2 + Erkek×1 + Paketleme×1 = 4)', $gun10['toplam_calisan'] === 4, json_encode($gun10));

echo "\n=== EK — ÇAVUŞ ÖZETİ: bulk birleşim doğru ===\n";
$ozet = pdks_rapor_cavus_ozeti('2026-03-01', '2026-03-31', null, null, null, db());
$ayseOzet = null; $mehmetOzet = null;
foreach ($ozet as $o) {
    if ($o['foreman']['id'] == $ayseId) $ayseOzet = $o;
    if ($o['foreman']['id'] == $mehmetId) $mehmetOzet = $o;
}
ok('çavuş özetinde Ayşe VAR', $ayseOzet !== null);
ok('Ayşe: güncel bakiye TRY doğru (1200 hakediş - 400 ödeme = 800)', $ayseOzet !== null && parasalEsit('800.00', $ayseOzet['guncel_bakiye']['TRY']['bakiye'] ?? '0'), json_encode($ayseOzet['guncel_bakiye'] ?? []));
ok('Mehmet: dönem hakedişi (Mart) EUR 50.00 (Ocak\'ın TRY 1000\'i dönem dışı, dahil EDİLMEDİ)', $mehmetOzet !== null && parasalEsit('50.00', $mehmetOzet['donem_hakedis']['EUR'] ?? '0') && !isset($mehmetOzet['donem_hakedis']['TRY']), json_encode($mehmetOzet['donem_hakedis'] ?? []));
ok('Mehmet: güncel bakiye TRY (Ocak dahil, 1000.00) ayrıca doğru görünüyor', $mehmetOzet !== null && parasalEsit('1000.00', $mehmetOzet['guncel_bakiye']['TRY']['bakiye'] ?? '0'), json_encode($mehmetOzet['guncel_bakiye'] ?? []));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
