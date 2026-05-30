# Sıradaki Görevler

## P0 — Açık Kritik Sorun

Şu an bilinen P0 yok.

## P1 — Önemli, Yakın Dönem

| Görev | Notlar |
|---|---|
| Sidebar görünüm canlı doğrulama | ≥900px ekranda sidebar+topbar gizleme çalışıyor mu? Hard refresh gerekebilir. |
| `migrate_normalize_v2.php` CLI-only'ye geçiş | Web erişimi kapalı değil — güvenlik riski |
| DB credentials `.env`'e taşıma | `config/db.php` içinde hardcoded, teknik borç |
| Kantar kilitleme / stok sayım onayı | Operasyonel ihtiyaç, sprint planı yok |
| Hesap audit canlı doğrulama | Sprint 29B'de eklendi, DB'de gerçekten yazıyor mu? SQL ile kontrol: `SELECT * FROM audit_log WHERE module='hesap' ORDER BY id DESC LIMIT 5` |

## P2 — Orta Vadeli

| Görev | Notlar |
|---|---|
| Audit log retention | 180 gün → arşiv/sil. Cron job veya manuel script. |
| record_view N+1 düzeltmesi | Palet + materyal her palet için ayrı sorgu olabilir |
| CSS/JS formatter | `assets/style.css` ~4000 satır, belirli bölümler dağınık |
| Print sayfaları mobil test | record_view yazdırma iPhone'da test edilmeli |
| Sidebar `notes.php` modal | Desktop'ta hızlı not butonu yok (sidebar linki tam sayfaya götürüyor) |

## Ertelenmiş

| Görev | Neden |
|---|---|
| Git history SQL dump tarama | `git log --all --name-only \| grep -i '\.sql'` ile kontrol edilebilir — hassas ise history rewrite |
| HKS API credentials güvenliği | `HksConfig.php` incelenmedi |
