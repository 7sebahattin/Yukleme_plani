<?php
declare(strict_types=1);
// Tarihli çavuş çalışma süresi için gerçek fonksiyonlar + bellek içi SQLite.
// Çalıştırma: php scripts/pdks_cavus_sure_smoke.php
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function okcs(string $name, bool $value, string $detail = ''): void
{
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo 'FAIL ' . $name . ($detail !== '' ? ' :: ' . $detail : '') . "\n"; }
}

$db = new class('sqlite::memory:') extends PDO {
    // Reproduce a second request changing the contract between reading and
    // writing a calculation, and between calculation and finalization.
    public ?Closure $beforeTransaction = null;
    public ?Closure $beforeFinalize = null;
    public function beginTransaction(): bool {
        $hook = $this->beforeTransaction;
        $this->beforeTransaction = null;
        if ($hook) $hook();
        return parent::beginTransaction();
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->beforeFinalize && str_contains($query, "SET status = 'final'")) {
            $hook = $this->beforeFinalize;
            $this->beforeFinalize = null;
            $hook();
        }
        return parent::prepare($query, $options);
    }
};
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function can(string $permission): bool { global $CAN_TEST; return $CAN_TEST ?? true; }
function is_admin(): bool { global $ADMIN_TEST; return $ADMIN_TEST ?? true; }
function active_depot(): ?string { return 'Depo A'; }

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_cavus_sure.php';
require_once $root . '/config/pdks_faz8j.php';

