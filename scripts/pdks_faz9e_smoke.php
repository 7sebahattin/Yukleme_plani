<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_faz9e_smoke.php — Faz 9E (v232) odaklı doğrulama.
//
// Faz 9E, UI/operasyonel netlik hardening'idir (görev talimatı: "DO NOT
// redesign the whole application. DO NOT change financial or attendance
// business rules."). Bu dosya ALTI maddeyi hedefler:
//   A. Sidebar aktif-durum tutarlılığı
//   B. Gün kapanışı kontrol listesi (yalnız bilgilendirme, yeni engel YOK)
//   C. Aksiyona dönüştürülebilir kalıcı-kart çakışma mesajı
//   D. Bayat taslak (needs_recalculation) netliği
//   E. Bağlamsal denetim/geçmiş görünürlüğü (audit_log, uydurma YOK)
//   F. Eksik-çıkış operasyonel netliği (Manuel Çıkış Gir / açıklayıcı etiket)
//
// Ağsız, canlı DB'ye dokunmaz — statik kaynak-kodu regex kontrolleri +
// bellek içi SQLite ile gerçek fonksiyon çağrıları (davranışsal).
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok9e(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}
function oku9e(string $p): string { global $root; return (string)@file_get_contents($root . '/' . $p); }

// =========================================================
// § 0 — SÖZ DİZİMİ: bu fazda değişen HER dosya
// =========================================================
echo "=== 0. SÖZ DİZİMİ ===\n";
foreach ([
    'config/helpers.php', 'config/pdks_gunluk.php', 'config/pdks_hakedis.php',
    'gunluk_isci_giris_cikis.php', 'gunluk_isci_puantaj.php', 'gunluk_isci_puantaj_detay.php',
    'cavus_hakedis.php', 'cavus_hakedis_detay.php', 'sw.js',
] as $f) {
    if (str_ends_with($f, '.js')) { ok9e("$f: dosya var", file_exists("$root/$f")); continue; }
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $cikti, $rc);
    ok9e("$f: php -l geçiyor", $rc === 0, implode("\n", $cikti));
}

// =========================================================
// § A — Sidebar aktif-durum tutarlılığı
// =========================================================
echo "\n=== A. Sidebar aktif-durum (madde A) ===\n";
$helpersSrc = oku9e('config/helpers.php');
ok9e('helpers.php: \$a_ptak TEK konsolide dizi olarak tanımlı (yeni sidebar bölümü İCAT EDİLMEDİ)',
    (bool)preg_match('/\$a_ptak = in_array\(\$cur, \[/', $helpersSrc));
foreach (['mesai_degerlendirme.php', 'manuel_cikis.php', 'gunluk_isci_puantaj_detay.php',
          'cavus_hakedis_detay.php', 'cavus_ekstre.php', 'isci_kartlari.php'] as $sayfa) {
    ok9e("helpers.php: \$a_ptak listesi '$sayfa' İÇERİYOR",
        (bool)preg_match('/\$a_ptak = in_array\(\$cur, \[.*?\'' . preg_quote($sayfa, '/') . '\'.*?\], true\);/s', $helpersSrc));
}
// Yazdırma sayfaları BİLİNÇLİ olarak listede YOK — chrome basmıyorlar.
foreach (['gunluk_puantaj_yazdir.php', 'cavus_hakedis_yazdir.php'] as $yazdirma) {
    $src = oku9e($yazdirma);
    ok9e("$yazdirma: render_header()/render_footer() ÇAĞIRMIYOR (aktif-durum kavramı geçersiz)",
        !str_contains($src, 'render_header(') && !str_contains($src, 'render_footer('));
}
ok9e('personel_takip.php (FAZ 8I 10-kart iniş yapısı) BU FAZDA DEĞİŞMEDİ (dosya mevcut, home-card sayısı hâlâ 10)',
    substr_count(oku9e('personel_takip.php'), 'class="home-card"') === 10);

// =========================================================
// § B — Gün kapanışı kontrol listesi
// =========================================================
echo "\n=== B. Gün kapanışı kontrol listesi (madde B) ===\n";
$giSrc = oku9e('gunluk_isci_giris_cikis.php');
ok9e('gunluk_isci_giris_cikis.php: yeni ?ajax=kapanis_kontrol ucu var',
    str_contains($giSrc, "'kapanis_kontrol'"));
