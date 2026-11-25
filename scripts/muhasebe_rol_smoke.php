<?php
// =========================================================
// scripts/muhasebe_rol_smoke.php — v550: muhasebe menüsü sayfa/işlem yetkileri (Roller'den verilebilir, VARSAYILAN KAPALI)
//   php scripts/muhasebe_rol_smoke.php     → çıkış kodu 0 = hepsi geçti
// (a) seed/sistem/eski roller hiç muhasebe.* üretmez · (b) sayfa/işlem kapıları · (c) admin her şeyi geçer
// (d) sube/depo tanımları/audit/backup admin_only kalır · (e) her kapsam içi sayfa dosyası yeni kapıyı taşır
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/role_permission_matrix.php';
require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/stok_core.php';
require_once $ROOT . '/config/fatura_core.php';
require_once $ROOT . '/config/kasa_core.php';
require_once $ROOT . '/config/ceksenet_core.php';

$helpers = (string)file_get_contents($ROOT . '/config/helpers.php');
$gruplar = nav_muhasebe_gruplari();
function muh_var(array $p): array { return array_values(array_filter($p, fn($x) => str_starts_with((string)$x, 'muhasebe.'))); }

echo "=== (a) varsayılan kapalı: tohum / eski rol / türetme ===\n";
// Kurulum tohumu: $all_p / $pdks_p / $rp_map metni (helpers.php) hiç muhasebe. içermez.
$bas = strpos($helpers, '$pdks_p = [');
$son = strpos($helpers, 'Seed YALNIZ yetkisi HİÇ OLMAYAN');
ok('tohum bloğu bulundu', $bas !== false && $son !== false && $son > $bas);
$blok = substr($helpers, $bas, $son - $bas);
ok('tohum listelerinde (all_p/pdks_p/rp_map) muhasebe. anahtarı YOK', !str_contains($blok, 'muhasebe.'));
$blokKod = preg_replace('~//[^\n]*~', '', $blok);
$blokKod = substr($blokKod, 0, strrpos($blokKod, ';') + 1);
$rp_map = null;
try { eval($blokKod); } catch (Throwable $e) { $rp_map = null; }
ok('tohum rol haritası çalıştırılabildi (5 sistem rolü)', is_array($rp_map) && count($rp_map) === 5);
foreach ((array)$rp_map as $slug => $perms) {
    ok("sistem rolü $slug: tohum + genişletme sonrası muhasebe.* YOK", muh_var(role_matrix_expand_permissions($perms)) === []);
}
$eskiRol = ['dashboard.read', 'records.read', 'records.write', 'records.delete', 'stok.read', 'stok.write', 'reports.read', 'reports.export', 'defs.read', 'defs.write', 'defs.admin', 'users.admin', 'hesap.read', 'siparis.read', 'siparis.write', 'kantar.read', 'kantar.write'];
ok('eski rol (işaretleyicisiz; stok.*, reports.*, records.*, users.admin) → muhasebe.* YOK', muh_var(role_matrix_expand_permissions($eskiRol)) === []);
ok('detaylı + katalog-sürümlü rol: kendiliğinden muhasebe.* YOK', muh_var(role_matrix_expand_permissions(['role.permissions.detailed', 'role.sections.detailed', 'role.catalog.1', 'stok.read', 'reports.read'])) === []);
ok('açıkça seçilen muhasebe.* anahtarı korunur (ve başka muhasebe.* türemez)', muh_var(role_matrix_expand_permissions(['role.permissions.detailed', 'muhasebe.cari_kartlari.read'])) === ['muhasebe.cari_kartlari.read']);
ok('ROLE_CATALOG_VERSION bu iş için artırılmadı (=3)', ROLE_CATALOG_VERSION === 3);

