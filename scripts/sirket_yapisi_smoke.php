<?php
// =========================================================
// scripts/sirket_yapisi_smoke.php — Şirket · Şube · Depo ek bilgisi (Faz 0.1, v505)
// Veri katmanı (config/sirket_yapisi.php) + sayfa/migrate STATİK değişmezleri.
//   php scripts/sirket_yapisi_smoke.php   → çıkış kodu 0 = tüm testler geçti
// Bellek içi SQLite (siparis harness'i); canlı DB'ye dokunmaz. Test verisi SENTETİKTİR.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/sirket_yapisi.php';

$db = db();
siparis_test_diger_tablolar($db);   // users + audit_log
$db->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)");
$db->exec("INSERT INTO material_definitions (type, name) VALUES ('depo','Depo A'),('depo','Depo B'),('urun','Ürün X')");

echo "=== 1. Şema ===\n";
$ddl = implode("\n", sirket_tablolar());
ok('4 tablo tanımlı', count(sirket_tablolar()) === 4);
ok('ALTER / FOREIGN KEY / ENUM / ON UPDATE yok', !preg_match('/\bALTER\b|FOREIGN KEY|\bENUM\(|ON UPDATE/i', $ddl));
ok('her tablo ROW_FORMAT=DYNAMIC + utf8mb4_unicode_ci', substr_count($ddl, 'ROW_FORMAT=DYNAMIC') === 4 && substr_count($ddl, 'utf8mb4_unicode_ci') === 4);
ok('şirket kodu + (şirket, şube kodu) + depo UNIQUE', str_contains($ddl, 'UNIQUE KEY `uq_sirket_kod` (`kod`)') && str_contains($ddl, 'UNIQUE KEY `uq_sube_kod` (`sirket_id`, `kod`)') && str_contains($ddl, 'UNIQUE KEY `uq_depo_sirket` (`depo_id`, `sirket_id`)'));
ok('migrate öncesi hazir=false', sirket_yapisi_hazir($db) === false);
foreach (sirket_tablolar() as $sql) { [$c, $ix] = siparis_ddl_sqlite($sql); $db->exec($c); foreach ($ix as $x) $db->exec($x); }
ok('kurulumdan sonra hazir=true', sirket_yapisi_hazir($db) === true);
$m = sirket_yapisi_migrate($db);
ok('migrate yeniden çalışınca 4 tablo "var"', count(array_filter($m, fn($x) => $x['durum'] === 'var')) === 4);

