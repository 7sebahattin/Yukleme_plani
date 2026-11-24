<?php
// =========================================================
// scripts/kullanici_ayar_smoke.php — Kullanıcı Ayarları grubu (v574) çekirdek + statik değişmezler
//   php scripts/kullanici_ayar_smoke.php   → çıkış kodu 0 = hepsi geçti
// config/kullanici_ayar.php (şifre/profil) · config/ajanda_core.php · kt_sil. Bellek içi SQLite (siparis harness'i);
// canlı DB'ye HİÇ dokunmaz. Sayfa dosyaları için statik denetim (kapı, CSRF, kullanıcı id'sinin kaynağı, şifre sızıntısı).
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/kullanici_tercih.php';
require_once $ROOT . '/config/kullanici_ayar.php';
require_once $ROOT . '/config/ajanda_core.php';

$pdo = db();
siparis_test_diger_tablolar($pdo);   // users + audit_log (kolonları aşağıda genişletilir)
$pdo->exec('DROP TABLE users');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT DEFAULT \'\', display_name TEXT, password_hash TEXT, is_active INT DEFAULT 1, last_login_at TEXT)');
$pdo->exec('CREATE TABLE user_sessions (token TEXT PRIMARY KEY, user_id INT NOT NULL)');
$MEVCUT = 'eski-sifre-123';
$h = password_hash($MEVCUT, PASSWORD_BCRYPT, ['cost' => 4]);
$pdo->prepare("INSERT INTO users (id, username, email, display_name, password_hash) VALUES (1,'ayse','ayse@example.test','Ayşe',?),(2,'mehmet','mehmet@example.test','Mehmet',?)")->execute([$h, $h]);
foreach ([['t1a', 1], ['t1b', 1], ['t1c', 1], ['t2a', 2]] as [$t, $u]) $pdo->prepare('INSERT INTO user_sessions (token, user_id) VALUES (?, ?)')->execute([$t, $u]);

function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function auditler(string $modul, string $olay): array { $s = db()->prepare('SELECT * FROM audit_log WHERE module = ? AND action = ?'); $s->execute([$modul, $olay]); return $s->fetchAll(); }

echo "=== 1. Şifre biçim denetimi ===\n";
ok('boş yeni şifre reddedilir', ka_sifre_bicim_hata('', '') !== null);
ok('7 karakter reddedilir', ka_sifre_bicim_hata('abc1234', 'abc1234') !== null);
ok('8 karakter + eşleşme kabul', ka_sifre_bicim_hata('abcd1234', 'abcd1234') === null);
ok('eşleşmeyen tekrar reddedilir', ka_sifre_bicim_hata('abcd1234', 'abcd12345') !== null);
ok('73 bayt reddedilir (bcrypt kesmesi)', ka_sifre_bicim_hata(str_repeat('a', 73), str_repeat('a', 73)) !== null);
ok('72 bayt kabul', ka_sifre_bicim_hata(str_repeat('a', 72), str_repeat('a', 72)) === null);
ok('kullanıcı adıyla aynı şifre reddedilir (büyük/küçük harf duyarsız)', ka_sifre_bicim_hata('AyseAyse', 'AyseAyse', 'ayseayse') !== null);

