# HKS Panel — PHP Sürümü (Entegrasyon Kılavuzu)

Bu paket, HKS (Hal Kayıt Sistemi) yurtdışı satış (ihracat) bildirimlerini
**web servis üzerinden, captchasız, tek istekte 100 künyeye kadar** yapan bir
panelin PHP + MySQL sürümüdür. Mevcut bir PHP paneline gömülmek üzere tasarlanmıştır.

> **Bu kılavuz, sistemi entegre edecek geliştirici/AI içindir.**

---

## 1. Dosyalar

| Dosya | Görevi |
|---|---|
| `index.html` | Arayüz (tek sayfa). Tüm ekranlar: firma seçimi, e Bildirim, taslaklar, gönderilenler. |
| `api.php` | Tüm istekleri karşılayan yönlendirici (`api.php?action=XXX`). JSON döner. |
| `hks_soap.php` | HKS web servisine cURL ile SOAP çağrıları. **Dokunmayın**, çekirdek mantık burada. |
| `db.php` | PDO bağlantısı, tablo oto-kurulumu, şifre AES-256 şifreleme. |
| `config.php` | DB bilgileri, şifreleme anahtarı, giriş ayarı, **endpoint seçimi** (`HKS_YENI_ENDPOINT`). **Doldurulacak tek dosya.** |
| `schema.sql` | Tablo şeması (bilgi amaçlı; tablolar zaten otomatik kurulur). |
| `../scripts/hks_endpoint_test.php` | Eski/yeni endpoint'i salt-okunur karşılaştırır (kayıt oluşturmaz). |
| `../scripts/hks_uretici_sevk_test.php` | Üreticiden Sevk Alım + kayıt durumu + DogumTarihi regresyon testleri (ağsız). |

---

## 2. Kurulum (3 adım)

1. **`config.php` doldurun:** MySQL host/db/kullanıcı/şifre + rastgele bir
   `HKS_SIFRELEME_ANAHTARI` (32+ karakter) yazın.
2. **Dosyaları sunucuya atın** (örn. `/hks/` klasörüne).
3. **`index.html`'i açın.** Tablolar ilk istekte otomatik oluşur; `schema.sql`
   çalıştırmak zorunlu değildir.

Not: Firma HKS şifreleri veritabanına **AES-256 ile şifreli** yazılır. Şifreleme
anahtarını sonradan değiştirirseniz eski firmalar çözülemez, yeniden girilmelidir.

---

## 3. Mevcut panele gömme (önerilen yaklaşım)

