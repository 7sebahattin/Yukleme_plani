<?php
// =========================================================
// scripts/frugro_ui_smoke.php — Frugro Siparişler SAYFALARI + uçları: render, 403/CSRF, depo izolasyonu, XSS,
// yükleme sonucu, düzenle/durum/sil, para birimleri toplanmıyor, nav kaydı.
//   php scripts/frugro_ui_smoke.php
// Bellek içi SQLite + stub auth + SENTETİK fixture (scripts/fixtures/frugro/order_log_ornek.xlsx). Sayfalar gerçekten çalıştırılır
// (header()/http_response_code()/exit kaynakta yakalanır). Canlı DB'ye dokunmaz.
// =========================================================
if (in_array('--csrf', $argv ?? [], true)) {            // alt süreç: CSRF'siz POST → reddedilmeli
    require_once __DIR__ . '/_siparis_test_lib.php';
    require_once $ROOT . '/config/frugro_core.php';
    $dosya = (string)$argv[array_search('--csrf', $argv) + 1];
    $db = db(); siparis_test_diger_tablolar($db);
    foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
    $IS_ADMIN = true; $UID = 1;
    ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['islem' => 'sil', 'id' => '1']; $_GET = [];
    ob_start();
    $src = file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $tmp = sys_get_temp_dir() . '/frg_csrf_' . getmypid() . '.php'; file_put_contents($tmp, $src);
    include $tmp;
    ob_get_clean();
    echo "CSRF-GECTI\n";
    exit(0);
}
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_core.php';
require_once $ROOT . '/vendor/autoload.php';
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$db = db();
siparis_test_diger_tablolar($db);

class FrgExit extends RuntimeException {}
$FT = ['h' => [], 'kod' => 200];
function frgt_header(string $h, bool $replace = true, int $code = 0): void { global $FT; if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $FT['h'][strtolower(trim($a))] = trim($b); } if ($code > 0) $FT['kod'] = $code; }
function frgt_code(?int $c = null) { global $FT; if ($c !== null) $FT['kod'] = $c; return $FT['kod']; }
function frg_calistir(string $tmp): void { include $tmp; }
/** @return array{html:string,kod:int,git:?string,hata:?string,json:?array} */
function sayfa(string $dosya, array $get = [], array $post = [], ?array $json = null, array $files = []): array
{
    global $ROOT, $FT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'frgt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'frgt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new FrgExit();', $src);
    $src = str_replace("file_get_contents('php://input')", '$GLOBALS[\'FRG_BODY\']', $src);
    $tmp = sys_get_temp_dir() . '/frg_ui_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $FT = ['h' => [], 'kod' => 200];
    $GLOBALS['FRG_BODY'] = $json === null ? '' : json_encode($json);
    $_GET = $get; $_POST = $post; $_FILES = $files;
    $_SERVER['REQUEST_METHOD'] = ($json !== null || $post || $files) ? 'POST' : 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya;
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['flash']);
    ob_start(); $hata = null;
    try { frg_calistir($tmp); } catch (FrgExit $e) {} catch (Throwable $e) { $hata = $e->getMessage(); }
    $html = (string)ob_get_clean(); @unlink($tmp);
    $j = json_decode($html, true);
    return ['html' => $html, 'kod' => $FT['kod'], 'git' => $FT['h']['location'] ?? null, 'hata' => $hata, 'json' => is_array($j) ? $j : null];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null): void { global $PERMS, $IS_ADMIN, $UID, $DEPOLAR; $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar; }
function csrf(): string { if (session_status() === PHP_SESSION_NONE) session_start(); return csrf_token(); }
function temiz(array $r): bool { return $r['hata'] === null && !preg_match('/\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b:/', $r['html']); }
function kayit(string $dosya, array $j): array { $j['csrf'] = csrf(); return sayfa($dosya, [], [], $j); }

$ADMIN = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'];
$OPER  = ['siparis.read', 'siparis.write', 'siparis.match'];
$VIEW  = ['siparis.read'];
$FIX = $ROOT . '/scripts/fixtures/frugro/order_log_ornek.xlsx';

