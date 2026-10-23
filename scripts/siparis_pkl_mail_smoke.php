<?php
// =========================================================
// scripts/siparis_pkl_mail_smoke.php — PKL mail taslağı (v383): Kime/Bilgi ayrımı, mailto, ekli .eml (X-Unsent), uç
//   + v411 evrak takımı mail taslağı (ek YOK): taslak kuralları, hks_kv ayarı, .eml, siparis_eml.php ucu, ekran
//   + v422 düzenlenebilir mail metni + PKL mail ayarları (ek Kime/Bilgi, metin şablonları, ?govde=)
//   php scripts/siparis_pkl_mail_smoke.php
// Bellek içi SQLite + stub auth + SENTETİK veri (@ornek.test; kişisel veri YOK). Canlı DB'ye / storage'a dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/_siparis_ortak.php';

$db = db();
siparis_test_diger_tablolar($db);
siparis_test_sema_kur($db);

echo "=== 1. Taslak kuralları (saf) ===\n";
$t = siparis_pkl_mail_taslak(['log@ornek.test', 'packinglist-FS-EU@ornek.test'], 'PKL-TC900001.10-AsyaDoc005-17.10.2026.xlsx', 'TC900001.10', 'Kullanıcı 1');
ok('packinglist… adresi Bilgi (CC), diğeri Kime', $t['kime'] === ['log@ornek.test'] && $t['bilgi'] === ['packinglist-FS-EU@ornek.test'], json_encode($t));
ok('konu = PKL dosya adı uzantısız', $t['konu'] === 'PKL-TC900001.10-AsyaDoc005-17.10.2026');
ok('gövde: kalıp + Asya Fresh + gönderenin adı', $t['govde'] === "Hi,\nPlease find attached the packing list.\n\nBest regards,\nAsya Fresh\nKullanıcı 1", $t['govde']);
$t2 = siparis_pkl_mail_taslak(['PackingList-x@ornek.test'], '', 'TC900002.10', '');
ok('yalnız packinglist adresi varsa Kime\'ye geçer; PKL yoksa konu = order ref; adsız imza', $t2['kime'] === ['PackingList-x@ornek.test'] && $t2['bilgi'] === [] && $t2['konu'] === 'TC900002.10'
    && str_ends_with($t2['govde'], "Best regards,\nAsya Fresh"), json_encode($t2));
$t3 = siparis_pkl_mail_taslak(['a@ornek.test', 'A@ORNEK.TEST', 'gecersiz', '', 'b@ornek.test'], "X\r\nBcc: kotu@ornek.test.xlsx", 'TC9', "Ad\r\nBcc: k@ornek.test");
ok('tekrarlanan (büyük/küçük harf) ve geçersiz adres atılır', $t3['kime'] === ['a@ornek.test', 'b@ornek.test'], json_encode($t3['kime']));
ok('konu/imzada satır sonu YOK (başlık enjeksiyonu)', !preg_match('/[\r\n]/', $t3['konu']) && !str_contains($t3['govde'], "\r") && substr_count($t3['govde'], "\n") === 5, json_encode($t3));
ok('adres yoksa Kime boş', siparis_pkl_mail_taslak([], 'a.xlsx', 'TC', 'x')['kime'] === []);

echo "\n=== 2. mailto ===\n";
$m = siparis_pkl_mailto($t);
ok('mailto: Kime yolda, Bilgi cc=, konu ve gövde kodlu', str_starts_with($m, 'mailto:log@ornek.test?cc=packinglist-FS-EU@ornek.test&subject=PKL-TC900001.10-AsyaDoc005-17.10.2026&body='), $m);
ok('mailto gövdesi CRLF ile (%0D%0A) ve Türkçe karakter kodlu', str_contains($m, 'Hi%2C%0D%0APlease') && str_contains($m, rawurlencode('Kullanıcı')), $m);
ok('cc yoksa cc parametresi yok', !str_contains(siparis_pkl_mailto($t2), 'cc='));

echo "\n=== 3. .eml (X-Unsent) ===\n";
$ek = (string)file_get_contents($ROOT . '/templates/excel/pkl_v14_blank.xlsx');
$eml = siparis_pkl_eml($t, 'PKL-TC900001.10-AsyaDoc005-17.10.2026.xlsx', $ek, 1760000000);
[$bas, $govde] = explode("\r\n\r\n", $eml, 2);
ok('ilk başlık X-Unsent: 1 (Outlook taslak olarak açar)', str_starts_with($eml, "X-Unsent: 1\r\n"));
ok('To / Cc / Subject / MIME başlıkları; From YOK', str_contains($bas, "\r\nTo: log@ornek.test\r\n") && str_contains($bas, "\r\nCc: packinglist-FS-EU@ornek.test\r\n")
    && str_contains($bas, "\r\nSubject: PKL-TC900001.10-AsyaDoc005-17.10.2026\r\n") && str_contains($bas, 'multipart/mixed') && !preg_match('/^From:/mi', $bas));
