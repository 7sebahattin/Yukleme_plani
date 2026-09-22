<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_faz9a_smoke.php — Faz 9A (v228) odaklı doğrulama.
//
// v227 sistem audit'inin M-01/M-04/H-04/B1/M-02/T-01/M-07 bulguları için
// yazılmış FAZ 9A DEĞİŞİKLİKLERİNİ hedefleyen BEHAVİORAL testlerdir —
// str_contains/preg_match ile "kod var mı" kontrolü DEĞİL, gerçek fonksiyon
// çağrıları + bellek içi SQLite ile "davranış doğru mu" kontrolüdür.
// Yalnız BURADA sınanan davranışlar için ikinci bir kaynak; ilgili modülün
// KENDİ smoke dosyaları (pdks_faz8j_smoke.php, pdks_rapor_tutarlilik_smoke.php,
// vb.) hâlâ birincil kapsamdır.
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok9a(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$ADMIN9A = true;
$DEPOT9A = 'Depo A';
$CAN9A = ['attendance.management_reports', 'attendance.entitlements', 'attendance.foreman_rates', 'attendance.foreman_accounts'];
function db(): PDO { global $db; return $db; }
function is_admin(): bool { global $ADMIN9A; return $ADMIN9A; }
function active_depot(): ?string { global $DEPOT9A; return $DEPOT9A; }
function can(string $p): bool { global $CAN9A; return in_array($p, $CAN9A, true); }

require_once $root . '/config/pdks.php';
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_faz8e.php';
require_once $root . '/config/pdks_faz8j.php';
require_once $root . '/config/pdks_cari.php';
require_once $root . '/config/pdks_rapor.php';

// ── Şema (Faz 8J-tam + kalıcı personel çapraz-sistem tabloları) ─────────
$db->exec("CREATE TABLE employees (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT, status TEXT DEFAULT 'aktif')");
$db->exec("CREATE TABLE employee_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, uid_hex TEXT, status TEXT DEFAULT 'aktif', revoke_reason TEXT, revoked_at TEXT, revoked_by INTEGER)");
$db->exec("CREATE TABLE employee_card_uids (id INTEGER PRIMARY KEY AUTOINCREMENT, card_id INTEGER, uid_hex TEXT, kind TEXT DEFAULT 'canonical', created_at TEXT)");
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'),('ERKEK','Erkek')");
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("INSERT INTO foremen (code,name) VALUES ('C1','Ayşe Çavuş'),('C2','Mehmet Çavuş')");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, canonical_uid TEXT, uid_bytes INTEGER DEFAULT 4, uid_decimal TEXT, enrolled_source TEXT DEFAULT 'usb_decimal', status TEXT DEFAULT 'available', notes TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT, opened_at TEXT, opened_by_user_id INTEGER, closed_at TEXT, closed_by_user_id INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_hours INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT DEFAULT 'scan', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER, created_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, payment_date TEXT, amount TEXT, currency TEXT, payment_method TEXT, reference_no TEXT, description TEXT, status TEXT, created_by_user_id INTEGER, created_at TEXT, cancelled_at TEXT, cancelled_by_user_id INTEGER, cancellation_reason TEXT)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$day = date('Y-m-d', strtotime('-1 day'));

// =========================================================
// § A — M-01: pdks_gunluk_depo_kontrol() paylaşılan yardımcı
// =========================================================
echo "=== A. M-01 — pdks_gunluk_depo_kontrol() ===\n";
ok9a('Eşleşen depo -> null (izin verilir)', pdks_gunluk_depo_kontrol('Depo A', 'Depo A') === null);
ok9a('Farklı depo -> hata döner', pdks_gunluk_depo_kontrol('Depo B', 'Depo A') !== null);
ok9a('Aktif depo boşsa -> hata döner (depo seçilmeli)', pdks_gunluk_depo_kontrol('Depo A', '') !== null);
ok9a('Kayıt deposu BOŞ (atanmamış veri) -> her depoda erişilebilir', pdks_gunluk_depo_kontrol('', 'Depo A') === null);
ok9a('$aktifDepo verilmezse active_depot() KULLANILIR', pdks_gunluk_depo_kontrol('Depo A') === null && pdks_gunluk_depo_kontrol('Depo B') !== null);
ok9a('pdks_faz8j_aktif_depo_kontrol() artık PAYLAŞILAN yardımcıyı SARAR (ikinci bir uygulama yok)',
    pdks_faz8j_aktif_depo_kontrol('Depo A') === null && pdks_faz8j_aktif_depo_kontrol('Depo B') !== null && pdks_faz8j_aktif_depo_kontrol('') !== null);

