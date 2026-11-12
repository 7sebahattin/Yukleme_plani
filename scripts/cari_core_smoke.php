<?php
// =========================================================
// scripts/cari_core_smoke.php — Cari İşlemler çekirdeği (config/cari_core.php) testi
//   php scripts/cari_core_smoke.php     → çıkış kodu 0 = hepsi geçti
// Bellek içi SQLite (siparis harness'i + siparis_ddl_sqlite çeviricisi). Canlı DB'ye HİÇ dokunmaz.
// SIPARIS_TEST_MYSQL_DSN verilirse (dbname 'test' içermeli) cari_migrate gerçek DDL'i çalıştırır.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/cari_core.php';

$pdo = db();
siparis_test_diger_tablolar($pdo);   // users + audit_log
if (siparis_test_mysql()) { $pdo->exec('DROP TABLE IF EXISTS material_definitions'); $pdo->exec('DROP TABLE IF EXISTS cari_hareketler'); $pdo->exec('DROP TABLE IF EXISTS cari_kartlar'); $pdo->exec('DROP TABLE IF EXISTS cari_tanimlar'); }
$pdo->exec(siparis_test_mysql()
    ? 'CREATE TABLE material_definitions (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(30), name VARCHAR(150), is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    : 'CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)');

function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function audit_say(string $a): int { return say('audit_log', "module = 'cari' AND action = ?", [$a]); }
$UID = 1; $IS_ADMIN = true; $AKTIF_DEPO = 'DEPO1';
$bugun = date('Y-m-d');

echo "=== 1. Şema / migrasyon ===\n";
ok('tablo yokken cari_hazir false', cari_hazir($pdo) === false);
if (!siparis_test_mysql()) {
    $GLOBALS['_cari_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void {
        [$c, $ix] = siparis_ddl_sqlite($ddl);
        $p->exec($c);
        foreach ($ix as $x) $p->exec($x);
    };
}
$m = cari_migrate($pdo);
ok('migrate: 3 tablo olusturuldu', count($m) === 3 && count(array_filter($m, fn($r) => $r['durum'] === 'olusturuldu')) === 3, json_encode($m));
ok('migrate sonrası cari_hazir true', cari_hazir($pdo) === true);
ok('ödeme şekli tohumu 6 satır', say('cari_tanimlar', "tur = 'odeme_sekli'") === 6);
$m2 = cari_migrate($pdo);
ok('migrate tekrar: hepsi var, tohum çoğalmadı', count(array_filter($m2, fn($r) => $r['durum'] === 'var')) === 3 && say('cari_tanimlar') === 6);

echo "\n=== 2. Yetki ===\n";
ok('admin: cari_can true', cari_can('read') && cari_can('write') && cari_can('delete') && cari_can('admin'));
$IS_ADMIN = false;
ok('admin değil: cari_can hepsi false', !cari_can('read') && !cari_can('write') && !cari_can('delete') && !cari_can('admin'));
ok('bilinmeyen eylem false', !cari_can('xyz'));
$IS_ADMIN = true;

echo "\n=== 3. Biçim / ayrıştırma ===\n";
ok('tutar "1.234,56" → 123456', cari_tutar_coz('1.234,56') === 123456);
ok('tutar "12.500" → 1250000', cari_tutar_coz('12.500') === 1250000);
ok('tutar "1234.5" → 123450', cari_tutar_coz('1234.5') === 123450);
ok('tutar "0,005" reddedilir (2\'den fazla ondalık)', cari_tutar_coz('0,005') === null);
ok('tutar "-5" reddedilir', cari_tutar_coz('-5') === null);
ok('tutar "abc" / "" / "0" reddedilir', cari_tutar_coz('abc') === null && cari_tutar_coz('') === null && cari_tutar_coz('0') === null);
ok('tutar üst sınır aşımı reddedilir', cari_tutar_coz('1.000.000.000.000,00') === null && cari_tutar_coz('999.999.999.999,99') === 99999999999999);
ok('cari_kurus: "0.1"+"0.2" kuruş tam', cari_kurus('0.10') + cari_kurus('0.20') === 30 && cari_kurus('1234.56') === 123456 && cari_kurus(null) === 0);
ok('tutar biçimi', cari_tutar_bicim(123456, 'TRY') === '1.234,56 ₺' && cari_tutar_bicim(-5, 'USD') === '-0,05 $' && cari_tutar_bicim(100, 'EUR', false) === '1,00');
ok('bakiye etiketi', cari_bakiye_etiketi(1)['metin'] === 'Borçlu' && cari_bakiye_etiketi(-1)['sinif'] === 'cr-a' && cari_bakiye_etiketi(0)['metin'] === 'Denk');
ok('belge no biçimi', cari_belge_no('tahsilat', '2026-03-04', 123) === 'TAH-2026-000123' && cari_belge_no('virman', '2026-03-04', 7) === 'VRM-2026-000007');
ok('kur: TRY\'de dolu reddedilir (false), boş null', cari_kur_coz('32,5', 'TRY') === false && cari_kur_coz('', 'TRY') === null && cari_kur_coz(' ', 'USD') === null);
ok('kur: "32,4512" → 32.451200; 7 ondalık ve 0 reddedilir', cari_kur_coz('32,4512', 'USD') === '32.451200' && cari_kur_coz('1.1234567', 'USD') === false && cari_kur_coz('0', 'USD') === false && cari_kur_coz('x', 'EUR') === false);
ok('tarih sınırları', cari_tarih_gecerli($bugun) && !cari_tarih_gecerli('1999-12-31') && !cari_tarih_gecerli('2026-02-30') && !cari_tarih_gecerli(date('Y-m-d', strtotime('+400 days'))) && !cari_tarih_gecerli('01.02.2026'));
ok('kod normalize büyük harf + trim', cari_kod_normalize(' abc-1 ') === 'ABC-1');

