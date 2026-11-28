---
name: fatura-modulu
description: Fatura modülü (satis_faturalari/alis_faturalari/sevk_irsaliyeleri/alinan_siparis_fisleri/fatura_tanimlari/fatura_kaydet/fatura_cikti.php, config/fatura_core.php + fatura_ui.php, assets/fatura.js/css): 8 belge türü, cari dekont + stok fişi bağı, dönüşüm (sipariş→irsaliye→fatura→iade) ve türetilmiş kalan, seri numarası, tanımlar, A4 yazdır + xlsx/csv, testler. fatura_* dosyalarına dokunurken kullan.
---

# Fatura (fatura_*)

**v611 — Stok bağı + depo kuralı (davranış notu, fatura kodu DEĞİŞMEDİ):** stoklu fatura `stok_fis_kaydet()` ile stok fişi üretir; v611'den beri `stok_fis_dogrula` Depo Tanımları geçişi (`depo_gecis_aktif()`) kapalıyken HER stok fişini reddeder (`kod depo_gecis`). `fatura_core.php` depoyu hâlâ `stok_depo_secenek()` ile ön-doğruladığı için geçiş kapalıyken stoklu fatura işlem ortasında `depo_gecis` hatasıyla düşer (transaction geri alınır, yarım kayıt kalmaz). Fatura formuna depo-geçiş uyarısı eklemek ayrı iş; bkz. stok-modulu v611.

> v527 (Dalga A arka uç + Dalga B ön yüz). Kapı v550'den beri `muhasebe_modul_can` (Roller `muhasebe.fatura.*`; bkz. `rol-yonetimi`) (sidebar "Fatura" grubu, `nav_muhasebe_gruplari()`). stok-modulu / cari-modulu kalıbının eşi.

