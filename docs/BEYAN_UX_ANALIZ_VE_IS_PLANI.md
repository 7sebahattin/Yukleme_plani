# Beyan ekranları — inceleme ve iş planı

Tarih: 11 Ekim 2026. Kapsam: kaynak koduna dayalı tasarım ve iş akışı incelemesi. Bir analiz ajanı iş mantığını, ana ajan yerleşimi inceledi ve bulguları birleştirdi. Uygulama kodu değiştirilmedi; canlı kayıt veya HKS bildirimi oluşturulmadı. Açık uygulama oturumu/yerel sunucu bulunmadığından mevcut arayüz tarayıcıda doğrulanmadı. Tasarım örnekleri temsili veriler kullanır; mevcut ekran görüntüsü değildir.

## Sonuç

Alan kaybının temel nedeni büyük boşluklardan önce bilgi sıralaması: kaynak metin ve HKS alanları her zaman açık, beyanın asıl bilgileri daha aşağıda, geniş ekranda bile iki sütun var. Sadece yazıları ve alanları küçültmek yeterli değil. Kullanıcının sık yaptığı düzeltme için parti, ürün, miktar, alıcı ve plaka öne alınmalı; kaynak metin ve yardımcı ayrıntılar gerektiğinde açılmalı.

Önerilen ana tasarım **Kompakt form**. Alternatif **Bölümlü çalışma alanı**, HKS bilgilerini sürekli kontrol eden kullanıcılar için uygundur; mobilde sağ panel alta iner. Oluşturma/düzenleme aynı alan düzenini kullanır. Tasarım seçimi uygulama değişikliği anlamına gelmez.

## Yerleşim ve tasarım bulguları

| ID | Öncelik | Bulgu / kullanıcıya etkisi | Öneri | Kaynak |
|---|---|---|---|---|
| Y1 | Yüksek | Oluşturma ve düzenleme en üstte 8 satır ham metin, 3 satır eşleşmeyen metin gösteriyor. Mevcut kaydın yalnız plakasını değiştirmek için bile bu blok geçiliyor. | Oluşturmada metinden doldurma ayrı bir açılır alan; düzenlemede kaynak metin kapalı. Eşleşmeyen satırlar yalnız varsa sayıyla ve açılabilir içerikle gösterilsin. | `beyan_create.php:182-205`, `beyan_edit.php:232-254` |
| Y2 | Yüksek | HKS bölümü temel bilgilerden önce geliyor. Beyan ürün adı ile HKS katalog ürünü ayrı bölümlerde; ilişkileri gözle takip etmek zor. | Ürün ve sevkiyat ilk bölüm olsun. HKS eşleştirmesi aynı ürün bağlamında özetlensin; eksik alan sayısı görünür kalsın. Plaka sevkiyat içinde tek alan olsun. | `beyan_create.php:207-211`, `config/helpers.php:2716-2727` |
| Y3 | Yüksek | Form geniş masaüstünde de iki sütun. Kısa palet/kasa/KG değerleri uzun metinler kadar geniş hücre alıyor. Ürün bölümü 7 alan için 4 satır kaplıyor. | Uygun genişlikte kimlik/sevkiyat için 3 sütun; kısa miktarlar tek kompakt satır. Mobilde 1–2 sütuna düşme; okunabilirliği küçültmeme. | `assets/style.css:5118-5124`, `beyan_create.php:248-284` |
| Y4 | Orta | Şirket adı ve adres ayrı tam genişlik satırları; analiz tarihleri ve notu her durumda açık. Birçok ikincil alan temel işlemi uzatıyor. | Şirket/iletişim ve analiz için özetli açılır bölümler. Red seçilirse not otomatik görünür; gerekli alan kapalı kalmaz. Dolu bilgilerin kısa özeti başlıkta kalsın. | `beyan_create.php:234-241`, `:317-346`, `beyan_edit.php:358-408` |
| Y5 | Yüksek | Kaydet/Güncelle yalnız uzun formun sonunda. Düzenlemede Sil aynı işlem satırında. | Görünür üst işlem çubuğu, gerçek uygulamada kaydırmada erişilebilir konum; mobilde klavyeyle çakışmayan alt işlem çubuğu. Sil ayrı ikincil menüde. Kaydedilmemiş değişiklik görünürlüğü. | `beyan_create.php:348-350`, `beyan_edit.php:414-428` |
| Y6 | Orta | Detayda HKS kartı ve bildirim geçmişi temel bilgilerden önce; geçmiş arttıkça beyan bilgileri aşağı itilir. Net KG tekrar gösteriliyor. | Tek kimlik/sevkiyat özeti ve HKS hazırlık özeti; geçmiş kapalı ayrı bölüm. Tekrarı yalnız karar vermeye yardım ediyorsa koruma. | `beyan_view.php:149-227` |
| Y7 | Orta | Liste iki uzun blok halinde. Alt bloğun adı “Yüklenen Beyanlar”, fakat red ve iptal de içinde. Arama bu alt blokta ve üstteki bekleyenlere uygulanmıyor. | “İşlem bekleyen / Kapanan / Tümü” kapsamı açık liste düzeni. Her kapsamda arama ve sayfalama. Sayıları tüm kayıt/filtre sonucu olarak açık adlandırma. | `beyanlar.php:204-248`, `config/helpers.php:2008-2009` |
| Y8 | Orta | Detay ana eylemi “Bildirim Yap”; geçmiş varsa alt açıklama bu ekranın HKS'ye göndermediğini söylüyor. İlk kullanımda eylemin sonucu belirsizleşiyor. | Ana eylemi “HKS taslağı oluştur” olarak adlandırma; gerçek gönderim adımını ayrıca belirtme. Firma dahil eksikleri aynı hazırlık özetinde gösterme. | `beyan_view.php:180-219`, `config/helpers.php:2716-2718` |

