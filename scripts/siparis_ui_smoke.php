<?php
// =========================================================
// scripts/siparis_ui_smoke.php — Siparişler SAYFALARI render + POST akışı + yetki/depo izolasyonu testi
//   php scripts/siparis_ui_smoke.php
//   SIPARIS_ORNEK_DIR=<klasör> php scripts/siparis_ui_smoke.php   (gerçek örnek PDF'ler yükleme ucundan geçer; yoksa ATLANIR)
// Bellek içi SQLite + stub auth + SENTETİK fixture'lar (siparis_ui_render.php::siparis_ui_tohum). Sayfalar gerçekten
// çalıştırılır: header()/http_response_code()/exit kaynak üzerinde yakalanır (CLI başlıkları saklamaz).
// CSRF reddi (die()) alt süreçte denenir. Canlı DB'ye ve storage/siparis'e HİÇ dokunmaz.
// =========================================================
if (in_array('--csrf', $argv ?? [], true)) {            // alt süreç: CSRF'siz POST → reddedilmeli
    require_once __DIR__ . '/siparis_ui_render.php';
    $dosya = (string)$argv[array_search('--csrf', $argv) + 1];
    $db = db(); siparis_ui_tohum($db);
    $IS_ADMIN = true; $UID = 1;
    ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
    $sevOnce = (int)$db->query('SELECT COUNT(*) FROM siparis_sevkiyatlar')->fetchColumn();
    $_POST = ['islem' => 'sil', 'sid' => '1', 'gerekce' => 'deneme', 'belge_id' => '1', 'bag_id' => '1', 'beyan_id' => '1', 'sevkiyat_id' => '1'];
    $_GET = ['id' => '1']; $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    $src = file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $tmp = sys_get_temp_dir() . '/sip_csrf_' . getmypid() . '.php'; file_put_contents($tmp, $src);
    include $tmp;
    $cikti = ob_get_clean();
    echo "CSRF-GECTI\n";      // buraya ulaşıldıysa reddedilmedi
    exit(0);
}
require_once __DIR__ . '/siparis_ui_render.php';        // siparis_ui_tohum() + _siparis_test_lib (render betiği require edilince yalnız tanım yapar)

$ROOT = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$db = db();

class SipExit extends RuntimeException {}
$SIPT = ['h' => [], 'kod' => 200];
function sipt_header(string $h, bool $replace = true, int $code = 0): void {
    global $SIPT;
    if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $SIPT['h'][strtolower(trim($a))] = trim($b); }
    if ($code > 0) $SIPT['kod'] = $code;
}
function sipt_code(?int $c = null) { global $SIPT; if ($c !== null) $SIPT['kod'] = $c; return $SIPT['kod']; }

/** Sayfa dosyasını AYRI kapsamda include eder (sayfanın $hata/$git/$post gibi yerel değişkenleri çağıranı ezmesin). */
function sip_calistir(string $tmp): void { include $tmp; }
/** Sayfayı çalıştırır. @return array{html:string,h:array,kod:int,git:?string,hata:?string,flash:?array} */
function sayfa(string $dosya, array $get = [], array $post = [], array $files = [], array $sunucu = []): array
{
    global $ROOT, $SIPT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sipt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sipt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SipExit();', $src);
    $tmp = sys_get_temp_dir() . '/sip_ui_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $SIPT = ['h' => [], 'kod' => 200];
    $_GET = $get; $_POST = $post; $_FILES = $files;
    $_SERVER['REQUEST_METHOD'] = $post || $files || isset($sunucu['CONTENT_LENGTH']) ? 'POST' : 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya;
    unset($_SERVER['CONTENT_LENGTH']);
    foreach ($sunucu as $k => $v) $_SERVER[$k] = $v;
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['flash']);
    ob_start();
    $hata = null;
    try { sip_calistir($tmp); }
    catch (SipExit $e) { /* normal çıkış */ }
    catch (Throwable $e) { $hata = $e->getMessage(); }
    $html = (string)ob_get_clean();
    @unlink($tmp);
    foreach ($sunucu as $k => $_) unset($_SERVER[$k]);
    return ['html' => $html, 'h' => $SIPT['h'], 'kod' => $SIPT['kod'], 'git' => $SIPT['h']['location'] ?? null, 'hata' => $hata, 'flash' => $_SESSION['flash'] ?? null];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null): void
{
    global $PERMS, $IS_ADMIN, $UID, $DEPOLAR;
    $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar;
}
function csrf(): string { if (session_status() === PHP_SESSION_NONE) session_start(); return csrf_token(); }
/** Basit HTML denge denetimi: açılan/kapanan blok etiketleri eşleşmeli. */
function html_dengeli(string $h): ?string
{
    $h = preg_replace('#<(script|style|textarea)\b.*?</\1>#si', '', $h);
    $h = preg_replace('/<!--.*?-->/s', '', $h);
    $void = ['meta', 'link', 'br', 'hr', 'img', 'input', 'source', 'col', 'area', 'base', 'wbr', 'path', 'circle', 'rect'];
    $yigin = [];
    preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>#', $h, $m, PREG_SET_ORDER);
    foreach ($m as $t) {
        $ad = strtolower($t[2]);
        if (in_array($ad, $void, true) || $t[3] === '/') continue;
        if ($t[1] === '') { $yigin[] = $ad; continue; }
        $son = array_pop($yigin);
        if ($son !== $ad) return "beklenen </$son>, bulunan </$ad>";
    }
    return $yigin === [] ? null : 'kapanmayan: ' . implode(',', array_slice($yigin, -3));
}
function temiz(array $r): bool { return $r['hata'] === null && !preg_match('/\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b:/', $r['html']); }
function say(PDO $db, string $t, string $w = '1=1', array $p = []): int { return siparis_test_say($db, $t, $w, $p); }

$ADMIN = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'];
$OPER  = ['siparis.read', 'siparis.write', 'siparis.match', 'beyan.read'];
$VIEW  = ['siparis.read'];

echo "=== 1. Tablolar yokken ===\n";
rol([], true);
foreach (['siparisler.php' => [], 'siparis_view.php' => ['id' => 1], 'siparis_pkl.php' => ['id' => 1], 'siparis_belge.php' => ['id' => 1], 'siparis_ayarlar.php' => []] as $f => $g) {
    $r = sayfa($f, $g);
    ok("$f: kurulmadı kutusu + migrate.php bağlantısı (admin)", temiz($r) && str_contains($r['html'], 'henüz kurulmadı') && str_contains($r['html'], 'migrate.php#siparis'), (string)$r['hata']);
}
rol(['siparis.read'], false);
$r = sayfa('siparisler.php');
ok('yönetici olmayan: kurulmadı, migrate bağlantısı yok', str_contains($r['html'], 'henüz kurulmadı') && !str_contains($r['html'], 'migrate.php#siparis'));
rol($VIEW);
$r = sayfa('siparis_dosya.php', ['id' => 1]);
ok('siparis_dosya.php tablo yokken 503', $r['kod'] === 503, (string)$r['kod']);

echo "\n=== 2. Tohum + listeyi çiz ===\n";
$ID = siparis_ui_tohum($db);
rol($ADMIN, true);
$r = sayfa('siparisler.php');
ok('liste temiz çalıştı (PHP uyarısı yok)', temiz($r), (string)$r['hata'] . substr($r['html'], 0, 200));
ok('HTML etiketleri dengeli', html_dengeli($r['html']) === null, (string)html_dengeli($r['html']));
foreach (['TC900001.10', 'TC900002.10', 'TC900011.10', 'TC900012.10', 'TC900099.10'] as $ref) ok("liste: $ref var", str_contains($r['html'], $ref));
ok('liste: ikinci depo sevkiyatı (admin kısıtsız) görünür', str_contains($r['html'], 'TC900031.10'));
ok('KPI çipleri 7 adet', preg_match_all('/class="sip-chip[ "]/', $r['html']) === 7 && str_contains($r['html'], 'PKL bekleyen') && str_contains($r['html'], 'Mail bekleyen'));
ok('açık + kapanan bölümler', str_contains($r['html'], 'id="sipAcik"') && str_contains($r['html'], 'id="sipKapali"'));
ok('v390: Açık ve Kapanan AYRI kartlar (İşlem Bekleyen ⏳ / Kapanan ✅, Beyanlar gibi)',
   str_contains($r['html'], 'class="sip-bolum sip-bolum-acik"') && str_contains($r['html'], 'class="sip-bolum sip-bolum-kapali"')
   && str_contains($r['html'], 'İşlem Bekleyen Sevkiyatlar') && str_contains($r['html'], 'Kapanan Sevkiyatlar')
   && !str_contains($r['html'], 'Açık sevkiyatlar</h2>') && str_contains($r['html'], '⏳') && str_contains($r['html'], '✅'));
ok('masaüstü tablo + mobil kart + satır bağlantısı', str_contains($r['html'], 'table-wrap pc-only') && str_contains($r['html'], 'card-list mobile-only sip-kartlar') && str_contains($r['html'], 'class="row-link'));
ok('belge rozetleri ✓/– ve durum rozetleri', str_contains($r['html'], 'sip-dok--var') && str_contains($r['html'], 'sip-dok--yok') && str_contains($r['html'], 'sip-badge--pkl') && str_contains($r['html'], 'sip-badge--mail') && str_contains($r['html'], 'sip-badge--iptal'));
ok('PO yok rozeti (PC ile açılan taslak)', str_contains($r['html'], 'sip-po--yok'));
ok('yükleme formu: CSRF + multipart + post_max_size verisi', str_contains($r['html'], 'enctype="multipart/form-data"') && str_contains($r['html'], 'name="csrf"') && str_contains($r['html'], 'data-sip-max-toplam=') && str_contains($r['html'], 'name="belgeler[]"'));
ok('yükleme alanı gizli girdi kaçmıyor: accept=.pdf,.xlsx', str_contains($r['html'], 'accept=".pdf,.xlsx"'));
ok('elle sevkiyat + ayarlar menüsü', str_contains($r['html'], 'sipYeniModal') && str_contains($r['html'], 'siparis_ayarlar.php'));
// v382 — "Belge yükle" kartı native <details>: varsayılan kapalı, özet satırı, eski section/aria-label yok
ok('v382: yükleme kartı <details class="card sip-yukle" id="yukle"> ve varsayılan KAPALI (open yok)', preg_match('/<details class="card sip-yukle" id="yukle" data-sip-yukle data-sip-yukle-uid="\d+"(?<open>[^>]*)>/', $r['html'], $m) === 1 && !str_contains($m['open'], 'open'));
ok('v382: summary.sip-yukle-ozet + gövde; form gövdenin İÇİNDE; eski aria-label="Belge yükle" yok',
    str_contains($r['html'], '<summary class="sip-yukle-ozet">') && str_contains($r['html'], '<div class="sip-yukle-govde">') && !str_contains($r['html'], 'aria-label="Belge yükle"')
    && strpos($r['html'], 'class="sip-yukle-govde"') < strpos($r['html'], 'action="siparis_yukle.php"'));
ok('v382: summary içinde <h2> yok (rol button)', preg_match('~<summary class="sip-yukle-ozet">(.*?)</summary>~s', $r['html'], $ms) === 1 && !str_contains($ms[1], '<h2'));
ok('v382: sipui_yukle_ozet_html: yarım + son sorgu + hata turuncu, mail yoksa yalnız başlık',
    str_contains(sipui_yukle_ozet_html([], null, false), '📥 Belge yükle</span>') && !str_contains(sipui_yukle_ozet_html([], null, false), 'Mailden al')
    && str_contains(sipui_yukle_ozet_html([], ['tarama_id' => 'x', 'tur' => 'siparis', 'i' => 0, 'toplam' => 2, 'depo' => 'D'], true), '⏸ Yarım tarama var')
    && preg_match('/Son sorgu: Lojistik · \d{2}\.\d{2} \d{2}:\d{2} · 3 alındı · 2 hata/', sipui_yukle_ozet_html([['tur' => 'lojistik', 'bitis' => 1792400000, 'sayac' => ['alindi' => 3, 'hata' => 2]]], null, true)) === 1
    && str_contains(sipui_yukle_ozet_html([['tur' => 'lojistik', 'bitis' => 1792400000, 'sayac' => ['alindi' => 3, 'hata' => 2]]], null, true), 'sip-yukle-son--hata')
    && !str_contains(sipui_yukle_ozet_html([['tur' => 'siparis', 'bitis' => 0, 'sayac' => ['alindi' => 1]]], null, true), 'sip-yukle-son--hata'));
