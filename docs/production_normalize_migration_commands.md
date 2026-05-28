# Production Normalize Migration — Hızlı Komut Listesi

Tam adımlar ve bağlam için: [`production_normalize_migration_runbook.md`](./production_normalize_migration_runbook.md)

---

## ÖN KOŞUL KONTROL

```bash
# Queue durumu (pending = 0 olmalı)
mysql -u root yukleme_plani -e "
  SELECT status, COUNT(*) AS adet
  FROM normalize_migration_queue
  GROUP BY status;"

# SQL dosyaları mevcut mu?
ls -lh scripts/forward_migration_*.sql scripts/rollback_migration_*.sql
```

---

## BACKUP

```bash
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/yukleme_plani"
mkdir -p "$BACKUP_DIR"

# Tam DB yedeği
mysqldump --no-tablespaces --single-transaction \
  -u root yukleme_plani \
  > "$BACKUP_DIR/full_backup_${TIMESTAMP}.sql"

# Etkilenen tablolar ayrı
mysqldump --no-tablespaces --single-transaction \
  -u root yukleme_plani \
  material_definitions loading_pallets pallet_materials \
  material_stock_movements normalize_migration_queue \
  > "$BACKUP_DIR/affected_tables_${TIMESTAMP}.sql"

# Doğrula
ls -lh "$BACKUP_DIR/"
[ -s "$BACKUP_DIR/full_backup_${TIMESTAMP}.sql" ] \
  && echo "✓ BACKUP OK" || echo "✗ BACKUP BOŞ — DEVAM ETME"
```

---

## PRODUCTION ÖNCESİ BASELINE

```bash
mysql -u root yukleme_plani -e "
  -- Orphan FK var mı?
  SELECT 'lp_kasa' AS ref,
    COUNT(*) AS orphan FROM loading_pallets lp
    LEFT JOIN material_definitions md ON md.id=lp.kasa_cinsi_id
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
"
# Tümü 0 olmalı — değilse DEVAM ETME
```

---

## PLAN TAZELE

```bash
php scripts/plan_normalize_migration.php --output-dir scripts/

# Beklenen çıktı son satırı:
# "✓ Plan hazır. Henüz hiçbir veri değiştirilmedi."
```

---

## FORWARD SQL DOĞRULA

```bash
FWD=$(ls -t scripts/forward_migration_*.sql | head -1)
echo "--- Uygulancak: $FWD ---"

# İçeriği oku — START TRANSACTION / COMMIT var mı?
grep -E "^(START TRANSACTION|COMMIT|DROP TABLE|TRUNCATE)" "$FWD"

# Satır sayısı
wc -l "$FWD"
```

---

## FORWARD MIGRATION UYGULA

```bash
FWD=$(ls -t scripts/forward_migration_*.sql | head -1)
echo "Uygulanacak: $FWD"

read -p "Production'da çalıştır? (evet): " CONFIRM
[ "$CONFIRM" = "evet" ] || { echo "İptal."; exit 0; }

mysql -u root yukleme_plani < "$FWD"
echo "Çıkış kodu: $?"
# 0 = başarılı, diğer = ROLLBACK'E GEÇ
```

---

## PRODUCTION VALIDATION (Migration Sonrası)

```bash
mysql -u root yukleme_plani -e "
  SELECT 'U+0307 kalan'        AS kontrol, COUNT(*) AS sonuc
    FROM material_definitions WHERE HEX(name) LIKE '%CC87%'
  UNION ALL
  SELECT 'approved dup hala var', COUNT(*)
    FROM material_definitions md
    INNER JOIN normalize_migration_queue q ON q.target_id=md.id
    WHERE q.status='approved' AND q.is_merge=1 AND q.is_survivor=0
  UNION ALL
  SELECT 'orphan lp_kasa', COUNT(*)
    FROM loading_pallets lp
    LEFT JOIN material_definitions md ON md.id=lp.kasa_cinsi_id
    WHERE lp.kasa_cinsi_id IS NOT NULL AND md.id IS NULL
  UNION ALL
  SELECT 'orphan lp_palet', COUNT(*)
    FROM loading_pallets lp
    LEFT JOIN material_definitions md ON md.id=lp.palet_tipi_id
    WHERE lp.palet_tipi_id IS NOT NULL AND md.id IS NULL
  UNION ALL
  SELECT 'orphan pm_mat', COUNT(*)
    FROM pallet_materials pm
    LEFT JOIN material_definitions md ON md.id=pm.material_id
    WHERE pm.material_id IS NOT NULL AND md.id IS NULL
  UNION ALL
  SELECT 'orphan msm_mat', COUNT(*)
    FROM material_stock_movements msm
    LEFT JOIN material_definitions md ON md.id=msm.material_id
    WHERE msm.material_id IS NOT NULL AND md.id IS NULL
  UNION ALL
  SELECT 'yeni duplicate', COUNT(*)
    FROM (
      SELECT CONCAT(type,'||',LOWER(TRIM(name))) AS k, COUNT(*) AS n
      FROM material_definitions GROUP BY k HAVING n>1
    ) t;
"
# Tüm sonuçlar 0 olmalı
```

---

## ROLLBACK (Gerekirse)

```bash
# Hızlı rollback (SQL ile)
RBK=$(ls -t scripts/rollback_migration_*.sql | head -1)
echo "ROLLBACK: $RBK"
cat "$RBK"          # ÖNCE OKU
mysql -u root yukleme_plani < "$RBK"
echo "Rollback çıkış kodu: $?"

# --- VEYA ---

# Tam DB restore (acil durum)
BACKUP_DIR="/var/backups/yukleme_plani"
BACKUP=$(ls -t "$BACKUP_DIR"/full_backup_*.sql | head -1)
echo "RESTORE: $BACKUP"
mysql -u root yukleme_plani < "$BACKUP"
echo "Restore çıkış kodu: $?"
```

---

## ROLLBACK SONRASI KONTROL

```bash
mysql -u root yukleme_plani -e "
  SELECT COUNT(*) AS md_toplam FROM material_definitions;
  SELECT COUNT(*) AS u0307_geri_dondu
    FROM material_definitions WHERE HEX(name) LIKE '%CC87%';"
```

---

## STAGING TEST (Referans)

```bash
# Staging DB'de forward + rollback testi (production öncesi)
php scripts/test_staging_migration.php \
    --test-db yukleme_plani_staging \
    --yes

# Beklenen son satır:
# "✓ PRODUCTION İÇİN HAZIR"
```

---

## GO / NO-GO ÖZET

| Kontrol | Komut | Beklenen |
|---|---|---|
| pending = 0 | `SELECT status, COUNT(*) FROM normalize_migration_queue GROUP BY status` | pending=0 |
| Backup boyutu > 0 | `ls -lh $BACKUP_DIR/full_backup_*.sql` | > 0 KB |
| Baseline orphan FK = 0 | Yukarıdaki baseline sorgu | tümü 0 |
| Staging geçti | test_staging_migration.php çıktısı | "HAZIR" |
| Forward SQL temiz | `grep DROP\|TRUNCATE $FWD` | boş |
| Migration exit = 0 | `echo $?` | 0 |
| Post-validation | Yukarıdaki validation sorgu | tümü 0 |
