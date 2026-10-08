<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_saat_duzelt_smoke.php — v320 geçmiş fiyat döneminin saatlerini düzeltme.
//
// Bellek içi SQLite + GERÇEK fonksiyonlar (pdks_cavus_ucret_smoke.php harness'i);
// canlı DB'ye dokunmaz. Kapsam: geriye uyum (kolon yok / NULL → bugünkü tutarlar
// birebir), 8 / 9 / 10,5 / 11 / 12 / 13 saat (saatlik + sabit FM), FM bekliyor /
// reddedildi / kısmi onay, onaylı FM kırpma, Tam saati fiyat döneminden vs mesai
// snapshot'ından, Yarım saati yalnız bilgi, doğrulama hataları, tek sınıflandırıcı
// statik denetimi, Yöntem B kişi-gün, hakediş 'cift' satırı, Mesai Tanımı metni.
//
//   php scripts/pdks_saat_duzelt_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function okcy(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function active_depot(): ?string { return 'Depo A'; }
function can(string $p): bool { return true; }
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid, 'new' => $n];
}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';

// ── Şema (pdks_cavus_ucret_smoke.php ile aynı temel) ──
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('KARISIK','Karışık')");
$kadin = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$karisik = (int)$db->query("SELECT id FROM worker_types WHERE code='KARISIK'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");

$day = date('Y-m-d', strtotime('-3 days'));
for ($f = 1; $f <= 7; $f++) $db->exec("INSERT INTO foremen (id,code,name) VALUES ({$f},'C{$f}','Çavuş {$f}')");

/** Tek dönemli yeni mesai: 06:00'dan $dk dakika. Dönem id + mesai id döner. */
function mesaiKur(int $foremanId, int $dk, int $snap = 540, ?int $tip = null): array {
    global $db, $day, $kadin;
    static $n = 0; $n++;
    $tip = $tip ?? $kadin;
    $db->prepare("INSERT INTO daily_work_sessions (foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,'Depo A','closed')")
       ->execute([$foremanId, 'Çavuş ' . $foremanId, 'C' . $foremanId, $snap, $day]);
    $sid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO worker_cards (card_no) VALUES (?)")->execute(['K' . $n]);
    $kart = (int)$db->lastInsertId();
    $g = strtotime("{$day} 06:00:00");
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status) VALUES (?,?,?,?,?,?,'closed')")
       ->execute([$sid, $kart, $tip, $tip === $kadin ? 'Kadın' : 'Karışık', date('Y-m-d H:i:s', $g), date('Y-m-d H:i:s', $g + $dk * 60)]);
    return [(int)$db->lastInsertId(), $sid];
}
function sinif(int $pid): array { global $db; return pdks_faz8b_donem_getir($pid, $db)['faz8b']; }
/** Değerlendirme (gerekirse) + hakediş; [faz8b, hakedis] döner. $fm: null = adayın tamamı, 'yok' = gönderme. */
function hesapla(int $pid, int $sid, $fm = null, ?string $sinifKarar = null): array {
    global $db;
    $f = sinif($pid);
    if ($f['sinif_onayi_gerekli'] || (int)$f['fazla_mesai_saat'] > 0) {
        if ($fm !== 'yok') {
            $saat = (int)$f['fazla_mesai_saat'] > 0 ? ($fm === null ? (int)$f['fazla_mesai_saat'] : (int)$fm) : null;
            $r = pdks_faz8b_degerlendirme_kaydet($pid, $f['sinif_onayi_gerekli'] ? ($sinifKarar ?? 'tam') : null, $saat, 1, $db);
            if (!$r['ok']) return [$f, ['ok' => false, 'hata' => 'değerlendirme: ' . $r['hata']]];
        }
    }
    return [sinif($pid), pdks_faz8b_hakedis_hesapla($sid, 1, $db)];
}
function tutar(array $h): string { return ($h['ok'] ?? false) ? (string)$h['total_amount'] : 'HATA:' . ($h['hata'] ?? '?'); }
function satir(array $h): array { return ($h['lines'] ?? [])[0] ?? []; }

// =========================================================
echo "=== S. SAATLERİ DÜZELT (pdks_faz8b_oran_saat_duzelt) ===\n";
$r = pdks_faz8b_oran_saat_duzelt(1, ['full_day' => '9'], 'test', 1, $db);
okcy('S0) saat kolonları yokken reddedilir', $r['ok'] === false);
pdks_faz8b_saat_kolonlari_migrate($db);
okcy('S0b) migrate', pdks_faz8b_saat_kolonlari_hazir($db) === true);

