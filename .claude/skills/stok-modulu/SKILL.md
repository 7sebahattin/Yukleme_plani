---
name: stok-modulu
description: Stok (muhasebe) modülü (stok_kartlari/tanimlari/kaydet.php + 4 fiş sayfası, config/stok_core.php + stok_ui.php, assets/stok.js/css): stok kartı, tanımlar, INSERT-only defter (stok_hareketler), hareketli ağırlıklı ortalama maliyet, depo filtresi, şema kartı, testler. stok_* / transfer_fisleri.php dosyalarına dokunurken kullan.
---

# Stok (muhasebe) — stok_*

> Plan: ayrı, INSERT-only defter; yalnız `is_admin()` (sidebar "Stok" grubu, `nav_muhasebe_gruplari()`). cari-modulu'nun aynası.
> **v524 Parça A:** kartlar + tanımlar + şema + kaydet ucu (7 dal). **Parça B:** 4 fiş sayfası (`stok_giris_fisleri`, `stok_cikis_fisleri`, `transfer_fisleri`, `stok_donusum_fisleri`), `fis_kaydet`/`fis_iptal`, cari bağı, kantardan doldur.

## Kapsam
- Sayfalar (kök, hepsi **GET-only**): `stok_kartlari` (master-detail: sol liste + sağ detay/hareketler; `?id=`, `?parca=detay` kısmi yanıt, `?yeni=1`, `?ara= &tip= &grup= &durum=aktif|pasif|tumu|kritik`) · `stok_tanimlari` (`?sekme=grup|giris_neden|cikis_neden|ice_aktar`). Fiş sayfaları Parça A'da yer tutucu ("hazırlanıyor").
- **Yazma TEK uç: `stok_kaydet.php`** (yalnız POST; `islem`: kart_kaydet/kart_durum/kart_sil/tanim_kaydet/tanim_durum/tanim_sil/ice_aktar; B: +fis_kaydet/fis_iptal). Sıra: yöntem → login → `csrf_check` → `stok_sayfa_kapisi` → dalın İLK ifadesi tek `stok_kapi(...)` → yalnız `stok_core` fonksiyonu. JSON: 200/422/409 (`yetersiz_stok`·`kantar_aktarilmis`·`hareketli`·`kullanimda`)/403/503; klasik form: 303 + flash, `geri` yalnız `^(stok_[a-z_]+|transfer_fisleri)\.php`.
- Çekirdek `config/stok_core.php` (kurallar, DB; `require_once cari_core.php` yalnız yardımcılar için), işaretleme `config/stok_ui.php` (sorgu YOK), istemci `assets/stok.js` + `stok.css` (kök `.stok`, önek `st-`; style.css/app.js'e DOKUNMAZ).

## Değiştirmeden önce oku
- **Ayrı defter / iki stok doğrusu:** `stok_hareketler` Ürün Stoğu (`stok.php`, kantar−yükleme türetimi) ve Malzeme Stok'tan (`material_stock_movements`) BAĞIMSIZDIR; onları okumaz/yazmaz. Ekranlardaki "Bu defter … bağımsızdır" notunu kaldırma. Kantar yalnız salt okunur ön-doldurma (B); aynı kantar fişine ikinci geçerli fiş = `kantar_aktarilmis`.
- **Defter INSERT-only:** `stok_hareketler` ve `stok_fis_satirlar` satırına UPDATE/DELETE YOK. `stok_fisler` UPDATE beyaz listesi: `fis_no = ?[, cari_hareket_id = ?]` ve `durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?` (statik smoke birebir denetler). Düzeltme = iptal (neden ≥3; ters satırlar `ters_id` ile INSERT) + "Kopyala". Taslak YOK.
- **Miktar milli tam sayı** (ana birim × 1000; `stok_miktar_coz`/`stok_miktar_bicim`/`stok_milli`/`stok_milli_db`). lt ve m dışındaki birimler TAM SAYI (kesir → null, sessiz yuvarlama yok). KG ekranda tam sayı + binlik nokta. Para kuruş tam sayı (`cari_kurus`/`cari_tutar_bicim`).
- **Maliyet:** hareketli ağırlıklı ortalama, ÜRÜN bazında (depolar ortak), TRY, KDV hariç. `stok_maliyet_uygula` SAF motor (DB yok) — içeride `ort` = kuruş/birim (float); DB'de `ort_sonra` = TL/birim, `q_sonra` = birim, `v_sonra` = TL. Geriye dönük yeniden değerleme YOK (id sırası). Maliyet durumu = kartın son defter satırı (`stok_maliyet_durumu`, depo filtresiz).
- **Depo kuralı (cari'nin TERSİ):** bakiye/hareket okumaları aktif depo filtreli — YALNIZ `stok_depo_filtre()` → `depo_sql_in` (named param yok). Depo boş satır tüm depolarda görünür. Maliyet ortalaması depo filtresiz.
- **Kapı:** `stok_can()` = `is_admin()` (TEK değişim noktası; `stok.read/write` izinlerine BAĞLANMADI — onlar stok.php/malzeme içindir). Yazan çekirdek fonksiyonlarda yetki kontrolü YOK, `audit_log_event(…, 'stok', …)` VAR.
- **Şema yalnız `migrate.php` "Stok (muhasebe)" kartı** (`mg_kart_ac('stok')`, `ne=stok`, `stok_migrate`; CREATE TABLE IF NOT EXISTS, ALTER/FK yok; tanım tohumu yalnız tablo yeni kurulduysa). Sayfalar tablo yoksa uyarı kartı (`stok_sayfa_kapisi`), uç 503. Sistem nedenleri (`kod` dolu): adı değişmez, silinmez, pasifleşir.
- **`stok_` dosya adı ↔ `sw.js` `SW_MUHASEBE_RE`:** `stok_` önekinin tamamı (stok_kaydet dahil) + `transfer_fis*` önbelleğe alınmaz; `/stok.php` ve `/malzeme_stok_*` KAPSAM DIŞI kalmalı. `assets/stok.*` SHELL'e girmez. Değişince APP_SURUM/SW sürümünü artır (surum-artir).
- **Sayfalar GET-only:** sayfaya `$_POST`/SQL ekleme (static smoke denetler); her yazma `stok_kaydet.php`'ye. Cari'ye yalnız `cari_core` FONKSİYONLARIYLA (B: `cari_hareket_ekle(…, $kaynak)`, `cari_kaynakli_iptal`); `cari_*` tablosuna SQL yazma.
- **nav testi:** `nav_muhasebe_static_smoke` `config/stok_core.php` içeren sayfayı çalışan modül sayar (şema kapısı + render_header/footer + GET-only); içermeyen fiş sayfası "hazırlanıyor" taşımalı. `kantar_fisleri.php` 302 → kantar.php DEĞİŞMEZ.
- **UI:** PC ≥1024 TEK EKRAN (`.st--tek-ekran`, `--st-sol`), liste ve detay ayrı kayar; mobil liste ↔ `?id=` detay, alt sabit eylem çubuğu `bottom: calc(var(--bn-h) + safe-area)` (z-index VERME). Modal `.pm-overlay > .pm-dialog.st-dialog > form` (z ≥600). Girdi <768 16px, dokunma ≥44px. Düzenleme verisi `#stKartVeri` (`stok_json_script`, JSON_HEX_*; `hareketli` bayrağı ana birim kilidi ipucunu açar).

## Test
```
php scripts/stok_core_smoke.php && php scripts/stok_gate_static_smoke.php && php scripts/stok_ui_smoke.php
php scripts/nav_muhasebe_static_smoke.php && php scripts/migrate_panel_smoke.php
NODE_PATH=/opt/node-tools/node_modules node scripts/stok_ui_smoke.js     # tarayıcı uçtan uca (1366 / 820 / 390)
STOK_SHOT=<klasör> node scripts/stok_ui_smoke.js                          # ekran görüntüleri (A_pc.png, A_mobil.png …)
php scripts/stok_ui_render.php kartlar id=1 > _test_stok.html            # tek sayfa HTML (--sema / --bos / --yonetici-degil)
```
`stok_ui_smoke.js` gerçek PHP'yi köprüler: `scripts/_stok_e2e_sunucu.js` (Node http → `scripts/_stok_istek.php` alt süreç) + `stok_ui_e2e_setup.php` (dosya SQLite + örnek veri) + `stok_ui_e2e_say.php` (DB gerçeği). `_stok_test_lib.php` `stok_t_fis/stok_t_iptal` YALNIZ TEST defter yazıcısıdır (Parça B'nin `stok_fis_kaydet`'i gelince UI testleri onu kullanabilir).
