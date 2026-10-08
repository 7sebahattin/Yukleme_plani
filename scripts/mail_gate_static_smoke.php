<?php
// =========================================================
// scripts/mail_gate_static_smoke.php — Mail Merkezi STATİK değişmezleri
// Kaynak koda bakar (sayfa çalıştırmaz): kapı tek kaynak, migrate yalnız
// migrate.php'de, sır/anahtar repoda yok, SW mail yollarını önbelleğe almaz,
// sürüm eşitliği. CLI; DB'ye dokunmaz.
//   php scripts/mail_gate_static_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function oku(string $f): string { global $ROOT; return (string)file_get_contents($ROOT . '/' . $f); }

$helpers = oku('config/helpers.php'); $index = oku('index.php'); $sw = oku('sw.js');
$core = oku('config/mail_core.php'); $migrate = oku('migrate.php');

echo "=== Kapı tek kaynak ===\n";
ok('helpers.php can_mail() tanımlı', str_contains($helpers, 'function can_mail(string $perm): bool'));
ok('can_mail fail-closed (can() yoksa false)', (bool)preg_match("/function can_mail[^{]*\{\s*if \(!function_exists\('can'\)\) return false;/", $helpers));
ok('sidebar $p_mail = can_mail(\'read\')', str_contains($helpers, "\$p_mail  = can_mail('read');"));
ok('sidebar mail bağlantısı $p_mail kapılı', str_contains($helpers, "if (\$p_mail) \$lnk('mail.php'"));
ok('nav_alt_izinler mail = can_mail(\'read\')', str_contains($helpers, "'mail'    => \$fn && can_mail('read')"));
ok('first_allowed_page mail.php = can_mail(\'read\')', str_contains($helpers, "'mail.php'           => can_mail('read')"));
ok('index.php kartı can_mail(\'read\') kapılı', (bool)preg_match("/if \(can_mail\('read'\)\): \?>\s*\n\s*<a href=\"mail\.php\"/", $index));
ok('index.php bölüm görünürlüğü can_mail içeriyor', str_contains($index, "|| can_mail('read');"));
ok('mail.php require_mail(\'read\')', str_contains(oku('mail.php'), "require_mail('read');"));
ok('mail_hesaplar.php require_mail(\'admin\')', str_contains(oku('mail_hesaplar.php'), "require_mail('admin');"));
ok('mail_hesaplar.php POST\'ta csrf_check', str_contains(oku('mail_hesaplar.php'), "csrf_check(\$_POST['csrf'] ?? null);"));
ok('mail.* yetkileri HİÇBİR sayfada elle can(\'mail.…\') ile denetlenmiyor (yalnız can_mail)',
    preg_match("/can\('mail\.(?!admin|read|reply|send)/", $helpers) === 0 && substr_count($helpers, "can('mail.") === 3 + 1 /* read/reply/send + admin */,
    'sayım: ' . substr_count($helpers, "can('mail."));
foreach (['mail.php', 'mail_hesaplar.php', 'index.php', 'migrate.php'] as $f) {
    ok("$f kapıyı can('mail.*') ile KOPYALAMIYOR", preg_match("/\bcan\('mail\./", oku($f)) === 0);
}

echo "\n=== Yetki kataloğu ve seed ===\n";
$auth = oku('config/auth.php');
foreach (['mail.read', 'mail.reply', 'mail.send', 'mail.admin'] as $p) {
    ok("katalogda $p", str_contains($auth, "'$p'"));
    ok("admin seed listesinde $p", str_contains($helpers, "'$p'"));
}

echo "\n=== Şema yalnız migrate.php'den ===\n";
$cagiran = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS)) as $f) {
    $yol = (string)$f;
    if (!str_ends_with($yol, '.php') || str_contains($yol, '/vendor/') || str_contains($yol, '/scripts/') || str_contains($yol, '/.git/')) continue;
    if (str_contains(file_get_contents($yol), 'mail_migrate(')) $cagiran[] = substr($yol, strlen($ROOT) + 1);
}
sort($cagiran);
ok('mail_migrate() yalnız tanım + migrate.php', $cagiran === ['config/mail_core.php', 'migrate.php'], implode(',', $cagiran));
ok('migrate.php mail kartı CSRF + audit', str_contains($migrate, "'ne'] ?? '') === 'mail'") && str_contains($migrate, "audit_log_event('migrate', 'mail'"));
ok('mail_core.php şemayı db.php auto-migration\'a eklemiyor', !str_contains(oku('config/db.php'), 'mail_'));
ok('ALTER yok', stripos($core, 'ALTER TABLE') === false);

