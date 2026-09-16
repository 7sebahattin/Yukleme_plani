<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$ROOT = dirname(__DIR__);

$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function db(): PDO
{
    global $PDO_TEST;
    return $PDO_TEST;
}

function can(string $p): bool
{
    return in_array(
        $p,
        ['attendance.management_reports', 'attendance.foreman_accounts'],
        true
    );
}

function is_admin(): bool
{
    return false;
}

require_once $ROOT . '/config/pdks_rapor.php';

$db = db();

$db->exec("
CREATE TABLE worker_types (
    id INTEGER PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(80) NOT NULL
)");

$db->exec("
CREATE TABLE daily_work_sessions (
    id INTEGER PRIMARY KEY,
    foreman_id INTEGER NOT NULL,
    foreman_name_snapshot VARCHAR(150) NOT NULL,
    foreman_code_snapshot VARCHAR(20) NOT NULL,
    work_date DATE NOT NULL,
    depo VARCHAR(150) NOT NULL,
    status VARCHAR(20) NOT NULL,
    notes TEXT NULL
)");

$db->exec("
CREATE TABLE worker_cards (
    id INTEGER PRIMARY KEY,
    card_no VARCHAR(30) NOT NULL,
    canonical_uid VARCHAR(32) NOT NULL,
    uid_decimal VARCHAR(25) NULL
)");

$db->exec("
CREATE TABLE daily_worker_work_periods (
    id INTEGER PRIMARY KEY,
    session_id INTEGER NOT NULL,
    worker_card_id INTEGER NOT NULL,
    worker_type_id_snapshot INTEGER NOT NULL,
    worker_type_name_snapshot VARCHAR(80) NOT NULL,
    entry_time DATETIME NOT NULL,
    exit_time DATETIME NULL,
    status VARCHAR(30) NOT NULL,
    source VARCHAR(30) NOT NULL
)");

$db->exec("
CREATE TABLE foreman_daily_entitlements (
    id INTEGER PRIMARY KEY,
    session_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    currency VARCHAR(10) NOT NULL
)");

$db->exec("
INSERT INTO worker_types (id,code,name) VALUES
(1,'KADIN','Kadın'),
(2,'ERKEK','Erkek')
");

$db->exec("
INSERT INTO daily_work_sessions
(id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,notes)
VALUES
(101,1,'Ayşe Çavuş','C001','2026-09-15','Depo A','closed',NULL),
(102,1,'Ayşe Çavuş','C001','2026-09-16','Depo A','closed',NULL),
(103,2,'Mehmet Çavuş','C002','2026-09-15','Depo A','closed',NULL),
(104,1,'Ayşe Çavuş','C001','2026-08-31','Depo A','closed',NULL)
");

$db->exec("
INSERT INTO worker_cards
(id,card_no,canonical_uid,uid_decimal)
VALUES
(1,'AUTO-A1','A1A1A1A1','880000001'),
(2,'AUTO-A2','A2A2A2A2','880000002'),
(3,'AUTO-A3','A3A3A3A3','880000003'),
(4,'AUTO-A4','A4A4A4A4','880000004'),
(5,'AUTO-A5','A5A5A5A5',NULL)
");

$db->exec("
INSERT INTO daily_worker_work_periods
(id,session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status,source)
VALUES
(1,101,1,1,'Kadın','2026-09-15 08:00:00','2026-09-15 17:00:00','closed','scan'),
(2,101,2,1,'Kadın','2026-09-15 08:05:00',NULL,'open','scan'),
(3,101,3,2,'Erkek','2026-09-15 08:10:00','2026-09-15 17:30:00','closed','scan'),
(4,102,4,2,'Erkek','2026-09-16 08:02:00','2026-09-16 16:50:00','closed','scan'),
(5,103,5,1,'Kadın','2026-09-15 08:03:00','2026-09-15 17:01:00','closed','scan')
");

$db->exec("
INSERT INTO foreman_daily_entitlements
(id,session_id,status,total_amount,currency)
VALUES
(1,101,'final',4250.00,'TRY'),
(2,102,'draft',1500.00,'TRY'),
(3,103,'final',1200.00,'TRY')
");

$fail = 0;
$pass = 0;

function ok8d(string $name, bool $condition, string $detail = ''): void
{
    global $fail, $pass;

    if ($condition) {
        $pass++;
        echo "OK  - {$name}\n";
    } else {
        $fail++;
        echo "HATA- {$name}";
        if ($detail !== '') echo " => {$detail}";
        echo "\n";
    }
}

echo "=== FAZ 8D / STEP 3 — ÇAVUŞ TOPLU DÖKÜM ===\n";

$ayse = pdks_rapor_cavus_toplu_dokum(
    '2026-09',
    'Depo A',
    1,
    true,
    $db
);

ok8d(
    'Ayşe için Eylül ayında iki günlük satır geldi',
    count($ayse) === 2,
    json_encode($ayse, JSON_UNESCAPED_UNICODE)
);

$gun15 = null;
$gun16 = null;

foreach ($ayse as $r) {
    if ($r['tarih'] === '2026-09-15') $gun15 = $r;
    if ($r['tarih'] === '2026-09-16') $gun16 = $r;
}

ok8d(
    '15 Eylül Kadın=2 Erkek=1 Toplam=3',
    $gun15 !== null
        && $gun15['kadin'] === 2
        && $gun15['erkek'] === 1
        && $gun15['toplam_isci'] === 3,
    json_encode($gun15, JSON_UNESCAPED_UNICODE)
);

ok8d(
    '15 Eylül ilk giriş 08:00, son çıkış 17:30',
    $gun15 !== null
        && $gun15['ilk_giris'] === '2026-09-15 08:00:00'
        && $gun15['son_cikis'] === '2026-09-15 17:30:00',
    json_encode($gun15, JSON_UNESCAPED_UNICODE)
);

ok8d(
    '15 Eylül eksik çıkış sayısı 1',
    $gun15 !== null && $gun15['eksik_cikis'] === 1,
    json_encode($gun15, JSON_UNESCAPED_UNICODE)
);

ok8d(
    'Hakediş mevcut snapshot üzerinden geldi',
    $gun15 !== null
        && $gun15['hakedis'] !== null
        && $gun15['hakedis']['status'] === 'final'
        && $gun15['hakedis']['total_amount'] === '4250',
    json_encode($gun15, JSON_UNESCAPED_UNICODE)
        . ' / SQLite DECIMAL='
        . json_encode($gun15['hakedis']['total_amount'] ?? null)
);

ok8d(
    'Ay filtresi Ağustos oturumunu dışarıda bıraktı',
    !array_filter(
        $ayse,
        fn($r) => $r['tarih'] === '2026-08-31'
    )
);

$tumuFinanssiz = pdks_rapor_cavus_toplu_dokum(
    '2026-09',
    'Depo A',
    null,
    false,
    $db
);

ok8d(
    'Tüm çavuşlar filtresinde üç günlük/çavuş oturumu geldi',
    count($tumuFinanssiz) === 3
);

ok8d(
    'Finansal yetki kullanılmadığında hakediş verisi döndürülmedi',
    count(array_filter(
        $tumuFinanssiz,
        fn($r) => $r['hakedis'] !== null
    )) === 0
);

$detay = pdks_rapor_cavus_kart_dokumu(
    101,
    'Depo A',
    $db
);

ok8d(
    'Detay doğru çavuş/oturumu döndürdü',
    $detay['session'] !== null
        && $detay['session']['cavus_adi'] === 'Ayşe Çavuş'
        && count($detay['cards']) === 3,
    json_encode($detay, JSON_UNESCAPED_UNICODE)
);

ok8d(
    'Kart dökümünde kart no ve UID birlikte mevcut',
    $detay['cards'][0]['card_no'] === 'AUTO-A1'
        && $detay['cards'][0]['okunan_uid'] === '880000001'
);

$eksikler = array_values(array_filter(
    $detay['cards'],
    fn($c) => $c['eksik_cikis']
));

ok8d(
    'Detay açık dönemi Eksik Çıkış olarak işaretledi',
    count($eksikler) === 1
        && $eksikler[0]['card_no'] === 'AUTO-A2'
);

$wrongDepot = pdks_rapor_cavus_kart_dokumu(
    101,
    'Depo B',
    $db
);

ok8d(
    'Başka depo oturumu detaydan okunamıyor',
    $wrongDepot['session'] === null
        && $wrongDepot['cards'] === []
);

$raporlarSrc = file_get_contents($ROOT . '/raporlar.php');
$listeSrc = file_get_contents($ROOT . '/cavus_toplu_dokum.php');
$detaySrc = file_get_contents($ROOT . '/cavus_toplu_dokum_detay.php');

ok8d(
    'Raporlar ana sayfasında Çavuş Toplu Döküm bağlantısı var',
    str_contains($raporlarSrc, 'cavus_toplu_dokum.php')
);

ok8d(
    'Aylık listede istenen kolonlar mevcut',
    str_contains($listeSrc, 'Toplam İşçi')
        && str_contains($listeSrc, 'Hakediş')
        && str_contains($listeSrc, 'İlk Giriş')
        && str_contains($listeSrc, 'Son Çıkış')
        && str_contains($listeSrc, 'Eksik Çıkış')
);

ok8d(
    'Aylık satır kart detay sayfasına tıklanabilir',
    str_contains($listeSrc, 'cavus_toplu_dokum_detay.php')
        && str_contains($listeSrc, 'ctd-click-row')
);

ok8d(
    'Kart detayında Kart No / UID / giriş / çıkış kolonları var',
    str_contains($detaySrc, 'Kart No')
        && str_contains($detaySrc, 'Okunan UID')
        && str_contains($detaySrc, 'Giriş Tarih / Saat')
        && str_contains($detaySrc, 'Çıkış Tarih / Saat')
);

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);