foreach (['gikkAcikDonem', 'gikkBekleyenSinif', 'gikkBekleyenFm', 'gikkHakedis'] as $id) {
    ok9e("gunluk_isci_giris_cikis.php: #$id alanı HTML'de var", str_contains($giSrc, "id=\"$id\""));
}
ok9e('gunluk_isci_giris_cikis.php: giKapatBtn tıklanınca kapanis_kontrol ÇAĞRILIYOR (onay ekranı açılmadan önce)',
    (bool)preg_match('/kapanisKontroluGoster\([^)]*\);\s*\n\s*ekranGoster\(closeConfirmSec\);/', $giSrc));
ok9e('gunluk_isci_giris_cikis.php: config/pdks_faz8b.php + config/pdks_hakedis.php EKLENDİ (kapanış kontrolü İÇİN)',
    str_contains($giSrc, "require_once __DIR__ . '/config/pdks_faz8b.php';")
    && str_contains($giSrc, "require_once __DIR__ . '/config/pdks_hakedis.php';"));
// Yalnız BİLGİLENDİRME — endpoint'in TEK engel yolu oturum/depo hatasıdır,
// bekleyen_sinif/bekleyen_fazla_mesai/hakedis_durum HİÇBİR ok=>false DÖNMEZ.
if (preg_match('/ajax.*kapanis_kontrol.*?\n(.*?)\/\/ ── GET render/s', $giSrc, $mm)) {
    $blok = $mm[1];
    $okFalseSayisi = substr_count($blok, "'ok' => false");
    ok9e('kapanis_kontrol ucu: YALNIZ oturum_yok(x2)/yanlis_depo İÇİN ok=>false döner (yeni engel kuralı YOK)',
        $okFalseSayisi === 3, "bulunan: $okFalseSayisi");
    ok9e('kapanis_kontrol ucu: bekleyen_sinif/bekleyen_fazla_mesai/hakedis_durum bloğu ok=>false İÇERMİYOR',
        !preg_match('/bekleyen_sinif.{0,80}ok.{0,5}false|hakedis_durum.{0,80}ok.{0,5}false/s', $blok));
} else {
    ok9e('kapanis_kontrol bloğu regex ile bulunabildi', false);
}
ok9e('gunluk_isci_giris_cikis.php: gerçek kapatma ?ajax=kapat AYNEN korunuyor (mutabakat kuralı değişmedi)',
    str_contains($giSrc, "'eksik_cikis_var'") && str_contains($giSrc, "ajax=kapat"));

// =========================================================
// § C — Aksiyona dönüştürülebilir kalıcı-kart çakışma mesajı
// =========================================================
echo "\n=== C. Kalıcı-kart çakışma mesajı (madde C) ===\n";
$gunlukSrc = oku9e('config/pdks_gunluk.php');
ok9e('config/pdks_gunluk.php: kart-oluşturma çakışma mesajı artık AKSİYON söylüyor (pasife al/iptal et)',
    (bool)preg_match("/uid_kalici_kartta.*?pasife alın\\/iptal edin/s", $gunlukSrc));
ok9e('config/pdks_gunluk.php: GİRİŞ taraması çakışma mesajı (kalici_kart) da AYNI aksiyonu söylüyor',
    (bool)preg_match("/kod' => 'kalici_kart'.*?pasife alın\\/iptal edin/s", $gunlukSrc));
ok9e('FAZ9A kuralı DEĞİŞMEDİ — engel hâlâ yalnız pdks_gunluk_uid_kalici_kartta_mi()/pdks_gunluk_kalici_kart_engeli() üzerinden',
    (bool)preg_match('/function pdks_gunluk_uid_kalici_kartta_mi/', $gunlukSrc)
    && (bool)preg_match('/function pdks_gunluk_kalici_kart_engeli/', $gunlukSrc));
ok9e('Hiçbir kart OTOMATİK pasife alınmıyor/silinmiyor (yalnız metin değişti — UPDATE/DELETE employee_cards YOK bu bloklarda)',
    !preg_match("/pasife alın\\/iptal edin.{0,400}(UPDATE\\s+employee_cards|DELETE\\s+FROM\\s+employee_cards)/s", $gunlukSrc));

