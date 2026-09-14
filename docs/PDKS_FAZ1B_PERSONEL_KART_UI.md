# PDKS FAZ 1B — PERSONEL / KART YÖNETİMİ ARAYÜZÜ

**Durum:** Faz 1B tamamlandı · **Faz 2 BAŞLATILMADI** · Canlıya alınmadı
**Tarih:** 2026-09-14 · **Branch:** `claude/nfc-attendance-roadmap-z14alg`
**Üst belgeler:** `PDKS_NFC_YOL_HARITASI.md` (mimari) · `PDKS_NFC_FAZ0_DOGRULAMA.md` (ölçümler) ·
`PDKS_FAZ1_SEMA.md` (şema + UID sözleşmesi + §6a düzeltmesi)

> **Bu fazda yapılmayanlar:** giriş/çıkış hareket motoru (`attendance_events`) ·
> API (`api_pdks.php`, Faz 2) · Android üretim uygulaması · cihaz kaydı
> (`attendance_devices`) · vardiya/puantaj/izin. **Bunların hiçbiri kod
> tabanına sızmadı — `scripts/pdks_faz1b_static_smoke.php` §5 bunu otomatik
> doğruluyor.** Canlı veritabanında hiçbir şey çalıştırılmadı, deploy yok.

---

## 1. ÖZET

Faz 1B, onaylanan Faz 1 şeması üzerine **kullanılabilir bir yönetim arayüzü**
kurar: personel kartoteksi (liste/oluştur/düzenle), fotoğraf yükleme, USB
kart tanımlama ve tam kart yaşam döngüsü (ata/iptal/kayıp/değiştir) —
mevcut uygulamanın kimlik doğrulama, yetki, tasarım ve veritabanı
konvansiyonlarını **birebir** kullanarak.

| Ölçüt | Sonuç |
|---|---|
| Yeni sayfa | 4 (`personel.php`, `personel_form.php`, `personel_kartlar.php`, `personel_foto.php`) |
| Yeni modül CSS/JS | `assets/pdks.css` + `assets/pdks.js` (maliyet/hesap emsali — tek-CSS kuralının bilinçli istisnası) |
| Mevcut tabloya `ALTER` | **0** — Faz 1 şeması aynen kullanıldı |
| Yeni tablo | **0** |
| `assets/style.css` / `assets/app.js` / `sw.js` değişikliği | **0** |
| `config/db.php` / `config/auth.php` değişikliği | **0** |
| `config/helpers.php` / `index.php` değişikliği | Yalnız **ekleme** (nav bağlama) — hiçbir satır silinmedi |
| Otomatik test | **350 / 350 geçti** (5 PDKS betiği) |
| Mevcut takım regresyonu | Yok (`test_material_stock_helpers` hâlâ **önceden var olan** tek hatayla) |

---

## 2. DOSYALAR

| Dosya | Durum | Ne |
|---|---|---|
| `personel.php` | **YENİ** | Personel listesi — arama, durum/departman filtresi, sayfalama |
| `personel_form.php` | **YENİ** | Oluştur + Düzenle (tek dosya, `hesap_kayit.php` deseni) + gömülü "Kart Yönetimi" |
| `personel_kartlar.php` | **YENİ** | Genel kart listesi + "kart-önce" USB tanımlama akışı |
| `personel_foto.php` | **YENİ** | Fotoğraf servis ucu (yetki + DB doğrulamalı) |
| `assets/pdks.css` | **YENİ** | Modül CSS'i — yalnız `var(--*)` token'ları, personel_\*.php'de yüklenir |
| `assets/pdks.js` | **YENİ** | Modal aç/kapa + USB tarama girişi + kart eylem modalı |
| `config/pdks.php` | değişti | **Yalnız ekleme:** personel CRUD + kart yaşam döngüsü sarmalayıcıları + fotoğraf işleme (§4-§6) |
| `config/helpers.php` | değişti | **Yalnız ekleme:** sidebar'a "Personel" grubu (2 link) |
| `index.php` | değişti | **Yalnız ekleme:** ana sayfaya "Personel" kartı |
| `scripts/pdks_faz1b_smoke.php` | **YENİ** | Alan mantığı testi — 82 test |
| `scripts/pdks_faz1b_ui_smoke.php` | **YENİ** | Render testi — 63 test |
| `scripts/pdks_faz1b_static_smoke.php` | **YENİ** | Statik kural testi — 44 test |

