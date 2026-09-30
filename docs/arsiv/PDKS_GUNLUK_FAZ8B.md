# Günlük İşçi Faz 8B — Mesai Değerlendirme ve Ücretlendirme

## Kapsam

Faz 8B, Faz 8A'nın nötr kart / work-period modelinin üzerine finansal değerlendirme katmanı ekler. Giriş/çıkış tarama davranışı değiştirilmez; özellikle **çıkış hiçbir zaman ücret sınıflandırması nedeniyle engellenmez**.

## Vardiya ve tolerans

Standart vardiya `08:00–17:00` ve normal süre 9 saattir.

- Girişte 15 dakika geç tolerans vardır: `08:15` giriş kabul edilir.
- Çıkışta 15 dakika erken tolerans vardır: `16:45` çıkış kabul edilir.
- Bu nedenle `08:15–16:45` dönemi de otomatik **Tam Mesai** sayılır.
- Fiili süre 9 saat veya üzeriyse, vardiya saati kaymış olsa bile otomatik **Tam Mesai** sayılır.
- Bu şartları karşılamayan kısa dönemler otomatik Yarım yapılmaz. Muhasebe **Tam / Yarım** kararı verir.
- Girişte seçilen `declared_attendance_class` geçmiş beyan olarak korunur; finansal karar bunu sessizce değiştirmez.

## Fazla mesai

Fazla mesai planlı bitiş `17:00` sonrasından ölçülür ve her zaman muhasebe onayına düşer.

15 dakika tolerans uygulanır:

- `17:00–17:15` → FM yok
- `17:16–18:15` → 1 saat FM adayı
- `18:16–19:15` → 2 saat FM adayı
- genel kural: tolerans sonrası **başlayan her saat yukarı yuvarlanır**

Örnek: `1 saat 15 dakika` plan sonrası çalışma 1 saat; `1 saat 16 dakika` plan sonrası çalışma 2 saat olarak ücretlendirilir.

Muhasebe FM adayını **Onaylar** veya **Reddeder**. Onay olmadan hakediş kesinleştirilemez.

## Çavuş fiyatları

Fiyat kaydı hâlâ `çavuş + işçi tipi + geçerlilik tarihi` bazındadır. Eski fiyat dönemi yerinde değiştirilmez; yeni dönem eklenir ve önceki dönem kapanır.

Her dönemde:

- Tam Mesai Ücreti (`daily_rate`, mevcut kolon korunur)
- Yarım Mesai Ücreti (`half_day_rate`)
- Fazla Mesai Tipi (`hourly` / `fixed`)
- Fazla Mesai Ücreti (`overtime_rate`)
- Para Birimi

FM `hourly` ise onaylanan yuvarlanmış saat × saatlik fiyat; `fixed` ise onaylanan FM için süre ne olursa olsun tek sabit tutar uygulanır.

## Muhasebe değerlendirme

Yeni `mesai_degerlendirme.php` ekranı mevcut `entitlements_finalize` yetkisini kullanır. Bu mevcut modelde muhasebe / finansal kesinleştirme yetkisidir; yeni bir geniş rol icat edilmez.

Her work period için sistem:

- gerçek giriş/çıkış saatini,
- toplam süreyi,
- girişteki Tam/Yarım beyanını,
- otomatik Tam durumunu veya bekleyen Tam/Yarım kararını,
- yuvarlanmış FM aday saatini,
- FM onay/red durumunu

gösterir.

Kesin hakedişe bağlı değerlendirme değiştirilemez. Taslak hakediş varken değerlendirme değişirse `needs_recalculation=1` olur ve yeniden hesaplanmadan taslak güncel kabul edilmez.

## Hakediş

Faz 8B hesap motoru her work period'ı finansal olarak ayrı değerlendirir. Her dönem için:

1. Tam/Yarım sınıfı çözülür.
2. İlgili tarihteki çavuş+işçi tipi fiyat dönemi bulunur.
3. Temel Tam/Yarım ücret uygulanır.
4. FM adayı varsa muhasebe kararı aranır.
5. FM onaylıysa `hourly` veya `fixed` kuralı uygulanır.
6. Finansal snapshot `foreman_daily_entitlement_lines` içine yazılır.

Herhangi bir dönem değerlendirme veya fiyat bekliyorsa **validate-first** ilkesiyle hakediş satırları kısmen değiştirilmez.

## Migrasyon

`faz8b_migrate.php` yalnız admin tarafından manuel çalıştırılır. Additive kolonlar ekler; Faz 8A tarama tablolarını drop/recreate etmez.

Dağıtım sırası:

1. Faz 8B kodunu deploy et.
2. `faz8b_migrate.php` üzerinden migrasyonu bir kez çalıştır.
3. Şema `✓ Hazır` olduktan sonra Çavuş Fiyatları'nda Tam/Yarım/FM fiyatlarını tanımla.
4. Mesai Değerlendirme ekranında bekleyen kısa mesai ve FM kararlarını tamamla.
5. Hakedişi hesapla / kesinleştir.

## Test hedefleri

`scripts/pdks_faz8b_smoke.php` en az şu kritik sınırları doğrular:

- 08:00–17:00 → Tam, FM 0
- 08:15–17:00 → Tam
- 08:15–16:45 → Tam (iki uç toleransı)
- 08:16–16:44 → muhasebe Tam/Yarım kararı
- 17:15 çıkış → FM 0
- 17:16 çıkış → 1 saat FM adayı
- 18:15 çıkış → 1 saat FM adayı
- 18:16 çıkış → 2 saat FM adayı
- 08:15–17:30 → Tam + 1 saat FM adayı
- Faz 8B migrasyonu SQLite üzerinde idempotent
