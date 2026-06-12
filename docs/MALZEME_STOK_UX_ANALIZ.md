# Sprint MalzemeStok-UX-Analiz-01 — malzeme_stok.php Analiz ve Yapı Önerisi Raporu

> Bu sprint yalnızca analizdir. Kod yazılmamış, veri değiştirilmemiş, refactor yapılmamıştır.
> Rapor tarihi: 2026-06-12 · İncelenen branch: `claude/material-stock-ux-analysis-j5a160`

---

## 1. Mevcut malzeme_stok.php Teknik Özeti

### Temel sayılar

| Metrik | Değer |
|---|---|
| Dosya uzunluğu | **1.899 satır** (tek dosya: PHP + HTML + CSS + JS) |
| İlişkili dosyalar | `malzeme_stok_tehis.php` (1.666 satır, admin), `malzeme_stok_import.php` (568 satır) |
| POST action sayısı | 6 (`ms_giris`, `ms_sevk`, `ms_duzeltme_direkt`, `ms_duzeltme`, `ms_update`, `ms_delete`) |
| GET parametre sayısı | **13** (`tarih_bas`, `tarih_bit`, `mat_id`, `mat_type`, `mat_name`, `depo`, `hareket_tipi`, `csv`, `hareket_page`, `ozet_kategori`, `ozet_tur`, `ozet_malzeme`, `ozet_depo`) |
| Her sayfa açılışında çalışan SQL | **~26 sorgu** (özet 2 + hareket 1 + dropdown 4 + audit özeti ~4 + audit detay 6 + veri kalite ~9) |
| Inline JS | ~105 satır (`<script>` bloğu, satır 1792–1897) |
| Inline `<style>` | Print CSS bloğu (satır 824–843) + yüzlerce inline `style=""` |

### Dosya içi bölge haritası (satır aralıkları)

| Satırlar | İçerik |
|---|---|
| 1–11 | Bootstrap, `require_login()`, `require_perm('stok.read')` |
| 14–34 | `ms_url()` — 12 parametreli filtre durumu koruyan URL üreteci |
| 37–66 | `ms_audit_counts()` + `audit_tbl_ms()` — orphan/duplicate sayaçları |
| 69–94 | Tür/birim/kategori sabitleri, `ms_cat_of()` |
| 96–169 | **POST** `ms_giris` / `ms_sevk` — giriş & sevk kaydı |
| 171–241 | **POST** `ms_duzeltme_direkt` — bağımsız ± düzeltme |
| 243–290 | **POST** `ms_duzeltme` — referanslı düzeltme (**ÖLÜ + BOZUK KOD**, bkz. Bölüm 4) |
| 292–404 | **POST** `ms_update` — hareket düzenleme (geçmişi mutate ediyor) |
| 406–430 | **POST** `ms_delete` — **hard DELETE** (loading kaynaklılar hariç) |
| 432–468 | GET filtreleri + iki ayrı WHERE builder (özet / hareket) |
| 470–596 | Stok özeti hesabı: SQL aggregate + PHP'de tanım-merkezli birleştirme + ozet_* filtreleri + sıralama |
| 598–614 | Hareket listesi sorgusu (`LIMIT 2000`, sayfalama PHP `array_slice`) |
| 616–647 | Dropdown listeleri (depo, malzeme adları ×2 kaynak, firma) |
| 653–706 | CSV export ×2 (özet `csv=ozet`, hareketler `csv=1`) |
| 715–737 | Audit detay sorguları (3 kontrol × count+rows) |
| 739–819 | Sprint 33D Veri Kalite Kontrolü (6 kontrol × count+rows) |
| 824–843 | Print CSS |
| 853–890 | Sayfa başlığı + butonlar + **negatif stok uyarı tablosu** |
| 892–944 | Ana filtre formu (tarih/tür/ad/depo) |
| 946–968 | 4 özet kartı (Toplam Giriş / Çıkış / Stokta / Negatif) |
| 970–1120 | Stok Özeti tablosu + **ikinci, ayrı filtre formu** (ozet_*) + print başlığı |
| 1122–1355 | 3 sekmeli form bloğu: Giriş / Sevk / Düzeltme |
| 1357–1370 | Hareket tipi pill filtreleri |
| 1372–1508 | Hareket tablosu + düzenle/sil butonları + sayfalama |
| 1510–1619 | Veri Kalite Kontrolü paneli (`<details>` — sorun varsa **varsayılan açık**) |
| 1621–1705 | Hareket Düzenle modalı |
| 1707–1790 | Sistem Audit paneli (`<details>`) |
| 1792–1897 | Inline JS (sekme, isim doldurma, edit modal) |

### Kullanılan tablolar

| Tablo | Kullanım |
|---|---|
| `material_stock_movements` | Ana tablo — okuma + INSERT/UPDATE/DELETE |
| `material_definitions` | Tanım eşleme (normalize_text_v2 ile), dropdown'lar, özet tabanı |
| `loading_records` | Orphan kontrolü, sync-dışı kayıt kontrolü (yalnızca okuma) |
| `loading_pallets` | Kasa/palet tipsiz kalite kontrolleri (yalnızca okuma) |
| `pallet_materials` | Bu sayfada **doğrudan kullanılmıyor** — `sync_malzeme_kullanim()` (config/helpers.php:1355) sarf kullanım hareketlerini buradan üretir |
| `material_templates` | **Kullanılmıyor** |
| `audit_log` | `audit_log_event()` üzerinden yazma (create/update/delete) |
| `users` | **Kullanılmıyor** — ⚠ hareketlerde `created_by` kolonu YOK; kim girdi bilgisi yalnızca audit_log'da |

