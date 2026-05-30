# Sprint Geçmişi

## Erken Sprintler (17–22)

| Sprint | Konu |
|---|---|
| 17 | records.php sayfalama, tarih filtresi |
| 18 | Ürün stok iyileştirme |
| 19 | Malzeme stok negatif uyarı + özet vurgusu |
| 20 | Kantar gruplama ortak helper, firma alias migrasyonu |
| 21 | Günlük operasyon raporu |
| 21A | KG virgülsüz gösterim (ekran tam sayı, CSV decimal korur) |
| 22 | Tanımlar ekranı (kasa/palet/malzeme CRUD) |

## Güvenlik ve Auth Sprintleri (23–30)

| Sprint | Konu |
|---|---|
| 23 | Login / logout / session |
| 24 | Rol & yetki sistemi (Admin/Operator/Viewer/Muhasebe) |
| 25 | Audit log altyapısı |
| 27 | Kullanıcı yönetim paneli (users.php) |
| 28A | Yükleme kaydı kilitleme + HKS auth fix + CSS modal fix |
| 28A-UI | Dashboard reorganizasyon, rapor kartları eklendi |
| 29A | P0 güvenlik: api_kalan write guard, kalan.js CSRF, notes permission, setup_admin/database.sql/hks/migrate.sql silindi, hks/.htaccess oluşturuldu |
| 29B | CLI guard (deploy/diagnostik/migrate), hesap modülü audit log (5 dosya), 17 migration artifact git'ten kaldırıldı, .gitignore güncellendi |
| 29C | `csrf_check()` JSON-aware hale getirildi, `is_json_request()` eklendi, api_kalan manual CSRF → merkezi |
| 30 | Dead try/catch temizliği (record_durum, audit), hks/.htaccess include-only sertleştirme |
| 30A | HKS api_send audit log eklendi (`hks_send` / `hks_send_failed`) |

## UI Sprintleri (31–32)

| Sprint | Konu |
|---|---|
| 31 | Desktop UI: ortak yapı taşları (container, page-head, card, data-table, home-card) CSS polish katmanı |
| 31B | KRİTİK BUGFIX: `min-width:768px { .mobile-only: none!important }` → `min-width:1024px`. 768–1023px arası liste tamamen boştu. Tablet 2-kolon kart grid eklendi. Filtre formu desktop'ta yatay düzen. |
| 32 | Masaüstü sabit sol sidebar: `render_desktop_sidebar()` helpers.php'de, ≥900px'de topbar gizlenir, sidebar 220px. ≥1280px'de 260px + 4-kolon grid. Permission'lar `can()`/`is_admin()` ile. Aktif menü `basename(PHP_SELF)` ile tespit. |
| 32A | Claude Code proje hafızası (CLAUDE.md + docs/) oluşturuldu |
