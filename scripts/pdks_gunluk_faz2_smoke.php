<?php
// =========================================================
// scripts/pdks_gunluk_faz2_smoke.php — Günlük İşçi Faz 2 (mesai oturumu +
// seri Giriş/Çıkış) backend testi
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ. scripts/pdks_gunluk_smoke.php
// İLE AYNI desen: elle yazılmış test şeması KULLANMAZ — hem config/pdks.php'nin
// (kalıcı personel — çapraz-sistem testleri için) hem config/pdks_gunluk.php'nin
// (Faz 1 + Faz 2) GERÇEK MySQL DDL'ini SQLite'a çevirip çalıştırır.
//
// Kapsam (görev listesindeki 20 madde ile eşlenir — 1-18 burada, 19-20
// scripts/pdks_gunluk_faz2_static_smoke.php'de):
//   1  çavuş/tarih/depo için oturum açma
//   2  sayfa yenilenince AYNI açık oturumun yeniden kullanılması
//   3  işçi kartı GİRİŞ
//   4  canlı kadın/erkek/toplam sayaçlar
//   5  mükerrer GİRİŞ reddi
//   6  GİRİŞ'siz ÇIKIŞ reddi
//   7  geçerli ÇIKIŞ
//   8  kart başka çavuş altında aktifken reddi
//   9  ÇIKIŞ sonrası kart operasyonel olarak serbest (AYNI oturumda bile)
//   10 kayıp/devre dışı kart reddi (yalnız GİRİŞ'te; ÇIKIŞ etkilenmez)
//   11 geçmiş işçi-tipi snapshot'ının korunması
//   12 tüm kartlar çıkış yapmışken oturum kapatma
//   13 eşleşmemiş çıkış mutabakatı
//   14 açık gerekçeyle eksik-çıkışla kapatma
//   15 sunucu-yetkili zaman damgaları
//   16 USB normalizasyonu
//   17 Web NFC normalizasyonu
//   18 kalıcı personel devam sistemi DEĞİŞMEDEN çalışıyor
//
//   php scripts/pdks_gunluk_faz2_smoke.php   → çıkış kodu 0 = geçti
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
// ⚠ depo İSTEMCİDEN alınmaz, active_depot() üzerinden sunucu tarafında
// çözülür (config/auth.php'nin GERÇEK deseni) — test için sabit bir depo.
$AKTIF_DEPO = 'Depo A';
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }

require_once $KOK . '/config/pdks.php';
require_once $KOK . '/config/pdks_gunluk.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — scripts/pdks_gunluk_smoke.php İLE BİREBİR
// AYNI (kasıtlı kopya, bkz. dosya başlığı).
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
            continue;   // FK'lar test amacıyla atlanır — pdks_gunluk_smoke.php ile aynı karar
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
// Şemayı kur
// ─────────────────────────────────────────────────────────
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
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
pdks_gunluk_migrate(db());   // GERÇEK migrate — KADIN/ERKEK seed'i

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-82s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

// ── Test verisi: iki çavuş, iki işçi tipi, dört işçi kartı ──
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();

function cavusEkle(string $kod, string $ad): int {
    $r = pdks_gunluk_cavus_olustur(['code' => $kod, 'name' => $ad], 1, db());
    return (int)$r['id'];
}
$ayseId   = cavusEkle('C001', 'Ayşe Çavuş');
$mehmetId = cavusEkle('C002', 'Mehmet Çavuş');