echo "\n=== 2. Şirket ekle / düzenle (sayı sınırı yok, kodda sabit şirket yok) ===\n";
$sayac = 0;
foreach (['AA', 'BB', 'CC', 'DD', 'EE', 'FF'] as $k) {
    $r = sirket_kaydet($db, ['kod' => $k, 'unvan' => "Deneme $k A.Ş.", 'vergi_no' => '123456789' . $sayac], null, 1);
    $sayac++;
    if (!$r['ok']) echo "  ret: " . implode(' | ', $r['hatalar']) . "\n";
}
ok('6 şirket eklendi (sınır yok)', count(sirket_listele($db)) === 6);
$r = sirket_kaydet($db, ['kod' => ' xy1 ', 'unvan' => 'Küçük Harf Kod', 'vergi_no' => '123.456.789-0 1', 'eposta' => 'a@b.co', 'renk' => '#AABBCC', 'logo_firma_no' => 'F007'], null, 1);
ok('boşluk/küçük harf kod temizlenir (XY1); vergi no yalnız rakam; renk küçük harf', $r['ok'] && ($s = sirket_getir($db, $r['id'])) && $s['kod'] === 'XY1' && $s['vergi_no'] === '12345678901' && $s['renk'] === '#aabbcc' && $s['logo_firma_no'] === '007', json_encode($r));
$id1 = $r['id'];
$r = sirket_kaydet($db, ['kod' => 'AA', 'unvan' => 'Aynı kod'], null, 1);
ok('aynı kod reddedilir', !$r['ok'] && str_contains(implode(' ', $r['hatalar']), 'kod'));
$r = sirket_kaydet($db, ['kod' => 'ZZ', 'unvan' => 'Aynı VKN', 'vergi_no' => '1234567890'], null, 1);
ok('aynı vergi no reddedilir (ayrı VKN = ayrı şirket)', !$r['ok'] && str_contains(implode(' ', $r['hatalar']), 'vergi no'));
foreach ([['kod' => 'Ş1', 'unvan' => 'x'], ['kod' => 'A', 'unvan' => 'x'], ['kod' => 'AB CD', 'unvan' => 'x'], ['kod' => 'AB-1', 'unvan' => 'x'], ['kod' => 'ABCDEFGHIJKLM', 'unvan' => 'x']] as $kotu) {
    ok('geçersiz kod reddedilir: ' . $kotu['kod'], !sirket_kaydet($db, $kotu, null, 1)['ok']);
}
ok('unvan zorunlu', !sirket_kaydet($db, ['kod' => 'QQ', 'unvan' => ''], null, 1)['ok']);
ok('vergi no 10/11 hane dışı reddedilir', !sirket_kaydet($db, ['kod' => 'QQ', 'unvan' => 'x', 'vergi_no' => '12345'], null, 1)['ok']);
ok('e-posta + renk doğrulanır', !sirket_kaydet($db, ['kod' => 'QQ', 'unvan' => 'x', 'eposta' => 'yanlis'], null, 1)['ok'] && !sirket_kaydet($db, ['kod' => 'QQ', 'unvan' => 'x', 'renk' => 'kirmizi'], null, 1)['ok']);
$r = sirket_kaydet($db, ['kod' => 'XY1', 'unvan' => 'Güncel Unvan', 'vergi_no' => '12345678901'], $id1, 1);
ok('düzenleme: kendi kodu/VKN\'siyle kaydedilebilir, unvan değişir', $r['ok'] && sirket_getir($db, $id1)['unvan'] === 'Güncel Unvan');
ok('düzenlemede boşaltılan alan NULL olur (eski değer kalmaz)', sirket_getir($db, $id1)['eposta'] === null);
ok('olmayan şirket düzenlenemez', !sirket_kaydet($db, ['kod' => 'NO', 'unvan' => 'x'], 9999, 1)['ok']);
ok('pasife al', sirket_aktif_yap($db, $id1, false, 1) && (int)sirket_getir($db, $id1)['aktif'] === 0);
ok('aktif listesi pasifi içermez', count(sirket_listele($db, true)) === 6 && count(sirket_listele($db)) === 7);
ok('yeniden aktif yap', sirket_aktif_yap($db, $id1, true, 1) && (int)sirket_getir($db, $id1)['aktif'] === 1);
ok('olmayan şirket pasife alınamaz', sirket_aktif_yap($db, 9999, false, 1) === false);
$aa = (int)$db->query("SELECT id FROM sirketler WHERE kod='AA'")->fetchColumn();
$bb = (int)$db->query("SELECT id FROM sirketler WHERE kod='BB'")->fetchColumn();

