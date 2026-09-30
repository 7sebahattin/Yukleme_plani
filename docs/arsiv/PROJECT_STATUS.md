# Proje Durumu

Son güncelleme: Sprint 32A

## Tamamlanan Temel Altyapı

- [x] Login / logout / session yönetimi
- [x] Rol & yetki sistemi (Admin / Operator / Viewer / Muhasebe)
- [x] Kullanıcı yönetim paneli (users.php)
- [x] Audit log (audit.php — tüm write/delete/lock/unlock olayları)
- [x] Yükleme kaydı kilitleme (`yuklendi` → locked_at/by, `records.unlock` ile açılır)
- [x] CSRF — form ve JSON endpoint'lerde standart
- [x] P0 güvenlik: api_kalan write guard, notes permission, setup_admin/database.sql/migrate.sql kaldırıldı
- [x] JSON CSRF standardı — `csrf_check()` JSON-aware (403+JSON)
- [x] HKS include-only koruması (.htaccess)
- [x] CLI-only script guard (deploy.php, diagnostik.php, migrate.php)
- [x] Hesap modülü audit log
- [x] HKS api_send audit log (Sprint 30A)
- [x] Desktop sidebar navigasyon (Sprint 32 — ≥900px)
- [x] Mobile/tablet içerik görünüm bugfix (Sprint 31B — 768–1023px boş liste sorunu)

## Aktif Branch

`claude/fix-records-print-mobile-WuKdT`

## Bilinen Teknik Borç

- DB credentials hâlâ `config/db.php` içinde hardcoded (`.env`'e taşınmadı)
- Git history'de eski SQL dump kalıntısı olabilir
- Audit log retention mekanizması yok (180 gün arşivleme planlanıyor)
- `migrate_normalize_v2.php` dual-mode (CLI+web) kaldı — CLI-only'ye geçmedi
- record_view.php'de N+1 sorgu olabilir (palet + materyal çekimi)
