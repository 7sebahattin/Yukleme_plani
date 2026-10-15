# Ters Kart Takip (v354)

Bağımsız, paralel çalışan günlük işçi giriş-çıkış modeli. Mevcut Günlük İşçi
PDKS (normal giriş, Tanımlı Giriş, Ortak Çıkış, Mesaiyi Kapat) **aynen** çalışır;
iki sistem aynı anda kullanılabilir.

## Problem ve prensip

Sabah çok sayıda işçiyi tek tek okutmak kuyruk yaratıyor. Akşam ise kartlar
geri alınırken tek tek okutulabiliyor. Bu yüzden sabah süreci tersine çevrildi:

> Çavuşun kalıcı kart havuzundan sabah dağıtılmadan **elde kalan** kartlar
> okutulur. Aradaki fark (UID bazında) o gün dağıtılan kartları ve çalışan
> sayısını verir. Akşam kartlar gerçek saatle çıkış yapar.

## Sahip kararları (2026-10-13)

| Konu | Karar |
|---|---|
| Havuz kaynağı | Ters'e özel **ayrı havuz** (`ters_havuz_kart`) |
| Tanımlı kart çakışması | Başka çavuşa/depoya **tanımlı** kart havuza eklenemez (önce Tanımı Kaldır) |
| Normal kiosk | Başka çavuşun ters havuzundaki kart normal girişe, Kart Havuzu tanımına ve elle/toplu eklemeye **giremez**. Aynı çavuş + depo serbest |
| Giriş saati | Çavuş + depo bazında, tarihli (geriye dönük değil); sabah onayında o gün için **gerekçeyle** değiştirilebilir |
| Karışık gün | Aynı çavuş aynı gün hem ters hem normal okutma kullanabilir; kart bazlı kilit çift girişi engeller |
| Dönmemiş kart | Dün eksik çıkışla kalan kart sabah ne kalan okutuldu ne kayıp işaretlendiyse **onay engellenir**; operatör "Kayıp" ya da "Bugün dağıtıldı" der |
| Beyan sayısı | İsteğe bağlı; dağıtılan kart sayısından farklıysa **uyarı** (engel değil) |
| Onay sonrası düzeltme | İlk çıkış okutulana kadar operatör (daily_scan) gerekçeyle **Sayımı Geri Al**; sonrası yalnız yönetici, Mesai Detayı'ndaki mevcut araçlarla |
| Erken onay | Sabit saat onay anından en fazla **2 saat** ileride olabilir |
| Şema | Yalnız 4 YENİ tablo (ALTER yok), kurulum `migrate.php` kartından (GO verildi) |

## Ekranlar

| Sayfa | İçerik | Yetki |
|---|---|---|
| `ters_kart.php` | Ana sayfa: 4 ekrana bağlantı + aktif depodaki çavuşların bugünkü durumu | worker_cards / daily_scan / daily_reports |
| `ters_kart_havuz.php` (A) | Çavuş seç → kart okut (tip seçili) → havuza ekle; Kayıp / Bozuk / Pasif / Kullanıma Al / Aktar / Çıkar; Havuzu sıfırla; sabit giriş saati + geçmişi; havuz geçmişi | `attendance.worker_cards` |
| `ters_kart_sayim.php` (B) | Çavuş seç → Sayımı Başlat → elde kalan kartları okut → canlı sayaçlar + önizleme → Onayla; onaylıda özet + Sayımı Geri Al | `attendance.daily_scan` |
| C · Ortak Çıkış (v355) | `gunluk_isci_giris_cikis.php?mod=ortak` — ters ve normal girişler TEK ekrandan çıkar; sonuç kartı giriş kaynağını gösterir ("↩ Ters Kart · sabit giriş 08:00"). `ters_kart_cikis.php` buraya yönlendirir | `attendance.daily_scan` |
| `ters_kart_mutabakat.php` (D) | Tarih seç; çavuş bazında havuz · kalan · dağıtılan · giriş · çıkış · içeride · eksik çıkış · dönmeyen kartlar | daily_scan / daily_reports |