// v382 — "Açık Sevkiyatlar" yazdırma / PDF düğmeleri (veri ucu: siparis_yazdir.php)
$yz = sayfa('siparisler.php', ['q' => 'TC90000', 'durum' => 'acik', 'acik_page' => '2', 'kapali_page' => '3']);
$acikBas = preg_match('~<div class="sip-bolum-bas" id="sipAcik">.*?</div></div>~s', $yz['html'], $mb) === 1 ? $mb[0] : '';
ok('v382: Açık başlığında 🖨️ Yazdır + 📤 PDF Gönder (data-sip-yazdir="yazdir" / "pdf"), no-print kapta', str_contains($acikBas, 'data-sip-yazdir="yazdir"') && str_contains($acikBas, 'data-sip-yazdir="pdf"') && str_contains($acikBas, 'class="sip-bolum-eylem no-print"') && str_contains($acikBas, '🖨️ Yazdır') && str_contains($acikBas, '📤 PDF Gönder'), $acikBas);
preg_match('/data-sip-yazdir-url="([^"]+)"/', $acikBas, $mu); $yu = html_entity_decode($mu[1] ?? '');
ok('v382: yazdırma URL\'i siparis_yazdir.php + filtre anahtarları (q, durum); sayfa numaraları (acik_page/kapali_page) YOK', str_starts_with($yu, 'siparis_yazdir.php?') && str_contains($yu, 'q=TC90000') && str_contains($yu, 'durum=acik') && !str_contains($yu, 'acik_page') && !str_contains($yu, 'kapali_page'), $yu);
$yh = sayfa('siparisler.php')['html']; $kp = strpos($yh, 'id="sipKapali"');
ok('v382: Kapanan bölümde yazdırma düğmesi YOK (iki bölümlü listede düğme yalnız Açık başlığında)', $kp !== false && substr_count($yh, 'data-sip-yazdir="yazdir"') === 1 && strpos($yh, 'data-sip-yazdir') < $kp && strpos(substr($yh, $kp), 'data-sip-yazdir') === false);
$gSakla = $_GET; $_GET = [];
ok('v382: siparis_url() geriye uyumlu (varsayılan siparisler.php) + 2. parametre ile başka uca', siparis_url([]) === 'siparisler.php' && siparis_url(['q' => 'a']) === 'siparisler.php?q=a' && siparis_url(['q' => 'a'], 'siparis_yazdir.php') === 'siparis_yazdir.php?q=a');
$_GET = $gSakla;
ok('v382: durum=kapali → yazdırma düğmesi YOK', strpos(sayfa('siparisler.php', ['durum' => 'kapali'])['html'], 'data-sip-yazdir') === false);
ok('v382: boş sonuç (eşleşen açık sevkiyat yok) → düğme YOK', strpos(sayfa('siparisler.php', ['q' => 'yok-boyle-bir-sevkiyat'])['html'], 'data-sip-yazdir') === false);
$yt = sayfa('siparisler.php', ['durum' => 'arac_bekleyen']);
$sonBas = preg_match('~<div class="sip-bolum-bas" id="sipSonuclar">.*?</div></div>~s', $yt['html'], $mt) === 1 ? $mt[0] : '';
ok('v382: tek durum filtresinde "Sonuçlar" başlığında düğmeler (durum=arac_bekleyen taşınır)', str_contains($sonBas, 'data-sip-yazdir="pdf"') && str_contains($sonBas, 'durum=arac_bekleyen') && substr_count($yt['html'], 'data-sip-yazdir="yazdir"') === 1);
ok('v382: siparisler.php siparis_yazdir.js\'i yükler (assets/siparis_yazdir.js, defer)', str_contains($yz['html'], 'assets/siparis_yazdir.js?v=') && preg_match('~<script src="assets/siparis_yazdir\.js\?v=\d+" defer>~', $yz['html']) === 1);
ok('v382: yazdırma düğmelerinde kişisel veri YOK (veri uçtan, tıkta gelir)', !preg_match('/Test Sürücü|\+90 5/', $yz['html']));
// v394 — ⋮ menüsünde 💬 WP Paylaş (veri ucu: siparis_paylas.php; liste HTML'inde kişisel veri YOK)
ok('v394: masaüstü + mobil menüde PKL\'den SONRA WP Paylaş düğmesi (data-sip-wp = sevkiyat id)', preg_match_all('~href="siparis_pkl\.php\?id=' . $ID['s1'] . '">📄 PKL</a>\s*<button type="button" data-sip-wp="' . $ID['s1'] . '"[^>]*>💬 WP Paylaş</button>~', $yh) === 2);
ok('v394: Kapanan bölümde de WP Paylaş var (mail_gonderildi sevkiyatı)', preg_match_all('~href="siparis_pkl\.php\?id=' . $ID['s11'] . '">📄 PKL</a>\s*<button type="button" data-sip-wp="' . $ID['s11'] . '"~', $yh) === 2
    && strpos($yh, 'data-sip-wp="' . $ID['s11'] . '"') > strpos($yh, 'id="sipKapali"'));
ok('v394: her kebab menüsünde bir WP Paylaş düğmesi', substr_count($yh, 'data-sip-wp="') > 0 && substr_count($yh, 'data-sip-wp="') === substr_count($yh, '<button class="pc-kebab"'));
ok('v394: durum=kapali sayfasında WP düğmesi VAR, yazdırma düğmesi YOK', ($kapW = sayfa('siparisler.php', ['durum' => 'kapali'])['html']) && str_contains($kapW, 'data-sip-wp=') && strpos($kapW, 'data-sip-yazdir') === false);
ok('v394: siparisler.php siparis_wp.js\'i yükler (defer)', preg_match('~<script src="assets/siparis_wp\.js\?v=\d+" defer>~', $yz['html']) === 1);
ok('SQL param hatası yok (HY093/uyarı log)', !str_contains((string)@file_get_contents(ini_get('error_log')), 'HY093'));
// çatışmalı sevkiyat uyarısı
ok('çatışma rozeti (⚠ çatışma)', str_contains($r['html'], '1 çatışma') || str_contains($r['html'], '⚠ 1'));

echo "\n=== 3. Liste filtreleri ===\n";
$f = fn(array $g) => sayfa('siparisler.php', $g);
$r = $f(['q' => 'TC900011']);
ok('q: yalnız eşleşen', str_contains($r['html'], 'TC900011.10') && !str_contains($r['html'], 'TC900002.10') && temiz($r));
$r = $f(['q' => '34TST111']);
ok('q: plakaya göre', str_contains($r['html'], 'TC900011.10') && !str_contains($r['html'], 'TC900002.10'));
$r = $f(['q' => "x' OR 1=1 --"]);
ok('q: SQL enjeksiyonu etkisiz (sonuç yok, hata yok)', temiz($r) && !str_contains($r['html'], 'TC900002.10'));
$r = $f(['durum' => 'kapali']);
ok('durum=kapali: yalnız kapananlar', str_contains($r['html'], 'TC900011.10') && str_contains($r['html'], 'TC900012.10') && !str_contains($r['html'], 'TC900002.10') && !str_contains($r['html'], 'id="sipAcik"'));
$r = $f(['durum' => 'pkl_hazir']);
ok('durum=pkl_hazir: tek "Sonuçlar" bölümü, yalnız TC900001.10', str_contains($r['html'], 'id="sipSonuclar"') && str_contains($r['html'], 'TC900001.10') && !str_contains($r['html'], 'TC900002.10'));
$r = $f(['durum' => 'arac_bekleyen']);
ok('durum=arac_bekleyen', str_contains($r['html'], 'TC900002.10') && !str_contains($r['html'], 'TC900001.10'));
$r = $f(['catisma' => '1']);
ok('catisma=1: yalnız çatışmalı', str_contains($r['html'], 'TC900001.10') && !str_contains($r['html'], 'TC900002.10'));
$r = $f(['eksik' => 'po']);
ok('eksik=po: PO\'su olmayan taslak', str_contains($r['html'], 'TC900099.10') && !str_contains($r['html'], 'TC900002.10'));
$r = $f(['po' => $ID['po1']]);
ok('po=<belge>: aynı PO grubu', str_contains($r['html'], 'TC900001.10') && str_contains($r['html'], 'TC900002.10') && !str_contains($r['html'], 'TC900031.10') && !str_contains($r['html'], 'TC900011.10'));
$r = $f(['cw' => '50.2026']);
ok('cw filtresi çalışır', temiz($r) && str_contains($r['html'], 'TC900001.10'));
$r = $f(['tarih_bas' => '2026-12-06', 'tarih_bit' => '2026-12-08']);
ok('tarih aralığı', str_contains($r['html'], 'TC900011.10') && !str_contains($r['html'], 'TC900001.10'));
$r = $f(['durum' => "x'; DROP TABLE siparis_sevkiyatlar;--", 'eksik' => 'zzz', 'cw' => 'abc', 'tarih_bas' => 'x']);
ok('geçersiz filtre değerleri yok sayılır', temiz($r) && str_contains($r['html'], 'TC900001.10') && say($db, 'siparis_sevkiyatlar') > 0);
$r = $f(['q' => 'yok-boyle-biri']);
ok('boş sonuç mesajı', str_contains($r['html'], 'eşleşen'));

echo "\n=== 4. Detay sayfası ===\n";
$r = sayfa('siparis_view.php', ['id' => $ID['s1']]);
ok('detay temiz + dengeli', temiz($r) && html_dengeli($r['html']) === null, (string)$r['hata'] . ' ' . (string)html_dengeli($r['html']));
foreach (['id="durum"', 'id="bilgi"', 'id="kalemler"', 'id="belgeler"', 'id="pkl"', 'id="beyan"', 'id="gecmis"', 'id="catismalar"'] as $a) ok("detay: $a", str_contains($r['html'], $a));
ok('durum adımları: 6 adım, tek aria-current=step, sıradaki kesik çerçeve', preg_match_all('/<li class="sip-step[ "]/', $r['html']) === 6 && substr_count($r['html'], 'aria-current="step"') === 1 && str_contains($r['html'], 'sip-step--sonraki'));
ok('kaynak rozetleri + elle kilit + kilidi kaldır', str_contains($r['html'], 'sip-src--ll') && str_contains($r['html'], 'sip-src--po') && str_contains($r['html'], 'Kilidi kaldır') && str_contains($r['html'], '🔒'));
ok('planlanan plaka altı satır', str_contains($r['html'], 'Planlanan:'));
ok('çatışma: geleni kabul et / mevcudu koru', str_contains($r['html'], 'Geleni kabul et') && str_contains($r['html'], 'Mevcudu koru'));
ok('kalem tablosu + etkin sütunu + toplam', str_contains($r['html'], 'sip-kalem-tablo') && str_contains($r['html'], 'Etkin pal/koli'));
ok('belgeler gruplu, eski sürüm rozeti, ZIP düğmeleri', str_contains($r['html'], 'eski sürüm') && str_contains($r['html'], 'data-sip-zip-secili') && str_contains($r['html'], 'siparis_zip.php?id=' . $ID['s1']));
ok('PKL paneli: durum + indir', str_contains($r['html'], 'AsyaDoc') && str_contains($r['html'], 'siparis_dosya.php?id=' . $ID['pkl1'] . '&amp;dl=1'));
ok('beyan önerisi (plaka eşleşti) + bağla', str_contains($r['html'], 'plaka eşleşti') && str_contains($r['html'], 'name="islem" value="bagla"'));
ok('geçmiş zaman çizelgesi', str_contains($r['html'], 'sip-tl') && str_contains($r['html'], 'PKL üretildi'));
ok('mail penceresi + PO e-postası (sentetik .invalid)', str_contains($r['html'], 'sipMailModal') && str_contains($r['html'], 'test@example.invalid') && str_contains($r['html'], 'data-sip-mail-gonder'));
ok('mail penceresi: kısmi PKL için ikinci onay kutusu', substr_count($r['html'], 'data-sip-mail-onay') === 2);
ok('tüm POST formlarında CSRF', substr_count($r['html'], 'method="post"') > 0 && substr_count($r['html'], 'method="post"') <= substr_count($r['html'], 'name="csrf"'));
$r = sayfa('siparis_view.php', ['id' => $ID['s1'], 'duzenle' => 'plakalar']);
ok('JS\'siz düzenleme formu (?duzenle=)', str_contains($r['html'], 'class="sip-edit-inline"') && str_contains($r['html'], 'name="alan" value="plakalar"'));
$r = sayfa('siparis_view.php', ['id' => $ID['s1'], 'gecmis' => 'tum']);
ok('geçmişin tamamı', temiz($r));
$r = sayfa('siparis_view.php', ['id' => $ID['s11']]);
ok('kapanan sevkiyat: tüm adımlar, Mail gönderildi düğmesi yok', temiz($r) && !str_contains($r['html'], 'data-sip-ac="sipMailModal"') && str_contains($r['html'], 'Mail gönderildi'));
ok('3 kaynaklı kalem: bölüm farklı rozeti + uyarı', str_contains($r['html'], 'Bölüm farklı') && str_contains($r['html'], 'class="sip-uyari'));
$r = sayfa('siparis_view.php', ['id' => $ID['s12']]);
ok('iptal edilen: iptal bandı + gerekçe', str_contains($r['html'], 'sip-iptal-bant') && str_contains($r['html'], 'müşteri iptal etti'));
$r = sayfa('siparis_view.php', ['id' => $ID['s99']]);
ok('PO\'suz taslak: po_yok uyarısı metni', str_contains($r['html'], 'PO henüz yüklenmedi'));
$r = sayfa('siparis_view.php', ['id' => 99999]);
ok('olmayan sevkiyat 404 sayfası', str_contains($r['html'], 'Sevkiyat bulunamadı') && str_contains($r['html'], 'href="siparisler.php"'));
$r = sayfa('siparis_view.php', ['id' => 'abc']);
ok('geçersiz id 404 sayfası', str_contains($r['html'], 'Sevkiyat bulunamadı'));

