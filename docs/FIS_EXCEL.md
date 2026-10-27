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
sütun sırası bozulsa da çalışır. Zorunlu: Fiş No, Tarih, Çavuş Kodu ya da Çavuş Adı. Şablon sürümü: `PDKS_FIS_SABLON_SURUMU` (değişirse okuyucu başlık-ada-göre olduğundan geriye uyumludur).

## Kurallar (özet)
- Yalnız bugünden **önceki** günler · ≤150 fiş · ≤2 MB · yalnız .xlsx (makro / dış bağlantı içeren dosya reddedilir).
- Giriş boşsa 08:00. Tam çıkış = giriş + çavuşun o günkü normal süresi (tarihli süre dönemi / fiyat dönemi saatleri sınıflandırıcıdan çözülür); FM k saat = FM başlangıcı + k saat; Yarım = Tam'ın yarısı.
- FM'ye girenler Tam kişilerden sayılır; Yarım'a FM verilmez. FM: kova sütunları (FM 1..6 Sa kişi) **ya da** «Toplam FM Saati» (+ ops. «FM Kişi Sayısı»; sabit tutarlı FM fiyatında zorunlu).
  Toplam saat dağıtımı deterministiktir (rastgele değil); FM fiyatları tipler arasında farklıysa kapalıdır → kova sütunları ya da tek «FM Tipi».
- Mesaide aktif kayıt varsa fiş durur («Mevcut kayıtlara EK yaz»). Kesin hakedişte hiçbir şey yazılmaz.
- Sözleşme: istek kimliği = depo + fiş no + revizyon (tarih/çavuş hariç).

## OCR komutu bakımı
Komut metni `pdks_fis_ocr_komutu()` içindedir (Açıklama sayfası ve yükleme sayfasındaki kutu aynı dizeyi kullanır). "YORUM KURALLARI" bölümü ("12 + 1", "N kişi : M yarım" yorumu)
sahibin kararına göre güncellenecek TEK yerdir; kod mantığı bundan bağımsızdır.

## Hata mesajı sözlüğü (seçme)
| Mesaj | Anlamı |
|---|---|
| `Satır n, «Başlık»: "12 + 1" tek sayı değil` | Tam ve Yarım sayılarını ayrı sütunlara yazın |
| `Çavuş kodu bulunamadı` | Kod Çavuşlar sayfasında yok (ada düşülmez) |
| `bugün veya gelecek tarihli fişler desteklenmez` | Yalnız geçmiş günler |
| `Toplam FM dağıtımı yapılamaz` | FM fiyatı nötr değil/bilinmiyor → kova sütunları |
| `Mesaide N aktif kayıt var` | Önce Toplu Geri Al ya da «EK yaz» |
| `Önizlemeden sonra durum değişti` | Mesai/fiyat/ayar değişti → yeniden önizleyin |

## Test
`php scripts/pdks_fis_plan_smoke.php` · `pdks_fis_xlsx_smoke.php` · `pdks_fis_yaz_smoke.php` · `pdks_fis_static_smoke.php` · `node scripts/pdks_fis_ui_smoke.js`.

## v448 — Oturum kaybı dayanıklılığı
Önizleme/sonuç verisi oturumdan bağımsız olarak `hks_kv`'de de tutulur (2 saat sonra silinir; kullanıcı + depo + 30 dk kuralı aynı). Hata mesajı nedeni söyler: süre doldu / başka kullanıcı-depo / sunucuda bulunamadı. Ayrıntı: CLAUDE.md "Fiş Excel'i Yükle".