echo "\n=== Sır / anahtar repoda yok ===\n";
ok('config/local.php .gitignore\'da', str_contains(oku('.gitignore'), 'config/local.php'));
ok('MAIL_MASTER_KEY için sabit/yedek değer YOK', preg_match("/define\(\s*'MAIL_MASTER_KEY'/", $core) === 0 && preg_match('/MAIL_MASTER_KEY[\'"]?\s*(=|=>)\s*[\'"][A-Za-z0-9+\/=]{20,}/', $core) === 0);
$izlenen = shell_exec('cd ' . escapeshellarg($ROOT) . ' && git ls-files') ?: '';
ok('config/local.php izlenmiyor (git ls-files)', !preg_match('#^config/local\.php$#m', $izlenen));
$sizinti = [];
foreach (['config/mail_core.php', 'mail.php', 'mail_hesaplar.php', 'scripts/mail_core_smoke.php'] as $f) {
    if (preg_match('/(password|passwd|pass)\s*=\s*[\'"][^\'"\s]{6,}[\'"]/i', oku($f))) $sizinti[] = $f;
}
ok('mail dosyalarında gömülü şifre atamaları yok', $sizinti === [], implode(',', $sizinti));
ok('audit çağrıları şifre/değer geçirmiyor (mail_hesaplar)', !preg_match("/audit_log_event\([^;]*\\\$_POST\[(?:'imap_pass'|'smtp_pass')\]\s*[,)]/s", oku('mail_hesaplar.php')));
ok('error_log çağrıları mail_redact() ile', preg_match_all('/error_log\(([^;]*)\);/', $core, $m) && !array_filter($m[1], fn($a) => !str_contains($a, 'mail_redact(')),
    json_encode($m[1] ?? []));
ok('şifre alanı forma value= ile geri basılmıyor', !preg_match('/name="(?:imap|smtp)_pass"[^>]*value="<\?=/', oku('mail_hesaplar.php')));

echo "\n=== M2: yeni dosyalar ===\n";
foreach (glob($ROOT . '/config/mail_*.php') as $f) {
    $ad = basename($f); $k = (string)file_get_contents($f);
    preg_match_all('/error_log\(([^;]*)\);/', $k, $mm);
    $kotu = array_filter($mm[1], fn($a) => !str_contains($a, 'mail_redact(') && !preg_match("/^'[^']*'(?: \. \(int\)\\$\w+)?$/", trim($a)));
    ok("$ad: error_log çağrıları redakte ya da sabit metin", $kotu === [], json_encode(array_values($kotu)));
    ok("$ad: eval()/exec/shell_exec/system/passthru/unserialize YOK", !preg_match('/\b(eval|shell_exec|system|passthru|proc_open|popen|unserialize)\s*\(/', $k) && !preg_match('/\bexec\s*\(/', preg_replace('/\$pdo->exec\(|\$db->exec\(/', '', $k)));
    ok("$ad: LIBXML_NOENT / allow_self_signed=true / verify_peer=false YOK", !str_contains($k, 'LIBXML_NOENT') && !preg_match("/allow_self_signed'\s*=>\s*true|verify_peer'\s*=>\s*false|verify_peer_name'\s*=>\s*false/", $k));
}
$imap = oku('config/mail_imap.php');
ok('IMAP: TLS doğrulaması zorunlu (verify_peer + verify_peer_name true)', str_contains($imap, "'verify_peer' => true, 'verify_peer_name' => true"));
ok('IMAP: yalnız ssl/starttls kabul ediliyor (düz metin reddi)', str_contains($imap, "if (!in_array(\$guvenlik, ['ssl', 'starttls'], true))"));
$imapKod = php_strip_whitespace($ROOT . '/config/mail_imap.php');
ok('IMAP: yalnız EXAMINE (SELECT/STORE/DELETE/EXPUNGE/COPY/MOVE komutu üretilmiyor)', !preg_match('/komut\(\s*[\'"](SELECT|STORE|DELETE|EXPUNGE|COPY|MOVE)/i', $imapKod) && !preg_match('/UID (STORE|EXPUNGE|COPY|MOVE)/i', $imapKod));
ok('IMAP: sunucuya yazan TEK komut APPEND (ekle() içinde, 1 adet)', preg_match_all('/komut\(\s*[\'"]APPEND/i', $imapKod) === 1 && preg_match('/function ekle\(.*?komut\(\s*[\'"]APPEND/s', $imapKod) === 1);
ok('IMAP: UNSEEN yok', !preg_match('/UNSEEN/i', $imapKod));
preg_match_all('/UID FETCH [^"\']*/', $imapKod, $fm);
ok('IMAP: tüm UID FETCH komutları BODY.PEEK (BODY[ ile \\Seen\'i DEĞİŞTİRMEZ)', count($fm[0]) >= 3 && !array_filter($fm[0], fn($l) => str_contains($l, 'BODY[')), 'bulunan komut sayısı: ' . count($fm[0]));
$sync = oku('config/mail_sync.php');
ok('senkron: UNSEEN kullanmıyor (yorumlar hariç)', !preg_match('/UNSEEN/i', php_strip_whitespace($ROOT . '/config/mail_sync.php')));
ok('senkron: mail_messages DELETE/UPDATE ile veri silmiyor (yalnız uid eşleme güncellemesi)', !preg_match('/DELETE FROM mail_messages/i', $sync));
ok('cron betiği mail_cron_calistir kullanıyor', str_contains(oku('scripts/mail_sync_cron.php'), 'mail_cron_calistir('));
ok('Bağlantıyı Test Et: POST + csrf + audit', str_contains(oku('mail_hesaplar.php'), "\$islem === 'test'") && str_contains(oku('mail_hesaplar.php'), "audit_log_event('mail_account_test'"));

