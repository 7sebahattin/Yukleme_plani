# Normalize Migration Review — Panel Kullanım Rehberi

**Nerede:** `audit.php` → sayfanın en altı → "🔬 Normalize Migration Review" bölümü  
**Amaç:** Her kaydı inceleyip **Onayla** veya **Hariç Tut** kararı ver. `pending = 0` olunca bitti.

---

> **Altın Kural: Emin değilsen → Hariç Tut.**  
> Hariç tutulan kayıt migration'a girmez. Daha sonra tekrar incelenebilir.  
> Yanlış onaylanan kayıt geri almak daha zordur.

---

## Adım 0 — Paneli Aç

Eğer panel "Henüz analiz yapılmamış" görünüyorsa:

1. **"Analizi Başlat"** butonuna tıkla.
2. Sayfa yüklendikten sonra tablo dolar.
3. Sayaç gösterir: `X bekliyor · Y onaylı · Z hariç`

Paneli daha önce açtıysan ve kayıtlar varsa doğrudan Adım 1'e geç.

---

## Adım 1 — İlk Önce Yüksek Riskli Kayıtları İncele

**Filtre:** `Sadece Yüksek Risk` → seç.

Bu kayıtlar merge işlemi gerektirir **ve** FK referansı taşınacaktır.  
Yani bir tanım silinecek, onun yerine başka bir tanım geçecek.

Her satırda şunlara bak:

| Sütun | Ne demek? |
|---|---|
| **Mevcut Değer** | Şu an veritabanındaki isim |
| **V2 Sonucu** | Migration sonrası olacak isim |
| **Rol** | `survivor` = kalacak / `dup` = silinecek |
| **Surviving ID** | Hangi tanım hayatta kalacak |
| **Dup ID** | Hangi tanım silinecek |
| **FK Etkisi** | Kaç palet/malzeme satırı taşınacak |
| **Risk** | YÜKSEK = FK taşıma var |

### Yüksek Riskli Kayıtta Karar Ver

**→ Onayla:** İkisi gerçekten aynı şeyse.  
Örnek: `KARAMAN CİHAT` ve `Karaman Cihat` → aynı firma, sadece büyük/küçük harf farkı.

**→ Hariç Tut:** Farklı olabileceklerine dair en ufak şüphe varsa.  
Örnek: `Cihat` ve `Cihat Karaköse` → farklı firmalar olabilir.

---

## Adım 2 — Merge Kayıtlarını İncele

**Filtre:** `Sadece Merge` → seç.

Merge = iki tanım normalize edilince aynı sonuca dönüşüyor. Biri silinecek, biri kalacak.

### Surviving ID / Dup ID Nedir?

```
Örnek:
  ID=12  "KAYISI"        → survivor  (kalır, ismi "Kayısı" olur)
  ID=47  "Kayısı "       → dup       (silinir, FK'ları ID=12'ye taşınır)
```

- **Survivor:** En çok FK referansı olan kazanır (eşitlik: küçük ID).
- **Dup:** Silinecek olan. FK'ları survivor'a taşınır.

### Merge Kararı İçin Kontrol Listesi

- [ ] Mevcut Değer ve V2 Sonucu aynı anlama mı geliyor?
- [ ] FK Etkisi sütununa bak — kaç kayıt taşınacak? (çok fazlaysa dikkatli ol)
- [ ] Surviving ID'nin temsil ettiği tanım doğru mu?
- [ ] Dup silinirse operasyon etkilenir mi?

---

## Adım 3 — U+0307 İçerenleri Temizle

**Filtre:** `Sadece U+0307` → seç.

U+0307 = görünmez unicode karakteri. `İ` harfinin yanlış kodlanmasından oluşur.  
Gözle fark edilmez ama string karşılaştırmalarını bozar.

### Karar

Bu kayıtlar genellikle **güvenli approve**:

- Mevcut Değer ile V2 Sonucu gözle aynı görünüyorsa → **Onayla**
- Merge yoksa (is_merge = 0) → neredeyse her zaman güvenli
- Merge varsa → Adım 2'deki merge kontrolünü yap

---

## Adım 4 — Kalan Pending Kayıtları Gözden Geçir

**Filtre:** Filtre yok (tümünü göster) → Status: `pending` → seç.

Sırayla her satırı incele. İki basit soru sor:

### Soru 1 — Değişiklik sadece Türkçe karakter düzeltmesi mi?

| Durum | Örnek | Karar |
|---|---|---|
| Sadece büyük/küçük harf | `ELMA` → `Elma` | ✅ Onayla |
| Sadece U+0307 temizliği | `İnci` (bozuk) → `İnci` | ✅ Onayla |
| Türkçe ı/i/İ/I düzeltmesi | `IZMIR` → `Izmir` | ✅ Onayla |
| Başında/sonunda boşluk | `"Elma "` → `"Elma"` | ✅ Onayla |

### Soru 2 — Merge mi? İki kayıt gerçekten aynı mı?

