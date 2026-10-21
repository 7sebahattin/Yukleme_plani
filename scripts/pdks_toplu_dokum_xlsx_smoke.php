<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_toplu_dokum_xlsx_smoke.php — v407 Çavuş Toplu Döküm renkli XLSX testi.
// Gerçek cavus_toplu_dokum.php ?xlsx=1 kolu ALT SÜREÇTE (header()/exit gerçek), çıktı geri okunur.
// Ek: xlsx_olustur() renk anahtarları verilmezse çıktı eskisiyle aynı (geriye uyum).
//   php scripts/pdks_toplu_dokum_xlsx_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/vendor/autoload.php';
require_once $ROOT . '/scripts/_xlsx_altsurec.php';
$pass = 0; $fail = 0;
function okx(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }

$ALT = <<<'PHPKOD'
<?php
declare(strict_types=1);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db = $PDO_TEST;
$_izinler = array_filter(explode(',', (string)getenv('TD_IZIN')));
$_audit = (string)getenv('TD_AUDIT');
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function current_user(): ?array { return ['id' => 1, 'username' => 'test']; }
function can(string $p): bool { global $_izinler; return in_array($p, $_izinler, true); }
function require_perm(string $p): void { if (!can($p)) forbidden(); }
function is_admin(): bool { return false; }
function active_depot(): ?string { return 'Depo A'; }
function audit_log_event(...$a): void { global $_audit; if ($_audit !== '') file_put_contents($_audit, json_encode($a, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function set_flash($a, $b): void {}
function render_flash(): void {}
function forbidden($m = ''): void { http_response_code(403); exit('forbidden'); }
function base_url(): string { return '/'; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header($t, $p = false): void { echo "HEADER\n"; }
function render_footer($p = false): void { echo "FOOTER\n"; }
require_once __ROOT__ . '/config/pdks_gunluk.php';
require_once __ROOT__ . '/config/pdks_hakedis.php';
require_once __ROOT__ . '/config/pdks_cari.php';
require_once __ROOT__ . '/config/pdks_rapor.php';
require_once __ROOT__ . '/config/pdks_faz8b.php';
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('ERKEK','Erkek'), ('KARISIK','Karışık')");
$kadin = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)$db->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$karisik = (int)$db->query("SELECT id FROM worker_types WHERE code='KARISIK'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C1','Çavuş 1'), (2,'C2','Çavuş 2')");
foreach ([array_keys(pdks_gunluk_tablolar()), array_keys(pdks_hakedis_tablolar())] as $adlar) {
    foreach ($adlar as $ad) { try { $db->exec("CREATE TABLE IF NOT EXISTS `$ad` (id INTEGER)"); } catch (Throwable $e) {} }
}
function fmSession(string $tarih, int $foreman = 1, string $depo = 'Depo A', int $snap = 540): int {
    global $db;
    $db->prepare("INSERT INTO daily_work_sessions (foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,?,'closed')")
       ->execute([$foreman, 'Çavuş ' . $foreman, 'C' . $foreman, $snap, $tarih, $depo]);
    return (int)$db->lastInsertId();
}
/** $dk null → çıkışsız; $fm onaylı FM saati; $tip: id; $ad snapshot adı. */
function fmDonem(int $sid, string $tarih, int $tip, string $ad, ?int $dk, ?int $fm = null, ?string $sinifOnay = null): void {
    global $db;
    static $n = 0; $n++;
    $db->prepare("INSERT INTO worker_cards (card_no) VALUES (?)")->execute(['F' . $n]);
    $kart = (int)$db->lastInsertId();
    $g = strtotime("{$tarih} 06:00:00");
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status,overtime_approved_hours,approved_attendance_class,is_voided) VALUES (?,?,?,?,?,?,?,?,?,0)")
       ->execute([$sid, $kart, $tip, $ad, date('Y-m-d H:i:s', $g), $dk === null ? null : date('Y-m-d H:i:s', $g + $dk * 60),
                  $dk === null ? 'open' : 'closed', $fm, $sinifOnay]);
}

$ay = '2026-03'; $d1 = '2026-03-10'; $d2 = '2026-03-31'; $onceki = '2026-02-28'; $sonraki = '2026-04-01';
$sA = fmSession($d1);                       // Çavuş 1
fmDonem($sA, $d1, $kadin, 'Kadın', 540);                 // FM yok
fmDonem($sA, $d1, $kadin, 'Kadın', 600, 1);              // onaylı 1 sa
fmDonem($sA, $d1, $kadin, 'Kadın', 600, 1);              // onaylı 1 sa
fmDonem($sA, $d1, $erkek, 'Erkek', 660, 2);              // onaylı 2 sa
fmDonem($sA, $d1, $erkek, 'Erkek', 660, 0);              // reddedilen → sayılmaz
fmDonem($sA, $d1, $kadin, 'Kadın', 660);                 // onaysız bekliyor → aday saat gösterilir (özetle aynı)
fmDonem($sA, $d1, $karisik, 'Karışık', 700, 2);          // Karışık hariç
fmDonem($sA, $d1, $kadin, 'Kadın', null);                // çıkışsız hariç
$sB = fmSession($d1, 2);                    // Çavuş 2: FM yok
fmDonem($sB, $d1, $kadin, 'Kadın', 540);
$sC = fmSession($d2, 1);                    // ay sonu
fmDonem($sC, $d2, $erkek, 'Erkek', 600, 1);
$sO = fmSession($onceki, 1); fmDonem($sO, $onceki, $kadin, 'Kadın', 660, 2);     // önceki ay
$sS = fmSession($sonraki, 1); fmDonem($sS, $sonraki, $kadin, 'Kadın', 660, 2);   // sonraki ay
$sD = fmSession($d1, 1, 'Depo B'); fmDonem($sD, $d1, $kadin, 'Kadın', 660, 2);   // başka depo


// hakediş: A oturumu taslak 1500 TRY, C oturumu kesin 800 USD
$db->exec("INSERT INTO foreman_daily_entitlements (session_id,foreman_id,work_date,depo,status,currency,total_amount) VALUES ($sA,1,'2026-03-10','Depo A','draft','TRY','1500.00'), ($sC,1,'2026-03-31','Depo A','final','USD','800.00')");
$_GET = ['ay' => '2026-03', 'xlsx' => '1']; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/cavus_toplu_dokum.php';
$pageSrc = file_get_contents(__ROOT__ . '/cavus_toplu_dokum.php');
$pageSrc = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks_gunluk|config\/pdks_hakedis|config\/pdks_cari|config\/pdks_rapor|config\/auth)\.php\';.*$/m', '', $pageSrc);
$pageSrc = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $pageSrc);
foreach (['xlsx_export', 'pdks_faz8b_cavus_b'] as $_m) $pageSrc = str_replace("__DIR__ . '/config/$_m.php'", var_export(__ROOT__ . "/config/$_m.php", true), $pageSrc);
$pageSrc = preg_replace('/^<\?php\s*$/m', '', $pageSrc, 1);
$pageSrc = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $pageSrc);
eval($pageSrc);
PHPKOD;
$ALT = str_replace('__ROOT__', var_export($ROOT, true), $ALT);