preg_match('/boundary="([^"]+)"/', $bas, $bm);
$parcalar = explode('--' . ($bm[1] ?? 'YOK'), $govde);
ok('iki parça + kapanış sınırı', count($parcalar) === 4 && str_starts_with($parcalar[3], '--'), (string)count($parcalar));
[$h1, $g1] = explode("\r\n\r\n", ltrim($parcalar[1], "\r\n"), 2);
ok('gövde parçası UTF-8 metin, base64 çözülünce kalıp (CRLF)', str_contains($h1, 'text/plain; charset=UTF-8') && base64_decode(preg_replace('/\s+/', '', $g1)) === str_replace("\n", "\r\n", $t['govde']));
[$h2, $g2] = explode("\r\n\r\n", ltrim($parcalar[2], "\r\n"), 2);
ok('ek parçası xlsx, dosya adıyla, baytlar birebir', str_contains($h2, 'spreadsheetml.sheet') && str_contains($h2, 'attachment; filename="PKL-TC900001.10-AsyaDoc005-17.10.2026.xlsx"')
    && base64_decode(preg_replace('/\s+/', '', $g2)) === $ek);
ok('satırlar ≤ 998 (base64 76 karakter satır)', max(array_map('strlen', explode("\r\n", $eml))) <= 998);
$emlTr = siparis_pkl_eml(['kime' => ['a@ornek.test'], 'bilgi' => [], 'konu' => 'Çeki listesi', 'govde' => 'x'], 'Çeki.xlsx', 'abc');
ok('ASCII dışı konu RFC 2047, dosya adı RFC 2231', str_contains($emlTr, 'Subject: =?UTF-8?B?' . base64_encode('Çeki listesi') . '?=') && str_contains($emlTr, "filename*=UTF-8''" . rawurlencode('Çeki.xlsx')));

echo "\n=== 4. Uç (siparis_dosya.php?eml=1) ===\n";
class SipExit extends RuntimeException {}
$SIPT = ['h' => [], 'kod' => 200];
function sipt_header(string $h, bool $replace = true, int $code = 0): void
{
    global $SIPT;
    if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $SIPT['h'][strtolower(trim($a))] = trim($b); }
    if ($code > 0) $SIPT['kod'] = $code;
}
function sipt_code(?int $c = null) { global $SIPT; if ($c !== null) $SIPT['kod'] = $c; return $SIPT['kod']; }
function sip_calistir(string $tmp): void { include $tmp; }
function uc(array $get, string $dosya = 'siparis_dosya.php', string $yontem = 'GET'): array
{
    global $ROOT, $SIPT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sipt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sipt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SipExit();', $src);
    $src = str_replace('readfile($yol);', 'echo file_get_contents($yol);', $src);
    $tmp = sys_get_temp_dir() . '/sip_eml_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    $SIPT = ['h' => [], 'kod' => 200];
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = $yontem;
    ob_start();
    $hata = null;
    try { sip_calistir($tmp); } catch (SipExit $e) {} catch (Throwable $e) { $hata = $e->getMessage(); }
    $govde = (string)ob_get_clean();
    @unlink($tmp);
    return ['govde' => $govde, 'kod' => $SIPT['kod'], 'h' => $SIPT['h'], 'hata' => $hata];
}
$PERMS = ['siparis.read']; $IS_ADMIN = false; $UID = 1;
// PO belgesi (parse_json'da packing list adresleri) + sevkiyat + PKL dosyası (gerçek boş şablon baytları)
$po = siparis_dosya_kaydet($db, siparis_test_dosya(siparis_test_pdf('po-mail'), 'po.pdf'), 1, 'DEPO1', siparis_test_mover());
$db->prepare('UPDATE siparis_belgeler SET tur = ?, parse_json = ? WHERE id = ?')->execute(['po', json_encode(['packing_list_emails' => ['log@ornek.test', 'packinglist-FS-EU@ornek.test']]), $po['belge_id']]);
$db->prepare("INSERT INTO siparis_sevkiyatlar (order_ref, tedarikci_no, durum, depo, po_belge_id, var_pkl) VALUES ('TC900001.10', '', 'pkl_hazir', 'DEPO1', ?, 1)")->execute([$po['belge_id']]);
$sid = (int)$db->lastInsertId();
$pk = siparis_dosya_kaydet($db, siparis_test_dosya_yol($ROOT . '/templates/excel/pkl_v14_blank.xlsx', 'PKL-TC900001.10-AsyaDoc005-17.10.2026.xlsx'), 1, 'DEPO1', siparis_test_mover());
ok('PKL test dosyası kaydedildi', !empty($pk['ok']), json_encode($pk));
$db->prepare("INSERT INTO siparis_pkl (sevkiyat_id, durum, dosya_belge_id, dosya_adi, sezon) VALUES (?, 'uretildi', ?, ?, '2026')")->execute([$sid, $pk['belge_id'], 'PKL-TC900001.10-AsyaDoc005-17.10.2026.xlsx']);
$r = uc(['id' => $pk['belge_id'], 'eml' => '1', 'sid' => $sid]);
ok('uç: message/rfc822 indirme, .eml adı, CSP sandbox', $r['hata'] === null && ($r['h']['content-type'] ?? '') === 'message/rfc822'
    && str_contains($r['h']['content-disposition'] ?? '', 'attachment; filename="PKL-TC900001.10-AsyaDoc005-17.10.2026.eml"') && str_contains($r['h']['content-security-policy'] ?? '', 'sandbox'), json_encode([$r['hata'], $r['h']]));