echo "\n=== 4. Kart doğrulama / CRUD ===\n";
ok('kod önerisi boş tabloda C00001', cari_kod_oner($pdo) === 'C00001');
$r = cari_kart_kaydet($pdo, ['unvan' => 'A'], $UID);
ok('unvan kısa → hata', !$r['ok'] && isset($r['alanlar']['unvan']));
$r = cari_kart_kaydet($pdo, ['unvan' => 'Alfa Gıda', 'tip' => 'xx'], $UID);
ok('geçersiz tip → hata', !$r['ok'] && isset($r['alanlar']['tip']));
$r = cari_kart_kaydet($pdo, ['unvan' => 'Alfa Gıda', 'vergi_no' => '123'], $UID);
ok('vergi no 10/11 hane kuralı', !$r['ok'] && isset($r['alanlar']['vergi_no']));
$r = cari_kart_kaydet($pdo, ['unvan' => 'Alfa Gıda', 'eposta' => 'yanlis'], $UID);
ok('e-posta doğrulaması', !$r['ok'] && isset($r['alanlar']['eposta']));
$r = cari_kart_kaydet($pdo, ['unvan' => 'Alfa Gıda', 'iban' => 'TR12'], $UID);
ok('IBAN doğrulaması', !$r['ok'] && isset($r['alanlar']['iban']));
$r = cari_kart_kaydet($pdo, ['unvan' => 'Alfa Gıda', 'vade_gun' => '400'], $UID);
ok('vade 0-365', !$r['ok'] && isset($r['alanlar']['vade_gun']));
$r = cari_kart_kaydet($pdo, ['unvan' => 'Alfa Gıda', 'para_birimi' => 'GBP'], $UID);
ok('para birimi beyaz liste', !$r['ok'] && isset($r['alanlar']['para_birimi']));
$r = cari_kart_kaydet($pdo, ['unvan' => 'Alfa Gıda', 'grup_id' => 999], $UID);
ok('olmayan grup reddedilir', !$r['ok'] && isset($r['alanlar']['grup_id']));
$gr = cari_tanim_kaydet($pdo, 'grup', 'Yurt İçi', 10, $UID);
ok('grup tanımı eklendi', $gr['ok'] && $gr['id'] > 0);
$A = cari_kart_kaydet($pdo, ['unvan' => 'Alfa Gıda', 'tip' => 'musteri', 'grup_id' => $gr['id'], 'vergi_no' => '1234567890', 'eposta' => 'a@ornek.test', 'iban' => 'tr12 3456 7890 1234 5678 9012 34', 'vade_gun' => '30'], $UID);
ok('kart eklendi + kod C00001', $A['ok'] && cari_kart_oku($pdo, $A['id'])['kod'] === 'C00001' && cari_kart_oku($pdo, $A['id'])['grup_adi'] === 'Yurt İçi');
ok('IBAN normalize (boşluksuz büyük)', cari_kart_oku($pdo, $A['id'])['iban'] === 'TR123456789012345678901234');
$B = cari_kart_kaydet($pdo, ['unvan' => 'Beta Tedarik', 'tip' => 'tedarikci', 'kod' => 'bt-1'], $UID);
ok('kod büyük harfe çevrilip saklanır', $B['ok'] && cari_kart_oku($pdo, $B['id'])['kod'] === 'BT-1');
$r = cari_kart_kaydet($pdo, ['unvan' => 'Başka', 'kod' => 'Bt-1'], $UID);
ok('kod tekilliği harf-duyarsız', !$r['ok'] && isset($r['alanlar']['kod']));
$r = cari_kart_kaydet($pdo, ['unvan' => 'Beta Tedarik Ltd', 'kod' => 'BT-1', 'tip' => 'tedarikci'], $UID, $B['id']);
ok('güncelleme kendi koduyla çakışmaz + audit update', $r['ok'] && cari_kart_oku($pdo, $B['id'])['unvan'] === 'Beta Tedarik Ltd' && audit_say('update') >= 1);
$C = cari_kart_kaydet($pdo, ['unvan' => 'Gama Ltd', 'tip' => 'ikisi', 'para_birimi' => 'USD'], $UID);
ok('kod önerisi ardışık (C00002 → C00003 sonra)', $C['ok'] && cari_kart_oku($pdo, $C['id'])['kod'] === 'C00002' && cari_kod_oner($pdo) === 'C00003');
ok('kart oluşturma audit', audit_say('create') >= 4);
$kl = cari_kart_listesi($pdo, ['q' => 'alfa']);
ok('liste q araması', count($kl['satirlar']) === 1 && $kl['satirlar'][0]['id'] === $A['id'] && $kl['kesildi'] === false);
ok('liste tip filtresi', count(cari_kart_listesi($pdo, ['tip' => 'tedarikci'])['satirlar']) === 1);

