<?php
// =========================================================
// scripts/siparis_core_smoke.php — Siparişler modülü çekirdeği:
// şema/DDL, can_siparis() matrisi, durum makinesi, dosya deposu, belge bağlama
// senaryoları (a–i), alan otoritesi/çatışma/kilit, sezon sayaçları + PKL üretimi,
// yüklenen PKL, beyan önerisi/bağı, audit kuralları.
//   php scripts/siparis_core_smoke.php   → çıkış kodu 0 = tüm testler geçti
// Bellek içi SQLite (SIPARIS_TEST_MYSQL_DSN ile gerçek MySQL); belgeler SENTETİK
// fixture'lar (scripts/fixtures/siparis/*.json) — gerçek veri YOK.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';

$db = db();
siparis_test_diger_tablolar($db);

echo "=== 1. Şema ===\n";
ok('migrate öncesi sema_hazir=false', siparis_sema_hazir($db) === false);
$ddl = implode("\n", siparis_tablolar());
ok('10 tablo tanımlı', count(siparis_tablolar()) === 10);
ok('ALTER yok', stripos($ddl, 'ALTER') === false);
ok('FOREIGN KEY yok', stripos($ddl, 'FOREIGN KEY') === false);
ok('ENUM / JSON tipi / ON UPDATE yok', !preg_match('/\bENUM\(|\bJSON\b|ON UPDATE/i', $ddl));
ok('her tablo ROW_FORMAT=DYNAMIC + utf8mb4_unicode_ci', substr_count($ddl, 'ROW_FORMAT=DYNAMIC') === 10 && substr_count($ddl, 'utf8mb4_unicode_ci') === 10);
ok('uq_ss_ref (tedarikci_no, order_ref) UNIQUE', str_contains($ddl, 'UNIQUE KEY `uq_ss_ref` (`tedarikci_no`, `order_ref`)'));
ok('uq_sp_no (sezon, belge_no) UNIQUE', str_contains($ddl, 'UNIQUE KEY `uq_sp_no` (`sezon`, `belge_no`)'));
if (siparis_test_mysql()) {
    foreach (array_keys(siparis_tablolar()) as $t) $db->exec("DROP TABLE IF EXISTS `$t`");
    $r = siparis_migrate($db);
    ok('MySQL: migrate 10 tablo "olusturuldu"', count(array_filter($r, fn($x) => $x['durum'] === 'olusturuldu')) === 10);
} else {
    $r = siparis_migrate($db);
    ok('SQLite: MySQL DDL hata raporlanıyor, istisna sızmıyor', count($r) === 10 && $r[0]['durum'] === 'hata');
    ok('hata mesajı teknik ayrıntı sızdırmıyor', !str_contains($r[0]['mesaj'], 'SQLSTATE'));
    foreach (siparis_tablolar() as $sql) { [$c, $ix] = siparis_ddl_sqlite($sql); $db->exec($c); foreach ($ix as $x) $db->exec($x); }
}
ok('kurulumdan sonra sema_hazir=true', siparis_sema_hazir($db) === true);
$r = siparis_migrate($db);
ok('migrate idempotent (hepsi "var")', count(array_filter($r, fn($x) => $x['durum'] === 'var')) === 10);
ok('kurulmadı kutusu: yönetici değilse migrate bağlantısı yok', !str_contains(siparis_kurulmadi_html(), 'migrate.php'));
$IS_ADMIN = true;
ok('kurulmadı kutusu: yönetici migrate.php#siparis görür', str_contains(siparis_kurulmadi_html(), 'migrate.php#siparis'));
$IS_ADMIN = false;

echo "\n=== 2. Yetki kapısı can_siparis() ===\n";
$matris = [
    [[], false, false, false, false],
    [['siparis.read'], true, false, false, false],
    [['siparis.write'], false, false, false, false],
    [['siparis.read', 'siparis.write'], true, true, false, false],
    [['siparis.read', 'siparis.delete'], true, false, true, false],
    [['siparis.read', 'siparis.match'], true, false, false, true],
    [['records.write', 'records.read', 'beyan.read', 'beyan.write', 'dashboard.read', 'mail.admin'], false, false, false, false],
];
foreach ($matris as [$p, $r_, $w, $d, $m]) {
    $PERMS = $p;
    ok('can_siparis [' . implode(',', $p) . ']', can_siparis('read') === $r_ && can_siparis('write') === $w && can_siparis('delete') === $d && can_siparis('match') === $m);
}
$PERMS = []; $IS_ADMIN = true;
ok('is_admin dört yetkiyi açar', can_siparis('read') && can_siparis('write') && can_siparis('delete') && can_siparis('match'));
ok('bilinmeyen yetki admin için de false', can_siparis('admin') === false && can_siparis('') === false);
$IS_ADMIN = false;
$PERMS = ['siparis.read'];
$hata = '';
try { require_siparis('write'); } catch (RuntimeException $e) { $hata = $e->getMessage(); }
ok('require_siparis(write) okuyucuyu reddeder', str_contains($hata, 'forbidden') && str_contains($hata, 'siparis.write'));
$hata = '';
try { require_siparis('read'); } catch (RuntimeException $e) { $hata = $e->getMessage(); }
ok('require_siparis(read) okuyucuyu geçirir', $hata === '');
$PERMS = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'];

echo "\n=== 3. Saf yardımcılar ===\n";
ok('sezon: Ekim 2026 → 2026', siparis_sezon('2026-10-16') === '2026');
ok('sezon: Şubat 2027 → 2026', siparis_sezon('2027-02-01') === '2026');
ok('sezon: Temmuz 2027 → 2027', siparis_sezon('2027-07-01') === '2027');
ok('belge no biçimi', siparis_belge_no_bicim(4) === 'AsyaDoc004' && siparis_belge_no_bicim(1234) === 'AsyaDoc1234');
$p = siparis_plaka_normalize('33 AHU 899 / 33ahu910');
ok('plaka: ayraç + boşluk + küçük harf', $p['deger'] === '33AHU899-33AHU910' && $p['uyari'] === null);
ok('plaka: tek boşlukla iki tam plaka', siparis_plaka_normalize('33AHU899 33AHU910')['deger'] === '33AHU899-33AHU910');
ok('plaka: 2+ boşlukla iki plaka', siparis_plaka_normalize('34 ABC 123   34 DE 4567')['deger'] === '34ABC123-34DE4567');
ok('plaka: tek plaka boşluklu', siparis_plaka_normalize('31 AYR 133')['deger'] === '31AYR133');
$p = siparis_plaka_normalize('B-AB 1234, 33AHU899');
ok('plaka: yabancı plaka yalnız uyarı', $p['deger'] !== null && $p['uyari'] !== null);
ok('plaka: boş → null', siparis_plaka_normalize('  ')['deger'] === null);
ok('deger_normalize: tarih d.m.Y', siparis_deger_normalize('16.10.2026', 'tarih') === '2026-10-16');
ok('deger_normalize: sayı TR binlik', siparis_deger_normalize('1.600', 'sayi') === '1600');
ok('deger_normalize: ondalık DB / TR', siparis_deger_normalize('4.7600', 'ondalik') === '4.76' && siparis_deger_normalize('4,76', 'ondalik') === '4.76');
ok('deger_normalize: metin boşluk teklenir, boş → null', siparis_deger_normalize("  A   B ", 'metin') === 'A B' && siparis_deger_normalize('', 'metin') === null);
ok('deger_esit: büyük/küçük harf duyarsız', siparis_deger_esit('Nakliye', 'NAKLIYE', 'metin') && !siparis_deger_esit('A', null, 'metin'));
ok('durum badge ikon + metin', str_contains(siparis_durum_badge('arac_atandi'), '🚚') && str_contains(siparis_durum_badge('arac_atandi'), 'Araç atandı'));
ok('durum badge bilinmeyen kaçışlı', str_contains(siparis_durum_badge('<x>'), '&lt;x&gt;'));
ok('ilerleme: iptal → 0, pkl_hazir → 5/7, mail → 6/7, evrak → 7/7', siparis_ilerleme(['durum' => 'iptal'])['adim'] === 0 && siparis_ilerleme(['durum' => 'pkl_hazir']) === ['adim' => 5, 'toplam' => 7, 'yuzde' => 71]
    && siparis_ilerleme(['durum' => 'mail_gonderildi']) === ['adim' => 6, 'toplam' => 7, 'yuzde' => 86] && siparis_ilerleme(['durum' => 'evrak_gonderildi']) === ['adim' => 7, 'toplam' => 7, 'yuzde' => 100]);
ok('eksik belgeler', siparis_eksik_belgeler(['var_po' => 1, 'var_ll' => 0, 'var_pc' => 0, 'var_pkl' => 1]) === ['ll', 'pc']);
$_GET = ['q' => 'TC', 'durum' => 'acik', 'zararli' => 'x'];
$u = siparis_url(['acik_page' => 2, 'q' => '']);
ok('siparis_url beyaz liste + override', $u === 'siparisler.php?durum=acik&acik_page=2');
$_GET = [];