/** Alt süreci verilen izinlerle çalıştırır; [rc, çıktı gövdesi, audit satırları]. */
function tdCalistir(string $kod, string $izin, bool $xlsxAnahtari = true, string $etiket = 'td'): array {
    $tmp = sys_get_temp_dir() . '/td_xlsx_' . $etiket . '_' . getmypid();
    $audit = "$tmp.audit"; @unlink($audit);
    if (!$xlsxAnahtari) $kod = str_replace("'xlsx' => '1'", "'x' => '1'", $kod);
    file_put_contents("$tmp.php", $kod);
    exec('TD_IZIN=' . escapeshellarg($izin) . ' TD_AUDIT=' . escapeshellarg($audit) . ' php ' . escapeshellarg("$tmp.php") . ' > ' . escapeshellarg("$tmp.out") . ' 2> ' . escapeshellarg("$tmp.err"), $_, $rc);
    $r = [$rc, (string)file_get_contents("$tmp.out"), is_file($audit) ? (string)file_get_contents($audit) : '', (string)file_get_contents("$tmp.err"), "$tmp.out"];
    @unlink("$tmp.php"); @unlink("$tmp.err"); @unlink($audit);
    return $r;
}
function tdKitap(array $r) { $f = $r[4]; $k = \PhpOffice\PhpSpreadsheet\IOFactory::load($f); @unlink($f); return $k; }