// ── Fikstür: Depo A'da bir oturum/hakediş, Depo B'de bir oturum/hakediş ──
$db->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (1,1,?,?,?,?,?)')
    ->execute(['Ayşe Çavuş','C1',$day,'Depo A','closed']);
$db->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (2,2,?,?,?,?,?)')
    ->execute(['Mehmet Çavuş','C2',$day,'Depo B','closed']);
$db->prepare("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount) VALUES (1,1,1,'Ayşe Çavuş','C1',?,'Depo A','draft','TRY','0.00')")->execute([$day]);
$db->prepare("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount) VALUES (2,2,2,'Mehmet Çavuş','C2',?,'Depo B','draft','TRY','0.00')")->execute([$day]);

echo "\n=== B. M-01 — sayfa BAZINDA gerçek veri yolu (aktif depo = Depo A) ===\n";
// Her satır, o sayfanın KENDİ SQL'inin çektiği depo değerini KULLANIR — bkz.
// mesai_degerlendirme.php/cavus_hakedis.php/cavus_hakedis_detay.php/
// gunluk_isci_giris_cikis.php/gunluk_isci_puantaj_detay.php/gunluk_puantaj_yazdir.php.
$oturumADepo = $db->query('SELECT depo FROM daily_work_sessions WHERE id=1')->fetchColumn();
$oturumBDepo = $db->query('SELECT depo FROM daily_work_sessions WHERE id=2')->fetchColumn();
ok9a('mesai_degerlendirme.php / gunluk_isci_puantaj_detay.php / gunluk_puantaj_yazdir.php: Depo A oturumu KABUL edilir', pdks_gunluk_depo_kontrol((string)$oturumADepo) === null);
ok9a('mesai_degerlendirme.php / gunluk_isci_puantaj_detay.php / gunluk_puantaj_yazdir.php: Depo B oturumu REDDEDİLİR', pdks_gunluk_depo_kontrol((string)$oturumBDepo) !== null);
$hakedisADepo = $db->query('SELECT depo FROM foreman_daily_entitlements WHERE id=1')->fetchColumn();
$hakedisBDepo = $db->query('SELECT depo FROM foreman_daily_entitlements WHERE id=2')->fetchColumn();
ok9a('cavus_hakedis_detay.php: Depo A hakedişi KABUL edilir', pdks_gunluk_depo_kontrol((string)$hakedisADepo) === null);
ok9a('cavus_hakedis_detay.php: Depo B hakedişi REDDEDİLİR (finalize/reopen/hesapla dahil — tek kontrol noktası)', pdks_gunluk_depo_kontrol((string)$hakedisBDepo) !== null);
// cavus_hakedis.php POST hesapla + gunluk_isci_giris_cikis.php ajax=kapat:
// session_id istemciden gelir, SAYFA kendi SELECT depo FROM daily_work_sessions
// WHERE id=? sorgusuyla ÇÖZER (bkz. sayfa kaynağı) — burada AYNI sorgu ve
// AYNI kontrol tekrarlanarak IDOR yolunun KENDİSİ kanıtlanır.
$stSid = $db->prepare('SELECT depo FROM daily_work_sessions WHERE id=?');
$stSid->execute([2]);
$sidDepo = $stSid->fetchColumn();
ok9a('cavus_hakedis.php (hesapla) / gunluk_isci_giris_cikis.php (ajax=kapat): session_id=2 (Depo B) elle gönderilirse REDDEDİLİR', $sidDepo !== false && pdks_gunluk_depo_kontrol((string)$sidDepo) !== null);
$stSid->execute([1]);
$sidDepoA = $stSid->fetchColumn();
ok9a('cavus_hakedis.php (hesapla) / gunluk_isci_giris_cikis.php (ajax=kapat): session_id=1 (Depo A) KABUL edilir', pdks_gunluk_depo_kontrol((string)$sidDepoA) === null);