// ── Behavioral: pdks_faz9a_smoke.php İLE AYNI fikstür deseni ──
function pdks_ddl_sqlite9e(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1]; $govde = $m[2];
    $parcalar = []; $buf = ''; $derinlik = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $derinlik++;
        if ($c === ')') $derinlik--;
        if ($c === ',' && $derinlik === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^(?:UNIQUE KEY|INDEX|KEY|CONSTRAINT)\b/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)"];
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$ADMIN9E = true; $DEPOT9E = 'Depo A'; $CAN9E = ['attendance.management_reports', 'attendance.entitlements', 'attendance.foreman_rates'];
function db(): PDO { global $db; return $db; }
function is_admin(): bool { global $ADMIN9E; return $ADMIN9E; }
function active_depot(): ?string { global $DEPOT9E; return $DEPOT9E; }
function can(string $p): bool { global $CAN9E; return in_array($p, $CAN9E, true); }

require_once $root . '/config/pdks.php';
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_faz9d.php';

$db->exec("CREATE TABLE employees (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT, status TEXT DEFAULT 'aktif')");
$db->exec("CREATE TABLE employee_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, uid_hex TEXT, status TEXT DEFAULT 'aktif', revoke_reason TEXT, revoked_at TEXT, revoked_by INTEGER)");
$db->exec("CREATE TABLE employee_card_uids (id INTEGER PRIMARY KEY AUTOINCREMENT, card_id INTEGER, uid_hex TEXT, kind TEXT DEFAULT 'canonical', created_at TEXT)");
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'),('ERKEK','Erkek')");
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1)");
$db->exec("INSERT INTO foremen (code,name) VALUES ('C1','Ayşe Çavuş')");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, canonical_uid TEXT, uid_bytes INTEGER DEFAULT 4, uid_decimal TEXT, enrolled_source TEXT DEFAULT 'usb_decimal', status TEXT DEFAULT 'available', notes TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, opened_at TEXT, opened_by_user_id INTEGER, closed_at TEXT, closed_by_user_id INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_hours TEXT, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT DEFAULT 'scan', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, display_name TEXT, username TEXT)");
$db->exec("INSERT INTO users (id,display_name,username) VALUES (7,'Test Kullanıcı','test.user')");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
foreach (pdks_faz9d_tablolar() as $sql) {
    [$create] = pdks_ddl_sqlite9e($sql);
    $db->exec($create);
}

$db->exec("INSERT INTO employees (id,full_name,status) VALUES (1,'Eski Personel','aktif')");
$db->exec("INSERT INTO employee_cards (id,employee_id,uid_hex,status) VALUES (1,1,'AABBCC01','aktif')");
$db->exec("INSERT INTO employee_card_uids (card_id,uid_hex) VALUES (1,'AABBCC01')");
$engel = pdks_gunluk_kalici_kart_engeli('AABBCC01', 'nfc_hex', $db);
ok9e('Davranışsal: AKTİF kalıcı kart okutulunca hata metni "pasife alın/iptal edin" İÇERİYOR',
    $engel !== null && str_contains((string)$engel['hata'], 'pasife alın/iptal edin'), json_encode($engel, JSON_UNESCAPED_UNICODE));
ok9e('Davranışsal: hata metni personelin adını da İÇERİYOR (hangi kart olduğu belli)',
    $engel !== null && str_contains((string)$engel['hata'], 'Eski Personel'));

// =========================================================
// § D — Bayat taslak (needs_recalculation) netliği
// =========================================================
echo "\n=== D. Bayat taslak netliği (madde D) ===\n";
$hakedisListeSrc = oku9e('cavus_hakedis.php');
$hakedisDetaySrc = oku9e('cavus_hakedis_detay.php');
ok9e('cavus_hakedis.php: sembol-yalnız "⚠️" YERİNE açık metin "Yeniden hesaplama gerekli" var (masaüstü)',
    str_contains($hakedisListeSrc, 'Yeniden hesaplama gerekli'));
ok9e('cavus_hakedis.php: mobil kartta da aynı açık metin var (yalnız "· yeniden hesap gerekli" kısaltması DEĞİL)',
    substr_count($hakedisListeSrc, 'Yeniden hesaplama gerekli') >= 2);