echo "\n=== 5. Hareket: yön, para birimi ayrımı, doğrulama ===\n";
$aId = $A['id']; $bId = $B['id']; $cId = $C['id'];
$h = fn(array $x) => cari_hareket_ekle($pdo, $x + ['cari_id' => $aId, 'tarih' => $bugun, 'para_birimi' => 'TRY'], $UID);
$r = $h(['tur' => 'odeme', 'tutar' => '1.000,00', 'odeme_sekli' => 'Nakit']);
ok('ödeme fişi: yon B, belge no ODE-', $r['ok'] && cari_hareket_oku($pdo, $r['id'])['yon'] === 'B' && str_starts_with($r['belge_no'], 'ODE-' . date('Y') . '-'));
$r = $h(['tur' => 'tahsilat', 'tutar' => '250,50', 'odeme_sekli' => 'Havale/EFT', 'evrak_no' => 'EV-1']);
$tahId = $r['id'];
ok('tahsilat fişi: yon A + evrak no + ödeme şekli', $r['ok'] && ($x = cari_hareket_oku($pdo, $tahId)) && $x['yon'] === 'A' && $x['evrak_no'] === 'EV-1' && $x['odeme_sekli'] === 'Havale/EFT' && $x['depo'] === 'DEPO1');
$r = $h(['tur' => 'acilis', 'tutar' => '100', 'yon' => 'A', 'aciklama' => 'Devir']);
ok('açılış: seçilen yön (A)', $r['ok'] && cari_hareket_oku($pdo, $r['id'])['yon'] === 'A');
$r = $h(['tur' => 'acilis', 'tutar' => '100', 'aciklama' => 'Devir']);
ok('açılışta yön zorunlu', !$r['ok'] && isset($r['alanlar']['yon']));
$r = $h(['tur' => 'acilis', 'tutar' => '100', 'yon' => 'B']);
ok('açılışta açıklama zorunlu', !$r['ok'] && isset($r['alanlar']['aciklama']));
$r = $h(['tur' => 'borc_dekont', 'tutar' => '40']);
ok('dekontta açıklama zorunlu', !$r['ok'] && isset($r['alanlar']['aciklama']));
$r = $h(['tur' => 'borc_dekont', 'tutar' => '40', 'aciklama' => 'Fark']);
ok('borç dekontu yon B', $r['ok'] && cari_hareket_oku($pdo, $r['id'])['yon'] === 'B');
$r = $h(['tur' => 'alacak_dekont', 'tutar' => '10', 'aciklama' => 'Fark']);
ok('alacak dekontu yon A', $r['ok'] && cari_hareket_oku($pdo, $r['id'])['yon'] === 'A');
ok('geçersiz tur reddedilir; virman bu uçtan girilmez', !$h(['tur' => 'xx', 'tutar' => '1'])['ok'] && !$h(['tur' => 'virman', 'tutar' => '1', 'aciklama' => 'abc'])['ok']);
ok('geçersiz tutar reddedilir', !$h(['tur' => 'odeme', 'tutar' => '0,005'])['ok'] && !$h(['tur' => 'odeme', 'tutar' => '-1'])['ok']);
ok('TRY satırında kur reddedilir', ($r = $h(['tur' => 'odeme', 'tutar' => '1', 'kur' => '30'])) && !$r['ok'] && isset($r['alanlar']['kur']));
ok('geçersiz tarih / para birimi / ödeme şekli reddedilir', !$h(['tur' => 'odeme', 'tutar' => '1', 'tarih' => '2026-13-01'])['ok'] && !$h(['tur' => 'odeme', 'tutar' => '1', 'para_birimi' => 'GBP'])['ok'] && !$h(['tur' => 'odeme', 'tutar' => '1', 'odeme_sekli' => 'Bitcoin'])['ok']);
ok('olmayan cari reddedilir', !cari_hareket_ekle($pdo, ['cari_id' => 9999, 'tur' => 'odeme', 'tutar' => '1', 'tarih' => $bugun, 'para_birimi' => 'TRY'], $UID)['ok']);
// TRY: B 1000 + 40 − A 250,50 − 100 − 10 = 679,50
$b = cari_bakiye($pdo, $aId);
ok('TRY bakiye = ΣB − ΣA = 679,50 (borçlu)', $b['TRY']['bakiye'] === 67950 && $b['TRY']['borc'] === 104000 && $b['TRY']['alacak'] === 36050 && cari_bakiye_etiketi($b['TRY']['bakiye'])['metin'] === 'Borçlu');
$r = $h(['tur' => 'tahsilat', 'tutar' => '50', 'para_birimi' => 'USD', 'kur' => '32,5']);
$usdId = $r['id'];
$h(['tur' => 'odeme', 'tutar' => '20', 'para_birimi' => 'EUR', 'kur' => '35']);
$b = cari_bakiye($pdo, $aId);
ok('3 para birimi AYRI bakiye (TRY/USD/EUR), karışmaz', count($b) === 3 && $b['TRY']['bakiye'] === 67950 && $b['USD']['bakiye'] === -5000 && $b['EUR']['bakiye'] === 2000);
ok('USD satırı kur saklar', cari_hareket_oku($pdo, $usdId)['kur'] !== null && abs((float)cari_hareket_oku($pdo, $usdId)['kur'] - 32.5) < 1e-9);
ok('hareket audit sayısı', audit_say('create') >= 11);
ok('float tuzağı: 0,10 + 0,20 borç = 30 kuruş', ($h(['cari_id' => $bId, 'tur' => 'odeme', 'tutar' => '0,10']) + $h(['cari_id' => $bId, 'tur' => 'odeme', 'tutar' => '0,20'])) && cari_bakiye($pdo, $bId)['TRY']['bakiye'] === 30);