$govdeUc = '';
if (preg_match('/boundary="([^"]+)"/', $r['govde'], $bm2)) {
    $p1 = explode('--' . $bm2[1], $r['govde'])[1] ?? '';
    $govdeUc = (string)base64_decode((string)preg_replace('/\s+/', '', explode("\r\n\r\n", ltrim($p1, "\r\n"), 2)[1] ?? ''));
}
ok('uç: X-Unsent taslak, Kime/Bilgi PO adreslerinden, imza = oturumdaki kullanıcı', str_starts_with($r['govde'], 'X-Unsent: 1') && str_contains($r['govde'], "To: log@ornek.test\r\n")
    && str_contains($r['govde'], "Cc: packinglist-FS-EU@ornek.test\r\n") && str_ends_with($govdeUc, "Asya Fresh\r\nKullanıcı 1"), $govdeUc);
$aud = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_pkl_eml'")->fetchColumn();
ok('uç: audit siparis_pkl_eml (adres YAZILMAZ, yalnız sayı)', $aud && str_contains((string)$aud, '"alici":2') && !str_contains((string)$aud, '@'), (string)$aud);
$r = uc(['id' => $po['belge_id'], 'eml' => '1', 'sid' => $sid]);
ok('uç: sevkiyatın PKL\'i olmayan belge (PO) ile taslak KURULMAZ (404)', $r['kod'] === 404 && !str_contains($r['govde'], 'X-Unsent'), $r['kod'] . ' ' . $r['govde']);
$r = uc(['id' => $pk['belge_id'], 'eml' => '1', 'sid' => 999999]);
ok('uç: olmayan sevkiyat 404', $r['kod'] === 404);
$DEPOLAR = ['DEPO2'];
$db->exec("UPDATE siparis_sevkiyatlar SET depo = 'DEPO1' WHERE id = $sid");
$db->prepare("UPDATE siparis_belgeler SET depo = '' WHERE id = ?")->execute([$pk['belge_id']]);
$r = uc(['id' => $pk['belge_id'], 'eml' => '1', 'sid' => $sid]);
ok('uç: başka depodaki sevkiyatın taslağı verilmez (forbidden)', $r['hata'] !== null && str_contains($r['hata'], 'forbidden') && !str_contains($r['govde'], 'X-Unsent'), (string)$r['hata']);
$DEPOLAR = null;
$PERMS = [];
$r = uc(['id' => $pk['belge_id'], 'eml' => '1', 'sid' => $sid]);
ok('uç: siparis.read yoksa açılmaz', $r['hata'] !== null && !str_contains($r['govde'], 'X-Unsent'));
$PERMS = ['siparis.read'];
$db->prepare('UPDATE siparis_belgeler SET parse_json = ? WHERE id = ?')->execute([json_encode(['packing_list_emails' => []]), $po['belge_id']]);
$r = uc(['id' => $pk['belge_id'], 'eml' => '1', 'sid' => $sid]);
ok("uç: PO'da adres yoksa 409", $r['kod'] === 409, (string)$r['kod']);
$r = uc(['id' => $pk['belge_id'], 'dl' => '1']);
ok('uç: eml parametresiz eski indirme aynen (xlsx baytları)', $r['hata'] === null && $r['govde'] === $ek && str_contains($r['h']['content-type'] ?? '', 'spreadsheetml'));

echo "\n=== 5. Ekran (siparis_view.php kaynağı) ===\n";
$v = (string)file_get_contents($ROOT . '/siparis_view.php');
ok('modal: mailto ve .eml AYNI taslaktan (siparis_pkl_mail_taslak + siparis_pkl_mailto)', str_contains($v, 'siparis_pkl_mail_taslak(') && str_contains($v, 'siparis_pkl_mailto($mailTaslak)'));
ok('modal: ekli taslak bağlantısı eml=1&sid', str_contains($v, '&amp;eml=1&amp;sid=<?= $id ?>'));
ok('modal: eski "tüm adresler Kime" mailto kalıbı YOK', !str_contains($v, "mailto:<?= h(implode(',', \$poEpostalar))"));

echo "\n=== 6. v411 evrak taslağı (saf) ===\n";
$po2 = ['log@ornek.test', 'packinglist-FS-EU@ornek.test'];
$e = siparis_evrak_mail_taslak(['customs@ornek.test'], $po2, 'PKL-TC900001.10-AsyaDoc005-17.10.2026.xlsx', 'TC900001.10', 'Kullanıcı 1');
ok('Kime = ayar önce + PO packinglist OLMAYAN; Bilgi boş', $e['kime'] === ['customs@ornek.test', 'log@ornek.test'] && $e['bilgi'] === [], json_encode($e));
ok('konu PKL taslağının konusuyla AYNI', $e['konu'] === siparis_pkl_mail_taslak($po2, 'PKL-TC900001.10-AsyaDoc005-17.10.2026.xlsx', 'TC900001.10', 'x')['konu'] && $e['konu'] === 'PKL-TC900001.10-AsyaDoc005-17.10.2026');
ok('gövde birebir (DOCs + Best Regards + Asya Fresh + ad)', $e['govde'] === "Hi,\nDOCs are attached. Thank you.\n\nBest Regards,\n\nAsya Fresh\nKullanıcı 1", $e['govde']);
$e2 = siparis_evrak_mail_taslak([], ['PackingList-x@ornek.test'], '', 'TC900002.10', '');
ok('yalnız packinglist adresi varsa Kime BOŞ (PKL kuralının aksine Kime\'ye geçmez); PKL yoksa konu = order ref; adsız imza Asya Fresh ile biter',
    $e2['kime'] === [] && $e2['bilgi'] === [] && $e2['konu'] === 'TC900002.10' && str_ends_with($e2['govde'], "Best Regards,\n\nAsya Fresh"), json_encode($e2));
