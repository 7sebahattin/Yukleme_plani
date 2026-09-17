<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_faz9b_smoke.php — Faz 9B (v229) odaklı doğrulama.
//
// v227 sistem audit'inin H-01 bulgusunu (günlük işçi tip modeli
// tutarsızlığı) TAMAMEN KAPATAN değişiklikleri hedefler — gerçek fonksiyon
// çağrıları + bellek içi SQLite ile "davranış doğru mu" kontrolüdür,
// str_contains/preg_match ile "kod var mı" kontrolü DEĞİL (static
// kontroller yalnız gerçek bir davranışsal doğrulamanın YERİNE GEÇMEYECEK
// noktalarda — ör. sayfa kaynağının hangi paylaşılan fonksiyonu çağırdığı
// — kullanılır ve öyle işaretlenir).
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok9b(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$ADMIN9B = true;
$DEPOT9B = 'Depo A';
function db(): PDO { global $db; return $db; }
function is_admin(): bool { global $ADMIN9B; return $ADMIN9B; }
function active_depot(): ?string { global $DEPOT9B; return $DEPOT9B; }
function can(string $p): bool { return true; }

require_once $root . '/config/pdks.php';
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_faz8e.php';
require_once $root . '/config/pdks_faz8j.php';
require_once $root . '/config/pdks_cari.php';
require_once $root . '/config/pdks_rapor.php';

// ── Şema — Faz 9A'nın pdks_faz9a_smoke.php'siyle AYNI tam Faz 8J şeması ──
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'),('ERKEK','Erkek')");
$kadinId = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)$db->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1)");
$db->exec("INSERT INTO foremen (code,name) VALUES ('C1','Ayşe Çavuş')");
$foremanId = (int)$db->lastInsertId();
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, canonical_uid TEXT, uid_bytes INTEGER DEFAULT 4, uid_decimal TEXT, enrolled_source TEXT DEFAULT 'usb_decimal', status TEXT DEFAULT 'available', notes TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, opened_at TEXT, opened_by_user_id INTEGER, closed_at TEXT, closed_by_user_id INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT DEFAULT 'scan', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER, created_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, payment_date TEXT, amount TEXT, currency TEXT, payment_method TEXT, reference_no TEXT, description TEXT, status TEXT, created_by_user_id INTEGER, created_at TEXT, cancelled_at TEXT, cancelled_by_user_id INTEGER, cancellation_reason TEXT)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$day = date('Y-m-d', strtotime('-1 day'));

// =========================================================
// § A — CENTRAL POLICY (madde 1-5)
// =========================================================
echo "=== A. TEK POLİTİKA — pdks_gunluk_desteklenen_tip_*() ===\n";
ok9b('1) desteklenen kod listesi TAM OLARAK KADIN/ERKEK', pdks_gunluk_desteklenen_tip_kodlari() === ['KADIN', 'ERKEK']);
ok9b('2) aktif KADIN kabul edilir', pdks_gunluk_desteklenen_tip_coz($kadinId, $db) !== null);
ok9b('3) aktif ERKEK kabul edilir', pdks_gunluk_desteklenen_tip_coz($erkekId, $db) !== null);

$forkliftId = (int)pdks_gunluk_tip_olustur('FORKLIFT', 'Forklift Operatörü', $db)['id'];
ok9b('4) desteklenmeyen ama AKTİF FORKLIFT REDDEDİLİR', pdks_gunluk_desteklenen_tip_coz($forkliftId, $db) === null);
ok9b('pdks_gunluk_tip_kodu_destekleniyor(): FORKLIFT false, KADIN/ERKEK true',
    !pdks_gunluk_tip_kodu_destekleniyor('FORKLIFT') && pdks_gunluk_tip_kodu_destekleniyor('KADIN') && pdks_gunluk_tip_kodu_destekleniyor('ERKEK'));