echo "\n=== 6. Çift gönderim (istek_anahtari) ===\n";
$r1 = $h(['tur' => 'tahsilat', 'tutar' => '5', 'istek_anahtari' => 'abc123']);
$r2 = $h(['tur' => 'tahsilat', 'tutar' => '5', 'istek_anahtari' => 'abc123']);
ok('ikinci gönderim aynı kaydı döner (tekrar=true), tek satır', $r1['ok'] && $r2['ok'] && $r2['id'] === $r1['id'] && ($r2['tekrar'] ?? false) === true && say('cari_hareketler', "istek_anahtari = 'abc123'") === 1);
ok('geçersiz istek anahtarı reddedilir', !$h(['tur' => 'tahsilat', 'tutar' => '5', 'istek_anahtari' => 'a b!'])['ok']);

echo "\n=== 7. İptal / bakiye / ekstre ===\n";
$bef = cari_bakiye($pdo, $aId)['TRY']['bakiye'];   // 679,50 − 5 (tahsilat) = 674,50
ok('iptal öncesi TRY = 674,50', $bef === 67450);
$r = cari_hareket_iptal($pdo, $r1['id'], 'x', $UID);
ok('iptal nedeni ≥3 zorunlu', !$r['ok']);
$r = cari_hareket_iptal($pdo, $r1['id'], 'Yanlış giriş', $UID);
ok('iptal ok + audit cancel', $r['ok'] && audit_say('cancel') === 1 && cari_hareket_oku($pdo, $r1['id'])['durum'] === 'iptal');
ok('iptal sonrası bakiye geri döndü (679,50)', cari_bakiye($pdo, $aId)['TRY']['bakiye'] === 67950);
$r = cari_hareket_iptal($pdo, $r1['id'], 'Yine', $UID);
ok('çift iptal reddedilir (zaten_iptal)', !$r['ok'] && ($r['kod'] ?? '') === 'zaten_iptal');
ok('olmayan hareket iptali reddedilir', !cari_hareket_iptal($pdo, 99999, 'Neden', $UID)['ok']);
ok('iptal satırı silinmedi, iptal alanları dolu', ($x = cari_hareket_oku($pdo, $r1['id'])) && $x['iptal_neden'] === 'Yanlış giriş' && (int)$x['iptal_by'] === $UID && $x['iptal_at'] !== null);
$pdo->prepare("UPDATE cari_hareketler SET kaynak_modul = 'siparis_odeme', kaynak_id = 1 WHERE id = ?")->execute([$tahId]);
$r = cari_hareket_iptal($pdo, $tahId, 'Deneme', $UID);
ok('kaynaklı hareket iptal edilemez (kaynakli)', !$r['ok'] && ($r['kod'] ?? '') === 'kaynakli');
$pdo->prepare('UPDATE cari_hareketler SET kaynak_modul = NULL, kaynak_id = NULL WHERE id = ?')->execute([$tahId]);

// Ekstre: ayrı cari (Gama, USD) ile kuruş hassasiyeti ve devir
$e = fn(array $x) => cari_hareket_ekle($pdo, $x + ['cari_id' => $cId, 'para_birimi' => 'USD', 'kur' => '30'], $UID);
$e(['tur' => 'acilis', 'yon' => 'B', 'tutar' => '100,10', 'tarih' => '2026-01-10', 'aciklama' => 'Açılış']);
$e(['tur' => 'odeme', 'tutar' => '0,20', 'tarih' => '2026-02-05']);
$iptalE = $e(['tur' => 'odeme', 'tutar' => '500', 'tarih' => '2026-02-06']);
cari_hareket_iptal($pdo, $iptalE['id'], 'Hatalı', $UID);
$e(['tur' => 'tahsilat', 'tutar' => '0,10', 'tarih' => '2026-02-20']);
$e(['tur' => 'tahsilat', 'tutar' => '10', 'tarih' => '2026-03-01']);
$x = cari_ekstre($pdo, $cId, ['bas' => '2026-02-01', 'bit' => '2026-02-28']);
$u = $x['USD'] ?? [];
ok('ekstre: yalnız USD, devir 100,10', array_keys($x) === ['USD'] && $u['devir'] === 10010);
ok('ekstre: dönem borç 0,20 / alacak 0,10, kapanış 100,20 (kuruş tam)', $u['donem_borc'] === 20 && $u['donem_alacak'] === 10 && $u['kapanis'] === 10020);
ok('ekstre: iptal satırı varsayılan görünmez; 2 satır; yürüyen 100,30 → 100,20', count($u['satirlar']) === 2 && $u['satirlar'][0]['yuruyen'] === 10030 && $u['satirlar'][1]['yuruyen'] === 10020);
ok('ekstre: dönem sonrası (Mart) satır dışarıda', !in_array('2026-03-01', array_column($u['satirlar'], 'tarih'), true));
$x2 = cari_ekstre($pdo, $cId, ['bas' => '2026-02-01', 'bit' => '2026-02-28', 'iptaller_gorunsun' => true])['USD'];
$ip = array_values(array_filter($x2['satirlar'], fn($s) => $s['durum'] === 'iptal'));
ok('iptaller görünür: yürüyen null, toplamlar/kapanış değişmedi', count($x2['satirlar']) === 3 && count($ip) === 1 && $ip[0]['yuruyen'] === null && $x2['kapanis'] === 10020 && $x2['donem_borc'] === 20);
ok('ekstre: kur varsa TL karşılığı (USD 0,20 × 30 = 6,00)', $u['satirlar'][0]['tl_karsilik'] === 600 && abs((float)$u['satirlar'][0]['kur'] - 30) < 1e-9);
$xt = cari_ekstre($pdo, $aId, [])['TRY'];
ok('ekstre: dönem filtresiz devir 0, kapanış = bakiye', $xt['devir'] === 0 && $xt['kapanis'] === cari_bakiye($pdo, $aId)['TRY']['bakiye']);
ok('ekstre: pb filtresi', array_keys(cari_ekstre($pdo, $aId, ['pb' => 'EUR'])) === ['EUR']);
ok('bitis tarihli bakiye (cari_bakiye bitis)', cari_bakiye($pdo, $cId, '2026-01-31')['USD']['bakiye'] === 10010);
$tp = cari_bakiyeler_toplu($pdo, [$aId, $cId]);
ok('bakiyeler_toplu id → pb → bakiye', $tp[$aId]['TRY'] === 67950 && $tp[$cId]['USD'] === cari_bakiye($pdo, $cId)['USD']['bakiye']);
$go = cari_genel_ozet($pdo);
ok('genel özet pb başına ayrı (TRY/USD/EUR anahtarları)', isset($go['TRY'], $go['USD'], $go['EUR']) && $go['TRY']['borclu_toplam'] > 0);
$kl = cari_kart_listesi($pdo, ['bakiye' => 'borclu']);
ok('liste bakiyeler dolu + borçlu filtresi', count($kl['satirlar']) >= 1 && ($kl['satirlar'][0]['bakiyeler'] ?? []) !== []);
ok('liste alacaklı filtresi (USD bakiyesi negatif olan A)', in_array($aId, array_column(cari_kart_listesi($pdo, ['bakiye' => 'alacakli'])['satirlar'], 'id'), true));