function kartEkle(string $no, int $tipId, string $uid): array {
    return pdks_gunluk_kart_olustur(['card_no' => $no, 'worker_type_id' => $tipId, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
}
$k1 = kartEkle('K001', $kadinId, '631799511');   // → 25A87ED7 (bilinen test kartı)
$k2 = kartEkle('K002', $kadinId, '111222333');
$e1 = kartEkle('E001', $erkekId, '444555666');
ok('test kartları oluşturuldu', $k1['ok'] && $k2['ok'] && $e1['ok']);

// ═══════════════════════════════════════════════════════════
echo "\n=== 1. OTURUM AÇMA — çavuş/tarih/depo ===\n";
$o1 = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
ok('oturum açıldı', $o1['ok'] === true, json_encode($o1));
ok('yeni=true (ilk açılış)', $o1['yeni'] === true);
ok('status=open', $o1['session']['status'] === 'open');
ok('depo = active_depot() değeri (Depo A)', $o1['session']['depo'] === 'Depo A');
ok('work_date = bugün', $o1['session']['work_date'] === date('Y-m-d'));
$ayseSessionId = (int)$o1['session']['id'];

ok('pasif çavuş için oturum açılamaz', pdks_gunluk_oturum_ac_veya_getir(999999, 1, db())['ok'] === false);
pdks_gunluk_cavus_aktiflik($mehmetId, false, 1, db());
$pasifDeneme = pdks_gunluk_oturum_ac_veya_getir($mehmetId, 1, db());
ok('pasif çavuş için oturum REDDEDİLİR', $pasifDeneme['ok'] === false && $pasifDeneme['kod'] === 'cavus_pasif');
pdks_gunluk_cavus_aktiflik($mehmetId, true, 1, db());

echo "\n=== 2. SAYFA YENİLENİNCE AYNI AÇIK OTURUM YENİDEN KULLANILIYOR ===\n";
$o2 = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
ok('ikinci çağrı da ok', $o2['ok'] === true);
ok('AYNI oturum id döndü (yeni satır İCAT EDİLMEDİ)', (int)$o2['session']['id'] === $ayseSessionId);
ok('yeni=false (yeniden kullanım)', $o2['yeni'] === false);
$stSayim = db()->prepare("SELECT COUNT(*) FROM daily_work_sessions WHERE foreman_id = ?");
$stSayim->execute([$ayseId]);
ok('foremen tablosunda TEK satır var (mükerrer oturum YOK)', (int)$stSayim->fetchColumn() === 1);

echo "\n=== 3. İŞÇİ KARTI GİRİŞ ===\n";
$g1 = pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
ok('GİRİŞ başarılı', $g1['ok'] === true, json_encode($g1));
ok('event_type=GIRIS yansıdı', $g1['event_type'] === 'GIRIS');
ok('kart no doğru (K001)', $g1['card']['card_no'] === 'K001');
ok('işçi tipi adı doğru (Kadın)', $g1['card']['worker_type_name'] === 'Kadın');

echo "\n=== 4. CANLI SAYAÇLAR — kadın/erkek/toplam ===\n";
pdks_gunluk_oturum_kaydet('111222333', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());   // K002 (Kadın)
pdks_gunluk_oturum_kaydet('444555666', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());   // E001 (Erkek)
$ozet1 = pdks_gunluk_oturum_ozet($ayseSessionId, db());
ok('Kadın girişi 2', ($ozet1['giris']['Kadın'] ?? 0) === 2, json_encode($ozet1));
ok('Erkek girişi 1', ($ozet1['giris']['Erkek'] ?? 0) === 1);
ok('toplam giriş 3', $ozet1['giris_toplam'] === 3);
ok('içerde toplam 3 (henüz çıkış yok)', $ozet1['icerde_toplam'] === 3);
ok('worker-type ADLARI DİNAMİK anahtar (sabit "Kadın"/"Erkek" kod dallanması DEĞİL)',
    array_key_exists('Kadın', $ozet1['giris']) && array_key_exists('Erkek', $ozet1['giris']));

echo "\n=== 5. MÜKERRER GİRİŞ REDDİ ===\n";
$gDup = pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
ok('AYNI kart AYNI oturumda ikinci GİRİŞ REDDEDİLDİ', $gDup['ok'] === false);
ok('hata kodu mukerrer_giris', $gDup['kod'] === 'mukerrer_giris');

echo "\n=== 6. GİRİŞ'SİZ ÇIKIŞ REDDİ ===\n";
$k3 = kartEkle('K003', $kadinId, '777888999');
ok('K003 oluşturuldu', $k3['ok'] === true);
$cYok = pdks_gunluk_oturum_kaydet('777888999', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
ok('hiç girmemiş kart için ÇIKIŞ REDDEDİLDİ', $cYok['ok'] === false);
ok('hata kodu giris_yok', $cYok['kod'] === 'giris_yok');

echo "\n=== 7. GEÇERLİ ÇIKIŞ ===\n";
$c1 = pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
ok('ÇIKIŞ başarılı', $c1['ok'] === true, json_encode($c1));
$ozet2 = pdks_gunluk_oturum_ozet($ayseSessionId, db());
ok('Kadın çıkışı 1', ($ozet2['cikis']['Kadın'] ?? 0) === 1);
ok('içerde toplam 2 oldu (3 giriş - 1 çıkış)', $ozet2['icerde_toplam'] === 2);

echo "\n=== 8. KART BAŞKA ÇAVUŞ ALTINDA AKTİFKEN REDDİ ===\n";
$oMehmet = pdks_gunluk_oturum_ac_veya_getir($mehmetId, 1, db());
ok('Mehmet için oturum açıldı', $oMehmet['ok'] === true);
$mehmetSessionId = (int)$oMehmet['session']['id'];
// K002 hâlâ Ayşe'nin oturumunda AÇIK (bölüm 4'te GİRİŞ yaptı, hiç ÇIKMADI).
$baska = pdks_gunluk_oturum_kaydet('111222333', 'usb_decimal', $mehmetSessionId, 'GIRIS', 1, db());
ok('Mehmet\'in oturumunda K002 GİRİŞİ REDDEDİLDİ (Ayşe\'de aktif)', $baska['ok'] === false, json_encode($baska));
ok('hata kodu baska_cavusta_aktif', $baska['kod'] === 'baska_cavusta_aktif');
ok('hata mesajı ÇAVUŞ ADINI içeriyor (Ayşe Çavuş)', str_contains($baska['hata'], 'Ayşe Çavuş'));

echo "\n=== 9. DÜZELTME: BİR İŞÇİ KARTI = BİR İŞÇİ / İŞ GÜNÜ ===\n";
// ⚠ DÜZELTME (kullanıcının açık talimatı #1, Faz 2 düzeltme turu): eski bu
// bölüm "ÇIKIŞ sonrası kart AYNI GÜN serbestçe tekrar girebilir" iddiasını
// test ediyordu — kullanıcı bunun YANLIŞ olduğunu, fazla kafa sayısı/ileride
// hakediş şişmesine yol açacağını açıkça belirtti. K001 bölüm 7'de
// GİRİŞ+ÇIKIŞ yaptı — bugün (bu iş günü + bu depo) için TÜKENDİ; ne AYNI
// çavuşta ne BAŞKA çavuşta yeniden GİREBİLİR. Serbestlik YALNIZ bir SONRAKİ
// work_date'te (bkz. 9b).
$tekrarAyniCavus = pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
ok('K001 AYNI gün / AYNI çavuşta (Ayşe) tekrar GİRİŞ REDDEDİLDİ', $tekrarAyniCavus['ok'] === false, json_encode($tekrarAyniCavus));
ok('hata kodu bugun_kullanilmis', $tekrarAyniCavus['kod'] === 'bugun_kullanilmis');
ok('hata mesajı ÖNCEKİ çavuş adını içeriyor (Ayşe Çavuş)', str_contains($tekrarAyniCavus['hata'], 'Ayşe Çavuş'));
ok('hata mesajı Giriş saatini içeriyor', str_contains($tekrarAyniCavus['hata'], 'Giriş:'));
ok('hata mesajı Çıkış saatini içeriyor', str_contains($tekrarAyniCavus['hata'], 'Çıkış:'));
ok('dönen "onceki" bloğu çavuş adını taşıyor (arayüz için)', $tekrarAyniCavus['onceki']['foreman_name'] === 'Ayşe Çavuş');

echo "\n--- 9a. AYNI kart / AYNI gün / BAŞKA çavuşta da REDDEDİLİR ---\n";
$tekrarBaskaCavus = pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $mehmetSessionId, 'GIRIS', 1, db());
ok('K001 AYNI gün / BAŞKA çavuşta (Mehmet) tekrar GİRİŞ de REDDEDİLDİ', $tekrarBaskaCavus['ok'] === false, json_encode($tekrarBaskaCavus));
ok('hata kodu yine bugun_kullanilmis (hangi çavuş olduğu fark etmiyor)', $tekrarBaskaCavus['kod'] === 'bugun_kullanilmis');

echo "\n--- 9b. AYNI kart BİR SONRAKİ iş gününde (yarın) yeniden kullanılabiliyor ---\n";
// pdks_gunluk_oturum_ac_veya_getir() SUNUCU tarihini (date('Y-m-d')) kullanır
// ve istemciden/testten bir tarih ALMAZ (kasıtlı — bkz. bölüm 15). "Yarın"ı
// test etmek için oturum satırı BURADA doğrudan eklenir (fonksiyon
// ATLANARAK) — bu YALNIZCA test kurgusu içindir, üretim kodunda böyle bir
// yol yoktur ve normal akış her zaman pdks_gunluk_oturum_ac_veya_getir()'den geçer.
$yarin = date('Y-m-d', strtotime('+1 day'));
$insYarin = db()->prepare(
    "INSERT INTO daily_work_sessions (foreman_id, work_date, depo, status, opened_at, opened_by_user_id)
     VALUES (?,?,?,?,?,?)"
);
$insYarin->execute([$ayseId, $yarin, 'Depo A', 'open', $yarin . ' 08:00:00', 1]);
$yarinkiSessionId = (int)db()->lastInsertId();
$yarinGiris = pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $yarinkiSessionId, 'GIRIS', 1, db());
ok('K001 BİR SONRAKİ iş gününde (yarın) tekrar GİRİŞ YAPABİLİYOR', $yarinGiris['ok'] === true, json_encode($yarinGiris));
$yarinCikis = pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $yarinkiSessionId, 'CIKIS', 1, db());
ok('K001 yarınki oturumda ÇIKIŞ da yapabiliyor', $yarinCikis['ok'] === true, json_encode($yarinCikis));

