---
name: beyan-hks
description: Beyanlar (beyan*.php, _beyan_liste/_beyan_form, config/helpers.php beyan_*/bb_*): form, liste, durum şeridi, WhatsApp metni, HKS bildirim köprüsü, Yükleme Bildir (beyansız yükleme, v498–v502). Beyan sayfalarına ya da bb_*/beyan_hks_* fonksiyonlarına dokunurken kullan.
---

# Beyanlar ve Beyan→HKS Bildirimi

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

**Beyan Gönderici Firma (v485, sahip isteği):** Metin ayrıştırma/yeni beyan formunda Gönderici Firma ARTIK "son kullanılan HKS firması"yla (`bb_son_firma`) doldurulmaz; boş gelir ve YENİ beyanda zorunludur (HTML `required` + `beyan_create.php` sunucu hatası). Düzenlemede zorunlu değil; kayıtlı `hks_firma_id` adı getirir.


## Beyan → Hal Kayıt Bildirimi (Sprint Beyan-Bildirim-01)

Beyan ekranındaki **"🏛 Bildirim Yap"** butonu, beyandaki veriden bir **HKS taslağı**
açar. Gönderim yapmaz.

**Dosyalar:** `api_beyan_bildirim.php` (JSON uç: `hazirla` / `taslak_olustur`) ·
`halkayit/taslak_lib.php` (ortak kütüphane) · `beyan_view.php` (buton + modal + geçmiş).
**Tablolar:** `beyan_hks_bildirim` (bağ + geçmiş) · `hks_eslesme` (öğrenilen eşlemeler) ·
`customs_declarations.vehicle_plate` + `.hks_durum` (yeni kolonlar).
**Test — üç katman, üçü de ağsız ve canlı DB'ye dokunmaz:**
1. `php scripts/beyan_bildirim_smoke.php` — statik: kaynak kodda kural arar.
2. `php scripts/beyan_ui_smoke.php` — **render**: bellek içi SQLite ile
   `beyan_view.php` + `beyan_edit.php`'yi gerçekten çalıştırır.
3. `node scripts/beyan_js_smoke.js` — **tarayıcı**: Playwright + Chromium ile
   `assets/app.js` davranışını doğrular (yazarak arama, boşluksuz plaka, pasif
   buton görünümü). Playwright yoksa kendini ATLAR, hata vermez — PHP-only
   depoda zorunlu bağımlılık olmasın diye. **Arayüz davranışı değiştirdiysen
   bunu çalıştır:** ilk iki katman JS'i hiç çalıştırmaz, üst üste gözden kaçan
   sorunların hepsi bu katmandaydı.
**Sürüm görünürlüğü:** `APP_SURUM` masaüstü sidebar'ının altında, ayrıca
`index.php` sayfa sonunda ve `beyan_bildirim_tani.php` başlığında yazar —
mobilde sidebar görünmediği için "deploy yansıdı mı?" sorusu oradan cevaplanır.
**Ön kontrol:** `beyan_bildirim_tani.php` (admin, salt-okunur) — migration, katalog
(kodun ADA göre aradığı "İhracat" sıfatı / "Satış" türü / "Yurt Dışı" işletme türü),
ürün eşleşme oranı ve beyan hazırlık özeti. **Canlıya alınca İLK burayı aç.**
Kural KOPYALAMAZ, uygulamanın kendi fonksiyonlarını çağırır — "TAMAM" diyorsa
özellik de aynı kararı verir.

**Değiştirmeden önce oku:**
- **Taslak yazmanın TEK yolu `hks_taslak_olustur()`** (`halkayit/taslak_lib.php`).
  `hks_bildirim_dogrula()` canlı sistemde öğrenilmiş kuralları (TC algoritması,
  KPS kimlik bütünlüğü, Üreticiden Sevk Alım kısıtları) taşır. **İKİNCİ BİR YAZMA
  YOLU AÇMA** — iki yol ayrışır, ayrışan taraf sessizce hatalı bildirim gönderir.
- **Beyan ekranı HKS'e HİÇBİR ŞEY GÖNDERMEZ.** `BildirimKaydet` geri alınamaz ve
  rüsum doğurur; gönderim yalnız `taslak_gonder` yolunda, oradaki atomik
  mükerrer-gönderim koruması ile yapılır.
- **Canlı SOAP çağrısı yok** — katalog listeleri `hks_kv.listeler_cache`'ten okunur.
  Önbellek boşsa özellik **fail-closed** davranır (409 + "Listeleri Güncelle" yönlendirmesi).
- **Plan taslağı olarak yazılır** (`planKg` var, künye yok): künyeler gönderim anında
  canlı stoktan çözülür. Stok yetmezse hiçbir bildirim gitmez, taslak korunur.
- **Bağ, taslağın İÇİNDE taşınır** (`ortak.kaynak = {tip:'beyan', beyanId}`). Taslak
  gönderilince satır silinip yeni id ile doğduğu için dış anahtar işe yaramaz;
  `taslak_gonder` ve `taslak_sil` bu izi okuyup bağ kaydını sonuçlandırır
  (`beyan_hks_taslak_isaretle()` — hata yutar, HKS akışını asla kesmez).
- **Bağın yaşam döngüsü — "taslağa atılan beyan bildirilmiş sayılır"** (v273):
  aktif durumlar TEK listede `beyan_hks_aktif_durumlar()` = `taslak` /
  `gonderildi` / **`silindi`** — SQL'de `beyan_hks_aktif_durumlar_sql()`;
  `'taslak','gonderildi'` diye elle YAZMA. Hal Kayıt'ta **Düzenle** yeni id'li
  taslak kaydedip eskisini siler: SPA `eskiTaslakId` gönderir, `taslak_kaydet`
  eski taslağın `ortak.kaynak` izini yeni taslağa aktarır ve bağı
  `beyan_hks_taslak_tasi()` ile yeni id'ye TAŞIR (eskiden silme bağı `iptal`e
  çekiyordu → gönderilmiş beyan yeniden "uygun" görünüyordu). Taslak silinince
  bağ `silindi` olur ve beyan KENDİLİĞİNDEN açılmaz. Gönderimde taslak id'si
  tutmazsa `kaynak.beyanId` yedek yol olarak kullanılır (yalnız gönderimde).
  Elle düzeltme `beyan_edit.php` → "🏛 Hal Bildirim Durumu" (ana formun
  DIŞINDA ayrı form): **Gönderildi Olarak İşaretle** (aktif bağ yokken; tek
  eşleşen `hks_gonderilenler` satırı varsa `gonderim_id` bağlanır) ve
  **Tekrar Aktif Et** (YALNIZ `silindi` → `iptal`). `beyan_hks_bag_duzelt()`,
  audit `beyan_hks_bag_duzelt`. Test: `beyan_ui_smoke.php` `[bag]` satırları.
- **Eşleştirme BEYANIN kalıcı alanıdır** (`hks_firma_id` / `hks_urun_id` +`_ad` /
  `hks_ulke_id` +`_ad`), beyan formundaki **"🏛 Hal Bildirim Bilgileri"**
  bölümünde girilir (`beyan_hks_form_bolumu()` — iki formun ORTAK parçası).
  Bildirim ekranı bunları **salt okunur** gösterir ve `taslak_olustur` değerleri
  **beyandan** okur, istemciden ASLA — aksi hâlde beyanda görünenden başka bir
  bildirim kurulabilirdi. Öğrenme (`hks_eslesme`) beyan kaydında yapılır,
  uç noktada TEKRARLANMAZ (iki yazıcı aynı anahtarı ezerdi).
- **Buton kapısı:** plaka dolu + **eşleştirme tam** (`beyan_hks_eslesme_tam()`) +
  durum uygun + net KG > 0 + aktif bildirim yok + **çift yetki** (`beyan.write`
  **ve** `records.write`). Plaka dolu olsa bile eşleştirme boşsa geçilmez.
  Kapalıysa sebebi yazılır.
- **1 beyan = 1 ürün = 1 bildirim.** Aktif (`taslak`/`gonderildi`) bağ varsa ikincisi
  açılmaz. İki ürünlü gümrük beyanı sisteme **iki ayrı beyan** olarak girilir.