ok9e('cavus_hakedis_detay.php: uyarı kutusu açıklama cümlesini İÇERİYOR (Puantaj / mesai değerlendirmesi ... değişti)',
    str_contains($hakedisDetaySrc, 'Puantaj / mesai değerlendirmesi hakediş taslağından sonra değişti'));
ok9e('cavus_hakedis_detay.php: yetkiliyse mevcut "Yeniden Hesapla" düğmesine YÖNLENDİRİYOR (yeni bir yeniden-hesaplama yolu İCAT EDİLMEDİ)',
    str_contains($hakedisDetaySrc, '🔄 Yeniden Hesapla') && str_contains($hakedisDetaySrc, "action === 'hesapla'"));
ok9e('needs_recalculation OTOMATİK temizlenmiyor/hesaplanmıyor bu fazda (yalnız GÖRÜNTÜLEME değişti) — dosyada yeni bir UPDATE ... needs_recalculation YOK',
    !preg_match('/UPDATE\s+foreman_daily_entitlements\s+SET\s+needs_recalculation/i', $hakedisListeSrc . $hakedisDetaySrc));

// =========================================================
// § E — Bağlamsal denetim/geçmiş görünürlüğü
// =========================================================
echo "\n=== E. Denetim/geçmiş görünürlüğü (madde E) ===\n";

// ── E1: puantaj denetim geçmişi — deterministik bağlantı, uydurma yok ──
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (1,1,'Ayşe Çavuş','C1','2026-01-05','Depo A','closed')");
$db->exec("INSERT INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_name_snapshot,entry_time,exit_time,status,work_date_snapshot,depo_snapshot) VALUES (10,1,1,'Kadın','2026-01-05 08:00:00',NULL,'legacy_unresolved','2026-01-05','Depo A')");
$db->exec("INSERT INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_name_snapshot,entry_time,exit_time,status,work_date_snapshot,depo_snapshot) VALUES (11,1,2,'Erkek','2026-01-05 08:05:00','2026-01-05 17:00:00','closed','2026-01-05','Depo A')");
$db->exec("INSERT INTO worker_cards (id,card_no,canonical_uid) VALUES (1,'K001','AA01'),(2,'K002','AA02')");
// period 10 için bir düzeltme + period 11 İÇİN İLGİSİZ bir tarama olayı (gunluk_giris) — İKİNCİSİ SÜZÜLMELİ.
$db->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values,created_at) VALUES (7,'puantaj_duzeltme','daily_worker_work_periods',10,'{}',?, '2026-01-05 12:00:00')")
    ->execute([json_encode(['reason' => 'Kart yanlış okutuldu', 'note' => 'Test notu'], JSON_UNESCAPED_UNICODE)]);
$db->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values,created_at) VALUES (7,'gunluk_giris','daily_worker_work_periods',11,NULL,'{}', '2026-01-05 08:05:00')")->execute();
$db->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values,created_at) VALUES (7,'puantaj_iptal','daily_worker_work_periods',999,'{}','{}', '2026-01-05 09:00:00')")->execute();

$gecmis = pdks_gunluk_puantaj_denetim_gecmisi([10, 11], $db);
ok9e('pdks_gunluk_puantaj_denetim_gecmisi(): yalnız BU oturumun dönemlerine bağlı satır döner (1 satır — period 999 SIZMADI)',
    count($gecmis) === 1, json_encode($gecmis, JSON_UNESCAPED_UNICODE));
ok9e('pdks_gunluk_puantaj_denetim_gecmisi(): ham GİRİŞ/ÇIKIŞ tarama olayı (gunluk_giris) SÜZÜLDÜ (fabrikasyon/tekrar YOK)',
    count($gecmis) === 1 && $gecmis[0]['action'] === 'puantaj_duzeltme');
ok9e('pdks_gunluk_puantaj_denetim_gecmisi(): aktör adı çözüldü, işlem etiketi Türkçe/okunur',
    $gecmis[0]['aktor'] === 'Test Kullanıcı' && $gecmis[0]['islem_etiket'] === '✏️ Puantaj kaydı düzeltildi');
ok9e('pdks_gunluk_puantaj_denetim_gecmisi(): detay ham JSON DEĞİL, okunur "sebep — not" metni',
    $gecmis[0]['detay'] === 'Kart yanlış okutuldu — Test notu');
