<?php
// =========================================================
// scripts/pdks_gun_sonu_kapanis_smoke.php — v275 gün sonu kapanış hatırlatması
//
// Kural (Z raporu): önceki günden açık kalan mesai, AYNI çavuş + AYNI depo
// için yeni gün açılmasını ENGELLER; bugünkü mesai süresi (açılış + normal
// süre + pay) dolunca yalnız UYARI verilir. Bellek içi SQLite — canlı DB'ye
// dokunmaz.  Çalıştır: php scripts/pdks_gun_sonu_kapanis_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$dbGs = new PDO('sqlite::memory:');
$dbGs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbGs->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$depoGs = 'FINIKE';
function db(): PDO { global $dbGs; return $dbGs; }
function active_depot(): ?string { global $depoGs; return $depoGs; }
function audit_log_event(string $a, string $m, int $id, ?array $o = null, ?array $n = null): void {}
require_once $root . '/config/pdks.php';
require_once $root . '/config/pdks_gunluk.php';

foreach ([
    'CREATE TABLE worker_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER, sort_order INTEGER)',
    'CREATE TABLE foremen (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)',
    'CREATE TABLE worker_cards (id INTEGER PRIMARY KEY, card_no TEXT, canonical_uid TEXT, worker_type_id INTEGER NULL, status TEXT)',
    'CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, opened_at TEXT, opened_by_user_id INTEGER, closed_at TEXT, closed_by_user_id INTEGER, notes TEXT, updated_at TEXT, UNIQUE(foreman_id, work_date, depo))',
    'CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT, canonical_uid_snapshot TEXT, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, recorded_by_user_id INTEGER, server_event_time TEXT)',
    'CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_event_id INTEGER, exit_event_id INTEGER, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT, approved_attendance_class TEXT, work_date_snapshot TEXT, depo_snapshot TEXT, status TEXT, source TEXT, is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)',
] as $sql) $dbGs->exec($sql);

$pass = 0; $fail = 0;
function okGs(string $ad, bool $v): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $ad\n"; } else { $fail++; echo "FAIL $ad\n"; } }

$today = date('Y-m-d');
$dun   = date('Y-m-d', strtotime('-1 day'));
$dbGs->exec("INSERT INTO worker_types VALUES (1,'KADIN','Kadın',1,1)");
$dbGs->exec("INSERT INTO foremen VALUES (1,'C1','Birinci Çavuş',1),(2,'C2','İkinci Çavuş',1),(3,'C3','Üçüncü Çavuş',1)");
$dbGs->exec("INSERT INTO worker_cards VALUES (1,'K001','AABBCC01',NULL,'active')");
$ins = $dbGs->prepare('INSERT INTO daily_work_sessions (foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,opened_at,opened_by_user_id) VALUES (?,?,?,?,?,?,?,7)');
// 1: çavuş 1 — DÜN FINIKE'de açık kalmış (1 eksik çıkışlı)
$ins->execute([1, 'Birinci Çavuş', 'C1', $dun, 'FINIKE', 'open', "$dun 07:30:00"]);
$eskiId = (int)$dbGs->lastInsertId();
$dbGs->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,declared_attendance_class,work_date_snapshot,depo_snapshot,status,source) VALUES (?,1,1,'Kadın',?,'auto',?,'FINIKE','open','scan')")
    ->execute([$eskiId, "$dun 07:35:00", $dun]);
// 2: çavuş 2 — DÜN başka depoda açık (FINIKE'yi etkilememeli)
$ins->execute([2, 'İkinci Çavuş', 'C2', $dun, 'ANTALYA', 'open', "$dun 07:30:00"]);
// 3: çavuş 3 — DÜN FINIKE'de KAPALI
$ins->execute([3, 'Üçüncü Çavuş', 'C3', $dun, 'FINIKE', 'closed', "$dun 07:30:00"]);

okGs('Faz 8A şeması etkin', pdks_gunluk_faz8a_sema_hazir($dbGs));

// ── Liste ──
$eski = pdks_gunluk_eski_acik_oturumlar();
okGs('aktif depoda yalnız 1 eski açık mesai (başka depo ve kapalı olan hariç)', count($eski) === 1 && $eski[0]['id'] === $eskiId);
okGs('satır çavuş adı + tarih + özet taşıyor', $eski[0]['foreman_name'] === 'Birinci Çavuş' && $eski[0]['work_date'] === $dun);
okGs('özetteki eksik çıkış = 1', (int)($eski[0]['ozet']['eksik_toplam'] ?? -1) === 1);
okGs('çavuş filtresi çalışıyor (çavuş 3 için boş)', pdks_gunluk_eski_acik_oturumlar(null, 3) === []);
okGs('ANTALYA deposunda çavuş 2 görünüyor', count(pdks_gunluk_eski_acik_oturumlar('ANTALYA')) === 1);

// ── Engel: yeni gün açılmaz ──
$r = pdks_gunluk_oturum_ac_veya_getir(1, 7, $dbGs);
okGs('çavuş 1 için bugün yeni mesai AÇILMADI (onceki_mesai_acik)', !$r['ok'] && $r['kod'] === 'onceki_mesai_acik');
okGs('ret yanıtı eski oturumları döndürüyor', count($r['eski_oturumlar'] ?? []) === 1);
okGs('ret sonrası bugün için satır YAZILMADI', (int)$dbGs->query("SELECT COUNT(*) FROM daily_work_sessions WHERE foreman_id=1 AND work_date='$today'")->fetchColumn() === 0);

