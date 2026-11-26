<?php
// =========================================================
// scripts/migrate_panel_smoke.php — Şema Migrasyon Paneli (migrate.php) görünümü: hazır kartlar kapalı, eksik olan açık (v388)
//   php scripts/migrate_panel_smoke.php
// Bellek içi SQLite + stub auth (siparis harness'i); migrate.php GERÇEKTEN çalıştırılır. Canlı DB'ye HİÇ dokunmaz.
// Panelin amacı DEĞİŞMEDİ: hiçbir kart / POST dalı kaldırılmadı (kaynak denetimi aşağıda).
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/siparis_yazdir.php';

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();

class MigExit extends RuntimeException {}
function migt_header(string $h, bool $replace = true, int $code = 0): void {}
function migt_code(?int $c = null) { return 200; }
function mig_calistir(string $tmp): void { include $tmp; }
function mig_sayfa(array $post = []): array
{
    global $ROOT;
    $src = (string)file_get_contents($ROOT . '/migrate.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'migt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'migt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new MigExit();', $src);
    $tmp = sys_get_temp_dir() . '/mig_panel_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    $_GET = []; $_POST = $post; $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    ob_start(); $hata = null;
    try { mig_calistir($tmp); } catch (MigExit $e) {} catch (Throwable $e) { $hata = $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $html = (string)ob_get_clean();
    @unlink($tmp);
    return ['html' => $html, 'hata' => $hata];
}
function rol(array $perms, bool $admin = false): void { global $PERMS, $IS_ADMIN; $PERMS = $perms; $IS_ADMIN = $admin; }
function csrf(): string { if (session_status() === PHP_SESSION_NONE) session_start(); return csrf_token(); }

echo "=== 1. Kaynak: hiçbir kart / POST dalı kaldırılmadı ===\n";
$kaynak = (string)file_get_contents($ROOT . '/migrate.php');
ok('47 kart sarıldı (mg_kart_ac / mg_kart_kapat eşli)', preg_match_all("/<\?php mg_kart_ac\(/", $kaynak) === 47 && preg_match_all('/<\?php mg_kart_kapat\(\); \?>/', $kaynak) === 47);
ok('sarılmamış üst düzey .card kalmadı', preg_match('/^  <div class="card"/m', $kaynak) === 0);
foreach (['pdks', 'pdks_gunluk', 'pdks_hakedis', 'pdks_cari', 'pdks_gunluk_faz8a', 'pdks_faz8b', 'pdks_gunluk_faz8j', 'pdks_faz9d', 'pdks_cavus_ucret', 'pdks_cavus_b', 'pdks_kart_tanim', 'pdks_ters', 'pdks_saat', 'pdks_yevmiye', 'pdks_servis', 'mail', 'siparis', 'siparis_sofor', 'siparis_yurtici_gumruk', 'siparis_odeme', 'siparis_evrak_gecis', 'urun_siparis', 'frugro', 'cari', 'stok', 'fatura', 'kasa', 'doviz', 'ceksenet', 'puantaj_isaret', 'puantaj_giris', 'beyansiz_yukleme', 'cekirdek_sema', 'kantar_fisno_uniq', 'perf_indeksler', 'genel_muhasebe', 'edonusum', 'erp_mutabakat'] as $ne) {
    ok("POST dalı duruyor: ne=$ne (csrf_check ile)", (bool)preg_match("/'" . preg_quote($ne, '/') . "'\)\s*\{\s*\n\s*csrf_check/", $kaynak));
}
ok('POST sonrası hiçbir kart kapanmaz (sonuç satırları görünür kalsın)', str_contains($kaynak, "=== 'POST'") && str_contains($kaynak, "\$hazir && !\$post ? '' : ' open'"));

echo "\n=== 2. Render — hiçbir tablo yokken (SQLite, boş) ===\n";
rol([], true);
$r = mig_sayfa();
ok('sayfa hatasız çizilir', $r['hata'] === null && str_contains($r['html'], 'Şema Migrasyon Paneli'), (string)$r['hata']);
$say = substr_count($r['html'], '<details class="card mg-kart');
ok('47 kart <details> olarak çizilir', $say === 47, (string)$say);
ok('cari kurulu değilken Kasa kartında önkoşul uyarısı (Cari İşlemler) görünür', str_contains($r['html'], 'Kasa sayfaları bu şema olmadan açılmaz'));
ok('cari + kasa kurulu değilken Çek-Senet kartında önkoşul uyarısı (Cari İşlemler, Kasa) görünür', str_contains($r['html'], 'Çek-Senet sayfaları bu şemalar olmadan açılmaz') && str_contains($r['html'], 'Cari İşlemler, Kasa'));
ok('tablo yokken kartlar EKSİK + AÇIK (kapalı hazır kart yok)', substr_count($r['html'], 'mg-hazir') === 0 && substr_count($r['html'], ' open style=') === 47, substr_count($r['html'], 'mg-hazir') . '/' . substr_count($r['html'], ' open style='));
ok('özet satırı: 0 / 47 hazır, düğme var', str_contains($r['html'], '0 / 47 kart hazır') && str_contains($r['html'], 'id="mgTumu"'));
ok('kartların kimlikleri korunur (migrate.php#siparis-sofor vb. bağlantılar)', str_contains($r['html'], 'id="beyansiz-yukleme"') && str_contains($r['html'], 'id="cari"') && str_contains($r['html'], 'id="stok"') && str_contains($r['html'], 'id="fatura"') && str_contains($r['html'], 'id="kasa"') && str_contains($r['html'], 'id="doviz"') && str_contains($r['html'], 'id="ceksenet"') && str_contains($r['html'], 'id="puantaj-isaret"') && str_contains($r['html'], 'id="puantaj-giris"') && str_contains($r['html'], 'id="siparis"') && str_contains($r['html'], 'id="siparis-sofor"') && str_contains($r['html'], 'id="siparis-yurtici-gumruk"') && str_contains($r['html'], 'id="siparis-evrak-gecis"') && str_contains($r['html'], 'id="siparis-odeme"') && str_contains($r['html'], 'id="cekirdek-sema"') && str_contains($r['html'], 'id="kantar-fisno-uniq"') && str_contains($r['html'], 'id="perf-indeksler"') && str_contains($r['html'], 'id="genel-muhasebe"') && str_contains($r['html'], 'id="edonusum"') && str_contains($r['html'], 'id="erp-mutabakat"'));
ok('başlık özet satırında, gövdede h2 kalmadı', str_contains($r['html'], '<span class="mg-baslik">Siparişler — Yurt içi gümrük</span>') && !str_contains($r['html'], '<h2 style="margin-top:0;">Siparişler'));
ok('formlar + düğmeler yerinde (kurulum yolu bozulmadı)', str_contains($r['html'], 'Yurt İçi Gümrük Alanını Ekle') && str_contains($r['html'], 'name="ne" value="siparis_sofor"'));
ok('HTML blok dengesi (details/div)', substr_count($r['html'], '<details') === substr_count($r['html'], '</details>') && substr_count($r['html'], '<div') === substr_count($r['html'], '</div>'));

echo "\n=== 3. Render — Siparişler şeması hazırken o kartlar kapanır ===\n";
foreach (siparis_tablolar() as $sql) { [$c, $idx] = siparis_ddl_sqlite($sql); db()->exec($c); foreach ($idx as $i) db()->exec($i); }
siparis_sofor_migrate(db()); siparis_yurtici_gumruk_migrate(db());
$r = mig_sayfa();
ok('hazır Siparişler kartları kapalı + ✓ Hazır rozeti', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-hazir" id="siparis-sofor" style=/', $r['html']) === 1
    && preg_match('/<details class="card mg-kart mg-hazir" id="siparis-yurtici-gumruk" style=/', $r['html']) === 1
    && preg_match('/<details class="card mg-kart mg-hazir" id="siparis" style=/', $r['html']) === 1, (string)$r['hata']);
ok('v400: taşınacak sevkiyat yokken Evrak takımı geçiş kartı HAZIR (kapalı, ✓ Taşınacak sevkiyat yok, form yok)', preg_match('/<details class="card mg-kart mg-hazir" id="siparis-evrak-gecis" style=/', $r['html']) === 1
    && str_contains($r['html'], 'Taşınacak sevkiyat yok') && !str_contains($r['html'], 'name="ne" value="siparis_evrak_gecis"'));
ok('hazır kartta "open" YOK, eksik kartta var', preg_match('/mg-hazir" id="siparis-sofor"[^>]*\sopen\s/', $r['html']) === 0 && preg_match('/mg-eksik"[^>]*\sopen\s/', $r['html']) === 1);
ok('özet satırı sayıları tutarlı', preg_match('#(\d+) / 47 kart hazır#', $r['html'], $m) === 1 && (int)$m[1] === substr_count($r['html'], 'class="card mg-kart mg-hazir"') && (int)$m[1] >= 3, $m[0] ?? '');

echo "\n=== 4. POST sonrası kart kapatılmaz ===\n";
$tok = csrf();
$r = mig_sayfa(['csrf' => $tok, 'ne' => 'siparis_yurtici_gumruk']);
ok('POST yanıtında hazır kart bile AÇIK (sonuç görünür)', $r['hata'] === null && preg_match('/mg-hazir" id="siparis-yurtici-gumruk"[^>]*\sopen\s/', $r['html']) === 1 && str_contains($r['html'], 'Son çalıştırma sonucu') && str_contains($r['html'], 'Kolon zaten mevcut'), (string)$r['hata']);

echo "\n=== 5. v400 — Evrak takımı tek seferlik geçiş kartı ===\n";
$lid = (int)siparis_sevkiyat_elle_ac(db(), 'TC900801.10', null, null, 1)['id'];
db()->exec("UPDATE siparis_sevkiyatlar SET durum = 'mail_gonderildi', mail_gonderildi_at = '2026-01-02 09:00:00' WHERE id = $lid");   // v400 öncesi kapanmış (işaretsiz) sevkiyat
$r = mig_sayfa();
ok('legacy mail_gonderildi → kart EKSİK + AÇIK, "1 sevkiyat" bekliyor, aday listesi + taşı formu', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="siparis-evrak-gecis"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], '1 sevkiyat taşınmayı bekliyor') && str_contains($r['html'], 'TC900801.10') && str_contains($r['html'], 'name="ne" value="siparis_evrak_gecis"') && str_contains($r['html'], "1 sevkiyatı Kapanan'a taşı"), (string)$r['hata']);
ok('v400: kart özeti sayaç tutarlı (47 kart, hazır sayısı bir azaldı)', preg_match('#(\d+) / 47 kart hazır#', $r['html'], $m5) === 1 && (int)$m5[1] === substr_count($r['html'], 'class="card mg-kart mg-hazir"'), $m5[0] ?? '');
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'siparis_evrak_gecis']);
ok('POST: "Son çalıştırma sonucu" + "1 sevkiyat taşındı, kalan 0"; kart açık kalır', $r['hata'] === null && str_contains($r['html'], 'Son çalıştırma sonucu') && str_contains($r['html'], '1 sevkiyat taşındı, kalan 0')
    && preg_match('/id="siparis-evrak-gecis"[^>]*\sopen\s/', $r['html']) === 1, (string)$r['hata']);