## Mantık ve veri doğruluğu bulguları

P1: veri doğruluğunu/işi tamamlama yolunu etkileyen yüksek öncelik. P2: kullanımı ve kontrolü aksatan sorun. Bunlar kaynak akışından doğrulanmıştır; canlıda yaşanmış olay iddiası değildir.

| ID | Öncelik | Sorun ve örnek etki | Düzeltme yönü | Kanıt |
|---|---|---|---|---|
| M1 | P1 | Tekli ayrıştırma sayısal `123.5` değerini forma `123.5` yazıyor; kayıt okuyucusu noktayı binlik ayraç sayıp `1235` yapabiliyor. | Tekli/toplu akışta ortak, türü belli sayı aktarımı; kullanıcıya Türkçe ondalık gösterim. | `assets/app.js:202-207`, `beyan_create.php:68-69`, `config/helpers.php:31-42` |
| M2 | P1 | Düzenleme formu ve detay durum formu KG'yi 0 ondalıkla hazırlıyor. `123,450` tekrar kayıtta `123` olabilir. | Mevcut üç ondalık hassasiyeti koruma; görünüm biçimlendirmesini kayıt değerinden ayırma. | `beyan_edit.php:38-39`, `beyan_view.php:379-380`, `config/helpers.php:1191-1192` |
| M3 | P1 | Detaydan sadece durum değiştirirken bütün alanlar sayfanın açıldığı andaki gizli kopyayla gönderiliyor. Başkasının yeni plaka/not düzeltmesi ezilebilir. | Yalnız durum/not alanlarını değiştiren işlem; tam düzenlemede kayıt sürümü kontrolü. | `beyan_view.php:365-385`, `beyan_edit.php:126-136` |
| M4 | P1 | HKS kataloğu boş/okunamaz olduğunda form okuyucusu tüm HKS eşleşmelerini null döndürüyor; normal güncelleme mevcut eşleşmeleri silebilir. | Katalog hatasıyla bilinçli alan temizlemeyi ayırma; mevcut veriyi koruma veya anlaşılır hata. | `config/helpers.php:2300-2302`, `:2734-2738`, `beyan_edit.php:121-167` |
| M5 | P1 | Listede “Yüklendi” işaretlemek plan bağlamıyor; sonra yüklendi durumunda eşleştirme kapalı. Sonradan bağlantı kurmak için durum geri alınmalı. | Durum ve plan bağlantısını ayrı, anlaşılır işlemler olarak tasarlama; sonradan eşleştirme yolu. | `_beyan_liste.php:72-78`, `beyan_edit.php:58-75`, `beyan_view.php:34-35` |
| M6 | P2 | Bekleyen kayıtlar en yeni 200 ile sınırlı. Daha eskilere bu listede arama veya sonraki sayfayla ulaşma yok. | Bekleyenler için arama, sayfalama ve toplam/gösterilen sayı açıklığı. | `beyanlar.php:32-34`, `:101-118`, `:225-228` |
| M7 | P2 | Plan eşleştirme araması yalnız yüklenmiş son 90 günlük/en fazla 200 satırı süzüyor. Eski plan aramada yokmuş gibi görünebilir. | Sunucu tarafında bütün izinli planlarda arama/sayfalama; tarih kapsamını görünür kılma. | `beyan_view.php:38-68`, `:654-661` |
| M8 | P2 | Yeniden ayrıştırma dolu alanları değiştirmiyor, ama “N alan dolduruldu” diyor. Kullanıcı yeni metnin uygulandığını sanabilir. | Bulunan, uygulanan ve farklı olan alanları ayırma; mevcut/yeni değer karşılaştırması. | `assets/app.js:202-225` |
| M9 | P2 | Toplu önizlemede satır seçme, yanlış alan düzeltme ve eşleşmeyen metni inceleme yok. “Tekli düzenle” seçilen beyanı forma taşımıyor. | Seçilebilir/düzenlenebilir önizleme; yalnız seçilenleri kaydetme; satır bazında sorun açıklaması. | `assets/app.js:237-294`, `beyan_bulk_save.php:45-52` |
| M10 | P2 | Geçersiz sayılar sıfır olabilir; kesirli palet/kasa kesilir. Negatif miktar, net > brüt ve ters tarih sırası için kontrol yok. | Alan yanında biçim doğrulaması; tam sayı ve çapraz alan kuralları. İstisnalar iş kuralına göre açıkça tanımlansın. | `beyan_create.php:67-79`, `beyan_edit.php:104-117`, `config/helpers.php:31-42` |

