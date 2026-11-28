<?php
// =========================================================
// scripts/hizli_kart_smoke.php — Ortak "Hızlı Cari / Stok Ekle" (v611 Faz 2): api_hizli_kart.php ucu + config/hizli_kart*.php + sayfa düğmeleri
//   php scripts/hizli_kart_smoke.php     → çıkış kodu 0 = hepsi geçti
// Her istek ALT SÜREÇTE (scripts/_stok_istek.php) gerçek exit/header/http_response_code ile çalışır; DB geçici dosya SQLite (canlıya HİÇ dokunmaz).
// Kimlikler algoritmayla ÜRETİLMİŞ sentetik değerlerdir (gerçek kişi/firma değil); telefon örneği uydurmadır.
// Kapsam: statik (uçta SQL yazma yok, CSRF/login/kapı sırası, sw.js) · şema yok → sema_yok · yöntem 405 · oturum 401 · CSRF 403 JSON ·
//         geçersiz tür · izinsiz 403 · cari müstahsil hızlı → hizli=1 · tekillik (VKN/TCKN) · audit'te kişisel veri yok · istek_id tekrarı ·
//         stok: ambalajlı kartta dara zorunlu, "1,8" kabul, HKS id/ad katalogda doğrulanır · seçenekler kişisel veri taşımaz · sayfa düğmeleri ·
//         eski şema (ek kolon yok) → düğme gizli + sema_yok.
// =========================================================
require_once __DIR__ . '/_stok_test_lib.php';

$db = db();
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
const HK_JSON = ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ACCEPT' => 'application/json'];
function api(array $post, array $ek = []): array
{
    $r = stok_t_istek(['dosya' => 'api_hizli_kart.php', 'post' => $post, 'server' => HK_JSON + ['REQUEST_METHOD' => 'POST']] + $ek);
    $r['j'] = json_decode(trim($r['govde']), true);
    return $r;
}
function ekle(array $post, array $ek = []): array { return api($post + ['csrf' => 'test-csrf-belirteci'], $ek); }
function secenek(array $get = [], array $ek = []): array
{
    $r = stok_t_istek(['dosya' => 'api_hizli_kart.php', 'get' => ['islem' => 'secenekler'] + $get, 'server' => HK_JSON + ['REQUEST_METHOD' => 'GET']] + $ek);
    $r['j'] = json_decode(trim($r['govde']), true);
    return $r;
}
function sayfa(string $dosya, array $ek = []): array { return stok_t_istek(['dosya' => $dosya] + $ek); }
function temiz(array $r): bool { return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error):/', $r['govde']) && $r['hata'] === null; }
function bas(array $r, string $ad): ?string { foreach ($r['basliklar'] as $b) if (stripos($b, $ad . ':') === 0) return trim(substr($b, strlen($ad) + 1)); return null; }
function kisa(array $r): string { return 'kod=' . $r['kod'] . ' ' . mb_substr((string)$r['govde'], 0, 400) . ' ' . ($r['hata'] ?? ''); }
function t_tckn(string $d9): string
{
    $d = array_map('intval', str_split($d9));
    $d10 = ((($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7 - ($d[1] + $d[3] + $d[5] + $d[7])) % 10 + 10) % 10;
    return $d9 . $d10 . ((array_sum($d) + $d10) % 10);
}
function t_vkn(string $d9): string
{
    $d = array_map('intval', str_split($d9)); $top = 0;
    for ($i = 0; $i < 9; $i++) { $t = ($d[$i] + 9 - $i) % 10; $p = ($t * (2 ** (9 - $i))) % 9; if ($t !== 0 && $p === 0) $p = 9; $top += $p; }
    return $d9 . ((10 - $top % 10) % 10);
}
$kod = static fn(string $f): string => (string)file_get_contents($ROOT . '/' . $f);

echo "=== 0. Statik denetim ===\n";
$uc = $kod('api_hizli_kart.php'); $cek = $kod('config/hizli_kart.php'); $ui = $kod('config/hizli_kart_ui.php');
$js = $kod('assets/hizli_kart.js'); $jsKod = (string)preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], $js); $css = $kod('assets/hizli_kart.css'); $sw = $kod('sw.js');
$SQL_YAZ = '/\b(INSERT\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM|REPLACE\s+INTO|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE)\b/i';
ok('uç: <?php + strict_types; SQL yazma YOK (INSERT INTO geçmez); ->prepare/->query/->exec YOK', str_starts_with($uc, '<?php') && str_contains($uc, 'declare(strict_types=1)') && !preg_match($SQL_YAZ, $uc) && !preg_match('/->(prepare|query|exec)\(/', $uc));
ok('çekirdek + ui: SQL yazma / doğrudan sorgu YOK (yazma yalnız cari_kart_hizli_ekle / stok_kart_hizli_ekle)', !preg_match($SQL_YAZ, $cek . $ui) && !preg_match('/->(prepare|query|exec)\(/', $cek . $ui)
    && str_contains($cek, 'cari_kart_hizli_ekle($pdo') && str_contains($cek, 'stok_kart_hizli_ekle($pdo') && !str_contains($cek, 'cari_kart_kaydet(') && !str_contains($cek, 'stok_kart_kaydet('));
