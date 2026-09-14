# PDKS FAZ 1 — ŞEMA, SÖZLEŞME VE TESLİMAT BELGESİ

**Durum:** Faz 1 tamamlandı · **Faz 2 BAŞLATILMADI** · Canlıya alınmadı
**Tarih:** 2026-09-14 · **Branch:** `claude/nfc-attendance-roadmap-z14alg`
**Üst belgeler:** `PDKS_NFC_YOL_HARITASI.md` (mimari) · `PDKS_NFC_FAZ0_DOGRULAMA.md` (ölçümler)

> **Bu fazda yapılmayanlar:** giriş/çıkış hareket motoru · API · Android uygulaması ·
> arayüz ekranları · pano · raporlar · vardiya · puantaj · offline kuyruk · deploy.
> **Canlı veritabanında hiçbir şey çalıştırılmadı.**

---

## 1. ÖZET

Faz 1, PDKS'in **veritabanı ve alan mantığı temelini** kurar. Dört yeni tablo,
UID normalizasyon sözleşmesi, kart yaşam döngüsü fonksiyonları, yetki kataloğu
ve 141 otomatik test.

| Ölçüt | Sonuç |
|---|---|
| Mevcut tablolara `ALTER` | **0** (sıfır) |
| Silinen veri | **0** |
| Yeniden adlandırılan kolon | **0** |
| Yeni tablo | 4 |
| Değiştirilen mevcut uygulama dosyası | 2 (`config/helpers.php`, `migrate.php`) — **ikisi de yalnız ekleme** |
| Kullanıcıya görünen davranış değişikliği | **Yok** (yeni yetkiler/rol, arayüzü olmadığı için görünmez) |
| Otomatik test | **141 / 141 geçti** |
| Geri alma | `git revert` — tablolar boş kalır, hiçbir modül etkilenmez |

---

## 2. OLUŞTURULAN / DEĞİŞEN DOSYALAR

| Dosya | Durum | Açıklama |
|---|---|---|
| `config/pdks.php` | **YENİ** | Çekirdek: şema DDL + UID normalizasyonu + kart alan mantığı + yetki kapısı |
| `scripts/pdks_uid_smoke.php` | **YENİ** | UID sözleşmesi testi (57 test) — Faz 0'daki kanıt betiğinin yerini alır |
| `scripts/pdks_db_smoke.php` | **YENİ** | Şema kısıtları + alan mantığı testi (84 test), bellek içi SQLite |
| `docs/PDKS_FAZ1_SEMA.md` | **YENİ** | Bu belge |
| `config/helpers.php` | değişti | **Yalnız ekleme:** 9 yetki + `ik` rolü + rol→yetki haritası |
| `migrate.php` | değişti | **Yalnız ekleme:** PDKS tablo migrasyon bölümü (admin) |
| `docs/PDKS_NFC_FAZ0_DOGRULAMA.md` | değişti | Canlı MySQL saat dilimi ölçümü işlendi (§1.3) |
| `docs/PDKS_NFC_YOL_HARITASI.md` | değişti | Referans güncellemesi |
| ~~`scripts/pdks_faz0_uid_kanit.php`~~ | **SİLİNDİ** | Algoritmanın ikinci kopyasıydı; `config/pdks.php`'ye taşındı, testi `pdks_uid_smoke.php`'ye devredildi |

**Dokunulmayanlar:** `assets/style.css` · `assets/app.js` · `sw.js` · `index.php` ·
`config/db.php` · `config/auth.php` · `users.php` ve diğer tüm uygulama sayfaları.

---

## 3. ŞEMA

### 3.1 `employees` — personel kartoteksi

