<?php
// =========================================================
// scripts/siparis_gate_static_smoke.php — Siparişler (v371) STATİK değişmezleri
// Kaynak koda bakar (sayfa çalıştırmaz): kapı tek kaynak, her uç oturum + kapı +
// (POST'ta) CSRF ister, migrate yalnız migrate.php'de, dosya sunumu başlıkları,
// SW siparis*.php'yi önbelleğe almaz, sürüm eşitliği. CLI; DB'ye dokunmaz.
//   php scripts/siparis_gate_static_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function oku(string $f): string { global $ROOT; return is_file($ROOT . '/' . $f) ? (string)file_get_contents($ROOT . '/' . $f) : ''; }
/** PHP yorumlarını atar (yorumdaki ad/anahtar sözcük yanlış alarm vermesin); HTML/string içeriği kalır. */
function kod_oku(string $f): string {
    $src = oku($f);
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $out .= $t[1]; }
        else $out .= $t;
    }
    return $out;
}

$helpers = oku('config/helpers.php'); $index = oku('index.php'); $sw = oku('sw.js');
$core = oku('config/siparis_core.php'); $migrate = oku('migrate.php'); $auth = oku('config/auth.php');

// Kök dizindeki tüm sipariş uç/sayfaları (partial `_siparis_liste.php` "_" ile başlar, eşleşmez)
$sayfalar = [];
foreach (glob($ROOT . '/siparis*.php') ?: [] as $f) $sayfalar[] = basename($f);
sort($sayfalar);
$BEKLENEN = ['siparis_ayarlar.php', 'siparis_belge.php', 'siparis_beyan_bagla.php', 'siparis_dosya.php', 'siparis_kaydet.php',
             'siparis_mail.php', 'siparis_paylas.php', 'siparis_pkl.php', 'siparis_view.php', 'siparis_yazdir.php', 'siparis_yukle.php', 'siparis_zip.php', 'siparisler.php'];

echo "=== Kapı tek kaynak ===\n";
ok('helpers.php can_siparis() tanımlı', str_contains($helpers, 'function can_siparis(string $perm): bool'));
ok('can_siparis fail-closed (can() yoksa false)', (bool)preg_match("/function can_siparis[^{]*\{\s*if \(!function_exists\('can'\)\) return false;/", $helpers));
$a = strpos($helpers, 'function nav_ptak_sayfalari(): array {'); $b = strpos($helpers, 'function render_desktop_sidebar(');
$c = strpos($helpers, 'function can_siparis(');
ok('can_siparis nav_ptak_sayfalari ile render_desktop_sidebar ARASINDA (rol_kapilari_smoke eval bölgesi)',
    $a !== false && $b !== false && $c !== false && $a < $c && $c < $b);
ok("sidebar \$p_sip = can_siparis('read')", str_contains($helpers, "\$p_sip   = can_siparis('read');"));
ok('sidebar sipariş bağlantısı $p_sip kapılı', str_contains($helpers, "if (\$p_sip)  \$lnk('siparisler.php'"));
ok('topnav sipariş bağlantısı can_siparis kapılı', (bool)preg_match("/if \(can_siparis\('read'\)\): \?>\s*\n\s*<a href=\"<\?= \\\$base \?>siparisler\.php\"/", $helpers));
ok("nav_alt_izinler siparis = can_siparis('read')", str_contains($helpers, "'siparis' => \$fn && can_siparis('read')"));
ok('nav_alt_sayfalar siparis kaydı (siparisler.php, ikon, grup Operasyon)',
    (bool)preg_match("/'siparis' => \['href' => 'siparisler\.php',[^\n]*'grup' => 'Operasyon', 'ikon' => 'assets\/nav-icons\/siparis\.svg'\]/", $helpers));
ok("first_allowed_page siparisler.php = can_siparis('read')", str_contains($helpers, "'siparisler.php'     => can_siparis('read')"));
ok('nav_aktif_anahtar siparis bölümü', str_contains($helpers, "'siparis' => \$a_sip") && str_contains($helpers, "\$a_sip   = in_array(\$cur, ['siparisler.php'"));
ok('index.php kartı can_siparis kapılı', (bool)preg_match("/if \(can_siparis\('read'\)\): \?>\s*\n\s*<a href=\"siparisler\.php\"/", $index));
ok('index.php bölüm görünürlüğü can_siparis içeriyor', str_contains($index, "|| can_siparis('read') || can_mail('read');"));
ok('siparis.* yetkileri helpers.php\'de YALNIZ can_siparis içinde okunuyor (4 çağrı)', substr_count($helpers, "can('siparis.") === 4,
    'sayım: ' . substr_count($helpers, "can('siparis."));
