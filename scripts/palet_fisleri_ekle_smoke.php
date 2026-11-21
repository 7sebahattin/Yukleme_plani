<?php
// Palet Fişleri — Ekle penceresi statik denetimi (v534). DB gerektirmez.
declare(strict_types=1);
$pass = 0; $fail = 0;
function ok(string $ad, bool $kosul): void { global $pass, $fail; if ($kosul) { $pass++; echo "PASS $ad\n"; } else { $fail++; echo "FAIL $ad\n"; } }
$php = (string)file_get_contents(__DIR__ . '/../palet_fisleri.php');
$js  = (string)file_get_contents(__DIR__ . '/../assets/palet-fisleri.js');

ok('yalnız yönetici kapısı', str_contains($php, "if (!is_admin()) { forbidden("));
ok('POST yolunda csrf_check', str_contains($php, "csrf_check(\$_POST['csrf'] ?? null)"));
ok('tanim_ekle yalnız dört tür (palet_tipi, firma, urun, kasa_cinsi)', preg_match("/pf_tanim_turleri\(\): array \{\s*return \['palet_tipi' => 'palet', 'firma' => 'müşteri', 'urun' => 'ürün', 'kasa_cinsi' => 'kasa'\];/u", $php) === 1);
ok('tanim_ekle beyaz liste dışı türü reddeder', str_contains($php, "isset(pf_tanim_turleri()[\$tur])"));
ok('yeni tanım audit\'e yazılır', str_contains($php, "audit_log_event('create', 'tanim'"));
ok('kasa tanımı sunucuda doğrulanır', str_contains($php, "type = 'kasa_cinsi' AND is_active = 1"));
ok('dara > brüt sunucuda reddedilir', str_contains($php, "if (\$dara > \$brut) throw new RuntimeException('Dara, brüt ağırlıktan büyük olamaz.')"));
ok('create kasa_cinsi_id yazar (NULL sabit değil)', !str_contains($php, "'', ?, '', ?, ?, ?, NULL, ?, ?, ?, 0, 0"));
ok('Ekle düğmesi etkin, mavi Yeni Palet Fişi yok', str_contains($php, 'id="pfAdd"') && !str_contains($php, 'Yeni Palet Fişi'));
ok('arama kutusu yok', !str_contains($php, 'id="pfSearch"'));
ok('belge no salt okunur', str_contains($php, 'name="belge_no"') && str_contains($php, 'readonly'));
ok('müşteri ve ürün zorunlu', str_contains($php, 'name="firma"') && str_contains($php, 'name="urun"') && str_contains($php, 'required'));
ok('JS: F2 açar, F10 kaydeder', str_contains($js, "event.key === 'F2'") && str_contains($js, "event.key === 'F10'"));
ok('JS: yazdıkça bul + "+ ekle" satırı', str_contains($js, 'function makeCombo') && str_contains($js, "type: 'ekle'"));
ok('JS: kaydedince pencere kapanmaz (sonraki palet hazırlanır)', str_contains($js, 'satirEkle(r.row)') && !str_contains($js, 'location.reload(); }\n        catch'));
ok('JS: hücre çift tıklama ile düzenleme yok', !str_contains($js, "'dblclick'"));
ok('JS: kantardan al yer tutucusu pasif', str_contains($php, 'pf-kantar" disabled'));
// ── Müstahsil (v535) ──
$core = (string)file_get_contents(__DIR__ . '/../config/palet_fisleri_core.php');
$mig  = (string)file_get_contents(__DIR__ . '/../migrate.php');
ok('sayfa kolon EKLEMEZ (ensure_column / ALTER yok)', !str_contains($php, 'ensure_column(') && !str_contains($php, 'ALTER TABLE'));
ok('sayfa kolonu yalnız denetler (palet_fisleri_kolon_var)', str_contains($php, "palet_fisleri_kolon_var(\$pdo, 'mustahsil_id')"));
ok('müstahsil cari kartından doğrulanır (aktif kart)', str_contains($php, 'SELECT unvan FROM cari_kartlar WHERE id = ? AND aktif = 1'));
ok('müstahsil kolonu yoksa INSERT\'e eklenmez', str_contains($php, "\$has_mustahsil ? ', mustahsil_id, mustahsil_ad' : ''"));
ok('sayfa cari kartı YAZMAZ (cari_kart_kaydet çağrısı yok; yazma cari_kaydet.php)', !str_contains($php, 'cari_kart_kaydet(') && str_contains($js, "'cari_kaydet.php'") && str_contains($js, "'kart_kaydet'"));
ok('yeni müstahsil kartı tedarikçi tipinde açılır', str_contains($js, "fd.append('tip', 'tedarikci')"));
ok('müstahsil palet başına: kayıttan sonra temizlenir', str_contains($js, "'aciklama2', 'mustahsil'].forEach"));
ok('migrate: tek çağıran migrate.php kartı (ne=palet_fisleri), açık GO sözleşmesi', str_contains($mig, "'ne'] ?? '') === 'palet_fisleri'") && str_contains($mig, 'palet_fisleri_migrate($pdo)'));
ok('migrasyon yalnız ADD COLUMN/ADD KEY (DROP/MODIFY/UPDATE yok)', !preg_match('/DROP |MODIFY |CHANGE COLUMN|UPDATE |DELETE /i', $core));
ok('migrasyon idempotent (kolon varsa atlar)', str_contains($core, "'durum' => 'var'"));
echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
