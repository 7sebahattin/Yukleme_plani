# Mail Center Agent Bridge

> **Amaç:** ChatGPT (teknik orkestratör / reviewer) ile Claude Code (uygulayıcı) arasındaki
> CANONICAL durum + handoff belgesi. Her önemli milestone sonunda güncellenir.
> Doğrudan talimat kanalı: Draft PR Conversation yorumları
> (`[CLAUDE -> CHATGPT]` / `[CHATGPT -> CLAUDE]`).
>
> ⚠ Bu belge **sır içermez**. Gerçek şifre / app password / API anahtarı / master key
> repoda ASLA bulunmaz (bkz. §Security Notes).

---

## Current State

| Alan | Değer |
|---|---|
| Base (`main`) SHA (başlangıçta doğrulandı) | `8c4165d320799f1ed3054e150a5413b96ed6cbdc` (PR #677 merge) |
| Feature branch | `ccr-cfeb15cc-xrykgj` — ¹ |
| Current HEAD | M1 commit `6c1abdf` (+ bu belge güncellemesi) — bkz. Completed Work |
| Draft PR | **#678** — https://github.com/7sebahattin/Yukleme_plani/pull/678 |
| Current milestone | **M6 tamam (sertleştirme) → M7 (tam test/regresyon/UI incelemesi) sırada** |
| Status | 🟡 Draft — merge/deploy YOK. `APPROVED_FOR_MERGE` (ChatGPT) beklenmiyor henüz. |

¹ Görev metni `feat/mail-center` adını istedi; bu oturumun çalışma ortamı geliştirmeyi
belirlenmiş `ccr-cfeb15cc-xrykgj` dalına bağlıyor ve başka dala push'u yasaklıyor.
Dal güncel `main`'den (aynı SHA) başlar; "mevcutsa/benzersiz yeni dal" yönergesinin
ruhuna uygundur. İstenirse PR başlığı/dalı sonradan yeniden adlandırılabilir.

**Merge / production kuralı:** ChatGPT açıkça `APPROVED_FOR_MERGE` demeden PR merge
edilmez. Repo'da `main`'e merge = canlı + test sitesine otomatik deploy
(`docs/DEPLOY_WORKFLOW.md`) olduğu için bu kural kritiktir.

---

## Repository Analizi (M0 bulguları — mimariyi belirleyen olgular)

| Olgu | Kaynak | Mimariye etkisi |
|---|---|---|
| Saf PHP, çerçeve yok, tek CSS/JS; modüller kendi `assets/<modul>.css/.js` alabilir (maliyet, hesap, pdks emsali) | CLAUDE.md | Mail modülü `assets/mail.css` + `assets/mail.js` kullanır; `style.css`/`app.js`'e dokunmaz → mobil düzen etkilenmez |
| Yeni tablolar **kendi migrate üçlüsü** ile gelir (`*_tablolar/_migrate/_sema_hazir`); genel `db.php` auto-migration'a EKLENMEZ; kurulum yalnız `migrate.php` kartı (admin, CSRF, audit); tablo yoksa özellik GİZLİ | `pdks_gunluk_kart_tanim_*`, `pdks_servis_*` | Aynı desen. "DB migration yalnızca açık GO ile" kuralı: tablolar **yalnız yöneticinin migrate.php'de butona basmasıyla** kurulur, hiçbir sayfa isteği şema değiştirmez |
| Yetki: `config/auth.php` `permission_catalog()` (roles.php'de görünürlük) + sayfa kapısı + seed. Admin için `can_beyan()` deseni = `can() \|\| is_admin()` | auth.php, helpers.php | `can_mail()` aynı desen; sidebar / bottomnav / `first_allowed_page` / `index.php` kartı / sayfa kapısı **TEK fonksiyon** `can_mail()`'den beslenir |
| Navigasyon tek kaynak `nav_aktif_anahtar()` + `nav_alt_sayfalar()` + `nav_alt_izinler()`; bottomnav ikonu `assets/nav-icons/*.svg` + `sw.js` SHELL | helpers.php | `mail` anahtarı bu üç yere + sidebar + `first_allowed_page()`'e eklenir; `bottomnav_smoke` güncellenir |
| **PHP `imap` eklentisi yok** (bu ortamda; PHP 8.4'te çekirdekten çıktı, PECL) ; `openssl`, `mbstring`, `iconv`, `dom`, `sodium`, `curl` var. Canlı host: cPanel, PHP 8.3, openssl (HKS AES-CBC kullanıyor) | `php -m`, halkayit/db.php | IMAP ve SMTP istemcileri **saf PHP, `stream_socket_client('ssl://')`** ile yazılır; `ext-imap`'e BAĞIMLILIK YOK. Test edilebilirlik için sahte sunucu akışı enjekte edilir |
| Şifreleme emsali: `halkayit/db.php` `hks_sifrele()` (AES-256-CBC) + `config/local.php`'de `HKS_CRED_KEY`; **ama kodda sabit bir yedek anahtar var** | halkayit/config.php | Mail için aynı yer (`config/local.php`, gitignore'lu) ama **yedek/sabit anahtar YOK, fail-closed**, AEAD (AES-256-GCM + AAD) |
| CLI-only script deseni (`PHP_SAPI !== 'cli'`), `flock` kilidi, `storage/` `.htaccess` ile kapalı | `scripts/db_backup_cron.php`, `db_backup_helpers.php` | `scripts/mail_sync_cron.php` aynı desen; kilit/durum `storage/mail/` altında |
| Audit: `audit_log_event()` hata yutar; `_audit_sanitize()` yalnız TAM anahtar adı süzer, >1000 karakter keser | helpers.php | Mail audit'ine **yalnız id/sayı/durum** yazılır; gövde/konu/şifre geçirilmez; hata metinleri `mail_redact()` ile temizlenir |
| Service Worker GET'leri önbelleğe alır (network-first); indirme uçları için dışlama kuralı var | sw.js, CLAUDE.md "sw.js kuralı" | Mail sayfa/JSON/ek yanıtları **SW'ye hiç girmez** (aksi hâlde mail içeriği cihaz CacheStorage'ında kalıcı yazılırdı) |
| Test kültürü: `scripts/*_smoke.php` bellek içi SQLite + MySQL DDL çevirici; `*_smoke.js` Playwright; statik kaynak-kural testleri | scripts/ | Aynı üç katman mail için de |
| Sürüm: `APP_SURUM` (helpers.php) = `sw.js CACHE_NAME` (şu an v307) | CLAUDE.md | Nav/ikon/asset değişince v308'e çekilir (önce `origin/main`'e bakılır) |

---

## Architecture Decisions

**AD-1 — Saf PHP IMAP/SMTP istemcisi.** `ext-imap` yok/kırılgan; Windows `.pyw` çözümü bilerek
temel alınmadı. `config/mail_imap.php` (IMAP alt kümesi: LOGIN/AUTHENTICATE PLAIN, CAPABILITY,
SELECT/EXAMINE, UID SEARCH, UID FETCH, LOGOUT, APPEND opsiyonel), `config/mail_smtp.php`
(EHLO, implicit TLS 465 / STARTTLS 587, AUTH PLAIN/LOGIN, MAIL FROM/RCPT/DATA). TLS
**doğrulamalı** (`verify_peer`, `verify_peer_name`); doğrulamayı kapatan ayar YOK.
Taşıma katmanı `MailStream` arayüzü → testte `FakeMailStream` (scripted sunucu).

**AD-2 — Okundu-bağımsız, UID tabanlı senkron.** `UNSEEN` KULLANILMAZ. Anahtar:
`UNIQUE(account_id, folder, uidvalidity, uid)`. Klasör başına `mail_sync_state(uidvalidity,
last_uid)`. Fetch `UID FETCH last_uid+1:*` (+ `*` tuzağı: dönen UID ≤ last_uid süzülür),
`BODY.PEEK[]` (sunucuda `\Seen` DEĞİŞTİRMEZ). Başka cihazdan okunmuş mail yine alınır, çünkü
alma kararı bayrağa bağlı değil. `UIDVALIDITY` değişirse: eski satırlar SİLİNMEZ; yeni epoch
için baştan taranır ve **Message-ID hash** ile (aynı klasörde) çiftleme engellenir.
İlk senkron yalnız son `initial_days` (varsayılan 30) gün. Uygulama içi `is_read` ayrı bir
bayrak (IMAP `\Seen`'i yalnız bilgi olarak saklarız).

**AD-3 — Veri modeli (7 tablo, hepsi `CREATE TABLE IF NOT EXISTS`, ALTER yok):**
`mail_accounts` · `mail_account_users` (hesap başına kullanıcı ACL — **fail-closed**: satır
yoksa yalnız `mail.admin`/admin görür) · `mail_threads` · `mail_messages` (ek metadata'sı
`attachments_json` — ayrı tablo/ayrı dosya yok) · `mail_outbox` · `mail_sync_state` ·
`mail_sync_log`. Ek **gövdesi diske/DB'ye yazılmaz**; indirme anında IMAP'tan o MIME parçası
çekilir (web kökünde saklanan dosya riski yok).

**AD-4 — Kimlik bilgisi şifreleme.** Alan başına AES-256-GCM (`openssl`), rastgele 96-bit
nonce, **AAD = `mail_accounts:<id>:<alan>:<key_id>`** (şifreli blob'u başka satıra/alana
taşıma saldırısını engeller), biçim `v1:<key_id>:<b64(nonce‖tag‖ct)>`. Master key
`MAIL_MASTER_KEY` (32 bayt, base64) — `config/local.php` (gitignore'lu) ya da ortam
değişkeni; **repoda ve DB'de yok, yedek anahtar yok**. Anahtar yoksa: hesap kaydetme /
senkron / gönderim REDDEDİLİR (fail-closed), ekranda net uyarı. Anahtar döndürme için
`key_id` alanı hazır (çoklu anahtar `MAIL_MASTER_KEYS` M6'da). Şifre ASLA geri gösterilmez
(formda boş bırak = değiştirme).

**AD-5 — Çeviri sağlayıcı soyutlaması.** `MailTranslationProviderInterface`
(`detect(text)`, `translate(text, from, to)`); provider sınıfları bağımsız, iş mantığı
sağlayıcıyı bilmez. Varsayılan sağlayıcı **`none`** (kapalı) — çünkü çeviri mail içeriğini
**üçüncü tarafa gönderir** (veri çıkışı; güvenlik açısından tartışmalı karar →
ChatGPT/sahip onayı gerekir, bkz. Needs ChatGPT Review). Durum alanı ayrı:
`tr_status = pending|translated|failed|skipped`; provider hatası maili ASLA gizlemez
(orijinal her zaman okunur), retry sayacı + backoff ile tekrar denenir. Dil tespiti
önce yerel (yazı sistemi + stopword) — provider'a gitmeden.

**AD-6 — Cevap = onay kapılı iki aşama.** Aşama 1 "Önizle": Türkçe taslak + çeviri
`mail_outbox`'a `status=translated` + `content_hash` ile yazılır. Aşama 2 "✅ Onayla ve Gönder":
istek `outbox_id + content_hash + csrf` taşır; sunucu hash'in saklı içerikle eşleştiğini
doğrular (onaylanan = gönderilen, TOCTOU yok), yetkiyi (`mail.send`) + hesap ACL'ini
denetler, `approved_by/at` yazar. **SMTP koduna giden TEK kapı** `mail_outbox_gonder()`;
`status='approved'` ve `approved_by IS NOT NULL` olmadan çalışmaz. İptal = `cancelled`.

**AD-7 — En-fazla-bir-kez (at-most-once) gönderim.** (a) Her compose formu tek kullanımlık
`idempotency_key` taşır → `UNIQUE(account_id, idempotency_key)`; (b) gönderim öncesi atomik
sahiplenme `UPDATE … SET status='sending', send_token=? WHERE id=? AND status='approved'`
(`rowCount()=1` olan tek istek SMTP'ye gider); (c) `Message-ID` onayda üretilip saklanır;
(d) SMTP `DATA` kabulünden ÖNCE hata → `failed` (güvenle yeniden denenebilir, kullanıcı
açıkça tetikler); `DATA` gönderildikten sonra yanıt belirsizse veya süreç ölürse satır
`sending`'de kalır ve **otomatik YENİDEN GÖNDERİLMEZ** → eşik sonrası `unknown` (insan
kontrol eder: "Gönderilenler'i kontrol et"). Aynı cevap iki kez gönderilemez.

**AD-8 — Thread başlıkları.** Yeni cevapta `In-Reply-To = orijinal Message-ID`,
`References = orijinalin References zinciri + orijinal Message-ID` (son ~20), `Subject` =
`Re: ` + (önceki `Re:/RE:/AW:/SV:/Ответ:` önekleri temizlenmiş), `To = Reply-To ?: From`,
`From` = hesabın kendi adresi. Başlık enjeksiyonu: tüm başlık değerlerinde CR/LF reddedilir,
adresler `FILTER_VALIDATE_EMAIL` + RFC2047 kodlama. Alıcı tarafında thread kurma
(`mail_threads`): `In-Reply-To`/`References` → mevcut mesaj bulunursa onun thread'i,
yoksa yeni thread. Konu-bazlı tahmin YOK (yanlış birleştirme riski; açık risk olarak not).
Gönderilen kopya hesabın `Sent` klasörüne `APPEND` ile **best-effort** yazılır (M5'te
sağlayıcı davranışı doğrulanır; Gmail zaten kendisi yazar → mükerrer olmaması için
hesap bazlı bayrak).

**AD-9 — Arka plan.** `scripts/mail_sync_cron.php` (CLI-only; HTTP'den 403): global
`flock` (aynı anda tek örnek) + hesap başına try/catch (bir hesap bozulursa diğerleri
devam) + zaman bütçesi + `mail_sync_log` + stdout; çıkış kodu yalnız tüm hesaplar patlarsa 1.
Aynı çalıştırma bekleyen çeviri kuyruğunu da sınırlı bütçeyle işler. Kurulum cPanel
Cron Jobs ekranından (kullanıcıdan SSH istenmez), örn. `*/5 * * * *`. Not: bazı paylaşımlı
hostlar 993/465/587 çıkış portunu kapatabilir → hesap sayfasında "Bağlantıyı Test Et".

**AD-10 — HTML mail güvenliği (katmanlı).** (1) Sunucuda `DOMDocument` ile **izin listesi**
temizleyici: script/style/iframe/object/embed/form/meta/link/base/svg/math vb. etiketler
tamamen düşer; tüm `on*` nitelikleri düşer; yalnız `href` (http/https/mailto/tel) ve
`src` (yalnız `cid:`→ placeholder) izinli; `javascript:`/`data:`/`vbscript:` URL'ler düşer;
`style` niteliği güvenli özellik izin listesiyle (url()/expression/behavior yok). (2)
**Uzak görsel/izleme pikseli varsayılan ENGELLİ** (`src` → `data-engelli-src`; "Görselleri
göster" mesaj başına, yalnız o görüntüleme). (3) Render `<iframe sandbox="" srcdoc=…>`
(script yok, same-origin yok) + içinde `<meta http-equiv="Content-Security-Policy"
content="default-src 'none'; img-src data: [https: yalnız kullanıcı açtıysa]; style-src
'unsafe-inline'">`. (4) Bağlantılar `rel="noopener noreferrer nofollow" target="_blank"`.
Düz metin her zaman `h()` + `nl2br`.

**AD-11 — Ek güvenliği.** İndirme `mail_ek.php` (GET, `can_mail('read')` + hesap ACL + mesaj
sahipliği), yalnız `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`,
`Cache-Control: no-store`, dosya adı temizlenir, tehlikeli uzantılar (exe, js, vbs, bat, scr,
msi, html, svg…) için ek uyarı + `application/octet-stream` zorlaması, boyut sınırı
(15 MB), `session_write_close()`. Her indirme audit'lenir (yalnız mesaj id + parça no).
Gönderilen ek v1 KAPSAM DIŞI.

**AD-12 — Yetkiler.** Katalog grubu **"Mail Merkezi"**: `mail.read` (gelen kutusu, çeviri
okuma), `mail.reply` (Türkçe cevap taslağı + çeviri önizleme), `mail.send` (onayla ve
gönder), `mail.admin` (hesap/kimlik bilgisi/ACL/sağlayıcı/log). Hepsinde `|| is_admin()`
(`can_beyan` deseni; admin rolünde seed olmadığı için kilitlenme olmasın). Hesap bazlı ACL
ayrıca (AD-3). `can_mail()` TEK kaynak; sidebar, bottomnav (`nav_alt_izinler`),
`first_allowed_page`, `index.php` kartı, sayfa/AJAX kapıları hepsi onu çağırır →
"kart var, sayfa 403" tutarsızlığı yapısal olarak yok; statik test denetler.

**AD-13 — UI.** Masaüstü 3 panel (hesap/klasör · liste · okuma+cevap), mobil liste →
detay (Türkçe/Orijinal sekmeleri, sabit "Cevapla"). Kendi CSS/JS (`assets/mail.css`,
`mail.js`), `.mail` sarmalayıcısı (mobil 16px input kuralı), modal z-index ≥ 600.
Yeni mail rozeti (okunmamış sayısı) sidebar/bottomnav/index'te.

---

## Threat Model (özet — STRIDE tabanlı)

| # | Tehdit | Etki | Önlem |
|---|---|---|---|
| T1 | DB sızıntısı → posta şifreleri | 5 şirket posta kutusu ele geçer | AES-256-GCM + AAD, anahtar repo/DB DIŞINDA; yedek anahtar yok; `db_backup` dökümünde yalnız şifreli blob |
| T2 | Kaynak/git sızıntısı | Sır commit'i | Hiçbir sır yok; `config/local.php` gitignore'lu; test **sentinel-sızıntı** testi repo + log + audit'i tarar |
| T3 | Kötü niyetli HTML mail → XSS (oturum çalma, CSRF tetikleme) | Panel ele geçirilir | AD-10 katmanları; XSS korpus testi; sandbox iframe origin'siz |
| T4 | İzleme pikseli / uzak görsel | Okundu bilgisi, IP sızar | Varsayılan engel; kullanıcı açar |
| T5 | Zararlı ek | İstemci kodu çalıştırma | AD-11; sunucuda ASLA çalıştırma/diske yazma yok |
| T6 | Başlık enjeksiyonu (CRLF) giden mailde | Spam/BCC enjeksiyonu | Başlık değerlerinde CR/LF ret, adres doğrulama, test |
| T7 | Yetkisiz okuma/gönderme (IDOR: mesaj/hesap id'si) | Başka şirketin postası | Her uç `can_mail()` + hesap ACL; mesaj id→hesap→ACL zinciri; fail-closed; izolasyon smoke testi |
| T8 | CSRF ile gönder/onayla | İstenmeyen mail | Tüm POST `csrf_check()` (JSON-aware), onay ayrıca `content_hash` + tek kullanımlık anahtar |
| T9 | Çift gönderim (çift tık, retry, cron) | Müşteriye iki kez aynı cevap | AD-7 |
| T10 | Onaylanmadan gönderim | Yanlış/çevrilmemiş mail gider | Tek kapı fonksiyonu + `approved_by` şartı + sahte SMTP'de çağrı sayacı testi |
| T11 | Mail içeriğinin üçüncü taraf çeviri servisine gitmesi | Gizlilik / ticari sır | Varsayılan **kapalı**; admin açık eder; sağlayıcıya yalnız gövde metni (başlık/adres/ek yok); maliyet/KVKK kararı sahip + ChatGPT review |
| T12 | Cron'un HTTP'den tetiklenmesi / paralel koşu | Kaynak tüketimi, çift işleme | CLI guard, `flock`, UID unique, idempotent insert |
| T13 | Hatalı/kötü IMAP yanıtı (dev literal, sonsuz döngü) | Bellek/zaman tüketimi | Literal ve satır uzunluk sınırları, zaman aşımı, mesaj boyutu limiti, parçalı fetch |
| T14 | TLS düşürme / MITM | Kimlik bilgisi sızar | Doğrulamalı TLS zorunlu, düz metin port yok |
| T15 | SW önbelleği / paylaşılan cihaz | Mail içeriği cihazda kalır | `sw.js` mail yollarını bypass, `no-store` |
| T16 | Hata/log/audit'te sır veya gövde | Sızıntı | `mail_redact()`, audit'e yalnız id/sayı; protokol günlüğünde `LOGIN`/`AUTH` argümanları maskeli |
| T17 | UIDVALIDITY değişimi / sunucu yeniden indeksleme | Kopya veya kayıp | AD-2 |
| T18 | Zaman/sıra: kayan saatli gönderen tarihi | Yanlış sıralama | `received_at` (sunucu) ile sırala, `date_header` yalnız görüntü |

---

## Completed Work

| Milestone | Commit | Durum |
|---|---|---|
| M0 — Analiz + mimari + tehdit modeli | `ea7f413` | ✅ |
| M1 — DB/config/permission temel yapısı | `6c1abdf` | ✅ (aşağıda) |
| M2 — IMAP istemcisi + MIME/HTML temizleyici + senkron motoru + cron | `5c59a7c` | ✅ (aşağıda) |
| M3 — Mail Merkezi UI (gelen kutusu/okuyucu) + ek indirme | `5356234` | ✅ (aşağıda) |
| M4 — Çeviri sağlayıcı soyutlaması + kuyruk + yerel dil tespiti | bkz. PR yorumu (commit SHA) | ✅ (aşağıda) |
| M5 — Cevap onayı + SMTP + at-most-once gönderim (+ bağımsız Opus incelemesi düzeltmeleri) | `6445274` | ✅ (aşağıda) |
| M6 — Sertleştirme: indeks uzunluğu, geri çekilme, toplam süre, anahtar rotasyonu, işletme uyarıları, günlük ekranı | `d212f1e` | ✅ (aşağıda) |

**M1 içeriği**
- `config/mail_core.php`: 7 tablo DDL (`mail_tablolar()`), `mail_migrate()` / `mail_sema_hazir()`
  (YALNIZ `migrate.php` "Mail Merkezi — Tablolar" kartından çağrılır; statik test garanti eder),
  AES-256-GCM+AAD şifreleme (`mail_sifrele/mail_coz`, `MAIL_MASTER_KEY`), `mail_redact()`,
  hesap doğrulama/CRUD (`mail_hesap_kaydet`, `mail_hesap_cred_oku`, `mail_hesap_goster`),
  hesap ACL (`mail_gorunur_hesap_idleri` — fail-closed), okunmamış sayaç, `require_mail()`.
- `config/helpers.php`: **`can_mail()` tek kapı** (read/reply/send/admin; `is_admin()` + `mail.admin` her şeyi açar,
  `can()` yoksa fail-closed). Sidebar, `nav_alt_izinler`, `first_allowed_page`, index kartı bunu çağırır.
  `can_mail()` bilerek `nav_ptak_sayfalari()` ile `first_allowed_page()` arasında durur: `rol_kapilari_smoke.php`
  o aralığı `eval` ediyor ve `first_allowed_page()` artık `can_mail()` çağırıyor (test dosyası değişmedi).
- `config/auth.php`: "Mail Merkezi" yetki grubu. `helpers.php` admin seed listesine 4 yetki.
- `mail.php` (iskelet: kurulum durumu + görünür hesaplar), `mail_hesaplar.php` (hesap + kullanıcı ataması; şifreler
  forma geri basılmaz), `migrate.php` kartı, `index.php` kartı + okunmamış rozeti.
- `sw.js`: `/mail*.php` yolları SW'ye hiç girmez; `mail.svg` SHELL'de; `APP_SURUM`/`CACHE_NAME` **v308**.
- `assets/nav-icons/mail.svg`; `scripts/bottomnav_render.php` bağımsız kapı tablosuna `mail.php` satırı eklendi
  (alt çubuk testi hâlâ bağımsız doğrulama yapıyor).

**M2 içeriği**
- `config/mail_imap.php`: saf PHP IMAP (ext-imap YOK). `MailStream` arayüzü (`MailSocketStream` gerçek, testte `FakeMailStream`),
  doğrulamalı TLS (düz metin seçeneği yok), AUTHENTICATE PLAIN / LOGIN, **EXAMINE** (salt okunur), `UID SEARCH`, `UID FETCH` (**yalnız BODY.PEEK**),
  literal/satır/toplam bayt/süre sınırları, modified UTF-7 klasör adları, kimlik bilgisi içermeyen komut günlüğü.
- `config/mail_mime.php`: RFC 2047/2231, charset→UTF-8, RFC 2046 multipart (IMAP parça numaralarıyla uyumlu), ek metadata'sı;
  **HTML temizleyici = DOM üzerinden izin listesiyle YENİDEN ÜRETİM** (script/style/iframe/svg/form… düşer, on* yok, `javascript:`/`data:` URL yok,
  uzak görsel `data-blocked-src` ile ENGELLİ, CSS izin listesi), sandbox'lı `iframe srcdoc` + CSP üretici.
- `config/mail_sync.php`: UID/UIDVALIDITY senkronu (UNSEEN YOK), çiftleme, thread çözümü (başlık tabanlı), hesap başına + global `flock`, hesap yalıtımı,
  `mail_imap_test()` ("Bağlantıyı Test Et" — `mail_hesaplar.php`), `mail_cron_calistir()`.
- `scripts/mail_sync_cron.php`: CLI-only cron girişi (cPanel: `*/5 * * * * php …/scripts/mail_sync_cron.php`).
- Şema eki: `mail_sync_state.rescan_from_epoch` (M1'in DDL'ine eklendi — henüz hiçbir DB'de kurulmadığı için ALTER gerekmedi).

**M3 içeriği**
- `mail.php`: sunucuda çizilen gelen kutusu + okuyucu. Mobil (<768) tek panel (liste YA DA mesaj, "← Liste", sabit "Cevapla" çubuğu alt çubuğun ÜSTÜNDE),
  ≥768 liste+mesaj yan yana, ≥1180 sol sütunda hesap/klasörler. Filtreler: Gelen · Okunmamış · Cevap Bekleyen · Taslak/Bekleyen · Gönderilen · Hatalı
  (giden kutusu M5'te dolacak; arayüz hazır). Arama (LIKE jokerleri kaçırılır), sayfalama (30), hesap çipleri + okunmamış rozeti, son senkron durumu,
  yöneticiye "⟳ Şimdi senkronla" (POST+CSRF, 20 sn bütçe), Türkçe/Orijinal sekmeleri (çeviri M4'te dolacak; yokken durum + Orijinal'e yönlendirme),
  thread listesi, okundu/okunmadı, "cevaplandı/cevap bekliyor say" (mail.reply).
- **HTML mail = sandbox'lı iframe** (`sandbox="allow-popups allow-popups-to-escape-sandbox"` — script YOK, same-origin YOK, form YOK) + CSP meta
  (`default-src 'none'; img-src data:`); uzak görseller varsayılan engelli, "Görselleri göster" yalnız o görüntüleme (`?img=1`). Düz metin `h()` ile.
- **GET yan etkisizdir**: mesaj açmak okundu işaretlemez; `assets/mail.js` kısa beklemeden sonra POST+CSRF ile işaretler (JS kapalıyken düğme var).
- `mail_ek.php` + `config/mail_attach.php`: ek gövdesi diske YAZILMAZ; mesaj→hesap→ACL, parça no mesajın kendi ek listesinde (beyaz liste),
  **UIDVALIDITY değişmişse indirme reddedilir (409)**, her zaman `attachment` + `nosniff` + `no-store` + `CSP sandbox`, riskli/bilinmeyen türler
  `application/octet-stream`'e zorlanır (HTML/SVG eki asla `text/html` servis edilmez), 15 MB sınırı, RTL (U+202E) dosya adı hilesi temizlenir, audit.
- `config/mail_view.php`: tüm sorgular görünür-hesap listesiyle sınırlı; `mail_post_isle()` (exit'siz, test edilebilir).
- `assets/mail.css` + `assets/mail.js` (kendi dosyaları; `style.css`/`app.js`'e dokunulmadı).
- **Bulunup düzeltilen gerçek hata:** `mail.php`/`mail_hesaplar.php` `render_header()`'ın zaten açtığı `<main class="container">`'ın içine ikinci `container`
  koyuyordu (sidebar kenar boşluğu iki kez uygulanıyor, masaüstünde okuyucu 2 px'e eziliyordu) — Playwright ölçümü yakaladı, düzeltildi ve statik testle kilitlendi.

**M4 içeriği** (`config/mail_translate.php`, `mail_dil_tespit()` mail_mime.php'de)
- `MailTranslationProviderInterface` (`ad()`, `parcaLimiti()`, `cevir($metin,$kaynak,$hedef)`) — iş mantığı sağlayıcıyı bilmez. Sağlayıcılar: **DeepL**
  (Free planı: ayda 500 000 karakter, anahtar gerekir), **LibreTranslate** (https adres + isteğe bağlı anahtar), **MyMemory** (anahtarsız, kota çok düşük), `none`.
  Seçim yalnız `config/local.php` sabitleriyle (`MAIL_TRANSLATE_PROVIDER/KEY/URL/EMAIL`); **varsayılan `none` = hiçbir şey dışarı gitmez**. Anahtar DB'ye/ekrana yazılmaz.
- **Veri çıkışı minimizasyonu:** sağlayıcıya yalnız gövde METNİ (alıntı satırları ve "… wrote:" / Original Message sonrası kırpılır, ≤ 12 000 karakter) + konu gider;
  gönderen/alıcı adresi, başlık, ek, hesap bilgisi ASLA. Testle sabit (sahte sağlayıcıya giden her bayt denetlenir).
- **Yerel dil tespiti** (sunucudan çıkmaz): yazı sistemi + sözcük puanı (tr/en/de/fr/es/it/pt/nl/pl/ru/uk/ar/fa/he/el/zh/ja/ko; emin değilse null). Senkronda `lang` yazılır;
  zaten hedef dildeki mail çeviri kuyruğuna hiç girmez (`skipped`) → kota + veri çıkışı korunur.
- **Durum makinesi** `pending → translated | failed | skipped` ayrı alanlarda; **çeviri hatası maili asla etkilemez** (yalnız `tr_*`). Geçici hata → 5 / 15 dk / 1 sa / 4 sa
  geri çekilme, 5. denemede `failed`; kalıcı hata (4xx) → hemen `failed`; kimlik/yapılandırma hatası (401/403) → kuyruk DURUR, mailler `pending` kalır, deneme hakkı yenmez;
  kota (456/429-kota) → 6 saat duraklatma. 7 günden eski bekleyenler çevrilmez.
- **HTTP:** yalnız https, yönlendirme takip edilmez, `CURLPROTO_HTTPS`, TLS doğrulamalı, 1 MB yanıt sınırı, zaman aşımı; hata metinleri `mail_redact()`'ten geçer.
- Cron: senkrondan sonra çeviri kuyruğu (`CEVIRI durum=… ceviri=… hata=…` satırı; senkron çıkış kodunu etkilemez). UI: Türkçe sekmesi dolar; sağlayıcı AÇIKSA
  "Şimdi çevir" / "Tekrar dene" (POST+CSRF, ACL, audit `mail_translate_manual`); yönetici ekranı sağlayıcı durumunu + son kuyruk çalışmasını gösterir.
- **Henüz yok:** giden (Türkçe → hedef dil) çeviri M5'te aynı sağlayıcı arayüzüyle gelecek.

**M5 içeriği** (`config/mail_smtp.php`, `config/mail_outbox.php`, `mail.php` cevap akışı)
- **Akış:** Türkçe cevap → taslak (`draft`) → önizleme: sağlayıcı ile hedef dile çeviri (`translated`) → ekranda **"TÜRKÇE ORİJİNAL CEVAP" ve
  "GÖNDERİLECEK ÇEVİRİ" yan yana** + gönderen kimliği + alıcı + (varsa) alıntı + Reply-To uyarısı → **"✅ Onayla ve Gönder"** (`mail.send`) → SMTP.
  "Onayla"ya basılmadan SMTP'ye HİÇ bağlanılmaz (taslak/önizleme/iptal testlerinde bağlantı sayacı 0). İptal her aşamada var.
  Sağlayıcı kapalı/arızalıysa Türkçe metin kaybolmaz; çeviri elle girilebilir (`tr_provider = manual`).
- **SMTP (saf PHP):** doğrulamalı TLS (ssl:// ya da STARTTLS; düz metin yok), AUTH PLAIN/LOGIN, kimlik bilgisi günlükte maskeli, toplam oturum üst sınırı 300 sn,
  yanıt sınıflaması: DATA öncesi hata / açık 4xx-5xx → `failed` (güvenle tekrar denenebilir); son "." sonrası kopma/yanıt yok/beklenmedik 1xx-3xx → **`unknown`** (otomatik tekrar YOK).
- **Thread başlıkları:** `Message-ID` onayda üretilir ve saklanır, `In-Reply-To` = orijinal (yazımı AYNEN korunur), `References` = zincir (kök + son 19), `Reply-To` hesaptan,
  `Re:/AW:/SV:/Ответ:` normalizasyonu, RFC 2047 (ASCII `=?` içeren konu da kodlanır), CRLF enjeksiyonu reddedilir. Giden cevap sync motoruna gelince orijinal thread'e katılır (testli).
- **At-most-once (AD-7) — sertleştirilmiş hâl:**
  1. Onay UPDATE'i `status='translated' AND content_hash=<ekrandaki hash>` koşullu; `approved_hash` + `dedupe_key` yazar.
  2. `UNIQUE dedupe_key` (hesap + ebeveyn + onaylı içerik) → aynı cevabın iki satırı **yapısal olarak** iki kez onaylanamaz (ön kontrol yalnız dostça mesaj için).
  3. Sahiplenme: `status='approved' ∧ approved_by ∧ Message-ID ∧ approved_hash ∧ content_hash = approved_hash ∧ hesap aktif`; ardından token ile yeniden okuma, kayıp → ABORT + audit.
  4. Gönderilen bayt'lar `approved_hash`'in baytlarıdır: hash **gönderen kimliğini** (hesap adresi, görünen ad, Reply-To) da kapsar → onaydan sonra hesap değişirse gönderilmez (`failed`/bütünlük).
  5. Sonuç yazımı yalnız kendi token'ıyla; süpürme gönderim sürerken satırı `unknown` yapsa bile mesaj gittiyse `sent` yazılır; sahiplik başkasına geçmişse sesli uyarı (audit + log + ekran).
  6. Süpürme: `sending` > 20 dk → `unknown` (SMTP üst sınırı 5 dk'nın çok üstünde), `approved` > 30 dk → onay geri alınır (`translated`).
  7. `unknown` yalnız insan kararıyla çözülür ("Gönderildi (doğruladım)" / "Gönderilmedi — tekrar denemeye izin ver", `mail.send`, audit); sonra tekrar gönderim aynı Message-ID'yi kullanır.
- **Gönderilen kopya:** hesapta açıksa IMAP APPEND (best-effort; hata gönderimi etkilemez; sunucu ikinci `+` gönderirse gövde ikinci kez yazılmaz).
- **Ağ katmanı:** `MailSocketStream` artık kendi tamponu + engellemeyen okuma/yazma + MUTLAK süre sınırı (yavaş-damla koruması) kullanır; STARTTLS'te tamponda bayt kalmışsa TLS'e geçilmez.
- **Message-ID yazımı korunur** (RFC 5322: sol kısım harf-duyarlı); eşleştirme hash'i `mail_mime_id_hash()` ile harf-duyarsız (mevcut satırların hash'i değişmez).
- Audit: yalnız id/durum/sayı (`mail_reply_draft/preview`, `mail_send_approve`, `mail_send`, `mail_send_failed`, `mail_send_unknown`, `mail_send_resolve`, `mail_send_sahiplik_kaybi`, `mail_approval_expired`, `mail_reply_cancel`); gövde/konu/adres/şifre yazılmaz.
- Şema eki (M1 DDL'ine, henüz hiçbir DB'de kurulu olmadığı için ALTER yok): `mail_outbox.quote_text`, `approved_hash`, `dedupe_key` (+ `UNIQUE uq_mo_dedupe`), `mail_sync_state.rescan_from_epoch`.

**M6 içeriği** (sertleştirme; yeni kod yüzeyi küçük, hepsi testli — `scripts/mail_hardening_smoke.php`, `mail_schema_static_smoke.php`)
- **MySQL indeks uzunluğu (gerçek hata bulundu):** `mail_outbox.out_message_id VARCHAR(255) UNIQUE` utf8mb4'te 1020 bayt → MySQL 5.6 / COMPACT satır biçiminde
  `1071 Specified key was too long` (767 bayt sınırı) ile kurulum patlardı. `VARCHAR(190)` (760 bayt) yapıldı, üretilen Message-ID alan adı ≤ 100 karakter
  (toplam ≤ 150). Yeni statik test (`mail_schema_static_smoke.php`) tüm 17 indeksi en kötü durum (767 bayt) için hesaplar; eski sütun genişliğiyle düştüğü doğrulandı.
- **Art arda senkron hatası geri çekilmesi:** `mail_sync_bekleme_sn()` — 3. ardışık hatadan sonra 5 dk × 2^(n−3), üst sınır 6 sa. Cron bekleme süresindeki hesabı atlar
  (`BEKLE hesap=…`, IMAP'a bağlanmaz, günlük satırı üretmez, çıkış kodunu etkilemez); yönetici "Şimdi senkronla" (`zorla`) atlar; başarı sayacı sıfırlar.
  Gerekçe: yanlış/iptal parolayla 5 dk'da bir giriş denemesi sağlayıcıda hesabı kilitletir.
- **IMAP toplam oturum süresi:** `toplam_sn` (900) — tek komut bütçesi ile toplam süreden küçüğü geçerli; gerçek soket akışına mutlak sınır iletilir (SMTP'deki 300 sn ile simetrik).
- **Master-key rotasyonu:** `mail_anahtar_donustur()` + `scripts/mail_rotate_key.php` (CLI-only, anahtarlar ortam değişkeninden, varsayılan kuru çalıştırma, `--uygula` tek transaction
  hep-ya-hiç, yazmadan önce her blob geri çözülüp doğrulanır, bozuk/yanlış anahtar → hiçbir şey yazılmaz). **Çalıştırmak sahibin kararıdır;** adımlar `docs/MAIL_OPERATIONS.md`.
- **İşletme uyarıları** (`mail_yapilandirma_uyarilari()`, Mail Hesapları ekranı): `config/local.php` o+r, `storage/mail` diğerlerine açık, cron canlılığı (son senkron > 30 dk / hiç çalışmadı), çeviri sağlayıcısı açıksa veri çıkışı bilgisi.
- **Senkron günlüğü ekranı** (`mail_sync_gunluk_getir()`, yalnız `mail.admin` sayfasında): hesap başına son başarılı / ardışık hata / sonraki deneme + son 20 çalıştırma; hata metinleri yeniden redakte edilir.
- `docs/MAIL_OPERATIONS.md`: kurulum sırası, ağ gereksinimleri, rotasyon, bakım, sorun giderme tablosu.

**Bağımsız Opus güvenlik incelemesi (M5) — bulgular ve düzeltmeler** (hepsi `scripts/mail_outbox_review_smoke.php`'de regresyon testli; 6 kritik düzeltme için mutasyon kontrolü yapıldı — düzeltme geri alınınca test düşüyor):
| # | Bulgu | Düzeltme |
|---|---|---|
| B1 | Önizleme ile onay yarışı: onaylanmış/gönderilmiş satırın içeriği, eski sürümü okuyan bir önizleme isteğiyle ezilebiliyordu; onay hash'i "ekrandaki" ile "onaylanan" arasında kopuktu | tüm yazımlar koşullu (`status` + okunan `content_hash`); `mail_outbox_yaz()` koşulsuz çağrıda `LogicException`; onay UPDATE'i hash'e bağlı |
| B2 | Aynı cevabın iki ayrı satırı yarışta ikisi de onaylanıp gönderilebiliyordu (ön kontrol yarışa açık) | `approved_hash` + `UNIQUE dedupe_key`; 23000 → "çift gönderim engellendi" |
| B3 | Süpürme 10 dk'da uçuştaki gönderimi `unknown` yapıp sonra `sent` yazımını engelliyordu; eşikler SMTP süresinden kısaydı | eşik 20 dk, SMTP oturumu ≤ 5 dk; sonuç yazımı `sending`/`unknown` + kendi token'ı; sahiplik kaybı sesli |
| B4 | Onaydan sonra hesap kimliği (Reply-To, görünen ad, adres) değişirse eski onayla yeni kimlikle gider; pasif hesaptan gönderim | hash gönderen kimliğini kapsar; sahiplenmede hesap aktif şartı; bütünlük denetimi `approved_hash`'e karşı |
| B5 | SMTP: çıplak `250` yanıtı protokol hatası sayılıyordu (kabul edilmiş mesaj `failed` olup tekrar gönderilebilirdi); 1xx/3xx son yanıt açık ret sanılıyordu; toplam süre sınırı yoktu | regex `^(\d{3})(?:([ -])(.*))?$`; yalnız 4xx/5xx açık ret, diğerleri `unknown`; `MAIL_SMTP_TOTAL_SN = 300` + soket mutlak süre sınırı |
| B6 | Message-ID küçük harfe çevriliyordu (In-Reply-To müşteri sistemindeki kimlikle eşleşmeyebilir); `=?` içeren ASCII konu encoded-word sahteciliği; Reply-To ≠ From görünmüyordu | yazım korunur + harf-duyarsız hash; konu kodlanır; onay panelinde uyarı + gönderen kimliği + alıntı açık |
| B7 | IMAP APPEND devam isteği her `+` için tekrar çalışıyordu (kötü sunucu gövdeyi çoklu yazdırabilir); audit boşlukları (kimlik/oluşturma hataları) | devam isteği tek seferlik, ikincisinde bağlantı kesilir; her sonlandırma yolu audit'li |

**Bağımsız Opus güvenlik incelemesi (M2) — bulgular ve düzeltmeler** (hepsi `scripts/mail_review_smoke.php`'de regresyon testli;
düzeltmeler geri alınınca testler düşüyor — mutasyon kontrolü yapıldı):

| # | Bulgu | Düzeltme |
|---|---|---|
| H1 | Dışarıdan tek zehirli mail (9999 tarihi, geçersiz UTF-8 References, dev alıcı JSON'u) MySQL strict modda INSERT'i reddettirip hesabı **kalıcı** durdurur | tarih 1970–9999'a sıkıştırılır; tüm başlık türevleri `mb_scrub`; to/cc JSON ≤ 60 KB; **karantina**: kayıt reddedilirse yer tutucu satır + imleç ilerler; yer tutucu da yazılamıyorsa (DB sorunu) imleç ilerlemez → mail kaybolmaz |
| M1 | STARTTLS sonrası düz metin tampon enjeksiyonu (MITM sahte CAPABILITY/UIDVALIDITY/FETCH) | tamponda bayt varsa TLS'e geçilmez; yanlış etiketli yanıt = protokol hatası |
| M2 | libxml `<meta charset>`'ı UTF-8'e çevrilmiş içeriğe tekrar uyguluyor (Türkçe Outlook HTML bozuluyor; `utf-7` ile `<b>` üretiliyor) | `<meta>` atılır + girdi saf ASCII (sayısal varlık) → charset yorumu yok |
| M3 | Message-ID dedupe sonsuza dek açık: yeni mail eski satırın UID'sini ele geçirebilir (ek indirmede yanlış parça) | dedupe yalnız geri tarama sürerken (`rescan_from_epoch`, kalıcı bayrak) ve yalnız önceki dönem satırlarına; eşleşen satır taşınır |
| M4 | Dönem değişimi + uzun kesinti: yalnız `initial_days` taranır, arada gelen mailler kaybolur | SINCE = min(pencere, `last_ok_at` − 1 gün) |
| L1 | Multipart RFC 2046 uyumsuzluğu (epilog gösteriliyor, başlıksız parça kayıp, boş parça numarayı kaydırıyor) | ayraç tabanlı bölme, kapanışta dur, her parça sayılır |
| L2 | Ham 8-bit + encoded-word karışık başlık siliniyor (gönderen kaybolur) | ham baytlar önce UTF-8'e çevrilir |
| L3 | Sonsuz `{0}` literal akışı; `foo[` bayrağı satırı yutar | literal sayı/süre sınırı; `[…]` yalnız BODY/BINARY sonrası |
| L4 | Sanitizer sınırı sessizce içerik düşürüyor | yürüyüş durur + `body_truncated` işaretlenir |
| L5 | Bozuk Content-ID ek listesini siliyor | cid temizlenir + `JSON_INVALID_UTF8_SUBSTITUTE` |

Reviewer'ın "sağlam" bulduklarından öne çıkanlar: 57 XSS yükü Chromium'da yeniden ayrıştırılıp sıfır izinsiz etiket/nitelik/şema doğrulandı;
`data-blocked-src` yeniden etkinleştirme yolu sahtelenemiyor; IMAP komut enjeksiyonu korumaları; AES-GCM/AAD kullanımı; ACL fail-closed.

## Tests

| Test | Sonuç |
|---|---|
| `mail_core_smoke.php` (M1) | 85/85 ✅ |
| `mail_ui_smoke.php` (M1) | 33/33 ✅ |
| `mail_gate_static_smoke.php` (M1 + M2 + M5 statik: TLS doğrulama, yalnız EXAMINE/BODY.PEEK, UNSEEN yok, error_log redakte, eval/exec yok, SMTP tek kapı) | 99/99 ✅ |
| `mail_imap_smoke.php` (sahte IMAP sunucusu: oturum, "UID n:*" tuzağı, literal, kopma, limit, BYE, UTF-7, belirteçleyici) | 45/45 ✅ |
| `mail_mime_smoke.php` (başlık/adres/RFC2231/multipart + **57'lik XSS korpusu** + kararlılık sanitize(sanitize(x))=sanitize(x) + srcdoc/CSP) | 120/120 ✅ |
| `mail_sync_smoke.php` (çiftleme yok, okunmuş mail kaçmıyor, kopma/devam, hesap yalıtımı, UIDVALIDITY, thread, sızıntı yok, limitler) | 61/61 ✅ |
| `mail_cron_smoke.php` (CLI-only, global kilit/BUSY, kısmi hata, günlük bakımı) | 19/19 ✅ |
| `mail_review_smoke.php` (Opus bulguları H1…L5 regresyonları; MySQL strict mod SQLite'ta taklit) | 39/39 ✅ |
| `mail_view_smoke.php` (M3: sorgular, filtreler, LIKE kaçışı, sayfalama, ACL/IDOR, durum değişiklikleri, ek indirme: ACL, parça beyaz listesi, UIDVALIDITY 409, octet-stream zorlaması, boyut, hata) | 46/46 ✅ |
| `mail_ui_smoke.php` (M1 + M3 + M5: sayfa render, GET yan etkisiz, IDOR, iframe sandbox, POST işlemleri, onay ekranı) | 84/84 ✅ |
| `mail_ui_render.php` + `mail_ui_smoke.js` (**Playwright/Chromium**: 360/390/767/768/1024/1280/1440 — yatay taşma, panel düzeni, sabit Cevapla çubuğu, dokunma hedefleri, 16px input, kontrast açık/koyu, **saklı XSS iframe içinde çalışmıyor**, konsol hatası) | 200/200 ✅ |
| `mail_translate_smoke.php` (M4: 17 dil tespiti, alıntı kırpma, parçalama, DeepL/Libre/MyMemory sahte HTTP, hata türleri, geri çekilme, kota, yapılandırma hatası, **veri çıkışı denetimi**, ACL, cron, sızıntı) | 107/107 ✅ |
| `bottomnav_render.php` + `bottomnav_smoke.js` (alt çubuk, mail girdisiyle) | (A) 1828 · (B) 1011 ✅ |
| `mail_smtp_smoke.php` (M5: sahte SMTP — oturum, AUTH, DATA sınıflaması, başlık oluşturucu, CRLF/başlık enjeksiyonu, thread başlıkları, sızıntı) | 62/62 ✅ |
| `mail_outbox_smoke.php` (M5: taslak→önizleme→onay→gönderim durum makinesi, onaysız SMTP yok, çift gönderim yok, unknown/insan çözümü, APPEND, iptal, ACL) | 109/109 ✅ |
| `mail_outbox_review_smoke.php` (M5 Opus bulguları B1…B7 regresyonları; yarışlar `_kanca_*` ile zorlanır) | 34/34 ✅ |
| `mail_stream_smoke.php` (gerçek soket çifti: satır/bayt okuma, mutlak süre, yavaş-damla, büyük yazma, yazma kilitlenmesi) | 9/9 ✅ |
| `mail_hardening_smoke.php` (M6: geri çekilme, toplam süre, anahtar rotasyonu, işletme uyarıları, günlük verisi; 5 mutasyon yakalandı) | 35/35 ✅ |
| `mail_schema_static_smoke.php` (M6: 17 indeks ≤ 767 bayt utf8mb4, Message-ID uzunluğu) | 4/4 ✅ |
| `mail_ui_smoke.js` (Playwright, M5 onay ekranı dahil) | 505/505 ✅ |
| Tüm mevcut `scripts/*_smoke.php` | ✅ regresyon yok |

Henüz test edilmeyenler (ağ/kimlik bilgisi gerektirir → sahip tarafında): gerçek Gmail/Outlook/Dovecot IMAP ve SMTP davranışı (587/465, Gmail/Outlook uygulama şifresi, gönderilen kopya APPEND'i), gerçek TLS/STARTTLS el sıkışması,
canlı MySQL strict mod. Planlanan: `mail_smtp_smoke.php`, `mail_outbox_smoke.php`, `mail_ui_smoke.js` (Playwright).

## Security Notes

- Sır YOK: ne bu belgede ne kodda ne testte (testler yalnız üretilmiş sahte değerler kullanır).
- `MAIL_MASTER_KEY` üretimi sahibe aittir: `php -r 'echo base64_encode(random_bytes(32)),"\n";'`
  → `config/local.php` içine `define('MAIL_MASTER_KEY', '…');`. **Ben gerçek anahtar/şifre istemem
  ve üretmem.** Anahtar kaybolursa kayıtlı posta şifreleri çözülemez (yeniden girilir).
- Master key rotasyonu: `scripts/mail_rotate_key.php` + `docs/MAIL_OPERATIONS.md` (M6). `local.php` izni 0600 önerilir; ekran gevşekse uyarır.

## Open Risks

1. Paylaşımlı hostun IMAP/SMTP **çıkış portu** kısıtı (993/465/587) — canlıda doğrulanmadı.
2. Çeviri sağlayıcısı seçimi ve **veri çıkışı** (T11) — sahip kararı gerekir.
3. Aynı hesapta farklı sağlayıcıların `UIDVALIDITY`/UID davranış farkları (+ ilk taramada `SINCE` INTERNALDATE'e göredir) (Gmail IMAP'ta
   etiket=klasör; yalnız INBOX hedeflenir).
4. Konu-bazlı thread tahmini bilerek yok → başlıksız yanıtlar ayrı thread olabilir.
5. Saf PHP IMAP: yalnız kullanılan komut alt kümesi test edildi; gerçek sunucu (Gmail/Outlook/
   cPanel Dovecot) farklarını canlı bağlantı testi ortaya çıkarabilir (credential gerektirir →
   sahip tarafında "Bağlantıyı Test Et").
6. `mail_account_users` ACL fail-closed: ilk kurulumda yönetici kullanıcıları atamalı.

## Next Planned Actions

- M7: tam test/regresyon/UI incelemesi (mobil/masaüstü ekran görüntüleri, erişilebilirlik, yük/uç durumlar) · M8: son entegrasyon incelemesi. **Gerçek IMAP/SMTP/çeviri sağlayıcısı sahibin credential'ıyla canlıda doğrulanacak; migration `migrate.php` kartıyla yalnız açık GO'dan sonra.**

## Needs ChatGPT Review

1. **AD-5/T11:** çeviri varsayılan KAPALI + veri çıkışı politikası; hangi ücretsiz sağlayıcı?
   (MyMemory anonim limitleri düşük; LibreTranslate genel örneği anahtar istiyor; self-host
   mümkün değil — paylaşımlı host.) M4'ten önce karar bekliyorum; arayüz hazır olacak.
2. **AD-7:** `unknown` durumunda insan kontrolü (otomatik yeniden gönderim YOK) — kabul mü?
3. **AD-3:** hesap ACL'i fail-closed (satır yoksa yalnız admin) — kabul mü?
4. **AD-4:** AES-256-GCM+AAD, `local.php` anahtarı; HKS'teki sabit yedek anahtar deseni
   BİLEREK tekrarlanmadı — kabul mü?
5. Dal adı sapması (üstteki ¹ notu).
6. **M5 / AD-7:** gönderim kimliği (From adresi + görünen ad + Reply-To) onay hash'ine dahil — onaydan sonra hesap ayarı değişirse gönderim `failed`'e düşer. Kabul mü?
7. **M5:** `approved` > 30 dk gönderilmemiş onay otomatik geri alınır (yeniden onay gerekir); `sending` > 20 dk → `unknown`. Eşikler kabul mü?
8. **M5:** gerçek SMTP (Gmail/Outlook/cPanel) testi sahip credential'ı olmadan yapılamadı; migration (yeni 7 tablo) çalıştırılmadı.
9. **M6:** art arda 3+ hatadan sonra otomatik geri çekilme (5 dk → 6 sa) politikası kabul mü? Master-key rotasyon betiği (çalıştırma sahibe ait) uygun mu?
10. **M6:** `out_message_id` sütunu 255→190 (MySQL indeks sınırı) — şema henüz hiçbir DB'de kurulu olmadığı için ALTER gerekmedi.
