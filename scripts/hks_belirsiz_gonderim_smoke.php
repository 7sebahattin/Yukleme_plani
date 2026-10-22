<?php
// =========================================================
// scripts/hks_belirsiz_gonderim_smoke.php — Hal Kayıt "belirsiz gönderim" işareti (v417)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: halkayit/belirsiz_gonderim_lib.php'nin
// GERÇEK fonksiyonları, bellek içi kv taklidi + bellek içi SQLite (Gönderilenler) ile sınanır.
// HKS sorgusu enjekte edilen closure'dır. api.php / app.html / hks_soap.php kuralları statik
// denetlenir. Örnekler sentetiktir.
//
//   php scripts/hks_belirsiz_gonderim_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-84s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

// --- kv taklidi (db.php hks_kv_* sözleşmesi) -----------------------------------------
$GLOBALS['KV'] = [];
function hks_kv_oku($a, $v = null) { return array_key_exists($a, $GLOBALS['KV']) ? json_decode($GLOBALS['KV'][$a], true) : $v; }
function hks_kv_yaz($a, $d) { $GLOBALS['KV'][$a] = json_encode($d, JSON_UNESCAPED_UNICODE); }
function hks_kv_sil($a) { unset($GLOBALS['KV'][$a]); }
function hks_kv_onekli($o) {
    $out = [];
    foreach ($GLOBALS['KV'] as $a => $d) if (strncmp($a, (string)$o, strlen((string)$o)) === 0) $out[$a] = json_decode($d, true);
    return $out;
}
// audit taklidi
$GLOBALS['AUDIT'] = [];
function audit_log_event($action, $module, $id, $old, $new) { $GLOBALS['AUDIT'][] = compact('action', 'module', 'new'); }

require_once "$KOK/halkayit/belirsiz_gonderim_lib.php";

$BUGUN = '2026-10-22';
$DUN = '2026-10-21';

echo "── anahtar ──\n";
$a1 = hks_belirsiz_anahtar('f1', '33 ncn-99', '', $BUGUN);
ok('anahtar <= 60 karakter', strlen($a1) <= 60 && strlen($a1) > 0, (string)strlen($a1));
ok('aynı girdi (plaka yazımı farklı) → aynı anahtar', $a1 === hks_belirsiz_anahtar('f1', '33NCN99', 'x', $BUGUN));
ok('farklı firma → farklı anahtar', $a1 !== hks_belirsiz_anahtar('f2', '33NCN99', '', $BUGUN));
ok('farklı plaka → farklı anahtar', $a1 !== hks_belirsiz_anahtar('f1', '07ABC12', '', $BUGUN));
ok('farklı gün → farklı anahtar', $a1 !== hks_belirsiz_anahtar('f1', '33NCN99', '', $DUN));
ok('plaka yok → belge no anahtarı (farklı belge → farklı anahtar)', hks_belirsiz_anahtar('f1', '', 'B-77', $BUGUN) !== ''
    && hks_belirsiz_anahtar('f1', '', 'B-77', $BUGUN) !== hks_belirsiz_anahtar('f1', '', 'B-78', $BUGUN));
ok('plaka ve belge yok → boş anahtar (işaret yazılamaz)', hks_belirsiz_anahtar('f1', ' ', ' ', $BUGUN) === '');
ok('uzun firma id / belge → yine <= 60', strlen(hks_belirsiz_anahtar(str_repeat('F', 200), str_repeat('9', 300), '', $BUGUN)) <= 60);
ok('firma öneki anahtarın öneki', strpos($a1, hks_belirsiz_firma_onek('f1')) === 0);