// Senaryo: önce yanlışlıkla 10 sa girildi (600 dk), sonra 9'a çekilmek istendi.
$r = pdks_faz8b_oran_ekle(1, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db, ['full_day' => '10']);
okcy('S1) 10 saatlik dönem eklenir', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$rateId = (int)$db->query("SELECT id FROM foreman_worker_rates WHERE foreman_id=1")->fetchColumn();
okcy('S1b) dönem saati 600 dk', (int)$db->query("SELECT full_day_minutes FROM foreman_worker_rates WHERE id=$rateId")->fetchColumn() === 600);

[$pid, $sid] = mesaiKur(1, 660); // 11 saat
$f = sinif($pid);
okcy('S2) 10 sa dönemde 11 sa → 1 FM', (int)$f['fazla_mesai_saat'] === 1, json_encode($f, JSON_UNESCAPED_UNICODE));
[$f2, $h] = hesapla($pid, $sid);
okcy('S2b) taslak hakediş hesaplandı', ($h['ok'] ?? false) === true, tutar($h));
$db->exec("UPDATE foreman_daily_entitlements SET needs_recalculation = 0");

$AUDIT = [];
$r = pdks_faz8b_oran_saat_duzelt($rateId, ['full_day' => '9'], '   ', 1, $db);
okcy('S3) boş gerekçe reddedilir', $r['ok'] === false && str_contains($r['hata'], 'gerekçe'));
$r = pdks_faz8b_oran_saat_duzelt(9999, ['full_day' => '9'], 'x', 1, $db);
okcy('S4) olmayan dönem reddedilir', $r['ok'] === false);
$r = pdks_faz8b_oran_saat_duzelt($rateId, ['full_day' => '30'], 'x', 1, $db);
okcy('S5) geçersiz saat reddedilir (değişmez)', $r['ok'] === false && (int)$db->query("SELECT full_day_minutes FROM foreman_worker_rates WHERE id=$rateId")->fetchColumn() === 600);

$r = pdks_faz8b_oran_saat_duzelt($rateId, ['full_day' => '9'], 'Tam saati yanlış girilmişti', 1, $db);
okcy('S6) 9 saate düzeltilir', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
okcy('S6b) kolon 540 dk', (int)$db->query("SELECT full_day_minutes FROM foreman_worker_rates WHERE id=$rateId")->fetchColumn() === 540);
okcy('S6c) ücretler değişmedi', (float)$db->query("SELECT daily_rate FROM foreman_worker_rates WHERE id=$rateId")->fetchColumn() === 1000.0
    && (float)$db->query("SELECT overtime_rate FROM foreman_worker_rates WHERE id=$rateId")->fetchColumn() === 150.0);
okcy('S6d) taslak yeniden hesaba işaretlendi', (int)$r['isaretlenen'] === 1
    && (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=$sid")->fetchColumn() === 1);
$f = sinif($pid);
okcy('S7) aynı geçmiş gün artık 11 sa → 2 FM', (int)$f['fazla_mesai_saat'] === 2, json_encode($f, JSON_UNESCAPED_UNICODE));
$oz = pdks_faz8b_gun_mesai_ozeti([$sid], $db);
okcy('S7b) Mesai Özeti 2. Saat sütununda', (int)($oz['tanim'][$kadin]['fm'][2] ?? $oz['tanim']['Kadın']['fm'][2] ?? -1) === 1 || str_contains(json_encode($oz), '"2":1'), json_encode($oz['tanim'] ?? null, JSON_UNESCAPED_UNICODE));
$au = array_values(array_filter($AUDIT, fn($a) => $a['action'] === 'saat_duzelt'));
okcy('S8) audit saat_duzelt + gerekçe', count($au) === 1 && $au[0]['module'] === 'foreman_worker_rates' && ($au[0]['new']['gerekce'] ?? '') === 'Tam saati yanlış girilmişti');

// Boş = çavuş süresi (NULL)
$r = pdks_faz8b_oran_saat_duzelt($rateId, ['full_day' => ''], 'boşalt', 1, $db);
okcy('S9) boş bırakılınca NULL', $r['ok'] === true && $db->query("SELECT full_day_minutes FROM foreman_worker_rates WHERE id=$rateId")->fetchColumn() === null);

// Kesin hakediş varsa reddeder
$db->exec("UPDATE foreman_daily_entitlements SET status='final' WHERE session_id=$sid");
$r = pdks_faz8b_oran_saat_duzelt($rateId, ['full_day' => '10'], 'x', 1, $db);
okcy('S10) kesin hakedişli dönem düzeltilemez', $r['ok'] === false && str_contains($r['hata'], 'kesinleşmiş'));
okcy('S10b) kolon değişmedi', $db->query("SELECT full_day_minutes FROM foreman_worker_rates WHERE id=$rateId")->fetchColumn() === null);

// Sayfa: POST dalı + kapılar
$src = (string)file_get_contents($root . '/cavus_fiyatlari.php');
$dal = substr($src, (int)strpos($src, "=== 'saat_duzelt'"), 1500);
okcy('S11) sayfa dalı CSRF + rates yetkisi', str_contains($dal, 'csrf_check') && str_contains($dal, "require_pdks_hakedis('rates')"));
okcy('S12) dönem çavuşa ait mi denetlenir', str_contains($dal, '(int)$rateCavus !== $cavusId'));
okcy('S13) Fiyat Geçmişi düğmesi + pencere', str_contains($src, 'data-cf-saat-duzelt') && str_contains($src, 'id="cfSaatDuzelt"'));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
