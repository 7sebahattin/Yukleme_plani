---
name: hesap-modulu
description: Hesap modülü (hesap*.php, config/hesap_calc.php, hesap_pdf.php): durum makinesi, kişisel kapsam/görünürlük, bakiye, PDF dönem raporu, dompdf kuralları. hesap_* dosyalarına dokunurken kullan.
---

# Hesap Modülü (personel masrafı)

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

## Hesap Modülü — Durum Makinesi (Sprint Hesap-01)

Personel masraf takibi. **Çekirdek: `config/hesap_calc.php`** — şema migrasyonu, tutar
ayrıştırma, durum makinesi, bakiye hesabı, yetki kapısı.

**Sayfalar:** `hesap.php` ("Hesabım" — kişisel pano) · `hesap_liste.php` · `hesap_kayit.php` ·
`hesap_muhasebe.php` (onay kuyruğu) · `hesap_durum.php` (geçiş JSON uç noktası) ·
`hesap_yazdir.php` · `hesap_export.php` · `hesap_muhasebe_fis_pdf.php` · `hesap_sil.php` ·
**yalnız yönetici:** `hesap_personel.php` ("Tüm Personel" — kişi × kur bakiye).
(`hesap_sahipsiz.php` v281'de 410 tombstone.) Yeni hesap sayfası eklerken
`nav_aktif_anahtar()` içindeki `$a_hes` listesine ekle.

### Arayüz (Faz 1-3)

Modül kendi CSS/JS'ini kullanır: **`assets/hesap.css` + `assets/hesap.js`** — yalnız
`hesap_*` sayfalarında yüklenir, `style.css`/`app.js`'e HİÇ dokunmaz (maliyet emsali).
`hesap_assets()` (header'dan sonra) ve `hesap_scripts()` (footer'dan önce) ile bağlanır.

- **Tüm sayfa gövdesi `<div class="hs">` içinde olmalı** — mobil 16px input kuralı buna bağlı.
  (Token'lar `:root`'ta tanımlı, o yüzden `.hs-badge`/`.hs-note` sarmalayıcısız da çalışır.)
- **Tek birincil eylem kuralı:** sayfada yalnız bir `.hs-cta`. Diğer her şey `.btn`.
  Renk yalnız iki yerde anlam taşır: tutar işareti ve durum rozeti. Gökkuşağı buton YOK.
- **Bakiye kartı** `.hs-balance--{alacak|borc|denk}` — `hesap_balance_label()` sınıfı belirler.
- **Alt sayfa (bottom sheet):** `data-hs-sheet="<id>"` ile açılır, `.hs-sheet-ovl` z-index 600.
- **Form sırası değişmez:** fiş fotoğrafı → tutar → kategori → Detaylar. İkincil alanların
  hepsi `<details id="hsDetails">` içinde, yeni kayıtta kapalı.
- **Tür/kategori için tek yetkili alan Detaylar içindeki `<select>`'lerdir**
  (`#hsTypeSelect` / `#hsCategorySelect`). Chip'ler ve tür butonları yalnız onları yazar —
  aynı `name` ile iki alan OLUŞTURMA, POST'ta çakışır ve JS kapalıyken form çalışmaz.
- **Durum geçiş butonu:** `data-hs-durum` + `data-hs-id` + `data-hs-not` (inline onclick yok).
- Test: `php scripts/hesap_ui_smoke.php` — sayfaları gerçekten render eder, PHP uyarısı ve
  HTML etiket dengesi dahil doğrular.

**Şema eklentileri** (`account_transactions`): `user_id` (masrafın sahibi) · `created_by` ·
`status` · `submitted_at` · `reviewed_by/at` · `review_note` · `paid_at` · `depo`.

**Durum akışı** — `hesap_transitions()` tek otoritedir, geçişi elle UPDATE ile yazma:

```
draft ⇄ submitted → approved → pending_payment → paid
          ↓            ↓             ↓
       rejected ────────┴─────────────┘  → draft (düzeltmeye al)
```

| Durum | Bakiyeye girer | Geçiren |
|---|:---:|---|
| `draft` / `submitted` | — | sahibi (`hesap.write`) |
| `approved` / `pending_payment` | ✓ | `hesap.approve` |
| `paid` | ✓ | `hesap.pay` — **kayıt kilitlenir**, açmak `hesap.admin` |
| `rejected` | — | `hesap.approve`, **gerekçe zorunlu** |

**Kurallar:**
- **Bakiye yalnız `approved`/`pending_payment`/`paid`'den** hesaplanır — `hesap_balance()`.
  Bekleyen tutar ayrı gösterilir, bakiyeye karışmaz.
- **Para birimleri ASLA toplanmaz.** `hesap_balance()` currency bazında döner; TRY dışı
  kurlar ekranda ayrı kart/satırdır. (Eski `array_sum` hatası — USD+TRY toplanıyordu.)
- **İşaret:** net < 0 → şirket personele borçlu (yeşil) · net > 0 → personel şirkete
  borçlu (kırmızı). `hesap_balance_label()` bunu etiketler.
- **Tutar girdisi `hesap_parse_amount()` ile ayrıştırılır** — `str_replace` KULLANMA;
  eski kod "1234.56"yı 123456 yapıyordu (100× hata).
- `is_given_to_accountant` **legacy bayrak olarak korunur**, `hesap_transition()` senkron
  tutar (bakiyeye giren durumlar = 1). Eski sorgular bozulmasın diye silinmedi.
- **Kişisel hesap (Sprint Hesap-Kişisel):** her ekran varsayılan olarak YALNIZ oturumdaki
  kullanıcının kayıtlarını gösterir — `hesap_kapsam_coz($_GET['personel'] ?? null, 'kendi')`
  + `hesap_kapsam_sql($k, $col)` (pozisyonel `?`; eski `hesap_owner_sql()` yalnız
  sarmalayıcıdır, yeni kodda kullanma). Başkasını YALNIZ yönetici görür:
  `hesap_sees_all()` = `is_admin() || can('hesap.admin')`; yönetici `?personel=<uid>|tum`
  ile kapsamı genişletir (yönetici değilse parametre SESSİZCE yok sayılır, 403 yok).
  Linkler parametreyi `hesap_kapsam_query()` ile taşır. Yönetici başkasına bakınca
  audit `view` yazılır (kendi hesabında yazılmaz).
- **hesap.approve / hesap.pay görünürlük VERMEZ:** onay/ödeme yalnız görülebilen satırda
  çalışır (`hesap_can_transition()` İLK satırı `hesap_row_visible()`). Görünmeyen kayıtta
  `hesap_transition()` "Kayıt bulunamadı." der ("yetkiniz yok" varlığı sızdırırdı).
  Muhasebe bu yüzden yalnız KENDİ kayıtlarını onaylar; başkasınınkini onaylaması
  gerekiyorsa rolüne `hesap.admin` verilir — o zaman herkesi görür. Onay kuyruğunun
  "bekleyen" filtresi yalnız `submitted` (taslak onay beklemez).
- **Kendi kaydını onaylama/ödeme SERBEST** (kullanıcı kararı). Sahip ≠ ben yasağı EKLEME.
- **Sahipsiz (`user_id IS NULL`):** yalnız yönetici görür, HİÇ KİMSENİN bakiyesine girmez
  (`hesap_balance_tum` dahil), sahibi yoktur (`hesap_is_owner` false). Eski sahipsiz
  kayıtların tümü atandı (canlı 0, 2026-09-30); "Sahipsiz Kayıtlar" ekranı ve sayaçları
  v281'de KALDIRILDI (`hesap_sahipsiz.php` = 410 tombstone). Sahip ataması/düzeltmesinin
  TEK yolu `hesap_kayit.php` "Kayıt sahibi" alanıdır (yönetici, düzenleme modu, audit
  `owner_change`): alan artık boş/"Sahipsiz" seçeneği SUNMAZ ve sahibi olan kaydın
  sahibini boşaltmayı REDDEDER — `user_id NULL` kayıt `tum` kapsamında da görünmez
  (`IS NOT NULL`). **Otomatik geri dolum / tahmin YOK.** NULL güvenlik kuralları
  (`hesap_row_visible`, `hesap_balance*`, `hesap_kapsam_sql`) korunur.
- **Depo YOK:** Hesap sorguları `depo_sql_in` / `depot_visible_to_user` kullanmaz — kişisel
  cari aktif depoya göre değişmemeli (DEPO2'de girilen masraf DEPO1'de kayboluyordu).
  `depo` kolonu yalnız bilgi amaçlı damgadır; `enforce_active_depot()` kapısı kalır.
- **Bakiye:** `hesap_balance(null)` = KENDİ bakiye (yönetici için de — global DEĞİL);
  `hesap_balance($uid)` başkası için yalnız yöneticide dolu, değilse sıfır (fail-closed).
  Global yalnız `hesap_balance_tum()` / `hesap_balance_by_user($from,$to,$kur)` —
  ikisi de yönetici değilse boş. Başkasına bakarken etiket üçüncü şahıs:
  `hesap_balance_label($net, true)`.
- **İçerik kilidi:** `hesap_icerik_kilitli()` — approved/pending_payment/paid kaydın
  içeriği ve silinmesi yönetici dışında KAPALI (tek kapı: `hesap_kayit`, `hesap_sil`,
  liste butonları). Düzeltme yolu durum makinesidir: "Reddet" → "Düzeltmeye Al" →
  düzenle → gönder (yeni geçiş EKLEME). Yönetici düzeltmesi/silmesi gerekçe ister
  (audit `update.duzeltme_nedeni` / `delete.gerekce`). Onaylı kaydın **fişi herkese**
  kilitli (yönetici dahil, `hesap_dosya_sil.php`). `hesap_is_locked()` eski kapıdır.
- **Biçim:** tutar — çoklu binlik ayırıcı ("1.234.567"), "0,xxx" ondalık, harf içeren
  girdi 0 (→ hata), en fazla 2 ondalık, < 1e10; para birimi `hesap_para_birimleri()`,
  ödeme yöntemi `hesap_odeme_yontemleri()` beyaz listesi, tarih `hesap_tarih_gecerli()`.
  `hesap_currency_sym()` bilinmeyen kodu `h()` ile kaçırır (eski serbest metin kayıtlar).
- **Toplamlar (O3):** liste / PDF / XLSX özetinde "bakiyeye giren" (onaylı + ödenen) ile
  "tüm durumlar / bekleyen" AYRI; reddedilen hiçbir bakiye toplamına girmez. CSV değişmedi.
- **Fiş dosyaları** `uploads/hesap/` altında web'e KAPALI (`Require all denied` —
  `.htaccess` hem depoda hem `HESAP_UPLOAD_HTACCESS` ile yeniden üretilir); yalnız
  `hesap_dosya.php` (sahiplik kontrolüyle) sunar. Statik URL VERME.
