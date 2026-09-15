# Günlük İşçi Faz 8A — Nötr İşçi Kartı + Mesai Dönemi Modeli

**Durum:** Kod tamamlandı · Canlıya alınmadı — merge/deploy için AYRI onay bekleniyor
**Branch:** `claude/phase-8a-neutral-cards-6fr4z7` · **Taban:** `main @ 5e63acf`

---

## 1. ÖZET

Faz 1-7'nin merkezi varsayımı — *"bir fiziksel kart = bir işçi tipi, bir gün
içinde en fazla bir kez kullanılır"* — bu fazda TERS ÇEVRİLİR:

- Kart artık **nötr bir jeton**dur. İşçi tipi karta değil, o taramanın
  yapıldığı **mesai dönemine** (`daily_worker_work_periods`) aittir ve
  GİRİŞ anında ekranda açıkça seçilir.
- Yeni değişmez kural: bir fiziksel kartın aynı anda **en fazla bir açık
  mesai dönemi** olabilir — tarih/depo/çavuştan bağımsız, global. Geçerli
  bir ÇIKIŞ'tan hemen sonra kart aynı gün, aynı ya da farklı çavuşta,
  sınırsız kez yeniden kullanılabilir.

`daily_worker_card_events` (Faz 2) değişmeden kalır — hâlâ ham/değişmez
tarama denetim kaydıdır. `daily_worker_work_periods` bunun üzerine kurulan
yeni **yetkili operasyonel kayıttır**.

## 2. ŞEMA

```sql
daily_worker_work_periods (
    id, session_id, worker_card_id,
    worker_type_id_snapshot, worker_type_name_snapshot,
    entry_event_id UNIQUE, exit_event_id UNIQUE NULL,
    entry_time, exit_time NULL,
    declared_attendance_class ('tam'|'yarim'),
    approved_attendance_class NULL,   -- Faz 8B'ye hazır, 8A'da HİÇ okunmaz/yazılmaz
    work_date_snapshot, depo_snapshot,
    status ('open'|'closed'|'legacy_unresolved'),
    source ('scan'|'legacy_backfill')
)
```

> **PRE-MERGE GÜVENLİK DÜZELTMESİ (merge öncesi):** ilk sürümde eksik
> çıkışlı eski (legacy) kayıtlar da `status='open'` yazılıyor, "açık dönem
> var mı" kontrolü bunları yalnız **dolaylı** bir `source='scan'` filtresiyle
> dışlıyordu. Bu, `status='open'` sütununun TEK BAŞINA "kart şu an meşgul"
> anlamına gelmesi gereken temel değişmezi bozuyordu — bir sorgu bu source
> filtresini unutursa (ör. yeni bir rapor/denetim ekranı), yıllar önceki
> çözülmemiş bir kayıt yanlışlıkla "şu an açık" görünebilirdi. **Düzeltme:**
> `status` artık ÜÇ AÇIKÇA AYRI değer taşır, ayrım `source` sütununda DEĞİL
> `status` sütununun KENDİSİNDE:
>
> - **`open`** — OTORİTER, CANLI açık dönem. Bu fiziksel kart ŞU AN meşgul
>   sayılır, YENİ bir GİRİŞ'i ENGELLER. Yalnız Faz 8A'nın kendi GİRİŞ/ÇIKIŞ
>   yazma yolu bu durumu yazar/değiştirir.
> - **`closed`** — tamamlanmış (GİRİŞ+ÇIKIŞ eşleşmiş) dönem.
> - **`legacy_unresolved`** — Faz 8A ÖNCESİ (Faz 1-7) veriden geriye
>   aktarılmış, eşleşen ÇIKIŞ'ı hiç olmamış TARİHSEL kayıt (bkz. §5). Bu
>   kartın BUGÜN elde tutulduğu anlamına GELMEZ — yalnız geçmişte
>   çözülmemiş bir katılım kaydıdır. Puantaj/raporda "📜 Geçmiş — Eksik
>   Çıkış" olarak görünmeye devam eder, ama HİÇBİR açık-dönem/kilit
>   sorgusunda `open` ile karıştırılmaz — kartı ASLA kilitlemez.
>
> `source` (`scan`|`legacy_backfill`) artık **yalnız köken/denetim
> bilgisidir** — hiçbir iş kuralı sorgusunda kullanılmaz. "Açık dönem var
> mı" kontrolü (`pdks_gunluk_faz8a_kart_acik_donemi()`) ve ÇIKIŞ'ın hangi
> dönemi kapatacağını bulan sorgu (`pdks_gunluk_faz8a_cikis_kaydet()`)
> yalnız `status = 'open'` arar — `source` filtresi YOKTUR. Puantaj/rapor
> görünümleri (`pdks_gunluk_faz8a_oturum_donemleri()`,
> `pdks_gunluk_faz8a_eksik_cikislar()`, günlük liste "eksik" sayaçları) hem
> `closed` hem `legacy_unresolved` dönemleri gösterir — geçmiş kaybolmaz;
> yalnız kilit/blokaj sorguları `legacy_unresolved`'i hiç görmez.