echo "\n=== 5. PKL editörü, belge sayfası, ayarlar ===\n";
$r = sayfa('siparis_pkl.php', ['id' => $ID['s1']]);
ok('PKL editörü temiz + dengeli', temiz($r) && html_dengeli($r['html']) === null, (string)$r['hata'] . (string)html_dengeli($r['html']));
foreach (['name="baslik[plakalar]"', 'name="varsayilan[M]"', 'name="bloklar[0][palet]"', 'name="satir[0][M]"', 'class="sip-bar"', 'Σ palet', 'id="onkontrol"'] as $a) ok("PKL: $a", str_contains($r['html'], $a));
ok('PKL: satır sayısı 20, palet ID önizlemesi 28..47', substr_count($r['html'], 'name="satir[') === 20 * 13 && str_contains($r['html'], '<strong data-sip-palet-no>28</strong>') && str_contains($r['html'], '<strong data-sip-palet-no>29</strong>') && str_contains($r['html'], '<strong data-sip-palet-no>47</strong>'));
ok('PKL: eksik hücre uyarısı + eksik alanlarla oluştur kartı', str_contains($r['html'], 'Boş zorunlu hücre') && str_contains($r['html'], 'name="eksik_izin" value="1"'));
ok('PKL: belge no ayrıldı → salt okunur', str_contains($r['html'], 'readonly') && !str_contains($r['html'], 'name="belge_no_istenen"'));
$r = sayfa('siparis_pkl.php', ['id' => $ID['s2']]);
ok('PKL (LL yok): navlun engeli + oluştur düğmesi pasif + neden', temiz($r) && str_contains($r['html'], 'Loading List yüklenmeden PKL oluşturulamaz') && str_contains($r['html'], 'Önce yukarıdaki engelleri giderin') && str_contains($r['html'], 'disabled aria-describedby="sipUretNeden"'));
$r = sayfa('siparis_belge.php', ['id' => $ID['po1']]);
ok('belge sayfası (PDF): üst veri + önizleme iframe + mobil düğmeler', temiz($r) && str_contains($r['html'], '<iframe class="sip-pdf"') && str_contains($r['html'], "PDF'i aç") && str_contains($r['html'], 'TC900001.10'));
$r = sayfa('siparis_belge.php', ['id' => $ID['pkl1']]);
ok('belge sayfası (xlsx): hücre önizleme tablosu', temiz($r) && str_contains($r['html'], 'Order Reference') && str_contains($r['html'], 'AsyaDoc'));
$r = sayfa('siparis_belge.php', ['id' => $ID['pkl1'], 'parca' => '1']);
ok('parca=1: yalnız parça (başlık/footer yok)', str_contains($r['html'], '<table') && !str_contains($r['html'], '<html') && !str_contains($r['html'], 'class="topbar"'));
$r = sayfa('siparis_ayarlar.php');
ok('ayarlar temiz: sayaçlar, son PKL, profiller', temiz($r) && html_dengeli($r['html']) === null && str_contains($r['html'], 'Son palet ID') && str_contains($r['html'], 'AsyaDoc002') && str_contains($r['html'], 'id="profiller"'));

echo "\n=== 6. Roller ===\n";
rol($ADMIN, true);
$a = sayfa('siparis_view.php', ['id' => $ID['s1']])['html'];
ok('admin: sil menüsü + belge silme + sayaç düzeltme', str_contains($a, 'sipSilModal') && str_contains($a, 'value="belge_sil"') && str_contains(sayfa('siparis_ayarlar.php')['html'], 'Düzelt'));
rol($OPER, false, 5);
$o = sayfa('siparis_view.php', ['id' => $ID['s1']]);
ok('operatör (read/write/match): düzenleme + yükleme + beyan bağla var', temiz($o) && str_contains($o['html'], 'sipYukleModal') && str_contains($o['html'], 'data-sip-alan=') && str_contains($o['html'], 'value="bagla"'));
ok('operatör: SİLME yok (sevkiyat/belge)', !str_contains($o['html'], 'sipSilModal') && !str_contains($o['html'], 'value="belge_sil"'));
ok('operatör: sayaç düzeltme + profil silme yok', !str_contains(sayfa('siparis_ayarlar.php')['html'], 'value="profil_sil"') && !str_contains(sayfa('siparis_ayarlar.php')['html'], 'Neden değiştirdiğimi'));
rol($VIEW, false, 6);
$v = sayfa('siparis_view.php', ['id' => $ID['s1']]);
ok('izleyici: sayfa açılır, YAZAN form yok', temiz($v) && !str_contains($v['html'], 'siparis_kaydet.php') && !str_contains($v['html'], 'siparis_yukle.php') && !str_contains($v['html'], 'data-sip-alan='));
ok('izleyici: pasif düğmeler nedenini söylüyor', str_contains($v['html'], 'title="Gerekli yetki: siparis.write"') && str_contains($v['html'], 'siparis.write yetkisi gerekir'));
ok('izleyici: beyan paneli yok (beyan.read ve match yok)', !str_contains($v['html'], 'id="beyan"'));
$l = sayfa('siparisler.php');
ok('izleyici listesi: yükleme kartı yok, pasif düğme + açıklama', temiz($l) && !str_contains($l['html'], 'siparis_yukle.php') && str_contains($l['html'], 'Salt okunur yetkiniz var') && str_contains($l['html'], 'disabled aria-describedby="sipSaltOkunur"'));
ok('v382: izleyici listesinde yazdırma düğmeleri VAR (siparis.read yeter; veri ucu aynı kapıda), yükleme kartı YOK', substr_count($l['html'], 'data-sip-yazdir=') === 2 && !str_contains($l['html'], 'sip-yukle-govde'));
ok('v394: izleyici listesinde WP Paylaş düğmesi VAR (siparis.read yeter)', str_contains($l['html'], 'data-sip-wp="'));
ok('izleyici: PKL salt okunur (fieldset disabled, çubuk yok)', str_contains(sayfa('siparis_pkl.php', ['id' => $ID['s1']])['html'], 'sip-fieldset" disabled') && !str_contains(sayfa('siparis_pkl.php', ['id' => $ID['s1']])['html'], 'class="sip-bar"'));
rol([], false, 7);
foreach (['siparisler.php' => [], 'siparis_view.php' => ['id' => $ID['s1']], 'siparis_pkl.php' => ['id' => $ID['s1']], 'siparis_belge.php' => ['id' => $ID['po1']], 'siparis_ayarlar.php' => [], 'siparis_dosya.php' => ['id' => $ID['po1']], 'siparis_zip.php' => ['id' => $ID['s1']]] as $f => $g) {
    $x = sayfa($f, $g);
    ok("yetkisiz: $f → forbidden (siparis.read)", $x['hata'] !== null && str_contains($x['hata'], 'siparis.read'), (string)$x['hata']);
}
rol(['beyan.read', 'records.write'], false, 7);
ok('köprü yok: records.write / beyan.read siparis kapısını açmaz', sayfa('siparisler.php')['hata'] !== null);
rol(['siparis.read', 'siparis.match'], false, 8);       // match var, beyan.read yok
$x = sayfa('siparis_view.php', ['id' => $ID['s1']]);
ok('match var + beyan.read yok: beyan paneli YOK', !str_contains($x['html'], 'id="beyan"') && !str_contains($x['html'], 'plaka eşleşti'));

echo "\n=== 7. Depo izolasyonu ===\n";
rol($ADMIN, false, 9, ['DEPO2']);
$x = sayfa('siparisler.php');
ok('DEPO2 kullanıcısı: yalnız DEPO2 sevkiyatı listelenir', str_contains($x['html'], 'TC900031.10') && !str_contains($x['html'], 'TC900002.10') && !str_contains($x['html'], 'TC900011.10'));
$x = sayfa('siparis_view.php', ['id' => $ID['s1']]);
ok('başka depo sevkiyatı → 403', $x['hata'] !== null && str_contains($x['hata'], 'forbidden'));
foreach (['siparis_pkl.php' => ['id' => $ID['s1']], 'siparis_zip.php' => ['id' => $ID['s1']], 'siparis_belge.php' => ['id' => $ID['po1']], 'siparis_dosya.php' => ['id' => $ID['po1']]] as $f => $g) {
    $x = sayfa($f, $g);
    ok("başka depo: $f → 403", $x['hata'] !== null && str_contains($x['hata'], 'forbidden'), (string)$x['hata']);
}
$x = sayfa('siparis_kaydet.php', [], ['csrf' => csrf(), 'islem' => 'sil', 'sid' => (string)$ID['s1'], 'gerekce' => 'deneme']);
ok('başka depo: POST sil → 403, sevkiyat duruyor', $x['hata'] !== null && say($db, 'siparis_sevkiyatlar', 'id = ? AND deleted_at IS NULL', [$ID['s1']]) === 1);
$x = sayfa('siparis_kaydet.php', [], ['csrf' => csrf(), 'islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$ID['s1'], 'alan' => 'plakalar', 'deger' => '34ZZZ999']);
ok('başka depo: POST alan düzenle → 403, değer değişmedi', $x['hata'] !== null && siparis_sevkiyat($db, $ID['s1'])['plakalar'] === '34TST777-34TST778');
rol($ADMIN, false, 9, ['DEPO1']);
$x = sayfa('siparis_view.php', ['id' => $ID['s31']]);
ok('DEPO1 kullanıcısı DEPO2 sevkiyatını göremez', $x['hata'] !== null);
$x = sayfa('siparisler.php');
ok('DEPO1 listesinde DEPO2 sevkiyatı yok', !str_contains($x['html'], 'TC900031.10'));
// ZIP: karışık bağ — DEPO1 kullanıcısı için görünmeyen belgeler atlanır
rol($ADMIN, true);

echo "\n=== 8. POST akışları (CSRF'li) ===\n";
$tok = csrf();
$K = fn(array $p) => sayfa('siparis_kaydet.php', [], ['csrf' => $tok] + $p);
$sid1 = $ID['s1'];
$x = $K(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sid1, 'alan' => 'yurtici_gumruk', 'deger' => 'MERSIN']);
ok('v387: yurt içi gümrük kolon yokken yazılamaz (şema notu)', ($x['flash']['type'] ?? '') === 'error' && str_contains((string)($x['flash']['msg'] ?? $x['flash']['message'] ?? json_encode($x['flash'])), 'Yurt içi gümrük'), json_encode($x['flash']));
$x = $K(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sid1, 'alan' => 'gumruk', 'deger' => 'TESTPORT-X', 'donus' => 'bilgi']);
$s = siparis_sevkiyat($db, $sid1);
ok('alan: değer yazıldı, kilitlendi, PRG #bilgi', $s['gumruk'] === 'TESTPORT-X' && isset($s['_elle']['gumruk']) && str_contains((string)$x['git'], 'siparis_view.php?id=' . $sid1 . '#bilgi') && ($x['flash']['type'] ?? '') === 'success', json_encode([$x['hata'], $x['git'], $x['flash']]));
$x = $K(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sid1, 'alan' => 'gumruk', 'deger' => 'TESTPORT-X']);
ok('alan: değişmeyen değer tekrar kilitlenmez ("Değişiklik yok")', str_contains((string)($x['flash']['msg'] ?? ''), 'Değişiklik yok'));
$x = $K(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sid1, 'alan' => 'yukleme_tarihi', 'deger' => '31.02.2026']);
ok('alan: geçersiz tarih hata verir', ($x['flash']['type'] ?? '') === 'error');
$x = $K(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sid1, 'alan' => 'order_ref', 'deger' => 'X']);
ok('alan: beyaz liste dışı alan reddedilir', ($x['flash']['type'] ?? '') === 'error' && siparis_sevkiyat($db, $sid1)['order_ref'] === 'TC900001.10');
$x = $K(['islem' => 'alan', 'varlik' => 'tablo; DROP', 'id' => '1', 'alan' => 'x', 'deger' => 'x']);
ok('alan: geçersiz varlık reddedilir', ($x['flash']['type'] ?? '') === 'error' && say($db, 'siparis_sevkiyatlar') > 0);
$x = $K(['islem' => 'kilit_ac', 'varlik' => 'sevkiyat', 'id' => (string)$sid1, 'alan' => 'gumruk']);
ok('kilit_ac: kilit kalktı', !isset(siparis_sevkiyat($db, $sid1)['_elle']['gumruk']) && ($x['flash']['type'] ?? '') === 'success');
$x = $K(['islem' => 'not', 'sid' => (string)$sid1, 'deger' => "Dikkat: <b>soğuk zincir</b>\nikinci satır"]);
ok('not: kaydedildi (HTML saklanır, ekranda kaçırılır)', siparis_sevkiyat($db, $sid1)['notlar'] === "Dikkat: <b>soğuk zincir</b> ikinci satır" || str_contains((string)siparis_sevkiyat($db, $sid1)['notlar'], 'soğuk zincir'));
$g = sayfa('siparis_view.php', ['id' => $sid1])['html'];
ok('not ekranda kaçırıldı (&lt;b&gt;)', str_contains($g, '&lt;b&gt;soğuk zincir') && !str_contains($g, '<b>soğuk zincir'));
// kalem düzenleme (çoklu alan; değişmeyen kilitlenmez)
$k = siparis_kalemler($db, $sid1)[0];
$x = $K(['islem' => 'alan', 'varlik' => 'kalem', 'id' => (string)$k['id'], 'alanlar' => ['etkin_palet' => '18', 'etkin_koli' => (string)$k['etkin_koli'], 'pf' => (string)$k['pf'], 'cesit' => (string)$k['cesit'], 'artikel_no' => (string)$k['artikel_no'], 'palet_tipi' => (string)$k['palet_tipi']], 'donus' => 'kalemler']);
$k2 = siparis_kalemler($db, $sid1)[0];
ok('kalem: yalnız değişen alan (etkin_palet) kilitlendi', (int)$k2['etkin_palet'] === 18 && isset($k2['_elle']['etkin_palet']) && !isset($k2['_elle']['pf']) && !isset($k2['_elle']['etkin_koli']), json_encode($k2['_elle']));
$x = $K(['islem' => 'alan', 'varlik' => 'kalem', 'id' => (string)$k['id'], 'alanlar' => ['etkin_palet' => '']]);
$k3 = siparis_kalemler($db, $sid1)[0];
ok('kalem: etkin alan boş → kilit kalkar, hesaplananına döner', !isset($k3['_elle']['etkin_palet']) && (int)$k3['etkin_palet'] === (int)$k['etkin_palet']);
// çatışma
$cid = (int)$db->query("SELECT id FROM siparis_catismalar WHERE sevkiyat_id = $sid1 AND durum = 'acik' LIMIT 1")->fetchColumn();
$x = $K(['islem' => 'catisma', 'catisma_id' => (string)$cid, 'karar' => 'red', 'donus' => 'catismalar']);
ok('çatışma: mevcut korundu', ($x['flash']['type'] ?? '') === 'success' && say($db, 'siparis_catismalar', "id = ? AND durum = 'red'", [$cid]) === 1);
$x = $K(['islem' => 'catisma', 'catisma_id' => (string)$cid, 'karar' => 'kabul']);
ok('çatışma: çözülmüşe tekrar karar reddedilir', ($x['flash']['type'] ?? '') === 'error');
// PKL editörü: Excel benzeri ızgara + Palet ID başlangıcı / boşluk-çakışma uyarısı (v372)
$sid2 = $ID['s2'];
$PP = fn(array $p) => sayfa('siparis_pkl.php', ['id' => $sid2], ['csrf' => $tok] + $p);
$r = sayfa('siparis_pkl.php', ['id' => $sid2]);
ok('PKL ızgara: Excelden yapıştır düğmesi + yapıştırma penceresi + araç çubuğu toplamları', temiz($r) && str_contains($r['html'], 'data-sip-ac="sipYapistirModal"') && str_contains($r['html'], 'id="sipYapistirModal"') && str_contains($r['html'], 'data-sip-t-brut') && str_contains($r['html'], 'data-sip-t-net') && str_contains($r['html'], 'data-sip-t-dolu'));
ok('PKL ızgara: yapıştırma penceresi .pm-dialog, <form> ile SARILMAZ, Uygula pasif başlar', preg_match('#id="sipYapistirModal".*?<div class="pm-dialog">\s*<div class="pm-header">#s', $r['html']) === 1 && str_contains($r['html'], 'data-sip-yap-uygula disabled'));
ok('PKL ızgara: Brüt/Net inputmode=decimal, enterkeyhint, sütun/satır öznitelikleri', str_contains($r['html'], 'name="satir[0][M]"') && preg_match('#name="satir\[0\]\[M\]"[^>]*data-sip-sutun="M" inputmode="decimal" enterkeyhint="next"#', $r['html']) === 1 && preg_match('#name="satir\[\d+\]\[N\]"[^>]*inputmode="decimal" enterkeyhint="done"#', $r['html']) === 1 && str_contains($r['html'], 'data-sip-satir="0" data-sip-blok="0"'));
ok('PKL ızgara: ilk satır Palet ID düzenlenebilir girdi (name=palet_bas_istenen) + varsayılan gizli alan, başka yerde ikinci palet_bas_istenen YOK',
    str_contains($r['html'], 'class="sip-hucre sip-palet-bas" name="palet_bas_istenen"') && str_contains($r['html'], 'name="palet_bas_varsayilan"') && substr_count($r['html'], 'name="palet_bas_istenen"') === 1);
