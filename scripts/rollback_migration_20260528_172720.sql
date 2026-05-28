-- ============================================================
-- NORMALIZE MİGRASYON — ROLLBACK PLAN
-- SADECE forward migration uygulandıktan sonra kullanın.
--
-- ⚠  UYARI: FK merge rollback'i garantili değildir.
--    Forward migration'dan sonra oluşan yeni kayıtlar
--    surviving_id'yi referans alacağından bunlar da geri
--    döner. Manuel kontrol gerektirir.
-- ============================================================

START TRANSACTION;

-- ─────────────────────────────────────────────────────────
-- [A] Rollback — isim geri yükleme
-- ─────────────────────────────────────────────────────────
  UPDATE `material_definitions` SET `name` = 'Plastik Kasa (Standart)' WHERE `id` = 1;  -- rollback id=1
  UPDATE `material_definitions` SET `name` = 'Plastik Kasa (BÃ¼yÃ¼k)' WHERE `id` = 2;  -- rollback id=2
  UPDATE `material_definitions` SET `name` = 'Tek KullanÄ±mlÄ±k Palet' WHERE `id` = 7;  -- rollback id=7
  UPDATE `material_definitions` SET `name` = 'Plastik KÃ¶ÅŸebent (4 lÃ¼)' WHERE `id` = 9;  -- rollback id=9
  UPDATE `material_definitions` SET `name` = 'PP Åžerit' WHERE `id` = 10;  -- rollback id=10
  UPDATE `material_definitions` SET `name` = 'Taban KaÄŸÄ±dÄ±' WHERE `id` = 15;  -- rollback id=15
  UPDATE `material_definitions` SET `name` = 'KÃ¶ÅŸe Karton' WHERE `id` = 18;  -- rollback id=18
  UPDATE `material_definitions` SET `name` = 'Kraft KaÄŸÄ±t' WHERE `id` = 19;  -- rollback id=19
  UPDATE `material_definitions` SET `name` = 'Plastik (K-65)' WHERE `id` = 23;  -- rollback id=23

COMMIT;

-- Rollback tamamlandı.
