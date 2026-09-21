<?php
// =========================================================
// scripts/pdks_print_liste_smoke.php — Personel Takibi liste/döküm
// yazdırma sayfaları (Sprint Print-PDKS-01) kaynak-kodu kuralları
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar
// (pdks_takip_static_smoke.php ile AYNI desen).
//
// Kapsam:
//   1  söz dizimi
//   2  mevcut Print-Arch-01 REUSE edildi (yeni print CSS/JS İCAT EDİLMEDİ)
//   3  "Yazdır" butonu .no-print içinde — kâğıda BASILMAZ
//   4  her sayfa KAYNAK sayfasıyla AYNI yetki kapısını kullanıyor
//   5  yazdırma sayfaları SALT OKUNUR — hiçbir yazma yolu yok
//   6  ikinci bir agregasyon YOK — kaynak veri fonksiyonları REUSE ediliyor
//   7  para birimleri toplanmıyor / kuruş üzerinden toplanıyor
//   8  mesai_degerlendirme_yazdir.php depo (IDOR) kapısını TEKRARLIYOR
//   9  kaynak sayfalarda Yazdır bağlantısı var ve filtreleri taşıyor
//
//   php scripts/pdks_print_liste_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$KOK = dirname(__DIR__);
$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-96s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $p): string { global $KOK; return (string)@file_get_contents($KOK . '/' . $p); }

// yazdırma sayfası => kaynak sayfası
$ciftler = [
    'cavus_toplu_dokum_yazdir.php'     => 'cavus_toplu_dokum.php',
    'cavus_hakedis_liste_yazdir.php'   => 'cavus_hakedis.php',
    'cavus_cari_yazdir.php'            => 'cavus_cari.php',
    'gunluk_puantaj_liste_yazdir.php'  => 'gunluk_isci_puantaj.php',
    'mesai_degerlendirme_yazdir.php'   => 'mesai_degerlendirme.php',
];

echo "\n=== 1. SÖZ DİZİMİ ===\n";
foreach (array_merge(array_keys($ciftler), array_values($ciftler)) as $f) {
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg($KOK . '/' . $f) . ' 2>&1', $cikti, $rc);
    ok("$f: php -l temiz", $rc === 0, implode("\n", $cikti));
}

echo "\n=== 2. PRINT-ARCH-01 REUSE (yeni print CSS/JS İCAT EDİLMEDİ) ===\n";
foreach (array_keys($ciftler) as $f) {
    $s = oku($f);
    ok("$f: print_helpers.php REQUIRE ediliyor", str_contains($s, "require_once __DIR__ . '/config/print_helpers.php';"));
    ok("$f: render_print_page_start/end çifti kullanılıyor",
        str_contains($s, 'render_print_page_start(') && str_contains($s, 'render_print_page_end('));
    ok("$f: render_print_header_html() ile standart başlık bloğu var", str_contains($s, 'render_print_header_html('));
    ok("$f: tablolar 'print-table' sınıfını kullanıyor (paylaşılan stil)", str_contains($s, 'class="print-table"'));
    ok("$f: KENDİ <style> bloğunu İCAT ETMİYOR (@page print_page_style()'dan gelir)",
        !preg_match('/<style\b/i', $s));
    ok("$f: assets/style.css veya app.js YÜKLEMİYOR (uygulama chrome'u gelmez)",
        !str_contains($s, 'assets/style.css') && !str_contains($s, 'assets/app.js'));
    ok("$f: render_header()/render_footer() ÇAĞIRMIYOR (sidebar/topbar/bottomnav yok)",
        !preg_match('/\brender_header\s*\(/', $s) && !preg_match('/\brender_footer\s*\(/', $s));
}

echo "\n=== 2B. PERSONEL TAKİBİ YAZDIRMA TEMASI (print_pdks.css) ===\n";
$temaCss = oku('assets/print_pdks.css');
ok('assets/print_pdks.css var', $temaCss !== '');
ok('print_pdks.css: görsel kurallar @media print DIŞINDA da tanımlı (ekran önizlemesi çıplak HTML gibi görünmez)',
    (bool)preg_match('/^table\.print-table\s*\{/m', $temaCss)
    && (bool)preg_match('/^\.print-summary-row\s*\{/m', $temaCss));
ok('print_pdks.css: renkli zemin üstüne BEYAZ yazı YOK (tarayıcı "arka plan grafikleri" kapalıyken metin kaybolmaz)',
    !preg_match('/color:\s*#fff[^;]*;[^}]*background:\s*(?!#fff)/i', str_replace(["\n", ' '], '', $temaCss))
    || !preg_match('/\.print-header-title[^}]*color:\s*#fff/i', $temaCss));
ok('print_pdks.css: başlık ve tablo ayrımını ÇERÇEVE taşıyor (zemin basılmasa da yapı durur)',
    str_contains($temaCss, 'border-bottom: 3px solid var(--pr-accent)')
    && str_contains($temaCss, 'border: 1px solid var(--pr-line)'));
