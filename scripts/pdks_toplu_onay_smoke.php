<?php
// =========================================================
// scripts/pdks_toplu_onay_smoke.php — v507 Mesai TOPLU ONAY (gün/çavuş/depo kapsamı).
// bekleyenler → önizleme → uygula; gerçek onay yolu (pdks_faz8b_degerlendirme_kaydet), bellek içi SQLite.
// CANLI VERİTABANINA DOKUNMAZ.   php scripts/pdks_toplu_onay_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);

$AUDIT_DB = null;
function audit_log_event(string $action, string $module, ?int $record_id = null, ?array $old_values = null, ?array $new_values = null, ?int $explicit_user_id = null): void {
    global $AUDIT_DB;
    if (!$AUDIT_DB) return;
    $AUDIT_DB->prepare("INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values) VALUES (?,?,?,?,?,?)")
        ->execute([$explicit_user_id, $action, $module, $record_id, json_encode($old_values, JSON_UNESCAPED_UNICODE), json_encode($new_values, JSON_UNESCAPED_UNICODE)]);
}

require_once __DIR__ . '/../config/pdks_faz8b.php';
require_once __DIR__ . '/../config/pdks_toplu_onay.php';

$gecen = 0; $hata = 0;
function ok8bt(string $ad, bool $kosul, string $detay = ''): void {
    global $gecen, $hata;
    if ($kosul) { $gecen++; echo "OK  - {$ad}\n"; }
    else { $hata++; echo "HATA- {$ad}" . ($detay !== '' ? " :: {$detay}" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['AUDIT_DB'] = $db;

$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(20) NOT NULL, name VARCHAR(150) NOT NULL)");
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(30) NOT NULL, name VARCHAR(80) NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no VARCHAR(30) NOT NULL)");
$db->exec("CREATE TABLE daily_work_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INT NOT NULL,
    foreman_name_snapshot VARCHAR(150) NOT NULL DEFAULT '', foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '',
    normal_work_minutes_snapshot INT NOT NULL DEFAULT 540,
    work_date DATE NOT NULL, depo VARCHAR(150) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'closed'
)");
$db->exec("CREATE TABLE foreman_worker_rates (
    id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INT NOT NULL, worker_type_id INT NOT NULL,
    daily_rate DECIMAL(12,2) NOT NULL, currency VARCHAR(10) NOT NULL DEFAULT 'TRY',
    valid_from DATE NOT NULL, valid_to DATE NULL, is_active INT NOT NULL DEFAULT 1, created_by_user_id INT NULL
)");
$db->exec("CREATE TABLE daily_worker_work_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT NOT NULL, worker_card_id INT NOT NULL,
    worker_type_id_snapshot INT NULL, worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '',
    entry_time DATETIME NOT NULL, exit_time DATETIME NULL,
    declared_attendance_class VARCHAR(10) NOT NULL DEFAULT 'tam', approved_attendance_class VARCHAR(10) NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'scan',
    is_voided INT NOT NULL DEFAULT 0, voided_at DATETIME NULL, voided_by_user_id INT NULL, void_reason VARCHAR(500) NULL
)");
$db->exec("CREATE TABLE foreman_daily_entitlements (
    id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT NOT NULL UNIQUE, foreman_id INT NOT NULL,
    foreman_name_snapshot VARCHAR(150) NOT NULL DEFAULT '', foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '',
    work_date DATE NOT NULL, depo VARCHAR(150) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'draft',
    currency VARCHAR(10) NOT NULL DEFAULT 'TRY', total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    calculated_at DATETIME NULL, calculated_by_user_id INT NULL, finalized_at DATETIME NULL, finalized_by_user_id INT NULL,
    missing_exit_ack INT NOT NULL DEFAULT 0, notes TEXT NULL, updated_at DATETIME NULL
)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INT NOT NULL, worker_type_id INT NULL,
    worker_type_code_snapshot VARCHAR(30) NOT NULL DEFAULT '', worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '',
    worker_count INT NOT NULL, unit_rate DECIMAL(12,2) NOT NULL, line_total DECIMAL(14,2) NOT NULL
)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$mig = pdks_faz8b_migrate($db);
ok8bt('Faz 8B migrasyonu hatasız', count(array_filter($mig, fn($r) => $r['durum'] === 'hata')) === 0, json_encode($mig, JSON_UNESCAPED_UNICODE));