foreach (['index.php', 'migrate.php'] as $f) {
    ok("$f kapıyı can('siparis.*') ile KOPYALAMIYOR", preg_match("/\bcan\('siparis\./", oku($f)) === 0);
}
ok('sipariş sayfa/uçları sayısı beklenenle aynı (Paket 2 dosyaları mevcut)', $sayfalar === $BEKLENEN,
    'bulunan: ' . implode(',', $sayfalar) . ' | eksik: ' . implode(',', array_diff($BEKLENEN, $sayfalar)));
foreach ($sayfalar as $f) {
    $k = kod_oku($f);
    ok("$f require_login() çağırıyor", str_contains($k, 'require_login()'));
    ok("$f require_siparis( kapısı var", str_contains($k, 'require_siparis('));
    ok("$f kapıyı can('siparis.*') ile KOPYALAMIYOR", preg_match("/\bcan\('siparis\./", $k) === 0);
}
ok("siparisler.php birebir require_siparis('read'); satırı (bottomnav kapı iğnesi)", str_contains(oku('siparisler.php'), "require_siparis('read');"));

echo "\n=== POST uçlarında CSRF mutasyondan ÖNCE ===\n";
$MUT = ['siparis_alan_elle', 'siparis_alan_kilidi_kaldir', 'siparis_catisma_coz', 'siparis_durum_degistir', 'siparis_durum_ilerlet',
        'siparis_sevkiyat_elle_ac', 'siparis_sevkiyat_sil', 'siparis_belge_sil', 'siparis_dosya_kaydet', 'siparis_belge_isle',
        'siparis_pkl_kaydet_taslak', 'siparis_pkl_uret', 'siparis_profil_kaydet', 'siparis_profil_sil', 'siparis_sayac_ayarla',
        'siparis_beyan_bagla', 'siparis_beyan_bag_kaldir', 'siparis_sevkiyat_yenile',
        // v378: siparis_yukle.php döngüsü ortak fonksiyona taşındı (dosyada siparis_dosya_kaydet( artık geçmez → bu ad olmadan CSRF denetimi sessizce düşerdi)
        'siparis_dosya_yukle_isle', 'siparis_mail_baslat', 'siparis_mail_adim', 'siparis_mail_vazgec', 'siparis_mail_ayar_kaydet'];
$mutRe = '/\b(' . implode('|', $MUT) . ')\s*\(/';
foreach ($sayfalar as $f) {
    $k = kod_oku($f);
    if (!preg_match($mutRe, $k, $m, PREG_OFFSET_CAPTURE)) continue;
    $ilk = $m[0][1];
    $cs = strpos($k, 'csrf_check(');
    ok("$f: csrf_check( var ve ilk mutasyon ({$m[1][0]}) öncesinde", $cs !== false && $cs < $ilk);
    ok("$f: mutasyon POST'a bağlı", (bool)preg_match("/REQUEST_METHOD'\]\s*(!==|===)\s*'POST'/", $k));
    ok("$f: yazma kapısı require_siparis('write'|'delete'|'match') içeriyor", (bool)preg_match("/require_siparis\('(write|delete|match)'\)|can_siparis\('(write|delete|match)'\)/", $k));
}
foreach (['siparis_dosya.php', 'siparis_zip.php', 'siparis_belge.php', 'siparis_view.php', 'siparisler.php', 'siparis_ayarlar.php', 'siparis_yazdir.php', 'siparis_paylas.php'] as $f) {
    $k = kod_oku($f);
    if ($k === '') continue;
    // GET-only görünüm sayfaları (POST'u siparis_kaydet/… uçlarına gider): ayarlar sayfası profil/sayaç formu içerebilir → muaf.
    if ($f === 'siparis_ayarlar.php') continue;
    ok("$f: doğrudan veri değiştiren çekirdek çağrısı YOK", preg_match($mutRe, $k) === 0);
}

