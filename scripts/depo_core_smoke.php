<?php
// =========================================================
// scripts/depo_core_smoke.php — Depo Tanımları çekirdeği (config/depo_core.php, v574) testi
//   php scripts/depo_core_smoke.php     → çıkış kodu 0 = hepsi geçti
// Bellek içi SQLite (siparis harness'i). Canlı DB'ye HİÇ dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/depo_core.php';

$pdo = db();
siparis_test_diger_tablolar($pdo);   // users + audit_log
$pdo->exec('CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)');
$pdo->exec('CREATE TABLE user_depolar (user_id INT, depo TEXT)');
// Eski (canlı) veri tabloları: depo metin kolonları
$pdo->exec('CREATE TABLE loading_pallets (id INTEGER PRIMARY KEY, depo TEXT)');
$pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY, depo TEXT)');
$pdo->exec('ALTER TABLE customs_declarations ADD COLUMN exit_depot TEXT');
$pdo->exec('CREATE TABLE ilgisiz (id INTEGER PRIMARY KEY, ad TEXT)');
$pdo->exec("INSERT INTO material_definitions (type,name) VALUES ('depo','KARAMAN CİHAT'),('depo','ESKİ DEPO 2'),('firma','X')");
$pdo->exec("INSERT INTO user_depolar VALUES (1,'KARAMAN CİHAT')");
$pdo->exec("INSERT INTO loading_pallets (depo) VALUES ('KARAMAN CİHAT'),('KARAMAN CİHAT'),('Eski Depo 2'),('')");
$pdo->exec("INSERT INTO employees (depo) VALUES ('KARAMAN CİHAT'),('ANTALYA'),(NULL)");
$pdo->exec("INSERT INTO customs_declarations (exit_depot) VALUES ('ANTALYA')");
$pdo->exec("INSERT OR IGNORE INTO users (id, username, display_name) VALUES (1,'u1','U1'),(2,'u2','U2'),(3,'u3','U3')");

function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
$UID = 1;

echo "=== 1. Şema / geçiş öncesi (fail-safe) ===\n";
ok('tablolar yokken depo_hazir false', depo_hazir($pdo) === false);
ok('tablolar yokken depo_gecis_aktif false (eski sistem sürer)', depo_gecis_aktif($pdo) === false);
$GLOBALS['_depo_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void {
    [$c, $ix] = siparis_ddl_sqlite($ddl);
    $p->exec($c);
    foreach ($ix as $x) $p->exec($x);
};
$m = depo_migrate($pdo);
ok('migrate: 4 tablo oluşturuldu', count(array_filter($m, fn($r) => $r['durum'] === 'olusturuldu')) === 4, json_encode($m));
ok('migrate sonrası depo_hazir true', depo_hazir($pdo) === true);
ok('migrate tekrar: hepsi var', count(array_filter(depo_migrate($pdo), fn($r) => $r['durum'] === 'var')) === 4);
ok('tablolar var ama geçiş yok → gecis_aktif hâlâ false', depo_gecis_aktif($pdo) === false);

echo "\n=== 2. Veri kolonu keşfi ===\n";
$kol = array_map(fn($x) => $x[0] . '.' . $x[1], depo_kolonlari($pdo));
sort($kol);
ok('keşif: depo/exit_depot kolonları bulundu, ilgisiz + yönetim tabloları yok', $kol === ['customs_declarations.exit_depot', 'employees.depo', 'loading_pallets.depo'], json_encode($kol));
$top = depo_kullanim_toplam($pdo);
ok('kullanım: TR-duyarsız toplam (KARAMAN CİHAT = 2+1)', ($top[depo_anahtar('KARAMAN CİHAT')] ?? 0) === 3 && ($top[depo_anahtar('Eski Depo 2')] ?? 0) === 1, json_encode($top));

echo "\n=== 3. Ad kuralları ===\n";
ok('boş ad reddedilir', depo_ad_dogrula('   ')[1] !== '');
ok('1 karakter reddedilir', depo_ad_dogrula('A')[1] !== '');
ok('101 karakter reddedilir', depo_ad_dogrula(str_repeat('a', 101))[1] !== '');
ok('ad TR büyük harf + boşluk sadeleşir', depo_ad_dogrula('  yeni   depo ı ')[0] === 'YENİ DEPO I');