$e3 = siparis_evrak_mail_taslak(['Customs@ornek.test', 'gecersiz', ''], ['CUSTOMS@ORNEK.TEST', 'log@ornek.test', 'LOG@ornek.test', 'packinglist@ornek.test'], "X\r\nBcc: k@ornek.test.xlsx", 'TC9', "Ad\r\nBcc: k@ornek.test");
ok('büyük/küçük harf duyarsız tekil, geçersiz atlanır, ayarın yazımı korunur', $e3['kime'] === ['Customs@ornek.test', 'log@ornek.test'], json_encode($e3['kime']));
ok('konu/imzada satır sonu YOK (başlık enjeksiyonu)', !preg_match('/[\r\n]/', $e3['konu']) && !str_contains($e3['govde'], "\r") && substr_count($e3['govde'], "\n") === 6, json_encode($e3));
ok('ayar + PO boş → Kime boş', siparis_evrak_mail_taslak([], [], 'a.xlsx', 'TC', 'x')['kime'] === []);

echo "\n=== 7. v411 ayar (hks_kv) ===\n";
ok('hks_kv yokken: kv_hazir false, oku boş, kaydet reddedilir', !siparis_evrak_mail_kv_hazir($db) && siparis_evrak_mail_ayar_oku($db) === ['kime' => []]
    && siparis_evrak_mail_ayar_kaydet($db, 'customs@ornek.test', 1)['ok'] === false);
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
ok('hks_kv varken kv_hazir true, ayar yok → boş', siparis_evrak_mail_kv_hazir($db) && siparis_evrak_mail_ayar_oku($db) === ['kime' => []]);
$k = siparis_evrak_mail_ayar_kaydet($db, "customs@ornek.test\nLog2@Ornek.test; customs@ornek.test,  \n", 1);
ok('kaydet: satır/virgül/noktalı virgül ayrımı, küçük harf, sıra korunarak tekil', $k['ok'] && siparis_evrak_mail_ayar_oku($db)['kime'] === ['customs@ornek.test', 'log2@ornek.test'], json_encode([$k, siparis_evrak_mail_ayar_oku($db)]));
$k = siparis_evrak_mail_ayar_kaydet($db, "customs@ornek.test\ngecersiz-adres", 1);
ok('geçersiz e-posta reddedilir, eski ayar korunur', !$k['ok'] && str_contains((string)($k['hata'] ?? ''), 'Geçersiz e-posta') && siparis_evrak_mail_ayar_oku($db)['kime'] === ['customs@ornek.test', 'log2@ornek.test'], json_encode($k));
$on1 = implode("\n", array_map(fn($i) => "a$i@ornek.test", range(1, 11)));
$k = siparis_evrak_mail_ayar_kaydet($db, $on1, 1);
ok('10\'dan fazla adres reddedilir', !$k['ok'] && str_contains((string)($k['hata'] ?? ''), '10'), json_encode($k));
ok('dizi girdisi de kabul edilir', siparis_evrak_mail_ayar_kaydet($db, ['x@ornek.test'], 1)['ok'] && siparis_evrak_mail_ayar_oku($db)['kime'] === ['x@ornek.test']);
$k = siparis_evrak_mail_ayar_kaydet($db, '', 1);
ok('boş liste = temizle', $k['ok'] && siparis_evrak_mail_ayar_oku($db)['kime'] === []);
$auds = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_evrak_mail_ayar'")->fetchAll(PDO::FETCH_COLUMN);
ok('audit siparis_evrak_mail_ayar yalnız adres SAYISI (adres YAZILMAZ); başarısız kayıt audit yazmaz', count($auds) === 3 && str_contains((string)$auds[0], '"adres_sayisi":2') && !array_filter($auds, fn($a) => str_contains((string)$a, '@')), json_encode($auds));
$db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_evrak_mail_ayar', '{bozuk']);
ok('bozuk JSON → boş (fail soft)', siparis_evrak_mail_ayar_oku($db) === ['kime' => []]);

echo "\n=== 8. v411 .eml (ek YOK) + mailto ===\n";
$eml = siparis_evrak_eml($e, 1760000000);
[$bas, $gv] = explode("\r\n\r\n", $eml, 2);
ok('ilk başlık X-Unsent: 1; To iki adres; Cc / From YOK', str_starts_with($eml, "X-Unsent: 1\r\n") && str_contains($bas, "\r\nTo: customs@ornek.test, log@ornek.test\r\n") && !preg_match('/^(Cc|From):/mi', $bas));
ok('tek parça text/plain UTF-8 base64; multipart / ek / Content-Disposition YOK', str_contains($bas, "Content-Type: text/plain; charset=UTF-8") && str_contains($bas, 'Content-Transfer-Encoding: base64')
    && !str_contains($eml, 'multipart') && !str_contains($eml, 'Content-Disposition') && !str_contains($eml, 'attachment'));
ok('Subject = konu; gövde base64 çözülünce kalıp (CRLF)', str_contains($bas, "\r\nSubject: PKL-TC900001.10-AsyaDoc005-17.10.2026\r\n") && base64_decode((string)preg_replace('/\s+/', '', $gv)) === str_replace("\n", "\r\n", $e['govde']));
ok('satırlar ≤ 998', max(array_map('strlen', explode("\r\n", $eml))) <= 998);
$emlTr = siparis_evrak_eml(['kime' => [], 'bilgi' => [], 'konu' => 'Çeki evrakı', 'govde' => 'x']);
ok('Kime boşsa To başlığı yok; ASCII dışı konu RFC 2047', !preg_match('/^To:/mi', $emlTr) && str_contains($emlTr, 'Subject: =?UTF-8?B?' . base64_encode('Çeki evrakı') . '?='));
$m = siparis_pkl_mailto($e);
ok('mailto: iki Kime, cc YOK, konu + gövde kodlu', str_starts_with($m, 'mailto:customs@ornek.test,log@ornek.test?subject=PKL-TC900001.10-AsyaDoc005-17.10.2026&body=') && !str_contains($m, 'cc=') && str_contains($m, 'DOCs%20are%20attached'), $m);
ok('mailto: Kime boşken de geçerli (mailto:?subject=…)', str_starts_with(siparis_pkl_mailto($e2), 'mailto:?subject=TC900002.10&body='), siparis_pkl_mailto($e2));

echo "\n=== 9. v411 uç (siparis_eml.php) ===\n";
$db->prepare('UPDATE siparis_belgeler SET parse_json = ? WHERE id = ?')->execute([json_encode(['packing_list_emails' => $po2]), $po['belge_id']]);
siparis_evrak_mail_ayar_kaydet($db, 'customs@ornek.test', 1);
$db->exec("UPDATE siparis_sevkiyatlar SET durum = 'pkl_hazir' WHERE id = $sid");
$r = uc(['sid' => $sid], 'siparis_eml.php');
ok('uç: mail gönderilmemiş sevkiyatta 409, taslak yok', $r['kod'] === 409 && !str_contains($r['govde'], 'X-Unsent'), $r['kod'] . ' ' . $r['govde'] . ' ' . $r['hata']);
$db->exec("UPDATE siparis_sevkiyatlar SET durum = 'mail_gonderildi' WHERE id = $sid");
$audOnce = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_evrak_eml'")->fetchColumn();
$r = uc(['sid' => $sid], 'siparis_eml.php');
ok('uç: message/rfc822 indirme, .eml adı = konu, nosniff + no-store + CSP sandbox', $r['hata'] === null && ($r['h']['content-type'] ?? '') === 'message/rfc822'
    && str_contains($r['h']['content-disposition'] ?? '', 'attachment; filename="PKL-TC900001.10-AsyaDoc005-17.10.2026.eml"') && ($r['h']['x-content-type-options'] ?? '') === 'nosniff'
    && str_contains($r['h']['cache-control'] ?? '', 'no-store') && str_contains($r['h']['content-security-policy'] ?? '', 'sandbox'), json_encode([$r['hata'], $r['h'], $r['kod']]));
[$ub, $ug] = array_pad(explode("\r\n\r\n", $r['govde'], 2), 2, '');
ok('uç: X-Unsent; To = ayar + PO (packinglist YOK); Cc YOK; ek YOK; imza = oturumdaki kullanıcı', str_starts_with($r['govde'], 'X-Unsent: 1') && str_contains($ub, "To: customs@ornek.test, log@ornek.test\r\n")
    && !str_contains($r['govde'], 'packinglist') && !preg_match('/^Cc:/mi', $ub) && !str_contains($r['govde'], 'multipart')
    && str_ends_with((string)base64_decode((string)preg_replace('/\s+/', '', $ug)), "Asya Fresh\r\nKullanıcı 1"), $r['govde']);
$aud = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_evrak_eml' ORDER BY id DESC")->fetchColumn();
ok('uç: audit siparis_evrak_eml yalnız alıcı SAYISI (adres YAZILMAZ)', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_evrak_eml'")->fetchColumn() === $audOnce + 1
    && str_contains((string)$aud, '"alici":2') && !str_contains((string)$aud, '@'), (string)$aud);
$db->exec("UPDATE siparis_sevkiyatlar SET durum = 'evrak_gonderildi' WHERE id = $sid");
$r = uc(['sid' => $sid], 'siparis_eml.php');
ok('uç: evrak takımı gönderilmiş (kapalı) sevkiyatta da indirilir', $r['hata'] === null && str_starts_with($r['govde'], 'X-Unsent: 1'));
$db->exec("UPDATE siparis_sevkiyatlar SET durum = 'iptal' WHERE id = $sid");
$r = uc(['sid' => $sid], 'siparis_eml.php');
ok('uç: iptal sevkiyatta 409', $r['kod'] === 409, (string)$r['kod']);
$db->exec("UPDATE siparis_sevkiyatlar SET durum = 'mail_gonderildi' WHERE id = $sid");
$r = uc(['sid' => $sid], 'siparis_eml.php', 'POST');
ok('uç: GET dışı 405', $r['kod'] === 405 && !str_contains($r['govde'], 'X-Unsent'), (string)$r['kod']);
$r = uc(['sid' => 999999], 'siparis_eml.php');
ok('uç: olmayan sevkiyat 404', $r['kod'] === 404);
$db->prepare("INSERT INTO siparis_sevkiyatlar (order_ref, tedarikci_no, durum, depo) VALUES ('TC900003.10', '', 'mail_gonderildi', 'DEPO1')")->execute();
$sid3 = (int)$db->lastInsertId();
$r = uc(['sid' => $sid3], 'siparis_eml.php');
ok('uç: PKL / PO yoksa konu = order ref, Kime yalnız ayar', $r['hata'] === null && str_contains($r['h']['content-disposition'] ?? '', 'filename="TC900003.10.eml"') && str_contains($r['govde'], "To: customs@ornek.test\r\n"), json_encode([$r['hata'], $r['h']]));
$DEPOLAR = ['DEPO2'];
$r = uc(['sid' => $sid], 'siparis_eml.php');
ok('uç: başka depodaki sevkiyatın taslağı verilmez (forbidden)', $r['hata'] !== null && str_contains($r['hata'], 'forbidden') && !str_contains($r['govde'], 'X-Unsent'), (string)$r['hata']);
$DEPOLAR = null;
$PERMS = [];
$r = uc(['sid' => $sid], 'siparis_eml.php');
ok('uç: siparis.read yoksa açılmaz', $r['hata'] !== null && !str_contains($r['govde'], 'X-Unsent'));
$PERMS = ['siparis.read'];

echo "\n=== 10. v411 ekran (siparis_view.php / siparis_ayarlar.php kaynağı) ===\n";
$v = (string)file_get_contents($ROOT . '/siparis_view.php');
$evB = (string)substr($v, (int)strpos($v, 'id="sipEvrakModal"'));
ok('evrak penceresi: taslak siparis_evrak_mail_taslak() + ayar okuma', str_contains($v, 'siparis_evrak_mail_taslak($evrakAyar, $poEpostalar') && str_contains($v, 'siparis_evrak_mail_ayar_oku($pdo)'));
ok('evrak penceresi: Outlook taslağı (siparis_eml.php?sid) + mailto + Kopyala + ZIP korunur', str_contains($evB, 'href="siparis_eml.php?sid=<?= $id ?>"') && str_contains($evB, 'siparis_pkl_mailto($evrakTaslak)')
    && str_contains($evB, 'data-sip-kopyala') && str_contains($evB, 'siparis_zip.php?id=<?= $id ?>') && str_contains($evB, 'Taslak ek İÇERMEZ'));
ok('evrak penceresi: ayar boşsa "Customs adresi tanımlı değil" + ayar bağlantısı yalnız siparis.delete ile', str_contains($evB, 'Customs adresi tanımlı değil') && str_contains($evB, "can_siparis('delete')") && str_contains($evB, 'siparis_ayarlar.php#evrak-mail'));
$ay = (string)file_get_contents($ROOT . '/siparis_ayarlar.php');
ok('ayarlar: #evrak-mail kartı, evrak_kime alanı, islem=evrak_mail_ayar', str_contains($ay, 'id="evrak-mail"') && str_contains($ay, 'name="evrak_kime"') && str_contains($ay, 'value="evrak_mail_ayar"'));

echo "\n=== 11. v422 mail metni + PKL mail ayarları ===\n";
// saf: temizleme / şablon
ok('govde_temizle: CRLF→LF, denetim karakteri atılır, sonda boşluk kırpılır, sekme/LF korunur', siparis_mail_govde_temizle("A\r\nB\x00\x07\tC\r\n\r\n  ") === "A\nB\tC");
ok('govde_temizle: üst sınır', mb_strlen(siparis_mail_govde_temizle(str_repeat('ş', SIPARIS_MAIL_GOVDE_MAX + 50))) === SIPARIS_MAIL_GOVDE_MAX);
ok('govde_olustur: {ad} dolar, boş şablon → varsayılan, boş ad → sondaki satır kırpılır',
    siparis_mail_govde_olustur("Selam\n{ad}", 'x', 'Kullanıcı 1') === "Selam\nKullanıcı 1" && siparis_mail_govde_olustur('  ', SIPARIS_PKL_GOVDE_VARSAYILAN, '') === "Hi,\nPlease find attached the packing list.\n\nBest regards,\nAsya Fresh"
    && siparis_mail_govde_olustur("Selam\n{ad}", 'x', "Ad\r\nBcc: k@ornek.test") === "Selam\nAd Bcc: k@ornek.test");
$g = siparis_pkl_mail_taslak(['log@ornek.test'], 'a.xlsx', 'TC', 'Kullanıcı 1', ['govde' => "Merhaba,\nPKL ektedir.\n{ad}"]);
ok('PKL taslağı: ayardaki şablon kullanılır', $g['govde'] === "Merhaba,\nPKL ektedir.\nKullanıcı 1", $g['govde']);
$ev = siparis_evrak_mail_taslak([], ['log@ornek.test'], 'a.xlsx', 'TC', 'Kullanıcı 1', "Evrak ektedir.\n{ad}");
ok('evrak taslağı: ayardaki şablon kullanılır; verilmezse varsayılan', $ev['govde'] === "Evrak ektedir.\nKullanıcı 1"
    && siparis_evrak_mail_taslak([], [], 'a.xlsx', 'TC', 'K')['govde'] === "Hi,\nDOCs are attached. Thank you.\n\nBest Regards,\n\nAsya Fresh\nK");
ok('uygula: dolu düzenleme gövdeyi değiştirir; boş/null taslağı korur; satır sonları temizlenir',
    siparis_mail_govde_uygula($ev, "Yeni\r\nmetin")['govde'] === "Yeni\nmetin" && siparis_mail_govde_uygula($ev, '  ')['govde'] === $ev['govde'] && siparis_mail_govde_uygula($ev, null)['govde'] === $ev['govde']);
// saf: ek Kime / Bilgi
$a = siparis_pkl_mail_taslak(['log@ornek.test', 'packinglist-x@ornek.test'], 'a.xlsx', 'TC', 'K', ['kime' => ['ek@ornek.test', 'LOG@ornek.test', 'gecersiz'], 'bilgi' => ['cc@ornek.test', 'ek@ornek.test']]);
ok('ayar adresleri PO adreslerine EKLENİR (tekil, geçersiz atılır, PO korunur)', $a['kime'] === ['log@ornek.test', 'ek@ornek.test'] && $a['bilgi'] === ['packinglist-x@ornek.test', 'cc@ornek.test'], json_encode($a));
$a = siparis_pkl_mail_taslak([], 'a.xlsx', 'TC', 'K', ['bilgi' => ['cc@ornek.test']]);
ok('yalnız ayar Bilgi varsa Kime\'ye geçer', $a['kime'] === ['cc@ornek.test'] && $a['bilgi'] === []);
$a = siparis_pkl_mail_taslak(['packinglist-x@ornek.test'], 'a.xlsx', 'TC', 'K', ['kime' => ['ek@ornek.test']]);
ok('ayardan Kime gelince PO\'daki packinglist adresi CC\'de kalır', $a['kime'] === ['ek@ornek.test'] && $a['bilgi'] === ['packinglist-x@ornek.test'], json_encode($a));
// KV ayarı
$db->exec('DROP TABLE hks_kv');
ok('hks_kv yokken ayar oku boş, kaydet reddedilir', siparis_pkl_mail_ayar_oku($db) === ['kime' => [], 'bilgi' => [], 'govde' => '', 'evrak_govde' => ''] && siparis_pkl_mail_ayar_kaydet($db, ['kime' => 'a@ornek.test'], 1)['ok'] === false);
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(100) PRIMARY KEY, deger TEXT)');
$k = siparis_pkl_mail_ayar_kaydet($db, ['kime' => "Ek@Ornek.test\nek@ornek.test; ek2@ornek.test", 'bilgi' => 'cc@ornek.test', 'govde' => "Merhaba\r\n{ad}", 'evrak_govde' => "Evrak\n{ad}"], 1);
$o = siparis_pkl_mail_ayar_oku($db);
ok('kaydet/oku: küçük harf + tekil, CRLF→LF', $k['ok'] && $o === ['kime' => ['ek@ornek.test', 'ek2@ornek.test'], 'bilgi' => ['cc@ornek.test'], 'govde' => "Merhaba\n{ad}", 'evrak_govde' => "Evrak\n{ad}"], json_encode([$k, $o]));
$k = siparis_pkl_mail_ayar_kaydet($db, ['kime' => 'gecersiz-adres'], 1);
ok('geçersiz e-posta reddedilir, eski ayar korunur', !$k['ok'] && str_contains((string)$k['hata'], 'Geçersiz e-posta') && siparis_pkl_mail_ayar_oku($db)['kime'] === ['ek@ornek.test', 'ek2@ornek.test']);
$k = siparis_pkl_mail_ayar_kaydet($db, ['bilgi' => implode("\n", array_map(fn($i) => "u$i@ornek.test", range(1, SIPARIS_EVRAK_MAIL_MAX + 1)))], 1);
ok('listede 10\'dan fazla adres reddedilir', !$k['ok'] && str_contains((string)$k['hata'], 'en çok'));
$k = siparis_pkl_mail_ayar_kaydet($db, ['govde' => str_repeat('x', SIPARIS_MAIL_GOVDE_MAX + 1)], 1);
ok('çok uzun metin şablonu reddedilir', !$k['ok'] && str_contains((string)$k['hata'], 'karakter'));
$auds = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_pkl_mail_ayar'")->fetchAll(PDO::FETCH_COLUMN);
ok('audit yalnız sayı/bayrak (adres ve metin YAZILMAZ); başarısız kayıt audit yazmaz', count($auds) === 1 && str_contains((string)$auds[0], '"kime_sayisi":2') && str_contains((string)$auds[0], '"pkl_metin":true')
    && !str_contains((string)$auds[0], '@') && !str_contains((string)$auds[0], 'Merhaba'), json_encode($auds));