echo "\n=== Yetki kataloğu ve seed ===\n";
foreach (['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match'] as $p) {
    ok("katalogda $p", str_contains($auth, "'$p'"));
    ok("admin seed listesinde $p", str_contains($helpers, "'$p'"));
}
ok("katalog grubu 'Siparişler'", str_contains($auth, "'Siparişler' => ["));
ok('operator seed: siparis.read/write/match', (bool)preg_match("/'operator' => \[[^\]]*'siparis\.read','siparis\.write','siparis\.match'\]/", $helpers));
ok('operator seed siparis.delete İÇERMİYOR', (bool)preg_match("/'operator' => \[[^\]]*\]/", $helpers, $om) && !str_contains($om[0], 'siparis.delete'));
ok('viewer + muhasebe seed: yalnız siparis.read',
    (bool)preg_match("/'viewer'   => \[[^\]]*'siparis\.read'\]/", $helpers) && (bool)preg_match("/'muhasebe' => \[[^\]]*'siparis\.read'/", $helpers));
ok('sync_depot_name_in_data siparis_sevkiyatlar + siparis_belgeler', str_contains($helpers, "['siparis_sevkiyatlar',      'depo']") && str_contains($helpers, "['siparis_belgeler',         'depo']"));

echo "\n=== Şema yalnız migrate.php'den ===\n";
$cagiran = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS)) as $f) {
    $yol = (string)$f;
    if (!str_ends_with($yol, '.php') || str_contains($yol, '/vendor/') || str_contains($yol, '/scripts/') || str_contains($yol, '/.git/')) continue;
    if (preg_match('/\bsiparis_migrate\s*\(/', kod_oku(substr($yol, strlen($ROOT) + 1)))) $cagiran[] = substr($yol, strlen($ROOT) + 1);
}
sort($cagiran);
ok('siparis_migrate() yalnız tanım + migrate.php', $cagiran === ['config/siparis_core.php', 'migrate.php'], implode(',', $cagiran));
ok('migrate.php sipariş kartı: CSRF + audit + id="siparis" (v388: kart mg_kart_ac(\'siparis\') ile açılır, render çıktısı migrate_panel_smoke.php\'de)',
    str_contains($migrate, "'ne'] ?? '') === 'siparis'") && str_contains($migrate, "audit_log_event('migrate', 'siparis'") && str_contains($migrate, "mg_kart_ac('siparis')"));
ok('migrate.php kartı ne=siparis formu + 10 tabloyu siparis_tablolar() ile listeler', str_contains($migrate, 'name="ne" value="siparis"') && str_contains($migrate, 'siparis_tablolar()'));
ok('db.php siparis_ tablosunu auto-migration\'a eklemiyor', !str_contains(oku('config/db.php'), 'siparis_'));
ok('helpers.php siparis_tablolar içermiyor (IIFE migrasyonuna eklenmedi)', !str_contains($helpers, 'siparis_tablolar'));
ok('DDL\'de ALTER yok', stripos($core, 'ALTER TABLE') === false);
ok('DDL\'de FOREIGN KEY yok (bütünlük uygulama kodunda)', stripos($core, 'FOREIGN KEY') === false);

echo "\n=== Güvenlik: PKL / ayrıştırıcı / dosya sunumu ===\n";
$pkl = kod_oku('config/siparis_pkl.php');
ok('siparis_pkl.php mevcut', $pkl !== '');
ok('siparis_pkl.php PhpOffice kullanmıyor (doğrudan XML yamalama)', $pkl !== '' && stripos($pkl, 'PhpOffice') === false);
ok('siparis_pkl.php exec/shell_exec/eval/popen YOK', $pkl !== '' && preg_match('/\b(exec|shell_exec|passthru|system|popen|proc_open|eval)\s*\(/', $pkl) === 0);
$pdf = kod_oku('config/siparis_pdf.php');
ok('siparis_pdf.php strict_types DEĞİL (gevşek tip dönüşümüne dayanır)', $pdf !== '' && !preg_match('/declare\s*\(\s*strict_types\s*=\s*1/', $pdf));
ok('siparis_pdf.php exec/shell_exec/eval YOK', $pdf !== '' && preg_match('/\b(exec|shell_exec|passthru|system|popen|proc_open|eval)\s*\(/', $pdf) === 0);
$dosya = oku('siparis_dosya.php');
ok('siparis_dosya.php nosniff', str_contains($dosya, 'nosniff'));
ok('siparis_dosya.php no-store', str_contains($dosya, 'no-store'));
ok('siparis_dosya.php session_write_close', str_contains($dosya, 'session_write_close'));
ok('siparis_dosya.php belge görünürlük (depo) kapısı', str_contains($dosya, 'siparis_belge_gorunur('));
ok('siparis_zip.php belge görünürlük (depo) kapısı', str_contains(oku('siparis_zip.php'), 'siparis_belge_gorunur('));
ok('storage/siparis/.htaccess "Require all denied"', str_contains(oku('storage/siparis/.htaccess'), 'Require all denied'));
$gi = oku('.gitignore');
ok('.gitignore storage/siparis/* + !storage/siparis/.htaccess', str_contains($gi, 'storage/siparis/*') && str_contains($gi, '!storage/siparis/.htaccess'));
ok('PKL şablonu templates/excel/pkl_v14_blank.xlsx mevcut', is_file($ROOT . '/templates/excel/pkl_v14_blank.xlsx'));

