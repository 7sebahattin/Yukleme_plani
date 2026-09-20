<?php
// =========================================================
// scripts/pdks_kart_sorgula_smoke.php — Kart Havuzu → "Kart Sorgula"
// (Sprint Kart-Sorgula-01) İŞ KURALI testi.
//
// SADECE CLI. CANLI VERİTABANINA HİÇ DOKUNMAZ — diğer pdks_gunluk_faz8a_*
// smoke betikleriyle AYNI desen: GERÇEK MySQL DDL'ini (pdks_tablolar/
// pdks_gunluk_tablolar/pdks_gunluk_faz8a_tablolar) SQLite'a çevirip bellek
// içinde çalıştırır.
//
//   php scripts/pdks_kart_sorgula_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$KOK = dirname(__DIR__);

$PERMS = [];
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
$IS_ADMIN = false;
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
$AKTIF_DEPO = 'Depo A';
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
function audit_log_event(...$a): void {}

require_once $KOK . '/config/pdks.php';
require_once $KOK . '/config/pdks_gunluk.php';
require_once $KOK . '/config/pdks_faz8j.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — diğer pdks_gunluk_* smoke betikleriyle
// BİREBİR AYNI (kasıtlı kopya).
// ─────────────────────────────────────────────────────────
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
            $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")"; continue;
        }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")"; continue;
        }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
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

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $db; return $db; }

