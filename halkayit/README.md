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
| `config.php` | DB bilgileri, şifreleme anahtarı, giriş ayarı. **Doldurulacak tek dosya.** |
| `schema.sql` | Tablo şeması (bilgi amaçlı; tablolar zaten otomatik kurulur). |

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
- **"1 ay" sınırı takvim ayıdır** (30 gün değil). Bu yüzden geriye dönük sorgu
  takvim ayı pencerelerine bölünür.
- **`UrunId` fiilen zorunludur** (şemada opsiyonel görünse de). Arayüz ürün seçtirir.
- **`KisiSifat` filtresi bilerek gönderilmez** — gönderilince bazı künyeler (farklı
  sıfatla bildirilmiş olanlar) listeden düşüyor.
- **İhracat + Satış + YurtDisiMi=true** olduğunda ikinci kişi bilgisi gerekmez;
  referanslı satışta sadece `MalinMiktari` + `MalinSatisFiyat` yeterli.
- **Bu CANLI sistemdir.** Her gönderim gerçek bildirimdir, geri alınamaz, rüsum
  doğurur. Bu yüzden arayüzde "önce taslağa kaydet → sonra onayla → gönder"
  akışı var. Bu iki aşamalı güvenlik akışını kaldırmayın.

### 5.1 Bildirim türleri ve karşı taraf kuralı (kılavuz 0.1.14)

| Tür | Karşı taraf | Referans | Hedef | Fiyat |
|---|---|---|---|---|
| Satış (yurt dışı) | gerekmez (`YurtDisiMi=true`) | referanslı | ülke | var |
| Satış (yurt içi) | kayıtlı **veya** kayıtsız | referanslı | kayıtlıda işyeri, kayıtsızda adres | var |
| Sevk Etme | **kayıtlı ZORUNLU** | referanslı | işyeri | yok |
| Satın Alım | **kayıtlı ZORUNLU** | referanssız | işyeri (kendi) | var |
| Üreticiden Sevk Alım | **kayıtsız üretici** | referanssız (referanslı YASAK) | adres | var |

Kılavuzun birebir ifadeleri:
- *"Bildirim türü 'Satın Alım' veya 'Sevk Etme' ise İkinci kişi GTB sisteminde kayıtlı
  bir kişi olmalıdır."* → kayıtsız müstahsilden **Satın Alım yapılamaz**, servis reddeder.
- *"Üreticiden Sevk Alım: Sadece kayıtsız üreticiden yapılan sevkiyat işlemlerinde
  kullanılacak bildirim türüdür."* → kayıtsız müstahsilin TEK geçerli yolu budur.
- *"Bildirim türü 'Üreticiden Sevk Alım'sa, İkinci kişi sıfat bilgisi 'Üretici' olmalıdır."*
- *"İkinci kişi ... GTB sisteminde kayıtlı değilse 'Eposta' bilgisi hariç diğer
  bilgilerinde gönderilmesi gerekir."* → `AdSoyad` + `CepTel` zorunlu olur.
- *"İkinci kisi GTB sisteminde kayıtlı kişi değil ise ... GidecekYerIl/Ilce/BeldeId
  '0' olamaz"* → kayıtsız karşı tarafta hedef, işyeri kaydıyla değil **adresle** bildirilir.

**Üreticiden Sevk Alım'da adres ne demek?** Mal üreticiden size gelir; buradaki
il/ilçe/belde **malın geldiği yeri** (kayıtsız üreticinin bulunduğu yeri) belirtir,
kendi tesisinizi değil. Arayüzdeki açıklama da bunu söyler.

**Doğum tarihi:** kılavuz 0.1.14'te `IkinciKisiBilgileriDTO` yalnızca `KisiSifat`,
`TcKimlikVergiNo`, `AdSoyad`, `Eposta`, `CepTel`, `YurtDisiMi` içerir — doğum tarihi
alanı **yoktur**. HKS web portalı kayıtsız üretici tanımlarken doğum tarihi isteyebilir;
servis şemasında karşılığı bulunmadığı için gönderilmez. Canlı şema değişmişse
`BildirimService.svc?xsd=xsd2` çıktısıyla doğrulayıp buraya not düşün.

---

## 6. İlk test önerisi

1. Firma ekle (HKS kullanıcı adı, şifre, web servis şifresi, vergi no).
2. "🔄 Listeler" ile referans listelerini indir (bu adım kimlik bilgilerini de doğrular).
3. Ürün seç → künyeleri getir (bu adımlar HKS'te KAYIT OLUŞTURMAZ, sadece okur).
4. İlk gerçek bildirimi **tek künye, küçük miktarla** yapıp HKS sitesinden doğrula.
