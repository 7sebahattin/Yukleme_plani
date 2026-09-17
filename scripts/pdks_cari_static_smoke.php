<?php
// =========================================================
// scripts/pdks_cari_static_smoke.php — Çavuş Cari (Faz 5) kaynak-kodu kuralları
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar (Faz 2/3/4'ün
// static smoke dosyalarıyla AYNI desen).
//
// Kapsam: görev talimatının statik olarak doğrulanabilir maddeleri —
//   19 yetki kapıları (attendance.foreman_accounts / attendance.foreman_payments)
//   20 operator FİNANSAL/cari sayfaları GÖREMİYOR
//   21 'ik' rolü ödeme yönetemiyor (varsayılan olarak İKİSİNİ de ALMAZ)
//   22 hiçbir normal sayfa DDL çalıştırmıyor (migrasyon TEK kontrollü yoldan)
//   + para hesabı BINARY FLOAT KULLANMIYOR (Faz 4'ün kuruş stratejisi REUSE),
//     Faz 1-4 tablolarına dokunulmadı, Faz 4 reopen koruması doğru bağlandı,
//     ödeme değişmezliği (UPDATE yok, yalnız status geçişi).
//
//   php scripts/pdks_cari_static_smoke.php   → çıkış kodu 0 = geçti
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

$sayfalar = ['cavus_odeme.php', 'cavus_cari.php', 'cavus_ekstre.php'];
$cariSrc = oku('config/pdks_cari.php');
$cariKod = kodSadece($cariSrc);
$hakedisSrc = oku('config/pdks_hakedis.php');
$helpersSrc = oku('config/helpers.php');
$migrateSrc = oku('migrate.php');

echo "\n=== 1. SÖZ DİZİMİ ===\n";
$cikti = []; $rc = 0;
exec('php -l ' . escapeshellarg($KOK . '/config/pdks_cari.php') . ' 2>&1', $cikti, $rc);
ok('config/pdks_cari.php: php -l geçiyor', $rc === 0, implode("\n", $cikti));
foreach ($sayfalar as $f) {
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg($KOK . '/' . $f) . ' 2>&1', $cikti, $rc);
    ok("$f: php -l geçiyor", $rc === 0, implode("\n", $cikti));
}

echo "\n=== 2. YETKİ KAPISI (görev madde 19) ===\n";
foreach ($sayfalar as $f) {
    $src = oku($f);
    ok("$f: require_login() çağırıyor", str_contains($src, 'require_login()'));
    ok("$f: require_pdks_cari(...) sayfa girişinde", (bool)preg_match('/require_pdks_cari\(\s*[\'"][a-z_]+[\'"]\s*\)/', $src));
}
ok("cavus_odeme.php: 'payments' eylemiyle korunuyor (ödeme kaydı/iptali)", (bool)preg_match('/require_pdks_cari\(\s*[\'"]payments[\'"]\s*\)/', oku('cavus_odeme.php')));
ok("cavus_cari.php: 'accounts' eylemiyle korunuyor (bakiye görüntüleme)", (bool)preg_match('/require_pdks_cari\(\s*[\'"]accounts[\'"]\s*\)/', oku('cavus_cari.php')));
ok("cavus_ekstre.php: 'accounts' eylemiyle korunuyor (ekstre görüntüleme)", (bool)preg_match('/require_pdks_cari\(\s*[\'"]accounts[\'"]\s*\)/', oku('cavus_ekstre.php')));

ok("helpers.php: attendance.foreman_accounts izni seed'e eklendi", str_contains($helpersSrc, "'attendance.foreman_accounts'"));
ok("helpers.php: attendance.foreman_payments izni seed'e eklendi", str_contains($helpersSrc, "'attendance.foreman_payments'"));
ok("helpers.php: 'muhasebe' rolü HER İKİ yeni izni de alıyor",
    (bool)preg_match("/'muhasebe'\s*=>\s*\[[^\]]*attendance\.foreman_accounts[^\]]*attendance\.foreman_payments/s", $helpersSrc)
    || (bool)preg_match("/'muhasebe'\s*=>\s*\[[^\]]*attendance\.foreman_payments[^\]]*attendance\.foreman_accounts/s", $helpersSrc));