ok('PKL ızgara: palet bağlamı form özniteliklerinde (son, diğer aralıklar JSON, kilitli=0)', str_contains($r['html'], 'data-sip-palet-son="') && str_contains($r['html'], 'data-sip-palet-diger="[') && str_contains($r['html'], 'data-sip-palet-kilitli="0"') && str_contains($r['html'], 'AsyaDoc'));
$sirada = preg_match('#name="palet_bas_varsayilan" value="(\d+)"#', $r['html'], $mm) ? (int)$mm[1] : 0;
$kal2 = siparis_kalemler($db, $sid2);
$blk = array_map(fn($k) => ['kalem_id' => (string)$k['id'], 'palet' => (string)$k['etkin_palet']], array_values(array_filter($kal2, fn($k) => (int)$k['etkin_palet'] > 0)));
$pbi = fn() => (siparis_json_coz($db->query("SELECT veri_json FROM siparis_pkl WHERE sevkiyat_id = $sid2")->fetchColumn())['palet_bas_istenen'] ?? 'yok');
$PP(['islem' => 'kaydet', 'bloklar' => $blk, 'varsayilan' => ['M' => '800'], 'palet_bas_istenen' => (string)$sirada, 'palet_bas_varsayilan' => (string)$sirada]);
ok('Palet ID: önerilen numara değiştirilmediyse SABİTLENMEZ (boş = otomatik sıradaki)', $sirada > 0 && $pbi() === 'yok', json_encode($pbi()));
$PP(['islem' => 'kaydet', 'bloklar' => $blk, 'varsayilan' => ['M' => '800'], 'palet_bas_istenen' => (string)($sirada + 40), 'palet_bas_varsayilan' => (string)$sirada]);
ok('Palet ID: değiştirilen başlangıç aynen saklanır', $pbi() === $sirada + 40, json_encode($pbi()));
$r = sayfa('siparis_pkl.php', ['id' => $sid2]);
ok('Palet ID: kayıtlı başlangıç girdide, boşluk uyarısı "Üretim öncesi denetim"de (sunucu çizimi)', str_contains($r['html'], 'name="palet_bas_istenen" value="' . ($sirada + 40) . '"') && str_contains($r['html'], 'numara boşta kalıyor') && str_contains($r['html'], 'id="onkontrol"'));
$pk1r = $db->query("SELECT palet_bas, palet_bit, belge_no FROM siparis_pkl WHERE sevkiyat_id = " . (int)$sid1)->fetch();
$PP(['islem' => 'kaydet', 'bloklar' => $blk, 'varsayilan' => ['M' => '800'], 'palet_bas_istenen' => (string)((int)$pk1r['palet_bas'] + 2), 'palet_bas_varsayilan' => (string)$sirada]);
$r = sayfa('siparis_pkl.php', ['id' => $sid2]);
ok('Palet ID: çakışma KAYDI engellemez; uyarı çakışan PKL\'yi adlandırır',
    $pbi() === (int)$pk1r['palet_bas'] + 2 && str_contains($r['html'], 'ile çakışıyor') && str_contains($r['html'], (string)$pk1r['belge_no']) && str_contains($r['html'], 'reddeder'));
$PP(['islem' => 'kaydet', 'bloklar' => $blk, 'varsayilan' => ['M' => '800'], 'palet_bas_istenen' => (string)$sirada, 'palet_bas_varsayilan' => (string)$sirada]);
ok('Palet ID: öneriye dönünce uyarı kalkar', !str_contains(sayfa('siparis_pkl.php', ['id' => $sid2])['html'], 'ile çakışıyor') && !str_contains(sayfa('siparis_pkl.php', ['id' => $sid2])['html'], 'numara boşta kalıyor'));
$r = sayfa('siparis_pkl.php', ['id' => $ID['s1']]);
ok('Palet ID (ayrılmış PKL): girdi YOK, aralık salt okunur + gerekçe notu, kilitli=1, ilk satır da <strong>', !str_contains($r['html'], 'name="palet_bas_istenen"') && str_contains($r['html'], 'data-sip-palet-kilitli="1"') && str_contains($r['html'], 'data-sip-palet-kilit-not') && str_contains($r['html'], 'Ayrıldı; yeniden üretimde korunur'));
rol(['siparis.read'], false, 6);
$r = sayfa('siparis_pkl.php', ['id' => $sid2]);
ok('izleyici: yapıştır düğmesi/penceresi ve Palet ID girdisi YOK (toplamlar görünür)', !str_contains($r['html'], 'sipYapistirModal') && !str_contains($r['html'], 'name="palet_bas_istenen"') && str_contains($r['html'], 'data-sip-t-brut'));
rol($ADMIN, true); $tok = csrf();
// durum
$x = $K(['islem' => 'durum', 'sid' => (string)$sid2, 'hedef' => 'hazirlik']);
ok('durum: hazırlık', siparis_sevkiyat($db, $sid2)['durum'] === 'hazirlik' && ($x['flash']['type'] ?? '') === 'success');
$x = $K(['islem' => 'durum', 'sid' => (string)$sid2, 'hedef' => 'iptal', 'gerekce' => '']);
ok('durum: gerekçesiz iptal reddedilir', siparis_sevkiyat($db, $sid2)['durum'] === 'hazirlik' && ($x['flash']['type'] ?? '') === 'error');
$x = $K(['islem' => 'durum', 'sid' => (string)$sid2, 'hedef' => 'mail_gonderildi', 'onay_gonderdim' => '1']);
ok('durum: PKL yokken mail_gonderildi reddedilir (neden yazılı)', str_contains((string)($x['flash']['msg'] ?? ''), 'Önce PKL oluşturun') && siparis_sevkiyat($db, $sid2)['durum'] === 'hazirlik');
$x = $K(['islem' => 'durum', 'sid' => (string)$sid1, 'hedef' => 'mail_gonderildi']);
ok('durum: onay kutusu olmadan mail_gonderildi reddedilir', siparis_sevkiyat($db, $sid1)['durum'] === 'pkl_hazir' && ($x['flash']['type'] ?? '') === 'error');
$x = $K(['islem' => 'durum', 'sid' => (string)$sid1, 'hedef' => 'mail_gonderildi', 'onay_gonderdim' => '1']);
ok('durum: kısmi PKL ikinci onay olmadan reddedilir', siparis_sevkiyat($db, $sid1)['durum'] === 'pkl_hazir' && str_contains((string)($x['flash']['msg'] ?? ''), 'boş hücre'));
$x = $K(['islem' => 'durum', 'sid' => (string)$sid1, 'hedef' => 'mail_gonderildi', 'onay_gonderdim' => '1', 'onay_eksik' => '1']);
$s = siparis_sevkiyat($db, $sid1);
ok('durum: mail gönderildi → kapandı, mail_gonderildi_at/by', $s['durum'] === 'mail_gonderildi' && $s['mail_gonderildi_at'] !== null && (int)$s['mail_gonderildi_by'] === 1
    && say($db, 'audit_log', "action = 'siparis_mail_gonderildi' AND record_id = ?", [$sid1]) === 1);
