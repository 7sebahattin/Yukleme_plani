# Hızlı Maliyet — tek ekran ve kart başına Ayar (v459)

30 Ekim kullanıcı isteği: Hızlı/Detaylı yöntem ayrımı kaldırılır; her maliyet
kartının altındaki **Ayar** açılır bölümü birim/miktar/fiyat düzenlemelerini
taşır. Miktar alanı ve üst özet etiketi **Ürün KG** olur.

Varsayılan görünüm sade fiyat girişidir. Bağımsız miktarlı bir kartta ana fiyat
salt okunur eşdeğer TL/kg; Ayar'da özgün birim/miktar/fiyat görünür. Aynı satır
bir kez toplanır. Her Ayar bağımsız açılır/kapanır ve veri değiştirmez; diğer
kartların açık durumları korunur. Ürün KG bağlı, manuel ve sabit tutar kuralları
aynıdır. Sabit miktar1; Kg satırında yeniden bağlama **Ürün KG kullan** ile yapılır.

Eski `method=quick/detailed` değerleri şema/kayıt uyumluluğu için korunur;
yeni yöntem değeri veya migration yoktur, eski kayıtlar yeniden yazılmaz.
Kayıt listesi artık yöntem etiketi göstermez. Üretim/Fason ve admin kısıtı aynı.
Kanonik kalem modeli ve matematik değişmez; backend değişiklikleri görünen KG
etiketleri/açıklamalarıdır. Mevcut kayıt üzerinde canlı test yazımı yapılmaz.

Dal `codex/hizli-maliyet-row-settings`, temel `bbdd64c`/v458; APP_SURUM/SW v459.
235 tarayıcı kontrolü (320/390/412/1440, açık/koyu tema),16 saf JS kontrolü,
41 controller ve32 anonim SQLite kayıt/motor kontrolü geçti. Bağımsız başka
bir Codex inceleyici gerçek anonim controller fixture'larıyla27 odaklı kontrol
ve salt okunur mobil panel PNG incelemesini tamamladı; engelleyici bulgu yok.
Ana/Ayar fiyat eşlemesi, caret, bağımsız açılır durumlar, manuel26palet×380,
kapalı geçersiz fiyat/miktar, eski detailed metadatası ve readonly doğrulandı.

Kanıtlar (repo dışı, anonim):

- `C:/Users/AsyaFresh/AppData/Local/Temp/quick-cost-qa-xHRNbS/proof.json` + PNG'ler.
- `C:/Users/AsyaFresh/AppData/Local/Temp/quick-cost-independent-row-derSaZ/proof.json`.

PHP/JS sözdizimi ve değişiklik kontrolleri geçti. Fiziksel telefon ve canlıda
kayıt yazımı bu testlere dahil değildir. Canlı kabul yayın sonrasında PR ve
devir checkpoint'inde ayrı kaydedilir; eski kabul sonuçları yeni sonuç sayılmaz.
