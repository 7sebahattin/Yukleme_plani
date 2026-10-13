# Çavuşun tarihli normal çalışma süresi — v351

Çavuş düzenleme ekranındaki **Tarihli normal çalışma süresi** bölümünden saat,
başlangıç tarihi, tarih aralığı veya süresiz kapsam ve gerekçe kaydedilir.
Başlangıç ve bitiş günleri dahildir. Örneğin 1–15 Ocak için 10 saat, 16 Ocak'tan
itibaren süresiz 9 saat tanımlanabilir. Hatalı ilk tanım, ilk mesainin tarihinden
başlayan bir kayıtla düzeltilebilir. Çakışan günlerde en son kaydedilen tanım
geçerlidir; sınırlı aralığın dışında önceki tanımlar korunur.

Profil kaydetmek süre tanımlarını değiştirmez. Yeni çavuşun ilk normal süresi
oluşturma sırasında girilebilir. Sonraki tarihli kayıtlar aynı ekranda kullanıcı,
kaydedilme zamanı, tarih aralığı, süre ve gerekçeyle listelenir.

## Hesaplamaya etkisi

- Tanım tüm depolarda, çavuşa bağlı bütün işçi türlerine uygulanır.
- Seçilen günlerde mevcut mesailerin `normal_work_minutes_snapshot` değeri
  güncellenir; gelecekte ve geçmişe dönük yeni açılan mesailer iş tarihindeki
  tanımdan süre alır. Tanımsız eski günler kendi snapshot değerlerini korur.
- Tarihli tanım seçili günlerde fiyat döneminin `full_day_minutes` ve
  `overtime_start_minutes` eşiklerinden önceliklidir. Ortak sınıflandırıcı
  sorgu kopyasında bu iki fiyat eşiğini kaldırır. Fiyat kaydı, parasal ücretler,
  yarım/çift yevmiye ayarları değiştirilmez; 15 dakika FM toleransı korunur.
- Etkilenen mesailerin önceki muhasebe sınıf/FM onayları kaldırılır. Yeni eşik
  üzerinden onaylar tekrar verilmelidir. Taslak hakedişler
  `needs_recalculation=1` olarak işaretlenir; hesaplama tamamlanana kadar eski
  parasal toplam güncel kabul edilmez.
- Seçili aralıkta kesinleşmiş hakediş varsa tüm kayıt reddedilir. Önce mevcut
  kontrollü yeniden açma akışı kullanılmalıdır; ödeme geçmişinin yeniden açmayı
  engellediği durumlarda bu koruma geçerlidir.

## Saklama ve tutarlılık

Yeni tablo veya migration yoktur. Mevcut `audit_log`, `module=foremen`,
`action=normal_sure_donemi` kayıtları eklemeli olay günlüğüdür. Bu kayıtlar
işlevsel veridir ve audit temizliği/arşivleme yapılırsa çözümleyicinin erişimi
korunmalıdır. İlk kaydın önceki normal süresi `baseline_minutes` olarak korunur;
sınırlı/gelecek tanım diğer tarihlere taşmaz. Tanımlar güncellenmez/silinmez;
düzeltme yeni bir olaydır, en yüksek `id` önceliklidir.

Olay kaydı, mesai snapshot değişiklikleri, onayların kaldırılması ve taslak
işaretleri aynı transaction içindedir. Audit yazımı başarısızsa hepsi geri alınır.
MySQL'de çavuş satırı kilitlenerek eşzamanlı süre değişiklikleri sıralanır.
Hakediş hesabı yazılırken süre günlüğünün revizyonu tekrar kontrol edilir;
kesinleştirme yeniden hesaplama işaretini geçersiz kılamaz.

## Doğrulama

```text
php scripts/pdks_cavus_sure_smoke.php
node scripts/pdks_cavus_sure_e2e.js
```

Tarayıcı testi `PW_PATH` ile Playwright, gerekirse `PW_CHROMIUM` ile tarayıcı
dosyasını alır. Gerçek sayfa ve hesaplama fonksiyonlarını geçici SQLite üzerinde
çalıştırır; üretim DB/auth yapılandırmasını yüklemez. Test sunucusu, CSRF ve yetki
kapılarını denetlenebilir yerel test yardımcılarıyla sağlar.