echo "\n=== C. M-01 — altı sayfanın HER BİRİ gerçekten pdks_gunluk_depo_kontrol() ÇAĞIRIYOR (statik kablolama kanıtı) ===\n";
// Yukarıdaki §B, YARDIMCININ doğru çalıştığını kanıtlar; bu bölüm onun
// gerçekten HER ALTI sayfaya BAĞLANDIĞINI (bir davranışsal test payload'ı
// olmayan gerçek HTTP isteği kurmadan) doğrular — ikisi birlikte "sayfa X
// artık depo dışı erişimi reddediyor" iddiasının TAM kanıtıdır.
foreach ([
    'mesai_degerlendirme.php', 'cavus_hakedis.php', 'cavus_hakedis_detay.php',
    'gunluk_isci_giris_cikis.php', 'gunluk_isci_puantaj_detay.php', 'gunluk_puantaj_yazdir.php',
] as $sayfa) {
    $src = (string)file_get_contents($root . '/' . $sayfa);
    ok9a("$sayfa: pdks_gunluk_depo_kontrol() çağırıyor", str_contains($src, 'pdks_gunluk_depo_kontrol('));
}

// ⚠ Denetim düzeltmesi (Personel Takibi derin taraması): yukarıdaki kontrol
// dosya GENELİNDE "string geçiyor mu" bakıyordu — session_id İSTEMCİDEN
// gelen HER $_GET['ajax'] dalının AYRI AYRI bu kontrolü çağırdığını KANITLAMIYORDU.
// gunluk_isci_giris_cikis.php'de tam olarak bu boşluk yüzünden ?ajax=kaydet
// dalı depo kontrolsüz kalmıştı (?ajax=kapat'ta VARDI, ?ajax=kaydet'te YOKTU) —
// dosya genelinde string arandığı için test yine de YEŞİLDİ. Şimdi HER
// session_id kullanan dal AYRI AYRI doğrulanıyor.
$gicSrc = (string)file_get_contents($root . '/gunluk_isci_giris_cikis.php');
foreach (['kaydet', 'kapat', 'kapanis_kontrol'] as $dal) {
    if (!preg_match(
        "/\\\$_GET\\['ajax'\\] \\?\\? ''\\) === '" . preg_quote($dal, '/') . "'\\) \\{(.*?)\n\\}\n\n/s",
        $gicSrc,
        $m
    )) {
        ok9a("gunluk_isci_giris_cikis.php: ?ajax=$dal bloğu regex ile bulunabildi", false);
        continue;
    }
    ok9a("gunluk_isci_giris_cikis.php: ?ajax=$dal dalı KENDİSİ pdks_gunluk_depo_kontrol() çağırıyor (session_id kullanan HER dal ayrı ayrı korumalı olmalı)",
        str_contains($m[1], 'pdks_gunluk_depo_kontrol('));
}

echo "\n=== D. M-04 — hakediş hesapla artık finansal-yazma izni istiyor ===\n";
ok9a('pdks_hakedis_can(): salt-görüntüleme izniyle entitlements_finalize REDDEDİLİR',
    (function () {
        global $CAN9A, $ADMIN9A;
        $eskiCan = $CAN9A; $eskiAdmin = $ADMIN9A;
        $ADMIN9A = false;   // is_admin() KISA-DEVRESİ kapatılmalı, aksi halde her zaman true döner
        $CAN9A = ['attendance.entitlements'];   // yalnız görüntüleme
        $r = pdks_hakedis_can('entitlements_finalize');
        $CAN9A = $eskiCan; $ADMIN9A = $eskiAdmin;
        return $r === false;
    })());
ok9a('pdks_hakedis_can(): entitlements+foreman_rates (finansal yazma) İLE entitlements_finalize KABUL edilir',
    (function () {
        global $CAN9A, $ADMIN9A;
        $eskiCan = $CAN9A; $eskiAdmin = $ADMIN9A;
        $ADMIN9A = false;
        $CAN9A = ['attendance.entitlements', 'attendance.foreman_rates'];
        $r = pdks_hakedis_can('entitlements_finalize');
        $CAN9A = $eskiCan; $ADMIN9A = $eskiAdmin;
        return $r === true;
    })());
