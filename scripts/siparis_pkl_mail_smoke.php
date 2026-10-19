<?php
// =========================================================
// scripts/siparis_pkl_mail_smoke.php — PKL mail taslağı (v383): Kime/Bilgi ayrımı, mailto, ekli .eml (X-Unsent), uç
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
function uc(array $get): array
{
    global $ROOT, $SIPT;
    $src = (string)file_get_contents($ROOT . '/siparis_dosya.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sipt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sipt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SipExit();', $src);
    $src = str_replace('readfile($yol);', 'echo file_get_contents($yol);', $src);
    $tmp = sys_get_temp_dir() . '/sip_eml_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    $SIPT = ['h' => [], 'kod' => 200];
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
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

siparis_test_bitir();
