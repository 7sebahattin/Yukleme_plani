<?php
// =========================================================
// scripts/frugro_mail_smoke.php — Frugro "Mailden al" (config/frugro_mail.php, frugro_mail.php, partial/JS/CSS) davranış testleri
//   php scripts/frugro_mail_smoke.php   → çıkış kodu 0 = tüm testler geçti
//
// Birleşik harness (_siparis_mail_test_lib.php): bellek içi SQLite + sahte IMAP + GERÇEK frugro_core/frugro_mail/mail_ek_hazirla.
// Sentetik: adresler yalnız @ornek.test; Excel = scripts/fixtures/frugro/order_log_ornek.xlsx (+ bellekte türetilen varyantlar).
// A ayar/kapı · B ek seçimi · C içe aktarım + 'Geri Gönderim' çift mail · D geçersiz/defter · E parçalı yürütme · F render/partial · G statik
// =========================================================
require_once __DIR__ . '/_siparis_mail_test_lib.php';
require_once $ROOT . '/config/frugro_core.php';
require_once $ROOT . '/config/frugro_mail.php';
require_once $ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$db = db();
smt_kur($db);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
$IS_ADMIN = true; $PERMS = []; $UID = 1; $AKTIF_DEPO = 'DEPO1';
$SIMDI = '2026-11-12 12:00:00';
$denetle = static fn(string $s): int => (int)$db->query($s)->fetchColumn();
$FIX = (string)file_get_contents($ROOT . '/scripts/fixtures/frugro/order_log_ornek.xlsx');

$A = smt_hesap('export@ornek.test'); $B = smt_hesap('iletisim@ornek.test'); $C = smt_hesap('pasif@ornek.test', false);
$MAIL = [$A => 'export@ornek.test', $B => 'iletisim@ornek.test'];
$GON = 'frugro@ornek.test'; $GON2 = 'baska@ornek.test';

/** Fixture'ın bir hücresini değiştirip bellekte yeni xlsx üretir (Order Log sayfasında 'Pallets' sütunu bulunur). */
function frgs_varyant(string $kaynak, callable $f): string
{
    $t = sys_get_temp_dir() . '/frgs_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.xlsx';
    file_put_contents($t, $kaynak);
    $x = IOFactory::load($t);
    $f($x);
    (new Xlsx($x))->save($t);
    $b = (string)file_get_contents($t); @unlink($t);
    return $b;
}
function frgs_bos_xlsx(string $sayfa, ?string $po): string
{
    $x = new Spreadsheet(); $ws = $x->getActiveSheet(); $ws->setTitle($sayfa);
    $ws->setCellValue('A1', 'SENTETIK');
    if ($po !== null) { $ws->setCellValueExplicit('A2', '(PO) Order Number', 's'); $ws->setCellValueExplicit('A4', $po, 's'); }
    $t = sys_get_temp_dir() . '/frgs_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.xlsx';
    (new Xlsx($x))->save($t); $b = (string)file_get_contents($t); @unlink($t);
    return $b;
}
const FRGS_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
function frgs_ek(string $part, string $ad, ?string $bytes, string $mime = FRGS_MIME): array { return smt_ek($part, $ad, $bytes, $mime); }
function frgs_tara(int $uid = 1, string $depo = 'DEPO1', array $opt = []): array
{
    $db = db();
    $r = frgm_baslat($db, $uid, $depo, $opt);
    $n = 0;
    while ($r['ok'] && empty($r['bitti']) && $n++ < 100) $r = frgm_adim($db, (string)$r['tarama_id'], $uid, $depo, $opt + ['istemci' => smt_fabrika()]);
    return $r;
}
function frgs_durumlar(array $r): array { return array_map(static fn(array $s): string => (string)$s['durum'], (array)($r['son']['satirlar'] ?? [])); }