echo "\n=== 3. 'ik' ROLÜ VARSAYILAN OLARAK ÖDEME YÖNETEMİYOR (görev talimatı: \"ik: NO payment management by default\") ===\n";
preg_match("/'ik'\s*=>\s*\[(.*?)\],\s*\n/s", $helpersSrc, $ikM);
$ikIzinleri = $ikM[1] ?? '';
ok("'ik' izin listesi çıkarılabildi", $ikIzinleri !== '');
ok("'ik' rolüne attendance.foreman_payments VERİLMEDİ (ödeme yönetimi yok)", !str_contains($ikIzinleri, 'attendance.foreman_payments'));
ok("'ik' rolüne attendance.foreman_accounts VERİLMEDİ (belirsizlikte en az yetki)", !str_contains($ikIzinleri, 'attendance.foreman_accounts'));

echo "\n=== 4. OPERATOR FİNANSAL/CARİ SAYFALARI GÖREMİYOR (görev madde 20, Faz 4 ile AYNI ilke) ===\n";
preg_match("/'operator'\s*=>\s*\[([^\]]*)\]/s", $helpersSrc, $opM);
$operatorIzinleri = $opM[1] ?? '';
ok("'operator' izin listesi çıkarılabildi", $operatorIzinleri !== '');
ok("'operator' rolüne attendance.foreman_accounts VERİLMEDİ", !str_contains($operatorIzinleri, 'attendance.foreman_accounts'));
ok("'operator' rolüne attendance.foreman_payments VERİLMEDİ", !str_contains($operatorIzinleri, 'attendance.foreman_payments'));
ok("tarama sayfası (gunluk_isci_giris_cikis.php) ödeme/bakiye/cari GÖSTERMİYOR",
    !preg_match('/\bpayment|ödeme|odeme|bakiye|balance|cari\b/i', oku('gunluk_isci_giris_cikis.php')));

echo "\n=== 5. NORMAL SAYFA ZİYARETİNDE DDL YOK (görev madde 22 — Faz 1-4 kuralıyla AYNI) ===\n";
foreach ($sayfalar as $f) {
    $src = oku($f);
    ok("$f: pdks_cari_migrate() ÇAĞRILMIYOR", !str_contains($src, 'pdks_cari_migrate('));
    ok("$f: pdks_cari_sayfa_kapisi() ÇAĞRILIYOR (güvenli-başarısızlık kapısı REUSE edildi)",
        str_contains($src, 'pdks_cari_sayfa_kapisi('));
    ok("$f: CREATE TABLE / ALTER TABLE İÇERMİYOR", !preg_match('/\b(CREATE TABLE|ALTER TABLE)\b/i', $src));
}
$migCagrisiOlan = [];
foreach (array_merge($sayfalar, ['cavus_fiyatlari.php', 'cavus_hakedis.php', 'cavus_hakedis_detay.php',
                                   'gunluk_isci_puantaj.php', 'gunluk_isci_puantaj_detay.php', 'gunluk_isci_giris_cikis.php',
                                   'cavuslar.php', 'cavus_form.php', 'isci_kartlari.php', 'isci_tipleri.php', 'migrate.php']) as $f) {
    if (str_contains(oku($f), 'pdks_cari_migrate(')) $migCagrisiOlan[] = $f;
}
ok('repodaki TEK pdks_cari_migrate() çağrı yeri YALNIZ migrate.php (Faz 5 ikinci bir yol AÇMADI)',
    $migCagrisiOlan === ['migrate.php'], implode(', ', $migCagrisiOlan));
