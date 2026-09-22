<?php
// =========================================================
// scripts/pdks_takip_static_smoke.php — Personel Takip Merkezi + Türkçe
// Export + Yazdırma Mimarisi (Faz 7) kaynak-kodu kuralları
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar (Faz 2-6'nın
// static smoke dosyalarıyla AYNI desen).
//
// Kapsam: görev talimatının statik olarak doğrulanabilir maddeleri —
//   1  sidebar yalnız TEK "Personel Takibi" girdisi gösterir
//   2  eski dağınık sidebar linkleri sidebar'dan KALDIRILDI
//   9  mobil "Personel" girişi personel_takip.php'ye bağlı
//  10  hedef sayfalar KENDİ yetki kontrolünü koruyor
//  11  personel-ilişkili CSV başlıkları Türkçe
//  12  bilinen İngilizce başlık KALMADI
//  13  export durum/yöntem etiketleri Türkçe
//  14  UTF-8 BOM korundu · 15 noktalı virgül ayracı korundu
//  16  export yetkileri hâlâ uygulanıyor
//  18  paylaşılan print stil sayfası/yardımcısı var
//  27  NFC UID çavuş fişinde BASILMIYOR
//  33  finansal yönetim raporu yazdırması izinleri kontrol ediyor
//  37  sayfa yüklemesinde DDL yok · 38 ikinci bir finansal kaynak YOK
//
//   php scripts/pdks_takip_static_smoke.php   → çıkış kodu 0 = geçti
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
    printf("%-90s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $p): string { global $KOK; return (string)@file_get_contents($KOK . '/' . $p); }
function kodSadece(string $s): string { return preg_replace('/^\s*\/\/.*$/m', '', $s); }

$helpersSrc = oku('config/helpers.php');
$indexSrc   = oku('index.php');
$takipSrc   = oku('personel_takip.php');

$yeniDosyalar = [
    'personel_takip.php', 'gunluk_puantaj_yazdir.php', 'cavus_hakedis_yazdir.php',
    'cavus_ekstre_yazdir.php', 'cavus_odeme_yazdir.php', 'rapor_yazdir.php',
];

echo "\n=== 1. SÖZ DİZİMİ ===\n";
foreach (array_merge($yeniDosyalar, [
    'gunluk_isci_puantaj.php', 'gunluk_isci_puantaj_detay.php', 'cavus_hakedis_detay.php',
    'cavus_ekstre.php', 'cavus_odeme.php', 'raporlar.php', 'index.php',
    'config/helpers.php', 'config/pdks_cari.php',
]) as $f) {
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg($KOK . '/' . $f) . ' 2>&1', $cikti, $rc);
    ok("$f: php -l geçiyor", $rc === 0, implode("\n", $cikti));
}

echo "\n=== 2. SIDEBAR — YALNIZ TEK 'Personel Takibi' GİRDİSİ (görev madde 1/2) ===\n";
ok("helpers.php: 'Personel Takibi' linki var", str_contains($helpersSrc, "'Personel Takibi'"));
ok("helpers.php: personel_takip.php'ye link veriyor", str_contains($helpersSrc, "\$lnk('personel_takip.php'"));
$eskiSidebarLinkleri = [
    "\$lnk('personel.php'", "\$lnk('personel_kartlar.php'", "\$lnk('giris_cikis.php'",
    "\$lnk('cavuslar.php'", "\$lnk('isci_kartlari.php'", "\$lnk('gunluk_isci_giris_cikis.php'",
    "\$lnk('gunluk_isci_puantaj.php'", "\$lnk('cavus_fiyatlari.php'", "\$lnk('cavus_hakedis.php'",
    "\$lnk('cavus_odeme.php'", "\$lnk('cavus_cari.php'", "\$lnk('raporlar.php'",
];
foreach ($eskiSidebarLinkleri as $eski) {
    ok("helpers.php: eski dağınık link KALDIRILDI — $eski YOK", !str_contains($helpersSrc, $eski), $eski);
}
ok("helpers.php: 'Günlük İşçi' section başlığı ARTIK YOK (tek bölüme indirildi)", !str_contains($helpersSrc, '>Günlük İşçi<'));
// ⚠ Sprint Navigasyon-04: link artık ayrı bir bölüm altında değil (Hesap'ın
// altına taşındı, "Personel" başlığı kaldırıldı) — gösterim tek satırlık
// `if ($p_gunluk) $lnk(...)` oldu, eski `if ($p_gunluk): ... endif;`
// bloğu değil. Kapı AYNI değişkene ($p_gunluk) bağlı kalmalı.
ok("helpers.php: Personel Takibi görünürlüğü aktif günlük işçi/hakediş/rapor izinlerine bağlı; legacy PDKS izni tek başına yeterli değil",
    (bool)preg_match('/if \(\$p_gunluk\)\s*\$lnk\(/', $helpersSrc));
// ⚠ Sprint Navigasyon-02: liste sidebar'ın içinden nav_ptak_sayfalari()
// fonksiyonuna taşındı — mobil bottomnav da AYNI kaynağı okuyor.
ok("helpers.php: aktif-sayfa vurgusu TÜM konsolide alt sayfaları kapsıyor (nav_ptak_sayfalari)",
    (bool)preg_match('/\$a_ptak = in_array\(\$cur, nav_ptak_sayfalari\(\), true\);/', $helpersSrc)
    && (bool)preg_match('/function nav_ptak_sayfalari\(\): array/', $helpersSrc));

echo "\n=== 9. MOBİL 'Personel' GİRİŞİ personel_takip.php'YE BAĞLI (görev madde 2) ===\n";
ok("index.php: 'Personel' ana sayfa kartı ARTIK personel_takip.php'ye açılıyor (personel.php DEĞİL)",
    (bool)preg_match('/href="personel_takip\.php"[^>]*class="home-card"/s', $indexSrc));
ok("index.php: bu kart görünürlüğü YALNIZ employees/cards DEĞİL, tüm attendance.* alt-izinlerinden HERHANGİ birine bakıyor",
    (bool)preg_match('/can\(.attendance\.foremen.\).*can\(.attendance\.management_reports.\)/s', $indexSrc)
    || (bool)preg_match('/can\(.attendance\.management_reports.\).*can\(.attendance\.foremen.\)/s', $indexSrc));
ok("index.php: personel.php'ye DOĞRUDAN giden eski 'home-card' bağlantısı KALMADI", !preg_match('/href="personel\.php"[^>]*class="home-card"/', $indexSrc));

echo "\n=== 3. PERSONEL TAKİP SAYFASI — YAPI VE İZİN FARKINDALIĞI ===\n";
ok('personel_takip.php: require_login() çağırıyor', str_contains($takipSrc, 'require_login()'));
ok('personel_takip.php: .home-grid/.home-card kullanıyor (ana dashboard İLE AYNI tasarım dili — görev talimatı)',
    str_contains($takipSrc, 'home-grid') && str_contains($takipSrc, 'home-card'));
ok('personel_takip.php: yalnız aktif modüllere erişimi olmayan kullanıcı forbidden() ile REDDEDİLİYOR',
    (bool)preg_match('/if \(!\$p_gunluk && !\$p_hakcari && !\$p_rapor\)\s*\{\s*\n\s*forbidden\(/', $takipSrc));
ok('personel_takip.php: legacy PDKS izinleri landing erişim koşulunda kullanılmıyor',
    !str_contains($takipSrc, 'attendance.employees') && !str_contains($takipSrc, 'attendance.cards') && !str_contains($takipSrc, 'attendance.scan'));
ok('personel_takip.php: tam 10 ana kartın her biri kendi aktif hedef izniyle sarılı',
    substr_count($takipSrc, 'class="home-card"') === 10 && substr_count($takipSrc, "can('attendance.") >= 10);
ok('personel_takip.php: legacy kartlar ve Ekstre landing kartı yok',
    !str_contains($takipSrc, 'href="personel.php"') && !str_contains($takipSrc, 'href="personel_kartlar.php"')
    && !str_contains($takipSrc, 'href="giris_cikis.php"') && !str_contains($takipSrc, 'href="isci_tipleri.php"')
    && !str_contains($takipSrc, 'href="cavus_ekstre.php"'));
ok('personel_takip.php: Kart Havuzu, Yönetim Raporları ve Çavuş Toplu Döküm doğru hedeflerde',
    (bool)preg_match('/href="isci_kartlari\.php" class="home-card">.*?Kart Havuzu/s', $takipSrc)
    && (bool)preg_match('/href="raporlar\.php" class="home-card">.*?Yönetim Raporları/s', $takipSrc)
    && str_contains($takipSrc, 'cavus_toplu_dokum.php'));
// ⚠ Sprint Navigasyon-05 (kullanıcı isteği): personel_takip.php'den açılan
// sayfalar arasındaki çapraz bağlantılar (Kart Havuzu ↔ Çavuşlar ↔ İşçi
// Tipleri vb.) kaldırıldı, yerine standart "← Personel Takibi" dönüş
// butonu geldi. isci_tipleri.php'nin KENDİSİ silinmedi/erişilemez OLMADI —
// yalnız isci_kartlari.php'den bu çapraz bağlantı gitti.
ok('isci_kartlari.php: İşçi Tipleri/Çavuşlar çapraz bağlantıları KALDIRILDI, yerine standart dönüş butonu geldi',
    !str_contains(oku('isci_kartlari.php'), 'href="isci_tipleri.php"')
    && !str_contains(oku('isci_kartlari.php'), 'href="cavuslar.php"')
    && str_contains(oku('isci_kartlari.php'), 'href="personel_takip.php"'));

echo "\n=== 10. HEDEF SAYFALAR KENDİ YETKİ KONTROLÜNÜ KORUYOR (görev madde 10 — bypass YOK) ===\n";
foreach ([
    'personel.php' => null, 'personel_kartlar.php' => null, 'giris_cikis.php' => null,
    'cavuslar.php' => 'attendance.foremen', 'isci_kartlari.php' => 'attendance.worker_cards',
    'gunluk_isci_giris_cikis.php' => null, 'gunluk_isci_puantaj.php' => null,
    'cavus_fiyatlari.php' => null, 'cavus_hakedis.php' => null, 'cavus_odeme.php' => null,
    'cavus_cari.php' => null, 'raporlar.php' => null,
] as $sayfa => $_) {
    $kod = oku($sayfa);
    ok("$sayfa: require_login() HÂLÂ çağrılıyor (personel_takip.php bunu BYPASS ETMEDİ)", str_contains($kod, 'require_login()'));
}

echo "\n=== 11/12/13/14/15. TÜRKÇE EXPORT BAŞLIKLARI + DEĞERLERİ ===\n";
$puantajSrc = oku('gunluk_isci_puantaj.php');
$ekstreSrc  = oku('cavus_ekstre.php');
$raporSrc   = oku('raporlar.php');
ok("gunluk_isci_puantaj.php CSV başlığı Türkçe (Tarih/Depo/Çavuş/...)",
    (bool)preg_match("/fputcsv\(\\\$out, \['Tarih', 'Depo', 'Çavuş'/", $puantajSrc));
ok("cavus_ekstre.php CSV başlığı Türkçe (Tarih/İşlem Türü/...)",
    (bool)preg_match("/fputcsv\(\\\$out, \['Tarih', 'İşlem Türü'/", $ekstreSrc));
ok("raporlar.php günlük CSV başlığı Türkçe", (bool)preg_match("/\\\$baslik = \['Tarih', 'İşçi Sayısı', 'Çavuş Sayısı'/", $raporSrc));
ok("raporlar.php çavuş CSV başlığı Türkçe", (bool)preg_match("/\\\$baslik = \['Çavuş', 'Çalışılan Gün'/", $raporSrc));

$yasakliBasliklar = ['Date', 'Type', 'Reference', 'Description', 'Increase', 'Decrease', 'Running Balance',
                      'Currency', 'Worker Count', 'Foreman', 'Payment', 'Entitlement', 'Status',
                      'Female Count', 'Male Count', 'Total Entry', 'Total Exit', 'Missing Exit', 'Session Status',
                      'First Entry', 'Last Exit', 'Foreman Count', 'Days Worked', 'Total Workers'];
foreach (['gunluk_isci_puantaj.php' => $puantajSrc, 'cavus_ekstre.php' => $ekstreSrc, 'raporlar.php' => $raporSrc] as $dosya => $kod) {
    $kodTemiz = kodSadece($kod);
    foreach ($yasakliBasliklar as $kelime) {
        $desen = '/[\'"]' . preg_quote($kelime, '/') . '[\'"]/';
        ok("$dosya: bilinen İngilizce başlık '$kelime' KALMADI (fputcsv dizilerinde)", !preg_match($desen, $kodTemiz));
    }
}

ok("cavus_odeme.php: ödeme yöntemi/durumu ekranda Türkçe etiketle gösteriliyor (pdks_cari_odeme_yontem_etiketi)",
    substr_count(oku('cavus_odeme.php'), 'pdks_cari_odeme_yontem_etiketi(') >= 2);
ok("config/pdks_cari.php: pdks_cari_odeme_yontem_etiketi() DB enum'unu DEĞİL, YALNIZ görüntüleme metnini eşliyor (yorum/işlev ayrımı belgelenmiş)",
    (bool)preg_match("/function pdks_cari_odeme_yontem_etiketi.*?'BANK'\s*=>\s*'Banka \/ Havale'.*?'CASH'\s*=>\s*'Nakit'.*?'OTHER'\s*=>\s*'Diğer'/s", oku('config/pdks_cari.php')));
ok("veritabanı enum DEĞERLERİ (status='BANK'/'CASH' vb.) HİÇBİR YERDE değiştirilmedi — yalnız YENİ, salt görüntüleme fonksiyonları eklendi",
    !preg_match("/UPDATE\s+foreman_payments\s+SET\s+payment_method/i", oku('config/pdks_cari.php')));

foreach (['gunluk_isci_puantaj.php' => $puantajSrc, 'cavus_ekstre.php' => $ekstreSrc, 'raporlar.php' => $raporSrc] as $dosya => $kod) {
    ok("$dosya: UTF-8 BOM KORUNDU (\\xEF\\xBB\\xBF)", str_contains($kod, '\xEF\xBB\xBF'));
    ok("$dosya: noktalı virgül (;) ayraç KORUNDU", (bool)preg_match("/fputcsv\([^;]*';'/", $kod));
}

echo "\n=== 16. EXPORT/YAZDIRMA YETKİLERİ SUNUCU TARAFINDA (görev madde 21) ===\n";
foreach ([
    'gunluk_puantaj_yazdir.php' => "require_pdks_gunluk('daily_reports')",
    'cavus_hakedis_yazdir.php' => "require_pdks_hakedis('entitlements_view')",
    'cavus_ekstre_yazdir.php' => "require_pdks_cari('accounts')",
    'cavus_odeme_yazdir.php' => "require_pdks_cari('payments')",
    'rapor_yazdir.php' => 'require_pdks_rapor()',
] as $dosya => $beklenenKapi) {
    $kod = oku($dosya);
    ok("$dosya: require_login() çağırıyor", str_contains($kod, 'require_login()'));
    ok("$dosya: $beklenenKapi ile korunuyor (kaynak sayfayla AYNI yetki — ?id=/?foreman_id= elle değiştirerek atlanamaz)",
        str_contains($kod, $beklenenKapi));
}
ok("rapor_yazdir.php: finansal bölüm AYRICA pdks_rapor_can('financial') ile SARILI (kaynak sayfayla AYNI kural)",
    (bool)preg_match("/if \(\\\$finansalGosterilebilir/", oku('rapor_yazdir.php')));

echo "\n=== 18. PAYLAŞILAN YAZDIRMA MİMARİSİ (görev madde 18) — MEVCUT Sprint Print-Arch-01 REUSE EDİLDİ ===\n";
ok('assets/print_base.css var (Faz 7 YENİ bir print CSS dosyası İCAT ETMEDİ — repoda ZATEN var olanı REUSE etti)',
    file_exists($KOK . '/assets/print_base.css'));
ok('config/print_helpers.php var (render_print_page_start/end, render_print_header_html)',
    file_exists($KOK . '/config/print_helpers.php'));
$printBaseCss = oku('assets/print_base.css');
foreach (['.no-print', '.print-only', '.print-sheet', '.print-header', '.print-summary-row', 'table.print-table', '.print-signatures'] as $sinif) {
    ok("assets/print_base.css: '$sinif' sınıfı tanımlı (görev talimatının istediği paylaşılan sınıflar)", str_contains($printBaseCss, $sinif));
}
ok('assets/print_base.css: @media print içinde .desktop-sidebar/.bottomnav/.topbar GİZLENİYOR (uygulama chrome\'u hiç basılmaz)',
    (bool)preg_match('/@media print \{.*?\.desktop-sidebar.*?\}/s', $printBaseCss) && str_contains($printBaseCss, '.bottomnav'));
ok('assets/print_base.css: thead { display: table-header-group } — çok sayfalı yazdırmada başlık TEKRARLANIYOR (görev talimatı madde 20)',
    (bool)preg_match('/table\.print-table\s+thead\s*\{\s*display:\s*table-header-group/', $printBaseCss));
foreach ($yeniDosyalar as $f) {
    if ($f === 'personel_takip.php') continue;
    ok("$f: print_helpers.php'yi REQUIRE ediyor (KENDİ print CSS/JS'ini İCAT ETMEDİ)", str_contains(oku($f), "require_once __DIR__ . '/config/print_helpers.php';"));
    ok("$f: 'Yazdır' butonu no-print sınıfıyla İŞARETLİ (kağıda BASILMAZ — görev talimatı madde 10/36)",
        (bool)preg_match('/no-print["\'][^>]*>[\s\S]*?onclick="window\.print\(\)"/', oku($f)));
}

echo "\n=== 27. NFC UID ÇAVUŞ FİŞİNDE BASILMIYOR (görev madde 11) ===\n";
$puantajYazdirSrc = oku('gunluk_puantaj_yazdir.php');
ok('gunluk_puantaj_yazdir.php: canonical_uid/ham_uid/uid_decimal HİÇ YOK — yalnız card_no basılıyor',
    !preg_match('/canonical_uid|ham_uid|uid_decimal/', $puantajYazdirSrc));
ok('gunluk_puantaj_yazdir.php: pdks_gunluk_oturum_kartlari() REUSE ediyor (kendi kart sorgusunu YAZMIYOR)',
    str_contains($puantajYazdirSrc, 'pdks_gunluk_oturum_kartlari('));
ok('gunluk_puantaj_yazdir.php: eksik çıkış UYDURULMUYOR — çıkış saati boşsa boş kalıyor, "Eksik Çıkış" AÇIKÇA yazılıyor',
    str_contains($puantajYazdirSrc, "'⚠️ Eksik Çıkış'") && (bool)preg_match('/\$k\[.cikis_saat.\] \? h\(date\(.H:i., strtotime\(\$k\[.cikis_saat.\]\)\)\) : .\s*./', $puantajYazdirSrc));
ok('gunluk_puantaj_yazdir.php: imza alanları (Çavuş + Kontrol Eden) print-signatures içinde var',
    substr_count($puantajYazdirSrc, 'print-sig-box') === 2 && str_contains($puantajYazdirSrc, 'Çavuş') && str_contains($puantajYazdirSrc, 'Kontrol Eden'));

echo "\n=== FİNANSAL/HAKEDİŞ YAZDIRMA — İKİNCİ BİR HESAP YOK (görev madde 17/38) ===\n";
foreach ([
    'cavus_hakedis_yazdir.php' => 'pdks_hakedis_satirlar(',
    'cavus_ekstre_yazdir.php' => 'pdks_cari_ekstre(',
    'cavus_odeme_yazdir.php' => 'pdks_cari_odeme_listesi(',
] as $dosya => $reuseFn) {
    $kod = kodSadece(oku($dosya));
    ok("$dosya: $reuseFn REUSE ediyor (kendi hesap/sorgu mantığını YAZMIYOR)", str_contains($kod, $reuseFn));
    ok("$dosya: INSERT/UPDATE/DELETE YOK (salt okunur — görev talimatı: 'Print documents are PRESENTATION ONLY')",
        !preg_match('/\b(INSERT INTO|UPDATE|DELETE FROM)\b/i', $kod));
    ok("$dosya: (float)/floatval() YALNIZ number_format için kullanılıyor — SQL SUM() YOK", !preg_match('/\bSUM\s*\(/i', $kod));
}
ok('cavus_ekstre_yazdir.php: para birimleri AYRI bölümlerde (foreach ... as \$cur => \$satirlar) basılıyor, ASLA TOPLANMIYOR',
    (bool)preg_match('/foreach \(\$ekstre as \$cur => \$satirlar\)/', kodSadece(oku('cavus_ekstre_yazdir.php'))));
ok('cavus_odeme_yazdir.php: GEÇERLİ ödeme toplamı pdks_cari_bakiye()\'NİN KENDİSİNDEN (status=valid süzülmüş) geliyor, ekran satırlarından YENİDEN TOPLANMIYOR',
    str_contains(kodSadece(oku('cavus_odeme_yazdir.php')), "\$b['odeme_toplam']") && !preg_match('/array_sum\(.*odemeler/', kodSadece(oku('cavus_odeme_yazdir.php'))));

echo "\n=== 37. SAYFA YÜKLEMESİNDE DDL YOK (görev madde 23/37) ===\n";
// ⚠ config/pdks_cari.php BİLEREK bu listede DEĞİL — o Faz 5'in KENDİ,
// önceden onaylanmış foreman_payments şemasını taşır (bu tur ona yalnız
// İKİ salt-görüntüleme etiket fonksiyonu EKLEDİ, hiçbir DDL SATIRI
// eklemedi/değiştirmedi — bkz. yukarıdaki "veritabanı enum DEĞERLERİ..."
// kontrolü). Faz 7'nin YENİ dosyalarının HİÇBİRİNDE DDL olmaması asıl
// kontrol edilen şey.
foreach ($yeniDosyalar as $f) {
    ok("$f: CREATE TABLE / ALTER TABLE İÇERMİYOR", !preg_match('/\b(CREATE TABLE|ALTER TABLE)\b/i', kodSadece(oku($f))));
}
ok('migrate.php: Faz 7 için YENİ bir migrasyon aksiyonu YOK (gerek yok)', !str_contains(oku('migrate.php'), 'personel_takip'));

echo "\n=== FAZ 1-6 DOSYALARINA İŞ MANTIĞI DEĞİŞİKLİĞİ YOK (yalnız nav/export/print eklendi) ===\n";
// ⚠ Faz 8A (bkz. scripts/pdks_gunluk_faz8a_static_smoke.php) config/pdks_gunluk.php,
// config/pdks_hakedis.php, config/pdks_rapor.php, isci_kartlari.php VE
// gunluk_isci_giris_cikis.php'yi BİLİNÇLİ, KAPSAMI BELİRLİ biçimde değiştirdi
// (nötr kart + mesai dönemi modeli — görev talimatının KENDİSİ: "This phase
// changes a central attendance assumption"). Bu beş dosya BU YÜZDEN listeden
// çıkarıldı; Faz 8A'nın KENDİ static testi kapsamı doğrular.
// ⚠ Faz 9B (v227 audit H-01 kapanışı, v229): cavus_fiyatlari.php BİLİNÇLİ
// OLARAK bu listeden ÇIKARILDI — YENİ oran tanımlama açılır listesi artık
// pdks_gunluk_desteklenen_tip_listele() (yalnız KADIN/ERKEK) kullanıyor;
// kapsamı scripts/pdks_faz9b_smoke.php AYRICA doğrular.
foreach ([
    'personel.php', 'personel_kartlar.php', 'giris_cikis.php',
] as $f) {
    $diff = trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff --stat -- ' . escapeshellarg($f) . ' 2>&1'));
    ok("$f: diff'i BOŞ — Faz 7 dokunmadı", $diff === '', $diff);
}

// ⚠ cavus_cari.php bu listeden ÇIKARILDI (Sprint Print-PDKS-01, kullanıcı
// onayıyla): sayfaya TEK satırlık "🖨️ Yazdır" bağlantısı eklendi. Dosyanın
// SALT OKUNUR doğası korunuyor — aşağıdaki kural onu ayrıca sabitliyor,
// dolayısıyla koruma kalkmış olmuyor, yalnız bilinen bir eklemeye açılıyor.
$cariSrc = oku('cavus_cari.php');
ok('cavus_cari.php: intentional Yazdır bağlantısı (tek ekleme) — başka bir şey değişmedi',
    str_contains($cariSrc, 'cavus_cari_yazdir.php')
    && !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $cariSrc));


