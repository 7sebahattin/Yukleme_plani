# Hızlı Maliyet v2 — Ham Mal, Randıman ve İhracat Maliyeti (v472 → v473)

Yeni hesaplar `hizli_maliyet_form.php`'de **v2 modeliyle** açılır. Her maliyet kalemi **kendi
hesaplama tabanıyla** çarpılır; bütün giderler tek bir "Ürün KG" ile çarpılmaz.
Amaç ERP değil: ham mal alımı ve ihracat teklifinde hızlı, anlaşılır fiyat hesabı.

## Dosyalar

| Dosya | Görev |
|---|---|
| `config/quick_cost_v2.php` | Saf motor (tek otorite), katalog, birimler, taslak kurtarma |
| `config/quick_cost.php` | `quick_cost_calculate()` dağıtıcı: `model === 2` → v2, aksi v1 (değişmedi) |
| `_hizli_maliyet_form_v2.php` | v2 formu (yalnız çizim) |
| `assets/hizli-maliyet-v2.js` | PHP motorunun **birebir aynası** + ekran davranışı (yalnız önizleme) |
| `assets/hizli-maliyet.css` | Sonundaki "Hızlı Maliyet v2" bloğu (`.qc2` kapsamı) |
| `hizli_maliyet_form.php` | Yeni kayıt / v2 kayıt → v2 formu; eski kayıt → v1 formu |

**PHP ve JS motorunu birlikte değiştir.** `scripts/quick_cost_v2_parity_smoke.js` iki motoru 172
senaryoda (zorunlu örnekler + tohumlu rastgele) alan alan karşılaştırır.

## Miktarlar ve randıman

| Alan | Anlamı |
|---|---|
| Ham mal KG | Satın alınan, kasa/palet darası hariç ayıklanmamış ürün |
| İşlenen KG | Ayıklanan ham ürün. **Boş = ham mal KG** (varsayım, ekranda uyarı olarak yazılır) |
| İhracatlık net KG | Paketlenen ihracat ürünü (kayıtta `net_kg` kolonu) |
| İç piyasa / sanayi / fire KG | Detaylı Hesapta dağılım |
| Sınıflandırılmamış KG | Ham − (ihracat + iç piyasa + sanayi + fire); otomatik hesaplanır |

- Randıman = ihracat ÷ ham × 100 (iki ondalık, yarım yukarı).
- Ters hesap (Hedef ihracat + randıman): gerekli ham KG = hedef ÷ (randıman ÷ 100).
  Randıman en fazla iki ondalık, 0 < r ≤ 100. Varsayılan randıman **yoktur** (sektör değeri atanmaz).
- Yöntem değişince diğer yöntemin girdisi o anki sonuçtan doldurulur (döngü yok; alanlar birbirini sürekli değiştirmez).
- Hata (kayıt engeli): ihracat > ham, işlenen > ham, ihracat > işlenen, dağılım toplamı > ham.

## Kalem tabanları

Her kalem `basis` + `unit` taşır:

- `raw_kg` / `processed_kg` / `export_kg` — miktar ilgili KG'ye bağlı; birim `kg` (TL/kg) ya da `ton`
  (TL/ton; tutar = KG × fiyat ÷ 1.000, kesin kuruş).
- `manual` — Kg, ton, koli/kasa, palet, araç, adet, çalışılan gün (× yevmiye), **işçi × gün** (× yevmiye),
  çalışma saati, palet × gün, ton × gün. İki çarpanlı miktar dört ondalığa yarım yukarı yuvarlanır.
- `fixed` — sabit toplam TL.

Varsayılanlar (v473): Ham mal alış = ham KG; hasat = ham KG; ön nakliye sabit; paketleme + malzeme + işletme gideri =
ihracat KG; fabrika gideri, ihracat nakliyesi, gümrük, analizler, DTS taksi, CASUS sabit. Malzeme tek ana satırdır.

**Taban/birim değişince eski miktar taşınmaz** (sıfırlanır); birim değiştiyse birim fiyat da sıfırlanır
(2 TL/kg asla 2 TL/ton ya da 2 TL/palet olmaz). Satırda not çıkar.