ok('migrate.php: Cari tabloları için ayrı, kontrollü bir POST aksiyonu var (ne=pdks_cari)',
    (bool)preg_match("/\\\$_POST\['ne'\]\s*\?\?\s*''\)\s*===\s*'pdks_cari'/", $migrateSrc));
ok('migrate.php: bu dal da csrf_check() çağırıyor', (bool)preg_match("/'pdks_cari'\)\s*\{\s*\n\s*csrf_check/", $migrateSrc));

echo "\n=== 6. FAZ 1-4 TABLOLARINA DOKUNULMADI (additive upgrade) ===\n";
ok('config/pdks_cari.php: foremen/foreman_daily_entitlements İÇİN ALTER TABLE YOK',
    !preg_match('/ALTER TABLE\s+`(foremen|foreman_daily_entitlements|foreman_daily_entitlement_lines|foreman_worker_rates)`/i', $cariSrc));
ok('config/pdks_cari.php: bu tablolar için CREATE TABLE YOK (yalnız KENDİ tek yeni tablosu: foreman_payments)',
    !preg_match('/CREATE TABLE IF NOT EXISTS\s+`(foremen|foreman_daily_entitlements|foreman_daily_entitlement_lines|foreman_worker_rates)`/i', $cariSrc));
ok('config/pdks_cari.php: yalnız TEK yeni tablo tanımlıyor (foreman_payments)',
    preg_match_all('/CREATE TABLE IF NOT EXISTS/i', $cariSrc) === 1);
// ⚠ Faz 7 (kullanıcının açık talimatı: nav/export/print katmanı) gunluk_isci_puantaj.php
// (CSV başlığı Türkçeleştirildi), gunluk_isci_puantaj_detay.php/cavus_hakedis_detay.php/
// cavus_odeme.php (yalnız "🖨️ Yazdır" bağlantısı EKLENDİ) dosyalarına BİLEREK,
// KAPSAMI BELİRLİ (yalnız gezinme/dışa aktarım/yazdırma, iş mantığı DEĞİL)
// dokundu — bu YÜZDEN o beş dosya bu listeden ÇIKARILDI; Faz 7'nin KENDİ
// static testi (pdks_takip_static_smoke.php) o dosyaların İŞ MANTIĞININ
// (yalnız UI/export/print DIŞINDA) değişmediğini AYRICA doğrular.
// ⚠ Faz 8A (bkz. scripts/pdks_gunluk_faz8a_static_smoke.php): görev
// talimatının KENDİSİ "bu faz merkezi bir devam varsayımını değiştiriyor"
// diyor — config/pdks_gunluk.php + gunluk_isci_giris_cikis.php Faz 8A'nın
// TAM OLARAK kapsamıdır (nötr kart + mesai dönemi modeli), bu YÜZDEN
// listeden BİLİNÇLİ OLARAK çıkarıldı; Faz 8A'nın KENDİ static testi o
// dosyalardaki değişikliğin kapsam İÇİNDE kaldığını AYRICA doğrular. Faz
// 5'in ASIL kontrol ettiği "kalıcı personel NFC akışına DOKUNULMADI" iddiası
// (giris_cikis.php/pdks_nfc_test.php/assets/pdks.js) burada AYNEN kalır.
// ⚠ Faz 9A (v227 audit'in M-01/M-04 bulguları, v228): cavus_hakedis.php
// BİLİNÇLİ OLARAK bu listeden ÇIKARILDI — hakedis hesapla/yeniden hesapla
// artık (a) session_id'nin AKTİF DEPOYA ait olduğunu sunucu tarafında
// doğruluyor (audit M-01: çapraz-depo IDOR) ve (b) 'entitlements_view'
// yerine 'entitlements_finalize' istiyor (audit M-04: salt-okunur izin
// finansal YAZMA yapabiliyordu). Kapsamı BELİRLİDİR — cari bakiye/ekstre/
// ödeme mantığına DOKUNMAZ; scripts/pdks_faz9a_smoke.php bunu AYRICA
// doğrular.
// ⚠ Faz 9B (v227 audit H-01 kapanışı, v229): cavus_fiyatlari.php AYNI
// gerekçeyle bu listeden ÇIKARILDI — YENİ oran tanımlama açılır listesi
// artık TEK paylaşılan günlük-işçi tip politikasını (yalnız KADIN/ERKEK)
// kullanıyor; kapsamı scripts/pdks_faz9b_smoke.php AYRICA doğrular.
$gcDiff = trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff --stat -- giris_cikis.php pdks_nfc_test.php assets/pdks.js 2>&1'));
ok('Faz 1-4 sayfaları/dosyaları diff\'i BOŞ — Faz 5 onları YENİDEN TASARLAMADI (Faz 8A\'nın KENDİ kapsamı olan config/pdks_gunluk.php + gunluk_isci_giris_cikis.php, Faz 9A\'nın KENDİ kapsamı olan cavus_hakedis.php, Faz 9B\'nin KENDİ kapsamı olan cavus_fiyatlari.php AYRICA test edilir)',
    $gcDiff === '', $gcDiff);