Arayüz tek bir `index.html`. Ana panelinize (sidebar'lı yapı) gömmek için iki yol:

- **Kolay yol:** `index.html`'i `<iframe>` içinde "Hal Bildirimi" menüsüne yerleştirin.
- **Bütünleşik yol:** `index.html`'in `<style>` ve `<body>` içeriğini kendi
  şablonunuza taşıyın. Tüm mantık sayfa sonundaki tek `<script>` bloğundadır;
  backend'e `api.php?action=...` üzerinden POST atar. Backend'i değiştirmeden
  sadece görünümü şablonunuza uyarlayabilirsiniz.

**Oturum/güvenlik:** Paneliniz zaten login kontrolü yapıyorsa, `api.php`'nin
başına kendi auth-guard'ınızı `require` edin ve `config.php`'de
`HKS_BASIT_GIRIS`'i `false` bırakın. Bağımsız çalışacaksa `true` yapıp
kullanıcı/şifre tanımlayın (HTTP Basic Auth).

---

## 4. API sözleşmesi (`api.php?action=`)

Tüm istekler **POST**, gövde JSON, cevap JSON. Hata olursa `{ "hata": "..." }`.

| action | Gövde | Döner |
|---|---|---|
| `firmalar` | — | `{firmalar:[{id,ad,vergiNo,renk,userName}]}` (şifre DÖNMEZ) |
| `firma_kaydet` | `{id?,ad,userName,password,servicePassword,vergiNo,renk}` | `{tamam:true}` |
| `firma_sil` | `{id}` | `{tamam:true}` |
| `sonlar` | — | `{plakalar,ulkeler,urunler}` (son kullanılanlar) |
| `listeler` | — | önbellekten referans listeleri veya `{bos:true}` |
| `listeler_yenile` | `{firmaId}` | HKS'ten çekip önbelleğe yazar |
| `kunyeler` | `{firmaId,urunId,aySayisi}` | `{kunyeler:[...]}` |
| `taslaklar` | — | `{taslaklar:[...]}` |
| `taslak_kaydet` | `{firmaId,satirlar,ortak}` | `{tamam:true}` |
| `taslak_sil` | `{id}` | `{tamam:true}` |
| `taslak_gonder` | `{id}` | HKS'e gönderir, `{genelHata,sonuclar}` döner |
| `gonderilenler` | — | `{gonderilenler:[...]}` (tek satır özetler) |

`ortak` alanı: `{fiyat, sifatId, bildirimTuruId, isletmeTuruId, ulkeId, ulkeAd,
urunId, urunAd, plaka, belgeNo, belgeTipiId}`.

---

## 5. HKS servisi hakkında kritik notlar (davranışı bozmayın)

Bu kurallar denenerek bulundu; `hks_soap.php` bunlara göre yazıldı:

- **Uçlar canlı:** `https://hks.hal.gov.tr/WebServices/{Bildirim,Genel,Urun}Service.svc`
- **Namespace çift slash içerir:** `http://www.gtb.gov.tr//WebServices` (yazım hatası değil).
- **Alan sırası şemaya bağlı:** zarf sırası `Istek, Password, ServicePassword, UserName`.
- **Referans künye sorgusunda tarih ZORUNLU.** Tarihsiz sorgu `GTBGLB00000001` verir.
- **"1 ay" sınırı, takvim ayı (AddMonths) aritmetiğiyle ölçülüyor, gün sayısıyla
  DEĞİL.** Geriye dönük sorgu bu yüzden pencerelere bölünür (`hks_guvenli_pencereler()`).
  İlk yaklaşım "bitişin günü, bir önceki ayın aynı gününe (kısaysa kırpılarak)
  taşınması" şeklindeydi; bitiş günü 29/30/31 olup hedef Şubat'a denk geldiğinde
  (örn. 29 Mart → 28 Şubat) bu, HKS'in kabul ettiğinden 1-3 gün daha uzun bir
  pencere üretip **"Bitiş Tarihi, başlangıç tarihinden en fazla 1 ay büyük
  olabilir"** hatasını tetikliyordu — çünkü Şubat'ın en geç günü (28/29), "1 ay
  sonrası"na hiçbir şekilde ulaşamıyor (matematiksel olarak imkânsız bir kısıt).
  Çözüm: sabit **27 günlük** adımlarla pencereleme — en kısa ay olan Şubat'ın
  (28 gün) bile altında kaldığından, HKS hangi kesin algoritmayı kullanırsa
  kullansın sınırı asla aşmaz.
- **`UrunId` fiilen zorunludur** (şemada opsiyonel görünse de). Arayüz ürün seçtirir.
- **`KisiSifat` filtresi bilerek gönderilmez** — gönderilince bazı künyeler (farklı
  sıfatla bildirilmiş olanlar) listeden düşüyor.
- **İhracat + Satış + YurtDisiMi=true** olduğunda ikinci kişi bilgisi gerekmez;
  referanslı satışta sadece `MalinMiktari` + `MalinSatisFiyat` yeterli.
- **Bu CANLI sistemdir.** Her gönderim gerçek bildirimdir, geri alınamaz, rüsum
  doğurur. Bu yüzden arayüzde "önce taslağa kaydet → sonra onayla → gönder"
  akışı var. Bu iki aşamalı güvenlik akışını kaldırmayın.

### 5.1 Bildirim türleri ve karşı taraf kuralı (kılavuz 0.1.14 — GTB HKS Geliştirici Kılavuzu, "Bildirim kayıt servisinin çalışma prensipleri")

| Tür | Karşı taraf | Referans | Hedef | Fiyat |
|---|---|---|---|---|
| Satış (yurt dışı) | gerekmez (`YurtDisiMi=true`) | referanslı | ülke | var |
| Satış (yurt içi) | kayıtlı **veya** kayıtsız | referanslı | kayıtlıda işyeri, kayıtsızda adres | var |
| Sevk Etme | **kayıtlı ZORUNLU** | referanslı | işyeri | yok |
| Satın Alım | kayıtlı **veya** kayıtsız¹ | referanssız | işyeri (kendi) | var |
| Üreticiden Sevk Alım | **kayıtsız üretici** | referanssız (referanslı YASAK) | **adres (İl/İlçe/Belde)** | var |

¹ **Satın Alım'da kayıtlı zorunluluğu KALDIRILDI (GTB 12.03.2025 sonrası).** Kılavuz
0.1.14 *"Bildirim türü 'Satın Alım' veya 'Sevk Etme' ise İkinci kişi GTB sisteminde
kayıtlı bir kişi olmalıdır"* diyordu; ancak 12.03.2025 duyurusundan sonra HKS, kayıtlı
olmayan kişiyi **TC + doğum tarihiyle KPS'ten (MERNİS) doğruluyor** ve Satın Alım künyesi
üretiyor — HKS sitesinde canlı olarak doğrulandı (sorgu sonrası ad/soyad otomatik geldi,
bildirim künye numarası aldı). Kayıtsız satıcıda `AdSoyad` + `CepTel` + `DogumTarihi`
zorunludur; hedef yine **kendi işyerimizdir** (mal bize gelir), adres dalı kullanılmaz.
**Sevk Etme'nin kayıtlı zorunluluğu DEĞİŞMEDİ.**

Kılavuzun birebir ifadeleri:
- *"Bildirim türü 'Satın Alım' veya 'Sevk Etme' ise İkinci kişi GTB sisteminde kayıtlı
  bir kişi olmalıdır."* → **Sevk Etme için hâlâ geçerli. Satın Alım için ARTIK GEÇERSİZ**
  (yukarıdaki ¹ dipnotu — GTB 12.03.2025 sonrası kayıtsız kişiden Satın Alım yapılabiliyor).
- *"Üreticiden Sevk Alım: Sadece kayıtsız üreticiden yapılan sevkiyat işlemlerinde
  kullanılacak bildirim türüdür."* → kayıtsız müstahsilin TEK geçerli yolu budur.
- *"Bildirim türü 'Üreticiden Sevk Alım'sa, İkinci kişi sıfat bilgisi 'Üretici' olmalıdır."*
  → **tam** ad eşleşmesi; "Üretici Birliği"/"Üretici Örgütü" FARKLI sıfatlardır ve kabul
  edilmez.
- *"İkinci kişi ... GTB sisteminde kayıtlı değilse 'Eposta' bilgisi hariç diğer
  bilgilerinde gönderilmesi gerekir."* → `AdSoyad` + `CepTel` zorunlu olur, `Eposta`
  hiçbir durumda gönderilmez.
- *"İkinci kisi GTB sisteminde kayıtlı kişi değil ise ... GidecekYerIl/Ilce/BeldeId
  '0' olamaz"* → kayıtsız karşı tarafta hedef, işyeri kaydıyla değil **adresle** bildirilir.
  **Üreticiden Sevk Alım'ın ikinci kişisi tanım gereği her zaman kayıtsızdır, dolayısıyla
  bu kural bu türde HER ZAMAN geçerlidir.**

**Üreticiden Sevk Alım'da hedef — kılavuza tam uyum (versiyon geçmişi).** Önceki
sürümlerde bu tür, kılavuzdan bilinçli olarak sapılarak "HKS sitesindeki akış böyle
görünüyor" varsayımıyla kendi firmanın `GidecekIsyeriId`'sini gönderiyordu; bu varsayım
canlıda hiç doğrulanmamıştı. Kılavuz 0.1.14 uyum düzeltmesiyle bu kaldırıldı: ikinci
kişi kayıtlı DEĞİLSE (Üreticiden Sevk Alım'da her zaman) hedef `GidecekYerIlId` /
`GidecekYerIlceId` / `GidecekYerBeldeId` ile bildirilir — Kart 2'deki İl/İlçe/Belde
seçimi zaten yurt içi Satış'ın kayıtsız-alıcı dalında vardı, aynı alanlar bu türde de
kullanılır (`app.html`: `turDegisti()` — `adresMod`; `hks_soap.php`: `hks_bildirim_xml()`
— `$ortak['hedefAdres']` dalı, TÜR-BAĞIMSIZ tek payload üretici).

**Üreticiden Sevk Alım — kuralların nerede uygulandığı.** Bu türün tüm kılavuz
kuralları hem tarayıcıda hem **sunucuda** denetlenir. `app.html` statik bir dosyadır
ve tarayıcı/SW önbelleğinden eski sürümü sunulabilir; CANLI ve geri alınamaz bir
sisteme giden alanların doğruluğu yalnız arayüze bırakılamaz.

| Kural (kılavuz) | Arayüz | Sunucu |
|---|---|---|
| `AdSoyad` + `CepTel` zorunlu (kayıtsız ikinci kişi) | `btnMalTaslak` | `hks_bildirim_dogrula()` |
| İkinci kişi sıfatı **tam olarak** Üretici (ID bazlı) | `ureticiSifati()` — tam eşleşme, kilitli alan | `hks_uretici_sifat_id()` + `hks_bildirim_dogrula()` (katalogdan ID, substring YOK) |
| Referanslı bildirim **YASAK** | künye kartı gizli | `hks_bildirim_dogrula()` (`referanssiz` + künye no "0") — **hem taslak_kaydet HEM taslak_gonder'da** |
| Hedef **adres** (İl/İlçe/Belde), işyeri DEĞİL | `turDegisti()` — `adresMod`, Kart 2 İl/İlçe/Belde | `hks_bildirim_xml()` — `hedefAdres` dalı (TÜR-BAĞIMSIZ) |
| Üretici GTB'de **KAYITSIZ** olmalı | `btnIkDogrula` sonrası engel (tri-state: REGISTERED/NOT_REGISTERED/UNKNOWN) | `taslak_gonder` — gönderim öncesi `hks_kayit_durumu()` |

Sunucudaki tür tespiti `ortak.uretSevk` bayrağı **veya** `ortak.turAd` içinde
"üretici" geçmesiyle yapılır (`hks_uret_sevk_mi()`) — eski taslaklarda yalnız
`turAd` bulunur, onlar da denetimden geçer.

`CepTel` CANLI servise **yalnız rakam** olarak gider: "0532 123 45 67" / "+90 532…"
gibi girişler `hks_bildirim_xml()` içinde temizlenir (tek otorite), arayüz de alandan
çıkışta aynı temizliği gösterir. `Eposta` hiçbir durumda gönderilmez (kılavuz: kayıtsız
ikinci kişide zorunlu alan olmaktan çıkarıldı).

**Kayıt durumu sorgusu — tri-state (REGISTERED / NOT_REGISTERED / UNKNOWN).**
Kılavuz 0.1.14: `KayitliKisiSorguDTO.KayitliKisiMi` yalnız "True"/"False" döner; "False"
ise `Sifatlari` null gelir. **Önceki sürümde** sorgu sonucu boş/eksik geldiğinde bu
"kayıtsız" (`kayitliMi=false`) sayılıyordu — bu YANLIŞTI ve kılavuz uyum düzeltmesiyle
kaldırıldı. `hks_kayit_durumu()` artık üç kesin durum döner:
- **REGISTERED** — `KayitliKisiMi=true`.
- **NOT_REGISTERED** — `KayitliKisiMi=false`.
- **UNKNOWN** — SOAP/ağ hatası, `IslemKodu` başarısız, sorgulanan TC'ye eşleşen DTO yok,
  veya `KayitliKisiMi` boolean'a çevrilemiyor. **Bu durumda `BildirimServisBildirimKaydet`
  ÇAĞRILMAZ** — ne "Doğrula" adımında ilerlemeye izin verilir, ne de gönderim yapılır.

**Gönderim öncesi bütünlük + AYNA denetimi.** `taslak_gonder`, geri alınamaz bildirimden
hemen önce iki şeyi TEKRAR doğrular (P1/P2 uyum düzeltmesi — eskiden yalnız AYNA
denetimi vardı, taslak kaydından sonra kural değişmiş/bozulmuş bir taslak doğrudan
`hks_bildirim_kaydet()`e gidebiliyordu):
1. **Kural bütünlüğü** — `hks_bildirim_dogrula()` taslak üzerinde tekrar çalıştırılır
   (referans künye, zorunlu alanlar, vb. — `taslak_kaydet` ile AYNI fonksiyon).
2. **AYNA (kayıt durumu)** — karşı tarafın GTB kayıt durumu `hks_kayit_durumu()` ile
   TEKRAR sorulur; taslak kaydedildikten sonra değişmiş olabilir. Tek sorgu sonucu hem
   gate kararı hem (varsa) teşhis kaydı için kullanılır (aynı istekte tek doğrulama
   kaynağı). Üç yön kapalıdır: Sevk Etme/Satın Alım'da karşı taraf kayıtlı **değilse**,
   Üreticiden Sevk Alım'da üretici kayıtlı **ise**, veya durum **UNKNOWN** ise — gönderim
   durur ve taslak silinmez.

**Doğum tarihi — GTB 12.03.2025 duyurusu (kılavuz 0.1.14'ü GÜNCELLER).**
Kılavuz 0.1.14 (2016) `IkinciKisiBilgileriDTO` içinde doğum tarihi alanı içermiyordu ve
bu README daha önce "gönderilmez" diyordu — **bu bilgi artık geçersiz.** GTB'nin
12.03.2025 tarihli duyurusu: *"Kimlik Paylaşım Sistemi (KPS) sorgulamalarında ... T.C.
kimlik numarası ile birlikte Doğum Tarihi bilgisi zorunlu hale getirilmiştir. ...
Sistemde kayıtlı olmayan kişi bildirimleri için T.C. kimlik numarası ve doğum tarihi
bilgilerinin girilmesi gerekmektedir."*

- `DogumTarihi`, `IkinciKisiBilgileriDTO` içinde **alfabetik sırada** gönderilir:
  `AdSoyad` → `CepTel` → **`DogumTarihi`** → `Eposta` → `KisiSifat` → `TcKimlikVergiNo`
  → `YurtDisiMi`. (DataContract sıralaması; sıra bozulursa alan sessizce yoksayılabilir.)
- **Yalnız dolu olduğunda gönderilir** (`hks_dogum_tarihi_xml()` boş dize dönerse alan
  hiç eklenmez) — böylece kayıtlı ikinci kişili ve yurt dışı akışlar birebir eskisi gibi
  kalır, mevcut çalışan davranış hiç etkilenmez.
- **Biçim:** ISO 8601 (`1980-01-01T00:00:00`), bu dosyadaki diğer tarih alanlarıyla
  (`BaslangicTarihi`/`BitisTarihi` — canlıda çalışıyor) aynı. GTB'nin duyuru ekindeki
  `Ornek_Request.txt` tarihi `01.01.1980 00:00:00` biçiminde yazmış (elle hazırlanmış
  SoapUI örneği); ilk canlı denemede ISO reddedilirse `hks_dogum_tarihi_xml()` içinde
  `d.m.Y H:i:s` denenmelidir.
- Arayüzde `#oIkDogum` (`<input type="date">`), kayıtsız karşı tarafta Ad/Ünvan ve Cep
  ile birlikte görünür ve zorunludur; backend `hks_bildirim_dogrula()` aynı kuralı
  bağımsız uygular.

**`Eposta` hakkında düzeltme.** Bu README daha önce "Eposta hiçbir durumda gönderilmez"
diyordu; doğrusu **zorunlu değildir** (kılavuz 0.1.8 notu bunu zorunluluktan çıkardı) ama
**yasak da değildir** — GTB'nin resmi örnek request'i `Eposta` gönderiyor. Panel bu alanı
kullanıcıdan hiç toplamadığı için pratikte gönderilmez; forma eklenirse serbestçe
gönderilebilir.

**Endpoint — GTB 12.03.2025 duyurusu.** Yeni adresler yayımlandı ve *"27 Mart 2025
tarihine kadar mevcut endpointler ve yeni endpointler birlikte kullanılabilecektir"*
denildi:

| Servis | Yeni adres |
|---|---|
| Bildirim | `https://ws.gtb.gov.tr:8443/HKSBildirimService` |
| Genel | `https://ws.gtb.gov.tr:8443/HKSGenelService` |
| Ürün | `https://ws.gtb.gov.tr:8443/HKSUrunService` |

Adres artık `config.php`'den seçilir: **`HKS_YENI_ENDPOINT`** (varsayılan `false` — mevcut
çalışan davranış korunur). `DogumTarihi` alanının yalnız yeni şemada bulunması muhtemel
olduğundan geçiş büyük olasılıkla gereklidir; ancak **önce doğrulayın**:

```
php scripts/hks_endpoint_test.php <firmaId>
```

Bu betik eski ve yeni endpoint'i aynı **salt-okunur** çağrıyla (Ülkeler listesi)
karşılaştırır — HKS'te kayıt oluşturmaz, rüsum doğurmaz. Yeni endpoint `ÇALIŞIYOR`
dönerse `HKS_YENI_ENDPOINT`'i `true` yapın; sorun çıkarsa `false`'a geri alın.

**e-Fatura senaryosu "HAL" — GTB 04.03.2026 duyurusu (kod dışı, ama kritik).**
Komisyoncu/tüccarların sebze-meyve satış ve sevklerinde düzenledikleri e-Fatura,
e-Arşiv Fatura ve e-İrsaliye belgelerinde **senaryonun "HAL" olması zorunludur**; aksi
tespit edilirse *"bildirimcinin ve/veya web servis yazılımının Hal Kayıt Sistemi web
servis yetkilendirilmesi iptal edilebilecektir"*. Son tarih **01.04.2026**. Bu, panelin
gönderdiği `BelgeNo`/`BelgeTipi` alanlarıyla ilgili **değildir** — belgeyi düzenleyen
e-fatura entegratörü tarafında ayarlanır. Kod tarafında yapılabilecek bir şey yoktur,
ancak yetkilendirme iptali doğrudan bu paneli de durduracağı için burada not edilmiştir.

**TC/VKN eşleşmesi.** Kayıt durumu sorgusunda dönen `TcKimlikVergiNo`, sorgulanan
TC/VKN ile `hks_tc_esit()` üzerinden karşılaştırılır — yalnızca format normalizasyonu
(rakam olmayan karakterler atılır), fuzzy eşleşme YAPILMAZ. Eşleşen satır yoksa durum
UNKNOWN'dır (bkz. yukarısı).

**`gonderilenler.bildirim_turu` (P3).** Gönderilen bildirimlerin türü artık ayrı bir
sütunda yapısal olarak tutulur (`URETICIDEN_SEVK_ALIM` / `SATIN_ALIM` / `SEVK_ETME` /
`SATIS`) — önceden yalnız `ulke_ad` alanına gömülü bir metin öneki ile ayırt
edilebiliyordu. `hks_bildirim_turu_kodu()` (api.php) yeni gönderimlerde bu alanı
doldurur; **legacy kayıtlar `NULL` kalır** (güvenilir biçimde belirlenemediği için
tahmine dayalı backfill yapılmamıştır).

---

## 6. İlk test önerisi

0. Otomatik testler (ağsız/DB'siz, saf fonksiyonlar): `php scripts/hks_kunye_detay_test.php`
   ve `php scripts/hks_uretici_sevk_test.php`. İkincisi "Üreticiden Sevk Alım" kılavuz
   0.1.14 uyum düzeltmesinin (kayıt durumu tri-state, sıfat tam eşleşmesi, gidecek yer
   payload'ı, referans künye kuralı) regresyon testleridir.
1. Firma ekle (HKS kullanıcı adı, şifre, web servis şifresi, vergi no).
2. "🔄 Listeler" ile referans listelerini indir (bu adım kimlik bilgilerini de doğrular).
3. Ürün seç → künyeleri getir (bu adımlar HKS'te KAYIT OLUŞTURMAZ, sadece okur).
4. İlk gerçek bildirimi **tek künye, küçük miktarla** yapıp HKS sitesinden doğrula.
5. **"Üreticiden Sevk Alım" ilk canlı testi özellikle önemli:** bu tür artık kayıtsız
   üretici için İl/İlçe/Belde adresiyle bildiriliyor (P1 düzeltmesi — eskiden kendi
   firmanın işyeri kaydı gönderiliyordu, bu hiç canlıda doğrulanmamıştı). HKS servisi
   bu alanları REDDEDERSE (`GTBGLB...` hata kodu), `hks_soap.php`'deki
   `hks_bildirim_xml()` içinde `hedefAdres` dalını incele; kılavuz metni bu dalın doğru
   olduğunu söylüyor, ama canlı doğrulama YAPILMADAN kesin değildir.
