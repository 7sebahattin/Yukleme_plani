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
| Current milestone | **M2 tamam (IMAP + MIME + senkron + cron, Opus güvenlik incelemesi uygulandı) → M3 (UI) sırada** |
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
| M2 — IMAP istemcisi + MIME/HTML temizleyici + senkron motoru + cron | bkz. PR yorumu (commit SHA) | ✅ (aşağıda) |

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
| `mail_gate_static_smoke.php` (M1 + M2 statik: TLS doğrulama, yalnız EXAMINE/BODY.PEEK, UNSEEN yok, error_log redakte, eval/exec yok) | 61/61 ✅ |
| `mail_imap_smoke.php` (sahte IMAP sunucusu: oturum, "UID n:*" tuzağı, literal, kopma, limit, BYE, UTF-7, belirteçleyici) | 45/45 ✅ |
| `mail_mime_smoke.php` (başlık/adres/RFC2231/multipart + **57'lik XSS korpusu** + kararlılık sanitize(sanitize(x))=sanitize(x) + srcdoc/CSP) | 120/120 ✅ |
| `mail_sync_smoke.php` (çiftleme yok, okunmuş mail kaçmıyor, kopma/devam, hesap yalıtımı, UIDVALIDITY, thread, sızıntı yok, limitler) | 61/61 ✅ |
| `mail_cron_smoke.php` (CLI-only, global kilit/BUSY, kısmi hata, günlük bakımı) | 19/19 ✅ |
| `mail_review_smoke.php` (Opus bulguları H1…L5 regresyonları; MySQL strict mod SQLite'ta taklit) | 39/39 ✅ |
| Tüm mevcut `scripts/*_smoke.php` | ✅ regresyon yok |

Henüz test edilmeyenler (ağ/kimlik bilgisi gerektirir → sahip tarafında): gerçek Gmail/Outlook/Dovecot IMAP davranışı, gerçek TLS/STARTTLS el sıkışması,
canlı MySQL strict mod. Planlanan: `mail_smtp_smoke.php`, `mail_outbox_smoke.php`, `mail_ui_smoke.js` (Playwright).

## Security Notes

- Sır YOK: ne bu belgede ne kodda ne testte (testler yalnız üretilmiş sahte değerler kullanır).
- `MAIL_MASTER_KEY` üretimi sahibe aittir: `php -r 'echo base64_encode(random_bytes(32)),"\n";'`
  → `config/local.php` içine `define('MAIL_MASTER_KEY', '…');`. **Ben gerçek anahtar/şifre istemem
  ve üretmem.** Anahtar kaybolursa kayıtlı posta şifreleri çözülemez (yeniden girilir).
- Master key rotasyonu ve `local.php` izinleri (0600) M6'da dokümante edilir.

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

- M3: Mail Merkezi UI (`mail.php` gelen kutusu, `mail_api.php` JSON, `mail_ek.php` ek indirme, `assets/mail.css/js`): 3 panel / mobil liste→detay,
  Türkçe/Orijinal sekmeleri, sandbox'lı iframe render, uzak görsel kapalı + "Görselleri göster", filtreler, okundu işaretleme, Playwright testi.
- Sonra M3…M8 (görev metnindeki sıra).

## Needs ChatGPT Review

1. **AD-5/T11:** çeviri varsayılan KAPALI + veri çıkışı politikası; hangi ücretsiz sağlayıcı?
   (MyMemory anonim limitleri düşük; LibreTranslate genel örneği anahtar istiyor; self-host
   mümkün değil — paylaşımlı host.) M4'ten önce karar bekliyorum; arayüz hazır olacak.
2. **AD-7:** `unknown` durumunda insan kontrolü (otomatik yeniden gönderim YOK) — kabul mü?
3. **AD-3:** hesap ACL'i fail-closed (satır yoksa yalnız admin) — kabul mü?
4. **AD-4:** AES-256-GCM+AAD, `local.php` anahtarı; HKS'teki sabit yedek anahtar deseni
   BİLEREK tekrarlanmadı — kabul mü?
5. Dal adı sapması (üstteki ¹ notu).
