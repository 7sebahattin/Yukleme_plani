<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_faz9d_smoke.php — Faz 9D (v231) odaklı doğrulama.
//
// v227 sistem audit'inin H-03 bulgusunu (ödeme sonrası hakediş düzeltmesi
// gerekirse ne olur?) KAPATAN İLERİYE DÖNÜK düzeltme/mahsup modelini
// hedefler — gerçek fonksiyon çağrıları + bellek içi SQLite ile "davranış
// doğru mu" kontrolüdür.
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok9d(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$ADMIN9D = true;
function db(): PDO { global $db; return $db; }
function is_admin(): bool { global $ADMIN9D; return $ADMIN9D; }
function active_depot(): ?string { return 'Depo A'; }
function can(string $p): bool { return true; }

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_cari.php';
require_once $root . '/config/pdks_faz9d.php';
require_once $root . '/config/pdks_rapor.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — pdks_cari_smoke.php/pdks_hakedis_smoke.php
// İLE BİREBİR AYNI (kasıtlı kopya) — foreman_entitlement_adjustments'ın
// INDEX/CONSTRAINT FOREIGN KEY içeren GERÇEK DDL'ini pdks_faz9d_migrate()
// üzerinden GERÇEKTEN çalıştırabilmek için.
// ─────────────────────────────────────────────────────────
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1];
    $govde = $m[2];
    $parcalar = []; $buf = ''; $derinlik = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $derinlik++;
        if ($c === ')') $derinlik--;
        if ($c === ',' && $derinlik === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = []; $indeksler = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")";
            continue;
        }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")";
            continue;
        }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    $create = "CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)";
    return [$create, $indeksler];
}
function pdks_kolon_listesi(string $ham): string
{
    $ham = preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $ham);
    return preg_replace('/\s+/', ' ', trim($ham));
}

// ── Şema — Faz 4/5'in mevcut smoke desenleriyle AYNI temel + Faz 9D'nin
//    YENİ tablosu. ──
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1)");
$db->exec("INSERT INTO foremen (code,name) VALUES ('C1','Ayşe Çavuş'),('C2','Veli Çavuş')");
$foremanId = 1; $foreman2Id = 2;
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER DEFAULT 0, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, line_total TEXT)");
$db->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, payment_date TEXT, amount TEXT, currency TEXT, payment_method TEXT, reference_no TEXT, description TEXT, status TEXT DEFAULT 'valid', created_by_user_id INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, cancelled_at TEXT, cancelled_by_user_id INTEGER, cancellation_reason TEXT)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

// ⚠ pdks_faz9d_tablolar()'ın GERÇEK DDL'i (MySQL sözdizimi) INDEX/
// CONSTRAINT FOREIGN KEY İÇERİR — SQLite'ın CREATE TABLE'ı bunu kabul
// etmez (pdks_cari_smoke.php'nin BİLİNEN, belgelenmiş sınırı — bkz. o
// dosyanın pdks_ddl_sqlite() docblock'u). Çevirici GERÇEK DDL'i SQLite'a
// çevirip TEK yazma yolundan (pdks_faz9d_tablolar()) OLUŞTURUR — ikinci,
// paralel bir şema TANIMLANMAZ. pdks_faz9d_migrate()'in KENDİSİ SONRA bu
// ÖNCEDEN OLUŞTURULMUŞ tabloya karşı İDEMPOTENT ("zaten var") yolunda sınanır.
foreach (pdks_faz9d_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    $db->exec($create);
    foreach ($indeksler as $ix) $db->exec($ix);
}
ok9d('şema hazır (gerçek DDL, SQLite\'a çevrilmiş)', pdks_faz9d_sema_hazir($db));
$mig = pdks_faz9d_migrate($db);
ok9d('migrasyon idempotent — mevcut tabloyu "var" olarak tanır, hata VERMEZ',
    count($mig) > 0 && count(array_filter($mig, fn($r) => $r['durum'] === 'var')) === count($mig), json_encode($mig, JSON_UNESCAPED_UNICODE));

