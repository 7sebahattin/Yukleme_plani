---
name: kasa-modulu
description: Kasa modülü (kasa_fisleri/kasa_tanimlari/kasa_kaydet/kasa_cikti.php, config/kasa_core.php + kasa_ui.php, assets/kasa.js/css): nakit kasa + banka hesabı (tip=banka kasa kartı), INSERT-only kasa defteri, tahsilat/tediye/gelir/gider/açılış/virman, cari bağı, yetersiz bakiye, tevsik uyarısı, kasa defteri yazdır + xlsx/csv, şema kartı, testler. kasa_* dosyalarına dokunurken kullan.
---

# Kasa (kasa_*)

> v528. Kapı v550'den beri `muhasebe_modul_can` (Roller `muhasebe.kasa.*`; bkz. `rol-yonetimi`) (sidebar "Kasa" grubu, `nav_muhasebe_gruplari()`; 2 sayfa). cari-modulu / stok-modulu / fatura-modulu kalıbının eşi. Çek-Senet modülü (`cek-senet` skill'i) kasaya YALNIZ bu dosyanın fonksiyonlarıyla bağlanır.

## Kapsam
- **Sayfalar (kök, hepsi GET-only):** `kasa_fisleri` (sol: kasa kartları + pb bazında genel özet; sağ: hareket listesi). GET: `?kasa=ID` (yoksa tüm kasalar) · `?id=N` hareket detayı · `?ara= &tur= &durum=gecerli|iptal|tumu (varsayılan gecerli) &donem=tumu|bugun|30g|bu_ay|gecen_ay|ozel (&bas &bit) &sayfa=` · `?parca=detay&id=N` kısmi yanıt (`kasa_parca()`, render_header'dan ÖNCE). Yürüyen bakiye sütunu yalnız tek kasa + `durum=gecerli` + tür/ara/cari süzgeci YOKKEN. `kasa_tanimlari`: `?sekme=kasa|gelir|gider` (+ `?yeni=1` yeni kasa modalı).
- **Yazma TEK uç `kasa_kaydet.php`** (yalnız POST; GET → 405 + `Allow`). Sıra: yöntem → `require_login` → `kasa_no_store` → JSON başlığı → `csrf_check` → `db()` → `kasa_sayfa_kapisi` → dalın İLK ifadesi tek `kasa_kapi(...)` → yalnız `kasa_core` fonksiyonu → `kasa_cevap`. 9 dal, bu sırayla (gate smoke sırayı denetler):

| `islem` | yetki | çekirdek |
|---|---|---|
| kart_kaydet | write | `kasa_kart_kaydet` (`aktif`/`eksi_izin` onay kutusu gelmezse 0) |
| kart_durum | write | `kasa_kart_durum` |
| kart_sil | delete | `kasa_kart_sil` |
| tanim_kaydet · tanim_durum · tanim_sil | admin | `kasa_tanim_*` |
| hareket_ekle | write | `kasa_hareket_ekle($pdo, $P, $uid)` (TAM 3 argüman) |
| virman_ekle | write | `kasa_virman_ekle` |
| hareket_iptal | delete | `kasa_hareket_iptal` |

  JSON: 200 · 422 (doğrulama) · 409 (`yetersiz_bakiye` · `kaynakli` · `hareketli` · `kullanimda`) · 403 · 503 (şema yok) · 500 (`sunucu_hatasi`; PDOException genel mesaj, ayrıntı yalnız `error_log`) · anahtarlar `ok,id,id2,belge_no,cari_belge_no,hata,alanlar,uyari,yonlendir,tekrar,kod,bakiye,eksik`. Klasik form: `set_flash` + 303 `geri`; `kasa_geri_guvenli` YALNIZ `^kasa_(fisleri|tanimlari)\.php(\?…)?$`, aksi `kasa_fisleri.php`.
- **Çıktı ucu `kasa_cikti.php`** (salt okunur GET): `?kasa=ID[&bas=&bit=][&otomatik=1]` A4 dikey kasa defteri (devir + yürüyen bakiye + kapanış) · `&bicim=xlsx|csv` (`require_perm('reports.export')` + `export_audit('kasa','defter',…)`; CSV `;` + BOM). Kasa yoksa / aktif depoda görünmüyorsa 404.
- Çekirdek `config/kasa_core.php` (kurallar + DB; `require_once cari_core.php`), işaretleme `config/kasa_ui.php` (SORGU/yazma YOK), istemci `assets/kasa.js` + `kasa.css` (kök `.kasa#kasa`, önek `ks-`; style.css/app.js'e DOKUNMAZ, SW SHELL'e girmez).

## Değiştirmeden önce oku
- **Banka hesabı ayrı modül DEĞİL:** `kasa_kartlar.tip='banka'` olan kart (`kasa_tipler()` = nakit | banka; banka_adi/sube/iban yalnız banka tipinde tutulur). Çek-Senet karneleri ve "tahsile ver" bunu kullanır. Yeni "Banka" modülü/tablosu açma.
- **Defter INSERT-only:** `kasa_hareketler`'de DELETE YOK; UPDATE yalnız 3 beyaz liste kalıbı (gate smoke tam metin): `belge_no = ?, cari_hareket_id = ?` · `belge_no = ?, bagli_id = ?` · `durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?` (iptal UPDATE'i `AND durum = 'gecerli'`; iki yerde: `kasa_hareket_iptal`, `kasa_kaynakli_iptal`). `INSERT INTO kasa_hareketler` tam 2 kez (hareket_ekle + virman_ekle). DELETE yalnız hareketsiz `kasa_kartlar` ve kullanılmayan `kasa_tanimlar`. Düzeltme = iptal (neden 3–500) + yeni kayıt. Taslak YOK. `istek_anahtari` UNIQUE → çift gönderim `tekrar:true`.
- **Tür / yön tablosu** (`kasa_hareket_turleri()`; dahili: `kasa_dahili_turler()`; belge no `ÖNEK-YYYY-000123`, id tabanlı):