echo "\n=== M5: gönderim yolu TEK ve onay kapılı ===\n";
$outbox = oku('config/mail_outbox.php'); $smtpSrc = oku('config/mail_smtp.php');
preg_match('/function mail_outbox_gonder\(.*?\n}\n/s', $outbox, $gm); $gonder = $gm[0] ?? '';
$tum = '';
foreach (glob($ROOT . '/config/*.php') as $f) $tum .= "\n/*FILE:" . basename($f) . "*/" . php_strip_whitespace($f);
foreach (['mail.php', 'mail_hesaplar.php', 'mail_ek.php', 'migrate.php', 'index.php'] as $f) $tum .= "\n/*FILE:$f*/" . php_strip_whitespace($ROOT . '/' . $f);
ok('mail_smtp_baglan() çağrısı yalnız mail_outbox_gonder() içinde (+ tanım)', substr_count($tum, 'mail_smtp_baglan(') === 2 && str_contains($gonder, 'mail_smtp_baglan('), (string)substr_count($tum, 'mail_smtp_baglan('));
ok('SMTP ->gonder() çağrısı yalnız mail_outbox_gonder() içinde', preg_match_all('/->gonder\(/', $tum) === 1 && str_contains($gonder, '->gonder('));
ok('MailSmtpClient yalnız mail_smtp.php + mail_outbox_gonder (kurucu)', preg_match_all('/new MailSmtpClient/', preg_replace('#/\*FILE:mail_smtp\.php\*/.*?(?=/\*FILE:|$)#s', '', $tum)) === 0);
ok('mail_outbox_gonder: atomik sahiplenme koşulu (approved ∧ approved_by ∧ Message-ID ∧ hash)', str_contains($gonder, "status = 'approved' AND approved_by IS NOT NULL AND out_message_id IS NOT NULL AND approved_hash IS NOT NULL AND content_hash = approved_hash") && str_contains($gonder, "rowCount() !== 1"));
ok('mail_outbox_gonder: sahiplenmeden SONRA bütünlük (hash) doğrulaması', strpos($gonder, 'mail_outbox_hash') !== false && strpos($gonder, 'rowCount()') < strpos($gonder, 'mail_outbox_hash'));
ok('mail_outbox_onayla: send yetkisi + hash_equals + bütünlük + ikiz kontrolü', (bool)preg_match('/function mail_outbox_onayla\(.*?sendYetkisi.*?hash_equals.*?mail_outbox_hash.*?mail_outbox_ikiz_var/s', $outbox));
ok('belirsizlikte (unknown) otomatik yeniden gönderim yolu YOK: unknown → yalnız insan kararı', !preg_match("/'unknown'[^;]*mail_outbox_gonder\(|status = 'unknown'[^;]*status = 'approved'/", $outbox));
ok('kimlik bilgisi kontrolü: gönderim yalnız mail_hesap_cred_oku ile', str_contains($gonder, 'mail_hesap_cred_oku('));
ok('cron / senkron SMTP gönderimi YAPMIYOR (yalnız takılı işaretleme)', !str_contains(php_strip_whitespace($ROOT . '/scripts/mail_sync_cron.php'), 'mail_outbox_gonder') && !str_contains(php_strip_whitespace($ROOT . '/config/mail_sync.php'), 'mail_outbox_gonder('));
ok('SMTP: TLS doğrulamalı taşıma (MailSocketStream) + düz metin yok', str_contains($smtpSrc, 'MailSocketStream::baglan(') && !preg_match("/stream_socket_client|fsockopen/", $smtpSrc));
ok('mail.php POST: cevap işlemleri mail_post_isle üzerinden, CSRF öncesi', strpos(oku('mail.php'), 'csrf_check(') < strpos(oku('mail.php'), 'mail_post_isle('));
ok('onay formu: tek-gönderim işareti + gizli hash + islem alanı', str_contains(oku('mail.php'), 'data-tek-gonderim') && str_contains(oku('mail.php'), 'name="hash"') && str_contains(oku('mail.php'), 'value="cevap_onayla"'));

