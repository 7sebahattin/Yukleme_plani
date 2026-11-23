<?php
// =========================================================
// scripts/hks_rusum_borc_smoke.php — Hal Kayıt Rüsum Borcu (v566)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: halkayit/rusum_borc_lib.php'nin
// GERÇEK fonksiyonları + hks_kv için bellek içi taklit. HKS yanıtı sentetik XML.
//
//   php scripts/hks_rusum_borc_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);

// hks_kv taklidi (gerçek db.php MySQL'e bağlanır) — lib yalnız bu iki işlevi kullanır.
$GLOBALS['KV'] = [];
function hks_kv_oku($a, $v = null) { return $GLOBALS['KV'][$a] ?? $v; }
function hks_kv_yaz($a, $d) { $GLOBALS['KV'][$a] = json_decode(json_encode($d), true); }

require_once "$KOK/halkayit/rusum_borc_lib.php";

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-84s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}
$j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);

// ── 1. Son ödeme = 5 iş günü (HKS portalı ekran görüntüsündeki 20 satır) ──
$portal = ['2026-10-20' => '2026-10-27', '2026-10-21' => '2026-10-28', '2026-10-22' => '2026-10-30', '2026-10-24' => '2026-11-02',
    '2026-10-28' => '2026-11-05', '2026-10-29' => '2026-11-05', '2026-10-30' => '2026-11-06', '2026-10-31' => '2026-11-06',
    '2026-11-01' => '2026-11-06', '2026-11-02' => '2026-11-09', '2026-11-03' => '2026-11-10', '2026-11-04' => '2026-11-11',
    '2026-11-05' => '2026-11-12', '2026-11-06' => '2026-11-13', '2026-11-07' => '2026-11-13', '2026-11-08' => '2026-11-13',
    '2026-11-09' => '2026-11-16', '2026-11-13' => '2026-11-20', '2026-11-14' => '2026-11-20', '2026-11-15' => '2026-11-20'];
$yanlis = [];
foreach ($portal as $t => $b) { $g = hks_rusum_son_odeme($t); if ($g !== $b) $yanlis[] = "$t: $g (beklenen $b)"; }
ok('son ödeme: portaldaki 20 satırın hepsi tutuyor', !$yanlis, $j($yanlis));
ok('son ödeme: geçersiz tarih → boş', hks_rusum_son_odeme('2026-02-30') === '' && hks_rusum_son_odeme('x') === '');
ok('son ödeme: tatil listesi verilmezse varsayılan; boş liste yalnız hafta sonu atlar',
   hks_rusum_son_odeme('2026-10-22', []) === '2026-10-29');
ok('son ödeme: Kurban Bayramı 2026 atlanır (22.05 Cuma → 03.06)', hks_rusum_son_odeme('2026-05-22') === '2026-06-03', hks_rusum_son_odeme('2026-05-22'));
ok('tatil listesi: biçim yyyy-mm-dd ve tekrarsız olmasa da hepsi geçerli tarih',
   count(array_filter(hks_rusum_tatiller(), fn($d) => DateTime::createFromFormat('!Y-m-d', $d) && DateTime::createFromFormat('!Y-m-d', $d)->format('Y-m-d') === $d)) === count(hks_rusum_tatiller()));

// ── 2. Grup kodu ──
ok('grup kodu: portal biçimi', hks_rusum_grup_kodu('0091880872', '2026-10-20', '2026-10-27') === '0091880872-20261020-20261027');
ok('grup kodu: VKN 10 haneye soldan sıfırlanır, rakam dışı atılır', hks_rusum_grup_kodu('91 880-872', '2026-10-20', '2026-10-27') === '0091880872-20261020-20261027');
ok('grup geçerli: iyi', hks_rusum_grup_gecerli('0091880872-20261020-20261027'));
ok('grup geçerli: kötü biçimler reddedilir', !hks_rusum_grup_gecerli("1' OR 1=1") && !hks_rusum_grup_gecerli('') && !hks_rusum_grup_gecerli('0091880872-2026102-20261027'));

