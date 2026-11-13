<?php
// =========================================================
// scripts/frugro_beyan_smoke.php — Frugro ↔ gümrük beyanı bağı (config/frugro_beyan.php + frugro_beyan.php)
//   php scripts/frugro_beyan_smoke.php
// Bellek içi SQLite + stub auth + SENTETİK veri. Canlı DB'ye dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_core.php';
require_once $ROOT . '/config/frugro_beyan.php';
require_once $ROOT . '/config/frugro_detay.php';

$db = db();
siparis_test_diger_tablolar($db);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
foreach (siparis_tablolar() as $sql) { [$c, $ix] = siparis_ddl_sqlite($sql); $db->exec($c); foreach ($ix as $x) $db->exec($x); }
$db->exec('CREATE TABLE IF NOT EXISTS hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
$IS_ADMIN = true;

$yeniSip = function (string $po, ?string $parti, ?string $plaka, string $durum = 'yeni', ?string $cikis = null) use ($db): int {
    $t = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO frugro_siparisler (po_no, cikis_tarihi, parti_no, plaka, durum, kaynak, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$po, $cikis ?? date('Y-m-d'), $parti, $plaka, $durum, 'elle', $t, $t]);
    return (int)$db->lastInsertId();
};
$yeniBeyan = function (string $parti, string $cekici, string $dorse, string $durum = 'taslak', ?string $olusma = null) use ($db): int {
    $db->prepare("INSERT INTO customs_declarations (status, party_no, tractor_plate, vehicle_plate, net_kg, pallet_count, product_name, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$durum, $parti, $cekici, $dorse, 14000, 26, 'NAR', $olusma ?? date('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
};
$hash = fn() => md5(json_encode($db->query('SELECT * FROM customs_declarations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));

$s1 = $yeniSip('26-7001-70001', null, '34 ABC 123 / 34-XY-45');
$s2 = $yeniSip('26-7002-70002', 'PX-9', null);
$bEs  = $yeniBeyan('P-777', '34ABC123', '34XY45');            // iki plaka eşleşir
$bTek = $yeniBeyan('P-778', '34 ABC 123', '06 ZZZ 999');       // bir plaka
$bPar = $yeniBeyan('px-9', '35 QQQ 111', '35 QQW 222');        // yalnız parti (s2)
$bIlg = $yeniBeyan('P-000', '07 AAA 000', '07 AAB 000');       // ilgisiz
$bEski = $yeniBeyan('P-777', '34ABC123', '34XY45', 'taslak', date('Y-m-d H:i:s', strtotime('-40 day')));
$bYuk = $yeniBeyan('P-777', '34ABC123', '34XY45', 'yuklendi');
$bOgl = $yeniBeyan('P-ogl', '34ABC123', '34XY45');
$db->exec("INSERT INTO siparis_sevkiyatlar (order_ref, created_at) VALUES ('OGL-1', datetime('now'))");
$oglId = (int)$db->lastInsertId();
$db->exec("INSERT INTO siparis_beyan (sevkiyat_id, beyan_id, durum, created_at) VALUES ($oglId, $bOgl, 'aktif', datetime('now'))");
$H0 = $hash();
$S1 = frg_getir($db, $s1); $S2 = frg_getir($db, $s2);

echo "=== 1. Arama + plaka anahtarı ===\n";
$pa = frgb_plaka_anahtar('34 ABC 123 / 34-XY-45');
ok('plaka anahtarı: parçalar boşluksuz büyük, birleşik', $pa['parca'] === ['34ABC123', '34XY45'] && $pa['birlesik'] === '34ABC12334XY45', json_encode($pa));
ok('ara: id ile', in_array($bEs, array_column(frgb_ara($db, (string)$bEs, $s1), 'id'), true));
ok('ara: parti no ile (büyük/küçük harf duyarsız)', in_array($bPar, array_column(frgb_ara($db, 'PX-9', $s1), 'id'), true));
ok('ara: plaka ile (boşluksuz)', in_array($bEs, array_column(frgb_ara($db, '34ABC123', $s1), 'id'), true));
ok('ara: OGL bağlı beyana "OGL OGL-1" etiketi', (function () use ($db, $s1, $bOgl) { foreach (frgb_ara($db, 'P-ogl', $s1) as $b) if ($b['id'] === $bOgl) return $b['bagli'] === 'OGL OGL-1'; return false; })());

echo "\n=== 2. Öneriler ===\n";
$o1 = frgb_oneriler($db, $S1);
$ids1 = array_column($o1, 'beyan_id');
ok('öneri: iki plaka eşleşen en üstte (puan 50), tek plaka 30', ($ids1[0] ?? 0) === $bEs && $o1[0]['puan'] === 50 && in_array($bTek, $ids1, true) && $o1[1]['puan'] === 30, json_encode($o1));
ok('öneri: ilgisiz, pencere dışı (40 gün), yuklendi, OGL-aktif beyan GELMEZ', !array_intersect([$bIlg, $bEski, $bYuk, $bOgl], $ids1), json_encode($ids1));
$o2 = frgb_oneriler($db, $S2);
ok('öneri: parti eşitliği +60 (boşluk/harf duyarsız), gerekçe "parti no aynı"', ($o2[0]['beyan_id'] ?? 0) === $bPar && $o2[0]['puan'] === 60 && $o2[0]['gerekce'] === ['parti no aynı'], json_encode($o2));
ok('öneri: parti boşsa parti puanı verilmez', !in_array('parti no aynı', array_merge(...array_column($o1, 'gerekce')), true));

echo "\n=== 3. Bağla ===\n";
$r = frgb_bagla($db, $S1, $bEs, 1, 'oneri');
$S1 = frg_getir($db, $s1);
ok('bağla: ok + parti no beyandan DOLDU (P-777) + KV', $r['ok'] && $r['parti_dolduruldu'] && $S1['parti_no'] === 'P-777' && array_column(frgb_oku($db, $s1)['beyanlar'], 'id') === [$bEs]);
ok('bağla: olay beyan_baglandi (öneri; parti dolduruldu)', (bool)array_filter(frg_olaylar($db, $s1), fn($o) => $o['tur'] === 'beyan_baglandi' && str_contains($o['metin'], '#' . $bEs) && str_contains($o['metin'], 'öneri') && str_contains($o['metin'], 'parti no beyandan')));
ok('bağlı beyan öneriden ve diğer siparişin önerisinden düşer', !in_array($bEs, array_column(frgb_oneriler($db, $S1), 'beyan_id'), true) && !in_array($bEs, array_column(frgb_oneriler($db, frg_getir($db, $s2)), 'beyan_id'), true));
$r2 = frgb_bagla($db, $S1, $bEs, 1, 'elle');
ok('aynı çift: reddedilir ("zaten bu siparişe bağlı")', !$r2['ok'] && str_contains($r2['hata'], 'zaten'));
$r3 = frgb_bagla($db, frg_getir($db, $s2), $bEs, 1, 'elle');
ok('ikinci siparişe aynı beyan: reddedilir (başka Frugro PO adıyla)', !$r3['ok'] && str_contains($r3['hata'], '26-7001-70001'), json_encode($r3));
$r4 = frgb_bagla($db, frg_getir($db, $s2), $bOgl, 1, 'elle');
ok('OGL\'de aktif bağlı beyan: reddedilir', !$r4['ok'] && str_contains($r4['hata'], 'OGL-1'));
$r5 = frgb_bagla($db, frg_getir($db, $s2), $bYuk, 1, 'elle');
ok('yuklendi beyan: reddedilir', !$r5['ok']);
ok('ara: bağlı beyan "bu" / "PO …" etiketi', (function () use ($db, $s1, $s2, $bEs) { $e = function (int $sid) use ($db, $bEs) { foreach (frgb_ara($db, 'P-777', $sid) as $b) if ($b['id'] === $bEs) return $b['bagli']; return '?'; }; return $e($s1) === 'bu' && $e($s2) === 'PO 26-7001-70001'; })());
$r6 = frgb_bagla($db, frg_getir($db, $s2), $bPar, 1, 'elle');
ok('elle girilmiş parti (PX-9) EZİLMEZ; parti_kaynak yazılmaz', $r6['ok'] && !$r6['parti_dolduruldu'] && frg_getir($db, $s2)['parti_no'] === 'PX-9' && frgb_oku($db, $s2)['parti_kaynak'] === null);
$sK = $yeniSip('26-7003-70003', null, null, 'evrak_gonderildi');
$r7 = frgb_bagla($db, frg_getir($db, $sK), $bTek, 1, 'elle');
ok('kapalı (evrak_gonderildi) siparişte bağla OK', $r7['ok'] && $r7['parti_dolduruldu']);

echo "\n=== 4. Kaldır ===\n";
$k = frgb_kaldir($db, frg_getir($db, $s1), $bEs, 1);
ok('kaldır: parti beyandan gelmişti ve değişmedi → NULL; KV boş; olay', $k['ok'] && $k['parti_temizlendi'] && frg_getir($db, $s1)['parti_no'] === null && frgb_oku($db, $s1)['beyanlar'] === [] && (bool)array_filter(frg_olaylar($db, $s1), fn($o) => $o['tur'] === 'beyan_kaldirildi'));
frgb_bagla($db, frg_getir($db, $s1), $bEs, 1, 'elle');
$db->exec("UPDATE frugro_siparisler SET parti_no = 'ELLE-1' WHERE id = $s1");
$k2 = frgb_kaldir($db, frg_getir($db, $s1), $bEs, 1);
ok('kaldır: parti elle değiştirilmişse DOKUNULMAZ', $k2['ok'] && !$k2['parti_temizlendi'] && frg_getir($db, $s1)['parti_no'] === 'ELLE-1');
ok('kaldır: bağlı olmayan beyan reddedilir', !frgb_kaldir($db, frg_getir($db, $s1), $bEs, 1)['ok']);
ok('kaldırınca beyan serbest: başka siparişe bağlanabilir', frgb_bagla($db, frg_getir($db, $s2), $bEs, 1, 'elle')['ok']);

echo "\n=== 5. Başlık, okuma, güvenlik ===\n";
$bg = frgb_baglar($db, $s2);
ok('başlık parti listesi: PX-9 + P-777 (tekil; px-9 tekrarı yok)', frgb_parti_listesi(frg_getir($db, $s2), $bg) === ['PX-9', 'P-777', ] || frgb_parti_listesi(frg_getir($db, $s2), $bg) === ['PX-9', 'P-777'], json_encode(frgb_parti_listesi(frg_getir($db, $s2), $bg)));
$db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['frugro_beyan_' . $sK, json_encode([$bYuk])]);
ok('eski düz [id,…] KV biçimi okunur', array_column(frgb_oku($db, $sK)['beyanlar'], 'id') === [$bYuk]);
ok('customs_declarations satırları İŞLEMLERDEN SONRA AYNI (yalnız SELECT)', $hash() === $H0);
$db->exec("UPDATE customs_declarations SET buyer_name = 'ALICI-X' WHERE id = $bPar");
$gAdm = frgb_baglar($db, $s2);
$IS_ADMIN = false; $PERMS = ['siparis.read'];
$gizli = frgb_baglar($db, $s2);
ok('özet beyaz liste (SIPARIS_BEYAN_KOLONLAR): alıcı/şoför/ham metin DEĞERİ özette yok (beyan.read yokken de redakte)', array_filter(array_merge(array_column(array_column($gAdm, 'beyan'), 'buyer_name'), array_column(array_column($gizli, 'beyan'), 'buyer_name'), array_column(array_column($gizli, 'beyan'), 'raw_text'))) === [] && $gizli !== []);
$IS_ADMIN = true;

echo "\n=== 6. Statik ===\n";
$kod = (string)file_get_contents($ROOT . '/config/frugro_beyan.php');
$uc = (string)file_get_contents($ROOT . '/frugro_beyan.php');
ok('config: customs_declarations\'a UPDATE/INSERT/DELETE YOK; siparis_beyan_arac_aktar/bagla/bag_kaldir YOK', !preg_match('/(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+`?customs_declarations/i', $kod) && !str_contains($kod, 'siparis_beyan_arac_aktar') && !preg_match('/siparis_beyan_(bagla|bag_kaldir)\(/', $kod) && !preg_match('/(INSERT\s+INTO|UPDATE)\s+siparis_beyan/i', $kod));
ok('config: include-only koruma; şema DDL YOK', str_contains($kod, 'SCRIPT_FILENAME') && !preg_match('/CREATE TABLE|ALTER TABLE/i', $kod));
ok('uç: csrf_check → require_siparis(write) → match + beyan.read → frgui_yetki(edit) → depo sırası', (bool)preg_match("/csrf_check\(.*require_siparis\('write', 'frugro'\).*can_siparis\('match'\).*can_beyan\('read'\).*frgui_yetki\('edit'\).*enforce_active_depot/s", $uc));
ok('uç: POST dışı 405 + frg_gorunur + audit_log_event + PRG 303', str_contains($uc, '405') && str_contains($uc, 'frg_gorunur(') && str_contains($uc, 'audit_log_event(') && str_contains($uc, '303') && str_contains($uc, 'siparis_no_store()'));
ok('uç: audit\'e parti/plaka DEĞERİ yazılmaz (yalnız id/bayrak)', !preg_match("/audit_log_event\([^;]*(party_no|plaka|parti_no)/", $uc));
ok('uç: kapalı/iptal siparişte ENGEL YOK (frg_kapali_durumlar kullanılmaz)', !str_contains($uc, 'frg_kapali_durumlar'));
ok('config: olaylar frg_olay_yaz ile', str_contains($kod, "frg_olay_yaz(\$pdo, \$sid, 'beyan_baglandi'") && str_contains($kod, "'beyan_kaldirildi'"));
ok('detay olay etiketleri: beyan_baglandi / beyan_kaldirildi', isset(frgd_olay_etiketleri()['beyan_baglandi'], frgd_olay_etiketleri()['beyan_kaldirildi']));
siparis_test_bitir();
