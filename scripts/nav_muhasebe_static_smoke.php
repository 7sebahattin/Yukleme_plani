<?php
// =========================================================
// scripts/nav_muhasebe_static_smoke.php — Muhasebe yer tutucu sayfaları STATİK değişmezleri
// nav_muhasebe_gruplari() tek kaynak; her sayfa kapılı, yönlendiren ya da "hazırlanıyor".
// CLI; DB'ye dokunmaz.   php scripts/nav_muhasebe_static_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function oku(string $f): string { global $ROOT; return (string)file_get_contents($ROOT . '/' . $f); }

$helpers = oku('config/helpers.php');
ok('nav_muhasebe_gruplari() kaynakta', (bool)preg_match('/function nav_muhasebe_gruplari\(\): array \{.*?\n\}\n/s', $helpers, $m));
$gruplar = [];
if (!empty($m[0])) { eval($m[0]); $gruplar = nav_muhasebe_gruplari(); }
ok('en az 5 grup', count($gruplar) >= 5);

$altKeys = [];
if (preg_match('/function nav_alt_sayfalar\(\): array \{(.*?)\n\}\n/s', $helpers, $m2)) {
    preg_match_all("/^\s*'([a-z_]+)'\s*=>\s*\['href'/m", $m2[1], $mm);
    $altKeys = $mm[1];
}
ok('nav_alt_sayfalar anahtarları okundu', count($altKeys) > 5);