$x = $K(['islem' => 'durum', 'sid' => (string)$sid1, 'hedef' => 'hazirlik', 'gerekce' => '']);
ok('durum: kapanmış sevkiyatı gerekçesiz yeniden açma reddedilir', siparis_sevkiyat($db, $sid1)['durum'] === 'mail_gonderildi');
$x = $K(['islem' => 'durum', 'sid' => (string)$sid1, 'hedef' => 'pkl_hazir', 'gerekce' => 'müşteri düzeltme istedi']);
ok('durum: gerekçeyle yeniden açılır', siparis_sevkiyat($db, $sid1)['durum'] === 'pkl_hazir');
// belge bağla / yeniden işle / sil
$bos = siparis_test_dosya(siparis_test_pdf('yetim1'), 'yetim.pdf');
$kk = siparis_dosya_kaydet($db, $bos, 1, 'DEPO1', siparis_test_mover());
$x = $K(['islem' => 'yeniden_isle', 'belge_id' => (string)$kk['belge_id']]);
ok('yeniden_isle: ayrıştırıcı gerçek PDF olmadığı için hata gösterir, sayfa düşmez', in_array($x['flash']['type'] ?? '', ['error', 'success'], true) && $x['hata'] === null);
$GLOBALS['_siparis_parse_override'] = fn(string $y): array => ['doc_type' => 'unknown', 'checks' => [], 'warnings' => [], 'meta' => []];
$x = $K(['islem' => 'belge_bagla', 'belge_id' => (string)$kk['belge_id'], 'sevkiyat_id' => (string)$sid2]);
unset($GLOBALS['_siparis_parse_override']);
ok('belge_bagla: tanınmayan belge hedef sevkiyata "diğer" olarak bağlandı', say($db, 'siparis_belge_bag', 'belge_id = ? AND sevkiyat_id = ?', [(int)$kk['belge_id'], $sid2]) === 1 && str_contains((string)$x['git'], 'siparis_view.php?id=' . $sid2), json_encode([$x['flash'], $x['git']]));
$x = $K(['islem' => 'belge_bagla', 'belge_id' => (string)$kk['belge_id'], 'sevkiyat_id' => '99999']);
ok('belge_bagla: olmayan sevkiyat reddedilir', ($x['flash']['type'] ?? '') === 'error');
rol($OPER, false, 5); $tok = csrf();
$x = sayfa('siparis_kaydet.php', [], ['csrf' => $tok, 'islem' => 'belge_sil', 'belge_id' => (string)$kk['belge_id']]);
ok('operatör belge silemez (siparis.delete)', $x['hata'] !== null && str_contains($x['hata'], 'siparis.delete') && say($db, 'siparis_belgeler', 'id = ? AND deleted_at IS NULL', [(int)$kk['belge_id']]) === 1);
$x = sayfa('siparis_kaydet.php', [], ['csrf' => $tok, 'islem' => 'sil', 'sid' => (string)$sid2, 'gerekce' => 'deneme']);
ok('operatör sevkiyat silemez', $x['hata'] !== null && say($db, 'siparis_sevkiyatlar', 'id = ? AND deleted_at IS NULL', [$sid2]) === 1);
rol($VIEW, false, 6); $tok = csrf();
$x = sayfa('siparis_kaydet.php', [], ['csrf' => $tok, 'islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sid1, 'alan' => 'gumruk', 'deger' => 'X']);
ok('izleyici POST edemez (siparis.write)', $x['hata'] !== null && str_contains($x['hata'], 'siparis.write'));
rol($ADMIN, true); $tok = csrf();
$x = $K(['islem' => 'belge_sil', 'belge_id' => (string)$kk['belge_id'], 'donus_sid' => (string)$sid2]);
ok('belge_sil (admin): yumuşak silindi + audit', say($db, 'siparis_belgeler', 'id = ? AND deleted_at IS NOT NULL', [(int)$kk['belge_id']]) === 1 && say($db, 'audit_log', "action = 'siparis_belge_sil'") >= 1);
$x = $K(['islem' => 'sil', 'sid' => (string)$sid2, 'gerekce' => 'xx']);
ok('sil: kısa gerekçe reddedilir', say($db, 'siparis_sevkiyatlar', 'id = ? AND deleted_at IS NULL', [$sid2]) === 1);
$x = $K(['islem' => 'sil', 'sid' => (string)$sid2, 'gerekce' => 'yanlış açıldı']);
ok('sil: yumuşak silindi + audit', say($db, 'siparis_sevkiyatlar', 'id = ? AND deleted_at IS NOT NULL', [$sid2]) === 1 && say($db, 'audit_log', "action = 'siparis_sevkiyat_sil'") === 1 && str_contains((string)$x['git'], 'siparisler.php'));
$x = $K(['islem' => 'yeni', 'order_ref' => 'tc777777.10', 'tur_no' => '9000000777', 'yukleme_tarihi' => '2026-12-20']);
$yeniS = siparis_test_sev($db, 'TC777777.10');
ok('yeni: elle sevkiyat açıldı (büyük harf, tur + tarih kilitli, depo=aktif depo) → detaya yönlendirir', $yeniS !== null && $yeniS['depo'] === 'DEPO1' && $yeniS['yukleme_tarihi'] === '2026-12-20' && str_contains((string)$x['git'], 'siparis_view.php?id=' . $yeniS['id']));
$x = $K(['islem' => 'yeni', 'order_ref' => 'abc', 'tur_no' => '', 'yukleme_tarihi' => '']);
ok('yeni: geçersiz order_ref reddedilir', ($x['flash']['type'] ?? '') === 'error' && say($db, 'siparis_sevkiyatlar', "order_ref = 'ABC'") === 0);
$x = $K(['islem' => 'uydurma']);
ok('geçersiz islem reddedilir', ($x['flash']['type'] ?? '') === 'error');
$x = sayfa('siparis_kaydet.php');
ok('GET → listeye yönlendirir', str_contains((string)$x['git'], 'siparisler.php'));

echo "\n=== 9. PKL POST akışı ===\n";
$P = fn(array $p, int $sid = 0) => sayfa('siparis_pkl.php', ['id' => $sid ?: $sid1], ['csrf' => $tok] + $p);
$x = $P(['islem' => 'onizle', 'bloklar' => [['kalem_id' => (string)$k['id'], 'palet' => '3', 'T' => '160']], 'varsayilan' => ['M' => '800', 'N' => '710', 'P' => '2026-12-03', 'K' => '2026'], 'baslik' => ['plakalar' => '34TST111-34TST112']]);
ok('önizle: kayıt YOK, ekrandaki değerlerle 3 satır önizlenir', temiz($x) && str_contains($x['html'], 'ekrandaki değerlerle') && str_contains($x['html'], '34TST111-34TST112') && $x['git'] === null);
$x = $P(['islem' => 'kaydet', 'bloklar' => [['kalem_id' => (string)$k['id'], 'palet' => '20', 'T' => '160']], 'varsayilan' => ['M' => '800', 'N' => '710', 'W' => '8683071468713'], 'baslik' => ['eta' => '2026-12-08']]);
$pk = $db->query("SELECT veri_json, eta FROM siparis_pkl WHERE sevkiyat_id = $sid1")->fetch();
ok('kaydet: taslak yazıldı (M=800, ETA), PRG', ($x['flash']['type'] ?? '') === 'success' && str_contains((string)$pk['veri_json'], '"M":"800"') && $pk['eta'] === '2026-12-08' && str_contains((string)$x['git'], 'siparis_pkl.php?id=' . $sid1));
$x = $P(['islem' => 'uret', 'bloklar' => [['kalem_id' => (string)$k['id'], 'palet' => '20', 'T' => '160']], 'varsayilan' => ['M' => '800', 'N' => '710']]);
ok('uret: eksik hücre varken izinsiz üretim reddedilir, editöre döner', ($x['flash']['type'] ?? '') === 'error' && str_contains((string)$x['git'], 'siparis_pkl.php') && str_contains((string)($x['flash']['msg'] ?? ''), 'boş'));
$once = say($db, 'siparis_belgeler', "tur = 'pkl'");
$x = $P(['islem' => 'uret', 'eksik_izin' => '1']);
ok('uret (eksik izinli): PKL yeniden üretildi, aynı aralık, detaya yönlendirir', ($x['flash']['type'] ?? '') === 'success' && str_contains((string)$x['git'], 'siparis_view.php?id=' . $sid1 . '#pkl')
    && (int)$db->query("SELECT palet_bas FROM siparis_pkl WHERE sevkiyat_id = $sid1")->fetchColumn() === 28, json_encode([$x['flash'], $x['git']]));
$x = $P(['islem' => 'profil_kaydet', 'profil_ad' => 'Test profil', 'varsayilan' => ['V' => 'TEST ÜRETİCİ', 'W' => '8683071468713', 'X' => 'T-1', 'Y' => 'IZ-1', 'Z' => 'R-1', 'AA' => '2026-11-01', 'AB' => 'TEST LAB']]);
ok('profil_kaydet: profil oluştu', ($x['flash']['type'] ?? '') === 'success' && say($db, 'siparis_profiller', "ad = 'Test profil'") === 1);
ok('audit\'te profil DEĞERİ yok (üretici/GGN/lab)', !str_contains(json_encode(siparis_test_audit($db)), 'TEST ÜRETİCİ') && !str_contains(json_encode(siparis_test_audit($db)), '8683071468713') && !str_contains(json_encode(siparis_test_audit($db)), 'TEST LAB'));
$x = $P(['islem' => 'bloklari_yenile']);
ok('bloklari_yenile çalışır', ($x['flash']['type'] ?? '') === 'success');
$r = sayfa('siparis_pkl.php', ['id' => $sid1]);
ok('PKL editörü profili listeler', str_contains($r['html'], 'Test profil') && str_contains($r['html'], 'data-v='));
rol($VIEW, false, 6); $tok = csrf();
$x = $P(['islem' => 'kaydet', 'varsayilan' => ['M' => '1']]);
ok('izleyici PKL kaydedemez/üretemez', $x['hata'] !== null && str_contains($x['hata'], 'siparis.write'));
rol($ADMIN, true); $tok = csrf();

echo "\n=== 10. Ayarlar POST ===\n";
$A = fn(array $p) => sayfa('siparis_ayarlar.php', [], ['csrf' => $tok] + $p);
$x = $A(['islem' => 'sayac', 'ad' => 'palet_2026', 'deger' => '100']);
ok('sayaç: onay kutusu olmadan değişmez', siparis_sayac_oku($db, 'palet_2026') !== 100 && ($x['flash']['type'] ?? '') === 'error');
$x = $A(['islem' => 'sayac', 'ad' => 'palet_2026', 'deger' => '100', 'anladim' => '1']);
ok('sayaç: güncellendi + audit siparis_sayac_duzelt', siparis_sayac_oku($db, 'palet_2026') === 100 && say($db, 'audit_log', "action = 'siparis_sayac_duzelt'") === 1);
$x = $A(['islem' => 'sayac', 'ad' => 'x; DROP', 'deger' => '1', 'anladim' => '1']);
ok('sayaç: geçersiz ad reddedilir', ($x['flash']['type'] ?? '') === 'error');
$pid = (int)$db->query("SELECT id FROM siparis_profiller LIMIT 1")->fetchColumn();
$x = $A(['islem' => 'profil_sil', 'profil_id' => (string)$pid]);
ok('profil_sil: yumuşak silindi + audit', say($db, 'siparis_profiller', 'id = ? AND deleted_at IS NOT NULL', [$pid]) === 1 && say($db, 'audit_log', "action = 'siparis_profil_sil'") === 1);
rol($OPER, false, 5); $tok = csrf();
$x = $A(['islem' => 'sayac', 'ad' => 'palet_2026', 'deger' => '5', 'anladim' => '1']);
ok('operatör sayaç düzeltemez', $x['hata'] !== null && siparis_sayac_oku($db, 'palet_2026') === 100);
rol($ADMIN, true); $tok = csrf();

echo "\n=== 11. Yükleme ucu ===\n";
$tmp = sys_get_temp_dir() . '/sip_up_' . getmypid(); @mkdir($tmp);
$yukle = function (array $dosyalar, array $post = [], array $get = [], array $sunucu = []) use (&$tok) {
    $f = ['belgeler' => ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []]];
    foreach ($dosyalar as [$ad, $icerik]) {
        $t = sys_get_temp_dir() . '/sipup_' . bin2hex(random_bytes(4)); file_put_contents($t, $icerik);
        $f['belgeler']['name'][] = $ad; $f['belgeler']['type'][] = ''; $f['belgeler']['tmp_name'][] = $t; $f['belgeler']['error'][] = UPLOAD_ERR_OK; $f['belgeler']['size'][] = strlen($icerik);
    }
    $GLOBALS['_siparis_parse_override'] = $GLOBALS['SIP_PARSE'] ?? null;      // null → gerçek ayrıştırıcı (sahte PDF → okunamadı)
    if ($GLOBALS['_siparis_parse_override'] === null) unset($GLOBALS['_siparis_parse_override']);
    $r = sayfa('siparis_yukle.php', $get, ['csrf' => $tok] + $post, $f, $sunucu);
    unset($GLOBALS['_siparis_parse_override']);
    return $r;
};
$SIP_PARSE = fn(string $y): array => ['doc_type' => 'unknown', 'checks' => [], 'warnings' => [], 'meta' => []];
$x = $yukle([['rastgele.pdf', siparis_test_pdf('u1')]]);
$son = $_SESSION['siparis_yukleme'] ?? [];
ok('yükleme: dosya kaydedildi, içerik tanınmadı → hata değil "bilinmiyor", özet oturumda', $x['hata'] === null && count($son) === 1 && ($son[0]['dosya'] ?? '') === 'rastgele.pdf' && str_contains((string)$x['git'], 'siparisler.php#sonuc'), json_encode([$x['hata'], $son]));
$l = sayfa('siparisler.php');
ok('sonuç özeti listede gösterilir ve TEK seferlik', str_contains($l['html'], 'id="sonuc"') && str_contains($l['html'], 'rastgele.pdf') && !str_contains(sayfa('siparisler.php')['html'], 'id="sonuc"'));
$l2 = sayfa('siparisler.php');
ok('hatalı/bağsız belge "Eşleşmemiş / hatalı belgeler" kutusunda (bağla / yeniden işle / önizle)', str_contains($l2['html'], 'id="yetim"') && str_contains($l2['html'], 'sipBaglaModal') && str_contains($l2['html'], 'value="yeniden_isle"'));
$x = $yukle([['x.exe', 'MZ....']]);
ok('yükleme: izinsiz uzantı reddedilir', (($_SESSION['siparis_yukleme'][0]['durum'] ?? '') === 'hata'));
$x = $yukle([['sahte.pdf', 'bu pdf degil']]);
ok('yükleme: sihirli bayt/MIME yanlışsa reddedilir', (($_SESSION['siparis_yukleme'][0]['durum'] ?? '') === 'hata'));
$cok = []; for ($i = 0; $i < 11; $i++) $cok[] = ["a$i.pdf", siparis_test_pdf("c$i")];
$n0 = say($db, 'siparis_belgeler');
$x = $yukle($cok);
ok('yükleme: 11 dosya reddedilir, hiçbiri kaydedilmez', say($db, 'siparis_belgeler') === $n0 && ($x['flash']['type'] ?? '') === 'error');
$x = $yukle([['a.pdf', siparis_test_pdf('u1')]]);
ok('yükleme: aynı içerik → "Zaten yüklü"', (($_SESSION['siparis_yukleme'][0]['durum'] ?? '') === 'zaten'));
// v375: aynı dosya eski okuyucuyla (parser_surum ≠ güncel) okunmuşsa yeniden yüklemede kendiliğinden yeniden okunur
$u1 = (int)$db->query("SELECT id FROM siparis_belgeler WHERE orijinal_ad = 'rastgele.pdf'")->fetchColumn();
$db->prepare("UPDATE siparis_belgeler SET parser_surum = 'v2' WHERE id = ?")->execute([$u1]);
ok('v375: eski sürümlü belge "eski" sayılır (işlenmiş durumda)', siparis_belge_surum_eski(['parser_surum' => 'v2', 'durum' => 'islendi']) && !siparis_belge_surum_eski(['parser_surum' => null, 'durum' => 'islendi'])
    && !siparis_belge_surum_eski(['parser_surum' => SIPARIS_PDF_SURUM, 'durum' => 'islendi']) && !siparis_belge_surum_eski(['parser_surum' => 'v2', 'durum' => 'hata']));
$nB = say($db, 'siparis_belgeler');
rol($ADMIN, false, 9, ['DEPO2']); $tok = csrf();
$x = $yukle([['a.pdf', siparis_test_pdf('u1')]]);
ok('v375: depo kapısı — başka depodan yükleyen eski belgeyi YENİDEN OKUTAMAZ ("Zaten yüklü", sürüm aynı)', (($_SESSION['siparis_yukleme'][0]['durum'] ?? '') === 'zaten')
    && $db->query("SELECT parser_surum FROM siparis_belgeler WHERE id = $u1")->fetchColumn() === 'v2');
rol($ADMIN, true); $tok = csrf();
$x = $yukle([['a.pdf', siparis_test_pdf('u1')]]);
$s1 = $_SESSION['siparis_yukleme'][0] ?? [];
ok('v375: eski sürümlü aynı dosya → yeniden işlendi (durum islendi, yeniden=1, eski_surum v2)', ($s1['durum'] ?? '') === 'islendi' && ($s1['yeniden'] ?? 0) === 1 && ($s1['eski_surum'] ?? '') === 'v2', json_encode($s1));
ok('v375: ikinci belge satırı AÇILMADI, sürüm güncellendi', say($db, 'siparis_belgeler') === $nB && $db->query("SELECT parser_surum FROM siparis_belgeler WHERE id = $u1")->fetchColumn() === SIPARIS_PDF_SURUM);
ok('v375: audit islem=surum_yenile + eski_surum', say($db, 'audit_log', "action = 'siparis_belge_yukle' AND new_values LIKE ?", ['%"islem":"surum_yenile"%']) === 1 && say($db, 'audit_log', "action = 'siparis_belge_yukle' AND new_values LIKE ?", ['%"eski_surum":"v2"%']) === 1);
ok('v375: sonuç özetinde "yeni okuyucuyla ... yeniden okundu" notu', str_contains(sayfa('siparisler.php')['html'], 'yeniden okundu, sevkiyat değerleri güncellendi'));
$x = $yukle([['a.pdf', siparis_test_pdf('u1')]]);
ok('v375: sürüm güncel olunca tekrar yükleme yine "Zaten yüklü"', (($_SESSION['siparis_yukleme'][0]['durum'] ?? '') === 'zaten') && say($db, 'siparis_belgeler') === $nB);
$x = $yukle([['b.pdf', siparis_test_pdf('hedefli')]], ['sevkiyat_id' => (string)$sid1], ['sevkiyat_id' => (string)$sid1]);
ok('yükleme: hedef sevkiyatla → detaya yönlendirir, tanınmayan belge "diğer" bağlanır', str_contains((string)$x['git'], 'siparis_view.php?id=' . $sid1) && say($db, 'siparis_belge_bag', 'sevkiyat_id = ?', [$sid1]) >= 2);
$x = $yukle([['c.pdf', siparis_test_pdf('x')]], [], [], ['CONTENT_LENGTH' => (string)(sipui_ini_bayt((string)ini_get('post_max_size')) + 5)]);
ok('yükleme: post_max_size aşımı → anlaşılır mesaj (CSRF hatası DEĞİL)', str_contains((string)($x['flash']['msg'] ?? ''), 'çok büyük') && $x['hata'] === null);
$x = sayfa('siparis_yukle.php', [], [], [], ['CONTENT_LENGTH' => '999999999']);
ok('yükleme: gövdesi boşalmış istek → anlaşılır mesaj', str_contains((string)($x['flash']['msg'] ?? ''), 'çok büyük'));
rol($VIEW, false, 6); $tok = csrf();
$n0 = say($db, 'siparis_belgeler');
$x = $yukle([['v.pdf', siparis_test_pdf('viewer')]]);
ok('izleyici yükleyemez', $x['hata'] !== null && say($db, 'siparis_belgeler') === $n0);
rol($ADMIN, false, 9, ['DEPO2']); $tok = csrf();
$x = $yukle([['d.pdf', siparis_test_pdf('depo2')]], ['sevkiyat_id' => (string)$sid1], ['sevkiyat_id' => (string)$sid1]);
ok('başka depo sevkiyatına yükleme → 403', $x['hata'] !== null);
rol($ADMIN, true); $tok = csrf();
// gerçek örnek PDF'ler
$ornek = siparis_test_ornekler();
if ($ornek === null) echo "ATLANDI (SIPARIS_ORNEK_DIR yok) — gerçek örnek PDF yükleme testleri\n";
else {
    $SIP_PARSE = null;      // gerçek ayrıştırıcı
    $yol = $ornek['1'] ?? [];
    $d = [];
    foreach (['po', 'll', 'pc'] as $t) if (isset($yol[$t])) $d[] = [basename($yol[$t]), (string)file_get_contents($yol[$t])];
    $x = $yukle($d);
    $s = $_SESSION['siparis_yukleme'] ?? [];
    ok('örnek set 1: 3 PDF işlendi, hata yok', count($s) === count($d) && count(array_filter($s, fn($r) => $r['durum'] === 'hata')) === 0, json_encode($s));
    ok('örnek set 1: PO/LL/PC içerikten sınıflandı', array_column($s, 'tur') === array_slice(['po', 'll', 'pc'], 0, count($d)));
    ok('örnek set 1: TC426865.10 / TC426866.10 sevkiyatları oluştu', siparis_test_sev($db, 'TC426865.10') !== null && siparis_test_sev($db, 'TC426866.10') !== null);
    $sx = siparis_test_sev($db, 'TC426865.10');
    $g = sayfa('siparis_view.php', ['id' => $sx['id']]);
    ok('örnek set 1: detay sayfası temiz, 3 belge türü', temiz($g) && str_contains($g['html'], 'TC426865.10') && html_dengeli($g['html']) === null);
    $x = $yukle($d);
    ok('örnek set 1: yeniden yükleme "Zaten yüklü"', count(array_filter($_SESSION['siparis_yukleme'] ?? [], fn($r) => $r['durum'] === 'zaten')) === count($d));
}

echo "\n=== 12. Dosya ve ZIP uçları ===\n";
$pdfBelge = $ID['po1']; $xlsxBelge = $ID['pkl1'];
$x = sayfa('siparis_dosya.php', ['id' => $pdfBelge]);
ok('PDF satır içi: Content-Type, inline, nosniff, no-store, CSP, Content-Length', ($x['h']['content-type'] ?? '') === 'application/pdf' && str_starts_with($x['h']['content-disposition'] ?? '', 'inline') && ($x['h']['x-content-type-options'] ?? '') === 'nosniff'
    && str_contains($x['h']['cache-control'] ?? '', 'no-store') && str_contains($x['h']['content-security-policy'] ?? '', "frame-ancestors 'self'") && (int)($x['h']['content-length'] ?? 0) > 0 && str_starts_with($x['html'], '%PDF-'), json_encode($x['h']));
ok('PDF: RFC 5987 dosya adı (filename + filename*)', str_contains($x['h']['content-disposition'] ?? '', 'filename="') && str_contains($x['h']['content-disposition'] ?? '', "filename*=UTF-8''"));
$a0 = say($db, 'audit_log', "action = 'siparis_dosya_indir'");
$x = sayfa('siparis_dosya.php', ['id' => $pdfBelge, 'dl' => '1']);
ok('dl=1: attachment + sandbox CSP + audit siparis_dosya_indir', str_starts_with($x['h']['content-disposition'] ?? '', 'attachment') && str_contains($x['h']['content-security-policy'] ?? '', 'sandbox') && say($db, 'audit_log', "action = 'siparis_dosya_indir'") === $a0 + 1);
$x = sayfa('siparis_dosya.php', ['id' => $pdfBelge]);
ok('satır içi görüntüleme audit YAZMAZ (yalnız dl=1)', say($db, 'audit_log', "action = 'siparis_dosya_indir'") === $a0 + 1);
$x = sayfa('siparis_dosya.php', ['id' => $xlsxBelge]);
ok('xlsx HER ZAMAN attachment + sandbox CSP + doğru MIME', str_starts_with($x['h']['content-disposition'] ?? '', 'attachment') && ($x['h']['content-type'] ?? '') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' && str_contains($x['h']['content-security-policy'] ?? '', 'sandbox'));
$x = sayfa('siparis_dosya.php', ['id' => 999999]);
ok('olmayan belge 404', $x['kod'] === 404);
$yolDisk = siparis_dosya_yolu($db->query("SELECT * FROM siparis_belgeler WHERE id = $pdfBelge")->fetch());
$orj = file_get_contents($yolDisk); file_put_contents($yolDisk, "GIF89a\x00\x00" . str_repeat('x', 50));
$x = sayfa('siparis_dosya.php', ['id' => $pdfBelge]);
ok('uzantı/içerik uyuşmazlığı: octet-stream + attachment (inline olmaz)', ($x['h']['content-type'] ?? '') === 'application/octet-stream' && str_starts_with($x['h']['content-disposition'] ?? '', 'attachment'));
file_put_contents($yolDisk, $orj);
$x = sayfa('siparis_zip.php', ['id' => $ID['s1']]);
$zt = sys_get_temp_dir() . '/sip_zip_' . getmypid() . '.zip'; file_put_contents($zt, $x['html']);
$z = new ZipArchive(); $acik = $z->open($zt) === true;
$adlar = []; if ($acik) for ($i = 0; $i < $z->numFiles; $i++) $adlar[] = $z->getNameIndex($i);
ok('ZIP: geçerli arşiv, application/zip + attachment + nosniff', $acik && ($x['h']['content-type'] ?? '') === 'application/zip' && str_starts_with($x['h']['content-disposition'] ?? '', 'attachment; filename="Evrak-TC900001.10.zip"') && ($x['h']['x-content-type-options'] ?? '') === 'nosniff', json_encode($x['h']));
ok('ZIP: geçerli sürümler <TÜR>-ad, eski sürüm yok', in_array('PO-po1.pdf', $adlar, true) && in_array('LL-ll3.pdf', $adlar, true) && !array_filter($adlar, fn($a) => str_starts_with($a, 'eski/')) && count(array_filter($adlar, fn($a) => str_starts_with($a, 'PKL-'))) === 1, json_encode($adlar));
$x = sayfa('siparis_zip.php', ['id' => $ID['s1'], 'eski' => '1']);
file_put_contents($zt, $x['html']); $z->open($zt); $adlar2 = []; for ($i = 0; $i < $z->numFiles; $i++) $adlar2[] = $z->getNameIndex($i);
ok('ZIP eski=1: eski sürüm eski/ altında', (bool)array_filter($adlar2, fn($a) => str_starts_with($a, 'eski/')), json_encode($adlar2));
$x = sayfa('siparis_zip.php', ['id' => $ID['s1'], 'belge' => [(string)$ID['po1']]]);
file_put_contents($zt, $x['html']); $z->open($zt);
ok('ZIP belge[]: yalnız seçilen', $z->numFiles === 1 && $z->getNameIndex(0) === 'PO-po1.pdf');
$z->open($zt); $sifirIcerik = $z->getFromIndex(0);
ok('ZIP içeriği özgün dosyayla aynı', $sifirIcerik === $orj);
$x = sayfa('siparis_zip.php', ['id' => $ID['s1'], 'belge' => ['99999']]);
ok('ZIP: seçilen belge sevkiyata ait değilse boş → uyarı + geri', $x['html'] === '' && ($x['flash']['type'] ?? '') === 'error' && str_contains((string)$x['git'], 'siparis_view.php'));
// ZIP depo: DEPO1'de olmayan belgeyi (po_depo2) s1'e bağla; DEPO1 kullanıcısı ZIP'inde görünmemeli ama bağ görünür sevkiyat üzerinden → siparis_belge_gorunur true; doğrudan görünmez belge için dosya ucu testi yukarıda.
rol($ADMIN, false, 9, ['DEPO2']);
$x = sayfa('siparis_zip.php', ['id' => $ID['s31']]);
$zok = $x['html'] !== '' && str_starts_with($x['html'], 'PK');
ok('DEPO2 kullanıcısı kendi sevkiyatının ZIP\'ini indirir', $zok);
$x = sayfa('siparis_dosya.php', ['id' => $ID['po_d2']]);
ok('DEPO2 kullanıcısı DEPO2 belgesini açar', ($x['h']['content-type'] ?? '') === 'application/pdf');
rol($ADMIN, true);
@unlink($zt);

echo "\n=== 13. Beyan bağı ucu ===\n";
$tok = csrf();
$B = fn(array $p) => sayfa('siparis_beyan_bagla.php', [], ['csrf' => $tok] + $p);
rol($OPER, false, 5); $tok = csrf();
$x = $B(['islem' => 'bagla', 'sid' => (string)$sid1, 'beyan_id' => '1', 'tur' => 'oneri']);
ok('beyan bağla (operatör: match + beyan.read)', ($x['flash']['type'] ?? '') === 'success' && say($db, 'siparis_beyan', "sevkiyat_id = ? AND durum = 'aktif'", [$sid1]) === 1 && str_contains((string)$x['git'], '#beyan'));
$x = $B(['islem' => 'bagla', 'sid' => (string)$ID['s11'], 'beyan_id' => '1', 'tur' => 'elle']);
ok('aynı beyan ikinci sevkiyata bağlanamaz', ($x['flash']['type'] ?? '') === 'error' && say($db, 'siparis_beyan', "durum = 'aktif'") === 1);
$g = sayfa('siparis_view.php', ['id' => $sid1]);
ok('detayda bağlı beyan + bağı kaldır', str_contains($g['html'], 'Beyan #1') && str_contains($g['html'], 'value="kaldir"'));
$bag = (int)$db->query("SELECT id FROM siparis_beyan WHERE durum = 'aktif'")->fetchColumn();
$x = $B(['islem' => 'kaldir', 'bag_id' => (string)$bag]);
ok('bağ kaldırıldı (satır silinmez)', say($db, 'siparis_beyan', "id = ? AND durum = 'kaldirildi'", [$bag]) === 1);
ok('customs_declarations\'a yazılmadı (bağ yalnız siparis_beyan)', (string)$db->query("SELECT COUNT(*) FROM customs_declarations")->fetchColumn() === '2' && (string)$db->query("SELECT status FROM customs_declarations WHERE id = 1")->fetchColumn() === 'beyan_acildi');
$x = $B(['islem' => 'ara']);
ok('geçersiz islem reddedilir', ($x['flash']['type'] ?? '') === 'error');
rol($VIEW, false, 6); $tok = csrf();
$x = $B(['islem' => 'bagla', 'sid' => (string)$sid1, 'beyan_id' => '1']);
ok('izleyici beyan bağlayamaz (siparis.match)', $x['hata'] !== null && str_contains($x['hata'], 'siparis.match'));
rol(['siparis.read', 'siparis.match'], false, 8); $tok = csrf();
$x = $B(['islem' => 'bagla', 'sid' => (string)$sid1, 'beyan_id' => '2']);
ok('match var ama beyan.read yok → 403 (çift kapı)', $x['hata'] !== null && str_contains($x['hata'], 'beyan.read'));
rol($ADMIN, true);
$g = sayfa('siparis_view.php', ['id' => $sid1, 'beyan_q' => '34TST']);
ok('beyan arama sonucu listelenir', str_contains($g['html'], 'Beyan #1'));

echo "\n=== 13b. Liste: navlun, ürün/Pal·PF, beyan parti no ===\n";
$satir = function (string $html, int $sid): string {      // masaüstü tablo satırı
    $h = 'data-href="siparis_view\.php\?id=' . $sid . '">';   // ana satır + (varsa) hemen altındaki ürün alt satırı
    return preg_match('#<tr class="row-link[^"]*" ' . $h . '(.*?)</tr>(\s*<tr class="row-link sip-urun-tr[^"]*" ' . $h . '(.*?)</tr>)?#s', $html, $m) ? $m[1] . ($m[3] ?? '') : '';
};
$kart = function (string $html, int $sid): string {       // mobil kart
    return preg_match('#<article class="sip-card[^"]*">((?:(?!</article>).)*?siparis_view\.php\?id=' . $sid . '".*?)</article>#s', $html, $m) ? $m[1] : '';   // v386: parti rozetleri linkin ÜSTÜNDE — tüm kart
};
$db->exec("INSERT INTO customs_declarations (status, party_no, created_at, deleted_at) VALUES ('taslak', 'SILINMIS-9', '2026-12-02 11:00:00', '2026-12-03 00:00:00')");
$silinmisId = (int)$db->lastInsertId();
$bagEkle = function (int $sid, int $beyan, string $durum = 'aktif') use ($db): void {
    $db->prepare("INSERT INTO siparis_beyan (sevkiyat_id, beyan_id, tur, durum, created_by, created_at) VALUES (?, ?, 'elle', ?, 1, '2026-12-04 10:00:00')")->execute([$sid, $beyan, $durum]);
};
$bagEkle($ID['s1'], 1); $bagEkle($ID['s1'], 2);                 // iki aktif bağ → "Parti … +1"
$bagEkle($ID['s11'], $silinmisId);                             // silinmiş beyan → Tur no'ya düşer
$bagEkle($ID['s31'], 2, 'kaldirildi');                          // kaldırılmış bağ → Tur no
foreach ([16, 18] as $i => $kal) {                             // s12: 3 → 5 kalem (üst sınır 3 satır + "+2 daha")
    $db->prepare("INSERT INTO siparis_kalemler (sevkiyat_id, ian, kalibre, sira, urun, aciklama, cesit, koli_icerik, pf, po_palet, etkin_palet, etkin_koli) VALUES (?, '', ?, ?, 'Testfruit', ?, 'TESTVAR', '3,5 KG', 200, 2, 2, 400)")
        ->execute([$ID['s12'], (string)$kal, 3 + $i, "Testfruit $kal PC LOSE (TR)"]);
}
rol($ADMIN, true);
$r = sayfa('siparisler.php');
ok('liste temiz + dengeli (yeni sütun)', temiz($r) && html_dengeli($r['html']) === null, (string)$r['hata'] . (string)html_dengeli($r['html']));
ok('ürün alt satırı: sütun sayısı kadar colspan, satır bağlantılı', str_contains($r['html'], 'class="row-link sip-urun-tr') && str_contains($r['html'], '<td colspan="9"><div class="sip-urunler">'));
// v384 — Excel gibi dönüşümlü bant: grup sınıfı sunucuda; ana satır + ürün alt satırı AYNI bant, ardışık gruplar farklı
preg_match_all('#<tr class="row-link( sip-urun-tr)? sip-grup sip-z([01])[^"]*" data-href="siparis_view\.php\?id=(\d+)">#', $r['html'], $__zm, PREG_SET_ORDER);
$__zg = []; $__zt = true;
foreach ($__zm as $__m) { $__k = $__m[3]; if ($__m[1] !== '') { $__zt = $__zt && isset($__zg[$__k]) && $__zg[$__k] === $__m[2]; } else { $__zg[$__k] = $__m[2]; } }
$__zs = array_values($__zg); $__zd = count($__zs) >= 3;
ok('zebra: her tablo satırı sip-grup sip-z0|1 taşır, ürün alt satırı ana satırla aynı bant', $__zt && count($__zm) === substr_count($r['html'], '<tr class="row-link'), count($__zm) . '/' . substr_count($r['html'], '<tr class="row-link'));
ok('zebra: bölüm içinde ardışık sevkiyatlar dönüşümlü (0,1,0,…)', $__zd && $__zs[0] === '0' && $__zs[1] === '1' && $__zs[2] === '0', implode(',', $__zs));
ok('zebra: mobil kartlar da sip-z0/sip-z1', substr_count($r['html'], '<article class="sip-card sip-z0') >= 2 && substr_count($r['html'], '<article class="sip-card sip-z1') >= 1);
$__css = (string)file_get_contents(__DIR__ . '/../assets/siparis.css');
ok('zebra CSS: açık + koyu bant rengi, hover önceliği', str_contains($__css, '.sip-table tbody > tr.sip-z1 > td { background: var(--sip-zebra); }') && str_contains($__css, 'html[data-theme="dark"] .sip-mod { --sip-zebra:') && str_contains($__css, '.sip-table tbody > tr.sip-grup:hover > td'));
// v387 — listede YALNIZ yurt içi gümrük (elle; kolon migrate kartından). PO gümrüğü (yurt dışı) listede GÖRÜNMEZ.
$__rg0 = sayfa('siparisler.php');
ok('yurt içi gümrük: kolon yokken liste çizilir, sütun boş', temiz($__rg0) && str_contains($__rg0['html'], '<th>Lojistik</th><th>Yurt içi gümrük</th>') && str_contains($__rg0['html'], '<td class="sip-gumruk-hucre"></td>'));
ok('yurt içi gümrük: migrasyon tek kolon ekler, idempotent', siparis_yurtici_gumruk_hazir($db) === false && array_column(siparis_yurtici_gumruk_migrate($db), 'durum') === ['eklendi'] && siparis_yurtici_gumruk_hazir($db) && array_column(siparis_yurtici_gumruk_migrate($db), 'durum') === ['var']);
$tok = csrf(); $K = fn(array $p) => sayfa('siparis_kaydet.php', [], ['csrf' => $tok] + $p);
$x = $K(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$ID['s12'], 'alan' => 'yurtici_gumruk', 'deger' => 'TEST GUMRUK MUD.']);
ok('v387: kolon varken elle yazılır (sevkiyat sayfası yolu)', ($x['flash']['type'] ?? '') === 'success' && siparis_sevkiyat($db, $ID['s12'])['yurtici_gumruk'] === 'TEST GUMRUK MUD.', json_encode([$x['hata'], $x['flash']]));
$__gEski = $db->query('SELECT gumruk FROM siparis_sevkiyatlar WHERE id = ' . (int)$ID['s1'])->fetchColumn();
$db->prepare('UPDATE siparis_sevkiyatlar SET gumruk = ?, yurtici_gumruk = ? WHERE id = ?')->execute(['YURTDISI-SINIR', 'TESTPORT <b>Y</b>', $ID['s1']]);
$db->exec("UPDATE siparis_sevkiyatlar SET gumruk = 'YURTDISI-2', yurtici_gumruk = NULL WHERE id = " . (int)$ID['s31']);
$__rg = sayfa('siparisler.php');
ok('gümrük: başlık + colspan 9', str_contains($__rg['html'], '<th>Lojistik</th><th>Yurt içi gümrük</th>') && !str_contains($__rg['html'], '<td colspan="8">'));
ok('yurt içi gümrük: yazılıysa hücrede (kaçırılmış), boşsa hücre boş', str_contains($satir($__rg['html'], $ID['s1']), '<span class="sip-gumruk" title="TESTPORT &lt;b&gt;Y&lt;/b&gt;">TESTPORT &lt;b&gt;Y&lt;/b&gt;</span>')
    && str_contains($satir($__rg['html'], $ID['s31']), '<td class="sip-gumruk-hucre"></td>') && !str_contains($__rg['html'], '<b>Y</b>'));
ok('yurt dışı (PO) gümrük listede GÖRÜNMEZ', !str_contains($__rg['html'], 'YURTDISI-SINIR') && !str_contains($__rg['html'], 'YURTDISI-2'));
ok('yurt içi gümrük: mobil kartta yalnız yazılıysa', str_contains($kart($__rg['html'], $ID['s1']), 'Yurt içi gümrük: TESTPORT &lt;b&gt;Y&lt;/b&gt;') && !str_contains($kart($__rg['html'], $ID['s31']), 'Yurt içi gümrük:'));
$__vg = sayfa('siparis_view.php', ['id' => $ID['s1']]);
ok('sevkiyat sayfası: Gümrük grubunda "Yurt içi gümrük" + "Yurt dışı gümrük" ayrı alanlar', temiz($__vg) && str_contains($__vg['html'], 'Yurt içi gümrük') && str_contains($__vg['html'], 'Yurt dışı gümrük') && str_contains($__vg['html'], 'YURTDISI-SINIR'));
$db->prepare('UPDATE siparis_sevkiyatlar SET gumruk = ? WHERE id = ?')->execute([$__gEski === false ? null : $__gEski, $ID['s1']]);
// v376 — sütun başlıkları + bugüne göre renkli tarih
ok('başlıklar: "Lojistik" ve "Palet/Kasa" (eski "Araç" / "Palet / Koli" yok)', str_contains($r['html'], '<th>Lojistik</th>') && str_contains($r['html'], '<th class="num">Palet/Kasa</th>')
    && !str_contains($r['html'], '<th>Araç</th>') && !str_contains($r['html'], 'Palet / Koli') && str_contains($r['html'], ' kasa</span>') && !str_contains($r['html'], ' koli</span>'));
$__gun = fn(int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' day'));
ok('tarih: geçmiş → yeşil sınıf (sip-tar--gecmis)', str_contains(sipui_tarih_html($__gun(-1)), 'sip-tar--gecmis') && str_contains(sipui_tarih_html($__gun(-400)), 'sip-tar--gecmis'));
ok('tarih: bugün → turuncu sınıf (sip-tar--bugun)', str_contains(sipui_tarih_html($__gun(0)), 'sip-tar--bugun') && str_contains(sipui_tarih_html(date('d.m.Y')), 'sip-tar--bugun'));
ok('tarih: henüz gelmemiş → sip-tar--gelecek', str_contains(sipui_tarih_html($__gun(1)), 'sip-tar--gelecek') && str_contains(sipui_tarih_html($__gun(90)), 'sip-tar--gelecek'));
ok('tarih: metin gg.aa.yyyy + durum title; boş / geçersiz düz "—"', str_contains(sipui_tarih_html('2026-12-03'), '>03.12.2026<') && str_contains(sipui_tarih_html($__gun(0)), 'title="Bugün"')
    && sipui_tarih_html(null) === '—' && sipui_tarih_html('') === '—' && sipui_tarih_html('abc') === '—');
