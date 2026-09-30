# PDKS GİRİŞ / ÇIKIŞ — BASİT SON AKIŞ (tek web uygulaması)

**Durum:** Kod tamamlandı · Canlıya alınmadı — merge/deploy için AYRI onay bekleniyor
**Tarih:** 2026-09-14 · **Branch:** `claude/nfc-attendance-roadmap-z14alg`
**Üst belgeler:** `PDKS_NFC_YOL_HARITASI.md` (mimari) · `PDKS_NFC_FAZ0_DOGRULAMA.md` (Faz 0 ölçümleri) ·
`PDKS_FAZ1_SEMA.md` (şema + UID sözleşmesi + §6a düzeltmesi) ·
`PDKS_FAZ1B_PERSONEL_KART_UI.md` (personel/kart arayüzü) ·
`PDKS_WEBNFC_DIAGNOSTIC.md` (Web NFC teşhis sayfası ve GERÇEK CİHAZ ölçümü)

> **Kapsam kararı (kullanıcı onaylı):** Native Android uygulaması, cihaz
> token/enrollment, heartbeat, ayrı kimlik doğrulama, mikroservis **YOK**.
> **TEK web uygulaması** — USB HID (masaüstü) ve tarayıcının kendi Web NFC
> API'si (`NDEFReader`, Android + Chrome) **AYNI** sunucu tarafı UID
> normalizasyonuna ve veritabanına çıkar. Cihaz/token/heartbeat/offline
> kuyruk/vardiya/puantaj/bordro tabloları **eklenmedi** — bilerek.

---

## 1. ÖZET

Bu faz, Faz 1'in (şema/UID) ve Faz 1B'nin (personel/kart yönetimi) üzerine
üç şey ekler:

1. **`web_nfc`** — Web NFC'nin (Chrome/Android) `NDEFReader.serialNumber`
   çıktısı için, **gerçek cihazda ölçülmüş** yeni bir UID kaynağı.
2. **`attendance_events`** — Giriş/Çıkış olaylarının yazıldığı, minimum
   alanlı tek yeni tablo + bunu yazan tek fonksiyon: `pdks_devam_kaydet()`.
3. **`giris_cikis.php`** — Kiosk benzeri, tek ekranlı GİRİŞ/ÇIKIŞ sayfası:
   önce mod (GİRİŞ ya da ÇIKIŞ) **açıkça** seçilir, sonra o mod içinde art
   arda kart okutulur.

| Ölçüt | Sonuç |
|---|---|
| Yeni sayfa | 1 (`giris_cikis.php`) |
| Yeni tablo | 1 (`attendance_events`) — Faz 1'in önceden atılan `attendance_gates` temeli dışında |
| Yeni UID kaynağı | 1 (`web_nfc`) — ölçülmüş bayt-tersi dönüşümü |
| Yeni çekirdek fonksiyon | `pdks_uid_from_web_nfc()`, `pdks_devam_kaydet()`, `pdks_event_turleri()` |
| Mevcut tabloya `ALTER` | **0** |
| `assets/style.css` / `assets/app.js` / `sw.js` değişikliği | **0** |
| `config/db.php` / `config/auth.php` değişikliği | **0** |
| `config/helpers.php` değişikliği | Sidebar'a "Giriş / Çıkış" linki + `$p_pdks` görünürlük koşulu genişletildi (attendance.scan) |
| Otomatik test | **507 / 507 geçti** (8 PDKS betiği, bkz. §8) |

---

## 2. DOSYALAR

