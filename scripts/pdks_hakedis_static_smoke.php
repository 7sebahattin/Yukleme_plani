<?php
// =========================================================
// scripts/pdks_hakedis_static_smoke.php — Çavuş Hakediş (Faz 4) kaynak-kodu kuralları
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar (Faz 2/3'ün
// static smoke dosyalarıyla AYNI desen).
//
// Kapsam: görev talimatının kalan maddeleri —
//   18 para hesabı BINARY FLOAT KULLANMIYOR
//   19 yetki kapıları (attendance.foreman_rates / attendance.entitlements)
//   20 operator FİNANSAL sayfaları GÖREMİYOR
//   21 hiçbir normal sayfa DDL çalıştırmıyor
//   + ödeme/cari/fatura/genel muhasebe YOK, DECIMAL kullanımı, Faz 1-3'e
//     dokunulmadığı, migrasyon additive olduğu.
//
//   php scripts/pdks_hakedis_static_smoke.php   → çıkış kodu 0 = geçti
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
function oku(string $p): string {
    global $KOK;
    $s = (string)@file_get_contents($KOK . '/' . $p);
    return str_replace(["\r\n", "\r"], "\n", $s);
}
function kodSadece(string $s): string { return preg_replace('/^\s*\/\/.*$/m', '', $s); }

$sayfalar = ['cavus_fiyatlari.php', 'cavus_hakedis.php', 'cavus_hakedis_detay.php'];
$hakedisSrc = oku('config/pdks_hakedis.php');
$hakedisKod = kodSadece($hakedisSrc);
$helpersSrc = oku('config/helpers.php');
$gunlukSrc  = oku('config/pdks_gunluk.php');
$migrateSrc = oku('migrate.php');

echo "\n=== 1. SÖZ DİZİMİ ===\n";
$cikti = []; $rc = 0;
exec('php -l ' . escapeshellarg($KOK . '/config/pdks_hakedis.php') . ' 2>&1', $cikti, $rc);
ok('config/pdks_hakedis.php: php -l geçiyor', $rc === 0, implode("\n", $cikti));
foreach ($sayfalar as $f) {
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg($KOK . '/' . $f) . ' 2>&1', $cikti, $rc);
    ok("$f: php -l geçiyor", $rc === 0, implode("\n", $cikti));
}

echo "\n=== 2. YETKİ KAPISI (görev madde 19) ===\n";
foreach ($sayfalar as $f) {
    $src = oku($f);
    ok("$f: require_login() çağırıyor", str_contains($src, 'require_login()'));
    ok("$f: require_pdks_hakedis(...) sayfa girişinde", (bool)preg_match('/require_pdks_hakedis\(\s*[\'"][a-z_]+[\'"]\s*\)/', $src));
}
ok("cavus_fiyatlari.php: 'rates' eylemiyle korunuyor (ticari fiyat yönetimi)", (bool)preg_match('/require_pdks_hakedis\(\s*[\'"]rates[\'"]\s*\)/', oku('cavus_fiyatlari.php')));
ok("cavus_hakedis.php: 'entitlements_view' eylemiyle korunuyor", (bool)preg_match('/require_pdks_hakedis\(\s*[\'"]entitlements_view[\'"]\s*\)/', oku('cavus_hakedis.php')));
ok("cavus_hakedis_detay.php: KESİNLEŞTİRME dalı 'entitlements_finalize' eylemiyle AYRICA korunuyor",
    (bool)preg_match('/require_pdks_hakedis\(\s*[\'"]entitlements_finalize[\'"]\s*\)/', oku('cavus_hakedis_detay.php')));

ok("pdks_hakedis_can(): 'entitlements_finalize' İKİ MEVCUT iznin (rates + entitlements) KESİŞİMİ — YENİ bir izin İCAT ETMİYOR",
    (bool)preg_match("/'entitlements_finalize'\s*=>\s*can\('attendance\.entitlements'\)\s*&&\s*can\('attendance\.foreman_rates'\)/", $hakedisSrc));
