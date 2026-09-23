# Excel İndirme Fonksiyonları — Sistem İncelemesi

> **Tarih:** 2026-09-23 · **Kapsam:** Sistemdeki tüm "Excel / CSV indir" butonları ve uç noktaları.
> **Amaç:** Mevcut formatları çıkarmak; CSV çıktılarının gerçek Excel'e (XLSX) çevrilip
> çevrilemeyeceğini ve hazır şablonlu Excel butonlarına dokunmadan bunun nasıl yapılacağını
> belirlemek. **Kod değişikliği yok** — bu belge yol haritası için girdi.

> ## ✅ Uygulama durumu — Sprint Excel-01 (v261)
>
> Kullanıcı kararları (§8): **1)** CSV şu an aktarılmıyor ama aktarılabilir → CSV
> **korundu**, bayt bayt aynı · **2)** Büyük raporlarda satır sınırı kabul →
> `XLSX_MAX_HUCRE` (150 bin hücre) · **3)** Yetki ve audit tek kurala bağlansın →
> tüm dışa aktarımlar `reports.export` ister ve audit'e yazar.
>
> | Faz | Durum |
> |---|---|
> | 1 — Ortak yardımcı | ✅ `config/xlsx_export.php` |
> | 2 — Raporlar (C1–C4) | ✅ "⬇ Excel İndir ▾" → CSV / XLSX; günlük rapor bölüm başına ayrı sayfa |
> | 3 — Stok/Kantar (C5–C10) | ✅ Kantar raporuna "Firma Özeti" sayfası eklendi |
> | 4 — Sahte .xls (H1, H2) | ✅ Hesap → gerçek XLSX + CSV (para birimi özeti ayrı sayfa); örnek palet → gerçek XLSX |
> | 5 — PDKS (C13–C16) | ✅ Tutarlar sayı hücresi; para birimi başına ayrı sütun/sayfa |
> | 6 — Audit ekranı (C11–C12) | ✅ Her kontrol ayrı sayfa |
> | 7 — PhpSpreadsheet yükseltmesi | ⏳ Yapılmadı — ayrı iş (şablonlu Excel'i etkiler) |
> | Kesişen — yetki/audit | ✅ Şablonlu yükleme Excel'i (X1) dahil |
>
> §3.3'teki C16 nokta-ondalık sorunu ve C13/C14'teki birleşik para metni **XLSX'te**
> çözüldü; CSV'leri bilinçli olarak aynı bırakıldı (aktarım biçimi değişmesin).
> Uygulama kuralları: `CLAUDE.md` → "Excel İndir — CSV + XLSX".

---

## 1. Kısa Özet

| Soru | Cevap |
|---|---|
| Sistemde kaç indirme noktası var? | **21 buton → 20 farklı çıktı**, 13 uç nokta dosyasında |
| Formatlar neler? | **3 aile:** gerçek XLSX (2 çıktı) · HTML'in `.xls` uzantısıyla gönderilmesi (2 çıktı) · noktalı virgüllü CSV (16 çıktı) |
| CSV → XLSX dönüştürülebilir mi? | **Evet.** Gereken kütüphane (PhpSpreadsheet 2.4.5) `vendor/` içinde zaten var ve canlıda iki uç noktada çalışıyor. Yeni bağımlılık gerekmiyor. |
| Şablonlu Excel butonları bozulur mu? | **Hayır, bozulması için sebep yok.** Şablonlu çıktılar ayrı dosyalarda ve kendi testleri var. Tek ortak risk, PhpSpreadsheet sürüm yükseltmesi; o iş ayrı bir faz olmalı. |
| Asıl fark ne olur? | Sayılar Excel'de **sayı** olarak açılır (toplanabilir), İngilizce dilli Excel'de sütunlar dağılmaz, mobilde önizleme düzgün çalışır, butonun üzerinde "Excel" yazıp CSV inmesi sorunu ortadan kalkar. |

> **Terim notu:** "XML formatı" olarak kastedilen büyük ihtimalle **XLSX**. XLSX, zip
> ile paketlenmiş XML dosyalarından oluşur (Office Open XML) ve Excel'in kendi formatıdır.
> Excel 2003'ün "XML Spreadsheet" formatı da bir seçenek, ama önerilmez: açılışta Excel
> uyarı verir, mobil uygulamalar ve Google Sheets bu formatı açamaz. Bu raporda
> "XLSX" diye geçen her yer bu anlamdadır.

---

## 2. Envanter — Tüm İndirme Noktaları

### 2.1 Gerçek XLSX (PhpSpreadsheet) — ✅ doğru format

| # | Ekran / Buton | Uç nokta | Nasıl üretiliyor | Yetki | Audit | Test |
|---|---|---|---|---|---|---|
| X1 | `record_view.php` → **📊 Excel İndir** | `record_excel_template.php?id=` | **ŞABLONLU**: `templates/excel/yukleme_plani_template.xlsx` açılır, ~58 hücreye veri ve **canlı formül** (`SUMIF`/`COUNTIF`/`ROUND`) yazılır, biçim şablondan gelir | `records.read` | ❌ yok | `scripts/record_excel_smoke.php` (gerçek dosyayı çalıştırır) |
| X2 | `rapor_malzeme.php` → **⬇ Excel İndir (Kullanım + Stok)** | `rapor_malzeme.php?xlsx=1` | **Kodla biçimli**, iki sayfa (Kullanım + Stok Özeti), başlık dolgusu, kenarlık, freeze pane, autofilter, sütun genişliği ayarı, sayı biçimi `#,##0` | `reports.export` (+ 2. sayfa için `stok.read`) | ✅ | `scripts/rapor_malzeme_xlsx_smoke.php` |

### 2.2 HTML tablosu `.xls` uzantısıyla — ⚠️ sahte Excel

| # | Ekran / Buton | Uç nokta | İçerik | Yetki | Audit |
|---|---|---|---|---|---|
| H1 | `hesap_liste.php` + `hesap_muhasebe.php` → **📊 Excel** | `hesap_export.php` | Stilli HTML tablosu (başlık rengi, gelir/gider renkleri, para birimi bazında toplam satırları). `Content-Type: application/vnd.ms-excel` | `hesap.read` + `reports.export` | ✅ |
| H2 | `_form.php` (yükleme formu, Excel Yükle paneli) → **⬇ Örnek İndir** | `excel_ornek_palet.php` | 3 sütunlu örnek palet tablosu (Palet No / Kasa Adeti / Brüt KG). **İçe aktarma şablonu**; kullanıcı doldurup `📥 Excel Yükle` ile geri yüklüyor (tarayıcıda SheetJS okuyor) | `records.write` | — |

**Bu formatın sorunları:**
- Excel açılışta *"Dosya biçimi ile uzantısı eşleşmiyor"* uyarısı verir. Kullanıcı her seferinde "Evet" demek zorunda kalır.
- Tutarlar `number_format(…, 2, ',', '.')` ile **metin** olarak yazılıyor (`1.234,56`). Excel bunları dil ayarına göre yorumluyor, her zaman sayıya çevirmiyor.
- Google Sheets, iOS Numbers ve mobil Excel önizlemesi HTML-`.xls` dosyasını genellikle açamıyor ya da ham HTML olarak gösteriyor.
- H2'de kullanıcı dosyayı Excel'de açıp kaydettiğinde dosya HTML olarak kalabiliyor. İçe aktarmanın çalışması, SheetJS'in HTML okuyabilmesine bağlı.

### 2.3 CSV (`;` ayraçlı, UTF-8 BOM'lu) — ⚠️ en büyük grup

| # | Ekran / Buton (etiket) | Uç nokta | Yapı | Sayı biçimi | Yetki | Audit |
|---|---|---|---|---|---|---|
| C1 | `reports.php` Günlük Rapor → **⬇ Excel/CSV** | `reports.php?type=gunluk&export=csv` | **Çok bölümlü**: Özet + Kantar + Yükleme + Çıkma + Makineye Dökülen (bölüm başlıkları `--- … ---`) | `1234,500` (binlik ayraç yok) | `reports.export` | ✅ |
| C2 | `reports.php` Yükleme/Çıkma → **⬇ Özet Excel** | `…&export=csv_summary` | Tek tablo + TOPLAM satırı | `1234,5` | `reports.export` | ✅ |
| C3 | `reports.php` Yükleme/Çıkma → **⬇ Detay Excel** | `…&export=csv` | Palet bazında 26 sütun, **satır sınırı yok** | `1234,500` | `reports.export` | ✅ |
| C4 | `reports.php` Depo/Ürün/Firma/Malzeme/Kantar → **⬇ Excel/CSV** | `…&export=csv` | Tek tablo + TOPLAM | `1234,5` | `reports.export` | ✅ |
| C5 | `rapor_malzeme.php` → **CSV** | `?csv=1` | Pivot (tarih sütunları), X2'nin CSV karşılığı | tam sayı | `reports.export` | ✅ |
| C6 | `kantar_raporu.php` → **⬇ CSV** | `?csv=1` | Fiş × firma dağılımı, 13 sütun | `1.234,500` | `kantar.read` | ❌ |
| C7 | `stok.php` → **⬇ CSV** | `?csv=1` | Üstte `# meta` satırları (filtre + özet KG), sonra hareket tablosu | `1.234,500` | `stok.read` | ✅ |
| C8 | `stok.php` → **⬇ Kalite Raporu CSV** | `?dkk_csv` | **Çok bölümlü** (`### grup` başlıkları) | `1.234,500` | `stok.read` | ✅ |
| C9 | `malzeme_stok.php` → **⬇ Özet CSV** | `?csv=ozet` | Tek tablo, 8 sütun | `1.234,500` | `stok.read` | ❌ |
| C10 | `malzeme_hareketleri.php` → **⬇ Hareket CSV** | `?csv=1` | Tek tablo, **100.000 satıra kadar** | `1.234,500` | `stok.read` | ❌ |
| C11 | `audit.php` → **⬇ Tüm Raporu CSV İndir** | `?csv=all` | **Çok bölümlü** (`=== başlık ===`) | ham | yalnız admin | ❌ |
| C12 | `audit.php` → bölüm başına **⬇ CSV** | `?csv=<anahtar>` | Tek bölüm | ham | yalnız admin | ❌ |
| C13 | `raporlar.php` (PDKS) → **⬇ Günlük CSV** | `?csv=gunluk` | Günlük trend; finansal sütunlar **"TRY: 1250.00 \| USD: 20.00"** biçiminde tek hücrede | metin | PDKS rapor | ❌ |
| C14 | `raporlar.php` (PDKS) → **⬇ Çavuş CSV** | `?csv=cavus` | Çavuş özeti; finansal sütunlar yine tek hücrede metin | metin | PDKS rapor | ❌ |
| C15 | `gunluk_isci_puantaj.php` → **⬇ CSV** | `?csv=1` | Günlük puantaj, 11 sütun | tam sayı | PDKS günlük | ❌ |
| C16 | `cavus_ekstre.php` → **⬇ CSV** | `?csv=1` | Ekstre, para birimi sütunlu | **`1250.50` (nokta!)** | PDKS cari | ❌ |

> Envanter dışı (indirme değil): `hesap_yazdir.php` (PDF), `admin_db_backup_download.php`
> (SQL yedeği), `hesap_dosya.php` (fiş görseli). İçe aktarma tarafı (`_form.php` Excel Yükle,
> `malzeme_stok_import.php`) yalnız okur, bu incelemenin konusu değil.

---

## 3. Tespit Edilen Sorunlar

### 3.1 Buton etiketi ile inen dosya uyuşmuyor
C1–C4'te buton **"Excel"** diyor, ama inen dosya `.csv`. Kullanıcı Excel bekliyor, eline metin dosyası geçiyor.
Sistemin en çok kullanılan raporları bu dört buton.

### 3.2 CSV'nin yapısal sınırları (dosyanın kendisinden kaynaklanan)
- **Dil ayarına bağlı.** `;` ayracı ve `,` ondalık yalnız **Türkçe bölge ayarlı** Excel'de doğru açılıyor.
  İngilizce ayarlı bir bilgisayarda (ör. yurt dışındaki alıcı, muhasebe yazılımı) tüm satır tek sütuna düşüyor.
- **Sayılar metin.** Tutar ve KG sütunları metin olarak üretiliyor. Excel çoğu durumda bunları sayıya çeviriyor,
  ama çevirmediği durumda `TOPLA` sıfır döndürüyor.
- **Biçim yok.** Başlık rengi, sütun genişliği, sabit başlık satırı, filtre, toplam satırı vurgusu eklenemiyor.
- **Çok bölümlü CSV'ler** (C1, C8, C11) farklı sütun yapısındaki tabloları alt alta diziyor. Filtre ve sıralama
  bu dosyalarda anlamsız hâle geliyor. XLSX'te bu bölümler **ayrı sayfalara** konabilir.
- **Mobil.** iOS'ta CSV düz metin olarak açılıyor, XLSX ise Dosyalar/Quick Look ile tablo olarak görünüyor.

### 3.3 Sayı biçimi tutarsız (4 farklı yazım)
| Yazım | Nerede | Türkçe Excel'deki sonuç |
|---|---|---|
| `1234,500` | reports.php | Sayı ✅ |
| `1.234,500` | stok, malzeme, kantar | Sayı ✅ (binlik ayraç tanınıyor) |
| `1250.50` | **cavus_ekstre (C16)** | ⚠️ **Metin**, hatta **tarih** olabilir: `12.05` gibi bir tutar "12 Mayıs"a dönüşme riski taşıyor |
| `TRY: 1250.00 \| USD: 20.00` | **raporlar.php (C13/C14)** | ❌ Hesaplanamaz metin |

C16 ve C13/C14, format dönüşümünden bağımsız olarak **muhasebe açısından en riskli** çıktılar.
(C16'daki tarih dönüşümü kodun yapısından çıkarılan bir risk; gerçek bir dosyayla açılarak doğrulanmalı.)

### 3.4 Tarih biçimi tutarsız
`2026-09-23` (reports, stok, malzeme) · `23.09.2026 14:30` (kantar) · `d.m.Y` başlıklar (rapor_malzeme).
XLSX'e geçerken hepsi **gerçek tarih hücresi** olarak yazılabilir. Excel böylece tarihleri sıralayabilir ve filtreleyebilir.

### 3.5 Yetki ve audit tutarsızlığı
- `reports.*` altındaki tüm dışa aktarımlar `reports.export` ister ve audit kaydı yazar.
- **C6, C9, C10** ise yalnız okuma yetkisi istiyor (`kantar.read` / `stok.read`) ve audit kaydı yazmıyor.
- **X1** (şablonlu yükleme Excel'i) audit kaydı yazmıyor.
- PDKS çıktıları (C13–C16) ve audit.php (C11/C12) de audit kaydı yazmıyor.

Bu bir güvenlik açığı değil: ekranı gören kullanıcı zaten o veriyi görüyor. Ama politika kararı gerektiriyor
(bkz. §7). XLSX dönüşümü yapılırken aynı dokunuşla eşitlenebilir.

### 3.6 Bağımlılık notu
`docs/HESAP_MODERNIZASYON_PLANI.md` notuna göre `composer audit`, **phpoffice/phpspreadsheet** için
üç yüksek önemli uyarı gösteriyor (CVE-2026-59931/59932/59933). Bu uyarılar, dışa aktarımı genişletmeden önce
değerlendirilmeli. Yükseltme **X1 ve X2'yi doğrudan etkiler** (bkz. §5).
İçe aktarma tarafında `_form.php` ve `malzeme_stok_import.php`, CDN'den SheetJS **0.18.5** yüklüyor.
Bu sürüm bilinen güvenlik açıkları olan eski bir sürüm; bu raporun kapsamı dışında, ayrı bir iş olarak not edildi.

---

## 4. CSV → XLSX Dönüştürülebilir mi?

**Evet. Teknik engel yok.** Gerekçeler:

1. **Kütüphane hazır.** PhpSpreadsheet 2.4.5 `vendor/` içinde commit'li, canlıda X1 ve X2 ile çalışıyor.
   Composer değişikliği ya da yeni paket gerekmiyor.
2. **Emsal var.** `rapor_malzeme.php` tam olarak hedeflenen deseni zaten uyguluyor: aynı veriden
   **birincil "Excel İndir" (XLSX) + ikincil "CSV"** butonu. Biçim yardımcıları (`$baslik_yaz`,
   `$genislik_ayarla`) oradan ortak bir dosyaya taşınıp çoğaltılabilir.
3. **Veri katmanı hazır.** Her CSV bloğu, önce bir dizi satır üretip sonra yazıyor
   (`$rows`, `$entries`, `$csv_rows`…). XLSX yazıcı **aynı diziyi** okuyabilir, yani sorgulara dokunmak gerekmiyor.
4. **Test deseni hazır.** `record_excel_smoke.php` gerçek uç noktayı alt süreçte çalıştırıp çıkan `.xlsx`'i
   geri okuyarak doğruluyor. Yeni çıktılar için de aynı yöntem kullanılabilir.

### 4.1 Dikkat edilmesi gereken tek teknik risk: bellek
PhpSpreadsheet her hücreyi bellekte nesne olarak tutar (kabaca hücre başına ~1 KB). CSV ise satırları akış hâlinde yazar.

| Çıktı | En kötü durum | Tahmini hücre | Durum |
|---|---|---|---|
| C10 Malzeme hareketleri | 100.000 satır × 11 | ~1,1 milyon | ⚠️ Paylaşımlı sunucunun `memory_limit` değerini aşabilir |
| C3 Detay Excel | sınırsız × 26 | veri hacmine bağlı | ⚠️ ölçülmeli |
| Diğerleri | birkaç bin satır | < 100 bin | ✅ sorun beklenmez |

**Çözüm seçenekleri** (Faz 0'da ölçüp karar verilecek):
- (a) Büyük çıktılar için **satır eşiği**. Eşik aşılırsa XLSX yerine CSV verilir ve ekranda bir not gösterilir.
- (b) PhpSpreadsheet hücre önbelleği. Belleği düşürür ama yavaş çalışır.
- (c) Büyük çıktılar için **CSV'yi birincil buton olarak bırakmak**.

Öneri: **(a).** Hem basit hem öngörülebilir.

### 4.2 CSV tamamen kaldırılmalı mı?
**Hayır, önerilmez.** `docs/MALZEME_STOK_UX_ANALIZ.md` (madde 9), CSV biçiminin *"muhasebe tarafında
kullanılıyor olabileceğini, formatın korunması gerektiğini"* not ediyor. CSV başka bir yazılıma aktarılan
tek çıktı olabilir. Güvenli yol: **XLSX'i birincil buton yapmak, CSV'yi ikincil butonda bayt bayt aynı bırakmak**
(`rapor_malzeme.php` emsali). CSV'yi kaldırmak, kullanım doğrulandıktan sonra ayrıca verilecek bir karar olmalı.

---

## 5. Şablonlu Excel Butonları Bozulmadan Yapılabilir mi?

**Evet.** Korunması gereken çıktılar ve koruma yöntemi:

| Korunacak | Neden hassas | Koruma |
|---|---|---|
| **X1** `record_excel_template.php` + `templates/excel/yukleme_plani_template.xlsx` | Hücre adresleri şablona sabit bağlı (A1, D2, H/I/J/K/L satırları…). Formüller canlı. Geçmişte PhpSpreadsheet sürüm farkı canlıda 500 hatası verdi (`getRowDimension` tip hatası) | **Dosyaya ve şablona dokunulmaz.** Ortak yardımcıya taşınmaz. Her fazın sonunda `php scripts/record_excel_smoke.php` çalıştırılır |
| **X2** `rapor_malzeme.php` XLSX bloğu | İki sayfa, yetkiye bağlı ikinci sayfa, filtre uyumu notları | Ortak yardımcı yazılırken **kopyalanır, taşınmaz**. Bu blok son faza kadar değişmez. `php scripts/rapor_malzeme_xlsx_smoke.php` her fazda çalıştırılır |
| **H1** `hesap_export.php` | "Şablon" değil ama stilli (renkler, para birimi bazında toplamlar). Muhasebe bu görünüme alışkın | Dönüşümde **aynı görünüm XLSX'te yeniden kurulur** (renkli başlık, gelir yeşil / gider kırmızı, para birimi başına TOPLAM blokları). Toplamlar kurlar arasında **karıştırılmaz** (CLAUDE.md kuralı) |
| **H2** `excel_ornek_palet.php` | İçe aktarma ile çift yönlü çalışıyor | XLSX'e çevrildikten sonra **`📥 Excel Yükle` ile geri yüklenip** doğrulanmalı (tarayıcı testi) |

**Neden güvenli:** Tüm CSV uç noktaları şablonlu dosyalardan **ayrı dosyalarda ve ayrı kod bloklarında**.
Ortak yardımcı **yeni bir dosya** olarak eklenir. X1 ve X2 onu kullanmaya zorlanmaz.
X1 ve X2'yi etkileyebilecek **tek** değişiklik PhpSpreadsheet sürüm yükseltmesi. Bu yüzden o iş ayrı bir fazda,
iki smoke testiyle birlikte ele alınmalı.

---

## 6. Önerilen Yaklaşım (tasarım taslağı — kod değil)

1. **Ortak yardımcı:** `config/xlsx_export.php` (yeni dosya). Sorumlulukları:
   - başlık satırı stili, sütun genişliği, freeze pane, autofilter (X2'deki desen),
   - sütun tipi bildirimi (`metin` / `tamsayi` / `kg` / `tutar` / `tarih`). Sayılar **sayı**, tarihler **tarih** hücresi olarak yazılır,
   - rapor başlığı ve filtre özeti satırı (X2'deki A1/A2 deseni),
   - çok bölümlü raporlar için **bölüm başına bir sayfa**,
   - çıktı başlıkları + `ob_end_clean` (X2'deki gibi, tamponun dosyayı bozmaması için),
   - satır eşiği kontrolü (§4.1-a).
2. **Tek veri kaynağı:** Her uç noktada satır dizisi **bir kez** üretilir. CSV ve XLSX yazıcıları aynı diziyi okur.
   Böylece iki çıktının birbirinden ayrışması imkânsız hâle gelir.
3. **Buton düzeni:** `⬇ Excel` (birincil, XLSX) + `CSV` (ikincil, ghost). Etiketler gerçek formatı söyler.
4. **KG kuralı:** Ekranda KG tam sayı gösteriliyor, CSV ondalığı koruyor (CLAUDE.md). XLSX'te **değer tam ondalıklı**
   yazılır, **görünüm** biçim koduyla belirlenir (ör. `#,##0.000`). Veri kaybı olmaz.
5. **Para birimi kuralı:** Tutarlar **para birimi başına ayrı sütun ya da ayrı satır** olarak yazılır.
   C13/C14'teki "TRY: … | USD: …" metni böylece hesaplanabilir sayılara ayrılır.
6. **Audit ve yetki:** Yeni XLSX yolları, mevcut CSV yolunun yetki kapısını birebir devralır. Politika kararı (§7) çıkarsa eksik audit kayıtları aynı dokunuşla eklenir.
7. **Test:** Her dönüştürülen uç nokta için bellek içi SQLite ile gerçek uç noktayı çalıştıran, `.xlsx`'i geri okuyan
   ve **aynı filtreyle CSV ile satır ve toplam eşitliğini** doğrulayan bir smoke testi yazılır.
8. **Sürüm:** UI etiketi değiştiği için `sw.js` cache ve `APP_SURUM` artırılır.

---

## 7. Yol Haritası

| Faz | İş | Dokunulan dosyalar | Risk | Önkoşul |
|---|---|---|---|---|
| **0 — Hazırlık** | CSV'yi dışarıda kim, nasıl kullanıyor, netleştir (§8-1). PhpSpreadsheet CVE'lerini değerlendir. Canlıda en büyük C3/C10 çıktılarının satır sayısını ve `memory_limit` değerini öğren. Mevcut CSV çıktılarından **referans dosyalar** al | — | Yok | — |
| **1 — Altyapı** | `config/xlsx_export.php` + smoke testi. Hiçbir buton değişmez | yeni dosyalar | Çok düşük | Faz 0 |
| **2 — Pilot: Raporlar** | C1–C4 (`reports.php`): "Excel" yazan butonlar gerçekten XLSX indirir, CSV ikincil butonda kalır. Günlük rapor bölümleri ayrı sayfalara ayrılır | `reports.php` | Orta: en çok kullanılan ekran | Faz 1 |
| **3 — Stok ve Kantar** | C6–C10. C10 için satır eşiği | `kantar_raporu.php`, `stok.php`, `malzeme_stok.php`, `malzeme_hareketleri.php` | Düşük–Orta | Faz 2'den geri bildirim |
| **4 — Sahte `.xls`'ler** | H1 → stilli XLSX (para birimi blokları korunur). H2 → gerçek XLSX örnek dosya + içe aktarma tarayıcı testi | `hesap_export.php`, `excel_ornek_palet.php` | Orta: muhasebenin alıştığı görünüm | Faz 1 |
| **5 — PDKS** | C13–C16. **Önce sayı düzeltmesi** (C16'daki nokta ondalık, C13/C14'teki birleşik metin), sonra XLSX | `raporlar.php`, `gunluk_isci_puantaj.php`, `cavus_ekstre.php` | Orta: finansal veri | Faz 1 |
| **6 — Audit ekranı** | C11/C12 (yalnız admin). İsteğe bağlı: bölümler ayrı sayfalara ayrılır | `audit.php` | Düşük | İsteğe bağlı |
| **7 — Kütüphane** | PhpSpreadsheet yükseltmesi (CVE). **X1 + X2 smoke testleri zorunlu**, tüm yeni XLSX testleri de çalıştırılır | `vendor/`, `composer.*` | **Yüksek: şablonlu Excel'i etkiler** | Ayrı PR, tüm testler |
| **Kesişen** | Yetki ve audit eşitlemesi (C6/C9/C10/X1/PDKS). SW cache ve `APP_SURUM` artırımı | ilgili dosyalar | Düşük | §8-3 kararı |

**Önerilen başlangıç:** Faz 0 → Faz 1 → Faz 2 (pilot). Pilot ile ekranda en görünür sorun ("Excel" yazıp CSV inmesi)
kapanır ve yöntem canlıda kanıtlanır. Sonraki fazlar pilotun geri bildirimiyle ilerler.

**Bağımsız hızlı kazanım:** C16'daki nokta ondalık (`1250.50`), XLSX işinden bağımsız olarak **tek satırlık bir
biçim düzeltmesiyle** giderilebilir. Finansal veri olduğu için öne alınması düşünülebilir.

---

## 8. Karar Bekleyen Sorular

1. **CSV kalıcı mı?** Muhasebe ya da başka bir yazılım CSV'yi otomatik içe aktarıyor mu? Aktarıyorsa CSV ikincil
   butonda **bayt bayt aynı** kalmalı. Aktarmıyorsa ileride kaldırılabilir.
2. **Büyük çıktılar:** C10 (100 bin satır) ve C3 için satır eşiği kabul edilebilir mi, yoksa bu iki çıktı CSV-birincil mi kalsın?
3. **Yetki politikası:** Stok ve kantar dışa aktarımı da `reports.export` istesin mi, yoksa ekranı görebilen indirebilsin mi (bugünkü durum)?
   Tüm dışa aktarımlar audit'e yazılsın mı?
4. **Hesap Excel'i (H1):** Muhasebe mevcut görünümü mü bekliyor, yoksa filtrelenebilir düz tablo mu tercih eder?
   (İkisi aynı dosyada iki sayfa olarak da verilebilir.)
5. **Günlük rapor (C1):** Bölümler ayrı sayfalara mı ayrılsın, yoksa tek sayfada alt alta mı kalsın (bugünkü okuma alışkanlığı)?
6. **PhpSpreadsheet yükseltmesi** XLSX yaygınlaştırmasından **önce** mi yapılsın, **sonra** mı? Önce yapılırsa yeni kod
   doğrudan yeni sürüme yazılır. Sonra yapılırsa şablonlu çıktı daha uzun süre bugünkü, kanıtlanmış sürümde kalır.