### Karar gerektiren iş kuralı

Red ve iptal beyanları planla eşleştirilebilir; işlem bunları yüklendiye taşıyabilir (`beyan_view.php:34-35`, `beyan_eslestir.php:75-86`, `:133-136`). Serbest durum geçişi kaynakta bilinçli tercih olarak açıklanmış. Bu nedenle doğrudan hata ilan edilmedi. Uygulama aşamasında “yeniden aç, gerekçe gir, sonra yüklemeye geçir” gibi açık bir akışın gerekip gerekmediği netleştirilmeli.

## Ekranların önerilen düzeni

### Kompakt form — önerilen

1. Başlık, parti/durum, erişilebilir Kaydet/Güncelle.
2. “WhatsApp metninden doldur” açılır alanı; düzenlemede başlangıçta kapalı. Metinden oluşturma seçilirse açılır, ayrıştırmadan sonra kısa özet ve sorun sayısına iner.
3. Parti, alıcı, depo, ürün/çeşit, marka, plaka ve nakliye: masaüstünde 3 sütun.
4. Palet, kasa, kasa cinsi, brüt/net: uygun genişlikte tek satır.
5. Şirket/iletişim, HKS eşleştirmesi ve analiz: kısa özetli açılır bölümler. Eksiklik ve hata işaretleri kapalıyken de görünür.
6. Kaydedilmemiş değişiklik bilgisi. Hata varsa ilgili bölüm otomatik açılır ve ilgili alana yönlenir.

### Bölümlü çalışma alanı — alternatif

Beyan / Analiz / Kaynak metin sekmeleri, geniş ekranda sağda dar HKS hazırlık paneli. Daha az kaydırma, fakat sekmeler nedeniyle bağlam değiştirme maliyeti vardır. Düzenleme/HKS kontrolü ağırlıklı kullanımda değerlendirilebilir. Küçük ekranda yan panel alta taşınır; tüm alanlar erişilebilir kalır.

Örneklerin Ekran seçimi oluşturma/düzenleme başlığı ve eylemini gösterir; örnek kayıt gerçek veri değildir. Bölüm açma, sekme değiştirme, karşılaştırma geri bildirimi ve örnek değerleri geri alma yereldir. Kaydet düğmesi veritabanına işlem yapmaz.

## Uygulama iş planı ve ekip yönetimi

Bu tur yalnız analiz/tasarım içindir. Kodlama ajanları başlatılmadı. Uygulama işi verildiğinde ana ajan kapsamı, ortak dosyaları ve kabul kontrollerini yönetecek; tek seferde en fazla bir uzman ajanla çalışılacak. Sürekli paralel ekip ve yinelenen tam depo okumaları yerine küçük teslimler kullanılacak.

| Sıra | İş paketi | Sorumluluk | Somut teslim / bitiş ölçütü |
|---|---|---|---|
| 1 | Veri doğruluğu: M1–M4 | İş mantığı ajanı; ana ajan incelemesi | Tekli/toplu metin, düzenle-kaydet ve durum değiştir sonrasında KG hassasiyeti korunur. Durum işlemi başka alanı yazmaz. Katalog kesintisi eşleşme silmez. |
| 2 | Oluşturma/düzenleme ortak kompakt düzeni: Y1–Y5 | Arayüz ajanı; ana ajan entegrasyonu | Alan sırası ve bölümler ortak. Kaynak metin kapatılabilir. Gerekli hata alanı görünür. 1440×900 ve 1366×768 masaüstü, 390 ve 320 px mobil kontrolü. |
| 3 | Ayrıştırma ve toplu önizleme: M8–M10 | Aynı arayüz/iş mantığı görevlendirmesi, sırayla | Gerçekte uygulanan alan sayısı doğru. Satır seçme/düzeltme var. Sorunlu veri sessizce sıfıra dönmüyor. |
| 4 | Liste/detay/eşleştirme: Y6–Y8, M5–M7 | Ana ajan + ihtiyaç halinde aynı uzman ajan | 201'inci bekleyen kayıt bulunur. 90 günden eski plan aranabilir. Kapanan etiketi red/iptali doğru anlatır. HKS taslak/gönderim ayrımı açık. |
| 5 | Son kontrol ve teslim | Ana ajan | Yetkiler, mevcut silme onayı, mobil klavye/odak, açık-koyu tema, hatalı ve boş durumlar gözden geçirilir. Değişiklik kapsamına uygun mevcut smoke kontrolleri çalıştırılır. |

