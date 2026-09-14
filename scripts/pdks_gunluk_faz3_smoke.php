<?php
// =========================================================
// scripts/pdks_gunluk_faz3_smoke.php — Günlük İşçi Faz 3 (günlük puantaj
// raporları) backend testi
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ. Faz 2'nin AYNI deseni:
// elle yazılmış test şeması KULLANMAZ — config/pdks.php + config/pdks_gunluk.php'nin
// GERÇEK MySQL DDL'ini SQLite'a çevirip çalıştırır, GERÇEK Faz 1/2 yazma
// fonksiyonlarıyla (pdks_gunluk_oturum_kaydet vb.) veri üretir ve YALNIZ
// Faz 3'ün YENİ okuma/rapor fonksiyonlarını (pdks_gunluk_gun_ozeti,
// pdks_gunluk_gun_listesi, pdks_gunluk_oturum_kartlari,
// pdks_gunluk_eksik_cikislar, pdks_gunluk_oturum_durumu) doğrular.
//
// Kapsam (görev talimatının 21 test maddesiyle eşlenir):
//   1  tek çavuş günlük özeti          8  tamamlanmış oturum durumu
//   2  aynı tarihte birden çok çavuş   9  eksik-çıkışla kapanmış durum
//   3  kadın/erkek benzersiz sayım    10  ilk GİRİŞ zamanı
//   4  toplam benzersiz işçi sayısı   11  son ÇIKIŞ zamanı
//   5  çıkış sayısı                   12  oturum detay kart satırları
//   6  eksik çıkış sayısı             13  geçmişte snapshot işçi tipi
//   7  açık oturum durumu             14  çavuş/tarih/depo filtreleri
//                                     15  istisna (eksik çıkış) filtresi
//   20 kalıcı PDKS etkilenmedi (sanity — asıl kanıt: pdks_db_smoke.php ayrı çalışır)
//   21 Faz 2 tarama akışı etkilenmedi (sanity — asıl kanıt: pdks_gunluk_faz2_smoke.php ayrı çalışır)
//   16-19 (yetki kapısı/operator/DDL/parametrik filtre) → pdks_gunluk_faz3_static_smoke.php'de
//
//   php scripts/pdks_gunluk_faz3_smoke.php   → çıkış kodu 0 = geçti
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

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — Faz 2 testiyle BİREBİR AYNI (kasıtlı kopya).
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
pdks_gunluk_migrate(db());

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-90s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();