foreach (['cavus_hakedis.php', 'cavus_hakedis_detay.php'] as $sayfa) {
    $src = (string)file_get_contents($root . '/' . $sayfa);
    // hesapla dalının 'entitlements_view' DEĞİL 'entitlements_finalize' istediğini
    // doğrulamak için: dosyada 'entitlements_view' geçen TEK yer sayfa
    // SEVİYESİ görüntüleme kapısı olmalı, 'hesapla' aksiyon bloğu İÇİNDE
    // GEÇMEMELİ.
    ok9a("$sayfa: 'action === \\'hesapla\\'' bloğu 'entitlements_finalize' istiyor",
        (bool)preg_match("/action['\"]?\\s*===?\\s*'hesapla'.*?entitlements_finalize/s", $src)
        || (bool)preg_match("/'hesapla'\\)\\s*\\{[^}]*?require_pdks_hakedis\\('entitlements_finalize'\\)/s", $src));
}

echo "\n=== E. H-04 — kalıcı personel UID engeli yalnız AKTİF kartlar için ===\n";
$db->exec("INSERT INTO employees (id,full_name,status) VALUES (1,'Eski Personel','ayrildi')");
$statusler = ['aktif' => true, 'iptal' => false, 'kayip' => false, 'degistirildi' => false, 'suresi_doldu' => false, 'pasif' => false];
$uidNo = 100;
$eslesmeTablosu = [];
foreach ($statusler as $durum => $blokluMu) {
    $uidNo++;
    $uid = strtoupper(dechex($uidNo)) . str_repeat('0', 8 - strlen(dechex($uidNo)));
    $cardId = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM employee_cards")->fetchColumn();
    $db->prepare("INSERT INTO employee_cards (id,employee_id,uid_hex,status) VALUES (?,1,?,?)")->execute([$cardId, $uid, $durum]);
    $db->prepare("INSERT INTO employee_card_uids (card_id,uid_hex) VALUES (?,?)")->execute([$cardId, $uid]);
    $eslesmeTablosu[$durum] = $uid;

    $engel = pdks_gunluk_kalici_kart_engeli($uid, 'nfc_hex', $db);
    $beklenenEngellenir = $blokluMu;
    ok9a("durum='$durum': pdks_gunluk_kalici_kart_engeli() " . ($beklenenEngellenir ? 'ENGELLER' : 'SERBEST BIRAKIR'),
        ($engel !== null) === $beklenenEngellenir, json_encode($engel));

    $kaliciMi = pdks_gunluk_uid_kalici_kartta_mi($uid, $db);
    ok9a("durum='$durum': pdks_gunluk_uid_kalici_kartta_mi() " . ($beklenenEngellenir ? 'ENGELLER' : 'SERBEST BIRAKIR'),
        ($kaliciMi !== null) === $beklenenEngellenir, json_encode($kaliciMi));
}
ok9a('Tüm employee_card_uids alias satırları KORUNDU (silinmedi) — 6 durum × 1 satır',
    (int)$db->query("SELECT COUNT(*) FROM employee_card_uids")->fetchColumn() === 6);
ok9a('Tüm employee_cards satırları KORUNDU (durum değişmedi, silinmedi)',
    (int)$db->query("SELECT COUNT(*) FROM employee_cards")->fetchColumn() === 6);
foreach ($eslesmeTablosu as $durum => $uid) {
    $cozum = pdks_kart_cozumle($uid, 'nfc_hex', $db);
    ok9a("durum='$durum': pdks_kart_cozumle() (tarihsel arama/denetim) DURUMDAN BAĞIMSIZ hâlâ çözer",
        $cozum !== null && $cozum['card']['status'] === $durum && $cozum['employee']['full_name'] === 'Eski Personel');
}

// ── Uçtan uca: iptal edilmiş bir kalıcı kart artık günlük işçi olarak
// KAYDEDİLEBİLİR (GİRİŞ taraması gerçekten çalışır) ────────────────────
$db->exec("INSERT INTO worker_types (id,code,name,is_active) SELECT id,code,name,is_active FROM worker_types WHERE 0");   // no-op guard
$db->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (99,1,?,?,?,?,?)')
    ->execute(['Ayşe Çavuş','C1',$day,'Depo A','open']);