echo "=== 1. Tablolar yokken ===\n";
rol([], true);
$r = sayfa('frugro_siparis.php');
ok('liste: kurulmadı kutusu + migrate.php#frugro (admin)', temiz($r) && str_contains($r['html'], 'henüz kurulmadı') && str_contains($r['html'], 'migrate.php#frugro'), (string)$r['hata']);
$r = sayfa('frugro_view.php', ['id' => 1]);
ok('detay: kurulmadı kutusu', temiz($r) && str_contains($r['html'], 'henüz kurulmadı'));
rol($OPER);
$r = kayit('frugro_kaydet.php', ['islem' => 'sil', 'id' => 1]);
ok('kaydet ucu: tablo yokken 409', $r['kod'] === 409, (string)$r['kod']);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }

echo "\n=== 2. Yükleme ucu ===\n";
rol($ADMIN, true);
$f = siparis_test_dosya_yol($FIX, 'order_log.xlsx');
$r = sayfa('frugro_yukle.php', [], ['csrf' => csrf()], null, ['dosya' => ['name' => [$f['name']], 'tmp_name' => [$f['tmp_name']], 'error' => [0], 'size' => [$f['size']]]]);
ok('yükleme: 200 + 1 yeni', $r['kod'] === 200 && ($r['json']['ozet']['yeni'] ?? -1) === 1 && ($r['json']['ozet']['hata'] ?? -1) === 0, json_encode($r['json']));
$ID = (int)$db->query('SELECT id FROM frugro_siparisler')->fetchColumn();
ok('yükleme: kayıt kaynak=yukleme, durum=yeni', $db->query("SELECT kaynak||durum FROM frugro_siparisler WHERE id=$ID")->fetchColumn() === 'yuklemeyeni');
ok('yükleme: dosya sonuç satırında PO var', ($r['json']['dosyalar'][0]['sonuclar'][0]['po_no'] ?? '') === '26-1622-44080');
$f = siparis_test_dosya_yol($FIX, 'order_log2.xlsx');
$r = sayfa('frugro_yukle.php', [], ['csrf' => csrf()], null, ['dosya' => $f]);
ok('aynı dosya tekrar: 0 yeni / 1 zaten (tek dosya biçimi de çalışır)', ($r['json']['ozet']['yeni'] ?? -1) === 0 && ($r['json']['ozet']['zaten'] ?? -1) === 1, json_encode($r['json']['ozet'] ?? null));
$r = sayfa('frugro_yukle.php', [], ['csrf' => csrf()], null, ['dosya' => siparis_test_dosya('düz metin', 'x.xlsx')]);
ok('xlsx olmayan içerik: hata sayılır, 200', ($r['json']['ozet']['hata'] ?? 0) === 1 && $r['json']['dosyalar'][0]['ok'] === false);
$r = sayfa('frugro_yukle.php', [], ['csrf' => csrf()], null, ['dosya' => siparis_test_dosya('x', 'x.pdf')]);
ok('uzantı .xlsx değil: reddedilir', ($r['json']['dosyalar'][0]['hata'] ?? '') === 'Yalnız .xlsx dosyası yüklenebilir.');
$r = sayfa('frugro_yukle.php', [], ['csrf' => csrf()]);
ok('dosyasız POST: 400', $r['kod'] === 400);
$r = sayfa('frugro_yukle.php');
ok('GET: 405', $r['kod'] === 405);
rol($VIEW);
$f = siparis_test_dosya_yol($FIX, 'a.xlsx');
$r = sayfa('frugro_yukle.php', [], ['csrf' => csrf()], null, ['dosya' => $f]);
ok('yalnız okuma yetkisi: yükleme 403', $r['kod'] === 403);
rol($ADMIN, true);
ok('yükleme audit\'e düştü', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='frugro_yukle'")->fetchColumn() >= 1);

echo "\n=== 3. Liste ===\n";
rol($ADMIN, true);
$r = sayfa('frugro_siparis.php');
ok('liste temiz çalıştı', temiz($r), (string)$r['hata'] . substr($r['html'], 0, 200));
ok('liste: PO + müşteri + liman + ürün', str_contains($r['html'], '26-1622-44080') && str_contains($r['html'], 'Aldi-UK') && str_contains($r['html'], 'FELIXSTOWE PORT') && str_contains($r['html'], 'NAR'));
ok('liste: para birimi atanmamış → "GBP/EUR seçilecek"', str_contains($r['html'], 'GBP/EUR seçilecek'));
ok('liste: 8 KPI çipi (OGL .sip-chip) + üç bölüm kartı (İşlem Bekleyen / Evrak Takımı Bekleyen / Kapanan)', preg_match_all('/class="sip-chip[ "]/', $r['html']) === 8 && str_contains($r['html'], 'sip-bolum-acik') && str_contains($r['html'], 'sip-bolum-evrak') && str_contains($r['html'], 'sip-bolum-kapali'));
ok('liste: Excel yükle düğmesi (write)', str_contains($r['html'], 'id="frgYukleDugme"'));
ok('liste: .table-wrap içinde sip-table + mobil kartlar (sip-kartlar)', str_contains($r['html'], 'table-wrap pc-only') && str_contains($r['html'], 'data-table sip-table') && str_contains($r['html'], 'sip-kartlar'));
ok('liste: satır içi onclick YOK', !preg_match('/\sonclick=/i', substr($r['html'], (int)strpos($r['html'], 'class="sip-mod frg"'))));
ok('liste: assets/frugro.css + frugro.js yükleniyor', str_contains($r['html'], 'assets/frugro.css') && str_contains($r['html'], 'assets/frugro.js'));
$r = sayfa('frugro_siparis.php', ['q' => 'ALDI']);
ok('arama: müşteri adı (büyük/küçük harf duyarsız)', str_contains($r['html'], '26-1622-44080'));
$r = sayfa('frugro_siparis.php', ['q' => 'yokboyle']);
ok('arama: eşleşmeyen → boş mesaj', str_contains($r['html'], 'eşleşen açık sipariş yok'));
$r = sayfa('frugro_siparis.php', ['durum' => 'kapali']);
ok('durum filtresi: kapalı → yalnız Kapanan bölümü, boş', str_contains($r['html'], 'Kapanan sipariş yok') && !str_contains($r['html'], 'sip-bolum-acik'));
rol($VIEW);
$r = sayfa('frugro_siparis.php');
ok('yalnız okuma: liste açılır, Excel yükle düğmesi YOK', temiz($r) && str_contains($r['html'], '26-1622-44080') && !str_contains($r['html'], 'frgYukleDugme'));
rol([]);
$r = sayfa('frugro_siparis.php');
ok('yetkisiz: liste 403 (hata fırlatır)', $r['hata'] !== null && str_contains($r['hata'], 'forbidden'));
$r = sayfa('frugro_view.php', ['id' => $ID]);
ok('yetkisiz: detay 403', $r['hata'] !== null && str_contains($r['hata'], 'forbidden'));

echo "\n=== 4. Para birimleri toplanmaz ===\n";
rol($ADMIN, true);
$p1 = ['po_no' => '26-1700-00001', 'kutu_palet' => 100, 'palet' => 10, 'fiyat_kutu_birim' => 46000, 'para_birimi' => 'GBP', 'urun' => 'NAR', 'cikis_tarihi' => '2026-11-12'];
$p2 = ['po_no' => '26-1700-00002', 'kutu_palet' => 100, 'palet' => 10, 'fiyat_kutu_birim' => 46000, 'para_birimi' => 'EUR', 'urun' => 'NAR', 'cikis_tarihi' => '2026-11-12'];
$ka = frg_siparis_kaydet($db, $p1 + ['kutu' => 1000], ['depo' => 'DEPO1']); frg_siparis_kaydet($db, $p2 + ['kutu' => 1000], ['depo' => 'DEPO1']);
$r = sayfa('frugro_siparis.php');
ok('GBP ve EUR tutarları ayrı ayrı gösterilir (4.600,00 GBP / EUR)', str_contains($r['html'], '4.600,00 GBP') && str_contains($r['html'], '4.600,00 EUR'));
ok('birleşik/çapraz toplam (9.200,00) YOK', !str_contains($r['html'], '9.200,00'));

echo "\n=== 5. Detay ===\n";
$r = sayfa('frugro_view.php', ['id' => $ID]);
ok('detay temiz', temiz($r), (string)$r['hata']);
ok('detay: bilgi + PKL/Invoice bağlantıları', str_contains($r['html'], '26-1622-44080') && str_contains($r['html'], 'frugro_pkl.php?id=' . $ID) && str_contains($r['html'], 'frugro_invoice.php?id=' . $ID));
ok('detay (P2, OGL eşi): ✎ alan penceresi + 7 adımlı şerit + ⋮ → Sil penceresi (onay kutulu); eski <dialog> YOK', str_contains($r['html'], 'id="sipAlanModal"') && str_contains($r['html'], 'data-sip-alan=') && substr_count($r['html'], '<li class="sip-step') === 7
    && str_contains($r['html'], 'data-sip-ac="frgSilModal"') && str_contains($r['html'], 'id="frgSilModal"') && !str_contains(substr($r['html'], (int)strpos($r['html'], 'sip-mod sip-view')), '<dialog'));
ok('detay: olay zaman çizelgesi (Sipariş alındı)', str_contains($r['html'], 'Sipariş alındı'));
$r = sayfa('frugro_view.php', ['id' => 99999]);
ok('olmayan id: 404', $r['kod'] === 404);
rol($VIEW);
$r = sayfa('frugro_view.php', ['id' => $ID]);
ok('yalnız okuma: ✎ / pencereler / Sil YOK', temiz($r) && !str_contains($r['html'], 'data-sip-alan=') && !str_contains($r['html'], 'frgSilModal') && !str_contains($r['html'], 'pm-overlay'));

echo "\n=== 6. Düzenle / durum / sil ===\n";
rol($OPER);
$r = kayit('frugro_kaydet.php', ['islem' => 'duzenle', 'id' => $ID, 'alanlar' => ['son_musteri' => 'Yeni Müşteri', 'fiyat_kutu_birim' => '5,25', 'para_birimi' => 'eur', 'durum' => 'kapali', 'depo' => 'DEPO9', 'po_no' => 'X']]);
ok('düzenle: 200, 3 alan değişti (durum/depo/po_no beyaz liste dışı atıldı)', $r['kod'] === 200 && ($r['json']['degisen'] ?? 0) === 3, json_encode($r['json']));
$s = frg_getir($db, $ID);
ok('düzenle: değerler yazıldı (fiyat on-binde, para birimi büyük harf)', $s['son_musteri'] === 'Yeni Müşteri' && (int)$s['fiyat_kutu_birim'] === 52500 && $s['para_birimi'] === 'EUR');
ok('düzenle: durum/depo/po_no DEĞİŞMEDİ', $s['durum'] === 'yeni' && $s['depo'] === 'DEPO1' && $s['po_no'] === '26-1622-44080');
ok('düzenle: olay + audit alan adlarıyla', (int)$db->query("SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id=$ID AND tur='duzenlendi' AND metin LIKE '%son_musteri%'")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE module='frugro_siparis' AND action='update' AND new_values LIKE '%son_musteri%'")->fetchColumn() === 1);
$r = kayit('frugro_kaydet.php', ['islem' => 'duzenle', 'id' => $ID, 'alanlar' => ['son_musteri' => 'Yeni Müşteri']]);
ok('düzenle: değişiklik yoksa degisen=0', ($r['json']['degisen'] ?? -1) === 0);
$r = kayit('frugro_kaydet.php', ['islem' => 'duzenle', 'id' => $ID, 'alanlar' => ['cikis_tarihi' => 'abc', 'kutu' => '1x', 'para_birimi' => 'USD']]);
ok('düzenle: geçersiz tarih/sayı/para birimi → 422', $r['kod'] === 422 && count($r['json']['hatalar'] ?? []) === 3);
$r = kayit('frugro_kaydet.php', ['islem' => 'duzenle', 'id' => $ID, 'alanlar' => ['son_musteri' => '<script>alert(1)</script>']]);
ok('düzenle: XSS metni kaydedilir ama detayda kaçırılır', $r['kod'] === 200);
$r = sayfa('frugro_view.php', ['id' => $ID]);
ok('detay: <script> kaçırıldı', !str_contains($r['html'], '<script>alert(1)') && str_contains($r['html'], '&lt;script&gt;alert(1)'));
$r = sayfa('frugro_siparis.php');
ok('liste: <script> kaçırıldı', !str_contains($r['html'], '<script>alert(1)') && str_contains($r['html'], '&lt;script&gt;'));
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $ID, 'durum' => 'iptal', 'gerekce' => '']);
ok('iptal: gerekçesiz 422', $r['kod'] === 422 && frg_getir($db, $ID)['durum'] === 'yeni');
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $ID, 'durum' => 'iptal', 'gerekce' => 'Müşteri vazgeçti']);
ok('iptal: gerekçeyle 200, durum=iptal, olay+audit', $r['kod'] === 200 && frg_getir($db, $ID)['durum'] === 'iptal'
    && (int)$db->query("SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id=$ID AND tur='durum' AND metin LIKE '%Müşteri vazgeçti%'")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='frugro_durum'")->fetchColumn() === 1);
$r = kayit('frugro_kaydet.php', ['islem' => 'duzenle', 'id' => $ID, 'alanlar' => ['urun' => 'X']]);
ok('iptal siparişte düzenleme 422', $r['kod'] === 422);
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $ID, 'durum' => 'yeniden_ac']);
ok('yeniden aç → yeni', $r['kod'] === 200 && frg_getir($db, $ID)['durum'] === 'yeni');
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $ID, 'durum' => 'kapali']);
ok('eski "kapali" hedefi artık 422 (adım adım: mail → evrak takımı)', $r['kod'] === 422 && frg_getir($db, $ID)['durum'] === 'yeni');
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $ID, 'durum' => 'yola_cikti']);
ok('yola_cikti: yeni siparişten (PKL yok) 422 — matris/ön koşul', $r['kod'] === 422 && frg_getir($db, $ID)['durum'] === 'yeni');
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $ID, 'durum' => 'bilinmeyen']);
ok('keyfi durum kodu 400', $r['kod'] === 400);
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $ID, 'durum' => 'hazirlik']);
ok('hazirlik: yeni → hazirlik 200', $r['kod'] === 200 && frg_getir($db, $ID)['durum'] === 'hazirlik');
$db->exec("UPDATE frugro_siparisler SET durum='kapali' WHERE id=$ID");   // eski veri (sonraki bölümler kapalı kayıt bekler)
rol($VIEW);
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $ID, 'durum' => 'yeniden_ac']);
ok('yalnız okuma: durum değiştirme 403', $r['kod'] === 403 && frg_getir($db, $ID)['durum'] === 'kapali');
rol($OPER);
$r = kayit('frugro_kaydet.php', ['islem' => 'sil', 'id' => $ID, 'onay' => true]);
ok('operatör silemez (siparis.delete yok): 403', $r['kod'] === 403 && frg_getir($db, $ID) !== null);
rol($ADMIN, true);
$r = kayit('frugro_kaydet.php', ['islem' => 'sil', 'id' => $ID]);
ok('sil: onaysız 422', $r['kod'] === 422 && frg_getir($db, $ID) !== null);
$r = kayit('frugro_kaydet.php', ['islem' => 'sil', 'id' => $ID, 'onay' => true]);
ok('sil: soft (deleted_at dolu, satır duruyor), audit', $r['kod'] === 200 && frg_getir($db, $ID) === null
    && (int)$db->query("SELECT COUNT(*) FROM frugro_siparisler WHERE id=$ID AND deleted_at IS NOT NULL")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='delete' AND module='frugro_siparis'")->fetchColumn() === 1);
$r = sayfa('frugro_view.php', ['id' => $ID]);
ok('silinmiş kayıt detayı 404', $r['kod'] === 404);
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => 99999, 'durum' => 'kapali']);
ok('olmayan id: 404', $r['kod'] === 404);