ok("helpers.php: attendance.foreman_rates izni seed'e eklendi", str_contains($helpersSrc, "'attendance.foreman_rates'"));
ok("helpers.php: attendance.entitlements izni seed'e eklendi", str_contains($helpersSrc, "'attendance.entitlements'"));
ok("helpers.php: 'muhasebe' rolü HER İKİ yeni izni de alıyor (ticari fiyat + hakediş)",
    (bool)preg_match("/'muhasebe'\s*=>\s*\[[^\]]*attendance\.foreman_rates[^\]]*attendance\.entitlements/s", $helpersSrc)
    || (bool)preg_match("/'muhasebe'\s*=>\s*\[[^\]]*attendance\.entitlements[^\]]*attendance\.foreman_rates/s", $helpersSrc));
ok("helpers.php: 'ik' rolü YALNIZ attendance.entitlements alıyor (attendance.foreman_rates ALMIYOR)",
    (bool)preg_match("/'ik'\s*=>\s*\[[^\]]*attendance\.entitlements/s", $helpersSrc)
    && !(bool)preg_match("/'ik'\s*=>\s*\[[^\]]*attendance\.foreman_rates/s", $helpersSrc));

echo "\n=== 3. OPERATOR FİNANSAL SAYFALARI GÖREMİYOR (görev madde 20 + 13, kullanıcının açık talimatı) ===\n";
preg_match("/'operator'\s*=>\s*\[([^\]]*)\]/s", $helpersSrc, $opM);
$operatorIzinleri = $opM[1] ?? '';
ok("'operator' izin listesi çıkarılabildi", $operatorIzinleri !== '');
ok("'operator' rolüne attendance.foreman_rates VERİLMEDİ", !str_contains($operatorIzinleri, 'attendance.foreman_rates'));
ok("'operator' rolüne attendance.entitlements VERİLMEDİ", !str_contains($operatorIzinleri, 'attendance.entitlements'));
$taramaKod = kodSadece(oku('gunluk_isci_giris_cikis.php'));
ok('tarama sayfası hakediş durumunu gösterebilir fakat fiyat/tutar/para birimi GÖSTERMEZ',
    !preg_match('/\b(?:daily_rate|half_day_rate|overtime_rate|unit_rate|total_amount|signed_amount|foreman_worker_rates|fiyat|ucret|ücret|tutar)\b|₺/iu', $taramaKod)
    && !preg_match('/\b(?:TRY|TL)\b/u', $taramaKod));

echo "\n=== 4. NORMAL SAYFA ZİYARETİNDE DDL YOK (görev madde 21 — Faz 1-3 kuralıyla AYNI) ===\n";
foreach ($sayfalar as $f) {
    $src = oku($f);
    ok("$f: pdks_hakedis_migrate() ÇAĞRILMIYOR", !str_contains($src, 'pdks_hakedis_migrate('));
    ok("$f: pdks_hakedis_sayfa_kapisi() ÇAĞRILIYOR (Faz 1-3'ün güvenli-başarısızlık kapısı REUSE edildi)",
        str_contains($src, 'pdks_hakedis_sayfa_kapisi('));
    ok("$f: CREATE TABLE / ALTER TABLE İÇERMİYOR", !preg_match('/\b(CREATE TABLE|ALTER TABLE)\b/i', $src));
}
$migCagrisiOlan = [];
foreach (array_merge($sayfalar, ['gunluk_isci_puantaj.php', 'gunluk_isci_puantaj_detay.php', 'gunluk_isci_giris_cikis.php',
                                   'cavuslar.php', 'cavus_form.php', 'isci_kartlari.php', 'isci_tipleri.php', 'migrate.php']) as $f) {
    if (str_contains(oku($f), 'pdks_hakedis_migrate(')) $migCagrisiOlan[] = $f;
}
ok('repodaki TEK pdks_hakedis_migrate() çağrı yeri YALNIZ migrate.php (Faz 4 ikinci bir yol AÇMADI)',
    $migCagrisiOlan === ['migrate.php'], implode(', ', $migCagrisiOlan));