// ⚠ DÜZELTME TURU: kullanıcının açık talimatıyla pdks_hakedis_hesapla()'nın
// 'TRY' hardcode HATASI (financial-integrity düzeltmesi, bu turun ASIL
// konusu) giderildi — bu, Faz 5'in ilk turunda "yalnız EKLEME" olan reopen
// korumasından FARKLI olarak GERÇEK satır değişiklikleri içerir (sabit
// 'TRY' değerinin ve currency'siz UPDATE'in kaldırılması). Bu YETKİLİ,
// BEKLENEN 4 satır aşağıda AÇIKÇA allowlist'e alınır; bunların DIŞINDA
// hiçbir satır silinmemiş olmalı.
$hakedisDiff = trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff -- config/pdks_hakedis.php 2>&1'));
$hakedisSilinen = array_filter(explode("\n", $hakedisDiff), fn($l) => preg_match('/^-(?!--)/', $l) === 1);
$hakedisParaBirimiDuzeltmesiEskiSatirlar = [
    "-    \$satirlar = []; \$toplamKurus = 0; \$eksikTipler = [];",
    "-                SET status='draft', total_amount=?, calculated_at=?, calculated_by_user_id=?, updated_at=?",
    "-        \$upd->execute([pdks_hakedis_kurus_tl(\$toplamKurus), \$simdi, \$userId, \$simdi, (int)\$mevcut['id']]);",
    "-            \$oturum['work_date'], \$oturum['depo'], 'draft', 'TRY', pdks_hakedis_kurus_tl(\$toplamKurus), \$simdi, \$userId,",
];
$hakedisBeklenmeyenSilinen = array_filter($hakedisSilinen, fn($l) => !in_array(trim($l), array_map('trim', $hakedisParaBirimiDuzeltmesiEskiSatirlar), true));
ok('config/pdks_hakedis.php\'deki satır silmeleri YALNIZ Faz 5\'in reopen koruması (0 silme) + DÜZELTME turunun para birimi düzeltmesi (4 belgelenmiş, yetkili satır) — başka HİÇBİR SİLME YOK',
    count($hakedisBeklenmeyenSilinen) === 0, count($hakedisBeklenmeyenSilinen) . " beklenmeyen silinen satır:\n" . implode("\n", $hakedisBeklenmeyenSilinen));

echo "\n=== 7. FAZ 4 KESİN-HAKEDİŞ YENİDEN AÇMA KORUMASI DOĞRU BAĞLANDI (kullanıcının açık talimatı: V1 = BLOKLA) ===\n";
ok("pdks_hakedis_yeniden_ac(): pdks_cari_odeme_var_mi() YUMUŞAK (function_exists) çapraz kontrol çağırıyor",
    (bool)preg_match("/function pdks_hakedis_yeniden_ac.*?function_exists\('pdks_cari_odeme_var_mi'\)\s*&&\s*pdks_cari_odeme_var_mi\(/s", $hakedisSrc));