Mevcut ekranlara eklenenler: Personel Takibi'nde "↩ Ters Kart Takip" kartı, Kart
Havuzu başlığında "↩ Ters Kart Havuzu" bağlantısı (tablolar kuruluysa), Mesai
Detayı'nda `source='ters'` dönemlere "↩ Ters" rozeti, `migrate.php` kurulum kartı.
Hiçbir mevcut düğme kaldırılmadı ya da işlevi değişmedi.

## Veri modeli (yalnız yeni tablolar)

- `ters_havuz_kart` — çavuş + depo + kart + tip üyeliği. Kart başına TEK aktif
  üyelik: `aktif_kart_id` UNIQUE (aktifken kart id, bitince NULL). Satırlar
  silinmez (`valid_to`, `end_reason`). `durum_etiketi` bozuk/kullanım dışı ayrımı.
- `ters_cavus_ayar` — sabit giriş saati, `valid_from` ile tarihli.
- `ters_sayim` — günlük sayım: `taslak` → `onayli` → (`geri_alindi`).
  Çavuş + gün + depo başına tek aktif sayım: `aktif_anahtar` UNIQUE. Onayda
  giriş saati, giriş zamanı, gerekçe, sayılar, beyan, `istek_id` (UNIQUE),
  onaylayan ve onay zamanı donar.
- `ters_sayim_kart` — taslakta kalan/teyit okutmaları; onayda havuzun tamamının
  anlık görüntüsü (`dagitilan` / `zaten_iceride` / `haric` / `kalan`) + oluşan dönem.

Mevcut tablolar: `worker_cards` (kart + global durum), `daily_work_sessions`,
`daily_worker_work_periods` (`source='ters'`, VARCHAR — mevcut değerler scan /
tanimli / manual), `daily_worker_card_events` (`source='ters'` = fiziksel okutma
değil). Bunlara **yeni kolon eklenmedi**.

## Zamanlar (karıştırılmaz)

| Zaman | Nerede |
|---|---|
| Mesai başlangıç (ör. 08:00) | `daily_worker_work_periods.entry_time`, `ters_sayim.giris_zamani` |
| Gerçek kayıt/işlem anı | GİRİŞ olayının `server_event_time` + `created_at` (kaynak `ters`) |
| Dağıtım onay anı | `ters_sayim.onay_zamani` (+ audit `ters_sayim_onay`) |
| Çıkış | Ortak Çıkış'ın gerçek sunucu saati |

## Yazma yolları (ikinci motor YOK)

- Giriş: `pdks_faz8j_satir_kontrol()` + `pdks_faz8j_satir_yaz()` (kaynak parametresi
  eklendi; varsayılan `manual` — eski çağıranlar değişmedi).
- Mesai: `pdks_faz8j_oturum_coz()` → `pdks_gunluk_oturum_ac_veya_getir()` (kiosk yolu:
  önceki gün açık mesai, kapalı mesai, pasif çavuş kuralları aynen).
- Çıkış: `pdks_gunluk_ortak_cikis_kaydet()` (değişmedi).
- Geri alma: `pdks_faz8j_void_uygula()` (dönem iptal edilir, silinmez).
- Kart durumu: `pdks_gunluk_kart_durum_degistir()`.

## Onay algoritması (`pdks_ters_sayim_onayla`)

HEP-YA-HİÇ, tek transaction. Kilit sırası (InnoDB REPEATABLE READ: tüm kilitli
okumalar ilk tutarlı okumadan önce):

1. `ters_sayim` satırı `FOR UPDATE` (çift onay / eşzamanlı operatör)
2. mesai satırı `FOR UPDATE` (Mesaiyi Kapat ile yarış)
3. havuz üyelikleri `FOR UPDATE` + kartlar artan id ile `FOR UPDATE` (kiosk girişiyle aynı kilit)
4. `pdks_ters_sayim_degerlendir()` kilit altında yeniden — tek kart hatalıysa hiçbir şey yazılmaz
5. mesai (kiosk yolu) → 6. dağıtılan her kart için giriş → 7. anlık görüntü + özet audit