echo "=== katalog / matris ===\n";
$kat = muhasebe_rol_katalog();
$cat = role_matrix_catalog_additions();   // permission_catalog()['Sayfa ve işlem izinleri'] ile aynı kaynak
foreach ($kat as $k => $l) ok("katalogda Türkçe etiketli: $k", isset($cat[$k]) && trim((string)$cat[$k]) !== '');
$satirlar = []; $gez = function (array $r) use (&$gez, &$satirlar): void { $satirlar[] = $r; foreach ($r['children'] as $c) $gez($c); };
foreach (role_permission_matrix() as $g) foreach ($g as $r) $gez($r);
$kodlar = []; foreach ($satirlar as $r) foreach ($r['actions'] as $a) $kodlar[] = $a['permission'];
foreach (array_keys($kat) as $k) ok("matriste kontrol var: $k", in_array($k, $kodlar, true));
$beklenenSayfa = 0; foreach ($gruplar as $gk => $g) foreach ($g[2] as $__d => $_) if (!in_array(pathinfo((string)$__d, PATHINFO_FILENAME), muhasebe_herkese_acik_sayfalar(), true)) $beklenenSayfa++;   // v575: herkese açık sayfalar (şifre değiştirme) Roller'de satır DEĞİL
ok("her kapsam içi sayfa ($beklenenSayfa) için .read katalogda", count(array_filter(array_keys($kat), fn($k) => str_ends_with($k, '.read'))) === $beklenenSayfa);
ok('çalışan 5 modül grup işlemleri + palet_fisleri işlemleri (18 anahtar)', count(array_filter(array_keys($kat), fn($k) => !str_ends_with($k, '.read'))) === 18);
$ozet = array_column(role_ozet_sema(), null, 'key');
ok('role_ozet_sema: muhasebe_cari satırı Gör sayfaları + Ekle/Düzenle/Sil içerir', isset($ozet['muhasebe_cari']) && count($ozet['muhasebe_cari']['perms']) === 4 + 3);
$oz = role_ozet(['muhasebe.cari_kartlari.read']);
ok('role_ozet: tek sayfa Gör → muhasebe_cari "salt" (kapalı değil)', (array_column($oz, 'duzey', 'key')['muhasebe_cari'] ?? '') === 'salt');
ok('role_ozet: izin yoksa kapalı', (array_column(role_ozet([]), 'duzey', 'key')['muhasebe_cari'] ?? '') === 'kapali');
// (d) admin_only
$adminAnahtar = []; foreach ($satirlar as $r) if ($r['admin_only']) $adminAnahtar[] = $r['key'];
foreach (['depo_tanimlari', 'audit', 'backup', 'attendance_fis'] as $k) ok("(d) admin_only kalır: $k", in_array($k, $adminAnahtar, true));
ok('(d) admin_only satırı tam 4 (v574: Şube Tanımları kalktı)', count($adminAnahtar) === 4);
foreach ($satirlar as $r) if (in_array($r['key'], ['cari_kartlari', 'stok_kartlari', 'palet_fisleri', 'sifre_degistirme'], true)) ok("kapsam içi satır admin_only DEĞİL: {$r['key']}", !$r['admin_only']);

ok('v575: sifre_degistirme Roller satırı/katalog anahtarı YOK (verilecek yetki yok)', !isset($kat['muhasebe.sifre_degistirme.read']) && !in_array('sifre_degistirme', array_column($satirlar, 'key'), true) && !in_array('muhasebe.sifre_degistirme.read', $kodlar, true));

$pf = (string)file_get_contents($ROOT . '/palet_fisleri.php');
echo "=== v575: herkes kendi şifresini değiştirebilir ===\n";
$IS_ADMIN = false; $PERMS = [];
ok('yetkisiz (rolsüz izinsiz) oturumlu kullanıcı: sifre_degistirme kapısı GEÇER', muhasebe_sayfa_can('sifre_degistirme'));
ok('aynı kullanıcı: ajanda / kullanici_tercihleri hâlâ KAPALI (varsayılan yalnız yönetici)', !muhasebe_sayfa_can('ajanda') && !muhasebe_sayfa_can('kullanici_tercihleri'));
$__uid = $UID; $UID = 0;
ok('oturumsuz istek: sifre_degistirme kapısı KAPALI', !muhasebe_sayfa_can('sifre_degistirme'));
$UID = $__uid;
ok('first_allowed_page rolsüz kullanıcıyı şifre sayfasına İTMEZ (menüde görünür, iniş sayfası değil)', first_allowed_page() !== 'sifre_degistirme.php');
echo "=== (b) sayfa + işlem kapıları ===\n";
$IS_ADMIN = false;
$PERMS = ['role.permissions.detailed', 'muhasebe.cari_kartlari.read'];
ok('cari_kartlari sayfa kapısı geçer', muhasebe_sayfa_can('cari_kartlari'));
ok('aynı gruptaki başka sayfa geçmez (cari_fisler)', !muhasebe_sayfa_can('cari_fisler'));
ok('stok sayfaları geçmez', !muhasebe_sayfa_can('stok_kartlari') && !muhasebe_sayfa_can('stok_giris_fisleri'));
ok('placeholder / palet / yönetici-only sayfaları geçmez', !muhasebe_sayfa_can('palet_fisleri') && !muhasebe_sayfa_can('gunluk_kurlar') && !muhasebe_sayfa_can('sube_tanimlari') && !muhasebe_sayfa_can('olmayan_sayfa'));
ok('cari_can(read) true; write/create/edit/delete/admin/tanim false', cari_can('read') && !cari_can('write') && !cari_can('create') && !cari_can('edit') && !cari_can('delete') && !cari_can('admin') && !cari_can('tanim'));
ok('stok/fatura/kasa/çek-senet okuma false', !stok_can('read') && !fatura_can('read') && !kasa_can('read') && !ceksenet_can('read'));
ok('bilinmeyen eylem false', !cari_can('xyz') && !cari_can(''));
$PERMS = ['role.permissions.detailed', 'muhasebe.cari.create'];   // sayfa Gör YOK
ok('işlem izni tek başına işe yaramaz (sayfa Gör yok)', !cari_can('create') && !cari_can('read'));
$PERMS = ['role.permissions.detailed', 'muhasebe.cari_kartlari.read', 'muhasebe.cari.create'];
ok('Gör + create → cari_can(create) true; edit/delete/write false', cari_can('create') && !cari_can('edit') && !cari_can('delete') && !cari_can('write') && cari_can('tanim'));
$PERMS = ['role.permissions.detailed', 'muhasebe.cari_kartlari.read', 'muhasebe.cari.create', 'muhasebe.cari.edit'];
ok('create + edit → write true (belirsiz işlem fail-closed ikisini ister)', cari_can('write') && !cari_can('delete'));
ok('cari işlemi başka modüle sızmaz (stok/kasa)', !stok_can('create') && !kasa_can('create'));
$PERMS = ['role.permissions.detailed', 'muhasebe.palet_fisleri.read', 'muhasebe.palet_fisleri.delete'];
ok('palet: read + delete → read/delete true, create/edit false', muhasebe_palet_can('read') && muhasebe_palet_can('delete') && !muhasebe_palet_can('create') && !muhasebe_palet_can('edit'));
$PERMS = ['role.permissions.detailed', 'muhasebe.palet_fisleri.create'];
ok('palet: Gör olmadan create işe yaramaz', !muhasebe_palet_can('create') && !muhasebe_palet_can('read'));
$PERMS = ['stok.read', 'stok.write', 'reports.read', 'reports.export', 'records.write', 'users.admin', 'defs.admin'];
ok('eski izinler (stok.*, reports.*, records.*, users.admin, defs.*) hiçbir muhasebe kapısını AÇMAZ', !cari_can('read') && !stok_can('read') && !fatura_can('read') && !kasa_can('read') && !ceksenet_can('read') && !muhasebe_palet_can('read') && !muhasebe_sayfa_can('ajanda') && !muhasebe_sayfa_can('stok_kartlari'));