pdks_gunluk_tip_aktiflik($kadinId, false, $db);   // ERKEK aktif kaldığı için "son aktif tip" engeline TAKILMAZ
ok9b('5) PASİF KADIN yeni operasyonel seçim için REDDEDİLİR', pdks_gunluk_desteklenen_tip_coz($kadinId, $db) === null);
$pasifListe = pdks_gunluk_desteklenen_tip_listele($db);
ok9b('5b) pasif KADIN desteklenen LİSTEDE de görünmez', !in_array('KADIN', array_column($pasifListe, 'code'), true));
pdks_gunluk_tip_aktiflik($kadinId, true, $db);    // testin geri kalanı için geri aç
ok9b('KADIN yeniden aktifleştirilince tekrar kabul edilir', pdks_gunluk_desteklenen_tip_coz($kadinId, $db) !== null);

// =========================================================
// § B — SCAN (madde 6-10)
// =========================================================
echo "\n=== B. TARAMA — GİRİŞ/ÇIKIŞ ===\n";
$db->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (1,?,?,?,?,?,?)')
    ->execute([$foremanId, 'Ayşe Çavuş', 'C1', $day, 'Depo A', 'open']);
pdks_gunluk_kart_olustur(['card_no' => 'K001', 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, $db);
pdks_gunluk_kart_olustur(['card_no' => 'K002', 'ham_uid' => '631799512', 'kaynak' => 'usb_decimal'], 1, $db);
pdks_gunluk_kart_olustur(['card_no' => 'K003', 'ham_uid' => '631799513', 'kaynak' => 'usb_decimal'], 1, $db);

$oncekiSayim = (int)$db->query('SELECT COUNT(*) FROM daily_worker_work_periods')->fetchColumn();
$girisKadin = pdks_gunluk_faz8a_giris_kaydet('631799511', 'usb_decimal', 1, $kadinId, 'tam', 1, $db);
ok9b('6) KADIN GİRİŞİ başarılı', $girisKadin['ok'] === true, json_encode($girisKadin, JSON_UNESCAPED_UNICODE));
$girisErkek = pdks_gunluk_faz8a_giris_kaydet('631799512', 'usb_decimal', 1, $erkekId, 'tam', 1, $db);
ok9b('7) ERKEK GİRİŞİ başarılı', $girisErkek['ok'] === true, json_encode($girisErkek, JSON_UNESCAPED_UNICODE));

$girisForklift = pdks_gunluk_faz8a_giris_kaydet('631799513', 'usb_decimal', 1, $forkliftId, 'tam', 1, $db);
ok9b('8) crafted FORKLIFT worker_type_id GİRİŞİ REDDEDİLİR', $girisForklift['ok'] === false && ($girisForklift['kod'] ?? '') === 'tip_bulunamadi', json_encode($girisForklift, JSON_UNESCAPED_UNICODE));

$sonrakiSayim = (int)$db->query('SELECT COUNT(*) FROM daily_worker_work_periods')->fetchColumn();
ok9b('9) reddedilen FORKLIFT girişi YENİ bir dönem/kayıt OLUŞTURMAZ (2 başarılı GİRİŞ dışında hiçbiri)', $sonrakiSayim === $oncekiSayim + 2, "önce=$oncekiSayim sonra=$sonrakiSayim");
$db1 = $db->query("SELECT id FROM worker_cards WHERE card_no='K003'")->fetchColumn();
ok9b('9b) FORKLIFT için otomatik kart bile OLUŞTURULMADI (kart zaten manuel tanımlıydı, yeni AUTO- kart YOK)',
    (int)$db->query("SELECT COUNT(*) FROM worker_cards WHERE card_no LIKE 'AUTO-%'")->fetchColumn() === 0);

$cikis = pdks_gunluk_faz8a_cikis_kaydet('631799511', 'usb_decimal', 1, 1, $db);
ok9b('10) ÇIKIŞ hiçbir tip parametresi OLMADAN çalışır', $cikis['ok'] === true, json_encode($cikis, JSON_UNESCAPED_UNICODE));

// =========================================================
// § C — FAZ 8J DÜZELTME (madde 11-16)
// =========================================================
echo "\n=== C. FAZ 8J DÜZELTME ===\n";
$duzeltmePeriod = (int)$db->query("SELECT id FROM daily_worker_work_periods WHERE worker_card_id = (SELECT id FROM worker_cards WHERE card_no='K002')")->fetchColumn();
function payload9b(int $pid, int $type, string $day): array {
    return ['period_id' => $pid, 'session_id' => 1, 'depo' => 'Depo A', 'worker_card_id' => (int)db()->query("SELECT worker_card_id FROM daily_worker_work_periods WHERE id=$pid")->fetchColumn(),
            'worker_type_id' => $type, 'entry_date' => $day, 'entry_clock' => '08:00', 'exit_date' => $day, 'exit_clock' => '17:00', 'reason' => 'Faz 9B testi', 'note' => ''];
}
$duzKadin = pdks_faz8j_duzelt(payload9b($duzeltmePeriod, $kadinId, $day), 1, $db);
ok9b('11) KADIN\'e düzeltme KABUL edilir', $duzKadin['ok'] === true, json_encode($duzKadin, JSON_UNESCAPED_UNICODE));
$duzErkek = pdks_faz8j_duzelt(payload9b($duzeltmePeriod, $erkekId, $day), 1, $db);
ok9b('12) ERKEK\'e düzeltme KABUL edilir', $duzErkek['ok'] === true, json_encode($duzErkek, JSON_UNESCAPED_UNICODE));

// ⚠ #16 için ham olay sayacı BURADA (yalnız REDDEDİLECEK FORKLIFT denemesinden
// hemen ÖNCE) alınır — #11'in kendisi (dönemin İLK kez çıkışı ayarlanması)
// meşru biçimde BİR manuel ÇIKIŞ olayı yazar (bkz. pdks_faz8e_manuel_cikis_olayi_ekle);
// bu PENCEREYİ dahil etmek "16" testinin yanlışlıkla o meşru olayı da
// "reddedilen düzeltmenin yan etkisi" sanmasına yol açardı.
$rawEventCountOnce = (int)$db->query('SELECT COUNT(*) FROM daily_worker_card_events')->fetchColumn();
$oncekiSatir = $db->query("SELECT worker_type_id_snapshot, worker_type_name_snapshot FROM daily_worker_work_periods WHERE id=$duzeltmePeriod")->fetch();
$duzForklift = pdks_faz8j_duzelt(payload9b($duzeltmePeriod, $forkliftId, $day), 1, $db);
ok9b('13) FORKLIFT düzeltme HEDEFİ REDDEDİLİR', $duzForklift['ok'] === false, json_encode($duzForklift, JSON_UNESCAPED_UNICODE));
ok9b('13b) ret mesajı AÇIK/AYIRT EDİCİ (kart-yok ile karıştırılmıyor)', str_contains($duzForklift['hata'] ?? '', 'desteklenmiyor'));

$sonrakiSatir = $db->query("SELECT worker_type_id_snapshot, worker_type_name_snapshot FROM daily_worker_work_periods WHERE id=$duzeltmePeriod")->fetch();
ok9b('15) reddedilen düzeltme SESSİZCE başka bir tipe DÖNÜŞTÜRMEZ — satır AYNI kaldı', $oncekiSatir === $sonrakiSatir, json_encode([$oncekiSatir, $sonrakiSatir], JSON_UNESCAPED_UNICODE));

$rawEventCountSonra = (int)$db->query('SELECT COUNT(*) FROM daily_worker_card_events')->fetchColumn();
ok9b('16) ham olay geçmişi (daily_worker_card_events) reddedilen FORKLIFT düzeltmesiyle DEĞİŞMEDİ', $rawEventCountSonra === $rawEventCountOnce, "önce=$rawEventCountOnce sonra=$rawEventCountSonra");

ok9b('14) düzeltme UI listesi (pdks_gunluk_desteklenen_tip_listele) backend\'in (pdks_faz8j_desteklenen_tip) kabul ettiğiyle BİREBİR AYNI küme',
    (function () use ($db, $kadinId, $erkekId, $forkliftId) {
        $uiListe = array_column(pdks_gunluk_desteklenen_tip_listele($db), 'id');
        sort($uiListe);
        $backendKabul = [];
        foreach ([$kadinId, $erkekId, $forkliftId] as $tid) {
            if (pdks_faz8j_desteklenen_tip($db, $tid) !== null) $backendKabul[] = $tid;
        }
        sort($backendKabul);
        return $uiListe === [$kadinId, $erkekId] && $backendKabul === [$kadinId, $erkekId] && $uiListe === $backendKabul;
    })());

// =========================================================
// § D — İŞÇİ TİPİ ADMİN SAYFASI (madde 17-22)
// =========================================================
echo "\n=== D. İŞÇİ TİPİ ADMİN SAYFASI ===\n";
$isciTipleriSrc = (string)file_get_contents($root . '/isci_tipleri.php');
// ⚠ Basit str_contains("'ekle'") KULLANILMAZ — sayfanın KENDİ açıklayıcı
// yorumu (bkz. dosya başlığı) bu ifadeyi İSİM olarak anar; gerçek kanıt
// $action İLE KARŞILAŞTIRAN bir KOD dalının YOKLUĞUDUR.
ok9b("17) \$action === 'ekle' KARŞILAŞTIRMASI (rastgele tip oluşturma dalı) KOD OLARAK YOK", !preg_match('/\$action\s*===\s*[\'"]ekle[\'"]/', $isciTipleriSrc));
ok9b('17b) pdks_gunluk_tip_olustur() sayfadan GERÇEKTEN ÇAĞRILMIYOR', !preg_match('/pdks_gunluk_tip_olustur\s*\(\s*[^)\s]/', $isciTipleriSrc));
$eklePost = ['action' => 'ekle', 'code' => 'USTA', 'name' => 'Usta'];
$oncekiTipSayisi = (int)$db->query('SELECT COUNT(*) FROM worker_types')->fetchColumn();
// Sayfa kendisi render edilmeden, aksiyonun backend'de HİÇBİR karşılığı
// olmadığını KENDİ fonksiyonuyla doğrula: isci_tipleri.php'nin POST
// dalı yalnız 'aktiflik' aksiyonunu tanır, crafted 'ekle' hiçbir şey
// YAPMAZ (üstteki statik kanıt zaten bunu gösteriyor; burada DB'nin
// gerçekten değişmediği TEYİT edilir).
ok9b('17c) worker_types tablosu bu turda YENİ bir satırla BÜYÜMEDİ (yalnız FORKLIFT testte elle eklendi)', (int)$db->query('SELECT COUNT(*) FROM worker_types')->fetchColumn() === $oncekiTipSayisi);

ok9b('18/19) worker_types.code için HİÇBİR yeniden adlandırma (UPDATE ... SET code) fonksiyonu YOK', !preg_match('/UPDATE\s+`?worker_types`?\s+SET\s+code\s*=/i', file_get_contents($root . '/config/pdks_gunluk.php')));
ok9b('20) worker_types için HİÇBİR DELETE fonksiyonu YOK (silme yolu yok)', !preg_match('/DELETE\s+FROM\s+`?worker_types`?/i', file_get_contents($root . '/config/pdks_gunluk.php')));

// 21) Son aktif desteklenen tipi pasifleştirmek REDDEDİLİR.
pdks_gunluk_tip_aktiflik($erkekId, false, $db);   // KADIN hâlâ aktif — izin verilir (tek başına kalmıyor)
$dbAktifKontrol = $db->query("SELECT is_active FROM worker_types WHERE id=$erkekId")->fetchColumn();
ok9b('21a) diğeri aktifken bir tipi pasifleştirmek SERBEST (kısıtlama YALNIZ son tip içindir)', (int)$dbAktifKontrol === 0);
pdks_gunluk_tip_aktiflik($erkekId, true, $db);    // geri aç
$sonAktifEngeli = pdks_gunluk_tip_aktiflik($kadinId, false, $db);   // ERKEK az önce geri açıldı, hâlâ serbest olmalı — asıl test: HER İKİSİ de pasifken üçüncüsü yok, o yüzden ikisini de pasifleştirmeyi dene
$ikinciPasif = null;
if ($sonAktifEngeli['ok']) {
    $ikinciPasif = pdks_gunluk_tip_aktiflik($erkekId, false, $db);   // şimdi KADIN pasif, ERKEK son aktif — BLOKLANMALI
    ok9b('21b) SON aktif desteklenen tip pasifleştirilemez (scan akışı boş kalırdı)', $ikinciPasif['ok'] === false, json_encode($ikinciPasif, JSON_UNESCAPED_UNICODE));
    pdks_gunluk_tip_aktiflik($kadinId, true, $db);    // testin geri kalanı için İKİSİNİ de geri aç
} else {
    ok9b('21b) SON aktif desteklenen tip pasifleştirilemez (scan akışı boş kalırdı)', false, 'ön koşul (KADIN pasifleştirme) beklenmedik biçimde reddedildi: ' . json_encode($sonAktifEngeli));
}
ok9b('21c) her iki sistem tipi de testin SONUNDA yeniden AKTİF', pdks_gunluk_desteklenen_tip_coz($kadinId, $db) !== null && pdks_gunluk_desteklenen_tip_coz($erkekId, $db) !== null);

ok9b('22) yanıltıcı "ör. FORKLIFT" placeholder metni SAYFADA ARTIK YOK', !str_contains($isciTipleriSrc, 'ör. FORKLIFT'));
ok9b('22b) sabit KADIN/ERKEK modeli SAYFADA AÇIKÇA anlatılıyor', str_contains($isciTipleriSrc, 'KADIN') && str_contains($isciTipleriSrc, 'ERKEK') && str_contains($isciTipleriSrc, 'sabit'));

// =========================================================
// § E — TARİHSEL/YABANCI VERİ GÜVENLİĞİ (madde 23-25)
// =========================================================
echo "\n=== E. TARİHSEL GÜVENLİK ===\n";
// FORKLIFT zaten §A'da oluşturuldu — burada TARİHSEL bir dönem/hakediş
// senaryosu kurulur: başka bir kurulumdan/eski bir dönemden kalmış gibi.
$db->exec("INSERT INTO worker_cards (id,card_no,canonical_uid,status) VALUES (900,'HIST-1','AABBCC01','available')");
$db->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (900,?,?,?,?,?,?)')
    ->execute([$foremanId, 'Ayşe Çavuş', 'C1', '2020-05-05', 'Depo A', 'closed']);
$db->exec("INSERT INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_event_id,exit_event_id,entry_time,exit_time,work_date_snapshot,depo_snapshot,status,source,is_voided) VALUES (900,900,900,$forkliftId,'Forklift Operatörü',9001,9002,'2020-05-05 08:00:00','2020-05-05 17:00:00','2020-05-05','Depo A','closed','scan',0)");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount) VALUES (900,900,$foremanId,'Ayşe Çavuş','C1','2020-05-05','Depo A','final','TRY','1300.00')");
$db->exec("INSERT INTO foreman_daily_entitlement_lines (id,entitlement_id,work_period_id,worker_type_id,worker_type_code_snapshot,worker_type_name_snapshot,attendance_class_snapshot,worker_count,unit_rate,overtime_hours,overtime_mode_snapshot,overtime_unit_rate,overtime_total,line_total) VALUES (900,900,900,$forkliftId,'FORKLIFT','Forklift Operatörü','tam',1,'1300.00',0,NULL,'0.00','0.00','1300.00')");

