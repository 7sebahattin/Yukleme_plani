# PDKS NFC — FAZ 0 DOĞRULAMA RAPORU

**Durum:** Faz 0 tamamlandı · **Faz 1 BAŞLATILMADI** · Onay bekleniyor
**Tarih:** 2026-09-14 · **Branch:** `claude/nfc-attendance-roadmap-z14alg`
**Üst belge:** `docs/PDKS_NFC_YOL_HARITASI.md` (doğruluk kaynağı)

> **Bu fazda yapılmayanlar:** migration yok · tablo yok · üretim kodu yok ·
> canlı veri değişikliği yok · deploy yok · production davranışı değişmedi.
> Eklenen her şey `scripts/` (web'e kapalı, CLI-only) ve `tools/` (web'e kapalı)
> altındadır; uygulamanın çalışan hiçbir dosyasına dokunulmadı.

---

## 0. YAPILAN TESTLER VE SONUÇLARI (özet tablo)

| # | Test | Yöntem | Sonuç |
|---|---|---|---|
| 1 | UID normalizasyon algoritması | `scripts/pdks_faz0_uid_kanit.php` — **bu ortamda çalıştırıldı** | ✅ **45/45 doğrulama geçti** |
| 2 | PHP saat dilimi | `scripts/pdks_faz0_zaman.php` — çalıştırıldı | ✅ `Europe/Istanbul`, `+03:00`, DST **yok** |
| 3 | MySQL saat dilimi | Canlı DB bu ortamdan erişilemez | ⏳ **SİZİN ÇALIŞTIRMANIZ GEREK** (§1.3) |
| 4 | `halkayit/api.php` auth deseni | Kaynak kod incelemesi | ✅ Analiz edildi — **kısmen** yeniden kullanılabilir (§2) |
| 5 | Personel tablosu var mı | Tüm `CREATE TABLE` taraması | ✅ **YOK** — kesin (§3) |
| 6 | Android `getId()` bayt sırası | Android SDK bu ortamda yok | ⏳ **Teşhis APK'sı hazır** (§5) — Faz 1 engelleyicisi **değil** |
| 7 | USB HID davranışı | Mevcut ölçümünüz + algoritma testi | ✅ Gereksinimler çıkarıldı (§6) |
| 8 | bcmath / gmp varlığı | `php -m` | ⚠ **İkisi de yok** → saf string aritmetiği zorunlu (§4.6) |
| 9 | Güvenlik bulguları sınıflandırma | Kaynak kod incelemesi | ✅ 1 yeni **BLOKER** bulundu (§7) |

**Ortam notu:** Bu oturum, canlı sunucudan yalıtılmış geçici bir konteynerde çalışıyor.
Yerel MySQL yok (`SQLSTATE[HY000] [2002]`), Android SDK yok (`ANDROID_HOME` boş).
Bu yüzden §1.3 ve §5 ölçümleri **sizin tarafınızdan** yapılacak; ikisi için de araç hazır.

---

## 1. MYSQL / PHP ZAMAN DOĞRULAMASI

### 1.1 PHP tarafı — ÖLÇÜLDÜ ✅

`scripts/pdks_faz0_zaman.php` çıktısı (bu ortam):

```
PHP sürümü                         8.4.19
date_default_timezone_get          Europe/Istanbul
PHP şimdi (Europe/Istanbul)        2026-09-14 14:53:08
PHP şimdi (UTC)                    2026-09-14 11:53:08
PHP UTC offset                     +03:00
Yaz saati (DST) etkin mi           hayır
/etc/timezone                      Etc/UTC
bcmath / gmp                       İKİSİ DE YOK
```

Doğrulanan üç gerçek:

1. **PHP saat dilimi sabit ve açık:** `config/db.php:9` → `date_default_timezone_set('Europe/Istanbul')`.
   Bu satır her istekte, her sayfada çalışır. PHP tarafında belirsizlik **yoktur**.
2. **Türkiye kalıcı UTC+3, DST yok** (`I` bayrağı = 0). Gece vardiyası hesabında
   "kaybolan/tekrar eden saat" sorunu **oluşmaz**. Bu, Faz 4'ü ciddi biçimde basitleştirir.
3. **İşletim sistemi `Etc/UTC`, PHP `Europe/Istanbul`.** Bu ortam, riski **birebir örnekliyor**:
   OS UTC'de, PHP zorla Istanbul'da. MySQL saat dilimini işletim sisteminden alıyorsa
   (`@@system_time_zone`), `NOW()` **UTC** döner ve PHP ile arada **3 saat** olur.
   Paylaşımlı hostinglerde bu yapılandırma yaygındır — varsayım yapılamaz.

### 1.2 Risk neden ciddi

Kod tabanı zaman yazarken **bilinçli olarak MySQL `NOW()`** kullanıyor
(`config/auth.php`: *"MySQL NOW() kullan — PHP timezone uyumsuzluğunu önler"*).
Yani projenin **seçtiği tek saat otoritesi MySQL'dir** ve o otoritenin gerçekte
hangi saat diliminde olduğu **hiç doğrulanmamış**.

Yükleme kayıtlarında 3 saatlik kayma fark edilmez. Puantajda doğrudan maaş
anlaşmazlığıdır: 08:00 giriş 05:00 görünür, gece vardiyası yanlış güne düşer.

### 1.3 ⏳ SİZİN ÇALIŞTIRMANIZ GEREKEN ÖLÇÜM (salt okunur)

**phpMyAdmin → SQL sekmesi →** aşağıdakini yapıştırıp çalıştırın.
Tek bir `SELECT`'tir; **hiçbir şey yazmaz, hiçbir ayarı değiştirmez.**

```sql
SELECT
    NOW()                AS mysql_now,
    UTC_TIMESTAMP()      AS mysql_utc,
    @@session.time_zone  AS oturum_tz,
    @@global.time_zone   AS global_tz,
    @@system_time_zone   AS sistem_tz,
    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS now_eksi_utc_saniye,
    VERSION()            AS mysql_surum;
```

**Sonucu bana gönderirken, o anki telefon/bilgisayar saatinizi de yazın.**
(Ör. "çalıştırdığımda saat 15:42 idi".)

### 1.4 Sonucun yorumu — kararı hangi değer belirliyor

Belirleyici tek alan: **`now_eksi_utc_saniye`**.