echo "\n── işaretle / oku / temizle ──\n";
$GLOBALS['KV'] = [];
$m = hks_belirsiz_isaretle('f1', 't123', '33 ncn-99', '', 21500, 'Bağlantı hatası: Operation timed out', 'istisna', $BUGUN, "$BUGUN 10:00:00");
ok('işaret yazıldı, id/gun/zaman/pk', is_array($m) && $m['id'] !== '' && $m['gun'] === $BUGUN && $m['zaman'] === "$BUGUN 10:00:00" && $m['pk'] === '33NCN99');
$m2 = hks_belirsiz_isaretle('f1', 't999', '33NCN99', '', 21500, 'ikinci', 'istisna', $BUGUN, "$BUGUN 11:00:00");
ok('aynı anahtar tekrar işaretlenirse ilk id/zaman KORUNUR', $m2['id'] === $m['id'] && $m2['zaman'] === "$BUGUN 10:00:00");
ok('kv\'de tek kayıt', count($GLOBALS['KV']) === 1);
ok('plaka+belge yoksa null', hks_belirsiz_isaretle('f1', 't1', '', '', 1, 'x') === null);
$bul = hks_belirsiz_oku_gunler('f1', '33ncn 99', '', $BUGUN);
ok('oku: bugün işaretini bulur (yazımı farklı plaka)', count($bul) === 1 && $bul[0]['id'] === $m['id']);
ok('oku: başka firma bulamaz (izolasyon)', hks_belirsiz_oku_gunler('f2', '33NCN99', '', $BUGUN) === []);
ok('oku: başka plaka bulamaz', hks_belirsiz_oku_gunler('f1', '07ABC12', '', $BUGUN) === []);
ok('ertesi gün (gece yarısı geçişi) işaret hâlâ geçerli', count(hks_belirsiz_oku_gunler('f1', '33NCN99', '', '2026-10-23')) === 1);
$ikiGun = hks_belirsiz_oku_gunler('f1', '33NCN99', '', '2026-10-24');
ok('iki gün sonra: işaret yok sayılır (TTL)', $ikiGun === []);
ok('süresi dolan yetim kayıt temizle_eskiler ile silinir; geçerli olana dokunmaz',
    hks_belirsiz_temizle_eskiler('f1', '2026-10-24') === 1 && count($GLOBALS['KV']) === 0);
$GLOBALS['KV'] = [];
hks_belirsiz_isaretle('f1', 't1', '33NCN99', '', 1, 'x', 'istisna', $BUGUN);
ok('geçerli işarete temizle_eskiler dokunmaz', hks_belirsiz_temizle_eskiler('f1', '2026-10-23') === 0 && count($GLOBALS['KV']) === 1);
$GLOBALS['KV'] = [];
hks_belirsiz_isaretle('f1', 't1', '33NCN99', '', 1, 'x', 'istisna', $BUGUN, "$BUGUN 10:00:00");

$GLOBALS['KV'] = [];
$m = hks_belirsiz_isaretle('f1', 't1', '33NCN99', '', 100, str_repeat('x', 500) . ' TC 12345678901 cep 05395025214', 'istisna', $BUGUN);
ok('hata metni 200 karaktere kısalır', mb_strlen($m['hata']) <= 200);
$m3 = hks_belirsiz_isaretle('f1', 't2', '33NCN99', '', 1, 'TC 12345678901 ve 05395025214 hata', 'istisna', $DUN);
ok('hata metninde 9+ haneli sayı (TC/cep) YOK', !preg_match('/\d{9,}/', $m3['hata']) && strpos($m3['hata'], '***') !== false, $m3['hata']);
$raw = json_encode($GLOBALS['KV']);
ok('kv kaydında kişisel alan anahtarı yok (tc/dogum/cep/sifre)', !preg_match('/"(tc|dogum|cep|sifre|password)[A-Za-z_]*":/i', $raw));
ok('liste_firma: yalnız o firma, geçerli olanlar (salt okunur)', count(hks_belirsiz_liste_firma('f1', $BUGUN)) === 2 && hks_belirsiz_liste_firma('f2', $BUGUN) === []);
$ozet = hks_belirsiz_taslak_icin(hks_belirsiz_liste_firma('f1', $BUGUN), '33 ncn-99', '');
ok('taslak_icin: plakaya uyan en yeni işaretin özeti, ayrıntı sızmaz', is_array($ozet) && array_keys($ozet) === ['isaretId', 'gun', 'zaman', 'neden'] && $ozet['gun'] === $BUGUN);
ok('taslak_icin: uymayan plaka → null', hks_belirsiz_taslak_icin(hks_belirsiz_liste_firma('f1', $BUGUN), '07ABC12', '') === null);
$n = hks_belirsiz_temizle_plaka('f1', '33NCN99', '', $BUGUN);
ok('temizle_plaka bugün+dün işaretlerini siler', $n === 2 && $GLOBALS['KV'] === []);