$day = date('Y-m-d', strtotime('-10 days'));
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (1,{$foremanId},'Ayşe Çavuş','C1','{$day}','Depo A','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at,finalized_at) VALUES (1,1,{$foremanId},'Ayşe Çavuş','C1','{$day}','Depo A','final','TRY','50000.00','{$day} 10:00:00','{$day} 11:00:00')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (2,{$foremanId},'Ayşe Çavuş','C1','{$day}','Depo B','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at) VALUES (2,2,{$foremanId},'Ayşe Çavuş','C1','{$day}','Depo B','draft','TRY','12000.00','{$day} 10:00:00')");

// =========================================================
// § A — CREATION (madde 1-8)
// =========================================================
echo "=== A. DÜZELTME OLUŞTURMA ===\n";
$rDraft = pdks_faz9d_duzeltme_ekle(2, '+', '1000', 'Yanlış hakedişe deneme', 1, $db);
ok9d('2) TASLAK hakedişe düzeltme REDDEDİLİR', $rDraft['ok'] === false && ($rDraft['kod'] ?? '') === 'kesin_degil', json_encode($rDraft, JSON_UNESCAPED_UNICODE));

$rZero = pdks_faz9d_duzeltme_ekle(1, '+', '0', 'Sıfır tutar denemesi', 1, $db);
ok9d('3) SIFIR tutar REDDEDİLİR', $rZero['ok'] === false, json_encode($rZero, JSON_UNESCAPED_UNICODE));

$rNoReason = pdks_faz9d_duzeltme_ekle(1, '+', '2500', '', 1, $db);
ok9d('4) GEREKÇESİZ düzeltme REDDEDİLİR', $rNoReason['ok'] === false && ($rNoReason['kod'] ?? '') === 'gerekce_zorunlu', json_encode($rNoReason, JSON_UNESCAPED_UNICODE));

$rPlus = pdks_faz9d_duzeltme_ekle(1, '+', '2500', 'Eksik hesaplanan FM tespit edildi', 1, $db);
ok9d('1) KESİN hakedişe düzeltme KABUL edilir', $rPlus['ok'] === true, json_encode($rPlus, JSON_UNESCAPED_UNICODE));
ok9d('5) + düzeltme DOĞRU İŞARETLE saklanır', $rPlus['ok'] === true && $rPlus['signed_amount'] === '2500.00', json_encode($rPlus, JSON_UNESCAPED_UNICODE));
$plusId = $rPlus['id'];

$rMinus = pdks_faz9d_duzeltme_ekle(1, '-', '3000', 'Fazla sayılan işçi tespit edildi', 1, $db);
ok9d('6) - düzeltme DOĞRU İŞARETLE (negatif) saklanır', $rMinus['ok'] === true && $rMinus['signed_amount'] === '-3000.00', json_encode($rMinus, JSON_UNESCAPED_UNICODE));
$minusId = $rMinus['id'];

ok9d('7) para birimi hakedişten MİRAS alınır (istemciden gelmez)', $rPlus['currency'] === 'TRY' && $rMinus['currency'] === 'TRY');

// 8) client çavuş/para birimi DEĞİŞTİREMEZ — fonksiyon imzasında bu
// parametreler HİÇ YOK (yapısal kanıt), DAVRANIŞSAL kanıt: kaydedilen
// satırın foreman_id'si HER ZAMAN entitlement'ın kendi foreman_id'sidir.
$satirDb = $db->query("SELECT foreman_id, currency FROM foreman_entitlement_adjustments WHERE id={$plusId}")->fetch();
ok9d('8) kaydedilen düzeltmenin foreman_id/currency\'si hakedişin KENDİSİYLE AYNI', (int)$satirDb['foreman_id'] === $foremanId && $satirDb['currency'] === 'TRY');
$sigFn = new ReflectionFunction('pdks_faz9d_duzeltme_ekle');
$paramNames = array_map(fn($p) => $p->getName(), $sigFn->getParameters());
ok9d('8b) fonksiyon imzasında foreman_id/currency parametresi YOK (istemci yapısal olarak gönderemez)', !in_array('foremanId', $paramNames, true) && !in_array('currency', $paramNames, true), json_encode($paramNames));