// ── 3. Yanıt ayrıştırma (sentetik HKS XML) ──
function xml_dto(string $kunye, string $tarih, string $rusum): string {
    return "<BildirimSorguDTO><BildirimTarihi>{$tarih}</BildirimTarihi><KunyeNo>{$kunye}</KunyeNo><RusumMiktari>{$rusum}</RusumMiktari></BildirimSorguDTO>";
}
$yanit = '<Body><X><IslemKodu>GTBWSRV0000001</IslemKodu><Sonuc><Bildirimler>'
    . xml_dto('111', '2026-10-20T09:15:00', '1000.5') . xml_dto('112', '2026-10-20T15:00:00', '899.9') . xml_dto('113', '2026-10-21T10:00:00', '0')
    . xml_dto('', '2026-10-21T10:00:00', '5') . '</Bildirimler></Sonuc></X></Body>';
$satir = hks_rusum_yanit_ayikla($yanit);
ok('ayıkla: künyesiz satır atılır, 3 satır', count($satir) === 3, $j($satir));
ok('ayıkla: tarih ilk 10 karakter, rüsum float', $satir[0]['tarih'] === '2026-10-20' && abs($satir[0]['rusum'] - 1000.5) < 1e-9, $j($satir[0]));
$hataVar = false;
try { hks_rusum_yanit_ayikla('<Body><IslemKodu>X</IslemKodu><ErrorModel><HataKodu>5</HataKodu><Mesaj>Yetki yok</Mesaj></ErrorModel></Body>'); }
catch (Exception $e) { $hataVar = str_contains($e->getMessage(), 'Yetki yok'); }
ok('ayıkla: HKS hata modeli → Exception (mesaj korunur)', $hataVar);

// ── 4. Gruplama ──
$g = hks_rusum_grupla($satir, '0091880872');
ok('grupla: yalnız rüsumu > 0 olan gün (20.10), toplam 1900,40', count($g['gruplar']) === 1 && abs($g['gruplar'][0]['tutar'] - 1900.4) < 1e-9, $j($g));
ok('grupla: adet 2, grup kodu + son ödeme', $g['gruplar'][0]['adet'] === 2 && $g['gruplar'][0]['grup'] === '0091880872-20261020-20261027' && $g['gruplar'][0]['sonOdeme'] === '2026-10-27');
ok('grupla: bildirim 3, rüsumlu 2', $g['bildirim'] === 3 && $g['rusumlu'] === 2, $j($g));
$g2 = hks_rusum_grupla([
    ['kunyeNo' => 'a', 'tarih' => '2026-11-03', 'rusum' => 10.0], ['kunyeNo' => 'b', 'tarih' => '2026-10-30', 'rusum' => 20.0],
    ['kunyeNo' => 'c', 'tarih' => 'bozuk', 'rusum' => 99.0], ['kunyeNo' => 'd', 'tarih' => '2026-10-30', 'rusum' => -5.0],
], '1234567890');
ok('grupla: eski → yeni sıralı, bozuk tarih ve negatif rüsum yok sayılır', $j(array_column($g2['gruplar'], 'tahakkuk')) === '["2026-10-30","2026-11-03"]', $j($g2));

// ── 5. Çekme (sahte çağrıcı): pencere bölme, künye türü 1+2, tekilleştirme, kısmi hata ──
$cagrilar = [];
$cagir = function (DateTime $b, DateTime $e, int $tur) use (&$cagrilar) {
    $cagrilar[] = [$b->format('Y-m-d'), $e->format('Y-m-d'), $tur];
    return [['kunyeNo' => 'K1', 'tarih' => '2026-10-20', 'rusum' => 100.0]];   // her çağrıda aynı künye → tek sayılır
};
$bas = new DateTime('2026-09-01'); $bit = new DateTime('2026-10-20');
$c = hks_rusum_tahakkuk_cek(null, $bas, $bit, $cagir);
ok('çek: 50 gün → 2 pencere × 2 künye türü = 4 çağrı', $c['cagri'] === 4 && count($cagrilar) === 4, $j($cagrilar));
ok('çek: türler 1 ve 2 ikisi de sorgulanır', count(array_unique(array_column($cagrilar, 2))) === 2);
ok('çek: aynı künye tekilleştirilir (rüsüm iki kez sayılmaz)', count($c['satirlar']) === 1 && $c['kismiHata'] === 0, $j($c));
$say = 0;
$c2 = hks_rusum_tahakkuk_cek(null, $bas, $bit, function ($b, $e, $t) use (&$say) { if (++$say === 2) throw new Exception('zaman aşımı'); return [['kunyeNo' => "k$say", 'tarih' => '2026-10-20', 'rusum' => 1.0]]; });
ok('çek: bir çağrı hata verirse kalan sonuçlar korunur, kismiHata sayılır', $c2['kismiHata'] === 1 && count($c2['satirlar']) === 3, $j($c2));
$hepsi = false;
try { hks_rusum_tahakkuk_cek(null, $bas, $bit, function () { throw new Exception('HKS kapalı'); }); } catch (Exception $e) { $hepsi = $e->getMessage() === 'HKS kapalı'; }
ok('çek: hiçbir çağrı başarılı değilse hata fırlatılır', $hepsi);
$cok = false;
try { hks_rusum_tahakkuk_cek(null, new DateTime('2020-01-01'), new DateTime('2026-10-20'), $cagir); } catch (Exception $e) { $cok = true; }
ok('çek: aşırı geniş aralık reddedilir (pencere sayısı > 8)', $cok);

