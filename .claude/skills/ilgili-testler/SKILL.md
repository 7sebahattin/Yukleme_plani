---
name: ilgili-testler
description: Değişen dosyalara göre ilgili smoke testlerini (scripts/*_smoke.php, *.js Playwright testleri) seçip çalıştırır, php -l ile söz dizimini denetler ve sonucu dürüstçe raporlar. Bir değişiklik bitince, commit/PR öncesinde ya da "testleri çalıştır" denince kullan. Argüman verilirse yalnız o dosyalar için test seçer.
argument-hint: "[dosya-yolu ...] (boşsa git farkından bulunur)"
allowed-tools: Bash(git diff *) Bash(git status *) Bash(git fetch *) Bash(php -l *) Bash(php scripts/*) Bash(node --check *) Bash(node scripts/*) Bash(bash scripts/tum_testler.sh *) Bash(ls *)
---

# İlgili testleri çalıştır

## 1. Değişen dosyaları bul

Kullanıcının verdiği yollar: "$ARGUMENTS" — boş değilse yalnız bunları kullan. Boşsa:

```bash
git fetch origin main -q
{ git diff --name-only origin/main...HEAD; git status --porcelain | awk '{print $NF}'; } | sort -u
```

## 2. Dosya → test eşlemesi

Tablo CLAUDE.md'deki "Test:" satırlarından derlenmiştir. Birden çok satır
eşleşirse hepsini birleştir. Yeni test eklendiyse `ls scripts/` ile kontrol et;
listede olmayan adı ÇALIŞTIRMA (uydurma test adı yok).

| Değişen yol (desen) | Testler (`scripts/`) |
|---|---|
| `assets/app.js` | `beyan_js_smoke.js` · `suggest_list_smoke.js` · `bottomnav_smoke.js` · `beyan_workspace_smoke.js` · `beyan_hks_urun_e2e.js` · `export_menu_smoke.js` · `pdks_faz1b_static_smoke.php` |
| `assets/style.css` | `roles_modal_smoke.js` · `bottomnav_smoke.js` · `buton_gorunum_smoke.js` · `export_menu_smoke.js` · `pdks_faz1b_static_smoke.php` |
| `sw.js` · `manifest.json` | `pdks_faz1b_static_smoke.php` · `db_backup_smoke.php` · `siparis_gate_static_smoke.php` · `mail_gate_static_smoke.php` |
| `config/helpers.php` (nav_*, sidebar, render_header) · `index.php` | `bottomnav_smoke.js` · `rol_kapilari_smoke.php` · `nav_muhasebe_static_smoke.php` · `pdks_takip_static_smoke.php` · `pdks_index_eksik_cikis_smoke.php` |
| `config/helpers.php` (beyan_*, bb_*) | aşağıdaki beyan satırı |
| `config/auth.php` · `roles.php` · `users.php` | `roles_ui_smoke.php` · `rol_kapilari_smoke.php` · `auth_permissions_smoke.php` · `roles_modal_smoke.js` |
| `.pm-dialog` içeren modal (herhangi bir sayfa) | `roles_modal_smoke.js` · `pdks_puantaj_dialog_smoke.js` |
| Kaldırılan sayfa / 410 tombstone | `rol_kapilari_smoke.php` |
| `migrate.php` | `migrate_panel_smoke.php` |
| `beyan*.php` · `_beyan_*.php` · `api_beyan_*.php` · `assets/beyan_yazdir.js` | `beyan_bildirim_smoke.php` · `beyan_ui_smoke.php` · `beyan_suggest_smoke.php` · `beyan_data_smoke.php` · `beyan_kopyala_smoke.php` · `beyan_permissions_smoke.php` · `beyan_whatsapp_smoke.php` · `beyan_js_smoke.js` · `beyan_workspace_smoke.js` · `beyan_workspace_permissions_smoke.js` · `beyan_status_menu_smoke.js` · `beyan_yazdir_smoke.js` · `beyan_hks_urun_e2e.js` |
| `halkayit/*` | `hks_*_test.php` (`hks_endpoint_test.php` HARİÇ — canlı HKS'e ağ çağrısı + kimlik ister, sunucuda elle çalışır) · `hks_*_smoke.php` · `hks_*_smoke.js` (dosyaya göre daralt: kişi havuzu → `hks_kisi_*`, gönderilenler/yazdır → `hks_gonderilenler_smoke.js` · `hks_gonderim_yazdir_smoke.js`, CSRF → `hks_csrf_*`, künye → `hks_kunye_*` · `hks_depo_tasnif_kaynak_*`, firma → `hks_firma_yonetim_*`, belirsiz gönderim → `hks_belirsiz_gonderim_*`) |
| `config/pdks*.php` · `gunluk_*` · `cavus*` · `personel*` · `isci_*` · `ters_kart*` · `_puantaj_*` · `assets/pdks.*` · `assets/ters_kart.js` | `pdks_*` (konuya göre daralt: Yöntem B → `pdks_cavus_b_smoke.php`; kiosk → `pdks_ortak_cikis_*` · `pdks_kiosk_*`; Kart Havuzu → `pdks_kart_*`; Mesai Detayı → `pdks_puantaj_dialog_smoke.js` · `pdks_mesai_ozeti_smoke.php`; Ters Kart → `pdks_ters_kart_*`; hakediş → `pdks_hakedis_*` · `pdks_hakedis_yeniden_ac_guard_smoke.php`) |
| `config/siparis*.php` · `siparis*.php` · `_siparis_*.php` · `assets/siparis*` | `siparis_core_smoke.php` · `siparis_pdf_smoke.php` · `siparis_pkl_smoke.php` · `siparis_pkl_mail_smoke.php` · `siparis_gate_static_smoke.php` · `siparis_ui_smoke.php` · `siparis_ui_smoke.js` · `siparis_yazdir_smoke.php` · `siparis_yazdir_smoke.js` · `siparis_cari_smoke.php` · `siparis_mail_smoke.php` (mail yolu) |
| `urun_siparis*` · `config/urun_siparis.php` · `assets/usip.*` | `urun_siparis_smoke.php` · `urun_siparis_smoke.js` |
| `hesap*` · `config/hesap_*` · `assets/hesap.*` | `hesap_smoke.php` · `hesap_ui_smoke.php` · `hesap_izolasyon_smoke.php` · `hesap_pdf_smoke.php` |
| `mail*` · `config/mail_*` | `mail_*_smoke.php` (`mail_race_mysql_smoke.php` MySQL yoksa kendini ATLAR) · `mail_ui_smoke.js` (önce `MAIL_UI_OUT=… php scripts/mail_ui_render.php`) · `mail_gate_static_smoke.php` |
| `maliyet*` · `config/cost_*` | `cost_link_smoke.php` |
| `record*` · `_form.php` · `templates/excel/*` | `record_excel_smoke.php` |
| `malzeme_*` · `config/material_stock_helpers.php` | `test_material_stock_helpers.php` |
| `config/xlsx_export.php` · `rapor_malzeme.php` | `export_menu_smoke.js` · `rapor_malzeme_xlsx_smoke.php` · `pdks_toplu_dokum_xlsx_smoke.php` |
| `admin_db_backups.php` · `config/db_backup_helpers.php` | `db_backup_smoke.php` |

Hiçbir satır eşleşmezse (ör. yalnız `docs/`) test gerekmez; yine de PHP/JS
dosyası varsa 3. adımdaki söz dizimi denetimini yap.

## 3. Çalıştır

Önce her değişen PHP dosyasına `php -l <dosya>`, her değişen `.js` dosyasına
`node --check <dosya>`.

Sonra testler — tercih edilen yol ortak çalıştırıcı:

```bash
bash scripts/tum_testler.sh scripts/a_smoke.php scripts/b_smoke.js ...
```

(Konumsal argüman = yalnız o testler; `.js` testlerinin render ön adımlarını
kendisi yapar. `--js` tüm testleri Playwright dahil, argümansız tüm PHP testleri.
Hata olursa çıkış kodu 1. `-v` başarısız testin son 40 satırını gösterir; `--liste` yalnız
ne çalışacağını yazar. Playwright yoksa tarayıcı testi "ATLANDI" der.)

`scripts/tum_testler.sh` yoksa doğrudan çalıştır: `php scripts/x.php`. `.js`
testlerinden önce CLAUDE.md'deki render adımı gerekir, örn.
`BOTTOMNAV_OUT=/tmp/bn php scripts/bottomnav_render.php && BOTTOMNAV_OUT=/tmp/bn node scripts/bottomnav_smoke.js`,
`php scripts/roles_modal_render.php > _test_roles.html && node scripts/roles_modal_smoke.js`,
`SIPARIS_UI_OUT=/tmp/sip php scripts/siparis_ui_render.php && node scripts/siparis_ui_smoke.js`.
Render adımını bilmediğin bir `.js` testi için CLAUDE.md'de test adını ara.
Kökte üretilen `_test_*.html` dosyalarını commit'leme.

## 4. Raporla

Her test için GEÇTİ / KALDI / ATLANDI (ör. Playwright yok → test kendini atlar;
bunu "geçti" diye SAYMA, "atlandı" de). Kalan testin ilgili çıktı satırını aktar.
Sonucu güzelleme; çalıştırılmayan testi çalıştırılmış gibi yazma.
