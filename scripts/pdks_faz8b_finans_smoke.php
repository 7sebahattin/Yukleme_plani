<?php
// Faz 8B finans smoke: Tam/Yarım + saatlik/sabit FM + muhasebe kararları.
declare(strict_types=1);

require_once __DIR__ . '/../config/pdks_faz8b.php';

$gecen = 0; $hata = 0;
function ok8bf(string $ad, bool $kosul, string $detay = ''): void {
    global $gecen, $hata;
    if ($kosul) { $gecen++; echo "OK  - {$ad}\n"; }
    else { $hata++; echo "HATA- {$ad}" . ($detay !== '' ? " :: {$detay}" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Faz 4/8A'nın bu test için gerekli en küçük şeması. Faz 8B kolonları
// BİLEREK yok; aşağıda gerçek pdks_faz8b_migrate() ekleyecek.
$db->exec("CREATE TABLE foremen (
    id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(20) NOT NULL, name VARCHAR(150) NOT NULL
)");
// Faz 9B: is_active EKLENDİ — eksikken pdks_gunluk_desteklenen_tip_coz()
// (Faz 9B'nin merkezi işçi tipi politikası, artık pdks_faz8b_oran_ekle()
// içinde çağrılıyor) "no such column" ile fatal veriyordu; production
// şemasında bu kolon her zaman VAR (diğer tüm test fikstürleriyle tutarlı).
$db->exec("CREATE TABLE worker_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(30) NOT NULL, name VARCHAR(80) NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1
)");
$db->exec("CREATE TABLE worker_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT, card_no VARCHAR(30) NOT NULL
)");
$db->exec("CREATE TABLE daily_work_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    foreman_id INT NOT NULL,
    foreman_name_snapshot VARCHAR(150) NOT NULL DEFAULT '',
    foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '',
    work_date DATE NOT NULL,
    depo VARCHAR(150) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'closed'
)");
$db->exec("CREATE TABLE foreman_worker_rates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    foreman_id INT NOT NULL,
    worker_type_id INT NOT NULL,
    daily_rate DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'TRY',
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    is_active INT NOT NULL DEFAULT 1,
    created_by_user_id INT NULL
)");
$db->exec("CREATE TABLE daily_worker_work_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INT NOT NULL,
    worker_card_id INT NOT NULL,
    worker_type_id_snapshot INT NULL,
    worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '',
    entry_time DATETIME NOT NULL,
    exit_time DATETIME NULL,
    declared_attendance_class VARCHAR(10) NOT NULL DEFAULT 'tam',
    approved_attendance_class VARCHAR(10) NULL,
    -- Faz 9A / B1: is_voided EKSİKSE pdks_gunluk_faz8j_etkin_kosul() '1=1'e
    -- düşer ve bu dosyanın finansal sorguları void'i HİÇ görmez (audit
    -- bulgusu: void semantiği testte sessizce devre dışı kalıyordu).
    is_voided INT NOT NULL DEFAULT 0,
    voided_at DATETIME NULL,
    voided_by_user_id INT NULL,
    void_reason VARCHAR(500) NULL
)");
$db->exec("CREATE TABLE foreman_daily_entitlements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INT NOT NULL UNIQUE,
    foreman_id INT NOT NULL,
    foreman_name_snapshot VARCHAR(150) NOT NULL DEFAULT '',
    foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '',
    work_date DATE NOT NULL,
    depo VARCHAR(150) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    currency VARCHAR(10) NOT NULL DEFAULT 'TRY',
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    calculated_at DATETIME NULL,
    calculated_by_user_id INT NULL,
    finalized_at DATETIME NULL,
    finalized_by_user_id INT NULL,
    missing_exit_ack INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    updated_at DATETIME NULL
)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entitlement_id INT NOT NULL,
    worker_type_id INT NULL,
    worker_type_code_snapshot VARCHAR(30) NOT NULL DEFAULT '',
    worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '',
    worker_count INT NOT NULL,
    unit_rate DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(14,2) NOT NULL
)");

$mig = pdks_faz8b_migrate($db);
ok8bf('Faz 8B migrasyonu hatasız', count(array_filter($mig, fn($r) => $r['durum'] === 'hata')) === 0, json_encode($mig, JSON_UNESCAPED_UNICODE));
ok8bf('Faz 8B şeması hazır', pdks_faz8b_sema_hazir($db));

$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C001','Ayşe Çavuş')");
$db->exec("INSERT INTO worker_types (id,code,name) VALUES (1,'KADIN','Kadın'),(2,'ERKEK','Erkek')");
$db->exec("INSERT INTO daily_work_sessions
    (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status)
    VALUES (1,1,'Ayşe Çavuş','C001','2026-09-16','Depo A','closed')");
for ($i=1; $i<=8; $i++) {
    $db->prepare("INSERT INTO worker_cards (id,card_no) VALUES (?,?)")->execute([$i, sprintf('K%03d',$i)]);
}

$rKad = pdks_faz8b_oran_ekle(1, 1, '1500', '900', 'hourly', '200', '2026-09-01', 'TRY', 1, $db);
$rErk = pdks_faz8b_oran_ekle(1, 2, '1300', '800', 'fixed', '350', '2026-09-01', 'TRY', 1, $db);
ok8bf('Kadın Tam/Yarım/Saatlik FM fiyatı eklendi', $rKad['ok'] === true, json_encode($rKad, JSON_UNESCAPED_UNICODE));
ok8bf('Erkek Tam/Yarım/Sabit FM fiyatı eklendi', $rErk['ok'] === true, json_encode($rErk, JSON_UNESCAPED_UNICODE));

function periodEkle(PDO $db, int $cardId, int $tipId, string $tip, string $giris, string $cikis): int {
    $st = $db->prepare("INSERT INTO daily_worker_work_periods
        (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,
         entry_time,exit_time,declared_attendance_class,approved_attendance_class)
        VALUES (1,?,?,?,?,?,'tam',NULL)");
    $st->execute([$cardId,$tipId,$tip,"2026-09-16 {$giris}:00","2026-09-16 {$cikis}:00"]);
    return (int)$db->lastInsertId();
}

// 1) Normal gün otomatik Tam.
$p1 = periodEkle($db, 1, 1, 'Kadın', '08:00', '17:00');
$h1 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
ok8bf('08:00-17:00 muhasebe kararı olmadan hesaplanır', $h1['ok'] === true, json_encode($h1, JSON_UNESCAPED_UNICODE));
ok8bf('normal Tam satırı 1500 TL', $h1['total_amount'] === '1500.00');

// 2) Kısa gün muhasebe kararı olmadan fiyatlanamaz; Yarım onaylanınca 900 TL.
$p2 = periodEkle($db, 2, 1, 'Kadın', '08:00', '12:00');
$h2Bekle = pdks_faz8b_hakedis_hesapla(1, 1, $db);
ok8bf('08:00-12:00 muhasebe kararı olmadan reddedilir', $h2Bekle['ok'] === false && ($h2Bekle['kod'] ?? '') === 'faz8b_degerlendirme_gerekli');
$d2 = pdks_faz8b_degerlendirme_kaydet($p2, 'yarim', null, 1, $db);
ok8bf('kısa gün Yarım olarak onaylandı', $d2['ok'] === true, json_encode($d2, JSON_UNESCAPED_UNICODE));
ok8bf('değerlendirme mevcut taslağı yeniden hesaplama gerekli yaptı', (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=1")->fetchColumn() === 1);
$h2 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
ok8bf('Tam 1500 + Yarım 900 = 2400 TL', $h2['ok'] === true && $h2['total_amount'] === '2400.00', json_encode($h2, JSON_UNESCAPED_UNICODE));
ok8bf('yeniden hesaplama sonrası needs_recalculation sıfırlandı', (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=1")->fetchColumn() === 0);

// 3) Saatlik FM: 18:15 => 1 saat; onaydan önce hesap bloklanır, sonra +200.
$p3 = periodEkle($db, 3, 1, 'Kadın', '08:00', '18:15');
$h3Bekle = pdks_faz8b_hakedis_hesapla(1, 1, $db);
ok8bf('18:15 FM onayı olmadan hesaplanmaz', $h3Bekle['ok'] === false && ($h3Bekle['kod'] ?? '') === 'faz8b_degerlendirme_gerekli');
$d3 = pdks_faz8b_degerlendirme_kaydet($p3, null, 'onayla', 1, $db);
ok8bf('18:15 için 1 saat FM onaylandı', $d3['ok'] === true);
$h3 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
$line3 = array_values(array_filter($h3['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === $p3))[0] ?? null;
ok8bf('saatlik FM satırı 1500 + 1×200 = 1700', $h3['ok'] === true && ($line3['line_total'] ?? '') === '1700.00' && (int)($line3['overtime_hours'] ?? 0) === 1, json_encode($line3, JSON_UNESCAPED_UNICODE));

// 4) 18:16 => tolerans kuralına göre 2 saat FM.
$p4 = periodEkle($db, 4, 1, 'Kadın', '08:00', '18:16');
pdks_faz8b_degerlendirme_kaydet($p4, null, 'onayla', 1, $db);
$h4 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
$line4 = array_values(array_filter($h4['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === $p4))[0] ?? null;
ok8bf('18:16 = 2 saat FM ve satır 1900 TL', $h4['ok'] === true && (int)($line4['overtime_hours'] ?? 0) === 2 && ($line4['line_total'] ?? '') === '1900.00', json_encode($line4, JSON_UNESCAPED_UNICODE));

// 5) Sabit FM: süre 3 saatlik dilime taşsa da ücret BİR KEZ 350 TL.
$p5 = periodEkle($db, 5, 2, 'Erkek', '08:00', '19:30');
pdks_faz8b_degerlendirme_kaydet($p5, null, 'onayla', 1, $db);
$h5 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
$line5 = array_values(array_filter($h5['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === $p5))[0] ?? null;
ok8bf('sabit FM aday saati 3 olarak saklanır', (int)($line5['overtime_hours'] ?? 0) === 3, json_encode($line5, JSON_UNESCAPED_UNICODE));
ok8bf('sabit FM 1300 + tek sefer 350 = 1650 TL', $h5['ok'] === true && ($line5['overtime_mode_snapshot'] ?? '') === 'fixed' && ($line5['overtime_total'] ?? '') === '350.00' && ($line5['line_total'] ?? '') === '1650.00', json_encode($line5, JSON_UNESCAPED_UNICODE));

// 6) FM reddedilirse yalnız Tam Mesai ücreti uygulanır.
$p6 = periodEkle($db, 6, 1, 'Kadın', '08:00', '17:30');
pdks_faz8b_degerlendirme_kaydet($p6, null, 'reddet', 1, $db);
$h6 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
$line6 = array_values(array_filter($h6['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === $p6))[0] ?? null;
ok8bf('FM reddedilince satır yalnız Tam 1500 TL', $h6['ok'] === true && (int)($line6['overtime_hours'] ?? -1) === 0 && ($line6['overtime_total'] ?? '') === '0.00' && ($line6['line_total'] ?? '') === '1500.00', json_encode($line6, JSON_UNESCAPED_UNICODE));

// 7) Kısa gün kararı sonradan Yarım→Tam değişirse taslak bayatlar ve yeni toplam kullanılır.
$d2b = pdks_faz8b_degerlendirme_kaydet($p2, 'tam', null, 1, $db);
ok8bf('muhasebe kısa günü Yarım→Tam değiştirebilir (final değilken)', $d2b['ok'] === true);
ok8bf('karar değişince taslak tekrar bayat işaretlenir', (int)$db->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id=1")->fetchColumn() === 1);
$h7 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
ok8bf('yeniden hesaplama yeni Tam kararını finansal toplama yansıtır', $h7['ok'] === true && $h7['total_amount'] === '9750.00', json_encode($h7, JSON_UNESCAPED_UNICODE));

// Toplam kontrolü:
// p1 1500 + p2 1500 + p3 1700 + p4 1900 + p5 1650 + p6 1500 = 9750.
$beklenen = '9750.00';
ok8bf('nihai toplam aritmetiği 9750 TL', $h7['ok'] === true && $h7['total_amount'] === $beklenen, json_encode($h7, JSON_UNESCAPED_UNICODE));

// 8) Faz 9A / B1: İPTAL EDİLMİŞ bir dönem — BİLEREK 12:00-18:16 (6s16dk,
// KISA — Tam/Yarım kararı GEREKTİRİR — VE planlı bitişten (17:00) 18:16
// FM'nin 2 saatlik dilimine girer — İKİ kuyruğa BİRDEN düşecek şekilde
// kurgulanır): void hariç tutma bozuksa hem Tam/Yarım kuyruğuna HEM FM
// kuyruğuna düşer, HİÇ değerlendirilmediği için hesaplama
// 'faz8b_degerlendirme_gerekli' ile BLOKE OLUR — dolayısıyla aşağıdaki
// "yeniden hesaplama hâlâ başarılı" tek başına GÜÇLÜ bir kanıttır.
// worker_card_id=8 kullanılmamış tek karttır (1-8 arası tanımlı, 1-6
// önceki periyotlarda kullanıldı).
$p8 = periodEkle($db, 8, 1, 'Kadın', '12:00', '18:16');
$db->prepare("UPDATE daily_worker_work_periods SET is_voided=1, voided_at='2026-09-16 20:00:00', voided_by_user_id=9, void_reason='test iptal' WHERE id=?")
    ->execute([$p8]);
$ozetVoid = pdks_faz8b_oturum_ozeti(1, $db);
ok8bf('İPTAL edilmiş dönem Tam/Yarım (bekleyen_sinif) kuyruğuna GİRMEZ', $ozetVoid['bekleyen_sinif'] === 0, json_encode($ozetVoid, JSON_UNESCAPED_UNICODE));
ok8bf('İPTAL edilmiş dönem Fazla Mesai (bekleyen_fazla_mesai) kuyruğuna GİRMEZ', $ozetVoid['bekleyen_fazla_mesai'] === 0, json_encode($ozetVoid, JSON_UNESCAPED_UNICODE));
$donemlerVoid = pdks_faz8b_oturum_donemleri(1, $db);
ok8bf('İPTAL edilmiş dönem Faz 8B değerlendirme girdisine (oturum_donemleri) HİÇ girmez', !in_array($p8, array_column($donemlerVoid, 'id'), true));
$h8 = pdks_faz8b_hakedis_hesapla(1, 1, $db);
ok8bf('İPTAL edilmiş, HİÇ değerlendirilmemiş dönem hesaplamayı BLOKE ETMEZ (değerlendirme gerekmiyor)', $h8['ok'] === true, json_encode($h8, JSON_UNESCAPED_UNICODE));
$line8 = array_values(array_filter($h8['lines'] ?? [], fn($x) => (int)$x['work_period_id'] === $p8))[0] ?? null;
ok8bf('İPTAL edilmiş dönem hakediş SATIRLARINA (entitlement lines) girmez', $line8 === null, json_encode($h8['lines'] ?? [], JSON_UNESCAPED_UNICODE));
ok8bf('İPTAL edilmiş dönem TOPLAMA (entitlement total) hiç KATKI yapmaz — 9750 TL AYNI kalır', $h8['ok'] === true && $h8['total_amount'] === $beklenen, json_encode($h8, JSON_UNESCAPED_UNICODE));
// ⚠ SQLite NUMERIC affinity ondalık sıfırları kırpar (CLAUDE.md'nin
// bilinen farklılığı) — DB'den okunan ham DECIMAL '9750.00' DEĞİL '9750'
// dönebilir, bu yüzden burada sayısal karşılaştırma yapılır.
ok8bf('Yeniden hesaplanan hakedişin veritabanındaki toplamı da 9750 TL', (float)$db->query("SELECT total_amount FROM foreman_daily_entitlements WHERE session_id=1")->fetchColumn() === (float)$beklenen);

echo "\nSONUÇ: {$gecen} geçti, {$hata} hata\n";
exit($hata === 0 ? 0 : 1);
