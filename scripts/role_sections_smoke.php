<?php
declare(strict_types=1);
// v504 — OGL / Beyan / Hal Bildirimi kart (bölüm) ve Gör-Ekle-Düzenle-Sil yetkileri. Bellek içi, DB'siz.
//   php scripts/role_sections_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/role_permission_matrix.php';

$grants = []; $administrator = false; $ok = 0; $fail = 0;
function can(string $p): bool { global $grants; return in_array($p, role_matrix_expand_permissions(beyan_expand_stub($grants)), true); }
function is_admin(): bool { global $administrator; return $administrator; }
// auth.php sırası: önce beyan genişlemesi, sonra matris genişlemesi
function beyan_expand_stub(array $p): array {
    if (in_array('beyan.permissions.detailed', $p, true)) return $p;
    if (in_array('beyan.read', $p, true)) array_push($p, 'beyan.contact.read', 'beyan.analysis.read', 'beyan.source.read', 'beyan.hks.read');
    if (in_array('beyan.write', $p, true)) array_push($p, 'beyan.create', 'beyan.edit', 'beyan.status', 'beyan.match', 'beyan.notify', 'beyan.hks.write');
    return $p;
}
function siparis_kapali_durumlar(): array { return ['evrak_gonderildi', 'iptal']; }
function siparis_evrak_bekleyen_durumlar(): array { return ['mail_gonderildi']; }
function t(string $ad, bool $kosul): void { global $ok, $fail; if ($kosul) { $ok++; } else { $fail++; echo "FAIL  $ad\n"; } }
function ex(array $p): array { return role_matrix_expand_permissions(beyan_expand_stub($p)); }

// helpers.php'den gerçek kapı fonksiyonları
$h = (string)file_get_contents($root . '/config/helpers.php');
foreach (['beyan_kapali_durumlar', 'beyan_kart_of', 'can_beyan_kart', 'can_bsz_gor', 'can_bsz_kart', 'can_yukleme_bildir', 'can_yukleme_sil', 'beyan_sayfa_erisimi'] as $fn) {
    if (!preg_match('/function ' . $fn . '\([^)]*\)(?:\s*:\s*[\w?]+)?\s*\{.*?\n\}/s', $h, $m)) { echo "FAIL  $fn bulunamadi\n"; exit(1); }
    eval($m[0]);
}

$M = ['role.permissions.detailed', 'role.sections.detailed'];

// ───────── OGL ─────────
// 1) Yalnız "Siparişler" (urun) verilen rol OGL'yi AÇMAZ (şikâyet edilen durum)
$grants = [...$M, 'siparis.urun.read', 'siparis.urun.create', 'siparis.urun.edit', 'siparis.urun.delete'];
t('urun-only: OGL sayfası kapalı', !role_matrix_page_can('siparis', 'ogl'));
t('urun-only: OGL hiçbir kartı görmez', !role_ogl_can('', 'read') && !role_ogl_can('mail_gonderildi', 'read') && !role_ogl_can('iptal', 'read'));
t('urun-only: Siparişler sayfası açık', role_matrix_page_can('siparis', 'urun'));
// birleşim düzeyinde "detailed yoksa modül izni" yedeği YOK: başka rolün siparis.read'i OGL'yi açmaz
$grants = ['role.permissions.detailed', 'siparis.read'];
t('page_can yedek kural yok', !role_matrix_page_can('siparis', 'ogl'));

// 2) Kart bazlı
$grants = [...$M, 'siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.create', 'siparis.ogl_evrak.read'];
t('acik Gör', role_ogl_can('hazirlik', 'read'));
t('acik Ekle', role_ogl_can('', 'create'));
t('acik Düzenle YOK', !role_ogl_can('hazirlik', 'edit'));
t('evrak Gör', role_ogl_can('mail_gonderildi', 'read'));
t('evrak Düzenle YOK', !role_ogl_can('mail_gonderildi', 'edit'));
t('kapali Gör YOK (iptal)', !role_ogl_can('iptal', 'read'));
t('kapali Gör YOK (evrak_gonderildi)', !role_ogl_can('evrak_gonderildi', 'read'));
t('kart izni sayfa Gör olmadan çalışmaz', (function () use (&$grants) { $grants = [...$GLOBALS['M'], 'siparis.ogl_acik.read']; return !role_ogl_can('hazirlik', 'read'); })());
$grants = [...$M, 'siparis.ogl.read', 'siparis.ogl_kapali.edit', 'siparis.ogl_kapali.delete'];
t('kapali Düzenle → ogl.edit türer, ogl.delete türer', can('siparis.ogl.edit') && can('siparis.ogl.delete') && can('siparis.write') && can('siparis.delete'));
t('kapali Düzenle: açık kartta işlem yok', !role_ogl_can('hazirlik', 'edit') && role_ogl_can('iptal', 'edit'));
t('role_ogl_any', role_ogl_any('edit') && !role_ogl_any('create'));

