<?php
// =========================================================
// scripts/pdks_kapanis_notu_smoke.php — v299 kapanış notu düzenleme
// (pdks_gunluk_oturum_not_guncelle). Bellek içi SQLite, canlı DB'ye dokunmaz.
//   php scripts/pdks_kapanis_notu_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$dbN = new PDO('sqlite::memory:');
$dbN->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbN->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$adminN = true;
$depoN = 'FINIKE';
$kullaniciN = 7;
function db(): PDO { global $dbN; return $dbN; }
function is_admin(): bool { global $adminN; return $adminN; }
function current_user(): ?array { global $kullaniciN; return ['id' => $kullaniciN]; }
function active_depot(): ?string { global $depoN; return $depoN; }
require_once $root . '/config/pdks.php';
require_once $root . '/config/pdks_faz8h.php';

foreach ([
    'CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, opened_at TEXT, opened_by_user_id INTEGER, closed_at TEXT, closed_by_user_id INTEGER, notes TEXT, updated_at TEXT, UNIQUE(foreman_id, work_date, depo))',
    'CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, session_id INTEGER, foreman_id INTEGER, status TEXT, needs_recalculation INTEGER DEFAULT 0, notes TEXT, updated_at TEXT, finalized_at TEXT, finalized_by_user_id INTEGER, total_amount TEXT)',
    'CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
] as $sql) $dbN->exec($sql);

$bugun = date('Y-m-d');
$eski = date('Y-m-d', strtotime('-40 days'));
$ins = $dbN->prepare('INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,opened_at,opened_by_user_id,closed_at,closed_by_user_id,notes,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([
    // id, çavuş, gün, depo, durum, not
    [1, 1, $bugun, 'FINIKE',  'closed', 'Mesai eksik çıkışla kapatıldı.'],
    [2, 2, $bugun, 'ANTALYA', 'closed', 'Başka depo'],
    [3, 3, $bugun, 'FINIKE',  'open',   null],
    [4, 4, $eski,  'FINIKE',  'closed', 'Eski not'],           // geçmiş tarih, kapalı
    [5, 5, $bugun, 'FINIKE',  'closed', null],                  // kesinleşmiş hakedişli
    [6, 6, $bugun, 'FINIKE',  'closed', 'Silinecek not'],
] as [$id, $cavus, $gun, $depo, $durum, $not]) {
    $ins->execute([$id, $cavus, 'Çavuş ' . $cavus, 'C' . $cavus, $gun, $depo, $durum, "$gun 08:00:00", 7,
        $durum === 'closed' ? "$gun 17:00:00" : null, $durum === 'closed' ? 7 : null, $not, "$gun 17:00:00"]);
}
$dbN->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,status,needs_recalculation,notes,total_amount,updated_at) VALUES (1,5,5,'final',0,'','2000.00','2020-01-01 00:00:00'),(2,1,1,'draft',0,'','1000.00','2020-01-01 00:00:00')");