echo "=== palet_fisleri: aktif depo kapsamı + yetki bayrakları (v550) ===\n";
ok('pf_liste_getir: depo_sql_in(\'p.depo\') süzgeci (liste + CSV/XLSX aynı sorgu)', str_contains($pf, "depo_sql_in('p.depo')") && str_contains($pf, '$st->execute([$baslangic, ...$depoPar])'));
ok('pf_get_row: depot_visible_to_user yoksa "bulunamadı" (save/delete/duplicate buna dayanır)', (bool)preg_match("/depot_visible_to_user\\(\\(string\\)\\(\\\$row\\['depo'\\]/", $pf));
ok('parti önerileri depo_sql_records_in ile süzülür', str_contains($pf, "depo_sql_records_in('loading_records')"));
ok('sayfa data-izin-ekle/duzelt/sil/mus bayraklarını basar (mus = muhasebe_grup_can cari create)', str_contains($pf, 'data-izin-ekle') && str_contains($pf, 'data-izin-duzelt') && str_contains($pf, 'data-izin-sil') && str_contains($pf, "muhasebe_grup_can('cari', 'create')"));
$js = (string)file_get_contents($ROOT . '/assets/palet-fisleri.js');
ok('JS: F2/F3/Delete/F4/Ctrl+Shift+D/çift tık yolları izin() bayrağına bakar', str_contains($js, "izin('Ekle')") && str_contains($js, "izin('Sil')") && str_contains($js, "izin('Duzelt')") === false && str_contains($js, "'Duzelt'") && str_contains($js, "izin('Mus')"));
// Çalışma zamanı: depo kapsamı (SQLite, stub depo_sql_in / depot_visible_to_user)
$pdoD = db();
$pdoD->exec("CREATE TABLE loading_pallets (id INTEGER PRIMARY KEY, depo TEXT)");
foreach ([[1, 'DEPO A'], [2, 'DEPO B'], [3, ''], [4, null]] as [$i, $d]) $pdoD->prepare('INSERT INTO loading_pallets (id, depo) VALUES (?, ?)')->execute([$i, $d]);
$DEPOLAR = ['DEPO A'];
[$ds, $dp] = depo_sql_in('p.depo');
$st = $pdoD->prepare("SELECT p.id FROM loading_pallets p WHERE 1=1 AND $ds ORDER BY p.id"); $st->execute($dp);
ok('depo süzgeci: yalnız aktif depo + deposu boş satırlar görünür (yabancı depo yok)', $st->fetchAll(PDO::FETCH_COLUMN) === [1, 3, 4]);
ok('get_row mantığı: yabancı depo görünmez, boş depo görünür', !depot_visible_to_user('DEPO B') && depot_visible_to_user('DEPO A') && depot_visible_to_user('') && depot_visible_to_user('depo a'));
$DEPOLAR = null;
ok('kısıtsız kullanıcıda süzgeç yok', depo_sql_in('p.depo')[0] === '');