echo "\n=== v378: Mailden al — tek yazma yolu, salt okunur posta kutusu, adres repoda yok ===\n";
$yukle = kod_oku('siparis_yukle.php'); $esl = kod_oku('config/siparis_eslestir.php'); $sm = kod_oku('config/siparis_mail.php'); $smUc = kod_oku('siparis_mail.php');
ok('siparis_dosya_yukle_isle() config/siparis_eslestir.php\'de tanımlı', str_contains($esl, 'function siparis_dosya_yukle_isle('));
ok('siparis_yukle.php ortak fonksiyonu çağırıyor, siparis_dosya_kaydet / siparis_belge_isle\'yi DOĞRUDAN çağırmıyor (tek yol)',
    str_contains($yukle, 'siparis_dosya_yukle_isle(') && !preg_match('/\b(siparis_dosya_kaydet|siparis_belge_isle)\s*\(/', $yukle));
ok('siparis_mail.php (config) tek yazma yolu: siparis_dosya_kaydet / siparis_belge_isle doğrudan çağrılmıyor, siparis_dosya_yukle_isle çağrılıyor',
    $sm !== '' && str_contains($sm, 'siparis_dosya_yukle_isle(') && !preg_match('/\b(siparis_dosya_kaydet|siparis_belge_isle)\s*\(/', $sm));
ok('siparis_dosya_kaydet kaynak beyaz listesi [\'yukleme\', \'mail\']', str_contains($core, "in_array(\$kaynak, ['yukleme', 'mail'], true)"));
ok('mail yolu silinmiş belgeyi geri getirmez (silineni_geri_getir => false)', str_contains($sm, "'silineni_geri_getir' => false"));
ok('config/siparis_mail.php: eval/exec/shell_exec/system/unserialize YOK', $sm !== '' && !preg_match('/\b(eval|exec|shell_exec|passthru|system|popen|proc_open|unserialize)\s*\(/', preg_replace('/\$(?:pdo|db)->exec\(/', '', $sm)));
ok('config/siparis_mail.php: IMAP istemci metodu çağrılmıyor (posta kutusuna yalnız mail_ek_hazirla ile gidilir)',
    $sm !== '' && !preg_match('/->(uidAra|uidMesaj|uidParca|klasorAc|komut|ekle|girisYap|baslat)\s*\(/', $sm) && !preg_match('/\bmail_imap_baglan\s*\(/', $sm) && str_contains($sm, 'mail_ek_hazirla('));