ok9e('pdks_gunluk_puantaj_denetim_gecmisi(): boş id listesi ÇÖKMEDEN [] döner',
    pdks_gunluk_puantaj_denetim_gecmisi([], $db) === []);

// ── E2: hakediş denetim geçmişi — deterministik bağlantı + Faz 9D mahsup ──
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount) VALUES (1,1,1,'Ayşe Çavuş','C1','2026-01-05','Depo A','final','TRY','50000.00')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount) VALUES (2,1,1,'Ayşe Çavuş','C1','2026-01-06','Depo A','final','TRY','10000.00')");
$db->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values,created_at) VALUES (7,'finalize','foreman_daily_entitlements',1,'{}','{}', '2026-01-05 18:00:00')")->execute();
$db->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values,created_at) VALUES (7,'reopen','foreman_daily_entitlements',2,'{}',?, '2026-01-06 09:00:00')")
    ->execute([json_encode(['sebep' => 'Yanlış kart sayısı'], JSON_UNESCAPED_UNICODE)]);
$db->exec("INSERT INTO foreman_entitlement_adjustments (id,entitlement_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,currency,signed_amount,reason,status,created_by_user_id,created_at) VALUES (5,1,1,'Ayşe Çavuş','C1','2026-01-05','TRY','250.00','Eksik ödeme mahsubu','valid',7,'2026-01-07 10:00:00')");
$db->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values,created_at) VALUES (7,'create','foreman_entitlement_adjustments',5,NULL,?, '2026-01-07 10:00:00')")
    ->execute([json_encode(['signed_amount' => '250.00', 'reason' => 'Eksik ödeme mahsubu'], JSON_UNESCAPED_UNICODE)]);

$hkGecmis = pdks_hakedis_denetim_gecmisi(1, $db);
ok9e('pdks_hakedis_denetim_gecmisi(): entitlement=1 için finalize + mahsup create döner (reopen(entitlement=2) SIZMADI)',
    count($hkGecmis) === 2, json_encode($hkGecmis, JSON_UNESCAPED_UNICODE));
$eylemler = array_column($hkGecmis, 'action');
ok9e('pdks_hakedis_denetim_gecmisi(): "finalize" ve "create" (mahsup) İKİSİ de var',
    in_array('finalize', $eylemler, true) && in_array('create', $eylemler, true));
ok9e('pdks_hakedis_denetim_gecmisi(): entitlement=2 sorgusu YALNIZ kendi reopen\'ını döner (izolasyon)',
    count(pdks_hakedis_denetim_gecmisi(2, $db)) === 1 && pdks_hakedis_denetim_gecmisi(2, $db)[0]['action'] === 'reopen');
ok9e('pdks_hakedis_denetim_gecmisi(): geçersiz id (<1) ÇÖKMEDEN [] döner', pdks_hakedis_denetim_gecmisi(0, $db) === []);
ok9e('pdks_hakedis_denetim_gecmisi(): mahsup tutarı okunur biçimde detayda var (ham JSON DEĞİL)',
    (bool)array_filter($hkGecmis, fn($r) => $r['action'] === 'create' && str_contains((string)$r['detay'], '250,00')));

foreach (['gunluk_isci_puantaj_detay.php', 'cavus_hakedis_detay.php'] as $sayfa) {
    $src = oku9e($sayfa);
    ok9e("$sayfa: 'İşlem Geçmişi' bölümü eklendi", str_contains($src, 'İşlem Geçmişi'));
    ok9e("$sayfa: ham JSON dökülmüyor (json_encode/print_r kullanıcıya YAZDIRILMIYOR)",
        !preg_match('/echo\s+json_encode|<\?=\s*json_encode|print_r\(\$d/i', $src));
}
ok9e('config/pdks_gunluk.php: audit.php\'nin GENEL kayıt defteriyle karışmasın diye docblock AÇIKÇA ayırıyor',
    str_contains($gunlukSrc, 'GENEL (admin, tüm sistem) kayıt defteriyle KARIŞTIRILMASIN'));