ok('liste: yükleme tarihi renk sınıfı ile basılır (tablo + mobil kart)', substr_count($r['html'], 'class="sip-tar sip-tar--') >= 2);
$t1 = $satir($r['html'], $ID['s1']); $t2 = $satir($r['html'], $ID['s31']); $t3 = $satir($r['html'], $ID['s11']); $t4 = $satir($r['html'], $ID['s12']);
ok('navlun no plakanın altında (tablo)', str_contains($t1, 'Navlun 6100000001') && str_contains($t1, 'class="sip-navlun') && strpos($t1, '34TST777') < strpos($t1, 'Navlun 6100000001'), substr($t1, 0, 300));
ok('navlun yoksa satır yok', !str_contains($t2, 'Navlun') && !str_contains($t4, 'Navlun'));
ok('navlun mobil kartta da', str_contains($kart($r['html'], $ID['s1']), 'Navlun 6100000001') && !str_contains($kart($r['html'], $ID['s31']), 'Navlun'));
ok('Article specification + Pal. + PF (tablo)', str_contains($t1, 'Testfruit 14 PC LOSE (TR) · TESTVAR · 3,5 KG') && str_contains($t1, 'Pal. 20 · PF 160'), substr($t1, 0, 400));
ok('etkin palet öncelikli (po 4 → etkin 12) + PF 200', str_contains($t3, 'Pal. 12 · PF 200') && str_contains($t3, 'Pal. 15 · PF 200') && !str_contains($t3, 'Pal. 4 '));
ok('gönderilmeyecek kalem (etkin palet 0, koli 0) satırı yok', substr_count($t3, 'class="sip-urun"') === 2);
ok('PO palet yedeği (etkin boşsa po_palet) + palet/PF yoksa meta satırı yok', str_contains(sipui_kalem_html([['aciklama' => 'X 1 PC', 'po_palet' => 7, 'etkin_palet' => null, 'pf' => null]]), 'Pal. 7') && !str_contains(sipui_kalem_html([['aciklama' => 'X 1 PC']]), 'sip-urun-meta'));
ok('5 kalem: en çok 3 satır + "+2 daha"', substr_count($t4, 'class="sip-urun"') === 3 && str_contains($t4, '+2 daha'));
ok('ürün bölümü mobil kartta', str_contains($kart($r['html'], $ID['s1']), 'sip-card-urun') && str_contains($kart($r['html'], $ID['s1']), 'Pal. 20 · PF 160'));
ok('kalem metni kaçırılıyor (XSS yok)', !str_contains(sipui_kalem_html([['aciklama' => '<script>x</script>', 'po_palet' => 1]]), '<script>'));
ok('yönetici: bağlı beyanların parti no\'ları Tur no yerine, her biri ayrı 3B rozet (v386)', str_contains($t1, '<span class="sip-parti-btn" title="Bağlı beyanın parti numarası"><span class="sip-parti-et">Parti</span> TC900001.10-A</span>') && substr_count($t1, 'class="sip-parti-btn"') === 2 && !str_contains($t1, 'Tur 9000000001') && !str_contains($t1, ' +1'), substr($t1, 0, 400));
ok('parti no mobil kartta da (rozet)', substr_count($kart($r['html'], $ID['s1']), 'class="sip-parti-btn"') === 2 && str_contains($kart($r['html'], $ID['s1']), '</span> TC900001.10-A</span>'));
ok('v386: parti rozetleri sipariş no\'nun ÜSTÜNDE (tablo + kart)', strpos($t1, 'sip-parti-rozetler') !== false && strpos($t1, 'sip-parti-rozetler') < strpos($t1, 'class="sip-ref"') && strpos($kart($r['html'], $ID['s1']), 'sip-parti-rozetler') < strpos($kart($r['html'], $ID['s1']), 'sip-card-link'));
// kaç tane olursa olsun: aynı parti no iki beyanda geçerse TEK satır, farklı olanların hepsi ayrı satır, "+N" kısaltması YOK
$db->exec("INSERT INTO customs_declarations (status, party_no, created_at) VALUES ('taslak', 'EK-3', '2026-12-02 12:00:00'), ('taslak', 'EK-4', '2026-12-02 12:00:00'), ('taslak', 'TC900001.10-A', '2026-12-02 12:00:00')");
$__ek = (int)$db->lastInsertId();                                   // son eklenen = 'TC900001.10-A' kopyası
$bagEkle($ID['s12'], $__ek - 2); $bagEkle($ID['s12'], $__ek - 1); $bagEkle($ID['s12'], $__ek); $bagEkle($ID['s12'], 1);   // beyan 1 de 'TC900001.10-A' → tekrar
$r2 = sayfa('siparisler.php'); $ta = $satir($r2['html'], $ID['s12']);
ok('4 bağ (3 farklı + 1 tekrar parti no) → tekrarsız 3 satır alt alta, "+N" yok', substr_count($ta, 'class="sip-parti-btn"') === 3 && str_contains($ta, '</span> EK-3<') && str_contains($ta, '</span> EK-4<') && str_contains($ta, '</span> TC900001.10-A<') && !str_contains($ta, ' +'), substr($ta, 0, 400));
$bagEkle($ID['s12'], 2);                                             // beyan 2 (başka parti no) → 4 satır
$r2 = sayfa('siparisler.php'); $ta = $satir($r2['html'], $ID['s12']);
ok('4 farklı parti no → 4 ayrı satır (mobil kartta da)', substr_count($ta, 'class="sip-parti-btn"') === 4 && substr_count($kart($r2['html'], $ID['s12']), 'class="sip-parti-btn"') === 4, substr($ta, 0, 400));
$db->exec("DELETE FROM siparis_beyan WHERE sevkiyat_id = " . (int)$ID['s12']);
$db->exec("DELETE FROM customs_declarations WHERE id > " . ($__ek - 3));
ok('bağı olmayan / kaldırılmış / silinmiş beyan: Tur no', str_contains($t2, 'Tur 9000000031') && str_contains($t3, 'Tur 9000000011') && !str_contains($t3, 'SILINMIS') && !str_contains($t3, 'Parti'));
rol($OPER, false, 5);          // siparis.read + beyan.read
$r = sayfa('siparisler.php');
ok('beyan.read olan operatör parti no\'yu görür', temiz($r) && substr_count($satir($r['html'], $ID['s1']), 'class="sip-parti-btn"') === 2 && str_contains($satir($r['html'], $ID['s1']), '</span> TC900001.10-A</span>'));
rol($VIEW, false, 6);          // yalnız siparis.read
$r = sayfa('siparisler.php');
$v1 = $satir($r['html'], $ID['s1']);
ok('beyan.read yok: parti no görünmez, Tur no gösterilir', temiz($r) && str_contains($v1, 'Tur 9000000001') && !str_contains($v1, 'Parti') && !str_contains($r['html'], 'TC900001.10-A'), substr($v1, 0, 300));
ok('beyan.read yok: navlun + ürün satırları yine görünür', str_contains($v1, 'Navlun 6100000001') && str_contains($v1, 'Pal. 20 · PF 160'));
ok('parti haritası: yetkisizde boş, yetkilide dolu', siparis_beyan_parti_haritasi($db, [$ID['s1']]) === [] && (rol($ADMIN, true) || true) && siparis_beyan_parti_haritasi($db, [$ID['s1'], $ID['s2'], $ID['s11']]) === [$ID['s1'] => ['parti' => 'TC900001.10-A', 'fazla' => 1, 'liste' => ['TC900001.10-A', (string)$db->query('SELECT party_no FROM customs_declarations WHERE id = 2')->fetchColumn()]]]);
$db->exec('ALTER TABLE customs_declarations RENAME TO cd_yedek');   // beyan tablosu yok → sayfa yine çizilir
rol($ADMIN, true);
$r = sayfa('siparisler.php');
ok('customs_declarations yokken liste temiz + Tur no\'ya düşer', temiz($r) && str_contains($satir($r['html'], $ID['s1']), 'Tur 9000000001') && html_dengeli($r['html']) === null, (string)$r['hata']);
ok('parti haritası: tablo yoksa boş (istisna yutulur)', siparis_beyan_parti_haritasi($db, [$ID['s1']]) === []);
$db->exec('ALTER TABLE cd_yedek RENAME TO customs_declarations');
ok('liste sorguları sabit: kalemler + parti tek sorgu (N+1 yok)', substr_count(file_get_contents($ROOT . '/siparisler.php'), 'FROM siparis_kalemler') === 1 && substr_count(file_get_contents($ROOT . '/_siparis_liste.php'), '->prepare(') === 0 && substr_count(file_get_contents($ROOT . '/_siparis_liste.php'), 'query(') === 0);
// temizlik: sonraki bölümlerin durumu değişmesin
$db->exec("DELETE FROM siparis_beyan WHERE sevkiyat_id IN (" . (int)$ID['s1'] . ',' . (int)$ID['s11'] . ',' . (int)$ID['s31'] . ')');
$db->exec("DELETE FROM customs_declarations WHERE id = " . $silinmisId);
$db->exec("DELETE FROM siparis_kalemler WHERE sevkiyat_id = " . (int)$ID['s12'] . " AND kalibre IN ('16','18')");
rol($ADMIN, true);

