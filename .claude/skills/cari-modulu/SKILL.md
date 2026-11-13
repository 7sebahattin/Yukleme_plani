---
name: cari-modulu
description: Cari İşlemler modülü (cari_kartlari/fisler/dekontlar/tanimlari/ekstre/kaydet.php, config/cari_core.php + cari_ui.php, assets/cari.js/css): cari kart, tahsilat/ödeme/dekont/virman, değişmez ledger, ekstre, tanımlar, şema kartı, testler. cari_* dosyalarına dokunurken kullan.
---

# Cari İşlemler (cari_*)

> Plan/tasarım kararları: ledger tabanlı, yalnız `is_admin()` (sidebar "Cari İşlemler" grubu, `nav_muhasebe_gruplari()`). Sayfalar `nav_muhasebe_static_smoke` istisnasıyla "çalışan modül" sayılır.

## Kapsam
- Sayfalar (kök, hepsi **GET-only**): `cari_kartlari` (master-detail: sol liste + sağ detay/ekstre; `?id=`, `?parca=detay|ekstre|kart` kısmi yanıt) · `cari_fisler` (tahsilat/ödeme/açılış) · `cari_dekontlar` (borç/alacak dekontu, virman) · `cari_tanimlari` (grup, ödeme şekli, firmalardan içe aktar) · `cari_ekstre` (yazdır/xlsx/csv).
- **Yazma TEK uç: `cari_kaydet.php`** (yalnız POST; `islem`: kart_kaydet/kart_durum/kart_sil/hareket_ekle/virman_ekle/hareket_iptal/tanim_kaydet/tanim_durum/tanim_sil/ice_aktar). Sıra: yöntem → login → `csrf_check` → şema kapısı → `cari_kapi()` → yalnız `cari_core` fonksiyonu. JSON (`X-Requested-With: fetch`): 200/422 (kod kullanimda/hareketli/zaten_iptal)/409/403/503; klasik form: 303 + flash, `geri` yalnız `^cari_[a-z]+\.php`.
- Çekirdek `config/cari_core.php` (kurallar, DB), işaretleme `config/cari_ui.php`, istemci `assets/cari.js` + `cari.css` (style.css/app.js'e DOKUNMAZ).

## Değiştirmeden önce oku
- **Ledger DEĞİŞMEZ:** `cari_hareketler` satırına UPDATE/DELETE YOK; düzeltme = iptal (durum=iptal + neden ≥3 + kim/ne zaman) + yeni belge. Virman = iki bacak, birlikte iptal.
- **Kaynak bağı (v524, Stok Parça B):** başka modül cari satırını YALNIZ `cari_hareket_ekle($pdo, $g, $uid, ['modul'=>…, 'id'=>…])` ile yazar (`$g`/POST'tan `kaynak_*` OKUNMAZ; `cari_kaydet.php` 4. parametre geçmez) ve `cari_kaynakli_iptal($pdo, $modul, $id, $neden, $uid)` ile iptal eder (idempotent, yetki yok, audit cancel). `cari_hareket_iptal` kaynaklı satırı 409 `kaynakli` ile reddeder. `cari_core`'a `stok_` adı yazma (stok gate smoke).
- **Para birimleri ASLA toplanmaz** (TRY/USD/EUR ayrı bakiye, ayrı özet; tutar kuruş tamsayı). Kur yalnız bilgi.
- **Yön kuralı:** bakiye = Borç − Alacak (+ = cari bize borçlu). Tahsilat/alacak dekontu = Alacak (bakiye azalır), ödeme/borç dekontu = Borç; açılışta yönü kullanıcı seçer; virman kaynak Alacak, hedef Borç.
- **Şema yalnız `migrate.php` "Cari İşlemler" kartı** (`cari_migrate`, CREATE TABLE IF NOT EXISTS); sayfalar tablo yoksa uyarı kartı (`cari_sayfa_kapisi`), yazma ucu 503 — sayfa tablo YARATMAZ. Test sitesinde şema yine migrate'ten kurulur.
- **`cari_` dosya adı ↔ `sw.js` `SW_MUHASEBE_RE`:** `cari*.php` önbelleğe alınmaz (ticari veri); yeni sayfa adı `cari_` ile başlamalı. `assets/cari.*` değişince APP_SURUM/SW sürümünü artır (surum-artir skill'i).
- **Sayfalar GET-only:** sayfaya `$_POST` ekleme (static smoke denetler); her yazma `cari_kaydet.php`'ye.
- **nav testi istisnası:** `nav_muhasebe_static_smoke` `config/cari_core.php` içeren sayfayı yer tutucu saymaz (şema kapısı + render_header/footer + GET-only denetler).
- `data-kopya` (`cari_json_attr`, h() ile) ve `#crCariVeri`/`#crKartVeri` (`cari_json_script`, JSON_HEX_*) gömme yardımcılarını atlama; elle JSON basma.
- **JS tuzakları (v entegrasyon):** formlarda `name="id"` girdisi var → `form.id` ELEMENT döner; kimlik için `getAttribute('id')`. `parca=detay` yanıtı `#crDetay` + ARDINDAN yeni `ul#crMenuDekont`/`ul#crMenuDiger` getirir (JS `menuleriYenile`). Menü Pasifleştir/Aktifleştir `data-aktif`, tanım switch'i `data-id` taşımalı. `[hidden]` + satır içi `display` çakışır (`.cari-ozel-tarih`). Modal açılışında ilk alana odak 30 ms sonra verilir (testte bekle).
- Modal içinde `<form>`: `.pm-dialog > form` flex kuralı (CLAUDE.md) bozulmamalı; modallar z-index ≥ 600; mobil girdi ≥16px, dokunma hedefi ≥44px.

## Test
```
php scripts/cari_core_smoke.php && php scripts/cari_gate_static_smoke.php && php scripts/cari_ui_smoke.php
php scripts/nav_muhasebe_static_smoke.php && php scripts/migrate_panel_smoke.php
NODE_PATH=/opt/node-tools/node_modules node scripts/cari_ui_smoke.js     # tarayıcı uçtan uca (215 adım)
CARI_SHOT=<klasör> node scripts/cari_ui_smoke.js                          # ekran görüntüleri
php scripts/cari_ui_render.php kartlar id=1 > _test_cari.html             # tek sayfa HTML
```
`cari_ui_smoke.js` gerçek PHP'yi köprüler: `scripts/_cari_e2e_sunucu.js` (Node http → `scripts/_cari_istek.php` alt süreç) + `cari_ui_e2e_setup.php` (dosya SQLite + örnek veri) + `cari_ui_e2e_say.php` (DB gerçeği). `tum_testler.sh` `_*.js` yardımcıları test saymaz.
