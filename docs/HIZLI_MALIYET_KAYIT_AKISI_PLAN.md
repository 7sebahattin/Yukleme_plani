# Hızlı Maliyet — kayıt akışı ve sade form planı

30 Ekim 2026 analiz planı; 31 Ekim kullanıcı ekleriyle güncellendi. Analiz ve
plan değerlendirmesinden sonra iki uygulayıcı kodlamayı tamamladı. Fonksiyonel
testleri ana ajan yürütür; gerçek sonuçlar ayrı teslim belgesinde tutulur.

## Analizden çıkan kararlar

Mevcut `hizli_maliyet.php` listeyi, formu, kaydetmeyi ve manuel tablo kurulumunu
aynı controller içinde yürütüyor. `quick_cost_sheets` ayrı tablosunda sürüm
kolonu var; silinme kolonu veya başka tablolara kayıt bağlantısı yok.
Yeni istek şema değiştirmeden uygulanabilir. Eski gelişmiş maliyet modülü,
`cost_sheets` ve mevcut hesap motoru bu çalışmanın kapsamına girmez.

Ana adres kayıt listesi olacak; oluşturma, düzenleme ve kopyalama ayrı form
adresinde çalışacak. Yalnız A/B/D harfleri/rozetleri/önekleri ve Üretim/Fason seçimi arayüzden
kaldırılacak; kalem adları ve adlandırılmış grup ara toplamları korunacak. Hesabın temel matematiği, on maliyet kalemi, kesin kuruş hesabı
ve mevcut kayıtların iç metadata alanları korunacak.

İzole hızlı maliyet kayıtlarında silme özelliği fiziksel silme kullanacak.
Soft delete için şema değişikliği veya JSON içine görünürlük işareti eklemek
gerekirdi. Bunlar yapılmayacak. Silme yalnız kullanıcının açık onay verdiği
uygulama POST işlemiyle mümkün olacak; ajan gerçek kayıt silmeyecek.

## Aşama 1 — controller ve veri katmanı

1. `hizli_maliyet.php`, aktif depo kapsamlı kayıt listesine dönüştürülür.
   Sayfada **Yeni maliyet hesapla** düğmesi, ürün arama, sayfalama ve kayıt
   bazında açma/düzenleme ile silme eylemleri bulunur. Liste sayfasında
   `qc-form` veya hesap giriş alanları bulunmaz. İşlem türü ve grup harfleri
   kayıt kartlarında gösterilmez.
2. Yeni `hizli_maliyet_form.php`, mevcut oluşturma/düzenleme/kopyalama
   akışını devralır. Yalnız hesap formunu gösterir; kayıt listesine dönüş
   bağlantısı bulunur. Başarılı kaydetme/kopyalama listeye flash mesajıyla
   yönlenir. Formdaki kopyalama, kaynak kaydı değiştirmeden yeni kayıt üretir.
3. Mevcut `operation_type` ve `method` kolonları ile JSON alanları korunur.
   Yeni kayıtta mevcut varsayılanlar kullanılır. Düzenlemede ve kopyalamada
   eski değerler değişmeden taşınır; görünmeyen seçimler sıfırlanmaz ve
   bunlar için migration yapılmaz.
4. Yeni veri katmanı arayüzü eklenir:

   ```php
   quick_cost_delete(PDO $pdo, int $id, int $expectedVersion): void
   ```

   İşlem, mevcut `quick_cost_access(true)` admin/okuma/yazma/aktif depo kapısından
   sonra ayrıca `can_maliyet('delete')` ister. Pozitif id/sürüm, audit
   altyapısı ve çağıranın açık transaction içinde olmaması doğrulanır.
   Yetki reddi hedef tablo sorgusundan önce gerçekleşir.

   Tek transaction içinde yalnız aktif depodaki hızlı maliyet kaydının audit
   için gerekli metadata alanları okunur. Silme için `document_json` hesap
   motorundan geçirilmez; bozuk JSON içeren bir kayıt da yetki ve sürüm
   koşullarıyla silinebilir. Sürüm karşılaştırılır, ardından yalnız
   `quick_cost_sheets` tablosuna `id + version + aktif depo kapsamı` koşullu
   DELETE gönderilir. Etkilenen satır bir olmalı; aksi halde çakışma verilir.
   `delete / quick_cost_sheets` audit olayı eski özet ve yeni `null` ile
   kaydedilir. Başarıda commit, hatada rollback uygulanır.

   Mevcut audit helper'ın hata politikasına uyulur; helper kendi hatasını
   yalnız loglayabiliyorsa audit hatasında mutlaka rollback olduğu söylenmez.