| tur | yön | önek | bağ |
|---|---|---|---|
| cari_tahsilat | G | KTH | cari zorunlu → cari `tahsilat` (Alacak) |
| cari_odeme | C | KOD | cari zorunlu → cari `odeme` (Borç) |
| gelir / gider | G / C | KGL / KGD | gelir/gider kalemi zorunlu (`kalem_ad` snapshot) |
| acilis | G | KAC | — |
| virman | çift | KVR | kaynak C + hedef G, AYNI pb, farklı kasa; ortak belge_no, `bagli_id` çapraz |
| kaynak_giris / kaynak_cikis (dahili) | G / C | KKG / KKC | yalnız `$kaynak` ile; cari/kalem istenmez, açıklama ≥3 zorunlu |

- **Cari bağı:** `cari_hareket_ekle(…, ['modul'=>'kasa','id'=>$hid])` tek tx içinde, hareket satırından SONRA; hata → `KASA_IC_HATA` → HEPSİ geri (hata `alanlar`'ı taşınır). İptal → `cari_kaynakli_iptal($pdo,'kasa',$id,…)`; cari ekranından o satır iptali 409 `kaynakli`. `cari_*` tablosuna bu dosyada HİÇ SQL yok (okuma dahil; unvan için `cari_kart_oku`/`cari_kart_listesi`).
- **Dahili türler yalnız `$kaynak` ile:** `kasa_hareket_ekle($pdo,$g,$uid,?array $kaynak)`; `kasa_tur_bilgi($tur, $kaynak !== null)` dahili türü ancak kaynak verilince çözer → POST yolundan `kaynak_giris/kaynak_cikis` "Geçersiz hareket türü" ile reddedilir. `kasa_kaydet.php` 4. parametreyi ASLA geçmez (gate smoke 3 argümanı sabitler). Kaynak `^[a-z_]{2,30}$` + id>0. Kaynaklı satır kasa ekranından iptal edilemez (409 `kaynakli`).
- **`kasa_kaynakli_iptal($pdo,$modul,$kaynakId,$neden,$uid)`:** o kaynağa bağlı TÜM geçerli kasa satırlarını iptal eder; yetki kontrolü YOK, idempotent (satır yoksa `ok` + boş `iptal_idler`), dış tx içinde çalışır. Çek-senet iptalinin kasa yarısı budur.
- **Yetersiz bakiye:** çıkışta (`yon=C` ve virman kaynağı) kilitli bakiye < tutar ve kartta `eksi_izin=0` ve `eksiye_izin` yok → 409 `yetersiz_bakiye` (+ `bakiye`, `eksik` kuruş; HİÇ yazılmaz). UI `#ksEksik` + "Yine de kaydet" AYNI `istek_anahtari` + `eksiye_izin=1` ile yeniden gönderir → kayıt + `uyari`. Kartta `eksi_izin=1` ise uyarısız geçer. İptal HER ZAMAN yapılır; kasa eksiye düşerse `uyari` (`kasa_eksi_uyarisi`).
- **`kasa_bakiye_kilitli`:** tx içinde, `kasa_kart_kilitle` (MySQL `FOR UPDATE`, id artan) SONRASI çağrılır; MySQL'de bakiye toplamı da `FOR UPDATE` ile en son işlenmiş veriyi okur (REPEATABLE READ görüntüsü eskimesin). Düz `kasa_bakiye` yalnız gösterim içindir. SQLite'ta kilitler atlanır → MySQL'e özgü kısım yalnız statik doğrulandı.
- **Tevsik sınırı:** `KASA_TEVSIK_SINIRI_KURUS = 3000000` (30.000,00 TL, VUK GT 575) — nakit kasa + TRY + cari_tahsilat/cari_odeme + tutar ≥ sınır → kayıt YAPILIR, yalnız `uyari` ("30.000 TL ve üzeri tahsilat/ödemeler banka aracılığıyla yapılmalıdır (VUK GT 575)."). Engel DEĞİL. Sınır yasal olarak yıllık değişebilir; değişirse sabiti VE metni (gate smoke ikisini de sabitler) birlikte güncelle.
- **Para:** kuruş tam sayı (`kasa_tutar_coz` → `cari_tutar_coz` → `kasa_para_db` DECIMAL(14,2)); **tutar belirsizliği (v528):** tutar okunan HER yer (`kasa_hareket_ortak` = hareket + virman; çek-senet bordro satırı da) `kasa_tutar_coz(mixed): array|int|null` sarmalayıcısını kullanır — NOKTA içermeyen `^[1-9]\d{0,2},\d{3}$` kalıbı ("1,500", "12,345"; "0,500" HARİÇ = 0,50) BELİRSİZ sayılır (ortak ayrıştırıcı 1.500,00 yapardı; "bir buçuk" niyeti 1000 kat hata) → 422 `alanlar[tutar]` "Tutar belirsiz: 1.500 için nokta kullanın (1.500), bir buçuk için 1,50 yazın."; diğer girdiler ortak ayrıştırıcıya aynen gider (ona DOKUNMA). `kasa.js`/`ceksenet.js` `tutarCoz` sunucuyla BİREBİR aynı (ui smoke JS ↔ PHP eşitliğini ~60 girdide sabitler; `scripts/kasa_ui_e2e_tutar.php`); tutar alanının altında `[data-tutar-onizleme]` ("= 1.500,00 ₺" ya da belirsizlik hatası). Ayrıştırıcıyı değiştirirsen sarmalayıcı + iki JS birlikte; para birimi KASADAN gelir, `$g['para_birimi']` okunmaz; kasanın pb'si hareket varken değişmez (409 `hareketli`). Para birimleri ASLA toplanmaz (`kasa_genel_ozet` pb başına ayrı; kasa tek pb'li). Kur yalnız bilgi (TRY'de girilmez).
- **Depo kuralı (kasa kartı üzerinden):** kart/liste/seçenek/hareket listesi süzgeci KART deposu üzerinden — `kasa_depo_filtre` → `depo_sql_in` (aktif depo + boş depo; boş = tüm depolar). `kasa_bakiye` / `kasa_bakiyeler_toplu` / `kasa_defter` süzgeçsizdir (bakiye kasanın bütünü); `kasa_hareketler.depo` yalnız `active_depot()` damgası. Aktif depo dışındaki gerçek-depolu kasada yazma reddi `kod=depo` (`kasa_depo_ret`); pasif kasaya hareket yok.
- **Kapı:** `kasa_can()` = `muhasebe_modul_can('kasa', …)` (v550; yönetici her zaman, diğerleri `muhasebe.kasa.*`; bkz. `rol-yonetimi`) (TEK değişim noktası). Yazan çekirdek fonksiyonlarda yetki kontrolü YOK; hepsi `audit_log_event(…, 'kasa', …)` yazar (hareket_ekle'de commit SONRASI).
- **Catch sırası:** `kasa_tx(...)` çevresinde `catch (PDOException)` ÖNCE, `catch (RuntimeException)` SONRA — PDOException, RuntimeException'ın alt sınıfıdır; ters sırada DB hatası `KASA_IC_HATA` kontrolüne düşüp yeniden fırlatılır. PDOException dalı önce istek anahtarını arar (kilit yarışında çift gönderim `tekrar`).
- **Şema yalnız `migrate.php` "Kasa" kartı** (`mg_kart_ac('kasa')`, `ne=kasa`, `kasa_migrate`; 3 tablo `kasa_kartlar` / `kasa_hareketler` / `kasa_tanimlar`; ALTER/FK/ENUM yok; tanım tohumu yalnız tablo yeni kurulduysa). Cari kartı ÖNCE gerekli (`kasa_sayfa_kapisi` cari + kasa eksiklerini sayar; sayfa uyarı kartı, uç 503). Migrasyon yalnız açık GO ile. `kasa_migrate` tek çağıranı migrate.php; `config/db.php`'de kasa CREATE YOK.
- **İzolasyon (gate smoke):** `kasa_core`/`kasa_ui`/`kasa.js|css`/`kasa_*.php`'de "ceksenet" / "çek-senet" sözcüğü (yorumda bile) GEÇMEZ — bağımlılık tek yönlü (çek-senet → kasa). `kasa_core`'da `stok_`/`fatura_` çağrısı yok. Sayfa ham metninde `config/cari_core.php` GEÇMEZ (yalnız `kasa_core`). Kasa kartı silinirken çek-senet bağı (karne `kasa_id`, `yer_kasa_id`) DENETLENMEZ (bilinçli: kasa çekirdeği çek-senedi bilmez).
- **`sw.js` `SW_MUHASEBE_RE`** `kasa` önekini kapsar (kasa_* ağ-only; `assets/kasa.*` SHELL'e girmez). Yeni sayfa adı `kasa` ile başlamalı.
- **Liste özeti + sayfalama (v528):** `kasa_hareket_listesi` özeti süzgeçle TUTARLI: `durum=iptal` → İPTAL satırlarının toplamı (`ozet_durum='iptal'`, UI "İptal edilen", Net yok); `gecerli`/`tumu` → yalnız geçerliler (`tumu` etiketi "Geçerli hareketler"). `?sayfa=N` son sayfadan büyükse son sayfaya sınırlanır (yanlış "Henüz hareket yok" olmaz). ≥1024'te `.ks-liste-govde .ks-tablo-kaydir { flex: none; overflow: visible }` (flex:1 + min-height:0 sayfalama çubuğunu satırların ÜSTÜNE bindirirdi — SİLME; ui smoke 100+ hareketle 1366/1024'te ölçer, kurulum modu `kasa_ui_e2e_setup.php … cok`).
- **CSV (v528):** `kasa_csv_metin()` METİN hücrelerini (`=` `+` `-` `@` TAB CR ile başlıyorsa) `'` önekler; sayısal tutar/bakiye (eksi dahil) ve tarih hücrelerine UYGULANMAZ. Çek-senet CSV'si de bunu çağırır.
- **Toast (v528):** mobilde görünür alt eylem çubuğunun ÜSTÜNE oturur (`--ks-toast-ek` JS'te ölçülür) ve `pointer-events: none`; aktif/pasif anahtarı (`input[role=switch]`) istek sürerken `disabled`, hata dönerse işareti geri alınır (FormData anahtar devre dışı kalmadan ÖNCE okunur).
- **UI:** PC ≥1024 TEK EKRAN (`.ks--tek-ekran`, sol kasalar + sağ liste, ayrı kayar). Mobil <768: liste kart (`.ks-hk`), kasa seçici, alt eylem çubuğu `.ks-eylem-bar` `bottom: calc(var(--bn-h) + safe-area)` (z-index VERME). Modallar `.pm-overlay > .pm-dialog.ks-dialog > form` (z ≥600; `#ksModalForm` ortak hareket formu, `#ksModalDetay`, `#ksModalIptal`, `#ksModalKart`); toast z 700. Girdi <768 16px, dokunma ≥44px. `kasa.js`'te TEK `innerHTML` (`htmlParca`), `localStorage` YOK (yalnız try/catch'li `sessionStorage` toast). Veri `#ksVeri` (`kasa_json_script`, JSON_HEX_*). Klavye: `/` arama, Ctrl+Enter kaydet, Esc kapat. Tutar çözümü JS'te yalnız önizleme; sunucu esas. Rozetlerde renk tek başına anlam taşımaz (▲ giriş, ▼ çıkış, ⇄ virman). Tutar biçimi `1.234,56 ₺` (`cari_tutar_bicim`).

## Test
```
php scripts/kasa_core_smoke.php && php scripts/kasa_gate_static_smoke.php && php scripts/kasa_ui_smoke.php
php scripts/nav_muhasebe_static_smoke.php && php scripts/migrate_panel_smoke.php
NODE_PATH=/opt/node-tools/node_modules node scripts/kasa_ui_smoke.js        # tarayıcı uçtan uca (1366 / 820 / 390; Playwright yoksa ATLANIR)
KASA_SHOT=<klasör> NODE_PATH=/opt/node-tools/node_modules node scripts/kasa_ui_smoke.js   # ekran görüntüleri
php scripts/kasa_ui_render.php fisler kasa=1 > _test_kasa.html               # tek sayfa (fisler|tanimlar|cikti; --sema / --bos / --ornek / --yonetici-degil)
```
Köprü: `scripts/_kasa_e2e_sunucu.js` (Node http → `scripts/_kasa_istek.php` alt süreç, GERÇEK sayfalar + dosya SQLite) + `kasa_ui_e2e_setup.php` + `kasa_ui_e2e_say.php` (DB gerçeği). Test kütüphanesi `scripts/_kasa_test_lib.php`. `tum_testler.sh` `scripts/*_smoke.php` ve `*.js` dosyalarını kendisi bulur (ayrı kayıt gerekmez).
