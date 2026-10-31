# Roller: sayfa ve alt başlık yetki matrisi

31 Ekim 2026. Ayrı çalışma kopyası: `Yukleme_plani_roles`, dal
`codex/roles-permission-matrix`, başlangıç `origin/main` = `2ad0405`.
Yerel uygulama ve doğrulama teslimidir; push/merge/yayın yapılmadı.

## Sonuç

Örnek görsel temel alınarak sayfa satırları ve Gör / Ekle / Düzenle / Sil
anahtarları oluşturuldu. Sidebar sırası, açılır alt başlıklar, özel işlemler,
Türkçe arama, bölüm filtresi ve seçili/seçili olmayan görünümü bulunur.
Kaydet/İptal düğmeleri sabittir. Klavye, odak döngüsü, Escape ve dar ekran
düzeni anonim tarayıcıda kontrol edilir.

Yükleme/Paletleme, OGL/Siparişler ve Hal Bildirimi erişimleri ayrıldı.
Kayıt, kantar, hesap, maliyet, tanım ve sipariş oluşturma/düzenleme kapıları
sunucuda da ayrıştırıldı. Sekiz rapor türünün görüntüleme ve dışa aktarma
kapıları ayrı; dışa aktarma ilgili ana/alt görüntüleme izinlerini de ister.
Malzeme şablonu yönetimi ayrı özel işlemdir. Mevcut kayıtlı katalog dışı
izinler rol düzenlenirken sessizce silinmez.

Eski roller `role.permissions.detailed` olmadan rol başına genişletilir,
sonra kullanıcı rollerinin izinleri birleştirilir. Yeni forma geçişte mevcut
etkili izinler anahtarlarda seçilir. Mevcut yönetici istisnaları korunur.
Şema veya seed değişikliği/migration yoktur.

## Veri kapsamı ve sınırlar

Depo kapsamı mevcut `role_permissions` içinde doğrulanmış seçimlerle tutulur.
Depo anahtarı ve ad parçaları en fazla 60 karakterdir; uzun Türkçe adlar ve
sayısal hash öneki sınanır. Açık depo sınırları roller arasında ve kullanıcı
depo atamalarıyla **kesişir**; eski/sınırsız bir rol sınırı kaldırmaz. Aktif
depo daha da daraltır. Boş kesişim kapalıdır. Seçili kapsamda boş depolu,
paletsiz ve izinli/izinsiz depoları birlikte içeren yükleme kaydı açılmaz.
Liste, tekil URL, ilgili API, rapor, dışa aktarma ve hedef depo yazımları
ortak kontrolleri kullanır. İçe aktarma tüm depoları ilk INSERT öncesi denetler.

Bu kapsam depo ile filtrelenen yükleme, paletleme, mal kabul, stok, maliyet,
raporlar ve mevcut depo filtreli personel bölümleri içindir. Beyanların şirket
genelinde görünmesi, Mail hesap atamaları ve Hesap kendi/tüm personel kuralları
korunur. Şube adında yeni veri modeli uydurulmadı. Eski günlük snapshot
arşivinin kapsamı güvenilir biçimde belirlenemediğinden seçili depo rollerine
kapalıdır; kapsamlı güncel günlük rapor kullanılabilir. Bu sınırlar ekranda
açıklanır.

Hazırlanmakta olan 51 muhasebe hedefi ve mevcut yöneticiye özel sayfalar
listelenir; desteklenmeyen CRUD hücreleri “—” gösterir. Personel, kart,
çavuş, tarama, fiyat, hakediş ve ödeme alt başlıkları mevcut gerçek işlemlere
göre ayrılır. Günlük giriş/çıkış, kart tanımı ve hakediş upsert yollarında
gerçek oluşturma/düzenleme dalı kontrol edilir. Yöneticiye özel eski
işlemler devredilmez. Ayrıştırılmayan kullanıcı/rol yönetimi ve bildirim
paketleri açıkça tek özel işlem olarak gösterilir.

## Araştırma ve ekip