echo "\n=== 8. Virman ===\n";
$vir = fn(array $x) => cari_virman_ekle($pdo, $x + ['tarih' => $bugun, 'para_birimi' => 'TRY', 'aciklama' => 'Mahsup'], $UID);
ok('kaynak = hedef reddedilir', !$vir(['kaynak_id' => $aId, 'hedef_id' => $aId, 'tutar' => '10'])['ok']);
ok('açıklama zorunlu', !cari_virman_ekle($pdo, ['kaynak_id' => $aId, 'hedef_id' => $bId, 'tutar' => '10', 'tarih' => $bugun, 'para_birimi' => 'TRY'], $UID)['ok']);
ok('geçersiz tutar reddedilir', !$vir(['kaynak_id' => $aId, 'hedef_id' => $bId, 'tutar' => '0'])['ok']);
$say0 = say('cari_hareketler');
$aOnce = cari_bakiye($pdo, $aId)['TRY']['bakiye']; $bOnce = cari_bakiye($pdo, $bId)['TRY']['bakiye'];
$v = $vir(['kaynak_id' => $aId, 'hedef_id' => $bId, 'tutar' => '100,00', 'istek_anahtari' => 'vir-1']);
ok('virman ok: 2 satır eklendi', $v['ok'] && say('cari_hareketler') === $say0 + 2);
$s1 = cari_hareket_oku($pdo, $v['id']); $s2 = cari_hareket_oku($pdo, $v['id2']);
ok('kaynak A / hedef B, ortak belge_no VRM-, çapraz bagli_id', $s1['yon'] === 'A' && $s1['cari_id'] == $aId && $s2['yon'] === 'B' && $s2['cari_id'] == $bId && $s1['belge_no'] === $s2['belge_no'] && str_starts_with($s1['belge_no'], 'VRM-') && (int)$s1['bagli_id'] === $s2['id'] && (int)$s2['bagli_id'] === $s1['id']);
ok('bakiyeler: kaynak −100, hedef +100', cari_bakiye($pdo, $aId)['TRY']['bakiye'] === $aOnce - 10000 && cari_bakiye($pdo, $bId)['TRY']['bakiye'] === $bOnce + 10000);
ok('virman çift gönderim tek çift', ($t = $vir(['kaynak_id' => $aId, 'hedef_id' => $bId, 'tutar' => '100,00', 'istek_anahtari' => 'vir-1'])) && $t['ok'] && ($t['tekrar'] ?? false) && say('cari_hareketler') === $say0 + 2);
ok('farklı para birimi: kur/tutar yine tek pb (virman pb ile yazılır)', ($vu = $vir(['kaynak_id' => $aId, 'hedef_id' => $cId, 'tutar' => '5', 'para_birimi' => 'USD', 'kur' => '30'])) && $vu['ok'] && cari_hareket_oku($pdo, $vu['id2'])['para_birimi'] === 'USD');
$r = cari_hareket_iptal($pdo, $v['id2'], 'Virman hatalı', $UID);   // hedef satırdan iptal
ok('virman iptali iki satırı birlikte iptal eder', $r['ok'] && count($r['iptal_idler']) === 2 && cari_hareket_oku($pdo, $v['id'])['durum'] === 'iptal' && cari_hareket_oku($pdo, $v['id2'])['durum'] === 'iptal');
ok('iptal sonrası bakiyeler virman öncesine döndü', cari_bakiye($pdo, $aId)['TRY']['bakiye'] === $aOnce && cari_bakiye($pdo, $bId)['TRY']['bakiye'] === $bOnce);
$lst = cari_hareket_listesi($pdo, ['tur' => 'virman', 'durum' => 'tumu']);
ok('listede virman satırları karşı cari unvanıyla', $lst['toplam_satir'] >= 4 && array_filter($lst['satirlar'], fn($s) => ($s['bagli_cari_unvan'] ?? '') !== ''));