$p = static fn(string $a): int => ($x = strpos($uc, $a)) === false ? -1 : $x;
ok('uç sırası: 405 → require_login → csrf_check → tür → izin (hizli_kart_izin create) → şema → hizli_kart_ekle', $p("'kod' => 'yontem'], 405") > 0 && $p("'kod' => 'yontem'], 405") < $p('require_login()')
    && $p('require_login()') < $p('csrf_check(') && $p('csrf_check(') < $p("'gecersiz_tur'], 400);\nif") && $p("hizli_kart_izin(\$tur, 'create')") > $p('csrf_check(')
    && $p("hizli_kart_izin(\$tur, 'create')") < $p('hizli_kart_sema_hazir($pdo, $tur)') && $p('hizli_kart_sema_hazir($pdo, $tur)') < $p('hizli_kart_ekle($pdo'));
ok('uç: JSON başlığı + no-store csrf_check\'ten ÖNCE', $p('application/json') < $p('csrf_check(') && $p("Cache-Control: no-store") > 0 && $p("Cache-Control: no-store") < $p('csrf_check('));
ok('izin kapısı modüllerin mevcut kapısı (cari_can / stok_can); yeni yetki anahtarı YOK', str_contains($cek, "cari_can(\$eylem) : stok_can(\$eylem)") && !preg_match("/can\\('muhasebe\\./", $cek . $uc));
ok('JS: innerHTML / insertAdjacentHTML / outerHTML / document.write YOK (kullanıcı verisi textContent)', !preg_match('/\b(innerHTML|outerHTML|insertAdjacentHTML|document\.write)\b/', $jsKod));
ok('JS API: window.HizliKart = {ac, kapat, kullanilabilir, secenekleriYenile}; istek_id + düğme kilidi', str_contains($js, 'window.HizliKart = { ac: ac') && str_contains($js, 'istek_id: istekId') && str_contains($js, 'if (!acikTur || kilit) return;'));
preg_match_all('/(?:class|id)="([^"]+)"|data-hk-[a-z-]+/', $ui, $m1);
preg_match_all('/\.(hk-[a-z0-9-]+)/', $css . $js, $m2);
$adlar = array_unique(array_merge(preg_split('/\s+/', implode(' ', $m1[1])) ?: [], $m1[0], $m2[1]));
$yasak = array_values(array_filter($adlar, static fn(string $a): bool => (bool)preg_match('/(^|[-_ ])(ad|ads)([-_ ]|$)|advert|sponsor|banner/i', $a)));
ok('sınıf/kimlik/data-* adlarında ad/ads/advert/sponsor/banner parçası YOK', $yasak === [], json_encode($yasak));
ok('CSS: koyu tema belirteçleri + mobil 16px + var(--surface) YOK', str_contains($css, 'html[data-theme="dark"] .hk-overlay') && str_contains($css, 'font-size: 16px') && !str_contains($css, '--surface'));
ok('sw.js: api_hizli_kart.php açık bypass kuralı; SHELL\'e hizli_kart.* girmez', str_contains($sw, "/\\/api_hizli_kart\\.php(\\/|$)/.test(u.pathname)) return;") && !preg_match('#SHELL[^;]*hizli_kart\.#s', $sw));
ok('sayfalar: Cari Kartları stok_core YÜKLEMEZ; iki sayfa da GET-only kalır', !str_contains($kod('cari_kartlari.php'), 'stok_core') && !str_contains($kod('cari_kartlari.php') . $kod('stok_kartlari.php'), '$_POST'));

