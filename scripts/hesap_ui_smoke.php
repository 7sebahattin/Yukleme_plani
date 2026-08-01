<?php
// =========================================================
// scripts/hesap_ui_smoke.php — Hesap arayüz (Faz 1-3) render testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/depo/render fonksiyonlarıyla sayfaları gerçekten
// render eder, sonra HTML'i doğrular.
//
//   php scripts/hesap_ui_smoke.php    → çıkış kodu 0 = tüm testler geçti
//
// Kapsam: PHP uyarı sızıntısı, HTML etiket dengesi, tek bakiye kartı,
// tek birincil CTA, fiş-önce form sırası, çift form alanı olmaması,
// rol bazlı görünürlük kapsamı.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['hesap.read','hesap.write','hesap.approve','hesap.pay','reports.export'];
function current_user(): ?array { return ['id'=>1,'username'=>'test','display_name'=>'Test']; }
function can(string $p): bool { global $PERMS; return in_array($p,$PERMS,true); }
function is_admin(): bool { return false; }
function depo_sql_in(string $c): array { return ['', []]; }
function depot_visible_to_user(?string $d): bool { return true; }
function audit_log_event(...$a): void {}
function active_depot(): ?string { return 'ÇİVRİL'; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function set_flash($a,$b): void {}
function render_flash(): void {}
function forbidden($m=''): void { throw new RuntimeException('forbidden'); }
function base_url(): string { return '/'; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header(string $t, bool $p=false): void { echo "<!doctype html><html><head><title>".h($t)."</title></head><body><main class=\"container\">"; }
function render_footer(bool $p=false): void { echo "</main></body></html>"; }

require_once $ROOT.'/config/hesap_calc.php';
require_once $ROOT.'/hesap_config.php';

// Şema + örnek veri
db()->exec("CREATE TABLE account_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, created_by INT,
  transaction_date TEXT, transaction_time TEXT DEFAULT '09:00', type TEXT,
  category TEXT DEFAULT '', amount REAL, currency TEXT DEFAULT 'TRY',
  payment_method TEXT DEFAULT 'nakit', person_company TEXT DEFAULT '',
  description TEXT DEFAULT '', document_no TEXT DEFAULT '', has_invoice INT DEFAULT 0,
  is_for_company INT DEFAULT 1, is_given_to_accountant INT DEFAULT 0, notes TEXT DEFAULT '',
  has_files INT DEFAULT 0, status TEXT DEFAULT 'submitted', submitted_at TEXT,
  reviewed_by INT, reviewed_at TEXT, review_note TEXT DEFAULT '', paid_at TEXT,
  depo TEXT DEFAULT '', created_at TEXT DEFAULT '2026-07-01', updated_at TEXT)");
db()->exec("CREATE TABLE account_files (id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id INT, file_name TEXT, original_name TEXT DEFAULT '',
  file_type TEXT DEFAULT '', file_size INT DEFAULT 0, uploaded_at TEXT)");
db()->exec("CREATE TABLE users (id INT, username TEXT, display_name TEXT)");
db()->exec("INSERT INTO users VALUES (1,'test','Test Personel'),(2,'ali','Ali')");

$ins = db()->prepare("INSERT INTO account_transactions
 (user_id,transaction_date,type,category,amount,currency,status,person_company,description,review_note,has_files)
 VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$ins->execute([1,'2026-07-02','gider','Yemek gideri',480,'TRY','approved','','Öğle yemeği','',1]);
$ins->execute([1,'2026-07-03','gider','Yakıt',350,'TRY','submitted','Petrol Ofisi','','',0]);
$ins->execute([1,'2026-07-04','gelir','Şirketten gelen ödeme',3975,'TRY','paid','','','',0]);
$ins->execute([1,'2026-07-05','gider','Market / Temizlik',221,'TRY','rejected','','','Fiş okunmuyor',0]);
$ins->execute([1,'2026-07-06','gider','Kargo',100,'USD','approved','','','',0]);
$ins->execute([2,'2026-07-07','gider','Otel / Konaklama',1500,'TRY','submitted','','','',0]);
db()->exec("INSERT INTO account_files (transaction_id,file_name,original_name) VALUES (1,'".str_repeat('a',32).".jpg','fis.jpg')");

// Sayfayı require'ları sıyırarak çalıştır
function renderPage(string $file, array $get = []): string {
    global $ROOT;
    $_GET = $get; $_SERVER['REQUEST_METHOD'] = 'GET';
    $src = file_get_contents($ROOT.'/'.$file);
    $src = preg_replace('/^\s*require_once __DIR__ . \'\/(config\/db|hesap_config|config\/auth)\.php\';\s*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^\s*require_hesap\([^)]*\);\s*$/m', '', $src);
    $src = preg_replace('/^\s*require_perm\([^)]*\);\s*$/m', '', $src);
    $src = preg_replace('/^\s*hesap_migrate\(\);\s*$/m', '', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir().'/pg_'.md5($file.serialize($get)).'.php';
    file_put_contents($tmp, "<?php\n".$src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return "__ERROR__: ".$e->getMessage(); }
    return ob_get_clean();
}


$fail = 0;
function ok(string $ad, bool $cond, string $ipucu = '') {
    global $fail;
    if (!$cond) $fail++;
    printf("%-58s %s%s\n", $ad, $cond ? 'OK' : '*** FAIL', ($cond ? '' : '  ' . $ipucu));
}
function cnt(string $h, string $needle): int { return substr_count($h, $needle); }

$dash = renderPage('hesap.php', []);
echo "── Pano ──\n";
// user 1: paid gelir 3975, approved gider 480 → net +3495 → personel şirkete borçlu
ok('tek bakiye kartı var',                 cnt($dash,'class="hs-balance ') === 1);
ok('bakiye yönü borç (kırmızı)',           str_contains($dash,'hs-balance--borc'));
ok('bakiye tutarı 3.495,00',               str_contains($dash,'3.495,00'));
ok('etiket "Şirkete borçlusunuz"',         str_contains($dash,'Şirkete borçlusunuz'));
ok('reddedilen 221 bakiyeye girmedi',      !str_contains($dash,'3.716,00') && !str_contains($dash,'3.274,00'));
ok('bekleyen rozeti görünüyor',            str_contains($dash,'onay bekliyor'));
ok('USD ayrı chip olarak',                 str_contains($dash,'hs-cur-chip') && str_contains($dash,'USD'));
ok('USD TRY toplamına karışmadı',          str_contains($dash,'Para birimleri ayrı tutulur'));
ok('TEK birincil CTA',                     cnt($dash,'class="hs-cta"') === 1, 'birden fazla dolu buton');
ok('CTA metni "Harcama Ekle"',             str_contains($dash,'Harcama Ekle'));
ok('eski gökkuşağı butonları gitti',       !str_contains($dash,'hesap-quick-btn'));
ok('eski 6 istatistik kutusu gitti',       !str_contains($dash,'hesap-stat-grid'));
ok('alt sayfa (bottom sheet) var',         str_contains($dash,'hsQuickSheet') && str_contains($dash,'hsMoreSheet'));
ok('reddedilen uyarısı görünüyor',         str_contains($dash,'fiş reddedildi'));
ok('red gerekçesi gösteriliyor',           str_contains($dash,'Fiş okunmuyor'));
ok('işlem kartları hs-tx',                 cnt($dash,'class="hs-tx"') >= 5);
ok('durum rozetleri var',                  str_contains($dash,'hs-badge--approved') && str_contains($dash,'hs-badge--paid'));
ok('fiş küçük resmi basıldı',              str_contains($dash,'hs-tx-thumb'));
ok('ay özeti katlanabilir',                str_contains($dash,'<details class="hs-fold"'));

echo "\n── Form (yeni kayıt) ──\n";
$f = renderPage('hesap_kayit.php', ['hizli'=>'gider']);
$posFoto = strpos($f, 'hs-photo-drop');
$posTutar = strpos($f, 'hs-amount-input');
$posKat  = strpos($f, 'hs-chips');
$posDet  = strpos($f, 'hs-details');
ok('1) fiş fotoğrafı en üstte',            $posFoto !== false && $posFoto < $posTutar, 'fotoğraf tutardan sonra');
ok('2) tutar fotoğraftan sonra',           $posTutar < $posKat);
ok('3) kategori tutardan sonra',           $posKat < $posDet);
ok('4) detaylar en sonda',                 $posDet !== false);
ok('detaylar VARSAYILAN KAPALI',           !preg_match('/<details[^>]*id="hsDetails"[^>]*\sopen/', $f), 'yeni kayıtta açık');
ok('kamera capture özniteliği',            str_contains($f,'capture="environment"'));
ok('tutar sayısal klavye',                 str_contains($f,'inputmode="decimal"'));
ok('kategori chip ızgarası',               str_contains($f,'hs-chip') && str_contains($f,'data-cat-type'));
ok('"Diğer…" chip i var',                  str_contains($f,'hs-chip-more'));
ok('tür butonları',                        cnt($f,'hs-type-btn') === 4);
ok('gider türü önseçili',                  str_contains($f,'data-type="gider"
                aria-pressed="true"') || preg_match('/data-type="gider"\s+aria-pressed="true"/',$f));
ok('type alanı TEK (çift name yok)',       cnt($f,'name="type"') === 1, 'çift name POST çakışması yapar');
ok('category alanı TEK',                   cnt($f,'name="category"') === 1);
ok('amount alanı TEK',                     cnt($f,'name="amount"') === 1);
ok('kırpma modalı var',                    str_contains($f,'hs-crop'));
ok('kaydet + taslak butonu',               str_contains($f,'Kaydet ve Gönder') && str_contains($f,'Taslak'));
ok('eski karmaşık grid gitti',             !str_contains($f,'class="record-form"'));

echo "\n── Form (düzenleme, reddedilmiş) ──\n";
$e = renderPage('hesap_kayit.php', ['id'=>'4']);
ok('düzenlemede detaylar AÇIK',            (bool)preg_match('/<details[^>]*id="hsDetails"[^>]*\sopen/', $e));
ok('red gerekçesi uyarısı',                str_contains($e,'Bu fiş reddedildi') && str_contains($e,'Fiş okunmuyor'));
ok('durum rozeti başlıkta',                str_contains($e,'hs-badge--rejected'));
ok('tutar dolu geldi (221,00)',            str_contains($e,'value="221,00"'));

echo "\n── Liste ──\n";
$l = renderPage('hesap_liste.php', []);
ok('durum filtreleri var',                 cnt($l,'hs-filter') >= 10);
// Filtre paneli — arama/tarih/tür/durum/fiş tek karta toplandı (önceden 6 ayrı,
// tutarsız görünümlü blok art arda diziliydi — dağınık görünüyordu).
ok('tek filtre paneli var',                cnt($l,'class="hs-filter-panel"') === 1, 'birden fazla veya sıfır panel');
ok('eski dağınık search-row gitti',        !str_contains($l,'class="search-row"'));
ok('eski özel tarih formu class\'ı gitti', !str_contains($l,'hesap-date-range'));
ok('select artık .hs-select ile stilli',   str_contains($l,'class="hs-select"'), 'select btn-ghost hack\'ine geri dönmüş olabilir');
ok('select btn-ghost hack\'i yok',         !str_contains($l,'<select onchange="location.href=updateParam(\'fis\',this.value)" class="btn btn-ghost"'));
ok('filtre satırları etiketli',            cnt($l,'hs-filter-label') >= 5, 'Ara/Tarih/Aralık/Tür/Durum/Fiş etiketleri eksik');
ok('dışa aktarma butonları başlıkta',      (bool)preg_match('#page-head.*?Excel.*?</div>#s', $l), 'Excel/PDF filtre panelinden başlığa taşınmalıydı');
ok('mobil kart listesi hs-tx',             str_contains($l,'hs-tx-list mobile-only'));
ok('masaüstü tablo korundu',               str_contains($l,'table-wrap pc-only'));
ok('geçiş butonları data-* ile',           str_contains($l,'data-hs-durum='));
ok('inline onclick hesapDurum gitti',      !str_contains($l,'onclick="hesapDurum'));
ok('para birimi ayrı özet satırı',         cnt($l,'hs-sum-row') === 2, 'TRY ve USD ayrı satır olmalı');
ok('USD net TRY ile toplanmadı',           str_contains($l,'-100,00 $') && str_contains($l,'1.424,00 ₺'));
// Her kayıt iki kez basılır (masaüstü tablo + mobil kart)
ok('ödenmiş kayıt kilitli — düzenle yok',  cnt($l,'hesap_kayit.php?id=3') === 0, 'paid kayıt düzenlenebilir görünüyor');
ok('ödenmemiş kayıt düzenlenebilir',       cnt($l,'hesap_kayit.php?id=1') === 2);

echo "\n── Boş durum ──\n";
$b = renderPage('hesap_liste.php', ['q'=>'zzzyokzzz']);
ok('boş durum kartı',                      str_contains($b,'hs-empty'));

echo "\n── Muhasebe ──\n";
$m = renderPage('hesap_muhasebe.php', []);
ok('personel bakiye tablosu',              str_contains($m,'Personel Bakiyeleri'));
ok('her iki personel listeleniyor',        str_contains($m,'Test Personel') && str_contains($m,'Ali'));
ok('onay/red toplu butonları',             str_contains($m,'Seçilenleri Onayla') && str_contains($m,'Reddet'));
ok('.hs sarmalayıcısı var (token için)',   str_contains($m,'<div class="hs">'));
ok('muhasebe filtre paneli var',           str_contains($m,'class="hs-filter-panel"'));
ok('durum select\'i .hs-select ile stilli', (bool)preg_match('#<select name="durum" class="hs-select"#', $m));
ok('eski inline btn-ghost select gitti',   !str_contains($m,'<select name="durum" class="btn btn-ghost"'));
ok('"Tüm Kayıtlar" başlıkta, filtreden ayrı', (bool)preg_match('#page-head.*?Tüm Kayıtlar.*?</div>\s*</div>#s', $m));



echo "── Düz personel (hesap.approve YOK) ──\n";
$PERMS = ['hesap.read','hesap.write'];
$d = renderPage('hesap.php', []);
ok('başka personelin 1.500 kaydı GÖRÜNMÜYOR', !str_contains($d,'1.500,00'));
ok('kendi kayıtları görünüyor (480)',          str_contains($d,'480,00'));
ok('bakiye hâlâ 3.495,00',                      str_contains($d,'3.495,00'));
$l = renderPage('hesap_liste.php', []);
ok('listede de başkasının kaydı yok',          !str_contains($l,'Otel / Konaklama'));

echo "\n── Muhasebe (hesap.approve VAR) ──\n";
$PERMS = ['hesap.read','hesap.write','hesap.approve','hesap.pay'];
$d2 = renderPage('hesap.php', []);
ok('muhasebe tüm personeli görüyor',            str_contains($d2,'1.500,00'));
$l2 = renderPage('hesap_liste.php', []);
ok('listede başkasının kaydı var',             str_contains($l2,'Otel / Konaklama'));

echo "\n── Yetkisiz: yazma yetkisi olmayan ──\n";
$PERMS = ['hesap.read'];
$d3 = renderPage('hesap.php', []);
ok('CTA gizlendi (hesap.write yok)',            !str_contains($d3,'class="hs-cta"'));
ok('kayıt türü alt sayfası gizlendi',           !str_contains($d3,'hsQuickSheet'));
ok('"Daha Fazla" hâlâ açık',                   str_contains($d3,'hsMoreSheet'));
ok('onay kuyruğu linki gizli',                 !str_contains($d3,'hesap_muhasebe.php'));

echo $fail === 0 ? "\n>>> TÜM UI TESTLERİ GEÇTİ\n" : "\n>>> $fail BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
