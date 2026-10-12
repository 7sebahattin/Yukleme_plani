# Kullanıcı, rol ve Beyan yetkilendirmesi

12 Ekim 2026

## İnceleme ve karar

Mevcut rol altyapısı bir kullanıcıya birden fazla rol atıyor ve rollerin yetkilerini birleştiriyor. Ancak Beyan oluşturma, düzenleme ve HKS bildirimi `beyan.write` içinde birleşiyor; bölüm erişimi ayrılmıyor. Uzun, düz kutucuk listesi bu farkları anlatmıyor. Kullanıcı örneğindeki menü / alt bölüm yaklaşımı mevcut rol tabloları üzerinde uygulanacak.

Sayfa erişimi, işlem yetkisi ve bölüm erişimi ayrı tutulur. Görmek, eklemek, düzenlemek ve silmek birbirini otomatik kapsayan seviyeler değildir. Birden fazla rolün izinleri birleşir; başka bir rolden verilen izin, seçili rolde kaldırılarak geri alınamaz. Yönetici rolünün mevcut özel erişimleri korunur ve arayüzde açıklanır.

## Uygulama

1. Roller: aranabilir modül grupları, işlem ve bölüm ayrımı, seçili izin özeti, güvenli toplu seçimler; masaüstünde geniş, mobilde tek sütun düzen.
2. Beyanlar: oluşturma, düzenleme, durum, plan eşleştirme ve HKS bildirimi ayrı izinler. Analiz, gümrük/iletişim, HKS ve kaynak metin için ayrı görüntüleme/düzenleme.
3. Uyumluluk: eski rollerin mevcut erişimini koruyan rol bazlı genişletme; ayrıntılı moda geçirilen rolün yalnız seçilen izinleri geçerli. Mevcut `role_permissions` tablosu kullanılır, şema değişikliği yapılmaz.
4. Güvenlik: yetkisiz alanlar HTML/JSON içinde gönderilmez; kayıt uçları bölüm izinlerini uygular ve mevcut kısıtlı verileri korur. Yalnız düğme gizlemek yeterli değildir. CSRF, audit ve son yönetici koruması sürer.
5. Doğrulama: eski roller, ayrıntılı roller, çoklu rol birleşimi, yetkisiz doğrudan istekler, kısıtlı alanların korunması, mevcut Beyan akışları, masaüstü/mobil düzen ve modal kaydırma.
6. Teslim: değişiklikleri gözden geçir, ilgili testleri çalıştır, sürüm/cache damgasını eşleştir, PR aç ve kullanıcı talimatıyla main'e merge et.

## Sınır ve açıklamalar

Diğer modüllerin mevcut işlem yetkileri aynı editörde düzenlenir. Bu değişiklikte bölüm düzeyindeki sunucu kontrolleri Beyan modülünde uygulanır. Kaynak metin ham belge içeriğini barındırabildiğinden ayrıca hassas bir erişim olarak değerlendirilmelidir. Kullanıcı yönetimindeki eski `users.read` / `users.write` izinleri tek başına yönetim ekranını açmaz; `users.admin` gereksinimi açıkça gösterilir.

## Araştırma

[OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html): her istekte sunucu tarafında yetki doğrulama ve açık yetki bulunmadığında erişimi reddetme ilkeleri uygulamanın temelidir.

## Uygulanan sonuç — v331

- Roller editöründe sayfa erişimi, işlem ve bölüm izinleri ayrıldı; arama, yalnız seçilenler filtresi ve kritik yetkileri vermeyen hazır seçimler eklendi. Mobil açıklamalar kapalı ayrıntılara alındı; sabit alt eylemler ve klavye erişimi doğrulandı.
- Kullanıcı formuna seçilen rollerin birleşik izin önizlemesi eklendi.
- Beyanlar için beş işlem ve dört bölümün görüntüleme/düzenleme izinleri sunucu uçlarında uygulandı. Ayrıntılı mod rol bazında değerlendirilir; bir rolün işaretçisi başka rolün eski izinlerini değiştirmez.
- Gizli bölüm verileri form, liste, detay ve öneri yanıtlarında çıkarılır. Kısıtlı alanlara gönderilen değerler mevcut kaydı değiştiremez. Plaka, HKS bölümü gizli veya salt okunur olduğunda temel formda düzenlenebilir; tek alan gönderilir.
- Oluşturma yetkisi olup düzenleme yetkisi olmayan kullanıcı kopyalamadan sonra erişebildiği detay sayfasına gider. Düzenleme yetkisi ve kaynak metin yazma izni olan kullanıcı metin ayrıştırabilir.
- Doğrulama yerel SQLite, gerçek PHP uç noktaları ve Edge/Playwright ile yapıldı; canlı MySQL ve HKS/SOAP gönderimi çalıştırılmadı. Migration yoktur.