- **Net KG zorunlu, brüte düşülmez** — rüsum net üzerinden hesaplanır.
- **Birim fiyat ÖNERİLİR, otomatik DOLDURULMAZ** (`bb_fiyat_onerileri`). HKS'in
  `MalinSatisFiyat` alanında **para birimi yoktur** (hks_soap.php) ve rüsum bu
  sayıdan hesaplanır. Kaynaklar: ① aynı firma+ürüne yapılmış son bildirimin
  fiyatı (`hks_gonderilenler` — HKS'in kendi biriminde, belirsizlik yok)
  ② bağlı planın maliyet hesabı (`cost_sheets.sale_unit_price`, KENDİ para
  biriminde — ham hâli ve kurla çevrilmiş hâli AYRI öneri olarak sunulur;
  **sessizce çevrilmez**). Maliyet önerisi `maliyet.read` yetkisine bağlıdır.
- **Modalde "alım" türleri YOK** (`bb_alim_turu_mu`). Satın Alım / Üreticiden Sevk
  Alım REFERANSSIZ bildirimdir — malın tam tanımını ister, plan taslağı bunu
  taşıyamaz; ayrıca HKS "İhracat" sıfatıyla Üreticiden Sevk Alım'ı reddediyor.
  Alım bildirimi Hal Kayıt panelinden yapılır; kuralların hepsi orada duruyor.
- **Ülke ipucu zinciri** (`bb_ulke_adaylari` → `bb_ulke_tahmin`): beyanda ülke
  alanı YOK. Sırayla denenir — ① bağlı planın `gidecek_ulke`si ② **şirket adı**
  (`company_name`) ③ **adresin ülke parçası** (`bb_adres_ulke_parcasi` —
  WhatsApp metnindeki `RUSSIA, 108811, G.MOSKVA...` satırının ilk virgülden
  önceki kısmı) ④ alıcı adı ⑤ aynı alıcıya yapılmış en son yüklemenin ülkesi.
  ⑥ ham metin (`raw_text`/`unmatched_text`) satır/virgül parçaları — yalnız
  ARAMA için. İlk çözülen kazanır; hiçbiri çözülmezse alan **boş kalır**.
- **Ülke adı köprüsü** (`bb_ulke_adi_karsiliklari` / `bb_ulke_karsiligi`):
  katalog Türkçe ("Rusya"), beyan metni gümrük evrakından geldiği için
  İngilizce ("RUSSIA", "RUSSIAN FEDERATION") — tam ad eşleşmesi hiç
  tutmuyordu. Tablo **olgusal ad karşılığıdır, tahmin değil**; parçalı/bulanık
  eşleşme YOK (Niger/Nigeria sessizce karışırdı). Tablo **Türkçe AD** döndürür,
  HKS id'si DEĞİL — id her zaman canlı katalogdan tam ad eşleşmesiyle bulunur;
  katalogda karşılığı yoksa eşleşme olmaz. Bir karşılık için birden çok Türkçe
  aday verilebilir (ör. "Rusya" / "Rusya Federasyonu") — katalog yazımı
  bilinmediği için sırayla denenir.
- **`bb_ulke_ad_norm()` ayrı bir normalizasyondur** — `hks_eslesme_norm()`
  Türkçe metin için ASCII **"I" → "ı"** (noktasız) yapar, "RUSSIAN" böylece
  "russıan" olur ve tabloyla HİÇ eşleşmez. Yabancı adlarda bunu kullanma.
  Kaydederken YALNIZ ②③④ `beyan_hks_ulke_ogren()` ile öğrenilir. ⑤ ve ⑥
  **ÖĞRENİLMEZ**: ham metin parçası anahtar olsaydı "Yeni Beyan" gibi her
  beyanda geçen bir satır tüm beyanlara yanlış ülke ön-doldururdu. Tarama
  güvenlidir çünkü çözüm kanonik ad karşılığı ya da tam katalog eşleşmesi
  gerektirir — bir metin parçası kendiliğinden ülkeye dönüşemez. ⑥ adayları
  için `bb_tahmin(..., $ogrenilenAra=false)` çağrılır: onlarca metin parçası
  için `hks_eslesme` sorgusu açılmaz.
- **Beyan öğrenilebilir alanlar (v327):** oluşturma/düzenlemede öneri ve açık
  `+ ekle` işlemi kullanılır; kayıt kaydetmek otomatik tanım oluşturmaz.
  `beyan.write` ve CSRF ile ayrı beyan tanım havuzu, mevcut HKS ürün kataloğu
  ve geçmiş kayıt önerileri kullanılır. Yeni DB şeması yoktur. Gümrük Adı /
  Adresi mevcut `company_name` / `company_address` kolonlarında korunur;
  ad seçimi bağlı adresi doldurur. Gümrük ve iletişim bölümü sürekli görünür.
  Başlık/Beyan Tipi formdan kaldırılmıştır; eski değer sıradan düzenlemede
  silinmez. Bağlantı düzeltme alanı kullanıcı kararıyla gizlidir.
- **Öneri listeleri TEMİZ BAŞLANGIÇ (v331, sahip kararı):** beyan formu önerileri (`beyan_suggest_form_data()`)
  `BEYAN_ONERI_BASLANGIC` (helpers.php, `2026-10-12 00:00:00`) ÖNCESİ veriyi göstermez: geçmiş beyanlar
  (`created_at >=`), zaman damgasız eski KV tanımları (`added_at` yoksa gizli; `+ekle` yeniden ekleyince
  damgalanır) ve genel tanım havuzu (`material_definitions`) KULLANILMAZ. HKS ürün KATALOĞU kalır (ürün adı →
  HKS id eşlemesi ona dayanır). Tarihi ileri almak = yeni temiz başlangıç; geri ALMA.
- **Not alanı (v332, sahip GO verdi — TEK şema değişikliği):** `customs_declarations.note TEXT NULL`
  (auto-migration `config/helpers.php`, yalnız ADD COLUMN). Beyan formunun "Beyan" sekmesinde en altta
  "Not" bölümü (`name="note"`, ≤2000 karakter); önizlemede yalnız doluysa 📝 Not kartı. Büyük harfe
  ÇEVRİLMEZ (serbest metin), öneri havuzuna girmez, **Kopyala'da kopyalanmaz** (o sevkiyata özel). Düzenle
  POST'unda `note` gönderilmezse eski değer korunur.
- **WhatsApp metni + Kopyala (v333):** Önizleme (`beyan_view.php`) üst çubuğundaki **⧉ Kopyala** artık beyan
  verisinden üretilen WhatsApp metnini panoya kopyalar (`beyan_whatsapp_metin()` — config/helpers.php, saf, DB yok;
  `*kalın*` + simgeler; boş alan/bölüm atlanır; değerlerdeki `* _ ~ \`` temizlenir). Metin `<script type="application/json"
  id="waMetin">` içinde, kopyalama `navigator.clipboard` + `execCommand` yedeği. Giriş ayrıştırıcısı (`beyan_parse.php`) DEĞİŞMEDİ.
  Numune ayrı kolon değil: ham/eşleşmeyen metinde "NUMUNE" satırından sonra aranır (`beyan_whatsapp_numune()`); Önemli Not = `note`.
  TASLAK oluşturan eski "Kopyala" **"Taslak Oluştur"** adını aldı (📝) ve YALNIZ `beyanlar.php` listesindedir (`beyan_kopyala.php`
  dosyası/URL'si aynı). İkisini karıştırma: önizlemede taslak formu YOK. Test: `php scripts/beyan_whatsapp_smoke.php`.
- **Numune alanları (v334, sahip GO verdi — 4 nullable ADD COLUMN):** `customs_declarations.sample_gross_kg` / `sample_net_kg`
  (DECIMAL 12,3) · `sample_crate_type` (VARCHAR 100) · `sample_crate_count` (INT); auto-migration `config/helpers.php`. Formda
  **Analiz sekmesinde** "🧪 Numune" bölümü (`_beyan_form.php`, Analiz Bilgileri'nin altı), önizlemede yalnız doluysa kart. Kayıtta `beyan_veri_dogrula()` yalnız
  GÖNDERİLEN alanı işler (net ≤ brüt); `beyan_edit` gönderilmeyen numuneyi korur. WhatsApp metni (`beyan_whatsapp_numune()`) ÖNCE
  bu kolonlara bakar (tarih/başka alan gerekmez), hepsi boşsa eski ham-metin ayrıştırması yedek kalır. Kopyala (Taslak Oluştur)
  numuneyi KOPYALAMAZ (o sevkiyata özel).
- **Form sekmeleri + önizleme yerleşimi (v336):** Beyan formunda sekmeler **Beyan · Analiz · Hal Bildirim · Kaynak metin**.
  HKS bölümü (`beyan_hks_form_bolumu()`: firma/ürün/ülke/plaka) artık sağ `<aside>` DEĞİL, kendi sekme panelindedir
  (`#beyanPanel_hks`, `.beyan-workspace-hks` kabı, max 640px) — HKS verisinin girileceği TEK yer. Beyan veren (Beyan/Analiz/Kaynak)
  ile bildirim yapan (Hal Bildirim) alanları ayrı sekmelerde. Önizlemede Numune kartı Analiz / Durum kartının YANINDA
  (`.bv-yanyana`, numune doluysa `-2` ile ≥900px iki kolon; mobilde alt alta).
- **Beyan detay eylemleri (v341):** Masaüstünde (≥1024px) seçili durumun sağında seçenekler kartlar gibi animasyonla yayılır; fare ayrıldıktan 3 saniye sonra animasyonla kapanır, geri gelince kapanma iptal edilir. Tıklama ve klavye erişimi desteklenir; mobilde mevcut durum şeridi sürer. Seçim tek `status_only` formuyla sunucuya gider; RED notu, CSRF ve yetki kontrolleri korunur. WhatsApp kopyalama düğmesi parti numarasının altında, yeşil renktedir; altındaki tekrar durum rozeti ve kayıt numarası kaldırılmıştır. Başlangıç sürümü v340 / PR #713 hızlı plaka girişini içerir.
- **Hızlı plaka girişi + form sadeleştirme (v340):** Önizlemedeki Hal Kayıt Bildirimi kartında plaka EKSİKSE hücre tıklanabilir
  (`#plakaHizliBtn`, turuncu) ve küçük native `<dialog id="plakaHizliDlg">` açar; kayıt `api_beyan_plaka.php` (POST JSON + CSRF,
  kapı `beyan.write` + `hks.write`, yalnız `vehicle_plate` UPDATE'i, `beyan_plaka_normalize()`, 3-20 harf/rakam/tire, audit
  `beyan_plaka_hizli`, arşivlenmiş beyan 409) ve sayfayı yeniler (düğme kapıları kendiliğinden güncellenir). Plaka doluyken hücre
  düz kalır. Tam düzenleme gerekmez. Beyan oluştur/düzenle formundaki parantez içi ipuçları ve alt metinler (isteğe bağlı/zorunlu
  notları, HKS giriş paragrafı, "katalogdan eşleşti" etiketleri, durum ipucu, silme notu, "Değişiklik yok" vb.) KALDIRILDI — geri
  ekleme (görüntü kirliliği, sahip kararı). Test: `beyan_ui_smoke.php` `[plaka]`/`[temiz]` satırları.
- **Form düzeni + koyu tema (v342):** Beyan formunda ilk kart **"Parti/Sevkiyat"** (eski Beyan / Sevkiyat); **Durum seçimi
  "Gümrük ve İletişim" kartının sonunda**; "Marka" etiketi **"Ambalaj Marka"** (form + önizleme). Masaüstü kenar çubuğunun
  logo satırında tema düğmesi (`.tema-dongu.sidebar-tema`, `asyaTemaDondur()`; tema JS'i `.tema-dongu` sınıfıyla TÜM düğmeleri
  günceller — id'ye bağlama). Koyu tema düzeltmeleri: öneri kutusu "+ ekle" satırı (`.ms-sug-add` koyu kuralı), beyan liste
  sayaç rozeti kontrastı. Yeni açık renkli yüzey/rozet eklerken `html[data-theme="dark"]` karşılığını DA yaz.
- **Gönderici/Ülke/Çekici/Dorse + Numune taşıması + benzer ürün eşleme (v343, sahip GO verdi — 2 nullable ADD COLUMN):**
  `customs_declarations.tractor_plate` (VARCHAR 20) ve `sender_company_name` (VARCHAR 150), auto-migration helpers.php. **Parti/Sevkiyat**
  kartında: Gönderici Firma (HKS firmalarından datalist, listede olmayan ad da serbest — `beyan_gonderici_coz()` TR-duyarsız TAM ad
  eşleşirse `hks_firma_id`'yi çözer, değilse boş bırakır) · Ülke (`hks_ulke_id`, HKS kataloğu) · Çekici Plaka (`tractor_plate`, HKS'e GİTMEZ)
  · Dorse Plaka (= `vehicle_plate`, HKS bildirimindeki plaka). Hal Bildirim sekmesinde YALNIZ HKS Ürünü kaldı
  (`beyan_hks_form_bolumu(..., 'sevkiyat'|'urun')`). `sender_company_name` HKS izin grubundadır (`beyan_permission_fields`).
  Önizlemede Lojistik / Alıcı kartı bu 4 alanı listeler; **WhatsApp metnine EKLENMEZ** (test denetler). Kopyala (Taslak Oluştur) göndericiyi
  kopyalar, plakaları kopyalamaz. **Numune** formda Beyan sekmesine (Ürün ve Miktarlar'ın altı), önizlemede Ürün Bilgileri gibi tek satırlı 3B
  "Numune Bilgileri" kartına taşındı (Analiz yanındaki `.bv-yanyana` kalktı). **Benzer ürün eşleme** `bb_urun_benzer()`: elle yazılan kısa ad
  ("Kapya") katalogdaki "Biber Salçalık (Kapya)" ile eşleşir — sorgunun TÜM sözcükleri (≥3 harf) katalog sözcüğüne eşit/önek ise aday; TEK
  aday (ya da en az fazla sözcüklü benzersiz aday) seçilir, belirsizse boş; yalnız ÜRÜNDE (ülkede benzer eşleme YOK); form ön-doldurması
  "benzer isimle eşleşti" rozetiyle, Eşleştir düğmesi de aynı. Gönderim yine taslak üzerinden kullanıcı denetiminde.
- **Öneriden sil + listede durum menüsü (v344, migration YOK):** Beyan formu öneri kutusunda (Gümrük Adı vb.) satırın üzerine gelince
  sağda **×** çıkar (`.ms-sug-del`, METİNSİZ düğme — `textContent` değişmez; dokunmatikte hep görünür) → `api_beyan_tanim_sil.php`
  (POST JSON + CSRF, `beyan.create|edit`, adres/alıcı/ilgili için `contact.write`, audit `delete`/`definitions`) →
  `beyan_suggest_hide()`: tanımı KV'den (`beyan_defs_<alan>`) siler VE `beyan_defs_hidden`'a yazar; `beyan_suggest_form_data()` gizlileri
  önerilerden/`companies`'ten çıkarır. Beyan KAYITLARINA dokunmaz; açık "+ ekle" gizliyi geri getirir (`beyan_suggest_save`);
  **HKS katalog ürünleri silinemez**. Liste (`beyanlar.php` + `_beyan_liste.php`): masaüstünde (≥1024px) durum rozeti üzerine gelince
  seçenekler **aşağı doğru** açılır (`.bl-durum`, `position:fixed` menü — `.table-wrap` kırpmaz; fare ayrılınca 3 sn sonra kapanır,
  scroll/resize kapatır). Her satırda tek POST formu `beyan_edit.php` `status_only` + `return_to=liste` + `liste_url` (yalnız
  `beyanlar.php…` kabul edilir → aynı filtre/sayfaya döner); RED için `prompt` + `analysis_note`; yetki `beyan.status`.
  Test: `beyan_suggest_smoke.php` (sil/geri getir) · `beyan_ui_smoke.php` `[durum-menü]`.
- **WhatsApp metin düzeni (v345):** `beyan_whatsapp_metin()` sırası: 📝 YENİ BEYAN (en üstte) → 🏢 firma + adres satırları (adres için 📍/ADRES başlığı ve ayrı TIN/KPP satırı YOK, metin olduğu gibi) → 🚛 taşıma/🌿 hat → parti/ürün/yük → numune → çıkış/ilgili/alıcı/marka → not. Geri ekleme. Test: `beyan_whatsapp_smoke.php`.
- **Önizleme renk sadeleştirmesi (v346):** `beyan_view.php` bölümleri (Temel/Ürün/Numune/Lojistik/Analiz/Bağlantı) tek nötr zemin + başlık bandsız (Yeni Beyan formu gibi); simge karoları/alan simgeleri bölüm renginde KALIR. Hal Kayıt Bildirimi (`hks`) ve uyarı kartları renkli. style.css sonundaki v346 bloğu.
- **Hal Bildirim sekmesi KALDIRILDI + HKS Ürün Adı (v347):** formda sekmeler Beyan · Analiz · Kaynak metin. "HKS Ürün Adı" (`hks_urun_id`, `beyan_hks_form_bolumu($f,$beyan,$yazabilir)` — 'urun' kipi) Ürün ve Miktarlar kartında Ürün Adı'nın yanındadır. Ürün adı bir HKS ürünüyle OTOMATİK eşleşiyorsa (`bb_tahmin`: öğrenilen → tam ad → benzer) alan KİLİTLİ (select `disabled` + `data-hks-kilit` + POST'u taşıyan gizli `input[data-hks-ayna]`); eşleşmiyorsa kullanıcı listeden seçer. Canlı eşleşme `api_beyan_urun_esle.php` (GET, salt okunur, `hks.read`) + app.js (400 ms gecikmeli; eşleşme gidince eski otomatik değer temizlenir). Sunucu kaydı eskisi gibi katalogla doğrular. Katalog boşsa alan çizilmez. Önizlemede Gümrük Adı + Gümrük Adresi Temel Bilgiler'de AYNI satırda (`.bv-gumruk-ad/-adres`, ≥900px flex; ad sığmazsa kendi sütununda sarar, mobilde alt alta). Test: `beyan_ui_smoke.php` `[hks-ürün]`.
- **HKS Ürün Adı davranışı (v348, v347'yi günceller):** alan HER ZAMAN pasif başlar (gri, `data-hks-kilit`, POST'u taşıyan `input[data-hks-ayna]` — boşsa boş değer taşır). Ürün Adı'ndan ÇIKILINCA (`change`; yazarken sorgu YOK) `api_beyan_urun_esle.php` sorgulanır: eşleşirse değer gelir ve pasif kalır; eşleşmezse alan boş + AÇIK olur ve turuncu çerçeve alır (`data-hks-serbest`, `.hks-serbest`). Sayfa ilk açılışında aynı durum sunucuda çizilir (ad yazılı + eşleşme yok → serbest; ad boş/eşleşme var → pasif). Alan başlığı yalnız "HKS Ürün Adı" — kart/başlık çerçevesi YOK.
- **HKS Ürün Adı — SUNUCU KARAR VERİR (v349, hata düzeltmesi):** v348'de ürün adı KABAK→ACUR değiştirilince kilitli alanın gizli kopyasındaki ESKİ id (KABAK) kaydediliyor, kayıt bunu "ACUR→KABAK" diye `hks_eslesme`'ye ÖĞRENİYOR, öğrenilen eşleşme `bb_tahmin`'de önce geldiği için alan sonraki açılışta yine KABAK'a KİLİTLENİYORDU (kendini besleyen döngü; JS isteği gecikir/başarısız olur/eski app.js önbellekte kalırsa tetiklenir). Kurallar: (1) kilit kararı `beyan_hks_urun_otomatik()` = YALNIZ katalog tam ad + benzer ad (`bb_tahmin(..., false)`) — öğrenilen eşleşme KİLİTLEMEZ, yalnız açık alanda öneri; (2) kayıtta `beyan_hks_urun_post_coz($_POST, product_name)` (beyan_edit + beyan_create, `beyan_hks_form_oku`'dan ÖNCE): formda `hks_urun_alan=1` varsa otomatik eşleşme → o id (gönderilen yok sayılır), ad boş → boş, eşleşme yok → yalnız açık select'ten gelen değer (gelmediyse boş); (3) `hks_eslesme_yaz('urun',…)` YALNIZ elle seçimde (`$hks_urun_elle`); (4) gizli değer kopyası (`data-hks-ayna`) KALDIRILDI — geri ekleme; (5) JS uç hatasında alan AÇILIR. Uçtan uca test: `node scripts/beyan_hks_urun_e2e.js` (php -S + dosya SQLite, `scripts/beyan_hks_urun_e2e_server.php`; kullanıcı senaryosu, yarış, uç hatası, elle seçim, zehirli öğrenme, JS kapalı).
- **HKS Ürün Adı — kilit kararı TARAYICIDA, ağsız (v350):** v349'da kilit kararı `api_beyan_urun_esle.php` isteğine bağlıydı; canlıda istek başarısız olunca alan boş AÇILIYOR, Güncelle'de ise sunucu aynı adı eşleştiriyordu (ekran ≠ kayıt). Artık Ürün Adı'ndan çıkılınca (`change`) app.js kararı `otomatik()` ile AĞA GİTMEDEN verir: `urunNorm` = `hks_eslesme_norm` (İ→i, I→ı, küçük harf), tam ad → `urunBenzer` = `bb_urun_benzer` BİREBİR aynası (≥3 harf sözcük, ≥4 harfte önek, en az fazla sözcük, beraberlik → yok); katalog = select option'ları (`data-katalog-disi` "Mevcut seçim" hariç). Eşleşirse alan HİÇ açılmaz; eşleşmezse açılır ve uç YALNIZ öğrenilmiş öneri için sorulur (hata → öneri yok). **PHP kuralını (`beyan_hks_urun_otomatik`/`bb_urun_benzer`/`hks_eslesme_norm`) değiştirirsen JS aynasını da değiştir** — e2e `I` bölümü 14 adda "ekran = kayıt" eşitliğini sınar. Uç artık `ob_start` + `display_errors=0` ile ara çıktıyı JSON'dan atar.
- **Yeni Beyan'da analiz tarihleri + turuncu Düzenle (v353):** Analiz sekmesindeki Numune Alındı / Analiz Sonuç tarihleri artık oluşturma formunda da çizilir (eskiden `$is_edit` koşuluyla yalnız düzenlemede vardı; `beyan_create.php` bu alanları zaten okuyup kaydediyordu). Önizleme üst çubuğundaki Düzenle `.btn-duzenle` ile turuncu (açık + koyu). Test: e2e `J` bölümü.
- **Şoför Adı + Şoför Telefonu (v356, sahip GO verdi — 2 nullable ADD COLUMN):** `customs_declarations.driver_name VARCHAR(150)` ve
  `driver_phone VARCHAR(40)` (auto-migration `config/helpers.php`, v343 `foreach` kalıbı). Form: Parti/Sevkiyat kartının 3. satırında Dorse
  Plaka'dan sonra (`_beyan_form.php`; `$field(..., 'tel')` kipi YALNIZ telefonda `type="tel"` + `inputmode="tel"` verir, `data-uppercase` basmaz;
  `style.css` sonunda `form.bf` için YALNIZ-EKLEME `input[type="tel"]` girdi kuralı (mevcut satırlara dokunulmaz — `pdks_faz1b_static_smoke` silinen satırı reddeder); yeni `data-ic="telefon"` simgesi `form.bf, .bv` kapsamında, açık + koyu tema).
  **Şoför Adı = öneri alanı** (`beyan_suggest_fields()['driver_name']`, KV `beyan_defs_driver_name`, max 150): "+ ekle", × ile silme
  (`api_beyan_tanim_ekle/sil.php`, `contact.write` kapısında) ve temiz başlangıç (`BEYAN_ONERI_BASLANGIC`) aynen geçerli; yükleme kaydındaki
  `material_definitions` `sofor` tipiyle KARIŞMAZ (`type` bilerek boş). `beyan_suggest_form_data()` geçmiş önerileri yeni kolondan okur — kolon yoksa
  öneriler sessizce boş kalır, bu yüzden test şemalarına kolon eklendi. **Telefon öneri listesine GİRMEZ**: `beyan_telefon_normalize()` (saf; yalnız rakam
  ve `+ ( ) - .` boşluk, ardışık boşluk tekler, ≤40) + `beyan_veri_dogrula()` alan gönderildiyse 7–15 rakam ister ("Şoför telefonu 7–15 haneli olmalıdır.").
  İzin: `beyan_permission_fields()` **`contact`** grubu (kişisel veri) — `beyan_redact_fields`/`beyan_protect_input` önizleme + düzenlemede otomatik
  uygular. Edit'te alan HİÇ gönderilmezse eski değer korunur (`tractor_plate` listesinde). **Kopyala'ya (`beyan_kopyala.php`), WhatsApp metnine
  (`beyan_whatsapp_metin`), audit değerlerine ve toplu yola (`beyan_insert`) GİRMEZ**; önizlemede Lojistik / Alıcı kartında Dorse Plaka'dan sonra
  (boşsa '—', `tel:` bağlantısı yok). Test: `beyan_ui_smoke.php` `[şoför]` satırları (oluştur→DB→düzenle→önizleme, geçersiz telefon, yetkisiz kullanıcı,
  gönderilmeyen alan, durum şeridi) · `beyan_suggest_smoke.php` · `beyan_data_smoke.php` · `beyan_kopyala_smoke.php` · `beyan_permissions_smoke.php` ·
  `beyan_workspace_permissions_smoke.js`.
- **Şoför Adı → Şoför Telefonu bağı (v361, migration YOK):** beyan formunda şoför seçilince/yazılıp çıkılınca (`change`) bilinen telefonu kendiliğinden gelir.
  Veri `beyan_suggest_form_data()['drivers']` = `{ŞOFÖR ADI: son telefon}` (geçmiş beyanlardan, `BEYAN_ONERI_BASLANGIC` sonrası; aynı şoförün EN SON telefonu;
  ad `beyan_suggest_normalize` ile büyük harf/TR-duyarsız birleşir; öneriden silinen şoför haritadan da çıkar; telefon `beyan_telefon_normalize`; AYRI sorgu — kolon yoksa
  öneri listeleri etkilenmez) → `_beyan_form.php` `#beyanSuggestMeta.drivers` (**`contact.read` yoksa boş**). İstemci `assets/app.js` öneri bloğunda, gümrük adresi bağıyla
  AYNI kural: telefon yalnız BOŞKEN ya da önceki OTOMATİK değerken değişir (`lastAutoPhone`) — elle yazılan/düzeltilen telefon ezilmez; telefonu bilinmeyen şoförde önceki
  otomatik telefon temizlenir. Yükleme kaydındaki `record_sofor_phone_map()` KULLANILMAZ (temiz başlangıç kararı; geri ekleme istenirse ayrı karar). Yeni beyan kaydedildikçe harita kendiliğinden öğrenir.
  Test: `beyan_suggest_smoke.php` (drivers) · `beyan_ui_smoke.php` `[şoför-bağ]` + `[yetki]` · `node scripts/beyan_workspace_smoke.js` (şoför→telefon satırları).
- **Bekleyen Beyanlar — Yazdır / PDF Gönder (v357, migration YOK, yeni yetki YOK):** `beyanlar.php` "İşlem Bekleyen Beyanlar" kartının başlığında
  **🖨️ Yazdır** ve **📤 PDF Gönder** (masaüstünde "⬇️ PDF İndir") düğmeleri (`.bl-aksiyon`, `#bekleyenYazdirBtn` / `#bekleyenPdfBtn`; kartta satır
  yoksa YOK). Çalışma biçimi Hal Kayıt › Bildirim Çoklu Künye Basım ile AYNI (`halkayit/app.html` `topluYazdirPenceresiAc`/`topluPdfPaylas`): Yazdır = yeni
  pencerede rapor + yazdırma penceresi (A4 YATAY); PDF = `halkayit/html2canvas.min.js` + `jspdf.umd.min.js` YALNIZ ilk basışta yüklenir, **telefonda Web
  Share API ile dosya paylaşılır (WhatsApp paylaşım listesinde açılır), masaüstünde PDF iner**. Paylaşım dokunma izni zaman aşımına uğrarsa (iOS,
  `NotAllowedError`) PDF saklanır, düğme "PDF hazır — Paylaş" olur, sonraki dokunuş paylaşır. Sayfa kesimi satır sınırında, 2. sayfadan itibaren tablo başlığı
  tekrarlanır. **Kapsam = kartta o an görünen sayfa** (arama + sayfalama aynen; ekranda görüneni basar). Veri sunucudan hazır gelir:
  `beyan_bekleyen_yazdir_veri()` (config/helpers.php, saf) → `<script type="application/json" id="bekleyenYazdirVeri">` (JSON_HEX_* ile `</script>` kıramaz);
  sütunlar ekrandaki tablo (**tek "Plaka", "Ülke" ve "Durum" sütunları YOK — v358/v359**; ekran listesinde kalır) + sonda **Şoför Adı / Şoför Telefonu / Çekici Plaka (`tractor_plate`) / Dorse Plaka (`vehicle_plate`)**; `Alıcı` + şoför sütunları yalnız `contact.read`, `HKS Firma`/`Ülke` yalnız `hks.read` ile
  gelir (satırlar `beyan_redact_fields()`'ten geçmiş); TOPLAM satırı Palet/Kasa/Brüt/Net. **v359: aynı dorse plakalı (≥2 satır) beyanların Parti No hücresi aynı renkte** — sunucu `satir_grup` (satır başına grup no / null; plaka büyük harf + yalnız harf/rakam ile karşılaştırılır, tek kalan/plakasız null), renk paleti `assets/beyan_yazdir.js` `RENKLER` (açık tonlar + `print-color-adjust:exact`). İstemci `assets/beyan_yazdir.js` (yalnız beyanlar.php'de yüklenir;
  maliyet.js/hesap.js emsali — app.js'e dokunmaz); hücreler istemcide kaçışlanır. Yeni sütun = YALNIZ `beyan_bekleyen_yazdir_veri()`'ye ekle.
  Test: `php scripts/beyan_ui_smoke.php` (`[yazdır]` satırları) · `node scripts/beyan_yazdir_smoke.js` (kendi fixture'ını `--render-liste` ile üretir:
  masaüstü + telefon, yazdırma penceresi, gerçek PDF indirme, çok sayfa, Web Share, izin reddi).
- **Aynı plaka = aynı Parti No rengi (v365):** "İşlem Bekleyen" listesinde (masaüstü tablo + mobil kart; Kapanan bölümde YOK) dorse plakası aynı olan ≥2 satırın `.bn-parti` rozeti aynı renkte (`bn-g bn-g0..9`, style.css v365 bloğu, açık+koyu). Kural yazdırmayla ORTAK: `beyan_plaka_grup_listesi()` (helpers.php) — iki yerde ayrı yazma. Tek/boş plaka mavi kalır. Sayfa başına (50 satır) hesaplanır. Migration YOK.
- **HKS GÖNDERİLDİ → otomatik YÜKLENDİ (v390, sahip kararı; migration YOK):** bağ `gonderildi` olunca beyan durumu `yuklendi` olur — TEK yer `beyan_hks_gonderildi_yuklendi()` (helpers.php), iki çağıran: `beyan_hks_taslak_isaretle('gonderildi')` (Hal Kayıt gönderimi) ve `beyan_hks_bag_duzelt('gonderildi_isaretle')`. Yalnız BEKLEYEN durumlar taşınır; zaten kapalı (`beyan_kapali_durumlar()` = yüklendi/iptal/red) beyana DOKUNULMAZ (iptal/red HKS gönderimiyle ezilmez). Taslak silme / Tekrar Aktif Et durumu değiştirmez. Audit `beyan_status_change` + `otomatik='hks_gonderildi'`; hata yutulur (HKS akışını kesmez). Eski (v390 öncesi) gönderilmiş beyanlar kendiliğinden taşınmaz — toplu düzeltme ayrı GO ister. Test: `beyan_ui_smoke.php` `[yüklendi]` satırları.
- **Önizlemede "↺ Tekrar Aktif Et" (v389):** Düzenle'deki "Hal Bildirim Durumu" bölümü kullanıcı kararıyla GİZLİ (`hidden`, test sabitliyor) olduğundan taslağı silinmiş beyanda düğme `beyan_view.php` bildirim kartının içindedir (`silindi` bağ + `beyan.notify` + `hks.write`; POST `beyan_edit.php` `hks_bag_islem=tekrar_aktif`).
- **Beyan formunda plaka `beyan_hks_form_bolumu()` İÇİNDEDİR**, "Temel
  Bilgiler"de değil — bildirim için gereken dört alan tek yerde. Katalog boş
  olsa bile çizilir (plaka katalogdan bağımsız). **İkinci bir `vehicle_plate`
  alanı açma** — aynı `name` ile iki alan POST'ta çakışır.
  Bölüm geniş ekranda sağ panelde, küçük ekranda sekmelerin altındadır.
  İki form `_beyan_form.php` ortak şablonunu kullanır.
- **Yeni Beyan görsel katmanı** (`form.bf` — style.css "YENİ BEYAN FORMU" bloğu):
  oluşturma ve düzenleme formuna uygulanır; `beyan_view` ayrı görünümünü korur.
  Kancalar: bölümde `data-bf="wa|hks|temel|urun|lojistik|durum"`
  (renk), başlıkta ve her `.form-group`'ta `data-ic="<simge>"` (CSS mask — rengi
  temadan gelir; simge adları blok sonundaki listede). Başlık emojisi
  `.bf-emoji` içinde durur ve bu formda gizlenir — `beyan_hks_form_bolumu()`
  ortak olduğu için form dışında emoji yedeği korunur. **Yeni alan eklerken
  `.form-group`'a `data-ic` ver**, yoksa sol karo çizilmez (alan yine çalışır).
  Metni Ayrıştır etiketi JS'te sabit değildir, butonun kendi metninden geri yazılır.
- **Yazarak aranabilir select** (`data-aramali="ipucu"` — app.js): Hal Kayıt
  panelindeki "İhracat Yapılan Ülke" kutusunun aynısı. **Asıl `<select>` DOM'da
  KALIR**, yalnız görsel olarak gizlenir — değeri o taşır, dolayısıyla POST,
  sunucu doğrulaması ve mevcut okuma/yazma kodu değişmeden çalışır. Önüne
  `datalist` bağlı bir metin kutusu eklenir; yazılan metin option adlarıyla
  **TAM** eşleşince değer atanır ve `change` yayılır. Eşleşme yoksa kutu uyarı
  rengine döner (`.ara-sec-bos`), sessizce boş kalmaz. Select'i `<input>` ile
  DEĞİŞTİRME — öneri listesi option'lardan üretilir ve değer kaybolur.
  `beyan_hks_form_bolumu()` bunu **yalnız 10'dan uzun listelerde** açar; kısa
  listede arama kutusu fazladan tıklama demektir (halkayit/app.html'in kendi
  gerekçesiyle aynı).
- **Plaka BOŞLUKSUZ + büyük harf** — HKS böyle bekler.
  `beyan_plaka_normalize()` TEK doğruluk kaynağıdır ve ÜÇ kaydetme yolunda da
  çağrılır (`beyan_create` / `beyan_edit` / `bb_taslak_kur`). İstemcideki
  `data-nospace` (app.js, imleç korumalı) yalnız kolaylıktır — JS kapalıysa,
  otomatik doldurmada veya yapıştırmada sunucu yine boşluksuz kaydeder.
  Plaka alanında **örnek (placeholder) metin yok**.
- `hks_eslesme_yaz()` **taşınabilir upsert** kullanır (UPDATE→INSERT→UPDATE);
  `ON DUPLICATE KEY UPDATE` MySQL'e özgüydü ve testlerde sessizce başarısız
  oluyordu — öğrenme hiç doğrulanamıyordu.
- **Sıfat/tür varsayılanı KURAL TABANLI** (`bb_varsayilanlar` — sıfat "İhracat",
  tür "Satış"): `app.html`'deki `listeleriUygula` kuralının aynasıdır, **ikisini
  birlikte değiştir**. "Son kullanılan"dan OKUNMAZ — `hks_kv.sonlar_<firmaId>`
  yalnız plaka/ülke/ürün/karşı taraf tutar, sıfat ve tür orada yoktur.
  Karşılığı bulunamazsa boş döner; **asla id uydurulmaz**.
- **Toplu bildirim** (`beyanlar.php` → `toplu_hazirla` / `toplu_olustur`): seçilen
  her beyan için AYRI taslak. Tekil akışla **aynı** `bb_taslak_kur()`'dan geçer —
  ikinci bir kurulum yolu açma. O fonksiyon `bb_cikti()` ÇAĞIRMAZ, `return`
  eder: bir satırın hatası isteği sonlandırırsa diğer satırlar hiç denenmez.
  Sonuç satır satır döner ve başarısızlar kullanıcıya **yazılır**. Üst sınır
  `BB_TOPLU_LIMIT`. Aynı beyan hem tabloda hem mobil kartta seçilebildiği için
  id'ler istemcide tekilleştirilir.
- **Uygunluk kapısı ÜÇ yerde yaşıyor** — `beyan_view.php` (buton), `beyanlar.php`
  (toplu seçim), `index.php` (sayaç). Kuralı değiştirirken **üçünü birden**
  güncelle, yoksa listede uygun görünen satır uç noktada reddedilir.
- **Derin bağlantı:** `halkayit/index.php?ekran=taslaklar` → iframe'e `#taslaklar`
  olarak geçer (beyaz liste, serbest metin yazılmaz). Taslaklar firma bazlı izole
  olduğu için SPA istegi BEKLETİR ve firma seçilince bir kez tüketir — otomatik
  firma seçmez (yanlış firmanın taslaklarını açma riski).
- **"Yurt Dışı" işletme türü** (`bb_yurtdisi_isletme_turu` — helpers.php) taslağın
  zorunlu alanıdır ve katalogdan ADA göre bulunur. Bulunamazsa taslak
  OLUŞTURULAMAZ (409) — id uydurulmaz. Kural TEK yerdedir; kopyalama.
- **v330 — Beyan listesi + önizleme:** Liste (`beyanlar.php` + `_beyan_liste.php`) Plaka · HKS Firma · Ülke
  sütunlarını taşır (firma adı `beyan_hks_firma_adlari()` tek sorgu), parti no 3B rozet (`.bn-parti`),
  satır sonunda ⧉ Kopyala. **Kopyala** = `beyan_kopyala.php` (POST+CSRF+`beyan.write`): TASLAK kopya açar,
  `beyan_edit.php`'ye yönlendirir; parti no / plaka / ham metin / analiz / plan bağı / HKS bildirim izi
  KOPYALANMAZ (eski plakanın yeni beyanda kalması yanlış bildirimdir), HKS eşleştirmesi kopyalanır.
  **Eşleştir ↔ HKS Taslağı Oluştur DEĞİŞİMLİ düğme:** firma/ürün/ülke eksikse `#hksEslestirBtn`
  (`api_beyan_bildirim.php?action=eslestir` → `beyan_hks_otomatik_eslestir()`: YALNIZ boş alanları tahminle
  doldurur, kayıtlıyı ezmez, id uydurmaz), tamsa `#hksOpenBtn`. Taslak oluşunca doğrudan
  `halkayit/index.php?ekran=taslaklar`'a gidilir. Önizleme bildirim kartı 5 hücre: **HKS Firma · Ülke ·
  Ürün · Net KG · Plaka**; bölüm başlık bandı bir ton koyu (`.bv … > .beyan-section-title`).
- **Bildirim kartı** (`beyan_view.php`, `.bk-*`): sırası **Ülke · Ürün · Net KG ·
  Plaka** — HKS bildiriminin dört zorunlu bilgisi, tek bakışta "hazır mı"
  cevabı. Eksik alan sessiz `—` değil, **işaretli**. Net KG bilerek iki yerde
  (kart + Ürün Bilgileri). **Bildirim Yap butonu ve engel sebebi bu kartın
  İÇİNDE** (`.bk-alt` / `.bk-engel`) — buton etkilediği verinin yanında durur
  ve pasifse sebebi ekranda yazar (`title` ipucu mobilde görünmez). Bildirim
  bölümü sayfada **TEK**; alt bölümde tekrar etme.
- **Üst eylem çubuğu yalnız sayfa düzeyi eylemleri taşır** (Beyanlar / Düzenle /
  Sil). Bağlama ait butonlar kendi kartlarının içine konur — beş buton iki
  satıra sarıyordu ve hangisinin neyi yaptığı belli olmuyordu. Tek birincil
  buton (Düzenle); satır içi renk verme.
- **`.btn:disabled` GLOBAL kuralı** (`style.css`) — bu kural yokken pasif buton
  aktifle birebir aynı görünüyordu (uygulama genelinde: `_form.php` palet
  uygula, `beyanlar.php` toplu kaydet, `audit.php` onay butonları). Silme.
- **Silme POST'tur** — `beyan_delete.php` GET'i bilerek reddeder (GET veri
  değiştirmez). `beyan_edit.php`'deki Sil uzun süre `<a href="beyan_delete.php?id=">`
  idi: tıklanınca GET gidiyor, uç nokta sessizce listeye geri atıyor ve
  **hiçbir şey olmuyordu**. Artık `formaction`/`formmethod="post"` ile
  çevreleyen formu POST eder (iç içe `<form>` geçersizdir) ve form `id`'yi
  gizli alanda taşır — `beyan_delete` onu `$_POST`'tan okur.
  **`beyan_delete.php`'ye `<a href>` ile bağlanma**; test bunu engelliyor.
- **Düzenle formunda Güncelle DOM'da Sil'den ÖNCE** (`order` ile görsel sıra
  korunur). Enter tuşu formun İLK submit butonunu tetikler; Sil önce olsaydı
  metin kutusunda Enter silme başlatırdı.
- **Listede silme YOK** — bilinçli; silme detay ve düzenle ekranlarından yapılır.
- **Beyan listesi İKİ BÖLÜM** (`beyanlar.php`, Sprint Beyan-Liste-02): **ÜSTTE
  "⏳ İşlem Bekleyen Beyanlar"** (`.beyan-blok-acik`), **ALTTA "✅ Kapanan
  Beyanlar" + filtre şeridi** (`.beyan-blok-kapanmis`). Bölümü DURUM belirler:
  `beyan_kapali_durumlar()` (`yuklendi`/`iptal`/`red`) alta, geri kalan HER
  durum üste. Yeni bir durum eklersen o listeyi gözden geçir — listede
  olmayan durum ÜST bölüme düşer (güvenli varsayılan: gözden kaçmaz).
  - **Bölümlerin araması ve sayfalaması bağımsızdır.** Üst bölüm `bek_q` /
    `bek_page`, alt bölüm `q` / `page` kullanır. Diğer bölümün parametreleri
    geçişlerde korunur; eski bekleyen kayıtlar da aranabilir.
  - **Filtre + sayfalama ALT bölümün içinde yaşar** (`$w_kapali` / `$p_kapali`).
    Durum pilleri yalnız **kapalı** durumları listeler; bekleyen bir durum pili
    seçilse arşiv sebepsiz boş görünürdü. Eski yer imi (`?status=taslak`)
    **filtre olarak uygulanmaz** — `$valid_statuses = beyan_kapali_durumlar()`.
  - **Satır/kart biçimi `_beyan_liste.php` partial'ında** — iki bölüm × masaüstü/
    mobil = dört kopya olurdu, ayrışırdı. Partial **fonksiyon TANIMLAMAZ**
    (sayfa testte iki kez include edilir) ve `$sec_rows` / `$sec_secim` /
    `$bildirim_uygun` bekler. Satır düzenini orada değiştir, beyanlar.php'de değil.
  - **"Tümü" seçim kutusu `class="bb-tumu"`, id DEĞİL** — sayfada iki tane var.
    Her biri **yalnız kendi tablosunu** seçer (arşiv satırları istemeden
    işaretlenmesin); mobil kart eşleri değere göre eşitlenir.
  - Uygunluk kapısı (`$bildirim_uygun`) ve toplu bildirim **bölümden bağımsız** —
    `$rows` iki bölümün birleşimidir, aktif bağ sorgusu tek sorguda kalır.
  - İki bölüm de `BEYAN_PER_PAGE` (50) ile sayfalanır; 200 kayıt kesme sınırı yoktur.
- **Bölüm başlıklarında açıklama pili YOK (v360, sahip kararı):** "Onay ve yükleme bekleyen beyan kayıtlarıdır." / "Yükleme tamamlanan, iptal veya reddedilen kayıtlardır." yazıları (`.bl-bilgi`) kaldırıldı — gereksiz yer kaplıyordu; geri ekleme.
- **Filtre şeridi tek satır** (`beyanlar.php`): `[arama] [Ara] [▾ Filtre] [Temizle]`.
  Detay paneli **her genişlikte katlanır** — eskiden `.beyan-filter-toggle`
  yalnız `<768px`'de görünürdü, masaüstünde panel kalıcı açık kalıyor ve on
  durum pili üç satıra sarıp beş girdiyle birlikte liste üstünde ~200px
  kaplıyordu (şimdi kapalıyken 42px). **`@media (min-width:768px)` içine
  `.beyan-filter-toggle{display:none}` geri koyma** — test bunu engelliyor.
- **Durum pilleri panelin İÇİNDE, tek satır** (`.bff-durum`): `flex-wrap:nowrap`
  + `overflow-x:auto` — sarmaz, taşarsa yatay kayar. Bağlantı (link) olarak
  kalmaları bilinçli: tek tıkla filtreler, forma bağlı değiller, JS kapalıyken
  de çalışırlar. Detay filtresi etkinken panel **açık** gelir ve toggle'da
  etkin filtrenin adı rozet olarak yazar (`$detay_ozet` → `.bft-rozet`), böylece
  panel kapalıyken de listenin neye göre süzüldüğü görünür.
- **`scripts/beyan_ui_smoke.php` artık `beyanlar.php`'yi de render eder**
  (`render_liste()` — sayfa iki kez include edildiği için üst seviye
  `function`/`const` bildirimleri koşullu sarılır). Liste düzenini değiştirince
  çalıştır.
- **Durum şeridi — TÜM durumlar tıklanabilir** (`beyan_view.php`, Sprint
  Beyan-Durum-01): dokuz durumun hepsi pil olarak durur, **seçili olan çerçeve
  + halka + ✓ ile işaretli** (`.beyan-durum-secili`), akıştaki sıradaki
  durum(lar) **kesik çerçeve** (`.beyan-durum-onerilen` — ipucu, KAPI DEĞİL).
  Ara durumlara tek tek tıklamak gerekmez; **doğrudan sonuca gidilebilir** ve
  terminal durumdan geri dönülebilir (şerit artık terminal durumda da çizilir).
  - **Şerit TEK formdur**, her durum bir `<button type="submit" name="status"
    value="...">`. Eskiden her buton kendi formunu ve ~26 gizli alanını
    taşıyordu; dokuz durumla bu 230+ gizli alan demekti. **`status` için AYRI
    bir hidden alan AÇMA** — aynı `name` iki yerden gelirse hangisinin
    kazandığı belirsizdir; değeri butonun kendi `value`'su taşır.
  - **Şerit `status_only=1` kullanır.** Sunucu güncel satırı kilitleyip yalnız
    durum/not/analiz tarihlerini değiştirir; gönderilmeyen alanları korur.
    Serbest geçişler sürer. Tam düzenleme `edit_token` ile eşzamanlı değişikliği
    reddeder. Gizli tam satır kopyasını yeniden gönderme.
  - Görsel sıra `beyan_durum_akis_sirasi()` (helpers.php): ana akış soldan sağa,
    **red/iptal en sonda** (`beyan_statuses()` sırası değil — orada `iptal`
    akışın ortasında). Listede olmayan durum SONA eklenir, kaybolmaz.
  - **`beyan_edit.php`'deki durum `<select>`'i AYNI kurala tabi** — tüm durumlar,
    aynı akış sırası. Eskiden sonraki durumlarla sınırlıydı ve terminal durumda
    "değiştirilemez" yazıyordu; sunucu hiçbir zaman kısıtlamadığı için bu iddia
    **yanlıştı**. Kuralı değiştirirken **iki ekranı birden** güncelle; test
    ikisini de sabitliyor.
  - **RED hâlâ not ister** (`prompt_note` → `analysis_note`); sunucu notsuz
    red'i reddeder. `temiz`/`red` seçilince `analysis_result_at` otomatik dolar.
  - Şerit tüm alanları hidden gönderir (tam güncelleme dalı): **yeni kolon
    eklersen o listeye de ekle**, yoksa her durum değişikliğinde silinir.

---

- **v502 — Silme kuralı güncellendi (sahip isteği, v501'in "Yapılanlar ASLA silinmez" maddesini DEĞİŞTİRİR):** `bsz_kaldir()` artık iki durumu siler: (a) "Bildirim Bekleyenler"deki kayıt (bekleyen Hal Kayıt taslağı da silinir) ve (b) **taslağı silinmiş kayıt** (`durum='silindi'`, "Taslak silindi" rozeti — Hal Kayıt'a dokunulmaz, satır soft `kaldirildi`). **GÖNDERİLMİŞ (`gonderildi`) ve "Durum belirsiz" kayıt ASLA silinmez** (düğme yok + sunucu 409; belirsiz = taslak yok ama gönderilmiş olabilir). Sil düğmesi `_beyansiz_liste.php`'de `goster` ∈ {bekliyor, silindi}. Test: `beyansiz_yukleme_smoke` (sil) · `beyansiz_sekme_ui_smoke` (3b).
- **v501 — Beyansız yükleme kaydını SİLME (sahip isteği, migration YOK):** hatalı/fazla açılan kayıt için yeni yetki **`beyan.yukleme_sil`** (`can_yukleme_sil()`: admin her zaman; başka rol yalnız Roller'den "Yükleme bildir sil (beyansız)" verilirse; `beyan.write/yukleme_bildir` VERMEZ; `$all_p` seed'inde yalnız admin). **YALNIZ "Bildirim Bekleyenler"deki kayıt silinir** (durum `taslak` + Hal Kayıt taslağı canlı); **"Bildirim Yapılanlar" (gönderilen · taslağı silinen · durum belirsiz) ASLA silinemez** (düğme yok + sunucu 409). `api_beyansiz_yukleme.php?action=sil` (POST JSON + CSRF; kapı: sil yetkisi, diğer eylemler yine `can_yukleme_bildir()`) → `bsz_kaldir()`: önce bekleyen Hal Kayıt taslağını siler (0 satır silindiyse aynı anda gönderiliyor olabilir → 409, dokunmaz), sonra satırı SOFT `durum='kaldirildi'` yapar (şemasız: `durum` VARCHAR; satır + audit `beyansiz_yukleme_sil` kalır, kişisel veri yok). `kaldirildi` kayıtlar liste/sayaç/aynı-plaka uyarısından çıkar (`bsz_bolumle`, `bsz_ayni_plaka_bugun`). Liste partial'ında (`_beyansiz_liste.php`) 🗑 Sil düğmesi yalnız `goster=bekliyor` satırında (masaüstü + mobil), onay penceresi `_beyansiz_sekme.php` sonundaki betikte. Test: `beyansiz_yukleme_smoke` (sil uçtan uca) · `beyansiz_sekme_ui_smoke` (3b) · `beyan_permissions_smoke` · `rol_kapilari_smoke`.
- **v500 — Yükleme Bildir sadeleşti + Taslaklar'da Kopyala → firma değiştir (sahip isteği, migration YOK, yeni yetki YOK):** (1) Pencerede **Araç Plakası ve Net KG** kutuları Hal Kayıt bildirim ekranındaki turuncu kutu görünümünde (`.bsz-vurgu`, açık+koyu; özgüllük `form.bf.bsz-bf .form-group.bsz-vurgu[data-ic] > input.form-control`). (2) "Yurt içi — **firma**": liste YALNIZ firmalar (Kişi Havuzu'nda 10 haneli VKN; kişi/11 haneli TC listelenmez ve sunucuda reddedilir — `bsz_kisi_secenekleri()` `LENGTH(tc)=10`). Listede yoksa altındaki "＋ Listede yok — yeni firma ekle" yalnız **ad** alır (`yeniFirmaAd`, 3-150 karakter; Kişi Havuzu'na YAZILMAZ, `karsi_kisi_id` NULL); taslak `ikinciAd` ile eksik yazılır, TC/VKN ve diğerleri Hal Kayıt'ta Düzenle ile tamamlanır (`hks_ikinci_tc_hatasi($o, $adlaYetin)` yalnız eksik kipte TC boş+ad varsa geçer; dolu bozuk TC yine reddedilir; gönderim katı). (3) **İşletme Türü (gidilen yer) KALKTI** (alan, `hazirla` listesi ve taslaktaki `isletmeTuruId`) — fiyatı girip taslağı tamamlayan kişi Hal Kayıt'ta seçer (v499'daki işletme türü maddesi geçersiz). (4) Hal Kayıt **Taslaklar → 📋 Kopyala** artık "Tekrar gönder" gibi menü açar (`gTekrarMenu` paylaşılır, `tekrarMenuKip`): **Aynı firma ile kopyala** (eski kopya-form davranışı AYNEN) · **Firma değiştir…** (`firmaSecPencere`, kip `taslak`) → `halkayit/api.php` `taslak_kopya_firmaya` (POST, CSRF merkezi kapıda, muaf DEĞİL; `API_ROTA`+`API_GOVDE_CSRF`): kaynak taslak `id + firma_id` ile okunur, `hks_gonderim_kopyasi()` beyaz listesinden + `hks_gonderim_tohumu(..., firmaDegisti=true)` ile (kişisel veri/`kaynak`/bayrak yok, künyeli taslak plana çevrilir, `sifatId`/`gidecekIsyeri*` boşaltılır) hedef firmaya **doğrudan** `hks_taslak_olustur()` ile yazılır (ikinci yazma yolu YOK), kaynak SİLİNMEZ, audit `hks_taslak_kopya` (kişisel veri yok), SPA hedef firmaya geçip Taslaklar'ı açar. Kopya `ortak.sifatBekliyor=true` taşır: `hks_bildirim_dogrula(..., $sifatBekleyenIzin)` YALNIZ taslak yazarken bildirimci sıfatını (ve yurt içinde gidecek işyerini) beklemez; `taslak_kaydet` bu bayrağı istemciden siler (Düzenle→Kaydet bayrağı kaldırır), `taslak_gonder` ve `taslak_fiyat_yaz` bayraklı taslağı reddeder (fail-closed). Kaynakta fiyat bekleyen/eksik (`tamamlanacak`) durum kopyada da sürer. Test: `beyansiz_yukleme_smoke.php` · `node scripts/beyansiz_yukleme_modal_smoke.js` · `hks_fiyatsiz_taslak_test.php` · `node scripts/hks_taslak_kopyala_smoke.js` · `hks_csrf_kapsam_smoke.php`.
- **v499 — Yükleme Bildir penceresi düzeni (sahip isteği, migration YOK, yeni yetki YOK):** pencere artık Yeni Beyan formunun ikonlu alan düzenini kullanır (`<form class="bf bsz-bf">` + `.beyan-section[data-bf]` + `.form-group[data-ic]`; ikon adları `diyez/bina/kure/kisi/depo/elma/terazi/arac` — yeni ikon `:is(form.bf,.bv)` listesinde). **Alıcı yönüne göre alanlar:** yurt dışı = YALNIZ Ülke (kişi/firma ve işletme türü gizli); yurt içi = Kişi/Firma + **İşletme Türü (gidilen yer)** (`isletmeTurleri`, "Yurt Dışı" türü HARİÇ; `hazirla` döner, `olustur` `isletmeTuruId`'yi katalogda doğrular, boş/Yurt Dışı → 400, `bsz_ortak_yurtici` `isletmeTuruId`'yi taslağa yazar). **Gidecek işyeri/adres ve karşı taraf sıfatı BU PENCEREDE YOK** (sahip kararı: canlı HKS sorgusu yapılmaz; Hal Kayıt'ta Düzenle ile tamamlanır, taslak yine `tamamlanacak`). **HKS Firma önceden seçili gelmez** ("Firma seçin"; `hazirla` `sonFirma` DÖNMEZ). **Net KG yazarken binlik nokta alır** (20000 → 20.000, imleç korunur; istemci tam sayı gönderir). Parti No alt yazısı ve fiyat/"taslak eksik açılır" açıklaması KALDIRILDI (yalnız "Bu işlem yalnızca TASLAK oluşturur; HKS'e bildirim GÖNDERİLMEZ." kaldı). **Tuzak:** `.form-group[data-ic]{display:grid}` `[hidden]`ı ezer → `form.bf .form-group[hidden]` + `.bsz-overlay [hidden]{display:none!important}` SİLME (eskiden Ülke+Kişi ikisi birden görünüyor, "Yine de bildir" boş bantla açılıyordu). Test: `beyansiz_yukleme_smoke.php` · `node scripts/beyansiz_yukleme_modal_smoke.js`.
- **v498 — Fiyat Hal Kayıt'ta GÖNDERİMDE girilir + "Yükleme Bildir" (beyansız yükleme) + 2 sekmeli Beyanlar + yeni yetki (sahip isteği; GO: yalnız `beyansiz_yukleme` tablosu, migrate.php kartından):**
  - **Fiyat gönderime kaydı.** Beyan ekranı (tekil `beyan_view.php` + toplu `beyanlar.php` penceresi) artık fiyat SORMAZ/ÖNERMEZ: `bb_taslak_kur(array $beyan,string $sifatId,string $turId,int $userId)` taslağı `ortak.fiyatBekliyor=true` ile ve `fiyat` anahtarı OLMADAN yazar (`bb_fiyat_onerileri()` silindi; maliyet/`cost_sheets` okuması yok; `beyan_hks_bildirim.fiyat` NULL, geçmişte "—"). Sözleşme (taslak JSON `ortak`): `fiyatBekliyor` true ya da anahtar YOK (true iken `fiyat` yok; yazıcı yalnız beyan uçları, temizleyen `halkayit/api.php taslak_fiyat_yaz`; SPA üretmez, `taslak_kaydet` istemciden geleni siler) · `tamamlanacak` true ya da YOK (yalnız yurt içi beyansız taslak; `taslak_fiyat_yaz` ona 409) · `kaynak` {tip:'beyan',beyanId,partiNo} | {tip:'beyansiz',kayitId,partiNo}. `hks_bildirim_dogrula($g,$planMi,$fiyatsizIzin,$eksikIzin)` yalnız taslak YAZARKEN gevşer (`hks_taslak_olustur` bayrakları ortak'tan türetir); GÖNDERİMDE katı çağrılır → fiyatsız/eksik taslak GİTMEZ (fail-closed). Gönderim penceresi `taslak_fiyat_yaz` ile fiyatı alır (öneri: aynı firma+ürün son gönderim fiyatı `sonFiyat`, otomatik doldurulmaz). `hks_gonderim_kopyasi` bayrakları KOPYALAMAZ. (Kapsam dışı bilinen hata: `api_beyan_bildirim.php` `hazirla`daki `$ulkeMetni` tanımsız.)
  - **Yükleme Bildir (beyansız).** `beyanlar.php` başlığında "🚚 Yükleme Bildir" (yalnız `can_yukleme_bildir()`) → `_yukleme_bildir_modal.php` → `api_beyansiz_yukleme.php` (`hazirla` · `plaka_kontrol` salt okunur · `olustur` CSRF). YALNIZ Satış; alanlar Parti No (opsiyonel, HKS'e gitmez) · HKS Firma · Ülke (→ yurt dışı/ihracat) VEYA Kişi/Firma (Kişi Havuzu → yurt içi) · Ürün · Net KG · Plaka; fiyat YOK. Sunucu istemciden YALNIZ id alır (ad/TC katalog/havuzdan; `$govde['urunAd'|'ulkeAd'|'firmaAd'|'tc']` OKUNMAZ). Yurt dışı ortak `bsz_ortak_yurtdisi()` = `bb_taslak_kur` alan kümesi (parite testli); yurt içi EKSİK taslak `bsz_ortak_yurtici()` (`yurtIci`,`tamamlanacak`,`fiyatBekliyor`,`fiyatGonder`; sıfat/işyeri/il yok; TC biçimi hâlâ doğrulanır) — Hal Kayıt'ta Düzenle ile tamamlanır. Çift tıklama: `istek_kodu` UNIQUE → 409 `tekrar`; aynı gün aynı plaka: 409 `ayni_plaka` + liste, "Yine de bildir" `plakaOnay` ile (ENGEL değil). Taslak hata verirse beyansiz satırı silinir. Audit `beyansiz_yukleme_olustur` (kişisel veri YOK).
  - **Tablo `beyansiz_yukleme`** (`config/beyansiz_yukleme.php`, `beyansiz_yukleme_migrate()`; kurulum yalnız `migrate.php#beyansiz-yukleme` kartı — canlıda VE test sitesinde ayrı basılır; kart sayısı 24). Karşı kişi için yalnız id+ad (TC/cep/doğum saklanmaz). Durum `taslak|gonderildi|silindi`; **taslak canlılığı PHP'de** `bsz_canli_taslak_idleri()` (parçalı IN; `hks_taslaklar` ile JOIN/EXISTS/COLLATE YOK — çapraz-collation): bekleyen = `taslak` VE canlı taslak, yapılan = gönderildi + silindi + ölü taslak ("Durum belirsiz"). Köprü: `beyan_hks_taslak_isaretle()`/`_tasi()` beyan bağını `beyan_hks_taslak_isaretle_beyan()`'a, beyansız satırı AYRI `beyansiz_taslak_isaretle()`'ye böler (ikinci UPDATE beyan bağı olmadan da çalışır; `$ek['beyansizId']` yalnız gönderimde yedek, yalnız `durum='taslak'` satır; tablo yoksa sessiz).
  - **Sekmeler.** `beyanlar.php?sekme=beyanlar|beyansiz` (varsayılan beyanlar; beyan.read yoksa beyansiz). Beyansız sekmesi erken dönüş yapar (`_beyansiz_sekme.php`, `_beyansiz_liste.php`; beyan sorguları ÇALIŞMAZ); görünürlük `can_yukleme_bildir() || (beyan.read && can_beyan('hks.read'))`. Başlık+sekme çubuğu `_beyanlar_baslik.php` (iki sekmenin ortak parçası). Beyansız satırda `hks.access` yoksa not "Hal Kayıt'ta gönderim için yetkili kişiye iletin"; karşı kişi adı `contact.read` ya da Yükleme Bildir yetkisiyle. CSS yalnız `.bsz-*` (style.css sonu).
  - **Yeni yetki `beyan.yukleme_bildir`** (`permission_catalog()` Beyanlar grubu; matris "Yükleme bildir (beyansız)"; `$all_p` seed'inde YALNIZ admin). `beyan.write` bunu VERMEZ (beyan_permissions.php değişmedi). TEK BAŞINA Beyanlar sayfasına girer: `beyan_sayfa_erisimi()` (= beyan.read ‖ yukleme_bildir ‖ admin) `beyanlar.php`, `first_allowed_page()`, sidebar `$p_beyan`, `nav_alt_izinler()`, topnav, `index.php` (kart + `$_ops_show`) için TEK kapıdır. **`can_yukleme_bildir()`/`beyan_sayfa_erisimi()` `first_allowed_page()`'in hemen üstündedir** (testler o aralığı eval eder; fonksiyonları başka yere taşıma).
  - **Test:** `beyansiz_yukleme_smoke.php` (migrate, bölümleme, isaretle/tasi, parite, statik + ALT SÜREÇTE API uçtan uca gerçek `hks_taslak_olustur`) · `beyansiz_sekme_ui_smoke.php` (beyanlar.php sekmeleri, yalnız-yukleme_bildir beyan sorgusu YOK) · `node scripts/beyansiz_yukleme_modal_smoke.js` (render: `beyansiz_yukleme_modal_render.php`) · `beyan_bildirim_smoke` · `beyan_ui_smoke` · `beyan_permissions_smoke` · `auth_permissions_smoke` · `rol_kapilari_smoke` · `role_permission_matrix_smoke` · `role_endpoint_gate_smoke` · `migrate_panel_smoke` · `dashboard_sidebar_parity_smoke`. Not: `pdks_faz1b_static_smoke`/`pdks_rapor_static_smoke` `git diff` ile "silinen satır" denetler — commit edilmemiş çalışma ağacında kırmızı görünür, commit sonrası geçer.