echo "\n=== 1. Şema yok (tablolar kurulmadı) ===\n";
$r = ekle(['tur' => 'cari', 'unvan' => 'Şemasız Örnek', 'tip' => 'musteri']);
ok('cari: 503 + kod sema_yok + anlaşılır mesaj', $r['kod'] === 503 && ($r['j']['kod'] ?? '') === 'sema_yok' && str_contains((string)($r['j']['hata'] ?? ''), 'Şema Migrasyon') && temiz($r), kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Şemasız Ürün', 'tip' => 'urun', 'ana_birim' => 'kg']);
ok('stok: 503 + kod sema_yok', $r['kod'] === 503 && ($r['j']['kod'] ?? '') === 'sema_yok' && temiz($r), kisa($r));
$r = secenek();
ok('seçenekler: ok, iki tür hazir=false, listeler boş', $r['kod'] === 200 && ($r['j']['ok'] ?? false) === true && ($r['j']['cari']['hazir'] ?? true) === false && ($r['j']['stok']['hazir'] ?? true) === false && ($r['j']['cari']['tipler'] ?? [1]) === [] && temiz($r), kisa($r));

stok_t_sema_kur($db);
$O = stok_t_ornek_veri($db);   // stok + cari şeması (yeni kurulum DDL'i v611 kolonlarıyla) + uydurma örnekler + HKS kataloğu
$amb = []; foreach (stok_tanim_listesi($db, 'ambalaj_tip') as $t) $amb[(string)$t['kod']] = (int)$t['id'];
ok('kurulum: cari_ek_hazir + stok_ek_hazir, ambalaj tohumu (kasa, palet)', cari_ek_hazir($db) && stok_ek_hazir($db) && isset($amb['kasa'], $amb['palet']));

echo "\n=== 2. Yöntem · oturum · CSRF · tür · izin ===\n";
$r = stok_t_istek(['dosya' => 'api_hizli_kart.php', 'get' => [], 'server' => ['REQUEST_METHOD' => 'GET']]);
$j = json_decode(trim($r['govde']), true);
ok('GET (islem yok) → 405 JSON + Allow; no-store', $r['kod'] === 405 && ($j['kod'] ?? '') === 'yontem' && str_contains((string)bas($r, 'Allow'), 'POST') && str_contains((string)bas($r, 'Cache-Control'), 'no-store'), kisa($r));
$r = stok_t_istek(['dosya' => 'api_hizli_kart.php', 'server' => ['REQUEST_METHOD' => 'PUT']]);
ok('PUT → 405', $r['kod'] === 405, kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => 'Oturumsuz', 'tip' => 'musteri'], ['uid' => 0]);
ok('oturum yok → 401 JSON kod oturum (yazma yok)', $r['kod'] === 401 && ($r['j']['kod'] ?? '') === 'oturum', kisa($r));
$n0 = say('cari_kartlar');
$r = api(['tur' => 'cari', 'unvan' => 'CSRF Yok', 'tip' => 'musteri']);
ok('CSRF yok → 403 JSON (csrf_check), kart açılmaz', $r['kod'] === 403 && ($r['j']['ok'] ?? null) === false && say('cari_kartlar') === $n0, kisa($r));
$r = api(['tur' => 'cari', 'unvan' => 'CSRF Yanlış', 'tip' => 'musteri', 'csrf' => 'yanlis']);
ok('CSRF yanlış → 403 JSON', $r['kod'] === 403 && ($r['j']['ok'] ?? null) === false, kisa($r));
$r = api(['tur' => 'cari', 'unvan' => 'CSRF Dizi', 'tip' => 'musteri', 'csrf' => ['x']]);
ok('CSRF dizi (tip karmaşası) → 403 JSON, PHP hatası yok', $r['kod'] === 403 && temiz($r), kisa($r));
$r = ekle(['tur' => 'kasa', 'unvan' => 'X']);
ok('geçersiz tür → 400 gecersiz_tur', $r['kod'] === 400 && ($r['j']['kod'] ?? '') === 'gecersiz_tur', kisa($r));
$r = ekle(['tur' => ['cari'], 'unvan' => 'X']);
ok('tür dizi → 400 gecersiz_tur', $r['kod'] === 400 && ($r['j']['kod'] ?? '') === 'gecersiz_tur' && temiz($r), kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => 'İzinsiz Örnek', 'tip' => 'musteri'], ['admin' => false, 'perms' => []]);
ok('izinsiz (yönetici değil, yetki yok) → 403 yetki, kart açılmaz', $r['kod'] === 403 && ($r['j']['kod'] ?? '') === 'yetki' && say('cari_kartlar') === $n0, kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => 'Yalnız Okur', 'tip' => 'musteri'], ['admin' => false, 'perms' => ['muhasebe.cari_kartlari.read']]);
ok('yalnız okuma izni (muhasebe.cari_kartlari.read) → 403 (oluşturma = muhasebe.cari.create)', $r['kod'] === 403 && ($r['j']['kod'] ?? '') === 'yetki', kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Çapraz', 'tip' => 'urun', 'ana_birim' => 'kg'], ['admin' => false, 'perms' => ['muhasebe.cari_kartlari.read', 'muhasebe.cari.create']]);
ok('cari oluşturma izni stok için geçmez → 403', $r['kod'] === 403 && ($r['j']['kod'] ?? '') === 'yetki', kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => 'Rol İzinli Müşteri Örnek', 'tip' => 'musteri'], ['admin' => false, 'perms' => ['muhasebe.cari_kartlari.read', 'muhasebe.cari.create']]);
ok('rol izni (cari_kartlari.read + cari.create; yönetici değil) → 200', $r['kod'] === 200 && ($r['j']['ok'] ?? false) === true, kisa($r));