echo "\n=== 2. Şifre değiştirme ===\n";
$r = ka_sifre_degistir($pdo, 1, '', 'yenisifre99', 'yenisifre99', 't1a');
ok('mevcut şifre boş → red', !$r['ok']);
$r = ka_sifre_degistir($pdo, 1, $MEVCUT, 'kisa', 'kisa', 't1a');
ok('biçim hatası → red, hash değişmedi', !$r['ok'] && password_verify($MEVCUT, (string)$pdo->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn()));
$_SESSION['sifre_onay'] = [];
$r = ka_sifre_degistir($pdo, 1, 'yanlis-sifre', 'yenisifre99', 'yenisifre99', 't1a');
ok('yanlış mevcut şifre → red', !$r['ok'] && str_contains($r['mesaj'], 'hatalı'), $r['mesaj']);
ok('yanlış denemede oturumlar silinmedi', say('user_sessions', 'user_id = 1') === 3);
$r = ka_sifre_degistir($pdo, 1, $MEVCUT, $MEVCUT, $MEVCUT, 't1a');
ok('yeni = mevcut → red', !$r['ok'] && str_contains($r['mesaj'], 'aynı'), $r['mesaj']);
$r = ka_sifre_degistir($pdo, 1, $MEVCUT, 'yenisifre99', 'yenisifre99', 't1a');
ok('doğru değişiklik başarılı', $r['ok'] && ($r['kapatilan'] ?? -1) === 2, json_encode($r));
$yeniHash = (string)$pdo->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn();
ok('yeni şifre doğrulanır, eskisi geçmez', password_verify('yenisifre99', $yeniHash) && !password_verify($MEVCUT, $yeniHash));
ok('bu oturum KALDI, diğer 2 oturum silindi', say('user_sessions', "user_id = 1") === 1 && say('user_sessions', "token = 't1a'") === 1);
ok('başka kullanıcının oturumuna dokunulmadı', say('user_sessions', 'user_id = 2') === 1);
ok('başka kullanıcının şifresi değişmedi', password_verify($MEVCUT, (string)$pdo->query('SELECT password_hash FROM users WHERE id=2')->fetchColumn()));
$a = auditler('users', 'password_change');
ok('audit: password_change yazıldı', count($a) === 1 && (int)$a[0]['record_id'] === 1);
$ham = json_encode($a);
ok('audit satırında şifre/hash YOK', !str_contains($ham, 'yenisifre99') && !str_contains($ham, $MEVCUT) && !str_contains($ham, '$2y$'));

// Token boş → hiçbir oturum silinmez (kendini atma yok)
$pdo->prepare('INSERT INTO user_sessions (token, user_id) VALUES (?, ?)')->execute(['t1x', 1]);
$r = ka_sifre_degistir($pdo, 1, 'yenisifre99', 'ucuncusifre77', 'ucuncusifre77', '');
ok('çerez yoksa: değişir ama oturum silinmez', $r['ok'] && ($r['kapatilan'] ?? -1) === 0 && say('user_sessions', 'user_id = 1') === 2, json_encode($r));

echo "\n=== 3. Şifre deneme sınırı (sifre_onay köprüsü) ===\n";
$_SESSION['sifre_onay'] = [];
for ($i = 1; $i <= 5; $i++) $son = ka_sifre_degistir($pdo, 1, 'yanlis' . $i, 'yenisifre99', 'yenisifre99', 't1a');
ok('5. yanlışta bekleme mesajı', !$son['ok'] && str_contains($son['mesaj'], 'sn sonra'), $son['mesaj']);
$r = ka_sifre_degistir($pdo, 1, 'ucuncusifre77', 'dorduncu1234', 'dorduncu1234', 't1a');
ok('bekleme sürerken DOĞRU şifre de reddedilir', !$r['ok'] && str_contains($r['mesaj'], 'sn sonra'), $r['mesaj']);
ok('bekleme sırasında hash değişmedi', password_verify('ucuncusifre77', (string)$pdo->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn()));
$_SESSION['sifre_onay'] = [];

echo "\n=== 4. Profil ===\n";
$r = ka_profil_kaydet($pdo, 1, "  Ayşe   Yılmaz\x07 ", 'ayse.yeni@example.test');
ok('geçerli profil kaydedilir', $r['ok'], json_encode($r));
$u = $pdo->query('SELECT display_name, email FROM users WHERE id=1')->fetch();
ok('ad temizlendi (boşluk/kontrol karakteri)', $u['display_name'] === 'Ayşe Yılmaz' && $u['email'] === 'ayse.yeni@example.test', json_encode($u, JSON_UNESCAPED_UNICODE));
ok('audit: update/users yazıldı', count(auditler('users', 'update')) === 1);
$r = ka_profil_kaydet($pdo, 1, 'Ayşe', 'gecersiz-eposta');
ok('geçersiz e-posta reddedilir', !$r['ok']);
$r = ka_profil_kaydet($pdo, 1, 'Ayşe', 'mehmet@example.test');
ok('başkasının e-postası reddedilir', !$r['ok'] && str_contains($r['mesaj'], 'başka'), $r['mesaj']);
$r = ka_profil_kaydet($pdo, 1, 'Ayşe', '');
ok('boş e-posta serbest', $r['ok'] && (string)$pdo->query('SELECT email FROM users WHERE id=1')->fetchColumn() === '');
$r = ka_profil_kaydet($pdo, 2, str_repeat('x', KA_AD_MAX + 1), '');
ok('çok uzun ad reddedilir', !$r['ok']);
ok('profil yalnız kendi satırını etkiler', (string)$pdo->query('SELECT display_name FROM users WHERE id=2')->fetchColumn() === 'Mehmet');
$once = say('audit_log', "module = 'users' AND action = 'update'");
$r = ka_profil_kaydet($pdo, 1, 'Ayşe', '');
ok('değişiklik yoksa audit yazılmaz', $r['ok'] && say('audit_log', "module = 'users' AND action = 'update'") === $once);