| Kolon | Tip | Null | Varsayılan | Amaç |
|---|---|:---:|---|---|
| `id` | INT AUTO_INCREMENT | H | — | PK |
| `personnel_no` | VARCHAR(30) | E | NULL | Sicil no. **UNIQUE**; NULL çoklu olabilir (sicili olmayan personel) |
| `full_name` | VARCHAR(150) | H | — | Ekranda gösterilen tam ad |
| `department` | VARCHAR(100) | H | `''` | Departman |
| `job_title` | VARCHAR(100) | H | `''` | Görev |
| `depo` | VARCHAR(150) | H | `''` | Depo damgası. `''` = tüm depolarda görünür (mevcut kural) |
| `status` | VARCHAR(20) | H | `'aktif'` | `aktif` · `pasif` · `ayrildi` |
| `user_id` | INT | E | NULL | Opsiyonel `users.id` bağı. **UNIQUE** (NULL hariç) |
| `photo_file` | VARCHAR(64) | E | NULL | `uploads/personel/` içindeki rastgele dosya adı |
| `photo_updated_at` | DATETIME | E | NULL | Android önbellek geçersizleştirme (Faz 2 ETag kaynağı) |
| `phone` | VARCHAR(30) | E | NULL | **Kapı API'sine ASLA gönderilmez** |
| `hire_date` / `leave_date` | DATE | E | NULL | İşe giriş / ayrılış |
| `notes` | TEXT | E | NULL | Serbest not |
| `created_by` / `updated_by` | INT | E | NULL | `users.id` |
| `created_at` | DATETIME | H | `CURRENT_TIMESTAMP` | |
| `updated_at` | DATETIME | E | NULL `ON UPDATE CURRENT_TIMESTAMP` | |