echo "\n=== 3. Cari: müstahsil hızlı ekle · tekillik · audit ===\n";
$TC = t_tckn('2' . substr((string)(100000000 + getmypid() % 89999999), 1, 8)); $VK = t_vkn('3' . substr((string)(100000000 + getmypid() % 89999999), 1, 8)); $TEL = '0 555 000 11 22';
$r = ekle(['tur' => 'cari', 'unvan' => 'Hızlı Müstahsil Deneme', 'tip' => 'mustahsil', 'tckn' => $TC, 'telefon' => $TEL]);
$j = $r['j'] ?? [];
$c1 = (int)($j['id'] ?? 0);
ok('müstahsil: 200 {ok, tur=cari, id, kod C…, ad, hizli:1, eksikler[]}', $r['kod'] === 200 && ($j['ok'] ?? false) === true && ($j['tur'] ?? '') === 'cari' && $c1 > 0 && preg_match('/^C\d{5}$/', (string)($j['kod'] ?? '')) === 1
    && ($j['ad'] ?? '') === 'Hızlı Müstahsil Deneme' && ($j['hizli'] ?? 0) === 1 && is_array($j['eksikler'] ?? null) && temiz($r), kisa($r));
$k = cari_kart_oku($db, $c1);
ok('DB: hizli=1, tip=mustahsil, tckn kayıtlı (çekirdek fonksiyonla)', (int)($k['hizli'] ?? 0) === 1 && ($k['tip'] ?? '') === 'mustahsil' && ($k['tckn'] ?? '') === $TC);
ok('yanıt TCKN / telefon DEĞERİ taşımaz', !str_contains($r['govde'], $TC) && !str_contains($r['govde'], '555 000 11 22'));
$aud = implode("\n", array_map(static fn(array $a): string => (string)$a['old_values'] . ' ' . (string)$a['new_values'], $db->query("SELECT old_values, new_values FROM audit_log WHERE module = 'cari'")->fetchAll()));
ok('audit (cari create) var; TCKN / telefon DEĞERİ audit\'te YOK', $aud !== '' && str_contains($aud, 'Hızlı Müstahsil Deneme') && !str_contains($aud, $TC) && !str_contains($aud, '555 000 11 22'));
$r = ekle(['tur' => 'cari', 'unvan' => 'Aynı TCKN Deneme', 'tip' => 'mustahsil', 'tckn' => $TC]);
ok('aynı TCKN → 422, alan hatası tckn (Faz 1 mesajı, maskeli), kart açılmaz', $r['kod'] === 422 && ($r['j']['ok'] ?? true) === false && str_contains((string)($r['j']['alanlar']['tckn'] ?? ''), 'başka bir cari kartta kayıtlı')
    && str_contains((string)($r['j']['alanlar']['tckn'] ?? ''), cari_tckn_maske($TC)) && !str_contains($r['govde'], $TC) && say('cari_kartlar', 'unvan = ?', ['Aynı TCKN Deneme']) === 0, kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => 'VKN Bir Deneme Ltd.', 'tip' => 'tedarikci', 'vergi_no' => $VK]);