echo "\n=== 4. Dosya deposu ===\n";
$k = siparis_dosya_kaydet($db, siparis_test_dosya(siparis_test_pdf('dosya1'), 'Belge 1.pdf'), 1, 'DEPO1', siparis_test_mover());
ok('PDF kaydedildi (yeni)', $k['ok'] && $k['yeni'] === true);
$b1 = $db->query('SELECT * FROM siparis_belgeler WHERE id = ' . (int)$k['belge_id'])->fetch();
ok('disk adı 32 hex + uzantı, tur=bilinmiyor, durum=yuklendi', (bool)preg_match('/^[a-f0-9]{32}\.pdf$/', $b1['disk_adi']) && $b1['tur'] === 'bilinmiyor' && $b1['durum'] === 'yuklendi');
$yol = siparis_dosya_yolu($b1);
clearstatcache();
ok('dosya yolu klasör içinde, izin 0640', $yol !== null && (fileperms($yol) & 0777) === 0640);
ok('.htaccess oluşturuldu (Require all denied)', str_contains((string)@file_get_contents(SIPARIS_DEPO_DIR . '.htaccess'), 'Require all denied'));
ok('klasör izni 0750', (fileperms(SIPARIS_DEPO_DIR) & 0777) === 0750);
@unlink(SIPARIS_DEPO_DIR . '.htaccess');
siparis_depo_hazirla();
ok('.htaccess silinirse yeniden yazılır', is_file(SIPARIS_DEPO_DIR . '.htaccess'));
$k2 = siparis_dosya_kaydet($db, siparis_test_dosya(siparis_test_pdf('dosya1'), 'kopya.pdf'), 1, 'DEPO1', siparis_test_mover());
ok('aynı içerik: yeni=false, aynı belge', $k2['ok'] && $k2['yeni'] === false && $k2['belge_id'] === $k['belge_id']);
ok('dosya yolu: kötü disk adı reddedilir', siparis_dosya_yolu(['disk_adi' => '../config/db.php']) === null && siparis_dosya_yolu(['disk_adi' => str_repeat('a', 32) . '.php']) === null);
$r = siparis_dosya_kaydet($db, siparis_test_dosya('merhaba dünya', 'sahte.pdf'), 1, 'DEPO1', siparis_test_mover());
ok('yanlış içerik (.pdf ama metin) reddedilir', !$r['ok']);
$r = siparis_dosya_kaydet($db, siparis_test_dosya("%PDX-1.4 xx", 'sahte2.pdf'), 1, 'DEPO1', siparis_test_mover());
ok('yanlış sihirli bayt reddedilir', !$r['ok']);
$f = siparis_test_dosya('', 'buyuk.pdf');
$h = fopen($f['tmp_name'], 'r+'); fwrite($h, '%PDF-1.4'); ftruncate($h, SIPARIS_MAX_DOSYA + 1); fclose($h);
$r = siparis_dosya_dogrula($f);
ok('15 MB üstü reddedilir', !$r['ok'] && str_contains((string)$r['hata'], 'büyük'));
ok('izinsiz uzantı reddedilir', !siparis_dosya_dogrula(siparis_test_dosya(siparis_test_pdf('x'), 'a.exe'))['ok']);
ok('yükleme hatası Türkçe', siparis_dosya_dogrula(['error' => UPLOAD_ERR_PARTIAL])['hata'] === 'Dosya yarım yüklendi — yeniden deneyin.');
$x = siparis_dosya_dogrula(siparis_test_dosya_yol(SIPARIS_PKL_SABLON, 'pkl.xlsx'));
ok('xlsx şablonu geçerli (cins xlsx)', $x['ok'] && $x['cins'] === 'xlsx');
ob_start(); $im = imagecreatetruecolor(4, 4); imagepng($im); $png = (string)ob_get_clean();
$x = siparis_dosya_dogrula(siparis_test_dosya($png, 'foto.png'));
ok('png görsel geçerli (cins resim)', $x['ok'] && $x['cins'] === 'resim');
$zipYol = sys_get_temp_dir() . '/siparis_t_' . getmypid() . '.xlsx';
$z = new ZipArchive(); $z->open($zipYol, ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString('[Content_Types].xml', '<x/>'); $z->close();
ok('workbook.xml olmayan zip reddedilir', !siparis_dosya_dogrula(siparis_test_dosya_yol($zipYol, 'bozuk.xlsx'))['ok']);
@unlink($zipYol);

echo "\n=== 5a. PO → 2 sevkiyat ===\n";
$r = siparis_test_isle($db, siparis_test_fixture('po_iki_sevkiyat'), 'po1');
$i = $r['isle'];
ok('PO işlendi: tur po, 2 sevkiyat, ikisi yeni', $i['ok'] && $i['tur'] === 'po' && count($i['sevkiyatlar']) === 2 && $i['sevkiyatlar'][0]['yeni'] && $i['sevkiyatlar'][1]['yeni'], json_encode($i));
ok('özet', $i['ozet'] === 'PO CW50.2026 · LIDL XX · 2 sevkiyat', $i['ozet']);
$poBelge = (int)$r['kayit']['belge_id'];
$s1 = siparis_test_sev($db, 'TC900001.10'); $s2 = siparis_test_sev($db, 'TC900002.10');
ok('TC900001.10 alanları', $s1['tur_no'] === '9000000001' && $s1['alici_kodu'] === '1000001' && $s1['alici_adi'] === 'LIDL XX' && $s1['yukleme_tarihi'] === '2026-12-03'
    && $s1['planlanan_plakalar'] === '34TST900' && $s1['bosaltma_yerleri'] === 'LIDL XX A, LIDL XX B' && (int)$s1['cw_hafta'] === 50 && (int)$s1['po_belge_id'] === $poBelge);
ok('var_po=1, durum siparis_alindi, depo DEPO1', (int)$s1['var_po'] === 1 && $s1['durum'] === 'siparis_alindi' && $s1['depo'] === 'DEPO1');
$k1 = siparis_kalemler($db, (int)$s1['id']);
ok('TC900001.10 tek kalem: 14 PC, PO 20 pal / 3200 koli, etkin po', count($k1) === 1 && $k1[0]['kalibre'] === '14' && (int)$k1[0]['po_palet'] === 20 && (int)$k1[0]['po_koli'] === 3200
    && (int)$k1[0]['etkin_palet'] === 20 && $k1[0]['etkin_kaynak'] === 'po');
ok('kalem: çeşit/artikel/koli içeriği/ambalaj/palet tipi/pf', $k1[0]['cesit'] === 'TESTVAR' && $k1[0]['artikel_no'] === '10000014' && $k1[0]['koli_icerik'] === '3,5 KG'
    && $k1[0]['ambalaj'] === 'Carton, 40 x 30 x 11 CM' && $k1[0]['palet_tipi'] === 'EV' && (int)$k1[0]['pf'] === 160);
ok('yerler_json.po iki boşaltma yeri', count($k1[0]['_yerler']['po'] ?? []) === 2 && $k1[0]['_yerler']['po'][0]['kod'] === '1000101');
ok('TC900002.10: 10 palet, tek kalem', (int)$s2['toplam_palet'] === 10 && count(siparis_kalemler($db, (int)$s2['id'])) === 1);
ok('olusturuldu olayı "PO ile"', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'olusturuldu' AND aciklama = 'PO ile'", [(int)$s1['id']]) === 1);
ok('belge_eklendi olayı', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'belge_eklendi'", [(int)$s1['id']]) === 1);
$bel = $db->query("SELECT * FROM siparis_belgeler WHERE id = $poBelge")->fetch();
ok('belge: tur po, islendi, belge_tarihi = başlık tarih+saat, cw, tedarikçi, parser v3', $bel['tur'] === 'po' && $bel['durum'] === 'islendi' && $bel['belge_tarihi'] === '2026-12-01 10:00:00'
    && (int)$bel['cw_hafta'] === 50 && $bel['tedarikci_no'] === '1102720' && $bel['parser_surum'] === SIPARIS_PDF_SURUM && str_contains((string)$bel['parse_json'], 'TC900001.10'));
ok('siparis_belgeler(): bağlı belge + gecerli', count(siparis_belgeler($db, (int)$s1['id'])) === 1 && (int)siparis_belgeler($db, (int)$s1['id'])[0]['gecerli'] === 1);

echo "\n=== 5c. LL (plakalı) → araç atandı ===\n";
$r = siparis_test_isle($db, siparis_test_fixture('ll_plakali'), 'll1');
$llBelge = (int)$r['kayit']['belge_id'];
$s1 = siparis_test_sev($db, 'TC900001.10');
ok('LL işlendi, mevcut sevkiyata bağlandı (yeni değil)', $r['isle']['ok'] && $r['isle']['tur'] === 'll' && count($r['isle']['sevkiyatlar']) === 1 && !$r['isle']['sevkiyatlar'][0]['yeni']);
ok('plakalar / navlun / nakliyeci / boşaltma', $s1['plakalar'] === '34TST001-34TST002' && $s1['navlun_no'] === '6100000001' && $s1['nakliyeci'] === 'NAKLIYE B' && $s1['bosaltma_tarihi'] === '2026-12-08');
ok('LL yükleme yeri PO\'yu ezdi (ll:2 > po:1) + olay', $s1['yukleme_yeri'] === 'ASYA FRESH - FINIKE'
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'alan_guncellendi'", [(int)$s1['id']]) >= 1);
ok('durum → arac_atandi (plaka geldi), var_ll=1', $s1['durum'] === 'arac_atandi' && (int)$s1['var_ll'] === 1);
$k1 = siparis_kalemler($db, (int)$s1['id']);
ok('kalem ll 20 pal / 3200 koli, etkin = ll', (int)$k1[0]['ll_palet'] === 20 && (int)$k1[0]['ll_koli'] === 3200 && $k1[0]['etkin_kaynak'] === 'll' && count($k1[0]['_yerler']['ll'] ?? []) === 2);
ok('tur no aynı → çatışma yok', siparis_test_say($db, 'siparis_catismalar', 'sevkiyat_id = ?', [(int)$s1['id']]) === 0);

echo "\n=== 5f. PO revizyonu ===\n";
$olay0 = siparis_test_say($db, 'siparis_olaylar');
$r = siparis_test_isle($db, siparis_test_fixture('po_revizyon'), 'po2');
$revBelge = (int)$r['kayit']['belge_id'];
$s1 = siparis_test_sev($db, 'TC900001.10'); $s2 = siparis_test_sev($db, 'TC900002.10');
ok('yeni revizyon: eski PO bağı gecerli=0 (TC900001.10)', (int)$db->query("SELECT gecerli FROM siparis_belge_bag WHERE belge_id = $poBelge AND sevkiyat_id = {$s1['id']}")->fetchColumn() === 0);
ok('yeni revizyon bağı gecerli=1, po_belge_id güncellendi', (int)$db->query("SELECT gecerli FROM siparis_belge_bag WHERE belge_id = $revBelge AND sevkiyat_id = {$s1['id']}")->fetchColumn() === 1 && (int)$s1['po_belge_id'] === $revBelge);
ok('aynı kaynak yeni sürüm → KDV no uygulandı + olay', $s1['kdv_no'] === 'XX000000009'
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'alan_guncellendi' AND yeni = 'XX000000009'", [(int)$s1['id']]) === 1);
ok('yükleme tarihi: LL (yetkili) korunur, PO revizyonu (değişen değer) çatışma açar', $s1['yukleme_tarihi'] === '2026-12-03' && (int)$s1['acik_catisma'] === 2
    && siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan = 'yukleme_tarihi' AND durum = 'acik' AND gelen_deger = '2026-12-04' AND mevcut_kaynak = 'll'", [(int)$s1['id']]) === 1);
ok('PO aynı yükleme yerini tekrarlıyor (LL ezmişti) → çatışma YOK', siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan = 'yukleme_yeri'", [(int)$s1['id']]) === 0
    && siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan = 'bosaltma_yerleri' AND durum = 'acik'", [(int)$s1['id']]) === 1);
ok('miktar çatışma üretmez: po_koli 3520, ll_koli 3200', (int)siparis_kalemler($db, (int)$s1['id'])[0]['po_koli'] === 3520 && (int)siparis_kalemler($db, (int)$s1['id'])[0]['ll_koli'] === 3200);
ok('revizyonda olmayan TC900002.10 değişmedi (eski PO hâlâ geçerli, var_po=1)', (int)$s2['var_po'] === 1 && (int)$s2['toplam_palet'] === 10 && $s2['deleted_at'] === null);
ok('uyarı: tarih ve toplam farkı', in_array('yukleme_tarihi_farkli', array_column($s1['_uyarilar'], 'kod'), true) && in_array('toplam_farkli', array_column($s1['_uyarilar'], 'kod'), true));
$eski = siparis_test_fixture('po_iki_sevkiyat');
$eski['header']['date'] = '2026-11-30'; $eski['meta']['created'] = '2026-11-30 08:00:00';
$eski['loadings'][0]['items'][0]['quantity'] = 1; $eski['loadings'][0]['vat_no'] = 'XX000000777';
$r = siparis_test_isle($db, $eski, 'po0');
$s1 = siparis_test_sev($db, 'TC900001.10');
ok('daha ESKİ PO sonradan gelirse bağlanır ama gecerli=0', (int)$db->query("SELECT gecerli FROM siparis_belge_bag WHERE belge_id = {$r['kayit']['belge_id']} AND sevkiyat_id = {$s1['id']}")->fetchColumn() === 0);
ok('eski PO değer uygulamaz (kdv, miktar, po_belge_id aynı)', $s1['kdv_no'] === 'XX000000009' && (int)siparis_kalemler($db, (int)$s1['id'])[0]['po_koli'] === 3520 && (int)$s1['po_belge_id'] === $revBelge);
ok('eski sürüm olayı + mesaj', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'belge_eski_surum' AND aciklama LIKE 'Eski sürüm%'", [(int)$s1['id']]) === 1
    && str_contains(implode(' ', $r['isle']['mesajlar']), 'eski'));

echo "\n=== 5g. İdempotent yeniden işleme ===\n";
$say = fn() => [siparis_test_say($db, 'siparis_sevkiyatlar'), siparis_test_say($db, 'siparis_kalemler'), siparis_test_say($db, 'siparis_olaylar'),
                siparis_test_say($db, 'siparis_catismalar'), siparis_test_say($db, 'siparis_belge_bag'), siparis_test_say($db, 'siparis_belgeler')];
$once = $say();
$sevOnce = $db->query('SELECT id, kaynaklar, plakalar, kdv_no, yukleme_tarihi, toplam_palet, durum FROM siparis_sevkiyatlar ORDER BY id')->fetchAll();
foreach ([[$revBelge, 'po_revizyon'], [$llBelge, 'll_plakali'], [$poBelge, 'po_iki_sevkiyat']] as [$bid, $fx]) {
    $doc = siparis_test_fixture($fx);
    $GLOBALS['_siparis_parse_override'] = fn(string $y): array => $doc;
    $x = siparis_belge_isle($db, $bid, 1);
    unset($GLOBALS['_siparis_parse_override']);
    ok("yeniden işle ($fx) ok", $x['ok']);
}
ok('satır + olay sayıları değişmedi', $say() === $once, json_encode([$once, $say()]));
ok('sevkiyat değerleri değişmedi', $db->query('SELECT id, kaynaklar, plakalar, kdv_no, yukleme_tarihi, toplam_palet, durum FROM siparis_sevkiyatlar ORDER BY id')->fetchAll() === $sevOnce);

echo "\n=== 5b. LL önce gelir (taslak), PO doldurur ===\n";
$r = siparis_test_isle($db, siparis_test_fixture('ll_plakasiz'), 'll2');
$s11 = siparis_test_sev($db, 'TC900011.10');
ok('LL taslak sevkiyat açtı', $r['isle']['ok'] && $s11 !== null && $r['isle']['sevkiyatlar'][0]['yeni']);
ok('po_yok uyarısı, var_po=0, var_ll=1', in_array('po_yok', array_column($s11['_uyarilar'], 'kod'), true) && (int)$s11['var_po'] === 0 && (int)$s11['var_ll'] === 1);
ok('5c: plakasız LL durumu değiştirmez', $s11['durum'] === 'siparis_alindi' && $s11['plakalar'] === null);
ok('olusturuldu olayı "LL ile — PO bekleniyor"', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'olusturuldu' AND aciklama LIKE 'LL ile%PO bekleniyor%'", [(int)$s11['id']]) === 1);
ok('LL kalemleri sira 900+', array_map(fn($k) => (int)$k['sira'], siparis_kalemler($db, (int)$s11['id'])) === [900, 901, 902]);
$r = siparis_test_isle($db, siparis_test_fixture('po_uc_kalibre'), 'po3');
$s11 = siparis_test_sev($db, 'TC900011.10');
ok('PO taslağı doldurdu, tekrar yok', siparis_test_say($db, 'siparis_sevkiyatlar', "order_ref = 'TC900011.10'") === 1 && (int)$s11['var_po'] === 1 && (int)$s11['po_belge_id'] === (int)$r['kayit']['belge_id']);
ok('po_yok uyarısı kalktı', !in_array('po_yok', array_column($s11['_uyarilar'], 'kod'), true));
$kk = siparis_kalemler($db, (int)$s11['id']);
ok('3 kalem PO sırasına alındı (10/12/14), artikel no', array_map(fn($k) => [(int)$k['sira'], $k['kalibre'], $k['artikel_no']], $kk) === [[0, '10', '10000010'], [1, '12', '10000012'], [2, '14', '10000014']]);
ok('TC900012.10 de açıldı (yalnız PO)', siparis_test_sev($db, 'TC900012.10') !== null);
ok('gümrük = customs_office', $s11['gumruk'] === 'TESTPORT');

echo "\n=== 5d. PC kalibre bölümü farklı ===\n";
$r = siparis_test_isle($db, siparis_test_fixture('pc_bolum_farkli'), 'pc1');
ok('PC işlendi: 2 sevkiyat (biri taslak)', $r['isle']['ok'] && count($r['isle']['sevkiyatlar']) === 2);
$s11 = siparis_test_sev($db, 'TC900011.10');
$kk = []; foreach (siparis_kalemler($db, (int)$s11['id']) as $k) $kk[$k['kalibre']] = $k;
ok('pc_koli 10=2400, 12=2810; 14 PC\'de yok (_yok)', (int)$kk['10']['pc_koli'] === 2400 && (int)$kk['12']['pc_koli'] === 2810 && $kk['14']['pc_koli'] === null && in_array('pc', $kk['14']['_kaynak']['_yok'] ?? [], true));
ok('etkin = PC: 10 → 12 pal, 12 → 15 pal (yukarı), 14 → 0', (int)$kk['10']['etkin_palet'] === 12 && (int)$kk['12']['etkin_palet'] === 15 && (int)$kk['14']['etkin_palet'] === 0
    && $kk['10']['etkin_kaynak'] === 'pc' && (int)$s11['toplam_palet'] === 27);
$kodlar = array_count_values(array_column($s11['_uyarilar'], 'kod'));
ok('split_differs × 3, pc_palet_bolunmuyor, toplam_farkli', ($kodlar['split_differs'] ?? 0) === 3 && ($kodlar['pc_palet_bolunmuyor'] ?? 0) === 1 && ($kodlar['toplam_farkli'] ?? 0) === 1, json_encode($kodlar));
$sd = array_values(array_filter($s11['_uyarilar'], fn($u) => $u['kod'] === 'split_differs' && ($u['kalibre'] ?? '') === '10'))[0]['mesaj'] ?? '';
ok('split_differs mesajı Türkçe + binlik', str_contains($sd, 'Kalibre 10') && str_contains($sd, 'PC 2.400') && str_contains($sd, 'PO 800'), $sd);
ok('fiyat 4.11 EUR/CAR, miras bayrağı (12), fiyat tarihi', (float)$kk['10']['fiyat'] === 4.11 && $kk['10']['para_birimi'] === 'EUR' && $kk['10']['fiyat_birim'] === 'CAR'
    && (int)$kk['10']['fiyat_miras'] === 0 && (int)$kk['12']['fiyat_miras'] === 1 && $kk['12']['fiyat_tarihi'] === '2026-12-12');
ok('boşaltma tarihi aynı değer → kaynak PC (daha yetkili)', $s11['bosaltma_tarihi'] === '2026-12-12' && ($s11['_kaynak']['bosaltma_tarihi']['_s'] ?? '') === 'pc');
ok('var_pc=1; çeşit aynı → çatışma yok', (int)$s11['var_pc'] === 1 && (int)$s11['acik_catisma'] === 0);
$s99 = siparis_test_sev($db, 'TC900099.10');
ok('bilinmeyen order_ref → PC ile taslak (po_yok), ürün PC\'den', $s99 !== null && in_array('po_yok', array_column($s99['_uyarilar'], 'kod'), true) && siparis_kalemler($db, (int)$s99['id'])[0]['urun'] === 'Testfruit');

echo "\n=== 5e. Elle düzeltme, çatışma, kilit ===\n";
$sid1 = (int)siparis_test_sev($db, 'TC900001.10')['id'];
$x = siparis_alan_elle($db, 'sevkiyat', $sid1, 'plakalar', '34 tst 777 / 34TST778', 2);
$s1 = siparis_sevkiyat($db, $sid1);
ok('elle plaka normalize + kilit + _s=elle', $x['ok'] && $s1['plakalar'] === '34TST777-34TST778' && isset($s1['_elle']['plakalar']) && $s1['_kaynak']['plakalar']['_s'] === 'elle' && (int)$s1['_elle']['plakalar']['by'] === 2);
ok('alan_elle olayı + audit siparis_alan_duzenle', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'alan_elle'", [$sid1]) === 1
    && siparis_test_say($db, 'audit_log', "action = 'siparis_alan_duzenle' AND module = 'siparis'") === 1);
ok('elle: beyaz liste dışı alan reddedilir', !siparis_alan_elle($db, 'sevkiyat', $sid1, 'var_po', '0', 1)['ok'] && !siparis_alan_elle($db, 'kalem', 1, 'po_koli', '5', 1)['ok']);
ok('elle: geçersiz tarih reddedilir', !siparis_alan_elle($db, 'sevkiyat', $sid1, 'yukleme_tarihi', '31.02.2026', 1)['ok']);
ok('elle: negatif sayı reddedilir', !siparis_alan_elle($db, 'kalem', 1, 'etkin_palet', '-3', 1)['ok']);
$yeniLl = siparis_test_fixture('ll_plakali');
$yeniLl['header']['plates'] = ['34TST003', '34TST004']; $yeniLl['meta']['created'] = '2026-12-03 07:00:00';
$r = siparis_test_isle($db, $yeniLl, 'll3');
$s1 = siparis_sevkiyat($db, $sid1);
ok('yeni LL farklı plaka: değer DEĞİŞMEZ, çatışma (mevcut_kaynak elle)', $s1['plakalar'] === '34TST777-34TST778' && (int)$r['isle']['catisma'] >= 1
    && siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan = 'plakalar' AND durum = 'acik' AND mevcut_kaynak = 'elle' AND gelen_deger = '34TST003-34TST004'", [$sid1]) === 1);
ok('önceki LL eski sürüm oldu', (int)$db->query("SELECT gecerli FROM siparis_belge_bag WHERE belge_id = $llBelge AND sevkiyat_id = $sid1")->fetchColumn() === 0);
$cid = (int)$db->query("SELECT id FROM siparis_catismalar WHERE sevkiyat_id = $sid1 AND alan = 'plakalar' AND durum = 'acik'")->fetchColumn();
$x = siparis_catisma_coz($db, $cid, 'kabul', 3);
$s1 = siparis_sevkiyat($db, $sid1);
ok('kabul: gelen uygulandı, kilit kalktı, _s=ll', $x['ok'] && $s1['plakalar'] === '34TST003-34TST004' && !isset($s1['_elle']['plakalar']) && $s1['_kaynak']['plakalar']['_s'] === 'll');
ok('kabul: çatışma kabul/cozen, olay + audit', $db->query("SELECT durum || '-' || cozen FROM siparis_catismalar WHERE id = $cid")->fetchColumn() === 'kabul-3'
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'catisma_kabul'", [$sid1]) === 1 && siparis_test_say($db, 'audit_log', "action = 'siparis_catisma_coz'") === 1);
ok('çözülmüş çatışma ikinci kez çözülemez', !siparis_catisma_coz($db, $cid, 'red', 3)['ok']);
$cid2 = (int)$db->query("SELECT id FROM siparis_catismalar WHERE sevkiyat_id = $sid1 AND alan = 'yukleme_tarihi' AND durum = 'acik'")->fetchColumn();
$cid3 = (int)$db->query("SELECT id FROM siparis_catismalar WHERE sevkiyat_id = $sid1 AND alan = 'bosaltma_yerleri' AND durum = 'acik'")->fetchColumn();
ok('red: mevcut korunur', siparis_catisma_coz($db, $cid2, 'red', 3)['ok'] && siparis_catisma_coz($db, $cid3, 'red', 3)['ok']
    && siparis_sevkiyat($db, $sid1)['yukleme_tarihi'] === '2026-12-03' && (int)siparis_sevkiyat($db, $sid1)['acik_catisma'] === 0);
$c0 = siparis_test_say($db, 'siparis_catismalar');
$doc = siparis_test_fixture('po_revizyon');
$GLOBALS['_siparis_parse_override'] = fn(string $y): array => $doc;
siparis_belge_isle($db, $revBelge, 1);
unset($GLOBALS['_siparis_parse_override']);
ok('reddedilmiş aynı değer yeniden çatışma AÇMAZ', siparis_test_say($db, 'siparis_catismalar') === $c0 && (int)siparis_sevkiyat($db, $sid1)['acik_catisma'] === 0);
$x = siparis_alan_elle($db, 'sevkiyat', $sid1, 'nakliyeci', 'NAKLIYE X', 2);
ok('elle nakliyeci', $x['ok'] && siparis_sevkiyat($db, $sid1)['nakliyeci'] === 'NAKLIYE X');
$x = siparis_alan_kilidi_kaldir($db, 'sevkiyat', $sid1, 'nakliyeci', 2);
$s1 = siparis_sevkiyat($db, $sid1);
ok('kilit kaldır: kaynak değeri geri gelir (LL)', $x['ok'] && $s1['nakliyeci'] === 'NAKLIYE B' && !isset($s1['_elle']['nakliyeci']) && $s1['_kaynak']['nakliyeci']['_s'] === 'll');
ok('kilit kaldır: olay + audit', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'kilit_kaldirildi'", [$sid1]) === 1 && siparis_test_say($db, 'audit_log', "action = 'siparis_kilit_kaldir'") === 1);
ok('kilitli olmayan alanın kilidi kaldırılamaz', !siparis_alan_kilidi_kaldir($db, 'sevkiyat', $sid1, 'nakliyeci', 2)['ok']);
$k12 = array_values(array_filter(siparis_kalemler($db, (int)siparis_test_sev($db, 'TC900011.10')['id']), fn($k) => $k['kalibre'] === '12'))[0];
$x = siparis_alan_elle($db, 'kalem', (int)$k12['id'], 'etkin_palet', '14', 1);
$k12b = array_values(array_filter(siparis_kalemler($db, (int)$k12['sevkiyat_id']), fn($k) => $k['kalibre'] === '12'))[0];
ok('kalem etkin_palet elle kilidi hesaplamada önceliklidir', $x['ok'] && (int)$k12b['etkin_palet'] === 14 && (int)siparis_sevkiyat($db, (int)$k12['sevkiyat_id'])['toplam_palet'] === 26);
ok('notlar elle (yalnız elle alanı)', siparis_alan_elle($db, 'sevkiyat', $sid1, 'notlar', "Şoför sabah 7'de gelecek", 1)['ok']);

echo "\n=== 5h. Tur çakışması ===\n";
$llTur = siparis_test_fixture('ll_plakali');
$llTur['shipments'][0]['order_ref'] = 'TC900077.10'; foreach ($llTur['rows'] as &$rw) $rw['order_ref'] = 'TC900077.10'; unset($rw);
$llTur['header']['transport_no'] = '6100000077'; $llTur['meta']['created'] = '2026-12-02 18:00:00';
$r = siparis_test_isle($db, $llTur, 'll4');
$s77 = siparis_test_sev($db, 'TC900077.10'); $s1 = siparis_sevkiyat($db, $sid1);
ok('aynı tur başka order_ref: AYRI sevkiyat (birleştirilmez)', $s77 !== null && (int)$s77['id'] !== $sid1);
ok('tur_cakismasi uyarısı iki tarafta', in_array('tur_cakismasi', array_column($s77['_uyarilar'], 'kod'), true) && in_array('tur_cakismasi', array_column($s1['_uyarilar'], 'kod'), true));
ok('uyari olayı iki tarafta + mesaj', siparis_test_say($db, 'siparis_olaylar', "tur = 'uyari' AND aciklama LIKE 'Tur 9000000001%'") === 2 && str_contains(implode(' ', $r['isle']['mesajlar']), 'birleştirilmedi'));

echo "\n=== 5i. Depo yalıtımı ===\n";
$DEPOLAR = ['DEPO2'];
ok('başka depodaki sevkiyat görünmez', siparis_gorunur($s1) === false);
ok('boş depo her depoda görünür', siparis_gorunur(['depo' => '']) === true);
ok('belge: görünmeyen sevkiyata bağlı → görünmez', siparis_belge_gorunur($db, ['id' => $poBelge, 'depo' => 'DEPO1']) === false);
$DEPOLAR = ['depo1'];
ok('TR-duyarsız depo eşleşmesi', siparis_gorunur($s1) === true && siparis_belge_gorunur($db, ['id' => $poBelge, 'depo' => 'DEPO1']) === true);
$DEPOLAR = ['DEPO2'];
ok('bağsız belge kendi deposuna bakar', siparis_belge_gorunur($db, ['id' => 999999, 'depo' => 'DEPO2']) === true && siparis_belge_gorunur($db, ['id' => 999999, 'depo' => 'DEPO1']) === false);
$DEPOLAR = null;

echo "\n=== 6. Belge türleri: hata / tanınmayan / görsel / PKL dışı Excel ===\n";
$r = siparis_test_isle($db, ['doc_type' => 'error', 'error' => 'not a PDF', 'checks' => [], 'warnings' => []], 'err1');
$bh = $db->query("SELECT * FROM siparis_belgeler WHERE id = {$r['kayit']['belge_id']}")->fetch();
ok('ayrıştırılamayan PDF: ok=false, durum hata, genel mesaj', !$r['isle']['ok'] && $bh['durum'] === 'hata' && !str_contains((string)$bh['hata_mesaj'], 'not a PDF'));
$r = siparis_test_isle($db, ['doc_type' => 'unknown', 'checks' => [], 'warnings' => [['level' => 'error', 'code' => 'unknown_doc', 'message' => 'x']], 'meta' => []], 'unk1');
ok('tanınmayan PDF: tur bilinmiyor, bağ yok', $r['isle']['ok'] && $r['isle']['tur'] === 'bilinmiyor' && siparis_test_say($db, 'siparis_belge_bag', 'belge_id = ?', [(int)$r['kayit']['belge_id']]) === 0);
$r = siparis_test_isle($db, ['doc_type' => 'unknown', 'checks' => [], 'warnings' => [], 'meta' => []], 'unk2', 1, $sid1);
ok('tanınmayan PDF + hedef sevkiyat: tur diger, hedefe bağlı', $r['isle']['ok'] && $r['isle']['tur'] === 'diger' && siparis_test_say($db, 'siparis_belge_bag', 'belge_id = ? AND sevkiyat_id = ?', [(int)$r['kayit']['belge_id'], $sid1]) === 1);
$r = siparis_test_isle($db, siparis_test_fixture('po_uc_kalibre'), 'po3b', 1, $sid1);
ok('başka sevkiyatın belgesi hedef sayfadan yüklenirse içeriğe göre bağlanır + uyarı mesajı', $r['isle']['ok'] && str_contains(implode(' ', $r['isle']['mesajlar']), 'içeriyor; o sevkiyata bağlandı')
    && siparis_test_say($db, 'siparis_belge_bag', 'belge_id = ? AND sevkiyat_id = ?', [(int)$r['kayit']['belge_id'], $sid1]) === 0);
$k = siparis_dosya_kaydet($db, siparis_test_dosya($png, 'arac.png'), 1, 'DEPO1', siparis_test_mover());
$x = siparis_belge_isle($db, (int)$k['belge_id'], 1);
ok('görsel hedefsiz → hata', !$x['ok'] && str_contains((string)($x['hata'] ?? ''), 'Görsel yalnız sevkiyat sayfasından'));
$x = siparis_belge_isle($db, (int)$k['belge_id'], 1, $sid1);
ok('görsel hedefle → diger, bağlı', $x['ok'] && $x['tur'] === 'diger' && $db->query("SELECT durum FROM siparis_belgeler WHERE id = {$k['belge_id']}")->fetchColumn() === 'islendi');
$xf = siparis_test_dosya_yol(SIPARIS_PKL_SABLON, 'baska.xlsx');
file_put_contents($xf['tmp_name'], 'x', FILE_APPEND);   // farklı sha (zip sonuna bayt: ZipArchive yine açar)
$k = siparis_dosya_kaydet($db, $xf, 1, 'DEPO1', siparis_test_mover());
$GLOBALS['_siparis_parse_override'] = fn(string $y): array => ['ok' => true, 'pkl' => false, 'belge_no' => null, 'order_refs' => [], 'palet_min' => null, 'palet_max' => null, 'satir' => 0, 'baslik' => []];
$x = siparis_belge_isle($db, (int)$k['belge_id'], 1);
unset($GLOBALS['_siparis_parse_override']);
ok('PKL olmayan Excel → hata "Yalnız müşteri PKL"', !$x['ok'] && str_contains((string)$x['hata'], "Yalnız müşteri PKL"));

echo "\n=== 7. Durum makinesi ===\n";
$sid2 = (int)siparis_test_sev($db, 'TC900002.10')['id'];
$d = fn() => siparis_sevkiyat($db, $sid2)['durum'];
ok('otomatik ileri: siparis_alindi → hazirlik', siparis_durum_ilerlet($db, $sid2, 'hazirlik', null, 'test') === true && $d() === 'hazirlik');
ok('otomatik GERİ gitmez', siparis_durum_ilerlet($db, $sid2, 'siparis_alindi', null, 'test') === false && $d() === 'hazirlik');
ok('otomatik aynı sıra: değişmez', siparis_durum_ilerlet($db, $sid2, 'hazirlik', null, 'test') === false);
ok('otomatik iptal edemez', siparis_durum_ilerlet($db, $sid2, 'iptal', null, 'test') === false);
$aud0 = siparis_test_say($db, 'audit_log', "action = 'siparis_durum'");
ok('otomatik ilerleme audit yazmaz', $aud0 === 0);
ok('elle iptal gerekçesiz reddedilir', !siparis_durum_degistir($db, $sid2, 'iptal', 1, 'x')['ok']);
ok('elle iptal gerekçeyle', siparis_durum_degistir($db, $sid2, 'iptal', 1, 'Müşteri iptal etti')['ok'] && $d() === 'iptal' && siparis_sevkiyat($db, $sid2)['iptal_nedeni'] === 'Müşteri iptal etti');
ok('iptaldeyken otomatik ilerleme yok', siparis_durum_ilerlet($db, $sid2, 'pkl_hazir', null, 'test') === false);
ok('kapalıdan çıkış gerekçe ister', !siparis_durum_degistir($db, $sid2, 'hazirlik', 1)['ok']);
ok('kapalıdan çıkış gerekçeyle, iptal nedeni temizlenir', siparis_durum_degistir($db, $sid2, 'hazirlik', 1, 'Yanlışlıkla iptal')['ok'] && siparis_sevkiyat($db, $sid2)['iptal_nedeni'] === null);
ok('yola_cikti → yola_cikis_at', siparis_durum_degistir($db, $sid2, 'yola_cikti', 1)['ok'] && siparis_sevkiyat($db, $sid2)['yola_cikis_at'] !== null);
$x = siparis_durum_degistir($db, $sid2, 'mail_gonderildi', 1);
ok('mail_gonderildi PKL yokken reddedilir', !$x['ok'] && $x['hata'] === 'Önce PKL oluşturun ya da yükleyin.');
ok('geçersiz durum reddedilir', !siparis_durum_degistir($db, $sid2, 'uydurma', 1)['ok']);
ok('aynı duruma geçiş reddedilir', !siparis_durum_degistir($db, $sid2, 'yola_cikti', 1)['ok']);
ok('elle durum audit siparis_durum', siparis_test_say($db, 'audit_log', "action = 'siparis_durum'") === 3);

echo "\n=== 8. PKL üretimi + sezon sayaçları ===\n";
$x = siparis_pkl_uret($db, $sid1, 1, false);
ok('eksik hücre varken izinsiz üretim reddedilir (M/N boş)', !$x['ok'] && $x['eksik'] > 0 && str_contains((string)$x['hata'], 'boş'), json_encode($x));
ok('reddedilen üretim numara AYIRMAZ (rollback)', siparis_sayac_oku($db, 'palet_2026') === 0 && siparis_sayac_oku($db, 'belge_no_2026') === 0
    && siparis_test_say($db, 'siparis_belgeler', "tur = 'pkl'") === 0);
$durum = siparis_pkl_durum($db, $sid1);
ok('pkl_durum: satır yok değil (kilitlenmiş taslak kaldı mı?) — öneriler 1 / AsyaDoc001', $durum['oneri']['palet_bas'] === 1 && $durum['oneri']['belge_no'] === 'AsyaDoc001' && $durum['oneri']['sezon'] === '2026');
$x = siparis_pkl_uret($db, $sid1, 1, true);
$pk1 = $db->query("SELECT * FROM siparis_pkl WHERE sevkiyat_id = $sid1")->fetch();
ok('kısmi PKL üretildi: AsyaDoc001, palet 1–20', $x['ok'] && $pk1['belge_no'] === 'AsyaDoc001' && (int)$pk1['palet_bas'] === 1 && (int)$pk1['palet_bit'] === 20 && $pk1['durum'] === 'uretildi', json_encode($x));
ok('eksik_hucre kaydedildi, dosya adı kuralı', (int)$pk1['eksik_hucre'] === $x['eksik'] && $x['eksik'] > 0 && $x['dosya_adi'] === 'PKL-TC900001.10-AsyaDoc001-03.12.2026.xlsx');
ok('sayaçlar: palet 20, belge no 1', siparis_sayac_oku($db, 'palet_2026') === 20 && siparis_sayac_oku($db, 'belge_no_2026') === 1);
$s1 = siparis_sevkiyat($db, $sid1);
ok('var_pkl=1, durum → pkl_hazir, olay + audit', (int)$s1['var_pkl'] === 1 && $s1['durum'] === 'pkl_hazir'
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'pkl_uretildi'", [$sid1]) === 1 && siparis_test_say($db, 'audit_log', "action = 'siparis_pkl_uret'") === 1);
$pb = $db->query("SELECT * FROM siparis_belgeler WHERE id = {$x['belge_id']}")->fetch();
ok('PKL belgesi: tur pkl, kaynak uretim, dosya diskte', $pb['tur'] === 'pkl' && $pb['kaynak'] === 'uretim' && siparis_dosya_yolu($pb) !== null);
$x2 = siparis_durum_degistir($db, $sid1, 'mail_gonderildi', 1);
ok('kısmi PKL: mail_gonderildi ek onay ister', !$x2['ok'] && str_contains((string)$x2['hata'], 'boş hücre'));
$sid11 = (int)siparis_test_sev($db, 'TC900011.10')['id'];
$x = siparis_pkl_uret($db, $sid11, 1, true);
$pk11 = $db->query("SELECT * FROM siparis_pkl WHERE sevkiyat_id = $sid11")->fetch();
ok('ikinci PKL ardışık: AsyaDoc002, 21–46 (26 palet)', $x['ok'] && $pk11['belge_no'] === 'AsyaDoc002' && (int)$pk11['palet_bas'] === 21 && (int)$pk11['palet_bit'] === 46, json_encode($x));
$hata = '';
$db->beginTransaction();
try { siparis_sayac_rezerve($db, 'palet_2026', 5, 10, '2026', null); } catch (RuntimeException $e) { $hata = $e->getMessage(); }
$db->rollBack();
ok('çakışan aralık rezervasyonu reddedilir (Türkçe)', str_contains($hata, 'çakışıyor'), $hata);
$hata = '';
try { siparis_sayac_rezerve($db, 'palet_2026', 1, null, '2026', null); } catch (LogicException $e) { $hata = 'logic'; }
ok('rezerve transaction dışında çağrılamaz', $hata === 'logic');
// en son PKL (21–46) küçülür/büyür → sayaç birlikte
$d11 = siparis_pkl_durum($db, $sid11)['veri'];
$d11['bloklar'][0]['palet'] = 6;      // 12 → 6 (toplam 20)
ok('taslak kaydı', siparis_pkl_kaydet_taslak($db, $sid11, $d11, [], 1)['ok']);
$x = siparis_pkl_uret($db, $sid11, 1, true);
$pk11 = $db->query("SELECT * FROM siparis_pkl WHERE sevkiyat_id = $sid11")->fetch();
ok('en son PKL küçüldü: 21–40, sayaç 40, numara aynı', $x['ok'] && (int)$pk11['palet_bit'] === 40 && siparis_sayac_oku($db, 'palet_2026') === 40 && $pk11['belge_no'] === 'AsyaDoc002' && (int)$pk11['uretim_sayisi'] === 2);
ok('yeniden üretim: önceki PKL dosya bağı eski sürüm', siparis_test_say($db, 'siparis_belge_bag', "sevkiyat_id = ? AND gecerli = 0", [$sid11]) >= 1
    && count(array_filter(siparis_belgeler($db, $sid11, false), fn($b) => $b['tur'] === 'pkl')) === 1);
$d11['bloklar'][0]['palet'] = 12;
siparis_pkl_kaydet_taslak($db, $sid11, $d11, [], 1);
$x = siparis_pkl_uret($db, $sid11, 1, true);
ok('en son PKL büyüdü: 21–46, sayaç 46', $x['ok'] && (int)$db->query("SELECT palet_bit FROM siparis_pkl WHERE sevkiyat_id = $sid11")->fetchColumn() === 46 && siparis_sayac_oku($db, 'palet_2026') === 46);
$d1 = siparis_pkl_durum($db, $sid1)['veri'];
$d1['bloklar'][0]['palet'] = 21;
siparis_pkl_kaydet_taslak($db, $sid1, $d1, [], 1);
$x = siparis_pkl_uret($db, $sid1, 1, true);
ok('eski PKL büyüyemez (sonraki ile çakışır)', !$x['ok'] && str_contains((string)$x['hata'], 'sonraki PKL ile çakışır'), json_encode($x));
$d1['bloklar'][0]['palet'] = 18;
siparis_pkl_kaydet_taslak($db, $sid1, $d1, ['plakalar' => '34 TST 003 - 34 TST 004'], 1);
$x = siparis_pkl_uret($db, $sid1, 1, true);
$pk1 = $db->query("SELECT * FROM siparis_pkl WHERE sevkiyat_id = $sid1")->fetch();
ok('eski PKL küçülür: 1–18, boşluk uyarısı, sayaç değişmez', $x['ok'] && (int)$pk1['palet_bit'] === 18 && siparis_sayac_oku($db, 'palet_2026') === 46
    && in_array('aralik_bosluk', array_column($x['uyarilar'], 'kod'), true) && $pk1['plakalar'] === '34TST003-34TST004');
ok('taslak olayı + audit', siparis_test_say($db, 'siparis_olaylar', "tur = 'pkl_taslak'") >= 3 && siparis_test_say($db, 'audit_log', "action = 'siparis_pkl_taslak'") >= 3);
$sid77 = (int)siparis_test_sev($db, 'TC900077.10')['id'];
$v = siparis_pkl_durum($db, $sid77)['veri'];
$v['palet_bas_istenen'] = 30;
siparis_pkl_kaydet_taslak($db, $sid77, $v, [], 1);
$x = siparis_pkl_uret($db, $sid77, 1, true);
ok('istenen başlangıç çakışırsa üretim reddedilir', !$x['ok'] && str_contains((string)$x['hata'], 'çakışıyor'));
$v['palet_bas_istenen'] = 100;
siparis_pkl_kaydet_taslak($db, $sid77, $v, [], 1);
$x = siparis_pkl_uret($db, $sid77, 1, true);
ok('istenen başlangıç (100) kabul, sayaç 119', $x['ok'] && (int)$db->query("SELECT palet_bas FROM siparis_pkl WHERE sevkiyat_id = $sid77")->fetchColumn() === 100 && siparis_sayac_oku($db, 'palet_2026') === 119);
// — palet numarası denetimi (v372): ENGELLEMEYEN uyarılar; kural saf fonksiyonda, bağlam DB'den —
$sid12p = (int)siparis_test_sev($db, 'TC900012.10')['id'];
$pu = fn(int $bas, int $adet, ?int $hariç = null) => siparis_pkl_palet_uyarilari($db, '2026', $bas, $adet, $hariç ?? $sid12p);
$kod = fn(array $u) => array_column($u, 'kod');
ok('palet denetimi: sayaçtan hemen sonraki başlangıç (120) uyarısız', $pu(120, 5) === [], json_encode($pu(120, 5)));
$u = $pu(125, 5);
ok('palet denetimi: boşluk — "Önceki siparişin son palet no\'su 119; 120–124 arası 5 numara boşta kalıyor."', $kod($u) === ['palet_bosluk'] && $u[0]['mesaj'] === "Önceki siparişin son palet no'su 119; 120–124 arası 5 numara boşta kalıyor." && $u[0]['seviye'] === 'uyari', json_encode($u));
ok('palet denetimi: tek numara boşta → "120 numarası"', str_contains($pu(121, 5)[0]['mesaj'] ?? '', '120 numarası boşta kalıyor'));
$u = $pu(44, 5);
ok('palet denetimi: çakışma PKL\'yi (belge no + order ref + aralık) adlandırır, "geride" bastırılır', $kod($u) === ['palet_cakisma'] && str_contains($u[0]['mesaj'], 'AsyaDoc002 (TC900011.10) 21–46') && str_contains($u[0]['mesaj'], 'reddeder'), json_encode($u));
ok('palet denetimi: geride ama çakışmayan aralık → palet_geride', $kod($pu(60, 5)) === ['palet_geride'], json_encode($pu(60, 5)));
ok('palet denetimi: çakışma ENGEL değil (seviye uyari, hata yok)', array_unique(array_column($pu(44, 5), 'seviye')) === ['uyari']);
$u = $pu(100, 20, $sid77);
ok('palet denetimi: ayrılmış PKL kendi aralığıyla çakışmaz; ölçüt kendinden önceki en büyük bit (46)', !in_array('palet_cakisma', $kod($u), true) && str_contains($u[0]['mesaj'] ?? '', "son palet no'su 46; 47–99 arası 53 numara"), json_encode($u));
$b77 = siparis_pkl_palet_baglam($db, '2026', $sid77);
ok('palet bağlamı: kendi_bas=100, diğerleri 2 aralık, sayaç yerine önceki bit', $b77['kendi_bas'] === 100 && $b77['son'] === 46 && count($b77['diger']) >= 2);
ok('palet bağlamı: ayrılmamış sevkiyatta ölçüt sezon sayacı', siparis_pkl_palet_baglam($db, '2026', $sid12p)['son'] === 119 && siparis_pkl_palet_baglam($db, '2026', $sid12p)['kendi_bas'] === null);
ok('palet denetimi (saf): sezonun ilk numarası 1 → uyarı yok; 5 → "henüz ayrılmadı"', siparis_pkl_palet_uyarilari_hesapla(0, [], 1, 10) === [] && str_contains(siparis_pkl_palet_uyarilari_hesapla(0, [], 5, 10)[0]['mesaj'], 'henüz palet numarası ayrılmadı; 1–4 arası 4 numara'));
ok('palet denetimi (saf): geçersiz girdi (0 adet / başlangıç 0) → boş', siparis_pkl_palet_uyarilari_hesapla(10, [], 20, 0) === [] && siparis_pkl_palet_uyarilari_hesapla(10, [], 0, 5) === []);
ok('palet denetimi (saf): 3\'ten çok çakışma kısaltılır ("… PKL daha")', str_contains(siparis_pkl_palet_uyarilari_hesapla(0, array_map(fn($i) => ['belge_no' => 'D' . $i, 'order_ref' => '', 'bas' => $i * 10, 'bit' => $i * 10 + 5], [1, 2, 3, 4, 5]), 1, 100)[0]['mesaj'], 've 2 PKL daha'));
ok('Excel yapıştırma sayı kuralı = PHP: 812→"812", "1.234,5"→"1234.5", "812,5"→"812.5", "1.234"→"1234"', siparis_pkl_sayi('812') === '812' && siparis_pkl_sayi('1.234,5') === '1234.5' && siparis_pkl_sayi('812,5') === '812.5' && siparis_pkl_sayi('1.234') === '1234');
$x = siparis_pkl_uret($db, (int)siparis_test_sev($db, 'TC900012.10')['id'], 1, true);
ok('LL yoksa (navlun yok) PKL oluşturulamaz', !$x['ok'] && str_contains((string)$x['hata'], 'Loading List'));
siparis_sayac_ayarla($db, 'palet_2026', 200, 1);
ok('sayaç düzeltme + audit', siparis_sayac_oku($db, 'palet_2026') === 200 && siparis_test_say($db, 'audit_log', "action = 'siparis_sayac_duzelt'") === 1);
$hata = '';
try { siparis_sayac_ayarla($db, "palet_2026'; DROP", 1, 1); } catch (InvalidArgumentException $e) { $hata = 'ok'; }
ok('geçersiz sayaç adı reddedilir', $hata === 'ok');
$x = siparis_durum_degistir($db, $sid1, 'mail_gonderildi', 1, '', true);
$s1 = siparis_sevkiyat($db, $sid1);
ok('kısmi onayla mail_gonderildi: at/by + audit', $x['ok'] && $s1['durum'] === 'mail_gonderildi' && $s1['mail_gonderildi_at'] !== null && (int)$s1['mail_gonderildi_by'] === 1
    && siparis_test_say($db, 'audit_log', "action = 'siparis_mail_gonderildi'") === 1);
ok('mail_gonderildi → otomatik ilerleme yok (evrak bekleyen; mail/evrak yalnız ELLE)', siparis_durum_ilerlet($db, $sid1, 'pkl_hazir', null, 'x') === false);

echo "\n=== 9. Yüklenen müşteri PKL'i ===\n";
$sid12 = (int)siparis_test_sev($db, 'TC900012.10')['id'];
$xf = siparis_test_dosya_yol(SIPARIS_PKL_SABLON, 'PKL-TC900012.10-AsyaDoc210-09.12.2026.xlsx');
file_put_contents($xf['tmp_name'], 'yy', FILE_APPEND);
$k = siparis_dosya_kaydet($db, $xf, 1, 'DEPO1', siparis_test_mover());
$pkOz = ['ok' => true, 'pkl' => true, 'belge_no' => 'AsyaDoc210', 'order_refs' => ['TC900012.10', 'TC999999.10'], 'palet_min' => 201, 'palet_max' => 226, 'satir' => 26,
         'baslik' => ['C2' => '1102720', 'C4' => 'AsyaDoc210', 'C5' => '09.12.2026']];
$GLOBALS['_siparis_parse_override'] = fn(string $y): array => $pkOz;
$x = siparis_belge_isle($db, (int)$k['belge_id'], 1);
unset($GLOBALS['_siparis_parse_override']);
$pk12 = $db->query("SELECT * FROM siparis_pkl WHERE sevkiyat_id = $sid12")->fetch();
ok('yüklenen PKL: tur pkl, satır yuklendi, numara + aralık', $x['ok'] && $x['tur'] === 'pkl' && $pk12['durum'] === 'yuklendi' && $pk12['belge_no'] === 'AsyaDoc210'
    && (int)$pk12['palet_bas'] === 201 && (int)$pk12['palet_bit'] === 226 && (int)$pk12['dosya_belge_id'] === (int)$k['belge_id']);
ok('olmayan order_ref yalnız mesaj (taslak açılmaz)', siparis_test_sev($db, 'TC999999.10') === null && str_contains(implode(' ', $x['mesajlar']), 'TC999999.10'));
ok('sayaçlar max ile ilerledi (palet 226, belge 210)', siparis_sayac_oku($db, 'palet_2026') === 226 && siparis_sayac_oku($db, 'belge_no_2026') === 210);
$s12 = siparis_sevkiyat($db, $sid12);
ok('var_pkl=1, durum pkl_hazir, olay pkl_yuklendi', (int)$s12['var_pkl'] === 1 && $s12['durum'] === 'pkl_hazir' && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'pkl_yuklendi'", [$sid12]) === 1);
ok('mail_gonderildi yüklenen PKL ile (eksik 0) onaysız geçer', siparis_durum_degistir($db, $sid12, 'mail_gonderildi', 1)['ok']);
$x = siparis_belge_sil($db, (int)$k['belge_id'], 1);
$pk12 = $db->query("SELECT * FROM siparis_pkl WHERE sevkiyat_id = $sid12")->fetch();
ok('belge sil: yumuşak, PKL taslağa döner, var_pkl=0, olay + audit', $x['ok'] && $pk12['durum'] === 'taslak' && $pk12['dosya_belge_id'] === null
    && (int)siparis_sevkiyat($db, $sid12)['var_pkl'] === 0 && $db->query("SELECT deleted_at FROM siparis_belgeler WHERE id = {$k['belge_id']}")->fetchColumn() !== null
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'belge_silindi'", [$sid12]) === 1 && siparis_test_say($db, 'audit_log', "action = 'siparis_belge_sil'") === 1);
$k2 = siparis_dosya_kaydet($db, $xf, 1, 'DEPO1', siparis_test_mover());
ok('silinmiş belge yeniden yüklenince geri gelir (yeni=false)', $k2['ok'] && $k2['yeni'] === false && (int)$k2['belge_id'] === (int)$k['belge_id']
    && $db->query("SELECT deleted_at FROM siparis_belgeler WHERE id = {$k['belge_id']}")->fetchColumn() === null);
$x = siparis_belge_sil($db, $revBelge, 1);
$s1 = siparis_sevkiyat($db, $sid1);
ok('geçerli PO silinince en yeni kalan PO geçerli olur, değerler geri alınmaz', $x['ok'] && (int)$s1['po_belge_id'] === $poBelge && (int)$s1['var_po'] === 1 && $s1['kdv_no'] === 'XX000000009');

echo "\n=== 10. Elle sevkiyat / silme / geri getirme ===\n";
ok('elle aç: biçim denetimi', !siparis_sevkiyat_elle_ac($db, 'yanlis', null, null, 1)['ok']);
ok('elle aç: geçersiz tarih', !siparis_sevkiyat_elle_ac($db, 'TC900500.10', null, '2026-13-40', 1)['ok']);
$x = siparis_sevkiyat_elle_ac($db, 'tc900500.10', '9000000500', '15.12.2026', 1);
$s5 = $x['ok'] ? siparis_sevkiyat($db, (int)$x['id']) : null;
ok('elle aç: büyük harf, depo=aktif depo, elle kilitli alanlar', $s5 !== null && $s5['order_ref'] === 'TC900500.10' && $s5['depo'] === 'DEPO1' && $s5['yukleme_tarihi'] === '2026-12-15'
    && isset($s5['_elle']['tur_no'], $s5['_elle']['yukleme_tarihi']) && in_array('po_yok', array_column($s5['_uyarilar'], 'kod'), true));
ok('elle aç: audit siparis_sevkiyat_ac', siparis_test_say($db, 'audit_log', "action = 'siparis_sevkiyat_ac'") === 1);
ok('elle aç: aynı numara ikinci kez açılmaz', !siparis_sevkiyat_elle_ac($db, 'TC900500.10', null, null, 1)['ok']);
ok('sil: gerekçe ister', !siparis_sevkiyat_sil($db, (int)$s5['id'], 1, 'x')['ok']);
ok('sil: yumuşak + olay + audit', siparis_sevkiyat_sil($db, (int)$s5['id'], 1, 'Yanlış açıldı')['ok'] && siparis_sevkiyat($db, (int)$s5['id']) === null
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'silindi'", [(int)$s5['id']]) === 1 && siparis_test_say($db, 'audit_log', "action = 'siparis_sevkiyat_sil'") === 1);
$po5 = siparis_test_fixture('po_iki_sevkiyat');
$po5['loadings'] = [$po5['loadings'][1]]; $po5['loadings'][0]['order_ref'] = 'TC900500.10'; $po5['loadings'][0]['tour'] = '9000000500'; $po5['meta']['created'] = '2026-12-14 08:00:00';
$r = siparis_test_isle($db, $po5, 'po5');
ok('silinmiş sevkiyat yeni belgeyle geri getirilir + olay', siparis_sevkiyat($db, (int)$s5['id']) !== null && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'geri_getirildi'", [(int)$s5['id']]) === 1
    && str_contains(implode(' ', $r['isle']['mesajlar']), 'geri getirildi'));
ok('elle kilitli yükleme tarihi PO ile ezilmedi (çatışma)', siparis_sevkiyat($db, (int)$s5['id'])['yukleme_tarihi'] === '2026-12-15' && (int)siparis_sevkiyat($db, (int)$s5['id'])['acik_catisma'] === 1);

echo "\n=== 11. Profiller ===\n";
$x = siparis_profil_kaydet($db, null, ['ad' => 'Bahçe A', 'uretici_adi' => 'URETICI TEST', 'ggn' => '4000000000006', 'kalinti_tarihi' => '02.10.2026', 'lab_adi' => 'LAB TEST'], 1);
ok('profil oluştur', $x['ok'] && $x['id'] > 0);
ok('profil: GGN 13 hane değilse red', !siparis_profil_kaydet($db, null, ['ad' => 'B', 'ggn' => '123'], 1)['ok']);
ok('profil: ad zorunlu', !siparis_profil_kaydet($db, null, ['ad' => ''], 1)['ok']);
ok('profil: geçersiz tarih red', !siparis_profil_kaydet($db, null, ['ad' => 'C', 'kalinti_tarihi' => '40.40.2026'], 1)['ok']);
$x2 = siparis_profil_kaydet($db, (int)$x['id'], ['ad' => 'Bahçe A', 'uretici_adi' => 'URETICI TEST 2', 'ggn' => '4000000000006', 'kalinti_tarihi' => '2026-10-02', 'lab_adi' => 'LAB TEST'], 1);
$pr = siparis_profiller($db);
ok('profil güncelle + liste', $x2['ok'] && count($pr) === 1 && $pr[0]['uretici_adi'] === 'URETICI TEST 2' && $pr[0]['kalinti_tarihi'] === '2026-10-02');
$pa = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_profil_kaydet' ORDER BY id DESC")->fetchColumn();
ok('profil audit yalnız değişen ALAN ADLARI', str_contains((string)$pa, 'uretici_adi') && !str_contains((string)$pa, 'URETICI'));
$vp = siparis_pkl_varsayilan(siparis_sevkiyat($db, $sid11), siparis_kalemler($db, $sid11), $pr[0]);
ok('PKL varsayılanı profilden V..AB', $vp['varsayilan']['V'] === 'URETICI TEST 2' && $vp['varsayilan']['W'] === '4000000000006' && $vp['varsayilan']['AA'] === '2026-10-02' && $vp['profil_id'] === (int)$x['id']);
ok('profil sil (yumuşak) + audit', siparis_profil_sil($db, (int)$x['id'], 1) && siparis_profiller($db) === [] && count(siparis_profiller($db, false)) === 0
    && siparis_test_say($db, 'audit_log', "action = 'siparis_profil_sil'") === 1);
ok('silinmiş profil ikinci kez silinmez', siparis_profil_sil($db, (int)$x['id'], 1) === false);

echo "\n=== 12. Beyan önerisi / bağı ===\n";
$IS_ADMIN = true;    // beyan_redact_fields gizlemesin
$s1 = siparis_sevkiyat($db, $sid1);
$ins = $db->prepare('INSERT INTO customs_declarations (status, party_no, vehicle_plate, tractor_plate, net_kg, pallet_count, product_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$ins->execute(['beyan_acildi', 'X-1', '34TST004', '34TST003', 9000, (int)$s1['toplam_palet'], 'NAR', '2026-12-03 10:00:00']);          // plaka + palet + ürün
$b1id = (int)$db->lastInsertId();
$ins->execute(['beyan_acildi', 'PARTI TC900001.10', null, null, null, null, 'ELMA', '2026-12-01 10:00:00']);     // parti no
$b2id = (int)$db->lastInsertId();
$ins->execute(['beyan_acildi', 'ALAKASIZ', '06ABC111', null, null, 99, 'NAR', '2026-12-02 10:00:00']);          // yalnız ürün (+5) → listeye girmez
$ins->execute(['beyan_acildi', 'PARTI TC900001.10', '34TST003', null, null, null, null, '2026-10-01 10:00:00']); // pencere dışı
$s1 = siparis_sevkiyat($db, $sid1);
$cdOnce = $db->query('SELECT * FROM customs_declarations ORDER BY id')->fetchAll();
$on = siparis_beyan_oneriler($db, $s1);
ok('öneriler: 2 aday, puan sırası', count($on) === 2 && $on[0]['beyan_id'] === $b1id && $on[1]['beyan_id'] === $b2id, json_encode(array_map(fn($o) => [$o['beyan_id'], $o['puan']], $on)));
ok('puan: plaka 50 + palet 15 + ürün 5 = 70; parti 40', $on[0]['puan'] === 70 && $on[1]['puan'] === 40 && in_array('plaka eşleşti', $on[0]['gerekce'], true) && in_array('palet sayısı aynı', $on[0]['gerekce'], true));
ok('öneri özeti beyaz liste', array_keys($on[0]['beyan']) === ['id', 'status', 'party_no', 'vehicle_plate', 'tractor_plate', 'net_kg', 'pallet_count', 'product_name', 'created_at']);
$ara = siparis_beyan_ara($db, '34 tst 003');
ok('arama: plaka boşluksuz', count($ara) === 2);
ok('arama: id ile', count(siparis_beyan_ara($db, (string)$b2id)) >= 1 && siparis_beyan_ara($db, '') === []);
ok('arama: LIKE jokerleri kaçışlı', siparis_beyan_ara($db, '%') === []);
ok('bağla', siparis_beyan_bagla($db, $sid1, $b1id, 1, 'oneri', 'plaka eşleşti')['ok']);
ok('aynı çift ikinci kez bağlanamaz', !siparis_beyan_bagla($db, $sid1, $b1id, 1, 'elle', '')['ok']);
$x = siparis_beyan_bagla($db, $sid11, $b1id, 1, 'elle', '');
ok('başka sevkiyata bağlı beyan reddedilir (order_ref söyler)', !$x['ok'] && str_contains((string)$x['hata'], 'TC900001.10'));
ok('geçersiz tür reddedilir', !siparis_beyan_bagla($db, $sid11, $b2id, 1, 'otomatik', '')['ok']);
ok('olmayan beyan reddedilir', !siparis_beyan_bagla($db, $sid11, 999999, 1, 'elle', '')['ok']);
ok('bağlı beyan diğer sevkiyatın önerilerinde yok', !in_array($b1id, array_column(siparis_beyan_oneriler($db, siparis_sevkiyat($db, $sid11)), 'beyan_id'), true));
$bag = siparis_beyan_baglar($db, $sid1);
ok('bağlar + özet', count($bag) === 1 && $bag[0]['beyan']['id'] === $b1id && $bag[0]['tur'] === 'oneri');
ok('bağı kaldır', siparis_beyan_bag_kaldir($db, (int)$bag[0]['id'], 1)['ok'] && siparis_beyan_baglar($db, $sid1) === [] && count(siparis_beyan_baglar($db, $sid1, true)) === 1);
ok('kaldırılmış bağ tekrar kaldırılamaz', !siparis_beyan_bag_kaldir($db, (int)$bag[0]['id'], 1)['ok']);
ok('kaldırınca başka sevkiyata bağlanabilir', siparis_beyan_bagla($db, $sid11, $b1id, 1, 'elle', '')['ok']);
ok('beyan olayları + audit', siparis_test_say($db, 'siparis_olaylar', "tur IN ('beyan_baglandi', 'beyan_kaldirildi')") === 3
    && siparis_test_say($db, 'audit_log', "action = 'siparis_beyan_bagla'") === 2 && siparis_test_say($db, 'audit_log', "action = 'siparis_beyan_kaldir'") === 1);
// v413 — bağ sırasında sevkiyat plakası/şoförü beyana aktarılır; yalnız ilgili beyan + yalnız araç/şoför alanları değişir
$cdSon = $db->query('SELECT * FROM customs_declarations ORDER BY id')->fetchAll();
$degisenId = [];
foreach ($cdSon as $i => $r) if ($r != $cdOnce[$i]) $degisenId[] = (int)$r['id'];
ok('v413: bağ yalnız bağlanan beyanı değiştirir', $degisenId === [$b1id] || $degisenId === [], json_encode($degisenId));
echo "\n=== 13. Olay / okuma yardımcıları ===\n";
$ol = siparis_olaylar($db, $sid1, 5);
ok('olaylar en yeni önce, kullanıcı adı', count($ol) === 5 && (int)$ol[0]['id'] > (int)$ol[4]['id'] && $ol[0]['kullanici_adi'] !== null);
$hata = '';
try { siparis_olay($db, $sid1, 'uydurma', null, null); } catch (InvalidArgumentException $e) { $hata = 'ok'; }
ok('bilinmeyen olay türü reddedilir', $hata === 'ok');
siparis_olay($db, $sid1, 'uyari', str_repeat('x', 400), null, str_repeat('ğ', 300));
$son = siparis_olaylar($db, $sid1, 1)[0];
ok('olay metinleri 255 karakterde kırpılır', mb_strlen((string)$son['eski']) === 255 && mb_strlen((string)$son['aciklama']) === 255);
ok('çatışma listesi (hepsi / açık)', count(siparis_catismalar($db, $sid1, null)) >= 2 && siparis_catismalar($db, $sid1) === []);

echo "\n=== 14. Audit kuralları ===\n";
$aud = siparis_test_audit($db);
$uzun = array_filter($aud, fn($a) => strlen((string)$a['action']) > 40);
ok('tüm audit eylem adları ≤ 40 karakter', $uzun === [] && count($aud) > 10);
$izinli = ['siparis_belge_yukle', 'siparis_belge_sil', 'siparis_alan_duzenle', 'siparis_kilit_kaldir', 'siparis_catisma_coz', 'siparis_durum', 'siparis_mail_gonderildi',
    'siparis_pkl_taslak', 'siparis_pkl_uret', 'siparis_dosya_indir', 'siparis_zip_indir', 'siparis_beyan_bagla', 'siparis_beyan_kaldir', 'siparis_beyan_arac_aktar', 'siparis_sayac_duzelt',
    'siparis_profil_kaydet', 'siparis_profil_sil', 'siparis_sevkiyat_sil', 'siparis_sevkiyat_ac', 'siparis_evrak_gonderildi', 'siparis_evrak_gecis',
    'siparis_evrak_mail_ayar', 'siparis_kalem_ekle', 'siparis_kalem_yetim_ekle', 'siparis_eksik_okuma_onay', 'siparis_pkl_mail_ayar'];   // v411: evrak takımı mail alıcıları (hks_kv; yalnız adres SAYISI)
ok('yalnız planlanmış eylem adları', array_diff(array_unique(array_column($aud, 'action')), $izinli) === []);
$tum = implode("\n", array_map(fn($a) => $a['old_values'] . ' ' . $a['new_values'], $aud));
ok('audit kişisel veri / ayrıştırma içeriği taşımıyor', !preg_match('/URETICI|LAB TEST|4000000000006|example\.invalid|CLERK|Şoför|Müşteri iptal/u', $tum));
$src = '';
foreach (['siparis_core', 'siparis_eslestir', 'siparis_pkl', 'siparis_beyan', 'siparis_evrak'] as $f) $src .= file_get_contents($ROOT . '/config/' . $f . '.php');
preg_match_all("/siparis_audit\('([a-z_]+)'/", $src, $mm);
ok('kaynaktaki tüm audit eylem adları ≤ 40 ve planlı', $mm[1] !== [] && array_filter($mm[1], fn($a) => strlen($a) > 40 || !in_array($a, $izinli, true)) === []);
$kod = '';
foreach (token_get_all($src) as $t) if (!is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) $kod .= is_array($t) ? $t[1] : $t;
ok('kütüphanelerde exec/shell/eval yok', !preg_match('/(?<!->)\b(shell_exec|exec|passthru|system|proc_open|popen|eval)\s*\(/', $kod));
ok('siparis_migrate yalnız tanımda (çağrı yok)', preg_match_all('/siparis_migrate\(/', $kod) === 1 && str_contains($kod, 'function siparis_migrate('));
ok('siparis_pkl.php PhpOffice/PhpSpreadsheet kullanmaz', !str_contains((string)file_get_contents($ROOT . '/config/siparis_pkl.php'), 'PhpOffice'));

echo "\n=== 15. LL: tek belgede iki Transport (iki tır) + aynı belgenin yeniden okunması (v374) ===\n";
$dogru = siparis_test_fixture('ll_iki_transport');
// v373 ayrıştırıcısının hatalı çıktısı: başlıkta yalnız SON blok, sevkiyatlarda taşıma alanı yok,
// "Additional / company / info" etiketleri ilk tırın 2. satırına yapışmış (sahte tur no + sahte IAN → fazladan kalem)
$hatali = $dogru; unset($hatali['header']['transports']);
foreach (['transport_no', 'transport_company', 'plates', 'additional_info'] as $f) $hatali['header'][$f] = $dogru['header']['transports'][1][$f];
foreach ($hatali['shipments'] as &$x) foreach (['transport_no', 'transport_company', 'plates', 'additional_info'] as $f) unset($x[$f]); unset($x);
$sahte = $hatali['shipments'][0]; $sahte['tour_id'] = '9000000091 Additional info'; $sahte['items'] = [$sahte['items'][1]]; $sahte['items'][0]['ian'] = '90009 company';
$sahte['pallets'] = 2; $sahte['colli'] = 320; $sahte['row_count'] = 1;
$hatali['shipments'][0]['items'] = [$hatali['shipments'][0]['items'][0]]; $hatali['shipments'][0]['pallets'] = 2; $hatali['shipments'][0]['colli'] = 320; $hatali['shipments'][0]['row_count'] = 1;
array_splice($hatali['shipments'], 1, 0, [$sahte]);
$hatali['rows'][1]['ian'] = '90009 company'; $hatali['rows'][1]['tour_id'] = '9000000091 Additional info';
$r = siparis_test_isle($db, $hatali, 'll_iki_hatali');
$ikiBelge = (int)$r['kayit']['belge_id'];
$a = siparis_test_sev($db, 'TC900091.10'); $b = siparis_test_sev($db, 'TC900092.10');
ok('hatalı okuma (eski davranışın kopyası): 1. tır 2. tırın navlununu almış', $r['isle']['ok'] && $a['navlun_no'] === '6100000092' && $a['nakliyeci'] === 'NAKLIYE Y' && $a['plakalar'] === null);
ok('hatalı okuma: 1. tırda sahte IAN ile fazladan kalem', count(siparis_kalemler($db, (int)$a['id'])) === 2);
ok('aynı order ref iki kez: ilk kaydın kalemi "yok" sayılmaz', count(array_filter(siparis_kalemler($db, (int)$a['id']), fn($k) => (int)$k['ll_koli'] === 320)) === 2);
// aynı belge (aynı id) düzeltilmiş ayrıştırıcıyla yeniden işlenir — "↻ Yeniden işle"
$GLOBALS['_siparis_parse_override'] = fn(string $yol): array => $dogru;
try { $i = siparis_belge_isle($db, $ikiBelge, 1, null); } finally { unset($GLOBALS['_siparis_parse_override']); }
$a = siparis_test_sev($db, 'TC900091.10'); $b = siparis_test_sev($db, 'TC900092.10');
ok('yeniden işle başarılı, iki sevkiyat', $i['ok'] && count($i['sevkiyatlar']) === 2);
ok('1. tır KENDİ navlun / nakliyeci / plakasını aldı', $a['navlun_no'] === '6100000091' && $a['nakliyeci'] === 'NAKLIYE X' && $a['plakalar'] === '34TST091-34TST092');
ok('2. tır kendi navlun / nakliyecisini korudu, plakasız', $b['navlun_no'] === '6100000092' && $b['nakliyeci'] === 'NAKLIYE Y' && $b['plakalar'] === null);
ok('1. tır tur no = 9000000091 (sahte metin düzeldi)', $a['tur_no'] === '9000000091');
$ka = siparis_kalemler($db, (int)$a['id']);
ok('sahte IAN\'lı kalem kaldırıldı, tek kalem 4 pal / 640 koli', count($ka) === 1 && $ka[0]['ian'] === '90009' && (int)$ka[0]['ll_palet'] === 4 && (int)$ka[0]['ll_koli'] === 640);
ok('kaldırma olayı yazıldı', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'uyari' AND aciklama LIKE ?", [(int)$a['id'], '%yeniden okumasında yok%']) === 1);
$bel = $db->query("SELECT ozet FROM siparis_belgeler WHERE id = $ikiBelge")->fetchColumn();
ok('belge özeti iki tırı da sayar', str_contains((string)$bel, '6100000091 · NAKLIYE X') && str_contains((string)$bel, '6100000092 · NAKLIYE Y'));
// elle girilen navlun yeniden okumada EZİLMEZ (çatışma)
$db->prepare("UPDATE siparis_sevkiyatlar SET navlun_no = 'ELLE1', elle_alanlar = ? WHERE id = ?")->execute([json_encode(['navlun_no' => ['uid' => 1]]), (int)$b['id']]);
$GLOBALS['_siparis_parse_override'] = fn(string $yol): array => $dogru;
try { siparis_belge_isle($db, $ikiBelge, 1, null); } finally { unset($GLOBALS['_siparis_parse_override']); }
ok('elle kilitli navlun korunur', siparis_test_sev($db, 'TC900092.10')['navlun_no'] === 'ELLE1');
// geri alma: bu belgenin önceki okuması plaka yazdı, yeni okumada plaka yok → yalnız BU belgenin değeri geri alınır
$plakasiz = $dogru; $plakasiz['header']['transports'][0]['plates'] = []; $plakasiz['shipments'][0]['plates'] = [];
$GLOBALS['_siparis_parse_override'] = fn(string $yol): array => $plakasiz;
try { siparis_belge_isle($db, $ikiBelge, 1, null); } finally { unset($GLOBALS['_siparis_parse_override']); }
ok('aynı belgenin önceki plakası yeni okumada yoksa geri alınır', siparis_test_sev($db, 'TC900091.10')['plakalar'] === null);
// eski parse_json (sevkiyatta taşıma alanı yok) başlığa düşer
$eskiBicim = $dogru; unset($eskiBicim['header']['transports']); foreach ($eskiBicim['shipments'] as &$x) unset($x['transport_no'], $x['transport_company'], $x['plates'], $x['additional_info']); unset($x);
$r = siparis_test_isle($db, $eskiBicim, 'll_eski_bicim');
ok('eski biçim: sevkiyatta alan yoksa başlık kullanılır', $r['isle']['ok'] && siparis_test_sev($db, 'TC900092.10')['nakliyeci'] === 'NAKLIYE X');

echo "\n=== 16. v375: eski okuyucuyla okunmuş belge yeniden okunur + bayat açık çatışma temizlenir ===\n";
// aynı iki-tır fixture'ı farklı order ref / tur / navlun ile (bölüm 15 verisiyle karışmasın)
$yerine = ['TC900091' => 'TC900093', 'TC900092' => 'TC900094', '9000000091' => '9000000093', '9000000092' => '9000000094',
    '6100000091' => '6100000093', '6100000092' => '6100000094', '34TST091' => '34TST093', '34TST092' => '34TST094'];
$dogru16 = json_decode(strtr((string)json_encode(siparis_test_fixture('ll_iki_transport')), $yerine), true);
$po16 = json_decode(strtr((string)json_encode(siparis_test_fixture('po_iki_sevkiyat')), ['TC900001' => 'TC900093', 'TC900002' => 'TC900094', '9000000001' => '9000000093', '9000000002' => '9000000094', '90001' => '90009']), true);
$r = siparis_test_isle($db, $po16, 'po93');
ok('PO (TC900093/94) işlendi, tur no PO\'dan', $r['isle']['ok'] && siparis_test_sev($db, 'TC900093.10')['tur_no'] === '9000000093' && siparis_test_sev($db, 'TC900094.10')['tur_no'] === '9000000094');
// v2'nin hatalı çıktısı (bölüm 15 ile aynı kalıp): başlıkta yalnız son blok, sevkiyatta taşıma yok, sahte "… Additional info" turu
$hatali = $dogru16; unset($hatali['header']['transports']);
foreach (['transport_no', 'transport_company', 'plates', 'additional_info'] as $f) $hatali['header'][$f] = $dogru16['header']['transports'][1][$f];
foreach ($hatali['shipments'] as &$x) foreach (['transport_no', 'transport_company', 'plates', 'additional_info'] as $f) unset($x[$f]); unset($x);
$sahte = $hatali['shipments'][0]; $sahte['tour_id'] = '9000000093 Additional info'; $sahte['items'] = [$sahte['items'][1]]; $sahte['items'][0]['ian'] = '90009 company';
$sahte['pallets'] = 2; $sahte['colli'] = 320; $sahte['row_count'] = 1;
$hatali['shipments'][0]['items'] = [$hatali['shipments'][0]['items'][0]]; $hatali['shipments'][0]['pallets'] = 2; $hatali['shipments'][0]['colli'] = 320; $hatali['shipments'][0]['row_count'] = 1;
array_splice($hatali['shipments'], 1, 0, [$sahte]);
$hatali['rows'][1]['ian'] = '90009 company'; $hatali['rows'][1]['tour_id'] = '9000000093 Additional info';
$hatali['shipments'][0]['tour_id'] = '9000000093 Additional info';       // aynı ref'te İLK kayıt geçerli: sahte tur no PO'nunkiyle (eşit yetki) çatışır
$r = siparis_test_isle($db, $hatali, 'll93_v2');
$b16 = (int)$r['kayit']['belge_id'];
$a = siparis_test_sev($db, 'TC900093.10'); $b = siparis_test_sev($db, 'TC900094.10');
$acikTur = fn(int $sid) => siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan = 'tur_no' AND durum = 'acik'", [$sid]);
ok('v2 okuması: navlun/nakliyeci yanlış tırda, sahte kalem, tur_no çatışması AÇIK (bu belgeden)', $r['isle']['ok'] && in_array('tur_farkli', array_column($a['_uyarilar'], 'kod'), true) && $a['navlun_no'] === '6100000094' && $a['nakliyeci'] === 'NAKLIYE Y'
    && count(siparis_kalemler($db, (int)$a['id'])) === 2 && $acikTur((int)$a['id']) === 1 && (int)$a['acik_catisma'] >= 1
    && siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan = 'tur_no' AND belge_id = ?", [(int)$a['id'], $b16]) === 1,
    json_encode([$a['navlun_no'], $a['nakliyeci'], count(siparis_kalemler($db, (int)$a['id'])), $acikTur((int)$a['id']), $a['acik_catisma'], $db->query('SELECT alan,gelen_deger,belge_id,durum FROM siparis_catismalar')->fetchAll(PDO::FETCH_ASSOC)]));
// siparis_belge_surum_eski kuralları
$db->prepare("UPDATE siparis_belgeler SET parser_surum = 'v2' WHERE id = ?")->execute([$b16]);
$satir = fn() => $db->query("SELECT * FROM siparis_belgeler WHERE id = $b16")->fetch(PDO::FETCH_ASSOC);
ok('surum_eski: eski sürüm + işlenmiş → true', siparis_belge_surum_eski($satir()) === true);
$db->exec("UPDATE siparis_belgeler SET parser_surum = NULL WHERE id = $b16");
ok('surum_eski: parser_surum NULL (PKL / görsel) → false', siparis_belge_surum_eski($satir()) === false);
$db->exec("UPDATE siparis_belgeler SET parser_surum = '" . SIPARIS_PDF_SURUM . "' WHERE id = $b16");
ok('surum_eski: güncel sürüm → false', siparis_belge_surum_eski($satir()) === false);
$db->exec("UPDATE siparis_belgeler SET parser_surum = 'v2', durum = 'hata' WHERE id = $b16");
ok('surum_eski: durum hata → false (zaten yeniden işlenir)', siparis_belge_surum_eski($satir()) === false);
$db->exec("UPDATE siparis_belgeler SET durum = 'islendi' WHERE id = $b16");
ok('surum_eski: ham dizi (ham değerler)', siparis_belge_surum_eski(['parser_surum' => 'v2', 'durum' => 'islendi']) && !siparis_belge_surum_eski(['durum' => 'islendi']) && !siparis_belge_surum_eski(['parser_surum' => '', 'durum' => 'islendi']));
// aynı baytların yeniden yüklenmesi tekilleşir (ikinci satır yok); yeniden okuma AYNI belge id'siyle
$nBelge = siparis_test_say($db, 'siparis_belgeler');
$k = siparis_dosya_kaydet($db, siparis_test_dosya(siparis_test_pdf('ll93_v2'), 'll93_v2.pdf'), 1, 'DEPO1', siparis_test_mover());
ok('aynı bayt: yeni=false, aynı belge id, satır sayısı değişmedi', $k['ok'] && $k['yeni'] === false && (int)$k['belge_id'] === $b16 && siparis_test_say($db, 'siparis_belgeler') === $nBelge);
$GLOBALS['_siparis_parse_override'] = fn(string $y): array => $dogru16;
try { $i = siparis_belge_isle($db, $b16, 1, null); } finally { unset($GLOBALS['_siparis_parse_override']); }
$a = siparis_test_sev($db, 'TC900093.10'); $b = siparis_test_sev($db, 'TC900094.10');
ok('yeniden okuma başarılı, iki sevkiyat', $i['ok'] && count($i['sevkiyatlar']) === 2);
ok('93: kendi navlun / nakliyeci / plaka / tur no', $a['navlun_no'] === '6100000093' && $a['nakliyeci'] === 'NAKLIYE X' && $a['plakalar'] === '34TST093-34TST094' && $a['tur_no'] === '9000000093');
ok('94: kendi navlun / nakliyeci / plaka yok / tur no', $b['navlun_no'] === '6100000094' && $b['nakliyeci'] === 'NAKLIYE Y' && $b['plakalar'] === null && $b['tur_no'] === '9000000094');
$ka = siparis_kalemler($db, (int)$a['id']);
ok('sahte kalem gitti, tek kalem 4 pal / 640 koli', count($ka) === 1 && $ka[0]['ian'] === '90009' && (int)$ka[0]['ll_palet'] === 4 && (int)$ka[0]['ll_koli'] === 640);
ok('bayat açık tur_no çatışması silindi, acik_catisma 0', $acikTur((int)$a['id']) === 0 && siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND durum = 'acik'", [(int)$a['id']]) === 0 && (int)$a['acik_catisma'] === 0, json_encode([$a['acik_catisma']]));
ok('"tur_farkli" uyarısı kalmadı', !in_array('tur_farkli', array_column($a['_uyarilar'], 'kod'), true) && !in_array('tur_farkli', array_column($b['_uyarilar'], 'kod'), true));
ok('belge sürümü (siparis_belge_isle yazdı) güncel, artık "eski" değil', siparis_belge_surum_eski($satir()) === false && $satir()['durum'] === 'islendi');
// elle kilitli alanın AÇIK çatışması yeniden okumada silinmez (elle dalı bayat_sil çağırmaz)
$db->prepare("UPDATE siparis_sevkiyatlar SET navlun_no = 'ELLE2', elle_alanlar = ? WHERE id = ?")->execute([json_encode(['navlun_no' => ['uid' => 1]]), (int)$b['id']]);
foreach ([1, 2] as $tur) {
    $GLOBALS['_siparis_parse_override'] = fn(string $y): array => $dogru16;
    try { siparis_belge_isle($db, $b16, 1, null); } finally { unset($GLOBALS['_siparis_parse_override']); }
}
ok('elle kilitli navlun: korundu, çatışma AÇIK ve silinmedi (tek satır)', siparis_test_sev($db, 'TC900094.10')['navlun_no'] === 'ELLE2'
    && siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan = 'navlun_no' AND durum = 'acik' AND belge_id = ?", [(int)$b['id'], $b16]) === 1);
// başka belgenin / kabul edilmiş çatışmaya dokunmaz
$bid94 = (int)$b['id'];
$ekle = fn(string $durum, ?int $belge, string $gelen) => $db->prepare("INSERT INTO siparis_catismalar (sevkiyat_id, kalem_id, alan, mevcut_deger, mevcut_kaynak, gelen_deger, gelen_kaynak, belge_id, durum, created_at) VALUES (?, NULL, 'kdv_no', 'M', 'po', ?, 'll', ?, ?, ?)")
    ->execute([$bid94, $gelen, $belge, $durum, siparis_simdi()]);
$ekle('acik', $b16, 'ESKI'); $ekle('acik', $poBelge, 'BASKA'); $ekle('kabul', $b16, 'KABUL'); $ekle('acik', $b16, 'YENI');
siparis_catisma_bayat_sil($db, $bid94, null, 'kdv_no', 'll', $b16, 'YENI');
$kal = $db->query("SELECT gelen_deger FROM siparis_catismalar WHERE sevkiyat_id = $bid94 AND alan = 'kdv_no' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
ok('bayat_sil: yalnız aynı belge+kaynak, açık ve farklı değerli satır silinir (başka belge / kabul / aynı değer kalır)', $kal === ['BASKA', 'KABUL', 'YENI'], json_encode($kal));
siparis_catisma_bayat_sil($db, $bid94, null, 'kdv_no', 'll', 0, 'X');
ok('bayat_sil: belge id 0 → hiçbir şey silmez', siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan = 'kdv_no'", [$bid94]) === 3);

echo "\n=== 17. Şoför adı + telefonu (v377: yalnız-elle alanlar, ekleyici migrasyon) ===\n";
$sofS = siparis_test_sev($db, 'TC900001.10');
ok('şoför: kolonlar yokken hazır=false (sayfalar çalışır, alanlar gizli)', siparis_sofor_hazir($db) === false);
ok('şoför: alan tanımı yalnız-elle (otorite yok), telefon tipi', siparis_alan_tanimlari()['sevkiyat']['sofor_adi']['otorite'] === [] && siparis_alan_tanimlari()['sevkiyat']['sofor_telefon']['tip'] === 'telefon'
    && siparis_alan_tanimlari()['sevkiyat']['sofor_adi']['elle'] === true);
$m1 = siparis_sofor_migrate($db);
ok('şoför migrasyonu: iki kolon eklendi (yalnız ADD COLUMN), hazır=true', count($m1) === 2 && array_column($m1, 'durum') === ['eklendi', 'eklendi'] && siparis_sofor_hazir($db) === true, json_encode($m1));
$m2 = siparis_sofor_migrate($db);
ok('şoför migrasyonu idempotent (ikinci çalıştırma "var")', array_column($m2, 'durum') === ['var', 'var']);
ok('şoför kolonları kaynak DDL\'e ve siparis_migrate\'e KARIŞMAZ ("ALTER yok" değişmezi)', !str_contains(implode("\n", siparis_tablolar()), 'sofor_') && stripos(implode("\n", siparis_tablolar()), 'ALTER') === false);
$r = siparis_alan_elle($db, 'sevkiyat', (int)$sofS['id'], 'sofor_adi', '  Test   Sürücü ', 1);
$t = siparis_alan_elle($db, 'sevkiyat', (int)$sofS['id'], 'sofor_telefon', '+90 (532) 000-00-00 dahili', 1);
$s2 = siparis_sevkiyat($db, (int)$sofS['id']);
ok('şoför adı + telefon elle kaydedildi (boşluk teklenir, telefondan harf atılır), elle kilitli', $r['ok'] && $t['ok'] && $s2['sofor_adi'] === 'Test Sürücü' && $s2['sofor_telefon'] === '+90 (532) 000-00-00'
    && isset($s2['_elle']['sofor_adi']) && isset($s2['_elle']['sofor_telefon']), json_encode([$r, $t, $s2['sofor_telefon']]));
foreach (['abc', '12345', '1234567890123456', '+++'] as $bad) {
    $x = siparis_alan_elle($db, 'sevkiyat', (int)$sofS['id'], 'sofor_telefon', $bad, 1);
    if ($x['ok'] || ($x['hata'] ?? '') !== 'Telefon 7–15 haneli olmalı.') { ok('şoför: geçersiz telefon reddedilir (' . $bad . ')', false, json_encode($x)); }
}
ok('şoför: geçersiz telefonlar (harf / <7 / >15 hane) reddedildi, eski değer korunur', siparis_sevkiyat($db, (int)$sofS['id'])['sofor_telefon'] === '+90 (532) 000-00-00');
$x = siparis_alan_elle($db, 'sevkiyat', (int)$sofS['id'], 'sofor_adi', str_repeat('A', 101), 1);
ok('şoför adı ≤ 100 karakter', !$x['ok'] && str_contains((string)$x['hata'], 'çok uzun'));
// belge yeniden okununca şoför bilgisi ASLA silinmez / değişmez (belge kaynağı yok; boş gelen veri silmez)
$GLOBALS['_siparis_parse_override'] = fn(string $yol): array => siparis_test_fixture('ll_plakali');
try { siparis_belge_isle($db, $llBelge, 1, null); } finally { unset($GLOBALS['_siparis_parse_override']); }
$s3 = siparis_sevkiyat($db, (int)$sofS['id']);
ok('şoför: LL yeniden işlenince değişmez, çatışma açılmaz', $s3['sofor_adi'] === 'Test Sürücü' && $s3['sofor_telefon'] === '+90 (532) 000-00-00');
$cat = siparis_test_say($db, 'siparis_catismalar', "sevkiyat_id = ? AND alan IN ('sofor_adi','sofor_telefon') AND durum = 'acik'", [(int)$sofS['id']]);
ok('şoför: belge kaynaklı çatışma yok', $cat === 0);
// belgeden ileride şoför bilgisi gelirse (otorite yok → 'saklandi'): elle değer korunur, kaynak kaydı tutulur
$ar = siparis_alan_uygula($db, 'sevkiyat', (int)$sofS['id'], 'sofor_adi', 'BELGEDEN GELEN', 'll', $llBelge, '2099-01-01');
$s4 = siparis_sevkiyat($db, (int)$sofS['id']);
ok('şoför: belgeden gelen değer elle değeri ezmez, kaynaklar altında saklanır', $ar === 'saklandi' && $s4['sofor_adi'] === 'Test Sürücü' && ($s4['_kaynak']['sofor_adi']['ll']['v'] ?? null) === 'BELGEDEN GELEN', $ar);
$x = siparis_alan_elle($db, 'sevkiyat', (int)$sofS['id'], 'sofor_telefon', '', 1);
ok('şoför: boş değer girilerek temizlenebilir', $x['ok'] && siparis_sevkiyat($db, (int)$sofS['id'])['sofor_telefon'] === null);
$tumA = implode("\n", array_map(fn($a) => $a['old_values'] . ' ' . $a['new_values'], siparis_test_audit($db)));
ok('şoför: audit kaydına ad / telefon yazılmaz (yalnız alan adı)', !str_contains($tumA, 'Test Sürücü') && !str_contains($tumA, '532'));

echo "\n=== 7b. Evrak takımı (v400) ===\n";
$dur = siparis_durumlar();
ok('durumlar: evrak_gonderildi sıra 7, mail ile iptal arasında, css evrak (VARCHAR(20) sığar)', ($dur['evrak_gonderildi']['sira'] ?? 0) === 7 && ($dur['evrak_gonderildi']['css'] ?? '') === 'evrak'
    && array_keys($dur) === ['siparis_alindi', 'hazirlik', 'arac_atandi', 'yola_cikti', 'pkl_hazir', 'mail_gonderildi', 'evrak_gonderildi', 'iptal'] && strlen('evrak_gonderildi') <= 20);
ok('kapalı = evrak_gonderildi + iptal; evrak bekleyen = mail_gonderildi', siparis_kapali_durumlar() === ['evrak_gonderildi', 'iptal'] && siparis_evrak_bekleyen_durumlar() === ['mail_gonderildi']);
ok('kapanmamış SQL (sabit yazılmaz, listeden türer)', siparis_kapanmamis_sql() === "s.durum NOT IN ('evrak_gonderildi','iptal')" && siparis_kapanmamis_sql('x.durum') === "x.durum NOT IN ('evrak_gonderildi','iptal')");
ok('olay türü evrak_gonderildi tanımlı', in_array('evrak_gonderildi', SIPARIS_OLAY_TURLERI, true));
$ev = siparis_sevkiyat_elle_ac($db, 'TC900701.10', null, null, 1);
$evId = (int)$ev['id'];
$db->exec("UPDATE siparis_sevkiyatlar SET var_pkl = 1, durum = 'pkl_hazir' WHERE id = $evId");   // test kısayolu: PKL hazır (eksik hücre 0)
ok('otomatik ilerleme mail/evrak\'a ASLA geçmez', siparis_durum_ilerlet($db, $evId, 'mail_gonderildi', null, 't') === false && siparis_durum_ilerlet($db, $evId, 'evrak_gonderildi', null, 't') === false
    && siparis_sevkiyat($db, $evId)['durum'] === 'pkl_hazir');
$x = siparis_durum_degistir($db, $evId, 'evrak_gonderildi', 1);
ok('pkl_hazir → evrak_gonderildi reddedilir (yalnız mail adımından)', !$x['ok'] && $x['hata'] === 'Evrak takımı yalnız "Mail gönderildi" adımındaki sevkiyatta işaretlenebilir.', json_encode($x));
$x = siparis_durum_degistir($db, $evId, 'mail_gonderildi', 1);
$sm = siparis_sevkiyat($db, $evId);
$maAt = $sm['mail_gonderildi_at'];
$maAud = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_mail_gonderildi' AND record_id = $evId")->fetchColumn();
ok('pkl_hazir → mail_gonderildi: at/by yazılır, audit new_values "evrak_akisi":1 işaretini taşır', $x['ok'] && $sm['durum'] === 'mail_gonderildi' && $maAt !== null && is_string($maAud) && str_contains($maAud, SIPARIS_EVRAK_GECIS_ISARET), (string)$maAud);
ok('mail_gonderildi artık kapalı DEĞİL: kapalı kümede yok, evrak bekleyende var', !in_array($sm['durum'], siparis_kapali_durumlar(), true) && in_array($sm['durum'], siparis_evrak_bekleyen_durumlar(), true));
usleep(1100000);   // durum_at / updated_at farkı görünsün
$x = siparis_durum_degistir($db, $evId, 'evrak_gonderildi', 1);
$se = siparis_sevkiyat($db, $evId);
ok('mail → evrak ok: durum_at yenilenir, mail_gonderildi_at/by DEĞİŞMEZ', $x['ok'] && $se['durum'] === 'evrak_gonderildi' && $se['durum_at'] > $sm['durum_at'] && $se['mail_gonderildi_at'] === $maAt && (int)$se['mail_gonderildi_by'] === 1, json_encode($x));
ok('evrak: tek "evrak_gonderildi" olayı + tek siparis_evrak_gonderildi audit', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'evrak_gonderildi'", [$evId]) === 1
    && siparis_test_say($db, 'audit_log', "action = 'siparis_evrak_gonderildi' AND record_id = ?", [$evId]) === 1);
ok('evrak_gonderildi kapalıdır → otomatik ilerleme yok', siparis_durum_ilerlet($db, $evId, 'pkl_hazir', null, 'x') === false);
$x = siparis_durum_degistir($db, $evId, 'pkl_hazir', 1);
ok('evrak → pkl_hazir gerekçesiz reddedilir (kapalıdan çıkış)', !$x['ok'] && str_contains((string)$x['hata'], 'gerekçe'));
$x = siparis_durum_degistir($db, $evId, 'mail_gonderildi', 1, 'Evrak yanlışlıkla işaretlendi');
$sg = siparis_sevkiyat($db, $evId);
ok('evrak → mail_gonderildi (geri al) gerekçeyle ok: PKL kontrolü yok, at/by değişmez, olay "durum"', $x['ok'] && $sg['durum'] === 'mail_gonderildi' && $sg['mail_gonderildi_at'] === $maAt && (int)$sg['mail_gonderildi_by'] === 1
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'durum' AND yeni = 'mail_gonderildi'", [$evId]) === 1
    && siparis_test_say($db, 'audit_log', "action = 'siparis_mail_gonderildi' AND record_id = ?", [$evId]) === 1, json_encode($x));
$x = siparis_durum_degistir($db, $evId, 'evrak_gonderildi', 1);
ok('geri alınan sevkiyat yeniden evrak\'a işaretlenebilir', $x['ok']);
siparis_durum_degistir($db, $evId, 'mail_gonderildi', 1, 'Tekrar mail aşamasına');
$db->exec("UPDATE siparis_sevkiyatlar SET var_pkl = 0 WHERE id = $evId");
ok('geri alma var_pkl=0 iken de çalışır (PKL kontrolü yalnız ileri işarette)', siparis_sevkiyat($db, $evId)['durum'] === 'mail_gonderildi');
$x = siparis_durum_degistir($db, $evId, 'pkl_hazir', 1);
ok('mail_gonderildi → pkl_hazir gerekçesiz geçer (mail artık kapalı değil)', $x['ok'] && siparis_sevkiyat($db, $evId)['durum'] === 'pkl_hazir', json_encode($x));
$db->exec("UPDATE siparis_sevkiyatlar SET var_pkl = 1, durum = 'mail_gonderildi' WHERE id = $evId");
$x = siparis_durum_degistir($db, $evId, 'iptal', 1);
ok('mail_gonderildi → iptal gerekçe ister', !$x['ok'] && str_contains((string)$x['hata'], 'gerekçe'));

echo "\n=== 7c. Tek seferlik geçiş (v400) ===\n";
$gSev = function (string $ref, string $durum, ?string $mailAt, bool $silinmis = false) use ($db): int {
    $r = siparis_sevkiyat_elle_ac($db, $ref, null, null, 1);
    $id = (int)$r['id'];
    $st = $db->prepare('UPDATE siparis_sevkiyatlar SET durum = ?, mail_gonderildi_at = ?, durum_at = ?, deleted_at = ? WHERE id = ?');
    $st->execute([$durum, $mailAt, '2026-01-02 10:00:00', $silinmis ? '2026-02-01 00:00:00' : null, $id]);
    return $id;
};
$gA = $gSev('TC900711.10', 'mail_gonderildi', '2026-01-02 09:00:00');         // eski (işaretsiz), tarihli
$gB = $gSev('TC900712.10', 'mail_gonderildi', null);                          // eski, tarih yok
$gD = $gSev('TC900714.10', 'mail_gonderildi', '2026-01-03 09:00:00', true);   // eski, silinmiş
$gC = (int)siparis_sevkiyat_elle_ac($db, 'TC900713.10', null, null, 1)['id'];   // yeni akış: işaretli
$db->exec("UPDATE siparis_sevkiyatlar SET var_pkl = 1, durum = 'pkl_hazir' WHERE id = $gC");
siparis_durum_degistir($db, $gC, 'mail_gonderildi', 1);
ok('say: eski işaretsiz 3 (tarihli + tarihsiz + silinmiş); v400 akışıyla işaretlenenler sayılmaz', siparis_evrak_gecis_say($db) === 3, (string)siparis_evrak_gecis_say($db));
$ad = siparis_evrak_gecis_adaylar($db, 50);
ok('adaylar id sırasıyla, silinmiş de listede', array_column($ad, 'id') === [$gA, $gB, $gD] && $ad[2]['deleted_at'] !== null);
$olay0 = siparis_test_say($db, 'siparis_olaylar', "tur = 'evrak_gonderildi'");
$r = siparis_evrak_gecis_uygula($db, 1);
ok('uygula: 3 taşındı, kalan 0', $r['ok'] && $r['adet'] === 3 && $r['kalan'] === 0, json_encode($r));
$gDurum = fn(int $i): array => $db->query("SELECT durum, durum_at FROM siparis_sevkiyatlar WHERE id = $i")->fetch(PDO::FETCH_ASSOC);
ok('A, B, D evrak_gonderildi oldu; durum_at DEĞİŞMEDİ; C hâlâ mail_gonderildi', $gDurum($gA)['durum'] === 'evrak_gonderildi' && $gDurum($gB)['durum'] === 'evrak_gonderildi' && $gDurum($gD)['durum'] === 'evrak_gonderildi'
    && $gDurum($gA)['durum_at'] === '2026-01-02 10:00:00' && $gDurum($gD)['durum_at'] === '2026-01-02 10:00:00' && $gDurum($gC)['durum'] === 'mail_gonderildi');
ok('3 "Toplu geçiş" zaman çizelgesi satırı', siparis_test_say($db, 'siparis_olaylar', "tur = 'evrak_gonderildi'") - $olay0 === 3
    && siparis_test_say($db, 'siparis_olaylar', "tur = 'evrak_gonderildi' AND aciklama LIKE 'Toplu geçiş%'") === 3);
$ga = $db->query("SELECT new_values, record_id FROM audit_log WHERE action = 'siparis_evrak_gecis'")->fetchAll(PDO::FETCH_ASSOC);
ok('tam bir özet audit (adet 3, idler), kayıt id\'si NULL', count($ga) === 1 && $ga[0]['record_id'] === null && str_contains($ga[0]['new_values'], '"adet":3'), json_encode($ga));
$r2 = siparis_evrak_gecis_uygula($db, 1);
ok('ikinci çalıştırma idempotent: adet 0, yeni audit/olay yok', $r2['ok'] && $r2['adet'] === 0 && siparis_test_say($db, 'audit_log', "action = 'siparis_evrak_gecis'") === 1
    && siparis_test_say($db, 'siparis_olaylar', "tur = 'evrak_gonderildi'") - $olay0 === 3);
$ek = file_get_contents($ROOT . '/config/siparis_evrak.php');
$ekKod = '';
foreach (token_get_all($ek) as $t) if (!is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) $ekKod .= is_array($t) ? $t[1] : $t;
ok('siparis_evrak.php şema değiştirmez (ALTER / CREATE / DROP TABLE yok)', !preg_match('/\b(ALTER|CREATE|DROP)\s+TABLE\b/i', $ekKod));

echo "\n=== 12b. Plaka/şoför aktarımı + yüklendi gizleme (v413) ===\n";
ok('plaka ayırma: 1.=çekici 2.=dorse', siparis_beyan_plaka_ayir('34 ABC 123-06XYZ45') === ['cekici' => '34ABC123', 'dorse' => '06XYZ45']
    && siparis_beyan_plaka_ayir('34ABC123') === ['cekici' => '34ABC123', 'dorse' => ''] && siparis_beyan_plaka_ayir('') === ['cekici' => '', 'dorse' => '']);
$ins->execute(['beyan_acildi', 'AKTAR-1', 'ESKIDORSE', 'ESKICEKICI', 1000, 1, 'NAR', '2026-12-03 10:00:00']);
$bA = (int)$db->lastInsertId();
$ins->execute(['yuklendi', 'GIDEN-1', '34TST003', '34TST004', 1000, (int)$s1['toplam_palet'], 'NAR', '2026-12-03 10:00:00']);
$bY = (int)$db->lastInsertId();
$ins->execute(['beyan_acildi', 'AKTAR-2', 'KALSIN', 'KALSIN2', 1000, 1, 'NAR', '2026-12-03 10:00:00']);
$bB = (int)$db->lastInsertId();
$ins->execute(['beyan_acildi', 'AKTAR-3', null, null, 1000, 1, 'NAR', '2026-12-03 10:00:00']);
$bC = (int)$db->lastInsertId();
$db->prepare("UPDATE siparis_sevkiyatlar SET plakalar = ?, sofor_adi = ?, sofor_telefon = ? WHERE id = ?")->execute(['33AHS305-34DOR111', 'Test Şoför', '+90 539 000 00 00', $sid11]);
$db->prepare("UPDATE siparis_sevkiyatlar SET plakalar = NULL, sofor_adi = NULL, sofor_telefon = NULL WHERE id = ?")->execute([$sid1]);
$oy = siparis_beyan_oneriler($db, siparis_sevkiyat($db, $sid1), 50);
ok('yüklendi beyan önerilerde YOK', !in_array($bY, array_column($oy, 'beyan_id'), true));
ok('yüklendi beyan aramada YOK', !in_array($bY, array_column(siparis_beyan_ara($db, '34TST003'), 'id'), true) && in_array($b1id, array_column(siparis_beyan_ara($db, '34TST003'), 'id'), true));
$r = siparis_beyan_bagla($db, $sid1, $bY, 1, 'elle', '');
ok('yüklendi beyan bağlanamaz', !$r['ok'] && str_contains((string)$r['hata'], 'Yüklendi'));
$IS_ADMIN = true;
$r = siparis_beyan_bagla($db, $sid11, $bA, 1, 'elle', '');
$bayiSql = $db->prepare('SELECT tractor_plate, vehicle_plate, driver_name, driver_phone, updated_by FROM customs_declarations WHERE id = ?');
$bayiSql->execute([$bA]); $a = $bayiSql->fetch(PDO::FETCH_ASSOC);
ok('bağlanınca çekici/dorse/şoför beyana yazıldı (sipariş doğru kabul)', $r['ok'] && $a['tractor_plate'] === '33AHS305' && $a['vehicle_plate'] === '34DOR111'
    && $a['driver_name'] === 'Test Şoför' && $a['driver_phone'] === '+90 539 000 00 00' && (int)$a['updated_by'] === 1, json_encode([$r, $a]));
ok('aktarılan alan listesi döner', count($r['aktarilan'] ?? []) === 4);
$au = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_beyan_arac_aktar'")->fetchAll(PDO::FETCH_COLUMN);
ok('audit: aktarım yazıldı, şoför adı/telefonu audit\'e YOK', count($au) >= 1 && !str_contains(implode('', $au), 'Test Şoför') && !str_contains(implode('', $au), '539'));
// sipariş tarafı boşsa beyan korunur; tek plaka yalnız çekiciyi yazar
$db->prepare("UPDATE siparis_sevkiyatlar SET plakalar = ?, sofor_adi = NULL, sofor_telefon = NULL WHERE id = ?")->execute(['06ONE111', $sid1]);
$r = siparis_beyan_bagla($db, $sid1, $bB, 1, 'elle', '');
$bayiSql->execute([$bB]); $a = $bayiSql->fetch(PDO::FETCH_ASSOC);
ok('tek plaka: yalnız çekici yazılır, dorse/şoför beyandaki korunur', $r['ok'] && $a['tractor_plate'] === '06ONE111' && $a['vehicle_plate'] === 'KALSIN' && $a['driver_name'] === null, json_encode($a));
echo "\n=== v421. PO eksik okuma uyarısı + Kalem ekle (elle) + bağlanamayan kalem ===\n";
ok('eksik_okuma: temiz PO → null', siparis_po_eksik_okuma(['checks' => [['id' => 'sum_blocks_pallets', 'ok' => true]], 'orphan_items' => []]) === null);
$__chk = [['id' => 'sum_blocks_pallets', 'scope' => 'item 2', 'ok' => false, 'expected' => 15, 'actual' => 0]];
ok('eksik_okuma: sum_blocks başarısız → mesaj (beklenen/bulunan yazar)', str_contains((string)siparis_po_eksik_okuma(['checks' => $__chk]), 'beklenen 15') && str_contains((string)siparis_po_eksik_okuma(['checks' => $__chk]), 'bulunan 0'));
ok('eksik_okuma: başka denetimler (palet × PF vb.) tek başına tetiklemez', siparis_po_eksik_okuma(['checks' => [['id' => 'block_pallets_x_pf', 'ok' => false]]]) === null);
$__or = [['loading_date' => '2026-12-05', 'item' => ['pcs_per_box' => 16, 'pallets' => 15]]];
ok('eksik_okuma: bağlanamayan satır yalnız AYNI tarihli sevkiyata', str_contains((string)siparis_po_eksik_okuma(['orphan_items' => $__or], '2026-12-05'), '16 PC') && siparis_po_eksik_okuma(['orphan_items' => $__or], '2026-12-09') === null);

$__d = siparis_test_fixture('po_iki_sevkiyat');
$__l = $__d['loadings'][1]; $__l['order_ref'] = 'TC977701.10'; $__l['tour'] = '9777000001';
$__d['loadings'] = [$__l]; $__d['items'][0]['pallets'] = 10 + 15; $__d['items'][0]['quantity'] = 1600 + 2400;
$__d['orphan_items'] = [['loading_date' => '2026-12-05', 'logistics' => 'OGL', 'incoterm' => 'EXW', 'page' => 3, 'item' => [
    'ian' => '90001', 'description' => 'Testfruit 16 PC LOSE (TR)', 'product' => 'Testfruit', 'pcs_per_box' => 16, 'pack_mode' => 'LOSE', 'origin' => 'TR', 'pallets' => 15, 'pallet_type' => 'EV', 'pf' => 160, 'quantity' => 2400,
    'locations' => [['name' => 'LIDL XX A', 'role' => 'location', 'code' => null, 'pallets' => 15, 'pf' => 160, 'cartons' => 2400]]]]];
$__d['checks'] = [['id' => 'sum_blocks_pallets', 'scope' => 'item 1', 'ok' => false, 'expected' => 25, 'actual' => 10]];
$r = siparis_test_isle($db, $__d, 'po_yetim');
$__sv = siparis_test_sev($db, 'TC977701.10'); $__sid = (int)$__sv['id']; $__bid = (int)$r['kayit']['belge_id'];
$__u = array_values(array_filter($__sv['_uyarilar'], fn($u) => $u['kod'] === 'po_eksik_okuma'));
ok('PO eksik okunmuş → sevkiyatta po_eksik_okuma uyarısı (seviye hata)', $r['isle']['ok'] && count($__u) === 1 && $__u[0]['seviye'] === 'hata' && str_contains($__u[0]['mesaj'], '16 PC'), json_encode($__sv['_uyarilar']));
ok('liste rozeti sorgusu (uyarilar_json LIKE) bu sevkiyatı yakalar', (int)$db->query("SELECT CASE WHEN uyarilar_json LIKE '%po_eksik_okuma%' THEN 1 ELSE 0 END FROM siparis_sevkiyatlar WHERE id = $__sid")->fetchColumn() === 1);
ok('tarih eşleşmeyen diğer sevkiyatlarda (po1: TC900001.10) uyarı YOK', !array_filter(siparis_test_sev($db, 'TC900001.10')['_uyarilar'], fn($u) => $u['kod'] === 'po_eksik_okuma'));

$x = siparis_po_yetim_ekle($db, $__bid, 0, $__sid, 1);
$__k = array_values(array_filter(siparis_kalemler($db, $__sid), fn($k) => (string)$k['kalibre'] === '16'));
ok('yetim_ekle: 16 PC kalemi PO miktarıyla eklendi (15 palet / 2400 koli, etkin po)', $x['ok'] && count($__k) === 1 && (int)$__k[0]['po_palet'] === 15 && (int)$__k[0]['po_koli'] === 2400 && (int)$__k[0]['etkin_palet'] === 15 && $__k[0]['etkin_kaynak'] === 'po', json_encode([$x, $__k]));
ok('yetim_ekle: kalem alanları (ürün/açıklama/pf) PO satırından', $__k && $__k[0]['urun'] === 'Testfruit' && (int)$__k[0]['pf'] === 160);
ok('yetim_ekle: sevkiyat toplamı güncellendi (25 palet)', (int)siparis_sevkiyat($db, $__sid)['toplam_palet'] === 25);
ok('yetim_ekle: onay yazıldı → po_eksik_okuma uyarısı kalktı', !array_filter(siparis_sevkiyat($db, $__sid)['_uyarilar'], fn($u) => $u['kod'] === 'po_eksik_okuma'));
ok('yetim_ekle: ikinci kez reddedilir (çift ekleme yok)', !siparis_po_yetim_ekle($db, $__bid, 0, $__sid, 1)['ok']);
ok('yetim_ekle: belgeye bağlı olmayan sevkiyat reddedilir', !siparis_po_yetim_ekle($db, $__bid, 0, (int)siparis_test_sev($db, 'TC900001.10')['id'], 1)['ok']);
ok('yetim_ekle: olmayan satır reddedilir', !siparis_po_yetim_ekle($db, $__bid, 5, $__sid, 1)['ok']);
ok('yetim_ekle: olay + audit', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND aciklama LIKE 'Kalibre 16 kalemi PO%'", [$__sid]) === 1 && siparis_test_say($db, 'audit_log', "action = 'siparis_kalem_yetim_ekle'") === 1);

// elle kalem ekleme
$s1 = siparis_test_sev($db, 'TC900001.10'); $sid1 = (int)$s1['id'];
$x = siparis_kalem_elle_ekle($db, $sid1, '12', '', '5', '', '160', 1);
$__k = array_values(array_filter(siparis_kalemler($db, $sid1), fn($k) => (string)$k['kalibre'] === '12'));
ok('kalem_ekle: kalibre 12, 5 palet, koli = palet × PF (800), elle kilitli', $x['ok'] && count($__k) === 1 && (int)$__k[0]['etkin_palet'] === 5 && (int)$__k[0]['etkin_koli'] === 800 && (int)$__k[0]['pf'] === 160
    && isset($__k[0]['_elle']['etkin_palet']) && $__k[0]['etkin_kaynak'] === 'elle', json_encode([$x, $__k ? $__k[0]['etkin_kaynak'] : null]));
ok('kalem_ekle: sevkiyat toplamı arttı (20 + 5 palet)', (int)siparis_sevkiyat($db, $sid1)['toplam_palet'] === 25);
ok('kalem_ekle: aynı kalibre ikinci kez reddedilir', !siparis_kalem_elle_ekle($db, $sid1, '12', '', '1', '', '', 1)['ok'] && !siparis_kalem_elle_ekle($db, $sid1, '14', '', '1', '', '', 1)['ok']);
ok('kalem_ekle: kalibre boş / palet+koli boş / negatif / harfli reddedilir', !siparis_kalem_elle_ekle($db, $sid1, '', '', '1', '', '', 1)['ok'] && !siparis_kalem_elle_ekle($db, $sid1, '9', '', '', '', '', 1)['ok']
    && !siparis_kalem_elle_ekle($db, $sid1, '9', '', '-3', '', '', 1)['ok'] && !siparis_kalem_elle_ekle($db, $sid1, '9', '', 'abc', '', '', 1)['ok'] && !siparis_kalem_elle_ekle($db, $sid1, '9 7', '', '1', '', '', 1)['ok']);
ok('kalem_ekle: yalnız koli verilirse palet PF\'ten türer', siparis_kalem_elle_ekle($db, $sid1, '10', '', '', '1600', '160', 1)['ok'] && (int)array_values(array_filter(siparis_kalemler($db, $sid1), fn($k) => (string)$k['kalibre'] === '10'))[0]['etkin_palet'] === 10);
ok('kalem_ekle: olmayan sevkiyat reddedilir', !siparis_kalem_elle_ekle($db, 999999, '8', '', '1', '', '', 1)['ok']);
ok('kalem_ekle: olay + audit (kişisel veri yok)', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND aciklama LIKE 'Kalibre 12 kalemi elle eklendi%'", [$sid1]) === 1 && siparis_test_say($db, 'audit_log', "action = 'siparis_kalem_ekle'") >= 2);
// elle onay (kontrol ettim)
$__d2 = $__d; $__d2['loadings'][0]['order_ref'] = 'TC977702.10'; $__d2['loadings'][0]['tour'] = '9777000002'; $__d2['orphan_items'] = [];
$r2 = siparis_test_isle($db, $__d2, 'po_yetim_onay2');
$__s12 = siparis_test_sev($db, 'TC977702.10');
ok('denetim hatası (orphan yok): uyarı çıkar', count(array_filter($__s12['_uyarilar'], fn($u) => $u['kod'] === 'po_eksik_okuma')) === 1);
$o = siparis_eksik_okuma_onayla($db, (int)$__s12['id'], 1);
ok('"kontrol ettim" onayı uyarıyı kaldırır + olay + audit', $o['ok'] && !array_filter(siparis_sevkiyat($db, (int)$__s12['id'])['_uyarilar'], fn($u) => $u['kod'] === 'po_eksik_okuma')
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND aciklama LIKE 'PO eksik okuma uyarısı kontrol%'", [(int)$__s12['id']]) === 1 && siparis_test_say($db, 'audit_log', "action = 'siparis_eksik_okuma_onay'") === 1);

// uçtan uca: sayfa devamlı gerçek PO düzeni (arındırılmış fixture) → ayrıştır → uygula → TC…924.10 İKİ kalem, 25 palet, uyarı yok
require_once $ROOT . '/config/siparis_pdf.php';
$__px = json_decode((string)file_get_contents($ROOT . '/scripts/fixtures/siparis/po_sayfa_devami_pages.json'), true)['pages'];
$__pp = []; foreach ($__px as $pn => $sg) $__pp[(int)$pn] = $sg;
$__pd = siparis_pdf_parse_order($__pp);
$r = siparis_test_isle($db, $__pd, 'po_devam_gercek');
$__s = siparis_test_sev($db, 'TC432924.10');
$__kk = $__s ? siparis_kalemler($db, (int)$__s['id']) : [];
ok('uçtan uca: TC432924.10 iki kalem (14 PC 10 pal + 16 PC 15 pal), toplam 25 palet / 4.000 koli', $r['isle']['ok'] && count($__kk) === 2 && (int)$__s['toplam_palet'] === 25 && (int)$__s['toplam_koli'] === 4000
    && array_sum(array_map(fn($k) => (int)$k['po_palet'], $__kk)) === 25, json_encode([$r['isle'], count($__kk), $__s['toplam_palet'] ?? null]));
ok('uçtan uca: po_eksik_okuma uyarısı YOK (PO tam okundu)', $__s && !array_filter($__s['_uyarilar'], fn($u) => $u['kod'] === 'po_eksik_okuma'));

$IS_ADMIN = false;
siparis_test_bitir();