### Adresler ve eylemler

| İstek | Davranış |
| --- | --- |
| `GET hizli_maliyet.php` | Kayıt listesi, arama, sayfalama ve yeni hesap düğmesi |
| `GET hizli_maliyet_form.php` | Yeni hesap formu |
| `GET hizli_maliyet_form.php?id=N` | Aktif depodaki kaydı açma/düzenleme |
| `GET hizli_maliyet_form.php?id=N&copy=1` | Kaynak değerleri korunan yeni hesap taslağı |
| Eski `GET hizli_maliyet.php?id=N[&copy=1]` | İd doğrulamasından sonra yeni form adresine 302 |
| Yeni form POST `payload/version/csrf` | Sunucuda doğrulama ve yeniden hesaplama; başarıda listeye dönüş |
| Eski ana adrese POST `payload/version/csrf` | Gövde kaybedilmeden aynı istekte form controller'ına iletilir |
| Liste POST `action=delete`, `id/version/csrf` | Admin + okuma + yazma + mevcut silme yetkisi + CSRF + depo + sürüm kontrollü silme |
| Liste POST `action=install_schema`, `csrf` | Mevcut açık yönetici kurulum yolu korunur |

Eski POST isteğinde payload anahtarının varlığı yönlendirme için yeterlidir;
bozuk veya dizi tipindeki payload da form doğrulamasına ulaşır. POST'u yeni
adrese HTTP redirect ile taşıyıp gövdeyi kaybetmek yoktur. Yalnız kabul edilen
id/copy parametreleri yeni GET adresine taşınır; serbest yönlendirme yoktur.
GET silme veya kurulum çalıştırmaz. Tanınmayan POST eylemi kayda yazılmaz.

### Hata davranışı ve taslak koruması

- Geçersiz id biçimi 400; ulaşılamayan kayıt form GET'inde 404 verir.
- Admin/okuma/yazma/silme yetkisi veya CSRF reddi 403 verir. Depo dışında
  kayıtta veri sızdırmadan 403/404 uygulanır; liste zaten kapsam dışını içermez.
- Bozuk payload, geçersiz sayı, sıfır Ürün KG ve geçersiz sürüm girdisi 422
  verir. Kullanıcının yazdığı fiyat/miktar metni formda korunur.
- Güncelleme veya silmede eski sürüm 409 verir. Başka oturumun kaydı ezilmez.
  Güncelleme çakışmasında eski davranış gibi gönderilmiş form taslağı görünür
  kalır. Silme çakışmasında güncel liste tekrar gösterilir ve silme yapılmaz.
- Kayıt hizmeti/JSON okuma sorunu 503 verir; başarı gibi sunulmaz.

Silme düğmesi ürünün hangi kayıt olduğunu ve **kalıcı olarak silinecek**
olduğunu belirten yerel tarayıcı onayı ister.
Form POST + CSRF + id + version taşır. Bu istemci onayı, sunucu yetki/sürüm
kontrollerinin yerine geçmez. Eski gelişmiş maliyet silme yolu kullanılmaz.

## Aşama 2 — form arayüzü

1. Maliyet kartlarındaki A/B/D rozetleri ve özetin harf önekleri kaldırılır.
   On maliyet kalemi, adlandırılmış grup toplamları, genel toplam,
   net kg maliyeti ve Ürün KG özeti korunur.
2. Üretim/Fason düğmeleri ve işlem türü açıklamaları formdan ve listeden
   kaldırılır. Görünmez alanların mevcut kanonik değerleri saklanır.
3. Düzenlenebilir ondalık girişe odaklanıldığında yalnız geçerli ve tam sıfır
   olan görünen metin temizlenir. Bu odaklanma tek başına `input` olayı,
   basis değişimi, dirty işareti veya hesap değişimi üretmez.
4. Odaktan çıkışta boş bırakılan alan `0` görünümüne döner. Geçerli baştaki
   sıfırlar Türkçe sayı ayracıyla güvenli normalize edilir. `0,25` gibi sıfır
   olmayan değer korunur; bozuk/negatif metin değiştirilmez. Salt okunur
   eşdeğer fiyat, disabled alan ve sabit miktar 1 bu davranıştan etkilenmez.
   Kullanıcının gerçek miktar düzenlemesinin bağımsız satıra geçirme davranışı
   korunur; yalnız odak aç/kapat bu dönüşümü yapmaz.
