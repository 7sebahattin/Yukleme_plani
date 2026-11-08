---
name: maliyet-modulu
description: Detaylı Maliyet modülü (maliyet*.php, config/cost_calc.php, maliyet.js): kalem tipleri, formül motoru, şablonlar. maliyet_* sayfalarına dokunurken kullan.
---

# Maliyet Hesabı Modülü (detaylı)

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

## Maliyet Hesabı Modülü (Sprint Maliyet-01)

Excel taslağının ("MALİYET ÇALIŞMA") sisteme taşınmış hâli. **Amaç: kullanıcı sonradan
kendi alanlarını ve hesaplama kurallarını ekleyebilsin** — kod değişikliği gerektirmez.

**Dosyalar:** `maliyet.php` (liste) · `maliyet_form.php` + `_maliyet_row.php` (form) ·
`maliyet_view.php` (görüntüle/yazdır) · `maliyet_sil.php` · `maliyet_alanlar.php` (alan/formül tanımları) ·
`maliyet_sablon.php` (şablonlar) · `maliyet_ambalaj.php` (fiyat listesi) ·
`config/cost_calc.php` (şema + formül motoru) · `assets/maliyet.css` + `assets/maliyet.js`.

> Modül kendi CSS/JS dosyasını kullanır (tek-CSS kuralının bilinçli istisnası): yalnız
> `maliyet_*` sayfalarında yüklenir, `style.css`/`app.js`'e hiç dokunmaz → mevcut mobil düzen etkilenmez.

**Tablolar:** `cost_sheets` · `cost_sheet_sections` · `cost_sheet_items` ·
`cost_field_defs` · `cost_templates` / `cost_template_sections` / `cost_template_items` ·
`cost_packaging_prices`. Hepsi `CREATE TABLE IF NOT EXISTS` (`cost_migrate()`), mevcut tabloya ALTER yok.

**Yetkiler:** `maliyet.read` / `.write` / `.delete` / `.unlock` / `.admin`
(admin hepsi, operator+muhasebe read+write). `require_maliyet('write')` kullan.

**Genişletme noktaları (kullanıcı tarafı, kod gerekmez):**
- Kalem ekle/sil/taşı — her bölümde serbest.
- Bölüm ekle — kendi bazı (Net KG / sabit / formül) ve kendi toplamı olur; `include_in_total=0` ile
  genel toplamdan hariç tutulur (Excel'deki depo ortalaması bloğu böyle).
- Başlık alanı ekle — `maliyet_alanlar.php`; tip `number` ise formüllerde `[kod]` olarak kullanılır,
  tip `formula` ise girdi almaz, hesaplar.
- Ambalaj fiyatı — `maliyet_ambalaj.php`; kalem adı yazılınca birim fiyat otomatik dolar.
- Şablon — mevcut bir hesaptan "Şablon Yap"; yeni hesaplar bu setle açılır.

**Kalem hesap tipleri** (`cost_calc_types()`): `qty_price` · `per_kg` · `fixed` · `percent` ·
`formula` · `subtotal` · `info`. Yeni tip eklerken üç yeri birden güncelle:
`cost_calc_types()` + `cost_compute_section_items()` (PHP) + `computeSection()`/`applyRowType()` (JS).

**Formül motoru — `eval()` YOK.** Kendi tokenizer + shunting-yard + RPN'i (`config/cost_calc.php`).
`assets/maliyet.js` aynı semantiğin aynası; **canlı önizleme sadece JS, kaydedilen tutarları
her zaman PHP yeniden hesaplar** (tek otorite). İkisini birlikte değiştir.

```
[kod]           → kalemin tutarı        [kod.miktar] [kod.fiyat] [kod.birim_maliyet]
[net_kg] [baz] [kur] [navlun] [satis_kg] [satis_fiyat]
[ust_toplam] [bolum_toplam] [genel_toplam]
yuvarla(x;n) min maks mutlak tavan taban topla ort eger(kosul;a;b)
```

- Bağımlılıklar topolojik sıralanır → ileri referans çalışır, **döngü tespit edilip uyarı verilir** (0 kabul edilir).
- Ondalık ayracı virgül de nokta da olur; fonksiyon argümanları **noktalı virgülle** ayrılır.
- `is_income=1` satır toplamdan **düşülür** (Excel'deki çıkma satırı).

**Dikkat:**
- `hidden` özniteliği `.mly-*` sınıflarının `display` kurallarını ezemez —
  `maliyet.css` başındaki `[hidden]{display:none!important}` kuralını **silme**.
- Kaydetme bölüm/kalemleri silip yeniden yazar (sıralama sadeliği için); id'ler değişir, dışarıdan referans verme.
- `status='kesin'` kilitler; açmak `maliyet.unlock` + revizyon nedeni ister.
- Depo damgası `cost_sheets.depo`; liste `depo_sql_column('depo')`, tekil erişim `depot_visible_to_user()`.

---