### `material_stock_movements` şeması (config/helpers.php:935–962)

Mevcut kolonlar: `id, movement_date, movement_type ENUM('giris','sevk','kullanim','duzeltme'), material_id (NULL olabilir), material_name, material_type, depo, quantity, unit, unit_dara_kg, total_dara_kg, source_type, source_id, source_detail_id, belge_no, firma, note, created_at, updated_at`.

**Eksikler (Bölüm 7'deki standarda göre):** `created_by`, `related_movement_id`, `is_voided/voided_by/voided_at`, `transfer` tipi.

### Hareket üretim akışı (mevcut)

- **Manuel:** `ms_giris`, `ms_sevk`, `ms_duzeltme_direkt` → bu sayfadan.
- **Otomatik:** `sync_malzeme_kullanim()` — yükleme kaydı kaydedilince `kullanim` hareketleri üretir (kasa, palet, sarf). Idempotent: kayda ait `source_type='loading'` hareketleri silip yeniden yazar. Çıkma kayıtları Sprint 36'dan beri hareket üretmez.
- **Import:** `malzeme_stok_import.php` (Excel).

---

## 2. Sayfadaki Sorumluluk Karmaşası

Tek dosyada **10 ayrı görev** iç içe:

| Görev | Satırlar | Kime lazım | Aynı sayfada kalmalı mı | Risk |
|---|---|---|---|---|
| A) Stok özeti (kartlar + tablo) | 470–596, 946–1120 | Herkes | ✅ Kalmalı — sayfanın asıl amacı bu | Düşük |
| B) Giriş/Sevk/Düzeltme formları | 96–241, 1122–1355 | Operator+ | ❌ Ayrı sayfa veya modal (`malzeme_stok_giris.php`) | Orta (yanlış girişler) |
| C) Hareket geçmişi | 598–614, 1357–1508 | Herkes | ❌ Ayrı sayfa (`malzeme_hareketleri.php`) | Düşük (okuma) |
| D) Negatif stok takibi | 592–596, 863–890, 808–817 | Herkes | ✅ Kalmalı ama **tek yerde** (şu an 3 yerde tekrar ediyor) | Düşük |
| E) Teşhis raporu (Veri Kalite + Sistem Audit) | 37–66, 715–819, 1510–1619, 1707–1790 | Yalnızca admin | ❌ `malzeme_stok_tehis.php`'ye taşınmalı (zaten var!) | Orta (her açılışta ~16 ekstra sorgu) |
| F) Referans düzeltme (`ms_duzeltme`) | 243–290 | Hiç kimse (UI'dan erişilemiyor) | ❌ **Silinmeli/yeniden yazılmalı** — bozuk ölü kod | **Yüksek** |
| G) Yanlış hareket düzeltme (`ms_update` + `ms_delete`) | 292–430, 1621–1705 | Operator+ (şu an), admin olmalı | ❌ Ayrı admin ekranı | **Yüksek** (hard delete + geçmiş mutasyonu) |
| H) Import/Excel bağlantısı | 856 | Operator+ | ✅ Sadece link — sorun yok | Düşük |
| I) Depo bazlı stok | Özet/filtrelerin içine gömülü | Herkes | ✅ Kalmalı, ama filtre olarak netleşmeli | Düşük |
| J) Admin bakım araçları | E+F+G toplamı | Admin | ❌ Tek admin bakım sayfasında toplanmalı | Yüksek |

**Çift teşhis katmanı sorunu:** Sayfa içinde "Veri Kalite Kontrolü" ve "Sistem Audit" panelleri var; ayrıca 1.666 satırlık bağımsız `malzeme_stok_tehis.php` (admin-only) neredeyse aynı kontrolleri (orphan, ID kopması, depo uyuşmazlığı, negatif analiz…) daha derin yapıyor. Aynı iş iki yerde, iki ayrı kod tabanıyla sürdürülüyor.

---

## 3. Kullanıcı Deneyimi Sorunları