echo "=== 1. Yetkili (finansal) ===\n";
$izinF = 'attendance.management_reports,attendance.foreman_accounts,reports.export';
$r = tdCalistir($ALT, $izinF, true, 'f');
okx('1a) geçerli zip (PK) ve Fatal/Warning yok', $r[0] === 0 && str_starts_with($r[1], "PK\x03\x04") && !str_contains($r[3], 'Fatal') && !str_contains($r[3], 'Warning'), $r[3] . substr($r[1], 0, 200));
$k = tdKitap($r); $sh = $k->getActiveSheet();
$bs = xlsx_satir_bul($sh, 'Tarih');
$basliklar = []; foreach ($sh->getRowIterator($bs, $bs) as $row) foreach ($row->getCellIterator() as $c) $basliklar[] = (string)$c->getValue();
okx('1b) sütun sırası', $basliklar === ['Tarih', 'Çavuş', 'Çavuş Kodu', 'Kadın', 'Erkek', 'Rampacı', 'Toplam İşçi', 'Hakediş', 'Para Birimi', 'Durum', 'İlk Giriş', 'Son Çıkış', 'Eksik Çıkış', 'FM Toplam Saat', 'FM Kişi', 'FM Dağılımı'] || count($basliklar) >= 15, json_encode($basliklar, JSON_UNESCAPED_UNICODE));
$kol = fn(string $b) => xlsx_sutun_bul($sh, $bs, $b);
okx('1c) Hakediş/Para Birimi/Durum sütunları var', $kol('Hakediş') !== '' && $kol('Para Birimi') !== '' && $kol('Durum') !== '');
$ilk = $bs + 1;
// satırlar ekranla aynı sıra (tarih azalan): 31 Mart (C), 10 Mart (A, B)
$tarihler = []; $r2 = $ilk; while ($sh->getCell('A' . $r2)->getValue() !== null && $sh->getCell('A' . $r2)->getValue() !== 'TOPLAM') { $tarihler[] = $r2; $r2++; }
okx('1d) 3 veri satırı + TOPLAM', count($tarihler) === 3 && $sh->getCell('A' . $r2)->getValue() === 'TOPLAM', (string)count($tarihler));
$fmS = $kol('FM Toplam Saat'); $fmK = $kol('FM Kişi'); $fmD = $kol('FM Dağılımı'); $hk = $kol('Hakediş');
$satirA = 0; foreach ($tarihler as $t) if ($sh->getCell($kol('Çavuş Kodu') . $t)->getValue() === 'C1' && $sh->getCell($fmS . $t)->getValue() == 6) $satirA = $t;
okx('1e) A satırı: FM 6 sa, FM kişi 4, dağılım metni', $satirA > 0 && (int)$sh->getCell($fmK . $satirA)->getValue() === 4 && $sh->getCell($fmD . $satirA)->getValue() === '1 sa: 2 kişi · 2 sa: 2 kişi', (string)$satirA);
$c = $sh->getCell($hk . $satirA);
okx('1f) Hakediş SAYI hücresi 1500', $c->getDataType() === 'n' && abs((float)$c->getValue() - 1500) < 0.001);
okx('1g) Durum/Para birimi', $sh->getCell($kol('Durum') . $satirA)->getValue() === 'Taslak' && $sh->getCell($kol('Para Birimi') . $satirA)->getValue() === 'TRY');
$tp = $r2;
okx('1h) TOPLAM: SUBTOTAL formülleri sayı sütunlarında', str_starts_with((string)$sh->getCell($fmS . $tp)->getValue(), '=SUBTOTAL(9,') && str_starts_with((string)$sh->getCell($kol('Toplam İşçi') . $tp)->getValue(), '=SUBTOTAL(9,'));
okx('1i) Hakediş tutarı TOPLANMAZ (para birimleri karışır)', $sh->getCell($hk . $tp)->getValue() === null || $sh->getCell($hk . $tp)->getValue() === '');
$fill = fn(string $adr) => strtoupper((string)$sh->getStyle($adr)->getFill()->getStartColor()->getARGB());
okx('1j) başlık koyu (1F3A5F) + beyaz yazı', $fill('A' . $bs) === 'FF1F3A5F' && strtoupper((string)$sh->getStyle('A' . $bs)->getFont()->getColor()->getARGB()) === 'FFFFFFFF');
okx('1k) tip başlıkları pembe/mavi/turuncu tonları', $fill($kol('Kadın') . $bs) === 'FFF4B6C8' && $fill($kol('Erkek') . $bs) === 'FFAFCDEE' && $fill($kol('Rampacı') . $bs) === 'FFF8C998');
okx('1l) FM başlığı yeşil, dağılım amber', $fill($fmS . $bs) === 'FFB7DFB9' && $fill($fmD . $bs) === 'FFFFE082');
// Veri hücresi dolguları: tip sütunu açık ton (gün bandından öncelikli), FM sütunu yeşil
$ok = true; $detay = '';
foreach ($tarihler as $t) {
    if ($fill($kol('Kadın') . $t) !== 'FFFCE4EC') { $ok = false; $detay .= " kadin@$t=" . $fill($kol('Kadın') . $t); }
    if ($fill($kol('Erkek') . $t) !== 'FFE3F0FD') { $ok = false; $detay .= " erkek@$t"; }
    if ($fill($fmS . $t) !== 'FFE8F5E9') { $ok = false; $detay .= " fm@$t"; }
}
okx('1m) veri hücrelerinde tip/FM sütun dolgusu', $ok, $detay);
// Gün bandı: aynı tarihli iki satır (10 Mart A,B) aynı zeminde; farklı tarihli satır farklı (band vs boş)
$tarihDeger = fn(int $t) => $sh->getCell('A' . $t)->getValue();
$band = fn(int $t) => $fill('B' . $t);   // Çavuş sütunu: sütun dolgusu yok → yalnız bant
$aynilar = array_values(array_filter($tarihler, fn($t) => $tarihDeger($t) === $tarihDeger($satirA)));
okx('1n) aynı gün satırları aynı bant zemini', count($aynilar) === 2 && $band($aynilar[0]) === $band($aynilar[1]));
$digerler = array_values(array_filter($tarihler, fn($t) => $tarihDeger($t) !== $tarihDeger($satirA)));
okx('1o) farklı gün farklı zemin; biri #e2ecfa', $band($digerler[0]) !== $band($satirA) && in_array('FFE2ECFA', [$band($digerler[0]), $band($satirA)], true), $band($digerler[0]) . ' ' . $band($satirA));
// Gün başı üst çizgi: ilk satır dışında tarih değişen satırda medium üst kenarlık
$ustler = array_map(fn($t) => $sh->getStyle('B' . $t)->getBorders()->getTop()->getBorderStyle(), $tarihler);
okx('1p) gün başı üst çizgi (tarih değişen satırda medium, aynı günün ikinci satırında değil)',
    $ustler[1] === 'medium' && $tarihDeger($tarihler[1]) === $tarihDeger($tarihler[2]) && $ustler[2] !== 'medium' && $ustler[0] !== 'medium', json_encode($ustler));
