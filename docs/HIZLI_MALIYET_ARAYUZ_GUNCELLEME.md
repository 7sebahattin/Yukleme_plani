# Hızlı Maliyet arayüz düzeltmesi — v458

30 Ekim kullanıcı görselindeki dört isteğin kapsamı:

1. Üstteki **Maliyet menüsü** bağlantısı kaldırılır; Yeni hesap ve Kayıtlar kalır.
2. İşlem düğmeleri **Üretim / Fason** sırasındadır. Mevcut/default tür değiştirilmez.
3. Hızlı yöntem yalnız fiyat; Detaylı yöntem birim, miktar ve fiyat girişlerini
   açar. Önceki uygulamada bu fark temel bilgilerin altında kaldığından ilk
   ekranda görünmüyordu. Düğme alt açıklamaları ve aktif yöntem açıklaması
   eklendi; açık yöntem tıklaması kalem başlığına odaklanır/kaydırır. Başlangıçta
   veya işlem türü değişince otomatik kaydırma yoktur. Telefon klavyesi açılmaz.
4. Ürün alanındaki **Örn. Kayısı** placeholder'ı kaldırılır. Ürün değeri silinmez.

Hesap motoru, kayıt modeli, admin kısıtı, depo kapsamı ve eski maliyet ekranı
değişmez. Veri/şema işlemi gerekmez. APP_SURUM ve SW birlikte v458.

Dal: `codex/hizli-maliyet-form-clarity`, temel `3e09f77` (güncel main/v457).
159 tarayıcı kontrolü (320/390/412/1440, açık/koyu tema),41 controller kontrolü
ve50 bağımsız odaklı UI kontrolü geçti. Düğme tıklaması ilk kalemi kaydet/alt
menü üzerinde görünür yapar; başlangıçta/tür değişiminde kaydırma yoktur.
Kayıtlı Üretim+detaylı palet26×380 ve geçersiz fiyat taslağının yöntem
geçişlerinde korunması ayrıca bağımsız doğrulandı. Admin kısıtı/hesap motoru/
şema ve eski maliyet dosyalarının değişmediği git diff ile doğrulandı.
PHP/JS sözdizimi ve değişiklik boşluk kontrolleri geçti.

Anonim yerel kanıtlar:

- `C:/Users/AsyaFresh/AppData/Local/Temp/quick-cost-qa-FFkwGS/proof.json` + PNG'ler.
- `C:/Users/AsyaFresh/.codex/local-data/quick-cost-ui-independent-iffnom/proof.json`.

Fiziksel Safari/Android cihaz kabulü yapılmış sayılmaz. Canlı kabul yayın sonrası
devir/checkpoint ve PR açıklamasında kaydedilir. Önceki test kabulleri yeni
sonuçmuş gibi sunulmaz; genel CI'nın eski Sipariş beklenti hataları ayrı konudur.