İşçilik: her satır tek yöntem taşır, bu yüzden aynı işçilik iki yöntemle mükerrer hesaplanamaz.

## Fason / Üretim (v473)

Fason/Üretim yalnız kaydın **etiketidir**; hesabı değiştirmez. v472'deki "fason hizmet kapsamı" (işçilik/ambalaj
dahil seçimi) ve "Fason işleme hizmeti" kalemi kaldırıldı. Fason bedeli girmek isterseniz **Fabrika gideri** ya da
**Yeni kalem** kullanılır.

## Kalemler (v473)

Ham mal alış · Hasat · Tarladan fabrikaya nakliye · **Paketleme işçiliği** (ayıklama + boylama + paketleme aynı iş:
tek satır, varsayılan taban ihracat KG; işçilik işlenen KG üzerinden ödeniyorsa Ayar'dan "İşlenen ham KG" seçilir) ·
**Malzeme** · **Fabrika gideri** (sabit TL; eski yükleme / soğuk hava / fason hizmet yerine) · İhracat nakliyesi ·
Gümrükleme · Resmi/Özel analiz · DTS taksi · İşletme gideri · CASUS. İşletme gideri ile Fabrika gideri aynı gideri
kapsıyorsa birini 0 bırakın (mükerrer sayılmasın).

## Malzeme palet detayı (v473)

Malzeme satırında Ayar'ın altındaki **Palet detayı** düğmesi paneli açar: satılan palet sayısı + her malzeme için
"palet başına adet" ve "birim fiyat". Satır tutarı = adet × fiyat (kuruşa yuvarlanır); **palet başı maliyet** = satırların
toplamı; **Malzeme tutarı = palet sayısı × palet başı maliyet**. Malzemeler: palet, şapka, köşebent, kasa, viyol,
kenar kartonu, taban kağıdı, şerit, file + "Malzeme ekle" ile en fazla 20 ek satır. "Bu detaydan hesapla" açıkken ana
fiyat kutusu kilitlenir (aynı gider iki yoldan sayılmaz); kapatınca satır fiyatı geçerli olur. İlk anlamlı girişte kutu
kendiliğinden açılır. Palet başı maliyet 1.000.000 TL'yi aşamaz.

## Çıkma / Suluk / Çöp

Ürün dağılımı alanları: **Çıkma KG** (eski iç piyasa), **Suluk KG** (eski sanayi/meyve suyu), **Çöp KG** (eski fire).
Çıkma + Suluk + Çöp + İhracat ham mal KG'yi aşamaz; kalan "sınıflandırılmamış" görünür. Çöp maliyeti ayrıca eklenmez:
ham mal zaten tüm KG üzerinden alındığı için çöpün maliyeti toplam içindedir.

## Sonuçlar ve yan ürün kredisi

- Toplam operasyon maliyeti (kayıtta `total`), ihracat net KG, randıman, ihracat net KG maliyeti (`unit_cost`).
- Varsayılan **ihtiyatlı**: tüm maliyet ihracat KG'ye yüklenir.
- Detaylı Hesapta iç piyasa / sanayi **gerçekleşmiş** satılan KG ve net TL/kg girilebilir. Gelir yalnız
  "maliyetten düş" işaretlenirse düşülür → "İhracata yüklenen tahmini maliyet". Ekranda **yönetimsel tahmin,
  resmi muhasebe maliyeti değildir** yazar. Satılan KG ayrılan KG'yi, kredi toplam maliyeti aşamaz.
  Satılmamış ürün asla gelir sayılmaz; iç piyasa geliri satış karşılaştırmasına ikinci kez eklenmez.

## Satış karşılaştırması (isteğe bağlı)

Fiyat > 0 ise hesaplanır; boşken kayıt yine yapılır. TL / USD / EUR, kur elle (0 < kur ≤ 1.000; TL'de 1).
Satış TL = ihracat KG × fiyat (döviz kuruşuna yuvarlanır) × kur. Kâr = satış − ihracata yüklenen maliyet.
Marj = kâr ÷ satış (markup değil). Başabaş = maliyet ÷ ihracat KG. Hedef marjlı fiyat = başabaş ÷ (1 − marj),
marj 0 ≤ m < 100. Otomatik kur yok.

## Hızlı / Detaylı

Aynı belge, yalnız görünüm (`method` kolonunda saklanır). Hızlı: ürün, ham KG, ihracat KG/randıman,
ham alış, paketleme, malzeme, fabrika gideri, ön nakliye, ihracat nakliyesi, gümrük,
analizler, işletme gideri. **Tutarı sıfırdan farklı her kalem Hızlı'da da görünür ve her zaman hesaba girer.**
Geçişte veri silinmez. Salt okunur kullanıcı da görünüm değiştirebilir.

## Eski kayıtlar

- **v472 v2 kayıtları (v473 uyumu):** kaldırılan kalemler (ayıklama, boylama, yükleme, soğuk hava, fason hizmet) tutarı varsa
  "(ESKİ)" ek kalemi olarak korunur, kayıtlı toplam değişmez; sıfırsa düşer. Eski "fason dahil" seçimiyle toplamdan
  çıkarılmış satırlar çıkarılmış kalır.

- `model` taşımayan kayıt **v1 olarak** açılır, v1 ekranı ve v1 motoruyla hesaplanır — sonuçları değişmez.
- Düzenle/Kopyala eski kaydı v1'de tutar; v2 gövdesi eski kayda gönderilirse 422 (sessiz dönüşüm yok),
  v1 gövdesi v2 kayda gönderilirse 422.
- Yeni kayıtta model gövdeden gelir: dağıtımdan önce açılmış eski sekmenin v1 gövdesi v1 olarak kaydedilir.
- Eski kaydın ham KG'si bilinmediği için uydurulmaz; yeni modelle hesaplamak isteyen yeni hesap açar.

## Saklama — migration YOK

Mevcut `quick_cost_sheets` aynen kullanılır; v2 alanları `document_json` içinde (`model: 2`).
Kolon anlamları v2'de: `net_kg` = ihracatlık net KG, `total` = toplam operasyon maliyeti,
`unit_cost` = ihracata yüklenen maliyet ÷ ihracat KG, `method` = görünüm. Yetki, CSRF, depo kapısı,
sürüm çakışması ve audit değişmedi.

## Testler

```
php scripts/quick_cost_v2_smoke.php          # motor + depo + MySQL şema uyumu (SQLite, 25 test)
node scripts/quick_cost_v2_parity_smoke.js   # PHP ↔ JS 172 senaryo
php scripts/quick_cost_page_smoke.php        # controller (v1 + v2, 102 kontrol)
node scripts/quick_cost_v2_browser_smoke.js  # Playwright 320/390/800/1280 px (171 kontrol)
php scripts/quick_cost_smoke.php && node scripts/quick_cost_ui_smoke.js && node scripts/quick_cost_browser_smoke.js   # v1 regresyon
```

Zorunlu örnek: ham 25.000 / işlenen 25.000 / ihracat 20.000 / iç piyasa 3.000 / fire 2.000 KG;
ham 25.000 × 20 = 500.000, paketleme 20.000 × 2,50 = 50.000, malzeme 20.000 × 4 = 80.000, ön nakliye 15.000,
navlun 60.000, gümrük 5.000, analiz 3.000 → **713.000 TL, %80, 35,65 TL/kg**. İç piyasa 3.000 × 8 = 24.000
kredi açıkça seçilince **689.000 TL, 34,45 TL/kg**.

## Bilinen sınırlar

- Ticari varsayımlar: işlenen KG boşsa = ham KG; ek kalemler "Genel ve diğer" grubuna girer.
- Ortak ürün maliyet dağıtımı (satış değerine göre) yapılmaz; yalnız gerçekleşmiş net gelir kredisi.
- Fiziksel iPhone/Android cihaz testi yapılmadı (headless Chromium ölçümü).