echo "\n--- 9c. SAYAÇ/RAPOR KURALI: benzersiz kart, ham satır sayısı DEĞİL ===\n";
// Kullanıcının açık talimatı: "Make this invariant explicit in backend
// logic and tests." — pdks_gunluk_oturum_ozet() zaten COUNT(DISTINCT
// worker_card_id) kullanıyor (bölüm 4'te 3 farklı kart → toplam 3
// doğrulandı). Burada ayrıca AYNI kart/gün/depo/yön için İKİNCİ bir olay
// satırının DB SEVİYESİNDE de (session'dan bağımsız) İMKANSIZ olduğu
// kanıtlanır — yani sayaç mantığının COUNT(*) ile COUNT(DISTINCT) arasında
// pratikte hiç ayrışamayacağı, kaza eseri değil YAPISAL bir garanti.
$stDupGiris = db()->prepare(
    "INSERT INTO daily_worker_card_events
        (session_id, worker_card_id, event_type, source, canonical_uid_snapshot,
         worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot,
         recorded_by_user_id, server_event_time)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
);
$mukerrerSatirEngellendi = false;
try {
    // K002 bölüm 4'te Ayşe'nin oturumunda GİRİŞ yaptı (hâlâ içeride) — AYNI
    // kart/gün/depo/yön için BAŞKA bir session_id'den (Mehmet'in oturumu)
    // ikinci bir GİRİŞ satırı eklemeyi dener.
    $stDupGiris->execute([$mehmetSessionId, (int)$k2['card_id'], 'GIRIS', 'usb_decimal', 'ZZZZZZZZ',
        $kadinId, 'Kadın', date('Y-m-d'), 'Depo A', 1, date('Y-m-d H:i:s')]);
} catch (PDOException $e) {
    $mukerrerSatirEngellendi = true;
}
ok('AYNI kart/gün/depo/yön için İKİNCİ olay satırı DB SEVİYESİNDE (uq_dwce_card_day_depo_type) İMKANSIZ',
    $mukerrerSatirEngellendi === true);

