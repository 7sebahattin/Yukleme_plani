# Fiş Excel'i Yükle (v446)

Kâğıt "İŞLETME İŞÇİ FİŞİ"ni dış bir yapay zekâyla (OCR) şablona döktürüp Excel'i yükleyerek fiş başına kartsız mesai kayıtlarını
(Tam / fazla mesai / Yarım) ve servisi yazar. Sayfa: Günlük Puantaj › **📥 Fiş Excel'i Yükle** (`gunluk_fis_yukle.php`). Yalnız yönetici.
Migration yok (tek istisna v463: Rampacı yevmiye kolonu — aşağıdaki «v463» bölümü). Uygulama içinde OCR/AI yok; yüklenen dosya sunucuda saklanmaz.

## Kullanım
1. **Şablonu indir** (`Fis_Excel_Sablonu_YYYYMMDD.xlsx`; çavuş listesi o günün sistem verisidir).
2. Fiş fotoğraflarını sayfadaki **komutla** birlikte yapay zekâya verin; «Fişler» sayfasını doldurmasını isteyin. Belirsiz hücre BOŞ + «Kontrol Notu» dolu gelmelidir.
3. Dolu `.xlsx`'i yükleyin → **önizleme** (hiçbir şey yazılmaz): fiş başına gruplar, FM dağılımı, servis, mevcut kayıt durumu, hata/uyarılar.
4. Onay kutularını gözden geçirin (Yarım kararı ☑, FM onayı ☑, EK yaz ☐, işletme uyuşmazlığı ☐) → **Seçilenleri yaz**.
5. Hatalı satırları Excel'de düzeltip **aynı dosyayı** yeniden yükleyin: yazılmış fişler «zaten yüklü» atlanır, çiftlenmez.

## Düzeltme (yazılmış fiş)
**En kolay yol (v464): «Üstüne yaz»** — aşağıdaki «v464 — Fiş düzeltme» bölümü. Elle yol: Mesai Detayı › Toplu İşlemler › **Geri Al** (sonuç ekranı toplu işlem kimliğini gösterir) → Excel'de o fişin **Revizyon** değerini 1 artır → yeniden yükle.
Servis Mesai Detayı'ndan geri alınmaz; yeniden yüklemede servis çiftlenmez (yalnız eksik fark eklenir). Servisi düzeltmek için Mesai Detayı'ndaki servis iptali (Üstüne yaz ve «Fiş yüklemelerini geri al» fiş etiketli servisleri de iptal eder).

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
| `«Rampacı Tam/Yarım» eski şablona aittir …` | v463: eski şablondaki Rampacı Tam/Yarım DOLU — yeni şablonu indirip «Rampacı Kişi» + «Rampacı Yevmiye» kullanın |
| `Rampacı yevmiye adedi yok («Rampacı Kişi» = N)` / `Rampacı kişi sayısı yok` / `yevmiye adedi (M) kişi sayısından (N) az olamaz` | Kişi ve yevmiye sütunları birlikte doldurulur; yevmiye ≥ kişi |
| `Rampacı: tek kişiye N yevmiye düşüyor (en çok 10) — OCR hatası şüphesi` | Sayıları fişten kontrol edin |
| `Rampacı yevmiye alanı kurulmadı (migrate.php › «Rampacı yevmiye alanı»)` | Yönetici Şema Migrasyon kartını bir kez basar; basılana dek yevmiyeli Rampacı fişleri yazılmaz (diğerleri yazılır) |
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