**Dokunulmayanlar:** `assets/style.css` · `assets/app.js` · `sw.js` ·
`config/db.php` · `config/auth.php` · `users.php` · tüm diğer uygulama sayfaları ·
Faz 1'in şema/UID fonksiyonları (`pdks_kart_olustur`, `pdks_kart_cozumle`,
`pdks_uid_*`, `pdks_tablolar`, `pdks_migrate` — **tek satır değişmedi**).

---

## 3. SAYFALAR

### 3.1 `personel.php` — Liste

- Arama (ad/sicil/görev), durum filtresi (`aktif`/`pasif`/`ayrildi`), departman
  filtresi (mevcut kayıtlardan türetilir — yeni bir tanım türü **açılmadı**,
  Faz 1'in "arayüz olmadan definitions.php'ye dokunma" kararı korundu).
- Depo kapsaması `depo_sql_in('e.depo')` ile — mevcut çok-depo mimarisiyle tutarlı.
- Sayfalama `hesap_liste.php` ile aynı desen (`LIMIT 50`, `?sayfa=`).
- Masaüstü: `.table-wrap.pc-only` + `.data-table`. Mobil: `.pdks-cards.mobile-only`
  (kart listesi, `users.php`'deki `.usr-cards` desenine benzer).
- Her satırda: avatar (fotoğraf veya baş harf), sicil, ad, departman, görev,
  durum rozeti, **aktif kart UID'si veya "Kart yok"**, bağlı kullanıcı hesabı.

### 3.2 `personel_form.php` — Oluştur / Düzenle + Kart Yönetimi

Tek dosya (`?id=` yoksa oluşturma, varsa düzenleme — `hesap_kayit.php` deseni).

**Personel alanları:** ad soyad (zorunlu), sicil no, departman (datalist önerili
serbest metin), görev, durum, depo, telefon, **bağlı kullanıcı hesabı** (yalnız
başka bir personele bağlı olmayan aktif kullanıcılar listelenir), işe giriş/ayrılış
tarihi, not, fotoğraf.

**"KART YÖNETİMİ" bölümü** (yalnız mevcut personel, `id > 0`):

- Aktif kart varsa: kanonik UID (büyük, monospace), tanımlanma tarihi, etiket,
  **Değiştir / İptal Et / Kayıp Bildir** butonları.
- Aktif kart yoksa: "**+ Kart Ata**" butonu (USB tarama modalı açar).
- **Kart Geçmişi tablosu**: personelin sahip olduğu **her** kart (aktif +
  iptal + kayıp + değiştirilmiş), en yeni önce. Hiçbir satır silinmez —
  Faz 1B §5, §12'deki "geçmiş kaybolmaz" şartının arayüz karşılığı.

Tüm kart eylemleri **tek paylaşılan modal**(`#pdksKartModal`) üzerinden akar;
JS (`pdksKartModalAc(action, cardId, employeeId, kartUid)`) eyleme göre
UID-tarama bloğunu veya gerekçe kutusunu gösterir/gizler.

**Silme:** Yalnız **hiç kart geçmişi olmayan** personel için "Sil" butonu
görünür (§5). Kart geçmişi varsa (aktif VEYA iptal edilmiş fark etmez) silme
tamamen engellenir — sunucu tarafında da tekrar kontrol edilir, yalnız
buton gizlenmez. Silinen personelin `employee_cards` FK'sı zaten
`ON DELETE CASCADE` olduğu için (Faz 1), bu kural **kart geçmişini asla
kaybetmeme** garantisinin gerçek uygulayıcısıdır.

### 3.3 `personel_kartlar.php` — Genel Kart Yönetimi

İki iş yapar:

1. **"Kart-önce" tanımlama akışı** — güvenlik masasının gerçek iş akışına
   uyar: kart önce USB'ye okutulur, sunucu kanoniği ve boşta olup olmadığını
   gösterir, **sonra** personel seçilip atanır. Yalnız **aktif kartı
   olmayan aktif personel** listede görünür (zaten atanmış birini seçme
   hatası yapısal olarak engellenir).
2. **Tüm kartların listesi** — UID/personel arama, durum filtresi. İptal
   edilmiş/kayıp kartlar da listede kalır (geçmiş kaybolmuyor).

### 3.4 `personel_foto.php` — Fotoğraf Servisi

`hesap_dosya.php`'nin "B5" kuralının aynısı: dosya adını bilmek yetmez, bir
`employees` satırının **gerçekten** o dosyayı işaret ettiği DB'den doğrulanır.

---

## 4. USB KART TANIMLAMA AKIŞI

```
[KARTI USB OKUYUCUYA OKUTUN]
        ↓ (okuyucu klavye gibi yazar: "631799511")
   giriş kutusu yalnız RAKAM kabul eder (data-pdks-scan)
        ↓ (250ms debounce, Enter formu GÖNDERMEZ)
   fetch → ?ajax=onizle&kaynak=usb_decimal&uid=631799511   (salt okunur, yazmaz)
        ↓
   sunucu: pdks_uid_from_decimal() → "25A87ED7"
        ↓
   "Algılanan Kart UID: 25A87ED7" + "✓ Boşta" / "⚠ Zaten tanımlı — Ahmet Yılmaz"
        ↓
   [PERSONELE TANIMLA]  →  POST ham_uid=631799511, kaynak SABİT usb_decimal
        ↓
   sunucu pdks_kart_ata() → pdks_uid_from_decimal() TEKRAR çalışır (önizleme
   GÜVENİLMEZ, yalnız gösterimdir) → kayıt
```

**§3'ün tüm gereksinimleri karşılandı:**

| Gereksinim | Nasıl |
|---|---|
| Kaynak açıkça `usb_decimal` | Sabit değer, formda gizli/sabit; asla `'nfc_hex'`/tahmin yok |
| Otomatik tespit yok | `pdks_kart_ata()` her zaman `'usb_decimal'` ile çağrılır bu sayfalarda |
| İstemci UID otoritesi değil | Önizleme yalnız **gösterim**; `ham_uid` sunucuya HAM gider, kanonikleştirme submit anında **tekrar** yapılır (bkz. §5) |
| Sunucu tarafı dönüşüm | `pdks_uid_from_decimal()` — Faz 1, değişmedi |
| Mükerrer tespiti | `pdks_kart_ata()` → `pdks_kart_olustur()` → `uid_kullanimda` |
| Net mükerrer/geçersiz hata mesajı | `$hata` flash'a yazılır, Türkçe, kod + mesaj |
| Odak korunur | `input.focus()` sayfa/modal açılışında; okuma sonrası formdan **çıkılmaz** (PRG redirect sonrası `autofocus` yeniden devrede) |
| Enter engellenir | `keydown` → `preventDefault()`, yalnız önizlemeyi tetikler |
| Enter yoksa da çalışır | `input` olayı (debounce'lu) zaten her tuş vuruşunda önizler; submit **butona tıklamayla** olur, Enter'a bağımlı değil |

---

## 5. UID GÜVENLİĞİ — DÜZELTİLMİŞ MODEL KORUNDU

Faz 1B, Faz 1'in düzeltilmiş UID modelini (`PDKS_FAZ1_SEMA.md` §6a) **hiçbir
yerde yeniden bozmadı.** Kanıtlar:

- `pdks_kart_ata()` / `pdks_kart_degistir()` kendi UID mantığını **yazmaz**,
  ikisi de `pdks_kart_olustur()`'u çağırır — otomatik ters-alias **yok**.
- `personel.php` / `personel_form.php` / `personel_kartlar.php` içinde
  `hexdec()`/`dechex()` **yok**, `employee_card_uids`'e doğrudan `INSERT`
  **yok** — tüm sayfalar `config/pdks.php`'nin fonksiyonlarını çağırır.
- `scripts/pdks_faz1b_static_smoke.php` §6-§7 bunu **otomatik** doğrular
  (regex ile "ikinci bir UID yazma yolu açılmadı" kanıtlanır).
- `scripts/pdks_faz1b_smoke.php` §11, Kart A (`25A87ED7`) ve Kart B
  (`D77EA825`, Kart A'nın bayt-tersi) `pdks_kart_ata()` **üzerinden**
  aynı anda, iki ayrı kart olarak atanabildiğini uçtan uca kanıtlıyor.

---

## 6. KART YAŞAM DÖNGÜSÜ

Yeni sarmalayıcı fonksiyonlar (`config/pdks.php`, Faz 1'in ham fonksiyonlarını
**değiştirmez**, üzerine iş kuralı ekler):

| Fonksiyon | İş kuralı | Sardığı Faz 1 fonksiyonu |
|---|---|---|
| `pdks_kart_ata()` | Personelin **zaten aktif kartı varsa reddeder** (`zaten_aktif_kart_var`) — şema bunu UNIQUE ile zorlamaz, kural burada | `pdks_kart_olustur()` |
| `pdks_kart_durum_degistir()` | Gerekçeyi **sunucu tarafında da** zorunlu kılar; `card_revoked`/`card_lost` adlandırılmış audit'i ekler | `pdks_kart_iptal()` |
| `pdks_kart_degistir()` | **TEK işlemde**: yeni kart yazılır → başarılıysa eski kart `degistirildi` + `replacement_card_id` bağlanır. Yeni kart **başarısız olursa eski karta hiç dokunulmaz** (test 16) | `pdks_kart_olustur()` |

**"Yalnız bir aktif kart" kuralı DB kısıtı değil, uygulama kuralıdır** — şema
(Faz 1) bilerek bunu zorlamıyor (bir personelin iki satırı olabilir, biri
aktif diğer geçmiş). Kural `pdks_kart_ata()`'da uygulanıyor ve testle
kanıtlı (§9).

**Hiçbir kart satırı SİLİNMEZ.** İptal/kayıp/değiştirme hepsi `UPDATE`;
personel silinirse bile (yalnız kart geçmişi YOKSA silinebiliyor zaten)
`ON DELETE CASCADE` yalnız o özel durumda devreye girer.

---

## 7. FOTOĞRAF YÖNETİMİ

| Adım | Davranış |
|---|---|
| Doğrulama | `pdks_foto_gecerli_mi()` — boyut ≤ 5 MB, `getimagesize()` gerçek görsel mi, `finfo` MIME kontrolü (yalnız jpeg/png/webp) |
| **Güvenlik** | Dosya **GD ile yeniden kodlanır** (piksel verisi yeniden çizilir, `imagejpeg()` ile yazılır) — orijinal bayt akışı **asla diske yazılmaz**. Kötü amaçlı EXIF/polyglot dosya bu adımda düşer. `hesap_upload_file()`'dan daha sıkı bir garanti (o ham baytı saklıyor) |
| Dosya adı | `bin2hex(random_bytes(16)) . '.jpg'` — kullanıcı girdisinden **tamamen bağımsız**, path traversal yapısal olarak imkânsız |
| Boyut | Uzun kenar en fazla 640px'e küçültülür (`PDKS_FOTO_MAX_KENAR`) |
| GD yoksa | Yükleme **reddedilir** (`gd_yok`) — ham bayt asla kabul edilmez, sessiz düşme yok |
| Eski fotoğraf temizliği | Yalnız **yeni fotoğraf başarıyla kaydedildikten SONRA** silinir — yükleme başarısız olursa eski fotoğraf **dokunulmadan** kalır |
| Kaldırma | "Fotoğrafı kaldır" onay kutusu → `photo_file`/`photo_updated_at` NULL, dosya silinir |
| Fallback | Fotoğraf yoksa ad-soyaddan baş harflerle yuvarlak avatar (`pdks_avatar_html()`) — hiçbir zaman kırık resim ikonu |
| Servis | `personel_foto.php` — DB doğrulamalı, `Cache-Control: private` |

---

## 8. DOĞRULAMA (Sunucu Tarafı)

| Alan | Kural |
|---|---|
| Ad soyad | Zorunlu, boşsa reddedilir |
| Sicil no | UNIQUE (kendisi hariç düzenlemede), boşsa serbest (NULL çoklu) |
| Kullanıcı hesabı | Var olmalı VE başka bir personele bağlı olmamalı |
| Durum | `pdks_personel_durumlari()` anahtarlarından biri olmalı |
| Kart UID | Kaynak zorunlu bildirilir, sunucu **her zaman** yeniden kanonikleştirir |
| Kart çakışması | Tam kanonik eşleşme — Faz 1 §6a modeli |
| Personel varlığı | Her kart işleminde `employees` tablosunda kontrol edilir |
| Yetki | Her POST dalında **iki kez**: sayfa girişinde (`require_pdks`) + eylem dalında (`pdks_can()`) |
| CSRF | Her POST dalında, **action switch'inden ÖNCE** — hiçbir dal korumasız kalmaz (`scripts/pdks_faz1b_static_smoke.php` §3 bunu doğruluyor) |

POST değerleri **asla körü körüne** güvenilmiyor — tüm SQL prepared
statement (`PDO::prepare`), tüm alanlar `trim()`/`mb_substr()` ile kırpılıyor
(reddetmek yerine kırpma — repo konvansiyonu, bkz. `clamp_loading_record_fields`).

---

## 9. YETKİLER

Faz 1'de zaten seed edilmiş yetkiler **aynen** kullanıldı — yeni yetki
**eklenmedi**:

| Sayfa/Eylem | Gereken yetki |
|---|---|
| `personel.php`, personel alanları görüntüleme/düzenleme | `attendance.employees` |
| Silme | `attendance.employees` (+ kart geçmişi yok kuralı) |
| `personel_kartlar.php`, tüm kart eylemleri (ata/iptal/kayıp/değiştir) | `attendance.cards` |
| Fotoğraf görüntüleme | `attendance.employees` |

`ik` rolü (Faz 1'de oluşturuldu) her ikisine de sahip. `admin` her zaman
geçer. **`guvenlik` rolü Faz 2'ye kadar açılmadı** — bu sayfalara hiçbir
rolün "geniş personel erişimi" kazanmaması gerektiği kuralı (§6) böylece
yapısal olarak korunuyor: kapı cihazının yetkisi (`attendance.scan`)
`employees`/`cards`'tan tamamen ayrı bir yetkidir ve henüz kimseye
verilmedi.

---

## 10. AUDIT

| Olay | Ne zaman | Kim yazıyor |
|---|---|---|
| `employee_created` | Yeni personel | `pdks_personel_olustur()` |
| `employee_updated` | Her düzenleme | `pdks_personel_guncelle()` |
| `employee_status_changed` | **Yalnız durum gerçekten değiştiyse** (ayrı olay — genel güncellemenin içinde kaybolmasın diye) | `pdks_personel_guncelle()` |
| `employee_deleted` | Silme (yalnız kart geçmişi yoksa mümkün) | `personel_form.php` |
| `employee_photo_updated` | Fotoğraf değiştirildi | `personel_form.php` |
| `card_assigned` | Kart atandı | `pdks_kart_ata()` |
| `card_revoked` | İptal edildi | `pdks_kart_durum_degistir()` |
| `card_lost` | Kayıp bildirildi | `pdks_kart_durum_degistir()` |
| `card_replaced` | Değiştirildi | `pdks_kart_degistir()` |

Ayrıca Faz 1'in kendi `card_create` / `card_revoke` olayları da (ham
fonksiyonlardan) yazılmaya devam eder — bilinçli fazlalık, geriye dönük
uyumluluk (Faz 1 testleri bu isimleri arıyor).

**Hassas veri yazılmaz:** telefon numarası, notlar gibi kişisel alanlar
audit'e girer ama `_audit_sanitize()` (mevcut, değişmedi) şifre/token/foto
verisini zaten filtreliyor; fotoğraf **dosya adı** yazılır, fotoğrafın
kendisi (base64/binary) hiçbir zaman audit'e girmez.

---

## 11. TESTLER — 350 / 350

```
$ php scripts/pdks_uid_smoke.php             →  63 test  (Faz 1 — REGRESYON)
$ php scripts/pdks_db_smoke.php              →  98 test  (Faz 1 — REGRESYON)
$ php scripts/pdks_faz1b_smoke.php           →  82 test  (Faz 1B — alan mantığı)
$ php scripts/pdks_faz1b_ui_smoke.php        →  63 test  (Faz 1B — render)
$ php scripts/pdks_faz1b_static_smoke.php    →  44 test  (Faz 1B — statik kurallar)
                                                 ──────────────────────────
                                                 350 / 350
```

`pdks_faz1b_smoke.php`'nin son adımı (§19) Faz 1'in iki testini **alt süreç
olarak çalıştırıp** sonuçlarını doğrular — yani Faz 1B'nin kendi test
takımı, Faz 1'i regresyona karşı **otomatik** kapsar.

### İstenen test listesi ↔ nerede

| İstenen | Nerede | Not |
|---|---|---|
| Personel oluşturma | `faz1b` §1 | |
| Mükerrer sicil no | `faz1b` §3 | |
| Opsiyonel `user_id` | `faz1b` §4 | |
| Mükerrer `user_id` reddi | `faz1b` §5 | + olmayan user_id de reddedilir |
| Personel düzenleme | `faz1b` §6 | Kendi sicilini koruyabilir, başkasınınkini alamaz |
| Pasif personel | `faz1b` §7 | + `employee_status_changed` yalnız gerçek değişimde |
| Fotoğraf doğrulaması | `faz1b` §17, `faz1b_ui` son bölüm | **Gerçek** GD-üretimi JPEG ile, sahte/PHP dosyasıyla da |
| USB kart atama | `faz1b` §8 | |
| `631799511 → 25A87ED7` | `faz1b` §8 | + `pdks_uid_smoke` (Faz 1, regresyon) |
| Mükerrer kart reddi | `faz1b` §9, §10 | Aynı personel VE başka personel |
| Kart A / Kart B bir arada | `faz1b` §11 | Uçtan uca `pdks_kart_ata()` ile |
| Kart iptali | `faz1b` §13 | + gerekçesiz reddi + hâlâ çözülebilirliği |
| Kayıp bildirimi | `faz1b` §14 | |
| Kart değiştirme | `faz1b` §15 | Eski korunur, geçmişte görünür |
| Kart geçmişi korunuyor | `faz1b` §15, `faz1b_ui` (Personel C senaryosu) | |
| Yetki reddi | `faz1b_ui` "YETKİ KAPISI" | **Gerçek sayfa render'ında** — stub değil |
| CSRF koruması | `faz1b_static` §3 | Statik: her POST dalı + action switch'inden önce |
| Geçersiz personel ID | `faz1b` §6, §12 | `personel_yok` kodu |
| Geçersiz UID girdisi | `faz1b` §12 | Kartsız taze personelle (bkz. not aşağıda) |

> **Not (§12 test tasarımı):** "geçersiz UID" testi özellikle **kartı olmayan**
> bir personelle yapılır — `pdks_kart_ata()` önce "zaten aktif kartı var mı"
> kontrolünü yapar, UID'e sonra bakar; zaten kartlı biriyle test edilseydi
> yanlış pozitif (`zaten_aktif_kart_var`) alınırdı. Bu, testin bir hatasıydı,
> `config/pdks.php`'nin değil — düzeltildi.

---

## 12. YOL HARİTASINDAN / TALİMATTAN SAPMALAR

| # | Sapma | Gerekçe |
|---|---|---|
| 1 | Departman/görev **serbest metin** (definitions.php'ye entegre değil) | Faz 1'in kendi kararı (§9-4) korundu: tanım türü eklemek Tanımlar ekranında kullanıcıya görünen bir değişiklik olurdu. Datalist önerisiyle UX kaybı en aza indirildi |
| 2 | Bottomnav'a **eklenmedi** | Talimat "keep it minimal" diyor; Faz 1 yol haritası da bunu bilerek dışarıda bırakmıştı (5 sekme dolu). Sidebar + ana sayfa kartı yeterli erişim sağlıyor |
| 3 | "Kart Yönetimi" **hem** genel sayfa (`personel_kartlar.php`) **hem** personel içi gömülü bölüm | Talimat madde 7'de ikisini de first-level nav item olarak öneriyordu; madde 2E personel detayında da istiyordu. İkisi AYNI `config/pdks.php` fonksiyonlarını çağırır — UID mantığı ikilenmedi |
| 4 | Personel silme **eklendi** (talimat "if delete exists at all…" diyordu — opsiyonel) | En güvenli hâliyle eklendi: yalnız hiç kart geçmişi olmayan personel için, iki kat sunucu kontrolü ile. Birincil yol yine "Ayrıldı" durumu |
| 5 | Fotoğraf **her zaman JPEG'e yeniden kodlanıyor** (orijinal format korunmuyor) | Talimat "no executable uploads" diyordu; GD yeniden kodlama bunu `hesap_upload_file()`'dan daha güçlü garanti ediyor. Bedel: PNG şeffaflığı beyaza düzleşiyor (personel fotoğrafı için önemsiz) |
| 6 | `attendance_gates` yönetim ekranı **eklenmedi** | Faz 1'de tablo açıldı ama talimat kapsamı yalnız personel+kart; kapı yönetimi Faz 2'nin cihaz kaydıyla birlikte anlamlı |

---

## 13. RİSKLER

| # | Risk | Durum |
|---|---|---|
| 1 | `test_material_stock_helpers` başarısız | **ÖNCEDEN VAR OLAN** (base commit'te de aynı) — Faz 1B kaynaklı değil |
| 2 | DDL yetkisi hâlâ ölçülmedi (Faz 0 Ölçüm 2) | Faz 1B yeni tablo açmıyor, bu riski **büyütmüyor**. Faz 1'in tabloları kurulu değilse sayfalar `try/catch` ile düzgün bir Türkçe uyarı gösteriyor (migrate.php'ye yönlendirme) |
| 3 | GD kurulu değilse fotoğraf yükleme tamamen kapanır | **Bilinçli** — ham bayt saklamaktansa özelliği kapatmak tercih edildi. Bu ortamda GD mevcut ve test edildi |
| 4 | "Yalnız bir aktif kart" kuralı DB kısıtı değil | Test edildi (§9) ama gelecekte `pdks_kart_ata()` dışında bir yazma yolu açılırsa kural atlanabilir — yorum satırlarında ve bu belgede açıkça uyarıldı |

---

## 14. MANUEL TEST KONTROL LİSTESİ (canlıya alınırsa)

- [ ] `migrate.php` → PDKS tabloları kurulu (Faz 1 onaylıysa zaten yapılmış olmalı)
- [ ] Sidebar'da "Personel" grubu **yalnız** `ik` rolü/admin için görünüyor
- [ ] Ana sayfa "Personel" kartı doğru rol için görünüyor
- [ ] Yeni personel oluştur → fotoğraf yükle → listede görünüyor
- [ ] Aynı sicil no ile ikinci personel → hata mesajı
- [ ] USB okuyucu ile gerçek kart okut → `personel_kartlar.php` önizlemesi kanoniği doğru gösteriyor
- [ ] Aynı kartı ikinci kez tanımlamaya çalış → "zaten tanımlı" uyarısı
- [ ] Kart iptal et (gerekçe gir) → geçmişte görünüyor, personelin aktif kartı yok
- [ ] Kartı değiştir → eski kart geçmişte "Değiştirildi", yeni kart aktif
- [ ] Kart geçmişi olan personeli silmeye çalış → engellenir
- [ ] Hiç kart görmemiş bir test personelini sil → başarılı
- [ ] Koyu tema açıkken tüm sayfaları gez — sabit renk/okunamayan metin yok
- [ ] Telefonda `personel.php` ve USB tanımlama ekranını gez — mobil kart görünümü doğru
- [ ] `audit.php`'den yeni olayların (employee_\*, card_\*) göründüğünü doğrula

---

## 15. FAZ 1B KABUL ÖLÇÜTLERİ

| Ölçüt | Durum |
|---|---|
| Mevcut kimlik doğrulama/yetki/CSS/nav kullanıldı, yeni çerçeve yok | ✅ |
| UID mantığı hiçbir yerde tekrarlanmadı, §6a düzeltmesi korundu | ✅ (statik test + uçtan uca test) |
| Personel CRUD, sunucu tarafı doğrulama | ✅ |
| Fotoğraf: doğrulama + güvenli ad + yeniden kodlama + fallback | ✅ |
| USB tanımlama: kaynak açık, istemci otoritesi yok, odak/Enter doğru | ✅ |
| Kart yaşam döngüsü: ata/iptal/kayıp/değiştir, geçmiş korunuyor | ✅ |
| Yetkiler sunucu tarafında, her dalda tekrar kontrol | ✅ |
| Audit olayları adlandırıldı | ✅ |
| Attendance event / Android üretim kodu YOK | ✅ (statik test) |
| Testler + geçiş | ✅ **350/350** |
| Belgeleme | ✅ (bu belge) |

**Faz 1B tamamlandı. Faz 2 BAŞLATILMADI. Onayınızı bekliyorum.**