Kart sınıfları: `kalan` · `dagitilacak` · `zaten_iceride` (bu çavuşun bugünkü
mesaisinde normal okutmayla zaten içeride — ikinci dönem yazılmaz) · `haric`
(kayıp/bozuk/pasif — işçi sayılmaz) · `hata` (başka mesaide açık, dönmemiş kart
kararsız, aynı gün eksik çıkış, desteklenmeyen tip, havuz dışı okutma).

## Mevcut sisteme etkiler

- `config/pdks_gunluk.php`: `pdks_gunluk_ters_havuz_*` kapısı (tablo yoksa null)
  normal girişte, tanım yazımında, Seri Kart sınıflamasında ve Kart Havuzu
  silmesinde; geçmiş tablo listesine iki yeni tablo.
- `config/pdks_faz8j.php`: `satir_yaz` kaynak/olay zamanı/audit parametreleri
  (varsayılanlar eski davranış), `tanim_kart_engeli` ters havuzu da denetler.
- Hakediş/cari/rapor: kaynak kolonuna göre dallanmadığı için ters dönemleri
  normal dönem gibi hesaplar. Tüketicilere kod EKLENMEDİ.
- Tablolar kurulmadan: hiçbir mevcut davranış değişmez (test 0. bölüm).

## Kurulum

1. Kodu canlıya al (PR → main, otomatik deploy).
2. Yönetici `migrate.php` → "Ters Kart Takip — Tablolar (v354)" → **Tabloları Oluştur**.
3. Kart Havuzu (A) ekranında çavuşlara kartları okut, giriş saatini gir.

## Testler

```
php scripts/pdks_ters_kart_smoke.php          # sunucu tarafı A–J (bellek içi SQLite)
node scripts/pdks_ters_kart_smoke.js          # tarayıcı: 7 render × 3 genişlik, okutma akışları
TK_SAYFA=sayim php scripts/pdks_ters_kart_render.php > _test_ters_sayim.html   # tek render
```

İlgili regresyon: `pdks_kart_havuzu_smoke.php` (geçmiş tablo listesi),
`pdks_tanimli_giris_smoke.php`, `pdks_ortak_cikis_smoke.php`,
`pdks_gecmis_ekle_smoke.php`, `pdks_toplu_ekle_smoke.php`, `rol_kapilari_smoke.php`.

## Bilinen sınırlar

- Ters yöntemin doğası gereği: ne kalan okutulan ne kayıp işaretlenen her
  aktif kart "dağıtıldı" sayılır. Fark edilmemiş bir kayıp kart çalışan sayısını
  1 artırır — beyan sayısı uyarısı ve akşam mutabakatındaki "dönmeyen kart"
  listesi bunu görünür kılar.
- Web NFC yalnız Android Chrome'da; iOS/masaüstünde NFC düğmesi gizlenir, USB
  okuyucu kullanılır.
- Bağlantı koparsa okutma kaydedilmez ve ekranda kırmızı uyarı çıkar (çevrimdışı
  kuyruk yok); taslak sunucuda olduğu için kaydedilenler kaybolmaz.

## Bağımsız denetim (v354) — düzeltilenler ve açık konular

Düzeltildi:
- İleri tarihli sabit saatte (≤2 sa) giriş saatinden ÖNCE çıkış yazılabiliyordu →
  `pdks_gunluk_faz8a_cikis_kaydet()` artık `giris_saati_gelmedi` ile reddeder (eski
  yollar giriş saatini hep ≤ şimdi yazdığı için mevcut davranış değişmez).
- Saat ayarı olmayan çavuşta elle girilen saat gerekçesiz geçiyordu → gerekçe zorunlu.
- Bugün çalışıp çıkmış kart sayımda okutulmazsa ikinci katılım sessizce yazılıyordu →
  önizlemede/onayda **uyarı** (sahip kararı: karışık gün serbest, engel değil). Aynı
  saatlerle çakışan dönem zaten ENGEL.