// ── 6. Ödendi işareti ──
$F = 'firma-1';
ok('ödeme: boş başlangıç', hks_rusum_odeme_oku($F) === []);
ok('ödeme: yaz → okunur', hks_rusum_odeme_yaz($F, '0091880872-20261020-20261027', true, 'Ali Veli', '2026-10-25')
   && hks_rusum_odeme_oku($F)['0091880872-20261020-20261027']['t'] === '2026-10-25');
ok('ödeme: kullanıcı adı kaydedilir', hks_rusum_odeme_oku($F)['0091880872-20261020-20261027']['k'] === 'Ali Veli');
ok('ödeme: geçersiz grup yazılmaz', hks_rusum_odeme_yaz($F, "x' OR 1", true) === false && count(hks_rusum_odeme_oku($F)) === 1);
ok('ödeme: başka firmanın işareti ayrı anahtarda', hks_rusum_odeme_oku('firma-2') === [] && hks_rusum_odeme_anahtar('firma-1') !== hks_rusum_odeme_anahtar('firma-2'));
ok('ödeme: anahtar kısa ve güvenli karakterli', preg_match('/^rod_[0-9a-f]{12}$/', hks_rusum_odeme_anahtar("f1' OR 1=1")) === 1);
ok('ödeme: geri al → kalkar', hks_rusum_odeme_yaz($F, '0091880872-20261020-20261027', false) && hks_rusum_odeme_oku($F) === []);

// ── 6b. Toplu işaret (v568) ──
$G1 = '0091880872-20261020-20261027'; $G2 = '0091880872-20261021-20261028'; $G3 = '0091880872-20261022-20261030';
$FT = 'firma-toplu';
ok('toplu: 3 grup tek çağrıda işaretlenir (3 döner)', hks_rusum_odeme_toplu_yaz($FT, [$G1, $G2, $G3], true, 'Ali', '2026-11-20') === 3 && count(hks_rusum_odeme_oku($FT)) === 3);
ok('toplu: kayıtta tarih + kullanıcı', hks_rusum_odeme_oku($FT)[$G2] === ['t' => '2026-11-20', 'k' => 'Ali']);
ok('toplu: 2 grup geri alınır, 1 kalır', hks_rusum_odeme_toplu_yaz($FT, [$G1, $G3], false) === 2 && array_keys(hks_rusum_odeme_oku($FT)) === [$G2]);
ok('toplu: biri geçersizse HİÇBİRİ yazılmaz (-1)', hks_rusum_odeme_toplu_yaz($FT, [$G1, 'bozuk'], true) === -1 && array_keys(hks_rusum_odeme_oku($FT)) === [$G2]);
ok('toplu: boş liste → -1', hks_rusum_odeme_toplu_yaz($FT, [], true) === -1);
ok('toplu: dizi dışı değer → -1', hks_rusum_odeme_toplu_yaz($FT, [['x']], true) === -1);
$cokluGrup = []; for ($i = 0; $i < HKS_RUSUM_TOPLU_LIMIT + 1; $i++) $cokluGrup[] = '0091880872-2026' . str_pad((string)(1000 + $i), 4, '0', STR_PAD_LEFT) . '-20261027';
ok('toplu: tek çağrıda limit (' . HKS_RUSUM_TOPLU_LIMIT . ') aşılırsa -1', hks_rusum_odeme_toplu_yaz($FT, $cokluGrup, true) === -1);
ok('toplu: aynı grup tekrarı tek sayılır', hks_rusum_odeme_toplu_yaz($FT, [$G1, $G1], true) === 1);