ok('migrate.php: Hakediş tabloları için ayrı, kontrollü bir POST aksiyonu var (ne=pdks_hakedis)',
    (bool)preg_match("/\\\$_POST\['ne'\]\s*\?\?\s*''\)\s*===\s*'pdks_hakedis'/", $migrateSrc));
ok('migrate.php: bu dal da csrf_check() çağırıyor', (bool)preg_match("/'pdks_hakedis'\)\s*\{\s*\n\s*csrf_check/", $migrateSrc));

echo "\n=== 5. FAZ 1-3 TABLOLARINA DOKUNULMADI (görev madde 16 — additive upgrade) ===\n";
ok('config/pdks_hakedis.php: foremen/worker_types/daily_work_sessions/daily_worker_card_events İÇİN ALTER TABLE YOK',
    !preg_match('/ALTER TABLE\s+`(foremen|worker_types|daily_work_sessions|daily_worker_card_events)`/i', $hakedisSrc));
ok('config/pdks_hakedis.php: bu dört tablo için CREATE TABLE YOK (yalnız KENDİ üç yeni tablosu)',
    !preg_match('/CREATE TABLE IF NOT EXISTS\s+`(foremen|worker_types|daily_work_sessions|daily_worker_card_events)`/i', $hakedisSrc));
// Faz 7: gunluk_isci_puantaj.php (CSV başlığı Türkçeleştirildi) ve
// gunluk_isci_puantaj_detay.php (Çavuş Gün Sonu Fişi yazdırma bağlantısı
// eklendi) bu turda KASITLI olarak değişti — navigasyon/çıktı katmanı, iş
// mantığı değil. Bu ikisi listeden çıkarıldı; iş mantığı değişmediği
// pdks_takip_static_smoke.php'de ayrıca doğrulanıyor. NFC tarama dosyaları
// (giris_cikis.php/pdks_nfc_test.php/assets/pdks.js/gunluk_isci_giris_cikis.php)
// hâlâ tamamen dokunulmamış olmalı.
// ⚠ Faz 8A (bkz. scripts/pdks_gunluk_faz8a_static_smoke.php) config/pdks_gunluk.php
// VE gunluk_isci_giris_cikis.php'yi BİLİNÇLİ OLARAK, KAPSAMI BELİRLİ biçimde
// değiştirdi (nötr kart + mesai dönemi modeli — görev talimatının KENDİSİ:
// "This phase changes a central attendance assumption"). Bu YÜZDEN "sıfır
// satır silindi" (Faz 4'ün KENDİ, o zamanki kapsamı için doğru olan) kuralı
// artık config/pdks_gunluk.php için GEÇERLİ DEĞİL — Faz 8A'nın KENDİ static
// testi o dosyadaki değişikliklerin (worker_cards.worker_type_id nullable,
// eski UNIQUE kısıtının kaldırılması, mevcut fonksiyonlara dahili dallanma)
// KAPSAM İÇİNDE ve BELGELİ olduğunu AYRICA doğrular. NFC tarama dosyaları
// (giris_cikis.php/pdks_nfc_test.php/assets/pdks.js) HÂLÂ tamamen dokunulmamış olmalı.
$gcDiff = trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff --stat -- giris_cikis.php pdks_nfc_test.php assets/pdks.js 2>&1'));
ok('Kalıcı personel NFC tarama dosyaları diff\'i BOŞ — Faz 4 onları YENİDEN TASARLAMADI',
    $gcDiff === '', $gcDiff);