- **Ana sayfa kartı** (`index.php`) kişiseldir: bugün (yalnız TRY) + kendi bekleyen sayısı;
  yönetici ek olarak onay bekleyen sayısını görür.
- Test: `php scripts/hesap_smoke.php` (bellek içi SQLite, canlı DB'ye dokunmaz) ·
  `php scripts/hesap_izolasyon_smoke.php` — iki operator (A/B), muhasebe, izleyici,
  yönetici, süper admin ile gerçek sayfaları çalıştırır; her izolasyon bulgusu
  (sahipsiz sızıntı, PDF personel özeti, ana sayfa sayacı, onaylı kaydın düzenlenmesi,
  depo kaybı, muhasebe görünürlüğü, silme, biçim) regresyondur. **Görünürlük/kapsam
  kuralına dokunduysan çalıştır.**

### PDF Dönem Raporu (Faz 5)

**`config/hesap_pdf.php`** — veri toplama (`hesap_report_data`), HTML üretimi
(`hesap_report_html`), PDF üretimi (`hesap_report_pdf`). Uç nokta: **`hesap_yazdir.php`**
(varsayılan PDF · `?goruntule=html` hızlı yazdırma · `?indir=1` dosya indirme).

Bölümler: logo + kapsam başlığı → dönem özeti (para birimi başına) → personel özeti →
kategori kırılımı (yüzde çubuklu) → işlem listesi (durum rozetli) → imza alanları →
**3×3 fiş görselleri** (sonda, her kutuda kayıt künyesi).

**dompdf kuralları — değiştirme:**
- `isRemoteEnabled = false` · `isPhpEnabled = false`. Görseller `data:` URI olarak gömülür;
  uzak kaynak çekilmez, HTML içinde PHP çalışmaz. Bu ayarları açma.
- **`text-transform: uppercase` KULLANMA** — CSS Türkçe i/İ eşlemesini bilmez:
  "Gelir" → "GELIR", "Şirkete" → "ŞIRKETE" olur. Etiketi doğrudan istenen yazımda yaz.
- **Sayfa numarası `counter(pages)` ile çalışmaz** (dompdf 0 döner). Render sonrası
  `$canvas->page_text(... '{PAGE_NUM} / {PAGE_COUNT}' ...)` kullanılır.
- Yazı tipi **DejaVu Sans** — Türkçe glifleri ve ₺ (U+20BA) içerir. Değiştirirken glif
  kapsamını doğrula.
- Görseller `hesap_pdf_image_uri()` ile GD üzerinden küçültülür (uzun kenar 900 px, JPEG 72).
  Okunamayan dosya `null` döner ve rapor "[görsel okunamadı]" ile devam eder — çökmez.
- Rapora en çok `HESAP_PDF_MAX_FIS` (90) görsel eklenir; aşan sayı rapora not düşülür.
- Test: `php scripts/hesap_pdf_smoke.php` (geçici yükleme klasörü, canlı uploads/'a dokunmaz).

**Bağımlılık:** `dompdf/dompdf ^3.0`, `vendor/` içinde commit'li (depo pratiği).
`vendor/` ~22 MB; 7.6 MB'ı DejaVu font ailesi — Türkçe için gerekli, silme.

---