ok('VKN ile tedarikçi → 200', $r['kod'] === 200 && ($r['j']['ok'] ?? false) === true, kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => 'VKN İki Deneme Ltd.', 'tip' => 'musteri', 'vergi_no' => $VK]);
ok('aynı VKN → 422 alanlar.vergi_no (tekillik)', $r['kod'] === 422 && str_contains((string)($r['j']['alanlar']['vergi_no'] ?? ''), 'başka bir cari kartta kayıtlı'), kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => 'Geçersiz VKN', 'tip' => 'musteri', 'vergi_no' => substr($VK, 0, 9) . (((int)substr($VK, -1) + 1) % 10)]);
ok('geçersiz VKN → 422 alanlar.vergi_no', $r['kod'] === 422 && isset($r['j']['alanlar']['vergi_no']), kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => '', 'tip' => '']);
ok('unvan + tip boş → 422, iki alan hatası (kod dogrulama)', $r['kod'] === 422 && ($r['j']['kod'] ?? '') === 'dogrulama' && isset($r['j']['alanlar']['unvan'], $r['j']['alanlar']['tip']), kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => 'Uydurma Tip', 'tip' => 'yok_boyle']);
ok('tanımsız tip → 422 alanlar.tip', $r['kod'] === 422 && isset($r['j']['alanlar']['tip']), kisa($r));
$r = ekle(['tur' => 'cari', 'unvan' => ['dizi'], 'tip' => 'musteri']);
ok('dizi unvan → 422 (PHP hatası yok)', $r['kod'] === 422 && temiz($r), kisa($r));
// istek_id: aynı anahtar ikinci kez → saklı yanıt, ikinci kart YOK
$ik = 'hkTest' . bin2hex(random_bytes(6));
$n1 = say('cari_kartlar');
$r1 = ekle(['tur' => 'cari', 'unvan' => 'Çift Tık Örnek', 'tip' => 'musteri', 'istek_id' => $ik], ['sid' => 'hkSmoke' . getmypid()]);
$r2 = ekle(['tur' => 'cari', 'unvan' => 'Çift Tık Örnek', 'tip' => 'musteri', 'istek_id' => $ik], ['sid' => 'hkSmoke' . getmypid()]);
ok('istek_id tekrarı: ikinci yanıt aynı id + tekrar:true, DB\'de tek kart', ($r1['j']['ok'] ?? false) && ($r2['j']['ok'] ?? false) && ($r1['j']['id'] ?? 0) === ($r2['j']['id'] ?? -1) && ($r2['j']['tekrar'] ?? false) === true && say('cari_kartlar') === $n1 + 1, kisa($r2));
$r = ekle(['tur' => 'cari', 'unvan' => 'Kötü Anahtar', 'tip' => 'musteri', 'istek_id' => 'x<y>']);
ok('geçersiz istek_id → 400 gecersiz_istek', $r['kod'] === 400 && ($r['j']['kod'] ?? '') === 'gecersiz_istek', kisa($r));

