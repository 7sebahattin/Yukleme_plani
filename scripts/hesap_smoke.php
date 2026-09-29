<?php
// =========================================================
// scripts/hesap_smoke.php — Hesap durum makinesi + bakiye testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/depo fonksiyonlarıyla çalışır, bu yüzden her ortamda
// güvenle koşturulabilir.
//
//   php scripts/hesap_smoke.php     → çıkış kodu 0 = tüm testler geçti
//
// Kapsam: kişisel bakiye (yalnız kendi; muhasebe DE yalnız kendi, yönetici
// hesap_balance_tum), sahipsiz kaydın hiçbir bakiyeye girmemesi, para birimi
// ayrımı, durum geçiş yetkileri (görünürlük kapısı, kendi kaydını onaylama
// serbest), içerik kilidi, gerekçe zorunluluğu, legacy bayrak senkronu,
// geri dolum (backfill) idempotanlığı, tutar/tarih/para birimi doğrulaması.
// =========================================================
declare(strict_types=1);

// ── Stub'lar: hesap_calc.php'nin dış bağımlılıkları ──
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$CUR_USER = ['id' => 1, 'username' => 'personel'];
$PERMS    = ['hesap.read', 'hesap.write'];
function current_user(): ?array { global $CUR_USER; return $CUR_USER; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { return can('users.admin'); }
function depo_sql_in(string $col): array { return ['', []]; }
function depot_visible_to_user(?string $d): bool { return true; }
function audit_log_event(...$a): void {}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/../config/hesap_calc.php';
require_once __DIR__ . '/../hesap_config.php';

db()->exec("CREATE TABLE account_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, created_by INT,
  transaction_date TEXT, type TEXT, amount REAL, currency TEXT DEFAULT 'TRY',
  status TEXT DEFAULT 'submitted', is_given_to_accountant INT DEFAULT 0,
  review_note TEXT DEFAULT '', submitted_at TEXT, reviewed_by INT, reviewed_at TEXT,
  paid_at TEXT, depo TEXT DEFAULT '')");

db()->exec("CREATE TABLE users (id INT, username TEXT, display_name TEXT, is_active INT DEFAULT 1)");
db()->exec("INSERT INTO users (id,username,display_name) VALUES (1,'personel','Personel Bir'),(2,'iki','Personel İki')");

$ins = db()->prepare("INSERT INTO account_transactions
  (user_id,transaction_date,type,amount,currency,status) VALUES (?,?,?,?,?,?)");
//         user, tarih,        tür,     tutar, kur,  durum
$ins->execute([1,'2026-07-01','gelir', 1000, 'TRY','approved']);   // şirket para verdi
$ins->execute([1,'2026-07-02','gider', 1200, 'TRY','approved']);   // personel harcadı
$ins->execute([1,'2026-07-03','gider',  500, 'TRY','submitted']);  // onay bekliyor
$ins->execute([1,'2026-07-04','gider',  300, 'TRY','rejected']);   // reddedildi
$ins->execute([1,'2026-07-05','gider',  100, 'USD','approved']);   // farklı kur
$ins->execute([2,'2026-07-06','gider', 9999, 'TRY','approved']);   // BAŞKA personel
$ins->execute([null,'2026-07-07','gider', 5555, 'TRY','approved']); // SAHİPSİZ (eski kayıt)

$fail = 0;
function check(string $ad, $got, $exp) {
    global $fail;
    $ok = is_float($exp) ? abs($got - $exp) < 0.001 : $got === $exp;
    if (!$ok) $fail++;
    printf("%-52s %-12s (beklenen %-10s) %s\n", $ad, var_export($got, true), var_export($exp, true), $ok ? 'OK' : '*** FAIL');
}

echo "── Bakiye: normal personel (yalnız kendi kayıtları) ──\n";
$b = hesap_balance();
check('TRY net (1000 gelir - 1200 gider)',        $b['TRY']['net'], -200.0);
check('TRY bekleyen (500 submitted, hariç)',      $b['TRY']['bekleyen'], -500.0);
check('reddedilen 300 bakiyeye girmedi',          $b['TRY']['gider'], 1200.0);
check('USD ayrı tutuldu, TRY ile toplanmadı',     $b['USD']['net'], -100.0);
check('başka personelin 9999 kaydı görünmedi',    isset($b['TRY']) && $b['TRY']['gider'] === 1200.0, true);
check('sahipsiz 5555 kişisel bakiyeye girmedi',   $b['TRY']['gider'], 1200.0);

$lbl = hesap_balance_label($b['TRY']['net']);
check('net<0 → şirket personele borçlu',          $lbl['yon'], 'alacak');
check('borç tutarı pozitif gösteriliyor',         $lbl['tutar'], 200.0);
check('net>0 → personel şirkete borçlu',          hesap_balance_label(150.0)['yon'], 'borc');
check('net=0 → denk',                             hesap_balance_label(0.0)['yon'], 'denk');

echo "\n── Kapsam: muhasebe YALNIZ kendini, yönetici tümünü görür ──\n";
$PERMS = ['hesap.read','hesap.write','hesap.approve','hesap.pay'];
check('muhasebe hesap_sees_all DEĞİL',            hesap_sees_all(), false);
$b2 = hesap_balance();
check('muhasebe hesap_balance() yalnız kendi (1200)', $b2['TRY']['gider'], 1200.0);
check('muhasebe başkasının bakiyesi → 0 (fail-closed)', hesap_balance(2)['TRY']['net'], 0.0);
check('muhasebe hesap_balance_tum → 0',           hesap_balance_tum()['TRY']['gider'], 0.0);
check('muhasebe hesap_balance_by_user → []',      hesap_balance_by_user(), []);
$PERMS[] = 'hesap.admin';
check('hesap.admin → hesap_sees_all',             hesap_sees_all(), true);
check('yönetici hesap_balance() yine KENDİ (1200)', hesap_balance()['TRY']['gider'], 1200.0);
check('yönetici başkasının bakiyesi (9999)',      hesap_balance(2)['TRY']['gider'], 9999.0);
check('hesap_balance_tum sahipsizi dışlar (11199)', hesap_balance_tum()['TRY']['gider'], 11199.0);
$bu = hesap_balance_by_user();
check('by_user: yalnız sahipli kişiler (2 satır)', count($bu), 2);
check('by_user: "Atanmamış" satırı yok',           in_array(null, array_column($bu, 'user_id'), true), false);
$bu2 = hesap_balance_by_user(null, null, null);
check('by_user(null kur): USD ayrı satır',         count(array_filter($bu2, fn($r) => $r['currency'] === 'USD')), 1);
check('kapsam_coz: yönetici tum',                  hesap_kapsam_coz('tum')['tip'], 'tum');
check('kapsam_coz: yönetici kişi 2',               hesap_kapsam_coz('2')['tip'], 'kisi');
check('kapsam_coz: olmayan kişi → kendi',          hesap_kapsam_coz('999')['tip'], 'kendi');
check('kapsam_coz: kendi id → kendi',              hesap_kapsam_coz('1')['tip'], 'kendi');
$PERMS = ['hesap.read','hesap.write','hesap.approve','hesap.pay'];
check('kapsam_coz: yönetici değil → param yok sayılır', hesap_kapsam_coz('tum')['tip'], 'kendi');
check('kapsam_sql: tum kapsamı yönetici değilse kendi', hesap_kapsam_sql(['tip'=>'tum','uid'=>null])[0], 'user_id = ?');
check('owner_sql: sahipsiz yok',                   str_contains(hesap_owner_sql()[0], 'IS NULL'), false);

echo "\n── Durum makinesi ──\n";
$PERMS = ['hesap.read','hesap.write'];   // düz personel
$row_sub = ['id'=>3,'user_id'=>1,'status'=>'submitted'];
check('personel kendi kaydını geri çekebilir',    hesap_can_transition($row_sub,'draft'), true);
check('personel kendi kaydını ONAYLAYAMAZ',       hesap_can_transition($row_sub,'approved'), false);
check('tanımsız geçiş (submitted→paid) reddedilir', hesap_can_transition($row_sub,'paid'), false);
check('başkasının kaydını geri çekemez',          hesap_can_transition(['id'=>9,'user_id'=>2,'status'=>'submitted'],'draft'), false);
check('sahipsiz kayıtta hesap.write YETMEZ',      hesap_can_transition(['id'=>9,'user_id'=>null,'status'=>'submitted'],'draft'), false);
check('sahipsiz kayıt sahibi yok (is_owner)',     hesap_is_owner(['user_id'=>null]), false);
check('sahipsiz kayıt görünmez',                  hesap_row_visible(['user_id'=>null]), false);

$PERMS = ['hesap.read','hesap.write','hesap.approve','hesap.pay'];
check('muhasebe KENDİ kaydını onaylayabilir (K-3)', hesap_can_transition($row_sub,'approved'), true);
check('muhasebe kendi kaydını reddedebilir',      hesap_can_transition($row_sub,'rejected'), true);
check('muhasebe BAŞKASININ kaydını onaylayamaz',  hesap_can_transition(['id'=>6,'user_id'=>2,'status'=>'submitted'],'approved'), false);
check('muhasebe başkasının kaydını göremez',      hesap_row_visible(['user_id'=>2]), false);
check('ödenmiş kayıt muhasebeye kilitli',         hesap_is_locked(['status'=>'paid']), true);
$PERMS[] = 'hesap.admin';
check('admin kilidi açabilir',                    hesap_is_locked(['status'=>'paid']), false);
check('yönetici başkasınınkini onaylayabilir',    hesap_can_transition(['id'=>6,'user_id'=>2,'status'=>'submitted'],'approved'), true);
check('yönetici sahipsiz kaydı görür',            hesap_row_visible(['user_id'=>null]), true);

echo "\n── İçerik kilidi (K4/Y4) ──\n";
foreach (['approved','pending_payment','paid'] as $st) {
    $PERMS = ['hesap.read','hesap.write','hesap.delete'];
    check("sahip: $st kilitli",                   hesap_icerik_kilitli(['user_id'=>1,'status'=>$st]), true);
    $PERMS = ['hesap.read','hesap.write','hesap.admin'];
    check("yönetici: $st açık",                   hesap_icerik_kilitli(['user_id'=>1,'status'=>$st]), false);
}
$PERMS = ['hesap.read','hesap.write'];
foreach (['draft','submitted','rejected'] as $st) {
    check("sahip: $st açık",                      hesap_icerik_kilitli(['user_id'=>1,'status'=>$st]), false);
}

echo "\n── Geçiş uygulaması (gerçek yazma) ──\n";
$PERMS = ['hesap.read','hesap.write','hesap.approve','hesap.pay'];
$r = hesap_transition(3, 'rejected', '');
check('gerekçesiz red engellendi',                $r['ok'], false);
$r = hesap_transition(6, 'approved', '');
check('muhasebe başkasının kaydı → "bulunamadı"', $r['msg'], 'Kayıt bulunamadı.');
check('başkasının kaydı değişmedi',               db()->query("SELECT status FROM account_transactions WHERE id=6")->fetchColumn(), 'approved');
$r = hesap_transition(7, 'rejected', 'x');
check('muhasebe sahipsiz kaydı değiştiremez',     $r['ok'], false);
$r = hesap_transition(3, 'approved', '');
check('onay uygulandı (kendi kaydı, K-3)',        $r['ok'], true);
$after = db()->query("SELECT status,is_given_to_accountant FROM account_transactions WHERE id=3")->fetch();
check('durum yazıldı',                            $after['status'], 'approved');
check('legacy is_given_to_accountant senkron',    (int)$after['is_given_to_accountant'], 1);
$b3 = hesap_balance(1);   // personel 1 = oturumdaki kullanıcı
check('onaydan sonra 500 bakiyeye girdi (personel 1)', $b3['TRY']['net'], -700.0);
check('muhasebe hesap_balance() yine yalnız kendi (-700)', hesap_balance()['TRY']['net'], -700.0);
$PERMS[] = 'hesap.admin';
check('yönetici tüm sahipli personel (-10699, sahipsiz hariç)', hesap_balance_tum()['TRY']['net'], -10699.0);
$PERMS = ['hesap.read','hesap.write','hesap.approve','hesap.pay'];
$r = hesap_transition(3, 'paid', '');
check('muhasebe kendi kaydını ödendi yapabilir (K-3)', $r['ok'], true);
check('bekleyen sıfırlandı',                      $b3['TRY']['bekleyen'], 0.0);
$r = hesap_transition(3, 'gecersiz_durum', '');
check('geçersiz durum kodu reddedildi',           $r['ok'], false);

echo "\n── Geri dolum (migrate) mantığı ──\n";
db()->exec("UPDATE account_transactions SET status='submitted', is_given_to_accountant=1 WHERE id=1");
db()->exec("UPDATE account_transactions SET status='approved' WHERE status='submitted' AND is_given_to_accountant=1");
$bf = db()->query("SELECT status FROM account_transactions WHERE id=1")->fetch();
check('eski muhasebeli kayıt approved oldu',      $bf['status'], 'approved');
$n = db()->exec("UPDATE account_transactions SET status='approved' WHERE status='submitted' AND is_given_to_accountant=1");
check('geri dolum idempotent (2. çalıştırma 0)',  (int)$n, 0);

echo "\n── Tutar ayrıştırma (B1) ──\n";
// Eski kod str_replace(['.',','],['','.']) idi: '1234.56' → 123456 (100× hata)
foreach ([
    ['1234.56',      1234.56],   // EN ondalık — regresyon testi
    ['1234,56',      1234.56],   // TR ondalık
    ['1.234,56',     1234.56],   // TR binlik + ondalık
    ['1,234.56',     1234.56],   // EN binlik + ondalık
    ['12.500',       12500.0],   // binlik (3 hane kuralı)
    ['1.234.567,89', 1234567.89],
    ['0,5',          0.5],
    ['-350,25',      -350.25],
    ['₺1.250,00',   1250.0],    // sembollü
    ['1 234,56',     1234.56],   // boşluklu
    ['1234',         1234.0],
    ['',             0.0],
    ['abc',          0.0],
    // O1: çoklu binlik ayırıcı + ondalık kuralları
    ['1.234.567',    1234567.0],
    ['1,234,567',    1234567.0],
    ['1.234.56',     0.0],       // geçersiz gruplama
    ['0,005',        0.005],     // tam kısım 0 → ondalık
    ['1e5',          0.0],       // harf → geçersiz (eskiden 15)
    ['TL 1.250,00',  1250.0],
    ['1.234',        1234.0],
] as [$in, $exp]) {
    check("hesap_parse_amount('$in')", hesap_parse_amount($in), $exp);
}

echo "\n── Biçim doğrulama (O2) ──\n";
check("hesap_currency_sym('XXX<b>') kaçırıldı",   hesap_currency_sym('XXX<b>'), 'XXX&lt;b&gt;');
check("hesap_currency_sym('TRY')",                hesap_currency_sym('TRY'), '₺');
check('para birimi listesi',                      hesap_para_birimleri(), ['TRY','USD','EUR','AED']);
check("hesap_tarih_gecerli('abc')",               hesap_tarih_gecerli('abc'), false);
check("hesap_tarih_gecerli('2026-02-30')",        hesap_tarih_gecerli('2026-02-30'), false);
check("hesap_tarih_gecerli('2026-02-28')",        hesap_tarih_gecerli('2026-02-28'), true);
check("hesap_saat_gecerli('09:30')",              hesap_saat_gecerli('09:30'), true);
check("hesap_saat_gecerli('25:00')",              hesap_saat_gecerli('25:00'), false);
check('üçüncü şahıs bakiye etiketi',              hesap_balance_label(-5.0, true)['label'], 'Şirket personele borçlu');

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
