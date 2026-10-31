# Fiş Excel'i Yükle (v446)

Kâğıt "İŞLETME İŞÇİ FİŞİ"ni dış bir yapay zekâyla (OCR) şablona döktürüp Excel'i yükleyerek fiş başına kartsız mesai kayıtlarını
(Tam / fazla mesai / Yarım) ve servisi yazar. Sayfa: Günlük Puantaj › **📥 Fiş Excel'i Yükle** (`gunluk_fis_yukle.php`). Yalnız yönetici.
Migration yok. Uygulama içinde OCR/AI yok; yüklenen dosya sunucuda saklanmaz.

## Kullanım
1. **Şablonu indir** (`Fis_Excel_Sablonu_YYYYMMDD.xlsx`; çavuş listesi o günün sistem verisidir).
2. Fiş fotoğraflarını sayfadaki **komutla** birlikte yapay zekâya verin; «Fişler» sayfasını doldurmasını isteyin. Belirsiz hücre BOŞ + «Kontrol Notu» dolu gelmelidir.
3. Dolu `.xlsx`'i yükleyin → **önizleme** (hiçbir şey yazılmaz): fiş başına gruplar, FM dağılımı, servis, mevcut kayıt durumu, hata/uyarılar.
4. Onay kutularını gözden geçirin (Yarım kararı ☑, FM onayı ☑, EK yaz ☐, işletme uyuşmazlığı ☐) → **Seçilenleri yaz**.
5. Hatalı satırları Excel'de düzeltip **aynı dosyayı** yeniden yükleyin: yazılmış fişler «zaten yüklü» atlanır, çiftlenmez.

## Düzeltme (yazılmış fiş)
Mesai Detayı › Toplu İşlemler › **Geri Al** (sonuç ekranı toplu işlem kimliğini gösterir) → Excel'de o fişin **Revizyon** değerini 1 artır → yeniden yükle.
Servis geri alınmaz; yeniden yüklemede servis çiftlenmez (yalnız eksik fark eklenir). Servisi düzeltmek için Mesai Detayı'ndaki servis iptali.

## Sütunlar
Tek kaynak `pdks_fis_sutunlar()` (config/pdks_fis.php); şablonun Açıklama sayfası ve başlık eşleme ondan üretilir. Başlıklar ada göre (TR-katlı, takma adlarla) eşlenir —
sütun sırası bozulsa da çalışır. Zorunlu: Fiş No, Tarih. **İşletme, Depo ve Çavuş Excel'de yoktur — yükleme sayfasında seçilir (bir dosya = tek çavuş).** Şablon sürümü: `PDKS_FIS_SABLON_SURUMU` (değişirse okuyucu başlık-ada-göre olduğundan geriye uyumludur).