ok('veri: sevkiyat evrak_gonderildi oldu', db()->query("SELECT durum FROM siparis_sevkiyatlar WHERE id = $lid")->fetchColumn() === 'evrak_gonderildi');
$r = mig_sayfa();
ok('sonraki GET: kart yine HAZIR (kapalı)', preg_match('/<details class="card mg-kart mg-hazir" id="siparis-evrak-gecis" style=/', $r['html']) === 1 && str_contains($r['html'], 'Taşınacak sevkiyat yok'));

echo "\n=== 6. Cari İşlemler kartı (ne=cari) ===\n";
$r = mig_sayfa();
ok('tablolar yokken cari kartı EKSİK + AÇIK, ✗ Tablolar eksik, form ne=cari', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="cari"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], 'name="ne" value="cari"') && str_contains($r['html'], '✗ Tablolar eksik'), (string)$r['hata']);
$GLOBALS['_cari_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'cari']);
ok('POST ne=cari: 3 tablo oluşturuldu, sonuç satırları görünür, genel kolon migrasyonu ÇALIŞMADI', $r['hata'] === null
    && str_contains($r['html'], 'Son çalıştırma sonucu') && substr_count($r['html'], 'cari_kartlar: olusturuldu') === 1 && !str_contains($r['html'], 'Çalıştırma Sonucu</h2>'), (string)$r['hata']);
ok('veri: 3 tablo + ödeme şekli tohumu 6', cari_hazir(db()) && siparis_test_say(db(), 'cari_tanimlar', "tur = 'odeme_sekli'") === 6);
$r = mig_sayfa();
ok('sonraki GET: cari kartı HAZIR (kapalı, ✓ Hazır)', preg_match('/<details class="card mg-kart mg-hazir" id="cari" style=/', $r['html']) === 1);

echo "\n=== 7. Stok (muhasebe) kartı (ne=stok) ===\n";
$r = mig_sayfa();
ok('tablolar yokken stok kartı EKSİK + AÇIK, ✗ Tablolar eksik, form ne=stok', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="stok"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], 'name="ne" value="stok"') && str_contains($r['html'], 'Stok (muhasebe)'), (string)$r['hata']);
$GLOBALS['_stok_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'stok']);
ok('POST ne=stok: 5 tablo oluşturuldu, sonuç satırları görünür, genel kolon migrasyonu ÇALIŞMADI', $r['hata'] === null
    && str_contains($r['html'], 'Son çalıştırma sonucu') && substr_count($r['html'], 'stok_hareketler: olusturuldu') === 1 && !str_contains($r['html'], 'Çalıştırma Sonucu</h2>'), (string)$r['hata']);
ok('veri: 5 tablo + tanım tohumu (1 grup, 4 giriş, 6 çıkış nedeni)', stok_hazir(db()) && siparis_test_say(db(), 'stok_tanimlar', "tur = 'grup'") === 1
    && siparis_test_say(db(), 'stok_tanimlar', "tur = 'giris_neden'") === 4 && siparis_test_say(db(), 'stok_tanimlar', "tur = 'cikis_neden'") === 6);
$r = mig_sayfa();
ok('sonraki GET: stok kartı HAZIR (kapalı, ✓ Hazır)', preg_match('/<details class="card mg-kart mg-hazir" id="stok" style=/', $r['html']) === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'stok']);
ok('ikinci POST ne=stok: hepsi "var" (idempotent), tohum çoğalmaz', $r['hata'] === null && substr_count($r['html'], ': var — Tablo zaten mevcut.') >= 5 && siparis_test_say(db(), 'stok_tanimlar') === 11);