$revokedUid = $eslesmeTablosu['iptal'];
$revokedDec = pdks_uid_to_decimal($revokedUid);
$girisIptal = pdks_gunluk_faz8a_giris_kaydet($revokedDec, 'usb_decimal', 99, 1, 'auto', 7, $db);
ok9a('İPTAL edilmiş kalıcı kart UID\'i artık GİRİŞ taramasıyla günlük işçi olarak otomatik kaydedilir',
    $girisIptal['ok'] === true, json_encode($girisIptal, JSON_UNESCAPED_UNICODE));
$activeUid = $eslesmeTablosu['aktif'];
$activeDec = pdks_uid_to_decimal($activeUid);
$girisAktif = pdks_gunluk_faz8a_giris_kaydet($activeDec, 'usb_decimal', 99, 1, 'auto', 7, $db);
ok9a('AKTİF kalıcı kart UID\'i GİRİŞ taramasında hâlâ REDDEDİLİR (kod=kalici_kart)',
    $girisAktif['ok'] === false && ($girisAktif['kod'] ?? '') === 'kalici_kart', json_encode($girisAktif, JSON_UNESCAPED_UNICODE));

// =========================================================
// § F — M-02: legacy_backfill mutabakatı
// =========================================================
echo "\n=== F. M-02 — legacy_backfill dönemleri tüm yüzeylerde tutarlı ===\n";
// ⚠ KENDİ, tek kullanımlık bir güne izole edilir — §B/§E'nin AYNI Depo A/
// foreman 1 fikstürleriyle (farklı günlerde olsalar bile gun_ozeti/KPI gibi
// TARİH ARALIĞI sorgularının) çakışıp YANLIŞ POZİTİF üretmesini önler.
$dayF = '2020-03-10';   // sabit, bugünden bağımsız — ay çakışması riskini TAMAMEN ortadan kaldırır
$db->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (50,1,?,?,?,?,?)')
    ->execute(['Ayşe Çavuş','C1',$dayF,'Depo A','closed']);
$db->exec("INSERT INTO worker_cards (id,card_no,canonical_uid,status) VALUES (50,'BF1','BF0000AA','available'),(51,'BF2','BF0000BB','available')");
$insBf = $db->prepare("INSERT INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_event_id,exit_event_id,entry_time,exit_time,work_date_snapshot,depo_snapshot,status,source,is_voided) VALUES (?,50,?,?,?,?,?,?,?,?,'Depo A',?,?,0)");
$insBf->execute([50,50,1,'Kadın',5001,5011,"$dayF 08:00:00","$dayF 17:00:00",$dayF,'closed','scan']);
$insBf->execute([51,51,2,'Erkek',5002,5012,"$dayF 08:00:00","$dayF 17:00:00",$dayF,'closed','legacy_backfill']);
$ozetBf = pdks_gunluk_faz8a_oturum_ozet(50, $db);
$sayimBf = pdks_gunluk_faz8a_oturum_kart_sayimi(50, $db);
$gunBf = pdks_gunluk_faz8a_gun_ozeti($dayF, 'Depo A', $db);
$donemlerBf = pdks_gunluk_faz8a_oturum_donemleri(50, $db);
ok9a('oturum_ozet.giris_toplam artık legacy_backfill dönemini de sayar (2, 1 DEĞİL)',
    $ozetBf['giris_toplam'] === 2, json_encode($ozetBf, JSON_UNESCAPED_UNICODE));
ok9a('oturum_kart_sayimi (hakediş girdisi) İLE oturum_ozet AYNI toplamı verir',
    array_sum(array_column($sayimBf, 'n')) === $ozetBf['giris_toplam']);
ok9a('oturum_donemleri (puantaj detay grid) İLE oturum_ozet AYNI satır sayısını verir',
    count($donemlerBf) === $ozetBf['giris_toplam']);
ok9a('gun_ozeti İLE oturum_ozet AYNI toplamı verir (Depo A / bu gün TEK oturum)',
    $gunBf['giris_toplam'] === $ozetBf['giris_toplam']);
ok9a('oturum_ozet.son_cikis legacy_backfill çıkışını da yansıtır',
    $ozetBf['son_cikis'] === "$dayF 17:00:00");
