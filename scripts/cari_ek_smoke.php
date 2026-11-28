<?php
// =========================================================
// scripts/cari_ek_smoke.php — Cari ek şeması (v611) çekirdek testi: config/cari_ek_sema.php + cari_core.php eklemeleri
//   php scripts/cari_ek_smoke.php     → çıkış kodu 0 = hepsi geçti
// Bellek içi SQLite (siparis harness'i). Canlı DB'ye HİÇ dokunmaz. Kimlikler SENTETİK (algoritmadan üretilir, gerçek kişi değil).
// Kapsam: fail-soft (şema hazır değil) · migrate iki kez (idempotent) · md_id düşer · tip tohumu · sistem tipi · kullanıcı tipi ·
//         VKN/TCKN algoritma · tekillik · doğum tarihi yalnız müstahsilde · eksik kuralı · hızlı ekle → hizli=1 → tamamlanınca 0 · audit'te kişisel veri yok.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/cari_core.php';

$pdo = db();
siparis_test_diger_tablolar($pdo);   // users + audit_log
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
$UID = 1; $IS_ADMIN = true; $AKTIF_DEPO = 'DEPO1';

/** Sentetik geçerli TCKN: 9 haneli tohumdan (ilk hane ≠ 0). */
function t_tckn(string $d9): string
{
    $d = array_map('intval', str_split($d9));
    $d10 = ((($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7 - ($d[1] + $d[3] + $d[5] + $d[7])) % 10 + 10) % 10;
    $d11 = (array_sum($d) + $d10) % 10;
    return $d9 . $d10 . $d11;
}
/** Sentetik geçerli VKN: 9 haneli tohumdan. */
function t_vkn(string $d9): string
{
    $d = array_map('intval', str_split($d9));
    $top = 0;
    for ($i = 0; $i < 9; $i++) {
        $t = ($d[$i] + 9 - $i) % 10;
        $p = ($t * (2 ** (9 - $i))) % 9;
        if ($t !== 0 && $p === 0) $p = 9;
        $top += $p;
    }
    return $d9 . ((10 - $top % 10) % 10);
}
function t_bozuk(string $s): string { return substr($s, 0, -1) . ((((int)substr($s, -1)) + 1) % 10); }
function kart(PDO $p, array $g, ?int $id = null): array { global $UID; return cari_kart_kaydet($p, $g + ['tip' => 'musteri', 'para_birimi' => 'TRY', 'aktif' => '1'], $UID, $id); }
$ddl = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$GLOBALS['_cari_ddl_calistir'] = $ddl;

echo "=== 1. Algoritmalar ===\n";
$tk = t_tckn('123456789'); $vk = t_vkn('123456789');
ok('üretilen TCKN geçerli, son hane bozulunca geçersiz', cari_tckn_gecerli($tk) && !cari_tckn_gecerli(t_bozuk($tk)));
ok('TCKN: ilk hane 0 / 10 hane / harf reddedilir', !cari_tckn_gecerli('0' . substr($tk, 1)) && !cari_tckn_gecerli(substr($tk, 0, 10)) && !cari_tckn_gecerli('1234567890a'));
ok('üretilen VKN geçerli, son hane bozulunca geçersiz', cari_vkn_gecerli($vk) && !cari_vkn_gecerli(t_bozuk($vk)));
ok('VKN: 9/11 hane ve harf reddedilir', !cari_vkn_gecerli(substr($vk, 0, 9)) && !cari_vkn_gecerli($vk . '1') && !cari_vkn_gecerli('12345678a0'));
ok('TCKN maskesi tam değeri göstermez', !str_contains(cari_tckn_maske($tk), substr($tk, 2, 7)) && str_starts_with(cari_tckn_maske($tk), substr($tk, 0, 2)) && str_ends_with(cari_tckn_maske($tk), substr($tk, -2)));

echo "\n=== 2. Fail-soft: ek şema kurulu DEĞİL (eski şema, md_id var) ===\n";
$eski = new PDO('sqlite::memory:');
$eski->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$eski->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$eski->exec('CREATE TABLE cari_kartlar (id INTEGER PRIMARY KEY AUTOINCREMENT, kod VARCHAR(30) NOT NULL, unvan VARCHAR(200) NOT NULL, tip VARCHAR(20) NOT NULL DEFAULT \'musteri\', grup_id INT NULL, vergi_dairesi VARCHAR(100) NULL, vergi_no VARCHAR(20) NULL, yetkili VARCHAR(150) NULL, telefon VARCHAR(40) NULL, eposta VARCHAR(150) NULL, adres VARCHAR(500) NULL, il VARCHAR(60) NULL, ulke VARCHAR(60) NULL, iban VARCHAR(40) NULL, para_birimi VARCHAR(3) NOT NULL DEFAULT \'TRY\', vade_gun INT NULL, notlar TEXT NULL, aktif TINYINT(1) NOT NULL DEFAULT 1, md_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INT NULL, updated_at DATETIME NULL, updated_by INT NULL)');
$eski->exec('CREATE UNIQUE INDEX uq_cari_kod ON cari_kartlar (kod)');
$eski->exec('CREATE INDEX idx_cari_md ON cari_kartlar (md_id)');
$eski->exec('CREATE TABLE cari_tanimlar (id INTEGER PRIMARY KEY AUTOINCREMENT, tur VARCHAR(20) NOT NULL, ad VARCHAR(100) NOT NULL, sira INT NOT NULL DEFAULT 0, aktif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INT NULL)');
$eski->exec('CREATE UNIQUE INDEX uq_carit_tur_ad ON cari_tanimlar (tur, ad)');
[$c, $ix] = siparis_ddl_sqlite(cari_tablolar()['cari_hareketler']); $eski->exec($c); foreach ($ix as $x) $eski->exec($x);
ok('eski şema: cari_hazir true, cari_ek_hazir false', cari_hazir($eski) && !cari_ek_hazir($eski));
ok('eski şema: cari_tipler sabit 5\'li liste', array_keys(cari_tipler($eski)) === ['musteri', 'tedarikci', 'ikisi', 'personel', 'diger'] && cari_tipler($eski, true) === cari_tipler($eski));
$o1 = kart($eski, ['unvan' => 'Eski Şema Örnek Ltd.', 'vergi_no' => '12345678901', 'telefon' => '0 555 000 00 01', 'tckn' => $tk, 'dogum_tarihi' => '1980-01-01']);
ok('eski şema: kart bugünkü gibi kaydedilir (11 haneli vergi no kabul, tckn/dogum yok sayılır), hata yok', $o1['ok'] === true && !isset($o1['eksikler']), json_encode($o1));
$satir = $eski->query('SELECT * FROM cari_kartlar WHERE id = ' . (int)$o1['id'])->fetch();
ok('eski şema: yeni kolonlar yazılmadı (satırda tckn/hizli anahtarı yok)', !array_key_exists('tckn', $satir) && !array_key_exists('hizli', $satir) && $satir['vergi_no'] === '12345678901');
ok('eski şema: hızlı ekle / tip tanımı reddedilir, tip listesi boş', !cari_kart_hizli_ekle($eski, ['unvan' => 'X Ltd', 'tip' => 'musteri'], 1)['ok'] && !cari_tanim_kaydet($eski, 'tip', 'Yeni Tip', 1, 1)['ok'] && cari_tanim_listesi($eski, 'tip') === []);
ok('eski şema: pasif/kart listesi + liste sorgusu hatasız', count(cari_kart_listesi($eski)['satirlar']) === 1 && cari_kart_oku($eski, (int)$o1['id']) !== null);
$o1b = kart($eski, ['unvan' => 'Eski Şema Örnek Ltd. (düzenlendi)', 'vergi_no' => '12345678901', 'telefon' => '0 555 000 00 01'], (int)$o1['id']);
ok('eski şema: düzenleme çalışır', $o1b['ok'] === true);

echo "\n=== 3. cari_ek_migrate: uygulanır, md_id düşer, tip tohumu, idempotent ===\n";
$m = cari_ek_migrate($eski);
$dur = array_column($m, 'durum', 'adim');
ok('migrate: hata satırı yok', !array_filter($m, fn($r) => $r['durum'] === 'hata'), json_encode($m, JSON_UNESCAPED_UNICODE));
ok('migrate: 4 kolon eklendi (kod, tckn, dogum_tarihi, hizli), md_id kaldırıldı, tip tohumu eklendi', ($dur['cari_tanimlar.kod'] ?? '') === 'eklendi' && ($dur['cari_kartlar.tckn'] ?? '') === 'eklendi' && ($dur['cari_kartlar.dogum_tarihi'] ?? '') === 'eklendi' && ($dur['cari_kartlar.hizli'] ?? '') === 'eklendi' && ($dur['cari_kartlar.md_id'] ?? '') === 'kaldirildi' && ($dur['sistem cari tipleri'] ?? '') === 'eklendi');
ok('migrate sonrası cari_ek_hazir true', cari_ek_hazir($eski));
$kol = array_column($eski->query('PRAGMA table_info(cari_kartlar)')->fetchAll(), 'name');
ok('md_id kolonu + idx_cari_md düştü; tckn/dogum_tarihi/hizli var', !in_array('md_id', $kol, true) && !cari_ek_indeks_var($eski, 'cari_kartlar', 'idx_cari_md') && in_array('tckn', $kol, true) && in_array('hizli', $kol, true));
ok('indeksler: (tur,kod), tckn, vergi_no', cari_ek_indeks_var($eski, 'cari_tanimlar', 'idx_carit_tur_kod') && cari_ek_indeks_var($eski, 'cari_kartlar', 'idx_cari_tckn') && cari_ek_indeks_var($eski, 'cari_kartlar', 'idx_cari_vergi'));
ok('mevcut kart korunur, hizli=0', (int)$eski->query('SELECT COUNT(*) FROM cari_kartlar')->fetchColumn() === 1 && (int)$eski->query('SELECT hizli FROM cari_kartlar WHERE id = ' . (int)$o1['id'])->fetchColumn() === 0);
$m2 = cari_ek_migrate($eski);
ok('migrate ikinci kez: hepsi var, hiçbir şey eklenmedi/kaldırılmadı', !array_filter($m2, fn($r) => $r['durum'] !== 'var'), json_encode($m2, JSON_UNESCAPED_UNICODE));
ok('tohum çoğalmadı (8 sistem tipi)', (int)$eski->query("SELECT COUNT(*) FROM cari_tanimlar WHERE tur = 'tip'")->fetchColumn() === 8);
$eskiTipler = cari_tipler($eski);
ok('tip sırası/adları: Müşteri, Tedarikçi, Müşteri+Tedarikçi, Personel/Çavuş, Diğer, Müstahsil, Nakliyeci, Gümrükçü', array_keys($eskiTipler) === ['musteri', 'tedarikci', 'ikisi', 'personel', 'diger', 'mustahsil', 'nakliyeci', 'gumrukcu'] && $eskiTipler['gumrukcu'] === 'Gümrükçü');
ok('kart "tam hazır": kolon + indeks + 8 tip tohumu', cari_ek_tam_hazir($eski));
$eski->exec("DELETE FROM cari_tanimlar WHERE tur = 'tip' AND kod = 'gumrukcu'");
ok('bir sistem tipi eksikse kart "tam hazır" DEĞİL (✗ kalır)', cari_ek_hazir($eski) && !cari_ek_tam_hazir($eski));
cari_ek_migrate($eski);
ok('migrate eksik tipi tamamlar → yeniden tam hazır', cari_ek_tam_hazir($eski));
$eski->exec('DROP INDEX `idx_cari_vergi`');
ok('bir indeks eksikse kart "tam hazır" DEĞİL', !cari_ek_tam_hazir($eski));
cari_ek_migrate($eski);
ok('migrate eksik indeksi tamamlar', cari_ek_tam_hazir($eski));
$bos = new PDO('sqlite::memory:'); $bos->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ok('cari tabloları yokken migrate hata satırı döner (çökmez)', cari_ek_migrate($bos)[0]['durum'] === 'hata');

echo "\n=== 4. Yeni kurulum (cari_migrate) ===\n";
$m = cari_migrate($pdo);
ok('yeni kurulumda 3 tablo + ek şema hazır + md_id YOK', count($m) === 3 && cari_ek_hazir($pdo) && !cari_ek_kolon_var($pdo, 'cari_kartlar', 'md_id'));
ok('yeni kurulumda 8 sistem tipi + 6 ödeme şekli tohumlandı', say('cari_tanimlar', "tur = 'tip'") === 8 && say('cari_tanimlar', "tur = 'odeme_sekli'") === 6);
cari_migrate($pdo);
ok('cari_migrate tekrar: tohum çoğalmaz', say('cari_tanimlar', "tur = 'tip'") === 8 && say('cari_tanimlar') === 14);
$mi = cari_ek_migrate($pdo);
ok('yeni kurulum + cari_ek_migrate: hepsi var (idempotent)', !array_filter($mi, fn($r) => $r['durum'] !== 'var'), json_encode($mi, JSON_UNESCAPED_UNICODE));

echo "\n=== 5. Tipler: sistem tipi ===\n";
$tl = cari_tanim_listesi($pdo, 'tip');
$tid = []; foreach ($tl as $t) $tid[(string)$t['kod']] = (int)$t['id'];
ok('cari_tanim_turleri tip içerir', (cari_tanim_turleri()['tip'] ?? '') === 'Cari Tipi');
$r = cari_tanim_kaydet($pdo, 'tip', 'Alıcı', 10, $UID, $tid['musteri']);
ok('sistem tipi adı değişir, KOD değişmez', $r['ok'] && cari_tipler($pdo)['musteri'] === 'Alıcı' && (string)$pdo->query('SELECT kod FROM cari_tanimlar WHERE id = ' . $tid['musteri'])->fetchColumn() === 'musteri');
cari_tanim_kaydet($pdo, 'tip', 'Müşteri', 10, $UID, $tid['musteri']);
foreach (['musteri', 'tedarikci', 'ikisi', 'personel', 'diger', 'mustahsil', 'nakliyeci', 'gumrukcu'] as $kod) {
    $s = cari_tanim_sil($pdo, $tid[$kod], $UID);
    if ($s['ok'] || ($s['kod'] ?? '') !== 'sistem') ok("sistem tipi $kod silinemez", false, json_encode($s));
}
ok('8 sistem tipi silinemez (kod=sistem)', say('cari_tanimlar', "tur = 'tip'") === 8);
$s1 = cari_tanim_durum($pdo, $tid['musteri'], false, $UID); $s2 = cari_tanim_durum($pdo, $tid['tedarikci'], false, $UID);
ok('musteri / tedarikci pasif yapılamaz', !$s1['ok'] && !$s2['ok'] && isset(cari_tipler($pdo)['musteri'], cari_tipler($pdo)['tedarikci']));
$s3 = cari_tanim_durum($pdo, $tid['gumrukcu'], false, $UID);
ok('diğer sistem tipleri pasif yapılabilir (aktif listeden düşer, tüm listede kalır)', $s3['ok'] && !isset(cari_tipler($pdo)['gumrukcu']) && isset(cari_tipler($pdo, true)['gumrukcu']));
$ng = kart($pdo, ['unvan' => 'Pasif Tipli Yeni Kart', 'tip' => 'gumrukcu']);
ok('pasif tip yeni kartta seçilemez', !$ng['ok'] && isset($ng['alanlar']['tip']));
cari_tanim_durum($pdo, $tid['gumrukcu'], true, $UID);

echo "\n=== 6. Tipler: kullanıcı tipi ===\n";
$a = cari_tanim_kaydet($pdo, 'tip', 'Komisyoncu', 90, $UID);
$kodA = (string)$pdo->query('SELECT kod FROM cari_tanimlar WHERE id = ' . (int)$a['id'])->fetchColumn();
ok('kullanıcı tipi eklenir, kod ad slug\'ı', $a['ok'] && $kodA === 'komisyoncu' && isset(cari_tipler($pdo)['komisyoncu']));
$b = cari_tanim_kaydet($pdo, 'tip', 'Çiftçi / Üretici Ortak Kooperatif', 91, $UID);
$kodB = (string)$pdo->query('SELECT kod FROM cari_tanimlar WHERE id = ' . (int)$b['id'])->fetchColumn();
ok('uzun/Türkçe ad: kod ≤20 karakter ASCII', $b['ok'] && strlen($kodB) <= 20 && preg_match('/^[a-z0-9]{1,20}$/', $kodB) === 1 && str_starts_with($kodB, 'ciftci'), $kodB);
$c1 = cari_tanim_kaydet($pdo, 'tip', '!!!', 92, $UID);
$kodC = (string)$pdo->query('SELECT kod FROM cari_tanimlar WHERE id = ' . (int)$c1['id'])->fetchColumn();
ok('slug boşsa t<sayı> kodu', $c1['ok'] && preg_match('/^t\d+$/', $kodC) === 1, $kodC);
$d1 = cari_tanim_kaydet($pdo, 'tip', 'Müşteri+Tedarikçi', 93, $UID);
ok('aynı ad (TR-duyarsız) reddedilir', !$d1['ok'] && !cari_tanim_kaydet($pdo, 'tip', 'KOMİSYONCU', 94, $UID)['ok']);
$e1 = cari_tanim_kaydet($pdo, 'tip', 'Musteri', 95, $UID);
$kodE = $e1['ok'] ? (string)$pdo->query('SELECT kod FROM cari_tanimlar WHERE id = ' . (int)$e1['id'])->fetchColumn() : '';
ok('slug sistem koduyla çakışırsa (musteri) t<sayı>', $e1['ok'] && $kodE !== 'musteri' && preg_match('/^t\d+$/', $kodE) === 1, $kodE);
$ck = kart($pdo, ['unvan' => 'Komisyoncu Kart A', 'tip' => 'komisyoncu']);
ok('kullanıcı tipiyle kart açılır', $ck['ok'] === true);
$sl = cari_tanim_sil($pdo, (int)$a['id'], $UID);
ok('kullanılan tip silinemez (kullanım sayısı mesajda), kullanım listede sayılır', !$sl['ok'] && ($sl['kod'] ?? '') === 'kullanimda' && str_contains($sl['hata'], '1 cari kartta') && (int)array_values(array_filter(cari_tanim_listesi($pdo, 'tip'), fn($t) => $t['kod'] === 'komisyoncu'))[0]['kullanim'] === 1);
cari_tanim_durum($pdo, (int)$a['id'], false, $UID);
$ck2 = kart($pdo, ['unvan' => 'Komisyoncu Kart B', 'tip' => 'komisyoncu']);
ok('pasif kullanıcı tipi yeni kartta seçilemez', !$ck2['ok']);
$ckd = kart($pdo, ['unvan' => 'Komisyoncu Kart A (düzenlendi)', 'tip' => 'komisyoncu', 'telefon' => '0 555 000 00 02'], (int)$ck['id']);
ok('pasif tipteki MEVCUT kart düzenlenebilir', $ckd['ok'] === true);
ok('başka tipe geçip tekrar pasif tipe dönmek yeni seçim sayılır (reddedilir)', kart($pdo, ['unvan' => 'Komisyoncu Kart A', 'tip' => 'tedarikci'], (int)$ck['id'])['ok'] && !kart($pdo, ['unvan' => 'Komisyoncu Kart A', 'tip' => 'komisyoncu'], (int)$ck['id'])['ok']);
$bos2 = cari_tanim_sil($pdo, (int)$b['id'], $UID);
ok('kullanılmayan kullanıcı tipi silinir', $bos2['ok'] && !isset(cari_tipler($pdo, true)[$kodB]));
ok('geçersiz tip kodu reddedilir', !kart($pdo, ['unvan' => 'Geçersiz Tipli', 'tip' => 'yok_boyle'])['ok']);

echo "\n=== 7. VKN / TCKN doğrulama ve tekillik ===\n";
$v1 = t_vkn('111111111'); $v2 = t_vkn('222222222'); $t1 = t_tckn('345678901'); $t2 = t_tckn('456789012');
$k1 = kart($pdo, ['unvan' => 'Kimlikli Örnek A.Ş.', 'vergi_no' => $v1]);
ok('geçerli VKN kaydedilir', $k1['ok'] === true);
$r = kart($pdo, ['unvan' => 'VKN Bozuk', 'vergi_no' => t_bozuk($v1)]);
ok('algoritması bozuk VKN reddedilir', !$r['ok'] && isset($r['alanlar']['vergi_no']));
$r = kart($pdo, ['unvan' => 'VKN 11 Hane', 'vergi_no' => $t1]);
ok('11 haneli vergi no (TCKN) reddedilir; mesaj TCKN alanına yönlendirir', !$r['ok'] && str_contains($r['alanlar']['vergi_no'], 'TCKN'));
$r = kart($pdo, ['unvan' => 'VKN Harf', 'vergi_no' => '12345abcde']);
ok('harfli VKN reddedilir', !$r['ok']);
$r = kart($pdo, ['unvan' => 'Aynı VKN', 'vergi_no' => $v1]);
ok('aynı VKN başka kartta: ENGEL, mesaj kod + unvan', !$r['ok'] && str_contains($r['alanlar']['vergi_no'], 'Kimlikli Örnek A.Ş.') && str_contains($r['alanlar']['vergi_no'], (string)cari_kart_oku($pdo, (int)$k1['id'])['kod']));
cari_kart_durum($pdo, (int)$k1['id'], false, $UID);
$r = kart($pdo, ['unvan' => 'Aynı VKN Pasife Karşı', 'vergi_no' => $v1]);
ok('aynı VKN PASİF kartta da engeller', !$r['ok']);
ok('düzenlemede kendi VKN\'i engel değil', kart($pdo, ['unvan' => 'Kimlikli Örnek A.Ş. (düz)', 'vergi_no' => $v1, 'aktif' => '0'], (int)$k1['id'])['ok']);
$k2 = kart($pdo, ['unvan' => 'Şahıs Örnek', 'tip' => 'tedarikci', 'tckn' => $t1]);
ok('geçerli TCKN kaydedilir', $k2['ok'] === true);
$r = kart($pdo, ['unvan' => 'TCKN Bozuk', 'tckn' => t_bozuk($t1)]);
ok('algoritması bozuk TCKN reddedilir', !$r['ok'] && isset($r['alanlar']['tckn']));
ok('10 haneli / harfli TCKN reddedilir', !kart($pdo, ['unvan' => 'T10', 'tckn' => substr($t1, 0, 10)])['ok'] && !kart($pdo, ['unvan' => 'Tabc', 'tckn' => '1234567890a'])['ok']);
$r = kart($pdo, ['unvan' => 'Aynı TCKN', 'tckn' => $t1]);
ok('aynı TCKN başka kartta: ENGEL; mesaj kod + unvan, TCKN tam GÖSTERİLMEZ (maskeli)', !$r['ok'] && str_contains($r['alanlar']['tckn'], 'Şahıs Örnek') && !str_contains($r['alanlar']['tckn'], $t1) && str_contains($r['alanlar']['tckn'], cari_tckn_maske($t1)));
cari_kart_durum($pdo, (int)$k2['id'], false, $UID);
ok('aynı TCKN PASİF kartta da engeller', !kart($pdo, ['unvan' => 'Aynı TCKN 2', 'tckn' => $t1])['ok']);
ok('düzenlemede kendi TCKN\'i engel değil', kart($pdo, ['unvan' => 'Şahıs Örnek (düz)', 'tip' => 'tedarikci', 'tckn' => $t1, 'aktif' => '0'], (int)$k2['id'])['ok']);
ok('TCKN değişince farklı değer kaydedilir; boş TCKN/VKN serbest, çoklu boş kart olabilir', kart($pdo, ['unvan' => 'Şahıs Örnek (düz)', 'tip' => 'tedarikci', 'tckn' => $t2, 'aktif' => '0'], (int)$k2['id'])['ok'] && kart($pdo, ['unvan' => 'Boş Kimlik 1'])['ok'] && kart($pdo, ['unvan' => 'Boş Kimlik 2'])['ok']);
ok('VKN ve TCKN ayrı alanlar: aynı kartta birlikte olur; TCKN k2\'den ayrılınca ($t1) başka kartta kullanılabilir', kart($pdo, ['unvan' => 'Çapraz', 'vergi_no' => $v2, 'tckn' => $t1])['ok'] === true);
ok('Çapraz karttaki VKN/TCKN artık engel', !kart($pdo, ['unvan' => 'Çapraz 2', 'vergi_no' => $v2])['ok'] && !kart($pdo, ['unvan' => 'Çapraz 3', 'tckn' => $t1])['ok']);

echo "\n=== 8. Doğum tarihi yalnız müstahsilde ===\n";
$ms = kart($pdo, ['unvan' => 'Müstahsil Örnek', 'tip' => 'mustahsil', 'dogum_tarihi' => '1975-04-09', 'telefon' => '0 555 000 00 03']);
ok('müstahsil: doğum tarihi kaydedilir', $ms['ok'] && (string)cari_kart_oku($pdo, (int)$ms['id'])['dogum_tarihi'] === '1975-04-09');
$ms2 = kart($pdo, ['unvan' => 'Müstahsil Örnek', 'tip' => 'mustahsil', 'dogum_tarihi' => '09.04.1975'], (int)$ms['id']);
ok('GG.AA.YYYY biçimi kabul edilir ve ISO saklanır', $ms2['ok'] && (string)cari_kart_oku($pdo, (int)$ms['id'])['dogum_tarihi'] === '1975-04-09');
$mt = kart($pdo, ['unvan' => 'Tip Müşteri Doğumlu', 'tip' => 'musteri', 'dogum_tarihi' => '1975-04-09']);
ok('müstahsil olmayan tipte sunucu NULL yazar', $mt['ok'] && cari_kart_oku($pdo, (int)$mt['id'])['dogum_tarihi'] === null);
$mt2 = kart($pdo, ['unvan' => 'Müstahsil Örnek', 'tip' => 'musteri', 'dogum_tarihi' => '1975-04-09'], (int)$ms['id']);
ok('müstahsilden başka tipe geçince doğum tarihi temizlenir', $mt2['ok'] && cari_kart_oku($pdo, (int)$ms['id'])['dogum_tarihi'] === null);
ok('geçersiz / gelecek / çok eski tarih reddedilir', !kart($pdo, ['unvan' => 'M1', 'tip' => 'mustahsil', 'dogum_tarihi' => '2020-02-31'])['ok'] && !kart($pdo, ['unvan' => 'M2', 'tip' => 'mustahsil', 'dogum_tarihi' => date('Y-m-d', strtotime('+1 day'))])['ok'] && !kart($pdo, ['unvan' => 'M3', 'tip' => 'mustahsil', 'dogum_tarihi' => '1800-01-01'])['ok'] && !kart($pdo, ['unvan' => 'M4', 'tip' => 'mustahsil', 'dogum_tarihi' => 'abc'])['ok']);
ok('müstahsilde boş doğum tarihi serbest', kart($pdo, ['unvan' => 'M5', 'tip' => 'mustahsil'])['ok'] === true);

echo "\n=== 9. Eksik alan kuralı (TEK kural) ===\n";
$E = static fn(array $k): array => array_keys(cari_kart_eksik_alanlar($k));
ok('unvan + tip her zaman', $E(['unvan' => '', 'tip' => '']) === ['unvan', 'tip'] && $E(['unvan' => 'A', 'tip' => 'diger']) === [] && $E(['unvan' => 'A', 'tip' => 'personel']) === []);
foreach (['musteri', 'tedarikci', 'ikisi', 'nakliyeci', 'gumrukcu'] as $tp) {
    ok("$tp: kimlik + iletişim ikisi de eksik", $E(['unvan' => 'A', 'tip' => $tp]) === ['kimlik', 'iletisim']);
}
ok('müşteri: vergi no VEYA tckn yeter; telefon VEYA adres yeter', $E(['unvan' => 'A', 'tip' => 'musteri', 'vergi_no' => $v1, 'adres' => 'Örnek Mah.']) === [] && $E(['unvan' => 'A', 'tip' => 'musteri', 'tckn' => $t1, 'telefon' => '0 555']) === [] && $E(['unvan' => 'A', 'tip' => 'musteri', 'vergi_no' => $v1]) === ['iletisim'] && $E(['unvan' => 'A', 'tip' => 'musteri', 'telefon' => '0 555']) === ['kimlik']);
ok('müstahsil: yalnız telefon; TCKN / doğum tarihi eksik SAYILMAZ', $E(['unvan' => 'A', 'tip' => 'mustahsil']) === ['telefon'] && $E(['unvan' => 'A', 'tip' => 'mustahsil', 'telefon' => '0 555']) === []);
ok('cari_kart_eksikler etiket listesi', cari_kart_eksikler(['unvan' => 'A', 'tip' => 'musteri']) === ['Vergi no veya TCKN', 'Telefon veya adres']);
ok('kullanıcı tipi: yalnız unvan + tip', $E(['unvan' => 'A', 'tip' => 'komisyoncu']) === []);

echo "\n=== 10. Hızlı ekle → hizli=1 → tamamlanınca hizli=0 ===\n";
$h = cari_kart_hizli_ekle($pdo, ['unvan' => 'Hızlı Örnek Ltd.', 'tip' => 'musteri'], $UID);
$hk = $h['ok'] ? cari_kart_oku($pdo, (int)$h['id']) : [];
ok('hızlı ekle: kart açıldı, hizli=1, kod otomatik, para birimi TRY, aktif', $h['ok'] && (int)$hk['hizli'] === 1 && preg_match('/^C\d{5}$/', (string)$hk['kod']) === 1 && $hk['para_birimi'] === 'TRY' && (int)$hk['aktif'] === 1 && $h['kod'] === $hk['kod'], json_encode($h));
ok('hızlı ekle: eksikler döner (kimlik + iletişim)', ($h['eksikler'] ?? []) === ['Vergi no veya TCKN', 'Telefon veya adres'] && ($h['eksik_alanlar'] ?? []) === ['kimlik', 'iletisim']);
ok('hızlı ekle audit: create + hizli:1', say('audit_log', "module = 'cari' AND action = 'create' AND record_id = ? AND new_values LIKE '%\"hizli\":1%'", [(int)$h['id']]) === 1);
ok('hızlı ekle: unvan/tip zorunlu, geçersiz tip reddedilir', !cari_kart_hizli_ekle($pdo, ['unvan' => '', 'tip' => 'musteri'], $UID)['ok'] && !cari_kart_hizli_ekle($pdo, ['unvan' => 'Tipsiz Kart'], $UID)['ok'] && !cari_kart_hizli_ekle($pdo, ['unvan' => 'Kötü Tip', 'tip' => 'yok_boyle'], $UID)['ok']);
$v4 = t_vkn('444444444');
$h2 = cari_kart_hizli_ekle($pdo, ['unvan' => 'Hızlı Kimlikli', 'tip' => 'tedarikci', 'vergi_no' => $v4, 'telefon' => '0 555 000 00 04'], $UID);
ok('hızlı ekle: opsiyonel alanlar yazılır; kimlik+iletişim tamam olsa da hizli=1 başlar (kullanıcı onaylayana dek)', $h2['ok'] && (int)cari_kart_oku($pdo, (int)$h2['id'])['hizli'] === 1 && ($h2['eksikler'] ?? ['x']) === []);
ok('hızlı ekle: tekillik kuralı geçerli (aynı VKN reddedilir; TCKN de)', !cari_kart_hizli_ekle($pdo, ['unvan' => 'Hızlı Yinelenen', 'tip' => 'tedarikci', 'vergi_no' => $v4], $UID)['ok'] && !cari_kart_hizli_ekle($pdo, ['unvan' => 'Hızlı TC Yinelenen', 'tip' => 'tedarikci', 'tckn' => $t2], $UID)['ok']);
$g1 = kart($pdo, ['unvan' => 'Hızlı Örnek Ltd.', 'tip' => 'musteri', 'telefon' => '0 555 000 00 05'], (int)$h['id']);
ok('hâlâ eksik: kayıt yapılır, hizli=1 kalır, eksik alanlar döner + uyarı', $g1['ok'] && (int)cari_kart_oku($pdo, (int)$h['id'])['hizli'] === 1 && ($g1['eksik_alanlar'] ?? []) === ['kimlik'] && str_contains((string)$g1['uyari'], 'Vergi no veya TCKN'));
$g2 = kart($pdo, ['unvan' => 'Hızlı Örnek Ltd.', 'tip' => 'musteri', 'telefon' => '0 555 000 00 05', 'vergi_no' => t_vkn('333333333')], (int)$h['id']);
ok('eksik kalmadı: hizli=0 olur (renk normale döner), uyarı yok', $g2['ok'] && (int)cari_kart_oku($pdo, (int)$h['id'])['hizli'] === 0 && empty($g2['eksik_alanlar']));
$g3 = kart($pdo, ['unvan' => 'Hızlı Örnek Ltd.', 'tip' => 'musteri'], (int)$h['id']);
ok('normal kart sonradan eksik bırakılsa bile hizli=1 OLMAZ (bayrak yalnız hızlı eklemede)', $g3['ok'] && (int)cari_kart_oku($pdo, (int)$h['id'])['hizli'] === 0);
$nk = kart($pdo, ['unvan' => 'Normal Formdan Eksikli', 'tip' => 'musteri']);
ok('normal formdan eksikli yeni kart hizli=0', $nk['ok'] && (int)cari_kart_oku($pdo, (int)$nk['id'])['hizli'] === 0);
$pk = kart($pdo, ['unvan' => 'POST Hızlı Denemesi', 'tip' => 'musteri', 'hizli' => '1']);
ok('POST ile hizli alanı gönderilemez (yok sayılır)', $pk['ok'] && (int)cari_kart_oku($pdo, (int)$pk['id'])['hizli'] === 0);
$ms3 = cari_kart_hizli_ekle($pdo, ['unvan' => 'Hızlı Müstahsil', 'tip' => 'mustahsil'], $UID);
$g4 = kart($pdo, ['unvan' => 'Hızlı Müstahsil', 'tip' => 'mustahsil', 'telefon' => '0 555 000 00 06'], (int)$ms3['id']);
ok('müstahsil hızlı: telefon girilince tamamlanır (TCKN/doğum olmadan hizli=0)', $ms3['ok'] && ($ms3['eksik_alanlar'] ?? []) === ['telefon'] && $g4['ok'] && (int)cari_kart_oku($pdo, (int)$ms3['id'])['hizli'] === 0);
ok('liste: hizli kolonu satırda gelir', (int)(array_values(array_filter(cari_kart_listesi($pdo)['satirlar'], fn($s) => (int)$s['id'] === (int)$h2['id']))[0]['hizli'] ?? 0) === 1);

echo "\n=== 10b. Silme audit'i de maskeli (v611 kural denetimi) ===\n";
$silT = t_tckn('567890123'); $silV = t_vkn('333333333');
$sk = kart($pdo, ['unvan' => 'Silinecek Müstahsil', 'tip' => 'mustahsil', 'tckn' => $silT, 'vergi_no' => $silV, 'telefon' => '0 555 000 00 09', 'dogum_tarihi' => '1970-02-03']);
$sr = $sk['ok'] ? cari_kart_sil($pdo, (int)$sk['id'], $UID) : ['ok' => false];
$silAudit = json_encode($pdo->query("SELECT old_values FROM audit_log WHERE module = 'cari' AND action = 'delete' AND record_id = " . (int)($sk['id'] ?? 0))->fetchAll(), JSON_UNESCAPED_UNICODE);
ok('silme yapıldı ve audit satırı var', $sk['ok'] && !empty($sr['ok']) && str_contains((string)$silAudit, 'Silinecek Müstahsil'), json_encode($sr, JSON_UNESCAPED_UNICODE));
ok('silme audit\'inde TCKN / VKN / telefon / doğum tarihi değeri yok', !str_contains($silAudit, $silT) && !str_contains($silAudit, $silV) && !str_contains($silAudit, '0 555 000 00 09') && !str_contains($silAudit, '1970-02-03'), $silAudit);

echo "\n=== 11. Audit: kişisel veri DEĞER olarak yazılmaz ===\n";
$tum = json_encode($pdo->query("SELECT old_values, new_values FROM audit_log WHERE module = 'cari'")->fetchAll(), JSON_UNESCAPED_UNICODE);
ok('audit\'te TCKN değeri yok', !str_contains($tum, $t1) && !str_contains($tum, $t2));
ok('audit\'te doğum tarihi değeri yok', !str_contains($tum, '1975-04-09'));
ok('audit\'te telefon değeri yok', !str_contains($tum, '0 555 000 00 0'));
ok('audit\'te VKN değeri yok', !str_contains($tum, $v1) && !str_contains($tum, $v2));
ok('audit\'te var/yok bayrağı var', str_contains($tum, '[var]') && str_contains($tum, '[yok]'));

echo "\n=== 12. Palet fişleri müstahsil bağı (tip=tedarikci) çalışmaya devam eder ===\n";
$pf = kart($pdo, ['unvan' => 'Palet Fişi Müstahsili', 'tip' => 'tedarikci']);
ok('tip=tedarikci ile kart açılır (assets/palet-fisleri.js yolu)', $pf['ok'] === true);

siparis_test_bitir();