echo "\n=== 4. Stok: dara zorunluluğu · 1,8 · HKS katalog ===\n";
$ns = say('stok_kartlar');
$r = ekle(['tur' => 'stok', 'ad' => 'Hızlı Kasa Deneme', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'ambalaj_tip_id' => (string)$amb['kasa']]);
ok('ambalaj tipi seçili + dara yok → 422 alanlar.birim_dara_kg (zorunlu), kart açılmaz', $r['kod'] === 422 && str_contains((string)($r['j']['alanlar']['birim_dara_kg'] ?? ''), 'zorunlu') && say('stok_kartlar') === $ns, kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Hızlı Kasa Deneme', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'ambalaj_tip_id' => (string)$amb['kasa'], 'birim_dara_kg' => '0']);
ok('ambalaj + dara 0 → 422', $r['kod'] === 422 && isset($r['j']['alanlar']['birim_dara_kg']), kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Hızlı Kasa Deneme', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'birim_dara_kg' => '1.234,5']);
ok('dara "1.234,5" (aralık dışı) → 422 stok_dara_coz kuralı', $r['kod'] === 422 && str_contains((string)($r['j']['alanlar']['birim_dara_kg'] ?? ''), '1.000 kg'), kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Hızlı Kasa Deneme', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'ambalaj_tip_id' => (string)$amb['kasa'], 'birim_dara_kg' => '1,8']);
$j = $r['j'] ?? []; $s1 = (int)($j['id'] ?? 0);
ok('ambalaj + dara "1,8" → 200 {tur=stok, kod STK-…, birim_dara_kg 1,8, hizli:1}', $r['kod'] === 200 && ($j['tur'] ?? '') === 'stok' && preg_match('/^STK-\d{4}$/', (string)($j['kod'] ?? '')) === 1 && ($j['birim_dara_kg'] ?? '') === '1,8' && ($j['hizli'] ?? 0) === 1 && temiz($r), kisa($r));
$k = stok_kart_oku($db, $s1);
ok('DB: hizli=1, ambalaj kasa, birim_dara_kg = 1,800 kg (gram 1800)', (int)($k['hizli'] ?? 0) === 1 && (int)($k['ambalaj_tip_id'] ?? 0) === $amb['kasa'] && stok_milli($k['birim_dara_kg'] ?? null) === 1800);
ok('eksikler: grup boş → "Stok grubu" (etiket) + eksik_alanlar grup_id', in_array('Stok grubu', (array)($j['eksikler'] ?? []), true) && in_array('grup_id', (array)($j['eksik_alanlar'] ?? []), true), json_encode($j, JSON_UNESCAPED_UNICODE));
$r = ekle(['tur' => 'stok', 'ad' => 'Hızlı Domates Deneme', 'tip' => 'urun', 'ana_birim' => 'kg', 'hks_urun_id' => '101', 'grup_id' => (string)$O['grup']['Taze Ürün']]);
$j = $r['j'] ?? [];
ok('HKS id 101 (katalogda) → 200, ad katalogdan "Domates", eksik yok', $r['kod'] === 200 && ($j['hks_urun_id'] ?? '') === '101' && ($j['hks_urun_ad'] ?? '') === 'Domates' && ($j['eksik_alanlar'] ?? [1]) === [], kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Hızlı Uydurma Ürün', 'tip' => 'urun', 'ana_birim' => 'kg', 'hks_urun_id' => '999']);
ok('HKS id 999 (katalogda YOK) → 422 alanlar.hks_urun', $r['kod'] === 422 && isset($r['j']['alanlar']['hks_urun']), kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Hızlı Kayısı Deneme', 'tip' => 'urun', 'ana_birim' => 'kg', 'hks_urun_ad' => 'kayısı']);
ok('HKS adla (JS seçimi yok) → katalogdan çözülür (#105)', $r['kod'] === 200 && ($r['j']['hks_urun_id'] ?? '') === '105' && ($r['j']['hks_urun_ad'] ?? '') === 'Kayısı', kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Hızlı Malzeme HKS', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'hks_urun_id' => '101']);
ok('tip=malzeme iken HKS yok sayılır (null)', $r['kod'] === 200 && array_key_exists('hks_urun_id', (array)$r['j']) && $r['j']['hks_urun_id'] === null, kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => '', 'tip' => '', 'ana_birim' => '']);
ok('ad/tip/ana birim boş → 422, üç alan hatası', $r['kod'] === 422 && isset($r['j']['alanlar']['ad'], $r['j']['alanlar']['tip'], $r['j']['alanlar']['ana_birim']), kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Ton Birim', 'tip' => 'urun', 'ana_birim' => 'ton']);
ok('tanımsız birim → 422 alanlar.ana_birim', $r['kod'] === 422 && isset($r['j']['alanlar']['ana_birim']), kisa($r));
$r = ekle(['tur' => 'stok', 'ad' => 'Pasif Ambalaj', 'tip' => 'malzeme', 'ana_birim' => 'adet', 'ambalaj_tip_id' => '99999', 'birim_dara_kg' => '2']);
ok('tanımsız ambalaj tipi → 422 alanlar.ambalaj_tip_id', $r['kod'] === 422 && isset($r['j']['alanlar']['ambalaj_tip_id']), kisa($r));
$sa = $db->query("SELECT new_values FROM audit_log WHERE module = 'stok' AND action = 'create' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('audit (stok create) hizli:1 bayrağıyla', is_string($sa) && str_contains($sa, '"hizli":1'));

echo "\n=== 5. Seçenekler (kişisel veri YOK) ===\n";
$r = secenek();
$j = $r['j'] ?? [];
ok('GET ?islem=secenekler → 200, iki tür hazır + izinli', $r['kod'] === 200 && ($j['ok'] ?? false) === true && ($j['cari']['hazir'] ?? false) && ($j['stok']['hazir'] ?? false) && ($j['cari']['izin'] ?? false) && ($j['stok']['izin'] ?? false) && temiz($r), kisa($r));
ok('cari.tipler yalnız aktif + mustahsil var; anahtarlar kod/ad', in_array('mustahsil', array_column((array)($j['cari']['tipler'] ?? []), 'kod'), true) && !array_filter((array)($j['cari']['tipler'] ?? []), static fn($t) => array_keys($t) !== ['kod', 'ad']));
ok('stok: kart tipleri 3, birimler (kg dahil), ambalaj kasa/palet (id/kod/ad), gruplar (id/ad)', count($j['stok']['kart_tipleri'] ?? []) === 3 && in_array('kg', array_column((array)($j['stok']['birimler'] ?? []), 'kod'), true)
    && array_column((array)($j['stok']['ambalaj_tipleri'] ?? []), 'kod') === ['kasa', 'palet'] && !array_filter((array)($j['stok']['gruplar'] ?? []), static fn($t) => array_keys($t) !== ['id', 'ad']));
ok('stok.hks: var + yalnız {id, ad}', ($j['stok']['hks']['var'] ?? false) === true && count($j['stok']['hks']['urunler'] ?? []) === 5 && !array_filter((array)$j['stok']['hks']['urunler'], static fn($u) => array_keys($u) !== ['id', 'ad']));
ok('seçenek yanıtı kişisel veri / kart verisi taşımaz (TCKN, VKN, telefon, unvan yok)', !str_contains($r['govde'], $TC) && !str_contains($r['govde'], $VK) && !str_contains($r['govde'], '555') && !str_contains($r['govde'], 'Hızlı Müstahsil Deneme'));
$r = secenek(['tur' => 'stok']);
ok('?tur=stok → yalnız stok bölümü', ($r['j']['ok'] ?? false) && isset($r['j']['stok']) && !isset($r['j']['cari']), kisa($r));
$r = secenek(['tur' => 'kasa']);
ok('?tur=kasa → 400 gecersiz_tur', $r['kod'] === 400, kisa($r));
$r = secenek([], ['admin' => false, 'perms' => []]);
ok('okuma izni yok → 403', $r['kod'] === 403 && ($r['j']['kod'] ?? '') === 'yetki', kisa($r));
$r = secenek([], ['admin' => false, 'perms' => ['muhasebe.cari_kartlari.read']]);
ok('yalnız cari okuma → yalnız cari bölümü, izin=false (oluşturma yok)', ($r['j']['ok'] ?? false) && isset($r['j']['cari']) && !isset($r['j']['stok']) && ($r['j']['cari']['izin'] ?? true) === false, kisa($r));

echo "\n=== 6. Sayfalar: ⚡ Hızlı ekle düğmesi + pencere ===\n";
$r = sayfa('cari_kartlari.php');
$g = $r['govde'];
ok('Cari Kartları (yönetici): düğme data-hk-ac="cari" + data-hk-git + #hkModal + #hkSecenek + hizli_kart.js/css', temiz($r) && str_contains($g, 'data-hk-ac="cari"') && str_contains($g, 'data-hk-git="cari_kartlari.php?id={id}"') && str_contains($g, 'id="hkModal"')
    && str_contains($g, 'id="hkSecenek"') && str_contains($g, 'assets/hizli_kart.js?v=') && str_contains($g, 'assets/hizli_kart.css?v=') && !str_contains($g, 'data-hk-bolum="stok"'), kisa($r));
ok('Cari Kartları: pencere .pm-overlay > .pm-dialog > form; csrf alanı; Kaydet/İptal', (bool)preg_match('#<div class="pm-overlay hk-overlay" id="hkModal" hidden[^>]*>\s*<div class="pm-dialog hk-dialog"[^>]*>.*?<form id="hkForm"#s', $g) && str_contains($g, 'name="csrf" value="test-csrf-belirteci"') && str_contains($g, 'data-hk-kaydet'));
ok('Cari Kartları: hızlı eklenen kart listede hızlı renkte (Faz 1 sınıfı)', (bool)preg_match('/class="cari-kart cari-kart--hizli" href="cari_kartlari\.php\?id=' . $c1 . '"/', $g));
preg_match('#<script type="application/json" id="hkSecenek">(.*?)</script>#s', $g, $sm);
ok('gömülü seçenek JSON çözülür, kişisel veri yok', is_array(json_decode($sm[1] ?? '', true)) && !str_contains($sm[1] ?? '', $TC) && !str_contains($sm[1] ?? '', '555'));
$r = sayfa('cari_kartlari.php', ['admin' => false, 'perms' => ['muhasebe.cari_kartlari.read']]);
ok('Cari Kartları (yalnız okuma): düğme + pencere YOK', temiz($r) && $r['kod'] === 200 && !str_contains($r['govde'], 'data-hk-ac=') && !str_contains($r['govde'], 'id="hkModal"'), kisa($r));
$r = sayfa('stok_kartlari.php');
$g = $r['govde'];
ok('Stok Kartları (yönetici): düğme data-hk-ac="stok" + pencere (yalnız stok bölümü)', temiz($r) && str_contains($g, 'data-hk-ac="stok"') && str_contains($g, 'data-hk-git="stok_kartlari.php?id={id}"') && str_contains($g, 'data-hk-bolum="stok"') && !str_contains($g, 'data-hk-bolum="cari"'), kisa($r));
ok('Stok Kartları: hızlı eklenen kart listede hızlı renkte (Faz 1 .st-satir--hizli)', (bool)preg_match('/st-satir--hizli[^>]*href="stok_kartlari\.php\?id=' . $s1 . '"|href="stok_kartlari\.php\?id=' . $s1 . '"[^>]*st-satir--hizli|st-satir st-satir--hizli/', $g));
$r = sayfa('stok_kartlari.php', ['admin' => false, 'perms' => ['muhasebe.stok_kartlari.read']]);
ok('Stok Kartları (yalnız okuma): düğme + pencere YOK', temiz($r) && !str_contains($r['govde'], 'data-hk-ac=') && !str_contains($r['govde'], 'id="hkModal"'), kisa($r));
$r = sayfa('stok_kartlari.php', ['admin' => false, 'perms' => ['muhasebe.stok_kartlari.read', 'muhasebe.stok.create']]);
ok('Stok Kartları (rol: okuma + stok.create): düğme var', temiz($r) && str_contains($r['govde'], 'data-hk-ac="stok"'), kisa($r));

echo "\n=== 7. Eski şema (ek kolonlar yok) → düğme gizli + sema_yok, sayfa kırılmaz ===\n";
stok_t_eski_kart_semasi($db);
$r = ekle(['tur' => 'stok', 'ad' => 'Eski Şema Ürün', 'tip' => 'urun', 'ana_birim' => 'kg']);
ok('stok eski şema → 503 sema_yok (kart açılmaz)', $r['kod'] === 503 && ($r['j']['kod'] ?? '') === 'sema_yok' && say('stok_kartlar', 'ad = ?', ['Eski Şema Ürün']) === 0, kisa($r));
$r = sayfa('stok_kartlari.php');
ok('Stok Kartları eski şemada açılır, düğme/pencere YOK', temiz($r) && $r['kod'] === 200 && !str_contains($r['govde'], 'data-hk-ac=') && !str_contains($r['govde'], 'id="hkModal"'), kisa($r));
$db->exec('ALTER TABLE cari_kartlar DROP COLUMN dogum_tarihi');   // YALNIZ TEST: cari ek şeması eksik (cari_ek_hazir false)
$r = ekle(['tur' => 'cari', 'unvan' => 'Eski Şema Cari', 'tip' => 'musteri']);
ok('cari eski şema → 503 sema_yok', $r['kod'] === 503 && ($r['j']['kod'] ?? '') === 'sema_yok', kisa($r));
$r = sayfa('cari_kartlari.php');
ok('Cari Kartları eski şemada açılır, düğme/pencere YOK', temiz($r) && $r['kod'] === 200 && !str_contains($r['govde'], 'data-hk-ac=') && !str_contains($r['govde'], 'id="hkModal"'), kisa($r));

siparis_test_bitir();