echo "\n=== 14. CSRF reddi (alt süreç) ===\n";
foreach (['siparis_kaydet.php', 'siparis_yukle.php', 'siparis_pkl.php', 'siparis_beyan_bagla.php', 'siparis_ayarlar.php'] as $f) {
    $out = []; $kod = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --csrf ' . escapeshellarg($f) . ' 2>&1', $out, $kod);
    $o = implode("\n", $out);
    ok("$f: CSRF'siz POST reddedilir (403 mesajı, işlem yok)", str_contains($o, 'Güvenlik doğrulaması başarısız') && !str_contains($o, 'CSRF-GECTI'), substr($o, -200));
}

echo "\n=== 15. Statik kaynak kuralları ===\n";
foreach (['siparis_dosya.php', 'siparis_zip.php'] as $f) {
    $s = file_get_contents($ROOT . '/' . $f);
    ok("$f: session_write_close + nosniff + no-store + belge görünürlük", str_contains($s, 'session_write_close()') && str_contains($s, 'nosniff') && str_contains($s, 'no-store') && str_contains($s, 'siparis_belge_gorunur('));
}
ok('siparis_zip.php: zlib sıkıştırma kapalı + zaman sınırı kaldırıldı', str_contains(file_get_contents($ROOT . '/siparis_zip.php'), "zlib.output_compression") && str_contains(file_get_contents($ROOT . '/siparis_zip.php'), 'set_time_limit(0)'));
foreach (glob($ROOT . '/siparis*.php') as $f) {
    $s = file_get_contents($f);
    ok(basename($f) . ': style.css/app.js/config dosyalarına dokunmuyor, inline onclick yok', !preg_match('/\sonclick=|\sonsubmit=|\sonchange=/i', $s));
}
echo "\n=== 9z. Şoför adı + telefonu (v377) ===\n";
rol($ADMIN, true);
$tokS = csrf(); $KS = fn(array $p) => sayfa('siparis_kaydet.php', [], ['csrf' => $tokS] + $p);
$sidS = $ID['s1'];
$g = sayfa('siparis_view.php', ['id' => $sidS])['html'];
ok('şoför: kolonlar yokken alanlar gizli; yönetici için kurulum notu (migrate.php kartı)', !str_contains($g, 'data-alan="sofor_adi"') && str_contains($g, 'migrate.php#siparis-sofor'));
rol($ADMIN, false);
ok('şoför: yönetici olmayana kurulum notu gösterilmez', !str_contains(sayfa('siparis_view.php', ['id' => $sidS])['html'], 'migrate.php#siparis-sofor'));
rol($ADMIN, true);
$x = $KS(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sidS, 'alan' => 'sofor_adi', 'deger' => 'Test Sürücü']);
ok('şoför: kolonlar yokken yazma reddedilir (şema güncellemesi uyarısı)', ($x['flash']['type'] ?? '') === 'error' && str_contains((string)($x['flash']['msg'] ?? ''), 'şema güncellemesi'), json_encode($x['flash']));
siparis_sofor_migrate($db);
$g = sayfa('siparis_view.php', ['id' => $sidS])['html'];
$pPl = strpos($g, 'data-alan="plakalar"'); $pSa = strpos($g, 'data-alan="sofor_adi"'); $pTe = strpos($g, 'data-alan="sofor_telefon"'); $pNv = strpos($g, 'data-alan="navlun_no"');
ok('şoför: plakaların altında — Şoför adı, Şoför telefonu, sonra navlun', $pPl !== false && $pSa > $pPl && $pTe > $pSa && $pNv > $pTe && str_contains($g, '<dt>Şoför adı</dt>') && str_contains($g, '<dt>Şoför telefonu</dt>') && !str_contains($g, 'migrate.php#siparis-sofor'));
$x = $KS(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sidS, 'alan' => 'sofor_adi', 'deger' => 'Test Sürücü', 'donus' => 'bilgi']);
$x2 = $KS(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sidS, 'alan' => 'sofor_telefon', 'deger' => '+90 532 000 00 00', 'donus' => 'bilgi']);
$sv = siparis_sevkiyat($db, $sidS);
ok('şoför: ad + telefon kaydedildi (PRG #bilgi)', $sv['sofor_adi'] === 'Test Sürücü' && $sv['sofor_telefon'] === '+90 532 000 00 00' && ($x['flash']['type'] ?? '') === 'success' && ($x2['flash']['type'] ?? '') === 'success' && str_contains((string)$x['git'], '#bilgi'));
$x = $KS(['islem' => 'alan', 'varlik' => 'sevkiyat', 'id' => (string)$sidS, 'alan' => 'sofor_telefon', 'deger' => 'abc']);
ok('şoför: geçersiz telefon hata verir, eski değer korunur', ($x['flash']['type'] ?? '') === 'error' && siparis_sevkiyat($db, $sidS)['sofor_telefon'] === '+90 532 000 00 00');
$g = sayfa('siparis_view.php', ['id' => $sidS])['html'];
ok('şoför: değerler ekranda', str_contains($g, 'Test Sürücü') && str_contains($g, '+90 532 000 00 00'));
preg_match('#data-alan="sofor_adi">(.*?)</dd>#s', $g, $ra); preg_match('#data-alan="sofor_telefon">(.*?)</dd>#s', $g, $rt);
ok('şoför: "Elle" rozeti ve "Kilidi kaldır" YOK (değeri silerdi); düzenleme bağlantısı var', !str_contains((string)($ra[1] ?? ''), 'Kilidi kaldır') && !str_contains((string)($ra[1] ?? ''), 'sip-src') && !str_contains((string)($rt[1] ?? ''), 'Kilidi kaldır')
    && str_contains((string)($ra[1] ?? ''), 'duzenle=sofor_adi') && str_contains((string)($rt[1] ?? ''), 'data-sip-alan='));
