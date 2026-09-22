<?php
// =========================================================
// scripts/pdks_gunluk_faz3_static_smoke.php — Günlük İşçi Faz 3 kaynak-kodu kuralları
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar
// (pdks_gunluk_faz2_static_smoke.php İLE AYNI desen).
//
// Kapsam: görev talimatının kalan maddeleri —
//   16 yetki kapısı (attendance.daily_reports)
//   17 operator'a geniş rapor izni VERİLMEDİ
//   18 hiçbir rapor sayfası DDL çalıştırmıyor
//   19 filtreler parametrik (istemciden gelen değer doğrudan SQL'e eklenmiyor)
//   + hakediş/fiyat/ödeme YOK, salt-okunur (edit/delete/manuel-çıkış YOK),
//     foreman/worker-type snapshot kullanımı, N+1 önleme deseni.
//
//   php scripts/pdks_gunluk_faz3_static_smoke.php   → çıkış kodu 0 = geçti
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

$sayfaListe = 'gunluk_isci_puantaj.php';
$sayfaDetay = 'gunluk_isci_puantaj_detay.php';
$gunlukSrc  = oku('config/pdks_gunluk.php');
$helpersSrc = oku('config/helpers.php');
$srcListe   = oku($sayfaListe);
$srcDetay   = oku($sayfaDetay);

echo "\n=== 1. SÖZ DİZİMİ ===\n";
foreach ([$sayfaListe, $sayfaDetay] as $f) {
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg($KOK . '/' . $f) . ' 2>&1', $cikti, $rc);
    ok("$f: php -l geçiyor", $rc === 0, implode("\n", $cikti));
}

echo "\n=== 2. YETKİ KAPISI (görev madde 16) ===\n";
foreach ([$sayfaListe => $srcListe, $sayfaDetay => $srcDetay] as $f => $src) {
    ok("$f: require_login() çağırıyor", str_contains($src, 'require_login()'));
    ok("$f: require_pdks_gunluk('daily_reports') sayfa girişinde",
        (bool)preg_match('/require_pdks_gunluk\(\s*[\'"]daily_reports[\'"]\s*\)/', $src));
}
ok("pdks_gunluk_can() match ifadesine 'daily_reports' eklendi",
    (bool)preg_match("/'daily_reports'\s*=>\s*can\('attendance\.daily_reports'\)/", $gunlukSrc));
ok("helpers.php: attendance.daily_reports izni seed'e eklendi", str_contains($helpersSrc, "'attendance.daily_reports'"));
ok("helpers.php: 'ik' rolüne attendance.daily_reports verildi",
    (bool)preg_match("/'ik'\s*=>\s*\[[^\]]*attendance\.daily_reports/s", $helpersSrc));
ok("helpers.php: 'muhasebe' rolüne attendance.daily_reports verildi",
    (bool)preg_match("/'muhasebe'\s*=>\s*\[[^\]]*attendance\.daily_reports/s", $helpersSrc));
// Faz 7: sidebar artık gunluk_isci_puantaj.php'ye DOĞRUDAN bağlanmıyor —
// tüm personel/PDKS alt sayfaları tek "Personel Takibi" girişi altında
// (personel_takip.php) toplandı (bkz. pdks_takip_static_smoke.php). Bağlantı
// ve izin kapısı artık personel_takip.php'de; helpers.php yalnız konsolide
// $a_ptak listesinde dosya adını taşır.
ok('helpers.php: sidebar\'da (konsolide $a_ptak listesi üzerinden) gunluk_isci_puantaj.php geçiyor', str_contains($helpersSrc, 'gunluk_isci_puantaj.php'));
$ptakSrc3 = oku('personel_takip.php');
ok('personel_takip.php: gunluk_isci_puantaj.php kartı var', str_contains($ptakSrc3, 'gunluk_isci_puantaj.php'));
ok('personel_takip.php: bu kart attendance.daily_reports iznine bağlı',
    (bool)preg_match("/can\('attendance\.daily_reports'\)[\s\S]{0,60}gunluk_isci_puantaj\.php/", $ptakSrc3));