echo "\n=== 4. Eski adla ÇAKIŞMA (karışma önlemi) ===\n";
$r = depo_ekle($pdo, ['ad' => 'Karaman Cihat']);
ok('geçiş ÖNCESİ bile eski depo adı (tanım+veri) reddedilir', !$r['ok'] && str_contains($r['hata'], 'ESKİ'), $r['hata']);
$r = depo_ekle($pdo, ['ad' => 'antalya']);
ok('yalnız veri kolonunda geçen ad da reddedilir', !$r['ok'] && str_contains($r['hata'], 'ESKİ'), $r['hata']);
ok('hiçbiri eklenmedi', say('depolar') === 0);

echo "\n=== 5. Ekle / kod / kullanıcı ataması ===\n";
$a0 = say('audit_log', "module='depolar' AND action='create'");
$r1 = depo_ekle($pdo, ['ad' => 'Merkez Depo', 'renk' => '#2563EB', 'kullanicilar' => [2, 3, 99]]);
ok('ekle ok', $r1['ok'] && $r1['id'] > 0, $r1['hata']);
$d1 = depo_getir($pdo, $r1['id']);
ok('kod D001 + ad büyük harf + renk küçük harfe', $d1['kod'] === 'D001' && $d1['ad'] === 'MERKEZ DEPO' && $d1['renk'] === '#2563eb', json_encode($d1));
ok('olmayan kullanıcı (99) atanmadı; 2 ve 3 atandı', say('depo_kullanicilar') === 2);
ok('audit create yazıldı', say('audit_log', "module='depolar' AND action='create'") === $a0 + 1);
$r2 = depo_ekle($pdo, ['ad' => 'merkez  depo']);
ok('aynı ad (harf/boşluk farkı) iki kez açılamaz', !$r2['ok'] && str_contains($r2['hata'], 'zaten'), $r2['hata']);
$r3 = depo_ekle($pdo, ['ad' => 'Soğuk Hava', 'renk' => 'kırmızı']);
ok('ikinci depo kod D002; geçersiz renk null', $r3['ok'] && depo_getir($pdo, $r3['id'])['kod'] === 'D002' && depo_getir($pdo, $r3['id'])['renk'] === null);

echo "\n=== 6. Atama okuma kuralları ===\n";
ok('ataması olmayan kullanıcı → null (kısıtsız)', depo_atanan_adlar($pdo, 1) === null);
ok('atanan kullanıcı yalnız kendi deposu', depo_atanan_adlar($pdo, 2) === ['MERKEZ DEPO']);
ok('aktif adlar', depo_aktif_adlar($pdo) === ['MERKEZ DEPO', 'SOĞUK HAVA']);
depo_guncelle($pdo, $r1['id'], ['aktif' => 0]);
ok('depo pasif → atanan kullanıcı için [] (hiçbir depo), aktif adlardan düşer', depo_atanan_adlar($pdo, 2) === [] && depo_aktif_adlar($pdo) === ['SOĞUK HAVA']);
ok('güncellemede verilmeyen alan korunur (renk)', depo_getir($pdo, $r1['id'])['renk'] === '#2563eb');
depo_guncelle($pdo, $r1['id'], ['aktif' => 1]);
depo_kullanici_depolari_yaz($pdo, 3, ['soğuk hava', 'YOK BÖYLE', 'MERKEZ DEPO']);
ok('kullanıcı ekranından ad listesiyle atama (bilinmeyen atlanır)', depo_atanan_adlar($pdo, 3) === ['MERKEZ DEPO', 'SOĞUK HAVA'] && (depo_kullanici_haritasi($pdo)[3] ?? []) === ['MERKEZ DEPO', 'SOĞUK HAVA']);
depo_kullanicilari_yaz($pdo, $r3['id'], []);
ok('depo kullanıcıları boşaltılır', count(depo_kullanici_listesi($pdo)[$r3['id']] ?? []) === 0);

