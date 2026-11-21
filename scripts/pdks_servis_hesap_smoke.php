<?php
declare(strict_types=1);
// Servis Hesabı (v532): servis hakedişe YAZILMAZ; günlük döküm, ödeme defteri, bakiye, çift ödeme koruması.
// Bellek içi SQLite, el yazımı minimal şema; canlı DB'ye dokunmaz.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function t(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n :: $d\n"; } }
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function audit_log_event(string $a, string $m, ?int $r = null, ?array $o = null, ?array $n = null, ?int $u = null): void {}
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_servis_hesap.php';

t('A1) PDKS_SERVIS_HAKEDISTE varsayılan false', PDKS_SERVIS_HAKEDISTE === false);
t('A2) hakedis_satirlari servisi yazmaz', pdks_servis_hakedis_satirlari(['id' => 1, 'foreman_id' => 1, 'work_date' => '2026-11-10'], $db)['satirlar'] === []);

$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY, name TEXT, is_active INT DEFAULT 1)");
$db->exec("CREATE TABLE foreman_service_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INT, big_rate TEXT, small_rate TEXT, currency TEXT DEFAULT 'TRY', valid_from TEXT, valid_to TEXT, is_active INT DEFAULT 1)");
$db->exec("CREATE TABLE daily_session_services (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT, foreman_id INT, work_date TEXT, depo TEXT DEFAULT '', service_type TEXT, quantity INT, is_voided INT DEFAULT 0)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY, session_id INT, status TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY, entitlement_id INT, worker_type_code_snapshot TEXT)");
$db->exec("INSERT INTO foremen (id,name) VALUES (1,'Çavuş A'),(2,'Çavuş B')");
$db->exec("INSERT INTO foreman_service_rates (foreman_id,big_rate,small_rate,valid_from) VALUES (1,'500.00','250.50','2026-01-01')");
$db->exec("INSERT INTO daily_session_services (session_id,foreman_id,work_date,depo,service_type,quantity) VALUES
 (10,1,'2026-11-10','Depo A','BUYUK',3),(10,1,'2026-11-10','Depo A','KUCUK',1),(10,1,'2026-11-10','Depo A','BUYUK',1),
 (11,1,'2026-11-11','Depo A','BUYUK',2),(12,2,'2026-11-12','Depo A','BUYUK',1),(13,1,'2026-11-13','Depo B','KUCUK',4)");
$db->exec("INSERT INTO daily_session_services (session_id,foreman_id,work_date,depo,service_type,quantity,is_voided) VALUES (10,1,'2026-11-10','Depo A','KUCUK',9,1)");
// Oturum 11: v532 öncesi KESİN hakedişte servis satırı var
$db->exec("INSERT INTO foreman_daily_entitlements VALUES (1,11,'final')");
$db->exec("INSERT INTO foreman_daily_entitlement_lines VALUES (1,1,'SERVIS_BUYUK')");

$s = pdks_servis_hesap_satirlari('2026-11-01', '2026-11-30', 'Depo A', null, $db);
$by = []; foreach ($s as $r) $by[$r['session_id']] = $r;
t('B1) depo filtresi: Depo B oturumu yok, 3 oturum', count($s) === 3 && !isset($by[13]), (string)count($s));
t('B2) oturum 10: 4 büyük + 1 küçük, iptal sayılmaz', $by[10]['adet']['BUYUK'] === 4 && $by[10]['adet']['KUCUK'] === 1);
t('B3) tutar 4×500 + 250,50 = 2250,50', $by[10]['tutar_kurus'] === 225050, (string)$by[10]['tutar_kurus']);
t('B4) fiyatı olmayan çavuş: fiyatsız + tutar 0', $by[12]['fiyatsiz'] === ['Büyük'] && $by[12]['tutar_kurus'] === 0);
t('B5) kesin hakedişte servisli mesai işaretli', $by[11]['hakedis_eski'] === true && $by[10]['hakedis_eski'] === false);
$f = pdks_servis_hesap_satirlari('2026-11-01', '2026-11-30', 'Depo A', 2, $db);
t('B6) çavuş filtresi', count($f) === 1 && $f[0]['foreman_id'] === 2);

$oz = pdks_servis_hesap_ozet($s);
t('C1) özet: eski hakedişli bakiyeye girmez (yalnız 2250,50)', $oz['TRY']['tutar_kurus'] === 225050 && $oz['TRY']['eski_kurus'] === 100000, json_encode($oz));

t('D1) ödeme tablosu yokken hazır değil', !pdks_servis_odeme_hazir($db));
t('D2) tablo yokken ekle reddi', pdks_servis_odeme_ekle('2026-11-15', '100', 'TRY', 'BANK', '', '', 'Depo A', 1, $db)['ok'] === false);
$db->exec("CREATE TABLE servis_odemeleri (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_date TEXT, amount TEXT, currency TEXT, payment_method TEXT, reference_no TEXT, description TEXT, depo TEXT DEFAULT '', status TEXT DEFAULT 'valid', created_by_user_id INT, created_at TEXT, cancelled_at TEXT, cancelled_by_user_id INT, cancellation_reason TEXT)");
t('D3) hazır', pdks_servis_odeme_hazir($db));
t('D4) geçersiz tutar / yöntem / gelecek tarih reddi',
    !pdks_servis_odeme_ekle('2026-11-15', 'abc', 'TRY', 'BANK', '', '', 'Depo A', 1, $db)['ok']
    && !pdks_servis_odeme_ekle('2026-11-15', '10', 'TRY', 'XXX', '', '', 'Depo A', 1, $db)['ok']
    && !pdks_servis_odeme_ekle('2999-01-01', '10', 'TRY', 'BANK', '', '', 'Depo A', 1, $db)['ok']);
$r = pdks_servis_odeme_ekle('2026-11-15', '1000,50', 'TRY', 'BANK', 'H1', 'kısmi', 'Depo A', 1, $db);
t('D5) ödeme kaydedildi', $r['ok'] === true);
$b = pdks_servis_bakiye('Depo A', $db);
t('E1) bakiye = 2250,50 − 1000,50 = 1250,00', $b['TRY']['bakiye_kurus'] === 125000 && $b['TRY']['odeme_kurus'] === 100050, json_encode($b));
t('E2) iptal gerekçesiz reddi', !pdks_servis_odeme_iptal((int)$r['id'], '  ', 1, $db)['ok']);
t('E3) iptal', pdks_servis_odeme_iptal((int)$r['id'], 'yanlış', 1, $db)['ok'] === true);
t('E4) iptal edilen bakiyeyi etkilemez, satır silinmez',
    pdks_servis_bakiye('Depo A', $db)['TRY']['bakiye_kurus'] === 225050 && count(pdks_servis_odeme_listesi(null, null, 'Depo A', $db)) === 1);
t('E5) ikinci iptal reddi', !pdks_servis_odeme_iptal((int)$r['id'], 'tekrar', 1, $db)['ok']);

// Statik
$k = file_get_contents($root . '/config/pdks_servis_hesap.php');
t('F1) ödeme tablosunda DELETE yok', stripos($k, 'DELETE FROM servis_odemeleri') === false);
$p = file_get_contents($root . '/servis_hesabi.php');
t('F2) sayfa: csrf_check + payments kapısı + SQL yok', str_contains($p, 'csrf_check(') && str_contains($p, "require_pdks_cari('payments')") && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $p));
t('F3) rol matrisi rotası', str_contains(file_get_contents($root . '/config/role_pdks_matrix.php'), "'servis_hesabi.php'"));
t('F4) migrate kartı', str_contains(file_get_contents($root . '/migrate.php'), "ne') ?? '') === 'pdks_servis_odeme'") || str_contains(file_get_contents($root . '/migrate.php'), "'pdks_servis_odeme'"));
echo "\nSONUÇ: $pass geçti, $fail hata\n";
exit($fail ? 1 : 0);
