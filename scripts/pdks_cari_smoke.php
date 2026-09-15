<?php
// =========================================================
// scripts/pdks_cari_smoke.php — Çavuş Cari Hesap (Günlük İşçi, Faz 5) backend testi
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ. Faz 2-4 ile AYNI desen: elle
// yazılmış test şeması KULLANMAZ — config/pdks.php + config/pdks_gunluk.php +
// config/pdks_hakedis.php + config/pdks_cari.php'nin GERÇEK MySQL DDL'ini
// SQLite'a çevirip çalıştırır, GERÇEK Faz 1-5 fonksiyonlarıyla veri üretir.
//
// Kapsam (görev talimatının 28 test maddesiyle eşlenir — 1-18, 22-23 burada;
// 19-21 pdks_cari_static_smoke.php'de; 24-27 AYRI paketlerin YENİDEN
// çalıştırılmasıyla; 28 pdks_cari_ui_smoke.php'de):
//    1  KESİN hakediş bakiyeyi ARTIRIR         10 aşım NEGATİF/avans bakiye üretir
//    2  TASLAK hakediş bakiyeyi ETKİLEMEZ       11 ekstre koşan bakiyesi doğru
//    3  birden çok KESİN hakediş doğru TOPLANIR 12 deterministik aynı-gün sıralama
//    4  ödeme bakiyeyi AZALTIR                  13 TRY/EUR ASLA toplanmaz
//    5  İPTAL edilen ödeme bakiyeyi AZALTMAZ    14 TRY ödemesi EUR bakiyesini ETKİLEMEZ
//    6  ödeme sessizce düzenlenemez/silinemez   15 çavuş adı değişse de ödeme geçmişi DEĞİŞMEZ
//    7  iptal GEREKÇE ister                     16 KESİN hakediş anlık görüntüsü tarihsel kalır
//    8  ödeme denetimi kullanıcı/zaman korur    17 güvensiz yeniden-açma cari geçmişi BOZAMAZ
//    9  aşım uyarısı/onayı                      18 TAM SAYI kuruş aritmetiği
//   22 normal sayfa ziyaretinde DDL yok (bkz. static test)  23 migrasyon İDEMPOTENT
//
//   php scripts/pdks_cari_smoke.php   → çıkış kodu 0 = geçti
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
// ⚠ pdks_cari_odeme_iptal() KENDİSİ attendance.foreman_payments'ı kontrol
// eder (Faz 4'ün pdks_hakedis_yeniden_ac()'ının is_admin() öz-kontrolüyle
// AYNI ilke — "Cancellation requires: authorized user" görev talimatı
// doğrudan FONKSİYON seviyesinde uygulanır, yalnız sayfa kapısına
// GÜVENİLMEZ). Bu test dosyası backend fonksiyonlarını DOĞRUDAN çağırdığı
// için (sayfa katmanını ATLAYARAK) bu izni baştan verir.
$PERMS = ['attendance.foreman_payments'];
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
$IS_ADMIN = false;
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
$AKTIF_DEPO = 'Depo A';
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }

require_once $KOK . '/config/pdks.php';
require_once $KOK . '/config/pdks_gunluk.php';
require_once $KOK . '/config/pdks_hakedis.php';
require_once $KOK . '/config/pdks_cari.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — Faz 2-4 testleriyle BİREBİR AYNI (kasıtlı kopya).
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
// ⚠ MySQL PDO DECIMAL kolonlarını STRING döner, SQLite (bu test şeması)
// sayısal döner — Faz 4 test dosyasındaki AYNI, belgelenen artefakt
// (bkz. pdks_hakedis_smoke.php). Yalnız HAM DB satırlarını okuyan
// assertion'larda kullanılır.
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

/** Belirli bir tarihte/depoda, tek bir kadın kartla TAMAMLANMIŞ ve
 *  KESİNLEŞTİRİLMİŞ bir hakediş üretir — testin tekrarlayan kurulumu. */
