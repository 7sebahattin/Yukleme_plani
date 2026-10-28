<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_oran_geri_cek_smoke.php — v455 en eski fiyat döneminin başlangıcını GEÇMİŞE çekme (+ geçmiş tarihli dönem → taslak işaretleme).
//
// Bellek içi SQLite + GERÇEK fonksiyonlar (pdks_saat_duzelt_smoke.php harness'i); canlı DB'ye dokunmaz.
// Kapsam: yalnız en eski dönem; önceki dönem varsa RED; eklenen aralıkta BU TİPİN final satırı varsa RED
// (başka tipin final'i engel DEĞİL); ücret/valid_to değişmez; taslaklar needs_recalculation; gerekçe zorunlu;
// gelecek / 2020 öncesi / eşit-ileri tarih RED; admin değilse RED; audit; oran_ekle geçmiş tarih → işaretleme;
// entegrasyon (bugünle açılan Şeritçi fiyatı → geçmiş mesai hesaplanamaz → geriye çek → hesaplanır).
//
//   php scripts/pdks_oran_geri_cek_smoke.php
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
$ADMIN = true;
function is_admin(): bool { global $ADMIN; return $ADMIN; }
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid, 'new' => $n];
}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';

// ── Şema (pdks_cavus_ucret_smoke.php ile aynı temel) ──
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('KARISIK','Karışık'), ('SERITCI','Şeritçi')");
$seritci = (int)$db->query("SELECT id FROM worker_types WHERE code='SERITCI'")->fetchColumn();
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
$bugun = date('Y-m-d');
$gun = fn(int $n): string => date('Y-m-d', strtotime("-{$n} days"));
function oranEkle(int $f, int $tip, string $ucret, string $baslangic): int {
    global $db;
    $db->prepare("INSERT INTO foreman_worker_rates (is_active, foreman_id, worker_type_id, daily_rate, half_day_rate, overtime_mode, overtime_rate, currency, valid_from, valid_to, created_by_user_id) VALUES (1,?,?,?,?,?,?,?,?,NULL,1)")
       ->execute([$f, $tip, $ucret, '500.00', 'hourly', '100.00', 'TRY', $baslangic]);
    return (int)$db->lastInsertId();
}
/** Belirli güne mesai + (taslak ya da final) hakediş kaydı + satır; satır tipi $satirTip. */
function hakedisKur(int $f, string $tarih, string $durum, int $satirTip, int $tipDonem): array {
    global $db, $day;
    $eski = $day; $day = $tarih;
    [$pid, $sid] = mesaiKur($f, 540, 540, $tipDonem);
    $day = $eski;
    $db->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status, currency, total_amount, needs_recalculation, calculated_at) VALUES (?,?,?,?,?,'TRY','0',0,?)")
       ->execute([$sid, $f, $tarih, 'Depo A', $durum, date('Y-m-d H:i:s')]);
    $eid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO foreman_daily_entitlement_lines (entitlement_id, work_period_id, worker_type_id, worker_type_code_snapshot, worker_type_name_snapshot, attendance_class_snapshot, worker_count, unit_rate, line_total) VALUES (?,?,?,?,?,'tam',1,'1000','1000')")
       ->execute([$eid, $pid, $satirTip, '', '']);
    return ['pid' => $pid, 'sid' => $sid, 'eid' => $eid];
}
function recalc(int $eid): int { global $db; return (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=$eid")->fetchColumn(); }
function vf(int $rid): string { global $db; return (string)$db->query("SELECT valid_from FROM foreman_worker_rates WHERE id=$rid")->fetchColumn(); }

echo "=== G. BAŞLANGICI GERİYE ÇEK (pdks_faz8b_oran_baslangic_geriye_cek) ===\n";
// a) temel akış — çavuş 2, Kadın dönemi 3 gün önce başlıyor
$R = oranEkle(2, $kadin, '1000.00', $gun(3));
$X = hakedisKur(2, $gun(5), 'draft', $kadin, $kadin);   // aralıkta (yeni=gun(6) .. gun(4))
$Y = hakedisKur(2, $gun(1), 'draft', $kadin, $kadin);   // aralık DIŞI (dönem zaten geçerli)
$AUDIT = [];
$ADMIN = false;
$r = pdks_faz8b_oran_baslangic_geriye_cek($R, $gun(6), 'test', 1, $db);
okcy('G1) admin değilse REDDEDİLİR, hiçbir şey değişmez', $r['ok'] === false && vf($R) === $gun(3) && recalc($X['eid']) === 0);
$ADMIN = true;
foreach ([['', 'boş tarih'], ['2026-13-40', 'geçersiz tarih'], ['26-01-01', 'biçim'], [$gun(-1), 'gelecek'], [$bugun, 'bugün (eski başlangıçtan sonra)'], [$gun(3), 'eşit'], [$gun(1), 'ileri'], ['2019-12-31', '2020 öncesi']] as [$t, $ad]) {
    $r = pdks_faz8b_oran_baslangic_geriye_cek($R, $t, 'test', 1, $db);
    okcy("G2) $ad reddedilir", $r['ok'] === false && vf($R) === $gun(3), json_encode($r, JSON_UNESCAPED_UNICODE));
}
$r = pdks_faz8b_oran_baslangic_geriye_cek($R, $gun(6), '   ', 1, $db);
okcy('G3) boş gerekçe reddedilir', $r['ok'] === false && str_contains($r['hata'], 'Gerekçe') && vf($R) === $gun(3));
$r = pdks_faz8b_oran_baslangic_geriye_cek($R, $gun(6), str_repeat('x', 501), 1, $db);
okcy('G3b) 501 karakter gerekçe reddedilir', $r['ok'] === false && vf($R) === $gun(3));
$r = pdks_faz8b_oran_baslangic_geriye_cek(99999, $gun(6), 'x', 1, $db);
okcy('G4) olmayan dönem reddedilir', $r['ok'] === false);
$db->exec("UPDATE foreman_worker_rates SET is_active = 0 WHERE id = $R");
$r = pdks_faz8b_oran_baslangic_geriye_cek($R, $gun(6), 'x', 1, $db);
okcy('G4b) pasif dönem reddedilir', $r['ok'] === false && vf($R) === $gun(3));
$db->exec("UPDATE foreman_worker_rates SET is_active = 1 WHERE id = $R");
$AUDIT = [];
$r = pdks_faz8b_oran_baslangic_geriye_cek($R, $gun(6), 'Şeritçi/Kadın fiyatı geçmiş mesailer için sonradan girildi', 1, $db);
okcy('G5) en eski dönem geriye çekilir', $r['ok'] === true && vf($R) === $gun(6), json_encode($r, JSON_UNESCAPED_UNICODE));
okcy('G5b) ücretler ve valid_to DEĞİŞMEDİ', (float)$db->query("SELECT daily_rate FROM foreman_worker_rates WHERE id=$R")->fetchColumn() === 1000.0 && $db->query("SELECT valid_to FROM foreman_worker_rates WHERE id=$R")->fetchColumn() === null);
okcy('G5c) aralıktaki (gün-5) taslak yeniden hesaba işaretlendi; aralık dışı (gün-1) DEĞİL', recalc($X['eid']) === 1 && recalc($Y['eid']) === 0 && (int)$r['isaretlenen'] === 1);
$au = array_values(array_filter($AUDIT, fn($a) => $a['action'] === 'rate_baslangic_geri'));
okcy('G5d) audit rate_baslangic_geri (eski/yeni + gerekçe)', count($au) === 1 && $au[0]['module'] === 'foreman_worker_rates' && $au[0]['record_id'] === $R && ($au[0]['new']['valid_from'] ?? '') === $gun(6) && str_contains((string)($au[0]['new']['gerekce'] ?? ''), 'geçmiş mesailer'));
$db->exec("UPDATE foreman_daily_entitlements SET needs_recalculation = 0");

// c) final kapısı TİPE özel
$Z1 = hakedisKur(2, $gun(8), 'final', $seritci, $kadin);   // başka tipin final satırı (Şeritçi) — Kadın'ı ENGELLEMEZ
$r = pdks_faz8b_oran_baslangic_geriye_cek($R, $gun(9), 'başka tipin final satırı engel değil', 1, $db);
okcy('G6) aralıkta BAŞKA tipin final satırı ENGEL DEĞİL', $r['ok'] === true && vf($R) === $gun(9), json_encode($r, JSON_UNESCAPED_UNICODE));
okcy('G6b) final hakedişe needs_recalculation YAZILMADI', recalc($Z1['eid']) === 0);
$Z2 = hakedisKur(2, $gun(11), 'final', $kadin, $kadin);    // bu tipin final satırı
$r = pdks_faz8b_oran_baslangic_geriye_cek($R, $gun(12), 'x', 1, $db);
okcy('G7) aralıkta BU tipin final satırı varsa REDDEDİLİR', $r['ok'] === false && str_contains($r['hata'], 'kesinleşmiş') && vf($R) === $gun(9), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = pdks_faz8b_oran_baslangic_geriye_cek($R, $gun(10), 'final günü aralığa girmiyor', 1, $db);
okcy('G7b) final gün aralık dışında kalırsa geçer (gün-10)', $r['ok'] === true && vf($R) === $gun(10), json_encode($r, JSON_UNESCAPED_UNICODE));
okcy('G7c) kesin hakedişler dokunulmadı', recalc($Z2['eid']) === 0 && $db->query("SELECT status FROM foreman_daily_entitlements WHERE id={$Z2['eid']}")->fetchColumn() === 'final');

// d) yalnız EN ESKİ dönem
$P1 = oranEkle(3, $kadin, '1000.00', '2024-01-01');
$db->exec("UPDATE foreman_worker_rates SET valid_to = '2024-12-31' WHERE id = $P1");
$P2 = oranEkle(3, $kadin, '1200.00', '2025-01-01');
$r = pdks_faz8b_oran_baslangic_geriye_cek($P2, '2024-06-01', 'x', 1, $db);
okcy('G8) öncesinde başka dönem olan (en eski OLMAYAN) dönem geriye çekilemez', $r['ok'] === false && str_contains($r['hata'], 'en eski') && vf($P2) === '2025-01-01');
$r = pdks_faz8b_oran_baslangic_geriye_cek($P1, '2023-06-01', 'en eski dönem', 1, $db);
okcy('G8b) en eski dönem geriye çekilir; sonraki dönemler/valid_to DEĞİŞMEDİ', $r['ok'] === true && vf($P1) === '2023-06-01' && vf($P2) === '2025-01-01' && $db->query("SELECT valid_to FROM foreman_worker_rates WHERE id=$P1")->fetchColumn() === '2024-12-31');
// başka çavuş/tip izolasyonu: çavuş 3'ün Şeritçi dönemi çavuş 3 Kadın'ın "öncesi" sayılmaz
$S3 = oranEkle(3, $seritci, '1800.00', '2024-03-01');
$r = pdks_faz8b_oran_baslangic_geriye_cek($S3, '2022-01-01', 'Şeritçi ilk fiyat', 1, $db);
okcy('G9) çavuş+tip bazında: Şeritçi\'nin en eski dönemi Kadın dönemlerinden bağımsız çekilir', $r['ok'] === true && vf($S3) === '2022-01-01');

// e) oran_ekle: geçmiş tarihli başlangıç → o günlerin taslakları işaretlenir; kronolojik kural DEĞİŞMEDİ
$Q = oranEkle(4, $kadin, '1000.00', '2020-01-01');
$T = hakedisKur(4, $gun(5), 'draft', $kadin, $kadin);
$r = pdks_faz8b_oran_ekle(4, $kadin, '1100', '600', 'hourly', '120', $gun(10), 'TRY', 1, $db);
okcy('G10) yeni dönem geçmiş tarihle eklenir; taslaklar işaretlenir (isaretlenen ≥ 1)', !empty($r['ok']) && (int)($r['isaretlenen'] ?? 0) >= 1 && recalc($T['eid']) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$db->exec("UPDATE foreman_daily_entitlements SET needs_recalculation = 0");
$r = pdks_faz8b_oran_ekle(4, $kadin, '1200', '650', 'hourly', '130', $gun(10), 'TRY', 1, $db);
okcy('G10b) oran_ekle kronolojik kural DEĞİŞMEDİ: aynı/eski başlangıç reddedilir', $r['ok'] === false && str_contains($r['hata'], 'sonra'));
$r = pdks_faz8b_oran_ekle(4, $kadin, '1300', '700', 'hourly', '140', $bugun, 'TRY', 1, $db);
okcy('G10c) bugün başlangıçlı dönem → işaretlenen 0 (geçmiş etkilenmez)', !empty($r['ok']) && (int)($r['isaretlenen'] ?? -1) === 0, json_encode($r, JSON_UNESCAPED_UNICODE));

// f) entegrasyon: Şeritçi fiyatı BUGÜNLE açıldı → geçmiş mesai hesaplanamaz → geriye çek → hesaplanır
oranEkle(5, $kadin, '1000.00', '2020-01-01');
$S5 = oranEkle(5, $seritci, '1800.00', $bugun);
$day = $gun(4);
[$pk, $sk] = mesaiKur(5, 540, 540, $kadin);
$db->prepare("INSERT INTO worker_cards (card_no) VALUES ('KS')")->execute(); $ks = (int)$db->lastInsertId();
$g0 = strtotime("{$day} 06:00:00");
$db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status) VALUES (?,?,?,?,?,?,'closed')")
   ->execute([$sk, $ks, $seritci, 'Şeritçi', date('Y-m-d H:i:s', $g0), date('Y-m-d H:i:s', $g0 + 540 * 60)]);
$h = pdks_faz8b_hakedis_hesapla($sk, 1, $db);
okcy('G11) Şeritçi fiyatı bugünle açılınca geçmiş mesai hesaplanamaz (fail-closed)', empty($h['ok']) && str_contains(json_encode($h, JSON_UNESCAPED_UNICODE), 'geçerli fiyat yok'), json_encode($h, JSON_UNESCAPED_UNICODE));
$r = pdks_faz8b_oran_baslangic_geriye_cek($S5, $gun(10), 'Şeritçi fiyatı geçmişe dönük', 1, $db);
okcy('G11b) geriye çekilir', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$h = pdks_faz8b_hakedis_hesapla($sk, 1, $db);
okcy('G11c) artık hesaplanır: 1000 + 1800 = 2800.00', !empty($h['ok']) && $h['total_amount'] === '2800.00', json_encode($h, JSON_UNESCAPED_UNICODE));

// g) Sayfa + fonksiyon kapıları (statik)
$src = (string)file_get_contents($root . '/cavus_fiyatlari.php');
$dal = substr($src, (int)strpos($src, "=== 'baslangic_geri'"), 1800);
okcy('G12) sayfa dalı CSRF + rates yetkisi', str_contains($dal, 'csrf_check') && str_contains($dal, "require_pdks_hakedis('rates')"));
okcy('G12b) dönem çavuşa ait mi denetlenir (IDOR)', str_contains($dal, '(int)$rateCavus !== $cavusId'));
okcy('G12c) düğme yalnız yöneticide + yalnız en eski dönemde; pencere tarih+gerekçe', str_contains($src, 'data-cf-geri-cek') && str_contains($src, '$cfGeriAcik = function_exists(\'is_admin\') && is_admin()') && str_contains($src, 'id="cfGeriCek"') && str_contains($src, 'name="yeni_baslangic"') && str_contains($src, 'name="gerekce" required'));
okcy('G12d) kullanıcı yönergesi (önce en eski fiyat geçmiş tarihle)', str_contains($src, 'EN ESKİ fiyatı geçmiş tarihle'));
$fn = (string)file_get_contents($root . '/config/pdks_faz8b.php');
$govde = substr($fn, (int)strpos($fn, 'function pdks_faz8b_oran_baslangic_geriye_cek'), 4500);
okcy('G13) fonksiyon is_admin kapısı + tek transaction + audit', str_contains($govde, 'is_admin()') && str_contains($govde, 'beginTransaction') && str_contains($govde, "'rate_baslangic_geri'") && str_contains($govde, 'FOR UPDATE'));
okcy('G13b) oran_ekle kronolojik kuralı yerinde (mevcut en son dönemden sonra)', str_contains($fn, 'mevcut en son fiyat döneminden sonra olmalıdır'));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
