---
name: pdks-kiosk-kart
description: Günlük işçi kiosku (gunluk_isci_giris_cikis.php), Mesaiyi Kapat, Ortak Çıkış, Geçmişe dönük/kartsız/toplu ekleme, Karışık/Rampacı tipi, Tanımlı Giriş, Seri Kart, Kart Havuzu, Kart Hareketleri düzenleme, Ters Kart Takip, liste ergonomisi. isci_kartlari/gunluk_*/ters_kart*/pdks_gunluk/pdks_faz8j/pdks_ters dosyalarına dokunurken kullan.
---

# PDKS — Kiosk, Kartlar, Mesai Detayı

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

## Gün Sonu Kapanışı — "Mesaiyi Kapat" (v275)

"Mesaiyi Kapat" günlük işçi mesaisinin **Z raporudur**: sayımı kilitler (kart
okutma durur, yeniden açma yalnız admin) ve hakedişi kesinleştirilebilir kılar
(`pdks_hakedis_finalize()` KAPALI mesai ister). Kapatma yetkisi
`attendance.daily_scan` (güvenlik/operator) — parayla ilgili hiçbir şey görmez.

- **Önceki günden açık mesai YENİ GÜNÜ ENGELLER** — `pdks_gunluk_oturum_ac_veya_getir()`
  yalnız YENİ oturum açarken aynı çavuş + aynı depo için
  `pdks_gunluk_eski_acik_oturumlar()`'a bakar, varsa `kod=onceki_mesai_acik` +
  `eski_oturumlar` döner. Bugünkü açık mesaiye devam ENGELLENMEZ; başka depo etkilemez.
- **Tarama ekranı** (`gunluk_isci_giris_cikis.php`) böyle mesai varsa **pencereyle
  açılır** (`#giEskiSec`, veri `#giEskiVeri` JSON). Kapatma AYNI `?ajax=kapat` yolu
  ve aynı eksik-çıkış mutabakatıdır — **ikinci bir kapatma yolu AÇMA**. "Sonra"
  geçişe izin verir; sunucu kuralı yine geçerlidir.
- **Süre doldu uyarısı** — `pdks_gunluk_kapat_uyarisi()`: açılış +
  `normal_work_minutes_snapshot` (yoksa 540) + `PDKS_GUNLUK_KAPAT_UYARI_PAY_DK` (60).
  ENGEL DEĞİL; mod ekranında Kapat'ın üstünde (`#giKapatUyari`, `ajax=oturum`
  yanıtındaki `kapat_uyarisi`) ve çavuş listesinin üstünde
  (`pdks_gunluk_suresi_dolan_oturumlar()`) görünür. Hesap SUNUCU saatiyle.
- `personel_takip.php` Giriş/Çıkış kartında kapatılmamış mesai sayısı rozeti.
- **Eksik çıkışlı kart yeni girişi ENGELLEMEZ** (kullanıcı kararı): eksik çıkışla
  kapatılan mesaideki dönem `open` KALIR (raporda "Eksik Çıkış", çıkış saati
  UYDURULMAZ), ama kilit yalnız **açık mesaideki** dönemdir —
  `pdks_gunluk_faz8a_kart_acik_donemi()` `s.status='open'` ister (legacy
  `pdks_gunluk_kart_acik_girisi()` ile aynı kural). Giriş yapılır ve yanıtta
  `uyari` döner (`pdks_gunluk_faz8a_kart_eksik_cikisli_donemi()` +
  `pdks_gunluk_eksik_cikis_uyari_metni()`; sonuç kartında `.pdks-result-uyari`).
  Bir kartta böylece İKİ `open` dönem olabilir: **çıkış sorgusu yalnız açık
  mesaidekini seçer** — bu filtreyi kaldırma, yoksa eski dönem kapatılır/yanlış
  çavuş reddi gelir. Admin yeniden açması (`pdks_faz8h.php`) mesaideki çıkışsız
  kart başka açık mesaide içerideyse `kart_baska_mesaide` ile reddedilir.
- **İzin YALNIZ önceki günlere aittir** (v276, kullanıcı kararı — güvenlik): kart
  AYNI GÜN (`work_date` = yeni oturumun günü) eksik çıkışla kapatılmış bir mesaide
  kaldıysa o gün başka mesaiye giremez → `kod=bugun_eksik_cikis` (depo/çavuş fark
  etmez). Yoksa mesaiyi erken kapatıp kartı başka çavuşa geçirerek aynı gün iki
  katılım yazılabilirdi. **NORMAL çıkış yapmış kartın aynı gün yeniden kullanımı
  (Faz 8A "nötr kart") DEĞİŞMEDİ** — kullanıcı bunu açıkça korudu.
- Test: `php scripts/pdks_gun_sonu_kapanis_smoke.php`.

### Ortak Çıkış (v288, v289)

Çavuş listesinin üstündeki **"🚪 ORTAK ÇIKIŞ"** düğmesi: çavuş seçmeden tek
ekrandan çıkış. Giriş DEĞİŞMEDİ (çavuş + işçi tipi zorunlu).

- **İkinci yazma yolu DEĞİL.** `pdks_gunluk_ortak_cikis_kaydet()` kartın açık
  mesaisini `pdks_gunluk_faz8a_kart_acik_donemi()` ile BULUR, yazmayı
  değiştirilmemiş `pdks_gunluk_faz8a_cikis_kaydet()`'e devreder (o fonksiyon
  kartı kilitleyip yeniden doğrular — arada mesai kapanırsa reddeder). Ortak
  fonksiyona INSERT/UPDATE EKLEME; test bunu engelliyor.
- Uç `?ajax=ortak_cikis` istemciden **session_id ALMAZ**. Sayaç ucu
  `?ajax=ortak_mesailer` (salt okunur). İkisi de CSRF + `daily_scan`.
- **Tarih sınırı YOK** (kullanıcı kararı): dünden açık mesaideki kart da çıkar
  (gece vardiyası). **Depo sınırı VAR** (`pdks_gunluk_depo_kontrol`). Tanımsız
  kart otomatik KAYDEDİLMEZ.
- v289: tarama ekranındaki "çavuş çavuş açık mesailer" paneli KALDIRILDI.
  Çavuş listesinde "içeride N" rozeti kalır (`pdks_gunluk_ortak_cikis_mesailer()`,
  tek gruplu sorgu); kaydet yanıtındaki `mesailer` ile ve çavuş listesine
  dönüşte `?ajax=ortak_mesailer` ile tazelenir. Düğme ile liste arasında
  düz çizgi ayraç (yazısız) vardır. **Mesaiyi Kapat ortak modda YOK** —
  kapatma çavuş bazında kalır.