| Durum | Örnek | Karar |
|---|---|---|
| Aynı şey, farklı yazım | `merkez depo` / `Merkez Depo` | ✅ Onayla |
| Biri kısaltma | `Ck` / `Cihat Karaköse` | ⛔ Hariç Tut |
| Biri eksik isim | `Cihat` / `Karaman Cihat` | ⛔ Hariç Tut |
| Farklı lokasyon | `Soğuk Hava` / `Soğukhava Deposu` | ⛔ Hariç Tut |
| Emin değilim | — | ⛔ Hariç Tut |

---

## Hangi Kayıtlar Kesinlikle ONAYLA

- ✅ Mevcut değer ve V2 sonucu gözle bakınca tamamen aynı (sadece unicode farkı)
- ✅ Merge yok, sadece büyük/küçük harf normalleştirmesi
- ✅ U+0307 içeriyor, merge yok, aynı anlam
- ✅ Aynı firma/depo/ürün olduğunu kesin biliyorsun

## Hangi Kayıtlar Kesinlikle HARIÇ TUT

- ⛔ İki farklı firma adı olabilir (Cihat vs Karaman Cihat)
- ⛔ Biri diğerinin kısaltması (MDP vs Merkez Depo)
- ⛔ Farklı depolar aynı ada sahip olabilir (Merkez Depo = İstanbul'daki mi, Konya'daki mi?)
- ⛔ Operasyonel olarak ayrı kullanılan iki kayıt merge edilirse raporlar bozulabilir
- ⛔ FK etkisi çok yüksek (100+ satır taşınacak) ve tam emin değilsin
- ⛔ **Herhangi bir şüphe varsa**

---

## Panel Tamamlama Kontrol Listesi

Tüm kayıtları inceledikten sonra:

- [ ] Sayaçta `bekliyor = 0` görünüyor
- [ ] `onaylı` sayısını not al: _____
- [ ] `hariç` sayısını not al: _____
- [ ] Toplam (onaylı + hariç) = başlangıçtaki toplam ile eşleşiyor

`bekliyor = 0` olana kadar panelden çıkma.

---

## Hızlı Toplu İşlemler (Dikkatli Kullan)

Panelin üst kısmında iki toplu buton vardır:

| Buton | Ne yapar | Ne zaman kullan |
|---|---|---|
| **FK-siz hepsini onayla** | FK etkisi = 0 olan tüm pending'leri onayla | İlk tek tek incelemeden sonra, geri kalanlar açıkça güvenliyse |
| **Tümünü onayla** | TÜM pending'leri onayla | Yalnızca tüm kayıtları bizzat inceledikten ve emin olduktan sonra |

> ⚠️ Toplu onayla butonunu inceleme yapmadan kullanma.  
> Önce yüksek riskli ve merge kayıtları tek tek gözden geçir.

---

## Panel Bittikten Sonra — Çalıştırılacak Komutlar

Panel tamamlandı, `bekliyor = 0`. Sırayla:

### Komut 1 — Migration planını üret

```bash
php scripts/plan_normalize_migration.php
```

Beklenen çıktı son satırı:
```
✓ Plan hazır. Henüz hiçbir veri değiştirilmedi.
```

Oluşan dosyaları not al:
- `scripts/forward_migration_<timestamp>.sql`
- `scripts/rollback_migration_<timestamp>.sql`
- `scripts/approved_migration_plan_<timestamp>.csv`

### Komut 2 — Staging testini çalıştır

```bash
# Staging DB yoksa önce oluştur:
mysql -u root -e "CREATE DATABASE yukleme_plani_staging CHARACTER SET utf8mb4;"

# Production'ı staging'e kopyala (ilk seferde):
php scripts/test_staging_migration.php \
    --test-db yukleme_plani_staging \
    --clone \
    --yes

# Test çalıştır:
php scripts/test_staging_migration.php \
    --test-db yukleme_plani_staging
```

### Beklenen Son Çıktı

```
── 8. Production için hazır mı? ─────────────────────────
  ✓ Tüm doğrulama kontrolleri geçti.
  Rollback durumu: ✓ Rollback çalışıyor

  ✓ PRODUCTION İÇİN HAZIR
```

---

## Bu Çıktıyı Görene Kadar Production'a Geçme

```
┌──────────────────────────────────────────────────────┐
│                                                      │
│   "✓ PRODUCTION İÇİN HAZIR"                         │
│                                                      │
│   görülmeden production migration uygulanmaz.        │
│                                                      │
│   Sonraki adım:                                      │
│   docs/production_normalize_migration_runbook.md     │
│   → Bölüm 9: Karar Kapısı → GO işaretle             │
│   → Kullanıcı onayı ver                              │
│                                                      │
└──────────────────────────────────────────────────────┘
```

---

## Sorun Giderme

| Durum | Çözüm |
|---|---|
| Panel boş, kayıt yok | "Yenile" butonuna tıkla |
| Onayla butonu grileşmiyor | Sayfayı yenile (F5) |
| Hatalı karar verdim | Satırın "↺ sıfırla" butonuna tıkla → tekrar pending olur |
| Toplu onay yanlış yaptım | Her satırda "↺ sıfırla" ile tek tek geri al |
| Staging DB yok | Yukarıdaki `CREATE DATABASE` komutunu çalıştır |
| Staging testi hata veriyor | `scripts/staging_report_*.txt` dosyasını incele |
