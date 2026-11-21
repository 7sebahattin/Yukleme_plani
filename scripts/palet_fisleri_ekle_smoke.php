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
ok('JS: kantardan al yer tutucusu pasif ve gizli', str_contains($php, 'pf-kantar" hidden disabled'));
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
ok('Sil: ilk basış satırı kırmızı işaretler (pf-row-silinecek), işaretli satırda ikinci basış Sil · Geri Al · İptal penceresini açar; yalnız pencerede Sil sunucuya delete gönderir', str_contains($js, "classList.add('pf-row-silinecek')") && str_contains($js, 'silDialog.showModal()') && preg_match("/async function silOnayla\\(\\).*?send\\('delete'/s", $js) === 1 && !str_contains($js, 'window.confirm(') && str_contains($php, 'id="pfSilOnay"') && str_contains($php, 'id="pfSilGeriAl"') && str_contains($php, 'id="pfSilIptal"') && str_contains((string)file_get_contents(__DIR__ . '/../assets/palet-fisleri.css'), 'tr.pf-row-silinecek td'));
ok('Sil: kırmızı işaret başka satıra geçince/Esc ile KALKMAZ; yalnız Geri Al kaldırır, İptal kırmızıyı korur', !str_contains($js, 'silmeIptal') && !str_contains($js, "event.key === 'Escape' && silinecek") && preg_match("/pfSilGeriAl'\\)\\?\\.addEventListener.*?classList\\.remove\\('pf-row-silinecek'\\)/s", $js) === 1 && preg_match("/pfSilIptal'\\)\\?\\.addEventListener\\('click', \\(\\) => \\{ silHedef = null; silDialog\\.close\\(\\); \\}\\)/", $js) === 1);
ok('Çoğalt sunucu satırını listeye ekler (yeniden yükleme yok) ve kopyayı Düzelt penceresinde açar', str_contains($js, "send('duplicate'") && !str_contains($js, 'location.reload') && preg_match("/send\\('duplicate'.*?satirEkle\\(r\\.row\\).*?openDialog\\('duzelt'\\)/s", $js) === 1);
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
ok('Köşe alanı tıklanmaz (çift tık işlevi kaldırıldı); sütun birleşimine (tutaç) çift tık TÜM sütunları daraltır', !str_contains($php, 'pf-kose') && !str_contains($js, 'pfKose') && str_contains($js, "className = 'pf-rs'") && str_contains($js, "tutac.addEventListener('dblclick', event => { event.stopPropagation(); hepsiniDaralt(); })") && !str_contains($js, 'sutunuDaralt'));
ok('Sütun taşıma: yarı saydam hayalet başlık + bırakma çizgisi, yalnız bırakınca taşınır, Esc vazgeçer', str_contains($js, 'function suruklemeBaslat') && str_contains($js, 'function suruklemeBitir(uygula)') && str_contains($js, "className = 'pf-hayalet'") && str_contains($js, "className = 'pf-birak'") && str_contains($js, "e.key === 'Escape'") && !str_contains($js, 'moveColumn(from, to);\n        armedHeader'));
// ── Kullanıcı bazlı sütun tercihi (v537) ──
$kt = (string)file_get_contents(__DIR__ . '/../config/kullanici_tercih.php');
ok('tercih: kullanıcı bazlı (user_id + anahtar) tablo, beyaz liste doğrulayıcı', str_contains($kt, 'PRIMARY KEY (`user_id`, `anahtar`)') && str_contains($kt, "if (\$anahtar === 'palet_fisleri.tablo')"));
ok('tercih kaydı sayfada kullanıcı kimliğiyle + csrf kapısı içinde', str_contains($php, "kt_yaz(\$pdo, (int)(\$auth_user['id'] ?? 0), \$anahtar, \$deger)") && str_contains($php, "csrf_check(\$_POST['csrf']"));
ok('tablo yoksa tarayıcıya (localStorage) düşer', str_contains($js, 'localStorage.setItem(YEREL_ANAHTAR') && str_contains($php, "'tercihSunucu' => kt_hazir(\$pdo)"));
ok('tercih tablosu yalnız migrate.php kartından (ne=kullanici_tercih), sayfa tablo yaratmaz', str_contains((string)file_get_contents(__DIR__ . '/../migrate.php'), "'kullanici_tercih'") && !str_contains($php, 'CREATE TABLE'));
// ── Bul penceresi + Ambalaj (v538) ──
ok('Bul: Boşluk tuşu pencereyi açar (yazı alanında/düğmede/açık pencerede değil)', str_contains($js, "event.key !== ' '") && str_contains($js, "document.querySelector('dialog[open]')") && str_contains($js, 'input,select,textarea,button'));
ok('Bul: 9 arama biçimi (Başlayan … Eşit Olmayan), varsayılan Akıllı arama', substr_count($php, '<option value=') >= 9 && str_contains($php, '<option value="akilli" selected>Akıllı arama</option>') && str_contains($php, 'Eşit Olmayan') && str_contains($php, 'İle Başlamayan') && str_contains($php, 'Boş Olan'));
ok('Bul: filtre seçili hücrenin sütununa uygulanır, sütunlar birlikte', str_contains($js, "selectedCell?.dataset.col") && str_contains($js, 'const filtreler = new Map()'));
ok('Bul: onay ✔ / vazgeç ✖ düğmeleri, eski arama kutusu yok', str_contains($php, 'id="pfBulTamam"') && str_contains($php, 'id="pfBulIptal"') && !str_contains($php, 'id="pfBulKutu"'));
ok('Seçili hücre ayrı renklenir (sabit Parti No arka planı yok)', !str_contains((string)file_get_contents(__DIR__ . '/../assets/palet-fisleri.css'), 'td[data-col="parti_no"]{background:#0b78d0'));
ok('Ambalaj sütunu (kasa cinsi adı): liste + Excel + tercih beyaz listesi', str_contains($php, '<th data-col="kasa_adi">Ambalaj</th>') && str_contains($php, "kd.type = 'kasa_cinsi'") && str_contains($php, "'Ambalaj'") && str_contains($kt, "'kasa_adi'"));
// ── Mobil düzen + koyu tema (v538) ──
$css = (string)file_get_contents(__DIR__ . '/../assets/palet-fisleri.css');
ok('Durum satırında sabit ipucu yazısı yok (yalnız işlem mesajı)', !str_contains($php, 'Kartlar seçili satırın partisini gösterir') && str_contains($css, '.pf-status:empty{display:none}'));
ok('Koyu tema: --surface tanımsızdı → sayfa kendi renk belirteçlerini taşır (açık + koyu)', !str_contains($css, 'var(--surface,') && str_contains($css, 'html[data-theme="dark"] .pf-page') && str_contains($css, '--pf-surface:var(--card') && str_contains($css, '--pf-text:#e7ecf4'));
ok('Araç çubuğu düğmeleri her temada beyaz zemin + koyu yazı', str_contains($css, 'background:#fff;color:#162c44;font:inherit;font-size:.86rem'));
ok('Mobil: sayfa kaymaz (html/body overflow hidden), tablo kendi içinde kayar', str_contains($css, 'html:has(.pf-page),body:has(.pf-page){overflow:hidden') && str_contains($css, '.pf-table-wrap{flex:1;min-height:0;height:auto}'));
ok('Mobil: özet kartları tek satır (7 sütun) + kısa etiket, alt çubuğun üstünde biter', str_contains($css, 'grid-template-columns:1.7fr repeat(6,minmax(0,1fr))') && str_contains($php, 'class="pf-k"') && str_contains($js, '--bn-h') && str_contains($js, 'safe-area-inset-bottom'));
// ── Ekle/Düzelt penceresi sadeleştirme + otomatik dara (v540) ──
ok('Pencerede açıklama paragrafı ve "Yazdıkça bulunur" yer tutucuları yok', !str_contains($php, 'Kaydedince pencere açık kalır') && !str_contains($php, 'Yazdıkça bulunur') && !str_contains($php, 'Yazın veya listeden seçin') && !str_contains($js, 'aciklamaEl'));
ok('Açılır liste odaklanınca açılmaz, yalnız yazınca açılır', !str_contains($js, "input.addEventListener('focus', render)") && str_contains($js, 'if (!q) { items = []; close(); return; }'));
ok('Dara otomatik: kasa adedi × kasa darası + palet darası (tanım unit_dara_kg), alan salt okunur', str_contains($php, "'unit' => (float)(\$x['unit_dara_kg']") && str_contains($php, 'name="dara_kg" id="pfDara" readonly') && str_contains($js, 'function daraOtomatik()') && str_contains($js, 'adet * (Number(tanimBul(\'kasa\')?.unit) || 0) + (Number(tanimBul(\'palet\')?.unit) || 0)'));
ok('"Kantardan al" düğmesi gizli (hidden)', str_contains($php, 'class="btn btn-ghost pf-kantar" hidden'));
ok('Mobil: Ekle/Düzelt penceresi tam ekran, alanlar 6 sütunlu ızgarada (pf-w2/w3/w4/w6), alt çubuk sabit', str_contains($css, '#pfDialog{width:100%;max-width:100%;height:100dvh') && str_contains($css, 'label.pf-w3{grid-column:span 3}') && str_contains($php, 'class="pf-kg pf-w2"'));
// ── İşlem sütunları + dara göstergesi (v541) ──
$cssd = (string)file_get_contents(__DIR__ . '/../assets/palet-fisleri.css');
ok('Yeni sütunlar: Tarih · İşlem · İşlem Tarihi · Kullanıcı · Şube (başlık + hücre + tercih beyaz listesi)', str_contains($php, '<th data-col="tarih">Tarih</th>') && str_contains($php, '<th data-col="islem">İşlem</th><th data-col="islem_zaman">İşlem Tarihi</th><th data-col="kullanici">Kullanıcı</th><th data-col="sube">Şube</th>') && str_contains($php, 'data-col="sube"') && str_contains($kt, "'islem_zaman', 'kullanici', 'sube'"));
ok('İşlem bilgisi audit_log + users\'tan okunur (şema değişmez), tablo yoksa boş döner', str_contains($php, 'function pf_islem_sql(PDO $pdo)') && str_contains($php, "a2.module = 'palet_fisleri'") && str_contains($php, "a2.action IN ('create', 'update')") && str_contains($php, "SELECT 1 FROM audit_log LIMIT 0") && !str_contains($php, 'ALTER TABLE'));
ok('İşlem adları: Eklendi / Çoğaltıldı (duplicate_of) / Düzeltildi', str_contains($php, "'Düzeltildi'") && str_contains($php, "'Çoğaltıldı'") && str_contains($php, "'Eklendi'") && str_contains($php, 'duplicate_of'));
ok('Şube = paletin depo damgası; Excel/CSV sonuna İşlem·İşlem Tarihi·Kullanıcı·Şube eklenir', str_contains($php, "p.depo, COALESCE(md.name") && str_contains($php, "'İşlem', 'İşlem Tarihi', 'Kullanıcı', 'Şube'"));
ok('Kayıt/güncelle/çoğalt yanıtı da işlem alanlarını taşır (satır anında güncellenir)', str_contains($php, "'islem' => pf_islem_adi(\$y)") && str_contains($js, 'islem_zaman: [r.islem_zaman'));
ok('Pencerede seçilen paletin ve ambalajın darası kutunun yanında yazar', str_contains($php, 'data-birim="palet"') && str_contains($php, 'data-birim="kasa"') && str_contains($js, 'function birimGoster()'));
ok('Brüt/Dara/Net kutuları belirgin zeminli (açık + koyu)', str_contains($cssd, '.pf-form-grid>label.pf-kg{background:var(--pf-kg-bg)') && str_contains($cssd, '--pf-kg-bg:#14304d'));
// ── Sütunlar penceresi (v543) ──
ok('Sol şerit/köşe sütunu kaldırıldı (tablo kenarda başlar)', !str_contains($php, 'pf-kose') && !str_contains($cssd, '.pf-kose') && !str_contains($cssd, 'margin-left:22px') && str_contains($js, 'const KOSE = 0'));
ok('Başlığa çift tık Sütunlar penceresini açar (tutaçta çift tık yine daraltır)', str_contains($php, 'id="pfSutunDialog"') && str_contains($php, 'id="pfSutunListe"') && str_contains($js, "headers.addEventListener('dblclick'") && str_contains($js, 'sutunPenceresiAc()') && str_contains($js, "if (event.target.closest('.pf-rs')) return; event.preventDefault(); sutunPenceresiAc()"));
ok('Sütun göster/gizle: işaret kutuları, en az bir sütun görünür, Tümünü göster, kullanıcı bazlı kayıt (gizli)', str_contains($js, 'kutu.disabled = kutu.checked') && str_contains($js, "id=\"pfSutunHepsi\"") === false && str_contains($php, 'id="pfSutunHepsi"') && str_contains($js, 'tercih.gizli = Array.from(gizli)') && str_contains($kt, "'gizli' => []") && str_contains($kt, "\$out['gizli']"));
ok('Gizli sütunlar gezinme (ok tuşları), yazdırma, parti listesi ve sütun taşımada atlanır', str_contains($js, 'const gorHuc = row =>') && str_contains($js, 'filter(th => !gizli.has(th.dataset.col))') && str_contains($js, '!gizli.has(c) && table.tHead') && str_contains($js, 'gizli sütunlar sayılmaz'));
echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