echo "\n=== M4/ChatGPT direktifleri: no-store + üçüncü taraf bildirimi ===\n";
foreach (['mail.php', 'mail_hesaplar.php', 'mail_ek.php'] as $f) {
    ok("$f: mail_no_store() / Cache-Control: no-store gönderiyor", str_contains(oku($f), 'mail_no_store();') || str_contains(oku($f), "Cache-Control: no-store"));
}
ok('mail_no_store(): no-store + private + Pragma + Expires', str_contains($core, "'Cache-Control: no-store, no-cache, must-revalidate, private'") && str_contains($core, "header('Pragma: no-cache')"));
ok('mail_no_store() çıktıdan ÖNCE (require_mail hemen ardından)', (bool)preg_match("/require_mail\('(?:read|admin)'\);\s*mail_no_store\(\);/", oku('mail.php') . oku('mail_hesaplar.php')));
$mailPhp = oku('mail.php');
// Sahip kararı (2026-10-08): düğmenin yanındaki "⚠ … ÜÇÜNCÜ TARAF …" uyarısı gereksiz tedirginlik → KALDIRILDI. Veri çıkışı yine KONTROLLÜ:
// sağlayıcı yalnız config/local.php'den açılır, hesap bazlı translate_enabled şart, "Şimdi çevir" açık bir tıklama ister; düğmenin title'ı bilgi verir.
ok('UI: çeviri düğmesi yalnız sağlayıcı AÇIK + hesapta çeviri AÇIKKEN görünür; görünür "ÜÇÜNCÜ TARAF" uyarısı yok (sahip kararı)', str_contains($mailPhp, 'mail_hesap_ceviri_acik($pdo') && !str_contains($mailPhp, 'ÜÇÜNCÜ TARAF çeviri servisine gönderilir'));
ok('UI: çevrilmiş mailde sağlayıcı + üçüncü taraf notu', str_contains($mailPhp, 'üçüncü taraf servis') && str_contains($mailPhp, 'mail metni bu servise gönderildi'));
ok('hesap formu: çeviri onay kutusunda üçüncü taraf uyarısı', str_contains(oku('mail_hesaplar.php'), 'üçüncü taraf çeviri servisine gönderilir'));
ok('çeviri sağlayıcısı varsayılan KAPALI (sabit yoksa none)', str_contains(oku('config/mail_translate.php'), "(string)MAIL_TRANSLATE_PROVIDER : 'none'") && !preg_match("/define\(\s*'MAIL_TRANSLATE_PROVIDER'/", php_strip_whitespace($ROOT . '/config/mail_translate.php')));
ok('gizli Google/resmi olmayan uç yok (yalnız deepl/libretranslate/mymemory)', !preg_match('/translate\.googleapis|translate\.google\.com|clients5|gtx|bing\.com\/translator/i', oku('config/mail_translate.php')));

echo "\n=== Service Worker + sürüm ===\n";
ok('sw.js mail yollarını bypass ediyor (PATH_INFO dahil)', str_contains($sw, "/\\/mail(_[a-z]+)?\\.php(\\/|$)/.test(u.pathname)) return;"));
ok('sw.js mail.svg SHELL\'de', str_contains($sw, "'./assets/nav-icons/mail.svg'"));
preg_match("/define\('APP_SURUM', '(v\d+)'\)/", $helpers, $a); preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", $sw, $b);
ok('APP_SURUM == sw.js CACHE_NAME', ($a[1] ?? 'x') === ($b[1] ?? 'y'), ($a[1] ?? '?') . ' vs ' . ($b[1] ?? '?'));
ok('mail.svg dosyası var', is_file($ROOT . '/assets/nav-icons/mail.svg'));
ok('HTTP\'den çalıştırılabilen mail sayfası oturum olmadan açılmaz (require_login)', str_contains(oku('mail.php'), 'require_login()') && str_contains(oku('mail_hesaplar.php'), 'require_login()'));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