echo "\n=== 8. Fatura kartı (ne=fatura) ===\n";
$r = mig_sayfa();
ok('tablolar yokken fatura kartı EKSİK + AÇIK, ✗ Tablolar eksik, form ne=fatura, kart stok kartından SONRA', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="fatura"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], 'name="ne" value="fatura"') && str_contains($r['html'], 'Fatura Tablolarını Oluştur')
    && strpos($r['html'], 'id="fatura"') > strpos($r['html'], 'id="stok"'), (string)$r['hata']);
ok('cari + stok kurulu → Fatura kartında önkoşul uyarısı YOK (kart kasa kartından önce biter)', !str_contains(substr($r['html'], (int)strpos($r['html'], 'id="fatura"'), (int)strpos($r['html'], 'id="kasa"') - (int)strpos($r['html'], 'id="fatura"')), 'Önce şu kart(lar) çalıştırılmalı'));
$GLOBALS['_fatura_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'fatura']);
ok('POST ne=fatura: 3 tablo oluşturuldu, sonuç satırları görünür, genel kolon migrasyonu ÇALIŞMADI', $r['hata'] === null
    && str_contains($r['html'], 'Son çalıştırma sonucu') && substr_count($r['html'], 'fatura_belgeler: olusturuldu') === 1 && substr_count($r['html'], 'fatura_satirlar: olusturuldu') === 1
    && substr_count($r['html'], 'fatura_tanimlar: olusturuldu') === 1 && !str_contains($r['html'], 'Çalıştırma Sonucu</h2>'), (string)$r['hata']);
$tsay = fn(string $t): int => siparis_test_say(db(), 'fatura_tanimlar', 'tur = ?', [$t]);
ok('veri: 3 tablo + tanım tohumu 26 (kdv 4, tevkifat 4, stopaj 1, bagkur 1, istisna 1, seri 3, teslim 11, sablon 1)', fatura_hazir(db()) && siparis_test_say(db(), 'fatura_tanimlar') === 26
    && $tsay('kdv') === 4 && $tsay('tevkifat') === 4 && $tsay('stopaj') === 1 && $tsay('bagkur') === 1 && $tsay('istisna') === 1 && $tsay('seri') === 3 && $tsay('teslim') === 11 && $tsay('sablon') === 1);
$r = mig_sayfa();
ok('sonraki GET: fatura kartı HAZIR (kapalı, ✓ Hazır)', preg_match('/<details class="card mg-kart mg-hazir" id="fatura" style=/', $r['html']) === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'fatura']);
ok('ikinci POST ne=fatura: hepsi "var" (idempotent), tohum çoğalmaz', $r['hata'] === null && substr_count($r['html'], 'fatura_tanimlar: var — Tablo zaten mevcut.') === 1
    && substr_count($r['html'], 'fatura_belgeler: var — Tablo zaten mevcut.') === 1 && siparis_test_say(db(), 'fatura_tanimlar') === 26);

