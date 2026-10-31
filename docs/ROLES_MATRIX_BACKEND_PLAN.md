# Rol matrisi backend planı

- Ortak saf API: config/role_permission_matrix.php, role_permission_matrix() => grup adı => satırlar. Satır key/label/route/actions/children/note/admin_only; action permission/label.
- Bilinen sidebar hedefleri ve mevcut alt bölümler görünür. Hazır olmayan yönetici sayfalarına işlem yetkisi uydurulmaz.
- role.permissions.detailed belirteci olmayan eski roller create/edit ve rapor tür izinleriyle genişletilir. Genişletme rol başına yapılır, sonra roller birleştirilir.
- Yeni create/edit izinleri mevcut write altyapısına köprü kurar; oluşturma/düzenleme uçları ayrıca açık action kapısıyla ayrılır.
- Sekiz rapor türü doğrudan URL ve export dahil sunucuda kontrol edilir.
- Mevcut admin-only kısıtları korunur. Şema veya seed migration yapılmaz.
- Veri kapsamı mevcut depo ve hesap sahipliği kontrollerini korur; yeni kapsam modeli ayrıca güvenlik incelemesine tabidir.

## Son şema kararı

- Yüklemeler: records.loading.{read,create,edit,delete}; Paletleme: records.pallet.*. Kayıt türü sunucuda kontrol edilir.
- OGL Siparişler: siparis.ogl.*; Ürün Siparişler: siparis.urun.*. require_siparis ikinci açık sayfa parametresi alır; varsayılan OGL, ürün uçları açıkça urun geçirir.
- Hal Bildirimi: hks.access; yeni rol için kayıt oluşturma yetkisi bu sayfayı kendiliğinden açmaz.
- Sayfa gezinmesi masaüstü, mobil ve dashboard için aynı page helper sonucunu kullanır.
- Stok mevcut hareketleri düzenleme/silme/taşıma yöneticiye özgüdür; rol matrisinde bu işlemlere sahte yetki verilmez.
- Personel alt sayfaları mevcut birleşik işlem paketlerini gösterir; ayrı CRUD olmayan paketin kapsamı açıklanır.
- Root ajan ayrı depo kapsamı normalizasyonu/ortak SQL kontrolleri ve kayıt türü erişim kontrollerini uygular.
- Arşiv günlük rapor snapshot'larında seçili rol deposu doğrulanamadığı için erişim kapalıdır. Bu durum güvenli sınır olarak UI'da açıklanır.

## Test kapsamı

Yeni scripts/role_permission_matrix_smoke.php eski rol genişlemesini, yeni rolün izinsiz kardeş sayfaya taşmamasını, HKS ayrımını, çoklu rol birleşmesini ve gerçek sayfa envanterini denetler. İşlevsel çalıştırma penceresini root koordine eder. Gerçek veritabanı bağlantısı, migration veya canlı kabul yoktur.

## Belge işlem hattı sınırı

OGL PDF/Excel otomatik eşleştirmesinde hedef kimliği önceden bilinmeyebilir. Bu nedenle işlem yetkisi yalnız POST hedefinden seçilmez: gerçek sevkiyat bul/oluştur helper'ı pipeline authorization callback'ini mevcut/yeni/yarışta bulunan kayıt dalında çağırır. PKL ve açık hedefe bağlama mevcut kayıt düzenleme yetkisi ister. Aynı tur numarasındaki diğer sevkiyata olay/yenileme yazılması da düzenleme kontrolüne bağlıdır; yetki reddinde işlem transaction'ı geri alınır. Yüklenmiş belge dosyası/hata metadatası kalabilir, sevkiyat/kalem/bağ mutasyonu kalmaz. Regresyon: scripts/role_siparis_pipeline_smoke.php, anonim SQLite gerçek helper dalları.