ok('print_pdks.css: print_base.css DEĞİŞTİRİLMEDİ — tema onun ÜZERİNE yükleniyor',
    str_contains($temaCss, 'print_base.css'));
foreach (array_keys($ciftler) as $f) {
    ok("$f: print_pdks.css temasını yüklüyor", str_contains(oku($f), "['print_pdks.css']"));
}
// Personel Takibi'nin ESKİ yazdırma sayfaları da AYNI temayı kullanır —
// biri temasız kalırsa çıktılar iki ayrı görünüme ayrışır.
foreach (['cavus_odeme_yazdir.php', 'cavus_hakedis_yazdir.php', 'cavus_ekstre_yazdir.php',
          'gunluk_puantaj_yazdir.php', 'rapor_yazdir.php'] as $f) {
    ok("$f: AYNI temayı yüklüyor (Personel Takibi çıktıları tek görünüm)", str_contains(oku($f), "['print_pdks.css']"));
}
// Tema YALNIZ Personel Takibi'ne ait — Yükleme/Çıkma/Stok çıktıları etkilenmez.
foreach (['print_loading.php', 'print_daily.php', 'malzeme_stok_rapor.php'] as $f) {
    ok("$f: temayı YÜKLEMİYOR (kapsam Personel Takibi ile sınırlı, bu sayfalar değişmedi)",
        !str_contains(oku($f), 'print_pdks.css'));
}
$helperSrc = oku('config/print_helpers.php');
ok("config/print_helpers.php: \$extra_css parametresi geriye dönük uyumlu (varsayılan boş dizi)",
    (bool)preg_match('/array \$extra_css = \[\]/', $helperSrc));
ok('config/print_helpers.php: ek CSS adı doğrulanıyor (dışarıdan yol/URL enjekte edilemez)',
    (bool)preg_match("/preg_match\('\/\^\[A-Za-z0-9_-\]\+\\\\\.css\\\$\/'/", $helperSrc));

echo "\n=== 3. 'YAZDIR' BUTONU KÂĞIDA BASILMIYOR ===\n";
foreach (array_keys($ciftler) as $f) {
    $s = oku($f);
    ok("$f: Yazdır butonu .no-print kapsayıcısı İÇİNDE",
        (bool)preg_match('/no-print["\'][^>]*>[\s\S]*?onclick="window\.print\(\)"/', $s));
    ok("$f: kaynak sayfaya geri dönüş bağlantısı da .no-print içinde",
        (bool)preg_match('/no-print["\'][^>]*>[\s\S]*?<a href=[\s\S]*?<\/div>/', $s));
}

echo "\n=== 4. YETKİ KAPILARI KAYNAK SAYFAYLA AYNI ===\n";
$beklenenKapilar = [
    'cavus_toplu_dokum_yazdir.php'    => 'require_pdks_rapor()',
    'cavus_hakedis_liste_yazdir.php'  => "require_pdks_hakedis('entitlements_view')",
    'cavus_cari_yazdir.php'           => "require_pdks_cari('accounts')",
    'gunluk_puantaj_liste_yazdir.php' => "require_pdks_gunluk('daily_reports')",
    'mesai_degerlendirme_yazdir.php'  => "require_pdks_hakedis('entitlements_finalize')",
];
foreach ($beklenenKapilar as $f => $kapi) {
    $kaynak = $ciftler[$f];
    ok("$f: $kapi kapısı var", str_contains(oku($f), $kapi));
    ok("$f: kapı KAYNAK sayfa ($kaynak) ile AYNI", str_contains(oku($kaynak), $kapi));
    ok("$f: require_login() ile açılıyor", str_contains(oku($f), 'require_login()'));
}
ok("cavus_toplu_dokum_yazdir.php: hakediş kolonu AYRICA pdks_rapor_can('financial') istiyor (URL ile atlanamaz)",
    str_contains(oku('cavus_toplu_dokum_yazdir.php'), "pdks_rapor_can('financial')"));

echo "\n=== 5. SALT OKUNUR — İKİNCİ BİR YAZMA YOLU YOK ===\n";
foreach (array_keys($ciftler) as $f) {
    $s = oku($f);
    ok("$f: SQL yazma ifadesi YOK (INSERT/UPDATE/DELETE)",
        !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $s));
    ok("$f: \$_POST okumuyor (GET-only yazdırma sayfası)", !str_contains($s, '$_POST'));
    ok("$f: csrf_token()/csrf_check() gerekmiyor (yazma yok)",
        !str_contains($s, 'csrf_check(') && !str_contains($s, 'csrf_token('));
    ok("$f: audit_log_event() ÇAĞIRMIYOR (salt okunur görüntüleme)", !str_contains($s, 'audit_log_event('));
    ok("$f: <form> İÇERMİYOR (kâğıtta gönderilecek bir şey yok)", !preg_match('/<form\b/i', $s));
}

