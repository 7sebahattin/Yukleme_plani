<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_fm_senaryo_smoke.php — Fazla mesai UÇTAN UCA senaryo matrisi.
//
// Sınıflandırıcı (pdks_faz8b_donem_siniflandir / pdks_faz8b_oturum_donemleri), Mesai Özeti
// (pdks_faz8b_gun_mesai_ozeti) ve hakediş (pdks_faz8b_hakedis_hesapla) AYNI sayıyı vermeli.
// Beklenen değerler, kaynak koddan BAĞIMSIZ yazılmış "sahip kuralı" oracle'ından gelir:
//   * giriş/çıkış tam saate ±15 dk yakınsa o tam saate çekilir (v317);
//   * eşik (Tam / FM başlangıcı) aşıldıktan sonra 15 dk tolerans, başlayan her saat → 1 FM
//     (11 sa = 2, 10 sa 11 dk = 1, 12 sa = 3, 9 sa 16 dk = 1);
//   * Çift (K5): eff >= 12 sa → Tam'ın yerine çift ücret, FM = 12 sa'ten SONRAKİ saatler.
//
// Konfigürasyonlar:
//   K1 fiyat döneminde saat alanı YOK, oturum snapshot = 540
//   K2 full_day_minutes = 540
//   K3 full_day = 540 + overtime_start_minutes = 600  (K3 = fiyat döneminde FM başlangıcı 10 sa
//      tanımlı → FM 10 sa SONRASI başlar, 11 sa → 1 FM)
//   K4 snapshot = 600 (çavuşun normal süresi 10 sa)
//   K5 full_day = 540 + double_day = 720 + double_day_rate 2000
//
// Bellek içi SQLite + gerçek fonksiyonlar; canlı DB'ye dokunmaz.
//   php scripts/pdks_fm_senaryo_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$root = dirname(__DIR__);
$pass = 0; $fail = 0; $FAILS = [];
function okfm(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail, $FAILS;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; $FAILS[] = $name . ($detay !== '' ? " :: $detay" : ''); echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function active_depot(): ?string { return 'Depo A'; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_servis.php';

$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('ERKEK','Erkek'), ('KARISIK','Karışık')");
$kadin = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
// Mesai özeti servis tablosunu okur (yoksa 0) — yine de gerçek DDL ile kuralım (ozeti smoke ile aynı çevirici, kısa).
foreach (pdks_servis_tablolar() as $sql) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`/i', $sql, $m)) continue;
    // Servis tabloları bu testin konusu değil; yokluğu özetin servis=0 dönmesiyle yeterli.
}
pdks_faz8b_saat_kolonlari_migrate($db);

$day = date('Y-m-d', strtotime('-3 days'));
for ($f = 1; $f <= 5; $f++) $db->exec("INSERT INTO foremen (id,code,name) VALUES ({$f},'C{$f}','Çavuş {$f}')");

const TAM_UCRET = 1000.0; const FM_UCRET = 150.0; const CIFT_UCRET = 2000.0;
// foreman → [ad, snapshot, saat alanları]
$KONF = [
    1 => ['K1', 540, null],
    2 => ['K2', 540, ['full_day' => '9']],
    3 => ['K3', 540, ['full_day' => '9', 'overtime_start' => '10']],
    4 => ['K4', 600, null],
    5 => ['K5', 540, ['full_day' => '9', 'double_day' => '12', 'double_day_rate' => '2000']],
];
foreach ($KONF as $fid => [$ad, $snap, $saat]) {
    $r = pdks_faz8b_oran_ekle($fid, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db, $saat);
    okfm("{$ad}) fiyat dönemi eklendi", $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
}

// ── Sahip kuralı ORACLE'ı (kaynak koddan bağımsız) ──
function orSnap(int $dk): int {            // gün içi dakika → ±15 dk'da en yakın tam saat
    $r = $dk % 60;
    if ($r <= 15) return $dk - $r;
    if ($r >= 45) return $dk + (60 - $r);
    return $dk;
}
function orFm(int $fazla): int { return $fazla <= 15 ? 0 : (int)ceil(($fazla - 15) / 60); }
function hm(string $s): int { [$h, $m] = array_map('intval', explode(':', $s)); return $h * 60 + $m; }
function orBeklenen(string $k, int $snap, string $g, string $c): array {
    $eff = max(0, orSnap(hm($c)) - orSnap(hm($g)));
    $tam = $k === 'K4' ? $snap : 540;
    $fmBas = $k === 'K3' ? 600 : $tam;
    $aday = orFm($eff - $fmBas);
    $cift = $k === 'K5' && $eff >= 720;
    $fm = $cift ? orFm($eff - 720) : $aday;
    $sinif = $cift ? 'cift' : 'tam';
    $onceki = $eff >= $tam ? $sinif : 'bekliyor';          // karar öncesi sınıf
    $unit = $cift ? CIFT_UCRET : TAM_UCRET;
    return ['eff' => $eff, 'aday' => $aday, 'fm' => $fm, 'sinif' => $sinif, 'onceki' => $onceki,
            'tutar' => $unit + $fm * FM_UCRET];
}

function kur(int $fid, int $snap, string $g, string $c): array {
    global $db, $day, $kadin;
    static $n = 0; $n++;
    $db->prepare("INSERT INTO daily_work_sessions (foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,'Depo A','closed')")
       ->execute([$fid, "Çavuş $fid", "C$fid", $snap, $day]);
    $sid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO worker_cards (card_no) VALUES (?)")->execute(['F' . $n]);
    $kart = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status) VALUES (?,?,?,'Kadın',?,?,'closed')")
       ->execute([$sid, $kart, $kadin, "$day $g:00", "$day $c:00"]);
    return [(int)$db->lastInsertId(), $sid];
}

$DONEMLER = [['07:59','19:11'], ['08:00','19:09'], ['07:57','17:13'], ['07:57','17:16'], ['08:02','17:00'],
             ['08:16','16:44'], ['07:57','18:11'], ['07:57','20:00'], ['08:00','21:20']];
$MATRIS = [];

foreach ($KONF as $fid => [$k, $snap, $saat]) {
    echo "\n=== {$k} ===\n";
    foreach ($DONEMLER as [$g, $c]) {
        $et = "$k $g-$c";
        $b = orBeklenen($k, $snap, $g, $c);
        [$pid, $sid] = kur($fid, $snap, $g, $c);

        // 1) sınıflandırıcı (tek dönem) + oturum_donemleri aynı sonucu vermeli
        $f = pdks_faz8b_donem_getir($pid, $db)['faz8b'];
        $od = pdks_faz8b_oturum_donemleri($sid, $db)[0]['faz8b'];
        okfm("$et) etkin süre {$b['eff']} dk", (int)$f['toplam_dk'] === $b['eff'], "gerçek {$f['toplam_dk']}");
        okfm("$et) FM adayı {$b['aday']}", (int)$f['fazla_mesai_saat'] === $b['aday'], "gerçek {$f['fazla_mesai_saat']}");
        okfm("$et) sınıf (karar öncesi) {$b['onceki']}", ($f['etkin_sinif'] ?? 'bekliyor') === ($b['onceki'] === 'cift' ? 'tam' : $b['onceki']),
            'gerçek ' . var_export($f['etkin_sinif'], true));
        okfm("$et) oturum_donemleri = donem_getir", $od['toplam_dk'] === $f['toplam_dk'] && $od['fazla_mesai_saat'] === $f['fazla_mesai_saat'] && $od['etkin_sinif'] === $f['etkin_sinif']);

        // 2) değerlendirme: sınıf kararı gerekiyorsa Tam, FM adayı TAMAMEN onaylı
        if ($f['sinif_onayi_gerekli'] || (int)$f['fazla_mesai_saat'] > 0) {
            $r = pdks_faz8b_degerlendirme_kaydet($pid, $f['sinif_onayi_gerekli'] ? 'tam' : null, (int)$f['fazla_mesai_saat'] > 0 ? (int)$f['fazla_mesai_saat'] : null, 1, $db);
            okfm("$et) değerlendirme kaydı", $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        $f2 = pdks_faz8b_donem_getir($pid, $db)['faz8b'];
        okfm("$et) onay sonrası sınıf {$b['sinif']}", $f2['etkin_sinif'] === $b['sinif'], (string)$f2['etkin_sinif']);
        okfm("$et) ödenecek FM {$b['fm']} (sınıflandırıcı)", (int)$f2['odenecek_fm_saat'] === $b['fm'], (string)$f2['odenecek_fm_saat']);

        // 3) Mesai Özeti
        $o = pdks_faz8b_gun_mesai_ozeti([$sid], $db);
        $t = $o['tanim']['Kadın'];
        okfm("$et) özet: sınıf kovası {$b['sinif']} = 1", $t[$b['sinif']] === 1 && $t['toplam'] === 1, json_encode($t));
        $kova = $b['fm'] > 0 ? min($b['fm'], PDKS_FAZ8B_OZET_FM_SUTUN) : 0;
        $kovaOk = $kova === 0 ? array_sum($t['fm']) === 0 : ($t['fm'][$kova] === 1 && array_sum($t['fm']) === 1);
        okfm("$et) özet: FM kovası {$kova}", $kovaOk, json_encode($t['fm']));
        okfm("$et) özet: fm_saat = {$b['fm']}", $o['fm_saat'] === $b['fm'], (string)$o['fm_saat']);
        okfm("$et) özet: fm_onayli = {$b['fm']}", $o['fm_onayli'] === $b['fm'], (string)$o['fm_onayli']);

        // 4) Hakediş
        $h = pdks_faz8b_hakedis_hesapla($sid, 1, $db);
        $hOk = ($h['ok'] ?? false) === true;
        okfm("$et) hakediş hesaplandı", $hOk, json_encode($h, JSON_UNESCAPED_UNICODE));
        $s = $hOk ? ($h['lines'][0] ?? []) : [];
        $hFm = (int)($s['overtime_hours'] ?? -1);
        okfm("$et) hakediş FM saat {$b['fm']}", $hFm === $b['fm'], (string)$hFm);
        $ot = (float)($s['overtime_total'] ?? -1);
        okfm("$et) hakediş FM tutar " . number_format($b['fm'] * FM_UCRET, 2, '.', ''), abs($ot - $b['fm'] * FM_UCRET) < 0.005, (string)($s['overtime_total'] ?? '?'));
        okfm("$et) hakediş toplam " . number_format($b['tutar'], 2, '.', ''), $hOk && abs((float)$h['total_amount'] - $b['tutar']) < 0.005, (string)($h['total_amount'] ?? '?'));
        okfm("$et) hakediş satır sınıfı {$b['sinif']}", ($s['attendance_class_snapshot'] ?? '') === $b['sinif'], (string)($s['attendance_class_snapshot'] ?? '?'));
        okfm("$et) üç katman aynı FM (sınıflandırıcı = özet = hakediş)", (int)$f2['odenecek_fm_saat'] === $o['fm_saat'] && $o['fm_saat'] === $hFm);

        $MATRIS[$k][] = [$g . '-' . $c, $b, (int)$f['toplam_dk'], $f['etkin_sinif'] ?? 'bekliyor', (int)$f['fazla_mesai_saat'], $hFm, $hOk ? (string)$h['total_amount'] : 'HATA', $hOk ? ($s['attendance_class_snapshot'] ?? '?') : '?'];
    }
}

// K3 ayrı sabitleme: FM başlangıcı 10 sa → 11 sa = 1 FM (K2'de 2 FM)
echo "\n=== K3 / K2 karşılaştırma ===\n";
[$p2, $s2] = kur(2, 540, '08:00', '19:00');
[$p3, $s3] = kur(3, 540, '08:00', '19:00');
okfm('K2 08:00-19:00 (11 sa) = 2 FM', (int)pdks_faz8b_donem_getir($p2, $db)['faz8b']['fazla_mesai_saat'] === 2);
okfm('K3 (FM başlangıcı 10 sa tanımlı) 08:00-19:00 (11 sa) = 1 FM', (int)pdks_faz8b_donem_getir($p3, $db)['faz8b']['fazla_mesai_saat'] === 1);
$f3 = pdks_faz8b_donem_getir($p3, $db)['faz8b'];
okfm('K3 fm_bas_dk = 600, tam_dk = 540', $f3['fm_bas_dk'] === 600 && $f3['tam_dk'] === 540);
// Sahip örnekleri (K1/K2): 11 sa=2, 10s11dk=1, 12 sa=3, 9s16dk=1, 9s15dk=0
foreach ([['08:00','19:00',2], ['08:00','18:11',1], ['08:00','20:00',3], ['08:00','17:16',1], ['08:00','17:15',0]] as [$g, $c, $beklenen]) {
    [$p, ] = kur(1, 540, $g, $c);
    $x = pdks_faz8b_donem_getir($p, $db)['faz8b'];
    okfm("K1 sahip örneği $g-$c → FM $beklenen", (int)$x['fazla_mesai_saat'] === $beklenen, (string)$x['fazla_mesai_saat'] . ' etkin=' . $x['toplam_dk']);
}

// ── Matris tablosu ──
echo "\n=== MATRİS (etkin süre | sınıf (karar öncesi) | FM aday | hakediş FM saat | hakediş toplam | hakediş sınıf | oracle FM) ===\n";
foreach ($MATRIS as $k => $satirlar) {
    echo "\n$k\n";
    printf("%-13s %-8s %-9s %-4s %-8s %-9s %-6s %-6s\n", 'giris-cikis', 'etkin', 'sinif', 'aday', 'hak.FM', 'toplam', 'sinif', 'oracle');
    foreach ($satirlar as [$aralik, $b, $eff, $sinif, $aday, $hFm, $toplam, $hs]) {
        printf("%-13s %-8s %-9s %-4d %-8d %-9s %-6s %-6d\n", $aralik, intdiv($eff, 60) . 's' . sprintf('%02d', $eff % 60), $sinif, $aday, $hFm, $toplam, $hs, $b['fm']);
    }
}

echo "\n=== SONUÇ ===\nPASS: $pass  FAIL: $fail\n";
if ($FAILS) { echo "\nBAŞARISIZLAR:\n"; foreach ($FAILS as $x) echo " - $x\n"; }
exit($fail > 0 ? 1 : 0);