// =========================================================
// § B — IMMUTABILITY (madde 9-12)
// =========================================================
echo "\n=== B. DEĞİŞMEZLİK — ORİJİNAL KESİN HAKEDİŞ HİÇ ETKİLENMEZ ===\n";
$entBefore = $db->query("SELECT total_amount, status FROM foreman_daily_entitlements WHERE id=1")->fetch();
ok9d('9) orijinal KESİN toplam (50000.00) düzeltme sonrası AYNI', $entBefore['total_amount'] === '50000.00');
ok9d('11) orijinal KESİN status (\'final\') düzeltme sonrası AYNI', $entBefore['status'] === 'final');
ok9d('10) foreman_daily_entitlement_lines satırı YOK/DEĞİŞMEDİ (bu senaryoda hiç yazılmadı — düzeltme fonksiyonu HİÇ INSERT/UPDATE yapmaz)',
    (int)$db->query("SELECT COUNT(*) FROM foreman_daily_entitlement_lines")->fetchColumn() === 0);
// ⚠ Basit str_contains('daily_worker_work_periods') KULLANILMAZ — dosyanın
// KENDİ açıklayıcı başlık yorumu bu tabloyu İSİM olarak anar ("... HİÇ
// DOKUNULMAZ" cümlesinde). Gerçek kanıt: gerçek KODDA (yorumlar hariç) bu
// tabloya yönelik HİÇBİR SQL yazma/okuma İFADESİ YOK.
$faz9dKodSadece = preg_replace('#//.*$#m', '', preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($root . '/config/pdks_faz9d.php')));
ok9d('12) attendance/mesai dönemi tabloları BU DOSYANIN GERÇEK KODUNDA hiç REFERANS EDİLMEDİ/DOKUNULMADI',
    !str_contains($faz9dKodSadece, 'daily_worker_work_periods'));

// =========================================================
// § C — CARİ (madde 13-18)
// =========================================================
echo "\n=== C. CARİ — HAKEDİŞ + DÜZELTME - ÖDEME ===\n";
$bakiye = pdks_cari_bakiye($foremanId, $db);
// Bu noktada: 50000 hakediş + 2500 - 3000 düzeltme = 49500, ödeme yok.
ok9d('13/14) 50k hakediş + (2500-3000) düzeltme => 49500 borç (paid yok)', $bakiye['TRY']['bakiye'] === '49500.00', json_encode($bakiye['TRY'] ?? null));
ok9d('güncel bakiye üç terimi de doğru raporluyor', $bakiye['TRY']['hakedis_toplam'] === '50000.00' && $bakiye['TRY']['duzeltme_toplam'] === '-500.00' && $bakiye['TRY']['odeme_toplam'] === '0.00');