### Kabul senaryoları

- Aynı örnek beyan tekli ve toplu ayrıştırıldığında `123,450` kg aynı kalır; düzenle-kaydet ve yalnız durum değiştir işleminde değişmez.
- İki kullanıcı aynı kaydı açar; birinin plaka değişikliği diğerinin durum işlemiyle geri alınmaz.
- HKS kataloğu okunamazken kaydetme mevcut firma/ürün/ülkeyi sessizce silmez.
- Sadece plaka düzeltmek için ham metin alanını geçmek veya şirket adresini açmak gerekmez.
- “Red” seçilince not görünür ve anlaşılır şekilde istenir; hata gizli sekmede/bölümde kalmaz.
- 200'den fazla bekleyen kayıtta en eski kayıt aranır ve açılır.
- Toplu önizlemede tek bir hatalı satır düzeltilir ya da seçilmez; diğerleri kaydedilebilir.
- Mobilde etiketler ve işlemler taşmaz; azaltılan boşluklar küçük dokunma hedefleriyle telafi edilmez.

## Limit ve kapsam

Başlangıç kullanım göstergesi: 5 saatlik %1, haftalık %9 kullanılmış. Bunlar hesap genelindeki anlık değerlerdir; bu incelemenin maliyeti değildir. Tek analiz ajanı kullanıldı. Ek araştırma ajanı, uygulama geliştirme veya yayınlama başlatılmadı. Piksel bazlı kazanç yüzdesi verilmedi; mevcut ekranın canlı ölçümü yapılmadı.

Derin incelenenler: liste, oluşturma, düzenleme, detay, metin ayrıştırma/toplu kayıt, yükleme planı eşleştirme ve ilgili HKS form/durum sınırları. Bildirim tanı sayfası ile ayrı Hal Kayıt panelinin tamamı ayrıntılı entegrasyon denetimi kapsamında değildir.


## Uygulama teslimi — 11 Ekim 2026

Kullanıcı ikinci tasarımı seçti; mevcut site renkleri ve ikonları korundu. Önceki analiz aşamasındaki kod yazmama kararı bu onayla sona erdi. Üç uzman ajan ortak form, tarayıcı davranışı ve veri doğruluğunu üstlendi; ana ajan liste/detay/eşleştirme, entegrasyon, son test ve merge işlemlerini yürüttü.

- Ortak form: Beyan / Analiz / Kaynak metin sekmeleri, üst kayıt eylemi, kapalı şirket ayrıntıları, geniş ekranda HKS yan paneli. JavaScript kapalıyken bölümler görünür kalır.
- Ayrıştırma bulunan/uygulanan/çakışan alanları ayırır; dolu değerler ve sıfır korunur. Toplu önizleme düzenlenebilir ve seçilebilir; belirsiz ağ hatasında aynı istek kimliği ve veriyle tekrar denenir.
- KG hassasiyeti, ortak sayı/tarih doğrulaması, yalnız durum güncelleme, eşzamanlı tam düzenleme kontrolü ve katalog arızasında HKS verisini koruma eklendi.
- Bekleyen/kapanan listeleri korunarak bağımsız arama ve 50 satır sayfalama eklendi. Eşleştirme bütün izinli tarihlerde aranır. Bağlantısız Yüklendi beyanı kilitli olmayan plana eşleştirilebilir.
- HKS eylemi “HKS Taslağı Oluştur” olarak açıklandı; bildirim geçmişi kapalı ayrıntı alanına alındı. Serbest durum geçişleri mevcut iş kuralı olarak korundu.
- Son kontroller: SQLite ile gerçek yardımcılar ve izole toplu uç nokta; mevcut UI/HKS testleri; Playwright sekme/doğrulama/ayrıştırma/toplu tekrar; masaüstü ve mobil görsel kontrol. Gerçek MySQL ve canlı HKS/SOAP bu yerel testlerin kapsamında değildir. Yeni migration yoktur.

Analiz kanıt satır numaraları ilk incelemeye aittir. Yukarıdaki yalnız analiz ve limit paragrafları ilk aşamayı anlatır; bu bölüm kullanıcı onaylı uygulama aşamasını kaydeder.