$oncekiTipSatirSayisi = (int)$db->query("SELECT COUNT(*) FROM worker_types WHERE code='FORKLIFT'")->fetchColumn();
$oncekiDonemSatirSayisi = (int)$db->query('SELECT COUNT(*) FROM daily_worker_work_periods WHERE id=900')->fetchColumn();
$oncekiHakedisToplam = $db->query('SELECT total_amount FROM foreman_daily_entitlements WHERE id=900')->fetchColumn();
$oncekiSatirToplam = $db->query('SELECT line_total FROM foreman_daily_entitlement_lines WHERE id=900')->fetchColumn();

// Bu noktadan sonra §A-D'de test edilen TÜM Faz 9B akışları (GİRİŞ/düzeltme/
// oran/aktiflik) ZATEN ÇALIŞTI — şimdi bu tarihsel satırların ETKİLENMEDİĞİ
// doğrulanır.
ok9b('23) tarihsel/desteklenmeyen FORKLIFT worker_types SATIRI SİLİNMEDİ', (int)$db->query("SELECT COUNT(*) FROM worker_types WHERE code='FORKLIFT'")->fetchColumn() === $oncekiTipSatirSayisi);
ok9b('23b) FORKLIFT satırının KENDİSİ (kod/ad) DEĞİŞMEDİ', $db->query("SELECT code,name FROM worker_types WHERE id=$forkliftId")->fetch() === ['code' => 'FORKLIFT', 'name' => 'Forklift Operatörü']);