echo "\n=== 3. Şube ekle / düzenle ===\n";
$r = sube_kaydet($db, ['sirket_id' => $aa, 'kod' => 'mrk', 'ad' => 'Merkez'], null, 1);
ok('şube eklenir, kod büyük harf', $r['ok'] && sube_getir($db, $r['id'])['kod'] === 'MRK', json_encode($r));
$sb1 = $r['id'];
for ($i = 1; $i <= 8; $i++) sube_kaydet($db, ['sirket_id' => $aa, 'kod' => "S$i", 'ad' => "Şube $i"], null, 1);
ok('bir şirkete istenen kadar şube (9)', count(sube_listele($db, $aa)) === 9);
ok('aynı şirkette aynı şube kodu reddedilir', !sube_kaydet($db, ['sirket_id' => $aa, 'kod' => 'MRK', 'ad' => 'x'], null, 1)['ok']);
ok('başka şirkette aynı şube kodu serbest', sube_kaydet($db, ['sirket_id' => $bb, 'kod' => 'MRK', 'ad' => 'Merkez'], null, 1)['ok']);
ok('olmayan şirkete şube eklenemez', !sube_kaydet($db, ['sirket_id' => 9999, 'kod' => 'X', 'ad' => 'x'], null, 1)['ok']);
ok('şube adı + kod zorunlu', !sube_kaydet($db, ['sirket_id' => $aa, 'kod' => '', 'ad' => 'x'], null, 1)['ok'] && !sube_kaydet($db, ['sirket_id' => $aa, 'kod' => 'Z9', 'ad' => ''], null, 1)['ok']);
$r = sube_kaydet($db, ['kod' => 'MRK', 'ad' => 'Merkez (yeni ad)', 'sehir' => 'Antalya', 'sirket_id' => $bb], $sb1, 1);
ok('şube düzenlenir; şirketi DEĞİŞMEZ (gövdedeki sirket_id yok sayılır)', $r['ok'] && (int)sube_getir($db, $sb1)['sirket_id'] === $aa && sube_getir($db, $sb1)['ad'] === 'Merkez (yeni ad)');
ok('şube pasife alınır', sube_aktif_yap($db, $sb1, false, 1) && (int)sube_getir($db, $sb1)['aktif'] === 0);
$pas = sirket_kaydet($db, ['kod' => 'PP', 'unvan' => 'Pasif Olacak'], null, 1)['id'];
sirket_aktif_yap($db, $pas, false, 1);
ok('pasif şirkete yeni şube eklenemez', !sube_kaydet($db, ['sirket_id' => $pas, 'kod' => 'A', 'ad' => 'x'], null, 1)['ok']);
ok('şube listesi şirket kodunu taşır', sube_listele($db, $aa)[0]['sirket_kod'] === 'AA');

echo "\n=== 4. Depo ek bilgisi ===\n";
$dep = depo_listele($db);
ok('yalnız depo türündeki tanımlar listelenir (2)', count($dep) === 2);
ok('ek bilgisi girilmemiş depo: tür=kendi, tanımsız, kısıtsız', $dep[0]['tur'] === 'kendi' && $dep[0]['tur_tanimli'] === false && $dep[0]['sirketler'] === []);
$dA = $dep[0]['id']; $dB = $dep[1]['id'];
$r = depo_detay_kaydet($db, $dA, 'fason', $sb1, 'Deneme', [$aa], 1);
ok('depo detayı kaydedilir (tür, şube, şirket izni)', $r['ok'], json_encode($r));
$d = depo_listele($db)[0];
ok('tür=fason, şube bağlı, izin [AA]', $d['tur'] === 'fason' && $d['tur_tanimli'] && $d['sube_id'] === $sb1 && $d['sirketler'] === [$aa] && $d['aciklama'] === 'Deneme');
$r = depo_detay_kaydet($db, $dA, 'anlasmali', null, '', [$aa, $bb, $aa], 1);
ok('yeniden kayıt güncelleme: mükerrer şirket tekilleşir, eski izinler SİLİNİR', $r['ok'] && depo_listele($db)[0]['sirketler'] === [$aa, $bb] && depo_listele($db)[0]['tur'] === 'anlasmali' && depo_listele($db)[0]['sube_id'] === null);
ok('geçersiz tür reddedilir', !depo_detay_kaydet($db, $dA, 'sahte', null, '', [], 1)['ok']);
ok('olmayan depo reddedilir (urun tipi de depo değildir)', !depo_detay_kaydet($db, 9999, 'kendi', null, '', [], 1)['ok'] && !depo_detay_kaydet($db, 3, 'kendi', null, '', [], 1)['ok']);
ok('olmayan şirket reddedilir', !depo_detay_kaydet($db, $dA, 'kendi', null, '', [9999], 1)['ok']);
ok('olmayan şube reddedilir', !depo_detay_kaydet($db, $dA, 'kendi', 9999, '', [], 1)['ok']);
ok('şubenin şirketi izin listesinde değilse reddedilir', !depo_detay_kaydet($db, $dA, 'kendi', $sb1, '', [$bb], 1)['ok']);
ok('izin listesi boşken şube serbest (kısıtsız depo)', depo_detay_kaydet($db, $dA, 'kendi', $sb1, '', [], 1)['ok']);
ok('depo tanımının kendisi (ad) DEĞİŞMEZ', $db->query("SELECT name FROM material_definitions WHERE id = $dA")->fetchColumn() === 'Depo A');
depo_detay_kaydet($db, $dB, 'kendi', null, '', [$aa], 1);
ok('depo_sirket_izinli: atanmamış depo herkese açık', depo_sirket_izinli($db, $dA, $bb) === true);
ok('depo_sirket_izinli: atanmış depo yalnız izinlilere', depo_sirket_izinli($db, $dB, $aa) === true && depo_sirket_izinli($db, $dB, $bb) === false);