- "Bugün dağıtıldı" teyidi ve sayım ekranından "Kayıp" yalnız önceki günden dönmemiş
  kartlar için; mutabakat teyitli kart sayısını ayrıca gösterir.
- Aktarmada hedef havuz sınırı ve tanım tipi, eklemede havuz sınırı kart yaratılmadan önce.
- Personel Takibi kartı tablolar yokken yalnız yöneticiye görünür.

Açık (sahip kararı gerekir, kod DEĞİŞTİRİLMEDİ):
- **Sabit saatin alt sınırı yok.** Gerekçe zorunlu ve audit'te, ama ör. öğleden sonra
  "00:00" girilebilir. İstenirse "onay anından en fazla N saat önce" kuralı eklenir.
- **Mesaiyi Kapat ile onay yarışı (paylaşılan kod):** `pdks_gunluk_oturum_kapat()` mesai
  satırını kilitlemeden özet okur; çok dar bir zaman penceresinde onayla eşzamanlı kapatma
  yeni açık dönemlerle mesaiyi gerekçesiz kapatabilir (ertesi sabah bu kartlar "dönmemiş"
  olarak onayı engeller — sessiz kayıp yok). Düzeltme kiosk tarafını da etkiler.
- **Havuz ekranı bilinmeyen UID için kart oluşturur** (görev tanımındaki "yeni kart
  ekleme"); v301'deki "kart eklemenin tek yolu Seri Kart Tanımla" kararına ikinci bir
  giriş noktası. Ters havuz dışında kullanılmaz; istenirse yalnız mevcut kartlarla sınırlanır.
- Onay ile havuz işlemleri farklı kilit sırasıyla çakışırsa InnoDB deadlock'u bir tarafı
  geri alır (veri bozulmaz, "eşzamanlı işlem" mesajı).

## v355 — Tek çıkış noktası: Ortak Çıkış

Sahip kararı: ters ve normal sistem aynı yerden çıkar. Ayrı "Hızlı Çıkış" ekranı kaldırıldı.
- `ters_kart_cikis.php` → `gunluk_isci_giris_cikis.php?mod=ortak` (302). Eski önbellekli sayfalar için POST
  `?ajax=cikis` korunur ve aynı `pdks_gunluk_ortak_cikis_kaydet()` yolunu çağırır.
- Kiosk `?mod=ortak` ile doğrudan Ortak Çıkış modunda açılır.
- `pdks_gunluk_faz8a_cikis_kaydet()` yanıtına `card.entry_source` eklendi (yalnız bilgi). Kiosk ÇIKIŞ sonucunda
  kaynak `scan` değilse bir satır gösterir: "↩ Ters Kart · sabit giriş HH:MM", "🏷 Tanımlı giriş", "✍ Elle eklenmiş giriş".
- Mutabakat ve hakediş hangi ekrandan çıkış yapıldığına bakmaz; dönem tablosundan sayar.

## v355 — Görünen ad: "Giriş Tanımlı"

Sahip kararıyla modülün ekrandaki adı "Giriş Tanımlı" oldu (Personel Takibi kartı yeni kapı ikonuyla, sayfa
başlıkları, mesajlar, Mesai Detayı rozeti "↩ Giriş Tanımlı", Ortak Çıkış sonuç satırı "↩ Giriş Tanımlı · sabit giriş
08:00"). Kod kimlikleri, URL'ler (`ters_kart*.php`) ve tablo adları (`ters_*`) değişmedi. Not: kiosktaki
"🏷 TANIMLI GİRİŞ" (kart → çavuş tanımıyla giriş, v298) ayrı bir özelliktir.

"Çıkış girişten önce yazılamaz" kapısı yalnız `source='ters'` dönemlere uygulanır; diğer giriş kaynaklarında
çıkış davranışı v354 öncesiyle aynıdır.

## v363 — Manuel Sayım (kartsız sabah sayımı)

Sahip isteği: kartla sayıma GÜNLÜK bir alternatif. Operatör o gün için ya kartla sayar
(mevcut akış, değişmedi) ya da yalnız çavuşun elinde kalan kart **sayısını** girer.
Örnek: havuzda 50 aktif kart, 20 geri geldi → "20" → 30 kişi çalışıyor. Akşam Ortak
Çıkış bu çavuşun havuz kartlarından en fazla 30 çıkışa izin verir; gün sonunda dağıtılan
kartların listesi çıkışlardan kendiliğinden oluşur.

**Şema değişmedi** (ALTER / yeni tablo yok):
- `ters_sayim.durum = 'manuel'` → onaylı manuel sayım (`aktif_anahtar` dolu kalır; çavuş +
  gün + depo başına yine TEK aktif sayım). Onaylı mı sorusu TEK yerde:
  `pdks_ters_sayim_onayli_mi()` (`onayli` | `manuel`); aktif durum listesi
  `pdks_ters_aktif_durumlar()` (mutabakat sorgusu).
- `ters_sayim_kart.rol = 'manuel_cikis'` → akşam çıkış yapan kart + oluşan dönem.
  `UNIQUE(sayim_id, worker_card_id)` aynı kartın ikinci çıkışını yapısal olarak engeller.

**Sabah — `pdks_ters_manuel_onayla()`** (önizleme `pdks_ters_manuel_onizle()`, değerlendirme
`pdks_ters_manuel_degerlendir()`): taslak sayım; kalan 0..aktif havuz (aktif = kayıp/bozuk/
kullanım dışı HARİÇ, kartla sayımla aynı tanım); dağıtılan = aktif − kalan ≥ 1. Sabit saat,
gerekçe, ≤2 sa ileri, mesai kuralları kartla sayımla AYNI (`pdks_ters_giris_coz`,
`pdks_ters_sayim_genel_kontrol`). Tek transaction, kilit sırası kartla aynı (sayım → mesai →
üyelik + kartlar). Mesai kiosk yoluyla (`pdks_faz8j_oturum_coz`) açılır; **sabah DÖNEM
YAZILMAZ**. `istek_id` ile tekrar gönderim güvenli. Audit `ters_sayim_manuel_onay`.
- **Zaten içeride:** bu çavuşun bugünkü mesaisinde normal okutmayla içeride olan havuz kartı
  (açık dönemi var) kendi normal çıkışını yapar; manuel çıkış hakkı
  **limit = dağıtılan − zaten içeride** (`pdks_ters_manuel_limit`). Limit < 1 → onay yok.
- Başka mesaide içeride / önceki günden dönmemiş / bugün eksik çıkışlı / tipi desteklenmeyen
  kartlar manuel sayımda bilinemez → **uyarı** (engel değil): bu kartlar akşam manuel çıkış
  yapamaz (`pdks_faz8j_satir_kontrol` reddeder).
- **Taslakta okutulmuş kalan/teyit satırları** manuel onayda SİLİNİR, listesi onay audit'inde
  (`silinen_taslak_okutma`) kalır — UNIQUE anahtar aynı kartın akşam `manuel_cikis` satırını
  engellerdi.

**Akşam — `pdks_ters_manuel_cikis()`**: kiosk `?ajax=ortak_cikis` (ve eski `ters_kart_cikis.php`
`?ajax=cikis`) YALNIZ `pdks_gunluk_ortak_cikis_kaydet()` `acik_donem_yok` döndürdüğünde
`pdks_ters_ortak_cikis_manuel_yedek()` ile dener. **Ortak çıkış fonksiyonu değişmedi.**
Uygulanamazsa (kart ters havuzda değil / başka depoda / çavuşun mesaisi açık manuel sayımı
yok) `null` → özgün hata aynen. Tek transaction, kilit sırası sayım → mesai → kart; sıra:
mükerrer (`mukerrer_cikis` "Bu kart bugün zaten çıkış yaptı.") → limit (`manuel_limit` "Bu
çavuşun manuel sayımına göre N kişi çalışıyor — N çıkış tamamlandı, fazla çıkış yapılamaz.")
→ giriş saati geldi mi (`giris_saati_gelmedi`) → kesin hakediş → tip → `pdks_faz8j_satir_kontrol`
→ `pdks_faz8j_satir_yaz` (kaynak `ters`, giriş = sabit saat, çıkış = gerçek saat, KAPALI dönem,
audit `ters_manuel_cikis`) → `ters_sayim_kart` `manuel_cikis`. Yanıt Ortak Çıkış başarı
yanıtıyla aynı biçimde (+ `manuel: {dagitilan, cikan, kalan}`, `mesaj`): kiosk sonuç kartında
kaynak satırı "↩ Giriş Tanımlı · sabit giriş 08:00 · 🔢 28/30 çıkış", daire = hâlâ çıkış yapmamış
kişi sayısı (manuelde tipe göre açık dönem olmadığı için toplam).

**Geri al:** yalnız ilk manuel çıkıştan ÖNCE ve mesai açıkken (`pdks_ters_manuel_geri_al_tx`,
`pdks_ters_sayim_geri_al` içinden); iptal edilecek dönem yok; sayım `geri_alindi`, aynı gün için
BOŞ yeni taslak. Sonrası yalnız yönetici, Mesai Detayı araçlarıyla.

**Gün sonu / mutabakat:** Yöntem "Manuel" · havuz · kalan (manuel) · dağıtılan · çıkış yapan /
limit · çıkışı okutulmayan (= limit − çıkan; mesai açıkken "İçeride" toplamına da girer) ·
çıkış yapan kartlar listesi (= dağıtılan kartlar). Okutulmayan kişi için dönem/çıkış
**uydurulmaz**. Hub "İçeride" = mesainin açık dönemleri + (limit − çıkan).

**Ekran:** taslak sayımda "📇 Kartla Say" / "🔢 Manuel Sayı Gir" seçimi (manuel modda kart
okuyucu durur). Manuel panel: aktif havuz, "Elde kalan kart" (inputmode numeric, büyük yazı),
canlı "→ N kişi çalışacak", sabit saat + gerekçe + isteğe bağlı beyan, onay penceresi. Onaylı
manuel görünüm: özet + Çalışan / Çıkış yapan / Çıkışı okutulmayan + Geri Al (çıkış yoksa).
pdks.css "v363" bloğu.

Test: `php scripts/pdks_ters_kart_smoke.php` (bölüm M) · `node scripts/pdks_ters_kart_smoke.js`
(4b; render `TK_SAYFA=sayim_manuel_onayli`).

### v363 — bağımsız inceleme düzeltmeleri
- **Eski eksik çıkış manuel çıkışı engellemez:** `pdks_faz8j_satir_kontrol()`'e OPT-IN `$secenek['kapali_mesai_eksik']`
  (varsayılan `[]` = mevcut tüm çağıranlar için davranış BİREBİR aynı). Yalnız `pdks_ters_manuel_cikis()` açar: KAPATILMIŞ
  mesaide çıkışsız kalmış dönem mesainin `closed_at`'inde biter sayılır (yoksa yok sayılır); AÇIK mesaideki çıkışsız dönem
  yine engeller. Sabah önizlemesi, son dönemi olmasa da düzeltilmemiş eksik çıkışı olan her kart için uyarır.
- **Mutabakat tutarlılığı:** manuel onayda "zaten içeride" kartlar `ters_sayim_kart` `rol='zaten_iceride'` (dönem id'li)
  anlık görüntüsüyle yazılır (kartla sayımdaki gibi); mutabakat bunları giriş/çıkış/içeride/eksik'e katar. Okutulmayan manuel
  kişi mesai açıkken `iceride`, kapanınca `eksik_cikis` (toplama da girer) — çıkış + içeride + eksik = dağıtılan.
- **İptal edilen manuel çıkış:** mükerrer kontrolü yalnız iptal EDİLMEMİŞ döneme bakar; yönetici yanlış çıkışı iptal ederse
  aynı kart yeniden çıkış yapar ve mevcut satır (UNIQUE) aynı kilitli işlemde yeni döneme UPDATE edilir.
- Başarısız manuel onaydan sonra önizleme yeniden alınır (düğme sunucu durumuna göre tazelenir).