$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C001','Ayşe Çavuş'),(2,'C002','Mehmet Çavuş')");
$db->exec("INSERT INTO worker_types (id,code,name) VALUES (1,'KADIN','Kadın'),(2,'ERKEK','Erkek'),(3,'KARISIK','Karışık')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES
    (1,1,'Ayşe Çavuş','C001',540,'2026-10-24','FİNİKE','closed'),
    (2,2,'Mehmet Çavuş','C002',540,'2026-10-24','FİNİKE','closed'),
    (3,1,'Ayşe Çavuş','C001',540,'2026-10-24','KARAMAN','closed')");
for ($i = 1; $i <= 30; $i++) $db->prepare("INSERT INTO worker_cards (id,card_no) VALUES (?,?)")->execute([$i, sprintf('K%03d', $i)]);
pdks_faz8b_oran_ekle(1, 1, '1500', '900', 'hourly', '200', '2026-09-01', 'TRY', 1, $db);   // çavuş 1 / Kadın fiyatlı
pdks_faz8b_oran_ekle(2, 1, '1500', '900', 'hourly', '200', '2026-09-01', 'TRY', 1, $db);   // çavuş 2 / Kadın fiyatlı
// çavuş 1 / Erkek (tip 2) için fiyat YOK; Karışık zaten fiyatlanmaz.

function per(PDO $db, int $s, int $card, int $tip, string $tipAd, string $g, string $c, string $src = 'manual'): int {
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,declared_attendance_class,source) VALUES (?,?,?,?,?,?, 'tam', ?)")
       ->execute([$s, $card, $tip, $tipAd, "2026-10-24 {$g}:00", $c === '' ? null : "2026-10-24 {$c}:00", $src]);
    return (int)$db->lastInsertId();
}
function onay(PDO $db, int $id): array { return $db->query("SELECT approved_attendance_class AS a, overtime_approved_hours AS h FROM daily_worker_work_periods WHERE id=$id")->fetch(); }

$pFm1      = per($db, 1, 1, 1, 'Kadın', '08:00', '17:16');                // otomatik Tam + FM adayı 1 sa (elle)
$pFm2      = per($db, 1, 2, 1, 'Kadın', '08:00', '18:20');                // FM adayı 2 sa (elle)
$pFmKart   = per($db, 1, 3, 1, 'Kadın', '08:00', '17:16', 'scan');        // FM adayı 1 sa (KART)
$pKisa     = per($db, 1, 4, 1, 'Kadın', '08:00', '12:00');                // 4 sa — karar bekler
$pKisa2    = per($db, 1, 5, 1, 'Kadın', '08:00', '10:00');                // 2 sa — Yarım olamaz
$pOto      = per($db, 1, 6, 1, 'Kadın', '08:00', '17:00');                // zaten hazır
$pFiyatsiz = per($db, 1, 7, 2, 'Erkek', '08:00', '17:16');                // Erkek fiyatsız
$pAcik     = per($db, 1, 8, 1, 'Kadın', '08:00', '');                     // çıkışsız
$pKaris    = per($db, 1, 9, 3, 'Karışık', '08:00', '17:16');              // Karışık atanmamış
$pBaskaDepo= per($db, 3, 10, 1, 'Kadın', '08:00', '17:16');               // KARAMAN deposu
$pOtuz     = per($db, 2, 11, 1, 'Kadın', '08:00', '17:16');               // başka çavuş (elle)

