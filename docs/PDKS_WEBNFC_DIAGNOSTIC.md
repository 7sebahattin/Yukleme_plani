# PDKS — WEB NFC TEŞHİSİ (Mimari Düzeltme: Tek Web Uygulaması)

**Durum:** Teşhis sayfası hazır, **canlıda test EDİLMEDİ** — sizin telefonunuzla
ölçülmesi gerekiyor · Faz 2 (kart entegrasyonu/giriş-çıkış) **BAŞLATILMADI**
**Tarih:** 2026-09-14 · **Branch:** `claude/nfc-attendance-roadmap-z14alg`

> **Bu adımda yapılmayanlar:** kart yönetimine Web NFC entegrasyonu ·
> Giriş/Çıkış sayfası · `attendance_events` tablosu · herhangi bir yeni
> yetki · canlıya deploy. Talimatınızdaki "STOP CONDITION" gereği yalnız
> **teşhis sayfası** kuruldu; sonuç ölçülmeden ilerlenmedi.

---

## 1. NEDEN BU DÜZELTME GEREKTİ

Önceki yol haritası (`PDKS_NFC_YOL_HARITASI.md` §8), güvenlik kapısındaki
telefon için **ayrı bir native Android uygulaması** (Kotlin, APK, cihaz
token'ı, heartbeat) öneriyordu. Bu, Web NFC'nin "kartlar NDEF değil,
güvenilmez olabilir" **varsayımına** dayanıyordu — hiç ölçülmemişti.

**Siz bu kararı iptal ettiniz.** Yeni gereksinim netleşti:

> **Tek web uygulaması.** `nuverna.derspros.com.tr` — hem masaüstünde USB
> okuyucudan hem Android telefonda **tarayıcının kendi Web NFC API'siyle**
> aynı sayfadan kart okusun. Ayrı APK yok, ayrı kurulum yok, ayrı kimlik
> doğrulama yok. USB'nin `usb_decimal`, Web NFC'nin `web_nfc` kaynağı
> olarak **aynı** sunucu fonksiyonlarına, **aynı** veritabanına çıkması.

Bu, Faz 0'daki "önce ölç, sonra tasarla" ilkesinin **aynısının** tarayıcı
API'sine uygulanmasıdır — orada Android `getId()`'nin bayt sırası
ölçülmeden kanon sabitlenmemişti; burada Web NFC'nin bu kartlarla
**gerçekten** çalışıp çalışmadığı ölçülmeden mimari kurulmuyor.

---

## 2. TEKNİK ARKA PLAN — Web NFC (`NDEFReader`) gerçekte nedir

Aşağıdakiler **bilinen platform gerçekleridir** (spekülasyon değil), teşhis
sayfasını doğru yorumlamak için önemli:

| Gerçek | Anlamı |
|---|---|
| **Yalnız Android + Chrome** (89+) | Masaüstü Chrome'da, Firefox'ta, Safari'de, iOS'ta (hiçbir tarayıcıda) **çalışmaz**. "Android telefonda Chrome" dışındaki her kombinasyon `'NDEFReader' in window` → `false` döner |
| **Yalnız HTTPS (güvenli bağlam)** | `window.isSecureContext` false ise API hiç yok sayılır |
| **İzin yalnız kullanıcı etkileşiminde** | `ndef.scan()` bir tıklama işleyicisi İÇİNDE çağrılmalı; sayfa yüklenirken otomatik istenemez (teşhis sayfası buna uyuyor) |
| **API'nin adı "NDEF Reader"** | Tasarım gereği NDEF (NFC Data Exchange Format) mesajlarını okumak için var. `serialNumber` alanı **teknoloji düzeyinde** (anticollision UID) geldiği için NDEF içeriği olmayan/boş bir kartta da genelde dolabilir — **ama bu, tüm Chrome sürümü + Android + NFC çipset kombinasyonlarında garanti değildir.** Bazı donanımlarda Chrome, hiç NDEF mesajı bulamayan bir etiket için `reading` olayını hiç tetiklemeyebilir veya `NotSupportedError`/`NetworkError` verebilir |
| **Kartlarınız** | Yol haritası §3'e göre MIFARE Classic / NfcA / **NdefFormatable**. "NdefFormatable" = kart **şu an** NDEF formatlı değil ama formatlanabilir. Yani kartların bugünkü hâli muhtemelen **boş/NDEF'siz** — yukarıdaki belirsizliğin tam ortasında |

**Sonuç: Bu tablo, sonucun "evet" ya da "hayır" olacağını söylemiyor —
neden ÖLÇÜLMESİ gerektiğini açıklıyor.** Üçüncü parti bir "NFC Tools" tipi
uygulamanın kartı okuyabilmesi de Web NFC'nin okuyacağının garantisi
değildir (o uygulamalar genelde ham teknoloji API'lerini kullanır, Chrome'un
NDEF-merkezli API'si değil).

---

## 3. YAPILAN — `pdks_nfc_test.php`

Mevcut Nuverna oturumu/yetkisiyle açılan, **hiçbir kayıt yazmayan** bir
teşhis sayfası. `personel_kartlar.php`'den "🔬 Web NFC Testi" bağlantısıyla
erişilir.

### Ne gösteriyor

| Bölüm | İçerik |
|---|---|
| 1) Ortam Bilgisi | Sunucu tarafı HTTPS · `isSecureContext` · `'NDEFReader' in window` · User-Agent |
| 2) Bilinen Test Kartı | USB'nin verdiği `631799511` → kanonik `25A87ED7`, karşılaştırma için |
| 3) Okuma Testi | **[📡 NFC OKUMAYI BAŞLAT]** butonu → izin İSTER → dinler → okunan `serialNumber`'ı **ham hâliyle**, ayraçsız büyük harfle, "aynı sıra" ve "ters sıra" olası kanonikleriyle, bilinen kartla eşleşip eşleşmediğiyle gösterir |
| 4) Okuma Geçmişi | O oturumda yapılan tüm denemelerin listesi (birden çok kart/deneme karşılaştırmak için) |

