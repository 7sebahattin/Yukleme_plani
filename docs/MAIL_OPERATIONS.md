# Mail Merkezi — İşletme Kılavuzu

Kurulum, anahtar yönetimi, cron, bakım ve sorun giderme. **Bu belgede gerçek anahtar/şifre yoktur ve olmayacaktır.**
Mimari kararlar: `docs/MAIL_CENTER_AGENT_BRIDGE.md`.

## 1. İlk kurulum sırası (sahip yapar)

1. **Anahtar üret** (sunucuda ya da kendi bilgisayarında; çıktıyı kimseyle paylaşma):
   `php -r 'echo base64_encode(random_bytes(32)),"\n";'`
2. `config/local.php` (git'e girmez) içine ekle: `define('MAIL_MASTER_KEY', '<çıktı>');`
   İzin: `chmod 600 config/local.php` (cPanel Dosya Yöneticisi → İzinler: 600 ya da 640). Herkesçe okunabilir (o+r) ise
   Mail Hesapları ekranı uyarı gösterir.
3. **Tabloları kur:** yönetici → `migrate.php` → "Mail Merkezi — Tablolar" kartı (yalnız açık karar sonrası; 7 tablo, yalnız `CREATE TABLE IF NOT EXISTS`, mevcut tablolara dokunmaz).
4. **Hesap ekle:** Mail → Mail Hesapları. Gmail/Outlook için normal parola değil **uygulama şifresi** gerekir. Ardından "Bağlantıyı Test Et".
5. **Kullanıcı ata:** hesabı görecek kullanıcıları işaretle (atama yoksa yalnız yönetici görür — fail-closed). Rollerde `mail.read/reply/send/admin` yetkilerini ver.
6. **Cron (cPanel → Cron Jobs; SSH gerekmez):**
   `*/5 * * * * php /home/<hesap>/<site-klasoru>/scripts/mail_sync_cron.php >> /dev/null 2>&1`
   Çıktı satırları: `OK` / `FAIL` / `BUSY` / `BEKLE` / `CEVIRI` / `UYARI`. Çıkış kodu 1 yalnız hiçbir hesap başarılı değilse.
   Cron durursa Mail Hesapları ekranı "cron durmuş olabilir" uyarısı gösterir (son senkron > 30 dk).
7. **Çeviri (isteğe bağlı, varsayılan KAPALI):** `config/local.php`'de `MAIL_TRANSLATE_PROVIDER` (`deepseek|deepl|libretranslate|mymemory`) + gerekli anahtar/adres sabitleri.
   Açıldığında mail metni (hesabın kimliği/alıcı-gönderen başlıkları/ekler hariç) üçüncü taraf servise gider — KVKK/ticari sır açısından karar sahibindedir. Hesap bazında ayrıca "otomatik çeviri" işaretlenir.
   **DeepSeek:** `config/local.php` dosyasına (mevcut `MAIL_MASTER_KEY` tanımını SİLMEDEN) şunları ekleyin:
   ```php
   define('MAIL_TRANSLATE_PROVIDER', 'deepseek');
   define('MAIL_TRANSLATE_KEY', 'BURAYA_DEEPSEEK_API_ANAHTARINIZ');
   ```
   Anahtarı ChatGPT/Claude/GitHub/ekran görüntüsü ile paylaşmayın; yalnız sunucudaki gitignore'lu yerel dosyaya yazın. `MAIL_TRANSLATE_KEY` başka sağlayıcı için tanımlıysa ikinci kez `define` kullanmayın; mevcut tanımı güncelleyin. DeepSeek Flash (`deepseek-flash`) düşük maliyetli *non-thinking* kipte `https://api.deepseek.com/chat/completions` üzerinden çalışır; **ücretsiz değildir**, token kullanımı için API bakiyesi gerekir. SMTP/IMAP şifreleri ve ekler gönderilmez; fakat e-posta gövdesindeki ticari/sözleşmesel bilgiler DeepSeek'e gönderilir (yurt dışına veri aktarımı riskini değerlendirin). Dış servis çalışmasa bile orijinal mail okunabilir, taslak korunur; gönderimde insan onayı zorunludur.
   **Başlatma:** Mail → Mail Hesapları → hesaba `Düzenle` → `Bu hesap için otomatik çeviri` kutusunu işaretle, `Hedef dil: tr` bırak ve kaydet. Ardından 5 dakikalık cron turunu bekle veya ilgili mailde `Şimdi çevir` kullan. 7 günden eski pending kayıtlar otomatik sıradan çıkar; ilgili mailde elle çevirme yapılabilir. Başlangıçta tek hesapla dene, sonra diğer hesaplarda aç.

**Veritabanı gereksinimi:** MySQL ≥ 5.7 ya da MariaDB ≥ 10.2 (tablolar açık `ROW_FORMAT=DYNAMIC` ile kurulur; geniş sütunlar için gerekli). Eski sürümde `migrate.php` kartı ilgili tabloda hata satırı gösterir, mevcut verilere dokunulmaz.

## 2. Ağ gereksinimleri

Sunucudan **çıkış** portları: IMAP 993 (SSL) / 143 (STARTTLS), SMTP 465 (SSL) / 587 (STARTTLS). Paylaşımlı hostlar bunları kapatabilir;
"Bağlantıyı Test Et" `Sunucuya bağlanılamadı` derse hosting sağlayıcısından çıkış izni isteyin. Düz metin (TLS'siz) bağlantı **desteklenmez**; sertifika doğrulaması kapatılamaz.

## 3. MAIL_MASTER_KEY rotasyonu

Ne zaman: anahtar sızdı/şüphe var, çalışan ayrıldı, periyodik politika. Anahtar kaybolursa kayıtlı posta parolaları çözülemez — hesaplar yeniden kaydedilir (veri kaybı yok, yalnız parolalar).

1. Yeni anahtarı üret (1. adımdaki komut).
2. **Veritabanı yedeği al** (Yönetim → Yedekler).
3. Kuru çalıştırma (hiçbir şey yazmaz; tüm blob'ları ESKİ anahtarla çözer):
   `MAIL_MASTER_KEY_OLD='<eski>' MAIL_MASTER_KEY_NEW='<yeni>' php scripts/mail_rotate_key.php`
   Anahtarlar **ortam değişkeni**dir (argüman değil — süreç listesinde/kabuk geçmişinde görünmesin). Komutu bir `HISTCONTROL=ignorespace` oturumunda başında boşlukla çalıştırın.
4. Uygula (tek transaction, hep-ya-hiç; herhangi bir blob eski anahtarla çözülemezse HİÇBİR şey değişmez):
   `MAIL_MASTER_KEY_OLD=… MAIL_MASTER_KEY_NEW=… php scripts/mail_rotate_key.php --uygula`
5. **Hemen** `config/local.php` içindeki `MAIL_MASTER_KEY`'i yeni değerle değiştirin. 4–5 arasında senkron/gönderim güvenli şekilde başarısız olur (veri kaybı yok); kısa tutun.
6. Doğrula: Mail Hesapları → "Bağlantıyı Test Et". Eski anahtarı güvenle imha edin.

Not: şifre blob'u biçimi `v1:<anahtar kimliği>:<base64>`; anahtar kimliği yanlış anahtarı erkenden yakalar. AAD hesap id + alan adıdır — blob başka hesaba/alana taşınırsa çözülmez.

## 4. Bakım ve izleme

- **Senkron günlüğü:** Mail Hesapları → "Senkron durumu ve günlüğü" (son 20 çalıştırma, hesap başına art arda hata sayısı, sonraki deneme zamanı). Günlük 30 gün saklanır.
- **Art arda hata geri çekilmesi:** 3 ardışık hatadan sonra cron o hesabı atlar (5 dk → 10 → 20 … en çok 6 sa); yanlış parolayla dakikada bir giriş denemek hesabı kilitletir. "Şimdi senkronla" (yönetici) beklemeyi atlar. Başarılı senkron sayacı sıfırlar.
- **`unknown` giden cevaplar:** SMTP son yanıtı alınamadığı için mesajın gidip gitmediği bilinmiyor; sistem **otomatik tekrar göndermez**. Mail → Gönderilen/Hatalı → kaydı aç → müşteriyi/Gönderilenler klasörünü kontrol et → "Gönderildi (doğruladım)" ya da "Gönderilmedi — tekrar denemeye izin ver".
- **UIDVALIDITY değişimi:** sunucu klasörü yeniden indeksledi; eski kayıtlar korunur, klasör yeniden taranır (Message-ID ile çiftleme engellenir). Elle işlem gerekmez.
- **Disk:** mesaj gövdeleri DB'dedir; ek dosyaları sunucuya YAZILMAZ (indirilirken IMAP'tan akıtılır). `storage/mail/` yalnız kilit dosyalarını içerir.
- **Yedek:** `db_backup` dökümünde şifre blob'ları yalnız şifreli yer alır; **anahtar yedekte yoktur** — `config/local.php`'yi ayrıca ve güvenli saklayın.

## 5. Sorun giderme

| Belirti | Olası neden | Çözüm |
|---|---|---|
| Hesap kaydedilemiyor / "MAIL_MASTER_KEY…" | Anahtar yok/geçersiz (32 bayt base64 değil) | 1. adım |
| Senkron `Kimlik doğrulama başarısız` | Parola/uygulama şifresi yanlış, IMAP kapalı | Hesabı düzenle; sağlayıcıda IMAP'ı aç |
| `Sunucuya bağlanılamadı` | Çıkış portu kapalı / host adı yanlış | Bölüm 2 |
| `TLS el sıkışması başarısız` | Sertifika doğrulanamıyor (süresi dolmuş/yanlış host adı) | Sağlayıcının doğru host adını kullan; doğrulamayı kapatma |
| Mailler gelmiyor ama hata yok | Cron çalışmıyor | Ekrandaki uyarı; cron yolunu kontrol et |
| Çeviri "beklemede" kalıyor | Sağlayıcı kapalı ya da kota doldu | Bölüm 1/7; kota 6 sa duraklatılır |
| "Çift gönderim engellendi" | Aynı cevap zaten onaylanmış/gönderilmiş | Beklenen davranış |