echo "\n=== 9. Kasa kartı (ne=kasa) ===\n";
$r = mig_sayfa();
ok('tablolar yokken kasa kartı EKSİK + AÇIK, ✗ Tablolar eksik, form ne=kasa, kart fatura kartından SONRA', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="kasa"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], 'name="ne" value="kasa"') && str_contains($r['html'], 'Kasa Tablolarını Oluştur')
    && strpos($r['html'], 'id="kasa"') > strpos($r['html'], 'id="fatura"'), (string)$r['hata']);
ok('cari kurulu → Kasa önkoşul uyarısı YOK', !str_contains($r['html'], 'Kasa sayfaları bu şema olmadan açılmaz'));
$GLOBALS['_kasa_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'kasa']);
ok('POST ne=kasa: 3 tablo oluşturuldu, sonuç satırları görünür, genel kolon migrasyonu ÇALIŞMADI', $r['hata'] === null
    && str_contains($r['html'], 'Son çalıştırma sonucu') && substr_count($r['html'], 'kasa_kartlar: olusturuldu') === 1 && substr_count($r['html'], 'kasa_hareketler: olusturuldu') === 1
    && substr_count($r['html'], 'kasa_tanimlar: olusturuldu') === 1 && !str_contains($r['html'], 'Çalıştırma Sonucu</h2>'), (string)$r['hata']);
ok('veri: 3 tablo + kalem tohumu (2 gelir + 6 gider), kasa kartı tohumu YOK', kasa_hazir(db()) && siparis_test_say(db(), 'kasa_tanimlar') === 8
    && siparis_test_say(db(), 'kasa_tanimlar', "tur = 'gelir'") === 2 && siparis_test_say(db(), 'kasa_tanimlar', "tur = 'gider'") === 6
    && siparis_test_say(db(), 'kasa_kartlar') === 0 && siparis_test_say(db(), 'kasa_hareketler') === 0);
$r = mig_sayfa();
ok('sonraki GET: kasa kartı HAZIR (kapalı, ✓ Hazır)', preg_match('/<details class="card mg-kart mg-hazir" id="kasa" style=/', $r['html']) === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'kasa']);
ok('ikinci POST ne=kasa: hepsi "var" (idempotent), tohum çoğalmaz', $r['hata'] === null && substr_count($r['html'], 'kasa_tanimlar: var — Tablo zaten mevcut.') === 1
    && substr_count($r['html'], 'kasa_hareketler: var — Tablo zaten mevcut.') === 1 && siparis_test_say(db(), 'kasa_tanimlar') === 8);

