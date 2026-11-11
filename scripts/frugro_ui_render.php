<?php
// scripts/frugro_ui_render.php — Frugro sayfalarını TARAYICI kontrolü için HTML'e basar (bellek içi SQLite, stub auth).
//   FRUGRO_UI_OUT=/tmp/frg php scripts/frugro_ui_render.php   (FRUGRO_ORNEK_DIR=<xlsx klasörü> ile gerçek örnekler)
//   Çıktı: liste.html, liste_arama.html, liste_tek_durum.html, detay.html (fixture), detay_<durum>.html (her durumdan sentetik sipariş),
//   pkl.html, invoice.html, ayarlar.html, siparis_idleri.json ({durum: id}).
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_core.php';
require_once $ROOT . '/vendor/autoload.php';
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$OUT = getenv('FRUGRO_UI_OUT') ?: sys_get_temp_dir() . '/frg-ui'; @mkdir($OUT, 0777, true);
$db = db(); siparis_test_diger_tablolar($db);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
class FrgExit extends RuntimeException {}
function frgt_header($h, $r = true, $c = 0): void {} function frgt_code($c = null) { return 200; }
function bas(string $dosya, array $get, string $ad): void {
    global $ROOT, $OUT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'frgt_header(', $src); $src = preg_replace('/\bhttp_response_code\(/', 'frgt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new FrgExit();', $src);
    $tmp = sys_get_temp_dir() . '/frg_r_' . getmypid() . '_' . basename($dosya); file_put_contents($tmp, $src);
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya; $_SERVER['REQUEST_URI'] = '/' . $dosya;
    ob_start(); try { (function () use ($tmp) { include $tmp; })(); } catch (FrgExit $e) {} catch (Throwable $e) { echo '<pre>HATA: ' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>'; }
    $html = (string)ob_get_clean(); @unlink($tmp);
    $html = preg_replace('#(href|src)="(?:\.\.?/)?assets/#', '$1="file://' . $ROOT . '/assets/', $html);
    file_put_contents("$OUT/$ad.html", $html); echo "$ad.html " . strlen($html) . "\n";
}
$PERMS = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match']; $IS_ADMIN = true; $UID = 1;
$dir = getenv('FRUGRO_ORNEK_DIR'); $dosyalar = $dir ? glob($dir . '/*.xlsx') : [$ROOT . '/scripts/fixtures/frugro/order_log_ornek.xlsx'];
foreach ($dosyalar as $f) { $o = frg_xlsx_oku($f); if ($o['ok']) frg_toplu_kaydet($db, $o, ['user_id' => 1, 'kaynak' => 'yukleme', 'dosya_sha' => $o['dosya_sha']]); }
$ids = $db->query('SELECT id FROM frugro_siparisler ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$pal = []; for ($i = 1; $i <= 26; $i++) $pal[] = ['palet_no' => (string)$i, 'kalibre' => '14', 'lot' => '26-1622-44080', 'ggn_no' => $i % 3 ? '4063651214000' : '4069453419551', 'ggn_adi' => $i % 3 ? 'Uretici A' : 'Uretici B', 'kutu' => 200, 'brut_kg' => 780 + $i, 'net_kg' => 680 + $i];
frg_palet_kaydet($db, (int)$ids[0], $pal);
// ── Sentetik siparişler: HER durumdan en az bir tane (kişisel veri YOK; P1/P2 ajanları ekran kontrolünde bunu kullanır) ──
//   Durumlar: yeni, hazirlik, pkl_hazir (paletli, 2 kalibreli), invoice_hazir (fatura_no + GBP), yola_cikti, mail_gonderildi,
//   evrak_gonderildi, iptal, eski kapali. Adım zamanları frugro_olaylar 'durum' olaylarından ("eski → yeni" kod biçimi) okunur.
//   Çıkış tarihleri bugüne göre dağıtılır (geçmiş / bugün / ileri) ve ikinci müşteri/liman çeşitliliği verilir.
$SENT = [  // [po, durum, çıkış günü ofseti, müşteri, liman, kutu_palet, palet, fiyat(on-binde), pb, parti, plaka, nakliyeci, tel, paletli, fatura]
    ['26-9001-90001', 'yeni',             3, 'Musteri-A', 'LIMAN-1', 200, 26, 46000, null,  null,    null,       null,        null,         false, null],
    ['26-9002-90002', 'hazirlik',         2, 'Musteri-B', 'LIMAN-2', 180, 20, 44000, null,  null,    null,       null,        null,         false, null],
    ['26-9003-90003', 'pkl_hazir',        1, 'Musteri-A', 'LIMAN-1', 200, 4,  46000, null,  'P-1001', '34 TST 001', 'Test Nakliyat', '+90 555 000 00 01', true,  null],
    ['26-9004-90004', 'invoice_hazir',    0, 'Musteri-C', 'LIMAN-1', 200, 4,  52000, 'GBP', 'P-1002', '34 TST 002', 'Test Nakliyat', '+90 555 000 00 02', true,  'INV-9004'],
    ['26-9005-90005', 'yola_cikti',      -1, 'Musteri-A', 'LIMAN-3', 220, 4,  46000, 'EUR', 'P-1003', '34 TST 003', 'Deneme Lojistik', '+90 555 000 00 03', true, 'INV-9005'],
    ['26-9006-90006', 'mail_gonderildi', -2, 'Musteri-B', 'LIMAN-2', 200, 4,  48000, 'GBP', 'P-1004', '34 TST 004', 'Deneme Lojistik', '+90 555 000 00 04', true, 'INV-9006'],
    ['26-9007-90007', 'evrak_gonderildi', -3, 'Musteri-C', 'LIMAN-1', 200, 4, 48000, 'GBP', 'P-1005', '34 TST 005', 'Test Nakliyat', '+90 555 000 00 05', true, 'INV-9007'],
    ['26-9008-90008', 'iptal',           -4, 'Musteri-B', 'LIMAN-3', 200, 10, 46000, null,  null,    null,       null,        null,         false, null],
    ['26-9009-90009', 'kapali',          -5, 'Musteri-A', 'LIMAN-1', 200, 4,  46000, 'GBP', 'P-1006', '34 TST 006', 'Test Nakliyat', '+90 555 000 00 06', true, 'INV-9009'],
];
$ADIM = ['yeni', 'hazirlik', 'pkl_hazir', 'invoice_hazir', 'yola_cikti', 'mail_gonderildi', 'evrak_gonderildi'];
$ins = $db->prepare("INSERT INTO frugro_siparisler (po_no, cikis_tarihi, varis_tarihi, giris_limani, son_musteri, son_musteri_po, urun, cesit, mense, boy, dis_paket, ic_paket, kutu, kutu_palet, palet, palet_tipi, incoterm, fiyat_kutu_birim, para_birimi, parti_no, plaka, sofor_adi, sofor_telefon, nakliyeci, fatura_no, durum, depo, kaynak, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$sentIds = [];
foreach ($SENT as $i => [$po, $dur, $of, $mus, $lim, $kp, $pl, $fy, $pb, $parti, $plaka, $nak, $tel, $paletli, $fat]) {
    $cik = date('Y-m-d', strtotime(($of >= 0 ? '+' : '') . $of . ' day'));
    $t0 = date('Y-m-d H:i:s', strtotime('-' . (10 - $i) . ' day'));
    $ins->execute([$po, $cik, date('Y-m-d', strtotime($cik . ' +8 day')), $lim, $mus, 'MP-' . (7000 + $i), $i % 2 ? 'NAR' : 'LIMON', 'Wonderful', 'TURKEY', '80-100', 'Karton 2.5 kg', 'Ağ', $kp * $pl, $kp, $pl, 'EUR palet', 'FCA', $fy, $pb, $parti, $plaka, $paletli ? 'Test Sofor' : null, $tel, $nak, $fat, $dur, 'DEPO1', $i % 2 ? 'mail' : 'yukleme', $t0, $t0]);
    $sid = (int)$db->lastInsertId(); $sentIds[$dur] = $sid;
    $db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$sid, 'olustu', 'Sipariş alındı: ' . $po, $t0]);
    if ($paletli) {   // 2 kalibreli palet seti (14 ve 16)
        $pp = []; for ($k = 1; $k <= $pl; $k++) $pp[] = ['palet_no' => (string)$k, 'kalibre' => $k % 2 ? '14' : '16', 'lot' => 'L-' . $k, 'ggn_no' => '4000000000001', 'ggn_adi' => 'ORNEK URETICI', 'kutu' => $kp, 'brut_kg' => 700 + $k, 'net_kg' => 640 + $k];
        frg_palet_kaydet($db, $sid, $pp);
    }
    $idx = array_search($dur, $ADIM, true);                 // geçilen adımların durum olayları (zaman damgalı)
    if ($idx !== false) for ($j = 1; $j <= $idx; $j++) $db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$sid, 'durum', $ADIM[$j - 1] . ' → ' . $ADIM[$j], date('Y-m-d H:i:s', strtotime($t0 . ' +' . $j . ' hour'))]);
    if ($dur === 'iptal') $db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$sid, 'durum', 'yeni → iptal · Gerekçe: Müşteri vazgeçti', date('Y-m-d H:i:s', strtotime($t0 . ' +1 hour'))]);
    if ($dur === 'kapali') $db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$sid, 'durum', 'Yeni → Kapalı', date('Y-m-d H:i:s', strtotime($t0 . ' +1 hour'))]);
}
// "fark" olayı + ham_json uyarısı (liste/detay ⚠ rozet ve Uyarılar kartı için) — yola_cikti siparişinde
$db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$sentIds['yola_cikti'], 'fark', 'Yeni dosyada fark var, alanlar EZİLMEDİ: palet: 4 → 5', date('Y-m-d H:i:s')]);
$db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$sentIds['yola_cikti'], 'guncellendi', 'Güncellendi: cikis_tarihi: 01.01.2026 → 02.01.2026', date('Y-m-d H:i:s')]);
$db->prepare('UPDATE frugro_siparisler SET ham_json = ? WHERE id = ?')->execute([json_encode(['uyarilar' => ['Sentetik uyarı: kutu toplamı palet x kutu/palet ile tutmuyor.']], JSON_UNESCAPED_UNICODE), $sentIds['yola_cikti']]);
file_put_contents("$OUT/siparis_idleri.json", json_encode($sentIds));
// P2 (detay): hks_kv — Frugro mail alıcı ayarı (yalnız @ornek.test) + üretilmiş Invoice girdisi (Invoice kartı / mail ekleri için)
$db->exec('CREATE TABLE IF NOT EXISTS hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
$db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['frugro_pkl_mail_ayar', json_encode(['kime' => ['siparis@ornek.test'], 'bilgi' => ['packinglist@ornek.test', 'lojistik@ornek.test'], 'govde' => '', 'evrak_govde' => ''])]);
foreach (['invoice_hazir' => 'INV-9004', 'yola_cikti' => 'INV-9005', 'mail_gonderildi' => 'INV-9006', 'evrak_gonderildi' => 'INV-9007'] as $dur => $no) {
    $db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['frugro_invoice_' . $sentIds[$dur], json_encode(['no' => $no, 'para_birimi' => 'GBP', 'uretildi_at' => date('Y-m-d H:i:s'), 'toplam_kurus' => 416000, 'snap' => ['no' => $no, 'po_no' => '26-900' . substr($no, -1) . '-9000' . substr($no, -1)]])]);
}
// v516: GGN listesi (ayar) + PKL eki (logger palet 2, antet açık) — sentetik, kişisel veri yok
$db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['frugro_ggn_uretici', json_encode(['liste' => [['ad' => 'Uretici A', 'ggn' => '4063651214000'], ['ad' => 'Uretici B', 'ggn' => '4069453419551']]])]);
$db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['frugro_pkl_ek_' . (int)$ids[0], json_encode(['logger_palet' => '2', 'antet' => true])]);
if (getenv('FRUGRO_UI_AYARSIZ')) $db->exec("DELETE FROM hks_kv WHERE anahtar = 'frugro_pkl_mail_ayar'");   // "alıcı tanımlı değil" görünümü
bas('frugro_siparis.php', [], 'liste');
bas('frugro_siparis.php', ['q' => 'P-100'], 'liste_arama');
bas('frugro_siparis.php', ['durum' => 'mail_gonderildi'], 'liste_tek_durum');
bas('frugro_siparis.php', ['durum' => 'pb_eksik', 'musteri' => 'Musteri-A'], 'liste_filtre');   // P1: sanal durum + müşteri süzgeci (▾ Filtre açık)
bas('frugro_yazdir.php', ['bolum' => 'acik'], 'yazdir_acik');          // P1: JSON uçlar (içerik .html adıyla)
bas('frugro_paylas.php', ['id' => $sentIds['pkl_hazir']], 'paylas_pkl_hazir');
foreach ($sentIds as $dur => $sid) bas('frugro_view.php', ['id' => $sid], 'detay_' . $dur);
bas('frugro_view.php', ['id' => $ids[0]], 'detay');   // fixture siparişi (26 paletli)
bas('frugro_pkl.php', ['id' => $ids[0]], 'pkl');
bas('frugro_invoice.php', ['id' => $ids[0]], 'invoice');
bas('frugro_ayarlar.php', [], 'ayarlar');