echo "\n=== 9. Pasif kart / silme kuralları ===\n";
$D = cari_kart_kaydet($pdo, ['unvan' => 'Delta Boş'], $UID);
ok('hareketsiz kart pasifleştirilir (uyarı yok)', ($r = cari_kart_durum($pdo, $D['id'], false, $UID)) && $r['ok'] && $r['uyari'] === null && (int)cari_kart_oku($pdo, $D['id'])['aktif'] === 0);
$r = cari_hareket_ekle($pdo, ['cari_id' => $D['id'], 'tur' => 'odeme', 'tutar' => '1', 'tarih' => $bugun, 'para_birimi' => 'TRY'], $UID);
ok('pasif karta yeni hareket reddedilir', !$r['ok']);
ok('pasif karta virman reddedilir', !cari_virman_ekle($pdo, ['kaynak_id' => $aId, 'hedef_id' => $D['id'], 'tutar' => '1', 'tarih' => $bugun, 'para_birimi' => 'TRY', 'aciklama' => 'Deneme'], $UID)['ok']);
cari_kart_durum($pdo, $D['id'], true, $UID);
$r = cari_hareket_ekle($pdo, ['cari_id' => $D['id'], 'tur' => 'odeme', 'tutar' => '1', 'tarih' => $bugun, 'para_birimi' => 'TRY', 'istek_anahtari' => 'dlt-1'], $UID);
ok('aktif edilince hareket girilir', $r['ok']);
cari_kart_durum($pdo, $D['id'], false, $UID);
$r2 = cari_hareket_iptal($pdo, $r['id'], 'Pasif karttaki iptal', $UID);
ok('pasif kartta iptal serbest', $r2['ok']);
$k = cari_kart_durum($pdo, $aId, false, $UID);
ok('bakiyesi sıfır olmayan kart pasife alınır + uyarı döner', $k['ok'] && $k['uyari'] !== null && audit_say('deactivate') >= 2);
cari_kart_durum($pdo, $aId, true, $UID);
$r = cari_kart_sil($pdo, $aId, $UID);
ok('hareketli kart silinemez (hareketli)', !$r['ok'] && ($r['kod'] ?? '') === 'hareketli');
$r = cari_kart_sil($pdo, $D['id'], $UID);
ok('yalnız iptal satırı olan kart da silinemez', !$r['ok'] && ($r['kod'] ?? '') === 'hareketli' && cari_kart_oku($pdo, $D['id']) !== null);
$E = cari_kart_kaydet($pdo, ['unvan' => 'Epsilon Hareketsiz'], $UID);
$r = cari_kart_sil($pdo, $E['id'], $UID);
ok('hareketsiz kart silinir + audit delete', $r['ok'] && cari_kart_oku($pdo, $E['id']) === null && audit_say('delete') === 1);
ok('olmayan kart silme/durum hata', !cari_kart_sil($pdo, 99999, $UID)['ok'] && !cari_kart_durum($pdo, 99999, true, $UID)['ok']);

echo "\n=== 10. Liste sorguları ===\n";
$l = cari_hareket_listesi($pdo, ['sinif' => 'fis', 'cari_id' => $aId]);
ok('fiş sınıfı: dekont/virman yok', $l['toplam_satir'] > 0 && !array_filter($l['satirlar'], fn($s) => in_array($s['tur'], ['borc_dekont', 'alacak_dekont', 'virman'], true)));
ok('varsayılan yalnız geçerli', !array_filter($l['satirlar'], fn($s) => $s['durum'] === 'iptal'));
$li = cari_hareket_listesi($pdo, ['durum' => 'iptal']);
ok('durum=iptal yalnız iptaller', $li['toplam_satir'] > 0 && !array_filter($li['satirlar'], fn($s) => $s['durum'] !== 'iptal'));
ok('özet pb → tur → adet/kuruş (yalnız geçerli)', ($l['ozet']['TRY']['odeme']['adet'] ?? 0) >= 1 && is_int($l['ozet']['TRY']['odeme']['kurus']) && isset($l['ozet']['USD']));
ok('tarih/pb/q filtreleri', cari_hareket_listesi($pdo, ['pb' => 'EUR'])['toplam_satir'] === 1 && cari_hareket_listesi($pdo, ['bas' => '2026-02-01', 'bit' => '2026-02-28', 'cari_id' => $cId])['toplam_satir'] === 2 && cari_hareket_listesi($pdo, ['q' => 'Alfa'])['toplam_satir'] > 0 && cari_hareket_listesi($pdo, ['q' => 'ODE-'])['toplam_satir'] > 0);
ok('sayfalama (limit 2)', count(cari_hareket_listesi($pdo, [], 2, 0)['satirlar']) === 2);

