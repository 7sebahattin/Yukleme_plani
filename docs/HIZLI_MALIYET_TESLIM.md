# Hızlı Maliyet — 30 Ekim 2026 teslimi

Durum: **yerel geliştirme, anonim işlevsel testler ve headless görsel kontrol tamam**.
Canlı kabul, migration, push, merge ve deploy yapılmadı. PR açılmadı;
AGENTS.md gereği somut sonuç sonrasında push için son açık onay beklenir.

Dal: `codex/hizli-maliyet`. Temel: güncel `origin/main` `0cf5aad` (v455).
Sürüm: v456. Çalışma kopyası:
`C:/Users/AsyaFresh/Projects/Yukleme_plani_hizli_maliyet`.
Asıl çalışma klasöründeki v341/Atlas dosyaları korunmuştur.

## Değişen dosyalar

- `hizli_maliyet.php`: oturum/yetki/CSRF kapıları, kayıt açma/düzenleme/kopyalama/listeme.
- `_hizli_maliyet_form.php`: mobil öncelikli form ve maliyet özeti.
- `config/quick_cost.php`: doğrulama ve kesin kuruş hesaplama motoru.
- `config/quick_cost_store.php`: izole PDO kaydı, depo filtresi, audit ve sürüm kontrolü.
- `assets/hizli-maliyet.js`, `assets/hizli-maliyet.css`: canlı hesaplama ve responsive tema.
- `maliyet.php`: yeni ekran bağlantısı.
- `config/helpers.php`: gezinme aktifliği ve APP_SURUM.
- `sw.js`: statik varlıklar, v456, yeni kayıt HTML'inin önbellekten dışlanması.
- `docs/sql/quick_cost_sheets.sql`: çalıştırılmamış tek tablo şeması.
- `scripts/quick_cost_smoke.php`: anonim hesaplama/kayıt testleri.
- `scripts/quick_cost_ui_smoke.js`: JS hesap ve durum geçişleri.
- `scripts/quick_cost_page_smoke.php`: bootstrap açmadan gerçek controller/form akışları.
- `scripts/quick_cost_browser_smoke.js`: gerçek headless tarayıcı ve SW yönlendirme kontrolü.
- `CLAUDE.md`, `docs/HIZLI_MALIYET.md`, bu teslim belgesi: kullanım/devir/sınırlar.

Eski `maliyet_form.php`, `maliyet_view.php`, `config/cost_calc.php`,
`config/cost_link.php`, `assets/maliyet.js`, `assets/maliyet.css` değiştirilmedi.
Eski listeye yalnız yeni sayfa bağlantısı eklendi.

## Kabul kanıtları

| Kontrol | Sonuç |
|---|---|
| PHP motor + anonim bellek içi SQLite | 31/31 |
| JS hesap/durum | 16/16 |
| Gerçek sayfa/form/store + gezinme | 29/29 |
| Headless Edge + Service Worker | 87/87 |
| Bağımsız BigInt oracle / PHP-JS eşitliği | 7.038 kontrol / 1.004 hesap |
| Bağımsız kayıt/yetki/izolasyon | 30/30 |
| PHP/JS syntax ve git diff --check | Geçti |

Zorunlu örnek A500.000 + B80.000 + D90.000 = **670.000 TL**, net20.000kg,
**33,50 TL/kg**. Sıfır bölme, bozuk/negatif sayılar, miktar korunması, farklı
birimler, sahte client toplamları, yetki/CSRF, depo, kopya kaynak bütünlüğü,
eski sürüm çakışması ve DB hatasında rollback doğrulandı.

Tarayıcı ölçüleri: 320×740, 390×844, 412×915, 1440×1000; açık/koyu tema;
taşma yok, en az44px dokunma hedefleri, kaydetme alt menü üzerinde.
Son örnek/ayrıntı/özet PNG'leri ayrıca görsel olarak incelendi.
Gerçek oturum/işletme verisi değil, anonim controller fixture kullanıldı.

Yerel kanıtlar (repo dışı, gerçek veri içermez):

- `C:/Users/AsyaFresh/AppData/Local/Temp/quick-cost-qa-c1p3wx/proof.json` ve PNG'ler.
- `C:/Users/AsyaFresh/.codex/local-data/quick-cost-independent-review-20261030.json`.
- `C:/Users/AsyaFresh/.codex/local-data/quick-cost-independent-oracle-20261030.json`.
- `C:/Users/AsyaFresh/.codex/local-data/quick-cost-independent-store-20261030.json`.

İlk controller testindeki sayısal int/float assertion ve gezinme harness'ının
ilgisiz helper stub eksikliği testte düzeltildi. İlk browser denemesi kurulu
olmayan Playwright Chromium nedeniyle başlamadı; kurulu Edge kullanıldı.
Browser harness seçicisi ve bağımsız beklenen ara toplamı düzeltildi.
Son kabul yukarıdaki başarılı koşulardır; önceki başarısız koşular kabul değildir.

## Kalan sınırlar ve sonraki adım

MySQL/InnoDB ve fiziksel iPhone/Safari/Android üzerinde kabul yapılmadı.
Kayıt için yeni tablo kurulmalıdır; canlıya uygulama son açık migration
onayına bağlıdır. Audit mevcut helper'ın catch/log politikasını korur.
Fason/Üretim ilk sürümde aynı fiyat varsayılanlarını kullanır; seçim fiyatları
silmez. Tür bazlı gelecekteki varsayılanlar açık bir işlemle uygulanmalıdır.

Yayın öncesi güncel main ve sürüm çakışması yeniden kontrol edilir.
Önce somut commit için push/PR onayı alınır; merge/deploy ve mevcut DB migration
bu onaydan ayrı tutulur. Eski Atlas D5/COPY-11B görsel işleri bu teslimle
tamamlanmış sayılmaz.