echo "\n=== 9b. Döviz & Günlük Kurlar kartı (ne=doviz) ===\n";
$r = mig_sayfa();
ok('tablolar yokken doviz kartı EKSİK + AÇIK, ✗ Tablolar eksik, form ne=doviz, kart kasa kartından SONRA', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="doviz"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], 'name="ne" value="doviz"') && str_contains($r['html'], 'Döviz &amp; Kur Tablolarını Oluştur')
    && strpos($r['html'], 'id="doviz"') > strpos($r['html'], 'id="kasa"'), (string)$r['hata']);
$GLOBALS['_doviz_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'doviz']);
ok('POST ne=doviz: 2 tablo oluşturuldu, sonuç satırları görünür, genel kolon migrasyonu ÇALIŞMADI', $r['hata'] === null
    && str_contains($r['html'], 'Son çalıştırma sonucu') && substr_count($r['html'], 'doviz_tanimlar: olusturuldu') === 1 && substr_count($r['html'], 'doviz_kurlar: olusturuldu') === 1
    && !str_contains($r['html'], 'Çalıştırma Sonucu</h2>'), (string)$r['hata']);
ok('veri: 2 tablo + 8 para birimi tohumu (4 aktif + 4 pasif), kur satırı YOK', doviz_hazir(db()) && siparis_test_say(db(), 'doviz_tanimlar') === 8
    && siparis_test_say(db(), 'doviz_tanimlar', 'aktif = 1') === 4 && siparis_test_say(db(), 'doviz_kurlar') === 0);
$r = mig_sayfa();
ok('sonraki GET: doviz kartı HAZIR (kapalı, ✓ Hazır)', preg_match('/<details class="card mg-kart mg-hazir" id="doviz" style=/', $r['html']) === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'doviz']);
ok('ikinci POST ne=doviz: hepsi "var" (idempotent), tohum çoğalmaz', $r['hata'] === null && substr_count($r['html'], 'doviz_tanimlar: var — Tablo zaten mevcut.') === 1
    && substr_count($r['html'], 'doviz_kurlar: var — Tablo zaten mevcut.') === 1 && siparis_test_say(db(), 'doviz_tanimlar') === 8);