- v290: ÇIKIŞ sonuç kartında (normal + ortak) daire (top + hareketli
  çemberler) AYNEN korunur; içindeki rakam, okutulan kartın cinsiyetinden o
  mesaide hâlâ İÇERİDE kalan kişi sayısıdır (`data-sayi="kalan"`). Ayrı
  "KALAN" başlığı/kutu YOK (kullanıcı kararı). Kaynak `ozet.eksik_tip`; yoksa
  giris-cikis, negatife düşmez. Tip Kadın/Erkek değilse eski davranış. GİRİŞ
  sonucu (daire = o cinsiyetin toplam girişi) DEĞİŞMEDİ. Hedef tip BÜYÜK harfle
  karşılaştırılır — `'kadin'.toLocaleUpperCase('tr-TR')` = `'KADİN'` (noktalı İ).
- Test: `php scripts/pdks_ortak_cikis_smoke.php` ·
  `php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html` →
  `node scripts/pdks_ortak_cikis_smoke.js`.

### Kiosk düzeltmeleri (v293)

- **Mesaiyi Kapat = PENCERE** (`#giCloseConfirmSec.pdks-kapat-ovl`, z 600): bulunulan
  ekranın (mod ekranı / kapatılmamış mesai penceresi) ÜSTÜNDE açılır; mesai özeti +
  kontrol listesi, en altta **✅ Onayla**. `ekranGoster()` ile AÇILMAZ (`hidden=false`);
  ekran değişince yine kapanır. Odak düğmeye DEĞİL pencereye verilir — USB okuyucunun
  Enter'ı mesaiyi kapatmasın. Esc/dış tık/Vazgeç = `kapatmadanVazgec()`; istek sürerken
  (`kapatSuruyor`) vazgeç ve çift dokunma engelli. Kapatma yine yalnız `kapat()` → `?ajax=kapat`.
- **Kadın ⇄ Erkek hızlı geçiş** (`#giTipGecis`): GİRİŞ taramasında seçili tip KADIN ise
  "ERKEK GİRİŞ", ERKEK ise "KADIN GİRİŞ"; aynı `modSec('GIRIS')` yolu, yalnız tip değişir.
  Tip butonları `data-gi-tip-kod` taşır. ÇIKIŞ ve ortak modda gizli (`tipGecisGuncelle()`).
- **Sonuç süresi:** başarı 3 sn (`SONUC_OK_MS`), hata 5 sn (`SONUC_HATA_MS`), eksik-çıkış
  uyarılı başarı 8 sn.
- Test: `php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html` →
  `node scripts/pdks_kiosk_v293_smoke.js`.

### Geçmişe Dönük Çalışma Ekle (v291)

Unutulan girişi/çıkışı sonradan, raporda görünür biçimde eklemek. Mesai Detayı'nda
"➕ Çalışma Ekle", Günlük Puantaj listesinde "➕ Geçmişe Dönük Çalışma Ekle"
(bugünde dünün listesine `?ekle=1` bağlantısı).

- **YALNIZ admin** (`pdks_faz8j_yetki()`), aktif depo kapısı, zorunlu sebep, audit `puantaj_ekle`.
- **İkinci kart-okutma yolu DEĞİL:** `pdks_faz8j_gecmis_ekle()` (config/pdks_faz8j.php)
  `kaydet`/`cikis_kaydet` fonksiyonlarını ÇAĞIRMAZ; GİRİŞ+ÇIKIŞ olaylarını `source='manual'`,
  dönemi `status='closed'`, `source='manual'` yazar (raporda "✍ Elle eklendi"). Tek transaction.
- Çavuşun o gün mesaisi yoksa YALNIZ geçmiş gün için doğrudan KAPALI mesai oluşturur;
  bugün için oluşturmaz (kartı okutarak aç). Kesinleşmiş hakediş varsa reddeder (önce yeniden aç);
  taslak hakedişe `needs_recalculation=1`. Çıkarma = mevcut "Kaydı İptal Et".
- Kurallar: giriş günü = mesai günü, çıkış > giriş, ≤ 24 sa, gelecek yok, çakışan aktif dönem yok.
- Form partial'ı `_puantaj_ekle.php` (fonksiyon tanımlamaz). Migration YOK.
- Test: `php scripts/pdks_gecmis_ekle_smoke.php` · `node scripts/pdks_gecmis_ekle_smoke.js` ·
  `php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html` → `node scripts/pdks_puantaj_dialog_smoke.js`.


### Kartsız Mesai + Toplu İşlem (v294)

Tekli "Çalışma Ekle"ye **Kartsız mesai** seçeneği; Mesai Detayı ve Günlük Puantaj'a
**👥 Toplu İşlem** (Kadın/Erkek grupları: giriş/çıkış + boş kart seçimi + kartsız sayı).
YALNIZ admin, CSRF, audit. Migration YOK.

- **Kartsız = kayıt başına SANAL kart** (`pdks_faz8j_kartsiz_kart_olustur()`): `card_no`
  `KARTSIZ-` + 6 haneli **satır id'si** (sıra sayacı YOK — eşzamanlılıkta çakışırdı),
  okutulamaz `canonical_uid` (`KARTSIZ`+hex), `status='disabled'`, `enrolled_source='kartsiz'`
  (`pdks_faz8j_kartsiz_mi()`). Puantaj/hakediş/cari/rapor onu normal dönem gibi sayar —
  tüketicilere kartsız kodu EKLEME. Kart Havuzu'nda gizli, durum değiştirilemez,
  "fiziksel kart" metriğine girmez, `pdks_faz8j_duzelt()` kartını değiştirmez. Çıkış zorunlu.
