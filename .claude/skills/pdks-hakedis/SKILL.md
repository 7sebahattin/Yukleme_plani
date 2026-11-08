---
name: pdks-hakedis
description: Çavuş ücreti (Yöntem A/B), kapanış/ödeme, saat bazlı yevmiye/çift yevmiye, servis ücreti, Şeritçi, saat başı toleransı, Mesai Özeti/Değerlendirme, Çavuş Ekstresi, Toplu Döküm (config/pdks_faz8b*, pdks_hakedis, pdks_cari, pdks_servis, cavus_*, mesai_*). Hakediş/fiyat/FM/cari hesabına dokunurken kullan.
---

# PDKS — Çavuş Ücreti, Hakediş, Mesai Özeti

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

**v493 — Mesai Değerlendirme: toplu fazla mesai onayı/reddi + filtre (sahip isteği, migration YOK, yeni yetki YOK):** `mesai_degerlendirme.php` üstünde "⏱ Toplu fazla mesai" çubuğu: Göster filtresi (Onay bekleyen · Onaylı · Reddedilen · FM'si olan hepsi · Tüm satırlar; varsayılan Onay bekleyen) + FM saati filtresi + "Görünenlerin tümünü seç" + **✅ Seçilenleri Onayla** (= hesaplanan saatin TAMAMI) / **❌ Seçilenleri Reddet** (= 0 saat), onay penceresiyle. Filtre istemcide (satırlarda `data-fm-durum/-saat`; gizlenen satırın seçimi düşer). Kısmi saat toplu verilmez (satırdaki Kaydet). POST `action=fm_toplu_kaydet` (CSRF + `entitlements_finalize`) → `pdks_faz8b_toplu_fm_kaydet()` (config/pdks_faz8b.php): ikinci yazma yolu YOK, her dönem `pdks_faz8b_degerlendirme_kaydet()`'e devredilir (kesin hakediş reddi/audit orada); başka oturum/FM adayı olmayan atlanır; Tam/Yarım kararı bekleyen satır hata verir; mevcut sınıf kararı aynen geçirilir. Mevcut Tam/Yarım toplu işlemi (`toplu_kaydet`) AYNEN. Test: `pdks_faz8b_toplu_degerlendirme_smoke` (8).

**v492 — Çavuş Ekstresi: Borç / Alacak + tip bazlı kırılım (sahip isteği, migration YOK, yeni yetki YOK):** `cavus_ekstre.php` (+ `_yazdir.php`, XLSX) sütunları artık Tarih · Tip · Belge/Açıklama · **işçi tipi başına kişi + hakediş** (Kadın, Erkek, Rampacı, Şeritçi; yalnız verisi olanlar) · **Fazla mesai (saat) + Fazla mesai hakediş** (tek kalem) · Diğer (çavuş ücreti/servis) · **Alacak** (= hakediş, toplam tutar) · **Borç** (= ödeme) · **Bakiye** (+ "Alacak" / − "Borç"). Eski "Artış/Azalış" başlıkları gitti (geri ekleme). Tablo sonunda TOPLAM satırı. Kırılım `foreman_daily_entitlement_lines`'tan okunur (tip satırı = line_total − overtime_total, FM ayrı; `worker_type_id` NULL = Diğer); yalnız GÖSTERİM, bakiye/hakediş toplamı DEĞİŞMEDİ; `overtime_*` kolonları yoksa kırılım sütunları çıkmaz (fail-soft). Mantık `config/pdks_cari.php` `pdks_cari_kirilim_*` (ekran/yazdırma/XLSX TEK kaynak — adı `pdks_cari_ekstre…` ile BAŞLATMA: statik test `function pdks_cari_ekstre.*?` regex'i ilk eşleşmeye bakar). CSV AYNEN (kural: CSV'ye dokunma). Test: `pdks_cari_smoke` (24) · `pdks_cari_ui_smoke` (7d).


**Çavuş tarihli normal süre (v351):** `cavus_form.php` ayrı süre formu + aynı ekranda geçmiş. `config/pdks_cavus_sure.php`, mevcut `audit_log` üzerinde `normal_sure_donemi` olayları; bu kayıtlar işlevsel veri, silinmez. Dahil tarih aralığı / süresiz, son kayıt önceliği; geçmiş snapshot ve onay düzeltmesi, taslak yeniden hesaplama; final varsa tam RED. Fiyat Tam/FM saatlerinden öncelikli, parasal ücret değişmez. Migration yok. Ayrıntı: `docs/CAVUS_SURE_DONEMLERI.md`.


## Çavuş Ücreti (Faz 8B eki)

Çavuşun KENDİ günlük ücreti — **zorunlu değil**. Tablo `foreman_daily_rates`
(`foreman_worker_rates`'in worker_type_id'siz eşdeğeri, tarihli valid_from/valid_to).
Giriş: `cavus_fiyatlari.php`'de Tam/Yarım/FM kartının ALTINDA ayrı kart
(`form=cavus_ucret`; mevcut form `form=oran`). Kod: `config/pdks_faz8b.php`
`pdks_faz8b_cavus_ucret_*`. Test: `php scripts/pdks_cavus_ucret_smoke.php`.

- **Otomatik satır:** `pdks_faz8b_hakedis_hesapla()` o oturumda ≥1 işlenmiş (voided
  olmayan) dönem varsa ve o tarihte geçerli ücret tanımlıysa 1 satır ekler
  (`worker_type_id=NULL`, `work_period_id=NULL`, "Çavuş Ücreti"). Çavuş kart basmaz.
  Ücret yoksa satır da yoktur ve **eksik SAYILMAZ**. Tutar Tam/Yarım'dan bağımsız,
  günlük sabit. Cari/ekstre/rapor/toplu döküm `total_amount` okuduğu için oralara
  kendiliğinden yansır — tüketicilere satır bazlı kod EKLEME.
- **Aynı gün İKİ oturum olabilir** (foreman+work_date+depo UNIQUE): ücret YALNIZ o
  günün en küçük id'li, dönemi olan oturumuna ("ankor") yazılır; bir oturum ücreti
  FİNAL olarak içerdiyse başka oturum ASLA almaz (çift ödeme yapısal olarak engelli).
  Dönem değişince kardeş taslak da `needs_recalculation` alır
  (`pdks_faz8b_cavus_ucret_kardes_isaretle()` — faz8e/8h/8j çağırır).
- **Para birimi** işçi satırlarından farklıysa mevcut `karisik_para_birimi` kapısı
  hesaplamayı durdurur (ayrı mesaj yok).
- **Kendi migrasyonu var** (`pdks_faz8b_cavus_ucret_migrate()`; `cavus_fiyatlari.php`
  tablo yoksa açılışta çağırır, `migrate.php`'de de kart var). `pdks_faz8b_sema_hazir()`'e
  BİLEREK EKLENMEDİ — eklenseydi bu opsiyonel tablo yokken TÜM Faz 8B kilitlenirdi.
  Tablo yoksa hesap "ücret tanımsız" gibi davranır.

## Çavuş Ücreti — Yöntem B (25 kişi-gün = 1 hakediş)

Çavuşun kendi ücretini hesaplamanın **ikinci** yolu (varsayılan hâlâ Yöntem A —
yukarıdaki günlük sabit ücret). Yöntem **çavuş bazında** seçilir
(`cavus_fiyatlari.php`, "Hesaplama Yöntemi" kutusu), tarihe göre DEĞİL —
geçmişi zaman damgalı tutulur, geriye dönük **değildir**.

- **Dosyalar:** yöntem seçimi + 3 yeni tablo `config/pdks_faz8b.php`'nin
  "ÇAVUŞ ÜCRETİ — YÖNTEM SEÇİMİ" bloğunda; kapanış motoru/orkestratörler/
  listeler YENİ `config/pdks_faz8b_cavus_b.php`'de (`pdks_faz8b.php` +
  `pdks_cari.php`'yi require eder). `pdks_cari.php`/`pdks_rapor.php`/
  `pdks_hakedis.php` yeni tabloları yalnız ham SQL + "tablo yoksa atla"
  deseniyle okur — bu yeni dosyayı require ETMEZ. Sayfalar: `cavus_fiyatlari.php`
  (yöntem seçimi), `cavus_odeme.php` (kapanış tetiği + önizleme), YENİ
  `cavus_donem_raporu.php` (dönem raporu, yalnız `raporlar.php`'den link).
- **3 yeni tablo** (`pdks_faz8b_cavus_ucret_b_tablolar()`; kendi migrate/
  sema_hazir çifti, `pdks_faz8b_sema_hazir()`'e BİLEREK EKLENMEZ, önkoşulu
  Hakediş+Cari Hesap tabloları): `foreman_rate_method_log` (yöntem geçmişi,
  zaman damgalı), `foreman_period_closures` (dönem kapanışı = tahakkuk),
  `foreman_period_closure_items` (hangi KESİN hakediş hangi kapanışa dahil —
  kişi-gün DONMUŞ, ASLA yeniden hesaplanmaz).
- **Sayım kuralı:** `SUM(worker_count) WHERE worker_type_id IS NOT NULL`
  (Çavuş Ücreti satırını ve gelecekteki "worker olmayan" satırları güvenle
  dışlar). Bir final hakediş B havuzuna girer ⇔ `status='final'` VE
  `finalized_at` dolu VE **finalize ANINDA** çavuşun yöntemi B'ydi
  (`pdks_faz8b_cavus_ucret_yontem_anda()` — iş TARİHİNE değil, KESİNLEŞME
  ANINA bakar) VE hakedişte "Çavuş Ücreti" satırı YOK VE hiçbir GEÇERLİ
  kapanışın kalemi değil. A'dayken kesinleşen günler ASLA B'ye girmez (çift
  ödeme); B→A→B geçişinde ARADA (A iken) kesinleşen günler de asla sayılmaz.
- **Kapanış = ödeme, TEK transaction:** `pdks_faz8b_cavus_ucret_odeme_kaydet()`
  tx açar → `pdks_faz8b_cavus_ucret_b_kilit()` (tx'in İLK sorgusu,
  `SELECT ... FOR UPDATE`, yalnız MySQL) → `pdks_cari_odeme_ekle()` → yöntem
  B ise `pdks_faz8b_cavus_ucret_b_kapat()` → commit. Biri başarısızsa HİÇBİRİ
  yazılmaz. Eşzamanlılık AYRICA `chain_key` (`'F{çavuş}:P{önceki kapanış id
  ya da 0}'`) + `UNIQUE uq_fpc_chain` ile korunur — 23000 → "eşzamanlı işlem"
  Türkçe mesajı.
- **Ücret tanımsız:** ödeme YİNE DE kaydedilir, kapanış YAPILMAZ (kişi-gün/
  devir açıkta bekler, `audit closure_skipped`); sonraki ödemede (o tarihteki
  ücretle) kapanır. **Adet 0 ama kişi-gün var** (ör. 24): kapanış YAZILIR,
  tutar 0.00, devir 24. **Yeni kişi-gün YOK**: kapanış hiç YAZILMAZ.
- **İptal yalnız orkestratörden** (`pdks_faz8b_cavus_ucret_odeme_iptal()`) —
  `pdks_cari_odeme_iptal()` DOĞRUDAN çağrılırsa (bu ödemeye bağlı geçerli
  kapanış varsa) `kapanis_bagli` ile REDDEDİLİR. Yalnız EN SON GEÇERLİ
  kapanış geri alınabilir (`kapanis_en_son_degil` — daha yeni kapanış varsa
  önce o iptal edilmeli). İptal → kapanış `status='cancelled'` + `chain_key
  NULL` (satır SİLİNMEZ), kişi-günler sonraki kapanışta yeniden sayılır.
- **Cari:** `pdks_cari_bakiye()` / `pdks_rapor_bakiye_toplu()` /
  `pdks_rapor_cavus_bakiye_toplu()` ÜÇÜ de geçerli kapanışları `hakedis_kurus`'a
  (+ bilgi amaçlı `cavus_hakedis_kurus`) ekler. **Kullanıcı cevabı:**
  `pdks_rapor_finansal_kpi()` / `pdks_rapor_gunluk_trend()` /
  `pdks_rapor_cavus_ozeti()` dönem hakedişi DE B kapanışlarını içerir (tarih
  filtresi `closure_date`, **depo filtresi UYGULANMAZ** — bir kapanış birden
  çok gün/depoyu kapsayabilir). `pdks_hakedis_yeniden_ac()`'a YALNIZ EKLEME:
  geçerli bir B kapanışının kalemi olan hakediş `b_kapanisina_dahil` ile
  yeniden açılamaz.
- **Yeniden açma korumaları `function_exists()`'e GÜVENMEZ:** tek çağıran
  `cavus_hakedis_detay.php` `pdks_cari.php`'yi yüklemez; eski "geçerli ödemesi
  olan çavuş" kontrolü (`function_exists('pdks_cari_odeme_var_mi')`) o ekranda
  sessizce atlanıyordu. Artık aynı sorgu `pdks_hakedis_tablo_var($pdo,
  'foreman_payments')` ile doğrudan yapılır (tablo yoksa Faz 4 tek başına
  çalışır). Test: `php scripts/pdks_hakedis_yeniden_ac_guard_smoke.php`
  (her senaryo YALNIZ `pdks_hakedis.php` yüklü ayrı alt süreçte).
- **Ekstre:** `CAVUS_HAKEDIS` satırı `pdks_cari_ekstre()`'ye AYRI bir satır
  türü olarak eklenir, ödemeyle AYNI tarihte ama `siralama_oncelik=0` ile o
  ödeme satırından ÖNCE sıralanır. Açıklama biçimi sabit: "Çavuş Hakedişi —
  N kişi-gün (+D devir) → K hakediş × ücret, devir R".
- **Toplu döküm** (`cavus_toplu_dokum.php` + `_yazdir.php`): ay içindeki B
  kapanışları mevcut günlük tablonun ALTINDA ayrı bir bölümdür (kapanış
  ödeme tarihine göre o aya düşer, depo filtresiz).
- **Dönem raporu** yalnız `raporlar.php`'den link alır (`personel_takip.php`'ye
  kart EKLENMEZ — kullanıcı cevabı). Yetki: `require_pdks_rapor()` +
  `pdks_rapor_can('financial')` + export'ta `reports.export`.
- **Test:** `php scripts/pdks_cavus_b_smoke.php` (bellek içi SQLite, gerçek
  DDL çevirici + gerçek fonksiyonlar — canlı DB'ye dokunmaz). Yöntem B'ye
  dokunan HERHANGİ bir değişiklikten sonra çalıştır.


### Saat Bazlı Yevmiye + Çift Yevmiye (v299)

- **Şema** (sahip GO verdi — YALNIZ ADD COLUMN): `foreman_worker_rates` + `full_day_minutes` ·
  `half_day_max_minutes` (yalnız bilgi) · `overtime_start_minutes` · `double_day_minutes` · `double_day_rate`,
  hepsi NULL. Kendi çifti `pdks_faz8b_saat_kolonlari_migrate/_hazir` — `pdks_faz8b_sema_hazir()`'e BİLEREK
  EKLENMEZ. Kurulum yalnız migrate.php kartı; `cavus_fiyatlari.php` migrate ÇAĞIRMAZ (kolon yoksa not gösterir).
- **NULL = bugünkü davranış birebir:** Tam eşiği = mesainin `normal_work_minutes_snapshot`'ı, FM Tam'dan 15 dk
  tolerans sonra, çift yok. `foremen.normal_work_minutes` kalır (formda Tam saatinin varsayılanı + kiosk uyarısı).
- **TEK sınıflandırıcı `pdks_faz8b_donem_siniflandir($donem, $oran)`** — Mesai Değerlendirme, tekli/toplu
  değerlendirme (`pdks_faz8b_donem_getir`), hakediş, Mesai Tanımı sütunu hepsi bunu kullanır. Süre/FM/çift
  hesabını başka yerde YAZMA; `pdks_faz8b_sure_karari` yalnız oradan çağrılır (test denetler).
- **Çift** (sahip kararı: çift Tam'ın YERİNE + sonrası FM; onay FM onayına bağlı): toplam ≥ çift eşiği VE onaylı
  süre (FM başı + onaylı FM × 60) ≥ eşik → sınıf `cift`, temel = çift ücret, FM = eşikten SONRAKİ onaylı saat;
  Tam–çift arası ayrıca ödenmez. Sabit FM'de çift gününe FM EKLENMEZ. FM red → Tam, bekliyor → hazır değil.
  Eşik değişince onaylı FM adaya KIRPILIR. Ayrı onay/şema YOK.
- Yarım saati sınıfı DEĞİŞTİRMEZ (sahip kararı: otomatik Yarım yok, karar muhasebede), yalnız ipucu. Satır
  `attendance_class_snapshot='cift'`, `worker_count=1` (Yöntem B'de 1 kişi-gün). Faz 4 motoru çift bilmez.
- **Mesai Detayı "Mesai Tanımı"** (DURUM'un yanında; MESAİ beyan sütunu KALIR): `pdks_faz8b_mesai_tanimi_etiketi()`
  — Tam/Yarım/Çift + FM; faz8b şeması yoksa gizli. DURUM "✅ Çıkış yapıldı" (CSS kodu `tam` aynı).
- Test: `php scripts/pdks_cift_yovmiye_smoke.php` · `PUANTAJ_FAZ8B=1 php scripts/pdks_puantaj_dialog_render.php
  > _test_puantaj_dialog.html` → `node scripts/pdks_puantaj_dialog_smoke.js` · `CAVUS_FIYAT_SENARYO=dolu|bos php
  scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat[_bos].html` → `node scripts/pdks_cavus_fiyat_smoke.js`.

### Servis Ücreti (v299)
Mesai Detayı "🚌 Servis Ücreti" (Çalışma Ekle/Toplu İşlem yanında) → BÜYÜK/KÜÇÜK adet; fiyat çavuş bazında
`cavus_fiyatlari.php` "🚌 Servis Ücreti" kartında (tarihli, geçmişli; önceki dönem bir gün önce kapanır).
- **Tablolar** `foreman_service_rates` + `daily_session_services` (sahip GO — yalnız 2 yeni tablo, ALTER YOK); kendi
  `pdks_servis_tablolar/_migrate/_sema_hazir` (config/pdks_servis.php), genel sema_hazir'lere EKLENMEZ, kurulum yalnız
  migrate.php kartı. Tablo yoksa özellik GİZLİ, hesap servisi yok sayar. pdks_faz8b.php dosya sonunda yükler.
- **Hakediş** (sahip kararı: çavuş hakedişine ek satır): `pdks_faz8b_hakedis_hesapla` Çavuş Ücreti bloğunun ALTINDA tür
  başına 1 satır (worker_type_id/work_period_id NULL, kod `SERVIS_BUYUK|SERVIS_KUCUK`, worker_count=adet). Fiyat yoksa
  eksik → hesap DURUR; para birimi karışık para kapısına katılır; Yöntem A/B fark etmez. Tüketicilere satır kodu EKLEME
  (total_amount okurlar). Faz 4 motoru servisi bilmez.
- **Dedektör tuzağı:** Çavuş Ücreti satırı = NULL/NULL **ve kod ''**. `baska_final_var_mi` ve B `aday_kalemler.cavus_satiri`
  `worker_type_code_snapshot = ''` ister — kaldırma (servis A'da günlük ücreti engeller, B'de günü havuzdan düşürür).
- **Yazma** `pdks_servis_ekle/_iptal`: yalnız admin, aktif depo = mesai deposu, mesai kilidi tx'in İLK sorgusu, kesin
  hakediş red, taslağa needs_recalculation, istek_id (UNIQUE + ön kontrol), gelecek gün yok.
  Düzenleme YOK; iptal soft + gerekçe. Audit `servis_ekle/servis_iptal` (modül daily_work_sessions, record_id=mesai).
  Yalnız servisi olan (işçi dönemi olmayan) mesai hakediş hesaplanamaz (`kart_yok`, mevcut kural).
- Partial `_puantaj_servis.php` (fonksiyon tanımlamaz, native dialog#servis). Gün Sonu Fişi yalnız ADET basar.
- **Fiyat yokken de giriş YAPILIR (v300, sahip kararı: servis kalkıyor, fiyat sonradan girilir):** `pdks_servis_ekle()`
  fiyat kapısı YOK, yanıtta `fiyatsiz` (tür adları) döner; pencerede sayaçlar açık + mavi `.sv-bilgi-kutu` (kırmızı engel
  YOK). Hakediş KORUNUR: fiyat yoksa eksik → bu mesainin hakedişi hesaplanamaz (işçi fiyatı olmayan mesaiyle AYNI
  fail-closed kural) — servis sessizce ödenmemiş kalmaz. Fiyat tanımlanınca hesap kendiliğinden geçer.
  `pdks_servis_ucret_ekle()` o çavuşun, bu tarihten itibaren servisi olan TASLAK hakedişlerini
  (`pdks_servis_taslaklari_isaretle`) yeniden hesaplamaya işaretler (kesin hakedişe dokunmaz; yanıtta `isaretlenen`).
  Fiyat formunun "Geçerlilik Başlangıcı" varsayılanı = fiyatsız en eski servis günü (`pdks_servis_fiyatsiz_en_eski_tarih`);
  fiyat servis gününü KAPSAMALI (`valid_from <= work_date`), yoksa o gün hâlâ fiyatsız sayılır.
  Test: `php scripts/pdks_servis_smoke.php` (bölüm L) · `PUANTAJ_SERVIS=nofiyat php scripts/pdks_puantaj_dialog_render.php >
  _test_servis_fiyatsiz.html` → `node scripts/pdks_servis_fiyatsiz_smoke.js`.
- Test: `php scripts/pdks_servis_smoke.php` · `PUANTAJ_SERVIS=1 php scripts/pdks_puantaj_dialog_render.php >
  _test_servis_dialog.html` → `node scripts/pdks_servis_dialog_smoke.js` · `pdks_cavus_fiyat_smoke.js`.


### Şeritçi İşçi Tipi + Geçmişe Dönük Fiyat (v455, W1)
Rampacı emsali 4. SABİT sistem tipi: `worker_types` SERITCI/"Şeritçi" (sahip GO verdi — yalnız veri satırı, şema YOK; migrate.php'ye dokunulmadı).
- `pdks_gunluk_desteklenen_tip_kodlari()` = KADIN, ERKEK, RAMPACI, SERITCI (Karışık YİNE politikada değil). Satır: migrate seed (sort_order 5) + tembel idempotent `pdks_gunluk_seritci_tip_garanti()` (pasifse yeniden AÇMAZ; `desteklenen_tip_listele()` ve `isci_tipleri.php` çağırır). `pdks_gunluk_tip_kayit()` sırası Kadın, Erkek, Rampacı, **Şeritçi**, Karışık (renk son eki `seritci`, TEAL); rapor sütunları/Toplu Döküm/Mesai Özeti/kiosk `TIP_KAYIT` döngüyle gelir — elle sütun YAZMA. Fiş Excel'de Şeritçi sütunları v456'da geldi (aşağıdaki «Fiş Excel yorum kuralları»); `pdks_fis_tip_kodlari()` artık kayıt defterinden, FM «Tümü» havuzu Şeritçi'yi KAPSAMAZ (`pdks_fis_fm_varsayilan_kodlar()`).
- **Kiosk tip ekranı 5 düğme:** `#giTipSec` popup'ı `max-height + overflow-y:auto`; `(max-height:820px),(max-width:767px)` altında sistem tipleri 2 kolon grid (Karışık + Vazgeç tam satır, markup değişmedi). YALNIZ bu popup — `<768px` genel kiosk kurallarına dokunma. Ölçüm: `pdks_kiosk_v293_smoke.js` (390×700 / 768×900 / 1280×720).
- **Geçmişe dönük fiyat:** `oran_ekle` kronolojik kuralı (yeni dönem mevcut en son başlangıçtan SONRA) DEĞİŞMEDİ. Yeni `pdks_faz8b_oran_baslangic_geriye_cek($rateId,$yeni,$gerekce,$user)` (Çavuş Ücretleri › Fiyat Geçmişi › «⏪ Başlangıcı geriye çek», `form=baslangic_geri`): YALNIZ `is_admin()`, gerekçe zorunlu, tarih ≥2020-01-01 ve ≤bugün, dönem çavuş+tipin EN ESKİ aktif dönemi olmalı; eklenen aralıkta BU TİPE ait kesin hakediş satırı varsa RED (başka tipin final'i engel DEĞİL); yalnız `valid_from` değişir; aralıktaki TASLAK hakedişler `needs_recalculation` (`pdks_faz8b_oran_taslak_isaretle()`); audit `rate_baslangic_geri`. `oran_ekle` de geçmiş tarihli başlangıçta taslakları işaretler (yanıtta `isaretlenen`). `pdks_hakedis.php`'ye SATIR SİLME eklenmez (cari static smoke korur).
- **Önizleme uyarısı (engel değil):** bir tipin iş tarihinde geçerli fiyatı yoksa fiş önizlemesi + Toplu İşlem önizlemesi "«Tip» için gg.aa.yyyy tarihinde geçerli fiyat YOK" der (`pdks_faz8j_fiyatsiz_tip_uyarilari()`); hakediş yine fail-closed durur.
- Test: `php scripts/pdks_seritci_smoke.php` · `pdks_oran_geri_cek_smoke.php` · `pdks_rampaci_smoke.php` · `pdks_fis_plan_smoke.php` (14b) · `pdks_fis_yaz_smoke.php` (N) · `node scripts/pdks_kiosk_v293_smoke.js` · `pdks_kiosk_renk_smoke.js`.


### Mesai Özeti (v313 → v314 taşındı)
Çavuşun kendi mesai sayfası **Mesai Detayı** (`gunluk_isci_puantaj_detay.php`), Kart Hareketleri'nin üstünde **📊 Mesai Özeti** (v313'te yanlışlıkla Günlük Puantaj listesine konmuştu; v314'te oradan KALDIRILDI — geri ekleme). Migration YOK, yeni yetki YOK.
- **Mesai Tanımı tablosu** sütunları SABİT (sahip kararı): İşçi · Tam · Yarım · Çift · 1. Saat · 2. Saat · 3. Saat · 4. Saat · 5+ Saat · Bekliyor · İçeride · Toplam; satırlar sistem tipleri (Kadın/Erkek/Rampacı) + Toplam. "N. Saat" = o kadar saat fazla mesai yapan İŞÇİ SAYISI (`PDKS_FAZ8B_OZET_FM_SUTUN`=5, son sütun 5 ve üzeri). Ayrıca Servis (Büyük/Küçük adet) ve Çalışma Saatleri (toleranslı toplam çalışma + toplam FM).
- **v318 — sayaç kartı KALDIRILDI** (sahip kararı): Mesai Detayı'ndaki Kadın/Erkek/Rampacı/Toplam Giriş/Toplam Çıkış/Eksik Çıkış kutuları gitti (geri ekleme); yerine tabloya **Eksik Çıkış** sütunu geldi (sıra: … Bekliyor · İçeride · Eksik Çıkış · Toplam). Çıkışsız dönem: mesai AÇIK → "İçeride", mesai KAPALI → "Eksik Çıkış" (`_s_status`). Durum rozeti (Açık Mesai/Tamamlandı) sayfa başlığında.
- **v319 — Bir gün geri / ileri** (Mesai Detayı, başlığın altı `.pdks-gun-nav`): AYNI çavuş + AYNI depodaki önceki/sonraki mesai günü (başka çavuş/depo sayılmaz). Aradaki günde mesai yoksa en yakın mesaiye gider, etikette "(3 gün sonra)" yazar; yoksa düğme pasif. Salt okunur bağlantı — hedef sayfa kendi depo/yetki kapılarını uygular. Test: `php scripts/pdks_gun_nav_smoke.php` (render: `PUANTAJ_GUN_NAV=1`).
- **v320 — "11 sa → 1 FM" şikâyeti (5 ajanlı inceleme):** hesap DOĞRU; eşik 10 sa idi (fiyat döneminde saat değeri). Kök tuzak: `cavus_fiyatlari.php` "Tam Yevmiye Saati"ni çavuşun o anki süresiyle ÖN-DOLDURUYOR ve her fiyat kaydı bunu kalıcı yazıyordu → artık BOŞ gelir (boş = NULL = mesainin kendi süresi; placeholder "boş = çavuş süresi (9 sa)"). GERİ ALMA. Mesai Özeti eşiğin KAYNAĞINI yazar (`fm_kaynak`: fm_fiyat / tam_fiyat / mesai) + Çavuş Ücretleri bağlantısı; açıklama örneği eşikten üretilir; kapalı mesaide çıkışsız satır "Eksik çıkış". Saat girdisi: tek haneli ondalık = ondalık saat ("9,5"/"9.5" → 9:30), iki hane = dakika ("9.30" → 9:30). Testler: `pdks_fm_senaryo_smoke.php` (5 ayar × 9 dönem, sınıflandırıcı = özet = hakediş) · `pdks_fm_onay_zincir_smoke.php`.
- **v320 — Saatleri Düzelt (geçmiş fiyat dönemi):** Çavuş Ücretleri › Fiyat Geçmişi her satırda "✏ Saatleri Düzelt" (native `dialog#cfSaatDuzelt`, POST `form=saat_duzelt` + CSRF + `require_pdks_hakedis('rates')`, dönemin çavuşa ait olduğu denetlenir) → `pdks_faz8b_oran_saat_duzelt()`: YALNIZ 5 saat kolonu (ücretlere dokunmaz), gerekçe zorunlu, dönem aralığında KESİN hakediş varsa red, taslaklara `needs_recalculation=1`, audit `saat_duzelt` (modül foreman_worker_rates). Yeni dönem eklemek geçmişi düzeltmediği için (dönem yalnız ileri tarihli açılır) bu yol var. Test: `php scripts/pdks_saat_duzelt_smoke.php`.
- **FM kuralı:** (v315 saat başı toleransıyla etkin süre) çavuşun mesai süresi (ör. 9 sa; fiyat döneminde FM başlangıcı varsa o) aşıldıktan sonra 15 dk tolerans, başlayan her saat yukarı — 10:11 → Tam + 1 sa, 12:00 → 3 sa, 9:15 → 0. Reddedilen FM sayılmaz; Çift günde çift eşiğinden sonraki onaylı saat. Tam/Yarım/Çift sınıfı hakedişle AYNI kuraldır (Tam ≥ mesai süresi; alttan tolerans YOK).
- **Hesap TEK yerde:** `pdks_faz8b_gun_mesai_ozeti()` (config/pdks_faz8b.php) her dönemi `pdks_faz8b_donem_siniflandir()`'dan geçirir — sayfada kendi süre/FM hesabı YAZMA. Servis: `pdks_servis_toplamlar_toplu()`. Faz 8B şeması yoksa özet gizli. Çıkışsız (içeride) ve atanmamış Karışık süre/FM'ye KATILMAZ.
- CSV/XLSX'e eklenmedi. Test: `php scripts/pdks_mesai_ozeti_smoke.php` · `PUANTAJ_FAZ8B=1 PUANTAJ_SERVIS=1 php scripts/pdks_puantaj_dialog_render.php`.

### Çavuş Toplu Döküm — Fazla Mesai + XLSX (v407)
`cavus_toplu_dokum.php` (+ `_yazdir.php`) her çavuş-gün satırında **Fazla Mesai** sütunu (N sa + "1 sa: 15 kişi · 2 sa: 3 kişi"), ay özetinde **Toplam FM (saat)** kutusu, yazdırmada aynısı (tfoot toplam FM). Migration YOK, yeni yetki YOK.
- **FM hesabı YALNIZ sınıflandırıcıda:** gösterim kuralı `pdks_faz8b_fm_goster($f)` (reddedilen 0; Çift günde `odenecek_fm_saat`) — `pdks_faz8b_gun_mesai_ozeti` de bunu çağırır. Ay verisi `pdks_faz8b_ay_fm_dagilimi($start,$end,$depo,$foremanId)` = TEK `pdks_faz8b_donem_sorgu` çağrısı (N+1 YOK; oturum başına `gun_mesai_ozeti` ÇAĞIRMA). Karışık/sistem dışı tip ve çıkışsız dönem atlanır. `pdks_rapor_cavus_toplu_dokum()` satırlara `fm_saat/fm_kisi/fm_dagilim` ekler (finansal kapıya BAĞLI DEĞİL); sayfalar/pdks_rapor.php'de FM hesabı YAZMA (test denetler).
- **XLSX kolu** (`?xlsx=1`, `render_header`'dan ÖNCE; `require_perm('reports.export')`, audit `export_audit`): düz `<a class="btn">📊 XLSX İndir</a>` Yazdır'ın yanında, `can('reports.export')` kapılı. **CSV EKLEME.** Hakediş/Para Birimi/Durum sütunları yalnız finansal yetkide; topla yalnız sayı sütunlarında (hakediş tutarı TOPLANMAZ). Renkler: koyu başlık, Kadın pembe / Erkek mavi / Rampacı turuncu, FM yeşil, gün bandı `#e2ecfa` (ekrandaki `$gNo/$gYeni` mantığı), gün başı üst çizgi.
- **`xlsx_olustur()` opsiyonel renk anahtarları** (verilmezse çıktı eskisiyle aynı): sütun `baslik_dolgu/baslik_yazi/dolgu`, blok `satir_bant/bant_dolgu/satir_ust_cizgi/ust_cizgi_renk/toplam_dolgu`; `xlsx_veri_stili()` önbellek anahtarı tip|dolgu|üst çizgi (satır başına `getStyle(range)` YOK).
- **v408 — FM sütunları 1. · 2. · 3. · 4. · 5+ Saat** (sahip isteği; Mesai Özeti ile aynı kural): her sütun o kadar saat FM yapan KİŞİ sayısı (0 soluk); `pdks_faz8b_fm_kovalari($fm_dagilim)` TEK kova kuralı (`PDKS_FAZ8B_OZET_FM_SUTUN`). Ekran/yazdırma/XLSX aynı sütunlar (XLSX: FM Toplam Saat + 5 sütun; eski "FM Kişi"/"FM Dağılımı" sütunları kalktı). Yazdırma tfoot kova toplamı.
- Test: `php scripts/pdks_toplu_dokum_fm_smoke.php` · `php scripts/pdks_toplu_dokum_xlsx_smoke.php` (gerçek sayfa alt süreçte).

### Saat Başı Toleransı (v315 → v317)
Sahip kararı (v317 kesin kural): `pdks_faz8b_sure_karari()` süreyi ham değil **etkin** saatlerle hesaplar
(`pdks_faz8b_saat_basi()` / `pdks_faz8b_etkin_saatler()`): giriş VE çıkış tam saate **±15 dk** (önce de sonra da) yakınsa
o tam saate çekilir — **07:57 → 08:00 · 08:14 → 08:00 · 16:46 → 17:00 · 17:13 → 17:00**; 15 dk'dan uzaksa (07:44, 08:16,
16:44, 17:16) dokunulmaz. 08:02–17:00 = 9 sa Tam; 07:57–17:16 = 9 sa 16 dk → FM 1; 08:16–16:44 = 8 sa 28 dk → karar bekler.
Saat KİLİDİ DEĞİL (08-17/09-18/12-21 aynı çalışır). Ham kart kayıtları DEĞİŞMEZ. (v315 yalnız erken giriş/geç çıkışı,
v316 ek bir kısaltma koruması yuvarlıyordu — v317'de ikisi de bu simetrik kurala indirildi, GERİ EKLEME.)
- TEK yer sınıflandırıcı olduğu için Tam/FM/Çift, hakediş, Mesai Değerlendirme, Mesai Tanımı, Mesai Özeti hepsi
  kendiliğinden etkilenir. `toplam_dk` = etkin süre; `ham_dk`, `giris_etkin`, `cikis_etkin` ayrıca döner. Kesin
  (final) hakedişler donmuştur, değişmez; taslaklar yeniden hesaplanınca yeni kuralı alır.
- Ekran süresi: `pdks_faz8b_sure_metni()` → "9s 00dk (ham 9s 16dk)"; Mesai Detayı Süre sütunu da etkin süre + `.pdks-sure-ham` notu.
- Test: `php scripts/pdks_saat_basi_tolerans_smoke.php`.