| Dosya | Durum | Ne |
|---|---|---|
| `giris_cikis.php` | **YENİ** | Kiosk GİRİŞ/ÇIKIŞ sayfası + aynı dosya içinde JSON kayıt ucu (`?ajax=kaydet`) |
| `config/pdks.php` | değişti | `web_nfc` kaynağı, `pdks_uid_from_web_nfc()`, `attendance_events` tablosu, `pdks_devam_kaydet()`, `pdks_event_turleri()` |
| `config/helpers.php` | değişti | Sidebar'a "Giriş / Çıkış" linki (`attendance.scan`) + `$p_pdks` genişletildi |
| `personel_kartlar.php` | değişti | Kart tanımlama akışı artık `kaynak=web_nfc`'yi de kabul ediyor (önizleme ucu + POST) |
| `personel_form.php` | değişti | Aynı — gömülü kart modalı `kaynak=web_nfc`'yi kabul ediyor |
| `assets/pdks.js` | değişti | Tarama kutuları artık kaynak-farkında (`data-pdks-kaynak-field`); NFC buton wiring (`data-pdks-nfc-target`) eklendi |
| `assets/pdks.css` | değişti | Kiosk ekranı için `.pdks-kiosk-*` sınıfları eklendi |
| `scripts/pdks_uid_smoke.php` | değişti | §12: `web_nfc` dönüşüm testleri eklendi (10 yeni test) |
| `scripts/pdks_db_smoke.php` | değişti | §15: `pdks_devam_kaydet()` domain testleri eklendi (60+ yeni test) |
| `scripts/pdks_faz1b_static_smoke.php` | değişti | §5 güncellendi: `attendance_events` artık MEVCUT — kapsam kontrolü Faz 1B sayfalarının onu TEKRARLAMADIĞINI doğrulayacak şekilde daraltıldı |
| `scripts/pdks_giris_cikis_static_smoke.php` | **YENİ** | Statik kural testi — 39 test |
| `scripts/pdks_giris_cikis_ui_smoke.php` | **YENİ** | Render + uçtan uca ajax testi — 31 test |

**Dokunulmayanlar:** `assets/style.css` · `assets/app.js` · `sw.js` ·
`config/db.php` · `config/auth.php` · `personel.php` · `personel_foto.php` ·
`pdks_nfc_test.php` (teşhis sayfası aynen kalır, bkz. §7) · Faz 1'in
UID/şema çekirdeği (`pdks_uid_hex_normalize`, `pdks_uid_from_decimal`,
`pdks_uid_adaylari`'nin USB/nfc_hex davranışı, `pdks_kart_olustur`'un
çakışma/alias mantığı) — **tek satır değişmedi**, yalnız `web_nfc` için
YENİ bir dal eklendi.

---

## 3. USB AKIŞI (değişmedi)

```
USB HID okuyucu → ondalık string (ör. "631799511")
        │  kaynak = 'usb_decimal'
        ▼
pdks_uid_from_decimal()  →  kanonik HEX ("25A87ED7")
```

Kart tanımlama (`personel_kartlar.php`, `personel_form.php`) ve Giriş/Çıkış
(`giris_cikis.php`) sayfalarının USB kutusu **yalnız rakam** kabul eder,
Enter formu göndermez (yalnız önizleme/kaydı tetikler), okuma sonrası odak
kutuya geri döner. Bu davranış **değişmedi** — yalnız kaynak etiketi artık
bir hidden alanla açıkça taşınıyor (aşağıya bakın).

---

## 4. WEB NFC AKIŞI — ÖLÇÜLMÜŞ, SPEKÜLATİF DEĞİL

### 4.1 Ölçüm

`pdks_nfc_test.php` (teşhis sayfası, `docs/PDKS_WEBNFC_DIAGNOSTIC.md`)
gerçek bir Android telefonda, gerçek test kartıyla (USB: `631799511` →
kanonik `25A87ED7`) çalıştırıldı. Sonuç:

```
event.serialNumber  =  "d7:7e:a8:25"
```

`d7:7e:a8:25` ayraçsız/büyük harfle `D77EA825` olur — bu, `25A87ED7`'nin
**bayt sırası ters çevrilmiş** hâlidir. Yani `web_nfc` kaynağı için doğru
dönüşüm, HER okumada deterministik biçimde bayt-tersini almaktır:

```
Web NFC serialNumber → ayraçsız/büyük harf → BAYT-TERSİ → kanonik
   "d7:7e:a8:25"     →      "D77EA825"      →  "25A87ED7"  ✓ USB ile AYNI
```

