<?php
// =========================================================
// scripts/faz2_butunluk_smoke.php — Faz 2 Veritabanı Bütünlüğü,
// Eşzamanlılık Kilitleri ve İş Mantığı Düzeltmeleri Smoke Testi
// (B-01, B-05, B-08, B-19, B-20, B-23, B-25, B-26)
//   php scripts/faz2_butunluk_smoke.php   → çıkış kodu 0 = hepsi geçti
// Bellek içi SQLite. Canlı DB'ye HİÇ dokunmaz.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/stok_core.php';
require_once $ROOT . '/config/fatura_core.php';
require_once $ROOT . '/config/kasa_core.php';
require_once $ROOT . '/config/ceksenet_core.php';
require_once $ROOT . '/config/depo_core.php';
require_once $ROOT . '/config/hesap_calc.php';
require_once $ROOT . '/config/cekirdek_migrate.php';
require_once $ROOT . '/config/muhasebe_roller.php';
require_once $ROOT . '/config/role_data_scope.php';

$pdo = db();
siparis_test_diger_tablolar($pdo);

// SQLite DDL kurulumu (cari, stok, fatura, kasa, ceksenet, depo)
$ddlYukle = static function (PDO $p, array $tablolar): void {
    foreach ($tablolar as $ddl) {
        [$c, $ix] = siparis_ddl_sqlite($ddl);
        $p->exec($c);
        foreach ($ix as $x) $p->exec($x);
    }
};
$ddlYukle($pdo, cari_tablolar());
$ddlYukle($pdo, stok_tablolar());
$ddlYukle($pdo, fatura_tablolar());
$ddlYukle($pdo, kasa_tablolar());
$ddlYukle($pdo, ceksenet_tablolar());
$ddlYukle($pdo, depo_tablolar());

echo "=== 1. [B-01] Runtime Auto-Migration / DDL / UPDATE Temizliği ===\n";
$dbSrc      = (string)file_get_contents($ROOT . '/config/db.php');
$helpersSrc = (string)file_get_contents($ROOT . '/config/helpers.php');
$cikmaSrc   = (string)file_get_contents($ROOT . '/cikmalar.php');
$cekMigSrc  = (string)file_get_contents($ROOT . '/config/cekirdek_migrate.php');

ok('config/db.php icinde ALTER/CREATE/SHOW COLUMNS/UPDATE kalmadi',
    !preg_match('/\b(ALTER\s+TABLE|CREATE\s+TABLE|SHOW\s+COLUMNS|UPDATE\s+`?kantar_gruplar)/i', $dbSrc));
ok('config/helpers.php icinde otomatik calisan PHP migration IIFE kalmadi; adli cekirdek_helpers_migrate() var',
    !preg_match('/^\(function\s*\(\)\s*\{/m', $helpersSrc) && str_contains($helpersSrc, 'function cekirdek_helpers_migrate('));
$cekNoComment = (string)preg_replace('/\/\/[^\n]*/', '', $cekMigSrc);
ok('TEXT NOT NULL DEFAULT \'\' strict-mode hatasi temizlendi (helpers + cekirdek_migrate DDL)',
    !preg_match('/TEXT\s+NOT\s+NULL\s+DEFAULT\s+\'\'/i', $helpersSrc)
    && !preg_match('/TEXT\s+NOT\s+NULL\s+DEFAULT\s+\'\'/i', $cekNoComment));
ok('cikmalar.php icinde ALTER TABLE kalmadi', !preg_match('/ALTER\s+TABLE/i', $cikmaSrc));
ok('hesap_migrate() varsayilan cagrida 0 sorgu ile bos doner', hesap_migrate() === []);

echo "\n=== 2. [B-05] MySQL REPEATABLE READ FOR UPDATE Kilitleri ===\n";
$stokSrc = (string)file_get_contents($ROOT . '/config/stok_core.php');
ok('stok_maliyet_durumu $kilitle parametresi ve FOR UPDATE icerir',
    str_contains($stokSrc, 'function stok_maliyet_durumu(PDO $pdo, int $kartId, bool $kilitle = false)')
    && str_contains($stokSrc, 'stok_maliyet_durumu($pdo, $kart, true)'));