$db->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
foreach (pdks_tablolar() as $ad => $sql) {
    // ⚠ Kalıcı personel kart tabloları AYRICA gerekli — pdks_gunluk_faz8a_smoke.php
    // İLE AYNI seçim: kalıcı-kart-çakışması testi için şart, diğerleri kapsam dışı.
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    $db->exec($create);
    foreach ($indeksler as $ix) $db->exec($ix);
}
foreach (pdks_gunluk_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    $db->exec($create);
    foreach ($indeksler as $ix) $db->exec($ix);
}
pdks_gunluk_migrate($db);
// ⚠ pdks_gunluk_faz8a_smoke.php İLE AYNI desen: daily_worker_work_periods
// ÖNCE gerçek DDL'den çevrilip oluşturulur — migrate() sonra yalnız
// ALTER/nötr-hâle-getirme adımlarını uygular (bkz. o betiğin aynı yorumu).
[$create, $indeksler] = pdks_ddl_sqlite(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
$db->exec($create);
foreach ($indeksler as $ix) $db->exec($ix);
pdks_gunluk_faz8a_migrate($db);
pdks_faz8j_migrate($db);   // is_voided/voided_at kolonları — bkz. config/pdks_faz8j.php

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-95s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

// ─────────────────────────────────────────────────────────
// Tohum verisi
// ─────────────────────────────────────────────────────────
$ayse = pdks_gunluk_cavus_olustur(['code' => 'AC01', 'name' => 'Ayşe Çavuş'], 1, $db);
$erkekId = (int)$db->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$kadinId = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();

echo "\n=== 1. HİÇ TANIMLI OLMAYAN UID ===\n";
$r1 = pdks_gunluk_faz8a_kart_sorgula('TANIMSIZ-UID-001', $db);
ok('ok=true döner', $r1['ok'] === true);
ok('bulundu=false', $r1['bulundu'] === false);
ok('kalici_cakisma=false', $r1['kalici_cakisma'] === false);

echo "\n=== 2. KALICI PERSONEL KARTIYLA ÇAKIŞMA (günlük havuzda YOK) ===\n";
$db->exec("INSERT INTO employees (id, full_name) VALUES (1, 'Mehmet Personel')");
$db->exec("INSERT INTO employee_cards (id, employee_id, uid_hex, status) VALUES (1, 1, 'KALICI-UID-01', 'aktif')");
$db->exec("INSERT INTO employee_card_uids (id, card_id, uid_hex) VALUES (1, 1, 'KALICI-UID-01')");
$r2 = pdks_gunluk_faz8a_kart_sorgula('KALICI-UID-01', $db);
ok('bulundu=false (günlük havuzda kart yok)', $r2['bulundu'] === false);
ok('kalici_cakisma=true', $r2['kalici_cakisma'] === true);
ok('kalici_isim doğru', $r2['kalici_isim'] === 'Mehmet Personel');

echo "\n=== 3. KART VAR — HİÇ TARAMA YAPILMAMIŞ ===\n";
$kart = pdks_gunluk_kart_olustur(['card_no' => 'K900', 'ham_uid' => '900000001', 'kaynak' => 'usb_decimal'], 1, $db);
ok('kart oluşturuldu', $kart['ok'] === true, json_encode($kart));
$kanonikK900 = $db->query("SELECT canonical_uid FROM worker_cards WHERE card_no='K900'")->fetchColumn();
$r3 = pdks_gunluk_faz8a_kart_sorgula($kanonikK900, $db);
ok('bulundu=true', $r3['bulundu'] === true);
ok('card_no doğru', $r3['card_no'] === 'K900');
ok('acik=null (henüz taranmadı)', $r3['acik'] === null);
ok('gecmis boş dizi', $r3['gecmis'] === []);

echo "\n=== 4. GİRİŞ YAPILMIŞ (AÇIK DÖNEM) ===\n";
$oturum = pdks_gunluk_oturum_ac_veya_getir((int)$ayse['id'], 1, $db);
$sid = (int)$oturum['session']['id'];
$g = pdks_gunluk_faz8a_giris_kaydet('900000001', 'usb_decimal', $sid, $erkekId, 'tam', 1, $db);
ok('GİRİŞ kabul edildi', $g['ok'] === true, json_encode($g));
$r4 = pdks_gunluk_faz8a_kart_sorgula($kanonikK900, $db);
ok('acik dolu', $r4['acik'] !== null);
ok('acik.foreman_name doğru', ($r4['acik']['foreman_name'] ?? null) === 'Ayşe Çavuş');
ok('acik.tip doğru', ($r4['acik']['tip'] ?? null) === 'Erkek');
ok('acik.depo doğru', ($r4['acik']['depo'] ?? null) === 'Depo A');
// ⚠ Açık dönem "Son 5 Dönem" listesinde de görünür (status='open', exit_time
// NULL) — "şu an" bloğu ile "geçmiş" listesi AYRI amaçlar: biri özet, diğeri
// tam dökümdür. Ön yüz (isci_kartlari.php) açık satırı "(henüz açık)" yazar.
ok('gecmiste 1 kayıt var (açık dönem de listelenir)', count($r4['gecmis']) === 1);
ok('gecmis satırı status=open', ($r4['gecmis'][0]['status'] ?? null) === 'open');
ok('gecmis satırı exit_time NULL/boş', empty($r4['gecmis'][0]['exit_time']));

echo "\n=== 5. ÇIKIŞ DA YAPILMIŞ (DÖNEM KAPANDI) ===\n";
$c = pdks_gunluk_faz8a_cikis_kaydet('900000001', 'usb_decimal', $sid, 1, $db);
ok('ÇIKIŞ kabul edildi', $c['ok'] === true, json_encode($c));
$r5 = pdks_gunluk_faz8a_kart_sorgula($kanonikK900, $db);
ok('acik=null (dönem kapandı)', $r5['acik'] === null);
ok('gecmiste 1 kayıt var', count($r5['gecmis']) === 1);
ok('gecmis.exit_time dolu', !empty($r5['gecmis'][0]['exit_time']));
ok('gecmis.cavus doğru', $r5['gecmis'][0]['cavus'] === 'Ayşe Çavuş');
ok('gecmis.tip doğru', $r5['gecmis'][0]['tip'] === 'Erkek');

echo "\n=== 6. SON 5 İLE SINIRLI + EN YENİ EN ÜSTTE ===\n";
// Aynı kartla 5 tur daha giriş/çıkış yap — toplam 6 kapalı dönem olacak.
for ($i = 0; $i < 5; $i++) {
    pdks_gunluk_faz8a_giris_kaydet('900000001', 'usb_decimal', $sid, $erkekId, 'tam', 1, $db);
    pdks_gunluk_faz8a_cikis_kaydet('900000001', 'usb_decimal', $sid, 1, $db);
}
$r6 = pdks_gunluk_faz8a_kart_sorgula($kanonikK900, $db);
ok('toplam 6 dönem varken yalnız 5 döner', count($r6['gecmis']) === 5);
$stTumu = $db->prepare("SELECT id FROM daily_worker_work_periods WHERE worker_card_id=(SELECT id FROM worker_cards WHERE card_no='K900') ORDER BY entry_time DESC LIMIT 1");
$stTumu->execute();
$enYeniId = (int)$stTumu->fetchColumn();
$stEnYeniZaman = $db->prepare("SELECT entry_time FROM daily_worker_work_periods WHERE id=?");
$stEnYeniZaman->execute([$enYeniId]);
ok('en üstteki satır en yeni giriş zamanına ait (DESC sıralama)', $r6['gecmis'][0]['entry_time'] === $stEnYeniZaman->fetchColumn());

echo "\n=== 7. İPTAL EDİLMİŞ (is_voided) DÖNEM GEÇMİŞTE GÖRÜNMEZ ===\n";
$db->exec("UPDATE daily_worker_work_periods SET is_voided=1 WHERE worker_card_id=(SELECT id FROM worker_cards WHERE card_no='K900') AND id=$enYeniId");
$r7 = pdks_gunluk_faz8a_kart_sorgula($kanonikK900, $db);
ok('iptal edilen dönem artık listede YOK (hâlâ 5 kayıt — bir öncekiler devreye girdi)', count($r7['gecmis']) === 5);
$idleriIcerirMi = false;
foreach ($r7['gecmis'] as $satir) { if ($satir['entry_time'] === $stEnYeniZaman->fetchColumn()) $idleriIcerirMi = true; }

echo "\n=== 8. KART SORGUSU HİÇBİR ŞEY YAZMAZ (salt okunur garanti) ===\n";
$oncekiEventSayisi = (int)$db->query("SELECT COUNT(*) FROM daily_worker_card_events")->fetchColumn();
$oncekiPeriodSayisi = (int)$db->query("SELECT COUNT(*) FROM daily_worker_work_periods")->fetchColumn();
pdks_gunluk_faz8a_kart_sorgula($kanonikK900, $db);
pdks_gunluk_faz8a_kart_sorgula('TANIMSIZ-BASKA-UID', $db);
ok('event sayısı değişmedi', (int)$db->query("SELECT COUNT(*) FROM daily_worker_card_events")->fetchColumn() === $oncekiEventSayisi);
ok('period sayısı değişmedi', (int)$db->query("SELECT COUNT(*) FROM daily_worker_work_periods")->fetchColumn() === $oncekiPeriodSayisi);

echo "\n=== SONUÇ: $gecen geçti, $fail hata ===\n";
exit($fail === 0 ? 0 : 1);
