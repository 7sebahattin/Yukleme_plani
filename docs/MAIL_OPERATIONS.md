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
   Doğru komutun tam yolu Mail Hesapları ekranındaki cron uyarısında hazır yazılı gelir (kopyalanabilir kutu).
   cPanel'de `php` komutu CGI ikilisi olabilir (`PHP_SAPI = cgi-fcgi`); betik bunu da kabul eder (yalnız web isteği reddedilir).
   **Kalp atışı:** her cron çalışması `storage/mail/.cron_kalp.json`'a zaman + sonuç satırlarını yazar (0600, sır içermez;
   DB'ye bağlanamasa bile). Çıktı `/dev/null`'a gitse de Mail Hesapları ekranı durumu buradan okur:
   - *"cron'un çalıştığına dair kayıt yok"* → cron hiç çalışmıyor: cron kurulu değil, yol/komut yanlış.
   - *"Cron N dakikadır çalışmıyor"* → cron eskiden çalışıyordu, durdu (son sonuç satırı gösterilir).
   - *"Cron çalışıyor ama hata veriyor: FAIL …"* → cron çalışıyor; neden mesajda (DB, anahtar, tablolar, kilit izni).
   - *"Cron çalışıyor; hesap … bekletiliyor"* → bir hesap art arda hata verdiği için geri çekilmede (5 dk → 6 sa);
     son hata "Senkron durumu ve günlüğü" bölümünde. Düzeltince "⟳ Şimdi senkronla" beklemeyi atlar.