1. **Sayfa aşırı uzun.** Negatif uyarı tablosu → ana filtre → 4 kart → özet tablosu (sayfalamasız, tüm malzeme×depo satırları) → 3 sekmeli form → pill filtreler → hareket tablosu → veri kalite paneli → sistem audit paneli. Normal kullanıcı "kalan ne kadar?" sorusu için bu yığının içinde geziniyor.
2. **İki ayrı filtre sistemi karışıyor.** Ana filtre (hareketleri VE özeti etkiler) + ozet_* filtreleri (yalnızca özeti etkiler). Butonlar "Filtrele" vs "Süz". Hangi filtrenin neyi daralttığı kullanıcıya hiçbir yerde anlatılmıyor; 13 GET parametresi hidden input'larla taşınıyor.
3. **Tarih filtresi "Kalan"ın anlamını bozuyor.** Tarih aralığı seçilince "Kalan" kolonu gerçek stok değil, o aralıktaki net değişim olur — ekranda hiçbir uyarı yok. Yanlış karar riski yüksek.
4. **Admin teşhis bölümleri normal kullanıcının önünde.** Veri Kalite paneli sorun varsa `open` geliyor (satır 1518) — viewer/muhasebe dahil herkes "kasa tipi seçilmemiş paletler" gibi teknik dökümlerle karşılaşıyor. Sistem Audit paneli de tüm `stok.read` sahiplerine görünüyor.
5. **Negatif stok 3 ayrı yerde tekrar ediyor** (üst uyarı tablosu, özet kartı, kalite panelindeki "neg_kasa_palet") — hangisinin "doğru liste" olduğu belirsiz.
6. **Mobilde tablo zayıf.** `stok-hide-sm` ile kolon gizleme var ama projenin diğer sayfalarındaki `.mobile-only` kart deseni burada yok; mobilde Kategori/Tür/Depo/Birim/Belge kolonları kaybolduğu için kullanıcı hangi deponun stoğuna baktığını göremiyor.
7. **"Toplam Giriş/Çıkış" kartları anlamsız sayı üretiyor.** adet + kg + m² + rulo aynı toplamda ("tüm birimler toplamı" notuyla). 15.932 adet + 120 kg = 16.052 → karar verilemez bir sayı.
8. **Yanlış girişin düzeltme yolu belirsiz.** Kullanıcının önünde 4 yol var: ✎ düzenle (geçmişi değiştirir), ✕ sil (kalıcı siler), ± Düzeltme sekmesi (yeni kayıt), ve hiç çalışmayan referans düzeltme. Hangisinin ne zaman kullanılacağı anlatılmıyor.
9. **ID mi isim mi takibi kullanıcıya görünmez.** Sistem material_id bazlı gruplar (satır 482–506'daki kök bugfix doğru), ama UI'da malzemenin ID'si hiçbir yerde gösterilmiyor; isim değişince kullanıcı hareketlerin neden hâlâ birleşik olduğunu/olmadığını anlayamıyor. `material_id NULL` hareketler sessizce isim bazlı gruplanıyor.
10. **Depo bazlı stok yarım.** Özet satırları depo kırılımlı ama malzemenin **depolar arası toplamı** hiçbir yerde yok; "Depo Boş" satırları gerçek depo satırlarıyla yan yana.
11. **Son hareket tarihi yok.** Bir malzemenin en son ne zaman hareket gördüğü özetten okunamıyor.
12. **Performans:** Her açılışta ~26 sorgu; hareketler `LIMIT 2000` çekilip PHP'de dilimleniyor; özet tablosu sayfalamasız. Veri büyüdükçe sayfa yavaşlayacak.

---

## 4. En Riskli Alanlar

1. **🔴 `ms_duzeltme` handler'ı bozuk + ölü kod (satır 243–290).** INSERT, şemada olmayan **`nota`** kolonuna yazıyor (satır 268; şemada kolon adı `note`). Çalıştırılsa SQLSTATE hatası fırlatır. Ayrıca sayfada bu action'ı gönderen hiçbir form yok — repo genelinde tek referans bu satır. Yani "mevcut harekete bağlı düzeltme" özelliği fiilen yok.
2. **🔴 Hard DELETE operatöre açık (`ms_delete`).** `stok.write` yeterli → operator rolü hareketi kalıcı silebiliyor. Muhasebe mantığına aykırı; audit_log'a yazılsa da stok hareket zincirinde iz kalmıyor.
3. **🔴 `ms_update` geçmişi mutate ediyor.** Tarih, malzeme, depo, miktar — hepsi yerinde değiştirilebiliyor. "Her hareket değişmez kayıt" ilkesi yok.
4. **🟠 `created_by` yok.** Hangi hareketi kimin girdiği tabloda tutulmuyor; yalnızca audit_log'dan dolaylı bulunabilir.
5. **🟠 Tarih filtresi + "Kalan"** birleşimi yanlış stok okumasına yol açabilir (Bölüm 3.3).
6. **🟠 Özet hesabı PHP'de birleşiyor** (satır 470–596): SQL aggregate + tanım merkezli merge + NULL-id fallback + kategori eşleme. Bu mantık sayfaya gömülü olduğu için CSV, kalite kontrolü ve tehis sayfası ayrı ayrı benzer hesaplar yapıyor — tutarsızlaşma riski.
7. **🟡 Teşhis sorguları her görüntülemede koşuyor** — `loading_pallets`/`loading_records` üzerinde EXISTS'li ağır sorgular dahil, viewer için bile.

---

## 5. Önerilen Yeni Sayfa Mimarisi

| Dosya | Amaç | Kim | Yapacakları | Yapmayacakları | Risk |
|---|---|---|---|---|---|
| **malzeme_stok.php** (sadeleşmiş) | Güncel stok özeti + listeleme | Tüm `stok.read` | Özet kartları, hızlı filtreler, stok tablosu/kart görünümü, satır başına Detay/Hareketler/Giriş Ekle linkleri | POST işlemi YOK; teşhis YOK; form YOK | Düşük |
| **malzeme_hareketleri.php** | Malzeme bazlı hareket dökümü. Parametre: `material_id`, `depo`, tarih, tip | Tüm `stok.read` | Hareket listesi, sayfalama (SQL `LIMIT/OFFSET`), CSV, kaynak linki (Yük #), çalışan stok bakiyesi kolonu | Düzenleme/silme YOK (admin'e düzelt ekranı linki) | Düşük |
| **malzeme_stok_giris.php** (veya ana sayfada modal) | Manuel giriş / sevk / sayım düzeltmesi | `stok.write` | 3 sekmeli mevcut form buraya taşınır; başarı sonrası ana sayfaya döner | Hareket düzenleme/silme YOK | Orta |
| **malzeme_stok_duzelt.php** | Yanlış hareket iptal / ters kayıt | **Yalnızca admin** (`materials.correct`) | Hareketi görüntüle → "İptal et" (void) veya "Ters hareket oluştur"; onay ekranı; audit | Hard DELETE YOK; yerinde UPDATE YOK | Yüksek → kontrollü |
| **malzeme_stok_tasi.php** | Hareketi doğru malzemeye/depoya taşıma | **Yalnızca admin** (`materials.move`) | Hareket seç → hedef tanım seç (aynı tip ailesi) → önizleme → onay → `material_id/depo` güncelle + audit | Miktar/tip değiştirme YOK | Yüksek → kontrollü |
| **malzeme_stok_tehis.php** (mevcut, genişler) | Teknik teşhis | Admin | Mevcut A–H4 kontrolleri + ana sayfadan taşınan Veri Kalite + Sistem Audit panelleri | Otomatik düzeltme YOK (sadece rapor + ilgili araca link) | Düşük (okuma) |
| **malzeme_stok_import.php** (mevcut) | Excel import | `materials.import` (şimdilik `stok.write`) | Mevcut akış korunur | — | Orta |
| **malzeme_stok_rapor.php** | Yazdırma/PDF/CSV sade çıktı | `reports.read` | Ana sayfadaki print CSS + CSV exportları buraya; filtreli sade rapor | Etkileşim YOK | Düşük |

Ortak katman: **`config/material_stock_helpers.php`** (Bölüm 9) — tüm sayfalar aynı hesap fonksiyonlarını kullanır, "tek doğru kaynak" oluşur.

Bu bölünmeyle ana dosya tahminen 1.899 → ~400-500 satıra iner.

---

## 6. Önerilen UI Yapısı

### Masaüstü (≥900px, mevcut sidebar düzeniyle uyumlu)

```
┌─ Sidebar ─┬──────────────────────────────────────────────┐
│ (mevcut)  │ [Özet kartları: Malzeme · Negatif ⚠ · Kritik │
│           │  · Bugün Giriş · Bugün Çıkış · Son Hareket]   │
│           ├──────────────────────────────────────────────┤
│           │ [Hızlı filtre pill'leri: Tümü | Negatif |     │
│           │  Stokta | Sıfır] [Depo ▾] [Tip ▾] [🔍 Ara]    │
│           ├──────────────────────────────────────────────┤
│           │ Stok tablosu:                                 │
│           │ Malzeme · Tip · Depo · Giriş · Çıkış ·        │
│           │ Düzeltme · KALAN · Son Hareket · Durum · ⋮    │
│           └──────────────────────────────────────────────┘
```

- Tek filtre çubuğu — ozet_*/ana filtre ikiliği kalkar; tarih filtresi yalnızca hareket sayfasında kalır (böylece "Kalan" her zaman gerçek stok).
- Satır işlem menüsü (kebab `⋮`, mevcut z-index 200 deseni): **Detay · Hareketler · Giriş Ekle**; admin'e ek: **Düzelt · Taşı**.
- Sağ detay paneli (opsiyonel, ≥1280px): seçili malzemenin son 10 hareketi.

### Mobil (<768px) — kart görünümü

Projedeki `.mobile-only` kart deseni buraya da uygulanır:

```
┌────────────────────────────────┐
│ YUNAN KASA              [Stokta]│   ← durum rozeti: yeşil/kırmızı/gri
│ Kasa Cinsi · Karaman Cihat      │
│                                 │
│  Giriş 15.932 · Kullanım 15.545 │
│        KALAN: 387 adet          │   ← büyük punto; negatifse kırmızı
│  Son hareket: 10.06.2026        │
│ [Detay] [Giriş Ekle] [Hareketler]│
└────────────────────────────────┘
```

- Negatif kalan: kırmızı kart kenarı + ⚠; sıfır: gri; pozitif: yeşil.
- KG/adet değerleri tam sayı, virgülsüz (mevcut kural korunur).
- Form inputları 16px (iOS zoom kuralı), `overflow-x: clip` korunur.
- Yeni modal eklenirse z-index ≥ 600.

### Renk/rozet standardı

| Durum | Görsel |
|---|---|
| Negatif stok | Kırmızı rozet "Negatif ⚠" + satır arka planı (mevcut `ms-row-negatif`) |
| Sıfır stok | Gri rozet "Tükendi" |
| Stokta | Yeşil rozet "Stokta" |
| Kritik (eşik altı) | Turuncu "Kritik" — eşik için `material_definitions`'a `min_stock` kolonu önerisi (migration ayrı GO konusu) |
| Pasif tanım | Mevcut "(pasif)" etiketi korunur |

---

## 7. Önerilen Stok Hareket Standardı

İlke: **muhasebe mantığı — hareketler değişmez (immutable), düzeltme yeni kayıtla yapılır.**

### movement_type genişletmesi

Mevcut: `ENUM('giris','sevk','kullanim','duzeltme')`. Önerilen hedef küme:

| Tip | Yön | Açıklama |
|---|---|---|
| `giris` | + | Satın alma / tedarik (mevcut) |
| `kullanim` | − | Yüklemeden otomatik (mevcut, source_type='loading') |
| `sevk` | − | Depodan dışarı sevk (mevcut) |
| `duzeltme_arti` | + | Sayım fazlası (mevcut `duzeltme`+işaretli miktar yerine açık tip) |
| `duzeltme_eksi` | − | Sayım eksiği |
| `iptal` | ∓ | Bir hareketi sıfırlayan ters kayıt (`related_movement_id` zorunlu) |
| `transfer_in` / `transfer_out` | +/− | Depolar arası taşıma (çift kayıt, aynı `related_movement_id`) |

Geçiş notu: mevcut işaretli-`duzeltme` kayıtları korunur; raporlamada `duzeltme AND quantity>=0 → duzeltme_arti` eşlemesi yapılır. ENUM genişletme **migration gerektirir — bu sprintte yapılmaz, GO bekler.**

### Eksik kolonlar (migration önerisi — GO bekler)

```sql
-- ÖNERİ — ÇALIŞTIRILMADI
ALTER TABLE material_stock_movements
  ADD COLUMN created_by INT NULL AFTER note,
  ADD COLUMN related_movement_id INT NULL AFTER source_detail_id,
  ADD COLUMN is_voided TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN voided_by INT NULL,
  ADD COLUMN voided_at DATETIME NULL,
  ADD INDEX idx_msm_related (related_movement_id);
-- movement_type ENUM genişletmesi ayrı, daha riskli adım olarak planlanmalı
```

`sign/direction` ayrı kolonuna gerek yok: yön `movement_type`'tan türetilebilir (tek doğru kaynak). Stok hesabı `is_voided=0` filtresiyle yapılır.

### Davranış kuralları

- ❌ `ms_delete` (hard DELETE) kaldırılır → yerine **void** (`is_voided=1` + audit).
- ❌ `ms_update` ile miktar/malzeme/tarih değişikliği kaldırılır → yalnızca not/belge_no gibi zararsız meta alanlar düzenlenebilir; miktar hatası = iptal + yeni kayıt.
- ✅ `source_type='loading'` hareketlerine dokunulmaz (mevcut koruma doğru, korunur) — kaynak kayıt düzenlenince `sync_malzeme_kullanim()` zaten yeniden üretir.

---

## 8. Yanlış Hareket Düzeltme / Taşıma Önerisi

Kullanıcı ihtiyacı: *"Stok girişi yanlış isimden yapılmışsa doğru malzemeye taşıyabilmeliyim."*

### Önerilen akış

```
malzeme_stok.php (satır: Hareketler)
  → malzeme_hareketleri.php?material_id=X&depo=Y
     → yanlış hareketin yanında [⋮] (admin'e görünür)
        ├─ "Başka malzemeye taşı"  → malzeme_stok_tasi.php?id=N
        ├─ "Hareketi iptal et"      → malzeme_stok_duzelt.php?id=N&op=void
        └─ "Ters düzeltme oluştur"  → malzeme_stok_duzelt.php?id=N&op=reverse
           → ONAY EKRANI: eski durum / yeni durum / etkilenen stok bakiyesi önizlemesi
           → POST + csrf_check + audit_log_event
           → stok özeti otomatik doğru hesaplanır (hesap her zaman hareketlerden türetildiği için
             ayrıca "yeniden hesaplama" adımı GEREKMEZ)
```

### Üç işlemin anlamı

| İşlem | Ne yapar | DB etkisi |
|---|---|---|
| **Taşı** | Hareketin `material_id` (+ gerekirse `material_name`, `depo`) alanını doğru tanıma çevirir. Yalnızca **aynı tip ailesi** içinde (tehis sayfasındaki tip ailesi mantığı, satır 61–92, yeniden kullanılır) | 1 UPDATE + audit (`move`) |
| **İptal (void)** | Hareketi hesaplardan düşürür ama satır durur | `is_voided=1, voided_by, voided_at` + audit (`void`) |
| **Ters düzeltme** | Orijinal dururken zıt yönlü yeni hareket ekler | 1 INSERT (`iptal` tipi, `related_movement_id`=orijinal) + audit |

Gereken dosyalar: `malzeme_stok_duzelt.php`, `malzeme_stok_tasi.php`, `malzeme_hareketleri.php` (giriş noktası), `config/material_stock_helpers.php` (void/move fonksiyonları). Migration yapılana kadar geçici çözüm: yalnızca "Taşı" (UPDATE material_id — mevcut şemayla mümkün) + "Ters düzeltme" (mevcut `duzeltme` tipiyle, not alanına `ref #N` yazarak) sunulabilir; "void" migration sonrası gelir.

Bozuk `ms_duzeltme` handler'ı (satır 243–290) bu yapı kurulurken **silinir** — zaten erişilemiyor ve `nota` kolonu hatası taşıyor.

---

## 9. Teknik Refactor Önerisi — `config/material_stock_helpers.php`

| Fonksiyon | Görevi | Parametreler | Okur / Yazar |
|---|---|---|---|
| `get_material_stock_summary(array $filters): array` | Bugünkü 470–596 satırlık özet mantığının tek kaynağı: aggregate + tanım merge + kategori | `['kategori','tur','malzeme','depo','durum'(negatif/sifir/stokta)]` | Okur: `material_stock_movements`, `material_definitions` |
| `get_material_stock_totals(): array` | Üst kartlar: malzeme sayısı, negatif sayısı, kritik sayısı, bugünkü giriş/çıkış, son hareket tarihi | — | Okur: `material_stock_movements` |
| `get_material_movements(int $material_id, string $depo='', array $opts=[]): array` | Hareket dökümü; SQL tabanlı `LIMIT/OFFSET` sayfalama (PHP `array_slice` yerine) | `material_id, depo, ['tip','tarih_bas','tarih_bit','page','per_page']` | Okur: `material_stock_movements` |
| `calculate_material_balance(int $material_id, string $depo=''): float` | Tek malzemenin güncel kalanı (void hariç) | `material_id, depo` | Okur: `material_stock_movements` |
| `get_negative_materials(): array` | Negatif kalan satırları (uyarı bandı + tehis ortak kullanır) | — | `get_material_stock_summary()` üstüne filtre |
| `get_material_movement_sources(int $movement_id): array` | Hareketin kaynağı: loading kaydı, palet, ilişkili/ters hareketler | `movement_id` | Okur: `material_stock_movements`, `loading_records`, `loading_pallets` |
| `create_manual_stock_movement(array $data, int $user_id): int` | ms_giris/ms_sevk/duzeltme INSERT'lerinin ortak, doğrulamalı tek yolu (tanım eşleme + dara hesabı dahil) | `data{type,date,material,depo,qty,unit,belge,firma,note}, user_id` | Yazar: `material_stock_movements`; audit |
| `void_stock_movement(int $movement_id, int $user_id, string $reason): bool` | İptal — migration sonrası `is_voided`, öncesinde ters kayıt | `movement_id, user_id, reason` | Yazar: `material_stock_movements`; audit |
| `move_stock_movement_to_material(int $movement_id, int $target_material_id, int $user_id, string $reason): bool` | Hareketi doğru tanıma taşır; tip ailesi doğrulaması yapar | `movement_id, target_material_id, user_id, reason` | Okur: `material_definitions`; Yazar: `material_stock_movements`; audit |

Kazanım: ana sayfa, hareket sayfası, CSV, rapor, tehis ve kalite kontrolleri **aynı** hesap fonksiyonunu çağırır → bugünkü "üç yerde üç ayrı hesap" tutarsızlık riski biter. Tanım eşleme (normalize_text_v2 döngüsü) şu an 3 POST handler'da kopyalı — tek fonksiyona iner.

---

## 10. Yetki ve Güvenlik Önerisi

### Mevcut durum

- Tüm yazma işlemleri (giriş, sevk, düzeltme, **düzenleme, silme**) tek yetkide: `stok.write` → **operator dahil**.
- Teşhis sayfası `is_admin()`; sayfa içi teşhis panelleri ise tüm `stok.read` sahiplerine açık (tutarsız).
- Sistem `role_permissions` tablosu + `can()` ile string-bazlı; yeni yetki eklemek kolay (config/helpers.php:1050'deki `$all_p` listesine ekleme + seed).

### Önerilen yetki matrisi (mevcut altyapıya birebir uyar)

| Yetki | Admin | Operator | Viewer | Muhasebe | Karşılık |
|---|:--:|:--:|:--:|:--:|---|
| `stok.read` (mevcut) | ✓ | ✓ | ✓ | ✓ | Özet + hareket görüntüleme |
| `stok.write` (mevcut) | ✓ | ✓ | — | — | Manuel giriş/sevk/sayım düzeltmesi |
| `stok.correct` (yeni) | ✓ | — | — | — | Hareket iptal / ters kayıt |
| `stok.move` (yeni) | ✓ | — | — | — | Hareket taşıma |
| `stok.import` (yeni) | ✓ | ✓? | — | — | Excel import (operatöre verilip verilmeyeceği işletme kararı) |
| `stok.diagnose` (yeni) | ✓ | — | — | — | Teşhis/bakım ekranları (bugünkü `is_admin()` yerine) |

Not: Görevde `materials.*` adlandırması önerilmiş; mevcut sistemde modül öneki `stok.` olduğu için (`stok.read/write` zaten canlı) **`stok.correct` / `stok.move` / `stok.import` / `stok.diagnose`** olarak eklemek tutarlıdır. Seed `INSERT IGNORE` ile idempotent — mevcut auto-migration desenine uyar; yine de role_permissions'a satır eklemek DB yazımı olduğundan **GO ile** yapılmalı.

### Güvenlik kuralları (korunacak/eklenecek)

- Her POST'ta `csrf_check()` (mevcut, korunur), her write/delete'te `require_perm` (mevcut, daraltılır).
- Operator'dan `ms_delete`/`ms_update` yetkisi alınır → yalnızca void/ters kayıt + admin taşıma.
- Tüm düzeltme işlemlerinde `reason` zorunlu (records.unlock'taki `revision_reason` deseniyle aynı).
- `audit_log_event()` mevcut tüm handler'larda var (iyi durumda) — yeni işlemler `void`/`move` action adlarıyla eklenir.

---

## 11. Aşamalı Sprint Planı

> Sıralama ilkesi: önce davranışı değiştirmeyen altyapı, sonra okuma sayfaları, en son riskli yazma akışları.

### MalzemeStok-Pro-00 — Helper katmanı (ÖNERİLEN BAŞLANGIÇ)
- **Amaç:** `config/material_stock_helpers.php` oluştur; özet/hareket/bakiye hesaplarını taşı; `malzeme_stok.php` aynı çıktıyı helper'dan alsın. Bozuk `ms_duzeltme` handler'ını kaldır.
- **Dosyalar:** `config/material_stock_helpers.php` (yeni), `malzeme_stok.php` (hesap blokları çağrıya döner).
- **Risk:** Düşük-orta — UI değişmez; regresyon riski hesap eşitliğinde.
- **Test:** Refactor öncesi/sonrası aynı filtrelerle Özet CSV + Hareket CSV çıktısı **bayt bayt** karşılaştırılır; negatif liste sayısı eşleşir.
- **Kullanıcı etkisi:** Sıfır (görünüm aynı).

### MalzemeStok-Pro-01 — Ana sayfa sadeleştirme
- **Amaç:** malzeme_stok.php = yalnızca kartlar + tek filtre çubuğu + stok tablosu. Formlar, hareket tablosu, kalite/audit panelleri kaldırılır (linke döner). Tarih filtresi ana sayfadan kalkar ("Kalan" daima gerçek stok).
- **Dosyalar:** `malzeme_stok.php`, `assets/style.css` (yeni sınıflar), `sw.js` (cache v+1).
- **Risk:** Orta — kullanıcı alışkanlığı değişir.
- **Test:** 4 rolde sayfa açılışı; mobil <768px taşma kontrolü (`overflow-x: clip`); sidebar/bottomnav aktif link; print.
- **Kullanıcı etkisi:** Yüksek (olumlu) — ilk ekranda stok durumu.

### MalzemeStok-Pro-02 — malzeme_hareketleri.php
- **Amaç:** ID bazlı hareket detay sayfası (`material_id`, `depo`, tarih, tip filtreleri); SQL sayfalama; CSV; özet satırındaki 🔍 buraya yönlenir.
- **Dosyalar:** `malzeme_hareketleri.php` (yeni), `malzeme_stok.php` (linkler), sidebar aktif link (`config/helpers.php`).
- **Risk:** Düşük (salt okuma).
- **Test:** mat_id'li/mat_id NULL malzeme; isim değiştirilmiş malzemenin geçmişinin bütün kalması; 2000+ hareketle sayfalama.
- **Kullanıcı etkisi:** Orta — "ürün bazlı geçmiş" ihtiyacı karşılanır.

### MalzemeStok-Pro-03 — Stok girişi ayrı sayfa/modal
- **Amaç:** Giriş/Sevk/Düzeltme formları `malzeme_stok_giris.php`'ye (veya modala) taşınır; satırdan "Giriş Ekle" malzeme/depo ön-dolu açılır.
- **Dosyalar:** `malzeme_stok_giris.php` (yeni), `malzeme_stok.php`, `assets/app.js` (gerekirse).
- **Risk:** Orta — günlük veri girişi akışı değişiyor; form POST'ları aynı helper'ı kullanır.
- **Test:** 3 hareket tipinin kaydı + audit kaydı + flash; mobil 16px input; CSRF.
- **Kullanıcı etkisi:** Orta — giriş 1 tık uzaklaşır ama hedefli ve ön-dolu olur.

### MalzemeStok-Pro-04 — Admin düzeltme/iptal ekranı
- **Amaç:** `malzeme_stok_duzelt.php` (void/ters kayıt + onay + zorunlu gerekçe). `ms_delete` ve `ms_update` ana akıştan kaldırılır. Yeni yetkiler (`stok.correct`) seed edilir — **migration/seed GO gerektirir**.
- **Dosyalar:** `malzeme_stok_duzelt.php` (yeni), `config/helpers.php` (yetki seed), helper (void fonksiyonu), migration (kolonlar — ayrı GO).
- **Risk:** **Yüksek** — yazma davranışı değişiyor; mutlaka staging'de.
- **Test:** Operator'un silememesi/düzenleyememesi; void sonrası bakiye; audit kayıtları; loading kaynaklı hareketin korunması.
- **Kullanıcı etkisi:** Operator için kısıt (bilgilendirme gerekir), veri güvenliği için kazanç.

### MalzemeStok-Pro-05 — Hareket taşıma ekranı
- **Amaç:** `malzeme_stok_tasi.php` — yanlış ID/isimden doğru tanıma taşıma; tip ailesi doğrulaması; önizleme + onay.
- **Dosyalar:** `malzeme_stok_tasi.php` (yeni), helper (move fonksiyonu).
- **Risk:** Yüksek — material_id güncelleme; tek tek, önizlemeli, audit'li.
- **Test:** Taşıma sonrası iki malzemenin bakiyesi; tip ailesi dışına taşımanın engellenmesi; audit old/new değerleri.
- **Kullanıcı etkisi:** Kullanıcının 1 numaralı düzeltme ihtiyacı çözülür.

### MalzemeStok-Pro-06 — Teşhisin admin'e taşınması
- **Amaç:** Ana sayfadaki Veri Kalite + Sistem Audit panelleri `malzeme_stok_tehis.php`'ye taşınır; ana sayfada admin'e tek satır link kalır ("⚠ N veri sorunu → Teşhis"). Sayfa başına sorgu ~26 → ~8'e iner.
- **Dosyalar:** `malzeme_stok.php`, `malzeme_stok_tehis.php`.
- **Risk:** Düşük.
- **Test:** Viewer'ın teşhis görmediği; admin linkinin sayının doğru geldiği; tehis yetki kontrolü (`stok.diagnose`).
- **Kullanıcı etkisi:** Normal kullanıcı için belirgin sadeleşme + hız.

### MalzemeStok-Pro-07 — Mobil kart görünümü + profesyonel filtreler
- **Amaç:** `<768px` kart görünümü (Bölüm 6 şablonu), durum rozetleri, hızlı filtre pill'leri, "son hareket" kolonu.
- **Dosyalar:** `malzeme_stok.php`, `assets/style.css`, `sw.js` (v+1).
- **Risk:** Orta — `<768px` kuralları hassas (kritik kural); yalnızca yeni sınıflarla, mevcut breakpoint'lere dokunmadan.
- **Test:** iPhone/Android gerçek cihaz; iOS dikey scroll; safe-area; PWA cache yenileme (hard refresh senaryosu).
- **Kullanıcı etkisi:** Yüksek (olumlu) — saha kullanımı.

---

## 12. İlk Başlanması Gereken Sprint

**MalzemeStok-Pro-00 (helper katmanı).** Gerekçe:

1. UI'a dokunmadığı için risksiz başlangıçtır ve CSV karşılaştırmasıyla **kanıtlanabilir** şekilde doğrulanır.
2. Sonraki tüm sprintlerin (01, 02, 03, 04, 05) ortak bağımlılığıdır — özet hesabı tek kaynağa inmeden sayfa bölmek, hesap kopyalarını çoğaltır.
3. Bozuk `ms_duzeltme` ölü kodu bu adımda temizlenir (davranış değişikliği yok — zaten erişilemiyor).

Hemen ardından **Pro-06** (teşhis taşıma) öne alınabilir: düşük riskli, kullanıcıya en hızlı hissedilir sadeleşmeyi getirir.

---

## 13. Bu Refactor Sırasında KESİNLİKLE DokunulmaMASI Gerekenler

1. **`sync_malzeme_kullanim()`** (config/helpers.php:1355) — yükleme→kullanım hareketi üretimi ve idempotent silme/yazma mantığı. Stok hesabının ana veri kaynağı; refactor bu fonksiyonun ürettiği kayıtları okur, asla şeklini değiştirmez.
2. **`source_type='loading'` koruması** — bu hareketlerin düzenlenemez/silinemez olması (satır 319, 415) yeni ekranlarda da aynen korunmalı.
3. **material_id bazlı GROUP BY kök bugfix'i** (satır 482–506) — isim değişikliğine dayanıklılık. Helper'a taşınırken mantık birebir korunmalı; `material_id NULL` → isim fallback dahil.
4. **`normalize_text_v2` tabanlı tanım eşleme** — büyük harf/normalize standardı; yeni giriş ekranı da aynı eşlemeyi kullanmalı (LOWER değil).
5. **Eski hareket verileri** — hiçbir sprintte mevcut satırlar silinmez/toplu güncellenmez; tüm düzeltmeler yeni kayıt veya tekil, audit'li, onaylı işlemle.
6. **`< 768px` mobil kuralları ve `overflow-x: clip`** — mevcut breakpoint mimarisine yeni sınıf eklenir, var olanlar değiştirilmez.
7. **DB migration'lar** (yeni kolonlar, ENUM genişletme, yeni yetki seed'leri) — yalnızca açık **GO** ile; bu rapor yalnızca öneriyi içerir.
8. **`stok.php` (ürün stoğu)** — ayrı modüldür (`loading_pallets` bazlı ürün stoğu); malzeme stok refactor'u ona dokunmaz.
9. **CSV export formatları** (kolon sırası, `;` ayraç, BOM, ondalık format) — muhasebe tarafında kullanılıyor olabilir; sadeleştirmede format korunmalı.
10. **`audit_log_event()` çağrı deseni ve hassas veri filtreleri** — mevcut audit kapsamı daraltılmaz, yalnızca genişletilir (`void`, `move`).