ok('config/siparis_mail.php: gelen-kutusu sorgusu mail_gonderilen_disla_sql() içeriyor', str_contains($sm, 'mail_gonderilen_disla_sql()'));
ok('config/siparis_mail.php: şemasız (CREATE/ALTER/DROP TABLE yok; ayar hks_kv\'de)', $sm !== '' && !preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $sm) && str_contains($sm, "'siparis_mail_ayar'"));
ok('config/siparis_mail.php: kapıyı can(\'mail.*\') / can(\'siparis.*\') ile KOPYALAMIYOR', preg_match("/\bcan\('(mail|siparis)\./", $sm) === 0 && str_contains($sm, "can_siparis('write') && can_mail('read')"));
ok('config/siparis_mail.php: Reply-To ile eşleştirme YOK (reply_to_addr sorguda geçmez)', !str_contains($sm, 'reply_to'));
preg_match_all('/error_log\(([^;]*)\);/', $sm, $em);
ok('config/siparis_mail.php: error_log yalnız sabit metin / mail_redact / sınıf adı', !array_filter($em[1], fn($a) => !str_contains($a, 'mail_redact(') && !preg_match("/^'[^']*'(?: \. get_class\(\\\$e\))?$/", trim($a))), json_encode($em[1]));
ok('siparis_mail.php (uç): require_siparis(\'write\') + siparis_mail_yetkisi() + mail_no_store()', str_contains($smUc, "require_siparis('write')") && str_contains($smUc, 'siparis_mail_yetkisi()') && str_contains($smUc, 'mail_no_store()'));
ok('siparis_mail.php (uç): session_write_close (IMAP beklerken oturum kilidi tutulmaz)', str_contains($smUc, 'session_write_close()'));
$cs = strpos($smUc, 'csrf_check('); $ilk = preg_match('/\bsiparis_mail_(baslat|adim|vazgec)\s*\(/', $smUc, $mm0, PREG_OFFSET_CAPTURE) ? $mm0[0][1] : false;
ok('siparis_mail.php (uç): csrf_check ilk mutasyondan ÖNCE', $cs !== false && $ilk !== false && $cs < $ilk);
ok('siparis_mail.php (uç): GET → 405, yalnız POST', (bool)preg_match("/REQUEST_METHOD'\]\s*!==\s*'POST'/", $smUc) && str_contains($smUc, '405'));
$swKural = preg_match('#(/\\\\/siparis\(ler\|_\[a-z\]\+\)\?\\\\\.php\(\\\\/\|\$\)/)\.test\(u\.pathname\)#', $sw, $swm) ? $swm[1] : '';
$swPcre = $swKural !== '' ? '#' . str_replace('\\/', '/', trim($swKural, '/')) . '#' : '#^$#';
ok('sw.js bypass regex\'i siparis_mail.php\'yi KAPSIYOR (uç adı tek alt çizgili ek olmalı)', $swKural !== '' && preg_match($swPcre, '/siparis_mail.php') === 1 && preg_match($swPcre, '/siparis_mail_tara.php') === 0, $swPcre);
$epostaRe = '/[a-z0-9._%+-]+@(?!ornek\.test\b)[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/i';
foreach (['config/siparis_mail.php', 'siparis_mail.php', 'siparisler.php', 'siparis_ayarlar.php', '_siparis_ortak.php', 'assets/siparis.js', 'assets/siparis.css', 'scripts/siparis_mail_smoke.php', 'scripts/_siparis_mail_test_lib.php', 'scripts/siparis_ui_render.php',
    'siparis_yazdir.php', 'config/siparis_yazdir.php', 'assets/siparis_yazdir.js', 'scripts/siparis_yazdir_smoke.php', 'scripts/siparis_yazdir_smoke.js',
    'siparis_paylas.php', 'config/siparis_wp.php', 'assets/siparis_wp.js'] as $f) {
    $t = str_ends_with($f, '.php') ? kod_oku($f) : oku($f);
    ok("$f: gerçek e-posta adresi YOK (yalnız @ornek.test)", $t !== '' && preg_match($epostaRe, $t, $em2) === 0, $em2[0] ?? '');
}
ok('siparis_ayarlar.php: "X adına Y" yönlendirmesi + mail_ayar POST (siparis.delete kapısı)', str_contains(oku('siparis_ayarlar.php'), "Mailde 'X adına Y' görünüyorsa iki adresi de ekleyin.") && str_contains(oku('siparis_ayarlar.php'), "\$islem === 'mail_ayar'") && (bool)preg_match("/'mail_ayar'\) \{\s*require_siparis\('delete'\)/", oku('siparis_ayarlar.php')));
ok('belge kökeni rozeti: siparis_view.php + siparis_belge.php kaynak=mail', str_contains(oku('siparis_view.php'), "\$b['kaynak'] === 'mail'") && str_contains(oku('siparis_belge.php'), "\$b['kaynak'] === 'mail'"));
ok('siparis_bag_yaz olay metni "(mailden)"', str_contains($esl, "' (mailden)'"));