echo "=== 1. LİSTELEME (aktif depo FİNİKE, 24.10.2026) ===\n";
$l = pdks_toplu_onay_bekleyenler('2026-10-24', 'FİNİKE', null, false, $db);
$ids = array_map(fn($s) => (int)$s['d']['id'], $l);
ok8bt('1a hazır (otomatik Tam) listede YOK', !in_array($pOto, $ids, true));
ok8bt('1b başka depo (KARAMAN) listede YOK', !in_array($pBaskaDepo, $ids, true));
ok8bt('1c bekleyen 9 dönem listede', count(array_intersect($ids, [$pFm1,$pFm2,$pFmKart,$pKisa,$pKisa2,$pFiyatsiz,$pAcik,$pKaris,$pOtuz])) === 9, json_encode($ids));
$lc = pdks_toplu_onay_bekleyenler('2026-10-24', 'FİNİKE', 2, false, $db);
ok8bt('1d çavuş filtresi', count($lc) === 1 && (int)$lc[0]['d']['id'] === $pOtuz);
$le = pdks_toplu_onay_bekleyenler('2026-10-24', 'FİNİKE', null, true, $db);
ok8bt('1e yalnız-elle filtresi kart okutulanı (source=scan) dışlar', !in_array($pFmKart, array_map(fn($s) => (int)$s['d']['id'], $le), true) && in_array($pFm1, array_map(fn($s) => (int)$s['d']['id'], $le), true));
ok8bt('1f önce: oturum 1 tam_hazir DEĞİL', pdks_faz8b_oturum_ozeti(1, $db)['tam_hazir'] === false);