echo "\n=== 10. Çek-Senet kartı (ne=ceksenet) ===\n";
$r = mig_sayfa();
ok('tablolar yokken çek-senet kartı EKSİK + AÇIK, ✗ Tablolar eksik, form ne=ceksenet, kart kasa kartından SONRA', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="ceksenet"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], 'name="ne" value="ceksenet"') && str_contains($r['html'], 'Çek-Senet Tablolarını Oluştur')
    && strpos($r['html'], 'id="ceksenet"') > strpos($r['html'], 'id="kasa"'), (string)$r['hata']);
ok('cari + kasa kurulu → Çek-Senet önkoşul uyarısı YOK', !str_contains($r['html'], 'Çek-Senet sayfaları bu şemalar olmadan açılmaz'));
$GLOBALS['_ceksenet_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'ceksenet']);
ok('POST ne=ceksenet: 3 tablo oluşturuldu, sonuç satırları görünür, genel kolon migrasyonu ÇALIŞMADI', $r['hata'] === null
    && str_contains($r['html'], 'Son çalıştırma sonucu') && substr_count($r['html'], 'ceksenet_kayitlar: olusturuldu') === 1 && substr_count($r['html'], 'ceksenet_hareketler: olusturuldu') === 1
    && substr_count($r['html'], 'ceksenet_tanimlar: olusturuldu') === 1 && !str_contains($r['html'], 'Çalıştırma Sonucu</h2>'), (string)$r['hata']);
ok('veri: 3 tablo + banka tohumu (10), karne / kayıt / hareket YOK', ceksenet_hazir(db()) && siparis_test_say(db(), 'ceksenet_tanimlar') === 10 && siparis_test_say(db(), 'ceksenet_tanimlar', "tur = 'banka'") === 10
    && siparis_test_say(db(), 'ceksenet_kayitlar') === 0 && siparis_test_say(db(), 'ceksenet_hareketler') === 0);
$r = mig_sayfa();
ok('sonraki GET: çek-senet kartı HAZIR (kapalı, ✓ Hazır)', preg_match('/<details class="card mg-kart mg-hazir" id="ceksenet" style=/', $r['html']) === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'ceksenet']);
ok('ikinci POST ne=ceksenet: hepsi "var" (idempotent), tohum çoğalmaz', $r['hata'] === null && substr_count($r['html'], 'ceksenet_tanimlar: var — Tablo zaten mevcut.') === 1
    && substr_count($r['html'], 'ceksenet_hareketler: var — Tablo zaten mevcut.') === 1 && siparis_test_say(db(), 'ceksenet_tanimlar') === 10);

echo "\n=== 11. Puantaj İşaretleri kartı (ne=puantaj_isaret, v553) ===\n";
$r = mig_sayfa();
ok('tablo yokken puantaj-isaret kartı EKSİK + AÇIK, ✗ Tablo eksik, form ne=puantaj_isaret, kart çek-senet kartından SONRA', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="puantaj-isaret"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], 'name="ne" value="puantaj_isaret"') && str_contains($r['html'], 'Puantaj İşaret Tablosunu Oluştur') && str_contains($r['html'], '✗ Tablo eksik')
    && strpos($r['html'], 'id="puantaj-isaret"') > strpos($r['html'], 'id="ceksenet"'), (string)$r['hata']);
ok('başlık özet satırında ("Puantaj İşaretleri")', str_contains($r['html'], '<span class="mg-baslik">Puantaj İşaretleri</span>'));
$GLOBALS['_pi_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'puantaj_isaret']);
ok('POST ne=puantaj_isaret: tablo oluşturuldu, sonuç satırı görünür, kart AÇIK kalır, genel kolon migrasyonu ÇALIŞMADI', $r['hata'] === null
    && str_contains($r['html'], 'Son çalıştırma sonucu') && substr_count($r['html'], 'puantaj_isaretler: olusturuldu') === 1 && !str_contains($r['html'], 'Çalıştırma Sonucu</h2>')
    && preg_match('/id="puantaj-isaret"[^>]*\sopen\s/', $r['html']) === 1, (string)$r['hata']);
ok('veri: tablo var, boş (tohum YOK); UNIQUE(employee_id, tarih) çalışır', pi_hazir(db()) && siparis_test_say(db(), 'puantaj_isaretler') === 0
    && (function () { db()->exec("INSERT INTO puantaj_isaretler (employee_id, tarih, kod, created_at) VALUES (1, '2026-11-03', 'D', '2026-11-01 00:00:00')"); try { db()->exec("INSERT INTO puantaj_isaretler (employee_id, tarih, kod, created_at) VALUES (1, '2026-11-03', 'R', '2026-11-01 00:00:00')"); return false; } catch (PDOException $e) { return true; } })());
db()->exec('DELETE FROM puantaj_isaretler');
$r = mig_sayfa();
ok('sonraki GET: puantaj-isaret kartı HAZIR (kapalı, ✓ Hazır)', preg_match('/<details class="card mg-kart mg-hazir" id="puantaj-isaret" style=/', $r['html']) === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'puantaj_isaret']);
ok('ikinci POST: "var" (idempotent)', $r['hata'] === null && substr_count($r['html'], 'puantaj_isaretler: var — Tablo zaten mevcut.') === 1);

