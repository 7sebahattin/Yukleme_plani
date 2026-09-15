<?php
// =========================================================
// scripts/pdks_gunluk_faz2_static_smoke.php — Günlük İşçi Faz 2 kaynak-kodu kuralları
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar
// (pdks_gunluk_static_smoke.php ile aynı desen).
//
// Kapsam: görev listesinin geri kalanı (19 no-auto-DDL, 20 izinler) +
// NFC/USB REUSE + "hakediş/ödeme YOK" + kalıcı personel giris_cikis.php'nin
// HİÇ DEĞİŞMEDİĞİ + snapshot alanlarının şemada VAR olduğu.
//
//   php scripts/pdks_gunluk_faz2_static_smoke.php   → çıkış kodu 0 = geçti
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
    printf("%-82s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $p): string { global $KOK; return (string)@file_get_contents($KOK . '/' . $p); }
function kodSadece(string $s): string { return preg_replace('/^\s*\/\/.*$/m', '', $s); }

$sayfa = 'gunluk_isci_giris_cikis.php';

echo "\n=== 1. SÖZ DİZİMİ ===\n";
$cikti = []; $rc = 0;
exec('php -l ' . escapeshellarg($KOK . '/' . $sayfa) . ' 2>&1', $cikti, $rc);
ok('gunluk_isci_giris_cikis.php: php -l geçiyor', $rc === 0, implode("\n", $cikti));

$src = oku($sayfa);
$kod = kodSadece($src);
$gunlukSrc = oku('config/pdks_gunluk.php');
$gunlukKod = kodSadece($gunlukSrc);

echo "\n=== 2. YETKİ KAPISI (görev madde 20) ===\n";
ok('require_login() çağırıyor', str_contains($src, 'require_login()'));
ok("require_pdks_gunluk('daily_scan') sayfa girişinde", (bool)preg_match('/require_pdks_gunluk\(\s*[\'"]daily_scan[\'"]\s*\)/', $src));
ok("require_pdks_gunluk('daily_scan') HER ÜÇ ajax dalında da TEKRAR var (savunma derinliği)",
    substr_count($src, "require_pdks_gunluk('daily_scan')") >= 4);   // 1 sayfa girişi + 3 ajax dalı
ok("csrf_check() HER ÜÇ ajax dalında da var", substr_count($src, 'csrf_check(') >= 3);
ok("pdks_gunluk_can() match ifadesine 'daily_scan' eklendi", (bool)preg_match("/'daily_scan'\s*=>\s*can\('attendance\.daily_scan'\)/", $gunlukSrc));

$helpersSrc = oku('config/helpers.php');
ok("helpers.php: attendance.daily_scan izni seed'e eklendi", str_contains($helpersSrc, "'attendance.daily_scan'"));
ok("helpers.php: 'ik' rolüne attendance.daily_scan verildi",
    (bool)preg_match("/'ik'\s*=>\s*\[[^\]]*attendance\.daily_scan/s", $helpersSrc));
ok('helpers.php: sidebar\'da gunluk_isci_giris_cikis.php bağlantısı var', str_contains($helpersSrc, 'gunluk_isci_giris_cikis.php'));
ok('helpers.php: sidebar bağlantısı attendance.daily_scan iznine bağlı',
    (bool)preg_match("/can\('attendance\.daily_scan'\)[\s\S]{0,60}gunluk_isci_giris_cikis\.php/", $helpersSrc));
ok('Yalnız TEK yeni izin eklendi (attendance.daily_scan) — hakediş/ödeme izni YOK',
    !preg_match('/attendance\.(pay|payment|hakedis|price|rate)/i', $helpersSrc));

echo "\n=== 2b. GÜVENLİK/OPERASYON ROLÜ DÜZELTMESİ (kullanıcının açık talimatı #2, Faz 2 düzeltme turu) ===\n";
// Repoda ayrı bir 'guvenlik'/'security' rolü YOK — kullanıcı "yoksa sessizce
// icat etme" dediği için YENİ bir rol AÇILMADI; taramayı sahada asıl yapacak
// GÜVENLİK personeli en yakın MEVCUT operasyonel role ('operator') tek bir
// izinle eklendi.
ok("helpers.php: 'operator' rolüne attendance.daily_scan verildi (sahadaki güvenlik/operasyon taramayı yapabilsin)",
    (bool)preg_match("/'operator'\s*=>\s*\[[^\]]*attendance\.daily_scan/s", $helpersSrc));
preg_match("/'operator'\s*=>\s*\[([^\]]*)\]/s", $helpersSrc, $opM);
$operatorIzinleri = $opM[1] ?? '';
ok("'operator' izin listesi çıkarılabildi", $operatorIzinleri !== '');
ok("'operator' rolüne çavuş YÖNETİMİ verilMEDİ (attendance.foremen YOK)", !str_contains($operatorIzinleri, 'attendance.foremen'));
ok("'operator' rolüne işçi kartı YÖNETİMİ verilMEDİ (attendance.worker_cards YOK)", !str_contains($operatorIzinleri, 'attendance.worker_cards'));
ok("'operator' rolüne muhasebe/admin izni verilMEDİ (users.admin YOK)", !str_contains($operatorIzinleri, 'users.admin'));
ok("'operator' rolüne hesap.approve/pay/delete/admin verilMEDİ (yalnız minimum tarama izni eklendi)",
    !preg_match('/hesap\.(approve|pay|delete|admin)/', $operatorIzinleri));
ok('bu düzeltmenin gerekçesi ("guvenlik"/"security" rolü yokluğu ve neden operator seçildiği) yorumla belgelendi',
    (bool)preg_match('/g[uü]venlik/iu', $helpersSrc) && str_contains($helpersSrc, "'operator'"));

echo "\n=== 3. NORMAL SAYFA ZİYARETİNDE DDL YOK (görev madde 19 — Faz 1 kuralıyla AYNI) ===\n";
ok('gunluk_isci_giris_cikis.php: pdks_gunluk_migrate() ÇAĞRILMIYOR', !str_contains($src, 'pdks_gunluk_migrate('));
ok('gunluk_isci_giris_cikis.php: pdks_gunluk_sayfa_kapisi() ÇAĞRILIYOR (Faz 1\'in güvenli-başarısızlık kapısı REUSE edildi)',
    str_contains($src, 'pdks_gunluk_sayfa_kapisi('));
ok('sayfa kapısı $pdo tanımlandıktan HEMEN SONRA, herhangi bir ajax dalından ÖNCE çağrılıyor',
    (bool)preg_match('/\$pdo\s*=\s*db\(\);\s*\n\s*pdks_gunluk_sayfa_kapisi\(\$pdo\);/', $src));
ok('yeni Faz 2 tabloları (daily_work_sessions/daily_worker_card_events) pdks_gunluk_tablolar() dizisine EKLENDİ — pdks_gunluk_sema_hazir() otomatik kapsıyor',
    str_contains($gunlukSrc, "\$t['daily_work_sessions']") && str_contains($gunlukSrc, "\$t['daily_worker_card_events']"));
ok('CREATE TABLE IF NOT EXISTS ile tanımlı (yıkıcı DEĞİL)',
    (bool)preg_match('/CREATE TABLE IF NOT EXISTS\s+`daily_work_sessions`/', $gunlukSrc)
    && (bool)preg_match('/CREATE TABLE IF NOT EXISTS\s+`daily_worker_card_events`/', $gunlukSrc));
$migCagrisiOlan = [];
foreach ([$sayfa, 'cavuslar.php', 'cavus_form.php', 'isci_kartlari.php', 'isci_tipleri.php', 'migrate.php'] as $f) {
    if (str_contains(oku($f), 'pdks_gunluk_migrate(')) $migCagrisiOlan[] = $f;
}
ok('repodaki TEK pdks_gunluk_migrate() çağrı yeri HÂLÂ yalnız migrate.php (Faz 2 ikinci bir yol AÇMADI)',
    $migCagrisiOlan === ['migrate.php'], implode(', ', $migCagrisiOlan));

echo "\n=== 4. NFC/USB — YENİDEN İCAT EDİLMEDİ, PAYLAŞILAN OKUMA YOLU REUSE EDİLDİ ===\n";
ok('gunluk_isci_giris_cikis.php KENDİ `new NDEFReader()`ını AÇMIYOR', !str_contains($kod, 'new NDEFReader()'));
ok('gunluk_isci_giris_cikis.php KENDİ AbortController AÇMIYOR (önceki kırık yaşam döngüsü GERİ GETİRİLMEDİ)',
    !str_contains($kod, 'new AbortController()') && !preg_match('/\.scan\(\s*\{/', $kod));
ok('paylaşılan okuma yolu sayfaya basılıyor (pdks_nfc_oku_js — config/pdks.php)', str_contains($src, 'pdks_nfc_oku_js()'));
ok('okuma PdksNfcOku.baslat() ile başlatılıyor (giris_cikis.php/pdks_nfc_test.php İLE AYNI)', str_contains($kod, 'PdksNfcOku.baslat('));
ok('destek kontrolü PdksNfcOku.destekli() üzerinden', str_contains($kod, 'PdksNfcOku.destekli()'));
ok('baslat() BUTON TIKLAMASININ İÇİNDE çağrılıyor (transient activation)',
    (bool)preg_match("/nfcBtn\.addEventListener\('click'[\s\S]{0,700}PdksNfcOku\.baslat\(/", $kod));
ok('okuma sonrası abort() YOK — oturum AÇIK kalıyor (teşhis sayfasının kanıtlanmış davranışı)',
    !str_contains($kod, '.abort()'));
ok('USB HID girişi inputmode="none" (mobil yazılım klavyesini davet etmiyor)',
    (bool)preg_match('/id="giScanInput"[\s\S]{0,200}inputmode="none"/', $src));
ok('USB girişi kaynak=usb_decimal ile gönderiliyor', str_contains($kod, "'usb_decimal'"));
ok('NFC girişi kaynak=web_nfc ile gönderiliyor', str_contains($kod, "'web_nfc'"));

echo "\n=== 5. KALICI PERSONEL giris_cikis.php BU GÖREVDE HİÇ DEĞİŞMEDİ ===\n";
$gcDiff = trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff --stat -- giris_cikis.php pdks_nfc_test.php assets/pdks.js 2>&1'));
ok('giris_cikis.php / pdks_nfc_test.php / assets/pdks.js diff BOŞ (Faz 2 dokunmadı)', $gcDiff === '', $gcDiff);
ok('gunluk_isci_giris_cikis.php ayrı bir dosya — giris_cikis.php REUSE/include EDİLMİYOR (kullanıcının açık talimatı)',
    !preg_match('/require(_once)?\s*.*giris_cikis\.php/', $src) && !preg_match('/include(_once)?\s*.*giris_cikis\.php/', $src));

echo "\n=== 6. SNAPSHOT ALANLARI ŞEMADA VAR (görev madde 11) ===\n";
preg_match('/\$t\[\'daily_worker_card_events\'\]\s*=\s*"(.*?)";/s', $gunlukSrc, $eventDdlM);
$eventDdl = $eventDdlM[1] ?? '';
ok('daily_worker_card_events DDL gövdesi çıkarılabildi', $eventDdl !== '');
ok('worker_type_id_snapshot sütunu var', str_contains($eventDdl, '`worker_type_id_snapshot`'));
ok('worker_type_name_snapshot sütunu var', str_contains($eventDdl, '`worker_type_name_snapshot`'));
ok('canonical_uid_snapshot sütunu var', str_contains($eventDdl, '`canonical_uid_snapshot`'));
ok('worker_type_id_snapshot\'a FK KONULMADI (bilinçli — tarihi referans, canlı bütünlüğe bağımlı değil)',
    !preg_match('/FOREIGN KEY \(`worker_type_id_snapshot`\)/', $eventDdl));
ok('pdks_gunluk_oturum_ozet() sayaçları worker_type_name_snapshot\'tan okuyor (CANLI worker_types join\'inden DEĞİL)',
    (bool)preg_match('/function pdks_gunluk_oturum_ozet.*?worker_type_name_snapshot/s', $gunlukSrc));

echo "\n=== 7. HİÇBİR MUHASEBE/HAKEDİŞ ALANI YOK (kullanıcının açık talimatı) ===\n";
// ⚠ Sözcük SINIRI (\b) İLE aranır — "rate" gibi kısa kökler aksi hâlde
// "migrate"/"pdks_gunluk_migrate()" içinde YANLIŞ POZİTİF üretir.
$yasakliKelimeler = ['price', 'fiyat', 'ucret', 'ücret', 'rate', 'hakedis', 'hakediş', 'payment', 'ödeme', 'odeme',
                      'invoice', 'fatura', 'cari_hesap', 'current_account', 'wage', 'salary', 'maas', 'maaş'];
foreach ($yasakliKelimeler as $kelime) {
    $desen = '/\b' . preg_quote($kelime, '/') . '\b/iu';
    ok("config/pdks_gunluk.php GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $gunlukKod));
    ok("gunluk_isci_giris_cikis.php GERÇEK KODUNDA '$kelime' YOK", !preg_match($desen, $kod));
}

echo "\n=== 8. EVENT GEÇMİŞİ DEĞİŞTİRİLEMEZ (kullanıcının açık talimatı) ===\n";
ok('config/pdks_gunluk.php: daily_worker_card_events için UPDATE YOK', !preg_match('/UPDATE\s+`?daily_worker_card_events`?\s+SET/i', $gunlukKod));
ok('config/pdks_gunluk.php: daily_worker_card_events için DELETE YOK', !preg_match('/DELETE\s+FROM\s+`?daily_worker_card_events`?/i', $gunlukKod));
ok('pdks_gunluk_oturum_kapat() eksik kartlar için UYDURMA bir ÇIKIŞ INSERT\'i YAPMIYOR (yalnız status günceller)',
    (bool)preg_match('/function pdks_gunluk_oturum_kapat.*?\n\}/s', $gunlukSrc, $kapatM)
    && !preg_match('/INSERT INTO daily_worker_card_events/', $kapatM[0]));

echo "\n=== 9. MESAİ KİMLİĞİ SUNUCU TARAFINDA ÇÖZÜLÜR — depo/tarih İSTEMCİDEN ALINMAZ ===\n";
ok('pdks_gunluk_oturum_ac_veya_getir() work_date parametresi ALMIYOR (imzada work_date/tarih YOK)',
    (bool)preg_match('/function pdks_gunluk_oturum_ac_veya_getir\(int \$foremanId, int \$userId, \?PDO \$pdo = null\)/', $gunlukSrc));
ok('work_date SUNUCUDA date(\'Y-m-d\') ile hesaplanıyor', str_contains($gunlukSrc, "\$tarih = date('Y-m-d')"));
ok('depo active_depot() üzerinden SUNUCUDA çözülüyor (istemci depo GÖNDERMİYOR)',
    substr_count($gunlukSrc, "function_exists('active_depot') ? (active_depot() ?? '') : ''") >= 2);
ok('ajax=oturum ucu istemciden yalnız foreman_id + mode alıyor (session/depo/tarih İSTEMCİDEN GELMİYOR)',
    (bool)preg_match('/foreman_id.*mode/s', $src) && !preg_match("/govde\['depo'\]|govde\['work_date'\]|govde\['tarih'\]/", $src));

echo "\n=== 10. AKTİF/AÇIK OTURUM TEKİLLİĞİ VERİTABANI SEVİYESİNDE DE GARANTİ ===\n";
preg_match('/\$t\[\'daily_work_sessions\'\]\s*=\s*"(.*?)";/s', $gunlukSrc, $sessDdlM);
$sessDdl = $sessDdlM[1] ?? '';
ok('daily_work_sessions DDL gövdesi çıkarılabildi', $sessDdl !== '');
ok('UNIQUE(foreman_id, work_date, depo) kısıtı var', (bool)preg_match('/UNIQUE KEY `uq_dws_foreman_date_depo` \(`foreman_id`, `work_date`, `depo`\)/', $sessDdl));

echo "\n=== 11. GÜNLÜK KART TEKİLLİĞİ — BİR İŞÇİ KARTI = BİR İŞÇİ / İŞ GÜNÜ (kullanıcının açık düzeltmesi #1) ===\n";
// worker_cards.status HÂLÂ yalnız available/lost/disabled olmalı — "kullanımda"
// bilgisi (ne SEANS içi ne GÜNLÜK) asla kalıcı bir kart durumu olarak
// eklenmedi; bugünkü kullanım daima olay satırlarından TÜRETİLİR.
preg_match('/function pdks_gunluk_kart_durumlari\(\): array\s*\{(.*?)\n\}/s', $gunlukSrc, $durumM);
$kartDurumGovde = $durumM[1] ?? '';
ok('pdks_gunluk_kart_durumlari() gövdesi çıkarılabildi', $kartDurumGovde !== '');
ok("pdks_gunluk_kart_durumlari() DÖNÜŞ DEĞERİNDE 'in_use' anahtarı YOK (Faz 1 kararının Faz 2'de de korunduğu — yalnız açıklayıcı yorumlarda geçebilir)",
    !preg_match("/'in_use'/", $kartDurumGovde));
ok("pdks_gunluk_kart_durumlari() DÖNÜŞ DEĞERİNDE used_today/gun_kullanildi gibi KALICI bir \"bugün kullanıldı\" bayrağı YOK (türetilir, saklanmaz)",
    !preg_match('/used_today|gun_kullanildi|kullanildi_bugun/i', $kartDurumGovde));
ok('daily_worker_card_events DDL gövdesi çıkarılabildi (bkz. bölüm 6)', $eventDdl !== '');
ok('work_date_snapshot sütunu eklendi (gün bazlı kısıt için kart olayına DAMGALANIR)', str_contains($eventDdl, '`work_date_snapshot`'));
ok('depo_snapshot sütunu eklendi (gün+depo bazlı kısıt için)', str_contains($eventDdl, '`depo_snapshot`'));
ok('UNIQUE(worker_card_id, work_date_snapshot, depo_snapshot, event_type) kısıtı VAR — "1 kart = 1 işçi/iş günü" kuralının DB SEVİYESİNDE garantisi',
    (bool)preg_match('/UNIQUE KEY `uq_dwce_card_day_depo_type` \(`worker_card_id`, `work_date_snapshot`, `depo_snapshot`, `event_type`\)/', $eventDdl));
ok('pdks_gunluk_oturum_kaydet() GİRİŞ dalında pdks_gunluk_kart_gun_kullanimi() ile ÖN-KONTROL yapıyor (dostça hata mesajı için, DB kısıtından ÖNCE)',
    (bool)preg_match('/function pdks_gunluk_oturum_kaydet.*?pdks_gunluk_kart_gun_kullanimi\(/s', $gunlukSrc));
ok("hata kodu 'bugun_kullanilmis' tanımlı (aynı gün/aynı veya başka çavuş fark etmeksizin reddin ortak kodu)",
    str_contains($gunlukSrc, "'bugun_kullanilmis'"));
ok('INSERT try/catch İLE sarılı — eşzamanlı taramada UNIQUE kısıt ihlali de aynı dostça koda ÇEVRİLİYOR (yarış koşulu son çaresi)',
    (bool)preg_match('/try\s*\{\s*\$ins->execute\(\[[\s\S]{0,400}?\}\s*catch\s*\(PDOException/', $gunlukSrc));

echo "\n=== 12. SAYAÇ/RAPOR KURALI: BENZERSİZ KART, HAM SATIR SAYISI DEĞİL (kullanıcının açık talimatı) ===\n";
preg_match('/function pdks_gunluk_oturum_ozet.*?\n\}/s', $gunlukSrc, $ozetM);
$ozetGovde = kodSadece($ozetM[0] ?? '');
ok('pdks_gunluk_oturum_ozet() gövdesi çıkarılabildi', $ozetGovde !== '');
ok('GİRİŞ sorgusu COUNT(DISTINCT worker_card_id) + event_type=GIRIS birlikte geçiyor',
    (bool)preg_match("/COUNT\(DISTINCT worker_card_id\)[\s\S]{0,120}event_type = 'GIRIS'/", $ozetGovde));
ok('ÇIKIŞ sorgusu COUNT(DISTINCT worker_card_id) + event_type=CIKIS birlikte geçiyor',
    (bool)preg_match("/COUNT\(DISTINCT worker_card_id\)[\s\S]{0,120}event_type = 'CIKIS'/", $ozetGovde));
ok('COUNT(DISTINCT worker_card_id) toplamda EN AZ 2 kez kullanılıyor (GİRİŞ+ÇIKIŞ)',
    substr_count($ozetGovde, 'COUNT(DISTINCT worker_card_id)') >= 2);
ok('sayaç mantığında ham COUNT(*) YOK (yalnız benzersiz kart sayımı)', !preg_match('/COUNT\(\*\)/', $ozetGovde));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