echo "\n── id doğrulama (bilinçli geçiş) ──\n";
$GLOBALS['KV'] = [];
$m = hks_belirsiz_isaretle('f1', 't1', '33NCN99', '', 1, 'x', 'istisna', $BUGUN);
$liste = hks_belirsiz_liste_firma('f1', $BUGUN);
ok('onay_gecerli: kayıtlı id eşleşir', hks_belirsiz_onay_gecerli($liste, $m['id']));
ok('onay_gecerli: boş / sahte / başka id reddedilir', !hks_belirsiz_onay_gecerli($liste, '') && !hks_belirsiz_onay_gecerli($liste, 'bgSAHTE') && !hks_belirsiz_onay_gecerli($liste, true) && !hks_belirsiz_onay_gecerli([], $m['id']));
ok('id_ile: yalnız birebir', hks_belirsiz_id_ile($liste, $m['id'])['anahtar'] === $m['anahtar'] && hks_belirsiz_id_ile($liste, 'x') === null);

echo "\n── fazla künye (saf) ──\n";
$hks = [['kunyeNo' => '2026-000111', 'urun' => 'Nar', 'miktar' => 20000.0, 'birim' => 'Kg', 'tarih' => $BUGUN, 'bildirimci' => 'ÖRNEK A.Ş.'],
        ['kunyeNo' => '000222', 'urun' => 'Nar', 'miktar' => 5.0],
        ['kunyeNo' => '2026000111'],           // aynı künye (biçimi farklı) — tekrar
        ['kunyeNo' => ''], ['kunyeNo' => '0']];
$f = hks_belirsiz_fazla_kunyeler($hks, ['222']);
ok('bilinen (başı sıfırlı yazım dahil) çıkarılır; kalan tekilleştirilir; boş/0 atlanır', count($f) === 1 && $f[0]['kunyeNo'] === '2026-000111', json_encode($f));
ok('bilinen yoksa hepsi (tekil) döner', count(hks_belirsiz_fazla_kunyeler($hks, [])) === 2);
ok('boş HKS listesi → boş', hks_belirsiz_fazla_kunyeler([], ['1']) === []);
ok('alan seti: kunyeNo/urun/miktar/birim/tarih/bildirimci (TC vb. yok)', array_keys($f[0]) === ['kunyeNo', 'urun', 'miktar', 'birim', 'tarih', 'bildirimci']);

echo "\n── Gönderilenler'den bilinen künyeler (yalnız SELECT) ──\n";
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE g (id TEXT, zaman TEXT, firma_id TEXT, firma_ad TEXT, veri TEXT)');
$ins = $db->prepare('INSERT INTO g VALUES (?,?,?,?,?)');
$ins->execute(['g1', "$BUGUN 08:00:00", 'f1', 'Firma A', json_encode(['yeniKunyeler' => ['111', '112'], 'sonuclar' => [['yeniKunyeNo' => '111'], ['yeniKunyeNo' => '113']]])]);
$ins->execute(['g2', "$BUGUN 09:00:00", 'f2', 'Firma B', json_encode(['yeniKunyeler' => ['999']])]);          // başka firma
$ins->execute(['g3', "$DUN 23:59:59", 'f1', 'Firma A', json_encode(['yeniKunyeler' => ['888']])]);            // dün
$ins->execute(['g4', "$BUGUN 10:00:00", '', 'Firma A', json_encode(['yeniKunyeler' => ['777']])]);             // eski kayıt (firma_id boş)
$ins->execute(['g5', "$BUGUN 10:30:00", 'f1', 'Firma A', 'bozuk-json']);
$b = hks_belirsiz_bilinen_kunyeler($db, 'g', 'f1', 'Firma A', $BUGUN);
sort($b);
ok('bugün, aynı firma (firma_id boş eski kayıt dahil): 111,112,113,777', $b === ['111', '112', '113', '777'], json_encode($b));
ok('başka firma (999) ve dün (888) GİRMEZ; bozuk json atlanır', !in_array('999', $b, true) && !in_array('888', $b, true));
$kaynakLib = (string)file_get_contents("$KOK/halkayit/belirsiz_gonderim_lib.php");