echo "\n=== 7. Geçiş ÖN KONTROL ve uygulama ===\n";
$e = depo_gecis_engelleri($pdo);
ok('yeni depolar var, eski adla çakışan yok → engel yok', $e === [], json_encode($e, JSON_UNESCAPED_UNICODE));
$eng0 = depo_gecis_uygula($pdo);   // henüz çalıştırmadan önce veri sayıları
ok('geçiş uygulandı', $eng0['ok'] === true && $eng0['eski_ad'] === 3, json_encode($eng0, JSON_UNESCAPED_UNICODE));   // KARAMAN CİHAT, ESKİ DEPO 2, ANTALYA
ok('geçiş sonrası gecis_aktif true', depo_gecis_aktif($pdo) === true);
ok('eski veri SİLİNMEDİ (loading_pallets 4, employees 3, customs 1)', say('loading_pallets') === 4 && say('employees') === 3 && say('customs_declarations') === 1);
ok('defter 3 ad', say('depo_eski_adlar') === 3);
ok('ikinci geçiş reddedilir', depo_gecis_uygula($pdo)['ok'] === false);
$r = depo_ekle($pdo, ['ad' => 'KARAMAN CİHAT']);
ok('geçiş SONRASI eski ad hâlâ yasak (defter)', !$r['ok'] && str_contains($r['hata'], 'ESKİ'));
$r = depo_ekle($pdo, ['ad' => 'Karaman Cihat 2']);
ok('benzer ama farklı ad serbest', $r['ok'] === true, $r['hata']);

echo "\n=== 8. Geçiş engeli: yeni depo adı eski veriyle aynı ===\n";
$pdo2 = $pdo;   // aynı bağlantı; geçişi geri alıp engel senaryosu kur
ok('geri al ok', depo_gecis_geri_al($pdo) === true && depo_gecis_aktif($pdo) === false);
ok('geri alma sonrası defter/veri aynen', say('depo_eski_adlar') === 3 && say('loading_pallets') === 4);
$pdo->exec("INSERT INTO depolar (kod,ad,ad_anahtar,aktif,created_at) VALUES ('D900','ANTALYA','" . depo_anahtar('ANTALYA') . "',1,'2026-01-01 00:00:00')");
depo_onbellek_sifirla();
$e = depo_gecis_engelleri($pdo);
ok('eski veride geçen adlı yeni depo geçişi ENGELLER', count($e) === 1 && str_contains($e[0], 'ANTALYA'), json_encode($e, JSON_UNESCAPED_UNICODE));
ok('engelliyken geçiş uygulanmaz', depo_gecis_uygula($pdo)['ok'] === false && depo_gecis_aktif($pdo) === false);
$pdo->exec("DELETE FROM depolar WHERE kod='D900'");
depo_onbellek_sifirla();
ok('çakışma giderilince geçiş tekrar yapılabilir', depo_gecis_uygula($pdo)['ok'] === true);

