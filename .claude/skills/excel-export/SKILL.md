---
name: excel-export
description: Dışa aktarım menüsü ve XLSX üretimi (config/xlsx_export.php, export_menu, export_audit, reports.export). Yeni dışa aktarım eklerken ya da XLSX/CSV koduna dokunurken kullan.
---

# Excel İndir — CSV + XLSX

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

## Excel İndir — CSV + XLSX (Sprint Excel-01)

Dışa aktarım butonları tek bir **"⬇ Excel İndir ▾"** menüsüdür; tıklanınca
**CSV İndir** ve **XLSX İndir** seçenekleri açılır. Envanter ve gerekçe:
`@docs/EXCEL_EXPORT_ANALIZ.md`.

**Dosyalar:** `config/xlsx_export.php` (`xlsx_olustur` / `xlsx_indir` / `export_menu` /
`export_audit`) · menü CSS'i `style.css` (`.dl-menu*`) · açılış `app.js` (kebab altyapısı).
**Test:** `node scripts/export_menu_smoke.js` (önce `php scripts/export_menu_render.php > _test_export_menu.html`) ·
PDKS XLSX kolları `pdks_*_ui_smoke.php` içinde alt süreçte (`scripts/_xlsx_altsurec.php`).

- **CSV'ye DOKUNMA.** CSV başka bir yazılıma aktarılabilir; biçim (`;`, BOM, ondalık
  yazımı, sütun sırası) bayt bayt korunur. Yeni XLSX kolu CSV kolunun **yanına**
  eklenir, aynı satır dizisini okur, CSV kodunu değiştirmez. Sorgu iki kola ortaksa
  kapanışa alınır (`reports.php` `$rpt_detay_veri`) — kopyalanmaz.
- **Yeni dışa aktarım eklerken:** `export_menu($csv_url, $xlsx_url, 'Etiket')` ile buton,
  uç noktada `if (csv || xlsx) require_perm('reports.export')` + her iki kolda
  `export_audit(...)`. **Ortak kapı `reports.export`** — sayfanın okuma yetkisine EK
  olarak istenir (kullanıcı kararı). Menü yetkisi olmayana hiç basılmaz.
- **`xlsx_olustur()` sütun tipleri:** `metin · tamsayi · kg · ondalik · tutar · sayi ·
  tarih · tarihsaat`. Sayılar SAYI, tarihler TARİH hücresi yazılır; çözülemeyen değer
  metin kalır (veri kaybolmaz). `kg` görünümü tam sayıdır, değer ondalığı korur.
- **Formül enjeksiyonu:** metin hücreleri `setCellValueExplicit(TYPE_STRING)` ile yazılır —
  `=HYPERLINK(...)` gibi bir firma adı/not formül olarak ÇALIŞMAZ. `setCellValue()` /
  `fromArray()` ile kullanıcı verisi YAZMA. Formül yalnız toplam satırındaki `SUBTOTAL(9,…)`
  (filtre uygulanınca yalnız görünen satırları toplar).
- **Toplam satırı** yalnız anlamlı sütunlarda (`'topla' => true`). Giriş/çıkış ya da
  farklı birimler/para birimleri aynı sütundaysa toplam KOYMA. **Para birimleri asla
  toplanmaz** — ayrı sütun (PDKS raporları) ya da ayrı sayfa (çavuş ekstresi, hesap özeti).
- **Bellek sınırı `XLSX_MAX_HUCRE` (150 bin hücre ≈ 130 MB / 7 sn, ölçüldü).** Aşılırsa
  yarım dosya yerine "filtreyi daraltın / CSV indirin" sayfası. Veri hücresi stili aralık
  (`getStyle('A5:J30000')`) ile DEĞİL, kayıtlı stil indeksiyle verilir — aralık çağrısı
  her hücreyi dolaşıyordu (300 bin hücrede +14 sn).
- **Hazır şablonlu yükleme Excel'i** (`record_excel_template.php` + `templates/excel/`)
  ve `rapor_malzeme.php`'nin kendi XLSX bloğu bu yardımcıyı KULLANMAZ; kendi testleri var
  (`record_excel_smoke.php`, `rapor_malzeme_xlsx_smoke.php`). Onlara yalnız yetki + audit eklendi.
- **`excel_ornek_palet.php` bir İÇE AKTARMA şablonudur:** başlık ilk sayfanın 1. SATIRINDA
  kalmalı (`app.js` `parseWorkbook` sözleşmesi) — `xlsx_olustur()` düzeni (A1'de başlık)
  orada KULLANILMAZ.
- **`hesap_export.php`** artık gerçek XLSX üretir (`?bicim=xlsx` varsayılan, `?bicim=csv`).
  Eskiden HTML tablosunu `.xls` uzantısıyla gönderiyordu.
- **Menü = kebab altyapısı** (`.pc-dropdown` + `app.js` `position:fixed`): mobilde
  `overflow-x:auto` olan `.rpt-actions` içinde KESİLMEZ. `position:absolute` bir listeye
  çevirme. Kebab menü içindeki dışa aktarımlar (günlük rapor) iki düz bağlantıdır.
- `fputcsv()` her çağrıda `';', '"', '\\'` ile çağrılır — PHP 8.4 varsayılan `$escape`'e
  güvenmeyi kullanımdan kaldırdı (değer aynı, çıktı değişmez).

---