echo "=== A. Ayar + kapı ===\n";
$r = frgm_baslat($db, 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('ayarsız → 400 ayarsiz', !$r['ok'] && $r['kod'] === 'ayarsiz' && $r['http'] === 400, json_encode($r));
$kombo = [];
foreach ([[false, false], [true, false], [false, true], [true, true]] as [$sw, $mr]) {
    $IS_ADMIN = false; $PERMS = array_merge(['siparis.read'], $sw ? ['siparis.write'] : [], $mr ? ['mail.read'] : []);
    $kombo[] = frgm_yetkisi();
}
ok('frgm_yetkisi: yalnız siparis.write VE mail.read birlikte', $kombo === [false, false, false, true], json_encode($kombo));
$IS_ADMIN = false; $PERMS = ['siparis.read', 'siparis.write'];   // mail.read YOK
$r = frgm_baslat($db, 2, 'DEPO1');
ok('yetkisiz (mail.read yok) baslat → 403', !$r['ok'] && $r['http'] === 403 && $r['kod'] === 'yetki', json_encode($r));
ok('yetkisiz devam / vazgeç → 403', frgm_adim($db, str_repeat('a', 16), 2, 'DEPO1')['http'] === 403 && frgm_vazgec($db, str_repeat('a', 16), 2)['http'] === 403);
$PERMS = ['siparis.read', 'siparis.delete'];
$k1 = frgm_ayar_yetkisi(); $PERMS = ['mail.read', 'mail.admin']; $k2 = frgm_ayar_yetkisi(); $PERMS = ['siparis.read', 'siparis.delete', 'mail.admin']; $k3 = frgm_ayar_yetkisi();
$IS_ADMIN = true; $PERMS = [];
ok('ayar yetkisi: sipariş silme + mail yönetimi ikisi birlikte', !$k1 && !$k2 && $k3);
$IS_ADMIN = false; $PERMS = ['siparis.read', 'siparis.write', 'mail.read'];
ok('yetkisiz kullanıcı ayarı DEĞİŞTİREMEZ', !frgm_ayar_kaydet($db, ['hesaplar' => [$A], 'gonderen' => $GON], 1)['ok'] && siparis_mail_kv_oku($db, FRGM_KV_AYAR) === null);
$IS_ADMIN = true; $PERMS = [];
ok('geçersiz e-posta reddedilir', !frgm_ayar_kaydet($db, ['hesaplar' => [$A], 'gonderen' => 'bu bir adres degil'], 1)['ok']);
ok('olmayan / pasif posta kutusu reddedilir', !frgm_ayar_kaydet($db, ['hesaplar' => [9999], 'gonderen' => $GON], 1)['ok'] && !frgm_ayar_kaydet($db, ['hesaplar' => [$C], 'gonderen' => $GON], 1)['ok']);
ok('en çok 10 gönderen', !frgm_ayar_kaydet($db, ['hesaplar' => [$A], 'gonderen' => implode("\n", array_map(fn($i) => "u$i@ornek.test", range(1, 11)))], 1)['ok']);
ok('geçersiz ayarlar KV\'ye yazılmadı', siparis_mail_kv_oku($db, FRGM_KV_AYAR) === null);
$y = frgm_ayar_kaydet($db, ['hesaplar' => [$A, $A], 'gonderen' => "Frugro@Ornek.Test\n frugro@ornek.test, $GON2;"], 1);
$ay = frgm_ayar_oku($db);
ok('ayar kaydedildi: adresler küçük harf + tekil, hesap tekil', $y['ok'] && $ay['hesaplar'] === [$A] && $ay['gonderen'] === [$GON, $GON2], json_encode($ay));
$aud = $db->query("SELECT new_values FROM audit_log WHERE action = 'frugro_mail_ayar'")->fetchAll(PDO::FETCH_COLUMN);
ok('audit frugro_mail_ayar yalnız SAYILAR içerir (adres geçmez)', count($aud) === 1 && !str_contains($aud[0], '@') && str_contains($aud[0], '"hesap_sayisi":1') && str_contains($aud[0], '"gonderen_sayisi":2'), json_encode($aud));
ok('OGL ayarı ile karışmaz (siparis_mail_ayar anahtarı boş)', siparis_mail_kv_oku($db, SIPARIS_MAIL_KV_AYAR) === null);
$db->exec("UPDATE hks_kv SET deger = '{bozuk' WHERE anahtar = 'frugro_mail_ayar'");
ok('bozuk ayar → boş (fail-closed)', frgm_ayar_oku($db) === ['hesaplar' => [], 'gonderen' => []] && !frgm_ayarli_mi(frgm_ayar_oku($db)));
frgm_ayar_kaydet($db, ['hesaplar' => [$A], 'gonderen' => "$GON\n$GON2"], 1);
ok('frgm_hazir: OGL mail şeması + Frugro tabloları', frgm_hazir($db));

echo "\n=== B. Ek seçimi ===\n";
$e = fn(string $part, string $ad, string $mime) => ['part' => $part, 'filename' => $ad, 'mime' => $mime, 'size' => 5000];
$sec = frgm_excel_ekleri(['attachments_json' => json_encode([
    $e('2', 'A PO 26-1623-44081 FRUGRO.xlsx', FRGS_MIME), $e('3', 'B.xlsm', 'application/vnd.ms-excel.sheet.macroEnabled.12'), $e('4', 'C.XLSX', 'application/octet-stream'),
    $e('5', 'D.pdf', 'application/pdf'), $e('6', 'E.xls', 'application/vnd.ms-excel'), $e('7', 'foto.png', 'image/png'), $e('8', 'Fwd.eml', 'message/rfc822'), $e('9]', 'k.xlsx', FRGS_MIME)]),
    'body_truncated' => 0, 'subject' => 'k']);
ok('Excel seçimi: .xlsx/.xlsm (mime ya da ad); pdf/xls/png/geçersiz parça hariç', array_column($sec['ekler'], 'part') === ['2', '3', '4'], json_encode($sec['ekler']));
ok('message/rfc822 → iletilen', array_column($sec['iletilen'], 'part') === ['8'] && $sec['sorun'] === null);
ok('kesik mail + Excel yok → ek_eksik', frgm_excel_ekleri(['attachments_json' => json_encode([$e('2', 'logo.png', 'image/png')]), 'body_truncated' => 1, 'subject' => 'k'])['sorun'] === 'ek_eksik');
ok('bozuk attachments_json → boş', frgm_excel_ekleri(['attachments_json' => '{bozuk', 'body_truncated' => 0, 'subject' => ''])['ekler'] === []);
$tmpv = sys_get_temp_dir() . '/frgs_v_' . getmypid() . '.xlsx';
file_put_contents($tmpv, $FIX);
ok("'Order Log' sayfası varlık kapısı (fixture evet, 'Sheet1' hayır)", frgm_order_log_var_mi($tmpv) && (file_put_contents($tmpv, frgs_bos_xlsx('Sheet1', '26-1622-44080')) !== false) && !frgm_order_log_var_mi($tmpv));
@unlink($tmpv);

echo "\n=== C. İçe aktarım + 'Geri Gönderim' çift mail ===\n";
$konu = 'Frugro / Nar / PO 26-1622-44080 / Ingiltere';
$ad = 'Pomegranates Ordering ASYA FRESH PO 26-1622-44080 FRUGRO.xlsx';
$m1 = smt_mail($A, $MAIL[$A], 'FRUGRO@ORNEK.TEST', [frgs_ek('2', $ad, $FIX), smt_ek('3', 'imza.pdf', smt_pdf('po1'), 'application/pdf')], '2026-11-10 09:00:00', ['subject' => $konu]);
$m2 = smt_mail($A, $MAIL[$A], 'baska@ornek.test', [frgs_ek('2', $ad, $FIX)], '2026-11-10 11:00:00', ['subject' => 'Geri Gönderim: ' . $konu]);          // aynı dosya, farklı adres
$m3 = smt_mail($A, $MAIL[$A], 'yabanci@ornek.test', [frgs_ek('2', $ad, $FIX)], '2026-11-10 11:30:00', ['subject' => $konu, 'reply_to' => $GON]);       // gönderen listede değil
$m4 = smt_mail($A, $MAIL[$A], $GON, [frgs_ek('2', $ad, $FIX)], '2026-10-01 09:00:00', ['subject' => $konu]);                                              // 15 gün dışı
smt_sayac_sifirla();
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
$st = array_column($r['son']['satirlar'], 'durum', 'm');
ok('1. mail: Excel alındı; PDF eki ve tanınmayan gönderen/pencere dışı mailler işlenmedi', ($st[$m1] ?? '') === 'alindi' && !isset($st[$m3]) && !isset($st[$m4]), json_encode($st));
ok("'Geri Gönderim' (2. adres, aynı dosya) → zaten", ($st[$m2] ?? '') === 'zaten', json_encode($st));
ok('tek PO kaydı: kaynak=mail, kaynak_mail_id=1. mail, depo=DEPO1, dosya sha saklandı', $denetle('SELECT COUNT(*) FROM frugro_siparisler') === 1
    && (string)$db->query('SELECT kaynak FROM frugro_siparisler')->fetchColumn() === 'mail' && (int)$db->query('SELECT kaynak_mail_id FROM frugro_siparisler')->fetchColumn() === $m1
    && (string)$db->query('SELECT depo FROM frugro_siparisler')->fetchColumn() === 'DEPO1' && (string)$db->query('SELECT dosya_sha FROM frugro_siparisler')->fetchColumn() === hash('sha256', $FIX));
ok('satırda PO no', ($r['son']['satirlar'][0]['po'][0]['po'] ?? '') === '26-1622-44080', json_encode($r['son']['satirlar'][0] ?? null));
ok('sayaç: 2 mail, 2 Excel ek, 1 alındı, 1 zaten', $r['son']['sayac']['mail'] === 2 && $r['son']['sayac']['ek'] === 2 && $r['son']['sayac']['alindi'] === 1 && $r['son']['sayac']['zaten'] === 1, json_encode($r['son']['sayac']));
ok('dosya KALICI diske kaydedilmedi (temp artığı yok)', glob(sys_get_temp_dir() . '/frgm*') === []);
ok('defter frugro_mail_ek yazıldı (adres/konu/dosya adı YOK, PO var)', ($ds = $db->query("SELECT new_values FROM audit_log WHERE action = 'frugro_mail_ek'")->fetchAll(PDO::FETCH_COLUMN)) && count($ds) === 2
    && !str_contains(implode('', $ds), '@') && !str_contains(implode('', $ds), 'Pomegranates') && str_contains($ds[0], '26-1622-44080'), json_encode($ds));
smt_sayac_sifirla();
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
ok('2. tarama: defterden onceden, IMAP bağlantısı 0, kayıt çoğalmadı', frgs_durumlar($r) === ['onceden', 'onceden'] && $SMT_BAGLANTI === 0 && $denetle('SELECT COUNT(*) FROM frugro_siparisler') === 1, json_encode(frgs_durumlar($r)) . " bağlantı:$SMT_BAGLANTI");

// Geri Gönderim: aynı PO, FARKLI içerik (durum yeni → alanlar güncellenir; ilerlemişse fark olarak yazılır)
$farkli = frgs_varyant($FIX, function ($x) {
    $ws = $x->getSheetByName('Order Log') ?? $x->getSheet(0);
    foreach ($ws->toArray(null, false, false, true) as $no => $row) foreach ($row as $kol => $v) if (is_string($v) && preg_match('/^FELIXSTOWE PORT$/', $v)) $ws->setCellValue($kol . $no, 'LONDON GATEWAY');
});
$m5 = smt_mail($A, $MAIL[$A], $GON2, [frgs_ek('2', $ad, $farkli)], '2026-11-11 08:00:00', ['subject' => 'Geri Gönderim: ' . $konu]);
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
$s5 = array_values(array_filter($r['son']['satirlar'], fn($s) => $s['m'] === $m5))[0] ?? [];
ok('aynı PO farklı içerik, durum yeni → alındı (güncellendi), kayıt SAYISI hâlâ 1', ($s5['durum'] ?? '') === 'alindi' && ($s5['po'][0]['durum'] ?? '') === 'guncellendi' && $denetle('SELECT COUNT(*) FROM frugro_siparisler') === 1, json_encode($s5));
ok('liman güncellendi', (string)$db->query('SELECT giris_limani FROM frugro_siparisler')->fetchColumn() === 'LONDON GATEWAY');
$db->exec("UPDATE frugro_siparisler SET durum = 'pkl_hazir'");
$farkli2 = frgs_varyant($FIX, function ($x) {
    $ws = $x->getSheetByName('Order Log') ?? $x->getSheet(0);
    foreach ($ws->toArray(null, false, false, true) as $no => $row) foreach ($row as $kol => $v) if (is_string($v) && preg_match('/^FELIXSTOWE PORT$/', $v)) $ws->setCellValue($kol . $no, 'TILBURY');
});
$m6 = smt_mail($A, $MAIL[$A], $GON2, [frgs_ek('2', $ad, $farkli2)], '2026-11-11 09:00:00', ['subject' => 'Geri Gönderim 2: ' . $konu]);
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
$s6 = array_values(array_filter($r['son']['satirlar'], fn($s) => $s['m'] === $m6))[0] ?? [];
ok('durum ilerlemişken farklı içerik → fark (alanlar EZİLMEZ), olay yazıldı', ($s6['durum'] ?? '') === 'fark' && (string)$db->query('SELECT giris_limani FROM frugro_siparisler')->fetchColumn() === 'LONDON GATEWAY'
    && $denetle("SELECT COUNT(*) FROM frugro_olaylar WHERE tur = 'fark'") === 1, json_encode($s6));
ok('fark sayacı + audit tara kaydı yalnız sayı', $r['son']['sayac']['fark'] === 1 && ($ta = $db->query("SELECT new_values FROM audit_log WHERE action = 'frugro_mail_tara' ORDER BY id DESC LIMIT 1")->fetchColumn()) && !str_contains((string)$ta, '@'));

echo "\n=== D. Geçersiz ekler + hatalar ===\n";
$db->exec('DELETE FROM mail_messages'); $db->exec("DELETE FROM audit_log WHERE action = 'frugro_mail_ek'");
$sayi0 = $denetle('SELECT COUNT(*) FROM frugro_siparisler');
$dA = smt_mail($A, $MAIL[$A], $GON, [frgs_ek('2', 'baska.xlsx', frgs_bos_xlsx('Sheet1', '26-1999-11111'))], '2026-11-11 10:00:00');          // Order Log sayfası yok
$dB = smt_mail($A, $MAIL[$A], $GON, [frgs_ek('2', 'polu.xlsx', frgs_bos_xlsx('Order Log', 'PO-YOK'))], '2026-11-11 10:01:00');             // PO deseni yok
$dC = smt_mail($A, $MAIL[$A], $GON, [frgs_ek('2', 'sahte.xlsx', 'Bu bir xlsx degil')], '2026-11-11 10:02:00');                           // xlsx değil
$dD = smt_mail($A, $MAIL[$A], $GON, [smt_ek('2', 'rapor.pdf', smt_pdf('po9'), 'application/pdf')], '2026-11-11 10:03:00');               // yalnız PDF
$dE = smt_mail($A, $MAIL[$A], $GON, [frgs_ek('2', 'yok.xlsx', $FIX)], '2026-11-11 10:04:00');
unset(smt_srv($MAIL[$A])->mesajlar[(int)$db->query("SELECT uid FROM mail_messages WHERE id = $dE")->fetchColumn()]);                      // sunucudan silinmiş
smt_sayac_sifirla();
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
$st = array_column($r['son']['satirlar'], 'durum', 'm');
ok("'Order Log' sayfası olmayan Excel → gecersiz", ($st[$dA] ?? '') === 'gecersiz', json_encode($st));
ok('PO deseni olmayan Order Log → gecersiz', ($st[$dB] ?? '') === 'gecersiz');
ok('xlsx olmayan içerik → gecersiz', ($st[$dC] ?? '') === 'gecersiz');
ok('tanınmayan ekler için sipariş kaydı AÇILMADI', $denetle('SELECT COUNT(*) FROM frugro_siparisler') === $sayi0);
ok('yalnız PDF eki olan mail Excel sayılmaz (satır yok, eksiz sayacı)', !isset($st[$dD]) && (int)$r['son']['eksiz'] === 1);
ok('sunucuda olmayan ek → ek_yok (hata DEĞİL)', ($st[$dE] ?? '') === 'ek_yok');
ok('defter: gecersiz kararı yazıldı', (frgm_defter($db, [$dA])[$dA]['2']['sonuc'] ?? '') === 'gecersiz');
smt_sayac_sifirla();
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
ok('2. tarama: gecersiz/ek_yok tekrar indirilmez (IMAP 0)', $SMT_BAGLANTI === 0 && in_array('gecersiz', frgs_durumlar($r), true) && in_array('ek_yok', frgs_durumlar($r), true), json_encode(frgs_durumlar($r)) . " bağlantı:$SMT_BAGLANTI");
// geçici hata (kimlik) → defter YOK, sonra alınır
$dH = smt_mail($A, $MAIL[$A], $GON, [frgs_ek('2', 'yeni.xlsx', frgs_varyant($FIX, function ($x) {
    $ws = $x->getSheetByName('Order Log') ?? $x->getSheet(0);
    foreach ($ws->toArray(null, false, false, true) as $no => $row) foreach ($row as $kol => $v) if (is_string($v) && $v === '26-1622-44080') $ws->setCellValue($kol . $no, '26-1700-44100');
}))], '2026-11-11 12:00:00');
smt_srv($MAIL[$A])->cfg['pass'] = 'YANLIS';
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
$sh = array_values(array_filter($r['son']['satirlar'], fn($s) => $s['m'] === $dH))[0] ?? [];
ok('kimlik hatası → hata, parola SIZMAZ, defter YOK', ($sh['durum'] ?? '') === 'hata' && !str_contains(json_encode($sh), 'YANLIS') && !str_contains(json_encode($sh), 'imap-parola-ABC') && frgm_defter($db, [$dH]) === []);
smt_srv($MAIL[$A])->cfg['pass'] = 'imap-parola-ABC';
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
ok('sorun giderilince yeniden denendi ve yeni PO alındı (2. kayıt)', in_array('alindi', frgs_durumlar($r), true) && $denetle('SELECT COUNT(*) FROM frugro_siparisler') === 2);
$db->exec("UPDATE frugro_siparisler SET deleted_at = '2026-11-01 00:00:00' WHERE po_no = '26-1700-44100'");
$db->exec("DELETE FROM audit_log WHERE action = 'frugro_mail_ek' AND record_id = $dH");
$r = frgs_tara(1, 'DEPO1', ['simdi' => $SIMDI]);
ok('silinmiş PO geri getirilmez (silinmis)', in_array('silinmis', frgs_durumlar($r), true) && $denetle("SELECT COUNT(*) FROM frugro_siparisler WHERE deleted_at IS NULL") === 1, json_encode(frgs_durumlar($r)));

echo "\n=== E. Parçalı yürütme + kilit ===\n";
$db->exec('DELETE FROM mail_messages'); $db->exec("DELETE FROM audit_log WHERE action = 'frugro_mail_ek'");
for ($i = 1; $i <= 8; $i++) {
    $v = frgs_varyant($FIX, function ($x) use ($i) {
        $ws = $x->getSheetByName('Order Log') ?? $x->getSheet(0);
        foreach ($ws->toArray(null, false, false, true) as $no => $row) foreach ($row as $kol => $c) if (is_string($c) && $c === '26-1622-44080') $ws->setCellValue($kol . $no, sprintf('26-18%02d-44%03d', $i, $i));
    });
    smt_mail($A, $MAIL[$A], $GON, [frgs_ek('2', "po$i.xlsx", $v)], '2026-11-12 08:0' . $i . ':00');
}
$r = frgm_baslat($db, 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('baslat: 8 ek, i=0, bitti değil', $r['ok'] && $r['toplam'] === 8 && $r['i'] === 0 && $r['bitti'] === false, json_encode($r));
ok('OGL kilidi tutulurken Frugro sorgusu ÇALIŞIR (ayrı kilit)', ($fh = mail_kilit_al('siparis_mail')) !== null && ($rk = frgm_adim($db, (string)$r['tarama_id'], 1, 'DEPO1', ['istemci' => smt_fabrika()])) && $rk['ok'] && $rk['i'] === 6 && !mail_kilit_birak($fh), json_encode($rk ?? null));
ok('yarım kayıt: bu kullanıcı için var, başkası için yok', ($y = frgm_yarim($db, 1)) !== null && $y['i'] === 6 && frgm_yarim($db, 2) === null);
$fh = mail_kilit_al('frugro_mail');
$rm = frgm_adim($db, (string)$r['tarama_id'], 1, 'DEPO1', ['istemci' => smt_fabrika()]);
mail_kilit_birak($fh);
ok('Frugro kilidi tutuluyken → 409 mesgul', !$rm['ok'] && $rm['http'] === 409 && $rm['kod'] === 'mesgul');
ok('başka kullanıcı devam EDEMEZ (409); depo değişince 409', frgm_adim($db, (string)$r['tarama_id'], 2, 'DEPO1')['http'] === 409 && frgm_adim($db, (string)$r['tarama_id'], 1, 'DEPO2')['kod'] === 'depo');
$r2 = frgm_adim($db, (string)$r['tarama_id'], 1, 'DEPO1', ['istemci' => smt_fabrika()]);
ok('2. adım tamamlar: bitti, kayıt silinir, son saklanır, 8 PO alındı', $r2['ok'] && $r2['bitti'] && siparis_mail_kv_oku($db, FRGM_KV_TARAMA) === null && frgm_son($db)['durum'] === 'bitti' && $r2['son']['sayac']['alindi'] === 8, json_encode($r2['son']['sayac'] ?? $r2));
$ra = frgm_baslat($db, 1, 'DEPO1', ['simdi' => '2026-11-14 12:00:00']);
ok('hepsi alınmış → plan boş, anında biter (onceden 8)', $ra['ok'] && $ra['bitti'] && $ra['son']['sayac']['onceden'] === 8, json_encode($ra['son']['sayac'] ?? $ra));
// vazgeç + başkasının kirası
$db->exec("DELETE FROM audit_log WHERE action = 'frugro_mail_ek'");
$rv = frgm_baslat($db, 1, 'DEPO1', ['simdi' => $SIMDI]);
$rb = frgm_baslat($db, 2, 'DEPO1', ['simdi' => $SIMDI]);
ok('başka kullanıcının taraması sürerken (kira) → 409 mesgul', $rv['ok'] && !$rv['bitti'] && !$rb['ok'] && $rb['kod'] === 'mesgul');
$rz = frgm_vazgec($db, (string)$rv['tarama_id'], 1);
ok('vazgeç: sonuç saklanır, durum vazgecildi', $rz['ok'] && $rz['bitti'] && frgm_son($db)['durum'] === 'vazgecildi' && frgm_yarim($db, 1) === null);

echo "\n=== F. Render + partial ===\n";
$son = ['tarama_id' => 'x', 'uid' => 1, 'depo' => 'DEPO1', 'tur' => 'frugro', 'basla' => 1, 'bitis' => 1, 'durum' => 'bitti', 'sayac' => frgm_sayac_bos(), 'eksiz' => 0, 'uyarilar' => ['<b>uyarı</b>'],
        'satirlar' => [frgm_satir(['m' => $db->query('SELECT id FROM mail_messages LIMIT 1')->fetchColumn(), 'p' => '2', 'dosya' => '<img src=x onerror=alert(1)>.xlsx'], 'hata', ['hata' => '<script>x</script>', 'po' => [['po' => '<i>26</i>', 'durum' => 'olustu']]])]];
$db->exec("UPDATE mail_messages SET subject = '<script>alert(1)</script>' WHERE id = " . (int)$son['satirlar'][0]['m']);
$html = frgm_sonuc_html($db, $son, [$A]);
ok('sonuç HTML kullanıcı verisini kaçırır (konu, dosya adı, hata, PO, uyarı)', !str_contains($html, '<script') && !str_contains($html, '<img') && !str_contains($html, '<b>uyarı') && !str_contains($html, '<i>26') && str_contains($html, '&lt;script&gt;'), $html);
ok('görünmeyen hesap → gönderen/konu GÖSTERİLMEZ', !str_contains(frgm_sonuc_html($db, $son, []), '&lt;script&gt;alert(1)&lt;/script&gt;<br>') && !str_contains(frgm_sonuc_html($db, $son, []), $GON));
$kv = frgm_kart_veri($db, 1);
ok('frgm_kart_veri: yetkili+hazır+ayarlı → goster, csrf, url', $kv['goster'] && $kv['hazir'] && $kv['ayarli'] && $kv['csrf'] !== '' && $kv['url'] === 'frugro_mail.php' && $kv['ayar_yetkisi']);
$IS_ADMIN = false; $PERMS = ['siparis.read'];
ok('yetkisiz → goster=false', frgm_kart_veri($db, 2)['goster'] === false);
$IS_ADMIN = true; $PERMS = [];
$ciz = function (array $veri) use ($ROOT): string { $frgMailVeri = $veri; ob_start(); include $ROOT . '/_frugro_mail_kart.php'; return (string)ob_get_clean(); };
ok('partial: veri yok / goster=false → boş çıktı', $ciz(['goster' => false]) === '');
$pk = $ciz(array_merge($kv, ['yarim' => ['tarama_id' => str_repeat('a', 16), 'i' => 3, 'toplam' => 8, 'depo' => 'DEPO1']]));
ok('partial: düğme + ilerleme + hata + sonuç + devam şeridi + css/js', str_contains($pk, 'data-frgm-baslat') && str_contains($pk, 'data-frgm-ilerleme') && str_contains($pk, 'data-frgm-hata') && str_contains($pk, 'data-frgm-sonuc')
    && str_contains($pk, 'data-frgm-devam="' . str_repeat('a', 16) . '"') && str_contains($pk, 'assets/frugro_mail.css') && str_contains($pk, 'assets/frugro_mail.js'));
ok('partial: satır içi olay YOK', !preg_match('/\son[a-z]+\s*=/i', $pk));
$pa = $ciz(['goster' => true, 'hazir' => true, 'ayarli' => false, 'ayar_yetkisi' => true, 'csrf' => 'x', 'url' => 'frugro_mail.php', 'ayar_url' => 'frugro_ayarlar.php', 'son_html' => '', 'yarim' => null, 'uyari' => 'Gönderen ayarlanmadı.']);
ok('partial: ayarsızken düğme YOK, ayar bağlantısı var', !str_contains($pa, 'data-frgm-baslat') && str_contains($pa, 'frugro_ayarlar.php') && str_contains($pa, 'Gönderen ayarlanmadı.'));
$pk2 = $ciz(array_merge($kv, ['son_html' => '<p>X</p>', 'url' => '"><x', 'csrf' => '"onfocus="a']));
ok('partial: öznitelik değerleri kaçırılır', !str_contains($pk2, '"><x') && !str_contains($pk2, '"onfocus="a'));

echo "\n=== G. Statik değişmezler ===\n";
$dosyalar = ['config/frugro_mail.php', 'frugro_mail.php', 'frugro_ayarlar.php', '_frugro_mail_kart.php', 'assets/frugro_mail.js', 'assets/frugro_mail.css', 'scripts/frugro_mail_smoke.php'];
$k = []; foreach ($dosyalar as $f) $k[$f] = (string)file_get_contents($ROOT . '/' . $f);
$uc = $k['frugro_mail.php'];
ok('uç: POST kapısı + csrf_check mutasyondan ÖNCE + require_siparis(write) + frgm_yetkisi', strpos($uc, 'csrf_check(') !== false && strpos($uc, 'csrf_check(') < strpos($uc, 'frgm_baslat(') && str_contains($uc, "require_siparis('write')") && str_contains($uc, 'frgm_yetkisi()') && str_contains($uc, "'POST'"));
ok('uç: session_write_close + no-store + JSON başlığı', str_contains($uc, 'session_write_close()') && str_contains($uc, 'mail_no_store()') && str_contains($uc, 'application/json'));
ok('uç adı TEK alt çizgili (frugro_mail.php) ve sw.js siparis regex\'ine girmez/muaf değil', file_exists($ROOT . '/frugro_mail.php') && !file_exists($ROOT . '/frugro_mail_tara.php'));
ok('ayarlar sayfası: kapı frgm_ayar_yetkisi + csrf_check + require_siparis(delete)', str_contains($k['frugro_ayarlar.php'], 'frgm_ayar_yetkisi()') && str_contains($k['frugro_ayarlar.php'], 'csrf_check(') && str_contains($k['frugro_ayarlar.php'], "require_siparis('delete')"));
ok('çekirdek: IMAP istemci metodu çağrılmaz (yalnız mail_ek_hazirla), posta kutusuna YAZMA yok', !preg_match('/->(klasorAc|uidParca|uidAra|bayrakKoy|kopyala|sil|expunge|store)\s*\(/i', $k['config/frugro_mail.php']) && str_contains($k['config/frugro_mail.php'], 'mail_ek_hazirla('));
ok('çekirdek: yeni yazma yolu YOK (move_uploaded_file / dosya kaydı / siparis_dosya_*)', !preg_match('/move_uploaded_file|copy\s*\(|siparis_dosya_yukle_isle|siparis_dosya_kaydet|INSERT INTO frugro_/', $k['config/frugro_mail.php']) && preg_match_all('/file_put_contents\(\s*([^,]+),/', $k['config/frugro_mail.php'], $fp) === 1 && trim($fp[1][0]) === '$tmp' && str_contains($k['config/frugro_mail.php'], 'frg_toplu_kaydet('));
ok('çekirdek: aday sorgusu OGL altyapısından (gönderilenler dışlaması orada)', str_contains($k['config/frugro_mail.php'], 'siparis_mail_adaylar('));
ok('JS: satır içi olay / eval / document.write YOK, innerHTML yalnız sonuç alanı', !preg_match('/onclick|eval\(|document\.write|insertAdjacentHTML/', $k['assets/frugro_mail.js']) && preg_match_all('/\.innerHTML\s*=/', $k['assets/frugro_mail.js']) === 1);
$mailRe = '/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,}/';
$yabanci = [];
foreach ($k as $f => $icerik) if (preg_match_all($mailRe, $icerik, $mm)) foreach ($mm[0] as $adr) if (!preg_match('/@ornek\.test$/i', $adr)) $yabanci[] = "$f:$adr";
ok('e-posta literali yalnız @ornek.test', $yabanci === [], implode(', ', $yabanci));

siparis_test_bitir();
