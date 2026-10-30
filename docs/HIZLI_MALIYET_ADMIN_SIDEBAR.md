# Hızlı Maliyet — admin erişimi ve sol menü (v457)

30 Ekim kullanıcı talimatı: **merge et, sol sidebar'a Hızlı Maliyet ekle;
şimdilik yalnız admin görsün**. Bu talimat push/PR/merge ve mevcut webhook
yayınını kapsar. Yeni şema işlemi veya maliyet kaydı değişikliği gerekmez.

- Sol Operasyon grubunda, Raporlar'ın altında ayrı **Hızlı Maliyet** bağlantısı.
- Yalnız `is_admin()` için menü ve eski Maliyet ekranındaki yeni sayfa düğmesi.
- Controller, normal oturum kapısından sonra admin olmayan GET/POST'u Hızlı
  Maliyet kayıt sorgusu veya tablo hazırlık kontrolünden önce reddeder.
- Store doğrudan liste/aç/kaydet/güncelle erişimini admin kontrolüyle kapatır;
  admin yoksa aktif depo yardımcısına veya PDO'ya ulaşmaz.
- `maliyet.admin`/`maliyet.read`/`maliyet.write` gibi izinler admin rolünün
  yerine geçmez. Eski detaylı maliyet ekranının yetkileri değişmez.
- Masaüstünde tek aktif bağlantı Hızlı Maliyet'tir. Mobilde mevcut Raporlar
  ailesi korunur; yeni mobil menü öğesi veya değişen kullanım sırası yoktur.
- APP_SURUM ve SW cache birlikte v457.

Doğrulama: güncellenmiş hesap/kayıt testleri32, controller testleri41,
kurulum korumaları11 ve gerçek sidebar render kontrolü19 geçti.
İlgisiz hesaplama/mobil tam suite kabulü gerekçesiz tekrar edilmedi.
Testler anonim SQLite ve sürücü doubles kullanır; gerçek DB/bootstrap yoktur.
Bağımsız son kontrolde56assertion geçti: doğrudan route/CRUD/kurulum403,
admin sağlayıcısı yokken kapalı erişim, depo/PDO öncesi ret, tek aktif link
ve eski maliyet/mobil gezinme korundu. Kanıt:
`C:/Users/AsyaFresh/.codex/local-data/quick-cost-admin-sidebar-independent-20261030.json`.
Canlı sidebar doğrulaması yayın sonrası ayrıca kaydedilir.

Önceki PR830 zaten main'e birleştirilmişti. Bu menü/yetki değişikliği güncel
`bd4136d` temelinden ayrı `codex/hizli-maliyet-sidebar` dalında hazırlanır.
Genel CI'daki önceki Sipariş beklenti hataları ayrı konudur ve tam CI yeşil
olarak sunulmaz; ayrıntısı HIZLI_MALIYET_CANLI_HAZIRLIK.md'dedir.