function cavusEkle(string $kod, string $ad): int {
    $r = pdks_gunluk_cavus_olustur(['code' => $kod, 'name' => $ad], 1, db());
    if (!$r['ok']) { fwrite(STDERR, "cavus olusturulamadi: " . json_encode($r) . "\n"); exit(1); }
    return (int)$r['id'];
}
function kartEkle(string $no, int $tipId, string $uid): array {
    return pdks_gunluk_kart_olustur(['card_no' => $no, 'worker_type_id' => $tipId, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
}

$ayseId  = cavusEkle('C001', 'Ayşe Çavuş');
$mehmetId = cavusEkle('C002', 'Mehmet Çavuş');
$fatmaId  = cavusEkle('C003', 'Fatma Çavuş');
$zeynepId = cavusEkle('C004', 'Zeynep Çavuş');

$k1 = kartEkle('K001', $kadinId, '631799511');   // → 25A87ED7
$k2 = kartEkle('K002', $kadinId, '111222333');
$k3 = kartEkle('K003', $kadinId, '777888999');
$e1 = kartEkle('E001', $erkekId, '444555666');
$e2 = kartEkle('E002', $erkekId, '888999000');
$e3 = kartEkle('E003', $erkekId, '222333444');   // Depo B / Zeynep için
ok('test kartları oluşturuldu', $k1['ok'] && $k2['ok'] && $k3['ok'] && $e1['ok'] && $e2['ok'] && $e3['ok']);

$bugun = date('Y-m-d');
$dun   = date('Y-m-d', strtotime('-1 day'));

// ═══════════════════════════════════════════════════════════
echo "\n=== Kurulum: bugünkü mesailer (Depo A) ===\n";

// Ayşe — AÇIK kalır (kasıtlı): K001 giriş+çıkış, E001 giriş+çıkış, K002 giriş (ÇIKMAZ — içeride).
$oAyse = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
ok('Ayşe oturumu açıldı', $oAyse['ok'] === true, json_encode($oAyse));
$ayseSessionId = (int)$oAyse['session']['id'];
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('444555666', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('111222333', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());   // K002 — içeride kalacak
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
pdks_gunluk_oturum_kaydet('444555666', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());

// Mehmet — TAM kapanır (eksiksiz): E002 giriş+çıkış.
$oMehmet = pdks_gunluk_oturum_ac_veya_getir($mehmetId, 1, db());
$mehmetSessionId = (int)$oMehmet['session']['id'];
pdks_gunluk_oturum_kaydet('888999000', 'usb_decimal', $mehmetSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('888999000', 'usb_decimal', $mehmetSessionId, 'CIKIS', 1, db());
$kapatMehmet = pdks_gunluk_oturum_kapat($mehmetSessionId, null, 1, db());
ok('Mehmet oturumu eksiksiz doğrudan kapandı', $kapatMehmet['ok'] === true, json_encode($kapatMehmet));

// Fatma — EKSİK ÇIKIŞLA kapanır (gerekçeli): K003 giriş, ÇIKMAZ, gerekçeyle kapatılır.
$oFatma = pdks_gunluk_oturum_ac_veya_getir($fatmaId, 1, db());
$fatmaSessionId = (int)$oFatma['session']['id'];
pdks_gunluk_oturum_kaydet('777888999', 'usb_decimal', $fatmaSessionId, 'GIRIS', 1, db());
$kapatFatma = pdks_gunluk_oturum_kapat($fatmaSessionId, 'K003 sahada kaldı, yarın teslim.', 1, db());
ok('Fatma oturumu eksik-çıkışla gerekçeli kapandı', $kapatFatma['ok'] === true, json_encode($kapatFatma));

// Zeynep — Depo B (depo filtresi testi için).
$AKTIF_DEPO = 'Depo B';
$oZeynep = pdks_gunluk_oturum_ac_veya_getir($zeynepId, 1, db());
$zeynepSessionId = (int)$oZeynep['session']['id'];
pdks_gunluk_oturum_kaydet('222333444', 'usb_decimal', $zeynepSessionId, 'GIRIS', 1, db());
$AKTIF_DEPO = 'Depo A';   // testin geri kalanı için sıfırla

// Dünkü mesai (tarih filtresi testi için) — doğrudan SQL ile (fonksiyon
// sunucu tarihini date('Y-m-d') ile sabit alır, bkz. Faz 2 bölüm 9b'nin
// AYNI test kurgusu).
db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id) VALUES (?,?,?,?,?,?,?,?)")
    ->execute([$ayseId, 'Ayşe Çavuş', 'C001', $dun, 'Depo A', 'closed', $dun . ' 08:00:00', 1]);
$dunkuSessionId = (int)db()->lastInsertId();
db()->prepare("INSERT INTO daily_worker_card_events
        (session_id, worker_card_id, event_type, source, canonical_uid_snapshot,
         worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot,
         recorded_by_user_id, server_event_time)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$dunkuSessionId, (int)$k3['card_id'], 'GIRIS', 'usb_decimal', 'ZZZZZZZZ', $kadinId, 'Kadın', $dun, 'Depo A', 1, $dun . ' 07:00:00']);

// ═══════════════════════════════════════════════════════════
echo "\n=== 1/2. GÜN LİSTESİ — bugün, Depo A, filtresiz (birden çok çavuş) ===\n";
$liste = pdks_gunluk_gun_listesi($bugun, 'Depo A', null, null, db());
ok('3 oturum döndü (Ayşe/Mehmet/Fatma) — Zeynep (Depo B) ve dünkü (başka tarih) HARİÇ', count($liste) === 3, json_encode(array_column(array_column($liste, 'session'), 'foreman_name_snapshot')));

$byName = [];
foreach ($liste as $row) $byName[$row['session']['foreman_name_snapshot']] = $row;
ok('Ayşe, Mehmet, Fatma hepsi listede', isset($byName['Ayşe Çavuş'], $byName['Mehmet Çavuş'], $byName['Fatma Çavuş']));

echo "\n=== 7. AÇIK OTURUM DURUMU ===\n";
ok('Ayşe (açık, K002 hâlâ içeride) durumu "acik"', $byName['Ayşe Çavuş']['durum']['kod'] === 'acik', json_encode($byName['Ayşe Çavuş']['durum']));
ok('etiket "Açık Mesai" (eksik olsa bile — görev talimatı madde 6)', $byName['Ayşe Çavuş']['durum']['etiket'] === 'Açık Mesai');
ok('Ayşe satırında eksik_toplam=1 (K002) — açık mesaide de görünür kalmalı', $byName['Ayşe Çavuş']['eksik_toplam'] === 1);

echo "\n=== 8. TAMAMLANMIŞ OTURUM DURUMU ===\n";
ok('Mehmet (kapalı, eksiksiz) durumu "tamamlandi"', $byName['Mehmet Çavuş']['durum']['kod'] === 'tamamlandi');
ok('etiket "Tamamlandı"', $byName['Mehmet Çavuş']['durum']['etiket'] === 'Tamamlandı');
ok('Mehmet satırında eksik_toplam=0', $byName['Mehmet Çavuş']['eksik_toplam'] === 0);

echo "\n=== 9. EKSİK-ÇIKIŞLA KAPANMIŞ OTURUM DURUMU ===\n";
ok('Fatma (kapalı, eksik) durumu "eksik_cikis"', $byName['Fatma Çavuş']['durum']['kod'] === 'eksik_cikis');
ok('etiket "Eksik Çıkış"', $byName['Fatma Çavuş']['durum']['etiket'] === 'Eksik Çıkış');
ok('Fatma satırında eksik_toplam=1 (K003)', $byName['Fatma Çavuş']['eksik_toplam'] === 1);

echo "\n=== 10/11. İLK GİRİŞ / SON ÇIKIŞ ZAMANI (gün listesi satırında) ===\n";
$ayseOzet = pdks_gunluk_oturum_ozet($ayseSessionId, db());
ok('Ayşe oturumu ilk_giris = K001 girişi (ilk kaydedilen)', $byName['Ayşe Çavuş']['ilk_giris'] === $ayseOzet['ilk_giris']);
ok('Ayşe oturumu son_cikis = son ÇIKIŞ (E001)', $byName['Ayşe Çavuş']['son_cikis'] === $ayseOzet['son_cikis']);
ok('ilk_giris/son_cikis SUNUCU zaman formatında (Y-m-d H:i:s)', (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$ayseOzet['ilk_giris']));
ok('pdks_gunluk_oturum_ozet() de AYNI ilk_giris/son_cikis alanlarını taşıyor (tek kaynak, tekrar yok)',
    array_key_exists('ilk_giris', $ayseOzet) && array_key_exists('son_cikis', $ayseOzet));

echo "\n=== 3/4/5/6. TEPE ÖZET — BENZERSİZ SAYIM (görev talimatının kritik kuralı) ===\n";
$gunOzeti = pdks_gunluk_gun_ozeti($bugun, 'Depo A', db());
ok('aktif_cavus = 3 (Ayşe+Mehmet+Fatma — Zeynep Depo B\'de HARİÇ)', $gunOzeti['aktif_cavus'] === 3, json_encode($gunOzeti));
ok('Kadın işçi = 3 (K001,K002,K003 — BENZERSİZ kart, ham satır sayısı DEĞİL)', ($gunOzeti['giris']['Kadın'] ?? 0) === 3);
ok('Erkek işçi = 2 (E001,E002)', ($gunOzeti['giris']['Erkek'] ?? 0) === 2);
ok('Toplam işçi = 5', $gunOzeti['giris_toplam'] === 5);
ok('Tam çıkış = 3 (K001,E001,E002 — çıkışı olan BENZERSİZ kartlar)', $gunOzeti['tam_cikis'] === 3);
ok('Eksik çıkış = 2 (K002 açık mesaide içeride + K003 eksik-çıkışla kapanmış)', $gunOzeti['eksik_cikis'] === 2);

echo "\n=== 14a. FİLTRE: ÇAVUŞ (tek çavuşun günlük özeti) ===\n";
$listeAyse = pdks_gunluk_gun_listesi($bugun, 'Depo A', $ayseId, null, db());
ok('yalnız Ayşe filtrelendiğinde TEK satır döner', count($listeAyse) === 1, json_encode($listeAyse));
ok('dönen satır gerçekten Ayşe\'ye ait', $listeAyse[0]['session']['foreman_name_snapshot'] === 'Ayşe Çavuş');

echo "\n=== 14b. FİLTRE: DEPO ===\n";
$listeDepoA = pdks_gunluk_gun_listesi($bugun, 'Depo A', null, null, db());
$listeDepoB = pdks_gunluk_gun_listesi($bugun, 'Depo B', null, null, db());
ok('Depo A listesinde Zeynep YOK', !array_filter($listeDepoA, fn($r) => $r['session']['foreman_name_snapshot'] === 'Zeynep Çavuş'));
ok('Depo B listesinde YALNIZ Zeynep VAR', count($listeDepoB) === 1 && $listeDepoB[0]['session']['foreman_name_snapshot'] === 'Zeynep Çavuş');
$ozetDepoB = pdks_gunluk_gun_ozeti($bugun, 'Depo B', db());
ok('Depo B tepe özeti Depo A\'dan İZOLE (aktif_cavus=1, Erkek=1)', $ozetDepoB['aktif_cavus'] === 1 && ($ozetDepoB['giris']['Erkek'] ?? 0) === 1);

echo "\n=== 14c. FİLTRE: TARİH ===\n";
$listeBugun = pdks_gunluk_gun_listesi($bugun, 'Depo A', null, null, db());
$listeDun   = pdks_gunluk_gun_listesi($dun, 'Depo A', null, null, db());
ok('bugünkü listede dünkü oturum YOK', !array_filter($listeBugun, fn($r) => (int)$r['session']['id'] === $dunkuSessionId));
ok('dünkü tarih filtresiyle YALNIZ dünkü oturum döner', count($listeDun) === 1 && (int)$listeDun[0]['session']['id'] === $dunkuSessionId);
$ozetDun = pdks_gunluk_gun_ozeti($dun, 'Depo A', db());
ok('dünkü tepe özeti bugünden İZOLE (Kadın=1, bugünün 3\'ü karışmıyor)', ($ozetDun['giris']['Kadın'] ?? 0) === 1);

echo "\n=== 14d. FİLTRE: DURUM (4 seçenek — görev talimatı madde 1, madde 6'daki 3'lü etiketten AYRI) ===\n";
$fAcik   = pdks_gunluk_gun_listesi($bugun, 'Depo A', null, 'acik', db());
$fKapali = pdks_gunluk_gun_listesi($bugun, 'Depo A', null, 'kapali', db());
$fEksik  = pdks_gunluk_gun_listesi($bugun, 'Depo A', null, 'eksik_cikis', db());
ok('"Açık" filtresi yalnız Ayşe\'yi döner', count($fAcik) === 1 && $fAcik[0]['session']['foreman_name_snapshot'] === 'Ayşe Çavuş', json_encode($fAcik));
ok('"Kapalı" filtresi Mehmet+Fatma\'yı döner (Tamamlandı VE Eksik Çıkış — İKİSİ de "kapalı"dır)', count($fKapali) === 2);
ok('"Eksik Çıkışlı" filtresi AÇIK/KAPALI FARK ETMEKSİZİN Ayşe+Fatma\'yı döner (çapraz-kesen istisna filtresi)',
    count($fEksik) === 2 && (bool)array_filter($fEksik, fn($r) => $r['session']['foreman_name_snapshot'] === 'Ayşe Çavuş')
                          && (bool)array_filter($fEksik, fn($r) => $r['session']['foreman_name_snapshot'] === 'Fatma Çavuş'));

echo "\n=== 12. OTURUM DETAY KART SATIRLARI ===\n";
$kartlarAyse = pdks_gunluk_oturum_kartlari($ayseSessionId, db());
ok('Ayşe oturumunda 3 kart satırı (K001,K002,E001)', count($kartlarAyse) === 3, json_encode($kartlarAyse));
$byCard = [];
foreach ($kartlarAyse as $k) $byCard[$k['card_no']] = $k;
ok('K001 durumu "tam" (giriş+çıkış)', $byCard['K001']['durum']['kod'] === 'tam' && $byCard['K001']['durum']['etiket'] === '✅ Tam');
ok('K001 çıkış saati DOLU', $byCard['K001']['cikis_saat'] !== null);
ok('K002 durumu "cikis_yok" (yalnız giriş)', $byCard['K002']['durum']['kod'] === 'cikis_yok' && $byCard['K002']['durum']['etiket'] === '⚠️ Çıkış Yok');
ok('K002 çıkış saati NULL (UYDURMA zaman YOK)', $byCard['K002']['cikis_saat'] === null);
ok('E001 durumu "tam"', $byCard['E001']['durum']['kod'] === 'tam');
ok('kart satırları tip alanında SNAPSHOT metnini taşıyor (worker_type_name_snapshot)', $byCard['K001']['tip'] === 'Kadın');

echo "\n=== 13. GEÇMİŞTE SNAPSHOT İŞÇİ TİPİ KORUNUYOR (Faz 3'ün YENİ sorgularında da) ===\n";
pdks_gunluk_kart_duzenle((int)$k1['card_id'], ['card_no' => 'K001', 'worker_type_id' => $erkekId], 1, db());   // K001 canlıda Erkek'e alındı
$kartlarAyseSonra = pdks_gunluk_oturum_kartlari($ayseSessionId, db());
$byCardSonra = [];
foreach ($kartlarAyseSonra as $k) $byCardSonra[$k['card_no']] = $k;
ok('oturum detayı K001\'i HÂLÂ "Kadın" gösteriyor (canlı tip Erkek\'e alınsa da)', $byCardSonra['K001']['tip'] === 'Kadın');
$gunOzetiSonra = pdks_gunluk_gun_ozeti($bugun, 'Depo A', db());
ok('gün özeti de HÂLÂ Kadın=3/Erkek=2 (canlı tip değişikliği geçmiş sayaçları KAYDIRMADI)',
    ($gunOzetiSonra['giris']['Kadın'] ?? 0) === 3 && ($gunOzetiSonra['giris']['Erkek'] ?? 0) === 2, json_encode($gunOzetiSonra));

echo "\n--- 13a. ÇAVUŞ ADI SNAPSHOT'I DA KORUNUYOR (Faz 3'ün foreman_name_snapshot kararı) ---\n";
pdks_gunluk_cavus_guncelle($ayseId, ['code' => 'C001', 'name' => 'Ayşe YENİ SOYAD', 'is_active' => 1], 1, db());
$listeSonra = pdks_gunluk_gun_listesi($bugun, 'Depo A', $ayseId, null, db());
ok('rapor satırı HÂLÂ "Ayşe Çavuş" gösteriyor (foremen.name SONRADAN değişse de — görev talimatı madde 14)',
    $listeSonra[0]['session']['foreman_name_snapshot'] === 'Ayşe Çavuş', json_encode($listeSonra[0]['session']));
$stCanli = db()->prepare("SELECT name FROM foremen WHERE id = ?");
$stCanli->execute([$ayseId]);
ok('canlı foremen.name GERÇEKTEN değişti (test verisi doğru kuruldu)', $stCanli->fetchColumn() === 'Ayşe YENİ SOYAD');

echo "\n=== 15. İSTİSNA (EKSİK ÇIKIŞ) RAPORU — TÜM oturumlar genelinde ===\n";
$eksikler = pdks_gunluk_eksik_cikislar($bugun, 'Depo A', null, db());
ok('2 eksik çıkış satırı (K002 açık + K003 kapalı-eksik)', count($eksikler) === 2, json_encode($eksikler));
$byEkCard = [];
foreach ($eksikler as $e) $byEkCard[$e['card_no']] = $e;
ok('K002 satırı VAR (Ayşe, hâlâ açık oturum)', isset($byEkCard['K002']));
ok('K002 satırında oturum_durumu=open, kapanış mesajı YOK', $byEkCard['K002']['oturum_durumu'] === 'open' && $byEkCard['K002']['oturum_kapali_mesaji'] === null);
ok('K003 satırı VAR (Fatma, kapalı oturum)', isset($byEkCard['K003']));
ok('K003 satırında oturum_durumu=closed', $byEkCard['K003']['oturum_durumu'] === 'closed');
ok('K003 satırında "Mesai eksik çıkışla kapatıldı." mesajı VAR', $byEkCard['K003']['oturum_kapali_mesaji'] === 'Mesai eksik çıkışla kapatıldı.');
ok('K003 satırında kapanış notu (gerekçe) İLETİLİYOR', str_contains((string)$byEkCard['K003']['kapanis_notu'], 'K003 sahada kaldı'));
ok('eksik satırlarda UYDURMA bir çıkış zamanı YOK (yalnız giriş_saat alanı var)', !array_key_exists('cikis_saat', $byEkCard['K002']));

echo "\n--- 15a. İSTİSNA raporu da çavuş filtresine SAYGI DUYUYOR ---\n";
$eksiklerFatma = pdks_gunluk_eksik_cikislar($bugun, 'Depo A', $fatmaId, db());
ok('yalnız Fatma filtrelenince tek satır (K003)', count($eksiklerFatma) === 1 && $eksiklerFatma[0]['card_no'] === 'K003');

echo "\n=== 20. KALICI PDKS ETKİLENMEDİ (sanity — asıl kanıt: pdks_db_smoke.php ayrı çalışır) ===\n";
db()->exec("INSERT INTO users (username) VALUES ('test')");
$empIns = db()->prepare("INSERT INTO employees (full_name, status) VALUES (?, 'aktif')");
$empIns->execute(['Test Personel']);
$empId = (int)db()->lastInsertId();
$permKart = pdks_kart_olustur($empId, '999111222', 'usb_decimal', [], db());
ok('kalıcı personel kartı Faz 3\'ten SONRA da normal oluşturuluyor', $permKart['ok'] === true, json_encode($permKart));

echo "\n=== 21. FAZ 2 TARAMA AKIŞI ETKİLENMEDİ (sanity — asıl kanıt: pdks_gunluk_faz2_smoke.php ayrı çalışır) ===\n";
$k9 = kartEkle('K009', $kadinId, '333444555');
$oTaze = pdks_gunluk_oturum_ac_veya_getir($zeynepId, 1, db());   // Depo A'da Zeynep'in BUGÜNKÜ ilk oturumu
$tazeSessionId = (int)$oTaze['session']['id'];
ok('Faz 2 GİRİŞ akışı (yeni snapshot kolonlarıyla) HÂLÂ çalışıyor', $oTaze['ok'] === true);
$g9 = pdks_gunluk_oturum_kaydet('333444555', 'usb_decimal', $tazeSessionId, 'GIRIS', 1, db());
ok('GİRİŞ kaydı başarılı', $g9['ok'] === true, json_encode($g9));
$g9tekrar = pdks_gunluk_oturum_kaydet('333444555', 'usb_decimal', $tazeSessionId, 'GIRIS', 1, db());
ok('Faz 2\'nin "bir kart = bir işçi/iş günü" kuralı HÂLÂ çalışıyor (mükerrer_giris)', $g9tekrar['ok'] === false && $g9tekrar['kod'] === 'mukerrer_giris');
ok('foreman_name_snapshot/foreman_code_snapshot yeni oturumda da DOLU', $oTaze['session']['foreman_name_snapshot'] === 'Zeynep Çavuş' && $oTaze['session']['foreman_code_snapshot'] === 'C004');

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