Analiz/backend: `permission_analysis`; araştırma/UI: `ux_research`;
bağımsız kaynak incelemesi: uygulamayan `independent_review` (Codex).
Ana ajan entegrasyonu ve fonksiyonel testleri sıralı yürütür.
Araştırma/plan: [UI planı](ROLES_MATRIX_PLAN.md),
[backend planı](ROLES_MATRIX_BACKEND_PLAN.md),
[personel planı](ROLES_MATRIX_PDKS_PLAN.md).
Referanslar: [Directus](https://directus.com/docs/guides/auth/access-control),
[Strapi](https://docs.strapi.io/cms/features/rbac),
[WAI switch](https://www.w3.org/WAI/ARIA/apg/patterns/switch/),
[WAI table](https://www.w3.org/WAI/ARIA/apg/patterns/table/).

## Doğrulama

Ana ajan sıralı olarak doğruladı:

| Kontrol | Sonuç |
| --- | --- |
| Değişen/yeni PHP dosyaları | 104 dosyada sözdizimi temiz |
| Matris / auth / endpoint eylemleri | 207 / 32 / 25 kontrol geçti |
| Depo kapsamı ve gerçek maliyet bağlantısı SQL'i | 54 kontrol geçti |
| Rapor kapsamı ve export okuma kapıları | 10 kontrol geçti |
| Sipariş ayrımı / gerçek pipeline dalları | 24 / 18 kontrol geçti |
| Personel istek politikası / gerçek motor ve istek kapıları | 416 / 19 kontrol geçti |
| Sipariş çekirdeği / UI / ürün siparişleri | 359 / 389 / 77 kontrol geçti |
| Hesap mantığı / UI / fiş sahiplik izolasyonu | geçti / geçti / 142 kontrol geçti |
| Hakediş / Faz 8B hesap regresyonu | 44 / 28 kontrol geçti |
| Dashboard/sidebar | 35 anonim profilde 2.704 kontrol geçti |
| Mobil gezinme | 88 anonim sayfada 2.421 değişmez + 1.326 davranış geçti |
| Roller modalı | 1440/820/390/320px'de 108 tarayıcı kontrolü geçti |

Rol oluşturma/düzenleme/silme, son yönetici kilidi, eski izin koruma ve
depo seçimi anonim SQLite UI testinde geçti. Node sözdizimi ve
`git diff --check` temiz. Geçerli testler yeni kod değişikliği veya bulgu
gerektirmedikçe tekrarlanmadı; finansal/servis pencereleri sıralandı.

Son bağımsız inceleyici `independent_review` açık P1/P2 bulgu kalmadığını
bildirdi. Bu statik kaynak kabulüdür; işlevsel kanıtları ana ajan üretti.
Bulunan eşleştirme, otomatik/manüel sipariş restore, şablon yönetimi,
maliyet bağlama, rapor export, günlük giriş/çıkış ve kart upsert açıkları
kapatıldı. İlk iki uygulayıcı son aşamada kapasite sınırına ulaştı; kalan
düzeltme ve testleri ana ajan tamamladı, bağımsız inceleyici son diff'i okudu.

Anonim kanıtlar: `%TEMP%/roles-matrix-final-qa/proof.json` ve aynı klasörde
masaüstü/dar ekran PNG'leri; `%TEMP%/roles-matrix-bottomnav` mobil gezinme
kanıtları. Ekran görüntülerindeki rol/depo isimleri sentetiktir, kaydedilmedi.
Tam genel CI veya canlı kabul iddiası yoktur.

Eski `cost_link_smoke.php` güvenli izole harness değildir: yanlışlıkla çağrıldı,
MySQL sürücüsü olmadığı için bağlantı aşamasında durdu. Yeniden çalıştırılmadı;
gerçek bağlantı/migration/kayıt yazımı gerçekleşmedi. Bunun yerine gerçek
`cost_link_record_summary()` SQL'i anonim SQLite üzerinde, bootstrap
yüklenmeden scope testine dahil edildi. Hesap izolasyon testinin ilk koşusu
eksik GD nedeniyle kabul üretmedi. Sonraki koşu yalnız o PHP sürecine kurulu
GD/fileinfo DLL'leri yüklenerek 142/142 geçti; sistem ayarı değiştirilmedi.
İlk başarısız sonuçlar başarılı test sayılmaz. Güvensiz manuel maliyet aracı
otomatik test listesinden çıkarıldı; komutsuz çağrı bootstrap'tan önce durur.

Windows'ta ortaya çıkan mevcut Sipariş realpath ayırıcı uyumsuzluğu,
kök dizin sınırı korunarak `DIRECTORY_SEPARATOR` ile düzeltildi. POSIX dosya
izin testleri POSIX'te aynen kalır; Windows'ta okunabilirlik sınanır. Linux
dosya modu veya native MySQL testi bu yerel Windows koşusundan çıkarılamaz.

Canlı Atlas/asya.scai.tr/hosting, mevcut iş DB'si veya FDB'ye bağlantı/yazım,
push/merge/deploy yapılmadı. Gerçek kayıtlar testlere veya görsellere taşınmadı.
Native MySQL, finansal kabul ve fiziksel telefon kabulü iddia edilmez.
Atlas otomasyonu PAUSED, Atlas current/lastAccepted ve açık görsel QA işleri
bu ayrı özellik tarafından değiştirilmez.