$db->exec("CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT,
    record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY, code TEXT, name TEXT,
    normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY, foreman_id INTEGER,
    foreman_name_snapshot TEXT, foreman_code_snapshot TEXT,
    normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540,
    work_date TEXT, depo TEXT, status TEXT, opened_at TEXT, opened_by_user_id INTEGER,
    closed_at TEXT, closed_by_user_id INTEGER, notes TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, session_id INTEGER,
    foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT,
    work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT,
    needs_recalculation INTEGER NOT NULL DEFAULT 0, calculated_at TEXT,
    calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER,
    missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT,
    is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (id,code,name) VALUES (1,'KADIN','Kadın')");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY, card_no TEXT, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY, session_id INTEGER,
    worker_card_id INTEGER, event_type TEXT, worker_type_name_snapshot TEXT,
    server_event_time TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (
    id INTEGER PRIMARY KEY, session_id INTEGER, worker_card_id INTEGER,
    worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT,
    entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam',
    approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT,
    overtime_approved INTEGER, overtime_approved_by_user_id INTEGER,
    overtime_approved_at TEXT, overtime_approved_hours INTEGER,
    exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0,
    voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT
)");
$db->exec("CREATE TABLE foreman_worker_rates (
    id INTEGER PRIMARY KEY, is_active INTEGER DEFAULT 1, foreman_id INTEGER,
    worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT,
    overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT,
    created_by_user_id INTEGER, full_day_minutes INTEGER, half_day_max_minutes INTEGER,
    overtime_start_minutes INTEGER, double_day_minutes INTEGER, double_day_rate TEXT
)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (
    id INTEGER PRIMARY KEY, entitlement_id INTEGER, work_period_id INTEGER,
    worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT,
    attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT,
    overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT,
    overtime_total TEXT, line_total TEXT)");
$db->exec("INSERT INTO foremen (id,code,name,normal_work_minutes) VALUES
    (1,'C1','Çavuş Bir',600),(2,'C2','Çavuş İki',480),
    (3,'C3','Çavuş Üç',600),(4,'C4','Çavuş Dört',570)");

function sessionEkle(int $id, int $foreman, string $date, string $depo, int $snapshot, string $status = 'closed', ?string $entStatus = null): void
{
    global $db;
    $db->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $foreman, 'Çavuş ' . $foreman, 'C' . $foreman, $snapshot, $date, $depo, $status]);
    if ($entStatus !== null) {
        $db->prepare('INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $id, $foreman, 'Çavuş ' . $foreman, 'C' . $foreman, $date, $depo, $entStatus]);
    }
}
function saveSure(int $foreman, int $minutes, string $from, ?string $to, string $reason = 'Sözleşme güncellemesi', int $user = 7): array
{
    global $db;
    return pdks_cavus_sure_kaydet($foreman, $minutes, $from, $to, $reason, $user, $db);
}

echo "=== TARİHLİ ÇAVUŞ NORMAL ÇALIŞMA SÜRESİ ===\n";
okcs('şema hazır', pdks_cavus_sure_hazir($db));

// İki dönem: 1–15 Ocak 10 saat, 16 Ocak sonrası süresiz 9 saat.
sessionEkle(1, 1, '2026-01-01', 'Depo A', 600, 'closed', 'draft');
sessionEkle(2, 1, '2026-01-15', 'Depo B', 600, 'closed', 'draft');
sessionEkle(3, 1, '2026-01-16', 'Depo A', 600, 'closed', 'draft');
sessionEkle(4, 1, '2026-02-01', 'Depo B', 600, 'closed', 'draft');
sessionEkle(5, 1, '2025-12-31', 'Depo A', 600, 'closed', 'draft');
sessionEkle(6, 2, '2026-01-16', 'Depo A', 480, 'closed', 'draft');
$r = saveSure(1, 600, '2026-01-01', '2026-01-15', 'Ocak ilk yarı 10 saat');
okcs('1–15 Ocak dönemi kaydedildi', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = saveSure(1, 540, '2026-01-16', null, '16 Ocak sonrası 9 saat');
okcs('16 Ocak sonrası süresiz dönem kaydedildi', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
okcs('aralık başlangıcı ve bitişi dahildir; 1–15 = 600 dk', pdks_cavus_sure_dk(1, '2026-01-01', $db) === 600 && pdks_cavus_sure_dk(1, '2026-01-15', $db) === 600);
okcs('16 Ocak sonrası = 540 dk', pdks_cavus_sure_dk(1, '2026-01-16', $db) === 540 && pdks_cavus_sure_dk(1, '2026-08-01', $db) === 540);
okcs('dönem öncesi ilk canlı değer baseline olarak korunur', pdks_cavus_sure_dk(1, '2025-12-31', $db) === 600);
okcs('tarihli güncelleme foremen canlı değerini değiştirmez', (int)$db->query('SELECT normal_work_minutes FROM foremen WHERE id=1')->fetchColumn() === 600);
okcs('diğer çavuşun süresi yalıtılmıştır', pdks_cavus_sure_dk(2, '2026-01-16', $db) === 480);

// Sınırlı düzeltme snapshots/draft işaretlerini yalnız kapsanan günlerde değiştirir.
sessionEkle(7, 1, '2026-01-10', 'Depo C', 600, 'closed', 'draft');
sessionEkle(8, 1, '2026-01-20', 'Depo A', 540, 'closed', 'draft');
sessionEkle(9, 2, '2026-01-10', 'Depo A', 480, 'closed', 'draft');
sessionEkle(10, 1, '2026-01-12', 'Depo A', 600, 'closed', 'draft');
$db->exec('UPDATE foreman_daily_entitlements SET needs_recalculation=0');
$r = saveSure(1, 660, '2026-01-05', '2026-01-12', 'Geriye dönük düzeltme');
okcs('geriye dönük kayıtta kapsanan depo oturumları sayılır', $r['ok'] === true && (int)$r['oturum_sayisi'] === 2, json_encode($r, JSON_UNESCAPED_UNICODE));
okcs('yalnız kapsanan günlerin snapshot ve taslakları değişti',
    (int)$db->query('SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id=7')->fetchColumn() === 660
    && (int)$db->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=7')->fetchColumn() === 1
    && (int)$db->query('SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id=10')->fetchColumn() === 660
    && (int)$db->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=10')->fetchColumn() === 1
    && (int)$db->query('SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id=8')->fetchColumn() === 540
    && (int)$db->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=8')->fetchColumn() === 0
    && (int)$db->query('SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id=9')->fetchColumn() === 480);
okcs('güncel tarih için gelecek dönem bugünkü süreyi değiştirmez', pdks_cavus_sure_dk(1, date('Y-m-d'), $db) === 540);

// Üst üste binen dönemlerde en son eklenen olay kazanır; eski günler buna göre çözülür.
$r = saveSure(1, 720, '2026-01-08', '2026-01-10', 'Örtüşen son kayıt');
okcs('örtüşen yeni dönem kaydedildi', $r['ok'] === true);
okcs('örtüşme içinde son audit id kazanır', pdks_cavus_sure_dk(1, '2026-01-08', $db) === 720 && pdks_cavus_sure_dk(1, '2026-01-10', $db) === 720);
okcs('örtüşme dışındaki önceki dönem etkilenmez', pdks_cavus_sure_dk(1, '2026-01-07', $db) === 660 && pdks_cavus_sure_dk(1, '2026-01-11', $db) === 660);

// Gelecek başlangıç, bugün dahil önceki tarihlere sızmamalıdır.
$today = date('Y-m-d');
$future = date('Y-m-d', strtotime($today . ' +20 days'));
$r = saveSure(2, 600, $future, null, 'Gelecek tarihte 10 saat');
okcs('gelecek dönem kaydedildi', $r['ok'] === true);
okcs('gelecek dönem bugünü ve önceki tarihi değiştirmiyor', pdks_cavus_sure_dk(2, $today, $db) === 480 && pdks_cavus_sure_dk(2, $future, $db) === 600);

// Gerçek kiosk ve geriye dönük oturum çözücüsü tarihi baz alır.
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
$backDate = date('Y-m-d', strtotime($today . ' -3 days'));
$r = saveSure(4, 750, $today, $today, 'Bugünkü oturum için 12:30');
$kiosk = pdks_faz8j_oturum_coz($db, 4, $today, 'Depo A', 7, true);
okcs('kiosk yoluyla açılan bugünkü oturum scheduled 750 dk snapshot alır',
    $r['ok'] === true && $kiosk['ok'] === true && $kiosk['yeni'] === true
    && (int)$kiosk['oturum']['normal_work_minutes_snapshot'] === 750,
    json_encode($kiosk, JSON_UNESCAPED_UNICODE));
$r = saveSure(4, 660, $backDate, $backDate, 'Geriye dönük oturum için 11 saat');
$manual = pdks_faz8j_oturum_coz($db, 4, $backDate, 'Depo A', 7, true,
    ['acilis' => $backDate . ' 08:00:00', 'kapanis' => $backDate . ' 19:00:00', 'reason' => 'düzeltme']);
okcs('backdated manual oturum scheduled 660 dk snapshot alır',
    $r['ok'] === true && $manual['ok'] === true && $manual['yeni'] === true
    && (int)$manual['oturum']['normal_work_minutes_snapshot'] === 660,
    json_encode($manual, JSON_UNESCAPED_UNICODE));

// Final entitlement bütün yazmayı engeller.
sessionEkle(20, 1, '2026-03-02', 'Depo A', 540, 'closed', 'draft');
$db->exec("UPDATE foreman_daily_entitlements SET status='final' WHERE id=20");
$beforeAudit = (int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$r = saveSure(1, 720, '2026-03-01', '2026-03-05', 'Final kilidi');
okcs('final hakediş kapsayan tüm yazmayı engeller', $r['ok'] === false && (int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $beforeAudit
    && (int)$db->query('SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id=20')->fetchColumn() === 540,
    json_encode($r, JSON_UNESCAPED_UNICODE));

// Girdi doğrulaması ve audit arızasında rollback.
$checks = [
    'geçersiz takvim tarihi reddedilir' => saveSure(1, 540, '2026-02-30', null)['ok'] === false,
    'ters tarih aralığı reddedilir' => saveSure(1, 540, '2026-04-02', '2026-04-01')['ok'] === false,
    '1 saat altı süre reddedilir' => saveSure(1, 59, '2026-04-01', null)['ok'] === false,
    '24 saat üstü süre reddedilir' => saveSure(1, 1441, '2026-04-01', null)['ok'] === false,
    'boş gerekçe reddedilir' => saveSure(1, 540, '2026-04-01', null, '  ')['ok'] === false,
];
foreach ($checks as $name => $value) okcs($name, $value);
$db->exec("CREATE TRIGGER fail_sure_audit BEFORE INSERT ON audit_log
    WHEN NEW.action='normal_sure_donemi' AND (NEW.new_values LIKE '%Audit rollback%' OR NEW.new_values LIKE '%İç işlem audit hatası%')
    BEGIN SELECT RAISE(ABORT,'audit failure'); END");
$beforeSessions = (int)$db->query("SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id=1")->fetchColumn();
$beforeAudit = (int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$r = saveSure(1, 720, '2026-01-01', '2026-01-01', 'Audit rollback');
okcs('audit insert hatası audit, snapshot ve taslak değişikliğini rollback eder', $r['ok'] === false
    && (int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $beforeAudit
    && (int)$db->query('SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id=1')->fetchColumn() === $beforeSessions,
    json_encode($r, JSON_UNESCAPED_UNICODE));
// Savepoint, başarıda ve hatada çağıranın açık transaction'ını korur.
$db->exec('CREATE TABLE tx_probe (value TEXT)');
$db->beginTransaction();
$db->exec("INSERT INTO tx_probe VALUES ('outer write')");
$nestedOk = saveSure(4, 720, '2026-05-01', '2026-05-01', 'İç işlem başarılı');
$nestedFail = saveSure(4, 720, '2026-05-02', '2026-05-02', 'İç işlem audit hatası');
okcs('savepoint başarılı kayıt dış transactionı açık bırakır', $nestedOk['ok'] === true && $db->inTransaction()
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='normal_sure_donemi' AND record_id=4 AND new_values LIKE '%İç işlem başarılı%'")->fetchColumn() === 1);
okcs('savepoint hatası sadece iç değişikliği geri alır, dış yazı ve transaction sürer', $nestedFail['ok'] === false && $db->inTransaction()
    && (int)$db->query('SELECT COUNT(*) FROM tx_probe')->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='normal_sure_donemi' AND record_id=4 AND new_values LIKE '%İç işlem audit hatası%'")->fetchColumn() === 0);
$db->rollBack();
$db->exec('DROP TRIGGER fail_sure_audit');

// Eksik çavuş ve yetkisiz kullanıcı mevcut audit geçmişini değiştiremez.
$beforeAudit = (int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$missing = saveSure(999, 540, '2026-05-01', null, 'Olmayan çavuş');
$ADMIN_TEST = false; $CAN_TEST = false;
$denied = saveSure(1, 540, '2026-05-01', null, 'Yetkisiz değişiklik');
$ADMIN_TEST = true; $CAN_TEST = true;
okcs('olmayan çavuş reddedilir; history değişmez', $missing['ok'] === false
    && pdks_cavus_sure_gecmis(999, $db) === [] && (int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $beforeAudit);
okcs('yetki reddi history değişikliği yapmaz', $denied['ok'] === false
    && (int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $beforeAudit);

// Kayıt geçmişinde kullanıcı, geçerlilik aralığı ve gerekçe bulunmalı.
$hist = pdks_cavus_sure_gecmis(1, $db);
$first = array_values(array_filter($hist, fn($x) => $x['gerekce'] === 'Ocak ilk yarı 10 saat'))[0] ?? null;
okcs('audit geçmişi kullanıcı/tarih aralığı/gerekçeyi döndürür', $first !== null
    && (int)$first['user_id'] === 7 && $first['valid_from'] === '2026-01-01'
    && $first['valid_to'] === '2026-01-15' && $first['gerekce'] === 'Ocak ilk yarı 10 saat'
    && $first['created_at'] !== '');

// Legacy fiyat eşiği 600 dk olsa bile tarihli çavuş süresi 540 dk ortak sınıflandırıcıda kazanır.
$db->exec("INSERT INTO foreman_worker_rates
    (id,foreman_id,worker_type_id,daily_rate,half_day_rate,overtime_mode,overtime_rate,currency,valid_from,valid_to,is_active,full_day_minutes,overtime_start_minutes)
    VALUES (1,1,1,'1000.00','500.00','hourly','100.00','TRY','2026-01-01',NULL,1,600,600)");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status)
    VALUES (30,1,'Çavuş Bir','C1',540,'2026-01-20','Depo B','closed')");
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (30,'K030')");
$db->exec("INSERT INTO daily_worker_work_periods (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status)
    VALUES (30,30,30,1,'Kadın','2026-01-20 08:00:00','2026-01-20 19:00:00','closed')");
$period = pdks_faz8b_donem_getir(30, $db);
okcs('tarihli süre 540 dk, 11 saat çalışma = 2 FM; legacy fiyat tam/FM 600 dk ezilir',
    $period !== null && (int)$period['faz8b']['normal_dk'] === 540
    && (int)$period['faz8b']['fazla_mesai_saat'] === 2
    && (int)$period['faz8b']['fm_bas_dk'] === 540,
    json_encode($period['faz8b'] ?? null, JSON_UNESCAPED_UNICODE));

// Eski 600 dk kararına verilen onaylar yeni 540 dk eşiğinde sıfırlanır;
// yalnız seçili foreman+gün dönemi etkilenir. Güncel hakediş de yeni onayla yenilenir.
$calcDay = $yesterday;
$calcNext = date('Y-m-d', strtotime($calcDay . ' +1 day'));
$db->exec("INSERT INTO foreman_worker_rates
    (id,foreman_id,worker_type_id,daily_rate,half_day_rate,overtime_mode,overtime_rate,currency,valid_from,valid_to,is_active,full_day_minutes,overtime_start_minutes)
    VALUES (2,3,1,'1000.00','500.00','hourly','100.00','TRY','2020-01-01',NULL,1,600,600)");
$calcSession = 41;
$outsideSession = 42;
$otherForemanSession = 43;
sessionEkle($calcSession, 3, $calcDay, 'Depo A', 600, 'closed', 'draft');
sessionEkle($outsideSession, 3, $calcNext, 'Depo A', 600, 'closed', 'draft');
sessionEkle($otherForemanSession, 4, $calcDay, 'Depo B', 570, 'closed', 'draft');
$db->exec("INSERT INTO worker_cards (id,card_no) VALUES (41,'K041'),(42,'K042'),(43,'K043')");
$periodInsert = $db->prepare("INSERT INTO daily_worker_work_periods
    (id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,
     declared_attendance_class,approved_attendance_class,approved_by_user_id,approved_at,overtime_approved,
     overtime_approved_by_user_id,overtime_approved_at,overtime_approved_hours,status,is_voided)
    VALUES (?,?,?,1,'Kadın',?,?,'tam',?,?,?,1,?,?,1,'closed',0)");
foreach ([[$calcSession,$calcSession,41,3,$calcDay . ' 08:00:00',$calcDay . ' 19:00:00'],
          [$outsideSession,$outsideSession,42,3,$calcNext . ' 08:00:00',$calcNext . ' 19:00:00'],
          [$otherForemanSession,$otherForemanSession,43,4,$calcDay . ' 08:00:00',$calcDay . ' 19:00:00']] as [$pid,$sid,$card,$foreman,$entry,$exit]) {
    $periodInsert->execute([$pid,$sid,$card,$entry,$exit,'tam',7,'2026-01-01 12:00:00',7,'2026-01-01 12:00:00']);
}
$beforeChange = pdks_faz8b_donem_getir($calcSession, $db);
$oldApproval = pdks_faz8b_degerlendirme_kaydet($calcSession, null, 1, 7, $db);
$oldCalc = pdks_faz8b_hakedis_hesapla($calcSession, 7, $db);
okcs('600 dk için 1 FM onayı ve eski tutar gerçek hakedişte hesaplanır',
    $beforeChange !== null && (int)$beforeChange['faz8b']['fazla_mesai_saat'] === 1
    && $oldApproval['ok'] === true && $oldCalc['ok'] === true && $oldCalc['total_amount'] === '1100.00',
    json_encode($oldCalc, JSON_UNESCAPED_UNICODE));
$r = saveSure(3, 540, $calcDay, $calcDay, 'Yeni eşik: 9 saat');
$changed = pdks_faz8b_donem_getir($calcSession, $db);
$fresh = $db->query('SELECT approved_attendance_class,approved_by_user_id,approved_at,overtime_approved,overtime_approved_hours,overtime_approved_by_user_id,overtime_approved_at FROM daily_worker_work_periods WHERE id=' . $calcSession)->fetch();
$outOther = $db->query('SELECT overtime_approved_hours,approved_by_user_id FROM daily_worker_work_periods WHERE id=' . $outsideSession)->fetch();
$foremanOther = $db->query('SELECT overtime_approved_hours,approved_by_user_id FROM daily_worker_work_periods WHERE id=' . $otherForemanSession)->fetch();
okcs('seçilen dönemde Tam/FM onayları sıfırlanır, yeni 540 dk adayında 2 FM bekler',
    $r['ok'] === true && $changed !== null && (int)$changed['faz8b']['fazla_mesai_saat'] === 2
    && $changed['faz8b']['fazla_mesai_durum'] === 'bekliyor'
    && $fresh['approved_attendance_class'] === null && $fresh['approved_by_user_id'] === null
    && $fresh['approved_at'] === null && $fresh['overtime_approved'] === null
    && $fresh['overtime_approved_hours'] === null && $fresh['overtime_approved_by_user_id'] === null
    && $fresh['overtime_approved_at'] === null);
okcs('başka tarih/çavuşun eski onayları korunur',
    (int)$outOther['overtime_approved_hours'] === 1 && (int)$outOther['approved_by_user_id'] === 7
    && (int)$foremanOther['overtime_approved_hours'] === 1 && (int)$foremanOther['approved_by_user_id'] === 7);
$pendingCalc = pdks_faz8b_hakedis_hesapla($calcSession, 7, $db);
$approval2 = pdks_faz8b_degerlendirme_kaydet($calcSession, null, 2, 7, $db);
$newCalc = pdks_faz8b_hakedis_hesapla($calcSession, 7, $db);
$line31 = $db->query('SELECT unit_rate,overtime_unit_rate,overtime_hours,line_total FROM foreman_daily_entitlement_lines WHERE entitlement_id=' . $calcSession . ' AND work_period_id=' . $calcSession)->fetch();
okcs('yeni FM onayı öncesi hakediş bekler', $pendingCalc['ok'] === false);
okcs('2 FM yeniden onayı güncel toplamı üretir; sabit yevmiye/FM birim fiyatları korunur',
    $approval2['ok'] === true && $newCalc['ok'] === true && $oldCalc['total_amount'] === '1100.00'
    && $newCalc['total_amount'] === '1200.00' && $line31['unit_rate'] === '1000.00'
    && $line31['overtime_unit_rate'] === '100.00' && (int)$line31['overtime_hours'] === 2
    && $line31['line_total'] === '1200.00', json_encode($newCalc, JSON_UNESCAPED_UNICODE));

// Interleaved requests must not overwrite a newer duration's invalidation or
// finalize totals calculated before that change. Hooks use the actual save API.
$db->beforeTransaction = static function () use ($calcDay): void {
    $r = saveSure(3, 600, $calcDay, $calcDay, 'Hesap devam ederken sözleşme değişti');
    if (!$r['ok']) throw new RuntimeException('Race fixture save failed.');
};
$staleCalc = pdks_faz8b_hakedis_hesapla($calcSession, 7, $db);
okcs('hesap okunurken değişen süre eski toplamı yazamaz; taslak yeniden hesaplanmalı kalır',
    $staleCalc['ok'] === false && ($staleCalc['kod'] ?? '') === 'sure_degisti'
    && (int)$db->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=41')->fetchColumn() === 1
    && $db->query('SELECT total_amount FROM foreman_daily_entitlements WHERE id=41')->fetchColumn() === '1200.00');
pdks_faz8b_degerlendirme_kaydet($calcSession, null, 1, 7, $db);
$db->beforeFinalize = static function () use ($calcDay): void {
    $r = saveSure(3, 540, $calcDay, $calcDay, 'Kesinleştirme öncesi sözleşme değişti');
    if (!$r['ok']) throw new RuntimeException('Finalize race fixture save failed.');
};
$staleFinal = pdks_faz8b_hakedis_finalize($calcSession, 7, false, $db);
$afterRace = $db->query('SELECT status,needs_recalculation FROM foreman_daily_entitlements WHERE id=41')->fetch();
okcs('hesap ile kesinleştirme arasında süre değişirse eski toplam kesinleşmez',
    $staleFinal['ok'] === false && ($staleFinal['kod'] ?? '') === 'sure_degisti'
    && $afterRace['status'] === 'draft' && (int)$afterRace['needs_recalculation'] === 1);
pdks_faz8b_degerlendirme_kaydet($calcSession, null, 2, 7, $db);
$freshFinal = pdks_faz8b_hakedis_finalize($calcSession, 7, false, $db);
okcs('güncel süre yeniden onaylandıktan sonra doğru 1200 tutarı kesinleşir',
    $freshFinal['ok'] === true && $freshFinal['status'] === 'final' && $freshFinal['total_amount'] === '1200.00');
$protectedFinal = saveSure(3, 600, $calcDay, $calcDay, 'Kesin hesaptan sonra değişiklik');
okcs('yeni kesinleşmiş hakediş sonraki süre değişikliğinden korunur',
    $protectedFinal['ok'] === false && $db->query('SELECT total_amount FROM foreman_daily_entitlements WHERE id=41')->fetchColumn() === '1200.00');

// Opsiyonel audit tablosu olmayan eski kurulumda okuma/fallback güvenli kalır.
$fallback = new PDO('sqlite::memory:');
$fallback->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fallback->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY, normal_work_minutes INTEGER NOT NULL)");
$fallback->exec('INSERT INTO foremen VALUES (1,570)');
okcs('audit tablosu yoksa süre geçmişi boş ve canlı değer fallback olur',
    pdks_cavus_sure_hazir($fallback) === false && pdks_cavus_sure_gecmis(1, $fallback) === []
    && pdks_cavus_sure_dk(1, '2026-01-01', $fallback) === 570);

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
