---
name: depo-yonetimi
description: Depo Tanımları ve yeni depo kayıt sistemi (depo_tanimlari.php, config/depo_core.php, migrate.php › Depolar kartı, v574): depolar/depo_kullanicilar/depo_eski_adlar/depo_ayar tabloları, eski depo/veri "ESKİ" defteri, geçiş (gecis_tarihi), depo ↔ kullanıcı ataması. depo_*/depot_options/user_assigned_depots koduna ya da yeni depo kolonu/tablosu eklerken kullan.
---

# Depo Yönetimi (v574, sahip kararı 2026-11)

**Karar:** Şube kavramı YOK (sube_tanimlari.php = 410). "Tanımlar" menüsü kalktı (definitions.php = 410; `material_definitions` tablosu durur). Eski depolar + eski veri **silinmez, "eski" diye işaretlenip gizlenir**. Yeni depolar sabit ID/kodlu yeni tablolarda.

## Şema (yalnız migrate.php › "Depolar" kartı; sayfalar CREATE/ALTER atmaz)
- `depolar` — `id`, **değişmez `kod`** (D001…), `ad` (tr_upper), `ad_anahtar` (TR-duyarsız katlanmış, UNIQUE), `renk`, `aktif`.
- `depo_kullanicilar` — (depo_id, user_id) ID ile atama. Eski `user_depolar` (ad metni) geçişten sonra OKUNMAZ.
- `depo_eski_adlar` — ESKİ ad defteri: geçişte bilinen tüm eski adlar (tanım + atama + veri kolonu değerleri). Yeni depo bu adlardan birini ALAMAZ.
- `depo_ayar` — `gecis_tarihi` satırı = yeni sistem AÇIK. Satır/tablo yoksa her şey ESKİ davranışta (fail-safe).
- Operasyon tablolarındaki `depo` / `exit_depot` / `hedef_depo` METİN kolonları değişmez; satır yeni depo ADINI taşır. Yeni depo kolonu eklerken kolon adı bu üçünden biri olsun ki `depo_kolonlari()` keşfi bulsun (yoksa `DEPO_KOLON_ADLARI`'na ekle) — yoksa yeniden adlandırma/rapor o kolonu görmez.

## Karışma önlemleri (DEĞİŞTİRME)
1. `depo_ad_cakisma()`: yeni depo adı başka yeni depoyla ya da ESKİ ad (defter; geçiş öncesi canlı eski kaynaklar dahil) ile çakışamaz.
2. Geçiş ön kontrolü (`depo_gecis_engelleri`): yeni depo adı eski verilerde geçiyorsa geçiş YAPILMAZ (eski satırlar yeni depoya karışırdı).
3. Geçiş açıkken `ensure_definition('depo', …)` NO-OP (eski formlar eski tanım üretemez); `depot_options`, `user_assigned_depots`, `depot_color`, `role_data_scope_options`, users.php yeni kaynaktan okur.
4. Ad değişince `depo_ad_aktar()` TÜM keşfedilen depo kolonlarını aynı işlemde yeni ada çeker. `kod` hiç değişmez.
5. Silme yalnız hiçbir satır o adı taşımıyorsa (`depo_sil`); kullanımdaysa pasifleştir.
6. Eski adla damgalı CANLI modül satırları (Personel/Sipariş vb.) gizlenmesin diye migrate kartında rapor + `depo_eski_veriyi_aktar` (v575: ÇOKLU eski ad `eski_ad[]` + seçili tablolar; her tablo AYRI işlemde — biri hata verirse diğerleri yine aktarılır ve mesajda `tablo.kolon — hata` yazar; tablo seçilmezse işlem yapılmaz). **Benzersiz anahtar çakışması** (ör. `daily_work_sessions` UNIQUE(foreman_id, work_date, depo): geçişten sonra hedef depo adıyla aynı çavuş+gün kaydı açıldıysa) tabloyu düşürmez: `UPDATE IGNORE` ile çakışmayanlar taşınır, çakışanlar ESKİ adda KALIR ve mesajda `ATLANAN … tablo.kolon: n` yazar (elle birleştirilir). Tablo grupları `depo_tablo_grubu()`: canlı (varsayılan işaretli) · eski · serbest (`customs_declarations.exit_depot` = depo filtresi DEĞİL, aktarma) · yedek (`*_yedek_*`: raporda gizli, aktarılmaz).
7. Geçiş: `depo_gecis_uygula` hiçbir veri silmez/değiştirmez; `depo_gecis_geri_al` yalnız ayar satırını kaldırır (acil durum, elle).

## Davranış kuralları
- Atama yok → kullanıcı TÜM depoları görür (eski kural korunur); atama var → yalnız atandığı AKTİF depolar; hepsi pasifse `[]`. Admin kısıtsız.
- Roller'deki depo kapsamı (`scope.depot.*`) ad hash'iyle durur; geçişten sonra seçenekler yalnız yeni depolar. Eski adlı kapsamı olan rol fail-closed (hiç depo) olur → Roller'den yeniden kaydedin.
- Sayfa yalnız `is_admin()`. **v575 (sahip kararı): depo_sec.php'den "Yeni Depo Ekle" KALKTI** — depo YALNIZ Yönetim › Depo Tanımları'ndan açılır (herkes için; `defs.create` artık depo açmaz). Depo yoksa seçim ekranı yöneticiye Depo Tanımları bağlantısını, diğerlerine "yöneticinizden isteyin" notunu gösterir.
- **Giriş + Depo Seçimi görünümü (v575):** `login.php` ve `depo_sec.php` ortak `assets/giris.css` (gradyan `.lc` kartı) kullanır; mobil mekaniği (`--app-h` script'i, tam ekran kart, `overflow-y:auto`) DEĞİŞTİRME. Depo kartları `.lc-depo` (aktif: `.lc-depo-aktif`).

## Test
`php scripts/depo_core_smoke.php` (bellek içi SQLite; keşif, çakışma, geçiş, ad aktarımı, silme) · `php scripts/migrate_panel_smoke.php` (39 kart).