**TC kimlik numarası kolonu YOKTUR** (onaylanan karar #2). Yol haritasında
`national_id_last4` önerilmişti; V1'de SGK/bordro entegrasyonu olmadığı için
**tamamen çıkarıldı** — gereksiz KVKK yüzeyi açmamak adına.

### 3.2 `employee_cards` — fiziksel kart

| Kolon | Tip | Null | Varsayılan | Amaç |
|---|---|:---:|---|---|
| `id` | INT AUTO_INCREMENT | H | — | PK |
| `employee_id` | INT | H | — | Kartı taşıyan personel (**FK**, CASCADE) |
| `uid_hex` | VARCHAR(32) | H | — | **Kanonik UID — UNIQUE** |
| `uid_bytes` | TINYINT | H | 4 | 4 / 7 / 10 |
| `uid_decimal` | VARCHAR(25) | E | NULL | Teşhis/arama. **Kimlik değil** |
| `card_type` | VARCHAR(30) | H | `'mifare_classic_1k'` | |
| `atqa` / `sak` | VARCHAR(8) | E | NULL | Teşhis (`0004` / `08`) |
| `label` | VARCHAR(60) | H | `''` | Kart üstündeki yazı |
| `status` | VARCHAR(20) | H | `'aktif'` | `aktif` `iptal` `kayip` `degistirildi` `suresi_doldu` `pasif` |
| `issued_at` / `expires_at` | DATE | E | NULL | Veriliş / son geçerlilik |
| `replacement_card_id` | INT | E | NULL | Yerine geçen kart — **geçmiş silinmez** |
| `revoked_at` / `revoked_by` | DATETIME / INT | E | NULL | İptal izi |
| `revoke_reason` | VARCHAR(200) | H | `''` | |
| `enrolled_source` | VARCHAR(20) | H | `'usb_decimal'` | `usb_decimal` · `nfc_hex` |
| `notes` / `created_by` / `created_at` / `updated_at` | — | — | — | |

### 3.3 `employee_card_uids` — UID takma adları

| Kolon | Tip | Null | Amaç |
|---|---|:---:|---|
| `id` | INT AUTO_INCREMENT | H | PK |
| `card_id` | INT | H | **FK** → `employee_cards.id`, ON DELETE CASCADE |
| `uid_hex` | VARCHAR(32) | H | Aranabilir gösterim — **UNIQUE** |
| `kind` | VARCHAR(20) | H | `canonical` · `reversed` · `legacy` |
| `created_at` | DATETIME | H | |

Her kart için **kanonik + ters** gösterim yazılır (palindrom UID'de tek satır).
`631799511`, `25A87ED7` ve `D7:7E:A8:25`'in aynı karta çözülmesi böylece bir
`if` bloğunun değil, **UNIQUE kısıtının** garantisi olur.

### 3.4 `attendance_gates` — kapı / lokasyon

| Kolon | Tip | Null | Varsayılan | Amaç |
|---|---|:---:|---|---|
| `id` | INT AUTO_INCREMENT | H | — | PK |
| `name` | VARCHAR(80) | H | — | **UNIQUE** — "Ana Giriş" |
| `depo` | VARCHAR(150) | H | `''` | Faz 2'de API'nin depo bağlamı buradan gelir |
| `is_active` | TINYINT(1) | H | 1 | |
| `sort_order` | INT | H | 0 | |
| `notes` / `created_by` / `created_at` / `updated_at` | — | — | — | |

> Faz 1'de yalnız **tablo** kurulur; kapı yönetim arayüzü Faz 2'dedir.
> `attendance_devices` Faz 1'de **açılmadı** — yol haritası onu Faz 2'ye koyuyor
> ve cihaz kaydı olmadan tek başına anlamı yok.

---

## 4. KISITLAR VE İNDEKSLER

### 4.1 UNIQUE — iş kurallarını uygulayanlar

| Kısıt | Tablo | Kolon | Uyguladığı iş kuralı |
|---|---|---|---|
| `uq_emp_pno` | employees | `personnel_no` | Aynı sicil iki personele verilemez (NULL serbest) |
| `uq_emp_user` | employees | `user_id` | **Bir uygulama hesabı en çok bir personele bağlanır** (NULL çoklu) |
| `uq_ec_uid` | employee_cards | `uid_hex` | **Aynı fiziksel kart iki kez kaydedilemez** → dolayısıyla aynı anda iki personele atanamaz |
| `uq_ecu_uid` | employee_card_uids | `uid_hex` | Bir gösterim yalnız bir karta çözülebilir; ters-gösterim çakışması da burada yakalanır |
| `uq_gate_name` | attendance_gates | `name` | Kapı adı tekil |

### 4.2 Yabancı anahtarlar

| Kısıt | İlişki | Davranış | Gerekçe |
|---|---|---|---|
| `fk_ec_emp` | `employee_cards.employee_id` → `employees.id` | **CASCADE** | Personel silinirse kartı sahipsiz kalmamalı |
| `fk_ecu_card` | `employee_card_uids.card_id` → `employee_cards.id` | **CASCADE** | Sahipsiz alias sessiz yanlış eşleşme üretir |
| `fk_emp_user` | `employees.user_id` → `users.id` | **SET NULL** | `users` MEVCUT tablodur. Hesap silinirse personel kaydı KAYBOLMAZ, yalnız bağı kopar |

> **`fk_emp_user` OPSİYONELDİR.** Ayrı bir `ALTER` olarak denenir ve başarısız
> olursa migrasyon **durmaz** (rapora yazılır). Gerekçe: `users` mevcut bir
> tablodur ve bazı paylaşımlı hostinglerde FK ekleme yetkisi kısıtlıdır. İş
> kuralını zaten `uq_emp_user` UNIQUE kısıtı uyguluyor; FK yalnız referans
> bütünlüğü için ek güvencedir. Testler bu toleransı doğruluyor (§7, test 13).

### 4.3 İndeksler

| Tablo | İndeks | Kolon(lar) | Neden |
|---|---|---|---|
| employees | `idx_emp_status` | `status` | Aktif personel listesi |
| employees | `idx_emp_depo` | `depo(80)` | `depo_sql_column()` filtresi |
| employees | `idx_emp_dept` | `department(80)` | Departman filtresi |
| employees | `idx_emp_name` | `full_name(80)` | Ad araması |
| employee_cards | `idx_ec_emp` | `employee_id` | Personelin kartları |
| employee_cards | `idx_ec_status` | `status` | Aktif kart filtresi |
| employee_cards | `idx_ec_dec` | `uid_decimal` | USB ondalığıyla teşhis araması |
| employee_card_uids | `idx_ecu_card` | `card_id` | Kartın aliasları |
| attendance_gates | `idx_gate_depo` / `idx_gate_active` | `depo(80)` / `is_active` | Depo kapsamı, aktif kapı |

> `uq_ecu_uid` aynı zamanda Faz 2'nin **en sık sorgusunun** (kart okutma →
> UID → kart) indeksidir; ayrı bir arama indeksi gerekmez.

---

## 5. MİGRASYON MEKANİZMASI

**Yeni bir migration çerçevesi getirilmedi.** Mevcut projenin desenine uyuldu
(`hesap_migrate()` emsali) ve **bir güvenlik katmanı eklendi**.

```
config/pdks.php → pdks_tablolar()   : tablo adı => CREATE TABLE IF NOT EXISTS
                → pdks_migrate()    : idempotent kurulum, satır satır rapor
                → pdks_sema_hazir() : tablolar hazır mı
```

### Neden `config/db.php`'ye konmadı ⚠

Bu **bilinçli bir karardır** ve iki gerekçesi var:

1. `config/db.php` her istekte, her sayfada çalışır. Oradaki bir migration
   hatası **tüm uygulamayı** (yükleme, kantar, hesap, beyan) etkiler.
2. **Faz 0 §1.3b bulgusu:** Canlı `config/db.php`, repodakinden farklı
   görünüyor (DB adı uyuşmuyor ve o dosyanın DROP etmesi gereken eski HKS
   tabloları hâlâ duruyor). Yani **o dosyaya yazılan migration canlıya hiç
   ulaşmayabilir.**

### Nasıl çalıştırılır

**`migrate.php` → "PDKS (Personel) Tabloları" kartı → "PDKS Tablolarını Oluştur"**
(yalnız admin, CSRF korumalı, POST).

- Tekrar çalıştırmak **güvenlidir** — var olan tablo "• Zaten var" der, dokunulmaz.
- Her tablo için ayrı sonuç satırı: `✓ Oluşturuldu` · `• Zaten var` · `✗ HATA` (tam mesajla).
- Oluşturma `audit_log`'a `migrate/pdks` olarak yazılır.
- Hata hâlinde: aynı sayfadaki **"CREATE TABLE SQL'lerini göster"** açılır
  bölümünden SQL'ler kopyalanıp phpMyAdmin'den elle çalıştırılabilir.
- `pdks_migrate()` **kendiliğinden ÇALIŞMAZ**; hiçbir sayfa onu otomatik çağırmaz.

**Faz 1 deploy'u bu yüzden tamamen atıldır (inert):** kod canlıya çıksa bile
admin butona basana kadar veritabanında hiçbir şey olmaz.

---

## 6. UID NORMALİZASYON SÖZLEŞMESİ

**Tek otorite: `config/pdks.php`.** Android istemci ve USB ekranı yalnız gösterim yapar.

```
KANON = BÜYÜK HARF HEX · ayraçsız · baştaki sıfır baytları korunmuş
        uzunluk bayta sabit: 4→8 hane · 7→14 hane · 10→20 hane
```

| Fonksiyon | Sözleşme |
|---|---|
| `pdks_uid_hex_normalize($ham)` | `:` `-` `.` boşluk `0x` temizler, büyütür, 4/7/10 bayt doğrular → kanon veya `null` |
| `pdks_uid_from_decimal($dec, $bayt=null)` | Ondalık → kanon, **sola sıfır dolgulu**. Binlik ayracını tolere eder |
| `pdks_uid_reverse($kanon)` | **Bayt** bazında ters çevirme (nibble değil) |
| `pdks_uid_to_decimal($kanon)` | Kanon → işaretsiz ondalık (yalnız gösterim) |
| `pdks_dec_to_hex_string($dec)` | Saf string aritmetiği — bcmath/gmp **gerektirmez** |
| `pdks_uid_adaylari($ham, $kaynak)` | Alias arama kümesi. **`$kaynak` ZORUNLU** |
| `pdks_uid_bayt_sayisi($kanon)` | Bayt uzunluğu |

### İki değişmez kural

**① Kaynak bildirilir, TAHMİN EDİLMEZ** (onaylanan karar #10)

```
'12345678'  nfc_hex     →  12345678   (kart A)
'12345678'  usb_decimal →  00BC614E   (kart B)
```
Aynı metin iki farklı kartı gösterir. `PDKS_UID_KAYNAKLARI` dışında bir kaynak
→ **boş liste** (fail-closed), asla tahmin yok.

**② `hexdec()` / `dechex()` / `(int)` TAM UID ÜZERİNDE KULLANILMAZ** (karar #12)

10 baytlık UID 80 bittir; PHP tamsayısına sığmaz ve bu fonksiyonlar sessizce
float'a düşüp **yanlış UID üretir**. Faz 0'da bu ortamda bcmath/gmp da yoktu.
`pdks_dec_to_hex_string()` yalnız **tek hane** (0-15) için `dechex()` kullanır —
o güvenlidir. Test `int cast BOZAR` bunu doğrular.

### Kart yazmanın TEK yolu

`pdks_kart_olustur($employeeId, $hamUid, $kaynak, $ek, $pdo)`

Tek işlemde: kanona çevirir → personeli doğrular → **kanonik VE ters** çakışmasını
kontrol eder → kartı yazar → **canonical + reversed alias'ları** yazar → audit'e düşer.

> ⚠ **İkinci bir yazma yolu açmayın.** Alias'sız yazılan bir kart, ters
> gösterimle okutulduğunda BULUNAMAZ. (`halkayit/taslak_lib.php`'deki
> "taslak yazmanın tek yolu" kuralının aynısı.)

---

## 7. TESTLER

```
$ php scripts/pdks_uid_smoke.php     →  57 test geçti, 0 hata
$ php scripts/pdks_db_smoke.php      →  84 test geçti, 0 hata
                                        ─────────────────────
                                        141 / 141
```

`pdks_db_smoke.php` **elle yazılmış bir test şeması kullanmaz.** `config/pdks.php`
içindeki **gerçek MySQL DDL'i** SQLite'a çevirip çalıştırır (`pdks_ddl_sqlite`).
Böylece test edilen UNIQUE/FK kısıtları üretime gidecek olanların ta kendisidir;
elle yazılmış bir şema, kendi yazdığımız kısıtları test etmek olurdu.

| İstenen test | Nerede | Sonuç |
|---|---|---|
| `631799511` → `25A87ED7` | uid §1 | ✅ |
| `25A87ED7` → `25A87ED7` | uid §1 | ✅ |
| Belirsiz sayısal HEX otomatik tespit edilmiyor | uid §8 | ✅ (`12345678` iki farklı karta) |
| Baştaki sıfır davranışı | uid §5 | ✅ (`2467966` → `0025A87E`) |
| 7 baytlık UID | uid §6, db §8 | ✅ |
| 10 baytlık UID | uid §7, db §8 | ✅ (int cast'in bozduğu da kanıtlı) |
| Mükerrer kanonik UID reddi | db §5, §6 | ✅ |
| Mükerrer alias reddi | db §5, §6 | ✅ (ters gösterimle deneme de reddedildi) |
| Aynı personele aynı kart iki kez verilemez | db §5 | ✅ |
| Bir kart aynı anda iki personelde olamaz | db §5 | ✅ |
| `employees.user_id` NULL kabul (çoklu) | db §2 | ✅ |
| `employees.user_id` mükerrer non-NULL reddi | db §2 | ✅ |

**Ek kapsam:** üç gösterimin aynı karta çözülmesi · tanımsız UID → null ·
FK CASCADE (personel silinince kart ve alias'ları da gider) · kart iptalinde
geçmişin korunması ve iptal kartın hâlâ **tanınması** (sessiz "tanımsız kart"
değil, Faz 2'de "KART İPTAL EDİLMİŞ" diyebilmek için) · palindrom UID'de tek
alias · yetki kapısı (`ik` rolünde `scan` YOK, `guvenlik` rolünde `read` YOK) ·
migrasyonun idempotanlığı ve FK hatasına dayanıklılığı.

### Mevcut test takımı — regresyon kontrolü

| Betik | Sonuç |
|---|---|
| `hesap_smoke` · `hesap_ui_smoke` · `hesap_pdf_smoke` | ✅ geçti |
| `beyan_bildirim_smoke` · `beyan_ui_smoke` | ✅ geçti |
| `cost_link_smoke` | ✅ geçti |
| `test_material_stock_helpers` | ⚠ **1 test başarısız — ÖNCEDEN VAR OLAN** |

> `test_material_stock_helpers` başarısızlığı, değişikliklerim **geri
> alındığında da** (`git stash` ile base commit'te) birebir tekrarlanıyor.
> Faz 1 kaynaklı DEĞİLDİR ve kapsam dışı olduğu için düzeltilmedi.

---

## 8. YETKİLER

`config/helpers.php` içindeki mevcut seed mekanizmasına **eklendi**
(`INSERT IGNORE` — mevcut atamaları bozmaz, tekrar çalışması güvenlidir).

| Yetki | Ne açacak |
|---|---|
| `attendance.read` | Hareket listesi, canlı pano (Faz 2) |
| `attendance.scan` | **Yalnız API**: kart okut + onayla (Faz 2) |
| `attendance.manual` | Web'den elle hareket (Faz 2) |
| `attendance.correct` | Hareket düzeltme, gerekçe zorunlu (Faz 2) |
| `attendance.report` | Raporlar + dışa aktarma (Faz 3) |
| `attendance.employees` | Personel ekle/düzenle (Faz 1B) |
| `attendance.cards` | Kart tanımla/iptal/devret (Faz 1B) |
| `attendance.devices` | Cihaz yönetimi (Faz 2) |
| `attendance.admin` | Kapı/cihaz yönetimi, tam erişim |

### Rol dağılımı

| Rol | Aldığı PDKS yetkileri | Not |
|---|---|---|
| `admin` | **hepsi** | `$all_p` üzerinden otomatik |
| **`ik`** *(YENİ — "İnsan Kaynakları")* | `read` `manual` `correct` `report` `employees` `cards` + `dashboard.read` | **`scan` BİLEREK YOK** — o kapı cihazının yetkisidir |
| `operator` · `viewer` · `muhasebe` | **hiçbiri** | Puantaj verisi hassastır; en az yetki |
| `guvenlik` | — | **Faz 2'de açılacak.** Şimdi açmak, kullanılmayan bir giriş hesabı yaratmak olurdu |

`attendance.scan` kataloğa **şimdi** eklendi (Faz 2'de yeni bir seed
değişikliği gerekmesin diye) ama yalnız admin'e düşüyor.

> **Görünürlük:** Bu yetkiler bir sonraki deploy'da `role_permissions`'a yazılır.
> Kullanıcı hiçbir değişiklik **görmez** — PDKS ekranı henüz yok, `users.php`
> yalnız rol atar (yetki listesi göstermez). Yeni `ik` rolü `users.php`'de
> atanabilir bir seçenek olarak görünür.

---

## 9. YOL HARİTASINDAN SAPMALAR

| # | Sapma | Gerekçe |
|---|---|---|
| 1 | **Arayüz ekranları yapılmadı** (`personel.php`, `personel_form.php`, `personel_kartlar.php`, `personel_foto.php`) | Yol haritası Faz 1'e dahil etmişti; **talimatınız** Faz 1'i "yalnız veritabanı/backend temeli" olarak tanımladı ve 11 maddelik kapsam listesinde arayüz yok. Sessizce genişletmek yerine ayırdım → **Faz 1B** olarak öneriyorum |
| 2 | `national_id_last4` kolonu **hiç açılmadı** | Onaylanan karar #2 "gereksiz KVKK yüzeyi açma" diyor. V1'de SGK/bordro yok; son 4 hane de gerekmiyor. Gerekirse sonradan tek kolon eklenir |
| 3 | `first_name` / `last_name` yerine **`full_name`** | Depo konvansiyonu tek alan kullanıyor (`sofor_adi`, `display_name`). Kapıda gösterilecek olan tam addır; iki alan, ad/soyad ayrımı belirsiz Türkçe adlarda veri kalitesi sorunu yaratır. Talimat "listeyi körü körüne kullanma, mevcut konvansiyona uyarla" diyordu |
| 4 | `definition_types()`'a `departman`/`gorev` **eklenmedi** | Yol haritası §B.3 öneriyordu. Backend-only Faz 1'de bir forma ihtiyaç duyulmuyor ve eklenirse **Tanımlar ekranında kullanıcıya görünen** bir değişiklik olurdu. Arayüz fazına (1B) ertelendi → Faz 1 kullanıcıya tamamen görünmez kaldı |
| 5 | `attendance_devices` **açılmadı** | Yol haritası Faz 2'ye koyuyor; talimat da "Faz 1 yol haritası gerektiriyorsa" diyordu. Cihaz kaydı, API olmadan anlamsız |
| 6 | `attendance_gates` **açıldı** (yol haritasında Faz 2) | Talimatınızın kapsam listesinde madde 5 olarak **açıkça** isteniyordu. Tablo boş durur; arayüzü Faz 2'de |
| 7 | `scripts/pdks_faz0_uid_kanit.php` **silindi** | Algoritmanın ikinci kopyasıydı. Faz 0'da kendi yazdığım notu uyguladım: algoritma `config/pdks.php`'ye taşındı, test `pdks_uid_smoke.php`'ye devredildi. İki kopya ayrışır ve ayrışan taraf sessizce yanlış kart eşler |
| 8 | `uid_decimal` **VARCHAR(25)** | Faz 0 §4.7 düzeltmesi (10 baytlık UID ondalığı 25 haneye çıkabiliyor); onaylanan karar #12 |

---

## 10. RİSKLER

| # | Risk | Etki | Durum |
|---|---|---|---|
| 1 | **Canlı `config/db.php` repodakinden farklı** (Faz 0 §1.3b) | O dosyaya yazılan migration canlıya ulaşmıyor olabilir | **Faz 1 etkilenmiyor** — migrasyonumuz `config/pdks.php`'de ve elle tetikleniyor. Yine de 1 dakikalık kontrol isteniyor |
| 2 | DDL yetkisi hâlâ **ölçülmedi** (Faz 0 Ölçüm 2) | `CREATE TABLE` reddedilirse tablolar kurulamaz | `migrate.php` tam hata mesajını ve elle çalıştırılacak SQL'i gösteriyor. **Faz 1B/2 öncesi ölçülmeli** |
| 3 | `fk_emp_user` eklenemeyebilir | Referans bütünlüğü zayıflar | Tolere ediliyor; iş kuralını `uq_emp_user` UNIQUE zaten uyguluyor. Rapor satırında görünür |
| 4 | UNIQUE kısıtı `utf8mb4_unicode_ci` ile **harf duyarsız** | Teorik olarak `25a87ed7` ve `25A87ED7` çakışır | **İstenen davranış** — zaten daima büyük harfe normalize ediliyor. Güvenlik normalizasyonda, collation'a bel bağlanmıyor |
| 5 | Ters-alias çakışması (≈4 milyarda 1) | Yeni kart tanımlanamaz | `pdks_kart_olustur()` `uid_kullanimda` kodu + çakışan kart id'si döndürür; sessiz başarısızlık yok |
| 6 | `test_material_stock_helpers` başarısız | — | **ÖNCEDEN VAR OLAN**, Faz 1 kaynaklı değil (base commit'te de aynı). Kapsam dışı |

---

## 11. FAZ 2 ÖNCESİ GEREKENLER

| # | Gereklilik | Kaynak |
|---|---|---|
| 1 | **Zaman kararının resmen dondurulması** → ölçüm **Seçenek A**'yı destekliyor (sapma yok, `+03`, 10800 sn). Onayınız gerekiyor | Karar #13 |
| 2 | Android `getId()` bayt sırası ölçümü (`tools/nfc_uid_tani/`) | Faz 0 §5.3 |
| 3 | **API login hız sınırı** — Faz 2'nin BLOKERİ | Faz 0 §7.3 |
| 4 | DDL yetkisi kontrolü: `migrate.php` → PDKS kartı → butona basıldığında ne diyor | Faz 0 Ölçüm 2 |
| 5 | USB okuyucu Enter/sıfır-dolgu davranışı (2 dakika) | Faz 0 §6.2 |
| 6 | Kapı adları · telefon sayısı/modeli · vardiya saatleri · personel sayısı | Faz 0 §9.3 |
| 7 | **Faz 1B (arayüz) yapılsın mı, Faz 2'ye mi katılsın** kararı | Bu belge §9-1 |

---

## 12. FAZ 1 KABUL ÖLÇÜTLERİ

| Ölçüt | Durum |
|---|---|
| Yalnız ekleme yapan migrasyon, mevcut tablolara ALTER yok | ✅ |
| Migrasyon idempotent ve tekrar çalıştırılabilir | ✅ (test 13) |
| Veri silme / kolon yeniden adlandırma yok | ✅ |
| Canlıda çalıştırma yok | ✅ |
| İş kurallarını uygulayan UNIQUE kısıtları | ✅ (5 adet) |
| Kasıtlı FK ve indeksler | ✅ (3 FK, 9 indeks) |
| UID normalizasyonu, kaynak açık, saf string aritmetiği | ✅ |
| 4 / 7 / 10 bayt desteği | ✅ |
| İstenen tüm testler + geçiş | ✅ **141/141** |
| Mevcut davranış korunuyor | ✅ (mevcut takım geçiyor; 1 önceden var olan hata) |
| Belgeleme | ✅ (bu belge) |

**Faz 1 tamamlandı. Faz 2 BAŞLATILMADI. Onayınızı bekliyorum.**