echo "\n=== 12. Puantaj Girişi kartı (ne=puantaj_giris, v554) ===\n";
$r = mig_sayfa();
ok('tablolar yokken puantaj-giris kartı EKSİK + AÇIK, ✗ Tablolar eksik, form ne=puantaj_giris, kart puantaj-isaret kartından SONRA', $r['hata'] === null
    && preg_match('/<details class="card mg-kart mg-eksik" id="puantaj-giris"[^>]*\sopen\s/', $r['html']) === 1
    && str_contains($r['html'], 'name="ne" value="puantaj_giris"') && str_contains($r['html'], 'Puantaj Girişi Tablolarını Oluştur') && str_contains($r['html'], '✗ Tablolar eksik')
    && strpos($r['html'], 'id="puantaj-giris"') > strpos($r['html'], 'id="puantaj-isaret"'), (string)$r['hata']);
ok('başlık özet satırında ("Puantaj Girişi")', str_contains($r['html'], '<span class="mg-baslik">Puantaj Girişi</span>'));
$GLOBALS['_pg_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'puantaj_giris']);
ok('POST ne=puantaj_giris: İKİ tablo oluşturuldu, sonuç satırları görünür, kart AÇIK kalır, genel kolon migrasyonu ÇALIŞMADI', $r['hata'] === null
    && str_contains($r['html'], 'Son çalıştırma sonucu') && substr_count($r['html'], 'puantaj_giris_kisiler: olusturuldu') === 1 && substr_count($r['html'], 'puantaj_giris_gunler: olusturuldu') === 1 && !str_contains($r['html'], 'Çalıştırma Sonucu</h2>')
    && preg_match('/id="puantaj-giris"[^>]*\sopen\s/', $r['html']) === 1, (string)$r['hata']);
ok('veri: iki tablo var, boş (tohum YOK); UNIQUE(kisi_id, tarih) çalışır; employees / puantaj_isaretler tablosuna DOKUNULMADI', pg_hazir(db()) && siparis_test_say(db(), 'puantaj_giris_kisiler') === 0 && siparis_test_say(db(), 'puantaj_giris_gunler') === 0
    && (function () { db()->exec("INSERT INTO puantaj_giris_gunler (kisi_id, tarih, kod, created_at) VALUES (1, '2026-11-03', 'X', '2026-11-01 00:00:00')"); try { db()->exec("INSERT INTO puantaj_giris_gunler (kisi_id, tarih, kod, created_at) VALUES (1, '2026-11-03', 'Y', '2026-11-01 00:00:00')"); return false; } catch (PDOException $e) { return true; } })());
db()->exec('DELETE FROM puantaj_giris_gunler');
$r = mig_sayfa();
ok('sonraki GET: puantaj-giris kartı HAZIR (kapalı, ✓ Hazır)', preg_match('/<details class="card mg-kart mg-hazir" id="puantaj-giris" style=/', $r['html']) === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'puantaj_giris']);
ok('ikinci POST: iki tablo da "var" (idempotent)', $r['hata'] === null && substr_count($r['html'], 'puantaj_giris_kisiler: var — Tablo zaten mevcut.') === 1 && substr_count($r['html'], 'puantaj_giris_gunler: var — Tablo zaten mevcut.') === 1);