echo "\n── HKS kontrolü (enjekte sorgu, ağsız) ──\n";
$GLOBALS['KV'] = [];
$isaret = [hks_belirsiz_isaretle('f1', 't1', '33 NCN 99', '', 1000, 'x', 'istisna', $BUGUN)];
$cagrilar = [];
$sorgu = function (array $satirlarPerGun) use (&$cagrilar) {
    return function ($gun, $pl, $bel) use (&$cagrilar, $satirlarPerGun) {
        $cagrilar[] = [$gun, $pl, $bel];
        return $satirlarPerGun[$gun] ?? [];
    };
};
$k = hks_belirsiz_kontrol($isaret, $sorgu([]), [], $BUGUN);
ok('kontrol: HKS boş → temiz', $k['durum'] === 'temiz' && $k['bulunan'] === [] && $cagrilar === [[$BUGUN, '33 NCN 99', '']], json_encode($cagrilar));
$cagrilar = [];
$k = hks_belirsiz_kontrol($isaret, $sorgu([$BUGUN => $hks]), ['222', '2026000111'], $BUGUN);
ok('kontrol: tüm künyeler Gönderilenler\'de kayıtlı → temiz', $k['durum'] === 'temiz');
$k = hks_belirsiz_kontrol($isaret, $sorgu([$BUGUN => $hks]), ['222'], $BUGUN);
ok('kontrol: kayıtta olmayan künye → kunye_var + bulunan', $k['durum'] === 'kunye_var' && count($k['bulunan']) === 1 && $k['bulunan'][0]['kunyeNo'] === '2026-000111');
$k = hks_belirsiz_kontrol($isaret, function () { throw new Exception('Bağlantı hatası: Operation timed out TC 12345678901'); }, [], $BUGUN);
ok('kontrol: sorgu istisnası → kontrol_edilemedi (FAIL-CLOSED), hata temizlenmiş', $k['durum'] === 'kontrol_edilemedi'
    && $k['bulunan'] === [] && $k['hata'] !== '' && !preg_match('/\d{9,}/', $k['hata']), json_encode($k, JSON_UNESCAPED_UNICODE));
ok('kontrol: işaret yoksa sorgu YAPILMAZ', (function () use ($sorgu, &$cagrilar) { $cagrilar = []; $r = hks_belirsiz_kontrol([], $sorgu([]), [], '2026-10-22'); return $r['durum'] === 'temiz' && $cagrilar === []; })());
// gece yarısı: işaret dünden, bugün de sorulur
$cagrilar = [];
$GLOBALS['KV'] = [];
$dunIsaret = [hks_belirsiz_isaretle('f1', 't1', '33NCN99', '', 1, 'x', 'istisna', $DUN)];
$k = hks_belirsiz_kontrol($dunIsaret, $sorgu([$BUGUN => [['kunyeNo' => '555']], $DUN => [['kunyeNo' => '444']]]), [], $BUGUN);
ok('kontrol: dünkü işarette DÜN ve BUGÜN ikisi sorulur', array_column($cagrilar, 0) === [$DUN, $BUGUN], json_encode($cagrilar));
ok('kontrol: iki günün künyeleri birleşir', count($k['bulunan']) === 2);
$k = hks_belirsiz_kontrol($dunIsaret, function ($gun) use ($DUN) { if ($gun === $DUN) return [['kunyeNo' => '1']]; throw new Exception('ikinci gün hata'); }, [], $BUGUN);
ok('kontrol: günlerden biri hata verirse kontrol_edilemedi (kısmi sonuçla geçilmez)', $k['durum'] === 'kontrol_edilemedi');
// belge no'lu (plakasız)
$cagrilar = [];
$GLOBALS['KV'] = [];
$bel = [hks_belirsiz_isaretle('f1', 't1', '', 'B-77', 1, 'x', 'istisna', $BUGUN)];
hks_belirsiz_kontrol($bel, $sorgu([]), [], $BUGUN);
ok('kontrol: plaka yoksa belge no ile sorulur', $cagrilar === [[$BUGUN, '', 'B-77']], json_encode($cagrilar));