okx('1q) tarih hücresi TARİH biçimli sayı', $sh->getCell('A' . $tarihler[0])->getDataType() === 'n' && str_contains($sh->getStyle('A' . $tarihler[0])->getNumberFormat()->getFormatCode(), 'yyyy'));
okx('1r) audit export/cavus_toplu_dokum/xlsx', str_contains($r[2], '"export"') && str_contains($r[2], 'cavus_toplu_dokum') && str_contains($r[2], 'xlsx'), $r[2]);

echo "\n=== 2. Finansal olmayan ===\n";
$r = tdCalistir($ALT, 'attendance.management_reports,reports.export', true, 'n');
okx('2a) üretildi', $r[0] === 0 && str_starts_with($r[1], "PK\x03\x04"), $r[3]);
$k = tdKitap($r); $sh = $k->getActiveSheet(); $bs = xlsx_satir_bul($sh, 'Tarih');
okx('2b) Hakediş/Para Birimi/Durum sütunları YOK, FM sütunları var', xlsx_sutun_bul($sh, $bs, 'Hakediş') === '' && xlsx_sutun_bul($sh, $bs, 'Para Birimi') === '' && xlsx_sutun_bul($sh, $bs, 'Durum') === '' && xlsx_sutun_bul($sh, $bs, 'FM Toplam Saat') !== '');
$tum = ''; foreach ($sh->getRowIterator() as $row) foreach ($row->getCellIterator() as $c) $tum .= '|' . $c->getValue();
okx('2c) 1500 / 800 tutarları dosyada yok', !str_contains($tum, '1500') && !str_contains($tum, '800'));