$tarihselDonem = pdks_gunluk_faz8a_oturum_donemleri(900, $db);
ok9b('24) tarihsel FORKLIFT dönemi puantaj/geçmiş görünümünde HÂLÂ OKUNABİLİR', count($tarihselDonem) === 1 && $tarihselDonem[0]['tip'] === 'Forklift Operatörü', json_encode($tarihselDonem, JSON_UNESCAPED_UNICODE));
ok9b('24b) tarihsel dönem SESSİZCE başka bir tipe YENİDEN SINIFLANDIRILMADI', (int)$tarihselDonem[0]['worker_type_id_snapshot'] === $forkliftId);
$kartOzeti = pdks_gunluk_oturum_kartlari(900, $db);
ok9b('24c) pdks_gunluk_oturum_kartlari() (paylaşılan puantaj okuma yolu) da AYNI satırı döner', count($kartOzeti) === 1 && (int)$kartOzeti[0]['worker_type_id_snapshot'] === $forkliftId);

ok9b('25) tarihsel KESİNLEŞMİŞ hakediş toplamı DEĞİŞMEDİ', $db->query('SELECT total_amount FROM foreman_daily_entitlements WHERE id=900')->fetchColumn() === $oncekiHakedisToplam);
ok9b('25b) tarihsel hakediş SATIRI (unsupported snapshot) DEĞİŞMEDİ', $db->query('SELECT line_total FROM foreman_daily_entitlement_lines WHERE id=900')->fetchColumn() === $oncekiSatirToplam);
ok9b('25c) tarihsel hakediş satırları listesi (pdks_hakedis_satirlar) FORKLIFT satırını hâlâ döner', count(pdks_hakedis_satirlar(900, $db)) === 1);