echo "\n=== 3. OPERATOR'A GENİŞ RAPOR İZNİ VERİLMEDİ (görev madde 17, kullanıcının açık talimatı) ===\n";
preg_match("/'operator'\s*=>\s*\[([^\]]*)\]/s", $helpersSrc, $opM);
$operatorIzinleri = $opM[1] ?? '';
ok("'operator' izin listesi çıkarılabildi", $operatorIzinleri !== '');
ok("'operator' rolüne attendance.daily_reports VERİLMEDİ (yalnız admin+ik+muhasebe)",
    !str_contains($operatorIzinleri, 'attendance.daily_reports'));
ok("'operator' rolü attendance.daily_scan'i KORUYOR (taramaya devam edebilmeli)",
    str_contains($operatorIzinleri, 'attendance.daily_scan'));

echo "\n=== 4. NORMAL SAYFA ZİYARETİNDE DDL YOK (görev madde 18 — Faz 1/2 kuralıyla AYNI) ===\n";
foreach ([$sayfaListe => $srcListe, $sayfaDetay => $srcDetay] as $f => $src) {
    ok("$f: pdks_gunluk_migrate() ÇAĞRILMIYOR", !str_contains($src, 'pdks_gunluk_migrate('));
    ok("$f: pdks_gunluk_sayfa_kapisi() ÇAĞRILIYOR (Faz 1/2'nin güvenli-başarısızlık kapısı REUSE edildi)",
        str_contains($src, 'pdks_gunluk_sayfa_kapisi('));
    ok("$f: CREATE TABLE / ALTER TABLE İÇERMİYOR", !preg_match('/\b(CREATE TABLE|ALTER TABLE)\b/i', $src));
}
$migCagrisiOlan = [];
foreach ([$sayfaListe, $sayfaDetay, 'cavuslar.php', 'cavus_form.php', 'isci_kartlari.php', 'isci_tipleri.php',
          'gunluk_isci_giris_cikis.php', 'migrate.php'] as $f) {
    if (str_contains(oku($f), 'pdks_gunluk_migrate(')) $migCagrisiOlan[] = $f;
}
ok('repodaki TEK pdks_gunluk_migrate() çağrı yeri HÂLÂ yalnız migrate.php (Faz 3 ikinci bir yol AÇMADI)',
    $migCagrisiOlan === ['migrate.php'], implode(', ', $migCagrisiOlan));

