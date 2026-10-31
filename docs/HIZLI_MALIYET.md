# Hızlı Maliyet

Sürüm v461. `hizli_maliyet.php` aktif depo kapsamındaki kayıt listesidir.
**Yeni maliyet hesapla** düğmesi `hizli_maliyet_form.php` adresini açar.
Sol Operasyon → Hızlı Maliyet ve mobil Diğer → Hızlı Maliyet bağlantıları yalnız yöneticide görünür. Mobil menüde Sabitle ile alt çubuğa alınabilir; kullanıcı sıralaması korunur.
Admin olmayanlar doğrudan sayfa ve veri katmanı erişiminde de reddedilir.
Mevcut gelişmiş maliyet sayfaları ve `cost_sheets` değiştirilmez.

## Kullanım

1. Listeden yeni hesap açın veya kayıt üzerinde Düzenle / Kopyala seçin.
2. Ürün adı, **Ürün KG**, tarih ve isteğe bağlı açıklama girin. Ürün KG,
   satılacak net miktardır; ham ürün, randıman ve stok hareketi yoktur.
3. On maliyet kartındaki fiyatları girin. Mevcut kalemlerin hiçbiri kaldırılmaz.
   Yalnız A/B/D rozetleri ve önekleri görünmez; adlandırılmış grup toplamları kalır.
4. İlgili kartın **Ayar** bölümünden birim, miktar ve özgün fiyatı değiştirin.
   Bağımsız paneller verileri silmez. Ana ve Ayar fiyatı aynı satırı besler.
5. **Yeni kalem ekle** ile en fazla kırk ek gider oluşturun. Adını yazın ve
   Ayar bölümünü kullanın. Yalnız eklenen kalemler kaldırılabilir.
6. Kaydetme sonrası listeye dönülür. Düzenleme ve kopyalama ayrı formda yapılır.
   **Sil** açık tarayıcı onayı ister; seçilen kayıt kalıcı olarak silinir.

Ürün, açıklama ve ek kalem adları Türkçe büyük harfe dönüştürülür; bu yalnız
CSS görünümü değildir. Sunucu da aynı dönüşümü uygular. Eski kayıtlar topluca
veya sayfa açılınca güncellenmez. İşlem türü/yöntem seçimi gösterilmez;
eski `operation_type` ve `method` bilgileri düzenleme/kopyalamada korunur.

Düzenlenebilir sayısal alanda sıfır odakta temizlenir; boş çıkılırsa tekrar0
olur. Odaklanmak tek başına miktar bazını veya hesabı değiştirmez. Geçerli
baştaki sıfırlar odaktan çıkınca normalize edilir. Ondalık virgül kullanılır.

Kartın küçük açıklaması rakamları tekrarlamaz; birime göre yazıyla açıklar:
`Ürün KG × fiyat = toplam maliyet`, `Palet adedi × palet fiyatı = toplam maliyet`
veya `Sabit tutar = toplam maliyet`. Rakamlar giriş ve sonuç kutularında görünür.

Birim seçicilerinin sağında aşağı üçgen bulunur. Hızlı Maliyet ana sayfa
kartından da açılır; ana kart, sidebar ve mobil menü aynı kabartmalı dolar
ikonunu kullanır. Ekleme düğmesi altındaki uzun malzeme açıklaması kaldırılmıştır.

## Hesap modeli

`basis=net_kg` miktarı Ürün KG ile değişir; `manual` kendi miktarını korur;
`fixed` miktarı1 kabul eder. Birim/miktar değiştirmek bağımsız hesaba geçer.
Kg satırı **Ürün KG kullan** ile yeniden bağlanabilir. Manuel satırın ana
kutusundaki eşdeğer TL/kg salt okunurdur; özgün miktar/fiyat Ayar'da korunur.

On sabit kalem zorunludur. Ek satırlar kararlı `custom_` kimliği, doğrulanmış
ad ve aynı birim/miktar/fiyat bilgileriyle saklanır; iç Diğer grubuna katılır.
İstemci grup/tutar bilgisi güvenilir sayılmaz; PHP her kayıtta yeniden hesaplar.
Miktar/fiyat üst sınırı1.000.000, toplam9.999.999.999,99TL. Dört ondalıklı
miktar/fiyat çarpımı kesin kuruşa yarım yukarı yuvarlanır. PHP/JS aynı tamsayı
mantığını kullanır. Net kg0 iken bölme yapılmaz; kayıt reddedilir. Negatif,
bozuk, bilimsel gösterim ve dörtten fazla ondalık kaydedilemez.

## Saklama, güvenlik ve uyumluluk

İzole `quick_cost_sheets` tablosu kullanılır. `document_json`, on sabit ve
varsa ek kalemleri saklar. Başlık/tarih/net kg/sonuç/not/depo/kullanıcı/zaman/sürüm
kolonları korunur. Bu sürüm için SQL işlemi veya migration gerekmez.
Mevcut ilk kurulum yolu yalnız eksik tabloda yönetici POST/CSRF ile çalışır;
şema kaynağı `docs/sql/quick_cost_sheets.sql` dağıtımda bulunmalıdır.

Okuma yönetici+maliyet okuma+aktif depo ister. Kaydetme yazma yetkisi ve CSRF
ister. Silme ayrıca `maliyet.delete` ister. Silme/güncelleme id+sürüm+depo
koşuluyla yapılır; eski sürüm409 verir. Silme yalnız hızlı maliyet tablosunu
hedefler ve hard delete kullanır; geri alma yoktur. Mevcut audit helper hata
loglayabildiğinden audit ile veri yazımının kesin atomik olduğu iddia edilmez.

Eski `hizli_maliyet.php?id=N&copy=1` bağlantıları yeni forma gider. Eski POST
gövdesi yönlendirmeyle kaybolmadan aynı istekte yeni controller'a aktarılır.
Her iki sayfa ve PATH_INFO yolları SW iş önbelleğine girmez; APP_SURUM/SW v461.

## Doğrulama

Uygulama bootstrap'ı veya iş DB'si açılmadan anonim bellek içi SQLite ve
headless Edge fixture'ları kullanılır:

```powershell
php scripts/quick_cost_smoke.php
php scripts/quick_cost_page_smoke.php
php scripts/quick_cost_sidebar_smoke.php
node scripts/quick_cost_ui_smoke.js
$env:PW_PATH = '<kurulu playwright paket yolu>'
$env:QUICK_COST_BROWSER_CHANNEL = 'msedge'
node scripts/quick_cost_browser_smoke.js
```

Zorunlu örnek:20.000kg; ürün25, malzeme4, nakliye1,60, işçilik2,
işletme0,50TL/kg; gümrük4.500, resmi1.500, özel2.000TL; diğer0.
Sonuç ürün500.000+malzeme80.000+diğer90.000=670.000TL /33,50TL/kg.
Ek satır, büyük harf, sıfır odak, sözel formül, kayıt akışı ve silme/yetki
senaryoları ilgili testlerde bulunur. Sonuçlar teslim belgesinde belirtilir.
Yerel320/390/412/1440px testleri fiziksel iPhone/Safari ve Android kabulü değildir.