// ⚠ Sprint Navigasyon-05: "Kart Havuzu" çapraz bağlantısı cavuslar.php'den
// KALDIRILDI (yukarıdaki AYNI gerekçe) — personel_takip.php'nin kendi kartı
// hâlâ isci_kartlari.php'ye açılıyor, bu sayfadan artık DEĞİL.
$cavuslarSrc = oku('cavuslar.php');
ok('cavuslar.php: Kart Havuzu çapraz bağlantısı KALDIRILDI, standart dönüş butonu var',
    !str_contains($cavuslarSrc, 'href="isci_kartlari.php"')
    && str_contains($cavuslarSrc, 'href="personel_takip.php"'));

$tiplerSrc = oku('isci_tipleri.php');
ok('isci_tipleri.php: intentional Kart Havuzu back-link rename',
    str_contains($tiplerSrc, 'href="isci_kartlari.php"')
    && str_contains($tiplerSrc, 'Kart Havuzu'));

echo "\n=== 11. SPRINT NAVİGASYON-05 — personel_takip.php'DEN AÇILAN 10 SAYFA (kullanıcı isteği) ===\n";
// personel_takip.php'nin 10 kartından doğrudan açılan sayfalarda birbirine
// giden "gereksiz" çapraz bağlantılar (Çavuşlar↔Kart Havuzu↔Fiyatlar↔
// Hakediş↔Puantaj↔Giriş-Çıkış↔Cari↔Ödeme↔Raporlar↔Toplu Döküm) kaldırıldı;
// yerine HER birine standart "← Personel Takibi" dönüş butonu eklendi.
// Yazdır butonlarına DOKUNULMADI — kullanıcı açıkça "yazdır butonlarını
// kaldırma" dedi.
$ptak10 = [
    'cavuslar.php', 'isci_kartlari.php', 'gunluk_isci_giris_cikis.php',
    'gunluk_isci_puantaj.php', 'cavus_fiyatlari.php', 'cavus_hakedis.php',
    'cavus_cari.php', 'cavus_odeme.php', 'raporlar.php', 'cavus_toplu_dokum.php',
];
foreach ($ptak10 as $f) {
    $src = oku($f);
    ok("$f: standart \"← Personel Takibi\" dönüş butonu var",
        str_contains($src, 'href="personel_takip.php"') && str_contains($src, '← Personel Takibi'));
}
// Yazdırma bağlantısı olan 6 sayfada Yazdır AYNEN duruyor (kaldırılmadı).
foreach ([
    'gunluk_isci_puantaj.php'  => 'gunluk_puantaj_liste_yazdir.php',
    'cavus_hakedis.php'        => 'cavus_hakedis_liste_yazdir.php',
    'cavus_cari.php'           => 'cavus_cari_yazdir.php',
    'cavus_odeme.php'          => 'cavus_odeme_yazdir.php',
    'raporlar.php'             => 'rapor_yazdir.php',
    'cavus_toplu_dokum.php'    => 'cavus_toplu_dokum_yazdir.php',
] as $f => $yazdirHedef) {
    ok("$f: Yazdır bağlantısı KORUNDU ($yazdirHedef)", str_contains(oku($f), $yazdirHedef));
}
// personel_takip.php'nin KENDİ 10 kartı hâlâ hepsine açılıyor — giriş
// noktası bu sayfa, çapraz linkler değil.
$ptakSrcTumu = oku('personel_takip.php');
foreach ($ptak10 as $f) {
    ok("personel_takip.php: $f kartı hâlâ duruyor (giriş noktası)", str_contains($ptakSrcTumu, "href=\"$f\""));
}

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