$gorulen = [];
foreach ($gruplar as $slug => $g) {
    ok("slug tekil: $slug", !isset($gorulen["slug:$slug"]));
    $gorulen["slug:$slug"] = true;
    ok("slug nav_alt anahtarıyla çakışmaz: $slug", !in_array($slug, $altKeys, true));
    foreach ($g[2] as $dosya => $_) {
        $ad = basename($dosya, '.php');
        ok("$dosya: iki grupta değil", !isset($gorulen[$dosya]), $dosya);
        $gorulen[$dosya] = true;
        ok("$dosya: nav_alt anahtarıyla çakışmaz", !in_array($ad, $altKeys, true));
        $yol = $ROOT . '/' . $dosya;
        if (!is_file($yol)) { ok("$dosya: kökte var", false); continue; }
        $s = (string)file_get_contents($yol);
        ok("$dosya: <?php ile başlar", str_starts_with($s, '<?php'));
        ok("$dosya: require_login()", str_contains($s, 'require_login()'));
        // v550: admin_konsolu yalnız is_admin(); diğer tüm menü sayfaları kendi muhasebe_sayfa_can('<slug>') kapısıyla (varsayılan kapalı, yönetici her zaman).
        if ($slug === 'admin_konsolu') ok("$dosya: is_admin() kapısı", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s));
        else ok("$dosya: muhasebe_sayfa_can('$ad') kapısı (bare is_admin gate yok)", str_contains($s, "if (!muhasebe_sayfa_can('$ad')) { forbidden(); }") && !preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s));
        $__kendiPostu = in_array($dosya, ['sifre_degistirme.php', 'ajanda.php', 'kullanici_tercihleri.php'], true);   // v574: PRG header('Location') başka sayfaya YÖNLENDİRME değildir
        if (!$__kendiPostu && preg_match("/header\('Location: ([^']+)'/", $s, $lm)) {
            $hedef = explode('?', $lm[1])[0];
            ok("$dosya: yönlendirme hedefi var ($hedef)", is_file($ROOT . '/' . $hedef));
            ok("$dosya: yönlendirme exit'li", (bool)preg_match('/header\([^;]+\);\s*exit;/', $s));
            ok("$dosya: yer tutucu kartı yok", !str_contains($s, 'render_header'));
        } elseif (str_contains($s, 'config/fatura_core.php')) {
            // Çalışan modül (Fatura, v526 Dalga B: satış/alış faturaları, sevk irsaliyeleri, alınan sipariş fişleri, tanımlar): şema kapısı + sayfa kabuğu.
            ok("$dosya: çalışan modül — fatura_sayfa_kapisi( (tablo yoksa uyarı kartı)", str_contains($s, 'fatura_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif (str_contains($s, 'config/cari_core.php') && $dosya !== 'palet_fisleri.php') {   // palet_fisleri.php cari_core'u yalnız müstahsil kartlarını OKUMAK için yükler (v535) — kendi dalında denetlenir
            // Çalışan modül (Cari İşlemler, cari_* sayfaları): yer tutucu DEĞİL — şema kapısı + sayfa kabuğu; is_admin() kapısı yukarıda zaten denetlendi.
            ok("$dosya: çalışan modül — cari_sayfa_kapisi( (tablo yoksa uyarı kartı)", str_contains($s, 'cari_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif (str_contains($s, 'config/stok_core.php')) {
            // Çalışan modül (Stok, stok_* sayfaları — v524 Parça A: kartlar + tanımlar): şema kapısı + sayfa kabuğu; is_admin() kapısı yukarıda.
            ok("$dosya: çalışan modül — stok_sayfa_kapisi( (tablo yoksa uyarı kartı)", str_contains($s, 'stok_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif (str_contains($s, 'config/kasa_core.php')) {
            // Çalışan modül (Kasa, kasa_* sayfaları — v528): şema kapısı + sayfa kabuğu; is_admin() kapısı yukarıda zaten denetlendi.
            ok("$dosya: çalışan modül — kasa_sayfa_kapisi( (tablo yoksa uyarı kartı)", str_contains($s, 'kasa_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif (str_contains($s, 'config/ceksenet_core.php')) {
            // Çalışan modül (Çek-Senet, cek_* / senet_* sayfaları — v528): şema kapısı + sayfa kabuğu; is_admin() kapısı yukarıda zaten denetlendi.
            ok("$dosya: çalışan modül — ceksenet_sayfa_kapisi( (tablo yoksa uyarı kartı)", str_contains($s, 'ceksenet_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif (str_contains($s, 'config/doviz_core.php')) {
            // Çalışan modül (Döviz & Günlük Kurlar — v576): şema kapısı + sayfa kabuğu + yalnız GET.
            ok("$dosya: çalışan modül — doviz_sayfa_kapisi( (tablo yoksa uyarı kartı)", str_contains($s, 'doviz_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif (str_contains($s, 'config/muhasebe_core.php')) {
            // Çalışan modül (ERP Faz 1: Genel Muhasebe / e-Dönüşüm / Risk-BA-BS-Kur Farkı — v587): şema kapısı + sayfa kabuğu + yalnız GET.
            ok("$dosya: çalışan modül — muh_sayfa_kapisi/edonusum_sayfa_kapisi", str_contains($s, 'muh_sayfa_kapisi(') || str_contains($s, 'edonusum_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif (str_contains($s, 'config/wms_stok_core.php')) {
            // Çalışan modül (ERP Faz 2: WMS Stok / Yeni Araç Yükleme v2 / Satın Alma / Üretim BOM-MRP — v589): şema kapısı + sayfa kabuğu + yalnız GET.
            ok("$dosya: çalışan modül — sayfa_kapisi(", str_contains($s, '_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif (str_contains($s, 'config/onay_core.php')) {
            // Çalışan modül (ERP Faz 3: Onay Akışları / Banka Mutabakat-Kredi-POS / İK Özlük-İzin-Bordro — v590): şema kapısı + sayfa kabuğu + yalnız GET.
            ok("$dosya: çalışan modül — sayfa_kapisi(", str_contains($s, '_sayfa_kapisi('));
            ok("$dosya: çalışan modül — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan modül — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan modül — yalnız GET (\$_POST yok)", !str_contains($s, '$_POST'));
        } elseif ($dosya === 'palet_fisleri.php') {
            // v530: Palet Fişleri aktif, yöneticiye açık Excel tarzı tablo sayfası.
            ok("$dosya: yönetim tablosu", str_contains($s, 'id="pfTable"'));
            ok("$dosya: yeni fiş ve hücre işlemleri", str_contains($s, "'create'") && str_contains($s, "'save'") && str_contains($s, "'duplicate'") && str_contains($s, "'delete'"));
            ok("$dosya: BRÜT / NET başlıkları ve referans alanı yok", str_contains($s, '>BRÜT<') && str_contains($s, '>NET<') && !str_contains($s, 'Yük.Ref') && !str_contains($s, '>Ref<'));
            ok("$dosya: render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: yazma işlemleri muhasebe_palet_can ile kapılı", str_contains($s, "muhasebe_palet_can('edit')") || str_contains($s, "pf_yetki('edit')"));
            ok("$dosya: cari tablolarına YAZMAZ (cari_kart_kaydet/cari_hareket_ekle yok; yazma cari_kaydet.php)", !str_contains($s, 'cari_kart_kaydet(') && !str_contains($s, 'cari_hareket_ekle(') && !preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?cari_/i', $s));
        } elseif (in_array($dosya, ['sifre_degistirme.php', 'ajanda.php', 'kullanici_tercihleri.php'], true)) {
            // v574: Kullanıcı Ayarları grubu çalışan sayfalar (kendi POST'u PRG + CSRF'li; ayrıntılı denetim scripts/kullanici_ayar_smoke.php).
            ok("$dosya: çalışan sayfa — render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
            ok("$dosya: çalışan sayfa — yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
            ok("$dosya: çalışan sayfa — POST varsa csrf_check", !str_contains($s, '$_POST') || str_contains($s, "csrf_check(\$_POST['csrf']"));
        } else {
            ok("$dosya: hazırlanıyor metni", str_contains($s, 'hazırlanıyor'));
            ok("$dosya: render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
        }
    }
}
// v555: menüden kaldırılanlar — katalogda YOK; 4 İhracat sayfa dosyası + kantar_fisleri.php YERİNDE (deploy dosya silmez, URL'ler kırılmaz);
// stok_donusum_fisleri.php 410 tombstone (rol_kapilari_smoke da denetler).
$katalogDosyalari = [];
foreach ($gruplar as $__g) foreach ($__g[2] as $__d => $_) $katalogDosyalari[] = $__d;
ok('v555: "ihracat" (İhracat Programı) grubu katalogda yok', !isset($gruplar['ihracat']));
foreach (['ihracat_faturasi_maliyeti.php', 'beyanname_siparis_proforma.php', 'dovizli_cari_fis.php', 'ihracat_tanimlari.php', 'kantar_fisleri.php', 'stok_donusum_fisleri.php'] as $__kaldirilan) {
    ok("v555: $__kaldirilan menü katalogunda yok", !in_array($__kaldirilan, $katalogDosyalari, true));
}
foreach (['ihracat_faturasi_maliyeti.php', 'beyanname_siparis_proforma.php', 'dovizli_cari_fis.php', 'ihracat_tanimlari.php', 'kantar_fisleri.php'] as $__kalan) {
    ok("v555: $__kalan dosyası yerinde (yalnız menüden/kartlardan kalktı)", is_file($ROOT . '/' . $__kalan));
}
$__tomb = oku('stok_donusum_fisleri.php');
ok('v555: stok_donusum_fisleri.php 410 tombstone (≤15 satır, require/db yok)', str_contains($__tomb, 'http_response_code(410)') && substr_count($__tomb, "\n") <= 15 && !preg_match('/require|include|db\(|session_|\$_POST|\$_GET/', $__tomb));
echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