### Neden güvenli — talebinizin her maddesi karşılandı

| Gereksinim | Karşılanma |
|---|---|
| Mevcut auth/layout kullanılır | `require_login()` + `require_pdks('cards')` + `render_header/footer()` — yeni hiçbir şey yok |
| Yalnız HTTPS'te çalışır | Sunucu `is_https()` ile uyarır; istemci `isSecureContext` ile ayrıca gösterir |
| İzin yalnız etkileşimde istenir | `new NDEFReader()` ve `.scan()` **yalnız** buton `click` işleyicisinin içinde — statik testle kanıtlı |
| Hiçbir kayıt yazmaz | `fetch`/`XMLHttpRequest`/`<form method=post>`/SQL yazma/`audit_log_event` **YOK** — statik testle kanıtlı |
| Kart otomatik atanmaz | `pdks_kart_ata()`/`pdks_kart_olustur()` bu sayfada hiç çağrılmaz |
| Bayt sırası varsayılmaz | "Aynı sıra" ve "ters sıra" **ikisi de** gösterilir, hiçbiri otomatik "doğru" işaretlenmez — Faz 1 §6a kuralının bu sayfadaki karşılığı |

**Otomatik kanıt:** `scripts/pdks_nfc_test_static_smoke.php` — 29 test,
yukarıdaki maddelerin hepsini regex ile doğruluyor (`php -l` dahil).

---

## 4. ⚠ NEDEN BEN KENDİM TEST EDEMEDİM

Bu oturum, tarayıcısı veya NFC donanımı olmayan **izole bir bulut
konteynerinde** çalışıyor — Faz 0'daki Android teşhis APK'sinde
karşılaştığım kısıtın **aynısı**, burada tarayıcı için. Kod
sözdizimsel olarak doğrulandı ve statik olarak talebinizin her maddesini
karşıladığı kanıtlandı, ama **gerçek bir Android telefonda gerçek bir
kartla ne döndüğü ölçülmedi.** Bu ölçüm yalnız sizin tarafınızdan
yapılabilir.

### 🔴 Test etmeniz için bir blokaj var: HTTPS erişimi

Web NFC yalnız **güvenli bağlamda** (gerçek HTTPS) çalışır. Bu sayfa şu an
yalnız `claude/nfc-attendance-roadmap-z14alg` dalında, **canlıya
alınmamış** durumda. Telefonunuzdan test edebilmeniz için iki yoldan biri
gerekiyor:

1. **Bu tek sayfayı canlıya alalım** (`main`'e merge → otomatik deploy,
   `docs/DEPLOY_WORKFLOW.md`daki mevcut süreç) — yalnız `pdks_nfc_test.php`
   + küçük bir bağlantı satırı, **hiçbir davranış değişikliği yok**, hiçbir
   veri yazmıyor. Sonrasında `https://nuverna.derspros.com.tr/pdks_nfc_test.php`
   telefonunuzdan doğrudan açılabilir.
2. Siz kendi yönteminizle (mevcut bir personel oturumuyla canlıya farklı
   bir yoldan erişim vb.) test edersiniz.

**Ben "canlıya al" onayı almadan deploy etmiyorum** (proje kuralı — yalnız
siz açıkça isteyince PR+merge yapılır). Hangisini istediğinizi belirtin;
1'i seçerseniz PR'ı hemen açıp merge ederim.

---

## 5. TEST PROTOKOLÜ — sizin yapmanız gereken

1. Android telefonda **Chrome** ile (başka tarayıcı değil) NFC'yi açık olarak
   `https://nuverna.derspros.com.tr/pdks_nfc_test.php` adresini açın
   (giriş yapmış olmanız + `attendance.cards` yetkiniz olması gerekir —
   admin zaten geçer).
2. Üstteki "Ortam Bilgisi" tablosuna bakın: `NDEFReader` "✓ Var" diyor mu?
   **Hayır diyorsa** buradan sonrasına gerek yok, §6'ya geçin.
3. **[📡 NFC OKUMAYI BAŞLAT]** butonuna basın, tarayıcının izin isteğini
   onaylayın.