echo "\n=== 6. HAKEDİŞ HESABI BİNARY FLOAT KULLANMIYOR (görev madde 12/18) ===\n";
preg_match('/function pdks_hakedis_hesapla.*?\n\}\n/s', $gunlukSrc . $hakedisSrc, $hesM);
$hesGovde = kodSadece($hesM[0] ?? '');
ok('pdks_hakedis_hesapla() gövdesi çıkarılabildi', $hesGovde !== '');
ok('para hesabında (float)/floatval() CAST YOK', !preg_match('/\(float\)|floatval\(/', $hesGovde));
ok('birim × adet çarpımı int KURUŞ üzerinde yapılıyor (int × int)', str_contains($hesGovde, '$birimKurus * $adet'));
ok('toplam int KURUŞ üzerinde biriktiriliyor (+=)', str_contains($hesGovde, '$toplamKurus += $satirKurus'));
ok('pdks_hakedis_tl_kurus()/pdks_hakedis_kurus_tl() TAMAMEN string/regex/int tabanlı — hiçbir yerde (float) YOK',
    (function () use ($hakedisKod) {
        preg_match('/function pdks_hakedis_tl_kurus.*?\n\}/s', $hakedisKod, $m1);
        preg_match('/function pdks_hakedis_kurus_tl.*?\n\}/s', $hakedisKod, $m2);
        $govde = ($m1[0] ?? '') . ($m2[0] ?? '');
        return $govde !== '' && !preg_match('/\(float\)|floatval\(/', $govde);
    })());
preg_match('/function pdks_hakedis_tablolar.*?\n\}\n/s', $hakedisSrc, $tabM);
$tablolarGovde = $tabM[0] ?? '';
ok('pdks_hakedis_tablolar() gövdesi çıkarılabildi', $tablolarGovde !== '');
ok('para kolonları DECIMAL (FLOAT/DOUBLE DEĞİL)', str_contains($hakedisSrc, 'DECIMAL(12,2)') && str_contains($hakedisSrc, 'DECIMAL(14,2)'));
ok('şemanın KENDİSİNDE (DDL gövdesinde, açıklayıcı yorumlar HARİÇ) FLOAT/DOUBLE tipi HİÇ KULLANILMADI',
    !preg_match('/\b(FLOAT|DOUBLE)\b/i', kodSadece($tablolarGovde)));
ok('pdks_hakedis_girdi_kurus(): kullanıcı girdisi doğrulanmadan (regex geçmeden) asla kuruşa çevrilmiyor (0 UYDURULMUYOR)',
    (bool)preg_match('/function pdks_hakedis_girdi_kurus.*?return null.*?return pdks_hakedis_tl_kurus/s', $hakedisKod));

echo "\n=== 7. HİÇBİR ÖDEME/CARİ/FATURA/GENEL MUHASEBE YOK (kullanıcının açık talimatı) ===\n";
// ⚠ Faz 5 (kullanıcının açık talimatı: "block unsafe reopen") pdks_hakedis_yeniden_ac()'a
// TEK, belgelenmiş bir çapraz-modül güvenlik kontrolü ekledi — o kontrolün hata
// mesajı KAÇINILMAZ olarak "ödeme"/"bakiye" kelimelerini içerir (kullanıcıya NEDEN
// engellendiğini açıklamak için). Bu YENİ bir ödeme/cari YAZMA yolu AÇMAZ (bkz.
// pdks_cari_static_smoke.php §7 — o kontrolün YALNIZ pdks_cari_odeme_var_mi()'yi
// YUMUŞAK çağırdığını, sert bağımlılık KURMADIĞINI doğrular). Bu yüzden bu fonksiyonun
// gövdesi taramadan ÇIKARILIR — dosyanın GERİ KALANI hâlâ tam kapsamda taranır.
$hakedisKodTaramaHaric = preg_replace('/(\/\*\*.*?\*\/\s*)?function pdks_hakedis_yeniden_ac.*?\n\}\n/s', '', $hakedisKod);
ok('pdks_hakedis_yeniden_ac() gövdesi + kendi docblock\'u tarama-dışı bırakılabildi (fonksiyon bulunabildi)', $hakedisKodTaramaHaric !== $hakedisKod);
$yasakliKelimeler = ['payment', 'ödeme', 'odeme', 'invoice', 'fatura', 'cari_hesap', 'current_account',
                      'bank', 'banka', 'cash', 'kasa', 'pdf', 'balance', 'bakiye',
                      'exchange rate', 'exchange_rate', 'kur_cevrim', 'fx_rate', 'doviz_kuru'];
