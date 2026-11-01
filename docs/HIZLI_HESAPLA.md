# Hızlı Hesapla (v478 → v479)

Tek ekran ihracat maliyeti + satış fiyatı. `hizli_hesapla.php` · motor `config/hizli_hesapla.php` (PHP otorite) ·
`assets/hizli-hesapla.js` (JS ayna, **işlem sırası birebir aynı**) · `assets/hizli-hesapla.css`.

## Formüller (erken yuvarlama YOK)
- Gerekli ham KG = Satış net KG / (1 − Fire/100)  (Ham KG girişi yok; fire ham maliyeti BİR kez etkiler)
- Toplam = ham KG × ham fiyat + net KG × malzeme + net KG × diğer(TL/kg) + nakliye + evrak + diğer sabit
- Net kg maliyeti = Toplam / Satış net KG · Döviz maliyeti = Net kg maliyeti / Kur
- Satış TL = Net KG × fiyat × kur (fiyat döviz işaretli) · Net KG × fiyat (TL/kg)
- Kâr = Satış − Toplam · Marj = Kâr / Satış × 100 (markup DEĞİL)
- Başabaş = Net kg maliyeti / Kur · Önerilen TL/kg = Net kg maliyeti / (1 − Hedef marj/100); döviz = / Kur
- Geçersiz: net KG ≤ 0, kur ≤ 0/boş, fire ≥ %100, hedef marj ≥ %100, negatif/harfli sayı (sessizce 0 yapılmaz).

Zorunlu örnek: 20.000 kg · 1,10 USD · kur 50 · ham 20 · nakliye 15.000 · malzeme 4 · evrak 5.000 · diğer 0,50 · fire %8 →
toplam 544.782,61 TL · 27,2391 TL/kg · 0,5448 USD/kg · satış 1.100.000 · kâr 555.217,39 · marj %50,47 · %20 hedefle 0,6810 USD/kg.

## Kayıt (şema DEĞİŞMEZ, migration YOK)
Mevcut `quick_cost_sheets`: `document_json.model = 3`, `method = 'hizli'`, `product` = hesap adı (büyük harf), `net_kg` = satış net KG,
`total` = toplam maliyet, `unit_cost` = net kg maliyeti. Kayıt `quick_cost_save()` ile yapılır (CSRF, `hizli_maliyet.write`, depo, audit,
sürüm çakışması); sunucu HER kayıtta/açılışta yeniden hesaplar, tarayıcı toplamı kullanılmaz. Listede (`hizli_maliyet.php`) "Hızlı Hesapla"
etiketiyle görünür; v1/v2 formu bu kaydı Hızlı Hesapla ekranına yönlendirir, Hızlı Hesapla v1/v2 kaydını kendi ekranına yönlendirir
(sessiz dönüştürme yok). Döviz kuru elle girilir (API yok); kur dövize özeldir (USD→EUR geçişinde eski kur taşınmaz).

## Paylaş / önbellek
Paylaş = kullanıcı tıklamasıyla `navigator.share`, yoksa panoya kopyala (yoksa elle kopyalama kutusu). Metin ekrandaki biçimli değerlerden
üretilir (`view().share`). SW `hizli_hesapla.php`'yi önbelleğe ALMAZ.

## Test
`php scripts/hizli_hesapla_smoke.php` · `node scripts/hizli_hesapla_js_smoke.js` (300 rastgele PHP/JS parite) ·
`node scripts/hizli_hesapla_browser_smoke.js` (320/375/390/412/1280; ekran görüntüleri `HH_QA_OUT`).

## v479 — İhracat randımanı modeli (model 4)
Fire ≠ ihracat randımanı: ihracata ayrılmayan ürün iç piyasa/sanayiye satılabilir. YENİ hesaplar `model:4`; eski `model:3` (fire) kayıtlar
`hh_calculate_v3` ile kendi matematiğiyle açılır (sessiz dönüşüm YOK; fire % randımana eşitlenmez).

Formüller (`hh_compute_v4` ↔ `compute4`):
- Gerekli ham KG = Satış net KG / (Randıman/100) · Ham mal = ham KG × ham fiyat (TL/ham kg)
- Malzeme = net KG × TL/net kg · Diğer masraf = (Net KG ya da Ham KG, seçime göre) × TL/kg · Sabit = nakliye + evrak + diğer sabit
- Toplam = ham mal + malzeme + diğer KG gideri + sabitler · Net kg maliyeti = Toplam / net KG · Döviz = / kur
- Satış = net KG × fiyatın TL karşılığı · Kâr = Satış − Toplam · Marj = Kâr/Satış·100 · Başabaş = net kg maliyeti/kur ·
  Önerilen TL/kg = net kg maliyeti / (1 − hedef marj/100); döviz = / kur. Randıman 0<y≤100, boşsa hesap tamamlanmaz.

Zorunlu örnek (net 20.000, randıman %80, ham 20, nakliye 15.000, malzeme 4, evrak 5.000, diğer 0,50 NET, 1,10 USD @ 50, hedef %20):
ham 25.000 kg · ham mal 500.000 · toplam **610.000 TL** · 30,5000 TL/kg · 0,6100 USD/kg · satış 1.100.000 · kâr 490.000 · marj %44,55 · önerilen 0,7625 USD/kg.
Diğer masraf 2 TL/kg: Net → 40.000, Ham → 50.000 TL.

Döviz: "Fiyat döviz" işareti değişince fiyat dönüştürülür (1,10 USD ⇄ 55,00 TL/kg; satışın TL değeri korunur, kur yoksa değişiklik geri alınır).
USD⇄EUR: kurlar ayrı saklanır; TL fiyat korunur, yeni döviz kuru girilene dek fiyat beklemede. Satış fiyatı 8 ondalığa kadar (dönüşüm kaybı yok).
Kayıt: `document_json.model=4`, `calc_version=4`, `yield_pct`, `other_kg_basis`. Eski kayıt → "Yeni randıman modeliyle kopyala" (`?id=N&yeni=1`).

## v480 — ekranda Fire
Model 4 ekranı fire girişi ister: ihracata kalan (`yield_pct`) = 100 − fire; formül ve kayıt alanları değişmedi. Örnek: fire %20 → ihracata kalan %80 → ham 25.000 kg → toplam 610.000 TL. Eski model 3 kopyalanırken `yield_pct = 100 − waste_pct`.