// =========================================================
// § F — Eksik-çıkış operasyonel netliği
// =========================================================
echo "\n=== F. Eksik-çıkış netliği (madde F) ===\n";
$eksikCikislar = pdks_gunluk_faz8a_eksik_cikislar('2026-01-05', 'Depo A', null, $db);
ok9e('pdks_gunluk_faz8a_eksik_cikislar(): period_id + donem_durumu alanları artık DÖNÜYOR',
    count($eksikCikislar) === 1 && isset($eksikCikislar[0]['period_id']) && isset($eksikCikislar[0]['donem_durumu']));
ok9e('pdks_gunluk_faz8a_eksik_cikislar(): legacy_unresolved dönem doğru durumla işaretli (canlı "open" İLE KARIŞTIRILMADI)',
    $eksikCikislar[0]['donem_durumu'] === 'legacy_unresolved');

$puantajSrc = oku9e('gunluk_isci_puantaj.php');
$puantajDetaySrc = oku9e('gunluk_isci_puantaj_detay.php');
ok9e('gunluk_isci_puantaj.php: "Manuel Çıkış Gir" derin bağlantısı eklendi', str_contains($puantajSrc, 'Manuel Çıkış Gir'));
ok9e('gunluk_isci_puantaj.php: bağlantı manuel_cikis.php?period_id=...&session_id=... hedefliyor',
    (bool)preg_match('/manuel_cikis\.php\?period_id=.*?&session_id=/', $puantajSrc));
ok9e('gunluk_isci_puantaj.php: yetkisiz kullanıcıya 403\'e giden bir bağlantı GÖSTERİLMİYOR — açıklayıcı metin var',
    str_contains($puantajSrc, 'Manuel düzeltme yetkisi gerekir'));
ok9e('gunluk_isci_puantaj.php: eylem entitlements_finalize izniyle KAPILI (manuel_cikis.php İLE AYNI yetki)',
    str_contains($puantajSrc, "pdks_hakedis_can('entitlements_finalize')"));
ok9e('gunluk_isci_puantaj_detay.php: "Manuel Çıkış Gir" derin bağlantısı eklendi', str_contains($puantajDetaySrc, 'Manuel Çıkış Gir'));
ok9e('gunluk_isci_puantaj_detay.php: eylem AYNI izinle KAPILI', str_contains($puantajDetaySrc, "pdks_hakedis_can('entitlements_finalize')"));
ok9e('FAZ8E kuralı DEĞİŞMEDİ — eylem yalnız "open"/"legacy_unresolved" durumları için gösteriliyor (yeni bir durum İCAT EDİLMEDİ)',
    (bool)preg_match("/in_array\\(\\\$[a-zA-Z]+\\['durum'\\]\\['kod'\\] \\?\\? '', \\['cikis_yok', 'legacy_unresolved'\\], true\\)/", $puantajDetaySrc)
    || (bool)preg_match("/in_array\\(\\\$donemDurum, \\['open', 'legacy_unresolved'\\], true\\)/", $puantajSrc));
ok9e('gunluk_isci_puantaj.php/detay: hiçbir yerde toplu/otomatik çıkış kapatma YOK (UPDATE ... exit_time = NOW veya benzeri İCAT EDİLMEDİ)',
    !preg_match('/UPDATE\s+daily_worker_work_periods\s+SET\s+exit_time/i', $puantajSrc . $puantajDetaySrc));
ok9e('manuel_cikis.php KENDİSİ bu fazda DEĞİŞMEDİ (mevcut Faz 8E akışı aynen korunuyor)',
    str_contains(oku9e('manuel_cikis.php'), "pdks_faz8e_manuel_cikis_kaydet("));

// =========================================================
// § G — Sürüm
// =========================================================
echo "\n=== G. Sürüm (APP_SURUM / CACHE_NAME) ===\n";
ok9e('config/helpers.php: APP_SURUM v237', (bool)preg_match("/APP_SURUM['\"]?\\s*,?\\s*['\"]v237['\"]/", $helpersSrc) || str_contains($helpersSrc, "'v237'"));
ok9e('sw.js: CACHE_NAME yukleme-plani-v237', str_contains(oku9e('sw.js'), 'yukleme-plani-v237'));

// =========================================================
echo "\n=== SONUÇ ===\n";
printf("Toplam: %d, Geçti: %d, Başarısız: %d\n", $pass + $fail, $pass, $fail);
exit($fail > 0 ? 1 : 0);