## Kapsam
- **Belge türleri** (`fatura_turleri()`, tek başlık tablosu `fatura_belgeler.tur`): satis · satis_ihracat · satis_iade (sayfa `satis_faturalari`) · alis · alis_mustahsil · alis_iade (`alis_faturalari`) · irsaliye (`sevk_irsaliyeleri`) · siparis (`alinan_siparis_fisleri`; v527'de 302 → urun_siparis KALKTI, Ürün Siparişleri ayrı).
- **Sayfalar (GET-only)**: `fatura_sayfa_govde($pdo, $sinif)` — sol liste (GET süzgeç `?ara= &tur= &durum= &donem=tumu|30g|bu_ay|gecen_ay|ozel (&bas &bit) &cari= (&cari_ara) &sd=`), sağ panel `?id=` detay · `?yeni=1[&tur=]` form · `?kopya=ID` · `?yeni=1&tur=X&kaynak=ID[,ID]` (ya da `kaynak[]=`) dönüşüm formu. `fatura_parca()` render_header'dan ÖNCE: `?parca=detay` (HTML) · `cari_ozet` · `kaynaklar` · `kaynak_satirlari` (JSON). `fatura_tanimlari.php?sekme=kdv|tevkifat|kesinti|istisna|seri|teslim|sablon|odeme`.
- **Yazma TEK uç `fatura_kaydet.php`** (POST; 6 dal: belge_kaydet · belge_iptal · siparis_kapat · tanim_kaydet · tanim_durum · tanim_sil). **Salt okunur uç `fatura_cikti.php`**: `?id=N[&otomatik=1]` A4 yazdır · `?sinif=&bicim=xlsx|csv` + süzgeçler (`require_perm('reports.export')` + `export_audit('fatura','liste',…)`).
- Çekirdek `config/fatura_core.php` (kurallar + DB; cari/stok'a YALNIZ fonksiyonlarıyla), işaretleme `config/fatura_ui.php` (SORGU YOK), istemci `assets/fatura.js|css` (kök `.fatura#fatura`, önek `ft-`; style.css/app.js'e DOKUNMAZ, SHELL'e girmez).

## Değiştirmeden önce oku
- **Hesap TAM SAYI** (plan §4): para kuruş, miktar milli, fiyat on-binde (f4), oran bp. Satır: brut=round(milli·f4/100000) → isk=round(brut·bp/10000) → net → kdv=round(net·oran/100); yuvarlama satırda. Tevkifat başlıkta round(kdv·pay/payda); stopaj/Bağ-Kur yalnız müstahsilde round(net·bp/10000); cari = genel − kesintiler. **JS (`carpBol`, `ondalikInt`) yalnız önizleme**, sunucu esas; JS toplam POST ETMEZ. Formülü değiştirirsen `fatura_satir_hesapla`/`fatura_toplam_hesapla` ve fatura.js `hepsi()` birlikte değişir — `fatura_ui_smoke.js` §4 örneklerini (15.197,86 · 10.600,00 · 97.000,00 · 79.505,94 ₺) kuruşu kuruşuna sabitler.
- **Değişmezlik:** `fatura_satirlar` INSERT-only; `fatura_belgeler` DELETE yok, UPDATE yalnız 3 beyaz liste kalıbı (gate smoke tam metin). Düzeltme = iptal (neden 3–500) + Kopyala. Taslak YOK.
- **Cari/stok bağı:** dekont yalnız `cari_hareket_ekle(..., ['modul'=>'fatura','id'=>$fid])`; stok fişi `stok_fis_kaydet($pdo,$sg,$uid,['modul'=>'fatura','id'=>$fid])` cari'siz (`'cari_id' => ''`); iç !ok → `FATURA_IC_HATA` → hepsi geri. İrsaliyeden gelen fatura satırı ASLA stok üretmez (çift sayım). İptal aşağıdan yukarı (`bagli_belge` 409, JSON `bagli` listesi).
- **Kalan miktar kolon DEĞİL** (türetilmiş sorgu; iptal edilen belge sayaçtan düşer). Aşım 409 `kalan_asimi` (UI "Kalana eşitle" `data-kalan-milli` ile).
- **Seri numarası** yalnız kayıtta (`fatura_seri_kilitle`); formdaki önizleme (`#ftVeri.seri_onizleme`, `fatura_seri_durum`) tahminidir. Karşı tarafın numarası (`fatura_no`) elle; mükerrer 409.
- **Satır alanları paralel diziler** (`kart_id[] kart_ara[] urun_adi[] miktar[] birim[] birim_fiyat[] iskonto[] kdv_orani[] gtip[] satir_brut_kg[] satir_net_kg[] satir_kap[] lot[] satir_aciklama[] kaynak_satir_id[]`); `i` = DOM sırası. Görünür ürün girdisi `kart_ara[]`; JS gönderirken kartsız (serbest) satırda metni `urun_adi[]`'na taşır (`formVerisi`). Türe göre gizlenen alanlar `data-ft-goster` + `disabled` (gönderilmez) — bir dizinin TÜM satırlarda aynı durumda olması şart (paralel hizalama).
- **`FATURA_SATIR_MAX = 60`** (max_input_vars). UI "Satır ekle" sınırda pasif.
- **Kopya** tanım seçimlerini AD/KOD snapshot'ıyla döndürür; `fatura_kopya_eslestir()` aktif tanım id'lerine eşler.
- **Son fiyat otomatik yazılmaz** (yalnız placeholder + "Uygula", farklı pb'de pasif). Tutar yazıyla YOK (karar #8). Yazdırma alt notu "Bu çıktı bilgi amaçlıdır, resmî e-belge değildir." (karar #16). Firma başlığı `siparis_inv_ihracatci()` (config/siparis_invoice.php) TEK kaynak — OGL Invoice şablonu da onu okur.
- **UI düzeni:** PC ≥1024 TEK EKRAN (`.ft--tek-ekran`, form modunda liste 56px raya iner `.ft-ana--form`, ≥1600 açık); yalnız `.ft-satirlar-kaydir` kayar. Mobil <768: satır = kart (aynı `tr`, girdiler gizli, `.ft-fs-ozet`), düzenleme `#ftSatirSheet` (içinde `<form>` YOK, `data-sh` girdileri name'siz), alt çubuk `.ft-eylem-bar` `bottom: calc(var(--bn-h)+safe-area)` z-index YOK. Modallar `.pm-overlay > .pm-dialog.ft-dialog > form` (z 600); satır menüsü `.ft-menu` fixed z 200. fatura.js'te TEK `innerHTML` (`htmlParca`), kalıcı tarayıcı deposu yok.
- **Şema yalnız migrate.php "Fatura" kartı** (önce Cari, sonra Stok); sayfalar eksikte `fatura_sema_uyari($eksik)` kartı, uç 503. Migrasyon yalnız açık GO ile.
- Sayfa ham metninde `config/cari_core.php` / `config/stok_core.php` YORUMDA BİLE geçmez (gate + nav smoke).

## Test
```
php scripts/fatura_core_smoke.php && php scripts/fatura_gate_static_smoke.php && php scripts/fatura_ui_smoke.php
php scripts/nav_muhasebe_static_smoke.php && php scripts/migrate_panel_smoke.php
NODE_PATH=/opt/node-tools/node_modules node scripts/fatura_ui_smoke.js     # tarayıcı uçtan uca (1366 / 820 / 390)
FATURA_SHOT=<klasör> node scripts/fatura_ui_smoke.js                        # ss_pc_form / ss_mobil_form / ss_pc_liste / ss_mobil_liste
php scripts/fatura_ui_render.php satis_faturalari.php yeni=1 > _test_fatura.html   # tek sayfa (--sema / --bos / --yonetici-degil)
```
Köprü: `scripts/_fatura_e2e_sunucu.js` (Node http → `scripts/_fatura_istek.php` alt süreç) + `fatura_ui_e2e_setup.php <db> [ornek|bos|sema] [cok]` + `fatura_ui_e2e_say.php` (DB gerçeği). Test kütüphanesi `_fatura_test_lib.php` (`fatura_t_istek` POST biçimi, `fatura_t_tanim`, `FATURA_E2E_DB`).