echo "=== (c) admin her şeyi geçer ===\n";
$IS_ADMIN = true; $PERMS = [];
foreach ($gruplar as $g) foreach ($g[2] as $dosya => $_) ok("admin sayfa kapısı: $dosya", muhasebe_sayfa_can(basename($dosya, '.php')));
ok('admin: tüm modül eylemleri', cari_can('read') && cari_can('write') && cari_can('delete') && cari_can('create') && cari_can('edit') && cari_can('admin') && stok_can('create') && fatura_can('edit') && kasa_can('delete') && ceksenet_can('write') && muhasebe_palet_can('create') && muhasebe_palet_can('delete'));
ok('admin: bilinmeyen eylem yine false', !cari_can('xyz'));
$IS_ADMIN = false;

echo "=== (e) sayfa dosyaları: yeni kapı, çıplak is_admin kapısı yok ===\n";
foreach ($gruplar as $gk => $g) foreach ($g[2] as $dosya => $_) {
    $s = (string)file_get_contents($ROOT . '/' . $dosya); $ad = basename($dosya, '.php');
    ok("$dosya: muhasebe_sayfa_can('$ad') kapısı + require_login", str_contains($s, "if (!muhasebe_sayfa_can('$ad')) { forbidden(); }") && str_contains($s, 'require_login()'));
    ok("$dosya: çıplak is_admin() { forbidden kapısı YOK", !preg_match('/is_admin\(\)\)\s*\{\s*forbidden/', $s));
}
foreach (nav_yonetim_sayfalari() as $dosya => $_) {
    $s = (string)file_get_contents($ROOT . '/' . $dosya);
    ok("$dosya: yönetici-only sayfa is_admin kapısı KALIR (Roller'den verilemez)", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s));
}
foreach (['cari_ekstre' => 'cari', 'kasa_cikti' => 'kasa', 'cek_cikti' => 'cek_senet', 'fatura_cikti' => 'fatura'] as $f => $grp) {
    $s = (string)file_get_contents("$ROOT/$f.php");
    ok("$f.php: muhasebe_grup_okuma_can('$grp') kapısı, çıplak is_admin yok", str_contains($s, "if (!muhasebe_grup_okuma_can('$grp')) { forbidden(); }") && !preg_match('/is_admin\(\)\)\s*\{\s*forbidden/', $s));
}
// Yazma uçları: her dal create/edit/delete (admin eylemi kalmadı)
foreach (['cari_kaydet', 'stok_kaydet', 'fatura_kaydet', 'kasa_kaydet', 'cek_kaydet'] as $f) {
    $s = (string)file_get_contents("$ROOT/$f.php");
    ok("$f.php: hiçbir dal 'write'/'admin' kapısı taşımaz (create|edit|delete)", !preg_match("/_kapi\('(write|admin)'\)/", $s));
}
ok('palet_fisleri.php: create/save/delete/duplicate/tanim_ekle işlem kapıları + records.unlock/reports.export korunur', substr_count($pf, 'pf_yetki(') >= 6 && str_contains($pf, "can('records.unlock')") && str_contains($pf, "require_perm('reports.export')"));
$sidebarBas = strpos($helpers, 'function nav_menu_modeli'); $sidebar = substr($helpers, $sidebarBas, 14000);   // v551: sidebar + mobil aynı modelden çizer
ok('sidebar: muhasebe sayfaları muhasebe_sayfa_can ile çizilir ($p_adm kapısı değil)', str_contains($sidebar, 'muhasebe_sayfa_can(') && !str_contains($sidebar, 'if ($p_adm) foreach ($sayfalar'));
ok('index.php kartları tek kaynak nav_menu_modeli() (muhasebe_sayfa_can) ile', str_contains((string)file_get_contents($ROOT . '/index.php'), "nav_menu_modeli('')") && str_contains($sidebar, 'muhasebe_sayfa_can('));
ok('first_allowed_page muhasebe_sayfa_can kullanır', str_contains($helpers, "muhasebe_sayfa_can(\$__slug)"));
ok('sw.js SW_MUHASEBE_RE değişmedi (cari/kasa/… önbelleğe alınmaz)', str_contains((string)file_get_contents($ROOT . '/sw.js'), 'SW_MUHASEBE_RE = /\/(cari|kasa|cek|senet|fatura|'));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