// ── 6c. Önbellek + ana ekran kartı özeti (v568) ──
$FK = 'firma-kart';
ok('özet: önbellek yokken null (kart "Hesaplamak için dokunun")', hks_rusum_ozet($FK, '2026-11-23') === null);
$grl = [
    ['grup' => $G1, 'tahakkuk' => '2026-10-20', 'sonOdeme' => '2026-10-27', 'tutar' => 1900.4, 'adet' => 2],
    ['grup' => $G2, 'tahakkuk' => '2026-10-21', 'sonOdeme' => '2026-10-28', 'tutar' => 51304.2, 'adet' => 5],
    ['grup' => '0091880872-20261120-20261127', 'tahakkuk' => '2026-11-20', 'sonOdeme' => '2026-11-27', 'tutar' => 1000.0, 'adet' => 1],
];
hks_rusum_onbellek_yaz($FK, $grl, '2026-05-23', '2026-11-23', '2026-11-23T14:00:00+03:00');
$oz = hks_rusum_ozet($FK, '2026-11-23');
ok('özet: vadesi geçmiş 53.204,60 · toplam borç 54.204,60', abs($oz['vadesiGecmis'] - 53204.6) < 0.005 && abs($oz['toplam'] - 54204.6) < 0.005, $j($oz));
ok('özet: sorgu aralığı + zamanı taşır, taze', $oz['bas'] === '2026-05-23' && $oz['bit'] === '2026-11-23' && $oz['zaman'] === '2026-11-23T14:00:00+03:00' && $oz['eski'] === false);
hks_rusum_odeme_yaz($FK, $G2, true, 'Ali', '2026-11-23');
$oz2 = hks_rusum_ozet($FK, '2026-11-23');
ok('özet: ödendi işaretleyince kart HKS\'siz güncellenir (51.304,20 düşer)', abs($oz2['vadesiGecmis'] - 1900.4) < 0.005 && abs($oz2['toplam'] - 2900.4) < 0.005, $j($oz2));
$oz3 = hks_rusum_ozet($FK, '2026-11-28');
ok('özet: vade günleri geçtikçe borç vadesi geçmişe döner (28.11: 1.000,00 da vadesi geçti)', abs($oz3['vadesiGecmis'] - 2900.4) < 0.005, $j($oz3));
ok('özet: 2 günden eski önbellek "eski" işaretlenir', hks_rusum_ozet($FK, '2026-11-26')['eski'] === true && hks_rusum_ozet($FK, '2026-11-24')['eski'] === false);
hks_rusum_onbellek_yaz('firma-kotu', [['grup' => "x' OR 1", 'tahakkuk' => '2026-10-20', 'sonOdeme' => '2026-10-27', 'tutar' => 5.0]], 'a', 'b');
ok('önbellek: geçersiz grup satırı yazılmaz', hks_rusum_onbellek_oku('firma-kotu')['g'] === []);
ok('önbellek: firma başına ayrı anahtar, kısa ve güvenli', hks_rusum_ozet('baska-firma', '2026-11-23') === null && preg_match('/^rbg_[0-9a-f]{12}$/', hks_rusum_onbellek_anahtar("f1' OR 1=1")) === 1);
ok('önbellek yazım uygunluğu: 6 aylık, bugüne yakın sorgu EVET', hks_rusum_onbellege_uygun('2026-05-23', '2026-11-23', '2026-11-23'));
ok('önbellek yazım uygunluğu: bitişi 3 gün önce hâlâ EVET', hks_rusum_onbellege_uygun('2026-05-20', '2026-11-20', '2026-11-23'));
ok('önbellek yazım uygunluğu: kısa aralık (30 gün) HAYIR — kart eksik toplam göstermesin', !hks_rusum_onbellege_uygun('2026-10-24', '2026-11-23', '2026-11-23'));
ok('önbellek yazım uygunluğu: eski aralık (bitiş 10 gün önce) HAYIR', !hks_rusum_onbellege_uygun('2026-04-01', '2026-11-13', '2026-11-23'));
ok('önbellek yazım uygunluğu: bozuk tarih HAYIR', !hks_rusum_onbellege_uygun('x', '2026-11-23', '2026-11-23'));

