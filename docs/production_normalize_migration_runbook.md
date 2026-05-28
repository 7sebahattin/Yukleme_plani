# Production Normalize Migration Runbook

**Proje:** Yükleme Planı — `nuverna.derspros.com.tr`  
**Migration:** `normalize_text_v2` geçişi — Turkish-safe title case, U+0307 combining dot temizliği  
**Branch:** `claude/fix-records-print-mobile-WuKdT`  
**Oluşturulma:** Sprint 10  
**Durum:** HAZIRLIK — Canlıya geçiş için kullanıcı onayı bekliyor

---

> **⛔ BU DOKÜMANI OKUMADAN PRODUCTION'DA HİÇBİR ADIM UYGULAMA.**  
> Her adım sırayla yapılmalı. Bir adım başarısız olursa dur ve Rollback Planı'na bak.

---

## İçindekiler

1. [Ön Koşullar](#1-ön-koşullar)
2. [Backup Adımları](#2-backup-adımları)
3. [Production Öncesi Kontroller](#3-production-öncesi-kontroller)
4. [Maintenance Mode](#4-maintenance-mode)
5. [Çalıştırma Sırası](#5-çalıştırma-sırası)
6. [Production Validation](#6-production-validation)
7. [Rollback Planı](#7-rollback-planı)
8. [Canlı Sonrası Test Listesi](#8-canlı-sonrası-test-listesi)
9. [Karar Kapısı (GO / NO-GO)](#9-karar-kapısı)
10. [İletişim ve Onay](#10-iletişim-ve-onay)

---

## 1. Ön Koşullar

Her madde tamamlandıktan sonra `[x]` ile işaretle.

### Sprint Bağımlılıkları

- [ ] **Sprint 8 tamamlandı:** `scripts/plan_normalize_migration.php` çalıştırıldı, `forward_migration_*.sql` ve `rollback_migration_*.sql` dosyaları `scripts/` klasöründe mevcut.
- [ ] **Sprint 9 tamamlandı:** `scripts/test_staging_migration.php` çalıştırıldı, terminal çıktısında `✓ PRODUCTION İÇİN HAZIR` yazıyor.
- [ ] **Staging raporu saklandı:** `scripts/staging_report_yukleme_plani_staging_<ts>.txt` dosyası arşivlendi.

### Queue Durumu Kontrolü

`audit.php` → "Normalize Migration Review" panelini aç:

| Kontrol | Beklenen | Gerçek |
|---|---|---|
| Toplam queue kayıt | > 0 | _____ |
| `approved` kayıt | > 0 | _____ |
| `pending` kayıt | **0** | _____ |
| `excluded` kayıt | (kayıt altına al) | _____ |

> **Kural:** `pending` satır varsa DEVAM ETME. Tüm satırlar ya `approved` ya `excluded` olmalı.

### SQL Dosyaları Kontrolü

```bash
ls -lh scripts/forward_migration_*.sql scripts/rollback_migration_*.sql
```

- [ ] `forward_migration_*.sql` — dosya mevcut, boyut > 0
- [ ] `rollback_migration_*.sql` — dosya mevcut, boyut > 0
- [ ] İki dosyanın timestamp'i aynı Sprint 8 çalışmasından geliyor

### Yüksek Riskli Kayıt İncelemesi

`audit.php` panelinde "Sadece Yüksek Risk" filtresini seç:

- [ ] Tüm YÜKSEK riskli satırlar gözden geçirildi
- [ ] FK referansı olan her dup için surviving_id doğrulandı
- [ ] Kararsız kalan satırlar `excluded` yapıldı (onaylanmadı)

---

## 2. Backup Adımları

> Backup başarısız olursa hiçbir adıma devam etme.

### 2a. Tam DB Yedeği

```bash
# Production sunucusunda çalıştır
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/yukleme_plani"
mkdir -p "$BACKUP_DIR"

mysqldump \
    --no-tablespaces \
    --single-transaction \
    --routines \
    --triggers \
    -u root \
    yukleme_plani \
    > "$BACKUP_DIR/full_backup_${TIMESTAMP}.sql"

echo "Boyut: $(du -sh $BACKUP_DIR/full_backup_${TIMESTAMP}.sql)"
```

**Kontroller:**

```bash
# Dosya oluştu mu?
ls -lh "$BACKUP_DIR/full_backup_${TIMESTAMP}.sql"

# Boyut 0 değil mi?
[ -s "$BACKUP_DIR/full_backup_${TIMESTAMP}.sql" ] && echo "✓ Backup OK" || echo "✗ Backup BOŞ!"

# En azından tablo yaratma satırı var mı?
grep -c "CREATE TABLE" "$BACKUP_DIR/full_backup_${TIMESTAMP}.sql"
```

- [ ] Dosya mevcut
- [ ] Dosya boyutu > 0 KB
- [ ] `CREATE TABLE` satırı sayısı > 5

### 2b. Etkilenecek Tabloların Ayrı Yedeği

```bash
mysqldump \
    --no-tablespaces \
    --single-transaction \
    -u root \
    yukleme_plani \
    material_definitions \
    loading_pallets \
    pallet_materials \
    material_stock_movements \
    normalize_migration_queue \
    > "$BACKUP_DIR/affected_tables_${TIMESTAMP}.sql"

echo "Boyut: $(du -sh $BACKUP_DIR/affected_tables_${TIMESTAMP}.sql)"
```

- [ ] Etkilenen tablo yedeği alındı ve boyut > 0

### 2c. Backup Klasörü Kontrolü

```bash
ls -lh "$BACKUP_DIR/"
```

Runbook doldur:

| Dosya | Boyut | Durum |
|---|---|---|
| `full_backup_<ts>.sql` | _____ | [ ] OK |
| `affected_tables_<ts>.sql` | _____ | [ ] OK |

---

## 3. Production Öncesi Kontroller

### 3a. Queue Sayaçları (Son Kontrol)

```sql
SELECT status, COUNT(*) AS adet
FROM normalize_migration_queue
GROUP BY status;

SELECT
  SUM(is_merge = 1 AND is_survivor = 0)  AS dup_to_delete,
  SUM(is_merge = 1 AND is_survivor = 1)  AS survivors,
  SUM(will_change = 1 AND is_merge = 0)  AS text_only_update,
  SUM(risk_level = 'YÜKSEK')             AS yuksek_risk,
  SUM(u0307 = 1)                         AS u0307_count,
  SUM(fk_total > 0 AND is_merge = 1 AND is_survivor = 0) AS risky_fk_dups
FROM normalize_migration_queue
WHERE status = 'approved';
```

Runbook doldur:

| Metrik | Değer |
|---|---|
| approved | _____ |
| pending | _____ |
| excluded | _____ |
| Silinecek dup sayısı | _____ |
| FK taşıması gereken dup | _____ |
| YÜKSEK risk kayıt | _____ |
| U+0307 içeren kayıt | _____ |

> **Kural:** `pending > 0` ise **STOP → NO-GO**.

### 3b. Mevcut Duplicate Durumu

```sql
-- Normalize sonrası merge edilecek gruplar
SELECT type, COUNT(*) AS merge_group_count
FROM (
  SELECT type, LOWER(TRIM(name)) AS norm
  FROM material_definitions
  GROUP BY type, LOWER(TRIM(name))
  HAVING COUNT(*) > 1
) t
GROUP BY type;
```

### 3c. Orphan FK Kontrolü (Migration Öncesi Baseline)

```sql
-- Baseline: zaten broken FK var mı? (migration öncesi)
SELECT 'lp_kasa'  AS ref,
       COUNT(*) AS orphan FROM loading_pallets lp
       LEFT JOIN material_definitions md ON md.id = lp.kasa_cinsi_id
       WHERE lp.kasa_cinsi_id IS NOT NULL AND md.id IS NULL
UNION ALL
SELECT 'lp_palet', COUNT(*) FROM loading_pallets lp
       LEFT JOIN material_definitions md ON md.id = lp.palet_tipi_id
       WHERE lp.palet_tipi_id IS NOT NULL AND md.id IS NULL
UNION ALL
SELECT 'pm_mat', COUNT(*) FROM pallet_materials pm
       LEFT JOIN material_definitions md ON md.id = pm.material_id
       WHERE pm.material_id IS NOT NULL AND md.id IS NULL
UNION ALL
SELECT 'msm_mat', COUNT(*) FROM material_stock_movements msm
       LEFT JOIN material_definitions md ON md.id = msm.material_id
       WHERE msm.material_id IS NOT NULL AND md.id IS NULL;
```

> **Kural:** Migration öncesi orphan FK = 0 olmalı. Değilse önce repair_kasa_ids.php ile düzelt.

---

## 4. Maintenance Mode

Uygulamada resmi maintenance mode yoktur. Aşağıdaki önlemleri al:

### 4a. Kullanıcı Uyarısı

Migration başlamadan en az **5 dakika önce** aktif kullanıcılara bildir:

```
"Sistem 5 dakika içinde kısa bakıma girecek (~2-3 dakika).
 Lütfen şu an kayıt girmeyin, açık formları kaydedin."
```

### 4b. Aktif Session Kontrolü

```bash
# PHP session dosyalarını kontrol et (son 5 dakikada aktif)
find /var/lib/php/sessions/ -name "sess_*" -newer /tmp -mmin -5 | wc -l
```

- [ ] Aktif session sayısı ≤ 1 (sadece sen)

### 4c. Migration Süresi Tahmini

Migration süresi lineerdir. Kaba tahmin:

| İşlem türü | Tahmini süre |
|---|---|
| TEXT_UPDATE (her kayıt) | ~1 ms |
| FK taşıma (her ref) | ~5 ms |
| DELETE | ~1 ms |
| DB transaction commit | ~50 ms |
| **Toplam (tipik)** | **< 30 saniye** |

> Migration transaction içinde çalışır — ya tamamı commit olur ya hiçbiri.

---

## 5. Çalıştırma Sırası

> Her adımı sırayla, atlamadan uygula.

### Adım 1 — Plan'ı Tazele

```bash
# Production sunucusunda scripts/ klasöründen
php scripts/plan_normalize_migration.php \
    --output-dir scripts/

# Çıktıyı kontrol et:
# - "Onaylı kayıt sayısı: N" (beklenen N ile eşleşmeli)
# - "✓ Plan hazır. Henüz hiçbir veri değiştirilmedi."
# - Yeni forward_migration_<ts>.sql ve rollback_migration_<ts>.sql oluştu
```

- [ ] Plan çıktısı beklenen kayıt sayısıyla eşleşiyor
- [ ] Yeni SQL dosyaları oluştu

### Adım 2 — Forward SQL'i Doğrula

```bash
# Dosyayı oku — içeriği gözden geçir
FWD=$(ls -t scripts/forward_migration_*.sql | head -1)
cat "$FWD"
```

Kontrol listesi:

- [ ] `START TRANSACTION;` ile başlıyor
- [ ] `COMMIT;` ile bitiyor
- [ ] Beklenen tablo adları var (`material_definitions`, `loading_pallets` vb.)
- [ ] Beklenmedik `DROP TABLE` veya `TRUNCATE` yok
- [ ] Satır sayısı makul (çok az veya çok fazla değil)

### Adım 3 — Production Migration

```bash
FWD=$(ls -t scripts/forward_migration_*.sql | head -1)
echo "Uygulanacak dosya: $FWD"

# SON ONAY
read -p "Production'da çalıştırılsın mı? (evet): " confirm
[ "$confirm" = "evet" ] || { echo "İptal."; exit 0; }

mysql -u root yukleme_plani < "$FWD"
echo "Çıkış kodu: $?"
```

- [ ] Çıkış kodu = 0 (başarılı)
- [ ] Hata mesajı yok

### Adım 4 — Hemen Ardından Validation

> Adım 3 başarısız olursa Adım 4'e geçme → Rollback Planı'na git.

```bash
php scripts/test_staging_migration.php \
    --test-db yukleme_plani \
    --skip-rollback \
    --yes \
    --forward "$FWD"
```

> **Not:** Bu komut validation-only modda çalışır (`--skip-rollback` ve production DB verildiğinde
> yalnızca doğrulama yapması için aşağıdaki ek güvenlik adımını da kontrol et.)

**Alternatif — Manuel validation SQL:**

```sql
-- V1: U+0307 kaldı mı?
SELECT COUNT(*) FROM material_definitions WHERE HEX(name) LIKE '%CC87%';
-- Beklenen: 0

-- V2: approved dup'lar silindi mi?
SELECT COUNT(*) FROM material_definitions md
INNER JOIN normalize_migration_queue q ON q.target_id = md.id
WHERE q.status = 'approved' AND q.is_merge = 1 AND q.is_survivor = 0;
-- Beklenen: 0

-- V3: Orphan FK (kasa)
SELECT COUNT(*) FROM loading_pallets lp
LEFT JOIN material_definitions md ON md.id = lp.kasa_cinsi_id
WHERE lp.kasa_cinsi_id IS NOT NULL AND md.id IS NULL;
-- Beklenen: 0

-- V4: Orphan FK (palet)
SELECT COUNT(*) FROM loading_pallets lp
LEFT JOIN material_definitions md ON md.id = lp.palet_tipi_id
WHERE lp.palet_tipi_id IS NOT NULL AND md.id IS NULL;
-- Beklenen: 0

-- V5: Orphan FK (pallet_materials)
SELECT COUNT(*) FROM pallet_materials pm
LEFT JOIN material_definitions md ON md.id = pm.material_id
WHERE pm.material_id IS NOT NULL AND md.id IS NULL;
-- Beklenen: 0

-- V6: Orphan FK (material_stock_movements)
SELECT COUNT(*) FROM material_stock_movements msm
LEFT JOIN material_definitions md ON md.id = msm.material_id
WHERE msm.material_id IS NOT NULL AND md.id IS NULL;
-- Beklenen: 0

-- V7: Yeni duplicate var mı?
SELECT type, LOWER(TRIM(name)) AS norm, COUNT(*) AS n
FROM material_definitions
GROUP BY type, LOWER(TRIM(name))
HAVING n > 1;
-- Beklenen: boş sonuç
```

Validation sonuçları:

| Kontrol | Beklenen | Gerçek | Durum |
|---|---|---|---|
| U+0307 sayısı | 0 | _____ | [ ] |
| Approved dup hâlâ mevcut | 0 | _____ | [ ] |
| Orphan FK (kasa) | 0 | _____ | [ ] |
| Orphan FK (palet) | 0 | _____ | [ ] |
| Orphan FK (pallet_materials) | 0 | _____ | [ ] |
| Orphan FK (msm) | 0 | _____ | [ ] |
| Yeni duplicate | 0 grup | _____ | [ ] |

> **Kural:** Herhangi bir kontrol başarısız → derhal Rollback Planı'na git.

---

## 6. Production Validation

Tüm SQL kontrolleri geçtikten sonra tarayıcıda manuel test:

### 6a. Temel Sayfalar

| Sayfa | URL | Beklenen | Durum |
|---|---|---|---|
| Ana Sayfa | `index.php` | Açılıyor, hata yok | [ ] |
| Yükleme Listesi | `records.php` | Kayıtlar görünüyor | [ ] |
| Çıkma Listesi | `cikmalar.php` | Kayıtlar görünüyor | [ ] |
| Kantar | `kantar.php` | Açılıyor | [ ] |
| Tanımlar | `definitions.php` | Tüm sekmeler | [ ] |
| Tanımlar → Firmalar | `definitions.php?type=firma` | Firma listesi | [ ] |
| Tanımlar → Depolar | `definitions.php?type=depo` | Depo listesi | [ ] |
| Ürün Stok | `stok.php` | Açılıyor | [ ] |
| Malzeme Stok | `stok.php` (malzeme bölümü) | Açılıyor | [ ] |
| Audit | `audit.php` | Açılıyor, hata yok | [ ] |

### 6b. Filtre Testleri

| Test | Adım | Beklenen |
|---|---|---|
| Firma filtresi | `stok.php`'de firma adı yaz | Sonuç geliyor |
| Depo filtresi | `stok.php`'de depo adı yaz | Sonuç geliyor |
| Ürün filtresi | `stok.php`'de ürün adı yaz | Sonuç geliyor |
| Kantar eşleşmesi | Var olan bir kantar fişini kontrol et | Firma/depo adı görünüyor |

- [ ] Firma filtresi çalışıyor
- [ ] Depo filtresi çalışıyor
- [ ] Ürün filtresi çalışıyor
- [ ] Kantar ↔ yükleme eşleşmesi çalışıyor

### 6c. Audit Paneli Kontrolleri

`audit.php` → "Normalize Migration Review" paneli:

- [ ] `approved` sayısı değişmedi (migration queue dokunulmadı)
- [ ] U+0307 sayacı = 0 (veya dramatik düşüş)
- [ ] Yeni `pending` kayıt çıkmadı

### 6d. normalize_text_v2 Kontrolü

`audit.php` → "Normalize Simülasyonu" bölümü:

- [ ] Tüm material_definitions isimler için "Fark var" = 0 (veya yalnızca excluded olanlar)

---

## 7. Rollback Planı

### Ne Zaman Rollback?

| Durum | Yanıt |
|---|---|
| Forward SQL hata verdi (exit ≠ 0) | `rollback_migration_*.sql` çalıştır |
| Validation'da orphan FK bulundu | Tam DB restore |
| Uygulama sayfaları hata veriyor | Hata türüne bak (önce log) |
| Performans dramatik düştü | Önce log, sonra karar |
| Birden fazla validation başarısız | Tam DB restore |

### 7a. Hızlı Rollback (rollback SQL)

> Kullanım koşulu: Forward migration uygulandı ama yalnızca isim/merge sorunu var,  
> yeni üretim kaydı GİRİLMEDİ.

```bash
RBK=$(ls -t scripts/rollback_migration_*.sql | head -1)
echo "Uygulanacak rollback: $RBK"
cat "$RBK"  # ÖNCE OKU

mysql -u root yukleme_plani < "$RBK"
echo "Rollback çıkış kodu: $?"
```

**Rollback sonrası kontrol:**

```sql
-- Silinen dup'lar geri döndü mü?
SELECT COUNT(*) FROM material_definitions md
INNER JOIN normalize_migration_queue q ON q.target_id = md.id
WHERE q.status = 'approved' AND q.is_merge = 1 AND q.is_survivor = 0;
-- Beklenen: approved dup sayısına eşit (geri döndü)

-- Temel sayı eskiye döndü mü?
SELECT COUNT(*) FROM material_definitions;
```

### 7b. Tam DB Restore (Acil Durum)

> Kullanım koşulu: Rollback SQL yeterli değil, veriler bozuldu.

```bash
BACKUP_DIR="/var/backups/yukleme_plani"
BACKUP=$(ls -t "$BACKUP_DIR"/full_backup_*.sql | head -1)
echo "Restore edilecek: $BACKUP"

# Uygulamayı durdur (Apache/Nginx)
# ...

mysql -u root yukleme_plani < "$BACKUP"
echo "Restore çıkış kodu: $?"
```

### 7c. Rollback Riskleri

| Risk | Açıklama |
|---|---|
| **FK merge geri dönmez** | Migration sonrası sisteme girilen yeni kayıtlar surviving_id'yi kullanmış olabilir. Rollback bunları dup_id'ye çeker — yanlış referans. |
| **Rollback SQL isim değişikliklerini geri alır** | `normalize_text_v2` formatındaki isimler eski formata döner — U+0307 tekrar çıkabilir. |
| **Tam restore migration öncesi duruma alır** | Migration sonrası girilen TÜM yeni kayıtlar kaybedilir. |

> **Öneri:** Migration sırasında (Adım 3–4 arası) yeni kayıt girilmemesi bu riskleri sıfırlar.

### 7d. Rollback Sonrası Kontroller

```sql
-- Material definitions sayısı backup ile eşleşiyor mu?
SELECT COUNT(*) FROM material_definitions;

-- U+0307 geri döndü mü (beklenir — eski format)
SELECT COUNT(*) FROM material_definitions WHERE HEX(name) LIKE '%CC87%';
```

- [ ] Uygulama sayfaları açılıyor
- [ ] Kayıt ekleme/düzenleme çalışıyor
- [ ] audit.php hatası yok

---

## 8. Canlı Sonrası Test Listesi

Migration başarıyla tamamlandıktan sonra tam fonksiyon testi:

### 8a. Yeni Kayıt Ekleme

- [ ] **Yeni Kantar Kaydı:** `kantar.php` → yeni fiş oluştur → kaydet → listede görünüyor
- [ ] **Yeni Yükleme Kaydı:** `record_create.php` → palet ekle → kaydet → `records.php`'de görünüyor
- [ ] **Yeni Çıkma Kaydı:** `cikma_create.php` → kaydet → `cikmalar.php`'de görünüyor

### 8b. Filtreler ve Eşleşmeler

- [ ] **Ürün filtreleme:** `stok.php` → ürün adı yaz → doğru sonuç
- [ ] **Depo filtreleme:** `stok.php` → depo adı yaz → doğru sonuç
- [ ] **Firma filtreleme:** `stok.php` → firma adı yaz → doğru sonuç
- [ ] **Kantar ↔ Yükleme eşleşmesi:** `stok.php` → bir firmayı kontrol et → kantar ve yükleme verileri tutarlı

### 8c. Tanımlar Duplicate Engeli

- [ ] `definitions.php?type=firma` → mevcut bir firma adını tekrar eklemeye çalış → hata mesajı çıkıyor
- [ ] `definitions.php?type=depo` → aynı test → hata mesajı çıkıyor
- [ ] `definitions.php` (malzeme) → aynı tür ve isimde ekle → hata mesajı çıkıyor

### 8d. Malzeme Stok

- [ ] **Stok hareketi:** Var olan bir yükleme kaydını düzenle → kaydet → `material_stock_movements` güncellendi
- [ ] `audit.php` → "Stok Tutarsızlığı" bölümü → hata yok
- [ ] `audit.php` → "Normalize Migration Review" → `approved` sayısı değişmedi

### 8e. Mobil Kontrol

- [ ] Ana sayfa mobilde açılıyor (bottom nav görünüyor)
- [ ] `records.php` mobilde scroll çalışıyor (yatay taşma yok)
- [ ] Palet modal mobilde açılıp kapanıyor

---

## 9. Karar Kapısı

### GO — Production Migration'a İzin Ver

Tüm maddeler işaretli olduğunda GO:

- [ ] Staging testi geçti (`✓ PRODUCTION İÇİN HAZIR`)
- [ ] Queue'da `pending = 0`
- [ ] Backup alındı, boyut > 0, `CREATE TABLE` satırı var
- [ ] Production öncesi orphan FK = 0 (baseline temiz)
- [ ] Yüksek riskli tüm kayıtlar gözden geçirildi
- [ ] Forward SQL manuel incelendi, beklenmedik komut yok
- [ ] Kullanıcı/ekip uyarıldı (5 dk maintenance)
- [ ] **Kullanıcı onayı alındı**

### NO-GO — Dur

Aşağıdakilerden biri geçerliyse NO-GO:

- [ ] Backup alınamadı veya boyut = 0
- [ ] Staging testi başarısız (`✗` çıktısı var)
- [ ] Queue'da hâlâ `pending` kayıt var
- [ ] Production öncesi orphan FK > 0 (repair gerekiyor)
- [ ] Forward SQL'de beklenmedik `DROP`/`TRUNCATE` var
- [ ] Yüksek riskli kayıtlar incelenmemiş, `approved` yapılmış
- [ ] Kullanıcı onayı yok

---

## 10. İletişim ve Onay

### Migration Öncesi Onay

| Onaylayan | İmza / Tarih |
|---|---|
| Sistem yöneticisi | _________________ |
| Proje sahibi | _________________ |

### Migration Sonucu

| Alan | Değer |
|---|---|
| Migration tarihi | _________________ |
| Uygulanan dosya | `forward_migration_______` |
| Süre (sn) | _________________ |
| Sonuç | [ ] BAŞARILI / [ ] ROLLBACK YAPILDI |
| Notlar | _________________ |

---

## Ekler

### Hızlı Referans — Kritik SQL Sorguları

```sql
-- Queue özeti
SELECT status, COUNT(*) FROM normalize_migration_queue GROUP BY status;

-- U+0307 kontrolü
SELECT COUNT(*) FROM material_definitions WHERE HEX(name) LIKE '%CC87%';

-- Duplicate kontrolü
SELECT type, LOWER(TRIM(name)) norm, COUNT(*) n
FROM material_definitions GROUP BY type, LOWER(TRIM(name)) HAVING n>1;

-- Tüm orphan FK'ler
SELECT 'lp_kasa' ref, COUNT(*) orphan
  FROM loading_pallets lp LEFT JOIN material_definitions md ON md.id=lp.kasa_cinsi_id
  WHERE lp.kasa_cinsi_id IS NOT NULL AND md.id IS NULL
UNION ALL
SELECT 'lp_palet', COUNT(*) FROM loading_pallets lp
  LEFT JOIN material_definitions md ON md.id=lp.palet_tipi_id
  WHERE lp.palet_tipi_id IS NOT NULL AND md.id IS NULL
UNION ALL
SELECT 'pm_mat', COUNT(*) FROM pallet_materials pm
  LEFT JOIN material_definitions md ON md.id=pm.material_id
  WHERE pm.material_id IS NOT NULL AND md.id IS NULL
UNION ALL
SELECT 'msm_mat', COUNT(*) FROM material_stock_movements msm
  LEFT JOIN material_definitions md ON md.id=msm.material_id
  WHERE msm.material_id IS NOT NULL AND md.id IS NULL;
```

### İlgili Dosyalar

| Dosya | Açıklama |
|---|---|
| `audit.php` | Normalize Migration Review paneli (UI) |
| `scripts/plan_normalize_migration.php` | Forward/rollback SQL üretici (Sprint 8) |
| `scripts/test_staging_migration.php` | Staging test & validation (Sprint 9) |
| `scripts/forward_migration_<ts>.sql` | Uygulanacak migration |
| `scripts/rollback_migration_<ts>.sql` | Geri alma planı |
| `config/helpers.php` | `normalize_text_v2()` tanımı |
| `docs/production_normalize_migration_commands.md` | Hızlı komut listesi |