echo "\n=== 11. Tanımlar ===\n";
ok('tanım tekilliği TR-duyarsız', !cari_tanim_kaydet($pdo, 'grup', 'YURT İÇİ', 5, $UID)['ok'] && !cari_tanim_kaydet($pdo, 'odeme_sekli', 'nakit', 5, $UID)['ok']);
ok('aynı ad farklı türde serbest', cari_tanim_kaydet($pdo, 'odeme_sekli', 'Yurt İçi', 5, $UID)['ok']);
ok('geçersiz tür / boş ad reddedilir', !cari_tanim_kaydet($pdo, 'xx', 'Ad', 1, $UID)['ok'] && !cari_tanim_kaydet($pdo, 'grup', '  ', 1, $UID)['ok']);
$lt = cari_tanim_listesi($pdo, 'grup');
ok('grup listesi kullanım sayısı (1 kart)', count($lt) === 1 && (int)$lt[0]['kullanim'] === 1);
$r = cari_tanim_sil($pdo, $gr['id'], $UID);
ok('kullanılan grup silinemez (kullanimda)', !$r['ok'] && ($r['kod'] ?? '') === 'kullanimda');
$g2 = cari_tanim_kaydet($pdo, 'grup', 'Yurt Dışı', 20, $UID);
ok('kullanılmayan grup silinir', $g2['ok'] && cari_tanim_sil($pdo, $g2['id'], $UID)['ok'] && say('cari_tanimlar', "ad = 'Yurt Dışı'") === 0);
$ren = cari_tanim_kaydet($pdo, 'grup', 'Yurt İçi 2', 10, $UID, $gr['id']);
ok('grup adı değişimi karta yansır (id bağlı)', $ren['ok'] && cari_kart_oku($pdo, $aId)['grup_adi'] === 'Yurt İçi 2');
$os = cari_tanim_listesi($pdo, 'odeme_sekli');
$nakit = array_values(array_filter($os, fn($t) => $t['ad'] === 'Nakit'))[0];
ok('ödeme şekli kullanım sayısı hareket sayar', (int)$nakit['kullanim'] >= 1);
cari_tanim_durum($pdo, (int)$nakit['id'], false, $UID);
ok('pasif ödeme şekli yeni harekette reddedilir; yalnızAktif listesinde yok', !$h(['tur' => 'odeme', 'tutar' => '1', 'odeme_sekli' => 'Nakit'])['ok'] && !array_filter(cari_tanim_listesi($pdo, 'odeme_sekli', true), fn($t) => $t['ad'] === 'Nakit'));
ok('ödeme şekli serbest silinir', cari_tanim_sil($pdo, (int)$nakit['id'], $UID)['ok']);
ok('olmayan tanım hata', !cari_tanim_sil($pdo, 99999, $UID)['ok'] && !cari_tanim_durum($pdo, 99999, true, $UID)['ok']);

echo "\n=== 12. İçe aktarım ===\n";
$ins = $pdo->prepare('INSERT INTO material_definitions (type, name, is_active) VALUES (?, ?, ?)');
foreach ([['firma', 'Zeta Ihracat', 1], ['tedarikci', 'Eta Tedarik', 1], ['firma', 'Ortak Firma', 1], ['tedarikci', 'ORTAK FIRMA', 1], ['firma', 'Pasif Firma', 0], ['firma', 'Alfa Gıda', 1], ['depo', 'DEPO1', 1]] as $r) $ins->execute($r);
$on = cari_ice_aktar_onizle($pdo);
$by = array_column($on, null, 'ad');
ok('önizleme: pasif/depo hariç 5 ad (ortak ad tek)', count($on) === 4 + 0 && !isset($by['Pasif Firma']) && !isset($by['DEPO1']), (string)count($on));
ok('önizleme: tip eşlemesi + ortak ad → ikisi', $by['Zeta Ihracat']['hedef_tip'] === 'musteri' && $by['Eta Tedarik']['hedef_tip'] === 'tedarikci' && $by['Ortak Firma']['hedef_tip'] === 'ikisi');
ok('önizleme: aynı unvanlı kart → var_unvan', $by['Alfa Gıda']['durum'] === 'var_unvan' && $by['Zeta Ihracat']['durum'] === 'yeni');
$kartOnce = say('cari_kartlar');
$ia = cari_ice_aktar($pdo, $UID);
ok('içe aktarım: 3 yeni kart eklendi, 1 atlandı + özet audit', $ia['ok'] && $ia['eklenen'] === 3 && $ia['atlanan'] === 1 && say('cari_kartlar') === $kartOnce + 3 && audit_say('cari_ice_aktar') === 1);
ok('md_id dolu + ardışık kodlar', say('cari_kartlar', 'md_id IS NOT NULL') === 3);
$ia2 = cari_ice_aktar($pdo, $UID);
ok('ikinci çalıştırma 0 ekler (idempotent)', $ia2['eklenen'] === 0 && say('cari_kartlar') === $kartOnce + 3);
$by2 = array_column(cari_ice_aktar_onizle($pdo), null, 'ad');
ok('sonra önizleme: var_md', $by2['Zeta Ihracat']['durum'] === 'var_md' && $by2['Ortak Firma']['durum'] === 'var_md');