ok('stok_fis_eksikler $kilitle parametresi ve FOR UPDATE icerir',
    str_contains($stokSrc, 'function stok_fis_eksikler(PDO $pdo, array $v, bool $kilitle = false)')
    && str_contains($stokSrc, 'stok_fis_eksikler($pdo, $v, true)'));
ok('stok_fis_iptal kart_id listesini tx disinda okur ve tx basinda stok_kart_kilitle cagirir',
    preg_match('/\$onKartIds\s*=.*?stok_tx\(\$pdo,\s*function.*?\{\s*stok_kart_kilitle\(\$pdo,\s*\$onKartIds\);/s', $stokSrc) === 1);

echo "\n=== 3. [B-08] PDKS Hakedis Atomik Transaction ve FOR UPDATE ===\n";
$hakSrc = (string)file_get_contents($ROOT . '/config/pdks_hakedis.php');
ok('pdks_hakedis_hesapla nested-safe tx + FOR UPDATE + status=draft + rowCount kontrolu icerir',
    preg_match('/function pdks_hakedis_hesapla.*?inTransaction\(\).*?FOR UPDATE.*?WHERE id=\? AND status=\'draft\'.*?rowCount\(\)/s', $hakSrc) === 1);
ok('pdks_hakedis_finalize nested-safe tx + FOR UPDATE + status=draft + rowCount kontrolu icerir',
    preg_match('/function pdks_hakedis_finalize.*?inTransaction\(\).*?FOR UPDATE.*?WHERE id=\? AND status=\'draft\'.*?rowCount\(\)/s', $hakSrc) === 1);

echo "\n=== 4. [B-19] depo_ad_aktar Atomik Defter Gruplari ve Kismi Aktarim Reddi ===\n";
$pdo->exec('CREATE TABLE IF NOT EXISTS material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)');
$pdo->exec("INSERT INTO material_definitions (type, name) VALUES ('depo', 'ESKI DEPO')");
$pdo->exec("INSERT INTO stok_fisler (tur, fis_no, tarih, depo, toplam_miktar, durum, created_at) VALUES ('giris', 'SG-1', '2026-01-10', 'ESKI DEPO', '10.000', 'gecerli', '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO stok_hareketler (fis_id, kart_id, tarih, depo, miktar, deger, q_sonra, v_sonra, ort_sonra, created_at) VALUES (1, 1, '2026-01-10', 'ESKI DEPO', '10.000', '100.00', '10.000', '100.00', '10.000000', '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO fatura_belgeler (tur, belge_no, tarih, depo, cari_id, cari_kod, cari_unvan, para_birimi, brut_toplam, iskonto_toplam, net_toplam, kdv_toplam, genel_toplam, cari_tutar, stok_etkisi, durum, created_at) VALUES ('satis', 'SF-1', '2026-01-10', 'ESKI DEPO', 1, 'C1', 'Cari 1', 'TRY', '100.00', '0.00', '100.00', '0.00', '100.00', '100.00', 1, 'gecerli', '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO ceksenet_kayitlar (sinif, yon, portfoy_no, seri_no, vade, tutar, para_birimi, cari_id, durum, depo, bordro_no, created_at) VALUES ('cek', 'alinan', 'CA-1', 'S001', '2026-05-01', '500.00', 'TRY', 1, 'portfoyde', 'ESKI DEPO', 'CB-1', '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO ceksenet_hareketler (kayit_id, islem, tarih, yeni_durum, belge_no, bordro_no, aciklama, depo, durum, created_at) VALUES (1, 'giris', '2026-01-10', 'portfoyde', 'CH-1', 'CB-1', '', 'ESKI DEPO', 'gecerli', '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO kasa_kartlar (kod, ad, tip, para_birimi, depo, aktif, created_at) VALUES ('KS01', 'Banka 1', 'banka', 'TRY', 'ESKI DEPO', 1, '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO kasa_hareketler (kasa_id, tur, yon, tarih, tutar, belge_no, aciklama, depo, durum, created_at) VALUES (1, 'tahsilat', 'G', '2026-01-10', '100.00', 'KH-1', '', 'ESKI DEPO', 'gecerli', '2026-01-10 10:00:00')");
depo_onbellek_sifirla();