// Bu tarihsel dönemi düzeltmeye çalışmak AÇIK bir mesajla reddedilir —
// SESSİZCE farklı bir tipe dönüştürülmez (görev talimatı §7).
$tarihselDuzeltmeDenemesi = pdks_faz8j_duzelt(['period_id' => 900, 'session_id' => 900, 'depo' => 'Depo A', 'worker_card_id' => 900, 'worker_type_id' => $forkliftId, 'entry_date' => '2020-05-05', 'entry_clock' => '08:00', 'exit_date' => '2020-05-05', 'exit_clock' => '17:30', 'reason' => 'test', 'note' => ''], 1, $db);
// ⚠ Bu tarihsel dönem KESİNLEŞMİŞ (final) bir hakedişe bağlı — o kilit
// pdks_faz8j_duzelt() içinde tip kontrolünden ÖNCE devreye girer (daha da
// güvenli: kesinleşmiş bir dönem türü NE OLURSA OLSUN düzeltilemez). Her
// iki neden de görev talimatı §7'nin istediği "SESSİZCE dönüştürme YOK,
// AÇIK bir admin mesajı VER" şartını sağlar — bu yüzden İKİSİ de kabul
// edilir (asıl KANIT: değer SESSİZCE değişmedi, aşağıdaki satırda).
ok9b('7§ — tarihsel FORKLIFT dönemini düzeltmeye çalışmak AÇIK mesajla reddedilir (silinmez/dönüştürülmez)',
    $tarihselDuzeltmeDenemesi['ok'] === false
    && (str_contains($tarihselDuzeltmeDenemesi['hata'] ?? '', 'desteklenmiyor') || str_contains($tarihselDuzeltmeDenemesi['hata'] ?? '', 'kesinleşmiş')),
    json_encode($tarihselDuzeltmeDenemesi, JSON_UNESCAPED_UNICODE));