echo "\n=== 12b. Sayfa/uç katmanı için çekirdek ekleri ===\n";
$za = (int)cari_kart_kaydet($pdo, ['unvan' => 'ZZ Liste A', 'tip' => 'musteri', 'para_birimi' => 'TRY', 'aktif' => '1'], $UID)['id'];
$zb = (int)cari_kart_kaydet($pdo, ['unvan' => 'ZZ Liste B', 'tip' => 'musteri', 'para_birimi' => 'TRY', 'aktif' => '1'], $UID)['id'];
cari_hareket_ekle($pdo, ['cari_id' => $za, 'tur' => 'tahsilat', 'tarih' => $bugun, 'tutar' => '10,00', 'para_birimi' => 'TRY', 'aciklama' => 'zz tahsilat'], $UID);
$vz = cari_virman_ekle($pdo, ['kaynak_id' => $za, 'hedef_id' => $zb, 'tarih' => $bugun, 'tutar' => '5,00', 'para_birimi' => 'TRY', 'aciklama' => 'zz virman'], $UID);
$L = cari_hareket_listesi($pdo, ['cari_q' => 'ZZ Liste'], 100, 0);
ok('cari_q: ünvan/kod LIKE → yalnız ZZ kartlarının satırları (1 tahsilat + 2 virman satırı)', $L['toplam_satir'] === 3 && count($L['satirlar']) === 3, (string)$L['toplam_satir']);
$L2 = cari_hareket_listesi($pdo, ['cari_q' => 'ZZ Liste', 'virman_tek' => true], 100, 0);
ok('virman_tek: virman çifti TEK satır (kaynak/A satırı), özet adedi 1 (çift sayılmaz)', $L2['toplam_satir'] === 2 && $L2['ozet']['TRY']['virman']['adet'] === 1 && $L2['ozet']['TRY']['virman']['kurus'] === 500);
$vs = array_values(array_filter($L2['satirlar'], fn($r) => $r['tur'] === 'virman'))[0];
ok('liste satırı: bagli_cari_id/unvan karşı carinin', (int)$vs['cari_id'] === $za && (int)$vs['bagli_cari_id'] === $zb && $vs['bagli_cari_unvan'] === 'ZZ Liste B' && $vs['yon'] === 'A');
$ek = cari_ekstre($pdo, $za, ['pb' => 'TRY'])['TRY']['satirlar'];
$ev = array_values(array_filter($ek, fn($r) => $r['tur'] === 'virman'))[0];
ok('ekstre satırı: id + yon + kaynak_modul(null) + bagli_cari_id (iptal düğmesi/karşı cari bağlantısı için)', $ev['id'] === (int)$vz['id'] && $ev['yon'] === 'A' && array_key_exists('kaynak_modul', $ev) && $ev['kaynak_modul'] === null && $ev['bagli_cari_id'] === $zb);
ok('cari_pb_sirala: TRY, USD, EUR, bilinmeyen sona', array_keys(cari_pb_sirala(['EUR' => 1, 'ZZZ' => 4, 'USD' => 3, 'TRY' => 2])) === ['TRY', 'USD', 'EUR', 'ZZZ']);
ok('cari_kart_pbler: hareketli para birimleri (sıralı); hareket sayısı', cari_kart_pbler($pdo, $za) === ['TRY'] && cari_kart_hareket_say($pdo, $za) === 2 && cari_kart_pbler($pdo, 999999) === [] && cari_kart_hareket_say($pdo, 999999) === 0);
ok('cari_geri_guvenli: yalnız yerel cari_*.php (+ sorgu); açık yönlendirme/CRLF/yol gezme → cari_kartlari.php',
    cari_geri_guvenli('cari_fisler.php?bas=2026-01-01') === 'cari_fisler.php?bas=2026-01-01' && cari_geri_guvenli('cari_kartlari.php') === 'cari_kartlari.php'
    && cari_geri_guvenli('https://x.example/') === 'cari_kartlari.php' && cari_geri_guvenli('//x.example') === 'cari_kartlari.php' && cari_geri_guvenli("cari_x.php\r\nSet-Cookie: a=b") === 'cari_kartlari.php'
    && cari_geri_guvenli('../index.php') === 'cari_kartlari.php' && cari_geri_guvenli('index.php') === 'cari_kartlari.php' && cari_geri_guvenli(null) === 'cari_kartlari.php' && cari_geri_guvenli(['cari_a.php']) === 'cari_kartlari.php');
ok('cari_csv_para: binlik ayraçsız, virgül ondalık', cari_csv_para(12540000) === '125400,00' && cari_csv_para(5) === '0,05');

echo "\n=== 13. Kaynak değişmezleri ===\n";
$src = (string)file_get_contents($ROOT . '/config/cari_core.php');
preg_match_all('/UPDATE\s+cari_hareketler\s+SET\s+([^"\']+?)\s+WHERE/i', $src, $mm);
$setler = array_map(fn($s) => preg_replace('/\s+/', ' ', $s), $mm[1]);
ok('hareket UPDATE\'leri yalnız belge_no/bagli_id ve iptal alanları', count($setler) === 2 && !array_filter($setler, fn($s) => preg_match('/\b(tutar|cari_id|tarih|para_birimi|yon)\s*=/', $s)), implode(' | ', $setler));
ok('hareket tablosunda DELETE yok', !preg_match('/DELETE\s+FROM\s+cari_hareketler/i', $src));
ok('depo filtre yardımcıları çağrılmaz (bilinçli istisna)', !preg_match('/depo_sql_|depot_visible_to_user/', preg_replace('#//[^\n]*#', '', $src)));
ok('named parametre kullanılmaz', !preg_match('/(?:WHERE|AND|SET|VALUES)[^;]*\s:\w+/', $src));

siparis_test_bitir();