$pass = 0; $fail = 0;
function okN(string $ad, bool $v, string $ip = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $ad\n"; } else { $fail++; echo "FAIL $ad" . ($ip !== '' ? "\n    -> $ip" : '') . "\n"; } }
function notN(int $id, string $not): array { return pdks_gunluk_oturum_not_guncelle($id, $not, 7, db()); }
function notDegeri(int $id) { return db()->query('SELECT notes FROM daily_work_sessions WHERE id=' . $id)->fetchColumn(); }
function auditSayisi(int $id): int { return (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='daily_session_note_edit' AND record_id=" . $id)->fetchColumn(); }

// ── yetki ──
$adminN = false;
$r = notN(1, 'Yetkisiz deneme');
okN('operator düzenleyemez', !$r['ok'] && $r['kod'] === 'yetkisiz' && notDegeri(1) === 'Mesai eksik çıkışla kapatıldı.' && auditSayisi(1) === 0);
$adminN = true;
$kullaniciN = 99;
$r = pdks_gunluk_oturum_not_guncelle(1, 'Başka kullanıcı kimliği', 7, db());
okN('oturumdaki kullanıcıyla eşleşmeyen userId reddedilir', !$r['ok'] && $r['kod'] === 'yetkisiz');
$kullaniciN = 7;
$r = pdks_gunluk_oturum_not_guncelle(1, 'x', 0, db());
okN('geçersiz userId reddedilir', !$r['ok'] && $r['kod'] === 'yetkisiz');

// ── depo / durum kapıları ──
$r = notN(2, 'Başka depo notu');
okN('başka depodaki mesai düzenlenemez', !$r['ok'] && $r['kod'] === 'yanlis_depo' && notDegeri(2) === 'Başka depo');
$r = notN(3, 'Açık mesai notu');
okN('AÇIK mesaide not düzenlenemez', !$r['ok'] && $r['kod'] === 'mesai_acik' && notDegeri(3) === null);
$r = notN(999, 'Yok');
okN('olmayan mesai', !$r['ok'] && $r['kod'] === 'oturum_yok');
$depoN = '';
$r = notN(1, 'Depo yok');
okN('aktif depo yoksa reddedilir', !$r['ok'] && $r['kod'] === 'gecersiz_istek');
$depoN = 'FINIKE';
okN('reddedilen denemeler audit YAZMAZ', (int)$dbN->query("SELECT COUNT(*) FROM audit_log")->fetchColumn() === 0);

// ── başarılı düzenleme ──
$r = notN(1, "  Düzeltilmiş not\r\nikinci satır  ");
okN('admin kapalı mesaide notu düzenler (trim + satır sonu normalleşir)', $r['ok'] && $r['degisti'] === true && notDegeri(1) === "Düzeltilmiş not\nikinci satır", var_export(notDegeri(1), true));
$a = $dbN->query("SELECT * FROM audit_log WHERE action='daily_session_note_edit' AND record_id=1")->fetch();
$ov = json_decode((string)($a['old_values'] ?? ''), true); $nv = json_decode((string)($a['new_values'] ?? ''), true);
okN('audit: modül daily_work_sessions, eski ve yeni not, kullanıcı', $a && $a['module'] === 'daily_work_sessions' && (int)$a['user_id'] === 7
    && ($ov['notes'] ?? null) === 'Mesai eksik çıkışla kapatıldı.' && ($nv['notes'] ?? null) === "Düzeltilmiş not\nikinci satır");
$r = notN(4, 'Eski kayıt düzeltmesi');
okN('GEÇMİŞ tarihli kapalı mesai da düzenlenir (tarih sınırı yok)', $r['ok'] && notDegeri(4) === 'Eski kayıt düzeltmesi');
$r = notN(5, 'Kesinleşmiş hakedişli mesai notu');
okN('kesinleşmiş hakedişte de çalışır', $r['ok'] && notDegeri(5) === 'Kesinleşmiş hakedişli mesai notu');
okN('hakediş satırları DEĞİŞMEDİ (durum, tazeleme bayrağı, güncellenme)',
    $dbN->query('SELECT status FROM foreman_daily_entitlements WHERE id=1')->fetchColumn() === 'final'
    && (int)$dbN->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=1')->fetchColumn() === 0
    && (int)$dbN->query('SELECT needs_recalculation FROM foreman_daily_entitlements WHERE id=2')->fetchColumn() === 0
    && $dbN->query('SELECT updated_at FROM foreman_daily_entitlements WHERE id=2')->fetchColumn() === '2020-01-01 00:00:00');

// ── boş → NULL, sınır, değişmeyen ──
$r = notN(6, "   \n ");
okN('boş metin notu siler (NULL)', $r['ok'] && notDegeri(6) === null);
$r = notN(6, '');
okN('zaten boşken boş kaydetmek no-op (audit yok)', $r['ok'] && $r['degisti'] === false && auditSayisi(6) === 1);
$onceki = auditSayisi(1);
$r = notN(1, "Düzeltilmiş not\nikinci satır");
okN('değişmeyen metin: ok ama yazmaz, audit eklemez', $r['ok'] && $r['degisti'] === false && auditSayisi(1) === $onceki);
$r = notN(1, str_repeat('ğ', 1001));
okN('1001 karakter reddedilir (karakter sayar, bayt değil)', !$r['ok'] && $r['kod'] === 'not_uzun' && notDegeri(1) === "Düzeltilmiş not\nikinci satır");
$r = notN(1, str_repeat('ğ', 1000));
okN('1000 karakter kabul edilir', $r['ok'] && mb_strlen((string)notDegeri(1), 'UTF-8') === 1000);

// ── yan etki: yalnız notes + updated_at ──
$satir = $dbN->query('SELECT * FROM daily_work_sessions WHERE id=1')->fetch();
okN('durum / kapanış alanları DEĞİŞMEDİ', $satir['status'] === 'closed' && $satir['closed_at'] === "$bugun 17:00:00" && (int)$satir['closed_by_user_id'] === 7);

// ── audit yazılamazsa geri alınır ──
$dbN->exec('DROP TABLE audit_log');
$r = notN(4, 'Audit yok');
okN('denetim kaydı yazılamazsa not DEĞİŞMEZ (rollback)', !$r['ok'] && $r['kod'] === 'yazma_hatasi' && notDegeri(4) === 'Eski kayıt düzeltmesi' && !$dbN->inTransaction());

// ── statik: para etkisi yok, kapılar işlevin İÇİNDE ──
$kaynak = file_get_contents($root . '/config/pdks_faz8h.php');
preg_match('/function pdks_gunluk_oturum_not_guncelle\(.*?\n}\n/s', $kaynak, $m);
$govde = $m[0] ?? '';
okN('işlev gövdesi bulundu', strlen($govde) > 500);
okN("gövdede 'entitlement' / needs_recalculation YOK (para etkisi yok)", stripos($govde, 'entitlement') === false && stripos($govde, 'needs_recalculation') === false);
okN('yetki işlevin İÇİNDE (is_admin) ve tek UPDATE yalnız notes yazar', str_contains($govde, 'is_admin()')
    && preg_match_all('/UPDATE\s+(\w+)\s+SET\s+notes\s*=/i', $govde) === 1 && preg_match_all('/(?<!FOR )\bUPDATE\b/', $govde) === 1
    && preg_match_all('/\bDELETE\b|\bINSERT INTO (?!audit_log)/i', $govde) === 0);
okN("satır kilidi (FOR UPDATE) ve status='closed' koşulu", str_contains($govde, 'FOR UPDATE') && str_contains($govde, "status = 'closed'"));

$sayfa = file_get_contents($root . '/gunluk_isci_puantaj_detay.php');
okN('sayfa: POST kapanis_notu CSRF + işlev, mesai id sunucudan ($id)', preg_match("/action'\] \?\? ''\) === 'kapanis_notu'.*?csrf_check.*?pdks_gunluk_oturum_not_guncelle\(\(int\)\\\$id,/s", $sayfa) === 1);
okN('sayfa: düğme yalnız admin + kapalı mesai + aktif depo', str_contains($sayfa, "\$notDuzenleGoster = is_admin() && \$oturum['status'] === 'closed'"));
okN('sayfa: dialog native <dialog class="pm-dialog"> + textarea kapanis_notu', str_contains($sayfa, '<dialog id="kapanisNotu" class="pm-dialog') && str_contains($sayfa, 'name="kapanis_notu"'));
okN('sayfa: not metni h() ile basılır', str_contains($sayfa, "h(\$oturum['notes'] ?: '—')") && str_contains($sayfa, "h((string)\$oturum['notes'])"));

echo "SONUÇ: $pass geçti, $fail hata\n";
exit($fail === 0 ? 0 : 1);
