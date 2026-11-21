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
ok('JS: satıra çift tık Düzelt penceresini açar; hücre içinde düzenleme (beginEdit) tetiklenmez', preg_match("/body\.addEventListener\('dblclick'[^}]*openDialog\('duzelt'\)/s", $js) === 1 && !preg_match("/body\.addEventListener\('dblclick'[^}]*beginEdit/s", $js));
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
// ── Araç çubuğu işlevleri (v536) ──
ok('hiçbir araç pasif değil (data-pf-pasif yok)', !str_contains($php, 'data-pf-pasif') && !str_contains($js, 'data-pf-pasif') && !str_contains($js, 'henüz çalışmıyor'));
foreach (['coglat', 'sil', 'bul', 'duzelt', 'yazdir'] as $arac) ok("araç bağlı: $arac", str_contains($php, "data-pf=\"$arac\"") && str_contains($js, $arac));
ok('Sil: F3 + Delete, Düzelt: F4', str_contains($js, "event.key === 'F3'") && str_contains($js, "event.key === 'F4'"));
ok('Sil onay ister ve sunucuya delete gönderir', str_contains($js, "window.confirm(") && str_contains($js, "send('delete'"));
ok('Çoğalt sunucu satırını listeye ekler (yeniden yükleme yok)', str_contains($js, "send('duplicate'") && !str_contains($js, 'location.reload'));
ok('Düzelt tam kayıt kaydeder (tam=1) ve müstahsili yalnız bayrakla değiştirir', str_contains($js, 'tam: 1') && str_contains($js, 'mustahsil_duzenle') && str_contains($php, "!empty(\$_POST['mustahsil_duzenle'])"));
ok('Filtre: başlangıç tarihi + yüklenen göster (GET, yazma yok)', str_contains($php, "\$_GET['baslangic']") && str_contains($php, "COALESCE(r.durum, '') <> 'yuklendi'") && str_contains($php, 'name="baslangic"'));
ok('Excel: reports.export kapısı + export_audit + csv/xlsx', str_contains($php, "require_perm('reports.export')") && str_contains($php, "export_audit('palet_fisleri'") && str_contains($php, 'xlsx_indir(') && str_contains($php, 'export_menu('));
ok('CSV: BOM + ; ayracı + binlik ayraçsız', str_contains($php, 'xEF') && str_contains($php, "fputcsv(\$out, \$x, ';'") && str_contains($php, "number_format((float)\$x[\$i], 3, ',', '')"));
ok('Yazdır gizli çerçeve kullanır (pencere açmaz)', str_contains($js, "createElement('iframe')") && str_contains($js, 'contentWindow.print()'));
ok('WhatsApp ve Parti Özeti düğmeleri kaldırıldı', !str_contains($php, 'data-pf="wp"') && !str_contains($php, 'data-pf="ozet"') && !str_contains($js, 'wa.me'));
ok('Kartlara tıklayınca partinin palet listesi açılır', str_contains($php, 'data-pf-liste') && str_contains($php, 'id="pfOzetDialog"') && str_contains($js, 'function partiListesiAc') && str_contains($js, "'[data-pf-liste]'"));
ok('Getir düğmesi yok; tarih/yüklenen değişince otomatik yenilenir', !str_contains($php, 'pf-getir') && str_contains($js, "requestSubmit()"));
ok('Başlık yazısı ve kısayol ipucu satırı kaldırıldı', !str_contains($php, 'pf-title') && !str_contains($php, 'pf-hints') && !str_contains($php, 'Yeni kayıtlar listenin en üstünde'));
ok('Buton sırası (soldan sağa): Yazdır · Excel · Düzelt · Bul · Sil · Çoğalt · Ekle', preg_match('/data-pf="yazdir".*?export_menu\(.*?data-pf="duzelt".*?data-pf="bul".*?data-pf="sil".*?data-pf="coglat".*?id="pfAdd"/s', $php) === 1);
ok('Sol üst köşe alanı + sütun genişliği tutacı', str_contains($php, 'id="pfKose"') && str_contains($js, "className = 'pf-rs'") && str_contains($js, 'hepsiniDaralt'));
// ── Kullanıcı bazlı sütun tercihi (v537) ──
$kt = (string)file_get_contents(__DIR__ . '/../config/kullanici_tercih.php');
ok('tercih: kullanıcı bazlı (user_id + anahtar) tablo, beyaz liste doğrulayıcı', str_contains($kt, 'PRIMARY KEY (`user_id`, `anahtar`)') && str_contains($kt, "if (\$anahtar === 'palet_fisleri.tablo')"));
ok('tercih kaydı sayfada kullanıcı kimliğiyle + csrf kapısı içinde', str_contains($php, "kt_yaz(\$pdo, (int)(\$auth_user['id'] ?? 0), \$anahtar, \$deger)") && str_contains($php, "csrf_check(\$_POST['csrf']"));
ok('tablo yoksa tarayıcıya (localStorage) düşer', str_contains($js, 'localStorage.setItem(YEREL_ANAHTAR') && str_contains($php, "'tercihSunucu' => kt_hazir(\$pdo)"));
ok('tercih tablosu yalnız migrate.php kartından (ne=kullanici_tercih), sayfa tablo yaratmaz', str_contains((string)file_get_contents(__DIR__ . '/../migrate.php'), "'kullanici_tercih'") && !str_contains($php, 'CREATE TABLE'));
echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