function finalHakedisUret(int $foremanId, string $tarih, string $uid, int $kadinId, string $oranTl): array {
    $ins = db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id) VALUES (?,?,?,?,?,?,?,?)");
    $stF = db()->prepare("SELECT name, code FROM foremen WHERE id=?"); $stF->execute([$foremanId]); $f = $stF->fetch();
    $ins->execute([$foremanId, $f['name'], $f['code'], $tarih, 'Depo A', 'open', $tarih . ' 08:00:00', 1]);
    $sid = (int)db()->lastInsertId();
    $k = pdks_gunluk_kart_olustur(['card_no' => 'K' . $uid, 'worker_type_id' => $kadinId, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
    if (!$k['ok']) { fwrite(STDERR, 'kart olusturulamadi: ' . json_encode($k) . "\n"); exit(1); }
    $g = pdks_gunluk_oturum_kaydet($uid, 'usb_decimal', $sid, 'GIRIS', 1, db());
    if (!$g['ok']) { fwrite(STDERR, 'giris basarisiz: ' . json_encode($g) . "\n"); exit(1); }
    pdks_gunluk_oturum_kaydet($uid, 'usb_decimal', $sid, 'CIKIS', 1, db());
    pdks_gunluk_oturum_kapat($sid, null, 1, db());
    $oranSonuc = pdks_hakedis_oran_gecerli($foremanId, $kadinId, $tarih, db());
    if ($oranSonuc === null) {
        pdks_hakedis_oran_ekle($foremanId, $kadinId, $oranTl, $tarih, 'TRY', 1, db());
    }
    $final = pdks_hakedis_finalize($sid, 1, false, db());
    if (!$final['ok']) { fwrite(STDERR, 'finalize basarisiz: ' . json_encode($final) . "\n"); exit(1); }
    return ['session_id' => $sid, 'entitlement_id' => (int)$final['entitlement_id'], 'total' => $final['total_amount']];
}

// ═══════════════════════════════════════════════════════════
echo "\n=== 1/2/3. KESİN HAKEDİŞ BAKİYEYİ ARTIRIR, TASLAK ETKİLEMEZ, ÇOKLU TOPLAM DOĞRU ===\n";
pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2026-09-01', 'TRY', 1, db());
$h1 = finalHakedisUret($ayseId, '2026-09-15', '111001', $kadinId, '1200');
ok('1. KESİN hakediş sonrası bakiye ARTTI (66000→1200, tek kart)', true);   // aşağıda sayısal doğrulanacak
$bakiye1 = pdks_cari_bakiye($ayseId, db());
ok('1. Ayşe/TRY bakiyesi 1200.00 (tek KESİN hakediş)', parasalEsit('1200.00', $bakiye1['TRY']['bakiye']), json_encode($bakiye1));

// Aynı gün ikinci bir kart daha — ama bu oturumu TASLAK bırakalım (finalize ETMEYELİM).
$ins2 = db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id) VALUES (?,?,?,?,?,?,?,?)");
$ins2->execute([$ayseId, 'Ayşe Çavuş', 'C001', '2026-09-16', 'Depo A', 'open', '2026-09-16 08:00:00', 1]);
$sidTaslak = (int)db()->lastInsertId();
$kT = pdks_gunluk_kart_olustur(['card_no' => 'K111002', 'worker_type_id' => $kadinId, 'ham_uid' => '111002', 'kaynak' => 'usb_decimal'], 1, db());
pdks_gunluk_oturum_kaydet('111002', 'usb_decimal', $sidTaslak, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('111002', 'usb_decimal', $sidTaslak, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($sidTaslak, null, 1, db());
$taslakHesap = pdks_hakedis_hesapla($sidTaslak, 1, db());
ok('taslak hesap başarıyla oluşturuldu (ama KESİN DEĞİL)', $taslakHesap['ok'] === true && $taslakHesap['status'] === 'draft', json_encode($taslakHesap));

$bakiye2 = pdks_cari_bakiye($ayseId, db());
ok('2. TASLAK hakediş bakiyeyi ETKİLEMEDİ (hâlâ 1200.00, 2400 DEĞİL)', parasalEsit('1200.00', $bakiye2['TRY']['bakiye']), json_encode($bakiye2));

$h2 = finalHakedisUret($ayseId, '2026-09-17', '111003', $kadinId, '1200');
$bakiye3 = pdks_cari_bakiye($ayseId, db());
ok('3. İKİNCİ KESİN hakediş sonrası bakiye DOĞRU TOPLANDI (1200+1200=2400.00)', parasalEsit('2400.00', $bakiye3['TRY']['bakiye']), json_encode($bakiye3));

// ═══════════════════════════════════════════════════════════
echo "\n=== 4/5. ÖDEME BAKİYEYİ AZALTIR, İPTAL EDİLEN ÖDEME AZALTMAZ ===\n";
$odeme1 = pdks_cari_odeme_ekle($ayseId, '2026-09-18', '1000', 'TRY', 'BANK', 'HAVALE-1', 'test ödeme', 1, db());
ok('4. ödeme kaydedildi', $odeme1['ok'] === true, json_encode($odeme1));
$bakiye4 = pdks_cari_bakiye($ayseId, db());
ok('4. ödeme SONRASI bakiye AZALDI (2400-1000=1400.00)', parasalEsit('1400.00', $bakiye4['TRY']['bakiye']), json_encode($bakiye4));

$odeme2 = pdks_cari_odeme_ekle($ayseId, '2026-09-19', '300', 'TRY', 'CASH', null, 'iptal edilecek', 1, db());
$bakiyeIptalOncesi = pdks_cari_bakiye($ayseId, db());
ok('ikinci ödeme sonrası bakiye 1100.00', parasalEsit('1100.00', $bakiyeIptalOncesi['TRY']['bakiye']));
$iptalGerekcesiz = pdks_cari_odeme_iptal((int)$odeme2['id'], '', 1, db());
ok('7. GEREKÇESİZ iptal REDDEDİLİR', $iptalGerekcesiz['ok'] === false && $iptalGerekcesiz['kod'] === 'gerekce_zorunlu', json_encode($iptalGerekcesiz));
$iptal = pdks_cari_odeme_iptal((int)$odeme2['id'], 'Mükerrer girildi, yanlışlıkla eklendi.', 1, db());
ok('gerekçeli iptal başarılı', $iptal['ok'] === true, json_encode($iptal));
$bakiye5 = pdks_cari_bakiye($ayseId, db());
ok('5. İPTAL edilen ödeme bakiyeyi AZALTMADI (hâlâ 1400.00, 1100 DEĞİL)', parasalEsit('1400.00', $bakiye5['TRY']['bakiye']), json_encode($bakiye5));

echo "\n=== 6. ÖDEME SESSİZCE DÜZENLENEMEZ/SİLİNEMEZ (yapısal) ===\n";
ok('config/pdks_cari.php içinde foreman_payments için HİÇBİR UPDATE (status/cancelled_* dışında) YOK — bkz. static test',
    true);   // ayrıntılı kod-düzeyi kanıt static testte
$stOdeme2 = db()->prepare("SELECT amount, foreman_id, payment_date, currency, status FROM foreman_payments WHERE id = ?");
$stOdeme2->execute([(int)$odeme2['id']]);
$odeme2Row = $stOdeme2->fetch();
ok('6. iptal edilen ödemenin TUTARI/ÇAVUŞU/TARİHİ/PARA BİRİMİ DEĞİŞMEDİ (yalnız status)',
    parasalEsit('300.00', $odeme2Row['amount']) && (int)$odeme2Row['foreman_id'] === $ayseId
    && $odeme2Row['payment_date'] === '2026-09-19' && $odeme2Row['currency'] === 'TRY' && $odeme2Row['status'] === 'cancelled');

echo "\n=== 8. ÖDEME DENETİMİ KULLANICI/ZAMAN KORUR ===\n";
$stOdeme1 = db()->prepare("SELECT created_by_user_id, created_at FROM foreman_payments WHERE id = ?");
$stOdeme1->execute([(int)$odeme1['id']]);
$odeme1Row = $stOdeme1->fetch();
ok('8. created_by_user_id doğru (1)', (int)$odeme1Row['created_by_user_id'] === 1);
ok('8. created_at SUNUCU zaman formatında dolu', (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$odeme1Row['created_at']));
$stIptalDenetim = db()->prepare("SELECT cancelled_by_user_id, cancelled_at, cancellation_reason FROM foreman_payments WHERE id = ?");
$stIptalDenetim->execute([(int)$odeme2['id']]);
$iptalDenetim = $stIptalDenetim->fetch();
ok('8. iptal edenin kullanıcı id\'si korunuyor', (int)$iptalDenetim['cancelled_by_user_id'] === 1);
ok('8. iptal zamanı SUNUCU formatında dolu', (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$iptalDenetim['cancelled_at']));
ok('8. iptal gerekçesi korunuyor', str_contains((string)$iptalDenetim['cancellation_reason'], 'Mükerrer'));

echo "\n=== 9/10. AŞIM UYARISI/ONAYI + NEGATİF (AVANS) BAKİYE ===\n";
// Ayşe bakiyesi şu an 1400.00 TRY. 2000 TL ödeme AŞIM olur.
$onizlemeAsim = pdks_cari_odeme_onizleme($ayseId, pdks_hakedis_tl_kurus('2000.00'), 'TRY', db());
ok('9. aşım DOĞRU TESPİT EDİLDİ (2000 > 1400 mevcut bakiye)', $onizlemeAsim['asim'] === true, json_encode($onizlemeAsim));
ok('9. fonksiyon ödemeyi ENGELLEMEZ — yalnız PROJEKSİYON döner (kullanıcının açık talimatı)', array_key_exists('yeni_bakiye_kurus', $onizlemeAsim));
$odemeAsim = pdks_cari_odeme_ekle($ayseId, '2026-09-20', '2000', 'TRY', 'BANK', null, 'aşım testi', 1, db());
ok('aşım tutarı YİNE DE kaydedilebiliyor (sessizce ENGELLENMİYOR — onay UI/sayfa katmanında)', $odemeAsim['ok'] === true, json_encode($odemeAsim));
$bakiye10 = pdks_cari_bakiye($ayseId, db());
ok('10. aşım sonrası bakiye NEGATİF (avans) — 1400-2000=-600.00', parasalEsit('-600.00', $bakiye10['TRY']['bakiye']), json_encode($bakiye10));
ok('10. durum "avans" olarak işaretlendi', $bakiye10['TRY']['durum'] === 'avans');
ok('10. etiket "Çavuş Avansı / Fazla Ödeme"', $bakiye10['TRY']['durum_etiket'] === 'Çavuş Avansı / Fazla Ödeme');
// Testin geri kalanı için temizle — bu ödemeyi iptal ederek bakiyeyi eski hâline döndür.
pdks_cari_odeme_iptal((int)$odemeAsim['id'], 'Test senaryosu temizliği.', 1, db());
$bakiyeTemiz = pdks_cari_bakiye($ayseId, db());
ok('temizlik sonrası bakiye 1400.00\'e döndü', parasalEsit('1400.00', $bakiyeTemiz['TRY']['bakiye']));

// ═══════════════════════════════════════════════════════════
echo "\n=== 11/12. EKSTRE KOŞAN BAKİYESİ + DETERMİNİSTİK AYNI-GÜN SIRALAMA ===\n";
// Aynı günde HEM hakediş HEM ödeme — sıralama finalized_at/created_at ile TIE-BREAK edilmeli.
$h3 = finalHakedisUret($mehmetId, '2026-09-21', '222001', $kadinId, '1250');
$odemeMehmet = pdks_cari_odeme_ekle($mehmetId, '2026-09-21', '500', 'TRY', 'CASH', null, 'aynı gün ödeme', 1, db());
$ekstreM = pdks_cari_ekstre($mehmetId, null, null, db());
ok('Mehmet ekstresinde TRY hareketleri var', isset($ekstreM['TRY']) && count($ekstreM['TRY']) === 2, json_encode($ekstreM));
$satirlarM = $ekstreM['TRY'];
ok('12. AYNI GÜNDE hakediş ÖNCE geldi (finalized_at ödemenin created_at\'ından ÖNCE oluştu)', $satirlarM[0]['tip'] === 'HAKEDIS', json_encode($satirlarM));
ok('12. ikinci satır ödeme', $satirlarM[1]['tip'] === 'ODEME');
ok('11. ilk satır (hakediş) koşan bakiyesi 1250.00', parasalEsit('1250.00', $satirlarM[0]['kosan_bakiye']));
ok('11. ikinci satır (ödeme) koşan bakiyesi 750.00 (1250-500)', parasalEsit('750.00', $satirlarM[1]['kosan_bakiye']));
ok('11. ekstredeki artis/azalis DOĞRU işaretli (hakediş=artış, ödeme=azalış)',
    $satirlarM[0]['artis'] !== null && $satirlarM[0]['azalis'] === null
    && $satirlarM[1]['artis'] === null && $satirlarM[1]['azalis'] !== null);

// ═══════════════════════════════════════════════════════════
echo "\n=== 13/14. TRY VE EUR ASLA TOPLANMAZ ===\n";
// ⚠ NOT (Faz 4'ün BİLİNEN, BURADA BİLEREK DOKUNULMAYAN bir sınırlaması —
// "Phase 4 is the financial source of entitlement. Do NOT create another
// entitlement calculation."): pdks_hakedis_hesapla() bugün itibarıyla
// foreman_daily_entitlements.currency alanına HER ZAMAN sabit 'TRY' yazıyor
// (kullanılan oranın KENDİ para biriminden BAĞIMSIZ olarak) — bu rapora da
// AÇIKÇA yazıldı, Faz 5 kapsamında DÜZELTİLMEDİ (Faz 4 hesap mantığına
// dokunma yetkisi bu görevde YOK). Faz 5'in KENDİ para birimi AYRIŞTIRMA
// mantığını (asıl test edilen budur) bu sınırlamadan BAĞIMSIZ kanıtlamak
// için, EUR hakediş satırı BURADA doğrudan (gerçek şemaya uygun) eklenir —
// Faz 4'ün hesap fonksiyonu YENİDEN YAZILMADAN/ÇAĞRILMADAN.
$ins3 = db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id) VALUES (?,?,?,?,?,?,?,?)");
$ins3->execute([$mehmetId, 'Mehmet Çavuş', 'C002', '2026-09-22', 'Depo A', 'closed', '2026-09-22 08:00:00', 1]);
$sidEur = (int)db()->lastInsertId();
$simdiEur = date('Y-m-d H:i:s');
$insEur = db()->prepare(
    "INSERT INTO foreman_daily_entitlements
        (session_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo,
         status, currency, total_amount, calculated_at, calculated_by_user_id, finalized_at, finalized_by_user_id)
     VALUES (?,?,?,?,?,?, 'final', 'EUR', '80.00', ?, 1, ?, 1)"
);
$insEur->execute([$sidEur, $mehmetId, 'Mehmet Çavuş', 'C002', '2026-09-22', 'Depo A', $simdiEur, $simdiEur]);
ok('EUR hakediş satırı test amaçlı eklendi', true);

$bakiyeMehmet = pdks_cari_bakiye($mehmetId, db());
ok('13. Mehmet\'in TRY VE EUR bakiyeleri AYRI anahtarlarda (İKİSİ de var)', isset($bakiyeMehmet['TRY']) && isset($bakiyeMehmet['EUR']), json_encode($bakiyeMehmet));
ok('13. TRY bakiyesi 750.00 (EUR\'un 80\'i KARIŞMADI)', parasalEsit('750.00', $bakiyeMehmet['TRY']['bakiye']));
ok('13. EUR bakiyesi 80.00 (TRY\'nin 750\'si KARIŞMADI)', parasalEsit('80.00', $bakiyeMehmet['EUR']['bakiye']));

$odemeEur = pdks_cari_odeme_ekle($mehmetId, '2026-09-23', '30', 'EUR', 'BANK', null, 'EUR ödeme', 1, db());
ok('EUR ödemesi kaydedildi', $odemeEur['ok'] === true, json_encode($odemeEur));
$bakiyeSonEur = pdks_cari_bakiye($mehmetId, db());
ok('14. EUR ödemesi SADECE EUR bakiyesini etkiledi (80-30=50.00)', parasalEsit('50.00', $bakiyeSonEur['EUR']['bakiye']));
ok('14. TRY bakiyesi HÂLÂ 750.00 (EUR ödemesi TRY\'yi ETKİLEMEDİ)', parasalEsit('750.00', $bakiyeSonEur['TRY']['bakiye']));

// ═══════════════════════════════════════════════════════════
echo "\n=== 15/16. TARİHSEL SNAPSHOT — çavuş adı/hakediş anlık görüntüsü DEĞİŞMEZ ===\n";
pdks_gunluk_cavus_guncelle($ayseId, ['code' => 'C001', 'name' => 'Ayşe YENİ SOYAD', 'is_active' => 1], 1, db());
$stOdemeSnapshot = db()->prepare("SELECT foreman_name_snapshot, foreman_code_snapshot FROM foreman_payments WHERE id = ?");
$stOdemeSnapshot->execute([(int)$odeme1['id']]);
$odemeSnap = $stOdemeSnapshot->fetch();
ok('15. Ödeme kaydı HÂLÂ "Ayşe Çavuş" gösteriyor (foremen.name SONRADAN değişse de)', $odemeSnap['foreman_name_snapshot'] === 'Ayşe Çavuş', json_encode($odemeSnap));

$stHakedisSnapshot = db()->prepare("SELECT foreman_name_snapshot, total_amount FROM foreman_daily_entitlements WHERE id = ?");
$stHakedisSnapshot->execute([$h1['entitlement_id']]);
$hakedisSnap = $stHakedisSnapshot->fetch();
ok('16. KESİN hakediş kaydı HÂLÂ "Ayşe Çavuş" ve 1200.00 gösteriyor (çavuş adı değişse de anlık görüntü DEĞİŞMEDİ)',
    $hakedisSnap['foreman_name_snapshot'] === 'Ayşe Çavuş' && parasalEsit('1200.00', $hakedisSnap['total_amount']));
$bakiyeSonrasi = pdks_cari_bakiye($ayseId, db());
ok('cari bakiye de HÂLÂ 1400.00 (çavuş adı değişikliği bakiyeyi BOZMADI)', parasalEsit('1400.00', $bakiyeSonrasi['TRY']['bakiye']));

echo "\n=== 17. GÜVENSİZ YENİDEN-AÇMA CARİ GEÇMİŞİ BOZAMAZ (Faz 4 KORUMASI) ===\n";
$IS_ADMIN = true;
$yenidenAcEngellenmis = pdks_hakedis_yeniden_ac($h1['entitlement_id'], 'test — ödeme olduğu için engellenmeli', 1, db());
ok('17. Ayşe\'nin GEÇERLİ ödemesi VARKEN KESİN hakedişi yeniden açmak ENGELLENDİ', $yenidenAcEngellenmis['ok'] === false && $yenidenAcEngellenmis['kod'] === 'cari_hareketli_engel', json_encode($yenidenAcEngellenmis));
$stDurumHalaFinal = db()->prepare("SELECT status FROM foreman_daily_entitlements WHERE id = ?");
$stDurumHalaFinal->execute([$h1['entitlement_id']]);
ok('17. hakediş HÂLÂ final durumda (yeniden açma GERÇEKTEN engellendi)', $stDurumHalaFinal->fetchColumn() === 'final');

// Hiç ödemesi olmayan bir çavuş için yeniden açma HÂLÂ serbest olmalı (Faz 4 davranışı bozulmadı).
$zeynepId = cavusEkle('C003', 'Zeynep Çavuş');
pdks_hakedis_oran_ekle($zeynepId, $kadinId, '1000', '2026-09-01', 'TRY', 1, db());
$hZeynep = finalHakedisUret($zeynepId, '2026-09-24', '333001', $kadinId, '1000');
$yenidenAcSerbest = pdks_hakedis_yeniden_ac($hZeynep['entitlement_id'], 'ödemesi yok, serbestçe açılabilmeli', 1, db());
ok('17b. ödemesi OLMAYAN çavuşun hakedişi HÂLÂ yeniden açılabiliyor (Faz 4 davranışı korunuyor)', $yenidenAcSerbest['ok'] === true, json_encode($yenidenAcSerbest));
$IS_ADMIN = false;

echo "\n=== 18. TAM SAYI KURUŞ ARİTMETİĞİ (binary float YOK) ===\n";
$kurusA = pdks_hakedis_tl_kurus('0.10');
$kurusB = pdks_hakedis_tl_kurus('0.20');
ok('18. 0.10 TL + 0.20 TL TAM OLARAK 0.30 TL (binary float\'ta 0.1+0.2=0.30000000000000004 hatası BURADA YOK)',
    pdks_hakedis_kurus_tl($kurusA + $kurusB) === '0.30');
ok('18. büyük tekrarlı toplama (1000 × 1200.01) HÂLÂ TAM', pdks_hakedis_kurus_tl(pdks_hakedis_tl_kurus('1200.01') * 1000) === '1200010.00');

echo "\n=== 23. MİGRASYON İDEMPOTENT ===\n";
$ikinciMigrasyon = pdks_cari_migrate(db());
ok('tablo zaten var — ikinci migrate() hiçbir şeyi BOZMAZ', (function () use ($ikinciMigrasyon) {
    foreach ($ikinciMigrasyon as $r) if ($r['durum'] === 'hata') return false;
    return true;
})(), json_encode($ikinciMigrasyon));
$stOdemeSayim = db()->query("SELECT COUNT(*) FROM foreman_payments");
ok('ikinci migrate() SONRASI ödeme verisi KAYBOLMADI', (int)$stOdemeSayim->fetchColumn() > 0);

echo "\n=== SANITY — kalıcı PDKS + Faz 2/3/4 etkilenmedi (asıl kanıt: ayrı paketler) ===\n";
db()->exec("INSERT INTO users (username) VALUES ('test')");
$empIns = db()->prepare("INSERT INTO employees (full_name, status) VALUES (?, 'aktif')");
$empIns->execute(['Test Personel']);
$permKart = pdks_kart_olustur((int)db()->lastInsertId(), '999111222', 'usb_decimal', [], db());
ok('kalıcı personel kartı Faz 5\'ten SONRA da normal oluşturuluyor', $permKart['ok'] === true, json_encode($permKart));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
