---
name: whatsapp-modulu
description: WhatsApp Yükleme Planı modülü (v582; whatsapp_yonetim.php, api_whatsapp_webhook.php, config/whatsapp_*.php, WhatsAppService.php, AIParserService.php, whatsapp-service/, docs/sql/whatsapp_migration.sql): Evolution API v2 köprüsü, Groq/Gemini ile mesaj ayrıştırma, taslak yükleme planı. Bu dosyalara dokunurken kullan.
---

# WhatsApp Yükleme Planı (v582)

Akış: Evolution API (Docker, `whatsapp-service/`) → webhook `api_whatsapp_webhook.php?token=…` → `whatsapp_webhook_isle()` →
log (`whatsapp_mesaj_loglari`) → AI (`AIParserService`) → karar → `whatsapp_plana_aktar()` → `loading_records` **durum='taslak'**.

## Değiştirmeden önce oku
- **Yapılandırma** yalnız `config/local.php` sabitleri/ortam değişkeni (`config/whatsapp_config.php` listesi). Anahtar/sır depoya GİRMEZ. `WEBHOOK_SECRET_TOKEN` boşsa webhook hiçbir isteği kabul etmez (fail-closed).
- **Webhook HER KOŞULDA HTTP 200 + JSON** döner (Evolution retry fırtınası). Yetkisiz de 200 + `yok_sayildi:yetkisiz`. 4xx/5xx basma; `rol` testi bunu denetler. FPM/LiteSpeed'de yanıt AI çağrısından ÖNCE kapatılır.
- **Yalnız grup mesajları** (`@g.us`) işlenir; kişisel sohbet HİÇ okunmaz. Gürültü mesajı (`isNoiseMessage`) AI'a gitmez ve SAKLANMAZ.
- **Idempotency:** önce `isleniyor` yer tutucu INSERT (UNIQUE `message_id`), sonra AI. Çökme → satır `ai_hata`/`isleniyor` kalır, panelden "Yeniden ayrıştır".
- **Uydurma koruması:** `AIParserService::sanitize()` mesajda GEÇMEYEN plaka/telefonu düşürür; tarih modele hesaplatılmaz (PHP 8 günlük takvim tablosu enjekte eder). Mesaj metni `<mesaj>` içinde VERİ olarak verilir (prompt injection).
- **loading_records'a yazan TEK yer** `whatsapp_plana_aktar()`. Taslak = `durum='taslak'` (records.php'de mor rozet/filtre, İşle yerine Onayla; onay `record_durum.php` ile `''`). Plaka plan modülü biçiminde BOŞLUKSUZ yazılır (`34KL4589`); log/AI çıktısında `34 KL 4589`.
- Aynı plaka + aynı tarih ikinci plan AÇMAZ (mevcut plana bağlanır). Tonaj plana yazılmaz (palet satırı yok; yalnız logda).
- Alan eşlemesi: firma_adi→firma · yukleme_yeri→bolge · bosaltma_yeri→gidecek_ulke · malzeme_cinsi→urun · plaka→on_plaka · dorse_plaka→arka_plaka · sofor_adi/telefon · yukleme_tarihi→tarih (yoksa mesaj günü).
- **Şema** `docs/sql/whatsapp_migration.sql`, yalnız açık GO ile (migrate.php kartı YOK). Tablolar yokken panel kurulum notu gösterir, webhook kaydetmeden yok sayar.
- Panel yalnız `is_admin()`; `nav_yonetim_sayfalari()` + Roller admin-only satırı + `assets/nav-icons/3d/whatsapp_yonetim.png`. `sw.js` panel + webhook'u önbelleğe ALMAZ.
- Sınıf adlarında `ad`/`ads`/`banner`/`sponsor` parçası yok (hepsi `wa-` önekli).
- Audit: `whatsapp_taslak_otomatik|manuel`, `whatsapp_ayar`, `whatsapp_oturum`, `whatsapp_yeniden_ayristir`, `status_change`. Ham mesaj metni audit'e YAZILMAZ.

## Test
`php scripts/whatsapp_smoke.php` (ağsız; sahte HTTP aktarımları + gerçek uç dosyaları `scripts/_whatsapp_istek.php` ile alt süreçte).
Nav değişince: `role_permission_matrix_smoke`, `muhasebe_rol_smoke`, `dashboard_sidebar_parity_smoke`.