echo "\n=== v575. Depolar kartı: gruplu rapor + çoklu ad aktarımı (gerçek POST) ===\n";
rol([], true);
$GLOBALS['_depo_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void { [$c, $ix] = siparis_ddl_sqlite($ddl); $p->exec($c); foreach ($ix as $x) $p->exec($x); };
$d0 = depo_migrate(db());
db()->exec('CREATE TABLE IF NOT EXISTS ters_sayim (id INTEGER PRIMARY KEY, depo TEXT)');
db()->exec('CREATE TABLE IF NOT EXISTS loading_pallets (id INTEGER PRIMARY KEY, depo TEXT)');
db()->exec('CREATE TABLE IF NOT EXISTS pdks_yedek_20260924_daily_work_sessions (id INTEGER PRIMARY KEY, depo TEXT)');
db()->exec("INSERT INTO ters_sayim (depo) VALUES ('FİNİKE'),('FİNİKE'),('Finike')");
db()->exec("INSERT INTO loading_pallets (depo) VALUES ('FİNİKE'),('KARAMAN CİHAT')");
db()->exec("INSERT INTO pdks_yedek_20260924_daily_work_sessions (depo) VALUES ('FİNİKE')");
depo_onbellek_sifirla();
$yeniId = depo_ekle(db(), ['ad' => 'Merkez Depo'])['id'];
$r = mig_sayfa();
ok('Depolar kartı hatasız çizilir', $r['hata'] === null, (string)$r['hata']);
ok('rapor: büyük/küçük harf farkı TEK satırda birleşir (ters_sayim.depo FİNİKE = 3)', preg_match('#ters_sayim\.depo</code></td><td>(?:FİNİKE|Finike)</td><td style="text-align:right">3</td>#u', $r['html']) === 1);
ok('rapor: yedek tablo gizli (+ N satır yedek notu), listede pdks_yedek tablosu YOK', str_contains($r['html'], 'yedek tablolarında') && !str_contains($r['html'], 'pdks_yedek_20260924_daily_work_sessions.depo'));
ok('form: eski ad ÇOKLU seçim (eski_ad[]), canlı tablo işaretli, eski tablo işaretsiz',
    str_contains($r['html'], 'name="eski_ad[]"') && preg_match('#name="tablolar\[\]" value="ters_sayim" checked#', $r['html']) === 1 && preg_match('#name="tablolar\[\]" value="loading_pallets" >#', $r['html']) === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'depo', 'adim' => 'aktar', 'eski_ad' => ['FİNİKE', 'KARAMAN CİHAT'], 'tablolar' => ['ters_sayim'], 'yeni_depo' => (string)$yeniId]);
ok('POST aktar: sipariş satırları (3) yeni depoya taşındı, sonuç mesajı tablo.kolon ile görünür', $r['hata'] === null && str_contains($r['html'], '✓ 3 satır') && str_contains($r['html'], 'ters_sayim.depo: 3')
    && siparis_test_say(db(), 'ters_sayim', "depo = 'MERKEZ DEPO'") === 3, (string)$r['hata']);
ok('seçilmeyen tablo (loading_pallets) DOKUNULMADI', siparis_test_say(db(), 'loading_pallets', "depo = 'FİNİKE'") === 1 && siparis_test_say(db(), 'loading_pallets', "depo = 'KARAMAN CİHAT'") === 1);
$r = mig_sayfa(['csrf' => csrf(), 'ne' => 'depo', 'adim' => 'aktar', 'eski_ad' => ['FİNİKE'], 'tablolar' => [], 'yeni_depo' => (string)$yeniId]);
ok('tablo seçilmeden POST: ✗ uyarı, hiçbir şey değişmedi', str_contains($r['html'], '✗ Aktarılacak tablo seçilmedi') && siparis_test_say(db(), 'loading_pallets', "depo = 'FİNİKE'") === 1);

echo "\n=== 13. Sekmeli görünüm (Hazır Olmayanlar / Hazır Olanlar) ===\n";
$r = mig_sayfa();
$hazirSayi = substr_count($r['html'], 'class="card mg-kart mg-hazir"');
$eksikSayi = 47 - $hazirSayi;
ok('iki sekme düğmesi var: Hazır Olmayanlar ve Hazır Olanlar',
    str_contains($r['html'], 'data-hedef="eksik"') && str_contains($r['html'], 'data-hedef="hazir"')
    && str_contains($r['html'], 'Hazır Olmayanlar') && str_contains($r['html'], 'Hazır Olanlar'));
ok('sekme rozet sayıları kart sayılarıyla eşleşir',
    str_contains($r['html'], 'mg-sekme-sayi--eksik">' . $eksikSayi . '</span>')
    && str_contains($r['html'], 'mg-sekme-sayi--ok">' . $hazirSayi . '</span>'));
ok('eksik kart varken varsayılan sekme "eksik"', str_contains($r['html'], 'id="mgPanel" data-sekme="eksik"'));
ok('kartlarda data-durum="hazir" / "eksik" sayısı tutarlı',
    substr_count($r['html'], 'style="margin:16px 0;padding:0;" data-durum="hazir"') === $hazirSayi
    && substr_count($r['html'], 'style="margin:16px 0;padding:0;" data-durum="eksik"') === $eksikSayi);

siparis_test_bitir();