7. **Çeviri (isteğe bağlı, varsayılan KAPALI):** `config/local.php`'de `MAIL_TRANSLATE_PROVIDER` (`deepseek|deepl|libretranslate|mymemory`) + gerekli anahtar/adres sabitleri.
   Açıldığında mail metni (hesabın kimliği/alıcı-gönderen başlıkları/ekler hariç) üçüncü taraf servise gider — KVKK/ticari sır açısından karar sahibindedir. Hesap bazında ayrıca "Bu hesapta çeviriye izin ver" işaretlenir.
   **v369 — çeviri yalnız istek üzerine (sahip kararı, token tasarrufu):** gelen mailler cron ile OTOMATİK çevrilmez; kullanıcı mailde **"Türkçeye çevir"**e basınca yalnız o mail gönderilir. Eski davranış (cron kuyruğu) gerekirse `config/local.php`'de `define('MAIL_TRANSLATE_AUTO', true);` ile açılır. Cevap çevirisi zaten kullanıcının "önizle" tıklamasıyla yapılır.
   **v370 — "Yalnız konuyu çevir", harcama sayacı, günlük sınır, sözlük:**
   - Okuyucuda "Türkçeye çevir"in yanında **Yalnız konuyu çevir**: yalnız konu satırı (en çok 300 karakter) tek çağrıyla gider, yalnız `subject_tr` yazılır (`tr_status`/`body_tr` değişmez; tam çeviri sonradan çalışır ve `subject_tr`'yi günceller). Okuyucuda orijinal konunun altında, listede satırın altında soluk Türkçe konu görünür. Mail zaten hedef dildeyse sağlayıcıya gidilmez. Denetim kaydı `mail_translate_subject` (yalnız mail id + karakter sayısı).
   - **Harcama sayacı** (şema yok): `storage/mail/.translate_usage.json` (0600, 60 gün; yalnız SAYILAR — sağlayıcı, gönderilen karakter, çağrı, hata, DeepSeek `usage` token'ları; mail içeriği/konu/adres ASLA yazılmaz). Mail Hesapları → Çeviri bölümünde bugün / bu ay toplamı ve sınır görünür. DeepSeek dışı sağlayıcılar token bildirmez (yalnız karakter). Hatalı çağrı karakter/token SAYILMAZ (yalnız hata sayacı).
   - **Günlük sınır** (isteğe bağlı): `config/local.php` → `define('MAIL_TRANSLATE_GUNLUK_LIMIT', 50000);` = günde en çok bu kadar KARAKTER (tanımsız/0 = sınırsız). Sınır aşılacaksa istek sağlayıcıya GİTMEDEN reddedilir (net Türkçe mesaj): "Türkçeye çevir", "Yalnız konuyu çevir", cevap önizlemesi (Türkçe taslak korunur, çeviri elle girilebilir) ve otomatik kuyruk (`gunluk_limit`, mailler pending kalır). Gövde+konu toplamı baştan denetlenir (yarım çeviri olmaz). Kontrol–yazma arası atomik değildir: eşzamanlı iki istek sınırı bir parça aşabilir (yumuşak sınır). Gün yerel saate göre döner.
   - **Ticari sözlük:** DeepSeek sistem istemine sabit kısa sözlük eklenir (`MAIL_CEVIRI_SOZLUK`, config/mail_translate.php: palet/kasa/CMR/fitosanitar sertifika/GTİP/brüt-net kg/plaka/Kapya-Çarliston biber/domates/salatalık/limon/mandalina/nar; TR–EN–RU). Her çağrıda ~150 token ekler.
   **DeepSeek:** `config/local.php` dosyasına (mevcut `MAIL_MASTER_KEY` tanımını SİLMEDEN) şunları ekleyin:
   ```php
   define('MAIL_TRANSLATE_PROVIDER', 'deepseek');
   define('MAIL_TRANSLATE_KEY', 'BURAYA_DEEPSEEK_API_ANAHTARINIZ');
   ```
   Anahtarı ChatGPT/Claude/GitHub/ekran görüntüsü ile paylaşmayın; yalnız sunucudaki gitignore'lu yerel dosyaya yazın. `MAIL_TRANSLATE_KEY` başka sağlayıcı için tanımlıysa ikinci kez `define` kullanmayın; mevcut tanımı güncelleyin. DeepSeek Flash (`deepseek-flash`) düşük maliyetli *non-thinking* kipte `https://api.deepseek.com/chat/completions` üzerinden çalışır; **ücretsiz değildir**, token kullanımı için API bakiyesi gerekir. SMTP/IMAP şifreleri ve ekler gönderilmez; fakat e-posta gövdesindeki ticari/sözleşmesel bilgiler DeepSeek'e gönderilir (yurt dışına veri aktarımı riskini değerlendirin). Dış servis çalışmasa bile orijinal mail okunabilir, taslak korunur; gönderimde insan onayı zorunludur.
   **Başlatma:** `config/local.php` kaydedildikten sonra Mail → Mail Hesapları bölümündeki `DeepSeek Bağlantısını Test Et` düğmesine tıklayın (yalnız sabit örnek `Good morning.` API'ye gönderilir, gerçek müşteri verisi gitmez; küçük bir API ücreti oluşabilir). Test başarılıysa hesaba `Düzenle` → `Bu hesapta çeviriye izin ver` kutusunu işaretle, `Hedef dil: tr` bırak ve kaydet. Ardından ilgili mailde `Türkçeye çevir` kullan (`MAIL_TRANSLATE_AUTO` açıksa cron da çevirir; 7 günden eski pending kayıtlar o kuyruktan çıkar). Başlangıçta tek hesapla dene, sonra diğer hesaplarda aç.

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
- **Gönderilenler klasörü okuma (v370):** hesapta "Gönderilenler klasörü" (`sent_folder`) tanımlı ve gelen kutusundan farklıysa senkron onu da okur (salt okunur: EXAMINE/BODY.PEEK; ayrı bağlantı = kendi bayt bütçesi; ayrı `mail_sync_state` satırı; ilk tarama `initial_days`). Gelen kutusu BAŞARILI bittiyse ve bekleyen iş (`kalan`) yoksa çalışır. Gönderilenler hatası gelen kutusunu etkilemez: not düşer, hata Mail Hesapları → senkron durumunda "Gönderilenler klasörü: …" olarak görünür, 3 ardışık hatadan sonra kendi geri çekilmesi başlar (sunucuda klasör adı yanlışsa buradan fark edilir). Gönderilenler satırları: okundu, cevap beklemez, ASLA çevrilmez, gelen/okunmamış/cevap bekleyen listelerinde ve sayaçlarında YOKTUR; yalnız okuyucuda "Konuşmadaki diğer mailler"de görünür ve cevap yazılamaz. Outlook/telefondan yazılan cevap (In-Reply-To, yoksa References'ın son öğesi) gelen kutusundaki ebeveyni "cevaplandı" yapar (`replied_at` boşsa; ebeveynden önce tarihli cevap sayılmaz). `sent_folder` adı sonradan değiştirilirse eski Gönderilenler satırları gelen listesinde görünebilir (klasör adı satırda saklıdır).
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
| Mailler gelmiyor ama hata yok | Cron çalışmıyor ya da hesap geri çekilmede | Mail Hesapları üstündeki cron uyarısı hangisi olduğunu söyler (kalp atışı); komut orada hazır |
| "cron durmuş olabilir" (eski sürüm) ama cron kurulu | Eski uyarı yalnız senkron günlüğüne bakıyordu; hesap geri çekilmedeyken günlük satırı yazılmaz | Güncel sürümde kalp atışı ile ayrılır |
| Çeviri "beklemede" kalıyor | Sağlayıcı kapalı ya da kota doldu | Bölüm 1/7; kota 6 sa duraklatılır |
| "Çift gönderim engellendi" | Aynı cevap zaten onaylanmış/gönderilmiş | Beklenen davranış |

## 6. Siparişler "Mailden al" (v378) — cron bağımlılığı

Siparişler sayfasındaki **Sipariş sorgula** (sipariş PO + fiyat onayı PC; ortak gönderen) ve **Lojistik sorgula** düğmeleri posta kutusunu canlı taramaz; cron'un doldurduğu `mail_messages` kayıtlarından son 15 günün aday maillerini seçer ve yalnız PDF ekini o an IMAP'tan (salt okunur, `BODY.PEEK`) çeker. Cron durmuşsa yeni mailler henüz DB'de olmaz — sonuç kartı "en son N dk önce senkronlandı" uyarısı verir (çözüm Mail Hesapları'ndaki cron uyarısıdır). Ayar (hangi posta kutuları, hangi gönderen adresleri) `Siparişler › Ayarlar › 📧 Mail sorgu ayarları` kartındadır; gönderen adresleri DB'de (hks_kv) saklanır, repoda yoktur. Mailde "X adına Y" görünüyorsa iki adres de eklenmelidir.