ok("config/pdks_hakedis.php config/pdks_cari.php'yi SERT (require) BAĞIMLILIKLA çağırmıyor (TERS yön YOK)",
    !preg_match('/require(_once)?\s+.*pdks_cari/', $hakedisSrc));
ok("config/pdks_cari.php config/pdks_hakedis.php'ye YALNIZ TEK YÖNLÜ atıf yapıyor (docblock'ta belgeleniyor)",
    str_contains($cariSrc, 'pdks_hakedis'));

echo "\n=== 8. ÖDEME DEĞİŞMEZLİĞİ (kullanıcının açık talimatı: amount/foreman_id/payment_date/currency ASLA UPDATE edilmez) ===\n";
ok('config/pdks_cari.php: foreman_payments İÇİN "UPDATE foreman_payments SET" YALNIZ status/cancelled_* alanlarını değiştiriyor (bir tane, iptal fonksiyonunda)',
    preg_match_all('/UPDATE\s+foreman_payments\s+SET/i', $cariSrc) === 1);
ok('o TEK UPDATE amount/foreman_id/payment_date/currency alanlarını YAZMIYOR',
    (bool)preg_match('/UPDATE foreman_payments SET status=.cancelled., cancelled_at=\?, cancelled_by_user_id=\?, cancellation_reason=\? WHERE id=\?/', $cariSrc));
ok('pdks_cari_odeme_iptal(): boş gerekçeyi REDDEDİYOR (gerekce_zorunlu)', (bool)preg_match('/function pdks_cari_odeme_iptal.*?gerekce_zorunlu/s', $cariSrc));
ok('pdks_cari_odeme_iptal(): KENDİ İÇİNDE pdks_cari_can(\'payments\') self-check YAPIYOR (savunma derinliği)',
    (bool)preg_match("/function pdks_cari_odeme_iptal.*?pdks_cari_can\('payments'\)/s", $cariSrc));
ok('pdks_cari_odeme_iptal(): zaten iptal edilmiş ödemeyi TEKRAR iptal etmiyor (zaten_iptal)', (bool)preg_match('/function pdks_cari_odeme_iptal.*?zaten_iptal/s', $cariSrc));
ok('pdks_cari_odeme_ekle(): 0 veya negatif tutarı REDDEDİYOR', (bool)preg_match('/function pdks_cari_odeme_ekle.*?kurus <= 0/s', $cariSrc));

echo "\n=== 9. AŞIM ENGELLENMEZ, YALNIZ UYARILIR (kullanıcının açık talimatı: \"Do NOT silently block overpayment\") ===\n";
ok('pdks_cari_odeme_onizleme() bulundu ve ödemeyi hiçbir koşulda REDDETMİYOR (yalnız asim bilgisi döner)',
    (bool)preg_match("/function pdks_cari_odeme_onizleme.*?'asim'\s*=>/s", $cariSrc)
    && !preg_match("/function pdks_cari_odeme_onizleme.*?return \['ok' => false/s", $cariSrc));
ok('cavus_odeme.php: aşım onayı bir CHECKBOX/parametre ile İSTEMCİDEN alınıyor, sunucu KARAR VERMİYOR (asim_onay)',
    str_contains(oku('cavus_odeme.php'), 'asim_onay'));

