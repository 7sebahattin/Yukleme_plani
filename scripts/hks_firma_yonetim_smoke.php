<?php
// =========================================================
// scripts/hks_firma_yonetim_smoke.php — Hal Kayıt firma yönetimi: yalnız yönetici + audit (v417)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: bellek içi SQLite +
// halkayit/firma_yonetim_lib.php'nin GERÇEK fonksiyonları (sahte şifreleme/audit
// yazıcısıyla). api.php'deki kapı fonksiyonu kaynaktan ALINIP sahte is_admin/csrf_check/
// hks_json_cikti ile gerçekten çalıştırılır; uç gövdeleri statik denetlenir.
//
//   php scripts/hks_firma_yonetim_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);
require_once "$KOK/halkayit/firma_yonetim_lib.php";

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-74s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

// ── Kurulum: sahte şifreleme (tersinir, düz metin DEĞİL) + audit toplayıcı ──
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE hks_firmalar (id TEXT PRIMARY KEY, ad TEXT, renk TEXT, user_name TEXT, password_enc TEXT,
  service_password_enc TEXT, vergi_no TEXT, olusturma TEXT DEFAULT CURRENT_TIMESTAMP)');
$sifrele = fn($d) => 'ENC:' . strrev(base64_encode((string)$d));
$coz     = fn($e) => base64_decode(strrev(substr((string)$e, 4)));
$bul = function ($id) use ($db, $coz) {
    $st = $db->prepare('SELECT * FROM hks_firmalar WHERE id = ?'); $st->execute([$id]);
    $f = $st->fetch(); if (!$f) return null;
    return ['id' => $f['id'], 'ad' => $f['ad'], 'renk' => $f['renk'], 'userName' => $f['user_name'],
        'password' => $coz($f['password_enc']), 'servicePassword' => $coz($f['service_password_enc']), 'vergiNo' => $f['vergi_no']];
};
$auditler = [];
$audit = function ($a, $o, $n) use (&$auditler) { $auditler[] = ['action' => $a, 'old' => $o, 'new' => $n]; };
$say = fn() => (int)$db->query('SELECT COUNT(*) FROM hks_firmalar')->fetchColumn();

$SIFRE = 'GizliSifre-Xq93'; $SRV = 'ServisSifre-Zt71'; $USER = 'hks_kullanici_ornek'; $VERGI = '1234567890';

echo "── Ekleme ──\n";
[$k, $y] = hks_firma_kaydet_uygula($db, 'hks_firmalar', ['ad' => ' Örnek Firma ', 'vergiNo' => $VERGI, 'userName' => $USER,
    'password' => $SIFRE, 'servicePassword' => $SRV, 'renk' => 'blue'], $bul, $sifrele, $audit);
ok('ekle → 200 tamam', $k === 200 && ($y['tamam'] ?? false) === true);
ok('satır oluştu', $say() === 1);
$id = (string)$db->query('SELECT id FROM hks_firmalar')->fetchColumn();
ok('id "f…" biçiminde', (bool)preg_match('/^f\d+$/', $id), $id);
ok('şifreler şifreli saklandı', $bul($id)['password'] === $SIFRE && $bul($id)['servicePassword'] === $SRV);
ok('create audit yazıldı', count($auditler) === 1 && $auditler[0]['action'] === 'create' && $auditler[0]['old'] === null);
$n = $auditler[0]['new'];
ok('audit: firma_id + ad(trim) + renk', $n['firma_id'] === $id && $n['ad'] === 'Örnek Firma' && $n['renk'] === 'blue');
ok('audit: vergi no MASKELİ (son 4 hane)', $n['vergi_no'] === '******7890', (string)$n['vergi_no']);
ok('audit: verildi bayrakları', $n['kullanici_adi_verildi'] === true && $n['sifre_verildi'] === true && $n['servis_sifresi_verildi'] === true);

[$k, $y] = hks_firma_kaydet_uygula($db, 'hks_firmalar', ['ad' => 'X', 'vergiNo' => '1', 'userName' => 'u'], $bul, $sifrele, $audit);
ok('yeni firma şifresiz → 400, satır/audit yok', $k === 400 && $say() === 1 && count($auditler) === 1);
[$k] = hks_firma_kaydet_uygula($db, 'hks_firmalar', ['ad' => '', 'vergiNo' => '1'], $bul, $sifrele, $audit);
ok('adsız → 400', $k === 400 && count($auditler) === 1);