echo "\n── kesin gitmedi sınıflaması ──\n";
class SahteAgHatasi extends Exception { public $errno = 0; }
$mk = function (int $no) { $e = new SahteAgHatasi('x'); $e->errno = $no; return $e; };
ok('errno 6/7/35/60 → kesin gitmedi', hks_belirsiz_kesin_gitmedi($mk(6)) && hks_belirsiz_kesin_gitmedi($mk(7)) && hks_belirsiz_kesin_gitmedi($mk(35)) && hks_belirsiz_kesin_gitmedi($mk(60)));
ok('errno 28 (zaman aşımı) / 52 / 56 / 0 → BELİRSİZ', !hks_belirsiz_kesin_gitmedi($mk(28)) && !hks_belirsiz_kesin_gitmedi($mk(52)) && !hks_belirsiz_kesin_gitmedi($mk(56)) && !hks_belirsiz_kesin_gitmedi($mk(0)));
ok('errno taşımayan genel istisna → BELİRSİZ (güvenli taraf)', !hks_belirsiz_kesin_gitmedi(new Exception('HTTP 504')));
ok('Throwable olmayan değer → false', !hks_belirsiz_kesin_gitmedi('x') && !hks_belirsiz_kesin_gitmedi(null));

echo "\n── audit (kişisel veri yok) ──\n";
$GLOBALS['AUDIT'] = [];
hks_belirsiz_audit('update', 'f1', [['id' => 'bgABC', 'plaka' => '33 NCN 99', 'gun' => $BUGUN]], ['bulunan_sayisi' => 2, 'kontrol' => 'kunye_var', 'tc' => '12345678901']);
$p = $GLOBALS['AUDIT'][0]['new'] ?? [];
ok('audit modül hks_belirsiz, plaka yalnız son 3 karakter', ($GLOBALS['AUDIT'][0]['module'] ?? '') === 'hks_belirsiz' && ($p['plaka_son3'] ?? '') === 'N99');
ok('audit payload beyaz liste: bilinmeyen anahtar (tc) yazılmaz', !array_key_exists('tc', $p) && ($p['bulunan_sayisi'] ?? 0) === 2 && !preg_match('/\d{9,}/', json_encode($p)));

