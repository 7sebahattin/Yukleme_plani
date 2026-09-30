# UI Notları

Son güncelleme: Sprint 32A

## Breakpoint Standardı

| Aralık | Cihaz | Davranış |
|---|---|---|
| `< 768px` | Mobil | Kart listesi, bottomnav görünür, topbar görünür |
| `768–1023px` | Tablet | Kart listesi (2-kolon grid), topbar görünür, sidebar yok |
| `≥ 900px` | Desktop | Sabit sol sidebar (220px), topbar+bottomnav gizli |
| `≥ 1024px` | Geniş Tablet / PC | `.pc-only` tablo görünür, `.mobile-only` kartlar gizli |
| `≥ 1280px` | Geniş Ekran | Sidebar 260px, 4-kolon home-grid, max-width 1400px |

**Kritik:** 900px ve 1024px ayrı eşikler. Sidebar 900px'de başlar ama tablo görünümü 1024px'de başlar. 900–1023px arası: sidebar var, kart listesi var (tablo değil).

## Mobil Görünüm (korunacak)

- Kart tabanlı liste (`record-card`, `card-list`)
- `bottomnav` 5 sekme: Ana Sayfa · Yüklemeler · ⊕ Yeni · Çıkmalar · Tanımlar
- `topbar` sticky header
- Input font-size ≥ 16px (iOS zoom önleme)
- `overflow-x: clip` html+body (iOS scroll sorunu)
- Safe area: `env(safe-area-inset-bottom)` container ve bottomnav'da

## Desktop Sidebar (Sprint 32)

- `render_desktop_sidebar()` — `config/helpers.php` içinde
- `render_header()` tarafından `</header>` sonrasında çağrılır
- `body.print-mode` veya `$print_mode=true` → sidebar çıkmaz
- Aktif sayfa: `basename($_SERVER['PHP_SELF'])` karşılaştırması
- Permission'lara göre nav grupları: Operasyon / Stok / Raporlama / Yönetim
- Kullanıcı adı/rol sidebar altında gösterilir

### Sidebar Görünürlük CSS

```css
.desktop-sidebar { display: none; }          /* varsayılan: gizli */

@media (min-width: 900px) {
    .desktop-sidebar { display: flex; ... }  /* 900px+ göster */
    .topbar          { display: none !important; }
    .bottomnav       { display: none !important; }
    .container       { margin-left: 220px !important; }
}

@media print {
    .desktop-sidebar { display: none !important; }
    .container       { margin-left: 0 !important; }
}
```

## 768–1023px Boş Liste Bugı (Sprint 31B — Düzeltildi)

**Hata:** Kayıt listesi 768–1023px arası tamamen boştu.

**Sebep:** `.mobile-only { display: none }` 768px'de başlıyordu; `.pc-only` görünümü ise 1024px'de başlıyordu. İkisi arasındaki tablet aralığında hiçbir içerik görünmüyordu.

**Düzeltme:** `.mobile-only { display: none }` eşiği `768px → 1024px` olarak değiştirildi (style.css ~3214. satır).

**Tablet sonucu:** 768–1023px arası `card-list` 2-kolonlu grid olarak görünür.

## Yazdırma Modu

`render_header($title, true)` → `$print_mode = true`

- `body.print-mode` class'ı eklenir
- Sidebar, topbar, bottomnav çıkmaz
- JS dosyaları yüklenmez
- SW kaydedilmez
- `@media print` kuralları sidebar ve container margin'i sıfırlar

## Tablo vs Kart Görünümü

```
records.php, cikmalar.php:
  .pc-only (tablo)  → display:none varsayılan, display:block @media ≥1024px
  .mobile-only (kartlar) → display:block varsayılan, display:none @media ≥1024px
```

Tablet (768–1023px): kartlar görünür, tablo gizli.  
Desktop (≥1024px): tablo görünür, kartlar gizli.

## Home Grid

```css
/* Varsayılan: 1 kolon */
@media (min-width: 500px)  { .home-grid: 3 kolon }
@media (min-width: 900px)  { .home-grid: 3 kolon }  /* sidebar ile 4 cramped olur */
@media (min-width: 1280px) { .home-grid: 4 kolon }  /* geniş ekranda sidebar+içerik yeter */
```

## SW Cache ve CSS Güncellemeleri

Sidebar görünmüyorsa veya CSS güncellemesi yansımıyorsa:

1. `sw.js` içinde `CACHE_NAME` versiyonunu artır (`yukleme-plani-v8` → `v9`)
2. Tarayıcıda hard refresh: `Ctrl+Shift+R` (veya `Cmd+Shift+R`)
3. PWA olarak kuruluysa: Chrome DevTools → Application → Service Workers → "Update"

## Z-index Referansı

| Katman | z-index |
|---|---|
| Topbar (sticky) | 100 |
| Desktop sidebar | 100 |
| Kebab dropdown | 200 |
| Bottom nav | 500 |
| Palet modal | 600 |
| Kalan modal | 1000 |
| Etiket crop overlay | 3000 |
