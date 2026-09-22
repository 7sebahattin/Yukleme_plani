<?php
// =========================================================
// scripts/pdks_rapor_static_smoke.php — Yönetim Raporlama Merkezi (Faz 6)
// kaynak-kodu kuralları
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar (Faz 2-5'in
// static smoke dosyalarıyla AYNI desen).
//
// Kapsam: görev talimatının statik olarak doğrulanabilir maddeleri —
//   27 finansal bölümler yetki-kapılı           32 sayfa yüklemesinde DDL yok
//   28 operator raporlama sayfasını GÖREMİYOR    33 çavuş/gün başına N+1 sorgu döngüsü yok
//   29 'ik' foreman_accounts OLMADAN finansal veri GÖREMİYOR
//   + para hesabı BINARY FLOAT KULLANMIYOR, ödeme/cari/hesap YAZMA yolu
//     AÇMADI (salt okunur), Faz 1-5 dosyalarına dokunulmadı, migrasyon YOK.
//
//   php scripts/pdks_rapor_static_smoke.php   → çıkış kodu 0 = geçti
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
/** kodSadece()'in AKSİNE /** ... *\/ blok yorumlarını da ÇIKARIR — yalnız
 *  "bu desen/fonksiyon GERÇEK KODDA hiç geçmiyor" tarzı kontroller için;
 *  kodSadece() (yalnız // satırları) diğer TÜM kontrollerle TUTARLILIK
 *  için ayrı bırakıldı, bu yardımcı YALNIZ bu dosyada, açıkça gerekende kullanılır. */
function kodVeYorumsuz(string $s): string { return preg_replace('/\/\*.*?\*\//s', '', kodSadece($s)); }

$raporSrc = oku('config/pdks_rapor.php');
$raporKod = kodSadece($raporSrc);
$sayfaSrc = oku('raporlar.php');
$sayfaKod = kodSadece($sayfaSrc);
$helpersSrc = oku('config/helpers.php');
$migrateSrc = oku('migrate.php');

echo "\n=== 1. SÖZ DİZİMİ ===\n";
$cikti = []; $rc = 0;
exec('php -l ' . escapeshellarg($KOK . '/config/pdks_rapor.php') . ' 2>&1', $cikti, $rc);
ok('config/pdks_rapor.php: php -l geçiyor', $rc === 0, implode("\n", $cikti));
$cikti = []; $rc = 0;
exec('php -l ' . escapeshellarg($KOK . '/raporlar.php') . ' 2>&1', $cikti, $rc);
ok('raporlar.php: php -l geçiyor', $rc === 0, implode("\n", $cikti));

echo "\n=== 2. YETKİ KAPISI (görev madde 27/28) ===\n";
ok('raporlar.php: require_login() çağırıyor', str_contains($sayfaSrc, 'require_login()'));
ok('raporlar.php: require_pdks_rapor() sayfa girişinde ÇAĞRILIYOR (sayfa seviyesi kapı)', str_contains($sayfaSrc, 'require_pdks_rapor()'));
ok("pdks_rapor_can('view'): attendance.management_reports kontrol ediyor", (bool)preg_match("/'view'\s*=>\s*can\('attendance\.management_reports'\)/", $raporSrc));
ok('helpers.php: attendance.management_reports izni seed\'e eklendi', str_contains($helpersSrc, "'attendance.management_reports'"));

echo "\n=== 3. OPERATOR RAPORLAMA SAYFASINI GÖREMİYOR (görev madde 28) ===\n";
preg_match("/'operator'\s*=>\s*\[([^\]]*)\]/s", $helpersSrc, $opM);
$operatorIzinleri = $opM[1] ?? '';
ok("'operator' izin listesi çıkarılabildi", $operatorIzinleri !== '');
ok("'operator' rolüne attendance.management_reports VERİLMEDİ", !str_contains($operatorIzinleri, 'attendance.management_reports'));

echo "\n=== 4. 'ik' YÖNETİM RAPORLARINI GÖRÜR AMA FİNANSAL VERİYİ GÖRMEZ (görev madde 29) ===\n";
preg_match("/'ik'\s*=>\s*\[(.*?)\],\s*\n/s", $helpersSrc, $ikM);
$ikIzinleri = $ikM[1] ?? '';
ok("'ik' izin listesi çıkarılabildi", $ikIzinleri !== '');
ok("'ik' rolüne attendance.management_reports VERİLDİ (operasyonel raporlama uygun — görev talimatı)", str_contains($ikIzinleri, 'attendance.management_reports'));
ok("'ik' rolüne attendance.foreman_accounts VERİLMEDİ (Faz 5'in kararı KORUNDU — finansal bölümler 'ik'e KAPALI kalır)", !str_contains($ikIzinleri, 'attendance.foreman_accounts'));
ok("pdks_rapor_can('financial'): attendance.foreman_accounts kontrol ediyor (YENİ bir finansal izin İCAT EDİLMEDİ, Faz 5'in KENDİ izni REUSE edildi)", (bool)preg_match("/'financial'\s*=>\s*can\('attendance\.foreman_accounts'\)/", $raporSrc));
ok('raporlar.php: finansal bölümler $finansalGosterilebilir/$finansalYetki değişkeniyle BÖLÜM-SEVİYESİNDE sarılıyor (ayrı bir "ik-sürümü" sayfa YOK)', substr_count($sayfaSrc, '$finansalGosterilebilir') >= 5);
ok('raporlar.php: yalnız TEK bir dosya var — "ik" için ayrı bir raporlar_ik.php/rapor_operasyonel.php YOK', !file_exists($KOK . '/raporlar_ik.php') && !file_exists($KOK . '/rapor_operasyonel.php'));

echo "\n=== 5. FİNANSAL BÖLÜMLERDE SIZDIRILAN VERİ YOK (görev talimatı: rates/hakediş/ödeme/bakiye 'ik'e SIZMAMALI) ===\n";
// raporlar.php'nin finansal $-değişkenlerini (finansalKpi/guncelBakiye/bakiyeSiralama)
// dolduran ATAMALAR yalnız `if ($finansalGosterilebilir)` bloğunun İÇİNDEDİR —
// koşulsuz/erken doldurma YOK.
ok('finansalKpi/guncelBakiye/bakiyeSiralama/karsilastirma YALNIZ "if ($finansalGosterilebilir)" bloğu İÇİNDE dolduruluyor',
    (bool)preg_match('/if \(\$finansalGosterilebilir\) \{\s*\n\s*\$finansalKpi\s*=.*?\$guncelBakiye\s*=.*?\$bakiyeSiralama\s*=.*?\$karsilastirma\s*=/s', $sayfaSrc));

echo "\n=== 6. HAKEDİŞ HESABI BİNARY FLOAT KULLANMIYOR (görev madde 12/18, Faz 4/5 kuruş stratejisi REUSE) ===\n";
foreach (['pdks_rapor_operasyonel_kpi', 'pdks_rapor_finansal_kpi', 'pdks_rapor_bakiye_toplu', 'pdks_rapor_cavus_bakiye_toplu', 'pdks_rapor_gunluk_trend', 'pdks_rapor_cavus_ozeti'] as $fn) {
    preg_match('/function ' . preg_quote($fn, '/') . '\(.*?\n\}\n/s', $raporSrc, $fM);
    $govde = kodSadece($fM[0] ?? '');
    ok("$fn() gövdesi çıkarılabildi", $govde !== '');
    ok("$fn() içinde (float)/floatval() CAST YOK", !preg_match('/\(float\)|floatval\(/', $govde));
    ok("$fn() içinde SQL SUM() KULLANILMIYOR (bkz. dosya başlığı — SQLite/MySQL DECIMAL SUM() farkı riski, PHP-taraflı kuruş toplama tercih edildi)", !preg_match('/\bSUM\s*\(/i', $govde));
}
ok('config/pdks_rapor.php: Faz 4\'ün pdks_hakedis_tl_kurus()/pdks_hakedis_kurus_tl() fonksiyonlarını REUSE ediyor (kendi para dönüştürücüsünü İCAT ETMİYOR)',
    str_contains($raporKod, 'pdks_hakedis_tl_kurus(') && str_contains($raporKod, 'pdks_hakedis_kurus_tl('));
ok('pdks_rapor_yuzde_degisim(): YALNIZ görüntüleme yüzdesi için bölme yapıyor, sonucu HİÇBİR finansal hesaba geri beslemiyor (dönüş değeri başka bir *_kurus hesaplamasında KULLANILMIYOR)',
    !preg_match('/pdks_rapor_yuzde_degisim\([^)]*\)\s*\[.(fark|yuzde).\]\s*[-+*\/]=?\s*.*kurus/', $raporKod));

echo "\n=== 7. BAKİYE/EKSTRE FORMÜLÜ FAZ 5'İN AYNASI — YENİDEN İCAT EDİLMEDİ ===\n";
preg_match('/function pdks_rapor_bakiye_toplu.*?\n\}\n/s', $raporSrc, $bakM);
$bakGovde = $bakM[0] ?? '';
ok("pdks_rapor_bakiye_toplu(): yalnız status = 'final' hakediş sayıyor (Faz 5 ilkesiyle AYNI)", (bool)preg_match("/status\s*=\s*'final'/", $bakGovde));
ok("pdks_rapor_bakiye_toplu(): yalnız status = 'valid' ödeme sayıyor (Faz 5 ilkesiyle AYNI)", (bool)preg_match("/status\s*=\s*'valid'/", $bakGovde));
ok("pdks_rapor_bakiye_toplu(): durum/durum_etiket üç değerli AYNI sözleşmeyi kullanıyor (borc/avans/kapali + Çavuşa Borcumuz/Çavuş Avansı/Hesap Kapalı)",
    str_contains($bakGovde, "'borc'") && str_contains($bakGovde, "'avans'") && str_contains($bakGovde, "'kapali'")
    && str_contains($bakGovde, 'Çavuşa Borcumuz') && str_contains($bakGovde, 'Çavuş Avansı / Fazla Ödeme') && str_contains($bakGovde, 'Hesap Kapalı'));
ok('config/pdks_rapor.php: foreman_daily_entitlements/foreman_payments\'a HİÇ INSERT/UPDATE/DELETE YOK (salt okunur — görev talimatı: "Phase 6 must be READ-ONLY reporting")',
    !preg_match('/\b(INSERT INTO|UPDATE|DELETE FROM)\s+(foreman_daily_entitlements|foreman_daily_entitlement_lines|foreman_payments|daily_work_sessions|daily_worker_card_events)\b/i', $raporKod));
ok('raporlar.php: HİÇ INSERT/UPDATE/DELETE YOK (salt okunur)', !preg_match('/\b(INSERT INTO|UPDATE|DELETE FROM)\b/i', $sayfaKod));

echo "\n=== 8. TARİHSEL SNAPSHOT KULLANIMI (görev madde 16) — CANLI ada geri DÖNMÜYOR ===\n";
preg_match('/function pdks_rapor_istisna_satirlari.*?\n\}\n/s', $raporSrc, $istM);
ok('pdks_rapor_istisna_satirlari(): çavuş adı foreman_name_snapshot\'tan okunuyor (foremen.name JOIN\'i DEĞİL)', str_contains($istM[0] ?? '', 'foreman_name_snapshot'));
preg_match('/function pdks_rapor_operasyonel_kpi.*?\n\}\n/s', $raporSrc, $kpiM);
ok('pdks_rapor_operasyonel_kpi(): işçi tipi kırılımı worker_type_name_snapshot\'tan okunuyor (worker_types.name JOIN\'i DEĞİL)', str_contains($kpiM[0] ?? '', 'worker_type_name_snapshot'));

echo "\n=== 9. SAYFA YÜKLEMESİNDE DDL YOK (görev madde 32) ===\n";
ok('raporlar.php: CREATE TABLE / ALTER TABLE İÇERMİYOR', !preg_match('/\b(CREATE TABLE|ALTER TABLE)\b/i', $sayfaSrc));
ok('config/pdks_rapor.php: GERÇEK KODDA (docblock/yorum HARİÇ) CREATE TABLE / ALTER TABLE İÇERMİYOR (Faz 6 YENİ bir tablo/index TANIMLAMAZ — bkz. dosya sonu)', !preg_match('/\b(CREATE TABLE|ALTER TABLE|CREATE INDEX)\b/i', kodVeYorumsuz($raporSrc)));
ok('config/pdks_rapor.php: KENDİ bir "..._migrate()"/"..._tablolar()" fonksiyonu YOK (diğer pdks_*.php modüllerinin AKSİNE — çünkü YENİ tablo YOK)', !preg_match('/function pdks_rapor_(migrate|tablolar)\(/', $raporSrc));
ok('migrate.php: "pdks_rapor" için YENİ bir migrasyon aksiyonu YOK (gerek yok, bkz. dosya başlığı "MİGRASYON GEREKMİYOR")', !str_contains($migrateSrc, 'pdks_rapor_migrate'));

echo "\n=== 10. N+1 YOK — ÇAVUŞ/GÜN BAŞINA DÖNGÜ İÇİNDE SORGU YOK (görev madde 33) ===\n";
// pdks_cari_bakiye() (Faz 5'in TEK-çavuşluk fonksiyonu) config/pdks_rapor.php
// içinde HİÇ çağrılmıyor olmalı — çağrılıyorsa bu, bir foreach İÇİNDE
// per-foreman bakiye hesaplama riskini gösterir (N+1).
ok('config/pdks_rapor.php: GERÇEK KODDA (docblock/yorum HARİÇ) pdks_cari_bakiye() (TEK çavuşluk Faz 5 fonksiyonu) HİÇ ÇAĞRILMIYOR — kendi TOPLU (pdks_rapor_cavus_bakiye_toplu) sürümü kullanılıyor',
    !preg_match('/(?<!_)pdks_cari_bakiye\(/', kodVeYorumsuz($raporSrc)));
ok('config/pdks_rapor.php: GERÇEK KODDA pdks_cari_odeme_listesi()/pdks_cari_ekstre() (TEK çavuşluk Faz 5 fonksiyonları) HİÇ ÇAĞRILMIYOR',
    !preg_match('/pdks_cari_(odeme_listesi|ekstre)\(/', kodVeYorumsuz($raporSrc)));
// pdks_rapor_cavus_ozeti() gövdesinde bir foreach döngüsünün İÇİNDE $pdo->
// query/prepare çağrısı olmamalı (foreman başına ayrı sorgu = N+1).
preg_match('/function pdks_rapor_cavus_ozeti.*?\n\}\n/s', $raporSrc, $ozM);
$ozGovde = $ozM[0] ?? '';
ok('pdks_rapor_cavus_ozeti() gövdesi çıkarılabildi', $ozGovde !== '');
ok('pdks_rapor_cavus_ozeti(): sonuç birleştirme foreach\'i (foreach ($foremenler as $f)) İÇİNDE $pdo->prepare/query YOK — tüm sorgular foreach\'TEN ÖNCE, TEK SEFER çalışıyor',
    (function () use ($ozGovde) {
        if (!preg_match('/foreach \(\$foremenler as \$f\).*?\n    \}\n/s', $ozGovde, $sonForeach)) return false;
        return !preg_match('/\$pdo->(prepare|query)\(/', $sonForeach[0]);
    })());
preg_match('/function pdks_rapor_gunluk_trend.*?\n\}\n/s', $raporSrc, $trM);
$trGovde = $trM[0] ?? '';
ok('pdks_rapor_gunluk_trend(): günleri dolduran "while (strtotime($cursor) <= ...)" döngüsü İÇİNDE $pdo->prepare/query YOK (yalnız boş-gün İSKELETİ kuruyor, sorgu SONRA TEK SEFER)',
    (function () use ($trGovde) {
        if (!preg_match('/while \(strtotime\(\$cursor\).*?\n    \}\n/s', $trGovde, $whileBlok)) return false;
        return !preg_match('/\$pdo->(prepare|query)\(/', $whileBlok[0]);
    })());
ok('pdks_rapor_gunluk_trend(): sonuç birleştirme foreach\'i (foreach ($gunler as $gun => &$g)) İÇİNDE $pdo->prepare/query YOK',
    (function () use ($trGovde) {
        if (!preg_match('/foreach \(\$gunler as \$gun => &\$g\).*?\n    \}\n/s', $trGovde, $sonForeach)) return false;
        return !preg_match('/\$pdo->(prepare|query)\(/', $sonForeach[0]);
    })());
// raporlar.php'nin KENDİSİ hiçbir foreach İÇİNDE pdks_rapor_*() fonksiyonu ÇAĞIRMAMALI
// (döngü içinde veri-çekme fonksiyonu = klasik N+1 belirtisi). Sayfa yalnız
// ZATEN toplu dönen dizileri render eder.
ok('raporlar.php: hiçbir <?php foreach döngüsü İÇİNDE pdks_rapor_*(...) veri-çekme çağrısı YOK (yalnız zaten-toplu sonuçlar render ediliyor)',
    !preg_match('/foreach\s*\([^)]*\)[^{]*\{[^}]*pdks_rapor_(operasyonel_kpi|finansal_kpi|bakiye_toplu|cavus_bakiye_toplu|cavus_ozeti|gunluk_trend|isci_tipi_dagilimi|cavus_bakiye_siralamasi|eksik_cikislar_araligi|acik_mesailer_araligi)\(/s', $sayfaKod));

echo "\n=== 11. HİÇBİR YENİ YAZMA YOLU / MUHASEBE-DIŞI ÖZELLİK YOK (görev SCOPE GUARD) ===\n";
$yasakliKelimeler = ['invoice', 'fatura', 'exchange rate', 'exchange_rate', 'kur_cevrim', 'payroll', 'bordro',
                      'tax', 'vergi', 'journal entry', 'muhasebe_fisi', 'pdf', 'bank reconciliation', 'banka_mutabakat'];
foreach ($yasakliKelimeler as $kelime) {
    $desen = '/\b' . preg_quote($kelime, '/') . '\b/iu';
    ok("config/pdks_rapor.php GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $raporKod));
    ok("raporlar.php GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $sayfaKod));
}

echo "\n=== 12. FAZ 1-5 DOSYALARINA DOKUNULMADI (Faz 6'nın kendi izin ekleri HARİÇ) ===\n";
// ⚠ Faz 7 (kullanıcının açık talimatı: nav/export/print katmanı) beş
// dosyaya (gunluk_isci_puantaj.php, gunluk_isci_puantaj_detay.php,
// cavus_hakedis_detay.php, cavus_odeme.php, cavus_ekstre.php) BİLEREK,
// KAPSAMI BELİRLİ (yalnız CSV başlığı Türkçeleştirme + "🖨️ Yazdır"
// bağlantısı, iş mantığı DEĞİL) dokundu — bu YÜZDEN o beş dosya bu
// listeden ÇIKARILDI (pdks_cari_static_smoke.php'nin AYNI turda AYNI
// gerekçeyle güncellenmiş §6 kontrolüyle TUTARLI). Faz 7'nin KENDİ static
// testi (pdks_takip_static_smoke.php) bu dosyaların iş mantığının
// değişmediğini AYRICA doğrular.
// ⚠ config/pdks_cari.php da BİLEREK bu listeden ÇIKARILDI — Faz 7 ona
// yalnız İKİ salt-görüntüleme etiket fonksiyonu (pdks_cari_odeme_yontem_etiketi/
// pdks_cari_odeme_durum_etiketi) EKLEDİ, DB enum'una veya bakiye/ekstre
// mantığına DOKUNMADI (bkz. pdks_takip_static_smoke.php'nin bunu AYRICA
// doğrulayan kontrolü).
// ⚠ Faz 8A (bkz. scripts/pdks_gunluk_faz8a_static_smoke.php) config/pdks_gunluk.php,
// config/pdks_hakedis.php (mali güvenlik kapısı) ve gunluk_isci_giris_cikis.php'yi
// BİLİNÇLİ OLARAK değiştirdi — görev talimatının KENDİSİ merkezi devam
// modelinin değiştiğini söylüyor. Bu üç dosya BU YÜZDEN listeden çıkarıldı;
// Faz 8A'nın KENDİ static testi kapsamlarının BELİRLİ kaldığını doğrular.
// ⚠ Faz 9A (v227 audit'in M-01/M-04 bulguları, v228): cavus_hakedis.php
// AYNI gerekçeyle (bkz. pdks_cari_static_smoke.php §6'daki AYNI turda
// yapılan AYNI güncelleme) bu listeden ÇIKARILDI — hakedis hesapla artık
// aktif depoyu doğruluyor ve 'entitlements_finalize' istiyor; kapsamı
// scripts/pdks_faz9a_smoke.php AYRICA doğrular.
// ⚠ Faz 9B (v227 audit H-01 kapanışı, v229): cavus_fiyatlari.php AYNI
// gerekçeyle bu listeden ÇIKARILDI — YENİ oran tanımlama açılır listesi
// artık TEK paylaşılan günlük-işçi tip politikasını (yalnız KADIN/ERKEK)
// kullanıyor; kapsamı scripts/pdks_faz9b_smoke.php AYRICA doğrular.
// ⚠ Sprint Navigasyon-05 (v255+, kullanıcı isteği): cavus_cari.php AYNI
// gerekçeyle bu "diff'i BOŞ olmalı" kontrolünden ÇIKARILDI — personel_takip.php'den
// açılan sayfalar arasındaki çapraz gezinme bağlantıları kaldırılıp standart
// "← Personel Takibi" dönüş butonuyla değiştirildi; kapsamı
// scripts/pdks_takip_static_smoke.php AYRICA doğrular (o dosyanın "intentional" bölümü).
// ⚠ Faz 6, üç çok satırlı literali (Faz 5'ten devralınan $p_gunluk +
// 'muhasebe' + 'ik' izin dizileri) attendance.management_reports EKLEYEREK
// genişletti — git diff bu TEK satırlık literalleri "silinip yeniden
// yazılmış" gösterir (pdks_faz1b_static_smoke.php'nin HER faz turunda
// güncellenen AYNI, belgelenen deseni). Bu 3 satır AÇIKÇA allowlist'e
// alınır; bunların DIŞINDA hiçbir satır silinmemiş olmalı.
$helpersDiff = trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff -- config/helpers.php 2>&1'));
$helpersSilinen = array_filter(explode("\n", $helpersDiff), fn($l) => preg_match('/^-(?!--)/', $l) === 1);
$helpersBeklenenEskiSatirlar = [
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports') || can('attendance.foreman_rates') || can('attendance.entitlements') || can('attendance.foreman_accounts') || can('attendance.foreman_payments'))) || \$p_adm;",
    "-                       'attendance.foreman_accounts','attendance.foreman_payments'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay','attendance.daily_reports','attendance.foreman_rates','attendance.entitlements','attendance.foreman_accounts','attendance.foreman_payments'],",
    "-                               'attendance.daily_reports','attendance.entitlements'],",
    // Sprint Navigasyon-01 (Faz 7) — pdks_faz1b_static_smoke.php'nin AYNI
    // turda AYNI gerekçeyle eklediği BÜYÜK, KASITLI silme listesiyle
    // BİREBİR AYNI (bkz. o dosyanın yorumu): 12 dağınık link + $a_*
    // değişkenleri TEK "Personel Takibi" linkine indirildi.
    "-    \$a_pdksp = in_array(\$cur, ['personel.php', 'personel_form.php'], true);",
    "-    \$a_pdksk = \$cur === 'personel_kartlar.php';",
    "-    \$a_pdksg = \$cur === 'giris_cikis.php';",
    "-    \$a_cavus = in_array(\$cur, ['cavuslar.php', 'cavus_form.php'], true);",
    "-    \$a_isk   = in_array(\$cur, ['isci_kartlari.php', 'isci_tipleri.php'], true);",
    "-    \$a_gunlukgc = \$cur === 'gunluk_isci_giris_cikis.php';",
    "-    \$a_puantaj  = in_array(\$cur, ['gunluk_isci_puantaj.php', 'gunluk_isci_puantaj_detay.php'], true);",
    "-    \$a_fiyat    = \$cur === 'cavus_fiyatlari.php';",
    "-    \$a_hakedis  = in_array(\$cur, ['cavus_hakedis.php', 'cavus_hakedis_detay.php'], true);",
    "-    \$a_odeme    = \$cur === 'cavus_odeme.php';",
    "-    \$a_cari     = in_array(\$cur, ['cavus_cari.php', 'cavus_ekstre.php'], true);",
    "-    \$a_yrapor   = \$cur === 'raporlar.php';",
    "-        <?php if (\$p_pdks): ?>",
    "-        <?php if (\$p_pdks || \$p_gunluk): ?>",
    "-        <?php if (\$_fn && (can('attendance.employees') || \$p_adm)) \$lnk('personel.php', '👤', 'Personeller', \$a_pdksp); ?>",
    "-        <?php if (\$_fn && (can('attendance.cards')     || \$p_adm)) \$lnk('personel_kartlar.php', '🪪', 'Kart Yönetimi', \$a_pdksk); ?>",
    "-        <?php if (\$_fn && (can('attendance.scan')      || \$p_adm)) \$lnk('giris_cikis.php', '🚪', 'Giriş / Çıkış', \$a_pdksg); ?>",
    "-        <?php endif; ?>",
    "-",
    "-        <?php if (\$p_gunluk): ?>",
    "-        <div class=\"sidebar-section\">Günlük İşçi</div>",
    "-        <?php if (\$_fn && (can('attendance.foremen')      || \$p_adm)) \$lnk('cavuslar.php',      '👷', 'Çavuşlar',      \$a_cavus); ?>",
    "-        <?php if (\$_fn && (can('attendance.worker_cards') || \$p_adm)) \$lnk('isci_kartlari.php', '🪪', 'İşçi Kartları', \$a_isk); ?>",
    "-        <?php if (\$_fn && (can('attendance.daily_scan')   || \$p_adm)) \$lnk('gunluk_isci_giris_cikis.php', '🚪', 'Giriş / Çıkış', \$a_gunlukgc); ?>",
    "-        <?php if (\$_fn && (can('attendance.daily_reports') || \$p_adm)) \$lnk('gunluk_isci_puantaj.php', '📅', 'Günlük Puantaj', \$a_puantaj); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_rates')  || \$p_adm)) \$lnk('cavus_fiyatlari.php', '💰', 'Çavuş Fiyatları', \$a_fiyat); ?>",
    "-        <?php if (\$_fn && (can('attendance.entitlements')   || \$p_adm)) \$lnk('cavus_hakedis.php',   '🧾', 'Hakediş',         \$a_hakedis); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_payments') || \$p_adm)) \$lnk('cavus_odeme.php', '💸', 'Çavuş Ödeme', \$a_odeme); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_accounts') || \$p_adm)) \$lnk('cavus_cari.php',  '📒', 'Çavuş Cari',  \$a_cari); ?>",
    "-        <?php if (\$_fn && (can('attendance.management_reports') || \$p_adm)) \$lnk('raporlar.php', '📊', 'Yönetim Raporları', \$a_yrapor); ?>",
    // Faz 7: SW cache sürümü + APP_SURUM birlikte v217'den v218'e çekildi
    // (assets/print_base.css'e yazdırma sayfaları için ek kural eklendiği
    // için CLAUDE.md kuralı gereği). Tek satırlık sürüm sabiti güncellemesi,
    // içerik kaybı DEĞİL — pdks_faz1b_static_smoke.php'nin AYNI turda AYNI
    // gerekçeyle eklediği satırın eşi.
    "-    define('APP_SURUM', 'v217');",
    // Faz 8A PRE-MERGE GÜVENLİK DÜZELTMESİ: v218'den v219'a — AYNI rutin
    // tek satırlık sürüm damgası güncellemesi (bkz. yukarıdaki v217→v218
    // emsali, pdks_faz1b_static_smoke.php'nin AYNI turda eklediği satırın eşi).
    "-    define('APP_SURUM', 'v218');",
    "-    define('APP_SURUM', 'v220');",
    "-    define('APP_SURUM', 'v221');",
    "-    define('APP_SURUM', 'v222');",
    "-    define('APP_SURUM', 'v223');",
    "-    define('APP_SURUM', 'v224');",
    "-    define('APP_SURUM', 'v225');",
    "-    define('APP_SURUM', 'v226');",
    // Faz 9A (v228): v227'den v228'e — AYNI rutin tek satırlık sürüm damgası
    // güncellemesi (pdks_faz1b_static_smoke.php'nin AYNI turda eklediği
    // satırın eşi).
    "-    define('APP_SURUM', 'v227');",
    // Faz 9B (v229): v228'den v229'a — AYNI rutin tek satırlık sürüm damgası
    // güncellemesi (pdks_faz1b_static_smoke.php'nin AYNI turda eklediği
    // satırın eşi).
    "-    define('APP_SURUM', 'v228');",
    // Faz 9C (v230): v229'dan v230'a — AYNI rutin tek satırlık sürüm damgası
    // güncellemesi (pdks_faz1b_static_smoke.php'nin AYNI turda eklediği
    // satırın eşi).
    "-    define('APP_SURUM', 'v229');",
    // Faz 9D (v231): v230'dan v231'e — AYNI rutin tek satırlık sürüm damgası
    // güncellemesi (pdks_faz1b_static_smoke.php'nin AYNI turda eklediği
    // satırın eşi).
    "-    define('APP_SURUM', 'v230');",
    "-    define('APP_SURUM', 'v232');",
    "-    define('APP_SURUM', 'v233');",
    // v234-v255 arası birçok küçük sprint turu (bu allowlist o dönemde
    // güncellenmedi, ama HEAD'e zaten commit'lendiği için git diff'te
    // artık görünmüyor). Sprint Navigasyon-05: v255'ten v256'ya çekildi
    // (AYNI rutin, tek satırlık sürüm damgası güncellemesi).
    "-    define('APP_SURUM', 'v255');",
    // Personel Takibi denetimi Fix 1-2-3 kapanışı: v256'dan v257'ye çekildi.
    "-    define('APP_SURUM', 'v256');",
    // Personel Takibi denetimi Fix 5-6-7-8 kapanışı: v257'den v258'e çekildi.
    "-    define('APP_SURUM', 'v257');",
    // Personel Takibi denetimi Fix 9-10-11 kapanışı: v258'den v259'a çekildi.
    "-    define('APP_SURUM', 'v258');",
];
$helpersBeklenmeyenSilinen = array_filter($helpersSilinen, fn($l) => !in_array(trim($l), array_map('trim', $helpersBeklenenEskiSatirlar), true));
ok('config/helpers.php: YALNIZ BİLİNEN/İNCELENMİŞ satırlar değişti (attendance.management_reports genişlemesi), başka hiçbir satır silinmedi',
    count($helpersBeklenmeyenSilinen) === 0, count($helpersBeklenmeyenSilinen) . " beklenmeyen silinen satır:\n" . implode("\n", $helpersBeklenmeyenSilinen));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