$yeniDepo = depo_ekle($pdo, ['ad' => 'Yeni Merkez']);
ok('yeni depo acildi', $yeniDepo['ok'] && $yeniDepo['id'] > 0, $yeniDepo['hata']);
$yeniDepoId = (int)$yeniDepo['id'];

// Kismi aktarım redleri
$kismiStok = depo_eski_veriyi_aktar($pdo, 'ESKI DEPO', $yeniDepoId, ['stok_fisler']);
ok('yalniz stok_fisler secimi reddedilir (eksik stok_hareketler/fatura_belgeler)',
    !$kismiStok['ok'] && str_contains($kismiStok['mesaj'], 'Bağlı defter tabloları ayrı aktarılamaz')
    && siparis_test_say($pdo, 'stok_fisler', "depo = 'ESKI DEPO'") === 1, $kismiStok['mesaj']);

$kismiCek = depo_eski_veriyi_aktar($pdo, 'ESKI DEPO', $yeniDepoId, ['ceksenet_kayitlar']);
ok('yalniz ceksenet_kayitlar secimi reddedilir',
    !$kismiCek['ok'] && str_contains($kismiCek['mesaj'], 'ceksenet_hareketler')
    && siparis_test_say($pdo, 'ceksenet_kayitlar', "depo = 'ESKI DEPO'") === 1, $kismiCek['mesaj']);

$kismiKasa = depo_eski_veriyi_aktar($pdo, 'ESKI DEPO', $yeniDepoId, ['kasa_kartlar']);
ok('yalniz kasa_kartlar secimi reddedilir',
    !$kismiKasa['ok'] && str_contains($kismiKasa['mesaj'], 'kasa_hareketler')
    && siparis_test_say($pdo, 'kasa_kartlar', "depo = 'ESKI DEPO'") === 1, $kismiKasa['mesaj']);

$direktHata = false;
try { depo_ad_aktar($pdo, 'ESKI DEPO', 'YENİ MERKEZ', ['stok_fisler']); }
catch (InvalidArgumentException $e) { $direktHata = str_contains($e->getMessage(), 'Bağlı defter'); }
ok('dogrudan depo_ad_aktar kismi secimde InvalidArgumentException firlatir', $direktHata);

// Tam atomik grup aktarimi basarili olur ve audit detayini yazar
$tamCek = depo_eski_veriyi_aktar($pdo, 'ESKI DEPO', $yeniDepoId, ['ceksenet_kayitlar', 'ceksenet_hareketler']);
ok('tam ceksenet cifti atomik olarak aktarilir',
    $tamCek['ok'] && $tamCek['satir'] === 2
    && siparis_test_say($pdo, 'ceksenet_kayitlar', "depo = 'YENİ MERKEZ'") === 1
    && siparis_test_say($pdo, 'ceksenet_hareketler', "depo = 'YENİ MERKEZ'") === 1, json_encode($tamCek, JSON_UNESCAPED_UNICODE));
