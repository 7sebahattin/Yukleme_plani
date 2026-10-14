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
| `ters_kart_cikis.php` (C) | Çavuş seçmeden tek ekrandan çıkış; çavuş, kart, tip, giriş→çıkış saati, içeride kalan | `attendance.daily_scan` |
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