// ── 7. Durum + toplamlar ──
$gr = [
    ['grup' => '0091880872-20261020-20261027', 'tahakkuk' => '2026-10-20', 'sonOdeme' => '2026-10-27', 'tutar' => 1900.4, 'adet' => 2],
    ['grup' => '0091880872-20261021-20261028', 'tahakkuk' => '2026-10-21', 'sonOdeme' => '2026-10-28', 'tutar' => 51304.2, 'adet' => 5],
    ['grup' => '0091880872-20261113-20261120', 'tahakkuk' => '2026-11-13', 'sonOdeme' => '2026-11-20', 'tutar' => 55902.4, 'adet' => 3],
    ['grup' => '0091880872-20261120-20261127', 'tahakkuk' => '2026-11-20', 'sonOdeme' => '2026-11-27', 'tutar' => 1000.0, 'adet' => 1],
];
$odeme = ['0091880872-20261021-20261028' => ['t' => '2026-10-30', 'k' => 'x']];
$d = hks_rusum_durumlari_isle($gr, $odeme, '2026-11-23');
$dur = array_column($d['gruplar'], 'durum');
ok('durum: ödendi / vadesi geçti / vadesi geçti / ödenmedi', $dur === ['gecikmis', 'odendi', 'gecikmis', 'odenmedi'], $j($dur));
ok('durum: vade günü bugünse henüz gecikmiş değil', hks_rusum_durum(['grup' => 'g', 'sonOdeme' => '2026-11-23'], [], '2026-11-23') === 'odenmedi');
$t = $d['toplam'];
ok('toplam: tutar 110107,00', abs($t['tutar'] - 110107.0) < 0.005, $j($t));
ok('toplam: ödenmiş 51304,20', abs($t['odendi'] - 51304.2) < 0.005, $j($t));
ok('toplam: ödenmemiş 58802,80 (gecikmiş 57802,80 dahil)', abs($t['odenmedi'] - 58802.8) < 0.005 && abs($t['gecikmis'] - 57802.8) < 0.005, $j($t));
ok('toplam: ödenmiş + ödenmemiş = toplam', abs($t['odendi'] + $t['odenmedi'] - $t['tutar']) < 0.005);
ok('odemeTarihi işlenir', $d['gruplar'][1]['odemeTarihi'] === '2026-10-30' && $d['gruplar'][0]['odemeTarihi'] === '');

// ── 8. Biçim ──
ok('biçim: 1900,4 → 1.900,40', hks_rusum_tl(1900.4) === '1.900,40');
ok('biçim: tarih gg.aa.yyyy', hks_rusum_tarih('2026-10-20') === '20.10.2026' && hks_rusum_tarih('x') === 'x');

// ── 9. Statik değişmezler ──
$sayfa = (string)file_get_contents("$KOK/halkayit/rusum_borc.php");
$lib = (string)file_get_contents("$KOK/halkayit/rusum_borc_lib.php");
ok('sayfa: giriş + hks.access + hks.read + içerik kapısı', str_contains($sayfa, "require_login()") && str_contains($sayfa, "require_perm('hks.access')")
   && str_contains($sayfa, "role_hks_can('read')") && str_contains($sayfa, "icerik_kapi('hks.rusum_borc')"));
ok('sayfa: POST csrf_check + hks.edit + audit', str_contains($sayfa, 'csrf_check(') && str_contains($sayfa, "role_hks_can('edit')") && str_contains($sayfa, "audit_log_event('hks_rusum_odeme'"));
ok('sayfa: audit VKN yazmaz (yalnız adet + tahakkuk günü aralığı)', str_contains($sayfa, 'substr($g, -17, 8)') && str_contains($sayfa, "'tahakkuk_ilk'") && !preg_match("/audit_log_event\([^;]*vergi/i", $sayfa));
ok('sayfa: HKS yazma/gönderim fonksiyonu YOK (salt okunur sorgu)', !preg_match('/hks_bildirim_kaydet|taslak_gonder|hks_taslak_olustur/', $sayfa . $lib));
ok('sayfa: DDL / migration YOK (şema değişmez)', !preg_match('/CREATE TABLE|ALTER TABLE/i', $sayfa . $lib));
ok('sayfa: yalnız hks_kv\'ye yazar', str_contains($lib, 'hks_kv_yaz(') && !preg_match('/INSERT INTO|UPDATE \w+ SET|DELETE FROM/i', $sayfa . $lib));
ok('sayfa: sarı uyarı bandı yok; yalnız "Ödemeleri manuel işaretleyiniz." (sahip kararı v568)', !str_contains($sayfa, 'Resmî borç sorgusu değildir') && str_contains($sayfa, 'Ödemeleri manuel işaretleyiniz.'));
ok('sayfa: varsayılan başlangıç son 6 ay', str_contains($sayfa, "' -6 months'"));
ok('sayfa: Sorgula Firma ile aynı satırda (rb-f-btn firma/tarihlerle aynı flex kabında)', preg_match('/rb-izgara.*rb-f-firma.*rb-f-tarih.*rb-f-tarih.*rb-f-btn/s', $sayfa) === 1);
ok('sayfa: toplu işaret grup[] + tek kv yazımı (hks_rusum_odeme_toplu_yaz)', str_contains($sayfa, 'hks_rusum_odeme_toplu_yaz(') && str_contains($sayfa, "fd.append('grup[]'"));
ok('sayfa: yazdırma kart düzenini geri alır (table-row !important) ve seçim/Bildirim/grup sütunlarını gizler', str_contains($sayfa, 'display: table-row !important') && str_contains($sayfa, '.rb-tablo .rb-col-sec'));
ok('sayfa: önbellek yalnız eksiksiz + tam aralıklı sorguda yazılır', str_contains($sayfa, 'hks_rusum_onbellege_uygun(') && str_contains($sayfa, "kismiHata'] > 0"));
ok('sayfa: no-store', str_contains($sayfa, 'no-store'));
ok('lib: HKS çağrısı yalnız BildirimcininYaptigiBildirimListesi (WSDL\'de var)', substr_count($lib, "hks_soap_cagir(") === 1
   && str_contains($lib, "'BildirimServisBildirimcininYaptigiBildirimListesi'"));
