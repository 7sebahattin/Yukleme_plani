---
name: bottomnav
description: Mobil alt çubuk, nav_aktif_anahtar, nav_alt_sayfalar/izinler, çerez sözleşmesi, --bn-h. Gezinme/alt çubuk koduna ya da yeni sayfa ailesi eklerken kullan.
---

# Mobil Alt Çubuk (bottomnav)

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

## Mobil Alt Çubuk — bottomnav (Sprint Alt-Menü-01)

Onaylı tasarım "Varyant A · Kabartma Karo": yüzen yuvarlak dok, her sekme renkli
3B karo (SVG). **Ana Sayfa solda sabit, "Diğer" sağda**, arada kullanıma göre
dolan slotlar: **390px altında 3, üstünde 4** (sunucu hep 4 çizer, CSS
`@media (max-width:389px)` 4.'yü gizler). Yalnız `<768px`'te görünür.

**Dosyalar:** `config/helpers.php` (`nav_aktif_anahtar` · `nav_alt_sayfalar` ·
`nav_alt_soguk_sira` · `nav_alt_izinler` · `nav_alt_cerez_oku` · `nav_alt_model` ·
`nav_alt_ciz`) · `assets/style.css` ("Bottom Navigation" bloğu) · `assets/app.js`
(sondaki bottomnav modülü) · `assets/nav-icons/*.svg` (16 ikon, sw.js SHELL'de).

- **Aktif bölüm TEK kaynak: `nav_aktif_anahtar()`** — sidebar da çubuk da onu
  okur. Hal Kayıt'ta yalnız `hks` (Ana Sayfa değil — `$a_home`'daki `!$in_hks`),
  `maliyet_*`'te `rapor`. Yeni bir sayfa ailesi eklerken ORAYA ekle.
- **Sayfa kaydı `nav_alt_sayfalar()`** saf veridir (DB/yetki yok): etiket,
  kısa etiket, renk, grup, **ikon yolu**. İkonu PNG/WebP'ye çevirmek yalnız
  `ikon` alanını değiştirmektir (`?v=<filemtime>` otomatik; sw.js SHELL'i güncelle).
- **Yetki kapısı `nav_alt_izinler()`** — her satır HEDEF SAYFANIN kendi
  kapısıyla aynı (`first_allowed_page()` ilkesi). Kapısı değişen sayfada
  burayı da güncelle. Stok (`stok.php`), maliyet, kantar raporu bilerek YOK.
- **Ana Sayfa hedefi:** `dashboard.read` → `index.php`, yoksa
  `first_allowed_page()`; o da null ise Ana Sayfa çizilmez. Ana Sayfa ASLA 403
  veren bir bağlantı olmaz; `index.php`'de aktiftir — `dashboard.read`'i
  olmayan kullanıcıda hedefi `first_allowed_page()` olduğu için O sayfa
  ailesinde (ör. Personel Takibi) de `aria-current` taşır (bottomnav_smoke
  bunu sabitliyor). Ne Ana Sayfa ne
  izinli sayfa varsa çubuk hiç basılmaz ve body `bn-yok` alır (`--bn-h: 0`).
- **"Diğer"** yalnız slotlara sığmayan sayfa varsa çıkar (tam 4 aday → yalnız
  dar ekranda, `.bn-more-dar`). Güncel sayfa görünen slotta değilse "Diğer"
  onun ikonunu + rozet + etiketini alır ve `aria-current="page"` taşır (dar
  ekranda 4. slot için bunu app.js yapar, genişlik değişince de). Alt sayfa
  sidebar gibi gruplu (Operasyon / Yönetim), aktif depo rozetini gösterir,
  Esc/✕/arka plan kapatır, odağı Diğer'e döndürür.
- **Kullanım YALNIZ cihazda:** `localStorage['asya_nav_kullanim_<uid>']` =
  `[[anahtar, ms], …]` (en çok 90 gün / 200 kayıt; aynı bölümde 30 dk içindeki
  art arda yüklemeler TEK ziyaret). Puan = Σ 0,5^(gün/14); Ana Sayfa sayılmaz.
  **Histerezis:** dışarıdaki sayfa en zayıf slotun yerine ancak
  `puan > en zayıf × 1,25 + 0,5` ise girer; kalan slot yerini korur. Soğuk
  başlangıç: Yüklemeler, Bildirim, Personel, Raporlar, sonra sidebar sırası.
- **Sabitle:** "Diğer" → "Sabitle" modu, en çok 4. Sabitler önce gelir ve
  sıralanmaz; `localStorage['asya_nav_sabit_<uid>']` + çerezde `p:`.
- **Çerez sözleşmesi `asya_nav`** (app.js yazar, `encodeURIComponent`, path=/,
  180 gün, SameSite=Lax): `u:<uid>;s:k1,k2,k3,k4;p:k1`. PHP `u` tutmazsa YOK
  SAYAR; her anahtar **beyaz liste + yetki kapısından** geçer — sahte çerez
  yalnız izinli sayfaları yeniden sıralayabilir. PHP sırayı çizer; app.js
  yüklemede yeniden sıralar ve **yeni işaretleme ÜRETMEZ**: izinli ama slotta
  olmayan sayfalar dokta `hidden` öğe olarak durur, JS yalnız yer değiştirir.
- **`--bn-h`** (style.css `:root`, 92px = dok ~84 + alt boşluk 8; güvenli alan
  HARİÇ; ≥768px ve `bn-yok`'ta 0): çubuğa göre yer bırakan HER kural
  `calc(var(--bn-h) + … + env(safe-area-inset-bottom, 0px))` kullanır —
  `.container` (mobil), `halkayit/index.php` iframe payı, `.bb-bar`,
  `hesap.css .hs-save`, `pdks.css` kiosk, `[data-record-id]` scroll-margin.
  **Sabit px yazma** — eski 58px HKS payı çubuğu büyütünce iframe'in altını örttü.
- `.bottomnav` tam genişlik şeffaf kapsayıcıdır (z-index 500, `pointer-events:none`),
  görünen dok `.bn-dock`; depo şeridi `.bottomnav::before` (`--depot-accent`).
  Koyu temada pasif ikon `saturate(.55) brightness(.86)`; aktif karo
  `translateY(-10px) scale(1.14)` + renkli alt çizgi, basınca `.9`;
  `prefers-reduced-motion`'da hareket yok. Etiketler sığmazsa 9.5px'e iner
  (`.tight`), yine sığmazsa `data-xs` kısa hâli.
- **Test:** `BOTTOMNAV_OUT=/tmp/bn php scripts/bottomnav_render.php` →
  `BOTTOMNAV_OUT=/tmp/bn node scripts/bottomnav_smoke.js` (Playwright; yoksa
  atlar). (A) tasarımdan bağımsız değişmezler, (B) bu tasarım: slot sayıları,
  tek `aria-current`, HKS/maliyet aktifliği, Ana Sayfa hedefi, JS kapalı sunucu
  sırası + sahte/başka kullanıcı çerezi, histerezis, Diğer aç/kapat, Sabitle,
  gerçek http kökeninde çerez yazımı, koyu tema, azaltılmış hareket. Çubuğa
  dokunduysan çalıştır.
- **`bnAltPay()` / `ekranAlti()` app.js'in EN ÜSTÜNDE, IIFE'lerin DIŞINDADIR** —
  dosya dört ayrı IIFE'dir; ilkinin içine konunca öneri kutusu (ikinci IIFE)
  her odaklanmada ReferenceError veriyordu. "Aşağıda yer var mı" kararı
  `ekranAlti()` ile verilir, ama `position:fixed` bir öğenin `bottom`'u
  viewport'un altından ölçüldüğü için `bottom` değerine `--bn-h` DÜŞÜLMEZ.
  Test: `node scripts/suggest_list_smoke.js`.

---
