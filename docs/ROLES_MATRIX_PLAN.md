# Roller: sayfa ve alt sayfa yetki matrisi

Kapsam: mevcut Asya Fresh uygulaması — asya.scai.tr.

31 Ekim 2026. Ayrı `codex/roles-permission-matrix` worktree'sinde yerel geliştirme.

## Analiz ve araştırma

Mevcut roles.php, izinleri modül kartlarında uzun açıklamalar halinde karıştırıyor.
Yeni ekran sidebar sırasını izleyen sayfa ve alt sayfa satırları kullanır.
Örnek görseldeki sade yatay satırlar, yeşil/gri anahtarlar ve sabit Gör / Ekle /
Düzenle / Sil sütunları temel alınır. Desteklenmeyen eylem boş anahtar yerine —
olarak görünür; yöneticiye özel sayfalar açıkça işaretlenir.

Resmî örnekler:
- Directus: koleksiyon ve CRUD eylemi başına izin, kayıt/alan kapsamları:
  https://directus.com/docs/guides/auth/access-control
- Strapi: bölümler, alt bölümler ve ayrı özel eylemler:
  https://docs.strapi.io/cms/features/rbac
- WAI switch: ikili kontrol, sabit etiket ve Space desteği:
  https://www.w3.org/WAI/ARIA/apg/patterns/switch/
- WAI table ve treegrid: native tablo + normal form kontrolleri tercih edilir;
  tam ok tuşu/focus sözleşmesi uygulanmadan treegrid rolü verilmez:
  https://www.w3.org/WAI/ARIA/apg/patterns/table/
  https://www.w3.org/WAI/ARIA/apg/patterns/treegrid/

## Uygulama planı

1. Saf backend matrisi tüm sidebar sayfalarını ve alt başlıklarını mevcut izinlerle
   eşler. Ayrılabilen oluştur/düzenle eylemleri backend'de gerçekten uygulanır.
2. roles.php editörü matrisi native tablolarla basar; dört ana eylem sabit sütundur.
   Alt sayfalar açılır, özel eylemler kendi detay alanındadır.
3. Arama + bölüm + tümü/seçili/seçili olmayan filtreleri görünümü daraltır; hiçbir
   filtre izin seçimini değiştirmez. Eşleşen çocukların ataları görünür kalır.
4. Eski rol izinleri rol başına genişletilir; detaylı mod işaretiyle yeniden
   kaydedilir. Matrise henüz yerleşmemiş katalog izinleri açık uyumluluk bölümünde
   korunur. Tek izin birden çok satırda görünürse seçimleri eşzamanlıdır.
5. Kullanıcı veri kapsamı, backend doğrulaması ve sorgu uygulamasıyla birlikte
   işlevli API olarak sunulur; yalnız görsel kontrol eklenmez.
6. Modal klavye döngüsü, Escape, kaydırılabilir gövde ve sabit alt düğmeler korunur.
   Mobilde her satırın başlığı ve dört eylem aynı sırada okunabilir.
7. Ana ajan sıralı yerel PHP/izin/tarayıcı kontrollerini çalıştırır; farklı Codex
   ajanı bağımsız inceleme yapar. Canlı veri veya DB kullanılmaz.

## Sınırlar

Kaynak/gerçek veri, gizli bootstrap ve DB bağlantıları kullanılmaz. Şema migration,
push, merge ve yayın bu yerel uygulama tesliminin kabulü değildir; somut çıktı
sonrası proje talimatındaki son açık onay kapısı geçerlidir.