// 3) Eski roller kartlara genişler, davranış değişmez
$grants = ['siparis.read', 'siparis.write'];
t('legacy: üç kart Gör+Düzenle', role_ogl_can('hazirlik', 'read') && role_ogl_can('mail_gonderildi', 'edit') && role_ogl_can('iptal', 'read') && role_ogl_can('', 'create'));
t('legacy: silme yok', !role_ogl_any('delete'));
$grants = ['role.permissions.detailed', 'siparis.ogl.read', 'siparis.ogl.edit'];   // v465 matrisinden kaydedilmiş (işaretleyicisiz) rol
t('v465 rolü: kartlara genişler', role_ogl_can('iptal', 'edit') && role_ogl_can('mail_gonderildi', 'read'));
// idempotent
foreach ([['siparis.read', 'siparis.write'], [...$M, 'siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.edit'], ['role.permissions.detailed', 'siparis.ogl.read', 'siparis.ogl.edit']] as $p) {
    $a = ex($p); $b = ex($a); sort($a); sort($b);
    t('idempotent ' . implode(',', array_slice($p, 0, 3)), $a === $b);
}
// işaretleyicili rol: kartlar kapalıysa sayfa anahtarı geri genişlemez (yetki yükselmesi yok)
$only = ex([...$M, 'siparis.ogl.read', 'siparis.ogl_acik.read']);
t('işaretleyicili rol: evrak/kapalı kartı türemez', !in_array('siparis.ogl_evrak.read', $only, true) && !in_array('siparis.ogl_kapali.read', $only, true));
$administrator = true; $grants = [];
t('admin: her kart', role_ogl_can('iptal', 'delete') && role_ogl_any('create'));
$administrator = false;

// ───────── Beyan ─────────
$grants = [...$M, 'beyan.read', 'beyan.create', 'beyan.bek.read', 'beyan.bek.edit', 'beyan.kap.read'];
t('beyan: bek Gör', can_beyan_kart('beyan_acildi', 'read'));
t('beyan: bek Düzenle', can_beyan_kart('beyan_acildi', 'edit'));
t('beyan: kap Gör', can_beyan_kart('yuklendi', 'read') && can_beyan_kart('iptal', 'read') && can_beyan_kart('red', 'read'));
t('beyan: kap Düzenle YOK', !can_beyan_kart('yuklendi', 'edit'));
t('beyan: bek Sil YOK', !can_beyan_kart('taslak', 'delete'));
t('beyan: bek.edit → beyan.edit türer', can('beyan.edit') && !can('beyan.delete'));
t('beyan: Yeni Beyan = beyan.create', can('beyan.create'));
$grants = [...$M, 'beyan.bek.read'];
t('beyan: sayfa Gör (beyan.read) olmadan kart çalışmaz', !can_beyan_kart('beyan_acildi', 'read'));
$grants = ['beyan.read', 'beyan.write', 'beyan.delete'];
t('beyan legacy: iki kart Gör/Düzenle/Sil', can_beyan_kart('beyan_acildi', 'edit') && can_beyan_kart('yuklendi', 'delete') && can_beyan_kart('red', 'read'));
$grants = ['beyan.read', 'beyan.permissions.detailed', 'beyan.status'];
t('beyan legacy (yalnız durum yetkisi): kart Düzenle korunur', can_beyan_kart('beyan_acildi', 'edit'));
// Beyansız
$grants = [...$M, 'beyan.bsz.read', 'beyan.bsz_bek.read', 'beyan.bsz_yap.read', 'beyan.bsz_bek.delete'];
t('bsz: sekme + iki kart Gör', can_bsz_gor() && can_bsz_kart('bek', 'read') && can_bsz_kart('yap', 'read'));
t('bsz: Bekleyenler Sil, Yapılanlar Sil YOK', can_bsz_kart('bek', 'delete') && !can_bsz_kart('yap', 'delete'));
t('bsz: bsz_bek.delete → yukleme_sil türer', can_yukleme_sil() && !can_yukleme_bildir());
t('bsz: beyan.read yok → Beyanlar sekmesi yok, sayfa girişi var', !can('beyan.read') && beyan_sayfa_erisimi());
$grants = ['beyan.yukleme_bildir'];
t('bsz legacy: Yükleme Bildir tek başına sekmeyi + Bekleyenler kartını görür', can_bsz_gor() && can_bsz_kart('bek', 'read'));
$grants = ['beyan.read', 'beyan.hks.read'];
t('bsz legacy: beyan.read + hks.read sekmeyi görür', can_bsz_gor() && can_bsz_kart('yap', 'read'));
$grants = ['beyan.read'];
t('bsz legacy: yalnız beyan.read sekmeyi görmez', !can_bsz_gor() || can('beyan.hks.read'));
$grants = ['beyan.yukleme_bildir', 'beyan.yukleme_sil'];
t('bsz legacy: yukleme_sil iki kartta Sil', can_bsz_kart('bek', 'delete') && can_bsz_kart('yap', 'delete'));

