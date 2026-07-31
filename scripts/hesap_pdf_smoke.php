<?php
// =========================================================
// scripts/hesap_pdf_smoke.php — Hesap PDF raporu (Faz 5) testi
//
// SADECE CLI. Canlı veritabanına ve canlı uploads/ dizinine HİÇ dokunmaz:
// bellek içi SQLite + geçici yükleme klasörü kullanır.
//
//   php scripts/hesap_pdf_smoke.php   → çıkış kodu 0 = tüm testler geçti
//
// Kapsam: veri toplama (para birimi/personel/kategori kırılımı), HTML rapor
// bölümleri, gerçek PDF üretimi, gömülü görseller, sayfa numarası,
// Türkçe karakter kaçışı, XSS kaçırma, görsel sınırı.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);

// ── Geçici yükleme klasörü — canlı uploads/ dizinine dokunma ──
$TMPUP = sys_get_temp_dir() . '/hesap_pdf_test_' . getmypid() . '/';
@mkdir($TMPUP, 0777, true);
define('HESAP_UPLOAD_DIR', $TMPUP);
register_shutdown_function(function () use ($TMPUP) {
    foreach (glob($TMPUP . '*') ?: [] as $f) @unlink($f);
    @rmdir($TMPUP);
});

// ── Stub'lar ──
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['hesap.read','hesap.write','hesap.approve','hesap.pay'];
function current_user(): ?array { return ['id'=>1,'username'=>'test','display_name'=>'Test Personel']; }
function can(string $p): bool { global $PERMS; return in_array($p,$PERMS,true); }
function is_admin(): bool { return false; }
function depo_sql_in(string $c): array { return ['', []]; }
function depot_visible_to_user(?string $d): bool { return true; }
function audit_log_event(...$a): void {}
function active_depot(): ?string { return 'ÇİVRİL'; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'x'; }

require_once $ROOT . '/config/hesap_calc.php';
require_once $ROOT . '/hesap_config.php';
require_once $ROOT . '/config/hesap_pdf.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

// ── Şema + veri ──
db()->exec("CREATE TABLE account_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, created_by INT,
  transaction_date TEXT, transaction_time TEXT DEFAULT '09:00', type TEXT,
  category TEXT DEFAULT '', amount REAL, currency TEXT DEFAULT 'TRY',
  payment_method TEXT DEFAULT 'nakit', person_company TEXT DEFAULT '',
  description TEXT DEFAULT '', document_no TEXT DEFAULT '', has_invoice INT DEFAULT 0,
  is_for_company INT DEFAULT 1, is_given_to_accountant INT DEFAULT 0, notes TEXT DEFAULT '',
  has_files INT DEFAULT 0, status TEXT DEFAULT 'submitted', submitted_at TEXT,
  reviewed_by INT, reviewed_at TEXT, review_note TEXT DEFAULT '', paid_at TEXT,
  depo TEXT DEFAULT '', created_at TEXT, updated_at TEXT)");
db()->exec("CREATE TABLE account_files (id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id INT, file_name TEXT, original_name TEXT DEFAULT '',
  file_type TEXT DEFAULT '', file_size INT DEFAULT 0, uploaded_at TEXT)");
db()->exec("CREATE TABLE users (id INT, username TEXT, display_name TEXT)");
db()->exec("INSERT INTO users VALUES (1,'test','Test Personel'),(2,'ikinci','İkinci Personel')");