echo "\n=== v382: Açık Sevkiyatlar yazdırma ===\n";
$yzUc = kod_oku('siparis_yazdir.php'); $yzCfg = kod_oku('config/siparis_yazdir.php'); $yzJs = oku('assets/siparis_yazdir.js');
ok('uç adı TEK alt çizgili: siparis_yazdir.php (sw bypass regex\'i kapsar); iki ekli ad (siparis_acik_yazdir.php) YOK', is_file($ROOT . '/siparis_yazdir.php') && !glob($ROOT . '/siparis_*_*yazdir*.php') && !glob($ROOT . '/siparis_*_*_*.php'));
ok('uç: require_siparis(\'read\') + siparis_no_store() + session_write_close() + GET dışı 405', str_contains($yzUc, "require_siparis('read');") && str_contains($yzUc, 'siparis_no_store();') && str_contains($yzUc, 'session_write_close();')
    && (bool)preg_match("/REQUEST_METHOD'\] \?\? 'GET'\) !== 'GET'/", $yzUc) && str_contains($yzUc, '405'));
ok('uç: JSON + nosniff başlıkları; yazan uç DEĞİL (CSRF gerekmez: yalnız GET) — yalnız audit yazar', str_contains($yzUc, 'application/json') && str_contains($yzUc, 'nosniff') && !str_contains($yzUc, 'csrf_check(') && preg_match($mutRe, $yzUc) === 0);
ok('sw.js bypass regex\'i /siparis_yazdir.php\'yi KAPSIYOR (şoför verisi önbelleğe girmez), siparis_acik_yazdir.php\'yi KAPSAMAZ (bu yüzden ad yasak)', $swKural !== '' && preg_match($swPcre, '/siparis_yazdir.php') === 1 && preg_match($swPcre, '/siparis_acik_yazdir.php') === 0, $swPcre);
ok('siparis_yazdir.js sw SHELL\'de DEĞİL (siparis.js emsali: modül JS\'i önbelleğe alınmaz)', !str_contains($sw, 'siparis_yazdir'));
ok('config/siparis_yazdir.php: yalnız SELECT; çıktı/exit/DDL yok; kapıyı can(\'siparis.*\') ile kopyalamıyor', $yzCfg !== '' && !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\s/', $yzCfg) && !preg_match('/\b(echo|print|die)\b/', $yzCfg) && preg_match("/\bcan\('siparis\./", $yzCfg) === 0);
ok('audit: siparis_yazdir eylemi, ad/telefon/arama metni YAZILMAZ (yalnız adet/amac/kapsam/kesildi/arama bayrağı)', str_contains($yzUc, "siparis_audit('siparis_yazdir', null, null, ['adet' => ") && str_contains($yzUc, "'amac' => \$amac, 'kapsam' => \$okunan['kapsam'], 'kesildi' =>") && str_contains($yzUc, "'arama' => \$F['q'] !== ''") && !preg_match('/siparis_audit\([^;]*(sofor|telefon)/i', $yzUc));
ok('şoför kolonları yalnız siparis_sofor_hazir() ile SELECT\'e eklenir (kolon yokken SQL hatası olmasın)', str_contains($yzCfg, 'siparis_sofor_hazir($pdo)') && substr_count($yzCfg, 's.sofor_adi') === 1);
ok('ülke haritası siparis_yazdir_ulke_haritasi(): 12 mal alıcı ID', substr_count(preg_replace('/\s+/', ' ', (string)substr($yzCfg, (int)strpos($yzCfg, 'function siparis_yazdir_ulke_haritasi'), 1800)), "' => ['kod' =>") === 12);
ok('beyan driver_* / hks_ulke_ad / PO dosya adı YOK (şoför kaynağı yalnız sevkiyat; ülke yalnız alici_kodu/adı)', !preg_match('/driver_|hks_ulke|orijinal_ad|disk_adi/', $yzCfg));
ok('planlanan_* basılmaz (SELECT\'te yok)', !str_contains($yzCfg, 'planlanan_'));
ok('liste/yazdırma filtresi TEK fonksiyon: siparis_liste_filtre() siparisler.php + siparis_yazdir.php\'de çağrılır; WHERE kopyası yok', str_contains(kod_oku('siparisler.php'), 'siparis_liste_filtre($_GET)') && str_contains($yzUc, 'siparis_liste_filtre($_GET)')
    && !str_contains(kod_oku('siparisler.php'), 's.deleted_at IS NULL\'') && !str_contains($yzCfg, "LIKE :q") && str_contains($core, 'function siparis_liste_filtre(array $get): array'));
ok('siparis_url() geriye uyumlu 2. parametre ($sayfa = \'siparisler.php\')', str_contains($core, "function siparis_url(array \$override, string \$sayfa = 'siparisler.php'): string"));
ok('siparis_yazdir.js: kullanıcı verisi esc() ile; innerHTML yalnız esc\'li rapor dizgisi; fetch same-origin + no-store; window.open fetch\'ten ÖNCE', str_contains($yzJs, 'function esc(') && str_contains($yzJs, "credentials: 'same-origin'") && str_contains($yzJs, "cache: 'no-store'")
    && strpos($yzJs, "window.open('', '_blank')") !== false && strpos($yzJs, "window.open('', '_blank')") < strpos($yzJs, "getir(btn, 'yazdir')") && !preg_match('/eval\s*\(|new Function\(/', $yzJs));
ok('siparis_yazdir.js: toplam satırı tbody sonunda (tfoot yok), @page A4 yatay', !str_contains($yzJs, '<tfoot') && str_contains($yzJs, '@page{size:A4 landscape'));
ok('siparisler.php: yazdırma düğmeleri data-sip-yazdir ile (satır içi onclick yok), script defer', str_contains(oku('siparisler.php'), 'data-sip-yazdir="yazdir"') && str_contains(oku('siparisler.php'), 'siparis_yazdir.js?v=') && !preg_match('/onclick=.*sip-yazdir|data-sip-yazdir[^>]*onclick/', oku('siparisler.php')));
ok('yükleme kartı native <details> (v382); eski <section class="card sip-yukle"> YOK', str_contains(oku('siparisler.php'), '<details class="card sip-yukle" id="yukle" data-sip-yukle') && !str_contains(oku('siparisler.php'), '<section class="card sip-yukle"'));

echo "\n=== v394: WP Paylaş ===\n";
$wpUc = kod_oku('siparis_paylas.php'); $wpCfg = kod_oku('config/siparis_wp.php'); $wpJs = oku('assets/siparis_wp.js'); $lst = oku('_siparis_liste.php');
ok('uç: require_siparis(\'read\') + siparis_no_store() + session_write_close() + GET dışı 405', str_contains($wpUc, "require_siparis('read');") && str_contains($wpUc, 'siparis_no_store();') && str_contains($wpUc, 'session_write_close();')
    && (bool)preg_match("/REQUEST_METHOD'\] \?\? 'GET'\) !== 'GET'/", $wpUc) && str_contains($wpUc, '405'));
ok('uç: JSON + nosniff; yalnız GET (csrf_check yok); doğrudan veri değiştiren çekirdek çağrısı yok', str_contains($wpUc, 'application/json') && str_contains($wpUc, 'nosniff') && !str_contains($wpUc, 'csrf_check(') && preg_match($mutRe, $wpUc) === 0);
ok('uç adı TEK alt çizgili: siparis_paylas.php; sw.js bypass regex\'i kapsar', is_file($ROOT . '/siparis_paylas.php') && !glob($ROOT . '/siparis_*_*_*.php') && $swKural !== '' && preg_match($swPcre, '/siparis_paylas.php') === 1, $swPcre);
ok('siparis_wp.js / siparis_paylas sw SHELL\'de DEĞİL', !str_contains($sw, 'siparis_wp') && !str_contains($sw, 'siparis_paylas'));
ok('config/siparis_wp.php: yalnız SELECT; çıktı/DDL yok; kapıyı can(\'siparis.*\') ile kopyalamıyor; depo + görünürlük kapısı', $wpCfg !== '' && !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\s/', $wpCfg) && !preg_match('/\b(echo|print|die)\b/', $wpCfg)
    && preg_match("/\bcan\('siparis\./", $wpCfg) === 0 && str_contains($wpCfg, 'depo_sql_column(') && str_contains($wpCfg, 'siparis_gorunur('));
ok('config/siparis_wp.php: planlanan_* okunmaz, tur no\'ya düşülmez', !str_contains($wpCfg, 'planlanan_') && !str_contains($wpCfg, 'tur_no'));
ok('audit: siparis_wp_paylas, ad/telefon/plaka/nakliyeci YAZILMAZ (yalnız kalem sayısı)', str_contains($wpUc, "siparis_audit('siparis_wp_paylas',") && !preg_match('/siparis_audit\([^;]*(sofor|telefon|plaka|nakliyeci)/i', $wpUc));
ok('liste: her iki menüde (masaüstü + mobil) PKL\'den SONRA data-sip-wp düğmesi; kişisel veri / data-sip-yazdir YOK', substr_count($lst, 'data-sip-wp="') === 2
    && preg_match_all('~siparis_pkl\.php\?id=<\?= \$__id \?>">📄 PKL</a>\s*<button type="button" data-sip-wp="<\?= \$__id \?>"~', $lst) === 2
    && !preg_match('/sofor|telefon/i', $lst) && !str_contains($lst, 'data-sip-yazdir'));
ok('siparisler.php: siparis_wp.js defer yüklenir; şoför kolonu sayfa sorgusuna girmez', str_contains(oku('siparisler.php'), 'assets/siparis_wp.js?v=') && str_contains(oku('siparisler.php'), 'defer></script>') && !str_contains(kod_oku('siparisler.php'), 'sofor'));
ok('siparis_wp.js: esc(), same-origin + no-store, ClipboardItem + Web Share + NotAllowedError, html2canvas tembel, PNG; jsPDF/eval/localStorage/onclick YOK',
    str_contains($wpJs, 'function esc(') && str_contains($wpJs, "credentials: 'same-origin'") && str_contains($wpJs, "cache: 'no-store'") && str_contains($wpJs, "'siparis_paylas.php?id='")
    && str_contains($wpJs, 'ClipboardItem') && str_contains($wpJs, 'navigator.share') && str_contains($wpJs, 'NotAllowedError') && str_contains($wpJs, "'halkayit/html2canvas.min.js'")
    && str_contains($wpJs, 'toBlob(') && str_contains($wpJs, "'image/png'") && !str_contains($wpJs, 'jspdf') && !preg_match('/eval\s*\(|new Function\(/', $wpJs)
    && !str_contains($wpJs, 'localStorage') && !preg_match('/\sonclick=/', $wpJs));

echo "\n=== Audit eylem adları ≤ 40 karakter ===\n";
$uzun = []; $sayi = 0;
$dosyalar = array_merge(['config/siparis_core.php', 'config/siparis_eslestir.php', 'config/siparis_pkl.php', 'config/siparis_beyan.php', 'config/siparis_pdf.php', 'config/siparis_mail.php', 'config/siparis_sofor.php', 'config/siparis_yazdir.php', 'config/siparis_wp.php', 'migrate.php'], $sayfalar, ['_siparis_liste.php']);
foreach ($dosyalar as $f) {
    $k = kod_oku($f);
    if (preg_match_all("/\b(?:siparis_audit|audit_log_event)\(\s*'([^']+)'/", $k, $mm)) {
        foreach ($mm[1] as $act) { $sayi++; if (strlen($act) > 40) $uzun[] = "$f:$act"; }
    }
}
ok("audit eylem sabitleri bulundu ($sayi) ve hepsi ≤ 40 karakter", $sayi > 0 && !$uzun, implode(', ', $uzun));

echo "\n=== Service Worker + sürüm ===\n";
ok('sw.js siparis bypass kuralı', str_contains($sw, "/\\/siparis(ler|_[a-z]+)?\\.php(\\/|\$)/.test(u.pathname)"));
ok('sw.js SHELL siparis.svg', str_contains($sw, "'./assets/nav-icons/siparis.svg'"));
ok('siparis.css/js SHELL\'de DEĞİL (mail emsali)', !str_contains($sw, 'siparis.css') && !str_contains($sw, 'siparis.js'));
ok('assets/nav-icons/siparis.svg mevcut + geçerli SVG', is_file($ROOT . '/assets/nav-icons/siparis.svg') && str_contains(oku('assets/nav-icons/siparis.svg'), '<svg') && str_contains(oku('assets/nav-icons/siparis.svg'), 'viewBox="0 0 64 64"'));
preg_match("/define\('APP_SURUM', '(v\d+)'\)/", $helpers, $m1);
preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", $sw, $m2);
ok('APP_SURUM == sw.js CACHE_NAME', ($m1[1] ?? 'a') === ($m2[1] ?? 'b'), (($m1[1] ?? '?') . ' / ' . ($m2[1] ?? '?')));
ok('sürüm ≥ v371 (Siparişler modülünün sürümü)', (int)ltrim($m1[1] ?? 'v0', 'v') >= 371);
ok('CLAUDE.md üst satırı aynı sürümü taşıyor', str_contains(oku('CLAUDE.md'), '`yukleme-plani-' . ($m1[1] ?? '?') . '`'));

echo "\n";
printf("%d geçti, %d başarısız\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