## v463 — Rampacı yevmiye (W3)
- **Sütunlar:** «Rampacı Tam» / «Rampacı Yarım» şablondan KALKTI; yerine **«Rampacı Kişi»** (kaç kişi) ve **«Rampacı Yevmiye»** (toplam yevmiye adedi). Şablon sürümü 3, 26 sütun. Eski şablon hâlâ okunur: eski Rampacı sütunları DOLUYSA fiş **hata** verir (sessiz tip kaybı olmasın), 0/boşsa sessiz geçilir.
- **Kurallar:** ikisi de boş → Rampacı yok · kişi var yevmiye yok → HATA (fişte yevmiye adedi yoksa iki sütunu da boş bırakın, Kontrol Notu'na yazın) · yevmiye var kişi yok → HATA · yevmiye < kişi → HATA · tek kişiye 10'dan fazla yevmiye → HATA (OCR hatası şüphesi) · «+» ve «.5» bu sütunlarda hatadır (tahmin yok).
- **Dağıtım** (`pdks_fis_rampaci_dagit`, rastgelelik yok): her kişiye *yevmiye ÷ kişi* (tam bölüm), kalan kadar kişiye +1. 3 kişi 3 yevmiye → 3 kişi 1 yevmiye · 3 kişi 5 yevmiye → 2 kişi 2 yevmiye + 1 kişi 1 yevmiye · 2 kişi 7 yevmiye → 1 kişi 4 + 1 kişi 3. Her kişi kartsız ayrı dönem olarak yazılır (`yevmiye_adedi` = kişinin yevmiyesi).
- **Ödeme:** toplam yevmiye × çavuşun **Rampacı Tam günlük** ücreti (Yarım/Çift/FM ücreti gerekmez; yevmiye Yarım fiyatla ödenmez; Tam fiyat yoksa hakediş durur). Hakediş satırı: `worker_count` = yevmiye, `unit_rate` = Tam tarife, `line_total` = tarife × yevmiye. Yevmiyeli Rampacı fazla mesaiye/Yarım kararına girmez; Çavuş Ücreti Yöntem B'de kişi-gün = yevmiye.
- **Kurulum (açık GO):** `daily_worker_work_periods.yevmiye_adedi` (tek nullable kolon). Yönetici **Şema Migrasyon › «Rampacı yevmiye alanı»** kartını test sitesinde ve canlıda ayrı ayrı bir kez basar. Basılmadan yevmiyeli Rampacı fişi önizlemede «Rampacı yevmiye alanı kurulmadı» hatası verir ve o fiş için hiçbir şey yazılmaz; yevmiyesiz fişler, kiosk ve Toplu İşlem/Çalışma Ekle'deki saat bazlı Rampacı etkilenmez.
- **Toplu İşlem / Çalışma Ekle** ekranlarında yevmiye alanı YOKTUR (yalnız Fiş Excel kod yolu); Mesai Detayı'nda yevmiyeli dönem «↻ N yevmiye» rozeti ve Mesai Özeti'nde «Rampacı yevmiye: Σ» notu görünür.
- **Test:** `php scripts/pdks_yevmiye_smoke.php` (kolonsuz senaryo dahil) · `pdks_fis_plan_smoke` (12b) · `pdks_fis_xlsx_smoke` (2c) · `pdks_fis_yaz_smoke` (P) · `node scripts/pdks_fis_ui_smoke.js`.

## v464 — Fiş düzeltme: üstüne yaz · fiş yüklemelerini geri al · etkilenen fişler (W4)
Eski kuralla yanlış yorumlanmış fişleri (ör. «12+1» → 12 Erkek + 1 Erkek Yarım) düzeltmek içindir. **Hiçbir kayıt SİLİNMEZ**: eski kayıtlar iptal edilir (Mesai Detayı'nda «iptal», gerekçe + kullanıcı audit'te).
Yalnız **Fiş Excel'i ile yazılmış** kayıtlar değişir; kiosk / elle / ters / tanımlı girişler korunur. **Kesinleşmiş hakedişli günler hiçbir yoldan değişmez** (çavuşun geçerli ödemesi varsa kesin hakediş zaten yeniden açılamaz); «ek hakediş / düzeltme» akışı bu sürümde yok.

### 1) Üstüne yaz (önerilen)
1. Excel'i yeni komutla yeniden üretin (fiş numarası aynı kalabilir; Revizyon girmeniz gerekmez).
2. **Fiş Excel'i Yükle** › 2. adımda dosyayı seçin, **«Düzeltme» kutusunda «Mevcut fiş kayıtlarının ÜSTÜNE YAZ»**'ı işaretleyin ve **gerekçe** yazın (zorunlu).
3. Önizlemede fişin altında **ESKİ (iptal edilecek) → YENİ** özeti çıkar (örn. «Erkek: 13 kişi (12 Tam, 1 Yarım)» → «Erkek: 12 kişi, Şeritçi: 1 kişi»), eski fiş servisi iptali ve yeni revizyon (otomatik: eski + 1) gösterilir. Satır varsayılan **seçili gelmez**; bilerek seçip yazın.
4. Yazma tek işlemdir: yeni kayıtlar + eski kayıtların iptali birlikte ya da hiç. Servis eski kayıtları iptal edilip yenisi yazılır (servis ayrı işlemdir: yarıda kesilirse aynı dosyayı «Üstüne yaz» ile yeniden yükleyin, kalan kısım tamamlanır).
- Mesaide **fiş dışı** aktif kayıt varsa (kiosk/elle/ters) önizleme ENGEL verir; fiş kayıtlarının üstüne yazmak için «Mevcut kayıtlara EK yaz»ı da işaretleyin — fiş dışı kayıtlar yine korunur.
- Aynı dosyada aynı çavuş + gün için birden çok fiş varsa «Üstüne yaz» yalnız İLK fişte çalışır (diğerleri EK ister).
- Önizleme ile yazma arasında mesaideki fiş kayıtları değişirse ya da önizlemeyi aynı yükle ikinci kez yazarsanız fiş «Değişti» der ve yazılmaz (çift yazım olmaz).

### 2) Çavuşun fiş yüklemelerini geri al (toplu · şifreli)
**Çavuşlar** listesinde satırdaki «↩ Fiş yüklemelerini geri al» (ya da Fiş Excel'i Yükle üst çubuğu) → çavuş + tarih aralığı (en çok 366 gün, yalnız geçmiş) + gerekçe → **döküm** (hiçbir şey değişmez): kaç mesai/dönem/servis iptal edilecek, hangi mesailer atlanacak (kesin hakediş / başka depo) ve nedeni.
Uygulamak için **çavuş kodunu yazın + giriş şifrenizi girin** (ekran kilidi PIN'i değil). Yanlış şifre/kod = hiçbir şey olmaz; 5 yanlış denemede 60 sn beklenir. İşlem mesai/batch başına ayrı yapılır: yarım kalırsa aynı işlemi tekrar çalıştırın, kalan kısım tamamlanır. Sonra yeniden yüklemek için Revizyon+1 ya da «Üstüne yaz».

### 3) Etkilenen fişler raporu
**Çavuşlar** › «📋 Etkilenen fişler» (ya da Fiş Excel'i Yükle üst çubuğu): yazılmış fişlerden **Yarım gün içerenleri** (tarih/çavuş süzgeci) tip kırılımıyla listeler. **✱** = Yarım kararı **Erkek** işçiye ait → eski «12+1 = Tam + Yarım» yorumundan kalmış olabilir. Salt okunur; düzeltme için yukarıdaki «Üstüne yaz».

### Teknik
- Mantık `config/pdks_fis.php` «J. DÜZELTME»; şifre doğrulaması `config/sifre_onay.php` (giriş şifresi; ekran kilidi/PIN'den bağımsız). İptal yalnız `pdks_faz8j_void_uygula` / `pdks_faz8j_toplu_geri_al` / `pdks_servis_iptal`.
- Fiş kaynaklı kayıt = Faz 8J özet audit'inde `kaynak:fis_excel`; üstüne yazılan toplu işlem `puantaj_toplu_geri_al` audit'iyle «geri alındı» görünür; yeni `fis_yukle` izinde `ustune_yaz`, `onceki_rev`, `degisen_toplu_idler`.
- Test: `php scripts/pdks_fis_ustune_yaz_smoke.php` · `pdks_fis_sifirla_smoke.php` · `sifre_onay_smoke.php` · `node scripts/pdks_fis_duzelt_smoke.js` (fixture: `pdks_fis_duzelt_render.php`).