- **Tek yazma yolu:** `pdks_faz8j_satir_kontrol()` (salt okunur) + `pdks_faz8j_satir_yaz()`
  (olay/dönem INSERT'ünün TEK yeri); oturum `pdks_faz8j_oturum_coz()`. Tekli ve toplu ikisi de
  bunları çağırır — kopya INSERT yazma.
- **Bugün kuralı (tekli + toplu):** açık mesai kullanılır; kapalıysa red; yoksa kiosk yolu
  `pdks_gunluk_oturum_ac_veya_getir()` ile AÇILIR (önceki gün açık mesai kuralı geçerli).
  Bugün kartlı satırda çıkış boş bırakılabilir → AÇIK dönem (kiosk kart kuralları). Geçmiş
  günde mesai yoksa KAPALI mesai yaratılır (pasif çavuş reddedilir).
- **Toplu:** `pdks_faz8j_toplu_onizle()` YAN ETKİSİZ (mesai/sanal kart yaratmaz);
  `pdks_faz8j_toplu_ekle()` HEP-YA-HİÇ, tek transaction, kartlar artan id sırasıyla kilitli,
  sınır `PDKS_FAZ8J_TOPLU_LIMIT` (250). Kaydet yalnız hatasız önizlemeden sonra açılır,
  girdi değişince kapanır. Önizlemedeki `uyarilar` (aynı saatlerde kartsız kayıt zaten var)
  ENGEL DEĞİL.
- **Çift gönderim koruması `istek_id`:** tekli form + toplu pencere tek kullanımlık anahtar
  gönderir; yazma transaction'ı önce mesai satırını kilitler (MySQL), sonra audit'te aynı
  `istek_id`'yi arar → varsa HİÇBİR ŞEY yazılmaz ("tekrar gönderim"). Kartlı satırı çakışma
  kontrolü korur ama KARTSIZI yalnız bu anahtar korur — kaldırma.
- **Toplu Geri Al:** `pdks_faz8j_toplu_listele()` / `pdks_faz8j_toplu_geri_al()` — batch
  (`toplu_id`, audit `puantaj_toplu_ekle`, modül `daily_work_sessions`, record_id = mesai id)
  içindeki aktif dönemleri tek seferde iptal eder (gerekçe zorunlu, kesin hakediş engeller).
  Sanal kartlar ve açılan mesai yerinde kalır.
- Dosyalar: `config/pdks_faz8j.php` · `_puantaj_ekle.php` · `_puantaj_toplu.php` ·
  `_puantaj_toplu_ajax.php` (`?ajax=toplu_onizle|toplu_ekle`, include-only, doğrudan 404).
- Test: `php scripts/pdks_toplu_ekle_smoke.php` · `php scripts/pdks_gecmis_ekle_smoke.php` ·
  `php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html` →
  `node scripts/pdks_puantaj_dialog_smoke.js` · `node scripts/pdks_gecmis_ekle_smoke.js` ·
  `node scripts/pdks_toplu_ekle_smoke.js`.


### Karışık Giriş + Otomatik Ata (v295)

Kiosk GİRİŞ tip ekranında **KADIN / ERKEK / KARIŞIK**. Karışık girenler sonra Mesai
Detayı'nda **🎲 Otomatik Ata** ile rastgele Kadın/Erkek'e atanır.

- **Tip satırı** `worker_types` KARISIK/"Karışık" (sahip GO verdi — YALNIZ veri satırı):
  `pdks_gunluk_migrate()` seed'i + `pdks_gunluk_karisik_tip_garanti()` (tembel, idempotent;
  pasifse yeniden AÇMAZ). Sabitler `PDKS_GUNLUK_KARISIK_KOD/_AD`.
- **Politika listesine EKLENMEZ:** `pdks_gunluk_desteklenen_tip_kodlari()` (fiyat, düzelt,
  tekli/toplu/kartsız ekleme) hâlâ yalnız KADIN/ERKEK. Kiosk GİRİŞ ayrı kapıdan geçer:
  `pdks_gunluk_giris_tip_kodlari()` / `_listele()` / `_coz()`. ÇIKIŞ tipi açık dönemden kopyalar.
- **Para kapısı (fail-closed):** `pdks_hakedis_karisik_engeli()` iki hakediş motorunda
  (8B + Faz 4) ve dolayısıyla finalize'da — mesaide atanmamış Karışık varsa hesap DURUR
  ("N Karışık kayıt atanmamış — önce Otomatik Ata"). `pdks_hakedis_karisik_oran_engeli()`
  iki `oran_ekle`'de Karışık fiyatını REDDEDER. Kaldırma: fiyat tanımlansa sessizce ödenirdi.
- **Atama** `pdks_faz8j_karisik_ata($sid,$kadin,$erkek,$reason,$istekId,$user)`: admin,
  aktif depo, `istek_id` + mesai kilidi, kesin hakediş red. Havuz = iptal edilmemiş Karışık
  dönemler (içeridekiler DAHİL); KISMİ serbest. `random_int` Fisher–Yates. YALNIZ dönemin
  `worker_type_id_snapshot`/`_name_snapshot` değişir — `pdks_faz8j_duzelt()` KULLANMA
  (Tam/Yarım + FM onayını sıfırlar); ham kart olayları DEĞİŞMEZ. Audit `karisik_ata`
  (modül `daily_work_sessions`, record_id = mesai id, `atama_id` KA…).
- **Geri Al** `pdks_faz8j_karisik_geri_al()`: yalnız hâlâ atandığı tipte olan dönemleri
  Karışık'a döndürür (elle değişeni/iptali atlar), bir kez; audit `karisik_geri_al`.
- Ekran: kiosk mor KARIŞIK düğmesi + rozet (Kadın⇄Erkek hızlı geçiş gizli), Mesai Detayı
  bildirim kartı + Otomatik Ata/Geri Al pencereleri + `.pdks-badge-karisik`, Günlük
  Puantaj'da "🎲 N Karışık" ipucu, toplu dökümde Karışık sayısı (Kadın+Erkek+Karışık=Toplam).
  Günlük Puantaj CSV/XLSX'e sütun EKLENMEDİ (CSV kuralı).
- Test: `php scripts/pdks_karisik_smoke.php` · `node scripts/pdks_karisik_smoke.js`.

### Liste Ergonomisi (v296)

- **Otomatik filtre:** Personel Takibi sayfalarındaki GET filtre formları `data-oto-filtre`
  taşır; seçim/tarih değişince kendiliğinden gönderilir (arama kutusu 400 ms gecikmeli /
  Enter; klavyeyle yazılan tarih 900 ms ya da odak kaybı). "Filtrele" düğmesi yalnız
  `<noscript>` içinde. POST formlarına uygulanmaz. Mekanizma TEK yerde:
  `config/pdks_liste_ui.php` (`pdks_gunluk.php` yükler) → sayfa `render_footer()`'dan önce
  `pdks_liste_ui_js()` çağırır. Yeni filtre formu eklerken `data-oto-filtre` ver, ayrı script YAZMA.
  "Özel" dönem seçimi tarihleri bekler (`data-oto-filtre-bekle`).
- **Tıklanabilir satır:** yalnız "Detay" içeren İşlem sütunu yerine `tr.pdks-satir-link[data-href]
  tabindex="0"` (tık / Enter; Ctrl/Cmd/orta tık yeni sekme; iç bağlantı/düğmeler etkilenmez) +
  birincil hücrede gerçek `<a>`. Başka eylemi olan satırlar (Düzenle, İptal…) düğmelerini korur.
- **Toplu döküm gün tonları:** `ctd-g0/ctd-g1` (tarihe göre dönüşümlü) + `ctd-gyeni` (grup başı
  kalın çizgi); yazdırmada `print_pdks.css`.
- **Çalışma Ekle penceresi** yeni görünüm (`_puantaj_ekle.php` + `pdks.css` v296-B bloğu): alan
  adları/id'ler/gizli alanlar DEĞİŞMEDİ.
- Test: `node scripts/pdks_oto_filtre_smoke.js` (önce `PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php
  scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html` ve `PUANTAJ_SAYFA=toplu …
  > _test_toplu_dokum.html`) · `php scripts/pdks_v296b_smoke.php` · `node scripts/pdks_v296b_smoke.js`.

### Görünüm + Değişken B Birimi (v297)