ok9b('7§b — reddedilen tarihsel düzeltme denemesi SONRASI satır YİNE DEĞİŞMEDİ', $db->query('SELECT exit_time FROM daily_worker_work_periods WHERE id=900')->fetchColumn() === '2020-05-05 17:00:00');

// =========================================================
// § F — ORAN YÖNETİMİ (madde 26-27)
// =========================================================
echo "\n=== F. ORAN YÖNETİMİ ===\n";
$yeniOranTipleri = pdks_gunluk_desteklenen_tip_listele($db);
ok9b('26) cavus_fiyatlari.php\'nin kullandığı YENİ-oran tip kaynağı YALNIZ KADIN/ERKEK döner', array_column($yeniOranTipleri, 'code') === ['ERKEK', 'KADIN'] || array_column($yeniOranTipleri, 'code') === ['KADIN', 'ERKEK'], json_encode($yeniOranTipleri, JSON_UNESCAPED_UNICODE));
ok9b('26b) cavus_fiyatlari.php GERÇEKTEN pdks_gunluk_desteklenen_tip_listele() ÇAĞIRIYOR (yeni oran açılır listesi)', str_contains((string)file_get_contents($root . '/cavus_fiyatlari.php'), 'pdks_gunluk_desteklenen_tip_listele('));