$sonAudit = (string)$pdo->query("SELECT new_values FROM audit_log WHERE module = 'depolar' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('audit_log etkilenen_tablolar detayini icerir', str_contains($sonAudit, 'etkilenen_tablolar') && str_contains($sonAudit, 'ceksenet_kayitlar.depo'), $sonAudit);

echo "\n=== 5. [B-20] Muhasebe Kart Silmede Moduller Arasi Referans Kontrolu ===\n";
// Banka kasasi (0 kasa hareketi) ama ceksenet_kayitlar.yer_kasa_id veya ceksenet_tanimlar.kasa_id tarafindan referansli
$pdo->exec("INSERT INTO kasa_kartlar (id, kod, ad, tip, para_birimi, depo, aktif, created_at) VALUES (10, 'BNK10', 'Tahsildeki Banka', 'banka', 'TRY', '', 1, '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO kasa_kartlar (id, kod, ad, tip, para_birimi, depo, aktif, created_at) VALUES (11, 'BNK11', 'Karneli Banka', 'banka', 'TRY', '', 1, '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO kasa_kartlar (id, kod, ad, tip, para_birimi, depo, aktif, created_at) VALUES (12, 'BNK12', 'Bos Kasa', 'nakit', 'TRY', '', 1, '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO ceksenet_kayitlar (sinif, yon, portfoy_no, seri_no, vade, tutar, para_birimi, cari_id, durum, yer_kasa_id, depo, bordro_no, created_at) VALUES ('cek', 'alinan', 'CA-2', 'S002', '2026-06-01', '750.00', 'TRY', 20, 'tahsilde', 10, '', 'CB-2', '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO ceksenet_tanimlar (tur, ad, kasa_id, aktif, created_at) VALUES ('karne', 'Karne 1', 11, 1, '2026-01-10 10:00:00')");

$DEPOLAR = null; // kisitsiz depo
$ks1 = kasa_kart_sil($pdo, 10, 1);
ok('tahsile verilmis cekin banka kasasi silinemez (kasa_hareketler=0 olsa da)',
    !$ks1['ok'] && ($ks1['kod'] ?? '') === 'hareketli' && siparis_test_say($pdo, 'kasa_kartlar', 'id = 10') === 1, json_encode($ks1, JSON_UNESCAPED_UNICODE));
$ks2 = kasa_kart_sil($pdo, 11, 1);
ok('cek karnesi bagli banka kasasi silinemez',
    !$ks2['ok'] && ($ks2['kod'] ?? '') === 'hareketli' && siparis_test_say($pdo, 'kasa_kartlar', 'id = 11') === 1, json_encode($ks2, JSON_UNESCAPED_UNICODE));
$ks3 = kasa_kart_sil($pdo, 12, 1);
ok('referanssiz bos kasa silinebilir', $ks3['ok'] && siparis_test_say($pdo, 'kasa_kartlar', 'id = 12') === 0);

// Cari karti (0 cari_hareketler) ama ceksenet_kayitlar / fatura_belgeler tarafindan referansli
$pdo->exec("INSERT INTO cari_kartlar (id, kod, unvan, tip, para_birimi, aktif, created_at) VALUES (20, 'CR20', 'Cek Carisi', 'musteri', 'TRY', 1, '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO cari_kartlar (id, kod, unvan, tip, para_birimi, aktif, created_at) VALUES (21, 'CR21', 'Bos Cari', 'musteri', 'TRY', 1, '2026-01-10 10:00:00')");
$cs1 = cari_kart_sil($pdo, 20, 1);
ok('ceksenet kaydi olan cari silinemez (cari_hareketler=0 olsa da)',
    !$cs1['ok'] && ($cs1['kod'] ?? '') === 'hareketli' && siparis_test_say($pdo, 'cari_kartlar', 'id = 20') === 1, json_encode($cs1, JSON_UNESCAPED_UNICODE));
$cs2 = cari_kart_sil($pdo, 21, 1);
ok('referanssiz bos cari silinebilir', $cs2['ok'] && siparis_test_say($pdo, 'cari_kartlar', 'id = 21') === 0);

// Stok karti (0 stok_hareketler/stok_fis_satirlar) ama fatura_satirlar tarafindan referansli
$pdo->exec("INSERT INTO stok_kartlar (id, kod, ad, tip, ana_birim, aktif, created_at) VALUES (30, 'ST30', 'Faturali Urun', 'urun', 'kg', 1, '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO stok_kartlar (id, kod, ad, tip, ana_birim, aktif, created_at) VALUES (31, 'ST31', 'Bos Urun', 'urun', 'kg', 1, '2026-01-10 10:00:00')");
$pdo->exec("INSERT INTO fatura_satirlar (belge_id, sira, kart_id, kart_kod, urun_adi, birim, miktar, brut_tutar, iskonto_tutar, tutar, kdv_orani, kdv_tutar, stoklu) VALUES (1, 1, 30, 'ST30', 'Faturali Urun', 'kg', '5.000', '50.00', '0.00', '50.00', 0, '0.00', 0)");
$ss1 = stok_kart_sil($pdo, 30, 1);
ok('fatura satirinda gecen stok karti silinemez (stok_hareketler=0 olsa da)',
    !$ss1['ok'] && ($ss1['kod'] ?? '') === 'hareketli' && siparis_test_say($pdo, 'stok_kartlar', 'id = 30') === 1, json_encode($ss1, JSON_UNESCAPED_UNICODE));
$ss2 = stok_kart_sil($pdo, 31, 1);
ok('referanssiz bos stok karti silinebilir', $ss2['ok'] && siparis_test_say($pdo, 'stok_kartlar', 'id = 31') === 0);

echo "\n=== 6. [B-23] Mukerrer Belge / Aktarim / Fis No Yaris Korumalari ===\n";
$fatSrc = (string)file_get_contents($ROOT . '/config/fatura_core.php');
$cekSrc = (string)file_get_contents($ROOT . '/config/ceksenet_core.php');
$kcSrc  = (string)file_get_contents($ROOT . '/kantar_create.php');
$cariSrc = (string)file_get_contents($ROOT . '/config/cari_core.php');
ok('fatura_kaydet cari_kart_kilitle() (FOR UPDATE) + kilitli fatura_mukerrer_bul icerir',
    str_contains($fatSrc, "cari_kart_kilitle(\$pdo, [(int)\$v['cari_id']]);")
    && str_contains($cariSrc, 'function cari_kart_kilitle(')
    && str_contains($cariSrc, 'ORDER BY id FOR UPDATE')
    && str_contains($fatSrc, "fatura_mukerrer_bul(\$pdo, (string)\$bilgi['sinif'], \$v['cari_id'], \$faturaNo, true)"));
ok('stok_fis_kaydet kantar_fisleri FOR UPDATE kilidi + kilitli stok_kantar_bagli_fis icerir',
    str_contains($stokSrc, 'SELECT id FROM kantar_fisleri WHERE id = ? FOR UPDATE')
    && str_contains($stokSrc, "stok_kantar_bagli_fis(\$pdo, (int)\$v['kaynak_id'], true)"));
ok('ceksenet_bordro_kaydet kilitli ceksenet_mukerrer_bul icerir',
    str_contains($cekSrc, "ceksenet_mukerrer_bul(\$pdo, \$v['sinif'], \$v['yon'], (string)\$r['banka_ad'], (string)\$r['hesap_no'], \$r['seri_no'], \$r['karne_id'], true)"));
ok('kantar_create.php transaction + gecici benzersiz yer tutucu + 23000 retry icerir',
    str_contains($kcSrc, 'beginTransaction()') && str_contains($kcSrc, '__TMP_') && str_contains($kcSrc, "'23000'"));

// kantar_fisno_uniq_migrate SQLite uzerinde uctan uca test
$pdo->exec("CREATE TABLE kantar_fisleri (id INTEGER PRIMARY KEY AUTOINCREMENT, fis_no TEXT NOT NULL DEFAULT '')");
$pdo->exec("INSERT INTO kantar_fisleri (fis_no) VALUES ('100'),('100')");
$kfEngel = kantar_fisno_uniq_migrate($pdo);
ok('mukerrer fis_no varken kantar_fisno_uniq_migrate veriyi bozmaz ve engel doner',
    !$kfEngel['ok'] && $kfEngel['durum'] === 'engel' && str_contains($kfEngel['mesaj'], '100'), json_encode($kfEngel, JSON_UNESCAPED_UNICODE));
$pdo->exec("UPDATE kantar_fisleri SET fis_no = '100-2' WHERE id = 2");
$kfOk = kantar_fisno_uniq_migrate($pdo);
ok('mukerrer giderilince uq_kf_fis_no UNIQUE indeksi eklenir',
    $kfOk['ok'] && $kfOk['durum'] === 'olusturuldu' && kantar_fisno_uniq_durum($pdo)['indeks_var'] === true, json_encode($kfOk, JSON_UNESCAPED_UNICODE));
$dupThrown = false;
try { $pdo->exec("INSERT INTO kantar_fisleri (fis_no) VALUES ('100')"); } catch (PDOException $e) { $dupThrown = true; }
ok('UNIQUE indeks mukerrer fis_no eklenmesini veritabani duzeyinde engeller', $dupThrown);

echo "\n=== 7. [B-25] 302 Yonlendirme Yetki Kapisi Senkronu ===\n";
ok('muhasebe_yonlendirme_hedefleri 3 aktif 302 sayfasini tanimlar',
    muhasebe_yonlendirme_hedefleri() === [
        'arac_yukleme_fisleri' => 'records.php',
        'mal_kabul_fisleri'    => 'kantar.php',
        'raporlar_listeler'    => 'reports.php',
    ]);
ok('records.php / record_view.php / kantar.php / kantar_view.php / reports.php hedef kapilari 302 slug ile senkron',
    str_contains((string)file_get_contents($ROOT . '/records.php'), "muhasebe_sayfa_can('arac_yukleme_fisleri')")
    && str_contains((string)file_get_contents($ROOT . '/record_view.php'), "muhasebe_sayfa_can('arac_yukleme_fisleri')")
    && str_contains((string)file_get_contents($ROOT . '/kantar.php'), "muhasebe_sayfa_can('mal_kabul_fisleri')")
    && str_contains((string)file_get_contents($ROOT . '/kantar_view.php'), "muhasebe_sayfa_can('mal_kabul_fisleri')")
    && str_contains((string)file_get_contents($ROOT . '/reports.php'), "muhasebe_sayfa_can('raporlar_listeler')"));
ok('config/helpers.php icindeki celiskili "onlara YONLENDIRMEZ" yorumu duzeltildi',
    !str_contains($helpersSrc, 'onlara YÖNLENDİRMEZ'));

// role_require_record_access: yalniz muhasebe.arac_yukleme_fisleri.read ile yukleme okuma acik, duzenleme/cikma kapali
$pdo->exec("CREATE TABLE loading_records (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT)");
$pdo->exec("CREATE TABLE loading_pallets (id INTEGER PRIMARY KEY AUTOINCREMENT, loading_record_id INT, depo TEXT)");
$pdo->exec("INSERT INTO loading_records (id, type) VALUES (1, 'yukleme'), (2, 'cikma')");
$pdo->exec("INSERT INTO loading_pallets (loading_record_id, depo) VALUES (1, ''), (2, '')");
$IS_ADMIN = false;
$PERMS = ['muhasebe.arac_yukleme_fisleri.read'];
$readOk = true;
try { role_require_record_access(1, 'read'); } catch (Throwable $e) { $readOk = false; }
$editBlocked = false;
try { role_require_record_access(1, 'edit'); } catch (Throwable $e) { $editBlocked = true; }
$cikmaBlocked = false;
try { role_require_record_access(2, 'read'); } catch (Throwable $e) { $cikmaBlocked = true; }
ok('yalniz muhasebe.arac_yukleme_fisleri.read → yukleme read ACIK, edit KAPALI, cikma KAPALI',
    $readOk && $editBlocked && $cikmaBlocked);

echo "\n=== 8. [B-26] api_beyan_bildirim.php Tanimsiz \$ulkeMetni Duzeltmesi ===\n";
$abbSrc = (string)file_get_contents($ROOT . '/api_beyan_bildirim.php');
ok('api_beyan_bildirim.php gecici BILINEN HATA yorumu kaldirildi', !str_contains($abbSrc, 'BİLİNEN HATA'));
ok('api_beyan_bildirim.php $ulkeMetni degiskenini plan/beyan uzerinden guvenle tanimliyor',
    str_contains($abbSrc, "\$ulkeMetni = trim((string)(\$plan['gidecek_ulke'] ?? ''));")
    && str_contains($abbSrc, "'ulke' => \$ulkeMetni"));

siparis_test_bitir();
