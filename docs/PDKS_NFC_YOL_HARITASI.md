# Personel NFC Giriş/Çıkış (PDKS) — Entegrasyon Yol Haritası

**Durum:** ONAY BEKLİYOR — hiçbir kod yazılmadı, hiçbir migration çalıştırılmadı, canlı veriye dokunulmadı.
**Hazırlayan:** Claude Code · **Tarih:** 2026-09-14 · **Branch:** `claude/nfc-attendance-roadmap-z14alg`
**Kapsam:** Mevcut Asya Fresh uygulamasına, mevcut mimariyi bozmadan eklenecek personel giriş/çıkış modülü.

> Bu belge yalnız bir plandır. Onayladıktan sonra Faz Faz uygulanacaktır.
> Her fazın sonunda durup onay isteyeceğim.

---

## ⛳ FAZ 0 TAMAMLANDI — 2026-09-14

**Doğrulama raporu: [`docs/PDKS_NFC_FAZ0_DOGRULAMA.md`](PDKS_NFC_FAZ0_DOGRULAMA.md)**

Faz 0'ın bu belgeyi değiştiren bulguları:

| Bulgu | Bu belgedeki etkisi |
|---|---|
| UID algoritması **kanıtlandı** (45/45; Faz 1'de `scripts/pdks_uid_smoke.php`) | §C'deki matematiksel iddialar doğrulandı. **Alias/eşleştirme mimarisi Faz 1'de sonradan düzeltildi — aşağıdaki ikinci banner'a ve §C.4'e bakın** |
| 🔴 **YENİ KURAL:** UID kaynağı (hex/ondalık) otomatik tespit **edilemez** | §C.3'e ek: `pdks_uid_adaylari()` `$kaynak` parametresi ZORUNLU. `12345678` hem geçerli hex hem geçerli ondalık — tahmin iki kartı karıştırır |
| ⚠ `bcmath`/`gmp` **yok** sayılmalı | Ondalık↔hex dönüşümünde `hexdec()`/`dechex()` **tam UID üzerinde kullanılamaz** (10 bayt = 80 bit, float'a düşer). Saf string aritmetiği zorunlu |
| `uid_decimal` boyu | VARCHAR(24) → **VARCHAR(25)** (§D.2'de düzeltildi) |
| Personel ↔ kullanıcı ilişkisi karara bağlandı | **`employees.user_id` NULL + UNIQUE** — bağlantı tablosu değil (Faz 0 §3.3) |
| 🔴 **YENİ BLOKER:** `api_pdks.php auth/login` hız sınırı | §F.2 #12'ye ek: bu **yeni açtığımız** yüzeydir, "sonra yapılır" değildir. Önlem yeni tablo gerektirmez — `audit_log` üzerinden COUNT (Faz 0 §7.3) |
| Zaman otoritesi | ⏳ MySQL ölçümü bekliyor. **Faz 1'i engellemiyor** (Faz 1'de puantaj zaman damgası yok), **Faz 2'yi bağlıyor** (Faz 0 §1.6) |
| Android `getId()` sırası | ⏳ Teşhis APK'sı hazır (`tools/nfc_uid_tani/`). **Şemayı etkilemiyor** — ölçüm sonucu tek bir kaynak adaptörüne (deterministik, tüm kartlar için aynı) işlenecek, bkz. aşağıdaki düzeltme banner'ı |

**Faz 1 durumu: KOŞULLU HAZIR** — üç karar onayı bekliyor (Faz 0 §10.2):
#2 tam TC saklansın mı · #1 kart devri 2 tablo mu · #11 `employees.user_id` yaklaşımı.

---

## ⛳ FAZ 1 UID MODELİ DÜZELTMESİ — 2026-09-14

**Kayıt: [`docs/PDKS_FAZ1_SEMA.md`](PDKS_FAZ1_SEMA.md) §6a ("⚠ DÜZELTME — Otomatik bayt-tersi alias'ı KALDIRILDI")**

Faz 1'in ilk teslimatı, §C.4'te aşağıda anlatılan iki-katmanlı alias modelini
**fazla genelleştirmişti**: bir kartın kanonik UID'sinin **bayt-tersini** de
otomatik olarak "aynı fiziksel kartın başka bir gösterimi" sayıp ikinci bir
alias satırı yazıyordu. **Bu yanlıştı** — iki FARKLI fiziksel kartın kanonik
UID'leri birbirinin bayt-tersi olabilir (bir tesadüf, kanıt değil) ve otomatik
ters-alias, o gerçek ikinci kartın kaydını körü körüne reddederdi.

**§C.4'ü okurken şunu unutmayın:** aşağıdaki "kanonik + ters çevrilmiş
gösterim" örneği yalnız **tek bir kartın** `631799511` / `25A87ED7` gibi
**yazım farklarını** (aynı baytlar, farklı gösterim) anlatır — `D7:7E:A8:25`
gibi gerçekten **ters bayt sıralı** bir girdinin otomatik olarak aynı karta
bağlanacağı iddiası artık **geçerli değildir**. Düzeltilmiş model,
`docs/PDKS_FAZ1_SEMA.md` §6a'da tam olarak belgelenmiştir; kısaca:

- `employee_card_uids` artık yalnız kartın **kendi kanoniğini** yazar (1 satır/kart).
- Bayt sırası belirsizliği (Android `getId()` ölçümü), kart bazında değil,
  **kaynak adaptörünün içinde**, tüm kartlar için tutarlı tek bir dönüşüm
  olarak çözülecek.
- Kanıt: `scripts/pdks_db_smoke.php` — `25A87ED7` ve `D77EA825` iki ayrı,
  bağımsız kart olarak aynı anda kaydedilip doğru çözülüyor.

---

## 0. Yönetici Özeti

| Soru | Cevap |
|---|---|
| Yeni ERP mi? | **Hayır.** Mevcut uygulamaya 1 modül eklenir; `records`/`kantar`/`beyan` gibi. |
| Yeni framework? | **Hayır.** Saf PHP 8 + PDO + vanilla JS + mevcut `style.css` deseni. Laravel/React/Node YOK. |
| Yeni bağımlılık? | **PHP tarafında sıfır.** Excel/PDF için depoda zaten var olan PhpSpreadsheet + dompdf kullanılır. |
| Mevcut tablolara dokunulacak mı? | **HAYIR — tek bir ALTER yok.** Modül yalnız yeni tablolar açar. Rollback = kodu geri al, tablolar boş kalır. |
| Android | **Minimal native Kotlin istemci** (Web NFC yetersiz — gerekçe §8'de). |
| V1 offline çalışır mı? | **Hayır, bilinçli.** V1 online-only; bağlantı yoksa ekran bunu tartışmasız gösterir ve asla "kaydedildi" demez. |
| Kaç faz? | 6 faz. Faz 1 (personel + kart kayıt) tek başına bile işe yarar. |
| En büyük risk | **Aktif Depo mimarisi ile API'nin çakışması** (§B.4) ve **MySQL saat dilimi** (§J). İkisi de Faz 0'da ölçülür. |

### Onay verirseniz ilk yapılacak şey

Faz 0 — **tek satır üretim kodu yazmadan** 6 ölçüm (§Ek-1). Bunların sonucu olmadan şema kesinleşmez.

---

# BÖLÜM A — MEVCUT SİSTEM ENVANTERİ (inceleme sonucu)

Aşağıdakilerin tamamı depoda **okunarak** doğrulandı, varsayım değildir.

## A.1 Mimari

| Konu | Tespit | Kaynak |
|---|---|---|
| Framework | **Yok.** MVC yok, router yok, ORM yok. Her sayfa kendi başına bir `.php` dosyası. | kök dizin, 88 adet `*.php` |
| PHP | `composer.json` → `">=8.1"`, platform pini `8.1.0`. Her dosyada `declare(strict_types=1)` | `composer.json` |
| DB | MySQL/MariaDB, InnoDB, `utf8mb4_unicode_ci`. PDO, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, **`EMULATE_PREPARES=false`** | `config/db.php` |
| Bağımlılık | `phpoffice/phpspreadsheet ^2.3`, `dompdf/dompdf ^3.0` — `vendor/` **repoda commit'li** | `composer.json`, `vendor/` |
| Frontend | Vanilla JS (IIFE), build tool yok. Tek CSS (`assets/style.css`, ~239 KB), tek JS (`assets/app.js`, ~108 KB) | `assets/` |
| Modül istisnası | `maliyet.*` ve `hesap.*` **kendi CSS/JS dosyasını** kullanır (`assets/maliyet.css`, `assets/hesap.css`) — tek-CSS kuralının bilinçli istisnası | CLAUDE.md, `hesap_config.php` |
| PWA | `manifest.json` + `sw.js`, network-first, `CACHE_NAME='yukleme-plani-v217'`, `APP_SURUM='v217'` ikisi elle senkron tutuluyor | `sw.js`, `config/helpers.php:14` |
| Tema | Açık/Koyu/Sistem — `localStorage['asya_tema']` + `<html data-theme>`. **Varsayılan açık tema**, koyu seçenek var. | `config/helpers.php` render_header |

> **Düzeltme:** Talebinizde "mevcut koyu (dark) dashboard" deniyor. Gerçekte varsayılan **açık** tema,
> koyu tema kullanıcı tercihine bağlı. Yeni modül **iki temada da** doğru görünmek zorunda —
> renkleri sabit yazmayıp `var(--card)`, `var(--text)`, `var(--border)` token'larını kullanacağız.

> **Uyarı:** `SYSTEM_AUDIT_REPORT.md` **eskimiş**. "Authentication YOK" diyor; oysa tam çalışan bir
> cookie-session + rol sistemi var. O belgeyi referans almayın; `CLAUDE.md` + `docs/ARCHITECTURE.md` güncel.

## A.2 Kimlik doğrulama (gerçekte nasıl çalışıyor)

`config/auth.php`:

```
login.php → login_user() (password_verify) → create_session()
   → user_sessions tablosuna 64 hex karakter token
   → setcookie('asya_session', token, HttpOnly, SameSite=Lax, Secure=is_https())
   → 24 saat, her istekte kayan (sliding) uzatma, MySQL NOW() ile
→ depo_sec.php (ZORUNLU depo seçimi)
→ her sayfa: require_login() → enforce_active_depot() → require_perm('x.y')
```

Kritik ayrıntılar:

- **PHP `$_SESSION` oturum için kullanılmıyor** — yalnız CSRF token ve flash mesaj için.
  Gerçek oturum cookie + `user_sessions` tablosu.
- `user_sessions.token` **DB'de açık (hash'siz)** tutuluyor. Yeni modülde bu deseni
  tekrarlamayacağız (aşağıda §F.3), mevcut tabloyu da değiştirmeyeceğiz.
- **Brute-force koruması YOK.** `login.php` başarısız denemeyi yalnız `audit_log`'a yazıyor
  (`login_failed`), ne gecikme ne kilit var. Güvenlik personeli hesabı eklenince bu risk büyür (§F.6).
- `forbidden()` ve `csrf_check()` **JSON-aware** — AJAX'ta JSON 403 döner.

## A.3 Yetki sistemi

- Tablolar: `roles` · `role_permissions` · `user_roles` · `user_depolar`
- Fonksiyonlar: `can('perm')` · `is_admin()` · `require_perm()` · `require_any_perm()` · `user_primary_role()`
- **Yetki kataloğu kodda seed ediliyor**, arayüzde düzenlenmiyor:
  `config/helpers.php` içindeki `$all_p` dizisi + `$rp_map` rol→yetki haritası, `INSERT IGNORE` ile
  her istekte idempotent yazılıyor. `users.php` yalnız **kullanıcı→rol** atamasını yönetiyor.
- Mevcut roller: `admin` · `operator` · `viewer` · `muhasebe`
- **Sonuç:** Yeni yetki ve yeni rol eklemek = `helpers.php`'deki iki diziye ekleme. Tamamen additive,
  `INSERT IGNORE` olduğu için mevcut atamaları bozmaz.

## A.4 Aktif Depo mimarisi — modülün en kritik kısıtı

- Girişten sonra **tek depo seçimi ZORUNLU** (`depo_sec.php`), cookie `asya_depo`, 180 gün.
- `user_allowed_depots()` tüm filtrelerin girdiği **tek kapı**.
- Filtre yardımcıları: `depo_sql_records()` · `depo_sql_column()` · `depo_sql_in()` · `depo_sql_records_in()`
- **Atanmamış veri kuralı:** `depo=''` olan satır TÜM depolarda görünür (eski veri kaybolmasın diye).
- Depo eşleşmeleri **TR-duyarsız** (`depo_fold()`), depo adı değişince veri de güncelleniyor
  (`sync_depot_name_in_data()`), her deponun bir rengi var (`depot_color()` → `--depot-accent`).
- Depo listesinin kaynağı: `material_definitions` tablosunda `type='depo'` satırları.

> **Bu mimarinin API'ye etkisi kritiktir — bkz. §B.4.** Android istemcinin cookie'si yoktur,
> dolayısıyla aktif deposu da yoktur. `require_login()` çağıran bir API ucu, Android'e
> **HTML redirect** döndürür. `halkayit/api.php` aynı sorunu yaşamış ve `require_login()` yerine
> `current_user()` + `can()` ile elle çözmüş; biz de aynı deseni (cihaz token'ıyla) izleyeceğiz.

## A.5 Migration mekanizması

Üç katman, üçü de idempotent:

1. `config/db.php` → `db()` içinde, ilk bağlantıda çalışan `ALTER`/`CREATE TABLE IF NOT EXISTS` bloğu.
2. `config/helpers.php` → dosya sonundaki IIFE içinde daha büyük bir şema bloğu (users, roles, audit_log, customs_declarations…).
3. Modül bazlı tembel migration: `hesap_migrate()` — **yalnız o modülün sayfaları çağırır**, `static $done` ile tek sefer.
4. Elle yedek yol: `migrate.php` (admin, web) — paylaşımlı hostingde ALTER yetkisi yoksa tam hata mesajını gösterir.

Her yerde hata yutuluyor (`catch (PDOException) { error_log(...) }`) — bir migration
başarısız olursa sayfa çökmüyor.

> **Seçimimiz:** PDKS migration'ı **3. desen** (`pdks_migrate()`) olacak. `config/db.php`'ye
> EKLENMEYECEK — oradaki bir hata her sayfayı, yani tüm uygulamayı etkiler. Ayrıca `migrate.php`'ye
> elle çalıştırma girdileri eklenecek.

## A.6 Test altyapısı (var ve iyi)

| Katman | Örnek | Yöntem |
|---|---|---|
| Statik | `scripts/beyan_bildirim_smoke.php` | Kaynak kodda kural arar |
| Birim/mantık | `scripts/hesap_smoke.php` | **Bellek içi SQLite** + stub'lanmış `db()`/`can()`/`current_user()` |
| Render | `scripts/hesap_ui_smoke.php`, `scripts/beyan_ui_smoke.php` | Sayfayı gerçekten include eder, HTML/uyarı doğrular |
| Tarayıcı | `scripts/beyan_js_smoke.js` | Playwright + Chromium, yoksa kendini ATLAR |

Hepsi CLI-only, canlı DB'ye dokunmuyor. `scripts/.htaccess` web erişimini tamamen kapatıyor.
**PDKS aynı dört katmanı kullanacak.**

## A.7 Audit

`audit_log_event($action, $module, $record_id, $old, $new, $explicit_user_id)` →
`audit_log` tablosu (`DATETIME(3)` hassasiyet, JSON old/new, ip, user_agent).
`_audit_sanitize()` password/token/csrf/cookie/foto_data alanlarını siler, 1000 karakterde keser.
Asla exception atmaz. **PDKS için hazır ve yeterli** — ayrı bir audit tablosuna gerek yok.

## A.8 Dosya/fotoğraf yönetimi — iki farklı desen var

| Desen | Nerede | Değerlendirme |
|---|---|---|
| Base64 `LONGTEXT` kolonda | `kantar_fisleri.foto_data`, `loading_records.etiket_foto` | Cihazlar arası kolay, ama her okuma DB'den ~MB çekiyor |
| Gerçek dosya + yetkili endpoint | `uploads/hesap/` + `hesap_dosya.php` | Rastgele 32-hex ad, `finfo` MIME doğrulaması, `.htaccess` ile PHP çalıştırma kapalı, **erişimde kaydın görünürlüğü de kontrol ediliyor** |

> **Seçimimiz: ikinci desen.** Personel fotoğrafı her kart okutmada Android'e gidecek;
> base64-in-DB bunu yavaşlatır ve ETag/cache kullanılamaz. `uploads/personel/` + `personel_foto.php`.

## A.9 Deploy

`main`'e merge = canlı. GitHub push webhook → `https://nuverna.derspros.com.tr/deploy.php` → ~4 dakika.
Kullanıcının SSH'a girmesine gerek yok. Doğrulama: hard refresh → sidebar altındaki `APP_SURUM`.
**Webhook Secret BOŞ** (bilinen risk, `docs/DEPLOY_WORKFLOW.md`'de belgeli, hazır çözüm
`scripts/deploy_webhook.php` olarak duruyor ama kurulmamış).

## A.10 Personel verisi — MEVCUT DEĞİL

Aranan ama bulunamayan: `employees`, `personel`, `staff` benzeri bir tablo **yok**.

- `users` = **uygulamaya giriş yapan** kişiler (kullanıcı adı + parola). Depo/tır operasyonunu
  yapan işçilerin çoğunun burada kaydı olmayacaktır.
- `hesap` modülü "personel" derken `account_transactions.user_id` → `users.id` demektedir
  (masrafın sahibi olan **uygulama kullanıcısı**).
- `material_definitions` içinde `sofor` (şoför) türü bir **öneri listesi** var — kimlik kaydı değil.

> **Sonuç: Yeni `employees` tablosu ZORUNLU.** `users` tablosu bunun yerine kullanılamaz —
> bordro/puantaj kaydı ile uygulama hesabı farklı şeylerdir ve her personele login vermek
> hem gereksiz hem risklidir. İsteğe bağlı bir `employees.user_id` bağı bırakacağız
> (ileride Hesap modülüyle eşleştirmek isterseniz diye).

## A.11 Navigasyon konvansiyonu

Yeni bir modül eklemek **dört yere** dokunmayı gerektiriyor (CLAUDE.md kontrol listesinde yazılı):

1. `render_desktop_sidebar()` — link + `$p_*` yetki değişkeni + `$a_*` aktif sayfa tespiti
2. `render_footer()` — bottomnav (yalnız 5 sekme sığıyor, **PDKS oraya eklenmeyecek**)
3. `index.php` — kart grid
4. `render_header()` topnav (768–899px tablet aralığı)

---

# BÖLÜM B — MİMARİ KARARLAR

## B.1 Karar: Modül biçimi

**Karar:** `hesap` ve `maliyet` modüllerinin izlediği "kök dizinde önek'li sayfalar + kendi CSS/JS'i" deseni.

```
personel.php              Personel listesi
personel_form.php         Personel ekle/düzenle (fotoğraf yükleme dahil)
personel_foto.php         Fotoğraf servis ucu (yetki kontrollü)
personel_kartlar.php      Kart yönetimi + USB ile kart tanımlama
pdks.php                  Canlı durum panosu (İçeride kim var)
pdks_hareketler.php       Giriş/çıkış hareket listesi + düzeltme
pdks_rapor.php            Raporlar (Excel/PDF)
pdks_cihazlar.php         Android cihaz yönetimi (admin)
pdks_kapilar.php          Kapı/lokasyon tanımları (admin)
api_pdks.php              TEK JSON yönlendirici — Android istemci buraya konuşur
config/pdks.php           Şema + iş mantığı + yetki kapısı (tek otorite)
assets/pdks.css           Modül CSS'i — style.css'e DOKUNULMAZ
assets/pdks.js            Modül JS'i — app.js'e DOKUNULMAZ
```

**Gerekçe:** `assets/style.css` 239 KB ve tüm mobil düzenin tek kaynağı. Ona dokunmamak,
CLAUDE.md'nin 1 numaralı kuralı olan "mobil görünümü bozma"yı **yapısal olarak** garanti eder.
Ayrıca SW cache versiyonu artırma zorunluluğunu da hafifletir (yine de `APP_SURUM` artırılacak).

## B.2 Karar: İsimlendirme

- **Tablolar İngilizce**: `employees`, `employee_cards`, `attendance_events` — baskın konvansiyon
  (`loading_records`, `account_transactions`, `customs_declarations`) budur.
- **Durum/tip değerleri Türkçe**: `'giris'`, `'cikis'`, `'aktif'`, `'iptal'` — ekran dili Türkçe,
  SQL'i elle okuyan kişi de Türkçe okusun (`loading_records.durum='yuklendi'` emsali).
- **Dosya önekleri Türkçe**: `personel_*`, `pdks_*` — kullanıcı URL'de gördüğü için.

## B.3 Karar: Departman/görev listesi — mevcut Tanımlar ekranını kullan

`definition_types()`'a iki tür eklenecek: `'departman' => 'Departman'`, `'gorev' => 'Görev'`.

> **DİKKAT — iki yerde birden:** Yeni türler **`non_material_definition_types()`** listesine de
> eklenmek ZORUNDA. Aksi halde `pallet_material_types()` bunları "palete giydirilen sarf malzeme"
> sanar ve Departman, malzeme ekleme modalinde çıkar. (`pallet_material_types()` = tüm türler − malzeme olmayanlar.)

**Kazanç:** Departman yönetimi için yeni ekran yazmaya gerek yok; `definitions.php` zaten var,
yetkisi (`defs.write`) zaten var, `material_definitions` şeması değişmiyor.

## B.4 Karar: API ile Aktif Depo çakışmasının çözümü ⚠

**Sorun:** `require_login()` → `enforce_active_depot()` → depo cookie'si yoksa **HTML redirect**.
Android istemci bunu JSON sanır ve ayrıştıramaz. Ayrıca `user_allowed_depots()` cookie'ye bağlıdır,
cihazın cookie'si yoktur → tüm depo filtreleri yanlış davranır.

**Çözüm:** `api_pdks.php` **`require_login()` ÇAĞIRMAZ.** Kendi kapısı vardır:

```
Cihaz token'ı (Bearer)  →  attendance_devices satırı  →  gate_id  →  attendance_gates.depo
Güvenlik oturum token'ı →  users satırı               →  can('attendance.scan')
                        ↓
            İSTEĞİN DEPO BAĞLAMI = kapının deposu (cookie'den DEĞİL)
```

Yani: **API'de depo bağlamı cihaza/kapıya bağlıdır, oturuma değil.** Bu, `halkayit/api.php`'nin
`require_login()` yerine `current_user()` + `can()` kullanma gerekçesiyle aynı gerekçedir ve orada
zaten kanıtlanmış bir desendir.

Web sayfaları ise normal yolu kullanır: `require_login()` + `require_pdks('read')` + `depo_sql_column('depo')`.

## B.5 Karar: Yetki modeli

`config/helpers.php` içindeki `$all_p` dizisine eklenecek 8 yetki:

| Yetki | Ne açar |
|---|---|
| `attendance.read` | Hareket listesi, canlı pano |
| `attendance.scan` | **Yalnız API**: kart okut + giriş/çıkış onayla |
| `attendance.manual` | Web'den elle hareket girme (kart okutulamadığında) |
| `attendance.correct` | Var olan hareketi düzeltme/iptal (gerekçe zorunlu) |
| `attendance.report` | Rapor ekranı + Excel/PDF dışa aktarma |
| `attendance.employees` | Personel ekle/düzenle/pasifleştir |
| `attendance.cards` | Kart tanımla/iptal/devret |
| `attendance.admin` | Cihaz ve kapı yönetimi, cihaz iptali |

Rol haritası (`$rp_map`'e eklenecek):

| Rol | Yetkiler |
|---|---|
| `admin` | hepsi (zaten `$all_p` alıyor) |
| **`ik`** (yeni — "İnsan Kaynakları") | read, manual, correct, report, employees, cards |
| **`guvenlik`** (yeni — "Güvenlik") | **yalnız `attendance.scan`** |
| `operator` | — (varsayılan olarak hiçbiri; PDKS verisi hassastır) |
| `viewer` / `muhasebe` | — |

> **Güvenlik rolü bilerek çıplak.** O hesap bir kapıdaki telefonda duruyor; çalınırsa saldırganın
> eline geçen tek yetki "kart okut ve giriş/çıkış yaz" olmalı. `dashboard.read` bile verilmiyor,
> yani o hesapla web paneline girilirse her sayfa 403 döner. Bu **istenen** davranıştır;
> Faz 2'de bu role özel "Bu hesap yalnız kapı uygulaması içindir" bilgilendirme ekranı eklenecek.

> **Not:** `user_primary_role()` içindeki `ORDER BY CASE r.slug` listesinde yeni roller yok;
> `ELSE 5` ile en sona düşerler. Bir kullanıcı hem `admin` hem `guvenlik` ise rozet "Sistem Yöneticisi"
> gösterir — doğru davranış. Değişiklik gerekmiyor.

## B.6 Karar: Feature flag

`config/pdks.php` içinde `const PDKS_AKTIF = true|false`. Kapalıyken:
sidebar linki çizilmez, `index.php` kartı çizilmez, sayfalar 404 benzeri "modül kapalı" döner,
`api_pdks.php` 503 döner. Canlıya erken çıkıp kapalı bırakma imkânı verir.

---

# BÖLÜM C — UID NORMALİZASYONU (§5)

## C.1 Ölçtüğünüz verinin doğrulaması

Verdiğiniz değerleri kontrol ettim; **matematik birebir tutuyor**:

```
0x25A87ED7  = 0x25·2^24 + 0xA8·2^16 + 0x7E·2^8 + 0xD7
            = 620756992 + 11010048 + 32256 + 215
            = 631.799.511      ← USB okuyucunun Excel'e yazdığı sayı ✓

0xD77EA825  = 3607101440 + 8257536 + 43008 + 37
            = 3.615.402.021    ← "Reverse Decimal" olarak gösterilen sayı ✓
```

**Çıkarım:** USB HID okuyucu, bayt dizisi `25 A8 7E D7`'nin **big-endian (MSB-first)** ondalık
karşılığını yazıyor. Diğer Android uygulamasının `D7:7E:A8:25` göstermesi, o uygulamanın diziyi
ters çevirerek gösterdiği anlamına gelir. Kart tektir; **üç gösterim aynı fiziksel kartı anlatır.**

## C.2 Çözülmemiş tek soru (Faz 0'da ölçülecek)

Android `Tag.getId()` **hangi sırayı** döndürüyor: `[25,A8,7E,D7]` mi, `[D7,7E,A8,25]` mi?

Bunu **üçüncü parti uygulamaların ekran çıktısına bakarak belirlemeyeceğiz** — talebinizde de
haklı olarak vurguladığınız nokta bu. Faz 0'da kendi küçük test APK'mız `Tag.getId()`'yi
ham olarak ekrana basacak ve kanon bu ölçümle sabitlenecek.

NFC standardı açısından beklenti: `getId()`, kartın anticollision sırasında **ilk gönderdiği
bayttan başlayarak** (UID0..UID3) döner; MIFARE Classic 1K (ATQA `0004`, SAK `08`) için bu
4 bayttır. Beklentimiz `[25,A8,7E,D7]`, yani USB ile **aynı yönde**. Ama bu beklenti kanona
dönüşmeden önce ölçülecek.

## C.3 Kanonik gösterim

**Kanon = büyük harf HEX, ayraçsız, baştaki sıfırlar korunmuş, uzunluk baytla sabit.**

```
25A87ED7          (4 bayt → 8 hex karakter)
04A2B3C4D5E6F0    (7 bayt → 14 hex karakter)
```

Dönüşüm kuralları (hepsi `config/pdks.php` içinde, **tek** fonksiyon ailesinde):

```php
pdks_uid_hex_normalize(string $raw): ?string
    // "D7:7E:A8:25" → "D77EA825"   ( : - boşluk temizlenir )
    // "25a87ed7"    → "25A87ED7"
    // geçersiz karakter / tek sayıda hane / 4·7·10 bayt dışı uzunluk → null

pdks_uid_from_decimal(string $dec, int $bytes = 4): ?string
    // "631799511"  → "25A87ED7"
    // "0631799511" → "25A87ED7"   (okuyucu sıfır dolgu yapabilir)
    // ">0xFFFFFFFF" ise otomatik 7 bayta (14 hane) yükseltilir
    // ÖNEMLİ: baştaki sıfırlar ondalıkta KAYBOLUR → mutlaka $bytes kadar sola sıfır doldurulur
    //         (UID 00 25 A8 7E → 2467966 → "0025A87E", "25A87E" DEĞİL)

pdks_uid_reverse(string $hex): string
    // "25A87ED7" → "D77EA825"   (bayt bazlı ters çevirme, nibble değil)

pdks_uid_adaylari(string $raw): array
    // Bir girdiden üretilebilecek TÜM kanonik adaylar: [hex, ters(hex)]
    // ondalık girdide: [BE(hex), LE(hex)]
```

## C.4 Belirsizliği çözen mekanizma: `employee_card_uids` alias tablosu

**Tek kolonlu bir `uid_hex` yeterli değil.** Okuyucu modeli değişirse (yeni USB cihaz LE ondalık
yazarsa, yeni bir Android sürümü sırayı değiştirirse) tüm kartların yeniden tanımlanması gerekirdi.

Çözüm: kart kaydı **kanonik UID**'yi tutar; **arama** ise alias tablosu üzerinden yapılır.

```
Kart tanımlanırken:
    employee_cards         (uid_hex = "25A87ED7")               ← kanon, UNIQUE
    employee_card_uids     ("25A87ED7", kind='canonical')       ← UNIQUE
    employee_card_uids     ("D77EA825", kind='reversed')        ← UNIQUE

Okuma anında (Android VEYA USB, fark etmez):
    gelen ham değer → pdks_uid_adaylari() → employee_card_uids IN (adaylar) → card_id
```

Böylece `631799511`, `25A87ED7` ve `D7:7E:A8:25` **tasarım gereği** aynı karta çözülür —
bir `if` bloğuyla değil, veritabanı kısıtıyla.

**Çakışma riski ve davranışı:** Alias tablosundaki `UNIQUE(uid_hex)`, gerçek UID'si başka bir
kartın tersi olan bir kartın tanımlanmasını engeller (yaklaşık 4 milyarda 1). Bu durumda
tanımlama **sessizce başarısız olmaz**; ekran "Bu UID başka bir karta ait (kart #12) — yöneticiye
başvurun" der ve olay audit'e yazılır.

## C.5 Zorunlu otomatik test (§5 gereği)

`scripts/pdks_uid_smoke.php` — ağsız, DB'siz, saf fonksiyon testi. En az şunları kanıtlar:

| Test | Beklenen |
|---|---|
| `pdks_uid_from_decimal('631799511')` | `'25A87ED7'` |
| `pdks_uid_hex_normalize('D7:7E:A8:25')` | `'D77EA825'` |
| `pdks_uid_reverse('25A87ED7')` | `'D77EA825'` |
| USB `631799511` ile Android `25A87ED7` **aynı card_id'ye** çözülür | ✓ (SQLite fixture ile) |
| `D7:7E:A8:25` de **aynı card_id'ye** çözülür | ✓ |
| `pdks_uid_from_decimal('2467966')` | `'0025A87E'` (baştaki sıfır korunur) |
| `pdks_uid_hex_normalize('ZZZ')` / `'25A8'`+tek hane | `null` |
| 7 baytlık UID (`04A2B3C4D5E6F0`) | kabul, 14 hane |

---

# BÖLÜM D — VERİ MODELİ

**Mevcut hiçbir tabloya ALTER yok.** Aşağıdakilerin tamamı yeni tablodur.
Hepsi `CREATE TABLE IF NOT EXISTS`, `InnoDB`, `utf8mb4_unicode_ci`.

**FK politikası:** Depo genel olarak FK kullanmıyor (tüm depoda tek FK var: `account_files`).
Bu konvansiyona uyup FK'yı **yalnız gerçek cascade gerektiren tek yerde** kullanacağız.

## D.1 `employees` — Personel kartoteksi

| Alan | Tip | Null | Varsayılan | Index | Amaç |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | H | — | PK | |
| `personnel_no` | VARCHAR(30) | E | NULL | **UNIQUE** `uq_emp_pno` | Sicil no. NULL bırakılabilir; MySQL çoklu NULL'a izin verir, boş string verseydik ikinci boş kayıt UNIQUE'e takılırdı |
| `full_name` | VARCHAR(150) | H | — | `idx_emp_name(80)` | Ekranda gösterilen ad |
| `department` | VARCHAR(100) | H | `''` | `idx_emp_dept(80)` | `material_definitions type='departman'` öneri havuzu |
| `job_title` | VARCHAR(100) | H | `''` | — | Görev |
| `depo` | VARCHAR(150) | H | `''` | `idx_emp_depo(80)` | Depo damgası. `''` = tüm depolarda görünür (§A.4 kuralı) |
| `status` | VARCHAR(20) | H | `'aktif'` | `idx_emp_status` | `aktif` · `pasif` · `ayrildi` |
| `user_id` | INT | E | NULL | `idx_emp_user` | Opsiyonel `users.id` bağı (Hesap modülü eşleşmesi için) |
| `photo_file` | VARCHAR(64) | E | NULL | — | `uploads/personel/` içindeki 32-hex dosya adı |
| `photo_updated_at` | DATETIME | E | NULL | — | Android önbellek geçersizleştirme (ETag kaynağı) |
| `phone` | VARCHAR(30) | E | NULL | — | **API'ye ASLA gönderilmez** (§K) |
| `national_id_last4` | VARCHAR(4) | E | NULL | — | Tam TC **saklanmaz**; ad benzerliğinde ayırt etmek için son 4 hane yeterli |
| `hire_date` / `leave_date` | DATE | E | NULL | — | İşe giriş / ayrılış |
| `notes` | TEXT | E | NULL | — | |
| `created_by` / `updated_by` | INT | E | NULL | — | `users.id` |
| `created_at` | DATETIME | H | CURRENT_TIMESTAMP | — | |
| `updated_at` | DATETIME | E | NULL ON UPDATE CURRENT_TIMESTAMP | — | |

**Neden gerekli:** Sistemde personel kartoteksi yok (§A.10) ve `users` bunun yerine geçemez.

**Neden tam TC saklamıyoruz:** İhtiyacı olan tek şey kapıda kimlik teyidi; bunu fotoğraf sağlıyor.
Tam TC saklamak, sızıntı riski/KVKK yükü getirir, karşılığında hiçbir işlevsel kazanç vermez.
Bordro entegrasyonu için ileride gerekirse ayrı, erişimi kısıtlı bir alan olarak tartışılır.

## D.2 `employee_cards` — Fiziksel kart kaydı

| Alan | Tip | Null | Varsayılan | Index | Amaç |
|---|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | H | — | PK | |
| `employee_id` | INT | H | — | `idx_ec_emp` | Kartı taşıyan personel |
| `uid_hex` | VARCHAR(32) | H | — | **UNIQUE** `uq_ec_uid` | **Kanonik UID** (§C.3) — talebinizdeki UNIQUE şartı |
| `uid_bytes` | TINYINT | H | 4 | — | 4 / 7 / 10 — ondalık→hex dönüşümünde dolgu uzunluğu |
| `uid_decimal` | VARCHAR(25) | E | NULL | `idx_ec_dec` | Teşhis/arama kolaylığı. **Kimlik değil**, kanon `uid_hex`'tir. *(Faz 0: 24 → 25; 10 baytlık UID ondalığı 25 haneye çıkabiliyor)* |
| `card_type` | VARCHAR(30) | H | `'mifare_classic_1k'` | — | ATQA/SAK'tan çıkarılan tip |
| `atqa` / `sak` | VARCHAR(8) | E | NULL | — | Teşhis (`0004` / `08`) |
| `label` | VARCHAR(60) | H | `''` | — | Kart üstündeki yazı/numara |
| `status` | VARCHAR(20) | H | `'aktif'` | `idx_ec_status` | `aktif` · `iptal` · `kayip` · `degistirildi` · `suresi_doldu` · `pasif` |
| `issued_at` | DATE | E | NULL | — | Veriliş |
| `expires_at` | DATE | E | NULL | — | Son geçerlilik (NULL = süresiz) |
| `replacement_card_id` | INT | E | NULL | — | Kaybolan kartın yerine geçen kartın id'si — **geçmiş silinmez** |
| `revoked_at` / `revoked_by` | DATETIME / INT | E | NULL | — | İptal anı ve iptal eden |
| `revoke_reason` | VARCHAR(200) | H | `''` | — | |
| `enrolled_source` | VARCHAR(20) | H | `'usb'` | — | `usb` · `nfc_phone` · `manual` |
| `notes` | TEXT | E | NULL | — | |
| `created_by` / `created_at` / `updated_at` | — | — | — | — | |

**Neden gerekli:** UID ↔ personel bağı, kart yaşam döngüsü ve UNIQUE kısıtı (§11).

**Kart devri:** Aynı fiziksel kart başka personele verilirse `employee_id` güncellenir ve
`audit_log_event('card_transfer','pdks',$card_id,$eski,$yeni)` yazılır. Hareketler
`employee_id` + `card_uid_snapshot`'ı zaten kendi içinde taşıdığı için **geçmiş bozulmaz**;
ayrı bir atama geçmişi tablosuna V1'de gerek yoktur.

## D.3 `employee_card_uids` — UID takma adları (normalizasyon garantisi)

| Alan | Tip | Null | Index | Amaç |
|---|---|---|---|---|
| `id` | INT AI | H | PK | |
| `card_id` | INT | H | `idx_ecu_card` | → `employee_cards.id`, **FK ON DELETE CASCADE** |
| `uid_hex` | VARCHAR(32) | H | **UNIQUE** `uq_ecu_uid` | Aranabilir gösterim |
| `kind` | VARCHAR(20) | H | — | `canonical` · `reversed` · `legacy` |
| `created_at` | DATETIME | H | — | |

**Neden gerekli:** §5'in "üçü de aynı karta çözülmeli" şartını **veritabanı kısıtı** hâline getirir
(§C.4). Okuyucu donanımı değişirse yeni bir alias satırı eklemek yeterli olur, veri taşınmaz.

**Tek FK burada:** Kart silinirse alias'ların kalması anlamsızdır ve sessiz yanlış eşleşme üretir.

## D.4 `attendance_gates` — Kapı / lokasyon

| Alan | Tip | Null | Varsayılan | Index |
|---|---|---|---|---|
| `id` | INT AI | H | — | PK |
| `name` | VARCHAR(80) | H | — | **UNIQUE** `uq_gate_name` |
| `depo` | VARCHAR(150) | H | `''` | `idx_gate_depo(80)` |
| `is_active` | TINYINT(1) | H | 1 | — |
| `sort_order` | INT | H | 0 | — |
| `notes` / `created_by` / `created_at` | — | — | — | — |

**Neden gerekli:** §17 — bugün tek kapı var ama cihaz→kapı→depo zinciri (§B.4) API'nin depo
bağlamını buradan alır. Kapı olmadan cihazın deposu belirsiz kalır.

## D.5 `attendance_devices` — Yetkili Android telefonlar

| Alan | Tip | Null | Varsayılan | Index | Amaç |
|---|---|---|---|---|---|
| `id` | INT AI | H | — | PK | |
| `device_uuid` | VARCHAR(64) | E | NULL | **UNIQUE** `uq_dev_uuid` | İstemcinin ürettiği kimlik. **Tek başına hiçbir şey ifade etmez** — yalnız kayıt sırasında sunucu bağladıysa geçerlidir |
| `name` | VARCHAR(80) | H | — | — | "Ana Giriş – Güvenlik 1" |
| `gate_id` | INT | H | — | `idx_dev_gate` | Hangi kapıda |
| `status` | VARCHAR(20) | H | `'beklemede'` | `idx_dev_status` | `beklemede` · `aktif` · `iptal` |
| `enroll_code` | VARCHAR(12) | E | NULL | `idx_dev_code` | Tek kullanımlık kayıt kodu (admin üretir) |
| `enroll_code_expires_at` | DATETIME | E | NULL | — | **15 dakika** |
| `enroll_code_used_at` | DATETIME | E | NULL | — | Kullanıldı işareti |
| `token_hash` | CHAR(64) | E | NULL | `idx_dev_token` | Cihaz token'ının **SHA-256'sı**. Ham token yalnız telefonda durur |
| `token_issued_at` | DATETIME | E | NULL | — | Rotasyon takibi |
| `app_version` / `device_model` / `android_version` | VARCHAR(40) | E | NULL | — | Destek/teşhis |
| `last_seen_at` | DATETIME | E | NULL | — | Heartbeat |
| `last_ip` | VARCHAR(45) | E | NULL | — | |
| `registered_by` / `registered_at` | INT / DATETIME | E | NULL | — | |
| `revoked_at` / `revoked_by` / `revoke_reason` | — | E | — | — | Kayıp telefonu iptal |

**Neden gerekli:** §16 — "rastgele istemci kimliğine güvenme". Burada güvenilen şey `device_uuid`
değil, **sunucunun ürettiği token**tır; `device_uuid` yalnız teşhis/eşleştirme içindir.

## D.6 `attendance_api_sessions` — Güvenlik görevlisinin cihazdaki oturumu

| Alan | Tip | Null | Index | Amaç |
|---|---|---|---|---|
| `token_hash` | CHAR(64) | H | **PK** | Ham token yalnız telefonda. `user_sessions`'tan farkı budur |
| `user_id` | INT | H | `idx_as_user` | Güvenlik görevlisi |
| `device_id` | INT | H | `idx_as_dev` | **Oturum cihaza bağlıdır** — token başka cihazda kullanılamaz |
| `created_at` / `expires_at` / `last_seen_at` | DATETIME | H/H/E | `idx_as_exp` | 12 saat, vardiya uzunluğu |
| `revoked_at` | DATETIME | E | — | Elle çıkış / admin iptali |

**Neden ayrı tablo (neden `user_sessions` yeniden kullanılmıyor):** `user_sessions` token'ı
`asya_session` cookie'si olarak **tam web oturumu** verir. Telefondan sızan bir token, o kişinin
tarayıcıdan tüm panele girmesi anlamına gelirdi. Ayrı tablo bu ayrıcalık yükselmesini kapatır ve
token'ı hash'li tutarak DB sızıntısında oturum çalınmasını da engeller.

## D.7 `attendance_scans` — Her kart okutma (onaylansın veya onaylanmasın)

| Alan | Tip | Null | Index | Amaç |
|---|---|---|---|---|
| `id` | BIGINT AI | H | PK | |
| `device_id` / `gate_id` / `security_user_id` | INT | E | `idx_sc_dev`, `idx_sc_gate` | Kim, nerede, hangi cihazla |
| `raw_uid` | VARCHAR(64) | H | — | İstemcinin gönderdiği **ham** değer (teşhis için birebir) |
| `uid_hex` | VARCHAR(32) | H | `idx_sc_uid` | Kanonik hâli |
| `card_id` / `employee_id` | INT | E | `idx_sc_emp` | Çözülebildiyse |
| `result` | VARCHAR(30) | H | `idx_sc_result` | `ok` · `bilinmeyen_kart` · `kart_iptal` · `kart_suresi_doldu` · `personel_pasif` · `mukerrer` · `cihaz_yetkisiz` |
| `proposed_type` | VARCHAR(10) | E | — | `giris` / `cikis` önerisi |
| `ticket_hash` | CHAR(64) | E | **UNIQUE** `uq_sc_ticket` | Onay bileti (§E.3) |
| `ticket_expires_at` / `ticket_used_at` | DATETIME | E | — | 60 saniye, **tek kullanımlık** |
| `event_id` | BIGINT | E | `idx_sc_event` | Onaylandıysa oluşan hareket |
| `scanned_at` | DATETIME | H | `idx_sc_time` | **Sunucu saati** |
| `device_reported_at` | DATETIME | E | — | Telefon saati — **güvenilmez**, yalnız sapma teşhisi |
| `client_uuid` | CHAR(36) | E | `idx_sc_cuuid` | İstemci istek kimliği |

**Neden gerekli (neden hareketin içine gömmüyoruz):** Üç ayrı işi tek başına yapıyor:
① onay biletinin tek kullanımlık durağı (sunucunun personeli **kendisi** çözmesini sağlayan mekanizma, §27),
② mükerrer okuma tespitinin veri kaynağı (§15),
③ "güvenlik görevlisi kartı okuttu ama onaylamadı" olayının kaydı — güvenlik personeli aktivite
raporunun (§22) tek kaynağı budur. Hareket tablosuna karıştırılsaydı puantaj sorguları kirlenirdi.

**Saklama:** 180 gün sonra silinebilir (hareket tablosu kalıcıdır). Faz 5'te basit bir temizlik betiği.

## D.8 `attendance_events` — Giriş/çıkış hareketleri (modülün kalbi)

| Alan | Tip | Null | Varsayılan | Index | Amaç |
|---|---|---|---|---|---|
| `id` | BIGINT AI | H | — | PK | |
| `employee_id` | INT | H | — | **`idx_ae_emp_time (employee_id, event_time)`** | Giriş/çıkış motorunun ana sorgusu |
| `card_id` | INT | E | NULL | — | Elle girişte NULL |
| `card_uid_snapshot` | VARCHAR(32) | H | `''` | — | O anki UID'nin **kopyası** — kart sonradan silinse/devredilse bile hareket kendini açıklar |
| `event_type` | VARCHAR(10) | H | — | `idx_ae_type` | `giris` · `cikis` |
| `event_time` | DATETIME | H | — | `idx_ae_time` | **YETKİLİ ZAMAN — sunucu `NOW()`** |
| `device_reported_at` | DATETIME | E | NULL | — | Telefon saati (güvenilmez, yalnız kayıt) |
| `time_source` | VARCHAR(20) | H | `'server'` | — | `server` · `device_offline` — **V2 offline kuyruğu için şimdiden ayrıldı** |
| `server_received_at` | DATETIME | H | CURRENT_TIMESTAMP | — | |
| `source` | VARCHAR(20) | H | — | `idx_ae_source` | `nfc_phone` · `usb` · `manual` · `import` |
| `gate_id` / `device_id` / `security_user_id` | INT | E | NULL | `idx_ae_gate` | Nerede, hangi telefonla, kim onayladı |
| `depo` | VARCHAR(150) | H | `''` | `idx_ae_depo(80)` | Kapının deposundan damgalanır |
| `status` | VARCHAR(20) | H | `'gecerli'` | `idx_ae_status` | `gecerli` · `duzeltildi` · `iptal` |
| `supersedes_event_id` | BIGINT | E | NULL | `idx_ae_sup` | Bu satır hangi hareketin düzeltilmiş hâli |
| `correction_reason` | VARCHAR(300) | H | `''` | — | **Düzeltmede ZORUNLU** |
| `corrected_by` / `corrected_at` | INT / DATETIME | E | NULL | — | |
| `client_uuid` | CHAR(36) | E | NULL | **UNIQUE** `uq_ae_client` | **Idempotency anahtarı** (§28) |
| `scan_id` | BIGINT | E | NULL | `idx_ae_scan` | Hangi okutmadan doğdu |
| `note` | VARCHAR(300) | H | `''` | — | |
| `created_by` / `created_at` | INT / DATETIME | E/H | — | — | |

**Neden ayrı bir `attendance_corrections` tablosu YOK:**
Talebinizde ihtimal olarak geçiyordu; **önermiyorum.** Düzeltme, eski satırı `status='duzeltildi'`
yapıp `supersedes_event_id` ile ona bağlı **yeni bir satır** yazmak demektir. Böylece:
- Okuma sorguları tek yerden beslenir (`WHERE status='gecerli'`) — iki tabloyu birleştirmek gerekmez,
  dolayısıyla "rapor düzeltmeleri hesaba katmayı unuttu" hatası **yapısal olarak** imkânsızdır.
- Geçmiş silinmez, zincir geriye doğru okunabilir.
- Değişikliğin kim/ne zaman/eski değer/yeni değer/gerekçe kaydı zaten `audit_log`'a yazılır (§A.7) —
  yani talebinizdeki `changed_by/changed_at/old_value/new_value/reason` alanlarının **tamamı** karşılanır.
Ayrı bir tablo, aynı veriyi ikinci kez ve ayrışma riskiyle tutmak olurdu.

**Neden `client_uuid` UNIQUE ve NULL'a açık:** MySQL UNIQUE index'i çoklu NULL'a izin verir;
elle girilen hareketlerde istemci kimliği olmaz. NFC akışında ise ikinci kez gelen aynı UUID
`INSERT` aşamasında duplicate key alır → sunucu var olan hareketi döndürür, ikinci giriş yazılmaz (§I.3).

## D.9 İleride (Faz 4+) — şimdi AÇILMAYACAK tablolar

`attendance_shifts` · `attendance_shift_assignments` · `attendance_leaves` · `attendance_holidays`

**Neden şimdi değil:** V1'in tek işi doğru giriş/çıkış kaydetmek. Vardiya tanımı olmadan da
"içeride kim var", "eksik çıkış", "kim saat kaçta geldi" raporları çalışır. Vardiya şemasını
gerçek vardiya kurallarınızı görmeden tasarlarsak, sonradan bozucu migration gerekir.

## D.10 İlişki diyagramı

```
users ──(opsiyonel)── employees ──1:N── employee_cards ──1:N── employee_card_uids
                          │                    │
                          │                    └── replacement_card_id ──┐ (kendine)
                          │                                              ▼
                          └──────────1:N───────── attendance_events ◄── employee_cards
                                                        ▲   │
attendance_gates ──1:N── attendance_devices ────────────┘   │
        │                        │                          │
        │                        └── attendance_api_sessions│
        │                                                   │
        └──────────── attendance_scans ─────────────────────┘ (scan_id / event_id)

material_definitions (type='departman'|'gorev'|'depo')  →  employees.department / job_title / depo
audit_log  ←  her write (create/update/revoke/correct/enroll/device_revoke)
```

---

# BÖLÜM E — API TASARIMI

## E.1 Biçim

**Tek yönlendirici**, `halkayit/api.php` deseniyle aynı: `POST /api_pdks.php?action=...`, gövde JSON,
cevap her zaman JSON. Neden tek dosya: kimlik/yetki/depo kapısı **bir kez** yazılır; on ayrı
`api_*.php` dosyası, kapının dokuz farklı kopyasının ayrışması demekti.

Ortak cevap zarfı:

```json
{ "ok": true,  "data": { ... } }
{ "ok": false, "code": "bilinmeyen_kart", "error": "Kart tanımlı değil", "http": 404 }
```

`code` **makine tarafından okunur ve sabittir**; Android ekranındaki Türkçe metinler bu koda göre
seçilir. `error` insan içindir ve değişebilir. Bu ayrım olmadan Android sürümü, sunucu metnini
değiştirdiğimiz gün bozulurdu.

## E.2 Kimlik başlıkları

```
X-PDKS-Device: <cihaz token'ı>       (her istekte zorunlu)
Authorization: Bearer <oturum token>  (login ve device/register hariç zorunlu)
```

## E.3 Uç noktalar

| action | Yetki | Girdi | Çıktı / Davranış |
|---|---|---|---|
| `device/register` | *(kayıt kodu)* | `enroll_code`, `device_uuid`, `model`, `app_version` | Kodu doğrular, tek kullanımlık işaretler, **cihaz token'ı** üretir (32 bayt rastgele), hash'ini saklar. Ham token yalnız bu cevapta döner. Audit: `device_enroll` |
| `auth/login` | — (cihaz token'ı şart) | `username`, `password` | `login_user()` ile doğrular + `can('attendance.scan')` kontrolü. Oturum token'ı döner (12 saat). Audit: `api_login` / `api_login_failed` |
| `auth/logout` | oturum | — | Oturumu iptal eder |
| `card/scan` | `attendance.scan` | `raw_uid`, `client_uuid`, `device_time` | UID'yi kanona çevirir → kart → personel. Mükerrer kontrolü. **Onay bileti** üretir (60 sn, tek kullanımlık). Döndürür: ad, sicil, departman, fotoğraf URL'i + sürümü, son hareket, **önerilen işlem** |
| `event/confirm` | `attendance.scan` | `ticket`, `client_uuid`, `device_time` | **`employee_id` KABUL ETMEZ.** Personeli biletten çözer. Idempotent. Hareketi yazar |
| `device/heartbeat` | oturum | `app_version` | `last_seen_at` günceller; sunucu saati + config (cooldown) döner |
| `employee/photo` | oturum | `employee_id`, `v` | Küçültülmüş JPEG, `ETag`/`Cache-Control: private` ile |
| `app/version` | cihaz token'ı | — | Zorunlu minimum APK sürümü (eski sürümü kapıda uyarmak için) |

## E.4 Android ASLA `employee_id` söyleyemez (§27'nin uygulanışı)

```
scan  →  sunucu: UID → employee'yi ÇÖZER → ticket üretir (rastgele 32 bayt, hash'i DB'de)
             ↓ ekranda fotoğraf + ad
confirm →  istemci YALNIZ ticket gönderir
       →  sunucu: ticket_hash ile attendance_scans satırını bulur
       →  employee_id'yi O SATIRDAN okur, istemciden DEĞİL
       →  bilet süresi geçmiş/kullanılmışsa reddeder
```

İstemci `employee_id=27` diyemez; söylese bile sunucu o alanı hiç okumaz.

## E.5 Neden REST kaynak yolları (`/api/attendance/scan`) kullanmıyoruz

Bu uygulama paylaşımlı hostingde, `.htaccess`'inde **hiç rewrite kuralı olmadan** çalışıyor;
her URL fiziksel bir dosya. `/api/attendance/scan` için `mod_rewrite` kurulumu gerekir ve bu,
tüm sitenin URL davranışını etkileyen bir değişikliktir. Kazancı yalnız estetik olurdu.
`api_pdks.php?action=card/scan` aynı işi, sıfır altyapı riskiyle yapar.

## E.6 CSRF

CSRF, **cookie ile kimlik doğrulayan** akışların sorunudur. `api_pdks.php` cookie kullanmaz
(Bearer token), dolayısıyla CSRF yüzeyi yoktur ve CSRF token istemez.
**Web sayfaları** (`personel_form.php`, `pdks_hareketler.php` düzeltme formu…) mevcut kuralın aynısına tabidir:
her POST'ta `csrf_check($_POST['csrf'] ?? null)`.

---

# BÖLÜM F — GÜVENLİK MODELİ VE TEHDİT ANALİZİ

## F.1 Temel ilke

> **MIFARE Classic UID bir kimlik doğrulama bilgisi DEĞİLDİR.**
> UID, 20 TL'lik bir cihazla kopyalanabilir; UID'si yazılabilir "magic" kartlar serbestçe satılır.

Bu yüzden UID tek başına hiçbir şeyi yetkilendirmez. Bir hareketin yazılması için **beşi birden** gerekir:

1. Tanınan kart UID'si
2. Kimliği doğrulanmış güvenlik kullanıcısı (`attendance.scan`)
3. Yetkili, iptal edilmemiş cihaz (geçerli cihaz token'ı)
4. Geçerli, süresi dolmamış API oturumu (cihaza bağlı)
5. Sunucu tarafı doğrulama (bilet + mükerrer + idempotency)

**Altıncı ve en güçlü katman insandır:** güvenlik görevlisi ekrandaki fotoğrafla kartı getireni
karşılaştırır. Klonlanmış kart bu katmanı geçemez — modülün temel güvenlik varsayımı budur.

## F.2 Tehdit matrisi

| # | Tehdit | Önlem | Faz |
|---|---|---|---|
| 1 | **UID klonlama** | Fotoğrafla görsel teyit zorunlu akış; UID asla tek başına yetki vermez; aynı UID kısa sürede iki kapıda görünürse pano uyarısı | 2 / 3 |
| 2 | **Kart paylaşımı** (A'nın kartını B getirir) | Görsel teyit; ek olarak ardışık aynı yönde hareket (giriş-giriş) uyarısı | 2 |
| 3 | **Replay (isteği tekrar gönderme)** | Bilet tek kullanımlık + 60 sn TTL; `client_uuid` UNIQUE; HTTPS | 2 |
| 4 | **Sahte API isteği** | Cihaz token'ı + oturum token'ı; ikisi de sunucu üretimi, hash'li saklanır | 2 |
| 5 | **Yetkisiz Android telefon** | Kayıt yalnız admin'in ürettiği tek kullanımlık kod + 15 dk pencere ile; kod olmadan token verilmez | 2 |
| 6 | **Çalınan güvenlik hesabı** | Rol çıplak (`attendance.scan` dışında hiçbir yetki yok); hesap web panelinde işe yaramaz; oturum cihaza bağlı; 12 saat TTL | 2 |
| 7 | **Cihaz taklidi** (`device_uuid` uydurma) | `device_uuid`'ye güvenilmez; yetkiyi token verir; kayıp telefon admin tarafından iptal edilir (`status='iptal'`, `token_hash=NULL` → ilk istekte 401) | 2 |
| 8 | **Mükerrer okuma** | Cooldown + `client_uuid` + istemci debounce (üç katman, §I.3) | 2 |
| 9 | **SQL injection** | Mevcut kural: **istisnasız** prepared statement, `EMULATE_PREPARES=false`. Yeni kodda ham SQL birleştirme yasak | 1 |
| 10 | **CSRF** | Web POST'larında `csrf_check()`; API cookie kullanmadığı için yüzey yok | 1 |
| 11 | **XSS** | Tüm çıktılar `h()` ile kaçırılır; personel adı/not alanları dahil | 1 |
| 12 | **Brute force** (§A.2'deki mevcut açık) | `api_pdks.php` `auth/login`'e kullanıcı+IP bazlı gecikmeli kilit. **Ayrıca web `login.php` için de aynı korumayı Faz 2'de öneriyorum** — mevcut açık, PDKS ile büyür | 2 |
| 13 | **Oturum çalınması** | Token DB'de hash'li; TLS zorunlu; cihaza bağlı oturum | 2 |
| 14 | **Saat manipülasyonu** | `event_time` **daima** sunucu `NOW()`; telefon saati yalnız `device_reported_at`'e yazılır; >5 dk sapma panoda uyarı | 2 |
| 15 | **Puantaj manipülasyonu** | Hareket satırları değiştirilmez; düzeltme yeni satır + gerekçe; `attendance.correct` ayrı yetki | 2 |
| 16 | **Yetkisiz elle düzeltme** | `attendance.correct` yalnız `ik` + `admin`; güvenlik rolünde YOK | 2 |
| 17 | **Audit kurcalama** | `audit_log`'a UI'dan yazma/silme yolu yok; DB düzeyinde koruma yok (mevcut durum) — **kabul edilen artık risk**, günlük DB yedeği (`admin_db_backups.php`) telafi eder | — |
| 18 | **Fotoğraf sızıntısı** | `uploads/personel/.htaccess` PHP kapalı + dizin listeleme kapalı; rastgele 32-hex ad; erişim yalnız yetki kontrollü endpoint'ten | 1 |
| 19 | **HTTPS zorlaması** | **Kök `.htaccess`'te HTTPS yönlendirmesi/HSTS YOK** (ölçüldü). Android istemci `cleartextTrafficPermitted=false` ile kendini korur, ama Faz 0'da hosting tarafının HTTPS zorlaması doğrulanmalı | 0 |
| 20 | **Deploy webhook'u imzasız** | Mevcut, bilinen risk (`docs/DEPLOY_WORKFLOW.md`). PDKS bunu büyütmez ama kapıya bakan bir sistem eklediğimiz için **Faz 0'da kapatılmasını öneriyorum** — hazır çözüm `scripts/deploy_webhook.php` | 0 |

## F.3 Token üretimi ve saklama kuralı

```php
$ham  = bin2hex(random_bytes(32));        // 64 hex — yalnız cevapta, yalnız bir kez
$hash = hash('sha256', $ham);             // DB'ye yalnız bu yazılır
// doğrulama: hash_equals($db_hash, hash('sha256', $gelen))
```

Mevcut `user_sessions` tablosu token'ı açık tutuyor (§A.2). **Onu değiştirmiyoruz** — çalışan
bir oturum sistemini bu iş için riske atmak doğru olmaz. Yeni tablolarda doğrusunu yapıyoruz ve
`docs/SECURITY_NOTES.md`'ye "bilinen fark" olarak yazıyoruz.

---

# BÖLÜM G — ANDROID İSTEMCİ

## G.1 Karar: Minimal native Kotlin (seçenek B)

| Seçenek | Değerlendirme | Karar |
|---|---|---|
| **A — Web NFC / PWA** | Web NFC yalnız **NDEF** okur; ham UID'yi bir NDEF etiketinin yan ürünü olarak verir. Kartlarınız MIFARE Classic/NfcA ve NDEF formatlı değil. Ayrıca yalnız Chrome/Android'de, sayfa ön plandayken ve HTTPS'te çalışır; ekran kilidi/uyku davranışı kapı terminali için uygun değil | ❌ **Elenir** |
| **B — Minimal native** | `NfcAdapter.enableReaderMode()` ham `Tag.getId()` verir. UID sırası bizim kontrolümüzde. Ekran açık tutma, ses/titreşim, ekran sabitleme (screen pinning) mümkün | ✅ **SEÇİLDİ** |
| **C — WebView + native köprü** | NFC native'de, arayüz web'de. Arayüz güncellemesi APK gerektirmez — cazip. Ama WebView + JS köprüsü + offline davranışı, B'nin sadeliğini ortadan kaldırır ve hata yüzeyini büyütür | ⚠ Faz 5 alternatifi |

**Sizin ilk tercihiniz de B idi ve inceleme sonrası bu tercih doğru çıkıyor.**

## G.2 Teknik notlar (uygulama fazında kritik)

```kotlin
// Doğru yöntem: enableReaderMode — foreground dispatch DEĞİL
nfcAdapter.enableReaderMode(
    activity,
    { tag -> onTag(tag.id) },                  // tag.id = ham UID baytları
    NfcAdapter.FLAG_READER_NFC_A or
    NfcAdapter.FLAG_READER_SKIP_NDEF_CHECK,    // NDEF ayrıştırmayı atla → daha hızlı, sistem sesi yok
    null
)
```

- `FLAG_READER_SKIP_NDEF_CHECK` **önemli**: kartlar NDEF değil; kontrolü atlamak hem hızlandırır
  hem de sistemin araya girmesini engeller.
- UID→hex dönüşümü istemcide **yalnız biçimlendirmedir**; kanonik karar sunucudadır.
- Bağımlılık: OkHttp + `org.json`. Retrofit/Moshi/Room **yok** — uygulama küçük kalmalı.
- `network_security_config.xml` → `cleartextTrafficPermitted="false"`.
- Ekran: `FLAG_KEEP_SCREEN_ON`, tek Activity, iki durum (Bekliyor / Onay).
- Dağıtım: Play Store yok; imzalı APK elden kurulur. `app/version` ucu eski sürümü uyarır.

## G.3 Ekran akışı

```
┌──────────────────────────┐        ┌──────────────────────────┐
│   NFC PERSONEL KONTROL   │        │   [ FOTOĞRAF — büyük ]   │
│                          │        │                          │
│      Kartı okutun        │        │   AD SOYAD               │
│         [NFC]            │  kart  │   Personel No: 027       │
│                          │  ───►  │   Departman: Depo        │
│  Ana Giriş               │        │   Son: 13.09 18:14 ÇIKIŞ │
│  Bağlantı: ● Online      │        │   Şimdiki işlem: GİRİŞ   │
│  Güvenlik: <ad>          │        │   [  GİRİŞİ ONAYLA  ]    │
└──────────────────────────┘        └──────────────────────────┘
            ▲                                    │ onay
            │      "GİRİŞ KAYDEDİLDİ" (1.5 sn)   │
            └────────────────────────────────────┘
```

**Fotoğraf ekranın en az yarısını kaplar** — teyidin yapılacağı yer orasıdır.
Bilet 60 saniyede düşer; ekran beklemede kalırsa otomatik olarak "Kartı okutun"a döner.

## G.4 Hata ekranları (§10) — hiçbiri sessiz değil

| `code` | Ekran | Renk | Davranış |
|---|---|---|---|
| `bilinmeyen_kart` | **KART TANIMLI DEĞİL** | kırmızı | Onay butonu yok; 3 sn sonra bekleme |
| `kart_iptal` | **KART İPTAL EDİLMİŞ** | kırmızı | + iptal tarihi |
| `kart_suresi_doldu` | **KARTIN SÜRESİ DOLMUŞ** | turuncu | |
| `personel_pasif` | **PERSONEL PASİF** | kırmızı | |
| `mukerrer` | **BU KART AZ ÖNCE OKUTULDU** | sarı | Kalan saniye geri sayımı gösterilir |
| *(ağ yok)* | **BAĞLANTI YOK** | gri, tüm ekran | **Okuma kabul EDİLMEZ**; "kaydedildi" asla yazılmaz |
| `sunucu_hatasi` | **İŞLEM KAYDEDİLEMEDİ** | kırmızı | "Tekrar Dene" butonu — **aynı `client_uuid` ile** |
| `cihaz_yetkisiz` | **BU CİHAZ YETKİLENDİRİLMEMİŞ** | kırmızı, kalıcı | Uygulama kayıt ekranına döner |
| `oturum_gecersiz` | **OTURUM SONA ERDİ** | gri | Giriş ekranına döner |

**Online-only'nin dürüstlük kuralı:** Sunucu 2xx dönmeden ekranda hiçbir koşulda "KAYDEDİLDİ"
yazmaz. Zaman aşımında "SONUÇ BİLİNMİYOR — TEKRAR DENEYİN" gösterilir ve tekrar denemede aynı
`client_uuid` gönderilir; sunucudaki idempotency bu durumu tek harekete indirir.

---

# BÖLÜM H — WEB ARAYÜZÜ

## H.1 Navigasyon

Sidebar'da **Operasyon ile Yönetim arasına yeni bir grup**:

```
Operasyon
  Ana Sayfa / Yüklemeler / Çıkmalar / Beyanlar / Kantar / Hal Bildirimi
  Raporlar / Malzeme Stok / Hesap / Maliyet

Personel            ← YENİ GRUP
  👤 Personeller       personel.php            attendance.employees | attendance.read
  💳 Kart Yönetimi     personel_kartlar.php    attendance.cards
  🚪 Giriş / Çıkış     pdks.php                attendance.read
  📈 PDKS Raporları    pdks_rapor.php          attendance.report

Yönetim
  Tanımlar / Kullanıcılar / İşlem Geçmişi / Veritabanı Yedekleri
  📱 PDKS Cihazları    pdks_cihazlar.php       attendance.admin     ← YENİ
```

- **Bottomnav'a eklenmiyor.** Beş sekme dolu ve mobil düzen oradan bozulur. Mobil erişim
  ana sayfa kartından olur.
- `index.php`'ye tek kart: **👤 Personel** → `pdks.php`, rozetinde "içeride: N".
- `render_desktop_sidebar()` içinde `$p_pdks` ve `$a_pdks` değişkenleri eklenecek (§A.11).

## H.2 Ekranlar

**`personel.php`** — liste: fotoğraf küçük resim, ad, sicil, departman, durum, kart sayısı.
Arama + departman/durum filtresi. Masaüstünde `.pc-only` tablo, mobilde `.mobile-only` kart
(mevcut desen). Depo filtresi `depo_sql_column('depo')`.

**`personel_form.php`** — ekle/düzenle. Fotoğraf yükleme (telefon kamerası ile de),
`hesap_upload_file()` deseni: `finfo` MIME doğrulaması, rastgele ad, GD ile en uzun kenar 900 px'e
küçültme. Departman/görev alanları `material_definitions` öneri listesinden (mevcut
`data-aramali` yazarak-arama bileşeni 10'dan uzun listelerde).

**`personel_kartlar.php`** — kart yönetimi + **USB ile tanımlama** (§12):

```
┌─────────────────────────────────────────┐
│ Personel:  [ Ad Soyad ▾ ]               │
│                                         │
│ ┌─── KARTI USB OKUYUCUYA OKUTUN ─────┐  │
│ │ [ odaklı giriş kutusu            ] │  │  ← autofocus, klavye girişi bekler
│ └────────────────────────────────────┘  │
│ Okunan:    631799511                    │
│ Kanonik:   25A87ED7          ✓ boşta    │
│ [ PERSONELE TANIMLA ]                   │
└─────────────────────────────────────────┘
```

USB okuyucu klavye gibi yazar ve genelde sonunda Enter gönderir. `assets/pdks.js`:
- giriş kutusu her zaman odaklı tutulur,
- sadece rakam/hex + Enter kabul edilir, Enter formu **göndermez** — önce AJAX ile sorgular,
- kanonik karşılık **anında** gösterilir (dönüşüm PHP'de, JS yalnız gösterir → tek otorite),
- UID zaten kayıtlıysa "Bu kart <ad>'a tanımlı" uyarısı çıkar, sessizce üzerine yazılmaz.
- Personel elle ondalık→hex çevirmez; hiçbir ekranda böyle bir alan yok.

Aynı ekranda kart iptali, kayıp bildirimi, "yerine yeni kart" (eskisi `degistirildi` olur,
`replacement_card_id` bağlanır) işlemleri.

**`pdks.php`** — canlı pano (§19):

```
BUGÜN   [ İçeride: 27 ]  [ Giriş: 31 ]  [ Çıkış: 4 ]  [ Geç Gelen: 3 ]  [ Eksik Çıkış: 1 ]

Foto | Personel | Departman | Giriş Saati | Kapı | Güvenlik | Durum
 ▣   | ...      | Depo      | 07:58       | Ana  | ...      | İÇERİDE
```

Filtreler: tarih · personel · departman · kapı · durum. 30 saniyede bir hafif yenileme
(sayfa yenileme değil, küçük bir JSON ucu). "Geç Gelen" Faz 3'e kadar sabit bir eşikle
(varsayılan 08:15) hesaplanır; vardiya geldiğinde vardiyadan gelir.

**`pdks_hareketler.php`** — hareket listesi + **düzeltme**. Düzeltme formu gerekçe olmadan
gönderilmez (sunucu da reddeder). Düzeltilen satır listede üstü çizili ve "→ düzeltildi" bağlantılı görünür.

**`pdks_cihazlar.php`** — cihaz ekle (kayıt kodu üretir, kodu ekranda ve QR olarak gösterir),
cihaz iptal et, son görülme zamanı. Kapı tanımları `pdks_kapilar.php`.

## H.3 Görsel dil

- Yeni bileşen sınıfları `.pdks-*` öneki ile `assets/pdks.css` içinde.
- Renkler **yalnız** mevcut token'lardan: `var(--card)`, `var(--text)`, `var(--border)`,
  `var(--primary)`, `var(--success)`, `var(--danger)`, `var(--warn)`.
  Sabit `#ffffff`/`#000000` yazılmaz → koyu tema kendiliğinden doğru çalışır.
- Mobil: input `font-size:16px` (iOS zoom), tablolar `.table-wrap` içinde,
  konteyner `padding-bottom: calc(80px + env(safe-area-inset-bottom))`.
- Yeni modal eklenirse `z-index ≥ 600`.
- Yazdırmada gizlenecekler `@media print { display:none }`.

---

# BÖLÜM I — İŞ MANTIĞI

## I.1 Giriş/Çıkış motoru (§14)

```sql
SELECT event_type, event_time
  FROM attendance_events
 WHERE employee_id = ? AND status = 'gecerli'
 ORDER BY event_time DESC, id DESC
 LIMIT 1
```

| Son geçerli hareket | Önerilen |
|---|---|
| `cikis` | `giris` |
| `giris` | `cikis` |
| yok | `giris` |

**Takvim gününe bakılmaz** — talebinizdeki 23:00 giriş / 07:00 çıkış senaryosu bu yüzden
kendiliğinden doğru çalışır. `ORDER BY event_time DESC, id DESC`'deki ikinci anahtar,
aynı saniyeye düşen iki hareketin sırasını belirsiz bırakmamak içindir.

**Kapıdan bağımsızdır:** Ana Giriş'ten girip Depo'dan çıkmak geçerlidir (§17'deki çok kapılı
geleceğe hazır). Kapı bazlı kısıtlama isterseniz sonradan eklenebilir; varsayılan olarak yoktur
çünkü tek kapıda hiçbir fark yaratmaz, çok kapıda ise yanlış reddetme üretirdi.

**Güvenlik görevlisi önerinin tersini seçebilir mi?** Varsayılan: **hayır**. Yalnız
`attendance.manual` yetkisi olan bir kullanıcı (Faz 2'de web'den, Faz 3'te istenirse cihazdan)
ters yönde hareket yazabilir ve bu ayrıca işaretlenir. Gerekçe: en sık gerçek hata "iki kez giriş"tir;
serbest seçim, o hatayı yakalanamaz hâle getirir.

## I.2 Mükerrer okuma koruması (§15)

Üç bağımsız katman:

| Katman | Nerede | Ne yapar |
|---|---|---|
| 1 — istemci debounce | Android | Aynı UID 3 sn içinde tekrar okunursa istek bile gitmez |
| 2 — sunucu cooldown | `card/scan` | Aynı `card_id` + aynı `device_id` + `PDKS_COOLDOWN_SN` (varsayılan **20 sn**) içinde → `mukerrer`, bilet üretilmez |
| 3 — idempotency | `event/confirm` | `client_uuid` UNIQUE (§I.3) |

Ek olarak `event/confirm`, aynı personel için `PDKS_COOLDOWN_SN` içinde **aynı yönde** bir hareket
varsa yeni hareket yazmaz ve var olanı döndürür — çift dokunuş ve ağ tekrarının ikisini de kapatır.

Cooldown `config/pdks.php` sabitidir (tablo değil): tek satır, `PDKS_*` konvansiyonuna uygun
(`HESAP_MAX_FILE_SIZE` emsali), ayarlar tablosu açma maliyeti yok.

## I.3 Idempotency (§28)

```
Android: confirm(ticket, client_uuid=UUIDv4)
   → sunucu INSERT attendance_events (... client_uuid) 
   → cevap kayboldu
Android: aynı client_uuid ile TEKRAR confirm
   → UNIQUE uq_ae_client duplicate key
   → sunucu var olan satırı bulur
   → 200 { ok:true, data:{ event_id, tekrar:true } }
   → İKİNCİ GİRİŞ YAZILMAZ
```

Yarış durumu (aynı anda iki istek) da kapalıdır: benzersizliği uygulama kodu değil, **veritabanı
kısıtı** garanti eder. Kontrol-sonra-yaz yaklaşımı bu yarışa açıktı.

Bilet ayrıca tek kullanımlıktır (`ticket_used_at`); farklı `client_uuid` ile gelen ikinci bir
confirm de bu yüzden reddedilir.

---

# BÖLÜM J — ZAMAN VE SAAT DİLİMİ ⚠

## J.1 Mevcut durum (ölçüldü)

- `config/db.php:9` → `date_default_timezone_set('Europe/Istanbul')` — **PHP tarafı net.**
- MySQL bağlantısında **`SET time_zone` çağrısı YOK** → MySQL `NOW()`, sunucunun kendi saat
  dilimini kullanır; bu Istanbul olmak zorunda değildir.
- Kod, zaman yazarken bilinçli olarak **MySQL `NOW()`** kullanıyor
  (`config/auth.php`: *"MySQL NOW() kullan — PHP timezone uyumsuzluğunu önler"*).
  Yani projenin **seçtiği tek saat otoritesi MySQL'dir.**

## J.2 Risk

MySQL sunucusu UTC ise, `event_time` UTC yazılır ama ekranda `date('d.m.Y H:i')` ile
gösterilirken PHP Istanbul varsayar → **3 saatlik kayma**. Yükleme kayıtlarında bu fark edilmez;
puantajda doğrudan maaş anlaşmazlığıdır.

## J.3 Karar

1. **Faz 0'da ölç** (§Ek-1, Ölçüm 1). Tek komut, saniyeler sürer.
2. **Sapma yoksa:** Modül `NOW()` kullanır — uygulamanın geri kalanıyla tam tutarlı.
3. **Sapma varsa:** Bağlantıda `SET time_zone` **KENDİLİĞİNDEN AÇILMAZ** — o değişiklik `NOW()`
   kullanan her modülü (kantar, yükleme, hesap, audit) etkiler ve ayrı bir gözden geçirme işidir.
   PDKS'e özel çözüm: `event_time` PHP tarafında `Europe/Istanbul` ile üretilir ve
   `config/pdks.php`'de bunun **neden** istisna olduğu yazılır.
4. **Lehimize olan gerçek:** Türkiye 2016'dan beri kalıcı UTC+3, yaz saati uygulaması yok.
   Yani gece yarısı vardiyalarında DST kaynaklı "kaybolan/tekrar eden saat" sorunu **yoktur**.
   Bu, gece vardiyası hesabını ciddi biçimde basitleştirir.
5. Telefon saati **hiçbir koşulda** yetkili değildir; yalnız `device_reported_at`'a yazılır ve
   5 dakikadan fazla saparsa panoda cihaz uyarısı çıkar (bozuk saatli cihazı fark etmek için).

---

# BÖLÜM K — GİZLİLİK (§26)

**Kapı telefonuna giden veri kümesi (tamamı):**

```
ad soyad · sicil no · departman · fotoğraf · son hareket (zaman + tip) · önerilen işlem
```

**Gitmeyenler:** telefon, adres, TC, işe giriş tarihi, maaş/hesap verisi, notlar, diğer
personelin hiçbir bilgisi, hareket geçmişi (yalnız **son** hareket gider).

Ek kurallar:
- `card/scan` **liste döndürmez** — yalnız okutulan kartın sahibini döndürür. Telefonda personel
  rehberi oluşmaz; telefon çalınsa bile toplu veri sızmaz.
- Fotoğraflar `private, max-age` ile sunulur, `ETag` = `photo_updated_at`.
- Fotoğraf ve personel verisine erişim her zaman yetki kontrollüdür (`hesap_dosya.php` deseni:
  dosyanın varlığı yetmez, kaydın **o kullanıcıya görünür olması** da aranır).
- Personel fotoğrafları ve giriş/çıkış kayıtları KVKK kapsamında kişisel veridir; işleme amacı,
  saklama süresi ve personel aydınlatma metni **sizin idari sorumluluğunuzdadır** — modül,
  silme/anonimleştirme için `employees.status='ayrildi'` + fotoğraf silme yolunu sağlar.

---

# BÖLÜM L — TEST STRATEJİSİ

Mevcut dört katmanın aynısı (§A.6), hepsi CLI-only ve canlı DB'ye dokunmadan:

| Betik | Katman | Kapsam |
|---|---|---|
| `scripts/pdks_uid_smoke.php` | saf birim | **§C.5'teki tablo** — UID normalizasyonunun tamamı. Ağsız, DB'siz |
| `scripts/pdks_smoke.php` | mantık (SQLite) | Giriş/çıkış motoru (gece yarısı geçişi dahil), cooldown, idempotency (aynı `client_uuid` iki kez → tek satır), bilet tek kullanımlık, yetki kapıları, düzeltme zinciri, depo filtresi |
| `scripts/pdks_api_smoke.php` | API (SQLite) | `api_pdks.php` uçlarını gerçek istek gövdeleriyle çağırır: yetkisiz cihaz 401, süresi geçmiş bilet reddi, **istemcinin gönderdiği `employee_id`'nin yok sayıldığının kanıtı**, hata kodlarının sabitliği |
| `scripts/pdks_ui_smoke.php` | render | Sayfaları gerçekten render eder: PHP uyarısı sızıntısı, HTML etiket dengesi, `h()` kaçırma, koyu temada sabit renk kullanılmamış olması, mobil 16px kuralı |
| `scripts/pdks_js_smoke.js` | tarayıcı | Playwright: USB giriş kutusunun odak davranışı, Enter'ın formu göndermemesi, kanonik gösterimin anında güncellenmesi. **Playwright yoksa kendini ATLAR** (mevcut konvansiyon) |

**Kabul kriteri:** Faz sonlarında beşi de sıfır hata ile geçmeden canlıya çıkılmaz.
Android tarafında ayrıca UID okuma için bir manuel test protokolü (§Ek-1, Ölçüm 4).

---

# BÖLÜM M — FAZLAR

## Faz 0 — Ölçüm ve doğrulama (kod yok)

**Çıktı:** `docs/PDKS_FAZ0_OLCUMLER.md` — altı ölçümün sonucu.
**Süre:** yarım gün. **Risk:** yok (salt okunur).

1. MySQL saat dilimi (§J) 2. DDL yetkisi 3. HTTPS/HSTS 4. Android `getId()` bayt sırası
5. USB okuyucu ham davranışı (önek/sonek/sıfır dolgu) 6. Yedek geri yükleme provası

Detaylar §Ek-1'de. **Bu ölçümler tamamlanmadan şema dondurulmaz.**

## Faz 1 — Personel kartoteksi + kart yönetimi (yalnız web)

**Kapsam:** `employees`, `employee_cards`, `employee_card_uids` tabloları · `config/pdks.php`
(UID fonksiyonları + şema + yetki kapısı) · `personel.php` / `personel_form.php` /
`personel_foto.php` / `personel_kartlar.php` · USB ile kart tanımlama · yetkiler + `ik` rolü ·
`departman`/`gorev` tanım türleri · sidebar + ana sayfa kartı · `pdks_uid_smoke` + `pdks_ui_smoke`.

**Faz 1 tek başına işe yarar:** NFC hiç gelmese bile elinizde fotoğraflı personel kartoteksi ve
kart zimmet kaydı olur. **Hareket kaydı yoktur** — yani bu faz hiçbir puantaj riski taşımaz.

**Çıkış kriteri:** Tüm personel girilmiş, USB ile tüm kartlar tanımlanmış, UID testleri geçiyor.

## Faz 2 — Hareket motoru + API + Android v1 (online-only)

**Kapsam:** `attendance_gates` / `devices` / `api_sessions` / `scans` / `events` tabloları ·
`api_pdks.php` (yedi uç) · cihaz kayıt akışı + `pdks_cihazlar.php` · `guvenlik` rolü ·
giriş/çıkış motoru · cooldown + idempotency · `pdks.php` canlı pano · `pdks_hareketler.php`
(elle giriş + düzeltme) · **Android APK v1** · `login.php` brute-force koruması ·
`pdks_smoke` + `pdks_api_smoke`.

**Çıkış kriteri:** Tek kapıda, iki telefonla, en az bir tam hafta gölge çalışma
(mevcut yönteminiz paralel sürer, karşılaştırılır).

## Faz 3 — Raporlama ve temel puantaj

Günlük/aylık devam, geç gelen, erken çıkan, eksik giriş/çıkış, içeride olanlar, kart geçmişi,
güvenlik görevlisi aktivitesi. Excel (PhpSpreadsheet) + PDF (dompdf — `config/hesap_pdf.php`
kurallarına birebir uyarak: `text-transform:uppercase` yasak, DejaVu Sans, `page_text` ile
sayfa no). Basit çalışma süresi hesabı (eşleşen giriş-çıkış çiftleri, gece yarısı geçişi dahil).

## Faz 4 — Vardiya + gelişmiş puantaj

`attendance_shifts` + atamalar + izin/tatil. Fazla mesai, devamsızlık, gece vardiyası.
**Ancak gerçek vardiya kurallarınız yazıya döküldükten sonra tasarlanır.**

## Faz 5 — Offline kuyruk (ihtiyaç kanıtlanırsa)

**V1 online-only önerim nettir.** Offline kuyruk; yerel şifreli depolama, senkron protokolü,
çakışma çözümü, telefon saatine kısmi güven ve "hangi zaman doğru" tartışması demektir —
karmaşıklığın büyük kısmı burada. Kapıda kablolu/Wi-Fi bağlantı varsa bu maliyet gereksizdir.

Şimdiden hazırlık yapıldı: `attendance_events.device_reported_at` ve `time_source` kolonları
Faz 2'de açılır, böylece offline'a geçiş **migration gerektirmez**.

Faz 5'e ancak Faz 2 sonrası ölçülen gerçek kesinti sıklığı gerektiriyorsa geçilir.

---

# BÖLÜM N — ÜRETİM GÜVENLİĞİ

## N.1 Bu modülün en güçlü güvenlik özelliği

> **Mevcut hiçbir tabloya tek bir `ALTER` yok.**

Dokunulanlar yalnız:
- yeni tablolar (`CREATE TABLE IF NOT EXISTS`),
- `roles` + `role_permissions`'a `INSERT IGNORE` (additive),
- kod dosyaları.

**Sonuç:** Rollback = `git revert` + deploy. Yeni tablolar veritabanında boş/kullanılmaz kalır,
mevcut modüllerin hiçbiri etkilenmez. Puantaj verisi bile korunur (silinmez, yalnız erişilmez olur).

## N.2 Migration güvenliği

- `pdks_migrate()` **yalnız PDKS sayfalarından** çağrılır, `static $done` ile tek sefer.
  `config/db.php`'ye **eklenmez** — oradaki bir hata tüm uygulamayı etkiler.
- Tüm DDL `try/catch` + `error_log` — başarısız migration sayfayı çökertmez.
- ALTER/CREATE yetkisi yoksa: `migrate.php`'ye girdiler eklenir (tam hata mesajı + elle
  çalıştırılacak SQL gösterir). Faz 0 Ölçüm 2 bunu önden söyler.

## N.3 Yedek ve rollback planı

- Günlük otomatik yedek zaten var (`config/db_backup_helpers.php`, admin girişinde tetiklenir).
- **Her fazın deploy'undan önce elle yedek** (`admin_db_backups.php` → İndir) ve dosyanın
  yerel diske indirildiğinin doğrulanması.
- **Faz 0'da bir kez geri yükleme provası** — hiç denenmemiş bir yedek, yedek değildir.
- Rollback sırası: ① feature flag `PDKS_AKTIF=false` (anında, deploy gerektirir ama şema
  dokunmaz) → ② `git revert` + merge → ③ gerekiyorsa DB geri yükleme (yalnız veri bozulmasında).

## N.4 Branch ve deploy

- Geliştirme `claude/nfc-attendance-roadmap-z14alg` üzerinde (veya her faz için ayrı branch).
- "Canlıya al" = PR + `main` merge → webhook → ~4 dakika (`docs/DEPLOY_WORKFLOW.md`).
- Her fazda `APP_SURUM` + `sw.js` `CACHE_NAME` **aynı sayıya** çekilir.
- Doğrulama: hard refresh → sidebar altındaki sürüm.

## N.5 Canlı veriyle deney yasağı

Tüm testler bellek içi SQLite ile. Canlı DB'de `INSERT`/`UPDATE` denemesi yapılmaz.
Faz 2 gölge çalışmasında mevcut yönteminiz **paralel sürer**; PDKS verisi en az bir hafta
resmî kayıt sayılmaz.

---

# BÖLÜM O — AÇIK KAYNAK DEĞERLENDİRMESİ (§32)

Mevcut açık kaynak PHP devam takip projelerine hızlı bir tarama yaptım. Bulgular:

| Bulgu | Sonuç |
|---|---|
| Alanın büyük kısmı **öğrenci/bitirme projesi** (NodeMCU/ESP32 + RC522 donanımı, okul yoklaması) | Bizim senaryomuz (güvenlik görevlisinin telefonu, kimlik teyidi, bordro) farklı |
| Daha olgun olanlar (ör. HR paketleri) **Laravel/CodeIgniter** tabanlı | CLAUDE.md'nin "çerçeve yok" kuralına doğrudan aykırı; içeri almak uygulamayı ikiye böler |
| Saf PHP olanlar genelde **bakımsız** ve güvenlik desenleri zayıf (çoğunda hazırlıklı sorgu, CSRF, yetki katmanı eksik) | Mevcut uygulamanızın güvenlik seviyesinin **altında** kalırlar |
| Android NFC tarafında üçüncü parti kütüphaneye ihtiyaç yok | Doğru referans, platformun kendi `NfcAdapter.enableReaderMode` API'si |

**Önerim: hiçbir projeden kod alınmasın.** Alacağımız şey fikir düzeyinde ve zaten bu belgeye
işlendi: iki aşamalı okut→onayla akışı, kart yaşam döngüsü durumları, "içeride kim var" panosu.
Kod kopyalamak; lisans, bakım ve güvenlik borcunu içeri almak olurdu — üstelik toplam iş
büyüklüğü (tahmini 3.000–4.000 satır PHP) bunu haklı çıkarmıyor.

Belirli bir projeyi ayrıntılı incelememi isterseniz adını söyleyin, lisans/bakım/güvenlik
açısından tek tek değerlendiririm.

Kaynaklar: [GitHub · attendance-management-system (PHP)](https://github.com/topics/attendance-management-system?l=php) ·
[code-boxx/I-Was-Here-PHP-Attendance-System](https://github.com/code-boxx/I-Was-Here-PHP-Attendance-System) ·
[lynnmugambi/NFC-Attendance-System](https://github.com/lynnmugambi/NFC-Attendance-System)

---

# BÖLÜM P — ONAYINIZI BEKLEYEN KARARLAR

Aşağıdakiler için bir öneri seçtim; itirazınız yoksa öyle ilerlerim.

| # | Konu | Önerim | Alternatif |
|---|---|---|---|
| 1 | Kart devri geçmişi | Kart satırında `employee_id` güncellenir + audit. Hareketler zaten kendi içinde `employee_id` + UID taşıdığı için geçmiş bozulmaz | Ayrı `employee_card_assignments` tablosu (daha ayrıntılı zimmet geçmişi, +1 tablo) |
| 2 | Tam TC kimlik | **Saklanmaz**; yalnız son 4 hane (ad benzerliğini ayırmak için) | Bordro entegrasyonu gerekiyorsa erişimi kısıtlı ayrı alan |
| 3 | Güvenlik rolünün web erişimi | Yok (yalnız `attendance.scan`) | `dashboard.read` eklenip ana sayfayı görmesi |
| 4 | Elle ters yön hareketi | Yalnız `attendance.manual` yetkisi, web'den | Güvenlik görevlisinin cihazdan seçebilmesi |
| 5 | Cooldown süresi | **20 saniye** (`config/pdks.php` sabiti) | 10–30 arası başka bir değer |
| 6 | Offline mod | V1'de **yok**; V2'ye hazırlık kolonları şimdiden açılır | Faz 2'ye dahil edilmesi (süre ve risk ciddi artar) |
| 7 | Kapı bazlı giriş/çıkış kısıtı | Yok (bir kapıdan girip diğerinden çıkılabilir) | Kapı eşleştirmesi zorunlu |
| 8 | `login.php` brute-force koruması | **Faz 2'ye dahil** (mevcut açık, PDKS ile büyür) | Ayrı bir iş olarak sonraya bırakmak |
| 9 | Deploy webhook Secret'ı | **Faz 0'da kapatılması önerilir** (hazır şablon mevcut) | Mevcut hâliyle bırakmak |

**Ayrıca sizden bilgi gereken üç konu:**

- **Vardiya saatleriniz** (Faz 4 için; şimdi gerekmez ama Faz 3'teki "geç gelen" eşiği için bir
  varsayılan lazım — geçici olarak 08:15 alacağım).
- **Kapı sayısı ve adları** (Faz 2 kurulumunda girilecek; bugün yalnız "Ana Giriş" varsayıyorum).
- **Kaç Android telefon** olacağı ve markaları (NFC donanımı ve Android sürümü doğrulanmalı).

---

# EK-1 — FAZ 0 ÖLÇÜM PROTOKOLÜ

Hepsi **salt okunur**; hiçbiri veri değiştirmez.

### Ölçüm 1 — MySQL saat dilimi (en kritik)

```sql
SELECT NOW()              AS mysql_simdi,
       @@session.time_zone AS oturum_tz,
       @@global.time_zone  AS global_tz,
       @@system_time_zone  AS sistem_tz;
```

phpMyAdmin'den çalıştırıp sonucu, **o anki telefon saatinizle birlikte** bana iletin.
`mysql_simdi` gerçek Türkiye saatinden farklıysa §J.3 adım 3 devreye girer.

### Ölçüm 2 — DDL yetkisi

`migrate.php` (admin) sayfasını açın; mevcut migration'lar "✓ zaten var" mı diyor, yoksa yetki
hatası mı veriyor? Hata varsa Faz 1'in şeması phpMyAdmin'den elle kurulur — plan değişmez,
yalnız kurulum adımı eklenir.

### Ölçüm 3 — HTTPS zorlaması

`http://nuverna.derspros.com.tr` (http, s'siz) adresine girin: otomatik olarak `https`'e
dönüyor mu? Dönmüyorsa Faz 1'de kök `.htaccess`'e yönlendirme + HSTS eklenmesi önerilir
(tek başına düşük riskli bir değişiklik, ama onayınızla).

### Ölçüm 4 — Android `Tag.getId()` bayt sırası ⚠ (UID kanonunu bu belirler)

Küçük bir teşhis APK'sı yazacağım; tek yaptığı, okutulan kartın `Tag.getId()` çıktısını
**hiç çevirmeden** ekrana basmak:

```
getId() ham baytlar : 25 A8 7E D7
büyük harf hex      : 25A87ED7
BE ondalık          : 631799511
LE ondalık          : 3615402021
ATQA / SAK / tech listesi
```

Elinizdeki **aynı kartı** okutup ekran görüntüsünü gönderin. Beklenen `25A87ED7`
(USB ile aynı yön); çıktı `D77EA825` olursa kanonu ona göre sabitleriz — alias tablosu
sayesinde her iki durumda da şema değişmez.

### Ölçüm 5 — USB okuyucu ham davranışı

Boş bir metin kutusuna (Not Defteri) **üç farklı kart** okutun ve şunları not edin:
- Sayının başında sıfır var mı? (`0631799511` gibi)
- Sonunda Enter/Tab var mı? (imleç alt satıra geçiyor mu?)
- Her zaman aynı hane sayısı mı? (sabit 10 hane mi, değişken mi?)

Bu üçü, `personel_kartlar.php`'deki giriş kutusunun davranışını belirler.

### Ölçüm 6 — Yedek geri yükleme provası

`admin_db_backups.php`'den güncel bir yedeği indirin ve **yerel/test bir MySQL'e geri yükleyin**.
Canlıya dokunmadan. Hiç denenmemiş bir yedek, yedek sayılmaz.

---

# EK-2 — UYGULAMA SIRASINDA UYULACAK PROJE KURALLARI

`CLAUDE.md` kontrol listesinin PDKS'e uyarlanmış hâli — her faz sonunda tek tek işaretlenecek:

- [ ] Mobilde taşma yok (`overflow-x: clip` korunuyor)
- [ ] Sidebar aktif link tespiti güncel (`$p_pdks` / `$a_pdks`)
- [ ] Bottomnav'a dokunulmadı (bilinçli)
- [ ] Mobilde tüm input'lar `font-size: 16px`
- [ ] Her yeni tablo `.table-wrap` içinde
- [ ] Yazdırmada gizlenecekler `@media print`'te
- [ ] Migration eklendi ve **idempotent**
- [ ] Her write/delete'te `can()` kontrolü
- [ ] Her kritik işlem `audit_log_event()`'e yazıyor
- [ ] Her POST'ta `csrf_check()` (API hariç — cookie kullanmıyor)
- [ ] Hassas veri audit'e yazılmıyor (token/parola/fotoğraf)
- [ ] `APP_SURUM` ve `sw.js` `CACHE_NAME` aynı sayıya çekildi
- [ ] `php -l` tüm değişen dosyalarda temiz
- [ ] Beş smoke testi de geçiyor
- [ ] Koyu temada sabit renk kullanılmadı (yalnız `var(--*)`)
- [ ] Kişisel isim/e-posta örnek veride kullanılmadı

---

**Belge sonu.** Onayınızı ve §P'deki dokuz karar ile üç bilgi için görüşünüzü bekliyorum.
