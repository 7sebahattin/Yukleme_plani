# Yükleme Planı

PHP 8 + MySQL ile geliştirilmiş, mobil öncelikli **yükleme planı** uygulaması.
Asya Fresh / Excel "Yükleme Planı" mantığını web tabanına taşır.

## Özellikler

- Genel kayıt + nakliye + yükleme planı (palet) bilgileri
- Palet bazında: kasa adeti, size, brüt kg
- **Otomatik dara hesaplama**: kasa cinsi (× kasa adeti) + palet tipi + ek malzemeler
- **Net = Brüt − Dara** otomatik
- Anlık alt toplamlar (kasa, brüt, dara, net)
- Tanımlar ekranı: kasa cinsi, palet tipi, şapka, köşebent, şerit, casus, kasa etiketi, minti, kenar kartonu, taban kağıdı, şale, viyol, köşe karton, kraft kağıt, file, diğer
- Mobilde kart tasarımı, PC'de Excel benzeri tablo girişi
- Enter / Tab ile hızlı geçiş
- Yazdırılabilir kayıt görünümü (A4 yatay)
- CSRF koruma + prepared statement (SQL injection güvenliği)
- Hesaplamalar hem JS'de anlık hem PHP'de yeniden yapılır

## Kurulum

### 1) Veritabanı
```bash
mysql -u root -p < database.sql
```
veya phpMyAdmin'den `database.sql` dosyasını içe aktarın.

### 2) DB bağlantı bilgileri
`config/db.php` dosyasındaki sabitleri düzenleyin:
```php
const DB_HOST = 'localhost';
const DB_NAME = 'yukleme_plani';
const DB_USER = 'root';
const DB_PASS = '';
```

### 3) Web sunucu
- PHP 8.0+ (PDO_MySQL açık olmalı)
- Apache, Nginx veya `php -S localhost:8000` ile çalıştırın
- Klasörü web kök dizinine kopyalayın → `index.php`'ye gidin

```bash
# Hızlı test
php -S 0.0.0.0:8000
```

## Klasör Yapısı

```
yukleme_plani/
├── config/
│   ├── db.php          # PDO bağlantı + ortak yardımcılar
│   └── calc.php        # Sunucu tarafı dara/net hesaplaması
├── assets/
│   ├── style.css       # Mobil öncelikli stil
│   └── app.js          # Dinamik palet + anlık hesaplama
├── _form.php           # Create/edit ortak form gövdesi
├── index.php           # Kayıt listesi
├── record_create.php   # Yeni kayıt
├── record_edit.php     # Kayıt düzenle
├── record_view.php     # Görüntüle + ?print=1 ile yazdır
├── record_delete.php   # Sil (önce onay)
├── definitions.php     # Tanımlar yönetimi
├── database.sql        # DB şeması + örnek tanımlar
└── README.md
```

## Hesaplama Mantığı

Bir palet satırı için:

```
kasa_dara_total = kasa_adeti × seçilen_kasa_cinsi.unit_dara_kg
palet_dara      = seçilen_palet_tipi.unit_dara_kg
extra_dara      = SUM( malzeme.unit_dara_kg × adet )

dara_kg = kasa_dara_total + palet_dara + extra_dara
net_kg  = max(0, brut_kg − dara_kg)
```

Bu hesap iki yerde yapılır:
- `assets/app.js` → kullanıcı yazdıkça anlık olarak
- `config/calc.php` → form POST edildiğinde sunucuda yeniden (güvenlik)

## Yazdırma / PDF

`Görüntüle` ekranında **Yazdır** butonu yeni sekmede sayfayı açar.
- Tarayıcı yazdırma diyalogundan PDF olarak da kaydedilebilir.
- A4 yatay boyutta, baskı için optimize edilmiştir.

## Güvenlik Notları

- Tüm sorgularda `PDO::prepare` + named/positional parametreler.
- Tüm form gönderimlerinde CSRF token doğrulaması.
- Tüm çıktı `htmlspecialchars` ile kaçışlanır.
- Çoklu kullanıcı veya kimlik doğrulama eklenecekse `definitions.php`,
  `record_*` dosyalarına oturum (login) kontrolü eklenmelidir.

## Bilinen Sınırlar / İleri Geliştirme

- Auth/login yok — yerel ağ veya tek kullanıcı için tasarlandı.
- Excel'e dışa aktarım eklenebilir (PhpSpreadsheet).
- Çok sayıda kayıt için sayfalama eklenebilir (şu an 500 ile sınırlı).