$ins = db()->prepare("INSERT INTO account_transactions
 (user_id,transaction_date,type,category,amount,currency,status,person_company,document_no,has_files)
 VALUES (?,?,?,?,?,?,?,?,?,?)");
$ins->execute([1,'2026-07-02','gider','Yemek gideri',   480,'TRY','approved','Lokanta','F-1001',1]);
$ins->execute([1,'2026-07-03','gider','Yakıt',          350,'TRY','approved','Petrol Ofisi','',1]);
$ins->execute([1,'2026-07-04','gelir','Şirketten gelen ödeme',3975,'TRY','paid','','',0]);
$ins->execute([1,'2026-07-05','gider','Market / Temizlik',221,'TRY','rejected','','',0]);
$ins->execute([2,'2026-07-06','gider','Otel / Konaklama',1500,'TRY','approved','Otel Şç&<>','',0]);
$ins->execute([1,'2026-07-07','gider','Kargo',           100,'USD','approved','','',1]);
// XSS denemesi — rapora kaçırılmış çıkmalı
$ins->execute([1,'2026-07-08','gider','<script>alert(1)</script>',50,'TRY','approved','"><img src=x>','',0]);

// ── Gerçek test görselleri ──
function testJpeg(string $path, string $etiket): void {
    $im = imagecreatetruecolor(600, 800);
    imagefill($im, 0, 0, imagecolorallocate($im, 250, 250, 245));
    imagestring($im, 5, 20, 20, $etiket, imagecolorallocate($im, 20, 20, 20));
    imagejpeg($im, $path, 85);
    imagedestroy($im);
}
$fi = db()->prepare("INSERT INTO account_files (transaction_id,file_name,original_name) VALUES (?,?,?)");
foreach ([1=>'fis_yemek', 2=>'fis_yakit', 6=>'fis_kargo'] as $tid => $ad) {
    $fn = str_pad((string)$tid, 32, 'a', STR_PAD_LEFT) . '.jpg';
    testJpeg(HESAP_UPLOAD_DIR . $fn, $ad);
    $fi->execute([$tid, $fn, $ad . '.jpg']);
}

// ── Yardımcılar ──
$fail = 0;
function ok(string $ad, bool $c, string $ipucu = '') {
    global $fail;
    if (!$c) $fail++;
    printf("%-56s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}
/**
 * PDF içeriğindeki metni çıkarır.
 * dompdf dizeleri UTF-16BE yazar; null baytları ATMAK yerine düzgün çözmek gerekir
 * (atmak ö/ş/₺ gibi karakterleri bozar ve testi yanlış yere düşürür).
 */
function pdfText(string $pdf): string {
    $out = '';
    if (!preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $m)) return '';
    foreach ($m[1] as $s) {
        $d = @gzuncompress($s);
        if ($d === false) continue;
        if (!preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $d, $t)) continue;
        foreach ($t[1] as $piece) {
            // PDF dize kaçışlarını çöz
            $raw = preg_replace_callback('/\\\\(n|r|t|b|f|\(|\)|\\\\|[0-7]{1,3})/', function ($mm) {
                return match ($mm[1]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\b", 'f' => "\f",
                    '(' => '(', ')' => ')', '\\' => '\\',
                    default => chr(octdec($mm[1])),
                };
            }, $piece);
            $out .= @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') . ' ';
        }
    }
    return $out;
}

// ═══════════ Veri toplama ═══════════
echo "── Rapor verisi ──\n";
$d = hesap_report_data(['tarih_bas'=>'2026-07-01','tarih_son'=>'2026-07-31']);

ok('tüm kayıtlar toplandı (7)',            count($d['rows']) === 7);
ok('TRY ve USD ayrı toplandı',             isset($d['toplamlar']['TRY'], $d['toplamlar']['USD']));
ok('TRY gelir 3.975',                      abs($d['toplamlar']['TRY']['gelir'] - 3975) < 0.01);
ok('TRY gider 2.601',                      abs($d['toplamlar']['TRY']['gider'] - 2601) < 0.01,
                                           'beklenen 480+350+221+1500+50');
ok('USD TRY ile karışmadı (100)',          abs($d['toplamlar']['USD']['gider'] - 100) < 0.01);
ok('TRY önce sıralandı',                   array_key_first($d['toplamlar']) === 'TRY');
ok('personel kırılımı 2 kişi',             count($d['personel']) === 2);
ok('reddedilen personel özetine girmedi',  abs($d['personel']['Test Personel']['gider'] - 880) < 0.01,
                                           'beklenen 480+350+50, red 221 hariç');
ok('kategori kırılımı yüzdeleri toplam 100', abs(array_sum(array_column($d['kategoriler'],'yuzde')) - 100) < 0.5);

// ── Bakiye durumu: devir + dönem = kapanış ──
ok('bakiye bölümü üretildi',               isset($d['bakiye']['TRY']));
ok('devir dönem öncesini kapsıyor (0)',    abs($d['bakiye']['TRY']['devir']) < 0.01, 'temmuz öncesi kayıt yok');
ok('dönem hareketi onaylı net (+1.595)',   abs($d['bakiye']['TRY']['donem'] - 1595) < 0.01,
                                           '3975 gelir - 2380 onaylı gider (iki personel)');
ok('kapanış = devir + dönem',              abs($d['bakiye']['TRY']['kapanis']
                                               - ($d['bakiye']['TRY']['devir'] + $d['bakiye']['TRY']['donem'])) < 0.01);
ok('personel devir/kapanış hesaplandı',    isset($d['personel']['Test Personel']['kapanis']));
ok('USD bakiyesi ayrı tutuldu',            isset($d['bakiye']['USD']) && abs($d['bakiye']['USD']['kapanis'] + 100) < 0.01);
ok('en büyük kategori Otel (1500)',        array_key_first($d['kategoriler']) === 'Otel / Konaklama');
ok('fiş görselleri toplandı (3)',          count($d['fisler']) === 3);
ok('görsel sınırı aşılmadı',               $d['kirpildi'] === 0);

// ═══════════ HTML ═══════════
echo "\n── Rapor HTML ──\n";
$html = hesap_report_html($d, true);
ok('şirket logosu gömüldü',                str_contains($html, 'data:image/jpeg;base64'));
ok('dönem hareketleri bölümü',             str_contains($html, 'Dönem Hareketleri'));
ok('bakiye durumu bölümü',                 str_contains($html, 'Bakiye Durumu'));
ok('devir sütunu başlığı',                 str_contains($html, 'Devir'));
ok('dönem sonu bakiye sütunu',             str_contains($html, 'Dönem Sonu Bakiye'));
ok('personel özeti bölümü',                str_contains($html, 'Personel Özeti'));
ok('kategori kırılımı bölümü',             str_contains($html, 'Kategori Kırılımı'));
ok('işlem listesi bölümü',                 str_contains($html, 'İşlem Listesi'));
ok('fiş görselleri bölümü',                str_contains($html, 'Fiş Görselleri'));
ok('imza alanları',                        str_contains($html, 'Muhasebe Onayı'));
ok('durum rozetleri renkli',               str_contains($html, 'Muhasebe Onayladı') && str_contains($html,'Reddedildi'));
ok('kapsam satırı tarih içeriyor',         str_contains($html, '01.07.2026') && str_contains($html, '31.07.2026'));
ok('depo kapsamı yazıldı',                 str_contains($html, 'ÇİVRİL'));
ok('hazırlayan yazıldı',                   str_contains($html, 'Test Personel'));
ok('çoklu kur uyarısı',                    str_contains($html, 'Para birimleri ayrı toplanır'));
ok('3×3 galeri tablosu',                   str_contains($html, 'class="gal"'));
ok('XSS kaçırıldı — ham script yok',       !str_contains($html, '<script>alert(1)</script>'), 'kaçırılmamış kullanıcı verisi');
ok('XSS kaçırılmış hâli var',              str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
ok('img src=x kaçırıldı',                  !str_contains($html, '"><img src=x>'));

// ═══════════ PDF ═══════════
echo "\n── PDF üretimi ──\n";
if (!class_exists(\Dompdf\Dompdf::class)) {
    ok('dompdf yüklü', false, 'vendor/ eksik — composer install gerekli');
} else {
    $t0 = microtime(true);
    $pdf = hesap_report_pdf($d);
    $sure = microtime(true) - $t0;

    ok('PDF üretildi',                     is_string($pdf) && $pdf !== '');
    ok('geçerli PDF başlığı',              str_starts_with((string)$pdf, '%PDF-'));
    ok('PDF sonlandırıcı %%EOF',           str_contains((string)$pdf, '%%EOF'));
    ok('makul boyut (>40 KB)',             strlen((string)$pdf) > 40000, strlen((string)$pdf) . ' bayt');
    ok('fiş görselleri gömüldü (JPEG)',    substr_count((string)$pdf, 'DCTDecode') >= 3,
                                           substr_count((string)$pdf,'DCTDecode') . ' JPEG bulundu');
    ok('font gömüldü (FontFile)',          str_contains((string)$pdf, 'FontFile'));
    ok('üretim süresi < 30 sn',            $sure < 30, round($sure, 1) . ' sn');

    $txt = pdfText((string)$pdf);
    ok('metin çıkarılabildi',              strlen($txt) > 200);
    ok('başlık PDF metninde',              str_contains($txt, 'Asya Fresh'));
    ok('Türkçe karakterler bozulmadı',     str_contains($txt, 'Dönem') || str_contains($txt, 'Kırılımı'),
                                           'Türkçe glif kaybı olabilir');
    ok('sayfa numarası basıldı',           (bool)preg_match('/Sayfa\s*\d+\s*\/\s*\d+/u', $txt), 'page_text çalışmadı');
    ok('toplam sayfa sıfır değil',         !preg_match('#Sayfa\s*/?\s*\d+\s*/\s*0#u', $txt), 'counter(pages) hatası geri geldi');
    ok('₺ simgesi korundu',                str_contains($txt, '₺'));
    ok('personel adı Türkçe doğru',        str_contains($txt, 'Test Personel'));
    // CSS text-transform:uppercase Türkçe i/İ eşlemesini bozar — kullanılmamalı
    ok('Türkçe büyük harf bozulmamış',     !str_contains($txt, 'GELIR') && !str_contains($txt, 'ŞIRKETE'),
                                           'text-transform:uppercase geri gelmiş olabilir');
    ok('etiketler doğru yazımda',          str_contains($txt, 'Toplam Gelir'));

    $dosya = hesap_report_filename($d['meta']);
    ok('dosya adı tarihli',                $dosya === 'hesap_raporu_20260701_20260731.pdf', $dosya);
}

// ═══════════ Uç durumlar ═══════════
echo "\n── Uç durumlar ──\n";
$bos = hesap_report_data(['tarih_bas'=>'2020-01-01','tarih_son'=>'2020-01-31']);
ok('boş dönem çökmüyor',                   $bos['meta']['adet'] === 0);
$bos_html = hesap_report_html($bos, true);
ok('boş dönem HTML üretiyor',              str_contains($bos_html, 'Bu dönemde kayıt bulunmuyor'));

// ── Hareketi olmayan ama devri olan ay (kullanıcının bildirdiği durum) ──
// Ağustos'ta hiç kayıt yok ama Temmuz'dan devreden bakiye var: rapor sıfır
// göstermemeli, devri ve kapanış bakiyesini taşımalı.
$agustos = hesap_report_data(['tarih_bas'=>'2026-08-01','tarih_son'=>'2026-08-31']);
ok('boş ayda kayıt yok',                   $agustos['meta']['adet'] === 0);
ok('boş ayda DEVİR taşınıyor',             abs($agustos['bakiye']['TRY']['devir'] - 1595) < 0.01,
                                           'devir kayboldu — asıl şikâyet buydu');
ok('boş ayda dönem hareketi sıfır',        abs($agustos['bakiye']['TRY']['donem']) < 0.01);
ok('boş ayda KAPANIŞ devirle aynı',        abs($agustos['bakiye']['TRY']['kapanis'] - 1595) < 0.01);
$ag_html = hesap_report_html($agustos, true);
ok('boş ay raporunda bakiye bölümü var',   str_contains($ag_html, 'Bakiye Durumu'));
ok('boş ay raporunda devir tutarı yazılı', str_contains($ag_html, '1.595,00'));
ok('personel devirleri ayrı ayrı yazılı',  str_contains($ag_html, '3.095,00') && str_contains($ag_html, '1.500,00'),
                                           'kişi bazlı borç okunamıyor');
ok('boş ay raporu yön etiketi veriyor',    str_contains($ag_html, 'Şirkete borçlusunuz')
                                           || str_contains($ag_html, 'Şirket size borçlu'));
ok('devri olan personel raporda görünüyor', str_contains($ag_html, 'Test Personel'),
                                            'dönemde hareketi yok ama devri var');
if (class_exists(\Dompdf\Dompdf::class)) {
    $bos_pdf = hesap_report_pdf($bos);
    ok('boş dönem PDF üretiyor',           is_string($bos_pdf) && str_starts_with($bos_pdf, '%PDF-'));
}

$filtreli = hesap_report_data(['tarih_bas'=>'2026-07-01','tarih_son'=>'2026-07-31','durum'=>'rejected']);
ok('durum filtresi çalışıyor',             count($filtreli['rows']) === 1);
$tur = hesap_report_data(['tarih_bas'=>'2026-07-01','tarih_son'=>'2026-07-31','type'=>'gelir']);
ok('tür filtresi çalışıyor',               count($tur['rows']) === 1);

// Görünürlük: düz personel başkasının kaydını raporlayamaz
$PERMS = ['hesap.read','hesap.write'];
$kisitli = hesap_report_data(['tarih_bas'=>'2026-07-01','tarih_son'=>'2026-07-31']);
ok('düz personel yalnız kendi kayıtları',  count($kisitli['rows']) === 6, 'başka personelin kaydı sızdı');
ok('başka personel özetten çıktı',         !isset($kisitli['personel']['İkinci Personel']));

// Bozuk görsel dosyası raporu düşürmemeli
$PERMS = ['hesap.read','hesap.write','hesap.approve'];
file_put_contents(HESAP_UPLOAD_DIR . str_pad('9', 32, 'b', STR_PAD_LEFT) . '.jpg', 'bu bir jpeg degil');
db()->prepare("INSERT INTO account_files (transaction_id,file_name,original_name) VALUES (?,?,?)")
   ->execute([4, str_pad('9', 32, 'b', STR_PAD_LEFT) . '.jpg', 'bozuk.jpg']);
$bozuk = hesap_report_data(['tarih_bas'=>'2026-07-01','tarih_son'=>'2026-07-31']);
$bozuk_html = hesap_report_html($bozuk, true);
ok('bozuk görsel raporu düşürmüyor',       str_contains($bozuk_html, 'görsel okunamadı'));
ok('bozuk görsel için null döner',         hesap_pdf_image_uri(HESAP_UPLOAD_DIR . str_pad('9',32,'b',STR_PAD_LEFT) . '.jpg') === null);
ok('olmayan dosya için null döner',        hesap_pdf_image_uri(HESAP_UPLOAD_DIR . 'yok.jpg') === null);

// ═══════════ Uç nokta: hesap_yazdir.php ═══════════
// Sayfanın kendisi ayrı bir süreçte çalıştırılır (exit + binary çıktı verdiği için).
echo "\n── hesap_yazdir.php uç noktası ──\n";
$boot = sys_get_temp_dir() . '/hesap_yazdir_boot_' . getmypid() . '.php';
$page = sys_get_temp_dir() . '/hesap_yazdir_page_' . getmypid() . '.php';
register_shutdown_function(function () use ($boot, $page) { @unlink($boot); @unlink($page); });

// Sayfadan require/guard satırlarını sıyır
$src = file_get_contents($ROOT . '/hesap_yazdir.php');
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|hesap_config|config\/auth|config\/hesap_pdf)\.php\';\s*$/m', '', $src);
$src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '', $src);
$src = preg_replace('/^\s*require_hesap\([^)]*\);\s*$/m', '', $src);
$src = preg_replace('/^\s*hesap_migrate\(\);\s*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
file_put_contents($page, "<?php\n" . $src);

// SQLite bellek içi olduğundan alt sürecin okuyabilmesi için diske kopyalanır
$dbfile = sys_get_temp_dir() . '/hesap_yazdir_db_' . getmypid() . '.sqlite';
@unlink($dbfile);
register_shutdown_function(fn() => @unlink($dbfile));
$disk = new PDO('sqlite:' . $dbfile);
$disk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (['account_transactions', 'account_files', 'users'] as $tbl) {
    $ddl = db()->query("SELECT sql FROM sqlite_master WHERE name='$tbl'")->fetchColumn();
    $disk->exec((string)$ddl);
    foreach (db()->query("SELECT * FROM $tbl")->fetchAll() as $r) {
        $cols = implode(',', array_map(fn($c) => '"' . $c . '"', array_keys($r)));
        $ph   = implode(',', array_fill(0, count($r), '?'));
        $disk->prepare("INSERT INTO $tbl ($cols) VALUES ($ph)")->execute(array_values($r));
    }
}
$disk = null;

// Bootstrap: bu test dosyasının stub'larını yeniden kurar, sonra sayfayı çalıştırır
file_put_contents($boot, '<?php
$ROOT = ' . var_export($ROOT, true) . ';
define("HESAP_UPLOAD_DIR", ' . var_export(HESAP_UPLOAD_DIR, true) . ');
$PDO_TEST = new PDO("sqlite:" . ' . var_export($dbfile, true) . ');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
$PERMS = ["hesap.read","hesap.write","hesap.approve"];
function current_user(): ?array { return ["id"=>1,"username"=>"test","display_name"=>"Test Personel"]; }
function can(string $p): bool { global $PERMS; return in_array($p,$PERMS,true); }
function is_admin(): bool { return false; }
function depo_sql_in(string $c): array { return ["", []]; }
function depot_visible_to_user(?string $d): bool { return true; }
function audit_log_event(...$a): void {}
function active_depot(): ?string { return "ÇİVRİL"; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
function csrf_token(): string { return "x"; }
require_once $ROOT . "/config/hesap_calc.php";
require_once $ROOT . "/hesap_config.php";
require_once $ROOT . "/config/hesap_pdf.php";
require_once $ROOT . "/vendor/autoload.php";
$_GET = json_decode($argv[1] ?? "{}", true) ?: [];
$_SERVER["REQUEST_METHOD"] = "GET";
include ' . var_export($page, true) . ';
');

$args = escapeshellarg(json_encode(['tarih_bas'=>'2026-07-01','tarih_son'=>'2026-07-31']));
$out  = shell_exec('php ' . escapeshellarg($boot) . ' ' . $args . ' 2>&1');
ok('uç nokta PDF döndürüyor',              is_string($out) && str_starts_with((string)$out, '%PDF-'),
                                           substr((string)$out, 0, 120));
ok('uç nokta çıktısı tam',                 str_contains((string)$out, '%%EOF'));

$args_html = escapeshellarg(json_encode(['tarih_bas'=>'2026-07-01','tarih_son'=>'2026-07-31','goruntule'=>'html']));
$out_html  = shell_exec('php ' . escapeshellarg($boot) . ' ' . $args_html . ' 2>&1');
ok('?goruntule=html HTML döndürüyor',      str_contains((string)$out_html, '<!doctype html>')
                                           && str_contains((string)$out_html, 'Bakiye Durumu'));
ok('HTML görünümünde PHP uyarısı yok',     !preg_match('/(Warning|Fatal error|Notice)/i', (string)$out_html));

echo $fail === 0 ? "\n>>> TÜM PDF TESTLERİ GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