echo "\n=== 7. Depo izolasyonu ===\n";
$dx = frg_siparis_kaydet($db, ['po_no' => '26-1800-00001', 'urun' => 'NAR', 'kutu' => 10, 'cikis_tarihi' => '2026-11-15'], ['depo' => 'DEPO2']);
$dy = frg_siparis_kaydet($db, ['po_no' => '26-1800-00002', 'urun' => 'NAR', 'kutu' => 10, 'cikis_tarihi' => '2026-11-15'], ['depo' => '']);
rol($ADMIN, false, 1, ['DEPO1']);
$r = sayfa('frugro_siparis.php');
ok('DEPO1 kullanıcısı: DEPO2 siparişi listede YOK, boş depolu VAR', !str_contains($r['html'], '26-1800-00001') && str_contains($r['html'], '26-1800-00002') && str_contains($r['html'], '26-1700-00001'));
$r = sayfa('frugro_view.php', ['id' => $dx['id']]);
ok('DEPO2 detayı 404 (varlığı sızdırmaz)', $r['kod'] === 404 && !str_contains($r['html'], '26-1800-00001'));
$r = kayit('frugro_kaydet.php', ['islem' => 'durum', 'id' => $dx['id'], 'durum' => 'kapali']);
ok('DEPO2 kaydına yazma 404, durum değişmedi', $r['kod'] === 404 && frg_getir($db, (int)$dx['id'])['durum'] === 'yeni');
$r = sayfa('frugro_view.php', ['id' => $dy['id']]);
ok('boş depolu kayıt detayı açılır', $r['kod'] === 200 && str_contains($r['html'], '26-1800-00002'));