foreach ($yasakliKelimeler as $kelime) {
    $desen = '/\b' . preg_quote($kelime, '/') . '\b/iu';
    ok("config/pdks_hakedis.php GERÇEK KODUNDA (Faz 5 reopen-koruma fonksiyonu HARİÇ) '$kelime' YOK", !preg_match($desen, $hakedisKodTaramaHaric));
}
// ⚠ Faz 9D / H-03 kapanışı: cavus_hakedis_detay.php artık BİLEREK bu KELİME
// taramasının DIŞINDA — KESİN hakedişe bağlı düzeltme/mahsup kartı, o
// düzeltmenin bu çavuşa yapılmış ÖDEMELERİ HİÇ DEĞİŞTİRMEDİĞİNİ AÇIKÇA
// belirtmek için "ödeme" kelimesini kullanır (bkz. config/pdks_faz9d.php —
// YENİ bir ödeme/cari YAZMA yolu AÇILMADI, yalnız var olan AYRIMI sayfada
// yazılı olarak da netleştirdi). cavus_fiyatlari.php/cavus_hakedis.php İÇİN
// tarama DEĞİŞMEDİ — kapsamı scripts/pdks_faz9d_smoke.php AYRICA doğrular.
foreach (array_diff($sayfalar, ['cavus_hakedis_detay.php']) as $f) {
    $kod = kodSadece(oku($f));
    foreach (['payment', 'ödeme', 'odeme', 'invoice', 'fatura', 'cari_hesap', 'current_account', 'bank', 'banka', 'balance', 'bakiye'] as $kelime) {
        $desen = '/\b' . preg_quote($kelime, '/') . '\b/iu';
        ok("$f GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $kod));
    }
}
$hakedisDetayKod = kodSadece(oku('cavus_hakedis_detay.php'));
foreach (['invoice', 'fatura', 'cari_hesap', 'current_account', 'bank', 'banka', 'balance', 'bakiye'] as $kelime) {
    $desen = '/\b' . preg_quote($kelime, '/') . '\b/iu';
    ok("cavus_hakedis_detay.php GERÇEK KODUNDA '$kelime' YOK (yalnız payment/ödeme/odeme Faz 9D istisnası)", !preg_match($desen, $hakedisDetayKod));
}

echo "\n=== 8. TASLAK/KESİN YAŞAM DÖNGÜSÜ — otomatik yeniden hesaplama KİLİDİ ===\n";
preg_match('/function pdks_hakedis_hesapla.*?\n\}\n/s', $hakedisSrc, $hM);
ok("pdks_hakedis_hesapla() KESİN (status='final') kayıtları REDDEDİYOR", (bool)preg_match("/zaten_kesinlesmis/", $hM[0] ?? ''));
ok("pdks_hakedis_yeniden_ac() yalnız is_admin() İLE çalışıyor", (bool)preg_match('/function pdks_hakedis_yeniden_ac.*?is_admin\(\)/s', $hakedisSrc));
ok("pdks_hakedis_yeniden_ac() BOŞ gerekçeyi REDDEDİYOR (records.unlock/revision_reason İLE AYNI ilke)",
    (bool)preg_match("/function pdks_hakedis_yeniden_ac.*?gerekce_zorunlu/s", $hakedisSrc));
ok("pdks_hakedis_finalize() oturum AÇIKSA REDDEDİYOR", (bool)preg_match("/function pdks_hakedis_finalize.*?oturum_acik/s", $hakedisSrc));
ok("pdks_hakedis_finalize() eksik-çıkışta AÇIK ONAY istiyor", (bool)preg_match("/function pdks_hakedis_finalize.*?eksik_cikis_onay_gerekli/s", $hakedisSrc));
ok("pdks_hakedis_finalize() oran EKSİKSE REDDEDİYOR (0 üretmiyor)", (bool)preg_match("/function pdks_hakedis_finalize.*?oran_eksik/s", $hakedisSrc));

echo "\n=== 9. SUNUCU-YETKİLİ ZAMAN DAMGALARI ===\n";
foreach (['pdks_hakedis_hesapla', 'pdks_hakedis_finalize', 'pdks_hakedis_yeniden_ac'] as $fn) {
    preg_match('/function ' . preg_quote($fn, '/') . '\(([^)]*)\)/', $hakedisSrc, $sigM);
    $imza = $sigM[1] ?? '';
    ok("$fn() imzasında İSTEMCİDEN zaman parametresi YOK", !preg_match('/zaman|time/i', $imza), $imza);
}
ok("pdks_hakedis_hesapla() server-side date('Y-m-d H:i:s') kullanıyor", (bool)preg_match("/function pdks_hakedis_hesapla.*?date\('Y-m-d H:i:s'\)/s", $hakedisSrc));

echo "\n=== 10. PARA BİRİMİ DÜZELTMESİ — İSTEMCİDEN GELMEZ, KARIŞIK PARA BİRİMİ REDDEDİLİR ===\n";
preg_match('/function pdks_hakedis_hesapla\(([^)]*)\)/', $hakedisSrc, $hesSigM);
ok("pdks_hakedis_hesapla() imzasında currency/para_birimi parametresi YOK (istemciden asla alınmaz)",
    !preg_match('/currency|para_?birimi/i', $hesSigM[1] ?? ''), $hesSigM[1] ?? '');
ok("pdks_hakedis_hesapla() gövdesinde sabit 'TRY' literal YAZMA/INSERT değeri olarak KULLANILMIYOR (yalnız boş-oran yer tutucusu yorumda geçebilir)",
    !preg_match("/,\s*'TRY'\s*,/", $hM[0] ?? ''));
ok("pdks_hakedis_hesapla(): para birimi UYGULANAN orandan (\$oran['currency']) OKUNUYOR",
    (bool)preg_match('/\$oran\[.currency.\]/', $hM[0] ?? ''));
ok("pdks_hakedis_hesapla(): birden fazla para birimi tespit edilince 'karisik_para_birimi' ile REDDEDİYOR",
    (bool)preg_match('/karisik_para_birimi/', $hM[0] ?? ''));
ok("pdks_hakedis_hesapla(): karışık para birimi kontrolü HERHANGİ bir DELETE/UPDATE/INSERT'TEN ÖNCE çalışıyor (validate-first — kısmi yazım yok)",
    (function () use ($hM) {
        $govde = $hM[0] ?? '';
        $posKontrol = strpos($govde, 'karisik_para_birimi');
        $posYazim = strpos($govde, 'DELETE FROM foreman_daily_entitlement_lines');
        return $posKontrol !== false && $posYazim !== false && $posKontrol < $posYazim;
    })());
ok("pdks_hakedis_hesapla(): TASLAK yeniden-hesaplamasında (UPDATE dalı) da currency=? YAZILIYOR (donuk kalan eski 'TRY' YOK)",
    (bool)preg_match("/UPDATE foreman_daily_entitlements\s+SET status='draft', currency=\?/", $hM[0] ?? ''));
ok("cavus_fiyatlari.php'nin \$_POST['currency']'si YALNIZ pdks_hakedis_oran_ekle() (bir RATE tanımlamak) için kullanılıyor, pdks_hakedis_hesapla()/finalize()'a HİÇ GEÇİRİLMİYOR",
    !preg_match('/pdks_hakedis_hesapla\([^)]*currency|pdks_hakedis_finalize\([^)]*currency/i', oku('cavus_fiyatlari.php')));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
