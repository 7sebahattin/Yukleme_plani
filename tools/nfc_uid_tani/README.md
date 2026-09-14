# NFC UID Tanı — Faz 0 Teşhis Uygulaması

**Bu bir üretim uygulaması DEĞİLDİR.** Tek işi, `android.nfc.Tag.getId()`'nin
döndürdüğü ham bayt dizisini **hiç çevirmeden** ekrana basmaktır.

- Ağa **çıkmaz**, sunucuya bağlanmaz, veri saklamaz.
- NFC dışında **hiçbir izin** istemez.
- Bağımlılığı **yoktur** (AndroidX/Material yok) → APK ~100 KB.

## Neden gerekli

Aynı fiziksel kart için:

| Kaynak | Değer |
|---|---|
| USB HID okuyucu (Excel'e yazdı) | `631799511` = `0x25A87ED7` |
| Bir Android teşhis uygulaması | `25 A8 7E D7` |
| Başka bir Android uygulaması | `D7:7E:A8:25` |

Üçüncü parti uygulamalar **birbiriyle çelişiyor**. Kanonik UID'yi onların ekran
çıktısına göre seçmek yanlış olur; platformun kendi API'sinden ölçmek gerekir.

## Derleme (Android Studio)

1. Android Studio → **Open** → bu klasörü (`tools/nfc_uid_tani`) seç.
2. İlk açılışta Gradle wrapper'ı ve SDK bileşenlerini kendisi indirir
   (sürüm uyarısı çıkarsa "Upgrade"i kabul etmek güvenlidir — bu bir tek
   kullanımlık teşhis aracıdır).
3. NFC'li telefonu USB ile bağla, **USB hata ayıklama**yı aç.
4. **Run ▶**.

### Android Studio yoksa — daha kısa yol

Yeni proje → **Empty Views Activity** (Kotlin) → oluşan projede sadece şu iki
dosyanın içeriğini bu depodakiyle değiştir:

- `app/src/main/java/.../MainActivity.kt`
- `app/src/main/AndroidManifest.xml` (NFC izni + `uses-feature` satırları)

## Test adımları

1. Uygulamayı aç → ekranda **"⏳ Kart bekleniyor…"** yazmalı.
2. **USB okuyucuda `631799511` veren AYNI fiziksel kartı** telefonun arkasına okut.
3. Ekran görüntüsü al **veya** "SONUCU KOPYALA" ile panoya al ve bana gönder.

## Beklenen ekran çıktısı

İki sonuçtan biri çıkacak:

**Durum A — getId() USB ile aynı yönde (beklenen):**

```
UID uzunluğu   : 4 bayt
getId() ham    : 25 A8 7E D7
HEX (API sırası): 25A87ED7
HEX (ters)     : D77EA825
Ondalık (API)  : 631799511
Ondalık (ters) : 3615402021
ATQA / SAK     : 0004 / 08
MifareClassic  : EVET

✓ getId() = USB ile AYNI YÖN
  KANON = getId() sırası (çevirme YOK)
```

**Durum B — getId() ters:**

```
getId() ham    : D7 7E A8 25
HEX (API sırası): D77EA825
HEX (ters)     : 25A87ED7
Ondalık (API)  : 3615402021
Ondalık (ters) : 631799511

⚠ getId() USB'nin TERSİ
  KANON = getId() TERS çevrilmiş hâli
```

Uygulama kararı **kendisi yazar**; yorumlamanıza gerek yok.

## Sonuç şemayı değiştirir mi?

**Hayır.** `employee_card_uids` alias tablosu her iki gösterimi de aynı karta
bağladığı için tablo yapısı iki durumda da aynıdır. Ölçüm yalnız Android
istemcisinin gönderdiği değeri ve hangi gösterimin `kind='canonical'`
etiketleneceğini belirler — yani bu bir **Faz 2 girdisidir, Faz 1 engelleyicisi
değildir**.

Ayrıca faydalı yan çıktılar: gerçek **ATQA/SAK** değerleri, UID'nin gerçekten
4 bayt olduğu ve test telefonunun NFC donanımının çalıştığı doğrulanır.