| `now_eksi_utc_saniye` | Anlamı | KARAR |
|---|---|---|
| **`10800`** (+3 saat) | MySQL UTC+3 → PHP ile **aynı an** | ✅ **Seçenek A** — `NOW()` kullanmaya devam |
| `0` | MySQL **UTC** → PHP'den **3 saat geri** | ⚠ **Seçenek C** — PDKS zamanını PHP üretir |
| başka bir değer | Beklenmedik saat dilimi | ⚠ **Seçenek C** + ayrı inceleme |

> `mysql_now` değerinin gerçek saatinize eşit olup olmadığına da bakın —
> `now_eksi_utc_saniye = 10800` ise eşit olmalıdır.

### 1.5 Seçeneklerin değerlendirmesi ve ÖNERİM

| | Seçenek | Değerlendirme |
|---|---|---|
| **A** | MySQL `NOW()` kullanmaya devam | **Sapma yoksa ÖNERİLEN.** Uygulamanın geri kalanıyla (kantar, yükleme, hesap, audit, oturum süreleri) tam tutarlı. Yeni bir istisna kuralı doğmaz |
| **B** | Bağlantıda `SET time_zone` | ❌ **ÖNERİLMEZ (Faz 1'de).** `NOW()` kullanan **her modülü** etkiler: mevcut kayıtlar eski, yeniler farklı saat dilimiyle yazılır → veri tabanında sessiz bir kırılma çizgisi. Doğru olabilir ama **PDKS'in kararı değildir**; ayrı bir iş, ayrı bir test, ayrı bir geri alma planı ister |
| **C** | Zamanı PHP üretir (`Europe/Istanbul`) | **Sapma varsa ÖNERİLEN.** Yalnız PDKS tablolarını etkiler, mevcut hiçbir modüle dokunmaz. Bedeli: `config/pdks.php`'de "burada neden `NOW()` kullanılmıyor" açıklamasının kalıcı olarak durması |
| **D** | Her ikisini yaz (`event_time` PHP + `server_received_at` MySQL) | Faydalı **ek**, tek başına çözüm değil. Zaten şemada var; sapma kalıcı olarak ölçülebilir kalır |

**Önerim:**
- `now_eksi_utc_saniye = 10800` → **A** (+ D zaten var).
- Aksi hâlde → **C**, ve mevcut sapmanın tüm uygulamayı etkilediği ayrı bir P1 görevi olarak `docs/NEXT_TASKS.md`'ye yazılır.

**Her iki durumda da değişmeyen kural:** Telefonun saati asla yetkili değildir;
yalnız `device_reported_at`'a yazılır ve 5 dakikadan fazla saparsa panoda uyarı çıkar.

### 1.6 ⚠ Bu ölçüm Faz 1'i engelliyor mu? → **HAYIR**

Önemli ve serbest bırakıcı bir tespit: **Faz 1 tablolarında puantaj zaman damgası yoktur.**
`employees` ve `employee_cards` yalnız `created_at` / `updated_at` / `issued_at` (DATE) taşır —
bunlar denetim alanlarıdır, bordro girdisi değildir ve uygulamanın geri kalanıyla aynı
davranırlar.

**Zaman kararı `attendance_events.event_time`'ı, yani FAZ 2'yi bağlar.**
Ölçüm sonucu gelmeden Faz 1 başlatılabilir; Faz 2 başlamadan gelmiş olmalıdır.

---

## 2. MEVCUT AUTH / API DESENİ (`halkayit/api.php`)

### 2.1 Nasıl çalışıyor — ölçülen davranış

```php
// halkayit/api.php:20-31
// require_login() kullanmıyoruz çünkü depo seçili değilse HTML redirect yapardı;
// bu uç JSON döndürmeli. Bu yüzden current_user() + can() ile elle kontrol.
$__hks_user = current_user();
if ($__hks_user === null) { http_response_code(401); echo json_encode(['hata'=>'Oturum gerekli…']); exit; }
if (!(can('records.write') || is_admin())) { http_response_code(403); echo json_encode(['hata'=>'…yetkiniz yok']); exit; }
```

| Konu | Tespit |
|---|---|
| Kimlik | `asya_session` **cookie'si** → `current_user()`. Tarayıcı tabanlı; cihaz kimliği yok |
| Yetki | `can('records.write') \|\| is_admin()` — tek, kaba kontrol |
| `require_login()` | **Bilinçli olarak çağrılmıyor** — HTML redirect'i önlemek için |
| Depo bağlamı | **HİÇ YOK.** `api.php` içinde tek bir `depo`/`active_depot`/`depo_sql_*` çağrısı yok |
| Hata zarfı | `{"hata": "..."}` + HTTP kodu — uygulamanın geri kalanındaki `{"ok":false,"error":...}` ile **tutarsız** |
| CSRF | **Hiç kontrol edilmiyor.** Cookie ile kimlik + durum değiştiren POST + token yok |

### 2.2 Bu desen güvenli mi? — dürüst cevap

**Kısmen. İki gerçek zayıflığı var ve ikisi de bizim için geçerli olmayacak:**

1. **Depo sorununu çözmüyor, atlıyor.** HKS verisi *firma* bazlı izole olduğu için
   depo filtresine ihtiyacı yok. PDKS'te ise depo **gerçek bir kısıt** (kapı → depo → kayıt
   damgası). Yani buradan kopyalanacak bir depo çözümü **yok**; kurmamız gerekiyor.
2. **CSRF koruması yok.** Cookie ile kimlik doğrulayan, durum değiştiren bir JSON ucu için
   bu bir açıktır. Şu an onu koruyan tek şey, oturum çerezindeki **`SameSite=Lax`**
   (`config/auth.php:auth_cookie_options`) — tarayıcılar çapraz siteden gelen POST'a çerezi
   iliştirmez. Yani sömürülebilir değil, ama koruma **kasıtlı bir katman değil, yan etki**.
   *(Bu bir PDKS bulgusu değildir; mevcut durumun tespitidir ve bu fazda değiştirilmedi.)*

### 2.3 ÖNERİ — Faz 1 için en küçük güvenli yaklaşım

**Yeniden kullanılacak olan:** *gerekçe ve iskelet* — `require_login()` yerine elle
kimlik + yetki kontrolü yapıp **her hata yolunda JSON dönmek**. Bu desen bu depoda
kanıtlanmış ve doğru.

**Yeniden kullanılmayacak olan:** cookie ile kimlik, CSRF'siz POST, `{"hata":...}` zarfı,
depo bağlamsızlığı.

Somut olarak:

| Konu | Faz 1/2 kararı |
|---|---|
| Kimlik | **Cookie YOK.** `X-PDKS-Device` (cihaz token'ı) + `Authorization: Bearer` (oturum token'ı), ikisi de sunucu üretimi ve **hash'li** saklanır |
| CSRF | Konu dışı — cookie kullanılmadığı için çapraz-site istek kurbanı yok. *(Web sayfaları her zamanki `csrf_check()`'e tabi.)* |
| Depo | Cookie'den **değil**, `cihaz → kapı → depo` zincirinden. Tek fonksiyon: `pdks_api_baglam()` |
| Hata zarfı | `{"ok":false,"code":"...","error":"..."}` — uygulamanın geri kalanıyla (`forbidden()`, `csrf_check()`) **tutarlı** |

**Ortak API-auth yardımcısı yazılsın mı? → Faz 1'de HAYIR.**
`api_pdks.php` tek bir yönlendirici; kapı zaten dosyanın başında **bir kez** kurulur.
Ortak bir soyutlama, ikinci bir API tüketicisi doğduğunda anlamlı olur.
`halkayit/api.php`'yi ona taşımak ise **refactor**'dür — çalışan bir HKS entegrasyonunu
PDKS uğruna riske atmak doğru olmaz. (Talebinizdeki "şimdi refactor etme" kısıtıyla da uyumlu.)

---

## 3. PERSONEL / KULLANICI MODELİ DOĞRULAMASI

### 3.1 Kesin tespit: personel ana tablosu YOK ✅

Kod tabanındaki **tüm** `CREATE TABLE` ifadeleri tarandı (PHP + SQL, `vendor/` hariç).
Kişiyle ilgili bulunan tablolar yalnızca şunlar:

| Tablo | Gerçekte ne tutuyor | Personel kartoteksi mi? |
|---|---|---|
| `users` | `username`, `email`, `password_hash`, `display_name`, `is_active` — **uygulamaya giriş yapanlar** | ❌ Hayır. Departman, sicil, fotoğraf, işe giriş tarihi **yok** |
| `user_sessions` / `user_roles` / `user_depolar` | Oturum, rol, depo ataması | ❌ Hayır |
| `dev_notes` | Geliştirici notları | ❌ Hayır |
| `material_definitions` (`type='sofor'`) | Şoför **adı öneri havuzu** (serbest metin) | ❌ Hayır — kimlik kaydı değil, otomatik tamamlama listesi |
| `loading_records.sofor_adi` | Serbest metin şoför adı | ❌ Hayır — üstelik şoförler **harici** kişilerdir, personel değil |
| `account_transactions.user_id` | Masrafın sahibi → `users.id` | ❌ Hayır — Hesap modülü "personel" derken **uygulama kullanıcısını** kastediyor |

**Sonuç kesin: `employees` tablosu zorunludur.**

### 3.2 Dört kavramın net ayrımı

| Kavram | Tanım | Nerede yaşar | Giriş yapar mı |
|---|---|---|---|
| **Uygulama kullanıcısı** | Panele giren kişi (admin, operatör, muhasebe) | `users` | Evet |
| **Personel (employee)** | Kapıdan giren/çıkan, puantajı tutulan kişi | **`employees` (yeni)** | Genelde **hayır** |
| **Güvenlik görevlisi** | Kapıdaki telefonu kullanan kişi | `users` + `guvenlik` rolü | Evet (yalnız API'ye) |
| **Hem personel hem kullanıcı** | Ör. depo şefi: hem kartla girer hem panele girer | **İkisinde birden**, `employees.user_id` ile bağlı | Evet |

Kritik gözlem: **kümeler kesişiyor ama eşit değil.** Personelin çoğunun hesabı olmayacak;
kullanıcıların bir kısmı (harici muhasebeci vb.) personel olmayacak. Bu yüzden tek tabloya
sıkıştırmak (`users`'a kolon eklemek) yanlış olur — hem `users`'ı şişirir hem
"hesabı olmayan personel" kavramını imkânsız kılar.

### 3.3 ÖNERİLEN İLİŞKİ: `employees.user_id` NULL (bağlantı tablosu DEĞİL)

```
employees.user_id  INT NULL  →  users.id      (0..1 ilişki, FK YOK, UNIQUE index VAR)
```

| Seçenek | Değerlendirme |
|---|---|
| **`employees.user_id` nullable** | ✅ **ÖNERİLEN.** Bir personelin en çok bir hesabı olur, bir hesap en çok bir personele aittir. Tek kolon, tek `LEFT JOIN`, `NULL` = hesabı yok |
| `employee_user_link` tablosu | ❌ Yalnız **çoka-çok** gerekseydi anlamlı olurdu. Bir insanın iki uygulama hesabı senaryosu yok; tablo maliyeti karşılıksız |
| `users`'a personel kolonları eklemek | ❌ **Kesinlikle hayır.** Mevcut tabloya ALTER demek (yol haritasının "sıfır ALTER" güvencesini bozar), ve hesapsız personeli imkânsız kılar |

**Ek kural:** `UNIQUE KEY uq_emp_user (user_id)` — MySQL çoklu `NULL`'a izin verdiği için
"hesabı olmayan sınırsız personel" çalışır; aynı hesabın iki personele bağlanması ise
veritabanı düzeyinde engellenir.

### 3.4 Mevcut sistemle neden uyumlu

1. **Mevcut tablolara ALTER yok** — `users` hiç dokunulmadan kalır; rollback güvencesi korunur.
2. **Hesap modülüyle köprü hazır:** `account_transactions.user_id` ile `employees.user_id`
   aynı `users.id`'yi işaret eder → ileride "bu personelin masrafları" raporu **şema değişikliği
   olmadan** yazılabilir.
3. **Yetki sistemi karışmaz:** Yetki `users` üzerinden yürür. Personel olmak hiçbir yetki
   vermez — kapıdan geçen 200 kişi panele erişim kazanmaz.
4. **Depo mimarisine uyumlu:** `employees.depo` mevcut `depo_sql_column()` filtreleriyle
   çalışır, `depo=''` kuralı (her depoda görünür) korunur.
5. **Güvenlik görevlisi doğal biçimde ifade edilir:** `users` satırı + `guvenlik` rolü;
   aynı kişi personel de ise `employees.user_id` ile bağlanır — kendi giriş/çıkışı da tutulur.

---

## 4. UID NORMALİZASYONU — KANITLANDI ✅

### 4.1 Çalıştırılan kanıt

```
$ php scripts/pdks_faz0_uid_kanit.php
SONUÇ: 45 doğrulama geçti, 0 hata.
✓ docs/PDKS_NFC_YOL_HARITASI.md §C'deki UID iddialarının TAMAMI kanıtlandı.
```

Temel iddia, programatik olarak doğrulandı:

```
0x25A87ED7  →  631799511      ✓   (USB HID okuyucunun yazdığı sayı)
631799511   →  "25A87ED7"     ✓   (kanona dönüş)
0xD77EA825  →  3615402021     ✓   (ters gösterim)
ters(25A87ED7) = D77EA825     ✓
```

Ve §5'in asıl şartı — **üç gösterim de aynı karta çözülüyor**:

| Girdi | Kaynak | Kanona ulaşıyor mu |
|---|---|---|
| `631799511` | USB ondalık | ✅ |
| `25 A8 7E D7` | NFC hex | ✅ |
| `D7:7E:A8:25` | NFC hex | ✅ |
| `3615402021` | USB ondalık (ters okuyucu) | ✅ |

### 4.2 Kanonik gösterim (KESİNLEŞTİ)

> **Kanon = BÜYÜK HARF HEX · ayraçsız · baştaki sıfır baytları korunmuş ·
> uzunluk bayt sayısıyla sabit (8 / 14 / 20 hane).**

```
25A87ED7               4 bayt  → 8 hane
04A2B3C4D5E6F0         7 bayt  → 14 hane
0102030405060708090A  10 bayt  → 20 hane
```

### 4.3 Algoritma (Faz 1'de `config/pdks.php`'ye taşınacak)

`scripts/pdks_faz0_uid_kanit.php` içinde **çalışır hâlde** duruyor:

| Fonksiyon | İş |
|---|---|
| `pdks_uid_hex_normalize($ham)` | `:` `-` `.` boşluk `0x` temizler, büyütür, doğrular → kanon veya `null` |
| `pdks_dec_to_hex_string($dec)` | Ondalık string → hex string, **bcmath/gmp gerektirmeden** |
| `pdks_uid_from_decimal($dec, $bayt=null)` | USB ondalığı → kanon, **sola sıfır dolgulu** |
| `pdks_uid_reverse($kanon)` | **Bayt** bazında ters çevirme (nibble değil) |
| `pdks_uid_to_decimal($kanon)` | Kanon → işaretsiz ondalık (yalnız gösterim/teşhis) |
| `pdks_uid_adaylari($ham, $kaynak)` | Alias aramasında kullanılacak aday kümesi |

### 4.4 🔴 FAZ 0'DA BULUNAN YENİ KURAL — otomatik tespit YASAK

Yol haritasında **yoktu**, test sırasında ortaya çıktı:

```
'12345678'  HEX olarak okunursa → 12345678   (kart A)
'12345678'  ONDALIK okunursa    → 00BC614E   (kart B)
```

**Aynı metin iki farklı kartı işaret ediyor.** 8 haneli bir sayı hem geçerli 4 baytlık
hex hem geçerli ondalıktır. Girdinin biçimine bakıp "bu hex mi ondalık mı" diye
**tahmin eden** bir kod, iki kartı sessizce karıştırır.

> **KURAL: `pdks_uid_adaylari()` `$kaynak` parametresi olmadan çalışmaz**
> (`usb_decimal` | `nfc_hex`). Kaynağı çağıran taraf **bildirir**:
> USB tanımlama ekranı `usb_decimal`, Android istemci `nfc_hex` gönderir.
> Bilinmeyen kaynak → **boş aday listesi** (fail-closed).

### 4.5 Doğrulama kuralları (kesinleşti)

| Kural | Karar |
|---|---|
| Kabul edilen uzunluklar | **4, 7, 10 bayt** (ISO/IEC 14443-3 tek/çift/üçlü kaskad) |
| Tek sayıda hex hanesi | Reddedilir (tam bayt olmalı) |
| 3, 5, 8 bayt gibi uzunluklar | Reddedilir |
| Baştaki sıfır baytı | **KORUNUR** — `2467966` → `0025A87E`, asla `25A87E` değil |
| Küçük harf hex | Kabul, büyütülür |
| `:` `-` `.` boşluk ayraçları | Kabul, temizlenir |
| `0x` öneki | Kabul, atılır |
| Ondalıkta binlik ayracı (`631.799.511`) | Kabul (Excel kopyala-yapıştır gerçeği) |
| Negatif / harfli / boş | Reddedilir → `null` |
| 10 bayttan uzun ondalık | Reddedilir |
| Palindrom UID (`A5A5A5A5`) | Kendi tersi → alias listesi tekilleşir, çift kayıt olmaz |

### 4.6 ⚠ bcmath/gmp bulunamadı → uygulamayı bağlayan karar

Bu ortamda **ne `bcmath` ne `gmp`** kurulu. Paylaşımlı hostingde de garanti değil.

- 4 bayt (32 bit) ve 7 bayt (56 bit) PHP'nin 64-bit tamsayısına sığar.
- **10 bayt = 80 bit → sığmaz.** `hexdec()`/`dechex()` sessizce **float'a düşer ve
  hassasiyet kaybeder** — yani yanlış UID üretir, hata vermeden.

> **KURAL:** Ondalık ↔ hex dönüşümünde `hexdec()`/`dechex()`/`intval()`
> **tam UID üzerinde kullanılmaz.** Dönüşüm, `pdks_dec_to_hex_string()`
> string aritmetiğiyle yapılır. (Test #6 bunu 10 baytta doğruluyor.)

### 4.7 Veritabanı kolon önerisi (kesinleşti)

| Kolon | Tip | Gerekçe |
|---|---|---|
| `employee_cards.uid_hex` | **`VARCHAR(32)`** + `UNIQUE` | Gerçek azami 20 hane (10 bayt); 32 rahat pay bırakır. Sabit `CHAR` değil — 4 ve 10 bayt bir arada yaşayacak |
| `employee_card_uids.uid_hex` | **`VARCHAR(32)`** + `UNIQUE` | Aynı |
| `employee_cards.uid_bytes` | `TINYINT` (4/7/10) | Ondalık→hex dolgu uzunluğu; ondalıktan geri dönüşü belirsizlikten kurtarır |
| `employee_cards.uid_decimal` | `VARCHAR(24)` | 10 bayt ondalığı 25 haneye kadar çıkabilir → **`VARCHAR(25)` yapılacak** (yol haritasındaki 24 düzeltildi) |
| `attendance_events.card_uid_snapshot` | `VARCHAR(32)` | Kartla aynı |

**Collation notu:** Tablolar `utf8mb4_unicode_ci`; bu, `UNIQUE`'i **harf duyarsız** yapar.
Biz zaten daima büyük harfe normalize ettiğimiz için sorun değil — üstelik yanlışlıkla
küçük harfle gelen bir sorgu da eşleşir. **Güvenlik normalizasyondadır, collation'a
bel bağlanmaz.**

---

## 5. ANDROID `getId()` BAYT SIRASI TESTİ

### 5.1 Durum: teşhis uygulaması **hazır**, ölçüm **sizde**

Bu ortamda Android SDK yok (`ANDROID_HOME` boş; yalnız Gradle 8.14.3 + JDK 21 var),
dolayısıyla APK **derlenemedi**. Kaynak kodu hazırlandı ve **derlenmemiş olduğunu
açıkça belirtiyorum** — Android Studio'nun ilk senkronizasyonunda sürüm uyarısı çıkabilir.

```
tools/nfc_uid_tani/
├── README.md                    ← derleme + test adımları + beklenen ekran çıktısı
├── settings.gradle.kts
├── build.gradle.kts
├── gradle.properties
└── app/
    ├── build.gradle.kts         ← BAĞIMLILIK YOK (AndroidX bile yok) → APK ~100 KB
    └── src/main/
        ├── AndroidManifest.xml  ← yalnız android.permission.NFC
        └── java/com/asyafresh/nfcuidtani/MainActivity.kt
```

**Uygulamanın yaptığı tek şey:** `tag.id` bayt dizisini **hiçbir çeviri yapmadan** göstermek.
Ağa çıkmaz, veri saklamaz, sunucuya bağlanmaz, üretim sistemine dokunmaz.

Kullandığı yöntem, üretimde de kullanılacak olanla aynı:

```kotlin
a.enableReaderMode(this, { tag -> goster(tag) },
    FLAG_READER_NFC_A or … or FLAG_READER_SKIP_NDEF_CHECK, null)
```

### 5.2 Ekranda gösterilenler

`UID uzunluğu (bayt)` · `getId() ham baytlar` · `HEX (API sırası)` · `HEX (ters)` ·
`Ondalık (API)` · `Ondalık (ters)` · `ATQA / SAK` · `MifareClassic?` · `Tech listesi`

Ayrıca uygulama **kararı kendisi yazar** — yorumlamanıza gerek yok:

```
✓ getId() = USB ile AYNI YÖN   →  KANON = getId() sırası (çevirme YOK)
⚠ getId() USB'nin TERSİ        →  KANON = getId() ters çevrilmiş hâli
```

### 5.3 Test adımları

1. Android Studio → **Open** → `tools/nfc_uid_tani` → **Run ▶** (telefon USB ile bağlı).
   *(Android Studio yoksa: yeni "Empty Views Activity" projesi açıp `MainActivity.kt` ve
   `AndroidManifest.xml` içeriklerini değiştirmek yeterli — `README.md`'de yazılı.)*
2. Uygulamayı aç → **"⏳ Kart bekleniyor…"**
3. **USB okuyucuda `631799511` veren AYNI fiziksel kartı** okut.
4. "SONUCU KOPYALA" → bana gönderin (veya ekran görüntüsü).

### 5.4 Beklenen sonuç ve iki ihtimalin de anlamı

**Beklentim `25A87ED7` (Durum A).** Gerekçe: ISO/IEC 14443-3'te kart, anticollision
sırasında UID0 baytını **ilk** gönderir ve Android `getId()` bu sırayı korur; USB
okuyucunuz da aynı diziyi big-endian ondalık yazmış görünüyor. Ama bu bir **beklentidir**,
kanon değildir — bu yüzden ölçülüyor.

### 5.5 ⚠ Bu ölçüm Faz 1'i engelliyor mu? → **HAYIR**

| Sonuç | Şemaya etkisi | Faz 1'e etkisi |
|---|---|---|
| Durum A (`25A87ED7`) | **Yok** | Yok |
| Durum B (`D77EA825`) | **Yok** | Yok |

Sebep: `employee_card_uids` alias tablosu her iki gösterimi de aynı karta bağlar.
Ölçüm yalnız (a) hangi gösterimin `kind='canonical'` etiketleneceğini ve
(b) Android istemcisinin ne göndereceğini belirler — **ikisi de Faz 2 konusudur.**

**Faz 1'de USB ile tanımlanan kartlar, ölçüm sonucu ne çıkarsa çıksın doğru çalışır**,
çünkü her tanımlamada hem kanon hem ters alias yazılır.

---

## 6. USB HID OKUYUCU — GEREKSİNİMLER

### 6.1 Doğrulanan davranış

| Gözlem | Kaynak | Durum |
|---|---|---|
| Klavye (HID) gibi davranıyor, sürücü gerekmiyor | Sizin ölçümünüz | ✅ Doğrulandı |
| Boş Excel hücresine `631799511` yazdı | Sizin ölçümünüz | ✅ Doğrulandı |
| Bu değer `0x25A87ED7`'nin big-endian ondalığı | `pdks_faz0_uid_kanit.php` | ✅ Kanıtlandı |

### 6.2 ⏳ Hâlâ ölçülmemiş üç davranış (2 dakikalık test)

Not Defteri'ne **üç farklı kart** okutup şunları not edin:

1. **Sonda Enter var mı?** (imleç alt satıra atlıyor mu, yoksa aynı satırda mı kalıyor?)
   → Giriş kutusunun `keydown` işlemesini belirler.
2. **Başta sıfır var mı?** (`0631799511` gibi, hep aynı hane sayısı mı?)
   → Sabit 10 hane ise `uid_bytes` çıkarımı kesinleşir.
3. **Araya `Tab` veya öneki var mı?** (bazı okuyucular `;` ya da `%` önekler)
   → Temizleme kuralını belirler.

**Bunların hiçbiri Faz 1'i engellemiyor:** `pdks_uid_from_decimal()` boşluk, binlik
ayracı ve baştaki sıfırları zaten tolere ediyor (test #9), Enter'ı da giriş kutusu
her hâlükârda yakalayacak. Ölçüm yalnız kutuyu **daha az sürtünmeli** yapar.

### 6.3 Kart tanımlama alanının karşılaması gereken davranışlar (Faz 1 tasarımı)

| Gereksinim | Karar |
|---|---|
| Yalnız rakam | Girdi `[0-9]` dışını **sessizce yok sayar**; kaynak `usb_decimal` olarak **sabittir** (§4.4) |
| Sondaki Enter | `keydown` yakalanır, `preventDefault()` → **form gönderilmez**, AJAX sorgu tetiklenir |
| Odak davranışı | Kutu sayfa açılışında odaklanır ve okuma sonrası **tekrar odaklanır** (arka arkaya kart tanımlama) |
| Elle yazım kazası | Yazılan değer **hemen kanona çevrilip gösterilir** (`631799511 → 25A87ED7`); kullanıcı ne kaydedeceğini görmeden butona basamaz |
| Ondalık→hex çevirme | **Kullanıcıdan asla istenmez.** Çeviri PHP'de (tek otorite), JS yalnız gösterir |
| Doğrulama | Geçersiz uzunluk/karakter → buton **pasif** + sebep yazılı (`.btn:disabled` global kuralı zaten var) |
| Mükerrer kart tespiti | Kaydetmeden önce alias sorgusu: UID zaten varsa **"Bu kart ⟨personel⟩'e tanımlı"** uyarısı; sessizce üzerine yazılmaz |
| Ters-alias çakışması | UID'nin **tersi** başka bir karta aitse (≈4 milyarda 1) tanımlama reddedilir, sebep yazılır, audit'e düşer |
| Yanlış personel seçimi | Personel **önce** seçilir; seçilmeden kutu etkin olmaz |

---

## 7. GÜVENLİK BULGULARI — SINIFLANDIRMA

> Talebiniz gereği bu bir kimlik doğrulama yeniden yazımı **değildir**.
> Her bulgu için **en küçük** makul önlem verilmiştir.

### 7.1 `asya_session` token'ı DB'de hash'siz

**Sınıf: `AYRI GÜVENLİK SERTLEŞTİRME GÖREVİ` (CAN BE SEPARATE TASK)**

| | |
|---|---|
| Gerçek | `user_sessions.token` açık metin. DB okuma erişimi olan biri oturumları ele geçirebilir |
| PDKS bunu büyütüyor mu | **Hayır.** `api_pdks.php` bu token'ı **hiç kullanmaz**; yeni tablolar (`attendance_devices.token_hash`, `attendance_api_sessions.token_hash`) SHA-256 saklar |
| Neden bloker değil | Saldırganın DB okuma yetkisi zaten varsa puantaj verisini **doğrudan** okuyabilir; oturum çalmak ek bir kapı açmaz. Risk artışı **sıfır** |
| En küçük önlem | Faz 1/2'de **hiçbir şey**. Ayrı bir görev olarak `docs/NEXT_TASKS.md`'ye yazılır; yapılırsa geçiş şöyledir: `token_hash` kolonu eklenir, doğrulama "hash varsa hash'e, yoksa eski kolona bak" olur, 24 saatte tüm oturumlar doğal olarak döner, eski kolon düşürülür |

### 7.2 `login.php`'de brute-force koruması yok

**Sınıf: `ÜRETİMDEN ÖNCE DÜZELTİLMELİ` (SHOULD FIX BEFORE PRODUCTION)**

| | |
|---|---|
| Gerçek | Başarısız giriş yalnız `audit_log`'a (`login_failed`) yazılıyor; gecikme, kilit veya CAPTCHA yok. Sınırsız parola denemesi mümkün |
| PDKS bunu büyütüyor mu | **Evet, dolaylı olarak.** Kapıda duran bir telefonda yaşayan `guvenlik` hesabı ekleniyor; bu hesabın parolası basit seçilmeye müsait ve fiziksel olarak erişilebilir bir yerde kullanılıyor |
| Neden Faz 1'i engellemiyor | Faz 1'de ne `guvenlik` rolü ne API girişi var. Faz 2 ile birlikte üretime çıkmalı |

### 7.3 🔴 YENİ BULGU — `api_pdks.php auth/login` yeni bir saldırı yüzeyi

**Sınıf: `NFC V1 İÇİN BLOKER` (BLOCKER FOR NFC V1)**

| | |
|---|---|
| Gerçek | Faz 2'de eklenecek `auth/login` ucu, **kimlik doğrulamadan önce** parola kabul eden yeni bir uçtur. Hız sınırı olmadan eklenirse, `login.php`'deki mevcut açığı **yeni ve otomasyona daha uygun** bir yüzeyle çoğaltır (JSON uç, tarayıcı gerekmez) |
| Neden bloker | Bu, miras alınan bir risk değil; **bizim ekleyeceğimiz** bir risktir. Hız sınırı olmayan bir API login ucu üretime çıkarılamaz |
| **En küçük önlem — yeni tablo GEREKTİRMEZ** | `audit_log` zaten `login_failed` kayıtlarını `ip` + `created_at(3)` ile tutuyor. Giriş denemesinden önce tek `COUNT`: son 15 dakikada aynı IP veya aynı kullanıcı adı için **10'dan fazla** başarısızlık varsa 60 sn gecikmeli 429 döndür |

```sql
-- Önlemin tamamı bu sorgudur (yeni tablo yok, yeni kolon yok):
SELECT COUNT(*) FROM audit_log
 WHERE action = 'login_failed'
   AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
   AND (ip = :ip OR JSON_UNQUOTE(JSON_EXTRACT(new_values,'$.username')) = :kadi);
```

> Aynı fonksiyon `login.php`'ye de takılırsa **§7.2 de aynı anda kapanır** —
> iki bulgu tek, küçük bir yardımcıyla çözülür. Faz 2'de önerdiğim budur.

### 7.4 Bilgi — bu fazda tespit edilen, PDKS dışı iki nokta

Bunlar **PDKS bulgusu değildir**, değiştirilmemiştir, bilgi olarak kaydedilmiştir:

- `halkayit/api.php`'de **CSRF kontrolü yok**; cookie'deki `SameSite=Lax` fiilen koruyor (§2.2).
- Deploy webhook **Secret'ı boş** (`docs/DEPLOY_WORKFLOW.md`'de zaten belgeli; hazır çözüm
  `scripts/deploy_webhook.php`). PDKS bunu büyütmez ama kapıya bakan bir sistem eklenmeden
  önce kapatılması **tavsiye edilir** (karar #9).

---

## 8. CİHAZ YETKİLENDİRME MODELİ

### 8.1 Seçeneklerin karşılaştırması

| | Model | Güvenlik | Bakım | Kayıp telefon | Değerlendirme |
|---|---|---|---|---|---|
| 1 | **Yalnız güvenlik kullanıcı girişi** | Zayıf — parolayı bilen **herhangi** bir telefondan kayıt açılabilir; cihaz kavramı yok | En kolay | Yalnız parola değişimi (tüm kapıları etkiler) | ❌ §7'deki "yetkili cihaz" şartını karşılamaz |
| 2 | **Kullanıcı girişi + APK'ya gömülü ortak sır** | Yanıltıcı — sır **her APK'da aynı**, bir telefon çözülünce tüm kurulum çöker; APK geri derlenebilir | Kolay ama sır rotasyonu = tüm telefonlara yeni APK | Mümkün değil (sır ortak) | ❌ Güvenlik hissi verir, sağlamaz |
| 3 | **Token tabanlı cihaz kaydı** (tek kullanımlık kod → cihaza özel token) | Her cihazın **kendi** token'ı var; sunucu üretir, hash'li saklanır; sır APK'da yok | Orta — admin ekranında kod üretme + iptal | **Tek satır:** `status='iptal'`, `token_hash=NULL` → ilk istekte 401 | ✅ **ÖNERİLEN** |
| 4 | **İstemci sertifikası (mTLS) / donanım anahtarı** | En yüksek | Yüksek — CA yönetimi, sertifika dağıtımı/yenileme, paylaşımlı hostingde mTLS **genelde mümkün değil** | Sertifika iptal listesi | ❌ 2–3 telefon için orantısız; hosting muhtemelen desteklemiyor |

### 8.2 Önerilen model — 3 (token tabanlı kayıt)

```
① Admin  → pdks_cihazlar.php → "Yeni Cihaz": ad + kapı seçilir
          → sunucu 8 haneli TEK KULLANIMLIK kod üretir, 15 dakika geçerli
② Telefon → kodu girer + device_uuid/model/sürüm gönderir
          → sunucu kodu doğrular, used_at damgalar, 32 baytlık token üretir
          → token'ın SHA-256'sı DB'ye; HAM token yalnız bu cevapta, bir kez
③ Telefon → token'ı Android KeyStore destekli EncryptedSharedPreferences'ta saklar
④ Her istek → X-PDKS-Device: <token>  +  Authorization: Bearer <oturum token>
```

**Neden bu model:**
- Sır **APK'da değil**, cihaz başına ve sunucu üretimi → bir telefonun ele geçmesi diğerlerini etkilemez.
- `device_uuid`'ye **güvenilmez**; yetkiyi yalnız token verir (talebinizdeki §16 şartı).
- Kayıt penceresi 15 dakika + tek kullanım → kod sızsa bile kısa ömürlü.
- Ek altyapı **sıfır**: CA yok, sertifika yok, push servisi yok.

### 8.3 Kayıp telefon iptali

```
pdks_cihazlar.php → cihaz satırı → [İPTAL ET]
  → status='iptal', token_hash=NULL, revoked_at/by/reason yazılır
  → attendance_api_sessions'ta o device_id'nin TÜM oturumları revoked_at=NOW()
  → audit_log_event('device_revoke','pdks',$id,$eski,$yeni)
  → telefonun BİR SONRAKİ isteği 401 → uygulama "BU CİHAZ YETKİLENDİRİLMEMİŞ" ekranına döner
```

Gecikme yok (token her istekte doğrulanır), uzaktan komut gerekmez, telefonun
internete çıkması bile gerekmez — yetkisi zaten sunucuda biter.

**Not:** İptal, o cihazın **geçmiş hareketlerini** silmez. Hareketler geçerlidir;
yalnız gelecekteki erişim kapanır.

---

## 9. §P KARARLARININ GÖZDEN GEÇİRİLMESİ

### 9.1 TEKNİK kararlar — güvenle önerebilirim

| # | Soru | Önerim | Gerekçe | Sonradan değiştirmenin bedeli | Faz 1'i engeller mi |
|---|---|---|---|---|---|
| **1** | Kart devri geçmişi: 2 tablo mu 3 tablo mu | **2 tablo** (`employee_cards` + alias), devir = `employee_id` güncelle + audit | Hareketler `employee_id` + `card_uid_snapshot`'ı kendi içinde taşıdığı için geçmiş zaten bozulmaz | **Orta** — sonradan `employee_card_assignments` eklemek yeni tablo + geri dolum demek; veri kaybı yok | ✅ **EVET** (şema) |
| **3** | Güvenlik rolünün web erişimi | **Yok** — yalnız `attendance.scan` | En az yetki. Çalınan hesap web panelinde işe yaramaz | **Düşük** — rol yetkisi tek satır, istendiği an eklenir | ❌ Hayır (Faz 2) |
| **8** | `login.php` brute-force koruması | **Faz 2'ye dahil** — API login ucu için zaten **zorunlu** (§7.3), aynı yardımcı ikisini de kapatır | Yeni açtığımız yüzey; ek maliyeti neredeyse sıfır | **Yüksek** — üretimde açık kalırsa istismar edilebilir | ❌ Hayır (Faz 2) |
| **9** | Deploy webhook Secret'ı | **Faz 0/1 sırasında kapatılması tavsiye** — hazır şablon mevcut | Mevcut risk; kapıya bakan sistem eklenmeden kapatılması doğru sıralama | **Düşük** — her an yapılabilir | ❌ Hayır |
| **10** *(yeni)* | UID kaynağı otomatik tespit edilsin mi | **HAYIR** — kaynak bildirilir (§4.4) | `12345678` hem hex hem ondalık geçerli; tahmin iki kartı karıştırır | **Çok yüksek** — yanlış eşleşen kartlar sessizdir, fark edilmesi aylar sürer | ✅ **EVET** (Faz 1 UI + API sözleşmesi) |
| **11** *(yeni)* | Personel ↔ kullanıcı ilişkisi | **`employees.user_id` NULL + UNIQUE** (§3.3) | 0..1 ilişki; bağlantı tablosu karşılıksız maliyet | **Düşük** — çoka-çok gerekirse sonradan tablo eklenebilir | ✅ **EVET** (şema) |
| **12** *(yeni)* | `uid_decimal` kolon boyu | **`VARCHAR(25)`** (yol haritasındaki 24 düzeltildi) | 10 baytlık UID ondalığı 25 haneye çıkabilir | **Düşük** | ✅ **EVET** (şema, önemsiz) |

### 9.2 İŞ KARARLARI — onayınız gerekiyor 🔴

Bunları **sizin adınıza sessizce seçmem doğru olmaz**; hukuki, operasyonel veya
mali sonuçları var.

| # | Soru | Önerim | Neden sizin kararınız | Sonradan değiştirmenin bedeli | Faz 1'i engeller mi |
|---|---|---|---|---|---|
| **2** | Personelin **tam TC kimlik numarası** saklansın mı | **Saklanmasın** — yalnız son 4 hane | **KVKK/hukuki sorumluluk sizde.** Bordro/SGK entegrasyonu planınız varsa gerekebilir; yoksa saklamak karşılıksız risktir | **Düşük** (kolon sonradan eklenir) ama **geri dolum** gerekir: 200 personelin TC'si elle girilir | ✅ **EVET** (şema) |
| **4** | Güvenlik görevlisi önerinin **tersini** seçebilsin mi (giriş yerine çıkış) | **Hayır** — yalnız `attendance.manual` yetkisiyle web'den | En sık gerçek hata "çift giriş"tir; serbest seçim onu **yakalanamaz** hâle getirir. Ama bu sizin operasyon kuralınız | **Düşük** — davranış değişikliği, veri değişmez | ❌ Hayır (Faz 2) |
| **5** | Mükerrer okuma **cooldown** süresi | **20 saniye** | Kapı akış hızınıza bağlı: vardiya değişiminde 40 kişi arka arkaya geçiyorsa kısa, seyrek geçişte uzun olmalı | **Sıfır** — tek sabit, her an değişir | ❌ Hayır |
| **6** | **Offline mod** V1'e dahil mi | **Hayır** — V1 online-only; hazırlık kolonları şimdiden açılır | Kapıdaki internet güvenilirliğini **siz** biliyorsunuz. Offline, Faz 2 süresini ~2 katına çıkarır | **Düşük** — `time_source`/`device_reported_at` kolonları Faz 2'de zaten açılıyor, migration gerekmez | ❌ Hayır (Faz 2 kapsamı) |
| **7** | Bir kapıdan girip **başka kapıdan** çıkmak serbest mi | **Serbest** (kapı bağımsız) | Tesis düzeninize bağlı. Tek kapıda hiç fark etmez | **Düşük** — sorgu kuralı, veri değişmez | ❌ Hayır (Faz 2) |
| **13** *(yeni)* | Zaman otoritesi: sapma çıkarsa **A mı C mi** | Ölçüme bağlı (§1.5) | Bordro doğruluğu; ayrıca "tüm uygulamayı düzeltelim mi" sorusu **sizin** kapsam kararınız | **Yüksek** — yanlış saatle yazılmış hareketler sonradan toplu düzeltme ister | ❌ Hayır (**Faz 2'yi** engeller) |

### 9.3 Ayrıca sizden bilgi bekleyenler

- **Kapı adları ve sayısı** (bugün "Ana Giriş" varsayıyorum) — Faz 2 kurulumu.
- **Kaç Android telefon, hangi model** — NFC donanımı ve Android sürümü doğrulanmalı.
- **Vardiya saatleri** — Faz 4 için; Faz 3'teki "geç gelen" eşiği geçici olarak **08:15**.
- **Yaklaşık personel sayısı** — Faz 1 veri girişi eforu ve fotoğraf depolama boyutu için.

---

## 10. FAZ 1 HAZIR MI?

### 10.1 Verdikt: **KOŞULLU HAZIR** 🟡

**Teknik olarak hazır.** Faz 1'i (personel kartoteksi + kart yönetimi + USB tanımlama)
bloke eden **hiçbir teknik bilinmeyen kalmadı**:

| Bilinmeyen | Durum |
|---|---|
| UID normalizasyon algoritması | ✅ **Kanıtlandı** (45/45) |
| Kanonik gösterim ve kolon tipleri | ✅ Kesinleşti |
| bcmath/gmp yokluğu | ✅ Çözüldü (string aritmetiği) |
| Personel tablosu gerçekten yok mu | ✅ Doğrulandı |
| Personel ↔ kullanıcı ilişkisi | ✅ Karara bağlandı (`user_id` NULL) |
| API auth deseni | ✅ Karara bağlandı (Faz 2'de uygulanacak) |
| Cihaz yetkilendirme modeli | ✅ Karara bağlandı (Faz 2'de uygulanacak) |
| MySQL saat dilimi | ⏳ Ölçüm bekliyor — **Faz 1'i etkilemiyor** (§1.6), **Faz 2'yi bağlıyor** |
| Android `getId()` sırası | ⏳ Ölçüm bekliyor — **şemayı etkilemiyor** (§5.5) |
| USB Enter/sıfır dolgu davranışı | ⏳ Ölçüm bekliyor — algoritma zaten tolere ediyor (§6.2) |

### 10.2 Faz 1'i açmak için gereken tek şey: **üç karar**

Şemaya giren ve sonradan değiştirilmesi geri dolum gerektiren kararlar:

1. **#2 — Tam TC kimlik saklanacak mı?** (önerim: hayır) 🔴 **İŞ KARARI**
2. **#1 — Kart devir geçmişi: 2 tablo mu?** (önerim: evet, 2 tablo) ⚙️ teknik, onay isterim
3. **#11 — `employees.user_id` NULL yaklaşımı?** (önerim: evet) ⚙️ teknik, onay isterim

Bu üçüne "onaylıyorum" demeniz Faz 1'i başlatmak için **yeterlidir**.
Kalan kararlar (#3, #4, #5, #6, #7, #8, #9, #13) Faz 2'ye kadar beklenebilir.

### 10.3 Faz 2 için ÖNCEDEN kapatılması gerekenler

| Gereklilik | Neden |
|---|---|
| §1.3 MySQL saat dilimi ölçümü | `event_time` otoritesi buna bağlı — bordro doğruluğu |
| §5.3 Android `getId()` ölçümü | İstemcinin göndereceği gösterim |
| §7.3 API login hız sınırı | **BLOKER** — hız sınırsız login ucu üretime çıkamaz |
| #13, #4, #5, #6, #7 kararları | Faz 2 kapsamını ve davranışını belirler |

### 10.4 DURDUM ⏹

Talebiniz gereği **burada duruyorum**. Faz 1 başlatılmadı; migration, tablo, Android
üretim uygulaması ve deploy yapılmadı. **Açık onayınızı bekliyorum.**

---

## EK — Bu fazda eklenen dosyalar

| Dosya | Tür | Web erişimi |
|---|---|---|
| `docs/PDKS_NFC_FAZ0_DOGRULAMA.md` | Bu rapor | — |
| `scripts/pdks_faz0_uid_kanit.php` | CLI test — **çalıştırıldı, 45/45 geçti** | ❌ `scripts/.htaccess` kapalı |
| `scripts/pdks_faz0_zaman.php` | CLI ölçüm — salt okunur, `config/db.php` include **etmez** | ❌ kapalı |
| `tools/nfc_uid_tani/**` | Android teşhis kaynağı (derlenmedi) | ❌ `tools/.htaccess` eklendi |

**Uygulamanın çalışan hiçbir dosyası değiştirilmedi.** `git status` ile doğrulanabilir:
tüm değişiklikler yeni dosya eklemesidir.