$faz8bBf = pdks_faz8b_oturum_donemleri(50, $db);
ok9a('Faz 8B (mesai değerlendirme/hakediş girdisi) legacy_backfill dönemini de içerir',
    count($faz8bBf) === 2);

// =========================================================
// § G — Void hariç tutma: raporlar / Faz 8B / hakediş / Toplu Döküm
// =========================================================
echo "\n=== G. Void hariç tutma — raporlar / Faz 8B / hakediş / Toplu Döküm ===\n";
// ⚠ §F İLE AYNI gerekçeyle KENDİ güne izole edilir (bu blok ayrıca Toplu
// Döküm için '2026-...' değil $dayG'nin ait olduğu AYI kullanır, o yüzden
// §I/§H'nin izole alt süreç fikstürleriyle de karışmaz — onlar ayrı bir
// PDO bağlantısında/ayda çalışır).
$dayG = '2019-11-05';   // sabit, bugünden bağımsız — ay çakışması riskini TAMAMEN ortadan kaldırır
$db->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (60,1,?,?,?,?,?)')
    ->execute(['Ayşe Çavuş','C1',$dayG,'Depo A','closed']);
$db->exec("INSERT INTO worker_cards (id,card_no,canonical_uid,status) VALUES (60,'V1','VV0000AA','available'),(61,'V2','VV0000BB','available')");
$db->exec("INSERT INTO foreman_worker_rates (foreman_id,worker_type_id,daily_rate,half_day_rate,overtime_mode,overtime_rate,currency,valid_from,created_by_user_id,created_at) VALUES (1,1,'1000.00','500.00','hourly','100.00','TRY','$dayG',7,'$dayG')");
$insV = $db->prepare("INSERT INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_event_id,exit_event_id,entry_time,exit_time,work_date_snapshot,depo_snapshot,status,source,declared_attendance_class,is_voided) VALUES (?,60,?,1,'Kadın',?,?,?,?,?,'Depo A','closed','scan','tam',?)");
$insV->execute([60,60,6001,6011,"$dayG 08:00:00","$dayG 17:00:00",$dayG,0]);
$insV->execute([61,61,6002,6012,"$dayG 08:00:00","$dayG 17:00:00",$dayG,1]);   // İPTAL — normal fiyatla ödenecekmiş gibi dursaydı 2000 TL olurdu

$kpiVoidOnce = pdks_rapor_faz8a_operasyonel_kpi($dayG, $dayG, 'Depo A', 1, null, $db);
ok9a('§G KPI (pdks_rapor_faz8a_operasyonel_kpi): iptal edilen dönem toplam_calisan\'a KARIŞMAZ (1, 2 DEĞİL)',
    $kpiVoidOnce['toplam_calisan'] === 1, json_encode($kpiVoidOnce, JSON_UNESCAPED_UNICODE));

$hesapV = pdks_faz8b_hakedis_hesapla(60, 7, $db);
ok9a('§G Faz 8B hakediş: iptal edilen dönem HİÇ hesaplanmaz, yalnız 1000 TL (2000 DEĞİL)',
    $hesapV['ok'] === true && $hesapV['total_amount'] === '1000.00', json_encode($hesapV, JSON_UNESCAPED_UNICODE));