echo "\n── kaynak denetimi: lib ──\n";
ok('lib çıktı basmaz / exit etmez', !preg_match('/\b(echo|print|exit|die)\b\s*[(\'"$]/', preg_replace('#//.*#', '', $kaynakLib)));
ok('lib taslak/Gönderilenler tablolarına yazmaz (INSERT/UPDATE/DELETE yok)', !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', preg_replace('#//.*#', '', $kaynakLib)));
ok("lib yasak alt dizeleri içermez (ayni_plaka, hks_gonderilen_)", strpos($kaynakLib, 'ayni_plaka') === false && strpos($kaynakLib, 'hks_gonderilen_') === false);
ok('lib HKS çağrısı yapmaz (SOAP/hks_toplu_kunye/hks_bildirim_kaydet yok — sorgu enjekte edilir)',
    !preg_match('/hks_toplu_kunye\(|hks_bildirim_kaydet|hks_soap_cagir|curl_/', preg_replace('#//.*#', '', $kaynakLib)));
$ht = (string)file_get_contents("$KOK/halkayit/.htaccess");
ok('.htaccess belirsiz_gonderim_lib.php\'yi iki sözdiziminde kapatır', substr_count($ht, 'belirsiz_gonderim_lib') >= 2);

echo "\n── kaynak denetimi: api.php taslak_gonder ──\n";
$api = (string)file_get_contents("$KOK/halkayit/api.php");
$pG = strpos($api, "case 'taslak_gonder':");
$pGSon = $pG !== false ? strpos($api, "case 'gonderilenler':", $pG) : false;
$g = ($pG !== false && $pGSon !== false) ? substr($api, $pG, $pGSon - $pG) : '';
ok('taslak_gonder gövdesi bulundu', $g !== '');
ok('hks_bildirim_kaydet çağrısı TEK (ikinci gönderim yolu yok)', substr_count($g, '= hks_bildirim_kaydet(') === 1 && substr_count($api, '= hks_bildirim_kaydet(') === 1);
$pReval = strpos($g, '$__revalHata = hks_bildirim_dogrula(');
$pKont = strpos($g, 'hks_belirsiz_oku_gunler(');
$pDeney = strpos($g, '$__satinKayitsiz = ');
$pKayit = strpos($g, '= hks_bildirim_kaydet(');
ok('kontrol bloğu: $__revalHata SONRASI, doğum-deneyi token\'ı tüketilmeden ÖNCE, gönderimden ÖNCE',
    $pReval !== false && $pKont !== false && $pDeney !== false && $pReval < $pKont && $pKont < $pDeney && $pDeney < $pKayit);
ok('deney token\'ı (hks_dogum_deney_iptal) kontrol bloğundan SONRA', strpos($g, 'hks_dogum_deney_iptal()') > $pKont);
ok('kontrol HKS\'e yalnız hks_toplu_kunye ile (salt okunur) sorar', strpos($g, 'hks_toplu_kunye($cfg, $gun, $pl, $bel)') !== false);
ok("taslak_gonder'de 'ayni_plaka' ve 'hks_gonderilen_' alt dizeleri yok", strpos($g, 'ayni_plaka') === false && strpos($g, 'hks_gonderilen_') === false);
ok('$taslagiGeriKoy closure\'ı parametresiz (kullanım: = function () use)', (bool)preg_match('/\$taslagiGeriKoy = function \(\) use \(\$db, \$t\) \{/', $g));
ok('kontrolün her 409 çıkışında taslak geri konur (kunye_var + kontrol_edilemedi)',
    (bool)preg_match("/\\\$taslagiGeriKoy\(\);\s*hks_json_cikti\(\[\s*'hata' => 'DURDURULDU: bu plaka için önceki gönderim belirsiz kalmıştı ve HKS\\\\'te Gönderilenler/s", $g)
    && (bool)preg_match("/\\\$taslagiGeriKoy\(\);\s*hks_json_cikti\(\[\s*'hata' => 'DURDURULDU: bu plaka için önceki gönderim belirsiz kalmıştı ve HKS\\\\'te künye olup olmadığı/s", $g));
ok("kontrol 409'ları: kod + belirsiz + taslakKorundu + isaretId",
    substr_count($g, "'belirsiz' => true, 'taslakKorundu' => true") === 3 && strpos($g, "'kod' => 'belirsiz_gonderim_kunye_var'") !== false
    && strpos($g, "'kod' => 'belirsiz_gonderim_kontrol_edilemedi'") !== false && strpos($g, "'kod' => 'belirsiz_gonderim'") !== false);
ok('belirsiz istisna yanıtı 502 DEĞİL 409 (proxy HTML 502 riski); yalnız kesin-gitmedi 502', (bool)preg_match("/'isaretId' => \\\$__isaret\['id'\] \?\? '',\s*\], 409\);/", $g)
    && substr_count($g, '], 502);') === 1);
ok('kesin gitmedi (hks_belirsiz_kesin_gitmedi) işaret YAZMADAN döner; diğer istisnalar işaretlenir',
    (bool)preg_match('/if \(hks_belirsiz_kesin_gitmedi\(\$__e\)\) \{.*?502\);\s*\}\s*\$__isaret = null;\s*try \{ \$__isaret = hks_belirsiz_isaretle_taslaktan\(/s', $g));
ok('bilinçli geçiş: kayıtlı işaret id\'siyle sunucuda doğrulanır (hks_belirsiz_onay_gecerli)', strpos($g, "hks_belirsiz_onay_gecerli(\$__bgIsaretler, \$g['belirsizOnay'] ?? '')") !== false);
ok('künye bulunduysa geçiş İKİNCİ onay ister (belirsizKunyeOnay)', strpos($g, "\$__bgOnay && !empty(\$g['belirsizKunyeOnay'])") !== false);
ok('geçiş audit\'e yazılır (hks_belirsiz_audit) — doğrudan audit_log_event yok', substr_count($g, 'hks_belirsiz_audit(') >= 2 && strpos($g, 'audit_log_event(') === false);
ok('CSRF merkezi kapıda (v418): taslak_gonder gövdesinde csrf_check yok, kapı switch öncesinde (taslak SAHİPLENİLMEDEN önce)',
    strpos($g, 'csrf_check(') === false
    && ($pc = strpos($api, 'csrf_check(hks_csrf_girdi($g))')) !== false && $pc < strpos($api, 'switch ($action)')
    && $pc < strpos($api, "case 'taslak_gonder':"));
ok('başarılı gönderimde işaret temizlenir (INSERT\'ten SONRA)',
    ($pT = strpos($g, 'hks_belirsiz_temizle_plaka(')) !== false && $pT > strpos($g, "INSERT INTO ' . hks_tablo('gonderilenler')"));
ok('yanıt_bos (ayrıştırılamayan yanıt) belirsiz işaretlenir', strpos($g, "hks_belirsiz_isaretle_taslaktan(\$t, 'yanit_bos'") !== false);
ok('$__bitti = true: en az bir künye oluştuktan sonra (count($basarili)===0 bloğundan SONRA)',
    ($pB = strpos($g, '$__bitti = true;')) !== false && $pB > strpos($g, 'if (count($basarili) === 0) {'));
ok('kapanış güvencesi: register_shutdown_function + taslak yoksa geri koy + HKS başladıysa işaretle',
    strpos($g, 'register_shutdown_function(') !== false && strpos($g, "hks_belirsiz_isaretle_taslaktan(\$t, 'sonlandi'") !== false);
ok('Gönderilenler INSERT\'i try/catch içinde (künye oluştuktan sonra 500 vermez) + kayitUyari döner',
    (bool)preg_match("/try \{\s*\\\$st = \\\$db->prepare\('INSERT INTO ' \. hks_tablo\('gonderilenler'\)/", $g) && strpos($g, "'kayitUyari' => \$kayitUyari") !== false);
ok('yanıta belirsizKontrol eklenir', strpos($g, "'belirsizKontrol' => \$__belirsizKontrol") !== false);

echo "\n── kaynak denetimi: taslaklar / belirsiz_isaret_kapat ──\n";
$pT1 = strpos($api, "case 'taslaklar':");
$pT2 = $pT1 !== false ? strpos($api, "case 'taslak_kaydet':", $pT1) : false;
$tg = ($pT1 !== false && $pT2 !== false) ? substr($api, $pT1, $pT2 - $pT1) : '';
ok('taslaklar ucu belirsizGonderim döner', strpos($tg, "'belirsizGonderim' => hks_belirsiz_taslak_icin(") !== false);
ok('taslaklar ucu yazma içermez (INSERT/UPDATE/DELETE/REPLACE/kv_yaz/kv_sil)', !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b|hks_kv_yaz|hks_kv_sil|temizle|isaretle/', $tg));
$pK1 = strpos($api, "case 'belirsiz_isaret_kapat':");
$pK2 = $pK1 !== false ? strpos($api, "case 'taslak_gonder':", $pK1) : false;
$kg = ($pK1 !== false && $pK2 !== false) ? substr($api, $pK1, $pK2 - $pK1) : '';
ok('belirsiz_isaret_kapat bulundu', $kg !== '');
ok('kapat: CSRF (merkezi kapı, muaf listede DEĞİL) + firma izolasyonu (yalnız firmanın listesinde) + 404 + audit',
    strpos($api, "'belirsiz_isaret_kapat'") !== false && !preg_match('/function hks_csrf_muaf_eylemler\(\): array \{[^}]*belirsiz_isaret_kapat/s', $api)
    && strpos($kg, 'hks_belirsiz_liste_firma($fid') !== false && strpos($kg, ', 404)') !== false && strpos($kg, 'hks_belirsiz_audit(') !== false);
ok('kapat: gönderim yapmaz, taslağa dokunmaz (hks_ / tablo yazımı yok)', !preg_match('/hks_bildirim_kaydet|hks_toplu_kunye|hks_tablo\(/', $kg));
ok("api.php lib'i yüklüyor", strpos($api, "require_once __DIR__ . '/belirsiz_gonderim_lib.php';") !== false);
ok('taslak_sil işareti SİLMEZ (plaka+gün anahtarlı; taslağa bağlı değil)', (function () use ($api) {
    $a = strpos($api, "case 'taslak_sil':"); $b = strpos($api, "case 'belirsiz_isaret_kapat':", $a);
    return $a !== false && $b !== false && strpos(substr($api, $a, $b - $a), 'hks_belirsiz_') === false; })());

echo "\n── kaynak denetimi: hks_soap.php / db.php / app.html ──\n";
$soap = (string)file_get_contents("$KOK/halkayit/hks_soap.php");
ok('HksAgHatasi sınıfı errno taşır; mesaj metinleri aynı', strpos($soap, 'class HksAgHatasi extends Exception') !== false
    && strpos($soap, "'Bağlantı hatası: ' . \$hata") !== false && strpos($soap, 'curl_errno($ch)') !== false);
ok('hks_bildirim_kaydet_tek yalnız hks_bildirim_kaydet\'ten çağrılır (değişmedi)', substr_count($soap, '= hks_bildirim_kaydet_tek(') === 1);
$dbp = (string)file_get_contents("$KOK/halkayit/db.php");
ok('db.php hks_kv_sil + hks_kv_onekli tanımlı', strpos($dbp, 'function hks_kv_sil(') !== false && strpos($dbp, 'function hks_kv_onekli(') !== false);
$app = (string)file_get_contents("$KOK/halkayit/app.html");
ok('app: rota belirsiz_isaret_kapat', strpos($app, "'POST /api/belirsiz_isaret_kapat': 'belirsiz_isaret_kapat'") !== false);
ok('app: api() hata nesnesine govde/durum ekler + HTML yanıtı anlaşılır mesaja çevirir',
    strpos($app, 'hx.govde = j || {}') !== false && strpos($app, 'Sunucu beklenmeyen bir yanıt verdi (HTTP') !== false);
ok('app: kartta rozet + "HKS\'te kontrol ettim" düğmesi', strpos($app, 'tk-uyari tk-belirsiz') !== false && strpos($app, 'data-belirsiz-kapat') !== false);
ok('app: 409 belirsiz kalıcı kutuda (belirsizDurduruldu), alert değil', strpos($app, 'function belirsizDurduruldu(') !== false
    && strpos($app, "_g.kod === 'belirsiz_gonderim_kunye_var'") !== false);
ok('app: onay düğmesi iki onay kutusu işaretlenmeden pasif (bgOnay1/bgOnay2)', strpos($app, 'id="bgOnay1"') !== false && strpos($app, 'id="bgOnay2"') !== false
    && strpos($app, '!($(\'bgOnay1\').checked') !== false);
ok('app: bilinçli geçiş gövdesi belirsizOnay + belirsizKunyeOnay', strpos($app, '_govde.belirsizOnay = belirsizOnay.isaretId') !== false
    && strpos($app, '_govde.belirsizKunyeOnay = true') !== false);
ok('app: HKS verisi (bulunan künyeler) eHtml ile basılır', strpos($app, 'eHtml(x.kunyeNo)') !== false && strpos($app, 'eHtml(x.bildirimci)') !== false);
ok('app: koyu tema karşılıkları', strpos($app, 'html[data-theme="dark"] .belirsiz-kutu') !== false && strpos($app, 'html[data-theme="dark"] .tk-satir .tk-uyari.tk-belirsiz') !== false);
ok('app: rozet kart satır SAYISINI değiştirmez (plaka satırının içinde)', substr_count($app, '_satir(') >= 7 && (bool)preg_match("/_satir\('tk-plaka'.*?tk-belirsiz/s", $app));
ok('app: Sil onayı işaretli taslakta ek uyarı', strpos($app, 'önceki gönderim BELİRSİZ. Taslağı silip') !== false);
ok('app: kartta düğme ızgarası (tk-butonlar 4 düğme) değişmedi', substr_count($app, 'data-gonder="${t.id}"') === 1 && substr_count($app, '<button class="btn btn-soluk" data-') === 3);

echo $fail === 0 ? "\nTÜM TESTLER GEÇTİ\n" : "\n$fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
