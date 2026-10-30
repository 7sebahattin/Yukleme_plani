# Hızlı Maliyet v460 — kayıt akışı ve manuel kalemler

31 Ekim 2026. Ayrı dal: `codex/hizli-maliyet-records-flow`.
Temel: `713e54c` / main v459. Durum: yerel geliştirme tamamlandı;
ana ajanın bütün yerel kabul kontrolleri geçti. Push/PR/merge/yayın henüz yapılmadı.

## Sonuç

- Ana adres `hizli_maliyet.php` kayıt listesi; **Yeni maliyet hesapla** ayrı
  `hizli_maliyet_form.php` formunu açar. Düzenleme/kopyalama formda yapılır.
- On maliyet kalemi ve adlandırılmış grup ara toplamları korunur. Yalnız
  A/B/D harfleri/rozetleri ve işlem türü seçimi kaldırılır. Eski işlem/yöntem
  metadatası düzenlemede ve kopyalamada sunucu tarafından korunur.
- Sıfır odakta temizlenir; boş blur sıfıra döner. Odak tek başına bağlı miktarı
  bağımsız yapmaz. Geçerli baştaki sıfırlar güvenli normalize edilir.
- Satır açıklaması rakamları yinelemez: **Ürün KG × fiyat = toplam maliyet**,
  **Palet adedi × palet fiyatı = toplam maliyet**, **Sabit tutar = toplam maliyet**.
- **Yeni kalem ekle** ile kırk ek kart eklenebilir. Ad/birim/miktar/fiyat ve
  kararlı kimlik JSON'da korunur. Yalnız ek kartlar kaldırılabilir.
- Ürün/not/ek kalem adı Türkçe büyük harfe istemcide ve sunucuda dönüşür.
  IME/imleç korunur; dönüşüm sonrası uzunluk kontrol edilir.
- Kayıt silme ayrı POST/CSRF + admin/okuma/yazma/silme + depo + sürüm
  koşulundadır. Seçilen #id/ürün ve kalıcılığı onayda gösterilir. Geri alma yoktur.
- Mobil **Diğer → Hızlı Maliyet** yalnız admin görünür; **Sabitle** ile alt
  çubuğa alınabilir. Liste/form aynı öğeyi etkinleştirir; eski maliyet gezinmesi korunur.

## Veri ve SQL

Aynı izole `quick_cost_sheets` tablosu ve `document_json` kullanılır.
Bu sürüm için SQL/migration gerekmez. On sabit kalem zorunlu, en fazla kırk
custom kalem aynı kesin kuruş motoruna katılır. Ekler sunucuda Diğer grubuna atanır.
Gelişmiş `cost_sheets` ve maliyet motoru/arayüz dosyaları değiştirilmedi.
Canlı/asıl kayıt üzerinde oluşturma/güncelleme/silme veya migration yapılmadı.

## Değişen dosyalar

`hizli_maliyet.php`, yeni `hizli_maliyet_form.php`, `_hizli_maliyet_form.php`,
`config/quick_cost.php`, `config/quick_cost_store.php`, `config/helpers.php`,
`assets/hizli-maliyet.js`, `assets/hizli-maliyet.css`, `sw.js`,
`scripts/quick_cost_smoke.php`, `scripts/quick_cost_page_smoke.php`,
`scripts/quick_cost_sidebar_smoke.php`, `scripts/quick_cost_ui_smoke.js`,
`scripts/quick_cost_browser_smoke.js`, `scripts/bottomnav_render.php`,
`scripts/bottomnav_smoke.js`, `CLAUDE.md`, `docs/HIZLI_MALIYET.md`,
`docs/HIZLI_MALIYET_KAYIT_AKISI_PLAN.md` ve bu teslim belgesi.

## Ana ajanın çalıştırdığı kontroller

| Takım | Sonuç |
| --- | --- |
| Hesap/veri katmanı, anonim SQLite | 47 geçti |
| Gerçek controller / yetki / eski GET-POST / kayıt akışı | 76 geçti |
| Saf JavaScript modeli | 28 geçti |
| Sidebar + mobil kayıt/yetki/aktiflik | 31 geçti |
| Headless Edge form / liste / SW | 400 kontrol geçti |
| Tam mobil menü matrisi, 75 anonim sayfa | 1.900 değişmez + 1.063 davranış kontrolü geçti |
| Değişen PHP/JS sözdizimi ve diff whitespace | temiz |

Form matrisi320/390/412/1440px, açık/koyu tema; tarayıcı hatası yok.
Zorunlu20.000kg örneği670.000TL ve33,50TL/kg olarak doğrulandı.
Özellik proof: `C:/Users/AsyaFresh/AppData/Local/Temp/quick-cost-qa-ZdQM2V/proof.json`.
Mobil menü log: `C:/Users/AsyaFresh/AppData/Local/Temp/quick-cost-mobile-96da616880514d3db184b5e25e304790/final-run.log`.
İlk controller koşusunda eski Raporlar aktiflik beklentisi yeni qcost ile güncellendi.
İlk tam menü koşusunda meşru ../index.php yolunu yasak ../index anahtarı sanan
substring kontrolü düzeltildi; gerçek data-nav anahtarları tam eşleşmeyle kontrol edilir.

Bağımsız Codex statik incelemesinde engelleyici bulgu yok; testleri ana ajan çalıştırdı.
MySQL/InnoDB, fiziksel iPhone/Safari/Android ve canlı son sürüm kabulü yapılmadı.
Mevcut audit helper best-effort; audit/data atomikliği garanti edilmez.
Genel CI tümü yeşil iddiası yok; önceki teslimde belgelenmiş iki Sipariş test
beklentisi bu kapsamda değiştirilmedi. SQL, mevcut maliyet ve gerçek kayıtlar korunur.

## Yayın sınırı

AGENTS.md somut sonuç sonrası son açık onay ister. Yerel sonuç ve commit
incelemeye hazırlandıktan sonra push/PR/merge/yayın için onay alınır. Main merge
her iki siteye otomatik yayın yaptığından bu kapı atlanmaz. Bu belge offline
sonucu canlı kabul olarak sunmaz. Atlas otomasyonu PAUSED ve ayrı Atlas veri
aktarımı onayı değişmez.

Yerel görsel kabul: ana ajan ek kalem kartını, 390px Diğer menüsünü ve320px
qcost sabitlenmiş alt çubuğu inceledi; gerçek iş verisi kullanılmadı.