echo "\n=== 5. Audit ===\n";
$n = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE module IN ('sirket','sube','depo_detay')")->fetchColumn();
ok('ekleme/güncelleme/pasife alma audit\'e yazıldı', $n >= 20, (string)$n);
$hassas = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE module IN ('sirket','sube') AND (new_values LIKE '%12345678901%' OR new_values LIKE '%a@b.co%' OR old_values LIKE '%12345678901%')")->fetchColumn();
ok('audit\'te vergi no / e-posta DEĞERİ yok (yalnız kod, unvan, değişen alan ADLARI)', $hassas === 0);

echo "\n=== 6. Statik: sayfalar + migrate + güvenlik ===\n";
$lib = (string)file_get_contents($ROOT . '/config/sirket_yapisi.php');
ok('lib: doğrudan istek 404', str_contains($lib, 'http_response_code(404)'));
ok('lib: şirket/şube için DELETE FROM yok (yalnız pasife alma); tek DELETE depo_sirket', substr_count($lib, 'DELETE FROM') === 1 && str_contains($lib, 'DELETE FROM depo_sirket'));
ok('lib: kodda sabit şirket adı/kodu yok', !preg_match('/Asya|Agro|Finike|\'AF\'/u', $lib));
$ui = (string)file_get_contents($ROOT . '/config/sirket_yapisi_ui.php');
ok('ui: tablo yoksa migrate kartına bağlantı + doğrudan istek 404', str_contains($ui, 'migrate.php#sirket-yapisi') && str_contains($ui, 'http_response_code(404)'));
foreach (['program_tanimlari.php', 'sube_tanimlari.php', 'depo_tanimlari.php'] as $p) {
    $s = (string)file_get_contents($ROOT . '/' . $p);
    ok("$p: require_login + is_admin kapısı", str_contains($s, 'require_login()') && (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s));
    ok("$p: her POST csrf_check ile", str_contains($s, "csrf_check(\$_POST['csrf'] ?? null)"));
    ok("$p: render_header/render_footer + tablo yoksa migrate bağlantısı", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()') && str_contains($s, 'sy_tablo_yok()'));
    ok("$p: şema kurmaz (CREATE/ALTER yok)", !preg_match('/CREATE TABLE|ALTER TABLE/i', $s));
}
$mg = (string)file_get_contents($ROOT . '/migrate.php');
ok('migrate.php: kart + POST dalı (csrf_check ile) + audit', str_contains($mg, "mg_kart_ac('sirket-yapisi')") && (bool)preg_match("/'sirket_yapisi'\)\s*\{\s*\n\s*csrf_check/", $mg) && str_contains($mg, "audit_log_event('migrate', 'sirket_yapisi'"));

siparis_test_bitir();