echo "── Düzenleme ──\n";
[$k] = hks_firma_kaydet_uygula($db, 'hks_firmalar', ['id' => $id, 'ad' => 'Yeni Ad', 'vergiNo' => $VERGI, 'userName' => '', 'password' => '', 'servicePassword' => ''], $bul, $sifrele, $audit);
ok('düzenle (boş şifre/kullanıcı) → 200', $k === 200);
$f = $bul($id);
ok('boş şifre/servis şifresi/kullanıcı adı ESKİSİNİ korur', $f['password'] === $SIFRE && $f['servicePassword'] === $SRV && $f['userName'] === $USER);
ok('renk gönderilmezse korunur', $f['renk'] === 'blue' && $f['ad'] === 'Yeni Ad');
$a = $auditler[1]; $yn = $a['new'];
ok('update audit: eski + yeni', $a['action'] === 'update' && $a['old']['ad'] === 'Örnek Firma' && $yn['ad'] === 'Yeni Ad');
ok('degisen_alanlar = [ad]', $yn['degisen_alanlar'] === ['ad'], json_encode($yn['degisen_alanlar']));
ok('şifre bayrakları false', $yn['sifre_degisti'] === false && $yn['servis_sifresi_degisti'] === false && $yn['kullanici_adi_degisti'] === false);

[$k] = hks_firma_kaydet_uygula($db, 'hks_firmalar', ['id' => $id, 'ad' => 'Yeni Ad', 'vergiNo' => '9876543210', 'renk' => 'red',
    'userName' => 'baska_kullanici', 'password' => 'YeniSifre-Pp55', 'servicePassword' => ''], $bul, $sifrele, $audit);
$f = $bul($id); $yn = $auditler[2]['new'];
ok('şifre değişti: yeni şifre saklandı, servis şifresi korundu', $f['password'] === 'YeniSifre-Pp55' && $f['servicePassword'] === $SRV);
ok('degisen_alanlar = [vergi_no, renk]', $yn['degisen_alanlar'] === ['vergi_no', 'renk'], json_encode($yn['degisen_alanlar']));
ok('bayraklar: kullanıcı adı + şifre değişti, servis değişmedi', $yn['kullanici_adi_degisti'] === true && $yn['sifre_degisti'] === true && $yn['servis_sifresi_degisti'] === false);
[$k, $y] = hks_firma_kaydet_uygula($db, 'hks_firmalar', ['id' => 'f0', 'ad' => 'A', 'vergiNo' => '1'], $bul, $sifrele, $audit);
ok('olmayan id düzenleme → 400, audit yok', $k === 400 && count($auditler) === 3);

echo "── Audit'e sızıntı ──\n";
$hepsi = json_encode($auditler, JSON_UNESCAPED_UNICODE);
foreach ([$SIFRE, $SRV, 'YeniSifre-Pp55', $USER, 'baska_kullanici', $VERGI, '9876543210'] as $gizli) {
    ok("audit JSON'unda geçmez: " . substr($gizli, 0, 6) . '…', strpos($hepsi, $gizli) === false);
}
$anahtarlar = [];
array_walk_recursive($auditler, function ($v, $k) use (&$anahtarlar) { $anahtarlar[] = (string)$k; });
ok('audit anahtarlarında şifre/kullanıcı değeri alanı yok', !array_intersect($anahtarlar, ['password', 'servicePassword', 'password_enc', 'service_password_enc', 'userName', 'user_name']));

echo "── Silme ──\n";
$onceAudit = count($auditler);
[$k] = hks_firma_sil_uygula($db, 'hks_firmalar', ['id' => ''], $bul, $audit);
ok('boş id → 400', $k === 400 && $say() === 1 && count($auditler) === $onceAudit);
[$k, $y] = hks_firma_sil_uygula($db, 'hks_firmalar', ['id' => 'f999'], $bul, $audit);
ok('olmayan firma → 400 "bulunamadı", audit yok', $k === 400 && ($y['hata'] ?? '') === 'Firma bulunamadı.' && count($auditler) === $onceAudit);
[$k, $y] = hks_firma_sil_uygula($db, 'hks_firmalar', ['id' => $id], $bul, $audit);
ok('sil → 200 tamam, satır gitti', $k === 200 && ($y['tamam'] ?? false) === true && $say() === 0);
$d = end($auditler);
ok('delete audit: eski değerler maskeli, yeni null', $d['action'] === 'delete' && $d['new'] === null && $d['old']['firma_id'] === $id && $d['old']['vergi_no'] === '******3210', json_encode($d['old']));
ok('silme audit\'inde şifre yok', strpos(json_encode($d), 'YeniSifre') === false && strpos(json_encode($d), $SRV) === false);