- **Yöntem B birimi çavuş bazında** (sahip GO verdi — TEK şema değişikliği):
  `foreman_rate_method_log.unit_size INT NULL` (NULL = 25, `PDKS_FAZ8B_CAVUS_B_BIRIM`).
  `cavus_fiyatlari.php` Yöntem kutusunda "Kaç kişi-gün = 1 hakediş" (1–1000); kaydetmek yeni
  zaman damgalı log satırı yazar (geriye dönük DEĞİL). Kapanış anındaki birim
  (`pdks_faz8b_cavus_ucret_birim_anda()`) `foreman_period_closures.unit_size`'a DONAR; eski
  kapanışlar değişmez, devir kişi-gün olarak kalır ve yeni birime bölünür. Kolon yoksa 25 dışı
  değer reddedilir (`birim_kolonu_yok`). Ekstre metnine birim yalnız ≠25 ise eklenir.
  Test: `php scripts/pdks_cavus_b_birim_smoke.php`.
- **Çavuş Ücretleri sayfası** yeni kart tasarımı (`.cf2-*`, pdks.css v297-A); form adları değişmedi.
  Test: `CAVUS_FIYAT_SENARYO=dolu|bos php scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat[_bos].html`
  → `node scripts/pdks_cavus_fiyat_smoke.js`.
- **Toplu İşlem penceresi** yeni tasarım (`.toplu-v2`); kart listesi grup başına
  "Kartlı giriş ekle" ile açılır (kapatınca seçim temizlenir). Payload değişmedi.
- **Kiosk tip renkleri:** GİRİŞ taramasında `#giScanSec.pdks-tip-kadin|erkek|karisik` (pembe/mavi/mor
  çemberler, `tipRenkAyarla()` — tip KODU ile); başarı sonucu `pdks-sonuc-*` aynı renkte (bilinmeyen
  tip yeşil, hata kırmızı). Test: `node scripts/pdks_kiosk_renk_smoke.js`.
- **Gerçek düğme görünümü:** `.btn-ghost` artık dolgulu+çerçeveli; geri bağlantıları `.btn-geri`
  (mavi, sayfa başlıklarında). Zeminle aynı renkte düz yazı düğme YAZMA.
  Test: `node scripts/buton_gorunum_smoke.js`.

### Tanımlı Giriş (v298)

Kart Havuzu'nda (`isci_kartlari.php`, "🏷 Tanım") karta ÇAVUŞ + TİP (yalnız KADIN/ERKEK,
`pdks_gunluk_desteklenen_tip_coz`) + DEPO (= aktif depo, istemciden alınmaz) tanımlanır; kioskta
ORTAK ÇIKIŞ'ın üstündeki **"🏷 TANIMLI GİRİŞ"** ile okutulan kart tanımlı çavuşun BUGÜNKÜ mesaisine
o tiple girer. Çavuş → tip → kart akışı AYNEN kalır. Çıkış = mevcut ORTAK ÇIKIŞ (değişiklik yok).

- **Tablo `worker_card_assignments`** (sahip GO verdi — YALNIZ yeni tablo, ALTER YOK). Kart başına
  TEK aktif tanım: `aktif_kart_id` (aktifken = kart id, bitince NULL) üzerinde UNIQUE (chain_key
  deseni); geçmiş SİLİNMEZ (`valid_to`/`ended_by_user_id`/`end_reason`). Kendi üçlüsü
  `pdks_gunluk_kart_tanim_tablolar/_migrate/_sema_hazir` — `pdks_gunluk_tablolar()`'a,
  `pdks_gunluk_sema_hazir()`'e, `pdks_gunluk_faz8a_sema_hazir()`'e BİLEREK EKLENMEZ (Çavuş Ücreti
  emsali). Tablo yoksa özellik GİZLİ, tanım okuması null ("tanım yok"). Kurulum yalnız `migrate.php`
  kartı; ekranlarda migrate ÇAĞRILMAZ.
- **İkinci yazma yolu YOK:** `pdks_gunluk_tanimli_giris_kaydet()` (config/pdks_gunluk.php) tanımı +
  mesaiyi BULUR — gövdesinde INSERT/UPDATE/DELETE yok (test denetler). Tanımsız kart otomatik
  KAYDEDİLMEZ (`kart_tanimsiz`). Salt okunur ön kontroller (kart durumu, açık dönem, aynı gün eksik
  çıkış, tip) başarısız okutmanın boş mesai açmasını engeller; sonra kiosk yolu
  `pdks_gunluk_oturum_ac_veya_getir()` (önceki gün açık mesai / kapalı mesai / pasif çavuş kuralları
  aynen) ve `pdks_gunluk_faz8a_giris_kaydet(..., 'tanimli')`. Dönem `source='tanimli'` (yalnız köken;
  olay tablosu DEĞİŞMEZ) — hakediş/cari/rapor buna göre DALLANMAZ, dallanma EKLEME. Mesai Detayı'nda
  "🏷 Tanımlı" rozeti; CSV/XLSX'e sütun YOK.
