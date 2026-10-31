# Ana menü ve Hızlı Maliyet görünümü — v461

31 Ekim 2026. Dal `codex/home-navigation-cost-polish`, temel `f2fea43` / v460.
Bu belge yayın öncesindeki yerel teslimi kaydeder. Güncel yayın sonucu PR
ve agent-workflow checkpoint kaydından izlenir.

## Değişiklik

Sidebar'daki modül bağlantıları ana sayfada kart olarak bulunur. Eksik
Siparişler, Hızlı Maliyet ve Roller kartları eklendi. On muhasebe grubunun51
alt kartı sidebar ile aynı `nav_muhasebe_gruplari()` kataloğundan üretilir.
Admin tüm yetkilerde69 benzersiz ana kart bulunur. Ana Sayfa kendisine kart
oluşturmaz; tema/depo/çıkış kontrolleri modül kartı olarak çoğaltılmaz.

Yeni admin kartları admin kapısını korur. Mevcut admin olmayan defs.read ve
users.admin kartları korunur. Tek Beyan/HKS/stok/Hesap izni olan kullanıcının
kendi kartını gizleyen bölüm koşulları düzeltildi. Sayaç, mail ve günlük yedek
sorgularının bulunduğu kaynak prefix'i temel commit ile aynıdır.

Birim seçicilerinin sağında tema rengine bağlı aşağı üçgen bulunur. Üçgen
`pointer-events:none` kullanır; native seçim/klavye/readonly ve48px alan
korunur. Ortak renderer sayesinde ek kalemler de aynı oku kullanır.
Yalnız **Yeni kalem ekle** altındaki uzun malzeme paragrafı kaldırıldı.
Malzeme kalemi, fiyatı ve sözel hesap açıklaması yerinde kalır.

Hızlı Maliyet için font/dış kaynak içermeyen64px SVG dolar simgesi üretildi:
yeşil kabartmalı karo, altın para, dolar çizgisi, gradyan ve gölge. Aynı
`assets/nav-icons/qcost.svg` ana kart, sidebar ve mobil menüde kullanılır.
APP_SURUM/SW v461; yeni ikon PWA kabuğuna eklendi. İş HTML cache istisnası aynı.

## Dosyalar

- `index.php`
- `_hizli_maliyet_form.php`, `assets/hizli-maliyet.css`
- `assets/nav-icons/qcost.svg`, `config/helpers.php`, `sw.js`
- `scripts/dashboard_sidebar_parity_smoke.php`, `scripts/dashboard_navigation_smoke.js`
- `scripts/quick_cost_sidebar_smoke.php`, `scripts/quick_cost_browser_smoke.js`
- `CLAUDE.md`, `docs/HIZLI_MALIYET.md`, plan ve bu teslim belgesi

## Ana ajanın çalıştırdığı kabul

| Takım | Sonuç |
| --- | --- |
| Dashboard/sidebar eşleşmesi,31 anonim yetki profili | 2.471 kontrol geçti |
| Dashboard headless Edge,320/390/412/1440px ve iki tema | 63 kontrol geçti |
| Quick sidebar/mobil ortak ikon/yetki/aktiflik/PWA | 35 kontrol geçti |
| Quick form/liste/SW ve hesap regresyonu, dört genişlik/iki tema | 435 kontrol geçti |
| Değişen PHP/JS lint ve git diff whitespace | temiz |

Mevcut670.000TL/33,50TLkg örneği, ek kalem, sıfır, büyük harf, payload ve
Ayar regresyonları geçer. Hesap/store kaynakları değiştirilmediğinden önceki
saf motor/store testleri gerekçesiz tekrar edilmedi. Yeni test bootstrap,
helpers migration IIFE, DB veya yedek/sayaç işlemlerini çalıştırmaz; yalnız
sunum sınırı ve gerekli adlandırılmış fonksiyonlar çıkarılır. Sayaç rozeti
koruması anonim değerlerle kontrol edilir. Gerçek veri/görüntü kullanılmaz.

Proof dosyaları:
- `C:/Users/AsyaFresh/AppData/Local/Temp/dashboard-nav-qa-pPE4lx/proof.json`
- `C:/Users/AsyaFresh/AppData/Local/Temp/quick-cost-qa-q9Laiq/proof.json`

Ana ajan masaüstü ana sayfa/dolar simgesi, mobil Birim oku ve dar koyu tema
görüntülerini inceledi. Bağımsız Codex kaynak incelemesinde engelleyici bulgu yok.
Bu bir menü/kart eşleşmesi kabulüdür;69 hedefin ticari işlevleri tek tek test
edilmiş sayılmaz. Fiziksel telefon/Safari veya genel CI tümü yeşil kabulü yok;
önceki iki ilgisiz Sipariş test beklentisi bu kapsamda değiştirilmedi.

SQL/migration gerekmez. Kimlik/yetki kapıları, hesap motoru, kayıt/veri modeli
ve mevcut maliyet kayıtları değiştirilmedi. Atlas otomasyonu PAUSED, Atlas
aktarımı ve önceki veri/görsel kabulleri değişmez. Somut commit sonrasında
AGENTS.md uyarınca alınan son açık yayın onayıyla push/PR/merge yapılabilir.