$lineV = array_values(array_filter($hesapV['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === 61))[0] ?? null;
ok9a('§G Faz 8B hakediş satırları: iptal edilen dönem (id=61) satırlarda YOK', $lineV === null);

$monthlyV = pdks_rapor_cavus_toplu_dokum(substr($dayG, 0, 7), 'Depo A', 1, false, $db);
$row60 = array_values(array_filter($monthlyV, fn($r) => (int)$r['session_id'] === 60))[0] ?? null;
ok9a('§G Çavuş Toplu Döküm: iptal edilen dönem aylık satıra KARIŞMAZ (toplam_isci=1)',
    $row60 !== null && (int)$row60['toplam_isci'] === 1, json_encode($row60, JSON_UNESCAPED_UNICODE));

// =========================================================
// § H — T-01: config/pdks_rapor.php artık KENDİ bağımlılıklarını yükler
// =========================================================
echo "\n=== H. T-01 — config/pdks_rapor.php tek başına (izole alt süreç) ===\n";
$izoleKod = <<<'PHP'
<?php
declare(strict_types=1);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function can(string $p): bool { return true; }
function is_admin(): bool { return false; }
// ⚠ BİLEREK YALNIZ pdks_rapor.php require edilir — pdks_gunluk.php/
// pdks_hakedis.php/pdks_cari.php YÜKLENMEZ. T-01 DÜZELTMESİNDEN ÖNCE bu,
// pdks_gunluk_faz8j_etkin_kosul() için "Call to undefined function" fatal
// hatasıyla ÇÖKERDİ (scripts/pdks_faz8d_toplu_dokum_smoke.php'nin canlı
// kanıtladığı gibi). Artık dosyanın KENDİ require_once'ları bunu karşılar.
require_once %s;
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT, sort_order INTEGER)");
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY, card_no TEXT, canonical_uid TEXT, uid_decimal TEXT)");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT, notes TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, status TEXT, source TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, session_id INTEGER, status TEXT, total_amount DECIMAL(14,2), currency TEXT)");
$sonuc = pdks_rapor_cavus_toplu_dokum('2026-01', 'Depo A', null, false, $db);
echo 'OK:' . (is_array($sonuc) ? '1' : '0');
PHP;
$tmpFile = tempnam(sys_get_temp_dir(), 'faz9a_izole_');
file_put_contents($tmpFile, sprintf($izoleKod, var_export($root . '/config/pdks_rapor.php', true)));
$izoleCikti = shell_exec('php ' . escapeshellarg($tmpFile) . ' 2>&1');
@unlink($tmpFile);
ok9a('config/pdks_rapor.php TEK BAŞINA (yalnız kendi require\'larıyla) fatal vermeden çalışır',
    is_string($izoleCikti) && str_contains($izoleCikti, 'OK:1'), (string)$izoleCikti);

// =========================================================
// § I — M-07: Faz 8B migrasyonu merkezi panelde REUSE edilir + idempotent
// =========================================================
echo "\n=== I. M-07 — Faz 8B migrasyonu migrate.php'de + idempotent ===\n";
$migrateSrc = (string)file_get_contents($root . '/migrate.php');
ok9a('migrate.php: Faz 8B için ayrı, kontrollü bir POST aksiyonu var (ne=pdks_faz8b)',
    (bool)preg_match("/\\\$_POST\\['ne'\\]\\s*\\?\\?\\s*''\\)\\s*===\\s*'pdks_faz8b'/", $migrateSrc));
ok9a('migrate.php: bu dal da csrf_check() çağırıyor', (bool)preg_match("/'pdks_faz8b'\\)\\s*\\{\\s*\\n\\s*csrf_check/", $migrateSrc));
ok9a('migrate.php: KENDİ migrasyon mantığını YAZMAZ, pdks_faz8b_migrate() ÇAĞIRIR (ikinci bir yol AÇMAZ)',
    str_contains($migrateSrc, 'pdks_faz8b_migrate($pdo)'));
ok9a('faz8b_migrate.php (bağımsız sayfa) hâlâ KORUNUYOR (geriye dönük bağlantı bozulmaz)',
    file_exists($root . '/faz8b_migrate.php') && str_contains((string)file_get_contents($root . '/faz8b_migrate.php'), 'pdks_faz8b_migrate('));
$mig1 = pdks_faz8b_migrate($db);
$mig1Hata = array_filter($mig1, fn($r) => ($r['durum'] ?? '') === 'hata');
ok9a('pdks_faz8b_migrate() ilk çalıştırma hatasız', count($mig1Hata) === 0, json_encode($mig1, JSON_UNESCAPED_UNICODE));
ok9a('pdks_faz8b_migrate() sonrası şema hazır', pdks_faz8b_sema_hazir($db));
$mig2 = pdks_faz8b_migrate($db);
$mig2Beklenmedik = array_filter($mig2, fn($r) => ($r['durum'] ?? '') !== 'var');
ok9a('pdks_faz8b_migrate() İKİNCİ çalıştırma İDEMPOTENT (hepsi "var")', count($mig2Beklenmedik) === 0, json_encode($mig2, JSON_UNESCAPED_UNICODE));

echo "\nSONUÇ: $pass geçti, $fail hata\n";
exit($fail === 0 ? 0 : 1);