### 4.2 Uygulama — `pdks_uid_from_web_nfc()` (`config/pdks.php`)

```php
function pdks_uid_from_web_nfc(?string $ham): ?string
{
    $temiz = pdks_uid_hex_normalize($ham);
    if ($temiz === null) return null;
    return pdks_uid_reverse($temiz);
}
```

`PDKS_UID_KAYNAKLARI` artık `['usb_decimal', 'nfc_hex', 'web_nfc']`.
`pdks_uid_adaylari()` ve `pdks_kart_olustur()` üç kaynağı da bir
`match($kaynak)` ile ayırt eder — otomatik kaynak TESPİTİ hâlâ YASAKTIR
(Faz 0/1 kararı #10): kaynak istemcinin **açıkça** gönderdiği değerdir.

### 4.3 Bu, Faz 1 §6a'nın yasakladığı "otomatik ters-alias" DEĞİLDİR

Faz 1'de düzeltilen hata: bir kartın kanonik UID'sinin yanına, "belki
tersi de odur" varsayımıyla **spekülatif ikinci bir alias satırı** yazmak
— iki farklı fiziksel kart aynı byte'ların tersiyle çakışabildiği için bu
tehlikeliydi (bkz. `PDKS_FAZ1_SEMA.md` §6a).

`pdks_uid_from_web_nfc()` bundan **kategorik olarak farklıdır**:

| | Faz 1'in yasakladığı (reverse-alias) | `web_nfc` adaptörü |
|---|---|---|
| Kapsam | Kart bazında, spekülatif | **Kaynak** bazında, TÜM okumalarda tutarlı |
| Dayanak | Varsayım ("belki tersi de odur") | **Gerçek cihaz ölçümü** |
| Sonuç | İkinci bir alias SATIRI yazılır | TEK kanonik değer üretilir (`pdks_uid_from_decimal()` gibi) |
| `nfc_hex` (teşhis ekranı) etkilenir mi | — | **HAYIR** — dönüşüm YALNIZ `web_nfc` içindir |

`scripts/pdks_uid_smoke.php` §12, `nfc_hex` kaynağının AYNI ham metni
(`D77EA825`) **farklı** (dönüşümsüz) kanonikleştirdiğini ayrıca doğrular —
iki kaynak birbirine karışmıyor.

### 4.4 İstemci tarafı — kanonikleştirme HİÇBİR ZAMAN JS'te yapılmaz

`giris_cikis.php` ve `assets/pdks.js`, `NDEFReader`'ın `reading` olayından
gelen `event.serialNumber`'ı **olduğu gibi**, yalnız `kaynak=web_nfc`
etiketiyle sunucuya gönderir. Bayt-tersi hesaplayan hiçbir JS kodu YOKTUR
— TEK OTORİTE her zaman sunucudaki `pdks_uid_from_web_nfc()`'tir.
`scripts/pdks_giris_cikis_static_smoke.php` §5 bunu regex ile doğrular.

---

## 5. KART ATAMASI — HEM USB HEM WEB NFC, AYNI SUNUCU FONKSİYONU

`personel_kartlar.php` ("Yeni Kart Tanımla") ve `personel_form.php`
(gömülü Kart Yönetimi modalı), her ikisi de artık iki giriş kanalı sunar:

```
[USB okuyucuya okutun]  ── kaynak=usb_decimal ──┐
                                                  ├──► pdks_kart_ata() / pdks_kart_degistir()
[📡 NFC İLE OKU butonu] ── kaynak=web_nfc ───────┘        (Faz 1B'nin AYNI fonksiyonları)
```

- Her iki sayfadaki `?ajax=onizle` önizleme ucu ve `action=kart_ata` /
  `action=kart_degistir` POST işleyicileri, `$_POST['kaynak']`'ı
  (`usb_decimal` | `web_nfc`) beyaz listeyle okur; boş/geçersizse
  güvenli varsayılan `usb_decimal`'dir.
- UID mantığı **hiçbir sayfada tekrarlanmaz** — ikisi de aynen Faz 1B'nin
  `pdks_kart_ata()`/`pdks_kart_degistir()` fonksiyonlarına çıkar, onlar da
  TEK yazma yolu `pdks_kart_olustur()`'a çıkar.
- **Doğrulama:** USB `631799511` ve Web NFC `d7:7e:a8:25`, İKİSİ DE aynı
  kanonik `25A87ED7`'ye çözülür — `scripts/pdks_db_smoke.php` §15 ve
  `scripts/pdks_giris_cikis_ui_smoke.php` bunu uçtan uca (kart atama +
  Giriş/Çıkış kaydı) kanıtlar.

### 5.1 `assets/pdks.js` — kaynak-farkında tarama kutusu

Her `[data-pdks-scan]` kutusu artık `data-pdks-kaynak-field` ile eşlenmiş
bir gizli `<input name="kaynak">` alanına sahiptir:

- **Gerçek** klavye/USB-HID `input` olayı → kaynak koşulsuz `usb_decimal`'e
  döner, yalnız rakam kabul edilir (davranış DEĞİŞMEDİ).
- **NFC okuması** → `[data-pdks-nfc-target]` butonu, hedef kutuya özel bir
  `pdksnfcread` DOM olayı **gönderir** (normal `input` olayını BİLEREK
  atlar — ham NFC değeri iki nokta/harf içerir, rakam-ayıklayıcı onu
  bozardı). Bu olay kaynağı `web_nfc`'ye çevirir ve ham değeri **olduğu
  gibi** kutuya yazar.
- NFC butonu bir kez tıklanınca oturum boyunca dinlemede kalır — her yeni
  kart taraması için tekrar tıklamak GEREKMEZ (teşhis sayfasında ölçülüp
  doğrulanmış davranışın aynısı, bkz. `PDKS_WEBNFC_DIAGNOSTIC.md`).

---

## 6. GİRİŞ / ÇIKIŞ SAYFASI (`giris_cikis.php`)

### 6.1 Akış

```
┌─────────────────┐     [GİRİŞ MODU] tıkla      ┌──────────────────────┐
│   Mod Seçimi     │ ──────────────────────────► │   Tarama Ekranı       │
│ [✅ GİRİŞ MODU]  │                              │  "KARTINIZI OKUTUN"   │
│ [🚪 ÇIKIŞ MODU]  │ ◄────────────────────────── │  (USB odaklı + NFC)   │
└─────────────────┘     [↩ Modu Değiştir]        └──────────┬────────────┘
                                                              │ her geçerli okuma
                                                              ▼
                                                   ┌──────────────────────┐
                                                   │  Sonuç (1.6-2 sn)     │
                                                   │  ✓ GİRİŞ KAYDEDİLDİ   │
                                                   │  ✕ KART TANIMLI DEĞİL │
                                                   └──────────┬────────────┘
                                                              │ otomatik
                                                              ▼
                                                     Tarama Ekranına döner
                                                     (AYNI mod korunur)
```

- **Yön İSTEMCİDE ASLA otomatik seçilmez/tahmin edilmez.** Kullanıcı
  ekranda GİRİŞ ya da ÇIKIŞ'ı **açıkça** seçer; seçim, kullanıcı "Modu
  Değiştir"e basana kadar SABİT kalır — art arda onlarca personel aynı
  modda okutulabilir.
- Sunucu tarafında da "son olaya göre" bir yön tahmini YOKTUR —
  `pdks_devam_kaydet()` her zaman istemcinin gönderdiği `event_type`'ı
  yazar, kendi başına yön DEĞİŞTİRMEZ.
- USB kutusu görsel olarak gizlidir ama **her zaman odaklıdır**
  (standart "erişilebilir gizleme" deseni — `display:none` DEĞİL, çünkü
  o odak ALAMAZ). Enter tuşu yakalanır ama okuyucunun Enter göndermesi
  ZORUNLU değildir — kısa bir yazma duraklamasından sonra da otomatik
  gönderilir.
- Web NFC, `NDEFReader` + `isSecureContext` destekleniyorsa **aynı
  ekranda** otomatik görünür — ayrı bir sistem/ekran YOKTUR.
- Başarı ekranı: yeşil, ✓, personel fotoğrafı (veya baş harf avatarı),
  ad-soyad, sicil no, departman, "GİRİŞ KAYDEDİLDİ"/"ÇIKIŞ KAYDEDİLDİ".
- Hata ekranı: kırmızı, ✕, sade Türkçe mesaj (§6.3).
- İkisi de birkaç saniye sonra otomatik olarak tarama ekranına döner —
  mod DEĞİŞMEZ.

### 6.2 Kayıt ucu — `giris_cikis.php?ajax=kaydet` (JSON, aynı dosya içinde)

```
İstemci  →  { csrf, ham_uid, kaynak, event_type }
Sunucu   →  csrf_check() → require_pdks('scan') (tekrar) → pdks_devam_kaydet()
         →  { ok:true,  event_type, employee:{full_name, personnel_no, department, photo_html} }
         →  { ok:false, kod, hata }
```

İstemci **hiçbir kimlik iddiasında bulunmaz** — `employee_id`/`card_id`
YOKTUR; kart/personel kimliği HER ZAMAN sunucuda, mevcut
`pdks_kart_cozumle()` ile çözülür (Faz 1'in TEK OTORİTE ilkesinin aynısı).

### 6.3 `pdks_devam_kaydet()` — hata kodları ve Türkçe mesajlar

| `kod` | Mesaj | Ne zaman |
|---|---|---|
| `kart_tanimsiz` | KART TANIMLI DEĞİL | Kart hiçbir `employee_card_uids` satırına çözülmüyor |
| `kart_iptal` | KART İPTAL EDİLMİŞ | Kart durumu `iptal` |
| `kart_kayip` | KART KAYIP | Kart durumu `kayip` |
| `kart_degistirildi` | KART DEĞİŞTİRİLMİŞ | Kart durumu `degistirildi` (eski, `pdks_kart_degistir()`'le değiştirilmiş kart) |
| `kart_suresi_doldu` | KARTIN SÜRESİ DOLMUŞ | Kart durumu `suresi_doldu` |
| `kart_pasif` | KART PASİF | Kart durumu `pasif` |
| `personel_pasif` | PERSONEL PASİF | Kart aktif ama personelin `status` alanı `aktif` değil |
| `mukerrer` | BU KART ZATEN AZ ÖNCE OKUTULDU | Aynı personel + aynı yön, `PDKS_COOLDOWN_SN` (20 sn) içinde |
| `gecersiz_yon` | Geçersiz giriş/çıkış yönü. | `event_type` `GIRIS`/`CIKIS` değil (mod seçilmemiş) |
| `gecersiz_kaynak` | Geçersiz okuma kaynağı. | `kaynak` üç bilinen değerden biri değil |

---

## 7. YENİ `attendance_events` TABLOSU

```sql
CREATE TABLE IF NOT EXISTS `attendance_events` (
    `id`                     INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id`            INT          NOT NULL,
    `card_id`                INT          NOT NULL,
    `event_type`             VARCHAR(10)  NOT NULL,   -- 'GIRIS' | 'CIKIS'
    `source`                 VARCHAR(20)  NOT NULL,   -- 'usb_decimal' | 'nfc_hex' | 'web_nfc'
    `canonical_uid_snapshot` VARCHAR(32)  NOT NULL,   -- o ANKİ kartın kanonik UID'si
    `recorded_by_user_id`    INT          NULL,       -- oturumdaki Nuverna kullanıcısı
    `server_event_time`      DATETIME     NOT NULL,   -- SUNUCU saati — istemciden ASLA alınmaz
    `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ...
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Bilerek eklenmemiş olanlar** (kullanıcının §16 kısıtlaması): cihaz
tablosu, Android/token tablosu, heartbeat, offline kuyruk, vardiya
motoru, puantaj/bordro tabloları. Bunların hiçbiri kod tabanına sızmadı
— `scripts/pdks_giris_cikis_static_smoke.php` §6 bunu otomatik doğrular.

`source` alanı, minimalizm ilkesine küçük bir istisnadır: teşhis/denetim
için hangi kanaldan okunduğunu (USB/NFC) kaydeder — Faz 1'in
`enrolled_source` (kart tablosunda) ile aynı gerekçe.

`FK` **yoktur** (Faz 1B'nin `employee_cards`/`employee_card_uids` deseninin
aksine) — bilerek: geçmiş bir olayın, ileride personel/kart satırı
silinse bile (bu sistemde zaten olmaz, kartlar silinmez) okunabilir
kalması için `canonical_uid_snapshot` zaten kendi başına yeterli bir iz
taşır.

---

## 8. MÜKERRER OKUMA / ÇİFT-TIKLAMA KORUMASI

- **Sunucu (tek otorite):** `pdks_devam_kaydet()`, aynı `employee_id` +
  aynı `event_type` için, `PDKS_COOLDOWN_SN` (20 sn, Faz 1'in kararı #5)
  içinde bir satır varsa reddeder (`kod: 'mukerrer'`). Kontrol **PHP'de
  hesaplanan bir zaman damgasıyla** yapılır (`NOW() - INTERVAL` gibi
  MySQL'e özgü SQL KULLANILMAZ) — bu yüzden bellek içi SQLite testinde de
  aynen çalışır (`hks_eslesme_yaz()`'daki taşınabilir upsert ile aynı
  gerekçe).
- **Farklı yön aynı anda mükerrer SAYILMAZ** — bir personel aynı saniyede
  GİRİŞ sonra ÇIKIŞ yazabilir (yanlışlıkla yanlış moddan okutulup hemen
  doğru moda geçilen senaryo).
- **Cooldown süresi geçince aynı yön TEKRAR kabul edilir** — yasal ikinci
  giriş/çıkış (ör. öğle arası) engellenmez.
- **İstemci tarafı ek katman:** `giris_cikis.php`'nin JS'i bir `busy`
  bayrağıyla aynı anda ikinci bir isteğin gitmesini engeller (çift
  tıklama/çift NFC okuma penceresi) — ama asıl garanti HER ZAMAN
  sunucudaki 20 saniyelik kontroldür; istemci tarafı yalnız gereksiz
  isteği önler.

---

## 9. YETKİ

Giriş/Çıkış sayfası, Faz 1'de zaten tanımlanmış `attendance.scan`
yetkisini kullanır (`require_pdks('scan')` → `can('attendance.scan')`).
Yeni bir yetki **eklenmedi**. Hatırlatma (Faz 1'den): `ik` rolü bilerek
`attendance.scan`'e sahip DEĞİLDİR — hangi rollerin bu yetkiye sahip
olacağına kullanıcı sonradan (`users.php`/`definitions.php` üzerinden)
karar verecek.

Sidebar: `config/helpers.php`'deki "Personel" bölüm görünürlüğü
(`$p_pdks`) artık `attendance.scan`'i de kapsayacak biçimde genişletildi
— aksi hâlde yalnız `attendance.scan`'e sahip (employees/cards YOK) bir
kullanıcı bölüm başlığını hiç göremezdi. Teşhis sayfası (`pdks_nfc_test.php`)
**birincil navigasyona eklenmedi** — yalnız `personel_kartlar.php`'deki
ikincil "🔬 Web NFC Testi" linki olarak kalır (admin/kart yönetimi
erişimi olanlar için bir tanılama aracı).

---

## 10. TESTLER

Sekiz betik, toplam **507 test**, hepsi geçiyor:

| Betik | Test sayısı | Ne kanıtlıyor |
|---|---:|---|
| `scripts/pdks_uid_smoke.php` | 73 | UID matematiği + §12 `web_nfc` dönüşümü (USB `631799511` ve Web NFC `d7:7e:a8:25`'in AYNI `25A87ED7`'ye çözüldüğü dahil) |
| `scripts/pdks_db_smoke.php` | 146 | Şema kısıtları + §15 `pdks_devam_kaydet()`: GİRİŞ/ÇIKIŞ yazımı, 6 kart durumu reddi, personel pasif reddi, 20sn mükerrer reddi + cooldown sonrası kabul, tanımsız kart, geçersiz yön, sunucu saati otoritesi |
| `scripts/pdks_faz1b_smoke.php` | 82 | Faz 1B personel/kart alan mantığı (regresyon) |
| `scripts/pdks_faz1b_ui_smoke.php` | 63 | Faz 1B sayfa render testi (regresyon) |
| `scripts/pdks_faz1b_static_smoke.php` | 44 | Statik kurallar — §5 güncellendi (attendance_events artık MEVCUT, ama Faz 1B sayfaları onu tekrarlamıyor) |
| `scripts/pdks_nfc_test_static_smoke.php` | 29 | Teşhis sayfası hâlâ hiçbir kayıt yazmıyor (regresyon) |
| `scripts/pdks_giris_cikis_static_smoke.php` | **39 (yeni)** | Yetki kapısı, CSRF, yön otomatik seçilmiyor, istemci kimlik iddiasında bulunmuyor, bayt-tersi istemcide YOK, aşırı mühendislik yasakları, navigasyon |
| `scripts/pdks_giris_cikis_ui_smoke.php` | **31 (yeni)** | Sayfa render + uçtan uca `ajax=kaydet`: GİRİŞ yazma, mükerrer reddi, tanımsız kart, **Web NFC ile aynı fiziksel kartın (USB ile atanmış) bulunması**, geçersiz kaynak/yön reddi, yetki kapısı |

Ayrıca ilgisiz regresyon paketleri de çalıştırıldı (§ Doğrulama Adımları,
final rapor).

---

## 11. WEB NFC — BİLİNEN TARAYICI GEREKSİNİMİ

- **Yalnız Android + Chrome** (`NDEFReader` başka hiçbir tarayıcıda/masaüstünde yok).
- **Yalnız HTTPS** (`window.isSecureContext` — `Web NFC` güvenli olmayan bağlamda çalışmaz).
- Desteklenmiyorsa: NFC butonu hiç GÖRÜNMEZ, sayfa sessizce USB-only'e
  düşer — hiçbir hata/çökme yoktur (`giris_cikis.php` ve `assets/pdks.js`
  her ikisi de `'NDEFReader' in window && window.isSecureContext` ile
  önce özellik algılar).
- İzin **yalnız** kullanıcı etkileşimi (buton tıklaması) içinde istenir —
  sayfa açılışında/otomatik istenmez (tarayıcı zaten izin vermez, ama
  UX açısından da doğru davranış budur).
- Bir kez "dinlemede" başlayınca (`ndef.scan()`), oturum boyunca yeniden
  tıklamaya gerek kalmadan art arda kartlar okunabilir — bu, teşhis
  sayfasında ölçülüp doğrulanmış gerçek davranıştır.

---

## 12. GEÇMİŞ, KİMSE KAYBOLMAZ — "DEĞİŞTİRİLDİ" DURUMUNUN GİRİŞ/ÇIKIŞA ETKİSİ

Faz 1B'nin kart değiştirme akışı (`pdks_kart_degistir()`) eski kartı asla
silmez, yalnız `degistirildi` işaretler ve `replacement_card_id` ile yeni
karta bağlar. Giriş/Çıkış açısından anlamı: **eski (fiziksel olarak artık
geçersiz) kart okutulursa** `pdks_devam_kaydet()` bunu `kart_degistirildi`
(`KART DEĞİŞTİRİLMİŞ`) olarak reddeder — sessizce "tanımsız kart" değil,
açıkça "bu kart artık geçerli değil, personelin YENİ kartı var" bilgisini
verir. `scripts/pdks_db_smoke.php` §15 bu senaryoyu uçtan uca (değiştir →
eski kartla okut → reddedilir → yeni kartla okut → kabul edilir) test eder.