echo "\n=== 6. İKİNCİ AGREGASYON YOK — KAYNAK FONKSİYONLAR REUSE ===\n";
$beklenenFonksiyonlar = [
    'cavus_toplu_dokum_yazdir.php'    => ['pdks_rapor_cavus_toplu_dokum('],
    'cavus_hakedis_liste_yazdir.php'  => ['pdks_gunluk_gun_listesi(', 'pdks_hakedis_gun_listesi(', 'pdks_faz8b_oturum_ozeti('],
    'cavus_cari_yazdir.php'           => ['pdks_cari_hesap_listesi('],
    'gunluk_puantaj_liste_yazdir.php' => ['pdks_gunluk_gun_ozeti(', 'pdks_gunluk_gun_listesi(', 'pdks_gunluk_eksik_cikislar('],
    'mesai_degerlendirme_yazdir.php'  => ['pdks_faz8b_oturum_donemleri(', 'pdks_faz8b_oturum_ozeti('],
];
foreach ($beklenenFonksiyonlar as $f => $fnler) {
    $s = oku($f);
    foreach ($fnler as $fn) {
        ok("$f: $fn REUSE ediliyor (kendi sorgusunu yazmıyor)", str_contains($s, $fn));
    }
}

echo "\n=== 7. PARA BİRİMİ KURALI (CLAUDE.md: kurlar ASLA toplanmaz) ===\n";
$cariSrc = oku('cavus_cari_yazdir.php');
ok('cavus_cari_yazdir.php: özet para birimi BAŞINA anahtarlanıyor ($ozet[$cur])',
    (bool)preg_match('/\$ozet\[\$cur\]/', $cariSrc));
ok('cavus_cari_yazdir.php: toplama KURUŞ (tam sayı) alanları üzerinden yapılıyor (float kayması yok)',
    str_contains($cariSrc, "bakiye_kurus") && str_contains($cariSrc, "hakedis_kurus") && str_contains($cariSrc, "odeme_kurus"));
ok('cavus_cari_yazdir.php: düzeltme kolonu var — bakiye = hakediş + düzeltme − ödeme aritmetiği kâğıtta kapanıyor',
    str_contains($cariSrc, 'duzeltme_kurus') && str_contains($cariSrc, 'Düzeltme'));
$hakedisListeSrc = oku('cavus_hakedis_liste_yazdir.php');
ok('cavus_hakedis_liste_yazdir.php: hakediş toplamı para birimi BAŞINA ayrı ($hakedisParaBirimi[$cur])',
    (bool)preg_match('/\$hakedisParaBirimi\[\$cur\]/', $hakedisListeSrc));

echo "\n=== 8. DEPO / IDOR KAPISI ===\n";
$mdSrc = oku('mesai_degerlendirme_yazdir.php');
ok('mesai_degerlendirme_yazdir.php: pdks_gunluk_depo_kontrol() ile depo kapısı TEKRARLANIYOR (?session_id= ile doğrudan açılabiliyor)',
    str_contains($mdSrc, 'pdks_gunluk_depo_kontrol(') && str_contains($mdSrc, 'forbidden('));
ok('mesai_degerlendirme_yazdir.php: depo kapısı KAYNAK sayfayla AYNI fonksiyonu kullanıyor',
    str_contains(oku('mesai_degerlendirme.php'), 'pdks_gunluk_depo_kontrol('));
foreach (['cavus_toplu_dokum_yazdir.php', 'cavus_hakedis_liste_yazdir.php', 'gunluk_puantaj_liste_yazdir.php'] as $f) {
    ok("$f: aktif depo filtreye besleniyor (active_depot())", str_contains(oku($f), 'active_depot()'));
}

echo "\n=== 9. KAYNAK SAYFALARDA YAZDIR BAĞLANTISI (filtreleri taşıyor) ===\n";
foreach ($ciftler as $yazdir => $kaynak) {
    $s = oku($kaynak);
    ok("$kaynak: $yazdir bağlantısı var", str_contains($s, $yazdir));
    ok("$kaynak: bağlantı yeni sekmede açılıyor (ekrandaki filtre kaybolmasın)",
        (bool)preg_match('/' . preg_quote($yazdir, '/') . '[\s\S]{0,400}?target="_blank"/', $s));
}
foreach ([
    'cavus_toplu_dokum.php'   => ["'ay' =>", "'cavus' =>"],
    'cavus_hakedis.php'       => ["'tarih' =>", "'cavus' =>", "'durum' =>"],
    'cavus_cari.php'          => ["'q' =>", "'durum' =>"],
    'gunluk_isci_puantaj.php' => ["'tarih' =>", "'cavus' =>", "'durum' =>"],
] as $kaynak => $parcalar) {
    $s = oku($kaynak);
    $yazdirSatiri = '';
    foreach (explode("\n", $s) as $satir) {
        if (str_contains($satir, '_yazdir.php')) { $yazdirSatiri = $satir; break; }
    }
    foreach ($parcalar as $p) {
        ok("$kaynak: Yazdır bağlantısı $p filtresini taşıyor", str_contains($yazdirSatiri, $p));
    }
}
ok('mesai_degerlendirme.php: Yazdır bağlantısı session_id taşıyor',
    (bool)preg_match('/mesai_degerlendirme_yazdir\.php\?session_id=/', oku('mesai_degerlendirme.php')));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