echo "\n=== 2. ÖNİZLEME YAN ETKİSİZ + SEÇİMSİZ KARAR = KARAR GEREKLİ ===\n";
$o = pdks_toplu_onay_calistir([$pFm1, $pKisa], null, null, 7, 'FİNİKE', false, $db);
ok8bt('2a seçimsiz: ikisi de "karar" (onay 0)', $o['sayac']['karar'] === 2 && $o['sayac']['onay'] === 0, json_encode($o['sayac']));
ok8bt('2b önizleme hiçbir şey yazmadı', onay($db, $pFm1)['h'] === null && onay($db, $pKisa)['a'] === null);
$o2 = pdks_toplu_onay_calistir([$pFm1, $pKisa], 'tam', 'onayla', 7, 'FİNİKE', false, $db);
ok8bt('2c seçimle: ikisi "onay"', $o2['sayac']['onay'] === 2, json_encode($o2['sayac']));
ok8bt('2d önizleme yine yazmadı (toplu_onay audit yok)', onay($db, $pFm1)['h'] === null && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='toplu_onay'")->fetchColumn() === 0);
$u0 = pdks_toplu_onay_calistir([$pFm1], 'tam', 'onayla', 7, 'FİNİKE', true, $db);
ok8bt('2e uygula: audit toplu_onay + adet', $u0['uygulandi'] === true
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='toplu_onay'")->fetchColumn() === 1
    && str_contains((string)$db->query("SELECT new_values FROM audit_log WHERE action='toplu_onay'")->fetchColumn(), '"adet":1'));
ok8bt('2f FM onayı gerçek yoldan yazıldı (1 sa)', (int)onay($db, $pFm1)['h'] === 1);

echo "\n=== 3. UYGUNSUZLAR ONAYLANMAZ; UYGUNLAR ONAYLANIR ===\n";
$hepsi = [$pFm2, $pFmKart, $pKisa, $pKisa2, $pOto, $pFiyatsiz, $pAcik, $pKaris, $pBaskaDepo, 999999];
$u = pdks_toplu_onay_calistir($hepsi, 'yarim', 'onayla', 7, 'FİNİKE', true, $db);
$by = []; foreach ($u['satirlar'] as $s) $by[(int)$s['d']['id']] = $s['plan'];
ok8bt('3a ok + uygulandi', $u['ok'] === true && $u['uygulandi'] === true, json_encode($u['hata']));
ok8bt('3b 4 sa kısa + Yarım → onaylandı (yarim)', onay($db, $pKisa)['a'] === 'yarim');
ok8bt('3c 2 sa kısa + Yarım → REDDEDİLDİ (3 saat kuralı), yazılmadı', ($by[$pKisa2]['durum'] ?? '') === 'uygunsuz' && onay($db, $pKisa2)['a'] === null);
ok8bt('3d FM adayları (elle + kart) tam saat onaylandı', (int)onay($db, $pFm2)['h'] === 2 && (int)onay($db, $pFmKart)['h'] === 1);
ok8bt('3e zaten hazır dönem "atla" (dokunulmadı)', ($by[$pOto]['durum'] ?? '') === 'atla' && onay($db, $pOto)['a'] === null);
ok8bt('3f v508: fiyatsız tip ENGELLENMEZ — onaylandı (FM 1 sa) + sarı uyarı', ($by[$pFiyatsiz]['durum'] ?? '') === 'onay' && (int)onay($db, $pFiyatsiz)['h'] === 1
    && str_contains((string)($by[$pFiyatsiz]['uyari'] ?? ''), 'fiyat girilene dek hakediş hesaplanamaz') && $u['sayac']['fiyatsiz'] === 1, json_encode($by[$pFiyatsiz] ?? null, JSON_UNESCAPED_UNICODE));
ok8bt('3g v508: çıkışsız dönem eski akış gibi Tam/Yarım kararıyla onaylanır (seçilen Yarım yazıldı) + uyarı', ($by[$pAcik]['durum'] ?? '') === 'onay' && onay($db, $pAcik)['a'] === 'yarim'
    && str_contains((string)($by[$pAcik]['uyari'] ?? ''), 'Çıkış yok') && str_contains((string)($by[$pAcik]['uyari'] ?? ''), '3 sa kuralı doğrulanamaz'), json_encode($by[$pAcik] ?? null, JSON_UNESCAPED_UNICODE));
$pAcik0 = per($db, 1, 15, 1, 'Kadın', '08:00', '');
$yAcik = pdks_toplu_onay_calistir([$pAcik0], null, null, 7, 'FİNİKE', false, $db);
ok8bt('3g2 çıkışsız + karar seçilmedi → "karar gerekli" (sınıf tahmin edilmez)', $yAcik['sayac']['karar'] === 1 && $yAcik['sayac']['onay'] === 0);
$db->exec("UPDATE daily_worker_work_periods SET is_voided=1, voided_at='2026-10-24 20:00:00' WHERE id = $pAcik0");
ok8bt('3h Karışık atanmamış onaylanmadı', ($by[$pKaris]['durum'] ?? '') === 'uygunsuz' && onay($db, $pKaris)['h'] === null);
ok8bt('3i BAŞKA DEPO id\'si onaylanmadı (depo kapısı)', ($by[$pBaskaDepo]['durum'] ?? '') === 'uygunsuz' && onay($db, $pBaskaDepo)['h'] === null);
ok8bt('3j olmayan id sayılır (yok=1), hata yok', $u['sayac']['yok'] === 1);
$oz3 = pdks_faz8b_oturum_ozeti(1, $db);
ok8bt('3k kalan bekleyenler yalnız uygunsuzlar (1 sınıf: 2sa; 1 FM: Karışık)', $oz3['bekleyen_fazla_mesai'] === 1 && $oz3['bekleyen_sinif'] === 1, json_encode($oz3));

echo "\n=== 4. HAKEDİŞ HESAPLANABİLİR (bekleyen kalmayınca) ===\n";
$db->exec("UPDATE daily_worker_work_periods SET is_voided=1, voided_at='2026-10-24 20:00:00' WHERE id IN ($pKisa2,$pKaris)");
$oz = pdks_faz8b_oturum_ozeti(1, $db);
ok8bt('4a oturum 1 tam_hazir (onaylar yazıldı)', $oz['tam_hazir'] === true, json_encode($oz));
$hk0 = pdks_faz8b_hakedis_hesapla(1, 7, $db);
ok8bt('4b v508: fiyatsız Erkek onaylı ama hakediş HESAPLANAMAZ (uyarı gerçek)', empty($hk0['ok']) && str_contains((string)($hk0['hata'] ?? ''), 'geçerli fiyat yok'), json_encode($hk0, JSON_UNESCAPED_UNICODE));
$db->exec("UPDATE daily_worker_work_periods SET is_voided=1, voided_at='2026-10-24 20:00:00' WHERE id = $pFiyatsiz");
$hk = pdks_faz8b_hakedis_hesapla(1, 7, $db);
ok8bt('4c fiyatsız kayıt çıkınca hakediş hesaplandı (çıkışsız Yarım dahil)', !empty($hk['ok']), json_encode($hk, JSON_UNESCAPED_UNICODE));

echo "\n=== 5. KESİNLEŞMİŞ HAKEDİŞE DOKUNULMAZ ===\n";
$pYeni = per($db, 2, 12, 1, 'Kadın', '08:00', '17:16');
$db->exec("INSERT INTO foreman_daily_entitlements (session_id,foreman_id,work_date,depo,status) VALUES (2,2,'2026-10-24','FİNİKE','final')");
$k = pdks_toplu_onay_calistir([$pYeni, $pOtuz], 'tam', 'onayla', 7, 'FİNİKE', true, $db);
ok8bt('5a kesin oturumdaki satırlar uygunsuz, onay 0', $k['sayac']['uygunsuz'] === 2 && $k['sayac']['onay'] === 0 && $k['ok'] === false, json_encode($k['sayac']));
ok8bt('5b yazılmadı', onay($db, $pYeni)['h'] === null && onay($db, $pOtuz)['h'] === null);

echo "\n=== 6. HEP-YA-HİÇ (beklenmedik hata → hepsi geri alınır) ===\n";
$pA = per($db, 1, 13, 1, 'Kadın', '08:00', '17:16');
$pB = per($db, 1, 14, 1, 'Kadın', '08:00', '17:16');
$db->exec("DELETE FROM foreman_daily_entitlements WHERE session_id = 1");
$db->exec("CREATE TRIGGER trg_hata BEFORE UPDATE ON daily_worker_work_periods WHEN NEW.id = $pB BEGIN SELECT RAISE(ABORT, 'zorla hata'); END");
$u6 = pdks_toplu_onay_calistir([$pA, $pB], 'tam', 'onayla', 7, 'FİNİKE', true, $db);
ok8bt('6a hata → ok=false, uygulandi=false', $u6['ok'] === false && $u6['uygulandi'] === false, json_encode($u6['hata'], JSON_UNESCAPED_UNICODE));
ok8bt('6b İLK satır da geri alındı (hiçbiri yazılmadı)', onay($db, $pA)['h'] === null && onay($db, $pB)['h'] === null);
$db->exec("DROP TRIGGER trg_hata");

echo "\n=== 7. GİRDİ KAPILARI ===\n";
ok8bt('7a boş seçim reddedilir', pdks_toplu_onay_calistir([], 'tam', 'onayla', 7, 'FİNİKE', true, $db)['ok'] === false);
ok8bt('7b geçersiz karar değeri yok sayılır (karar gerekli)', pdks_toplu_onay_calistir([$pA], 'xx', 'yy', 7, 'FİNİKE', false, $db)['sayac']['karar'] === 1);
ok8bt('7c aktif depo yoksa hiçbir şey onaylanmaz', pdks_toplu_onay_calistir([$pA], 'tam', 'onayla', 7, '', true, $db)['sayac']['uygunsuz'] === 1);
$r7 = pdks_toplu_onay_calistir([$pA], 'tam', 'reddet', 7, 'FİNİKE', true, $db);
ok8bt('7d FM reddet yolu (0 saat yazılır)', $r7['ok'] && onay($db, $pA)['h'] !== null && (int)onay($db, $pA)['h'] === 0);

echo "\n=== 9. OTOMATİK ONAY (yalnız source=manual; sınıf tahmin edilmez) ===\n";
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (4,1,'Ayşe Çavuş','C001',540,'2026-10-24','FİNİKE','closed')");
$m1 = per($db, 4, 16, 1, 'Kadın', '08:00', '17:16');            // manual, FM 1
$m2 = per($db, 4, 17, 1, 'Kadın', '08:00', '18:20');            // manual, FM 2
$m3 = per($db, 4, 18, 1, 'Kadın', '08:00', '12:00');            // manual, 4 sa → Tam/Yarım belirsiz
$m4 = per($db, 4, 19, 1, 'Kadın', '08:00', '10:00');            // manual, 2 sa → <3 sa
$m5 = per($db, 4, 20, 1, 'Kadın', '08:00', '');                 // manual, çıkışsız
$s1 = per($db, 4, 21, 1, 'Kadın', '08:00', '17:16', 'scan');    // KART — otomatik onaylanmaz
$f1 = per($db, 4, 22, 2, 'Erkek', '08:00', '17:16');            // manual, fiyatsız tip → onay + uyarı
$k1 = per($db, 4, 23, 3, 'Karışık', '08:00', '17:16');          // Karışık → onaylanmaz
$m6 = per($db, 4, 24, 1, 'Kadın', '08:00', '17:00');            // zaten hazır
$ids9 = [$m1, $m2, $m3, $m4, $m5, $s1, $f1, $k1, $m6];
$audit0 = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='toplu_onay'")->fetchColumn();
$o9 = pdks_toplu_onay_calistir($ids9, 'tam', 'onayla', 7, 'FİNİKE', false, $db, true);   // seçiciler VERİLSE de yok sayılır
$p9 = []; foreach ($o9['satirlar'] as $s) $p9[(int)$s['d']['id']] = $s['plan'];
ok8bt('9a sayaç: onay 3 (m1,m2,f1) · karar 5 (m3,m4,m5,s1 + …) beklenen', $o9['sayac']['onay'] === 3 && $o9['sayac']['karar'] === 4 && $o9['sayac']['uygunsuz'] === 1 && $o9['sayac']['atla'] === 1, json_encode($o9['sayac']));
ok8bt('9b özet: 3 Tam, 0 Yarım, 4 sa FM, 1 fiyatsız', $o9['sayac']['tam'] === 3 && $o9['sayac']['yarim'] === 0 && $o9['sayac']['fm_saat'] === 4 && $o9['sayac']['fiyatsiz'] === 1, json_encode($o9['sayac']));
ok8bt('9c 3 sa altı manual → karar gerekli + neden', ($p9[$m4]['durum'] ?? '') === 'karar' && str_contains($p9[$m4]['neden'], '3 saatten az'), json_encode($p9[$m4] ?? null, JSON_UNESCAPED_UNICODE));
ok8bt('9d 4 sa manual (Tam/Yarım belirsiz) → karar gerekli, Tam TAHMİN EDİLMEZ', ($p9[$m3]['durum'] ?? '') === 'karar' && str_contains($p9[$m3]['neden'], 'belirsiz'));
ok8bt('9e çıkışsız manual → karar gerekli', ($p9[$m5]['durum'] ?? '') === 'karar' && str_contains($p9[$m5]['neden'], 'Çıkış yok'));
ok8bt('9f KART okutulmuş (scan) → karar, otomatik onaylanmaz', ($p9[$s1]['durum'] ?? '') === 'karar' && str_contains($p9[$s1]['neden'], 'Kart okutulmuş'));
ok8bt('9g Karışık → onaylanmaz', ($p9[$k1]['durum'] ?? '') === 'uygunsuz');
ok8bt('9h önizleme yan etkisiz', onay($db, $m1)['h'] === null && onay($db, $f1)['h'] === null && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='toplu_onay'")->fetchColumn() === $audit0);
$yanlis = pdks_toplu_onay_calistir($ids9, null, null, 7, 'FİNİKE', true, $db, true, 2);
ok8bt('9i önizleme adedi uyuşmazsa HİÇBİR ŞEY yazılmaz', $yanlis['ok'] === false && $yanlis['uygulandi'] === false && onay($db, $m1)['h'] === null && str_contains((string)$yanlis['hata'], 'değişti'), json_encode($yanlis['hata'], JSON_UNESCAPED_UNICODE));
$db->exec("CREATE TRIGGER trg_hata9 BEFORE UPDATE ON daily_worker_work_periods WHEN NEW.id = $f1 BEGIN SELECT RAISE(ABORT, 'zorla hata'); END");
$h9 = pdks_toplu_onay_calistir($ids9, null, null, 7, 'FİNİKE', true, $db, true, 3);
ok8bt('9j hep-ya-hiç: sondaki satır hata verirse m1/m2 de geri alınır', $h9['ok'] === false && onay($db, $m1)['h'] === null && onay($db, $m2)['h'] === null);
$db->exec("DROP TRIGGER trg_hata9");
$u9 = pdks_toplu_onay_calistir($ids9, 'tam', 'onayla', 7, 'FİNİKE', true, $db, true, 3);
ok8bt('9k uygulandı; FM tam saat yazıldı (1,2,1)', $u9['ok'] && $u9['uygulandi'] && (int)onay($db, $m1)['h'] === 1 && (int)onay($db, $m2)['h'] === 2 && (int)onay($db, $f1)['h'] === 1, json_encode($u9['hata'], JSON_UNESCAPED_UNICODE));
ok8bt('9l karar gerekli / kart / Karışık satırlara DOKUNULMADI (sınıf + FM boş)', onay($db, $m3)['a'] === null && onay($db, $m4)['a'] === null && onay($db, $m5)['a'] === null && onay($db, $s1)['h'] === null && onay($db, $s1)['a'] === null && onay($db, $k1)['h'] === null);
ok8bt('9m otomatik sınıf kararı yazılmadı (Tam otomatik; approved_attendance_class boş)', onay($db, $m1)['a'] === null && onay($db, $m2)['a'] === null);
$a9 = (string)$db->query("SELECT new_values FROM audit_log WHERE action='toplu_onay' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok8bt('9n özet audit: mod otomatik_elle + adet/tam/fm_saat', str_contains($a9, '"mod":"otomatik_elle"') && str_contains($a9, '"adet":3') && str_contains($a9, '"fm_saat":4'), $a9);
// Plan düzeyi: otomatik, FM kararı verilmiş dönemi ezmez
$pz = pdks_toplu_onay_plan(['faz8b' => ['finans_hazir' => false, 'toplam_dk' => 556, 'sinif_onayi_gerekli' => false, 'fazla_mesai_saat' => 1, 'fazla_mesai_onay_saat' => 0, 'etkin_sinif' => 'tam'], 'worker_type_name_snapshot' => 'Kadın', 'source' => 'manual'], false, true, null, null, true);
ok8bt('9o otomatik: verilmiş FM kararı (reddet=0) AYNEN geçer', $pz['durum'] === 'onay' && $pz['fm'] === 0);
// Seçili (eski) akış: çıkışsız + Tam
$sa = pdks_toplu_onay_plan(['faz8b' => ['finans_hazir' => false, 'toplam_dk' => null, 'sinif_onayi_gerekli' => true, 'fazla_mesai_saat' => 0, 'fazla_mesai_onay_saat' => null, 'etkin_sinif' => null], 'worker_type_name_snapshot' => 'Kadın', 'source' => 'scan'], false, true, 'tam', null);
ok8bt('9p seçili akış: kart okutmuş çıkışsız + Tam kararı → onay', $sa['durum'] === 'onay' && $sa['sinif'] === 'tam' && str_contains($sa['uyari'], 'Çıkış yok'));

echo "\n=== 8. STATİK: İKİNCİ YAZMA YOLU YOK + KAPILAR ===\n";
$cfg = (string)file_get_contents(__DIR__ . '/../config/pdks_toplu_onay.php');
$sayfa = (string)file_get_contents(__DIR__ . '/../mesai_toplu_onay.php') . (string)file_get_contents(__DIR__ . '/../_toplu_onay_ekran.php');
$cfgKod = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $cfg);
ok8bt('8a config: doğrudan UPDATE/INSERT/DELETE yok', !preg_match('/\b(UPDATE\s+\w|INSERT\s+INTO|DELETE\s+FROM)\b/i', (string)$cfgKod));
ok8bt('8b tek yazma yolu pdks_faz8b_degerlendirme_kaydet', str_contains($cfg, 'pdks_faz8b_degerlendirme_kaydet('));
ok8bt('8c sayfa: CSRF + yetki + depo/şema kapısı', str_contains($sayfa, 'csrf_check(') && str_contains($sayfa, "require_pdks_hakedis('entitlements_finalize')") && str_contains($sayfa, 'pdks_faz8b_sayfa_kapisi'));
ok8bt('8d sayfa: sınıf/FM kararı varsayılanı BOŞ (otomatik onay yok)', substr_count($sayfa, '<option value="">Seçiniz') === 2);
ok8bt('8e sayfa: önizleme + ayrı uygula', str_contains($sayfa, "'onizle'") && str_contains($sayfa, "'uygula'"));
ok8bt('8e2 sayfa: otomatik onay ayrı buton + önizleme + beklenen adet; id sunucuda çözülür', str_contains($sayfa, 'Elle girilenleri otomatik onayla') && str_contains($sayfa, "'oto_onizle'") && str_contains($sayfa, "'oto_uygula'")
    && str_contains($sayfa, 'name="beklenen"') && str_contains($sayfa, 'pdks_toplu_onay_bekleyenler($tarih, $depo, $cavusId, true, $pdo)'));
ok8bt('8f cavus_hakedis.php Toplu Onay bağlantısı', str_contains((string)file_get_contents(__DIR__ . '/../cavus_hakedis.php'), 'mesai_toplu_onay.php'));
require_once __DIR__ . '/../config/role_pdks_matrix.php';
ok8bt('8g rol matrisi: route kayıtlı, uygula=mesai edit, önizle=read', (role_pdks_routes()['mesai_toplu_onay.php'] ?? '') === 'mesai'
    && role_pdks_request_action('mesai_toplu_onay.php', 'POST', [], ['action' => 'uygula']) === ['mesai', 'edit']
    && role_pdks_request_action('mesai_toplu_onay.php', 'POST', [], ['action' => 'onizle']) === ['mesai', 'read']
    && role_pdks_request_action('mesai_toplu_onay.php', 'POST', [], ['action' => 'oto_uygula']) === ['mesai', 'edit']
    && role_pdks_request_action('mesai_toplu_onay.php', 'POST', [], ['action' => 'oto_onizle']) === ['mesai', 'read']
    && role_pdks_request_action('mesai_toplu_onay.php', 'POST', [], ['action' => 'x']) === null);
ok8bt('8h nav_ptak_sayfalari kaynağında', str_contains((string)file_get_contents(__DIR__ . '/../config/helpers.php'), "'mesai_toplu_onay.php'"));

echo "\n=== SONUÇ: $gecen geçti, $hata hata ===\n";
exit($hata === 0 ? 0 : 1);
