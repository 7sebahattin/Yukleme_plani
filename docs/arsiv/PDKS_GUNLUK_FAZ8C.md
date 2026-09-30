# Günlük İşçi Faz 8C — Rapor / Yazdırma / Operasyon Tamamlama

## Neden bu faz var?

Faz 8A nötr kart + mesai dönemi modelini, Faz 8B ise Tam/Yarım/Fazla Mesai değerlendirmesi ve finansal hesap motorunu tamamladı. Faz 8C yeni bir ücret kuralı veya yeni bir tarama motoru eklemez; Faz 8B ile oluşan yeni finansal gerçekliğin mevcut Faz 5-7 çıktılarına ve navigasyonuna eksiksiz yansımasını tamamlar.

Bu belge, daha önce repoda ayrı ve kesin bir "Faz 8C" spesifikasyonu bulunmadığı için kapsamı burada sabitler.

## Kapsam

### 1. Hakediş yazdırma çıktısı

`cavus_hakedis_yazdir.php`, Faz 8B finansal snapshot alanlarını şema uygunsa gösterir:

- Tam Mesai / Yarım Mesai
- onaylı fazla mesai saati
- fazla mesai tipi: Saatlik / Sabit
- saatlik FM'de birim ücret
- FM toplamı
- temel ücret
- satır toplamı

Faz 8B öncesi eski snapshot'lar aynı sayfadan eski dört kolonlu görünümle yazdırılmaya devam eder.

Taslak hakedişte `needs_recalculation=1` ise yazdırma çıktısı bunun güncel olmadığını açıkça belirtir.

### 2. Personel Takip Merkezi

Faz 7'deki landing page'in kart açıklamaları yeni akışa göre güncellenir:

- Çavuş Fiyatları → Tam / Yarım / Fazla Mesai ücretleri
- Hakedişler → Mesai değerlendirme + taslak / kesin hakediş

Yetkiler değişmez; yeni bir geniş rol veya izin tanımlanmaz.

### 3. Geriye uyumluluk

- Faz 8B şeması olmayan ortamda eski hakediş yazdırma görünümü korunur.
- Kesinleşmiş eski Faz 4-7 hakedişleri yeniden hesaplanmaz, geçmiş snapshot sessizce değiştirilmez.
- Cari, ödeme ve yönetim raporları toplam tutarı `foreman_daily_entitlements` üzerinden okumaya devam eder; Faz 8B toplamları zaten bu tabloya yazıldığı için ikinci bir finansal defter oluşturulmaz.

## Bilinçli kapsam dışı

### Offline IndexedDB / service-worker senkronizasyonu

Eski tasarım belgelerinde "Faz 8B/8C kapsamı" altında offline IndexedDB kuyruğu ve senkronizasyon çakışma arayüzü adı geçiyordu. Ancak daha sonra alınan mimari kararla ayrı Android istemcisi ve Android-özel offline kuyruk iptal edildi; mevcut PDKS V1 tek web uygulaması ve online-only çalışır. Bu nedenle Faz 8C, offline tarama veya service-worker üzerinden POST kuyruğu EKLEMEZ.

Bağlantı yokken sistem bir taramayı "kaydedildi" diye göstermemelidir. Offline tarama ileride ayrıca istenirse kendi güvenlik/idempotency tasarımıyla ayrı bir proje olarak ele alınmalıdır.

### FX ve yeni cari motoru

Faz 5 zaten para birimi bazlı cari/ödeme altyapısını içerir ve farklı para birimlerini birbirine çevirmeden ayrı tutar. Faz 8C FX dönüşümü veya ikinci bir cari defteri eklemez.

## Migrasyon

Yok. Faz 8C yalnız sunum/navigasyon entegrasyonudur; yeni tablo veya kolon oluşturmaz.

## Test hedefleri

- Faz 8B satırı yazdırmada Tam/Yarım sınıfını gösterir.
- Saatlik FM satırı saat, birim ücret ve toplam FM tutarını gösterir.
- Sabit FM satırı "Sabit" olarak görünür.
- `needs_recalculation=1` taslağında uyarı görünür.
- Faz 8B öncesi eski hakediş yazdırma testi bozulmaz.
- Yazdırma sayfası salt okunur kalır; INSERT/UPDATE/DELETE içermez.
- Personel Takip Merkezi mevcut izin matrisini değiştirmez.