echo "── api.php kapısı (gerçek fonksiyon, sahte bağımlılıklarla) ──\n";
$api = file_get_contents("$KOK/halkayit/api.php");
ok('api.php firma_yonetim_lib.php\'yi yükler', strpos($api, "require_once __DIR__ . '/firma_yonetim_lib.php'") !== false);
preg_match('/function hks_firma_yonetici_kapisi\(array \$g\): void \{.*?\n\}\n/s', $api, $m);
ok('kapı fonksiyonu api.php\'de bulundu', !empty($m[0]));
class KapiCikis extends Exception { public $kod; public $govde; }
$GLOBALS['__admin'] = false; $GLOBALS['__csrf_cagri'] = 0;
function is_admin(): bool { return $GLOBALS['__admin']; }
function csrf_check(?string $t): void { $GLOBALS['__csrf_cagri']++; if ($t !== 'dogru') { $e = new KapiCikis('csrf'); $e->kod = 403; $e->govde = ['ok' => false, 'error' => 'Güvenlik doğrulaması başarısız.', 'code' => 403]; throw $e; } }
function hks_json_cikti($v, $kod = 200) { $e = new KapiCikis('json'); $e->kod = $kod; $e->govde = $v; throw $e; }
function hks_csrf_girdi(array $g): ?string { return $g['csrf'] ?? null; }
eval($m[0]);
function kapi_dene(bool $admin, array $g): ?KapiCikis {
    $GLOBALS['__admin'] = $admin; $GLOBALS['__csrf_cagri'] = 0;
    try { hks_firma_yonetici_kapisi($g); } catch (KapiCikis $e) { return $e; }
    return null;
}
$e = kapi_dene(false, ['csrf' => 'dogru']);
ok('yönetici değil (records.write sahibi operatör) → 403', $e && $e->kod === 403);
ok('403 gövdesinde `code` alanı YOK (istemci CSRF döngüsüne girmesin)', $e && !array_key_exists('code', $e->govde) && isset($e->govde['hata']));
ok('yönetici değilken CSRF sorulmaz', $GLOBALS['__csrf_cagri'] === 0);
// v418: CSRF merkezi kapıya (switch öncesi) taşındı; bu fonksiyon yalnız yönetici kapısıdır
// (ikinci csrf_check, merkezi kapının kapattığı oturumu yeniden açıp kilitlerdi).
$e = kapi_dene(true, ['csrf' => 'yanlis']);
ok('yönetici → geçer; CSRF burada TEKRAR denetlenmez (merkezi kapıda)', $e === null && $GLOBALS['__csrf_cagri'] === 0);
ok('firma_kaydet/firma_sil muaf listede DEĞİL (merkezi kapı CSRF ister)',
    preg_match('/function hks_csrf_muaf_eylemler\(\): array \{(.*?)\n\}/s', $api, $__mm)
    && strpos($__mm[1], "'firma_kaydet'") === false && strpos($__mm[1], "'firma_sil'") === false);

echo "── api.php uç gövdeleri (statik) ──\n";
foreach (['firma_kaydet', 'firma_sil'] as $uc) {
    preg_match("/case '$uc': \{(.*?)\n    \}\n/s", $api, $g);
    $govde = $g[1] ?? '';
    ok("$uc: gövde bulundu", $govde !== '');
    ok("$uc: ilk iş hks_firma_yonetici_kapisi(\$g)", (bool)preg_match('/^\s*hks_firma_yonetici_kapisi\(\$g\);/', $govde));
    ok("$uc: modül 'hks_firma', record_id null", strpos($govde, "audit_log_event(\$a, 'hks_firma', null,") !== false);
    ok("$uc: SOAP / gönderim / taslak yazıcı çağrısı yok", !preg_match('/hks_bildirim_kaydet|hks_taslak_olustur|hks_soap_|curl_|SoapClient/', $govde));
}
preg_match("/case 'firmalar': \{(.*?)\n    \}\n/s", $api, $g);
ok("firmalar yanıtı: üst düzey 'yonetici' = is_admin()", strpos($g[1] ?? '', "'yonetici' => (function_exists('is_admin') && is_admin())") !== false);
ok("firmalar yanıtı: şifre alanı dönmez", !preg_match('/password|servicePassword/', $g[1] ?? ''));
ok('lib: audit_log_event / hks_db çağırmaz, exit/echo yok', !preg_match('/audit_log_event\(|hks_db\(|\bexit\b|\becho\b/', preg_replace('#//[^\n]*#', '', file_get_contents("$KOK/halkayit/firma_yonetim_lib.php"))));
ok('.htaccess firma_yonetim_lib.php\'yi kapatır', substr_count(file_get_contents("$KOK/halkayit/.htaccess"), 'firma_yonetim_lib') >= 3);

echo $fail === 0 ? "\nTÜM TESTLER GEÇTİ\n" : "\n$fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