// Senaryo A/B/C/D için TEMİZ bir çavuş (foreman2Id) üzerinde tekrar kurulum.
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (3,{$foreman2Id},'Veli Çavuş','C2','{$day}','Depo A','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at,finalized_at) VALUES (3,3,{$foreman2Id},'Veli Çavuş','C2','{$day}','Depo A','final','TRY','50000.00','{$day} 10:00:00','{$day} 11:00:00')");

// A) final 50k, paid 0, +2000 => 52000
pdks_faz9d_duzeltme_ekle(3, '+', '2000', 'Senaryo A', 1, $db);
$balA = pdks_cari_bakiye($foreman2Id, $db);
ok9d('14E-A) final 50k + paid 0 + adj +2k => bakiye 52000', $balA['TRY']['bakiye'] === '52000.00', json_encode($balA['TRY']));

// Ters kayıtla A'yı nötrle, sonra B/C/D için sıfırdan devam.
$duzA = $db->query("SELECT id FROM foreman_entitlement_adjustments WHERE entitlement_id=3 ORDER BY id DESC LIMIT 1")->fetchColumn();
pdks_faz9d_duzeltme_ters_kayit((int)$duzA, 'Senaryo A temizliği', 1, $db);

// B) final 50k, paid 50k, +2k => 2000
pdks_cari_odeme_ekle($foreman2Id, $day, '50000', 'TRY', 'BANK', null, 'Senaryo B ödeme', 1, $db);
pdks_faz9d_duzeltme_ekle(3, '+', '2000', 'Senaryo B', 1, $db);
$balB = pdks_cari_bakiye($foreman2Id, $db);
ok9d('15/14E-B) final 50k + paid 50k + adj +2k => bakiye 2000', $balB['TRY']['bakiye'] === '2000.00', json_encode($balB['TRY']));

// Bu çavuşu C/D testleri için TEMİZ bir üçüncü çavuşla tekrar kur (B'nin
// ödemesini geri almak yerine, birbirine karışmayan AYRI senaryo çavuşları).
$foreman3Id = 3; $db->exec("INSERT INTO foremen (id,code,name) VALUES (3,'C3','Fatma Çavuş')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (4,{$foreman3Id},'Fatma Çavuş','C3','{$day}','Depo A','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at,finalized_at) VALUES (4,4,{$foreman3Id},'Fatma Çavuş','C3','{$day}','Depo A','final','TRY','50000.00','{$day} 10:00:00','{$day} 11:00:00')");
pdks_cari_odeme_ekle($foreman3Id, $day, '20000', 'TRY', 'BANK', null, 'Senaryo C ödeme', 1, $db);
pdks_faz9d_duzeltme_ekle(4, '-', '5000', 'Senaryo C', 1, $db);
$balC = pdks_cari_bakiye($foreman3Id, $db);
ok9d('16/14E-C) final 50k + paid 20k + adj -5k => bakiye 25000', $balC['TRY']['bakiye'] === '25000.00', json_encode($balC['TRY']));

$foreman4Id = 4; $db->exec("INSERT INTO foremen (id,code,name) VALUES (4,'C4','Mehmet Çavuş')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (5,{$foreman4Id},'Mehmet Çavuş','C4','{$day}','Depo A','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at,finalized_at) VALUES (5,5,{$foreman4Id},'Mehmet Çavuş','C4','{$day}','Depo A','final','TRY','50000.00','{$day} 10:00:00','{$day} 11:00:00')");
pdks_cari_odeme_ekle($foreman4Id, $day, '50000', 'TRY', 'BANK', null, 'Senaryo D ödeme', 1, $db);
pdks_faz9d_duzeltme_ekle(5, '-', '5000', 'Senaryo D', 1, $db);
$balD = pdks_cari_bakiye($foreman4Id, $db);
// Görev talimatı madde 14D: negatif bakiye YAPAY OLARAK SIFIRA KENETLENMEZ
// (çavuş avansı/fazla ödeme olarak KALIR).
ok9d('17/14E-D) final 50k + paid 50k + adj -5k => bakiye -5000 (avans, SIFIRA KENETLENMEZ)', $balD['TRY']['bakiye'] === '-5000.00', json_encode($balD['TRY']));
ok9d('17b) negatif bakiye \'avans\' olarak sınıflandırılır', $balD['TRY']['durum'] === 'avans');

// 18) güncel bakiye raporları (pdks_rapor_*) da düzeltmeyi kullanır.
$topluD = pdks_rapor_bakiye_toplu(null, $foreman4Id, $db);
ok9d('18) pdks_rapor_bakiye_toplu() düzeltmeyi AYNI şekilde yansıtır', $topluD['TRY']['bakiye'] === '-5000.00', json_encode($topluD['TRY'] ?? null));
$cavusTopluD = pdks_rapor_cavus_bakiye_toplu([$foreman4Id], $db);
ok9d('18b) pdks_rapor_cavus_bakiye_toplu() düzeltmeyi AYNI şekilde yansıtır', ($cavusTopluD[$foreman4Id]['TRY']['bakiye'] ?? null) === '-5000.00', json_encode($cavusTopluD[$foreman4Id] ?? null));

// =========================================================
// § D — PAYMENT GUARD (madde 19-21)
// =========================================================
echo "\n=== D. ÖDEME TAVANI — DÜZELTİLMİŞ BAKİYEYİ KULLANIR ===\n";
// Yeni, temiz bir çavuş: final 50k, ödeme yok, +10k düzeltme => tavan 60k.
$foreman5Id = 5; $db->exec("INSERT INTO foremen (id,code,name) VALUES (5,'C5','Zeynep Çavuş')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (6,{$foreman5Id},'Zeynep Çavuş','C5','{$day}','Depo A','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at,finalized_at) VALUES (6,6,{$foreman5Id},'Zeynep Çavuş','C5','{$day}','Depo A','final','TRY','50000.00','{$day} 10:00:00','{$day} 11:00:00')");
$onizlemeOnceki = pdks_cari_odeme_onizleme($foreman5Id, 50000 * 100, 'TRY', $db);
ok9d('düzeltme öncesi 50000 ödeme aşım DEĞİL (tam bakiyeyi karşılıyor)', $onizlemeOnceki['asim'] === false, json_encode($onizlemeOnceki));

pdks_faz9d_duzeltme_ekle(6, '+', '10000', 'Ödeme tavanı testi — artır', 1, $db);
$onizleme60k = pdks_cari_odeme_onizleme($foreman5Id, 60000 * 100, 'TRY', $db);
ok9d('20) + düzeltme sonrası 60000 ödeme artık aşım DEĞİL (yeni tavan)', $onizleme60k['asim'] === false, json_encode($onizleme60k));
$onizleme50k = pdks_cari_odeme_onizleme($foreman5Id, 50000 * 100, 'TRY', $db);
ok9d('19) 50000 ödeme HÂLÂ aşım DEĞİL ama artık DAHA fazla ödenebilir (tavan 60k oldu)', $onizleme50k['asim'] === false && $onizleme50k['yeni_bakiye_kurus'] === 1000000);

$foreman6Id = 6; $db->exec("INSERT INTO foremen (id,code,name) VALUES (6,'C6','Ahmet Çavuş')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (7,{$foreman6Id},'Ahmet Çavuş','C6','{$day}','Depo A','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at,finalized_at) VALUES (7,7,{$foreman6Id},'Ahmet Çavuş','C6','{$day}','Depo A','final','TRY','50000.00','{$day} 10:00:00','{$day} 11:00:00')");
pdks_faz9d_duzeltme_ekle(7, '-', '10000', 'Ödeme tavanı testi — azalt', 1, $db);
$onizleme45k = pdks_cari_odeme_onizleme($foreman6Id, 45000 * 100, 'TRY', $db);
ok9d('21) - düzeltme sonrası 45000 ödeme ARTIK AŞIM (tavan 40k\'a düştü)', $onizleme45k['asim'] === true, json_encode($onizleme45k));
$onizleme40k = pdks_cari_odeme_onizleme($foreman6Id, 40000 * 100, 'TRY', $db);
ok9d('21b) 40000 ödeme tam tavanı karşılıyor, aşım DEĞİL', $onizleme40k['asim'] === false, json_encode($onizleme40k));

// =========================================================
// § E — REVERSAL (madde 22-27)
// =========================================================
echo "\n=== E. TERS KAYIT — DÜZENLEME/FİZİKSEL SİLME YOK ===\n";
$oncekiSayim = (int)$db->query("SELECT COUNT(*) FROM foreman_entitlement_adjustments")->fetchColumn();
$rTersReasonYok = pdks_faz9d_duzeltme_ters_kayit($plusId, '', 1, $db);
ok9d('26) ters kayıt GEREKÇESİZ REDDEDİLİR', $rTersReasonYok['ok'] === false && ($rTersReasonYok['kod'] ?? '') === 'gerekce_zorunlu');

$rTers = pdks_faz9d_duzeltme_ters_kayit($plusId, 'Yanlışlıkla girilmiş, iptal', 1, $db);
ok9d('27) ters kayıt (reason ile) KABUL edilir ve DENETLENİR', $rTers['ok'] === true, json_encode($rTers, JSON_UNESCAPED_UNICODE));
$auditRev = $db->query("SELECT * FROM audit_log WHERE record_id={$plusId} AND action='reverse'")->fetch();
ok9d('27b) ters kayıt audit_log\'a yazıldı', $auditRev !== false);

$sonrakiSayim = (int)$db->query("SELECT COUNT(*) FROM foreman_entitlement_adjustments")->fetchColumn();
ok9d('22) fiziksel SİLME YOK — satır sayısı AZALMADI, YENİ bir satır EKLENDİ', $sonrakiSayim === $oncekiSayim + 1, "önce=$oncekiSayim sonra=$sonrakiSayim");

$origAfterRev = $db->query("SELECT signed_amount, reason, created_at, reversed_at FROM foreman_entitlement_adjustments WHERE id={$plusId}")->fetch();
// ⚠ SQLite NUMERIC affinity ondalık sıfırları kırpar (CLAUDE.md'nin bilinen
// farklılığı) — DB'den okunan ham DECIMAL '2500.00' DEĞİL '2500' dönebilir,
// bu yüzden burada sayısal karşılaştırma yapılır.
ok9d('23) orijinal düzeltmenin tutarı/gerekçesi DEĞİŞMEDİ (yalnız reversed_at eklendi)',
    (float)$origAfterRev['signed_amount'] === 2500.0 && $origAfterRev['reason'] === 'Eksik hesaplanan FM tespit edildi' && $origAfterRev['reversed_at'] !== null,
    json_encode($origAfterRev, JSON_UNESCAPED_UNICODE));
ok9d('25) orijinal düzeltme HÂLÂ GÖRÜNÜR (listelerden kaybolmadı)',
    in_array($plusId, array_column(pdks_faz9d_duzeltmeler(1, $db), 'id'), true));

$netSonra = 0;
foreach ($db->query("SELECT signed_amount FROM foreman_entitlement_adjustments WHERE entitlement_id=1 AND status='valid'")->fetchAll(PDO::FETCH_COLUMN) as $tutar) {
    $netSonra += pdks_hakedis_tl_kurus((string)$tutar);
}
// entitlement=1 üzerinde: +2500 (ters kayıtlı, net etkisi 0) + -3000 (minus, hâlâ geçerli) = -3000.
ok9d('24) ters kayıt sonrası NET finansal etki sıfırlanır (+2500 => 0), yalnız -3000 kalır', $netSonra === -300000, "netSonra=$netSonra");

$rCiftTers = pdks_faz9d_duzeltme_ters_kayit($plusId, 'Tekrar dene', 1, $db);
ok9d('zaten ters kayıtlı bir düzeltme TEKRAR ters kayda alınamaz', $rCiftTers['ok'] === false && ($rCiftTers['kod'] ?? '') === 'zaten_ters_kayitli');

$tersRowId = $rTers['reversal_id'];
$rTersinTersi = pdks_faz9d_duzeltme_ters_kayit($tersRowId, 'Ters kaydın tersi denemesi', 1, $db);
ok9d('bir TERS KAYIT tekrar ters kayda alınamaz', $rTersinTersi['ok'] === false && ($rTersinTersi['kod'] ?? '') === 'ters_kaydin_tersi');

// =========================================================
// § F — EKSTRE (madde 28-31)
// =========================================================
echo "\n=== F. EKSTRE — DÜZELTME/MAHSUP KENDİ OLAYI, KRONOLOJİ + KOŞAN BAKİYE ===\n";
$ekstre = pdks_cari_ekstre($foremanId, null, null, $db);
$tipler = array_column($ekstre['TRY'], 'tip');
ok9d('28) + düzeltme ekstrede KENDİ olay türüyle (DUZELTME) görünür', in_array('DUZELTME', $tipler, true), json_encode($tipler));
ok9d('29) - düzeltme ekstrede KENDİ olay türüyle (MAHSUP) görünür', in_array('MAHSUP', $tipler, true), json_encode($tipler));

$tarihler = array_column($ekstre['TRY'], 'tarih');
$kronoDogru = true;
for ($i = 1; $i < count($ekstre['TRY']); $i++) {
    if (strcmp((string)$ekstre['TRY'][$i]['tarih'], (string)$ekstre['TRY'][$i-1]['tarih']) < 0) { $kronoDogru = false; break; }
}
ok9d('30) ekstre KRONOLOJİK sırada (tarih azalmıyor)', $kronoDogru, json_encode($tarihler));

// ⚠ $bakiye (§C'de hesaplandı) §E'deki ters kayıttan ÖNCEKİ ANLIK
// GÖRÜNTÜDÜR (entitlement 1'in +2500'ü nötrlenmeden önce) — bu YÜZDEN
// TAZE bir pdks_cari_bakiye() çağrısıyla karşılaştırılır, bayat değerle DEĞİL.
$bakiyeGuncel = pdks_cari_bakiye($foremanId, $db);
$sonSatir = end($ekstre['TRY']);
ok9d('31) koşan bakiye SON satırda gerçek (güncel) bakiyeyle EŞLEŞİR', $sonSatir['kosan_bakiye'] === $bakiyeGuncel['TRY']['bakiye'], json_encode([$sonSatir['kosan_bakiye'], $bakiyeGuncel['TRY']['bakiye']]));

// =========================================================
// § G — RAPOR (madde 32-33)
// =========================================================
echo "\n=== G. RAPOR — GÜNCEL BAKİYE DÜZELTMEYİ İÇERİR, OPERASYONEL KPI DEĞİŞMEZ ===\n";
$rapBakiye = pdks_rapor_bakiye_toplu(null, $foremanId, $db);
ok9d('32) pdks_rapor_bakiye_toplu() GÜNCEL bakiye düzeltmeyi İÇERİR', $rapBakiye['TRY']['bakiye'] === $bakiyeGuncel['TRY']['bakiye'], json_encode($rapBakiye['TRY'] ?? null));
// 33) operasyonel/attendance fonksiyonları BU DOSYADAN hiç ETKİLENMEDİ —
// yapısal kanıt: pdks_faz9d.php hiçbir attendance/puantaj fonksiyonu
// TANIMLAMAZ/DEĞİŞTİRMEZ (yalnız kendi düzeltme fonksiyonları).
$faz9dSrc = (string)file_get_contents($root . '/config/pdks_faz9d.php');
ok9d('33) pdks_faz9d.php GERÇEK KODUNDA pdks_rapor_operasyonel_kpi/pdks_gunluk_oturum çağrısı YOK (operasyonel KPI\'a DOKUNULMADI)',
    !str_contains($faz9dSrc, 'pdks_rapor_operasyonel_kpi') && !str_contains($faz9dSrc, 'pdks_gunluk_oturum'));

// =========================================================
// § H — ÇOKLU PARA BİRİMİ (madde 34-35)
// =========================================================
echo "\n=== H. ÇOKLU PARA BİRİMİ — ASLA KARIŞTIRILMAZ ===\n";
$foreman7Id = 7; $db->exec("INSERT INTO foremen (id,code,name) VALUES (7,'C7','Usd Çavuş')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (8,{$foreman7Id},'Usd Çavuş','C7','{$day}','Depo A','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at,finalized_at) VALUES (8,8,{$foreman7Id},'Usd Çavuş','C7','{$day}','Depo A','final','USD','1000.00','{$day} 10:00:00','{$day} 11:00:00')");
$rUsd = pdks_faz9d_duzeltme_ekle(8, '+', '100', 'USD düzeltme testi', 1, $db);
ok9d('34) USD hakedişe eklenen düzeltme USD PARA BİRİMİNİ MİRAS alır', $rUsd['ok'] === true && $rUsd['currency'] === 'USD', json_encode($rUsd, JSON_UNESCAPED_UNICODE));

$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (9,{$foreman7Id},'Usd Çavuş','C7','{$day}','Depo A','closed')");
$db->exec("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,currency,total_amount,calculated_at,finalized_at) VALUES (9,9,{$foreman7Id},'Usd Çavuş','C7','{$day}','Depo A','final','TRY','5000.00','{$day} 10:00:00','{$day} 11:00:00')");
pdks_faz9d_duzeltme_ekle(9, '+', '500', 'TRY düzeltme testi', 1, $db);
$bakiye7 = pdks_cari_bakiye($foreman7Id, $db);
ok9d('35) aynı çavuşun USD ve TRY bakiyeleri AYRI anahtarlarda, ASLA TOPLANMAZ',
    isset($bakiye7['USD']) && isset($bakiye7['TRY']) && $bakiye7['USD']['bakiye'] === '1100.00' && $bakiye7['TRY']['bakiye'] === '5500.00',
    json_encode($bakiye7));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