echo "\n=== 5. Ajanda şeması ===\n";
ok('tablo yokken ajanda_hazir false', ajanda_hazir($pdo) === false);
$GLOBALS['_ajanda_ddl_calistir'] = function (PDO $p, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$m = ajanda_migrate($pdo);
ok('migrate: olusturuldu', $m[0]['durum'] === 'olusturuldu', json_encode($m));
ok('migrate sonrası ajanda_hazir true', ajanda_hazir($pdo) === true);
ok('migrate tekrar: var (idempotent)', ajanda_migrate($pdo)[0]['durum'] === 'var');

echo "\n=== 6. Ajanda girdi doğrulama ===\n";
ok('tarih 2026-11-24 geçerli', ajanda_tarih_coz('2026-11-24') === '2026-11-24');
ok('tarih 2026-02-30 geçersiz', ajanda_tarih_coz('2026-02-30') === null);
ok('tarih 24.11.2026 geçersiz (yalnız ISO)', ajanda_tarih_coz('24.11.2026') === null);
ok('tarih 1999 aralık dışı', ajanda_tarih_coz('1999-01-01') === null);
ok('saat boş → ""', ajanda_saat_coz('') === '');
ok('saat 9:05 → 09:05', ajanda_saat_coz('9:05') === '09:05');
ok('saat 24:00 geçersiz', ajanda_saat_coz('24:00') === null);
ok('saat 12:60 geçersiz', ajanda_saat_coz('12:60') === null);
ok('boş başlık reddedilir', !ajanda_girdi_coz(['baslik' => '   ', 'tarih' => '2026-11-24'])['ok']);
ok('başlık uzunluk sınırı', !ajanda_girdi_coz(['baslik' => str_repeat('b', AJ_BASLIK_MAX + 1), 'tarih' => '2026-11-24'])['ok']);
ok('not uzunluk sınırı', !ajanda_girdi_coz(['baslik' => 'x', 'not_metni' => str_repeat('n', AJ_NOT_MAX + 1), 'tarih' => '2026-11-24'])['ok']);
ok('çok boşluklu başlık sadeleşir', ajanda_girdi_coz(['baslik' => " a \t b  ", 'tarih' => '2026-11-24'])['veri']['baslik'] === 'a b');

echo "\n=== 7. Ajanda CRUD + kişiye özel izolasyon ===\n";
$bugun = '2026-11-24';
$k = [
    'gecmis' => ajanda_ekle($pdo, 1, ['baslik' => 'GİZLİ-BAŞLIK-ESKİ', 'tarih' => '2026-11-20', 'not_metni' => 'özel not'])['id'],
    'bugun2' => ajanda_ekle($pdo, 1, ['baslik' => 'bugün öğleden sonra', 'tarih' => $bugun, 'saat' => '15:00'])['id'],
    'bugun1' => ajanda_ekle($pdo, 1, ['baslik' => 'bugün sabah', 'tarih' => $bugun, 'saat' => '9:30'])['id'],
    'bugunS' => ajanda_ekle($pdo, 1, ['baslik' => 'bugün saatsiz', 'tarih' => $bugun])['id'],
    'yarin'  => ajanda_ekle($pdo, 1, ['baslik' => 'yarın', 'tarih' => '2026-11-25'])['id'],
    'hafta'  => ajanda_ekle($pdo, 1, ['baslik' => 'hafta içi', 'tarih' => '2026-11-30'])['id'],
    'sonra'  => ajanda_ekle($pdo, 1, ['baslik' => 'ay sonu', 'tarih' => '2026-12-20'])['id'],
];
$baskasi = ajanda_ekle($pdo, 2, ['baslik' => 'Mehmet\'in kaydı', 'tarih' => $bugun])['id'];
ok('7 + 1 kayıt eklendi', say('ajanda_kayitlar') === 8);
$acik = ajanda_listele($pdo, 1, 'acik');
ok('açık liste yalnız kendi kayıtları (7)', count($acik) === 7 && !in_array('Mehmet\'in kaydı', array_column($acik, 'baslik'), true));
ok('sıralama: tarih → saat → saatsiz sonda', array_column($acik, 'baslik') === ['GİZLİ-BAŞLIK-ESKİ', 'bugün sabah', 'bugün öğleden sonra', 'bugün saatsiz', 'yarın', 'hafta içi', 'ay sonu'], implode('|', array_column($acik, 'baslik')));
$g = ajanda_grupla($acik, $bugun);
ok('gruplama: gecikmiş/bugün/yarın/hafta/sonra = 1/3/1/1/1', array_map('count', $g) === ['gecikmis' => 1, 'bugun' => 3, 'yarin' => 1, 'hafta' => 1, 'sonra' => 1], json_encode(array_map('count', $g)));
$o = ajanda_ozet($pdo, 1, $bugun);
ok('özet: gecikmiş 1 · bugün 3 · 7 gün 2 · açık 7 · tamam 0', $o === ['gecikmis' => 1, 'bugun' => 3, 'hafta' => 2, 'acik' => 7, 'tamam' => 0], json_encode($o));

$r = ajanda_guncelle($pdo, 2, $k['gecmis'], ['baslik' => 'ele geçirildi', 'tarih' => $bugun]);
ok('başkası güncelleyemez (bulunamadı)', !$r['ok'] && (string)$pdo->query("SELECT baslik FROM ajanda_kayitlar WHERE id = {$k['gecmis']}")->fetchColumn() === 'GİZLİ-BAŞLIK-ESKİ');
ok('başkası durum değiştiremez', !ajanda_durum($pdo, 2, $k['gecmis'], true)['ok'] && (int)$pdo->query("SELECT tamam FROM ajanda_kayitlar WHERE id = {$k['gecmis']}")->fetchColumn() === 0);
ok('başkası silemez', !ajanda_sil($pdo, 2, $k['gecmis'])['ok'] && say('ajanda_kayitlar', "id = {$k['gecmis']}") === 1);
ok('başkasının listesinde görünmez', count(ajanda_listele($pdo, 2, 'tumu')) === 1);

$r = ajanda_guncelle($pdo, 1, $k['yarin'], ['baslik' => 'yarın (düzeltildi)', 'tarih' => '2026-11-26', 'saat' => '08:00', 'not_metni' => 'not eklendi']);
ok('sahibi günceller', $r['ok']);
$satir = $pdo->query("SELECT * FROM ajanda_kayitlar WHERE id = {$k['yarin']}")->fetch();
ok('güncel değerler yazıldı', $satir['baslik'] === 'yarın (düzeltildi)' && $satir['tarih'] === '2026-11-26' && $satir['saat'] === '08:00' && $satir['not_metni'] === 'not eklendi');
ok('aynı değerle güncelleme "bulunamadı" demez', ajanda_guncelle($pdo, 1, $k['yarin'], ['baslik' => 'yarın (düzeltildi)', 'tarih' => '2026-11-26', 'saat' => '08:00', 'not_metni' => 'not eklendi'])['ok']);

ok('tamamlandı işaretlenir', ajanda_durum($pdo, 1, $k['gecmis'], true, '2026-11-24 10:00:00')['ok']);
ok('tamamlanan açık listeden düştü', count(ajanda_listele($pdo, 1, 'acik')) === 6);
$tm = ajanda_listele($pdo, 1, 'tamam');
ok('tamam görünümünde 1 kayıt + tamam_zaman', count($tm) === 1 && $tm[0]['tamam_zaman'] === '2026-11-24 10:00:00');
ok('yeniden açılır', ajanda_durum($pdo, 1, $k['gecmis'], false)['ok'] && (string)$pdo->query("SELECT COALESCE(tamam_zaman,'') FROM ajanda_kayitlar WHERE id = {$k['gecmis']}")->fetchColumn() === '');
ajanda_durum($pdo, 1, $k['gecmis'], true); ajanda_durum($pdo, 1, $k['sonra'], true);
$t = ajanda_tamamlananlari_sil($pdo, 1);
ok('tamamlananları toplu sil: 2 kayıt', $t['ok'] && $t['adet'] === 2 && say('ajanda_kayitlar', 'user_id = 1 AND tamam = 1') === 0);
ok('toplu silme başkasının kaydına dokunmadı', say('ajanda_kayitlar', 'user_id = 2') === 1);
ok('sahibi siler', ajanda_sil($pdo, 1, $k['hafta'])['ok'] && say('ajanda_kayitlar', "id = {$k['hafta']}") === 0);
ok('olmayan kayıt silinemez', !ajanda_sil($pdo, 1, 999999)['ok']);
ok('geçersiz id/uid güvenli', !ajanda_sil($pdo, 1, 0)['ok'] && !ajanda_ekle($pdo, 0, ['baslik' => 'x', 'tarih' => $bugun])['ok'] && ajanda_listele($pdo, 0) === []);

echo "\n=== 8. Ajanda audit gizliliği + açık kayıt tavanı ===\n";
$aj = json_encode($pdo->query("SELECT * FROM audit_log WHERE module = 'ajanda'")->fetchAll(), JSON_UNESCAPED_UNICODE);
ok('audit: create/update/delete olayları var', count(auditler('ajanda', 'create')) >= 7 && count(auditler('ajanda', 'update')) >= 1 && count(auditler('ajanda', 'delete')) >= 1);
ok('audit satırlarında başlık/not içeriği YOK', !str_contains($aj, 'GİZLİ-BAŞLIK-ESKİ') && !str_contains($aj, 'özel not') && !str_contains($aj, 'not eklendi'));
$pdo->exec('DELETE FROM ajanda_kayitlar WHERE user_id = 1');
$pdo->beginTransaction();
$ins = $pdo->prepare("INSERT INTO ajanda_kayitlar (user_id, baslik, tarih, tamam, created_at, updated_at) VALUES (1, 'x', '2026-12-01', 0, '2026-11-24 00:00:00', '2026-11-24 00:00:00')");
for ($i = 0; $i < AJ_ACIK_MAX; $i++) $ins->execute();
$pdo->commit();
ok('açık kayıt tavanı: ' . AJ_ACIK_MAX . ' dolunca yeni kayıt reddedilir', !ajanda_ekle($pdo, 1, ['baslik' => 'taşan', 'tarih' => '2026-12-02'])['ok']);
ok('tavan başka kullanıcıyı etkilemez', ajanda_ekle($pdo, 2, ['baslik' => 'serbest', 'tarih' => '2026-12-02'])['ok']);

echo "\n=== 9. Tarih etiketi ===\n";
ok('24 Kas 2026 Salı', ajanda_tarih_etiket('2026-11-24') === '24 Kas 2026 Salı', ajanda_tarih_etiket('2026-11-24'));
ok('1 Oca 2027 Cuma', ajanda_tarih_etiket('2027-01-01') === '1 Oca 2027 Cuma', ajanda_tarih_etiket('2027-01-01'));

echo "\n=== 10. kt_sil (tablo düzeni sıfırlama) ===\n";
$pdo->exec('CREATE TABLE kullanici_tercihleri (user_id INT NOT NULL, anahtar TEXT NOT NULL, deger TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY (user_id, anahtar))');
ok('kt_yaz palet düzeni', kt_yaz($pdo, 1, 'palet_fisleri.tablo', ['genislik' => ['belge_no' => 120], 'sira' => ['belge_no'], 'gizli' => []]));
ok('kt_yaz başka kullanıcı', kt_yaz($pdo, 2, 'palet_fisleri.tablo', ['gizli' => ['sube']]));
ok('kt_oku kaydı döner', kt_oku($pdo, 1, 'palet_fisleri.tablo') !== null);
ok('kt_sil bilinmeyen anahtarı REDDEDER', kt_sil($pdo, 1, 'baska.anahtar') === false);
ok('kt_sil kendi kaydını siler', kt_sil($pdo, 1, 'palet_fisleri.tablo') === true && kt_oku($pdo, 1, 'palet_fisleri.tablo') === null);
ok('kt_sil başkasının kaydına dokunmaz', kt_oku($pdo, 2, 'palet_fisleri.tablo') !== null);
ok('kt_sil kayıt yokken de true', kt_sil($pdo, 1, 'kantar.tablo') === true);
ok('ka_tablo_tercihleri anahtarları kt_dogrula beyaz listesinde', array_reduce(array_keys(ka_tablo_tercihleri()), fn($c, $a) => $c && kt_dogrula($a, []) !== null, true));

echo "\n=== 11. Sayfa dosyaları — statik değişmezler ===\n";
$oku = fn(string $f): string => (string)file_get_contents($ROOT . '/' . $f);
foreach (['sifre_degistirme.php' => 'sifre_degistirme', 'ajanda.php' => 'ajanda', 'kullanici_tercihleri.php' => 'kullanici_tercihleri'] as $f => $slug) {
    $s = $oku($f);
    ok("$f: require_login() + muhasebe_sayfa_can('$slug') kapısı", str_contains($s, 'require_login()') && str_contains($s, "if (!muhasebe_sayfa_can('$slug')) { forbidden(); }") && !preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s));
    ok("$f: yer tutucu metni YOK, render_header/render_footer var", !str_contains($s, 'hazırlanıyor') && str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
    ok("$f: her POST'ta csrf_check", str_contains($s, "csrf_check(\$_POST['csrf']") && substr_count($s, 'name="csrf"') >= 1);
    ok("$f: kullanıcı id'si oturumdan (POST/GET'ten user_id/uid ALINMAZ)", str_contains($s, "(int)\$auth_user['id']") && !preg_match('/\$_(POST|GET|REQUEST)\[\'(user_id|uid|kullanici_id)\'\]/', $s));
    ok("$f: PRG (header Location + exit) ve no-store", str_contains($s, "header('Location:") && str_contains($s, "header('Cache-Control: no-store')"));
    ok("$f: sınıf/kimlik adında reklam engelleyici süzgeci parçası yok", !preg_match('/(class|id)="[^"]*\b(ad|ads|advert|sponsor|banner)\b[^"]*"/i', $s));
}
$sf = $oku('sifre_degistirme.php');
ok('sifre_degistirme.php: şifre alanları geri basılmaz (value= yok)', !preg_match('/<input[^>]+type="password"[^>]+value=/', $sf));
ok('sifre_degistirme.php: autocomplete current/new-password', str_contains($sf, 'autocomplete="current-password"') && substr_count($sf, 'autocomplete="new-password"') === 2);
ok('sifre_degistirme.php: şifre error_log/audit çağrısı yok', !preg_match('/error_log\([^)]*\$_POST|audit_log_event\([^)]*(mevcut|yeni)/', $sf));
$aj = $oku('ajanda.php');
ok('ajanda.php: yazmalar yalnız ajanda_* çekirdeğinden (sayfada ham INSERT/UPDATE/DELETE yok)', !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $aj));
ok('ajanda.php: tablo yokken kurulum notu', str_contains($aj, 'migrate.php#ajanda') && str_contains($aj, 'ajanda_hazir('));
$kt = $oku('kullanici_tercihleri.php');
ok('kullanici_tercihleri.php: tablo sıfırlama beyaz listeden (ka_tablo_tercihleri)', str_contains($kt, 'isset($TABLOLAR[$anahtar])'));
ok('kullanici_tercihleri.php: ALTER/CREATE TABLE YOK (şema yalnız migrate.php)', !preg_match('/\b(CREATE\s+TABLE|ALTER\s+TABLE)\b/i', $kt));
$mg = $oku('migrate.php');
ok('migrate.php: Ajanda kartı + ne=ajanda POST dalı + ajanda_migrate', str_contains($mg, "mg_kart_ac('ajanda')") && str_contains($mg, "=== 'ajanda'") && str_contains($mg, 'ajanda_migrate($pdo)'));
ok('ajanda_core.php: DDL yalnız ajanda_migrate içinde çalışır (başka dosyada CREATE yok)', substr_count($oku('config/ajanda_core.php'), '$pdo->exec(ajanda_ddl())') === 1 && !str_contains($aj . $kt . $sf, 'ajanda_ddl'));
ok('SW önbelleğe ALMAZ (sifre_deg|ajanda|kullanici_tercihleri)', str_contains($oku('sw.js'), 'sifre_deg') && str_contains($oku('sw.js'), 'ajanda') && str_contains($oku('sw.js'), 'tercihleri'));
$css = $oku('assets/kullanici_ayar.css');
ok('kullanici_ayar.css: reklam engelleyici süzgeci parçası içeren sınıf yok', !preg_match('/\.[a-z-]*\b(ad|ads|advert|sponsor|banner)\b[a-z-]*\s*[{,.:\s]/i', preg_replace('/\/\*.*?\*\//s', '', $css)));
ok('kullanici_ayar.css: mobil kırılım (<768px) var', str_contains($css, '@media (max-width: 767px)'));

siparis_test_bitir();