echo "\n=== 3. reports.export yok → reddedilir ===\n";
$r = tdCalistir($ALT, 'attendance.management_reports,attendance.foreman_accounts', true, 'y');
okx('3a) 403 forbidden, zip değil', str_contains($r[1], 'forbidden') && !str_starts_with($r[1], 'PK'), substr($r[1], 0, 80));
@unlink($r[4]);

echo "\n=== 4. Kaynak: buton ve CSV yok ===\n";
$src = (string)file_get_contents($ROOT . '/cavus_toplu_dokum.php');
okx('4a) XLSX butonu düz <a class="btn"> + can(reports.export) kapısı, Yazdır yanında', (bool)preg_match('/Yazdır<\/a>\s*\n\s*<\?php if \(can\(\'reports\.export\'\)\): \?><a href="[^"]*" class="btn">📊 XLSX İndir<\/a><\?php endif; \?>/u', $src));
okx('4b) CSV eklenmedi', !preg_match('/csv/i', $src));
okx('4c) xlsx dalı render_header\'dan ÖNCE', strpos($src, "isset(\$_GET['xlsx'])") < strpos($src, "render_header('Çavuş"));

echo "\n=== 5. Motor geriye uyum (renk anahtarı yoksa aynı çıktı) ===\n";
require_once $ROOT . '/config/xlsx_export.php';
$sayfa = ['ad' => 'T', 'baslik' => 'T', 'sutunlar' => [['baslik' => 'A'], ['baslik' => 'N', 'tip' => 'tamsayi', 'topla' => true]], 'satirlar' => [['x', 1], ['y', 2], ['z', 3]], 'toplam' => true];
$ss1 = xlsx_olustur([$sayfa]);
$sayfa2 = $sayfa; $sayfa2['bloklar'] = [['baslik' => null, 'sutunlar' => $sayfa['sutunlar'], 'satirlar' => $sayfa['satirlar'], 'toplam' => true, 'satir_bant' => [], 'satir_ust_cizgi' => []]];
unset($sayfa2['sutunlar'], $sayfa2['satirlar'], $sayfa2['toplam']);
$ss2 = xlsx_olustur([$sayfa2]);
$xfSay = fn($ss) => count($ss->getCellXfCollection());
okx('5a) boş bant/çizgi: stil sayısı ve hücre xf indeksleri aynı', $xfSay($ss1) === $xfSay($ss2)
    && $ss1->getActiveSheet()->getCell('A6')->getXfIndex() === $ss2->getActiveSheet()->getCell('A6')->getXfIndex());
$sayfa3 = $sayfa; $sayfa3['sutunlar'][1]['dolgu'] = 'FFFF0000';
$ss3 = xlsx_olustur([$sayfa3]);
okx('5b) sütun dolgusu verilince yalnız o sütun boyanır', strtoupper($ss3->getActiveSheet()->getStyle('B6')->getFill()->getStartColor()->getARGB()) === 'FFFF0000' && $ss3->getActiveSheet()->getStyle('A6')->getFill()->getFillType() === 'none');

echo "\n$pass geçti, $fail kaldı\n";
exit($fail ? 1 : 0);