5. Kartın mevcut açıklama alanı hesabı rakamları tekrarlamadan yazıyla açıklar:

   - `Ürün KG × fiyat = toplam maliyet`
   - `Palet adedi × palet fiyatı = toplam maliyet`
   - `Sabit tutar = toplam maliyet`

   Açıklama baz/birime göre değişir; aynı küçük yazı stili korunur.
6. On sabit kalem korunarak en fazla kırk manuel kalem eklenebilir.
   Küçük **Yeni kalem ekle** düğmesi ad/birim/miktar/fiyat kartı oluşturur.
   Yalnız ek kartlar kaldırılabilir. Sunucu ekleri D grubuna atar ve aynı
   kesin kuruş hesabıyla bir kez toplar; kararlı custom_ kimliği JSON'da saklanır.
7. Ürün, not ve ek kalem adları Türkçe büyük harfe gerçek değer olarak
   dönüştürülür; sunucu normalize eder. Eski kayıtlar topluca değiştirilmez.

Ayar panelleri bağımsız açılıp kapanmaya devam eder. İki fiyat kutusu tek
kanonik satırı besler; tutarlar bir kez toplanır. Açık panel durumu, imleç,
orijinal miktar/fiyat ve hatalı taslakların korunması devam eder.

## Dosya sahipliği

Kodlama yalnız plan değerlendirilip görevlendirildikten sonra başlar. En fazla
iki uygulayıcı kullanılır; aynı dosyada paralel yazıcı olmaz.

| Sorumlu | Dosyalar |
| --- | --- |
| Veri/controller uygulayıcısı | `config/quick_cost_store.php`, `hizli_maliyet.php`, yeni `hizli_maliyet_form.php`, `scripts/quick_cost_smoke.php`, `scripts/quick_cost_page_smoke.php` |
| Arayüz uygulayıcısı | `_hizli_maliyet_form.php`, `assets/hizli-maliyet.js`, `assets/hizli-maliyet.css`, `scripts/quick_cost_ui_smoke.js`, `scripts/quick_cost_browser_smoke.js` |
| Ana ajan | `config/helpers.php` gezinme/APP_SURUM, `sw.js`, ilgili sidebar testi, `CLAUDE.md`, `docs/HIZLI_MALIYET.md`, devir dosyaları ve son teslim |

Ana ajan yeni form adresini masaüstü Hızlı Maliyet aktifliğine ekler. Mobil
Diğer menüsüne yalnız admin için ayrı Hızlı Maliyet kaydı eklenir; sabitlenebilir,
mevcut kullanıcı sıralaması korunur. Liste ve form mobilde qcost aktifliğini
paylaşır; eski maliyet sayfaları rapor ailesinde kalır. Hem liste hem form HTML'i, PATH_INFO dahil, Service
Worker iş önbelleğine girmez. Sürüm hedefi v460'tır; commit öncesi uzak ana dal
yeniden kontrol edilir, daha yeni sürüm varsa çakışmayan sonraki sayı seçilir.
Sidebar aktifliği tam olarak `hizli_maliyet.php` ve `hizli_maliyet_form.php`
sayfalarını kapsar; ilgisiz sayfa önekleriyle eşleşmez. SW'nin yeni form
adresinde hem normal yol hem `hizli_maliyet_form.php/extra` davranışı sınanır.

`config/quick_cost.php` ek kalem ve büyük harf doğrulaması için veri uygulayıcısına
verilir. `config/quick_cost_schema.php` ve SQL şeması değiştirilmez. Yeni kolon, migration, genel veri temizliği veya eski maliyet
modülünde yeniden yazım yoktur.

## Aşama 3 — test sırası ve bağımsız inceleme

Fonksiyonel pencereler ana ajan tarafından sırayla verilir; aynı anda servis
ve tarayıcı/test çalıştırılmaz. Sıra:

1. İki uygulayıcı yalnız kodlama ve sözdizimi kontrolü yapar.
2. Farklı Codex ajanı kaynak farklarını ve kritik davranışları bağımsız
   inceler. Bulgular dosya sahibi tarafından düzeltilir; yalnız değişiklikten
   etkilenen testler yeniden koşulur.
3. Ana ajan kullanıcı talebi doğrultusunda tüm fonksiyonel kabul testlerini ve bütünleşik
   dosya/sürüm kontrollerini kendisi çalıştırır. Başarısızlık veya eksik
   kabul tamamlanmış gibi raporlanmaz.

