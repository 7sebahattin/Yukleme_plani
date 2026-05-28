-- ============================================================
-- NORMALIZE MİGRASYON — FORWARD PLAN
-- Oluşturulma: 2026-05-28 19:03:52
-- ÇALIŞTIRMADAN ÖNCE TAM YEDEK ALIN!
--
-- Adımlar:
--   1. mysqldump ile tam yedek al
--   2. Bu dosyayı transaction içinde çalıştır
--   3. Uygulamayı test et
--   4. Sorun yoksa rollback dosyasını sil
-- ============================================================

START TRANSACTION;

-- ─────────────────────────────────────────────────────────
-- [A] Sadece isim güncellemeleri (9 kayıt)
-- ─────────────────────────────────────────────────────────
  UPDATE `material_definitions` SET `name` = 'Plastik Kasa (standart)' WHERE `id` = 1;  -- [kasa_cinsi] "Plastik Kasa (Standart)" → "Plastik Kasa (standart)"
  UPDATE `material_definitions` SET `name` = 'Plastik Kasa (bã¼yã¼k)' WHERE `id` = 2;  -- [kasa_cinsi] "Plastik Kasa (BÃ¼yÃ¼k)" → "Plastik Kasa (bã¼yã¼k)"
  UPDATE `material_definitions` SET `name` = 'Tek Kullanä±mlä±k Palet' WHERE `id` = 7;  -- [palet_tipi] "Tek KullanÄ±mlÄ±k Palet" → "Tek Kullanä±mlä±k Palet"
  UPDATE `material_definitions` SET `name` = 'Plastik Kã¶åÿebent (4 Lã¼)' WHERE `id` = 9;  -- [kosebent] "Plastik KÃ¶ÅŸebent (4 lÃ¼)" → "Plastik Kã¶åÿebent (4 Lã¼)"
  UPDATE `material_definitions` SET `name` = 'Pp Åžerit' WHERE `id` = 10;  -- [serit] "PP Åžerit" → "Pp Åžerit"
  UPDATE `material_definitions` SET `name` = 'Taban Kaäÿä±dä±' WHERE `id` = 15;  -- [taban_kagidi] "Taban KaÄŸÄ±dÄ±" → "Taban Kaäÿä±dä±"
  UPDATE `material_definitions` SET `name` = 'Kã¶åÿe Karton' WHERE `id` = 18;  -- [kose_karton] "KÃ¶ÅŸe Karton" → "Kã¶åÿe Karton"
  UPDATE `material_definitions` SET `name` = 'Kraft Kaäÿä±t' WHERE `id` = 19;  -- [kraft_kagit] "Kraft KaÄŸÄ±t" → "Kraft Kaäÿä±t"
  UPDATE `material_definitions` SET `name` = 'Plastik (k-65)' WHERE `id` = 23;  -- [kasa_cinsi] "Plastik (K-65)" → "Plastik (k-65)"

COMMIT;

-- Etkilenen tablolar: material_definitions
-- TEXT UPDATE: 9 | FK merge: 0 | DELETE: 0