echo "\n=== 5. PARAMETRİK FİLTRELER (görev madde 9 — arbitrary SQL sort/inject YOK) ===\n";
foreach ([$sayfaListe => $srcListe, $sayfaDetay => $srcDetay] as $f => $src) {
    // ⚠ Satır-satır kontrol EDİLİR — dosya genelinde tek bir regex ile
    // arama, iki tırnak arasında KALAN çok satırlı (ör. bir SQL dizesiyle
    // hiç ilgisi olmayan) metni de "aynı dize" sanıp YANLIŞ POZİTİF üretir
    // (bu depoda daha önce ENUM/ALTER TABLE/COUNT(*) yorumlarında YAŞANMIŞ
    // AYNI hata sınıfı). Asıl risk deseni: $_GET değerinin `.` ile bir SQL
    // dizesine EKLENMESİDİR — bu depoda her yerde `?` yer tutucu + execute()
    // dizisi kullanılır, $_GET SQL dizesine hiç DOKUNMAZ.
    $satirlar = explode("\n", $src);
    $concatVar = null;
    foreach ($satirlar as $i => $satir) {
        if (preg_match('/\.\s*\$_GET\[/', $satir) || preg_match('/\$_GET\[[^\]]+\]\s*\./', $satir)) {
            $concatVar = 'satır ' . ($i + 1) . ': ' . trim($satir);
            break;
        }
    }
    ok("$f: \$_GET bir SQL dizesine `.` (concatenation) ile EKLENMİYOR", $concatVar === null, (string)$concatVar);
    ok("$f: ORDER BY için \$_GET'ten gelen bir değer KULLANILMIYOR (sabit ORDER BY)",
        !preg_match('/ORDER BY[^;]*\$_GET/is', $src));
}
$gunFonksiyonlari = ['pdks_gunluk_gun_ozeti', 'pdks_gunluk_gun_listesi', 'pdks_gunluk_oturum_kartlari', 'pdks_gunluk_eksik_cikislar'];
foreach ($gunFonksiyonlari as $fn) {
    preg_match('/function ' . preg_quote($fn, '/') . '\(.*?\n\}\n/s', $gunlukSrc, $fnM);
    $govde = $fnM[0] ?? '';
    ok("$fn() gövdesi çıkarılabildi", $govde !== '');
    // Her WHERE/AND koşulu ya sabit bir string, ya `?` yer tutucudur —
    // fonksiyon PARAMETRELERİ prepare()/execute() ile ayrı geçilir, hiçbir
    // yerde doğrudan string enjekte edilmez (aşağıdaki iki kontrol birlikte
    // bunu doğrular).
    ok("$fn(): tüm sorgular \$pdo->prepare() ile hazırlanıyor (execute() ile ayrı parametre)",
        substr_count($govde, '->prepare(') > 0 && substr_count($govde, '->prepare(') === substr_count($govde, '->execute('));
    ok("$fn(): fonksiyon PARAMETRELERİ ({$fn} argümanları) SQL dizesine sprintf/concat ile YAZILMIYOR — yalnız \$params/\$par dizisine ekleniyor",
        !preg_match('/"\s*\.\s*\$(workDate|depo|foremanId|sessionId|durumFiltresi)\b/', $govde));
}

echo "\n=== 6. HAKEDİŞ/FİYAT/ÖDEME YOK (görev talimatı — 'Do NOT implement hakediş, pricing, payments...') ===\n";
$yasakliKelimeler = ['price', 'fiyat', 'ucret', 'ücret', 'rate', 'hakedis', 'hakediş', 'payment', 'ödeme', 'odeme',
                      'invoice', 'fatura', 'cari_hesap', 'current_account', 'wage', 'salary', 'maas', 'maaş'];