## 3. EŞZAMANLILIK STRATEJİSİ

`pdks_gunluk_faz8a_kart_kilitle()`: `SELECT id FROM worker_cards WHERE
id=? FOR UPDATE` (yalnız MySQL sürücüsünde eklenir — SQLite testleri tek
bağlantılı olduğu için FOR UPDATE söz dizimini tanımaz ve gerek de yoktur).

GİRİŞ ve ÇIKIŞ fonksiyonlarının HER İKİSİ de: kilit → kontrol → yaz, TEK
transaction içinde. İki eşzamanlı istek AYNI fiziksel karta değiyorsa,
ikincisi birincinin commit/rollback'ine kadar bekler ve "açık dönem var
mı" sorgusunu birincinin yazdığı güncel veriyle görür — bu yüzden iki
GİRİŞ'in aynı anda "açık dönem yok" görüp ikisinin de yazması yapısal
olarak imkânsızdır. Farklı kartlar hiç serileşmez.

Kasıtlı olarak kullanılmayan alternatif: MySQL generated-column + UNIQUE
index numarası gerçek bir ikinci savunma katmanı olurdu, ama bilinmeyen/
paylaşımlı barındırma ortamlarında test edilmemiş sürüm-özel özellik
eklemekten kaçınıldı (CLAUDE.md'nin REGEXP/ON DUPLICATE KEY ihtiyat
ilkesiyle aynı gerekçe) — satır kilidi tek başına yeterli ve taşınabilir.

## 4. MİGRASYON — `migrate.php` → "Faz 8A Migrasyonunu Çalıştır"

`pdks_gunluk_faz8a_migrate()`, dört adım, tek çağrıda, bu sırayla:

1. `daily_worker_work_periods` tablosunu oluşturur (additive).
2. `worker_cards.worker_type_id` → `MODIFY COLUMN ... NULL` (üretimde hâlâ
   NOT NULL olabilir; fresh kurulumlar zaten nullable doğar).
3. `daily_worker_card_events` üzerindeki eski `uq_dwce_card_day_depo_type`
   kısıtını `DROP INDEX` ile kaldırır.
4. **Legacy backfill** — Faz 1-7'nin eski GİRİŞ olaylarını yeni tabloya
   aktarır (bkz. §5).

İdempotent — her adım kendi durumunu kontrol eder, tekrar çalıştırmak
güvenlidir; kısmen tamamlanmış bir durumdan devam ettirilebilir (bkz.
aşağıdaki "gerçek başarısızlık davranışı").