- **Engel kuralı (normal ekran):** `pdks_gunluk_faz8a_giris_kaydet()` kart KİLİDİNDEN SONRA tanımı okur;
  mesainin çavuşu / seçilen tip / mesainin deposu tanımla uyuşmazsa `kart_baska_tanimli` ("Bu kart
  Çavuş A / Kadın'a tanımlı…"). Kural TEK yerde: `pdks_gunluk_kart_tanim_engeli()`. Admin'in elle/toplu
  eklemesi (Faz 8J) v303'ten beri BAŞKA çavuşa/depoya tanımlı kartı REDDEDER (`pdks_faz8j_tanim_kart_engeli()`);
  aynı çavuş farklı tip yalnız uyarı (`pdks_faz8j_tanim_uyarilari()`). Bkz. "Kart Havuzu (v303)".
- **Depo:** tanımlı ekranda kart başka depoya tanımlıysa `tanim_baska_depo`. Karşılaştırma TR-duyarsız
  (`pdks_gunluk_depo_fold`).
- **Tanım yazma** `pdks_gunluk_kart_tanim_kaydet()` / `_bitir()`: transaction + `pdks_gunluk_faz8a_kart_kilitle()`
  (girişle AYNI kilit), kartsız sanal kart / pasif çavuş / Karışık / boş depo reddi, UNIQUE 23000 →
  "eşzamanlı işlem". Kart içerideyse ENGEL DEĞİL, `uyari` (açık dönem eski çavuşta kalır). Audit
  `kart_tanim` / `kart_tanim_bitir` (modül `worker_cards`). Yeni yetki YOK: yazma = Kart Havuzu kapısı,
  okutma = `attendance.daily_scan`. Kayıp/devre dışı kart ve pasifleşen çavuşun tanımı kendiliğinden
  BİTMEZ — kiosk mevcut kodlarla reddeder, listede uyarı rozeti.
- **Kiosk** `?ajax=tanimli_giris` (CSRF + `daily_scan`; istemciden YALNIZ `ham_uid` + `kaynak`).
  `tanimliMod`: aynı UID 3 sn içinde istemcide yok sayılır, `mukerrer_giris` → "Zaten giriş yapıldı"
  bilgisi, `onceki_mesai_acik` → düğmeyle mevcut `eskiPencereAc()` (otomatik açma yok; kapatma yine
  `?ajax=kapat`), kapatınca/"Sonra" Tanımlı moda dönülür. Hızlı geçiş ve Mesaiyi Kapat bu modda yok.
- Test: `php scripts/pdks_tanimli_giris_smoke.php` · `php scripts/pdks_tanimli_giris_render.php >
  _test_tanimli_giris.html` → `node scripts/pdks_tanimli_giris_smoke.js` · `php
  scripts/pdks_kart_tanim_render.php > _test_kart_tanim.html` → `node scripts/pdks_kart_tanim_modal_smoke.js`.

### Seri Kart Tanımla (v299)
Kart Havuzu "⚡ Seri Kart Tanımla": çavuş + tip (`pdks_gunluk_desteklenen_tip_listele`) seçilir, kartlar art arda okutulur (USB / sürekli NFC), liste kontrol edilip TEK Kaydet ile hepsi tanımlanır. Yeni kart otomatik havuza (nötr, kart no sıradan). Tablo/Faz 8A/aktif depo yoksa düğme GİZLİ. Yeni yetki YOK (kapı `worker_cards`), migration YOK.
- **HEP-YA-HİÇ:** `pdks_gunluk_kart_tanim_toplu_kaydet()` tek tx; HATA satırı varsa hiçbir şey yazılmaz. Kilit altında `..._toplu_degerlendir()` yeniden çağrılır. Yazma yalnız MEVCUT `pdks_gunluk_kart_olustur()` + `pdks_gunluk_kart_tanim_kaydet()` — gövdede worker_cards/worker_card_assignments'a doğrudan INSERT/UPDATE YOK (test denetler); ikinci yazma yolu AÇMA.
- **Sınıflar** (`..._satir_sinifla`, saf): yeni · tanimlanacak · ayni (atlanır) · baska_cavus (ENGEL — "önce Tanımı Kaldır") · hata (geçersiz UID, aktif kalıcı kart, kayıp/devre dışı, kartsız sanal kart, yinelenen). Aynı çavuş farklı tip/depo → tanım güncellenir. Parti sınırı 100 (sunucu da denetler).
- **Uçlar:** GET `ajax=tanim_satir` (salt okunur, sınıf SUNUCUDAN döner, JS sınıflama kopyası YOK) · POST JSON `ajax=tanim_toplu_kaydet` (CSRF + `worker_cards`; depo aktif depodan, istemciden ALINMAZ).
- **Tekrar gönderim:** `istek_id` özet audit `kart_tanim_toplu`'da aranır (JSON_THROW doğrudan INSERT; `audit_log_event` hata yutar). MySQL'de `GET_LOCK('pdks_kart_tanim_toplu')` tx'in ilk işi — kaldırma. Kart içerideyse engel değil, `uyarilar`.
- **UI:** `#iskSeriModal` = başlık / kayan gövde / sabit alt çubuk, `<form>` SARILMAZ (`.pm-dialog > form` kuralı kalsın). pdks.css "v299" bloğu.
- Test: `php scripts/pdks_kart_toplu_tanim_smoke.php` · `php scripts/pdks_kart_toplu_render.php > _test_kart_toplu.html` → `node scripts/pdks_kart_toplu_smoke.js`.
- **v301 — tekli "Yeni Kart Tanımla" KALDIRILDI** (sahip kararı): kart ekleme/tanımlamanın TEK yolu Seri Kart Tanımla. Sayfadan `kart_ekle` POST dalı, `ajax=onizle` ucu ve `$onerilenKartNo` gitti; `pdks_gunluk_kart_olustur()` / `pdks_gunluk_sonraki_kart_no()` Seri Kart'ın toplu kaydı için KALIR, `assets/pdks.js` `data-pdks-scan` yardımcısı `personel_kartlar`/`personel_form` için KALIR — silme. `$seriHazir` false (tablo/Faz 8A/aktif depo yok) iken sayfa `#iskSeriYok` ile nedenini + migrate.php yönlendirmesini söyler; o durumda kart eklenemez. Düzenle/Durum/Tanım modalları ve Kart Sorgula DEĞİŞMEDİ. Testler bölümün YOKLUĞUNU denetler (kaynakta "Yeni Kart Tanımla" / `kart_ekle` geçmemeli — yorumda bile).

### Seri Tanım Kaldır (v364)
Kart Havuzu "🧹 Seri Tanım Kaldır" (Seri Kart Tanımla'nın yanında, aynı `$seriHazir` koşuluyla gizli): kartlar art arda okutulur
(USB / sürekli NFC), liste (kart no · çavuş · tip · depo) kontrol edilir, opsiyonel not, TEK "Tanımları Kaldır" (onay penceresi) ile
hepsinin AKTİF DEPODAKİ tanımı biter. Kart havuzda nötr kalır, hiçbir şey SİLİNMEZ (geçmiş satırı `valid_to` + `aktif_kart_id=NULL`).
Yeni yetki YOK (kapı `worker_cards`), migration YOK.
- **Sınıflar** (`pdks_gunluk_kart_tanim_kaldir_satir_sinifla()`, saf): kaldirilacak · tanimsiz (atlanır) · baska_depo (ENGEL) · hata
  (geçersiz UID, havuzda yok, kartsız sanal kart, yinelenen). Kayıp/devre dışı kartın tanımı da kaldırılır. İçerideki kart = `uyari`
  (engel değil). Sınıf SUNUCUDAN: GET `ajax=tanim_kaldir_satir` (`pdks_gunluk_kart_tanim_kaldir_satir()`, salt okunur) — JS kopyası YOK.
- **Yazma** POST JSON `ajax=tanim_kaldir_toplu` → `pdks_gunluk_kart_tanim_kaldir_toplu($satirlar, $reason, $istekId, $userId, $pdo)`:
  depo = `active_depot()` (istemciden ALINMAZ), HEP-YA-HİÇ tek tx, MySQL'de Seri Kart ile AYNI `GET_LOCK('pdks_kart_tanim_toplu')` tx'in ilk
  işi, kartlar artan id ile kilitli, kilit altında yeniden değerlendirme; engel varsa hiçbir şey yazılmaz. Her kart MEVCUT
  `pdks_gunluk_kart_tanim_bitir()` ile kapanır (dış tx'e katılır, erken commit yok; kart başına audit `kart_tanim_bitir`) — gövdede
  worker_card_assignments'a doğrudan UPDATE YOK (test denetler). Varsayılan gerekçe "Seri tanım kaldırma". `istek_id` özet audit
  `kart_tanim_kaldir_toplu`'da aranır (`..._toplu_istek_kayitli/_audit`'e `$action` parametresi eklendi). Sınır 100.
- **UI:** `#iskSeriKaldirModal` (`.isk-seri .isk-kaldir`; başlık / kayan gövde / sabit alt çubuk, `<form>` SARILMAZ), sayaçlar
  Kaldırılacak / Atlanacak / Engel; pdks.css "v364" bloğu.
- Test: `php scripts/pdks_kart_tanim_kaldir_smoke.php` · `php scripts/pdks_kart_toplu_render.php > _test_kart_toplu.html` →
  `node scripts/pdks_kart_toplu_smoke.js` (L bölümü).

### Kart Havuzu — Tanımlı/Tanımsız Ayrımı + Seçerek Silme (v303)
Sahip kararları: normal kiosk girişi DEĞİŞMEDİ (tanımlı kart kendi çavuşu+tipiyle normal ekrandan girebilir);
silme YALNIZ seçerek; geçmişsiz kart DELETE, geçmişli kart ARŞİV; elle eklemede tanımlı kart yalnız kendi çavuşunda.
Migration YOK, yeni yetki YOK.
- **Ekran** (`isci_kartlari.php`, pdks.css "v303"): Toplam · 🏷 Tanımlı · Tanımsız sayaçları (+ Arşiv, Kayıp) —
  `pdks_gunluk_kart_havuzu_ozet()` (TEK sayım kaynağı; kartsız HARİÇ; toplam = disabled olmayanlar). "Tanımlı Kartlar"
  özeti çavuş → tip → adet; çavuş adı `?tanim=tanimli&cavus=ID`, tip çipi `&ttip=TIPID`. Filtre: arama · tanim · cavus ·
  ttip (TANIM tipi) · durum. Eski legacy `tip` filtresi ve "Tip (eski)" sütunu KALDIRILDI. Varsayılan liste arşivi
  (`disabled`) GİZLER. Durum rozeti: tanımlı+available → "🏷 Tanımlı" ("Boşta" YALNIZ tanımsız kart).
- **Seçerek silme** (yalnız `is_admin()`, yalnız tanımsız satırda kutu): POST `action=tanimsiz_sil` →
  `pdks_gunluk_kart_tanimsiz_sil()`: HEP-YA-HİÇ tek tx, kartlar `pdks_gunluk_faz8a_kart_kilitle()` ile kilitli, istek_id
  (audit `kart_toplu_sil`), sınır `PDKS_GUNLUK_KART_SIL_LIMIT`. Ret: tanımlı ("önce Tanımı Kaldır") · içeride · kartsız ·
  yok. Geçmiş = `pdks_gunluk_kart_gecmis_tablolari()` (worker_cards'a FK veren TÜM tablolar — yeni FK eklersen BURAYA
  ekle; unutulursa MySQL 1451 → tüm işlem geri alınır). Geçmişli → `status='disabled'` + notes'a "[Arşiv tarih] gerekçe";
  geçmişsiz → DELETE. Sorgu hatası → arşiv (fail-closed). audit_log geçmiş SAYILMAZ. Audit `kart_sil`/`kart_arsiv`
  tx içinde doğrudan INSERT. Arşivlenen fiziksel kart Seri Kart'ta "devre dışı" hatası verir — Düzenle'den Boşta'ya alınır.
- **Elle ekleme kısıtı:** `pdks_faz8j_bos_kartlar($gun, $pdo, $foremanId, $depo)` satırlara `tanim_foreman_id`/`tanim_depo`
  ekler; Mesai Detayı çavuş+depo ile süzer (`pdks_faz8j_kart_tanim_suz()`; Düzenle listesinde dönemin mevcut kartı her
  zaman kalır), Günlük Puantaj listesi depo ile süzer ve `_puantaj_ekle.php`/`_puantaj_toplu.php` JS'i seçilen çavuşa göre
  `data-tanim-foreman` ile gizler. Sunucu: `pdks_faz8j_satir_kontrol(..., $hedef)` ve `pdks_faz8j_duzelt_satir()` (yalnız
  kart DEĞİŞİYORSA) → `pdks_faz8j_tanim_kart_engeli()` TEK kural. Toplu düzelt / saat-tip düzeltme engellenmez.
- Test: `php scripts/pdks_kart_havuzu_smoke.php` · `node scripts/pdks_kart_havuzu_smoke.js` (render'ı kendisi çağırır;
  `KH_QS`, `KH_NONADMIN=1`) · `php scripts/pdks_tanimli_giris_smoke.php` · `PUANTAJ_TANIM=1 php scripts/pdks_puantaj_dialog_render.php`.


### Rampacı İşçi Tipi (v299)
Kadın/Erkek yanında 3. SABİT sistem tipi: `worker_types` RAMPACI/"Rampacı" (sahip GO verdi — yalnız veri satırı, kolon yok).
- Politika `pdks_gunluk_desteklenen_tip_kodlari()` = KADIN, ERKEK, RAMPACI → fiyat, düzeltme, kartsız/toplu ekleme, tanımlı giriş, seri tanım, hakediş, Yöntem B havuzu (sahip kararı: sayılır) tip id ile OTOMATİK kapsar. Karışık politikada DEĞİL; Otomatik Ata yalnız Kadın/Erkek'e atar. Fiyatı olmayan çavuşta Rampacı'lı mesai hakedişi "geçerli fiyat yok" ile durur (fail-closed).
- Satır: migrate seed (sort_order 4, mevcut satırlara dokunulmaz) + tembel idempotent `pdks_gunluk_rampaci_tip_garanti()` (pasifse yeniden AÇMAZ; `desteklenen_tip_listele()` ve isci_tipleri çağırır). Görüntü sırası kodda: Kadın, Erkek, Rampacı, Karışık.
- TEK KAYIT: `pdks_gunluk_tip_kayit()` (kod → ad, kısa, sayaç etiketi, renk son eki kadin/erkek/rampaci/karisik, `sutun`, `sistem`). Ekran+yazdırma rapor sütunları/kutuları (`pdks_gunluk_tip_sistem_sutunlari()`), `pdks_rapor_cavus_toplu_dokum()` SQL sütunları ve kiosk renkleri (`TIP_KAYIT` JSON) bundan DÖNGÜYLE üretilir — elle 'Kadın'/'Erkek' sütunu YAZMA. Yeni tip = kayıt + CSS (`pdks-kiosk-typebtn-/pdks-tip-/pdks-sonuc-/pdks-kiosk-type-badge-/is-<renk>`, `tv-grup-<renk>`).
- Rampacı sütunu 0 olsa da görünür; Karışık yalnız atanmamış kayıt varsa. CSV/XLSX'e sütun EKLENMEZ (sahip kararı).
- Kiosk: turuncu RAMPACI düğmesi/çember/sonuç/rozet; Kadın⇄Erkek hızlı geçiş Rampacı'da gizli; ÇIKIŞ "kalan" dairesi Karışık hariç tüm sistem tipleri için.
- Test: `php scripts/pdks_rampaci_smoke.php` · `node scripts/pdks_kiosk_renk_smoke.js` (render: `php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html`).
- **v463:** Fiş Excel'inde Rampacı artık saat bazlı Tam/Yarım değil **yevmiye adedi** (`yevmiye_adedi` kolonu, «Rampacı Kişi» + «Rampacı Yevmiye»); kiosk/Toplu İşlem/Çalışma Ekle'deki saat bazlı Rampacı AYNEN. Ayrıntı: Fiş Excel bölümü «v463 — Rampacı yevmiye».


### Görünüm (v301)

- **"← Personel Takibi" geri düğmesi TURUNCU** (tüm sayfalar, sahip kararı): `personel_takip.php`'ye giden bağlantı `class="btn btn-geri btn-geri-ptak"` taşır (`style.css`, açık + koyu tema; beyaz yazı AA için #c2410c). Diğer geri düğmeleri (`.btn-geri`) MAVİ kalır — turuncu yalnız bu hedefe. Yeni sayfa eklerken bu bağlantıya `btn-geri-ptak` ver (statik test denetler).
- **Filtre çubuğu tarih kutusu** (`.pdks-filter-bar input[type=date]`, pdks.css): ≥768px'te sabit genişlik (max 200px) — global `input[type=date]{width:100%}` Günlük Puantaj'da tarihi tüm satıra yayıyordu; mobilde tam genişlik kalır.
- Test: `php scripts/pdks_geri_ptak_smoke.php` · `PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html` → `node scripts/pdks_geri_ptak_smoke.js`.

### Kart Hareketleri — Seçerek Toplu Düzenle / İptal (v302)
Mesai Detayı "Kart Hareketleri"nde satırlar seçilir (masaüstü: kutu **Kart No hücresinin İÇİNDE** — ayrı sütun
DEĞİL, sıralama ve testleri `cells[0]` = Kart No'ya bağlı; mobil: kart başında) → "N seçili" çubuğu →
**✏ Seçilenleri Düzenle** (Hepsine uygula satırı + satır satır Tip / Giriş / Çıkış listesi) ya da
**🗑 Seçilenleri İptal Et**. Kapı tekil Düzenle ile AYNI: `is_admin() && $faz8jHazir && mesai deposu = aktif depo`
(`$tdAjaxKapi`). Migration YOK, yeni yetki YOK.
- **İkinci yazma yolu YOK:** tekil `pdks_faz8j_duzelt()` satır mantığı `pdks_faz8j_duzelt_satir()` çekirdeğine
  çıkarıldı (dönem UPDATE'inin TEK yeri); `pdks_faz8j_toplu_duzelt()` onu, `pdks_faz8j_toplu_iptal()`
  `pdks_faz8j_void_uygula()`'yı çağırır — toplu gövdelerde `UPDATE daily_worker_work_periods` YAZMA (test denetler).
- HEP-YA-HİÇ tek tx, mesai kilidi İLK sorgu, `istek_id` (audit `puantaj_toplu_duzeltme`/`puantaj_toplu_iptal`,
  modül `daily_work_sessions`, record_id = mesai; satır audit'i `toplu_id` taşır), kesin hakediş red, sınır
  `PDKS_FAZ8J_TOPLU_LIMIT`. Tüm satırlar denetlenir, hatalar satır satır döner (`hatalar[{period_id,card_no,hata}]`),
  biri bile hatalıysa hiçbir şey yazılmaz. Kart DEĞİŞMEZ; giriş günü = mesai günü (istemciden tarih alınmaz);
  çıkış < giriş → `exit_date` = ertesi gün ("+1 gün"). **Değişmeyen satır ATLANIR** (dakika düzeyinde; saniye korunur)
  — Tam/Yarım/FM onayları sıfırlanmaz. Hiç değişiklik yoksa `ok:false`.
- Uçlar `?ajax=toplu_duzelt|toplu_iptal` (`_puantaj_toplu_duzelt_ajax.php`, include-only, mesai id'si sunucudan).
  Pencereler `_puantaj_toplu_duzelt.php` (native dialog, fonksiyon tanımlamaz, kullanıcı verisi textContent),
  pdks.css "v302" bloğu.
- Test: `php scripts/pdks_toplu_duzelt_smoke.php` · `node scripts/pdks_toplu_duzelt_smoke.js` (kendi sayfasını
  `PUANTAJ_TOPLU_DUZELT=1 php scripts/pdks_puantaj_dialog_render.php` ile basar).


### Kart Hareketleri Sıralaması + Kapanış Notu (v299)

- **Sıralama:** Mesai Detayı "Kart Hareketleri" varsayılan sırası SUNUCUDA, yalnız `gunluk_isci_puantaj_detay.php`'de `usort`: son işlem (çıkış varsa çıkış, yoksa giriş) DESC, beraberlikte `period_id` DESC. Paylaşılan `pdks_gunluk_faz8a_oturum_donemleri()` SQL'i (ASC) Gün Sonu Fişi ile ortaktır, KRONOLOJİK kalır — ona sıralama ekleme.
- **Başlık sıralaması TEK yerde:** `config/pdks_liste_ui.php`. `<table data-pdks-sirala>` + `<th data-sirala="metin|sayi|zaman">` içinde GERÇEK `<button>` + hücrede ham `data-sirala-deger`; artan → azalan → varsayılan, boşlar her iki yönde sonda, metin `tr` doğal (K2 < K10), aktif `th`'de `aria-sort`. Mobil karşılığı `select[data-pdks-sirala-sec]` + `data-sd-*`. Yeni tabloda ayrı script YAZMA. Test: `PUANTAJ_FAZ8B=1 PUANTAJ_SIRALA=1 php scripts/pdks_puantaj_dialog_render.php > _test_sirala.html` + `PUANTAJ_SIRALA=1 PUANTAJ_KAPALI=1 … > _test_kapanis_notu.html` → `node scripts/pdks_sirala_smoke.js`.
- **Kapanış notu düzenleme:** `pdks_gunluk_oturum_not_guncelle()` (config/pdks_faz8h.php) — YALNIZ admin (sahip kararı), mesai aktif depoda, YALNIZ `status='closed'` (her tarih), gerekçe yok, boş → NULL, ≤1000 karakter, tek tx + `FOR UPDATE`, audit `daily_session_note_edit`. Hakediş/cari bu kolonu okumaz; hakediş tablolarına/`needs_recalculation`'a DOKUNMAZ (kesin hakedişte de çalışır) — gövdeye ekleme (test denetler). Ekran: kapalı mesaide "✏ Notu Düzenle" → native `<dialog id="kapanisNotu">`, POST `action=kapanis_notu` + CSRF, mesai id'si sunucudan. Test: `php scripts/pdks_kapanis_notu_smoke.php`.

### Ters Kart Takip (v354)
Bağımsız, paralel günlük işçi modeli: sabah yalnız çavuşun elinde KALAN kartlar okutulur, dağıtılanlar (UID bazında)
onayla birlikte SABİT giriş saatiyle açık dönem alır; akşam mevcut Ortak Çıkış. Normal kiosk/Tanımlı Giriş/Ortak Çıkış
AYNEN çalışır. Ayrıntı + sahip kararları: `docs/TERS_KART_TAKIP.md`.
- **Dosyalar:** `config/pdks_ters.php` (şema + tüm mantık) · `ters_kart.php` (hub) · `ters_kart_havuz.php` (A) ·
  `ters_kart_sayim.php` (B) · `ters_kart_cikis.php` (C) · `ters_kart_mutabakat.php` (D) · `assets/ters_kart.js` ·
  `pdks.css` "v354" `.tk-*` bloğu. Yeni yetki YOK (havuz `worker_cards`, sayım/çıkış `daily_scan`, mutabakat +`daily_reports`).
- **4 yeni tablo** (sahip GO — ALTER YOK): `ters_havuz_kart` (kart başına tek aktif üyelik, `aktif_kart_id` UNIQUE) ·
  `ters_cavus_ayar` (tarihli saat) · `ters_sayim` (`aktif_anahtar` UNIQUE = çavuş+gün+depo başına tek aktif sayım,
  `istek_id` UNIQUE) · `ters_sayim_kart` (onayda havuzun anlık görüntüsü). `pdks_ters_tablolar/_migrate/_sema_hazir`;
  genel sema_hazir'lere EKLENMEZ, kurulum yalnız migrate.php kartı. Tablo yoksa modül "kurulmamış" der, eski sistem aynen.
- **İkinci yazma yolu YOK:** giriş `pdks_faz8j_satir_kontrol` + `pdks_faz8j_satir_yaz` (meta `kaynak`='ters',
  `olay_zamani`=gerçek an, `audit_action`='ters_giris'; varsayılanlar eski davranış), mesai `pdks_faz8j_oturum_coz`,
  çıkış `pdks_gunluk_ortak_cikis_kaydet`, geri alma `pdks_faz8j_void_uygula`. pdks_ters.php dönem/olay/worker_cards
  tablolarına doğrudan yazmaz (test denetler).
- **Zamanlar:** dönem `entry_time` = sabit saat; GİRİŞ olayı `source='ters'`, `server_event_time` = gerçek kayıt anı;
  onay anı `ters_sayim.onay_zamani`. Sabit saat onaydan en çok 2 sa ileride (`PDKS_TERS_ILERI_DK`).
- **Onay HEP-YA-HİÇ**, kilit sırası: sayım FOR UPDATE → mesai FOR UPDATE → üyelik + kartlar (artan id) FOR UPDATE →
  `pdks_ters_sayim_degerlendir()` kilit altında yeniden. Bu kilitlerden ÖNCE kilitsiz okuma EKLEME (InnoDB anlık görüntüsü
  erken sabitlenir → kiosk girişini görmeyip çift açık dönem). Dönmemiş kart (son dönemi eksik çıkışlı) karar verilmeden
  onay ENGELLİ; bu çavuşun mesaisinde normal okutmayla içerideki kart `zaten_iceride` (ikinci dönem yok).
- **Kart sahipliği kapısı TEK yerde** `pdks_gunluk_ters_havuz_engeli()` (pdks_gunluk.php, tablo yoksa null): normal giriş
  (kart kilidi altında), tanım yazımı, Seri Kart sınıflaması, Kart Havuzu silme, `pdks_faz8j_tanim_kart_engeli`.
  `ters_havuz_kart` + `ters_sayim_kart` `pdks_gunluk_kart_gecmis_tablolari()`'nda.
- **Geri al** ilk çıkışa kadar (daily_scan, gerekçe); dönemler iptal (silinmez), okutmalar yeni taslağa kopyalanır.
- Mesai Detayı'nda `source='ters'` → "↩ Ters" rozeti. Hakediş/cari/rapor kaynağa göre DALLANMAZ — dallanma EKLEME.
- **v355 — TEK çıkış noktası = Ortak Çıkış** (sahip kararı): ayrı "Hızlı Çıkış" ekranı kalktı; `ters_kart_cikis.php`
  yalnız `gunluk_isci_giris_cikis.php?mod=ortak`'a yönlendirir (eski önbellekli sayfalar için POST `?ajax=cikis` aynı
  `pdks_gunluk_ortak_cikis_kaydet()`'i çağırır). Kiosk `?mod=ortak` ile doğrudan Ortak Çıkış modunda açılır.
  `pdks_gunluk_faz8a_cikis_kaydet()` yanıtı `card.entry_source` (scan/tanimli/manual/ters/legacy_backfill — yalnız bilgi)
  taşır; kiosk ÇIKIŞ sonucunda scan dışı kaynakta `.pdks-result-kaynak` satırı ("↩ Ters Kart · sabit giriş 08:00").
  Mutabakat çıkışı hangi ekrandan yapıldığına bakmaz (dönem tablosundan sayar). Ters sekmesi/hub "🚪 Ortak Çıkış".
  `?mod=ortak` + önceki günden açık mesai: ÖNCE #giEskiSec penceresi, "Sonra"/son mesai kapanınca Ortak Çıkış (`ortakDonus`, tek seferlik).
- **v355 — görünen ad "Giriş Tanımlı"** (sahip kararı; kod/URL/tablo adları `ters_*` KALDI). Personel Takibi kartı ikonu
  `assets/mobile-visual/giris-tanimli.svg` (`.pdks-icon-giris-tanimli`). ⚠ v298 "🏷 TANIMLI GİRİŞ" (kiosk, kart→çavuş tanımı)
  AYRI özelliktir — karıştırma.
- **"Çıkış girişten önce olamaz" kapısı YALNIZ `source='ters'`** (`pdks_gunluk_faz8a_cikis_kaydet`, kod `giris_saati_gelmedi`).
  Genelleştirme: v354'te tüm kaynaklara uygulanınca `pdks_faz8h_smoke` (sabit 08:00 girişli veri) sabah erken saatte düşüyordu.
- **v363 — Manuel Sayım** (şema YOK): taslakta "📇 Kartla Say" / "🔢 Manuel Sayı Gir". Manuel = elde kalan kart SAYISI;
  `ters_sayim.durum='manuel'` (onaylı mı → `pdks_ters_sayim_onayli_mi()`, `'onayli'` diye elle yazma), sabah DÖNEM YAZILMAZ.
  Akşam Ortak Çıkış `acik_donem_yok` dönerse `pdks_ters_ortak_cikis_manuel_yedek()` → `pdks_ters_manuel_cikis()` (satir_kontrol +
  satir_yaz, sabit giriş + gerçek çıkış, KAPALI dönem, `ters_sayim_kart.rol='manuel_cikis'`); en fazla limit = dağıtılan − zaten
  içeride, aşılırsa `manuel_limit`. `pdks_gunluk_ortak_cikis_kaydet()` DEĞİŞMEDİ. Geri al yalnız ilk çıkıştan önce. Okutulmayan
  kişi için dönem UYDURULMAZ. Ayrıntı: `docs/TERS_KART_TAKIP.md` "v363".
- Test: `php scripts/pdks_ters_kart_smoke.php` (A–M) · `node scripts/pdks_ters_kart_smoke.js` (render'ı kendisi üretir;
  tek render: `TK_SAYFA=<sayfa> php scripts/pdks_ters_kart_render.php`).