$r3 = pdks_gunluk_oturum_ac_veya_getir(3, 7, $dbGs);
okGs('dünkü mesaisi KAPALI çavuş 3 normal açabiliyor', $r3['ok'] && $r3['yeni']);
$r2 = pdks_gunluk_oturum_ac_veya_getir(2, 7, $dbGs);
okGs('başka depoda açık kalan mesai FINIKE açılışını engellemiyor', $r2['ok'] && $r2['yeni']);

// ── Kapatma: eksik çıkış gerekçe ister, sonra yeni gün açılır ──
$k1 = pdks_gunluk_oturum_kapat($eskiId, null, 7, $dbGs);
okGs('gerekçesiz kapatma eksik çıkış nedeniyle reddedildi', !$k1['ok'] && $k1['kod'] === 'eksik_cikis_var');
$k2 = pdks_gunluk_oturum_kapat($eskiId, 'Kart sahada kaldı', 7, $dbGs);
okGs('gerekçeyle kapatıldı', $k2['ok'] === true);
okGs('liste boşaldı', pdks_gunluk_eski_acik_oturumlar() === []);
$r1b = pdks_gunluk_oturum_ac_veya_getir(1, 7, $dbGs);
okGs('kapatma sonrası çavuş 1 için bugün mesai açıldı', $r1b['ok'] && $r1b['yeni']);
$r1c = pdks_gunluk_oturum_ac_veya_getir(1, 7, $dbGs);
okGs('bugünkü açık mesaiye devam engellenmiyor', $r1c['ok'] && !$r1c['yeni']);

// ── Süre doldu uyarısı ──
$ac = strtotime("$today 07:00:00");
$ot = ['status' => 'open', 'work_date' => $today, 'opened_at' => "$today 07:00:00", 'normal_work_minutes_snapshot' => 540];
okGs('süre dolmadan uyarı YOK (16:59)', pdks_gunluk_kapat_uyarisi($ot, $ac + (540 + 59) * 60) === null);
$u = pdks_gunluk_kapat_uyarisi($ot, $ac + (540 + 60) * 60);
okGs('açılış + 9 saat + 60 dk → uyarı (17:00)', $u !== null && $u['tur'] === 'sure_doldu' && $u['sinir'] === '17:00');
okGs('snapshot yoksa 540 dk varsayılır', pdks_gunluk_kapat_uyarisi(['status' => 'open', 'work_date' => $today, 'opened_at' => "$today 07:00:00"], $ac + 600 * 60) !== null);
okGs('kısa normal süre (300 dk) erken uyarır', pdks_gunluk_kapat_uyarisi($ot + ['normal_work_minutes_snapshot' => 300], $ac + 360 * 60) === null
    && pdks_gunluk_kapat_uyarisi(['normal_work_minutes_snapshot' => 300] + $ot, $ac + 360 * 60) !== null);
okGs('kapalı mesaide uyarı YOK', pdks_gunluk_kapat_uyarisi(['status' => 'closed'] + $ot, $ac + 900 * 60) === null);
$ue = pdks_gunluk_kapat_uyarisi(['status' => 'open', 'work_date' => $dun, 'opened_at' => "$dun 07:00:00"]);
okGs('geçmiş güne ait açık mesai her zaman uyarır (eski_gun)', $ue !== null && $ue['tur'] === 'eski_gun');

$dbGs->exec("UPDATE daily_work_sessions SET opened_at='$today 00:00:01' WHERE work_date='$today'");
$sd = pdks_gunluk_suresi_dolan_oturumlar(null, $dbGs, strtotime("$today 23:59:00"));
okGs('süresi dolan bugünkü mesailer listeleniyor (FINIKE: çavuş 1, 2, 3)', count($sd) === 3 && $sd[0]['uyari']['tur'] === 'sure_doldu');
okGs('sabah erken saatte liste boş', pdks_gunluk_suresi_dolan_oturumlar(null, $dbGs, strtotime("$today 00:30:00")) === []);

// ── Sayfa kancaları (statik) ──
$page = (string)file_get_contents($root . '/gunluk_isci_giris_cikis.php');
okGs('sayfa eski açık mesai penceresini içeriyor (#giEskiSec)', str_contains($page, 'id="giEskiSec"'));
okGs('GİRİŞ reddinde pencere açılıyor (onceki_mesai_acik → eskiPencereAc)', (bool)preg_match("/onceki_mesai_acik'\\) \\{.*?eskiPencereAc\\(/s", $page));
okGs('mod ekranında süre uyarısı alanı var (#giKapatUyari)', str_contains($page, 'id="giKapatUyari"'));
okGs('ajax=oturum yanıtına kapat_uyarisi ekleniyor', str_contains($page, "\$sonuc['kapat_uyarisi'] = pdks_gunluk_kapat_uyarisi("));
okGs('pencere kapatmayı aynı ?ajax=kapat yolundan yapıyor (ikinci yazma yolu yok)', substr_count($page, "daily_work_sessions SET") === 0);

echo "\nSONUÇ: $pass geçti, $fail hata\n";
exit($fail > 0 ? 1 : 0);