### Kabul senaryoları

- Ana adres yalnız kayıt listesi; yeni hesap düğmesi ayrı formu açar.
- Formdan oluşturma/düzenleme/kopyalama, listeye dönüş ve aktif depo kapsamı.
- Eski GET adreslerinde doğrulanmış yönlendirme; eski POST'ta aynı gövdeyle
  kaydetme ve 422/409 sırasında ham taslak koruması.
- Eski `operation_type` ve `method` değerlerinin açma/kaydetme/kopyalama
  boyunca korunması; arayüzde işlem seçimi ve A/B/D harfleri olmaması;
  on kalem ve adlandırılmış grup toplamlarının korunması.
- Sıfır odak davranışında tutar/basis/dirty değişmemesi; boş blur sıfırı,
  baştaki sıfırlar, `0,25`, bozuk metin, salt okunur alan ve sabit miktar 1.
- Kg/Ton/Adet/Koli-kasa/Palet/Araç/Sabit giderde doğru sözel formül; manuel
  miktarın Ürün KG ve Ayar aç/kapat işlemlerinden etkilenmemesi.
- Silmede GET etkisizliği, yanlış CSRF, admin olmayan kullanıcı, okuma/yazma/silme
  yetkisiz kullanıcı, başka depo, eski sürüm, bulunmayan kayıt ve açık
  transaction reddi. Başarıda yalnız hedef hızlı kayıt silinir, audit özeti
  oluşur. DB hatasında işlem geri alınır. Bozuk JSON kaydı kontrollü silinebilir.
- Eski `cost_sheets` ve yükleme tablolarının anonim işaret kayıtları değişmez.
- 20.000 kg / 670.000 TL / 33,50 TL/kg örneği, büyük ölçekte kuruş hassasiyeti,
  fiyat alias/imleç koruması, kapalı hatalı Ayar alanı ve readonly görünüm.
- 320/390/412/1440 piksel, tema, yatay taşma, dokunma alanları ve alt çubuk
  üzerindeki kaydet alanı; yeni liste/form gezinmesi ve SW bypass kapsamı.
- Ek kalem oluşturma/kaldırma/kaydetme/yeniden açma, 40 sınırı, eksik sabit
  kalem/tekrarlı kimlik reddi; Türkçe büyük harf ve genişleyen harf uzunluğu.
- Mobil Diğer bağlantısı ve sabitlenmiş qcost her iki sayfada aktif;
  admin olmayan sahte qcost çerezine rağmen bağlantıyı göremez.

Controller test harness'i hem listeyi hem formu izole çalıştıracak şekilde
güncellenir. Eski POST'un yeni form controller include'u testte de yakalanır;
test asla gerçek bootstrap veya DB config dosyasına düşmez. `--render legacy`
ve `--render readonly` fixture sözleşmeleri yeni formu render ederek korunur.

## Veri güvenliği ve teslim

Orijinal/gerçek kayıtlar, private local-data, kaynak/referans FDB ve mevcut iş
veritabanı test girdisi değildir. Gizli dosya ve uygulama bootstrap'ı açılmaz;
FDB'ye motor bağlantısı kurulmaz. Testler yalnız anonim bellek içi SQLite ve
anonim yerel tarayıcı fixture'ları kullanır. Gerçek satırlar, görüntüler veya
kimlik bilgileri prompt/repo/fixture/dış servis çıktısına taşınmaz.

Bu çalışma sırasında gerçek kayıt silme, deneme kaydı yazma veya migration
yapılmaz. Kullanıcının uygulamada ileride kullanacağı silme özelliğinin
geliştirilmesi, ajanın mevcut kayıtları silmesi anlamına gelmez.

Son test ve bağımsız inceleme sonrasında ana ajan yalnız ilgili dosyaları
commit eder, PR oluşturur ve kullanıcının geçerli yayın talimatı kapsamında
merge/yayın öncesi somut sonucu sunup AGENTS.md gereği son açık onayı alır. Ana dal yerelde doğrudan düzenlenmez. Yayın sonrası
ajan kabulü yalnız salt okunur sürüm/HTTP/görünüm kontrolleridir; canlıda
hesap oluşturma, güncelleme, silme veya tablo kurulumu testi yapılmaz.
Son rapor PR/commit, geçen testler, canlı doğrulamanın gerçek kapsamı ve kalan
sınırları ayrı ve açık biçimde belirtir; devir kayıtları güncellenir.
