# Hızlı Maliyet — 30 Ekim canlı geçiş hazırlığı

Kullanıcı PR830 için canlıya alıp test etmek istediğini belirtti. Ayrıca yalnız
yeni `quick_cost_sheets` tablosunun kurulmasını açıkça onayladı. Mevcut maliyet
kayıtlarını değiştirme yetkisi verilmedi. Atlas aktarım onayı bundan ayrıdır.

İlk teslim `58fef74` ve `HIZLI_MALIYET_TESLIM.md` tarihsel yerel kabul kaydıdır.
Bu ek dilimde yalnız yöneticiye açık, POST/CSRF korumalı kurulum düğmesi eklendi.
`quick_cost_install(PDO)` kullanıcı, admin, maliyet okuma/yazma ve aktif depo
kontrolünden sonra sabit hash doğrulanmış SQL'den tek yeni tablo CREATE'i
çalıştırır. Hazır tabloda DDL/audit yapmaz; GET/include DDL çalıştırmaz;
çağıranın açık işlemi reddedilir. Mevcut tabloya ALTER veya kayıt DML'i yoktur.

Yeni kurulum katmanı 11/11 PDO spy testinden, güncellenmiş gerçek sayfa akışı
35/35 anonim SQLite kontrolünden geçti. Eski hesaplama/mobil kabuller korunur;
bu ek kurulum testi gerçek MySQL kabulü sayılmaz. Bağımsız kurulum incelemesinde
29 kontrol geçti: yetki/depo/işlem korumaları, LF/CRLF sabit SQL, ek DROP,
başka tablo ve değiştirilmiş şemanın DDL öncesi reddi doğrulandı. Repo dışı kanıt:
`C:/Users/AsyaFresh/.codex/local-data/quick-cost-schema-independent-833c4e205f/proof.json`.

## GitHub CI'daki mevcut Sipariş hataları

PR830 ilk CI koşusu 41038264432 başarısızdır. Yeni özellik testleri yerelde
geçmiştir; CI başarısızlığı gizlenmez veya tüm suite yeşil olarak sunulmaz.

- `siparis_cari_smoke.php:251`, bütün `<dialog>` öğelerini yasaklar. v454
  genel ekran kilidi penceresi bu eski beklentiye takılır; ödeme pencereleri
  hâlâ kendi yetkisiyle korunur. Temel `0cf5aad` ayrı worktree'sinde aynı
  beklenen test hatası tekrar gözlendi. Windows'ta doğrudan dosya yolu kontrolü
  de ayrıca başarısız oldu; bu bir Linux CI sonucu olarak sunulmaz.
- `siparis_ui_smoke.js:131`, beş ürünlü fixture'da `+N daha` bekler. Önceki
  değişiklik gösterim sınırını 3'ten 5'e çıkarmıştır; beş üründe ek gösterge
  olmaması doğrudur. İlgili kaynak/test dosyaları temel ve PR başında aynıdır.

İlgisiz Sipariş davranışı ve testleri bu PR'da değiştirilmedi. Dağıtım öncesi
son main/sürüm kontrolü ve canlı dağıtım sonrası erişim doğrulaması gerekir.
Tablo kurulumu ve canlı hesap kaydetme, yalnız başarı fiilen gözlendiğinde
tamamlandı olarak raporlanır. Kullanıcı yönetici oturumuna ihtiyaç vardır;
kimlik bilgisi dosyaları veya uygulama bootstrap'ı okunmaz.
