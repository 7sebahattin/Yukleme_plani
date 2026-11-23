<?php
declare(strict_types=1);
// v507 — İçerik (kart/düğme) yetkileri: katalog, sürümlü genişletme, kapı, kaynak bağlantısı. Bellek içi, DB'siz.
//   php scripts/role_content_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/role_permission_matrix.php';

$grants = []; $administrator = false; $ok = 0; $fail = 0;
function beyan_expand_stub(array $p): array {
    if (in_array('beyan.permissions.detailed', $p, true)) return $p;
    if (in_array('beyan.read', $p, true)) array_push($p, 'beyan.contact.read', 'beyan.analysis.read', 'beyan.source.read', 'beyan.hks.read');
    if (in_array('beyan.write', $p, true)) array_push($p, 'beyan.create', 'beyan.edit', 'beyan.status', 'beyan.match', 'beyan.notify', 'beyan.hks.write', 'beyan.source.write');
    return $p;
}
function can(string $p): bool { global $grants; return in_array($p, role_matrix_expand_permissions(beyan_expand_stub($grants)), true); }
function is_admin(): bool { global $administrator; return $administrator; }
function forbidden(string $m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function t(string $ad, bool $k): void { global $ok, $fail; if ($k) $ok++; else { $fail++; echo "FAIL  $ad\n"; } }
function ex(array $p): array { return role_matrix_expand_permissions(beyan_expand_stub($p)); }

$flat = role_content_flat();
t('katalog dolu (≥ 60 öğe)', count($flat) >= 60);
$gecerliOn = array_merge(array_keys(role_matrix_catalog_additions()), ['dashboard.read', 'beyan.read', 'beyan.create', 'beyan.notify', 'beyan.hks.write', 'beyan.source.write', 'reports.export', 'siparis.ogl.read']);

// 1) Katalog bütünlüğü
foreach ($flat as $key => $o) {
    t("anahtar biçimi: $key", (bool)preg_match('/^ui\.[a-z]+\.[a-z0-9_]+$/D', $key));
    t("tür geçerli: $key", in_array($o['tur'], ['kart', 'dugme'], true));
    t("ön koşul tanımlı: $key", count($o['gerek']) > 0);
    foreach ($o['gerek'] as $g) t("ön koşul $g katalogda ($key)", in_array($g, $gecerliOn, true));
    t("sayfa matriste: $key", in_array($o['sayfa'], array_map(static fn($r) => $r['key'], array_merge(...array_values(role_permission_matrix()))), true));
}
$labels = role_matrix_catalog_additions();
foreach ($flat as $key => $o) t("kataloga etiket girdi: $key", isset($labels[$key]) && $labels[$key] === $o['ad']);
t('sürüm işaretleyicisi kataloğa girdi', isset($labels['role.catalog.' . ROLE_CATALOG_VERSION]));

// 2) Her anahtar KAYNAKTA kullanılıyor (ölü anahtar yok) — kapı çağrısı icerik_can/icerik_kapi/$ic('x')/$icXxx
$kaynak = '';
foreach (array_merge(glob($root . '/*.php') ?: [], glob($root . '/halkayit/*.php') ?: [], glob($root . '/halkayit/*.html') ?: [], glob($root . '/config/*.php') ?: []) as $f) {
    if (str_contains($f, 'role_content.php')) continue;
    $kaynak .= (string)file_get_contents($f) . "\n";
}
$ozel = [   // JS/HTML'de başka biçimde bağlanan öğeler (body sınıfı: icx-<anahtar>)
    'ui.hks.ekr_bildirim' => 'icx-ekr_bildirim', 'ui.hks.ekr_taslaklar' => 'icx-ekr_taslaklar', 'ui.hks.ekr_gonderilenler' => 'icx-ekr_gonderilenler',
    'ui.hks.ekr_stok' => 'icx-ekr_stok', 'ui.hks.ekr_sorgu' => 'icx-ekr_sorgu', 'ui.hks.ekr_gelen' => 'icx-ekr_gelen', 'ui.hks.ekr_etiket' => 'icx-ekr_etiket',
    'ui.hks.ekr_kunye' => 'icx-ekr_kunye', 'ui.hks.gonderim_raporu' => 'icx-gonderim_raporu',
];
foreach ($flat as $key => $o) {
    $kisa = substr($key, 3);                 // siparis.mail_al
    [$sayfa, $oge] = explode('.', $kisa, 2);
    $var = str_contains($kaynak, "'$kisa'")                                   // icerik_can('siparis.mail_al')
        || str_contains($kaynak, "'$oge'")                                     // $ic('belge_yukle') / $icerikHarita değeri
        || str_contains($kaynak, "'$sayfa.$oge'")
        || str_contains($kaynak, "'$oge' =>") || str_contains($kaynak, "'$kisa' =>")
        || (isset($ozel[$key]) && str_contains($kaynak, $ozel[$key]))
        || str_contains($kaynak, "'$sayfa.' . ") || str_contains($kaynak, "'$sayfa.'.");
    t("anahtar kaynakta kullanılıyor: $key", $var);
}

// 3) Kapı davranışı
$grants = ['role.permissions.detailed', 'role.sections.detailed', 'role.catalog.' . ROLE_CATALOG_VERSION, 'siparis.ogl.read', 'siparis.ogl_acik.read', 'ui.siparis.kpi'];
t('kapı: seçilen öğe açık', icerik_can('siparis.kpi'));
t('kapı: seçilmeyen öğe kapalı (güncel sürümde otomatik verilmez)', !icerik_can('siparis.yazdir') && !icerik_can('siparis.mail_al'));
t('kapı: bilinmeyen anahtar fail-closed', !icerik_can('siparis.yok_boyle_bir_sey'));
$administrator = true;
t('kapı: admin tüm içerik', icerik_can('siparis.mail_al') && icerik_can('hks.gonder'));
t('kapı: admin bilinmeyen anahtarı da açmaz', !icerik_can('yok.yok'));
$administrator = false;
$gecti = false; try { icerik_kapi('siparis.mail_al'); } catch (RuntimeException $e) { $gecti = str_contains($e->getMessage(), 'Mailden al'); }
t('icerik_kapi kapalıysa 403 (öğe adıyla)', $gecti);

// 4) Sürümlü genişletme: ESKİ roller ön koşul izniyle yeni içeriği OTOMATİK alır, yeni roller almaz
$eski = ex(['siparis.read', 'siparis.write', 'beyan.read', 'beyan.write', 'dashboard.read']);   // işaretleyicisiz
t('eski rol (v0): OGL salt içerikleri otomatik', in_array('ui.siparis.kpi', $eski, true) && in_array('ui.siparis.sev_pkl', $eski, true) && in_array('ui.siparis.zip', $eski, true));
t('eski rol: yazma içerikleri de (kart Ekle/Düzenle ön koşulu var)', in_array('ui.siparis.mail_al', $eski, true) && in_array('ui.siparis.durum_degistir', $eski, true));
t('eski rol: ön koşulu OLMAYAN içerik gelmez (Cari: reports.export yok)', !in_array('ui.siparis.cari', $eski, true) && !in_array('ui.siparis.odeme', $eski, true));
t('eski rol: ayarlar (yazma var) gelir ama sil yetkisiz içerik gelmez', in_array('ui.siparis.ayarlar', $eski, true) && !in_array('ui.siparis.belge_sil', $eski, true));
t('eski rol: Beyan düğmeleri', in_array('ui.beyan.wa_kopyala', $eski, true) && in_array('ui.beyan.taslak_olustur', $eski, true) && in_array('ui.beyan.hks_eslestir', $eski, true));
t('eski rol: Ana Sayfa rozetleri', in_array('ui.dashboard.rozetler', $eski, true));
t('eski rol: Hal Bildirimi yok (hks.access verilmedi)', !in_array('ui.hks.gonder', $eski, true));
$hks = ex(['records.write']);   // hks.access türetilir
t('eski rol: records.write → hks.access → HKS içerikleri', in_array('ui.hks.gonder', $hks, true) && in_array('ui.hks.kisi_havuzu', $hks, true) && in_array('ui.hks.ekr_stok', $hks, true));
$v1 = ex(['role.permissions.detailed', 'role.sections.detailed', 'siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.create']);   // v505'te kaydedilmiş
t('v1 rol (v505): yeni içerik ön koşul izniyle otomatik gelir', in_array('ui.siparis.mail_al', $v1, true) && in_array('ui.siparis.kpi', $v1, true));
t('v1 rol: ön koşulu olmayan gelmez', !in_array('ui.siparis.alan_duzenle', $v1, true) && !in_array('ui.siparis.belge_sil', $v1, true));
$v2 = ex(['role.permissions.detailed', 'role.sections.detailed', 'role.catalog.' . ROLE_CATALOG_VERSION, 'siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.create']);
t('güncel sürümde kaydedilmiş rol: içerik OTOMATİK verilmez', !in_array('ui.siparis.mail_al', $v2, true) && !in_array('ui.siparis.kpi', $v2, true));
// katalog ileride büyürse: since > rolün sürümü olan öğe otomatik gelir (simülasyon)
t('sürüm okuma: işaretleyicisiz=0, kart işaretleyicili=1, katalog işaretleyicili=N', role_catalog_version_of(['x']) === 0 && role_catalog_version_of(['role.sections.detailed']) === 1 && role_catalog_version_of(['role.catalog.' . ROLE_CATALOG_VERSION]) === ROLE_CATALOG_VERSION);
foreach ([$eski, $v1, $v2, $hks] as $i => $x) { $y = ex($x); sort($x); sort($y); t("idempotent #$i", $x === $y); }

// 5) Özet şeması içerik anahtarlarını sayar
$ozet = role_ozet($v2);
$ogl = array_values(array_filter($ozet, static fn($o) => $o['key'] === 'siparis'))[0];
t('özet: OGL kısmi, içerik sayılır', $ogl['duzey'] === 'kismi' && $ogl['toplam'] > 40);
$bos = role_ozet([]);
t('özet: boş rol tüm sayfalar kapalı', count(array_filter($bos, static fn($o) => $o['duzey'] !== 'kapali')) === 0);
$salt = role_ozet(['siparis.ogl.read', 'siparis.ogl_acik.read', 'ui.siparis.kpi']);
t('özet: yalnız Gör + salt içerik = Görüntüleme düzeyi', array_values(array_filter($salt, static fn($o) => $o['key'] === 'siparis'))[0]['duzey'] === 'salt');

// 6) Uç kapıları kaynakta (kapı fonksiyonu çağrılıyor)
foreach (['siparis_yazdir.php' => 'siparis.yazdir', 'siparis_paylas.php' => 'siparis.wp_paylas', 'siparis_zip.php' => 'siparis.zip', 'siparis_cari.php' => 'siparis.cari',
          'siparis_odemeler.php' => 'siparis.odeme', 'siparis_ayarlar.php' => 'siparis.ayarlar', 'siparis_mail.php' => 'siparis.mail_al', 'siparis_yukle.php' => 'siparis.belge_yukle',
          'siparis_eml.php' => 'siparis.mail_taslak', 'siparis_beyan_bagla.php' => 'siparis.sev_beyan', 'halkayit/gonderim_rapor.php' => 'hks.gonderim_raporu'] as $f => $k)
    t("uç kapısı: $f → $k", str_contains((string)file_get_contents($root . '/' . $f), "icerik_kapi('$k')"));
t('siparis_kaydet: işlem → içerik haritası', str_contains((string)file_get_contents($root . '/siparis_kaydet.php'), "'belge_sil' => 'belge_sil'") && str_contains((string)file_get_contents($root . '/siparis_kaydet.php'), "'durum_evrak'"));
t('halkayit api: içerik haritası CSRF öncesi kapıda', str_contains((string)file_get_contents($root . '/halkayit/api.php'), 'hks_eylem_icerigi($action, $g)'));
t('index.php: rozetler içerik yetkisine bağlı', str_contains((string)file_get_contents($root . '/index.php'), "icerik_can('dashboard.rozetler')"));

echo "Role content: $ok geçti, $fail kaldı\n";
exit($fail ? 1 : 0);