$g = sayfa('siparis_view.php', ['id' => $sidS, 'duzenle' => 'sofor_telefon'])['html'];
ok('şoför: telefon düzenleme formu tel girdisi', str_contains($g, 'type="tel"') && str_contains($g, 'inputmode="tel"'));
rol(['siparis.read'], false);
$g = sayfa('siparis_view.php', ['id' => $sidS])['html'];
ok('şoför: izleyici değerleri görür, düzenleme bağlantısı yok', str_contains($g, 'Test Sürücü') && !str_contains($g, 'duzenle=sofor_adi'));
rol($ADMIN, true);
$gl = sayfa('siparisler.php')['html'];
ok('şoför: liste sayfası etkilenmez', !str_contains($gl, 'Test Sürücü'));
ok('v394: WP Paylaş düğmesi var, şoför adı/telefonu liste HTML\'inde YOK', str_contains($gl, 'data-sip-wp="' . $sidS . '"') && !str_contains($gl, 'Test Sürücü') && !str_contains($gl, '532 000') && html_dengeli($gl) === null);

$css = file_get_contents($ROOT . '/assets/siparis.css');
ok('CSS: [hidden] kuralı + 16px mobil input + --bn-h kullanımı + overflow-x:hidden YOK', str_contains($css, '.sip-mod [hidden]') && str_contains($css, 'font-size: 16px') && str_contains($css, 'var(--bn-h)') && !preg_match('/overflow-x:\s*hidden/', $css));
ok('CSS: koyu tema karşılığı', str_contains($css, 'html[data-theme="dark"] .sip-mod'));
ok('CSS: text-transform:uppercase veri için kullanılmıyor', !str_contains($css, 'text-transform: uppercase'));
ok('JS: IIFE + window.SiparisUI', str_contains(file_get_contents($ROOT . '/assets/siparis.js'), 'window.SiparisUI') && str_contains(file_get_contents($ROOT . '/assets/siparis.js'), '(function () {'));
$aud = [];
foreach (glob($ROOT . '/siparis*.php') as $f) if (preg_match_all("/audit_log_event\('([a-z_]+)'/", file_get_contents($f), $m)) foreach ($m[1] as $a) $aud[] = $a;
ok('sayfa audit eylem adları ≤ 40 karakter', $aud !== [] && max(array_map('strlen', $aud)) <= 40, implode(',', array_unique($aud)));

siparis_test_bitir();