echo "\n=== 9. Yeniden adlandırma TÜM veri kolonlarını çeker, eskiye dokunmaz ===\n";
// Yeni depo adı veri satırlarında kullanılır hale gelsin (yeni modüllerin damgası)
$pdo->exec("INSERT INTO employees (depo) VALUES ('MERKEZ DEPO'),('MERKEZ DEPO')");
$pdo->exec("INSERT INTO customs_declarations (exit_depot) VALUES ('MERKEZ DEPO')");
$r = depo_guncelle($pdo, $r1['id'], ['ad' => 'Ana Depo', 'aktif' => 1]);
ok('yeniden adlandırma ok, 3 satır aktarıldı', $r['ok'] && $r['aktarilan'] === 3, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('employees + customs yeni adı taşır', say('employees', "depo = 'ANA DEPO'") === 2 && say('customs_declarations', "exit_depot = 'ANA DEPO'") === 1);
ok('ESKİ satırlar dokunulmadı', say('employees', "depo = 'KARAMAN CİHAT'") === 1 && say('loading_pallets', "depo = 'KARAMAN CİHAT'") === 2);
$r = depo_guncelle($pdo, $r1['id'], ['ad' => 'Karaman Cihat']);
ok('yeniden adlandırarak ESKİ ada geçmek reddedilir', !$r['ok'] && str_contains($r['hata'], 'ESKİ'));
ok('ret sonrası ad/veri değişmedi', depo_getir($pdo, $r1['id'])['ad'] === 'ANA DEPO' && say('employees', "depo = 'ANA DEPO'") === 2);
ok('kod değişmedi (sabit)', depo_getir($pdo, $r1['id'])['kod'] === 'D001');

echo "\n=== 10. Silme ===\n";
$rs = depo_sil($pdo, $r1['id']);
ok('kullanımdaki depo silinemez (pasifleştirin)', !$rs['ok'] && str_contains($rs['hata'], 'Pasif'), $rs['hata']);
$rs = depo_sil($pdo, $r3['id']);
ok('kullanılmayan depo silinir + atamaları gider', $rs['ok'] && depo_getir($pdo, $r3['id']) === null && !isset(depo_kullanici_listesi($pdo)[$r3['id']]));
ok('audit delete yazıldı', say('audit_log', "module='depolar' AND action='delete'") === 1);

echo "\n=== 11. Eski veriyi yeni depoya aktar (canlı modül koruması) ===\n";
$hedef = depo_ekle($pdo, ['ad' => 'Yeni Antalya'])['id'];
$x = depo_eski_veriyi_aktar($pdo, 'antalya', $hedef, ['employees']);
ok('yalnız seçili tabloda aktarım (employees 1 satır)', $x['ok'] && $x['satir'] === 1 && say('employees', "depo = 'YENİ ANTALYA'") === 1 && say('customs_declarations', "exit_depot = 'ANTALYA'") === 1, json_encode($x, JSON_UNESCAPED_UNICODE));
$x = depo_eski_veriyi_aktar($pdo, 'Uydurma Ad', $hedef);
ok('eski listede olmayan kaynak adı reddedilir', !$x['ok']);
depo_guncelle($pdo, $hedef, ['aktif' => 0]);
$x = depo_eski_veriyi_aktar($pdo, 'antalya', $hedef);
ok('pasif hedefe aktarım reddedilir', !$x['ok']);

echo "\n=== 12. Renk / yardımcılar ===\n";
ok('depo_renk geçerli rengi döner', depo_renk($pdo, 'ana depo') === '#2563eb' || depo_renk($pdo, 'Ana Depo') === '#2563eb');
ok('depo_renk tanımsız ad → null', depo_renk($pdo, 'yok') === null);


echo "\n=== 13. Çoklu ad + tablo başına hata yalıtımı + tablo grupları (v575) ===\n";
ok('grup: yedek / serbest / canlı / eski ayrımı',
    depo_tablo_grubu('pdks_yedek_20260924_daily_work_sessions') === 'yedek' && depo_tablo_grubu('customs_declarations') === 'serbest'
    && depo_tablo_grubu('siparis_sevkiyatlar') === 'canli' && depo_tablo_grubu('daily_work_sessions') === 'canli' && depo_tablo_grubu('puantaj_giris_kisiler') === 'canli'
    && depo_tablo_grubu('loading_pallets') === 'eski' && depo_tablo_grubu('material_stock_movements') === 'eski');
$pdo->exec('CREATE TABLE siparis_sevkiyatlar (id INTEGER PRIMARY KEY, depo TEXT)');
$pdo->exec("INSERT INTO siparis_sevkiyatlar (depo) VALUES ('KARAMAN CİHAT'),('ESKİ DEPO 2'),('ESKİ DEPO 2')");
$pdo->exec('CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY, depo TEXT, kod TEXT, UNIQUE (depo, kod))');
$pdo->exec("INSERT INTO daily_work_sessions (depo, kod) VALUES ('ESKİ DEPO 2','a'),('YENİ ÇATIŞAN','a'),('KARAMAN CİHAT','b')");
depo_onbellek_sifirla();
$hed = depo_ekle($pdo, ['ad' => 'Çoklu Hedef'])['id'];
// 'YENİ ÇATIŞAN' eski listede olmadığı için hedef adı değil; çatışmayı hedef adın kendisiyle kuruyoruz:
$pdo->exec("INSERT INTO daily_work_sessions (depo, kod) VALUES ('ÇOKLU HEDEF','a')");
$x = depo_eski_veriyi_aktar($pdo, ['KARAMAN CİHAT', 'Eski Depo 2'], $hed, ['siparis_sevkiyatlar', 'daily_work_sessions']);
ok('çoklu ad: siparis_sevkiyatlar 3 satır aktarıldı', ($x['tablo']['siparis_sevkiyatlar.depo'] ?? 0) === 3 && say('siparis_sevkiyatlar', "depo = 'ÇOKLU HEDEF'") === 3, json_encode($x, JSON_UNESCAPED_UNICODE));
ok('benzersiz çakışması: tablo HATA vermez, çakışan satır ATLANAN olarak sayılır ve mesajda tablo.kolon yazar', $x['hata'] === [] && ($x['atlanan']['daily_work_sessions.depo'] ?? 0) === 1 && str_contains($x['mesaj'], 'ATLANAN') && str_contains($x['mesaj'], 'daily_work_sessions.depo'), json_encode($x, JSON_UNESCAPED_UNICODE));
ok('çakışan satır ESKİ adda kaldı (silinmedi/ezilmedi), çakışmayan satır yeni depoya geçti',
    say('daily_work_sessions', "depo = 'ESKİ DEPO 2'") === 1 && say('daily_work_sessions', "depo = 'ÇOKLU HEDEF' AND kod = 'b'") === 1);
ok('çakışma sonrası toplam: siparis_sevkiyatlar 3 + daily 1 satır taşındı, ok true', $x['ok'] === true && ($x['tablo']['siparis_sevkiyatlar.depo'] ?? 0) === 3 && ($x['tablo']['daily_work_sessions.depo'] ?? 0) === 1 && say('siparis_sevkiyatlar', "depo = 'ÇOKLU HEDEF'") === 3);
$x = depo_eski_veriyi_aktar($pdo, 'KARAMAN CİHAT', $hed, ['customs_declarations']);
ok('serbest metin tablosu yalnız açıkça seçilirse aktarılır; seçilmeyince dokunulmaz', $x['satir'] === 0);
$x = depo_eski_veriyi_aktar($pdo, [], $hed);
ok('ad seçilmeden aktarım reddedilir', !$x['ok'] && str_contains($x['mesaj'], 'seçilmedi'));
$x = depo_eski_veriyi_aktar($pdo, ['KARAMAN CİHAT', 'Uydurma'], $hed);
ok('listede olmayan ad varsa HİÇBİRİ aktarılmaz', !$x['ok'] && say('loading_pallets', "depo = 'ÇOKLU HEDEF'") === 0);

$x = depo_eski_veriyi_aktar($pdo, 'KARAMAN CİHAT', $hed, []);
ok('hiç tablo seçilmeden aktarım reddedilir (null = varsayılan, [] = seçim yok)', !$x['ok'] && str_contains($x['mesaj'], 'tablo seçilmedi'));


echo "\n=== 14. Geri al → yeni depo adıyla damgalı satırlar → yeniden geçiş engellenmez (v575) ===\n";
ok('geçiş açık', depo_gecis_aktif($pdo) === true);
$pdo->exec("INSERT INTO employees (depo) VALUES ('MERKEZ DEPO'),('MERKEZ DEPO')");   // geçişten sonra yeni sistemde damgalanan satırlar
depo_gecis_geri_al($pdo);
ok('geri al: ayar kalktı, eski davranış; veri ve defter yerinde', depo_gecis_aktif($pdo) === false && say('depo_eski_adlar') >= 3 && say('employees', "depo = 'MERKEZ DEPO'") >= 2);
ok('geri alınmış durumda yeni depo adı (MERKEZ DEPO) "eski" SAYILMAZ (defter yetkili)', !isset(depo_eski_adlar($pdo)[depo_anahtar('MERKEZ DEPO')]));
ok('yeniden geçişte yeni depo adıyla damgalı satırlar ENGEL olmaz', depo_gecis_engelleri($pdo) === [], json_encode(depo_gecis_engelleri($pdo), JSON_UNESCAPED_UNICODE));
ok('yeniden geçiş uygulanır; yeni satırlar yerinde, eski adlar defterde', depo_gecis_uygula($pdo)['ok'] === true && depo_gecis_aktif($pdo) === true && say('employees', "depo = 'MERKEZ DEPO'") >= 2);

siparis_test_bitir();