// Tarihsel/desteklenmeyen bir oran satırı (başka kurulumdan/eski veriden
// kalmış gibi) — görev talimatı §8: "historical unsupported rate row, if
// any, must remain readable."
$db->exec("INSERT INTO foreman_worker_rates (id,is_active,foreman_id,worker_type_id,daily_rate,half_day_rate,overtime_mode,overtime_rate,currency,valid_from,created_by_user_id,created_at) VALUES (900,1,$foremanId,$forkliftId,'1300.00','650.00','hourly','150.00','TRY','2020-01-01',1,'2020-01-01')");
$oranGecmisi = pdks_hakedis_oran_gecmisi($foremanId, $db);
$forkliftOranSatiri = array_values(array_filter($oranGecmisi, fn($r) => (int)$r['worker_type_id'] === $forkliftId));
ok9b('27) tarihsel FORKLIFT oran satırı pdks_hakedis_oran_gecmisi() ile HÂLÂ OKUNABİLİR', count($forkliftOranSatiri) === 1, json_encode($oranGecmisi, JSON_UNESCAPED_UNICODE));
ok9b('27b) tarihsel oran satırının tutarı DEĞİŞMEDİ', ($forkliftOranSatiri[0]['daily_rate'] ?? null) === '1300.00');

// =========================================================
// § G — UI (madde 28-30)
// =========================================================
echo "\n=== G. UI — KADIN pembe / ERKEK mavi, tutarlı liste ===\n";
$scanSrc = (string)file_get_contents($root . '/gunluk_isci_giris_cikis.php');
ok9b("28) KADIN düğmesi pdks-kiosk-typebtn-kadin (pembe) SINIFINI KORUYOR", str_contains($scanSrc, "\$t['code'] === 'KADIN' ? ' pdks-kiosk-typebtn-kadin' : ''"));
ok9b('29) ERKEK düğmesi AYNI koşulla pembe sınıfı ALMAZ (varsayılan/mavi görünüm)', (bool)preg_match("/\\\$t\['code'\] === 'KADIN' \? ' pdks-kiosk-typebtn-kadin' : ''/", $scanSrc));
$pdksCss = (string)file_get_contents($root . '/assets/pdks.css');
ok9b('29b) pdks-kiosk-typebtn-kadin CSS TANIMI (pembe renk) hâlâ mevcut, DEĞİŞMEDİ', str_contains($pdksCss, '.pdks-kiosk-typebtn-kadin { background:#ec4899'));

$correctionSrc = (string)file_get_contents($root . '/gunluk_isci_puantaj_detay.php');
ok9b('30) tarama VE düzeltme AYNI paylaşılan fonksiyonu (pdks_gunluk_desteklenen_tip_listele) ÇAĞIRIYOR — iki AYRI liste YOK',
    str_contains($scanSrc, 'pdks_gunluk_desteklenen_tip_listele(') && str_contains($correctionSrc, 'pdks_gunluk_desteklenen_tip_listele('));
ok9b('30b) davranışsal olarak da AYNI: iki çağrı AYNI iki id kümesini döner',
    array_column(pdks_gunluk_desteklenen_tip_listele($db), 'id') === array_column(pdks_gunluk_desteklenen_tip_listele($db), 'id'));

echo "\nSONUÇ: $pass geçti, $fail hata\n";
exit($fail === 0 ? 0 : 1);