4. USB'de `631799511` (kanonik `25A87ED7`) veren **aynı fiziksel kartı**
   telefonun arkasına (genelde kamera yakını) yaklaştırın.
5. Ekranda çıkan sonucu — özellikle **"event.serialNumber (ham)"** satırını
   — **ekran görüntüsü olarak** veya metin olarak bana gönderin.
6. Mümkünse **2-3 farklı kartla** ve **aynı kartı 2-3 kez** tekrar okutup
   sonucun **tutarlı** (her seferinde aynı) olup olmadığını da bildirin —
   güvenilirlik için tutarlılık, tek bir başarılı okumadan daha önemlidir.

---

## 6. SONUCA GÖRE NE OLACAK

| Sonuç | Sıradaki adım |
|---|---|
| ✅ `serialNumber` **her okumada tutarlı** bir değer veriyor (aynı sıra veya ters sıra fark etmez — hangisi olduğu ölçülecek) | **Adım 3-4'e geçilir:** kart tanımlama ekranına "NFC İLE KART OKU" eklenir, Giriş/Çıkış sayfası kurulur — hepsi **aynı** `pdks_kart_ata()`/yeni giriş-çıkış fonksiyonlarına çıkar, JS'te UID mantığı **tekrarlanmaz** |
| ⚠ `NDEFReader` telefonda/tarayıcıda **hiç yok** | Web NFC bu cihazda **hiç** kullanılamaz — USB tek giriş yöntemi olarak kalır, Web NFC girişi o cihaz için gösterilmez (arayüz zaten `NDEFReader` desteğine göre butonu gösterir/gizler) |
| ⚠ Destek var ama okuma **hep hata veriyor** (`NotSupportedError`/`NetworkError`/`readingerror`) | Kartın gerçekten NDEF-siz olması ve Chrome'un bu durumda okumayı reddetmesi ihtimali güçlenir. **Tam hata adı/mesajını** bildirin — kesin teşhis ondan çıkar |
| ⚠ Bazen okuyor, bazen okumuyor (**tutarsız**) | En riskli durum — üretimde güvenlik kapısında kullanılamaz. Sizinle birlikte değerlendirilir |

**Talimatınız gereği:** kart gerçekten okunamıyorsa **durup** tam
tarayıcı/kart hatasını raporlayacağım, sessizce bir APK'ya
dönmeyeceğim — karar sizin.

---

## 7. SONRAKİ ADIMLAR (yalnız ölçüm OLUMLUYSA, henüz YAPILMADI)

Bunlar plan olarak burada duruyor, **hiçbiri kodlanmadı**:

- `PDKS_UID_KAYNAKLARI`'na `'web_nfc'` eklenir (yanında `'usb_decimal'`,
  `'nfc_hex'` — üçüncüsü zaten Faz 1'de "gelecekte Android için" ayrılmıştı,
  `web_nfc` onun yerini alabilir veya yanına eklenir, ölçüm sonucuna göre
  karar verilir).
- `pdks_uid_hex_normalize()`'ın (mevcut, Faz 1) NFC kaynak adaptörü rolü
  `web_nfc` kaynağı için de kullanılır — **ikinci bir normalizasyon
  fonksiyonu YAZILMAZ**, ölçülen bayt sırası burada (gerekiyorsa) tek
  noktadan işlenir.
- `personel_kartlar.php`/`personel_form.php`'deki kart tanımlama modaline
  "[NFC İLE KART OKU]" butonu eklenir — yalnız `NDEFReader` destekleniyorsa
  gösterilir, USB kutusunun yanında ikinci bir seçenek olarak.
- **Yeni tablo:** yalnız `attendance_events` (giriş/çıkış hareketleri).
  `attendance_devices`/`device_tokens`/`heartbeat` **AÇILMAYACAK** — talebiniz
  gereği cihaz kaydı kavramı tamamen kaldırıldı (Web NFC'de "cihaz" diye bir
  şey kaydedilmez, yalnız o an oturum açmış kullanıcı + tarayıcı vardır).
- Giriş/Çıkış sayfası: USB kutusu + (destekleniyorsa) NFC butonu, ikisi de
  **aynı** sunucu ucuna POST eder; sunucu kaynağı (`usb_decimal`/`web_nfc`)
  ayrı parametre olarak alır, UID'yi **kendisi** çözer — istemci `employee_id`
  söyleyemez (Faz 1'in "istemci UID otoritesi değildir" ilkesiyle aynı).
- 20 saniyelik mükerrer koruma sunucu tarafında (`created_at` + UID +
  cihaz/istemci kimliği yerine burada yalnız kullanıcı+kart+süre yeterli,
  çünkü ayrı cihaz kaydı yok).

---

## 8. AÇIK SORU

**Bu teşhis sayfasını test edebilmeniz için canlıya almamı ister misiniz?**
(Yalnız bu sayfa + bir bağlantı satırı; hiçbir veri yazmaz, hiçbir mevcut
sayfayı değiştirmez.) Onaylarsanız hemen PR açıp merge ederim; onaylamazsanız
kendi yönteminizle test edip sonucu bana iletin.