> **PRE-MERGE GÜVENLİK DÜZELTMESİ — atomiklik iddiası düzeltildi:** önceki
> rapor "migrasyon tamamlanınca yeni kurallar ANINDA, ATOMİK olarak devreye
> girer" diye özetlemişti. Bu **yanıltıcıydı**: `pdks_gunluk_faz8a_migrate()`
> DÖRT adımı **TEK bir veritabanı transaction'ı İÇİNDE ÇALIŞTIRMAZ** — ve
> çalıştıramaz da, çünkü MySQL'de DDL (`CREATE TABLE`, `ALTER TABLE ...
> MODIFY COLUMN`, `DROP INDEX`) **implicit commit** yapar: her DDL
> ifadesinden önce (InnoDB'de bazı sürümlerde sonra da) o ana kadarki
> transaction otomatik commit edilir. Yani bu dört adım arka arkaya çalışan
> **DÖRT AYRI, GERİ ALINAMAZ commit** noktasıdır — `ROLLBACK` bunlardan
> hiçbirini geri alamaz. "Atomik" kelimesi bu davranışı yanlış tarif
> ediyordu; doğru tarif **"sıralı, idempotent, kendi kendini denetleyen dört
> adım"**dır — atomiklik DEĞİL, aşağıdaki AND-gate güvenliği sağlar.
>
> **Gerçek kısmi-başarısızlık davranışı:** her adım BAĞIMSIZ try/catch
> içindedir ve KENDİ mevcut durumunu (tablo var mı / kolon zaten nullable mı
> / index zaten yok mu) kontrol ederek karar verir — bir adım `hata`
> raporlasa bile döngü **DURMAZ**, sıradaki adımı yine dener (backfill hariç,
> o da yalnız gerekli iki tablo mevcutsa çalışır — kendi başına ek bir risk
> taşımaz, çünkü additive-only'dir). N. adım başarısız olduktan SONRA
> migrasyonu tekrar çalıştırmak GÜVENLİDİR: başarılı adımlar "zaten var/zaten
> uygulanmış" olarak atlanır, yalnız başarısız adım yeniden denenir — hiçbir
> adım iki kez zarar verecek şekilde tekrarlanmaz (CREATE TABLE IF NOT
> EXISTS + varlık kontrolleri).
>
> **Kısmi tamamlanmış durumda normal trafik ne görür:** `pdks_gunluk_faz8a_sema_hazir()`
> aşağıdaki üç koşulu **VE** (AND) ile birleştirir — DÖRDÜ arasında yalnız
> İLK ÜÇÜ şema koşuludur (4. adım — backfill — bir VERİ adımıdır, şema
> hazırlığına dahil DEĞİLDİR, additive olduğu için erken/geç çalışması
> güvenlidir):

### Dağıtım sıralaması — güvenli, sıfır bekleme penceresi

`pdks_gunluk_faz8a_sema_hazir()` üç koşulun HEPSİNİ kontrol eder (tablo +
nullable kolon + kısıtın kaldırılmışlığı). Üç koşuldan HERHANGİ BİRİ eksikse
(migrasyon hiç çalışmamış YA DA yarıda kalmış olsun fark etmez) fonksiyon
**false** döner ve Faz 1-7'nin eski davranışı aynen çalışmaya devam eder —
"kısmen Faz 8A, kısmen eski mantık" karışık bir ara durum **yapısal olarak
imkânsızdır**, çünkü kontrol her istekte YENİDEN yapılır (bayrak DB'de
saklanmaz, önbelleğe alınmaz):

- **Kod deploy edildi, migrasyon HENÜZ çalıştırılmadı (ya da yarıda
  kaldı):** bayrak false — Faz 1-7'nin eski davranışı (aynı kart/gün/depo
  başına bir kez) aynen çalışmaya devam eder. Tarama BOZULMAZ.
- **Üç koşulun HEPSİ sağlandı:** bayrak true — yeni "tek açık dönem" kuralı
  devreye girer. Bu, "tek transaction'ın commit'i" anlamında atomik
  DEĞİLDİR — sıradaki isteğin bu üç koşulu ayrı ayrı sorgulayıp HEPSİNİ true
  bulmasıdır (DDL'lerin hepsi zaten kalıcı commit edilmiş durumdadır).

Ayrı bir dağıtım adımı/bekleme süresi gerekmez — webhook zaten dosyaları
dakikalar içinde indiriyor (CLAUDE.md → Deploy Workflow), migrasyon ise
admin'in migrate.php'den tek tıkla tetiklediği ayrı, kontrollü bir adımdır.

## 5. LEGACY BACKFILL — bilinçli karar

Görev talimatı "backfill gereksizse yapma" diyordu; **bilinçli olarak
yapıldı**, çünkü gerekliliği doğrulandı:

- **Neden gerekli:** puantaj/rapor fonksiyonları migrasyon tamamlanır
  tamamlanmaz `daily_worker_work_periods`'u TEK kaynak olarak okumaya
  başlıyor. Backfill yapılmasaydı tüm eski günlerin puantajı migrasyon
  anında sıfıra düşerdi.
- **Neden deterministik/güvenli:** eski `uq_dwce_card_day_depo_type`
  kısıtı + uygulama katmanı, bir (session_id, worker_card_id) çifti için
  en fazla bir GİRİŞ ve en fazla bir ÇIKIŞ satırı garanti ediyordu —
  eşleştirme belirsiz değil, kesindir.
- **Ne aktarılır:** her eski GİRİŞ olayı → bir dönem satırı.
  Eşleşen ÇIKIŞ'ı varsa `status='closed'`; yoksa (eksik çıkış)
  `status='legacy_unresolved'` (PRE-MERGE DÜZELTMESİ — önceden `status='open'`
  yazılıp yalnız `source` filtresiyle dışlanıyordu, bkz. §2 kutusu) — geçmiş
  kaybolmaz (puantaj/raporda "📜 Geçmiş — Eksik Çıkış" görünür), ama
  **`status` sütununun kendisi `open` OLMADIĞI için** yeni taramayı asla
  engellemez; ayrıca ne midnight/gün değişiminde ne de oturum kapatılınca
  bu satırlar geriye dönük değiştirilir — backfill TEK SEFERLİK, statik bir
  aktarımdır.
- **`declared_attendance_class='tam'`:** eski model Tam/Yarım ayrımını
  bilmiyordu — tek seçenek tam gündü, bu uydurma değil gerçek karşılıktır.
- Yalnız **ekler**, `daily_worker_card_events`'e tek satır dokunmaz. İki
  kez çalıştırmak güvenlidir (entry_event_id zaten aktarılmışsa atlanır).

## 6. MALİ GÜVENLİK — Faz 8B'ye kadar

`pdks_hakedis_hesapla()` (dolayısıyla `pdks_hakedis_finalize()`), bir
oturumda `declared_attendance_class <> 'tam'` olan EN AZ bir dönem varsa
`faz8a_degerlendirme_gerekli` ile REDDEDER:

> "Bu mesai kaydı yeni Tam/Yarım mesai modelini kullanıyor. Hakediş Faz 8B
> mesai değerlendirmesi tamamlanmadan kesinleştirilemez."

Tümü Tam Mesai olan oturumlar (yeni VEYA geriye aktarılmış) bu kapıdan
etkilenmez — sayım artık **dönem** (katılım) bazlıdır, aynı kart aynı gün
iki kez kullanıldıysa iki ayrı katılım fiyatlanır (eski COUNT(DISTINCT
worker_card_id) modeli bunu bir sayardı — bu, Faz 8A'nın same-day reuse
kuralıyla artık YANLIŞ olurdu). Mevcut KESİN hakedişler dokunulmadan kalır.

## 7. RAPORLAMA TERMİNOLOJİSİ

`pdks_rapor_operasyonel_kpi/gunluk_trend/cavus_ozeti`, şema hazırsa
period-tabanlı sayıma dallanır. Ana metrik artık **"İşçi Katılımı"**
(work-period participation) — "benzersiz çalışan" DEĞİL, çünkü nötr
kartlar insan kimliğini kanıtlayamaz. Ayrı, karıştırılmayan bir
**"Fiziksel Kart Kullanımı"** (distinct kart) metriği de eklendi.

## 8. GERÇEK CİHAZ TEST PLANI

| Test | Adımlar | Beklenen |
|---|---|---|
| A | Ayşe → GİRİŞ → Kadın → Tam → K001,K002,K003 art arda tara | Her biplemede toplam artıyor, bekleme yok |
| B | Ekrandan ayrılmadan Erkek'e geç → K004,K005 tara | Sayaç dinamik güncelleniyor |
| C | ÇIKIŞ → K001 tara | Doğru dönem kapanıyor |
| D | 5dk sonra: Mehmet → GİRİŞ → Erkek → Yarım → AYNI K001'i tara | Kabul edilir |
| E | K001 Mehmet'te açıkken: Ayşe → GİRİŞ → K001 tara | Reddedilir ("açık görünüyor") |
| F | Ayşe → ÇIKIŞ → K001 tara (gerçekte Mehmet'te açık) | Yanlış-çavuş reddi, Mehmet adı açıkça yazar |
| G | Günlük Puantaj | K001 iki bağımsız dönem olarak görünür |
| H | Faz 7 yazdırma | İki dönem ayrı ayrı basılır, Mesai Sınıfı + Süre kolonları dolu |

## 9. YAPILMADI (bilinçli, Faz 8B/8C kapsamı)

Yarım gün finansal oranı, fazla mesai hesabı, muhasebe gün-sonu düzeltme
arayüzü, hakediş finansal yeniden tasarımı, offline IndexedDB kuyruğu,
service-worker mutasyonu, senkronizasyon çakışma arayüzü, FX, yeni cari
mantığı. `approved_attendance_class` kolonu eklendi ama hiçbir yerde
okunmuyor/yazılmıyor — yalnız Faz 8B'nin üzerine kolayca inşa edebileceği
bir temel.