ok('.htaccess: lib doğrudan çağrıya kapalı', str_contains((string)file_get_contents("$KOK/halkayit/.htaccess"), 'rusum_borc_lib'));
ok('sw.js: sayfa önbelleğe alınmaz', str_contains((string)file_get_contents("$KOK/sw.js"), 'rusum_borc'));
$api = (string)file_get_contents("$KOK/halkayit/api.php");
ok("api.php: 'rusum_ozet' salt okunur (CSRF muaf listesinde) ve case'i var", preg_match("/hks_csrf_muaf_eylemler\(\): array \{.*?'rusum_ozet'.*?\n\}/s", $api) === 1 && str_contains($api, "case 'rusum_ozet':"));
ok("api.php: rusum_ozet içerik yetkisi ui.hks.rusum_borc'a bağlı", str_contains($api, "'rusum_ozet' => 'rusum_borc'"));
preg_match("/case 'rusum_ozet': \{.*?\n    \}/s", $api, $__rc);
ok('api.php: rusum_ozet HKS çağırmaz / yazmaz (yalnız önbellek okur)', isset($__rc[0]) && str_contains($__rc[0], 'hks_rusum_ozet(') && !preg_match('/hks_soap_cagir|hks_rusum_pencere|hks_rusum_tahakkuk_cek|hks_kv_yaz|INSERT|UPDATE|DELETE/', $__rc[0]));
ok('api.php: rusum_borc_lib yüklü', str_contains($api, "require_once __DIR__ . '/rusum_borc_lib.php'"));
$app = (string)file_get_contents("$KOK/halkayit/app.html");
ok('app.html: Borç Sorgula düğmesi + içerik gizleme + firma bağlama', str_contains($app, 'id="btnRusumBorc"') && str_contains($app, 'body.icx-rusum_borc #btnRusumBorc')
   && str_contains($app, "'rusum_borc.php' + (/^[A-Za-z0-9_"));
ok('app.html: sağ üst Rüsum Borcu kartı + rota + içerik gizleme', str_contains($app, 'id="rusumKart"') && str_contains($app, "'POST /api/rusum_ozet': 'rusum_ozet'") && str_contains($app, 'body.icx-rusum_borc #rusumKart')
   && str_contains($app, 'Toplam borç: ') && str_contains($app, 'Vadesi geçmiş rüsum borcu'));
ok('rol kataloğu: ui.hks.rusum_borc', str_contains((string)file_get_contents("$KOK/config/role_content.php"), "'ui.hks.rusum_borc'"));
preg_match("/define\('APP_SURUM', '(v\d+)'\)/", (string)file_get_contents("$KOK/config/helpers.php"), $m1);
preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", (string)file_get_contents("$KOK/sw.js"), $m2);
ok('sürüm: APP_SURUM == sw.js CACHE_NAME', ($m1[1] ?? 'a') === ($m2[1] ?? 'b'), ($m1[1] ?? '?') . ' / ' . ($m2[1] ?? '?'));

echo $fail ? "\n$fail test BAŞARISIZ\n" : "\nTüm testler geçti\n";
exit($fail ? 1 : 0);