echo "\n=== 10. HAKEDİŞ HESABI BİNARY FLOAT KULLANMIYOR (Faz 4'ün kuruş stratejisi REUSE, görev madde 12/18) ===\n";
foreach (['pdks_cari_bakiye', 'pdks_cari_odeme_ekle', 'pdks_cari_odeme_onizleme', 'pdks_cari_ekstre'] as $fn) {
    preg_match('/function ' . preg_quote($fn, '/') . '\(.*?\n\}\n/s', $cariSrc, $fM);
    $govde = kodSadece($fM[0] ?? '');
    ok("$fn() gövdesi çıkarılabildi", $govde !== '');
    ok("$fn() içinde (float)/floatval() CAST YOK", !preg_match('/\(float\)|floatval\(/', $govde));
}
ok('config/pdks_cari.php: Faz 4\'ün pdks_hakedis_tl_kurus()/pdks_hakedis_kurus_tl() fonksiyonlarını REUSE ediyor (kendi para dönüştürücüsünü İCAT ETMİYOR)',
    str_contains($cariKod, 'pdks_hakedis_tl_kurus(') && str_contains($cariKod, 'pdks_hakedis_kurus_tl('));
ok('para kolonu DECIMAL (FLOAT/DOUBLE DEĞİL)', str_contains($cariSrc, 'DECIMAL(14,2)'));
preg_match('/function pdks_cari_tablolar.*?\n\}\n/s', $cariSrc, $tabM);
ok('şemanın KENDİSİNDE (DDL gövdesinde, açıklayıcı yorumlar HARİÇ) FLOAT/DOUBLE tipi HİÇ KULLANILMADI',
    !preg_match('/\b(FLOAT|DOUBLE)\b/i', kodSadece($tabM[0] ?? '')));

echo "\n=== 11. BAKİYE YALNIZ KESİN HAKEDİŞ + GEÇERLİ ÖDEMEDEN TÜRETİLİR (görev talimatı, DRAFT ASLA etkilemez) ===\n";
preg_match('/function pdks_cari_bakiye.*?\n\}\n/s', $cariSrc, $bakM);
$bakGovde = $bakM[0] ?? '';
ok("pdks_cari_bakiye(): KESİN olmayan (status != 'final') hakediş dahil EDİLMİYOR — sorgu status = 'final' filtresiyle",
    (bool)preg_match("/status\s*=\s*'final'/", $bakGovde));
ok("pdks_cari_bakiye(): iptal edilmiş ödeme dahil EDİLMİYOR — sorgu status = 'valid' filtresiyle",
    (bool)preg_match("/status\s*=\s*'valid'/", $bakGovde));
ok('config/pdks_cari.php: KENDİ bakiye/hesap tablosunu (2. bir finansal defter) YAZMIYOR — SELECT dışında foreman_daily_entitlements\'a hiç INSERT/UPDATE YOK',
    !preg_match('/(INSERT INTO|UPDATE)\s+foreman_daily_entitlements/i', $cariSrc));

echo "\n=== 12. ÇOKLU PARA BİRİMİ İZOLASYONU (görev talimatı: \"Do NOT sum different currencies together\") ===\n";
ok('pdks_cari_bakiye(): currency bazında AYRI dizi anahtarı kullanıyor (cross-currency toplama YOK)',
    (bool)preg_match('/\$sonuc\[\$cur\]/', $bakGovde));
preg_match('/function pdks_cari_ekstre.*?\n\}\n/s', $cariSrc, $ekstM);
ok('pdks_cari_ekstre(): satırlar currency bazında AYRI dizide toplanıyor, koşan bakiye YALNIZ o para biriminin dizisinde hesaplanıyor',
    (bool)preg_match('/\$satirlar\[\(string\)\$h\[.currency.\]\]\[\]/', $ekstM[0] ?? '')
    && (bool)preg_match('/\$satirlar\[\(string\)\$p\[.currency.\]\]\[\]/', $ekstM[0] ?? ''));