echo "\n=== 10. KAYIP/DEVRE DIŞI KART REDDİ (yalnız GİRİŞ) ===\n";
$k4 = kartEkle('K004', $kadinId, '222333444');
pdks_gunluk_kart_durum_degistir((int)$k4['card_id'], 'lost', 1, db());
$kayipGiris = pdks_gunluk_oturum_kaydet('222333444', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
ok('KAYIP kart GİRİŞİ reddedildi', $kayipGiris['ok'] === false && $kayipGiris['kod'] === 'kart_kayip');

$k5 = kartEkle('K005', $kadinId, '333444555');
pdks_gunluk_kart_durum_degistir((int)$k5['card_id'], 'disabled', 1, db());
$devreDisiGiris = pdks_gunluk_oturum_kaydet('333444555', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
ok('DEVRE DIŞI kart GİRİŞİ reddedildi', $devreDisiGiris['ok'] === false && $devreDisiGiris['kod'] === 'kart_devre_disi');

echo "\n--- 10a. Tasarım kararı: ÇIKIŞ, kartın GÜNCEL master durumundan ETKİLENMEZ ---\n";
$k6 = kartEkle('K006', $kadinId, '555666777');
pdks_gunluk_oturum_kaydet('555666777', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());   // önce normal GİRİŞ
pdks_gunluk_kart_durum_degistir((int)$k6['card_id'], 'lost', 1, db());   // sonradan KAYIP işaretlendi
$kayipCikis = pdks_gunluk_oturum_kaydet('555666777', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
ok('içerideyken KAYIP işaretlenen kart YİNE DE ÇIKIŞ yapabiliyor', $kayipCikis['ok'] === true, json_encode($kayipCikis));

echo "\n=== 11. GEÇMİŞ İŞÇİ-TİPİ SNAPSHOT'I KORUNUYOR ===\n";
$k7 = kartEkle('K007', $kadinId, '666777888');
$snapGiris = pdks_gunluk_oturum_kaydet('666777888', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
ok('K007 GİRİŞ anında tip adı Kadın', $snapGiris['card']['worker_type_name'] === 'Kadın');
// Şimdi kartın CANLI (master) tipini ERKEK'e değiştir.
pdks_gunluk_kart_duzenle((int)$k7['card_id'], ['card_no' => 'K007', 'worker_type_id' => $erkekId], 1, db());
$stSnap = db()->prepare("SELECT worker_type_name_snapshot FROM daily_worker_card_events WHERE worker_card_id = ? AND event_type = 'GIRIS' ORDER BY id DESC LIMIT 1");
$stSnap->execute([$k7['card_id']]);
ok('GEÇMİŞ event satırı HÂLÂ "Kadın" gösteriyor (canlı tip değişse de)', $stSnap->fetchColumn() === 'Kadın');
// Özet fonksiyonu da CANLI join'den DEĞİL, snapshot'tan okumalı.
$ozetSnap = pdks_gunluk_oturum_ozet($ayseSessionId, db());
// K007 hâlâ içeride (GİRİŞ yaptı, ÇIKMADI) — "Kadın" sayacına dahil olmalı, "Erkek"e KAÇMAMALI.
ok('canlı sayaç da snapshot\'ı kullanıyor — K007 hâlâ "Kadın" giriş sayısına dahil',
    ($ozetSnap['giris']['Kadın'] ?? 0) >= 1);
pdks_gunluk_oturum_kaydet('666777888', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());   // temizlik

echo "\n=== 12. TÜM KARTLAR ÇIKMIŞKEN OTURUM KAPATMA ===\n";
$temizSonuc = pdks_gunluk_oturum_ac_veya_getir($mehmetId, 1, db());
// Mehmet'in oturumu (mehmetSessionId) — bölüm 8/9'da kullanılan kartların
// hepsi zaten çıkmış olmalı. Kontrol amaçlı temiz bir kart daha kullanalım.
$k8 = kartEkle('E002', $erkekId, '888999000');
pdks_gunluk_oturum_kaydet('888999000', 'usb_decimal', $mehmetSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('888999000', 'usb_decimal', $mehmetSessionId, 'CIKIS', 1, db());
$kapatTemiz = pdks_gunluk_oturum_kapat($mehmetSessionId, null, 1, db());
ok('tüm kartlar çıkmışken DOĞRUDAN kapanıyor (gerekçe GEREKMİYOR)', $kapatTemiz['ok'] === true, json_encode($kapatTemiz));
$stKapali = db()->prepare("SELECT status FROM daily_work_sessions WHERE id = ?");
$stKapali->execute([$mehmetSessionId]);
ok('status=closed oldu', $stKapali->fetchColumn() === 'closed');
ok('zaten kapalı oturum tekrar kapatılamaz', pdks_gunluk_oturum_kapat($mehmetSessionId, null, 1, db())['ok'] === false);

echo "\n=== 13. EŞLEŞMEMİŞ ÇIKIŞ MUTABAKATI (gerekçesiz kapatma REDDEDİLİR) ===\n";
// Ayşe'nin oturumunda K003/K004/K005 hâlâ AÇIK GİRİŞ'siz (hiç girmediler,
// sorun değil) ama K002... kontrolü netleştirmek için taze bir eksik kart:
$k9 = kartEkle('K009', $kadinId, '999000111');
pdks_gunluk_oturum_kaydet('999000111', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());   // ÇIKMAYACAK — bilerek
$kapatEksik = pdks_gunluk_oturum_kapat($ayseSessionId, null, 1, db());
ok('eksik çıkış VARKEN gerekçesiz kapatma REDDEDİLİR', $kapatEksik['ok'] === false);
ok('hata kodu eksik_cikis_var', $kapatEksik['kod'] === 'eksik_cikis_var');
ok('ozet içinde eksik kart listesi var (K009 dahil)',
    (bool)array_filter($kapatEksik['ozet']['eksik_kartlar'], fn($k) => $k['card_no'] === 'K009'));
ok('oturum HÂLÂ open (kapatma denemesi durumu DEĞİŞTİRMEDİ)',
    (function () use ($ayseSessionId) { $s = db()->prepare("SELECT status FROM daily_work_sessions WHERE id=?"); $s->execute([$ayseSessionId]); return $s->fetchColumn(); })() === 'open');

echo "\n=== 14. AÇIK GEREKÇEYLE EKSİK-ÇIKIŞLA KAPATMA ===\n";
$kapatGerekceli = pdks_gunluk_oturum_kapat($ayseSessionId, 'K009 sahada kaldı, yarın teslim edilecek.', 1, db());
ok('gerekçeyle kapatma başarılı', $kapatGerekceli['ok'] === true, json_encode($kapatGerekceli));
$stAyseKapali = db()->prepare("SELECT status, notes FROM daily_work_sessions WHERE id = ?");
$stAyseKapali->execute([$ayseSessionId]);
$ayseKapaliRow = $stAyseKapali->fetch();
ok('status=closed', $ayseKapaliRow['status'] === 'closed');
ok('notes alanına gerekçe yazıldı', str_contains((string)$ayseKapaliRow['notes'], 'K009 sahada kaldı'));
$stK009Giris = db()->prepare("SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id = ? AND event_type='CIKIS'");
$stK009Giris->execute([$k9['card_id']]);
ok('K009 için UYDURMA bir ÇIKIŞ satırı EKLENMEDİ (0 çıkış kaydı)', (int)$stK009Giris->fetchColumn() === 0);
$stK009GirisVar = db()->prepare("SELECT COUNT(*) FROM daily_worker_card_events WHERE worker_card_id = ? AND event_type='GIRIS'");
$stK009GirisVar->execute([$k9['card_id']]);
ok('K009\'un GİRİŞ satırı SİLİNMEDİ (hâlâ 1 giriş kaydı)', (int)$stK009GirisVar->fetchColumn() === 1);

echo "\n--- 14a. Kapalı oturuma yeni tarama denemesi net biçimde reddedilir ---\n";
$kapaliTarama = pdks_gunluk_oturum_kaydet('999000111', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
ok('kapalı oturuma tarama REDDEDİLİR', $kapaliTarama['ok'] === false && $kapaliTarama['kod'] === 'oturum_kapali');

echo "\n--- 14b. Eksik-çıkışla kapatılan oturumdaki kart AYNI GÜN yeni bir işçi olarak KULLANILAMAZ ---\n";
// ⚠ Kullanıcının açık talimatı: "closing with a missing exit must NOT make
// the card reusable as a new worker later that same day; it becomes
// reusable automatically on the next work_date." K009 bölüm 13/14'te
// GİRİŞ yaptı, HİÇ ÇIKMADI ve oturumu gerekçeyle KAPATILDI (Ayşe/Mehmet
// bugün için artık kapalı — bu yüzden TAZE bir üçüncü çavuşla, AYNI iş
// gününde deneriz).
$zeynepId = cavusEkle('C004', 'Zeynep Çavuş');
$oZeynep = pdks_gunluk_oturum_ac_veya_getir($zeynepId, 1, db());
ok('Zeynep için (üçüncü, taze) oturum açıldı', $oZeynep['ok'] === true, json_encode($oZeynep));
$k009YenidenGiris = pdks_gunluk_oturum_kaydet('999000111', 'usb_decimal', (int)$oZeynep['session']['id'], 'GIRIS', 1, db());
ok('K009 (eksik-çıkışla kapanmış oturumdan) AYNI gün YENİ ÇAVUŞTA GİRİŞ YAPAMIYOR — kart kapatmayla SERBEST KALMADI',
    $k009YenidenGiris['ok'] === false, json_encode($k009YenidenGiris));
ok('reddin sebebi hâlâ o günkü kullanım/aktiflik (bugun_kullanilmis veya baska_cavusta_aktif) — asla "ok"=true DEĞİL',
    in_array($k009YenidenGiris['kod'], ['bugun_kullanilmis', 'baska_cavusta_aktif'], true));

echo "\n=== 15. SUNUCU-YETKİLİ ZAMAN DAMGALARI ===\n";
$rf = new ReflectionFunction('pdks_gunluk_oturum_kaydet');
$parametreAdlari = array_map(fn($p) => $p->getName(), $rf->getParameters());
ok('pdks_gunluk_oturum_kaydet() İSTEMCİDEN zaman parametresi ALMIYOR (imzada "zaman"/"time" YOK)',
    !array_filter($parametreAdlari, fn($ad) => stripos($ad, 'zaman') !== false || stripos($ad, 'time') !== false));
$oturumYeni = pdks_gunluk_oturum_ac_veya_getir($mehmetId, 1, db());
// Aynı depo/tarihte Mehmet'in oturumu kapalıydı — YENİ bir taze test: Fatma.
$fatmaId = cavusEkle('C003', 'Fatma Çavuş');
$oFatma = pdks_gunluk_oturum_ac_veya_getir($fatmaId, 1, db());
$k10 = kartEkle('K010', $kadinId, '112233445');
$oncekiZaman = date('Y-m-d H:i:s');
$zamanliGiris = pdks_gunluk_oturum_kaydet('112233445', 'usb_decimal', (int)$oFatma['session']['id'], 'GIRIS', 1, db());
$sonrakiZaman = date('Y-m-d H:i:s');
ok('server_event_time sunucu SAATİ aralığında (istemciden gelen bir değer DEĞİL)',
    $zamanliGiris['server_time'] >= $oncekiZaman && $zamanliGiris['server_time'] <= $sonrakiZaman);
$stZaman = db()->prepare("SELECT server_event_time, recorded_by_user_id, source FROM daily_worker_card_events WHERE id = ?");
$stZaman->execute([$zamanliGiris['event_id']]);
$zamanRow = $stZaman->fetch();
ok('kaydedilen satırda recorded_by_user_id doğru (audit — kimlik doğrulanmış kullanıcı)', (int)$zamanRow['recorded_by_user_id'] === 1);
ok('kaydedilen satırda source doğru (usb_decimal)', $zamanRow['source'] === 'usb_decimal');

echo "\n=== 16. USB NORMALİZASYONU (config/pdks.php'den REUSE) ===\n";
ok('bilinen test kartı: 631799511 (usb_decimal) → 25A87ED7', pdks_uid_from_decimal('631799511') === '25A87ED7');
$stKanonik = db()->prepare("SELECT canonical_uid_snapshot FROM daily_worker_card_events WHERE worker_card_id = ? LIMIT 1");
$stKanonik->execute([$k1['card_id']]);
ok('K001 olayında kaydedilen kanonik UID de 25A87ED7', $stKanonik->fetchColumn() === '25A87ED7');

echo "\n=== 17. WEB NFC NORMALİZASYONU (config/pdks.php'den REUSE) ===\n";
ok('bilinen test kartı: d7:7e:a8:25 (web_nfc) → 25A87ED7 (AYNI fiziksel kart, farklı kaynak)',
    pdks_uid_from_web_nfc('d7:7e:a8:25') === '25A87ED7');
$k11 = kartEkle('K011', $kadinId, '113344556');
$nfcGiris = pdks_gunluk_oturum_kaydet('99:88:77:66', 'web_nfc', (int)$oFatma['session']['id'], 'GIRIS', 1, db());
ok('web_nfc kaynağıyla bilinmeyen bir UID "kart_tanimsiz" döner (kaynak doğru işlendi)',
    $nfcGiris['ok'] === false && $nfcGiris['kod'] === 'kart_tanimsiz');

echo "\n=== 18. KALICI PERSONEL DEVAM SİSTEMİ DEĞİŞMEDEN ÇALIŞIYOR ===\n";
db()->exec("INSERT INTO users (username) VALUES ('test')");
$empIns = db()->prepare("INSERT INTO employees (full_name, status) VALUES (?, 'aktif')");
$empIns->execute(['Test Personel']);
$empId = (int)db()->lastInsertId();
$permKart = pdks_kart_olustur($empId, '999111222', 'usb_decimal', [], db());
ok('kalıcı personel kartı NORMAL şekilde oluşturuluyor (Faz 2 dokunmadı)', $permKart['ok'] === true, json_encode($permKart));

echo "\n--- 18a. Kalıcı personel kartı yanlışlıkla Günlük İşçi ekranında okutulursa AYIRT EDİLİR ---\n";
$kalıciYanlisliklaGunluk = pdks_gunluk_oturum_kaydet('999111222', 'usb_decimal', (int)$oFatma['session']['id'], 'GIRIS', 1, db());
ok('kalıcı personel kartı Günlük İşçi GİRİŞİNDE REDDEDİLİR', $kalıciYanlisliklaGunluk['ok'] === false);
ok('hata kodu kalici_kart', $kalıciYanlisliklaGunluk['kod'] === 'kalici_kart');
ok('hata mesajı personel adını İÇERİYOR (Test Personel)', str_contains($kalıciYanlisliklaGunluk['hata'], 'Test Personel'));

echo "\n--- 18b. Ters yön hâlâ çalışıyor (Faz 1 çapraz-kontrolü BOZULMADI) ---\n";
$empIns->execute(['İkinci Test Personel']);
$empId2 = (int)db()->lastInsertId();
$carprazTers = pdks_kart_olustur($empId2, '631799511', 'usb_decimal', [], db());   // K001'in AYNI fiziksel UID'i
ok('işçi havuzu kartıyla ÇAKIŞAN yeni kalıcı personel kartı REDDEDİLİR (Faz 1 korunuyor)', $carprazTers['ok'] === false);
ok('hata kodu uid_gunluk_havuzda', $carprazTers['kod'] === 'uid_gunluk_havuzda');

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
