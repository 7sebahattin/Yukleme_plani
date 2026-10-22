# Sıradaki Görevler

## P0 — Açık Kritik Sorun

Şu an bilinen P0 yok.

## P1 — Önemli, Yakın Dönem

| Görev | Notlar |
|---|---|
| Sidebar görünüm canlı doğrulama | ≥900px ekranda sidebar+topbar gizleme çalışıyor mu? Hard refresh gerekebilir. |
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
| HKS belirsiz gönderim — artçı işler (v417 sonrası) | (1) Beyan köprüsü: belirsiz kalan gönderimde HKS'te künye bulununca "gönderildi say" (Gönderilenler kaydı + `beyan_hks_taslak_isaretle('gonderildi')`) ayrı eylem olarak yok; (2) SIGKILL / proxy'nin süreci öldürmesinde kapanış güvencesi (`register_shutdown_function`) çalışmaz — write-ahead "gönderim niyeti" kv kaydı düşünülebilir; (3) TopluKunye'nin referanssız Satın Alım künyelerini listelediği canlıda kanıtlanmadı — boş sonuç kesin kanıt değil, `hks_yaptigim_penceresi` ikinci kaynak olabilir. |