## Kurallar (özet)
- Yalnız bugünden **önceki** günler · ≤150 fiş · ≤2 MB · yalnız .xlsx (makro / dış bağlantı içeren dosya reddedilir).
- Giriş boşsa 08:00. Tam çıkış = giriş + çavuşun o günkü normal süresi (tarihli süre dönemi / fiyat dönemi saatleri sınıflandırıcıdan çözülür); FM k saat = FM başlangıcı + k saat; Yarım = Tam'ın yarısı.
- **Yorum kuralları (v456):** «Erkek Tam» hücresinde `A+B` / `+B` (örn. `12+1`, `+2`) = **A Erkek + B Şeritçi** (Şeritçi tam gün); «+» **yalnız Erkek Tam'da** geçerlidir — Kadın / Rampacı / Şeritçi alanında «+» HATA'dır (tahmin yok, Kontrol Notu'na yazılır). Kadın / Erkek / Şeritçi **Tam** hücresinde ondalık `.5` (örn. `33,5`) = **33 Tam + 1 Yarım**; `.5` dışı ondalık HATA, Yarım sütununa yazılan ondalık HATA, Rampacı'da ondalık HATA. Aynı kişi iki yoldan sayılmaz: `33,5` ile aynı tipin «Yarım» sütunu birlikte doluysa ya da Erkek `+B` ile «Şeritçi Tam» birlikte doluysa HATA.
  **Eski «12 + 1 = 12 Tam + 1 Yarım» kuralı İPTAL EDİLDİ (v456, sahip kararı)** — fişte Erkek Yarım istiyorsanız «Erkek Yarım» sütununa yazın. Saf ayrıştırıcı: `pdks_fis_say_parca()`.
- **Ham Metin uyarısı (v456):** Ham Metin «Erkek A + B» gösteriyor ama «Erkek Yarım» dolu ve Şeritçi 0 ise (yapay zekâ eski komutla doldurmuş olabilir) önizlemede UYARI çıkar ve fiş varsayılan olarak SEÇİLİ gelmez (engel değil; otomatik düzeltme YOK). Çözüm: yapay zekâyı yeni komutla yeniden çalıştırın.
- **Şeritçi (v456):** «Şeritçi Tam» / «Şeritçi Yarım» sütunları (şablon sürümü 2; eski 24 sütunlu şablon hâlâ okunur). «FM Tipi» listesine `Şeritçi` ve `Erkek+Şeritçi` eklendi; Şeritçi «Tümü»/boş FM Tipi'ne GİRMEZ.
- Fişte **yalnız toplam fazla mesai saati** varsa «Toplam FM Saati» yolu kullanılır (kova sütunları boş; OCR komutu bunu açıkça söyler); FM fiyatları seçilen tipler arasında farklıysa toplam saat yolu kapalıdır (fail-closed).
- FM'ye girenler Tam kişilerden sayılır; Yarım'a FM verilmez. FM: kova sütunları (FM 1..6 Sa kişi) **ya da** «Toplam FM Saati» (+ ops. «FM Kişi Sayısı»; sabit tutarlı FM fiyatında zorunlu).
  Toplam saat dağıtımı deterministiktir (rastgele değil); FM fiyatları tipler arasında farklıysa kapalıdır → kova sütunları ya da tek «FM Tipi».
- Mesaide aktif kayıt varsa fiş durur («Mevcut kayıtlara EK yaz»). Kesin hakedişte hiçbir şey yazılmaz.
- Sözleşme: istek kimliği = depo + fiş no + revizyon (tarih/çavuş hariç).

## OCR komutu bakımı
Komut metni `pdks_fis_ocr_komutu()` içindedir (Açıklama sayfası ve yükleme sayfasındaki kutu aynı dizeyi kullanır). "YORUM KURALLARI" bölümü ("A+B" = Erkek + Şeritçi, ".5" = Tam + Yarım; "N kişi : M yarım" yorumsuz kalır → Kontrol Notu)
sahibin kararına göre güncellenecek TEK yerdir; kod mantığı bundan bağımsızdır.

## Hata mesajı sözlüğü (seçme)
| Mesaj | Anlamı |
|---|---|
| `Satır n, «Başlık»: "12 + 1" tek sayı değil` | Rampacı / Yarım sütununda birden çok sayı — ayrı sütunlara yazın |
| `«12+1» bu alanda belirsiz — «+» yalnız Erkek alanında Şeritçi anlamına gelir` | Kadın / Şeritçi alanında «+» yorumlanmaz; Kontrol Notu'na yazın |
| `Ondalık («.5») yazımı ile «… Yarım» sütunu birlikte kullanılamaz` | Aynı kişi iki kez sayılırdı — yalnız birini yazın |
| `Şeritçi hem «Erkek Tam» içindeki «+N» … çift sayım` | «+N» ya da «Şeritçi Tam» — yalnız birini kullanın |
| `Çavuş seçilmedi` | Yükleme sayfasında «Çavuş» alanını seçin |
| `bugün veya gelecek tarihli fişler desteklenmez` | Yalnız geçmiş günler |
| `Toplam FM dağıtımı yapılamaz` | FM fiyatı nötr değil/bilinmiyor → kova sütunları |
| `Mesaide N aktif kayıt var` | Önce Toplu Geri Al ya da «EK yaz» |
| `Önizlemeden sonra durum değişti` | Mesai/fiyat/ayar değişti → yeniden önizleyin |

## Test
`php scripts/pdks_fis_plan_smoke.php` · `pdks_fis_xlsx_smoke.php` · `pdks_fis_yaz_smoke.php` · `pdks_fis_static_smoke.php` · `node scripts/pdks_fis_ui_smoke.js`.

## v450 — Oturum kaybı dayanıklılığı
Önizleme/sonuç verisi oturumdan bağımsız olarak `hks_kv`'de de tutulur (2 saat sonra silinir; kullanıcı + depo + 30 dk kuralı aynı). Hata mesajı nedeni söyler: süre doldu / başka kullanıcı-depo / sunucuda bulunamadı. Ayrıntı: CLAUDE.md "Fiş Excel'i Yükle".

## v455 — Şeritçi tipi (W1)
- Dördüncü sabit sistem işçi tipi: **Şeritçi** (`SERITCI`; Rampacı emsali, yalnız `worker_types` veri satırı — şema yok). Kiosk, Toplu İşlem, Çavuş Ücretleri ve raporlar tip kayıt defterinden (`pdks_gunluk_tip_kayit()`) gelir.
- (v455'te Fiş Excel'inde Şeritçi sütunu yoktu; v456'da eklendi, yukarıya bakın.) sert-kodlu tip listesi artık kayıt defterine bağlıdır (`pdks_fis_tip_kodlari()`).
- **FM havuzu:** «Tümü» / boş FM Tipi Şeritçi'yi **kapsamaz** (`pdks_fis_fm_varsayilan_kodlar()`); Şeritçi FM'ye yalnız «FM Tipi» hücresinde `Şeritçi` / `Erkek Şeritçi` yazılırsa girer.
- **Fiyatsız tip uyarısı:** önizlemede bir tipin o tarihte geçerli fiyatı yoksa ««Şeritçi» için gg.aa.yyyy tarihinde geçerli fiyat YOK — kayıt yazılır, fiyat girilene dek hakediş hesaplanamaz.» uyarısı çıkar (engel değil; hakediş fail-closed durur).
- **Geçmiş tarihli fiyat:** Şeritçi için önce en eski fiyatı geçmiş tarihle girin, sonra güncel fiyatı yeni tarihle ekleyin. Yanlış tarihle açılmış ilk dönem Çavuş Ücretleri › Fiyat Geçmişi › «⏪ Başlangıcı geriye çek» ile (yalnız yönetici, gerekçeli, kesin hakedişli satırı olan aralıkta reddedilir) düzeltilir; etkilenen taslak hakedişler yeniden hesaba işaretlenir.
