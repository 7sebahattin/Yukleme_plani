<?php
// =========================================================
// scripts/kullanici_ayar_render.php — Kullanıcı Ayarları sayfalarını GERÇEK PHP ile çizer (tarayıcı testi için)
//   php scripts/kullanici_ayar_render.php <sifre|ajanda|ajanda_gun|ajanda_tamam|tercih|ajanda_kurulmamis> > _test.html
// Bellek içi SQLite + siparis harness'i; canlı DB'ye dokunmaz. Çıktıdaki göreli yollar (assets/…) kök dizinden açılınca çözülür.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/kullanici_tercih.php';
require_once $ROOT . '/config/kullanici_ayar.php';
require_once $ROOT . '/config/ajanda_core.php';
$which = $argv[1] ?? 'ajanda';
$pdo = db();
siparis_test_diger_tablolar($pdo);
$pdo->exec('DROP TABLE users');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT DEFAULT '', display_name TEXT, password_hash TEXT, is_active INT DEFAULT 1, last_login_at TEXT)");
$pdo->exec("INSERT INTO users (id, username, email, display_name, password_hash, last_login_at) VALUES (1,'admin','admin@example.test','Örnek Yönetici','x','2026-11-24 08:15:00')");
$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, label TEXT, slug TEXT)');
$pdo->exec('CREATE TABLE user_roles (user_id INT, role_id INT)');
$pdo->exec("INSERT INTO roles VALUES (1,'Yönetici','admin')");
$pdo->exec('INSERT INTO user_roles VALUES (1,1)');
$pdo->exec('CREATE TABLE kullanici_tercihleri (user_id INT NOT NULL, anahtar TEXT NOT NULL, deger TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY (user_id, anahtar))');
kt_yaz($pdo, 1, 'palet_fisleri.tablo', ['genislik' => ['belge_no' => 140], 'sira' => [], 'gizli' => []]);
if ($which !== 'ajanda_kurulmamis') {
    $GLOBALS['_ajanda_ddl_calistir'] = function (PDO $p, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
    ajanda_migrate($pdo);
    $bugun = date('Y-m-d');
    $g = fn(int $d) => date('Y-m-d', strtotime(($d >= 0 ? '+' : '') . $d . ' day'));
    foreach ([
        ['Eski beyan evraklarını kontrol et', $g(-2), '10:00', 'Eksik belge varsa çavuşa haber ver.'],
        ['Hal bildirimi gönderimlerini gözden geçir', $g(0), '09:30', ''],
        ['Haftalık ödeme listesini hazırla', $g(0), '', 'Cari bakiyelerle karşılaştır'],
        ['OGL hesabını sisteme bağla', $g(0), '14:00', "Sabit satırlar:\n- iskonto\n- vade farkı"],
        ['Kantar raporunu yazdır', $g(0), '17:30', ''],
        ['Yarınki sevkiyat için palet say', $g(1), '', ''],
        ['Ay sonu stok sayımı', $g(6), '08:00', 'Depo sorumlusu ile birlikte; sayım formunu yazdır.'],
        ['Frugro order log yükle', $g(6), '', 'Sütun sırasını kontrol et'],
        ['Hafta sonu soğuk hava kontrolü', date('Y-m-d', strtotime('first saturday of ' . date('Y-m'))), '10:30', 'Hafta sonu nöbet notu'],
        ['Çok uzun bir başlık: gümrük müşavirinden alınacak tüm evrakların son durumu', $g(11), '11:15', ''],
    ] as [$b, $t, $s2, $n]) ajanda_ekle($pdo, 1, ['baslik' => $b, 'tarih' => $t, 'saat' => $s2, 'not_metni' => $n]);
    ajanda_durum($pdo, 1, 3, true);
}
$_COOKIE['asya_session'] = 't1a';
if (!defined('AUTH_COOKIE_NAME')) define('AUTH_COOKIE_NAME', 'asya_session');
$IS_ADMIN = true;
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/x.php'; $_SERVER['PHP_SELF'] = '/x.php';
$_GET = $which === 'ajanda_tamam' ? ['gorunum' => 'tamam'] : ($which === 'ajanda_gun' ? ['gun' => date('Y-m-d')] : []);
$sayfa = ['sifre' => 'sifre_degistirme.php', 'tercih' => 'kullanici_tercihleri.php'][$which] ?? 'ajanda.php';
$_SESSION['flash'] = $which === 'sifre' ? ['type' => 'error', 'msg' => 'Mevcut şifre hatalı (4 deneme hakkı kaldı).'] : null;
// Sayfalar config/db.php + config/auth.php'yi require eder; harness bunların yerine kendi db()/current_user() saplamalarını
// taşıdığından (yeniden tanım hatası) sayfa kaynağı o iki satır ve strict_types bildirimi çıkarılarak çalıştırılır.
$src = (string)file_get_contents($ROOT . '/' . $sayfa);
$src = preg_replace("#^require_once __DIR__ \\. '/config/(db|auth)\\.php';\\s*$#m", '', $src);
$src = preg_replace('#^declare\\(strict_types=1\\);\\s*$#m', '', $src);
$src = str_replace('__DIR__', var_export($ROOT, true), $src);
ob_start();
eval('?>' . $src);
echo ob_get_clean();