$yeniKodListe  = kodSadece($srcListe);
$yeniKodDetay  = kodSadece($srcDetay);
foreach ($yasakliKelimeler as $kelime) {
    $desen = '/\b' . preg_quote($kelime, '/') . '\b/iu';
    ok("gunluk_isci_puantaj.php GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $yeniKodListe));
    // Faz 8H: yalnız admin yeniden açma engelinde kesin hakediş durumu okunur;
    // fiyat, ödeme veya hakediş hesabı bu sayfada hâlâ yapılmaz.
    if (!in_array($kelime, ['hakedis', 'hakediş'], true)) {
        ok("gunluk_isci_puantaj_detay.php GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $yeniKodDetay));
    }
}
ok('detay yalnız kesin hakediş engelini okur; hakediş hesabı veya yeniden açması yapmaz',
    str_contains($srcDetay, "status = 'final'") && !str_contains($srcDetay, 'pdks_hakedis_yeniden_ac(')
    && !str_contains($srcDetay, 'pdks_hakedis_hesapla('));

echo "\n=== 7. SALT OKUNUR — DÜZENLEME/SİLME/MANUEL ÇIKIŞ YOK (görev madde 11) ===\n";
foreach ([$sayfaListe => $srcListe, $sayfaDetay => $srcDetay] as $f => $src) {
    ok("$f: INSERT/UPDATE/DELETE SQL'i YOK (salt okunur)",
        !preg_match('/\b(INSERT INTO|UPDATE\s+`?\w+`?\s+SET|DELETE FROM)\b/i', $src));
    ok("$f: pdks_gunluk_oturum_kaydet()/pdks_gunluk_oturum_kapat() ÇAĞRILMIYOR (yazma yollarına dokunmuyor)",
        !str_contains($src, 'pdks_gunluk_oturum_kaydet(') && !str_contains($src, 'pdks_gunluk_oturum_kapat('));
}
ok('Faz 3 fonksiyonlarının hiçbirinde INSERT/UPDATE/DELETE YOK (config/pdks_gunluk.php)', (function () use ($gunFonksiyonlari, $gunlukSrc) {
    foreach ($gunFonksiyonlari as $fn) {
        preg_match('/function ' . preg_quote($fn, '/') . '\(.*?\n\}\n/s', $gunlukSrc, $m);
        if (preg_match('/\b(INSERT INTO|UPDATE\s+`?\w+`?\s+SET|DELETE FROM)\b/i', $m[0] ?? '')) return false;
    }
    return true;
})());

echo "\n=== 8. SNAPSHOT KULLANIMI — CANLI worker_types/foremen JOIN'İ DEĞİL (görev madde 4/14) ===\n";
ok('pdks_gunluk_oturum_kartlari() worker_type_name_snapshot okuyor (canlı worker_types join\'i DEĞİL)',
    (bool)preg_match('/function pdks_gunluk_oturum_kartlari.*?worker_type_name_snapshot/s', $gunlukSrc));
ok('pdks_gunluk_gun_ozeti() worker_type_name_snapshot okuyor', (bool)preg_match('/function pdks_gunluk_gun_ozeti.*?worker_type_name_snapshot/s', $gunlukSrc));
ok('pdks_gunluk_gun_listesi() foreman_name_snapshot okuyor (foremen.name CANLI join\'i DEĞİL)',
    (bool)preg_match('/function pdks_gunluk_gun_listesi.*?foreman_name_snapshot/s', $gunlukSrc));
ok('daily_work_sessions DDL\'inde foreman_name_snapshot/foreman_code_snapshot VAR',
    str_contains($gunlukSrc, '`foreman_name_snapshot`') && str_contains($gunlukSrc, '`foreman_code_snapshot`'));
ok('pdks_gunluk_oturum_ac_veya_getir() bu snapshot alanlarını oturum AÇILIRKEN dolduruyor',
    (bool)preg_match('/function pdks_gunluk_oturum_ac_veya_getir.*?foreman_name_snapshot/s', $gunlukSrc));

echo "\n=== 9. N+1 ÖNLEME (görev madde 13) — gün listesi TEK toplu sorgu setiyle çalışıyor ===\n";
preg_match('/function pdks_gunluk_gun_listesi.*?\n\}\n/s', $gunlukSrc, $listM);
$listGovde = $listM[0] ?? '';
ok('pdks_gunluk_gun_listesi() gövdesi çıkarılabildi', $listGovde !== '');
ok('gün listesi HER session için pdks_gunluk_oturum_ozet() ÇAĞIRMIYOR (N+1 riski YOK)',
    !str_contains($listGovde, 'pdks_gunluk_oturum_ozet('));
ok('session_id IN (...) ile TOPLU sorgu kullanılıyor (en az 3 kez: olay/zaman/eksik)',
    substr_count($listGovde, 'session_id IN (') >= 3);

echo "\n=== 10. İNDEKS — Faz 3'ün yeni sorgu deseni için EKLENEN indeks ===\n";
preg_match('/\$t\[\'daily_worker_card_events\'\]\s*=\s*"(.*?)";/s', $gunlukSrc, $eventDdlM);
$eventDdl = $eventDdlM[1] ?? '';
ok('daily_worker_card_events DDL gövdesi çıkarılabildi', $eventDdl !== '');
ok('idx_dwce_workdate_depo_type (work_date_snapshot, depo_snapshot, event_type) EKLENDİ',
    (bool)preg_match('/INDEX `idx_dwce_workdate_depo_type` \(`work_date_snapshot`, `depo_snapshot`, `event_type`\)/', $eventDdl));
// ⚠ Faz 8A (bkz. scripts/pdks_gunluk_faz8a_static_smoke.php) bu kısıtı
// BİLEREK KALDIRDI — "bir işçi kartı = bir işçi/iş günü" kuralı Faz 8A'da
// GEÇERSİZDİR. Bu satır artık "kısıt hâlâ BURADA" yerine "kaldırma
// pdks_gunluk_faz8a_migrate()'in KENDİ kontrollü ALTER'ından geçiyor, base
// DDL'de artık YOK" doğrular — Faz 3'ün kendi testi bu supersede'i
// GÖRMEZDEN GELMEZ.
ok('uq_dwce_card_day_depo_type Faz 8A tarafından base DDL\'den kaldırıldı (bkz. pdks_gunluk_faz8a_migrate)',
    !str_contains($eventDdl, 'uq_dwce_card_day_depo_type'));

echo "\n=== 11. giris_cikis.php / pdks_nfc_test.php / assets/pdks.js Faz 3'TE HİÇ DEĞİŞMEDİ ===\n";
// ⚠ Faz 8A (bkz. scripts/pdks_gunluk_faz8a_static_smoke.php) gunluk_isci_giris_cikis.php'yi
// BİLİNÇLİ OLARAK değiştirdi (İşçi Tipi/Tam-Yarım seçimi + Faz 8A kaydı) —
// bu dosya listeden ÇIKARILDI, kalıcı personel NFC dosyaları AYNEN kalır.
$gcDiff = trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff --stat -- giris_cikis.php pdks_nfc_test.php assets/pdks.js 2>&1'));
ok('bu üç dosyanın diff\'i BOŞ (görev talimatı: "Do NOT touch the proven NFC implementation")', $gcDiff === '', $gcDiff);

echo "\n=== 12. SIDEBAR AKTİF-SAYFA TESPİTİ GÜNCELLENDİ ===\n";
// Faz 7: $a_puantaj (ve diğer 11 tekil aktif-sayfa değişkeni) sidebar
// konsolidasyonuyla KASITLI olarak silindi — hepsi tek $a_ptak dizisinde
// birleşti (bkz. pdks_takip_static_smoke.php / pdks_faz1b_static_smoke.php'nin
// izin verilen silinen satır listesi). gunluk_isci_puantaj.php dosya adı hâlâ
// $a_ptak içinde aktif-sayfa tespiti için kullanılıyor.
ok('helpers.php: $a_puantaj yerine konsolide $a_ptak değişkeni tanımlı', str_contains($helpersSrc, '$a_ptak'));
// ⚠ Sprint Navigasyon-02: liste sidebar'ın içinden nav_ptak_sayfalari()
// fonksiyonuna taşındı (mobil bottomnav da AYNI kaynağı okusun diye) —
// $a_ptak artık satır içi dizi değil, o fonksiyonu çağırıyor.
ok('helpers.php: $a_ptak dizisi gunluk_isci_puantaj.php\'yi içeriyor (aktif-sayfa tespiti korunuyor)',
    (bool)preg_match('/\$a_ptak = in_array\(\$cur, nav_ptak_sayfalari\(\), true\);/', $helpersSrc)
    && (function () use ($helpersSrc) {
        if (!preg_match('/function nav_ptak_sayfalari\(\): array\s*\{(.*?)\}/s', $helpersSrc, $m)) return false;
        return str_contains($m[1], "'gunluk_isci_puantaj.php'");
    })());
// ⚠ Sprint Navigasyon-02: $p_gunluk artık nav_ptak_gorunur()'u çağırıyor —
// izin listesi (attendance.daily_reports dahil) o fonksiyonun içinde.
ok("helpers.php: \$p_gunluk artık attendance.daily_reports'u da kapsıyor (aksi hâlde yalnız-rapor rolü — muhasebe — sidebar bölümünü hiç GÖRMEZ)",
    (bool)preg_match('/\$p_gunluk\s*=\s*nav_ptak_gorunur\(\);/', $helpersSrc)
    && (function () use ($helpersSrc) {
        if (!preg_match('/function nav_ptak_gorunur\(\): bool\s*\{(.*?)^\}/ms', $helpersSrc, $m)) return false;
        return str_contains($m[1], 'attendance.daily_reports');
    })());

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