echo "\n=== 8. CSRF'siz POST (alt süreç) ===\n";
foreach (['frugro_kaydet.php', 'frugro_yukle.php'] as $d) {
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --csrf ' . escapeshellarg($d) . ' 2>&1');
    ok("$d: CSRF'siz POST reddedildi (CSRF-GECTI basılmadı)", !str_contains($o, 'CSRF-GECTI'), substr($o, 0, 200));
}

echo "\n=== 9. Gezinme ===\n";
rol($ADMIN, true);
$_SERVER['PHP_SELF'] = '/frugro_view.php';
ok('nav_aktif_anahtar: frugro_view/pkl/invoice/ayarlar/mail → frugro', (function () { foreach (['frugro_siparis.php', 'frugro_view.php', 'frugro_pkl.php', 'frugro_invoice.php', 'frugro_ayarlar.php', 'frugro_mail.php'] as $p) { $_SERVER['PHP_SELF'] = '/' . $p; if (nav_aktif_anahtar() !== 'frugro') return false; } return true; })());
$kayitlar = nav_alt_sayfalar();
$anahtarlar = array_keys($kayitlar);
ok('nav_alt_sayfalar: frugro, siparis\'ten HEMEN sonra', array_search('frugro', $anahtarlar, true) === array_search('siparis', $anahtarlar, true) + 1);
ok('nav_alt_sayfalar: frugro kaydı (href, ikon dosyası var)', $kayitlar['frugro']['href'] === 'frugro_siparis.php' && is_file($ROOT . '/' . $kayitlar['frugro']['ikon']));
rol($VIEW);
ok('nav_alt_izinler: siparis.read → frugro açık', nav_alt_izinler()['frugro'] === true && nav_alt_izinler()['frugro'] === nav_alt_izinler()['siparis']);
rol([]);
ok('nav_alt_izinler: yetkisiz → frugro kapalı', nav_alt_izinler()['frugro'] === false);

siparis_test_bitir();