echo "\n=== 13. EKSTRE DETERMİNİSTİK SIRALAMA (görev talimatı: aynı gün için otoriter zaman/id tie-break) ===\n";
ok('pdks_cari_ekstre(): usort() tarih → siralama_zaman → siralama_id sırasıyla tie-break YAPIYOR',
    (bool)preg_match('/strcmp\(\(string\)\$a\[.tarih.\].*?strcmp\(\(string\)\$a\[.siralama_zaman.\].*?<=>\s*\$b\[.siralama_id.\]/s', $ekstM[0] ?? ''));
ok('hakediş satırı siralama_zaman olarak finalized_at (yoksa calculated_at) kullanıyor — İSTEMCİDEN gelen zaman DEĞİL',
    str_contains($ekstM[0] ?? '', "finalized_at'] ?? \$h['calculated_at'"));
ok('ödeme satırı siralama_zaman olarak created_at kullanıyor — sunucu-yetkili zaman damgası',
    str_contains($ekstM[0] ?? '', "created_at']"));

echo "\n=== 14. CSV DIŞA AKTARIM (görev madde 28) — Faz 3'ün AYNI kalıbı ===\n";
$ekstreSrc = oku('cavus_ekstre.php');
ok('cavus_ekstre.php: ?csv=1 uç noktası HTML çıktısından ÖNCE header() çağırıyor', (bool)preg_match('/isset\(\$_GET\[.csv.\]\)\).*?header\(.Content-Type: text\/csv/s', $ekstreSrc));
ok('cavus_ekstre.php: UTF-8 BOM yazıyor (Excel Türkçe uyumluluğu)', str_contains($ekstreSrc, '\xEF\xBB\xBF'));
ok('cavus_ekstre.php: fputcsv noktalı virgül (;) ayraç kullanıyor (Faz 3 kalıbıyla AYNI)', (bool)preg_match_all("/fputcsv\(.*?,\s*';',\s*'\"',\s*'\\\\\\\\'\)/", $ekstreSrc) >= 2);

echo "\n=== 15. SUNUCU-YETKİLİ ZAMAN DAMGALARI ===\n";
foreach (['pdks_cari_odeme_ekle', 'pdks_cari_odeme_iptal'] as $fn) {
    preg_match('/function ' . preg_quote($fn, '/') . '\(([^)]*)\)/', $cariSrc, $sigM);
    $imza = $sigM[1] ?? '';
    ok("$fn() imzasında İSTEMCİDEN zaman parametresi YOK (yalnız payment_date iş tarihidir, oluşturma zamanı DEĞİL)",
        !preg_match('/\bzaman\b|\bcreated_at\b|\bcancelled_at\b/i', $imza), $imza);
}
ok("pdks_cari_odeme_iptal() server-side date('Y-m-d H:i:s') kullanıyor", (bool)preg_match("/function pdks_cari_odeme_iptal.*?date\('Y-m-d H:i:s'\)/s", $cariSrc));

echo "\n=== 16. HİÇBİR FATURA/GENEL MUHASEBE/BANKA MUTABAKATI/KUR/BORDRO/VERGİ/PDF YOK (görev DELIVERABLE dışlama listesi) ===\n";
$yasakliKelimeler = ['invoice', 'fatura', 'exchange rate', 'exchange_rate', 'kur_cevrim', 'payroll', 'bordro',
                      'tax', 'vergi', 'journal entry', 'muhasebe_fisi', 'pdf', 'bank reconciliation', 'banka_mutabakat'];
foreach ($yasakliKelimeler as $kelime) {
    $desen = '/\b' . preg_quote($kelime, '/') . '\b/iu';
    ok("config/pdks_cari.php GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $cariKod));
}
foreach ($sayfalar as $f) {
    $kod = kodSadece(oku($f));
    foreach (['invoice', 'fatura', 'exchange rate', 'exchange_rate', 'payroll', 'bordro', 'tax', 'vergi', 'pdf'] as $kelime) {
        $desen = '/\b' . preg_quote($kelime, '/') . '\b/iu';
        ok("$f GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $kod));
    }
}

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