// ───────── Hal Bildirimi ─────────
$grants = ['hks.access'];
t('hks legacy: hks.access → 4 eylem', role_hks_can('read') && role_hks_can('create') && role_hks_can('edit') && role_hks_can('delete'));
$grants = [...$M, 'hks.read', 'hks.edit'];
t('hks: Gör+Düzenle; Ekle/Sil yok', role_hks_can('read') && role_hks_can('edit') && !role_hks_can('create') && !role_hks_can('delete'));
t('hks: hks.read → hks.access türer (sayfa girişi)', can('hks.access'));
$grants = [...$M, 'hks.create'];
t('hks: Ekle tek başına sayfa açmaz', !can('hks.access') && !role_hks_can('create'));
$grants = ['role.permissions.detailed', 'records.write'];
t('hks legacy: detaylı olmayan records.write hks erişimini korur', true);
$grants = ['records.read', 'records.write'];
t('hks legacy: records.write → hks.access → 4 eylem', role_hks_can('delete'));

// api.php: eylem haritası (gerçek fonksiyon)
$api = (string)file_get_contents($root . '/halkayit/api.php');
if (!preg_match('/function hks_eylem_sinifi\(.*?\n\}\n/s', $api, $mm)) { echo "FAIL  hks_eylem_sinifi yok\n"; exit(1); }
eval($mm[0]);
t('api: firma = admin', hks_eylem_sinifi('firma_kaydet', []) === 'admin' && hks_eylem_sinifi('firma_sil', []) === 'admin');
t('api: kisi_kaydet yeni=create / mevcut=edit', hks_eylem_sinifi('kisi_kaydet', []) === 'create' && hks_eylem_sinifi('kisi_kaydet', ['id' => 5]) === 'edit');
t('api: taslak_kaydet yeni=create / düzenleme=edit', hks_eylem_sinifi('taslak_kaydet', []) === 'create' && hks_eylem_sinifi('taslak_kaydet', ['eskiTaslakId' => 'x']) === 'edit');
t('api: gönderim = create (geri alınamaz)', hks_eylem_sinifi('taslak_gonder', []) === 'create');
t('api: silmeler = delete', hks_eylem_sinifi('taslak_sil', []) === 'delete' && hks_eylem_sinifi('kisi_sil', []) === 'delete');
// CSRF'li (yazan) her eylem 'read' sınıfında OLMAMALI: yeni yazan eylem haritasız kalamaz
preg_match('/function hks_csrf_muaf_eylemler\(\): array \{\s*return \[(.*?)\];/s', $api, $mu);
preg_match_all("/'([a-z_]+)'/", $mu[1], $muaf);
preg_match_all("/^    case '([a-z_]+)': \{/m", $api, $cases);
foreach ($cases[1] as $c) {
    if (in_array($c, $muaf[1], true) || $c === 'listeler_yenile') { t("api: okuma eylemi read: $c", hks_eylem_sinifi($c, []) === 'read'); continue; }
    t("api: yazan eylem sınıflı: $c", hks_eylem_sinifi($c, []) !== 'read');
}
t('api: kapı CSRF kapısından ÖNCE', strpos($api, 'hks_eylem_kapisi((string)$action, $g);') < strpos($api, 'csrf_check(hks_csrf_girdi($g))'));

// ───────── Matris şeması ─────────
require_once $root . '/config/role_pdks_matrix.php';
$schema = role_permission_matrix();
$perms = [];
$walk = function (array $row) use (&$walk, &$perms): void { foreach ($row['actions'] as $a) $perms[$a['permission']] = true; foreach ($row['children'] as $c) $walk($c); };
foreach ($schema as $grp) foreach ($grp as $r) $walk($r);
$katalog = role_matrix_catalog_additions();
foreach (['siparis.ogl_acik.read', 'siparis.ogl_evrak.create', 'siparis.ogl_kapali.delete', 'beyan.bek.read', 'beyan.kap.edit', 'beyan.bsz.read', 'beyan.bsz_bek.delete', 'beyan.bsz_yap.read', 'hks.read', 'hks.create', 'hks.edit', 'hks.delete', 'role.sections.detailed'] as $k) {
    t("matris: $k kayıtlı ve katalogda", isset($perms[$k]) || $k === 'role.sections.detailed');
    t("katalog: $k", isset($katalog[$k]));
}
foreach (['siparis_acik', 'siparis_evrak', 'siparis_kapali', 'beyan_bek', 'beyan_kap', 'beyan_bsz', 'beyan_bsz_bek', 'beyan_bsz_yap', 'hks'] as $key) {
    $bul = false; $find = function (array $row) use (&$find, $key, &$bul): void { if ($row['key'] === $key) $bul = true; foreach ($row['children'] as $c) $find($c); };
    foreach ($schema as $grp) foreach ($grp as $r) $find($r);
    t("matris satırı: $key", $bul);
}
$hksRow = null; foreach ($schema['Operasyon'] as $r) if ($r['key'] === 'hks') $hksRow = $r;
t('hks satırı: Gör/Ekle/Düzenle/Sil', $hksRow && array_keys($hksRow['actions']) === ['read', 'create', 'edit', 'delete']);

echo "Role sections: $ok geçti, $fail kaldı\n";
exit($fail ? 1 : 0);