$db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_pkl_mail_ayar', '{bozuk']);
ok('bozuk JSON → boş (fail soft)', siparis_pkl_mail_ayar_oku($db)['kime'] === []);
siparis_pkl_mail_ayar_kaydet($db, ['kime' => 'ek@ornek.test', 'evrak_govde' => "Evrak ayar metni\n{ad}"], 1);

// uç: evrak .eml — ayar şablonu ve ?govde= düzenlemesi
$db->exec("UPDATE siparis_sevkiyatlar SET durum = 'mail_gonderildi' WHERE id = $sid");
$r = uc(['sid' => $sid], 'siparis_eml.php');
[$ub, $ug] = array_pad(explode("\r\n\r\n", $r['govde'], 2), 2, '');
ok('uç: evrak .eml gövdesi ayardaki şablondan', str_ends_with((string)base64_decode((string)preg_replace('/\s+/', '', $ug)), "Evrak ayar metni\r\nKullanıcı 1"), $r['govde']);
$r = uc(['sid' => $sid, 'govde' => "Düzenlenmiş\r\nmetin"], 'siparis_eml.php');
[$ub, $ug] = array_pad(explode("\r\n\r\n", $r['govde'], 2), 2, '');
ok('uç: ?govde= penceredeki düzenlemeyi gövdeye yazar (başlıklar etkilenmez)', trim((string)base64_decode((string)preg_replace('/\s+/', '', $ug))) === "Düzenlenmiş\r\nmetin" && str_starts_with($r['govde'], 'X-Unsent: 1') && !preg_match('/^Bcc:/mi', $ub), $r['govde']);
$r = uc(['sid' => $sid, 'govde' => "x\r\nBcc: kotu@ornek.test"], 'siparis_eml.php');
[$ub, $ug] = array_pad(explode("\r\n\r\n", $r['govde'], 2), 2, '');
ok('uç: ?govde= içindeki "Bcc:" başlığa SIZMAZ (gövde base64)', !preg_match('/^Bcc:/mi', $ub) && !str_contains($r['govde'], 'kotu@'), $ub);
$r = uc(['sid' => $sid, 'govde' => ''], 'siparis_eml.php');
[$ub, $ug] = array_pad(explode("\r\n\r\n", $r['govde'], 2), 2, '');
ok('uç: boş ?govde= → ayar şablonu', str_ends_with((string)base64_decode((string)preg_replace('/\s+/', '', $ug)), "Evrak ayar metni\r\nKullanıcı 1"));

echo "\n=== 12. v422 ekran kaynağı ===\n";
$v = (string)file_get_contents($ROOT . '/siparis_view.php');
$pkB = (string)substr($v, 0, (int)strpos($v, 'id="sipEvrakModal"'));
$evB = (string)substr($v, (int)strpos($v, 'id="sipEvrakModal"'));
ok('PKL penceresi: ayar okunur, taslağa verilir, düzenlenebilir metin kutusu + mailto/eml/sıfırla işaretleri',
    str_contains($v, 'siparis_pkl_mail_ayar_oku($pdo)') && str_contains($v, "(string)\$sev['order_ref'], (string)(\$auth_user['display_name'] ?: \$auth_user['username']), \$pklMailAyar)")
    && str_contains($pkB, 'textarea rows="6"') && str_contains($pkB, 'data-sip-govde') && str_contains($pkB, 'data-sip-govde-mailto') && str_contains($pkB, 'data-sip-govde-eml') && str_contains($pkB, 'data-sip-govde-sifirla'));
ok('evrak penceresi: ayardaki evrak metni taslağa verilir; metin kutusu + işaretler', str_contains($v, "\$pklMailAyar['evrak_govde']") && str_contains($evB, 'data-sip-govde-ilk') && str_contains($evB, 'data-sip-govde-mailto')
    && str_contains($evB, 'data-sip-govde-eml') && str_contains($evB, 'data-sip-govde-sifirla'));
$d = (string)file_get_contents($ROOT . '/siparis_dosya.php');
ok('siparis_dosya.php (PKL .eml): ayar + ?govde= uygulanır', str_contains($d, 'siparis_pkl_mail_ayar_oku($pdo)') && str_contains($d, "siparis_mail_govde_uygula(\$taslak, isset(\$_GET['govde'])"));
$ay = (string)file_get_contents($ROOT . '/siparis_ayarlar.php');
ok('ayarlar: #pkl-mail kartı, alanlar, islem=pkl_mail_ayar, siparis.delete kapısı', str_contains($ay, 'id="pkl-mail"') && str_contains($ay, 'name="pkl_kime"') && str_contains($ay, 'name="pkl_bilgi"')
    && str_contains($ay, 'name="pkl_govde"') && str_contains($ay, 'name="evrak_govde"') && str_contains($ay, 'value="pkl_mail_ayar"')
    && preg_match("/islem === 'pkl_mail_ayar'\)\s*\{\s*require_siparis\('delete'\)/", $ay) === 1);
ok('JS: input → mailto body / eml govde parametresi; sıfırla', str_contains((string)file_get_contents($ROOT . '/assets/siparis.js'), 'textarea[data-sip-govde]') && str_contains((string)file_get_contents($ROOT . '/assets/siparis.js'), 'data-sip-govde-sifirla'));

siparis_test_bitir();
