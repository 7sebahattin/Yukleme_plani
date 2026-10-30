# Hızlı Maliyet

Adres: `hizli_maliyet.php`. Mevcut **Maliyet → Hızlı Maliyet** bağlantısından açılır.
Bu ekran doğrudan satılacak net kg üzerinden çalışır. Eski `maliyet_form.php`,
`cost_sheets`, formül motoru ve yükleme bağlantısı değiştirilmez.

## Kullanım

1. Ürün, satılacak net kilogram ve işlem tarihini girin.
2. Fason veya Üretim seçin. Seçim kaydedilir; ilk sürümde matematik aynıdır.
3. Hızlı görünümde ortalama TL/net kg fiyatlarını ve sabit giderleri girin.
4. İhtiyaç varsa Detaylı görünümde aynı satırların birim, miktar ve fiyatını değiştirin.
5. Kaydedilen hesabı aşağıdaki aktif depo kapsamlı kayıt listesinden açın.
   Aynı sayfada düzenleyebilir veya **Kopyalayarak yeni hesap** seçebilirsiniz.

Ürün A, tek Malzeme satırı B ve diğer sekiz gider D grubudur. Ambalaj alt
kalemleri, ham kg, randıman, fire, stok, KDV, cari, satış ve kâr bu ekranda yoktur.

## Tek hesap modeli

Hızlı/Detaylı seçimi yalnız görünümü değiştirir. Kaydedilen on satır her zaman
bir kez toplanır. `basis=net_kg` satırlarının miktarı net kg ile değişir;
`manual` satırlar kendi miktarını korur; `fixed` satırlarda miktar birdir.
Detaylı görünümde miktar veya birim düzenlemek satırı bağımsız yapar.
Kg birimindeki bağımsız satır **Net kg kullan** ile yeniden bağlanabilir.

Hızlı görünümde bağımsız satırın **eşdeğer TL/net kg** değeri salt okunur;
orijinal miktar/fiyat korunur, düzenleme Detaylı görünümde yapılır.
Net kg sıfırken bölme yapılmaz; önizleme sıfır birim maliyet gösterir ve kayıt
sunucuda reddedilir. Metin girişinde Türkçe binlik nokta ve ondalık virgül
kullanılır: `20.000`, `1,60`. JSON'da sayılar sayısal tipte gönderilir.
Negatif, bozuk, bilimsel gösterim ve dörtten fazla ondalık reddedilir.

Miktar/net kg/birim fiyat üst sınırı 1.000.000; toplam üst sınırı
9.999.999.999,99 TL'dir. Her satır dört ondalıklı miktar/fiyatın kesin çarpımından
yarım yukarı kuralıyla kuruşa yuvarlanır. Toplam, bu kuruşların toplamıdır;
net kg maliyeti altı ondalıkla hesaplanır, ekranda iki ondalık gösterilir.
PHP ve JS aynı sabit ölçekli tamsayı hesabını kullanır. Tarayıcı toplamları
kaydetme otoritesi değildir; PHP her kayıtta tekrar hesaplar.

## Veri ve kurulum

`quick_cost_sheets` izole tablosu kullanılır. Başlık, işlem/yöntem, net kg,
toplamlar, not, depo, oluşturan/güncelleyen kullanıcı, zamanlar ve sürüm
kolonları vardır. `document_json` kanonik on satırı birim/miktar/fiyat/baz
bilgileriyle saklar. Eski maliyet listesine veya satış önerisine katılmaz.

Şema `docs/sql/quick_cost_sheets.sql` dosyasında **hazırlandı, mevcut veya canlı
DB'ye uygulanmadı**. Runtime DDL veya otomatik migration yoktur. Tablo yokken
hesaplama ekranı çalışır ve kaydetme kapalı görünür. Canlı kurulum için son açık
onay sonrası bu tek yeni tablo kurulmalıdır; kullanıcı tablolarına ALTER/DML yoktur.
Yerel testler tamamen anonim bellek içi SQLite kullanır. MySQL/InnoDB kurulumu
ve gerçek cihaz kabulü bu teslimde yapılmış sayılmaz.

Oturum, `maliyet.read` ve `maliyet.write` kapıları, aktif depo, POST CSRF,
sunucu doğrulaması ve mevcut `audit_log_event` kullanılır. Kimlik/depo client'tan
alınmaz. Güncelleme `id + version + aktif depo` koşullu tek işlemde yapılır;
çakışmada 409 döner ve gönderilmiş girişler korunur. Audit helper'ın mevcut
politikası gereği audit yazım hatası loglanabilir; audit hata verirse mutlaka
kaydın da geri alınacağı iddia edilmez.

Yeni sayfa HTML'i Service Worker önbelleğine girmez. JS/CSS kabuğa eklenmiştir;
SW ve APP_SURUM birlikte v456'dır. Yayına almadan önce uzak ana daldaki sürüm
yeniden kontrol edilmelidir.

## Doğrulama

```powershell
php scripts/quick_cost_smoke.php
node scripts/quick_cost_ui_smoke.js
php scripts/quick_cost_page_smoke.php
$env:PW_PATH = '<kurulu playwright veya playwright-core paket yolu>'
$env:QUICK_COST_BROWSER_CHANNEL = 'msedge' # gerekirse kurulu yerel tarayıcı
node scripts/quick_cost_browser_smoke.js
```

Tarayıcı testi uygulama/bootstrap veya DB servisi başlatmaz. Gerçek controller
ve formdan anonim statik fixture üretir; yerel headless tarayıcıyla 320, 390,
412 ve 1440 piksel genişlikleri, iki tema, görünüm geçişleri, manuel miktar,
payload, geçersiz giriş, taşma ve alt gezinme üstündeki kaydet alanını kontrol
eder. PNG ve `proof.json` yolları test çıktısındadır. Bu, fiziksel iPhone/Safari
ve Android/Chrome üzerinde kullanıcı kabulünün yerine geçmez.

Zorunlu örnek: 20.000 net kg; ürün25, malzeme4, nakliye1,60, işçilik2,
işletme0,50 TL/kg; gümrük4.500, resmi1.500, özel2.000 TL; diğerleri0.
Beklenen A500.000 + B80.000 + D90.000 = **670.000 TL / 33,50 TL/kg**.
